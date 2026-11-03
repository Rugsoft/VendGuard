<?php

declare(strict_types=1);

/**
 * VendGuard - CustodyChainPerformanceTest (Módulo 26, RNF-01)
 *
 * Mide la composición de la ficha de detalle con una cadena de custodia viva y
 * exige que el percentil 95 quede por debajo de los 300 ms del RNF-01. No es una
 * prueba de carga: es una guarda contra una regresión que convierta la cadena en
 * una consulta N+1 o en un recorrido cuadrático.
 *
 * Dogma Vanilla: PHP 8.2 estricto, PDO nativo, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\CoordinatorIncidentDetailService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard · Módulo 26 · RNF-01 (ficha con custodia < 300 ms)\n";
echo "======================================================================\n\n";

$samples = 20;
$budgetMs = 300.0;

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$incidentRepo = new PdoIncidentRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$detailService = new CoordinatorIncidentDetailService($incidentRepo);

$failures = 0;
$assert = function (string $title, bool $condition, string $message = '') use (&$failures): void {
    if ($condition) {
        echo "  [PASS] {$title}\n";
    } else {
        echo "  [FAIL] {$title}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$techA       = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$techB       = $userRepo->findByEmail('marta.ruta@vendguard.internal');
$machine     = $machineRepo->findByCode('VEND-0301');
$assert('0.1 Semillas suficientes', $coordinator !== null && $techA !== null && $techB !== null && $machine !== null);
if ($coordinator === null || $techA === null || $techB === null || $machine === null) {
    echo "ERROR FATAL: sin semillas no se puede medir.\n";
    exit(1);
}

TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machine->getId());

$incident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: 'T26P-' . strtoupper(uniqid()),
    machineId: $machine->getId(),
    locationId: $machine->getLocationId(),
    category: IncidentCategory::ELECTRICAL_OFF,
    description: 'Fixture de latencia de la cadena de custodia.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    createdAt: null
));
$incidentId = (int)$incident->getId();

$incidentRepo->assign($incidentId, (int)$techA->getId());
$incidentRepo->assign($incidentId, (int)$techB->getId(), (int)$coordinator->getId(), null, null, 'Primera reasignación de la medición.');
$incidentRepo->assign($incidentId, (int)$techA->getId(), (int)$coordinator->getId(), null, null, 'Segunda reasignación de la medición.');

// Calentamiento: la primera lectura paga la preparación del motor y no representa
// el coste en régimen de la ficha.
$warmup = $detailService->buildDetail($incidentId);
$chain = $warmup?->toArray()['custody_chain'] ?? [];
$assert('0.2 La ficha de calentamiento incluye tres tramos', (int)($chain['summary']['total_assignments_count'] ?? 0) === 3);

$times = [];
for ($i = 0; $i < $samples; $i++) {
    $start = hrtime(true);
    $dto = $detailService->buildDetail($incidentId);
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;
    if ($dto === null) {
        $failures++;
        echo "  [FAIL] La lectura {$i} devolvió null\n";
        continue;
    }
    $times[] = $elapsedMs;
}

sort($times);
$count = count($times);
$p95Index = max(0, (int)ceil(0.95 * $count) - 1);
$p95 = $count > 0 ? $times[$p95Index] : INF;
$median = $count > 0 ? $times[intdiv($count, 2)] : INF;

printf("  Medición: n=%d · mediana=%.1f ms · p95=%.1f ms · máx=%.1f ms\n", $count, $median, $p95, $count > 0 ? $times[$count - 1] : INF);

$assert(
    sprintf('1.1 El percentil 95 queda por debajo de %.0f ms', $budgetMs),
    $p95 < $budgetMs,
    sprintf('p95 medido: %.1f ms', $p95)
);
$assert('1.2 La medición tiene muestras suficientes', $count === $samples);

echo "\n======================================================================\n";
echo " RESULTADO: {$failures} FALLO(S)\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
