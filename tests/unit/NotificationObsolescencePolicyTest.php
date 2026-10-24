<?php

declare(strict_types=1);

/**
 * NotificationObsolescencePolicyTest
 *
 * Suite de pruebas unitarias para la política pura de obsolescencia (T-NOTIF-03, RF-07.1).
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Service\NotificationObsolescencePolicy;

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - NotificationObsolescencePolicyTest\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
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

$createItem = function (
    NotificationTemplate $template,
    NotificationChannel $channel = NotificationChannel::EMAIL,
    ?int $recipientUserId = null
): NotificationOutboxItem {
    return new NotificationOutboxItem(
        id: 1,
        incidentId: 10,
        channel: $channel,
        priority: NotificationPriority::STANDARD,
        template: $template,
        recipient: 'dest@example.com',
        recipientRole: 'COORDINATOR',
        recipientUserId: $recipientUserId,
        recipientLocationId: null,
        subject: 'Test Subject',
        payload: [],
        dedupKey: null,
        status: NotificationStatus::PENDING,
        attempts: 0,
        maxAttempts: 5,
        nextAttemptAt: new DateTimeImmutable(),
        processingStartedAt: null,
        processedAt: null,
        lastError: null,
        obsoleteReason: null,
        createdAt: new DateTimeImmutable(),
        updatedAt: new DateTimeImmutable()
    );
};

$createIncident = function (
    IncidentStatus $status,
    ?int $technicianId = null,
    ?string $startedAt = null
): Incident {
    return new Incident(
        id: 10,
        ticketCode: 'TICK-2026-00010',
        machineId: 1,
        locationId: 1,
        assignedTechnicianId: $technicianId,
        reporterName: 'Reporter',
        reporterPhone: '600000000',
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'Test incident',
        urgency: UrgencyLevel::HIGH,
        status: $status,
        startedAt: $startedAt
    );
};

// 1. Regla (a): Alerta 2h sin asignar cuando ya tiene técnico asignado
echo "--- Regla (a): Alerta de 2h sin asignar (RF-07.1.a) ---\n";
$item2h = $createItem(NotificationTemplate::SLA_IMMINENCE_2H_UNASSIGNED);
$incUnassigned = $createIncident(IncidentStatus::REGISTERED, null);
$incAssigned = $createIncident(IncidentStatus::ASSIGNED, 4);

$res2h_1 = NotificationObsolescencePolicy::evaluate($item2h, $incUnassigned);
$assert('2h sin asignar es válida si el ticket sigue sin asignar', !$res2h_1['is_obsolete']);

$res2h_2 = NotificationObsolescencePolicy::evaluate($item2h, $incAssigned);
$assert('2h sin asignar es obsoleta si el ticket ya fue asignado', $res2h_2['is_obsolete']);
$assert('2h sin asignar documenta motivo exacto', $res2h_2['reason'] === NotificationObsolescencePolicy::REASON_SLA_2H_ALREADY_ASSIGNED);

// 2. Regla (b): Alerta 3h sin iniciar cuando ya inició o entró en EN_CURSO
echo "--- Regla (b): Alerta de 3h sin iniciar (RF-07.1.b) ---\n";
$item3h = $createItem(NotificationTemplate::SLA_IMMINENCE_3H_UNSTARTED);
$incAssignedNotStarted = $createIncident(IncidentStatus::ASSIGNED, 4, null);
$incInProgress = $createIncident(IncidentStatus::IN_PROGRESS, 4, '2026-10-17 11:00:00');

$res3h_1 = NotificationObsolescencePolicy::evaluate($item3h, $incAssignedNotStarted);
$assert('3h sin iniciar es válida si el ticket no ha iniciado', !$res3h_1['is_obsolete']);

$res3h_2 = NotificationObsolescencePolicy::evaluate($item3h, $incInProgress);
$assert('3h sin iniciar es obsoleta si ya está en IN_PROGRESS', $res3h_2['is_obsolete']);
$assert('3h sin iniciar documenta motivo exacto', $res3h_2['reason'] === NotificationObsolescencePolicy::REASON_SLA_3H_ALREADY_STARTED);

// 3. Regla (c): Nueva asignación a técnico reemplazado
echo "--- Regla (c): Asignación a técnico reemplazado (RF-07.1.c) ---\n";
$itemAssignTech4 = $createItem(NotificationTemplate::TECHNICIAN_ASSIGNED, NotificationChannel::EMAIL, 4);
$incWithTech4 = $createIncident(IncidentStatus::ASSIGNED, 4);
$incWithTech9 = $createIncident(IncidentStatus::ASSIGNED, 9);

$resTech_1 = NotificationObsolescencePolicy::evaluate($itemAssignTech4, $incWithTech4);
$assert('Aviso de asignación es válido para el técnico actualmente asignado', !$resTech_1['is_obsolete']);

$resTech_2 = NotificationObsolescencePolicy::evaluate($itemAssignTech4, $incWithTech9);
$assert('Aviso de asignación es obsoleto si el técnico fue reemplazado por otro', $resTech_2['is_obsolete']);
$assert('Aviso de asignación documenta motivo exacto', $resTech_2['reason'] === NotificationObsolescencePolicy::REASON_TECHNICIAN_SUPERSEDED);

$itemHandoverTech4 = $createItem(NotificationTemplate::TELEGRAM_INCIDENT_ASSIGNMENT, NotificationChannel::TELEGRAM, 4);
$resHandover = NotificationObsolescencePolicy::evaluate($itemHandoverTech4, $incWithTech4);
$assert('Aviso de relevo es válido para el técnico receptor vigente', !$resHandover['is_obsolete']);
$resHandoverSuperseded = NotificationObsolescencePolicy::evaluate($itemHandoverTech4, $incWithTech9);
$assert('Aviso de relevo es obsoleto si el ticket cambia de técnico', $resHandoverSuperseded['is_obsolete']);

// 4. Regla (d): Aviso de pausa a sede cuando la pausa ya fue reanudada
echo "--- Regla (d): Aviso de pausa ya reanudada (RF-07.1.d) ---\n";
$itemPause = $createItem(NotificationTemplate::PENDING_INFO_SITE_REQUEST);
$incPaused = $createIncident(IncidentStatus::PENDING_INFO, 4);
$incResumed = $createIncident(IncidentStatus::IN_PROGRESS, 4);

$resPause_1 = NotificationObsolescencePolicy::evaluate($itemPause, $incPaused);
$assert('Aviso de pausa es válido mientras el ticket está en PENDING_INFO', !$resPause_1['is_obsolete']);

$resPause_2 = NotificationObsolescencePolicy::evaluate($itemPause, $incResumed);
$assert('Aviso de pausa es obsoleto si el ticket ya no está en PENDING_INFO', $resPause_2['is_obsolete']);
$assert('Aviso de pausa documenta motivo exacto', $resPause_2['reason'] === NotificationObsolescencePolicy::REASON_PAUSE_ALREADY_RESUMED);

// 5. Regla (e): Cancelación o cierre de expediente cancela avisos operativos previos
echo "--- Regla (e): Cancelación o cierre de expediente (RF-07.1.e) ---\n";
$itemGeneric = $createItem(NotificationTemplate::TECHNICIAN_ASSIGNED, NotificationChannel::EMAIL, 4);
$itemCancelSite = $createItem(NotificationTemplate::INCIDENT_CANCELLED_SITE);
$incCancelled = $createIncident(IncidentStatus::CANCELLED, 4);
$incClosed = $createIncident(IncidentStatus::CLOSED, 4);

$resCancel_1 = NotificationObsolescencePolicy::evaluate($itemGeneric, $incCancelled);
$assert('Aviso operativo previo es obsoleto si el ticket se canceló', $resCancel_1['is_obsolete']);
$assert('Documenta motivo de ticket cancelado', $resCancel_1['reason'] === NotificationObsolescencePolicy::REASON_INCIDENT_ALREADY_CANCELLED);

$resCancel_2 = NotificationObsolescencePolicy::evaluate($itemCancelSite, $incCancelled);
$assert('El propio aviso de cancelación de expediente NO se marca como obsoleto', !$resCancel_2['is_obsolete']);

$resClosed_1 = NotificationObsolescencePolicy::evaluate($itemGeneric, $incClosed);
$assert('Aviso operativo previo es obsoleto si el ticket se cerró', $resClosed_1['is_obsolete']);
$assert('Documenta motivo de ticket cerrado', $resClosed_1['reason'] === NotificationObsolescencePolicy::REASON_INCIDENT_ALREADY_CLOSED);

// 6. Regla (f): Usuario destinatario interno o canal Webhook desactivado
echo "--- Regla (f): Destinatario o canal Webhook desactivado (RF-07.1.f) ---\n";
$itemUser = $createItem(NotificationTemplate::TECHNICIAN_ASSIGNED, NotificationChannel::EMAIL, 4);
$resUserDeactivated = NotificationObsolescencePolicy::evaluate($itemUser, $incWithTech4, false, true);
$assert('Aviso a usuario interno desactivado es obsoleto', $resUserDeactivated['is_obsolete']);
$assert('Documenta usuario interno desactivado', $resUserDeactivated['reason'] === NotificationObsolescencePolicy::REASON_RECIPIENT_USER_DEACTIVATED);

$itemWebhook = $createItem(NotificationTemplate::CHRONIC_BREAKDOWN_ALERT, NotificationChannel::WEBHOOK);
$resWebhookDisabled = NotificationObsolescencePolicy::evaluate($itemWebhook, $incWithTech4, true, false);
$assert('Aviso Webhook con canal desactivado es obsoleto', $resWebhookDisabled['is_obsolete']);
$assert('Documenta canal Webhook desactivado', $resWebhookDisabled['reason'] === NotificationObsolescencePolicy::REASON_WEBHOOK_CHANNEL_DISABLED);

echo "\nResultado: {$assertions} evaluadas, {$failures} fallidas.\n";
if ($failures > 0) {
    exit(1);
}
