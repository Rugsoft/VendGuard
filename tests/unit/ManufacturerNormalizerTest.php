<?php

declare(strict_types=1);

/**
 * ManufacturerNormalizerTest · T-TAXONOMY-04
 *
 * Suite unitaria del extractor determinista de fabricante del Motor Pareto
 * (Módulo 25, `QA 4`). El parque real escribe el mismo fabricante de formas
 * incompatibles (`NECTA KIKKO MAX`, `  necta   krea `, `Necta Samba Combo`), y
 * una heurística distinta por consulta fragmentaría la misma marca en varias
 * filas del diagrama. Esta suite fija el contrato exacto:
 *
 *   1. `trim()` del modelo y primer token antes de cualquier secuencia de
 *      espacios; una sola palabra se conserva entera.
 *   2. Formato Título sobre UTF-8, sin importar cómo viniera escrito.
 *   3. `'Sin Marca Especificada'` para nulo o texto en blanco.
 *   4. El filtro de fabricante es insensible a mayúsculas y a espacios
 *      sobrantes, y un filtro vacío no filtra (`QA 4`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Service\ManufacturerNormalizer;

echo "======================================================================\n";
echo " VENDGUARD · Suite unitaria · ManufacturerNormalizerTest (T-TAXONOMY-04)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

/**
 * @param array<string, string> $cases Modelo de entrada => fabricante esperado.
 */
$assertExtraction = static function (string $caseTitle, array $cases) use ($assert): void {
    foreach ($cases as $model => $expected) {
        $actual = ManufacturerNormalizer::extractManufacturer($model);
        $assert(
            "{$caseTitle} · «{$model}» → «{$expected}»",
            $actual === $expected,
            "se obtuvo «{$actual}»"
        );
    }
};

// ─────────────────────────────────────────────────────────────────────
// 1. Extracción determinista en Formato Título
// ─────────────────────────────────────────────────────────────────────
echo "--- 1. Extracción del primer token en Formato Título (QA 4) ---\n";

$assertExtraction('1.1 Necta se normaliza siempre igual', [
    'Necta Kikko Max' => 'Necta',
    'NECTA SAMBA' => 'Necta',
    '  necta   krea ' => 'Necta',
]);

$assertExtraction('1.2 Fabricantes multi-palabra', [
    'Jofemar Vision ES Plus' => 'Jofemar',
    'Sanden Vendo G-Drink' => 'Sanden',
    'Bianchi Gaia Espresso' => 'Bianchi',
    'Azkoyen Palma+' => 'Azkoyen',
    'Fas Fast 900' => 'Fas',
]);

$assertExtraction('1.3 Modelos de una sola palabra', [
    'Fasel' => 'Fasel',
    'Saeco' => 'Saeco',
    'NECTA' => 'Necta',
]);

$assertExtraction('1.4 Separadores distintos del espacio simple', [
    "Necta\tKikko" => 'Necta',
    "Necta\nSamba" => 'Necta',
    'Necta  Kikko  Max' => 'Necta',
]);

$assert(
    '1.5 La extracción es idempotente sobre su propia salida',
    ManufacturerNormalizer::extractManufacturer(
        ManufacturerNormalizer::extractManufacturer('NECTA KIKKO MAX')
    ) === 'Necta'
);

// ─────────────────────────────────────────────────────────────────────
// 2. Ausencia de modelo
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 2. Ausencia de marca declarada ---\n";

$assert(
    '2.1 Un modelo nulo devuelve la etiqueta canónica',
    ManufacturerNormalizer::extractManufacturer(null) === ManufacturerNormalizer::UNKNOWN_MANUFACTURER
);
$assert(
    '2.2 Un modelo vacío o en blanco devuelve la etiqueta canónica',
    ManufacturerNormalizer::extractManufacturer('') === 'Sin Marca Especificada'
    && ManufacturerNormalizer::extractManufacturer('   ') === 'Sin Marca Especificada'
    && ManufacturerNormalizer::extractManufacturer("\t\n ") === 'Sin Marca Especificada'
);

// ─────────────────────────────────────────────────────────────────────
// 3. Filtro por fabricante
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 3. Filtro insensible a mayúsculas ---\n";

$assert(
    '3.1 Un filtro vacío o nulo no filtra',
    ManufacturerNormalizer::matchesFilter('Necta Kikko Max', null) === true
    && ManufacturerNormalizer::matchesFilter('Necta Kikko Max', '') === true
    && ManufacturerNormalizer::matchesFilter('Necta Kikko Max', '   ') === true
);
$assert(
    '3.2 El filtro compara el fabricante normalizado',
    ManufacturerNormalizer::matchesFilter('NECTA KIKKO MAX', 'necta') === true
    && ManufacturerNormalizer::matchesFilter('  necta   krea ', ' NECTA ') === true
    && ManufacturerNormalizer::matchesFilter('Jofemar Vision ES Plus', 'JOFEMAR') === true
);
$assert(
    '3.3 Un fabricante distinto no pasa el filtro',
    ManufacturerNormalizer::matchesFilter('Necta Kikko Max', 'Jofemar') === false
    && ManufacturerNormalizer::matchesFilter(null, 'Necta') === false
    && ManufacturerNormalizer::matchesFilter('   ', 'Necta') === false
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-TAXONOMY-04 (normalizador) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
