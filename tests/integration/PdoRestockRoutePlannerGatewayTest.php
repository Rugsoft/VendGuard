<?php

declare(strict_types=1);

/**
 * PdoRestockRoutePlannerGatewayTest · T-RRP-06
 *
 * Certifica en PostgreSQL el gateway transaccional del Planificador de Rutas
 * (Módulo 37) y el perímetro temporal `REASSIGNED` unificado en M32, M34, M35 y
 * M36.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Lectura y bloqueo.** `lockMachine()` devuelve los hechos de contexto
 *      con `FOR UPDATE` y tolera identificadores inexistentes.
 *   2. **Versionado atómico de agendas (`QA-2`, Art. III.3).** Guardar una
 *      versión cierra la vigente e inserta la nueva en la misma transacción; el
 *      histórico queda inmutable y el índice único parcial impide dos activas.
 *   3. **Excepciones diarias (`C1`).** Inserción, unicidad de la excepción viva,
 *      revocación y superación por la visita física real, con `resolved_by` y
 *      `resolved_at` sellados.
 *   4. **Salvaguarda sanitaria (`QA-1`, Art. II).** Sello `EXPIRED` sin tocar la
 *      cuarentena y alerta interna `RESTOCK_FEFO_STOP_SKIPPED_ALERT` con
 *      prioridad `CRITICAL_SANITARY` y rol destinatario `COORDINATOR`.
 *   5. **Idempotencia offline (`C3`).** La misma mutación registrada dos veces
 *      devuelve el mismo identificador y su payload original.
 *   6. **Perímetro `REASSIGNED` (`QA-3`).** Un desvío activo de hoy habilita al
 *      receptor en el planograma M32, en la visita M34, en la recaudación M35 y
 *      en la Coincidencia en Sede M36, sin asignarle la zona entera.
 *   7. **Cero borrados (Art. III.1).** El disparador `prevent_hard_delete()`
 *      rechaza cualquier `DELETE` sobre agendas y excepciones.
 *   8. **Ausencia de N+1 (RNF-02).** `PdoRestockRouteReadModel::loadParkSnapshot()`
 *      y `RestockRoutePlannerService` componen la hoja móvil y el tablero diario
 *      con exactamente 3 sentencias SQL tanto con 4 máquinas como con 12 máquinas.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL; sin `PG_TEST_DSN` sale con 2.
 * LIMPIEZA: esquema aislado `t_rrp06_gateway` borrado con `CASCADE`, incluso si
 * una aserción falla.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\RestockRoutePlannerService;
use VendGuard\Core\Domain\Enum\RestockCadenceType;
use VendGuard\Core\Domain\Enum\RestockOverrideStatus;
use VendGuard\Core\Domain\Enum\RestockOverrideType;
use VendGuard\Core\Domain\Enum\RestockReassignmentReasonCode;
use VendGuard\Core\Domain\Enum\RestockSkipReasonCode;
use VendGuard\Core\Domain\Enum\RestockTimeWindow;
use VendGuard\Core\Domain\Model\MachineRestockSchedule;
use VendGuard\Core\Domain\Model\RestockRouteDailyOverride;
use VendGuard\Infrastructure\Repository\PdoInSituRestockVisitGateway;
use VendGuard\Infrastructure\Repository\PdoMachineCashCollectionGateway;
use VendGuard\Infrastructure\Repository\PdoMachinePlanogramRepository;
use VendGuard\Infrastructure\Repository\PdoRestockRoutePlannerGateway;
use VendGuard\Infrastructure\Repository\PdoRestockRouteReadModel;
use VendGuard\Infrastructure\Repository\PdoRestockerSatSynergyGateway;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const RRP06_SCHEMA = 't_rrp06_gateway';

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

$expectSqlState = static function (callable $operation, string $sqlState): bool {
    try {
        $operation();
    } catch (PDOException $exception) {
        return (string)$exception->getCode() === $sqlState;
    }

    return false;
};

/**
 * PDO con contador de sentencias para certificar ausencia de N+1 (RNF-02).
 */
final class RrpCountingPdo extends PDO
{
    public int $statements = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->statements++;

        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->statements++;

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

echo "======================================================================\n";
echo " VENDGUARD · T-RRP-06 · Gateway del planificador y perímetro REASSIGNED\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pdo = PostgresFixtureBuilder::gateOrExit();
$countingPdo = null;

try {
    PostgresFixtureBuilder::createIsolationSchema($pdo, RRP06_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');
    $tomorrow = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))
        ->modify('+1 day')
        ->format('Y-m-d');

    echo "\n--- Escenario (zona, sede, cuatro máquinas y tres operarios) ---\n";

    $zoneId = (int)$scalar(
        $pdo,
        "INSERT INTO operational_zones (code, name) VALUES ('Z-RRP-06', 'Zona Planificador 37') RETURNING id"
    );
    $locationId = (int)$scalar(
        $pdo,
        "INSERT INTO locations (site_code, site_code_key, name, address, zone_id, latitude, longitude, sla_tier)
         VALUES ('RRP-06', 'RRP-06', 'Sede Planificador 37', 'Calle de la Ruta 37', :zone, 41.385064, 2.1734035,
                 'CRITICAL_INFRASTRUCTURE_24_7')
         RETURNING id",
        [':zone' => $zoneId]
    );

    $machineIds = [];
    for ($i = 1; $i <= 4; $i++) {
        $machineIds[$i] = (int)$scalar(
            $pdo,
            "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location, :code, 'Modelo RRP', 'PERISHABLE_FOOD', 'Planta 1')
             RETURNING id",
            [':location' => $locationId, ':code' => sprintf('VEND-RRP-%03d', 700 + $i)]
        );
    }

    $createUser = static function (string $name, string $email, string $role, string $code) use ($pdo, $scalar): int {
        return (int)$scalar(
            $pdo,
            "INSERT INTO users (name, email, email_key, password_hash, role, operator_code, phone, is_active)
             VALUES (:name, :email, :email, 'hash', :role, :code, '+34600000000', TRUE)
             RETURNING id",
            [
                ':name' => $name,
                ':email' => $email,
                ':role' => $role,
                ':code' => $code,
            ]
        );
    };

    $coordinatorId = $createUser('Coordinación RRP', 'coord.rrp06@test.local', 'COORDINATOR', 'COO-06');
    $ownerId = $createUser('Reponedora Titular', 'owner.rrp06@test.local', 'RESTOCKER', 'REP-06');
    $receiverId = $createUser('Reponedor Receptor', 'receiver.rrp06@test.local', 'RESTOCKER', 'REP-07');
    $technicianId = $createUser('Técnico RRP', 'tech.rrp06@test.local', 'TECHNICIAN', 'TEC-06');

    $pdo->exec(
        "INSERT INTO restocker_zone_assignments (restocker_id, zone_id, role_in_zone, is_primary)
         VALUES ({$ownerId}, {$zoneId}, 'PRIMARY', TRUE)"
    );
    $pdo->exec(
        "INSERT INTO technician_shifts (technician_id, shift_date, status)
         VALUES ({$receiverId}, '{$today}', 'ON_SHIFT'),
                ({$technicianId}, '{$today}', 'ON_SHIFT')"
    );

    $gateway = new PdoRestockRoutePlannerGateway($pdo);
    $planograms = new PdoMachinePlanogramRepository($pdo);
    $visits = new PdoInSituRestockVisitGateway($pdo);
    $collections = new PdoMachineCashCollectionGateway($pdo);
    $synergy = new PdoRestockerSatSynergyGateway($pdo);

    // =================================================================
    // 1. Lectura y bloqueo de la máquina
    // =================================================================
    echo "\n--- 1. lockMachine ---\n";

    $machine = $gateway->lockMachine($machineIds[1]);
    $assert(
        '1.1 lockMachine devuelve código, sede, zona, SLA y estado sanitario',
        $machine !== null
            && $machine['machine_code'] === 'VEND-RRP-701'
            && $machine['location_id'] === $locationId
            && $machine['zone_id'] === $zoneId
            && $machine['sla_tier'] === 'CRITICAL_INFRASTRUCTURE_24_7'
            && $machine['sanitary_status'] === 'OK'
            && $machine['is_active'] === true,
        json_encode($machine)
    );
    $assert(
        '1.2 lockMachine tolera identificadores inválidos o inexistentes',
        $gateway->lockMachine(0) === null && $gateway->lockMachine(999999) === null
    );
    $assert(
        '1.3 lockMachine publica la pausa estacional y el bloqueo operativo',
        $machine !== null
            && $machine['is_seasonal_pause'] === false
            && $machine['seasonal_pause_until'] === null
            && $machine['is_out_of_service'] === false
    );

    // =================================================================
    // 2. Versionado atómico de agendas (QA-2, Art. III.3)
    // =================================================================
    echo "\n--- 2. Agendas versionadas ---\n";

    $assert('2.1 Una máquina sin agenda devuelve null', $gateway->findActiveSchedule($machineIds[1]) === null);

    $firstSchedule = new MachineRestockSchedule(
        null,
        $machineIds[1],
        RestockCadenceType::DAILY,
        null,
        null,
        RestockTimeWindow::MORNING,
        false,
        'Agenda inicial T-RRP-06.',
        true,
        $coordinatorId,
        $coordinatorId
    );
    $firstId = $gateway->saveScheduleVersion($firstSchedule);
    $active = $gateway->findActiveSchedule($machineIds[1]);

    $assert(
        '2.2 saveScheduleVersion inserta la primera versión activa',
        $firstId > 0
            && $active !== null
            && $active->getId() === $firstId
            && $active->getCadenceType() === RestockCadenceType::DAILY
            && $active->getPreferredTimeWindow() === RestockTimeWindow::MORNING
            && $active->isActive()
    );
    $assert(
        '2.3 listActiveSchedules indexa por machine_id',
        isset($gateway->listActiveSchedules()[$machineIds[1]])
            && $gateway->listActiveSchedules()[$machineIds[1]]->getId() === $firstId
    );

    $secondSchedule = new MachineRestockSchedule(
        null,
        $machineIds[1],
        RestockCadenceType::WEEKLY,
        3,
        null,
        RestockTimeWindow::AFTERNOON,
        false,
        'Versión semanal T-RRP-06.',
        true,
        $coordinatorId,
        $coordinatorId
    );
    $secondId = $gateway->saveScheduleVersion($secondSchedule);

    $activeCount = (int)$scalar(
        $pdo,
        'SELECT COUNT(*) FROM machine_restock_schedules WHERE machine_id = :machine AND is_active = TRUE',
        [':machine' => $machineIds[1]]
    );
    $closedRow = $pdo->query(
        'SELECT is_active, deactivated_by, deactivated_at
           FROM machine_restock_schedules WHERE id = ' . $firstId
    )->fetch(PDO::FETCH_ASSOC);
    $historyCount = (int)$scalar(
        $pdo,
        'SELECT COUNT(*) FROM machine_restock_schedules WHERE machine_id = :machine',
        [':machine' => $machineIds[1]]
    );

    $asBool = static fn(mixed $value): bool => is_bool($value)
        ? $value
        : in_array(strtolower(trim((string)$value)), ['1', 't', 'true'], true);
    $assert(
        '2.4 La segunda versión cierra la vigente e inserta la nueva',
        $secondId > $firstId
            && $activeCount === 1
            && $asBool($closedRow['is_active']) === false
            && (int)$closedRow['deactivated_by'] === $coordinatorId
            && $closedRow['deactivated_at'] !== null
            && $historyCount === 2,
        json_encode([
            'second' => $secondId,
            'first' => $firstId,
            'active' => $activeCount,
            'closed' => $closedRow,
            'history' => $historyCount,
        ])
    );
    $assert(
        '2.5 El índice único parcial impide dos agendas activas',
        $expectSqlState(
            static fn() => $pdo->exec(
                "INSERT INTO machine_restock_schedules (machine_id, cadence_type, created_by, updated_by)
                 VALUES ({$machineIds[1]}, 'DAILY', {$coordinatorId}, {$coordinatorId})"
            ),
            '23505'
        )
    );
    $assert(
        '2.6 deactivateSchedule cierra una sola vez',
        $gateway->deactivateSchedule($secondId, $coordinatorId, $today . ' 12:00:00+01') === true
            && $gateway->deactivateSchedule($secondId, $coordinatorId, $today . ' 12:05:00+01') === false
            && $gateway->findActiveSchedule($machineIds[1]) === null
    );
    $assert(
        '2.7 prevent_hard_delete rechaza el borrado físico de la agenda',
        $expectSqlState(
            static fn() => $pdo->exec('DELETE FROM machine_restock_schedules WHERE id = ' . $firstId),
            '45000'
        )
    );

    // =================================================================
    // 3. Excepciones diarias (C1)
    // =================================================================
    echo "\n--- 3. Excepciones diarias ---\n";

    $skipped = new RestockRouteDailyOverride(
        null,
        $machineIds[2],
        $today,
        RestockOverrideType::SKIPPED,
        RestockOverrideStatus::ACTIVE,
        $ownerId,
        null,
        RestockSkipReasonCode::LOCATION_CLOSED,
        null,
        'La conserjería permanece cerrada con persiana metálica bajada y sin personal.',
        true,
        null,
        'skip.rrp06.0001',
        null,
        $coordinatorId
    );
    $skippedId = $gateway->insertOverride($skipped);

    $assert(
        '3.1 insertOverride persiste la omisión con su causa y motivo',
        $skippedId > 0
            && $gateway->findActiveOverride($machineIds[2], $today)?->getId() === $skippedId
            && $gateway->findActiveOverride($machineIds[2], $today)?->getSkipReasonCode()
                === RestockSkipReasonCode::LOCATION_CLOSED
            && $gateway->findActiveOverride($machineIds[2], $today)?->hadActiveFefoAlert() === true
    );
    $assert(
        '3.2 findOverrideByClientMutationId recupera la mutación offline',
        $gateway->findOverrideByClientMutationId('skip.rrp06.0001')?->getId() === $skippedId
    );
    $assert(
        '3.3 El índice único parcial impide dos excepciones vivas por máquina y día',
        $expectSqlState(
            static fn() => $gateway->insertOverride(new RestockRouteDailyOverride(
                null,
                $machineIds[2],
                $today,
                RestockOverrideType::SKIPPED,
                RestockOverrideStatus::ACTIVE,
                $ownerId,
                null,
                RestockSkipReasonCode::NO_KEY_OR_ACCESS,
                null,
                'Sin llaves de acceso al recinto durante toda la jornada.',
                false,
                null,
                null,
                null,
                $coordinatorId
            )),
            '23505'
        )
    );

    $revokedCount = $gateway->revokeActiveOverrides($machineIds[2], $today, $coordinatorId, $today . ' 13:00:00+01');
    $revokedRow = $pdo->query(
        'SELECT status, resolved_by, resolved_at
           FROM restock_route_daily_overrides WHERE id = ' . $skippedId
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        '3.4 revokeActiveOverrides sella REVOKED con actor y fecha',
        $revokedCount === 1
            && (string)$revokedRow['status'] === 'REVOKED'
            && (int)$revokedRow['resolved_by'] === $coordinatorId
            && $revokedRow['resolved_at'] !== null
            && $gateway->findActiveOverride($machineIds[2], $today) === null
    );

    $visitId = (int)$scalar(
        $pdo,
        "INSERT INTO machine_restock_visits (
            visit_code, machine_id, restocker_id, status, cabin_temp_celsius,
            client_mutation_id, started_at, completed_at
         ) VALUES ('MRV-2026-9001', :machine, :restocker, 'COMPLETED', 3.0,
                   'visit-rrp06-0001', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
         RETURNING id",
        [':machine' => $machineIds[3], ':restocker' => $ownerId]
    );
    $gateway->insertOverride(new RestockRouteDailyOverride(
        null,
        $machineIds[3],
        $today,
        RestockOverrideType::SKIPPED,
        RestockOverrideStatus::ACTIVE,
        $ownerId,
        null,
        RestockSkipReasonCode::LOCAL_HOLIDAY,
        null,
        'Festivo local decretado por el ayuntamiento con sede cerrada.',
        false,
        null,
        null,
        null,
        $coordinatorId
    ));
    $supersededCount = $gateway->supersedeSkippedOverrides($machineIds[3], $today, $visitId, $ownerId, $today . ' 14:00:00+01');
    $supersededRow = $pdo->query(
        'SELECT status, superseded_by_visit_id
           FROM restock_route_daily_overrides
          WHERE machine_id = ' . $machineIds[3] . " AND service_date = '{$today}'"
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        '3.5 La visita física supera la omisión viva',
        $supersededCount === 1
            && (string)$supersededRow['status'] === 'SUPERSEDED'
            && (int)$supersededRow['superseded_by_visit_id'] === $visitId
    );

    $gateway->insertOverride(new RestockRouteDailyOverride(
        null,
        $machineIds[3],
        $tomorrow,
        RestockOverrideType::REASSIGNED,
        RestockOverrideStatus::ACTIVE,
        null,
        $receiverId,
        null,
        RestockReassignmentReasonCode::ZONE_UNCOVERED,
        null,
        false,
        null,
        null,
        null,
        $coordinatorId
    ));
    $assert(
        '3.6 supersedeSkippedOverrides no toca un desvío REASSIGNED',
        $gateway->supersedeSkippedOverrides($machineIds[3], $tomorrow, $visitId, $ownerId, $today . ' 14:05:00+01') === 0
    );
    $assert(
        '3.7 prevent_hard_delete rechaza el borrado físico de la excepción',
        $expectSqlState(
            static fn() => $pdo->exec('DELETE FROM restock_route_daily_overrides WHERE id = ' . $skippedId),
            '45000'
        )
    );

    // =================================================================
    // 4. Salvaguarda sanitaria y alerta interna (QA-1, Art. II)
    // =================================================================
    echo "\n--- 4. Sello sanitario y alerta interna ---\n";

    $assert(
        '4.1 markMachineSanitaryExpired sella EXPIRED una sola vez',
        $gateway->markMachineSanitaryExpired($machineIds[4]) === true
            && $gateway->markMachineSanitaryExpired($machineIds[4]) === false
            && (string)$scalar(
                $pdo,
                'SELECT sanitary_status FROM machines WHERE id = :machine',
                [':machine' => $machineIds[4]]
            ) === 'EXPIRED'
    );
    $assert(
        '4.1b restoreMachineSanitaryOkIfClear restituye OK sin lotes vencidos',
        $gateway->restoreMachineSanitaryOkIfClear($machineIds[4], $today) === true
            && (string)$scalar(
                $pdo,
                'SELECT sanitary_status FROM machines WHERE id = :machine',
                [':machine' => $machineIds[4]]
            ) === 'OK'
    );
    $pdo->exec("UPDATE machines SET sanitary_status = 'QUARANTINE' WHERE id = {$machineIds[3]}");
    $assert(
        '4.1c La cuarentena no se restituye a OK',
        $gateway->restoreMachineSanitaryOkIfClear($machineIds[3], $today) === false
    );
    $assert(
        '4.2 La cuarentena no se degrada a EXPIRED',
        $gateway->markMachineSanitaryExpired($machineIds[3]) === false
            && (string)$scalar(
                $pdo,
                'SELECT sanitary_status FROM machines WHERE id = :machine',
                [':machine' => $machineIds[3]]
            ) === 'QUARANTINE'
    );

    $notificationId = $gateway->enqueueFefoSkipAlert(
        $machineIds[4],
        $today,
        $skippedId,
        'coordinacion@vendguard.internal',
        'EMAIL',
        ['earliest_fefo_expiration_date' => $today]
    );
    $notification = $pdo->query(
        'SELECT template, priority, recipient_role, channel, payload_json
           FROM notification_outbox WHERE id = ' . $notificationId
    )->fetch(PDO::FETCH_ASSOC);
    $notificationPayload = json_decode((string)$notification['payload_json'], true);
    $assert(
        '4.3 La alerta FEFO usa plantilla, prioridad y rol internos de Coordinación',
        $notificationId > 0
            && (string)$notification['template'] === 'RESTOCK_FEFO_STOP_SKIPPED_ALERT'
            && (string)$notification['priority'] === 'CRITICAL_SANITARY'
            && (string)$notification['recipient_role'] === 'COORDINATOR'
            && (string)$notification['channel'] === 'EMAIL'
            && ($notificationPayload['machine_id'] ?? null) === $machineIds[4]
    );

    // =================================================================
    // 5. Idempotencia offline (C3)
    // =================================================================
    echo "\n--- 5. Cola offline ---\n";

    $offlineId = $gateway->recordOfflineMutation(
        $receiverId,
        'skip.rrp06.offline.1',
        $machineIds[4],
        $today . 'T10:15:00+01:00',
        ['id' => $skippedId, 'status' => 'ACTIVE'],
        200
    );
    $assert(
        '5.1 recordOfflineMutation registra la mutación aplicada',
        $offlineId > 0
            && $gateway->recordOfflineMutation(
                $receiverId,
                'skip.rrp06.offline.1',
                $machineIds[4],
                $today . 'T10:15:00+01:00',
                ['id' => $skippedId, 'status' => 'ACTIVE'],
                200
            ) === $offlineId
    );
    $offline = $gateway->findOfflineMutation($receiverId, 'skip.rrp06.offline.1');
    $assert(
        '5.2 findOfflineMutation devuelve el payload original sin duplicar',
        $offline !== null
            && $offline['id'] === $offlineId
            && $offline['http_status'] === 200
            && ($offline['payload']['status'] ?? null) === 'ACTIVE'
            && (int)$scalar(
                $pdo,
                'SELECT COUNT(*) FROM offline_sync_mutations
                  WHERE technician_id = :restocker AND client_mutation_id = :mutation',
                [':restocker' => $receiverId, ':mutation' => 'skip.rrp06.offline.1']
            ) === 1
    );
    $assert(
        '5.3 findOfflineMutation devuelve null para una mutación desconocida',
        $gateway->findOfflineMutation($receiverId, 'skip.rrp06.unknown') === null
    );

    // =================================================================
    // 6. Perímetro temporal REASSIGNED en M32, M34, M35 y M36 (QA-3)
    // =================================================================
    echo "\n--- 6. Perímetro REASSIGNED en M32, M34, M35 y M36 ---\n";

    $assert(
        '6.1 El receptor no ve la máquina antes del desvío (M32)',
        $planograms->findMachineForRestocker($machineIds[1], $receiverId) === null
    );
    $assert(
        '6.2 El receptor no está cubierto antes del desvío (M34 y M35)',
        $visits->lockMachineContext($machineIds[1], $receiverId)['is_covered'] === false
            && $collections->lockMachineContext($machineIds[1], $receiverId)['is_covered'] === false
    );

    $reassignmentId = $gateway->insertOverride(new RestockRouteDailyOverride(
        null,
        $machineIds[1],
        $today,
        RestockOverrideType::REASSIGNED,
        RestockOverrideStatus::ACTIVE,
        $ownerId,
        $receiverId,
        null,
        RestockReassignmentReasonCode::FEFO_RESCUE,
        'Rescate de perecederos antes del cierre de la jornada.',
        true,
        null,
        null,
        null,
        $coordinatorId
    ));

    $activeReassignments = $gateway->findActiveReassignmentsForRestocker($receiverId, $today);
    $assert(
        '6.2b findActiveReassignmentsForRestocker devuelve el desvío vivo del receptor',
        count($activeReassignments) === 1
            && $activeReassignments[0]->getId() === $reassignmentId
            && $activeReassignments[0]->getMachineId() === $machineIds[1],
        json_encode(array_map(static fn($row): array => $row->toArray(), $activeReassignments))
    );

    $assert(
        '6.3 El desvío activo habilita la máquina en el planograma M32',
        $reassignmentId > 0
            && $planograms->findMachineForRestocker($machineIds[1], $receiverId) !== null
            && $planograms->findMachineForRestocker($machineIds[1], $ownerId) !== null
    );
    $assert(
        '6.4 El desvío activo habilita la visita M34 y la recaudación M35',
        $visits->lockMachineContext($machineIds[1], $receiverId)['is_covered'] === true
            && $collections->lockMachineContext($machineIds[1], $receiverId)['is_covered'] === true
    );

    $bootstrap = $collections->routeBootstrap($receiverId);
    $assert(
        '6.5 El bootstrap de recaudación incluye la máquina desviada',
        array_key_exists($machineIds[1], $bootstrap['machines'])
    );

    $coincidences = $synergy->technicianCoincidences($technicianId, [$locationId]);
    $coincidenceIds = array_map(
        static fn(array $row): int => (int)$row['user_id'],
        $coincidences[$locationId] ?? []
    );
    $assert(
        '6.6 La Coincidencia en Sede M36 incluye al receptor del desvío',
        in_array($receiverId, $coincidenceIds, true)
    );

    $gateway->revokeActiveOverrides($machineIds[1], $today, $coordinatorId, $today . ' 15:00:00+01');
    $assert(
        '6.7 Revocar el desvío devuelve la máquina al perímetro ordinario',
        $planograms->findMachineForRestocker($machineIds[1], $receiverId) === null
            && $visits->lockMachineContext($machineIds[1], $receiverId)['is_covered'] === false
            && $collections->lockMachineContext($machineIds[1], $receiverId)['is_covered'] === false
    );
    $assert(
        '6.8 El desvío no amplía el perímetro a la zona entera',
        $planograms->findMachineForRestocker($machineIds[2], $receiverId) === null
    );

    // =================================================================
    // 7. RNF-02 · Ausencia de N+1 en PdoRestockRouteReadModel y composición diaria
    // =================================================================
    echo "\n--- 7. RNF-02 · Ausencia de consultas N+1 ---\n";

    $pdo->exec(
        "INSERT INTO technician_shifts (technician_id, shift_date, status, started_at)
         VALUES ({$ownerId}, '{$today}', 'ON_SHIFT', CURRENT_TIMESTAMP - INTERVAL '1 hour')"
    );

    $dsn = (string)getenv('PG_TEST_DSN');
    $countingPdo = new RrpCountingPdo($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    $countingPdo->exec('SET search_path TO ' . RRP06_SCHEMA . ', public');

    $countingReadModel = new PdoRestockRouteReadModel($countingPdo);
    $countingPlanner = new RestockRoutePlannerService($countingReadModel);

    $countingPdo->statements = 0;
    $snapBefore = $countingReadModel->loadParkSnapshot($today);
    $snapBeforeQueries = $countingPdo->statements;

    $countingPdo->statements = 0;
    $routeBefore = $countingPlanner->buildRestockerRoute($ownerId, $today);
    $routeBeforeQueries = $countingPdo->statements;

    $countingPdo->statements = 0;
    $dayBefore = $countingPlanner->buildCoordinatorDay($today);
    $dayBeforeQueries = $countingPdo->statements;

    $assert(
        '7.1 Con 4 máquinas, loadParkSnapshot y la composición diaria ejecutan exactamente 3 sentencias SQL',
        count($snapBefore['machines']) === 4
            && $snapBeforeQueries === 3
            && $routeBeforeQueries === 3
            && $dayBeforeQueries === 3,
        sprintf(
            'machines=%d · snap=%d · route=%d · day=%d',
            count($snapBefore['machines']),
            $snapBeforeQueries,
            $routeBeforeQueries,
            $dayBeforeQueries
        )
    );

    // Ampliamos el parque de 4 a 12 máquinas (+8 máquinas en 2 sedes adicionales, agendas, producto frío y carriles).
    $location2Id = (int)$scalar(
        $pdo,
        "INSERT INTO locations (site_code, site_code_key, name, address, zone_id, latitude, longitude, sla_tier)
         VALUES ('RRP-06-L2', 'rrp-06-l2', 'Sede Planificador L2', 'Av. Paral·lel 120', :zone, 41.375200, 2.164100, 'PRIORITY_CORPORATE')
         RETURNING id",
        [':zone' => $zoneId]
    );
    $location3Id = (int)$scalar(
        $pdo,
        "INSERT INTO locations (site_code, site_code_key, name, address, zone_id, latitude, longitude, sla_tier)
         VALUES ('RRP-06-L3', 'rrp-06-l3', 'Sede Planificador L3', 'Carrer Aragó 300', :zone, 41.392400, 2.168200, 'STANDARD')
         RETURNING id",
        [':zone' => $zoneId]
    );

    $coldProductId = (int)$scalar(
        $pdo,
        "INSERT INTO vending_products
             (sku, name, category, unit_type, requires_cold_chain, max_shelf_life_days, unit_cost_eur, sale_price_eur)
         VALUES ('RRP06-COLD-01', 'Bocadillo Frío RRP06', 'PERISHABLE_FOOD', 'UNIT', TRUE, 14, 1.10, 3.20)
         RETURNING id"
    );

    $extraMachineStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location, :code, 'Modelo RRP N+1', 'PERISHABLE_FOOD', 'Planta 1')
         RETURNING id"
    );
    $extraScheduleStmt = $pdo->prepare(
        "INSERT INTO machine_restock_schedules
             (machine_id, cadence_type, preferred_time_window, include_weekends, is_active, created_by, updated_by)
         VALUES (:machine_id, 'DAILY', 'MORNING', TRUE, TRUE, :actor, :actor)"
    );
    $extraSlotStmt = $pdo->prepare(
        'INSERT INTO machine_planogram_slots
             (machine_id, slot_code, product_id, max_capacity, current_estimated_qty, min_threshold, current_expiration_date, is_active)
         VALUES (:machine_id, :slot_code, :product_id, 10, :qty, 2, :expiration, TRUE)'
    );

    for ($idx = 5; $idx <= 12; $idx++) {
        $targetLocation = $idx <= 8 ? $location2Id : $location3Id;
        $extraMachineStmt->execute([
            ':location' => $targetLocation,
            ':code' => sprintf('VEND-RRP-%03d', 700 + $idx),
        ]);
        $extraMachineId = (int)$extraMachineStmt->fetchColumn();
        $extraScheduleStmt->execute([
            ':machine_id' => $extraMachineId,
            ':actor' => $coordinatorId,
        ]);
        $extraSlotStmt->execute([
            ':machine_id' => $extraMachineId,
            ':slot_code' => 'C' . $idx,
            ':product_id' => $coldProductId,
            ':qty' => $idx % 2 === 0 ? 0 : 4,
            ':expiration' => $idx <= 6 ? $today : $tomorrow,
        ]);
    }

    $countingPdo->statements = 0;
    $snapAfter = $countingReadModel->loadParkSnapshot($today);
    $snapAfterQueries = $countingPdo->statements;

    $countingPdo->statements = 0;
    $routeAfter = $countingPlanner->buildRestockerRoute($ownerId, $today);
    $routeAfterQueries = $countingPdo->statements;

    $countingPdo->statements = 0;
    $dayAfter = $countingPlanner->buildCoordinatorDay($today);
    $dayAfterQueries = $countingPdo->statements;

    $assert(
        '7.2 Al triplicar el parque (4 → 12 máquinas en 3 sedes), las sentencias SQL siguen siendo constantes (3 = O(1), cero N+1)',
        count($snapAfter['machines']) === 12
            && count($routeAfter['route_plan']['stops']) >= 2
            && $snapAfterQueries === 3
            && $routeAfterQueries === 3
            && $dayAfterQueries === 3
            && $snapAfterQueries === $snapBeforeQueries
            && $routeAfterQueries === $routeBeforeQueries
            && $dayAfterQueries === $dayBeforeQueries,
        sprintf(
            'machines=%d · stops=%d · snap=%d · route=%d · day=%d',
            count($snapAfter['machines']),
            count($routeAfter['route_plan']['stops']),
            $snapAfterQueries,
            $routeAfterQueries,
            $dayAfterQueries
        )
    );
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, "\n[FAIL] Excepción inesperada: " . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getTraceAsString() . "\n");
} finally {
    $countingPdo = null;
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, RRP06_SCHEMA);
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "\n[FAIL] No se pudo limpiar el esquema aislado: " . $exception->getMessage() . "\n");
    }
}

echo "\n======================================================================\n";
echo " Aserciones: {$assertions} · Fallos: {$failures}\n";
echo $failures === 0 ? " RESULTADO: 100% EN VERDE\n" : " RESULTADO: EN ROJO\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
