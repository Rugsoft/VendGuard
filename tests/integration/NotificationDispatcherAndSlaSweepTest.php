<?php

declare(strict_types=1);

/**
 * NotificationDispatcherAndSlaSweepTest
 *
 * Certificación de integración de T-NOTIF-07 sobre el motor único PostgreSQL:
 * el «Hecho cuando» exige que `NotificationDispatcherService` ejecute las cuatro
 * fases atómicas de la pasada en segundo plano:
 *
 *   1. Rescate de filas atrapadas en `PROCESSING` más de 5 minutos (RF-01.4).
 *   2. Vigilancia 24/7 de los umbrales de SLA sanitario de 2h sin asignar y 3h
 *      sin iniciar, con sellado `sla_2h_alert_sent_at` / `sla_3h_alert_sent_at`
 *      y reinicio del ciclo tras la reapertura en garantía (RF-04.1 a RF-04.5).
 *   3. Reclamo del lote con prioridad sanitaria estricta (RF-01.3, Art. II).
 *   4. Despacho con verificación previa de obsolescencia, backoff progresivo de
 *      5 intentos y paso a `FAILED_PERMANENT` con auditoría (RF-06.1, RF-06.2,
 *      RF-07.1).
 *
 * Las filas se leen del PostgreSQL real y los transportes son el adaptador
 * determinista del arnés (`InMemoryNotificationTransport`): se certifica el
 * estado que quedará en la bandeja de salida sin abrir un solo socket.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\NotificationDispatcherService;
use VendGuard\Application\Service\NotificationOutboxEnqueuerService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Notification\Transport\InMemoryNotificationTransport;
use VendGuard\Infrastructure\Notification\Transport\NotificationTransportRegistry;
use VendGuard\Infrastructure\Notification\Transport\TransportDeliveryResult;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - NotificationDispatcherAndSlaSweepTest (T-NOTIF-07)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

$outbox = new PdoNotificationOutboxRepository($pdo);
$settings = new PdoNotificationSettingsRepository($pdo, $outbox);
$locations = new PdoLocationRepository($pdo);
$users = new PdoUserRepository($pdo);
$machines = new PdoMachineRepository($pdo);
$incidents = new PdoIncidentRepository($pdo);
$transactions = new PdoTransactionManager($pdo);

$enqueuer = new NotificationOutboxEnqueuerService(
    outbox: $outbox,
    settings: $settings,
    locations: $locations,
    users: $users,
    onCallTechnicians: $users,
    machines: $machines,
    incidents: $incidents,
    cycles: $incidents,
    transactions: $transactions
);

$emailTransport = new InMemoryNotificationTransport(NotificationChannel::EMAIL);
$webhookTransport = new InMemoryNotificationTransport(NotificationChannel::WEBHOOK);
$transports = new NotificationTransportRegistry($emailTransport, $webhookTransport);

$dispatcher = new NotificationDispatcherService(
    outbox: $outbox,
    incidents: $incidents,
    users: $users,
    settings: $settings,
    cycles: $incidents,
    slaWatch: $incidents,
    slaAlerts: $enqueuer,
    transports: $transports,
    transactions: $transactions,
    audit: new AuditLogger()
);

$failures = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
    }
};

$coordinatorRows = $users->findAll(['status' => 'active', 'role' => 'COORDINATOR']);
$coordinatorEmail = strtolower((string)($coordinatorRows[0]['email'] ?? ''));
$coordinatorId = (int)($coordinatorRows[0]['id'] ?? 1);
$technician = $users->findById(2, true);
$technicianEmail = $technician !== null ? strtolower($technician->getEmail()) : '';

$assert('El escenario dispone de coordinador activo con correo válido', $coordinatorEmail !== '');
$assert('El escenario dispone de técnico activo con correo válido', $technicianEmail !== '');

$current = $settings->findCurrent();
$current->updateConfiguration('Canal de certificación T-NOTIF-07', 'https://emergencias.example.org/hook-tnotif07', true, $coordinatorId);
$settings->save($current);

$sanitaryMachines = $pdo->query("
    SELECT m.id
    FROM machines m
    JOIN locations l ON l.id = m.location_id
    WHERE m.deleted_at IS NULL
      AND m.is_active = TRUE
      AND l.is_active = TRUE
      AND l.deleted_at IS NULL
      AND m.machine_type IN ('PERISHABLE_FOOD', 'COMBO')
    ORDER BY m.id ASC
")->fetchAll(PDO::FETCH_COLUMN);

$assert('El parque sembrado ofrece máquinas bajo vigilancia sanitaria para el reloj de SLA', count($sanitaryMachines) >= 4);

/** @var list<int> $availableMachines */
$availableMachines = array_map('intval', $sanitaryMachines);

/**
 * Crea un expediente en una máquina sanitaria libre y lo lleva al estado y al
 * ciclo indicados por SQL directo (la suite controla el reloj, no el servicio).
 */
$newSanitaryIncident = function (
    string $status,
    ?int $technicianId,
    int $cycleMinutesAgo,
    ?string $startedAt = null
) use (&$availableMachines, $pdo, $incidents, $machines, $locations): Incident {
    if ($availableMachines === []) {
        throw new RuntimeException('El banco sanitario de la suite se agotó.');
    }

    $machineId = (int)array_shift($availableMachines);
    $machine = $machines->findById($machineId);
    $location = $locations->findById($machine->getLocationId());

    $incident = $incidents->create(new Incident(
        id: null,
        ticketCode: TicketCode::generate()->value(),
        machineId: $machine->getId(),
        locationId: $location->getId(),
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'Avería de certificación del despachador y vigilante (T-NOTIF-07).',
        urgency: UrgencyLevel::CRITICAL,
        status: IncidentStatus::REGISTERED,
        reporterName: 'Informador de Certificación',
        reporterPhone: '600333444'
    ), null, 'Alta de certificación T-NOTIF-07');

    // `NOW() - make_interval(mins => N)` en lugar de una cadena de intervalo: PostgreSQL
    // interpreta «-2 hours 5 minutes» como -1 h 55 min (el signo sólo afecta al primer
    // campo), y un banco de pruebas que se equivoca en el reloj certifica lo que no es.
    $stmt = $pdo->prepare("
        UPDATE incidents
        SET status = :status,
            assigned_technician_id = :technician_id,
            cycle_started_at = NOW() - make_interval(mins => :cycle_minutes),
            started_at = :started_at,
            sla_2h_alert_sent_at = NULL,
            sla_3h_alert_sent_at = NULL
        WHERE id = :id
    ");
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':technician_id', $technicianId, $technicianId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':cycle_minutes', $cycleMinutesAgo, PDO::PARAM_INT);
    $stmt->bindValue(':started_at', $startedAt, $startedAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':id', (int)$incident->getId(), PDO::PARAM_INT);
    $stmt->execute();

    $fresh = $incidents->findById((int)$incident->getId());
    if ($fresh === null) {
        throw new RuntimeException('El expediente de certificación no pudo releerse.');
    }

    return $fresh;
};

$fetchRows = function (int $incidentId, string $template) use ($pdo): array {
    $stmt = $pdo->prepare(
        "SELECT * FROM notification_outbox
         WHERE incident_id = :incident_id AND template = :template AND deleted_at IS NULL
         ORDER BY id ASC"
    );
    $stmt->execute([':incident_id' => $incidentId, ':template' => $template]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$outboxRow = function (int $id) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM notification_outbox WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        throw new RuntimeException("La fila {$id} de la bandeja no existe.");
    }

    return $row;
};

$enqueueSimple = function (
    NotificationChannel $channel,
    NotificationPriority $priority,
    NotificationTemplate $template,
    string $recipient,
    string $role,
    ?int $incidentId = null,
    ?int $recipientUserId = null,
    ?int $recipientLocationId = null
) use ($outbox): NotificationOutboxItem {
    $item = $outbox->enqueue(new NotificationOutboxItem(
        id: null,
        incidentId: $incidentId,
        channel: $channel,
        priority: $priority,
        template: $template,
        recipient: $recipient,
        recipientRole: $role,
        recipientUserId: $recipientUserId,
        recipientLocationId: $recipientLocationId,
        subject: 'Certificación T-NOTIF-07 · ' . $template->value,
        payload: ['ticket_code' => 'TICK-NOTIF07']
    ));

    if ($item === null || $item->getId() === null) {
        throw new RuntimeException('No se pudo encolar la notificación de certificación.');
    }

    return $item;
};

// ─────────────────────────────────────────────────────────────────────────────
// 1. RF-01.3 / Art. II — Prioridad sanitaria estricta en el orden de despacho
// ─────────────────────────────────────────────────────────────────────────────
$standardItem = $enqueueSimple(
    NotificationChannel::EMAIL,
    NotificationPriority::STANDARD,
    NotificationTemplate::INCIDENT_OPENED_SITE,
    'ordinaria.tnotif07@example.org',
    'LOCATION'
);
$criticalItem = $enqueueSimple(
    NotificationChannel::EMAIL,
    NotificationPriority::CRITICAL_SANITARY,
    NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT,
    'critica.tnotif07@example.org',
    'COORDINATOR',
    null,
    $coordinatorId
);

$firstPass = $dispatcher->runPass(1);
$secondPass = $dispatcher->runPass(1);

$assert(
    'RF-01.3: la primera plaza de la cola es para la emergencia sanitaria (Art. II)',
    $emailTransport->deliveredRecipients()[0] === 'critica.tnotif07@example.org',
    'Destinatarios entregados: ' . implode(', ', $emailTransport->deliveredRecipients())
);
$assert(
    'El aviso ordinario sale después, sin adelantar a la alerta crítica',
    $emailTransport->deliveredRecipients()[1] === 'ordinaria.tnotif07@example.org'
);
$assert(
    'Ambos envíos quedan en SENT con su processed_at',
    (string)$outboxRow((int)$criticalItem->getId())['status'] === 'SENT'
        && (string)$outboxRow((int)$standardItem->getId())['status'] === 'SENT'
        && $outboxRow((int)$criticalItem->getId())['processed_at'] !== null
);
$assert('El resumen declara las dos entregas del lote', $firstPass->sentCount === 1 && $secondPass->sentCount === 1);

// ─────────────────────────────────────────────────────────────────────────────
// 2. RF-01.4 — Rescate automático de filas atrapadas en PROCESSING > 5 minutos
// ─────────────────────────────────────────────────────────────────────────────
$insertStuck = function (string $recipient, int $attempts) use ($pdo): int {
    $stmt = $pdo->prepare("
        INSERT INTO notification_outbox (
            channel, priority, template, recipient, recipient_role,
            subject, payload_json, status, attempts, max_attempts,
            next_attempt_at, processing_started_at
        ) VALUES (
            'EMAIL', 'STANDARD', 'INCIDENT_OPENED_SITE', :recipient, 'LOCATION',
            'Rescate de certificación T-NOTIF-07', '{}'::jsonb, 'PROCESSING', :attempts, 5,
            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP - INTERVAL '6 minutes'
        )
        RETURNING id
    ");
    $stmt->bindValue(':recipient', $recipient, PDO::PARAM_STR);
    $stmt->bindValue(':attempts', $attempts, PDO::PARAM_INT);
    $stmt->execute();

    return (int)$stmt->fetchColumn();
};

$rescuableId = $insertStuck('rescatable.tnotif07@example.org', 1);
$exhaustedId = $insertStuck('agotada.tnotif07@example.org', 4);

$rescuePass = $dispatcher->runPass(50);

$assert('RF-01.4: el rescate contabiliza las dos filas atrapadas', $rescuePass->rescuedStuckCount === 2);
$assert(
    'La fila rescatada con intentos disponibles vuelve a la cola y se entrega',
    (string)$outboxRow($rescuableId)['status'] === 'SENT'
        && (int)$outboxRow($rescuableId)['attempts'] === 3
);
$assert(
    'El quinto intento interrumpido cae a FAILED_PERMANENT sin volver a la cola',
    (string)$outboxRow($exhaustedId)['status'] === 'FAILED_PERMANENT'
        && (int)$outboxRow($exhaustedId)['attempts'] === 5
        && str_contains((string)$outboxRow($exhaustedId)['last_error'], 'rescate automático')
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. RF-04.1 / RF-04.4 — Umbral de 2h sin asignar, anti-spam y reinicio del ciclo
// ─────────────────────────────────────────────────────────────────────────────
$twoHourIncident = $newSanitaryIncident('REGISTERED', null, 125);
$twoHourId = (int)$twoHourIncident->getId();

$slaPass1 = $dispatcher->runPass(100);
$slaRows1 = $fetchRows($twoHourId, 'SLA_IMMINENCE_2H_UNASSIGNED');

$assert('RF-04.1: el umbral de 2h sin asignar emite su alerta', $slaPass1->sla2hAlertsEnqueued === 1 && $slaRows1 !== []);
$sla1Recipients = array_map(static fn(array $row): string => (string)$row['recipient'], $slaRows1);
$assert(
    'La alerta de 2h nace con prioridad CRITICAL_SANITARY y llega a Coordinación',
    $slaRows1 !== []
        && (string)$slaRows1[0]['priority'] === 'CRITICAL_SANITARY'
        && in_array($coordinatorEmail, $sla1Recipients, true)
);
$assert(
    'El canal corporativo recibe también la alerta de 2h',
    count(array_filter($slaRows1, static fn(array $row): bool => (string)$row['channel'] === 'WEBHOOK')) === 1
);
$assert(
    'RF-04.4: la marca sla_2h_alert_sent_at queda sellada en el ciclo',
    $incidents->findById($twoHourId)?->getSla2hAlertSentAt() !== null
);

$slaPass2 = $dispatcher->runPass(100);
$assert(
    'RF-04.4: una segunda pasada no repite la alerta ya sellada',
    $slaPass2->sla2hAlertsEnqueued === 0
        && count($fetchRows($twoHourId, 'SLA_IMMINENCE_2H_UNASSIGNED')) === count($slaRows1)
);

$stmt = $pdo->prepare("
    UPDATE incidents
    SET status = 'REOPENED',
        cycle_started_at = NOW() - make_interval(mins => 125),
        reopened_at = NOW(),
        sla_2h_alert_sent_at = NULL,
        sla_3h_alert_sent_at = NULL
    WHERE id = :id
");
$stmt->execute([':id' => $twoHourId]);

$slaPass3 = $dispatcher->runPass(100);
$assert(
    'RF-04.5: la reapertura en garantía inicia un ciclo nuevo y vuelve a alertar',
    $slaPass3->sla2hAlertsEnqueued === 1
        && count($fetchRows($twoHourId, 'SLA_IMMINENCE_2H_UNASSIGNED')) > count($slaRows1)
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. RF-04.2 — Umbral de 3h sin haber iniciado la intervención
// ─────────────────────────────────────────────────────────────────────────────
$threeHourIncident = $newSanitaryIncident('ASSIGNED', 2, 190);
$threeHourId = (int)$threeHourIncident->getId();

$threeHourPass = $dispatcher->runPass(100);
$threeHourRows = $fetchRows($threeHourId, 'SLA_IMMINENCE_3H_UNSTARTED');

$assert('RF-04.2: el umbral de 3h sin iniciar emite su alerta', $threeHourPass->sla3hAlertsEnqueued === 1 && $threeHourRows !== []);
$assert(
    'La alerta de 3h llega al técnico asignado con su correo real',
    in_array($technicianEmail, array_map(static fn(array $row): string => (string)$row['recipient'], $threeHourRows), true)
);
$assert(
    'La alerta de 3h llega también a Coordinación y al canal corporativo',
    in_array($coordinatorEmail, array_map(static fn(array $row): string => (string)$row['recipient'], $threeHourRows), true)
        && count(array_filter($threeHourRows, static fn(array $row): bool => (string)$row['channel'] === 'WEBHOOK')) === 1
);
$assert(
    'RF-04.4: la marca sla_3h_alert_sent_at queda sellada y no se repite',
    $incidents->findById($threeHourId)?->getSla3hAlertSentAt() !== null
        && $dispatcher->runPass(100)->sla3hAlertsEnqueued === 0
);
$assert(
    'Un ticket ya asignado no dispara la alerta de 2h',
    $fetchRows($threeHourId, 'SLA_IMMINENCE_2H_UNASSIGNED') === []
);

// ─────────────────────────────────────────────────────────────────────────────
// 4.1 RF-04.3 — Ventana nocturna: el umbral se mide en tiempo natural 24/7
// ─────────────────────────────────────────────────────────────────────────────
// El ciclo arranca a las 00:20 y la pasada se evalúa a las 03:25. Si la regla
// consultase un calendario comercial —como hace el módulo de pausas— esas tres
// horas de madrugada no contarían y la alerta no existiría. Ambas referencias se
// fijan en hora de Madrid, la del arnés, para que el cálculo no dependa del huso
// con el que el motor escriba la columna.
$madrid = new DateTimeZone('Europe/Madrid');
$nightDate = (new DateTimeImmutable('today', $madrid))->format('Y-m-d');
$nightNow = new DateTimeImmutable($nightDate . ' 03:25:00', $madrid);
// La columna es `timestamptz` y el motor evalúa en UTC: el inicio del ciclo se
// escribe con su desplazamiento explícito (+02:00 en octubre) para que el
// instante absoluto sea el de la madrugada de Madrid y no el de la madrugada UTC.
$nightCycleStart = (new DateTimeImmutable($nightDate . ' 00:20:00', $madrid))->format('Y-m-d H:i:sP');

$nightIncident = $newSanitaryIncident('REGISTERED', null, 200);
$nightId = (int)$nightIncident->getId();

$nightStmt = $pdo->prepare("
    UPDATE incidents
    SET cycle_started_at = :cycle_started_at,
        sla_2h_alert_sent_at = NULL,
        sla_3h_alert_sent_at = NULL
    WHERE id = :id
");
$nightStmt->execute([':cycle_started_at' => $nightCycleStart, ':id' => $nightId]);

$nightPass = $dispatcher->runPass(100, $nightNow);
$nightTwoHourRows = $fetchRows($nightId, 'SLA_IMMINENCE_2H_UNASSIGNED');
$nightThreeHourRows = $fetchRows($nightId, 'SLA_IMMINENCE_3H_UNSTARTED');

$assert(
    'RF-04.3: un ciclo iniciado de madrugada también cruza el umbral de 2h',
    $nightPass->sla2hAlertsEnqueued === 1 && $nightTwoHourRows !== []
);
$assert(
    'RF-04.3: el umbral de 3h se evalúa en tiempo natural continuo, sin calendario comercial',
    $nightPass->sla3hAlertsEnqueued === 1 && $nightThreeHourRows !== []
);
$assert(
    'RF-04.3: la alerta nocturna de 2h sale con prioridad sanitaria y su sello de ciclo',
    (string)$nightTwoHourRows[0]['priority'] === 'CRITICAL_SANITARY'
        && $incidents->findById($nightId)?->getSla2hAlertSentAt() !== null
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. RF-07.1 — Obsolescencia evaluada justo antes del envío
// ─────────────────────────────────────────────────────────────────────────────
$obsoleteItem = $enqueueSimple(
    NotificationChannel::EMAIL,
    NotificationPriority::CRITICAL_SANITARY,
    NotificationTemplate::SLA_IMMINENCE_2H_UNASSIGNED,
    'obsoleta.tnotif07@example.org',
    'COORDINATOR',
    $threeHourId,
    $coordinatorId
);

$obsoletePass = $dispatcher->runPass(100);
$obsoleteRow = $outboxRow((int)$obsoleteItem->getId());

$assert('RF-07.1: la alerta de 2h ya sin objeto se cancela sin salir', (string)$obsoleteRow['status'] === 'CANCELLED_OBSOLETE');
$assert(
    'El motivo exacto de obsolescencia queda documentado en la fila',
    str_contains((string)$obsoleteRow['obsolete_reason'], 'assigned technician')
);
$assert(
    'El destinatario obsoleto nunca recibió el mensaje',
    !in_array('obsoleta.tnotif07@example.org', $emailTransport->deliveredRecipients(), true)
        && $obsoletePass->cancelledObsoleteCount === 1
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. RF-06.1 / RF-06.2 — Backoff progresivo y paso a fallo definitivo con auditoría
// ─────────────────────────────────────────────────────────────────────────────
$backoffRecipient = 'rebote.tnotif07@example.org';
$backoffItem = $enqueueSimple(
    NotificationChannel::EMAIL,
    NotificationPriority::STANDARD,
    NotificationTemplate::INCIDENT_OPENED_SITE,
    $backoffRecipient,
    'LOCATION'
);
$backoffId = (int)$backoffItem->getId();

$emailTransport->scriptOutcomes(
    $backoffRecipient,
    TransportDeliveryResult::transientFailure('SMTP 451 greylisting temporal'),
    TransportDeliveryResult::transientFailure('SMTP 451 greylisting temporal'),
    TransportDeliveryResult::transientFailure('SMTP 451 greylisting temporal'),
    TransportDeliveryResult::transientFailure('SMTP 451 greylisting temporal'),
    TransportDeliveryResult::transientFailure('SMTP 451 greylisting temporal')
);

$forceReady = function (int $id, ?int $attempts = null) use ($pdo): void {
    $sql = "UPDATE notification_outbox SET next_attempt_at = CURRENT_TIMESTAMP - INTERVAL '1 second'";
    if ($attempts !== null) {
        $sql .= ', attempts = :attempts';
    }
    $sql .= ' WHERE id = :id';

    $stmt = $pdo->prepare($sql);
    if ($attempts !== null) {
        $stmt->bindValue(':attempts', $attempts, PDO::PARAM_INT);
    }
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
};

$backoffPass1 = $dispatcher->runPass(1);
$backoffRow1 = $outboxRow($backoffId);
$scheduledAt1 = new DateTimeImmutable((string)$backoffRow1['next_attempt_at']);
$delay1 = $scheduledAt1->getTimestamp() - (new DateTimeImmutable('now'))->getTimestamp();

$assert(
    'RF-06.1: el primer fallo transitorio programa PENDING_RETRY con su intento contabilizado',
    (string)$backoffRow1['status'] === 'PENDING_RETRY' && (int)$backoffRow1['attempts'] === 1
);
$assert('El primer backoff espera alrededor de 60 segundos', $delay1 >= 55 && $delay1 <= 65, "Retardo medido: {$delay1} s");
$assert('El resumen contabiliza la reprogramación', $backoffPass1->retryScheduledCount === 1);

$forceReady($backoffId);
$backoffPass2 = $dispatcher->runPass(1);
$backoffRow2 = $outboxRow($backoffId);
$scheduledAt2 = new DateTimeImmutable((string)$backoffRow2['next_attempt_at']);
$delay2 = $scheduledAt2->getTimestamp() - (new DateTimeImmutable('now'))->getTimestamp();

$assert(
    'El segundo fallo escala la espera a 5 minutos con dos intentos',
    (string)$backoffRow2['status'] === 'PENDING_RETRY'
        && (int)$backoffRow2['attempts'] === 2
        && $delay2 >= 295
        && $delay2 <= 305,
    "Retardo medido: {$delay2} s"
);
$assert('El diagnóstico del proveedor se conserva en last_error', str_contains((string)$backoffRow2['last_error'], '451'));

$forceReady($backoffId);
$backoffPass3 = $dispatcher->runPass(1);
$backoffRow3 = $outboxRow($backoffId);
$delay3 = (new DateTimeImmutable((string)$backoffRow3['next_attempt_at']))->getTimestamp()
    - (new DateTimeImmutable('now'))->getTimestamp();

$assert(
    'El tercer fallo escala la espera a 15 minutos con tres intentos',
    (string)$backoffRow3['status'] === 'PENDING_RETRY'
        && (int)$backoffRow3['attempts'] === 3
        && $delay3 >= 890
        && $delay3 <= 910,
    "Retardo medido: {$delay3} s"
);

$forceReady($backoffId);
$backoffPass4 = $dispatcher->runPass(1);
$backoffRow4 = $outboxRow($backoffId);
$delay4 = (new DateTimeImmutable((string)$backoffRow4['next_attempt_at']))->getTimestamp()
    - (new DateTimeImmutable('now'))->getTimestamp();

$assert(
    'El cuarto fallo escala la espera a 60 minutos con cuatro intentos',
    (string)$backoffRow4['status'] === 'PENDING_RETRY'
        && (int)$backoffRow4['attempts'] === 4
        && $delay4 >= 3590
        && $delay4 <= 3610,
    "Retardo medido: {$delay4} s"
);

$forceReady($backoffId);
$backoffPass5 = $dispatcher->runPass(1);
$backoffRow5 = $outboxRow($backoffId);
$auditFailures = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'NOTIFICATION_PERMANENT_FAILURE'")->fetchColumn();

$assert(
    'RF-06.2: el quinto intento pasa a FAILED_PERMANENT',
    (string)$backoffRow5['status'] === 'FAILED_PERMANENT' && (int)$backoffRow5['attempts'] === 5
);
$assert('RF-06.2: el fallo definitivo queda auditado en audit_log', $auditFailures >= 1);
$assert(
    'El resumen contabiliza el fallo definitivo tras recorrer la curva completa',
    $backoffPass3->retryScheduledCount === 1
        && $backoffPass4->retryScheduledCount === 1
        && $backoffPass5->failedPermanentCount === 1
);

// ─────────────────────────────────────────────────────────────────────────────
// 7. Identidad contable del resumen de la pasada
// ─────────────────────────────────────────────────────────────────────────────
$emptyPass = $dispatcher->runPass(10);
$assert(
    'El resumen de una pasada sin lote cuadra a cero y mide su duración',
    $emptyPass->claimedCount === 0
        && $emptyPass->sentCount === 0
        && $emptyPass->retryScheduledCount === 0
        && $emptyPass->failedPermanentCount === 0
        && $emptyPass->cancelledObsoleteCount === 0
        && $emptyPass->durationMs >= 0
);
$assert(
    'La proyección plana publica las claves del contrato de despacho',
    array_keys($emptyPass->toArray()) === [
        'rescued_stuck_count', 'sla_2h_alerts_enqueued', 'sla_3h_alerts_enqueued', 'claimed_count',
        'sent_count', 'retry_scheduled_count', 'failed_permanent_count', 'cancelled_obsolete_count', 'duration_ms',
    ]
);

// Censo del catálogo: el vigilante es la única vía de las dos plantillas de
// inminencia de SLA; el resto de las 19 las cubre la suite de encolado.
$slaTemplatesCertified = $pdo->query("
    SELECT DISTINCT template
    FROM notification_outbox
    WHERE deleted_at IS NULL
      AND template IN ('SLA_IMMINENCE_2H_UNASSIGNED', 'SLA_IMMINENCE_3H_UNSTARTED')
    ORDER BY template ASC
")->fetchAll(PDO::FETCH_COLUMN);
$assert(
    'RF-04: las dos plantillas de inminencia de SLA quedan certificadas por el vigilante',
    $slaTemplatesCertified === ['SLA_IMMINENCE_2H_UNASSIGNED', 'SLA_IMMINENCE_3H_UNSTARTED'],
    'Plantillas observadas: ' . implode(', ', $slaTemplatesCertified)
);

TestDataCleaner::purge($pdo);

echo "\n";
echo "──────────────────────────────────────────────────────────────────────\n";
echo sprintf(" Total Aserciones: %d | Fallos: %d\n", $assertions, $failures);
echo "──────────────────────────────────────────────────────────────────────\n";

exit($failures === 0 ? 0 : 1);
