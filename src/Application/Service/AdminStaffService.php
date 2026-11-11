<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use VendGuard\Core\Domain\Exception\RestockerCannotBeOnCallException;
use VendGuard\Core\Domain\Exception\RoleChangeForbiddenException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\TechnicianShiftLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\ShiftEventType;

/**
 * AdminStaffService
 *
 * Fachada de aplicación del CRUD de Personal Interno para el Módulo 31. Envuelve
 * el servicio administrativo ya existente con las tres reglas que el rol
 * `RESTOCKER` introduce y que ningún módulo anterior necesitaba:
 *
 *   1. **Alta de reponedor (`D3`, RF-01.1).** El rol `RESTOCKER` se admite junto
 *      a `COORDINATOR` y `TECHNICIAN`, pero la guardia 24/7 (`is_on_call`) se
 *      rechaza con `422 RESTOCKER_CANNOT_BE_ON_CALL`: la atención de emergencias
 *      térmicas es exclusiva del Técnico SAT (Art. II). La bandera no se
 *      persiste nunca desde el alta; la base de datos la fuerza a `FALSE`.
 *   2. **Inmutabilidad del rol (`C1`, EARS 01.2).** Cualquier `PUT` que envíe un
 *      `role` distinto al persistido se rechaza con
 *      `409 ROLE_CHANGE_FORBIDDEN` sin tocar la fila. El cambio de puesto se
 *      resuelve dando de baja la cuenta y creando una ficha nueva.
 *   3. **Baja con cierre automático de turno (`C3`, EARS 01.3).** Desactivar a
 *      un operario con un turno abierto cierra ese turno en la misma transacción
 *      (`FORCE_CLOSE_SHIFT` en `technician_shift_logs` y en `audit_log`, motivo
 *      `Cierre automático por desactivación de cuenta de usuario`), de modo que
 *      nadie queda fichado para siempre acumulando horas.
 *
 * Dogma Vanilla: PHP 8.2+ puro. El servicio no abre conexiones: la composición
 * inyecta el `TransactionManagerInterface` de producción y, sin él, la
 * operación se ejecuta tal cual (útil en dobles de prueba).
 *
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
class AdminStaffService
{
    /** Motivo contractual del cierre al dar de baja la cuenta (C3, EARS 01.3). */
    public const DEACTIVATION_CLOSE_REASON = 'Cierre automático por desactivación de cuenta de usuario';

    /** Acción de auditoría de la baja con turno abierto (contrato §5). */
    public const FORCE_CLOSE_SHIFT_ACTION = 'FORCE_CLOSE_SHIFT';

    private AdminUserService $adminUsers;
    private UserRepositoryInterface $userRepo;
    private TechnicianShiftRepositoryInterface $shiftRepo;
    private AuditLogger $auditLogger;
    private ?TechnicianShiftLogRepositoryInterface $shiftLogRepo;
    private ?TransactionManagerInterface $transactions;

    public function __construct(
        AdminUserService $adminUsers,
        UserRepositoryInterface $userRepo,
        TechnicianShiftRepositoryInterface $shiftRepo,
        ?AuditLogger $auditLogger = null,
        ?TechnicianShiftLogRepositoryInterface $shiftLogRepo = null,
        ?TransactionManagerInterface $transactions = null
    ) {
        $this->adminUsers = $adminUsers;
        $this->userRepo = $userRepo;
        $this->shiftRepo = $shiftRepo;
        $this->auditLogger = $auditLogger ?? new AuditLogger();
        $this->shiftLogRepo = $shiftLogRepo;
        $this->transactions = $transactions;
    }

    /**
     * Da de alta un usuario interno aplicando las reglas del Módulo 31.
     *
     * @param array<string, mixed> $data Datos del alta (name, email, role, phone, password, telegram_chat_id, is_on_call).
     * @param array{id: int|null, role: string, name: string} $actor Coordinador actuante.
     * @param string|null $clientIp Dirección IP del cliente para auditoría.
     * @return User Usuario creado.
     *
     * @throws RestockerCannotBeOnCallException Si un `RESTOCKER` llega con `is_on_call` encendido.
     * @throws InvalidArgumentException Si `is_on_call` no es un booleano interpretable.
     */
    public function createUser(array $data, array $actor, ?string $clientIp = null): User
    {
        $requestedRole = strtoupper(trim((string)($data['role'] ?? '')));
        $isOnCall = self::readBooleanFlag($data['is_on_call'] ?? null, 'is_on_call');

        if ($requestedRole === UserRole::RESTOCKER->value && $isOnCall) {
            throw new RestockerCannotBeOnCallException(
                isset($data['operator_code']) ? (string)$data['operator_code'] : null
            );
        }

        // La guardia no se crea desde el alta de personal: nace apagada y se
        // enciende después por la acción dedicada, que sólo admite técnicos.
        unset($data['is_on_call']);

        return $this->adminUsers->createUser($data, $actor, $clientIp);
    }

    /**
     * Actualiza una ficha de personal respetando la inmutabilidad del rol.
     *
     * Si el payload incluye `is_active: false` sobre una cuenta activa, la
     * edición se resuelve como la baja del apartado 3 (cierre de turno incluido);
     * si incluye `is_active: true` sobre una cuenta inactiva, se reactiva.
     *
     * @param array<string, mixed> $data Campos a actualizar.
     * @param array{id: int|null, role: string, name: string} $actor Coordinador actuante.
     * @return User Ficha resultante.
     *
     * @throws UserNotFoundException Si el usuario no existe.
     * @throws RoleChangeForbiddenException Si se intenta cambiar el rol (C1).
     * @throws RestockerCannotBeOnCallException Si se intenta encender la guardia de un reponedor.
     */
    public function updateUser(int $id, array $data, array $actor, ?string $clientIp = null): User
    {
        $existing = $this->userRepo->findById($id, onlyActive: false);
        if ($existing === null) {
            throw new UserNotFoundException($id);
        }

        $this->assertRoleIsImmutable($existing, $data);
        $this->assertRestockerIsNotOnCall($existing->getRole(), $data['is_on_call'] ?? null);

        if (array_key_exists('is_active', $data)) {
            $isActive = self::readBooleanFlag($data['is_active'], 'is_active');

            if (!$isActive && $existing->isActive()) {
                $this->deactivateUser($id, $actor, $clientIp);

                return $this->requireUser($id);
            }

            if ($isActive && !$existing->isActive()) {
                return $this->adminUsers->reactivateUser($id, $actor, $clientIp);
            }
        }

        unset($data['role'], $data['is_on_call'], $data['is_active']);

        return $this->adminUsers->updateUser($id, $data, $actor, $clientIp);
    }

    /**
     * Da de baja la cuenta y, en la misma transacción, cierra cualquier turno
     * abierto del operario (Módulo 31, `C3`, EARS 01.3).
     *
     * Cubre a `RESTOCKER` y `TECHNICIAN`: ambos fichan jornada y ambos pueden
     * quedar con un turno huérfano acumulando horas si la cuenta se apaga sin
     * cerrarlo. El cierre descuenta el tramo final de pausa si el operario
     * estaba en `MEAL_BREAK`, porque lo hace la propia entidad del turno.
     *
     * @param int $id Usuario a desactivar.
     * @param array{id: int|null, role: string, name: string} $actor Coordinador actuante.
     * @return bool `true` si la baja se persistió.
     *
     * @throws UserNotFoundException Si el usuario no existe.
     * @throws Throwable Si la baja o el cierre fallan: la transacción se deshace.
     */
    public function deactivateUser(int $id, array $actor, ?string $clientIp = null): bool
    {
        if ($this->requireUser($id) === null) {
            throw new UserNotFoundException($id);
        }

        return (bool)$this->runAtomically(function () use ($id, $actor, $clientIp): bool {
            $activeShift = $this->shiftRepo->findActiveShiftByTechnician($id);
            $closedShift = $this->closeOpenShift($activeShift, $actor);

            if (!$this->adminUsers->deactivateUser($id, $actor, $clientIp)) {
                throw new RuntimeException(
                    "No se pudo desactivar la cuenta #{$id}: la baja se deshace para no dejar el turno cerrado y la cuenta activa."
                );
            }

            if ($closedShift !== null && $activeShift !== null) {
                $this->auditLogger->logUserEvent(
                    $id,
                    'FORCE_CLOSE_SHIFT',
                    $actor,
                    $activeShift->toArray(),
                    $closedShift->toArray(),
                    [
                        'shift_id' => $closedShift->id(),
                        'reason' => self::DEACTIVATION_CLOSE_REASON,
                    ]
                );
            }

            return true;
        });
    }

    /**
     * Cierra el turno abierto del operario, si lo tiene, y lo deja en la
     * bitácora inmutable como `FORCE_CLOSE_SHIFT` (Art. III.3).
     */
    private function closeOpenShift(?TechnicianShift $activeShift, array $actor): ?TechnicianShift
    {
        if ($activeShift === null || !$activeShift->isOpen()) {
            return null;
        }

        $shiftId = $activeShift->id();
        if ($shiftId === null) {
            throw new RuntimeException('El turno abierto no tiene identificador persistido: no se puede cerrar.');
        }

        $actorId = isset($actor['id']) && $actor['id'] !== null
            ? (int)$actor['id']
            : $activeShift->technicianId();

        $executionTime = new DateTimeImmutable('now');

        $closedShift = $this->shiftRepo->closeShift(
            shiftId: $shiftId,
            closedBy: $actorId,
            closeReason: self::DEACTIVATION_CLOSE_REASON,
            isForceClosed: true,
            executionTime: $executionTime
        );

        $this->shiftLogRepo?->record(
            shiftId: $shiftId,
            technicianId: $closedShift->technicianId(),
            eventType: ShiftEventType::FORCE_CLOSE_SHIFT,
            actorUserId: $actorId,
            effectiveAt: $executionTime,
            previousState: $activeShift->toArray(),
            newState: $closedShift->toArray(),
            reason: self::DEACTIVATION_CLOSE_REASON
        );

        return $closedShift;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws RoleChangeForbiddenException
     */
    private function assertRoleIsImmutable(User $existing, array $data): void
    {
        if (!array_key_exists('role', $data)) {
            return;
        }

        $requested = strtoupper(trim((string)$data['role']));
        if ($requested === '' || $requested === $existing->getRole()->value) {
            return;
        }

        throw new RoleChangeForbiddenException(
            $existing->getId(),
            $existing->getRole()->value,
            $requested
        );
    }

    /**
     * @throws RestockerCannotBeOnCallException
     * @throws InvalidArgumentException
     */
    private function assertRestockerIsNotOnCall(UserRole $role, mixed $rawFlag): void
    {
        if ($rawFlag === null || !$role->isRestocker()) {
            return;
        }

        if (self::readBooleanFlag($rawFlag, 'is_on_call')) {
            throw new RestockerCannotBeOnCallException();
        }
    }

    /**
     * @throws UserNotFoundException
     */
    private function requireUser(int $id): User
    {
        $user = $this->userRepo->findById($id, onlyActive: false, allowDeleted: true);
        if ($user === null) {
            throw new UserNotFoundException($id);
        }

        return $user;
    }

    private function runAtomically(callable $operation): mixed
    {
        return $this->transactions !== null
            ? $this->transactions->runInTransaction($operation)
            : $operation();
    }

    /**
     * Interpreta una bandera booleana de payload sin convertir errores de
     * cliente en estados válidos: `"true"`, `1` y `true` encienden; `"false"`,
     * `0` y `false` apagan; cualquier otra cosa es un 422 de validación.
     *
     * @throws InvalidArgumentException
     */
    private static function readBooleanFlag(mixed $value, string $field): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 0 || $value === 1) {
                return $value === 1;
            }

            throw new InvalidArgumentException("El campo '{$field}' debe ser un booleano.");
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) {
                throw new InvalidArgumentException("El campo '{$field}' debe ser un booleano.");
            }

            return $parsed;
        }

        throw new InvalidArgumentException("El campo '{$field}' debe ser un booleano.");
    }
}
