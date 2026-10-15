<?php

declare(strict_types=1);

/**
 * SeedRunnerSingleEngineTest
 *
 * Suite propia del sembrador del arnés (T-13-04, EARS-13.3 y EARS-13.4).
 *
 * El sembrador es el eslabón crítico del porte: **85 ficheros de prueba** dependen de él
 * (79 lo instancian, más el cierre de la corrida, el diagnóstico de humo y los sembradores
 * de demostración), de modo que su ruta se certifica antes que la de ninguna otra suite.
 * Lo que se certifica aquí no es que las semillas existan —eso lo mide `SeedDataTest`
 * (T-04)— sino **por dónde se escriben** y **qué contrato cumplen**:
 *
 *   1. **La ruta es la del motor único.** El dialecto sale del adaptador y de la conexión
 *      viva, no de una suposición: el upsert es `ON CONFLICT … DO UPDATE` (la forma del
 *      motor que sirve producción) y en el fuente del sembrador no queda ni un identificador
 *      entre acentos graves ni una familia privativa del motor heredado fuera de sus
 *      comentarios.
 *   2. **El catálogo que escribe es el canónico.** Las diez sedes, las trece máquinas —cada
 *      una en su sede— y las seis cuentas que el sembrador declara son las que quedan
 *      activas, y sembrar no añade una sola fila fuera del catálogo: la identidad que el
 *      contrato de limpieza §8.1 protege es exactamente la que el sembrador escribe, porque
 *      una lista paralela se degrada en silencio.
 *   3. **Idempotencia de verdad.** Repetir la siembra no duplica: refresca la ficha
 *      propuesta, reactiva la sede dada de baja y rearma el freno de intentos de la clave de
 *      centro (hallazgo S-4). Se mide **degradando el catálogo a propósito** y volviendo a
 *      sembrar, no comparando dos corridas idénticas.
 *   4. **Claves de centro de desarrollo (hallazgo S-4).** La regla es `DEV-<site_code>` y sólo
 *      se siembra donde el contexto la admite —arnés o petición explícita, nunca con el
 *      entorno declarado de producción—: la clave sembrada abre sesión de sede por el
 *      servicio real y una ajena no la abre.
 *
 * Esta suite **toca maestras** (refresca el catálogo y degrada una sede para medir la
 * reparación), así que declara el gancho de restitución en su único sitio (contrato §8.3) y
 * restituye el catálogo en el mismo escenario en el que lo degrada.
 *
 * Dogma Vanilla: cero dependencias externas, PHP 8.2+ puro.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';
require_once __DIR__ . '/../../src/Infrastructure/Dialect/SqlDialectAdapter.php';
require_once __DIR__ . '/../../src/Application/Service/AuthService.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Service\SiteAccessCodeGenerator;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

echo "======================================================================\n";
echo " VendGuard: Sembrador del arnés por la ruta del motor único (T-13-04)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$dialect = SqlDialectAdapter::forConnection($pdo);
$runner = new SeedRunner($pdo);

// Contrato de limpieza §8.3: la suite toca maestras, así que la restitución del parque se
// declara aquí —en un solo sitio— y corre también tras un error fatal.
TestDataCleaner::registerShutdownRestitution();

$failures = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
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

/** Columna de texto del banco, tal cual, para comparar contra el catálogo. */
$column = static function (string $sql, array $params = []) use ($pdo): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map(
        static fn($value): string => (string)$value,
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );
};

/** Escalar único del banco (conteos, identidades sueltas). */
$scalar = static function (string $sql, array $params = []) use ($pdo): string {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (string)$stmt->fetchColumn();
};

$catalog = SeedRunner::canonicalIdentities();
$canonicalSites = array_map(static fn($value): string => (string)$value, $catalog['locations']['site_code']);
$canonicalMachines = array_map(static fn($value): string => (string)$value, $catalog['machines']['code']);
$canonicalUsers = array_map(static fn($value): string => (string)$value, $catalog['users']['email']);
$canonicalMachineSites = SeedRunner::canonicalMachineSites();

// Estado de partida: el parque tal y como lo deja el arnés, y el residuo que ya exista —de
// otra suite— para poder distinguir lo que la siembra añade de lo que ya estaba. `purge()`
// no se invoca aquí a propósito: esta suite no limpia el banco, certifica al sembrador.
$residueBefore = TestDataCleaner::countParkResidue($pdo);
$summary = $runner->seedAll();

// =====================================================================
// 1. La ruta es la del motor único (EARS-13.4)
// =====================================================================
echo "--- 1. La ruta es la del motor único ---\n";

$assert(
    '1.1 El adaptador del sembrador se resuelve desde la conexión viva y declara el motor de producción',
    $dialect->driver() === 'pgsql' && $dialect->isPostgres() && $dialect->driver() === (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
    'driver del adaptador: ' . $dialect->driver() . ' · driver de la conexión: ' . (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)
);

$upsert = $dialect->upsertAssignments(['site_code'], [
    'name' => SqlDialectAdapter::PROPOSED_VALUE,
    'login_attempts' => '0',
]);
$assert(
    '1.2 El upsert que sostiene la idempotencia es el del motor único, no la familia heredada',
    str_contains($upsert, 'ON CONFLICT') && str_contains($upsert, 'EXCLUDED.')
        && !str_contains($upsert, 'ON DUPLICATE KEY') && !str_contains($upsert, 'VALUES('),
    'upsert renderizado: ' . $upsert
);

// Escaneo del fuente sin comentarios: el dialecto heredado no puede volver por una línea de
// SQL escrita a mano. Los comentarios y docblocks quedan fuera del escaneo a propósito —citan
// sintaxis heredada para explicar por qué se retiró—, así que se tokeniza el fichero.
$seederPath = __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';
$seederSource = (string)file_get_contents($seederPath);
$seederCode = '';
foreach (token_get_all($seederSource) as $token) {
    if (is_array($token)) {
        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
            continue;
        }
        $seederCode .= $token[1];
        continue;
    }
    $seederCode .= $token;
}

$legacyPatterns = [
    'identificador entre acentos graves' => '/`[A-Za-z_][A-Za-z0-9_]*`/',
    'upsert del motor heredado' => '/\bON\s+DUPLICATE\s+KEY\b/i',
    'aritmética temporal del motor heredado' => '/\b(?:DATE_ADD|DATE_SUB|DATEDIFF|TIMESTAMPDIFF|STR_TO_DATE)\s*\(/i',
    'extracción JSON del motor heredado' => '/\b(?:JSON_EXTRACT|JSON_UNQUOTE)\s*\(/i',
    'funciones de texto del motor heredado' => '/\b(?:IFNULL|GROUP_CONCAT|LAST_INSERT_ID|DATABASE)\s*\(/i',
    'catálogo de metadatos del motor heredado' => '/\binformation_schema\b/i',
    'conmutador de integridad referencial del motor heredado' => '/FOREIGN_KEY_CHECKS/i',
];
$legacyFound = [];
foreach ($legacyPatterns as $family => $pattern) {
    if (preg_match($pattern, $seederCode, $match) === 1) {
        $legacyFound[] = $family . ' («' . $match[0] . '»)';
    }
}
$assert(
    '1.3 El fuente del sembrador no ejecuta dialecto del motor heredado fuera de sus comentarios',
    $legacyFound === [],
    'encontrado: ' . implode(' · ', $legacyFound)
);

$assert(
    '1.4 El sembrador construye su SQL con el adaptador y no a mano',
    str_contains($seederCode, '$this->dialect->upsertAssignments(')
        && str_contains($seederCode, '$this->dialect->booleanLiteral(')
        && str_contains($seederCode, '$this->dialect->identityKey('),
    'el fuente no invoca las tres familias del adaptador que sostienen la siembra'
);

// =====================================================================
// 2. El catálogo que escribe es el canónico (EARS-13.4, contrato §8.1)
// =====================================================================
echo "\n--- 2. El catálogo que escribe es el canónico ---\n";

$activeSites = $column('SELECT site_code FROM locations WHERE deleted_at IS NULL ORDER BY site_code');
$missingSites = array_values(array_diff($canonicalSites, $activeSites));
$assert(
    '2.1 Las diez sedes del catálogo están activas tras sembrar',
    $missingSites === [] && count($canonicalSites) === 10,
    'faltan: ' . json_encode($missingSites, JSON_UNESCAPED_UNICODE) . ' · activas: ' . count($activeSites)
);

$machineSites = [];
foreach ($pdo->query(
    'SELECT m.code, l.site_code FROM machines m JOIN locations l ON l.id = m.location_id WHERE m.deleted_at IS NULL'
)->fetchAll(PDO::FETCH_NUM) as $row) {
    $machineSites[(string)$row[0]] = (string)$row[1];
}
$misplaced = [];
$absentMachines = [];
foreach ($canonicalMachineSites as $code => $expectedSite) {
    if (!isset($machineSites[$code])) {
        $absentMachines[] = $code;
        continue;
    }
    if ($machineSites[$code] !== $expectedSite) {
        $misplaced[] = $code . ' → ' . $machineSites[$code] . ' (esperada ' . $expectedSite . ')';
    }
}
$assert(
    '2.2 Las trece máquinas del catálogo existen y cada una está en su sede canónica',
    count($canonicalMachines) === 13 && $absentMachines === [] && $misplaced === [],
    'ausentes: ' . json_encode($absentMachines, JSON_UNESCAPED_UNICODE) . ' · mal ubicadas: ' . json_encode($misplaced, JSON_UNESCAPED_UNICODE)
);

$activeUsers = $column('SELECT email FROM users WHERE deleted_at IS NULL ORDER BY email');
$missingUsers = array_values(array_diff($canonicalUsers, $activeUsers));
$assert(
    '2.3 Las seis cuentas del catálogo están activas tras sembrar',
    $missingUsers === [] && count($canonicalUsers) === 6,
    'faltan: ' . json_encode($missingUsers, JSON_UNESCAPED_UNICODE) . ' · activas: ' . count($activeUsers)
);

$assert(
    '2.4 El resumen de la siembra declara el catálogo completo (10 sedes · 13 máquinas · 6 cuentas)',
    (int)$summary['locations'] === 10 && (int)$summary['machines'] === 13 && (int)$summary['users'] === 6,
    'resumen: ' . json_encode($summary, JSON_UNESCAPED_UNICODE)
);

$preventiveAuditEvents = (int)$scalar(
    'SELECT COUNT(*) FROM audit_log WHERE ' . $dialect->castToText('metadata') . ' LIKE :marker',
    [':marker' => '%ORD-PREV-2026-0001%']
);
$restOfCatalog = [
    'preventive_settings' => (int)$scalar('SELECT COUNT(*) FROM preventive_settings'),
    'route_settings' => (int)$scalar('SELECT COUNT(*) FROM route_settings'),
    'preventive_orders' => (int)$scalar('SELECT COUNT(*) FROM preventive_orders'),
    'sanitary_certificates' => (int)$scalar('SELECT COUNT(*) FROM sanitary_certificates'),
    'preventive_order_items' => (int)$scalar('SELECT COUNT(*) FROM preventive_order_items'),
    'spare_parts' => (int)$scalar('SELECT COUNT(*) FROM spare_parts'),
    'preventive_audit_events' => $preventiveAuditEvents,
];
$assert(
    '2.5 El resto del catálogo queda sembrado (frecuencias, base central, órdenes, checklist, repuestos y auditoría)',
    $restOfCatalog['preventive_settings'] === 5
        && $restOfCatalog['route_settings'] === 1
        && $restOfCatalog['preventive_orders'] >= 3
        && $restOfCatalog['sanitary_certificates'] >= 2
        && $restOfCatalog['preventive_order_items'] >= 14
        && $restOfCatalog['spare_parts'] >= 13
        && $restOfCatalog['preventive_audit_events'] >= 1,
    'medido: ' . json_encode($restOfCatalog, JSON_UNESCAPED_UNICODE)
);

$residueAfter = TestDataCleaner::countParkResidue($pdo);
$assert(
    '2.6 Sembrar no crea una sola fila fuera del catálogo que el contrato §8.1 protege',
    (int)$residueAfter['total'] === (int)$residueBefore['total'],
    'residuo antes: ' . json_encode($residueBefore['by_table'], JSON_UNESCAPED_UNICODE)
        . ' · después: ' . json_encode($residueAfter['by_table'], JSON_UNESCAPED_UNICODE)
);

// =====================================================================
// 3. Idempotencia: el upsert del adaptador no duplica y reencuentra (EARS-13.4)
// =====================================================================
echo "\n--- 3. Idempotencia y reencuentro ---\n";

$canonicalCountsBefore = [
    'locations' => (int)$scalar('SELECT COUNT(*) FROM locations WHERE deleted_at IS NULL'),
    'machines' => (int)$scalar('SELECT COUNT(*) FROM machines WHERE deleted_at IS NULL'),
    'users' => (int)$scalar('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
];
$runner->seedAll();
$canonicalCountsAfter = [
    'locations' => (int)$scalar('SELECT COUNT(*) FROM locations WHERE deleted_at IS NULL'),
    'machines' => (int)$scalar('SELECT COUNT(*) FROM machines WHERE deleted_at IS NULL'),
    'users' => (int)$scalar('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
];
$assert(
    '3.1 Repetir la siembra no duplica ninguna maestra (el upsert resuelve por la clave)',
    $canonicalCountsBefore === $canonicalCountsAfter,
    'antes: ' . json_encode($canonicalCountsBefore, JSON_UNESCAPED_UNICODE)
        . ' · después: ' . json_encode($canonicalCountsAfter, JSON_UNESCAPED_UNICODE)
);

// Degradación deliberada de una sede del catálogo: ficha alterada, baja lógica y freno de
// intentos disparado. Lo que se mide es la reparación, no que la siembra sea repetible sin
// efecto: el `finally` la vuelve a sembrar aunque una aserción lance.
$degradedSite = 'SEDE-BCN-10';
$canonicalName = '';
foreach ($pdo->query('SELECT site_code, name FROM locations WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_NUM) as $row) {
    if ((string)$row[0] === $degradedSite) {
        $canonicalName = (string)$row[1];
    }
}
$pdo->prepare(
    'UPDATE locations SET name = :name, deleted_at = CURRENT_TIMESTAMP, login_attempts = 5, '
    . 'login_locked_until = CURRENT_TIMESTAMP + INTERVAL \'15 minutes\' WHERE site_code = :site'
)->execute([':name' => 'FICHA ALTERADA POR LA SUITE', ':site' => $degradedSite]);

try {
    $runner->seedAll();

    $restored = $pdo->prepare('SELECT name, deleted_at, login_attempts, login_locked_until FROM locations WHERE site_code = :site');
    $restored->execute([':site' => $degradedSite]);
    $row = $restored->fetch(PDO::FETCH_ASSOC) ?: [];

    // `array_key_exists` y no `??`: la columna existe y su valor es `null` a propósito —una baja
    // lógica que se levanta—, y el operador de coalescencia trataría ese `null` como ausencia.
    $assert(
        '3.2 Una sede alterada y dada de baja vuelve a su ficha canónica y se reactiva',
        array_key_exists('deleted_at', $row)
            && (string)$row['name'] === $canonicalName
            && $row['deleted_at'] === null,
        'nombre: ' . json_encode($row['name'], JSON_UNESCAPED_UNICODE)
            . ' · deleted_at: ' . json_encode($row['deleted_at'], JSON_UNESCAPED_UNICODE)
            . ' · ficha canónica: ' . json_encode($canonicalName, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '3.3 El freno de intentos de la clave de centro vuelve a cero al sembrar (S-4)',
        array_key_exists('login_locked_until', $row)
            && (int)$row['login_attempts'] === 0
            && $row['login_locked_until'] === null,
        'intentos: ' . json_encode($row['login_attempts']) . ' · bloqueada hasta: ' . json_encode($row['login_locked_until'])
    );

    $assert(
        '3.4 La sede reparada es una sola fila, no un duplicado del catálogo',
        (int)$scalar('SELECT COUNT(*) FROM locations WHERE site_code = :site', [':site' => $degradedSite]) === 1,
        'filas con esa sede: ' . $scalar('SELECT COUNT(*) FROM locations WHERE site_code = :site', [':site' => $degradedSite])
    );
} finally {
    // Restitución en el mismo escenario: el catálogo no puede quedar degradado por una
    // aserción fallida (contrato §8.5).
    $runner->seedAll();
}

// =====================================================================
// 4. Claves de centro de desarrollo (hallazgo S-4)
// =====================================================================
echo "\n--- 4. Claves de centro de desarrollo (hallazgo S-4) ---\n";

$assert(
    '4.1 La regla de la clave de desarrollo es DEV-<site_code>, con el código normalizado',
    SeedRunner::devAccessCode('SEDE-BCN-01') === 'DEV-SEDE-BCN-01'
        && SeedRunner::devAccessCode(' sede-bcn-01 ') === 'DEV-SEDE-BCN-01',
    'clave derivada: ' . SeedRunner::devAccessCode('SEDE-BCN-01')
);

$assert(
    '4.2 En el contexto del arnés la siembra de claves se declara abierta',
    SeedRunner::devAccessCodeSeedingEnabled() === true,
    'el bootstrap de pruebas no define VENDGUARD_TESTING'
);

$previousAppEnv = getenv('APP_ENV');
$previousVendguardEnv = getenv('VENDGUARD_ENV');
putenv('APP_ENV=production');
$refusedByAppEnv = SeedRunner::devAccessCodeSeedingEnabled() === false;
putenv('APP_ENV');
putenv('VENDGUARD_ENV=prod');
$refusedByVendguardEnv = SeedRunner::devAccessCodeSeedingEnabled() === false;
putenv('VENDGUARD_ENV');
if (is_string($previousAppEnv) && $previousAppEnv !== '') {
    putenv('APP_ENV=' . $previousAppEnv);
}
if (is_string($previousVendguardEnv) && $previousVendguardEnv !== '') {
    putenv('VENDGUARD_ENV=' . $previousVendguardEnv);
}
$assert(
    '4.3 Con el entorno declarado de producción la siembra de claves se cierra (no se siembra un secreto conocido)',
    $refusedByAppEnv && $refusedByVendguardEnv,
    'APP_ENV=production la cierra: ' . ($refusedByAppEnv ? 'sí' : 'no') . ' · VENDGUARD_ENV=prod la cierra: ' . ($refusedByVendguardEnv ? 'sí' : 'no')
);

$authService = new AuthService();
$session = $authService->loginSite('SEDE-BCN-01', SeedRunner::devAccessCode('SEDE-BCN-01'));
$assert(
    '4.4 La clave de desarrollo sembrada abre sesión de sede por el servicio real',
    is_array($session) && ($session['location']->getSiteCode() ?? '') === 'SEDE-BCN-01' && ($session['token'] ?? '') !== '',
    'sesión: ' . json_encode(is_array($session) ? array_keys($session) : null)
);

$foreignKeyRefused = $authService->loginSite('SEDE-BCN-01', SeedRunner::devAccessCode('SEDE-BCN-99')) === null;
$assert(
    '4.5 Una clave ajena no abre la sede',
    $foreignKeyRefused,
    'la sede aceptó una clave que no es la suya'
);

// El intento fallido dejó un intento contabilizado sobre una sede del catálogo. Se rearma por
// la ruta que usa el cierre de la corrida —el reencuentro del propio sembrador— y se comprueba
// que el contador queda a cero **antes** de la sesión, y que la clave vuelve a abrir.
$runner->seedLocations();
$attemptsAfterRearm = (int)$scalar('SELECT login_attempts FROM locations WHERE site_code = :site', [':site' => 'SEDE-BCN-01']);
$reopened = $authService->loginSite('SEDE-BCN-01', SeedRunner::devAccessCode('SEDE-BCN-01'));
$assert(
    '4.6 El reencuentro rearma el freno de la sede y la clave de centro vuelve a abrir sesión',
    $attemptsAfterRearm === 0
        && is_array($reopened)
        && (int)$scalar('SELECT COUNT(*) FROM locations WHERE deleted_at IS NULL AND login_locked_until IS NOT NULL') === 0,
    'intentos tras rearmar: ' . $attemptsAfterRearm
        . ' · sesión reabierta: ' . (is_array($reopened) ? 'sí' : 'no')
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. SIEMBRA POR LA RUTA DEL MOTOR ÚNICO CERTIFICADA (T-13-04).\n"
    : " RESULTADO: HAY FALLOS. EL SEMBRADOR NO CUMPLE SU CONTRATO (T-13-04).\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
