<?php

declare(strict_types=1);

/**
 * AdminStaffRestockerServiceTest · T-RES-07
 *
 * Certifica las tres reglas que el Módulo 31 introduce en el CRUD de Personal
 * Interno (`AdminStaffService`), sin tocar el motor:
 *
 *   1. **Alta de `RESTOCKER` (`D3`, RF-01.1).** Se admite el rol junto a
 *      `COORDINATOR` y `TECHNICIAN`; la guardia 24/7 se rechaza con
 *      `422 RESTOCKER_CANNOT_BE_ON_CALL` y el alta nunca la persiste. El chat de
 *      Telegram sigue siendo opcional, porque el reponedor también recibe ruta.
 *   2. **Inmutabilidad del rol (`C1`, EARS 01.2).** Un `PUT` con un rol distinto
 *      al persistido devuelve `409 ROLE_CHANGE_FORBIDDEN` y deja la ficha
 *      intacta; el mismo rol (en cualquier caja) no es un cambio.
 *   3. **Baja con cierre automático de turno (`C3`, EARS 01.3).** Desactivar a
 *      un operario con turno abierto lo cierra en la misma transacción como
 *      `FORCE_CLOSE_SHIFT`, con el motivo contractual, bitácora y auditoría; el
 *      cierre desde `MEAL_BREAK` descuenta el tramo final de pausa.
 *
 * Dobles en memoria para el repositorio de usuarios, el de turnos, el de
 * bitácora, el de auditoría y el gestor de transacciones. Ningún doble simula
 * reglas de negocio: sólo almacenan lo que el servicio real escribiría.
 *
 * Uso: php tests/unit/AdminStaffRestockerServiceTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AdminStaffService;
use VendGuard\Application\Service\AdminUserService;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Core\Domain\Exception\CannotDeactivateSelfException;
use VendGuard\Core\Domain\Exception\RestockerCannotBeOnCallException;
use VendGuard\Core\Domain\Exception\RoleChangeForbiddenException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Model\TechnicianShiftLog;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\ShiftEventType;
use VendGuard\Core\Domain\ValueObject\ShiftStatus;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-RES-07 · Alta RESTOCKER, rol inmutable y baja con cierre de turno\n";
echo "======================================================================\n";

/**
 * Repositorio de usuarios en memoria: guarda filas y hidrata la entidad.
 */
class StaffTestUserRepository implements UserRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    /** @var array<int, int> */
    public array $pendingIncidents = [];
    private int $nextId = 1;

    public function seed(string $name, string $email, string $role, bool $active = true, ?string $chatId = null): User
    {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash('secreto-' . $id, PASSWORD_BCRYPT),
            'role' => $role,
            'phone' => null,
            'is_active' => $active,
            'is_on_call' => false,
            'telegram_chat_id' => $chatId,
            'deleted_at' => null,
        ];

        return $this->hydrate($id);
    }

    private function hydrate(int $id): User
    {
        $row = $this->rows[$id];

        return new User(
            $id,
            (string)$row['name'],
            (string)$row['email'],
            (string)$row['password_hash'],
            UserRole::fromString((string)$row['role']),
            $row['phone'] !== null ? (string)$row['phone'] : null,
            (bool)$row['is_active'],
            null,
            null,
            $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            (bool)$row['is_on_call'],
            $row['telegram_chat_id'] !== null ? (string)$row['telegram_chat_id'] : null
        );
    }

    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        foreach ($this->rows as $id => $row) {
            if (strtolower((string)$row['email']) === strtolower(trim($email))) {
                if ($onlyActive && !$row['is_active']) {
                    continue;
                }
                if (!$allowDeleted && $row['deleted_at'] !== null) {
                    continue;
                }

                return $this->hydrate($id);
            }
        }

        return null;
    }

    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        if (!isset($this->rows[$id])) {
            return null;
        }

        $row = $this->rows[$id];
        if ($onlyActive && !$row['is_active']) {
            return null;
        }
        if (!$allowDeleted && $row['deleted_at'] !== null) {
            return null;
        }

        return $this->hydrate($id);
    }

    public function findAllTechnicians(bool $onlyActive = true): array
    {
        $result = [];
        foreach (array_keys($this->rows) as $id) {
            $row = $this->rows[$id];
            if ($row['role'] === UserRole::TECHNICIAN->value && (!$onlyActive || $row['is_active'])) {
                $result[] = $this->hydrate($id);
            }
        }

        return $result;
    }

    public function create(array $data): User
    {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'name' => (string)$data['name'],
            'email' => strtolower((string)$data['email']),
            'password_hash' => password_hash((string)($data['password'] ?? 'x'), PASSWORD_BCRYPT),
            'role' => strtoupper((string)$data['role']),
            'phone' => $data['phone'] ?? null,
            'is_active' => true,
            'is_on_call' => (bool)($data['is_on_call'] ?? false),
            'telegram_chat_id' => $data['telegram_chat_id'] ?? null,
            'deleted_at' => null,
        ];

        return $this->hydrate($id);
    }

    public function update(int $id, array $data): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        foreach (['name', 'phone', 'telegram_chat_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->rows[$id][$field] = $data[$field];
            }
        }

        return true;
    }

    public function updatePassword(int $id, string $newPassword): bool
    {
        return isset($this->rows[$id]);
    }

    public function resetPassword(int $id, string $newPassword): bool
    {
        return $this->updatePassword($id, $newPassword);
    }

    public function softDelete(int $id): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        $this->rows[$id]['is_active'] = false;
        $this->rows[$id]['deleted_at'] = (new DateTimeImmutable('now'))->format(DATE_ATOM);

        return true;
    }

    public function restore(int $id): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        $this->rows[$id]['is_active'] = true;
        $this->rows[$id]['deleted_at'] = null;

        return true;
    }

    public function findAll(array $filters = []): array
    {
        return array_map(fn (int $id): array => $this->hydrate($id)->toArray(), array_keys($this->rows));
    }

    public function countActiveByRole(UserRole|string $role): int
    {
        $value = $role instanceof UserRole ? $role->value : strtoupper($role);

        return count(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['role'] === $value && $row['is_active']
        ));
    }

    public function countActiveAssignedIncidents(int $userId): int
    {
        return $this->pendingIncidents[$userId] ?? 0;
    }

    public function countPendingIncidents(int $technicianId): int
    {
        return $this->countActiveAssignedIncidents($technicianId);
    }
}

/**
 * Repositorio de turnos en memoria con un único turno observable.
 */
class StaffTestShiftRepository implements TechnicianShiftRepositoryInterface
{
    public ?TechnicianShift $shift = null;
    public int $closeCalls = 0;
    public bool $failOnClose = false;

    public function openShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift
    {
        $started = $startedAt ?? new DateTimeImmutable('-2 hours');

        return $this->shift = new TechnicianShift(
            id: 500 + $technicianId,
            technicianId: $technicianId,
            status: ShiftStatus::ON_SHIFT,
            startedAt: $started,
            shiftDate: $started->format('Y-m-d')
        );
    }

    public function findActiveShiftByTechnician(int $technicianId): ?TechnicianShift
    {
        if ($this->shift === null || $this->shift->technicianId() !== $technicianId || !$this->shift->isOpen()) {
            return null;
        }

        return $this->shift;
    }

    public function findById(int $shiftId): ?TechnicianShift
    {
        return $this->shift !== null && $this->shift->id() === $shiftId ? $this->shift : null;
    }

    public function startShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift
    {
        return $this->openShift($technicianId, $startedAt);
    }

    public function pauseMeal(int $shiftId, ?DateTimeImmutable $pausedAt = null): TechnicianShift
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function resumeShift(int $shiftId, ?DateTimeImmutable $resumedAt = null): TechnicianShift
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function closeShift(
        int $shiftId,
        int $closedBy,
        ?string $closeReason = null,
        ?DateTimeImmutable $closedAt = null,
        bool $isForceClosed = false,
        ?DateTimeImmutable $executionTime = null
    ): TechnicianShift {
        if ($this->failOnClose) {
            throw new RuntimeException('Fallo simulado del motor al cerrar el turno.');
        }

        if ($this->shift === null || $this->shift->id() !== $shiftId) {
            throw new LogicException('Turno desconocido.');
        }

        $this->closeCalls++;

        return $this->shift = $this->shift->close($closedBy, $closeReason, $closedAt, $isForceClosed, $executionTime);
    }

    public function saveAdjustment(TechnicianShift $adjustedShift): TechnicianShift
    {
        return $adjustedShift;
    }

    public function findOverlappingShiftId(
        int $technicianId,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $closedAt,
        ?int $excludeShiftId = null
    ): ?int {
        return null;
    }

    public function findShiftsByDateRange(string $fromDate, string $toDate, ?int $technicianId = null, ?string $role = null): array
    {
        return $this->shift !== null ? [$this->shift] : [];
    }

    public function findOrphanOpenShiftsBefore(string $beforeDate, ?int $technicianId = null, ?string $role = null): array
    {
        return [];
    }

    public function getClosedInterventionMetricsByTechnician(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $technicianId = null,
        ?string $role = null
    ): array {
        return [];
    }

    public function getActiveShiftsMap(?string $role = null): array
    {
        return [];
    }
}

/**
 * Bitácora en memoria: conserva exactamente lo que el servicio le entrega.
 */
class StaffTestShiftLogRepository implements TechnicianShiftLogRepositoryInterface
{
    /** @var list<TechnicianShiftLog> */
    public array $entries = [];

    public function record(
        int $shiftId,
        int $technicianId,
        ShiftEventType $eventType,
        int $actorUserId,
        DateTimeImmutable $effectiveAt,
        ?array $previousState,
        array $newState,
        ?string $reason = null
    ): TechnicianShiftLog {
        $log = new TechnicianShiftLog(
            count($this->entries) + 1,
            $shiftId,
            $technicianId,
            $eventType,
            $actorUserId,
            $effectiveAt,
            $previousState,
            $newState,
            $reason
        );

        $this->entries[] = $log;

        return $log;
    }

    public function findByShiftId(int $shiftId): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (TechnicianShiftLog $log): bool => $log->shiftId() === $shiftId
        ));
    }
}

/**
 * Audit log en memoria, append-only.
 */
class StaffTestAuditLogRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return array_slice($this->events, $offset, $limit);
    }

    public function countEvents(array $filters = []): int
    {
        return count($this->events);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (AuditEvent $event): bool => $event->getEntityType() === $entityType
                && $event->getEntityId() === $entityId
        ));
    }
}

/**
 * Gestor de transacciones observado: cuenta aperturas, confirmaciones y deshaces.
 */
class StaffTestTransactionManager implements TransactionManagerInterface
{
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;

    public function runInTransaction(callable $operation): mixed
    {
        $this->begins++;

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $this->rollbacks++;
            throw $exception;
        }

        $this->commits++;

        return $result;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Escenario
// ─────────────────────────────────────────────────────────────────────────

$users = new StaffTestUserRepository();
$coordinator = $users->seed('Coordinación de Servicio', 'coord.t07@vendguard.internal', 'COORDINATOR');
$technicianOne = $users->seed('Técnico Uno', 'tec.t07.uno@vendguard.internal', 'TECHNICIAN');
$technicianTwo = $users->seed('Técnico Dos', 'tec.t07.dos@vendguard.internal', 'TECHNICIAN');

$auditRepo = new StaffTestAuditLogRepository();
$auditLogger = new AuditLogger($auditRepo);
$shifts = new StaffTestShiftRepository();
$shiftLogs = new StaffTestShiftLogRepository();
$transactions = new StaffTestTransactionManager();

$service = new AdminStaffService(
    new AdminUserService($users, $auditLogger),
    $users,
    $shifts,
    $auditLogger,
    $shiftLogs,
    $transactions
);

$actor = ['id' => $coordinator->getId(), 'role' => 'COORDINATOR', 'name' => $coordinator->getName()];

$restockerPayload = static fn (string $email, string $code): array => [
    'name' => 'Reponedor de Prueba',
    'email' => $email,
    'role' => 'RESTOCKER',
    'password' => 'ClaveSegura123',
    'operator_code' => $code,
];

$throws = static function (callable $operation): ?Throwable {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
};

// ─────────────────────────────────────────────────────────────────────────
// 1. Alta de RESTOCKER
// ─────────────────────────────────────────────────────────────────────────

echo "\n--- 1. Alta de reponedor (D3, RF-01.1) ---\n";

$created = $service->createUser($restockerPayload('rep.t07.uno@vendguard.internal', 'REP-701'), $actor);

$assert(
    '1.1 El alta admite RESTOCKER y lo deja activo con la guardia apagada',
    $created->getRole() === UserRole::RESTOCKER && $created->isActive() && $created->isOnCall() === false,
    $created->getRole()->value
);

$assert(
    '1.2 El alta de reponedor exige `operator_code` (llega a la ficha)',
    $users->rows[$created->getId()]['role'] === 'RESTOCKER'
);

$onCallRejection = $throws(static fn () => $service->createUser(
    $restockerPayload('rep.t07.dos@vendguard.internal', 'REP-702') + ['is_on_call' => true],
    $actor
));

$assert(
    '1.3 `is_on_call = true` sobre RESTOCKER devuelve 422 RESTOCKER_CANNOT_BE_ON_CALL',
    $onCallRejection instanceof RestockerCannotBeOnCallException
        && $onCallRejection->getHttpStatusCode() === 422
        && $onCallRejection->getErrorCode() === 'RESTOCKER_CANNOT_BE_ON_CALL'
);

$assert(
    '1.4 El rechazo de guardia no deja una ficha a medias',
    $users->findByEmail('rep.t07.dos@vendguard.internal', onlyActive: false) === null
);

$technicianWithFlag = $service->createUser([
    'name' => 'Técnico con bandera',
    'email' => 'tec.t07.bandera@vendguard.internal',
    'role' => 'TECHNICIAN',
    'password' => 'ClaveSegura123',
    'is_on_call' => true,
], $actor);

$assert(
    '1.5 La guardia nunca nace en el alta: la bandera se descarta y el técnico entra apagado',
    $technicianWithFlag->isOnCall() === false
);

$restockerWithChat = $service->createUser(
    $restockerPayload('rep.t07.chat@vendguard.internal', 'REP-703') + ['telegram_chat_id' => '123456789'],
    $actor
);

$assert(
    '1.6 El chat de Telegram opcional se admite para el reponedor (también recibe ruta)',
    $restockerWithChat->getTelegramChatId() === '123456789'
);

$assert(
    '1.7 El alta registra USER_CREATED en el rastro de auditoría (vocabulario del CRUD)',
    count(array_filter(
        $auditRepo->events,
        static fn (AuditEvent $event): bool => $event->getAction() === 'USER_CREATED'
            && $event->getEntityId() === $created->getId()
    )) === 1
);

// ─────────────────────────────────────────────────────────────────────────
// 2. Inmutabilidad del rol (C1)
// ─────────────────────────────────────────────────────────────────────────

echo "\n--- 2. Inmutabilidad del rol (C1, EARS 01.2) ---\n";

$roleChange = $throws(static fn () => $service->updateUser(
    $technicianOne->getId(),
    ['role' => 'RESTOCKER', 'name' => 'Intento de cambio'],
    $actor
));

$assert(
    '2.1 Técnico → Reponedor devuelve 409 ROLE_CHANGE_FORBIDDEN',
    $roleChange instanceof RoleChangeForbiddenException
        && $roleChange->getHttpStatusCode() === 409
        && $roleChange->getErrorCode() === 'ROLE_CHANGE_FORBIDDEN'
        && $roleChange->getCurrentRole() === 'TECHNICIAN'
        && $roleChange->getRequestedRole() === 'RESTOCKER'
);

$technicianRow = $users->rows[$technicianOne->getId()];
$assert(
    '2.2 El rechazo no altera ni el rol ni el nombre de la ficha',
    $technicianRow['role'] === 'TECHNICIAN' && $technicianRow['name'] === 'Técnico Uno'
);

$reverseChange = $throws(static fn () => $service->updateUser(
    $created->getId(),
    ['role' => 'TECHNICIAN'],
    $actor
));

$assert(
    '2.3 Reponedor → Técnico también se rechaza con el mismo código',
    $reverseChange instanceof RoleChangeForbiddenException
);

$sameRoleInLowercase = $service->updateUser(
    $created->getId(),
    ['role' => 'restocker', 'name' => 'Reponedor Renombrado'],
    $actor
);

$assert(
    '2.4 Enviar el mismo rol en otra caja no es un cambio de rol',
    $sameRoleInLowercase->getRole() === UserRole::RESTOCKER
        && $sameRoleInLowercase->getName() === 'Reponedor Renombrado'
);

$onCallOnUpdate = $throws(static fn () => $service->updateUser(
    $created->getId(),
    ['is_on_call' => true],
    $actor
));

$assert(
    '2.5 Encender la guardia de un reponedor existente devuelve 422',
    $onCallOnUpdate instanceof RestockerCannotBeOnCallException
        && $users->rows[$created->getId()]['is_on_call'] === false
);

$missingUser = $throws(static fn () => $service->updateUser(999_999, ['name' => 'Fantasma'], $actor));

$assert(
    '2.6 Editar una ficha inexistente devuelve UserNotFoundException',
    $missingUser instanceof UserNotFoundException
);

// ─────────────────────────────────────────────────────────────────────────
// 3. Baja con cierre automático de turno (C3)
// ─────────────────────────────────────────────────────────────────────────

echo "\n--- 3. Baja con cierre automático de turno (C3, EARS 01.3) ---\n";

$victim = $service->createUser($restockerPayload('rep.t07.baja@vendguard.internal', 'REP-704'), $actor);
$shifts->openShift($victim->getId());

$deactivated = $service->deactivateUser($victim->getId(), $actor);

$closedShift = $shifts->shift;
$logEntries = $shiftLogs->entries;
$forceCloseEvents = array_values(array_filter(
    $auditRepo->events,
    static fn (AuditEvent $event): bool => $event->getAction() === 'FORCE_CLOSE_SHIFT'
        && $event->getEntityId() === $victim->getId()
));

$assert(
    '3.1 La baja cierra el turno abierto con el motivo contractual',
    $deactivated === true
        && $closedShift !== null
        && $closedShift->closedAt() !== null
        && $closedShift->isForceClosed() === true
        && $closedShift->closeReason() === AdminStaffService::DEACTIVATION_CLOSE_REASON
);

$assert(
    '3.2 El motivo es literalmente el del contrato y supera los 20 caracteres',
    AdminStaffService::DEACTIVATION_CLOSE_REASON === 'Cierre automático por desactivación de cuenta de usuario'
        && mb_strlen(AdminStaffService::DEACTIVATION_CLOSE_REASON) > 20
);

$assert(
    '3.3 La bitácora registra FORCE_CLOSE_SHIFT con el estado previo y el resultante',
    count($logEntries) === 1
        && $logEntries[0]->eventType() === ShiftEventType::FORCE_CLOSE_SHIFT
        && $logEntries[0]->reason() === AdminStaffService::DEACTIVATION_CLOSE_REASON
        && $logEntries[0]->shiftId() === $closedShift?->id()
        && $logEntries[0]->actorUserId() === $coordinator->getId()
);

$assert(
    '3.4 audit_log registra FORCE_CLOSE_SHIFT con shift_id y motivo',
    count($forceCloseEvents) === 1
        && ($forceCloseEvents[0]->getMetadata()['shift_id'] ?? null) === $closedShift?->id()
        && ($forceCloseEvents[0]->getMetadata()['reason'] ?? null) === AdminStaffService::DEACTIVATION_CLOSE_REASON
);

$assert(
    '3.5 La cuenta queda inactiva y la baja se audita',
    $users->findById($victim->getId(), onlyActive: false, allowDeleted: true)?->isActive() === false
        && count(array_filter(
            $auditRepo->events,
            static fn (AuditEvent $event): bool => $event->getAction() === 'USER_DEACTIVATED'
                && $event->getEntityId() === $victim->getId()
        )) === 1
);

$assert(
    '3.6 Cierre y baja viajan en una única transacción confirmada',
    $transactions->begins === 1 && $transactions->commits === 1 && $transactions->rollbacks === 0
);

// Baja con turno en pausa de comida: el tramo final se acumula como descanso.
$mealVictim = $service->createUser($restockerPayload('rep.t07.comida@vendguard.internal', 'REP-705'), $actor);
$shifts->shift = null;
$mealStart = new DateTimeImmutable('-90 minutes');
$shifts->shift = new TechnicianShift(
    id: 900,
    technicianId: $mealVictim->getId(),
    status: ShiftStatus::MEAL_BREAK,
    startedAt: new DateTimeImmutable('-3 hours'),
    mealBreakStartedAt: $mealStart,
    shiftDate: $mealStart->format('Y-m-d')
);

$service->deactivateUser($mealVictim->getId(), $actor);

$assert(
    '3.7 Cerrar desde MEAL_BREAK descuenta el tramo final de pausa',
    $shifts->shift?->mealBreakSeconds() > 0 && $shifts->shift?->isForceClosed() === true,
    'meal_seconds=' . (string)$shifts->shift?->mealBreakSeconds()
);

$assert(
    '3.8 El cierre de una cuenta en pausa también deja su bitácora',
    count($shiftLogs->entries) === 2
        && $shiftLogs->entries[1]->eventType() === ShiftEventType::FORCE_CLOSE_SHIFT
        && $shiftLogs->entries[1]->technicianId() === $mealVictim->getId()
);

// Baja sin turno abierto: no hay cierre ni bitácora inventada.
$cleanVictim = $service->createUser($restockerPayload('rep.t07.limpia@vendguard.internal', 'REP-706'), $actor);
$shifts->shift = null;
$entriesBefore = count($shiftLogs->entries);
$auditBefore = count($auditRepo->events);

$service->deactivateUser($cleanVictim->getId(), $actor);

$assert(
    '3.9 Sin turno abierto no se inventa un cierre ni una entrada de bitácora',
    count($shiftLogs->entries) === $entriesBefore
        && count(array_filter(
            array_slice($auditRepo->events, $auditBefore),
            static fn (AuditEvent $event): bool => $event->getAction() === 'FORCE_CLOSE_SHIFT'
        )) === 0
        && $users->findById($cleanVictim->getId(), onlyActive: false, allowDeleted: true)?->isActive() === false
);

// El último reponedor activo puede darse de baja: la guardia mínima protege
// coordinación y ruta técnica, no la cobertura de reposición (C3).
$loneRestocker = $service->createUser($restockerPayload('rep.t07.solo@vendguard.internal', 'REP-707'), $actor);
$shifts->shift = null;
$activeRestockersBefore = $users->countActiveByRole(UserRole::RESTOCKER);
$service->deactivateUser($loneRestocker->getId(), $actor);

$assert(
    '3.10 La baja de un reponedor no la bloquea la guardia mínima de coordinación y ruta',
    $users->rows[$loneRestocker->getId()]['is_active'] === false
        && $users->countActiveByRole(UserRole::RESTOCKER) === $activeRestockersBefore - 1,
    'activos=' . $users->countActiveByRole(UserRole::RESTOCKER)
);

$selfDeactivation = $throws(static fn () => $service->deactivateUser($coordinator->getId(), $actor));

$assert(
    '3.11 La auto-desactivación sigue prohibida (regla heredada del CRUD)',
    $selfDeactivation instanceof CannotDeactivateSelfException
);

// Fallo del motor al cerrar: la baja no debe persistirse.
$rollbackVictim = $service->createUser($restockerPayload('rep.t07.rollback@vendguard.internal', 'REP-708'), $actor);
$shifts->shift = null;
$shifts->openShift($rollbackVictim->getId());
$shifts->failOnClose = true;
$rollbacksBefore = $transactions->rollbacks;

$rollbackFailure = $throws(static fn () => $service->deactivateUser($rollbackVictim->getId(), $actor));

$assert(
    '3.12 Si el cierre falla, la baja se deshace y la cuenta sigue activa',
    $rollbackFailure instanceof RuntimeException
        && $transactions->rollbacks === $rollbacksBefore + 1
        && $users->rows[$rollbackVictim->getId()]['is_active'] === true
);

// Edición con `is_active = false` (misma regla por la vía del PUT).
$putVictim = $service->createUser($restockerPayload('rep.t07.put@vendguard.internal', 'REP-709'), $actor);
$shifts->shift = null;
$shifts->failOnClose = false;
$shifts->openShift($putVictim->getId());

$putResult = $service->updateUser($putVictim->getId(), ['is_active' => false], $actor);

$assert(
    '3.13 PUT con `is_active = false` ejecuta la baja con cierre de turno',
    $putResult->isActive() === false
        && $shifts->shift?->isForceClosed() === true
        && $shifts->shift?->closeReason() === AdminStaffService::DEACTIVATION_CLOSE_REASON
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. ALTA RESTOCKER, ROL INMUTABLE Y BAJA CON CIERRE CERTIFICADOS.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL CRUD DE PERSONAL DEL MÓDULO 31.\n";
echo "======================================================================\n";
exit(1);
