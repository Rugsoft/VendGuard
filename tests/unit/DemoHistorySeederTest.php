<?php

declare(strict_types=1);

/**
 * DemoHistorySeederTest
 *
 * Certifica, **sin base de datos**, los contratos que el histórico de demostración
 * (`database/DemoHistorySeeder.php`) tiene que cumplir para poder sembrarse en el
 * motor nuevo: que las averías completadas no compitan por el índice único parcial
 * `uq_machine_active_ticket` y que las que siguen en garantía —vivas— ocupen
 * máquinas exclusivas, que la línea temporal sea monótona y quepa en la ventana de
 * garantía de 48 h, que las reaperturas sean legales (dentro de las 48 h posteriores
 * a la resolución que reabren, con motivo y parte justificados) y que cada máquina y
 * cada técnico del fixture existan en el catálogo maestro que declaran las semillas
 * del motor.
 *
 * Y, desde la enmienda EARS-02.6, también la política del arranque —el histórico entra por
 * defecto y sólo un «no» declarado lo apaga— y las propiedades de la pasada que reafirma un
 * histórico ya sembrado: el desplazamiento de las marcas es un `UPDATE` fila a fila, no hay ni
 * un borrado en el camino (`DELETE` ni `TRUNCATE`, y la ventana de purga del arnés queda
 * fuera), y la reafirmación no alcanza ni a la traza que dejó de ser la del fixture ni a la
 * máquina que ya está ocupada por otro aviso vivo.
 *
 * El catálogo y los tipos enumerados **no se copian aquí**: se leen de
 * `database/supabase_seeds.sql` y `database/supabase_init.sql`, que son la fuente
 * de verdad del arranque desplegado. Una fixture que invente una máquina o una
 * categoría que el DDL no declara falla en esta suite, no en el servicio.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../database/DemoHistorySeeder.php';

use VendGuard\Database\DemoHistorySeeder;

echo "========================================================\n";
echo " VendGuard: Contratos del histórico de demostración\n";
echo "========================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = static function (string $description, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [OK] {$description}\n";
        return;
    }

    $failures++;
    echo "  [FALLO] {$description}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
};

$projectRoot = dirname(__DIR__, 2);
$fixtures = DemoHistorySeeder::fixtures();

/** Averías completadas: cerradas, con su reloj de garantía ya consumido. */
$isClosed = static fn (array $fixture): bool => $fixture['status'] === 'CLOSED';

/** Averías en garantía: resueltas y todavía reabribles desde el portal de la sede. */
$isInWarranty = static fn (array $fixture): bool => $fixture['status'] === 'RESOLVED';

/** Averías que declaran la vuelta de garantía (reapertura y reintervención). */
$hasReopen = static fn (array $fixture): bool => isset($fixture['reopen']);

/** Marca, en minutos desde la medianoche de hoy, del instante declarado. */
$markFromToday = static function (array $fixture, int $minutesAfterReport): int {
    [$hour, $minute] = array_map('intval', explode(':', (string)$fixture['reported_at']));

    return (int)$fixture['day_offset'] * 1440 + $hour * 60 + $minute + $minutesAfterReport;
};

// ─────────────────────────────────────────────────────────────────────────────
// 1. Fuentes de verdad del despliegue
// ─────────────────────────────────────────────────────────────────────────────

$seedSql = (string)file_get_contents($projectRoot . '/database/supabase_seeds.sql');
$initSql = (string)file_get_contents($projectRoot . '/database/supabase_init.sql');

/** Parque maestro de las semillas del motor nuevo: código => tipo de máquina. */
$masterFleet = [];
if (preg_match_all("/'(VEND-\d{4})'\s*,\s*'[^']*'\s*,\s*'([A-Z_]+)'/", $seedSql, $fleetMatches, PREG_SET_ORDER)) {
    foreach ($fleetMatches as $match) {
        $masterFleet[$match[1]] = $match[2];
    }
}

/** Personal interno de las semillas del motor nuevo. */
$masterStaff = [];
if (preg_match_all("/'([a-z0-9._-]+@vendguard\.internal)'/", $seedSql, $staffMatches)) {
    $masterStaff = array_values(array_unique($staffMatches[1]));
}

/** Etiquetas de un tipo enumerado del DDL del motor nuevo. */
$enumLabels = static function (string $typeName) use ($initSql): array {
    $pattern = '/CREATE TYPE ' . preg_quote($typeName, '/') . ' AS ENUM \((.*?)\)/s';
    if (preg_match($pattern, $initSql, $matches) !== 1) {
        return [];
    }

    return preg_match_all("/'([A-Z_]+)'/", $matches[1], $labels) ? $labels[1] : [];
};

$assert(
    '1. Las fuentes de verdad del despliegue se leen (13 máquinas maestras y 6 usuarios)',
    count($masterFleet) === 13 && count($masterStaff) === 6,
    'máquinas: ' . count($masterFleet) . ' · usuarios: ' . count($masterStaff)
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Forma del fixture
// ─────────────────────────────────────────────────────────────────────────────

$assert(
    '2. El histórico declara diecisiete averías: trece completadas y cuatro en garantía',
    count($fixtures) === 17
    && count(array_filter($fixtures, $isClosed)) === 13
    && count(array_filter($fixtures, $isInWarranty)) === 4,
    sprintf(
        'declaradas: %d · completadas: %d · en garantía: %d',
        count($fixtures),
        count(array_filter($fixtures, $isClosed)),
        count(array_filter($fixtures, $isInWarranty))
    )
);

$ticketCodes = array_column($fixtures, 'ticket_code');
$assert(
    '3. Los códigos de ticket son únicos y declaran su origen de demostración',
    count(array_unique($ticketCodes)) === count($ticketCodes)
    && array_filter($ticketCodes, static fn (string $code): bool => str_starts_with($code, 'INC-DEMO-')) === $ticketCodes,
    implode(', ', $ticketCodes)
);

// Las averías en garantía están **vivas**: el índice único parcial
// `uq_machine_active_ticket` es `(machine_id) WHERE status NOT IN ('CLOSED',
// 'CANCELLED')`, de modo que ninguna puede compartir máquina con otro expediente
// abierto —ni entre ellas ni con ningún otro— y ninguna puede llevar fecha de cierre.
$inWarrantyMachines = array_column(array_filter($fixtures, $isInWarranty), 'machine_code');

$assert(
    '4. Las cuatro averías en garantía están sin cerrar y ocupan máquinas exclusivas (Art. V.2 y V.6)',
    count($inWarrantyMachines) === 4
    && count(array_unique($inWarrantyMachines)) === 4
    && array_filter(array_filter($fixtures, $isInWarranty), static fn (array $fixture): bool => $fixture['closure_delay_minutes'] !== null) === [],
    'máquinas en garantía: ' . implode(', ', $inWarrantyMachines)
);

$assert(
    '5. Toda avería completada declara el retardo de su cierre automático',
    array_filter(array_filter($fixtures, $isClosed), static fn (array $fixture): bool => $fixture['closure_delay_minutes'] === null) === [],
    'sin cierre: ' . implode(', ', array_column(
        array_filter(array_filter($fixtures, $isClosed), static fn (array $fixture): bool => $fixture['closure_delay_minutes'] === null),
        'ticket_code'
    ))
);

$assert(
    '6. Diagnóstico y solución superan el mínimo real de 20 caracteres (Art. V.1)',
    array_filter($fixtures, static fn (array $fixture): bool => mb_strlen(trim($fixture['diagnosis'])) >= DemoHistorySeeder::MIN_JUSTIFICATION_LENGTH
        && mb_strlen(trim($fixture['action'])) >= DemoHistorySeeder::MIN_JUSTIFICATION_LENGTH) === $fixtures
);

// La reincidencia se sostiene sobre tres textos: el motivo que declara la sede al
// reabrir y el parte de la reintervención (diagnóstico y solución). Son los que el
// detalle del coordinador muestra y los que el `CHECK` del motor exige con 20
// caracteres reales, así que ninguno puede ir de relleno.
$reopenJustificationFailures = [];
foreach (array_filter($fixtures, $hasReopen) as $fixture) {
    foreach (['reason', 'diagnosis', 'action'] as $field) {
        if (mb_strlen(trim((string)$fixture['reopen'][$field])) < DemoHistorySeeder::MIN_JUSTIFICATION_LENGTH) {
            $reopenJustificationFailures[] = $fixture['ticket_code'] . '.' . $field;
        }
    }
}

$assert(
    '7. Hay dos reincidencias por garantía y su reapertura y reintervención se justifican (Art. V.1)',
    count(array_filter($fixtures, $hasReopen)) === 2 && $reopenJustificationFailures === [],
    'reincidencias: ' . count(array_filter($fixtures, $hasReopen)) . ' · textos cortos: ' . implode(', ', $reopenJustificationFailures)
);

$machineCodes = array_column($fixtures, 'machine_code');
$assert(
    '8. El histórico recorre las trece máquinas maestras y las completadas reparten doce (Art. V.6)',
    count(array_unique($machineCodes)) === 13
    && count(array_unique(array_column(array_filter($fixtures, $isClosed), 'machine_code'))) === 12,
    sprintf(
        'máquinas: %d · entre las completadas: %d',
        count(array_unique($machineCodes)),
        count(array_unique(array_column(array_filter($fixtures, $isClosed), 'machine_code')))
    )
);

$assert(
    '9. Cada máquina del fixture existe en el catálogo maestro de las semillas',
    array_diff($machineCodes, array_keys($masterFleet)) === [],
    'desconocidas: ' . implode(', ', array_diff($machineCodes, array_keys($masterFleet)))
);

$technicianEmails = array_column($fixtures, 'technician_email');
$assert(
    '10. Cada técnico del fixture existe en el catálogo maestro de usuarios',
    array_diff($technicianEmails, $masterStaff) === [],
    'desconocidos: ' . implode(', ', array_diff($technicianEmails, $masterStaff))
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Coherencia temporal
// ─────────────────────────────────────────────────────────────────────────────

$chronologyFailures = [];
$reopenWindowFailures = [];
$warrantyFailures = [];
$horizonFailures = [];
$slaFailures = [];

foreach ($fixtures as $fixture) {
    $assign = (int)$fixture['assign_delay_minutes'];
    $start = (int)$fixture['start_delay_minutes'];
    $resolution = (int)$fixture['resolution_minutes'];
    $reopen = $fixture['reopen'] ?? null;
    $closed = $fixture['closure_delay_minutes'];

    // La resolución **efectiva** es la que el expediente guarda como `resolved_at` y
    // la que reinicia el reloj de las 48 h, así que en una avería reabierta el cierre
    // se mide desde la segunda vuelta, no desde la primera.
    $effectiveResolution = $reopen === null
        ? $resolution
        : $resolution + (int)$reopen['after_minutes'] + (int)$reopen['resolution_minutes'];
    $closedOffset = $closed === null ? null : $effectiveResolution + (int)$closed;

    if (!($assign > 0 && $assign < $start && $start < $resolution
        && ($closedOffset === null || $resolution < $closedOffset))) {
        $chronologyFailures[] = $fixture['ticket_code'];
    }

    if ($reopen !== null) {
        $reopenedAt = $resolution + (int)$reopen['after_minutes'];
        $reassignedAt = $reopenedAt + (int)$reopen['reassign_minutes'];
        $restartedAt = $reopenedAt + (int)$reopen['restart_minutes'];
        $reresolvedAt = $reopenedAt + (int)$reopen['resolution_minutes'];

        if (!($resolution < $reopenedAt && $reopenedAt < $reassignedAt
            && $reassignedAt < $restartedAt && $restartedAt < $reresolvedAt)) {
            $chronologyFailures[] = $fixture['ticket_code'] . ' (vuelta de garantía)';
        }

        // La ventana de reapertura es de 48 h desde la resolución y es el único
        // margen que el motor de estados admite: fuera de ella la sede rechaza el
        // aviso (EARS 9.2).
        if ((int)$reopen['after_minutes'] < 1 || (int)$reopen['after_minutes'] > DemoHistorySeeder::WARRANTY_WINDOW_MINUTES) {
            $reopenWindowFailures[] = $fixture['ticket_code'];
        }
    }

    if ($closedOffset !== null && ((int)$closed <= 0 || (int)$closed > DemoHistorySeeder::WARRANTY_WINDOW_MINUTES)) {
        $warrantyFailures[] = $fixture['ticket_code'];
    }

    // Dos horizontes distintos: una avería completada tiene que haber cerrado ya
    // —resolución más 48 h, de ahí los tres días mínimos— y una en garantía tiene
    // que estar viva, es decir resuelta y dentro de sus 48 h, a la hora que sea
    // cuando se siembre (la peor hora es el final del día, de ahí el día completo
    // de margen que exige `MIN_IN_WARRANTY_DAY_OFFSET`).
    $oldestAllowed = $closedOffset === null
        ? DemoHistorySeeder::MIN_IN_WARRANTY_DAY_OFFSET
        : DemoHistorySeeder::MIN_DAY_OFFSET;
    if ((int)$fixture['day_offset'] > $oldestAllowed) {
        $horizonFailures[] = $fixture['ticket_code'];
    }

    // Ninguna marca del fixture —ni siquiera la reintervención— puede caer en el
    // futuro: el último instante declarado se mide contra la medianoche de hoy, que
    // es anterior a cualquier hora de siembra.
    $lastMark = $markFromToday($fixture, $effectiveResolution);
    if ($closed !== null) {
        $lastMark += (int)$closed;
    }
    if ($lastMark >= 0) {
        $horizonFailures[] = $fixture['ticket_code'] . ' (marca futura)';
    }

    // Y la garantía de las averías resueltas sigue abierta al sembrar.
    if ($closedOffset === null) {
        $resolvedMark = $markFromToday($fixture, $effectiveResolution);
        if ($resolvedMark <= 1440 - DemoHistorySeeder::WARRANTY_WINDOW_MINUTES) {
            $warrantyFailures[] = $fixture['ticket_code'] . ' (garantía vencida al sembrar)';
        }
    }

    $machineType = $masterFleet[$fixture['machine_code']] ?? '';
    if ($machineType === 'PERISHABLE_FOOD' && $effectiveResolution > 240) {
        $slaFailures[] = $fixture['ticket_code'];
    }
}

$assert(
    '11. La línea temporal es monótona: alta < asignación < inicio < resolución < cierre',
    $chronologyFailures === [],
    implode(', ', $chronologyFailures)
);

$assert(
    '12. La reapertura cae dentro de la ventana de garantía de 48 h (EARS 9.2)',
    $reopenWindowFailures === [],
    implode(', ', $reopenWindowFailures)
);

$assert(
    '13. El cierre cabe en la ventana de garantía de 48 h y la garantía sigue viva al sembrar (Art. V.6)',
    $warrantyFailures === [],
    implode(', ', $warrantyFailures)
);

$assert(
    '14. Ninguna marca del fixture cae en el futuro, se siembre el día que se siembre',
    $horizonFailures === [],
    implode(', ', $horizonFailures)
);

$assert(
    '15. Los perecederos del fixture respetan el SLA de 4 h (Art. II)',
    $slaFailures === [],
    implode(', ', $slaFailures)
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Enumerados del DDL y convivencia con el fixture del motor local
// ─────────────────────────────────────────────────────────────────────────────

$categories = $enumLabels('incident_category');
$urgencies = $enumLabels('urgency_level');
$statuses = $enumLabels('incident_status');

$assert(
    '16. Categorías, urgencias y estados del fixture pertenecen a los enumerados del DDL',
    $categories !== [] && $urgencies !== [] && $statuses !== []
    && array_diff(array_unique(array_column($fixtures, 'category')), $categories) === []
    && array_diff(array_unique(array_column($fixtures, 'urgency')), $urgencies) === []
    && array_diff(array_unique(array_column($fixtures, 'status')), $statuses) === [],
    sprintf(
        'categorías: %s · urgencias: %s · estados: %s',
        json_encode($categories) ?: '',
        json_encode($urgencies) ?: '',
        json_encode($statuses) ?: ''
    )
);

// Dos sembradores de demostración conviviendo: el del motor local y este. Si
// compartieran códigos de ticket, ejecutar los dos sobre la misma base haría que
// uno reescribiera las averías del otro en lugar de sumar histórico.
$localFixture = (string)file_get_contents($projectRoot . '/database/DemoMetricsSeeder.php');
preg_match_all('/INC-DEMO-\d+/', $localFixture, $localTickets);
$shared = array_values(array_intersect(array_unique($localTickets[0]), $ticketCodes));

$assert(
    '17. Los códigos del histórico nuevo no pisan los del fixture del motor local',
    $localTickets[0] !== [] && $shared === [],
    'compartidos: ' . implode(', ', $shared)
);

$assert(
    '18. El sembrador delega la cláusula de upsert en el adaptador de dialecto (EARS-01.5)',
    str_contains((string)file_get_contents($projectRoot . '/database/DemoHistorySeeder.php'), 'upsertFromInsertedRow')
);

// La puerta del arranque: el histórico entra por defecto —cada redespliegue lo devuelve a su
// sitio (EARS-02.6)— y sólo un «no» declarado lo apaga. La variable se manipula y se restituye
// su valor previo, para que la aserción no dependa de lo que tenga declarado el entorno que
// ejecuta la batería.
$flag = DemoHistorySeeder::ENVIRONMENT_FLAG;
$previousFlag = getenv($flag);

putenv($flag);
$absentIsYes = DemoHistorySeeder::enabledByEnvironment();
putenv($flag . '=');
$emptyIsYes = DemoHistorySeeder::enabledByEnvironment();
putenv($flag . '=0');
$zeroIsNo = DemoHistorySeeder::enabledByEnvironment();
putenv($flag . '=OFF');
$offIsNo = DemoHistorySeeder::enabledByEnvironment();
putenv($flag . '=no');
$lowercaseNo = DemoHistorySeeder::enabledByEnvironment();
putenv($flag . '=1');
$oneIsYes = DemoHistorySeeder::enabledByEnvironment();
putenv($flag . '=yes');
$yesIsYes = DemoHistorySeeder::enabledByEnvironment();

putenv($previousFlag === false ? $flag : $flag . '=' . $previousFlag);

$assert(
    '19. El arranque siembra el histórico por defecto: ausente o vacío valen «sí» (EARS-02.6)',
    $absentIsYes === true && $emptyIsYes === true
);

$assert(
    '20. Sólo un «no» declarado lo apaga, sin distinguir mayúsculas, y el «sí» explícito sigue valiendo',
    $zeroIsNo === false && $offIsNo === false && $lowercaseNo === false
    && $oneIsYes === true && $yesIsYes === true
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. La pasada sobre un histórico ya sembrado (EARS-02.6)
// ─────────────────────────────────────────────────────────────────────────────
//
// Las fechas del fixture son relativas y se materializan al sembrar, así que cada pasada
// mueve el expediente hacia adelante. Sin la reafirmación de las marcas, la traza quedaría
// anclada antes del alta que cuenta. Aquí se certifica en estático lo que el motor real mide
// en la categoría `new_engine`: que el desplazamiento es un `UPDATE` fila a fila, que no hay
// un solo borrado en el camino y que la reafirmación está guardada por la forma del fixture y
// por la ocupación del parque.

$seederSource = (string)file_get_contents($projectRoot . '/database/DemoHistorySeeder.php');

$assert(
    '21. La traza y la auditoría se reafirman con UPDATE fila a fila, conservando la identidad de cada fila',
    str_contains($seederSource, "'UPDATE incident_history'")
    && str_contains($seederSource, "'UPDATE audit_log'")
    && str_contains($seederSource, "' WHERE id = :id AND incident_id = :incident_id'")
    && str_contains($seederSource, "' WHERE id = :id AND entity_type = :entity_type AND entity_id = :entity_id'")
);

$assert(
    '22. El sembrador no borra ni trunca nada (Art. III.1) y no usa la señal de purga del arnés',
    preg_match('/\b(DELETE\s+FROM|TRUNCATE\s+TABLE|DROP\s+TABLE)\b/i', $seederSource) === 0
    && !str_contains($seederSource, 'vendguard.purge')
    && !str_contains($seederSource, 'vendguard_purge')
    && !str_contains($seederSource, 'set_config')
);

$assert(
    '23. La reafirmación está guardada por la forma del fixture y por la ocupación de la máquina',
    str_contains($seederSource, 'matchesFixtureShape')
    && str_contains($seederSource, 'machineOccupiedByOtherIncident')
    && str_contains($seederSource, "AND status NOT IN ('CLOSED', 'CANCELLED')")
);

// ─────────────────────────────────────────────────────────────────────────────

echo "\n========================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. HISTÓRICO DE DEMOSTRACIÓN CERTIFICADO.\n";
    echo "========================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "========================================================\n";
exit(1);
