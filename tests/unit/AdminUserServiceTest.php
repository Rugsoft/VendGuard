<?php

declare(strict_types=1);

/**
 * AdminUserServiceTest
 * 
 * Suite de pruebas unitarias para AdminUserService (Tarea T-ADM-09).
 * Valida contraseñas seguras (>= 8 caracteres), auto-desactivación bloqueada (403),
 * guardia mínima operativa (409), bloqueo por averías asignadas pendientes (409),
 * colisiones de email activo/inactivo (409), reseteo de claves y auditoría inmutable
 * sin exponer credenciales ni hashes (RF-03, RF-05, Art. III y Art. V.4).
 * 
 * Dogma Vanilla: Cero dependencias externas, PHP 8.2+ puro.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AdminUserService;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Core\Domain\Exception\CannotDeactivateSelfException;
use VendGuard\Core\Domain\Exception\InactiveRecordCollisionException;
use VendGuard\Core\Domain\Exception\MinimumActiveStaffException;
use VendGuard\Core\Domain\Exception\PendingIncidentsBlockedException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - AdminUserServiceTest (T-ADM-09)\n";
echo "======================================================================\n\n";

$assertions = 0;

function assertCondition(bool $cond, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$cond) {
        echo "  [FAIL] {$message}\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

/**
 * Repositorio en memoria para Usuarios Internos.
 */
class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<int, User> */
    public array $users = [];
    /** @var array<int, int> [userId => pendingCount] */
    public array $pendingIncidents = [];
    private int $nextId = 1;

    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        $normalized = strtolower(trim($email));
        foreach ($this->users as $u) {
            if ($u->getEmail() === $normalized) {
                if ($onlyActive && !$u->isActive()) {
                    continue;
                }
                if (!$allowDeleted && $u->isSoftDeleted()) {
                    continue;
                }
                return $u;
            }
        }
        return null;
    }

    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        if (!isset($this->users[$id])) {
            return null;
        }
        $u = $this->users[$id];
        if ($onlyActive && !$u->isActive()) {
            return null;
        }
        if (!$allowDeleted && $u->isSoftDeleted()) {
            return null;
        }
        return $u;
    }

    public function findAllTechnicians(bool $onlyActive = true): array
    {
        $res = [];
        foreach ($this->users as $u) {
            if ($u->isTechnician()) {
                if ($onlyActive && !$u->isActive()) {
                    continue;
                }
                $res[] = $u;
            }
        }
        return $res;
    }

    public function create(array $data): User
    {
        $id = $this->nextId++;
        $passwordHash = password_hash((string)($data['password'] ?? 'secret123'), PASSWORD_BCRYPT);
        $role = $data['role'] instanceof UserRole ? $data['role'] : UserRole::fromString((string)$data['role']);

        $user = new User(
            id: $id,
            name: (string)$data['name'],
            email: strtolower((string)$data['email']),
            passwordHash: $passwordHash,
            role: $role,
            phone: isset($data['phone']) ? (string)$data['phone'] : null,
            isActive: true,
            createdAt: date('Y-m-d H:i:s'),
            updatedAt: date('Y-m-d H:i:s'),
            deletedAt: null
        );

        $this->users[$id] = $user;
        return $user;
    }

    public function update(int $id, array $data): bool
    {
        if (!isset($this->users[$id])) {
            return false;
        }
        $existing = $this->users[$id];
        $this->users[$id] = new User(
            id: $existing->getId(),
            name: (string)($data['name'] ?? $existing->getName()),
            email: $existing->getEmail(),
            passwordHash: $existing->getPasswordHash(),
            role: $existing->getRole(),
            phone: array_key_exists('phone', $data) ? ($data['phone'] !== null ? (string)$data['phone'] : null) : $existing->getPhone(),
            isActive: $existing->isActive(),
            createdAt: $existing->getCreatedAt(),
            updatedAt: date('Y-m-d H:i:s'),
            deletedAt: $existing->getDeletedAt()
        );
        return true;
    }

    public function updatePassword(int $id, string $newPassword): bool
    {
        if (!isset($this->users[$id])) {
            return false;
        }
        $existing = $this->users[$id];
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $this->users[$id] = new User(
            id: $existing->getId(),
            name: $existing->getName(),
            email: $existing->getEmail(),
            passwordHash: $newHash,
            role: $existing->getRole(),
            phone: $existing->getPhone(),
            isActive: $existing->isActive(),
            createdAt: $existing->getCreatedAt(),
            updatedAt: date('Y-m-d H:i:s'),
            deletedAt: $existing->getDeletedAt()
        );
        return true;
    }

    public function resetPassword(int $id, string $newPassword): bool
    {
        return $this->updatePassword($id, $newPassword);
    }

    public function softDelete(int $id): bool
    {
        if (!isset($this->users[$id])) {
            return false;
        }
        $existing = $this->users[$id];
        $this->users[$id] = new User(
            id: $existing->getId(),
            name: $existing->getName(),
            email: $existing->getEmail(),
            passwordHash: $existing->getPasswordHash(),
            role: $existing->getRole(),
            phone: $existing->getPhone(),
            isActive: false,
            createdAt: $existing->getCreatedAt(),
            updatedAt: date('Y-m-d H:i:s'),
            deletedAt: date('Y-m-d H:i:s')
        );
        return true;
    }

    public function restore(int $id): bool
    {
        if (!isset($this->users[$id])) {
            return false;
        }
        $existing = $this->users[$id];
        $this->users[$id] = new User(
            id: $existing->getId(),
            name: $existing->getName(),
            email: $existing->getEmail(),
            passwordHash: $existing->getPasswordHash(),
            role: $existing->getRole(),
            phone: $existing->getPhone(),
            isActive: true,
            createdAt: $existing->getCreatedAt(),
            updatedAt: date('Y-m-d H:i:s'),
            deletedAt: null
        );
        return true;
    }

    public function findAll(array $filters = []): array
    {
        $status = $filters['status'] ?? 'all';
        $role = $filters['role'] ?? 'all';
        $search = isset($filters['search']) ? strtolower(trim((string)$filters['search'])) : null;

        $res = [];
        foreach ($this->users as $u) {
            if ($status === 'active' && !$u->isActive()) {
                continue;
            }
            if ($status === 'inactive' && $u->isActive()) {
                continue;
            }
            if ($role !== 'all' && $u->getRole()->value !== $role) {
                continue;
            }
            if ($search !== null && $search !== '') {
                $match = str_contains(strtolower($u->getName()), $search)
                    || str_contains(strtolower($u->getEmail()), $search)
                    || ($u->getPhone() !== null && str_contains(strtolower($u->getPhone()), $search));
                if (!$match) {
                    continue;
                }
            }

            $arr = $u->toArray();
            $arr['active_assigned_incidents_count'] = $this->countActiveAssignedIncidents($u->getId());
            $res[] = $arr;
        }
        return $res;
    }

    public function countActiveByRole(UserRole|string $role): int
    {
        $roleValue = $role instanceof UserRole ? $role->value : strtoupper((string)$role);
        $c = 0;
        foreach ($this->users as $u) {
            if ($u->isActive() && $u->getRole()->value === $roleValue) {
                $c++;
            }
        }
        return $c;
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
 * Repositorio en memoria para AuditLog.
 */
class InMemoryAuditLogRepository implements AuditLogRepositoryInterface
{
    /** @var array<int, AuditEvent> */
    public array $events = [];
    private int $nextId = 1;

    public function log(AuditEvent $event): AuditEvent
    {
        $logged = new AuditEvent(
            id: $this->nextId++,
            entityType: $event->getEntityType(),
            entityId: $event->getEntityId(),
            action: $event->getAction(),
            userId: $event->getUserId(),
            userRole: $event->getUserRole(),
            userName: $event->getUserName(),
            previousState: $event->getPreviousState(),
            newState: $event->getNewState(),
            metadata: $event->getMetadata(),
            createdAt: date('Y-m-d H:i:s')
        );

        $this->events[] = $logged;
        return $logged;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array { return $this->events; }
    public function countEvents(array $filters = []): int { return count($this->events); }
    public function findByEntity(string $entityType, int $entityId): array { return []; }
}

$userRepo = new InMemoryUserRepository();
$auditRepo = new InMemoryAuditLogRepository();
$auditLogger = new AuditLogger($auditRepo);
$service = new AdminUserService($userRepo, $auditLogger);

$coordinatorActor = [
    'id'   => 1,
    'role' => 'COORDINATOR',
    'name' => 'Elena Coordinadora',
];

// Semillas iniciales: 2 coordinadores y 2 técnicos
$coord1 = $userRepo->create([
    'name'     => 'Elena Coordinadora',
    'email'    => 'elena.coord@vendguard.internal',
    'role'     => 'COORDINATOR',
    'phone'    => '688001122',
    'password' => 'CoordSegura2026!',
]);
$coord2 = $userRepo->create([
    'name'     => 'Marcos Coordinador',
    'email'    => 'marcos.coord@vendguard.internal',
    'role'     => 'COORDINATOR',
    'phone'    => '688001133',
    'password' => 'CoordMarcos2026!',
]);
$tech1 = $userRepo->create([
    'name'     => 'Carlos Rodríguez',
    'email'    => 'carlos.tec@vendguard.internal',
    'role'     => 'TECHNICIAN',
    'phone'    => '677998811',
    'password' => 'TecnicoClave2026!',
]);
$tech2 = $userRepo->create([
    'name'     => 'Lucía Técnica',
    'email'    => 'lucia.tec@vendguard.internal',
    'role'     => 'TECHNICIAN',
    'phone'    => '677998822',
    'password' => 'LuciaClave2026!',
]);

// -------------------------------------------------------------
// Caso 1: Creación de usuario y validaciones de contraseña segura
// -------------------------------------------------------------
echo "--- Caso 1: Creación de usuario y validaciones de contraseña segura ---\n";

// 1.1 Rechazar contraseña corta (< 8 caracteres)
$shortPwdCaught = false;
try {
    $service->createUser([
        'name'     => 'Nuevo Técnico',
        'email'    => 'nuevo.tec@vendguard.internal',
        'role'     => 'TECHNICIAN',
        'password' => 'corta7!',
    ], $coordinatorActor);
} catch (InvalidArgumentException $e) {
    $shortPwdCaught = true;
    assertCondition(str_contains($e->getMessage(), '8 caracteres'), '1.1 Rechaza contraseña de menos de 8 caracteres');
}
assertCondition($shortPwdCaught, '1.2 Excepción lanzada ante contraseña débil');

// 1.3 Rechazar email inválido
$invalidEmailCaught = false;
try {
    $service->createUser([
        'name'     => 'Nuevo Técnico',
        'email'    => 'formato-invalido',
        'role'     => 'TECHNICIAN',
        'password' => 'PasswordValido2026!',
    ], $coordinatorActor);
} catch (InvalidArgumentException $e) {
    $invalidEmailCaught = true;
    assertCondition(str_contains($e->getMessage(), 'no tiene un formato válido'), '1.3 Rechaza email sin formato correcto');
}
assertCondition($invalidEmailCaught, '1.4 Excepción lanzada ante email malformado');

// 1.5 Rechazar rol no permitido
$invalidRoleCaught = false;
try {
    $service->createUser([
        'name'     => 'Admin Supremo',
        'email'    => 'admin@vendguard.internal',
        'role'     => 'SUPERADMIN',
        'password' => 'PasswordValido2026!',
    ], $coordinatorActor);
} catch (InvalidArgumentException $e) {
    $invalidRoleCaught = true;
    assertCondition(str_contains($e->getMessage(), 'TECHNICIAN o COORDINATOR'), '1.5 Rechaza rol no contemplado');
}
assertCondition($invalidRoleCaught, '1.6 Excepción lanzada ante rol inválido');

// 1.7 Creación exitosa de nuevo técnico con auditoría
$newTech = $service->createUser([
    'name'     => 'Santiago Gómez',
    'email'    => 'santiago.tec@vendguard.internal',
    'role'     => 'TECHNICIAN',
    'phone'    => '655223344',
    'password' => 'PasswordSegura2026!',
], $coordinatorActor, '192.168.1.50');

assertCondition($newTech instanceof User, '1.7 createUser devuelve instancia de User');
assertCondition($newTech->getEmail() === 'santiago.tec@vendguard.internal', '1.8 Email normalizado en minúsculas');
assertCondition($newTech->getRole() === UserRole::TECHNICIAN, '1.9 Rol asignado TECHNICIAN');
assertCondition($newTech->isActive(), '1.10 Usuario creado en estado activo');

// Verificar auditoría USER_CREATED
$lastEvent = end($auditRepo->events);
assertCondition($lastEvent->getEntityType() === 'USER', '1.11 Entidad de auditoría es USER');
assertCondition($lastEvent->getAction() === 'USER_CREATED', '1.12 Acción de auditoría es USER_CREATED');
assertCondition($lastEvent->getEntityId() === $newTech->getId(), '1.13 Entity ID coincide');
assertCondition(!isset($lastEvent->getNewState()['password']), '1.14 Contraseña en texto plano NO expuesta en auditoría');
assertCondition(!isset($lastEvent->getNewState()['password_hash']), '1.15 Hash de contraseña NO expuesto en auditoría');
assertCondition($lastEvent->getMetadata()['ip'] === '192.168.1.50', '1.16 Metadatos capturan IP del cliente');

// -------------------------------------------------------------
// Caso 2: Detección de colisiones de correo (activo e inactivo)
// -------------------------------------------------------------
echo "\n--- Caso 2: Detección de colisiones de correo (activo e inactivo) ---\n";

// 2.1 Colisión con usuario activo
$activeCollisionCaught = false;
try {
    $service->createUser([
        'name'     => 'Santiago Copia',
        'email'    => 'santiago.tec@vendguard.internal',
        'role'     => 'TECHNICIAN',
        'password' => 'OtraClaveSegura2026!',
    ], $coordinatorActor);
} catch (InactiveRecordCollisionException $e) {
    $activeCollisionCaught = true;
    assertCondition($e->getErrorCode() === 'USER_ALREADY_EXISTS_ACTIVE', '2.1 Código USER_ALREADY_EXISTS_ACTIVE');
    assertCondition($e->canReactivate() === false, '2.2 canReactivate es false');
    assertCondition($e->getHttpStatusCode() === 409, '2.3 Código HTTP 409');
}
assertCondition($activeCollisionCaught, '2.4 Colisión con usuario activo detectada');

// Dar de baja a Santiago para probar colisión con inactivo
$userRepo->softDelete($newTech->getId());

// 2.2 Colisión con usuario inactivo
$inactiveCollisionCaught = false;
try {
    $service->createUser([
        'name'     => 'Santiago Reactivable',
        'email'    => 'santiago.tec@vendguard.internal',
        'role'     => 'TECHNICIAN',
        'password' => 'OtraClaveSegura2026!',
    ], $coordinatorActor);
} catch (InactiveRecordCollisionException $e) {
    $inactiveCollisionCaught = true;
    assertCondition($e->getErrorCode() === 'USER_ALREADY_EXISTS_INACTIVE', '2.5 Código USER_ALREADY_EXISTS_INACTIVE');
    assertCondition($e->canReactivate() === true, '2.6 canReactivate es true');
    assertCondition($e->getEntityId() === $newTech->getId(), '2.7 entityId señala al usuario inactivo');
}
assertCondition($inactiveCollisionCaught, '2.8 Colisión con usuario inactivo detectada');

// Restaurar a Santiago para siguientes pruebas
$userRepo->restore($newTech->getId());

// -------------------------------------------------------------
// Caso 3: Actualización de datos de contacto (updateUser)
// -------------------------------------------------------------
echo "\n--- Caso 3: Actualización de datos de contacto (updateUser) ---\n";

$updatedUser = $service->updateUser($newTech->getId(), [
    'name'  => 'Santiago Gómez Morales',
    'phone' => '655998877',
], $coordinatorActor, '192.168.1.51');

assertCondition($updatedUser->getName() === 'Santiago Gómez Morales', '3.1 Nombre actualizado');
assertCondition($updatedUser->getPhone() === '655998877', '3.2 Teléfono actualizado');

$lastEvent = end($auditRepo->events);
assertCondition($lastEvent->getAction() === 'USER_UPDATED', '3.3 Evento USER_UPDATED registrado');
assertCondition(in_array('name', $lastEvent->getMetadata()['changed_fields'], true), '3.4 changed_fields incluye name');
assertCondition(in_array('phone', $lastEvent->getMetadata()['changed_fields'], true), '3.5 changed_fields incluye phone');

// -------------------------------------------------------------
// Caso 4: Reseteo administrativo de contraseña (resetPassword)
// -------------------------------------------------------------
echo "\n--- Caso 4: Reseteo administrativo de contraseña (resetPassword) ---\n";

// 4.1 Rechazar clave corta en reseteo
$shortResetCaught = false;
try {
    $service->resetPassword($newTech->getId(), 'corta', $coordinatorActor);
} catch (InvalidArgumentException $e) {
    $shortResetCaught = true;
    assertCondition(str_contains($e->getMessage(), '8 caracteres'), '4.1 Rechaza clave corta en reseteo');
}
assertCondition($shortResetCaught, '4.2 Validación de longitud en resetPassword');

// 4.2 Reseteo exitoso
$resetSuccess = $service->resetPassword($newTech->getId(), 'NuevaClaveValida2026!', $coordinatorActor, '192.168.1.52');
assertCondition($resetSuccess === true, '4.3 resetPassword devuelve true');

// Verificar verificación de nueva contraseña
$reloaded = $service->getUser($newTech->getId());
assertCondition($reloaded->verifyPassword('NuevaClaveValida2026!'), '4.4 Nueva contraseña verifica exitosamente');

// Verificar evento de auditoría
$lastEvent = end($auditRepo->events);
assertCondition($lastEvent->getAction() === 'USER_PASSWORD_RESET', '4.5 Evento USER_PASSWORD_RESET registrado');
assertCondition($lastEvent->getNewState()['password_reset'] === true, '4.6 new_state indica password_reset');
assertCondition(!isset($lastEvent->getNewState()['password']), '4.7 Contraseña en claro omitida en reset');
assertCondition(!isset($lastEvent->getNewState()['password_hash']), '4.8 Hash omitido en reset');
assertCondition($lastEvent->getMetadata()['reset_by_coordinator_id'] === 1, '4.9 Metadatos identifican coordinador');

// -------------------------------------------------------------
// Caso 5: Bloqueo de auto-desactivación del coordinador en sesión
// -------------------------------------------------------------
echo "\n--- Caso 5: Bloqueo de auto-desactivación del coordinador en sesión ---\n";

$selfDeactCaught = false;
try {
    // Elena (ID 1) intenta desactivar a Elena (ID 1)
    $service->deactivateUser(1, ['id' => 1, 'role' => 'COORDINATOR', 'name' => 'Elena Coordinadora']);
} catch (CannotDeactivateSelfException $e) {
    $selfDeactCaught = true;
    assertCondition($e->getErrorCode() === 'CANNOT_DEACTIVATE_SELF', '5.1 Código CANNOT_DEACTIVATE_SELF');
    assertCondition($e->getHttpStatusCode() === 403, '5.2 Código HTTP 403 Forbidden');
    assertCondition(str_contains($e->getMessage(), 'no puede desactivar su propio usuario'), '5.3 Mensaje descriptivo');
}
assertCondition($selfDeactCaught, '5.4 Auto-desactivación terminantemente bloqueada');

// -------------------------------------------------------------
// Caso 6: Salvaguarda de guardia mínima operativa (Caso Límite 9)
// -------------------------------------------------------------
echo "\n--- Caso 6: Salvaguarda de guardia mínima operativa (Caso Límite 9) ---\n";

// Tenemos coord1 y coord2. Marcos (ID 2) desactiva a Elena (ID 1) -> permitido (quedaría Marcos activo).
// Primero desactivemos a coord2 usando a Elena:
$service->deactivateUser(2, ['id' => 1, 'role' => 'COORDINATOR', 'name' => 'Elena Coordinadora']);
assertCondition(!$userRepo->findById(2, onlyActive: false, allowDeleted: true)->isActive(), '6.1 Coord 2 desactivado');

// Ahora solo queda 1 coordinador activo (Elena, ID 1). Si Marcos intentara desactivarla, o si un script lo intenta:
$minCoordCaught = false;
try {
    // Aunque el actor tuviera id 99 (otro actor simulado), queda 1 coordinador activo
    $service->deactivateUser(1, ['id' => 99, 'role' => 'COORDINATOR', 'name' => 'Actor Externo']);
} catch (MinimumActiveStaffException $e) {
    $minCoordCaught = true;
    assertCondition($e->getErrorCode() === 'MINIMUM_ACTIVE_STAFF_BREACH', '6.2 Código MINIMUM_ACTIVE_STAFF_BREACH');
    assertCondition($e->getHttpStatusCode() === 409, '6.3 Código HTTP 409 Conflict');
    assertCondition($e->getRole() === 'COORDINATOR', '6.4 Rol identificado es COORDINATOR');
}
assertCondition($minCoordCaught, '6.5 Bloqueada baja del último coordinador activo');

// Restaurar coord2
$userRepo->restore(2);

// Probar guardia mínima con técnicos: desactivar tech2 y newTech para que solo quede tech1
$userRepo->softDelete($tech2->getId());
$userRepo->softDelete($newTech->getId());

assertCondition($userRepo->countActiveByRole(UserRole::TECHNICIAN) === 1, '6.6 Queda exactamente 1 técnico activo');

$minTechCaught = false;
try {
    $service->deactivateUser($tech1->getId(), $coordinatorActor);
} catch (MinimumActiveStaffException $e) {
    $minTechCaught = true;
    assertCondition($e->getErrorCode() === 'MINIMUM_ACTIVE_STAFF_BREACH', '6.7 Infracción de guardia mínima en técnicos');
    assertCondition($e->getRole() === 'TECHNICIAN', '6.8 Rol identificado es TECHNICIAN');
}
assertCondition($minTechCaught, '6.9 Bloqueada baja del último técnico activo');

// Restaurar técnicos
$userRepo->restore($tech2->getId());
$userRepo->restore($newTech->getId());

// -------------------------------------------------------------
// Caso 7: Bloqueo de baja por averías asignadas pendientes
// -------------------------------------------------------------
echo "\n--- Caso 7: Bloqueo de baja por averías asignadas pendientes ---\n";

// Simular 2 averías pendientes asignadas a tech1
$userRepo->pendingIncidents[$tech1->getId()] = 2;

$pendingIncCaught = false;
try {
    $service->deactivateUser($tech1->getId(), $coordinatorActor);
} catch (PendingIncidentsBlockedException $e) {
    $pendingIncCaught = true;
    assertCondition($e->getErrorCode() === 'TECHNICIAN_HAS_PENDING_INCIDENTS', '7.1 Código TECHNICIAN_HAS_PENDING_INCIDENTS');
    assertCondition($e->getHttpStatusCode() === 409, '7.2 Código HTTP 409');
    assertCondition($e->getPendingIncidentsCount() === 2, '7.3 pendingIncidentsCount es 2');
    assertCondition(str_contains($e->getMessage(), '2 averías asignadas pendientes'), '7.4 Mensaje detalla conteo');
}
assertCondition($pendingIncCaught, '7.5 Bloqueada baja de técnico con averías pendientes');

// Simular reasignación de averías desde panel de triaje (quedan 0)
$userRepo->pendingIncidents[$tech1->getId()] = 0;

// Ahora la baja de tech1 debe prosperar
$deactSuccess = $service->deactivateUser($tech1->getId(), $coordinatorActor, '192.168.1.53');
assertCondition($deactSuccess === true, '7.6 deactivateUser retorna true tras liberar carga');
assertCondition(!$userRepo->findById($tech1->getId(), onlyActive: false, allowDeleted: true)->isActive(), '7.7 Técnico ya no está activo');

$lastEvent = end($auditRepo->events);
assertCondition($lastEvent->getAction() === 'USER_DEACTIVATED', '7.8 Evento USER_DEACTIVATED registrado');
assertCondition($lastEvent->getPreviousState()['is_active'] === true, '7.9 previous_state is_active = true');
assertCondition($lastEvent->getNewState()['is_active'] === false, '7.10 new_state is_active = false');
assertCondition($lastEvent->getMetadata()['role'] === 'TECHNICIAN', '7.11 Metadatos registran rol');

// -------------------------------------------------------------
// Caso 8: Reactivación de usuario inactivo (reactivateUser)
// -------------------------------------------------------------
echo "\n--- Caso 8: Reactivación de usuario inactivo (reactivateUser) ---\n";

$restoredUser = $service->reactivateUser($tech1->getId(), $coordinatorActor, '192.168.1.54');
assertCondition($restoredUser->isActive(), '8.1 reactivateUser retorna usuario activo');
assertCondition($userRepo->findById($tech1->getId())->isActive(), '8.2 Usuario activo en repositorio');

$lastEvent = end($auditRepo->events);
assertCondition($lastEvent->getAction() === 'USER_REACTIVATED', '8.3 Evento USER_REACTIVATED registrado');
assertCondition($lastEvent->getPreviousState()['is_active'] === false, '8.4 previous_state is_active = false');
assertCondition($lastEvent->getNewState()['is_active'] === true, '8.5 new_state is_active = true');

// -------------------------------------------------------------
// Caso 9: Listado de usuarios con filtros (listUsers)
// -------------------------------------------------------------
echo "\n--- Caso 9: Listado de usuarios con filtros (listUsers) ---\n";

// Desactivar un usuario para probar filtros
$userRepo->softDelete($newTech->getId());

$allUsers = $service->listUsers('all');
assertCondition(count($allUsers) === 5, '9.1 Total de 5 usuarios registrados');

$activeUsers = $service->listUsers('active');
assertCondition(count($activeUsers) === 4, '9.2 4 usuarios activos');

$inactiveUsers = $service->listUsers('inactive');
assertCondition(count($inactiveUsers) === 1, '9.3 1 usuario inactivo');
assertCondition($inactiveUsers[0]['id'] === $newTech->getId(), '9.4 Inactivo coincide con newTech');

$techsOnly = $service->listUsers('all', 'TECHNICIAN');
assertCondition(count($techsOnly) === 3, '9.5 3 técnicos en total');

$searchResult = $service->listUsers('all', 'all', 'Morales');
assertCondition(count($searchResult) === 1, '9.6 Búsqueda por apellido Morales encuentra 1 resultado');
assertCondition($searchResult[0]['id'] === $newTech->getId(), '9.7 Resultado de búsqueda coincide');

echo "\n======================================================================\n";
echo " RESULTADO: 100% EN VERDE. ({$assertions} aserciones pasadas)\n";
echo " CONDICIÓN T-ADM-09 CUMPLIDA SATISFACTORIAMENTE.\n";
echo "======================================================================\n\n";
