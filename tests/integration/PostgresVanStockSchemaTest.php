<?php

declare(strict_types=1);

/**
 * PostgresVanStockSchemaTest · T-VAN-01
 *
 * Certifica **en el motor real** el esquema del stock de repuestos en furgoneta del Módulo 18:
 * la tabla `technician_van_stocks`, sus restricciones, su blindaje constitucional y la ampliación
 * del enumerado de auditoría. No comprueba servicio ni API —eso llega con T-VAN-04, T-VAN-05 y
 * T-VAN-06— sino las garantías que la base debe imponer por sí sola, porque son las que ningún
 * camino de código puede saltarse:
 *
 *   1. **Una fila por pareja técnico/pieza (RF-01.1).** La unicidad `uq_van_stock_technician_part`
 *      rechaza con `SQLSTATE '23505'` la segunda fila de la misma pareja: el saldo es un valor
 *      declarado, no un libro de movimientos.
 *   2. **Cotas anti-errata (RF-01.2 / RD-04).** `chk_van_stock_quantity_range` acepta negativos
 *      dentro de la cota —el negativo es un descuadre declarado, no un error— y rechaza lo que
 *      queda fuera; `chk_van_stock_threshold_range` no admite umbrales negativos.
 *   3. **El descuento atómico funciona en una sola sentencia (RD-02).** El `INSERT ... ON CONFLICT
 *      ... DO UPDATE` crea la fila desde cero y resta en el mismo acto: sin lectura previa no hay
 *      carrera entre dos declaraciones simultáneas.
 *   4. **El saldo no se borra (Art. III.1).** `DELETE` lanza `SQLSTATE '45000'` con la marca
 *      constitucional; la baja es cantidad `0`. Aun con la ventana de purga del arnés abierta, las
 *      claves foráneas `RESTRICT` impiden borrar un técnico o una pieza en uso.
 *   5. **Auditoría (RF-05.1).** El enumerado `audit_entity_type` contiene `TECHNICIAN_VAN_STOCK` y
 *      `audit_log` admite escribir el evento: un valor declarado que el motor no deje insertar no
 *      serviría de nada.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible (el motor de `deploy/local/postgres/`). NO pertenece a la
 *   batería hermética (RNF-04): se declara con su marca de categoría, se ejecuta en su canal
 *   propio y su informe va aparte. Sin `PG_TEST_DSN` la suite **no se omite en silencio**: lo dice
 *   y sale con código 2.
 *
 * LIMPIEZA: el escenario vive en su propio esquema y se borra con `CASCADE` al terminar, incluso
 * si una aserción falla. No deja una sola tabla en la base de certificación.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresVanStockSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Database\PostgresMigrationRunner;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta suite: no comparte escenario con las suites de correspondencia. */
const VAN_STOCK_TEST_SCHEMA = 't_van01_stock';

/** Marca que el disparador constitucional antepone a sus rechazos (Art. III.1). */
const PURGE_VIOLATION_MARKER = 'ART_III_1_VIOLATION';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-VAN-01 · Esquema del stock de furgoneta (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures: solo los datos mínimos que la regla necesita para poder dispararse
// ─────────────────────────────────────────────────────────────────────────────

/** Alta de técnico: rol `TECHNICIAN` y clave canónica, que es lo que la unicidad filtra. */
$createTechnician = static function (PDO $pdo, string $suffix, string $name): int {
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, operator_code)
         VALUES (:name, :email, :email_key, :hash, \'TECHNICIAN\', :operator)
         RETURNING id'
    );
    $stmt->execute([
        ':name' => $name,
        ':email' => 'tecnico.stock.' . $suffix . '@vendguard.internal',
        ':email_key' => 'tecnico.stock.' . $suffix . '@vendguard.internal',
        ':hash' => password_hash('stock-' . $suffix, PASSWORD_BCRYPT),
        ':operator' => 'STOCK-' . strtoupper($suffix),
    ]);

    return (int)$stmt->fetchColumn();
};

/** Alta de repuesto del catálogo maestro. */
$createSparePart = static function (PDO $pdo, string $partCode, string $name): int {
    $stmt = $pdo->prepare(
        'INSERT INTO spare_parts (part_code, name, category, manufacturer, reference_cost, is_active)
         VALUES (:part_code, :name, \'HYDRAULIC\', \'Proveedor de Certificación\', 10.00, TRUE)
         RETURNING id'
    );
    $stmt->execute([':part_code' => $partCode, ':name' => $name]);

    return (int)$stmt->fetchColumn();
};

/** El ajuste absoluto del coordinador (contratos §6.2), tal y como lo ejecuta el repositorio. */
$upsertStock = static function (PDO $pdo, int $technicianId, int $sparePartId, int $quantity, int $threshold): void {
    $stmt = $pdo->prepare(
        'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
         VALUES (:technician_id, :spare_part_id, :quantity_available, :min_threshold)
         ON CONFLICT (technician_id, spare_part_id)
         DO UPDATE SET
             quantity_available = EXCLUDED.quantity_available,
             min_threshold      = EXCLUDED.min_threshold,
             updated_at         = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':technician_id' => $technicianId,
        ':spare_part_id' => $sparePartId,
        ':quantity_available' => $quantity,
        ':min_threshold' => $threshold,
    ]);
};

/** El descuento atómico por consumo (contratos §6.1): una sola sentencia, sin lectura previa. */
$consumeStock = static function (PDO $pdo, int $technicianId, int $sparePartId, int $quantity): void {
    $stmt = $pdo->prepare(
        'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
         VALUES (:technician_id, :spare_part_id, 0 - :quantity, 0)
         ON CONFLICT (technician_id, spare_part_id)
         DO UPDATE SET
             quantity_available = technician_van_stocks.quantity_available - :quantity,
             updated_at         = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':technician_id' => $technicianId,
        ':spare_part_id' => $sparePartId,
        ':quantity' => $quantity,
    ]);
};

$crash = null;
$residueProbe = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migración 009 en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, VAN_STOCK_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la migración 009 aplican sin errores y el esquema converge',
        in_array('009_technician_van_stocks.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf('%d sentencias · migraciones: %s', $schemaReport['statements'], implode(', ', $schemaReport['migrations']))
    );

    $columnCount = (int)$pgPdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = 'technician_van_stocks'"
    )->fetchColumn();

    $assert(
        'La tabla technician_van_stocks declara sus siete columnas',
        $columnCount === 7,
        'columnas: ' . $columnCount
    );

    $technicianOne = $createTechnician($pgPdo, 'uno', 'Técnico de Certificación Uno');
    $technicianTwo = $createTechnician($pgPdo, 'dos', 'Técnico de Certificación Dos');
    $valve = $createSparePart($pgPdo, 'CERT-VALV-01', 'Electroválvula de certificación');
    $joint = $createSparePart($pgPdo, 'CERT-JUNT-02', 'Junta de certificación');

    $assert(
        'El escenario siembra técnicos y repuestos',
        $technicianOne > 0 && $technicianTwo > 0 && $valve > 0 && $joint > 0
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Una fila por pareja técnico/pieza (RF-01.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Unicidad por pareja técnico/pieza ---\n";

    $upsertStock($pgPdo, $technicianOne, $valve, 4, 2);

    $firstRow = $pgPdo->query(
        'SELECT quantity_available, min_threshold FROM technician_van_stocks
          WHERE technician_id = ' . $technicianOne . ' AND spare_part_id = ' . $valve
    )->fetch();
    $assert(
        'El saldo declarado se guarda con su cantidad y su umbral',
        $firstRow !== false && (int)$firstRow['quantity_available'] === 4 && (int)$firstRow['min_threshold'] === 2,
        'fila: ' . json_encode($firstRow)
    );

    $duplicatePair = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $technicianOne, $valve): void {
        $stmt = $pgPdo->prepare(
            'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
             VALUES (:technician_id, :spare_part_id, 9, 0)'
        );
        $stmt->execute([':technician_id' => $technicianOne, ':spare_part_id' => $valve]);
    });
    $assert(
        'La segunda fila de la misma pareja se rechaza con 23505 por uq_van_stock_technician_part',
        $duplicatePair !== null
            && $duplicatePair['state'] === '23505'
            && str_contains($duplicatePair['message'], 'uq_van_stock_technician_part'),
        $duplicatePair === null ? 'la pareja duplicada se admitió' : 'estado=' . $duplicatePair['state']
    );

    $upsertStock($pgPdo, $technicianTwo, $valve, 1, 0);
    $assert(
        'Control positivo: la misma pieza puede estar en la furgoneta de otro técnico',
        (int)$pgPdo->query('SELECT COUNT(*) FROM technician_van_stocks WHERE spare_part_id = ' . $valve)->fetchColumn() === 2
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Cotas anti-errata y negativo admitido (RF-01.2 / RD-04)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cotas de cantidad y umbral ---\n";

    $negativeStock = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $technicianOne, $joint, $upsertStock): void {
        $upsertStock($pgPdo, $technicianOne, $joint, -1000, 0);
    });
    $assert(
        'Una cantidad por debajo de la cota se rechaza (chk_van_stock_quantity_range)',
        $negativeStock !== null
            && $negativeStock['state'] === '23514'
            && str_contains($negativeStock['message'], 'chk_van_stock_quantity_range'),
        $negativeStock === null ? 'la cantidad fuera de cota se admitió' : 'estado=' . $negativeStock['state']
    );

    $negativeThreshold = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $technicianOne, $joint, $upsertStock): void {
        $upsertStock($pgPdo, $technicianOne, $joint, 1, -1);
    });
    $assert(
        'Un umbral negativo se rechaza (chk_van_stock_threshold_range)',
        $negativeThreshold !== null
            && $negativeThreshold['state'] === '23514'
            && str_contains($negativeThreshold['message'], 'chk_van_stock_threshold_range'),
        $negativeThreshold === null ? 'el umbral negativo se admitió' : 'estado=' . $negativeThreshold['state']
    );

    $upsertStock($pgPdo, $technicianOne, $joint, -3, 0);
    $declaredMismatch = (int)$pgPdo->query(
        'SELECT quantity_available FROM technician_van_stocks
          WHERE technician_id = ' . $technicianOne . ' AND spare_part_id = ' . $joint
    )->fetchColumn();
    $assert(
        'Control positivo: un descuadre negativo declarado se guarda tal cual (RD-04)',
        $declaredMismatch === -3,
        'cantidad: ' . $declaredMismatch
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Descuento atómico en una sola sentencia (RF-02 / RD-02)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Descuento atómico sin lectura previa ---\n";

    $thirdPart = $createSparePart($pgPdo, 'CERT-MOT-03', 'Motor de certificación');
    $consumeStock($pgPdo, $technicianTwo, $thirdPart, 2);

    $createdByConsumption = (int)$pgPdo->query(
        'SELECT quantity_available FROM technician_van_stocks
          WHERE technician_id = ' . $technicianTwo . ' AND spare_part_id = ' . $thirdPart
    )->fetchColumn();
    $assert(
        'Consumir sin saldo previo crea la fila desde cero y la deja en negativo',
        $createdByConsumption === -2,
        'cantidad: ' . $createdByConsumption
    );

    $consumeStock($pgPdo, $technicianTwo, $thirdPart, 3);
    $accumulated = (int)$pgPdo->query(
        'SELECT quantity_available FROM technician_van_stocks
          WHERE technician_id = ' . $technicianTwo . ' AND spare_part_id = ' . $thirdPart
    )->fetchColumn();
    $assert(
        'Un segundo consumo acumula el descuento sobre el saldo existente',
        $accumulated === -5,
        'cantidad: ' . $accumulated
    );

    $consumeStock($pgPdo, $technicianOne, $valve, 2);
    $positiveAfterConsumption = (int)$pgPdo->query(
        'SELECT quantity_available FROM technician_van_stocks
          WHERE technician_id = ' . $technicianOne . ' AND spare_part_id = ' . $valve
    )->fetchColumn();
    $assert(
        'El consumo resta sobre un saldo positivo declarado',
        $positiveAfterConsumption === 2,
        'cantidad: ' . $positiveAfterConsumption
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. El saldo no se borra: se ajusta a cero (Art. III.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Borrado físico rechazado ---\n";

    $physicalDelete = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $technicianOne, $valve): void {
        $pgPdo->exec('DELETE FROM technician_van_stocks WHERE technician_id = ' . $technicianOne . ' AND spare_part_id = ' . $valve);
    });
    $assert(
        'El borrado físico de un saldo se rechaza con SQLSTATE 45000 (Art. III.1)',
        $physicalDelete !== null
            && $physicalDelete['state'] === '45000'
            && str_contains($physicalDelete['message'], PURGE_VIOLATION_MARKER),
        $physicalDelete === null ? 'el borrado pasó' : 'estado=' . $physicalDelete['state']
    );

    $restrictedPartDelete = TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo, $valve): ?array {
        return PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $valve): void {
            $pgPdo->exec('DELETE FROM spare_parts WHERE id = ' . $valve);
        });
    });
    $assert(
        'Aun con la ventana de purga abierta, la clave foránea RESTRICT impide borrar una pieza en uso',
        $restrictedPartDelete !== null && $restrictedPartDelete['state'] === '23503',
        $restrictedPartDelete === null
            ? 'la pieza en uso se borró pese a la clave foránea'
            : 'estado=' . $restrictedPartDelete['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Auditoría: TECHNICIAN_VAN_STOCK utilizable (RF-05.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Auditoría: TECHNICIAN_VAN_STOCK ---\n";

    // El filtro por espacio de nombres es obligatorio: el tipo base `public` y el del escenario
    // aislado comparten nombre, y sin él la lectura mezclaría las etiquetas de los dos.
    $enumLabels = $pgPdo->query(
        "SELECT e.enumlabel
           FROM pg_enum e
           JOIN pg_type t ON t.oid = e.enumtypid
           JOIN pg_namespace n ON n.oid = t.typnamespace
          WHERE t.typname = 'audit_entity_type' AND n.nspname = current_schema()
          ORDER BY e.enumsortorder"
    )->fetchAll(PDO::FETCH_COLUMN);

    $assert(
        'El enumerado audit_entity_type declara la entidad del stock de furgoneta',
        in_array('TECHNICIAN_VAN_STOCK', $enumLabels, true),
        implode(' · ', $enumLabels)
    );

    $writeAuditEvent = static function (PDO $pdo, string $entityType, int $entityId): void {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log (entity_type, entity_id, action, user_role, user_name, new_state)
             VALUES (:entity_type, :entity_id, 'VAN_STOCK_ADJUSTED', 'COORDINATOR', 'Coordinación de Certificación', '{}'::jsonb)"
        );
        $stmt->execute([':entity_type' => $entityType, ':entity_id' => $entityId]);
    };

    $writeAuditEvent($pgPdo, 'TECHNICIAN_VAN_STOCK', $technicianOne);
    $auditRows = (int)$pgPdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE entity_type = 'TECHNICIAN_VAN_STOCK'"
    )->fetchColumn();
    $assert(
        'audit_log admite escribir eventos de la entidad nueva',
        $auditRows === 1,
        'eventos escritos: ' . $auditRows
    );

    $invalidEntity = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $writeAuditEvent, $technicianOne): void {
        $writeAuditEvent($pgPdo, 'TECHNICIAN_VAN_STOCK_TYPO', $technicianOne);
    });
    $assert(
        'Control negativo: una etiqueta fuera del enumerado se rechaza (22P02)',
        $invalidEntity !== null && $invalidEntity['state'] === '22P02',
        $invalidEntity === null ? 'la etiqueta inválida se admitió' : 'estado=' . $invalidEntity['state']
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, VAN_STOCK_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    // `to_regclass` devuelve `NULL` cuando la relación no existe: es la sonda de residuo que no
    // depende del texto exacto del mensaje del motor.
    $residueProbe = $pgPdo->query("SELECT to_regclass('" . VAN_STOCK_TEST_SCHEMA . ".technician_van_stocks')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado y sus tablas ya no existen',
    $residueProbe === null,
    'la sonda de residuo encontró la tabla en ' . json_encode($residueProbe)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-VAN-01 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
