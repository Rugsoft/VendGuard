<?php

declare(strict_types=1);

/**
 * DemoHistorySeederPostgresTest · El histórico de demostración sobre el motor nuevo
 *
 * Certifica, contra un PostgreSQL real, lo que la suite hermética
 * (`tests/unit/DemoHistorySeederTest.php`) sólo puede afirmar en estático: que
 * `DemoHistorySeeder` **escribe** el histórico de averías en el motor del
 * despliegue —DDL base, migraciones y catálogo maestro aplicados en un esquema
 * aislado— sin chocar con el blindaje constitucional del propio motor.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **El esquema y el catálogo del repositorio se levantan enteros**: las
 *      sentencias de `database/supabase_init.sql`, la migración del motor por el
 *      ejecutor canónico y las semillas maestras. Sin parque no hay histórico que
 *      sembrar, y un sembrador que dependiera de una máquina inventada fallaría
 *      aquí.
 *
 *   2. **Las diecisiete averías entran, y las trece completadas quedan cerradas.**
 *      El índice único parcial `uq_machine_active_ticket` sólo admite una
 *      incidencia activa por máquina (Art. V.2 y V.6): si dos de las cuatro
 *      averías en garantía compartieran parque —o si alguna pisara un expediente
 *      vivo— el motor rechazaría el alta y esta suite lo vería. Se comprueba
 *      además que la cláusula de upsert que renderiza el adaptador funciona de
 *      verdad (T-HYBRID-25) y que el sembrador es idempotente.
 *
 *   3. **Las averías en garantía están vivas y son reabribles**: `RESOLVED` sin
 *      cierre y resueltas dentro de las 48 h, que es la única situación en la que
 *      el portal de la sede admite una reapertura (Art. V.6).
 *
 *   4. **La reincidencia se cuenta donde el cuadro de mandos la lee.** Las dos
 *      averías reabiertas dejan en `incident_history` el par `RESOLVED`/`REOPENED`
 *      que el repositorio de métricas recorre para atribuir la reintervención al
 *      técnico que firmó la resolución reabierta, y el desglose por técnico
 *      (`getBreakdownByTechnician`) devuelve exactamente esas dos reaperturas
 *      (EARS 2.3.1) repartidas entre los dos profesionales del fixture.
 *
 *   5. **La hora local del parque sobrevive al `TIMESTAMPTZ`.** El sembrador
 *      escribe con desplazamiento horario explícito, que es la única forma de que
 *      un alta de las 08:30 en `Europe/Madrid` siga leyéndose a las 08:30 y no a
 *      las 06:30 UTC.
 *
 *   6. **La traza y la auditoría cuelgan de cada expediente con las filas que le
 *      tocan** —cuatro o cinco en una avería de una sola vuelta, ocho o nueve en
 *      una reabierta— y la segunda pasada no las duplica: el histórico de estados
 *      es inmutable por diseño.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible (el motor de `deploy/local/postgres/`). NO
 *   pertenece a la batería hermética (RNF-04, spec §8.1): se declara con su marca
 *   de categoría, se ejecuta en su canal propio y su informe va aparte. Sin
 *   `PG_TEST_DSN` —contrato de entorno de la categoría, sin valor por defecto— la
 *   suite **no se omite en silencio**: lo dice y sale con código 2.
 *
 * LIMPIEZA: el escenario vive en el esquema aislado del arnés y se borra con
 * `CASCADE` al terminar, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/DemoHistorySeederPostgresTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Core/Domain/Model/MetricFilter.php';
require_once __DIR__ . '/../../src/Infrastructure/Dialect/SqlDialectAdapter.php';
require_once __DIR__ . '/../../src/Infrastructure/Repository/PdoMetricsRepository.php';
require_once __DIR__ . '/../../database/DemoHistorySeeder.php';

use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Database\DemoHistorySeeder;
use VendGuard\Infrastructure\Database\SqlScriptSplitter;
use VendGuard\Infrastructure\Repository\PdoMetricsRepository;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Antigüedades mínimas declaradas por el fixture, repetidas aquí para que la aserción no se lea de sí misma. */
const DEMO_HISTORY_MIN_DAY_OFFSET = -3;
const DEMO_HISTORY_MIN_IN_WARRANTY_DAY_OFFSET = -1;

/** Ventana del cuadro de mandos por defecto, en minutos: el filtro que lee el indicador. */
const DEMO_HISTORY_DASHBOARD_WINDOW_MINUTES = 30 * 24 * 60;

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;
$crash = null;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$pgPdo = PostgresFixtureBuilder::gateOrExit();

echo "======================================================================\n";
echo " VENDGUARD · Histórico de demostración sobre el motor nuevo (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    PostgresFixtureBuilder::createIsolationSchema($pgPdo);
    $schema = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    $seedStatements = 0;
    foreach (SqlScriptSplitter::split((string)file_get_contents($projectRoot . '/database/supabase_seeds.sql')) as $statement) {
        $result = $pgPdo->query($statement);
        if ($result instanceof PDOStatement) {
            $result->closeCursor();
        }
        $seedStatements++;
    }

    $assert(
        'El esquema del repositorio y el catálogo maestro se levantan en el motor nuevo',
        $schema['statements'] >= 100 && $seedStatements >= 5,
        "DDL: {$schema['statements']} sentencias · migraciones: " . count($schema['migrations']) . " · semillas: {$seedStatements} sentencias"
    );

    echo "\n--- Sembrado idempotente del histórico de demostración ---\n";

    $seeder = new DemoHistorySeeder($pgPdo);
    $first = $seeder->seed();
    $second = $seeder->seed();

    $assert(
        'El sembrador escribe las 17 averías del fixture (13 completadas y 4 en garantía)',
        $first['incidents'] === 17,
        json_encode($first, JSON_UNESCAPED_UNICODE) ?: ''
    );

    $assert(
        'La segunda pasada reafirma las averías y no duplica trazas ni auditoría',
        $second['incidents'] === 17 && $second['history_events'] === 0 && $second['audit_events'] === 0,
        json_encode($second, JSON_UNESCAPED_UNICODE) ?: ''
    );

    echo "\n--- Estado real del histórico en PostgreSQL ---\n";

    $scalar = static fn (string $sql): string => (string)$pgPdo->query($sql)->fetchColumn();

    $assert(
        'Los 13 expedientes completados existen y están cerrados',
        $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%' AND status = 'CLOSED'") === '13'
    );

    // Una avería en garantía es un expediente resuelto sin cierre y todavía dentro de
    // las 48 h: fuera de esa ventana el portal de la sede rechaza la reapertura
    // (EARS 9.2), así que el fixture dejaría de servir para probarla.
    $assert(
        'Las 4 averías en garantía están sin cerrar y dentro de su ventana de reapertura de 48 h (Art. V.6)',
        $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%' AND status = 'RESOLVED' AND closed_at IS NULL") === '4'
        && $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%' AND status = 'RESOLVED' AND resolved_at > now() - interval '48 hours'") === '4'
    );

    $assert(
        'Ninguna avería viva comparte máquina: el índice uq_machine_active_ticket se respeta (Art. V.2 y V.6)',
        $scalar("SELECT COUNT(*) FROM incidents WHERE status NOT IN ('CLOSED', 'CANCELLED')") === '4'
        && $scalar("SELECT COUNT(*) FROM (SELECT machine_id FROM incidents WHERE status NOT IN ('CLOSED', 'CANCELLED') GROUP BY machine_id HAVING COUNT(*) > 1) t") === '0'
    );

    $assert(
        'Diagnóstico y solución superan el mínimo real de 20 caracteres (Art. V.1)',
        $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%' AND char_length(trim(resolution_diagnosis)) >= 20 AND char_length(trim(resolution_action)) >= 20") === '17'
    );

    $assert(
        'Cada expediente de una sola vuelta tiene sus 5 eventos de traza o sus 4 si sigue en garantía',
        $scalar("SELECT COUNT(*) FROM (SELECT h.incident_id FROM incident_history h JOIN incidents i ON i.id = h.incident_id WHERE i.ticket_code LIKE 'INC-DEMO-11%' GROUP BY h.incident_id HAVING COUNT(*) = 5) t") === '12'
        && $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code IN ('INC-DEMO-1201', 'INC-DEMO-1202', 'INC-DEMO-1203')") === '3'
        && $scalar("SELECT COUNT(*) FROM (SELECT h.incident_id FROM incident_history h JOIN incidents i ON i.id = h.incident_id WHERE i.ticket_code IN ('INC-DEMO-1201', 'INC-DEMO-1202', 'INC-DEMO-1203') GROUP BY h.incident_id HAVING COUNT(*) = 4) t") === '3'
    );

    // La vuelta de garantía añade cuatro eventos a la traza de la avería reabierta:
    // reapertura, reasignación, reinicio de intervención y nueva resolución. La que
    // además cerró suma el cierre automático.
    $assert(
        'La avería reabierta y cerrada encadena 9 eventos de traza; la reabierta en garantía, 8',
        $scalar("SELECT COUNT(*) FROM incident_history h JOIN incidents i ON i.id = h.incident_id WHERE i.ticket_code = 'INC-DEMO-1204'") === '9'
        && $scalar("SELECT COUNT(*) FROM incident_history h JOIN incidents i ON i.id = h.incident_id WHERE i.ticket_code = 'INC-DEMO-1205'") === '8'
    );

    $assert(
        'La traza de la reincidencia deja la reapertura sin causante interno y con el motivo de la sede',
        $scalar("SELECT COUNT(*) FROM incident_history h JOIN incidents i ON i.id = h.incident_id WHERE i.ticket_code LIKE 'INC-DEMO-120%' AND h.from_status = 'RESOLVED' AND h.to_status = 'REOPENED' AND h.user_id IS NULL AND char_length(trim(h.action_note)) >= 20 AND h.action_note LIKE '%Reapertura solicitada por la sede (1ª reincidencia). Motivo:%'") === '2'
    );

    $assert(
        'Las dos reincidencias declaran su reapertura y su motivo en el expediente (Art. V.6)',
        $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-120%' AND reopened_at IS NOT NULL AND char_length(trim(reopen_reason)) >= 20") === '2'
        && $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-12%' AND reopened_at IS NULL") === '3'
    );

    $assert(
        'Cada expediente tiene sus 5 registros de auditoría (y 4 si sigue en garantía sin reapertura)',
        $scalar("SELECT COUNT(*) FROM (SELECT a.entity_id FROM audit_log a JOIN incidents i ON i.id = a.entity_id WHERE i.ticket_code LIKE 'INC-DEMO-11%' AND a.entity_type = 'TICKET' GROUP BY a.entity_id HAVING COUNT(*) = 5) t") === '12'
        && $scalar("SELECT COUNT(*) FROM (SELECT a.entity_id FROM audit_log a JOIN incidents i ON i.id = a.entity_id WHERE i.ticket_code IN ('INC-DEMO-1201', 'INC-DEMO-1202', 'INC-DEMO-1203') AND a.entity_type = 'TICKET' GROUP BY a.entity_id HAVING COUNT(*) = 4) t") === '3'
    );

    $assert(
        'La auditoría de la reapertura la firma la sede, sin cuenta interna y con el motivo declarado',
        $scalar("SELECT COUNT(*) FROM audit_log a JOIN incidents i ON i.id = a.entity_id WHERE i.ticket_code = 'INC-DEMO-1205' AND a.action = 'REOPEN_TICKET' AND a.user_id IS NULL AND a.user_role = 'SITE_MANAGER' AND a.user_name LIKE 'Responsable de Sede · %' AND a.new_state ->> 'reopen_reason' IS NOT NULL AND a.new_state ->> 'reopened_at' IS NOT NULL") === '1'
        && $scalar("SELECT COUNT(*) FROM audit_log a JOIN incidents i ON i.id = a.entity_id WHERE i.ticket_code = 'INC-DEMO-1204' AND a.action = 'REOPEN_TICKET' AND (a.metadata ->> 'reopen_count') = '1' AND (a.metadata ->> 'ticket_code') = 'INC-DEMO-1204'") === '1'
    );

    $assert(
        'La auditoría de una avería reabierta suma 9 registros si cerró y 8 si sigue en garantía',
        $scalar("SELECT COUNT(*) FROM audit_log a JOIN incidents i ON i.id = a.entity_id WHERE i.ticket_code = 'INC-DEMO-1204' AND a.entity_type = 'TICKET'") === '9'
        && $scalar("SELECT COUNT(*) FROM audit_log a JOIN incidents i ON i.id = a.entity_id WHERE i.ticket_code = 'INC-DEMO-1205' AND a.entity_type = 'TICKET'") === '8'
    );

    // La monotonía de una avería reabierta no es la de una de una sola vuelta: la
    // reapertura desasigna al técnico y la reasignación vuelve a escribir
    // `assigned_at` **después** del primer `started_at`, que la aplicación conserva
    // con `COALESCE(started_at, CURRENT_TIMESTAMP)`. Lo que sí tiene que cumplirse
    // siempre es que nada ocurra antes del alta ni después de la resolución efectiva.
    $assert(
        'La línea temporal es coherente en el motor: alta < inicio < resolución, y la vuelta de garantía cabe dentro',
        $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%' AND NOT (created_at < started_at AND started_at < resolved_at AND assigned_at < resolved_at)") === '0'
        && $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%' AND reopened_at IS NOT NULL AND NOT (reopened_at > created_at AND reopened_at < resolved_at)") === '0'
        && $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%' AND closed_at IS NOT NULL AND NOT (resolved_at <= closed_at)") === '0'
    );

    // Dos matices distintos: que ningún cierre caiga en el futuro (el histórico no
    // puede declarar algo que aún no ha pasado) y que ninguna avería sea más
    // reciente que el horizonte declarado. El horizonte se mide en **días del
    // calendario del parque**, no en horas transcurridas: el alta de «hace tres
    // días» son tres días de calendario en `Europe/Madrid`, y compararla con una
    // resta de 72 h sobre el instante actual la declararía demasiado reciente por
    // unas horas según la hora a la que se ejecute la suite.
    $assert(
        'Ningún cierre cae en el futuro y ninguna avería es más reciente que su horizonte declarado',
        $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%' AND closed_at > now()") === '0'
        && $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%' AND status = 'CLOSED' AND resolved_at > now()") === '0'
        && $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%' AND status = 'CLOSED'"
            . " AND (created_at AT TIME ZONE 'Europe/Madrid')::date > ((now() AT TIME ZONE 'Europe/Madrid')::date - " . abs(DEMO_HISTORY_MIN_DAY_OFFSET) . ')') === '0'
        && $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%' AND status = 'RESOLVED'"
            . " AND (created_at AT TIME ZONE 'Europe/Madrid')::date > ((now() AT TIME ZONE 'Europe/Madrid')::date - " . abs(DEMO_HISTORY_MIN_IN_WARRANTY_DAY_OFFSET) . ')') === '0'
    );

    $assert(
        'El tipo de máquina copiado y la sede coinciden con el catálogo',
        $scalar("SELECT COUNT(*) FROM incidents i JOIN machines m ON m.id = i.machine_id WHERE i.ticket_code LIKE 'INC-DEMO-%' AND (i.machine_type_snapshot IS DISTINCT FROM m.machine_type OR i.location_id <> m.location_id)") === '0'
    );

    $assert(
        'El histórico recorre las 13 máquinas del parque y las completadas reparten 12',
        $scalar("SELECT COUNT(DISTINCT machine_id) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%'") === '13'
        && $scalar("SELECT COUNT(DISTINCT machine_id) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%' AND status = 'CLOSED'") === '12'
    );

    $perishableAverage = (int)$scalar(
        "SELECT COALESCE(ROUND(AVG(EXTRACT(EPOCH FROM (i.resolved_at - i.created_at)) / 60)), 0)"
        . " FROM incidents i JOIN machines m ON m.id = i.machine_id"
        . " WHERE i.ticket_code LIKE 'INC-DEMO-%' AND m.machine_type = 'PERISHABLE_FOOD'"
    );

    $assert(
        'Los perecederos del histórico respetan el SLA de 4 h (Art. II)',
        $perishableAverage > 0 && $perishableAverage <= 240,
        "MTTR medio de perecederos: {$perishableAverage} min"
    );

    $assert(
        'La hora local del parque sobrevive al TIMESTAMPTZ (alta a las 08:30 en Europe/Madrid)',
        $scalar("SELECT to_char(created_at AT TIME ZONE 'Europe/Madrid', 'HH24:MI') FROM incidents WHERE ticket_code = 'INC-DEMO-1101'") === '08:30'
    );

    $assert(
        'Las averías del histórico no pisan las del fixture del motor local',
        $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-%'") === '17'
        && $scalar("SELECT COUNT(*) FROM incidents WHERE ticket_code LIKE 'INC-DEMO-09%' OR ticket_code LIKE 'INC-DEMO-10%'") === '0'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // El indicador de reincidencias, leído por donde lo lee el cuadro de mandos
    // ─────────────────────────────────────────────────────────────────────────
    //
    // No basta con que el fixture escriba las filas: el desglose por técnico las
    // tiene que **contar y atribuir** (EARS 2.3.1). Se ejecuta el repositorio real
    // contra el esquema aislado y se compara con lo que el propio fixture declara,
    // de modo que la expectativa no se copia a mano.

    echo "\n--- Desglose por técnico (PdoMetricsRepository sobre el motor nuevo) ---\n";

    $technicianNamesByEmail = [];
    foreach ($pgPdo->query('SELECT name, email FROM users') as $row) {
        $technicianNamesByEmail[(string)$row['email']] = (string)$row['name'];
    }

    $expectedReopens = [];
    $expectedInWindow = 0;
    foreach (DemoHistorySeeder::fixtures() as $fixture) {
        if (isset($fixture['reopen'])) {
            $name = $technicianNamesByEmail[$fixture['technician_email']] ?? $fixture['technician_email'];
            $expectedReopens[$name] = ($expectedReopens[$name] ?? 0) + 1;
        }

        // La marca de resolución efectiva, relativa a la medianoche de hoy, es lo que
        // el filtro de 30 días compara: el fixture declara fechas relativas, así que
        // la ventana se puede anticipar aquí sin consultar el motor dos veces.
        [$hour, $minute] = array_map('intval', explode(':', (string)$fixture['reported_at']));
        $resolution = (int)$fixture['resolution_minutes'];
        if (isset($fixture['reopen'])) {
            $resolution += (int)$fixture['reopen']['after_minutes'] + (int)$fixture['reopen']['resolution_minutes'];
        }
        $mark = (int)$fixture['day_offset'] * 1440 + $hour * 60 + $minute + $resolution;

        if ($mark > -DEMO_HISTORY_DASHBOARD_WINDOW_MINUTES) {
            $expectedInWindow++;
        }
    }

    $metrics = new PdoMetricsRepository($pgPdo);
    $filter = MetricFilter::fromQueryParams(['period' => MetricFilter::PERIOD_LAST_30_DAYS]);
    $breakdown = $metrics->getBreakdownByTechnician($filter);

    $reopensByTechnician = [];
    foreach ($breakdown as $row) {
        $count = (int)$row['warranty_reopens'];
        if ($count > 0) {
            $reopensByTechnician[(string)$row['technician_name']] = $count;
        }
    }

    $resolvedInWindow = array_sum(array_column($breakdown, 'tickets_resolved'));

    $assert(
        'El desglose por técnico cubre a los 5 técnicos de ruta del parque',
        count($breakdown) === 5,
        'filas: ' . count($breakdown) . ' · resueltas en la ventana: ' . $resolvedInWindow
    );

    $assert(
        'El desglose por técnico cuenta como reincidencia las 2 reintervenciones por garantía (EARS 2.3.1)',
        $reopensByTechnician === $expectedReopens && array_sum($reopensByTechnician) === 2,
        'esperadas: ' . json_encode($expectedReopens, JSON_UNESCAPED_UNICODE) . ' · contadas: ' . json_encode($reopensByTechnician, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        'Cada reincidencia se atribuye al técnico que firmó la resolución reabierta',
        $reopensByTechnician === $expectedReopens && count($reopensByTechnician) === 2
        && array_product($reopensByTechnician) === 1,
        json_encode($reopensByTechnician, JSON_UNESCAPED_UNICODE) ?: ''
    );

    $assert(
        'El desglose reparte las averías resueltas de la ventana de 30 días sobre el histórico sembrado',
        $resolvedInWindow === $expectedInWindow && $resolvedInWindow >= 12,
        "en la ventana: {$resolvedInWindow} de " . count(DemoHistorySeeder::fixtures()) . ' averías sembradas'
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueCheck = $pgPdo->query(
        "SELECT to_regclass('" . PostgresFixtureBuilder::ISOLATION_SCHEMA . ".incidents')"
    )->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El escenario se retira entero: el esquema aislado ya no existe en el motor',
    $residueCheck === null,
    'la sonda de residuo encontró ' . json_encode($residueCheck)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. HISTÓRICO DE DEMOSTRACIÓN CERTIFICADO EN EL MOTOR NUEVO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
