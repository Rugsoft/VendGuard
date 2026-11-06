<?php

declare(strict_types=1);

/**
 * SanitarySpoilageServiceIntegrationTest · T-SPOILAGE-09
 *
 * Certifica contra PostgreSQL el servicio de aplicación del Acta de Merma y
 * Destrucción de Lotes por Cuarentena Sanitaria (Módulo 29), sobre un esquema
 * aislado y sin semillas que alteren la numeración oficial.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Levantamiento por Técnico y por Coordinador (`QA 1`).** El técnico
 *      firma como `technician_id` y `registered_by`; Coordinación firma como
 *      `registered_by` y debe declarar el técnico ejecutor. El acta, el
 *      certificado y `audit_log` conservan esa distinción.
 *
 *   2. **Bloqueo por avería correctiva abierta (`C1`).** Una máquina en
 *      cuarentena con avería `ASSIGNED`, `IN_PROGRESS` o `PENDING_PARTS` no
 *      puede levantar la cuarentena; al pasar a `RESOLVED` el levantamiento
 *      completa el acta.
 *
 *   3. **Reapertura en garantía (`QA 2`).** Tras el acta `...-0001`, la misma
 *      avería se reabre y se resuelve de nuevo; el segundo levantamiento emite
 *      `...-0002` y deja intactos todos los hechos del acta anterior.
 *
 *   4. **Panel, ajustes y exportación (`QA 3`, `QA 4`, `C5`).** Las máquinas
 *      inactivas no entran en el contador de cuarentenas; los ajustes
 *      sucesivos conservan el antes/después en auditoría; el acta vacía no
 *      admite ajuste; el CSV lleva BOM, dos secciones, cabeceras con cero filas
 *      y neutraliza las celdas que comienzan por `=`, `+`, `-` o `@`.
 *
 *   5. **Restitución sin nuevas alertas (`C6`).** El levantamiento no escribe
 *      nuevas filas en `notification_outbox`; el acta no dispara mensajería.
 *
 *   6. **Límite superior del filtro de fechas (`QA 4.3`).** El rango incluye
 *      el día final completo: un acta emitida a las `23:59:59.5` aparece en el
 *      listado y en el CSV de ese día, y no se arrastra al día anterior.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL local (`deploy/local/postgres/`);
 * sin motor sale con código 2, nunca se omite en silencio. `run_all.php` la
 * excluye de la batería hermética por su marca de categoría.
 *
 * LIMPIEZA: todo el escenario vive en un esquema aislado que se elimina con
 * `CASCADE` en el `finally`, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local
 *   php tests/integration/SanitarySpoilageServiceIntegrationTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';

use VendGuard\Application\Service\SanitarySpoilageService;
use VendGuard\Core\Domain\Exception\SanitarySpoilageValidationException;
use VendGuard\Infrastructure\Repository\PdoSanitarySpoilageRecordRepository;

const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado propio de esta suite. */
const SPOILAGE_SERVICE_SCHEMA = 't_spoilage29_service';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

/**
 * @return array{0: string, 1: int}
 */
$errorOf = static function (callable $operation): array {
    try {
        $operation();
    } catch (SanitarySpoilageValidationException $exception) {
        return [$exception->getErrorCode(), $exception->getHttpStatusCode()];
    } catch (Throwable $exception) {
        return ['UNEXPECTED: ' . $exception->getMessage(), 0];
    }

    return ['NO_EXCEPTION', 0];
};

$recordByCode = static function (array $records, string $recordCode): ?array {
    foreach ($records as $record) {
        if (($record['record_code'] ?? null) === $recordCode) {
            return $record;
        }
    }

    return null;
};

echo "======================================================================\n";
echo " VENDGUARD · T-SPOILAGE-09 · Servicio del Acta de Merma APPCC (Módulo 29)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pdo = null;
$crash = null;

try {
    echo "\n--- Escenario (DDL base + migraciones en esquema aislado) ---\n";

    $pdo = PostgresFixtureBuilder::gateOrExit();
    PostgresFixtureBuilder::createIsolationSchema($pdo, SPOILAGE_SERVICE_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $assert(
        'El esquema aplica la migración 025 y deja el libro de mermas disponible',
        $schemaReport['statements'] > 100
        && in_array('025_sanitary_spoilage_records.sql', $schemaReport['migrations'], true)
    );

    $year = (int)date('Y');

    $insertLocation = static function (PDO $pdo) : int {
        $stmt = $pdo->prepare("
            INSERT INTO locations (site_code, site_code_key, name, address, postal_code)
            VALUES ('T29-SEDE', 't29-sede', 'Sede de Certificación T29', 'Calle de la Prueba 29', '08001')
            RETURNING id
        ");
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    };

    $insertUser = static function (PDO $pdo, string $role, string $name, string $operatorCode): int {
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active)
            VALUES (:name, :email, :email_key, 'integration-test-only', :role, :operator_code, TRUE)
            RETURNING id
        ");
        $key = strtolower($role) . '.t29@example.test';
        $stmt->execute([
            ':name' => $name,
            ':email' => $key,
            ':email_key' => $key,
            ':role' => $role,
            ':operator_code' => $operatorCode,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $insertMachine = static function (
        PDO $pdo,
        int $locationId,
        string $code,
        bool $isActive = true,
        string $sanitaryStatus = 'QUARANTINE'
    ): int {
        $activeLiteral = $isActive ? 'TRUE' : 'FALSE';
        $stmt = $pdo->prepare("
            INSERT INTO machines (location_id, code, model, machine_type, floor_wing, is_active, sanitary_status)
            VALUES (:location_id, :code, 'Modelo T29', 'PERISHABLE_FOOD', 'Planta 0', {$activeLiteral}, :sanitary_status)
            RETURNING id
        ");
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':sanitary_status' => $sanitaryStatus,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $insertIncident = static function (
        PDO $pdo,
        int $machineId,
        int $locationId,
        int $technicianId,
        string $ticketCode,
        string $status,
        bool $resolved = false
    ): int {
        $resolvedAtSql = $resolved ? 'CURRENT_TIMESTAMP' : 'NULL';
        $diagnosis = $resolved
            ? 'Diagnóstico térmico verificado con sonda calibrada y registro de temperatura.'
            : null;
        $action = $resolved
            ? 'Se restituyó el frío, se retiró el producto expuesto y se desinfectó la cabina.'
            : null;
        $pendingPartsReason = $status === 'PENDING_PARTS'
            ? 'Pendiente de recepción del compresor de sustitución para completar la reparación.'
            : null;

        $stmt = $pdo->prepare("
            INSERT INTO incidents (
                ticket_code, machine_id, machine_type_snapshot, location_id,
                assigned_technician_id, category, description, urgency, status,
                assigned_at, started_at, resolved_at, resolution_diagnosis,
                resolution_action, pending_parts_reason
            ) VALUES (
                :ticket_code, :machine_id, 'PERISHABLE_FOOD', :location_id,
                :technician_id, 'TEMPERATURE_COLD', :description, 'HIGH', :status,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, {$resolvedAtSql}, :diagnosis,
                :action, :pending_parts_reason
            )
            RETURNING id
        ");
        $stmt->execute([
            ':ticket_code' => $ticketCode,
            ':machine_id' => $machineId,
            ':location_id' => $locationId,
            ':technician_id' => $technicianId,
            ':description' => 'Avería térmica de certificación del Módulo 29 con producto perecedero expuesto.',
            ':status' => $status,
            ':diagnosis' => $diagnosis,
            ':action' => $action,
            ':pending_parts_reason' => $pendingPartsReason,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $payload = static function (int $units = 2, string $batch = 'LOTE-T29-ALFA'): array {
        return [
            'discarded_units_count' => $units,
            'empty_machine_confirmed' => $units === 0,
            'affected_batch_codes' => $units === 0 ? 'SIN_STOCK_AFECTADO' : $batch,
            'cabin_temp_at_removal' => 7.5,
            'restored_stabilized_temp_c' => 3.5,
            'disposal_method' => $units === 0 ? 'NOT_APPLICABLE_EMPTY' : 'ON_SITE_ORGANIC_SEALED',
            'sanitary_notes' => 'Retirada completa del producto expuesto y desinfección de la cabina con producto autorizado.',
        ];
    };

    $locationId = $insertLocation($pdo);
    $technicianId = $insertUser($pdo, 'TECHNICIAN', 'Técnico de Certificación T29', 'T29-TEC');
    $coordinatorId = $insertUser($pdo, 'COORDINATOR', 'Coordinación de Certificación T29', 'T29-COO');

    $technician = [
        'id' => $technicianId,
        'role' => 'TECHNICIAN',
        'name' => 'Técnico de Certificación T29',
    ];
    $coordinator = [
        'id' => $coordinatorId,
        'role' => 'COORDINATOR',
        'name' => 'Coordinación de Certificación T29',
    ];

    $repo = new PdoSanitarySpoilageRecordRepository($pdo);
    $service = new SanitarySpoilageService($repo, $pdo);

    $auditStmt = $pdo->prepare("
        SELECT user_id, user_role, user_name, previous_state, new_state, metadata
        FROM audit_log
        WHERE entity_type = 'SANITARY_SPOILAGE_RECORD'
          AND entity_id = :entity_id
          AND action = :action
        ORDER BY id DESC
        LIMIT 1
    ");

    $auditOf = static function (int $entityId, string $action) use ($auditStmt): ?array {
        $auditStmt->execute([':entity_id' => $entityId, ':action' => $action]);
        $row = $auditStmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    };

    echo "\n--- 1. Levantamiento por Técnico y por Coordinador (QA 1) ---\n";

    $machineA = $insertMachine($pdo, $locationId, 'T29-A');
    $incidentA = $insertIncident($pdo, $machineA, $locationId, $technicianId, 'T29-INC-A', 'RESOLVED', true);

    // Regresión del hallazgo 1: una certificación previa ya ocupa el primer
    // correlativo del año. El acta debe reservar el siguiente, nunca un
    // aleatorio que pueda colisionar con la clave única del motor.
    $legacyMachine = $insertMachine($pdo, $locationId, 'T29-CERT-PREVIO', true, 'OK');
    $legacyOrderStmt = $pdo->prepare("
        INSERT INTO preventive_orders (
            order_code, machine_id, location_id, assigned_technician_id,
            status, order_type, scheduled_date, due_date, completed_at, result
        ) VALUES (
            'PREV-T29-COLISION', :machine_id, :location_id, :technician_id,
            'COMPLETED', 'REINSPECTION', CURRENT_DATE, CURRENT_DATE, CURRENT_TIMESTAMP, 'CONFORME'
        )
        RETURNING id
    ");
    $legacyOrderStmt->execute([
        ':machine_id' => $legacyMachine,
        ':location_id' => $locationId,
        ':technician_id' => $technicianId,
    ]);
    $legacyOrderId = (int)$legacyOrderStmt->fetchColumn();

    $pdo->prepare("
        INSERT INTO sanitary_certificates (
            certificate_code, preventive_order_id, machine_id, location_id,
            technician_id, technician_name, technician_operator_code,
            inspection_date, valid_until, temperature_measured, result, status
        ) VALUES (
            :certificate_code, :preventive_order_id, :machine_id, :location_id,
            :technician_id, 'Técnico de Certificación T29', 'T29-TEC',
            CURRENT_TIMESTAMP, CURRENT_DATE + 15, 3.5, 'CONFORME', 'VALID'
        )
    ")->execute([
        ':certificate_code' => sprintf('CERT-%04d-0001', $year),
        ':preventive_order_id' => $legacyOrderId,
        ':machine_id' => $legacyMachine,
        ':location_id' => $locationId,
        ':technician_id' => $technicianId,
    ]);

    $outboxBeforeLift = (int)$pdo->query('SELECT COUNT(*) FROM notification_outbox')->fetchColumn();

    $firstLift = $service->liftQuarantineWithSpoilageRecord(
        $machineA,
        $payload(),
        'https://example.test/t29-evidencia-a.jpg',
        $technician
    );
    $firstAct = $firstLift['spoilage_record'];
    $firstRecordId = (int)$firstAct['id'];
    $firstRecordCode = (string)$firstAct['record_code'];
    $outboxAfterLift = (int)$pdo->query('SELECT COUNT(*) FROM notification_outbox')->fetchColumn();
    $outboxForIncident = (int)$pdo->query(
        'SELECT COUNT(*) FROM notification_outbox WHERE incident_id = ' . $incidentA
    )->fetchColumn();

    $assert(
        'El Técnico emite el acta 0001 y firma como técnico y registrador',
        $firstRecordCode === sprintf('ACTA-MERMA-%04d-0001', $year)
        && (int)$firstAct['technician_id'] === $technicianId
        && (int)$firstAct['registered_by'] === $technicianId
        && (int)$firstAct['incident_id'] === $incidentA,
        $firstRecordCode
    );
    $assert(
        'El levantamiento devuelve la máquina a OK y el QR queda desbloqueado',
        ($firstLift['machine_sanitary_status'] ?? null) === 'OK'
        && ($firstLift['qr_unblocked'] ?? null) === true
        && (string)$pdo->query('SELECT sanitary_status FROM machines WHERE id = ' . $machineA)->fetchColumn() === 'OK'
    );
    $assert(
        'C6: el levantamiento no escribe nuevas filas en notification_outbox',
        $outboxAfterLift === $outboxBeforeLift && $outboxForIncident === 0,
        sprintf('total %d→%d; incidencia=%d', $outboxBeforeLift, $outboxAfterLift, $outboxForIncident)
    );

    $certificateA = (string)($firstAct['certificate_code'] ?? '');
    $certificateCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM sanitary_certificates WHERE certificate_code = ' . $pdo->quote($certificateA)
    )->fetchColumn();
    $assert(
        'El certificado higiénico se persiste con el correlativo siguiente al ya emitido',
        $certificateCount === 1 && $certificateA === sprintf('CERT-%04d-0002', $year),
        $certificateA
    );

    $firstAudit = $auditOf($firstRecordId, 'CREATE_SPOILAGE_RECORD_AND_LIFT_QUARANTINE');
    $firstAuditMetadata = is_array($firstAudit)
        ? json_decode((string)$firstAudit['metadata'], true)
        : null;
    $assert(
        'La auditoría del Técnico conserva técnico y registrador',
        is_array($firstAudit)
        && (int)$firstAudit['user_id'] === $technicianId
        && $firstAudit['user_role'] === 'TECHNICIAN'
        && is_array($firstAuditMetadata)
        && (int)($firstAuditMetadata['technician_id'] ?? 0) === $technicianId
        && (int)($firstAuditMetadata['registered_by'] ?? 0) === $technicianId
    );

    $publicHistory = $service->listByMachine($machineA, 'TECHNICIAN');
    $coordinatorHistory = $service->listByMachine($machineA, 'COORDINATOR');
    $assert(
        'El historial público oculta el coste y el de Coordinación lo conserva',
        ($publicHistory['is_in_quarantine'] ?? true) === false
        && ($publicHistory['has_open_incidents'] ?? true) === false
        && count($publicHistory['records'] ?? []) === 1
        && !array_key_exists('estimated_merchandise_loss_eur', $publicHistory['records'][0])
        && array_key_exists('estimated_merchandise_loss_eur', $coordinatorHistory['records'][0])
    );

    $officialAct = $service->getOfficialAct($firstRecordId, $coordinator);
    $assert(
        'El Acta Oficial A4 omite todos los importes y motivos de ajuste',
        !array_key_exists('estimated_merchandise_loss_eur', $officialAct)
        && !array_key_exists('loss_adjusted_by', $officialAct)
        && !array_key_exists('loss_adjustment_reason', $officialAct)
        && !array_key_exists('loss_adjusted_by_name', $officialAct)
    );
    $viewAudit = $auditOf($firstRecordId, 'VIEW_SANITARY_SPOILAGE_ACT');
    $assert('La lectura del Acta Oficial deja su evento de auditoría', is_array($viewAudit));

    echo "\n--- 2. Reapertura en garantía: 0001 se conserva y nace 0002 (QA 2) ---\n";

    $immutableProjection = 'record_code, machine_id, incident_id, technician_id, registered_by, '
        . 'quarantine_origin, discarded_units_count, empty_machine_confirmed, affected_batch_codes, '
        . 'cabin_temp_at_removal, restored_stabilized_temp_c, disposal_method, evidence_photo_url, '
        . 'sanitary_notes, certificate_code, created_at';
    $firstRowBefore = $pdo->query(
        'SELECT ' . $immutableProjection . ' FROM sanitary_spoilage_records WHERE id = ' . $firstRecordId
    )->fetch(PDO::FETCH_ASSOC);

    $pdo->prepare("
        UPDATE incidents
        SET status = 'IN_PROGRESS',
            resolved_at = NULL,
            resolution_diagnosis = NULL,
            resolution_action = NULL,
            reopened_at = CURRENT_TIMESTAMP,
            reopen_reason = 'Reapertura en garantía por reincidencia térmica dentro de las 48 horas.',
            assigned_at = CURRENT_TIMESTAMP,
            started_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ")->execute([':id' => $incidentA]);
    $pdo->prepare("UPDATE machines SET sanitary_status = 'QUARANTINE' WHERE id = :id")
        ->execute([':id' => $machineA]);

    $reopenedStatus = (string)$pdo->query(
        'SELECT status FROM incidents WHERE id = ' . $incidentA
    )->fetchColumn();
    $reopenedMachine = (string)$pdo->query(
        'SELECT sanitary_status FROM machines WHERE id = ' . $machineA
    )->fetchColumn();
    $assert(
        'La reapertura deja el expediente vivo y la máquina de nuevo en cuarentena',
        $reopenedStatus === 'IN_PROGRESS' && $reopenedMachine === 'QUARANTINE',
        $reopenedStatus . ' / ' . $reopenedMachine
    );

    $pdo->prepare("
        UPDATE incidents
        SET status = 'RESOLVED',
            resolved_at = CURRENT_TIMESTAMP + INTERVAL '2 seconds',
            resolution_diagnosis = 'Segunda resolución térmica verificada con sonda calibrada y registro completo.',
            resolution_action = 'Se sustituyó el termostato, se restituyó el frío y se desinfectó la cabina.',
            pending_parts_reason = NULL
        WHERE id = :id
    ")->execute([':id' => $incidentA]);

    $secondLift = $service->liftQuarantineWithSpoilageRecord(
        $machineA,
        $payload(2, 'LOTE-T29-REAPERTURA'),
        'https://example.test/t29-evidencia-reapertura.jpg',
        $technician
    );
    $secondAct = $secondLift['spoilage_record'];
    $secondRecordCode = (string)$secondAct['record_code'];

    $assert(
        'La segunda cuarentena emite el acta 0002 ligada a la misma avería reabierta',
        $secondRecordCode === sprintf('ACTA-MERMA-%04d-0002', $year)
        && (int)$secondAct['incident_id'] === $incidentA
        && (int)$secondAct['technician_id'] === $technicianId
        && (int)$secondAct['registered_by'] === $technicianId,
        $secondRecordCode
    );

    $firstRowAfter = $pdo->query(
        'SELECT ' . $immutableProjection . ' FROM sanitary_spoilage_records WHERE id = ' . $firstRecordId
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        'El acta 0001 permanece intacta tras la segunda emisión (Art. III.3)',
        is_array($firstRowBefore) && $firstRowBefore === $firstRowAfter,
        $firstRowBefore === false ? 'no se pudo leer el acta 0001' : 'hechos sin cambios'
    );

    echo "\n--- 3. Levantamiento por Coordinador y autoría ejecutora (QA 1) ---\n";

    $machineB = $insertMachine($pdo, $locationId, 'T29-B');
    [$missingTechnicianCode, $missingTechnicianStatus] = $errorOf(
        static fn () => $service->liftQuarantineWithSpoilageRecord(
            $machineB,
            $payload(),
            'https://example.test/t29-evidencia-b-sin-tecnico.jpg',
            $coordinator
        )
    );
    $assert(
        'Coordinación no puede certificar sin declarar el técnico ejecutor',
        $missingTechnicianCode === 'EXECUTING_TECHNICIAN_REQUIRED' && $missingTechnicianStatus === 422,
        $missingTechnicianCode
    );
    $assert(
        'El intento fallido no deja acta ni modifica la máquina',
        (int)$pdo->query('SELECT COUNT(*) FROM sanitary_spoilage_records WHERE machine_id = ' . $machineB)->fetchColumn() === 0
        && (string)$pdo->query('SELECT sanitary_status FROM machines WHERE id = ' . $machineB)->fetchColumn() === 'QUARANTINE'
    );

    $coordinatorPayload = $payload();
    $coordinatorPayload['technician_id'] = $technicianId;
    $coordinatorLift = $service->liftQuarantineWithSpoilageRecord(
        $machineB,
        $coordinatorPayload,
        'https://example.test/t29-evidencia-b.jpg',
        $coordinator
    );
    $coordinatorAct = $coordinatorLift['spoilage_record'];
    $coordinatorRecordId = (int)$coordinatorAct['id'];
    $assert(
        'Coordinación registra el acta con el técnico ejecutor y ella como registradora',
        (string)$coordinatorAct['record_code'] === sprintf('ACTA-MERMA-%04d-0003', $year)
        && (int)$coordinatorAct['technician_id'] === $technicianId
        && (int)$coordinatorAct['registered_by'] === $coordinatorId
        && array_key_exists('estimated_merchandise_loss_eur', $coordinatorAct),
        (string)$coordinatorAct['record_code']
    );

    $coordinatorAudit = $auditOf($coordinatorRecordId, 'CREATE_SPOILAGE_RECORD_AND_LIFT_QUARANTINE');
    $coordinatorAuditMetadata = is_array($coordinatorAudit)
        ? json_decode((string)$coordinatorAudit['metadata'], true)
        : null;
    $assert(
        'La auditoría de Coordinación distingue ejecutor y registrador',
        is_array($coordinatorAudit)
        && (int)$coordinatorAudit['user_id'] === $coordinatorId
        && $coordinatorAudit['user_role'] === 'COORDINATOR'
        && is_array($coordinatorAuditMetadata)
        && (int)($coordinatorAuditMetadata['technician_id'] ?? 0) === $technicianId
        && (int)($coordinatorAuditMetadata['registered_by'] ?? 0) === $coordinatorId
    );

    echo "\n--- 4. Bloqueo por avería abierta y exclusión de inactivas (C1, QA 3) ---\n";

    $machineC = $insertMachine($pdo, $locationId, 'T29-C');
    $incidentC = $insertIncident($pdo, $machineC, $locationId, $technicianId, 'T29-INC-C', 'ASSIGNED');
    $machineD = $insertMachine($pdo, $locationId, 'T29-D-INACTIVA', false, 'QUARANTINE');
    $machineE = $insertMachine($pdo, $locationId, 'T29-E-OK', true, 'OK');

    $panelBlocked = $service->listForCoordinator([]);
    $blockedIds = array_map(
        static fn (array $machine): int => (int)$machine['machine_id'],
        $panelBlocked['active_quarantined_machines']
    );
    $machineCEntry = null;
    foreach ($panelBlocked['active_quarantined_machines'] as $entry) {
        if ((int)$entry['machine_id'] === $machineC) {
            $machineCEntry = $entry;
            break;
        }
    }
    $assert(
        'El panel muestra la cuarentena bloqueada y excluye la máquina inactiva',
        (int)$panelBlocked['kpis']['active_quarantined_machines_count'] === 1
        && in_array($machineC, $blockedIds, true)
        && !in_array($machineD, $blockedIds, true)
        && is_array($machineCEntry)
        && ($machineCEntry['has_open_incident'] ?? false) === true
        && ($machineCEntry['open_incident_status'] ?? '') === 'ASSIGNED'
    );

    foreach (['ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS'] as $blockedStatus) {
        $pendingReason = $blockedStatus === 'PENDING_PARTS'
            ? 'Pendiente de recepción del compresor de sustitución para completar la reparación.'
            : null;
        $pdo->prepare("
            UPDATE incidents
            SET status = :status,
                pending_parts_reason = :pending_parts_reason
            WHERE id = :id
        ")->execute([
            ':status' => $blockedStatus,
            ':pending_parts_reason' => $pendingReason,
            ':id' => $incidentC,
        ]);

        $beforeBlockedCount = (int)$pdo->query(
            'SELECT COUNT(*) FROM sanitary_spoilage_records WHERE machine_id = ' . $machineC
        )->fetchColumn();
        [$blockedCode, $blockedHttpStatus] = $errorOf(
            static fn () => $service->liftQuarantineWithSpoilageRecord(
                $machineC,
                $payload(),
                'https://example.test/t29-evidencia-c.jpg',
                $technician
            )
        );
        $afterBlockedCount = (int)$pdo->query(
            'SELECT COUNT(*) FROM sanitary_spoilage_records WHERE machine_id = ' . $machineC
        )->fetchColumn();
        $assert(
            'La avería ' . $blockedStatus . ' bloquea el levantamiento con 409',
            $blockedCode === 'ACTIVE_INCIDENT_BLOCKS_QUARANTINE_LIFT'
            && $blockedHttpStatus === 409
            && $beforeBlockedCount === $afterBlockedCount
            && (string)$pdo->query('SELECT sanitary_status FROM machines WHERE id = ' . $machineC)->fetchColumn() === 'QUARANTINE',
            $blockedCode
        );
    }

    $pdo->prepare("
        UPDATE incidents
        SET status = 'RESOLVED',
            resolved_at = CURRENT_TIMESTAMP + INTERVAL '3 seconds',
            resolution_diagnosis = 'Diagnóstico final de la avería térmica con sonda calibrada y registro completo.',
            resolution_action = 'Se sustituyó el compresor, se restituyó el frío y se desinfectó la cabina.',
            pending_parts_reason = NULL
        WHERE id = :id
    ")->execute([':id' => $incidentC]);

    $thirdLift = $service->liftQuarantineWithSpoilageRecord(
        $machineC,
        $payload(2, 'LOTE-T29-CERRADA'),
        'https://example.test/t29-evidencia-c-resuelta.jpg',
        $technician
    );
    $thirdAct = $thirdLift['spoilage_record'];
    $assert(
        'Al resolverse la avería, la máquina bloqueada emite el acta 0004',
        (string)$thirdAct['record_code'] === sprintf('ACTA-MERMA-%04d-0004', $year)
        && (int)$thirdAct['incident_id'] === $incidentC
    );

    $panelAfterC = $service->listForCoordinator([]);
    $assert(
        'La inactiva no reaparece en el panel ni en el contador de cuarentenas',
        (int)$panelAfterC['kpis']['active_quarantined_machines_count'] === 0
        && $panelAfterC['active_quarantined_machines'] === []
    );

    [$inactiveCode, $inactiveStatus] = $errorOf(
        static fn () => $service->liftQuarantineWithSpoilageRecord(
            $machineD,
            $payload(),
            'https://example.test/t29-evidencia-d.jpg',
            $technician
        )
    );
    $assert(
        'Una máquina inactiva no puede levantar la cuarentena',
        $inactiveCode === 'MACHINE_INACTIVE' && $inactiveStatus === 409,
        $inactiveCode
    );

    [$notQuarantineCode, $notQuarantineStatus] = $errorOf(
        static fn () => $service->liftQuarantineWithSpoilageRecord(
            $machineE,
            $payload(),
            'https://example.test/t29-evidencia-e.jpg',
            $technician
        )
    );
    $assert(
        'Una máquina fuera de cuarentena no puede emitir un acta de levantamiento',
        $notQuarantineCode === 'MACHINE_NOT_IN_QUARANTINE' && $notQuarantineStatus === 409,
        $notQuarantineCode
    );

    [$missingMachineCode, $missingMachineStatus] = $errorOf(
        static fn () => $service->liftQuarantineWithSpoilageRecord(
            99999999,
            $payload(),
            'https://example.test/t29-evidencia-inexistente.jpg',
            $technician
        )
    );
    $assert(
        'Una máquina inexistente responde 404 MACHINE_NOT_FOUND',
        $missingMachineCode === 'MACHINE_NOT_FOUND' && $missingMachineStatus === 404,
        $missingMachineCode
    );

    [$missingActCode, $missingActStatus] = $errorOf(
        static fn () => $service->getOfficialAct(99999999, $coordinator)
    );
    $assert(
        'Un acta inexistente responde 404 SPOILAGE_RECORD_NOT_FOUND',
        $missingActCode === 'SPOILAGE_RECORD_NOT_FOUND' && $missingActStatus === 404,
        $missingActCode
    );

    [$missingHistoryCode, $missingHistoryStatus] = $errorOf(
        static fn () => $service->listByMachine(99999999, 'TECHNICIAN')
    );
    $assert(
        'El historial de una máquina inexistente responde 404 MACHINE_NOT_FOUND',
        $missingHistoryCode === 'MACHINE_NOT_FOUND' && $missingHistoryStatus === 404,
        $missingHistoryCode
    );

    echo "\n--- 5. Acta vacía, ajustes sucesivos y auditoría antes/después (C5, QA 4) ---\n";

    $machineF = $insertMachine($pdo, $locationId, 'T29-F-VACIA');
    $emptyLift = $service->liftQuarantineWithSpoilageRecord(
        $machineF,
        $payload(0),
        'https://example.test/t29-evidencia-f-vacia.jpg',
        $technician
    );
    $emptyAct = $emptyLift['spoilage_record'];
    $emptyCost = (float)$pdo->query(
        'SELECT estimated_merchandise_loss_eur FROM sanitary_spoilage_records WHERE id = ' . (int)$emptyAct['id']
    )->fetchColumn();
    $assert(
        'La máquina vacía emite un acta de cero unidades con coste 0,00 €',
        (string)$emptyAct['record_code'] === sprintf('ACTA-MERMA-%04d-0005', $year)
        && (int)$emptyAct['discarded_units_count'] === 0
        && ($emptyAct['affected_batch_codes'] ?? '') === 'SIN_STOCK_AFECTADO'
        && ($emptyAct['disposal_method'] ?? '') === 'NOT_APPLICABLE_EMPTY'
        && abs($emptyCost) < 0.001
    );

    [$emptyAdjustCode, $emptyAdjustStatus] = $errorOf(
        static fn () => $service->adjustLossEstimate(
            (int)$emptyAct['id'],
            [
                'estimated_merchandise_loss_eur' => 5.00,
                'loss_adjustment_reason' => 'Intento improcedente de ajustar un acta sin producto destruido.',
            ],
            $coordinator
        )
    );
    $assert(
        'El acta de cero unidades rechaza cualquier ajuste económico',
        $emptyAdjustCode === 'EMPTY_MACHINE_LOSS_CANNOT_BE_ADJUSTED' && $emptyAdjustStatus === 422,
        $emptyAdjustCode
    );

    $firstAdjustmentReason = 'Ajuste según albarán del lote T29 con coste unitario revisado y validado.';
    $firstAdjustment = $service->adjustLossEstimate(
        $firstRecordId,
        [
            'estimated_merchandise_loss_eur' => 7.00,
            'loss_adjustment_reason' => $firstAdjustmentReason,
        ],
        $coordinator
    );
    $assert(
        'El primer ajuste guarda 7,00 € y la autoría de Coordinación',
        abs((float)$firstAdjustment['spoilage_record']['estimated_merchandise_loss_eur'] - 7.00) < 0.001
        && (int)$firstAdjustment['spoilage_record']['loss_adjusted_by'] === $coordinatorId
    );

    $secondAdjustmentReason = 'Segundo ajuste tras conciliación del proveedor con importe definitivo aprobado.';
    $secondAdjustment = $service->adjustLossEstimate(
        $firstRecordId,
        [
            'estimated_merchandise_loss_eur' => 9.50,
            'loss_adjustment_reason' => $secondAdjustmentReason,
        ],
        $coordinator
    );
    $assert(
        'El segundo ajuste sucesivo guarda 9,50 €',
        abs((float)$secondAdjustment['spoilage_record']['estimated_merchandise_loss_eur'] - 9.50) < 0.001
    );

    [$identicalCode, $identicalStatus] = $errorOf(
        static fn () => $service->adjustLossEstimate(
            $firstRecordId,
            [
                'estimated_merchandise_loss_eur' => 9.50,
                'loss_adjustment_reason' => 'Ajuste idéntico que no modifica el importe vigente del acta.',
            ],
            $coordinator
        )
    );
    $assert(
        'Un ajuste con importe idéntico se rechaza con 422',
        $identicalCode === 'IDENTICAL_LOSS_ESTIMATE' && $identicalStatus === 422,
        $identicalCode
    );

    $adjustmentAuditStmt = $pdo->prepare("
        SELECT previous_state, new_state
        FROM audit_log
        WHERE entity_type = 'SANITARY_SPOILAGE_RECORD'
          AND entity_id = :entity_id
          AND action = 'ADJUST_SPOILAGE_MERCHANDISE_LOSS'
        ORDER BY id ASC
    ");
    $adjustmentAuditStmt->execute([':entity_id' => $firstRecordId]);
    $adjustmentAuditRows = $adjustmentAuditStmt->fetchAll(PDO::FETCH_ASSOC);
    $adjustmentPreviousFirst = isset($adjustmentAuditRows[0])
        ? json_decode((string)$adjustmentAuditRows[0]['previous_state'], true)
        : null;
    $adjustmentNewFirst = isset($adjustmentAuditRows[0])
        ? json_decode((string)$adjustmentAuditRows[0]['new_state'], true)
        : null;
    $adjustmentPreviousSecond = isset($adjustmentAuditRows[1])
        ? json_decode((string)$adjustmentAuditRows[1]['previous_state'], true)
        : null;
    $adjustmentNewSecond = isset($adjustmentAuditRows[1])
        ? json_decode((string)$adjustmentAuditRows[1]['new_state'], true)
        : null;
    $assert(
        'Los dos ajustes auditan el importe anterior y el nuevo',
        count($adjustmentAuditRows) === 2
        && is_array($adjustmentPreviousFirst)
        && is_array($adjustmentNewFirst)
        && is_array($adjustmentPreviousSecond)
        && is_array($adjustmentNewSecond)
        && abs((float)$adjustmentPreviousFirst['estimated_merchandise_loss_eur'] - 5.00) < 0.001
        && abs((float)$adjustmentNewFirst['estimated_merchandise_loss_eur'] - 7.00) < 0.001
        && abs((float)$adjustmentPreviousSecond['estimated_merchandise_loss_eur'] - 7.00) < 0.001
        && abs((float)$adjustmentNewSecond['estimated_merchandise_loss_eur'] - 9.50) < 0.001
    );

    $historyAfterAdjustments = $service->listByMachine($machineA, 'COORDINATOR');
    $adjustedAct = $recordByCode($historyAfterAdjustments['records'], $firstRecordCode);
    $assert(
        'El historial de Coordinación refleja el ajuste final del acta 0001',
        is_array($adjustedAct)
        && abs((float)$adjustedAct['estimated_merchandise_loss_eur'] - 9.50) < 0.001
        && (int)$adjustedAct['loss_adjusted_by'] === $coordinatorId
    );

    $panelBeforeCsv = $service->listForCoordinator([]);
    $assert(
        'Los KPIs globales cuentan cinco actas y ocho unidades sin la inactiva',
        (int)$panelBeforeCsv['kpis']['total_records_count'] === 5
        && (int)$panelBeforeCsv['kpis']['total_discarded_units_count'] === 8
        && (int)$panelBeforeCsv['kpis']['active_quarantined_machines_count'] === 0
        && abs((float)$panelBeforeCsv['kpis']['total_estimated_loss_eur'] - 24.50) < 0.01,
        'actas: ' . $panelBeforeCsv['kpis']['total_records_count']
    );

    echo "\n--- 6. Exportación CSV: BOM, secciones, cero filas y anti-fórmulas (QA 4) ---\n";

    $machineG = $insertMachine($pdo, $locationId, 'T29-G-FORMULA');
    $formulaLift = $service->liftQuarantineWithSpoilageRecord(
        $machineG,
        $payload(2, '=SUM(A1:A2)'),
        'https://example.test/t29-evidencia-g-formula.jpg',
        $technician
    );
    $formulaAct = $formulaLift['spoilage_record'];
    $assert(
        'La sexta acta se emite con el lote de prueba anti-fórmulas',
        (string)$formulaAct['record_code'] === sprintf('ACTA-MERMA-%04d-0006', $year)
    );

    $formulaVariants = ['=SUM(A1:A2)', '+SUM(A1:A2)', '-SUM(A1:A2)', '@SUM(A1:A2)'];
    $formulaInsert = $pdo->prepare("
        INSERT INTO sanitary_spoilage_records (
            record_code, machine_id, technician_id, registered_by, quarantine_origin,
            discarded_units_count, empty_machine_confirmed, affected_batch_codes,
            cabin_temp_at_removal, restored_stabilized_temp_c, disposal_method,
            evidence_photo_url, sanitary_notes, estimated_merchandise_loss_eur, certificate_code
        ) VALUES (
            :record_code, :machine_id, :technician_id, :registered_by, 'MANUAL_SANITARY_LOCK',
            2, FALSE, :batch_codes, 7.5, 3.5, 'ON_SITE_ORGANIC_SEALED',
            :evidence_photo_url, :sanitary_notes, 5.00, :certificate_code
        )
    ");
    foreach ($formulaVariants as $index => $variant) {
        $formulaInsert->execute([
            ':record_code' => sprintf('ACTA-MERMA-%04d-9%03d', $year, 100 + $index),
            ':machine_id' => $machineG,
            ':technician_id' => $technicianId,
            ':registered_by' => $technicianId,
            ':batch_codes' => $variant,
            ':evidence_photo_url' => 'https://example.test/t29-evidencia-formula-' . $index . '.jpg',
            ':sanitary_notes' => 'Acta de prueba creada para certificar la neutralización de fórmulas en el CSV.',
            ':certificate_code' => sprintf('CERT-T29-F%02d', $index + 1),
        ]);
    }

    $formulaCsv = $service->exportCsvForCoordinator(['machine_id' => $machineG], $coordinator);
    $allFormulasEscaped = true;
    foreach ($formulaVariants as $variant) {
        if (!str_contains($formulaCsv, "'" . $variant)) {
            $allFormulasEscaped = false;
        }
    }
    $assert(
        'El CSV lleva BOM, ambas secciones y los cuatro KPIs',
        str_starts_with($formulaCsv, "\xEF\xBB\xBF")
        && str_contains($formulaCsv, 'SECCIÓN 1')
        && str_contains($formulaCsv, 'SECCIÓN 2')
        && str_contains($formulaCsv, 'Máquinas en Cuarentena Activa')
        && str_contains($formulaCsv, 'Total de Actas Emitidas')
        && str_contains($formulaCsv, 'Unidades Perecederas Destruidas')
        && str_contains($formulaCsv, 'Merma Económica Estimada')
    );
    $assert(
        'El CSV neutraliza las celdas que empiezan por =, +, - y @',
        $allFormulasEscaped,
        implode(' | ', $formulaVariants)
    );
    $assert(
        'El CSV filtrado contiene el acta de la máquina seleccionada',
        str_contains($formulaCsv, (string)$formulaAct['record_code'])
    );

    $emptyCsv = $service->exportCsvForCoordinator(['machine_id' => $machineD], $coordinator);
    $assert(
        'El CSV con cero filas conserva cabeceras y no inventa actas',
        str_starts_with($emptyCsv, "\xEF\xBB\xBF")
        && str_contains($emptyCsv, 'Código de Acta')
        && str_contains($emptyCsv, 'SECCIÓN 2')
        && !str_contains($emptyCsv, 'ACTA-MERMA-')
    );

    $exportAuditCountBefore = (int)$pdo->query("
        SELECT COUNT(*) FROM audit_log
        WHERE entity_type = 'SANITARY_SPOILAGE_RECORD'
          AND action = 'EXPORT_SPOILAGE_RECORDS_CSV'
    ")->fetchColumn();
    [$invalidDateCode, $invalidDateStatus] = $errorOf(
        static fn () => $service->exportCsvForCoordinator(['date_from' => '2026-02-31'], $coordinator)
    );
    $exportAuditCountAfter = (int)$pdo->query("
        SELECT COUNT(*) FROM audit_log
        WHERE entity_type = 'SANITARY_SPOILAGE_RECORD'
          AND action = 'EXPORT_SPOILAGE_RECORDS_CSV'
    ")->fetchColumn();
    $assert(
        'El CSV rechaza una fecha imposible sin auditar la exportación',
        $invalidDateCode === 'INVALID_DATE_RANGE'
        && $invalidDateStatus === 422
        && $exportAuditCountBefore === $exportAuditCountAfter
    );
    $assert(
        'Cada exportación válida deja su evento de auditoría',
        $exportAuditCountBefore === 2
    );

    $panelAfterCsv = $service->listForCoordinator([]);
    $assert(
        'Los cuatro registros sintéticos de fórmula se suman al libro de mermas',
        (int)$panelAfterCsv['kpis']['total_records_count'] === 10
    );

    echo "\n--- 7. Límite superior del filtro de fechas: día final completo (QA 4.3) ---\n";

    $machineH = $insertMachine($pdo, $locationId, 'T29-H-ULTIMO-SEGUNDO');
    $lateDay = '2026-03-15';
    $lateRecordCode = sprintf('ACTA-MERMA-%04d-9007', $year);
    $lateInsert = $pdo->prepare("
        INSERT INTO sanitary_spoilage_records (
            record_code, machine_id, technician_id, registered_by, quarantine_origin,
            discarded_units_count, empty_machine_confirmed, affected_batch_codes,
            cabin_temp_at_removal, restored_stabilized_temp_c, disposal_method,
            evidence_photo_url, sanitary_notes, estimated_merchandise_loss_eur,
            certificate_code, created_at
        ) VALUES (
            :record_code, :machine_id, :technician_id, :registered_by, 'MANUAL_SANITARY_LOCK',
            2, FALSE, 'LOTE-T29-ULTIMO-SEGUNDO', 7.5, 3.5, 'ON_SITE_ORGANIC_SEALED',
            :evidence_photo_url, :sanitary_notes, 5.00, :certificate_code,
            CAST(:created_at AS timestamptz)
        )
    ");
    $lateInsert->execute([
        ':record_code' => $lateRecordCode,
        ':machine_id' => $machineH,
        ':technician_id' => $technicianId,
        ':registered_by' => $technicianId,
        ':evidence_photo_url' => 'https://example.test/t29-evidencia-ultimo-segundo.jpg',
        ':sanitary_notes' => 'Acta emitida en el último medio segundo del día para certificar el límite superior del filtro.',
        ':certificate_code' => 'CERT-T29-LATE01',
        ':created_at' => $lateDay . ' 23:59:59.5',
    ]);

    $lateDayPanel = $service->listForCoordinator([
        'machine_id' => $machineH,
        'date_from' => $lateDay,
        'date_to' => $lateDay,
    ]);
    $assert(
        'date_to incluye un acta emitida a las 23:59:59.5 del último día',
        count($lateDayPanel['records']) === 1
        && (string)$lateDayPanel['records'][0]['record_code'] === $lateRecordCode,
        'actas: ' . count($lateDayPanel['records'])
    );

    $lateDayCsv = $service->exportCsvForCoordinator([
        'machine_id' => $machineH,
        'date_from' => $lateDay,
        'date_to' => $lateDay,
    ], $coordinator);
    $assert(
        'El CSV del día incluye el acta del último medio segundo',
        str_contains($lateDayCsv, $lateRecordCode)
    );

    $dayBeforePanel = $service->listForCoordinator([
        'machine_id' => $machineH,
        'date_from' => '2026-03-14',
        'date_to' => '2026-03-14',
    ]);
    $assert(
        'El límite superior exclusivo no arrastra el día anterior',
        $dayBeforePanel['records'] === []
    );
} catch (Throwable $exception) {
    $crash = $exception;
    fwrite(STDERR, "\n[EXCEPCIÓN NO CONTROLADA] " . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getFile() . ':' . $exception->getLine() . "\n");
    $failures++;
} finally {
    if ($pdo instanceof PDO) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, SPOILAGE_SERVICE_SCHEMA);
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, "\n[LIMPIEZA] No se pudo retirar el esquema aislado: " . $cleanupException->getMessage() . "\n");
        }
    }
}

echo "\n======================================================================\n";
echo " VENDGUARD · T-SPOILAGE-09 · RESULTADO\n";
echo " Aserciones: {$assertions} · Fallos: {$failures}\n";
echo "======================================================================\n";

if ($failures > 0) {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
    exit(1);
}

echo " RESULTADO: 100% EN VERDE.\n";
exit(0);
