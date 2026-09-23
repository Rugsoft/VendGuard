<?php

declare(strict_types=1);

namespace VendGuard\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * DemoMetricsSeeder
 * 
 * Puebla la base de datos con un historial representativo y realista de:
 * 1. Averías resueltas y cerradas con diagnósticos técnicos y acciones de resolución (Art. V.1).
 * 2. Tiempos de resolución (MTTR) que demuestran cumplimiento de SLA (< 4.0h en perecederos).
 * 3. Incidencias en distintos estados: en garantía (RESOLVED) y cerradas (CLOSED).
 * 4. Trazabilidad completa en `audit_log` con eventos de creación, asignación, inicio, resolución y cierre.
 * 
 * Idempotente: utiliza ON DUPLICATE KEY UPDATE respetando el Artículo III.
 */
class DemoMetricsSeeder
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Ejecuta el sembrado de métricas, reparaciones e histórico de auditoría.
     *
     * @return array<string, int> Conteo de registros insertados.
     */
    public function seed(): array
    {
        $this->ensureUsers();

        $locMap = $this->getLocationMap();
        $machMap = $this->getMachineMap();
        $userMap = $this->getUserMap();

        if (empty($locMap) || empty($machMap) || empty($userMap)) {
            throw new RuntimeException("No se encontraron sedes, máquinas o usuarios base para sembrar métricas.");
        }

        $jordiId = $userMap['jordi.ruta@vendguard.internal'] ?? null;
        $martaId = $userMap['marta.ruta@vendguard.internal'] ?? null;
        $saraId = $userMap['coordinacion@vendguard.internal'] ?? null;

        if ($jordiId === null || $martaId === null || $saraId === null) {
            throw new RuntimeException("No se pudieron resolver los IDs de los usuarios técnicos y coordinadora.");
        }

        $vend0101 = $machMap['VEND-0101'] ?? null; // PERISHABLE_FOOD (Hospital del Mar)
        $vend0102 = $machMap['VEND-0102'] ?? null; // HOT_DRINKS (Hospital del Mar)
        $vend0201 = $machMap['VEND-0201'] ?? null; // COMBO (Torre Glòries)

        if ($vend0101 === null || $vend0102 === null || $vend0201 === null) {
            throw new RuntimeException("No se pudieron resolver las máquinas base VEND-0101, VEND-0102, VEND-0201.");
        }

        $this->pdo->beginTransaction();

        try {
            // Definición de averías y reparaciones con fechas realistas
            $incidents = [
                // 1. Período anterior (Agosto 2026): VEND-0101 Frío perecedero resuelto en 2h 10m (cumple SLA 4h)
                [
                    'ticket_code' => 'INC-DEMO-0810',
                    'machine_id' => $vend0101['id'],
                    'location_id' => $vend0101['location_id'],
                    'assigned_technician_id' => $jordiId,
                    'reporter_name' => 'Dra. Carmen Morales',
                    'reporter_phone' => '611223344',
                    'category' => 'TEMPERATURE_COLD',
                    'description' => 'Temperatura en cuba de sándwiches a 9.2°C con aviso acústico intermitente en display.',
                    'urgency' => 'CRITICAL',
                    'status' => 'CLOSED',
                    'created_at' => '2026-08-10 08:30:00',
                    'assigned_at' => '2026-08-10 08:45:00',
                    'started_at' => '2026-08-10 09:10:00',
                    'resolved_at' => '2026-08-10 10:40:00', // 130 min (2h 10m)
                    'closed_at' => '2026-08-12 10:40:00',
                    'diagnosis' => 'Sonda de temperatura NTC averiada por pico de tensión registrando falsos 12°C en cuba.',
                    'action' => 'Sustitución de sonda NTC y ajuste de parámetros de histéresis a 3.5°C estables.',
                    'tech_name' => 'Jordi Técnico Ruta BCN',
                ],

                // 2. Período anterior (Agosto 2026): VEND-0102 Café caliente resuelto en 3h 45m
                [
                    'ticket_code' => 'INC-DEMO-0822',
                    'machine_id' => $vend0102['id'],
                    'location_id' => $vend0102['location_id'],
                    'assigned_technician_id' => $martaId,
                    'reporter_name' => 'Enrique Vigilancia',
                    'reporter_phone' => '622334455',
                    'category' => 'PRODUCT_JAM',
                    'description' => 'El café no cae en vaso y sale vapor denso por la ranura de erogación.',
                    'urgency' => 'MEDIUM',
                    'status' => 'CLOSED',
                    'created_at' => '2026-08-22 14:00:00',
                    'assigned_at' => '2026-08-22 14:30:00',
                    'started_at' => '2026-08-22 15:15:00',
                    'resolved_at' => '2026-08-22 17:45:00', // 225 min (3h 45m)
                    'closed_at' => '2026-08-24 17:45:00',
                    'diagnosis' => 'Atasco del grupo de infusión de café por apelmazamiento de molienda excesivamente fina.',
                    'action' => 'Desmontaje del grupo erogador, limpieza por inmersión y reajuste del micrométrico del molino.',
                    'tech_name' => 'Marta Técnica Ruta BCN',
                ],

                // 3. Período anterior (Agosto 2026): VEND-0201 Pago con tarjeta resuelto en 3h 30m
                [
                    'ticket_code' => 'INC-DEMO-0828',
                    'machine_id' => $vend0201['id'],
                    'location_id' => $vend0201['location_id'],
                    'assigned_technician_id' => $jordiId,
                    'reporter_name' => 'Sonia Administrativa',
                    'reporter_phone' => '633445566',
                    'category' => 'PAYMENT_SYSTEM',
                    'description' => 'El datáfono contactless indica error de comunicación y rechaza pagos con tarjeta.',
                    'urgency' => 'HIGH',
                    'status' => 'CLOSED',
                    'created_at' => '2026-08-28 10:15:00',
                    'assigned_at' => '2026-08-28 10:30:00',
                    'started_at' => '2026-08-28 11:00:00',
                    'resolved_at' => '2026-08-28 13:45:00', // 210 min (3h 30m)
                    'closed_at' => '2026-08-30 13:45:00',
                    'diagnosis' => 'Fallo de comunicación del lector contactless por cableado MDB pinzado en puerta.',
                    'action' => 'Reparación y enfundado del mazo de cables MDB y actualización del firmware del lector Nayax.',
                    'tech_name' => 'Jordi Técnico Ruta BCN',
                ],

                // 4. Mes actual (Hace 19 días): VEND-0101 Frío perecedero resuelto en 2h 05m (cumple SLA 4h)
                [
                    'ticket_code' => 'INC-DEMO-0904',
                    'machine_id' => $vend0101['id'],
                    'location_id' => $vend0101['location_id'],
                    'assigned_technician_id' => $jordiId,
                    'reporter_name' => 'Laura Sanitaria',
                    'reporter_phone' => '600111222',
                    'category' => 'TEMPERATURE_COLD',
                    'description' => 'Aviso preventivo: compresor encendido de forma continua con escarcha en evaporador.',
                    'urgency' => 'CRITICAL',
                    'status' => 'CLOSED',
                    'created_at' => '2026-09-04 09:00:00',
                    'assigned_at' => '2026-09-04 09:12:00',
                    'started_at' => '2026-09-04 09:35:00',
                    'resolved_at' => '2026-09-04 11:05:00', // 125 min (2h 05m)
                    'closed_at' => '2026-09-06 11:05:00',
                    'diagnosis' => 'Acumulación de suciedad en la rejilla del ventilador evaporador reduciendo el flujo térmico.',
                    'action' => 'Limpieza profunda con desengrasante alimentario y comprobación del ciclo de desescarche.',
                    'tech_name' => 'Jordi Técnico Ruta BCN',
                ],

                // 5. Mes actual (Hace 13 días): VEND-0201 Atasco snacks resuelto en 2h 50m
                [
                    'ticket_code' => 'INC-DEMO-0910',
                    'machine_id' => $vend0201['id'],
                    'location_id' => $vend0201['location_id'],
                    'assigned_technician_id' => $martaId,
                    'reporter_name' => 'Marc Recepción',
                    'reporter_phone' => '600333444',
                    'category' => 'PRODUCT_JAM',
                    'description' => 'Bolsa de patatas enganchada en la espiral 23 sin caer al cajón de entrega.',
                    'urgency' => 'MEDIUM',
                    'status' => 'CLOSED',
                    'created_at' => '2026-09-10 11:20:00',
                    'assigned_at' => '2026-09-10 11:45:00',
                    'started_at' => '2026-09-10 12:30:00',
                    'resolved_at' => '2026-09-10 14:10:00', // 170 min (2h 50m)
                    'closed_at' => '2026-09-12 14:10:00',
                    'diagnosis' => 'Bolsa de frutos secos trabada en la trampilla basculante de recogida de producto.',
                    'action' => 'Alineación del muelle de retorno de la trampilla y verificación de diez ciclos de dispensación.',
                    'tech_name' => 'Marta Técnica Ruta BCN',
                ],

                // 6. Últimos 7 días (Hace 7 días): VEND-0102 Fuga eléctrica resuelto en 3h 00m
                [
                    'ticket_code' => 'INC-DEMO-0916',
                    'machine_id' => $vend0102['id'],
                    'location_id' => $vend0102['location_id'],
                    'assigned_technician_id' => $jordiId,
                    'reporter_name' => 'Guillermo Mantenimiento',
                    'reporter_phone' => '644556677',
                    'category' => 'ELECTRICAL_OFF',
                    'description' => 'Máquina totalmente apagada. El diferencial del cuadro saltó a primera hora.',
                    'urgency' => 'HIGH',
                    'status' => 'CLOSED',
                    'created_at' => '2026-09-16 07:45:00',
                    'assigned_at' => '2026-09-16 08:00:00',
                    'started_at' => '2026-09-16 08:30:00',
                    'resolved_at' => '2026-09-16 10:45:00', // 180 min (3h 00m)
                    'closed_at' => '2026-09-18 10:45:00',
                    'diagnosis' => 'Fuga de agua en racor de entrada de electroválvula provocando salto diferencial.',
                    'action' => 'Sustitución de racor rápido y tramo de teflón de 6mm con secado completo de la electrónica.',
                    'tech_name' => 'Jordi Técnico Ruta BCN',
                ],

                // 7. Últimos 7 días (Hace 4 días): VEND-0201 Monedero resuelto en 2h 45m
                [
                    'ticket_code' => 'INC-DEMO-0919',
                    'machine_id' => $vend0201['id'],
                    'location_id' => $vend0201['location_id'],
                    'assigned_technician_id' => $martaId,
                    'reporter_name' => 'Patricia RRHH',
                    'reporter_phone' => '655667788',
                    'category' => 'PAYMENT_SYSTEM',
                    'description' => 'Traga monedas de 1 euro sin acreditar saldo ni devolver el importe.',
                    'urgency' => 'LOW',
                    'status' => 'CLOSED',
                    'created_at' => '2026-09-19 15:10:00',
                    'assigned_at' => '2026-09-19 15:30:00',
                    'started_at' => '2026-09-19 16:15:00',
                    'resolved_at' => '2026-09-19 17:55:00', // 165 min (2h 45m)
                    'closed_at' => '2026-09-21 17:55:00',
                    'diagnosis' => 'Lector de monedas rechazaba por acumulación de grasa en las fotocélulas de entrada.',
                    'action' => 'Limpieza de fotocélulas ópticas y recalibración del canal de validación de 1€ y 2€.',
                    'tech_name' => 'Marta Técnica Ruta BCN',
                ],

                // 8. Ayer (Cerrado por garantía): VEND-0101 Display resuelto en 1h 45m
                [
                    'ticket_code' => 'INC-DEMO-0922',
                    'machine_id' => $vend0101['id'],
                    'location_id' => $vend0101['location_id'],
                    'assigned_technician_id' => $jordiId,
                    'reporter_name' => 'Laura Sanitaria',
                    'reporter_phone' => '600111222',
                    'category' => 'OTHER',
                    'description' => 'Display frontal con caracteres ilegibles dificultando la selección de productos.',
                    'urgency' => 'LOW',
                    'status' => 'CLOSED',
                    'created_at' => '2026-09-22 09:15:00',
                    'assigned_at' => '2026-09-22 09:30:00',
                    'started_at' => '2026-09-22 09:50:00',
                    'resolved_at' => '2026-09-22 11:00:00', // 105 min (1h 45m)
                    'closed_at' => '2026-09-23 11:00:00',
                    'diagnosis' => 'Falso contacto en conector cinta ribbon del display LCD frontal de la máquina.',
                    'action' => 'Sustitución de cable plano ribbon y ajuste de los tornillos de fijación del frontal.',
                    'tech_name' => 'Jordi Técnico Ruta BCN',
                ],
            ];

            $insertIncidentStmt = $this->pdo->prepare("
                INSERT INTO `incidents` (
                    `ticket_code`, `machine_id`, `location_id`, `assigned_technician_id`,
                    `reporter_name`, `reporter_phone`, `category`, `description`, `urgency`,
                    `status`, `assigned_at`, `started_at`, `resolved_at`, `closed_at`,
                    `resolution_diagnosis`, `resolution_action`, `created_at`, `updated_at`
                ) VALUES (
                    :ticket_code, :machine_id, :location_id, :assigned_technician_id,
                    :reporter_name, :reporter_phone, :category, :description, :urgency,
                    :status, :assigned_at, :started_at, :resolved_at, :closed_at,
                    :resolution_diagnosis, :resolution_action, :created_at, :updated_at
                )
                ON DUPLICATE KEY UPDATE
                    `machine_id` = VALUES(`machine_id`),
                    `location_id` = VALUES(`location_id`),
                    `assigned_technician_id` = VALUES(`assigned_technician_id`),
                    `category` = VALUES(`category`),
                    `description` = VALUES(`description`),
                    `urgency` = VALUES(`urgency`),
                    `status` = VALUES(`status`),
                    `assigned_at` = VALUES(`assigned_at`),
                    `started_at` = VALUES(`started_at`),
                    `resolved_at` = VALUES(`resolved_at`),
                    `closed_at` = VALUES(`closed_at`),
                    `resolution_diagnosis` = VALUES(`resolution_diagnosis`),
                    `resolution_action` = VALUES(`resolution_action`),
                    `updated_at` = VALUES(`updated_at`),
                    `deleted_at` = NULL
            ");

            $insertAuditStmt = $this->pdo->prepare("
                INSERT INTO `audit_log` (
                    `entity_type`, `entity_id`, `action`, `user_id`, `user_role`, `user_name`,
                    `previous_state`, `new_state`, `metadata`, `created_at`
                ) VALUES (
                    :entity_type, :entity_id, :action, :user_id, :user_role, :user_name,
                    :previous_state, :new_state, :metadata, :created_at
                )
            ");

            $incidentCount = 0;
            $auditCount = 0;

            foreach ($incidents as $inc) {
                $insertIncidentStmt->execute([
                    ':ticket_code' => $inc['ticket_code'],
                    ':machine_id' => $inc['machine_id'],
                    ':location_id' => $inc['location_id'],
                    ':assigned_technician_id' => $inc['assigned_technician_id'],
                    ':reporter_name' => $inc['reporter_name'],
                    ':reporter_phone' => $inc['reporter_phone'],
                    ':category' => $inc['category'],
                    ':description' => $inc['description'],
                    ':urgency' => $inc['urgency'],
                    ':status' => $inc['status'],
                    ':assigned_at' => $inc['assigned_at'],
                    ':started_at' => $inc['started_at'],
                    ':resolved_at' => $inc['resolved_at'],
                    ':closed_at' => $inc['closed_at'],
                    ':resolution_diagnosis' => $inc['diagnosis'],
                    ':resolution_action' => $inc['action'],
                    ':created_at' => $inc['created_at'],
                    ':updated_at' => $inc['closed_at'] ?? $inc['resolved_at'] ?? $inc['created_at'],
                ]);

                // Obtener ID del ticket para auditoría
                $idStmt = $this->pdo->prepare("SELECT `id` FROM `incidents` WHERE `ticket_code` = :tc");
                $idStmt->execute([':tc' => $inc['ticket_code']]);
                $incidentId = (int)$idStmt->fetchColumn();
                $incidentCount++;

                // Comprobar si ya existen eventos para este ticket antes de duplicar
                $existStmt = $this->pdo->prepare("SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` = 'TICKET' AND `entity_id` = :eid");
                $existStmt->execute([':eid' => $incidentId]);
                if ((int)$existStmt->fetchColumn() === 0) {
                    // 1. Creación
                    $insertAuditStmt->execute([
                        ':entity_type' => 'TICKET',
                        ':entity_id' => $incidentId,
                        ':action' => 'TICKET_CREATED',
                        ':user_id' => null,
                        ':user_role' => 'SYSTEM',
                        ':user_name' => $inc['reporter_name'] . ' (Aviso Web/QR)',
                        ':previous_state' => null,
                        ':new_state' => json_encode([
                            'ticket_code' => $inc['ticket_code'],
                            'status' => 'REGISTERED',
                            'urgency' => $inc['urgency'],
                            'category' => $inc['category'],
                        ], JSON_UNESCAPED_UNICODE),
                        ':metadata' => json_encode(['source' => 'QR_SCAN', 'client_ip' => '127.0.0.1']),
                        ':created_at' => $inc['created_at'],
                    ]);
                    $auditCount++;

                    // 2. Asignación
                    $insertAuditStmt->execute([
                        ':entity_type' => 'TICKET',
                        ':entity_id' => $incidentId,
                        ':action' => 'TECHNICIAN_ASSIGNED',
                        ':user_id' => $saraId,
                        ':user_role' => 'COORDINATOR',
                        ':user_name' => 'Sara Coordinadora',
                        ':previous_state' => json_encode(['status' => 'REGISTERED', 'assigned_technician_id' => null]),
                        ':new_state' => json_encode(['status' => 'ASSIGNED', 'assigned_technician_id' => $inc['assigned_technician_id']]),
                        ':metadata' => json_encode(['technician_name' => $inc['tech_name']]),
                        ':created_at' => $inc['assigned_at'],
                    ]);
                    $auditCount++;

                    // 3. Inicio Intervención
                    $insertAuditStmt->execute([
                        ':entity_type' => 'TICKET',
                        ':entity_id' => $incidentId,
                        ':action' => 'INTERVENTION_STARTED',
                        ':user_id' => $inc['assigned_technician_id'],
                        ':user_role' => 'TECHNICIAN',
                        ':user_name' => $inc['tech_name'],
                        ':previous_state' => json_encode(['status' => 'ASSIGNED']),
                        ':new_state' => json_encode(['status' => 'IN_PROGRESS']),
                        ':metadata' => json_encode(['location' => 'In-situ']),
                        ':created_at' => $inc['started_at'],
                    ]);
                    $auditCount++;

                    // 4. Resolución
                    $insertAuditStmt->execute([
                        ':entity_type' => 'TICKET',
                        ':entity_id' => $incidentId,
                        ':action' => 'TICKET_RESOLVED',
                        ':user_id' => $inc['assigned_technician_id'],
                        ':user_role' => 'TECHNICIAN',
                        ':user_name' => $inc['tech_name'],
                        ':previous_state' => json_encode(['status' => 'IN_PROGRESS']),
                        ':new_state' => json_encode([
                            'status' => 'RESOLVED',
                            'resolution_diagnosis' => $inc['diagnosis'],
                            'resolution_action' => $inc['action'],
                        ], JSON_UNESCAPED_UNICODE),
                        ':metadata' => json_encode(['warranty_period_hours' => 48]),
                        ':created_at' => $inc['resolved_at'],
                    ]);
                    $auditCount++;

                    // 5. Cierre
                    if ($inc['closed_at'] !== null) {
                        $insertAuditStmt->execute([
                            ':entity_type' => 'TICKET',
                            ':entity_id' => $incidentId,
                            ':action' => 'TICKET_AUTO_CLOSED',
                            ':user_id' => null,
                            ':user_role' => 'SYSTEM_CRON',
                            ':user_name' => 'AutoCloseCron (48h Garantía)',
                            ':previous_state' => json_encode(['status' => 'RESOLVED']),
                            ':new_state' => json_encode(['status' => 'CLOSED']),
                            ':metadata' => json_encode(['warranty_cleared' => true]),
                            ':created_at' => $inc['closed_at'],
                        ]);
                        $auditCount++;
                    }
                }
            }

            // Eventos en máquinas y sedes
            $existMachAudit = (int)$this->pdo->query("SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` = 'MACHINE' AND `action` = 'QR_LABEL_GENERATED'")->fetchColumn();
            if ($existMachAudit === 0) {
                $insertAuditStmt->execute([
                    ':entity_type' => 'MACHINE',
                    ':entity_id' => $vend0101['id'],
                    ':action' => 'QR_LABEL_GENERATED',
                    ':user_id' => $saraId,
                    ':user_role' => 'COORDINATOR',
                    ':user_name' => 'Sara Coordinadora',
                    ':previous_state' => null,
                    ':new_state' => json_encode(['qr_code' => 'VENDGUARD-0101-SAFE', 'type' => 'PERISHABLE_FOOD']),
                    ':metadata' => json_encode(['format' => 'SVG_VECTOR', 'phone_override' => null]),
                    ':created_at' => '2026-09-01 10:00:00',
                ]);
                $auditCount++;
            }

            $existLocAudit = (int)$this->pdo->query("SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` = 'LOCATION' AND `action` = 'LOCATION_INSPECTED'")->fetchColumn();
            if ($existLocAudit === 0) {
                $insertAuditStmt->execute([
                    ':entity_type' => 'LOCATION',
                    ':entity_id' => $vend0101['location_id'],
                    ':action' => 'LOCATION_INSPECTED',
                    ':user_id' => $saraId,
                    ':user_role' => 'COORDINATOR',
                    ':user_name' => 'Sara Coordinadora',
                    ':previous_state' => json_encode(['status' => 'OPERATIONAL']),
                    ':new_state' => json_encode(['status' => 'VERIFIED_AUDIT']),
                    ':metadata' => json_encode(['inspector' => 'Sanidad Hospitalaria']),
                    ':created_at' => '2026-09-05 16:30:00',
                ]);
                $auditCount++;
            }

            $this->pdo->commit();

            return [
                'incidents' => $incidentCount,
                'audit_events' => $auditCount,
            ];
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Error sembrando datos demo de métricas: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    private function ensureUsers(): void
    {
        $hash = password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 10]);

        $users = [
            ['name' => 'Sara Coordinadora', 'email' => 'coordinacion@vendguard.internal', 'role' => 'COORDINATOR', 'phone' => '677000111'],
            ['name' => 'Jordi Técnico Ruta BCN', 'email' => 'jordi.ruta@vendguard.internal', 'role' => 'TECHNICIAN', 'phone' => '677222333'],
            ['name' => 'Marta Técnica Ruta BCN', 'email' => 'marta.ruta@vendguard.internal', 'role' => 'TECHNICIAN', 'phone' => '677444555'],
        ];

        $sql = "INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `phone`, `is_active`)
                VALUES (:name, :email, :password_hash, :role, :phone, 1)
                ON DUPLICATE KEY UPDATE
                    `name` = VALUES(`name`),
                    `password_hash` = VALUES(`password_hash`),
                    `role` = VALUES(`role`),
                    `phone` = VALUES(`phone`),
                    `deleted_at` = NULL";

        $stmt = $this->pdo->prepare($sql);
        foreach ($users as $u) {
            $stmt->execute([
                ':name' => $u['name'],
                ':email' => $u['email'],
                ':password_hash' => $hash,
                ':role' => $u['role'],
                ':phone' => $u['phone'],
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function getUserMap(): array
    {
        $stmt = $this->pdo->query("SELECT `email`, `id` FROM `users` WHERE `deleted_at` IS NULL");
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[$row['email']] = (int)$row['id'];
        }
        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function getLocationMap(): array
    {
        $stmt = $this->pdo->query("SELECT `site_code`, `id` FROM `locations` WHERE `deleted_at` IS NULL");
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[$row['site_code']] = (int)$row['id'];
        }
        return $map;
    }

    /**
     * @return array<string, array{id: int, location_id: int, machine_type: string}>
     */
    private function getMachineMap(): array
    {
        $stmt = $this->pdo->query("SELECT `code`, `id`, `location_id`, `machine_type` FROM `machines` WHERE `deleted_at` IS NULL");
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[$row['code']] = [
                'id' => (int)$row['id'],
                'location_id' => (int)$row['location_id'],
                'machine_type' => (string)$row['machine_type'],
            ];
        }
        return $map;
    }
}
