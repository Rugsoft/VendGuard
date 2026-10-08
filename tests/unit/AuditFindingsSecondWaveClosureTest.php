<?php

declare(strict_types=1);

/**
 * AuditFindingsSecondWaveClosureTest
 *
 * Guarda de cierre de la segunda tanda de hallazgos del informe de auditoría arquitectónica,
 * según el triaje aprobado en `specs/technical/auditoria_arquitectura_triage.md` §6:
 *
 * - **S-5** Credenciales de base de datos: en producción no se admite el acceso administrativo
 *   implícito (`root` sin contraseña); en desarrollo se conserva el XAMPP local.
 * - **H-5** Paginación de preventivos con placeholders vinculados como enteros.
 * - **T-1** El plan técnico describe el mecanismo real de privacidad (RNF-04) en lugar de
 *   artefactos que nunca existieron, y el endpoint especulado no está en el router.
 * - **§5.2** El informe de cierre constitucional es un acta histórica fechada, no una
 *   declaración de conformidad vigente.
 * - **§6** Existe el trinquete que congela la deuda de colores hardcodeados.
 * - **V-6** La sesión de sede dura 24 h (EARS 1.3) tras la decisión de Product Owner del
 *   2026-10-08; el parámetro de TTL explícito sigue disponible y el valor de 7 días no vuelve.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoPreventiveOrderRepository;
use VendGuard\Presentation\Routing\AppRouter;
use VendGuard\Presentation\Routing\Router;

echo "======================================================================\n";
echo " VendGuard: Cierre de Hallazgos - Segunda Tanda (S-5, H-5, V-6, T-1, §5.2, §6)\n";
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

$projectRoot = dirname(__DIR__, 2);

// ── Entorno controlado: se guarda y se restaura al final ──────────────────────
$envNames = [
    'APP_ENV', 'VENDGUARD_ENV',
    'DATABASE_URL', 'MYSQL_URL',
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_DATABASE',
    'DB_USER', 'DB_USERNAME', 'DB_PASS', 'DB_PASSWORD', 'DB_SSL',
];
$savedEnv = [];
foreach ($envNames as $envName) {
    $savedEnv[$envName] = getenv($envName);
}
$setEnv = function (string $name, string $value): void {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
};
$unsetEnv = function (string $name): void {
    putenv($name);
    unset($_ENV[$name]);
};

// =====================================================================
// GRUPO 1: S-5 — Credenciales de base de datos en producción
// =====================================================================
echo "--- Grupo 1: S-5 Credenciales de base de datos (fail-closed en producción) ---\n";

// Escenario de producción sin credenciales explícitas.
$setEnv('APP_ENV', 'production');
foreach (['DATABASE_URL', 'MYSQL_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_DATABASE', 'DB_USER', 'DB_USERNAME', 'DB_PASS', 'DB_PASSWORD', 'DB_SSL'] as $envName) {
    $unsetEnv($envName);
}

$implicitFailure = null;
try {
    ConnectionFactory::getConnection([], true);
} catch (RuntimeException $e) {
    $implicitFailure = $e;
}
$assert(
    "1.1 En producción sin credenciales explícitas la conexión falla antes de conectar (S-5.1)",
    $implicitFailure instanceof RuntimeException
        && str_contains($implicitFailure->getMessage(), 'insegura en producción')
        && str_contains($implicitFailure->getMessage(), 'DATABASE_URL'),
    $implicitFailure === null ? 'No lanzó excepción' : $implicitFailure->getMessage()
);

$rootFailure = null;
try {
    ConnectionFactory::getConnection(['user' => 'root', 'password' => ''], true);
} catch (RuntimeException $e) {
    $rootFailure = $e;
}
$assert(
    "1.2 En producción, `root` sin contraseña se rechaza aunque venga en configuración explícita (S-5.2)",
    $rootFailure instanceof RuntimeException
        && str_contains($rootFailure->getMessage(), 'root sin contraseña'),
    $rootFailure === null ? 'No lanzó excepción' : $rootFailure->getMessage()
);

$explicitFailure = null;
try {
    ConnectionFactory::getConnection(['user' => 'vendguard_probe', 'password' => 'probe'], true);
} catch (RuntimeException $e) {
    $explicitFailure = $e;
}
$assert(
    "1.3 Con credenciales explícitas la política no bloquea: el fallo es de conexión, no de configuración (S-5.2)",
    $explicitFailure instanceof RuntimeException
        && !str_contains($explicitFailure->getMessage(), 'insegura en producción')
        && str_contains($explicitFailure->getMessage(), 'Fallo de conexión'),
    $explicitFailure === null ? 'No lanzó excepción' : $explicitFailure->getMessage()
);

// Escenario de desarrollo: la configuración local (XAMPP) sigue funcionando.
$unsetEnv('APP_ENV');
$devConnection = null;
try {
    $devConnection = ConnectionFactory::getConnection([], true);
} catch (RuntimeException $e) {
    $devConnection = null;
}
$assert(
    "1.4 En desarrollo la conexión local por defecto sigue funcionando (S-5.3)",
    $devConnection instanceof PDO,
    'Sin conexión local disponible en este entorno'
);

// =====================================================================
// GRUPO 2: H-5 — Paginación de preventivos con placeholders
// =====================================================================
echo "\n--- Grupo 2: H-5 Paginación con parámetros vinculados ---\n";

$preventiveRepoFile = (string)file_get_contents($projectRoot . '/src/Infrastructure/Repository/PdoPreventiveOrderRepository.php');
$assert(
    "2.1 La consulta usa LIMIT/OFFSET con placeholders vinculados como enteros (H-5.1)",
    str_contains($preventiveRepoFile, 'LIMIT :limit OFFSET :offset')
        && str_contains($preventiveRepoFile, "bindValue(':limit', \$limit, PDO::PARAM_INT)")
        && str_contains($preventiveRepoFile, "bindValue(':offset', \$offset, PDO::PARAM_INT)")
        && !str_contains($preventiveRepoFile, 'LIMIT {')
        && !str_contains($preventiveRepoFile, 'OFFSET {')
);

$preventiveRepo = new PdoPreventiveOrderRepository();

$paged = null;
try {
    $paged = $preventiveRepo->findForCoordinatorList(['limit' => 2, 'offset' => 0]);
} catch (Throwable $e) {
    $paged = null;
}
$assert(
    "2.2 La paginación sin filtros se ejecuta sin error contra MariaDB con prepares nativos (H-5.2)",
    is_array($paged) && count($paged) <= 2,
    'La consulta paginada lanzó una excepción o devolvió algo distinto de un array'
);

$pagedWithFilters = null;
try {
    $pagedWithFilters = $preventiveRepo->findForCoordinatorList(['status' => 'SCHEDULED', 'limit' => 1, 'offset' => 0]);
} catch (Throwable $e) {
    $pagedWithFilters = null;
}
$assert(
    "2.3 La paginación combinada con filtros también se ejecuta sin error (H-5.2)",
    is_array($pagedWithFilters),
    'La consulta paginada con filtros lanzó una excepción'
);

// =====================================================================
// GRUPO 3: T-1 — Alineación del plan técnico con el mecanismo real
// =====================================================================
echo "\n--- Grupo 3: T-1 Privacidad del informador (plan alineado) ---\n";

$planContent = (string)file_get_contents($projectRoot . '/specs/technical/plan.md');
$rnf04Row = '';
foreach (preg_split('/\r?\n/', $planContent) ?: [] as $line) {
    if (str_contains($line, 'RNF-04 (Privacidad Informador)')) {
        $rnf04Row = $line;
        break;
    }
}
$assert(
    "3.1 La fila de RNF-04 cita el mecanismo real y no los artefactos inexistentes (T-1.1)",
    $rnf04Row !== ''
        && str_contains($rnf04Row, 'sanitizeIncidentForSite')
        && str_contains($rnf04Row, 'SiteManagerPartsDataSegregationTest')
        && !str_contains($rnf04Row, 'IncidentTimeline.js')
        // El artefacto inexistente se cita entre backticks; así la guarda no confunde el nombre
        // real `SiteManagerPartsDataSegregationTest.php`, que contiene esa misma subcadena.
        && !str_contains($rnf04Row, '`DataSegregationTest.php`')
        && !str_contains($rnf04Row, 'locations/{code}/incidents')
);

$planNote = str_contains($planContent, 'Nota de vigencia')
    && str_contains($planContent, 'hallazgo T-1')
    && str_contains($planContent, 'trazabilidad');
$assert(
    "3.2 El plan declara que la matriz es el mapa de diseño y cuál es la trazabilidad viva (T-1.1)",
    $planNote
);

// El endpoint especulado no existe y la decisión queda fijada por esta guarda.
$routerReflection = new ReflectionClass(Router::class);
$routesProperty = $routerReflection->getProperty('routes');
$routesProperty->setAccessible(true);
$registeredRoutes = $routesProperty->getValue(AppRouter::create());

$speculatedEndpointExists = false;
foreach ($registeredRoutes as $routesByMethod) {
    foreach ($routesByMethod as $route) {
        if (preg_match('#^/api/locations/\{[^}]+\}/incidents$#', (string)$route['pattern']) === 1) {
            $speculatedEndpointExists = true;
        }
    }
}
$assert(
    "3.3 No existe listado de incidencias por sede en el router: la regla se cubre por saneado (T-1.2)",
    $speculatedEndpointExists === false
);

// =====================================================================
// GRUPO 4: §5.2 — El informe de cierre es un acta histórica
// =====================================================================
echo "\n--- Grupo 4: §5.2 Informe de cierre como acta histórica ---\n";

$reportContent = (string)file_get_contents($projectRoot . '/docs/constitutional_audit_report.md');
$assert(
    "4.1 El informe se declara histórico y fechado, conservando sus cifras originales (§5.2.1)",
    str_contains($reportContent, 'Acta histórica')
        && str_contains($reportContent, 'Documento histórico')
        && str_contains($reportContent, '43 suites')
        && str_contains($reportContent, '906 aserciones')
);

$assert(
    "4.2 El informe apunta a la fuente de verdad viva y no se presenta como conformidad actual (§5.2.2)",
    str_contains($reportContent, 'ConstitutionalAuditTest.php')
        && str_contains($reportContent, 'DocMetricsGuard.php')
        && str_contains($reportContent, 'no debe citarse como declaración de conformidad vigente')
);

// =====================================================================
// GRUPO 5: §6 — Trinquete de deuda de tokens
// =====================================================================
echo "\n--- Grupo 5: §6 Trinquete de colores hardcodeados ---\n";

$ratchetFile = $projectRoot . '/tests/unit/DesignTokenDebtRatchetTest.mjs';
$ratchetContent = is_file($ratchetFile) ? (string)file_get_contents($ratchetFile) : '';
$assert(
    "5.1 El trinquete de deuda de tokens existe y declara un techo numérico (§6.1)",
    $ratchetContent !== ''
        && preg_match('/const CEILING = (\d+);/', $ratchetContent, $ceiling) === 1
        && (int)$ceiling[1] > 0
);

// =====================================================================
// GRUPO 6: V-6 — Sesión de sede de 24 h (decisión de PO, 2026-10-08)
// =====================================================================
echo "\n--- Grupo 6: V-6 TTL de la sesión de sede (EARS 1.3) ---\n";

$authService = new AuthService(null, null, 'clave-de-prueba-v6');
$testLocation = new Location(1, 'SEDE-TEST-01', 'Sede de prueba', 'Calle Falsa 123');

$siteToken = $authService->generateSiteToken($testLocation);
$sitePayload = $authService->validateSiteToken($siteToken);
$siteTtl = ($sitePayload !== null && isset($sitePayload['exp'])) ? ((int)$sitePayload['exp'] - time()) : -1;
$assert(
    "6.1 El token de sede por defecto dura 24 horas (V-6 / EARS 1.3)",
    $siteTtl >= 86395 && $siteTtl <= 86400,
    "TTL medido: {$siteTtl}s"
);

$shortToken = $authService->generateSiteToken($testLocation, 3600);
$shortPayload = $authService->validateSiteToken($shortToken);
$shortTtl = ($shortPayload !== null && isset($shortPayload['exp'])) ? ((int)$shortPayload['exp'] - time()) : -1;
$assert(
    "6.2 El TTL explícito del token de sede sigue respetándose (contrato del parámetro)",
    $shortTtl >= 3595 && $shortTtl <= 3600,
    "TTL medido: {$shortTtl}s"
);

$authServiceFile = (string)file_get_contents($projectRoot . '/src/Application/Service/AuthService.php');
$assert(
    "6.3 La implementación no vuelve a emitir tokens de 7 días (sin 604800)",
    !str_contains($authServiceFile, '604800')
);

// ── Restauración del entorno ─────────────────────────────────────────────────
foreach ($savedEnv as $envName => $envValue) {
    if ($envValue === false) {
        $unsetEnv($envName);
    } else {
        $setEnv($envName, $envValue);
    }
}

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. SEGUNDA TANDA CERRADA (S-5, H-5, V-6, T-1, §5.2, §6).\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
