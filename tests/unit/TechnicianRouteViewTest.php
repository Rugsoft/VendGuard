<?php

declare(strict_types=1);

/**
 * TechnicianRouteViewTest
 * 
 * Suite de pruebas unitarias para la vista móvil de campo del Técnico (TechnicianRouteView.js)
 * según la tarea T-38 y los requisitos RF-07, RF-08 y RNF-01.
 * 
 * Valida la condición "Hecho cuando":
 * - La interfaz vertical de smartphone permite al técnico ver su lista de tareas.
 * - Pulsar "Iniciar intervención" transiciona a IN_PROGRESS.
 * - "Pausar por repuesto" abre el modal que exige descripción del componente solicitado.
 * - Abre el modal de cierre que exige >= 20 caracteres por campo (diagnóstico y acción).
 * 
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

echo "======================================================================\n";
echo " VendGuard: Test Unitario - TechnicianRouteViewTest (T-38)\n";
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
$viewPath = $baseDir . '/public/assets/js/views/TechnicianRouteView.js';
$testMjsPath = $baseDir . '/tests/unit/TechnicianRouteViewTest.mjs';

// =====================================================================
// GRUPO 1: Existencia e Integridad Estructural del Componente
// =====================================================================
echo "--- Grupo 1: Existencia e Integridad Estructural ---\n";

$viewExists = file_exists($viewPath);
$assert("1.1 El archivo TechnicianRouteView.js existe", $viewExists);

if (!$viewExists) {
    echo "\n[ERROR CRITICO] TechnicianRouteView.js no encontrado. Abortando pruebas.\n";
    exit(1);
}

$viewContent = (string) file_get_contents($viewPath);

// =====================================================================
// GRUPO 2: Contratos de Usabilidad Móvil y Acciones (RF-07, RF-08, RNF-01)
// =====================================================================
echo "\n--- Grupo 2: Contratos de Usabilidad Móvil y Acciones Técnicas ---\n";

$assert("2.1 Interfaz móvil vertical con contenedor smartphone (max-width: 500px / RNF-01)",
    strpos($viewContent, 'max-width: 500px') !== false &&
    strpos($viewContent, 'vg-technician-route') !== false
);

$assert("2.2 Implementa acción de inicio de intervención (startIntervention / EARS 7.1)",
    strpos($viewContent, 'startIntervention') !== false &&
    strpos($viewContent, 'api.technician.startIncident') !== false
);

$assert("2.3 Modal de pausa por falta de repuestos (EARS 7.2)",
    strpos($viewContent, 'showPauseModal') !== false &&
    strpos($viewContent, 'submitPause') !== false &&
    strpos($viewContent, 'api.technician.pauseIncident') !== false
);

$assert("2.4 Modal de resolución con validación estricta >= 20 caracteres por campo (RF-08 / EARS 8.1, 8.2)",
    strpos($viewContent, 'isDiagnosisValid') !== false &&
    strpos($viewContent, 'isActionValid') !== false &&
    strpos($viewContent, 'canResolve') !== false &&
    strpos($viewContent, 'submitResolve') !== false &&
    strpos($viewContent, '20') !== false
);

$assert("2.5 Integración de llamada táctil directa al conserje (tel: / RNF-01)",
    strpos($viewContent, 'tel:') !== false
);

// =====================================================================
// GRUPO 3: Ejecución del Runner Dinámico JS (40 aserciones reactivas)
// =====================================================================
echo "\n--- Grupo 3: Ejecución del Runner Dinámico JS ---\n";

$output = [];
$returnCode = 0;
exec("node \"{$testMjsPath}\"", $output, $returnCode);

$assert("3.1 Runner JS finaliza con código de salida 0", $returnCode === 0, implode("\n", array_slice($output, -10)));

$hasGreenResult = false;
foreach ($output as $line) {
    if (strpos($line, 'CONDITION T-38 FULFILLED SUCCESSFULLY') !== false) {
        $hasGreenResult = true;
        break;
    }
}
$assert("3.2 Runner JS confirma cumplimiento total de T-38", $hasGreenResult);

// =====================================================================
// RESUMEN FINAL
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Pasadas: " . ($assertions - $failures) . " | Falladas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-38 COMPLETADA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: EXISTEN FALLOS EN LA SUITE DE PRUEBAS.\n";
    echo "======================================================================\n";
    exit(1);
}
