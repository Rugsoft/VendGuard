<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\OnCallTechnicianRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserLockoutRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

/**
 * PdoUserRepository
 * 
 * Implementación PDO para el acceso y autenticación de usuarios internos.
 * Utiliza sentencias preparadas nativas y filtrado de soft delete (RF-03, RF-04 / RNF-03).
 *
 * El dialecto no se escribe aquí: las diferencias que el motor no sabe resolver sólo
 * —booleano literal (D-3), ventana temporal del periodo de garantía y clave canónica de
 * identidad (D-5)— se le piden al adaptador, que es el punto único de traducción (RF-01.5).
 */
class PdoUserRepository implements UserRepositoryInterface, UserLockoutRepositoryInterface, OnCallTechnicianRepositoryInterface, TechnicianLockRepositoryInterface
{
    private PDO $pdo;

    private SqlDialectAdapter $dialect;

    /** @var array<string, ?User> Cache en memoria por clave para reducir round-trips en lecturas repetidas */
    private static array $cacheById = [];

    public static function clearCache(): void
    {
        self::$cacheById = [];
    }

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->dialect = SqlDialectAdapter::forConnection($this->pdo);
    }

    /**
     * Recupera un usuario por su dirección de correo electrónico.
     *
     * @param string $email
     * @param bool $onlyActive
     * @param bool $allowDeleted
     * @return User|null
     */
    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        // «El mismo login» es una sola regla con dos formas (D-5): donde la colación local compara
        // sin distinguir mayúsculas ni acentos, aquí se compara por la clave canónica, que es la
        // columna indexada del motor nuevo. El valor enlazado es el mismo en los dos casos, y se
        // recorta por delante y por detrás antes de calcular la clave: el recorte de la entrada es
        // una regla del repositorio —un login entre espacios sigue siendo el mismo login— y la
        // clave sólo añade el plegado que la colación local hacía por su cuenta.
        $emailKey = $this->dialect->identityKey(trim($email));
        $identityColumn = $this->dialect->requiresCanonicalIdentityColumns() ? 'email_key' : 'email';

        $sql = "
            SELECT 
                id, 
                name, 
                email, 
                password_hash, 
                role, 
                phone, 
                is_active, 
                is_on_call,
                telegram_chat_id,
                created_at, 
                updated_at, 
                deleted_at
            FROM users
            WHERE {$identityColumn} = :email_key
        ";

        if (!$allowDeleted) {
            $sql .= " AND deleted_at IS NULL";
        }

        if ($onlyActive) {
            $sql .= " AND is_active = {$this->dialect->booleanLiteral(true)}";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':email_key' => $emailKey]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return User::fromDatabaseRow($row);
    }

    /**
     * Recupera un usuario por su identificador primario.
     *
     * @param int $id
     * @param bool $onlyActive
     * @param bool $allowDeleted
     * @return User|null
     */
    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        $cacheKey = "{$id}_" . ($onlyActive ? '1' : '0') . '_' . ($allowDeleted ? '1' : '0');
        if (array_key_exists($cacheKey, self::$cacheById)) {
            return self::$cacheById[$cacheKey];
        }

        $sql = "
            SELECT 
                id, 
                name, 
                email, 
                password_hash, 
                role, 
                phone, 
                is_active, 
                is_on_call,
                telegram_chat_id,
                created_at, 
                updated_at, 
                deleted_at
            FROM users
            WHERE id = :id
        ";

        if (!$allowDeleted) {
            $sql .= " AND deleted_at IS NULL";
        }

        if ($onlyActive) {
            $sql .= " AND is_active = {$this->dialect->booleanLiteral(true)}";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            self::$cacheById[$cacheKey] = null;
            return null;
        }

        $user = User::fromDatabaseRow($row);
        self::$cacheById[$cacheKey] = $user;
        return $user;
    }

    /**
     * Obtiene la lista de todos los técnicos de ruta activos para la asignación de partes.
     *
     * @param bool $onlyActive
     * @return array<User>
     */
    public function findAllTechnicians(bool $onlyActive = true): array
    {
        $sql = "
            SELECT 
                id, 
                name, 
                email, 
                password_hash, 
                role, 
                phone, 
                is_active, 
                is_on_call,
                telegram_chat_id,
                created_at, 
                updated_at, 
                deleted_at
            FROM users
            WHERE role = 'TECHNICIAN'
              AND deleted_at IS NULL
        ";

        if ($onlyActive) {
            $sql .= " AND is_active = {$this->dialect->booleanLiteral(true)}";
        }

        $sql .= " ORDER BY name ASC";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll();

        return array_map(fn(array $row) => User::fromDatabaseRow($row), $rows);
    }

    /**
     * Da de alta a un nuevo usuario interno en el sistema con credenciales Bcrypt (RF-03, EARS 3.1).
     *
     * @param array<string, mixed> $data
     * @return User
     * @throws RuntimeException
     */
    public function create(array $data): User
    {
        $passwordHash = isset($data['password_hash']) && !empty($data['password_hash'])
            ? (string)$data['password_hash']
            : password_hash((string)($data['password'] ?? ''), PASSWORD_BCRYPT, ['cost' => 10]);

        $email = strtolower(trim((string)$data['email']));

        // La clave canónica acompaña a la identidad desde el alta (A-1, EARS-08.5): donde el motor
        // la exige como columna `NOT NULL` con índice único, una fila sin clave no sería un
        // duplicado invisible sino un alta incompleta, y la base la rechazaría. Donde no existe la
        // columna, la regla la aplica la colación y escribirla sería un error de columna.
        $identityColumns = '';
        $identityValues = '';
        $identityParams = [];
        if ($this->dialect->requiresCanonicalIdentityColumns()) {
            $identityColumns = "email_key,\n                ";
            $identityValues = ":email_key,\n                ";
            $identityParams[':email_key'] = $this->dialect->identityKey($email);
        }

        $sql = "
            INSERT INTO users (
                name,
                email,
                {$identityColumns}password_hash,
                role,
                phone,
                telegram_chat_id,
                is_active,
                created_at,
                updated_at,
                deleted_at
            ) VALUES (
                :name,
                :email,
                {$identityValues}:password_hash,
                :role,
                :phone,
                :telegram_chat_id,
                {$this->dialect->booleanLiteral(true)},
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP,
                NULL
            )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($identityParams + [
            ':name'          => trim((string)$data['name']),
            ':email'         => $email,
            ':password_hash' => $passwordHash,
            ':role'          => strtoupper(trim((string)$data['role'])),
            ':phone'         => isset($data['phone']) && $data['phone'] !== '' ? trim((string)$data['phone']) : null,
            ':telegram_chat_id' => isset($data['telegram_chat_id']) && $data['telegram_chat_id'] !== ''
                ? trim((string)$data['telegram_chat_id'])
                : null,
        ]);

        $newId = (int)$this->pdo->lastInsertId();
        $created = $this->findById($newId, false, true);
        if ($created === null) {
            throw new RuntimeException("Error al recuperar el usuario recién creado con ID {$newId}");
        }

        self::clearCache();
        return $created;
    }

    /**
     * Actualiza los datos personales y de contacto de un usuario (RF-03, EARS 3.3).
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        if (array_key_exists('name', $data)) {
            $fields[] = "name = :name";
            $params[':name'] = trim((string)$data['name']);
        }
        if (array_key_exists('phone', $data)) {
            $fields[] = "phone = :phone";
            $params[':phone'] = $data['phone'] !== null && $data['phone'] !== '' ? trim((string)$data['phone']) : null;
        }
        if (array_key_exists('telegram_chat_id', $data)) {
            $fields[] = "telegram_chat_id = :telegram_chat_id";
            $params[':telegram_chat_id'] = $data['telegram_chat_id'] !== null && $data['telegram_chat_id'] !== ''
                ? trim((string)$data['telegram_chat_id'])
                : null;
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = CURRENT_TIMESTAMP";

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        self::clearCache();
        return $stmt->rowCount() >= 0;
    }

    /**
     * Restablece la contraseña de acceso de un usuario cifrándola en Bcrypt (RF-03, EARS 3.4).
     *
     * @param int $id
     * @param string $newPassword
     * @return bool
     */
    public function updatePassword(int $id, string $newPassword): bool
    {
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]);

        $sql = "
            UPDATE users
            SET password_hash = :password_hash,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':password_hash' => $passwordHash,
            ':id'            => $id,
        ]);

        self::clearCache();
        return $stmt->rowCount() > 0;
    }

    /**
     * Alias de updatePassword para compatibilidad con nomenclatura del plan (RF-03, EARS 3.4).
     *
     * @param int $id
     * @param string $newPassword
     * @return bool
     */
    public function resetPassword(int $id, string $newPassword): bool
    {
        return $this->updatePassword($id, $newPassword);
    }

    /**
     * Aplica borrado lógico marcando is_active = 0 y deleted_at = CURRENT_TIMESTAMP (RF-03, RNF-03, Art. III.1).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // La marca de guardia se retira en el mismo acto (RF-02.6) y la
            // restricción `chk_users_on_call_active_tech` la exige: un técnico
            // inactivo no puede conservar `is_on_call = TRUE`.
            $sql = "
                UPDATE users
                SET is_active = {$this->dialect->booleanLiteral(false)},
                    is_on_call = {$this->dialect->booleanLiteral(false)},
                    deleted_at = CURRENT_TIMESTAMP
                WHERE id = :id
                  AND deleted_at IS NULL
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            $deleted = $stmt->rowCount() > 0;

            if ($deleted) {
                // Los avisos que aún esperaban a este destinatario quedan sin
                // validez (RF-07.1.f): se cancelan lógicamente, nunca se borran.
                (new PdoNotificationOutboxRepository($this->pdo))->cancelPendingForRecipientUser(
                    $id,
                    'Destinatario interno desactivado en el directorio de personal.'
                );
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            self::clearCache();

            return $deleted;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Activa o desactiva la condición de Técnico de Guardia (RF-02.5).
     *
     * El filtro de la sentencia impone la regla completa en el motor: sólo un
     * TECHNICIAN activo y no dado de baja puede lucir la marca. Un `false`
     * significa que la fila no cumple la regla (o no existe), no un fallo de
     * escritura: los errores de PDO se propagan como excepción.
     */
    public function setOnCall(int $userId, bool $onCall): bool
    {
        $sql = "
            UPDATE users
            SET is_on_call = :on_call,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND role = 'TECHNICIAN'
              AND is_active = {$this->dialect->booleanLiteral(true)}
              AND deleted_at IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':on_call', $onCall, PDO::PARAM_BOOL);
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        self::clearCache();

        return $stmt->rowCount() > 0;
    }

    /**
     * Técnicos activos con la marca de guardia (RF-03.1 y RF-03.2).
     *
     * Un guardia en ausencia activa no recibe avisos, salvo que haya iniciado
     * turno: la presencia física prevalece sobre la ausencia planificada (R4).
     * La pausa de comida no lo excluye de la bandeja: sigue siendo guardia y
     * debe poder leer el aviso al reanudar.
     *
     * @return array<User>
     */
    public function findOnCallTechnicians(): array
    {
        $sql = "
            SELECT
                u.id,
                u.name,
                u.email,
                u.password_hash,
                u.role,
                u.phone,
                u.is_active,
                u.is_on_call,
                u.telegram_chat_id,
                u.created_at,
                u.updated_at,
                u.deleted_at
            FROM users u
            WHERE u.role = 'TECHNICIAN'
              AND u.is_active = {$this->dialect->booleanLiteral(true)}
              AND u.is_on_call = {$this->dialect->booleanLiteral(true)}
              AND u.deleted_at IS NULL
              AND (
                  NOT EXISTS (
                      SELECT 1 FROM technician_absences a
                       WHERE a.technician_id = u.id
                         AND a.cancelled_at IS NULL
                         AND a.starts_at <= :now
                         AND a.ends_at > :now
                  )
                  OR EXISTS (
                      SELECT 1 FROM technician_shifts s
                       WHERE s.technician_id = u.id
                         AND s.closed_at IS NULL
                         AND s.status = 'ON_SHIFT'
                  )
              )
            ORDER BY u.name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':now' => (new DateTimeImmutable('now'))->format(DateTimeImmutable::ATOM)]);

        return array_map(
            static fn(array $row): User => User::fromDatabaseRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Guardias que pueden recibir el Nivel 1 sanitario en el instante indicado.
     *
     * Excluye ausencias sin turno y pausas de comida. Un guardia en OFF_SHIFT
     * sin ausencia sigue siendo elegible (R6 / RF-08.5). Ordena primero a los
     * que están en turno.
     *
     * @return array<User>
     */
    public function findAvailableOnCallTechnicians(DateTimeImmutable $at, ?int $excludeTechnicianId = null): array
    {
        $sql = "
            SELECT
                u.id,
                u.name,
                u.email,
                u.password_hash,
                u.role,
                u.phone,
                u.is_active,
                u.is_on_call,
                u.telegram_chat_id,
                u.created_at,
                u.updated_at,
                u.deleted_at
            FROM users u
            WHERE u.role = 'TECHNICIAN'
              AND u.is_active = {$this->dialect->booleanLiteral(true)}
              AND u.is_on_call = {$this->dialect->booleanLiteral(true)}
              AND u.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM technician_shifts m
                   WHERE m.technician_id = u.id
                     AND m.closed_at IS NULL
                     AND m.status = 'MEAL_BREAK'
              )
              AND (
                  NOT EXISTS (
                      SELECT 1 FROM technician_absences a
                       WHERE a.technician_id = u.id
                         AND a.cancelled_at IS NULL
                         AND a.starts_at <= :at
                         AND a.ends_at > :at
                  )
                  OR EXISTS (
                      SELECT 1 FROM technician_shifts s
                       WHERE s.technician_id = u.id
                         AND s.closed_at IS NULL
                         AND s.status = 'ON_SHIFT'
                  )
              )
        ";

        $params = [':at' => $at->format(DateTimeImmutable::ATOM)];
        if ($excludeTechnicianId !== null) {
            $sql .= ' AND u.id <> :exclude_id';
            $params[':exclude_id'] = $excludeTechnicianId;
        }

        $sql .= "
            ORDER BY CASE WHEN EXISTS (
                SELECT 1 FROM technician_shifts o
                 WHERE o.technician_id = u.id
                   AND o.closed_at IS NULL
                   AND o.status = 'ON_SHIFT'
            ) THEN 0 ELSE 1 END, u.name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn(array $row): User => User::fromDatabaseRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Número de técnicos activos de guardia (resumen del panel de Coordinación).
     */
    public function countOnCallTechnicians(): int
    {
        $sql = "
            SELECT COUNT(*) AS total
            FROM users u
            WHERE u.role = 'TECHNICIAN'
              AND u.is_active = {$this->dialect->booleanLiteral(true)}
              AND u.is_on_call = {$this->dialect->booleanLiteral(true)}
              AND u.deleted_at IS NULL
              AND (
                  NOT EXISTS (
                      SELECT 1 FROM technician_absences a
                       WHERE a.technician_id = u.id
                         AND a.cancelled_at IS NULL
                         AND a.starts_at <= :now
                         AND a.ends_at > :now
                  )
                  OR EXISTS (
                      SELECT 1 FROM technician_shifts s
                       WHERE s.technician_id = u.id
                         AND s.closed_at IS NULL
                         AND s.status = 'ON_SHIFT'
                  )
              )
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':now' => (new DateTimeImmutable('now'))->format(DateTimeImmutable::ATOM)]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Bloquea la fila del técnico hasta el final de la transacción actual.
     */
    public function lockTechnicianForUpdate(int $technicianId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM users
              WHERE id = :id
                AND role = 'TECHNICIAN'
                AND deleted_at IS NULL
              FOR UPDATE"
        );
        $stmt->execute([':id' => $technicianId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Restaura un usuario previamente borrado lógicamente (RF-03, EARS 3.6).
     *
     * @param int $id
     * @return bool
     */
    public function restore(int $id): bool
    {
        $sql = "
            UPDATE users
            SET is_active = {$this->dialect->booleanLiteral(true)},
                deleted_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND deleted_at IS NOT NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);

        self::clearCache();
        return $stmt->rowCount() > 0;
    }

    /**
     * Recupera el listado del personal con filtros por rol, estado y recuento de averías asignadas (RF-03, EARS 3.2).
     *
     * La carga reporta las averías que aún deben trabajo al técnico: `ASSIGNED`,
     * `IN_PROGRESS` o `PENDING_PARTS` (Decisión QA 2, specs/04-admin-crud/plan.md §3.2).
     * Una avería `RESOLVED` en su ventana de garantía de 48 h sigue activa como
     * expediente de la máquina (`is_active_ticket = 1`, pendiente de cierre automático),
     * pero el trabajo del técnico ya está hecho: si el consumidor reabre por garantía,
     * `PdoIncidentRepository::reopen()` desasigna al técnico y la avería regresa al
     * triaje como `REOPENED` (sin técnico), de modo que ninguna avería `RESOLVED` ni
     * `REOPENED` puede deber trabajo a quien figura como técnico asignado.
     * Contarlas como carga falsificaría el directorio y bloquearía la baja de un
     * técnico que ya no tiene nada pendiente.
     *
     * Junto a la carga se expone `warranty_incidents_count`, un contador puramente
     * informativo (solo lectura) con las averías `RESOLVED` dentro de su ventana de
     * garantía de 48 h (`resolved_at >= NOW() - INTERVAL 48 HOUR`). No forma parte
     * del bloqueo de baja: el trabajo del técnico ya está hecho y `reopen()` desasigna
     * al técnico si el consumidor reabre por garantía.
     *
     * @param array<string, mixed> $filters
     * @return array<array<string, mixed>>
     */
    public function findAll(array $filters = []): array
    {
        $sql = "
            SELECT 
                u.id,
                u.name,
                u.email,
                u.role,
                u.phone,
                u.is_active,
                u.is_on_call,
                u.telegram_chat_id,
                u.created_at,
                u.updated_at,
                u.deleted_at,
                COALESCE(inc.active_count, 0) AS active_assigned_incidents_count,
                COALESCE(war.warranty_count, 0) AS warranty_incidents_count
            FROM users u
            LEFT JOIN (
                SELECT assigned_technician_id, COUNT(*) as active_count
                FROM incidents
                WHERE deleted_at IS NULL
                  AND status IN ('ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS')
                GROUP BY assigned_technician_id
            ) inc ON inc.assigned_technician_id = u.id
            LEFT JOIN (
                SELECT assigned_technician_id, COUNT(*) as warranty_count
                FROM incidents
                WHERE deleted_at IS NULL
                  AND status = 'RESOLVED'
                  AND resolved_at >= {$this->dialect->subtractInterval('NOW()', 48, 'HOUR')}
                GROUP BY assigned_technician_id
            ) war ON war.assigned_technician_id = u.id
            WHERE 1=1
        ";

        $params = [];
        $status = $filters['status'] ?? 'all';

        if ($status === 'active') {
            $sql .= " AND u.is_active = {$this->dialect->booleanLiteral(true)} AND u.deleted_at IS NULL";
        } elseif ($status === 'inactive') {
            $sql .= " AND (u.is_active = {$this->dialect->booleanLiteral(false)} OR u.deleted_at IS NOT NULL)";
        }

        if (!empty($filters['role']) && $filters['role'] !== 'all') {
            $sql .= " AND u.role = :role";
            $params[':role'] = strtoupper(trim((string)$filters['role']));
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string)$filters['search']) . '%';
            $sql .= " AND (
                u.name LIKE :s1
                OR u.email LIKE :s2
                OR u.phone LIKE :s3
            )";
            $params[':s1'] = $term;
            $params[':s2'] = $term;
            $params[':s3'] = $term;
        }

        $sql .= " ORDER BY u.name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(function (array $row): array {
            $roleEnum = UserRole::tryFrom((string)$row['role']);

            return [
                'id'                              => (int)$row['id'],
                'name'                            => (string)$row['name'],
                'email'                           => (string)$row['email'],
                'role'                            => (string)$row['role'],
                'role_label'                      => $roleEnum !== null ? $roleEnum->label() : (string)$row['role'],
                'phone'                           => $row['phone'] !== null ? (string)$row['phone'] : null,
                'is_active'                       => (bool)$row['is_active'] && $row['deleted_at'] === null,
                'is_on_call'                      => (bool)($row['is_on_call'] ?? false),
                'telegram_chat_id'                => $row['telegram_chat_id'] !== null ? (string)$row['telegram_chat_id'] : null,
                'telegram_linked'                 => $row['telegram_chat_id'] !== null,
                'active_assigned_incidents_count' => (int)$row['active_assigned_incidents_count'],
                'warranty_incidents_count'        => (int)$row['warranty_incidents_count'],
                'created_at'                      => (string)$row['created_at'],
                'updated_at'                      => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
                'deleted_at'                      => $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            ];
        }, $rows);
    }

    /**
     * Cuenta el número de usuarios activos para un rol específico (Guardia Mínima Operativa).
     *
     * @param UserRole|string $role
     * @return int
     */
    public function countActiveByRole(UserRole|string $role): int
    {
        $roleValue = $role instanceof UserRole ? $role->value : strtoupper(trim($role));

        $sql = "
            SELECT COUNT(*) 
            FROM users
            WHERE role = :role
              AND is_active = {$this->dialect->booleanLiteral(true)}
              AND deleted_at IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':role' => $roleValue]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Cuenta las incidencias activas asignadas a un técnico (bloqueo de baja técnica).
     *
     * @param int $userId
     * @return int
     */
    public function countActiveAssignedIncidents(int $userId): int
    {
        // Pendiente de trabajo del técnico (Decisión QA 2, specs/04-admin-crud/plan.md §3.2):
        // ASSIGNED / IN_PROGRESS / PENDING_PARTS. Una avería RESOLVED en su ventana de
        // garantía de 48 h sigue siendo un expediente activo de la máquina
        // (is_active_ticket = 1, cierre automático pendiente), pero el técnico ya resolvió
        // su trabajo y no vuelve a intervenir: reopen() desasigna al técnico si el
        // consumidor reabre por garantía. Contarla bloquearía la baja de un técnico que
        // ya no tiene nada pendiente.
        $sql = "
            SELECT COUNT(*) 
            FROM incidents
            WHERE assigned_technician_id = :user_id
              AND deleted_at IS NULL
              AND status IN ('ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS')
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Alias de countActiveAssignedIncidents para compatibilidad con nomenclatura del plan.
     *
     * @param int $technicianId
     * @return int
     */
    public function countPendingIncidents(int $technicianId): int
    {
        return $this->countActiveAssignedIncidents($technicianId);
    }

    /**
     * {@inheritDoc}
     */
    public function findLoginLockoutState(int $userId): ?array
    {
        $sql = "
            SELECT 
                login_attempts AS attempts,
                login_locked_until AS locked_until,
                (login_locked_until IS NOT NULL AND login_locked_until > NOW()) AS is_locked
            FROM users
            WHERE id = :id
              AND deleted_at IS NULL
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'attempts' => (int)$row['attempts'],
            'is_locked' => (bool)$row['is_locked'],
            'locked_until' => $row['locked_until'] !== null ? (string)$row['locked_until'] : null,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function registerFailedLogin(int $userId, int $maxAttempts, int $lockSeconds): void
    {
        // Limpiar bloqueo expirado si ya transcurrió el tiempo de penalización
        $clearExpiredSql = "
            UPDATE users
            SET login_attempts = 0, login_locked_until = NULL
            WHERE id = :id
              AND login_locked_until IS NOT NULL
              AND login_locked_until <= NOW()
        ";
        $this->pdo->prepare($clearExpiredSql)->execute([':id' => $userId]);

        // Registrar fallo e incrementar contador. Si llega al umbral, bloquear
        $sql = "
            UPDATE users
            SET
                login_locked_until = CASE
                    WHEN (login_attempts + 1) >= :max_attempts
                    THEN {$this->dialect->addInterval('NOW()', ':lock_seconds', 'SECOND')}
                    ELSE NULL
                END,
                login_attempts = login_attempts + 1
            WHERE id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':max_attempts' => $maxAttempts,
            ':lock_seconds' => $lockSeconds,
            ':id' => $userId,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function resetLoginAttempts(int $userId): void
    {
        $sql = "
            UPDATE users
            SET
                login_attempts = 0,
                login_locked_until = NULL
            WHERE id = :id
        ";

        $this->pdo->prepare($sql)->execute([':id' => $userId]);
    }
}
