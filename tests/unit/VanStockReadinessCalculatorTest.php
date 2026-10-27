<?php

declare(strict_types=1);

/**
 * VanStockReadinessCalculatorTest · T-RESTOCK-04
 *
 * Certifica el indicador `van_stock_readiness` de las tarjetas de avería activa
 * (Módulo 23, RF-05.4, contrato §3.3 y §5.13) sin base de datos ni reloj: el
 * calculador es puro y esa pureza es lo que permite recorrer todos los caminos.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Los cinco estados del contrato.** `READY_IN_VAN`, `SHARED_IN_VAN`,
 *      `NOT_READY`, `HAS_OUT_OF_CATALOG` y `NOT_APPLICABLE`, con sus etiquetas
 *      exactas y sin insignia en los estados que no afirman cobertura.
 *   2. **Stock compartido (QA 3).** Dos averías del mismo técnico pidiendo la
 *      misma pieza con saldo para una sola: ambas deben decir «stock compartido»
 *      y no dar por hecho que hay unidades para las dos a la vez. El caso
 *      contrario —saldo igual a la demanda agregada— sigue siendo cobertura
 *      completa.
 *   3. **Fuera de catálogo (QA 2).** Una solicitud `PENDING` fuera de catálogo
 *      manda sobre la cobertura catalogada: nunca se pinta la insignia de
 *      repuesto disponible, ni siquiera cuando la pieza catalogada está cubierta
 *      o el saldo es insuficiente.
 *   4. **Alcance de la agregación.** Sólo suman las averías activas del mismo
 *      técnico: la demanda de otro compañero, o de una avería cerrada, no debe
 *      convertir una cobertura completa en compartida.
 *   5. **Saldos negativos y filas ausentes.** Un descuadre negativo vale `0` y
 *      una pieza sin fila en `technician_van_stocks` no cubre nada.
 *   6. **Pureza y forma.** Dos llamadas con el mismo contexto coinciden, los
 *      argumentos no se mutan, todas las averías reciben indicador y el objeto
 *      tiene exactamente las cinco claves del contrato.
 *
 * Uso:
 *   php tests/unit/VanStockReadinessCalculatorTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\VanStockReadinessCalculator;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$calculator = new VanStockReadinessCalculator();

$incident = static fn (int $id, string $status, ?int $technicianId): array => [
    'incident_id' => $id,
    'status' => $status,
    'assigned_technician_id' => $technicianId,
];

$requests = static fn (array $catalog, int $outOfCatalog = 0): array => [
    'catalog' => $catalog,
    'out_of_catalog' => $outOfCatalog,
];

$statusOf = static fn (array $readiness, int $incidentId): ?string => $readiness[$incidentId]['status'] ?? null;
// `??` confundiría una etiqueta nula legítima (`NOT_READY`, `HAS_OUT_OF_CATALOG`,
// `NOT_APPLICABLE`) con una clave ausente, que es justo lo que hay que distinguir.
$badgeOf = static function (array $readiness, int $incidentId): ?string {
    if (!isset($readiness[$incidentId]) || !array_key_exists('badge_label', $readiness[$incidentId])) {
        return '__missing__';
    }

    return $readiness[$incidentId]['badge_label'];
};

echo "======================================================================\n";
echo " VENDGUARD · T-RESTOCK-04 · Indicador de disponibilidad en furgoneta\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. READY_IN_VAN: la furgoneta cubre la avería y toda la demanda del técnico
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. READY_IN_VAN (📦 Repuesto ya en furgoneta) ---\n";

$ready = $calculator->calculate(
    [$incident(101, 'PENDING_PARTS', 2)],
    [101 => $requests([14 => 2])],
    [2 => [14 => 5]]
);

$assert(
    '1.1 Saldo holgado => READY_IN_VAN con la etiqueta del contrato',
    $statusOf($ready, 101) === VanStockReadinessCalculator::STATUS_READY_IN_VAN
        && $badgeOf($ready, 101) === '📦 Repuesto ya en furgoneta'
);
$assert(
    '1.2 El indicador declara 1/1 referencias cubiertas y sin fuera de catálogo',
    $ready[101]['covered_references'] === 1
        && $ready[101]['total_catalog_references'] === 1
        && $ready[101]['has_out_of_catalog_requests'] === false
);
$assert(
    '1.3 El indicador tiene exactamente las cinco claves del contrato §5.13',
    array_keys($ready[101]) === [
        'status',
        'badge_label',
        'covered_references',
        'total_catalog_references',
        'has_out_of_catalog_requests',
    ],
    implode(', ', array_keys($ready[101]))
);

$exact = $calculator->calculate(
    [$incident(102, 'ASSIGNED', 2)],
    [102 => $requests([14 => 3, 8 => 1])],
    [2 => [14 => 3, 8 => 1]]
);
$assert(
    '1.4 Saldo exactamente igual a la demanda de dos piezas => READY_IN_VAN (2/2)',
    $statusOf($exact, 102) === VanStockReadinessCalculator::STATUS_READY_IN_VAN
        && $exact[102]['covered_references'] === 2
        && $exact[102]['total_catalog_references'] === 2
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. SHARED_IN_VAN: cubre esta avería, no la suma de las del mismo técnico
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. SHARED_IN_VAN (📦 Repuesto en furgoneta · stock compartido) ---\n";

$sharedIncidents = [
    $incident(201, 'PENDING_PARTS', 3),
    $incident(202, 'IN_PROGRESS', 3),
];
$shared = $calculator->calculate(
    $sharedIncidents,
    [201 => $requests([14 => 1]), 202 => $requests([14 => 1])],
    [3 => [14 => 1]]
);

$assert(
    '2.1 Dos averías del mismo técnico pidiendo la misma pieza con una sola unidad => SHARED_IN_VAN en ambas',
    $statusOf($shared, 201) === VanStockReadinessCalculator::STATUS_SHARED_IN_VAN
        && $statusOf($shared, 202) === VanStockReadinessCalculator::STATUS_SHARED_IN_VAN
);
$assert(
    '2.2 La insignia compartida es la del contrato, distinta de la de cobertura completa',
    $badgeOf($shared, 201) === '📦 Repuesto en furgoneta (stock compartido)'
        && $badgeOf($shared, 201) !== VanStockReadinessCalculator::BADGE_READY_IN_VAN
);
$assert(
    '2.3 Cada avería sigue contando su cobertura individual (1/1) pese al compartido',
    $shared[201]['covered_references'] === 1 && $shared[201]['total_catalog_references'] === 1
);

$mixedParts = $calculator->calculate(
    [$incident(203, 'PENDING_PARTS', 4), $incident(204, 'PENDING_PARTS', 4)],
    [203 => $requests([14 => 1, 8 => 1]), 204 => $requests([14 => 2])],
    [4 => [14 => 2, 8 => 5]]
);
$assert(
    '2.4 Basta una pieza sin saldo para la demanda agregada para marcar SHARED (14: 2 < 3; 8: 5 >= 1)',
    $statusOf($mixedParts, 203) === VanStockReadinessCalculator::STATUS_SHARED_IN_VAN
        && $statusOf($mixedParts, 204) === VanStockReadinessCalculator::STATUS_SHARED_IN_VAN
);

$aggregateExact = $calculator->calculate(
    [$incident(205, 'PENDING_PARTS', 5), $incident(206, 'PENDING_PARTS', 5)],
    [205 => $requests([14 => 1]), 206 => $requests([14 => 2])],
    [5 => [14 => 3]]
);
$assert(
    '2.5 Saldo igual a la demanda agregada (3 para 1+2) => READY_IN_VAN, no compartido',
    $statusOf($aggregateExact, 205) === VanStockReadinessCalculator::STATUS_READY_IN_VAN
        && $statusOf($aggregateExact, 206) === VanStockReadinessCalculator::STATUS_READY_IN_VAN
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. NOT_READY: el saldo no cubre esta avería
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. NOT_READY (sin insignia) ---\n";

$notReady = $calculator->calculate(
    [$incident(301, 'PENDING_PARTS', 6)],
    [301 => $requests([8 => 3])],
    [6 => [8 => 2]]
);
$assert(
    '3.1 Saldo insuficiente => NOT_READY sin etiqueta',
    $statusOf($notReady, 301) === VanStockReadinessCalculator::STATUS_NOT_READY
        && $badgeOf($notReady, 301) === null
);
$assert(
    '3.2 La avería declara 0/1 referencias cubiertas',
    $notReady[301]['covered_references'] === 0 && $notReady[301]['total_catalog_references'] === 1
);

$partialMultiPart = $calculator->calculate(
    [$incident(302, 'PENDING_INFO', 6)],
    [302 => $requests([14 => 1, 8 => 2])],
    [6 => [14 => 4, 8 => 1]]
);
$assert(
    '3.3 Con una pieza cubierta y otra no => NOT_READY con 1/2',
    $statusOf($partialMultiPart, 302) === VanStockReadinessCalculator::STATUS_NOT_READY
        && $partialMultiPart[302]['covered_references'] === 1
        && $partialMultiPart[302]['total_catalog_references'] === 2
);

$negativeStock = $calculator->calculate(
    [$incident(303, 'REOPENED', 7)],
    [303 => $requests([14 => 1])],
    [7 => [14 => -3]]
);
$assert(
    '3.4 Un descuadre negativo cuenta como 0 unidades => NOT_READY',
    $statusOf($negativeStock, 303) === VanStockReadinessCalculator::STATUS_NOT_READY
);

$missingRow = $calculator->calculate(
    [$incident(304, 'ASSIGNED', 7)],
    [304 => $requests([99 => 1])],
    [7 => [14 => 50]]
);
$assert(
    '3.5 Una pieza sin fila en technician_van_stocks no cubre nada => NOT_READY',
    $statusOf($missingRow, 304) === VanStockReadinessCalculator::STATUS_NOT_READY
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. HAS_OUT_OF_CATALOG: no se afirma cobertura si falta un componente (QA 2)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. HAS_OUT_OF_CATALOG (QA 2) ---\n";

$mixedOutOfCatalog = $calculator->calculate(
    [$incident(401, 'PENDING_PARTS', 2)],
    [401 => $requests([14 => 1], 1)],
    [2 => [14 => 9999]]
);
$assert(
    '4.1 Pieza catalogada cubierta + solicitud fuera de catálogo => HAS_OUT_OF_CATALOG sin insignia',
    $statusOf($mixedOutOfCatalog, 401) === VanStockReadinessCalculator::STATUS_HAS_OUT_OF_CATALOG
        && $badgeOf($mixedOutOfCatalog, 401) === null
);
$assert(
    '4.2 El indicador conserva la cobertura catalogada (1/1) y marca la bandera',
    $mixedOutOfCatalog[401]['covered_references'] === 1
        && $mixedOutOfCatalog[401]['total_catalog_references'] === 1
        && $mixedOutOfCatalog[401]['has_out_of_catalog_requests'] === true
);

$onlyOutOfCatalog = $calculator->calculate(
    [$incident(402, 'PENDING_PARTS', 2)],
    [402 => $requests([], 2)],
    [2 => [14 => 9999]]
);
$assert(
    '4.3 Sólo solicitudes fuera de catálogo => HAS_OUT_OF_CATALOG con 0/0 referencias',
    $statusOf($onlyOutOfCatalog, 402) === VanStockReadinessCalculator::STATUS_HAS_OUT_OF_CATALOG
        && $onlyOutOfCatalog[402]['covered_references'] === 0
        && $onlyOutOfCatalog[402]['total_catalog_references'] === 0
        && $onlyOutOfCatalog[402]['has_out_of_catalog_requests'] === true
);

$outOfCatalogWins = $calculator->calculate(
    [$incident(403, 'PENDING_PARTS', 2)],
    [403 => $requests([14 => 5], 1)],
    [2 => [14 => 0]]
);
$assert(
    '4.4 Fuera de catálogo manda sobre el saldo insuficiente: no degrada a NOT_READY',
    $statusOf($outOfCatalogWins, 403) === VanStockReadinessCalculator::STATUS_HAS_OUT_OF_CATALOG
        && $outOfCatalogWins[403]['covered_references'] === 0
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. NOT_APPLICABLE: fuera del ámbito del indicador
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. NOT_APPLICABLE (sin insignia ni cifras) ---\n";

$inactiveIncident = $calculator->calculate(
    [$incident(501, 'RESOLVED', 2)],
    [501 => $requests([14 => 1])],
    [2 => [14 => 5]]
);
$assert(
    '5.1 Una avería no activa => NOT_APPLICABLE aunque tenga solicitudes y stock',
    $statusOf($inactiveIncident, 501) === VanStockReadinessCalculator::STATUS_NOT_APPLICABLE
        && $badgeOf($inactiveIncident, 501) === null
);

$withoutTechnician = $calculator->calculate(
    [$incident(502, 'ASSIGNED', null)],
    [502 => $requests([14 => 1])],
    [2 => [14 => 5]]
);
$assert(
    '5.2 Una avería sin técnico asignado => NOT_APPLICABLE',
    $statusOf($withoutTechnician, 502) === VanStockReadinessCalculator::STATUS_NOT_APPLICABLE
);

$withoutRequests = $calculator->calculate(
    [$incident(503, 'PENDING_PARTS', 2)],
    [],
    [2 => [14 => 5]]
);
$assert(
    '5.3 Una avería sin solicitudes PENDING (ni entrada en el mapa) => NOT_APPLICABLE',
    $statusOf($withoutRequests, 503) === VanStockReadinessCalculator::STATUS_NOT_APPLICABLE
);

$emptyRequests = $calculator->calculate(
    [$incident(504, 'PENDING_PARTS', 2)],
    [504 => $requests([], 0)],
    [2 => [14 => 5]]
);
$assert(
    '5.4 Un mapa de solicitudes vacío se trata igual que la ausencia de entrada',
    $statusOf($emptyRequests, 504) === VanStockReadinessCalculator::STATUS_NOT_APPLICABLE
);

$assert(
    '5.5 NOT_APPLICABLE no declara referencias ni bandera',
    $inactiveIncident[501]['covered_references'] === 0
        && $inactiveIncident[501]['total_catalog_references'] === 0
        && $inactiveIncident[501]['has_out_of_catalog_requests'] === false
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. Alcance de la agregación: sólo las averías activas del mismo técnico
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. Alcance de la agregación y pureza ---\n";

$scoped = $calculator->calculate(
    [
        $incident(601, 'PENDING_PARTS', 6),
        $incident(602, 'PENDING_PARTS', 9),
        $incident(603, 'RESOLVED', 6),
    ],
    [
        601 => $requests([14 => 1]),
        602 => $requests([14 => 9]),
        603 => $requests([14 => 50]),
    ],
    [6 => [14 => 1], 9 => [14 => 9]]
);
$assert(
    '6.1 La demanda de otro técnico no afecta al indicador (601 sigue READY)',
    $statusOf($scoped, 601) === VanStockReadinessCalculator::STATUS_READY_IN_VAN
);
$assert(
    '6.2 Una avería cerrada del mismo técnico no entra en la demanda agregada',
    $statusOf($scoped, 603) === VanStockReadinessCalculator::STATUS_NOT_APPLICABLE
        && $statusOf($scoped, 601) === VanStockReadinessCalculator::STATUS_READY_IN_VAN
);

$context = [
    [$incident(701, 'PENDING_PARTS', 2), $incident(702, 'PENDING_PARTS', 2)],
    [701 => $requests([14 => 1]), 702 => $requests([14 => 1])],
    [2 => [14 => 1]],
];
$contextBefore = $context;
$firstPass = $calculator->calculate($context[0], $context[1], $context[2]);
$secondPass = $calculator->calculate($context[0], $context[1], $context[2]);

$assert(
    '6.3 Dos llamadas con el mismo contexto devuelven exactamente el mismo indicador',
    $firstPass === $secondPass
);
$assert(
    '6.4 El calculador no muta las averías, solicitudes ni saldos recibidos',
    $context === $contextBefore
);
$assert(
    '6.5 El resultado cubre todas las averías recibidas, en su orden',
    array_keys($firstPass) === [701, 702]
);
$assert(
    '6.6 badgeFor() sólo devuelve etiqueta en los estados que afirman cobertura',
    VanStockReadinessCalculator::badgeFor(VanStockReadinessCalculator::STATUS_READY_IN_VAN) === '📦 Repuesto ya en furgoneta'
        && VanStockReadinessCalculator::badgeFor(VanStockReadinessCalculator::STATUS_SHARED_IN_VAN) === '📦 Repuesto en furgoneta (stock compartido)'
        && VanStockReadinessCalculator::badgeFor(VanStockReadinessCalculator::STATUS_NOT_READY) === null
        && VanStockReadinessCalculator::badgeFor(VanStockReadinessCalculator::STATUS_HAS_OUT_OF_CATALOG) === null
        && VanStockReadinessCalculator::badgeFor(VanStockReadinessCalculator::STATUS_NOT_APPLICABLE) === null
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. INDICADOR DE FURGONETA CERTIFICADO.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
