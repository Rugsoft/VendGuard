<?php

declare(strict_types=1);

/**
 * TechnicianAssignmentSuggesterVanStockTest · T-RESTOCK-06
 *
 * Certifica el cruce informativo de stock de furgoneta en el recomendador de
 * triaje (Módulo 23, RF-06, `D2`, `C1`, `C5`, `QA 2`) sin base de datos ni reloj.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Nivel 1 · solicitudes explícitas (`EXPLICIT_REQUESTS`).** Cobertura
 *      completa, parcial y nula, la degradación a parcial cuando además hay una
 *      pieza fuera de catálogo (`QA 2`), el caso de solicitud únicamente fuera de
 *      catálogo (no aplicable, sin insignia) y el saldo negativo contado como cero.
 *      Las etiquetas se comparan literalmente porque son el texto que ve
 *      Coordinación en la tarjeta.
 *   2. **Nivel 2 · compatibilidad por modelo y categoría (`CATEGORY_COMPATIBILITY`).**
 *      El mapeo de las cinco categorías de avería al vocabulario del catálogo
 *      —incluida `OTHER`, que no filtra—, el universo vacío como no aplicable
 *      (`C1`) y los tres grados de cobertura de la familia.
 *   3. **Invariante del Art. II (`D2`).** El cruce **no** altera `total_score`, ni
 *      `score_breakdown`, ni el orden, ni la recomendación, ni el escalado
 *      sanitario: la guardia 24/7 sigue encabezando una emergencia de frío aunque
 *      su furgoneta no lleve ninguna pieza y otro candidato las lleve todas.
 *   4. **Retrocompatibilidad y validación.** Una foto sin contexto de furgoneta
 *      conserva exactamente la proyección anterior, sin el bloque; un contexto
 *      ilegible falla en voz alta en lugar de pintar una cobertura inventada.
 *
 * El mapeo de categorías vive en el repositorio, que lo resuelve en SQL: aquí se
 * lee su tabla cerrada por reflexión para certificar que es la que el evaluador
 * recibe, sin abrir una conexión.
 *
 * Uso:
 *   php tests/unit/TechnicianAssignmentSuggesterVanStockTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\SuggestedTechnicianDto;
use VendGuard\Application\Service\GeoDistanceService;
use VendGuard\Application\Service\TechnicianAssignmentSuggester;
use VendGuard\Infrastructure\Repository\PdoTechnicianSuggestionRepository;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-RESTOCK-06 · Cruce de stock de furgoneta en triaje\n";
echo "======================================================================\n";

$location = [
    'id' => 4,
    'site_code' => 'SEDE-BCN-01',
    'name' => 'Hospital del Mar',
    'postal_code' => '08005',
    'zone_id' => 1,
    'zone_name' => 'Barcelona Litoral y Norte',
    'latitude' => 41.3874,
    'longitude' => 2.1701,
];

$base = ['base_latitude' => 41.3800, 'base_longitude' => 2.1600];

$incident = static fn (array $overrides = []): array => array_merge([
    'id' => 105,
    'ticket_code' => 'INC-20261026-0105',
    'priority' => 'HIGH',
    'machine_type' => 'COLD_DRINKS',
    'machine_model' => 'Bianchi Gaia Espresso',
    'category' => 'TEMPERATURE_COLD',
    'location' => $location,
], $overrides);

$technician = static fn (int $id, array $overrides = []): array => array_merge([
    'technician_id' => $id,
    'name' => 'Técnico ' . $id,
    'operator_code' => 'OP-' . sprintf('%03d', $id),
    'is_on_call' => false,
    'is_active' => true,
    'shift_status' => 'ON_SHIFT',
    'affinity' => 'NONE',
    'active_incidents' => 0,
    'pending_info_incidents' => 0,
    'preventive_orders_today' => 0,
    'has_live_stop_at_target_location' => false,
    'live_stops_in_zone_other_locations' => 0,
    'last_assigned_at' => null,
    'in_progress_location' => null,
    'last_completed_location' => null,
], $overrides);

/**
 * Añade el contexto de furgoneta del Módulo 23 a una foto de avería.
 *
 * @param array<int, int> $pendingCatalog
 * @param list<int> $compatiblePartIds
 */
$withVanStockContext = static function (
    array $incident,
    array $pendingCatalog,
    bool $hasOutOfCatalog,
    array $compatiblePartIds
): array {
    $incident['pending_catalog_requests'] = $pendingCatalog;
    $incident['has_pending_out_of_catalog_requests'] = $hasOutOfCatalog;
    $incident['compatible_parts'] = array_map(
        static fn (int $sparePartId): array => [
            'spare_part_id' => $sparePartId,
            'part_code' => 'REF-' . $sparePartId,
            'part_name' => 'Pieza ' . $sparePartId,
            'category' => 'OTHER',
        ],
        $compatiblePartIds
    );

    return $incident;
};

$suggester = new TechnicianAssignmentSuggester(new GeoDistanceService);

$rowFor = static function (array $ranked, int $technicianId): ?array {
    foreach ($ranked as $row) {
        if (($row['technician_id'] ?? null) === $technicianId) {
            return $row;
        }
    }

    return null;
};

$order = static fn (array $ranked): array => array_column($ranked, 'technician_id');

// ─────────────────────────────────────────────────────────────────────────────
// 1. Nivel 1: solicitudes explícitas pendientes
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. Nivel 1 · EXPLICIT_REQUESTS ---\n";

$full = $suggester->getSuggestionsForIncident(
    $withVanStockContext($incident(), [14 => 2, 8 => 1], false, [14, 8, 21]),
    [$technician(2, ['van_stock' => [14 => 5, 8 => 1]])],
    $base
);
$fullMatch = $rowFor($full->rankedTechnicians, 2)['van_stock_match'] ?? null;
$assert(
    '1.1 Las dos referencias cubiertas => FULL_MATCH con la etiqueta literal del contrato',
    $fullMatch !== null
        && $fullMatch['status'] === 'FULL_MATCH'
        && $fullMatch['evaluation_level'] === 'EXPLICIT_REQUESTS'
        && $fullMatch['badge'] === SuggestedTechnicianDto::BADGE_STOCK_COMPATIBLE
        && $fullMatch['matched_references'] === 2
        && $fullMatch['total_references'] === 2
        && $fullMatch['has_out_of_catalog_requests'] === false
        && $fullMatch['label'] === 'Repuesto solicitado en furgoneta (2/2 ref.)',
    json_encode($fullMatch, JSON_UNESCAPED_UNICODE)
);
$assert(
    '1.2 El bloque expone exactamente las siete claves del contrato §5.12',
    $fullMatch !== null && array_keys($fullMatch) === [
        'status',
        'evaluation_level',
        'badge',
        'matched_references',
        'total_references',
        'has_out_of_catalog_requests',
        'label',
    ],
    json_encode(array_keys($fullMatch ?? []))
);
$assert(
    '1.3 La insignia de cobertura se suma a las del ranking sin desplazar las existentes',
    in_array(SuggestedTechnicianDto::BADGE_STOCK_COMPATIBLE, $full->rankedTechnicians[0]['badges'], true)
        && $full->rankedTechnicians[0]['badges'][0] === 'RECOMENDADO'
);

$partial = $suggester->getSuggestionsForIncident(
    $withVanStockContext($incident(), [14 => 2, 8 => 3], false, [14, 8]),
    [$technician(2, ['van_stock' => [14 => 5, 8 => 2]])],
    $base
);
$partialMatch = $rowFor($partial->rankedTechnicians, 2)['van_stock_match'] ?? null;
$assert(
    '1.4 Una referencia sin alcanzar => PARTIAL_MATCH (1/2) con STOCK_PARCIAL',
    $partialMatch !== null
        && $partialMatch['status'] === 'PARTIAL_MATCH'
        && $partialMatch['badge'] === SuggestedTechnicianDto::BADGE_STOCK_PARCIAL
        && $partialMatch['matched_references'] === 1
        && $partialMatch['total_references'] === 2
        && $partialMatch['label'] === 'Repuesto solicitado parcial (1/2 ref.)',
    json_encode($partialMatch, JSON_UNESCAPED_UNICODE)
);

$noStock = $suggester->getSuggestionsForIncident(
    $withVanStockContext($incident(), [14 => 1], false, [14]),
    [$technician(2, ['van_stock' => []])],
    $base
);
$noStockMatch = $rowFor($noStock->rankedTechnicians, 2)['van_stock_match'] ?? null;
$assert(
    '1.5 Ninguna unidad de la referencia pedida => NO_STOCK con SIN_STOCK_COMPATIBLE',
    $noStockMatch !== null
        && $noStockMatch['status'] === 'NO_STOCK'
        && $noStockMatch['badge'] === SuggestedTechnicianDto::BADGE_SIN_STOCK_COMPATIBLE
        && $noStockMatch['matched_references'] === 0
        && $noStockMatch['label'] === 'Sin repuesto solicitado en furgoneta (0/1 ref.)',
    json_encode($noStockMatch, JSON_UNESCAPED_UNICODE)
);

$zeroCoveredPositiveStock = $suggester->getSuggestionsForIncident(
    $withVanStockContext($incident(), [14 => 5, 8 => 3], false, [14, 8]),
    [$technician(2, ['van_stock' => [14 => 2, 8 => 0]])],
    $base
);
$zeroCoveredMatch = $rowFor($zeroCoveredPositiveStock->rankedTechnicians, 2)['van_stock_match'] ?? null;
$assert(
    '1.6b M=0 pero con saldo positivo en alguna referencia => PARTIAL_MATCH con STOCK_PARCIAL (EARS 05.2)',
    $zeroCoveredMatch !== null
        && $zeroCoveredMatch['status'] === 'PARTIAL_MATCH'
        && $zeroCoveredMatch['badge'] === SuggestedTechnicianDto::BADGE_STOCK_PARCIAL
        && $zeroCoveredMatch['matched_references'] === 0
        && $zeroCoveredMatch['total_references'] === 2
        && $zeroCoveredMatch['label'] === 'Repuesto solicitado parcial (0/2 ref.)'
);

$mixedOutOfCatalog = $suggester->getSuggestionsForIncident(
    $withVanStockContext($incident(), [14 => 1, 8 => 1], true, [14, 8, 21]),
    [$technician(2, ['van_stock' => [14 => 9, 8 => 9]])],
    $base
);
$mixedMatch = $rowFor($mixedOutOfCatalog->rankedTechnicians, 2)['van_stock_match'] ?? null;
$assert(
    '1.7 Cobertura completa + pieza fuera de catálogo => PARTIAL_MATCH degradado (QA 2)',
    $mixedMatch !== null
        && $mixedMatch['status'] === 'PARTIAL_MATCH'
        && $mixedMatch['badge'] === SuggestedTechnicianDto::BADGE_STOCK_PARCIAL
        && $mixedMatch['matched_references'] === 2
        && $mixedMatch['total_references'] === 2
        && $mixedMatch['has_out_of_catalog_requests'] === true
        && $mixedMatch['label'] === 'Repuesto solicitado parcial (2/2 ref. + pieza fuera de catálogo)',
    json_encode($mixedMatch, JSON_UNESCAPED_UNICODE)
);

$partialMixed = $suggester->getSuggestionsForIncident(
    $withVanStockContext($incident(), [14 => 1, 8 => 1], true, [14, 8]),
    [$technician(2, ['van_stock' => [14 => 9]])],
    $base
);
$assert(
    '1.8 Cobertura parcial + fuera de catálogo conserva el sufijo en la etiqueta',
    ($rowFor($partialMixed->rankedTechnicians, 2)['van_stock_match']['label'] ?? null)
        === 'Repuesto solicitado parcial (1/2 ref. + pieza fuera de catálogo)'
);

$noneMixed = $suggester->getSuggestionsForIncident(
    $withVanStockContext($incident(), [14 => 2], true, [14]),
    [$technician(2, ['van_stock' => [14 => 0]])],
    $base
);
$noneMixedMatch = $rowFor($noneMixed->rankedTechnicians, 2)['van_stock_match'] ?? null;
$assert(
    '1.9 Sin catálogo cubierto + fuera de catálogo => NO_STOCK con el sufijo',
    $noneMixedMatch !== null
        && $noneMixedMatch['status'] === 'NO_STOCK'
        && $noneMixedMatch['label'] === 'Sin repuesto solicitado en furgoneta (0/1 ref. + pieza fuera de catálogo)'
        && $noneMixedMatch['has_out_of_catalog_requests'] === true
);

$onlyOutOfCatalog = $suggester->getSuggestionsForIncident(
    $withVanStockContext($incident(), [], true, [14, 8]),
    [$technician(2, ['van_stock' => [14 => 9]])],
    $base
);
$onlyOutOfCatalogMatch = $rowFor($onlyOutOfCatalog->rankedTechnicians, 2)['van_stock_match'] ?? null;
$assert(
    '1.10 Sólo solicitud fuera de catálogo => NOT_APPLICABLE sin insignia y con su aviso (QA 2)',
    $onlyOutOfCatalogMatch !== null
        && $onlyOutOfCatalogMatch['status'] === 'NOT_APPLICABLE'
        && $onlyOutOfCatalogMatch['evaluation_level'] === 'EXPLICIT_REQUESTS'
        && $onlyOutOfCatalogMatch['badge'] === null
        && $onlyOutOfCatalogMatch['matched_references'] === 0
        && $onlyOutOfCatalogMatch['total_references'] === 0
        && $onlyOutOfCatalogMatch['has_out_of_catalog_requests'] === true
        && $onlyOutOfCatalogMatch['label'] === 'Solicitud fuera de catálogo (no verificable en furgoneta)'
);
$assert(
    '1.11 Un estado sin insignia no añade ninguna marca de stock al ranking',
    !in_array(SuggestedTechnicianDto::BADGE_STOCK_COMPATIBLE, $onlyOutOfCatalog->rankedTechnicians[0]['badges'], true)
        && !in_array(SuggestedTechnicianDto::BADGE_STOCK_PARCIAL, $onlyOutOfCatalog->rankedTechnicians[0]['badges'], true)
        && !in_array(SuggestedTechnicianDto::BADGE_SIN_STOCK_COMPATIBLE, $onlyOutOfCatalog->rankedTechnicians[0]['badges'], true)
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Nivel 2: compatibilidad por modelo de máquina y categoría del fallo
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Nivel 2 · CATEGORY_COMPATIBILITY ---\n";

// El mapeo vive en el repositorio porque se resuelve en SQL; se lee su tabla
// cerrada sin abrir conexión para certificar que es la que recibe el evaluador.
$categoryFor = new ReflectionMethod(PdoTechnicianSuggestionRepository::class, 'sparePartCategoryFor');
$expectedMapping = [
    'TEMPERATURE_COLD' => 'THERMAL',
    'PAYMENT_SYSTEM' => 'PAYMENT_SYSTEM',
    'PRODUCT_JAM' => 'MECHANICAL',
    'ELECTRICAL_OFF' => 'ELECTRONIC',
    'OTHER' => null,
];

foreach ($expectedMapping as $incidentCategory => $expectedPartCategory) {
    $assert(
        sprintf('2.1 La categoría %s se mapea a %s', $incidentCategory, $expectedPartCategory ?? 'cualquier categoría'),
        $categoryFor->invoke(null, $incidentCategory) === $expectedPartCategory
    );
}

$categories = [
    'TEMPERATURE_COLD' => [[14], [14 => 1], 'FULL_MATCH', 'Stock compatible (1/1 ref.)'],
    'PAYMENT_SYSTEM' => [[20], [20 => 0], 'NO_STOCK', 'Sin stock compatible (0/1 ref.)'],
    'PRODUCT_JAM' => [[21, 22], [21 => 1], 'PARTIAL_MATCH', 'Stock compatible parcial (1/2 ref.)'],
    'ELECTRICAL_OFF' => [[23, 24], [23 => 1, 24 => 1], 'FULL_MATCH', 'Stock compatible (2/2 ref.)'],
    'OTHER' => [[14, 20, 21], [], 'NO_STOCK', 'Sin stock compatible (0/3 ref.)'],
];

foreach ($categories as $incidentCategory => [$universe, $stock, $expectedStatus, $expectedLabel]) {
    $result = $suggester->getSuggestionsForIncident(
        $withVanStockContext($incident(['category' => $incidentCategory]), [], false, $universe),
        [$technician(2, ['van_stock' => $stock])],
        $base
    );
    $match = $rowFor($result->rankedTechnicians, 2)['van_stock_match'] ?? null;

    $assert(
        sprintf(
            '2.2 %s sin solicitudes evalúa el universo compatible (%d ref.) => %s',
            $incidentCategory,
            count($universe),
            $expectedStatus
        ),
        $match !== null
            && $match['status'] === $expectedStatus
            && $match['evaluation_level'] === 'CATEGORY_COMPATIBILITY'
            && $match['total_references'] === count($universe)
            && $match['has_out_of_catalog_requests'] === false
            && $match['label'] === $expectedLabel,
        json_encode($match, JSON_UNESCAPED_UNICODE)
    );
}

$emptyUniverse = $suggester->getSuggestionsForIncident(
    $withVanStockContext($incident(['machine_model' => 'Modelo sin compatibilidades']), [], false, []),
    [$technician(2, ['van_stock' => [14 => 9]])],
    $base
);
$emptyMatch = $rowFor($emptyUniverse->rankedTechnicians, 2)['van_stock_match'] ?? null;
$assert(
    '2.3 Universo compatible vacío => NOT_APPLICABLE con etiqueta neutra sin insignia (C1)',
    $emptyMatch !== null
        && $emptyMatch['status'] === 'NOT_APPLICABLE'
        && $emptyMatch['evaluation_level'] === 'CATEGORY_COMPATIBILITY'
        && $emptyMatch['badge'] === null
        && $emptyMatch['matched_references'] === 0
        && $emptyMatch['total_references'] === 0
        && $emptyMatch['label'] === 'ℹ️ Sin repuestos catalogados para este modelo/categoría'
);

$negativeUniverse = $suggester->getSuggestionsForIncident(
    $withVanStockContext($incident(['category' => 'OTHER']), [], false, [14, 8]),
    [$technician(2, ['van_stock' => [14 => -2, 8 => 1]])],
    $base
);
$assert(
    '2.4 Un saldo negativo no suma como referencia disponible en el nivel 2 (1/2)',
    ($rowFor($negativeUniverse->rankedTechnicians, 2)['van_stock_match']['status'] ?? null) === 'PARTIAL_MATCH'
        && ($rowFor($negativeUniverse->rankedTechnicians, 2)['van_stock_match']['matched_references'] ?? null) === 1
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Invariante del Art. II: el stock no puntúa ni reordena
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Art. II · puntuación, orden y escalado intactos (D2) ---\n";

$coldTechnicians = [
    $technician(1, [
        'affinity' => 'PRIMARY',
        'active_incidents' => 1,
        'has_live_stop_at_target_location' => true,
    ]),
    $technician(7, [
        'is_on_call' => true,
        'active_incidents' => 5,
    ]),
    $technician(2, [
        'affinity' => 'BACKUP',
        'active_incidents' => 1,
    ]),
];

$coldTechniciansBefore = $coldTechnicians;

$coldIncident = $incident(['priority' => 'CRITICAL']);
$coldEnrichedIncident = $withVanStockContext($coldIncident, [14 => 1], false, [14]);

$coldLegacy = $suggester->getSuggestionsForIncident($coldIncident, $coldTechnicians, $base);
$coldEnrichedTechnicians = $coldTechnicians;
// La guardia no lleva la pieza; el último candidato del ranking sí. Aun así, la
// guardia debe seguir encabezando el escalado sanitario.
$coldEnrichedTechnicians[0]['van_stock'] = [];
$coldEnrichedTechnicians[1]['van_stock'] = [];
$coldEnrichedTechnicians[2]['van_stock'] = [14 => 9];

$coldEnriched = $suggester->getSuggestionsForIncident($coldEnrichedIncident, $coldEnrichedTechnicians, $base);

$scores = static fn (array $ranked): array => array_map(
    static fn (array $row): array => [$row['technician_id'], $row['total_score'], $row['score_breakdown']],
    $ranked
);

$assert(
    '3.1 El orden de candidatos es idéntico con y sin stock de furgoneta',
    $order($coldLegacy->rankedTechnicians) === $order($coldEnriched->rankedTechnicians),
    json_encode([$order($coldLegacy->rankedTechnicians), $order($coldEnriched->rankedTechnicians)])
);
$assert(
    '3.2 total_score y score_breakdown de cada candidato no cambian con el stock',
    $scores($coldLegacy->rankedTechnicians) === $scores($coldEnriched->rankedTechnicians),
    json_encode($scores($coldEnriched->rankedTechnicians))
);
$assert(
    '3.3 El escalado sanitario del Art. II se mantiene y sigue encabezando la guardia 24/7',
    $coldLegacy->recommendation['is_cold_chain_escalation'] === true
        && $coldEnriched->recommendation['is_cold_chain_escalation'] === true
        && $coldEnriched->recommendation['top_technician_id'] === 7
        && $coldEnriched->rankedTechnicians[0]['technician_id'] === 7
);
$assert(
    '3.4 La guardia sin stock conserva la recomendación frente al candidato con stock completo',
    $coldEnriched->rankedTechnicians[0]['van_stock_match']['badge'] === SuggestedTechnicianDto::BADGE_SIN_STOCK_COMPATIBLE
        && $coldEnriched->rankedTechnicians[0]['is_top_suggestion'] === true
        && $rowFor($coldEnriched->rankedTechnicians, 2)['van_stock_match']['badge'] === SuggestedTechnicianDto::BADGE_STOCK_COMPATIBLE
        && $rowFor($coldEnriched->rankedTechnicians, 2)['is_top_suggestion'] === false
);
$assert(
    '3.5 La recomendación y su explicación son idénticas: el stock no se cuela en el relato',
    $coldLegacy->recommendation === $coldEnriched->recommendation
);

$ordinaryTechnicians = [
    $technician(3, ['affinity' => 'PRIMARY']),
    $technician(4, ['affinity' => 'BACKUP']),
];
$ordinaryEnrichedTechnicians = $ordinaryTechnicians;
$ordinaryEnrichedTechnicians[0]['van_stock'] = [];
$ordinaryEnrichedTechnicians[1]['van_stock'] = [14 => 9];

$ordinaryLegacy = $suggester->getSuggestionsForIncident($incident(), $ordinaryTechnicians, $base);
$ordinaryEnriched = $suggester->getSuggestionsForIncident(
    $withVanStockContext($incident(), [14 => 3], false, [14]),
    $ordinaryEnrichedTechnicians,
    $base
);

$assert(
    '3.6 En el caso ordinario el titular sin stock sigue por delante del suplente con stock',
    $order($ordinaryLegacy->rankedTechnicians) === [3, 4]
        && $order($ordinaryEnriched->rankedTechnicians) === [3, 4]
        && $ordinaryEnriched->rankedTechnicians[0]['total_score'] === $ordinaryLegacy->rankedTechnicians[0]['total_score']
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Retrocompatibilidad, determinismo y validación temprana
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Retrocompatibilidad, determinismo y validación ---\n";

$assert(
    '4.1 Una foto sin contexto de furgoneta conserva la proyección anterior, sin van_stock_match',
    !array_key_exists('van_stock_match', $ordinaryLegacy->rankedTechnicians[0])
        && !in_array(SuggestedTechnicianDto::BADGE_STOCK_COMPATIBLE, $ordinaryLegacy->rankedTechnicians[0]['badges'], true)
);
$assert(
    '4.2 Una foto con contexto añade el bloque a todos los candidatos',
    array_reduce(
        $coldEnriched->rankedTechnicians,
        static fn (bool $carry, array $row): bool => $carry && array_key_exists('van_stock_match', $row),
        true
    )
);
$assert(
    '4.3 Dos ejecuciones con la misma foto y el mismo stock devuelven lo mismo',
    $coldEnriched->toArray() === $suggester
        ->getSuggestionsForIncident($coldEnrichedIncident, $coldEnrichedTechnicians, $base)
        ->toArray()
);
$coldEnrichedPhotoBefore = $coldEnrichedTechnicians;
$suggester->getSuggestionsForIncident($coldEnrichedIncident, $coldEnrichedTechnicians, $base);
$assert(
    '4.4 El cálculo no muta la foto recibida',
    $coldTechnicians === $coldTechniciansBefore && $coldEnrichedTechnicians === $coldEnrichedPhotoBefore
);

$invalidCatalog = null;
try {
    $suggester->getSuggestionsForIncident(
        $incident(['pending_catalog_requests' => 'dos bombas']),
        [$technician(2)],
        $base
    );
} catch (InvalidArgumentException $exception) {
    $invalidCatalog = $exception->getMessage();
}
$assert(
    '4.5 Un mapa de solicitudes ilegible se rechaza en voz alta',
    $invalidCatalog !== null && str_contains($invalidCatalog, 'solicitudes pendientes'),
    $invalidCatalog ?? 'no se lanzó la excepción'
);

$invalidUniverse = null;
try {
    $suggester->getSuggestionsForIncident(
        $incident(['compatible_parts' => [['part_code' => 'SIN-ID']]]),
        [$technician(2)],
        $base
    );
} catch (InvalidArgumentException $exception) {
    $invalidUniverse = $exception->getMessage();
}
$assert(
    '4.6 Un universo compatible sin identificador se rechaza en voz alta',
    $invalidUniverse !== null && str_contains($invalidUniverse, 'referencia ilegible'),
    $invalidUniverse ?? 'no se lanzó la excepción'
);

$invalidStock = null;
try {
    $suggester->getSuggestionsForIncident(
        $withVanStockContext($incident(), [14 => 1], false, [14]),
        [$technician(2, ['van_stock' => 'mucho'])],
        $base
    );
} catch (InvalidArgumentException $exception) {
    $invalidStock = $exception->getMessage();
}
$assert(
    '4.7 Un saldo de furgoneta ilegible se rechaza en voz alta',
    $invalidStock !== null && str_contains($invalidStock, 'saldo de furgoneta'),
    $invalidStock ?? 'no se lanzó la excepción'
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CRUCE DE FURGONETA CERTIFICADO (T-RESTOCK-06).\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
