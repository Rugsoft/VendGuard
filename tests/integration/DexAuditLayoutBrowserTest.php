<?php

declare(strict_types=1);

/**
 * VendGuard - Layout de la consola EVA-DTS `.dex` (M39)
 *
 * Monta el componente real `CoordinatorDexAuditsSubTab` con los estilos de
 * producción y mide en un Chromium real:
 *   - la consola a 360 x 800 con sus cuatro KPIs, filtros, carga manual y tabla;
 *   - el detalle financiero con selecciones, eventos y cruces M34/M35;
 *   - el modal de anulación con motivo largo y acción alcanzable;
 *   - la consola a 1440 px con la tabla ancha y el detalle limitado a 720 px.
 *
 * La suite no puede ser hermética: exige un navegador instalado y Node 22+ con
 * `WebSocket` global (protocolo DevTools sin dependencias npm). Por eso declara
 * `new_engine` y sale con código 2, nunca 0, cuando el entorno no puede medir.
 * Un verde significa que el layout se comprobó en un navegador real.
 *
 * Dogma Vanilla: PHP 8.2+ puro; el arnés del navegador es Node ESM nativo.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

/** Marca de categoría: la batería hermética no ejecuta esta suite (necesita navegador). */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

$projectRoot = dirname(__DIR__, 2);
$runner = $projectRoot . '/tests/Support/DexAuditLayoutBrowserRunner.mjs';

echo "======================================================================\n";
echo " VENDGUARD · Layout de la consola EVA-DTS .dex (M39)\n";
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
    exit(2);
}

exit(1);
