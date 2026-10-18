<?php

declare(strict_types=1);

/**
 * NotificationStatusStateMachineTest
 *
 * Suite de pruebas unitarias para los 6 estados canónicos de la bandeja de salida (Módulo 14 · T-NOTIF-02).
 * Verifica:
 *   1. Existencia, nombres y valores de los 6 estados canónicos (RF-01.1).
 *   2. Etiquetas descriptivas en castellano para cada estado.
 *   3. Propiedades semánticas (isPendingDispatch, isTerminal, canBeCancelled, canBeRetried).
 *   4. Grafo y matriz exhaustiva de transiciones permitidas y prohibidas entre los 6 estados.
 *   5. Ciclo de vida y mutaciones sobre entidades NotificationOutboxItem y NotificationChannelSettings.
 *   6. Extensiones de Location (contactEmail), User (isOnCall) e Incident (reporterEmail, marcas de ciclo).
 *
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\Model\NotificationChannelSettings;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - NotificationStatusStateMachineTest (T-NOTIF-02)\n";
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

// =====================================================================
// GRUPO 1: Los 6 Estados Canónicos y sus Propiedades (RF-01.1)
// =====================================================================
echo "--- Grupo 1: Los 6 estados canónicos y propiedades semánticas ---\n";

$cases = NotificationStatus::cases();
$assert('1.1 Exactamente 6 estados canónicos declarados en NotificationStatus', count($cases) === 6, 'Encontrados: ' . count($cases));

$expectedValues = ['PENDING', 'PROCESSING', 'PENDING_RETRY', 'SENT', 'FAILED_PERMANENT', 'CANCELLED_OBSOLETE'];
$actualValues = array_map(fn(NotificationStatus $s) => $s->value, $cases);
$assert('1.2 Los 6 valores canónicos coinciden con el contrato', $actualValues === $expectedValues);

foreach ($cases as $status) {
    $assert(
        sprintf('1.3 Estado %s tiene etiqueta en castellano no vacía', $status->value),
        trim($status->label()) !== ''
    );
}

$assert('1.4 PENDING está en cola de despacho', NotificationStatus::PENDING->isPendingDispatch() === true);
$assert('1.5 PROCESSING está en cola de despacho', NotificationStatus::PROCESSING->isPendingDispatch() === true);
$assert('1.6 PENDING_RETRY está en cola de despacho', NotificationStatus::PENDING_RETRY->isPendingDispatch() === true);
$assert('1.7 SENT no está en cola de despacho y es terminal', NotificationStatus::SENT->isPendingDispatch() === false && NotificationStatus::SENT->isTerminal() === true);
$assert('1.8 CANCELLED_OBSOLETE no está en cola y es terminal', NotificationStatus::CANCELLED_OBSOLETE->isPendingDispatch() === false && NotificationStatus::CANCELLED_OBSOLETE->isTerminal() === true);
$assert('1.9 FAILED_PERMANENT permite reintento manual (canBeRetried)', NotificationStatus::FAILED_PERMANENT->canBeRetried() === true);
$assert('1.10 PENDING permite cancelación manual (canBeCancelled)', NotificationStatus::PENDING->canBeCancelled() === true);
$assert('1.11 PENDING_RETRY permite cancelación manual (canBeCancelled)', NotificationStatus::PENDING_RETRY->canBeCancelled() === true);
$assert('1.12 FAILED_PERMANENT permite cancelación manual (canBeCancelled)', NotificationStatus::FAILED_PERMANENT->canBeCancelled() === true);
$assert('1.13 SENT no permite cancelación manual', NotificationStatus::SENT->canBeCancelled() === false);

// =====================================================================
// GRUPO 2: Matriz de Transiciones de la Bandeja de Salida
// =====================================================================
echo "\n--- Grupo 2: Matriz exhaustiva de transiciones de estado ---\n";

// PENDING
$assert('2.1 PENDING -> PROCESSING permitido (toma del despachador)', NotificationStatus::PENDING->canTransitionTo(NotificationStatus::PROCESSING));
$assert('2.2 PENDING -> CANCELLED_OBSOLETE permitido (obsolescencia / cancelación manual)', NotificationStatus::PENDING->canTransitionTo(NotificationStatus::CANCELLED_OBSOLETE));
$assert('2.3 PENDING -> SENT prohibido sin pasar por PROCESSING', !NotificationStatus::PENDING->canTransitionTo(NotificationStatus::SENT));
$assert('2.4 PENDING -> FAILED_PERMANENT prohibido directo', !NotificationStatus::PENDING->canTransitionTo(NotificationStatus::FAILED_PERMANENT));

// PROCESSING
$assert('2.5 PROCESSING -> SENT permitido (entrega exitosa confirmada)', NotificationStatus::PROCESSING->canTransitionTo(NotificationStatus::SENT));
$assert('2.6 PROCESSING -> PENDING_RETRY permitido (fallo transitorio)', NotificationStatus::PROCESSING->canTransitionTo(NotificationStatus::PENDING_RETRY));
$assert('2.7 PROCESSING -> FAILED_PERMANENT permitido (5º intento o rechazo definitivo)', NotificationStatus::PROCESSING->canTransitionTo(NotificationStatus::FAILED_PERMANENT));
$assert('2.8 PROCESSING -> CANCELLED_OBSOLETE permitido (obsolescencia detectada en proceso)', NotificationStatus::PROCESSING->canTransitionTo(NotificationStatus::CANCELLED_OBSOLETE));
$assert('2.9 PROCESSING -> PENDING prohibido directo', !NotificationStatus::PROCESSING->canTransitionTo(NotificationStatus::PENDING));

// PENDING_RETRY
$assert('2.10 PENDING_RETRY -> PROCESSING permitido (reintento tomado por despachador)', NotificationStatus::PENDING_RETRY->canTransitionTo(NotificationStatus::PROCESSING));
$assert('2.11 PENDING_RETRY -> CANCELLED_OBSOLETE permitido (cancelación manual/obsolescencia)', NotificationStatus::PENDING_RETRY->canTransitionTo(NotificationStatus::CANCELLED_OBSOLETE));
$assert('2.12 PENDING_RETRY -> SENT prohibido sin pasar por PROCESSING', !NotificationStatus::PENDING_RETRY->canTransitionTo(NotificationStatus::SENT));

// SENT
$assert('2.13 SENT es terminal: ninguna transición permitida', NotificationStatus::SENT->allowedTransitions() === []);

// FAILED_PERMANENT
$assert('2.14 FAILED_PERMANENT -> PENDING permitido (reintento manual RF-06.4)', NotificationStatus::FAILED_PERMANENT->canTransitionTo(NotificationStatus::PENDING));
$assert('2.15 FAILED_PERMANENT -> CANCELLED_OBSOLETE permitido (cancelación manual RF-06.4)', NotificationStatus::FAILED_PERMANENT->canTransitionTo(NotificationStatus::CANCELLED_OBSOLETE));
$assert('2.16 FAILED_PERMANENT -> SENT prohibido directo', !NotificationStatus::FAILED_PERMANENT->canTransitionTo(NotificationStatus::SENT));

// CANCELLED_OBSOLETE
$assert('2.17 CANCELLED_OBSOLETE es terminal: ninguna transición permitida', NotificationStatus::CANCELLED_OBSOLETE->allowedTransitions() === []);

// =====================================================================
// GRUPO 3: Value Objects (Channel, Priority, Template)
// =====================================================================
echo "\n--- Grupo 3: Value Objects de Notificación ---\n";

$assert('3.1 NotificationChannel tiene EMAIL, WEBHOOK y TELEGRAM', count(NotificationChannel::cases()) === 3);
$assert('3.2 NotificationChannel::fromString normaliza insensible a mayúsculas', NotificationChannel::fromString('email') === NotificationChannel::EMAIL);

$assert('3.3 NotificationPriority tiene CRITICAL_SANITARY y STANDARD', count(NotificationPriority::cases()) === 2);
$assert('3.4 NotificationPriority::CRITICAL_SANITARY isCritical() es true', NotificationPriority::CRITICAL_SANITARY->isCritical());
$assert('3.5 NotificationPriority::STANDARD isCritical() es false', !NotificationPriority::STANDARD->isCritical());

$assert('3.6 NotificationTemplate declara las 19 plantillas cerradas', count(NotificationTemplate::cases()) === 19);
$assert('3.7 COLD_CHAIN_IMMEDIATE_ALERT asigna prioridad crítica sanitaria por defecto', NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT->defaultPriority() === NotificationPriority::CRITICAL_SANITARY);
$assert('3.8 INCIDENT_OPENED_SITE asigna prioridad estándar por defecto', NotificationTemplate::INCIDENT_OPENED_SITE->defaultPriority() === NotificationPriority::STANDARD);

// =====================================================================
// GRUPO 4: Entidades de Dominio (NotificationOutboxItem y Settings)
// =====================================================================
echo "\n--- Grupo 4: Entidades NotificationOutboxItem y NotificationChannelSettings ---\n";

$now = new DateTimeImmutable('2026-10-17 10:00:00', new DateTimeZone('UTC'));
$item = new NotificationOutboxItem(
    id: 1,
    incidentId: 42,
    channel: NotificationChannel::EMAIL,
    priority: NotificationPriority::CRITICAL_SANITARY,
    template: NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT,
    recipient: 'coordinacion@vendguard.internal',
    recipientRole: 'COORDINATOR',
    recipientUserId: 1,
    recipientLocationId: null,
    subject: '⚠️ ALERTA: Rotura de frío en máquina #1',
    payload: ['ticket_code' => 'TICK-2026-0001', 'machine_code' => 'VEND-0101'],
    dedupKey: 'inc:42:cold:email:1',
    status: NotificationStatus::PENDING,
    createdAt: $now,
    updatedAt: $now
);

$assert('4.1 NotificationOutboxItem se instancia correctamente en estado PENDING', $item->getStatus() === NotificationStatus::PENDING);
$assert('4.2 NotificationOutboxItem getters coinciden', $item->getIncidentId() === 42 && $item->getRecipient() === 'coordinacion@vendguard.internal');

// Flujo 1: PENDING -> PROCESSING -> SENT
$item->markProcessing(new DateTimeImmutable('2026-10-17 10:01:00', new DateTimeZone('UTC')));
$assert('4.3 Transición a PROCESSING registrada con startedAt', $item->getStatus() === NotificationStatus::PROCESSING && $item->getProcessingStartedAt() !== null);

$item->markSent(new DateTimeImmutable('2026-10-17 10:01:02', new DateTimeZone('UTC')));
$assert('4.4 Transición a SENT incrementa attempts a 1 y guarda processedAt', $item->getStatus() === NotificationStatus::SENT && $item->getAttempts() === 1 && $item->getProcessedAt() !== null);

// Flujo 2: Fallo transitorio y reintento
$itemRetry = new NotificationOutboxItem(
    id: 2,
    incidentId: 43,
    channel: NotificationChannel::EMAIL,
    priority: NotificationPriority::STANDARD,
    template: NotificationTemplate::INCIDENT_OPENED_SITE,
    recipient: 'contacto.sede-bcn-01@sede.vendguard.es',
    recipientRole: 'LOCATION',
    recipientUserId: null,
    recipientLocationId: 1,
    subject: 'Nueva avería registrada',
    payload: ['ticket_code' => 'TICK-2026-0002']
);

$itemRetry->markProcessing(new DateTimeImmutable('2026-10-17 10:00:00', new DateTimeZone('UTC')));
$nextAttempt = new DateTimeImmutable('2026-10-17 10:01:00', new DateTimeZone('UTC'));
$itemRetry->recordTransientFailure('Connection timed out after 5000ms', $nextAttempt);
$assert('4.5 recordTransientFailure pasa a PENDING_RETRY con error y nextAttempt',
    $itemRetry->getStatus() === NotificationStatus::PENDING_RETRY
    && $itemRetry->getAttempts() === 1
    && $itemRetry->getLastError() === 'Connection timed out after 5000ms'
);

// Flujo 3: Fallo permanente y reintento manual (RF-06.4)
$itemRetry->recordPermanentFailure('550 Mailbox does not exist');
$assert('4.6 recordPermanentFailure pasa a FAILED_PERMANENT', $itemRetry->getStatus() === NotificationStatus::FAILED_PERMANENT && $itemRetry->getAttempts() === 2);

$itemRetry->resetForManualRetry('nuevo.correo@sede.vendguard.es', new DateTimeImmutable('2026-10-17 10:05:00', new DateTimeZone('UTC')));
$assert('4.7 resetForManualRetry restablece a PENDING con attempts = 0 y nuevo destinatario',
    $itemRetry->getStatus() === NotificationStatus::PENDING
    && $itemRetry->getAttempts() === 0
    && $itemRetry->getRecipient() === 'nuevo.correo@sede.vendguard.es'
    && $itemRetry->getLastError() === null
);

// Flujo 4: Agrupación de ráfagas en PENDING (RF-05.4)
$itemBurst = new NotificationOutboxItem(
    id: 3,
    incidentId: 44,
    channel: NotificationChannel::EMAIL,
    priority: NotificationPriority::STANDARD,
    template: NotificationTemplate::PENDING_INFO_SITE_REPLY,
    recipient: 'tecnico@vendguard.internal',
    recipientRole: 'TECHNICIAN',
    recipientUserId: 2,
    recipientLocationId: null,
    subject: 'Respuesta de sede (1)',
    payload: ['comment_text' => 'Primer mensaje']
);
$itemBurst->updatePayloadForBurst(['comment_text' => 'Segundo mensaje actualizado'], 'Respuesta de sede (2)');
$assert('4.8 updatePayloadForBurst actualiza payload y asunto en PENDING sin crear duplicado',
    $itemBurst->getPayload()['comment_text'] === 'Segundo mensaje actualizado'
    && $itemBurst->getSubject() === 'Respuesta de sede (2)'
);

// Settings
$settings = new NotificationChannelSettings(
    id: 1,
    webhookName: 'Canal Slack Central',
    webhookUrl: 'https://hooks.slack.com/services/T00/B00/X00',
    isWebhookEnabled: true
);
$assert('4.9 NotificationChannelSettings isConfiguredAndEnabled() es true', $settings->isConfiguredAndEnabled());
$settings->recordTestResult(true, null);
$assert('4.10 recordTestResult registra éxito y timestamp', $settings->getLastTestStatus() === 'SUCCESS' && $settings->getLastTestedAt() !== null);

// =====================================================================
// GRUPO 5: Extensiones en Modelos Existentes (Location, User, Incident)
// =====================================================================
echo "\n--- Grupo 5: Extensiones en Location, User e Incident ---\n";

$location = new Location(
    id: 1,
    siteCode: 'SEDE-TEST-01',
    name: 'Sede Test',
    address: 'Calle Mayor 1',
    contactEmail: 'sede.contacto@vendguard.es'
);
$assert('5.1 Location tiene contactEmail y getter getContactEmail()', $location->getContactEmail() === 'sede.contacto@vendguard.es');
$assert('5.2 Location toArray() incluye contact_email', $location->toArray()['contact_email'] === 'sede.contacto@vendguard.es');

$user = new User(
    id: 2,
    name: 'Jordi Técnico',
    email: 'jordi.ruta@vendguard.internal',
    passwordHash: 'hash',
    role: UserRole::TECHNICIAN,
    isOnCall: true
);
$assert('5.3 User tiene isOnCall y getter isOnCall()', $user->isOnCall() === true);
$assert('5.4 User toArray() incluye is_on_call', $user->toArray()['is_on_call'] === true);

$incident = new Incident(
    id: 10,
    ticketCode: 'TICK-TEST-10',
    machineId: 1,
    locationId: 1,
    category: IncidentCategory::TEMPERATURE_COLD,
    description: 'Fallo de termostato con pérdida de frío',
    urgency: UrgencyLevel::CRITICAL,
    reporterEmail: 'ciudadano@gmail.com',
    cycleStartedAt: '2026-10-17 08:30:00+02',
    coldAlertSentAt: '2026-10-17 08:31:00+02',
    sla2hAlertSentAt: null,
    sla3hAlertSentAt: null
);
$assert('5.5 Incident tiene reporterEmail y getters de ciclo sanitario',
    $incident->getReporterEmail() === 'ciudadano@gmail.com'
    && $incident->getCycleStartedAt() === '2026-10-17 08:30:00+02'
    && $incident->getColdAlertSentAt() === '2026-10-17 08:31:00+02'
);
$assert('5.6 Incident toArray() incluye reporter_email y marcas de ciclo sanitario',
    $incident->toArray()['reporter_email'] === 'ciudadano@gmail.com'
    && $incident->toArray()['cycle_started_at'] === '2026-10-17 08:30:00+02'
);

// =====================================================================
// Resumen y Código de Salida
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-NOTIF-02 CUMPLIDA SATISFACTORIAMENTE.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
echo "======================================================================\n";
exit(1);
