<?php

declare(strict_types=1);

/**
 * FrontendApiStoreTest
 * 
 * Suite de pruebas unitarias para el cliente HTTP nativo (api.js) y el almacén
 * reactivo de estado (store.js) según la especificación técnica T-32 (RNF-01).
 * 
 * Valida la condición "Hecho cuando":
 * - api.js gestiona llamadas fetch(), adjunta tokens automáticamente y maneja errores.
 * - store.js expone el estado reactivo del usuario y la sede con Vue 3 reactive.
 * 
 * Dogma Vanilla: Ejecutable nativamente sin dependencias pesadas.
 */

echo "======================================================================\n";
echo " VendGuard: Test Unitario - FrontendApiStoreTest (T-32)\n";
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
$apiJsPath = $baseDir . '/public/assets/js/api.js';
$storeJsPath = $baseDir . '/public/assets/js/store.js';
$testMjsPath = $baseDir . '/tests/unit/FrontendApiStoreTest.mjs';

// =====================================================================
// GRUPO 1: Existencia e Integridad Estructural de Ficheros
// =====================================================================
echo "--- Grupo 1: Existencia e Integridad de Ficheros Frontend ---\n";

$apiExists = file_exists($apiJsPath);
$assert("1.1 El archivo public/assets/js/api.js existe", $apiExists);

$storeExists = file_exists($storeJsPath);
$assert("1.2 El archivo public/assets/js/store.js existe", $storeExists);

if (!$apiExists || !$storeExists) {
    echo "\n[ERROR CRITICO] Ficheros de frontend no encontrados. Abortando pruebas.\n";
    exit(1);
}

$apiContent = (string) file_get_contents($apiJsPath);
$storeContent = (string) file_get_contents($storeJsPath);

// =====================================================================
// GRUPO 2: Contratos y Capacidades de api.js
// =====================================================================
echo "\n--- Grupo 2: Capacidades del Cliente HTTP (api.js) ---\n";

$assert("2.1 Define la clase ApiError con soporte de códigos de error y estado HTTP", 
    strpos($apiContent, 'class ApiError extends Error') !== false &&
    strpos($apiContent, 'this.code = code') !== false &&
    strpos($apiContent, 'this.status = status') !== false
);

$assert("2.2 Define la clase ApiClient con inyección automática de tokens Bearer",
    strpos($apiContent, 'class ApiClient') !== false &&
    strpos($apiContent, "headers['Authorization'] = `Bearer \${this.token}`") !== false
);

$assert("2.3 Define cabecera X-Site-Code para el contexto de sede",
    strpos($apiContent, "headers['X-Site-Code'] = this.siteCode") !== false
);

$assert("2.4 Maneja envío de FormData y omite Content-Type manual para cálculo de boundary",
    strpos($apiContent, 'body instanceof FormData') !== false
);

$assert("2.5 Desempaqueta automáticamente el envelope { success: true, data: ... }",
    strpos($apiContent, "'data' in json") !== false
);

$assert("2.6 Dispone de métodos para todos los contratos de dominio (auth, coordinator, technician, incidents)",
    strpos($apiContent, 'siteLogin:') !== false &&
    strpos($apiContent, 'internalLogin:') !== false &&
    strpos($apiContent, 'getMachines:') !== false &&
    strpos($apiContent, 'getIncidents:') !== false &&
    strpos($apiContent, 'assignTechnician:') !== false &&
    strpos($apiContent, 'cancelIncident:') !== false &&
    strpos($apiContent, 'getMyRoute:') !== false &&
    strpos($apiContent, 'resolveIncident:') !== false
);

// =====================================================================
// GRUPO 3: Contratos y Reactividad de store.js
// =====================================================================
echo "\n--- Grupo 3: Almacén Reactivo de Estado (store.js) ---\n";

$assert("3.1 Utiliza y expone el estado reactivo con Vue 3 reactive",
    strpos($storeContent, 'vueReactive') !== false &&
    strpos($storeContent, 'export const state = vueReactive(') !== false
);

$assert("3.2 Expone sesión para usuario interno y sede",
    strpos($storeContent, 'user: null') !== false &&
    strpos($storeContent, 'location: null') !== false &&
    strpos($storeContent, 'token: null') !== false &&
    strpos($storeContent, 'authType: null') !== false
);

$assert("3.3 Implementa setInternalSession sincronizando token con api.js y almacenamiento local",
    strpos($storeContent, 'function setInternalSession(') !== false &&
    strpos($storeContent, 'api.setToken(token)') !== false &&
    strpos($storeContent, "localStorage.setItem") !== false
);

$assert("3.4 Implementa setSiteSession sincronizando token y siteCode con api.js",
    strpos($storeContent, 'function setSiteSession(') !== false &&
    strpos($storeContent, 'api.setSiteCode(location.site_code)') !== false
);

$assert("3.5 Implementa clearSession y restoreSession",
    strpos($storeContent, 'function clearSession(') !== false &&
    strpos($storeContent, 'function restoreSession(') !== false
);

$assert("3.6 Expone getters de rol y perfil (isAuthenticated, isCoordinator, isTechnician, isSiteSession)",
    strpos($storeContent, 'get isAuthenticated()') !== false &&
    strpos($storeContent, 'get isCoordinator()') !== false &&
    strpos($storeContent, 'get isTechnician()') !== false &&
    strpos($storeContent, 'get isSiteSession()') !== false
);

// =====================================================================
// GRUPO 4: Ejecución Dinámica del Runner JS de Extremo a Extremo
// =====================================================================
echo "\n--- Grupo 4: Ejecución Dinámica del Runner JS (44 aserciones activas) ---\n";

$output = [];
$returnCode = 0;
exec("node \"{$testMjsPath}\"", $output, $returnCode);

$assert("4.1 Runner JS se ejecuta con código de salida 0", $returnCode === 0, implode("\n", array_slice($output, -10)));

$hasGreenResult = false;
foreach ($output as $line) {
    if (strpos($line, 'RESULT: 100% IN GREEN. CONDITION T-32 FULFILLED SUCCESSFULLY.') !== false) {
        $hasGreenResult = true;
        break;
    }
}
$assert("4.2 Runner JS confirma cumplimiento al 100% de la condición T-32", $hasGreenResult);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-32 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
    echo "======================================================================\n";
    exit(1);
}
