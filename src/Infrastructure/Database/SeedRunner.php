<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use PDO;
use PDOException;
use RuntimeException;

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
     * Carga programática de sedes iniciales.
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

        $sql = "
            INSERT INTO `locations` (`site_code`, `name`, `address`, `latitude`, `longitude`, `contact_name`, `contact_phone`, `is_active`)
            VALUES (:site_code, :name, :address, :latitude, :longitude, :contact_name, :contact_phone, 1)
            ON DUPLICATE KEY UPDATE
                `name` = VALUES(`name`),
                `address` = VALUES(`address`),
                `latitude` = VALUES(`latitude`),
                `longitude` = VALUES(`longitude`),
                `contact_name` = VALUES(`contact_name`),
                `contact_phone` = VALUES(`contact_phone`),
                `deleted_at` = NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $count = 0;

        foreach ($locations as $loc) {
            $stmt->execute([
                ':site_code' => $loc['site_code'],
                ':name' => $loc['name'],
                ':address' => $loc['address'],
                ':latitude' => $loc['latitude'],
                ':longitude' => $loc['longitude'],
                ':contact_name' => $loc['contact_name'],
                ':contact_phone' => $loc['contact_phone'],
            ]);
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
            $partsRes = $this->seedSpareParts();

            $this->pdo->commit();

            return [
                'locations' => $locCount,
                'machines' => $machCount,
                'users' => $userCount,
                'preventive_settings' => $prevCount,
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
