<?php

declare(strict_types=1);

/**
 * VendGuard - Layout móvil 360 px de las superficies L1/SAT (H-36-04)
 *
 * Monta `RestockerRouteView` y `TechnicianRouteView` con los estilos de
 * producción y mide en un Chromium real, a 360 × 800, la bandeja de Avisos de
 * Nivel 1 y la tarjeta SAT de una parada escalada: documento, body, sección,
 * tarjeta, coincidencia en sede, Nota Interna de Taller, foto y acciones.
 *
 * La suite no puede ser hermética: exige un navegador instalado y Node 22+ con
 * `WebSocket` global (protocolo DevTools sin dependencias npm). Por eso declara
 * `new_engine` —la batería global la enumera y la deriva— y sale con código 2,
 * nunca 0, cuando el entorno no puede medir. Un verde de esta suite significa
 * que el layout se comprobó en un navegador real a 360 px.
 *
 * Dogma Vanilla: PHP 8.2+ puro; el arnés del navegador es Node ESM nativo.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

/** Marca de categoría: la batería hermética no ejecuta esta suite (necesita navegador). */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

$projectRoot = dirname(__DIR__, 2);
$runner = $projectRoot . '/tests/Support/RestockerSatLayoutBrowserRunner.mjs';

echo "======================================================================\n";
echo " VENDGUARD · Layout móvil 360 px de avisos L1 y ruta SAT (H-36-04)\n";
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
