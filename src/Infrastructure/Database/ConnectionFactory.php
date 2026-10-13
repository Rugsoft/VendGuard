<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Infrastructure\Config\SecretProvider;

/**
 * ConnectionFactory
 *
 * Factoría única de conexiones PDO, con **detección automática de motor** (EARS-01.4): la misma
 * aplicación opera indistintamente contra PostgreSQL administrado en la nube —por el agrupador
 * transaccional Supavisor, puerto 6543— o contra MariaDB/MySQL local, sin que la lógica de
 * dominio ni el frontend cambien una línea.
 *
 * El motor lo declara la propia configuración: una cadena de conexión con esquema
 * `postgres://`, `postgresql://` o `pgsql:` —en forma de URL o de DSN de PDO— activa la rama
 * PostgreSQL; cualquier otra cosa conserva la rama MariaDB/MySQL de siempre, que es la de todos
 * los entornos locales y la del arnés hermético.
 *
 * Contrato de la rama PostgreSQL (EARS-01.2 y RNF-05):
 * - **Agrupador transaccional**: `PDO::ATTR_EMULATE_PREPARES => true`. Sin emulación, las
 *   sentencias preparadas del servidor no sobreviven al cambio de conexión física del agrupador
 *   y la operación muere con «prepared statement does not exist».
 * - **TLS con verificación de identidad del servidor**: `sslmode=verify-full` más la CA
 *   declarada en `sslrootcert`. Degradar a `require`, `prefer`, `allow` o `disable` está
 *   prohibido: `require` cifra y **no** verifica la identidad, de modo que el criterio se
 *   certificaría en falso. Si la CA declarada no existe, la factoría falla en cerrado en lugar
 *   de sustituirla en silencio por otra.
 * - **Presupuesto de conexión de 5 s** (`ATTR_TIMEOUT`), coherente extremo a extremo con el
 *   timeout ordinario que ya aplica el cliente (EARS-04.1).
 *
 * Política de credenciales (hallazgo S-5), **idéntica en los dos drivers** (EARS-01.6): en
 * desarrollo se conserva el acceso local (`root` sin contraseña sobre XAMPP), pero cuando el
 * entorno se declara de producción la factoría **falla en cerrado antes de intentar conectar**:
 * exige una fuente explícita de credenciales (`DATABASE_URL`/`SUPABASE_DB_URL`/`MYSQL_URL` o
 * `DB_USER`/`DB_PASSWORD`) y rechaza al usuario administrativo de cada motor —`root` en MySQL,
 * `postgres` en PostgreSQL— sin contraseña. No hay valores por defecto silenciosos: un
 * `postgres://` de producción sin contraseña falla aquí, con su nombre y su motivo, y no en un
 * error genérico de autenticación al final del arranque.
 *
 * Cumple con Dogma Vanilla (sin dependencias externas) y Clean Architecture.
 */
class ConnectionFactory
{
    /** Motores relacionales admitidos. */
    public const DRIVER_PGSQL = 'pgsql';
    public const DRIVER_MYSQL = 'mysql';

    /** Puerto del agrupador transaccional Supavisor: el camino declarado de la nube (EARS-01.2). */
    public const POOLER_PORT = '6543';

    /** Modo TLS exigido al motor en la nube: cifrado **con** verificación de identidad (RNF-05). */
    public const REQUIRED_SSL_MODE = 'verify-full';

    /** Presupuesto de conexión del driver, en segundos (EARS-04.1). */
    public const CONNECT_TIMEOUT_SECONDS = 5;

    /** Esquemas y prefijos que declaran cada motor en la cadena de conexión. */
    private const PGSQL_SCHEMES = ['postgres', 'postgresql', 'pgsql'];
    private const MYSQL_SCHEMES = ['mysql', 'mariadb'];

    /** Puerto y base de datos por defecto de cada motor. */
    private const DEFAULT_PORT_MYSQL = '3306';
    private const DEFAULT_DATABASE_MYSQL = 'vendguard_db';
    private const DEFAULT_DATABASE_PGSQL = 'postgres';

    /** Usuario administrativo de cada motor: sin contraseña no se admite en producción (S-5). */
    private const ADMINISTRATIVE_USERS = [
        self::DRIVER_MYSQL => 'root',
        self::DRIVER_PGSQL => 'postgres',
    ];

    /**
     * Almacenes de CA candidatos, en el orden en que cada rama los prefiere.
     *
     * PostgreSQL: primero el almacén que instala la imagen (`ca-certificates`) y después la CA
     * que el proyecto ya versiona para el motor heredado (`RNF-05`). MySQL: se conserva el orden
     * histórico (la CA incluida en el proyecto primero) para no alterar el comportamiento vivo.
     */
    private const PGSQL_SSL_CA_CANDIDATES = [
        '/etc/ssl/certs/ca-certificates.crt',
        __DIR__ . '/isrgrootx1.pem',
    ];
    private const MYSQL_SSL_CA_CANDIDATES = [
        __DIR__ . '/isrgrootx1.pem',
        '/etc/ssl/certs/ca-certificates.crt',
    ];

    /**
     * Claves de configuración que constituyen una fuente explícita de credenciales (S-5).
     * `driver` queda fuera a propósito: declarar el motor no es declarar credenciales.
     */
    private const CREDENTIAL_CONFIG_KEYS = [
        'host', 'port', 'dbname', 'database', 'charset',
        'user', 'password', 'ssl', 'sslmode', 'ssl_mode', 'sslrootcert', 'ssl_root_cert',
    ];

    private static ?PDO $instance = null;

    /**
     * Obtiene una conexión PDO configurada.
     *
     * @param array<string, mixed> $customConfig Configuración opcional para sobrescribir variables de entorno.
     * @param bool $fresh Si es true, fuerza la creación de una nueva instancia en lugar de reutilizar la existente.
     * @return PDO
     * @throws RuntimeException Si la configuración es insegura en producción o la conexión falla.
     */
    public static function getConnection(array $customConfig = [], bool $fresh = false): PDO
    {
        if (self::$instance !== null && !$fresh && empty($customConfig)) {
            return self::$instance;
        }

        $configuration = self::resolveConnection($customConfig);

        // La política se resuelve ANTES de abrir la conexión: en producción no se llega a
        // intentar conectar con credenciales inseguras (hallazgo S-5, EARS-01.6).
        self::assertCredentialPolicy($configuration);

        try {
            $pdo = new PDO(
                $configuration['dsn'],
                $configuration['user'],
                $configuration['password'],
                $configuration['options']
            );

            if (!$fresh && empty($customConfig)) {
                self::$instance = $pdo;
            }

            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException("Fallo de conexión a la base de datos: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Resuelve la configuración efectiva de conexión **sin abrir ninguna conexión**.
     *
     * Superficie de certificación: permite comprobar el driver detectado, el DSN construido, las
     * opciones PDO y el modo TLS sin depender de que el motor esté disponible ni de que la red lo
     * permita —es la única vía de certificar la rama PostgreSQL en la batería hermética, donde no
     * hay motor nuevo—. La contraseña nunca se devuelve; se informa de si quedó declarada.
     *
     * @param array<string, mixed> $customConfig Configuración opcional para sobrescribir variables de entorno.
     * @return array<string, mixed>
     * @throws RuntimeException Si el TLS pedido degrada la verificación de identidad (RNF-05).
     */
    public static function describeConnection(array $customConfig = []): array
    {
        $configuration = self::resolveConnection($customConfig);
        $configuration['password_declared'] = $configuration['password'] !== '';
        $configuration['password'] = null;

        return $configuration;
    }

    /**
     * Restablece la instancia en caché (útil para suites de test).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Resuelve driver, credenciales, TLS y opciones a partir de la configuración explícita y del
     * entorno. No abre conexión ni lanza la política de credenciales: eso lo decide el llamante,
     * de modo que la resolución pueda certificarse sin red.
     *
     * @param array<string, mixed> $customConfig
     * @return array<string, mixed>
     */
    private static function resolveConnection(array $customConfig): array
    {
        $connectionString = self::firstNonEmpty(
            self::readEnvironmentValue('DATABASE_URL'),
            self::readEnvironmentValue('SUPABASE_DB_URL'),
            self::readEnvironmentValue('MYSQL_URL')
        );

        $declared = $connectionString !== null ? self::parseConnectionString($connectionString) : [];
        $driver = self::resolveDriver($declared, $customConfig);
        $isPostgres = $driver === self::DRIVER_PGSQL;

        $host = (string)self::firstNonEmpty(
            $customConfig['host'] ?? null,
            $declared['host'] ?? null,
            self::readEnvironmentValue('DB_HOST'),
            '127.0.0.1'
        );

        // El puerto por defecto de PostgreSQL es el del agrupador transaccional (6543): el plan
        // ratificado descarta el 5432 en la nube porque no pasa por Supavisor (EARS-01.2).
        $port = (string)self::firstNonEmpty(
            $customConfig['port'] ?? null,
            $declared['port'] ?? null,
            self::readEnvironmentValue('DB_PORT'),
            $isPostgres ? self::POOLER_PORT : self::DEFAULT_PORT_MYSQL
        );

        $database = (string)self::firstNonEmpty(
            $customConfig['dbname'] ?? null,
            $declared['database'] ?? null,
            self::readEnvironmentValue('DB_NAME'),
            self::readEnvironmentValue('DB_DATABASE'),
            $isPostgres ? self::DEFAULT_DATABASE_PGSQL : self::DEFAULT_DATABASE_MYSQL
        );

        $user = (string)self::firstNonEmpty(
            $customConfig['user'] ?? null,
            $declared['user'] ?? null,
            self::readEnvironmentValue('DB_USER'),
            self::readEnvironmentValue('DB_USERNAME'),
            self::ADMINISTRATIVE_USERS[$driver]
        );

        // Una contraseña declarada y vacía es una declaración, no una ausencia: no puede caer en
        // un valor por defecto (política S-5).
        $password = (string)self::firstDeclared(
            $customConfig['password'] ?? null,
            $declared['password'] ?? null,
            self::readEnvironmentValue('DB_PASS'),
            self::readEnvironmentValue('DB_PASSWORD')
        );

        $charset = (string)self::firstNonEmpty($customConfig['charset'] ?? null, 'utf8mb4');

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // El agrupador en modo transacción exige emulación en PostgreSQL; MariaDB conserva
            // las sentencias preparadas nativas de siempre (EARS-01.2).
            PDO::ATTR_EMULATE_PREPARES => $isPostgres,
            PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ];

        $sslRootCertificate = null;

        if ($isPostgres) {
            $ssl = self::resolvePostgresTls([
                'mode' => self::firstNonEmpty(
                    $customConfig['sslmode'] ?? null,
                    $customConfig['ssl_mode'] ?? null,
                    $declared['ssl_mode'] ?? null
                ),
                'root_certificate' => self::firstNonEmpty(
                    $customConfig['sslrootcert'] ?? null,
                    $customConfig['ssl_root_cert'] ?? null,
                    $declared['ssl_root_cert'] ?? null,
                    self::readEnvironmentValue('DB_SSL_ROOT_CERT')
                ),
                'disable_requested' => array_key_exists('ssl', $customConfig) && $customConfig['ssl'] === false,
            ]);

            $sslRootCertificate = $ssl['root_certificate'];

            // UTF-8 es la codificación del motor y de la base creada por el DDL: el DSN de
            // PostgreSQL no admite el parámetro `charset` de MySQL.
            $dsn = 'pgsql:host=' . $host
                . ';port=' . $port
                . ';dbname=' . $database
                . ';sslmode=' . $ssl['mode']
                . ';sslrootcert=' . $sslRootCertificate
                . self::buildExtraDsnParameters($declared['parameters'] ?? []);
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$charset} COLLATE {$charset}_unicode_ci";

            // TLS opcional del motor heredado (TiDB Cloud, Aiven, ...): se conserva tal cual.
            // Este camino no hereda la tolerancia al certificado del motor nuevo: allí RNF-05
            // exige verificación de identidad y aquí se mantiene el comportamiento vivo.
            $enableSsl = self::resolveMysqlSslFlag($customConfig, $host);
            if ($enableSsl) {
                $ca = self::firstExistingFile(self::MYSQL_SSL_CA_CANDIDATES);
                if ($ca !== null) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                }
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                $sslRootCertificate = $ca;
            }
        }

        return [
            'driver' => $driver,
            'dsn' => $dsn,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'user' => $user,
            'password' => $password,
            'charset' => $charset,
            'options' => $options,
            'ssl_mode' => $isPostgres ? self::REQUIRED_SSL_MODE : null,
            'ssl_root_certificate' => $sslRootCertificate,
            'uses_pooler' => $isPostgres && $port === self::POOLER_PORT,
            'administrative_user' => self::ADMINISTRATIVE_USERS[$driver],
            'has_explicit_credential_source' => self::hasExplicitCredentialSource($customConfig, $connectionString),
        ];
    }

    /**
     * ¿Se activa TLS en la rama MariaDB/MySQL? Declaración explícita, variable de entorno o
     * proveedores gestionados conocidos, exactamente como hasta ahora.
     *
     * @param array<string, mixed> $customConfig
     */
    private static function resolveMysqlSslFlag(array $customConfig, string $host): bool
    {
        if (array_key_exists('ssl', $customConfig) && $customConfig['ssl'] !== null) {
            return (bool)$customConfig['ssl'];
        }

        return in_array(self::readEnvironmentValue('DB_SSL'), ['true', '1'], true)
            || str_contains($host, 'tidbcloud.com')
            || str_contains($host, 'aivencloud.com');
    }

    /**
     * Política de credenciales del proyecto (hallazgo S-5), igual para los dos drivers.
     *
     * Se resuelve antes de conectar y sin valores por defecto silenciosos: en producción hace
     * falta una fuente explícita de credenciales, y el usuario administrativo del motor
     * —`root` en MySQL, `postgres` en PostgreSQL— no se admite sin contraseña.
     *
     * @param array<string, mixed> $configuration
     */
    private static function assertCredentialPolicy(array $configuration): void
    {
        if (!SecretProvider::isProduction()) {
            return;
        }

        if ($configuration['has_explicit_credential_source'] !== true) {
            throw new RuntimeException(
                'Configuración de base de datos insegura en producción (hallazgo S-5): no hay credenciales explícitas. Define DATABASE_URL/MYSQL_URL o DB_USER/DB_PASSWORD antes de desplegar.'
            );
        }

        if ($configuration['user'] === $configuration['administrative_user'] && $configuration['password'] === '') {
            throw new RuntimeException(sprintf(
                'Configuración de base de datos insegura en producción (hallazgo S-5): el usuario %s sin contraseña no está permitido (usuario administrativo de %s). Usa un usuario de aplicación con contraseña.',
                $configuration['administrative_user'],
                $configuration['driver']
            ));
        }
    }

    /**
     * Fuente explícita de credenciales: configuración que las declare, cadena de conexión del
     * entorno o variables de usuario/contraseña. Declarar solo el motor no cuenta.
     *
     * @param array<string, mixed> $customConfig
     */
    private static function hasExplicitCredentialSource(array $customConfig, ?string $connectionString): bool
    {
        foreach (self::CREDENTIAL_CONFIG_KEYS as $key) {
            if (array_key_exists($key, $customConfig)) {
                return true;
            }
        }

        if ($connectionString !== null) {
            return true;
        }

        foreach (['DB_USER', 'DB_USERNAME', 'DB_PASS', 'DB_PASSWORD'] as $name) {
            if (self::readEnvironmentValue($name) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Driver activo: lo declara la cadena de conexión y, si no, la configuración explícita. Sin
     * declaración, el motor heredado (MariaDB/MySQL), que es el de todos los entornos locales.
     *
     * @param array<string, mixed> $declared
     * @param array<string, mixed> $customConfig
     */
    private static function resolveDriver(array $declared, array $customConfig): string
    {
        if (isset($declared['driver'])) {
            return (string)$declared['driver'];
        }

        if (isset($customConfig['driver'])) {
            $declaredDriver = self::driverFromScheme(strtolower(trim((string)$customConfig['driver'])));
            if ($declaredDriver !== null) {
                return $declaredDriver;
            }
        }

        return self::DRIVER_MYSQL;
    }

    /**
     * Interpreta la cadena de conexión en sus dos formas admitidas: URL
     * (`postgres://usuario:clave@host:6543/base?sslmode=verify-full`) o DSN de PDO
     * (`pgsql:host=…;port=…;dbname=…;user=…;password=…`), que es la forma que declara la
     * categoría de certificación del motor nuevo.
     *
     * @return array<string, mixed>
     */
    private static function parseConnectionString(string $connectionString): array
    {
        $parsed = @parse_url($connectionString);
        if (!is_array($parsed)) {
            $parsed = [];
        }

        $scheme = isset($parsed['scheme']) ? strtolower((string)$parsed['scheme']) : '';
        $driver = self::driverFromScheme($scheme);

        // Forma DSN: el prefijo declara el motor y los parámetros viajan en la ruta.
        if ($driver !== null && !isset($parsed['host']) && isset($parsed['path']) && str_contains((string)$parsed['path'], '=')) {
            return self::parseDsnString($driver, (string)$parsed['path']);
        }

        if (!isset($parsed['host'])) {
            return $driver !== null ? ['driver' => $driver] : [];
        }

        $declared = [
            'host' => (string)$parsed['host'],
            'port' => isset($parsed['port']) ? (string)$parsed['port'] : null,
            'database' => isset($parsed['path']) ? ltrim((string)$parsed['path'], '/') : null,
            'user' => isset($parsed['user']) ? rawurldecode((string)$parsed['user']) : null,
            'password' => isset($parsed['pass']) ? rawurldecode((string)$parsed['pass']) : null,
        ];
        if ($driver !== null) {
            $declared['driver'] = $driver;
        }

        $parameters = self::parseConnectionQuery(isset($parsed['query']) ? (string)$parsed['query'] : '');
        if ($parameters['declared'] !== []) {
            $declared = array_merge($declared, $parameters['declared']);
        }
        if ($parameters['extra'] !== []) {
            $declared['parameters'] = $parameters['extra'];
        }

        return $declared;
    }

    /**
     * Separa los parámetros de consulta de una URL entre los que la factoría resuelve (TLS) y los
     * que se delegan al driver.
     *
     * @return array{declared: array<string, string>, extra: array<string, string>}
     */
    private static function parseConnectionQuery(string $query): array
    {
        if ($query === '') {
            return ['declared' => [], 'extra' => []];
        }

        parse_str($query, $queryParameters);

        $declared = [];
        $extra = [];
        foreach ($queryParameters as $name => $value) {
            $name = strtolower((string)$name);
            $value = is_scalar($value) ? (string)$value : '';

            if ($name === 'sslmode') {
                $declared['ssl_mode'] = $value;
            } elseif ($name === 'sslrootcert') {
                $declared['ssl_root_cert'] = $value;
            } elseif ($name !== '') {
                $extra[$name] = $value;
            }
        }

        return ['declared' => $declared, 'extra' => $extra];
    }

    /**
     * Interpreta una cadena en forma de DSN de PDO (`host=…;port=…;dbname=…;user=…;password=…`).
     * Las credenciales se extraen del texto para no reemitirlas dentro del DSN.
     *
     * @return array<string, mixed>
     */
    private static function parseDsnString(string $driver, string $parameters): array
    {
        $declared = ['driver' => $driver];
        $extra = [];

        foreach (explode(';', $parameters) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            if ($name === '') {
                continue;
            }

            switch ($name) {
                case 'host':
                    $declared['host'] = $value;
                    break;
                case 'port':
                    $declared['port'] = $value;
                    break;
                case 'dbname':
                case 'database':
                    $declared['database'] = $value;
                    break;
                case 'user':
                    $declared['user'] = $value;
                    break;
                case 'password':
                    $declared['password'] = $value;
                    break;
                case 'sslmode':
                    $declared['ssl_mode'] = $value;
                    break;
                case 'sslrootcert':
                    $declared['ssl_root_cert'] = $value;
                    break;
                default:
                    $extra[$name] = $value;
                    break;
            }
        }

        if ($extra !== []) {
            $declared['parameters'] = $extra;
        }

        return $declared;
    }

    /**
     * Concatena al DSN de PostgreSQL los parámetros extra que declare la cadena de conexión
     * (`application_name`, `connect_timeout`, ...), que libpq entiende.
     *
     * @param array<string, string> $parameters
     */
    private static function buildExtraDsnParameters(array $parameters): string
    {
        $dsn = '';

        foreach ($parameters as $name => $value) {
            $name = (string)$name;
            $value = (string)$value;

            // Un valor con separadores o comillas permitiría reinyectar una cláusula posterior
            // (`sslmode=disable`) y anular en silencio la verificación de identidad del motor:
            // RNF-05 se defiende aquí, rechazando en voz alta en lugar de degradar.
            if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1 || preg_match('/[;\'"\\\\\r\n]/', $value) === 1) {
                throw new RuntimeException(sprintf(
                    'Parámetro de conexión no admitido («%s»): un valor con separadores o comillas podría reescribir las cláusulas obligatorias del DSN (sslmode/sslrootcert) y anular la verificación de identidad del motor en la nube (RNF-05).',
                    $name
                ));
            }

            $dsn .= ';' . $name . '=' . $value;
        }

        return $dsn;
    }

    /**
     * Bloque TLS obligatorio de la rama PostgreSQL (RNF-05): modo verificado y CA declarada.
     *
     * @param array<string, mixed> $declared
     * @return array{mode: string, root_certificate: string}
     */
    private static function resolvePostgresTls(array $declared): array
    {
        if (($declared['disable_requested'] ?? false) === true) {
            throw new RuntimeException(
                'El motor en la nube no admite conexiones sin TLS (RNF-05): la configuración pide desactivarlo. Toda conexión a PostgreSQL se cifra con verificación de identidad del servidor.'
            );
        }

        $requestedMode = isset($declared['mode']) && $declared['mode'] !== '' ? (string)$declared['mode'] : null;
        if ($requestedMode !== null && $requestedMode !== self::REQUIRED_SSL_MODE) {
            throw new RuntimeException(sprintf(
                'Modo TLS no admitido («%s») para el motor en la nube (RNF-05): se exige sslmode=%s con verificación de identidad del servidor, y está prohibido degradar a require, prefer, allow o disable.',
                $requestedMode,
                self::REQUIRED_SSL_MODE
            ));
        }

        $declaredCertificate = isset($declared['root_certificate']) && $declared['root_certificate'] !== ''
            ? (string)$declared['root_certificate']
            : null;

        return [
            'mode' => self::REQUIRED_SSL_MODE,
            'root_certificate' => self::resolvePostgresRootCertificate($declaredCertificate),
        ];
    }

    /**
     * CA con la que se verifica la identidad del motor en la nube. Si se declara una, esa manda y
     * tiene que existir; si no, se usa la primera candidata disponible. Sin CA no hay conexión:
     * `verify-full` sin raíz de confianza no verifica nada.
     */
    private static function resolvePostgresRootCertificate(?string $declaredCertificate): string
    {
        if ($declaredCertificate !== null) {
            if (!is_file($declaredCertificate)) {
                throw new RuntimeException(sprintf(
                    'La CA declarada para el motor en la nube no existe («%s»): no se sustituye en silencio por otra, porque verify-full dejaría de verificar la identidad que RNF-05 exige.',
                    $declaredCertificate
                ));
            }

            return $declaredCertificate;
        }

        $candidate = self::firstExistingFile(self::PGSQL_SSL_CA_CANDIDATES);
        if ($candidate !== null) {
            return $candidate;
        }

        throw new RuntimeException(
            'No hay ninguna CA de confianza para verificar la identidad del motor en la nube (RNF-05): declara sslrootcert o instala el almacén de certificados de la imagen. El criterio no se degrada.'
        );
    }

    /**
     * Primer fichero existente de la lista, o `null` si no hay ninguno.
     *
     * @param list<string> $candidates
     */
    private static function firstExistingFile(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Driver al que corresponde un esquema o prefijo de conexión, o `null` si no declara ninguno. */
    private static function driverFromScheme(string $scheme): ?string
    {
        if (in_array($scheme, self::PGSQL_SCHEMES, true)) {
            return self::DRIVER_PGSQL;
        }

        if (in_array($scheme, self::MYSQL_SCHEMES, true)) {
            return self::DRIVER_MYSQL;
        }

        return null;
    }

    /** Primer valor declarado no vacío: los ausentes y los vacíos no deciden. */
    private static function firstNonEmpty(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate)) {
                $value = trim((string)$candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** Primer valor declarado, aunque sea la cadena vacía: una contraseña vacía es una declaración. */
    private static function firstDeclared(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate)) {
                return (string)$candidate;
            }
        }

        return null;
    }

    /**
     * Lee una variable de entorno de las fuentes habituales de PHP. Devuelve `null` si no está
     * definida y la cadena vacía si está definida y vacía: la diferencia decide la política de
     * credenciales (una contraseña vacía declarada no es lo mismo que una contraseña ausente).
     */
    private static function readEnvironmentValue(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? null;
        }

        return is_string($value) ? $value : null;
    }
}
