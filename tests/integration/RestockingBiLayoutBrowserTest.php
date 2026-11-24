<?php

declare(strict_types=1);

/**
 * VendGuard - Layout real de la sub-pestaña M38 (T-RBI-07)
 *
 * Certifica en un Chromium real la sub-pestaña «📦 Métricas de Carga y
 * Reposición» montada desde `CoordinatorMetricsView` con los estilos de
 * producción:
 *
 *  - Documento `360 x 800` sin desbordamiento horizontal del documento ni del
 *    body, con `scrollWidth <= clientWidth` en cada superficie.
 *  - Cascada zona/sede que limpia `location_id` fuera de la demarcación.
 *  - Nueve tarjetas KPI y tres tablas con scroll propio e insignias «Baja» y
 *    «Planograma sobredimensionado».
 *  - Dos `<canvas>` Chart.js reales, conmutadores €/Unidades y
 *    Rotura/Caducidad × Máquina/Producto.
 *  - Regresión del «Top 10»: pasar de un criterio vacío a uno con datos pinta
 *    el gráfico en el primer clic.
 *  - Aviso térmico no segmentable al filtrar por reponedor.
 *  - Descarga CSV autenticada vía `fetch` + `Blob` con cabecera Bearer.
 *  - Consola sin errores ni excepciones no capturadas.
 *  - Rejilla de escritorio `1280 x 900` sin desbordamiento.
 *
 * La suite no puede ser hermética: exige un navegador instalado y Node 22+ con
 * `WebSocket` global (protocolo DevTools sin dependencias npm). Por eso declara
 * `new_engine` —la batería global la enumera y la deriva— y sale con código 2,
 * nunca 0, cuando el entorno no puede medir. Un verde de esta suite significa
 * que el layout se comprobó en un navegador real.
 *
 * Dogma Vanilla: PHP 8.2+ puro; el arnés del navegador es Node ESM nativo.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

/** Marca de categoría: la batería hermética no ejecuta esta suite (necesita navegador). */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

$projectRoot = dirname(__DIR__, 2);
$runner = $projectRoot . '/tests/Support/RestockingBiLayoutBrowserRunner.mjs';

echo "======================================================================\n";
echo " VENDGUARD · Layout real de la sub-pestaña M38 (T-RBI-07)\n";
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
