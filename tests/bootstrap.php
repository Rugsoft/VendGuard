<?php

declare(strict_types=1);

/**
 * VendGuard - Bootstrap de Pruebas Automatizadas
 *
 * Configuración común para las suites de pruebas unitarias e integración.
 *
 * **Motor del arnés (módulo 13).** La batería hermética resuelve su persistencia con
 * `DATABASE_URL`, que es la variable que `ConnectionFactory` lee **primero**: el arnés apunta
 * al mismo motor que la aplicación, por la misma ruta, y no por un canal lateral. El motor es
 * PostgreSQL —el banco aprovisionado en `deploy/local/postgres/`— y ningún otro: un
 * `DATABASE_URL` que declare el motor heredado se rechaza aquí, antes de que una sola suite
 * corra, para que no pueda haber un verde medido contra el motor equivocado.
 *
 * Fuentes del contrato, en este orden:
 *   1. El entorno del proceso (lo que el operador haya cargado con `source`).
 *   2. El contrato versionado del banco: `deploy/local/postgres/.env.battery` (no versionado
 *      el fichero, sí su procedimiento: lo genera `provision-tls-engine.sh`).
 * Si ninguna de las dos declara el motor, el arnés **no** improvisa valores por defecto: se
 * declara no ejecutable y sale con código 1 (EARS-13.1 y 13.6).
 *
 * El contrato se declara en el entorno del proceso (`putenv`), de modo que los procesos hijos
 * que abre el ejecutor —una suite por proceso— heredan el mismo motor sin volver a resolverlo.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Contexto de pruebas: habilita las claves de centro de desarrollo de las sedes
// semilla (hallazgo S-4). `SeedRunner::devAccessCodeSeedingEnabled()` exige esta
// constante —o `VENDGUARD_DEV_SITE_KEYS=1`— y jamás siembra con el entorno
// declarado de producción, que es el arranque del contenedor desplegado.
define('VENDGUARD_TESTING', true);

/**
 * Resuelve el contrato del motor del arnés a partir del entorno o del contrato del banco.
 *
 * @return array{dsn: ?string, source: ?string}
 */
function vendguardResolveTestEngine(): array
{
    $declared = getenv('DATABASE_URL');
    if (is_string($declared) && trim($declared) !== '') {
        return ['dsn' => trim($declared), 'source' => 'entorno del proceso'];
    }

    $contractPath = __DIR__ . '/../deploy/local/postgres/.env.battery';
    if (is_file($contractPath)) {
        $contents = (string)file_get_contents($contractPath);
        if (preg_match('/^\s*(?:export\s+)?DATABASE_URL\s*=\s*(.+?)\s*$/m', $contents, $matches) === 1) {
            // El valor puede venir entrecomillado por el `export` del contrato.
            $dsn = trim(trim((string)$matches[1]), "\"'");
            if ($dsn !== '') {
                $resolved = realpath($contractPath);
                return ['dsn' => $dsn, 'source' => 'contrato del banco (' . ($resolved === false ? $contractPath : $resolved) . ')'];
            }
        }
    }

    return ['dsn' => null, 'source' => null];
}

/**
 * Describe el motor declarado sin revelar la credencial: motor, base y destino.
 *
 * @return string
 */
function vendguardDescribeTestEngine(string $dsn): string
{
    $value = static function (string $key, string $fallback) use ($dsn): string {
        return preg_match('/' . $key . '=([^;]+)/i', $dsn, $m) === 1 ? (string)$m[1] : $fallback;
    };

    return sprintf(
        'PostgreSQL · base %s en %s:%s',
        $value('dbname', '(sin declarar)'),
        $value('host', '127.0.0.1'),
        $value('port', '5432')
    );
}

$engine = vendguardResolveTestEngine();

if ($engine['dsn'] === null) {
    fwrite(STDERR, "\n[ARNÉS NO EJECUTABLE] No hay motor declarado para la batería.\n");
    fwrite(STDERR, "  La batería resuelve su persistencia con DATABASE_URL y no admite valores por defecto.\n");
    fwrite(STDERR, "  Aprovisiona y carga el banco de la batería:\n");
    fwrite(STDERR, "    bash deploy/local/postgres/provision-tls-engine.sh\n");
    fwrite(STDERR, "    source deploy/local/postgres/.env.battery\n");
    fwrite(STDERR, "  (o deja que el arnés lea el contrato directamente: deploy/local/postgres/.env.battery)\n");
    exit(1);
}

// Guarda de motor único (EARS-13.1): el arnés mide el motor que sirve producción. Un contrato
// que declare el motor heredado no se ejecuta «en otro motor»: se rechaza.
if (preg_match('/^\s*(mysql|mariadb)\s*:/i', $engine['dsn']) === 1) {
    fwrite(STDERR, "\n[ARNÉS NO EJECUTABLE] El contrato declara el motor heredado (MySQL/MariaDB).\n");
    fwrite(STDERR, "  El sistema tiene un solo motor: PostgreSQL. Revisa DATABASE_URL y el contrato del banco.\n");
    exit(1);
}

putenv('DATABASE_URL=' . $engine['dsn']);
$_ENV['DATABASE_URL'] = $engine['dsn'];
$_SERVER['DATABASE_URL'] = $engine['dsn'];

require_once __DIR__ . '/Support/EngineClock.php';

// El reloj del arnés también se declara, no se hereda. Sin esta línea cada `new
// DateTimeImmutable()` y cada `date()` desnudos de las suites tomaban la zona del `php.ini` del
// desarrollador —`Europe/Berlin` en la máquina donde se midió este defecto—, de modo que el
// veredicto de la batería dependía de la máquina: la misma suite medía distinto en local que en
// CI (EARS-13.5 y 13.6: la batería es hermética y no improvisa). Se declara en la zona operativa
// del parque, que es la que el sistema documenta para sus instantes locales (`IncidentPauseService`,
// `AuditEvent`, `MetricFilter`), y las marcas que cruzan al motor se escriben por `EngineClock`,
// único punto donde vive la zona de la sesión del motor (`UTC`, la de sus columnas con zona).
date_default_timezone_set(EngineClock::OPERATIONAL_TIMEZONE);

// La declaración se imprime una sola vez —en el proceso que arranca la corrida— y se marca en
// el entorno para que las 259 suites hijas no la repitan en cada línea de la salida.
if (getenv('VENDGUARD_TEST_ENGINE_DECLARED') === false) {
    echo ' Arnés: motor único ' . vendguardDescribeTestEngine($engine['dsn'])
        . ' · contrato: ' . $engine['source']
        . ' · reloj: ' . date_default_timezone_get() . " (motor en " . EngineClock::SESSION_TIMEZONE . ")\n";
    putenv('VENDGUARD_TEST_ENGINE_DECLARED=1');
    $_ENV['VENDGUARD_TEST_ENGINE_DECLARED'] = '1';
}

require_once __DIR__ . '/../src/autoload.php';

// Limpiador compartido de datos de prueba. Se carga aquí para que TODAS las
// suites lo tengan disponible sin un `require_once` propio: el orden de borrado
// seguro se deriva del grafo real de claves foráneas, de modo que ninguna tabla
// hija con ON DELETE RESTRICT (refund_requests, spare_part_requests,
// incident_replaced_parts, unclaimed_cash_findings) puede volver a reventar un
// `DELETE FROM incidents`. Ver specs/technical/testing_cleanup_contract.md
require_once __DIR__ . '/Support/TestDataCleaner.php';

// Calendario comercial del arnés (08:00-18:00, lunes a viernes, Europe/Madrid). Se
// carga aquí por el mismo motivo: las suites que certifican el desplazamiento del
// vencimiento contractual (RF-03.3, cierre de F-3) calculan su expectativa con esta
// implementación independiente en lugar de reutilizar el servicio de producción.
require_once __DIR__ . '/Support/BusinessCalendar.php';
