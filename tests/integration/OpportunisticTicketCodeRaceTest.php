<?php

declare(strict_types=1);

/**
 * OpportunisticTicketCodeRaceTest (Módulo 16 · EARS 2.4)
 *
 * Extiende la política de reintento del código de ticket a las **dos superficies
 * oportunistas que crean un expediente**, con carreras reales entre dos procesos y
 * dos conexiones PDO independientes:
 *
 *   1. **Alta Oportunista In Situ** (`createOpportunisticIncident`, Flujo A).
 *   2. **Sustitución por Avería Crónica** (`assumeOpportunisticIncident`, 3.ª
 *      reincidencia, Flujo B).
 *
 * Cómo se fuerza la colisión sin tocar el azar del dominio: la política de alta
 * (`IncidentTicketAllocator`) recibe su fuente de códigos por constructor y esta
 * suite le entrega una secuencia cuyo primer elemento es exactamente el código que
 * un proceso titular ya insertó sin confirmar. Es la misma carrera que produce el
 * azar en producción —dos altas simultáneas cayendo en el mismo código—, pero
 * reproducible. La espera medida demuestra que la escritura perdedora quedó
 * bloqueada en el índice único hasta que el titular confirmó; sin ella el caso no
 * habría ejercitado `uq_incidents_ticket_code`.
 *
 * Qué certifica cada caso:
 *
 *   - El reintento reabre la **unidad de trabajo completa**: habilitación de sede,
 *     coexistencia, escritura, historial y auditoría. Por eso se comprueba que el
 *     intento perdedor no dejó ni historial duplicado ni eventos de auditoría
 *     duplicados, y que el expediente nace con el código nuevo.
 *   - La sustitución crónica sigue siendo **atómica**: el predecesor se cierra con
 *     la marca `[AVERÍA CRÓNICA]`, el sucesor hereda el vínculo y no queda hueco
 *     entre ambos.
 *   - Agotada la cota, la respuesta es `409 TICKET_CODE_COLLISION` y el predecesor
 *     vuelve a `RESOLVED` intacto: la transacción del intento perdedor se deshace
 *     entera en lugar de dejar la máquina sin expediente vivo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IncidentTicketAllocator;
use VendGuard\Application\Service\OpportunisticIncidentService;
use VendGuard\Core\Domain\Exception\TicketCodeCollisionException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Controller\TechnicianOpportunisticController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

echo "======================================================================\n";
echo " VendGuard · Módulo 16 · EARS 2.4 · Carrera real del código de ticket\n";
echo " Alta oportunista y sustitución por Avería Crónica · dos procesos\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::registerShutdownRestitution();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$assertions = 0;
$failures = 0;
$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
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
$machineRepo = new PdoMachineRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$auditRepo = new PdoAuditLogRepository($pdo);
$holderScript = __DIR__ . '/../Support/UniqueRaceHolder.php';

$jordi = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$assert('0.1 Técnico semilla disponible', $jordi instanceof User);
if (!$jordi instanceof User) {
    echo "\nNo se puede certificar sin el técnico semilla.\n";
    exit(1);
}

/** Ficha mínima de una máquina por código. */
$machineByCode = static function (string $code) use ($machineRepo): array {
    $machine = $machineRepo->findByCode($code);
    if ($machine === null) {
        throw new RuntimeException("La semilla no declara la máquina {$code}.");
    }

    return ['id' => (int)$machine->getId(), 'location_id' => (int)$machine->getLocationId(), 'code' => $machine->getCode()];
};

$machine = $machineByCode('VEND-0302');          // máquina de trabajo (sede 3)
$anchorMachine = $machineByCode('VEND-0301');    // parada ancla que habilita la sede 3
// Una máquina titular distinta por caso: el índice `uq_machine_active_ticket` no
// permite dos expedientes vivos en la misma máquina, y el titular de cada carrera
// deja la suya ocupada al confirmar.
$holderMachines = [
    'create' => $machineByCode('VEND-0201'),
    'chronic' => $machineByCode('VEND-0401'),
    'exhausted' => $machineByCode('VEND-0601'),
    'http' => $machineByCode('VEND-0101'),
];

$assert(
    '0.2 Las máquinas titulares viven fuera de la sede de trabajo y son distintas entre sí',
    $machine['location_id'] === $anchorMachine['location_id']
        && count(array_unique(array_map(
            static fn (array $holder): int => $holder['location_id'],
            $holderMachines
        ))) === count($holderMachines)
        && !in_array($machine['location_id'], array_column($holderMachines, 'location_id'), true)
);

/** Servicio con la fuente de códigos inyectada. @param list<string> $codes */
$serviceWith = static function (array $codes) use ($incidentRepo, $machineRepo, $locationRepo, $auditRepo, $pdo): OpportunisticIncidentService {
    $remaining = array_values($codes);
    $generator = static function () use (&$remaining): string {
        return $remaining === [] ? 'INC-2026-AGOT' : (string)array_shift($remaining);
    };

    return new OpportunisticIncidentService(
        opportunistic: $incidentRepo,
        machines: $machineRepo,
        locations: $locationRepo,
        incidents: $incidentRepo,
        transactions: new PdoTransactionManager($pdo),
        auditLogger: new AuditLogger($auditRepo),
        tickets: new IncidentTicketAllocator($generator)
    );
};

$stamp = static fn (int $offsetSeconds = 0): string => EngineClock::shift($offsetSeconds);

/**
 * Lanza la carrera: el proceso titular inserta la sentencia y la retiene sin confirmar
 * mientras la operación real de negocio se ejecuta contra esa fila invisible.
 *
 * @return array{result: mixed, error: ?Throwable, elapsed: float, exit: int, log: string, ready: bool}
 */
$race = function (string $insertSql, callable $operation) use ($pdo, $holderScript): array {
    $sqlFile = tempnam(sys_get_temp_dir(), 'vg_g9_sql_');
    $readyFile = tempnam(sys_get_temp_dir(), 'vg_g9_ready_');
    $logFile = tempnam(sys_get_temp_dir(), 'vg_g9_log_');
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

/** Sentencia del titular: un expediente vivo con el código que la operación intentará usar. */
$holdIncidentSql = static function (string $ticketCode, array $holderMachine): string {
    return sprintf(
        "INSERT INTO incidents (ticket_code, machine_id, location_id, category, description, urgency, status) "
        . "VALUES ('%s', %d, %d, 'OTHER', 'Titular de la carrera G9 sobre el código de ticket.', 'MEDIUM', 'REGISTERED')",
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
        "{$label} · la escritura perdedora esperó al índice único antes de recibir el 23505",
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

/** Filas de una máquina, ordenadas por id. @return list<array<string, mixed>> */
$machineRows = static function (int $machineId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM incidents WHERE machine_id = :id ORDER BY id');
    $stmt->execute([':id' => $machineId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

/** Eventos de auditoría de un expediente para una acción. */
$auditCount = static function (int $incidentId, string $action) use ($pdo): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_log WHERE entity_type = 'TICKET' AND entity_id = :id AND action = :action"
    );
    $stmt->execute([':id' => $incidentId, ':action' => $action]);

    return (int)$stmt->fetchColumn();
};

/** Eventos de historial de un expediente (total y aperturas). */
$historyCounts = static function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM incident_history WHERE incident_id = :id');
    $stmt->execute([':id' => $incidentId]);
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM incident_history WHERE incident_id = :id AND from_status IS NULL');
    $stmt->execute([':id' => $incidentId]);

    return ['total' => $total, 'opening' => (int)$stmt->fetchColumn()];
};

// ---------------------------------------------------------------------
// ANCLA DE HABILITACIÓN: una parada ASSIGNED del técnico en la sede 3.
// Sin ella el módulo responde 403 y la carrera no llegaría a la escritura.
// ---------------------------------------------------------------------
$anchor = $incidentRepo->create(
    new Incident(
        id: null,
        ticketCode: 'INC-2026-G9AN',
        machineId: $anchorMachine['id'],
        locationId: $anchorMachine['location_id'],
        category: IncidentCategory::PRODUCT_JAM,
        description: 'Parada ancla de la certificación G9 sobre el código de ticket.',
        urgency: UrgencyLevel::MEDIUM,
        status: IncidentStatus::ASSIGNED,
        assignedTechnicianId: $jordi->getId(),
        reporterName: 'Fixture G9 · Ancla',
        assignedAt: $stamp(),
        cycleStartedAt: $stamp()
    ),
    $jordi->getId(),
    'Parada ancla de la certificación G9'
);
$assert('0.3 Parada ancla ASSIGNED creada para habilitar la sede', $anchor->getId() !== null);

// ---------------------------------------------------------------------
// CASO 1 · Alta Oportunista In Situ con el primer código ocupado
// ---------------------------------------------------------------------
echo "\n--- Caso 1: alta oportunista con el primer código ocupado ---\n";

TestDataCleaner::purgeIncidentsByMachine($pdo, $machine['id']);

$createHeldCode = 'INC-2026-G9A1';
$createFreshCode = 'INC-2026-G9A2';
$createService = $serviceWith([$createHeldCode, $createFreshCode]);
$createRace = $race(
    $holdIncidentSql($createHeldCode, $holderMachines['create']),
    static function () use ($createService, $jordi, $machine): array {
        return $createService->createOpportunisticIncident($jordi, [
            'machine_id' => $machine['id'],
            'category' => 'PRODUCT_JAM',
            'origin_reason' => 'TECHNICIAN_DETECTION',
            'description' => 'Atasco en la espiral B4 detectado durante la visita de la carrera G9.',
        ]);
    }
);
$assertRaceOutcome('1.1 Alta oportunista', $createRace);

$created = is_array($createRace['result']) ? $createRace['result'] : [];
$assert(
    '1.2 Alta oportunista · la respuesta publica el código reasignado y el parte en curso',
    ($created['ticket_code'] ?? null) === $createFreshCode
        && ($created['status'] ?? null) === 'IN_PROGRESS'
        && ($created['opportunistic_mode'] ?? null) === 'NEW'
        && ($created['opportunistic_origin_reason'] ?? null) === 'TECHNICIAN_DETECTION',
    'ticket: ' . var_export($created['ticket_code'] ?? null, true)
        . ' · status: ' . var_export($created['status'] ?? null, true)
);

$createRows = $machineRows($machine['id']);
$assert(
    '1.3 Alta oportunista · la máquina queda con un único expediente, nacido con el código nuevo',
    count($createRows) === 1
        && $createRows[0]['ticket_code'] === $createFreshCode
        && $createRows[0]['status'] === 'IN_PROGRESS',
    json_encode($createRows)
);

$createId = (int)($createRows[0]['id'] ?? 0);
$createHistory = $historyCounts($createId);
$assert(
    '1.4 Alta oportunista · el intento perdedor no duplicó el historial de apertura',
    $createHistory['opening'] === 1 && $createHistory['total'] === 1,
    json_encode($createHistory)
);
$assert(
    '1.5 Alta oportunista · el intento perdedor no duplicó la auditoría del alta',
    $auditCount($createId, 'INCIDENT_CREATED_OPPORTUNISTIC') === 1
);

$heldOnMachine = $pdo->prepare('SELECT COUNT(*) FROM incidents WHERE ticket_code = :code AND machine_id = :machine');
$heldOnMachine->execute([':code' => $createHeldCode, ':machine' => $machine['id']]);
$assert(
    '1.6 Alta oportunista · el código titular nunca llegó a escribirse sobre la máquina',
    (int)$heldOnMachine->fetchColumn() === 0
);

// ---------------------------------------------------------------------
// CASO 2 · Sustitución por Avería Crónica con el primer código ocupado
// ---------------------------------------------------------------------
echo "\n--- Caso 2: 3.ª reincidencia (Avería Crónica) con el primer código ocupado ---\n";

TestDataCleaner::purgeIncidentsByMachine($pdo, $machine['id']);

$predecessor = $incidentRepo->create(
    new Incident(
        id: null,
        ticketCode: 'INC-2026-G9D0',
        machineId: $machine['id'],
        locationId: $machine['location_id'],
        category: IncidentCategory::PRODUCT_JAM,
        description: 'Atasco recurrente del monedero durante la certificación G9.',
        urgency: UrgencyLevel::MEDIUM,
        status: IncidentStatus::RESOLVED,
        assignedTechnicianId: $jordi->getId(),
        reporterName: 'Fixture G9 · Crónica',
        assignedAt: $stamp(-1800),
        cycleStartedAt: $stamp(-1800),
        resolvedAt: $stamp(-900),
        resolutionDiagnosis: 'Diagnóstico de la segunda reparación del monedero.',
        resolutionAction: 'Acción correctiva aplicada en el segundo servicio técnico.'
    ),
    $jordi->getId(),
    'Predecesor con dos reaperturas acumuladas'
);
$predecessorId = (int)$predecessor->getId();
foreach ([
    ['RESOLVED', 'REOPENED', 'Primera reapertura simulada.'],
    ['REOPENED', 'IN_PROGRESS', 'Reanudación simulada.'],
    ['IN_PROGRESS', 'RESOLVED', 'Nueva resolución simulada.'],
    ['RESOLVED', 'REOPENED', 'Segunda reapertura simulada.'],
    ['REOPENED', 'IN_PROGRESS', 'Reanudación simulada.'],
    ['IN_PROGRESS', 'RESOLVED', 'Resolución final simulada.'],
] as [$from, $to, $note]) {
    $incidentRepo->insertHistory($predecessorId, $jordi->getId(), $from, $to, $note);
}
$predecessorHistoryBefore = $historyCounts($predecessorId);
$assert(
    '2.0 El predecesor acumula dos reaperturas antes de la carrera',
    $predecessorHistoryBefore['opening'] === 1 && $predecessorHistoryBefore['total'] === 7,
    json_encode($predecessorHistoryBefore)
);

$chronicHeldCode = 'INC-2026-G9C1';
$chronicFreshCode = 'INC-2026-G9C2';
$chronicService = $serviceWith([$chronicHeldCode, $chronicFreshCode]);
$chronicRace = $race(
    $holdIncidentSql($chronicHeldCode, $holderMachines['chronic']),
    static function () use ($chronicService, $jordi, $predecessorId): array {
        return $chronicService->assumeOpportunisticIncident(
            $jordi,
            $predecessorId,
            ['reopen_reason' => 'Tercera reincidencia del mismo monedero en menos de 48 horas.']
        );
    }
);
$assertRaceOutcome('2.1 Avería Crónica', $chronicRace);

$chronic = is_array($chronicRace['result']) ? $chronicRace['result'] : [];
$successorId = (int)($chronic['id'] ?? 0);
$assert(
    '2.2 Avería Crónica · el sucesor nace con el código reasignado, limpio y en curso',
    ($chronic['chronic_replacement_created'] ?? null) === true
        && ($chronic['ticket_code'] ?? null) === $chronicFreshCode
        && ($chronic['status'] ?? null) === 'IN_PROGRESS'
        && $successorId !== $predecessorId,
    'ticket: ' . var_export($chronic['ticket_code'] ?? null, true)
        . ' · id: ' . $successorId
);

$chronicRows = $machineRows($machine['id']);
$predecessorRow = [];
$successorRow = [];
foreach ($chronicRows as $row) {
    if ((int)$row['id'] === $predecessorId) {
        $predecessorRow = $row;
    }
    if ((int)$row['id'] === $successorId) {
        $successorRow = $row;
    }
}
$assert(
    '2.3 Avería Crónica · el predecesor queda CLOSED con la marca [AVERÍA CRÓNICA]',
    ($predecessorRow['status'] ?? null) === 'CLOSED'
        && str_contains((string)($predecessorRow['reopen_reason'] ?? ''), '[AVERÍA CRÓNICA]'),
    json_encode($predecessorRow)
);
$assert(
    '2.4 Avería Crónica · el sucesor declara al predecesor y la máquina no queda sin expediente vivo',
    (int)($successorRow['chronic_predecessor_incident_id'] ?? 0) === $predecessorId
        && count($chronicRows) === 2
        && ($successorRow['status'] ?? null) === 'IN_PROGRESS',
    json_encode($chronicRows)
);
$successorHistory = $historyCounts($successorId);
$assert(
    '2.5 Avería Crónica · el reintento no duplicó el historial del sucesor',
    $successorHistory['opening'] === 1 && $successorHistory['total'] === 1,
    json_encode($successorHistory)
);
$assert(
    '2.6 Avería Crónica · el reintento no duplicó el evento de auditoría del reemplazo',
    $auditCount($predecessorId, 'INCIDENT_CHRONIC_REPLACED') === 1
);

// ---------------------------------------------------------------------
// CASO 3 · Cota agotada: el predecesor vuelve a RESOLVED y hay 409
// ---------------------------------------------------------------------
echo "\n--- Caso 3: tres colisiones seguidas agotan el reintento de la sustitución ---\n";

TestDataCleaner::purgeIncidentsByMachine($pdo, $machine['id']);

$exhaustedPredecessor = $incidentRepo->create(
    new Incident(
        id: null,
        ticketCode: 'INC-2026-G9E0',
        machineId: $machine['id'],
        locationId: $machine['location_id'],
        category: IncidentCategory::PRODUCT_JAM,
        description: 'Atasco recurrente del monedero durante la certificación G9 (cota).',
        urgency: UrgencyLevel::MEDIUM,
        status: IncidentStatus::RESOLVED,
        assignedTechnicianId: $jordi->getId(),
        reporterName: 'Fixture G9 · Cota',
        assignedAt: $stamp(-1800),
        cycleStartedAt: $stamp(-1800),
        resolvedAt: $stamp(-900),
        resolutionDiagnosis: 'Diagnóstico de la segunda reparación del monedero.',
        resolutionAction: 'Acción correctiva aplicada en el segundo servicio técnico.'
    ),
    $jordi->getId(),
    'Predecesor de la cota agotada'
);
$exhaustedPredecessorId = (int)$exhaustedPredecessor->getId();
foreach ([
    ['RESOLVED', 'REOPENED', 'Primera reapertura simulada.'],
    ['REOPENED', 'IN_PROGRESS', 'Reanudación simulada.'],
    ['IN_PROGRESS', 'RESOLVED', 'Nueva resolución simulada.'],
    ['RESOLVED', 'REOPENED', 'Segunda reapertura simulada.'],
    ['REOPENED', 'IN_PROGRESS', 'Reanudación simulada.'],
    ['IN_PROGRESS', 'RESOLVED', 'Resolución final simulada.'],
] as [$from, $to, $note]) {
    $incidentRepo->insertHistory($exhaustedPredecessorId, $jordi->getId(), $from, $to, $note);
}

$exhaustedHeldCode = 'INC-2026-G9X1';
$exhaustedService = $serviceWith([$exhaustedHeldCode, $exhaustedHeldCode, $exhaustedHeldCode]);
$exhaustedRace = $race(
    $holdIncidentSql($exhaustedHeldCode, $holderMachines['exhausted']),
    static function () use ($exhaustedService, $jordi, $exhaustedPredecessorId): array {
        return $exhaustedService->assumeOpportunisticIncident(
            $jordi,
            $exhaustedPredecessorId,
            ['reopen_reason' => 'Tercera reincidencia del mismo monedero en menos de 48 horas.']
        );
    }
);
$assert(
    '3.1 Cota · el titular retuvo la fila sin confirmar y confirmó en verde',
    $exhaustedRace['ready'] === true && $exhaustedRace['exit'] === 0 && str_contains($exhaustedRace['log'], 'COMMITTED'),
    $exhaustedRace['log']
);
$exhaustedError = $exhaustedRace['error'];
$assert(
    '3.2 Cota · agotados los tres intentos la respuesta es 409 TICKET_CODE_COLLISION, no un 500',
    $exhaustedError instanceof TicketCodeCollisionException
        && $exhaustedError->getErrorCode() === 'TICKET_CODE_COLLISION'
        && $exhaustedError->getHttpStatusCode() === 409
        && $exhaustedError->getAttempts() === IncidentTicketAllocator::MAX_ATTEMPTS
        && !$exhaustedError instanceof PDOException,
    $exhaustedError !== null ? get_class($exhaustedError) . ': ' . $exhaustedError->getMessage() : 'no se lanzó excepción'
);

$exhaustedRows = $machineRows($machine['id']);
$exhaustedReloaded = $exhaustedRows[0] ?? [];
$assert(
    '3.3 Cota · el predecesor vuelve a RESOLVED sin marca crónica y sin sucesor huérfano',
    count($exhaustedRows) === 1
        && (int)($exhaustedReloaded['id'] ?? 0) === $exhaustedPredecessorId
        && ($exhaustedReloaded['status'] ?? null) === 'RESOLVED'
        && !str_contains((string)($exhaustedReloaded['reopen_reason'] ?? ''), '[AVERÍA CRÓNICA]')
        && ($exhaustedReloaded['closed_at'] ?? null) === null,
    json_encode($exhaustedRows)
);
$exhaustedHistory = $historyCounts($exhaustedPredecessorId);
$assert(
    '3.4 Cota · el cierre del predecesor se deshizo con la transacción: no dejó historial',
    $exhaustedHistory['total'] === 7,
    json_encode($exhaustedHistory)
);
$assert(
    '3.5 Cota · el intento perdedor no firmó el reemplazo en la auditoría',
    $auditCount($exhaustedPredecessorId, 'INCIDENT_CHRONIC_REPLACED') === 0
);

// ---------------------------------------------------------------------
// CASO 4 · Alta oportunista agotada: la capa HTTP responde 409, no 500
// ---------------------------------------------------------------------
// El servicio ya devuelve `TicketCodeCollisionException`; lo que se certifica aquí
// es la costura que ve el técnico: el controlador real la traduce al código de
// error del contrato en lugar de dejarla caer en el 500 genérico.
echo "\n--- Caso 4: el alta oportunista agotada se traduce a 409 en la capa HTTP ---\n";

TestDataCleaner::purgeIncidentsByMachine($pdo, $machine['id']);

$httpHeldCode = 'INC-2026-G9H1';
$httpPayload = [
    'machine_id' => $machine['id'],
    'category' => 'PRODUCT_JAM',
    'origin_reason' => 'TECHNICIAN_DETECTION',
    'description' => 'Atasco en la espiral B4 detectado durante la visita de la carrera G9 (cota HTTP).',
];
$httpController = new TechnicianOpportunisticController($serviceWith([$httpHeldCode, $httpHeldCode, $httpHeldCode]));
$httpRace = $race(
    $holdIncidentSql($httpHeldCode, $holderMachines['http']),
    static function () use ($httpController, $jordi, $httpPayload): Response {
        $request = new Request('POST', '/api/technician/opportunistic/incidents', [], $httpPayload);
        $request->setAttribute('authenticated_user', $jordi);

        return $httpController->createIncident($request);
    }
);
$assert(
    '4.1 Cota HTTP · el titular retuvo la fila sin confirmar y confirmó en verde',
    $httpRace['ready'] === true && $httpRace['exit'] === 0 && str_contains($httpRace['log'], 'COMMITTED'),
    $httpRace['log']
);

$httpResponse = $httpRace['result'] instanceof Response ? $httpRace['result'] : null;
$httpBody = $httpResponse?->getDecodedBody() ?? [];
$assert(
    '4.2 Cota HTTP · el alta responde 409 TICKET_CODE_COLLISION con los intentos consumidos',
    $httpResponse !== null
        && $httpResponse->getStatusCode() === 409
        && ($httpBody['error']['code'] ?? null) === 'TICKET_CODE_COLLISION'
        && (int)($httpBody['error']['details']['attempts'] ?? 0) === IncidentTicketAllocator::MAX_ATTEMPTS,
    'status: ' . var_export($httpResponse?->getStatusCode(), true) . ' · body: ' . json_encode($httpBody)
);
$assert(
    '4.3 Cota HTTP · el alta fallida no dejó ningún expediente en la máquina',
    $machineRows($machine['id']) === []
);

// ---------------------------------------------------------------------
// CIERRE · la colisión fue real y no queda residuo
// ---------------------------------------------------------------------
echo "\n--- Cierre: la colisión fue real ---\n";

foreach ([$createHeldCode, $chronicHeldCode, $exhaustedHeldCode, $httpHeldCode] as $heldCode) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM incidents WHERE ticket_code = :code');
    $stmt->execute([':code' => $heldCode]);
    $assert("El código titular {$heldCode} quedó confirmado por el proceso perdedor de la carrera", (int)$stmt->fetchColumn() === 1);
}

TestDataCleaner::purge($pdo);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions}\n";
echo " Aserciones fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. EARS 2.4 CUBIERTO EN LAS DOS PUERTAS OPORTUNISTAS.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
