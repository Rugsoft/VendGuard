<?php

declare(strict_types=1);

/**
 * OpportunisticSchemaCorrespondenceTest · T-OPP-02
 *
 * Certifica **en el motor único** (PostgreSQL) la forma que el Módulo 16 añade al
 * esquema, y sólo eso: los tipos nativos de la avería oportunista, la ampliación
 * del catálogo de plantillas de la bandeja de salida, la coherencia entre
 * modalidad y submotivo, la cadena de reincidencias y la convivencia con el
 * ticket activo único por máquina (Art. V.2).
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Los miembros de los tres tipos enumerados.** `opportunistic_mode` y
 *      `opportunistic_origin_reason` se leen del catálogo vivo (`pg_enum`) y se
 *      comparan con la lista cerrada de la especificación, en su orden. De
 *      `notification_template` se certifica que las dos plantillas del módulo
 *      existen, que son las dos últimas del catálogo y que el catálogo tiene 21
 *      valores: un alta que se olvidara de una plantilla dejaría el indicador en
 *      rojo, y una plantilla añadida sin especificación también.
 *
 *   2. **La matriz de coherencia, no un caso suelto.** Las seis combinaciones
 *      válidas se aceptan y las cinco incoherentes se rechazan con `SQLSTATE
 *      23514` nombrando `chk_incidents_opportunistic_coherence`. Las filas de la
 *      matriz nacen en `CLOSED` a propósito: así la restricción de coherencia se
 *      mide sola, sin que el índice de ticket activo (`uq_machine_active_ticket`)
 *      decida antes que ella.
 *
 *   3. **La integridad de `fk_incidents_chronic_predecessor`.** La clave foránea
 *      existe con las reglas declaradas (`ON DELETE RESTRICT`, `ON UPDATE
 *      CASCADE`, leídas del catálogo y no del fichero `.sql`), rechaza un
 *      predecesor inexistente tanto en el alta como en la actualización, admite
 *      un predecesor real y **impide borrarlo**, incluso dentro de la ventana de
 *      purga del arnés: retirar el crónico dejaría huérfana la cadena de
 *      reincidencias que exige el Art. III.1.
 *
 *   4. **La convivencia con `uq_machine_active_ticket` (Art. V.2).** Sobre una
 *      máquina libre se admite el alta oportunista; sobre la misma máquina con
 *      un expediente vivo —incluido `RESOLVED`, que es la ventana de garantía de
 *      48 h— el alta se rechaza con `SQLSTATE 23505`: la segunda avería de la
 *      misma máquina se **asume o se reabre**, no se duplica. Cerrado el ciclo, el
 *      expediente sucesor se admite y enlaza al crónico.
 *
 * QUÉ NO SE CERTIFICA AQUÍ (declarado, no omitido)
 *
 *   - Las transiciones de estado y la habilitación de sede por ventana de gracia
 *     son dominio puro: viven en `OpportunisticStateTransitionsTest` y en las
 *     políticas de T-OPP-04. Esta suite sólo mide lo que la base de datos impone.
 *   - La forma de la tabla `incidents` ya se certifica en `PostgresSchemaTest` y
 *     `SchemaConvergenceParityTest`; aquí se añaden las cuatro columnas nuevas y
 *     se comprueban sus tipos, sin repetir el inventario completo.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible (el motor de `deploy/local/postgres/`). NO
 *   pertenece a la batería hermética (RNF-04, spec §8.1): se declara con su marca
 *   de categoría, se ejecuta en su canal propio y su informe va aparte. Sin
 *   `PG_TEST_DSN` la suite no se omite en silencio: lo dice y sale con código 2.
 *
 * LIMPIEZA: el escenario vive en un esquema aislado propio y se borra con
 * `CASCADE` al terminar, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/OpportunisticSchemaCorrespondenceTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema propio: el escenario de esta suite no comparte espacio con el de otras certificaciones. */
const OPPORTUNISTIC_SCHEMA = 't_opp16_schema';

/** Miembros cerrados de los dos tipos del módulo, en el orden que declara el DDL. */
const OPPORTUNISTIC_MODE_LABELS = ['NEW', 'ASSUMED'];
const OPPORTUNISTIC_REASON_LABELS = [
    'SITE_VERBAL_NOTICE',
    'TECHNICIAN_DETECTION',
    'EXISTING_TICKET_CLAIM',
    'WARRANTY_REOPEN_IN_SITU',
];

/** Las dos plantillas que estrena el módulo, en el orden con que se anexan al catálogo. */
const OPPORTUNISTIC_NEW_TEMPLATES = [
    'OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT',
    'INCIDENT_REOPENED_IN_SITU_SITE',
];

/** Total esperado del catálogo cerrado tras el módulo (19 del módulo 14 + 2 del módulo 16). */
const NOTIFICATION_TEMPLATE_TOTAL = 21;

/** Combinaciones que la restricción debe admitir. */
const COHERENCE_ACCEPTED = [
    'sin modalidad ni submotivo' => [null, null],
    'nueva por aviso verbal' => ['NEW', 'SITE_VERBAL_NOTICE'],
    'nueva por detección propia' => ['NEW', 'TECHNICIAN_DETECTION'],
    'nueva por reapertura en garantía' => ['NEW', 'WARRANTY_REOPEN_IN_SITU'],
    'asumida de un ticket abierto' => ['ASSUMED', 'EXISTING_TICKET_CLAIM'],
    'asumida por reapertura en garantía' => ['ASSUMED', 'WARRANTY_REOPEN_IN_SITU'],
];

/** Combinaciones que la restricción debe rechazar. */
const COHERENCE_REJECTED = [
    'nueva con asunción de ticket' => ['NEW', 'EXISTING_TICKET_CLAIM'],
    'asumida por aviso verbal' => ['ASSUMED', 'SITE_VERBAL_NOTICE'],
    'asumida por detección propia' => ['ASSUMED', 'TECHNICIAN_DETECTION'],
    'submotivo sin modalidad' => [null, 'SITE_VERBAL_NOTICE'],
    'modalidad sin submotivo' => ['NEW', null],
];

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
echo " VENDGUARD · T-OPP-02 · Correspondencia de esquema del módulo 16\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$enumLabels = static function (PDO $pdo, string $typeName): array {
    $stmt = $pdo->prepare(
        'SELECT e.enumlabel
           FROM pg_enum e
           JOIN pg_type t ON t.oid = e.enumtypid
           JOIN pg_namespace n ON n.oid = t.typnamespace
          WHERE t.typname = :type AND n.nspname = current_schema()
          ORDER BY e.enumsortorder'
    );
    $stmt->execute([':type' => $typeName]);

    return array_map(static fn (array $row): string => (string)$row['enumlabel'], $stmt->fetchAll());
};

$constraintDefinition = static function (PDO $pdo, string $table, string $name): ?string {
    $stmt = $pdo->prepare(
        "SELECT pg_get_constraintdef(con.oid)
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = :table AND con.conname = :name"
    );
    $stmt->execute([':table' => $table, ':name' => $name]);
    $definition = $stmt->fetchColumn();

    return $definition === false || $definition === null ? null : (string)$definition;
};

$indexDefinition = static function (PDO $pdo, string $name): ?string {
    $stmt = $pdo->prepare(
        'SELECT pg_get_indexdef(c.oid)
           FROM pg_class c
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = :name'
    );
    $stmt->execute([':name' => $name]);
    $definition = $stmt->fetchColumn();

    return $definition === false || $definition === null ? null : (string)$definition;
};

/**
 * Alta de un expediente con sus metadatos oportunistas, para la matriz y las sondas.
 * Las columnas van nombradas a propósito: la suite mide estas cuatro, no la forma
 * completa de la tabla.
 */
$insertIncident = static function (
    PDO $pdo,
    string $ticketCode,
    int $machineId,
    int $locationId,
    string $status,
    ?string $mode,
    ?string $reason,
    ?int $predecessorId = null,
    ?string $informantName = null
): int {
    $stmt = $pdo->prepare(
        "INSERT INTO incidents (
            ticket_code, machine_id, location_id, category, description, urgency, status,
            opportunistic_mode, opportunistic_origin_reason, opportunistic_informant_name,
            chronic_predecessor_incident_id
         ) VALUES (
            :ticket_code, :machine_id, :location_id, 'OTHER', :description, 'LOW', :status,
            :mode, :reason, :informant, :predecessor
         ) RETURNING id"
    );
    $stmt->execute([
        ':ticket_code' => $ticketCode,
        ':machine_id' => $machineId,
        ':location_id' => $locationId,
        ':description' => 'Expediente de certificación del esquema oportunista (T-OPP-02).',
        ':status' => $status,
        ':mode' => $mode,
        ':reason' => $reason,
        ':informant' => $informantName,
        ':predecessor' => $predecessorId,
    ]);

    return (int)$stmt->fetchColumn();
};

$rows = [];
$residueCheck = null;
$crash = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio, entero, en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones del motor nuevo) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, OPPORTUNISTIC_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    foreach (['locations', 'users', 'machines'] as $index => $table) {
        $rows[$table] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, $table, $rows, sprintf('OPP%02d', $index + 1));
    }

    $locationId = (int)$rows['locations']['id'];
    $archiveMachineId = (int)PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'machines', $rows, 'OPPA')['id'];
    $activeMachineId = (int)PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'machines', $rows, 'OPPB')['id'];

    $assert(
        'El escenario se levanta con el esquema del repositorio y la migración 005 aplicada',
        in_array('005_opportunistic_incident.sql', $schemaReport['migrations'], true),
        sprintf('%d sentencias · migraciones: %s', $schemaReport['statements'], implode(', ', $schemaReport['migrations']))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Miembros cerrados de los tipos del módulo
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Tipos nativos y catálogo de plantillas ---\n";

    $modeLabels = $enumLabels($pgPdo, 'opportunistic_mode');
    $assert(
        'opportunistic_mode declara exactamente NEW y ASSUMED, en ese orden',
        $modeLabels === OPPORTUNISTIC_MODE_LABELS,
        'miembros: ' . implode('/', $modeLabels)
    );

    $reasonLabels = $enumLabels($pgPdo, 'opportunistic_origin_reason');
    $assert(
        'opportunistic_origin_reason declara exactamente los cuatro submotivos de la especificación',
        $reasonLabels === OPPORTUNISTIC_REASON_LABELS,
        'miembros: ' . implode('/', $reasonLabels)
    );

    $templateLabels = $enumLabels($pgPdo, 'notification_template');
    $missingTemplates = array_values(array_diff(OPPORTUNISTIC_NEW_TEMPLATES, $templateLabels));
    $assert(
        'notification_template incorpora las dos plantillas del módulo 16',
        $missingTemplates === [],
        $missingTemplates === [] ? 'ambas presentes' : 'ausentes: ' . implode(', ', $missingTemplates)
    );

    $assert(
        sprintf('El catálogo cerrado de plantillas queda en %d valores', NOTIFICATION_TEMPLATE_TOTAL),
        count($templateLabels) === NOTIFICATION_TEMPLATE_TOTAL,
        'miembros: ' . count($templateLabels)
    );

    $assert(
        'Las dos plantillas del módulo se anexan al final del catálogo (ampliación, no reordenación)',
        array_slice($templateLabels, -2) === OPPORTUNISTIC_NEW_TEMPLATES,
        'últimas: ' . implode('/', array_slice($templateLabels, -2))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Columnas, restricción, clave foránea e índice declarados
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Columnas e integridad declarada ---\n";

    $columns = PostgresFixtureBuilder::columns($pgPdo, 'incidents');
    $expectedColumns = [
        'opportunistic_mode' => ['udt' => 'opportunistic_mode', 'nullable' => true],
        'opportunistic_origin_reason' => ['udt' => 'opportunistic_origin_reason', 'nullable' => true],
        'opportunistic_informant_name' => ['udt' => 'varchar', 'nullable' => true],
        'chronic_predecessor_incident_id' => ['udt' => 'int8', 'nullable' => true],
    ];

    $columnProblems = [];
    foreach ($expectedColumns as $name => $expected) {
        $declared = $columns[$name] ?? null;
        if ($declared === null) {
            $columnProblems[] = "{$name} no existe";
            continue;
        }
        if ($declared['udt_name'] !== $expected['udt']) {
            $columnProblems[] = "{$name} es {$declared['udt_name']}";
        }
        if ($declared['nullable'] !== $expected['nullable']) {
            $columnProblems[] = "{$name} no es opcional";
        }
    }

    $assert(
        'Las cuatro columnas oportunistas existen con su tipo y son opcionales',
        $columnProblems === [],
        $columnProblems === [] ? '4 columnas verificadas' : implode(' · ', $columnProblems)
    );

    $coherenceDefinition = $constraintDefinition($pgPdo, 'incidents', 'chk_incidents_opportunistic_coherence');
    $assert(
        'chk_incidents_opportunistic_coherence está declarada con los motivos de cada modalidad',
        $coherenceDefinition !== null
            && str_contains($coherenceDefinition, 'NEW')
            && str_contains($coherenceDefinition, 'ASSUMED')
            && str_contains($coherenceDefinition, 'SITE_VERBAL_NOTICE')
            && str_contains($coherenceDefinition, 'EXISTING_TICKET_CLAIM')
            && str_contains($coherenceDefinition, 'WARRANTY_REOPEN_IN_SITU'),
        $coherenceDefinition === null ? 'la restricción no existe' : 'definición leída del catálogo'
    );

    $fkRow = $pgPdo->query(
        "SELECT con.confdeltype AS delete_rule, con.confupdtype AS update_rule
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'incidents'
            AND con.conname = 'fk_incidents_chronic_predecessor'"
    )->fetch();

    $assert(
        'fk_incidents_chronic_predecessor se declara ON DELETE RESTRICT y ON UPDATE CASCADE',
        is_array($fkRow)
            && (string)$fkRow['delete_rule'] === 'r'
            && (string)$fkRow['update_rule'] === 'c',
        is_array($fkRow)
            ? 'delete=' . $fkRow['delete_rule'] . ' · update=' . $fkRow['update_rule']
            : 'la clave foránea no existe'
    );

    $opportunisticIndex = $indexDefinition($pgPdo, 'idx_incidents_opportunistic_mode');
    $assert(
        'idx_incidents_opportunistic_mode es parcial y excluye lo borrado lógicamente',
        $opportunisticIndex !== null
            && str_contains($opportunisticIndex, 'opportunistic_mode')
            && str_contains($opportunisticIndex, 'deleted_at IS NULL')
            && str_contains($opportunisticIndex, 'opportunistic_mode IS NOT NULL'),
        $opportunisticIndex === null ? 'el índice no existe' : mb_substr($opportunisticIndex, 0, 140)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Matriz de coherencia modalidad ↔ submotivo
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Coherencia modalidad ↔ submotivo ---\n";

    $sequence = 0;
    foreach (COHERENCE_ACCEPTED as $case => [$mode, $reason]) {
        $sequence++;
        $rejection = PostgresFixtureBuilder::expectRejection(
            static function () use ($insertIncident, $pgPdo, $sequence, $archiveMachineId, $locationId, $mode, $reason): void {
                $insertIncident(
                    $pgPdo,
                    sprintf('T-OPP16-OK-%02d', $sequence),
                    $archiveMachineId,
                    $locationId,
                    'CLOSED',
                    $mode,
                    $reason
                );
            }
        );

        $assert(
            "Se admite la combinación coherente «{$case}»",
            $rejection === null,
            $rejection === null ? 'admitida' : 'rechazada con ' . $rejection['state'] . ' · ' . $rejection['message']
        );
    }

    foreach (COHERENCE_REJECTED as $case => [$mode, $reason]) {
        $sequence++;
        $rejection = PostgresFixtureBuilder::expectRejection(
            static function () use ($insertIncident, $pgPdo, $sequence, $archiveMachineId, $locationId, $mode, $reason): void {
                $insertIncident(
                    $pgPdo,
                    sprintf('T-OPP16-KO-%02d', $sequence),
                    $archiveMachineId,
                    $locationId,
                    'CLOSED',
                    $mode,
                    $reason
                );
            }
        );

        $assert(
            "Se rechaza la combinación incoherente «{$case}» con SQLSTATE 23514",
            $rejection !== null
                && $rejection['state'] === '23514'
                && str_contains($rejection['message'], 'chk_incidents_opportunistic_coherence'),
            $rejection === null ? 'la fila se admitió' : 'estado=' . $rejection['state']
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Cadena de reincidencias: la clave foránea autorreferencial
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cadena de averías crónicas (fk_incidents_chronic_predecessor) ---\n";

    $chronicId = $insertIncident(
        $pgPdo,
        'T-OPP16-CRONICO',
        $archiveMachineId,
        $locationId,
        'CLOSED',
        'NEW',
        'TECHNICIAN_DETECTION'
    );

    $successorId = $insertIncident(
        $pgPdo,
        'T-OPP16-SUCESOR',
        $archiveMachineId,
        $locationId,
        'CLOSED',
        'NEW',
        'WARRANTY_REOPEN_IN_SITU',
        $chronicId
    );

    $linkedPredecessor = (int)$pgPdo->query(
        'SELECT chronic_predecessor_incident_id FROM incidents WHERE id = ' . $successorId
    )->fetchColumn();

    $assert(
        'Un expediente sucesor puede enlazar con su crónico predecesor',
        $successorId > 0 && $linkedPredecessor === $chronicId,
        sprintf('sucesor=%d · predecesor leído=%d', $successorId, $linkedPredecessor)
    );

    $danglingInsert = PostgresFixtureBuilder::expectRejection(
        static function () use ($insertIncident, $pgPdo, $archiveMachineId, $locationId): void {
            $insertIncident(
                $pgPdo,
                'T-OPP16-HUERFANO',
                $archiveMachineId,
                $locationId,
                'CLOSED',
                'NEW',
                'WARRANTY_REOPEN_IN_SITU',
                2147483000
            );
        }
    );

    $assert(
        'La clave foránea rechaza un predecesor inexistente en el alta (SQLSTATE 23503)',
        $danglingInsert !== null
            && $danglingInsert['state'] === '23503'
            && str_contains($danglingInsert['message'], 'fk_incidents_chronic_predecessor'),
        $danglingInsert === null ? 'la fila huérfana se admitió' : 'estado=' . $danglingInsert['state']
    );

    $danglingUpdate = PostgresFixtureBuilder::expectRejection(
        static function () use ($pgPdo, $successorId): void {
            $stmt = $pgPdo->prepare('UPDATE incidents SET chronic_predecessor_incident_id = :id WHERE id = :row');
            $stmt->execute([':id' => 2147483000, ':row' => $successorId]);
        }
    );

    $assert(
        'La clave foránea rechaza un predecesor inexistente en la actualización (SQLSTATE 23503)',
        $danglingUpdate !== null && $danglingUpdate['state'] === '23503',
        $danglingUpdate === null ? 'la actualización se admitió' : 'estado=' . $danglingUpdate['state']
    );

    // La ventana de purga del arnés desactiva el disparador constitucional: si el borrado sigue
    // rechazándose, el rechazo es de la clave foránea y no del Art. III.1, que es lo que se mide.
    $predecessorDelete = TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo, $chronicId): ?array {
        return PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $chronicId): void {
            $stmt = $pgPdo->prepare('DELETE FROM incidents WHERE id = :id');
            $stmt->execute([':id' => $chronicId]);
        });
    });

    $assert(
        'Con la ventana de purga abierta, la clave foránea impide borrar el crónico predecesor (SQLSTATE 23503)',
        $predecessorDelete !== null
            && $predecessorDelete['state'] === '23503'
            && str_contains($predecessorDelete['message'], 'fk_incidents_chronic_predecessor'),
        $predecessorDelete === null ? 'el predecesor se borró: la cadena quedó rota' : 'estado=' . $predecessorDelete['state']
    );

    $survivingPredecessor = (int)$pgPdo->query(
        'SELECT count(*) FROM incidents WHERE id = ' . $chronicId
    )->fetchColumn();

    $assert(
        'El expediente crónico sigue existiendo tras el intento de borrado (Art. III.1 y Art. V.2)',
        $survivingPredecessor === 1
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Convivencia con el ticket activo único por máquina (Art. V.2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Convivencia con uq_machine_active_ticket ---\n";

    $activeId = $insertIncident(
        $pgPdo,
        'T-OPP16-ACTIVO',
        $activeMachineId,
        $locationId,
        'IN_PROGRESS',
        'NEW',
        'SITE_VERBAL_NOTICE',
        null,
        'Marta Gómez (Conserjería)'
    );

    $assert(
        'El alta oportunista se admite en una máquina libre y nace en curso',
        $activeId > 0
            && (string)$pgPdo->query('SELECT status FROM incidents WHERE id = ' . $activeId)->fetchColumn() === 'IN_PROGRESS',
        'id=' . $activeId
    );

    $duplicateNew = PostgresFixtureBuilder::expectRejection(
        static function () use ($insertIncident, $pgPdo, $activeMachineId, $locationId): void {
            $insertIncident(
                $pgPdo,
                'T-OPP16-DUPLICADO',
                $activeMachineId,
                $locationId,
                'IN_PROGRESS',
                'NEW',
                'TECHNICIAN_DETECTION'
            );
        }
    );

    $assert(
        'Una segunda avería oportunista sobre la misma máquina se rechaza con SQLSTATE 23505: se asume, no se duplica',
        $duplicateNew !== null
            && $duplicateNew['state'] === '23505'
            && str_contains($duplicateNew['message'], 'uq_machine_active_ticket'),
        $duplicateNew === null ? 'el duplicado se admitió' : 'estado=' . $duplicateNew['state']
    );

    $pgPdo->prepare("UPDATE incidents SET status = 'RESOLVED', resolved_at = CURRENT_TIMESTAMP WHERE id = :id")
        ->execute([':id' => $activeId]);

    $duplicateResolved = PostgresFixtureBuilder::expectRejection(
        static function () use ($insertIncident, $pgPdo, $activeMachineId, $locationId): void {
            $insertIncident(
                $pgPdo,
                'T-OPP16-GARANTIA',
                $activeMachineId,
                $locationId,
                'IN_PROGRESS',
                'ASSUMED',
                'WARRANTY_REOPEN_IN_SITU'
            );
        }
    );

    $assert(
        'En la ventana de garantía de 48 h la máquina sigue ocupada: la reapertura in situ reutiliza el expediente',
        $duplicateResolved !== null
            && $duplicateResolved['state'] === '23505'
            && str_contains($duplicateResolved['message'], 'uq_machine_active_ticket'),
        $duplicateResolved === null ? 'se admitió un segundo expediente en garantía' : 'estado=' . $duplicateResolved['state']
    );

    $pgPdo->prepare("UPDATE incidents SET status = 'CLOSED', closed_at = CURRENT_TIMESTAMP WHERE id = :id")
        ->execute([':id' => $activeId]);

    $successorOnClosedMachine = $insertIncident(
        $pgPdo,
        'T-OPP16-SUCESOR-ACTIVO',
        $activeMachineId,
        $locationId,
        'IN_PROGRESS',
        'NEW',
        'WARRANTY_REOPEN_IN_SITU',
        $activeId
    );

    $assert(
        'Cerrado el ciclo, el expediente sucesor se admite y enlaza al crónico predecesor',
        $successorOnClosedMachine > 0
            && (int)$pgPdo->query('SELECT chronic_predecessor_incident_id FROM incidents WHERE id = ' . $successorOnClosedMachine)->fetchColumn() === $activeId,
        'sucesor=' . $successorOnClosedMachine
    );

    $informantOnSite = $pgPdo->query(
        'SELECT opportunistic_informant_name FROM incidents WHERE id = ' . $successorOnClosedMachine
    )->fetchColumn();

    $assert(
        'El informante de sede se persiste sólo en el expediente que lo declaró',
        $informantOnSite === null
            && (string)$pgPdo->query('SELECT opportunistic_informant_name FROM incidents WHERE id = ' . $activeId)->fetchColumn() === 'Marta Gómez (Conserjería)'
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, OPPORTUNISTIC_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    // `to_regclass` devuelve NULL cuando la relación no existe: la sonda de residuo no depende
    // del texto exacto del mensaje del motor.
    $residueCheck = $pgPdo->query("SELECT to_regclass('" . OPPORTUNISTIC_SCHEMA . ".incidents')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado y sus tablas ya no existen en el motor',
    $residueCheck === null,
    'la sonda de residuo encontró la tabla en ' . json_encode($residueCheck)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. T-OPP-02 (correspondencia de esquema del módulo 16) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN LA CORRESPONDENCIA DE ESQUEMA DEL MÓDULO 16.\n";
echo "======================================================================\n";
exit(1);
