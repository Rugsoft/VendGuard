<?php

declare(strict_types=1);

/**
 * HarnessResidueGuardTest
 *
 * Guarda del residuo del parque del banco de pruebas (hallazgo F-4 del módulo 11,
 * contrato de limpieza §8).
 *
 * El hallazgo: `TestDataCleaner::purge()` protege las maestras porque son del
 * negocio, y ese blindaje dejaba el otro lado descubierto — lo que crean las
 * suites no lo retiraba nadie, así que el banco acumulaba filas en cada corrida
 * (206 sedes frente a las 10 semilla al abrirse el hallazgo). El defecto era
 * invisible porque ninguna suite rompía: sólo el parque se degradaba.
 *
 * El primer cierre midió el residuo con un **prefijo reservado** (`SEDE-TEST-*`,
 * `SEDE-MAP*`). Era una lista paralela al catálogo y, por serlo, se degradaba en
 * silencio: una suite que nombrara su sede de otra forma (`SEDE-TEMP-*`) quedaba
 * fuera del criterio, y ninguna regla de nombre podía ver lo que otra suite
 * colgase de una **sede semilla** —una máquina nueva en una sede del catálogo— ni
 * una **cuenta** creada por una suite. El parque real lo demostró: el banco tenía
 * una sede y una cuenta que el detector declaraba inexistentes.
 *
 * El criterio es ahora la identidad contra el catálogo canónico que declara el
 * propio sembrador (`SeedRunner::canonicalIdentities()`, contrato §8.5): lo que se
 * siembra es lo que se protege, y todo lo demás es del arnés.
 *
 * Lo que esta suite impide es que el limpiador vuelva a degradarse en un helper que
 * "parece" funcionar. No se limita a declarar la invariante: le planta residuo de
 * las tres familias —sede, máquina colgada de una sede semilla y cuenta—, comprueba
 * que el detector lo ve, que el borrado ingenuo del caso real **fracasa** con el
 * rechazo del motor, que la restitución drena el subárbol entero sin tocar una sola
 * fila del catálogo, y que **devuelve a su sede** la máquina del catálogo que una suite
 * reubicó, abortando sólo lo que el catálogo no puede situar.
 *
 * **Motor único (módulo 13):** la suite escribe el dialecto del motor que sirve
 * producción. Antes reconocía el rechazo «en la forma de cada motor» (1451 de
 * MariaDB o 45000 del disparador de PostgreSQL); con un solo motor se certifica una
 * sola forma, y esa es la que sirve producción.
 *
 * Dogma Vanilla: cero dependencias externas, PHP 8.2+ puro.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';
require_once __DIR__ . '/../../src/Infrastructure/Dialect/SqlDialectAdapter.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - HarnessResidueGuardTest (cierre de F-4)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// La clave canónica de identidad (`site_code_key`, `email_key`) **no** se escribe a mano ni se
// adivina: la calcula el mismo adaptador que usa la aplicación (A-1/A-2, hallazgo S-4), que es el
// único punto donde vive esa regla. Una sede o una cuenta del arnés tiene que parecerse a las reales.
$dialect = new SqlDialectAdapter((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

// Sede y máquina semilla del escenario 4: la máquina se reubica en una sede del arnés
// para provocar el aborto y se devuelve a su sede al cerrar el escenario.
$seedSiteCode = 'SEDE-BCN-01';
$seedMachineCode = 'VEND-0101';

$homeSeedMachine = static function () use ($pdo, $seedSiteCode, $seedMachineCode): void {
    $pdo->prepare(
        'UPDATE machines SET location_id = (SELECT id FROM locations WHERE site_code = :site) WHERE code = :code'
    )->execute([':site' => $seedSiteCode, ':code' => $seedMachineCode]);
};

// Red de seguridad del escenario 4, declarada ANTES de la restitución porque los ganchos
// de apagado corren en orden de declaración (FIFO): si la guarda muere entre la reubicación
// de una máquina del catálogo y su devolución, primero se la devuelve a su sede sembrada y
// sólo después se restituye el parque, de modo que una guarda rota no deje una máquina de
// negocio apuntando a una sede del arnés.
register_shutdown_function(static function () use ($homeSeedMachine): void {
    $homeSeedMachine();
});

// Restitución del parque ante cualquier salida (contrato de limpieza §8.3): esta
// suite planta residuo a propósito, así que se drena también tras un error fatal.
TestDataCleaner::registerShutdownRestitution();

// Estado de partida normalizado: sin incidencias huérfanas y con las semillas puestas.
// NO se vacían las maestras: precisamente lo que se mide aquí es si el arnés dejó residuo
// en ellas, y `purge()` no las toca por diseño.
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

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
$column = static function (string $sql) use ($pdo): array {
    return array_map(
        static fn($value): string => (string)$value,
        $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)
    );
};

/**
 * Comparación como conjunto: el catálogo se declara en orden de siembra, no alfabético, así
 * que contrastarlo por orden mediría el orden de dos listas y no la pertenencia.
 */
$asSet = static function (array $values): array {
    $sorted = array_map(static fn($value): string => (string)$value, $values);
    sort($sorted, SORT_STRING);

    return $sorted;
};

/** Id de una fila localizada por su identidad, para encadenar las altas del escenario. */
$idOf = static function (string $table, string $identityColumn, string $value) use ($pdo): int {
    return (int)$pdo->query(
        'SELECT id FROM ' . $table . ' WHERE ' . $identityColumn . ' = ' . $pdo->quote($value)
    )->fetchColumn();
};

// =========================================================================
// BLOQUE 1: El criterio es el catálogo, no una lista paralela de nombres
// =========================================================================

echo "--- Bloque 1: El criterio de residuo ---\n";

$catalog = SeedRunner::canonicalIdentities();

$start = TestDataCleaner::countParkResidue($pdo);
$assert(
    '1.1 La corrida anterior no dejó ninguna maestra fuera del catálogo',
    $start['total'] === 0,
    "residuo de partida: {$start['total']} fila(s) " . json_encode($start['by_table'], JSON_UNESCAPED_UNICODE)
        . ' — alguna suite no restituyó su estado (contrato §8.3)'
);

$assert(
    '1.2 El catálogo canónico declara las tres maestras del parque con su identidad',
    array_keys($catalog) === ['locations', 'machines', 'users']
        && array_keys($catalog['locations']) === ['site_code']
        && array_keys($catalog['machines']) === ['code']
        && array_keys($catalog['users']) === ['email'],
    'declarado: ' . json_encode(array_map('array_keys', $catalog), JSON_UNESCAPED_UNICODE)
);

$seededSites = $column("SELECT site_code FROM locations WHERE deleted_at IS NULL AND is_active = TRUE");
$assert(
    '1.3 El catálogo coincide exactamente con el parque sembrado (10 sedes, 13 máquinas, 6 cuentas)',
    count($catalog['locations']['site_code']) === 10
        && count($catalog['machines']['code']) === 13
        && count($catalog['users']['email']) === 6
        && $asSet($column('SELECT code FROM machines')) === $asSet($catalog['machines']['code'])
        && $asSet($column('SELECT email FROM users WHERE deleted_at IS NULL')) === $asSet($catalog['users']['email'])
        && array_values(array_intersect($catalog['locations']['site_code'], $seededSites)) === $catalog['locations']['site_code'],
    'catálogo: ' . json_encode(array_map('count', $catalog), JSON_UNESCAPED_UNICODE)
);

$assert(
    '1.4 El detector mide las tres maestras del parque',
    array_keys($start['by_table']) === ['locations', 'machines', 'users'],
    'medidas: ' . json_encode(array_keys($start['by_table']), JSON_UNESCAPED_UNICODE)
);

// ─── Escenario del residuo real, en sus tres familias: una sede cuyo nombre el criterio
// retirado no miraba (`SEDE-TEMP-*`, la fuga viva del banco) y otra en baja lógica; una
// máquina colgada de una **sede semilla**; y una **cuenta** del arnés con avería asignada. ──
$harnessSiteCode = 'SEDE-TEMP-GUARD';
$deletedSiteCode = 'SEDE-MAP-GUARD';
$harnessMachineOnOwnSite = 'GUARD-A1';
$harnessMachineOnSeedSite = 'VEND-GUARD-X';
$harnessTechnicianEmail = 'tecnico.guarda@vendguard.internal';

// Altas con el booleano **literal** del motor y no con un parámetro: el valor booleano no
// tiene una forma textual portable y adivinarla es justo el tipo de suposición que este
// arnés no hace. La baja lógica se declara con `NOW()`, que es del motor.
$insertSite = $pdo->prepare(
    "INSERT INTO locations (site_code, site_code_key, name, address, latitude, longitude, contact_name, contact_phone, is_active, deleted_at)
     VALUES (:code, :key, :name, 'Carrer de la Guarda 1, Barcelona', 41.39, 2.19, 'Contacto Guarda', '600000123', :active, :deleted)"
);
$insertSite->execute([
    ':code' => $harnessSiteCode,
    ':key' => $dialect->identityKey($harnessSiteCode),
    ':name' => 'Sede del arnés viva',
    ':active' => 'TRUE',
    ':deleted' => null,
]);
$insertSite->execute([
    ':code' => $deletedSiteCode,
    ':key' => $dialect->identityKey($deletedSiteCode),
    ':name' => 'Sede del arnés en baja lógica',
    ':active' => 'FALSE',
    ':deleted' => (new DateTimeImmutable())->format('Y-m-d H:i:sP'),
]);

$insertMachine = $pdo->prepare(
    "INSERT INTO machines (location_id, code, model, machine_type, floor_wing, notes, is_active, sanitary_status, next_sanitary_inspection_due)
     VALUES (:location_id, :code, 'Modelo Guarda', 'SNACKS', 'Planta 1', NULL, TRUE, 'OK', CURRENT_DATE + INTERVAL '30 days')"
);
$insertMachine->execute([
    ':location_id' => $idOf('locations', 'site_code', $harnessSiteCode),
    ':code' => $harnessMachineOnOwnSite,
]);
// La máquina del arnés que cuelga de una sede **semilla**: el caso que ningún criterio de
// nombres podía ver, porque la sede que la sostiene sí es del catálogo.
$insertMachine->execute([
    ':location_id' => $idOf('locations', 'site_code', $seedSiteCode),
    ':code' => $harnessMachineOnSeedSite,
]);

$pdo->prepare(
    "INSERT INTO users (name, email, email_key, password_hash, role, phone, is_active)
     VALUES ('Técnico de guarda', :email, :key, :hash, 'TECHNICIAN', '600000456', TRUE)"
)->execute([
    ':email' => $harnessTechnicianEmail,
    ':key' => $dialect->identityKey($harnessTechnicianEmail),
    ':hash' => password_hash('Guarda123!', PASSWORD_BCRYPT, ['cost' => 4]),
]);
$harnessTechnicianId = $idOf('users', 'email', $harnessTechnicianEmail);

$insertIncident = $pdo->prepare(
    "INSERT INTO incidents (ticket_code, machine_id, location_id, assigned_technician_id, category, description, urgency)
     VALUES (:ticket, :machine_id, :location_id, :technician_id, 'PRODUCT_JAM', 'Avería plantada por la guarda de residuo.', 'HIGH')"
);
$insertIncident->execute([
    ':ticket' => 'TST-GUARD-0001',
    ':machine_id' => $idOf('machines', 'code', $harnessMachineOnOwnSite),
    ':location_id' => $idOf('locations', 'site_code', $harnessSiteCode),
    ':technician_id' => null,
]);
$insertIncident->execute([
    ':ticket' => 'TST-GUARD-0002',
    ':machine_id' => $idOf('machines', 'code', $harnessMachineOnSeedSite),
    ':location_id' => $idOf('locations', 'site_code', $seedSiteCode),
    ':technician_id' => $harnessTechnicianId,
]);

$planted = TestDataCleaner::countParkResidue($pdo);
$assert(
    '1.5 El detector ve las tres familias: dos sedes, la máquina de la sede semilla y la cuenta',
    $planted['total'] === $start['total'] + 5
        && $planted['by_table']['locations'] === 2
        && $planted['by_table']['machines'] === 2
        && $planted['by_table']['users'] === 1
        && in_array($harnessMachineOnSeedSite, $planted['samples']['machines'] ?? [], true)
        && in_array($harnessTechnicianEmail, $planted['samples']['users'] ?? [], true),
    'contado: ' . json_encode($planted, JSON_UNESCAPED_UNICODE)
);

// El criterio retirado (contrato §8.1) se declara aquí como testigo histórico: de las cuatro
// raíces del residuo plantado sólo miraba la `SEDE-MAP-*`. La sede `SEDE-TEMP-*`, la máquina
// colgada de la sede semilla y la cuenta quedaban fuera, y esa ceguera es exactamente lo que
// encontró el banco real. Un futuro retorno a las listas de nombres tropieza con esta aserción.
// Los prefijos se declaran como prefijos y no como patrones de comodín porque la regla del
// contrato decía «empiece por»: se mide eso y sólo eso.
$retiredPrefixes = ['SEDE-TEST-', 'SEDE-MAP'];
$retiredRuleSees = static function (string $identity) use ($retiredPrefixes): bool {
    foreach ($retiredPrefixes as $prefix) {
        if (str_starts_with($identity, $prefix)) {
            return true;
        }
    }

    return false;
};
$coveredByRetiredRule = array_values(array_filter(
    [$harnessSiteCode, $deletedSiteCode, $harnessMachineOnSeedSite, $harnessTechnicianEmail],
    $retiredRuleSees
));
$assert(
    '1.6 El criterio retirado del prefijo sólo alcanzaba una de las cuatro raíces del residuo',
    $coveredByRetiredRule === [$deletedSiteCode],
    'alcanzadas por el prefijo reservado: ' . json_encode($coveredByRetiredRule, JSON_UNESCAPED_UNICODE)
);

// =========================================================================
// BLOQUE 2: El borrado ingenuo fracasa (por qué hace falta el subárbol)
// =========================================================================

echo "\n--- Bloque 2: La mordida del subárbol RESTRICT ---\n";

$naiveDelete = function (PDO $pdo, string $sql, array $params): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return ['failed' => false, 'message' => ''];
    } catch (PDOException $e) {
        return ['failed' => true, 'message' => $e->getMessage()];
    }
};

// El rechazo se reconoce en la forma del motor único: el disparador constitucional (`45000`)
// y la restricción de clave foránea del propio motor.
$refusedNaiveDelete = static function (array $outcome): bool {
    if (($outcome['failed'] ?? false) !== true) {
        return false;
    }
    $message = (string)($outcome['message'] ?? '');

    return str_contains($message, '45000')
        || str_contains($message, 'ART_III_1_VIOLATION')
        || str_contains($message, 'foreign key constraint');
};

$naiveLocation = $naiveDelete($pdo, 'DELETE FROM locations WHERE site_code = :code', [':code' => $harnessSiteCode]);
$assert(
    '2.1 El DELETE ingenuo de la sede fracasa con el rechazo del motor',
    $refusedNaiveDelete($naiveLocation),
    'la sentencia debía fracasar y no lo hizo: sin subárbol no hay limpieza real (mensaje: '
        . $naiveLocation['message'] . ')'
);

$naiveMachine = $naiveDelete($pdo, 'DELETE FROM machines WHERE code = :code', [':code' => $harnessMachineOnSeedSite]);
$assert(
    '2.2 El DELETE ingenuo de la máquina fracasa por la avería colgada',
    $refusedNaiveDelete($naiveMachine),
    'mensaje: ' . $naiveMachine['message']
);

$naiveUser = $naiveDelete($pdo, 'DELETE FROM users WHERE email = :email', [':email' => $harnessTechnicianEmail]);
$assert(
    '2.3 El DELETE ingenuo de la cuenta fracasa por la avería que tiene asignada',
    $refusedNaiveDelete($naiveUser),
    'mensaje: ' . $naiveUser['message']
);

$assert(
    '2.4 Tras los tres intentos fallidos el expediente sigue completo',
    (int)$pdo->query('SELECT COUNT(*) FROM locations WHERE site_code = ' . $pdo->quote($harnessSiteCode))->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM machines WHERE code = ' . $pdo->quote($harnessMachineOnSeedSite))->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM users WHERE email = ' . $pdo->quote($harnessTechnicianEmail))->fetchColumn() === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'TST-GUARD-000%'")->fetchColumn() === 2
);

// =========================================================================
// BLOQUE 3: La restitución drena el subárbol y respeta el catálogo
// =========================================================================

echo "\n--- Bloque 3: Restitución del parque ---\n";

$drained = TestDataCleaner::restorePark($pdo);
$assert(
    '3.1 La restitución drena el subárbol completo de las tres familias',
    ($drained['locations'] ?? 0) === 2
        && ($drained['machines'] ?? 0) === 2
        && ($drained['users'] ?? 0) === 1
        && ($drained['incidents'] ?? 0) === 2,
    'drenado: ' . json_encode($drained, JSON_UNESCAPED_UNICODE)
);

$assert(
    '3.2 El residuo ya no existe en la base',
    (int)$pdo->query('SELECT COUNT(*) FROM locations WHERE site_code IN (' . $pdo->quote($harnessSiteCode) . ', ' . $pdo->quote($deletedSiteCode) . ')')->fetchColumn() === 0
        && (int)$pdo->query('SELECT COUNT(*) FROM machines WHERE code IN (' . $pdo->quote($harnessMachineOnOwnSite) . ', ' . $pdo->quote($harnessMachineOnSeedSite) . ')')->fetchColumn() === 0
        && (int)$pdo->query('SELECT COUNT(*) FROM users WHERE email = ' . $pdo->quote($harnessTechnicianEmail))->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'TST-GUARD-000%'")->fetchColumn() === 0
);

$after = TestDataCleaner::countParkResidue($pdo);
$assert(
    '3.3 El detector vuelve a cero (detector y restitución son el mismo conjunto)',
    $after['total'] === 0 && array_sum($after['by_table']) === 0,
    'contado después: ' . json_encode($after, JSON_UNESCAPED_UNICODE)
);

$canonicalSitesAfter = $column(
    "SELECT site_code FROM locations WHERE site_code LIKE 'SEDE-BCN-%' AND deleted_at IS NULL ORDER BY site_code"
);
$assert(
    '3.4 Las diez sedes semilla siguen intactas y en el mismo orden',
    $canonicalSitesAfter === $catalog['locations']['site_code'],
    'catálogo: ' . json_encode($catalog['locations']['site_code']) . ' / después: ' . json_encode($canonicalSitesAfter)
);

$machinesAfter = $column('SELECT code FROM machines ORDER BY code');
$usersAfter = $column('SELECT email FROM users WHERE deleted_at IS NULL ORDER BY email');
$assert(
    '3.5 Las trece máquinas y las seis cuentas del catálogo siguen intactas',
    $asSet($machinesAfter) === $asSet($catalog['machines']['code'])
        && $asSet($usersAfter) === $asSet($catalog['users']['email']),
    'máquinas: ' . json_encode($machinesAfter) . ' / cuentas: ' . json_encode($usersAfter)
);

$seededState = (int)$pdo->query(
    "SELECT COUNT(*) FROM locations WHERE site_code LIKE 'SEDE-BCN-%' AND is_active = TRUE AND deleted_at IS NULL"
)->fetchColumn();
$assert(
    '3.6 La restitución no desactiva ni marca ninguna sede semilla',
    $seededState === 10,
    "sedes semilla activas y sin baja lógica: {$seededState} de 10"
);

$assert(
    '3.7 Sin residuo la restitución no ejecuta una sola sentencia',
    TestDataCleaner::restorePark($pdo) === [],
    'una lista vacía de identidades fuera de catálogo debe detener la operación antes del primer DELETE'
);

$assert(
    '3.8 La guarda no deja residuo propio',
    TestDataCleaner::countParkResidue($pdo)['total'] === 0,
    'la propia suite de guarda dejó maestras fuera del catálogo'
);

// =========================================================================
// BLOQUE 4: El catálogo se restituye, no se drena
// =========================================================================

echo "\n--- Bloque 4: El catálogo se restituye antes de drenar ---\n";

// El caso que el criterio ensanchado hace posible por primera vez: una sede del arnés que
// sostiene una máquina **del** catálogo, porque la suite que la reubicó murió antes de
// devolverla. El drenaje la alcanzaría como hija de esa sede y destruiría parque de negocio
// en silencio. La restitución devuelve la máquina a la sede que declara el catálogo —el
// sembrador no lo hace: su upsert no reescribe `location_id`—, de modo que el parque se sana
// y el residuo se drena en la misma operación.
$machineSite = static function (string $code) use ($pdo): string {
    return (string)$pdo->query(
        'SELECT l.site_code FROM machines m JOIN locations l ON l.id = m.location_id WHERE m.code = '
        . $pdo->quote($code)
    )->fetchColumn();
};

$assert(
    '4.1 Con el parque en su sitio, la restitución del catálogo no escribe nada',
    TestDataCleaner::restituteCatalogMachines($pdo) === [],
    'no había ninguna máquina del catálogo desviada de su sede'
);

$rehomedSiteCode = 'SEDE-TEMP-GUARD-REHOMED';
$insertSite->execute([
    ':code' => $rehomedSiteCode,
    ':key' => $dialect->identityKey($rehomedSiteCode),
    ':name' => 'Sede del arnés con parque de negocio encima',
    ':active' => 'TRUE',
    ':deleted' => null,
]);
$pdo->prepare(
    'UPDATE machines SET location_id = (SELECT id FROM locations WHERE site_code = :site) WHERE code = :code'
)->execute([':site' => $rehomedSiteCode, ':code' => $seedMachineCode]);

$rehomed = TestDataCleaner::restituteCatalogMachines($pdo);
$assert(
    '4.2 La restitución del catálogo declara la máquina devuelta y su sede',
    $rehomed === [$seedMachineCode => $seedSiteCode] && $machineSite($seedMachineCode) === $seedSiteCode,
    'devuelto: ' . json_encode($rehomed, JSON_UNESCAPED_UNICODE) . ' · sede actual: ' . $machineSite($seedMachineCode)
);

$rehomedDrained = TestDataCleaner::restorePark($pdo);
$assert(
    '4.3 La restitución drena la sede del arnés sin arrastrar la máquina del catálogo',
    ($rehomedDrained['locations'] ?? 0) === 1
        && ($rehomedDrained['machines'] ?? 0) === 0
        && (int)$pdo->query('SELECT COUNT(*) FROM locations WHERE site_code = ' . $pdo->quote($rehomedSiteCode))->fetchColumn() === 0,
    'drenado: ' . json_encode($rehomedDrained, JSON_UNESCAPED_UNICODE)
);

$afterRehome = TestDataCleaner::countParkResidue($pdo);
$assert(
    '4.4 El detector vuelve a cero y la máquina semilla conserva su sede sembrada',
    $afterRehome['total'] === 0
        && $machineSite($seedMachineCode) === $seedSiteCode
        && $asSet($column('SELECT code FROM machines')) === $asSet($catalog['machines']['code']),
    'sede actual: ' . $machineSite($seedMachineCode) . ' · residuo: ' . json_encode($afterRehome, JSON_UNESCAPED_UNICODE)
);

// =========================================================================
// BLOQUE 5: El estado de las cuentas del catálogo también se restituye
// =========================================================================

echo "\n--- Bloque 5: Restitución del estado de las cuentas ---\n";

// Una cuenta del catálogo cerrada por intentos fallidos no es residuo —no sobra ninguna fila—
// pero sí parque degradado: la suite que sólo inicia sesión con ella falla con
// `ACCOUNT_LOCKED` y el defecto parece suyo. Pasó en vivo: `UserLoginLockoutTest` bloquea
// `jordi.ruta@…` y muere antes de reiniciarla, y la corrida siguiente lo pagó.
$lockedAccount = (string)$catalog['users']['email'][1];

$assert(
    '5.1 Con el parque en su sitio, la restitución de las cuentas no escribe nada',
    TestDataCleaner::restituteCatalogUsers($pdo) === [],
    'ninguna cuenta del catálogo tenía el freno de intentos movido'
);

$pdo->prepare(
    "UPDATE users SET login_attempts = 9, login_locked_until = NOW() + INTERVAL '15 minutes' WHERE email = :email"
)->execute([':email' => $lockedAccount]);

$resetAccounts = TestDataCleaner::restituteCatalogUsers($pdo);
$attemptsLeft = (int)$pdo->query(
    'SELECT login_attempts FROM users WHERE email = ' . $pdo->quote($lockedAccount)
)->fetchColumn();
$assert(
    '5.2 La restitución de las cuentas declara la cuenta reiniciada y la deja utilizable',
    $resetAccounts === [$lockedAccount] && $attemptsLeft === 0,
    'reiniciadas: ' . json_encode($resetAccounts, JSON_UNESCAPED_UNICODE) . ' · intentos restantes: ' . $attemptsLeft
);

$assert(
    '5.3 Las seis cuentas del catálogo siguen ahí: restituir el estado no drena filas',
    TestDataCleaner::countParkResidue($pdo)['by_table']['users'] === 0
        && count($column('SELECT email FROM users WHERE deleted_at IS NULL')) === 6,
    'cuentas: ' . json_encode($column('SELECT email FROM users WHERE deleted_at IS NULL ORDER BY email'), JSON_UNESCAPED_UNICODE)
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. RESIDUO DEL PARQUE BAJO GUARDA POR IDENTIDAD (F-4, §8.5).\n"
    : " RESULTADO: HAY FALLOS. EL ARNÉS VUELVE A DEJAR RESIDUO (F-4 REABIERTO).\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
