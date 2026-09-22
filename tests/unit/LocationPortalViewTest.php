<?php

declare(strict_types=1);

/**
 * LocationPortalViewTest
 * 
 * Suite de pruebas unitarias para la vista del Portal del Responsable (LocationPortalView.js)
 * y el componente de tarjeta de máquina (MachineCard.js) según la tarea T-34 (RF-01, RF-02, RNF-02, RNF-06).
 * 
 * Valida la condición "Hecho cuando":
 * - El conserje entra con su código de sede.
 * - Visualiza las máquinas de su edificio en tarjetas de 8px.
 * - Ve claramente cuáles tienen incidencias abiertas y cuáles están operativas.
 * 
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

echo "======================================================================\n";
echo " VendGuard: Test Unitario - LocationPortalViewTest (T-34)\n";
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
$machineCardPath = $baseDir . '/public/assets/js/components/MachineCard.js';
$portalViewPath = $baseDir . '/public/assets/js/views/LocationPortalView.js';
$testMjsPath = $baseDir . '/tests/unit/LocationPortalViewTest.mjs';

// =====================================================================
// GRUPO 1: Existencia e Integridad Estructural de Ficheros
// =====================================================================
echo "--- Grupo 1: Existencia e Integridad de Ficheros Frontend ---\n";

$cardExists = file_exists($machineCardPath);
$assert("1.1 El archivo MachineCard.js existe", $cardExists);

$portalExists = file_exists($portalViewPath);
$assert("1.2 El archivo LocationPortalView.js existe", $portalExists);

if (!$cardExists || !$portalExists) {
    echo "\n[ERROR CRITICO] Ficheros no encontrados. Abortando pruebas.\n";
    exit(1);
}

$cardContent = (string) file_get_contents($machineCardPath);
$portalContent = (string) file_get_contents($portalViewPath);

// =====================================================================
// GRUPO 2: Contratos de MachineCard.js (RNF-06 / RF-02)
// =====================================================================
echo "\n--- Grupo 2: Tarjetas de Máquina y Prevención de Duplicados ---\n";

$assert("2.1 Aplica radio de contenedor de 8px para tarjetas (--radius-card)",
    strpos($cardContent, "var(--radius-card, 8px)") !== false
);

$assert("2.2 Aplica radio de 4px en botones interactivos (--radius-interactive)",
    strpos($cardContent, "var(--radius-interactive, 4px)") !== false
);

$assert("2.3 Distingue visualmente máquinas con aviso activo bloqueando nuevos reportes",
    strpos($cardContent, "hasActiveIncident") !== false &&
    strpos($cardContent, "Avería en curso. No es posible abrir un nuevo ticket") !== false &&
    strpos($cardContent, "Añadir comentarios / fotos") !== false
);

$assert("2.4 Dispone de botón de reporte para máquinas totalmente operativas",
    strpos($cardContent, "Reportar avería") !== false &&
    strpos($cardContent, "handleReportClick") !== false
);

$assert("2.5 Dispone de acción de reapertura para máquinas en garantía de 48h",
    strpos($cardContent, "Reabrir incidencia") !== false &&
    strpos($cardContent, "isUnderWarranty") !== false
);

// =====================================================================
// GRUPO 3: Contratos de LocationPortalView.js (RF-01 / RNF-02)
// =====================================================================
echo "\n--- Grupo 3: Portal del Responsable de Sede ---\n";

$assert("3.1 Implementa autenticación sin contraseña mediante código de sede",
    strpos($portalContent, "handleSiteLogin") !== false &&
    strpos($portalContent, "api.auth.siteLogin") !== false &&
    strpos($portalContent, "site-code-input") !== false
);

$assert("3.2 Carga y visualiza las máquinas de la sede",
    strpos($portalContent, "loadMachines") !== false &&
    strpos($portalContent, "api.locations.getMachines") !== false
);

$assert("3.3 Dispone de filtros por estado (Todas, Con Avería, Operativas)",
    strpos($portalContent, "activeFilter === 'incident'") !== false &&
    strpos($portalContent, "activeFilter === 'operational'") !== false &&
    strpos($portalContent, "filteredMachines") !== false
);

$assert("3.4 Renderiza el grid responsivo de tarjetas MachineCard",
    strpos($portalContent, "class=\"vg-machine-grid\"") !== false &&
    strpos($portalContent, "<MachineCard") !== false
);

// =====================================================================
// GRUPO 4: Ejecución del Runner Dinámico JS (32 aserciones activas)
// =====================================================================
echo "\n--- Grupo 4: Ejecución del Runner Dinámico JS ---\n";

$output = [];
$returnCode = 0;
exec("node \"{$testMjsPath}\"", $output, $returnCode);

$assert("4.1 Runner JS finaliza con código de salida 0", $returnCode === 0, implode("\n", array_slice($output, -10)));

$hasGreenResult = false;
foreach ($output as $line) {
    if (strpos($line, 'RESULT: 100% IN GREEN. CONDITION T-34 FULFILLED SUCCESSFULLY.') !== false) {
        $hasGreenResult = true;
        break;
    }
}
$assert("4.2 Runner JS confirma cumplimiento al 100% de la condición T-34", $hasGreenResult);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-34 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
    echo "======================================================================\n";
    exit(1);
}
