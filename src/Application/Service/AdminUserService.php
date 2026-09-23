<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\CannotDeactivateSelfException;
use VendGuard\Core\Domain\Exception\InactiveRecordCollisionException;
use VendGuard\Core\Domain\Exception\MinimumActiveStaffException;
use VendGuard\Core\Domain\Exception\PendingIncidentsBlockedException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;

/**
 * AdminUserService
 * 
 * Servicio de Aplicación responsable de orquestar la administración integral del personal interno
 * (Técnicos de Ruta y Coordinadores de Servicio).
 * 
 * Aplica políticas estrictas de seguridad (Bcrypt con claves >= 8 caracteres, RNF-04, Art. V.4),
 * blindaje contra auto-desactivación de la sesión activa (Caso Límite 8, EARS 3.5),
 * salvaguarda de guardia mínima operativa (Caso Límite 9, EARS 3.6),
 * bloqueo ante técnicos con averías asignadas pendientes (Decisión QA 2, EARS 3.7)
 * y registro append-only sincrónico en audit_log protegiendo permanentemente hashes y credenciales (RF-03, RF-05, Art. III).
 */
class AdminUserService
{
    private UserRepositoryInterface $userRepo;
    private AuditLogger $auditLogger;

    public function __construct(
        UserRepositoryInterface $userRepo,
        ?AuditLogger $auditLogger = null
    ) {
        $this->userRepo = $userRepo;
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Recupera el catálogo de personal interno con filtros de estado, rol y búsqueda (RF-03, EARS 3.11).
     *
     * @param string $status 'active', 'inactive' o 'all'.
     * @param string $role 'all', 'TECHNICIAN' o 'COORDINATOR'.
     * @param string|null $search Término de búsqueda en nombre, email o teléfono.
     * @return array<array<string, mixed>>
     */
    public function listUsers(string $status = 'all', string $role = 'all', ?string $search = null): array
    {
        return $this->userRepo->findAll([
            'status' => $status,
            'role'   => $role,
            'search' => $search,
        ]);
    }

    /**
     * Recupera un usuario interno por su identificador primario (RF-03).
     *
     * @param int $id ID del usuario.
     * @param bool $allowDeleted Si es true, permite recuperar registros inactivos o dados de baja.
     * @return User
     * @throws UserNotFoundException Si el usuario no existe.
     */
    public function getUser(int $id, bool $allowDeleted = false): User
    {
        $user = $this->userRepo->findById($id, onlyActive: false, allowDeleted: $allowDeleted);
        if ($user === null) {
            throw new UserNotFoundException($id);
        }

        return $user;
    }

    /**
     * Da de alta a un nuevo técnico de ruta o coordinador con credenciales Bcrypt seguras (RF-03, EARS 3.1).
     *
     * @param array<string, mixed> $data Datos de entrada (name, email, role, phone, password).
     * @param array{id: int|null, role: string, name: string} $actor Usuario coordinador actuante.
     * @param string|null $clientIp Dirección IP del cliente para auditoría.
     * @return User Usuario recién creado.
     * @throws InvalidArgumentException Si algún dato no cumple las especificaciones de formato o seguridad.
     * @throws InactiveRecordCollisionException Si el correo electrónico ya existe (activo o inactivo).
     */
    public function createUser(array $data, array $actor, ?string $clientIp = null): User
    {
        $name = trim((string)($data['name'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $rawRole = strtoupper(trim((string)($data['role'] ?? '')));
        $phone = isset($data['phone']) && $data['phone'] !== '' ? trim((string)$data['phone']) : null;
        $password = (string)($data['password'] ?? '');

        $this->validateName($name);
        $this->validateEmail($email);
        $role = $this->validateRole($rawRole);
        $this->validatePasswordStrength($password);

        if ($phone !== null) {
            $this->validatePhone($phone);
        }

        // Detección de colisión de email (RF-03, EARS 3.2)
        $existing = $this->userRepo->findByEmail($email, onlyActive: false, allowDeleted: true);
        if ($existing !== null) {
            if ($existing->isActive()) {
                throw new InactiveRecordCollisionException(
                    'USER_ALREADY_EXISTS_ACTIVE',
                    "El correo electrónico '{$email}' ya se encuentra registrado y activo.",
                    $existing->getId(),
                    'USER',
                    false
                );
            }

            throw new InactiveRecordCollisionException(
                'USER_ALREADY_EXISTS_INACTIVE',
                "El correo electrónico '{$email}' pertenece a un usuario inactivo.",
                $existing->getId(),
                'USER',
                true
            );
        }

        $created = $this->userRepo->create([
            'name'     => $name,
            'email'    => $email,
            'role'     => $role->value,
            'phone'    => $phone,
            'password' => $password,
        ]);

        // Auditoría inmutable sincrónica (RF-05, EARS 4.1) - NUNCA persistir passwords ni hashes
        $metadata = [];
        if ($clientIp !== null && $clientIp !== '') {
            $metadata['ip'] = $clientIp;
        }

        $this->auditLogger->logUserEvent(
            $created->getId(),
            'USER_CREATED',
            $actor,
            null,
            [
                'name'  => $created->getName(),
                'email' => $created->getEmail(),
                'role'  => $created->getRole()->value,
                'phone' => $created->getPhone(),
            ],
            !empty($metadata) ? $metadata : null
        );

        return $created;
    }

    /**
     * Actualiza datos de contacto de un usuario interno (RF-03, EARS 3.3).
     * El email y el rol son inmutables para preservar la integridad y prevenir escaladas de privilegios.
     *
     * @param int $id ID del usuario a modificar.
     * @param array<string, mixed> $data Campos a actualizar (name, phone).
     * @param array{id: int|null, role: string, name: string} $actor Usuario coordinador actuante.
     * @param string|null $clientIp
     * @return User Usuario con datos actualizados.
     * @throws UserNotFoundException Si el usuario no existe.
     * @throws InvalidArgumentException Si los datos proporcionados son inválidos.
     */
    public function updateUser(int $id, array $data, array $actor, ?string $clientIp = null): User
    {
        $existing = $this->getUser($id, allowDeleted: false);

        $updateData = [];
        $changedFields = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string)$data['name']);
            $this->validateName($name);
            if ($name !== $existing->getName()) {
                $updateData['name'] = $name;
                $changedFields[] = 'name';
            }
        }

        if (array_key_exists('phone', $data)) {
            $phone = $data['phone'] !== null && $data['phone'] !== '' ? trim((string)$data['phone']) : null;
            if ($phone !== null) {
                $this->validatePhone($phone);
            }
            if ($phone !== $existing->getPhone()) {
                $updateData['phone'] = $phone;
                $changedFields[] = 'phone';
            }
        }

        if (empty($updateData)) {
            return $existing;
        }

        $this->userRepo->update($id, $updateData);
        $updated = $this->getUser($id, allowDeleted: false);

        // Auditoría diferencial sincrónica (RF-05, EARS 4.2)
        $metadata = ['changed_fields' => $changedFields];
        if ($clientIp !== null && $clientIp !== '') {
            $metadata['ip'] = $clientIp;
        }

        $this->auditLogger->logUserEvent(
            $id,
            'USER_UPDATED',
            $actor,
            [
                'name'  => $existing->getName(),
                'phone' => $existing->getPhone(),
            ],
            [
                'name'  => $updated->getName(),
                'phone' => $updated->getPhone(),
            ],
            $metadata
        );

        return $updated;
    }

    /**
     * Restablece administrativamente la contraseña de un usuario interno (RF-03, EARS 3.4).
     * Exige una longitud mínima de 8 caracteres y omite la credencial en logs de auditoría (RNF-04).
     *
     * @param int $id ID del usuario al que se le cambiará la contraseña.
     * @param string $newPassword Nueva contraseña en texto plano.
     * @param array{id: int|null, role: string, name: string} $actor Usuario coordinador actuante.
     * @param string|null $clientIp
     * @return bool
     * @throws UserNotFoundException Si el usuario no existe.
     * @throws InvalidArgumentException Si la contraseña no alcanza 8 caracteres.
     */
    public function resetPassword(int $id, string $newPassword, array $actor, ?string $clientIp = null): bool
    {
        $existing = $this->getUser($id, allowDeleted: false);
        $this->validatePasswordStrength($newPassword);

        $success = $this->userRepo->resetPassword($id, $newPassword);
        if (!$success) {
            return false;
        }

        // Auditoría inmutable de reseteo sin filtrar claves ni hashes (RF-05, EARS 4.5, RNF-04)
        $metadata = [
            'reset_by_coordinator_id' => isset($actor['id']) ? (int)$actor['id'] : null,
        ];
        if ($clientIp !== null && $clientIp !== '') {
            $metadata['ip'] = $clientIp;
        }

        $this->auditLogger->logUserEvent(
            $id,
            'USER_PASSWORD_RESET',
            $actor,
            null,
            ['password_reset' => true],
            $metadata
        );

        return true;
    }

    /**
     * Da de baja lógica a un usuario interno aplicando salvaguardas infranqueables (RF-03, EARS 3.5 a 3.9).
     *
     * Reglas de negocio:
     * 1. Auto-desactivación bloqueada: El coordinador en sesión no puede desactivarse a sí mismo (Caso Límite 8, HTTP 403).
     * 2. Guardia mínima activa: Debe quedar al menos 1 técnico y 1 coordinador activos en la plataforma (Caso Límite 9, HTTP 409).
     * 3. Técnico con averías pendientes: Bloqueado si tiene tickets en ASSIGNED, IN_PROGRESS o PENDING_PARTS (Decisión QA 2, HTTP 409).
     *
     * @param int $id ID del usuario a desactivar.
     * @param array{id: int|null, role: string, name: string} $actor Usuario coordinador actuante.
     * @param string|null $clientIp
     * @return bool
     * @throws UserNotFoundException Si el usuario no existe.
     * @throws CannotDeactivateSelfException Si intenta auto-desactivar su sesión.
     * @throws MinimumActiveStaffException Si se vulnera la guardia mínima.
     * @throws PendingIncidentsBlockedException Si el técnico tiene averías activas asignadas.
     */
    public function deactivateUser(int $id, array $actor, ?string $clientIp = null): bool
    {
        $targetUser = $this->getUser($id, allowDeleted: false);

        // 1. Bloqueo de auto-desactivación del usuario en sesión activa (Caso Límite 8, EARS 3.5)
        $actorId = isset($actor['id']) ? (int)$actor['id'] : null;
        if ($actorId !== null && $actorId === $id) {
            throw new CannotDeactivateSelfException('Acción denegada: no puede desactivar su propio usuario en sesión activa.');
        }

        // 2. Salvaguarda de guardia mínima operativa (Caso Límite 9, EARS 3.6)
        $activeRoleCount = $this->userRepo->countActiveByRole($targetUser->getRole());
        if ($activeRoleCount <= 1) {
            throw new MinimumActiveStaffException(
                'No se puede dar de baja al usuario porque debe existir al menos un coordinador y un técnico activo en la plataforma.',
                $targetUser->getRole()->value
            );
        }

        // 3. Bloqueo por averías asignadas pendientes en ruta técnica (Decisión QA 2, EARS 3.7)
        if ($targetUser->isTechnician()) {
            $pendingTickets = $this->userRepo->countActiveAssignedIncidents($id);
            if ($pendingTickets > 0) {
                throw new PendingIncidentsBlockedException(
                    $pendingTickets,
                    $id,
                    "No se puede dar de baja al técnico porque tiene {$pendingTickets} averías asignadas pendientes. Reasigne sus averías desde el panel de triaje antes de darlo de baja."
                );
            }
        }

        // 4. Ejecución del borrado lógico
        $success = $this->userRepo->softDelete($id);
        if (!$success) {
            return false;
        }

        // 5. Auditoría inmutable sincrónica (RF-05, EARS 4.4)
        $metadata = [
            'role'   => $targetUser->getRole()->value,
            'reason' => 'Baja administrativa',
        ];
        if ($clientIp !== null && $clientIp !== '') {
            $metadata['ip'] = $clientIp;
        }

        $this->auditLogger->logUserEvent(
            $id,
            'USER_DEACTIVATED',
            $actor,
            ['is_active' => true],
            ['is_active' => false],
            $metadata
        );

        return true;
    }

    /**
     * Reactiva una cuenta de usuario previamente dada de baja lógica (RF-03, EARS 3.10).
     *
     * @param int $id ID del usuario a reactivar.
     * @param array{id: int|null, role: string, name: string} $actor Usuario coordinador actuante.
     * @param string|null $clientIp
     * @return User Usuario restaurado en estado activo.
     * @throws UserNotFoundException Si el usuario no existe.
     */
    public function reactivateUser(int $id, array $actor, ?string $clientIp = null): User
    {
        $user = $this->getUser($id, allowDeleted: true);

        if ($user->isActive()) {
            return $user;
        }

        $this->userRepo->restore($id);
        $restored = $this->getUser($id, allowDeleted: false);

        // Auditoría inmutable sincrónica (RF-05, EARS 4.4)
        $metadata = [
            'role'   => $restored->getRole()->value,
            'reason' => 'Reactivación administrativa',
        ];
        if ($clientIp !== null && $clientIp !== '') {
            $metadata['ip'] = $clientIp;
        }

        $this->auditLogger->logUserEvent(
            $id,
            'USER_REACTIVATED',
            $actor,
            ['is_active' => false],
            ['is_active' => true],
            $metadata
        );

        return $restored;
    }

    // -------------------------------------------------------------
    // Validaciones de Dominio Internas
    // -------------------------------------------------------------

    private function validateName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('El nombre completo del usuario es obligatorio.');
        }

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('El nombre del usuario debe tener entre 2 y 100 caracteres.');
        }
    }

    private function validateEmail(string $email): void
    {
        if (trim($email) === '') {
            throw new InvalidArgumentException('La dirección de correo electrónico es obligatoria.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("La dirección de correo electrónico '{$email}' no tiene un formato válido.");
        }
    }

    private function validateRole(string $rawRole): UserRole
    {
        $role = UserRole::tryFrom($rawRole);
        if ($role === null) {
            throw new InvalidArgumentException("El rol '{$rawRole}' no es válido. Debe ser TECHNICIAN o COORDINATOR.");
        }

        return $role;
    }

    private function validatePasswordStrength(string $password): void
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('La contraseña debe contener al menos 8 caracteres de longitud.');
        }
    }

    private function validatePhone(string $phone): void
    {
        if (!preg_match('/^[0-9+ -]{6,25}$/', $phone)) {
            throw new InvalidArgumentException("El teléfono '{$phone}' no tiene un formato válido. Debe contener entre 6 y 25 caracteres numéricos.");
        }
    }
}
