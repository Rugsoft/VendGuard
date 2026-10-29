<?php

declare(strict_types=1);

/**
 * VendGuard - Cerrojo de sincronización con dos pestañas reales (H5)
 *
 * Los dobles de `navigator.locks` demuestran la lógica, no que el navegador
 * impida de verdad que dos pestañas vacíen la misma cola. Esta suite abre dos
 * pestañas de Chromium sobre el mismo perfil y el mismo origen, comparte
 * `IndexedDB` entre ambas y arranca el vaciado a la vez; el servidor de pruebas
 * cuenta los envíos en vuelo y los identificadores que llegan.
 *
 * La suite no puede ser hermética: exige un navegador instalado y Node 22+ con
 * `WebSocket` global (protocolo DevTools sin dependencias npm). Por eso declara
 * `new_engine` —la batería global la enumera y la deriva— y sale con código 2,
 * nunca 0, cuando el entorno no puede medir. Un verde de esta suite significa
 * que el cerrojo se comprobó en un navegador real.
 *
 * Dogma Vanilla: PHP 8.2+ puro; el arnés del navegador es Node ESM nativo.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

/** Marca de categoría: la batería hermética no ejecuta esta suite (necesita navegador). */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

$projectRoot = dirname(__DIR__, 2);
$runner = $projectRoot . '/tests/Support/OfflineSyncLockBrowserRunner.mjs';

echo "======================================================================\n";
echo " VENDGUARD · Cerrojo de sincronización con dos pestañas reales (H5)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n\n";

if (!is_file($runner)) {
    fwrite(STDERR, "  [NO EJECUTABLE] Falta el arnés {$runner}.\n");
    exit(2);
}

$nodeBinary = trim((string)shell_exec('node --version 2>&1'));
if ($nodeBinary === '' || !str_starts_with($nodeBinary, 'v')) {
    fwrite(STDERR, "  [NO EJECUTABLE] No hay Node.js disponible para hablar con DevTools.\n");
    exit(2);
}

echo "  · Node: {$nodeBinary}\n\n";

$command = 'node ' . escapeshellarg($runner);
passthru($command, $exitCode);

if ($exitCode === 0) {
    exit(0);
}

if ($exitCode === 2) {
    // La propia suite ya explicó el motivo con la marca [NO EJECUTABLE]; el canal
    // la lista aparte y no la cuenta como fallo.
    exit(2);
}

exit(1);
