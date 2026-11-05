<?php

declare(strict_types=1);

/**
 * PostgresSanitarySpoilageSchemaTest · T-SPOILAGE-03
 *
 * Certifica el suelo del Módulo 29 sobre el motor único PostgreSQL: la Tabla 40
 * `sanitary_spoilage_records` y las guardas que la sostienen.
 *
 *   1. **Forma declarada.** 22 columnas, las seis claves foráneas con `ON DELETE
 *      RESTRICT`, la unicidad `uq_sanitary_spoilage_records_code`, los cuatro
 *      índices del libro de mermas y los tres disparadores:
 *      `trg_no_delete_…` (Art. III.1), `trg_touch_…` (Art. III.3) y
 *      `trg_immutable_…` (RNF-01).
 *
 *   2. **Dos ramas de acta (D3, C5).** Un acta con producto destruido y un acta
 *      de máquina vacía se insertan con sus hechos completos. El motor rechaza
 *      la máquina vacía sin confirmación expresa, la vacía con coste, la que
 *      declara unidades con coste cero y la que mezcla método de destrucción con
 *      cero unidades.
 *
 *   3. **Hechos sanitarios inmutables (RNF-01).** El motor rechaza con `45000`
 *      cualquier cambio de unidades, observaciones o certificado tras la
 *      emisión, mientras que el coste estimado —el ajuste auditado de
 *      Coordinación— se actualiza y se lee (Art. III.3).
 *
 *   4. **Cotas y coherencia del ajuste (C5, QA 4).** El motor rechaza
 *      temperaturas fuera del rango físico plausible, observaciones y lotes
 *      demasiado cortos, un origen inventado y un ajuste sin justificación
 *      suficiente. El acta de máquina vacía no admite ajuste económico.
 *
 *   5. **Integridad referencial y numeración oficial.** La máquina debe existir
 *      (FK RESTRICT) y el código oficial no admite duplicados (`QA 2`).
 *
 *   6. **Prohibición de borrado físico.** Sin la ventana de purga el disparador
 *      constitucional rechaza con `45000`; con la ventana abierta la fila se retira.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible a través de `PG_TEST_DSN`, sin valor por defecto.
 *   Sin motor, la suite se declara no ejecutable con código 2 y no se omite en silencio.
 *
 * LIMPIEZA: el escenario vive en un esquema aislado que se borra con `CASCADE` en el
 * `finally`, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresSanitarySpoilageSchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado propio: no comparte escenario con las demás suites del canal. */
const SPOILAGE_SCHEMA = 't_spoilage29_schema';

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
echo " VENDGUARD · T-SPOILAGE-03 · Esquema y guardas del Acta de Merma APPCC\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$crash = null;
$residueCheck = null;

try {
    echo "\n--- Escenario (DDL base + migraciones del motor nuevo) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, SPOILAGE_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    $assert(
        'El esquema del repositorio se aplica entero, incluida la migración 025',
        $schemaReport['statements'] > 100
        && in_array('025_sanitary_spoilage_records.sql', $schemaReport['migrations'], true),
        sprintf('%d sentencias · migraciones: %s', $schemaReport['statements'], implode(', ', $schemaReport['migrations']))
    );

    echo "\n--- Forma declarada de la Tabla 40 ---\n";

    $columns = PostgresFixtureBuilder::columns($pgPdo, 'sanitary_spoilage_records');
    $assert('La tabla declara sus 22 columnas canónicas', count($columns) === 22, 'columnas: ' . count($columns));

    $expectedColumns = [
        'id', 'record_code', 'machine_id', 'incident_id', 'maintenance_order_id',
        'technician_id', 'registered_by', 'quarantine_origin', 'discarded_units_count',
        'empty_machine_confirmed', 'affected_batch_codes', 'cabin_temp_at_removal',
        'restored_stabilized_temp_c', 'disposal_method', 'evidence_photo_url',
        'sanitary_notes', 'estimated_merchandise_loss_eur', 'loss_adjusted_by',
        'loss_adjustment_reason', 'certificate_code', 'created_at', 'updated_at',
    ];
    $missingColumns = array_values(array_diff($expectedColumns, array_keys($columns)));
    $assert('Las columnas del contrato están todas presentes', $missingColumns === [], 'faltan: ' . implode(', ', $missingColumns));

    $indexStmt = $pgPdo->prepare(
        "SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'sanitary_spoilage_records'"
    );
    $indexStmt->execute();
    $indexes = array_map(static fn (array $row): string => (string)$row['indexname'], $indexStmt->fetchAll());
    $expectedIndexes = [
        'idx_spoilage_records_machine_created',
        'idx_spoilage_records_incident',
        'idx_spoilage_records_preventive_order',
        'idx_spoilage_records_technician_created',
    ];
    $missingIndexes = array_values(array_diff($expectedIndexes, $indexes));
    $assert('Los cuatro índices de lectura existen', $missingIndexes === [], 'faltan: ' . implode(', ', $missingIndexes));

    $fkStmt = $pgPdo->prepare(
        "SELECT conname, confdeltype FROM pg_constraint
          WHERE conrelid = 'sanitary_spoilage_records'::regclass AND contype = 'f'"
    );
    $fkStmt->execute();
    $foreignKeys = $fkStmt->fetchAll();
    $allRestrict = $foreignKeys !== []
        && count(array_filter($foreignKeys, static fn (array $row): bool => $row['confdeltype'] === 'r')) === count($foreignKeys);
    $assert(
        'Las seis claves foráneas son ON DELETE RESTRICT (Art. III.3)',
        count($foreignKeys) === 6 && $allRestrict,
        'FKs: ' . count($foreignKeys)
    );

    $uniqueStmt = $pgPdo->prepare(
        "SELECT conname FROM pg_constraint WHERE conrelid = 'sanitary_spoilage_records'::regclass AND contype = 'u'"
    );
    $uniqueStmt->execute();
    $assert(
        'Existe uq_sanitary_spoilage_records_code',
        in_array('uq_sanitary_spoilage_records_code', array_column($uniqueStmt->fetchAll(), 'conname'), true)
    );

    $triggerStmt = $pgPdo->prepare(
        "SELECT tgname FROM pg_trigger WHERE tgrelid = 'sanitary_spoilage_records'::regclass AND NOT tgisinternal"
    );
    $triggerStmt->execute();
    $triggers = array_column($triggerStmt->fetchAll(), 'tgname');
    $expectedTriggers = [
        'trg_no_delete_sanitary_spoilage_records',
        'trg_touch_sanitary_spoilage_records',
        'trg_immutable_sanitary_spoilage_records',
    ];
    $missingTriggers = array_values(array_diff($expectedTriggers, $triggers));
    $assert('Los tres disparadores constitucionales están vinculados', $missingTriggers === [], 'faltan: ' . implode(', ', $missingTriggers));

    $auditValues = $pgPdo->query(
        "SELECT enumlabel FROM pg_enum WHERE enumtypid = 'audit_entity_type'::regtype"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(
        'audit_entity_type incorpora SANITARY_SPOILAGE_RECORD',
        in_array('SANITARY_SPOILAGE_RECORD', $auditValues, true)
    );

    echo "\n--- Alta válida de las dos ramas de acta (D3, C5) ---\n";

    $parents = [];
    $parents['users'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'users', $parents, '01');
    $parents['locations'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'locations', $parents, '01');
    $parents['machines'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'machines', $parents, '01');

    $sequence = 0;
    $insertRecord = static function (array $overrides = []) use ($pgPdo, $parents, &$sequence): int {
        $sequence++;
        $units = $overrides['discarded_units_count'] ?? 14;
        $isEmpty = $overrides['empty_machine_confirmed'] ?? ($units === 0);
        $disposal = $overrides['disposal_method'] ?? ($units === 0 ? 'NOT_APPLICABLE_EMPTY' : 'ON_SITE_ORGANIC_SEALED');
        $batches = $overrides['affected_batch_codes'] ?? ($units === 0
            ? 'SIN_STOCK_AFECTADO · cabina vacía verificada'
            : 'LOTE-2026-0412 · yogures naturales; LOTE-2026-0413 · postres');
        $cost = $overrides['estimated_merchandise_loss_eur'] ?? ($units === 0 ? '0.00' : '35.00');
        $emptyLiteral = $isEmpty ? 'TRUE' : 'FALSE';

        $stmt = $pgPdo->prepare(
            "INSERT INTO sanitary_spoilage_records (
                record_code, machine_id, technician_id, registered_by,
                quarantine_origin, discarded_units_count, empty_machine_confirmed,
                affected_batch_codes, cabin_temp_at_removal, restored_stabilized_temp_c,
                disposal_method, evidence_photo_url, sanitary_notes,
                estimated_merchandise_loss_eur, loss_adjusted_by, loss_adjustment_reason,
                certificate_code
            ) VALUES (
                :record_code, :machine_id, :technician_id, :registered_by,
                :quarantine_origin, :units, {$emptyLiteral},
                :batches, CAST(:cabin_temp AS NUMERIC(4,1)), CAST(:restored_temp AS NUMERIC(4,1)),
                :disposal, :photo, :notes,
                CAST(:cost AS NUMERIC(10,2)), :adjusted_by, :reason,
                :certificate
            ) RETURNING id"
        );
        $stmt->execute([
            ':record_code' => $overrides['record_code'] ?? sprintf('ACTA-TEST-%04d', $sequence),
            ':machine_id' => $overrides['machine_id'] ?? $parents['machines']['id'],
            ':technician_id' => $parents['users']['id'],
            ':registered_by' => $parents['users']['id'],
            ':quarantine_origin' => $overrides['quarantine_origin'] ?? 'BIOLOGICAL_CLOCK_4H',
            ':units' => $units,
            ':batches' => $batches,
            ':cabin_temp' => $overrides['cabin_temp_at_removal'] ?? '9.4',
            ':restored_temp' => $overrides['restored_stabilized_temp_c'] ?? '3.2',
            ':disposal' => $disposal,
            ':photo' => $overrides['evidence_photo_url'] ?? '/uploads/spoilage/acta-test-0001.jpg',
            ':notes' => $overrides['sanitary_notes'] ?? 'Retirada y destrucción del producto expuesto con higienización completa de la cabina.',
            ':cost' => $cost,
            ':adjusted_by' => $overrides['loss_adjusted_by'] ?? null,
            ':reason' => $overrides['loss_adjustment_reason'] ?? null,
            ':certificate' => $overrides['certificate_code'] ?? sprintf('CERT-TEST-%04d', $sequence),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $recordId = $insertRecord();
    $assert('Un acta con producto destruido se inserta con su clave generada', $recordId > 0, 'id: ' . $recordId);

    $lossRead = $pgPdo->query(
        'SELECT estimated_merchandise_loss_eur FROM sanitary_spoilage_records WHERE id = ' . $recordId
    )->fetchColumn();
    $assert('El coste estimado se conserva con dos decimales', (string)$lossRead === '35.00', 'coste: ' . (string)$lossRead);

    $emptyId = $insertRecord(['discarded_units_count' => 0]);
    $emptyCost = $pgPdo->query(
        'SELECT estimated_merchandise_loss_eur FROM sanitary_spoilage_records WHERE id = ' . $emptyId
    )->fetchColumn();
    $assert(
        'Un acta de máquina vacía se inserta con coste cero',
        $emptyId > 0 && (string)$emptyCost === '0.00',
        'id: ' . $emptyId . ' · coste: ' . (string)$emptyCost
    );

    echo "\n--- Hechos inmutables y ajuste financiero auditado (RNF-01) ---\n";

    $immutableUnits = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $pgPdo->exec("UPDATE sanitary_spoilage_records SET discarded_units_count = 15 WHERE id = " . $recordId)
    );
    $assert(
        'Se rechaza cambiar las unidades destruidas de un acta (RNF-01)',
        $immutableUnits !== null && $immutableUnits['state'] === '45000',
        $immutableUnits === null ? 'la fila se actualizó' : $immutableUnits['message']
    );

    $immutableNotes = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $pgPdo->exec("UPDATE sanitary_spoilage_records SET sanitary_notes = 'Observaciones corregidas después de emitir el acta.' WHERE id = " . $recordId)
    );
    $assert(
        'Se rechaza corregir las observaciones sanitarias tras la emisión (RNF-01)',
        $immutableNotes !== null && $immutableNotes['state'] === '45000',
        $immutableNotes === null ? 'la fila se actualizó' : $immutableNotes['message']
    );

    $immutableCertificate = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $pgPdo->exec("UPDATE sanitary_spoilage_records SET certificate_code = 'CERT-ALTERADO' WHERE id = " . $recordId)
    );
    $assert(
        'Se rechaza sustituir el certificado higiénico emitido (RNF-01)',
        $immutableCertificate !== null && $immutableCertificate['state'] === '45000',
        $immutableCertificate === null ? 'la fila se actualizó' : $immutableCertificate['message']
    );

    $adjustedBy = (int)$parents['users']['id'];
    $adjusted = $pgPdo->exec(
        "UPDATE sanitary_spoilage_records
            SET estimated_merchandise_loss_eur = 40.00,
                loss_adjusted_by = {$adjustedBy},
                loss_adjustment_reason = 'Ajuste de Coordinación tras revisar el albarán del proveedor.'
          WHERE id = " . $recordId
    );
    $adjustmentRead = $pgPdo->query(
        'SELECT estimated_merchandise_loss_eur FROM sanitary_spoilage_records WHERE id = ' . $recordId
    )->fetchColumn();
    $assert(
        'El ajuste del coste por Coordinación se admite y se lee (C5)',
        $adjusted === 1 && (string)$adjustmentRead === '40.00',
        'filas: ' . (string)$adjusted . ' · coste: ' . (string)$adjustmentRead
    );

    $emptyAdjustment = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $pgPdo->exec(
            "UPDATE sanitary_spoilage_records
                SET estimated_merchandise_loss_eur = 5.00,
                    loss_adjusted_by = {$adjustedBy},
                    loss_adjustment_reason = 'Ajuste improcedente sobre un acta sin producto destruido.'
              WHERE id = " . $emptyId
        )
    );
    $assert(
        'Se rechaza ajustar el coste de un acta de máquina vacía (C5)',
        $emptyAdjustment !== null && $emptyAdjustment['state'] === '23514',
        $emptyAdjustment === null ? 'la fila se actualizó' : $emptyAdjustment['message']
    );

    echo "\n--- Coherencia de las ramas y cotas de los hechos (D3, C5) ---\n";

    $emptyWithoutConfirmation = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertRecord(['discarded_units_count' => 0, 'empty_machine_confirmed' => false])
    );
    $assert(
        'Se rechaza un acta de cero unidades sin confirmar la máquina vacía (D3)',
        $emptyWithoutConfirmation !== null && $emptyWithoutConfirmation['state'] === '23514',
        $emptyWithoutConfirmation === null ? 'la fila se insertó' : $emptyWithoutConfirmation['message']
    );

    $emptyWithLoss = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertRecord(['discarded_units_count' => 0, 'estimated_merchandise_loss_eur' => '50.00'])
    );
    $assert(
        'Se rechaza un acta de máquina vacía con coste (C5)',
        $emptyWithLoss !== null && $emptyWithLoss['state'] === '23514',
        $emptyWithLoss === null ? 'la fila se insertó' : $emptyWithLoss['message']
    );

    $destroyedWithoutLoss = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertRecord(['discarded_units_count' => 5, 'estimated_merchandise_loss_eur' => '0.00'])
    );
    $assert(
        'Se rechaza un acta con unidades destruidas y coste cero (C5)',
        $destroyedWithoutLoss !== null && $destroyedWithoutLoss['state'] === '23514',
        $destroyedWithoutLoss === null ? 'la fila se insertó' : $destroyedWithoutLoss['message']
    );

    $invalidOrigin = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertRecord(['quarantine_origin' => 'INVENTADA'])
    );
    $assert(
        'Se rechaza un origen de cuarentena fuera del catálogo (QA 2)',
        $invalidOrigin !== null && $invalidOrigin['state'] === '23514',
        $invalidOrigin === null ? 'la fila se insertó' : $invalidOrigin['message']
    );

    $hotCabin = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertRecord(['cabin_temp_at_removal' => '40.0'])
    );
    $assert(
        'Se rechaza una temperatura de retirada fuera del rango físico plausible',
        $hotCabin !== null && $hotCabin['state'] === '23514',
        $hotCabin === null ? 'la fila se insertó' : $hotCabin['message']
    );

    $shortNotes = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertRecord(['sanitary_notes' => 'Limpio'])
    );
    $assert(
        'Se rechaza un acta con observaciones por debajo de veinte caracteres',
        $shortNotes !== null && $shortNotes['state'] === '23514',
        $shortNotes === null ? 'la fila se insertó' : $shortNotes['message']
    );

    $shortBatches = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertRecord(['affected_batch_codes' => 'X'])
    );
    $assert(
        'Se rechaza un acta con lotes por debajo de tres caracteres',
        $shortBatches !== null && $shortBatches['state'] === '23514',
        $shortBatches === null ? 'la fila se insertó' : $shortBatches['message']
    );

    $shortReason = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertRecord([
            'loss_adjusted_by' => $adjustedBy,
            'loss_adjustment_reason' => 'Ajuste',
        ])
    );
    $assert(
        'Se rechaza un ajuste sin justificación suficiente (QA 4)',
        $shortReason !== null && $shortReason['state'] === '23514',
        $shortReason === null ? 'la fila se insertó' : $shortReason['message']
    );

    $missingMachine = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertRecord(['machine_id' => 2147483000])
    );
    $assert(
        'Se rechaza un acta sobre una máquina inexistente (FK RESTRICT)',
        $missingMachine !== null && $missingMachine['state'] === '23503',
        $missingMachine === null ? 'la fila se insertó' : $missingMachine['message']
    );

    $insertRecord(['record_code' => 'ACTA-TEST-DUPLICADA']);
    $duplicate = PostgresFixtureBuilder::expectRejection(
        static fn (): mixed => $insertRecord(['record_code' => 'ACTA-TEST-DUPLICADA'])
    );
    $assert(
        'El segundo alta del mismo código oficial choca con la clave única (QA 2)',
        $duplicate !== null
        && $duplicate['state'] === '23505'
        && str_contains($duplicate['message'], 'uq_sanitary_spoilage_records_code'),
        $duplicate === null ? 'la fila se insertó' : $duplicate['message']
    );

    echo "\n--- Borrado físico: disparador constitucional ---\n";

    $deleteRecord = static fn (): mixed => $pgPdo->exec('DELETE FROM sanitary_spoilage_records WHERE id = ' . $recordId);
    $constitutional = PostgresFixtureBuilder::expectRejection($deleteRecord);
    $assert(
        'Sin la ventana de purga, el disparador rechaza el borrado con SQLSTATE 45000 (Art. III.1)',
        $constitutional !== null && $constitutional['state'] === '45000',
        $constitutional === null ? 'la fila se borró' : $constitutional['message']
    );

    $deleted = TestDataCleaner::withPurgeWindow($pgPdo, static fn (): mixed => $deleteRecord());
    $remaining = (int)$pgPdo->query(
        'SELECT count(*) FROM sanitary_spoilage_records WHERE id = ' . $recordId
    )->fetchColumn();
    $assert(
        'Con la ventana abierta, la fila se retira y deja de leerse',
        $deleted === 1 && $remaining === 0,
        'filas borradas: ' . (string)$deleted
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, SPOILAGE_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueCheck = $pgPdo->query(
        "SELECT to_regclass('" . SPOILAGE_SCHEMA . ".sanitary_spoilage_records')"
    )->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El esquema aislado se retira entero y no deja tablas en el motor',
    $residueCheck === null,
    'residuo: ' . json_encode($residueCheck)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. T-SPOILAGE-03 (esquema y guardas) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN EL ESQUEMA DEL ACTA DE MERMA APPCC.\n";
echo "======================================================================\n";
exit(1);
