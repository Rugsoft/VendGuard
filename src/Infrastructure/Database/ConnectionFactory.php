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
 * Factoría única de conexiones PDO para el **motor único del sistema: PostgreSQL (Supabase)**
 * (EARS-13.1, EARS-13.2).
 *
 * La persistencia opera de forma homogénea contra PostgreSQL administrado en la nube
 * —por el agrupador transaccional Supavisor, puerto 6543, o directo— o contra el motor
 * PostgreSQL local del arnés y desarrollo. No se conservan ramas, valores por defecto ni
 * credenciales del motor heredado MariaDB/MySQL.
 *
 * Contrato de persistencia (EARS-01.2, RNF-05, EARS-13.1, EARS-13.2):
 * - **Driver único**: `pgsql` (PostgreSQL).
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
 * Política de credenciales (hallazgo S-5, EARS-13.2):
 * En producción la factoría **falla en cerrado antes de intentar conectar**: exige una fuente
 * explícita de credenciales (`DATABASE_URL`, `SUPABASE_DB_URL` o `DB_USER`/`DB_PASSWORD`) y
 * rechaza al usuario administrativo del motor (`postgres`) sin contraseña. No hay valores por
 * defecto silenciosos: un `postgres://` de producción sin contraseña falla aquí, con su nombre
 * y su motivo, y no en un error genérico de autenticación al final del arranque.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO, sin dependencias externas.
 */
class ConnectionFactory
{
    /** Motor relacional único del sistema (EARS-13.1). */
    public const DRIVER_PGSQL = 'pgsql';
    public const DRIVER_MYSQL = 'mysql';

    /** Puerto del agrupador transaccional Supavisor: el camino declarado de la nube (EARS-01.2). */
    public const POOLER_PORT = '6543';

    /** Modo TLS exigido al motor en la nube: cifrado **con** verificación de identidad (RNF-05). */
    public const REQUIRED_SSL_MODE = 'verify-full';

    /** Presupuesto de conexión del driver, en segundos (EARS-04.1). */
    public const CONNECT_TIMEOUT_SECONDS = 5;

    /** Esquemas y prefijos que declaran el motor PostgreSQL. */
    private const PGSQL_SCHEMES = ['postgres', 'postgresql', 'pgsql'];

    /** Puerto y base de datos por defecto de PostgreSQL. */
    private const DEFAULT_PORT_PGSQL = '6543';
    private const DEFAULT_DATABASE_PGSQL = 'postgres';

    /** Usuario administrativo del motor PostgreSQL (S-5). */
    private const ADMINISTRATIVE_USER = 'postgres';

    /**
     * Almacenes de CA candidatos para verificar la identidad del servidor (RNF-05).
     */
    private const PGSQL_SSL_CA_CANDIDATES = [
        __DIR__ . '/prod-ca-2021.crt',
        '/etc/ssl/certs/ca-certificates.crt',
        __DIR__ . '/isrgrootx1.pem',
    ];

    /**
     * Claves de configuración que constituyen una fuente explícita de credenciales (S-5).
     */
    private const CREDENTIAL_CONFIG_KEYS = [
        'host', 'port', 'dbname', 'database',
        'user', 'password', 'ssl', 'sslmode', 'ssl_mode', 'sslrootcert', 'ssl_root_cert',
    ];

    private static ?PDO $instance = null;

    /**
     * Cota ambiente de espera de bloqueo declarada por el proceso, en segundos, o `null` si
     * no hay ninguna vigente (T-HYBRID-07, T-PAUSE-40).
     */
    private static ?int $ambientLockWaitSeconds = null;

    /**
     * Obtiene una conexión PDO configurada contra el motor único PostgreSQL.
     *
     * @param array<string, mixed> $customConfig Configuración opcional para sobrescribir variables de entorno.
     * @param bool $fresh Si es true, fuerza la creación de una nueva instancia en lugar de reutilizar la existente.
     * @return PDO
     * @throws RuntimeException Si la configuración no es PostgreSQL, es insegura en producción o la conexión falla.
     */
    public static function getConnection(array $customConfig = [], bool $fresh = false): PDO
    {
        if (self::$instance !== null && !$fresh && empty($customConfig)) {
            return self::$instance;
        }

        $configuration = self::resolveConnection($customConfig);

        // La política se resuelve ANTES de abrir la conexión (EARS-13.2, S-5).
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
     * @param array<string, mixed> $customConfig Configuración opcional para sobrescribir variables de entorno.
     * @return array<string, mixed>
     * @throws RuntimeException Si el driver no es PostgreSQL o si el TLS degrada la verificación de identidad (RNF-05).
     */
    public static function describeConnection(array $customConfig = []): array
    {
        $configuration = self::resolveConnection($customConfig);
        $configuration['password_declared'] = $configuration['password'] !== '';
        $configuration['password'] = null;

        return $configuration;
    }

    /**
     * Restablece la instancia en caché y la cota ambiente (útil para suites de test).
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$ambientLockWaitSeconds = null;
    }

    /**
     * Declara la cota ambiente de espera de bloqueo del proceso y devuelve la anterior.
     *
     * @param int|null $seconds Cota en segundos, o `null` para retirarla.
     * @return int|null La cota anterior.
     */
    public static function declareAmbientLockWaitSeconds(?int $seconds): ?int
    {
        $previous = self::$ambientLockWaitSeconds;
        self::$ambientLockWaitSeconds = $seconds;

        return $previous;
    }

    /**
     * Cota ambiente de espera de bloqueo vigente, o `null` si el proceso no ha declarado ninguna.
     */
    public static function ambientLockWaitSeconds(): ?int
    {
        return self::$ambientLockWaitSeconds;
    }

    /**
     * Resuelve driver, credenciales, TLS y opciones a partir de la configuración explícita y del entorno.
     *
     * @param array<string, mixed> $customConfig
     * @return array<string, mixed>
     */
    private static function resolveConnection(array $customConfig): array
    {
        $connectionString = self::firstNonEmpty(
            $customConfig['DATABASE_URL'] ?? null,
            $customConfig['connection_string'] ?? null,
            self::readEnvironmentValue('DATABASE_URL'),
            self::readEnvironmentValue('SUPABASE_DB_URL')
        );

        $declared = $connectionString !== null ? self::parseConnectionString($connectionString) : [];
        $driver = self::resolveDriver($declared, $customConfig);

        $host = (string)self::firstNonEmpty(
            $customConfig['host'] ?? null,
            $declared['host'] ?? null,
            self::readEnvironmentValue('DB_HOST'),
            '127.0.0.1'
        );

        // Puerto por defecto: agrupador transaccional (6543)
        $port = (string)self::firstNonEmpty(
            $customConfig['port'] ?? null,
            $declared['port'] ?? null,
            self::readEnvironmentValue('DB_PORT'),
            self::DEFAULT_PORT_PGSQL
        );

        $database = (string)self::firstNonEmpty(
            $customConfig['dbname'] ?? null,
            $declared['database'] ?? null,
            self::readEnvironmentValue('DB_NAME'),
            self::readEnvironmentValue('DB_DATABASE'),
            self::DEFAULT_DATABASE_PGSQL
        );

        $user = (string)self::firstNonEmpty(
            $customConfig['user'] ?? null,
            $declared['user'] ?? null,
            self::readEnvironmentValue('DB_USER'),
            self::readEnvironmentValue('DB_USERNAME'),
            self::ADMINISTRATIVE_USER
        );

        $password = (string)self::firstDeclared(
            $customConfig['password'] ?? null,
            $declared['password'] ?? null,
            self::readEnvironmentValue('DB_PASS'),
            self::readEnvironmentValue('DB_PASSWORD')
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
        ];

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

        $dsn = 'pgsql:host=' . $host
            . ';port=' . $port
            . ';dbname=' . $database
            . ';sslmode=' . $ssl['mode']
            . ';sslrootcert=' . $sslRootCertificate
            . self::buildExtraDsnParameters($declared['parameters'] ?? []);

        return [
            'driver' => $driver,
            'dsn' => $dsn,
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'user' => $user,
            'password' => $password,
            'options' => $options,
            'ssl_mode' => self::REQUIRED_SSL_MODE,
            'ssl_root_certificate' => $sslRootCertificate,
            'uses_pooler' => $port === self::POOLER_PORT,
            'administrative_user' => self::ADMINISTRATIVE_USER,
            'has_explicit_credential_source' => self::hasExplicitCredentialSource($customConfig, $connectionString),
        ];
    }

    /**
     * Política de credenciales del proyecto (hallazgo S-5, EARS-13.2).
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
                'Configuración de base de datos insegura en producción (hallazgo S-5): no hay credenciales explícitas. Define DATABASE_URL o DB_USER/DB_PASSWORD antes de desplegar.'
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
     * Fuente explícita de credenciales.
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
     * Resuelve el driver validando que sea el motor único PostgreSQL (EARS-13.1).
     * Cualquier configuración o intento de usar otro motor es rechazado.
     *
     * @param array<string, mixed> $declared
     * @param array<string, mixed> $customConfig
     * @throws RuntimeException Si se intenta configurar un motor distinto a PostgreSQL.
     */
    private static function resolveDriver(array $declared, array $customConfig): string
    {
        $rawDriver = null;
        if (isset($declared['driver'])) {
            $rawDriver = (string)$declared['driver'];
        } elseif (isset($customConfig['driver'])) {
            $rawDriver = (string)$customConfig['driver'];
        }

        if ($rawDriver !== null) {
            $normalized = strtolower(trim($rawDriver));
            $resolved = self::driverFromScheme($normalized);
            if ($resolved !== self::DRIVER_PGSQL) {
                throw new RuntimeException(sprintf(
                    'Motor de base de datos no admitido («%s»): el sistema opera exclusivamente con PostgreSQL (Supabase) bajo el contrato de motor único (EARS-13.1).',
                    $rawDriver
                ));
            }

            return self::DRIVER_PGSQL;
        }

        if (isset($customConfig['user']) && $customConfig['user'] === 'root') {
            throw new RuntimeException(sprintf(
                'Motor de base de datos no admitido («mysql/root»): el sistema opera exclusivamente con PostgreSQL (Supabase) bajo el contrato de motor único (EARS-13.1).'
            ));
        }

        return self::DRIVER_PGSQL;
    }

    /**
     * Interpreta la cadena de conexión en formato URL o DSN.
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
        if ($scheme !== '' && $driver === null) {
            throw new RuntimeException(sprintf(
                'Motor de base de datos no admitido («%s»): el sistema opera exclusivamente con PostgreSQL (Supabase) bajo el contrato de motor único (EARS-13.1).',
                $scheme
            ));
        }

        // Forma DSN: pgsql:host=...
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
     * Parámetros de consulta de la URL.
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
     * Concatena parámetros adicionales en el DSN de PostgreSQL.
     *
     * @param array<string, string> $parameters
     */
    private static function buildExtraDsnParameters(array $parameters): string
    {
        $dsn = '';

        foreach ($parameters as $name => $value) {
            $name = (string)$name;
            $value = (string)$value;

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
     * Bloque TLS obligatorio de PostgreSQL (RNF-05).
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
     * CA de confianza para PostgreSQL.
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
     * Primer fichero existente de la lista.
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

    /**
     * Valida el esquema del motor. Devuelve DRIVER_PGSQL o null.
     */
    private static function driverFromScheme(string $scheme): ?string
    {
        if (in_array($scheme, self::PGSQL_SCHEMES, true)) {
            return self::DRIVER_PGSQL;
        }

        return null;
    }

    /** Primer valor declarado no vacío. */
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

    /** Primer valor declarado, aunque sea cadena vacía. */
    private static function firstDeclared(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_scalar($candidate)) {
                return (string)$candidate;
            }
        }

        return null;
    }

    /** Lee una variable de entorno. */
    private static function readEnvironmentValue(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? null;
        }

        return is_string($value) ? $value : null;
    }
}
