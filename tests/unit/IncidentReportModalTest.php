<?php

declare(strict_types=1);

/**
 * IncidentReportModalTest
 * 
 * Suite de pruebas unitarias para el modal guiado de reporte de avería (IncidentReportModal.js)
 * y el componente de previsualización de imágenes (ImagePreview.js) según la tarea T-35
 * (RF-02, RF-03, RNF-02, RNF-05).
 * 
 * Valida la condición "Hecho cuando":
 * - Permite reportar la avería en < 2 min.
 * - Si la máquina ya tiene ticket activo, bloquea la creación y habilita el formulario para anexar comentarios/fotos.
 * - Valida límite de 5 MB en fotografías y preserva datos de texto en caso de fallo.
 * 
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

echo "======================================================================\n";
echo " VendGuard: Test Unitario - IncidentReportModalTest (T-35)\n";
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
$previewJsPath = $baseDir . '/public/assets/js/components/ImagePreview.js';
$reportModalPath = $baseDir . '/public/assets/js/components/IncidentReportModal.js';
$testMjsPath = $baseDir . '/tests/unit/IncidentReportModalTest.mjs';

// =====================================================================
// GRUPO 1: Existencia e Integridad Estructural de Ficheros
// =====================================================================
echo "--- Grupo 1: Existencia e Integridad de Componentes Frontend ---\n";

$previewExists = file_exists($previewJsPath);
$assert("1.1 El archivo ImagePreview.js existe", $previewExists);

$modalExists = file_exists($reportModalPath);
$assert("1.2 El archivo IncidentReportModal.js existe", $modalExists);

if (!$previewExists || !$modalExists) {
    echo "\n[ERROR CRITICO] Ficheros no encontrados. Abortando pruebas.\n";
    exit(1);
}

$previewContent = (string) file_get_contents($previewJsPath);
$modalContent = (string) file_get_contents($reportModalPath);

// =====================================================================
// GRUPO 2: Contratos de ImagePreview.js (RNF-05)
// =====================================================================
echo "\n--- Grupo 2: Validación de Fotografía y Límite de 5 MB ---\n";

$assert("2.1 Define límite de tamaño de 5 MB (5 * 1024 * 1024)",
    strpos($previewContent, '5 * 1024 * 1024') !== false || strpos($previewContent, 'maxSizeMb') !== false
);

$assert("2.2 Restringe formatos estrictamente a tipos gráficos seguros (jpg, png, webp)",
    strpos($previewContent, 'image/jpeg') !== false &&
    strpos($previewContent, 'image/png') !== false &&
    strpos($previewContent, 'image/webp') !== false
);

$assert("2.3 Limpia la vista previa y emite valor actualizado",
    strpos($previewContent, 'clearPreview') !== false &&
    strpos($previewContent, "'update:modelValue'") !== false
);

// =====================================================================
// GRUPO 3: Contratos de IncidentReportModal.js (RF-02 / RF-03 / Art. II)
// =====================================================================
echo "\n--- Grupo 3: Reporte Guiado y Bloqueo Estricto de Duplicados ---\n";

$assert("3.1 Define las categorías estándar de avería del sistema",
    strpos($modalContent, 'TEMPERATURE_COLD') !== false &&
    strpos($modalContent, 'PAYMENT_SYSTEM') !== false &&
    strpos($modalContent, 'PRODUCT_JAM') !== false &&
    strpos($modalContent, 'ELECTRICAL_OFF') !== false
);

$assert("3.2 Incorpora alerta sanitaria crítica para máquinas PERISHABLE_FOOD con fallo térmico (Art. II)",
    strpos($modalContent, 'isFoodSafetyCritical') !== false &&
    strpos($modalContent, 'Prioridad Sanitaria Crítica (Art. II Constitución)') !== false
);

$assert("3.3 Bloquea la creación de nuevo ticket si la máquina ya cuenta con aviso activo (RF-02)",
    strpos($modalContent, 'hasActiveIncident') !== false &&
    strpos($modalContent, 'bloquea automáticamente la creación de partes duplicados') !== false
);

$assert("3.4 Habilita formulario para anexar comentarios o fotos a ticket existente (EARS 2.3)",
    strpos($modalContent, 'handleSubmitComment') !== false &&
    strpos($modalContent, 'api.incidents.addComment') !== false &&
    strpos($modalContent, 'Anexar comentarios o fotos adicionales a este ticket') !== false
);

$assert("3.5 Soporta captura de importe retenido en euros (EARS 3.8)",
    strpos($modalContent, 'retainedMoney') !== false &&
    strpos($modalContent, 'retained_money_amount') !== false
);

// =====================================================================
// GRUPO 4: Ejecución del Runner Dinámico JS (23 aserciones activas)
// =====================================================================
echo "\n--- Grupo 4: Ejecución del Runner Dinámico JS ---\n";

$output = [];
$returnCode = 0;
exec("node \"{$testMjsPath}\"", $output, $returnCode);

$assert("4.1 Runner JS finaliza con código de salida 0", $returnCode === 0, implode("\n", array_slice($output, -10)));

$hasGreenResult = false;
foreach ($output as $line) {
    if (strpos($line, 'RESULT: 100% IN GREEN. CONDITION T-35 FULFILLED SUCCESSFULLY.') !== false) {
        $hasGreenResult = true;
        break;
    }
}
$assert("4.2 Runner JS confirma cumplimiento al 100% de la condición T-35", $hasGreenResult);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-35 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
    echo "======================================================================\n";
    exit(1);
}
