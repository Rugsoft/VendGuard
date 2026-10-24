<?php

declare(strict_types=1);

/**
 * PdoNotificationOutboxRepositoryTest
 *
 * Certificación de integración de T-NOTIF-04 sobre el motor único PostgreSQL.
 *
 * Cubre los cinco criterios del «Hecho cuando»:
 *   1. Inserción idempotente por `dedup_key` y agrupación de ráfagas de
 *      `PENDING_INFO_SITE_REPLY` sobre la fila `PENDING` existente.
 *   2. Rescate atómico de filas atrapadas en `PROCESSING` más de cinco minutos,
 *      con contabilización del intento y paso a `FAILED_PERMANENT` en el quinto.
 *   3. Reclamo de lote con prioridad `CRITICAL_SANITARY` primero y
 *      `FOR UPDATE SKIP LOCKED`.
 *   4. Consultas paginadas/filtradas por los seis estados y contadores KPI.
 *   5. Cancelación lógica por obsolescencia de un destinatario interno y del
 *      canal Webhook (Art. III.1: nunca se borra físicamente).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - PdoNotificationOutboxRepositoryTest (T-NOTIF-04)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();

$repo = new PdoNotificationOutboxRepository($pdo);

$failures = 0;
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

$marker = 'TNOTIF04-' . time() . '-' . bin2hex(random_bytes(3));

$incidentRow = $pdo->query("
    SELECT i.id, i.ticket_code, i.location_id
    FROM incidents i
    WHERE i.deleted_at IS NULL
    ORDER BY i.id ASC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$createdIncidentId = null;

if ($incidentRow === false) {
    // El sembrado sólo deja maestras: la suite crea su propio expediente para
    // no depender de que otra prueba haya dejado una avería viva.
    $machine = $pdo->query("
        SELECT m.id, m.location_id
        FROM machines m
        WHERE m.deleted_at IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM incidents i
              WHERE i.machine_id = m.id AND i.deleted_at IS NULL
          )
        ORDER BY m.id ASC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if ($machine === false) {
        echo "  [ERROR] No hay máquina libre para crear el expediente de la prueba.\n";
        exit(1);
    }

    $ticketCode = 'INC-NOTIF04-' . time();
    $incident = (new PdoIncidentRepository($pdo))->create(
        new Incident(
            null,
            $ticketCode,
            (int)$machine['id'],
            (int)$machine['location_id'],
            IncidentCategory::fromString('PAYMENT_SYSTEM'),
            'Incidencia autocontenida de la suite T-NOTIF-04.',
            UrgencyLevel::fromString('MEDIUM'),
            IncidentStatus::REGISTERED
        ),
        null,
        'Expediente creado por la suite T-NOTIF-04'
    );

    $createdIncidentId = (int)$incident->getId();
    $incidentId = $createdIncidentId;
    $locationId = (int)$incident->getLocationId();
} else {
    $incidentId = (int)$incidentRow['id'];
    $ticketCode = (string)$incidentRow['ticket_code'];
    $locationId = (int)$incidentRow['location_id'];
}

$technician = $pdo->query("
    SELECT id, email
    FROM users
    WHERE role = 'TECHNICIAN' AND is_active = TRUE AND deleted_at IS NULL
    ORDER BY id ASC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if ($technician === false) {
    echo "  [ERROR] El sembrado no dejó ningún técnico activo para la prueba.\n";
    exit(1);
}

$technicianId = (int)$technician['id'];
$technicianEmail = (string)$technician['email'];
$locationEmail = (string)$pdo->query("SELECT contact_email FROM locations WHERE id = {$locationId}")->fetchColumn();

$resetOutbox = static function () use ($pdo): void {
    TestDataCleaner::withPurgeWindow($pdo, static function () use ($pdo): void {
        $pdo->exec('DELETE FROM notification_outbox');
    });
};

/**
 * @param array<string, mixed> $overrides
 */
$makeItem = static function (array $overrides = []) use (
    $marker,
    $incidentId,
    $technicianId,
    $technicianEmail,
    $locationId,
    $locationEmail
): NotificationOutboxItem {
    return new NotificationOutboxItem(
        id: null,
        incidentId: array_key_exists('incidentId', $overrides) ? $overrides['incidentId'] : $incidentId,
        channel: $overrides['channel'] ?? NotificationChannel::EMAIL,
        priority: $overrides['priority'] ?? NotificationPriority::STANDARD,
        template: $overrides['template'] ?? NotificationTemplate::TECHNICIAN_ASSIGNED,
        recipient: $overrides['recipient'] ?? $technicianEmail,
        recipientRole: $overrides['recipientRole'] ?? 'TECHNICIAN',
        recipientUserId: array_key_exists('recipientUserId', $overrides) ? $overrides['recipientUserId'] : $technicianId,
        recipientLocationId: array_key_exists('recipientLocationId', $overrides) ? $overrides['recipientLocationId'] : null,
        subject: $overrides['subject'] ?? "[TEST {$marker}] Aviso de asignación",
        payload: $overrides['payload'] ?? ['marker' => $marker],
        dedupKey: array_key_exists('dedupKey', $overrides) ? $overrides['dedupKey'] : null,
        status: $overrides['status'] ?? NotificationStatus::PENDING,
        attempts: $overrides['attempts'] ?? 0,
        maxAttempts: $overrides['maxAttempts'] ?? 5,
        nextAttemptAt: $overrides['nextAttemptAt'] ?? null,
        processingStartedAt: $overrides['processingStartedAt'] ?? null,
        processedAt: $overrides['processedAt'] ?? null,
        lastError: $overrides['lastError'] ?? null,
        obsoleteReason: $overrides['obsoleteReason'] ?? null
    );
};

// =====================================================================
// 1. Inserción idempotente por dedup_key
// =====================================================================
echo "--- 1. Encolado idempotente por dedup_key ---\n";
$resetOutbox();

$dedupKey = "inc:{$incidentId}:test:{$marker}";
$first = $repo->enqueue($makeItem(['dedupKey' => $dedupKey, 'subject' => "[TEST {$marker}] Primera entrega"]));
$second = $repo->enqueue($makeItem(['dedupKey' => $dedupKey, 'subject' => "[TEST {$marker}] Entrega duplicada"]));

$assert('1.1 La primera inserción devuelve la fila persistida', $first !== null && $first->getId() !== null);
$assert('1.2 La segunda inserción con el mismo dedup_key no crea fila', $second === null);
$assert(
    '1.3 Sólo existe una fila para la clave de deduplicación',
    (int)$pdo->query("SELECT COUNT(*) FROM notification_outbox WHERE dedup_key = " . $pdo->quote($dedupKey))->fetchColumn() === 1
);

// =====================================================================
// 2. Agrupación de ráfagas de respuesta de sede
// =====================================================================
echo "\n--- 2. Agrupación de ráfagas de PENDING_INFO_SITE_REPLY ---\n";
$resetOutbox();

$burstFirst = $repo->enqueue($makeItem([
    'template' => NotificationTemplate::PENDING_INFO_SITE_REPLY,
    'subject' => "[TEST {$marker}] Respuesta de sede: primero",
    'payload' => ['message' => 'El edificio abre a las 08:00', 'marker' => $marker],
]));
$burstSecond = $repo->enqueue($makeItem([
    'template' => NotificationTemplate::PENDING_INFO_SITE_REPLY,
    'subject' => "[TEST {$marker}] Respuesta de sede: segundo",
    'payload' => ['message' => 'Confirmado: acceda por la puerta lateral', 'marker' => $marker],
]));

$burstCount = (int)$pdo->query("
    SELECT COUNT(*) FROM notification_outbox
    WHERE incident_id = {$incidentId}
      AND template = 'PENDING_INFO_SITE_REPLY'
      AND recipient_user_id = {$technicianId}
")->fetchColumn();

$assert('2.1 La primera respuesta crea la notificación pendiente', $burstFirst !== null && $burstFirst->getId() !== null);
$assert('2.2 La segunda respuesta reutiliza la misma fila', $burstSecond !== null && $burstSecond->getId() === $burstFirst->getId());
$assert('2.3 No se duplica el correo en la ráfaga', $burstCount === 1, "filas encontradas: {$burstCount}");

$reloadedBurst = $repo->findById((int)($burstFirst?->getId() ?? 0));
$assert(
    '2.4 El contenido se actualiza con el último mensaje de la sede',
    $reloadedBurst !== null
        && ($reloadedBurst->getPayload()['message'] ?? null) === 'Confirmado: acceda por la puerta lateral'
        && str_contains($reloadedBurst->getSubject(), 'segundo')
);

// =====================================================================
// 3. Reclamo de lote por prioridad sanitaria y operativa
// =====================================================================
echo "\n--- 3. claimReadyBatch con prioridades CRITICAL_SANITARY, PRIORITY y SKIP LOCKED ---\n";
$resetOutbox();

$standard = $repo->enqueue($makeItem([
    'subject' => "[TEST {$marker}] Ordinaria",
    'priority' => NotificationPriority::STANDARD,
    'dedupKey' => "std:{$marker}",
]));
$priority = $repo->enqueue($makeItem([
    'subject' => "[TEST {$marker}] Relevo prioritario",
    'priority' => NotificationPriority::PRIORITY,
    'template' => NotificationTemplate::TELEGRAM_INCIDENT_ASSIGNMENT,
    'dedupKey' => "priority:{$marker}",
]));
$critical = $repo->enqueue($makeItem([
    'subject' => "[TEST {$marker}] Crítica sanitaria",
    'priority' => NotificationPriority::CRITICAL_SANITARY,
    'template' => NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT,
    'dedupKey' => "crit:{$marker}",
]));
$future = $repo->enqueue($makeItem([
    'subject' => "[TEST {$marker}] Programada en el futuro",
    'dedupKey' => "future:{$marker}",
    'nextAttemptAt' => new DateTimeImmutable('+1 hour'),
]));

// Con un lote de UNA plaza, la única fila reclamada debe ser la sanitaria
// aunque la ordinaria se creara antes y estuviera primero por calendario.
$criticalBatch = $repo->claimReadyBatch(1);
$remainingBatch = $repo->claimReadyBatch(5);

$assert('3.1 El lote de una plaza sólo trae la fila sanitaria', count($criticalBatch) === 1);
$assert(
    '3.2 La prioridad sanitaria va primero (Art. II)',
    ($criticalBatch[0] ?? null) !== null && $criticalBatch[0]->getId() === $critical?->getId(),
    'reclamada: ' . ($criticalBatch[0]?->getId() ?? '(ninguna)')
);
$assert('3.3 Las filas reclamadas quedan en PROCESSING', ($criticalBatch[0] ?? null)?->getStatus() === NotificationStatus::PROCESSING);
$assert(
    '3.4 El segundo lote entrega primero el relevo prioritario y después la ordinaria',
    count($remainingBatch) === 2
        && $remainingBatch[0]->getId() === $priority?->getId()
        && $remainingBatch[1]->getId() === $standard?->getId()
);
$assert(
    '3.5 La fila programada para el futuro no se reclama',
    $future !== null && $repo->findById((int)$future->getId())?->getStatus() === NotificationStatus::PENDING
);
$assert(
    '3.6 Las tres filas listas originales están en PROCESSING',
    $standard !== null && $repo->findById((int)$standard->getId())?->getStatus() === NotificationStatus::PROCESSING
        && $priority !== null && $repo->findById((int)$priority->getId())?->getStatus() === NotificationStatus::PROCESSING
        && $critical !== null && $repo->findById((int)$critical->getId())?->getStatus() === NotificationStatus::PROCESSING
);

// =====================================================================
// 4. Rescate de envíos atrapados en PROCESSING > 5 minutos
// =====================================================================
echo "\n--- 4. rescueStuckProcessingItems ---\n";
$resetOutbox();

$stuckRetry = $repo->enqueue($makeItem(['subject' => "[TEST {$marker}] Atrapada]", 'dedupKey' => "stuck:{$marker}"]));
$stuckLast = $repo->enqueue($makeItem([
    'subject' => "[TEST {$marker}] Atrapada en el quinto intento]",
    'dedupKey' => "stuck5:{$marker}",
    'attempts' => 4,
]));
$fresh = $repo->enqueue($makeItem(['subject' => "[TEST {$marker}] En curso reciente]", 'dedupKey' => "fresh:{$marker}"]));

$staleDate = $pdo->quote((new DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:sP'));
$pdo->exec("UPDATE notification_outbox SET status = 'PROCESSING', processing_started_at = {$staleDate} WHERE id = " . (int)($stuckRetry?->getId() ?? 0));
$pdo->exec("UPDATE notification_outbox SET status = 'PROCESSING', processing_started_at = {$staleDate} WHERE id = " . (int)($stuckLast?->getId() ?? 0));
$pdo->exec("UPDATE notification_outbox SET status = 'PROCESSING', processing_started_at = CURRENT_TIMESTAMP WHERE id = " . (int)($fresh?->getId() ?? 0));

$rescued = $repo->rescueStuckProcessingItems(5);

$retried = $repo->findById((int)($stuckRetry?->getId() ?? 0));
$failed = $repo->findById((int)($stuckLast?->getId() ?? 0));
$stillFresh = $repo->findById((int)($fresh?->getId() ?? 0));

$assert('4.1 Se rescatan las dos filas atrapadas', $rescued === 2, "rescatadas: {$rescued}");
$assert('4.2 La fila rescatada pasa a PENDING_RETRY', $retried?->getStatus() === NotificationStatus::PENDING_RETRY);
$assert('4.3 El intento interrumpido se contabiliza', $retried?->getAttempts() === 1);$assert(
    '4.4 El error de tránsito queda documentado',
    $retried !== null && str_contains((string)$retried->getLastError(), 'rescate automático')
);
$assert('4.5 El quinto intento cae en FAILED_PERMANENT', $failed?->getStatus() === NotificationStatus::FAILED_PERMANENT && $failed?->getAttempts() === 5);
$assert('4.6 El fallo definitivo sella processed_at', $failed?->getProcessedAt() !== null);
$assert('4.7 La fila reciente no se toca', $stillFresh?->getStatus() === NotificationStatus::PROCESSING);

// =====================================================================
// 5. Consultas filtradas, paginación y contadores KPI
// =====================================================================
echo "\n--- 5. Consultas por los seis estados, filtros y KPI ---\n";
$resetOutbox();
$now = new DateTimeImmutable('now');

$states = [
    NotificationStatus::PENDING,
    NotificationStatus::PROCESSING,
    NotificationStatus::PENDING_RETRY,
    NotificationStatus::SENT,
    NotificationStatus::FAILED_PERMANENT,
    NotificationStatus::CANCELLED_OBSOLETE,
];

foreach ($states as $index => $status) {
    $repo->enqueue($makeItem([
        'subject' => "[TEST {$marker}] Estado {$status->value}",
        'dedupKey' => "state:{$status->value}:{$marker}",
        'status' => $status,
        'channel' => $index % 2 === 0 ? NotificationChannel::EMAIL : NotificationChannel::WEBHOOK,
        'priority' => $index < 2 ? NotificationPriority::CRITICAL_SANITARY : NotificationPriority::STANDARD,
        'processedAt' => $status === NotificationStatus::SENT ? $now : null,
        'recipient' => $index % 2 === 0 ? $technicianEmail : 'https://emergencias.example.org/hook',
        'recipientUserId' => $index % 2 === 0 ? $technicianId : null,
    ]));
}

$kpi = $repo->countByStatus();
$assert('5.1 Los KPI exponen los seis estados canónicos', count(array_intersect(array_keys($kpi), array_map(static fn($s) => $s->value, $states))) === 6);
$assert('5.2 Los contadores por estado son exactos', $kpi['PENDING'] === 1 && $kpi['PROCESSING'] === 1 && $kpi['PENDING_RETRY'] === 1 && $kpi['SENT'] === 1 && $kpi['FAILED_PERMANENT'] === 1 && $kpi['CANCELLED_OBSOLETE'] === 1);
$assert('5.3 in_queue agrega pendiente, en proceso y pendiente de reintento', $kpi['in_queue'] === 3);
$assert('5.4 sent_last_24h cuenta la enviada de las últimas 24 horas', $kpi['sent_last_24h'] === 1);

$failedRows = $repo->findForCoordinator(['status' => 'FAILED_PERMANENT']);
$assert('5.5 El filtro por estado devuelve sólo ese estado', count($failedRows) === 1 && $failedRows[0]['status'] === 'FAILED_PERMANENT');
$assert('5.6 La consulta enriquece con el código de ticket', ($failedRows[0]['ticket_code'] ?? null) === $ticketCode);

$webhookRows = $repo->findForCoordinator(['channel' => 'WEBHOOK']);
$assert('5.7 El filtro por canal WEBHOOK devuelve tres filas', count($webhookRows) === 3, 'filas: ' . count($webhookRows));

$criticalRows = $repo->findForCoordinator(['priority' => 'CRITICAL_SANITARY']);
$assert('5.8 El filtro por prioridad sanitaria devuelve dos filas', count($criticalRows) === 2, 'filas: ' . count($criticalRows));

$ticketRows = $repo->findForCoordinator(['ticket_code' => substr($ticketCode, 0, 8)]);
$assert('5.9 El filtro por ticket acepta búsqueda parcial', count($ticketRows) === 6, 'filas: ' . count($ticketRows));
$assert('5.10 countForCoordinator coincide con el listado filtrado', $repo->countForCoordinator(['ticket_code' => substr($ticketCode, 0, 8)]) === 6);

$page = $repo->findForCoordinator([], 2, 1);
$assert('5.11 La paginación respeta limit y offset', count($page) === 2);

// =====================================================================
// 6. Cancelación por obsolescencia y destinatario desactivado
// =====================================================================
echo "\n--- 6. Cancelación por obsolescencia ---\n";
$resetOutbox();

$pendingA = $repo->enqueue($makeItem(['subject' => "[TEST {$marker}] Pendiente A]", 'dedupKey' => "cancel-a:{$marker}"]));
$pendingB = $repo->enqueue($makeItem([
    'subject' => "[TEST {$marker}] Pendiente B]",
    'dedupKey' => "cancel-b:{$marker}",
    'status' => NotificationStatus::PENDING_RETRY,
]));
$sentC = $repo->enqueue($makeItem([
    'subject' => "[TEST {$marker}] Enviada C]",
    'dedupKey' => "cancel-c:{$marker}",
    'status' => NotificationStatus::SENT,
    'processedAt' => $now,
]));

$cancelled = $repo->cancelPendingForIncident($incidentId, 'Cierre administrativo del expediente en la prueba.');

$assert('6.1 Se cancelan las dos filas pendientes', $cancelled === 2, "canceladas: {$cancelled}");
$assert('6.2 La fila PENDING queda cancelada por obsolescencia', $repo->findById((int)($pendingA?->getId() ?? 0))?->getStatus() === NotificationStatus::CANCELLED_OBSOLETE);
$assert('6.3 La fila PENDING_RETRY queda cancelada por obsolescencia', $repo->findById((int)($pendingB?->getId() ?? 0))?->getStatus() === NotificationStatus::CANCELLED_OBSOLETE);
$assert('6.4 La fila enviada permanece inmutable', $repo->findById((int)($sentC?->getId() ?? 0))?->getStatus() === NotificationStatus::SENT);

$preserved = $repo->enqueue($makeItem([
    'subject' => "[TEST {$marker}] Aviso de cancelación preservado]",
    'template' => NotificationTemplate::INCIDENT_CANCELLED_SITE,
    'dedupKey' => "preserve:{$marker}",
    'recipientRole' => 'LOCATION',
    'recipient' => $locationEmail,
    'recipientUserId' => null,
    'recipientLocationId' => $locationId,
]));
$repo->cancelPendingForIncident(
    $incidentId,
    'Segunda pasada de cancelación de la prueba.',
    [NotificationTemplate::INCIDENT_CANCELLED_SITE->value]
);
$assert(
    '6.5 La plantilla de cancelación puede preservarse',
    $preserved !== null && $repo->findById((int)$preserved->getId())?->getStatus() === NotificationStatus::PENDING
);

$userItem = $repo->enqueue($makeItem(['subject' => "[TEST {$marker}] Destinatario interno]", 'dedupKey' => "user-cancel:{$marker}"]));
$assert('6.6 cancelPendingForRecipientUser cancela la fila del usuario', $repo->cancelPendingForRecipientUser($technicianId, 'Técnico desactivado en la prueba.') === 1);
$assert(
    '6.7 El motivo de obsolescencia queda registrado',
    $userItem !== null
        && str_contains((string)$repo->findById((int)$userItem->getId())?->getObsoleteReason(), 'Técnico desactivado')
);

$webhookItem = $repo->enqueue($makeItem([
    'subject' => "[TEST {$marker}] Webhook pendiente]",
    'channel' => NotificationChannel::WEBHOOK,
    'recipient' => 'https://emergencias.example.org/hook',
    'recipientUserId' => null,
    'dedupKey' => "webhook-cancel:{$marker}",
]));
$assert('6.8 cancelPendingWebhookItems cancela el envío del canal', $repo->cancelPendingWebhookItems('Canal corporativo desactivado en la prueba.') === 1);
$assert(
    '6.9 El envío Webhook queda cancelado, nunca borrado',
    $webhookItem !== null && $repo->findById((int)$webhookItem->getId())?->getStatus() === NotificationStatus::CANCELLED_OBSOLETE
);
$assert(
    '6.10 La fila sigue existiendo físicamente (Art. III.1)',
    $webhookItem !== null
        && (int)$pdo->query("SELECT COUNT(*) FROM notification_outbox WHERE id = " . (int)$webhookItem->getId())->fetchColumn() === 1
);

// Limpieza final de las filas de la prueba, con la ventana de purga abierta.
$resetOutbox();

if ($createdIncidentId !== null) {
    TestDataCleaner::purgeIncident($pdo, $createdIncidentId);
}

echo "\n----------------------------------------------------------------------\n";
echo "Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo "Resultado: [OK] Todos los tests de PdoNotificationOutboxRepository pasaron con éxito.\n";
    exit(0);
}

echo "Resultado: [FALLO] Se detectaron {$failures} fallos en el repositorio de la bandeja de salida.\n";
exit(1);
