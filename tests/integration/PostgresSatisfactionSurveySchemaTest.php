<?php

declare(strict_types=1);

/**
 * PostgresSatisfactionSurveySchemaTest · T-CSAT-01
 *
 * Certifica **en el motor real** el esquema de la encuesta de satisfacción del Módulo 20: la
 * tabla `incident_satisfaction_surveys`, sus cotas de nota y comentario, su unicidad por
 * episodio de resolución y su doble inmutabilidad (Art. III.1).
 *
 * Lo que se prueba aquí es lo que ningún camino de código puede saltarse: aunque un cliente
 * futuro escriba sin pasar por `IncidentSatisfactionSurvey`, la base sigue rechazando una nota
 * fuera de rango, un episodio duplicado o una reescritura de la respuesta.
 *
 *   1. **Nota (RD-04).** Entero entre 1 y 5; 0 y 6 se rechazan.
 *   2. **Comentario (RD-05).** Nulo o con 1–500 caracteres tras `btrim`; vacío se rechaza.
 *   3. **Episodio (RD-01).** `(incident_id, resolution_at)` único; la misma avería admite dos
 *      resoluciones distintas.
 *   4. **Ancla (RF-02.6).** `resolution_at` no admite `NULL`.
 *   5. **Inmutabilidad (Art. III.1).** `UPDATE` y `DELETE` lanzan `SQLSTATE '45000'`; el borrado
 *      solo cede dentro de la ventana de purga del arnés.
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
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresSatisfactionSurveySchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Infrastructure\Database\PostgresMigrationRunner;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta suite. */
const SURVEY_TEST_SCHEMA = 't_csat01_surveys';

/** Marca que el disparador constitucional antepone a sus rechazos (Art. III.1). */
const SURVEY_PURGE_MARKER = 'ART_III_1_VIOLATION';

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
echo " VENDGUARD · T-CSAT-01 · Esquema de la encuesta CSAT (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

/**
 * Inserta una encuesta con los valores recibidos y devuelve el `SQLSTATE` del rechazo, o `null`.
 *
 * @param array{incident_id: int, location_id: int, technician_id?: int|null,
 *              technician_name_snapshot?: string|null, resolution_at?: string,
 *              rating?: int|string|null, comment?: string|null} $values
 */
$insertSurvey = static function (PDO $pdo, array $values): ?array {
    $sql = 'INSERT INTO incident_satisfaction_surveys
                (incident_id, location_id, technician_id, technician_name_snapshot,
                 resolution_at, rating, comment)
            VALUES (:incident_id, :location_id, :technician_id, :technician_name_snapshot,
                    :resolution_at, :rating, :comment)';

    $params = [
        ':incident_id' => $values['incident_id'],
        ':location_id' => $values['location_id'],
        ':technician_id' => $values['technician_id'] ?? null,
        ':technician_name_snapshot' => $values['technician_name_snapshot'] ?? null,
        ':resolution_at' => array_key_exists('resolution_at', $values) ? $values['resolution_at'] : '2026-10-22 17:04:11+02',
        ':rating' => $values['rating'] ?? 5,
        ':comment' => $values['comment'] ?? null,
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
    echo "\n--- Escenario (DDL base + migración 011 en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, SURVEY_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la migración 011 aplican sin errores y el esquema converge',
        in_array('011_incident_satisfaction_surveys.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf('%d sentencias · migraciones: %s', $schemaReport['statements'], implode(', ', $schemaReport['migrations']))
    );

    $columnCount = (int)$pgPdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = 'incident_satisfaction_surveys'"
    )->fetchColumn();
    $assert(
        'La tabla incident_satisfaction_surveys declara sus nueve columnas',
        $columnCount === 9,
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
        'El escenario se siembra entero, incluida la encuesta mínima de la tabla nueva',
        $insertionFailure === null && isset($rows['incident_satisfaction_surveys']),
        $insertionFailure ?? 'tablas sembradas: ' . count($rows)
    );

    $incidentId = (int)$rows['incidents']['id'];
    $locationId = (int)$rows['locations']['id'];
    $technicianId = (int)$rows['users']['id'];

    // ──────────────────────────────────────────────────────────────────────
    // 1. Nota entera de 1 a 5 (RD-04)
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Nota: entero de 1 a 5 ---\n";

    $acceptedRatingOne = $insertSurvey($pgPdo, [
        'incident_id' => $incidentId,
        'location_id' => $locationId,
        'technician_id' => $technicianId,
        'technician_name_snapshot' => 'Ana Ruiz de Certificación',
        'resolution_at' => '2026-10-22 08:00:00+02',
        'rating' => 1,
    ]);
    $assert(
        'La nota mínima (1) se admite',
        $acceptedRatingOne === null,
        $acceptedRatingOne === null ? 'insertada' : 'estado=' . $acceptedRatingOne['state'] . ' · ' . $acceptedRatingOne['message']
    );

    $acceptedRatingFive = $insertSurvey($pgPdo, [
        'incident_id' => $incidentId,
        'location_id' => $locationId,
        'resolution_at' => '2026-10-22 09:00:00+02',
        'rating' => 5,
    ]);
    $assert(
        'La nota máxima (5) se admite',
        $acceptedRatingFive === null,
        $acceptedRatingFive === null ? 'insertada' : 'estado=' . $acceptedRatingFive['state']
    );

    $ratingZero = $insertSurvey($pgPdo, [
        'incident_id' => $incidentId,
        'location_id' => $locationId,
        'resolution_at' => '2026-10-22 10:00:00+02',
        'rating' => 0,
    ]);
    $assert(
        'La nota 0 se rechaza con 23514 por chk_survey_rating',
        $ratingZero !== null
            && $ratingZero['state'] === '23514'
            && str_contains($ratingZero['message'], 'chk_survey_rating'),
        $ratingZero === null ? 'se admitió la nota 0' : 'estado=' . $ratingZero['state']
    );

    $ratingSix = $insertSurvey($pgPdo, [
        'incident_id' => $incidentId,
        'location_id' => $locationId,
        'resolution_at' => '2026-10-22 11:00:00+02',
        'rating' => 6,
    ]);
    $assert(
        'La nota 6 se rechaza con 23514 por chk_survey_rating',
        $ratingSix !== null
            && $ratingSix['state'] === '23514'
            && str_contains($ratingSix['message'], 'chk_survey_rating'),
        $ratingSix === null ? 'se admitió la nota 6' : 'estado=' . $ratingSix['state']
    );

    // ──────────────────────────────────────────────────────────────────────
    // 2. Comentario opcional de 1 a 500 caracteres (RD-05)
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Comentario: nulo o de 1 a 500 caracteres ---\n";

    $emptyComment = $insertSurvey($pgPdo, [
        'incident_id' => $incidentId,
        'location_id' => $locationId,
        'resolution_at' => '2026-10-22 12:00:00+02',
        'comment' => '',
    ]);
    $assert(
        'Un comentario vacío se rechaza con 23514 por chk_survey_comment',
        $emptyComment !== null
            && $emptyComment['state'] === '23514'
            && str_contains($emptyComment['message'], 'chk_survey_comment'),
        $emptyComment === null ? 'se admitió el comentario vacío' : 'estado=' . $emptyComment['state']
    );

    $maxComment = $insertSurvey($pgPdo, [
        'incident_id' => $incidentId,
        'location_id' => $locationId,
        'resolution_at' => '2026-10-22 13:00:00+02',
        'comment' => str_repeat('a', 500),
    ]);
    $assert(
        'Un comentario de 500 caracteres se admite',
        $maxComment === null,
        $maxComment === null ? 'insertado' : 'estado=' . $maxComment['state']
    );

    $overlongComment = $insertSurvey($pgPdo, [
        'incident_id' => $incidentId,
        'location_id' => $locationId,
        'resolution_at' => '2026-10-22 14:00:00+02',
        'comment' => str_repeat('a', 501),
    ]);
    $assert(
        'Un comentario de 501 caracteres se rechaza',
        $overlongComment !== null,
        $overlongComment === null ? 'se admitió el comentario de 501' : 'estado=' . $overlongComment['state']
    );

    // ──────────────────────────────────────────────────────────────────────
    // 3. Unicidad por episodio de resolución (RD-01)
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Episodio: unicidad por (incident_id, resolution_at) ---\n";

    $duplicateEpisode = $insertSurvey($pgPdo, [
        'incident_id' => $incidentId,
        'location_id' => $locationId,
        'resolution_at' => '2026-10-22 08:00:00+02',
        'rating' => 4,
    ]);
    $assert(
        'El mismo episodio valorado dos veces se rechaza con 23505 por uq_survey_episode',
        $duplicateEpisode !== null
            && $duplicateEpisode['state'] === '23505'
            && str_contains($duplicateEpisode['message'], 'uq_survey_episode'),
        $duplicateEpisode === null ? 'se admitió el duplicado' : 'estado=' . $duplicateEpisode['state']
    );

    $sameIncidentNewEpisode = $insertSurvey($pgPdo, [
        'incident_id' => $incidentId,
        'location_id' => $locationId,
        'resolution_at' => '2026-10-25 16:30:00+02',
        'rating' => 3,
    ]);
    $assert(
        'La misma avería admite una segunda encuesta tras otra resolución',
        $sameIncidentNewEpisode === null,
        $sameIncidentNewEpisode === null ? 'insertada' : 'estado=' . $sameIncidentNewEpisode['state']
    );

    // ──────────────────────────────────────────────────────────────────────
    // 4. Ancla de vigencia obligatoria (RF-02.6)
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Ancla de vigencia ---\n";

    $missingResolution = $insertSurvey($pgPdo, [
        'incident_id' => $incidentId,
        'location_id' => $locationId,
        'resolution_at' => null,
        'rating' => 4,
    ]);
    $assert(
        'Una encuesta sin resolution_at se rechaza con 23502',
        $missingResolution !== null && $missingResolution['state'] === '23502',
        $missingResolution === null ? 'se admitió la encuesta sin ancla' : 'estado=' . $missingResolution['state']
    );

    // ──────────────────────────────────────────────────────────────────────
    // 5. Inmutabilidad (Art. III.1)
    // ──────────────────────────────────────────────────────────────────────
    echo "\n--- Inmutabilidad: UPDATE y DELETE ---\n";

    $stmt = $pgPdo->prepare('SELECT id FROM incident_satisfaction_surveys WHERE incident_id = :id ORDER BY id LIMIT 1');
    $stmt->execute([':id' => $incidentId]);
    $surveyId = (int)$stmt->fetchColumn();

    $updateRejection = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $surveyId): void {
        $stmt = $pgPdo->prepare('UPDATE incident_satisfaction_surveys SET rating = 1 WHERE id = :id');
        $stmt->execute([':id' => $surveyId]);
    });
    $assert(
        'La reescritura de una respuesta se rechaza con 45000 y la marca constitucional',
        $updateRejection !== null
            && $updateRejection['state'] === '45000'
            && str_contains($updateRejection['message'], SURVEY_PURGE_MARKER),
        $updateRejection === null ? 'se admitió el UPDATE' : 'estado=' . $updateRejection['state']
    );

    $deleteRejection = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $surveyId): void {
        $stmt = $pgPdo->prepare('DELETE FROM incident_satisfaction_surveys WHERE id = :id');
        $stmt->execute([':id' => $surveyId]);
    });
    $assert(
        'El borrado fuera de la ventana de purga se rechaza con 45000 y la marca constitucional',
        $deleteRejection !== null
            && $deleteRejection['state'] === '45000'
            && str_contains($deleteRejection['message'], SURVEY_PURGE_MARKER),
        $deleteRejection === null ? 'se admitió el DELETE' : 'estado=' . $deleteRejection['state']
    );

    TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo, $surveyId, &$purgedRows): void {
        $stmt = $pgPdo->prepare('DELETE FROM incident_satisfaction_surveys WHERE id = :id');
        $stmt->execute([':id' => $surveyId]);
        $purgedRows = $stmt->rowCount();
    });
    $assert(
        'Dentro de la ventana de purga del arnés el borrado afecta exactamente a una fila',
        $purgedRows === 1,
        'filas borradas: ' . var_export($purgedRows ?? null, true)
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, SURVEY_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    // `to_regclass` devuelve `NULL` cuando la relación no existe: sonda de residuo que no
    // depende del texto exacto del mensaje del motor.
    $residueProbe = $pgPdo->query("SELECT to_regclass('" . SURVEY_TEST_SCHEMA . ".incident_satisfaction_surveys')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado y su tabla ya no existen en el motor',
    $residueProbe === null,
    'la sonda de residuo encontró ' . json_encode($residueProbe)
);

if ($crash !== null) {
    $failures++;
    echo "\n[FAIL] Excepción no controlada: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo sprintf(" RESULTADO: %d aserciones · %d fallos\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
