<?php

declare(strict_types=1);

/**
 * MttrMetricTest
 * 
 * Suite de pruebas unitarias para el Value Object MttrMetric (T-MET-02).
 * Valida el cálculo determinista del MTTR, formateo legible en "Xh Ym", horas con 1 decimal,
 * manejo seguro de muestra vacía ("N/A"), protección ante anomalías horarias (resolved < created)
 * y exclusión estricta de incidencias canceladas o duplicadas.
 * 
 * Cumple con RF-01 (EARS 1.1, 1.3, 1.4, 1.6, 1.7, 1.8), RNF-01 y el Dogma Vanilla de VendGuard.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\MttrMetric;

echo "======================================================================\n";
echo " VendGuard: Verificación Unitaria de MttrMetric (T-MET-02)\n";
echo "======================================================================\n\n";

$assertions = 0;

function assertCondition(bool $cond, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$cond) {
        echo "  [FALLO] {$message}\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

try {
    // -------------------------------------------------------------
    // 1. Instanciación Básica, Formateo y Decimales (RF-01, EARS 1.1, 1.8)
    // -------------------------------------------------------------
    echo "--- 1. Instanciación Básica, Formateo y Decimales ---\n";

    $metric1 = MttrMetric::fromMinutes(195);
    assertCondition($metric1->getMinutes() === 195, "1.1 Minutos correctos (195 min)");
    assertCondition($metric1->getFormatted() === "3h 15m", "1.2 Formateo legible exacto ('3h 15m')");
    assertCondition($metric1->getHours() === 3.3, "1.3 Horas decimales redondeadas con un decimal (3.3)");
    assertCondition($metric1->hasData() === true, "1.4 hasData() retorna true");

    $metricExactHour = MttrMetric::fromMinutes(120);
    assertCondition($metricExactHour->getFormatted() === "2h 00m", "1.5 Formateo de horas exactas incluye dos dígitos de minutos ('2h 00m')");
    assertCondition($metricExactHour->getHours() === 2.0, "1.6 Horas decimales exactas (2.0)");

    $metricZero = MttrMetric::fromMinutes(0);
    assertCondition($metricZero->getMinutes() === 0, "1.7 Resolución inmediata en 0 minutos se computa");
    assertCondition($metricZero->getFormatted() === "0h 00m", "1.8 Formateo de 0 minutos es '0h 00m'");
    assertCondition($metricZero->getHours() === 0.0, "1.9 Horas de 0 minutos es 0.0");
    assertCondition($metricZero->hasData() === true, "1.10 Resolución en 0 minutos cuenta como dato válido");

    // -------------------------------------------------------------
    // 2. Muestra Vacía / Sin Incidencias Resueltas (RF-01, EARS 1.6)
    // -------------------------------------------------------------
    echo "\n--- 2. Tratamiento de Muestra Vacía (N/A) ---\n";

    $emptyDirect = MttrMetric::noData();
    assertCondition($emptyDirect->getMinutes() === null, "2.1 noData() tiene minutos null");
    assertCondition($emptyDirect->getFormatted() === "N/A", "2.2 noData() muestra 'N/A'");
    assertCondition($emptyDirect->getHours() === null, "2.3 noData() tiene horas null");
    assertCondition($emptyDirect->hasData() === false, "2.4 noData() hasData() retorna false");

    $fromNull = MttrMetric::fromMinutes(null);
    assertCondition($fromNull->getFormatted() === "N/A" && $fromNull->getMinutes() === null, "2.5 fromMinutes(null) produce N/A");

    $fromNegative = MttrMetric::fromMinutes(-15);
    assertCondition($fromNegative->getFormatted() === "N/A" && $fromNegative->getMinutes() === null, "2.6 fromMinutes con valor negativo se normaliza a N/A");

    $fromEmptyArray = MttrMetric::fromTicketDurations([]);
    assertCondition($fromEmptyArray->getFormatted() === "N/A", "2.7 fromTicketDurations con array vacío produce N/A sin división por cero");

    // -------------------------------------------------------------
    // 3. Cálculo Determinista a partir de Fechas y Tiempo 24/7 (EARS 1.1)
    // -------------------------------------------------------------
    echo "\n--- 3. Cálculo de Tiempos 24/7 y Media Aritmética ---\n";

    $sampleTickets = [
        [
            'id' => 1,
            'status' => 'RESOLVED',
            'created_at' => '2026-09-01 08:00:00',
            'resolved_at' => '2026-09-01 10:30:00', // 150 minutos (2h 30m)
        ],
        [
            'id' => 2,
            'status' => 'CLOSED',
            'created_at' => '2026-09-01 12:00:00',
            'resolved_at' => '2026-09-01 16:00:00', // 240 minutos (4h 00m)
        ],
    ];
    // Media esperada: (150 + 240) / 2 = 390 / 2 = 195 minutos -> 3h 15m (3.3h)
    $calculated = MttrMetric::fromTicketDurations($sampleTickets);
    assertCondition($calculated->getMinutes() === 195, "3.1 Cálculo promedio correcto de 195 min");
    assertCondition($calculated->getFormatted() === "3h 15m", "3.2 Formateo del promedio es '3h 15m'");
    assertCondition($calculated->getHours() === 3.3, "3.3 Horas promedio decimales (3.3)");

    // -------------------------------------------------------------
    // 4. Exclusión Estricta de Cancelados y Duplicados (EARS 1.4)
    // -------------------------------------------------------------
    echo "\n--- 4. Exclusión de Tickets Cancelados y Duplicados ---\n";

    $ticketsWithCancelled = [
        [
            'id' => 1,
            'status' => 'RESOLVED',
            'created_at' => '2026-09-01 10:00:00',
            'resolved_at' => '2026-09-01 12:00:00', // 120 minutos
        ],
        [
            'id' => 2,
            'status' => 'CANCELLED',
            'created_at' => '2026-09-01 00:00:00',
            'resolved_at' => '2026-09-05 00:00:00', // Cancelado a los 4 días -> debe ignorarse por completo
        ],
        [
            'id' => 3,
            'status' => 'DUPLICATE',
            'created_at' => '2026-09-01 00:00:00',
            'resolved_at' => '2026-09-02 00:00:00', // Descartado por duplicado -> debe ignorarse
        ],
    ];

    $metricWithCancelled = MttrMetric::fromTicketDurations($ticketsWithCancelled);
    assertCondition($metricWithCancelled->getMinutes() === 120, "4.1 Los tickets CANCELLED y DUPLICATE se excluyen (tiempo = 120 min, no distorsionado)");
    assertCondition($metricWithCancelled->getFormatted() === "2h 00m", "4.2 Formateo resultante es '2h 00m'");

    // Si todos los tickets son cancelados, el resultado debe ser N/A
    $onlyCancelled = [
        ['status' => 'CANCELLED', 'created_at' => '2026-09-01 10:00:00', 'resolved_at' => '2026-09-01 11:00:00'],
    ];
    $metricOnlyCancelled = MttrMetric::fromTicketDurations($onlyCancelled);
    assertCondition($metricOnlyCancelled->getFormatted() === "N/A", "4.3 Muestra que solo contiene cancelados resulta en N/A");

    // -------------------------------------------------------------
    // 5. Protección ante Inconsistencias de Reloj (EARS 1.7)
    // -------------------------------------------------------------
    echo "\n--- 5. Protección ante Inconsistencia Horaria (resolved < created) ---\n";

    $inconsistentTickets = [
        [
            'status' => 'RESOLVED',
            'created_at' => '2026-09-01 12:00:00',
            'resolved_at' => '2026-09-01 11:30:00', // Error reloj: resolución antes de creación
        ],
        [
            'status' => 'RESOLVED',
            'created_at' => '2026-09-01 10:00:00',
            'resolved_at' => '2026-09-01 11:00:00', // 60 minutos
        ]
    ];
    // Inconsistente computa 0 min + 60 min = 60 / 2 = 30 min
    $metricInconsistent = MttrMetric::fromTicketDurations($inconsistentTickets);
    assertCondition($metricInconsistent->getMinutes() === 30, "5.1 Tiempo negativo por desincronización se trunca a 0 (media = 30 min)");
    assertCondition($metricInconsistent->getFormatted() === "0h 30m", "5.2 Formateo seguro '0h 30m'");

    // -------------------------------------------------------------
    // 6. Serialización JSON y Array
    // -------------------------------------------------------------
    echo "\n--- 6. Serialización JSON y Array ---\n";

    $arr = $metric1->toArray();
    assertCondition(isset($arr['minutes'], $arr['formatted'], $arr['hours']), "6.1 toArray() contiene todas las claves requeridas");
    assertCondition($arr['minutes'] === 195 && $arr['formatted'] === '3h 15m' && $arr['hours'] === 3.3, "6.2 toArray() valores correctos");

    $json = json_encode($metric1);
    assertCondition(is_string($json) && str_contains($json, '"formatted":"3h 15m"'), "6.3 jsonSerialize() codifica correctamente");

    echo "\n======================================================================\n";
    echo " RESULTADO: {$assertions}/{$assertions} aserciones pasadas exitosamente [100% VERDE]\n";
    echo "======================================================================\n";

} catch (Throwable $e) {
    echo "\n[ERROR INESPERADO]: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
