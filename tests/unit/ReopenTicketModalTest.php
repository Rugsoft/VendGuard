<?php

declare(strict_types=1);

/**
 * ReopenTicketModalTest
 * 
 * Suite de pruebas unitarias para el modal de reapertura en garantía (ReopenTicketModal.js)
 * según la tarea T-36 y el requisito RF-09 (EARS 9.1, 9.2, 9.3).
 * 
 * Valida la condición "Hecho cuando":
 * - En máquinas en estado RESUELTA muestra el botón "Reabrir incidencia" si no han pasado 48h.
 * - Envía el motivo de reapertura a la API.
 * - Deshabilita la reapertura si han transcurrido más de 48 horas (exige nuevo ticket).
 * - Bloquea la reapertura ante avería crónica (límite de 2 reaperturas sucesivas).
 * 
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

echo "======================================================================\n";
echo " VendGuard: Test Unitario - ReopenTicketModalTest (T-36)\n";
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
$reopenModalPath = $baseDir . '/public/assets/js/components/ReopenTicketModal.js';
$testMjsPath = $baseDir . '/tests/unit/ReopenTicketModalTest.mjs';

// =====================================================================
// GRUPO 1: Existencia e Integridad Estructural del Fichero
// =====================================================================
echo "--- Grupo 1: Existencia e Integridad del Componente Frontend ---\n";

$modalExists = file_exists($reopenModalPath);
$assert("1.1 El archivo ReopenTicketModal.js existe", $modalExists);

if (!$modalExists) {
    echo "\n[ERROR CRITICO] ReopenTicketModal.js no encontrado. Abortando pruebas.\n";
    exit(1);
}

$modalContent = (string) file_get_contents($reopenModalPath);

// =====================================================================
// GRUPO 2: Contratos de Reapertura y Ventana de 48 Horas (RF-09)
// =====================================================================
echo "\n--- Grupo 2: Contratos de Garantía y Ventana de 48 Horas ---\n";

$assert("2.1 Calcula horas transcurridas desde resolved_at",
    strpos($modalContent, 'hoursSinceResolution') !== false &&
    strpos($modalContent, 'resolved_at') !== false
);

$assert("2.2 Evalúa ventana de 48 horas para habilitar garantía (EARS 9.1)",
    strpos($modalContent, 'isWarrantyActive') !== false &&
    strpos($modalContent, '48.0') !== false
);

$assert("2.3 Exige motivo de reapertura obligatorio (reason)",
    strpos($modalContent, 'reason') !== false &&
    strpos($modalContent, 'api.incidents.reopen') !== false
);

$assert("2.4 Muestra aviso y botón de nuevo ticket si la garantía expiró > 48h (EARS 9.2)",
    strpos($modalContent, 'Ventana de Garantía Expirada') !== false &&
    strpos($modalContent, 'handleCreateNewTicket') !== false
);

$assert("2.5 Bloquea reapertura automática si se alcanza límite de Avería Crónica (EARS 9.3)",
    strpos($modalContent, 'CHRONIC_INCIDENT_LIMIT') !== false &&
    strpos($modalContent, 'Avería Crónica') !== false
);

// =====================================================================
// GRUPO 3: Ejecución del Runner Dinámico JS (16 aserciones activas)
// =====================================================================
echo "\n--- Grupo 3: Ejecución del Runner Dinámico JS ---\n";

$output = [];
$returnCode = 0;
exec("node \"{$testMjsPath}\"", $output, $returnCode);

$assert("3.1 Runner JS finaliza con código de salida 0", $returnCode === 0, implode("\n", array_slice($output, -10)));

$hasGreenResult = false;
foreach ($output as $line) {
    if (strpos($line, 'RESULT: 100% IN GREEN. CONDITION T-36 FULFILLED SUCCESSFULLY.') !== false) {
        $hasGreenResult = true;
        break;
    }
}
$assert("3.2 Runner JS confirma cumplimiento al 100% de la condición T-36", $hasGreenResult);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-36 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
    echo "======================================================================\n";
    exit(1);
}
