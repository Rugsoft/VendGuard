<?php

declare(strict_types=1);

/**
 * PostgresRootCauseTaxonomySchemaTest · T-TAXONOMY-02
 *
 * Certifica las tres guardas de esquema que sostienen la taxonomía del Módulo 25
 * sobre el motor único PostgreSQL:
 *
 *   1. **Una sola solución predeterminada activa por causa.** El índice único
 *      parcial `uq_standard_solutions_default_per_cause` rechaza una segunda
 *      solución con `is_default = TRUE AND is_active = TRUE` para la misma causa
 *      (D1, EARS 01.1), pero admite varias soluciones inactivas no predeterminadas
 *      y rechaza una inactiva que conserve la marca (`chk_standard_solutions_default_active`).
 *
 *   2. **Coherencia todo-o-nada de la clasificación del expediente.** El CHECK
 *      `chk_incidents_taxonomy_coherence` rechaza una avería con `root_cause_id`
 *      pero sin `standard_solution_id` y también una con las dos referencias pero
 *      sin alguno de los cinco snapshots; sólo acepta los siete campos completos
 *      o los siete vacíos (EARS 02.5, Art. III.3).
 *
 *   3. **Trazabilidad histórica blindada.** Con la ventana de purga del arnés
 *      abierta —para que el disparador constitucional no sea el que rechace—, las
 *      FKs `ON DELETE RESTRICT` de `incidents` impiden borrar una causa o una
 *      solución referenciada por un expediente (Art. III.1). Sin la ventana, el
 *      rechazo es el del disparador (`45000`).
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible a través de `PG_TEST_DSN`, sin valor por
 *   defecto. Sin motor, la suite se declara no ejecutable con código 2 y no se
 *   omite en silencio.
 *
 * LIMPIEZA: el escenario vive en un esquema aislado que se borra con `CASCADE`
 * en el `finally`, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/PostgresRootCauseTaxonomySchemaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado propio: no comparte escenario con las demás suites del canal. */
const TAXONOMY_SCHEMA = 't_taxonomy25_schema';

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
echo " VENDGUARD · T-TAXONOMY-02 · Guardas de esquema de la taxonomía\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$crash = null;
$residueCheck = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones del motor nuevo) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, TAXONOMY_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    $assert(
        'El esquema del repositorio se aplica entero, incluida la migración 021',
        $schemaReport['statements'] > 100 && in_array('021_root_cause_taxonomy.sql', $schemaReport['migrations'], true),
        sprintf('%d sentencias · migraciones: %s', $schemaReport['statements'], implode(', ', $schemaReport['migrations']))
    );

    // Padres mínimos del expediente: el alta genérica del arnés respeta el grafo.
    $parents = [];
    $parents['locations'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'locations', $parents, '01');
    $parents['machines'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'machines', $parents, '01');
    $parents['users'] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'users', $parents, '01');
    $incident = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'incidents', $parents, '01');
    $incidentId = (int)$incident['id'];

    $insertCause = static function (PDO $pdo, string $code, string $name): int {
        $stmt = $pdo->prepare(
            "INSERT INTO root_causes (code, category, name)
             VALUES (:code, 'PAYMENT_SYSTEM', :name)
             RETURNING id"
        );
        $stmt->execute([':code' => $code, ':name' => $name]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("El motor no devolvió el id de la causa «{$code}».");
        }

        return (int)$id;
    };

    $insertSolution = static function (
        PDO $pdo,
        int $causeId,
        string $code,
        string $name,
        bool $isDefault,
        bool $isActive
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO standard_solutions (root_cause_id, code, name, is_default, is_active)
             VALUES (:cause_id, :code, :name, '
                . ($isDefault ? 'TRUE' : 'FALSE') . ', '
                . ($isActive ? 'TRUE' : 'FALSE') . ')
             RETURNING id'
        );
        $stmt->execute([
            ':cause_id' => $causeId,
            ':code' => $code,
            ':name' => $name,
        ]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("El motor no devolvió el id de la solución «{$code}».");
        }

        return (int)$id;
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Índice único parcial de la solución predeterminada activa
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Índice único parcial de solución predeterminada ---\n";

    $causeId = $insertCause($pgPdo, 'RC-TEST-001', 'Causa de certificación del esquema');
    $defaultSolutionId = $insertSolution(
        $pgPdo,
        $causeId,
        'SOL-TEST-001A',
        'Solución predeterminada de certificación',
        true,
        true
    );

    $secondDefault = PostgresFixtureBuilder::expectRejection(
        static fn (): int => $insertSolution(
            $pgPdo,
            $causeId,
            'SOL-TEST-001B',
            'Segunda solución predeterminada de certificación',
            true,
            true
        )
    );
    $assert(
        'Una segunda solución activa con is_default = TRUE para la misma causa se rechaza',
        $secondDefault !== null && $secondDefault['state'] === '23505',
        $secondDefault === null ? 'la fila se insertó' : $secondDefault['message']
    );

    $inactiveSolutionA = $insertSolution(
        $pgPdo,
        $causeId,
        'SOL-TEST-001C',
        'Solución inactiva no predeterminada A',
        false,
        false
    );
    $inactiveSolutionB = $insertSolution(
        $pgPdo,
        $causeId,
        'SOL-TEST-001D',
        'Solución inactiva no predeterminada B',
        false,
        false
    );
    $assert(
        'Se admiten varias soluciones inactivas con is_default = FALSE para la misma causa',
        $inactiveSolutionA > 0 && $inactiveSolutionB > 0
    );

    $inactiveDefault = PostgresFixtureBuilder::expectRejection(
        static fn (): int => $insertSolution(
            $pgPdo,
            $causeId,
            'SOL-TEST-001E',
            'Solución inactiva que intenta conservar la marca',
            true,
            false
        )
    );
    $assert(
        'Una solución inactiva no puede conservar is_default = TRUE',
        $inactiveDefault !== null && $inactiveDefault['state'] === '23514',
        $inactiveDefault === null ? 'la fila se insertó' : $inactiveDefault['message']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Coherencia todo-o-nada de los siete campos del expediente
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Coherencia todo-o-nada de la clasificación del expediente ---\n";

    $partialReference = PostgresFixtureBuilder::expectRejection(
        static function () use ($pgPdo, $incidentId, $causeId): void {
            $stmt = $pgPdo->prepare('UPDATE incidents SET root_cause_id = :cause_id WHERE id = :id');
            $stmt->execute([':cause_id' => $causeId, ':id' => $incidentId]);
        }
    );
    $assert(
        'Se rechaza root_cause_id sin standard_solution_id',
        $partialReference !== null && $partialReference['state'] === '23514',
        $partialReference === null ? 'la fila se actualizó' : $partialReference['message']
    );

    $partialSnapshots = PostgresFixtureBuilder::expectRejection(
        static function () use ($pgPdo, $incidentId, $causeId, $defaultSolutionId): void {
            $stmt = $pgPdo->prepare(
                "UPDATE incidents
                    SET root_cause_id = :cause_id,
                        standard_solution_id = :solution_id,
                        root_cause_code_snapshot = 'RC-TEST-001',
                        root_cause_category_snapshot = 'PAYMENT_SYSTEM',
                        root_cause_name_snapshot = 'Causa de certificación del esquema',
                        standard_solution_code_snapshot = 'SOL-TEST-001A'
                  WHERE id = :id"
            );
            $stmt->execute([
                ':cause_id' => $causeId,
                ':solution_id' => $defaultSolutionId,
                ':id' => $incidentId,
            ]);
        }
    );
    $assert(
        'Se rechaza una clasificación con las referencias pero sin el nombre congelado de la solución',
        $partialSnapshots !== null && $partialSnapshots['state'] === '23514',
        $partialSnapshots === null ? 'la fila se actualizó' : $partialSnapshots['message']
    );

    $classify = $pgPdo->prepare(
        "UPDATE incidents
            SET root_cause_id = :cause_id,
                standard_solution_id = :solution_id,
                root_cause_code_snapshot = 'RC-TEST-001',
                root_cause_category_snapshot = 'PAYMENT_SYSTEM',
                root_cause_name_snapshot = 'Causa de certificación del esquema',
                standard_solution_code_snapshot = 'SOL-TEST-001A',
                standard_solution_name_snapshot = 'Solución predeterminada de certificación'
          WHERE id = :id"
    );
    $classify->execute([
        ':cause_id' => $causeId,
        ':solution_id' => $defaultSolutionId,
        ':id' => $incidentId,
    ]);
    $frozen = $pgPdo->prepare(
        'SELECT root_cause_code_snapshot, root_cause_category_snapshot, root_cause_name_snapshot,
                standard_solution_code_snapshot, standard_solution_name_snapshot
           FROM incidents WHERE id = :id'
    );
    $frozen->execute([':id' => $incidentId]);
    $snapshots = $frozen->fetch();
    $assert(
        'Una clasificación con los siete campos completos se persiste y se lee congelada',
        $classify->rowCount() === 1
            && is_array($snapshots)
            && $snapshots['root_cause_code_snapshot'] === 'RC-TEST-001'
            && $snapshots['root_cause_category_snapshot'] === 'PAYMENT_SYSTEM'
            && $snapshots['root_cause_name_snapshot'] === 'Causa de certificación del esquema'
            && $snapshots['standard_solution_code_snapshot'] === 'SOL-TEST-001A'
            && $snapshots['standard_solution_name_snapshot'] === 'Solución predeterminada de certificación'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. FKs ON DELETE RESTRICT y disparador constitucional
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Borrado físico: FK RESTRICT y blindaje constitucional ---\n";

    // La causa de la incidencia se sustituye por una **sin soluciones hijas**:
    // así el primer rechazo que mide la prueba es el del expediente
    // (`fk_incidents_root_cause`) y no el de las soluciones de la causa
    // (`fk_standard_solutions_root_cause`). La pertenencia causa-solución la
    // valida el dominio; el esquema sólo exige que ambos campos existan.
    $causeForIncidentId = $insertCause(
        $pgPdo,
        'RC-TEST-002',
        'Causa sin soluciones para aislar la FK del expediente'
    );
    $reclassify = $pgPdo->prepare(
        "UPDATE incidents
            SET root_cause_id = :cause_id,
                root_cause_code_snapshot = 'RC-TEST-002',
                root_cause_name_snapshot = 'Causa sin soluciones para aislar la FK del expediente'
          WHERE id = :id"
    );
    $reclassify->execute([':cause_id' => $causeForIncidentId, ':id' => $incidentId]);
    $assert(
        'El expediente queda referenciando una causa sin soluciones hijas',
        $reclassify->rowCount() === 1
    );

    $deleteCause = static function () use ($pgPdo, $causeForIncidentId): void {
        $stmt = $pgPdo->prepare('DELETE FROM root_causes WHERE id = :id');
        $stmt->execute([':id' => $causeForIncidentId]);
    };

    $causeRestrict = TestDataCleaner::withPurgeWindow(
        $pgPdo,
        static fn (): ?array => PostgresFixtureBuilder::expectRejection($deleteCause)
    );
    $assert(
        'Con la ventana de purga abierta, fk_incidents_root_cause impide borrar la causa referenciada',
        $causeRestrict !== null
            && $causeRestrict['state'] === '23503'
            && str_contains($causeRestrict['message'], 'fk_incidents_root_cause'),
        $causeRestrict === null ? 'la causa se borró' : $causeRestrict['message']
    );

    $deleteSolution = static function () use ($pgPdo, $defaultSolutionId): void {
        $stmt = $pgPdo->prepare('DELETE FROM standard_solutions WHERE id = :id');
        $stmt->execute([':id' => $defaultSolutionId]);
    };

    $solutionRestrict = TestDataCleaner::withPurgeWindow(
        $pgPdo,
        static fn (): ?array => PostgresFixtureBuilder::expectRejection($deleteSolution)
    );
    $assert(
        'Con la ventana de purga abierta, fk_incidents_standard_solution impide borrar la solución referenciada',
        $solutionRestrict !== null
            && $solutionRestrict['state'] === '23503'
            && str_contains($solutionRestrict['message'], 'fk_incidents_standard_solution'),
        $solutionRestrict === null ? 'la solución se borró' : $solutionRestrict['message']
    );

    $constitutionalRejection = PostgresFixtureBuilder::expectRejection($deleteCause);
    $assert(
        'Sin la ventana de purga, el disparador constitucional rechaza el borrado con SQLSTATE 45000',
        $constitutionalRejection !== null && $constitutionalRejection['state'] === '45000',
        $constitutionalRejection === null ? 'la causa se borró' : $constitutionalRejection['message']
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, TAXONOMY_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueCheck = $pgPdo->query(
        "SELECT to_regclass('" . TAXONOMY_SCHEMA . ".incidents')"
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
    echo " RESULT: 100% EN VERDE. T-TAXONOMY-02 (guardas de esquema) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN LAS GUARDAS DE ESQUEMA DE LA TAXONOMÍA.\n";
echo "======================================================================\n";
exit(1);
