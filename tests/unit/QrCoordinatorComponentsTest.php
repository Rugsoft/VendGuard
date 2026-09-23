<?php

declare(strict_types=1);

/**
 * QrCoordinatorComponentsTest
 * 
 * Suite de pruebas unitarias para los componentes de coordinador de códigos QR:
 * - QrLabelModal.js
 * - QrBatchPrintView.js
 * - qr-print.css
 * - Integración en CoordinatorDashboardView.js
 * según la tarea T-QR-14 (RF-01, RF-02 / EARS 2.1, 2.2).
 * 
 * Valida la condición "Hecho cuando":
 * - El panel de coordinación incluye el botón "🏷️ Imprimir QR" en cada máquina (con previsualización,
 *   teléfono editable, descarga SVG e impresión).
 * - El botón "📄 Etiquetas de Sede (A4)" maqueta la cuadrícula con saltos de página limpios.
 * 
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

echo "======================================================================\n";
echo " VendGuard: Test Unitario - QrCoordinatorComponentsTest (T-QR-14)\n";
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

$baseDir = dirname(__DIR__, 2);
$labelModalPath = $baseDir . '/public/assets/js/components/QrLabelModal.js';
$batchViewPath = $baseDir . '/public/assets/js/views/QrBatchPrintView.js';
$printCssPath = $baseDir . '/public/assets/css/qr-print.css';
$dashboardPath = $baseDir . '/public/assets/js/views/CoordinatorDashboardView.js';
$testMjsPath = $baseDir . '/tests/unit/QrCoordinatorComponentsTest.mjs';

// =====================================================================
// GRUPO 1: Existencia e Integridad Estructural de Ficheros Frontend
// =====================================================================
echo "--- Grupo 1: Existencia e Integridad de Ficheros Frontend ---\n";

$assert("1.1 El archivo QrLabelModal.js existe", file_exists($labelModalPath));
$assert("1.2 El archivo QrBatchPrintView.js existe", file_exists($batchViewPath));
$assert("1.3 El archivo qr-print.css existe", file_exists($printCssPath));
$assert("1.4 El archivo QrCoordinatorComponentsTest.mjs existe", file_exists($testMjsPath));

$dashboardContent = (string) file_get_contents($dashboardPath);
$modalContent = (string) file_get_contents($labelModalPath);
$batchContent = (string) file_get_contents($batchViewPath);
$cssContent = (string) file_get_contents($printCssPath);

// =====================================================================
// GRUPO 2: Contratos de Integración en CoordinatorDashboardView (T-QR-14)
// =====================================================================
echo "\n--- Grupo 2: Integración en CoordinatorDashboardView ---\n";

$assert("2.1 Dispone de botón '🏷️ Imprimir QR' en cada fila de máquina",
    strpos($dashboardContent, '🏷️ Imprimir QR') !== false &&
    strpos($dashboardContent, 'openQrLabelModal') !== false
);

$assert("2.2 Dispone de botón '📄 Etiquetas de Sede (A4)' en la barra de herramientas",
    strpos($dashboardContent, '📄 Etiquetas de Sede (A4)') !== false &&
    strpos($dashboardContent, 'openQrBatchPrint') !== false
);

$assert("2.3 Template renderiza componentes QrLabelModal y QrBatchPrintView",
    strpos($dashboardContent, '<QrLabelModal') !== false &&
    strpos($dashboardContent, '<QrBatchPrintView') !== false
);

// =====================================================================
// GRUPO 3: Contratos de QrLabelModal y QrBatchPrintView (RF-01, RF-02)
// =====================================================================
echo "\n--- Grupo 3: Funcionalidades de Etiquetas e Impresión A4 ---\n";

$assert("3.1 QrLabelModal implementa previsualización de SVG vectorial (EARS 1.4)",
    strpos($modalContent, 'data-testid="svg-preview-container"') !== false &&
    strpos($modalContent, 'svgContent') !== false
);

$assert("3.2 QrLabelModal permite editar teléfono y persistencia en sede (EARS 1.2, 1.3)",
    strpos($modalContent, 'data-testid="phone-input"') !== false &&
    strpos($modalContent, 'data-testid="update-location-checkbox"') !== false &&
    strpos($modalContent, 'updateLocationPhone') !== false
);

$assert("3.3 QrLabelModal permite descargar archivo SVG e imprimir individualmente (EARS 2.1, 2.3)",
    strpos($modalContent, 'downloadSvg') !== false &&
    strpos($modalContent, 'printSingleLabel') !== false
);

$assert("3.4 QrBatchPrintView maqueta cuadrícula de etiquetas para A4 (EARS 2.2)",
    strpos($batchContent, 'data-testid="batch-grid"') !== false &&
    strpos($batchContent, 'qr-a4-sheet') !== false &&
    strpos($batchContent, 'triggerPrint') !== false
);

$assert("3.5 qr-print.css aplica @media print con break-inside: avoid y tamaño A4 (RNF-03)",
    strpos($cssContent, '@media print') !== false &&
    strpos($cssContent, 'size: A4 portrait') !== false &&
    strpos($cssContent, 'break-inside: avoid') !== false
);

// =====================================================================
// GRUPO 4: Ejecución del Runner Dinámico JS (23 aserciones Node.js ESM)
// =====================================================================
echo "\n--- Grupo 4: Ejecución del Runner Dinámico JS (Node.js ESM) ---\n";

$output = [];
$returnCode = 0;
exec("node \"{$testMjsPath}\"", $output, $returnCode);

$assert("4.1 Runner JS finaliza con código de salida 0", $returnCode === 0, implode("\n", array_slice($output, -10)));

$hasGreenResult = false;
foreach ($output as $line) {
    if (strpos($line, 'RESULTADO: ¡TODAS LAS PRUEBAS PASARON') !== false) {
        $hasGreenResult = true;
        break;
    }
}
$assert("4.2 Runner JS confirma cumplimiento al 100% de la condición T-QR-14", $hasGreenResult);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON ({$assertions} aserciones, 0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S) de {$assertions} evaluadas.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
