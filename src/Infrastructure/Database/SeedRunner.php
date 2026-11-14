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
            'contact_name', 'contact_phone', 'postal_code', 'sla_tier', 'is_active',
        ];
        $placeholders = [
            ':site_code', ':name', ':address', ':latitude', ':longitude',
            ':contact_name', ':contact_phone', ':postal_code', ':sla_tier', $this->dialect->booleanLiteral(true),
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
            // El perfil contractual se reafirma como el resto de la ficha: una sede
            // que cambia de contrato no conserva el nivel anterior (C1, QA 4).
            'sla_tier' => SqlDialectAdapter::PROPOSED_VALUE,
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
                ':sla_tier' => $loc['sla_tier'],
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
    /**
     * Reconstruye la cadena de custodia de los expedientes que aún no tienen tramos.
     *
     * POR QUÉ EXISTE
     *   La cadena de custodia (Módulo 26) no es un catálogo que se siembre con
     *   literales: nace de las transiciones reales de cada avería. Pero un banco de
     *   pruebas —o un despliegue que reejecuta el arranque— puede tener expedientes
     *   anteriores al módulo sin ningún tramo, y entonces la ficha de Coordinación
     *   mostraría una cadena vacía donde hubo trabajo real (EARS 05.3).
     *
     * QUÉ HACE Y QUÉ NO
     *   Inserta un tramo base por cada incidencia con técnico asignado que carezca
     *   de tramos, con los valores de respaldo deterministas de QA 5 (`SIN-CODIGO`,
     *   `Coordinación / Sistema`, `Registro histórico previo`), y sella el tramo si
     *   el expediente ya terminó, aplicando `GREATEST(assigned_at, desenlace)` para
     *   que ningún fin quede antes de su inicio (QA 4). No toca un histórico ya
     *   escrito: el `NOT EXISTS` es la idempotencia y, además, los disparadores del
     *   motor rechazarían cualquier reescritura (Art. III.3).
     *
     * Sobre un parque sin incidencias devuelve `0` y no inventa nada: el arranque en
     * limpio es estricto (EARS-02.1).
     *
     * @return int Tramos insertados en esta pasada.
     */
    public function seedIncidentAssignments(): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO incident_assignments (
                incident_id, technician_id, technician_name_snapshot, technician_code_snapshot,
                assigned_by, assigned_by_name_snapshot, assignment_source, assigned_at,
                unassigned_by, unassigned_by_name_snapshot, unassignment_reason_type,
                reassignment_reason, unassigned_at, created_at
             )
             SELECT i.id, u.id, u.name,
                    COALESCE(NULLIF(btrim(u.operator_code), ''), 'SIN-CODIGO'),
                    NULL, 'Coordinación / Sistema',
                    CAST('COORDINATOR_ASSIGN' AS incident_assignment_source),
                    COALESCE(i.assigned_at, i.created_at),
                    NULL,
                    CASE
                        WHEN i.status IN ('RESOLVED', 'CLOSED', 'CANCELLED')
                        THEN 'Coordinación / Sistema'
                    END,
                    CASE
                        WHEN i.status = 'CANCELLED'
                        THEN CAST('CANCELLED' AS incident_unassignment_reason_type)
                        WHEN i.status IN ('RESOLVED', 'CLOSED')
                        THEN CAST('RESOLVED' AS incident_unassignment_reason_type)
                    END,
                    CASE
                        WHEN i.status = 'CANCELLED' THEN left(
                            COALESCE(NULLIF(btrim(i.cancellation_reason), ''), 'Registro histórico previo'),
                            1000
                        )
                        WHEN i.status IN ('RESOLVED', 'CLOSED') THEN left(
                            COALESCE(NULLIF(btrim(i.resolution_diagnosis), ''), 'Registro histórico previo'),
                            1000
                        )
                    END,
                    CASE
                        WHEN i.status IN ('RESOLVED', 'CLOSED', 'CANCELLED') THEN GREATEST(
                            COALESCE(i.assigned_at, i.created_at),
                            COALESCE(i.resolved_at, i.cancelled_at, i.closed_at, i.created_at)
                        )
                    END,
                    COALESCE(i.assigned_at, i.created_at)
               FROM incidents i
               JOIN users u ON u.id = i.assigned_technician_id
              WHERE i.assigned_technician_id IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM incident_assignments a WHERE a.incident_id = i.id
                )"
        );

        $statement->execute();

        return $statement->rowCount();
    }
    /**
     * Bandeja de taller central (Módulo 27): sustituciones demo repartidas por el ciclo.
     *
     * POR QUÉ EL SEMBRADOR CREA LAS SUSTITUCIONES
     *   `workshop_repair_items` no cuelga del catálogo maestro sino de una línea de
     *   `incident_replaced_parts`, y el arranque en limpio no inventa incidencias
     *   (EARS-02.1). Sin una sustitución previa no hay expediente que sembrar, así que
     *   este método crea las líneas demo sobre la orden preventiva ya sembrada
     *   (`ORD-PREV-2026-0001`, intervención `PREVENTIVE`) y las reparte por los cinco
     *   estados del ciclo (D1): pendiente de entrega, recepcionada, en banco,
     *   reacondicionada —hacia almacén central y hacia furgoneta— y baja definitiva,
     *   tanto por fallo en banco como por extravío previo a la recepción (C1).
     *
     * POR QUÉ UNA LÍNEA LLEVA DOS UNIDADES
     *   El desglose `1..N` solo se demuestra con una línea de cantidad mayor que uno:
     *   la primera unidad sigue pendiente de entrega y la segunda ya volvió al
     *   almacén, de modo que el sembrador certifica que dos unidades de la misma
     *   extracción son dos expedientes y no una fila con la cantidad agregada.
     *
     * IDEMPOTENCIA Y TRAZABILIDAD
     *   La línea se reconoce por (orden preventiva, repuesto, instante de extracción) y
     *   la unidad por (línea, `unit_index`). Reejecutar el sembrador no duplica filas,
     *   no reescribe un dictamen terminal y no toca un expediente que ya exista: el
     *   `NOT EXISTS` es la idempotencia y los disparadores del motor rechazarían
     *   cualquier reescritura del histórico (Art. III.3).
     *
     * @return int Unidades insertadas en esta pasada.
     */
    public function seedWorkshopRepairItems(): int
    {
        $identityColumn = $this->dialect->requiresCanonicalIdentityColumns() ? 'email_key' : 'email';
        $coordinatorEmail = 'coordinacion@vendguard.internal';
        $technicianEmail = 'jordi.ruta@vendguard.internal';
        $orderCode = 'ORD-PREV-2026-0001';

        $orderStmt = $this->pdo->prepare(
            "SELECT id, machine_id, location_id,
                    COALESCE(assigned_technician_id, (SELECT id FROM users WHERE {$identityColumn} = :fallback_technician)) AS technician_id
               FROM preventive_orders
              WHERE order_code = :order_code
              LIMIT 1"
        );
        $orderStmt->execute([
            ':order_code' => $orderCode,
            ':fallback_technician' => $technicianEmail,
        ]);
        $order = $orderStmt->fetch();
        $orderStmt->closeCursor();

        if (!is_array($order) || $order['technician_id'] === null) {
            // Sin orden preventiva ni técnico no hay sustitución que colgar: el
            // arranque en limpio devuelve 0 y no inventa averías (EARS-02.1).
            return 0;
        }

        $coordinatorStmt = $this->pdo->prepare(
            "SELECT id FROM users WHERE {$identityColumn} = :coordinator_email LIMIT 1"
        );
        $coordinatorStmt->execute([':coordinator_email' => $coordinatorEmail]);
        $coordinatorId = (int)$coordinatorStmt->fetchColumn();
        $coordinatorStmt->closeCursor();

        $insertPart = $this->pdo->prepare(
            "INSERT INTO incident_replaced_parts (
                intervention_type, preventive_order_id, machine_id, location_id,
                technician_id, spare_part_id, quantity, unit_cost_snapshot,
                old_part_destination, notes, installed_at
             )
             SELECT 'PREVENTIVE', po.id, po.machine_id, po.location_id,
                    :technician_id, spare_part.id, :quantity, :unit_cost,
                    'TALLER', :notes, :installed_at
               FROM preventive_orders po
               JOIN spare_parts spare_part ON spare_part.part_code = :part_code
              WHERE po.order_code = :order_code
                AND NOT EXISTS (
                    SELECT 1 FROM incident_replaced_parts p
                     WHERE p.preventive_order_id = po.id
                       AND p.spare_part_id = spare_part.id
                       AND p.installed_at = :installed_at_lookup
                )"
        );

        $findPart = $this->pdo->prepare(
            "SELECT p.id
               FROM incident_replaced_parts p
               JOIN preventive_orders po ON po.id = p.preventive_order_id
               JOIN spare_parts sp ON sp.id = p.spare_part_id
              WHERE po.order_code = :order_code
                AND sp.part_code = :part_code
                AND p.installed_at = :installed_at
              ORDER BY p.id ASC
              LIMIT 1"
        );

        $insertUnit = $this->pdo->prepare(
            "INSERT INTO workshop_repair_items (
                incident_replaced_part_id, unit_index, total_units_in_line,
                intervention_type, preventive_order_id, machine_id, location_id,
                extracted_by_technician_id, spare_part_id, is_out_of_catalog,
                part_code_snapshot, part_name_snapshot, part_category_snapshot,
                machine_code_snapshot, machine_model_snapshot, location_name_snapshot,
                technician_name_snapshot, unit_cost_snapshot, status,
                received_at, received_by_user_id, reception_notes,
                bench_started_at, bench_diagnosis,
                refurbishment_cost_eur, net_savings_eur,
                restock_destination, restocked_to_technician_id,
                resolved_at, resolved_by_user_id, created_at
             )
             SELECT p.id, :unit_index, :total_units,
                    p.intervention_type, p.preventive_order_id, p.machine_id, p.location_id,
                    p.technician_id, p.spare_part_id, p.is_out_of_catalog,
                    sp.part_code,
                    COALESCE(NULLIF(btrim(sp.name), ''), 'Pieza extraída sin catálogo'),
                    COALESCE(sp.category::text, 'OTHER'),
                    m.code, m.model, l.name, u.name, p.unit_cost_snapshot,
                    CAST(:status AS workshop_repair_status),
                    :received_at, :received_by_user_id, :reception_notes,
                    :bench_started_at, :bench_diagnosis,
                    :refurbishment_cost, :net_savings,
                    CAST(:restock_destination AS workshop_restock_destination), :restocked_to_technician,
                    :resolved_at, :resolved_by_user_id, p.installed_at
               FROM incident_replaced_parts p
               JOIN machines m ON m.id = p.machine_id
               JOIN locations l ON l.id = p.location_id
               JOIN users u ON u.id = p.technician_id
               LEFT JOIN spare_parts sp ON sp.id = p.spare_part_id
              WHERE p.id = :part_id_lookup
                AND NOT EXISTS (
                    SELECT 1 FROM workshop_repair_items w
                     WHERE w.incident_replaced_part_id = p.id
                       AND w.unit_index = :unit_index_lookup
                )"
        );

        $inserted = 0;

        foreach (self::workshopRepairSeedBlueprints() as $line) {
            $insertPart->execute([
                ':technician_id' => $order['technician_id'],
                ':quantity' => $line['quantity'],
                ':unit_cost' => $line['unit_cost'],
                ':notes' => $line['notes'],
                ':installed_at' => $line['installed_at'],
                ':part_code' => $line['part_code'],
                ':order_code' => $orderCode,
                ':installed_at_lookup' => $line['installed_at'],
            ]);

            $findPart->execute([
                ':order_code' => $orderCode,
                ':part_code' => $line['part_code'],
                ':installed_at' => $line['installed_at'],
            ]);
            $partId = (int)$findPart->fetchColumn();
            $findPart->closeCursor();

            if ($partId <= 0) {
                continue;
            }

            foreach ($line['units'] as $unitIndex => $unit) {
                $insertUnit->execute([
                    ':unit_index' => $unitIndex,
                    ':total_units' => $line['quantity'],
                    ':status' => $unit['status'],
                    ':received_at' => $unit['received_at'] ?? null,
                    ':reception_notes' => $unit['reception_notes'] ?? null,
                    ':bench_started_at' => $unit['bench_started_at'] ?? null,
                    ':bench_diagnosis' => $unit['bench_diagnosis'] ?? null,
                    ':refurbishment_cost' => $unit['refurbishment_cost_eur'] ?? '0.00',
                    ':net_savings' => $unit['net_savings_eur'] ?? '0.00',
                    ':restock_destination' => $unit['restock_destination'] ?? null,
                    ':restocked_to_technician' => ($unit['restock_destination'] ?? null) === 'TECHNICIAN_VAN'
                        ? $order['technician_id']
                        : null,
                    ':resolved_at' => $unit['resolved_at'] ?? null,
                    ':received_by_user_id' => ($unit['received_at'] ?? null) !== null ? $coordinatorId : null,
                    ':resolved_by_user_id' => ($unit['resolved_at'] ?? null) !== null ? $coordinatorId : null,
                    ':part_id_lookup' => $partId,
                    ':unit_index_lookup' => $unitIndex,
                ]);

                $inserted += $insertUnit->rowCount();
            }
        }

        return $inserted;
    }

    /**
     * Líneas demo del ciclo de taller: una por caso del contrato, con sus unidades.
     *
     * Los importes respetan el suelo cero de `net_savings_eur` (C2): la unidad
     * reacondicionada ahorra la diferencia entre el valor de catálogo y el coste real
     * de la reparación, y la baja no ahorra nada —el motor exige coste y ahorro a cero
     * en `SCRAPPED_IRREPARABLE`—.
     *
     * @return list<array<string, mixed>>
     */
    private static function workshopRepairSeedBlueprints(): array
    {
        return [
            [
                'part_code' => 'GRUP-ESPR-01',
                'quantity' => 2,
                'unit_cost' => '145.00',
                'installed_at' => '2026-10-02 09:15:00+00',
                'notes' => 'Ciclo de taller demo · grupo infusor retirado en la revisión preventiva.',
                'units' => [
                    1 => [
                        'status' => 'PENDING_DELIVERY',
                    ],
                    2 => [
                        'status' => 'REFURBISHED_RESTORED',
                        'received_at' => '2026-10-06 08:30:00+00',
                        'reception_notes' => 'Unidad recibida en taller central con embalaje íntegro.',
                        'bench_started_at' => '2026-10-07 09:00:00+00',
                        'bench_diagnosis' => 'Grupo infusor descalcificado y junta de pistón sustituida en banco.',
                        'refurbishment_cost_eur' => '22.00',
                        'net_savings_eur' => '123.00',
                        'restock_destination' => 'CENTRAL_WAREHOUSE',
                        'resolved_at' => '2026-10-09 12:00:00+00',
                    ],
                ],
            ],
            [
                'part_code' => 'MON-CASH-01',
                'quantity' => 1,
                'unit_cost' => '185.00',
                'installed_at' => '2026-10-08 11:40:00+00',
                'notes' => 'Ciclo de taller demo · monedero retirado para revisión de aceptación.',
                'units' => [
                    1 => [
                        'status' => 'RECEIVED_IN_WORKSHOP',
                        'received_at' => '2026-10-12 10:15:00+00',
                        'reception_notes' => 'Recibido en taller central pendiente de entrada en banco.',
                    ],
                ],
            ],
            [
                'part_code' => 'VENT-EVAP-01',
                'quantity' => 1,
                'unit_cost' => '46.50',
                'installed_at' => '2026-10-13 08:05:00+00',
                'notes' => 'Ciclo de taller demo · ventilador de evaporador con ruido en giro.',
                'units' => [
                    1 => [
                        'status' => 'IN_BENCH_TEST',
                        'received_at' => '2026-10-15 09:20:00+00',
                        'bench_started_at' => '2026-10-16 07:45:00+00',
                        'bench_diagnosis' => 'Turbina con rodamientos nuevos, verificada a 230 V durante veinte minutos.',
                    ],
                ],
            ],
            [
                'part_code' => 'DISP-LCD-01',
                'quantity' => 1,
                'unit_cost' => '42.00',
                'installed_at' => '2026-10-16 16:20:00+00',
                'notes' => 'Ciclo de taller demo · pantalla frontal con retroiluminación intermitente.',
                'units' => [
                    1 => [
                        'status' => 'REFURBISHED_RESTORED',
                        'received_at' => '2026-10-19 08:10:00+00',
                        'bench_started_at' => '2026-10-20 09:30:00+00',
                        'bench_diagnosis' => 'Pantalla con retroiluminación reparada y contraste ajustado en banco.',
                        'refurbishment_cost_eur' => '6.50',
                        'net_savings_eur' => '35.50',
                        'restock_destination' => 'TECHNICIAN_VAN',
                        'resolved_at' => '2026-10-21 11:00:00+00',
                    ],
                ],
            ],
            [
                'part_code' => 'SOND-NTC-01',
                'quantity' => 1,
                'unit_cost' => '15.20',
                'installed_at' => '2026-10-20 10:00:00+00',
                'notes' => 'Ciclo de taller demo · sonda térmica con lectura errática.',
                'units' => [
                    1 => [
                        'status' => 'SCRAPPED_IRREPARABLE',
                        'received_at' => '2026-10-22 09:00:00+00',
                        'bench_started_at' => '2026-10-23 08:30:00+00',
                        'bench_diagnosis' => 'Sonda con hilo interno roto sin reparación posible; se envía a reciclaje.',
                        'resolved_at' => '2026-10-23 10:00:00+00',
                    ],
                ],
            ],
            [
                'part_code' => 'FILT-AGUA-01',
                'quantity' => 1,
                'unit_cost' => '58.00',
                'installed_at' => '2026-10-24 07:45:00+00',
                'notes' => 'Ciclo de taller demo · cartucho retirado que no llegó al taller.',
                'units' => [
                    1 => [
                        'status' => 'SCRAPPED_IRREPARABLE',
                        'bench_diagnosis' => 'Pieza extraviada antes de la entrega en taller; baja dictaminada por coordinación.',
                        'resolved_at' => '2026-10-27 16:40:00+00',
                    ],
                ],
            ],
        ];
    }

    /**
     * Guías rápidas de diagnóstico por modelo (Módulo 28).
     *
     * Siembra las ocho guías reales del parque resolviendo repuesto y causa por sus
     * códigos estables: los identificadores los asigna la secuencia de cada base y
     * una semilla que los escribiera fijos dejaría de servir en la siguiente.
     *
     * El alta es **insertar si no existe** (`DO NOTHING`) y nunca reafirma los
     * campos: el catálogo lo gobierna Coordinación y un paso reescrito, un repuesto
     * recomendado distinto o una baja lógica posterior no puede deshacerse al
     * resembrar el parque. `Fas Perla` se queda sin guía a propósito: es el modelo
     * huérfano que demuestra el indicador de cobertura (QA 1).
     *
     * @return int Guías canónicas procesadas.
     */
    public function seedTroubleshootingGuides(): int
    {
        $sql = sprintf(
            "INSERT INTO machine_troubleshooting_guides (
                machine_model, machine_model_key, breakdown_type, title,
                error_codes_json, steps_json, recommended_spare_part_ids,
                suggested_root_cause_id, is_active
            ) VALUES (
                :machine_model, :machine_model_key, :breakdown_type, :title,
                CAST(:error_codes_json AS JSONB), CAST(:steps_json AS JSONB),
                CAST(:spare_part_ids AS JSONB),
                (SELECT id FROM root_causes WHERE code = :root_cause_code),
                %s
            ) %s",
            $this->dialect->booleanLiteral(true),
            $this->dialect->upsertDoNothing(['machine_model_key', 'breakdown_type'])
        );
        $stmt = $this->pdo->prepare($sql);
        $partStmt = $this->pdo->prepare('SELECT id FROM spare_parts WHERE part_code = :part_code');

        $count = 0;
        foreach (self::canonicalTroubleshootingGuideRows() as $guide) {
            $partIds = [];
            foreach ($guide['part_codes'] as $partCode) {
                $partStmt->execute([':part_code' => $partCode]);
                $partId = $partStmt->fetchColumn();
                if ($partId === false) {
                    throw new RuntimeException(
                        'No se pudo resolver el repuesto sembrado «' . $partCode . '» (Módulo 28).'
                    );
                }
                $partIds[] = (int)$partId;
            }

            $stmt->execute([
                ':machine_model' => $guide['machine_model'],
                ':machine_model_key' => $guide['machine_model_key'],
                ':breakdown_type' => $guide['breakdown_type'],
                ':title' => $guide['title'],
                ':error_codes_json' => (string)json_encode($guide['error_codes'], JSON_UNESCAPED_UNICODE),
                ':steps_json' => (string)json_encode($guide['steps'], JSON_UNESCAPED_UNICODE),
                ':spare_part_ids' => (string)json_encode($partIds),
                ':root_cause_code' => $guide['root_cause_code'],
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Catálogo canónico de guías de diagnóstico (Módulo 28).
     *
     * Los códigos de repuesto y de causa raíz coinciden con los de
     * `database/supabase_seeds.sql` y con el catálogo de los módulos 18 y 25;
     * `CloudDeploySchemaParityTest` vigila esa paridad.
     *
     * @return list<array{machine_model: string, machine_model_key: string, breakdown_type: string, title: string, error_codes: list<array{code: string, meaning: string}>, steps: list<array{step_order: int, title: string, instruction: string}>, part_codes: list<string>, root_cause_code: string}>
     */
    private static function canonicalTroubleshootingGuideRows(): array
    {
        return [
            [
                'machine_model' => 'Sanden Vendo G-Drink',
                'machine_model_key' => 'sanden vendo g-drink',
                'breakdown_type' => 'TEMPERATURE_COLD',
                'title' => 'Cadena de frío: sonda NTC, turbina de evaporador y batería condensadora',
                'error_codes' => [
                    ['code' => 'E04', 'meaning' => 'Sonda térmica NTC de evaporador en circuito abierto o descalibrada'],
                    ['code' => 'AL-TEMP', 'meaning' => 'Temperatura de cabina por encima del umbral sanitario de +8 °C'],
                ],
                'steps' => [
                    ['step_order' => 1, 'title' => 'Comprobar cierre de puerta y turbina de evaporador', 'instruction' => 'Abrir la puerta interior y verificar que la turbina gira sin bloqueo al accionar el pulsador de puerta, y que no hay hielo acumulado en la batería.'],
                    ['step_order' => 2, 'title' => 'Medir la resistencia de la sonda NTC 10K', 'instruction' => 'Desconectar el conector J4 de la placa y medir con polímetro la resistencia de la sonda entre 10 kOhm y 18 kOhm según la temperatura ambiente de la sala.'],
                    ['step_order' => 3, 'title' => 'Limpiar la batería condensadora y revisar el presostato', 'instruction' => 'Soplar la batería condensadora con aire comprimido a baja presión, revisar que el ventilador exterior gire y confirmar que el presostato no esté disparado.'],
                ],
                'part_codes' => ['SOND-NTC-01', 'VENT-EVAP-01'],
                'root_cause_code' => 'RC-COLD-01',
            ],
            [
                'machine_model' => 'Bianchi Gaia Espresso',
                'machine_model_key' => 'bianchi gaia espresso',
                'breakdown_type' => 'OTHER',
                'title' => 'Caldera espresso: electroválvula de entrada, bomba EX5 y filtro antical',
                'error_codes' => [
                    ['code' => 'ERR-H2O', 'meaning' => 'Tiempo de llenado de caldera agotado o caudal de entrada insuficiente'],
                ],
                'steps' => [
                    ['step_order' => 1, 'title' => 'Verificar la llave de paso y el cartucho filtrante', 'instruction' => 'Comprobar que la toma de agua de la sede tiene presión suficiente y que el cartucho antical Brita no está saturado ni bloqueado por cal.'],
                    ['step_order' => 2, 'title' => 'Activar un lavado y escuchar la bomba vibratoria', 'instruction' => 'Ejecutar un ciclo de lavado desde el menú técnico de puerta y confirmar que la bomba EX5 vibra y que la electroválvula Ulka abre el paso hacia el grupo.'],
                    ['step_order' => 3, 'title' => 'Medir 24V DC en la bobina de la electroválvula', 'instruction' => 'Con el ciclo activo, medir la tensión en el conector de la bobina de la electroválvula y descartar un falso contacto en el arnés del equipo.'],
                ],
                'part_codes' => ['VALV-ULKA-01', 'BOMB-VIB-02', 'FILT-AGUA-01'],
                'root_cause_code' => 'RC-OTH-01',
            ],
            [
                'machine_model' => 'Necta Samba Combo',
                'machine_model_key' => 'necta samba combo',
                'breakdown_type' => 'PRODUCT_JAM',
                'title' => 'Atasco en espiral: alineación de canal y fotocélula de caída',
                'error_codes' => [
                    ['code' => 'JAM-01', 'meaning' => 'Producto retenido en la espiral sin confirmación de caída'],
                    ['code' => 'SEN-02', 'meaning' => 'Fotocélula de detección de caída sin señal'],
                ],
                'steps' => [
                    ['step_order' => 1, 'title' => 'Liberar el canal y revisar la espiral', 'instruction' => 'Retirar el producto atascado, comprobar que la espiral gira libre y que el paso coincide con el tamaño del envase cargado en el canal.'],
                    ['step_order' => 2, 'title' => 'Limpiar las lentes de la fotocélula', 'instruction' => 'Desmontar la barrera óptica, limpiar ambas lentes con un paño seco y verificar que el led de recepción conmuta al pasar la mano por delante.'],
                    ['step_order' => 3, 'title' => 'Probar tres extracciones consecutivas', 'instruction' => 'Cargar tres unidades del mismo producto y ejecutar tres ventas seguidas para confirmar que la caída se detecta en todas ellas.'],
                ],
                'part_codes' => ['MOT-ESP-01', 'FOTO-CAID-01'],
                'root_cause_code' => 'RC-JAM-01',
            ],
            [
                'machine_model' => 'Fas Fast 900',
                'machine_model_key' => 'fas fast 900',
                'breakdown_type' => 'PAYMENT_SYSTEM',
                'title' => 'Pasarela de pago: monedero NRI G13 y terminal VPOS Touch',
                'error_codes' => [
                    ['code' => 'MDB-01', 'meaning' => 'Sin respuesta del validador de monedas en el bus MDB'],
                    ['code' => 'TLS-04', 'meaning' => 'Terminal contactless sin enlace de telemetría'],
                ],
                'steps' => [
                    ['step_order' => 1, 'title' => 'Inspeccionar el canal de monedas y la trampilla', 'instruction' => 'Accionar la palanca de devolución del selector y revisar que no haya monedas dobladas ni objetos extraños en los sensores ópticos del monedero.'],
                    ['step_order' => 2, 'title' => 'Comprobar alimentación y conector MDB', 'instruction' => 'Verificar el conector Molex de seis pines del bus MDB, su tensión de 24V y que el terminal Nayax muestre estado en línea con la antena conectada.'],
                    ['step_order' => 3, 'title' => 'Ejecutar una venta de prueba con tarjeta', 'instruction' => 'Realizar una compra con tarjeta contactless y confirmar el cargo, la devolución del cambio y el registro de la venta en la telemetría.'],
                ],
                'part_codes' => ['MON-CASH-01', 'TELEC-NAYAX-01'],
                'root_cause_code' => 'RC-PAY-01',
            ],
            [
                'machine_model' => 'Azkoyen Palma+',
                'machine_model_key' => 'azkoyen palma+',
                'breakdown_type' => 'TEMPERATURE_COLD',
                'title' => 'Bebidas frías: control de temperatura, ventilador y sonda de cabina',
                'error_codes' => [
                    ['code' => 'E05', 'meaning' => 'Sensor de temperatura de cabina fuera de rango'],
                    ['code' => 'FAN-01', 'meaning' => 'Ventilador de evaporador bloqueado o sin alimentación'],
                ],
                'steps' => [
                    ['step_order' => 1, 'title' => 'Leer la temperatura real de la cabina', 'instruction' => 'Colocar un termómetro de referencia en el centro de la cabina y comparar la lectura con la que muestra el display del equipo.'],
                    ['step_order' => 2, 'title' => 'Revisar el ventilador de evaporador', 'instruction' => 'Con la puerta cerrada, confirmar que el aire sale por las rejillas y que la turbina no roza con acumulación de hielo en el evaporador.'],
                    ['step_order' => 3, 'title' => 'Comprobar la sonda y el contacto del termostato', 'instruction' => 'Medir la sonda NTC en el conector de la placa y verificar que el termostato de seguridad no ha disparado por sobretemperatura.'],
                ],
                'part_codes' => ['SOND-NTC-01', 'VENT-EVAP-01'],
                'root_cause_code' => 'RC-COLD-02',
            ],
            [
                'machine_model' => 'Necta Korinto',
                'machine_model_key' => 'necta korinto',
                'breakdown_type' => 'PRODUCT_JAM',
                'title' => 'Grupo infusor de café: juntas, cámara de compresión y bloqueo',
                'error_codes' => [
                    ['code' => 'GRP-03', 'meaning' => 'Grupo infusor bloqueado en posición de compresión'],
                ],
                'steps' => [
                    ['step_order' => 1, 'title' => 'Desmontar y revisar el grupo infusor', 'instruction' => 'Extraer el grupo, comprobar que la cámara sube y baja sin resistencia y que no hay restos de café endurecido en las guías.'],
                    ['step_order' => 2, 'title' => 'Sustituir juntas tóricas y engrasar con grasa alimentaria', 'instruction' => 'Cambiar las juntas del pistón, aplicar grasa alimentaria en las guías y volver a montar el grupo con el trinquete en posición inicial.'],
                    ['step_order' => 3, 'title' => 'Ejecutar un ciclo de café y comprobar la extracción', 'instruction' => 'Realizar dos cafés seguidos, verificar que el grupo no se bloquea y que el líquido sale con caudal y temperatura correctos.'],
                ],
                'part_codes' => ['GRUP-ESPR-01', 'JUNT-TOR-01'],
                'root_cause_code' => 'RC-JAM-04',
            ],
            [
                'machine_model' => 'Jofemar Vision',
                'machine_model_key' => 'jofemar vision',
                'breakdown_type' => 'ELECTRICAL_OFF',
                'title' => 'Pantalla y placa: display LCD sin imagen y etapa de potencia',
                'error_codes' => [
                    ['code' => 'DSP-00', 'meaning' => 'Display frontal sin comunicación con la placa de control'],
                    ['code' => 'PWR-11', 'meaning' => 'Fallo de alimentación en la etapa de potencia'],
                ],
                'steps' => [
                    ['step_order' => 1, 'title' => 'Comprobar la alimentación general del equipo', 'instruction' => 'Verificar la toma de corriente, el fusible interno y que el piloto de la fuente conmutada permanezca encendido tras el arranque.'],
                    ['step_order' => 2, 'title' => 'Revisar la faja plana del display', 'instruction' => 'Desconectar y volver a conectar la faja de datos del display, comprobando que no tenga pistas dobladas ni suciedad en el conector.'],
                    ['step_order' => 3, 'title' => 'Medir las tensiones de la fuente conmutada', 'instruction' => 'Medir 5V y 12V en los bornes de salida de la fuente y confirmar que no hay caída de tensión al conectar la pantalla.'],
                ],
                'part_codes' => ['DISP-LCD-01'],
                'root_cause_code' => 'RC-ELEC-03',
            ],
            [
                'machine_model' => 'Saeco Cristallo',
                'machine_model_key' => 'saeco cristallo',
                'breakdown_type' => 'OTHER',
                'title' => 'Circuito hidráulico: descalcificación, electroválvula y bomba',
                'error_codes' => [
                    ['code' => 'DESC-02', 'meaning' => 'Bloqueo por incrustación calcárea en el circuito de agua'],
                ],
                'steps' => [
                    ['step_order' => 1, 'title' => 'Comprobar el filtro y la dureza del agua', 'instruction' => 'Revisar la fecha del cartucho antical, medir la dureza del agua de entrada y sustituir el filtro si ya está agotado.'],
                    ['step_order' => 2, 'title' => 'Ejecutar el programa de descalcificación', 'instruction' => 'Lanzar el ciclo de descalcificación desde el menú de servicio, dejar actuar el producto y enjuagar con dos depósitos de agua limpia.'],
                    ['step_order' => 3, 'title' => 'Verificar el caudal y la electroválvula', 'instruction' => 'Comprobar que la bomba entrega caudal constante y que la electroválvula abre sin ruido anómalo al pedir agua caliente.'],
                ],
                'part_codes' => ['VALV-ULKA-01', 'FILT-AGUA-01', 'BOMB-VIB-02'],
                'root_cause_code' => 'RC-OTH-01',
            ],
        ];
    }

    /**
     * Actas de merma y destrucción de lotes por cuarentena sanitaria (Módulo 29).
     *
     * Siembra las tres actas históricas que cubren los desenlaces reales de una
     * cuarentena: producto destruido por rotura de frío, inspección preventiva no
     * conforme con ajuste económico auditado y cabina verificada vacía. Máquina y
     * usuarios se resuelven por sus claves estables —código y correo— porque los
     * identificadores los asigna la secuencia de cada base.
     *
     * El alta es insertar si no existe y nunca reafirma los hechos sanitarios: un
     * acta emitida es inmutable (Art. III.3) y solo admite el ajuste financiero
     * auditado de Coordinación.
     *
     * @return int Actas canónicas procesadas.
     */
    public function seedSanitarySpoilageRecords(): int
    {
        $sqlTemplate = "INSERT INTO sanitary_spoilage_records (
            record_code, machine_id, incident_id, maintenance_order_id,
            technician_id, registered_by, quarantine_origin,
            discarded_units_count, empty_machine_confirmed, affected_batch_codes,
            cabin_temp_at_removal, restored_stabilized_temp_c, disposal_method,
            evidence_photo_url, sanitary_notes, estimated_merchandise_loss_eur,
            loss_adjusted_by, loss_adjustment_reason, certificate_code, created_at
        ) VALUES (
            :record_code, :machine_id, NULL, NULL,
            :technician_id, :registered_by, :quarantine_origin,
            :discarded_units_count, %s, :affected_batch_codes,
            :cabin_temp_at_removal, :restored_stabilized_temp_c, :disposal_method,
            :evidence_photo_url, :sanitary_notes, :estimated_merchandise_loss_eur,
            :loss_adjusted_by, :loss_adjustment_reason, :certificate_code, :created_at
        ) %s";

        $machineStmt = $this->pdo->prepare('SELECT id FROM machines WHERE code = :code');
        $userStmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');

        $count = 0;
        foreach (self::canonicalSanitarySpoilageRecordRows() as $record) {
            $machineStmt->execute([':code' => $record['machine_code']]);
            $machineId = $machineStmt->fetchColumn();
            if ($machineId === false) {
                throw new RuntimeException(
                    'No se pudo resolver la máquina sembrada «' . $record['machine_code'] . '» (Módulo 29).'
                );
            }

            $technicianId = $this->resolveSeedUserId($userStmt, $record['technician_email']);
            $registeredById = $this->resolveSeedUserId($userStmt, $record['registered_by_email']);
            $lossAdjustedById = $record['loss_adjusted_by_email'] === null
                ? null
                : $this->resolveSeedUserId($userStmt, $record['loss_adjusted_by_email']);

            $sql = sprintf(
                $sqlTemplate,
                $this->dialect->booleanLiteral($record['empty_machine_confirmed']),
                $this->dialect->upsertDoNothing(['record_code'])
            );

            $this->pdo->prepare($sql)->execute([
                ':record_code' => $record['record_code'],
                ':machine_id' => (int)$machineId,
                ':technician_id' => $technicianId,
                ':registered_by' => $registeredById,
                ':quarantine_origin' => $record['quarantine_origin'],
                ':discarded_units_count' => $record['discarded_units_count'],
                ':affected_batch_codes' => $record['affected_batch_codes'],
                ':cabin_temp_at_removal' => $record['cabin_temp_at_removal'],
                ':restored_stabilized_temp_c' => $record['restored_stabilized_temp_c'],
                ':disposal_method' => $record['disposal_method'],
                ':evidence_photo_url' => $record['evidence_photo_url'],
                ':sanitary_notes' => $record['sanitary_notes'],
                ':estimated_merchandise_loss_eur' => $record['estimated_merchandise_loss_eur'],
                ':loss_adjusted_by' => $lossAdjustedById,
                ':loss_adjustment_reason' => $record['loss_adjustment_reason'],
                ':certificate_code' => $record['certificate_code'],
                ':created_at' => $record['created_at'],
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Resuelve el identificador de un usuario sembrado por su correo canónico.
     *
     * @param \PDOStatement $userStmt Consulta preparada reutilizable.
     */
    private function resolveSeedUserId(\PDOStatement $userStmt, string $email): int
    {
        $userStmt->execute([':email' => $email]);
        $userId = $userStmt->fetchColumn();
        if ($userId === false) {
            throw new RuntimeException(
                'No se pudo resolver el usuario sembrado «' . $email . '» (Módulo 29).'
            );
        }

        return (int)$userId;
    }

    /**
     * Catálogo canónico de actas de merma (Módulo 29).
     *
     * Los códigos de máquina y los correos coinciden con `database/supabase_seeds.sql`
     * (secciones 3, 4 y 21); los códigos de certificado siguen el formato oficial
     * `CERT-AAAA-NNNN` que el propio levantamiento expide.
     *
     * @return list<array<string, mixed>>
     */
    private static function canonicalSanitarySpoilageRecordRows(): array
    {
        return [
            [
                'record_code' => 'ACTA-MERMA-2026-0001',
                'machine_code' => 'VEND-0101',
                'technician_email' => 'jordi.ruta@vendguard.internal',
                'registered_by_email' => 'jordi.ruta@vendguard.internal',
                'quarantine_origin' => 'BIOLOGICAL_CLOCK_4H',
                'discarded_units_count' => 14,
                'empty_machine_confirmed' => false,
                'affected_batch_codes' => 'LOTE-2026-0412 · yogures naturales; LOTE-2026-0415 · sándwiches de pavo',
                'cabin_temp_at_removal' => '9.4',
                'restored_stabilized_temp_c' => '3.2',
                'disposal_method' => 'ON_SITE_ORGANIC_SEALED',
                'evidence_photo_url' => '/assets/seed/spoilage/ACTA-MERMA-2026-0001.jpg',
                'sanitary_notes' => 'Retirada de 14 unidades expuestas por rotura de la cadena de frío; cabina higienizada con solución alimentaria y secada antes de reponer.',
                'estimated_merchandise_loss_eur' => '35.00',
                'loss_adjusted_by_email' => null,
                'loss_adjustment_reason' => null,
                'certificate_code' => 'CERT-2026-0101',
                'created_at' => '2026-09-14 08:40:00+00',
            ],
            [
                'record_code' => 'ACTA-MERMA-2026-0002',
                'machine_code' => 'VEND-0201',
                'technician_email' => 'marta.ruta@vendguard.internal',
                'registered_by_email' => 'coordinacion@vendguard.internal',
                'quarantine_origin' => 'PREVENTIVE_NON_CONFORMITY',
                'discarded_units_count' => 8,
                'empty_machine_confirmed' => false,
                'affected_batch_codes' => 'LOTE-2026-0931 · sándwiches mixtos; LOTE-2026-0936 · ensaladas de pasta',
                'cabin_temp_at_removal' => '10.2',
                'restored_stabilized_temp_c' => '3.8',
                'disposal_method' => 'RETURN_TO_CENTRAL_BIOHAZARD',
                'evidence_photo_url' => '/assets/seed/spoilage/ACTA-MERMA-2026-0002.jpg',
                'sanitary_notes' => 'Inspección preventiva no conforme por exceso térmico: retirada precintada de 8 unidades y desinfección completa de la cabina.',
                'estimated_merchandise_loss_eur' => '29.60',
                'loss_adjusted_by_email' => 'coordinacion@vendguard.internal',
                'loss_adjustment_reason' => 'Ajuste contable al precio de reposición real de los dos lotes retirados.',
                'certificate_code' => 'CERT-2026-0102',
                'created_at' => '2026-09-28 17:05:00+00',
            ],
            [
                'record_code' => 'ACTA-MERMA-2026-0003',
                'machine_code' => 'VEND-0301',
                'technician_email' => 'carlos.ruta@vendguard.internal',
                'registered_by_email' => 'carlos.ruta@vendguard.internal',
                'quarantine_origin' => 'BIOLOGICAL_CLOCK_4H',
                'discarded_units_count' => 0,
                'empty_machine_confirmed' => true,
                'affected_batch_codes' => 'SIN_STOCK_AFECTADO',
                'cabin_temp_at_removal' => '18.6',
                'restored_stabilized_temp_c' => '3.0',
                'disposal_method' => 'NOT_APPLICABLE_EMPTY',
                'evidence_photo_url' => '/assets/seed/spoilage/ACTA-MERMA-2026-0003-empty.jpg',
                'sanitary_notes' => 'Cabina verificada vacía: sin producto perecedero expuesto; se higienizó y se fotografió el interior antes de reabrir.',
                'estimated_merchandise_loss_eur' => '0.00',
                'loss_adjusted_by_email' => null,
                'loss_adjustment_reason' => null,
                'certificate_code' => 'CERT-2026-0103',
                'created_at' => '2026-10-19 11:25:00+00',
            ],
        ];
    }

    /**
     * Catálogo maestro comercial y de consumibles (Módulo 32 · C3, `vending_products`).
     *
     * Las quince referencias cubren las cinco categorías del módulo: refrescos y
     * zumos de venta directa, snacks, perecederos con cadena de frío obligatoria,
     * ingredientes de tolva en gramos y consumibles de tira. El `sku` identifica la
     * fila y no se refresca (C1); `is_active` tampoco, para que una resemilla no
     * resucite una baja lógica decidida por Coordinación.
     *
     * @return int Número de productos procesados.
     */
    public function seedVendingProducts(): int
    {
        $sql = sprintf(
            'INSERT INTO vending_products (
                sku, name, category, unit_type, requires_cold_chain,
                max_shelf_life_days, unit_cost_eur, sale_price_eur
            ) VALUES (
                :sku, :name, :category, :unit_type, :requires_cold_chain,
                :max_shelf_life_days, :unit_cost_eur, :sale_price_eur
            ) %s',
            $this->dialect->upsertAssignments(['sku'], [
                'name' => SqlDialectAdapter::PROPOSED_VALUE,
                'category' => SqlDialectAdapter::PROPOSED_VALUE,
                'unit_type' => SqlDialectAdapter::PROPOSED_VALUE,
                'requires_cold_chain' => SqlDialectAdapter::PROPOSED_VALUE,
                'max_shelf_life_days' => SqlDialectAdapter::PROPOSED_VALUE,
                'unit_cost_eur' => SqlDialectAdapter::PROPOSED_VALUE,
                'sale_price_eur' => SqlDialectAdapter::PROPOSED_VALUE,
            ])
        );

        $stmt = $this->pdo->prepare($sql);
        $count = 0;

        foreach (self::canonicalVendingProductRows() as $product) {
            $stmt->bindValue(':sku', $product['sku'], PDO::PARAM_STR);
            $stmt->bindValue(':name', $product['name'], PDO::PARAM_STR);
            $stmt->bindValue(':category', $product['category'], PDO::PARAM_STR);
            $stmt->bindValue(':unit_type', $product['unit_type'], PDO::PARAM_STR);
            $stmt->bindValue(':requires_cold_chain', $product['requires_cold_chain'], PDO::PARAM_BOOL);
            $stmt->bindValue(
                ':max_shelf_life_days',
                $product['max_shelf_life_days'],
                $product['max_shelf_life_days'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
            );
            $stmt->bindValue(':unit_cost_eur', $product['unit_cost_eur'], PDO::PARAM_STR);
            $stmt->bindValue(':sale_price_eur', $product['sale_price_eur'], PDO::PARAM_STR);
            $stmt->execute();
            $count++;
        }

        return $count;
    }

    /**
     * Planograma de las trece máquinas sembradas (Módulo 32 · C3, `machine_planogram_slots`).
     *
     * Cada carril respeta la matriz `MachineType × ProductCategory` (D3): los
     * perecederos solo caen en `PERISHABLE_FOOD` o `COMBO`, y las tolvas de café
     * solo en máquinas de bebida caliente o combo. Se inserta **solo si no
     * existe**: `current_estimated_qty` es existencia operativa y una resemilla no
     * debe borrar el trabajo del reponedor ni reactivar un carril retirado (C1).
     *
     * @return int Número de carriles procesados.
     */
    public function seedMachinePlanograms(): int
    {
        $sql = sprintf(
            "INSERT INTO machine_planogram_slots (
                machine_id, slot_code, product_id, max_capacity,
                current_estimated_qty, min_threshold, is_active
            ) VALUES (
                (SELECT id FROM machines WHERE code = :machine_code LIMIT 1),
                :slot_code,
                (SELECT id FROM vending_products WHERE sku = :sku LIMIT 1),
                :max_capacity,
                :current_estimated_qty,
                :min_threshold,
                %s
            ) %s",
            $this->dialect->booleanLiteral(true),
            $this->dialect->upsertDoNothing(['machine_id', 'slot_code'])
        );

        $stmt = $this->pdo->prepare($sql);
        $count = 0;

        foreach (self::canonicalPlanogramSlotRows() as $slot) {
            $stmt->bindValue(':machine_code', $slot['machine_code'], PDO::PARAM_STR);
            $stmt->bindValue(':slot_code', $slot['slot_code'], PDO::PARAM_STR);
            $stmt->bindValue(':sku', $slot['sku'], PDO::PARAM_STR);
            $stmt->bindValue(':max_capacity', $slot['max_capacity'], PDO::PARAM_INT);
            $stmt->bindValue(':current_estimated_qty', $slot['current_estimated_qty'], PDO::PARAM_INT);
            $stmt->bindValue(':min_threshold', $slot['min_threshold'], PDO::PARAM_INT);
            $stmt->execute();
            $count++;
        }

        return $count;
    }

    /**
     * Stock de producto por lote en las furgonetas de reposición (Módulo 33 · D2, D4).
     *
     * El saldo se identifica por `(restocker_id, product_id, batch_code)`: dos lotes
     * del mismo sándwich conviven en la furgoneta porque caducan distinto y el FEFO
     * los ordena por fecha. Se inserta **solo si no existe**: la existencia es
     * operativa y una resemilla no debe borrar el trabajo del reponedor —el ajuste
     * manual se audita, no se siembra—. Las referencias se resuelven por la clave
     * canónica del reponedor y por `sku`, nunca por identificadores de secuencia.
     *
     * @return int Saldos por lote canónicos vivos tras sembrar.
     */
    public function seedRestockerVanProductStocks(): int
    {
        $identityColumn = $this->dialect->requiresCanonicalIdentityColumns() ? 'email_key' : 'email';

        $insert = $this->pdo->prepare(sprintf(
            'INSERT INTO restocker_van_product_stocks (
                restocker_id, product_id, batch_code, expiration_date, quantity_available
            ) VALUES (
                (SELECT id FROM users WHERE %1$s = :restocker_email LIMIT 1),
                (SELECT id FROM vending_products WHERE sku = :sku LIMIT 1),
                :batch_code, :expiration_date, :quantity_available
            ) %2$s',
            $identityColumn,
            $this->dialect->upsertDoNothing(['restocker_id', 'product_id', 'batch_code'])
        ));

        foreach (self::canonicalRestockerVanProductStockRows() as $row) {
            $insert->bindValue(':restocker_email', $row['email'], PDO::PARAM_STR);
            $insert->bindValue(':sku', $row['sku'], PDO::PARAM_STR);
            $insert->bindValue(':batch_code', $row['batch_code'], PDO::PARAM_STR);
            $insert->bindValue(
                ':expiration_date',
                $row['expiration_days'] === null ? null : date('Y-m-d', strtotime(sprintf('+%d days', $row['expiration_days']))),
                $row['expiration_days'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $insert->bindValue(':quantity_available', $row['quantity_available'], PDO::PARAM_INT);
            $insert->execute();
        }

        // Se informa del saldo canónico vivo, no de las filas insertadas: igual que
        // el cuadrante territorial, resembrar no altera las cifras del resumen.
        $verify = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*)
               FROM restocker_van_product_stocks s
               INNER JOIN users u ON u.id = s.restocker_id
               INNER JOIN vending_products p ON p.id = s.product_id
              WHERE u.%s = :restocker_email
                AND p.sku = :sku
                AND s.batch_code = :batch_code',
            $identityColumn
        ));

        $count = 0;
        foreach (self::canonicalRestockerVanProductStockRows() as $row) {
            $verify->execute([
                ':restocker_email' => $row['email'],
                ':sku' => $row['sku'],
                ':batch_code' => $row['batch_code'],
            ]);
            $count += (int)$verify->fetchColumn();
        }

        return $count;
    }

    /**
     * Órdenes de carga demo: una liquidada al cierre y otra despachada (Módulo 33).
     *
     * La orden no nace de una avería: nace de la demanda logística de la ruta. La
     * liquidada demuestra el cuadre completo —incluida una línea planificada y no
     * despachada, sellada en 0 (QA 4)— y la despachada espera la recepción en un
     * toque del reponedor, con temperatura de salida de cámara de 3,5 °C (Art. II).
     * Las órdenes son hechos históricos: se insertan **solo si no existen** y una
     * resemilla no reescribe su estado, su temperatura ni su cuadre.
     *
     * @return int Órdenes canónicas vivas tras sembrar.
     */
    public function seedProductLoadingOrders(): int
    {
        $identityColumn = $this->dialect->requiresCanonicalIdentityColumns() ? 'email_key' : 'email';

        $orderInsert = $this->pdo->prepare(sprintf(
            'INSERT INTO product_loading_orders (
                order_code, restocker_id, status, demand_mode, safety_buffer_pct,
                cold_chamber_exit_temp_celsius, notes, reconciliation_notes,
                created_by, dispatched_by, reconciled_by,
                created_at, dispatched_at, confirmed_at, reconciled_at
            ) VALUES (
                :order_code,
                (SELECT id FROM users WHERE %1$s = :restocker_email LIMIT 1),
                :status, :demand_mode, :safety_buffer_pct, :cold_temp, :notes, :reconciliation_notes,
                (SELECT id FROM users WHERE %1$s = :created_by_email LIMIT 1),
                (SELECT id FROM users WHERE %1$s = :dispatched_by_email LIMIT 1),
                (SELECT id FROM users WHERE %1$s = :reconciled_by_email LIMIT 1),
                :created_at, :dispatched_at, :confirmed_at, :reconciled_at
            ) %2$s',
            $identityColumn,
            $this->dialect->upsertDoNothing(['order_code'])
        ));

        $timestamp = static fn (?int $offsetHours): ?string => $offsetHours === null
            ? null
            : date('Y-m-d H:i:s', time() + $offsetHours * 3600);

        foreach (self::canonicalProductLoadingOrderRows() as $order) {
            $orderInsert->bindValue(':order_code', $order['order_code'], PDO::PARAM_STR);
            $orderInsert->bindValue(':restocker_email', $order['restocker_email'], PDO::PARAM_STR);
            $orderInsert->bindValue(':status', $order['status'], PDO::PARAM_STR);
            $orderInsert->bindValue(':demand_mode', $order['demand_mode'], PDO::PARAM_STR);
            $orderInsert->bindValue(':safety_buffer_pct', $order['safety_buffer_pct'], PDO::PARAM_INT);
            $orderInsert->bindValue(
                ':cold_temp',
                $order['cold_chamber_exit_temp_celsius'],
                $order['cold_chamber_exit_temp_celsius'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $orderInsert->bindValue(':notes', $order['notes'], PDO::PARAM_STR);
            $orderInsert->bindValue(
                ':reconciliation_notes',
                $order['reconciliation_notes'],
                $order['reconciliation_notes'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $orderInsert->bindValue(':created_by_email', $order['created_by_email'], PDO::PARAM_STR);
            $orderInsert->bindValue(
                ':dispatched_by_email',
                $order['dispatched_by_email'],
                $order['dispatched_by_email'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $orderInsert->bindValue(
                ':reconciled_by_email',
                $order['reconciled_by_email'],
                $order['reconciled_by_email'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );

            $timestamps = [
                ':created_at' => $timestamp($order['created_at_offset_hours']),
                ':dispatched_at' => $timestamp($order['dispatched_at_offset_hours']),
                ':confirmed_at' => $timestamp($order['confirmed_at_offset_hours']),
                ':reconciled_at' => $timestamp($order['reconciled_at_offset_hours']),
            ];
            foreach ($timestamps as $placeholder => $value) {
                $orderInsert->bindValue($placeholder, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }

            $orderInsert->execute();
        }

        $itemInsert = $this->pdo->prepare(sprintf(
            'INSERT INTO product_loading_order_items (
                order_id, product_id, reason, requested_qty, dispatched_qty,
                batch_code, expiration_date,
                van_stock_before_reconcile, restocked_in_machines_qty, withdrawn_spoilage_qty,
                expected_return_qty, counted_return_qty, discrepancy_qty, is_active
            ) VALUES (
                (SELECT id FROM product_loading_orders WHERE order_code = :order_code LIMIT 1),
                (SELECT id FROM vending_products WHERE sku = :sku LIMIT 1),
                :reason, :requested_qty, :dispatched_qty, :batch_code, :expiration_date,
                :van_stock_before_reconcile, :restocked_in_machines_qty, :withdrawn_spoilage_qty,
                :expected_return_qty, :counted_return_qty, :discrepancy_qty, %1$s
            ) %2$s',
            $this->dialect->booleanLiteral(true),
            $this->dialect->upsertDoNothing(['order_id', 'product_id'])
        ));

        foreach (self::canonicalProductLoadingOrderItemRows() as $item) {
            $itemInsert->bindValue(':order_code', $item['order_code'], PDO::PARAM_STR);
            $itemInsert->bindValue(':sku', $item['sku'], PDO::PARAM_STR);
            $itemInsert->bindValue(':reason', $item['reason'], PDO::PARAM_STR);
            $itemInsert->bindValue(':requested_qty', $item['requested_qty'], PDO::PARAM_INT);
            $itemInsert->bindValue(
                ':dispatched_qty',
                $item['dispatched_qty'],
                $item['dispatched_qty'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
            );
            $itemInsert->bindValue(
                ':batch_code',
                $item['batch_code'],
                $item['batch_code'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $itemInsert->bindValue(
                ':expiration_date',
                $item['expiration_days'] === null ? null : date('Y-m-d', strtotime(sprintf('+%d days', $item['expiration_days']))),
                $item['expiration_days'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );

            foreach (['van_stock_before_reconcile', 'restocked_in_machines_qty', 'withdrawn_spoilage_qty', 'expected_return_qty', 'counted_return_qty', 'discrepancy_qty'] as $column) {
                $itemInsert->bindValue(
                    ':' . $column,
                    $item[$column],
                    $item[$column] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
                );
            }

            $itemInsert->execute();
        }

        $verify = $this->pdo->prepare('SELECT COUNT(*) FROM product_loading_orders WHERE order_code = :order_code');
        $count = 0;
        foreach (self::canonicalProductLoadingOrderRows() as $order) {
            $verify->execute([':order_code' => $order['order_code']]);
            $count += (int)$verify->fetchColumn();
        }

        return $count;
    }

    /**
     * Los seis saldos por lote de las furgonetas sembradas (Módulo 33 · D2, D4).
     *
     * `expiration_days` se declara relativo al día de la siembra —el semáforo FEFO
     * se demuestra vivo, no congelado en una fecha del pasado— y es `NULL` para los
     * no perecederos, que viajan con el lote normalizado `'SIN-LOTE'`.
     *
     * @return list<array{email: string, sku: string, batch_code: string, expiration_days: ?int, quantity_available: int}>
     */
    private static function canonicalRestockerVanProductStockRows(): array
    {
        return [
            ['email' => 'lucia.repostaje@vendguard.internal', 'sku' => 'SAND-MIXTO-01', 'batch_code' => 'L-SAND-2602', 'expiration_days' => 2, 'quantity_available' => 12],
            ['email' => 'lucia.repostaje@vendguard.internal', 'sku' => 'SAND-MIXTO-01', 'batch_code' => 'L-SAND-2606', 'expiration_days' => 4, 'quantity_available' => 18],
            ['email' => 'lucia.repostaje@vendguard.internal', 'sku' => 'COLA-LATA-33', 'batch_code' => 'SIN-LOTE', 'expiration_days' => null, 'quantity_available' => 60],
            ['email' => 'lucia.repostaje@vendguard.internal', 'sku' => 'SNACK-PATAT-40', 'batch_code' => 'SIN-LOTE', 'expiration_days' => null, 'quantity_available' => 40],
            ['email' => 'oscar.repostaje@vendguard.internal', 'sku' => 'YOGUR-NAT-125', 'batch_code' => 'L-YOG-2611', 'expiration_days' => 12, 'quantity_available' => 24],
            ['email' => 'oscar.repostaje@vendguard.internal', 'sku' => 'AGUA-MIN-50', 'batch_code' => 'SIN-LOTE', 'expiration_days' => null, 'quantity_available' => 48],
        ];
    }

    /**
     * Las dos órdenes de carga demo, una por estado demostrable (Módulo 33 · D3, QA 4).
     *
     * Los instantes se declaran como desplazamiento en horas respecto de la siembra:
     * la liquidada es el cierre del día anterior y la despachada salió hace una hora.
     *
     * @return list<array<string, mixed>>
     */
    private static function canonicalProductLoadingOrderRows(): array
    {
        return [
            [
                'order_code' => 'PLO-2026-0001',
                'restocker_email' => 'lucia.repostaje@vendguard.internal',
                'status' => 'RECONCILED_AT_CLOSE',
                'demand_mode' => 'ALERTS_ONLY',
                'safety_buffer_pct' => 10,
                'cold_chamber_exit_temp_celsius' => '3.0',
                'notes' => 'Orden matutina de la zona centro: dos lotes de sándwich y reposición de refrescos.',
                'reconciliation_notes' => 'Cuadre sin descuadre: volvió una unidad de merma de sándwich retirada en ruta.',
                'created_by_email' => 'coordinacion@vendguard.internal',
                'dispatched_by_email' => 'coordinacion@vendguard.internal',
                'reconciled_by_email' => 'coordinacion@vendguard.internal',
                'created_at_offset_hours' => -24,
                'dispatched_at_offset_hours' => -24,
                'confirmed_at_offset_hours' => -23,
                'reconciled_at_offset_hours' => 0,
            ],
            [
                'order_code' => 'PLO-2026-0002',
                'restocker_email' => 'oscar.repostaje@vendguard.internal',
                'status' => 'DISPATCHED',
                'demand_mode' => 'ALL_GAPS',
                'safety_buffer_pct' => 15,
                'cold_chamber_exit_temp_celsius' => '3.5',
                'notes' => 'Reposición completa de la ruta norte con yogur de lote largo y frutos secos.',
                'reconciliation_notes' => null,
                'created_by_email' => 'coordinacion@vendguard.internal',
                'dispatched_by_email' => 'coordinacion@vendguard.internal',
                'reconciled_by_email' => null,
                'created_at_offset_hours' => -2,
                'dispatched_at_offset_hours' => -1,
                'confirmed_at_offset_hours' => null,
                'reconciled_at_offset_hours' => null,
            ],
        ];
    }

    /**
     * Las cinco líneas de las dos órdenes demo (Módulo 33 · C1, D3, QA 4).
     *
     * La tercera línea de la orden liquidada se planificó y no se despachó: sus seis
     * campos de cuadre quedan en 0, que es la forma que exige el Art. V.1/QA 4.
     *
     * @return list<array<string, mixed>>
     */
    private static function canonicalProductLoadingOrderItemRows(): array
    {
        return [
            [
                'order_code' => 'PLO-2026-0001',
                'sku' => 'SAND-MIXTO-01',
                'reason' => 'STOCKOUT_ALERT',
                'requested_qty' => 24,
                'dispatched_qty' => 24,
                'batch_code' => 'L-SAND-2602',
                'expiration_days' => 2,
                'van_stock_before_reconcile' => 24,
                'restocked_in_machines_qty' => 20,
                'withdrawn_spoilage_qty' => 2,
                'expected_return_qty' => 2,
                'counted_return_qty' => 2,
                'discrepancy_qty' => 0,
            ],
            [
                'order_code' => 'PLO-2026-0001',
                'sku' => 'COLA-LATA-33',
                'reason' => 'LOW_STOCK_ALERT',
                'requested_qty' => 60,
                'dispatched_qty' => 60,
                'batch_code' => 'SIN-LOTE',
                'expiration_days' => null,
                'van_stock_before_reconcile' => 60,
                'restocked_in_machines_qty' => 60,
                'withdrawn_spoilage_qty' => 0,
                'expected_return_qty' => 0,
                'counted_return_qty' => 0,
                'discrepancy_qty' => 0,
            ],
            [
                'order_code' => 'PLO-2026-0001',
                'sku' => 'AGUA-MIN-50',
                'reason' => 'ROUTE_GAP_FILL',
                'requested_qty' => 36,
                'dispatched_qty' => 0,
                'batch_code' => null,
                'expiration_days' => null,
                'van_stock_before_reconcile' => 0,
                'restocked_in_machines_qty' => 0,
                'withdrawn_spoilage_qty' => 0,
                'expected_return_qty' => 0,
                'counted_return_qty' => 0,
                'discrepancy_qty' => 0,
            ],
            [
                'order_code' => 'PLO-2026-0002',
                'sku' => 'YOGUR-NAT-125',
                'reason' => 'STOCKOUT_ALERT',
                'requested_qty' => 24,
                'dispatched_qty' => 24,
                'batch_code' => 'L-YOG-2611',
                'expiration_days' => 12,
                'van_stock_before_reconcile' => null,
                'restocked_in_machines_qty' => null,
                'withdrawn_spoilage_qty' => null,
                'expected_return_qty' => null,
                'counted_return_qty' => null,
                'discrepancy_qty' => null,
            ],
            [
                'order_code' => 'PLO-2026-0002',
                'sku' => 'SNACK-FRUTSEC-50',
                'reason' => 'MANUAL_ADDITION',
                'requested_qty' => 20,
                'dispatched_qty' => 20,
                'batch_code' => 'SIN-LOTE',
                'expiration_days' => null,
                'van_stock_before_reconcile' => null,
                'restocked_in_machines_qty' => null,
                'withdrawn_spoilage_qty' => null,
                'expected_return_qty' => null,
                'counted_return_qty' => null,
                'discrepancy_qty' => null,
            ],
        ];
    }
    /**
     * Catálogo canónico de productos comerciales y consumibles (Módulo 32 · C3).
     *
     * Los importes cruzan como cadena decimal para que el redondeo binario del
     * flotante no altere un céntimo. La caducidad es `NULL` salvo cuando la
     * cadena de frío la exige (`QA 1`).
     *
     * @return list<array{sku: string, name: string, category: string, unit_type: string, requires_cold_chain: bool, max_shelf_life_days: ?int, unit_cost_eur: string, sale_price_eur: string}>
     */
    private static function canonicalVendingProductRows(): array
    {
        return [
            ['sku' => 'COLA-LATA-33', 'name' => 'Lata de Cola 33cl', 'category' => 'COLD_DRINK', 'unit_type' => 'UNIT', 'requires_cold_chain' => false, 'max_shelf_life_days' => null, 'unit_cost_eur' => '0.35', 'sale_price_eur' => '1.20'],
            ['sku' => 'AGUA-MIN-50', 'name' => 'Agua Mineral Natural 50cl', 'category' => 'COLD_DRINK', 'unit_type' => 'UNIT', 'requires_cold_chain' => false, 'max_shelf_life_days' => null, 'unit_cost_eur' => '0.18', 'sale_price_eur' => '0.90'],
            ['sku' => 'ZUMO-NAR-33', 'name' => 'Zumo de Naranja 33cl', 'category' => 'COLD_DRINK', 'unit_type' => 'UNIT', 'requires_cold_chain' => false, 'max_shelf_life_days' => null, 'unit_cost_eur' => '0.32', 'sale_price_eur' => '1.30'],
            ['sku' => 'SNACK-PATAT-40', 'name' => 'Patatas Fritas Clásicas 40g', 'category' => 'SNACK', 'unit_type' => 'UNIT', 'requires_cold_chain' => false, 'max_shelf_life_days' => null, 'unit_cost_eur' => '0.42', 'sale_price_eur' => '1.10'],
            ['sku' => 'SNACK-FRUTSEC-50', 'name' => 'Frutos Secos Tostados 50g', 'category' => 'SNACK', 'unit_type' => 'UNIT', 'requires_cold_chain' => false, 'max_shelf_life_days' => null, 'unit_cost_eur' => '0.55', 'sale_price_eur' => '1.40'],
            ['sku' => 'BARRA-ENERG-45', 'name' => 'Barrita Energética de Cereales 45g', 'category' => 'SNACK', 'unit_type' => 'UNIT', 'requires_cold_chain' => false, 'max_shelf_life_days' => null, 'unit_cost_eur' => '0.48', 'sale_price_eur' => '1.30'],
            ['sku' => 'SAND-MIXTO-01', 'name' => 'Sándwich Mixto Jamón y Queso Fresco', 'category' => 'PERISHABLE_FOOD', 'unit_type' => 'UNIT', 'requires_cold_chain' => true, 'max_shelf_life_days' => 5, 'unit_cost_eur' => '1.15', 'sale_price_eur' => '2.40'],
            ['sku' => 'ENSALADA-CESAR', 'name' => 'Ensalada César Preparada 220g', 'category' => 'PERISHABLE_FOOD', 'unit_type' => 'UNIT', 'requires_cold_chain' => true, 'max_shelf_life_days' => 4, 'unit_cost_eur' => '1.95', 'sale_price_eur' => '3.60'],
            ['sku' => 'YOGUR-NAT-125', 'name' => 'Yogur Natural Azucarado 125g', 'category' => 'PERISHABLE_FOOD', 'unit_type' => 'UNIT', 'requires_cold_chain' => true, 'max_shelf_life_days' => 20, 'unit_cost_eur' => '0.38', 'sale_price_eur' => '1.10'],
            ['sku' => 'CAFE-GRANO-1K', 'name' => 'Café en Grano Natural 1kg', 'category' => 'HOT_BEVERAGE_INGREDIENT', 'unit_type' => 'GRAMS', 'requires_cold_chain' => false, 'max_shelf_life_days' => null, 'unit_cost_eur' => '9.80', 'sale_price_eur' => '0.00'],
            ['sku' => 'LECHE-POLVO-1K', 'name' => 'Leche en Polvo Espumante 1kg', 'category' => 'HOT_BEVERAGE_INGREDIENT', 'unit_type' => 'GRAMS', 'requires_cold_chain' => false, 'max_shelf_life_days' => null, 'unit_cost_eur' => '6.40', 'sale_price_eur' => '0.00'],
            ['sku' => 'CHOCO-SOLUBLE-1K', 'name' => 'Chocolate en Polvo Soluble 1kg', 'category' => 'HOT_BEVERAGE_INGREDIENT', 'unit_type' => 'GRAMS', 'requires_cold_chain' => false, 'max_shelf_life_days' => null, 'unit_cost_eur' => '5.10', 'sale_price_eur' => '0.00'],
            ['sku' => 'AZUCAR-BLANCO-1K', 'name' => 'Azúcar Blanco 1kg', 'category' => 'HOT_BEVERAGE_INGREDIENT', 'unit_type' => 'GRAMS', 'requires_cold_chain' => false, 'max_shelf_life_days' => null, 'unit_cost_eur' => '1.20', 'sale_price_eur' => '0.00'],
            ['sku' => 'VASOS-7OZ-TIRA', 'name' => 'Tira de Vasos de Cartón 7oz', 'category' => 'CUP_STIRRER_CONSUMABLE', 'unit_type' => 'SLEEVE', 'requires_cold_chain' => false, 'max_shelf_life_days' => null, 'unit_cost_eur' => '1.80', 'sale_price_eur' => '0.00'],
            ['sku' => 'PALETINAS-MAD', 'name' => 'Paletinas Removedoras de Madera', 'category' => 'CUP_STIRRER_CONSUMABLE', 'unit_type' => 'SLEEVE', 'requires_cold_chain' => false, 'max_shelf_life_days' => null, 'unit_cost_eur' => '0.75', 'sale_price_eur' => '0.00'],
        ];
    }

    /**
     * Planograma canónico de las trece máquinas sembradas (Módulo 32 · C3).
     *
     * Los carriles numerados usan el orden físico de bandeja y las tolvas/tubos
     * su código alfabético (`QA 3`). Un carril nace agotado (`VEND-0401.11`) y otro
     * bajo umbral (`VEND-0501.13`) para que el semáforo sea demostrable.
     *
     * @return list<array{machine_code: string, slot_code: string, sku: string, max_capacity: int, current_estimated_qty: int, min_threshold: int}>
     */
    private static function canonicalPlanogramSlotRows(): array
    {
        return [
            ['machine_code' => 'VEND-0101', 'slot_code' => '11', 'sku' => 'SAND-MIXTO-01', 'max_capacity' => 10, 'current_estimated_qty' => 4, 'min_threshold' => 3],
            ['machine_code' => 'VEND-0101', 'slot_code' => '12', 'sku' => 'ENSALADA-CESAR', 'max_capacity' => 8, 'current_estimated_qty' => 0, 'min_threshold' => 2],
            ['machine_code' => 'VEND-0101', 'slot_code' => '13', 'sku' => 'YOGUR-NAT-125', 'max_capacity' => 12, 'current_estimated_qty' => 5, 'min_threshold' => 4],
            ['machine_code' => 'VEND-0101', 'slot_code' => '21', 'sku' => 'AGUA-MIN-50', 'max_capacity' => 12, 'current_estimated_qty' => 8, 'min_threshold' => 3],
            ['machine_code' => 'VEND-0301', 'slot_code' => '11', 'sku' => 'SAND-MIXTO-01', 'max_capacity' => 10, 'current_estimated_qty' => 3, 'min_threshold' => 3],
            ['machine_code' => 'VEND-0301', 'slot_code' => '12', 'sku' => 'ENSALADA-CESAR', 'max_capacity' => 8, 'current_estimated_qty' => 2, 'min_threshold' => 2],
            ['machine_code' => 'VEND-0301', 'slot_code' => '13', 'sku' => 'YOGUR-NAT-125', 'max_capacity' => 12, 'current_estimated_qty' => 9, 'min_threshold' => 4],
            ['machine_code' => 'VEND-0301', 'slot_code' => '21', 'sku' => 'AGUA-MIN-50', 'max_capacity' => 12, 'current_estimated_qty' => 6, 'min_threshold' => 3],
            ['machine_code' => 'VEND-0701', 'slot_code' => '11', 'sku' => 'SAND-MIXTO-01', 'max_capacity' => 10, 'current_estimated_qty' => 7, 'min_threshold' => 3],
            ['machine_code' => 'VEND-0701', 'slot_code' => '12', 'sku' => 'ENSALADA-CESAR', 'max_capacity' => 8, 'current_estimated_qty' => 0, 'min_threshold' => 2],
            ['machine_code' => 'VEND-0701', 'slot_code' => '13', 'sku' => 'YOGUR-NAT-125', 'max_capacity' => 12, 'current_estimated_qty' => 4, 'min_threshold' => 4],
            ['machine_code' => 'VEND-0701', 'slot_code' => '21', 'sku' => 'AGUA-MIN-50', 'max_capacity' => 12, 'current_estimated_qty' => 10, 'min_threshold' => 3],
            ['machine_code' => 'VEND-0102', 'slot_code' => 'HOPPER_COFFEE', 'sku' => 'CAFE-GRANO-1K', 'max_capacity' => 2000, 'current_estimated_qty' => 1400, 'min_threshold' => 400],
            ['machine_code' => 'VEND-0102', 'slot_code' => 'HOPPER_MILK', 'sku' => 'LECHE-POLVO-1K', 'max_capacity' => 1500, 'current_estimated_qty' => 900, 'min_threshold' => 300],
            ['machine_code' => 'VEND-0102', 'slot_code' => 'HOPPER_SUGAR', 'sku' => 'AZUCAR-BLANCO-1K', 'max_capacity' => 1000, 'current_estimated_qty' => 700, 'min_threshold' => 200],
            ['machine_code' => 'VEND-0102', 'slot_code' => 'TUBE_CUPS', 'sku' => 'VASOS-7OZ-TIRA', 'max_capacity' => 600, 'current_estimated_qty' => 420, 'min_threshold' => 150],
            ['machine_code' => 'VEND-0302', 'slot_code' => 'HOPPER_COFFEE', 'sku' => 'CAFE-GRANO-1K', 'max_capacity' => 2000, 'current_estimated_qty' => 1800, 'min_threshold' => 400],
            ['machine_code' => 'VEND-0302', 'slot_code' => 'HOPPER_MILK', 'sku' => 'LECHE-POLVO-1K', 'max_capacity' => 1500, 'current_estimated_qty' => 200, 'min_threshold' => 300],
            ['machine_code' => 'VEND-0302', 'slot_code' => 'HOPPER_SUGAR', 'sku' => 'AZUCAR-BLANCO-1K', 'max_capacity' => 1000, 'current_estimated_qty' => 500, 'min_threshold' => 200],
            ['machine_code' => 'VEND-0302', 'slot_code' => 'TUBE_CUPS', 'sku' => 'VASOS-7OZ-TIRA', 'max_capacity' => 600, 'current_estimated_qty' => 0, 'min_threshold' => 150],
            ['machine_code' => 'VEND-0302', 'slot_code' => 'TUBE_STIRRERS', 'sku' => 'PALETINAS-MAD', 'max_capacity' => 400, 'current_estimated_qty' => 260, 'min_threshold' => 100],
            ['machine_code' => 'VEND-0502', 'slot_code' => 'HOPPER_COFFEE', 'sku' => 'CAFE-GRANO-1K', 'max_capacity' => 2000, 'current_estimated_qty' => 1500, 'min_threshold' => 400],
            ['machine_code' => 'VEND-0502', 'slot_code' => 'HOPPER_CHOCOLATE', 'sku' => 'CHOCO-SOLUBLE-1K', 'max_capacity' => 1200, 'current_estimated_qty' => 800, 'min_threshold' => 250],
            ['machine_code' => 'VEND-0502', 'slot_code' => 'HOPPER_SUGAR', 'sku' => 'AZUCAR-BLANCO-1K', 'max_capacity' => 1000, 'current_estimated_qty' => 600, 'min_threshold' => 200],
            ['machine_code' => 'VEND-0502', 'slot_code' => 'TUBE_CUPS', 'sku' => 'VASOS-7OZ-TIRA', 'max_capacity' => 600, 'current_estimated_qty' => 380, 'min_threshold' => 150],
            ['machine_code' => 'VEND-0502', 'slot_code' => 'TUBE_STIRRERS', 'sku' => 'PALETINAS-MAD', 'max_capacity' => 400, 'current_estimated_qty' => 300, 'min_threshold' => 100],
            ['machine_code' => 'VEND-1001', 'slot_code' => 'HOPPER_COFFEE', 'sku' => 'CAFE-GRANO-1K', 'max_capacity' => 2500, 'current_estimated_qty' => 2100, 'min_threshold' => 500],
            ['machine_code' => 'VEND-1001', 'slot_code' => 'HOPPER_MILK', 'sku' => 'LECHE-POLVO-1K', 'max_capacity' => 1800, 'current_estimated_qty' => 1200, 'min_threshold' => 400],
            ['machine_code' => 'VEND-1001', 'slot_code' => 'HOPPER_SUGAR', 'sku' => 'AZUCAR-BLANCO-1K', 'max_capacity' => 1200, 'current_estimated_qty' => 850, 'min_threshold' => 250],
            ['machine_code' => 'VEND-1001', 'slot_code' => 'TUBE_CUPS', 'sku' => 'VASOS-7OZ-TIRA', 'max_capacity' => 800, 'current_estimated_qty' => 640, 'min_threshold' => 200],
            ['machine_code' => 'VEND-0201', 'slot_code' => '11', 'sku' => 'SAND-MIXTO-01', 'max_capacity' => 10, 'current_estimated_qty' => 5, 'min_threshold' => 3],
            ['machine_code' => 'VEND-0201', 'slot_code' => '21', 'sku' => 'SNACK-PATAT-40', 'max_capacity' => 14, 'current_estimated_qty' => 9, 'min_threshold' => 4],
            ['machine_code' => 'VEND-0201', 'slot_code' => '31', 'sku' => 'COLA-LATA-33', 'max_capacity' => 14, 'current_estimated_qty' => 11, 'min_threshold' => 4],
            ['machine_code' => 'VEND-0201', 'slot_code' => 'HOPPER_COFFEE', 'sku' => 'CAFE-GRANO-1K', 'max_capacity' => 2000, 'current_estimated_qty' => 1600, 'min_threshold' => 400],
            ['machine_code' => 'VEND-0201', 'slot_code' => 'TUBE_CUPS', 'sku' => 'VASOS-7OZ-TIRA', 'max_capacity' => 600, 'current_estimated_qty' => 450, 'min_threshold' => 150],
            ['machine_code' => 'VEND-0401', 'slot_code' => '11', 'sku' => 'SAND-MIXTO-01', 'max_capacity' => 10, 'current_estimated_qty' => 0, 'min_threshold' => 3],
            ['machine_code' => 'VEND-0401', 'slot_code' => '21', 'sku' => 'SNACK-PATAT-40', 'max_capacity' => 14, 'current_estimated_qty' => 6, 'min_threshold' => 4],
            ['machine_code' => 'VEND-0401', 'slot_code' => '31', 'sku' => 'COLA-LATA-33', 'max_capacity' => 14, 'current_estimated_qty' => 12, 'min_threshold' => 4],
            ['machine_code' => 'VEND-0401', 'slot_code' => 'HOPPER_COFFEE', 'sku' => 'CAFE-GRANO-1K', 'max_capacity' => 2000, 'current_estimated_qty' => 1300, 'min_threshold' => 400],
            ['machine_code' => 'VEND-0401', 'slot_code' => 'TUBE_CUPS', 'sku' => 'VASOS-7OZ-TIRA', 'max_capacity' => 600, 'current_estimated_qty' => 300, 'min_threshold' => 150],
            ['machine_code' => 'VEND-0801', 'slot_code' => '11', 'sku' => 'SAND-MIXTO-01', 'max_capacity' => 10, 'current_estimated_qty' => 4, 'min_threshold' => 3],
            ['machine_code' => 'VEND-0801', 'slot_code' => '21', 'sku' => 'SNACK-PATAT-40', 'max_capacity' => 14, 'current_estimated_qty' => 10, 'min_threshold' => 4],
            ['machine_code' => 'VEND-0801', 'slot_code' => '31', 'sku' => 'COLA-LATA-33', 'max_capacity' => 14, 'current_estimated_qty' => 8, 'min_threshold' => 4],
            ['machine_code' => 'VEND-0801', 'slot_code' => 'HOPPER_COFFEE', 'sku' => 'CAFE-GRANO-1K', 'max_capacity' => 2000, 'current_estimated_qty' => 1750, 'min_threshold' => 400],
            ['machine_code' => 'VEND-0801', 'slot_code' => 'TUBE_CUPS', 'sku' => 'VASOS-7OZ-TIRA', 'max_capacity' => 600, 'current_estimated_qty' => 520, 'min_threshold' => 150],
            ['machine_code' => 'VEND-0501', 'slot_code' => '11', 'sku' => 'SNACK-PATAT-40', 'max_capacity' => 16, 'current_estimated_qty' => 12, 'min_threshold' => 5],
            ['machine_code' => 'VEND-0501', 'slot_code' => '12', 'sku' => 'BARRA-ENERG-45', 'max_capacity' => 16, 'current_estimated_qty' => 7, 'min_threshold' => 5],
            ['machine_code' => 'VEND-0501', 'slot_code' => '13', 'sku' => 'SNACK-FRUTSEC-50', 'max_capacity' => 14, 'current_estimated_qty' => 4, 'min_threshold' => 5],
            ['machine_code' => 'VEND-0501', 'slot_code' => '21', 'sku' => 'COLA-LATA-33', 'max_capacity' => 14, 'current_estimated_qty' => 9, 'min_threshold' => 4],
            ['machine_code' => 'VEND-0601', 'slot_code' => '11', 'sku' => 'AGUA-MIN-50', 'max_capacity' => 18, 'current_estimated_qty' => 14, 'min_threshold' => 5],
            ['machine_code' => 'VEND-0601', 'slot_code' => '12', 'sku' => 'COLA-LATA-33', 'max_capacity' => 18, 'current_estimated_qty' => 10, 'min_threshold' => 5],
            ['machine_code' => 'VEND-0601', 'slot_code' => '13', 'sku' => 'ZUMO-NAR-33', 'max_capacity' => 16, 'current_estimated_qty' => 3, 'min_threshold' => 5],
            ['machine_code' => 'VEND-0901', 'slot_code' => '11', 'sku' => 'AGUA-MIN-50', 'max_capacity' => 18, 'current_estimated_qty' => 16, 'min_threshold' => 5],
            ['machine_code' => 'VEND-0901', 'slot_code' => '12', 'sku' => 'COLA-LATA-33', 'max_capacity' => 18, 'current_estimated_qty' => 12, 'min_threshold' => 5],
            ['machine_code' => 'VEND-0901', 'slot_code' => '13', 'sku' => 'ZUMO-NAR-33', 'max_capacity' => 16, 'current_estimated_qty' => 8, 'min_threshold' => 5],
        ];
    }

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
            // El cuadrante de carga (Módulo 31) se siembra cuando usuarios y zonas
            // ya existen: lo resuelve por la clave canónica del reponedor y por el
            // código de la zona.
            $zoneRestockerCount = $this->seedRestockerZoneAssignments();
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
            // Catálogo comercial y planogramas del Módulo 32 (C3): las referencias
            // entran antes que los carriles, que las resuelven por `sku`. El
            // planograma se inserta solo si no existe, para no pisar existencias.
            $vendingProductCount = $this->seedVendingProducts();
            $planogramSlotCount = $this->seedMachinePlanograms();
            // El stock por lote de las furgonetas (Módulo 33) se siembra después
            // del catálogo: resuelve reponedor por `email_key` y producto por `sku`,
            // y no reafirma existencias operativas al resembrar.
            $restockerVanStockCount = $this->seedRestockerVanProductStocks();
            // Las órdenes de carga demo cuelgan del reponedor y de sus lotes: son
            // hechos históricos que una resemilla no reescribe.
            $productLoadingOrderCount = $this->seedProductLoadingOrders();
            // Las guías de diagnóstico (Módulo 28) resuelven repuesto y causa por sus
            // códigos: entran cuando ambas maestras ya están sembradas. `Fas Perla`
            // queda sin guía a propósito para el indicador de cobertura (QA 1).
            $troubleshootingGuideCount = $this->seedTroubleshootingGuides();
            // El saldo de furgoneta se siembra después del catálogo maestro: lo resuelve
            // por `part_code` y por la clave canónica del técnico (Módulo 18).
            $vanStockCount = $this->seedVanStocks();
            // Las órdenes de carga demo (Módulo 23) cuelgan de técnico y repuesto,
            // así que entran cuando ambas maestras ya están sembradas.
            $vanRestockOrderCount = $this->seedVanRestockOrders();
            // El libro de idempotencia del Módulo 24 se apoya en las órdenes
            // preventivas ya sembradas: sin ellas no hay parada a la que apuntar.
            $offlineSyncMutationCount = $this->seedOfflineSyncMutations();
            // La cadena de custodia del Módulo 26 no tiene literales propios: se
            // reconstruye para los expedientes que ya existan sin tramos. Sobre un
            // parque recién sembrado devuelve 0 y no inventa averías.
            $custodyAssignmentCount = $this->seedIncidentAssignments();
            // La bandeja de taller del Módulo 27 cuelga de sustituciones reales: el
            // sembrador crea sus líneas demo sobre la orden preventiva ya sembrada y
            // las reparte por los cinco estados del ciclo (D1). Reejecutarlo no
            // duplica unidades ni reescribe un dictamen (Art. III.3).
            $workshopRepairItemCount = $this->seedWorkshopRepairItems();
            // Las actas de merma (Módulo 29) cuelgan de máquina, técnico y
            // coordinador ya sembrados: entran al final para no depender del
            // orden de las maestras y no reafirman ningún hecho sanitario.
            $spoilageRecordCount = $this->seedSanitarySpoilageRecords();

            $this->pdo->commit();

            return [
                'locations' => $locCount,
                'machines' => $machCount,
                'users' => $userCount,
                'operational_zones' => $zoneCount,
                'zone_postal_codes' => $zonePostalCodeCount,
                'technician_zone_assignments' => $zoneTechnicianCount,
                'restocker_zone_assignments' => $zoneRestockerCount,
                'preventive_settings' => $prevCount,
                'root_causes' => $taxonomyCounts['root_causes'],
                'standard_solutions' => $taxonomyCounts['standard_solutions'],
                'preventive_checklists' => $checklistOrders,
                'preventive_audit_events' => $auditEvents,
                'spare_parts' => $partsRes['parts'],
                'spare_part_compatibilities' => $partsRes['compatibilities'],
                'vending_products' => $vendingProductCount,
                'machine_planogram_slots' => $planogramSlotCount,
                'restocker_van_product_stocks' => $restockerVanStockCount,
                'product_loading_orders' => $productLoadingOrderCount,
                'troubleshooting_guides' => $troubleshootingGuideCount,
                'technician_van_stocks' => $vanStockCount,
                'van_restock_orders' => $vanRestockOrderCount,
                'offline_sync_mutations' => $offlineSyncMutationCount,
                'incident_assignments' => $custodyAssignmentCount,
                'workshop_repair_items' => $workshopRepairItemCount,
                'sanitary_spoilage_records' => $spoilageRecordCount,
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

    /**
     * Cuadrante de carga demo (Módulo 31): un titular y su suplente.
     *
     * `REP-01` es Titular (`PRIMARY`) de la zona centro y `REP-02` su suplente
     * (`BACKUP`) allí y en la zona sur. Es el mismo cuadrante que declara
     * `database/supabase_seeds.sql` (sección 11 ·b), y se resuelve por la clave
     * canónica del usuario y el código de la zona, nunca por identificadores.
     *
     * @return list<array{email: string, zone_code: string, role_in_zone: string, is_primary: bool}>
     */
    private static function canonicalRestockerZoneRows(): array
    {
        return [
            ['email' => 'lucia.repostaje@vendguard.internal', 'zone_code' => 'ZONA-BCN-CENTRO', 'role_in_zone' => 'PRIMARY', 'is_primary' => true],
            ['email' => 'oscar.repostaje@vendguard.internal', 'zone_code' => 'ZONA-BCN-CENTRO', 'role_in_zone' => 'BACKUP', 'is_primary' => false],
            ['email' => 'oscar.repostaje@vendguard.internal', 'zone_code' => 'ZONA-BCN-SUR', 'role_in_zone' => 'BACKUP', 'is_primary' => false],
        ];
    }

    /**
     * Siembra el cuadrante de carga de los reponedores (Módulo 31).
     *
     * `role_in_zone` e `is_primary` viajan siempre sincronizados porque la base
     * los ata con `chk_restocker_zone_role_coherence` (QA 1): el sembrador no
     * puede declarar una combinación que la restricción rechazaría. Como en el
     * cuadrante técnico, la alta es «insertar si no existe» y la baja es lógica,
     * de modo que resembrar no reabre una vinculación retirada por coordinación.
     *
     * @return int Vinculaciones canónicas de carga efectivamente vivas tras sembrar.
     */
    public function seedRestockerZoneAssignments(): int
    {
        $identityColumn = $this->dialect->requiresCanonicalIdentityColumns() ? 'email_key' : 'email';
        $sqlTemplate = 'INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
             SELECT u.id, z.id, :role_in_zone, %s
               FROM users u, operational_zones z
              WHERE u.%s = :email
                AND z.code = :zone_code
                AND NOT EXISTS (
                    SELECT 1 FROM restocker_zone_assignments a
                     WHERE a.restocker_id = u.id
                       AND a.zone_id = z.id
                       AND a.removed_at IS NULL
                )';

        $verify = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*)
               FROM restocker_zone_assignments a
               INNER JOIN users u ON u.id = a.restocker_id
               INNER JOIN operational_zones z ON z.id = a.zone_id
              WHERE u.%s = :email
                AND z.code = :zone_code
                AND a.removed_at IS NULL',
            $identityColumn
        ));

        $count = 0;
        foreach (self::canonicalRestockerZoneRows() as $row) {
            $statement = $this->pdo->prepare(sprintf(
                $sqlTemplate,
                $this->dialect->booleanLiteral((bool)$row['is_primary']),
                $identityColumn
            ));
            $statement->execute([
                ':email' => $row['email'],
                ':zone_code' => $row['zone_code'],
                ':role_in_zone' => $row['role_in_zone'],
            ]);

            $verify->execute([
                ':email' => $row['email'],
                ':zone_code' => $row['zone_code'],
            ]);
            $count += (int)$verify->fetchColumn();
        }

        return $count;
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
                'sla_tier' => 'CRITICAL_INFRASTRUCTURE_24_7',
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
                'sla_tier' => 'PRIORITY_CORPORATE',
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
                'sla_tier' => 'CRITICAL_INFRASTRUCTURE_24_7',
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
                'sla_tier' => 'PRIORITY_CORPORATE',
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
                'sla_tier' => 'STANDARD',
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
                'sla_tier' => 'STANDARD',
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
                'sla_tier' => 'CRITICAL_INFRASTRUCTURE_24_7',
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
                'sla_tier' => 'PRIORITY_CORPORATE',
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
                'sla_tier' => 'STANDARD',
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
                'sla_tier' => 'PRIORITY_CORPORATE',
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
            // Módulo 31 · los dos reponedores semilla: el titular de carga de la
            // zona centro y su suplente. La guardia técnica del Art. II no admite
            // este rol, así que nacen con `is_on_call` en su valor por defecto
            // (FALSE) y nunca se marcan como técnicos de guardia.
            [
                'name' => 'Lucía Reponedora BCN',
                'email' => 'lucia.repostaje@vendguard.internal',
                'password_hash' => $passwordHash,
                'role' => 'RESTOCKER',
                'operator_code' => 'REP-01',
                'phone' => '677334455',
            ],
            [
                'name' => 'Óscar Reponedor BCN',
                'email' => 'oscar.repostaje@vendguard.internal',
                'password_hash' => $passwordHash,
                'role' => 'RESTOCKER',
                'operator_code' => 'REP-02',
                'phone' => '677445566',
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