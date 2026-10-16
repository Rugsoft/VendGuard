<?php

declare(strict_types=1);

/**
 * ConnectionFactoryTest — certificación de la factoría de conexión del motor único
 * PostgreSQL (Supabase) (T-03, ampliada por T-HYBRID-19, portada al motor único en T-13-14).
 *
 * QUÉ CERTIFICA BAJO EL CONTRATO DE MOTOR ÚNICO (EARS-13.1, EARS-13.2)
 *
 * 1. **La conexión viva con el motor único**:
 *    Devuelve instancia PDO con ERRMODE_EXCEPTION, ATTR_DEFAULT_FETCH_MODE asociativo,
 *    emulación de sentencias preparadas activada (ATTR_EMULATE_PREPARES = true) para
 *    compatibilidad con el agrupador transaccional Supavisor (EARS-01.2), y verifica
 *    que la base conectada coincide con `SELECT current_database()`.
 *
 * 2. **Configuración y autodetección sin red (EARS-13.1)**:
 *    Por defecto o mediante `DATABASE_URL` / `SUPABASE_DB_URL`, el motor resuelto es
 *    siempre PostgreSQL (`pgsql`). Se resuelve el puerto del agrupador transaccional (6543)
 *    o puerto directo si se especifica, y las cadenas o alias que intenten configurar motores
 *    distintos (como `mysql://`, `mariadb`, etc.) son rechazados en voz alta con excepción
 *    (EARS-13.1).
 *
 * 3. **Plazos y opciones PDO (EARS-04.1)**:
 *    Presupuesto de conexión de 5 s (`ATTR_TIMEOUT`), opciones de error y fetch, y
 *    emulación de preparadas activada.
 *
 * 4. **TLS estricto con verificación de identidad (RNF-05)**:
 *    Se exige `sslmode=verify-full` con CA existente (`sslrootcert`). Cualquier intento
 *    de degradar a `require`, `disable` o desactivar con `ssl => false` falla en cerrado
 *    antes de conectar. Una CA declarada inexistente también es rechazada en cerrado.
 *
 * 5. **Política de credenciales de producción (S-5, EARS-13.2)**:
 *    En producción (`APP_ENV=production`), se exige fuente explícita de credenciales.
 *    El usuario administrativo `postgres` sin contraseña es rechazado inmediatamente.
 *    Una credencial vacía se distingue de una ausencia, y con credenciales de aplicación
 *    la política deja pasar hasta la conexión real.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;

$projectRoot = dirname(__DIR__, 2);

echo "======================================================================\n";
echo " VendGuard: Suite de Integración - ConnectionFactoryTest (T-13-14)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }
    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

$assertThrows = function (
    string $caseTitle,
    callable $operation,
    string $expectedFragment,
    string $exceptionClass = RuntimeException::class
) use ($assert): void {
    $thrown = null;
    try {
        $operation();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    if ($thrown === null) {
        $assert($caseTitle, false, 'No lanzó ninguna excepción: el caso inválido se aceptó en silencio.');
        return;
    }

    if (!$thrown instanceof $exceptionClass) {
        $assert($caseTitle, false, sprintf('Lanzó %s en lugar de %s.', $thrown::class, $exceptionClass));
        return;
    }

    $assert(
        $caseTitle,
        str_contains($thrown->getMessage(), $expectedFragment),
        sprintf('El mensaje «%s» no contiene «%s».', $thrown->getMessage(), $expectedFragment)
    );
};

$withEnvironment = function (array $variables, callable $body) use (&$assertions): mixed {
    $previous = [];
    foreach ($variables as $name => $value) {
        $previous[$name] = getenv($name);
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    $restore = static function () use ($variables, $previous): void {
        foreach ($variables as $name => $value) {
            if ($previous[$name] === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
                continue;
            }
            putenv("{$name}={$previous[$name]}");
            $_ENV[$name] = $previous[$name];
            $_SERVER[$name] = $previous[$name];
        }
    };

    try {
        return $body();
    } finally {
        $restore();
    }
};

$withoutConnectionEnvironment = function (callable $body) {
    $names = ['DATABASE_URL', 'SUPABASE_DB_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_DATABASE', 'DB_USER', 'DB_USERNAME', 'DB_PASS', 'DB_PASSWORD', 'APP_ENV', 'VENDGUARD_ENV', 'DB_SSL', 'DB_SSL_ROOT_CERT'];
    $previous = [];

    foreach ($names as $name) {
        $previous[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    try {
        return $body();
    } finally {
        foreach ($previous as $name => [$env, $envArray, $serverArray]) {
            if ($env !== false) {
                putenv("{$name}={$env}");
            }
            if ($envArray !== null) {
                $_ENV[$name] = $envArray;
            }
            if ($serverArray !== null) {
                $_SERVER[$name] = $serverArray;
            }
        }
    }
};

ConnectionFactory::reset();

// =====================================================================
// GRUPO 1 — La conexión viva con el motor único PostgreSQL
// =====================================================================
echo "--- Grupo 1: conexión viva con el motor único PostgreSQL ---\n";

$pdo = ConnectionFactory::getConnection();
$live = ConnectionFactory::describeConnection();
$liveDriver = (string)$live['driver'];

$assert('1.1 La factoría devuelve una instancia de PDO', $pdo instanceof PDO);
$assert(
    '1.2 El modo de error es ERRMODE_EXCEPTION, no un fallo silencioso',
    $pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION
);
$assert(
    '1.3 El modo de recuperación por defecto es asociativo',
    $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) === PDO::FETCH_ASSOC
);

$emulatePrepares = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
$assert(
    '1.4 ATTR_EMULATE_PREPARES está activa en PostgreSQL (Supavisor)',
    (bool)$emulatePrepares === true
);

$assert(
    '1.5 El motor detectado coincide con el motor real de la sesión (pgsql)',
    (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === ConnectionFactory::DRIVER_PGSQL
        && $liveDriver === ConnectionFactory::DRIVER_PGSQL
);

$connectedDatabase = (string)$pdo->query('SELECT current_database()')->fetchColumn();
$assert(
    '1.6 PostgreSQL: la base conectada es la que declaró la factoría',
    $connectedDatabase === (string)$live['database'],
    sprintf('conectada: %s · declarada: %s', $connectedDatabase, (string)$live['database'])
);

// =====================================================================
// GRUPO 2 — Configuración y resolución de motor único (EARS-13.1)
// =====================================================================
echo "\n--- Grupo 2: resolución de motor único sin red (EARS-13.1) ---\n";

ConnectionFactory::reset();

$defaultConfiguration = $withoutConnectionEnvironment(static fn (): array => ConnectionFactory::describeConnection());
$assert(
    '2.1 Por defecto, el motor único es siempre PostgreSQL',
    $defaultConfiguration['driver'] === ConnectionFactory::DRIVER_PGSQL
        && $defaultConfiguration['ssl_mode'] === ConnectionFactory::REQUIRED_SSL_MODE
        && $defaultConfiguration['port'] === ConnectionFactory::POOLER_PORT,
    sprintf('driver: %s · port: %s', (string)$defaultConfiguration['driver'], (string)$defaultConfiguration['port'])
);

$postgresConfiguration = $withEnvironment(
    ['DATABASE_URL' => 'postgres://vendguard_app:secreta@aws-0-eu-central-1.pooler.supabase.com:6543/postgres'],
    static fn (): array => ConnectionFactory::describeConnection()
);

$assert(
    '2.2 Una cadena postgres:// configura PostgreSQL',
    $postgresConfiguration['driver'] === ConnectionFactory::DRIVER_PGSQL
);
$assert(
    '2.3 PostgreSQL resuelve el agrupador transaccional en el puerto 6543 (EARS-01.2)',
    $postgresConfiguration['port'] === ConnectionFactory::POOLER_PORT
        && $postgresConfiguration['uses_pooler'] === true
        && str_contains((string)$postgresConfiguration['dsn'], 'port=' . ConnectionFactory::POOLER_PORT)
);
$assert(
    '2.4 La base y el usuario declarados en la URL llegan a la configuración resuelta',
    $postgresConfiguration['database'] === 'postgres' && $postgresConfiguration['user'] === 'vendguard_app'
);
$assert(
    '2.5 La contraseña nunca se devuelve, pero sí se declara que existe',
    $postgresConfiguration['password'] === null && $postgresConfiguration['password_declared'] === true
);

$dsnFormConfiguration = $withEnvironment(
    [
        'DATABASE_URL' => '',
        'SUPABASE_DB_URL' => 'pgsql:host=db.pooler.supabase.com;port=6543;dbname=vendguard;user=vendguard_app;password=secreta',
    ],
    static fn (): array => ConnectionFactory::describeConnection()
);

$assert(
    '2.6 La forma DSN del contrato también declara PostgreSQL',
    $dsnFormConfiguration['driver'] === ConnectionFactory::DRIVER_PGSQL
        && $dsnFormConfiguration['host'] === 'db.pooler.supabase.com'
        && $dsnFormConfiguration['database'] === 'vendguard'
        && $dsnFormConfiguration['password_declared'] === true
);

$directPortConfiguration = $withEnvironment(
    ['DATABASE_URL' => 'postgres://vendguard_app:secreta@db.local:5432/vendguard'],
    static fn (): array => ConnectionFactory::describeConnection()
);

$assert(
    '2.7 Un puerto explícito distinto del agrupador no se declara como agrupador',
    $directPortConfiguration['driver'] === ConnectionFactory::DRIVER_PGSQL
        && $directPortConfiguration['port'] === '5432'
        && $directPortConfiguration['uses_pooler'] === false
);

// Rechazo de motores distintos al motor único (EARS-13.1)
$assertThrows(
    '2.8 Rechazo de configuración con URL de motor no admitido (mysql://)',
    static fn (): array => $withoutConnectionEnvironment(static fn (): array => ConnectionFactory::describeConnection(['DATABASE_URL' => 'mysql://app_user:secreta@127.0.0.1:3306/vendguard_db'])),
    'EARS-13.1'
);

$assertThrows(
    '2.9 Rechazo de alias de motor heredado (driver => mysql)',
    static fn (): array => $withoutConnectionEnvironment(static fn (): array => ConnectionFactory::describeConnection(['driver' => 'mysql'])),
    'EARS-13.1'
);

$assertThrows(
    '2.10 Rechazo de alias de motor heredado (driver => mariadb)',
    static fn (): array => $withoutConnectionEnvironment(static fn (): array => ConnectionFactory::describeConnection(['driver' => 'mariadb'])),
    'EARS-13.1'
);

$assertThrows(
    '2.11 Rechazo de motor desconocido (driver => oracle)',
    static fn (): array => $withoutConnectionEnvironment(static fn (): array => ConnectionFactory::describeConnection(['driver' => 'oracle'])),
    'EARS-13.1'
);

// =====================================================================
// GRUPO 3 — Plazos y opciones PDO
// =====================================================================
echo "\n--- Grupo 3: plazos y opciones PDO (EARS-04.1) ---\n";

$postgresOptions = $postgresConfiguration['options'];

$assert(
    '3.1 El presupuesto de conexión es de 5 s (coherencia con el cliente)',
    $postgresOptions[PDO::ATTR_TIMEOUT] === ConnectionFactory::CONNECT_TIMEOUT_SECONDS
        && ConnectionFactory::CONNECT_TIMEOUT_SECONDS === 5,
    sprintf('pgsql: %s', var_export($postgresOptions[PDO::ATTR_TIMEOUT], true))
);

$assert(
    '3.2 Modo de error ERRMODE_EXCEPTION y modo de recuperación asociativo',
    $postgresOptions[PDO::ATTR_ERRMODE] === PDO::ERRMODE_EXCEPTION
        && $postgresOptions[PDO::ATTR_DEFAULT_FETCH_MODE] === PDO::FETCH_ASSOC
);

$assert(
    '3.3 La emulación de preparadas está activa en PostgreSQL',
    $postgresOptions[PDO::ATTR_EMULATE_PREPARES] === true
);

$assert(
    '3.4 El DSN de PostgreSQL exige TLS con verificación de identidad y CA explícita (RNF-05)',
    str_contains((string)$postgresConfiguration['dsn'], 'sslmode=' . ConnectionFactory::REQUIRED_SSL_MODE)
        && str_contains((string)$postgresConfiguration['dsn'], 'sslrootcert=')
        && is_file((string)$postgresConfiguration['ssl_root_certificate']),
    (string)$postgresConfiguration['dsn']
);

$assert(
    '3.5 El DSN de PostgreSQL no arrastra el parámetro charset, que es de MySQL',
    !str_contains((string)$postgresConfiguration['dsn'], 'charset=')
);

$extraParameterConfiguration = $withEnvironment(
    ['DATABASE_URL' => 'postgres://app:secreta@db.local:6543/vendguard?application_name=vendguard&sslmode=verify-full'],
    static fn (): array => ConnectionFactory::describeConnection()
);

$assert(
    '3.6 Los parámetros extra se delegan al driver en el DSN',
    str_contains((string)$extraParameterConfiguration['dsn'], 'application_name=vendguard')
        && $extraParameterConfiguration['ssl_mode'] === ConnectionFactory::REQUIRED_SSL_MODE,
    (string)$extraParameterConfiguration['dsn']
);

// =====================================================================
// GRUPO 4 — TLS con verificación de identidad (RNF-05)
// =====================================================================
echo "\n--- Grupo 4: fallo en cerrado del TLS del motor único (RNF-05) ---\n";

$postgresBase = ['driver' => 'postgres', 'host' => 'db.local', 'dbname' => 'vendguard', 'user' => 'app', 'password' => 'secreta'];

$assertThrows(
    '4.1 Degradar el TLS a «require» se rechaza antes de conectar',
    static fn (): array => ConnectionFactory::describeConnection($postgresBase + ['sslmode' => 'require']),
    'RNF-05'
);
$assertThrows(
    '4.2 Degradar el TLS a «disable» se rechaza antes de conectar',
    static fn (): array => ConnectionFactory::describeConnection($postgresBase + ['sslmode' => 'disable']),
    'RNF-05'
);
$assertThrows(
    '4.3 Desactivar el TLS con `ssl => false` se rechaza: en la nube no hay conexión sin cifrar',
    static fn (): array => ConnectionFactory::describeConnection($postgresBase + ['ssl' => false]),
    'no admite conexiones sin TLS'
);
$assertThrows(
    '4.4 Una CA declarada que no existe se rechaza: no se sustituye en silencio por otra',
    static fn (): array => ConnectionFactory::describeConnection($postgresBase + ['sslrootcert' => '/ruta/inexistente/ca.pem']),
    'no existe'
);

$projectCa = $projectRoot . '/src/Infrastructure/Database/isrgrootx1.pem';
$explicitCaConfiguration = ConnectionFactory::describeConnection($postgresBase + [
    'sslmode' => 'verify-full',
    'sslrootcert' => $projectCa,
]);

$assert(
    '4.5 El modo exigido se declara explícitamente y la CA del proyecto se respeta',
    $explicitCaConfiguration['ssl_mode'] === ConnectionFactory::REQUIRED_SSL_MODE
        && $explicitCaConfiguration['ssl_root_certificate'] === $projectCa
);

// =====================================================================
// GRUPO 5 — Política de credenciales (S-5, EARS-13.2)
// =====================================================================
echo "\n--- Grupo 5: política de credenciales S-5 del motor único ---\n";

$withEnvironment(
    ['APP_ENV' => 'production', 'DATABASE_URL' => 'postgres://postgres@db.local:6543/vendguard'],
    static function () use ($assert): void {
        $thrown = null;
        try {
            ConnectionFactory::getConnection(['host' => '127.0.0.1', 'port' => '1', 'user' => 'postgres', 'password' => '', 'dbname' => 'vendguard', 'driver' => 'postgres'], true);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $assert(
            '5.1 PostgreSQL: el rechazo en producción nombra al usuario administrativo postgres',
            $thrown instanceof RuntimeException
                && str_contains($thrown->getMessage(), 'postgres')
                && str_contains($thrown->getMessage(), 'pgsql'),
            $thrown === null ? 'No lanzó ninguna excepción.' : $thrown->getMessage()
        );
    }
);

$withoutConnectionEnvironment(static function () use ($assert): void {
    putenv('APP_ENV=production');
    $_ENV['APP_ENV'] = 'production';
    $_SERVER['APP_ENV'] = 'production';

    $thrown = null;
    try {
        ConnectionFactory::getConnection([], true);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    $assert(
        '5.2 Producción sin ninguna fuente explícita de credenciales tampoco se admite',
        $thrown instanceof RuntimeException
            && str_contains($thrown->getMessage(), 'S-5')
            && str_contains($thrown->getMessage(), 'credenciales explícitas'),
        $thrown === null ? 'No lanzó ninguna excepción.' : $thrown->getMessage()
    );

    putenv('DB_USER=');
    $_ENV['DB_USER'] = '';
    $_SERVER['DB_USER'] = '';

    $declaredEmpty = null;
    try {
        ConnectionFactory::getConnection([], true);
    } catch (Throwable $e) {
        $declaredEmpty = $e;
    }

    $assert(
        '5.3 Una credencial declarada y vacía cuenta como declaración, no como ausencia',
        $declaredEmpty instanceof RuntimeException
            && str_contains($declaredEmpty->getMessage(), 'sin contraseña')
            && !str_contains($declaredEmpty->getMessage(), 'no hay credenciales explícitas'),
        $declaredEmpty === null ? 'No lanzó ninguna excepción.' : $declaredEmpty->getMessage()
    );
});

$withEnvironment(
    ['APP_ENV' => 'production', 'DATABASE_URL' => ''],
    static function () use ($assert): void {
        $thrown = null;
        try {
            ConnectionFactory::getConnection(['host' => '127.0.0.1', 'port' => '1', 'user' => 'app_user', 'password' => 'secreta', 'dbname' => 'vendguard_db'], true);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $assert(
            '5.4 Con credenciales de aplicación la política deja pasar hasta la conexión',
            $thrown instanceof RuntimeException
                && str_contains($thrown->getMessage(), 'Fallo de conexión')
                && !str_contains($thrown->getMessage(), 'S-5'),
            $thrown === null ? 'No lanzó ninguna excepción.' : $thrown->getMessage()
        );
    }
);

// Fallo rápido (fail-fast) antes de intentar conectar
$withEnvironment(
    ['APP_ENV' => 'production', 'DATABASE_URL' => 'postgres://postgres@192.0.2.1:6543/vendguard'],
    static function () use ($assert): void {
        $started = hrtime(true);
        $thrown = null;
        try {
            ConnectionFactory::getConnection(['host' => '192.0.2.1', 'port' => '6543', 'user' => 'postgres', 'password' => '', 'dbname' => 'vendguard'], true);
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $assert(
            '5.5 El rechazo es fail-fast: resuelve antes de intentar conectar (por debajo de 250 ms)',
            $thrown instanceof RuntimeException
                && str_contains($thrown->getMessage(), 'S-5')
                && $elapsedMs < 250,
            sprintf('tiempo: %.2f ms · error: %s', $elapsedMs, $thrown === null ? 'ninguno' : $thrown->getMessage())
        );
    }
);

echo "\n======================================================================\n";
echo " Aserciones: {$assertions} | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Factoría de conexión única certificada (T-13-14).\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
