<?php

declare(strict_types=1);

/**
 * SiteAccessCodeGeneratorTest
 *
 * Contrato de la clave de centro (hallazgo S-4): formato tipográfico, alfabeto sin
 * caracteres ambiguos y normalización tolerante a separadores y mayúsculas.
 *
 * La clave se entrega impresa y se teclea en un móvil, así que el contrato visible
 * importa tanto como el criptográfico: `XXXXX-XXXXX`, nunca `0`/`O` ni `1`/`I`/`L`,
 * y una verificación que acepte lo mismo con o sin guion.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Service\SiteAccessCodeGenerator;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - SiteAccessCodeGenerator (hallazgo S-4)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $title, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$title}\n";
        return;
    }
    echo "  [FAIL] {$title}\n";
    if ($detail !== '') {
        echo "         Motivo: {$detail}\n";
    }
    $failures++;
};

// ─── 1. Formato y alfabeto ───────────────────────────────────────────────────
echo "--- 1. Formato y alfabeto de la clave ---\n";

$samples = [];
for ($i = 0; $i < 300; $i++) {
    $samples[] = SiteAccessCodeGenerator::generate();
}

$pattern = '/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{5}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{5}$/';
$malformed = array_values(array_filter($samples, static fn (string $code): bool => preg_match($pattern, $code) !== 1));

$assert(
    '1.1 Las 300 claves generadas tienen el formato XXXXX-XXXXX del alfabeto seguro',
    $malformed === [],
    'malformadas: ' . implode(', ', array_slice($malformed, 0, 3))
);

$ambiguous = [];
foreach ($samples as $code) {
    if (preg_match('/[01OIL]/', $code) === 1) {
        $ambiguous[] = $code;
    }
}
$assert(
    '1.2 Ninguna clave contiene caracteres ambiguos al teclear (0/O, 1/I/L)',
    $ambiguous === [],
    'ambiguas: ' . implode(', ', array_slice($ambiguous, 0, 3))
);

$assert(
    '1.3 Las 300 claves generadas son distintas (espacio de 31^10, sin colisiones prácticas)',
    count(array_unique($samples)) === count($samples),
    'distintas: ' . count(array_unique($samples))
);

// ─── 2. Normalización de lo que teclea el responsable ────────────────────────
echo "\n--- 2. Normalización tolerante ---\n";

$assert(
    '2.1 La normalización elimina guiones, espacios y pasa a mayúsculas',
    SiteAccessCodeGenerator::normalize(' k7m4p-2qx9r ') === 'K7M4P2QX9R'
    && SiteAccessCodeGenerator::normalize('K7M4P 2QX9R') === 'K7M4P2QX9R'
    && SiteAccessCodeGenerator::normalize('k7m4p2qx9r') === 'K7M4P2QX9R'
);

$assert(
    '2.2 La normalización no altera una clave ya canónica',
    SiteAccessCodeGenerator::normalize('K7M4P-2QX9R') === 'K7M4P2QX9R'
);

// ─── 3. Validación estructural ──────────────────────────────────────────────
echo "\n--- 3. Validación estructural ---\n";

$assert(
    '3.1 Una clave canónica con o sin guion es válida',
    SiteAccessCodeGenerator::isValid('K7M4P-2QX9R')
    && SiteAccessCodeGenerator::isValid(' K7M4P2QX9R ')
);

$assert(
    '3.2 Una clave corta, larga o con caracteres ambiguos o ajenos no es válida',
    !SiteAccessCodeGenerator::isValid('K7M4P-2QX9')
    && !SiteAccessCodeGenerator::isValid('K7M4P-2QX9RR')
    && !SiteAccessCodeGenerator::isValid('K7M4O-2QX9R')
    && !SiteAccessCodeGenerator::isValid('K7M4P-2QX9!')
    && !SiteAccessCodeGenerator::isValid('')
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONTRATO DE LA CLAVE DE CENTRO VERIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}
echo " RESULTADO: CONTRATO DE LA CLAVE DE CENTRO INCUMPLIDO.\n";
echo "======================================================================\n";
exit(1);
