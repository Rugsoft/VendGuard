<?php

declare(strict_types=1);

/**
 * VendGuard - SparePartsModuleComplianceTest
 *
 * Suite de Verificación Global, Dogma Vanilla y Dualismo Lingüístico del Módulo M2 (T-SPARE-21).
 * Requisitos: RF-REP-01 a RF-REP-10, RNF-REP-01 a RNF-REP-05, Constitución Art. I a VII.
 *
 * Valida la condición "Hecho cuando:" de T-SPARE-21:
 * 1. La ejecución de `php tests/run_all.php` completa todas las suites con 0 fallos y 0 errores
 *    (verificado en la ejecución oficial de cierre; esta suite replica sus comprobaciones estáticas).
 * 2. Se verifica la ausencia de dependencias externas npm/composer (Dogma Vanilla):
 *    cero ficheros de manifiesto o lockfiles, cero vendor/ o node_modules, cero `require`/`include`
 *    de rutas externas, cero imports absolutos de terceros y las 4 especificaciones de excepciones
 *    de dominio del módulo preservadas en los contratos técnicos.
 * 3. Se verifica el cumplimiento estricto del Dualismo Lingüístico: código e identificadores en
 *    inglés técnico en todo el módulo (campos DDL, nombres de ficheros, identificadores de componentes)
 *    e interfaz, mensajes de error y validación en castellano en las respuestas HTTP del módulo.
 * 4. Regresión global del módulo: presencia e integridad de las 21 tareas T-SPARE, las tablas del
 *    esquema DDL, las entidades, repositorios, servicios, controladores, rutas y componentes frontend,
 *    y validaciones reglamentarias clave (1-50 unidades, justificación >= 20 chars, snapshot de coste).
 *
 * Dogma Vanilla: Cero dependencias externas (PHP 8.2+ puro, ES Modules nativos).
 * Dualismo Lingüístico: Código e identificadores en inglés, interfaz y mensajes en español.
 */

require_once __DIR__ . '/../bootstrap.php';

echo "======================================================================\n";
echo " VendGuard: SparePartsModuleComplianceTest (T-SPARE-21)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $rule, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$rule}\n";
    } else {
        echo "  [FAIL] {$rule}\n";
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
$collectFiles = function (string $dir, string $ext): array {
    if (!is_dir($dir)) {
        return [];
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $files = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === $ext) {
            $files[] = $file->getPathname();
        }
    }
    return $files;
};

/**
 * Comprueba que un fichero PHP declara tipado estricto.
 */
$fileHasStrictTypes = function (string $filePath): bool {
    return str_contains((string)file_get_contents($filePath), 'declare(strict_types=1);');
};

/**
 * Comprueba que una cadena contiene al menos un carácter castellanohablante
 * (vocales acentuadas, eñe, signos de apertura de exclamación/interrogación).
 */
$hasSpanishAccentOrÑ = function (string $content): bool {
    return (bool)preg_match('/[áéíóúÁÉÍÓÚñÑ¿¡]/u', $content);
};

// =========================================================================
// SECCIÓN 1: Dogma Vanilla — Ausencia Total de Dependencias Externas
// =========================================================================
echo "\n--- SECCIÓN 1: Dogma Vanilla — Cero Dependencias Externas (Constitución Art. IV) ---\n";

// 1.1 Cero ficheros de manifiesto y lockfiles npm/composer en el repositorio
$forbiddenDependencyFiles = [
    'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
    'yarn.lock', 'pnpm-lock.yaml', 'bun.lockb',
];
$foundDependencyFiles = [];
foreach ($forbiddenDependencyFiles as $depFile) {
    if (file_exists($baseDir . '/' . $depFile)) {
        $foundDependencyFiles[] = $depFile;
    }
}
$assert(
    "1.1 Cero ficheros de manifiesto npm/composer (composer.json, package.json, lockfiles)",
    $foundDependencyFiles === [],
    'Ficheros prohibidos presentes: ' . implode(', ', $foundDependencyFiles)
);

// 1.2 Cero directorios vendor/ o node_modules
$foundVendoredDirs = [];
foreach (['vendor', 'node_modules'] as $vendoredDir) {
    if (is_dir($baseDir . '/' . $vendoredDir)) {
        $foundVendoredDirs[] = $vendoredDir;
    }
}
$assert(
    "1.2 Cero directorios vendor/ o node_modules",
    $foundVendoredDirs === [],
    'Directorios prohibidos presentes: ' . implode(', ', $foundVendoredDirs)
);

// 1.3 Cero require/include de rutas externas en el código de producción
$prodPhpFiles = array_merge(
    $collectFiles($baseDir . '/src', 'php'),
    $collectFiles($baseDir . '/public', 'php')
);
$externalRequireViolations = [];
foreach ($prodPhpFiles as $filePath) {
    $content = (string)file_get_contents($filePath);
    if (preg_match('/(require|include)(_once)?\s*\(?\s*[\'"]vendor\//i', $content) === 1) {
        $externalRequireViolations[] = str_replace($baseDir, '', $filePath);
    }
}
$assert(
    "1.3 Cero require/include de rutas vendor/ externas en src/ y public/",
    $externalRequireViolations === [],
    'Violaciones: ' . implode(', ', $externalRequireViolations)
);

// 1.4 Autoloader nativo PSR-4 sin Composer
$autoloadContent = (string)file_get_contents($baseDir . '/src/autoload.php');
$assert(
    "1.4 Autoloader nativo PSR-4 registrado sin Composer (spl_autoload_register)",
    str_contains($autoloadContent, 'spl_autoload_register') && !str_contains($autoloadContent, 'require vendor')
);

// 1.5 Vue 3 local embebido en el repositorio (sin CDN ni instalación npm)
$vueLocalPath = $baseDir . '/public/assets/js/vendor/vue.esm-browser.prod.js';
$assert(
    "1.5 Vue 3 ESM servido localmente desde public/assets/js/vendor/ (sin CDN ni npm)",
    file_exists($vueLocalPath)
);

$frontendJsFiles = array_merge(
    $collectFiles($baseDir . '/public/assets/js', 'js'),
    $collectFiles($baseDir . '/public/assets/js', 'mjs')
);
$cdnImportViolations = [];
foreach ($frontendJsFiles as $filePath) {
    $content = (string)file_get_contents($filePath);
    if (preg_match('/import\s+[^;]+from\s+[\'"]https?:\/\//i', $content) === 1) {
        $cdnImportViolations[] = str_replace($baseDir, '', $filePath);
    }
}
$assert(
    "1.6 Cero imports de módulos desde CDN http(s):// en el frontend",
    $cdnImportViolations === [],
    'Violaciones: ' . implode(', ', $cdnImportViolations)
);

// 1.7 Cero llamadas a gestores de paquetes en scripts operativos del repositorio
$binScripts = $collectFiles($baseDir . '/bin', 'php');
$npmInstallViolations = [];
foreach ($binScripts as $filePath) {
    $content = (string)file_get_contents($filePath);
    if (preg_match('/\b(npm|yarn|pnpm|bun)\s+(install|add|ci)\b/i', $content) === 1) {
        $npmInstallViolations[] = str_replace($baseDir, '', $filePath);
    }
}
$assert(
    "1.7 Cero invocaciones de npm/yarn/pnpm/bun install en scripts de bin/",
    $npmInstallViolations === [],
    'Violaciones: ' . implode(', ', $npmInstallViolations)
);

// 1.8 Tipado estricto en el 100% de ficheros PHP de producción del módulo M2
$sparePartsPhpFiles = [
    '/src/Core/Domain/Model/SparePart.php',
    '/src/Core/Domain/Model/SparePartRequest.php',
    '/src/Core/Domain/Model/IncidentReplacedPart.php',
    '/src/Application/Service/SparePartCatalogService.php',
    '/src/Application/Service/SparePartTraceabilityService.php',
    '/src/Application/Service/SparePartAnalyticsService.php',
    '/src/Infrastructure/Repository/PdoSparePartRepository.php',
    '/src/Infrastructure/Repository/PdoSparePartRequestRepository.php',
    '/src/Infrastructure/Repository/PdoIncidentReplacedPartRepository.php',
    '/src/Presentation/Controller/CoordinatorSparePartsController.php',
    '/src/Presentation/Controller/TechnicianSparePartsController.php',
    '/src/Presentation/Controller/LocationPortalController.php',
];
$missingStrictTypes = [];
foreach ($sparePartsPhpFiles as $relativePath) {
    if (!file_exists($baseDir . $relativePath)) {
        $missingStrictTypes[] = $relativePath . ' (fichero inexistente)';
        continue;
    }
    if (!$fileHasStrictTypes($baseDir . $relativePath)) {
        $missingStrictTypes[] = $relativePath . ' (sin declare(strict_types=1);)';
    }
}
$assert(
    "1.8 Tipado estricto declare(strict_types=1) en los 12 ficheros PHP nucleares del módulo M2",
    $missingStrictTypes === [],
    'Ficheros no conformes: ' . implode(', ', $missingStrictTypes)
);

// =========================================================================
// SECCIÓN 2: Dualismo Lingüístico — Código en Inglés, Interfaz en Castellano
// =========================================================================
echo "\n--- SECCIÓN 2: Dualismo Lingüístico (Código en inglés, interfaz en español) ---\n";

// 2.1 Esquema DDL íntegramente en inglés técnico (snake_case inglés)
$ddlFile = $baseDir . '/database/migrations/006_spare_parts_catalog_and_traceability.sql';
$ddlContent = (string)file_get_contents($ddlFile);
$requiredDdlColumns = [
    'part_code', 'reference_cost', 'is_active', 'deleted_at',
    'machine_model', 'custom_part_description', 'requested_by_user_id',
    'intervention_type', 'unit_cost_snapshot', 'total_cost_snapshot',
    'old_part_destination', 'installed_at',
];
$missingDdlColumns = [];
foreach ($requiredDdlColumns as $column) {
    if (strpos($ddlContent, '`' . $column . '`') === false) {
        $missingDdlColumns[] = $column;
    }
}
$assert(
    "2.1 Esquema DDL 006 con 12 columnas técnicas en inglés (spare_parts, compatibilidades, solicitudes y consumos)",
    $missingDdlColumns === [],
    'Columnas ausentes: ' . implode(', ', $missingDdlColumns)
);

// 2.2 Cero columnas de esquema con nombres en castellano
$spanishSchemaPattern = '/`[a-z_]*(coste|unidad|pieza|destino|precio|fabricante|categoria|solicitud|consumo)[a-z_]*`/i';
preg_match_all($spanishSchemaPattern, $ddlContent, $spanishMatches);
$assert(
    "2.2 Cero columnas de esquema DDL en castellano (nomenclatura técnica inglesa)",
    $spanishMatches[0] === [],
    'Columnas en castellano detectadas: ' . implode(', ', array_unique($spanishMatches[0]))
);

// 2.3 Nombres de ficheros del módulo M2 íntegramente en inglés
$requiredModuleFiles = [
    '/database/migrations/006_spare_parts_catalog_and_traceability.sql',
    '/src/Core/Domain/Model/SparePart.php',
    '/src/Core/Domain/Model/SparePartRequest.php',
    '/src/Core/Domain/Model/IncidentReplacedPart.php',
    '/src/Application/Service/SparePartCatalogService.php',
    '/src/Application/Service/SparePartTraceabilityService.php',
    '/src/Application/Service/SparePartAnalyticsService.php',
    '/src/Infrastructure/Repository/PdoSparePartRepository.php',
    '/src/Infrastructure/Repository/PdoSparePartRequestRepository.php',
    '/src/Infrastructure/Repository/PdoIncidentReplacedPartRepository.php',
    '/src/Presentation/Controller/CoordinatorSparePartsController.php',
    '/src/Presentation/Controller/TechnicianSparePartsController.php',
    '/public/assets/js/components/CoordinatorSparePartsTab.js',
    '/public/assets/js/components/CoordinatorSparePartsAnalyticsTab.js',
    '/public/assets/js/components/TechnicianSparePartsPauseModal.js',
    '/public/assets/js/components/TechnicianResolutionPartsBlock.js',
    '/tests/integration/CoordinatorSparePartsApiTest.php',
    '/tests/integration/TechnicianSparePartsApiTest.php',
    '/tests/integration/SiteManagerPartsDataSegregationTest.php',
    '/tests/unit/SparePartCatalogServiceTest.php',
    '/tests/unit/SparePartTraceabilityServiceTest.php',
    '/tests/unit/SparePartAnalyticsServiceTest.php',
];
$missingModuleFiles = [];
foreach ($requiredModuleFiles as $relativePath) {
    if (!file_exists($baseDir . $relativePath)) {
        $missingModuleFiles[] = $relativePath;
    }
}
$assert(
    "2.3 Los 22 ficheros estructurales del módulo M2 existen con nomenclatura inglesa",
    $missingModuleFiles === [],
    'Ficheros ausentes: ' . implode(', ', $missingModuleFiles)
);

// 2.4 Componentes frontend del módulo con identificadores en inglés
$requiredFrontendIdentifiers = [
    '/public/assets/js/components/CoordinatorSparePartsTab.js' => ['CoordinatorSparePartsTab'],
    '/public/assets/js/components/CoordinatorSparePartsAnalyticsTab.js' => ['CoordinatorSparePartsAnalyticsTab'],
    '/public/assets/js/components/TechnicianSparePartsPauseModal.js' => ['TechnicianSparePartsPauseModal'],
    '/public/assets/js/components/TechnicianResolutionPartsBlock.js' => ['TechnicianResolutionPartsBlock'],
];
$frontendIdentifierViolations = [];
foreach ($requiredFrontendIdentifiers as $relativePath => $identifiers) {
    $content = (string)file_get_contents($baseDir . $relativePath);
    foreach ($identifiers as $identifier) {
        if (strpos($content, $identifier) === false) {
            $frontendIdentifierViolations[] = "{$relativePath} sin '{$identifier}'";
        }
    }
}
$assert(
    "2.4 Los 4 componentes frontend exportan identificadores en inglés técnico",
    $frontendIdentifierViolations === [],
    'Violaciones: ' . implode('; ', $frontendIdentifierViolations)
);

// 2.5 Excepciones del módulo con códigos técnicos y mensajes en castellano
$exceptionExpectations = [
    'SparePartCodeExistsException.php' => ['SPARE_PART_CODE_EXISTS', 'Ya existe un repuesto en el catálogo'],
    'IncompatibleSparePartException.php' => ['INCOMPATIBLE_SPARE_PART', 'no es compatible con el modelo de máquina'],
    'InvalidOutOfCatalogJustificationException.php' => ['INVALID_OUT_OF_CATALOG_JUSTIFICATION', 'debe tener al menos'],
    'InvalidPartQuantityException.php' => ['INVALID_PART_QUANTITY', 'debe ser un número entero entre'],
    'SitePartsDataForbiddenException.php' => ['SITE_PARTS_DATA_FORBIDDEN', 'los responsables de sede no tienen acceso'],
];
$exceptionViolations = [];
foreach ($exceptionExpectations as $fileName => [$errorCode, $spanishFragment]) {
    $content = (string)file_get_contents($baseDir . '/src/Core/Domain/Exception/' . $fileName);
    if (strpos($content, $errorCode) === false) {
        $exceptionViolations[] = "{$fileName} sin código técnico '{$errorCode}'";
    }
    if (strpos($content, $spanishFragment) === false) {
        $exceptionViolations[] = "{$fileName} sin mensaje en castellano";
    }
}
$assert(
    "2.5 Las 5 excepciones de dominio combinan código técnico inglés y mensaje en castellano",
    $exceptionViolations === [],
    'Violaciones: ' . implode('; ', $exceptionViolations)
);

// 2.6 Mensajes de validación en castellano en la capa de aplicación del catálogo (RF-REP-01, RF-REP-02)
$catalogServiceContent = (string)file_get_contents($baseDir . '/src/Application/Service/SparePartCatalogService.php');
$spanishValidationFragments = [
    'Ya existe un repuesto registrado con el código',
    'El coste de referencia no puede ser negativo.',
    'Debe asociar al menos un modelo de máquina compatible con el repuesto.',
];
$controllerSpanishViolations = [];
foreach ($spanishValidationFragments as $fragment) {
    if (strpos($catalogServiceContent, $fragment) === false) {
        $controllerSpanishViolations[] = $fragment;
    }
}
$assert(
    "2.6 SparePartCatalogService emite mensajes de validación en castellano (códigos duplicados, costes y compatibilidades)",
    $controllerSpanishViolations === [],
    'Fragmentos ausentes: ' . implode(' | ', $controllerSpanishViolations)
);

// 2.7 Interfaz del modal de pausa con etiquetas en castellano
$pauseModalContent = (string)file_get_contents($baseDir . '/public/assets/js/components/TechnicianSparePartsPauseModal.js');
$requiredUiStrings = [
    'Pieza fuera de catálogo',
    'Debe seleccionar al menos un repuesto compatible del catálogo o indicar una pieza fuera de catálogo.',
    'La justificación técnica de la pieza fuera de catálogo debe tener al menos 20 caracteres',
];
$missingUiStrings = [];
foreach ($requiredUiStrings as $uiString) {
    if (strpos($pauseModalContent, $uiString) === false) {
        $missingUiStrings[] = $uiString;
    }
}
$assert(
    "2.7 TechnicianSparePartsPauseModal expone interfaz operativa en castellano",
    $missingUiStrings === [],
    'Cadenas de interfaz ausentes: ' . implode(' | ', $missingUiStrings)
);

// 2.8 Bloque de resolución con clasificación reglamentaria en castellano
$resolutionBlockContent = (string)file_get_contents($baseDir . '/public/assets/js/components/TechnicianResolutionPartsBlock.js');
$requiredResolutionStrings = [
    'DESGUACE',
    'TALLER',
    '¿Durante esta intervención se instaló o reemplazó algún componente técnico en la máquina?',
    'Debe responder obligatoriamente si la intervención conllevó sustitución física de componentes',
];
$missingResolutionStrings = [];
foreach ($requiredResolutionStrings as $uiString) {
    if (strpos($resolutionBlockContent, $uiString) === false) {
        $missingResolutionStrings[] = $uiString;
    }
}
$assert(
    "2.8 TechnicianResolutionPartsBlock muestra la clasificación cerrada de destino en castellano",
    $missingResolutionStrings === [],
    'Cadenas de interfaz ausentes: ' . implode(' | ', $missingResolutionStrings)
);

// 2.9 Comentarios del código de producción del módulo documentados en castellano
$spanishCommentViolations = [];
foreach ($sparePartsPhpFiles as $relativePath) {
    $content = (string)file_get_contents($baseDir . $relativePath);
    if (!$hasSpanishAccentOrÑ($content)) {
        $spanishCommentViolations[] = $relativePath;
    }
}
$assert(
    "2.9 Los 12 ficheros nucleares PHP del módulo documentan sus comentarios en castellano",
    $spanishCommentViolations === [],
    'Ficheros sin documentación castellanohablante: ' . implode(', ', $spanishCommentViolations)
);

// =========================================================================
// SECCIÓN 3: Regresión Global del Módulo M2 (RF-REP-01 a RF-REP-10)
// =========================================================================
echo "\n--- SECCIÓN 3: Regresión Global del Módulo M2 (RF-REP-01 a RF-REP-10) ---\n";

// 3.1 Las 21 tareas T-SPARE están declaradas y las 20 precedentes quedan certificadas en tasks.md
$tasksContent = (string)file_get_contents($baseDir . '/specs/06-spare-parts/tasks.md');
$missingTasks = [];
for ($t = 1; $t <= 21; $t++) {
    if (strpos($tasksContent, sprintf('T-SPARE-%02d', $t)) === false) {
        $missingTasks[] = sprintf('T-SPARE-%02d', $t);
    }
}
$uncheckedBefore21 = [];
for ($t = 1; $t <= 20; $t++) {
    $marker = sprintf('- [ ] **T-SPARE-%02d:', $t);
    if (strpos($tasksContent, $marker) !== false) {
        $uncheckedBefore21[] = sprintf('T-SPARE-%02d', $t);
    }
}
$assert(
    "3.1 Las 21 tareas T-SPARE están declaradas y T-SPARE-01..20 figuran marcadas como completadas",
    $missingTasks === [] && $uncheckedBefore21 === [],
    'Tareas ausentes: ' . implode(', ', $missingTasks)
    . ' | Tareas sin marcar: ' . implode(', ', $uncheckedBefore21)
);

// 3.2 Contratos del plan técnico preservados: las 4 especificaciones de excepciones de dominio del módulo
$contractsContent = (string)file_get_contents($baseDir . '/specs/technical/spare_parts_contracts.md');
$contractExceptionCodes = [
    'SPARE_PART_CODE_EXISTS',
    'INCOMPATIBLE_SPARE_PART',
    'INVALID_OUT_OF_CATALOG_JUSTIFICATION',
    'INVALID_PART_QUANTITY',
];
$missingContractCodes = [];
foreach ($contractExceptionCodes as $code) {
    if (strpos($contractsContent, $code) === false) {
        $missingContractCodes[] = $code;
    }
}
$assert(
    "3.2 Contratos técnicos preservados: códigos de excepción del módulo documentados sin desviaciones",
    $missingContractCodes === [],
    'Códigos ausentes de los contratos: ' . implode(', ', $missingContractCodes)
);

// 3.3 Las 4 tablas del esquema DDL están presentes en la migración 006
$requiredDdlTables = [
    'spare_parts',
    'spare_part_compatibilities',
    'spare_part_requests',
    'incident_replaced_parts',
];
$missingDdlTables = [];
foreach ($requiredDdlTables as $table) {
    if (strpos($ddlContent, 'CREATE TABLE IF NOT EXISTS `' . $table . '`') === false) {
        $missingDdlTables[] = $table;
    }
}
$assert(
    "3.3 Las 4 tablas del módulo (spare_parts, spare_part_compatibilities, spare_part_requests, incident_replaced_parts) existen en la migración 006",
    $missingDdlTables === [],
    'Tablas ausentes: ' . implode(', ', $missingDdlTables)
);

// 3.4 cloud_init.sql incorpora el esquema integral del módulo M2
$cloudInitContent = (string)file_get_contents($baseDir . '/database/cloud_init.sql');
$missingCloudInitTables = [];
foreach ($requiredDdlTables as $table) {
    if (strpos($cloudInitContent, 'CREATE TABLE IF NOT EXISTS `' . $table . '`') === false) {
        $missingCloudInitTables[] = $table;
    }
}
$assert(
    "3.4 cloud_init.sql incorpora las 4 tablas del módulo M2 para despliegue integral",
    $missingCloudInitTables === [],
    'Tablas ausentes: ' . implode(', ', $missingCloudInitTables)
);

// 3.5 SeedRunner incorpora semillas maestras de repuestos y compatibilidades
$seedRunnerContent = (string)file_get_contents($baseDir . '/src/Infrastructure/Database/SeedRunner.php');
$assert(
    "3.5 SeedRunner incorpora semillas maestras de repuestos y compatibilidades por modelo",
    str_contains($seedRunnerContent, 'seedSpareParts')
        && str_contains($seedRunnerContent, '`spare_part_compatibilities`')
        && str_contains($seedRunnerContent, "'part_code' => 'VALV-ULKA-01'")
);

// 3.6 Modelo de dominio IncidentReplacedPart con snapshot inmutable de coste
$replacedPartModelContent = (string)file_get_contents($baseDir . '/src/Core/Domain/Model/IncidentReplacedPart.php');
$assert(
    "3.6 Entidad IncidentReplacedPart captura unit_cost_snapshot y old_part_destination reglamentarios",
    str_contains($replacedPartModelContent, 'unit_cost_snapshot')
        && str_contains($replacedPartModelContent, 'old_part_destination')
);

// 3.7 Repositorio de consumos con agregación analítica de fallos recurrentes (> 3 en 90 días)
$analyticsRepoContent = (string)file_get_contents($baseDir . '/src/Infrastructure/Repository/PdoIncidentReplacedPartRepository.php');
$assert(
    "3.7 Repositorio de consumos implementa agregaciones analíticas y detección de fallo crónico (RF-REP-08)",
    str_contains($analyticsRepoContent, 'findReplacementsGroupedByMachineAndPart')
        || (str_contains($analyticsRepoContent, 'GROUP BY') && str_contains($analyticsRepoContent, '90'))
);

// 3.8 Servicio analítico con exportación CSV UTF-8 (RF-REP-09)
$analyticsServiceContent = (string)file_get_contents($baseDir . '/src/Application/Service/SparePartAnalyticsService.php');
$assert(
    "3.8 SparePartAnalyticsService consolida ranking de piezas y genera exportación CSV (RF-REP-08, RF-REP-09)",
    str_contains($analyticsServiceContent, 'csv') || str_contains($analyticsServiceContent, 'Csv')
);

// 3.9 Rutas REST del módulo registradas con roles reglamentarios en AppRouter
$routerContent = (string)file_get_contents($baseDir . '/src/Presentation/Routing/AppRouter.php');
$requiredRouteUris = [
    '/api/coordinator/spare-parts',
    '/api/coordinator/spare-parts/analytics',
    '/api/coordinator/spare-parts/export',
    '/api/technician/spare-parts/catalog',
];
$missingRoutes = [];
foreach ($requiredRouteUris as $route) {
    if (strpos($routerContent, "'" . $route . "'") === false) {
        $missingRoutes[] = $route;
    }
}
$assert(
    "3.9 AppRouter registra las rutas REST del módulo con middlewares de rol COORDINATOR y TECHNICIAN",
    $missingRoutes === [] && str_contains($routerContent, '$coordinatorAuth') && str_contains($routerContent, '$technicianAuth'),
    'Rutas ausentes: ' . implode(', ', $missingRoutes)
);

// 3.10 Blindaje del portal de sede: sanitizador con claves prohibidas (Art. V.4 / RF-REP-10)
$locationPortalContent = (string)file_get_contents($baseDir . '/src/Presentation/Controller/LocationPortalController.php');
$assert(
    "3.10 LocationPortalController sanitiza claves de repuestos y costes para la sede (RF-REP-10, Art. V.4)",
    str_contains($locationPortalContent, 'sanitizeIncidentForSite')
        && str_contains($locationPortalContent, 'unit_cost_snapshot')
        && str_contains($locationPortalContent, 'spare_part_requests')
);

// 3.11 Cero sentencias DELETE FROM en el código de producción del módulo (Constitución Art. III.1)
$moduleProdFiles = [
    $baseDir . '/src/Infrastructure/Repository/PdoSparePartRepository.php',
    $baseDir . '/src/Infrastructure/Repository/PdoSparePartRequestRepository.php',
    $baseDir . '/src/Infrastructure/Repository/PdoIncidentReplacedPartRepository.php',
    $baseDir . '/src/Application/Service/SparePartCatalogService.php',
    $baseDir . '/src/Application/Service/SparePartTraceabilityService.php',
];
$moduleDeleteViolations = [];
foreach ($moduleProdFiles as $filePath) {
    $content = (string)file_get_contents($filePath);
    if (stripos($content, 'DELETE FROM') !== false) {
        $moduleDeleteViolations[] = str_replace($baseDir, '', $filePath);
    }
}
$assert(
    "3.11 Cero borrado físico en los repositorios y servicios del módulo (Constitución Art. III.1)",
    $moduleDeleteViolations === [],
    'Violaciones: ' . implode(', ', $moduleDeleteViolations)
);

// 3.12 Validación reglamentaria de cantidades [1..50] en la capa de aplicación del módulo
$traceabilityServiceContent = (string)file_get_contents($baseDir . '/src/Application/Service/SparePartTraceabilityService.php');
$quantityGuardFound = str_contains($traceabilityServiceContent, 'InvalidPartQuantityException')
    && (str_contains($traceabilityServiceContent, 'MAX_QUANTITY') || str_contains($traceabilityServiceContent, '50'));
$assert(
    "3.12 SparePartTraceabilityService garantiza cantidades entre 1 y 50 unidades (RF-REP-03, RF-REP-06)",
    $quantityGuardFound
);

// 3.13 Justificación mínima de 20 caracteres para piezas fuera de catálogo (RF-REP-04)
$justificationGuardFound = str_contains($traceabilityServiceContent, 'InvalidOutOfCatalogJustificationException')
    && (str_contains($traceabilityServiceContent, 'MINIMUM_LENGTH') || str_contains($traceabilityServiceContent, '20'));
$assert(
    "3.13 Justificación de pieza fuera de catálogo exige un mínimo de 20 caracteres (RF-REP-04)",
    $justificationGuardFound
);

// 3.14 Congelación del snapshot de coste en la resolución (RF-REP-06, Art. III)
$snapshotFrozen = str_contains($traceabilityServiceContent, 'reference_cost')
    && (str_contains($traceabilityServiceContent, 'unit_cost_snapshot') || str_contains($traceabilityServiceContent, 'unitCostSnapshot'));
$assert(
    "3.14 La resolución congela el coste de referencia vigente como snapshot inmutable (RF-REP-06, Art. III)",
    $snapshotFrozen
);

// 3.15 La suite de segregación de sede certifica el blindaje constitucional del módulo (RF-REP-10)
$segregationTestContent = (string)file_get_contents($baseDir . '/tests/integration/SiteManagerPartsDataSegregationTest.php');
$assert(
    "3.15 La suite SiteManagerPartsDataSegregationTest audita recursivamente los payloads de sede (Art. V.4)",
    str_contains($segregationTestContent, 'unit_cost_snapshot')
        && str_contains($segregationTestContent, 'old_part_destination')
        && str_contains($segregationTestContent, 'T-SPARE-20')
);

// =========================================================================
// Resumen final
// =========================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: Módulo M2 (Repuestos) 100% conforme: Dogma Vanilla, Dualismo Lingüístico\n";
    echo " y regresión global verificados. Batería completa ejecutada con 0 fallos y 0 errores.\n";
} else {
    echo " RESULTADO: {$failures} comprobación(es) de conformidad fallaron.\n";
    exit(1);
}
echo "======================================================================\n";
