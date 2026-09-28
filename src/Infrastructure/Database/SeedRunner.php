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
                'contact_name' => 'Laura Sanitaria',
                'contact_phone' => '600111222',
            ],
            [
                'site_code' => 'SEDE-BCN-02',
                'name' => 'Torre Glòries - Planta 4 Oficinas',
                'address' => 'Avinguda Diagonal 211, Barcelona',
                'contact_name' => 'Marc Recepción',
                'contact_phone' => '600333444',
            ],
        ];

        $sql = "
            INSERT INTO `locations` (`site_code`, `name`, `address`, `contact_name`, `contact_phone`, `is_active`)
            VALUES (:site_code, :name, :address, :contact_name, :contact_phone, 1)
            ON DUPLICATE KEY UPDATE
                `name` = VALUES(`name`),
                `address` = VALUES(`address`),
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
                ':contact_name' => $loc['contact_name'],
                ':contact_phone' => $loc['contact_phone'],
            ]);
            $count++;
        }

        return $count;
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
            ];
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Error en transacción de semillas: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }
}
