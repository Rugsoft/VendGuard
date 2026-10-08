<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Core\Domain\Service\SiteAccessCodeGenerator;

/**
 * SeedRunner
 * 
 * Gestiona la inserción y actualización de datos semilla (Seed Data) en MariaDB/MySQL.
 * Garantiza idempotencia (ON DUPLICATE KEY UPDATE) y cifrado seguro Bcrypt para contraseñas.
 * 
 * Respeta el Dogma Vanilla y el principio de Clean Architecture.
 */
class SeedRunner
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
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
        $locations = [
            [
                'site_code' => 'SEDE-BCN-01',
                'name' => 'Hospital del Mar - Edificio Central',
                'address' => 'Passeig Marítim 25, Barcelona',
                'latitude' => 41.3853120,
                'longitude' => 2.1932450,
                'contact_name' => 'Laura Sanitaria',
                'contact_phone' => '600111222',
            ],
            [
                'site_code' => 'SEDE-BCN-02',
                'name' => 'Torre Glòries - Planta 4 Oficinas',
                'address' => 'Avinguda Diagonal 211, Barcelona',
                'latitude' => 41.4036290,
                'longitude' => 2.1895120,
                'contact_name' => 'Marc Recepción',
                'contact_phone' => '600333444',
            ],
            [
                'site_code' => 'SEDE-BCN-03',
                'name' => 'Hospital Universitari de Bellvitge',
                'address' => 'Carrer de la Feixa Llarga s/n, L\'Hospitalet de Llobregat',
                'latitude' => 41.3458200,
                'longitude' => 2.1075400,
                'contact_name' => 'Carles Coordinador',
                'contact_phone' => '600555666',
            ],
            [
                'site_code' => 'SEDE-BCN-04',
                'name' => 'World Trade Center Barcelona',
                'address' => 'Moll de Barcelona s/n, Barcelona',
                'latitude' => 41.3725000,
                'longitude' => 2.1819000,
                'contact_name' => 'Núria Port',
                'contact_phone' => '600777888',
            ],
            [
                'site_code' => 'SEDE-BCN-05',
                'name' => 'Campus Nord UPC - Edifici Nexus',
                'address' => 'Carrer del Gran Capità 2, Barcelona',
                'latitude' => 41.3888000,
                'longitude' => 2.1123000,
                'contact_name' => 'Albert Campus',
                'contact_phone' => '600999000',
            ],
            [
                'site_code' => 'SEDE-BCN-06',
                'name' => 'Parc Tecnològic Barcelona Activa',
                'address' => 'Carrer Marie Curie 8, Nou Barris, Barcelona',
                'latitude' => 41.4392000,
                'longitude' => 2.1758000,
                'contact_name' => 'Clara Innovació',
                'contact_phone' => '611222333',
            ],
            [
                'site_code' => 'SEDE-BCN-07',
                'name' => 'Badalona Centre Mèdic Can Ruti',
                'address' => 'Carretera de Canyet s/n, Badalona',
                'latitude' => 41.4645000,
                'longitude' => 2.2421000,
                'contact_name' => 'Sergi Logística',
                'contact_phone' => '611444555',
            ],
            [
                'site_code' => 'SEDE-BCN-08',
                'name' => 'Sant Cugat Trade Center',
                'address' => 'Avinguda de les Corts Catalanes 5, Sant Cugat del Vallès',
                'latitude' => 41.4712000,
                'longitude' => 2.0689000,
                'contact_name' => 'Gemma Gestió',
                'contact_phone' => '611666777',
            ],
            [
                'site_code' => 'SEDE-BCN-09',
                'name' => 'Fira Gran Via - Pavelló 1',
                'address' => 'Avinguda Joan Carles I 64, L\'Hospitalet de Llobregat',
                'latitude' => 41.3547000,
                'longitude' => 2.1284000,
                'contact_name' => 'Pau Esdeveniments',
                'contact_phone' => '611888999',
            ],
            [
                'site_code' => 'SEDE-BCN-10',
                'name' => 'WTC Almeda Park Cornellà',
                'address' => 'Plaça de la Pau s/n, Cornellà de Llobregat',
                'latitude' => 41.3533000,
                'longitude' => 2.0862000,
                'contact_name' => 'Mireia Serveis',
                'contact_phone' => '622111222',
            ],
        ];

        $seedDevAccessCodes = self::devAccessCodeSeedingEnabled();
        $extraColumns = $seedDevAccessCodes
            ? ', `access_code_hash`, `access_code_issued_at`, `login_attempts`, `login_locked_until`'
            : '';
        $extraValues = $seedDevAccessCodes ? ', :access_code_hash, CURRENT_TIMESTAMP, 0, NULL' : '';
        $extraUpdates = $seedDevAccessCodes
            ? ",
                `access_code_hash` = VALUES(`access_code_hash`),
                `access_code_issued_at` = VALUES(`access_code_issued_at`),
                `login_attempts` = 0,
                `login_locked_until` = NULL"
            : '';

        $sql = "
            INSERT INTO `locations` (`site_code`, `name`, `address`, `latitude`, `longitude`, `contact_name`, `contact_phone`, `is_active`{$extraColumns})
            VALUES (:site_code, :name, :address, :latitude, :longitude, :contact_name, :contact_phone, 1{$extraValues})
            ON DUPLICATE KEY UPDATE
                `name` = VALUES(`name`),
                `address` = VALUES(`address`),
                `latitude` = VALUES(`latitude`),
                `longitude` = VALUES(`longitude`),
                `contact_name` = VALUES(`contact_name`),
                `contact_phone` = VALUES(`contact_phone`),
                `deleted_at` = NULL{$extraUpdates}
        ";

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
            ];
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
     * Carga la configuración singleton de la Base Central de rutas.
     *
     * @return int Número de configuraciones procesadas.
     */
    public function seedRouteSettings(): int
    {
        $sql = "
            INSERT INTO `route_settings` (
                `id`, `base_name`, `base_address`, `base_latitude`, `base_longitude`,
                `operational_radius_km`, `min_latitude`, `max_latitude`, `min_longitude`, `max_longitude`
            ) VALUES (
                1, :base_name, :base_address, :base_latitude, :base_longitude,
                100, 27.0, 44.5, -18.5, 5.0
            )
            ON DUPLICATE KEY UPDATE `id` = VALUES(`id`)
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
        $machines = [
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

        $sql = "
            INSERT INTO `machines` (`location_id`, `code`, `model`, `machine_type`, `floor_wing`, `notes`, `is_active`, `sanitary_status`, `next_sanitary_inspection_due`)
            VALUES (
                (SELECT `id` FROM `locations` WHERE `site_code` = :site_code LIMIT 1),
                :code,
                :model,
                :machine_type,
                :floor_wing,
                :notes,
                1,
                'OK',
                DATE_ADD(CURRENT_DATE(), INTERVAL :due_days DAY)
            )
            ON DUPLICATE KEY UPDATE
                `model` = VALUES(`model`),
                `machine_type` = VALUES(`machine_type`),
                `floor_wing` = VALUES(`floor_wing`),
                `notes` = VALUES(`notes`),
                `sanitary_status` = VALUES(`sanitary_status`),
                `next_sanitary_inspection_due` = VALUES(`next_sanitary_inspection_due`),
                `deleted_at` = NULL
        ";

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

        $users = [
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

        $sql = "
            INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `operator_code`, `phone`, `is_active`)
            VALUES (:name, :email, :password_hash, :role, :operator_code, :phone, 1)
            ON DUPLICATE KEY UPDATE
                `name` = VALUES(`name`),
                `password_hash` = VALUES(`password_hash`),
                `role` = VALUES(`role`),
                `operator_code` = VALUES(`operator_code`),
                `phone` = VALUES(`phone`),
                `deleted_at` = NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $count = 0;

        foreach ($users as $user) {
            $stmt->execute([
                ':name' => $user['name'],
                ':email' => $user['email'],
                ':password_hash' => $user['password_hash'],
                ':role' => $user['role'],
                ':operator_code' => $user['operator_code'],
                ':phone' => $user['phone'],
            ]);
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

        $sql = "
            INSERT INTO `preventive_settings` (`machine_type`, `default_frequency_days`, `max_allowed_days`, `advance_warning_days`)
            VALUES (:machine_type, :default_frequency_days, :max_allowed_days, :advance_warning_days)
            ON DUPLICATE KEY UPDATE
                `default_frequency_days` = VALUES(`default_frequency_days`),
                `max_allowed_days` = VALUES(`max_allowed_days`),
                `advance_warning_days` = VALUES(`advance_warning_days`)
        ";

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
     * Carga programática de órdenes preventivas y certificados sanitarios iniciales.
     *
     * @return array{orders: int, certificates: int}
     */
    public function seedPreventiveOrdersAndCertificates(): array
    {
        $sqlOrders = "
            INSERT INTO `preventive_orders` (
                `order_code`, `machine_id`, `location_id`, `assigned_technician_id`,
                `status`, `order_type`, `scheduled_date`, `due_date`, `started_at`, `completed_at`,
                `temperature_measured`, `result`, `is_quarantine_triggered`, `notes`
            ) VALUES
            (
                'ORD-PREV-2026-0001',
                (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0101' LIMIT 1),
                (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01' LIMIT 1),
                (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
                'COMPLETED',
                'ROUTINE',
                DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY),
                CURRENT_DATE(),
                DATE_SUB(NOW(), INTERVAL 1 DAY),
                DATE_SUB(NOW(), INTERVAL 1 DAY),
                3.2,
                'CONFORME',
                0,
                'Inspección higiénico-sanitaria inicial conforme. Temperatura en rango seguro (Art. II).'
            ),
            (
                'ORD-PREV-2026-0002',
                (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0201' LIMIT 1),
                (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-02' LIMIT 1),
                (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
                'COMPLETED',
                'ROUTINE',
                DATE_SUB(CURRENT_DATE(), INTERVAL 2 DAY),
                CURRENT_DATE(),
                DATE_SUB(NOW(), INTERVAL 2 DAY),
                DATE_SUB(NOW(), INTERVAL 2 DAY),
                3.8,
                'CONFORME',
                0,
                'Inspección periódica inicial conforme.'
            ),
            (
                'ORD-PREV-2026-0003',
                (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0102' LIMIT 1),
                (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01' LIMIT 1),
                NULL,
                'PENDING_ASSIGNMENT',
                'ROUTINE',
                DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY),
                DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY),
                NULL,
                NULL,
                NULL,
                NULL,
                0,
                'Revisión preventiva inminente pendiente de asignación técnica.'
            )
            ON DUPLICATE KEY UPDATE
                `status` = VALUES(`status`),
                `result` = VALUES(`result`)
        ";

        $this->pdo->exec($sqlOrders);

        $sqlCerts = "
            INSERT INTO `sanitary_certificates` (
                `certificate_code`, `preventive_order_id`, `machine_id`, `location_id`,
                `technician_id`, `technician_name`, `technician_operator_code`,
                `inspection_date`, `valid_until`, `temperature_measured`,
                `result`, `status`
            ) VALUES
            (
                'CERT-2026-0001',
                (SELECT `id` FROM `preventive_orders` WHERE `order_code` = 'ORD-PREV-2026-0001' LIMIT 1),
                (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0101' LIMIT 1),
                (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-01' LIMIT 1),
                (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
                'Jordi Técnico Ruta BCN',
                'OP-01',
                DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY),
                DATE_ADD(CURRENT_DATE(), INTERVAL 14 DAY),
                3.2,
                'CONFORME',
                'VALID'
            ),
            (
                'CERT-2026-0002',
                (SELECT `id` FROM `preventive_orders` WHERE `order_code` = 'ORD-PREV-2026-0002' LIMIT 1),
                (SELECT `id` FROM `machines` WHERE `code` = 'VEND-0201' LIMIT 1),
                (SELECT `id` FROM `locations` WHERE `site_code` = 'SEDE-BCN-02' LIMIT 1),
                (SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1),
                'Jordi Técnico Ruta BCN',
                'OP-01',
                DATE_SUB(CURRENT_DATE(), INTERVAL 2 DAY),
                DATE_ADD(CURRENT_DATE(), INTERVAL 13 DAY),
                3.8,
                'CONFORME',
                'VALID'
            )
            ON DUPLICATE KEY UPDATE
                `valid_until` = VALUES(`valid_until`),
                `status` = VALUES(`status`)
        ";

        $this->pdo->exec($sqlCerts);

        $this->pdo->exec("
            UPDATE `machines` SET
                `last_sanitary_inspection_at` = DATE_SUB(NOW(), INTERVAL 1 DAY),
                `next_sanitary_inspection_due` = DATE_ADD(CURRENT_DATE(), INTERVAL 14 DAY),
                `sanitary_status` = 'OK'
            WHERE `code` = 'VEND-0101'
        ");

        $this->pdo->exec("
            UPDATE `machines` SET
                `last_sanitary_inspection_at` = DATE_SUB(NOW(), INTERVAL 2 DAY),
                `next_sanitary_inspection_due` = DATE_ADD(CURRENT_DATE(), INTERVAL 13 DAY),
                `sanitary_status` = 'OK'
            WHERE `code` = 'VEND-0201'
        ");

        $this->pdo->exec("
            UPDATE `machines` SET
                `last_sanitary_inspection_at` = DATE_SUB(NOW(), INTERVAL 28 DAY),
                `next_sanitary_inspection_due` = DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY),
                `sanitary_status` = 'OK'
            WHERE `code` = 'VEND-0102'
        ");

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
     * no hay autoloader cargado, así que no puede depender de otras clases del proyecto.
     *
     * Sin estas respuestas, la ficha de detalle de la orden mostraría el bloque de
     * checklist vacío y no habría nada que auditar desde Coordinación.
     *
     * @return int Número de órdenes cuyo checklist quedó sembrado.
     */
    public function seedPreventiveChecklistItems(): int
    {
        $selectOrder = $this->pdo->prepare("
            SELECT `id`
            FROM `preventive_orders`
            WHERE `order_code` = :order_code
            LIMIT 1
        ");
        $selectItem = $this->pdo->prepare("
            SELECT `id`
            FROM `preventive_order_items`
            WHERE `preventive_order_id` = :order_id
              AND `item_code` = :item_code
            LIMIT 1
        ");
        $updateItem = $this->pdo->prepare("
            UPDATE `preventive_order_items`
            SET
                `item_description` = :item_description,
                `is_critical` = :is_critical,
                `status` = :status,
                `observations` = :observations
            WHERE `id` = :id
        ");
        $insertItem = $this->pdo->prepare("
            INSERT INTO `preventive_order_items` (
                `preventive_order_id`,
                `item_code`,
                `item_description`,
                `is_critical`,
                `status`,
                `observations`
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
            SELECT `id`, `order_code`, `machine_id`, `status`, `assigned_technician_id`, `completed_at`
            FROM `preventive_orders`
            WHERE `order_code` = :order_code
            LIMIT 1
        ");
        $existsEvent = $this->pdo->prepare("
            SELECT `id`
            FROM `audit_log`
            WHERE `entity_type` = 'MACHINE'
              AND `entity_id` = :machine_id
              AND `action` = :action
              AND JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.\"order_code\"')) = :order_code
            LIMIT 1
        ");
        $insertEvent = $this->pdo->prepare("
            INSERT INTO `audit_log` (
                `entity_type`, `entity_id`, `action`, `user_id`, `user_role`, `user_name`,
                `previous_state`, `new_state`, `metadata`, `created_at`
            ) VALUES (
                'MACHINE', :machine_id, :action, :user_id, :user_role, :user_name,
                :previous_state, :new_state, :metadata, :created_at
            )
        ");

        $coordinatorId = (int)($this->pdo->query(
            "SELECT `id` FROM `users` WHERE `email` = 'coordinacion@vendguard.internal' LIMIT 1"
        )->fetchColumn() ?: 1);
        $technicianId = (int)($this->pdo->query(
            "SELECT `id` FROM `users` WHERE `email` = 'jordi.ruta@vendguard.internal' LIMIT 1"
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

        $sqlPart = "
            INSERT INTO `spare_parts` (`part_code`, `name`, `category`, `manufacturer`, `reference_cost`, `is_active`, `notes`)
            VALUES (:part_code, :name, :category, :manufacturer, :reference_cost, :is_active, :notes)
            ON DUPLICATE KEY UPDATE
                `name` = VALUES(`name`),
                `category` = VALUES(`category`),
                `manufacturer` = VALUES(`manufacturer`),
                `reference_cost` = VALUES(`reference_cost`),
                `is_active` = VALUES(`is_active`),
                `notes` = VALUES(`notes`)
        ";
        $stmtPart = $this->pdo->prepare($sqlPart);

        $sqlCompat = "
            INSERT INTO `spare_part_compatibilities` (`spare_part_id`, `machine_model`)
            VALUES ((SELECT `id` FROM `spare_parts` WHERE `part_code` = :part_code LIMIT 1), :machine_model)
            ON DUPLICATE KEY UPDATE `machine_model` = VALUES(`machine_model`)
        ";
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
            $prevCount = $this->seedPreventiveSettings();
            $this->seedPreventiveOrdersAndCertificates();
            $checklistOrders = $this->seedPreventiveChecklistItems();
            $auditEvents = $this->seedPreventiveAuditTrail();
            $partsRes = $this->seedSpareParts();

            $this->pdo->commit();

            return [
                'locations' => $locCount,
                'machines' => $machCount,
                'users' => $userCount,
                'preventive_settings' => $prevCount,
                'preventive_checklists' => $checklistOrders,
                'preventive_audit_events' => $auditEvents,
                'spare_parts' => $partsRes['parts'],
                'spare_part_compatibilities' => $partsRes['compatibilities'],
            ];
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Error en transacción de semillas: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }
}
