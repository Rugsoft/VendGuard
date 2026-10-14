<?php

declare(strict_types=1);

/**
 * ConnectionFactoryTest — certificación de la factoría única de conexiones PDO
 * (T-03, ampliada por T-HYBRID-19).
 *
 * QUÉ CERTIFICA, Y POR QUÉ EN CINCO GRUPOS DISTINTOS
 *
 * La suite nació en T-03 como una sonda de arranque: cinco `echo` que comprobaban
 * la conexión viva y **ninguna aserción**. Certificaba «funciona aquí» y no dejaba
 * rastro verificable de nada. T-HYBRID-19 la amplía —en el mismo fichero, porque el
 * enunciado prohíbe expresamente duplicarla en `tests/unit/`— hasta cubrir lo que la
 * especificación le exige a la factoría:
 *
 *   1. **La conexión viva, con la comprobación equivalente de cada motor.** Se
 *      mantiene lo que ya se verificaba (instancia PDO, `ERRMODE_EXCEPTION`,
 *      `ATTR_EMULATE_PREPARES` según el contrato de cada rama) y las dos consultas
 *      que eran **sintaxis exclusiva de MySQL** —`SHOW VARIABLES LIKE
 *      'character_set_connection'` y `SELECT DATABASE()`— pasan a resolverse con la
 *      forma que cada motor entiende. La regla que se certifica es una y no cambia con
 *      el motor: *la sesión está configurada como la factoría la declaró*. Además se
 *      cruza el motor real (`ATTR_DRIVER_NAME`) contra el que declara la factoría, que
 *      es lo que convierte la autodetección en un hecho y no en una intención.
 *
 *   2. **La autodetección de driver sin red (EARS-01.4).** Con
 *      `ConnectionFactory::describeConnection()`, que resuelve configuración, DSN y
 *      opciones **sin abrir conexión**, se certifican las dos ramas y su forma: una
 *      cadena `postgres://…` activa PostgreSQL por el agrupador (puerto 6543), una
 *      `mysql://…` conserva la rama heredada, y sin declaración el motor es el local.
 *      Aquí no se toca el motor nuevo: se lee su contrato, que es la única vía hermética
 *      (RNF-04) y quedó declarada como tal por T-HYBRID-13.
 *
 *   3. **Plazos y opciones PDO.** `ATTR_TIMEOUT` de 5 s en los dos motores para que la
 *      coherencia con el cliente sea extremo a extremo (EARS-04.1), el modo de error, el
 *      modo de recuperación, la emulación de sentencias preparadas —**activa** en
 *      PostgreSQL, porque el agrupador transaccional no sostiene sentencias de servidor—
 *      y el charset declarado.
 *
 *   4. **TLS con verificación de identidad (RNF-05).** Se certifica el fallo en cerrado:
 *      `sslmode=require` o `disable`, un `ssl => false` explícito y una CA declarada que
 *      no existe se rechazan **antes** de intentar conectar, y el modo efectivo es
 *      siempre `verify-full` con una CA real del proyecto.
 *
 *   5. **La política de credenciales S-5, idéntica en los dos drivers (EARS-01.6).**
 *      En producción, una credencial administrativa sin contraseña se rechaza nombrando
 *      el usuario administrativo **de su motor** (`root` en MySQL, `postgres` en
 *      PostgreSQL) y nombrarlo distinto en cada rama es precisamente la prueba de que la
 *      regla es una solo. El caso positivo se mide por donde se puede medir sin red: la
 *      política deja pasar y el fallo que llega es **de conexión** (puerto cerrado en
 *      loopback), no de credenciales. Y se cronometra: la política resuelve en menos de
 *      250 ms contra un host no enrutable, lo que demuestra que el rechazo ocurre
 *      **antes** de abrir la conexión y no como un error genérico al final del arranque.
 *
 * QUÉ NO SE CERTIFICA AQUÍ (declarado, no omitido)
 *
 * La **ejecución** de la rama PostgreSQL: la batería es hermética y el motor nuevo no
 * está en todos los puestos. Su semántica pertenece a la categoría del motor nuevo
 * (`PostgresSchemaTest`, `T-HYBRID-21/24`); aquí se certifica su contrato de
 * configuración, que es lo que la rama comparte con la aplicación entera.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO, sin dependencias externas ni bundlers.
 * Dualismo Lingüístico: identificadores y código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;

$projectRoot = dirname(__DIR__, 2);

// =====================================================================
// Arnés mínimo de aserciones (mismo contrato que el resto de la batería)
// =====================================================================

echo "======================================================================\n";
echo " VendGuard: Suite de Integración - ConnectionFactoryTest (T-03 · T-HYBRID-19)\n";
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

/**
 * Afirma que la operación rechaza en voz alta con un fragmento identificable del mensaje.
 * `$exceptionClass` acota el tipo: un rechazo por la clase equivocada no es el rechazo que se fija.
 */
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

/**
 * Declara variables de entorno en las tres fuentes que consulta el proyecto y las restituye al
 * terminar, aunque el cuerpo falle. La suite corre en su propio proceso, pero dejar el entorno
 * como estaba es parte de no mentir sobre lo que se ha medido.
 *
 * @param array<string, string> $variables
 */
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

/**
 * Retira del proceso todas las variables de configuración de conexión y las restituye al terminar.
 *
 * No basta con dejarlas vacías, y esa es justamente una de las reglas que certifica esta suite: la
 * factoría distingue «ausente» de «declarada y vacía» a propósito —una contraseña vacía declarada es
 * una declaración, no una ausencia— y esa diferencia decide la política de credenciales (S-5).
 */
$withoutConnectionEnvironment = function (callable $body) { 
    $names = ['DATABASE_URL', 'SUPABASE_DB_URL', 'MYSQL_URL', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_DATABASE', 'DB_USER', 'DB_USERNAME', 'DB_PASS', 'DB_PASSWORD', 'APP_ENV', 'VENDGUARD_ENV', 'DB_SSL', 'DB_SSL_ROOT_CERT'];
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

// La sesión viva se resuelve con el entorno real del puesto, que es el único que puede responder.
ConnectionFactory::reset();

// =====================================================================
// GRUPO 1 — La conexión viva, con la comprobación equivalente de cada motor
// =====================================================================

echo "--- Grupo 1: conexión viva y comprobación equivalente por motor ---\n";

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

// La emulación de sentencias preparadas es un contrato POR RAMA, y por eso el valor esperado se
// deriva del driver y no se fija a mano: el agrupador transaccional exige activarla en PostgreSQL
// (EARS-01.2) y MariaDB conserva las sentencias nativas de siempre.
$emulatePrepares = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
$expectedEmulation = $liveDriver === ConnectionFactory::DRIVER_PGSQL;
$assert(
    sprintf('1.4 ATTR_EMULATE_PREPARES sigue el contrato de la rama (%s: %s)', $liveDriver, $expectedEmulation ? 'activa' : 'inactiva'),
    (bool)$emulatePrepares === $expectedEmulation,
    sprintf('valor devuelto: %s', var_export($emulatePrepares, true))
);

// La autodetección se cruza contra el motor que de verdad responde: es lo que la convierte en un
// hecho medido y no en la intención declarada en la configuración.
$assert(
    '1.5 El motor detectado coincide con el motor real de la sesión',
    (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === $liveDriver,
    sprintf('declarado: %s · real: %s', $liveDriver, (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME))
);

// Comprobación equivalente: una sola regla —la sesión está configurada como se declaró— con la
// consulta que cada motor puede responder. Aquí vivían las dos sintaxis que solo entendía MySQL.
if ($liveDriver === ConnectionFactory::DRIVER_PGSQL) {
    $connectedDatabase = (string)$pdo->query('SELECT current_database()')->fetchColumn();
    $assert(
        '1.6 PostgreSQL: la base conectada es la que declaró la factoría',
        $connectedDatabase === (string)$live['database'],
        sprintf('conectada: %s · declarada: %s', $connectedDatabase, (string)$live['database'])
    );
} else {
    $charsetRow = $pdo->query("SHOW VARIABLES LIKE 'character_set_connection'")->fetch();
    $charset = (string)($charsetRow['Value'] ?? '');
    $assert(
        '1.6 MariaDB: la codificación de la sesión es el charset declarado (utf8mb4)',
        str_starts_with($charset, (string)$live['charset']),
        sprintf('charset de sesión: %s · declarado: %s', $charset, (string)$live['charset'])
    );

    $connectedDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $assert(
        '1.7 MariaDB: la base conectada es la que declaró la factoría',
        $connectedDatabase === (string)$live['database'],
        sprintf('conectada: %s · declarada: %s', $connectedDatabase, (string)$live['database'])
    );
}

// =====================================================================
// GRUPO 2 — Autodetección de driver, sin abrir ninguna conexión
// =====================================================================

echo "\n--- Grupo 2: autodetección de driver sin red (EARS-01.4) ---\n";

ConnectionFactory::reset();

$defaultConfiguration = ConnectionFactory::describeConnection();
$assert(
    '2.1 Sin declaración, el motor es el heredado de todos los entornos locales',
    $defaultConfiguration['driver'] === ConnectionFactory::DRIVER_MYSQL
        && $defaultConfiguration['ssl_mode'] === null
        && $defaultConfiguration['uses_pooler'] === false,
    sprintf('driver: %s', (string)$defaultConfiguration['driver'])
);

$postgresConfiguration = $withEnvironment(
    ['DATABASE_URL' => 'postgres://vendguard_app:secreta@aws-0-eu-central-1.pooler.supabase.com:6543/postgres'],
    static fn (): array => ConnectionFactory::describeConnection()
);

$assert(
    '2.2 Una cadena postgres:// activa la rama PostgreSQL',
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
    ['SUPABASE_DB_URL' => 'pgsql:host=db.pooler.supabase.com;port=6543;dbname=vendguard;user=vendguard_app;password=secreta'],
    static fn (): array => ConnectionFactory::describeConnection()
);

$assert(
    '2.6 La forma DSN del contrato del motor nuevo también declara PostgreSQL',
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

$mysqlUrlConfiguration = $withEnvironment(
    ['MYSQL_URL' => 'mysql://app_user:secreta@127.0.0.1:3306/vendguard_db'],
    static fn (): array => ConnectionFactory::describeConnection()
);

$assert(
    '2.8 Una cadena mysql:// conserva la rama heredada, sin TLS declarado',
    $mysqlUrlConfiguration['driver'] === ConnectionFactory::DRIVER_MYSQL
        && $mysqlUrlConfiguration['port'] === '3306'
        && $mysqlUrlConfiguration['ssl_mode'] === null
        && str_starts_with((string)$mysqlUrlConfiguration['dsn'], 'mysql:')
);

$declaredDriverAliases = $withEnvironment(
    [],
    static fn (): array => [
        'mariadb' => ConnectionFactory::describeConnection(['driver' => 'mariadb'])['driver'],
        'mysql' => ConnectionFactory::describeConnection(['driver' => 'mysql'])['driver'],
        'postgresql' => ConnectionFactory::describeConnection(['driver' => 'postgresql'])['driver'],
        'pgsql' => ConnectionFactory::describeConnection(['driver' => 'pgsql'])['driver'],
    ]
);

$assert(
    '2.9 Los alias de motor admitidos se resuelven a sus dos ramas canónicas',
    $declaredDriverAliases === [
        'mariadb' => ConnectionFactory::DRIVER_MYSQL,
        'mysql' => ConnectionFactory::DRIVER_MYSQL,
        'postgresql' => ConnectionFactory::DRIVER_PGSQL,
        'pgsql' => ConnectionFactory::DRIVER_PGSQL,
    ],
    json_encode($declaredDriverAliases)
);

$assert(
    '2.10 Un alias de motor desconocido no inventa rama: cae en el motor heredado',
    ConnectionFactory::describeConnection(['driver' => 'oracle'])['driver'] === ConnectionFactory::DRIVER_MYSQL
);

// =====================================================================
// GRUPO 3 — Plazos y opciones PDO
// =====================================================================

echo "\n--- Grupo 3: plazos y opciones PDO (EARS-04.1) ---\n";

$mysqlOptions = $mysqlUrlConfiguration['options'];
$postgresOptions = $postgresConfiguration['options'];

$assert(
    '3.1 El presupuesto de conexión es de 5 s en los dos motores (coherencia con el cliente)',
    $mysqlOptions[PDO::ATTR_TIMEOUT] === ConnectionFactory::CONNECT_TIMEOUT_SECONDS
        && $postgresOptions[PDO::ATTR_TIMEOUT] === ConnectionFactory::CONNECT_TIMEOUT_SECONDS
        && ConnectionFactory::CONNECT_TIMEOUT_SECONDS === 5,
    sprintf('mysql: %s · pgsql: %s', var_export($mysqlOptions[PDO::ATTR_TIMEOUT], true), var_export($postgresOptions[PDO::ATTR_TIMEOUT], true))
);

$assert(
    '3.2 Los dos motores declaran el mismo modo de error y el mismo modo de recuperación',
    $mysqlOptions[PDO::ATTR_ERRMODE] === PDO::ERRMODE_EXCEPTION
        && $postgresOptions[PDO::ATTR_ERRMODE] === PDO::ERRMODE_EXCEPTION
        && $mysqlOptions[PDO::ATTR_DEFAULT_FETCH_MODE] === PDO::FETCH_ASSOC
        && $postgresOptions[PDO::ATTR_DEFAULT_FETCH_MODE] === PDO::FETCH_ASSOC
);

$assert(
    '3.3 La emulación de preparadas está activa en PostgreSQL y desactivada en MariaDB',
    $postgresOptions[PDO::ATTR_EMULATE_PREPARES] === true
        && $mysqlOptions[PDO::ATTR_EMULATE_PREPARES] === false,
    sprintf('pgsql: %s · mysql: %s', var_export($postgresOptions[PDO::ATTR_EMULATE_PREPARES], true), var_export($mysqlOptions[PDO::ATTR_EMULATE_PREPARES], true))
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

$assert(
    '3.6 El DSN de MariaDB declara el charset de sesión',
    str_contains((string)$mysqlUrlConfiguration['dsn'], 'charset=utf8mb4')
);

$assert(
    '3.7 MariaDB fija la colación de la sesión con una sola sentencia de arranque',
    isset($mysqlOptions[PDO::MYSQL_ATTR_INIT_COMMAND])
        && str_contains((string)$mysqlOptions[PDO::MYSQL_ATTR_INIT_COMMAND], 'utf8mb4_unicode_ci')
);

$extraParameterConfiguration = $withEnvironment(
    ['DATABASE_URL' => 'postgres://app:secreta@db.local:6543/vendguard?application_name=vendguard&sslmode=verify-full'],
    static fn (): array => ConnectionFactory::describeConnection()
);

$assert(
    '3.8 Los parámetros que la factoría no resuelve se delegan al driver en el DSN',
    str_contains((string)$extraParameterConfiguration['dsn'], 'application_name=vendguard')
        && $extraParameterConfiguration['ssl_mode'] === ConnectionFactory::REQUIRED_SSL_MODE,
    (string)$extraParameterConfiguration['dsn']
);

// =====================================================================
// GRUPO 4 — TLS con verificación de identidad (RNF-05)
// =====================================================================

echo "\n--- Grupo 4: fallo en cerrado del TLS del motor nuevo (RNF-05) ---\n";

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

$assert(
    '4.6 La rama heredada no hereda la exigencia: su TLS sigue siendo opcional',
    $mysqlUrlConfiguration['ssl_root_certificate'] === null
        && !isset($mysqlOptions[PDO::MYSQL_ATTR_SSL_CA])
);

// =====================================================================
// GRUPO 5 — Política de credenciales (hallazgo S-5), idéntica en los dos motores
// =====================================================================

echo "\n--- Grupo 5: política de credenciales S-5, sin red y en los dos motores ---\n";

// Una sola declaración de entorno sirve para los dos motores: la regla es la misma y lo único que
// cambia es el usuario administrativo que cada uno tiene.
$withEnvironment(
    ['APP_ENV' => 'production', 'DATABASE_URL' => 'mysql://root@127.0.0.1:3306/vendguard_db'],
    static function () use ($assert): void {
        $thrown = null;
        try {
            ConnectionFactory::getConnection(['host' => '127.0.0.1', 'port' => '1', 'user' => 'root', 'password' => '', 'dbname' => 'vendguard_db'], true);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $assert(
            '5.1 MySQL: el rechazo nombra al usuario administrativo de su motor',
            $thrown instanceof RuntimeException
                && str_contains($thrown->getMessage(), 'root')
                && str_contains($thrown->getMessage(), 'mysql'),
            $thrown === null ? 'No lanzó ninguna excepción.' : $thrown->getMessage()
        );
    }
);

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
            '5.2 PostgreSQL: la misma regla, con el usuario administrativo de SU motor',
            $thrown instanceof RuntimeException
                && str_contains($thrown->getMessage(), 'postgres')
                && str_contains($thrown->getMessage(), 'pgsql'),
            $thrown === null ? 'No lanzó ninguna excepción.' : $thrown->getMessage()
        );
    }
);

// Sin una sola declaración de credenciales: es el caso que S-5 prohíbe en producción. Aquí
// «ninguna» es literal, y por eso las variables se retiran en vez de vaciarse.
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
        '5.3 Producción sin ninguna fuente explícita de credenciales tampoco se admite',
        $thrown instanceof RuntimeException
            && str_contains($thrown->getMessage(), 'S-5')
            && str_contains($thrown->getMessage(), 'credenciales explícitas'),
        $thrown === null ? 'No lanzó ninguna excepción.' : $thrown->getMessage()
    );

    // La distinción «ausente» frente a «declarada y vacía» no es cosmética: una credencial vacía
    // declarada cuenta como fuente, y entonces el rechazo es el del usuario administrativo, que es
    // otro diagnóstico distinto. Si esta aserción cambiase de mensaje, la política habría empezado
    // a tratar igual dos cosas que el proyecto decidió separar a propósito.
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
        '5.4 Una credencial declarada y vacía cuenta como declaración, no como ausencia',
        $declaredEmpty instanceof RuntimeException
            && str_contains($declaredEmpty->getMessage(), 'sin contraseña')
            && !str_contains($declaredEmpty->getMessage(), 'no hay credenciales explícitas'),
        $declaredEmpty === null ? 'No lanzó ninguna excepción.' : $declaredEmpty->getMessage()
    );
});

// El caso positivo se mide por donde se puede medir sin red: la política deja pasar y lo que
// llega es un fallo **de conexión** (puerto cerrado en loopback), no de credenciales. Y se
// cronometra contra un host no enrutable: si el rechazo esperase a la conexión, el reloj lo diría.
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
            '5.5 Con credenciales de aplicación la política deja pasar hasta la conexión',
            $thrown instanceof RuntimeException
                && str_contains($thrown->getMessage(), 'Fallo de conexión')
                && !str_contains($thrown->getMessage(), 'S-5'),
            $thrown === null ? 'No lanzó ninguna excepción.' : $thrown->getMessage()
        );
    }
);

$withEnvironment(
    ['APP_ENV' => 'production', 'VENDGUARD_ENV' => 'production'],
    static function () use ($assert): void {
        $startedAt = hrtime(true);
        try {
            ConnectionFactory::getConnection(['host' => '192.0.2.1', 'port' => '6543', 'user' => 'postgres', 'password' => '', 'dbname' => 'vendguard', 'driver' => 'postgres'], true);
        } catch (Throwable) {
            // El rechazo se mide por el reloj: lo que importa es que llega antes de conectar.
        }
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        $assert(
            '5.6 El rechazo es fail-fast: resuelve antes de intentar conectar (por debajo de 250 ms)',
            $elapsedMs < 250.0,
            sprintf('tardó %.2f ms: la política se resolvió después de abrir la conexión', $elapsedMs)
        );
    }
);

// =====================================================================
// Cierre
// =====================================================================

echo "\n======================================================================\n";
echo " Aserciones: {$assertions} | Fallos: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. La factoría de conexiones cumple su contrato en los dos motores.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
echo "======================================================================\n";
exit(1);
