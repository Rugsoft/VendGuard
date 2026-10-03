<?php

declare(strict_types=1);

/**
 * VendGuard - Contrato de limpieza de datos de prueba (TestDataCleaner)
 *
 * Suite de regresión que protege la invariante que caused que una única fila de
 * reintegro huérfana revantara 22 suites de integración a la vez.
 *
 * La aserción clave es la sección 3: comprueba que el orden ingenuo
 * (`DELETE FROM incidents` antes que sus hijas) FALLA de verdad con error 1451.
 * Sin esa comprobación, un helper con el orden invertido pasaría los tests en una
 * base limpia y sólo explotaría en producción.
 *
 * Escenarios cubiertos:
 *   1. Las cuatro hijas con ON DELETE RESTRICT admiten filas y son drenadas.
 *   2. El orden derivado del grafo coloca siempre a las hijas antes que al padre.
 *   3. El orden inverso falla (el test muerde si alguien invierte el helper).
 *   4. El limpiador respeta las tablas maestras y las declaradas por el contrato.
 *   5. `purgeIncident()` drena un incidente concreto sin tocar los demás.
 *
 * Hecho cuando: el limpiador drena las 4 hijas RESTRICT, el orden es correcto y
 * el orden ingenuo está demostrado como roto.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;

$pdo = ConnectionFactory::getConnection();
$failures = 0;
$assertions = 0;

$assert = static function (string $label, bool $condition, string $detail = '') use (&$failures, &$assertions): void {
    $assertions++;
    echo $condition ? "  [PASS] {$label}\n" : "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    if (!$condition) {
        $failures++;
    }
};

/** Tablas hijas de `incidents` cuya FK es ON DELETE RESTRICT. */
$restrictChildren = ['incident_replaced_parts', 'refund_requests', 'spare_part_requests', 'unclaimed_cash_findings'];

$countRows = static fn (string $table): int => (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();

echo "======================================================================\n";
echo " VendGuard: Test de Integración - TestDataCleanerIsolationTest\n";
echo "======================================================================\n\n";

// Estado de partida garantizado. Esta suite NO puede depender de lo que dejó la
// suite que se ejecutó antes: un `purge()` previo deja `incidents` vacía, y una
// suite que asume datos sembrados es exactamente la fragilidad que este arreglo
// viene a eliminar.
echo "--- 0. Estado de partida garantizado ---\n";
(new SeedRunner($pdo))->seedAll();
require_once __DIR__ . '/../../database/DemoMetricsSeeder.php';
(new \VendGuard\Database\DemoMetricsSeeder($pdo))->seed();

$seededIncidents = $countRows('incidents');
$seededMachines = $countRows('machines');
$assert('0.1 Hay al menos una incidencia sembrada para colgar los huérfanos', $seededIncidents > 0, 'incidencias: ' . $seededIncidents);
$assert('0.2 Hay máquinas y sedes sembradas', $seededMachines > 0 && $countRows('locations') > 0);

echo "\n--- 1. Escenario: las 4 hijas con ON DELETE RESTRICT admiten filas ---\n";

$pdo->beginTransaction();
try {
    $incidentId = (int)$pdo->query('SELECT `id` FROM `incidents` ORDER BY `id` LIMIT 1')->fetchColumn();
    $machineId = (int)$pdo->query('SELECT `id` FROM `machines` ORDER BY `id` LIMIT 1')->fetchColumn();
    $locationId = (int)$pdo->query('SELECT `id` FROM `locations` ORDER BY `id` LIMIT 1')->fetchColumn();
    $technicianId = (int)$pdo->query("SELECT `id` FROM `users` WHERE `role` = 'TECHNICIAN' ORDER BY `id` LIMIT 1")->fetchColumn();
    $partId = (int)$pdo->query('SELECT `id` FROM `spare_parts` ORDER BY `id` LIMIT 1')->fetchColumn();

    $assert('1.0 Existen datos maestros sembrados para construir el escenario', $incidentId > 0 && $machineId > 0 && $locationId > 0 && $technicianId > 0 && $partId > 0);

    // Un refund pegado a un incidente vivo: exactamente el huérfano que reventaba
    // las 22 suites cuando T-REF-01 añadió `fk_refund_incident` con RESTRICT.
    $pdo->prepare(
        "INSERT INTO `refund_requests` (`incident_id`, `machine_id`, `location_id`, `claimant_name`, `claimant_contact`,
            `claimed_amount`, `compensation_method`, `pickup_pin`, `tracking_token`)
         VALUES (:iid, :mid, :lid, 'Cliente Huérfano', '600000000', 2.50, 'EN_MANO_SEDE', '1234', :token)"
    )->execute([':iid' => $incidentId, ':mid' => $machineId, ':lid' => $locationId, ':token' => bin2hex(random_bytes(32))]);

    $pdo->prepare(
        "INSERT INTO `unclaimed_cash_findings` (`incident_id`, `machine_id`, `technician_id`, `amount`)
         VALUES (:iid, :mid, :tid, 1.75)"
    )->execute([':iid' => $incidentId, ':mid' => $machineId, ':tid' => $technicianId]);

    $pdo->prepare(
        "INSERT INTO `spare_part_requests` (`incident_id`, `spare_part_id`, `quantity`, `status`, `requested_by_user_id`)
         VALUES (:iid, :pid, 1, 'PENDING', :uid)"
    )->execute([':iid' => $incidentId, ':pid' => $partId, ':uid' => $technicianId]);

    $pdo->prepare(
        "INSERT INTO `incident_replaced_parts` (`intervention_type`, `incident_id`, `machine_id`, `location_id`,
            `technician_id`, `spare_part_id`, `quantity`, `unit_cost_snapshot`, `old_part_destination`)
         VALUES ('CORRECTIVE', :iid, :mid, :lid, :tid, :pid, 1, 0.00, 'RECICLADO')"
    )->execute([':iid' => $incidentId, ':mid' => $machineId, ':lid' => $locationId, ':tid' => $technicianId, ':pid' => $partId]);

    $assert('1.1 La tabla `refund_requests` admite una fila ligada a un incidente', $countRows('refund_requests') >= 1);
    $assert('1.2 La tabla `unclaimed_cash_findings` admite una fila ligada a un incidente', $countRows('unclaimed_cash_findings') >= 1);
    $assert('1.3 La tabla `spare_part_requests` admite una fila ligada a un incidente', $countRows('spare_part_requests') >= 1);
    $assert('1.4 La tabla `incident_replaced_parts` admite una fila ligada a un incidente', $countRows('incident_replaced_parts') >= 1);

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n[ERROR FATAL preparing scenario] " . $e->getMessage() . "\n";
    $failures++;
}

echo "\n--- 2. Orden derivado del grafo: las hijas van siempre antes que el padre ---\n";

$order = TestDataCleaner::deleteOrder($pdo);
$position = [];
foreach ($order as $index => $table) {
    $position[$table] = $index;
}

$assert('2.1 El plan de borrado incluye la tabla raíz `incidents`', isset($position['incidents']));
$assert('2.2 `incidents` es la ÚLTIMA tabla del plan (nadie la borra antes de tiempo)', ($position['incidents'] ?? -1) === count($order) - 1, 'posición: ' . ($position['incidents'] ?? -1) . ' de ' . (count($order) - 1));

foreach ($restrictChildren as $child) {
    $assert(
        "2.3 `{$child}` se borra antes que `incidents` (FK RESTRICT respetada)",
        isset($position[$child], $position['incidents']) && $position[$child] < $position['incidents'],
        'posición ' . ($position[$child] ?? -1) . ' vs ' . ($position['incidents'] ?? -1)
    );
}

$assert(
    '2.4 `incident_replaced_parts` se borra antes que sus otros dos padres (`preventive_orders`, `spare_parts`)',
    isset($position['incident_replaced_parts'], $position['preventive_orders'], $position['spare_parts'])
        && $position['incident_replaced_parts'] < $position['preventive_orders']
        && $position['incident_replaced_parts'] < $position['spare_parts']
);
$assert(
    '2.5 `spare_part_requests` se borra antes que `spare_parts` (FK doble: incidents + spare_parts)',
    isset($position['spare_part_requests'], $position['spare_parts']) && $position['spare_part_requests'] < $position['spare_parts']
);
$assert(
    '2.6 Las hijas CASCADE también se borran explícitamente antes que `incidents`',
    isset($position['incident_comments'], $position['incident_history'])
        && $position['incident_comments'] < $position['incidents']
        && $position['incident_history'] < $position['incidents']
);

$assert('2.7 El plan cubre las 12 tablas transitorias del contrato', count($order) === 12, 'obtenidas: ' . count($order));

echo "\n--- 3. PRUEBA DE MORDIDA: el orden ingenuo debe fallar con 1451 ---\n";

// Se reconstruye el huérfano dentro de una transacción y se intenta el borrado
// con el orden QUE USABAN las 29 suites antes de este arreglo. Si algún día
// alguien invierte el orden en TestDataCleaner, esta aserción se pone roja.
$pdo->beginTransaction();
try {
    $incidentId = (int)$pdo->query('SELECT `id` FROM `incidents` ORDER BY `id` LIMIT 1')->fetchColumn();
    $machineId = (int)$pdo->query('SELECT `id` FROM `machines` ORDER BY `id` LIMIT 1')->fetchColumn();
    $locationId = (int)$pdo->query('SELECT `id` FROM `locations` ORDER BY `id` LIMIT 1')->fetchColumn();

    $pdo->prepare(
        "INSERT INTO `refund_requests` (`incident_id`, `machine_id`, `location_id`, `claimant_name`, `claimant_contact`,
            `claimed_amount`, `compensation_method`, `pickup_pin`, `tracking_token`)
         VALUES (:iid, :mid, :lid, 'Cliente Huérfano', '600000000', 2.50, 'EN_MANO_SEDE', '1234', :token)"
    )->execute([':iid' => $incidentId, ':mid' => $machineId, ':lid' => $locationId, ':token' => bin2hex(random_bytes(32))]);

    // Orden histórico de las suites: primero `incidents`, luego sus hijas.
    $naiveFailed = false;
    $naiveMessage = '';
    try {
        $pdo->exec('DELETE FROM `incidents`');
    } catch (PDOException $e) {
        $naiveFailed = true;
        $naiveMessage = $e->getMessage();
    }

    $assert(
        '3.1 `DELETE FROM incidents` a pelo FALLA con la FK RESTRICT activa',
        $naiveFailed,
        'no falló: el huérfano no llegó a bloquear nada, la prueba no valdría'
    );
    $assert(
        '3.2 El fallo es el error 1451 de MariaDB (violación de integridad referencial)',
        str_contains($naiveMessage, '1451'),
        'mensaje: ' . $naiveMessage
    );
    $assert('3.3 El intento fallido NO ha borrado la fila huérfana', $countRows('refund_requests') >= 1);

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n[ERROR FATAL en la prueba de mordida] " . $e->getMessage() . "\n";
    $failures++;
}

echo "\n--- 4. purge() drena las hijas RESTRICT y respeta las maestras ---\n";

$machinesBefore = $countRows('machines');
$locationsBefore = $countRows('locations');
$usersBefore = $countRows('users');

// Se siembran datos maestros por si la base está vacía, para que purge() tenga
// sobre qué actuar sin depender del orden de ejecución de las suites.
(new SeedRunner($pdo))->seedAll();

$machinesSeeded = $countRows('machines');
$locationsSeeded = $countRows('locations');
$usersSeeded = $countRows('users');

// Un huérfano real, ya fuera de transacción.
$incidentId = (int)$pdo->query('SELECT `id` FROM `incidents` ORDER BY `id` LIMIT 1')->fetchColumn();
$machineId = (int)$pdo->query('SELECT `id` FROM `machines` ORDER BY `id` LIMIT 1')->fetchColumn();
$locationId = (int)$pdo->query('SELECT `id` FROM `locations` ORDER BY `id` LIMIT 1')->fetchColumn();
$technicianId = (int)$pdo->query("SELECT `id` FROM `users` WHERE `role` = 'TECHNICIAN' ORDER BY `id` LIMIT 1")->fetchColumn();
$partId = (int)$pdo->query('SELECT `id` FROM `spare_parts` ORDER BY `id` LIMIT 1')->fetchColumn();

if ($incidentId > 0 && $partId > 0 && $technicianId > 0) {
    $pdo->prepare(
        "INSERT INTO `refund_requests` (`incident_id`, `machine_id`, `location_id`, `claimant_name`, `claimant_contact`,
            `claimed_amount`, `compensation_method`, `pickup_pin`, `tracking_token`)
         VALUES (:iid, :mid, :lid, 'Cliente Huérfano', '600000000', 2.50, 'EN_MANO_SEDE', '1234', :token)"
    )->execute([':iid' => $incidentId, ':mid' => $machineId, ':lid' => $locationId, ':token' => bin2hex(random_bytes(32))]);

    $pdo->prepare(
        "INSERT INTO `unclaimed_cash_findings` (`incident_id`, `machine_id`, `technician_id`, `amount`)
         VALUES (:iid, :mid, :tid, 1.75)"
    )->execute([':iid' => $incidentId, ':mid' => $machineId, ':tid' => $technicianId]);

    $assert('4.1 Hay un huérfano de reintegro listo para el ensayo', $countRows('refund_requests') >= 1);

    // Este es el momento de verdad: el mismo borrado que antes moría con 1451.
    $purged = false;
    $purgeResult = [];
    $purgeError = '';
    try {
        $purgeResult = TestDataCleaner::purge($pdo);
        $purged = true;
    } catch (Throwable $e) {
        $purgeError = $e->getMessage();
    }

    $assert(
        '4.2 `purge()` completa el borrado SIN lanzar excepción 1451',
        $purged,
        'error: ' . $purgeError
    );

    foreach ($restrictChildren as $child) {
        $assert("4.3 `{$child}` queda a 0 filas tras `purge()`", $countRows($child) === 0, 'quedan ' . $countRows($child));
    }
    $assert('4.4 `incidents` queda a 0 filas tras `purge()`', $countRows('incidents') === 0, 'quedan ' . $countRows('incidents'));

    $assert(
        '4.5 `purge()` devuelve el detalle de filas borradas por tabla',
        is_array($purgeResult) && isset($purgeResult['incidents'], $purgeResult['refund_requests']),
        'claves: ' . implode(',', array_keys($purgeResult))
    );
    $assert('4.5b `purge()` informa de haber borrado al menos un retegro huérfano', ($purgeResult['refund_requests'] ?? 0) >= 1, 'borrados: ' . ($purgeResult['refund_requests'] ?? 0));

    $assert('4.6 `purge()` NO toca `machines`', $countRows('machines') === $machinesSeeded, 'antes: ' . $machinesSeeded . ', ahora: ' . $countRows('machines'));
    $assert('4.7 `purge()` NO toca `locations`', $countRows('locations') === $locationsSeeded, 'antes: ' . $locationsSeeded . ', ahora: ' . $countRows('locations'));
    $assert('4.8 `purge()` NO toca `users`', $countRows('users') === $usersSeeded, 'antes: ' . $usersSeeded . ', ahora: ' . $countRows('users'));

    echo "\n--- 5. purgeIncident() drena un incidente sin tocar los demás ---\n";

    $machineA = (int)$pdo->query('SELECT `id` FROM `machines` ORDER BY `id` LIMIT 1')->fetchColumn();
    $machineB = (int)$pdo->query('SELECT `id` FROM `machines` ORDER BY `id` LIMIT 1 OFFSET 1')->fetchColumn();
    $locationId = (int)$pdo->query('SELECT `id` FROM `locations` ORDER BY `id` LIMIT 1')->fetchColumn();

    $insertIncident = static function (PDO $pdo, string $code, int $machineId, int $locationId): int {
        $pdo->prepare(
            "INSERT INTO `incidents` (`ticket_code`, `machine_id`, `location_id`, `category`, `description`, `urgency`, `status`)
             VALUES (:tc, :mid, :lid, 'OTHER', 'Incidencia de prueba de aislamiento', 'LOW', 'REGISTERED')"
        )->execute([':tc' => $code, ':mid' => $machineId, ':lid' => $locationId]);
        return (int)$pdo->lastInsertId();
    };

    $incA = $insertIncident($pdo, 'INC-CLEANER-A', $machineA, $locationId);
    $incB = $insertIncident($pdo, 'INC-CLEANER-B', $machineB, $locationId);

    // Sólo el incidente A tiene retegro colgado: es el que hay que drenar.
    $pdo->prepare(
        "INSERT INTO `refund_requests` (`incident_id`, `machine_id`, `location_id`, `claimant_name`, `claimant_contact`,
            `claimed_amount`, `compensation_method`, `pickup_pin`, `tracking_token`)
         VALUES (:iid, :mid, :lid, 'Cliente A', '600000000', 2.50, 'EN_MANO_SEDE', '1234', :token)"
    )->execute([':iid' => $incA, ':mid' => $machineA, ':lid' => $locationId, ':token' => bin2hex(random_bytes(32))]);

    $assert('5.1 El escenario tiene 2 incidentes y 1 retegro colgado del primero', $countRows('incidents') === 2 && $countRows('refund_requests') === 1);

    $deleted = TestDataCleaner::purgeIncident($pdo, $incA);

    $assert('5.2 `purgeIncident()` borra el retegro del incidente A', ($deleted['refund_requests'] ?? 0) >= 1, 'borrados: ' . ($deleted['refund_requests'] ?? 0));
    $assert('5.3 `purgeIncident()` borra el incidente A', ($deleted['incidents'] ?? 0) === 1);
    $assert('5.4 El incidente B sobrevive al purgado dirigido', $countRows('incidents') === 1);
    $assert('5.5 No queda ningún retegro huérfano', $countRows('refund_requests') === 0);

    // Se deja la base como estaba: sin filas de test.
    TestDataCleaner::purge($pdo);
} else {
    $assert('4.0 Se pudo construir el escenario de huérfano', false, 'faltan datos maestros');
    $failures++;
}

echo "\n--- 6. El contrato declara las hijas conocidas ---\n";

$declared = TestDataCleaner::knownOperationalTables();
foreach ($restrictChildren as $child) {
    $assert("6.1 El contrato declara `{$child}` como tabla operacional", in_array($child, $declared, true));
}

// La autoverificación de no-fuga: la suite no debe dejar filas en las hijas.
echo "\n";
foreach ($restrictChildren as $child) {
    $assertions++;
    if ($countRows($child) === 0) {
        echo "  [PASS] 6.2 La suite no deja rastro en `{$child}`\n";
    } else {
        $failures++;
        echo "  [FAIL] 6.2 La suite dejó " . $countRows($child) . " fila(s) en `{$child}`\n";
    }
}

// Se devuelven las semillas y las métricas de demo para no fastidiar a nadie.
try {
    (new SeedRunner($pdo))->seedAll();
    require_once __DIR__ . '/../../database/DemoMetricsSeeder.php';
    (new \VendGuard\Database\DemoMetricsSeeder($pdo))->seed();
} catch (Throwable $e) {
    echo "  [WARN] Re-siembra final: " . $e->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Contrato de limpieza CUMPLIDO.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);