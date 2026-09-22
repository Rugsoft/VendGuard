<?php

declare(strict_types=1);

/**
 * CoordinatorDashboardViewTest
 * 
 * Suite de pruebas unitarias para la vista del Panel de Coordinación (CoordinatorDashboardView.js)
 * según la tarea T-37 y los requisitos RF-04, RF-05, RF-06 y RF-11 (SLA 24/7).
 * 
 * Valida la condición "Hecho cuando":
 * - Muestra la tabla de averías con filtros (por estado, urgencia, búsqueda de texto y SLA).
 * - Actualiza alertas de SLA > 60m mediante sondeo cada 60s (intervalo periódico).
 * - Abre modales de asignación técnica (con justificación obligatoria al reclasificar urgencia).
 * - Abre modal de descarte (borrado lógico) exigiendo motivo obligatorio.
 * 
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

echo "======================================================================\n";
echo " VendGuard: Test Unitario - CoordinatorDashboardViewTest (T-37)\n";
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
$viewPath = $baseDir . '/public/assets/js/views/CoordinatorDashboardView.js';
$testMjsPath = $baseDir . '/tests/unit/CoordinatorDashboardViewTest.mjs';

// =====================================================================
// GRUPO 1: Existencia e Integridad Estructural del Componente
// =====================================================================
echo "--- Grupo 1: Existencia e Integridad Estructural ---\n";

$viewExists = file_exists($viewPath);
$assert("1.1 El archivo CoordinatorDashboardView.js existe", $viewExists);

if (!$viewExists) {
    echo "\n[ERROR CRITICO] CoordinatorDashboardView.js no encontrado. Abortando pruebas.\n";
    exit(1);
}

$viewContent = (string) file_get_contents($viewPath);

// =====================================================================
// GRUPO 2: Contratos de Filtrado, Sondeo SLA y Modales (RF-05, RF-06, RF-11)
// =====================================================================
echo "\n--- Grupo 2: Contratos de SLA 24/7, Sondeo y Modales ---\n";

$assert("2.1 Implementa sondeo periódico cada 60s (60000ms) para alertas SLA",
    strpos($viewContent, 'startPolling') !== false &&
    strpos($viewContent, '60000') !== false &&
    strpos($viewContent, 'stopPolling') !== false
);

$assert("2.2 Detecta incumplimientos de SLA > 60m para alerta crítica",
    strpos($viewContent, 'slaBreachedIncidents') !== false &&
    strpos($viewContent, 'waiting_minutes') !== false &&
    strpos($viewContent, 'vg-sla-alert-banner') !== false
);

$assert("2.3 Dispone de filtros combinados (estado, urgencia, búsqueda y SLA)",
    strpos($viewContent, 'filteredIncidents') !== false &&
    strpos($viewContent, 'filterStatus') !== false &&
    strpos($viewContent, 'filterUrgency') !== false &&
    strpos($viewContent, 'filterSlaOnly') !== false
);

$assert("2.4 Modal de asignación exige motivo si se reclasifica urgencia (RF-05 / EARS 5.3)",
    strpos($viewContent, 'assignUrgencyOverride') !== false &&
    strpos($viewContent, 'assignUrgencyReason') !== false &&
    strpos($viewContent, 'submitAssignment') !== false
);

$assert("2.5 Modal de descarte exige motivo obligatorio para soft delete (RF-06 / EARS 6.1)",
    strpos($viewContent, 'cancelReason') !== false &&
    strpos($viewContent, 'submitCancellation') !== false &&
    strpos($viewContent, 'Soft Delete') !== false
);

// =====================================================================
// GRUPO 3: Ejecución del Runner Dinámico JS (24 aserciones reactivas)
// =====================================================================
echo "\n--- Grupo 3: Ejecución del Runner Dinámico JS ---\n";

$output = [];
$returnCode = 0;
exec("node \"{$testMjsPath}\"", $output, $returnCode);

$assert("3.1 Runner JS finaliza con código de salida 0", $returnCode === 0, implode("\n", array_slice($output, -10)));

$hasGreenResult = false;
foreach ($output as $line) {
    if (strpos($line, 'CONDITION T-37 FULFILLED SUCCESSFULLY') !== false) {
        $hasGreenResult = true;
        break;
    }
}
$assert("3.2 Runner JS confirma cumplimiento total de T-37", $hasGreenResult);

// =====================================================================
// RESUMEN FINAL
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Pasadas: " . ($assertions - $failures) . " | Falladas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-37 COMPLETADA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: EXISTEN FALLOS EN LA SUITE DE PRUEBAS.\n";
    echo "======================================================================\n";
    exit(1);
}
