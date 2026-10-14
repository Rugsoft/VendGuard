<?php

declare(strict_types=1);

/**
 * HealthEndpointTest (T-HYBRID-14)
 *
 * Certificación hermética —motor local, sin red— del endpoint técnico de diagnóstico
 * `GET /api/status` y de la **no regresión** del contrato vivo de `GET /api/health`
 * (RF-07.1 a RF-07.3, RF-07.5, EARS-07.1 a EARS-07.5, Art. V.4, Art. VI.1).
 *
 * QUÉ SE CERTIFICA AQUÍ, Y POR QUÉ ASÍ
 *
 *   1. **La ruta nueva y su proyección pública.** El despacho se ejerce sobre el mismo
 *      enrutador que sirve `public/index.php` (`AppRouter::create()`), no sobre una copia: si
 *      alguien desregistra o re-apunta la ruta, esta suite se pone en rojo. La respuesta
 *      pública se comprueba **por su forma exacta** —`status` y `timestamp_utc`, nada más—,
 *      porque publicar de más es exactamente el fallo que EARS-07.2 prohíbe: el motor, el
 *      agrupador y la latencia sólo viajan con credencial.
 *
 *   2. **La telemetría y su filtro no fatal.** Las tres formas de credencial que el proyecto
 *      ya tenía (cabecera `X-Cron-Secret`, `Authorization: Bearer <CRON_SECRET>` y sesión de
 *      Coordinador) abren la proyección detallada; ninguna otra cosa la abre, y su ausencia
 *      **no rechaza la petición**: sólo reduce la respuesta. Es la diferencia entre este
 *      endpoint y las rutas batch, que son fallo cerrado, y por eso se certifica con una
 *      credencial inválida y con una sesión de Técnico (que es sesión, pero no de
 *      Coordinador), además del caso sin credencial ninguna.
 *
 *   3. **El `503` por el camino real, con el motor de verdad inalcanzable.** La fase del motor
 *      caído no se simula inyectando un doble: se apunta la configuración de conexión a un
 *      puerto donde nadie escucha, se descarta la conexión cacheada y se despacha **por el
 *      enrutador**, de modo que la petición recorre controlador, servicio, factoría y PDO como
 *      en producción. Es la única forma de demostrar que el endpoint informa del motor caído
 *      en lugar de caerse con él, y de paso certifica que el texto del fallo —que lleva host,
 *      usuario y pista de credencial— **no** se publica.
 *
 *   4. **El contrato de `/api/health` intacto.** Envoltura JSON de identificación del servicio
 *      con sus seis claves históricas, servicio del documento de la aplicación ante
 *      `Accept: text/html` y el mismo manejador compartido que `/`. Se comprueba además bajo
 *      el motor caído: la sonda vieja no puede haber adquirido una dependencia de base de
 *      datos, porque sigue respondiendo `200` cuando no hay motor.
 *
 *   5. **Las garantías estructurales de la credencial.** Un predicado público
 *      (`CronAuthMiddleware::hasBatchCredential`), una sola comparación del secreto, ninguna
 *      cabecera nueva y ninguna comparación propia en el controlador. Se certifica leyendo el
 *      código de producción y la firma del método, porque el riesgo que se cierra aquí no es
 *      de comportamiento sino de deriva: una segunda comprobación del secreto en otro sitio
 *      no rompería ninguna respuesta, y por eso ninguna prueba de comportamiento la vería.
 *
 * QUÉ NO SE CERTIFICA AQUÍ (declarado, no omitido)
 *
 *   El `503` se certifica con el **motor local** inalcanzable, que es la forma en que un
 *   proceso puede dejar de responder en cualquier entorno. La suspensión del proyecto en la
 *   nube no se certifica aquí —el proveedor no emite `540` a través de PDO y la aplicación no
 *   puede observar ese estado—: eso pertenece al flujo de latido y restauración (T-HYBRID-05),
 *   que lo determina por la API de gestión. Tampoco se certifica la latencia real contra el
 *   motor remoto: su medición es su propia tarea (T-HYBRID-27).
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\DTO\HealthCheckResponseDto;
use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\HealthCheckService;
use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Controller\HealthController;
use VendGuard\Presentation\Http\Middleware\CronAuthMiddleware;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;
use VendGuard\Presentation\Routing\Router;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - HealthEndpointTest (T-HYBRID-14)\n";
echo "======================================================================\n\n";

$failures   = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
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

$pdo = ConnectionFactory::getConnection();

// Limpieza operacional y re-siembra: la suite es autosuficiente y deja el parque como lo
// encontró. Necesita los usuarios semilla para poder firmar una sesión de Coordinador y otra
// de Técnico de verdad, no tokens inventados.
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router       = AppRouter::create();
$secretCron   = SecretProvider::cronSecret();
$statusRoute  = '/api/status';
$legacyRoute  = '/api/health';

// ─── Credenciales reales de las dos formas de sesión ─────────────────────────
$authService  = new AuthService(new PdoLocationRepository($pdo), new PdoUserRepository($pdo));
$userRepo     = new PdoUserRepository($pdo);
$coordinator  = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician   = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$coordinatorToken = $coordinator !== null ? $authService->generateInternalToken($coordinator) : '';
$technicianToken  = $technician !== null ? $authService->generateInternalToken($technician) : '';

$assert(
    '0. Usuarios semilla disponibles para firmar las dos sesiones de la prueba',
    $coordinator !== null && $technician !== null,
    'Faltan coordinacion@vendguard.internal o jordi.ruta@vendguard.internal tras la re-siembra.'
);

/** Despacho por el enrutador real, con las cabeceras que se le pasen. */
$dispatch = static function (string $method, string $path, array $headers = []) use ($router): Response {
    return $router->dispatch(new Request(method: $method, path: $path, headers: $headers));
};

/** Claves de la proyección pública: es la lista completa, no un mínimo. */
$publicKeys = ['status', 'timestamp_utc'];

/** Campos que NUNCA pueden viajar sin credencial (arquitectura interior). */
$telemetryKeys = ['database_driver', 'connection_pooler', 'response_time_ms', 'sanitary_clock', 'failure_reason'];

$isoUtc = static fn (mixed $value): bool => is_string($value)
    && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) === 1;

// =====================================================================
// GRUPO 1: La ruta nueva y su proyección pública
// =====================================================================
echo "\n--- Grupo 1: GET /api/status registrada y con proyección pública mínima ---\n";

$routerReflection = new ReflectionClass(Router::class);
$routesProperty   = $routerReflection->getProperty('routes');
$routesProperty->setAccessible(true);
$registeredRoutes = $routesProperty->getValue($router);

$statusRegistration = null;
foreach ($registeredRoutes['GET'] ?? [] as $route) {
    if ($route['pattern'] === $statusRoute) {
        $statusRegistration = $route;
        break;
    }
}

$statusMiddleware = $statusRegistration['middlewares'] ?? [];

$assert(
    '1.1 GET /api/status está registrada y apunta a HealthController::check',
    is_array($statusRegistration)
        && ($statusRegistration['handler'] ?? null) === [HealthController::class, 'check'],
    'Ruta no encontrada o manejador distinto de [HealthController, check].'
);

$assert(
    '1.2 La ruta no lleva middleware: la credencial filtra la respuesta, no la puerta',
    is_array($statusRegistration) && $statusMiddleware === [],
    'Middlewares registrados: ' . count($statusMiddleware)
    . '. Un middleware fallo cerrado convertiría el diagnóstico no fatal en un 401 (plan §2.1).'
);

$postDispatch = $dispatch('POST', $statusRoute);
$assert(
    '1.3 La ruta es sólo GET: POST /api/status responde 405 con los métodos permitidos',
    $postDispatch->getStatusCode() === 405
        && ($postDispatch->getDecodedBody()['error']['details']['allowed_methods'] ?? []) === ['GET'],
    'Código obtenido: ' . $postDispatch->getStatusCode()
);

$publicResponse = $dispatch('GET', $statusRoute);
$publicBody     = $publicResponse->getDecodedBody() ?? [];
$publicData     = $publicBody['data'] ?? [];

$assert(
    '1.4 La llamada pública responde 200 con la envoltura JSON del proyecto',
    $publicResponse->getStatusCode() === 200
        && ($publicBody['success'] ?? null) === true
        && is_array($publicData)
);

$assert(
    '1.5 La proyección pública publica exactamente status y timestamp_utc',
    array_keys($publicData) === $publicKeys,
    'Claves publicadas: ' . implode(', ', array_keys($publicData))
);

$assert(
    '1.6 El estado publicado pertenece al catálogo cerrado del contrato',
    in_array($publicData['status'] ?? null, HealthCheckResponseDto::STATUSES, true),
    'Estado publicado: ' . var_export($publicData['status'] ?? null, true)
);

$assert(
    '1.7 El motor local responde: el diagnóstico no se declara UNHEALTHY',
    ($publicData['status'] ?? null) !== HealthCheckResponseDto::STATUS_UNHEALTHY,
    'El motor local no respondió a la verificación; el resto de la suite no es concluyente.'
);

$assert(
    '1.8 El instante viaja en ISO-8601 UTC, la forma que entiende el vigilante externo',
    $isoUtc($publicData['timestamp_utc'] ?? null),
    'Instante publicado: ' . var_export($publicData['timestamp_utc'] ?? null, true)
);

$leakedKeys = array_values(array_intersect($telemetryKeys, array_keys($publicData)));
$assert(
    '1.9 La respuesta pública no filtra ni motor, ni agrupador, ni latencia (EARS-07.2)',
    $leakedKeys === [],
    'Campos que no deberían viajar sin credencial: ' . implode(', ', $leakedKeys)
);

$assert(
    '1.10 La respuesta pública se declara JSON por cabecera',
    str_contains((string)$publicResponse->getHeader('Content-Type'), 'application/json'),
    'Content-Type: ' . var_export($publicResponse->getHeader('Content-Type'), true)
);

$assert(
    '1.11 Un diagnóstico no se cachea: la cabecera lo declara',
    (string)$publicResponse->getHeader('Cache-Control') === 'no-cache, no-store, must-revalidate',
    'Cache-Control: ' . var_export($publicResponse->getHeader('Cache-Control'), true)
);

$rawPublicBody = $publicResponse->getBody();
$leakMarkers  = ['supavisor', 'pgsql', 'response_time_ms', 'sanitary_clock', 'failure_reason', '127.0.0.1', 'vendguard_db'];
$foundMarkers = array_values(array_filter($leakMarkers, static fn (string $m): bool => stripos($rawPublicBody, $m) !== false));

$assert(
    '1.12 El cuerpo público no nombra el motor, el host ni la base de datos',
    $foundMarkers === [],
    'Rastros encontrados en el cuerpo: ' . implode(', ', $foundMarkers)
);

// =====================================================================
// GRUPO 2: Telemetría filtrada por credencial, sin rechazo
// =====================================================================
echo "\n--- Grupo 2: Telemetría detallada sólo con credencial, y la ausencia no rechaza ---\n";

$authenticated = $dispatch('GET', $statusRoute, ['X-Cron-Secret' => $secretCron]);
$authData      = ($authenticated->getDecodedBody() ?? [])['data'] ?? [];

$assert(
    '2.1 Con X-Cron-Secret válido la respuesta detalla el diagnóstico',
    $authenticated->getStatusCode() === 200
        && is_string($authData['database_driver'] ?? null)
        && array_key_exists('connection_pooler', $authData)
        && is_numeric($authData['response_time_ms'] ?? null)
        && is_array($authData['sanitary_clock'] ?? null),
    'Proyección obtenida: ' . implode(', ', array_keys(is_array($authData) ? $authData : []))
);

$expectedDetailedKeys = ['status', 'database_driver', 'connection_pooler', 'response_time_ms', 'sanitary_clock', 'timestamp_utc'];
$assert(
    '2.2 La respuesta detallada sana coincide con el contrato documentado (plan §2.1.B)',
    array_keys($authData) === $expectedDetailedKeys,
    'Claves publicadas: ' . implode(', ', array_keys(is_array($authData) ? $authData : []))
);

$assert(
    '2.3 El libro del barrido viaja con sus dos campos, o con ninguno',
    array_keys($authData['sanitary_clock'] ?? []) === ['last_pass_at', 'last_result']
        && (($authData['sanitary_clock']['last_pass_at'] ?? null) === null
            || $isoUtc($authData['sanitary_clock']['last_pass_at'])),
    'Bloque sanitary_clock publicado: ' . json_encode($authData['sanitary_clock'] ?? null)
);

$assert(
    '2.4 El status y el instante no cambian entre proyección pública y detallada',
    ($authData['status'] ?? null) === ($publicData['status'] ?? null)
        && ($authData['timestamp_utc'] ?? null) === ($publicData['timestamp_utc'] ?? null)
);

$bearerSecret = $dispatch('GET', $statusRoute, ['Authorization' => 'Bearer ' . $secretCron]);
$assert(
    '2.5 Authorization: Bearer con el secreto del proceso abre la telemetría',
    array_key_exists('database_driver', (($bearerSecret->getDecodedBody() ?? [])['data'] ?? [])),
    'La forma Bearer del mismo secreto no publicó telemetría.'
);

$coordinatorResponse = $dispatch('GET', $statusRoute, ['Authorization' => "Bearer {$coordinatorToken}"]);
$coordinatorData     = ($coordinatorResponse->getDecodedBody() ?? [])['data'] ?? [];
$assert(
    '2.6 Una sesión de Coordinador abre la telemetría',
    $coordinatorToken !== '' && array_key_exists('database_driver', $coordinatorData),
    'Sesión de Coordinador sin telemetría (token vacío: ' . var_export($coordinatorToken === '', true) . ').'
);

$technicianData = (($dispatch('GET', $statusRoute, ['Authorization' => "Bearer {$technicianToken}"]))->getDecodedBody() ?? [])['data'] ?? [];
$assert(
    '2.7 Una sesión de Técnico no la abre: sólo Coordinador o el proceso batch',
    $technicianToken !== '' && array_keys($technicianData) === $publicKeys,
    'Claves publicadas a la sesión de Técnico: ' . implode(', ', array_keys($technicianData))
);

$wrongSecret = $dispatch('GET', $statusRoute, ['X-Cron-Secret' => $secretCron . '-incorrecto']);
$wrongData   = ($wrongSecret->getDecodedBody() ?? [])['data'] ?? [];
$assert(
    '2.8 Una credencial inválida NO rechaza: responde 200 con la proyección pública',
    $wrongSecret->getStatusCode() === 200 && array_keys($wrongData) === $publicKeys
);

$noCredential = $dispatch('GET', $statusRoute);
$assert(
    '2.9 La ausencia de credencial tampoco rechaza (comprobación no fatal, EARS-07.3)',
    $noCredential->getStatusCode() === 200
        && ($noCredential->getDecodedBody()['success'] ?? null) === true
);

// Predicado público y reutilizable: se interroga directamente, que es como lo consume el
// controlador y como lo consume el rechazo del proceso batch.
$credentialGate = new CronAuthMiddleware(null, $secretCron);
$assert(
    '2.10 hasBatchCredential(Request) es público y responde por las tres formas válidas',
    $credentialGate->hasBatchCredential(new Request(headers: ['X-Cron-Secret' => $secretCron])) === true
        && $credentialGate->hasBatchCredential(new Request(headers: ['Authorization' => 'Bearer ' . $secretCron])) === true
        && $credentialGate->hasBatchCredential(new Request(headers: ['Authorization' => "Bearer {$coordinatorToken}"])) === true
);
$assert(
    '2.11 hasBatchCredential(Request) niega todo lo demás',
    $credentialGate->hasBatchCredential(new Request()) === false
        && $credentialGate->hasBatchCredential(new Request(headers: ['X-Cron-Secret' => 'otra-cosa'])) === false
        && $credentialGate->hasBatchCredential(new Request(headers: ['Authorization' => "Bearer {$technicianToken}"])) === false
        && $credentialGate->hasBatchCredential(new Request(headers: ['Authorization' => 'Bearer token-corrupto'])) === false
);

// =====================================================================
// GRUPO 3: Motor inalcanzable -> 503, por el enrutador real
// =====================================================================
echo "\n--- Grupo 3: Motor inalcanzable, 503 y cero filtración del fallo ---\n";

/**
 * Apunta la conexión a un puerto donde nadie escucha.
 *
 * Se descartan las conexiones cacheadas y las cadenas de entorno que pudieran tener
 * prioridad, de modo que la petición recorra de verdad la factoría y falle al conectar,
 * como fallaría en cualquier puesto con el motor apagado.
 */
$deadEngineUrl = 'mysql://root:contrasena-de-prueba@127.0.0.1:1/vendguard_db';
$envNames      = ['DATABASE_URL', 'SUPABASE_DB_URL', 'MYSQL_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_DATABASE', 'DB_USER', 'DB_USERNAME', 'DB_PASS', 'DB_PASSWORD'];
$savedEnv      = [];

$forgetEnv = static function (string $name): void {
    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);
};

foreach ($envNames as $envName) {
    $savedEnv[$envName] = getenv($envName);
    $forgetEnv($envName);
}
putenv('MYSQL_URL=' . $deadEngineUrl);
$_ENV['MYSQL_URL'] = $deadEngineUrl;

ConnectionFactory::reset();

$deadPublic      = $dispatch('GET', $statusRoute);
$deadPublicBody  = $deadPublic->getDecodedBody() ?? [];
$deadPublicError = $deadPublicBody['error'] ?? [];

try {
    $assert(
        '3.1 Motor inalcanzable: el diagnóstico responde 503 con la envoltura de error',
        $deadPublic->getStatusCode() === 503
            && ($deadPublicBody['success'] ?? null) === false
            && ($deadPublicError['code'] ?? null) === HealthController::ERROR_DATABASE_UNAVAILABLE
            && ($deadPublicError['message'] ?? null) === HealthController::UNAVAILABLE_MESSAGE,
        'Código obtenido: ' . $deadPublic->getStatusCode() . ' · cuerpo: ' . substr($deadPublic->getBody(), 0, 200)
    );

    $deadDetails = $deadPublicError['details'] ?? [];
    $assert(
        '3.2 El detalle público del 503 declara estado e instante, y nada más',
        array_keys($deadDetails) === ['status', 'timestamp_utc']
            && ($deadDetails['status'] ?? null) === HealthCheckResponseDto::STATUS_UNHEALTHY
            && $isoUtc($deadDetails['timestamp_utc'] ?? null),
        'Detalle publicado: ' . json_encode($deadDetails)
    );

    $deadAuthenticated     = $dispatch('GET', $statusRoute, ['X-Cron-Secret' => $secretCron]);
    $deadAuthenticatedBody = $deadAuthenticated->getDecodedBody() ?? [];
    $deadAuthDetails       = $deadAuthenticatedBody['error']['details'] ?? [];
    $assert(
        '3.3 Con credencial el 503 sigue siendo 503 y añade el motivo del conjunto cerrado',
        $deadAuthenticated->getStatusCode() === 503
            && ($deadAuthDetails['failure_reason'] ?? null) === HealthCheckService::REASON_CONNECT_FAILED,
        'Estado: ' . $deadAuthenticated->getStatusCode() . ' · detalle: ' . json_encode($deadAuthDetails)
    );

    $rawDead = $deadPublic->getBody() . $deadAuthenticated->getBody();
    $secretMarkers = ['contrasena-de-prueba', '127.0.0.1', 'vendguard_db', 'root', 'SQLSTATE', 'Fallo de conexión', 'mysql://'];
    $foundSecrets  = array_values(array_filter($secretMarkers, static fn (string $m): bool => stripos($rawDead, $m) !== false));
    $assert(
        '3.4 El 503 no publica el texto del fallo: ni DSN, ni usuario, ni contraseña (EARS-07.4)',
        $foundSecrets === [],
        'Rastros encontrados: ' . implode(', ', $foundSecrets)
    );

    // La sonda vieja no puede haber adquirido dependencia del motor: con el motor caído
    // sigue sirviendo su contrato exacto.
    $legacyWhileDown = $dispatch('GET', $legacyRoute);
    $assert(
        '3.5 /api/health sigue respondiendo 200 con el motor inalcanzable (no toca la base de datos)',
        $legacyWhileDown->getStatusCode() === 200
            && (($legacyWhileDown->getDecodedBody() ?? [])['data']['name'] ?? null) === 'VendGuard API',
        'Código obtenido: ' . $legacyWhileDown->getStatusCode()
    );
} finally {
    foreach ($savedEnv as $envName => $envValue) {
        if ($envValue === false || $envValue === null) {
            $forgetEnv($envName);
            continue;
        }
        putenv($envName . '=' . $envValue);
        $_ENV[$envName] = $envValue;
    }
    ConnectionFactory::reset();
}

$restored = $dispatch('GET', $statusRoute);
$assert(
    '3.6 Restituida la configuración, el diagnóstico vuelve a 200: la suite no deja el banco tocado',
    $restored->getStatusCode() === 200
        && (($restored->getDecodedBody() ?? [])['data']['status'] ?? null) !== HealthCheckResponseDto::STATUS_UNHEALTHY,
    'Código obtenido tras la restitución: ' . $restored->getStatusCode()
);

// =====================================================================
// GRUPO 4: No regresión del contrato de /api/health (EARS-07.5)
// =====================================================================
echo "\n--- Grupo 4: El contrato vivo de /api/health queda intacto ---\n";

$legacyResponse = $dispatch('GET', $legacyRoute);
$legacyBody     = $legacyResponse->getDecodedBody() ?? [];
$legacyData     = $legacyBody['data'] ?? [];

$assert(
    '4.1 /api/health responde 200 con la envoltura JSON del proyecto',
    $legacyResponse->getStatusCode() === 200 && ($legacyBody['success'] ?? null) === true
);

$assert(
    '4.2 La carga útil conserva exactamente sus seis claves históricas',
    array_keys($legacyData) === ['name', 'version', 'status', 'message', 'timestamp', 'environment'],
    'Claves publicadas: ' . implode(', ', array_keys($legacyData))
);

$assert(
    '4.3 La identificación del servicio no ha cambiado',
    ($legacyData['name'] ?? null) === 'VendGuard API'
        && ($legacyData['version'] ?? null) === '1.0.0-mvp'
        && ($legacyData['status'] ?? null) === 'operational'
        && is_string($legacyData['message'] ?? null)
        && $legacyData['message'] !== ''
);

$assert(
    '4.4 El bloque de entorno sigue declarando la versión de PHP y el SAPI',
    array_keys($legacyData['environment'] ?? []) === ['php_version', 'sapi']
        && ($legacyData['environment']['php_version'] ?? null) === PHP_VERSION
        && ($legacyData['environment']['sapi'] ?? null) === PHP_SAPI
);

$legacyTimestamp = $legacyData['timestamp'] ?? null;
$assert(
    '4.5 El instante del servicio sigue publicándose en ISO-8601',
    is_string($legacyTimestamp) && strtotime($legacyTimestamp) !== false
);

$legacyLeaks = array_values(array_intersect(
    array_merge($telemetryKeys, ['timestamp_utc', 'database_driver']),
    array_keys($legacyData)
));
$assert(
    '4.6 El diagnóstico no se ha colado en la sonda vieja',
    $legacyLeaks === [],
    'Campos del diagnóstico presentes en /api/health: ' . implode(', ', $legacyLeaks)
);

$htmlResponse = $dispatch('GET', $legacyRoute, ['Accept' => 'text/html']);
$htmlPath     = dirname(__DIR__, 2) . '/public/index.html';
$expectedHtml = is_file($htmlPath) ? (string)file_get_contents($htmlPath) : '';

$assert(
    '4.7 Ante Accept: text/html sirve el documento de la aplicación, no un JSON',
    $expectedHtml !== ''
        && $htmlResponse->getStatusCode() === 200
        && $htmlResponse->getBody() === $expectedHtml
        && str_contains((string)$htmlResponse->getHeader('Content-Type'), 'text/html'),
    'Documento esperado: ' . ($expectedHtml === '' ? 'no existe public/index.html' : 'existe')
        . ' · Content-Type: ' . var_export($htmlResponse->getHeader('Content-Type'), true)
        . ' · cuerpo idéntico: ' . var_export($htmlResponse->getBody() === $expectedHtml, true)
);

$rootHtmlResponse = $dispatch('GET', '/', ['Accept' => 'text/html']);
$assert(
    '4.8 La raíz `/` comparte el documento y el comportamiento dual',
    $htmlResponse->getBody() === $rootHtmlResponse->getBody()
        && $rootHtmlResponse->getStatusCode() === 200
);

$assert(
    '4.9 `/` y `/api/health` siguen servidos por el mismo manejador compartido',
    ($registeredRoutes['GET'][0]['pattern'] ?? null) === '/'
        && ($registeredRoutes['GET'][0]['handler'] ?? null) === ($registeredRoutes['GET'][1]['handler'] ?? null)
        && ($registeredRoutes['GET'][1]['pattern'] ?? null) === $legacyRoute
);

// =====================================================================
// GRUPO 5: Una sola comparación del secreto y ninguna cabecera nueva
// =====================================================================
echo "\n--- Grupo 5: Garantías estructurales de la credencial ---\n";

$baseDir = dirname(__DIR__, 2);
$middlewareSource = (string)file_get_contents($baseDir . '/src/Presentation/Http/Middleware/CronAuthMiddleware.php');
$controllerSource = (string)file_get_contents($baseDir . '/src/Presentation/Controller/HealthController.php');

$assert(
    '5.1 El rechazo del proceso batch delega en el predicado público',
    str_contains($middlewareSource, 'if (!$this->hasBatchCredential($request))')
        && !str_contains($middlewareSource, 'function isAuthorized')
);

$predicateOffset = strpos($middlewareSource, 'function hasBatchCredential');
$beforePredicate = $predicateOffset === false ? $middlewareSource : substr($middlewareSource, 0, $predicateOffset);
$afterPredicate  = $predicateOffset === false ? '' : substr($middlewareSource, $predicateOffset);

$assert(
    '5.2 La comparación del secreto existe una sola vez, y vive en el predicado',
    $predicateOffset !== false
        && substr_count($afterPredicate, 'hash_equals') === 2
        && substr_count($beforePredicate, 'hash_equals') === 0,
    'hash_equals antes del predicado: ' . substr_count($beforePredicate, 'hash_equals')
        . ' · dentro: ' . substr_count($afterPredicate, 'hash_equals')
);

$assert(
    '5.3 El controlador consume el predicado y no reimplementa la comparación',
    str_contains($controllerSource, 'hasBatchCredential(')
        && !str_contains($controllerSource, 'hash_equals')
        && !str_contains($controllerSource, 'CRON_SECRET')
);

$assert(
    '5.4 Ninguna cabecera nueva: no existe un mecanismo de credencial paralelo',
    !str_contains($controllerSource, 'X-Internal-Token')
        && !str_contains($middlewareSource, 'X-Internal-Token')
        && str_contains($middlewareSource, 'X-Cron-Secret')
);

$predicateMethod = new ReflectionMethod(CronAuthMiddleware::class, 'hasBatchCredential');
$assert(
    '5.5 hasBatchCredential(Request): bool es público, tipado y de instancia',
    $predicateMethod->isPublic()
        && !$predicateMethod->isStatic()
        && $predicateMethod->getReturnType() instanceof ReflectionNamedType
        && $predicateMethod->getReturnType()->getName() === 'bool'
        && (string)$predicateMethod->getParameters()[0]->getType() === Request::class,
    'Firma: ' . $predicateMethod->getReturnType() . ' (' . (string)($predicateMethod->getParameters()[0]->getType() ?? 'sin tipo') . ')'
);

$checkMethod = new ReflectionMethod(HealthController::class, 'check');
$assert(
    '5.6 HealthController::check(Request $request): Response tiene la firma del contrato',
    $checkMethod->isPublic()
        && (string)$checkMethod->getParameters()[0]->getType() === Request::class
        && $checkMethod->getReturnType() instanceof ReflectionNamedType
        && $checkMethod->getReturnType()->getName() === Response::class
);

$assert(
    '5.7 El controlador no lleva SQL ni PDO: la sustancia del diagnóstico vive en el servicio',
    preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|PDO)\b/i', $controllerSource) === 0
        && str_contains($controllerSource, 'HealthCheckService')
        && str_contains($controllerSource, '->check(')
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: TODAS LAS CONDICIONES DE T-HYBRID-14 CUMPLIDAS.\n";
} else {
    echo " RESULTADO: {$failures} COMPROBACIÓN(ES) FALLIDA(S).\n";
}
echo " Total Aserciones: {$assertions}\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
