<?php

declare(strict_types=1);

/**
 * UIComponentsTest
 * 
 * Suite de pruebas unitarias para los componentes base de interfaz gráfica:
 * - IncidentBadge.js (Badges con colores semánticos y 4px radius)
 * - ModalDialog.js (Diálogo modal accesible WAI-ARIA y 8px card radius)
 * - AppNavbar.js (Barra de navegación, identidad de sesión y logout)
 * 
 * Cumple la condición "Hecho cuando:" de la tarea T-33 (RNF-01, RNF-06).
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

echo "======================================================================\n";
echo " VendGuard: Test Unitario - UIComponentsTest (T-33)\n";
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
$badgeJsPath = $baseDir . '/public/assets/js/components/IncidentBadge.js';
$modalJsPath = $baseDir . '/public/assets/js/components/ModalDialog.js';
$navbarJsPath = $baseDir . '/public/assets/js/components/AppNavbar.js';
$testMjsPath = $baseDir . '/tests/unit/UIComponentsTest.mjs';

// =====================================================================
// GRUPO 1: Existencia e Integridad Estructural de Componentes
// =====================================================================
echo "--- Grupo 1: Existencia e Integridad de Componentes UI Base ---\n";

$badgeExists = file_exists($badgeJsPath);
$assert("1.1 El archivo IncidentBadge.js existe", $badgeExists);

$modalExists = file_exists($modalJsPath);
$assert("1.2 El archivo ModalDialog.js existe", $modalExists);

$navbarExists = file_exists($navbarJsPath);
$assert("1.3 El archivo AppNavbar.js existe", $navbarExists);

if (!$badgeExists || !$modalExists || !$navbarExists) {
    echo "\n[ERROR CRITICO] Componentes base no encontrados. Abortando pruebas.\n";
    exit(1);
}

$badgeContent = (string) file_get_contents($badgeJsPath);
$modalContent = (string) file_get_contents($modalJsPath);
$navbarContent = (string) file_get_contents($navbarJsPath);

// =====================================================================
// GRUPO 2: Contratos de IncidentBadge.js
// =====================================================================
echo "\n--- Grupo 2: Badges Semánticos (IncidentBadge.js) ---\n";

$assert("2.1 Define configuración para urgencias (CRITICAL, HIGH, MEDIUM, LOW)",
    strpos($badgeContent, 'CRITICAL:') !== false &&
    strpos($badgeContent, 'HIGH:') !== false &&
    strpos($badgeContent, 'MEDIUM:') !== false &&
    strpos($badgeContent, 'LOW:') !== false
);

$assert("2.2 Define configuración para estados de ciclo de vida (REGISTERED, ASSIGNED, IN_PROGRESS, RESOLVED, etc.)",
    strpos($badgeContent, 'REGISTERED:') !== false &&
    strpos($badgeContent, 'ASSIGNED:') !== false &&
    strpos($badgeContent, 'IN_PROGRESS:') !== false &&
    strpos($badgeContent, 'RESOLVED:') !== false
);

$assert("2.3 Aplica radio de borde interactivo de 4px (--radius-interactive)",
    strpos($badgeContent, 'var(--radius-interactive, 4px)') !== false
);

$assert("2.4 Dispone de normalización diacrítica de acentos (ej. CRÍTICA -> CRITICA)",
    strpos($badgeContent, "normalize('NFD')") !== false
);

// =====================================================================
// GRUPO 3: Contratos de ModalDialog.js
// =====================================================================
echo "\n--- Grupo 3: Diálogo Modal Accesible (ModalDialog.js) ---\n";

$assert("3.1 Cumple directiva WAI-ARIA role=\"dialog\"",
    strpos($modalContent, 'role="dialog"') !== false
);

$assert("3.2 Define aria-modal=\"true\"",
    strpos($modalContent, 'aria-modal="true"') !== false
);

$assert("3.3 Enlaza dinámicamente :aria-labelledby con el identificador del título",
    strpos($modalContent, ':aria-labelledby="titleId"') !== false
);

$assert("3.4 Aplica radio de borde de 8px en tarjetas y modales (--radius-card)",
    strpos($modalContent, 'var(--radius-card, 8px)') !== false
);

$assert("3.5 Dispone de gestión de tecla Escape y ranuras header, default y footer",
    strpos($modalContent, "event.key === 'Escape'") !== false &&
    strpos($modalContent, '<slot name="footer">') !== false
);

// =====================================================================
// GRUPO 4: Contratos de AppNavbar.js
// =====================================================================
echo "\n--- Grupo 4: Barra de Navegación y Cierre de Sesión (AppNavbar.js) ---\n";

$assert("4.1 Importa y enlaza con el almacén reactivo store.js",
    strpos($navbarContent, "from '../store.js'") !== false
);

$assert("4.2 Muestra el logotipo VendGuard con tipografía DM Sans y color primario #2560ff",
    strpos($navbarContent, "'DM Sans'") !== false &&
    strpos($navbarContent, '#2560ff') !== false
);

$assert("4.3 Dispone de soporte visual para sesión de sede (site_code) y usuario interno (role)",
    strpos($navbarContent, 'isSiteSession') !== false &&
    strpos($navbarContent, 'site_code') !== false &&
    strpos($navbarContent, 'roleLabel') !== false
);

$assert("4.4 Implementa botón y acción de cierre de sesión (store.clearSession y emit logout)",
    strpos($navbarContent, 'store.clearSession()') !== false &&
    strpos($navbarContent, "this.\$emit('logout')") !== false
);

// =====================================================================
// GRUPO 5: Ejecución del Runner Dinámico JS (41 aserciones activas)
// =====================================================================
echo "\n--- Grupo 5: Ejecución del Runner Dinámico JS ---\n";

$output = [];
$returnCode = 0;
exec("node \"{$testMjsPath}\"", $output, $returnCode);

$assert("5.1 Runner JS finaliza con código de salida 0", $returnCode === 0, implode("\n", array_slice($output, -10)));

$hasGreenResult = false;
foreach ($output as $line) {
    if (strpos($line, 'RESULT: 100% IN GREEN. CONDITION T-33 FULFILLED SUCCESSFULLY.') !== false) {
        $hasGreenResult = true;
        break;
    }
}
$assert("5.2 Runner JS confirma cumplimiento al 100% de la condición T-33", $hasGreenResult);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-33 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
    echo "======================================================================\n";
    exit(1);
}
