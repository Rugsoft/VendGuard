<?php

declare(strict_types=1);

/**
 * AuditFindingsClosureTest
 *
 * Guarda de cierre de los hallazgos de seguridad y arquitectura del informe de auditoría
 * (`docs/auditoria_arquitectura.md`), según el triaje aprobado en
 * `specs/technical/auditoria_arquitectura_triage.md`:
 *
 * - **S-1** Secretos por entorno con fallo en cerrado (`SecretProvider`).
 * - **S-2** Middleware de autenticación del proceso cron + controlador fail-closed.
 * - **H-1** `src/Core/` sin dependencias de `Infrastructure` (servicios QR en `Application`).
 * - **H-3** Sin alias `class_alias()` de servicios en `src/Core/Domain/Service/`.
 *
 * Las aserciones estructurales evitan que una refactorización futura reabra el hallazgo por
 * descuido; las conductuales demuestran el comportamiento (clave histórica muerta, 401 sin
 * credencial, marca del middleware).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Presentation\Controller\CronController;
use VendGuard\Presentation\Http\Middleware\CronAuthMiddleware;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;
use VendGuard\Presentation\Routing\Router;

echo "======================================================================\n";
echo " VendGuard: Cierre de Hallazgos de Auditoría (S-1, S-2, H-1, H-3)\n";
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

// Las claves históricas que vivían en el repositorio. Si alguna vuelve al código, el
// hallazgo S-1 está reabierto: cualquiera con el repositorio puede firmar tokens válidos.
$legacyAuthKey = 'vendguard-secret-key-change-in-production-rf04';
$legacyCronKey = 'vendguard-cron-secret-key-2026';

// ── Entorno controlado: se guarda y se restaura al final ──────────────────────
$envNames = ['SECRET_KEY', 'CRON_SECRET', 'APP_ENV', 'VENDGUARD_ENV'];
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
// GRUPO 1: S-1 — Secretos por entorno con fallo en cerrado
// =====================================================================
echo "--- Grupo 1: S-1 SecretProvider (sin claves utilizables en el repositorio) ---\n";

// 1.1 La variable de entorno tiene prioridad absoluta.
$setEnv('SECRET_KEY', 'clave-de-entorno-de-prueba');
$assert(
    "1.1 SECRET_KEY se lee del entorno cuando existe",
    SecretProvider::authSecret() === 'clave-de-entorno-de-prueba'
);

// 1.2 Fuera de producción y sin variable, se usa la clave exclusiva de desarrollo.
$unsetEnv('SECRET_KEY');
$assert(
    "1.2 Sin SECRET_KEY, fuera de producción se usa la clave de desarrollo",
    SecretProvider::authSecret() === SecretProvider::DEV_AUTH_SECRET,
    'Recibido: ' . SecretProvider::authSecret()
);

// 1.3 La clave de desarrollo no es la histórica del repositorio.
$assert(
    "1.3 La clave de desarrollo es distinta de la clave histórica (queda invalidada)",
    SecretProvider::DEV_AUTH_SECRET !== $legacyAuthKey
        && SecretProvider::DEV_CRON_SECRET !== $legacyCronKey
);

// 1.4 CRON_SECRET respeta el mismo contrato.
$setEnv('CRON_SECRET', 'secreto-cron-de-prueba');
$assert(
    "1.4 CRON_SECRET se lee del entorno cuando existe",
    SecretProvider::cronSecret() === 'secreto-cron-de-prueba'
);
$unsetEnv('CRON_SECRET');
$assert(
    "1.5 Sin CRON_SECRET, fuera de producción se usa el secreto de desarrollo",
    SecretProvider::cronSecret() === SecretProvider::DEV_CRON_SECRET
);

// 1.6 Fallo en cerrado en producción: sin variable, excepción.
$thrownAuth = null;
$thrownCron = null;
$setEnv('APP_ENV', 'production');
try {
    SecretProvider::authSecret();
} catch (RuntimeException $e) {
    $thrownAuth = $e;
}
try {
    SecretProvider::cronSecret();
} catch (RuntimeException $e) {
    $thrownCron = $e;
}
$assert(
    "1.6 En producción y sin SECRET_KEY/CRON_SECRET la resolución lanza excepción (fail-closed)",
    $thrownAuth instanceof RuntimeException
        && $thrownCron instanceof RuntimeException
        && str_contains($thrownAuth->getMessage(), 'SECRET_KEY')
        && str_contains($thrownCron->getMessage(), 'CRON_SECRET'),
    sprintf('auth=%s cron=%s', $thrownAuth?->getMessage() ?? 'sin excepción', $thrownCron?->getMessage() ?? 'sin excepción')
);

// 1.7 En producción con variable definida, el sistema arranca con ella.
$setEnv('SECRET_KEY', 'clave-de-produccion-de-prueba');
$assert(
    "1.7 En producción con SECRET_KEY definida, se usa la variable de entorno",
    SecretProvider::authSecret() === 'clave-de-produccion-de-prueba'
);
$assert(
    "1.8 isProduction() reconoce APP_ENV=production y VENDGUARD_ENV=prod",
    SecretProvider::isProduction() === true
);
$setEnv('VENDGUARD_ENV', 'prod');
$unsetEnv('APP_ENV');
$assert(
    "1.9 VENDGUARD_ENV=prod también activa el modo producción",
    SecretProvider::isProduction() === true
);
$unsetEnv('VENDGUARD_ENV');
$unsetEnv('SECRET_KEY');
$assert(
    "1.10 Sin declaración de entorno, el sistema opera en modo desarrollo",
    SecretProvider::isProduction() === false
);

// 1.11 Regresión conductual: la clave histórica ya no valida tokens.
$legacySigner = new AuthService(null, null, $legacyAuthKey);
$currentSigner = new AuthService();
$user = new User(1, 'Coordinación de Prueba', 'coordinacion.prueba@vendguard.internal', 'hash', UserRole::COORDINATOR);

$legacyToken = $legacySigner->generateInternalToken($user);
$currentToken = $currentSigner->generateInternalToken($user);
$assert(
    "1.11 Un token firmado con la clave histórica del repositorio es rechazado",
    $currentSigner->validateInternalToken($legacyToken) === null
);
$assert(
    "1.12 Un token firmado con la clave vigente sigue siendo válido",
    $currentSigner->validateInternalToken($currentToken) !== null
);

// 1.13 Guarda estructural: los literales históricos no están en el código de producción.
$sourceRoots = [$projectRoot . '/src', $projectRoot . '/public'];
$legacyHits = [];
foreach ($sourceRoots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'js'], true)) {
            continue;
        }
        $content = (string)file_get_contents($file->getPathname());
        if (str_contains($content, $legacyAuthKey) || str_contains($content, $legacyCronKey)) {
            $legacyHits[] = str_replace($projectRoot . '/', '', $file->getPathname());
        }
    }
}
$assert(
    "1.13 Ningún fichero PHP/JS de src/ o public/ contiene las claves históricas",
    $legacyHits === [],
    'Encontradas en: ' . implode(', ', $legacyHits)
);

// =====================================================================
// GRUPO 2: S-2 — Middleware del cron y controlador fail-closed
// =====================================================================
echo "\n--- Grupo 2: S-2 Autenticación del proceso cron en middleware ---\n";

// 2.1 La ruta se registra con middleware (ya no es una ruta "desnuda").
$routerReflection = new ReflectionClass(Router::class);
$routesProperty = $routerReflection->getProperty('routes');
$routesProperty->setAccessible(true);
$registeredRoutes = $routesProperty->getValue(AppRouter::create());

$cronRoute = null;
foreach ($registeredRoutes['POST'] ?? [] as $route) {
    if ($route['pattern'] === '/api/cron/auto-close') {
        $cronRoute = $route;
        break;
    }
}
$cronMiddleware = $cronRoute['middlewares'] ?? [];
$assert(
    "2.1 POST /api/cron/auto-close está registrada con middleware",
    is_array($cronRoute) && count($cronMiddleware) === 1 && $cronMiddleware[0] instanceof CronAuthMiddleware,
    is_array($cronRoute) ? 'Middlewares registrados: ' . count($cronMiddleware) : 'Ruta no encontrada'
);

// 2.2 El middleware marca la petición y deja pasar con credencial válida.
$middleware = new CronAuthMiddleware(null, 'secreto-cron-de-prueba');
$nextCalled = false;
$next = function (Request $request) use (&$nextCalled) {
    $nextCalled = true;
    return \VendGuard\Presentation\Http\Response::json(['ok' => true], 200);
};
$authorizedRequest = new Request(
    method: 'POST',
    path: '/api/cron/auto-close',
    headers: ['X-Cron-Secret' => 'secreto-cron-de-prueba']
);
$authorizedResponse = $middleware->handle($authorizedRequest, $next);
$assert(
    "2.2 El middleware autoriza con X-Cron-Secret válido y marca la petición",
    $nextCalled === true
        && $authorizedResponse->getStatusCode() === 200
        && $authorizedRequest->getAttribute('cron_authenticated') === true
);

// 2.3 El middleware corta con credencial inválida y no delega.
$nextCalled = false;
$rejectedRequest = new Request(
    method: 'POST',
    path: '/api/cron/auto-close',
    headers: ['X-Cron-Secret' => 'secreto-incorrecto']
);
$rejectedResponse = $middleware->handle($rejectedRequest, $next);
$assert(
    "2.3 El middleware rechaza con 401 sin delegar cuando la credencial no es válida",
    $nextCalled === false
        && $rejectedResponse->getStatusCode() === 401
        && ($rejectedResponse->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED'
);

// 2.4 El controlador es fail-closed: sin la marca del middleware no ejecuta el proceso.
$controller = new CronController(new PdoIncidentRepository());
$failClosedRequest = new Request(
    method: 'POST',
    path: '/api/cron/auto-close',
    headers: ['X-Cron-Secret' => 'secreto-cron-de-prueba']
);
$failClosedResponse = $controller->autoClose($failClosedRequest);
$assert(
    "2.4 CronController sin la marca del middleware responde 401 aunque el secreto sea válido",
    $failClosedResponse->getStatusCode() === 401
        && ($failClosedResponse->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED'
);

// 2.5 Con la marca, el controlador ejecuta el caso de uso y devuelve el contrato.
$stubRepo = new class extends PdoIncidentRepository {
    public function __construct()
    {
    }

    public function autoCloseResolvedIncidents(int $hours = 48): array
    {
        return [];
    }
};
$authorizedController = new CronController($stubRepo);
$stubRequest = new Request(method: 'POST', path: '/api/cron/auto-close');
$stubRequest->setAttribute('cron_authenticated', true);
$stubResponse = $authorizedController->autoClose($stubRequest);
$stubBody = $stubResponse->getDecodedBody();
$assert(
    "2.5 Con la marca del middleware, el controlador ejecuta el auto-cierre y devuelve closed_count/closed_tickets",
    $stubResponse->getStatusCode() === 200
        && ($stubBody['data']['closed_count'] ?? null) === 0
        && ($stubBody['data']['closed_tickets'] ?? null) === []
);

// =====================================================================
// GRUPO 3: H-1 — Core sin dependencias de Infrastructure
// =====================================================================
echo "\n--- Grupo 3: H-1 Servicios QR en la capa de aplicación ---\n";

$coreInfrastructureHits = [];
$coreIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot . '/src/Core'));
foreach ($coreIterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    if (str_contains((string)file_get_contents($file->getPathname()), 'VendGuard\\Infrastructure')) {
        $coreInfrastructureHits[] = str_replace($projectRoot . '/', '', $file->getPathname());
    }
}
$assert(
    "3.1 Ningún fichero de src/Core/ importa VendGuard\\Infrastructure (regla de dependencia)",
    $coreInfrastructureHits === [],
    'Infractores: ' . implode(', ', $coreInfrastructureHits)
);

foreach (['QrScanService', 'QrReportService', 'QrLabelService'] as $serviceName) {
    $applicationPath = $projectRoot . '/src/Application/Service/' . $serviceName . '.php';
    $corePath = $projectRoot . '/src/Core/Service/' . $serviceName . '.php';
    $content = is_file($applicationPath) ? (string)file_get_contents($applicationPath) : '';
    $assert(
        "3.2 {$serviceName} vive en Application/Service, ya no en Core/Service, y declara su espacio de nombres",
        is_file($applicationPath)
            && !is_file($corePath)
            && str_contains($content, 'namespace VendGuard\\Application\\Service;')
    );
}

$oldNamespaceHits = [];
$scanRoots = [$projectRoot . '/src', $projectRoot . '/tests'];
foreach ($scanRoots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $content = (string)file_get_contents($file->getPathname());
        foreach (['QrScanService', 'QrReportService', 'QrLabelService'] as $serviceName) {
            if (str_contains($content, 'Core\\Service\\' . $serviceName)) {
                $oldNamespaceHits[] = str_replace($projectRoot . '/', '', $file->getPathname()) . " ({$serviceName})";
            }
        }
    }
}
$assert(
    "3.3 Ningún consumidor referencia el espacio de nombres antiguo Core\\Service\\Qr*",
    $oldNamespaceHits === [],
    'Referencias: ' . implode(', ', $oldNamespaceHits)
);

// =====================================================================
// GRUPO 4: H-3 — Sin alias de servicios
// =====================================================================
echo "\n--- Grupo 4: H-3 Una única ruta canónica por servicio ---\n";

$aliasFiles = [];
$aliasIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot . '/src/Core/Domain/Service'));
foreach ($aliasIterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    if (str_contains((string)file_get_contents($file->getPathname()), 'class_alias')) {
        $aliasFiles[] = basename($file->getPathname());
    }
}
$assert(
    "4.1 No queda ningún class_alias() en src/Core/Domain/Service/",
    $aliasFiles === [],
    'Alias restantes: ' . implode(', ', $aliasFiles)
);

foreach (['UrgencyCalculator', 'ResolutionValidator', 'IncidentStateMachine'] as $serviceName) {
    $canonicalPath = $projectRoot . '/src/Core/Service/' . $serviceName . '.php';
    $aliasPath = $projectRoot . '/src/Core/Domain/Service/' . $serviceName . '.php';
    $assert(
        "4.2 {$serviceName} tiene una sola implementación canónica en Core/Service",
        is_file($canonicalPath) && !is_file($aliasPath)
    );
}

$aliasReferenceHits = [];
$scanRoots = [$projectRoot . '/src', $projectRoot . '/tests'];
foreach ($scanRoots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $content = (string)file_get_contents($file->getPathname());
        foreach (['UrgencyCalculator', 'ResolutionValidator', 'IncidentStateMachine'] as $serviceName) {
            if (str_contains($content, 'Core\\Domain\\Service\\' . $serviceName)) {
                $aliasReferenceHits[] = str_replace($projectRoot . '/', '', $file->getPathname()) . " ({$serviceName})";
            }
        }
    }
}
$assert(
    "4.3 Ningún fichero referencia el espacio de nombres eliminado Core\\Domain\\Service\\{servicios}",
    $aliasReferenceHits === [],
    'Referencias: ' . implode(', ', $aliasReferenceHits)
);

// 4.4 El alias de MachineType queda fuera del alcance de H-3 y sigue disponible.
$machineTypeAlias = (string)file_get_contents($projectRoot . '/src/Core/Domain/ValueObject/MachineType.php');
$assert(
    "4.4 El alias de MachineType (usado por tests, fuera del alcance de H-3) permanece intacto",
    str_contains($machineTypeAlias, 'class_alias')
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
    echo " RESULTADO: 100% EN VERDE. HALLAZGOS S-1, S-2, H-1 y H-3 CERRADOS.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
