<?php

declare(strict_types=1);

/**
 * HarnessResidueGuardTest
 *
 * Guarda del residuo de sedes del banco de pruebas (hallazgo F-4 del módulo 11,
 * contrato de limpieza §8).
 *
 * El hallazgo: `TestDataCleaner::purge()` protege `locations` porque son
 * maestras, y ese blindaje dejaba el otro lado descubierto — las sedes que crean
 * las suites no las retiraba nadie, así que el banco acumulaba filas en cada
 * corrida (206 filas frente a las 10 semilla al abrirse el hallazgo). El defecto
 * era invisible porque ninguna suite rompía: sólo el parque se degradaba.
 *
 * Lo que esta suite impide es que el limpiador vuelva a degradarse en un helper
 * que "parece" funcionar. No se limita a declarar la invariante: le planta
 * residuo, comprueba que el detector lo ve, que el borrado ingenuo del caso real
 * **fracasa** con el 1451 de las claves RESTRICT, y que la purga drena el
 * subárbol entero sin tocar una sola sede semilla.
 *
 * Dogma Vanilla: cero dependencias externas, PHP 8.2+ puro.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - HarnessResidueGuardTest (cierre de F-4)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Restitución del parque ante cualquier salida (contrato de limpieza §8.3): esta
// suite planta residuo a propósito, así que se drena también tras un error fatal.
register_shutdown_function(static function () use ($pdo): void {
    TestDataCleaner::purgeTestLocations($pdo);
});

// Estado de partida normalizado: sin incidencias huérfanas y con las semillas
// puestas. NO se tocan las sedes: precisamente lo que se mide aquí es si el
// arnés dejó residuo de sedes, y `purge()` no las limpia por diseño.
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

// ─── Escenario: dos sedes del arnés, una viva (con máquina y avería colgando por
// FK RESTRICT) y otra en baja lógica. Son las dos caras del residuo real: las
// `SEDE-MAP18-*` quedaban activas y las `SEDE-TEST-*`, soft-deleted. ──────────
$activeCode  = 'SEDE-TEST-GUARD-A';
$deletedCode = 'SEDE-MAP-GUARD-B';

$canonicalCodes = $pdo->query(
    "SELECT `site_code` FROM `locations` WHERE `site_code` LIKE 'SEDE-BCN-%' AND `deleted_at` IS NULL ORDER BY `site_code`"
)->fetchAll(PDO::FETCH_COLUMN);

// =========================================================================
// BLOQUE 1: El detector ve el residuo (la invariante y su mordida)
// =========================================================================
echo "--- Bloque 1: El detector de residuo ---\n";

$start = TestDataCleaner::countTestLocations($pdo);
$assert(
    '1.1 La corrida anterior no dejó ninguna sede del banco de pruebas',
    $start['total'] === 0,
    "residuo de partida: {$start['total']} sede(s) " . json_encode($start['by_prefix'], JSON_UNESCAPED_UNICODE)
        . ' — alguna suite no restituyó su estado (contrato §8.3)'
);

$assert(
    '1.2 El criterio de pertenencia es el prefijo reservado declarado',
    TestDataCleaner::testLocationPrefixes() === ['SEDE-TEST-%', 'SEDE-MAP%'],
    'prefijos declarados: ' . json_encode(TestDataCleaner::testLocationPrefixes(), JSON_UNESCAPED_UNICODE)
);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM `locations` WHERE `site_code` LIKE 'SEDE-BCN-%' AND (`site_code` LIKE :t OR `site_code` LIKE :m)");
$stmt->execute([':t' => 'SEDE-TEST-%', ':m' => 'SEDE-MAP%']);
$assert(
    '1.3 Ningún prefijo reservado alcanza la familia semilla SEDE-BCN-*',
    (int)$stmt->fetchColumn() === 0,
    'un prefijo reservado solapado arrastraría sedes de negocio en la purga'
);

$insertLocation = $pdo->prepare(
    "INSERT INTO `locations` (`site_code`, `name`, `address`, `latitude`, `longitude`, `contact_name`, `contact_phone`, `is_active`, `deleted_at`)
     VALUES (:code, :name, 'Carrer de la Guarda 1, Barcelona', 41.39, 2.19, 'Contacto Guarda', '600000123', :active, :deleted)"
);
$insertLocation->execute([
    ':code' => $activeCode,
    ':name' => 'Sede de guarda viva',
    ':active' => 1,
    ':deleted' => null,
]);
$insertLocation->execute([
    ':code' => $deletedCode,
    ':name' => 'Sede de guarda en baja lógica',
    ':active' => 0,
    ':deleted' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
]);
$activeLocationId = (int)$pdo->query("SELECT `id` FROM `locations` WHERE `site_code` = " . $pdo->quote($activeCode))->fetchColumn();

$pdo->prepare(
    "INSERT INTO `machines` (`location_id`, `code`, `model`, `machine_type`, `floor_wing`, `notes`, `is_active`, `sanitary_status`, `next_sanitary_inspection_due`)
     VALUES (:location_id, 'GUARD-A1', 'Modelo Guarda', 'SNACKS', 'Planta 1', NULL, 1, 'CONFORME', DATE_ADD(CURDATE(), INTERVAL 30 DAY))"
)->execute([':location_id' => $activeLocationId]);
$activeMachineId = (int)$pdo->query("SELECT `id` FROM `machines` WHERE `code` = 'GUARD-A1'")->fetchColumn();

$pdo->prepare(
    "INSERT INTO `incidents` (`ticket_code`, `machine_id`, `location_id`, `category`, `description`, `urgency`)
     VALUES ('TST-GUARD-0001', :machine_id, :location_id, 'PRODUCT_JAM', 'Avería plantada por la guarda de residuo.', 'HIGH')"
)->execute([':machine_id' => $activeMachineId, ':location_id' => $activeLocationId]);

$planted = TestDataCleaner::countTestLocations($pdo);
$assert(
    '1.4 El detector cuenta el residuo plantado, vivo y en baja lógica',
    $planted['total'] === $start['total'] + 2
        && ($planted['by_prefix']['SEDE-TEST-%'] ?? 0) === ($start['by_prefix']['SEDE-TEST-%'] ?? 0) + 1
        && ($planted['by_prefix']['SEDE-MAP%'] ?? 0) === ($start['by_prefix']['SEDE-MAP%'] ?? 0) + 1,
    'contado: ' . json_encode($planted, JSON_UNESCAPED_UNICODE)
);

// =========================================================================
// BLOQUE 2: El borrado ingenuo fracasa (por qué hace falta el subárbol)
// =========================================================================
echo "\n--- Bloque 2: La mordida del subárbol RESTRICT ---\n";

$naiveDelete = function (PDO $pdo, string $sql, array $params) : array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return ['failed' => false, 'message' => ''];
    } catch (PDOException $e) {
        return ['failed' => true, 'message' => $e->getMessage()];
    }
};

// El rechazo del borrado ingenuo se reconoce en la forma de cada motor: en MariaDB
// muerde primero la FK RESTRICT (`1451`), en PostgreSQL el disparador `Art. III.1`
// (`45000`) —ambas formas se certifican, ninguna se sustituye por la otra—.
$refusedNaiveDelete = static function (array $outcome): bool {
    if (($outcome['failed'] ?? false) !== true) {
        return false;
    }
    $message = (string)($outcome['message'] ?? '');

    return str_contains($message, '1451')
        || str_contains($message, 'foreign key constraint fails')
        || str_contains($message, '45000')
        || str_contains($message, 'ART_III_1_VIOLATION');
};

$naiveLocation = $naiveDelete($pdo, 'DELETE FROM `locations` WHERE `site_code` = :code', [':code' => $activeCode]);
$assert(
    '2.1 El DELETE ingenuo de la sede fracasa con el 1451 de las FK RESTRICT',
    $refusedNaiveDelete($naiveLocation),
    'la sentencia debía fracasar y no lo hizo: sin subárbol no hay limpieza real'
);

$naiveMachine = $naiveDelete($pdo, 'DELETE FROM `machines` WHERE `code` = :code', [':code' => 'GUARD-A1']);
$assert(
    '2.2 El DELETE ingenuo de la máquina fracasa por la avería colgada',
    $refusedNaiveDelete($naiveMachine),
    'mensaje: ' . $naiveMachine['message']
);

$assert(
    '2.3 Tras los dos intentos fallidos el expediente sigue completo',
    (int)$pdo->query("SELECT COUNT(*) FROM `locations` WHERE `site_code` = " . $pdo->quote($activeCode))->fetchColumn() === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM `machines` WHERE `code` = 'GUARD-A1'")->fetchColumn() === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM `incidents` WHERE `ticket_code` = 'TST-GUARD-0001'")->fetchColumn() === 1
);

// =========================================================================
// BLOQUE 3: La purga drena el subárbol y respeta las semillas
// =========================================================================
echo "\n--- Bloque 3: Restitución del parque ---\n";

$drained = TestDataCleaner::purgeTestLocations($pdo);
$assert(
    '3.1 La purga drena la sede viva, su máquina y su avería',
    ($drained['locations'] ?? 0) === 2
        && ($drained['machines'] ?? 0) === 1
        && ($drained['incidents'] ?? 0) === 1,
    'drenado: ' . json_encode($drained, JSON_UNESCAPED_UNICODE)
);

$assert(
    '3.2 El subárbol ya no existe en la base',
    (int)$pdo->query("SELECT COUNT(*) FROM `locations` WHERE `site_code` IN (" . $pdo->quote($activeCode) . ', ' . $pdo->quote($deletedCode) . ')')->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM `machines` WHERE `code` = 'GUARD-A1'")->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM `incidents` WHERE `ticket_code` = 'TST-GUARD-0001'")->fetchColumn() === 0
);

$after = TestDataCleaner::countTestLocations($pdo);
$assert(
    '3.3 El detector vuelve al estado de partida (detector y purga son el mismo conjunto)',
    $after['total'] === $start['total'],
    'contado después: ' . json_encode($after, JSON_UNESCAPED_UNICODE)
);

$canonicalAfter = $pdo->query(
    "SELECT `site_code` FROM `locations` WHERE `site_code` LIKE 'SEDE-BCN-%' AND `deleted_at` IS NULL ORDER BY `site_code`"
)->fetchAll(PDO::FETCH_COLUMN);
$assert(
    '3.4 Las diez sedes semilla siguen intactas y en el mismo orden',
    $canonicalAfter === $canonicalCodes && count($canonicalCodes) === 10,
    'antes: ' . json_encode($canonicalCodes) . ' / después: ' . json_encode($canonicalAfter)
);

$seededState = (int)$pdo->query(
    "SELECT COUNT(*) FROM `locations` WHERE `site_code` LIKE 'SEDE-BCN-%' AND `is_active` = 1 AND `deleted_at` IS NULL"
)->fetchColumn();
$assert(
    '3.5 La purga no desactiva ni marca ninguna sede semilla',
    $seededState === 10,
    "sedes semilla activas y sin baja lógica: {$seededState} de 10"
);

$assert(
    '3.6 Sin sedes de prueba la purga no ejecuta una sola sentencia',
    TestDataCleaner::purgeTestLocations($pdo) === [],
    'una lista vacía de sedes debe detener la operación antes del primer DELETE'
);

$assert(
    '3.7 La guarda no deja residuo propio',
    TestDataCleaner::countTestLocations($pdo)['total'] === $start['total'],
    'la propia suite de guarda dejó sedes del banco de pruebas'
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. RESIDUO DE SEDES DEL ARNÉS BAJO GUARDA (F-4).\n"
    : " RESULTADO: HAY FALLOS. EL ARNÉS VUELVE A DEJAR RESIDUO (F-4 REABIERTO).\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
