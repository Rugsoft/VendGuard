<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Repository\LocationBatchRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationContactEmailRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

/**
 * PdoLocationRepository
 * 
 * Implementación nativa con PDO de la persistencia de Sedes (locations) en MariaDB.
 * Cumple estrictamente con el Dogma Vanilla y el mandato constitucional de Soft Delete (Art. III.1).
 *
 * Los identificadores se escriben desnudos —los dos motores admiten así todo el esquema, y las
 * comillas de un motor son sintaxis privativa del otro—, y las diferencias que no tienen forma
 * común —booleano literal (D-3), clave canónica del código de sede (D-5, EARS-08.5)— se le piden
 * al adaptador de dialecto, que es el punto único de traducción (RF-01.5, T-HYBRID-25).
 *
 * Además del contrato principal, declara la capacidad de lote
 * (`LocationBatchRepositoryInterface::findManyByIds()`), que existe para la bandeja de ruta del
 * técnico: resolver la sede de cada parada con una consulta por parada era un N+1 medido contra el
 * motor remoto (Módulo 12 · T-HYBRID-27).
 */
class PdoLocationRepository implements LocationRepositoryInterface, LocationBatchRepositoryInterface, LocationContactEmailRepositoryInterface
{
    private PDO $pdo;

    private SqlDialectAdapter $dialect;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->dialect = SqlDialectAdapter::forConnection($this->pdo);
    }

    /**
     * Recupera una sede por su código alfanumérico único.
     *
     * @param string $siteCode Código de sede (ej: SEDE-BCN-01).
     * @param bool $onlyActive Si es true, exige además is_active = 1.
     * @param bool $allowDeleted Si es true, permite recuperar registros con deleted_at IS NOT NULL.
     * @return Location|null
     */
    public function findBySiteCode(string $siteCode, bool $onlyActive = true, bool $allowDeleted = false): ?Location
    {
        $normalizedCode = strtoupper(trim($siteCode));

        // «El mismo código de sede» es una sola regla con dos formas (D-5): donde la colación local
        // compara sin distinguir mayúsculas ni acentos, aquí se compara por la clave canónica, que es
        // la columna indexada del motor nuevo. El valor enlazado es el mismo en los dos casos.
        $siteCodeKey = $this->dialect->identityKey($normalizedCode);
        $identityColumn = $this->dialect->requiresCanonicalIdentityColumns() ? 'site_code_key' : 'site_code';

        $sql = "
            SELECT 
                id, 
                site_code, 
                name, 
                address,
                latitude,
                longitude,
                contact_name, 
                contact_phone, 
                contact_email,
                has_physical_reception,
                is_active, 
                created_at, 
                updated_at, 
                deleted_at
            FROM locations
            WHERE {$identityColumn} = :site_code_key
        ";

        if (!$allowDeleted) {
            $sql .= " AND deleted_at IS NULL";
        }

        if ($onlyActive) {
            $sql .= " AND is_active = {$this->dialect->booleanLiteral(true)}";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':site_code_key' => $siteCodeKey]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return Location::fromDatabaseRow($row);
    }

    /**
     * Recupera una sede por su identificador primario.
     *
     * @param int $id Identificador numérico de sede.
     * @param bool $allowDeleted Si es true, permite recuperar sedes dadas de baja lógica.
     * @return Location|null
     */
    public function findById(int $id, bool $allowDeleted = false): ?Location
    {
        $sql = "
            SELECT 
                id, 
                site_code, 
                name, 
                address,
                latitude,
                longitude,
                contact_name, 
                contact_phone, 
                contact_email,
                has_physical_reception,
                is_active, 
                created_at, 
                updated_at, 
                deleted_at
            FROM locations
            WHERE id = :id
        ";

        if (!$allowDeleted) {
            $sql .= " AND deleted_at IS NULL";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return Location::fromDatabaseRow($row);
    }

    /**
     * Sedes de un lote de identificadores en UNA sola consulta (Módulo 12 · T-HYBRID-27).
     *
     * Es la forma por lotes de `findById(..., allowDeleted: false)`: mismas columnas y mismo
     * descarte de bajas lógicas, pero con una sola ida y vuelta para toda la bandeja. La ruta del
     * técnico resolvía la sede de cada parada por separado, y contra el motor remoto esa lectura
     * era una de las tres vueltas por parada que agotaban el presupuesto de RNF-01.
     *
     * Los identificadores viajan como marcadores nombrados enlazados con `PDO::PARAM_INT` —la
     * forma que los dos drivers traducen a su tipo entero—, nunca interpolados en la sentencia.
     * Se deduplican y se descartan los imposibles, de modo que la consulta no lleva huecos.
     *
     * @param array<int, int|string> $locationIds
     * @return array<int, Location> Mapa `id de sede => Location`, sólo con las encontradas.
     */
    public function findManyByIds(array $locationIds): array
    {
        $ids = [];
        foreach ($locationIds as $locationId) {
            $id = (int)$locationId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $key = ":location_batch_{$index}";
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $sql = "
            SELECT 
                id, 
                site_code, 
                name, 
                address,
                latitude,
                longitude,
                contact_name, 
                contact_phone, 
                contact_email,
                has_physical_reception,
                is_active, 
                created_at, 
                updated_at, 
                deleted_at
            FROM locations
            WHERE id IN (" . implode(', ', $placeholders) . ")
              AND deleted_at IS NULL
            ORDER BY id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        $locations = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $locations[(int)$row['id']] = Location::fromDatabaseRow($row);
        }

        return $locations;
    }

    /**
     * Lista todas las sedes activas y no borradas lógicamente del sistema.
     *
     * @return array<Location>
     */
    public function findAllActive(): array
    {
        $sql = "
            SELECT 
                id, 
                site_code, 
                name, 
                address,
                latitude,
                longitude,
                contact_name, 
                contact_phone, 
                contact_email,
                has_physical_reception,
                is_active, 
                created_at, 
                updated_at, 
                deleted_at
            FROM locations
            WHERE deleted_at IS NULL
              AND is_active = {$this->dialect->booleanLiteral(true)}
            ORDER BY site_code ASC
        ";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll();

        return array_map(fn(array $row) => Location::fromDatabaseRow($row), $rows);
    }

    /**
     * Lista todas las sedes con filtros de estado, búsqueda y conteo de máquinas (RF-01, EARS 1.1).
     *
     * @param string $status 'active', 'inactive' o 'all'.
     * @param string|null $search Búsqueda de texto parcial.
     * @return array<array<string, mixed>>
     */
    public function findAll(string $status = 'all', ?string $search = null): array
    {
        $sql = "
            SELECT 
                l.id, 
                l.site_code, 
                l.name, 
                l.address,
                l.latitude,
                l.longitude,
                l.contact_name, 
                l.contact_phone, 
                l.contact_email,
                l.has_physical_reception,
                l.access_code_issued_at,
                (l.access_code_hash IS NOT NULL) AS has_access_code,
                l.is_active, 
                l.created_at, 
                l.updated_at, 
                l.deleted_at,
                COALESCE(m_active.active_count, 0) AS active_machines_count,
                COALESCE(m_total.total_count, 0) AS total_machines_count
            FROM locations l
            LEFT JOIN (
                SELECT location_id, COUNT(*) as active_count
                FROM machines
                WHERE is_active = {$this->dialect->booleanLiteral(true)} AND deleted_at IS NULL
                GROUP BY location_id
            ) m_active ON l.id = m_active.location_id
            LEFT JOIN (
                SELECT location_id, COUNT(*) as total_count
                FROM machines
                GROUP BY location_id
            ) m_total ON l.id = m_total.location_id
            WHERE 1=1
        ";

        $params = [];

        if ($status === 'active') {
            $sql .= " AND l.is_active = {$this->dialect->booleanLiteral(true)} AND l.deleted_at IS NULL";
        } elseif ($status === 'inactive') {
            $sql .= " AND (l.is_active = {$this->dialect->booleanLiteral(false)} OR l.deleted_at IS NOT NULL)";
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $sql .= " AND (
                l.site_code LIKE :s1 
                OR l.name LIKE :s2 
                OR l.address LIKE :s3 
                OR l.contact_name LIKE :s4
            )";
            $params[':s1'] = $term;
            $params[':s2'] = $term;
            $params[':s3'] = $term;
            $params[':s4'] = $term;
        }

        $sql .= " ORDER BY l.site_code ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(fn(array $row): array => [
            'id' => (int)$row['id'],
            'site_code' => (string)$row['site_code'],
            'name' => (string)$row['name'],
            'address' => (string)$row['address'],
            'latitude' => (float)$row['latitude'],
            'longitude' => (float)$row['longitude'],
            'contact_name' => $row['contact_name'] !== null ? (string)$row['contact_name'] : null,
            'contact_phone' => $row['contact_phone'] !== null ? (string)$row['contact_phone'] : null,
            'contact_email' => (string)($row['contact_email'] ?? 'recepcion@sede.vendguard.es'),
            'has_physical_reception' => (bool)$row['has_physical_reception'],
            'has_access_code' => (bool)$row['has_access_code'],
            'access_code_issued_at' => $row['access_code_issued_at'] !== null ? (string)$row['access_code_issued_at'] : null,
            'is_active' => (bool)$row['is_active'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
            'deleted_at' => $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            'active_machines_count' => (int)$row['active_machines_count'],
            'total_machines_count' => (int)$row['total_machines_count'],
        ], $rows);
    }

    /**
     * Da de alta una nueva sede cliente con código único (RF-01, EARS 1.2).
     *
     * @param array<string, mixed> $data
     * @return Location
     * @throws RuntimeException
     */
    public function create(array $data): Location
    {
        $siteCode = strtoupper(trim((string)$data['site_code']));

        // La clave canónica acompaña al código desde el alta (A-2, EARS-08.5): donde el motor la
        // exige como columna `NOT NULL` con índice único, una fila sin clave no sería un duplicado
        // invisible sino un alta incompleta, y la base la rechazaría. Donde no existe la columna,
        // la regla la aplica la colación y escribirla sería un error de columna.
        $identityColumns = '';
        $identityValues = '';
        $identityParams = [];
        if ($this->dialect->requiresCanonicalIdentityColumns()) {
            $identityColumns = "site_code_key,\n                ";
            $identityValues = ":site_code_key,\n                ";
            $identityParams[':site_code_key'] = $this->dialect->identityKey($siteCode);
        }

        $sql = "
            INSERT INTO locations (
                site_code,
                {$identityColumns}name,
                address,
                latitude,
                longitude,
                contact_name,
                contact_phone,
                contact_email,
                has_physical_reception,
                is_active,
                created_at,
                updated_at,
                deleted_at
            ) VALUES (
                :site_code,
                {$identityValues}:name,
                :address,
                :latitude,
                :longitude,
                :contact_name,
                :contact_phone,
                :contact_email,
                :has_physical_reception,
                :is_active,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP,
                NULL
            )
        ";

        // Los booleanos cruzan como parámetro enlazado con `PDO::PARAM_BOOL`: es la forma que los
        // dos drivers traducen al tipo de cada motor (D-3, y su certificación declarada), mientras
        // que el `1` de MariaDB no es siquiera un operador válido en el motor nuevo y un booleano
        // enviado como texto llegaría al motor nuevo como `1`/`''`, que su tipo `BOOLEAN` rechaza.
        //
        // Los valores se enlazan todos por nombre y la sentencia se ejecuta sin array: mezclar
        // `bindValue()` con un array de ejecución hace que PDO pierda la cuenta de los parámetros
        // ya enlazados (`HY093`), y esa pérdida no depende del motor, depende de la forma.
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':site_code', $siteCode, PDO::PARAM_STR);
        if (isset($identityParams[':site_code_key'])) {
            $stmt->bindValue(':site_code_key', $identityParams[':site_code_key'], PDO::PARAM_STR);
        }
        $stmt->bindValue(':name', trim((string)$data['name']), PDO::PARAM_STR);
        $stmt->bindValue(':address', trim((string)$data['address']), PDO::PARAM_STR);
        $stmt->bindValue(':latitude', array_key_exists('latitude', $data) ? (float)$data['latitude'] : 41.3850640, PDO::PARAM_STR);
        $stmt->bindValue(':longitude', array_key_exists('longitude', $data) ? (float)$data['longitude'] : 2.1734035, PDO::PARAM_STR);
        $contactName = isset($data['contact_name']) && $data['contact_name'] !== '' ? trim((string)$data['contact_name']) : null;
        $stmt->bindValue(':contact_name', $contactName, $contactName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $contactPhone = isset($data['contact_phone']) && $data['contact_phone'] !== '' ? trim((string)$data['contact_phone']) : null;
        $stmt->bindValue(':contact_phone', $contactPhone, $contactPhone !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $contactEmail = isset($data['contact_email']) && trim((string)$data['contact_email']) !== ''
            ? strtolower(trim((string)$data['contact_email']))
            : 'recepcion@sede.vendguard.es';
        if (filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('El correo de contacto de la sede no tiene un formato válido.');
        }
        $stmt->bindValue(':contact_email', $contactEmail, PDO::PARAM_STR);
        $stmt->bindValue(':has_physical_reception', array_key_exists('has_physical_reception', $data)
            ? (bool)$data['has_physical_reception']
            : true, PDO::PARAM_BOOL);
        $stmt->bindValue(':is_active', true, PDO::PARAM_BOOL);
        $stmt->execute();

        $newId = (int)$this->pdo->lastInsertId();
        $created = $this->findById($newId, true);
        if ($created === null) {
            throw new RuntimeException("Error al recuperar la sede recién creada con ID {$newId}");
        }

        return $created;
    }

    /**
     * Actualiza los datos descriptivos de una sede (RF-01, EARS 1.3).
     * El site_code es inmutable y no se modifica.
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
        if (array_key_exists('address', $data)) {
            $fields[] = "address = :address";
            $params[':address'] = trim((string)$data['address']);
        }
        if (array_key_exists('latitude', $data)) {
            $fields[] = "latitude = :latitude";
            $params[':latitude'] = (float)$data['latitude'];
        }
        if (array_key_exists('longitude', $data)) {
            $fields[] = "longitude = :longitude";
            $params[':longitude'] = (float)$data['longitude'];
        }
        if (array_key_exists('contact_name', $data)) {
            $fields[] = "contact_name = :contact_name";
            $params[':contact_name'] = $data['contact_name'] !== null && $data['contact_name'] !== '' ? trim((string)$data['contact_name']) : null;
        }
        if (array_key_exists('contact_phone', $data)) {
            $fields[] = "contact_phone = :contact_phone";
            $params[':contact_phone'] = $data['contact_phone'] !== null && $data['contact_phone'] !== '' ? trim((string)$data['contact_phone']) : null;
        }
        if (array_key_exists('contact_email', $data)) {
            $contactEmail = strtolower(trim((string)$data['contact_email']));
            if (filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('El correo de contacto de la sede no tiene un formato válido.');
            }
            $fields[] = "contact_email = :contact_email";
            $params[':contact_email'] = $contactEmail;
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = CURRENT_TIMESTAMP";

        $sql = "UPDATE locations SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() >= 0;
    }

    /**
     * Aplica borrado lógico (Soft Delete) marcando is_active = 0 y deleted_at = NOW().
     * Respeta la prohibición constitucional de eliminación física de registros (Art. III.1).
     *
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool
    {
        $sql = "
            UPDATE locations
            SET is_active = {$this->dialect->booleanLiteral(false)},
                deleted_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND deleted_at IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Restaura una sede previamente dada de baja (fija is_active = 1 y deleted_at = NULL).
     *
     * @param int $id
     * @return bool
     */
    public function restore(int $id): bool
    {
        $sql = "
            UPDATE locations
            SET is_active = {$this->dialect->booleanLiteral(true)},
                deleted_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Actualiza el correo oficial de la sede (RF-02.1).
     *
     * Es la dirección a la que el Centro de Notificaciones envía los avisos
     * institucionales; un valor inválido se rechaza antes de tocar el motor.
     */
    public function updateContactEmail(int $id, string $contactEmail): bool
    {
        $normalized = strtolower(trim($contactEmail));
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('El correo de contacto de la sede no tiene un formato válido.');
        }

        $sql = "
            UPDATE locations
            SET contact_email = :contact_email,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND deleted_at IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':contact_email', $normalized, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Actualiza el teléfono de contacto maestro de una sede.
     */
    public function updateContactPhone(int $id, string $contactPhone): bool
    {
        $sql = "
            UPDATE locations
            SET contact_phone = :contact_phone,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND deleted_at IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':contact_phone' => trim($contactPhone),
        ]);

        return $stmt->rowCount() >= 0;
    }

    /**
     * Cuenta el número de máquinas activas vinculadas a una sede (RF-01, EARS 1.4).
     *
     * @param int $locationId
     * @return int
     */
    public function countActiveMachines(int $locationId): int
    {
        $sql = "
            SELECT COUNT(*) 
            FROM machines 
            WHERE location_id = :location_id 
              AND is_active = {$this->dialect->booleanLiteral(true)} 
              AND deleted_at IS NULL
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':location_id' => $locationId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Cuenta el total histórico de máquinas (activas e inactivas) de una sede.
     *
     * @param int $locationId
     * @return int
     */
    public function countTotalMachines(int $locationId): int
    {
        $sql = "SELECT COUNT(*) FROM machines WHERE location_id = :location_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':location_id' => $locationId]);

        return (int)$stmt->fetchColumn();
    }
}
