<?php

declare(strict_types=1);

/**
 * CoordinatorSanitarySweepEndpointTest (T-PAUSE-41)
 *
 * Suite de integración de la **superficie operativa de la contención del barrido
 * sanitario** (RF-07.1 a RF-07.5, Art. II y Art. III), por el enrutador real y
 * contra PostgreSQL, con DOS conexiones PDO independientes:
 *
 * 1. **Control de acceso (Art. V.4):** sin credencial `401`, token corrupto `401`,
 *    token de técnico `403` y sesión de coordinación `200`; el payload no se
 *    publica a nadie más.
 * 2. **Las tres situaciones que el panel antes no podía distinguir:** un libro
 *    vacío (`CLEAR`, sin latido), un barrido que corre y no aplaza (latido con
 *    cuarentenas y `is_stale` en falso) y un barrido que aplaza por contención de
 *    fila real (`MONITORED`, con el seguimiento abierto del expediente).
 * 3. **El ciclo completo del incremento, medido y no supuesto:** con la fila de
 *    la máquina retenida por una transacción viva de la segunda conexión, el
 *    barrido —por su ruta real— muere en el `55P03` y publica un aplazamiento; la
 *    superficie lo muestra con su presentación (expediente, máquina, sede, planta,
 *    estados) y **sin** encender la alerta; liberado el tenedor, la pasada
 *    siguiente sí evalúa y la superficie vuelve a `CLEAR` **cerrando el
 *    seguimiento por identidad** (la fila queda con `resolved_at`).
 * 4. **Escalado y orden:** un libro con más aplazamientos que el umbral de parque
 *    eleva la superficie a `ESCALATED` sin ningún seguimiento abierto, y los
 *    seguimientos escalados viajan por delante de los que no lo están.
 *
 * Autónoma: PostgreSQL real, cero llamadas de red salientes (Art. I y Art. IV), y
 * el parque queda restituido a las semillas al terminar.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\SanitaryClockSweepService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - CoordinatorSanitarySweepEndpointTest (T-PAUSE-41)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures   = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

// ─── Grupo 0: banco de pruebas con dos conexiones independientes ───────────────

echo "--- Grupo 0: Banco de pruebas ---\n";

$pdoSweep   = ConnectionFactory::getConnection();
$pdoHolder  = ConnectionFactory::getConnection([], true);

$assert(
    '0.1 Dos conexiones PDO REALES e independientes sobre la misma base de datos',
    $pdoSweep !== $pdoHolder
    && $pdoSweep->query('SELECT current_database()')->fetchColumn() === $pdoHolder->query('SELECT current_database()')->fetchColumn(),
    'las dos conexiones del banco de pruebas no son independientes'
);

TestDataCleaner::purge($pdoSweep);
(new SeedRunner($pdoSweep))->seedAll();

/** @var array<int, string> Estado sanitario del parque al arrancar (restituido al final). */
$sanitarySnapshot = [];
foreach ($pdoSweep->query('SELECT id, sanitary_status FROM machines WHERE deleted_at IS NULL') as $row) {
    $sanitarySnapshot[(int)$row['id']] = (string)$row['sanitary_status'];
}

$router       = AppRouter::create();
$incidentRepo = new PdoIncidentRepository($pdoSweep);
$locationRepo = new PdoLocationRepository($pdoSweep);
$userRepo     = new PdoUserRepository($pdoSweep);
$authService  = new AuthService($locationRepo, $userRepo);

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician  = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$assert(
    '0.2 Semilla disponible: coordinador de operaciones y técnico de ruta',
    $coordinator !== null && $technician !== null
);

if ($coordinator === null || $technician === null) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken  = $authService->generateInternalToken($technician);
$coordinatorId    = (int)$coordinator->getId();
$technicianId     = (int)$technician->getId();

// Máquinas libres bajo vigilancia sanitaria (perecederas y mixtas), sin expediente vivo: son
// las únicas que el reloj biológico puede poner en cuarentena. Los dos booleanos y el de la sede
// van en la forma que el motor único declara (clase D-3 del porte): el literal numérico frente a
// un `boolean` no es una comparación que el motor admita (`42883`).
$watchPool = $pdoSweep->query(
    "SELECT m.id AS machine_id, m.location_id, m.code, m.machine_type
       FROM machines m
       JOIN locations l ON l.id = m.location_id
      WHERE m.is_active = TRUE
        AND m.is_blocked_no_access = FALSE
        AND m.deleted_at IS NULL
        AND l.is_active = TRUE
        AND l.deleted_at IS NULL
        AND m.machine_type IN ('PERISHABLE_FOOD', 'COMBO')
        AND NOT EXISTS (
            SELECT 1 FROM incidents i
             WHERE i.machine_id = m.id
               AND i.deleted_at IS NULL
               AND i.status NOT IN ('CLOSED', 'CANCELLED')
        )
      ORDER BY m.id"
)->fetchAll(PDO::FETCH_ASSOC);

$assert(
    '0.3 El banco expone al menos cuatro máquinas libres bajo vigilancia sanitaria',
    count($watchPool) >= 4,
    sprintf('máquinas libres bajo vigilancia: %d', count($watchPool))
);

if (count($watchPool) < 4) {
    echo "ERROR FATAL: el banco no puede aislar las máquinas de la superficie.\n";
    exit(1);
}

$heartbeatMachine = $watchPool[0];
$contendedMachine = $watchPool[1];
$olderBookMachine = $watchPool[2];
$youngerBookMachine = $watchPool[3];

// ─── Instrumentos ─────────────────────────────────────────────────────────────

$sequence = 0;
$suffix   = strtoupper(bin2hex(random_bytes(3)));
$createdIncidentIds = [];

/** Crea y envejece un expediente vivo: la avería que el reloj biológico ve vencida. */
$createExpiredTicket = function (array $machine, int $hours = 5) use (
    $pdoSweep,
    $incidentRepo,
    $technicianId,
    $coordinatorId,
    $suffix,
    &$sequence,
    &$createdIncidentIds
): Incident {
    $sequence++;

    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: sprintf('TST-VIS-%s-%02d', $suffix, $sequence),
        machineId: (int)$machine['machine_id'],
        locationId: (int)$machine['location_id'],
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'Expediente de la superficie operativa del barrido sanitario (T-PAUSE-41).',
        urgency: UrgencyLevel::CRITICAL,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentId = (int)$created->getId();
    $createdIncidentIds[] = $incidentId;

    $incidentRepo->assign($incidentId, $technicianId, $coordinatorId);
    $incidentRepo->startIntervention($incidentId, $technicianId);

    $pdoSweep->prepare(
        'UPDATE incidents SET created_at = NOW() - INTERVAL :hours HOUR WHERE id = :id'
    )->execute([':hours' => $hours, ':id' => $incidentId]);

    return $incidentRepo->findById($incidentId);
};

$machineStatus = static function (int $machineId) use ($pdoSweep): string {
    $stmt = $pdoSweep->prepare('SELECT sanitary_status FROM machines WHERE id = :id');
    $stmt->execute([':id' => $machineId]);

    return (string)$stmt->fetchColumn();
};

/** La superficie, por su ruta real, con la sesión de quien la consulte. */
$surface = static function (?string $token) use ($router) {
    return $router->dispatch(new Request(
        method: 'GET',
        path: '/api/coordinator/sanitary-sweep/contention',
        headers: $token === null ? [] : ['Authorization' => "Bearer {$token}"]
    ));
};

$cronSecret = SecretProvider::cronSecret();

/** El barrido, por su ruta real (router + CronAuthMiddleware fail-closed). */
$sweep = static function () use ($router, $cronSecret) {
    return $router->dispatch(new Request(
        method: 'POST',
        path: '/api/cron/sanitary-clock-sweep',
        headers: ['X-Cron-Secret' => $cronSecret]
    ));
};

$contentionRow = static function (int $incidentId) use ($pdoSweep): ?array {
    $stmt = $pdoSweep->prepare(
        'SELECT * FROM sanitary_sweep_contentions WHERE incident_id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $incidentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
};

$latestPass = static function () use ($pdoSweep): ?array {
    $row = $pdoSweep->query(
        'SELECT * FROM sanitary_sweep_passes ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
};

try {
    // =====================================================================
    // GRUPO 1: Control de acceso (Art. V.4)
    // =====================================================================
    echo "\n--- Grupo 1: Control de acceso ---\n";

    $anonymous = $surface(null);
    $corrupt   = $router->dispatch(new Request(
        method: 'GET',
        path: '/api/coordinator/sanitary-sweep/contention',
        headers: ['Authorization' => 'Bearer token_corrupto']
    ));
    $asTechnician = $surface($technicianToken);

    $assert(
        '1.1 Sin credencial la superficie no se publica (401)',
        $anonymous->getStatusCode() === 401
        && ($anonymous->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED',
        sprintf('estado=%d', $anonymous->getStatusCode())
    );

    $assert(
        '1.2 Un token corrupto tampoco (401)',
        $corrupt->getStatusCode() === 401
        && ($corrupt->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED'
    );

    $assert(
        '1.3 El rol técnico no accede a la instrumentación interna (403)',
        $asTechnician->getStatusCode() === 403
        && ($asTechnician->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
    );

    $authorized = $surface($coordinatorToken);
    $emptyPayload = $authorized->getDecodedBody()['data'] ?? [];

    $assert(
        '1.4 La sesión de coordinación sí: 200 con el sobre estándar',
        $authorized->getStatusCode() === 200
        && ($authorized->getDecodedBody()['success'] ?? false) === true
    );

    $assert(
        '1.5 El payload publica exactamente las claves del contrato, en orden, sin campos extra',
        array_keys($emptyPayload) === ['generated_at', 'cadence_minutes', 'escalation', 'sweep', 'contentions']
        && array_keys($emptyPayload['sweep'] ?? []) === [
            'last_pass',
            'minutes_since_last_pass',
            'is_stale',
            'window_hours',
            'passes_in_window',
            'passes_with_contention',
            'deferrals_in_window',
            'failures_in_window',
            'quarantines_in_window',
            'contention_rate_percent',
        ],
        json_encode(array_keys($emptyPayload), JSON_UNESCAPED_UNICODE)
    );

    // =====================================================================
    // GRUPO 2: Un libro vacío no se parece a un barrido parado
    // =====================================================================
    echo "\n--- Grupo 2: Superficie sin libro ---\n";

    $assert(
        '2.1 Sin ninguna pasada registrada: CLEAR, sin latido y sin antigüedad (nunca corrió)',
        ($emptyPayload['escalation']['level'] ?? '') === 'CLEAR'
        && ($emptyPayload['escalation']['requires_attention'] ?? true) === false
        && array_key_exists('last_pass', $emptyPayload['sweep'] ?? [])
        && $emptyPayload['sweep']['last_pass'] === null
        && $emptyPayload['sweep']['minutes_since_last_pass'] === null
        && ($emptyPayload['sweep']['is_stale'] ?? true) === false
        && ($emptyPayload['contentions'] ?? ['x']) === [],
        json_encode($emptyPayload, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '2.2 La cadencia ratificada y la ventana de tendencia viajan en la respuesta',
        ($emptyPayload['cadence_minutes'] ?? 0) === 15
        && ($emptyPayload['sweep']['window_hours'] ?? 0) === 24
        && ($emptyPayload['escalation']['consecutive_passes_threshold'] ?? 0) === 3
        && ($emptyPayload['escalation']['per_pass_threshold'] ?? 0) === 5
    );

    // =====================================================================
    // GRUPO 3: Latido real del barrido (sin contención)
    // =====================================================================
    echo "\n--- Grupo 3: Latido del barrido ---\n";

    $heartbeatTicket = $createExpiredTicket($heartbeatMachine, 5);
    $heartbeatMachineId = (int)$heartbeatMachine['machine_id'];

    $sweepResponse = $sweep();
    $sweepData = $sweepResponse->getDecodedBody()['data'] ?? [];

    $surfacePayload = $surface($coordinatorToken)->getDecodedBody()['data'] ?? [];
    $lastPass = $surfacePayload['sweep']['last_pass'] ?? null;

    $assert(
        '3.1 La pasada del barrido queda publicada en la superficie con sus recuentos',
        $sweepResponse->getStatusCode() === 200
        && ($sweepData['quarantined'][0]['incident_id'] ?? null) === $heartbeatTicket->getId()
        && is_array($lastPass)
        && $lastPass['pass_id'] !== null
        && $lastPass['candidates'] === $sweepData['candidates']
        && $lastPass['evaluated'] === $sweepData['evaluated']
        && $lastPass['quarantined_count'] === count($sweepData['quarantined'])
        && $lastPass['contended_count'] === 0
        && $lastPass['failures_count'] === 0
        && $lastPass['finished_at'] !== null,
        json_encode($lastPass, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '3.2 Un barrido que acaba de latir no se declara parado y la cuarentena entra en la tendencia',
        ($surfacePayload['sweep']['is_stale'] ?? true) === false
        && ($surfacePayload['sweep']['minutes_since_last_pass'] ?? -1) === 0
        && ($surfacePayload['sweep']['passes_in_window'] ?? 0) >= 1
        && ($surfacePayload['sweep']['quarantines_in_window'] ?? 0) >= 1
        && ($surfacePayload['sweep']['passes_with_contention'] ?? -1) === 0
        && ($surfacePayload['escalation']['level'] ?? '') === 'CLEAR'
        && ($surfacePayload['escalation']['fleet_escalation'] ?? true) === false,
        json_encode($surfacePayload['sweep'], JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '3.3 La máquina quedó realmente en cuarentena y la superficie no inventa seguimientos',
        $machineStatus($heartbeatMachineId) === 'QUARANTINE'
        && ($surfacePayload['contentions'] ?? ['x']) === []
    );

    // =====================================================================
    // GRUPO 4: El ciclo completo del incremento (contención → escalado → cierre)
    // =====================================================================
    echo "\n--- Grupo 4: Contención real, aviso y cierre por identidad ---\n";

    $contendedTicket = $createExpiredTicket($contendedMachine, 5);
    $contendedIncidentId = (int)$contendedTicket->getId();
    $contendedMachineId = (int)$contendedMachine['machine_id'];

    // La fila de la máquina queda retenida por una transacción VIVA de la segunda conexión:
    // es el escritor que el barrido no puede esperar (T-PAUSE-39/40).
    $pdoHolder->beginTransaction();
    $pdoHolder->prepare('SELECT id FROM machines WHERE id = :id FOR UPDATE')
        ->execute([':id' => $contendedMachineId]);

    $startedAt = microtime(true);
    $contendedSweep = $sweep();
    $elapsedMs = (microtime(true) - $startedAt) * 1000;
    $contendedData = $contendedSweep->getDecodedBody()['data'] ?? [];

    // La cota declarada es de 2 s (T-PAUSE-40) y el motor la sobrepasa hasta en 1 s al comprobar
    // el vencimiento: el margen de la aserción es el mismo que ya fija la suite de la carrera
    // (< 3× la cota) y sigue estando un orden de magnitud por debajo del valor de servidor (50 s).
    $creditBoundMs = SanitaryClockSweepService::LOCK_WAIT_BUDGET_SECONDS * 3000;

    $assert(
        '4.1 Con la fila retenida, el barrido aplaza el expediente en lugar de esperar al tenedor',
        $contendedSweep->getStatusCode() === 200
        && ($contendedData['contended_count'] ?? -1) === 1
        && ($contendedData['contended'][0]['incident_id'] ?? null) === $contendedIncidentId
        && ($contendedData['failures_count'] ?? -1) === 0
        && $elapsedMs < $creditBoundMs
        && $machineStatus($contendedMachineId) === 'OK',
        sprintf(
            'ms=%.0f (margen=%d), datos=%s',
            $elapsedMs,
            $creditBoundMs,
            json_encode($contendedData, JSON_UNESCAPED_UNICODE)
        )
    );

    $assert(
        '4.2 El aplazamiento abre su seguimiento en el libro, sin estado ni cuarentena para la máquina',
        ($row = $contentionRow($contendedIncidentId)) !== null
        && (int)$row['consecutive_passes'] === 1
        && (int)$row['total_passes'] === 1
        && $row['escalated_at'] === null
        && $row['resolved_at'] === null
        && (int)$row['machine_id'] === $contendedMachineId,
        json_encode($row, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '4.3 La pasada aplazada queda en el libro con su contención y sin fallos reales',
        ($passRow = $latestPass()) !== null
        && (int)$passRow['contended_count'] === 1
        && (int)$passRow['failures_count'] === 0,
        json_encode($passRow, JSON_UNESCAPED_UNICODE)
    );

    $afterContention = $surface($coordinatorToken)->getDecodedBody()['data'] ?? [];
    $contentionRows = $afterContention['contentions'] ?? [];

    $assert(
        '4.4 La superficie muestra el aplazamiento vivo y NO enciende la alerta (aún no persiste)',
        ($afterContention['escalation']['level'] ?? '') === 'MONITORED'
        && ($afterContention['escalation']['requires_attention'] ?? true) === false
        && ($afterContention['escalation']['open_contentions'] ?? -1) === 1
        && ($afterContention['escalation']['escalated_contentions'] ?? -1) === 0
        && ($afterContention['sweep']['passes_with_contention'] ?? 0) >= 1
        && ($afterContention['sweep']['deferrals_in_window'] ?? 0) >= 1,
        json_encode($afterContention['escalation'], JSON_UNESCAPED_UNICODE)
    );

    $publishedRow = null;
    foreach ($contentionRows as $candidateRow) {
        if (($candidateRow['incident_id'] ?? null) === $contendedIncidentId) {
            $publishedRow = $candidateRow;
            break;
        }
    }

    $assert(
        '4.5 El seguimiento viaja con la presentación del expediente y de la máquina, ya resuelta',
        is_array($publishedRow)
        && $publishedRow['ticket_code'] === $contendedTicket->getTicketCode()
        && $publishedRow['machine_id'] === $contendedMachineId
        && $publishedRow['machine_code'] === (string)$contendedMachine['code']
        && $publishedRow['location_name'] !== null
        && $publishedRow['incident_status'] === 'IN_PROGRESS'
        && $publishedRow['machine_sanitary_status'] === 'OK'
        && $publishedRow['consecutive_passes'] === 1
        && $publishedRow['escalated'] === false
        && $publishedRow['age_minutes'] >= 0
        && $publishedRow['minutes_since_last_detection'] >= 0,
        json_encode($publishedRow, JSON_UNESCAPED_UNICODE)
    );

    // Se libera el tenedor: la pasada siguiente vuelve a evaluar el expediente y el reloj lo
    // pone en cuarentena. La superficie debe cerrar el seguimiento que ELLA ya no aplazó.
    $pdoHolder->rollBack();

    $recoverySweep = $sweep();
    $recoveryData = $recoverySweep->getDecodedBody()['data'] ?? [];
    $afterRecovery = $surface($coordinatorToken)->getDecodedBody()['data'] ?? [];
    $closedRow = $contentionRow($contendedIncidentId);

    $assert(
        '4.6 Liberado el tenedor, la pasada siguiente sí evalúa y cuarentena la máquina',
        $recoverySweep->getStatusCode() === 200
        && ($recoveryData['contended_count'] ?? -1) === 0
        && ($recoveryData['failures_count'] ?? -1) === 0
        && in_array($contendedIncidentId, array_map(
            'intval',
            array_column($recoveryData['quarantined'] ?? [], 'incident_id')
        ), true)
        && $machineStatus($contendedMachineId) === 'QUARANTINE',
        json_encode($recoveryData, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '4.7 El seguimiento queda CERRADO por identidad y la superficie vuelve a CLEAR',
        is_array($closedRow)
        && $closedRow['resolved_at'] !== null
        && ($afterRecovery['contentions'] ?? ['x']) === []
        && ($afterRecovery['escalation']['level'] ?? '') === 'CLEAR'
        && ($afterRecovery['escalation']['requires_attention'] ?? true) === false
        && ($afterRecovery['escalation']['open_contentions'] ?? -1) === 0,
        json_encode(
            ['fila' => $closedRow, 'escalation' => $afterRecovery['escalation'] ?? null],
            JSON_UNESCAPED_UNICODE
        )
    );

    $assert(
        '4.8 El cierre no pierde el histórico: el acumulado del episodio sigue publicado en la fila',
        is_array($closedRow)
        && (int)$closedRow['consecutive_passes'] === 1
        && (int)$closedRow['total_passes'] === 1
    );

    // =====================================================================
    // GRUPO 5: Escalado de parque y orden de la lista
    // =====================================================================
    echo "\n--- Grupo 5: Escalado de parque y orden ---\n";

    $pdoSweep->prepare(
        'INSERT INTO sanitary_sweep_passes
            (started_at, finished_at, candidates, evaluated, quarantined_count,
             contended_count, failures_count, duration_ms)
         VALUES (NOW(), NOW(), 7, 1, 0, 6, 0, 12.50)'
    )->execute();
    $fleetPassId = (int)$pdoSweep->lastInsertId();

    $fleetPayload = $surface($coordinatorToken)->getDecodedBody()['data'] ?? [];

    $assert(
        '5.1 Más aplazamientos que el umbral de parque elevan el aviso sin ningún seguimiento abierto',
        ($fleetPayload['escalation']['fleet_escalation'] ?? false) === true
        && ($fleetPayload['escalation']['level'] ?? '') === 'ESCALATED'
        && ($fleetPayload['escalation']['requires_attention'] ?? false) === true
        && ($fleetPayload['escalation']['open_contentions'] ?? -1) === 0
        && ($fleetPayload['escalation']['escalated_contentions'] ?? -1) === 0
        && ($fleetPayload['sweep']['last_pass']['contended_count'] ?? -1) === 6,
        json_encode($fleetPayload['escalation'], JSON_UNESCAPED_UNICODE)
    );

    // El borrado del libro de pasadas va por la **ventana de purga** del contrato de limpieza (§3):
    // el guardián del Art. III.1 muerde cualquier `DELETE` en crudo —también sobre el libro de
    // pasadas— y en el motor retirado esa puerta no existía (`45000` no es un código de su catálogo).
    TestDataCleaner::withPurgeWindow($pdoSweep, static function () use ($pdoSweep, $fleetPassId): void {
        $pdoSweep->prepare('DELETE FROM sanitary_sweep_passes WHERE id = :id')
            ->execute([':id' => $fleetPassId]);
    });

    // Dos seguimientos sembrados a mano —el camino de lectura es lo que se certifica aquí—, con
    // el escalado en el MÁS RECIENTE: la lista debe poner primero el escalado, porque la alerta
    // nunca puede quedar por debajo de un aplazamiento reciente.
    $olderTicket   = $createExpiredTicket($olderBookMachine, 5);
    $youngerTicket = $createExpiredTicket($youngerBookMachine, 5);

    // La aritmética temporal va en la forma del motor único (el intervalo con su unidad como
    // literal); el retirado escribía `INTERVAL 90 MINUTE`, que aquí no se parsea.
    $pdoSweep->prepare(
        "INSERT INTO sanitary_sweep_contentions
            (incident_id, machine_id, first_detected_at, last_detected_at,
             consecutive_passes, total_passes, escalated_at, resolved_at)
         VALUES (:incident_id, :machine_id, NOW() - INTERVAL '90 minutes', NOW() - INTERVAL '5 minutes', 2, 2, NULL, NULL)"
    )->execute([
        ':incident_id' => (int)$olderTicket->getId(),
        ':machine_id'  => (int)$olderTicket->getMachineId(),
    ]);

    $pdoSweep->prepare(
        "INSERT INTO sanitary_sweep_contentions
            (incident_id, machine_id, first_detected_at, last_detected_at,
             consecutive_passes, total_passes, escalated_at, resolved_at)
         VALUES (:incident_id, :machine_id, NOW() - INTERVAL '30 minutes', NOW() - INTERVAL '5 minutes', 4, 4, NOW() - INTERVAL '20 minutes', NULL)"
    )->execute([
        ':incident_id' => (int)$youngerTicket->getId(),
        ':machine_id'  => (int)$youngerTicket->getMachineId(),
    ]);

    $orderedPayload = $surface($coordinatorToken)->getDecodedBody()['data'] ?? [];
    $orderedRows = $orderedPayload['contentions'] ?? [];

    $assert(
        '5.2 El escalado manda en el orden: el escalado más reciente va por delante del antiguo sin escalar',
        count($orderedRows) === 2
        && ($orderedRows[0]['incident_id'] ?? null) === (int)$youngerTicket->getId()
        && ($orderedRows[0]['escalated'] ?? false) === true
        && ($orderedRows[1]['incident_id'] ?? null) === (int)$olderTicket->getId()
        && ($orderedRows[1]['escalated'] ?? true) === false
    );

    $assert(
        '5.3 Un seguimiento escalado eleva la superficie a ESCALATED con su recuento',
        ($orderedPayload['escalation']['level'] ?? '') === 'ESCALATED'
        && ($orderedPayload['escalation']['requires_attention'] ?? false) === true
        && ($orderedPayload['escalation']['open_contentions'] ?? -1) === 2
        && ($orderedPayload['escalation']['escalated_contentions'] ?? -1) === 1
        && ($orderedRows[0]['age_minutes'] ?? -1) >= 30
        && ($orderedRows[0]['consecutive_passes'] ?? -1) === 4,
        json_encode($orderedPayload['escalation'], JSON_UNESCAPED_UNICODE)
    );
} finally {
    // Restitución del banco: parque a sus semillas y libro de pasadas vacío, sin depender de
    // que todas las aserciones hayan ido bien.
    if ($pdoHolder->inTransaction()) {
        $pdoHolder->rollBack();
    }

    TestDataCleaner::purge($pdoSweep);

    $restore = $pdoSweep->prepare('UPDATE machines SET sanitary_status = :status WHERE id = :id');
    foreach ($sanitarySnapshot as $machineId => $status) {
        $restore->execute([':status' => $status, ':id' => $machineId]);
    }

    try {
        (new SeedRunner($pdoSweep))->seedAll();
    } catch (Throwable $exception) {
        echo "  [WARN] Re-siembra final: " . $exception->getMessage() . "\n";
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-41 (INTEGRACIÓN) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
