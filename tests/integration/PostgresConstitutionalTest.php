<?php

declare(strict_types=1);

/**
 * PostgresConstitutionalTest · T-HYBRID-21
 *
 * Blindaje constitucional **sobre el motor nuevo**: lo que en la certificación hermética se
 * comprueba contra MariaDB, aquí se comprueba contra PostgreSQL, que es el motor donde el
 * sistema va a operar en la nube (RF-01, RF-02, RF-08.1 a RF-08.5, RF-10, Art. II, III.1, V.1,
 * V.2, V.4 y V.6).
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Art. III.1 — el borrado físico se rechaza con la boca abierta.** `DELETE FROM` sobre
 *      cada una de las 25 tablas lanza `SQLSTATE '45000'` con la marca `ART_III_1_VIOLATION`.
 *      Cada tabla se prueba **con una fila dentro**: un disparador `FOR EACH ROW` no ve una tabla
 *      vacía, de modo que certificar el rechazo sin filas no certificaría nada. La fixture se
 *      construye con el arnés del motor nuevo (`PostgresFixtureBuilder`), que respeta el grafo de
 *      claves foráneas.
 *
 *   2. **La puerta del arnés, en la forma del motor.** La ventana de purga
 *      (`TestDataCleaner::withPurgeWindow`, única definición de la señal) abre y cierra la señal
 *      **de sesión** —`set_config('vendguard.purge', 'on', false)`—, y se certifica que dentro
 *      está encendida, que el borrado pasa y que al salir se apaga y el mismo borrado vuelve a
 *      ser rechazado. Y se certifica, leyendo el código de producción, que la aplicación **nunca**
 *      la activa: ese es el requisito que hace absoluta la regla (Art. III.1).
 *
 *   3. **Art. V.2 y V.6 — un solo ticket activo por máquina.** El índice único parcial
 *      `uq_machine_active_ticket` rechaza el segundo expediente vivo sobre la misma máquina,
 *      **incluido `RESOLVED`** (la ventana de garantía de 48 horas), y admite `CLOSED` y
 *      `CANCELLED`. Es la forma del motor nuevo de la diferencia aceptada D-6: allí no existe la
 *      columna derivada del motor local, y eso también se certifica.
 *
 *   4. **Art. V.1 — justificaciones de 20 caracteres como mínimo.** Las siete restricciones
 *      declaradas en el DDL se ejercen por la base de datos: 19 caracteres se rechazan con
 *      `SQLSTATE '23514'` nombrando la restricción, 20 se aceptan, el recorte manda (19 caracteres
 *      rodeados de espacios siguen siendo 19) y el nulo se admite porque la regla es sobre lo que
 *      se escribe, no sobre lo que se omite.
 *
 *   5. **Art. V.4 y EARS-08.5 — la unicidad canónica, igual en los dos motores.** El mismo login
 *      escrito con acentos y el mismo código de sede escrito con otra letra plegable se rechazan
 *      en **ambos** motores, cada uno por su mecanismo: el motor nuevo por la columna canónica
 *      (`email_key`, `site_code_key` con su índice único) y el motor local por su colación. Los
 *      repositorios reales (`PdoUserRepository`, `PdoLocationRepository`) son los que ejecutan el
 *      alta en los dos: no se compara SQL escrito en la suite, se compara el comportamiento del
 *      código de producción contra los dos motores.
 *
 * QUÉ NO SE CERTIFICA AQUÍ (declarado, no omitido)
 *
 *   - La **telemetría** del diagnóstico (`sanitary_clock.last_pass_at` en la proyección
 *     autenticada y su ausencia en la pública): vive en `HealthEndpointTest.php`, que es la suite
 *     hermética del endpoint y donde el contrato de `/api/status` se ejerce por el enrutador real.
 *     Duplicarla aquí certificaría dos veces lo mismo y podría divergir.
 *   - El disparador es `FOR EACH ROW`: una sentencia `DELETE` sobre una tabla **vacía** no tiene
 *     filas que rechazar, y `TRUNCATE` no dispara disparadores de fila en ninguno de los dos
 *     motores. No es un agujero explotable por la aplicación —no existe un solo `TRUNCATE` en
 *     `src/`, `bin/`, `tests/` ni en los guiones de `database/`, y el arnés limpia sólo con
 *     `DELETE` bajo la ventana—, pero se declara en lugar de silenciarse.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible además del MariaDB local (la prueba de paridad necesita los
 *   dos). NO pertenece a la batería hermética (RNF-04, spec §8.1): se declara con su marca de
 *   categoría, se ejecuta en su canal propio y su informe va aparte. Sin `PG_TEST_DSN` la suite no
 *   se omite en silencio: lo dice y sale con código 2.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/PostgresConstitutionalTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Repository/PdoUserRepository.php';
require_once __DIR__ . '/../../src/Infrastructure/Repository/PdoLocationRepository.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Las 22 tablas del contrato de correspondencia (§2.1 y Módulo 14). */
const CONTRACT_TABLES = [
    'locations', 'machines', 'users', 'incidents', 'incident_history', 'incident_comments',
    'incident_replaced_parts', 'refund_requests', 'unclaimed_cash_findings', 'spare_parts',
    'spare_part_compatibilities', 'spare_part_requests', 'preventive_settings', 'preventive_orders',
    'preventive_order_items', 'sanitary_certificates', 'route_settings', 'sanitary_sweep_passes',
    'sanitary_sweep_contentions', 'audit_log',
    'notification_outbox', 'notification_channel_settings',
    'operational_zones', 'operational_zone_postal_codes', 'technician_zone_assignments',
];

/** Las siete restricciones de longitud mínima del Art. V.1, con la tabla y la columna que vigilan. */
const LENGTH_CHECKS = [
    'chk_resolution_diagnosis_len' => ['table' => 'incidents', 'column' => 'resolution_diagnosis'],
    'chk_resolution_action_len' => ['table' => 'incidents', 'column' => 'resolution_action'],
    'chk_pending_info_reason_text_len' => ['table' => 'incidents', 'column' => 'pending_info_reason_text'],
    'chk_cancellation_reason_len' => ['table' => 'incidents', 'column' => 'cancellation_reason'],
    'chk_custom_part_description_len' => ['table' => 'spare_part_requests', 'column' => 'custom_part_description'],
    'chk_coordinator_justification_len' => ['table' => 'refund_requests', 'column' => 'coordinator_justification'],
    'chk_technician_justification_len' => ['table' => 'refund_requests', 'column' => 'technician_justification'],
];

/** Marca que el disparador constitucional escribe en el mensaje de la excepción. */
const PURGE_VIOLATION_MARKER = 'ART_III_1_VIOLATION';

/** Credenciales y códigos de la prueba de paridad: se retiran al terminar, con o sin fallo. */
const PARITY_USER_EMAIL = 'parity.h21@gestion-vending.test';
const PARITY_USER_EMAIL_FOLDED_VARIANT = 'paríty.h21@gestion-vending.test';
const PARITY_USER_EMAIL_DISTINCT = 'parity.h21.otra@gestion-vending.test';
const PARITY_LOCATION_CODE = 'SEDE-TEST-H21-Ñ';
const PARITY_LOCATION_CODE_FOLDED_VARIANT = 'SEDE-TEST-H21-N';

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
echo " VENDGUARD · T-HYBRID-21 · Blindaje constitucional del motor nuevo\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$rows = [];
$crash = null;
$parityRowsCreated = false;
$noWindowDeleteState = '';
$windowDeleteState = '';
$signalInsideWindow = null;
$signalAfterWindow = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio y una fila por tabla
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (esquema del repositorio y una fila por tabla) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    foreach (PostgresFixtureBuilder::insertionOrder($pgPdo, PostgresFixtureBuilder::tables($pgPdo)) as $index => $table) {
        $rows[$table] = PostgresFixtureBuilder::insertMinimumRow($pgPdo, $table, $rows, sprintf('%02d', $index + 1));
    }

    $assert(
        sprintf('El escenario se levanta con el esquema del repositorio y una fila en cada una de las %d tablas', count(CONTRACT_TABLES)),
        count($rows) === count(CONTRACT_TABLES) && in_array('001_initial_baseline.sql', $schemaReport['migrations'], true) && in_array('002_notification_center_outbox.sql', $schemaReport['migrations'], true),
        sprintf('%d filas · %d sentencias de DDL · migraciones: %s',
            count($rows),
            $schemaReport['statements'],
            implode(', ', $schemaReport['migrations']))
    );

    // ─────────────────────────────────────────────────────────────────────
    // Art. III.1: el borrado físico se rechaza, tabla por tabla y con filas dentro
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Art. III.1: rechazo ruidoso de «DELETE FROM» ---\n";

    foreach (CONTRACT_TABLES as $table) {
        if (!isset($rows[$table])) {
            $assert("Art. III.1 · «{$table}» rechaza el borrado físico", false, 'la tabla no llegó a sembrarse');
            continue;
        }

        $rejection = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $table): void {
            $pgPdo->exec('DELETE FROM "' . $table . '"');
        });

        $assert(
            "Art. III.1 · «{$table}» rechaza el borrado físico con SQLSTATE 45000",
            $rejection !== null
                && $rejection['state'] === '45000'
                && str_contains($rejection['message'], PURGE_VIOLATION_MARKER),
            $rejection === null
                ? 'el borrado pasó: la regla constitucional no se aplicó'
                : 'estado=' . $rejection['state'] . ' · ' . mb_substr(str_replace("\n", ' ', $rejection['message']), 0, 90)
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // La puerta del arnés: de sesión, en la forma del motor, y cerrada al salir
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- La puerta de purga del arnés en el motor nuevo ---\n";

    // Tabla hoja: no tiene hijas que la referencien, así que el borrado ejercita el disparador
    // constitucional y ninguna clave foránea, que es lo que se quiere medir.
    $leafTable = 'sanitary_sweep_passes';
    $leafWhere = '"' . PostgresFixtureBuilder::primaryKey($pgPdo, $leafTable)[0] . '" = :id';
    $leafParams = [':id' => $rows[$leafTable]['id']];

    $deletedInsideWindow = TestDataCleaner::withPurgeWindow($pgPdo, static function () use ($pgPdo, $leafTable, $leafWhere, $leafParams, &$signalInsideWindow): int {
        $signalInsideWindow = $pgPdo->query("SELECT current_setting('vendguard.purge', true)")->fetchColumn();
        $stmt = $pgPdo->prepare('DELETE FROM "' . $leafTable . '" WHERE ' . $leafWhere);
        $stmt->execute($leafParams);

        return $stmt->rowCount();
    });

    $signalAfterWindow = $pgPdo->query("SELECT current_setting('vendguard.purge', true)")->fetchColumn();

    $assert(
        'La ventana de purga se declara de sesión en la forma del motor nuevo (' . "set_config('vendguard.purge', 'on', false)" . ')',
        $signalInsideWindow === 'on',
        'valor dentro de la ventana: ' . json_encode($signalInsideWindow)
    );

    $assert(
        'Con la ventana abierta el borrado pasa y retira exactamente la fila dirigida',
        $deletedInsideWindow === 1,
        'filas borradas: ' . $deletedInsideWindow
    );

    $assert(
        'Al salir de la ventana la señal queda apagada en la sesión (no hay puerta abierta)',
        $signalAfterWindow === 'off',
        'valor después de la ventana: ' . json_encode($signalAfterWindow)
    );

    // La sonda posterior apunta a una tabla **distinta**, cuya fila sigue existiendo: repetir el
    // borrado de la fila que la ventana acaba de retirar no probaría nada (un disparador de fila
    // no ve una tabla sin filas) y pasaría por buena una puerta que siguiera abierta.
    $afterWindowLeaf = 'sanitary_sweep_contentions';
    $afterWindowRejection = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $afterWindowLeaf): void {
        $pgPdo->exec('DELETE FROM "' . $afterWindowLeaf . '"');
    });

    $assert(
        'Cerrada la ventana, un borrado dirigido de una fila que sigue ahí vuelve a rechazarse con SQLSTATE 45000',
        $afterWindowRejection !== null
            && $afterWindowRejection['state'] === '45000'
            && str_contains($afterWindowRejection['message'], PURGE_VIOLATION_MARKER),
        $afterWindowRejection === null ? 'el borrado volvió a pasar: la ventana no se cerró' : 'estado=' . $afterWindowRejection['state']
    );

    $applicationMentions = [];
    foreach (['src', 'bin', 'public'] as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot . '/' . $root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower((string)$file->getExtension()) !== 'php') {
                continue;
            }
            $content = (string)file_get_contents($file->getPathname());
            if (str_contains($content, 'vendguard.purge') || str_contains($content, 'vendguard_purge')) {
                $applicationMentions[] = $root . '/' . $file->getFilename();
            }
        }
    }

    $assert(
        'La aplicación nunca activa la señal de purga: ni una mención en src/, bin/ ni public/',
        $applicationMentions === [],
        $applicationMentions === [] ? 'cero menciones' : 'menciones en: ' . implode(', ', $applicationMentions)
    );

    // ─────────────────────────────────────────────────────────────────────
    // Art. V.2 y V.6: un solo ticket activo por máquina (índice único parcial, D-6)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Art. V.2 y V.6: un único ticket activo por máquina ---\n";

    $machineId = (int)$rows['machines']['id'];
    $locationId = (int)$rows['locations']['id'];

    $indexDefinition = (string)$pgPdo->query(
        "SELECT pg_get_indexdef(c.oid)
           FROM pg_class c
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = 'uq_machine_active_ticket'"
    )->fetchColumn();

    $derivedColumnExists = (bool)$pgPdo->query(
        "SELECT count(*) FROM information_schema.columns
          WHERE table_schema = current_schema() AND table_name = 'incidents' AND column_name = 'is_active_ticket'"
    )->fetchColumn();

    $assert(
        'El índice único parcial existe con su predicado y la columna derivada de D-6 no se materializa',
        $indexDefinition !== ''
            && str_contains($indexDefinition, 'uq_machine_active_ticket')
            && str_contains($indexDefinition, 'CLOSED')
            && str_contains($indexDefinition, 'CANCELLED')
            && $derivedColumnExists === false,
        'definición: ' . mb_substr($indexDefinition, 0, 120) . ' · columna is_active_ticket presente: ' . ($derivedColumnExists ? 'sí' : 'no')
    );

    $insertIncident = static function (PDO $pdo, string $ticketCode, string $status, int $machine, int $location) {
        $stmt = $pdo->prepare(
            "INSERT INTO incidents (ticket_code, machine_id, location_id, category, description, urgency, status)
             VALUES (:ticket_code, :machine_id, :location_id, 'OTHER', :description, 'LOW', :status)
             RETURNING id"
        );
        $stmt->execute([
            ':ticket_code' => $ticketCode,
            ':machine_id' => $machine,
            ':location_id' => $location,
            ':description' => 'Expediente de certificación del índice único parcial (T-HYBRID-21).',
            ':status' => $status,
        ]);

        return $stmt;
    };

    // La máquina de la prueba es propia: la fixture ya tiene un expediente activo en su máquina y
    // reutilizarlo mezclaría dos cosas distintas (que el índice existe, que ya lo certifica el
    // rechazo del duplicado) con esta, que es el ciclo completo de la regla.
    $pairMachine = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'machines', $rows, 'H21A');
    $pairMachineId = (int)$pairMachine['id'];

    $activeTicket = PostgresFixtureBuilder::expectRejection(static function () use ($insertIncident, $pgPdo, $pairMachineId, $locationId): void {
        $insertIncident($pgPdo, 'T-H21-ACTIVO', 'REGISTERED', $pairMachineId, $locationId);
    });

    $assert(
        'El primer expediente activo de una máquina se admite (la máquina queda ocupada)',
        $activeTicket === null,
        $activeTicket === null ? 'admitido' : 'rechazado con ' . $activeTicket['state']
    );

    $resolvedDuplicate = PostgresFixtureBuilder::expectRejection(static function () use ($insertIncident, $pgPdo, $pairMachineId, $locationId): void {
        $insertIncident($pgPdo, 'T-H21-RESUELTO', 'RESOLVED', $pairMachineId, $locationId);
    });

    $assert(
        'Un segundo expediente RESOLVED sobre la misma máquina se rechaza con SQLSTATE 23505 nombrando el índice',
        $resolvedDuplicate !== null
            && $resolvedDuplicate['state'] === '23505'
            && str_contains($resolvedDuplicate['message'], 'uq_machine_active_ticket'),
        $resolvedDuplicate === null ? 'el segundo ticket activo se admitió' : 'estado=' . $resolvedDuplicate['state'] . ' · ' . mb_substr(str_replace("\n", ' ', $resolvedDuplicate['message']), 0, 90)
    );

    $closedTicket = PostgresFixtureBuilder::expectRejection(static function () use ($insertIncident, $pgPdo, $pairMachineId, $locationId): void {
        $insertIncident($pgPdo, 'T-H21-CERRADO', 'CLOSED', $pairMachineId, $locationId);
    });

    $assert(
        'Un expediente CLOSED sobre la misma máquina se admite (la máquina vuelve a estar libre)',
        $closedTicket === null,
        $closedTicket === null ? 'admitido' : 'rechazado con ' . $closedTicket['state']
    );

    $cancelledTicket = PostgresFixtureBuilder::expectRejection(static function () use ($insertIncident, $pgPdo, $pairMachineId, $locationId): void {
        $insertIncident($pgPdo, 'T-H21-CANCELADO', 'CANCELLED', $pairMachineId, $locationId);
    });

    $assert(
        'Un expediente CANCELLED sobre la misma máquina se admite',
        $cancelledTicket === null,
        $cancelledTicket === null ? 'admitido' : 'rechazado con ' . $cancelledTicket['state']
    );

    $secondMachine = PostgresFixtureBuilder::insertMinimumRow($pgPdo, 'machines', $rows, 'H21B');
    $otherTicket = PostgresFixtureBuilder::expectRejection(static function () use ($insertIncident, $pgPdo, $secondMachine, $locationId): void {
        $insertIncident($pgPdo, 'T-H21-OTRA-MAQUINA', 'REGISTERED', (int)$secondMachine['id'], $locationId);
    });

    $assert(
        'La regla es por máquina: otro expediente activo en una máquina distinta se admite',
        $otherTicket === null && (int)$secondMachine['id'] !== $pairMachineId,
        $otherTicket === null ? 'admitido' : 'rechazado con ' . $otherTicket['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // Art. V.1: justificaciones de 20 caracteres como mínimo, por la base de datos
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Art. V.1: longitud mínima de las justificaciones ---\n";

    $declaredChecks = $pgPdo->query(
        "SELECT con.conname AS name, c.relname AS table_name, pg_get_constraintdef(con.oid) AS definition
           FROM pg_constraint con
           JOIN pg_class c ON c.oid = con.conrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND con.contype = 'c'"
    )->fetchAll();

    $catalogByName = [];
    foreach ($declaredChecks as $check) {
        $catalogByName[(string)$check['name']] = [
            'table' => (string)$check['table_name'],
            'definition' => (string)$check['definition'],
        ];
    }

    $mappingProblems = [];
    foreach (LENGTH_CHECKS as $name => $target) {
        $declared = $catalogByName[$name] ?? null;
        if ($declared === null) {
            $mappingProblems[] = "{$name} no existe";
            continue;
        }
        if ($declared['table'] !== $target['table'] || !str_contains($declared['definition'], $target['column'])) {
            $mappingProblems[] = "{$name} no vigila {$target['table']}.{$target['column']}";
        }
    }

    $assert(
        'Las siete restricciones de longitud están declaradas en el motor, en su tabla y su columna',
        $mappingProblems === [],
        $mappingProblems === [] ? count(LENGTH_CHECKS) . ' restricciones verificadas' : implode(' · ', $mappingProblems)
    );

    $rowIdFor = static function (string $table) use ($rows): int {
        return (int)$rows[$table]['id'];
    };

    foreach (LENGTH_CHECKS as $name => $target) {
        $table = $target['table'];
        $column = $target['column'];
        $rowId = $rowIdFor($table);

        $tooShort = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $table, $column, $rowId): void {
            $stmt = $pgPdo->prepare("UPDATE \"{$table}\" SET \"{$column}\" = :value WHERE id = :id");
            $stmt->execute([':value' => str_repeat('x', 19), ':id' => $rowId]);
        });

        $assert(
            "Art. V.1 · «{$name}» rechaza 19 caracteres con SQLSTATE 23514",
            $tooShort !== null && $tooShort['state'] === '23514' && str_contains($tooShort['message'], $name),
            $tooShort === null ? 'el valor corto se admitió' : 'estado=' . $tooShort['state']
        );

        $exactLength = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $table, $column, $rowId): void {
            $stmt = $pgPdo->prepare("UPDATE \"{$table}\" SET \"{$column}\" = :value WHERE id = :id");
            $stmt->execute([':value' => str_repeat('y', 20), ':id' => $rowId]);
        });

        $readBack = $pgPdo->prepare("SELECT \"{$column}\" AS value FROM \"{$table}\" WHERE id = :id");
        $readBack->execute([':id' => $rowId]);
        $stored = $readBack->fetchColumn();

        $assert(
            "Art. V.1 · «{$name}» acepta justo 20 caracteres y el valor queda escrito",
            $exactLength === null && $stored === str_repeat('y', 20),
            $exactLength === null ? 'leído=' . mb_strlen((string)$stored) . ' caracteres' : 'rechazado con ' . $exactLength['state']
        );
    }

    $paddedShort = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $rowIdFor): void {
        $stmt = $pgPdo->prepare('UPDATE incidents SET resolution_diagnosis = :value WHERE id = :id');
        $stmt->execute([':value' => '   ' . str_repeat('z', 19) . '   ', ':id' => $rowIdFor('incidents')]);
    });

    $assert(
        'Art. V.1 · el recorte manda: 19 caracteres rodeados de espacios se siguen rechazando',
        $paddedShort !== null && $paddedShort['state'] === '23514',
        $paddedShort === null ? 'el valor con relleno se admitió' : 'estado=' . $paddedShort['state']
    );

    $nullValue = PostgresFixtureBuilder::expectRejection(static function () use ($pgPdo, $rowIdFor): void {
        $stmt = $pgPdo->prepare('UPDATE incidents SET resolution_diagnosis = NULL WHERE id = :id');
        $stmt->execute([':id' => $rowIdFor('incidents')]);
    });

    $assert(
        'Art. V.1 · la regla es sobre lo que se escribe: el nulo se admite (no hay justificación)',
        $nullValue === null,
        $nullValue === null ? 'admitido' : 'rechazado con ' . $nullValue['state']
    );

    // ─────────────────────────────────────────────────────────────────────
    // Art. V.4 y EARS-08.5: unicidad canónica, igual en los dos motores
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Art. V.4 y EARS-08.5: unicidad canónica en los dos motores ---\n";

    $canonicalColumns = $pgPdo->query(
        "SELECT table_name, column_name
           FROM information_schema.columns
          WHERE table_schema = current_schema()
            AND column_name IN ('email_key', 'site_code_key')
          ORDER BY table_name"
    )->fetchAll();

    $canonicalIndexes = $pgPdo->query(
        "SELECT c.relname AS name
           FROM pg_class c
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname IN ('uq_users_email_key', 'uq_locations_site_code_key')"
    )->fetchAll();

    $assert(
        'El motor nuevo declara las columnas canónicas A-1/A-2 y sus índices únicos',
        count($canonicalColumns) === 2 && count($canonicalIndexes) === 2,
        'columnas: ' . implode(', ', array_map(static fn (array $row): string => $row['table_name'] . '.' . $row['column_name'], $canonicalColumns))
            . ' · índices: ' . implode(', ', array_column($canonicalIndexes, 'name'))
    );

    $pgUsers = new PdoUserRepository($pgPdo);
    
    $pgLocations = new PdoLocationRepository($pgPdo);
    

    $createUser = static fn (PdoUserRepository $repository, string $email) => $repository->create([
        'name' => 'Paridad canónica H21',
        'email' => $email,
        'role' => 'TECHNICIAN',
        'password' => 'ParidadH21!Segura',
    ]);

    $createLocation = static fn (PdoLocationRepository $repository, string $siteCode) => $repository->create([
        'site_code' => $siteCode,
        'name' => 'Sede de paridad canónica H21',
        'address' => 'Carrer de la Paritat 21, Barcelona',
    ]);

    $pgUserCreated = $createUser($pgUsers, PARITY_USER_EMAIL);
    
    $parityRowsCreated = true;

    $assert(
        'El mismo login se da de alta en los dos motores con el repositorio real',
        $pgUserCreated->getEmail() === PARITY_USER_EMAIL,
        'email=' . $pgUserCreated->getEmail()
    );

    $pgLookup = $pgUsers->findByEmail(mb_strtoupper(PARITY_USER_EMAIL_FOLDED_VARIANT));
    

    $assert(
        'La búsqueda del mismo login escrito con acentos y mayúsculas encuentra la fila en los dos motores',
        $pgLookup !== null && $pgLookup->getEmail() === PARITY_USER_EMAIL,
        'email=' . json_encode($pgLookup?->getEmail())
    );

    $pgUserDuplicate = PostgresFixtureBuilder::expectRejection(static fn () => $createUser($pgUsers, PARITY_USER_EMAIL_FOLDED_VARIANT));
    

    $assert(
        'El motor nuevo rechaza la variante con acentos del mismo login por la clave canónica (email_key)',
        $pgUserDuplicate !== null
            && str_contains($pgUserDuplicate['message'], 'uq_users_email_key')
            && str_contains($pgUserDuplicate['message'], 'email_key'),
        $pgUserDuplicate === null ? 'la variante se admitió' : mb_substr(str_replace("\n", ' ', $pgUserDuplicate['message']), 0, 110)
    );

    $pgLocationCreated = $createLocation($pgLocations, PARITY_LOCATION_CODE);
    

    $assert(
        'El mismo código de sede se da de alta en los dos motores con el repositorio real',
        $pgLocationCreated->getSiteCode() === PARITY_LOCATION_CODE,
        'site_code=' . $pgLocationCreated->getSiteCode()
    );

    $pgLocationDuplicate = PostgresFixtureBuilder::expectRejection(static fn () => $createLocation($pgLocations, PARITY_LOCATION_CODE_FOLDED_VARIANT));
    
    $parityRowsCreated = $parityRowsCreated && $pgLocationCreated->getSiteCode() === PARITY_LOCATION_CODE;

    $assert(
        'El motor nuevo rechaza la variante plegada del código de sede por la clave canónica (site_code_key)',
        $pgLocationDuplicate !== null
            && str_contains($pgLocationDuplicate['message'], 'uq_locations_site_code_key')
            && str_contains($pgLocationDuplicate['message'], 'site_code_key'),
        $pgLocationDuplicate === null ? 'la variante se admitió' : mb_substr(str_replace("\n", ' ', $pgLocationDuplicate['message']), 0, 110)
    );

    $pgDistinct = PostgresFixtureBuilder::expectRejection(static fn () => $createUser($pgUsers, PARITY_USER_EMAIL_DISTINCT));

    $assert(
        'Control positivo: un login genuinamente distinto se admite en el motor único',
        $pgDistinct === null,
        $pgDistinct === null ? 'admitido' : 'rechazado con ' . $pgDistinct['state']
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

echo "\n--- Limpieza y residuo ---\n";

$pgResidue = $pgPdo->query("SELECT to_regclass('" . PostgresFixtureBuilder::ISOLATION_SCHEMA . ".incidents')")->fetchColumn();

$assert(
    'El escenario del motor único se retira entero (esquema aislado inexistente)',
    $pgResidue === null,
    'la sonda de residuo encontró ' . json_encode($pgResidue)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. T-HYBRID-21 (blindaje constitucional del motor único) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN EL BLINDAJE DEL MOTOR ÚNICO.\n";
echo "======================================================================\n";
exit(1);
