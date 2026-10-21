<?php

declare(strict_types=1);

/**
 * VanStockPrivacyTest · T-VAN-07
 *
 * Certifica la frontera de privacidad del Módulo 18 (Art. V.4, RF-06): el stock de la furgoneta es
 * un dato interno de operación y la Sede no lo ve por ninguna vía.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **El saneador de sede borra el stock, también anidado.** Las claves del módulo
 *      (`quantity_available`, `min_threshold`, `is_below_threshold`, `is_negative`, `van_stock` y
 *      sus variantes) desaparecen de cualquier proyección que pase por el embudo de la Sede, y el
 *      filtro por nombre alcanza también las claves compuestas —una ampliación futura del
 *      expediente no puede abrir la fuga por olvidar la lista exacta—.
 *   2. **La Sede no tiene ruta al stock.** El router expone el inventario solo en las superficies
 *      de técnico y coordinación; no existe ningún endpoint bajo `/api/location/` que lo sirva.
 *   3. **Las dos proyecciones del DTO respetan el mínimo privilegio.** La del técnico no lleva
 *      identidad de técnico (la furgoneta es la suya) y ninguna de las dos lleva coste alguno: el
 *      coste del repuesto vive congelado en la línea de la avería (RD-05).
 *   4. **El valor por defecto del DTO es la proyección más restrictiva.** Un `json_encode` directo
 *      —por ejemplo, en un endpoint futuro que olvide elegir proyección— no puede filtrar más de
 *      lo que el técnico ve.
 *
 * Esta suite es hermética: no toca el motor ni la base de datos.
 *
 * Uso: php tests/unit/VanStockPrivacyTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\VanStockEntryDto;
use VendGuard\Presentation\Controller\LocationPortalController;

$baseDir = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($ok) {
        echo "  [PASS] {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";

        return;
    }
    $failures++;
    echo "  [FAIL] {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
};

echo "======================================================================\n";
echo " VENDGUARD · T-VAN-07 · Privacidad del stock de furgonetas (Módulo 18)\n";
echo "======================================================================\n";

// =============================================================================
// 1. Saneador de la Sede: el stock no sale por el embudo
// =============================================================================
echo "\n--- 1. Saneador de la Sede ---\n";

$sanitizer = (new ReflectionClass(LocationPortalController::class))->newInstanceWithoutConstructor();

$sitePayload = [
    'ticket_code' => 'INC-2026-00042',
    'status' => 'IN_PROGRESS',
    'technician_name' => 'Técnico de Ruta',
    'van_stock' => [
        'technician_id' => 7,
        'quantity_available' => 2,
        'min_threshold' => 3,
    ],
    'technician_van_stocks' => null,
    'quantity_available' => 2,
    'min_threshold' => 3,
    'is_below_threshold' => true,
    'is_negative' => false,
];

$sanitized = $sanitizer->sanitizeIncidentForSite($sitePayload) ?? [];

$stockKeys = ['van_stock', 'technician_van_stocks', 'quantity_available', 'min_threshold', 'is_below_threshold', 'is_negative'];
$leakedExact = array_values(array_filter($stockKeys, static fn (string $key): bool => array_key_exists($key, $sanitized)));
$assert(
    '1.1 El saneador elimina las claves exactas del stock',
    $leakedExact === [],
    implode(',', $leakedExact)
);

$assert(
    '1.2 El expediente de la sede conserva lo que sí le corresponde',
    ($sanitized['ticket_code'] ?? null) === 'INC-2026-00042'
        && ($sanitized['status'] ?? null) === 'IN_PROGRESS'
);

$nestedPayload = [
    'ticket_code' => 'INC-2026-00043',
    'machine' => [
        'code' => 'MAQ-MAD-001',
        'tupla_van_stock' => ['quantity_available' => 1],
    ],
    'summary' => [
        'stock_quantity_available' => 4,
        'stock_min_threshold' => 2,
        'quantities_used' => 4,
    ],
];

$nestedSanitized = $sanitizer->sanitizeIncidentForSite($nestedPayload) ?? [];
$machineBlock = $nestedSanitized['machine'] ?? [];
$summaryBlock = $nestedSanitized['summary'] ?? [];
$assert(
    '1.3 El filtro por nombre alcanza las claves compuestas y anidadas',
    !array_key_exists('tupla_van_stock', $machineBlock)
        && !array_key_exists('stock_quantity_available', $summaryBlock)
        && !array_key_exists('stock_min_threshold', $summaryBlock)
        && ($machineBlock['code'] ?? null) === 'MAQ-MAD-001'
        && ($summaryBlock['quantities_used'] ?? null) === 4,
    json_encode($nestedSanitized)
);

// =============================================================================
// 2. La Sede no tiene ruta al stock
// =============================================================================
echo "\n--- 2. Superficie de la Sede ---\n";

$routerSource = (string)file_get_contents($baseDir . '/src/Presentation/Routing/AppRouter.php');
$assert(
    '2.1 El inventario del técnico y el de coordinación están enrutados',
    str_contains($routerSource, "'/api/technician/van-stock'")
        && str_contains($routerSource, "'/api/coordinator/van-stocks'")
);
$assert(
    '2.2 No existe ningún endpoint de stock bajo la superficie de la Sede',
    preg_match("#'/api/location/[^']*van-stock#", $routerSource) !== 1
);

// =============================================================================
// 3. Proyecciones del DTO: mínimo privilegio y cero coste
// =============================================================================
echo "\n--- 3. Proyecciones del DTO ---\n";

$entry = new VanStockEntryDto(
    sparePartId: 1,
    partCode: 'VALV-ULKA-01',
    name: 'Electroválvula 24V Ulka',
    category: 'HYDRAULIC',
    quantityAvailable: 2,
    minThreshold: 3,
    technicianId: 7,
    technicianName: 'Carlos Rodríguez',
    operatorCode: 'OP-02'
);

$technicianProjection = $entry->toTechnicianArray();
$coordinatorProjection = $entry->toCoordinatorArray();

$assert(
    '3.1 La proyección del técnico no lleva identidad de técnico ni marcas internas',
    !array_key_exists('technician_id', $technicianProjection)
        && !array_key_exists('technician_name', $technicianProjection)
        && !array_key_exists('operator_code', $technicianProjection)
        && !array_key_exists('is_negative', $technicianProjection)
        && $technicianProjection['is_below_threshold'] === true,
    json_encode($technicianProjection)
);

$costPattern = '/cost|price|importe/i';
$technicianCostKeys = array_values(array_filter(array_keys($technicianProjection), static fn ($key): bool => preg_match($costPattern, (string)$key) === 1));
$coordinatorCostKeys = array_values(array_filter(array_keys($coordinatorProjection), static fn ($key): bool => preg_match($costPattern, (string)$key) === 1));
$assert(
    '3.2 Ninguna de las dos proyecciones lleva coste (Art. V.4, RD-05)',
    $technicianCostKeys === [] && $coordinatorCostKeys === []
);

$assert(
    '3.3 La proyección del coordinador sí identifica la furgoneta y marca el descuadre',
    $coordinatorProjection['technician_id'] === 7
        && $coordinatorProjection['technician_name'] === 'Carlos Rodríguez'
        && $coordinatorProjection['operator_code'] === 'OP-02'
        && $coordinatorProjection['is_negative'] === false
);

$assert(
    '3.4 El valor por defecto del DTO es la proyección más restrictiva',
    $entry->jsonSerialize() === $technicianProjection
);

// =============================================================================
// Resumen de Ejecución
// =============================================================================
echo "\n==========================================================================\n";
echo " RESUMEN: {$assertions} aserciones evaluadas. ";
if ($failures === 0) {
    echo "TODAS LAS PRUEBAS PASARON (100% OK).\n";
} else {
    echo "{$failures} FALLOS DETECTADOS.\n";
}
echo "==========================================================================\n";

exit($failures === 0 ? 0 : 1);
