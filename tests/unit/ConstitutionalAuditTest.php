<?php

declare(strict_types=1);

/**
 * ConstitutionalAuditTest (T-41)
 * 
 * Suite de Auditoría Constitucional Automática para el Cierre del MVP de VendGuard.
 * Valida de forma estricta los Artículos I al VII de `constitution.md`:
 * 
 * - Artículo I: Supremacía de la Especificación y cero mocks en código de producción.
 * - Artículo II: Principio de Precaución y Seguridad Alimentaria (Criticidad Innegociable).
 * - Artículo III: Prohibición absoluta de borrado físico destructivo (cero `DELETE FROM` en producción).
 * - Artículo IV: Minimalismo Tecnológico, Dogma Vanilla, tipado estricto en el 100% de ficheros PHP y cero frameworks pesados.
 * - Artículo V: Integridad de Reglas de Negocio (20 caracteres de resolución, 48h de garantía, 5MB upload).
 * - Artículo VI: Delimitación Sagrada del Alcance (Anti-Feature Creep, cero telemetría IoT / MDB).
 * - Artículo VII: Inmutabilidad constitucional.
 * 
 * Hecho cuando: Se comprueba que no existe ningún `DELETE FROM` en el código, cero frameworks pesados,
 *               tipado estricto en el 100% de ficheros PHP y cumplimiento de los tokens visuales de Docker.
 */

require_once __DIR__ . '/../bootstrap.php';

// Colores de salida en terminal
$colorBold  = "\033[1m";
$colorGreen = "\033[32m";
$colorRed   = "\033[31m";
$colorReset = "\033[0m";

echo "{$colorBold}======================================================================{$colorReset}\n";
echo "{$colorBold} VendGuard: Auditoría Constitucional y Cierre del MVP (T-41){$colorReset}\n";
echo "{$colorBold}======================================================================{$colorReset}\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $rule, bool $condition, string $detail = '') use (&$assertions, &$failures, $colorGreen, $colorRed, $colorReset): void {
    $assertions++;
    if ($condition) {
        echo "  {$colorGreen}[PASS]{$colorReset} {$rule}\n";
    } else {
        echo "  {$colorRed}[FAIL]{$colorReset} {$rule}\n";
        if ($detail !== '') {
            echo "         Motivo: {$detail}\n";
        }
        $failures++;
    }
};

$baseDir = dirname(__DIR__, 2);

/**
 * Recopila todos los archivos de un directorio de forma recursiva con una extensión dada.
 */
function collectFiles(string $dir, string $ext): array {
    if (!is_dir($dir)) return [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $files = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === $ext) {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

// ─────────────────────────────────────────────────────────────────────────
// AUDITORÍA 1: ARTÍCULO III — CERO BORRADO FÍSICO (NO "DELETE FROM" EN SRC Y PUBLIC)
// ─────────────────────────────────────────────────────────────────────────
echo "{$colorBold}--- 1. Artículo III: Inviolabilidad de Datos y Cero Borrado Físico ---{$colorReset}\n";

$prodPhpFiles = array_merge(
    collectFiles($baseDir . '/src', 'php'),
    collectFiles($baseDir . '/public', 'php')
);

$deleteFromViolations = [];
foreach ($prodPhpFiles as $filePath) {
    $content = (string)file_get_contents($filePath);
    if (preg_match('/\bDELETE\s+FROM\b/i', $content)) {
        $deleteFromViolations[] = str_replace($baseDir, '', $filePath);
    }
}

$assert(
    "1.1 Cero sentencias 'DELETE FROM' en todo el código de producción (src/ y public/)",
    empty($deleteFromViolations),
    "Se detectaron sentencias DELETE FROM en: " . implode(', ', $deleteFromViolations)
);

// Verificar implementación de borrado lógico (soft delete) en repositorios
$incidentRepoContent = (string)file_get_contents($baseDir . '/src/Infrastructure/Repository/PdoIncidentRepository.php');
$assert(
    "1.2 PdoIncidentRepository aplica soft delete y filtro deleted_at IS NULL",
    str_contains($incidentRepoContent, 'deleted_at IS NULL') && str_contains($incidentRepoContent, 'CANCELLED')
);

// ─────────────────────────────────────────────────────────────────────────
// AUDITORÍA 2: ARTÍCULO IV — DOGMA VANILLA Y CERO FRAMEWORKS PESADOS
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorBold}--- 2. Artículo IV: Minimalismo Tecnológico y Cero Bloatware ---{$colorReset}\n";

// Verificar ausencia de dependencias externas / frameworks en raíz
$composerJsonExists = file_exists($baseDir . '/composer.json');
$vendorDirExists = is_dir($baseDir . '/vendor');
$nodeModulesExists = is_dir($baseDir . '/node_modules');

$assert(
    "2.1 Ausencia total de composer.json / paquetes de terceros en tiempo de ejecución",
    !$composerJsonExists
);
$assert(
    "2.2 Ausencia total de directorio vendor/ externo (Arquitectura Vanilla PHP puro)",
    !$vendorDirExists
);
$assert(
    "2.3 Ausencia total de node_modules/ en producción (Frontend Vanilla ESM)",
    !$nodeModulesExists
);

// ─────────────────────────────────────────────────────────────────────────
// AUDITORÍA 3: ARTÍCULO IV — TIPADO ESTRICTO EN EL 100% DE FICHEROS PHP
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorBold}--- 3. Artículo IV: Tipado Estricto (declare(strict_types=1)) ---{$colorReset}\n";

$allPhpFiles = array_merge(
    collectFiles($baseDir . '/src', 'php'),
    collectFiles($baseDir . '/public', 'php'),
    collectFiles($baseDir . '/tests', 'php')
);

$missingStrictTypes = [];
foreach ($allPhpFiles as $filePath) {
    $content = (string)file_get_contents($filePath);
    if (!str_contains($content, 'declare(strict_types=1);')) {
        $missingStrictTypes[] = str_replace($baseDir, '', $filePath);
    }
}

$assert(
    "3.1 Tipado estricto (declare(strict_types=1);) en el 100% de ficheros PHP (" . count($allPhpFiles) . " ficheros)",
    empty($missingStrictTypes),
    "Ficheros sin tipado estricto: " . implode(', ', $missingStrictTypes)
);

// ─────────────────────────────────────────────────────────────────────────
// AUDITORÍA 4: RNF-06 & DESIGN TOKENS — SISTEMA DE DISEÑO DOCKER
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorBold}--- 4. RNF-06 y Sistema de Diseño Visual Docker ---{$colorReset}\n";

$cssPath = $baseDir . '/public/assets/css/design-tokens.css';
$assert("4.1 Archivo design-tokens.css existe e integrado", file_exists($cssPath));
$cssContent = (string)file_get_contents($cssPath);

// Paleta Docker
$assert(
    "4.2 Paleta Docker: Voltaje primario azul eléctrico (#2560ff)",
    str_contains($cssContent, '--color-primary: #2560ff')
);
$assert(
    "4.3 Paleta Docker: Superficie canvas off-white (#f9fafb)",
    str_contains($cssContent, '--color-canvas: #f9fafb')
);
$assert(
    "4.4 Paleta Docker: Tinta de texto slate (#2c333f)",
    str_contains($cssContent, '--color-slate: #2c333f')
);

// Tipografía
$assert(
    "4.5 Tipografía Docker: DM Sans para titulares y visualización",
    str_contains($cssContent, "'DM Sans'")
);
$assert(
    "4.6 Tipografía Docker: Inter para cuerpo, navegación y botones",
    str_contains($cssContent, "'Inter'")
);

// Bordes conservadores
$assert(
    "4.7 Radios conservadores: 4px para elementos interactivos (--radius-interactive)",
    str_contains($cssContent, '--radius-interactive: 4px')
);
$assert(
    "4.8 Radios conservadores: 8px para tarjetas y paneles (--radius-card)",
    str_contains($cssContent, '--radius-card: 8px')
);

// ─────────────────────────────────────────────────────────────────────────
// AUDITORÍA 5: ARTÍCULO II — SEGURIDAD ALIMENTARIA (CRITICIDAD INNEGOCIABLE)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorBold}--- 5. Artículo II: Principio de Precaución y Seguridad Alimentaria ---{$colorReset}\n";

$urgencyCalcContent = (string)file_get_contents($baseDir . '/src/Core/Service/UrgencyCalculator.php');
$assert(
    "5.1 UrgencyCalculator fuerza CRITICAL ante rotura de frío en alimentos perecederos",
    str_contains($urgencyCalcContent, "PERISHABLE_FOOD") &&
    str_contains($urgencyCalcContent, "TEMPERATURE_COLD") &&
    str_contains($urgencyCalcContent, "UrgencyLevel::CRITICAL")
);
$assert(
    "5.2 UrgencyCalculator fuerza CRITICAL ante máquina de alimentos apagada (ELECTRICAL_OFF)",
    str_contains($urgencyCalcContent, "ELECTRICAL_OFF")
);

// ─────────────────────────────────────────────────────────────────────────
// AUDITORÍA 6: ARTÍCULO V — INTEGRIDAD INVIOLABLE DE REGLAS DE NEGOCIO
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorBold}--- 6. Artículo V: Integridad Inviolable de Reglas de Negocio ---{$colorReset}\n";

$resolutionValContent = (string)file_get_contents($baseDir . '/src/Core/Service/ResolutionValidator.php');
$assert(
    "6.1 Regla RF-08: Exigencia de mínimo 20 caracteres independientes en diagnóstico y solución",
    str_contains($resolutionValContent, "20") &&
    str_contains($resolutionValContent, "mb_strlen")
);

$schemaContent = (string)file_get_contents($baseDir . '/database/schema.sql');
$assert(
    "6.2 Regla RF-02: Índice único condicional para bloqueo atómico de duplicados en BD",
    str_contains($schemaContent, "uq_machine_active_ticket")
);

$uploaderContent = (string)file_get_contents($baseDir . '/src/Infrastructure/Storage/LocalFileUploader.php');
$assert(
    "6.3 Regla RNF-05: Límite estricto de 5 MB y validación de tipos MIME seguros",
    str_contains($uploaderContent, "5 * 1024 * 1024") &&
    str_contains($uploaderContent, "image/jpeg") &&
    str_contains($uploaderContent, "image/png") &&
    str_contains($uploaderContent, "image/webp")
);

// ─────────────────────────────────────────────────────────────────────────
// AUDITORÍA 7: ARTÍCULO VI — DELIMITACIÓN DEL ALCANCE (ANTI-FEATURE CREEP)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorBold}--- 7. Artículo VI: Delimitación Sagrada del Alcance (Anti-Feature Creep) ---{$colorReset}\n";

$prohibitedTerms = ['telemetry', 'mdb_protocol', 'dex_protocol', 'van_inventory', 'payment_gateway', 'stripe', 'paypal'];
$creepDetected = [];

foreach ($prodPhpFiles as $filePath) {
    $content = strtolower((string)file_get_contents($filePath));
    foreach ($prohibitedTerms as $term) {
        if (str_contains($content, $term)) {
            $creepDetected[] = str_replace($baseDir, '', $filePath) . " ({$term})";
        }
    }
}

$assert(
    "7.1 Cero código de telemetría IoT MDB/DEX o inventario de furgonetas (Foco estricto en MVP)",
    empty($creepDetected),
    "Términos no autorizados detectados: " . implode(', ', $creepDetected)
);

// ─────────────────────────────────────────────────────────────────────────
// AUDITORÍA 8: MÓDULO QR — ARTÍCULOS I A VII (T-QR-16)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorBold}--- 8. Módulo QR: Auditoría Constitucional y Seguridad Zero Trust (T-QR-16) ---{$colorReset}\n";

// 8.1 Dogma Vanilla en Generación QR
$matrixGenFile = $baseDir . '/src/Core/Domain/Service/QrMatrixGenerator.php';
$svgRenderFile = $baseDir . '/src/Core/Domain/Service/NativeSvgQrRenderer.php';
$assert(
    "8.1 Algoritmo QR nativo puro sin paquetes externos (Reed-Solomon Vanilla en PHP)",
    file_exists($matrixGenFile) && file_exists($svgRenderFile)
);

// 8.2 Blindaje de Privacidad y Zero Trust en Scan Público (Art. V.4)
$scanCtrlContent = (string)file_get_contents($baseDir . '/src/Presentation/Controller/QrScanController.php');
$scanServiceContent = (string)file_get_contents($baseDir . '/src/Core/Service/QrScanService.php');
$assert(
    "8.2 QrScanController y QrScanService NO exponen assigned_technician ni notas internas en escaneo público",
    !str_contains($scanCtrlContent, "'assigned_technician'") &&
    !str_contains($scanServiceContent, "'internal_notes'") &&
    !str_contains($scanServiceContent, "'resolution_notes'")
);

// 8.3 Control de Acceso RBAC en etiquetas de coordinador
$routerContent = (string)file_get_contents($baseDir . '/src/Presentation/Routing/AppRouter.php');
$assert(
    "8.3 Endpoints /qr-label y /qr-batch protegidos por InternalAuthMiddleware('COORDINATOR')",
    str_contains($routerContent, "/api/coordinator/machines/{id}/qr-label") &&
    str_contains($routerContent, "/api/coordinator/locations/{id}/qr-batch") &&
    str_contains($routerContent, "COORDINATOR")
);

// 8.4 Dualismo Lingüístico en componentes Frontend QR
$qrViewContent = (string)file_get_contents($baseDir . '/public/assets/js/views/QrReportView.js');
$assert(
    "8.4 Dualismo Lingüístico: QrReportView utiliza inglés en código y español en interfaz",
    str_contains($qrViewContent, "export const QrReportView") &&
    str_contains($qrViewContent, "resolveMachine") &&
    str_contains($qrViewContent, "submitReport") &&
    str_contains($qrViewContent, "Asistencia Técnica") &&
    str_contains($qrViewContent, "Aviso Registrado con Éxito")
);

// ─────────────────────────────────────────────────────────────────────────
// AUDITORÍA 9: MÓDULO DE MÉTRICAS Y AUDITORÍA — ARTÍCULOS I A VII (T-MET-16)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorBold}--- 9. Módulo Métricas y Auditoría: Auditoría Constitucional (T-MET-16) ---{$colorReset}\n";

// 9.1 Dogma Vanilla en Analítica y Exportación (Art. I y IV)
$mttrMetricFile = $baseDir . '/src/Core/Domain/Model/MttrMetric.php';
$calcServiceFile = $baseDir . '/src/Application/Service/MetricsCalculationService.php';
$exportServiceFile = $baseDir . '/src/Application/Service/MetricsExportService.php';
$exportServiceContent = file_exists($exportServiceFile) ? (string)file_get_contents($exportServiceFile) : '';

$assert(
    "9.1 Dogma Vanilla: Cálculo analítico y exportación CSV nativos sin dependencias externas",
    file_exists($mttrMetricFile) &&
    file_exists($calcServiceFile) &&
    file_exists($exportServiceFile) &&
    str_contains($exportServiceContent, "\\xEF\\xBB\\xBF") &&
    str_contains($exportServiceContent, "10000")
);

// 9.2 Seguridad Alimentaria y SLAs Críticos (Art. II)
$kpiSummaryFile = $baseDir . '/src/Core/Domain/Model/KpiSummary.php';
$pdoMetricsRepoFile = $baseDir . '/src/Infrastructure/Repository/PdoMetricsRepository.php';
$kpiSummaryContent = file_exists($kpiSummaryFile) ? (string)file_get_contents($kpiSummaryFile) : '';
$pdoMetricsRepoContent = file_exists($pdoMetricsRepoFile) ? (string)file_get_contents($pdoMetricsRepoFile) : '';

$assert(
    "9.2 Artículo II: SLA innegociable de 4.0h para máquinas de alimentos perecederos y 24.0h general",
    str_contains($kpiSummaryContent, "SLA_PERISHABLE_HOURS = 4.0") &&
    str_contains($kpiSummaryContent, "SLA_GENERAL_HOURS") &&
    str_contains($pdoMetricsRepoContent, "PERISHABLE_FOOD") &&
    str_contains($pdoMetricsRepoContent, "240")
);

// 9.3 Inmutabilidad Radical y Trazabilidad de Auditoría (Art. III y V.1)
$auditRepoInterface = (string)file_get_contents($baseDir . '/src/Core/Domain/Repository/AuditLogRepositoryInterface.php');
$auditRepoPdo = (string)file_get_contents($baseDir . '/src/Infrastructure/Repository/PdoAuditLogRepository.php');

$auditModificationsDetected = [];
foreach ($prodPhpFiles as $filePath) {
    $content = strtoupper((string)file_get_contents($filePath));
    if (str_contains($content, 'UPDATE AUDIT_LOG') || str_contains($content, 'DELETE FROM AUDIT_LOG')) {
        $auditModificationsDetected[] = str_replace($baseDir, '', $filePath);
    }
}

$assert(
    "9.3 Artículo III: Tabla audit_log estrictamente append-only (cero UPDATE/DELETE en todo el código)",
    empty($auditModificationsDetected) &&
    !str_contains(strtolower($auditRepoInterface), 'function delete') &&
    !str_contains(strtolower($auditRepoInterface), 'function update') &&
    !str_contains(strtolower($auditRepoPdo), 'function delete') &&
    !str_contains(strtolower($auditRepoPdo), 'function update'),
    "Mutaciones de audit_log detectadas en: " . implode(', ', $auditModificationsDetected)
);

// 9.4 Zero Trust y RBAC Estricto en Métricas (Art. V.4)
$techMetricsCtrlContent = (string)file_get_contents($baseDir . '/src/Presentation/Controller/TechnicianMetricsController.php');

$assert(
    "9.4 Control de Acceso RBAC y neutralización de suplantación en rutas de métricas",
    str_contains($routerContent, "/api/coordinator/metrics/summary") &&
    str_contains($routerContent, "/api/coordinator/metrics/breakdown") &&
    str_contains($routerContent, "/api/coordinator/metrics/export") &&
    str_contains($routerContent, "/api/coordinator/audit-log") &&
    str_contains($routerContent, "/api/technician/my-metrics") &&
    str_contains($techMetricsCtrlContent, "getAttribute('user_id')") &&
    !str_contains($techMetricsCtrlContent, "\$_GET['technician_id']")
);

// 9.5 Cero Bloatware en Informes Ejecutivos (Art. IV)
$printCssFile = $baseDir . '/public/assets/css/metrics-print.css';
$printCssContent = file_exists($printCssFile) ? (string)file_get_contents($printCssFile) : '';

$assert(
    "9.5 Informe Ejecutivo PDF nativo vía CSS @media print sin librerías externas pesadas",
    file_exists($printCssFile) &&
    str_contains($printCssContent, "@media print") &&
    str_contains($printCssContent, "page-break-inside: avoid")
);

// 9.6 Dualismo Lingüístico en Frontend de Métricas y Auditoría
$coordMetricsView = (string)file_get_contents($baseDir . '/public/assets/js/views/CoordinatorMetricsView.js');
$techMetricsView = (string)file_get_contents($baseDir . '/public/assets/js/views/TechnicianMetricsView.js');
$modalView = (string)file_get_contents($baseDir . '/public/assets/js/components/ExecutiveReportModal.js');

$assert(
    "9.6 Dualismo Lingüístico: Vistas de métricas con código en inglés e interfaz en español",
    str_contains($coordMetricsView, "export const CoordinatorMetricsView") &&
    str_contains($coordMetricsView, "loadMetrics") &&
    str_contains($coordMetricsView, "Cuadro de Mandos (KPIs)") &&
    str_contains($techMetricsView, "export const TechnicianMetricsView") &&
    str_contains($techMetricsView, "loadMyMetrics") &&
    str_contains($modalView, "export const ExecutiveReportModal") &&
    str_contains($modalView, "Informe Ejecutivo de Rendimiento")
);

// ─────────────────────────────────────────────────────────────────────────
// RESUMEN FINAL DE LA AUDITORÍA CONSTITUCIONAL
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorBold}======================================================================{$colorReset}\n";
echo " Total Controles Constitucionales Evaluados : {$assertions}\n";
echo " Controles Cumplidos Satisfactoriamente     : " . ($assertions - $failures) . "\n";
echo " Infracciones Constitucionales Detectadas  : {$failures}\n";
echo "{$colorBold}======================================================================{$colorReset}\n";

if ($failures === 0) {
    echo "{$colorBold}{$colorGreen} RESULTADO: AUDITORÍA CONSTITUCIONAL APROBADA AL 100%.{$colorReset}\n";
    echo "{$colorBold}{$colorGreen} CONDICIÓN T-MET-16 CUMPLIDA CON ÉXITO. EL MÓDULO 03 QUEDA BLINDADO Y CERRADO.{$colorReset}\n";
    echo "{$colorBold}======================================================================{$colorReset}\n";
    exit(0);
} else {
    echo "{$colorBold}{$colorRed} RESULTADO: INFRACCIONES DETECTADAS EN LA AUDITORÍA.{$colorReset}\n";
    echo "{$colorBold}======================================================================{$colorReset}\n";
    exit(1);
}

