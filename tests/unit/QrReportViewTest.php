<?php

declare(strict_types=1);

/**
 * QrReportViewTest
 * 
 * Suite de pruebas unitarias para la vista móvil de escaneo y reporte (QrReportView.js)
 * según la tarea T-QR-13 (RF-03, RF-04, RF-05 y Artículos II y V de la Constitución de VendGuard).
 * 
 * Valida la condición "Hecho cuando":
 * - Al montar QrReportView con una máquina limpia se muestra la máquina bloqueada y el banner sanitario en perecederos.
 * - Ante avería activa muestra el panel público y formulario de comentario adicional.
 * - Tras reportar muestra la confirmación #TICK-XXXX.
 * 
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

echo "======================================================================\n";
echo " VendGuard: Test Unitario - QrReportViewTest (T-QR-13)\n";
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
$viewPath = $baseDir . '/public/assets/js/views/QrReportView.js';
$testMjsPath = $baseDir . '/tests/unit/QrReportViewTest.mjs';

// =====================================================================
// GRUPO 1: Existencia e Integridad Estructural de Ficheros Frontend
// =====================================================================
echo "--- Grupo 1: Existencia e Integridad de Ficheros Frontend ---\n";

$viewExists = file_exists($viewPath);
$assert("1.1 El archivo QrReportView.js existe", $viewExists);

$mjsExists = file_exists($testMjsPath);
$assert("1.2 El archivo QrReportViewTest.mjs existe", $mjsExists);

if (!$viewExists || !$mjsExists) {
    echo "\n[ERROR CRITICO] Ficheros no encontrados. Abortando pruebas.\n";
    exit(1);
}

$viewContent = (string) file_get_contents($viewPath);

// =====================================================================
// GRUPO 2: Contratos de QrReportView.js (RF-03 / Mandato Sanitario Art. II)
// =====================================================================
echo "\n--- Grupo 2: Contratos de QrReportView.js ---\n";

$assert("2.1 Dispone de chip de máquina bloqueada en selección (EARS 3.2)",
    strpos($viewContent, 'data-testid="machine-locked-chip"') !== false &&
    strpos($viewContent, '🔒 Bloqueada en selección') !== false
);

$assert("2.2 Dispone de banner de alerta sanitaria en alimentos perecederos (Art. II / EARS 3.3)",
    strpos($viewContent, 'data-testid="perishable-health-banner"') !== false &&
    strpos($viewContent, 'Máquina de alimentos frescos:') !== false &&
    strpos($viewContent, 'rotura de frío') !== false
);

$assert("2.3 Dispone de panel público para averías activas preexistentes (EARS 4.1)",
    strpos($viewContent, 'data-testid="active-incident-panel"') !== false &&
    strpos($viewContent, 'Esta máquina ya tiene un aviso abierto') !== false
);

$assert("2.4 Dispone de formulario para añadir observaciones adicionales (EARS 4.2)",
    strpos($viewContent, 'data-testid="additional-comment-form"') !== false &&
    strpos($viewContent, 'submitAdditionalComment') !== false
);

$assert("2.5 Dispone de pantalla de confirmación con código de ticket (EARS 3.4)",
    strpos($viewContent, 'data-testid="confirmation-card"') !== false &&
    strpos($viewContent, 'formattedTicketCode') !== false
);

$assert("2.6 Dispone de pantalla de cortesía ante máquina no encontrada (EARS 5.2)",
    strpos($viewContent, 'data-testid="not-found-card"') !== false &&
    strpos($viewContent, 'Máquina no identificada o temporalmente fuera de servicio') !== false
);

$assert("2.7 Blindaje de Privacidad Art. V.4: no renderiza ni solicita datos de técnicos en modo público",
    strpos($viewContent, 'assigned_technician') === false &&
    strpos($viewContent, 'technician_phone') === false
);

// =====================================================================
// GRUPO 3: Ejecución del Runner Dinámico JS (27 aserciones Node.js ESM)
// =====================================================================
echo "\n--- Grupo 3: Ejecución del Runner Dinámico JS (Node.js ESM) ---\n";

$output = [];
$returnCode = 0;
exec("node \"{$testMjsPath}\"", $output, $returnCode);

$assert("3.1 Runner JS finaliza con código de salida 0", $returnCode === 0, implode("\n", array_slice($output, -10)));

$hasGreenResult = false;
foreach ($output as $line) {
    if (strpos($line, 'RESULTADO: ¡TODAS LAS PRUEBAS PASARON') !== false) {
        $hasGreenResult = true;
        break;
    }
}
$assert("3.2 Runner JS confirma cumplimiento al 100% de la condición T-QR-13", $hasGreenResult);

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
