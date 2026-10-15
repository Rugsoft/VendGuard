<?php

declare(strict_types=1);

/**
 * VendGuard — Carga bajo demanda del histórico de averías de demostración.
 *
 * Uso:
 *   php bin/seed_demo_history.php
 *
 * Añade 17 averías con su traza de estados y su rastro de auditoría, para que el
 * cuadro de mandos de Métricas, el listado de averías y el detalle del coordinador
 * tengan datos con los que probar:
 *
 *   · 13 averías **completadas** (`CLOSED`), con su cierre dentro de la ventana de
 *     garantía de 48 h.
 *   · 4 averías **en garantía** (`RESOLVED`, sin cierre), que es el estado con el
 *     que se prueba el aviso de avería en garantía y su cuenta atrás.
 *   · 2 de ellas **reabiertas de verdad** por la sede dentro de las 48 h: la traza
 *     encadena resolución → reapertura → reasignación → reintervención, que es lo
 *     que alimenta el **indicador de reincidencias** (`warranty_reopens`) del
 *     desglose por técnico (EARS 2.3.1). Al terminar, el script imprime ese
 *     desglose tal y como lo lee el coordinador.
 *
 * Es la vía del **motor nuevo** (PostgreSQL en Supabase, el despliegue vigente):
 * el sembrador escribe identificadores desnudos y delega la cláusula de upsert en
 * el adaptador de dialecto, así que el mismo comando funciona también contra el
 * motor local. El fixture del arnés (`DemoMetricsSeeder`) sigue siendo el del
 * motor local y no se toca.
 *
 * No se ejecuta en el arranque por defecto: el arranque en limpio es estricto
 * (EARS-02.1, sin incidencias ni historiales previos). Para que el contenedor lo
 * cargue al arrancar hay un opt-in explícito y declarado:
 * `VENDGUARD_DEMO_HISTORY=1` en el entorno del servicio.
 *
 * Script sin autoloader, como el resto de `bin/`: cada dependencia de la cadena se
 * declara a mano (`ConnectionFactory` → `SecretProvider` por S-5; `SeedRunner` no
 * interviene aquí, pero el adaptador de dialecto sí: T-HYBRID-25, y el repositorio
 * de métricas de la comprobación final arrastra su propio contrato de dominio). La
 * guarda `CloudDeploySchemaParityTest` Caso 1c-bis certifica en proceso limpio que
 * esta lista está completa.
 */

require_once __DIR__ . '/../src/Infrastructure/Config/SecretProvider.php';
require_once __DIR__ . '/../src/Core/Domain/Service/SiteAccessCodeGenerator.php';
require_once __DIR__ . '/../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../src/Infrastructure/Dialect/SqlDialectAdapter.php';
require_once __DIR__ . '/../src/Core/Domain/Model/MetricFilter.php';
require_once __DIR__ . '/../src/Core/Domain/Model/MachineType.php';
require_once __DIR__ . '/../src/Core/Domain/Model/KpiSummary.php';
require_once __DIR__ . '/../src/Core/Domain/Repository/MetricsRepositoryInterface.php';
require_once __DIR__ . '/../src/Infrastructure/Repository/PdoMetricsRepository.php';
require_once __DIR__ . '/../database/DemoHistorySeeder.php';

use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Database\DemoHistorySeeder;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;
use VendGuard\Infrastructure\Repository\PdoMetricsRepository;

echo "======================================================================\n";
echo " VendGuard: Histórico de averías de demostración\n";
echo "======================================================================\n\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $dialect = SqlDialectAdapter::forConnection($pdo);

    echo "[1/3] Conexión establecida. Motor detectado: {$dialect->driver()}\n\n";

    $fixtures = DemoHistorySeeder::fixtures();
    $completed = count(array_filter($fixtures, static fn (array $fixture): bool => $fixture['status'] === 'CLOSED'));
    $inWarranty = count(array_filter($fixtures, static fn (array $fixture): bool => $fixture['status'] === 'RESOLVED'));
    $reopened = count(array_filter($fixtures, static fn (array $fixture): bool => isset($fixture['reopen'])));

    echo '[2/3] Sembrando ' . count($fixtures) . " averías ({$completed} completadas, {$inWarranty} en garantía,"
        . " {$reopened} con reincidencia por garantía)...\n";
    $seeder = new DemoHistorySeeder($pdo);
    $summary = $seeder->seed();
    echo "      - Averías escritas/actualizadas: {$summary['incidents']}\n";
    echo "      - Eventos de traza de estados (incident_history): {$summary['history_events']}\n";
    echo "      - Registros de auditoría (audit_log): {$summary['audit_events']}\n\n";

    echo "[3/3] Comprobando lo que el cuadro de mandos va a leer...\n";

    // La diferencia temporal entre dos marcas no tiene forma común en los dos
    // motores: la regla se declara una vez y el adaptador resuelve su sintaxis.
    $elapsedMinutes = $dialect->timestampDifference('MINUTE', 'created_at', 'resolved_at');

    $global = $pdo->query(
        "SELECT COUNT(*) AS total_resolved, ROUND(AVG({$elapsedMinutes})) AS avg_mttr_minutes"
        . " FROM incidents WHERE resolved_at IS NOT NULL AND status IN ('RESOLVED', 'CLOSED')"
    )->fetch(PDO::FETCH_ASSOC);

    $mttrMinutes = (int)($global['avg_mttr_minutes'] ?? 0);
    echo "      * Averías resueltas o cerradas: {$global['total_resolved']}\n";
    echo '      * MTTR medio: ' . intdiv($mttrMinutes, 60) . 'h ' . ($mttrMinutes % 60) . "m ({$mttrMinutes} min)\n";

    $perishable = $pdo->query(
        "SELECT COUNT(*) AS total, ROUND(AVG({$dialect->timestampDifference('MINUTE', 'i.created_at', 'i.resolved_at')})) AS avg_mttr_minutes"
        . " FROM incidents i JOIN machines m ON i.machine_id = m.id"
        . " WHERE i.resolved_at IS NOT NULL AND i.status IN ('RESOLVED', 'CLOSED')"
        . " AND m.machine_type = 'PERISHABLE_FOOD'"
    )->fetch(PDO::FETCH_ASSOC);

    $perishableMinutes = (int)($perishable['avg_mttr_minutes'] ?? 0);
    echo "      * Perecederos atendidos: {$perishable['total']}"
        . ' · MTTR ' . intdiv($perishableMinutes, 60) . 'h ' . ($perishableMinutes % 60) . 'm'
        . ' (SLA < 4,0 h: ' . ($perishableMinutes <= 240 ? 'CUMPLIDO' : 'INCUMPLIDO') . ")\n";

    // El indicador de reincidencias se lee por donde lo lee el coordinador: el
    // desglose por técnico del repositorio de métricas, con el filtro por defecto
    // del cuadro de mandos: `warranty_reopens` son las reaperturas por garantía
    // atribuidas al técnico que firmó la resolución reabierta (EARS 2.3.1).
    $filter = MetricFilter::fromQueryParams(['period' => MetricFilter::PERIOD_LAST_30_DAYS]);
    $breakdown = (new PdoMetricsRepository($pdo))->getBreakdownByTechnician($filter);

    echo "\n      Desglose por técnico (ventana de 30 días):\n";
    echo "      ------------------------------------------------------------------\n";
    printf("      %-30s %10s %14s\n", 'Técnico', 'Resueltas', 'Reincidencias');
    echo "      ------------------------------------------------------------------\n";

    $totalReopens = 0;
    foreach ($breakdown as $row) {
        $reopens = (int)$row['warranty_reopens'];
        $totalReopens += $reopens;
        printf(
            "      %-30s %10d %14s\n",
            (string)$row['technician_name'],
            (int)$row['tickets_resolved'],
            $reopens > 0 ? (string)$reopens : '·'
        );
    }
    echo "      ------------------------------------------------------------------\n";
    echo '      Reincidencias por garantía contadas en la ventana: ' . $totalReopens . "\n";

    echo "\n[LISTO] Abre el Cuadro de Mandos de Métricas y el listado de averías: los datos ya están.\n";
    echo "======================================================================\n";
    exit(0);
} catch (Throwable $e) {
    echo "\n[ERROR] No se pudo cargar el histórico de demostración: " . $e->getMessage() . "\n";
    exit(1);
}
