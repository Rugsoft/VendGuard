<?php

declare(strict_types=1);

/**
 * PdoRestockerSatSynergyGatewayTest · T-RSS-05
 *
 * Certifica en PostgreSQL el gateway transaccional de la sinergia reponedor ↔
 * SAT (Módulo 36): inicio y resolución de Nivel 1, escalado al SAT en sus tres
 * ramas, sugerencia por zona con carga L1, Coincidencia en Sede bidireccional,
 * relevo de fin de turno y idempotencia de la cola offline.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Inicio L1 (EARS 03.1).** Solo el reponedor custodio de un aviso
 *      `ASSIGNED` puede iniciarlo; la transición sella `started_at`, deja
 *      rastro en `incident_history` y `audit_log`, y un reenvío con el mismo
 *      `client_mutation_id` devuelve el payload original sin duplicar efectos.
 *
 *   2. **Resolución L1 (EARS 03.1, QA-CONST-01).** La cuarentena sanitaria
 *      bloquea con 409; una máquina limpia resuelve con los cinco snapshots de
 *      taxonomía, cierra el tramo de custodia con `RESOLVED` y no crea hoja de
 *      servicio.
 *
 *   3. **Escalado en tres ramas (EARS 03.2, QA-CONST-02).** Sobre un L1 activo
 *      escala el mismo expediente y cierra la custodia; sobre un L2 preexistente
 *      anexa evidencia sin tocar la custodia del técnico; a puerta abierta crea
 *      un único expediente L2 con el rastro del reponedor.
 *
 *   4. **Sugerencia y coincidencia (RF-02.1, D4).** El ranking respeta zona,
 *      turno y carga L1; la Coincidencia en Sede se calcula en ambos sentidos y
 *      solo para operarios con turno abierto.
 *
 *   5. **Relevo C1.** Un L1 en `IN_PROGRESS` bloquea el cierre con 409; los
 *      avisos `ASSIGNED` se reasignan al siguiente reponedor de la zona o
 *      vuelven a `REGISTERED` sin cambiar de nivel.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL; sin `PG_TEST_DSN` sale con 2.
 * LIMPIEZA: esquema aislado `t_rss05_gateway` borrado con `CASCADE`, incluso si
 * una aserción falla.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Repository\PdoRestockerSatSynergyGateway;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const RSS05_SCHEMA = 't_rss05_gateway';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$scalar = static function (PDO $pdo, string $sql, array $params = []): mixed {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
};

echo "======================================================================\n";
echo " VENDGUARD · T-RSS-05 · Gateway PDO de la sinergia reponedor-SAT\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pdo = PostgresFixtureBuilder::gateOrExit();
$crash = null;

try {
    PostgresFixtureBuilder::createIsolationSchema($pdo, RSS05_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    echo "\n--- Escenario (zona, sede, siete máquinas y tres operarios) ---\n";

    $zoneId = (int)$scalar(
        $pdo,
        "INSERT INTO operational_zones (code, name) VALUES ('Z-RSS-05', 'Zona Sinergia 36') RETURNING id"
    );

    $locationId = (int)$scalar(
        $pdo,
        "INSERT INTO locations (site_code, site_code_key, name, address, zone_id)
         VALUES ('RSS-05', 'RSS-05', 'Sede Sinergia 36', 'Calle de la Prueba 36', :zone)
         RETURNING id",
        [':zone' => $zoneId]
    );

    $machineIds = [];
    for ($i = 1; $i <= 7; $i++) {
        $machineIds[$i] = (int)$scalar(
            $pdo,
            "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location, :code, 'Modelo RSS', 'SNACKS', 'Planta 1')
             RETURNING id",
            [':location' => $locationId, ':code' => sprintf('VEND-RSS-%03d', 500 + $i)]
        );
    }

    $createUser = static function (string $name, string $email, string $role, string $code) use ($pdo): int {
        return (int)$pdo->query(
            "INSERT INTO users (name, email, email_key, password_hash, role, operator_code, phone, is_active)
             VALUES (" . $pdo->quote($name) . ", " . $pdo->quote($email) . ", " . $pdo->quote($email) . ",
                     'hash', " . $pdo->quote($role) . ", " . $pdo->quote($code) . ", '+34600000000', TRUE)
             RETURNING id"
        )->fetchColumn();
    };

    $restockerId = $createUser('Reponedora Uno', 'rep.rss05@test.local', 'RESTOCKER', 'REP-05');
    $backupId = $createUser('Reponedor Suplente', 'backup.rss05@test.local', 'RESTOCKER', 'REP-06');
    $technicianId = $createUser('Técnico SAT', 'tec.rss05@test.local', 'TECHNICIAN', 'TEC-05');

    $pdo->exec(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES ({$restockerId}, {$zoneId}, 'PRIMARY', TRUE),
                ({$backupId}, {$zoneId}, 'BACKUP', FALSE)"
    );

    $pdo->exec(
        "INSERT INTO technician_shifts (technician_id, shift_date, status)
         VALUES ({$restockerId}, CURRENT_DATE, 'ON_SHIFT'),
                ({$backupId}, CURRENT_DATE, 'ON_SHIFT'),
                ({$technicianId}, CURRENT_DATE, 'ON_SHIFT')"
    );

    $ticketSeed = 900;
    $ticketCode = static function () use (&$ticketSeed): string {
        $ticketSeed++;

        return sprintf('INC-2026-%04d', $ticketSeed);
    };

    $gateway = new PdoRestockerSatSynergyGateway($pdo, $ticketCode);

    $actor = static fn (int $id, string $name, string $role, string $code): array => [
        'id' => $id,
        'name' => $name,
        'operator_code' => $code,
        'role' => $role,
    ];
    $rep = $actor($restockerId, 'Reponedora Uno', 'RESTOCKER', 'REP-05');
    $repBackup = $actor($backupId, 'Reponedor Suplente', 'RESTOCKER', 'REP-06');

    $incident1 = PostgresFixtureBuilder::insertIncident($pdo, 'INC-2026-0101', $locationId, $machineIds[1], [
        'category' => 'PRODUCT_JAM',
        'status' => 'ASSIGNED',
        'urgency' => 'MEDIUM',
        'description' => 'Carril 14 vacío detectado en ruta.',
        'dispatch_level' => 'L1_RESTOCKER',
        'level1_issue_type' => 'STOCKOUT_EMPTY_SLOT',
        'assigned_technician_id' => $restockerId,
        'assigned_at' => '2026-11-18 09:00:00+00',
    ]);
    $incident2 = PostgresFixtureBuilder::insertIncident($pdo, 'INC-2026-0102', $locationId, $machineIds[2], [
        'category' => 'PRODUCT_JAM',
        'status' => 'ASSIGNED',
        'urgency' => 'MEDIUM',
        'description' => 'Espiral 22 trabada.',
        'dispatch_level' => 'L1_RESTOCKER',
        'level1_issue_type' => 'MINOR_SPIRAL_JAM',
        'assigned_technician_id' => $restockerId,
        'assigned_at' => '2026-11-18 09:05:00+00',
    ]);
    $incident3 = PostgresFixtureBuilder::insertIncident($pdo, 'INC-2026-0103', $locationId, $machineIds[3], [
        'category' => 'PAYMENT_SYSTEM',
        'status' => 'ASSIGNED',
        'urgency' => 'HIGH',
        'description' => 'Monedero sin devolución.',
        'dispatch_level' => 'L2_SAT',
        'assigned_technician_id' => $technicianId,
        'assigned_at' => '2026-11-18 08:30:00+00',
    ]);
    $incident5 = PostgresFixtureBuilder::insertIncident($pdo, 'INC-2026-0105', $locationId, $machineIds[5], [
        'category' => 'PRODUCT_JAM',
        'status' => 'IN_PROGRESS',
        'urgency' => 'MEDIUM',
        'description' => 'Carril 7 en intervención.',
        'dispatch_level' => 'L1_RESTOCKER',
        'level1_issue_type' => 'STOCKOUT_EMPTY_SLOT',
        'assigned_technician_id' => $restockerId,
        'started_at' => '2026-11-18 09:30:00+00',
    ]);
    $incident6 = PostgresFixtureBuilder::insertIncident($pdo, 'INC-2026-0106', $locationId, $machineIds[6], [
        'category' => 'OTHER',
        'status' => 'ASSIGNED',
        'urgency' => 'LOW',
        'description' => 'Falta de vasos.',
        'dispatch_level' => 'L1_RESTOCKER',
        'level1_issue_type' => 'MISSING_CONSUMABLES',
        'assigned_technician_id' => $restockerId,
    ]);
    $incident7 = PostgresFixtureBuilder::insertIncident($pdo, 'INC-2026-0107', $locationId, $machineIds[7], [
        'category' => 'PAYMENT_SYSTEM',
        'status' => 'ASSIGNED',
        'urgency' => 'LOW',
        'description' => 'Tubos de cambio vacíos.',
        'dispatch_level' => 'L1_RESTOCKER',
        'level1_issue_type' => 'EMPTY_CHANGE_TUBES',
        'assigned_technician_id' => $restockerId,
    ]);

    foreach ([$incident1, $incident2, $incident3, $incident5, $incident6, $incident7] as $incident) {
        PostgresFixtureBuilder::openCustodySegment(
            $pdo,
            (int)$incident['id'],
            (int)($incident['assigned_technician_id'] ?? $restockerId),
            'COORDINATOR_ASSIGN'
        );
    }

    echo "  [OK]  Escenario sembrado (7 máquinas, 3 operarios, 6 tramos)\n";

    echo "\n--- Contexto L1 de la ruta del reponedor (T-RSS-11, D3/D4) ---\n";
    $routeContext = $gateway->restockerLevel1RouteContext($restockerId, $machineIds);
    $routeAssigned = $routeContext['assigned_incidents'] ?? [];
    $routeMachineFlags = $routeContext['machine_flags'] ?? [];
    $assert(
        'El contexto L1 publica únicamente los avisos activos asignados al reponedor',
        count($routeAssigned) === 5
            && array_reduce($routeAssigned, static fn (bool $carry, array $row): bool => $carry && $row['level1_issue_type'] !== null, true)
    );
    $assert(
        'La máquina con L1 activo se marca sin inventar el estado SAT',
        ($routeMachineFlags[$machineIds[1]]['has_active_l1_incident'] ?? false) === true
            && ($routeMachineFlags[$machineIds[1]]['has_active_sat_incident'] ?? true) === false
            && ($routeMachineFlags[$machineIds[1]]['active_l1_issue_type'] ?? null) === 'STOCKOUT_EMPTY_SLOT'
    );
    $assert(
        'La máquina con SAT abierto se marca para no duplicar el expediente',
        ($routeMachineFlags[$machineIds[3]]['has_active_sat_incident'] ?? false) === true
            && ($routeMachineFlags[$machineIds[3]]['has_active_l1_incident'] ?? true) === false
    );

    echo "\n--- Inicio de Nivel 1 e idempotencia offline (EARS 03.1, C2) ---\n";

    $startMutation = [
        'client_mutation_id' => 'mut-rss05-start-0001',
        'client_occurred_at' => '2026-11-18 10:00:00+00',
    ];
    $started = $gateway->startLevel1((int)$incident1['id'], $rep, $startMutation);
    $assert(
        'El aviso ASSIGNED del reponedor se inicia en IN_PROGRESS',
        ($started['ok'] ?? false) === true
            && ($started['status'] ?? '') === 'IN_PROGRESS'
            && ($started['started_at'] ?? '') === '2026-11-18 10:00:00+00',
        'status=' . ($started['status'] ?? 'n/a')
    );
    $assert(
        'El inicio deja rastro en incident_history y audit_log',
        (int)$scalar($pdo, "SELECT COUNT(*) FROM incident_history WHERE incident_id = :id AND to_status = 'IN_PROGRESS'", [':id' => (int)$incident1['id']]) === 1
            && (int)$scalar($pdo, "SELECT COUNT(*) FROM audit_log WHERE entity_id = :id AND action = 'START_L1_INCIDENT'", [':id' => (int)$incident1['id']]) === 1
    );

    $replayed = $gateway->startLevel1((int)$incident1['id'], $rep, $startMutation);
    $assert(
        'El reenvío con el mismo client_mutation_id no duplica efectos',
        ($replayed['idempotent_replay'] ?? false) === true
            && (int)$scalar($pdo, "SELECT COUNT(*) FROM incident_history WHERE incident_id = :id AND action_note = 'START_L1_INCIDENT'", [':id' => (int)$incident1['id']]) === 1
            && (int)$scalar($pdo, "SELECT COUNT(*) FROM offline_sync_mutations WHERE technician_id = :tid AND client_mutation_id = :mid", [':tid' => $restockerId, ':mid' => $startMutation['client_mutation_id']]) === 1
    );

    $startAgain = $gateway->startLevel1((int)$incident1['id'], $rep);
    $assert(
        'Un segundo inicio sin mutación se rechaza con 409',
        ($startAgain['ok'] ?? true) === false
            && ($startAgain['error_code'] ?? '') === 'INVALID_INCIDENT_STATE_TRANSITION'
            && ($startAgain['http_status'] ?? 0) === 409
    );

    echo "\n--- Resolución de Nivel 1 (EARS 03.1, QA-CONST-01) ---\n";

    $gateway->startLevel1((int)$incident2['id'], $rep, [
        'client_mutation_id' => 'mut-rss05-start-0002',
        'client_occurred_at' => '2026-11-18 10:05:00+00',
    ]);

    $rootCauseId = (int)$scalar(
        $pdo,
        "INSERT INTO root_causes (code, category, name)
         VALUES ('RC-RSS05-SPIRAL', 'PRODUCT_JAM', 'Envase mal posicionado en boca de espiral')
         RETURNING id"
    );
    $solutionId = (int)$scalar(
        $pdo,
        "INSERT INTO standard_solutions (root_cause_id, code, name, is_default)
         VALUES (:root, 'SS-RSS05-SPIRAL', 'Liberación manual de envase y reacomodo de carril', TRUE)
         RETURNING id",
        [':root' => $rootCauseId]
    );

    $pdo->exec("UPDATE machines SET sanitary_status = 'QUARANTINE' WHERE id = {$machineIds[2]}");
    $quarantined = $gateway->resolveLevel1((int)$incident2['id'], $rep, [
        'resolution_diagnosis' => 'Se verifica que la bolsa del carril 22 quedó girada contra el cristal frontal.',
        'resolution_action' => 'Se libera manualmente el envase y se reacomoda la fila completa del carril.',
    ]);
    $assert(
        'Resolver un L1 sobre máquina en cuarentena devuelve 409',
        ($quarantined['ok'] ?? true) === false
            && ($quarantined['error_code'] ?? '') === 'SANITARY_L1_RESOLUTION_BLOCKED'
            && ($quarantined['http_status'] ?? 0) === 409
    );
    $pdo->exec("UPDATE machines SET sanitary_status = 'OK' WHERE id = {$machineIds[2]}");

    $resolved = $gateway->resolveLevel1((int)$incident2['id'], $rep, [
        'root_cause_id' => $rootCauseId,
        'standard_solution_id' => $solutionId,
        'root_cause_code' => 'RC-L1-SPIRAL-RELEASE',
        'root_cause_category' => 'PRODUCT_JAM',
        'root_cause_name' => 'Envase mal posicionado en boca de espiral',
        'standard_solution_code' => 'SS-L1-CLEAR-SPIRAL',
        'standard_solution_name' => 'Liberación manual de envase y reacomodo de carril',
        'resolution_diagnosis' => 'Se verifica que la bolsa del carril 22 quedó girada contra el cristal frontal.',
        'resolution_action' => 'Se libera manualmente el envase y se reacomoda la fila completa del carril.',
    ], [
        'client_mutation_id' => 'mut-rss05-resolve-0001',
        'client_occurred_at' => '2026-11-18 10:20:00+00',
    ]);
    $assert(
        'La resolución sella RESOLVED y los snapshots de taxonomía',
        ($resolved['ok'] ?? false) === true
            && ($resolved['status'] ?? '') === 'RESOLVED'
            && $scalar($pdo, 'SELECT status FROM incidents WHERE id = :id', [':id' => (int)$incident2['id']]) === 'RESOLVED'
            && $scalar($pdo, 'SELECT root_cause_code_snapshot FROM incidents WHERE id = :id', [':id' => (int)$incident2['id']]) === 'RC-L1-SPIRAL-RELEASE'
            && $scalar($pdo, 'SELECT standard_solution_code_snapshot FROM incidents WHERE id = :id', [':id' => (int)$incident2['id']]) === 'SS-L1-CLEAR-SPIRAL'
    );
    $assert(
        'La custodia abierta se cierra con RESOLVED y sin hoja de servicio',
        $scalar($pdo, 'SELECT unassignment_reason_type FROM incident_assignments WHERE incident_id = :id AND unassigned_at IS NOT NULL', [':id' => (int)$incident2['id']]) === 'RESOLVED'
            && (int)$scalar($pdo, 'SELECT COUNT(*) FROM incident_service_sheets WHERE incident_id = :id', [':id' => (int)$incident2['id']]) === 0
    );

    echo "\n--- Escalado al SAT en tres ramas (EARS 03.2, QA-CONST-02) ---\n";

    $escalateA = $gateway->escalateToSat($machineIds[1], $rep, [
        'category' => 'PRODUCT_JAM',
        'workshop_internal_note' => 'Al abrir la máquina se comprueba que el motorreductor de la espiral 14 está quemado.',
        'photo_path' => '/uploads/incidents/rss05_motor14.jpg',
        'urgency' => 'HIGH',
        'sla_target_minutes' => 480,
    ], [
        'client_mutation_id' => 'mut-rss05-escalate-0001',
        'client_occurred_at' => '2026-11-18 10:30:00+00',
    ]);
    $assert(
        'Sobre un L1 activo escala el mismo expediente a L2_SAT',
        ($escalateA['ok'] ?? false) === true
            && ($escalateA['escalation_outcome'] ?? '') === 'ESCALATED_FROM_L1'
            && $scalar($pdo, 'SELECT dispatch_level FROM incidents WHERE id = :id', [':id' => (int)$incident1['id']]) === 'L2_SAT'
            && $scalar($pdo, 'SELECT escalated_from_restocker_id FROM incidents WHERE id = :id', [':id' => (int)$incident1['id']]) == $restockerId
    );
    $assert(
        'La Rama A cierra la custodia y deja la nota interna del reponedor',
        $scalar($pdo, 'SELECT unassignment_reason_type FROM incident_assignments WHERE incident_id = :id AND unassigned_at IS NOT NULL', [':id' => (int)$incident1['id']]) === 'ESCALATED_TO_SAT_BY_RESTOCKER'
            && (int)$scalar($pdo, "SELECT COUNT(*) FROM incident_comments WHERE incident_id = :id AND author_type = 'RESTOCKER' AND is_internal = TRUE", [':id' => (int)$incident1['id']]) === 1
    );

    $escalateC = $gateway->escalateToSat($machineIds[3], $rep, [
        'category' => 'TEMPERATURE_COLD',
        'workshop_internal_note' => 'La puerta deja escapar aire caliente y el termostato marca veinte grados.',
        'photo_path' => '/uploads/incidents/rss05_frio.jpg',
        'urgency' => 'CRITICAL',
    ], [
        'client_mutation_id' => 'mut-rss05-escalate-0002',
        'client_occurred_at' => '2026-11-18 10:35:00+00',
    ]);
    $assert(
        'Sobre un L2 preexistente absorbe la evidencia sin crear otro ticket',
        ($escalateC['ok'] ?? false) === true
            && ($escalateC['escalation_outcome'] ?? '') === 'MERGED_INTO_EXISTING_SAT_TICKET'
            && ($escalateC['merged_into_existing_sat_ticket'] ?? false) === true
            && (int)$scalar($pdo, 'SELECT COUNT(*) FROM incidents WHERE machine_id = :m', [':m' => $machineIds[3]]) === 1
            && $scalar($pdo, 'SELECT category FROM incidents WHERE id = :id', [':id' => (int)$incident3['id']]) === 'TEMPERATURE_COLD'
    );
    $assert(
        'La Rama C respeta intacta la custodia del técnico',
        (int)$scalar($pdo, 'SELECT COUNT(*) FROM incident_assignments WHERE incident_id = :id AND unassigned_at IS NULL AND technician_id = :tid', [':id' => (int)$incident3['id'], ':tid' => $technicianId]) === 1
    );

    $escalateB = $gateway->escalateToSat($machineIds[4], $rep, [
        'category' => 'ELECTRICAL_OFF',
        'workshop_internal_note' => 'La máquina no enciende y el diferencial del cuadro salta al conectar.',
        'photo_path' => '/uploads/incidents/rss05_cuadro.jpg',
        'urgency' => 'CRITICAL',
    ], [
        'client_mutation_id' => 'mut-rss05-escalate-0003',
        'client_occurred_at' => '2026-11-18 10:40:00+00',
    ]);
    $assert(
        'A puerta abierta crea un único expediente L2 con el rastro del reponedor',
        ($escalateB['ok'] ?? false) === true
            && ($escalateB['escalation_outcome'] ?? '') === 'CREATED_NEW_SAT_TICKET'
            && (int)$scalar($pdo, 'SELECT COUNT(*) FROM incidents WHERE machine_id = :m', [':m' => $machineIds[4]]) === 1
            && $scalar($pdo, 'SELECT dispatch_level FROM incidents WHERE machine_id = :m', [':m' => $machineIds[4]]) === 'L2_SAT'
            && $scalar($pdo, 'SELECT escalated_from_restocker_id FROM incidents WHERE machine_id = :m', [':m' => $machineIds[4]]) == $restockerId
    );
    $replayB = $gateway->escalateToSat($machineIds[4], $rep, [
        'category' => 'ELECTRICAL_OFF',
        'workshop_internal_note' => 'La máquina no enciende y el diferencial del cuadro salta al conectar.',
        'photo_path' => '/uploads/incidents/rss05_cuadro.jpg',
    ], [
        'client_mutation_id' => 'mut-rss05-escalate-0003',
        'client_occurred_at' => '2026-11-18 10:40:00+00',
    ]);
    $assert(
        'El reenvío del escalado devuelve el mismo expediente creado',
        ($replayB['idempotent_replay'] ?? false) === true
            && ($replayB['incident_id'] ?? 0) === ($escalateB['incident_id'] ?? -1)
            && (int)$scalar($pdo, 'SELECT COUNT(*) FROM incidents WHERE machine_id = :m', [':m' => $machineIds[4]]) === 1
    );

    echo "\n--- Sugerencia por zona y Coincidencia en Sede (RF-02.1, D4) ---\n";

    $suggested = $gateway->suggestRestockers((int)$incident6['id']);
    $assert(
        'El ranking sugiere a los reponedores de la zona con su carga L1',
        count($suggested) === 2
            && ($suggested[0]['user_id'] ?? 0) === $restockerId
            && ($suggested[0]['role_in_zone'] ?? '') === 'PRIMARY'
            && ($suggested[0]['active_l1_incidents_count'] ?? 0) >= 1
            && ($suggested[1]['user_id'] ?? 0) === $backupId
    );

    $technicianView = $gateway->technicianCoincidences($technicianId, [$locationId]);
    $restockerView = $gateway->restockerCoincidences($restockerId, [$locationId]);
    $assert(
        'El técnico ve a los reponedores activos de la sede',
        isset($technicianView[$locationId])
            && count($technicianView[$locationId]) === 2
            && ($technicianView[$locationId][0]['role'] ?? '') === 'RESTOCKER'
            && ($technicianView[$locationId][0]['shift_status'] ?? '') === 'ON_SHIFT'
    );
    $assert(
        'La reponedora ve al técnico SAT activo de la sede',
        isset($restockerView[$locationId])
            && count($restockerView[$locationId]) === 1
            && ($restockerView[$locationId][0]['user_id'] ?? 0) === $technicianId
            && ($restockerView[$locationId][0]['role'] ?? '') === 'TECHNICIAN'
    );
    $pdo->exec("UPDATE technician_shifts SET status = 'OFF_SHIFT', closed_at = CURRENT_TIMESTAMP WHERE technician_id = {$technicianId}");
    $assert(
        'Sin turno abierto no se publica Coincidencia en Sede',
        $gateway->technicianCoincidences($technicianId, [$locationId]) === []
    );
    $pdo->exec("UPDATE technician_shifts SET status = 'ON_SHIFT', closed_at = NULL WHERE technician_id = {$technicianId}");

    echo "\n--- Relevo automático de fin de turno (C1) ---\n";

    $blocked = $gateway->handoverLevel1($restockerId, 'SHIFT_CLOSE', 'Cierre de turno con aviso en curso.');
    $assert(
        'Un L1 en IN_PROGRESS bloquea el cierre con su código de ticket',
        ($blocked['ok'] ?? true) === false
            && ($blocked['error_code'] ?? '') === 'SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1'
            && ($blocked['http_status'] ?? 0) === 409
            && in_array('INC-2026-0105', $blocked['blocked_ticket_codes'] ?? [], true)
    );

    $pdo->exec("UPDATE incidents SET status = 'ASSIGNED' WHERE id = {$incident5['id']}");
    $handover = $gateway->handoverLevel1($restockerId, 'SHIFT_CLOSE', 'Cierre de turno con avisos pendientes.');
    $assert(
        'Los avisos ASSIGNED se reasignan al suplente en turno',
        ($handover['ok'] ?? false) === true
            && ($handover['reassigned_count'] ?? 0) === 3
            && $scalar($pdo, 'SELECT assigned_technician_id FROM incidents WHERE id = :id', [':id' => (int)$incident6['id']]) == $backupId
            && $scalar($pdo, 'SELECT unassignment_reason_type FROM incident_assignments WHERE incident_id = :id AND unassigned_at IS NOT NULL', [':id' => (int)$incident6['id']]) === 'RESTOCKER_SHIFT_HANDOVER'
            && (int)$scalar($pdo, 'SELECT COUNT(*) FROM incident_assignments WHERE incident_id = :id AND unassigned_at IS NULL AND technician_id = :tid', [':id' => (int)$incident6['id'], ':tid' => $backupId]) === 1
    );

    $pdo->exec("UPDATE technician_shifts SET status = 'OFF_SHIFT', closed_at = CURRENT_TIMESTAMP WHERE technician_id IN ({$restockerId}, {$backupId})");
    $released = $gateway->handoverLevel1($backupId, 'ABSENCE', 'Ausencia sobrevenida del reponedor suplente.');
    $assert(
        'Sin suplente en turno el aviso vuelve a REGISTERED sin cambiar de nivel',
        ($released['ok'] ?? false) === true
            && ($released['released_count'] ?? 0) >= 1
            && $scalar($pdo, 'SELECT status FROM incidents WHERE id = :id', [':id' => (int)$incident7['id']]) === 'REGISTERED'
            && $scalar($pdo, 'SELECT assigned_technician_id FROM incidents WHERE id = :id', [':id' => (int)$incident7['id']]) === null
            && $scalar($pdo, 'SELECT dispatch_level FROM incidents WHERE id = :id', [':id' => (int)$incident7['id']]) === 'L1_RESTOCKER'
    );
} catch (Throwable $exception) {
    $crash = $exception;
    fwrite(STDERR, "\n[EXCEPCIÓN] " . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, RSS05_SCHEMA);
        $residue = $pdo->query(
            "SELECT COUNT(*) FROM pg_namespace WHERE nspname = '" . RSS05_SCHEMA . "'"
        )->fetchColumn();
        $assert('El esquema aislado se retira entero (residuo 0)', (int)$residue === 0, 'residuo=' . $residue);
    } catch (Throwable $cleanup) {
        fwrite(STDERR, "\n[LIMPIEZA] " . $cleanup->getMessage() . "\n");
        $failures++;
    }
}

echo "======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallidas: {$failures}\n";
echo $failures === 0 && $crash === null
    ? " RESULTADO: 100% EN VERDE. GATEWAY DE LA SINERGIA CERTIFICADO (T-RSS-05).\n"
    : " RESULTADO: FALLO.\n";
echo "======================================================================\n";

exit($failures === 0 && $crash === null ? 0 : 1);
