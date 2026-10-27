<?php

declare(strict_types=1);

/**
 * PostgresVanRestockSchemaTest · T-RESTOCK-02
 *
 * Certifica sobre el motor PostgreSQL el blindaje de las dos tablas del Módulo 23:
 * órdenes de carga de furgoneta y sus líneas. No certifica todavía el ciclo de
 * aplicación ni los endpoints (eso es T-RESTOCK-08 a T-RESTOCK-11): aquí se mide
 * lo que sólo la base de datos puede garantizar, incluso si un servicio futuro
 * olvidara validarlo.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Una sola orden abierta por técnico (D3, RF-01.1).** El índice único
 *      parcial `uq_van_restock_open_per_tech` rechaza una segunda orden en
 *      `PENDING_PICKING` y también una segunda en `DISPATCHED`, y la libera en
 *      cuanto la anterior alcanza un estado terminal (`CONFIRMED_BY_TECH` o
 *      `CANCELLED`). Se prueba con filas reales y se lee el nombre del índice en
 *      el mensaje del motor, no se confía en que «algo» falló.
 *
 *   2. **Cancelación justificada (Art. V.1).** `chk_van_restock_orders_cancel_consistency`
 *      exige 20 caracteres reales tras `btrim`: 19 rodeados de espacios se
 *      rechazan y 20 se aceptan. La prueba del límite importa porque el
 *      validador de dominio repetirá la regla; la base es la última puerta.
 *
 *   3. **Baja lógica coherente de líneas (QA 1, Art. III.1).** `removed_at` y
 *      `removed_by` se mueven juntos. Una línea retirada no puede quedarse sin
 *      actor, una viva no puede arrastrar sólo uno de los dos, y la reactivación
 *      devuelve ambos a `NULL` sin borrar la fila.
 *
 *   4. **Prohibición de borrado físico (Art. III.1).** `DELETE FROM` sobre
 *      cabecera y líneas se rechaza con `SQLSTATE '45000'` y la marca
 *      `ART_III_1_VIOLATION`, con filas dentro: un disparador `FOR EACH ROW` no
 *      ve una tabla vacía.
 *
 * CATEGORÍA: `new_engine`
 *   Exige el PostgreSQL de certificación. Sin `PG_TEST_DSN` no se omite en
 *   silencio: lo declara y sale con código 2. El escenario vive en su propio
 *   esquema y se borra con `CASCADE` al terminar, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.battery && php tests/integration/PostgresVanRestockSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

/** Marca de categoría: `run_all.php` la lee para no confundirla con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Marca que el disparador constitucional escribe en el mensaje de la excepción. */
const PURGE_VIOLATION_MARKER = 'ART_III_1_VIOLATION';

/** Identidad de la fixture: nombres reservados para que una limpieza fallida sea visible. */
const FIXTURE_TECH_EMAIL = 'restock.tech.m23@vendguard.test';
const FIXTURE_COORD_EMAIL = 'restock.coord.m23@vendguard.test';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;
$crash = null;
$pgPdo = null;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-RESTOCK-02 · Esquema de órdenes de carga (Módulo 23)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

try {
    PostgresFixtureBuilder::createIsolationSchema($pgPdo);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    $assert(
        'El esquema del repositorio compila y la migración 016 se aplica sobre la base aislada',
        in_array('016_van_restock_orders.sql', $schemaReport['migrations'], true),
        'migraciones: ' . implode(', ', $schemaReport['migrations'])
    );

    $tables = PostgresFixtureBuilder::tables($pgPdo);
    $assert(
        'Las tablas 32 y 33 del contrato existen en el esquema certificado',
        in_array('van_restock_orders', $tables, true) && in_array('van_restock_order_items', $tables, true),
        'tablas: ' . count($tables)
    );

    // ─────────────────────────────────────────────────────────────────────
    // Fixture mínima: dos cuentas y dos repuestos reales
    // ─────────────────────────────────────────────────────────────────────
    $insertUser = $pgPdo->prepare(
        "INSERT INTO users (name, email, email_key, password_hash, role, is_active)
         VALUES (:name, :email, :email_key, 'fixture-hash-m23', :role, TRUE)
         RETURNING *"
    );

    $insertUser->execute([
        ':name' => 'Técnico Fixture M23',
        ':email' => FIXTURE_TECH_EMAIL,
        ':email_key' => FIXTURE_TECH_EMAIL,
        ':role' => 'TECHNICIAN',
    ]);
    $technician = $insertUser->fetch();

    $insertUser->execute([
        ':name' => 'Coordinación Fixture M23',
        ':email' => FIXTURE_COORD_EMAIL,
        ':email_key' => FIXTURE_COORD_EMAIL,
        ':role' => 'COORDINATOR',
    ]);
    $coordinator = $insertUser->fetch();

    $insertPart = $pgPdo->prepare(
        "INSERT INTO spare_parts (part_code, name, category, manufacturer, is_active)
         VALUES (:part_code, :name, :category, 'Fixture M23', TRUE)
         RETURNING *"
    );
    $insertPart->execute([
        ':part_code' => 'FIX-M23-THERMAL',
        ':name' => 'Sonda Fixture M23',
        ':category' => 'THERMAL',
    ]);
    $thermalPart = $insertPart->fetch();
    $insertPart->execute([
        ':part_code' => 'FIX-M23-MECHANICAL',
        ':name' => 'Motor Fixture M23',
        ':category' => 'MECHANICAL',
    ]);
    $mechanicalPart = $insertPart->fetch();

    $technicianId = (int)$technician['id'];
    $coordinatorId = (int)$coordinator['id'];

    $createOrder = static function (PDO $pdo, int $technicianId, int $createdBy): array {
        $stmt = $pdo->prepare(
            "INSERT INTO van_restock_orders (technician_id, status, notes, created_by)
             VALUES (:technician_id, 'PENDING_PICKING', 'Fixture del esquema M23.', :created_by)
             RETURNING *"
        );
        $stmt->execute([':technician_id' => $technicianId, ':created_by' => $createdBy]);
        $created = $stmt->fetch();

        return is_array($created) ? $created : [];
    };

    $transition = static function (PDO $pdo, int $orderId, string $sql, array $params = []): void {
        $stmt = $pdo->prepare('UPDATE van_restock_orders SET ' . $sql . ' WHERE id = :order_id');
        $stmt->execute($params + [':order_id' => $orderId]);
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Una sola orden abierta por técnico (D3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Unicidad de la orden abierta por técnico (D3) ---\n";

    $orderA = $createOrder($pgPdo, $technicianId, $coordinatorId);
    $assert(
        'La primera orden nace en PENDING_PICKING',
        ($orderA['status'] ?? null) === 'PENDING_PICKING',
        'estado: ' . var_export($orderA['status'] ?? null, true)
    );

    $secondOpen = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $createOrder, $technicianId, $coordinatorId): void {
        $createOrder($pgPdo, $technicianId, $coordinatorId);
    });
    $assert(
        'Una segunda orden PENDING_PICKING del mismo técnico se rechaza con 23505 y nombra el índice parcial',
        $secondOpen !== null
            && $secondOpen['state'] === '23505'
            && str_contains($secondOpen['message'], 'uq_van_restock_open_per_tech'),
        $secondOpen === null ? 'la segunda orden se aceptó' : 'estado=' . $secondOpen['state'] . ' · ' . mb_substr($secondOpen['message'], 0, 120)
    );

    $transition(
        $pgPdo,
        (int)$orderA['id'],
        "status = 'CONFIRMED_BY_TECH', dispatched_by = :dispatched_by, dispatched_at = CURRENT_TIMESTAMP,
         confirmed_by = :confirmed_by, confirmed_at = CURRENT_TIMESTAMP",
        [':dispatched_by' => $coordinatorId, ':confirmed_by' => $technicianId]
    );
    $confirmed = $pgPdo->query('SELECT status FROM van_restock_orders WHERE id = ' . (int)$orderA['id'])->fetchColumn();
    $assert('La orden pasa a CONFIRMED_BY_TECH con sus cuatro marcas de rama', $confirmed === 'CONFIRMED_BY_TECH', 'estado: ' . var_export($confirmed, true));

    $orderB = $createOrder($pgPdo, $technicianId, $coordinatorId);
    $assert(
        'Confirmar la orden anterior libera el índice y permite una nueva orden abierta',
        ($orderB['status'] ?? null) === 'PENDING_PICKING',
        'id: ' . ($orderB['id'] ?? 'sin alta')
    );

    $transition(
        $pgPdo,
        (int)$orderB['id'],
        "status = 'DISPATCHED', dispatched_by = :dispatched_by, dispatched_at = CURRENT_TIMESTAMP",
        [':dispatched_by' => $coordinatorId]
    );
    $dispatchedState = $pgPdo->query('SELECT status FROM van_restock_orders WHERE id = ' . (int)$orderB['id'])->fetchColumn();
    $assert('La orden pasa a DISPATCHED con su actor e instante', $dispatchedState === 'DISPATCHED', 'estado: ' . var_export($dispatchedState, true));

    $thirdOpen = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $createOrder, $technicianId, $coordinatorId): void {
        $createOrder($pgPdo, $technicianId, $coordinatorId);
    });
    $assert(
        'Una orden DISPATCHED también ocupa el hueco abierto del técnico (23505)',
        $thirdOpen !== null && $thirdOpen['state'] === '23505',
        $thirdOpen === null ? 'la tercera orden se aceptó' : 'estado=' . $thirdOpen['state']
    );

    $transition(
        $pgPdo,
        (int)$orderB['id'],
        "status = 'CANCELLED', cancelled_by = :cancelled_by, cancelled_at = CURRENT_TIMESTAMP,
         cancellation_reason = :reason",
        [':cancelled_by' => $coordinatorId, ':reason' => 'Cancelación de prueba con motivo suficiente.']
    );
    $orderC = $createOrder($pgPdo, $technicianId, $coordinatorId);
    $assert(
        'Cancelar la orden anterior libera el índice y permite una nueva orden abierta',
        ($orderC['status'] ?? null) === 'PENDING_PICKING',
        'id: ' . ($orderC['id'] ?? 'sin alta')
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Cancelación justificada (Art. V.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Justificación mínima de cancelación (Art. V.1) ---\n";

    $shortReason = '   ' . str_repeat('a', 19) . '   ';
    $shortRejection = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $transition, $orderC, $coordinatorId, $shortReason): void {
        $transition(
            $pgPdo,
            (int)$orderC['id'],
            "status = 'CANCELLED', cancelled_by = :cancelled_by, cancelled_at = CURRENT_TIMESTAMP,
             cancellation_reason = :reason",
            [':cancelled_by' => $coordinatorId, ':reason' => $shortReason]
        );
    });
    $assert(
        'Cancelar con 19 caracteres reales tras btrim se rechaza con 23514 y nombra la restricción',
        $shortRejection !== null
            && $shortRejection['state'] === '23514'
            && str_contains($shortRejection['message'], 'chk_van_restock_orders_cancel_consistency'),
        $shortRejection === null ? 'la cancelación corta se aceptó' : 'estado=' . $shortRejection['state'] . ' · ' . mb_substr($shortRejection['message'], 0, 120)
    );

    $exactReason = '   ' . str_repeat('b', 20) . '   ';
    $transition(
        $pgPdo,
        (int)$orderC['id'],
        "status = 'CANCELLED', cancelled_by = :cancelled_by, cancelled_at = CURRENT_TIMESTAMP,
         cancellation_reason = :reason",
        [':cancelled_by' => $coordinatorId, ':reason' => $exactReason]
    );
    $cancelledRow = $pgPdo->query(
        'SELECT status, char_length(btrim(cancellation_reason)) AS real_length FROM van_restock_orders WHERE id = ' . (int)$orderC['id']
    )->fetch();
    $assert(
        'Cancelar con 20 caracteres reales se acepta y conserva la justificación',
        ($cancelledRow['status'] ?? null) === 'CANCELLED' && (int)($cancelledRow['real_length'] ?? 0) === 20,
        'estado=' . var_export($cancelledRow['status'] ?? null, true) . ' · longitud=' . (int)($cancelledRow['real_length'] ?? 0)
    );

    $orderD = $createOrder($pgPdo, $technicianId, $coordinatorId);

    // ─────────────────────────────────────────────────────────────────────
    // 3. Baja lógica coherente de líneas (QA 1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Baja lógica y reactivación de líneas (QA 1) ---\n";

    $insertItem = $pgPdo->prepare(
        "INSERT INTO van_restock_order_items (order_id, spare_part_id, requested_qty, reason)
         VALUES (:order_id, :spare_part_id, 2, 'MANUAL')
         RETURNING *"
    );
    $insertItem->execute([':order_id' => (int)$orderD['id'], ':spare_part_id' => (int)$thermalPart['id']]);
    $item = $insertItem->fetch();
    $assert('La línea activa de la orden se inserta con motivo MANUAL', ($item['reason'] ?? null) === 'MANUAL' && ($item['removed_at'] ?? null) === null);

    $removedAtOnly = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $item): void {
        $stmt = $pgPdo->prepare('UPDATE van_restock_order_items SET removed_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([':id' => (int)$item['id']]);
    });
    $assert(
        'Retirar una línea sin actor se rechaza con 23514 y nombra chk_van_restock_items_removed_consistency',
        $removedAtOnly !== null
            && $removedAtOnly['state'] === '23514'
            && str_contains($removedAtOnly['message'], 'chk_van_restock_items_removed_consistency'),
        $removedAtOnly === null ? 'la baja incoherente se aceptó' : 'estado=' . $removedAtOnly['state']
    );

    $pgPdo->prepare('UPDATE van_restock_order_items SET removed_at = CURRENT_TIMESTAMP, removed_by = :actor WHERE id = :id')
        ->execute([':actor' => $coordinatorId, ':id' => (int)$item['id']]);
    $removedRow = $pgPdo->query(
        'SELECT removed_at, removed_by FROM van_restock_order_items WHERE id = ' . (int)$item['id']
    )->fetch();
    $assert(
        'La baja lógica con instante y actor se acepta y conserva la fila',
        ($removedRow['removed_at'] ?? null) !== null && (int)($removedRow['removed_by'] ?? 0) === $coordinatorId
    );

    $pgPdo->prepare('UPDATE van_restock_order_items SET removed_at = NULL, removed_by = NULL WHERE id = :id')
        ->execute([':id' => (int)$item['id']]);
    $reactivated = $pgPdo->query(
        'SELECT removed_at, removed_by FROM van_restock_order_items WHERE id = ' . (int)$item['id']
    )->fetch();
    $assert(
        'Reactivar la misma pieza devuelve removed_at y removed_by a NULL sin borrar la fila',
        ($reactivated['removed_at'] ?? null) === null && ($reactivated['removed_by'] ?? null) === null
    );

    $removedByOnly = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $item, $coordinatorId): void {
        $stmt = $pgPdo->prepare('UPDATE van_restock_order_items SET removed_by = :actor WHERE id = :id');
        $stmt->execute([':actor' => $coordinatorId, ':id' => (int)$item['id']]);
    });
    $assert(
        'Una línea viva no puede arrastrar sólo removed_by (23514)',
        $removedByOnly !== null && $removedByOnly['state'] === '23514',
        $removedByOnly === null ? 'la baja incoherente se aceptó' : 'estado=' . $removedByOnly['state']
    );

    $pgPdo->prepare(
        "INSERT INTO van_restock_order_items (order_id, spare_part_id) VALUES (:order_id, :spare_part_id)"
    )->execute([':order_id' => (int)$orderD['id'], ':spare_part_id' => (int)$mechanicalPart['id']]);

    $duplicateLine = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $orderD, $mechanicalPart): void {
        $stmt = $pgPdo->prepare(
            "INSERT INTO van_restock_order_items (order_id, spare_part_id) VALUES (:order_id, :spare_part_id)"
        );
        $stmt->execute([':order_id' => (int)$orderD['id'], ':spare_part_id' => (int)$mechanicalPart['id']]);
    });
    $assert(
        'La misma pieza no se puede repetir en la misma orden (23505)',
        $duplicateLine !== null && $duplicateLine['state'] === '23505',
        $duplicateLine === null ? 'la línea duplicada se aceptó' : 'estado=' . $duplicateLine['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Borrado físico prohibido (Art. III.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Prohibición de borrado físico (Art. III.1) ---\n";

    $itemDelete = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $orderD): void {
        $stmt = $pgPdo->prepare('DELETE FROM van_restock_order_items WHERE order_id = :order_id');
        $stmt->execute([':order_id' => (int)$orderD['id']]);
    });
    $assert(
        'DELETE FROM van_restock_order_items se rechaza con 45000 y la marca constitucional',
        $itemDelete !== null
            && $itemDelete['state'] === '45000'
            && str_contains($itemDelete['message'], PURGE_VIOLATION_MARKER),
        $itemDelete === null ? 'el borrado pasó' : 'estado=' . $itemDelete['state']
    );

    $orderDelete = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $orderD): void {
        $stmt = $pgPdo->prepare('DELETE FROM van_restock_orders WHERE id = :id');
        $stmt->execute([':id' => (int)$orderD['id']]);
    });
    $assert(
        'DELETE FROM van_restock_orders se rechaza con 45000 y la marca constitucional',
        $orderDelete !== null
            && $orderDelete['state'] === '45000'
            && str_contains($orderDelete['message'], PURGE_VIOLATION_MARKER),
        $orderDelete === null ? 'el borrado pasó' : 'estado=' . $orderDelete['state']
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }
}

if ($crash !== null) {
    echo "\n[ERROR FATAL] " . $crash->getMessage() . "\n";
    $failures++;
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0 && $crash === null) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-RESTOCK-02 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIÓN(ES).\n";
echo "======================================================================\n";
exit(1);
