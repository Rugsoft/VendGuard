<?php

declare(strict_types=1);

/**
 * SanitaryClockSweepEndpointTest (T-PAUSE-38)
 *
 * Suite de integración del barrido de fondo del Reloj Sanitario Biológico
 * (RF-03.5.1, RF-03.4, Art. II y Art. III). Certifica, por el enrutador real y
 * contra MariaDB, el hueco que el módulo declaraba abierto:
 *
 *   - `POST /api/cron/sanitary-clock-sweep` está protegido por `CronAuthMiddleware`
 *     (secreto de cron, Bearer del secreto o sesión de coordinación) y el controlador
 *     es fail-closed sin la marca del middleware.
 *   - **La desviación que se cierra:** un expediente vivo de máquina bajo vigilancia
 *     sanitaria con más de 4 h naturales entra en cuarentena **sin que nadie haya
 *     intentado cerrar la avería**, y la máquina se publica bloqueada en el canal
 *     ciudadano (modo `SANITARY_QUARANTINE`) por el mismo estado que escribió el barrido.
 *   - El rastro inmutable se escribe **una sola vez** por máquina y el segundo barrido
 *     no añade nada; la puerta sanitaria del cierre (RF-03.5.2) sigue mordiendo.
 *   - Controles negativos: bebidas frías, snacks, umbrales no alcanzados y expedientes
 *     liquidados no despiertan.
 *
 * Sin caso cURL propio a propósito: el despacho se ejerce sobre el mismo enrutador que
 * sirve `public/index.php` y el transporte HTTP de la familia `/api/cron/*` ya está
 * certificado por `CronAutoCloseEndpointTest`; duplicarlo aquí no añadiría cobertura y
 * sí una dependencia de servidor vivo.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoLockWaitBudget;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Controller\CronController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - SanitaryClockSweepEndpointTest (T-PAUSE-38)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router       = AppRouter::create();
$incidentRepo = new PdoIncidentRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);

$cronSecret  = SecretProvider::cronSecret();
$sweepPath   = '/api/cron/sanitary-clock-sweep';

$failures   = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

// ─── Inventario del parque que la suite va a tocar (restituido en el finally) ──

/** @var array<int, string> Estado sanitario de TODO el parque al arrancar la suite. */
$sanitarySnapshot = [];
foreach ($pdo->query('SELECT `id`, `sanitary_status` FROM `machines` WHERE `deleted_at` IS NULL') as $row) {
    $sanitarySnapshot[(int)$row['id']] = (string)$row['sanitary_status'];
}

/**
 * Máquinas libres de una tipología (sin expediente vivo) con su sede.
 *
 * @return list<array{machine_id: int, location_id: int, code: string, machine_type: string}>
 */
$freeMachinesByType = function (string $machineType, int $limit = 3) use ($pdo): array {
    $stmt = $pdo->prepare(
        "SELECT m.`id` AS machine_id, m.`location_id`, m.`code`, m.`machine_type`
           FROM `machines` m
           JOIN `locations` l ON l.`id` = m.`location_id`
          WHERE m.`is_active` = 1
            AND m.`is_blocked_no_access` = 0
            AND m.`deleted_at` IS NULL
            AND l.`is_active` = 1
            AND l.`deleted_at` IS NULL
            AND m.`machine_type` = :machine_type
            AND NOT EXISTS (
                SELECT 1 FROM `incidents` i
                 WHERE i.`machine_id` = m.`id`
                   AND i.`deleted_at` IS NULL
                   AND i.`status` NOT IN ('CLOSED', 'CANCELLED')
            )
          ORDER BY m.`id`
          LIMIT {$limit}"
    );
    $stmt->execute([':machine_type' => $machineType]);

    return array_map(
        static fn (array $row): array => [
            'machine_id'   => (int)$row['machine_id'],
            'location_id'  => (int)$row['location_id'],
            'code'         => (string)$row['code'],
            'machine_type' => (string)$row['machine_type'],
        ],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
};

$perishablePool = $freeMachinesByType('PERISHABLE_FOOD');
$comboPool      = $freeMachinesByType('COMBO');
$coldPool       = $freeMachinesByType('COLD_DRINKS');
$snackPool      = $freeMachinesByType('SNACKS');

$technicianRows = $pdo->query(
    "SELECT `id` FROM `users`
      WHERE `role` = 'TECHNICIAN' AND `deleted_at` IS NULL
      ORDER BY `id` LIMIT 1"
)->fetchAll(PDO::FETCH_COLUMN);

$technician  = $userRepo->findById((int)($technicianRows[0] ?? 0));
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

echo "--- Caso 0: Banco de pruebas ---\n";

$assert(
    '0.1 El parque expone tipologías libres suficientes (perecedera, mixta, fría y snacks)',
    count($perishablePool) >= 1 && count($comboPool) >= 1 && count($coldPool) >= 1 && count($snackPool) >= 1,
    sprintf(
        'perecederas=%d combos=%d frías=%d snacks=%d',
        count($perishablePool),
        count($comboPool),
        count($coldPool),
        count($snackPool)
    )
);

$assert(
    '0.2 Semilla disponible: técnico de ruta y coordinador de operaciones',
    $technician instanceof User && $coordinator instanceof User
);

if (!$technician instanceof User || !$coordinator instanceof User
    || count($perishablePool) < 1 || count($comboPool) < 1
    || count($coldPool) < 1 || count($snackPool) < 1) {
    echo "ERROR FATAL: el banco de pruebas no está disponible.\n";
    exit(1);
}

$techId           = (int)$technician->getId();
$coordinatorId    = (int)$coordinator->getId();
$technicianToken  = $authService->generateInternalToken($technician);
$coordinatorToken = $authService->generateInternalToken($coordinator);
$authHeader       = static fn (string $token): array => ['Authorization' => 'Bearer ' . $token];

// ─── Instrumentos de siembra y de medición ────────────────────────────────────

$sequence = 0;
$suffix   = substr((string)time(), -5);

/** @return int Id del expediente creado en IN_PROGRESS y asignado al técnico. */
$createInProgressTicket = function (array $machine) use ($incidentRepo, $techId, $coordinatorId, $suffix, &$sequence): int {
    $sequence++;
    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: sprintf('TST-P38-%s-%02d', $suffix, $sequence),
        machineId: $machine['machine_id'],
        locationId: $machine['location_id'],
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'Expediente del barrido sanitario de fondo (T-PAUSE-38).',
        urgency: UrgencyLevel::CRITICAL,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentId = (int)$created->getId();
    $incidentRepo->assign($incidentId, $techId, $coordinatorId);
    $incidentRepo->startIntervention($incidentId, $techId);

    return $incidentId;
};

/** Envejece la apertura del expediente propio (instrumento de la suite). */
$backdateOpening = function (int $incidentId, int $hours) use ($pdo): void {
    $pdo->prepare('UPDATE `incidents` SET `created_at` = NOW() - INTERVAL :hours HOUR WHERE `id` = :id')
        ->execute([':hours' => $hours, ':id' => $incidentId]);
};

$incidentRow = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incidents` WHERE `id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$machineRow = function (int $machineId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT `id`, `code`, `machine_type`, `sanitary_status` FROM `machines` WHERE `id` = :id');
    $stmt->execute([':id' => $machineId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$historyCount = function (int $incidentId) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `incident_history` WHERE `incident_id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return (int)$stmt->fetchColumn();
};

/**
 * Cursor de auditoría: `audit_log` es append-only y el arnés NO la purga entre
 * suites, así que todo recuento de eventos se mide por delta sobre esta foto
 * (precedente ratificado en T-PAUSE-13/14 y declarado en T-PAUSE-21).
 */
$auditCursor = static function () use ($pdo): int {
    return (int)$pdo->query('SELECT COALESCE(MAX(`id`), 0) FROM `audit_log`')->fetchColumn();
};

/** @return list<array<string, mixed>> Eventos de auditoría nuevos desde el cursor. */
$auditEvents = function (string $entityType, int $entityId, string $action, int $sinceId = 0) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT `action`, `entity_type`, `entity_id`, `user_id`, `user_role`, `new_state`, `metadata`, `created_at`
           FROM `audit_log`
          WHERE `entity_type` = :entity_type AND `entity_id` = :entity_id AND `action` = :action
            AND `id` > :since_id
          ORDER BY `id` ASC'
    );
    $stmt->execute([':entity_type' => $entityType, ':entity_id' => $entityId, ':action' => $action, ':since_id' => $sinceId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$sweep = function (array $headers) use ($router, $sweepPath) {
    return $router->dispatch(new Request(method: 'POST', path: $sweepPath, headers: $headers));
};

try {
    // =====================================================================
    // CASO 1: Seguridad y control de acceso del proceso batch
    // =====================================================================
    echo "\n--- Caso 1: Seguridad y control de acceso ---\n";

    $noAuth = $router->dispatch(new Request(method: 'POST', path: $sweepPath));
    $assert(
        '1.1 Sin credencial => 401 UNAUTHORIZED',
        $noAuth->getStatusCode() === 401 && ($noAuth->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED'
    );

    $badSecret = $sweep(['X-Cron-Secret' => 'secreto_falso_123']);
    $assert('1.2 X-Cron-Secret incorrecto => 401', $badSecret->getStatusCode() === 401);

    $technicianAttempt = $sweep($authHeader($technicianToken));
    $assert('1.3 Token de técnico no autorizado para el barrido => 401', $technicianAttempt->getStatusCode() === 401);

    $failClosed = (new CronController($incidentRepo))->sweepSanitaryClock(
        new Request(method: 'POST', path: $sweepPath, headers: ['X-Cron-Secret' => $cronSecret])
    );
    $assert(
        '1.4 El controlador sin la marca del middleware => 401 (fail-closed)',
        $failClosed->getStatusCode() === 401 && ($failClosed->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED'
    );

    $coordinatorSweep = $sweep($authHeader($coordinatorToken));
    $assert(
        '1.5 La sesión de coordinación puede lanzar el barrido => 200',
        $coordinatorSweep->getStatusCode() === 200,
        'Código: ' . $coordinatorSweep->getStatusCode()
    );

    // =====================================================================
    // CASO 2: La desviación que se cierra — despertar sin intento de cierre
    // =====================================================================
    echo "\n--- Caso 2: Cuarentena automática sin intento de cierre (RF-03.5.1) ---\n";

    $perishable  = $perishablePool[0];
    $perishableId = $perishable['machine_id'];

    $sanitaryTicketId = $createInProgressTicket($perishable);
    $backdateOpening($sanitaryTicketId, 5);

    $historyBefore = $historyCount($sanitaryTicketId);
    $assert(
        '2.0 Premisa del banco: 5 h naturales de avería, sin cuarentena y sin ningún intento de cierre',
        ($machineRow($perishableId)['sanitary_status'] ?? '') === 'OK'
        && ($incidentRow($sanitaryTicketId)['status'] ?? '') === 'IN_PROGRESS'
        && ((new DateTimeImmutable((string)($incidentRow($sanitaryTicketId)['created_at'] ?? 'now')))->getTimestamp() <= time() - 14400)
    );

    $auditBeforeSweep = $auditCursor();
    $sweepResponse = $sweep(['X-Cron-Secret' => $cronSecret]);
    $sweepBody = $sweepResponse->getDecodedBody();
    $sweepData = $sweepBody['data'] ?? [];

    $assert(
        '2.1 POST /api/cron/sanitary-clock-sweep con el secreto del proceso => 200 y success true',
        $sweepResponse->getStatusCode() === 200 && ($sweepBody['success'] ?? false) === true,
        'Código: ' . $sweepResponse->getStatusCode()
    );

    $assert(
        '2.2 El barrido publica exactamente las claves del contrato documentado',
        array_keys($sweepData) === [
            'candidates',
            'evaluated',
            'quarantined',
            'skipped',
            'failures',
            'failures_count',
            'contended',
            'contended_count',
            'threshold_seconds',
            'duration_ms',
        ],
        json_encode(array_keys($sweepData), JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '2.2.bis El resumen cuadra sobre datos reales: candidates = evaluated + fallos + aplazados',
        ($sweepData['contended'] ?? null) === []
        && ($sweepData['contended_count'] ?? -1) === 0
        && ($sweepData['evaluated'] + $sweepData['failures_count'] + $sweepData['contended_count']) === $sweepData['candidates'],
        json_encode($sweepData, JSON_UNESCAPED_UNICODE)
    );

    $quarantinedMachines = array_column($sweepData['quarantined'] ?? [], 'machine_id');
    $assert(
        '2.3 El resumen declara la máquina recién puesta en cuarentena, con su ticket',
        ($sweepData['candidates'] ?? 0) >= 1
        && in_array($perishableId, array_map('intval', $quarantinedMachines), true)
        && ($sweepData['threshold_seconds'] ?? 0) === 14400
        && ($sweepData['failures_count'] ?? -1) === 0,
        json_encode($sweepData, JSON_UNESCAPED_UNICODE)
    );

    $postSweepMachine = $machineRow($perishableId);
    $assert(
        '2.4 La máquina queda en QUARANTINE sin que nadie haya resuelto ni cerrado la avería',
        ($postSweepMachine['sanitary_status'] ?? '') === 'QUARANTINE'
        && ($incidentRow($sanitaryTicketId)['status'] ?? '') === 'IN_PROGRESS',
        json_encode($postSweepMachine, JSON_UNESCAPED_UNICODE)
    );

    $clockEvents = $auditEvents('MACHINE', $perishableId, 'SANITARY_QUARANTINE_AUTO_TRIGGERED', $auditBeforeSweep);
    $clockState = json_decode((string)($clockEvents[0]['new_state'] ?? '{}'), true) ?: [];
    $assert(
        '2.5 Se escribió exactamente un evento inmutable, con el actor del sistema y el checklist exigido',
        count($clockEvents) === 1
        && ($clockEvents[0]['user_role'] ?? '') === 'SYSTEM'
        && ($clockState['sanitary_status'] ?? '') === 'QUARANTINE'
        && ($clockState['sanitary_checklist_required'] ?? false) === true
        && str_contains((string)($clockEvents[0]['metadata'] ?? ''), '14400')
        && str_contains((string)($clockEvents[0]['metadata'] ?? ''), (string)$sanitaryTicketId),
        json_encode($clockEvents, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '2.6 El barrido no escribe historial del expediente: la transición es de máquina, no de ticket',
        $historyCount($sanitaryTicketId) === $historyBefore
    );

    $qrResponse = $router->dispatch(new Request(method: 'GET', path: '/api/qr/scan/' . $perishable['code']));
    $qrData = $qrResponse->getDecodedBody()['data'] ?? [];
    $assert(
        '2.7 El canal ciudadano ya publica el bloqueo por riesgo térmico (Art. II, RF-03.5.1)',
        $qrResponse->getStatusCode() === 200
        && ($qrData['status_mode'] ?? '') === 'SANITARY_QUARANTINE'
        && ($qrData['can_report'] ?? true) === false
        && ($qrData['alert']['thermal_risk_label'] ?? '') === 'Riesgo térmico: máquina en cuarentena preventiva'
        && ($qrData['active_incident']['ticket_code'] ?? '') === (string)($incidentRow($sanitaryTicketId)['ticket_code'] ?? ''),
        json_encode(['status_mode' => $qrData['status_mode'] ?? null, 'alert' => $qrData['alert'] ?? null], JSON_UNESCAPED_UNICODE)
    );

    $gateResponse = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/technician/incidents/' . $sanitaryTicketId . '/resolve',
        parsedBody: [
            'resolution_diagnosis' => 'Termostato descalibrado con lectura real de 11 grados en el recinto.',
            'resolution_action'    => 'Sustituida sonda NTC y estabilizado el ciclo de frío a 3,5 grados.',
        ],
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '2.8 La puerta sanitaria del cierre sigue mordiendo (422 SANITARY_CHECKLIST_REQUIRED, Art. II)',
        $gateResponse->getStatusCode() === 422
        && ($gateResponse->getDecodedBody()['error']['code'] ?? '') === 'SANITARY_CHECKLIST_REQUIRED'
        && ($incidentRow($sanitaryTicketId)['status'] ?? '') === 'IN_PROGRESS'
    );

    // =====================================================================
    // CASO 3: Idempotencia del barrido
    // =====================================================================
    echo "\n--- Caso 3: Idempotencia ---\n";

    $auditBeforeSecond = $auditCursor();
    $secondSweep = $sweep(['X-Cron-Secret' => $cronSecret]);
    $secondData = $secondSweep->getDecodedBody()['data'] ?? [];
    $secondQuarantined = array_map('intval', array_column($secondData['quarantined'] ?? [], 'machine_id'));

    $assert(
        '3.1 El segundo barrido no vuelve a declarar la máquina ya en cuarentena',
        $secondSweep->getStatusCode() === 200
        && !in_array($perishableId, $secondQuarantined, true)
        && ($secondData['failures_count'] ?? -1) === 0,
        json_encode($secondData, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '3.2 La segunda pasada no añade ni un solo evento de cuarentena para esa máquina',
        $auditEvents('MACHINE', $perishableId, 'SANITARY_QUARANTINE_AUTO_TRIGGERED', $auditBeforeSecond) === []
    );

    // =====================================================================
    // CASO 4: La mixta también está bajo vigilancia (RF-03.4, T-PAUSE-26)
    // =====================================================================
    echo "\n--- Caso 4: Vigilancia de la máquina mixta ---\n";

    $combo = $comboPool[0];
    $comboTicketId = $createInProgressTicket($combo);
    $backdateOpening($comboTicketId, 5);

    $auditBeforeCombo = $auditCursor();
    $comboSweep = $sweep(['X-Cron-Secret' => $cronSecret]);
    $comboData = $comboSweep->getDecodedBody()['data'] ?? [];

    $assert(
        '4.1 La mixta COMBO con 5 h naturales entra en cuarentena por el barrido',
        ($machineRow($combo['machine_id'])['sanitary_status'] ?? '') === 'QUARANTINE'
        && in_array($combo['machine_id'], array_map('intval', array_column($comboData['quarantined'] ?? [], 'machine_id')), true)
        && count($auditEvents('MACHINE', $combo['machine_id'], 'SANITARY_QUARANTINE_AUTO_TRIGGERED', $auditBeforeCombo)) === 1
    );

    // =====================================================================
    // CASO 5: Controles negativos
    // =====================================================================
    echo "\n--- Caso 5: Controles negativos ---\n";

    $cold = $coldPool[0];
    $coldTicketId = $createInProgressTicket($cold);
    $backdateOpening($coldTicketId, 6);

    $snack = $snackPool[0];
    $snackTicketId = $createInProgressTicket($snack);
    $backdateOpening($snackTicketId, 6);

    $youngTicketId = $createInProgressTicket($perishablePool[1] ?? $perishable);
    $backdateOpening($youngTicketId, 2);
    $youngMachineId = (int)($incidentRow($youngTicketId)['machine_id'] ?? 0);

    $settledTicketId = $createInProgressTicket($comboPool[1] ?? $combo);
    $pdo->prepare(
        "UPDATE `incidents`
            SET `status` = 'RESOLVED',
                `resolved_at` = NOW() - INTERVAL 6 HOUR,
                `resolution_diagnosis` = 'Compresor sin presión por fuga en la línea de baja.',
                `resolution_action` = 'Recargado el circuito y verificado el ciclo completo de frío.'
          WHERE `id` = :id"
    )->execute([':id' => $settledTicketId]);
    $backdateOpening($settledTicketId, 8);
    $settledMachineId = (int)($incidentRow($settledTicketId)['machine_id'] ?? 0);

    $auditBeforeControls = $auditCursor();
    $controlSweep = $sweep(['X-Cron-Secret' => $cronSecret]);
    $controlData = $controlSweep->getDecodedBody()['data'] ?? [];
    $controlQuarantined = array_map('intval', array_column($controlData['quarantined'] ?? [], 'machine_id'));

    $assert(
        '5.1 Las bebidas frías quedan fuera de la vigilancia sanitaria (6 h de avería)',
        ($machineRow($cold['machine_id'])['sanitary_status'] ?? '') === 'OK'
        && !in_array($cold['machine_id'], $controlQuarantined, true)
        && $auditEvents('MACHINE', $cold['machine_id'], 'SANITARY_QUARANTINE_AUTO_TRIGGERED', $auditBeforeControls) === []
    );

    $assert(
        '5.2 Los snacks tampoco entran en cuarentena por este reloj',
        ($machineRow($snack['machine_id'])['sanitary_status'] ?? '') === 'OK'
        && !in_array($snack['machine_id'], $controlQuarantined, true)
        && $auditEvents('MACHINE', $snack['machine_id'], 'SANITARY_QUARANTINE_AUTO_TRIGGERED', $auditBeforeControls) === []
    );

    $assert(
        '5.3 Una perecedera con 2 h naturales de avería no despierta todavía',
        ($machineRow($youngMachineId)['sanitary_status'] ?? '') === 'OK'
        && !in_array($youngMachineId, $controlQuarantined, true)
        && $auditEvents('MACHINE', $youngMachineId, 'SANITARY_QUARANTINE_AUTO_TRIGGERED', $auditBeforeControls) === []
    );

    $assert(
        '5.4 Un expediente liquidado (RESOLVED) de hace 8 h no dispara la cuarentena',
        ($machineRow($settledMachineId)['sanitary_status'] ?? '') === 'OK'
        && !in_array($settledMachineId, $controlQuarantined, true)
        && $auditEvents('MACHINE', $settledMachineId, 'SANITARY_QUARANTINE_AUTO_TRIGGERED', $auditBeforeControls) === [],
        json_encode($machineRow($settledMachineId), JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '5.5 El barrido sigue en verde y sin fallos con controles negativos en el lote',
        $controlSweep->getStatusCode() === 200 && ($controlData['failures_count'] ?? -1) === 0,
        json_encode($controlData, JSON_UNESCAPED_UNICODE)
    );

    // =====================================================================
    // CASO 6: Contrato del artefacto de línea de comandos
    // =====================================================================
    echo "\n--- Caso 6: Equivalente de línea de comandos ---\n";

    $cliPath = dirname(__DIR__, 2) . '/bin/sanitary_clock_sweep.php';
    $assert('6.1 El programador sin HTTP dispone de `bin/sanitary_clock_sweep.php`', is_file($cliPath));

    $cliSource = is_file($cliPath) ? (string)file_get_contents($cliPath) : '';
    $assert(
        '6.2 El CLI reutiliza el mismo servicio de barrido y no reimplementa la decisión',
        str_contains($cliSource, 'SanitaryClockSweepService')
        && str_contains($cliSource, '->sweep(')
        && !str_contains($cliSource, 'updateSanitaryStatus')
    );
    $assert(
        '6.3 El CLI declara fallo al planificador cuando el barrido reporta fallos reales',
        str_contains($cliSource, 'requiresOperatorAttention') && preg_match('/exit\(\s*\\$exitCode\s*\)/', $cliSource) === 1,
        'El CLI debe terminar en código distinto de cero con fallos reales'
    );

    $assert(
        '6.4 El CLI NO alerta por contención de fila y la informa como aplazamiento',
        str_contains($cliSource, 'contended')
        && str_contains($cliSource, 'hasContention')
        && !preg_match('/contendedCount\s*>\s*0\s*\?\s*1/', $cliSource),
        'El código de salida del CLI no puede decidirse por el recuento de contención'
    );

    // =====================================================================
    // CASO 7: Cota de espera de bloqueo de la sesión (T-PAUSE-40)
    // =====================================================================
    echo "\n--- Caso 7: Cota de espera de bloqueo de la sesión ---\n";

    $lockBudget = new PdoLockWaitBudget($pdo);
    $sessionValue = static fn (): int => (int)$pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
    $serverValue = $sessionValue();

    $insideBudget = null;
    $budgetResult = $lockBudget->runWithLockWaitBudget(2, static function () use ($sessionValue, &$insideBudget): string {
        $insideBudget = $sessionValue();

        return 'operación acotada';
    });

    $assert(
        '7.1 Dentro de la cota la sesión espera lo pedido y al salir se restituye el valor anterior',
        $insideBudget === 2
        && $sessionValue() === $serverValue
        && $budgetResult === 'operación acotada',
        sprintf(
            'dentro=%s, antes=%d, después=%d',
            var_export($insideBudget, true),
            $serverValue,
            $sessionValue()
        )
    );

    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
    $insideWide = null;
    $lockBudget->runWithLockWaitBudget(100, static function () use ($sessionValue, &$insideWide): void {
        $insideWide = $sessionValue();
    });
    $afterWide = $sessionValue();
    $pdo->exec(sprintf('SET SESSION innodb_lock_wait_timeout = %d', $serverValue));

    $assert(
        '7.2 Una cota más ancha que la de la sesión no la eleva (la elección del operador se respeta)',
        $insideWide === 3 && $afterWide === 3,
        sprintf('dentro=%s, después=%d', var_export($insideWide, true), $afterWide)
    );

    $badBudgetMessage = null;
    try {
        $lockBudget->runWithLockWaitBudget(0, static fn (): string => 'no debería correr');
    } catch (InvalidArgumentException $exception) {
        $badBudgetMessage = $exception->getMessage();
    }

    $assert(
        '7.3 Una cota imposible se rechaza sin ejecutar la operación ni tocar la sesión',
        $badBudgetMessage !== null && $sessionValue() === $serverValue,
        (string)$badBudgetMessage
    );

    $sessionAfterFailure = null;
    try {
        $lockBudget->runWithLockWaitBudget(1, static function (): void {
            throw new RuntimeException('Fallo de la operación acotada (sonda).');
        });
    } catch (RuntimeException $exception) {
        $sessionAfterFailure = $sessionValue();
    }

    $assert(
        '7.4 Si la operación estalla, la cota se restituye igualmente',
        $sessionAfterFailure === $serverValue,
        sprintf('cota tras el fallo=%s (antes=%d)', var_export($sessionAfterFailure, true), $serverValue)
    );
} finally {
    // Restitución del estado sanitario de TODO el parque: el barrido escribe sobre
    // filas maestras que otras suites esperan intactas.
    if ($sanitarySnapshot !== []) {
        $restore = $pdo->prepare('UPDATE `machines` SET `sanitary_status` = :status WHERE `id` = :id');
        foreach ($sanitarySnapshot as $machineId => $status) {
            $restore->execute([':status' => $status, ':id' => $machineId]);
        }
    }
}

// ─── RESULTADO FINAL ──────────────────────────────────────────────────────────

echo "\n" . str_repeat('=', 70) . "\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-38 (INTEGRACIÓN) CUMPLIDA.\n";
} else {
    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
}
echo str_repeat('=', 70) . "\n";

exit($failures > 0 ? 1 : 0);
