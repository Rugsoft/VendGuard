<?php

declare(strict_types=1);

/**
 * TicketCodeRaceRetryTest (G7 · EARS 2.4)
 *
 * Certifica, con **carreras reales entre dos procesos y dos conexiones PDO**, que las
 * tres puertas de alta de la Fase 1 registran el expediente con un código de ticket
 * nuevo cuando el índice único `uq_incidents_ticket_code` rechaza el primero, en lugar
 * de devolver un 500:
 *
 *   1. **Reporte por QR** (`QrReportService`).
 *   2. **Portal de sede** (`LocationPortalController::createIncident`).
 *   3. **Puente de no conformidad preventiva** (`PreventiveCoexistenceBridgeService`).
 *
 * Cómo se fuerza la colisión sin tocar el azar del dominio: la política de alta recibe
 * su fuente de códigos por constructor, y la suite le entrega una secuencia cuyo primer
 * elemento es exactamente el código que un proceso titular ya insertó sin confirmar.
 * Esa es la misma carrera que produce el azar en producción —dos altas simultáneas
 * cayendo en el mismo código— pero reproducible.
 *
 * Cada caso mide además la espera: la escritura perdedora queda bloqueada en el índice
 * hasta que el titular confirma, y sólo entonces recibe el `23505`. Sin esa espera, el
 * caso no habría ejercitado el índice.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\IncidentTicketAllocator;
use VendGuard\Application\Service\PreventiveCoexistenceBridgeService;
use VendGuard\Application\Service\QrReportService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveOrderRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;
use VendGuard\Presentation\Controller\LocationPortalController;
use VendGuard\Presentation\Http\Request;

echo "======================================================================\n";
echo " VendGuard · G7 · Carrera real del código de ticket y reintento (EARS 2.4)\n";
echo " Dos procesos · dos conexiones PDO · QR, portal y puente preventivo\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::registerShutdownRestitution();
(new SeedRunner($pdo))->seedAll();

$failures = 0;
$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    $failures++;
    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
};

$incidentRepo = new PdoIncidentRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$orderRepo = new PdoPreventiveOrderRepository($pdo);
$settingsRepo = new PdoPreventiveSettingsRepository($pdo);
$holderScript = __DIR__ . '/../Support/UniqueRaceHolder.php';

/** Ficha mínima de una máquina por código. */
$machineByCode = static function (string $code) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT id, location_id, code FROM machines WHERE code = :code');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException("La semilla no declara la máquina {$code}.");
    }

    return ['id' => (int)$row['id'], 'location_id' => (int)$row['location_id'], 'code' => (string)$row['code']];
};

/** ¿La máquina tiene un expediente vigente que impida un alta nueva? */
$hasActiveIncident = static function (int $machineId) use ($pdo): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM incidents WHERE machine_id = :id AND status NOT IN ('CLOSED', 'CANCELLED')"
    );
    $stmt->execute([':id' => $machineId]);

    return (int)$stmt->fetchColumn() > 0;
};

/** Secuencia determinista de códigos candidatos. */
$sequence = static function (array $codes): callable {
    $remaining = array_values($codes);

    return static function () use (&$remaining): string {
        return $remaining === [] ? 'INC-2026-AGOT' : (string)array_shift($remaining);
    };
};

/**
 * Lanza la carrera: el proceso titular inserta la sentencia y la retiene sin confirmar
 * mientras la puerta de alta real se ejecuta contra esa fila invisible.
 *
 * @return array{result: mixed, error: ?Throwable, elapsed: float, exit: int, log: string, ready: bool}
 */
$race = function (string $insertSql, callable $operation) use ($pdo, $holderScript): array {
    $sqlFile = tempnam(sys_get_temp_dir(), 'vg_g7_sql_');
    $readyFile = tempnam(sys_get_temp_dir(), 'vg_g7_ready_');
    $logFile = tempnam(sys_get_temp_dir(), 'vg_g7_log_');
    @unlink($readyFile);
    file_put_contents($sqlFile, $insertSql);

    $process = proc_open(
        [PHP_BINARY, $holderScript, $sqlFile, $readyFile, '1200'],
        [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']],
        $pipes
    );
    if (!is_resource($process)) {
        @unlink($sqlFile);
        @unlink($readyFile);
        @unlink($logFile);
        throw new RuntimeException('No se pudo arrancar el proceso titular de la carrera.');
    }
    fclose($pipes[0]);

    $deadline = microtime(true) + 10.0;
    while (!is_file($readyFile) && microtime(true) < $deadline) {
        usleep(50_000);
    }
    $ready = is_file($readyFile);

    $startedAt = microtime(true);
    $result = null;
    $error = null;
    if ($ready) {
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $error = $exception;
        }
    }
    $elapsed = microtime(true) - $startedAt;

    $exit = proc_close($process);
    $log = trim((string)file_get_contents($logFile));

    @unlink($sqlFile);
    @unlink($readyFile);
    @unlink($logFile);

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    return [
        'result' => $result,
        'error' => $error,
        'elapsed' => $elapsed,
        'exit' => $exit,
        'log' => $log,
        'ready' => $ready,
    ];
};

/** Sentencia del titular: un expediente vivo con el código que la puerta intentará usar. */
$holdIncidentSql = static function (string $ticketCode, array $holderMachine): string {
    return sprintf(
        "INSERT INTO incidents (ticket_code, machine_id, location_id, category, description, urgency, status) "
        . "VALUES ('%s', %d, %d, 'OTHER', 'Titular de la carrera G7 sobre el código de ticket.', 'MEDIUM', 'REGISTERED')",
        $ticketCode,
        $holderMachine['id'],
        $holderMachine['location_id']
    );
};

$assertRaceOutcome = function (string $label, array $raceResult) use ($assert): void {
    $assert(
        "{$label} · el titular retuvo la fila sin confirmar y confirmó en verde",
        $raceResult['ready'] === true && $raceResult['exit'] === 0 && str_contains($raceResult['log'], 'COMMITTED'),
        'ready=' . var_export($raceResult['ready'], true) . ' · exit=' . $raceResult['exit'] . ' · log=' . $raceResult['log']
    );
    $assert(
        "{$label} · el alta perdedora esperó al índice único antes de recibir el 23505",
        $raceResult['elapsed'] >= 0.30,
        sprintf('espera medida: %.3f s', $raceResult['elapsed'])
    );
    $assert(
        "{$label} · la colisión no escapó como excepción: el reintento la absorbió",
        $raceResult['error'] === null,
        $raceResult['error'] !== null
            ? get_class($raceResult['error']) . ': ' . $raceResult['error']->getMessage()
            : ''
    );
};

/** Comprueba que el expediente vive con el código reasignado, y sólo con él. */
$assertPersistedWith = function (string $label, array $machine, string $expectedTicket, string $expectedStatus) use ($pdo, $assert): void {
    $stmt = $pdo->prepare(
        'SELECT ticket_code, status FROM incidents WHERE machine_id = :id AND deleted_at IS NULL ORDER BY id'
    );
    $stmt->execute([':id' => $machine['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $assert(
        "{$label} · la máquina queda con un único expediente, nacido con el código nuevo",
        count($rows) === 1 && $rows[0]['ticket_code'] === $expectedTicket && $rows[0]['status'] === $expectedStatus,
        json_encode($rows)
    );
};

// ---------------------------------------------------------------------
// CASO 1 · Reporte por QR
// ---------------------------------------------------------------------
echo "\n--- Caso 1: alta por QR con el primer código ocupado ---\n";

$qrMachine = $machineByCode('VEND-0101');
$qrHolderMachine = $machineByCode('VEND-0201');
$assert(
    '1.0 Máquinas libres para el caso del QR',
    !$hasActiveIncident($qrMachine['id']) && !$hasActiveIncident($qrHolderMachine['id'])
);

$qrHeldCode = 'INC-2026-G7Q1';
$qrFreshCode = 'INC-2026-G7Q2';
$qrService = new QrReportService(
    ticketAllocator: new IncidentTicketAllocator($sequence([$qrHeldCode, $qrFreshCode]))
);
$qrRace = $race(
    $holdIncidentSql($qrHeldCode, $qrHolderMachine),
    static function () use ($qrService, $qrMachine): array {
        return $qrService->report([
            'machine_code' => $qrMachine['code'],
            'category' => 'OTHER',
            'description' => 'Aviso de carrera G7 lanzado desde el código QR.',
            'reporter_name' => 'Fixture G7 · QR',
        ]);
    }
);
$assertRaceOutcome('1.1 QR', $qrRace);
$qrTicket = is_array($qrRace['result']) ? ($qrRace['result']['ticket_code'] ?? null) : null;
$assert(
    '1.2 QR · la respuesta publica el código reasignado y un alta no fusionada',
    $qrTicket === $qrFreshCode && ($qrRace['result']['merged'] ?? null) === false,
    'ticket: ' . var_export($qrTicket, true)
);
$assertPersistedWith('1.3 QR', $qrMachine, $qrFreshCode, 'REGISTERED');

// ---------------------------------------------------------------------
// CASO 2 · Portal de sede
// ---------------------------------------------------------------------
echo "\n--- Caso 2: alta desde el portal de sede con el primer código ocupado ---\n";

$portalMachine = $machineByCode('VEND-0301');
$portalHolderMachine = $machineByCode('VEND-0302');
$assert(
    '2.0 Máquinas libres para el caso del portal',
    !$hasActiveIncident($portalMachine['id']) && !$hasActiveIncident($portalHolderMachine['id'])
);

$portalLocation = $locationRepo->findById($portalMachine['location_id']);
$assert('2.0b La sede de la máquina del portal existe', $portalLocation !== null);

$portalHeldCode = 'INC-2026-G7P1';
$portalFreshCode = 'INC-2026-G7P2';
$portalController = new LocationPortalController(
    ticketAllocator: new IncidentTicketAllocator($sequence([$portalHeldCode, $portalFreshCode]))
);
$portalRace = $race(
    $holdIncidentSql($portalHeldCode, $portalHolderMachine),
    static function () use ($portalController, $portalMachine, $portalLocation) {
        $request = new Request('POST', '/api/incidents', [], [
            'machine_id' => $portalMachine['id'],
            'category' => 'OTHER',
            'description' => 'Aviso de carrera G7 lanzado desde el portal de sede.',
            'reporter_name' => 'Fixture G7 · Sede',
        ]);
        $request->setAttribute('authenticated_location', $portalLocation);

        return $portalController->createIncident($request);
    }
);
$assertRaceOutcome('2.1 Portal', $portalRace);
$portalResponse = $portalRace['result'] instanceof \VendGuard\Presentation\Http\Response ? $portalRace['result'] : null;
$portalBody = $portalResponse !== null ? $portalResponse->getDecodedBody() : null;
$portalTicket = $portalBody['data']['ticket_code'] ?? ($portalBody['ticket_code'] ?? null);
$assert(
    '2.2 Portal · la sede recibe 201 con el código reasignado',
    $portalResponse !== null
        && $portalResponse->getStatusCode() === 201
        && $portalTicket === $portalFreshCode,
    'status: ' . var_export($portalResponse !== null ? $portalResponse->getStatusCode() : null, true)
        . ' · ticket: ' . var_export($portalTicket, true)
);
$assertPersistedWith('2.3 Portal', $portalMachine, $portalFreshCode, 'REGISTERED');

// ---------------------------------------------------------------------
// CASO 3 · Puente de no conformidad preventiva
// ---------------------------------------------------------------------
echo "\n--- Caso 3: correctivo automático del preventivo con el primer código ocupado ---\n";

$bridgeMachine = $machineByCode('VEND-0102');
$bridgeHolderMachine = $machineByCode('VEND-0401');
$assert(
    '3.0 Máquinas libres para el caso del puente preventivo',
    !$hasActiveIncident($bridgeMachine['id']) && !$hasActiveIncident($bridgeHolderMachine['id'])
);

$orderStmt = $pdo->prepare(
    'SELECT id, assigned_technician_id FROM preventive_orders WHERE machine_id = :id AND linked_incident_id IS NULL ORDER BY id LIMIT 1'
);
$orderStmt->execute([':id' => $bridgeMachine['id']]);
$orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
$assert('3.0b La máquina del puente tiene una orden preventiva vinculable', $orderRow !== false);

$bridgeOrderId = (int)($orderRow['id'] ?? 0);
$bridgeTechnicianId = (int)($orderRow['assigned_technician_id'] ?? 2);
$bridgeHeldCode = 'INC-2026-G7B1';
$bridgeFreshCode = 'INC-2026-G7B2';
$bridge = new PreventiveCoexistenceBridgeService(
    $incidentRepo,
    $orderRepo,
    $settingsRepo,
    ticketAllocator: new IncidentTicketAllocator($sequence([$bridgeHeldCode, $bridgeFreshCode]))
);
$bridgeRace = $race(
    $holdIncidentSql($bridgeHeldCode, $bridgeHolderMachine),
    static function () use ($bridge, $bridgeOrderId, $bridgeTechnicianId): array {
        return $bridge->handleNonConformity(
            $bridgeOrderId,
            $bridgeTechnicianId,
            null,
            [],
            'No conformidad de la carrera G7',
            ['id' => $bridgeTechnicianId, 'role' => 'TECHNICIAN', 'name' => 'Fixture G7 · Técnico']
        );
    }
);
$assertRaceOutcome('3.1 Puente', $bridgeRace);
$bridgeResult = is_array($bridgeRace['result']) ? $bridgeRace['result'] : [];
$assert(
    '3.2 Puente · el correctivo se abre con el código reasignado',
    ($bridgeResult['mode'] ?? null) === 'CREATED_NEW_INCIDENT'
        && ($bridgeResult['ticket_code'] ?? null) === $bridgeFreshCode,
    'mode: ' . var_export($bridgeResult['mode'] ?? null, true) . ' · ticket: ' . var_export($bridgeResult['ticket_code'] ?? null, true)
);
$assertPersistedWith('3.3 Puente', $bridgeMachine, $bridgeFreshCode, 'IN_PROGRESS');

// ---------------------------------------------------------------------
// CASO 4 · Cota agotada: 409 explícito, nunca un 500
// ---------------------------------------------------------------------
// El titular retiene el código; la fuente de códigos insiste con el mismo. Los tres
// intentos chocan y la puerta debe rendirse con `TicketCodeCollisionException` (409),
// dejando la máquina sin un expediente a medias.
echo "\n--- Caso 4: tres colisiones seguidas agotan el reintento ---\n";

$exhaustedMachine = $machineByCode('VEND-0501');
$exhaustedHolderMachine = $machineByCode('VEND-0502');
$assert(
    '4.0 Máquinas libres para el caso de cota agotada',
    !$hasActiveIncident($exhaustedMachine['id']) && !$hasActiveIncident($exhaustedHolderMachine['id'])
);

$exhaustedHeldCode = 'INC-2026-G7X1';
$exhaustedService = new QrReportService(
    ticketAllocator: new IncidentTicketAllocator(
        $sequence([$exhaustedHeldCode, $exhaustedHeldCode, $exhaustedHeldCode])
    )
);
$exhaustedRace = $race(
    $holdIncidentSql($exhaustedHeldCode, $exhaustedHolderMachine),
    static function () use ($exhaustedService, $exhaustedMachine): array {
        return $exhaustedService->report([
            'machine_code' => $exhaustedMachine['code'],
            'category' => 'OTHER',
            'description' => 'Aviso de carrera G7 con la cota de reintentos agotada.',
            'reporter_name' => 'Fixture G7 · Cota',
        ]);
    }
);
$assert(
    '4.1 Cota · el titular retuvo la fila sin confirmar y confirmó en verde',
    $exhaustedRace['ready'] === true && $exhaustedRace['exit'] === 0 && str_contains($exhaustedRace['log'], 'COMMITTED'),
    $exhaustedRace['log']
);
$exhaustedError = $exhaustedRace['error'];
$assert(
    '4.2 Cota · agotados los tres intentos la respuesta es 409 TICKET_CODE_COLLISION, no un 500',
    $exhaustedError instanceof \VendGuard\Core\Domain\Exception\TicketCodeCollisionException
        && $exhaustedError->getErrorCode() === 'TICKET_CODE_COLLISION'
        && $exhaustedError->getHttpStatusCode() === 409
        && $exhaustedError->getAttempts() === IncidentTicketAllocator::MAX_ATTEMPTS
        && !$exhaustedError instanceof \PDOException,
    $exhaustedError !== null ? get_class($exhaustedError) . ': ' . $exhaustedError->getMessage() : 'no se lanzó excepción'
);
$stmt = $pdo->prepare('SELECT COUNT(*) FROM incidents WHERE machine_id = :id AND deleted_at IS NULL');
$stmt->execute([':id' => $exhaustedMachine['id']]);
$assert(
    '4.3 Cota · el alta fallida no dejó ningún expediente a medias en la máquina',
    (int)$stmt->fetchColumn() === 0
);

// ---------------------------------------------------------------------
// CIERRE · la colisión fue real y no queda residuo
// ---------------------------------------------------------------------
echo "\n--- Cierre: la colisión fue real ---\n";

foreach ([$qrHeldCode, $portalHeldCode, $bridgeHeldCode, $exhaustedHeldCode] as $heldCode) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM incidents WHERE ticket_code = :code');
    $stmt->execute([':code' => $heldCode]);
    $assert("El código titular {$heldCode} quedó confirmado en la base", (int)$stmt->fetchColumn() === 1);
}

// La orden preventiva apunta al expediente que acaba de nacer: se libera el vínculo
// antes de drenar las tablas transitorias para no chocar con la clave foránea.
$pdo->prepare('UPDATE preventive_orders SET linked_incident_id = NULL WHERE id = :id')
    ->execute([':id' => $bridgeOrderId]);
TestDataCleaner::purge($pdo);

echo "\n======================================================================\n";
echo " Total Aserciones fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. G7 CERTIFICADA.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
