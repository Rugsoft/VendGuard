<?php

declare(strict_types=1);

/**
 * OpportunisticRaceHolder (Módulo 16 · G5)
 *
 * Proceso titiritero de la carrera REAL de altas oportunistas: abre su propia
 * conexión PDO, inserta el expediente dentro de una transacción y **espera** con
 * la fila sin confirmar. El proceso padre lanza en ese instante un segundo alta
 * por otra conexión, que queda bloqueada por el índice único condicional hasta
 * que este proceso confirma. Es la única forma de que la colisión de unicidad
 * ocurra de verdad y no simulada: un solo proceso PHP no puede hacer esperar una
 * escritura mientras confirma otra en el mismo hilo.
 *
 * Uso (invocado por `OpportunisticConcurrentWriteRaceTest`):
 *   php OpportunisticRaceHolder.php <machineId> <locationId> <technicianId> <readyFile> <holdMs>
 *
 * Contrato de salida:
 *   - Escribe el ID insertado en `<readyFile>` en cuanto la fila está sin confirmar.
 *   - Duerme `<holdMs>` milisegundos y confirma.
 *   - Imprime `COMMITTED <id>` y termina con código 0; cualquier fallo va a stderr.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\OpportunisticMode;
use VendGuard\Core\Domain\ValueObject\OpportunisticOriginReason;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;

$machineId = (int)($argv[1] ?? 0);
$locationId = (int)($argv[2] ?? 0);
$technicianId = (int)($argv[3] ?? 0);
$readyFile = (string)($argv[4] ?? '');
$holdMs = (int)($argv[5] ?? 0);

if ($machineId < 1 || $locationId < 1 || $technicianId < 1 || $readyFile === '' || $holdMs < 1) {
    fwrite(STDERR, "Uso: php OpportunisticRaceHolder.php <machineId> <locationId> <technicianId> <readyFile> <holdMs>\n");
    exit(2);
}

$pdo = ConnectionFactory::getConnection([], true);
$repo = new PdoIncidentRepository($pdo);

$stamp = static fn (int $offsetSeconds = 0): string => EngineClock::shift($offsetSeconds);

$incident = new Incident(
    id: null,
    ticketCode: 'INC-2026-G5H' . random_int(100, 999),
    machineId: $machineId,
    locationId: $locationId,
    category: IncidentCategory::PRODUCT_JAM,
    description: 'Alta ganadora de la carrera real de la certificación G5.',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::IN_PROGRESS,
    assignedTechnicianId: $technicianId,
    reporterName: 'Fixture G5 · Holder',
    assignedAt: $stamp(),
    startedAt: $stamp(),
    cycleStartedAt: $stamp(),
    opportunisticMode: OpportunisticMode::NEW,
    opportunisticOriginReason: OpportunisticOriginReason::TECHNICIAN_DETECTION
);

$pdo->beginTransaction();

try {
    $created = $repo->createOpportunisticIncident($incident, $technicianId, 'Alta ganadora de la carrera G5');
    $createdId = (int)$created->getId();

    file_put_contents($readyFile, (string)$createdId);

    usleep($holdMs * 1000);

    $pdo->commit();
    echo "COMMITTED {$createdId}\n";
    exit(0);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
