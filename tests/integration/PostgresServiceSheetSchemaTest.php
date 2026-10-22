<?php

declare(strict_types=1);

/**
 * PostgresServiceSheetSchemaTest · T-SIGN-01
 *
 * Certifica **en el motor real** el esquema de la hoja de servicio del Módulo 19: la tabla
 * `incident_service_sheets`, sus `CHECK` de rama, tamaño y documento, su ancla de vigencia, su
 * índice de historial y su blindaje constitucional.
 *
 * Lo que se prueba aquí es lo que ningún camino de código puede saltarse: aunque un cliente futuro
 * escriba sin pasar por `ServiceSheetValidator`, la base sigue rechazando una hoja incoherente.
 *
 *   1. **Rama excluyente (RF-02.2).** Firma con nombre y documento, o ausencia con motivo de al
 *      menos 10 caracteres; nunca las dos ni ninguna.
 *   2. **Tamaño del trazo (RNF-02).** Entre 64 y 307 200 bytes.
 *   3. **Documento congelado (RD-10).** Objeto JSON con clave `version`.
 *   4. **Ancla de vigencia (RF-03.6).** `resolution_at` no admite `NULL`.
 *   5. **Historial (RD-01).** Dos hojas para la misma avería conviven: una reapertura añade, no
 *      reemplaza.
 *   6. **Inmutabilidad (Art. III.1).** `DELETE` lanza `SQLSTATE '45000'` con la marca
 *      constitucional.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible (el motor de `deploy/local/postgres/`). NO pertenece a la
 *   batería hermética (RNF-04): se declara con su marca de categoría, se ejecuta en su canal
 *   propio y su informe va aparte. Sin `PG_TEST_DSN` la suite **no se omite en silencio**: lo dice
 *   y sale con código 2.
 *
 * LIMPIEZA: el escenario vive en su propio esquema y se borra con `CASCADE` al terminar, incluso
 * si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresServiceSheetSchemaTest.php
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

/** Esquema aislado de esta suite. */
const SERVICE_SHEET_TEST_SCHEMA = 't_sign01_sheets';

/** Marca que el disparador constitucional antepone a sus rechazos (Art. III.1). */
const SERVICE_SHEET_PURGE_MARKER = 'ART_III_1_VIOLATION';

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

/**
 * PNG mínimo y bien formado: firma, `IHDR` con las dimensiones pedidas, relleno y `IEND`.
 * El `CHECK` solo mira la longitud en bytes, así que no hace falta comprimir píxeles reales.
 */
$pngBytes = static function (int $width, int $height, int $padding = 0): string {
    $chunk = static fn(string $type, string $data): string
        => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0));
    if ($padding > 0) {
        $png .= $chunk('tEXt', "Padding\x00" . str_repeat('x', $padding));
    }

    return $png . $chunk('IEND', '');
};

echo "======================================================================\n";
echo " VENDGUARD · T-SIGN-01 · Esquema de la hoja de servicio (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

/**
 * Inserta una hoja con los valores recibidos y devuelve el `SQLSTATE` del rechazo, o `null`.
 *
 * El trazo viaja como hexadecimal y se decodifica en SQL: así la suite no depende de cómo trate
 * el driver un `bytea` como parámetro.
 *
 * @param array{signature?: string|null, name?: string|null, document?: string|null,
 *              reason?: string|null, resolution_at?: string|null, document_json?: string} $values
 */
$insertSheet = static function (PDO $pdo, int $incidentId, int $technicianId, array $values): ?array {
    $sql = 'INSERT INTO incident_service_sheets
                (incident_id, technician_id, signer_name, signer_document, signature_png,
                 absence_reason, resolution_at, signed_document)
            VALUES (:incident_id, :technician_id, :signer_name, :signer_document,
                    decode(:signature_png, \'hex\'), :absence_reason, :resolution_at,
                    CAST(:signed_document AS jsonb))';

    $signature = $values['signature'] ?? null;

    $params = [
        ':incident_id' => $incidentId,
        ':technician_id' => $technicianId,
        ':signer_name' => $values['name'] ?? null,
        ':signer_document' => $values['document'] ?? null,
        ':signature_png' => $signature === null ? null : bin2hex($signature),
        ':absence_reason' => $values['reason'] ?? null,
        ':resolution_at' => array_key_exists('resolution_at', $values) ? $values['resolution_at'] : '2026-10-22 09:00:00+00',
        ':signed_document' => $values['document_json'] ?? '{"version":1,"ticket_code":"CERT-19-0001"}',
    ];

    return PostgresFixtureBuilder::expectRejection(static function () use ($pdo, $sql, $params): void {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    });
};

$crash = null;
$residueProbe = null;

try {
    // ──────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio en un esquema aislado
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migración 010 en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, SERVICE_SHEET_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la migración 010 aplican sin errores y el esquema converge',
        in_array('010_incident_service_sheets.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf('%d sentencias · migraciones: %s', $schemaReport['statements'], implode(', ', $schemaReport['migrations']))
    );

    $columnCount = (int)$pgPdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = 'incident_service_sheets'"
    )->fetchColumn();
    $assert(
        'La tabla incident_service_sheets declara sus diez columnas',
        $columnCount === 10,
        'columnas: ' . $columnCount
    );

    // Alta mínima del grafo completo: la suite no reimplementa el escenario del arnés.
    $rows = [];
    $insertionFailure = null;
    $order = PostgresFixtureBuilder::insertionOrder($pgPdo, PostgresFixtureBuilder::tables($pgPdo));
    foreach ($order as $index => $table) {
        try {
            $rows[$table] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, $table, $rows, sprintf('%02d', $index + 1));
        } catch (Throwable $exception) {
            $insertionFailure = "{$table}: " . $exception->getMessage();
            break;
        }
    }
    $assert(
        'El escenario se siembra entero, incluida la hoja mínima de la tabla nueva',
        $insertionFailure === null && isset($rows['incident_service_sheets']),
        $insertionFailure ?? 'tablas sembradas: ' . count($rows)
    );

    $incidentId = (int)$rows['incidents']['id'];
    $technicianId = (int)$rows['users']['id'];
    $png = $pngBytes(1, 1, 40);

    // ──────────────────────────────────────────────────────────────────────
    // 1. Rama excluyente (RF-02.2)
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Rama excluyente: firma o ausencia ---\n";

    $signedRejection = $insertSheet($pgPdo, $incidentId, $technicianId, [
        'signature' => $png,
        'name' => 'Ana Ruiz de Certificación',
        'document' => '12345678Z',
    ]);
    $assert(
        'La rama de firma con nombre y documento se admite',
        $signedRejection === null,
        $signedRejection === null ? 'insertada' : 'estado=' . $signedRejection['state'] . ' · ' . $signedRejection['message']
    );

    $absenceRejection = $insertSheet($pgPdo, $incidentId, $technicianId, [
        'reason' => 'Sede sin conserjería, acceso por llave',
    ]);
    $assert(
        'La rama de ausencia con motivo suficiente se admite',
        $absenceRejection === null,
        $absenceRejection === null ? 'insertada' : 'estado=' . $absenceRejection['state']
    );

    $bothBranches = $insertSheet($pgPdo, $incidentId, $technicianId, [
        'signature' => $png,
        'name' => 'Ana Ruiz de Certificación',
        'document' => '12345678Z',
        'reason' => 'No había nadie en la sede',
    ]);
    $assert(
        'Firma y motivo a la vez se rechazan con 23514 por chk_service_sheet_branch',
        $bothBranches !== null
            && $bothBranches['state'] === '23514'
            && str_contains($bothBranches['message'], 'chk_service_sheet_branch'),
        $bothBranches === null ? 'se admitieron las dos ramas' : 'estado=' . $bothBranches['state']
    );

    $noBranch = $insertSheet($pgPdo, $incidentId, $technicianId, []);
    $assert(
        'Ni firma ni motivo se rechazan con 23514',
        $noBranch !== null && $noBranch['state'] === '23514',
        $noBranch === null ? 'se admitió una hoja vacía' : 'estado=' . $noBranch['state']
    );

    $shortReason = $insertSheet($pgPdo, $incidentId, $technicianId, ['reason' => 'Corto']);
    $assert(
        'Un motivo de ausencia de menos de 10 caracteres se rechaza',
        $shortReason !== null && $shortReason['state'] === '23514',
        $shortReason === null ? 'se admitió el motivo corto' : 'estado=' . $shortReason['state']
    );

    $signedWithoutDocument = $insertSheet($pgPdo, $incidentId, $technicianId, [
        'signature' => $png,
        'name' => 'Ana Ruiz de Certificación',
    ]);
    $assert(
        'Una firma sin documento se rechaza',
        $signedWithoutDocument !== null && $signedWithoutDocument['state'] === '23514',
        $signedWithoutDocument === null ? 'se admitió la firma incompleta' : 'estado=' . $signedWithoutDocument['state']
    );

    // ──────────────────────────────────────────────────────────────────────
    // 2. Tamaño del trazo (RNF-02)
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Cotas del trazo ---\n";

    $tinyPng = $insertSheet($pgPdo, $incidentId, $technicianId, [
        'signature' => "\x89PNG\r\n\x1a\n" . str_repeat('A', 20),
        'name' => 'Ana Ruiz de Certificación',
        'document' => '12345678Z',
    ]);
    $assert(
        'Un trazo por debajo de 64 bytes se rechaza por chk_service_sheet_png_size',
        $tinyPng !== null
            && $tinyPng['state'] === '23514'
            && str_contains($tinyPng['message'], 'chk_service_sheet_png_size'),
        $tinyPng === null ? 'se admitió el trazo diminuto' : 'estado=' . $tinyPng['state']
    );

    $hugePng = $insertSheet($pgPdo, $incidentId, $technicianId, [
        'signature' => $pngBytes(1, 1, 307200),
        'name' => 'Ana Ruiz de Certificación',
        'document' => '12345678Z',
    ]);
    $assert(
        'Un trazo por encima de 307 200 bytes se rechaza',
        $hugePng !== null && $hugePng['state'] === '23514',
        $hugePng === null ? 'se admitió el trazo sobredimensionado' : 'estado=' . $hugePng['state']
    );

    // ──────────────────────────────────────────────────────────────────────
    // 3. Documento congelado y ancla de vigencia (RD-10, RF-03.6)
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Documento congelado y ancla ---\n";

    $arrayDocument = $insertSheet($pgPdo, $incidentId, $technicianId, [
        'reason' => 'Sede sin conserjería, acceso por llave',
        'document_json' => '["version"]',
    ]);
    $assert(
        'Un documento que no es objeto se rechaza por chk_service_sheet_document',
        $arrayDocument !== null
            && $arrayDocument['state'] === '23514'
            && str_contains($arrayDocument['message'], 'chk_service_sheet_document'),
        $arrayDocument === null ? 'se admitió un array como documento' : 'estado=' . $arrayDocument['state']
    );

    $unversionedDocument = $insertSheet($pgPdo, $incidentId, $technicianId, [
        'reason' => 'Sede sin conserjería, acceso por llave',
        'document_json' => '{"ticket_code":"CERT-19-0002"}',
    ]);
    $assert(
        'Un documento sin clave version se rechaza',
        $unversionedDocument !== null && $unversionedDocument['state'] === '23514',
        $unversionedDocument === null ? 'se admitió un documento sin versión' : 'estado=' . $unversionedDocument['state']
    );

    $noResolutionAt = $insertSheet($pgPdo, $incidentId, $technicianId, [
        'reason' => 'Sede sin conserjería, acceso por llave',
        'resolution_at' => null,
    ]);
    $assert(
        'Una hoja sin resolution_at se rechaza con 23502 (ancla de vigencia)',
        $noResolutionAt !== null && $noResolutionAt['state'] === '23502',
        $noResolutionAt === null ? 'se admitió una hoja sin ancla' : 'estado=' . $noResolutionAt['state']
    );

    // ──────────────────────────────────────────────────────────────────────
    // 4. Varias hojas por avería e índice de historial (RD-01)
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Historial y ausencia de unicidad por avería ---\n";

    $sheetCount = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM incident_service_sheets WHERE incident_id = ' . $incidentId
    )->fetchColumn();
    $assert(
        'Una reapertura añade otra hoja: la avería acumula más de una',
        $sheetCount >= 3,
        'hojas: ' . $sheetCount
    );

    $indexNames = $pgPdo->query(
        "SELECT indexname FROM pg_indexes
          WHERE schemaname = current_schema() AND tablename = 'incident_service_sheets'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(
        'El índice de historial idx_service_sheet_incident está declarado',
        in_array('idx_service_sheet_incident', $indexNames, true),
        implode(' · ', $indexNames)
    );

    $foreignKeys = $pgPdo->query(
        "SELECT constraint_name FROM information_schema.table_constraints
          WHERE table_schema = current_schema() AND table_name = 'incident_service_sheets'
            AND constraint_type = 'FOREIGN KEY'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(
        'Las claves foráneas de avería y técnico están declaradas',
        in_array('fk_service_sheet_incident', $foreignKeys, true)
            && in_array('fk_service_sheet_technician', $foreignKeys, true),
        implode(' · ', $foreignKeys)
    );

    // ──────────────────────────────────────────────────────────────────────
    // 5. Inmutabilidad (Art. III.1)
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Borrado físico rechazado ---\n";

    $physicalDelete = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo): void {
        $pgPdo->exec('DELETE FROM incident_service_sheets');
    });
    $assert(
        'El borrado físico de una hoja se rechaza con SQLSTATE 45000 (Art. III.1)',
        $physicalDelete !== null
            && $physicalDelete['state'] === '45000'
            && str_contains($physicalDelete['message'], SERVICE_SHEET_PURGE_MARKER),
        $physicalDelete === null ? 'el borrado pasó' : 'estado=' . $physicalDelete['state']
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, SERVICE_SHEET_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueProbe = $pgPdo->query("SELECT to_regclass('" . SERVICE_SHEET_TEST_SCHEMA . ".incident_service_sheets')")->fetchColumn();
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
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-SIGN-01 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
