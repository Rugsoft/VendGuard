<?php

declare(strict_types=1);

/**
 * VendGuard - Guarda contra la deriva del recuento del canal `new_engine`
 *
 * El README del motor local anunciaba «las 14 suites de la categoría» cuando el
 * descubrimiento por marca ya encontraba 27. El número se escribía a mano una vez
 * y nadie volvía a contrastarlo, así que la certificación del canal se leía con
 * una cifra falsa.
 *
 * Esta guarda no mantiene otra lista: descubre las suites con la **misma** clase
 * que usa `tests/run_new_engine.php` (`NewEngineChannel`) y compara ese recuento
 * con el que declaran los documentos versionados. Si una suite cambia de
 * categoría, se añade una nueva o alguien reescribe el README, la batería falla
 * en lugar de publicar una cifra desfasada.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes y documentación en castellano.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/Support/NewEngineChannel.php';

$assertions = 0;
$failures = 0;

$assert = function (string $label, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$label}\n";
        return;
    }

    $failures++;
    echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
};

$integrationDir = $baseDir . '/tests/integration';
$documentedPath = 'deploy/local/postgres/README.md';
$documentedFile = $baseDir . '/' . $documentedPath;

// ─────────────────────────────────────────────────────────────────────────
// 1. El descubrimiento encuentra el canal y no pasa por vacío
// ─────────────────────────────────────────────────────────────────────────
$suites = NewEngineChannel::discover($integrationDir);
$suiteCount = count($suites);

// Suelo anti-vacuidad: si el patrón de la marca se rompe, `discover()` devuelve
// cero suites y la comparación posterior sería trivialmente cierta contra un
// README que también declarase cero. El canal tenía 27 suites al escribir esto.
$assert(
    '1.1 El descubrimiento encuentra las suites del canal (suelo de 20)',
    $suiteCount >= 20,
    "Descubiertas: {$suiteCount}"
);

$representativeSuites = ['OfflinePwaSyncApiTest.php', 'PostgresSchemaTest.php'];
$missingRepresentative = array_values(array_diff($representativeSuites, array_keys($suites)));
$assert(
    '1.2 El descubrimiento incluye las suites representativas del canal',
    $missingRepresentative === [],
    'No encontradas: ' . implode(', ', $missingRepresentative)
);

// ─────────────────────────────────────────────────────────────────────────
// 2. El README declara el recuento en la línea del comando
// ─────────────────────────────────────────────────────────────────────────
$readme = @file_get_contents($documentedFile);
$readme = $readme === false ? '' : $readme;

$anchorPattern = '/run_new_engine\.php\s*#\s+las\s+(\d+)\s+suites de la categor[íi]a/u';
$anchorMatches = [];
preg_match_all($anchorPattern, $readme, $anchorMatches, PREG_SET_ORDER);

$assert(
    '2.1 El README declara el recuento una sola vez, en la línea del comando',
    count($anchorMatches) === 1,
    'Coincidencias: ' . count($anchorMatches)
);

$documentedCount = $anchorMatches === [] ? -1 : (int)$anchorMatches[0][1];
$assert(
    '2.2 El recuento declarado coincide con las suites descubiertas',
    $documentedCount === $suiteCount,
    "README: {$documentedCount} · descubiertas: {$suiteCount}"
);

// ─────────────────────────────────────────────────────────────────────────
// 3. Ningún otro markdown versionado publica un recuento distinto
// ─────────────────────────────────────────────────────────────────────────
$trackedMarkdown = [];
$listing = shell_exec(sprintf('git -C %s ls-files -- "*.md" 2>&1', escapeshellarg($baseDir)));
if (is_string($listing)) {
    foreach (preg_split('/\r?\n/', trim($listing)) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && str_ends_with(strtolower($line), '.md')) {
            $trackedMarkdown[] = $line;
        }
    }
}

$assert(
    '3.1 Se pueden enumerar los markdown versionados',
    $trackedMarkdown !== [],
    'git no devolvió ningún documento versionado'
);

$declarations = 0;
$divergent = [];
foreach ($trackedMarkdown as $relativePath) {
    $content = @file_get_contents($baseDir . '/' . $relativePath);
    if ($content === false) {
        $divergent[] = "{$relativePath}: ilegible";
        continue;
    }

    preg_match_all('/las\s+(\d+)\s+suites de la categor[íi]a/u', $content, $found);
    foreach ($found[1] as $declared) {
        $declarations++;
        if ((int)$declared !== $suiteCount) {
            $divergent[] = "{$relativePath}: {$declared}";
        }
    }
}

$assert(
    '3.2 Ningún documento versionado declara un recuento distinto',
    $divergent === [],
    'Divergentes: ' . implode(', ', $divergent)
);

$assert(
    '3.3 La documentación publica el recuento al menos una vez',
    $declarations >= 1,
    'Ninguna frase «las N suites de la categoría» en los markdown versionados'
);

// ─────────────────────────────────────────────────────────────────────────
// 4. El runner y la guarda miden con la misma fuente de verdad
// ─────────────────────────────────────────────────────────────────────────
$runnerSource = (string)@file_get_contents($baseDir . '/tests/run_new_engine.php');
$assert(
    '4.1 El runner descubre las suites con la clase compartida',
    str_contains($runnerSource, 'NewEngineChannel::discover')
        && str_contains($runnerSource, "Support/NewEngineChannel.php"),
    'El runner no usa NewEngineChannel::discover()'
);

$assert(
    '4.2 El README remite a esta guarda como responsable del recuento',
    str_contains($readme, 'NewEngineChannelDocumentationTest'),
    'El README no nombra la guarda que mantiene el recuento'
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 90) . "\n";
echo " Documento auditado: {$documentedPath}\n";
echo " Suites descubiertas: {$suiteCount} · declaraciones documentadas: {$declarations}\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. RECUENTO DEL CANAL new_engine SIN DERIVA.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);
