<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Core\Domain\Service\SiteAccessCodeGenerator;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

/**
 * SeedRunner
 * 
 * Gestiona la inserción y actualización de datos semilla (Seed Data) en los dos motores
 * del sistema (MariaDB/MySQL y PostgreSQL).
 * Garantiza idempotencia —la cláusula de upsert la renderiza el adaptador de dialecto en
 * la forma de cada motor (T-HYBRID-25)— y cifrado seguro Bcrypt para contraseñas.
 *
 * El dialecto no se escribe aquí: los identificadores se escriben desnudos —ambos motores
 * los admiten así en todo el esquema—, los booleanos se declaran con el literal que cada
 * motor entiende (D-3), la aritmética temporal se pide al adaptador y, donde el esquema del
 * motor nuevo lo exige, se escribe además la clave canónica de identidad (`site_code_key`,
 * `email_key`, EARS-08.5).
 * 
 * Respeta el Dogma Vanilla y el principio de Clean Architecture.
 */
class SeedRunner
{
    private PDO $pdo;

    private SqlDialectAdapter $dialect;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->dialect = SqlDialectAdapter::forConnection($pdo);
    }

    /**
     * Ejecuta un fichero SQL de semillas directamente contra la base de datos.
     *
     * @param string $sqlFilePath Ruta al fichero SQL.
     * @return bool
     * @throws RuntimeException Si el fichero no existe o falla la ejecución.
     */
    public function runSqlFile(string $sqlFilePath): bool
    {
        if (!file_exists($sqlFilePath)) {
            throw new RuntimeException("El fichero de semillas no existe: {$sqlFilePath}");
        }

        $sqlContent = file_get_contents($sqlFilePath);
        if ($sqlContent === false) {
            throw new RuntimeException("Error al leer el fichero de semillas: {$sqlFilePath}");
        }

        try {
            $this->pdo->exec($sqlContent);
            return true;
        } catch (PDOException $e) {
            throw new RuntimeException("Error ejecutando script de semillas: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Clave de centro de desarrollo de una sede (hallazgo S-4).
     *
     * No es un secreto: solo existe para que el entorno local y la batería puedan
     * iniciar sesión de sede sin depender de una entrega física. La regla es
     * `DEV-<site_code>` y está documentada en el README.
     */
    public static function devAccessCode(string $siteCode): string
    {
        return 'DEV-' . strtoupper(trim($siteCode));
    }

    /**
     * ¿Se pueden sembrar las claves de centro de desarrollo?
     *
     * Solo en contexto de pruebas (`VENDGUARD_TESTING`, definido por el bootstrap) o
     * cuando el operador lo pide de forma explícita (`VENDGUARD_DEV_SITE_KEYS=1`), y
     * nunca con el entorno declarado de producción. La detección de producción se
     * replica aquí —en vez de depender de `SecretProvider`— porque esta capa se
     * ejecuta en arranques mínimos sin autoloader (`bin/init_cloud_db.php`), que es
     * precisamente el arranque del contenedor desplegado: sembrar aquí una clave
     * conocida sería entregar el portal de sede a quien lea el repositorio.
     */
    public static function devAccessCodeSeedingEnabled(): bool
    {
        foreach (['APP_ENV', 'VENDGUARD_ENV'] as $envName) {
            $value = getenv($envName);
            if ($value !== false && in_array(strtolower(trim($value)), ['production', 'prod'], true)) {
                return false;
            }
        }

        return defined('VENDGUARD_TESTING') || getenv('VENDGUARD_DEV_SITE_KEYS') === '1';
    }

    /**
     * Carga programática de sedes iniciales.
     *
     * En desarrollo/pruebas siembra además la clave de centro determinista y limpia
     * el freno de intentos (S-4), para que la batería parta siempre del mismo estado.
     * En producción el campo no se toca: cada sede recibe su clave por coordinación.
     *
     * @return int Número de sedes procesadas.
     */
    public function seedLocations(): int
    {
        $locations = self::canonicalLocationRows();

        $seedDevAccessCodes = self::devAccessCodeSeedingEnabled();
        $writesCanonicalKey = $this->dialect->requiresCanonicalIdentityColumns();

        $columns = [
            'site_code', 'name', 'address', 'latitude', 'longitude',
            'contact_name', 'contact_phone', 'postal_code', 'is_active',
        ];
        $placeholders = [
            ':site_code', ':name', ':address', ':latitude', ':longitude',
            ':contact_name', ':contact_phone', ':postal_code', $this->dialect->booleanLiteral(true),
        ];

        if ($writesCanonicalKey) {
            $columns[] = 'site_code_key';
            $placeholders[] = ':site_code_key';
        }

        if ($seedDevAccessCodes) {
            $columns = array_merge($columns, ['access_code_hash', 'access_code_issued_at', 'login_attempts', 'login_locked_until']);
            $placeholders = array_merge($placeholders, [':access_code_hash', 'CURRENT_TIMESTAMP', '0', 'NULL']);
        }

        // La regla del reencuentro: refrescar la ficha con la fila propuesta —y la clave
        // canónica donde existe—, reactivar la sede dada de baja y rearmar su clave de centro.
        $assignments = [
            'name' => SqlDialectAdapter::PROPOSED_VALUE,
            'address' => SqlDialectAdapter::PROPOSED_VALUE,
            'latitude' => SqlDialectAdapter::PROPOSED_VALUE,
            'longitude' => SqlDialectAdapter::PROPOSED_VALUE,
            'contact_name' => SqlDialectAdapter::PROPOSED_VALUE,
            'contact_phone' => SqlDialectAdapter::PROPOSED_VALUE,
            // El CP es la llave de la herencia territorial (Resolución QA 7): el
            // sembrador lo escribe, no lo deja al azar de una migración.
            'postal_code' => SqlDialectAdapter::PROPOSED_VALUE,
            'deleted_at' => 'NULL',
        ];

        if ($writesCanonicalKey) {
            $assignments['site_code_key'] = SqlDialectAdapter::PROPOSED_VALUE;
        }

        if ($seedDevAccessCodes) {
            $assignments['access_code_hash'] = SqlDialectAdapter::PROPOSED_VALUE;
            $assignments['access_code_issued_at'] = SqlDialectAdapter::PROPOSED_VALUE;
            $assignments['login_attempts'] = '0';
            $assignments['login_locked_until'] = 'NULL';
        }

        $sql = sprintf(
            'INSERT INTO locations (%s) VALUES (%s) %s',
            implode(', ', $columns),
            implode(', ', $placeholders),
            $this->dialect->upsertAssignments(['site_code'], $assignments)
        );

        $stmt = $this->pdo->prepare($sql);
        $count = 0;

        foreach ($locations as $loc) {
            $params = [
                ':site_code' => $loc['site_code'],
                ':name' => $loc['name'],
                ':address' => $loc['address'],
                ':latitude' => $loc['latitude'],
                ':longitude' => $loc['longitude'],
                ':contact_name' => $loc['contact_name'],
                ':contact_phone' => $loc['contact_phone'],
                ':postal_code' => $loc['postal_code'],
            ];
            if ($writesCanonicalKey) {
                $params[':site_code_key'] = $this->dialect->identityKey($loc['site_code']);
            }
            if ($seedDevAccessCodes) {
                // Se hashea la forma normalizada, la misma que compara `AuthService::loginSite()`,
                // de modo que `DEV-SEDE-BCN-01` y `devsede bcn 01` abren la misma sede.
                $params[':access_code_hash'] = password_hash(
                    SiteAccessCodeGenerator::normalize(self::devAccessCode($loc['site_code'])),
                    PASSWORD_BCRYPT,
                    ['cost' => 10]
                );
            }

            $stmt->execute($params);
            $count++;
        }

        return $count;
    }

    /**
     * Catálogo territorial del arranque: las tres demarcaciones de Barcelona.
     *
     * El sembrador es el **único** escritor canónico del arnés y del entorno local
     * (hallazgo H-2 de la verificación funcional del 2026-10-21): hasta esta fase
     * `seedAll()` no sembraba `operational_zones`, así que cada corrida de la batería
     * dejaba el catálogo vacío, las diez sedes «Sin Zona Asignada» y el recomendador
     * sin afinidad. Los valores son los mismos que declara `supabase_seeds.sql`
     * (secciones 9 a 12) y `CloudDeploySchemaParityTest` vigila esa paridad.
     *
     * @return int Número de zonas procesadas.
     */
    public function seedOperationalZones(): int
    {
        $sql = sprintf(
            'INSERT INTO operational_zones (code, name, color_hex, description, is_active) VALUES (%s) %s',
            ':code, :name, :color_hex, :description, ' . $this->dialect->booleanLiteral(true),
            $this->dialect->upsertAssignments(['code'], [
                'name' => SqlDialectAdapter::PROPOSED_VALUE,
                'color_hex' => SqlDialectAdapter::PROPOSED_VALUE,
                'description' => SqlDialectAdapter::PROPOSED_VALUE,
                'is_active' => $this->dialect->booleanLiteral(true),
            ])
        );
        $stmt = $this->pdo->prepare($sql);

        $count = 0;
        foreach (self::canonicalOperationalZoneRows() as $zone) {
            $stmt->execute([
                ':code' => $zone['code'],
                ':name' => $zone['name'],
                ':color_hex' => $zone['color_hex'],
                ':description' => $zone['description'],
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Códigos postales de las zonas sembradas, con alta sólo si el CP está libre.
     *
     * No reasigna un CP que ya vive en otra zona: la semilla declara el estado
     * canónico y un traslado hecho por el coordinador no se deshace al resembrar.
     *
     * @return int Códigos canónicos efectivamente vivos en su zona tras sembrar.
     */
    public function seedZonePostalCodes(): int
    {
        $sql = "
            INSERT INTO operational_zone_postal_codes (postal_code, zone_id)
            SELECT :postal_code, z.id
              FROM operational_zones z
             WHERE z.code = :zone_code
               AND NOT EXISTS (
                   SELECT 1 FROM operational_zone_postal_codes p
                    WHERE p.postal_code = :postal_code_live
                      AND p.released_at IS NULL
               )
        ";
        $stmt = $this->pdo->prepare($sql);

        foreach (self::canonicalZonePostalCodeRows() as $row) {
            $stmt->execute([
                ':postal_code' => $row['postal_code'],
                ':zone_code' => $row['zone_code'],
                ':postal_code_live' => $row['postal_code'],
            ]);
        }

        // El resumen publica el territorio **canónico en vigor**, no las altas de
        // esta pasada: reejecutar la siembra es idempotente y debe informar lo
        // mismo, y un CP movido por el coordinador se delata aquí (hallazgo H-2).
        $verify = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM operational_zone_postal_codes p
               INNER JOIN operational_zones z ON z.id = p.zone_id
              WHERE p.postal_code = :postal_code
                AND p.released_at IS NULL
                AND z.code = :zone_code'
        );

        $count = 0;
        foreach (self::canonicalZonePostalCodeRows() as $row) {
            $verify->execute([
                ':postal_code' => $row['postal_code'],
                ':zone_code' => $row['zone_code'],
            ]);
            $count += (int)$verify->fetchColumn();
        }

        return $count;
    }

    /**
     * Cuadrante demo: un titular por zona y las suplencias repartidas.
     *
     * Se resuelve por la clave canónica del usuario (`email_key`) y el código de la
     * zona, nunca por identificadores que la secuencia asigna distinto en cada base.
     *
     * @return int Vinculaciones canónicas efectivamente vivas tras sembrar.
     */
    public function seedTechnicianZoneAssignments(): int
    {
        $identityColumn = $this->dialect->requiresCanonicalIdentityColumns() ? 'email_key' : 'email';
        $sqlTemplate = 'INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
             SELECT u.id, z.id, %s
               FROM users u, operational_zones z
              WHERE u.%s = :email
                AND z.code = :zone_code
                AND NOT EXISTS (
                    SELECT 1 FROM technician_zone_assignments a
                     WHERE a.technician_id = u.id
                       AND a.zone_id = z.id
                       AND a.removed_at IS NULL
                )';

        foreach (self::canonicalTechnicianZoneRows() as $row) {
            $statement = $this->pdo->prepare(sprintf(
                $sqlTemplate,
                $this->dialect->booleanLiteral((bool)$row['is_primary']),
                $identityColumn
            ));
            $statement->execute([
                ':email' => $row['email'],
                ':zone_code' => $row['zone_code'],
            ]);
        }

        // Igual que el territorio: se informa del cuadrante canónico vivo, de modo
        // que resembrar no altera las cifras y una baja del coordinador se ve.
        $verify = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*)
               FROM technician_zone_assignments a
               INNER JOIN users u ON u.id = a.technician_id
               INNER JOIN operational_zones z ON z.id = a.zone_id
              WHERE u.%s = :email
                AND z.code = :zone_code
                AND a.removed_at IS NULL',
            $identityColumn
        ));

        $count = 0;
        foreach (self::canonicalTechnicianZoneRows() as $row) {
            $verify->execute([
                ':email' => $row['email'],
                ':zone_code' => $row['zone_code'],
            ]);
            $count += (int)$verify->fetchColumn();
        }

        return $count;
    }

    /**
     * Reafirma la herencia territorial de las sedes desde su código postal.
     *
     * Es la misma regla que publica `supabase_seeds.sql` (sección 12): la sede hereda
     * la zona viva de su CP, y se queda en «Sin Zona Asignada» cuando el CP no está
     * en el catálogo. Las sedes con anulación manual no se tocan (RF-03.2).
     *
     * @return int Número de sedes recolocadas.
     */
    public function seedTerritorialInheritance(): int
    {
        $zoneByPostalCode = [];
        $stmt = $this->pdo->query(
            'SELECT postal_code, zone_id FROM operational_zone_postal_codes WHERE released_at IS NULL'
        );
        foreach ($stmt->fetchAll() as $row) {
            $zoneByPostalCode[(string)$row['postal_code']] = (int)$row['zone_id'];
        }

        $update = $this->pdo->prepare('UPDATE locations SET zone_id = :zone_id WHERE id = :id');

        $count = 0;
        foreach ($this->pdo->query('SELECT id, postal_code, zone_id, is_manual_override FROM locations')->fetchAll() as $location) {
            if ((bool)$location['is_manual_override']) {
                continue;
            }

            $postalCode = $location['postal_code'] !== null ? (string)$location['postal_code'] : null;
            $expected = $postalCode !== null && isset($zoneByPostalCode[$postalCode]) ? $zoneByPostalCode[$postalCode] : null;
            $current = $location['zone_id'] !== null ? (int)$location['zone_id'] : null;

            if ($expected === $current) {
                continue;
            }

            $update->bindValue(':zone_id', $expected, $expected === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $update->bindValue(':id', (int)$location['id'], PDO::PARAM_INT);
            $update->execute();
            $count++;
        }

        return $count;
    }

    /**
     * Carga la configuración singleton de la Base Central de rutas.
     *
     * @return int Número de configuraciones procesadas.
     */
    public function seedRouteSettings(): int
    {
        // Fila singleton: se inserta sólo si no existe. La guarda portable evita además
        // escribir un identificador de identidad que el motor nuevo no admite a mano.
        $sql = "
            INSERT INTO route_settings (base_name, base_address, base_latitude, base_longitude)
            SELECT :base_name, :base_address, :base_latitude, :base_longitude
            WHERE NOT EXISTS (SELECT 1 FROM route_settings)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':base_name' => 'Base Central VendGuard Barcelona',
            ':base_address' => 'Carrer de la Marina 100, 08018 Barcelona',
            ':base_latitude' => 41.3935000,
            ':base_longitude' => 2.1890000,
        ]);

        return 1;
    }

    /**
     * Carga programática de máquinas iniciales.
     *
     * @return int Número de máquinas procesadas.
     */
    public function seedMachines(): int
    {
        $machines = self::canonicalMachineRows();

        $sql = sprintf(
            "INSERT INTO machines (location_id, code, model, machine_type, floor_wing, notes, is_active, sanitary_status, next_sanitary_inspection_due)
            VALUES (
                (SELECT id FROM locations WHERE site_code = :site_code LIMIT 1),
                :code,
                :model,
                :machine_type,
                :floor_wing,
                :notes,
                %s,
                'OK',
                %s
            )
            %s",
            $this->dialect->booleanLiteral(true),
            $this->dialect->addInterval('CURRENT_DATE()', ':due_days', 'DAY'),
            $this->dialect->upsertAssignments(['code'], [
                'model' => SqlDialectAdapter::PROPOSED_VALUE,
                'machine_type' => SqlDialectAdapter::PROPOSED_VALUE,
                'floor_wing' => SqlDialectAdapter::PROPOSED_VALUE,
                'notes' => SqlDialectAdapter::PROPOSED_VALUE,
                'sanitary_status' => SqlDialectAdapter::PROPOSED_VALUE,
                'next_sanitary_inspection_due' => SqlDialectAdapter::PROPOSED_VALUE,
                'deleted_at' => 'NULL',
            ])
        );

        $stmt = $this->pdo->prepare($sql);
        $count = 0;

        foreach ($machines as $mach) {
            $dueDays = ($mach['machine_type'] === 'PERISHABLE_FOOD' || $mach['machine_type'] === 'COMBO') ? 15 : 30;
            $stmt->execute([
                ':site_code' => $mach['site_code'],
                ':code' => $mach['code'],
                ':model' => $mach['model'],
                ':machine_type' => $mach['machine_type'],
                ':floor_wing' => $mach['floor_wing'],
                ':notes' => $mach['notes'],
                ':due_days' => $dueDays,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Carga programática de usuarios con contraseñas cifradas en Bcrypt.
     *
     * @param string $plainPassword Contraseña por defecto para los usuarios semilla.
     * @return int Número de usuarios procesados.
     */
    public function seedUsers(string $plainPassword = 'Password123!'): int
    {
        $passwordHash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 10]);

        $users = self::canonicalUserRows($passwordHash);

        $writesCanonicalKey = $this->dialect->requiresCanonicalIdentityColumns();

        $columns = ['name', 'email', 'password_hash', 'role', 'operator_code', 'phone', 'is_active'];
        $placeholders = [
            ':name', ':email', ':password_hash', ':role', ':operator_code', ':phone',
            $this->dialect->booleanLiteral(true),
        ];

        if ($writesCanonicalKey) {
            $columns[] = 'email_key';
            $placeholders[] = ':email_key';
        }

        $assignments = [
            'name' => SqlDialectAdapter::PROPOSED_VALUE,
            'password_hash' => SqlDialectAdapter::PROPOSED_VALUE,
            'role' => SqlDialectAdapter::PROPOSED_VALUE,
            'operator_code' => SqlDialectAdapter::PROPOSED_VALUE,
            'phone' => SqlDialectAdapter::PROPOSED_VALUE,
            'deleted_at' => 'NULL',
        ];

        if ($writesCanonicalKey) {
            $assignments['email_key'] = SqlDialectAdapter::PROPOSED_VALUE;
        }

        $sql = sprintf(
            'INSERT INTO users (%s) VALUES (%s) %s',
            implode(', ', $columns),
            implode(', ', $placeholders),
            $this->dialect->upsertAssignments(['email'], $assignments)
        );

        $stmt = $this->pdo->prepare($sql);
        $count = 0;

        foreach ($users as $user) {
            $params = [
                ':name' => $user['name'],
                ':email' => $user['email'],
                ':password_hash' => $user['password_hash'],
                ':role' => $user['role'],
                ':operator_code' => $user['operator_code'],
                ':phone' => $user['phone'],
            ];
            if ($writesCanonicalKey) {
                $params[':email_key'] = $this->dialect->identityKey($user['email']);
            }
            $stmt->execute($params);
            $count++;
        }

        return $count;
    }

    /**
     * Carga programática de frecuencias normativas preventivas (Art. II).
     *
     * @return int Número de configuraciones procesadas.
     */
    public function seedPreventiveSettings(): int
    {
        $settings = [
            ['machine_type' => 'PERISHABLE_FOOD', 'default_frequency_days' => 15, 'max_allowed_days' => 15, 'advance_warning_days' => 5],
            ['machine_type' => 'HOT_DRINKS',       'default_frequency_days' => 30, 'max_allowed_days' => 60, 'advance_warning_days' => 5],
            ['machine_type' => 'COLD_DRINKS',      'default_frequency_days' => 45, 'max_allowed_days' => 90, 'advance_warning_days' => 5],
            ['machine_type' => 'SNACKS',           'default_frequency_days' => 60, 'max_allowed_days' => 90, 'advance_warning_days' => 5],
            ['machine_type' => 'COMBO',            'default_frequency_days' => 15, 'max_allowed_days' => 45, 'advance_warning_days' => 5],
        ];

        $sql = sprintf(
            "INSERT INTO preventive_settings (machine_type, default_frequency_days, max_allowed_days, advance_warning_days)
            VALUES (:machine_type, :default_frequency_days, :max_allowed_days, :advance_warning_days)
            %s",
            $this->dialect->upsertAssignments(['machine_type'], [
                'default_frequency_days' => SqlDialectAdapter::PROPOSED_VALUE,
                'max_allowed_days' => SqlDialectAdapter::PROPOSED_VALUE,
                'advance_warning_days' => SqlDialectAdapter::PROPOSED_VALUE,
            ])
        );

        $stmt = $this->pdo->prepare($sql);
        $count = 0;

        foreach ($settings as $setting) {
            $stmt->execute([
                ':machine_type' => $setting['machine_type'],
                ':default_frequency_days' => $setting['default_frequency_days'],
                ':max_allowed_days' => $setting['max_allowed_days'],
                ':advance_warning_days' => $setting['advance_warning_days'],
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Catálogo inicial de causas raíz y soluciones estandarizadas (Módulo 25).
     *
     * Es el mismo catálogo que declara `database/supabase_seeds.sql` (sección 17):
     * 16 causas repartidas en las 5 categorías de `incident_category` y 34
     * soluciones, con exactamente una predeterminada activa por causa (D1). El
     * banco del arnés lo necesita porque sus suites crean incidencias y resuelven
     * con taxonomía, y no deben depender de un fichero SQL externo.
     *
     * El alta es **insertar si no existe** (`DO NOTHING`) y nunca reafirma los
     * campos: el catálogo lo gobierna Coordinación y un renombrado, una baja
     * lógica o un cambio de predeterminada posterior no puede deshacerse al
     * resembrar el parque.
     *
     * @return array{root_causes: int, standard_solutions: int} Filas canónicas procesadas.
     */
    public function seedRootCauseTaxonomy(): array
    {
        $causeSql = sprintf(
            "INSERT INTO root_causes (code, category, name, description, is_active)
             VALUES (:code, :category, :name, NULL, %s)
             %s",
            $this->dialect->booleanLiteral(true),
            $this->dialect->upsertDoNothing(['code'])
        );
        $causeStmt = $this->pdo->prepare($causeSql);
        $causeIdStmt = $this->pdo->prepare('SELECT id FROM root_causes WHERE code = :code');

        // El booleano de `is_default` va como literal y no como parámetro: PDO
        // convierte `false` en cadena vacía al ejecutar un array de valores, y
        // PostgreSQL la rechaza como booleano (22P02). Se preparan las dos formas
        // una sola vez y cada solución usa la que le corresponde.
        $solutionTemplate = "INSERT INTO standard_solutions (root_cause_id, code, name, is_default, is_active)
             VALUES (:root_cause_id, :code, :name, %s, %s)
             %s";
        $solutionStmts = [];
        foreach ([true, false] as $isDefault) {
            $solutionStmts[$isDefault ? 1 : 0] = $this->pdo->prepare(sprintf(
                $solutionTemplate,
                $this->dialect->booleanLiteral($isDefault),
                $this->dialect->booleanLiteral(true),
                $this->dialect->upsertDoNothing(['code'])
            ));
        }

        $causeCount = 0;
        $solutionCount = 0;

        foreach (self::canonicalRootCauseTaxonomyRows() as $cause) {
            $causeStmt->execute([
                ':code' => $cause['code'],
                ':category' => $cause['category'],
                ':name' => $cause['name'],
            ]);

            $causeIdStmt->execute([':code' => $cause['code']]);
            $causeId = $causeIdStmt->fetchColumn();
            if ($causeId === false) {
                throw new RuntimeException(
                    'No se pudo resolver la causa raíz sembrada «' . $cause['code'] . '» (Módulo 25).'
                );
            }
            $causeCount++;

            foreach ($cause['solutions'] as $solution) {
                $solutionStmts[$solution['is_default'] ? 1 : 0]->execute([
                    ':root_cause_id' => $causeId,
                    ':code' => $solution['code'],
                    ':name' => $solution['name'],
                ]);
                $solutionCount++;
            }
        }

        return [
            'root_causes' => $causeCount,
            'standard_solutions' => $solutionCount,
        ];
    }

    /**
     * Catálogo canónico de causas raíz con sus soluciones (Módulo 25).
     *
     * Cada causa declara una única solución inicial `is_default = true`; las
     * demás nacen activas y no predeterminadas. Los códigos son inmutables y
     * coinciden con los de `database/supabase_seeds.sql`; `CloudDeploySchemaParityTest`
     * vigila esa paridad.
     *
     * @return list<array{code: string, category: string, name: string, solutions: list<array{code: string, name: string, is_default: bool}>}>
     */
    private static function canonicalRootCauseTaxonomyRows(): array
    {
        return [
            [
                'code' => 'RC-COLD-01',
                'category' => 'TEMPERATURE_COLD',
                'name' => 'Sonda térmica NTC descalibrada o derivada',
                'solutions' => [
                    ['code' => 'SOL-COLD-01A', 'name' => 'Recalibración y ajuste de sonda NTC en cámara', 'is_default' => true],
                    ['code' => 'SOL-COLD-01B', 'name' => 'Sustitución de sonda térmica NTC roscada', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-COLD-02',
                'category' => 'TEMPERATURE_COLD',
                'name' => 'Obstrucción o suciedad en condensador / ventilador evaporador',
                'solutions' => [
                    ['code' => 'SOL-COLD-02A', 'name' => 'Limpieza de batería condensadora y desbloqueo de turbina', 'is_default' => true],
                    ['code' => 'SOL-COLD-02B', 'name' => 'Sustitución de motor ventilador de evaporador', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-COLD-03',
                'category' => 'TEMPERATURE_COLD',
                'name' => 'Fuga de gas refrigerante o fallo de compresor hermético',
                'solutions' => [
                    ['code' => 'SOL-COLD-03A', 'name' => 'Sellado de circuito y recarga de refrigerante', 'is_default' => true],
                    ['code' => 'SOL-COLD-03B', 'name' => 'Sustitución de grupo frigorífico / compresor', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-COLD-04',
                'category' => 'TEMPERATURE_COLD',
                'name' => 'Disparo de termostato de seguridad en caldera / grupo térmico',
                'solutions' => [
                    ['code' => 'SOL-COLD-04A', 'name' => 'Rearme manual de termostato y purga térmica', 'is_default' => true],
                    ['code' => 'SOL-COLD-04B', 'name' => 'Sustitución de termostato de seguridad 135°C', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-PAY-01',
                'category' => 'PAYMENT_SYSTEM',
                'name' => 'Moneda doblada o cuerpo extraño en selector multimoneda',
                'solutions' => [
                    ['code' => 'SOL-PAY-01A', 'name' => 'Apertura de canal, retirada de objeto y limpieza óptica', 'is_default' => true],
                    ['code' => 'SOL-PAY-01B', 'name' => 'Sustitución de monedero selector MDB', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-PAY-02',
                'category' => 'PAYMENT_SYSTEM',
                'name' => 'Tubos de cambio agotados o atasco en casete de devolución',
                'solutions' => [
                    ['code' => 'SOL-PAY-02A', 'name' => 'Desatasco de casete, nivelación y prueba de expulsión', 'is_default' => true],
                    ['code' => 'SOL-PAY-02B', 'name' => 'Sustitución de casete / módulo de cambio', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-PAY-03',
                'category' => 'PAYMENT_SYSTEM',
                'name' => 'Pérdida de enlace o bloqueo en terminal Contactless / Nayax',
                'solutions' => [
                    ['code' => 'SOL-PAY-03A', 'name' => 'Reinicio de bus MDB y reconexión de antena 4G', 'is_default' => true],
                    ['code' => 'SOL-PAY-03B', 'name' => 'Sustitución de terminal lector Contactless MDB', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-JAM-01',
                'category' => 'PRODUCT_JAM',
                'name' => 'Atasco de producto en espiral o bandeja de extracción',
                'solutions' => [
                    ['code' => 'SOL-JAM-01A', 'name' => 'Liberación de canal, realineado de espiral y test de giro', 'is_default' => true],
                    ['code' => 'SOL-JAM-01B', 'name' => 'Reconfiguración de paso de espiral según envase', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-JAM-02',
                'category' => 'PRODUCT_JAM',
                'name' => 'Fallo de motor extractor de espiral o microinterruptor de leva',
                'solutions' => [
                    ['code' => 'SOL-JAM-02A', 'name' => 'Ajuste de leva y reconexión de terminal de motor', 'is_default' => true],
                    ['code' => 'SOL-JAM-02B', 'name' => 'Sustitución de motor extractor de espiral 24V', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-JAM-03',
                'category' => 'PRODUCT_JAM',
                'name' => 'Suciedad o desalineación en fotocélula de detección de caída',
                'solutions' => [
                    ['code' => 'SOL-JAM-03A', 'name' => 'Limpieza de lentes infrarrojas y calibración de barrera', 'is_default' => true],
                    ['code' => 'SOL-JAM-03B', 'name' => 'Sustitución de sensor fotocélula de caída', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-JAM-04',
                'category' => 'PRODUCT_JAM',
                'name' => 'Bloqueo mecánico o pérdida de estanqueidad en grupo infusor de café',
                'solutions' => [
                    ['code' => 'SOL-JAM-04A', 'name' => 'Desmontaje, higienización, engrase alimentario y cambio de juntas', 'is_default' => true],
                    ['code' => 'SOL-JAM-04B', 'name' => 'Sustitución de grupo infusor de café espresso', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-ELEC-01',
                'category' => 'ELECTRICAL_OFF',
                'name' => 'Disparo de protección magneto-térmica o diferencial del edificio',
                'solutions' => [
                    ['code' => 'SOL-ELEC-01A', 'name' => 'Verificación de toma de corriente, rearme y arranque supervisado', 'is_default' => true],
                    ['code' => 'SOL-ELEC-01B', 'name' => 'Sustitución de fusible interno o filtro antiparasitario', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-ELEC-02',
                'category' => 'ELECTRICAL_OFF',
                'name' => 'Fallo en fuente de alimentación conmutada o placa de potencia',
                'solutions' => [
                    ['code' => 'SOL-ELEC-02A', 'name' => 'Reseteo de etapa de potencia y apriete de bornes', 'is_default' => true],
                    ['code' => 'SOL-ELEC-02B', 'name' => 'Sustitución de fuente / placa electrónica de control', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-ELEC-03',
                'category' => 'ELECTRICAL_OFF',
                'name' => 'Avería o apagado de pantalla Display LCD frontal',
                'solutions' => [
                    ['code' => 'SOL-ELEC-03A', 'name' => 'Reconexión de faja plana de datos y ajuste de contraste', 'is_default' => true],
                    ['code' => 'SOL-ELEC-03B', 'name' => 'Sustitución de módulo Display LCD gráfico', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-OTH-01',
                'category' => 'OTHER',
                'name' => 'Obstrucción calcárea o fallo de electroválvula / bomba hidráulica',
                'solutions' => [
                    ['code' => 'SOL-OTH-01A', 'name' => 'Descalcificación de circuito y limpieza de asiento de válvula', 'is_default' => true],
                    ['code' => 'SOL-OTH-01B', 'name' => 'Sustitución de electroválvula o bomba vibratoria', 'is_default' => false],
                    ['code' => 'SOL-OTH-01C', 'name' => 'Sustitución de cartucho filtrante antical', 'is_default' => false],
                ],
            ],
            [
                'code' => 'RC-OTH-02',
                'category' => 'OTHER',
                'name' => 'Vandalismo, golpe externo en puerta/botonera o falsa alarma sin fallo',
                'solutions' => [
                    ['code' => 'SOL-OTH-02A', 'name' => 'Ajuste mecánico de cerradura/puerta y test funcional completo', 'is_default' => true],
                    ['code' => 'SOL-OTH-02B', 'name' => 'Verificación integral sin anomalía técnica reproducible (falsa alarma)', 'is_default' => false],
                    ['code' => 'SOL-OTH-02C', 'name' => 'Reparación de botonera o cristal frontal', 'is_default' => false],
                ],
            ],
        ];
    }

    /**
     * Carga programática de órdenes preventivas y certificados sanitarios iniciales.
     *
     * @return array{orders: int, certificates: int}
     */
    public function seedPreventiveOrdersAndCertificates(): array
    {
        $sqlOrders = sprintf(
            "INSERT INTO preventive_orders (
                order_code, machine_id, location_id, assigned_technician_id,
                status, order_type, scheduled_date, due_date, started_at, completed_at,
                temperature_measured, result, is_quarantine_triggered, notes
            ) VALUES
            (
                'ORD-PREV-2026-0001',
                (SELECT id FROM machines WHERE code = 'VEND-0101' LIMIT 1),
                (SELECT id FROM locations WHERE site_code = 'SEDE-BCN-01' LIMIT 1),
                (SELECT id FROM users WHERE email = 'jordi.ruta@vendguard.internal' LIMIT 1),
                'COMPLETED',
                'ROUTINE',
                %s,
                CURRENT_DATE,
                %s,
                %s,
                3.2,
                'CONFORME',
                %s,
                'Inspección higiénico-sanitaria inicial conforme. Temperatura en rango seguro (Art. II).'
            ),
            (
                'ORD-PREV-2026-0002',
                (SELECT id FROM machines WHERE code = 'VEND-0201' LIMIT 1),
                (SELECT id FROM locations WHERE site_code = 'SEDE-BCN-02' LIMIT 1),
                (SELECT id FROM users WHERE email = 'jordi.ruta@vendguard.internal' LIMIT 1),
                'COMPLETED',
                'ROUTINE',
                %s,
                CURRENT_DATE,
                %s,
                %s,
                3.8,
                'CONFORME',
                %s,
                'Inspección periódica inicial conforme.'
            ),
            (
                'ORD-PREV-2026-0003',
                (SELECT id FROM machines WHERE code = 'VEND-0102' LIMIT 1),
                (SELECT id FROM locations WHERE site_code = 'SEDE-BCN-01' LIMIT 1),
                NULL,
                'PENDING_ASSIGNMENT',
                'ROUTINE',
                %s,
                %s,
                NULL,
                NULL,
                NULL,
                NULL,
                %s,
                'Revisión preventiva inminente pendiente de asignación técnica.'
            )
            %s",
            $this->dialect->subtractInterval('CURRENT_DATE()', 1, 'DAY'),
            $this->dialect->subtractInterval('NOW()', 1, 'DAY'),
            $this->dialect->subtractInterval('NOW()', 1, 'DAY'),
            $this->dialect->booleanLiteral(false),
            $this->dialect->subtractInterval('CURRENT_DATE()', 2, 'DAY'),
            $this->dialect->subtractInterval('NOW()', 2, 'DAY'),
            $this->dialect->subtractInterval('NOW()', 2, 'DAY'),
            $this->dialect->booleanLiteral(false),
            $this->dialect->addInterval('CURRENT_DATE()', 2, 'DAY'),
            $this->dialect->addInterval('CURRENT_DATE()', 2, 'DAY'),
            $this->dialect->booleanLiteral(false),
            $this->dialect->upsertAssignments(['order_code'], [
                'status' => SqlDialectAdapter::PROPOSED_VALUE,
                'result' => SqlDialectAdapter::PROPOSED_VALUE,
            ])
        );

        $this->pdo->exec($sqlOrders);

        $sqlCerts = sprintf(
            "INSERT INTO sanitary_certificates (
                certificate_code, preventive_order_id, machine_id, location_id,
                technician_id, technician_name, technician_operator_code,
                inspection_date, valid_until, temperature_measured,
                result, status
            ) VALUES
            (
                'CERT-2026-0001',
                (SELECT id FROM preventive_orders WHERE order_code = 'ORD-PREV-2026-0001' LIMIT 1),
                (SELECT id FROM machines WHERE code = 'VEND-0101' LIMIT 1),
                (SELECT id FROM locations WHERE site_code = 'SEDE-BCN-01' LIMIT 1),
                (SELECT id FROM users WHERE email = 'jordi.ruta@vendguard.internal' LIMIT 1),
                'Jordi Técnico Ruta BCN',
                'OP-01',
                %s,
                %s,
                3.2,
                'CONFORME',
                'VALID'
            ),
            (
                'CERT-2026-0002',
                (SELECT id FROM preventive_orders WHERE order_code = 'ORD-PREV-2026-0002' LIMIT 1),
                (SELECT id FROM machines WHERE code = 'VEND-0201' LIMIT 1),
                (SELECT id FROM locations WHERE site_code = 'SEDE-BCN-02' LIMIT 1),
                (SELECT id FROM users WHERE email = 'jordi.ruta@vendguard.internal' LIMIT 1),
                'Jordi Técnico Ruta BCN',
                'OP-01',
                %s,
                %s,
                3.8,
                'CONFORME',
                'VALID'
            )
            %s",
            $this->dialect->subtractInterval('CURRENT_DATE()', 1, 'DAY'),
            $this->dialect->addInterval('CURRENT_DATE()', 14, 'DAY'),
            $this->dialect->subtractInterval('CURRENT_DATE()', 2, 'DAY'),
            $this->dialect->addInterval('CURRENT_DATE()', 13, 'DAY'),
            $this->dialect->upsertAssignments(['certificate_code'], [
                'valid_until' => SqlDialectAdapter::PROPOSED_VALUE,
                'status' => SqlDialectAdapter::PROPOSED_VALUE,
            ])
        );

        $this->pdo->exec($sqlCerts);

        $this->pdo->exec(sprintf(
            "UPDATE machines SET
                last_sanitary_inspection_at = %s,
                next_sanitary_inspection_due = %s,
                sanitary_status = 'OK'
            WHERE code = 'VEND-0101'",
            $this->dialect->subtractInterval('NOW()', 1, 'DAY'),
            $this->dialect->addInterval('CURRENT_DATE()', 14, 'DAY')
        ));

        $this->pdo->exec(sprintf(
            "UPDATE machines SET
                last_sanitary_inspection_at = %s,
                next_sanitary_inspection_due = %s,
                sanitary_status = 'OK'
            WHERE code = 'VEND-0201'",
            $this->dialect->subtractInterval('NOW()', 2, 'DAY'),
            $this->dialect->addInterval('CURRENT_DATE()', 13, 'DAY')
        ));

        $this->pdo->exec(sprintf(
            "UPDATE machines SET
                last_sanitary_inspection_at = %s,
                next_sanitary_inspection_due = %s,
                sanitary_status = 'OK'
            WHERE code = 'VEND-0102'",
            $this->dialect->subtractInterval('NOW()', 28, 'DAY'),
            $this->dialect->addInterval('CURRENT_DATE()', 2, 'DAY')
        ));

        return ['orders' => 3, 'certificates' => 2];
    }

    /**
     * Carga programática de las respuestas del checklist normativo de las inspecciones
     * preventivas ya completadas (RF-PREV-03, RF-PD-05).
     *
     * Implementa el mismo guardado idempotente por pareja (orden, código de ítem) que
     * `PdoPreventiveItemRepository::saveOrderItems()` —actualiza la respuesta existente o
     * inserta la nueva, sin borrar nunca físicamente (Art. III)— pero con SQL propio: esta
     * capa se ejecuta en arranques mínimos (por ejemplo el contenedor de despliegue) donde
     * el bootstrap carga las clases una a una, así que el `SqlDialectAdapter` que sostiene
     * su dialecto tiene que estar requerido allí (`bin/init_cloud_db.php` lo hace).
     *
     * Sin estas respuestas, la ficha de detalle de la orden mostraría el bloque de
     * checklist vacío y no habría nada que auditar desde Coordinación.
     *
     * @return int Número de órdenes cuyo checklist quedó sembrado.
     */
    public function seedPreventiveChecklistItems(): int
    {
        $selectOrder = $this->pdo->prepare("
            SELECT id
            FROM preventive_orders
            WHERE order_code = :order_code
            LIMIT 1
        ");
        $selectItem = $this->pdo->prepare("
            SELECT id
            FROM preventive_order_items
            WHERE preventive_order_id = :order_id
              AND item_code = :item_code
            LIMIT 1
        ");
        $updateItem = $this->pdo->prepare("
            UPDATE preventive_order_items
            SET
                item_description = :item_description,
                is_critical = :is_critical,
                status = :status,
                observations = :observations
            WHERE id = :id
        ");
        $insertItem = $this->pdo->prepare("
            INSERT INTO preventive_order_items (
                preventive_order_id,
                item_code,
                item_description,
                is_critical,
                status,
                observations
            ) VALUES (
                :order_id,
                :item_code,
                :item_description,
                :is_critical,
                :status,
                :observations
            )
        ");

        $seededOrders = 0;

        foreach ($this->preventiveChecklistBlueprints() as $orderCode => $blueprint) {
            $selectOrder->execute([':order_code' => $orderCode]);
            $orderId = $selectOrder->fetchColumn();
            $selectOrder->closeCursor();

            if ($orderId === false || $orderId === null) {
                continue;
            }

            foreach ($blueprint as $item) {
                // Cada sentencia recibe exactamente sus marcadores: pasar parámetros de más
                // (o de menos) es un error de vinculación en PDO, no una precaución inofensiva.
                $itemColumns = [
                    ':item_description' => $item['item_description'],
                    ':is_critical' => $item['is_critical'] ? 1 : 0,
                    ':status' => $item['status'],
                    ':observations' => $item['observations'],
                ];

                $selectItem->execute([':order_id' => (int)$orderId, ':item_code' => $item['item_code']]);
                $existingId = $selectItem->fetchColumn();
                $selectItem->closeCursor();

                if ($existingId !== false && $existingId !== null) {
                    $updateItem->execute($itemColumns + [':id' => (int)$existingId]);
                    continue;
                }

                $insertItem->execute(
                    $itemColumns + [':order_id' => (int)$orderId, ':item_code' => $item['item_code']]
                );
            }

            $seededOrders++;
        }

        return $seededOrders;
    }

    /**
     * Siembra la trazabilidad de auditoría de las órdenes preventivas demo (RF-PD-09, Art. III).
     *
     * Cada acción del ciclo preventivo deja su evento append-only bajo la entidad de la
     * máquina auditada (así lo registran los controladores y servicios reales del módulo
     * vía `AuditLogger::logMachineEvent`), con el `order_code` en `metadata` como atribución
     * a la orden. Sin ellos, la ficha de detalle mostraría la cronología vacía aunque la
     * orden tenga historia real que auditar. El guardado es idempotente por la terna
     * (máquina, acción, order_code) y nunca borra nada.
     *
     * @return int Número de eventos de auditoría sembrados.
     */
    public function seedPreventiveAuditTrail(): int
    {
        $selectOrder = $this->pdo->prepare("
            SELECT id, order_code, machine_id, status, assigned_technician_id, completed_at
            FROM preventive_orders
            WHERE order_code = :order_code
            LIMIT 1
        ");
        $existsEvent = $this->pdo->prepare("
            SELECT id
            FROM audit_log
            WHERE entity_type = 'MACHINE'
              AND entity_id = :machine_id
              AND action = :action
              AND {$this->dialect->jsonExtract('metadata', 'order_code')} = :order_code
            LIMIT 1
        ");
        $insertEvent = $this->pdo->prepare("
            INSERT INTO audit_log (
                entity_type, entity_id, action, user_id, user_role, user_name,
                previous_state, new_state, metadata, created_at
            ) VALUES (
                'MACHINE', :machine_id, :action, :user_id, :user_role, :user_name,
                :previous_state, :new_state, :metadata, :created_at
            )
        ");

        $coordinatorId = (int)($this->pdo->query(
            "SELECT id FROM users WHERE email = 'coordinacion@vendguard.internal' LIMIT 1"
        )->fetchColumn() ?: 1);
        $technicianId = (int)($this->pdo->query(
            "SELECT id FROM users WHERE email = 'jordi.ruta@vendguard.internal' LIMIT 1"
        )->fetchColumn() ?: 1);

        $seeded = 0;

        foreach ($this->preventiveAuditBlueprints() as $orderCode => $events) {
            $selectOrder->execute([':order_code' => $orderCode]);
            $order = $selectOrder->fetch(PDO::FETCH_ASSOC);
            $selectOrder->closeCursor();

            if ($order === false) {
                continue;
            }

            foreach ($events as $event) {
                $existsEvent->execute([
                    ':machine_id' => (int)$order['machine_id'],
                    ':action' => $event['action'],
                    ':order_code' => (string)$order['order_code'],
                ]);
                $alreadyLogged = $existsEvent->fetchColumn();
                $existsEvent->closeCursor();

                if ($alreadyLogged !== false && $alreadyLogged !== null) {
                    continue;
                }

                // El veredicto de la inspección y el certificado son hechos del técnico;
                // el resto del ciclo es coordinación.
                $isCoordinatorAction = !in_array(
                    $event['action'],
                    ['EVALUATE_PREVENTIVE_CHECKLIST', 'ISSUE_SANITARY_CERTIFICATE'],
                    true
                );
                $insertEvent->execute([
                    ':machine_id' => (int)$order['machine_id'],
                    ':action' => $event['action'],
                    ':user_id' => $isCoordinatorAction ? $coordinatorId : $technicianId,
                    ':user_role' => $isCoordinatorAction ? 'COORDINATOR' : 'TECHNICIAN',
                    ':user_name' => $event['user_name'],
                    ':previous_state' => $event['previous_state'] !== null ? json_encode($event['previous_state']) : null,
                    ':new_state' => json_encode($event['new_state']),
                    ':metadata' => json_encode(array_merge(
                        $event['metadata'] ?? [],
                        ['order_code' => $order['order_code']]
                    )),
                    ':created_at' => $event['created_at'],
                ]);
                $seeded++;
            }
        }

        return $seeded;
    }

    /**
     * Guion de eventos de auditoría de las órdenes demo, en orden cronológico.
     *
     * @return array<string, list<array{action: string, user_name: string, previous_state: array<string, mixed>|null, new_state: array<string, mixed>, metadata: array<string, mixed>|null, created_at: string}>>
     */
    private function preventiveAuditBlueprints(): array
    {
        $coordinatorName = 'Sara Coordinadora';
        $technicianName = 'Jordi Técnico Ruta BCN';
        $createdAt = date('Y-m-d H:i:s', strtotime('-3 days'));

        return [
            'ORD-PREV-2026-0001' => [
                [
                    'action' => 'CREATE_PREVENTIVE_ORDER',
                    'user_name' => $coordinatorName,
                    'previous_state' => null,
                    'new_state' => ['status' => 'PENDING_ASSIGNMENT', 'order_type' => 'ROUTINE'],
                    'metadata' => ['origin' => 'SCHEDULER'],
                    'created_at' => $createdAt,
                ],
                [
                    'action' => 'ASSIGN_PREVENTIVE_ORDER',
                    'user_name' => $coordinatorName,
                    'previous_state' => ['status' => 'PENDING_ASSIGNMENT'],
                    'new_state' => ['status' => 'SCHEDULED'],
                    'metadata' => ['operator_code' => 'OP-01'],
                    'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
                ],
                [
                    'action' => 'EVALUATE_PREVENTIVE_CHECKLIST',
                    'user_name' => $technicianName,
                    'previous_state' => ['status' => 'IN_INSPECTION'],
                    'new_state' => ['status' => 'COMPLETED', 'result' => 'CONFORME', 'temperature_measured' => 3.2],
                    'metadata' => ['quarantine_triggered' => false],
                    'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
                ],
            ],
            'ORD-PREV-2026-0002' => [
                [
                    'action' => 'CREATE_PREVENTIVE_ORDER',
                    'user_name' => $coordinatorName,
                    'previous_state' => null,
                    'new_state' => ['status' => 'PENDING_ASSIGNMENT', 'order_type' => 'ROUTINE'],
                    'metadata' => ['origin' => 'SCHEDULER'],
                    'created_at' => date('Y-m-d H:i:s', strtotime('-4 days')),
                ],
                [
                    'action' => 'ASSIGN_PREVENTIVE_ORDER',
                    'user_name' => $coordinatorName,
                    'previous_state' => ['status' => 'PENDING_ASSIGNMENT'],
                    'new_state' => ['status' => 'SCHEDULED'],
                    'metadata' => ['operator_code' => 'OP-01'],
                    'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
                ],
                [
                    'action' => 'EVALUATE_PREVENTIVE_CHECKLIST',
                    'user_name' => $technicianName,
                    'previous_state' => ['status' => 'IN_INSPECTION'],
                    'new_state' => ['status' => 'COMPLETED', 'result' => 'CONFORME', 'temperature_measured' => 3.8],
                    'metadata' => ['quarantine_triggered' => false],
                    'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
                ],
            ],
            'ORD-PREV-2026-0003' => [
                [
                    'action' => 'CREATE_PREVENTIVE_ORDER',
                    'user_name' => $coordinatorName,
                    'previous_state' => null,
                    'new_state' => ['status' => 'PENDING_ASSIGNMENT', 'order_type' => 'ROUTINE'],
                    'metadata' => ['origin' => 'SCHEDULER'],
                    'created_at' => $createdAt,
                ],
            ],
        ];
    }

    /**
     * Catálogo normativo respondido por las dos inspecciones completadas del escenario demo:
     * cuatro ítems críticos y tres secundarios conforme a la severidad tipificada (EARS 3.3).
     *
     * Los estados usan los literales del enum de `preventive_order_items.status` para no
     * arrastrar dependencias de autoload a esta capa.
     *
     * @return array<string, list<array{item_code: string, item_description: string, is_critical: bool, status: string, observations: string|null}>>
     */
    private function preventiveChecklistBlueprints(): array
    {
        $temperatureItem = [
            'item_code' => 'TEMPERATURE_READING',
            'item_description' => 'Temperatura de sonda estabilizada ≤ 4.0 °C en alimentos perecederos',
            'is_critical' => true,
        ];
        $boilerLeakItem = [
            'item_code' => 'BOILER_LEAK',
            'item_description' => 'Fuga activa en caldera o circuito hidráulico con riesgo de quemadura o inundación',
            'is_critical' => true,
        ];
        $electricalItem = [
            'item_code' => 'ELECTRICAL_GROUNDING',
            'item_description' => 'Derivación, cable pelado o ausencia de toma de tierra eléctrica',
            'is_critical' => true,
        ];
        $pestItem = [
            'item_code' => 'PEST_PRESENCE',
            'item_description' => 'Presencia de plagas, insectos o contaminación biológica en el interior de la cabina',
            'is_critical' => true,
        ];
        $casingItem = [
            'item_code' => 'CASING_WEAR',
            'item_description' => 'Desgaste incipiente o suciedad leve en carcasas exteriores o botonera',
            'is_critical' => false,
        ];
        $lightingItem = [
            'item_code' => 'LED_LIGHTING',
            'item_description' => 'Iluminación LED interior parcialmente degradada o tenue',
            'is_critical' => false,
        ];
        $filterItem = [
            'item_code' => 'WATER_FILTER_LIFE',
            'item_description' => 'Cartucho de filtro de agua próximo a agotar su ciclo de vida útil',
            'is_critical' => false,
        ];

        return [
            'ORD-PREV-2026-0001' => [
                $temperatureItem + [
                    'status' => 'PASS',
                    'observations' => 'Sonda estabilizada en 3.2 °C tras espera de régimen térmico.',
                ],
                $boilerLeakItem + [
                    'status' => 'NOT_APPLICABLE',
                    'observations' => 'Máquina sin circuito de caldera: comprobación no aplicable.',
                ],
                $electricalItem + [
                    'status' => 'PASS',
                    'observations' => 'Toma de tierra verificada con polímetro sin incidencias.',
                ],
                $pestItem + [
                    'status' => 'PASS',
                    'observations' => 'Cabina desinfectada y sin indicios biológicos.',
                ],
                $casingItem + [
                    'status' => 'PASS',
                    'observations' => null,
                ],
                $lightingItem + [
                    'status' => 'WARN',
                    'observations' => 'Tira LED superior con brillo reducido; se programa revisión de seguimiento.',
                ],
                $filterItem + [
                    'status' => 'PASS',
                    'observations' => null,
                ],
            ],
            'ORD-PREV-2026-0002' => [
                $temperatureItem + [
                    'status' => 'PASS',
                    'observations' => 'Lectura de sonda registrada en 3.8 °C, dentro del margen normativo.',
                ],
                $boilerLeakItem + [
                    'status' => 'NOT_APPLICABLE',
                    'observations' => 'Combo sin circuito de agua caliente: comprobación no aplicable.',
                ],
                $electricalItem + [
                    'status' => 'PASS',
                    'observations' => null,
                ],
                $pestItem + [
                    'status' => 'PASS',
                    'observations' => 'Sin presencia de insectos ni restos orgánicos.',
                ],
                $casingItem + [
                    'status' => 'PASS',
                    'observations' => null,
                ],
                $lightingItem + [
                    'status' => 'PASS',
                    'observations' => null,
                ],
                $filterItem + [
                    'status' => 'WARN',
                    'observations' => 'Cartucho al 80% de vida útil; sustituir en la próxima visita programada.',
                ],
            ],
        ];
    }

    /**
     * Carga programática de catálogo de repuestos y compatibilidades (Módulo 06 / M2).
     *
     * @return array{parts: int, compatibilities: int}
     */
    public function seedSpareParts(): array
    {
        $parts = [
            [
                'part_code' => 'VALV-ULKA-01',
                'name' => 'Electroválvula 24V 2 Vías Ulka',
                'category' => 'HYDRAULIC',
                'manufacturer' => 'Ulka',
                'reference_cost' => 28.50,
                'is_active' => 1,
                'notes' => 'Válvula de entrada de agua para calderas espresso',
                'models' => ['Bianchi Gaia Espresso', 'Fas Perla', 'Azkoyen Palma+'],
            ],
            [
                'part_code' => 'BOMB-VIB-02',
                'name' => 'Bomba Vibratoria 230V EX5',
                'category' => 'HYDRAULIC',
                'manufacturer' => 'Ulka / CEME',
                'reference_cost' => 36.00,
                'is_active' => 1,
                'notes' => 'Bomba de presión estándar para café',
                'models' => ['Bianchi Gaia Espresso', 'Fas Perla', 'Azkoyen Palma+'],
            ],
            [
                'part_code' => 'SOND-NTC-01',
                'name' => 'Sonda Térmica NTC 10K Roscada',
                'category' => 'THERMAL',
                'manufacturer' => 'Carel',
                'reference_cost' => 15.20,
                'is_active' => 1,
                'notes' => 'Sonda de temperatura para cámaras refrigeradas',
                'models' => ['Sanden Vendo G-Drink', 'Necta Samba Combo', 'Azkoyen Palma+', 'Fas Fast 900'],
            ],
            [
                'part_code' => 'TERM-SEG-01',
                'name' => 'Termostato de Seguridad 135°C',
                'category' => 'THERMAL',
                'manufacturer' => 'Campini',
                'reference_cost' => 12.80,
                'is_active' => 1,
                'notes' => 'Rearme manual de seguridad para grupo térmico',
                'models' => ['Bianchi Gaia Espresso', 'Fas Perla', 'Azkoyen Palma+'],
            ],
            [
                'part_code' => 'MOT-ESP-01',
                'name' => 'Motor Extractor de Espiral 24V DC',
                'category' => 'MECHANICAL',
                'manufacturer' => 'Sande / Merkle',
                'reference_cost' => 24.50,
                'is_active' => 1,
                'notes' => 'Motor con microinterruptor de posición',
                'models' => ['Sanden Vendo G-Drink', 'Necta Samba Combo', 'Azkoyen Palma+', 'Fas Fast 900'],
            ],
            [
                'part_code' => 'MON-CASH-01',
                'name' => 'Monedero Selector de Monedas NRI G13',
                'category' => 'PAYMENT_SYSTEM',
                'manufacturer' => 'Crane / CPI',
                'reference_cost' => 185.00,
                'is_active' => 1,
                'notes' => 'Validador estándar MDB multimoneda',
                'models' => ['Sanden Vendo G-Drink', 'Bianchi Gaia Espresso', 'Necta Samba Combo', 'Azkoyen Palma+', 'Fas Fast 900', 'Fas Perla'],
            ],
            [
                'part_code' => 'DISP-LCD-01',
                'name' => 'Display LCD Gráfico 128x64 Azul',
                'category' => 'ELECTRONIC',
                'manufacturer' => 'Winstar',
                'reference_cost' => 42.00,
                'is_active' => 1,
                'notes' => 'Pantalla frontal de selección de usuario',
                'models' => ['Sanden Vendo G-Drink', 'Bianchi Gaia Espresso', 'Necta Samba Combo', 'Azkoyen Palma+', 'Fas Fast 900', 'Fas Perla'],
            ],
            [
                'part_code' => 'JUNT-TOR-01',
                'name' => 'Kit 10 Juntas Tóricas Silicona Alimentaria',
                'category' => 'CONSUMABLE',
                'manufacturer' => 'Parker',
                'reference_cost' => 8.50,
                'is_active' => 1,
                'notes' => 'Juntas de estanqueidad para grupo de café y pistón',
                'models' => ['Bianchi Gaia Espresso', 'Fas Perla', 'Azkoyen Palma+'],
            ],
            [
                'part_code' => 'TELEC-NAYAX-01',
                'name' => 'Lector Contactless & Telemetría VPOS Touch',
                'category' => 'PAYMENT_SYSTEM',
                'manufacturer' => 'Nayax',
                'reference_cost' => 295.00,
                'is_active' => 1,
                'notes' => 'Terminal de pago con tarjeta, móvil contactless y telemetría 4G MDB',
                'models' => ['Sanden Vendo G-Drink', 'Bianchi Gaia Espresso', 'Necta Samba Combo', 'Azkoyen Palma+', 'Fas Fast 900', 'Fas Perla'],
            ],
            [
                'part_code' => 'FOTO-CAID-01',
                'name' => 'Sensor Fotocélula Detección Caída de Producto',
                'category' => 'ELECTRONIC',
                'manufacturer' => 'Omron / Fas',
                'reference_cost' => 38.00,
                'is_active' => 1,
                'notes' => 'Barrera óptica infrarroja de validación de entrega de producto',
                'models' => ['Sanden Vendo G-Drink', 'Necta Samba Combo', 'Azkoyen Palma+', 'Fas Fast 900'],
            ],
            [
                'part_code' => 'VENT-EVAP-01',
                'name' => 'Motor Ventilador Evaporador No-Frost 230V',
                'category' => 'THERMAL',
                'manufacturer' => 'Ebm-Papst',
                'reference_cost' => 46.50,
                'is_active' => 1,
                'notes' => 'Turbina de recirculación de aire frío en cabina refrigerada',
                'models' => ['Sanden Vendo G-Drink', 'Necta Samba Combo', 'Azkoyen Palma+', 'Fas Fast 900'],
            ],
            [
                'part_code' => 'GRUP-ESPR-01',
                'name' => 'Grupo Infusor de Café Espresso Z4000',
                'category' => 'MECHANICAL',
                'manufacturer' => 'N&W / Bianchi',
                'reference_cost' => 145.00,
                'is_active' => 1,
                'notes' => 'Módulo de erogación de café en grano con cámara de compresión variable',
                'models' => ['Bianchi Gaia Espresso', 'Fas Perla'],
            ],
            [
                'part_code' => 'FILT-AGUA-01',
                'name' => 'Cartucho Filtración Antical y Carbón Activo',
                'category' => 'CONSUMABLE',
                'manufacturer' => 'Brita Professional',
                'reference_cost' => 58.00,
                'is_active' => 1,
                'notes' => 'Filtro descalcificador de agua potable para calderas vending',
                'models' => ['Bianchi Gaia Espresso', 'Fas Perla'],
            ],
        ];

        $sqlPart = sprintf(
            "INSERT INTO spare_parts (part_code, name, category, manufacturer, reference_cost, is_active, notes)
            VALUES (:part_code, :name, :category, :manufacturer, :reference_cost, :is_active, :notes)
            %s",
            $this->dialect->upsertFromInsertedRow(['part_code'], [
                'name', 'category', 'manufacturer', 'reference_cost', 'is_active', 'notes',
            ])
        );
        $stmtPart = $this->pdo->prepare($sqlPart);

        $sqlCompat = sprintf(
            'INSERT INTO spare_part_compatibilities (spare_part_id, machine_model)
            VALUES ((SELECT id FROM spare_parts WHERE part_code = :part_code LIMIT 1), :machine_model)
            %s',
            $this->dialect->upsertDoNothing(['spare_part_id', 'machine_model'])
        );
        $stmtCompat = $this->pdo->prepare($sqlCompat);

        $partCount = 0;
        $compatCount = 0;

        foreach ($parts as $p) {
            $stmtPart->execute([
                ':part_code' => $p['part_code'],
                ':name' => $p['name'],
                ':category' => $p['category'],
                ':manufacturer' => $p['manufacturer'],
                ':reference_cost' => $p['reference_cost'],
                ':is_active' => $p['is_active'],
                ':notes' => $p['notes'],
            ]);
            $partCount++;

            foreach ($p['models'] as $model) {
                $stmtCompat->execute([
                    ':part_code' => $p['part_code'],
                    ':machine_model' => $model,
                ]);
                $compatCount++;
            }
        }

        return ['parts' => $partCount, 'compatibilities' => $compatCount];
    }

    /**
     * Saldo demo de las furgonetas del parque sembrado.
     *
     * Se resuelve por las claves canónicas del técnico (`email_key`) y del repuesto
     * (`part_code`), nunca por identificadores que la secuencia asigna distinto en cada
     * base. La siembra incluye a propósito un saldo justo en el umbral y otro negativo:
     * la alerta y el descuadre del Módulo 18 tienen que ser demostrables en el entorno
     * sembrado, no solo dentro de una prueba.
     *
     * @return int Saldos canónicos vivos tras sembrar.
     */
    public function seedVanStocks(): int
    {
        $identityColumn = $this->dialect->requiresCanonicalIdentityColumns() ? 'email_key' : 'email';
        $insert = $this->pdo->prepare(sprintf(
            'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
             SELECT u.id, sp.id, :quantity_available, :min_threshold
               FROM users u, spare_parts sp
              WHERE u.%s = :email
                AND sp.part_code = :part_code
                AND NOT EXISTS (
                    SELECT 1 FROM technician_van_stocks vs
                     WHERE vs.technician_id = u.id
                       AND vs.spare_part_id = sp.id
                )',
            $identityColumn
        ));

        foreach (self::canonicalVanStockRows() as $row) {
            $insert->execute([
                ':quantity_available' => $row['quantity_available'],
                ':min_threshold' => $row['min_threshold'],
                ':email' => $row['email'],
                ':part_code' => $row['part_code'],
            ]);
        }

        // Se informa del saldo canónico vivo, no de las filas insertadas: igual que el
        // cuadrante territorial, resembrar no altera las cifras del resumen.
        $verify = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*)
               FROM technician_van_stocks vs
               INNER JOIN users u ON u.id = vs.technician_id
               INNER JOIN spare_parts sp ON sp.id = vs.spare_part_id
              WHERE u.%s = :email
                AND sp.part_code = :part_code',
            $identityColumn
        ));

        $count = 0;
        foreach (self::canonicalVanStockRows() as $row) {
            $verify->execute([':email' => $row['email'], ':part_code' => $row['part_code']]);
            $count += (int)$verify->fetchColumn();
        }

        return $count;
    }

    /**
     * Los cinco saldos de furgoneta del parque sembrado.
     *
     * @return list<array{email: string, part_code: string, quantity_available: int, min_threshold: int}>
     */
    private static function canonicalVanStockRows(): array
    {
        return [
            ['email' => 'jordi.ruta@vendguard.internal', 'part_code' => 'VALV-ULKA-01', 'quantity_available' => 4, 'min_threshold' => 2],
            ['email' => 'jordi.ruta@vendguard.internal', 'part_code' => 'JUNT-TOR-01', 'quantity_available' => 1, 'min_threshold' => 2],
            ['email' => 'marta.ruta@vendguard.internal', 'part_code' => 'BOMB-VIB-02', 'quantity_available' => 3, 'min_threshold' => 1],
            ['email' => 'marta.ruta@vendguard.internal', 'part_code' => 'TELEC-NAYAX-01', 'quantity_available' => -1, 'min_threshold' => 0],
            ['email' => 'carlos.ruta@vendguard.internal', 'part_code' => 'SOND-NTC-01', 'quantity_available' => 2, 'min_threshold' => 2],
        ];
    }

    /**
     * Órdenes de carga demo del Módulo 23, una por estado del ciclo.
     *
     * La orden no nace de una avería: nace de la demanda logística, de modo que
     * el arranque en limpio puede demostrar el albarán sin inventar expedientes.
     * La orden de Jordi incluye una línea retirada con baja lógica (Art. III.1) y
     * la de Marta un despacho parcial (`dispatched_qty` menor que lo solicitado).
     *
     * @return list<array<string, mixed>>
     */
    private static function canonicalVanRestockOrderRows(): array
    {
        $coordinator = 'coordinacion@vendguard.internal';

        return [
            [
                'technician_email' => 'jordi.ruta@vendguard.internal',
                'status' => 'PENDING_PICKING',
                'notes' => 'Reposición matinal: preparar en jaula 3 antes de las 08:30.',
                'created_at' => '2026-10-26 07:45:00+02',
                'dispatcher_email' => null,
                'dispatched_at' => null,
                'confirmer_email' => null,
                'confirmed_at' => null,
                'canceller_email' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'items' => [
                    ['part_code' => 'JUNT-TOR-01', 'current_quantity' => 1, 'min_threshold' => 2, 'pending_request_qty' => 0, 'requested_qty' => 2, 'dispatched_qty' => null, 'reason' => 'LOW_STOCK', 'removed_at' => null, 'remover_email' => null],
                    ['part_code' => 'SOND-NTC-01', 'current_quantity' => 0, 'min_threshold' => 1, 'pending_request_qty' => 2, 'requested_qty' => 4, 'dispatched_qty' => null, 'reason' => 'BOTH', 'removed_at' => null, 'remover_email' => null],
                    ['part_code' => 'FILT-AGUA-01', 'current_quantity' => 0, 'min_threshold' => 0, 'pending_request_qty' => 0, 'requested_qty' => 1, 'dispatched_qty' => null, 'reason' => 'MANUAL', 'removed_at' => '2026-10-26 07:50:00+02', 'remover_email' => $coordinator],
                ],
            ],
            [
                'technician_email' => 'marta.ruta@vendguard.internal',
                'status' => 'DISPATCHED',
                'notes' => 'Entregado parcialmente en el mostrador de almacén.',
                'created_at' => '2026-10-25 07:40:00+02',
                'dispatcher_email' => $coordinator,
                'dispatched_at' => '2026-10-25 08:05:00+02',
                'confirmer_email' => null,
                'confirmed_at' => null,
                'canceller_email' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'items' => [
                    ['part_code' => 'BOMB-VIB-02', 'current_quantity' => 3, 'min_threshold' => 0, 'pending_request_qty' => 4, 'requested_qty' => 1, 'dispatched_qty' => 1, 'reason' => 'PENDING_REQUEST', 'removed_at' => null, 'remover_email' => null],
                    ['part_code' => 'TELEC-NAYAX-01', 'current_quantity' => -1, 'min_threshold' => 0, 'pending_request_qty' => 1, 'requested_qty' => 3, 'dispatched_qty' => 2, 'reason' => 'BOTH', 'removed_at' => null, 'remover_email' => null],
                ],
            ],
            [
                'technician_email' => 'carlos.ruta@vendguard.internal',
                'status' => 'CONFIRMED_BY_TECH',
                'notes' => 'Carga recibida antes de iniciar la ruta del sud.',
                'created_at' => '2026-10-24 07:30:00+02',
                'dispatcher_email' => $coordinator,
                'dispatched_at' => '2026-10-24 08:00:00+02',
                'confirmer_email' => 'carlos.ruta@vendguard.internal',
                'confirmed_at' => '2026-10-24 08:20:00+02',
                'canceller_email' => null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'items' => [
                    ['part_code' => 'TERM-SEG-01', 'current_quantity' => 0, 'min_threshold' => 1, 'pending_request_qty' => 1, 'requested_qty' => 3, 'dispatched_qty' => 3, 'reason' => 'BOTH', 'removed_at' => null, 'remover_email' => null],
                    ['part_code' => 'MOT-ESP-01', 'current_quantity' => 0, 'min_threshold' => 0, 'pending_request_qty' => 1, 'requested_qty' => 1, 'dispatched_qty' => 1, 'reason' => 'PENDING_REQUEST', 'removed_at' => null, 'remover_email' => null],
                ],
            ],
            [
                'technician_email' => 'elena.ruta@vendguard.internal',
                'status' => 'CANCELLED',
                'notes' => 'Preparada la víspera de un cambio de ruta.',
                'created_at' => '2026-10-23 07:35:00+02',
                'dispatcher_email' => null,
                'dispatched_at' => null,
                'confirmer_email' => null,
                'confirmed_at' => null,
                'canceller_email' => $coordinator,
                'cancelled_at' => '2026-10-23 09:10:00+02',
                'cancellation_reason' => 'Devolución física de la caja a estantería por cambio de ruta.',
                'items' => [
                    ['part_code' => 'VALV-ULKA-01', 'current_quantity' => 0, 'min_threshold' => 0, 'pending_request_qty' => 2, 'requested_qty' => 2, 'dispatched_qty' => null, 'reason' => 'PENDING_REQUEST', 'removed_at' => null, 'remover_email' => null],
                    ['part_code' => 'DISP-LCD-01', 'current_quantity' => 0, 'min_threshold' => 0, 'pending_request_qty' => 0, 'requested_qty' => 1, 'dispatched_qty' => null, 'reason' => 'MANUAL', 'removed_at' => null, 'remover_email' => null],
                ],
            ],
        ];
    }

    /**
     * Órdenes de carga demo y sus eventos de auditoría (Módulo 23).
     *
     * Se resuelven por la clave canónica del técnico (`email_key`) y por el
     * instante de creación de la orden, que es la identidad natural de la
     * semilla: reejecutar el catálogo no duplica ni la orden ni sus líneas. La
     * línea retirada se conserva con `removed_at`/`removed_by` y jamás se borra
     * (Art. III.1); el saldo del vehículo no se toca aquí, porque una orden
     * sembrada no representa una recepción real.
     *
     * @return int Órdenes canónicas vivas tras sembrar.
     */
    public function seedVanRestockOrders(): int
    {
        $identityColumn = $this->dialect->requiresCanonicalIdentityColumns() ? 'email_key' : 'email';

        $insertOrder = $this->pdo->prepare(sprintf(
            'INSERT INTO van_restock_orders (
                technician_id, status, notes, created_by, created_at,
                dispatched_by, dispatched_at, confirmed_by, confirmed_at,
                cancelled_by, cancelled_at, cancellation_reason
             )
             SELECT technician.id, :status, :notes, creator.id, :created_at,
                    dispatcher.id, :dispatched_at, confirmer.id, :confirmed_at,
                    canceller.id, :cancelled_at, :cancellation_reason
               FROM users technician
               JOIN users creator ON creator.%1$s = :creator_email
               LEFT JOIN users dispatcher ON dispatcher.%1$s = :dispatcher_email
               LEFT JOIN users confirmer ON confirmer.%1$s = :confirmer_email
               LEFT JOIN users canceller ON canceller.%1$s = :canceller_email
              WHERE technician.%1$s = :technician_email
                AND NOT EXISTS (
                    SELECT 1 FROM van_restock_orders o
                     WHERE o.technician_id = technician.id
                       AND o.created_at = :created_at_lookup
                )',
            $identityColumn
        ));

        $findOrder = $this->pdo->prepare(sprintf(
            'SELECT o.id
               FROM van_restock_orders o
               JOIN users technician ON technician.id = o.technician_id
              WHERE technician.%s = :technician_email
                AND o.created_at = :created_at
              ORDER BY o.id ASC
              LIMIT 1',
            $identityColumn
        ));

        $insertItem = $this->pdo->prepare(sprintf(
            'INSERT INTO van_restock_order_items (
                order_id, spare_part_id,
                current_quantity_snapshot, min_threshold_snapshot, pending_request_qty_snapshot,
                requested_qty, dispatched_qty, reason,
                removed_at, removed_by, created_at
             )
             SELECT :order_id, spare_part.id, :current_quantity, :min_threshold, :pending_request_qty,
                    :requested_qty, :dispatched_qty, :reason,
                    :removed_at, remover.id, :created_at
               FROM spare_parts spare_part
               LEFT JOIN users remover ON remover.%1$s = :remover_email
              WHERE spare_part.part_code = :part_code
                AND NOT EXISTS (
                    SELECT 1 FROM van_restock_order_items i
                     WHERE i.order_id = :order_id_lookup
                       AND i.spare_part_id = spare_part.id
                )',
            $identityColumn
        ));

        $insertAudit = $this->pdo->prepare(sprintf(
            'INSERT INTO audit_log (
                entity_type, entity_id, action, user_id, user_role, user_name,
                previous_state, new_state, metadata, created_at
             )
             SELECT \'VAN_RESTOCK_ORDER\', :entity_id, :action, actor.id, :user_role, actor.name,
                    :previous_state, :new_state, :metadata, :created_at
               FROM users actor
              WHERE actor.%1$s = :actor_email
                AND NOT EXISTS (
                    SELECT 1 FROM audit_log a
                     WHERE a.entity_type = \'VAN_RESTOCK_ORDER\'
                       AND a.entity_id = :entity_id_lookup
                       AND a.action = :action_lookup
                       AND a.created_at = :created_at_lookup
                )',
            $identityColumn
        ));

        foreach (self::canonicalVanRestockOrderRows() as $order) {
            $insertOrder->execute([
                ':status' => $order['status'],
                ':notes' => $order['notes'],
                ':created_at' => $order['created_at'],
                ':dispatched_at' => $order['dispatched_at'],
                ':confirmed_at' => $order['confirmed_at'],
                ':cancelled_at' => $order['cancelled_at'],
                ':cancellation_reason' => $order['cancellation_reason'],
                ':creator_email' => 'coordinacion@vendguard.internal',
                ':dispatcher_email' => $order['dispatcher_email'],
                ':confirmer_email' => $order['confirmer_email'],
                ':canceller_email' => $order['canceller_email'],
                ':technician_email' => $order['technician_email'],
                ':created_at_lookup' => $order['created_at'],
            ]);

            $findOrder->execute([
                ':technician_email' => $order['technician_email'],
                ':created_at' => $order['created_at'],
            ]);
            $orderId = (int)$findOrder->fetchColumn();
            $findOrder->closeCursor();

            if ($orderId <= 0) {
                continue;
            }

            foreach ($order['items'] as $item) {
                $insertItem->execute([
                    ':order_id' => $orderId,
                    ':current_quantity' => $item['current_quantity'],
                    ':min_threshold' => $item['min_threshold'],
                    ':pending_request_qty' => $item['pending_request_qty'],
                    ':requested_qty' => $item['requested_qty'],
                    ':dispatched_qty' => $item['dispatched_qty'],
                    ':reason' => $item['reason'],
                    ':removed_at' => $item['removed_at'],
                    ':remover_email' => $item['remover_email'],
                    ':created_at' => $order['created_at'],
                    ':part_code' => $item['part_code'],
                    ':order_id_lookup' => $orderId,
                ]);
            }

            foreach ($this->vanRestockOrderAuditEvents($order, $orderId) as $event) {
                $insertAudit->execute([
                    ':entity_id' => $orderId,
                    ':action' => $event['action'],
                    ':user_role' => $event['user_role'],
                    ':previous_state' => $event['previous_state'],
                    ':new_state' => $event['new_state'],
                    ':metadata' => $event['metadata'],
                    ':created_at' => $event['created_at'],
                    ':actor_email' => $event['actor_email'],
                    ':entity_id_lookup' => $orderId,
                    ':action_lookup' => $event['action'],
                    ':created_at_lookup' => $event['created_at'],
                ]);
            }
        }

        $count = 0;
        foreach (self::canonicalVanRestockOrderRows() as $order) {
            $findOrder->execute([
                ':technician_email' => $order['technician_email'],
                ':created_at' => $order['created_at'],
            ]);
            $count += $findOrder->fetchColumn() !== false ? 1 : 0;
            $findOrder->closeCursor();
        }

        return $count;
    }

    /**
     * Eventos de auditoría que acompañan a una orden sembrada.
     *
     * @param array<string, mixed> $order
     * @return list<array<string, mixed>>
     */
    private function vanRestockOrderAuditEvents(array $order, int $orderId): array
    {
        $coordinator = 'coordinacion@vendguard.internal';
        $metadata = json_encode(
            ['order_code' => sprintf('RO-%06d', $orderId), 'source' => 'SEED'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $encode = static fn(array $state): string => (string)json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $events = [[
            'action' => 'CREATE_VAN_RESTOCK_ORDER',
            'actor_email' => $coordinator,
            'user_role' => 'COORDINATOR',
            'previous_state' => null,
            'new_state' => $encode(['status' => 'PENDING_PICKING', 'source' => 'SEED']),
            'metadata' => $metadata,
            'created_at' => $order['created_at'],
        ]];

        $removedAt = null;
        foreach ($order['items'] as $item) {
            if ($item['removed_at'] !== null && ($removedAt === null || $item['removed_at'] > $removedAt)) {
                $removedAt = $item['removed_at'];
            }
        }
        if ($removedAt !== null) {
            $events[] = [
                'action' => 'UPDATE_VAN_RESTOCK_ORDER',
                'actor_email' => $coordinator,
                'user_role' => 'COORDINATOR',
                'previous_state' => $encode(['status' => 'PENDING_PICKING']),
                'new_state' => $encode(['status' => 'PENDING_PICKING', 'soft_removed' => true]),
                'metadata' => $metadata,
                'created_at' => $removedAt,
            ];
        }

        if ($order['dispatched_at'] !== null) {
            $events[] = [
                'action' => 'DISPATCH_VAN_RESTOCK_ORDER',
                'actor_email' => $order['dispatcher_email'] ?? $coordinator,
                'user_role' => 'COORDINATOR',
                'previous_state' => $encode(['status' => 'PENDING_PICKING']),
                'new_state' => $encode(['status' => 'DISPATCHED']),
                'metadata' => $metadata,
                'created_at' => $order['dispatched_at'],
            ];
        }

        if ($order['confirmed_at'] !== null) {
            $events[] = [
                'action' => 'CONFIRM_VAN_RESTOCK_ORDER',
                'actor_email' => $order['confirmer_email'] ?? $order['technician_email'],
                'user_role' => 'TECHNICIAN',
                'previous_state' => $encode(['status' => 'DISPATCHED']),
                'new_state' => $encode(['status' => 'CONFIRMED_BY_TECH']),
                'metadata' => $metadata,
                'created_at' => $order['confirmed_at'],
            ];
        }

        if ($order['cancelled_at'] !== null) {
            $events[] = [
                'action' => 'CANCEL_VAN_RESTOCK_ORDER',
                'actor_email' => $order['canceller_email'] ?? $coordinator,
                'user_role' => 'COORDINATOR',
                'previous_state' => $encode(['status' => 'PENDING_PICKING']),
                'new_state' => $encode([
                    'status' => 'CANCELLED',
                    'cancellation_reason' => $order['cancellation_reason'],
                ]),
                'metadata' => $metadata,
                'created_at' => $order['cancelled_at'],
            ];
        }

        return $events;
    }

    /**
     * Mutaciones de sincronización sembradas del Módulo 24.
     *
     * Deja en el libro de idempotencia las dos ramas que la bandeja del técnico
     * necesita demostrar: una inspección preventiva completada sin cobertura y
     * aplicada al recuperar la señal, y una reinspección que llegó cuando la
     * orden ya estaba cerrada y se volcó como conflicto conservando el desfase
     * de reloj del dispositivo. Se resuelven por la clave canónica del técnico y
     * por `order_code`, nunca por identificadores que la secuencia asigna
     * distinto en cada base; reejecutar el catálogo no duplica ninguna fila.
     *
     * La auditoría de la mutación aplicada reutiliza la acción ya catalogada
     * `EVALUATE_PREVENTIVE_CHECKLIST`. El volcado en conflicto estrena su propia
     * acción con el repositorio del módulo y su etiqueta en el visor, así que
     * sembrarla ahora dejaría `audit_log` con una acción que coordinación no
     * sabría nombrar (el guardián del vocabulario lo rechaza).
     *
     * @return int Mutaciones canónicas vivas tras sembrar.
     */
    public function seedOfflineSyncMutations(): int
    {
        $identityColumn = $this->dialect->requiresCanonicalIdentityColumns() ? 'email_key' : 'email';

        $insertMutation = $this->pdo->prepare(sprintf(
            'INSERT INTO offline_sync_mutations (
                technician_id, client_mutation_id, stop_type, stop_id, operation_type,
                outcome_status, client_occurred_at, chain_started_at, effective_occurred_at,
                synced_at, clock_skew_adjusted, sanitary_seal_confirmed,
                response_http_status, response_payload
             )
             SELECT technician.id, :client_mutation_id, \'PREVENTIVE_ORDER\', preventive.id,
                    :operation_type, :outcome_status, :client_occurred_at,
                    :chain_started_at, :effective_occurred_at, :synced_at,
                    CAST(:clock_skew_adjusted AS BOOLEAN),
                    CAST(:sanitary_seal_confirmed AS BOOLEAN),
                    :response_http_status, CAST(:response_payload AS JSONB)
               FROM users technician
               JOIN preventive_orders preventive ON preventive.order_code = :order_code
              WHERE technician.%1$s = :technician_email
                AND NOT EXISTS (
                    SELECT 1 FROM offline_sync_mutations m
                     WHERE m.technician_id = technician.id
                       AND m.client_mutation_id = :client_mutation_id_lookup
                )',
            $identityColumn
        ));

        $insertAudit = $this->pdo->prepare(
            'INSERT INTO audit_log (
                entity_type, entity_id, action, user_id, user_role, user_name,
                previous_state, new_state, metadata, created_at
             )
             SELECT \'PREVENTIVE_ORDER\', m.stop_id, \'EVALUATE_PREVENTIVE_CHECKLIST\',
                    m.technician_id, \'TECHNICIAN\', technician.name,
                    :previous_state, :new_state, :metadata, m.synced_at
               FROM offline_sync_mutations m
               JOIN users technician ON technician.id = m.technician_id
              WHERE m.stop_type = \'PREVENTIVE_ORDER\'
                AND m.client_mutation_id = :client_mutation_id
                AND NOT EXISTS (
                    SELECT 1 FROM audit_log a
                     WHERE a.entity_type = \'PREVENTIVE_ORDER\'
                       AND a.entity_id = m.stop_id
                       AND a.action = \'EVALUATE_PREVENTIVE_CHECKLIST\'
                       AND a.created_at = m.synced_at
                )'
        );

        $now = new \DateTimeImmutable('now');
        $seeded = 0;

        foreach (self::canonicalOfflineSyncMutationRows() as $row) {
            $syncedAt = $now->modify(sprintf('%+d seconds', -$row['sync_delay_seconds']));
            $clientOccurredAt = $syncedAt->modify(sprintf('%+d seconds', $row['client_offset_seconds']));
            $chainStartedAt = $clientOccurredAt->modify(sprintf('%+d seconds', $row['chain_offset_seconds']));
            $effectiveOccurredAt = $row['clock_skew_adjusted'] ? $syncedAt : $clientOccurredAt;

            $insertMutation->execute([
                ':client_mutation_id' => $row['client_mutation_id'],
                ':client_mutation_id_lookup' => $row['client_mutation_id'],
                ':operation_type' => $row['operation_type'],
                ':outcome_status' => $row['outcome_status'],
                ':client_occurred_at' => $clientOccurredAt->format('Y-m-d H:i:sP'),
                ':chain_started_at' => $chainStartedAt->format('Y-m-d H:i:sP'),
                ':effective_occurred_at' => $effectiveOccurredAt->format('Y-m-d H:i:sP'),
                ':synced_at' => $syncedAt->format('Y-m-d H:i:sP'),
                ':clock_skew_adjusted' => $row['clock_skew_adjusted'] ? 'true' : 'false',
                ':sanitary_seal_confirmed' => $row['sanitary_seal_confirmed'] ? 'true' : 'false',
                ':response_http_status' => $row['response_http_status'],
                ':response_payload' => (string)json_encode(
                    $row['response_payload'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                ':order_code' => $row['order_code'],
                ':technician_email' => $row['technician_email'],
            ]);

            $seeded += $insertMutation->rowCount();

            if ($row['outcome_status'] !== 'APPLIED') {
                continue;
            }

            $insertAudit->execute([
                ':previous_state' => (string)json_encode(['status' => 'IN_PROGRESS'], JSON_UNESCAPED_UNICODE),
                ':new_state' => (string)json_encode(
                    ['status' => 'COMPLETED', 'result' => 'CONFORME'],
                    JSON_UNESCAPED_UNICODE
                ),
                ':metadata' => (string)json_encode([
                    'source' => 'SEED',
                    'offline_sync' => [
                        'client_mutation_id' => $row['client_mutation_id'],
                        'operation_type' => $row['operation_type'],
                        'client_occurred_at' => $clientOccurredAt->format('Y-m-d H:i:sP'),
                        'effective_occurred_at' => $effectiveOccurredAt->format('Y-m-d H:i:sP'),
                        'synced_at' => $syncedAt->format('Y-m-d H:i:sP'),
                        'clock_skew_adjusted' => $row['clock_skew_adjusted'],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':client_mutation_id' => $row['client_mutation_id'],
            ]);
        }

        return $seeded;
    }

    /**
     * Las dos mutaciones canónicas del Módulo 24.
     *
     * `sync_delay_seconds` sitúa la sincronización en el pasado; los desplazamientos
     * de cliente y cadena reproducen el reloj desajustado del segundo caso, que es
     * justo el que el servidor corrige preservando la duración relativa (QA 4).
     *
     * @return list<array<string, mixed>>
     */
    private static function canonicalOfflineSyncMutationRows(): array
    {
        return [
            [
                'technician_email' => 'jordi.ruta@vendguard.internal',
                'order_code' => 'ORD-PREV-2026-0001',
                'client_mutation_id' => 'seed-offline-20261026-0001',
                'operation_type' => 'COMPLETE_PREVENTIVE',
                'outcome_status' => 'APPLIED',
                'sync_delay_seconds' => 86400,
                'client_offset_seconds' => 0,
                'chain_offset_seconds' => -1500,
                'clock_skew_adjusted' => false,
                'sanitary_seal_confirmed' => false,
                'response_http_status' => 200,
                'response_payload' => [
                    'status' => 'COMPLETED',
                    'result' => 'CONFORME',
                    'idempotent_replay' => false,
                    'source' => 'SEED',
                ],
            ],
            [
                'technician_email' => 'jordi.ruta@vendguard.internal',
                'order_code' => 'ORD-PREV-2026-0002',
                'client_mutation_id' => 'seed-offline-20261026-0002',
                'operation_type' => 'REINSPECT_PREVENTIVE',
                'outcome_status' => 'CONFLICT_DUMPED',
                'sync_delay_seconds' => 172800,
                'client_offset_seconds' => -86400,
                'chain_offset_seconds' => -1500,
                'clock_skew_adjusted' => true,
                'sanitary_seal_confirmed' => false,
                'response_http_status' => 409,
                'response_payload' => [
                    'error' => 'OFFLINE_MUTATION_CONFLICT',
                    'conflict_reason' => 'La orden preventiva ya constaba como completada en el servidor al recuperar la cobertura.',
                    'work_summary' => ['checklist_completed' => true, 'evidence_photos' => 1],
                    'source' => 'SEED',
                ],
            ],
        ];
    }

    /**
     * Ejecuta todas las semillas dentro de una transacción.
     *
     * @param string $defaultPassword
     * @return array<string, int> Resumen de filas procesadas.
     */
    public function seedAll(string $defaultPassword = 'Password123!'): array
    {
        $this->pdo->beginTransaction();

        try {
            $locCount = $this->seedLocations();
            $this->seedRouteSettings();
            $machCount = $this->seedMachines();
            $userCount = $this->seedUsers($defaultPassword);
            // El catálogo territorial entra en el mismo sembrador que el resto del
            // parque (hallazgo H-2): zonas, códigos postales, cuadrante y la herencia
            // de cada sede. Sin esto, la batería dejaba el territorio vacío.
            $zoneCount = $this->seedOperationalZones();
            $zonePostalCodeCount = $this->seedZonePostalCodes();
            $zoneTechnicianCount = $this->seedTechnicianZoneAssignments();
            $this->seedTerritorialInheritance();
            $prevCount = $this->seedPreventiveSettings();
            // El catálogo de taxonomía (Módulo 25) no depende de otras maestras: se
            // siembra antes de las órdenes preventivas para que cualquier suite que
            // resuelva una avería encuentre causa y solución.
            $taxonomyCounts = $this->seedRootCauseTaxonomy();
            $this->seedPreventiveOrdersAndCertificates();
            $checklistOrders = $this->seedPreventiveChecklistItems();
            $auditEvents = $this->seedPreventiveAuditTrail();
            $partsRes = $this->seedSpareParts();
            // El saldo de furgoneta se siembra después del catálogo maestro: lo resuelve
            // por `part_code` y por la clave canónica del técnico (Módulo 18).
            $vanStockCount = $this->seedVanStocks();
            // Las órdenes de carga demo (Módulo 23) cuelgan de técnico y repuesto,
            // así que entran cuando ambas maestras ya están sembradas.
            $vanRestockOrderCount = $this->seedVanRestockOrders();
            // El libro de idempotencia del Módulo 24 se apoya en las órdenes
            // preventivas ya sembradas: sin ellas no hay parada a la que apuntar.
            $offlineSyncMutationCount = $this->seedOfflineSyncMutations();

            $this->pdo->commit();

            return [
                'locations' => $locCount,
                'machines' => $machCount,
                'users' => $userCount,
                'operational_zones' => $zoneCount,
                'zone_postal_codes' => $zonePostalCodeCount,
                'technician_zone_assignments' => $zoneTechnicianCount,
                'preventive_settings' => $prevCount,
                'root_causes' => $taxonomyCounts['root_causes'],
                'standard_solutions' => $taxonomyCounts['standard_solutions'],
                'preventive_checklists' => $checklistOrders,
                'preventive_audit_events' => $auditEvents,
                'spare_parts' => $partsRes['parts'],
                'spare_part_compatibilities' => $partsRes['compatibilities'],
                'technician_van_stocks' => $vanStockCount,
                'van_restock_orders' => $vanRestockOrderCount,
                'offline_sync_mutations' => $offlineSyncMutationCount,
            ];
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Error en transacción de semillas: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Catálogo canónico de sedes del parque.
     *
     * Es la ÚNICA declaración de las sedes que el sistema considera de negocio: la
     * siembra las escribe y el criterio de residuo del arnés las lee de aquí
     * (`canonicalIdentities()`). Una lista paralela en el limpiador sería una segunda
     * verdad que se degradaría en silencio en cuanto una sede nueva entrara al catálogo.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Las tres demarcaciones operativas del parque sembrado.
     *
     * @return list<array{code: string, name: string, color_hex: string, description: string}>
     */
    private static function canonicalOperationalZoneRows(): array
    {
        return [
            [
                'code' => 'ZONA-BCN-NORTE',
                'name' => 'Barcelona Litoral y Norte',
                'color_hex' => '#2560ff',
                'description' => 'Sant Martí, Poblenou, Nou Barris y Badalona',
            ],
            [
                'code' => 'ZONA-BCN-CENTRO',
                'name' => 'Barcelona Centro y Litoral',
                'color_hex' => '#10b981',
                'description' => 'Ciutat Vella, Eixample y Campus Nord (Les Corts)',
            ],
            [
                'code' => 'ZONA-BCN-SUR',
                'name' => 'Barcelona Sur y Vallès',
                'color_hex' => '#f59e0b',
                'description' => 'Bellvitge, Fira Gran Via, Cornellà y Sant Cugat',
            ],
        ];
    }

    /**
     * Los diez códigos postales de las sedes sembradas, cada uno en una sola zona.
     *
     * @return list<array{postal_code: string, zone_code: string}>
     */
    private static function canonicalZonePostalCodeRows(): array
    {
        return [
            ['postal_code' => '08018', 'zone_code' => 'ZONA-BCN-NORTE'],
            ['postal_code' => '08042', 'zone_code' => 'ZONA-BCN-NORTE'],
            ['postal_code' => '08916', 'zone_code' => 'ZONA-BCN-NORTE'],
            ['postal_code' => '08003', 'zone_code' => 'ZONA-BCN-CENTRO'],
            ['postal_code' => '08039', 'zone_code' => 'ZONA-BCN-CENTRO'],
            ['postal_code' => '08034', 'zone_code' => 'ZONA-BCN-CENTRO'],
            ['postal_code' => '08907', 'zone_code' => 'ZONA-BCN-SUR'],
            ['postal_code' => '08908', 'zone_code' => 'ZONA-BCN-SUR'],
            ['postal_code' => '08940', 'zone_code' => 'ZONA-BCN-SUR'],
            ['postal_code' => '08195', 'zone_code' => 'ZONA-BCN-SUR'],
        ];
    }

    /**
     * Cuadrante demo: un titular por zona y las suplencias repartidas.
     *
     * @return list<array{email: string, zone_code: string, is_primary: bool}>
     */
    private static function canonicalTechnicianZoneRows(): array
    {
        return [
            ['email' => 'elena.ruta@vendguard.internal', 'zone_code' => 'ZONA-BCN-NORTE', 'is_primary' => true],
            ['email' => 'marta.ruta@vendguard.internal', 'zone_code' => 'ZONA-BCN-CENTRO', 'is_primary' => true],
            ['email' => 'carlos.ruta@vendguard.internal', 'zone_code' => 'ZONA-BCN-SUR', 'is_primary' => true],
            ['email' => 'jordi.ruta@vendguard.internal', 'zone_code' => 'ZONA-BCN-NORTE', 'is_primary' => false],
            ['email' => 'jordi.ruta@vendguard.internal', 'zone_code' => 'ZONA-BCN-CENTRO', 'is_primary' => false],
            ['email' => 'marc.ruta@vendguard.internal', 'zone_code' => 'ZONA-BCN-SUR', 'is_primary' => false],
            ['email' => 'marc.ruta@vendguard.internal', 'zone_code' => 'ZONA-BCN-NORTE', 'is_primary' => false],
        ];
    }

    private static function canonicalLocationRows(): array
    {
        return [
            [
                'site_code' => 'SEDE-BCN-01',
                'name' => 'Hospital del Mar - Edificio Central',
                'address' => 'Passeig Marítim 25, Barcelona',
                'latitude' => 41.3853120,
                'longitude' => 2.1932450,
                'contact_name' => 'Laura Sanitaria',
                'contact_phone' => '600111222',
                'postal_code' => '08003',
            ],
            [
                'site_code' => 'SEDE-BCN-02',
                'name' => 'Torre Glòries - Planta 4 Oficinas',
                'address' => 'Avinguda Diagonal 211, Barcelona',
                'latitude' => 41.4036290,
                'longitude' => 2.1895120,
                'contact_name' => 'Marc Recepción',
                'contact_phone' => '600333444',
                'postal_code' => '08018',
            ],
            [
                'site_code' => 'SEDE-BCN-03',
                'name' => 'Hospital Universitari de Bellvitge',
                'address' => 'Carrer de la Feixa Llarga s/n, L\'Hospitalet de Llobregat',
                'latitude' => 41.3458200,
                'longitude' => 2.1075400,
                'contact_name' => 'Carles Coordinador',
                'contact_phone' => '600555666',
                'postal_code' => '08907',
            ],
            [
                'site_code' => 'SEDE-BCN-04',
                'name' => 'World Trade Center Barcelona',
                'address' => 'Moll de Barcelona s/n, Barcelona',
                'latitude' => 41.3725000,
                'longitude' => 2.1819000,
                'contact_name' => 'Núria Port',
                'contact_phone' => '600777888',
                'postal_code' => '08039',
            ],
            [
                'site_code' => 'SEDE-BCN-05',
                'name' => 'Campus Nord UPC - Edifici Nexus',
                'address' => 'Carrer del Gran Capità 2, Barcelona',
                'latitude' => 41.3888000,
                'longitude' => 2.1123000,
                'contact_name' => 'Albert Campus',
                'contact_phone' => '600999000',
                'postal_code' => '08034',
            ],
            [
                'site_code' => 'SEDE-BCN-06',
                'name' => 'Parc Tecnològic Barcelona Activa',
                'address' => 'Carrer Marie Curie 8, Nou Barris, Barcelona',
                'latitude' => 41.4392000,
                'longitude' => 2.1758000,
                'contact_name' => 'Clara Innovació',
                'contact_phone' => '611222333',
                'postal_code' => '08042',
            ],
            [
                'site_code' => 'SEDE-BCN-07',
                'name' => 'Badalona Centre Mèdic Can Ruti',
                'address' => 'Carretera de Canyet s/n, Badalona',
                'latitude' => 41.4645000,
                'longitude' => 2.2421000,
                'contact_name' => 'Sergi Logística',
                'contact_phone' => '611444555',
                'postal_code' => '08916',
            ],
            [
                'site_code' => 'SEDE-BCN-08',
                'name' => 'Sant Cugat Trade Center',
                'address' => 'Avinguda de les Corts Catalanes 5, Sant Cugat del Vallès',
                'latitude' => 41.4712000,
                'longitude' => 2.0689000,
                'contact_name' => 'Gemma Gestió',
                'contact_phone' => '611666777',
                'postal_code' => '08195',
            ],
            [
                'site_code' => 'SEDE-BCN-09',
                'name' => 'Fira Gran Via - Pavelló 1',
                'address' => 'Avinguda Joan Carles I 64, L\'Hospitalet de Llobregat',
                'latitude' => 41.3547000,
                'longitude' => 2.1284000,
                'contact_name' => 'Pau Esdeveniments',
                'contact_phone' => '611888999',
                'postal_code' => '08908',
            ],
            [
                'site_code' => 'SEDE-BCN-10',
                'name' => 'WTC Almeda Park Cornellà',
                'address' => 'Plaça de la Pau s/n, Cornellà de Llobregat',
                'latitude' => 41.3533000,
                'longitude' => 2.0862000,
                'contact_name' => 'Mireia Serveis',
                'contact_phone' => '622111222',
                'postal_code' => '08940',
            ],
        ];
    }

    /**
     * Catálogo canónico de máquinas del parque, ligadas a su sede por `site_code`.
     *
     * La identidad de una máquina es su `code`: es lo que el contrato de limpieza usa
     * para distinguir una máquina del parque sembrado de una que creó una suite.
     *
     * @return list<array<string, mixed>>
     */
    private static function canonicalMachineRows(): array
    {
        return [
            [
                'site_code' => 'SEDE-BCN-01',
                'code' => 'VEND-0101',
                'model' => 'Sanden Vendo G-Drink',
                'machine_type' => 'PERISHABLE_FOOD',
                'floor_wing' => 'Planta Baja - Urgencias',
                'notes' => 'Máquina de sándwiches y lácteos frescos',
            ],
            [
                'site_code' => 'SEDE-BCN-01',
                'code' => 'VEND-0102',
                'model' => 'Bianchi Gaia Espresso',
                'machine_type' => 'HOT_DRINKS',
                'floor_wing' => 'Planta 1 - Sala Médica',
                'notes' => 'Café en grano y bebidas calientes',
            ],
            [
                'site_code' => 'SEDE-BCN-02',
                'code' => 'VEND-0201',
                'model' => 'Necta Samba Combo',
                'machine_type' => 'COMBO',
                'floor_wing' => 'Planta 4 - Office Este',
                'notes' => 'Snacks y refrescos variados',
            ],
            [
                'site_code' => 'SEDE-BCN-03',
                'code' => 'VEND-0301',
                'model' => 'Sanden Vendo G-Drink',
                'machine_type' => 'PERISHABLE_FOOD',
                'floor_wing' => 'Edificio Principal - Hall Consultas',
                'notes' => 'Comida fresca y ensaladas preparadas',
            ],
            [
                'site_code' => 'SEDE-BCN-03',
                'code' => 'VEND-0302',
                'model' => 'Bianchi Gaia Espresso',
                'machine_type' => 'HOT_DRINKS',
                'floor_wing' => 'Planta 2 - Sala Descanso Personal',
                'notes' => 'Café de especialidad e infusiones',
            ],
            [
                'site_code' => 'SEDE-BCN-04',
                'code' => 'VEND-0401',
                'model' => 'Necta Samba Combo',
                'machine_type' => 'COMBO',
                'floor_wing' => 'Edifici Est - Planta Baixa Lobby',
                'notes' => 'Bebidas isotónicas y aperitivos saludables',
            ],
            [
                'site_code' => 'SEDE-BCN-05',
                'code' => 'VEND-0501',
                'model' => 'Fas Fast 900',
                'machine_type' => 'SNACKS',
                'floor_wing' => 'Edifici Nexus I - Entrada Estudiants',
                'notes' => 'Aperitivos, frutos secos y barritas energéticas',
            ],
            [
                'site_code' => 'SEDE-BCN-05',
                'code' => 'VEND-0502',
                'model' => 'Fas Perla',
                'machine_type' => 'HOT_DRINKS',
                'floor_wing' => 'Edifici Nexus I - Sala Professorat',
                'notes' => 'Café largo, cortado y chocolate',
            ],
            [
                'site_code' => 'SEDE-BCN-06',
                'code' => 'VEND-0601',
                'model' => 'Azkoyen Palma+',
                'machine_type' => 'COLD_DRINKS',
                'floor_wing' => 'Coworking Principal - Zona Cafeteria',
                'notes' => 'Aguas minerales, zumos y refrescos en lata/botella',
            ],
            [
                'site_code' => 'SEDE-BCN-07',
                'code' => 'VEND-0701',
                'model' => 'Sanden Vendo G-Drink',
                'machine_type' => 'PERISHABLE_FOOD',
                'floor_wing' => 'Planta 0 - Accés Visitants',
                'notes' => 'Sandwiches envasados y yogures refrigerados',
            ],
            [
                'site_code' => 'SEDE-BCN-08',
                'code' => 'VEND-0801',
                'model' => 'Necta Samba Combo',
                'machine_type' => 'COMBO',
                'floor_wing' => 'Atri Central - Planta Baixa',
                'notes' => 'Bebidas frías y aperitivos mixtos',
            ],
            [
                'site_code' => 'SEDE-BCN-09',
                'code' => 'VEND-0901',
                'model' => 'Azkoyen Palma+',
                'machine_type' => 'COLD_DRINKS',
                'floor_wing' => 'Pavelló 1 - Porta Nord',
                'notes' => 'Bebidas frías de alta rotación para ferias',
            ],
            [
                'site_code' => 'SEDE-BCN-10',
                'code' => 'VEND-1001',
                'model' => 'Bianchi Gaia Espresso',
                'machine_type' => 'HOT_DRINKS',
                'floor_wing' => 'Planta 1 - Mòdul B Corporatiu',
                'notes' => 'Servicio de café continuo para oficinas',
            ],
        ];
    }

    /**
     * Catálogo canónico de cuentas del parque (coordinación y técnicos de ruta).
     *
     * El hash entra por parámetro —y por defecto vacío— porque leer la identidad del
     * catálogo (su `email`) no debe costar un bcrypt: quien siembra pasa el hash real,
     * quien sólo enumera identidades no lo calcula.
     *
     * @return list<array<string, mixed>>
     */
    private static function canonicalUserRows(string $passwordHash = ''): array
    {
        return [
            [
                'name' => 'Sara Coordinadora',
                'email' => 'coordinacion@vendguard.internal',
                'password_hash' => $passwordHash,
                'role' => 'COORDINATOR',
                'operator_code' => null,
                'phone' => '677000111',
            ],
            [
                'name' => 'Jordi Técnico Ruta BCN',
                'email' => 'jordi.ruta@vendguard.internal',
                'password_hash' => $passwordHash,
                'role' => 'TECHNICIAN',
                'operator_code' => 'OP-01',
                'phone' => '677222333',
            ],
            [
                'name' => 'Marta Técnica Ruta BCN',
                'email' => 'marta.ruta@vendguard.internal',
                'password_hash' => $passwordHash,
                'role' => 'TECHNICIAN',
                'operator_code' => 'OP-02',
                'phone' => '677444555',
            ],
            [
                'name' => 'Carlos Técnico Ruta Sud',
                'email' => 'carlos.ruta@vendguard.internal',
                'password_hash' => $passwordHash,
                'role' => 'TECHNICIAN',
                'operator_code' => 'OP-03',
                'phone' => '677666777',
            ],
            [
                'name' => 'Elena Técnica Ruta Nord',
                'email' => 'elena.ruta@vendguard.internal',
                'password_hash' => $passwordHash,
                'role' => 'TECHNICIAN',
                'operator_code' => 'OP-04',
                'phone' => '677888999',
            ],
            [
                'name' => 'Marc Técnico Express BCN',
                'email' => 'marc.ruta@vendguard.internal',
                'password_hash' => $passwordHash,
                'role' => 'TECHNICIAN',
                'operator_code' => 'OP-05',
                'phone' => '677112233',
            ],
        ];
    }

    /**
     * Identidad del catálogo canónico del parque, por tabla maestra.
     *
     * Devuelve, para cada maestra que el sembrador posee, la columna que la
     * identifica y los valores canónicos: `tabla => [columna => list<string>]`.
     *
     * El contrato de limpieza del arnés lo usa como criterio de pertenencia:
     * todo lo que está en una maestra y NO aparece aquí lo creó una suite —o
     * quedó de una corrida muerta—, y la restitución del parque lo drena con su
     * subárbol. Se deriva de los mismos literales de siembra, así que no puede
     * desviarse del parque que el sembrador escribe.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function canonicalIdentities(): array
    {
        return [
            'locations' => ['site_code' => array_column(self::canonicalLocationRows(), 'site_code')],
            'machines' => ['code' => array_column(self::canonicalMachineRows(), 'code')],
            'users' => ['email' => array_column(self::canonicalUserRows(), 'email')],
        ];
    }

    /**
     * Sede canónica de cada máquina del parque: `code => site_code`.
     *
     * La identidad dice QUÉ es del catálogo; este mapa dice DÓNDE está. Lo necesita la
     * restitución del arnés: una máquina del catálogo que una suite reubicó en una sede de
     * pruebas no se reconstruye adivinando, se devuelve a la sede que declara el propio
     * sembrador. El mapa sale de los mismos literales que la siembra, así que no puede
     * desviarse del parque que `seedMachines()` escribe.
     *
     * @return array<string, string>
     */
    public static function canonicalMachineSites(): array
    {
        $sites = [];
        foreach (self::canonicalMachineRows() as $machine) {
            $sites[(string)$machine['code']] = (string)$machine['site_code'];
        }

        return $sites;
    }
}