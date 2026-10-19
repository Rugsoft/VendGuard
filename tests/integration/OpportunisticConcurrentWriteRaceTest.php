<?php

declare(strict_types=1);

/**
 * OpportunisticConcurrentWriteRaceTest (Módulo 16 · laguna G5)
 *
 * Certifica la **carrera real** entre dos altas oportunistas simultáneas sobre la
 * misma máquina, con dos procesos y dos conexiones PDO independientes:
 *
 *   1. El proceso titular abre su conexión, inserta el expediente dentro de una
 *      transacción y se queda con la fila sin confirmar.
 *   2. El proceso de esta suite (segunda conexión) intenta insertar otro
 *      expediente sobre la misma máquina. La comprobación previa no ve la fila
 *      ajena —no está confirmada—, así que la escritura **espera** en el índice
 *      único condicional `uq_machine_active_ticket`.
 *   3. Al confirmar el titular, PostgreSQL responde con SQLSTATE `23505`; el
 *      repositorio la traduce a `DuplicateIncidentException`
 *      (`MACHINE_HAS_ACTIVE_INCIDENT`) y el técnico recibe el 409 con el ticket
 *      existente en lugar de un 500.
 *
 * La espera medida es la prueba de que la segunda escritura no decidió sobre una
 * foto vieja: si el índice no mordiera, el intento habría terminado de inmediato.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en español.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\OpportunisticMode;
use VendGuard\Core\Domain\ValueObject\OpportunisticOriginReason;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard · Módulo 16 · G5 · Carrera real de altas oportunistas\n";
echo " Dos procesos · dos conexiones PDO · mismo índice único\n";
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

$machineRepo = new PdoMachineRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$jordi = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$machine = $machineRepo->findByCode('VEND-0302');

$assert('0.1 Semilla disponible: técnico y máquina del escenario', $jordi !== null && $machine !== null);
if ($jordi === null || $machine === null) {
    echo "\nNo se puede certificar sin la semilla del escenario.\n";
    exit(1);
}

TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machine->getId());

$machineId = (int)$machine->getId();
$locationId = (int)$machine->getLocationId();
$technicianId = (int)$jordi->getId();

$stamp = static fn (int $offsetSeconds = 0): string => EngineClock::shift($offsetSeconds);

// ─── Proceso titular: inserta y retiene la fila sin confirmar ────────────────
$readyFile = tempnam(sys_get_temp_dir(), 'vg_g5_ready_');
$logFile = tempnam(sys_get_temp_dir(), 'vg_g5_log_');
@unlink($readyFile);
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $logFile, 'w'],
    2 => ['file', $logFile, 'a'],
];
$holderCommand = [
    PHP_BINARY,
    __DIR__ . '/../Support/OpportunisticRaceHolder.php',
    (string)$machineId,
    (string)$locationId,
    (string)$technicianId,
    $readyFile,
    '1500',
];
$holder = proc_open($holderCommand, $descriptors, $holderPipes);
$assert('0.2 El proceso titular arranca con conexión propia', is_resource($holder));
if (!is_resource($holder)) {
    echo "\nNo se puede certificar sin el proceso titular.\n";
    exit(1);
}
fclose($holderPipes[0]);

$readyDeadline = microtime(true) + 10.0;
while (!is_file($readyFile) && microtime(true) < $readyDeadline) {
    usleep(50_000);
}
$holderIncidentId = is_file($readyFile) ? (int)trim((string)file_get_contents($readyFile)) : 0;
$assert(
    '0.3 El titular deja su expediente insertado y sin confirmar',
    $holderIncidentId > 0,
    'readyFile=' . $readyFile . ' · log=' . trim((string)file_get_contents($logFile))
);

if ($holderIncidentId < 1) {
    proc_terminate($holder);
    proc_close($holder);
    @unlink($readyFile);
    @unlink($logFile);
    exit(1);
}

// ─── Segunda conexión: intenta el alta mientras la fila ajena sigue abierta ──
$pdoB = ConnectionFactory::getConnection([], true);
$repoB = new PdoIncidentRepository($pdoB);
$pdoB->exec("SET lock_timeout = '8000ms'");

$contenderTicket = 'INC-2026-G5C' . random_int(100, 999);
$contender = new Incident(
    id: null,
    ticketCode: $contenderTicket,
    machineId: $machineId,
    locationId: $locationId,
    category: IncidentCategory::PRODUCT_JAM,
    description: 'Alta perdedora de la carrera real de la certificación G5.',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: $technicianId,
    reporterName: 'Fixture G5 · Contender',
    assignedAt: $stamp(),
    startedAt: $stamp(),
    cycleStartedAt: $stamp(),
    opportunisticMode: OpportunisticMode::NEW,
    opportunisticOriginReason: OpportunisticOriginReason::TECHNICIAN_DETECTION
);

$startedAt = microtime(true);
$raceError = null;
try {
    $repoB->createOpportunisticIncident($contender, $technicianId, 'Alta perdedora de la carrera G5');
} catch (Throwable $exception) {
    $raceError = $exception;
}
$elapsed = microtime(true) - $startedAt;

$assert(
    '1.1 La segunda alta espera al índice único en lugar de decidir sobre una foto vieja',
    $elapsed >= 0.30,
    sprintf('espera medida: %.3f s', $elapsed)
);
$assert(
    '1.2 Tras confirmar el titular, la colisión se traduce a DuplicateIncidentException (409)',
    $raceError instanceof DuplicateIncidentException
        && $raceError->getErrorCode() === 'MACHINE_HAS_ACTIVE_INCIDENT'
        && $raceError->getHttpStatusCode() === 409,
    $raceError !== null
        ? get_class($raceError) . ': ' . $raceError->getMessage()
        : 'la segunda alta no lanzó ninguna excepción'
);
$assert(
    '1.3 La perdedora informa del expediente que SÍ existe',
    $raceError instanceof DuplicateIncidentException
        && $raceError->getTicketCode() === $pdo->query(
            'SELECT ticket_code FROM incidents WHERE id = ' . $holderIncidentId
        )->fetchColumn()
);

// ─── Cierre del proceso titular y verificación del estado final ─────────────
$holderExit = proc_close($holder);
$holderLog = trim((string)file_get_contents($logFile));
@unlink($readyFile);
@unlink($logFile);

$assert(
    '1.4 El proceso titular confirma su transacción y termina en verde',
    $holderExit === 0 && str_contains($holderLog, 'COMMITTED ' . $holderIncidentId),
    'exit=' . $holderExit . ' · log=' . $holderLog
);

$incidentCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM incidents WHERE machine_id = ' . $machineId . ' AND deleted_at IS NULL'
)->fetchColumn();
$winnerRow = $pdo->query(
    'SELECT ticket_code, status, opportunistic_mode FROM incidents WHERE id = ' . $holderIncidentId
)->fetch(PDO::FETCH_ASSOC);
$loserRows = (int)$pdo->query(
    "SELECT COUNT(*) FROM incidents WHERE ticket_code = " . $pdo->quote($contenderTicket)
)->fetchColumn();

$assert(
    '1.5 Sobrevive UN solo expediente activo en la máquina y es el del titular',
    $incidentCount === 1
        && is_array($winnerRow)
        && ($winnerRow['ticket_code'] ?? null) !== $contenderTicket
        && ($winnerRow['status'] ?? null) === 'IN_PROGRESS'
        && ($winnerRow['opportunistic_mode'] ?? null) === 'NEW',
    'filas=' . $incidentCount
);
$assert(
    '1.6 La perdedora no dejó rastro de expediente ni de ticket',
    $loserRows === 0
);

$historyCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM incident_history WHERE incident_id = ' . $holderIncidentId
)->fetchColumn();
$assert('1.7 El titular conserva un único evento inicial en el historial', $historyCount === 1);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} · Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. G5 CERTIFICADA.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

TestDataCleaner::purgeIncidentsByMachine($pdo, $machineId);

exit($failures === 0 ? 0 : 1);
