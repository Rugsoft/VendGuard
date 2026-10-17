<?php

declare(strict_types=1);

/**
 * NotificationOutboxEnqueueIntegrationTest
 *
 * Certificación de integración de T-NOTIF-06 y del censo de T-NOTIF-10 sobre el
 * motor único PostgreSQL.
 *
 * El «Hecho cuando» exige que `NotificationOutboxEnqueuerService` encole de forma
 * atómica los eventos del catálogo: apertura (con doble destinatario de Sede y
 * proyección ciudadana), Alerta Inmediata de Frío (apertura, reapertura y
 * reclasificación crítica), asignación/reasignación, pausa `PENDING_INFO`,
 * respuesta de Sede con agrupación de ráfagas, resolución con garantía de 48 h,
 * reapertura en garantía, Avería Crónica, cancelación con advertencia de bloqueo,
 * acuse al segundo ciudadano QR, Cuarentena Sanitaria 4h y Escalado de Contención.
 *
 * Cada grupo de aserciones lee las filas REALES de `notification_outbox`, de modo
 * que se certifica el artefacto que consumirá el despachador —rol de destinatario,
 * plantilla, prioridad, canal, `dedup_key` y payload sanitizado— y no una
 * expectativa intermedia. También se prueba la atomicidad: un fallo después de
 * encolar dentro de una transacción no deja filas.
 *
 * Nota de aislamiento: el parque sembrado sólo admite una avería activa por
 * máquina, así que cada escenario recibe una máquina distinta del banco de
 * fixtures. Los escenarios que exigen vigilancia sanitaria toman máquinas
 * perecederas o mixtas; el resto, cualquier máquina del parque.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\IncidentCommentService;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Application\Service\NotificationOutboxEnqueuerService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - NotificationOutboxEnqueueIntegrationTest (T-NOTIF-06)\n";
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

$coordinatorRows = $users->findAll(['status' => 'active', 'role' => 'COORDINATOR']);
$coordinatorEmail = strtolower((string)($coordinatorRows[0]['email'] ?? ''));
$technician = $users->findById(2, true);
$technician2 = $users->findById(3, true);
$coordinatorId = (int)($coordinatorRows[0]['id'] ?? 1);

$assert('El escenario dispone de coordinador activo con correo válido', $coordinatorEmail !== '');
$assert('El escenario dispone de dos técnicos activos', $technician !== null && $technician2 !== null);

$users->setOnCall(2, true);

// Banco de máquinas del parque: una avería activa por máquina como máximo.
$allMachines = $pdo->query("
    SELECT m.id
    FROM machines m
    JOIN locations l ON l.id = m.location_id
    WHERE m.deleted_at IS NULL
      AND m.is_active = TRUE
      AND l.is_active = TRUE
      AND l.deleted_at IS NULL
    ORDER BY m.id ASC
")->fetchAll(PDO::FETCH_COLUMN);
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

$assert('El parque sembrado ofrece máquinas para aislar cada escenario', count($allMachines) >= 12);
$assert('El parque sembrado ofrece máquinas bajo vigilancia sanitaria', count($sanitaryMachines) >= 4);

/** Máquinas ocupadas por un expediente vivo de esta suite: `id => incident_id`. */
$usedMachines = [];

/**
 * Libera la máquina del expediente más antiguo cancelándolo, para que un escenario
 * posterior pueda reutilizarla sin chocar con la regla de unicidad de avería activa.
 */
$releaseOldest = function () use (&$usedMachines, $incidents): void {
    $machineId = array_key_first($usedMachines);
    if ($machineId === null) {
        throw new RuntimeException('No queda ninguna máquina de certificación que liberar.');
    }

    $incidentId = (int)$usedMachines[$machineId];
    unset($usedMachines[$machineId]);

    $stale = $incidents->findById($incidentId);
    if ($stale === null || $stale->getStatus()->isTerminal()) {
        return;
    }

    try {
        $incidents->cancel($incidentId, 'Liberación de la máquina tras el escenario de certificación T-NOTIF-06.', 1);
    } catch (Throwable) {
        // Estados terminales o transiciones no admitidas: la máquina ya no bloquea.
    }
};

/**
 * Crea un expediente en una máquina libre y devuelve su contexto de fixtures.
 *
 * @return array{incident: Incident, machine: object, official: string, machine_code: string}
 */
$fixture = function (string $category, string $urgency, ?string $reporterEmail = null, bool $sanitary = false) use (
    &$usedMachines,
    $releaseOldest,
    $sanitaryMachines,
    $allMachines,
    $incidents,
    $machines,
    $locations
): array {
    $pool = $sanitary ? $sanitaryMachines : $allMachines;

    $pick = static function () use ($pool, &$usedMachines): ?int {
        foreach ($pool as $candidate) {
            if (!isset($usedMachines[(int)$candidate])) {
                return (int)$candidate;
            }
        }

        return null;
    };

    $machineId = $pick();
    if ($machineId === null) {
        $releaseOldest();
        $machineId = $pick();
    }

    if ($machineId === null) {
        throw new RuntimeException('El banco de fixtures agotó las máquinas disponibles.');
    }

    $machine = $machines->findById($machineId);
    $location = $locations->findById($machine->getLocationId());

    $incident = $incidents->create(new Incident(
        id: null,
        ticketCode: TicketCode::generate()->value(),
        machineId: $machine->getId(),
        locationId: $location->getId(),
        category: IncidentCategory::fromString($category),
        description: 'Avería de certificación del encolado transaccional (T-NOTIF-06).',
        urgency: UrgencyLevel::fromString($urgency),
        status: IncidentStatus::REGISTERED,
        reporterName: 'Informador de Certificación',
        reporterPhone: '600111222',
        reporterEmail: $reporterEmail
    ), null, 'Alta de certificación T-NOTIF-06');

    $usedMachines[$machineId] = (int)$incident->getId();

    return [
        'incident' => $incident,
        'machine' => $machine,
        'official' => strtolower(trim((string)($location->getContactEmail() ?? ''))),
        'machine_code' => $machine->getCode(),
    ];
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

$column = function (array $rows, string $field): array {
    return array_values(array_map(static fn (array $row): string => (string)$row[$field], $rows));
};

$payloadOf = static fn (array $row): array => json_decode((string)$row['payload_json'], true) ?? [];

// El canal corporativo se mantiene apagado en la mayoría de escenarios para que
// los recuentos midan sólo destinatarios con correo; se enciende en su propio caso.
$current = $settings->findCurrent();
$current->updateConfiguration('Canal de certificación T-NOTIF-06', null, false);
$settings->save($current);

// ─────────────────────────────────────────────────────────────────────────────
// 1. RF-05.1 / RF-02.3 / RF-02.4 — Apertura desde la Sede con doble destinatario
// ─────────────────────────────────────────────────────────────────────────────
$reporterEmail = 'informador.sede@externo.example';
$site = $fixture('PRODUCT_JAM', 'MEDIUM', $reporterEmail);
$siteIncident = $site['incident'];
$siteId = (int)$siteIncident->getId();
$enqueued = $enqueuer->enqueueIncidentOpened(
    $siteIncident,
    null,
    null,
    NotificationOutboxEnqueuerService::ORIGIN_LOCATION_PORTAL,
    $reporterEmail
);
$siteOpeningRows = $fetchRows($siteId, 'INCIDENT_OPENED_SITE');
$assert('Apertura de Sede encola dos destinatarios (Art. III.3)', $enqueued === 2 && count($siteOpeningRows) === 2);
$assert(
    'El doble destinatario incluye correo oficial e informador',
    in_array($site['official'], $column($siteOpeningRows, 'recipient'), true)
        && in_array($reporterEmail, $column($siteOpeningRows, 'recipient'), true)
);
$assert('Los destinatarios de Sede viajan con rol LOCATION', $column($siteOpeningRows, 'recipient_role') === ['LOCATION', 'LOCATION']);
$assert(
    'La apertura de Sede publica enlace al Portal de Sede',
    str_contains((string)$payloadOf($siteOpeningRows[0])['tracking_url'], '/?site=')
);
$assert(
    'La apertura de Sede no filtra claves internas',
    !array_key_exists('resolution_diagnosis', $payloadOf($siteOpeningRows[0]))
        && !array_key_exists('technician_name', $payloadOf($siteOpeningRows[0]))
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. RF-05.1 — Apertura por QR con correo ciudadano y sin él
// ─────────────────────────────────────────────────────────────────────────────
$citizenEmail = 'ciudadano.qr@example.com';
$qr = $fixture('TEMPERATURE_COLD', 'CRITICAL', $citizenEmail, true);
$qrIncident = $qr['incident'];
$qrId = (int)$qrIncident->getId();
$enqueuer->enqueueIncidentOpened(
    $qrIncident,
    null,
    null,
    NotificationOutboxEnqueuerService::ORIGIN_CITIZEN_QR,
    $citizenEmail
);
$qrOpeningRows = $fetchRows($qrId, 'INCIDENT_OPENED_CITIZEN_QR');
$assert('Apertura QR con correo encola la confirmación ciudadana', count($qrOpeningRows) === 1);
$assert('La confirmación ciudadana viaja con rol CITIZEN_QR', (string)$qrOpeningRows[0]['recipient_role'] === 'CITIZEN_QR');
$assert(
    'El payload ciudadano enlaza a la vista pública QR y no expone la descripción',
    str_contains((string)$payloadOf($qrOpeningRows[0])['tracking_url'], '/?qr=')
        && !array_key_exists('description', $payloadOf($qrOpeningRows[0]))
);

$silentQr = $fixture('TEMPERATURE_COLD', 'CRITICAL', null, true);
$enqueuer->enqueueIncidentOpened(
    $silentQr['incident'],
    null,
    null,
    NotificationOutboxEnqueuerService::ORIGIN_CITIZEN_QR,
    null
);
$assert(
    'RF-02.4: el ciudadano QR sin correo no genera aviso ciudadano',
    $fetchRows((int)$silentQr['incident']->getId(), 'INCIDENT_OPENED_CITIZEN_QR') === []
        && count($fetchRows((int)$silentQr['incident']->getId(), 'INCIDENT_OPENED_SITE')) === 1
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. RF-03.1 / RF-03.2 / RF-04.4 — Alerta Inmediata de Frío y deduplicación
// ─────────────────────────────────────────────────────────────────────────────
$coldRows = $fetchRows($qrId, 'COLD_CHAIN_IMMEDIATE_ALERT');
$assert('RF-03.1: la avería térmica perecedera dispara la Alerta Inmediata de Frío', count($coldRows) >= 1);
$assert(
    'La alerta de frío viaja con prioridad CRITICAL_SANITARY',
    $column($coldRows, 'priority') === array_fill(0, count($coldRows), 'CRITICAL_SANITARY')
);
$assert(
    'La alerta de frío llega a Coordinación y al técnico de guardia',
    in_array($coordinatorEmail, $column($coldRows, 'recipient'), true)
        && $technician !== null
        && in_array(strtolower($technician->getEmail()), $column($coldRows, 'recipient'), true)
);
$assert(
    'RF-03.2: sin técnico asignado y con guardia activa no se emite el aviso «sin guardia»',
    !str_contains((string)$coldRows[0]['subject'], 'SIN TÉCNICO DE GUARDIA')
);
$assert(
    'Las filas de frío llevan clave de deduplicación por ciclo y destinatario',
    $coldRows !== [] && trim((string)$coldRows[0]['dedup_key']) !== ''
);

$dedupBefore = count($coldRows);
$enqueuer->enqueueColdChainAlert($incidents->findById($qrId));
$assert(
    'RF-04.4: repetir la alerta del mismo ciclo no duplica filas',
    count($fetchRows($qrId, 'COLD_CHAIN_IMMEDIATE_ALERT')) === $dedupBefore
);

$users->setOnCall(2, false);
$third = $fixture('TEMPERATURE_COLD', 'CRITICAL', null, true);
$enqueuer->enqueueIncidentOpened($third['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_LOCATION_PORTAL, null);
$thirdColdRows = $fetchRows((int)$third['incident']->getId(), 'COLD_CHAIN_IMMEDIATE_ALERT');
$assert(
    'RF-03.2: sin técnico de guardia el asunto incluye el literal completo de advertencia',
    $thirdColdRows !== [] && str_contains(
        (string)$thirdColdRows[0]['subject'],
        'SIN TÉCNICO DE GUARDIA DESIGNADO: Requiere asignación manual inmediata'
    )
);
$users->setOnCall(2, true);

// ─────────────────────────────────────────────────────────────────────────────
// 4. RF-05.2 / RF-03.1 — Asignación, reasignación y reclasificación crítica
// ─────────────────────────────────────────────────────────────────────────────
$assign = $fixture('PRODUCT_JAM', 'MEDIUM');
$assignId = (int)$assign['incident']->getId();
$assigned = $incidents->assign($assignId, 2, $coordinatorId);
$enqueuer->enqueueAssignment($assign['incident'], $assigned);
$assignedRows = $fetchRows($assignId, 'TECHNICIAN_ASSIGNED');
$assert('RF-05.2: la asignación encola el aviso al técnico entrante', count($assignedRows) === 1);
$assert(
    'El aviso de asignación lleva urgencia, sede y máquina',
    (string)$assignedRows[0]['recipient'] === strtolower($technician->getEmail())
        && (string)$payloadOf($assignedRows[0])['site_name'] !== ''
        && (string)$payloadOf($assignedRows[0])['machine_code'] === $assign['machine_code']
);

$reassigned = $incidents->assign(
    incidentId: $assignId,
    technicianId: 3,
    coordinatorId: $coordinatorId,
    reassignmentReason: 'Reasignación de certificación T-NOTIF-06'
);
$enqueuer->enqueueAssignment($assigned, $reassigned);
$assert(
    'RF-05.2: la reasignación avisa al entrante y al saliente',
    count($fetchRows($assignId, 'TECHNICIAN_ASSIGNED')) === 2
        && count($fetchRows($assignId, 'TECHNICIAN_UNASSIGNED')) === 1
);
$unassignedRows = $fetchRows($assignId, 'TECHNICIAN_UNASSIGNED');
$assert(
    'El aviso de desasignación llega exactamente al técnico saliente',
    (string)$unassignedRows[0]['recipient'] === strtolower($technician->getEmail())
);

$critical = $fixture('PRODUCT_JAM', 'MEDIUM', null, true);
$criticalId = (int)$critical['incident']->getId();
$criticalBefore = $incidents->assign($criticalId, 2, $coordinatorId);
$criticalAfter = $incidents->assign(
    incidentId: $criticalId,
    technicianId: 3,
    coordinatorId: $coordinatorId,
    urgencyOverride: 'CRITICAL',
    urgencyReason: 'Reclasificación a crítica por riesgo alimentario (Art. II)',
    reassignmentReason: 'Reclasificación crítica de certificación T-NOTIF-06'
);
$enqueuer->enqueueAssignment($criticalBefore, $criticalAfter);
$reclassifiedRows = $fetchRows($criticalId, 'COLD_CHAIN_IMMEDIATE_ALERT');
$assert(
    'RF-03.1(b): la reclasificación a CRITICAL dispara la alerta de frío en máquina bajo vigilancia',
    count($reclassifiedRows) >= 1
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. RF-05.3 — Pausa PENDING_INFO exclusivamente a la Sede
// ─────────────────────────────────────────────────────────────────────────────
$pauseService = new IncidentPauseService(
    incidentRepo: $incidents,
    notificationEnqueuer: $enqueuer
);
$pause = $fixture('PRODUCT_JAM', 'MEDIUM', $reporterEmail);
$pauseId = (int)$pause['incident']->getId();
$incidents->assign($pauseId, 2, $coordinatorId);
$pauseService->declarePendingInfoPause(
    $pauseId,
    $coordinatorId,
    IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
    'La sede permanece cerrada fuera del horario de apertura autorizado.'
);
$pauseRows = $fetchRows($pauseId, 'PENDING_INFO_SITE_REQUEST');
$assert('RF-05.3: la pausa encola la solicitud de acceso a la Sede', count($pauseRows) === 1);
$assert(
    'La solicitud de pausa va SÓLO al correo oficial, jamás al informador ni al ciudadano',
    (string)$pauseRows[0]['recipient'] === $pause['official']
        && (string)$pauseRows[0]['recipient_role'] === 'LOCATION'
);
$assert(
    'El payload de pausa publica causa tipificada y motivo explicativo',
    (string)($payloadOf($pauseRows[0])['reason_category_label'] ?? '') !== ''
        && mb_strlen((string)($payloadOf($pauseRows[0])['reason_text'] ?? '')) >= 20
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. RF-05.4 — Respuesta de Sede con agrupación de ráfagas
// ─────────────────────────────────────────────────────────────────────────────
$commentService = new IncidentCommentService(
    incidentRepo: $incidents,
    auditLogger: null,
    fileUploader: null,
    fileCompensator: null,
    notificationEnqueuer: $enqueuer
);
$commentService->addComment(
    $pauseId,
    'SITE_MANAGER',
    'Confirmamos la apertura del edificio mañana a las 08:00 para el acceso técnico.'
);
$replyRows = $fetchRows($pauseId, 'PENDING_INFO_SITE_REPLY');
$assert('RF-05.4: la respuesta pública de Sede encola el aviso al técnico asignado', count($replyRows) === 1);
$assert(
    'El aviso de respuesta llega al técnico responsable',
    (string)$replyRows[0]['recipient'] === strtolower($technician->getEmail())
        && (string)$replyRows[0]['recipient_role'] === 'TECHNICIAN'
);

$commentService->addComment(
    $pauseId,
    'SITE_MANAGER',
    'Actualizamos: el conserje estará en la puerta principal hasta las diez de la mañana.'
);
$replyRowsAfterBurst = $fetchRows($pauseId, 'PENDING_INFO_SITE_REPLY');
$assert(
    'RF-05.4: la ráfaga reutiliza la fila PENDING en lugar de duplicar correos',
    count($replyRowsAfterBurst) === 1
        && str_contains((string)$payloadOf($replyRowsAfterBurst[0])['site_reply_text'], 'conserje')
);

// ─────────────────────────────────────────────────────────────────────────────
// 7. RF-05.5 — Resolución con garantía de 48 h (Sede y Ciudadano QR)
// ─────────────────────────────────────────────────────────────────────────────
$resolve = $fixture('PRODUCT_JAM', 'MEDIUM', $reporterEmail);
$resolveId = (int)$resolve['incident']->getId();
$incidents->assign($resolveId, 2, $coordinatorId);
$incidents->startIntervention($resolveId, 2);
$resolved = $incidents->resolve(
    $resolveId,
    2,
    'Diagnóstico de certificación: mecanismo de expulsión desalineado en la planta 4.',
    'Acción de certificación: realineado el mecanismo y verificadas diez extracciones consecutivas.'
);
$enqueuer->enqueueResolution($resolved);
$resolvedRows = $fetchRows($resolveId, 'INCIDENT_RESOLVED_SITE');
$assert('RF-05.5: la resolución encola la confirmación a la Sede', count($resolvedRows) === 2);
$assert(
    'La confirmación de servicio restablecido anuncia la garantía de 48 h',
    str_contains(strtolower((string)$resolvedRows[0]['subject']), '48')
        || str_contains(strtolower((string)json_encode($payloadOf($resolvedRows[0]))), '48')
);
$assert(
    'La resolución SÍ puede llevar el alias neutral del técnico, nunca su identidad real',
    $technician !== null && !str_contains((string)$resolvedRows[0]['subject'], (string)$technician->getName())
);

$qrResolve = $fixture('TEMPERATURE_COLD', 'CRITICAL', $citizenEmail, true);
$qrResolveId = (int)$qrResolve['incident']->getId();
// La apertura ciudadana deja la huella que habilita la proyección pública
// (el expediente se abrió por QR y el correo original viajó en ella).
$enqueuer->enqueueIncidentOpened($qrResolve['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_CITIZEN_QR, $citizenEmail);
$incidents->assign($qrResolveId, 2, $coordinatorId);
$incidents->startIntervention($qrResolveId, 2);
$qrResolved = $incidents->resolve(
    $qrResolveId,
    2,
    'Diagnóstico de certificación: termostato sustituido y cadena de frío restablecida.',
    'Acción de certificación: verificación térmica durante una hora con registro continuo.'
);
$enqueuer->enqueueResolution($qrResolved);
$qrResolvedSiteRows = $fetchRows($qrResolveId, 'INCIDENT_RESOLVED_SITE');
$assert(
    'RF-05.5: el Ciudadano QR original recibe su confirmación de resolución',
    count($fetchRows($qrResolveId, 'INCIDENT_RESOLVED_CITIZEN_QR')) === 1
);
$assert(
    'RF-02.3: un expediente QR no recibe la proyección de Sede (el ciudadano nunca ve el portal de sede)',
    count($qrResolvedSiteRows) === 1
        && !in_array($citizenEmail, $column($qrResolvedSiteRows, 'recipient'), true)
);

// ─────────────────────────────────────────────────────────────────────────────
// 8. RF-05.7 / RF-03.1 — Reapertura en garantía y Avería Crónica
// ─────────────────────────────────────────────────────────────────────────────
$reopen = $fixture('TEMPERATURE_COLD', 'CRITICAL', null, true);
$reopenId = (int)$reopen['incident']->getId();
$incidents->assign($reopenId, 2, $coordinatorId);
$incidents->startIntervention($reopenId, 2);
$incidents->resolve(
    $reopenId,
    2,
    'Diagnóstico de certificación para reapertura: sensor de temperatura degradado en cámara.',
    'Acción de certificación para reapertura: sensor sustituido y calibrado con patrón externo.'
);
$reopened = $incidents->reopenWithLifecycleNotifications(
    $reopenId,
    'El fallo térmico persiste tras la intervención y la máquina vuelve a perder frío.',
    function (Incident $reopenedIncident) use ($enqueuer): void {
        $enqueuer->enqueueReopened($reopenedIncident);
    }
);
$assert(
    'RF-05.7: la reapertura encola el aviso a Coordinación',
    count($fetchRows($reopenId, 'INCIDENT_REOPENED_COORDINATOR')) === 1
);
$assert(
    'RF-03.1: el ciclo reabierto vuelve a permitir la Alerta Inmediata de Frío',
    count($fetchRows($reopenId, 'COLD_CHAIN_IMMEDIATE_ALERT')) >= 2
);
$assert(
    'La reapertura deja el expediente sin técnico asignado',
    $reopened->getAssignedTechnicianId() === null && $reopened->getStatus() === IncidentStatus::REOPENED
);

// Tercera reincidencia: el repositorio marca Avería Crónica y el callback encola la alerta
// dentro de la misma transacción que el sello (RF-05.7).
$reopenCountLimitHit = false;
for ($cycle = 1; $cycle <= 2; $cycle++) {
    $incidents->assign($reopenId, 2, $coordinatorId);
    $incidents->startIntervention($reopenId, 2);
    $incidents->resolve(
        $reopenId,
        2,
        'Diagnóstico de certificación del ciclo ' . $cycle . ': se repite la pérdida de frío en la misma máquina.',
        'Acción de certificación del ciclo ' . $cycle . ': verificación completa de la cámara refrigerada.'
    );

    try {
        $incidents->reopenWithLifecycleNotifications(
            $reopenId,
            'Nueva reincidencia certificada del mismo fallo térmico en la máquina perecedera.',
            function (Incident $reopenedIncident) use ($enqueuer): void {
                $enqueuer->enqueueReopened($reopenedIncident);
            },
            function (Incident $chronic) use ($enqueuer): void {
                $enqueuer->enqueueChronicBreakdown($chronic);
            }
        );
    } catch (\VendGuard\Core\Domain\Exception\ChronicIncidentException) {
        $reopenCountLimitHit = true;
    }
}
$assert('RF-05.7: la 3.ª reincidencia bloquea la reapertura automática', $reopenCountLimitHit);
$assert(
    'RF-05.7: la Avería Crónica encola su alerta a Coordinación',
    count($fetchRows($reopenId, 'CHRONIC_BREAKDOWN_ALERT')) === 1
);

// ─────────────────────────────────────────────────────────────────────────────
// 9. RF-05.8 / RF-07.1 — Cancelación con advertencia de bloqueo y obsolescencia
// ─────────────────────────────────────────────────────────────────────────────
$cancel = $fixture('PRODUCT_JAM', 'MEDIUM', $reporterEmail);
$cancelId = (int)$cancel['incident']->getId();
$enqueuer->enqueueIncidentOpened($cancel['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_LOCATION_PORTAL, $reporterEmail);
$cancelled = $incidents->cancel(
    $cancelId,
    'Cancelación de certificación T-NOTIF-06 con motivo suficientemente descriptivo.',
    $coordinatorId
);
$enqueuer->enqueueCancellation($cancelled, true);
$cancelRows = $fetchRows($cancelId, 'INCIDENT_CANCELLED_SITE');
$assert('RF-05.8: la cancelación encola el aviso informativo a la Sede', count($cancelRows) === 2);
$assert(
    'La cancelación advierte de la máquina bloqueada tras 72 h hábiles',
    ($payloadOf($cancelRows[0])['blocked_after_72h'] ?? false) === true
);
$assert(
    'RF-07.1: los avisos previos pendientes del expediente quedan cancelados por obsolescencia',
    (int)$pdo->query("SELECT COUNT(*) FROM notification_outbox WHERE incident_id = {$cancelId} AND status = 'CANCELLED_OBSOLETE'")->fetchColumn() === 2
);

// La cancelación de un expediente nacido por QR también cierra el canal ciudadano:
// quien reportó la avería recibe el aviso de cierre sin ver la proyección de Sede.
$qrCancel = $fixture('PRODUCT_JAM', 'MEDIUM', $citizenEmail, true);
$qrCancelId = (int)$qrCancel['incident']->getId();
$enqueuer->enqueueIncidentOpened($qrCancel['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_CITIZEN_QR, $citizenEmail);
$qrCancelled = $incidents->cancel(
    $qrCancelId,
    'Cierre administrativo del aviso ciudadano en la certificación T-NOTIF-10.',
    $coordinatorId
);
$enqueuer->enqueueCancellation($qrCancelled, false);
$qrCancelRows = $fetchRows($qrCancelId, 'INCIDENT_CANCELLED_CITIZEN_QR');
$qrCancelSiteRows = $fetchRows($qrCancelId, 'INCIDENT_CANCELLED_SITE');
$assert(
    'RF-05.8: la cancelación de un expediente QR cierra el canal ciudadano',
    count($qrCancelRows) === 1
        && (string)$qrCancelRows[0]['recipient'] === strtolower($citizenEmail)
        && (string)$qrCancelRows[0]['recipient_role'] === 'CITIZEN_QR'
        && !in_array(strtolower($citizenEmail), $column($qrCancelSiteRows, 'recipient'), true),
    'Ciudadanas: ' . count($qrCancelRows)
        . ' · Site: ' . count($qrCancelSiteRows)
        . ' · Destinatarios sitio: ' . implode(', ', $column($qrCancelSiteRows, 'recipient'))
);

// ─────────────────────────────────────────────────────────────────────────────
// 10. RF-05.9 — Acuse puntual al segundo informador QR
// ─────────────────────────────────────────────────────────────────────────────
$secondCitizenEmail = 'segundo.informador.qr@example.com';
$ack = $fixture('PRODUCT_JAM', 'MEDIUM', $citizenEmail);
$ackId = (int)$ack['incident']->getId();
$enqueuer->enqueueIncidentOpened($ack['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_CITIZEN_QR, $citizenEmail);
$enqueuer->enqueueCitizenObservationAck(
    $ack['incident'],
    $secondCitizenEmail,
    'Aporto una observación adicional sobre la misma máquina averiada.'
);
$ackRows = $fetchRows($ackId, 'CITIZEN_QR_OBSERVATION_ACK');
$assert('RF-05.9: el segundo informador recibe su acuse puntual', count($ackRows) === 1);
$assert(
    'El acuse llega al segundo informador sin suplantar el correo del reportador original',
    (string)$ackRows[0]['recipient'] === $secondCitizenEmail
        && (string)$incidents->findById($ackId)->getReporterEmail() === $citizenEmail
);
$siteAck = $fixture('PRODUCT_JAM', 'MEDIUM', $reporterEmail);
$enqueuer->enqueueIncidentOpened($siteAck['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_LOCATION_PORTAL, $reporterEmail);
$assert(
    'Un expediente abierto por la Sede no recibe la proyección ciudadana',
    $enqueuer->enqueueCitizenObservationAck($siteAck['incident'], $reporterEmail, 'Observación de sede que no es ciudadana.') === 0
        && $fetchRows((int)$siteAck['incident']->getId(), 'CITIZEN_QR_OBSERVATION_ACK') === []
);

// ─────────────────────────────────────────────────────────────────────────────
// 11. RF-03.3 — Cuarentena Sanitaria 4h con deduplicación por episodio
// ─────────────────────────────────────────────────────────────────────────────
$quarantine = $fixture('TEMPERATURE_COLD', 'CRITICAL', null, true);
$quarantineId = (int)$quarantine['incident']->getId();
$enqueuer->enqueueIncidentOpened($quarantine['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_LOCATION_PORTAL, null);
$quarantineEnqueued = $enqueuer->enqueueSanitaryQuarantine($quarantine['incident']);
$quarantineInternal = $fetchRows($quarantineId, 'SANITARY_QUARANTINE_TRIGGERED');
$quarantineSite = $fetchRows($quarantineId, 'SITE_SANITARY_QUARANTINE_NOTICE');
$assert('RF-03.3: la cuarentena encola la alerta crítica interna', $quarantineEnqueued >= 1 && count($quarantineInternal) >= 1);
$assert('RF-03.3: la cuarentena encola el aviso preventivo a la Sede', count($quarantineSite) === 1);
$assert(
    'La cuarentena interna viaja con prioridad crítica y el aviso a Sede llega al correo oficial',
    (string)$quarantineInternal[0]['priority'] === 'CRITICAL_SANITARY'
        && (string)$quarantineSite[0]['recipient'] === $quarantine['official']
);
$internalCount = count($quarantineInternal);
$enqueuer->enqueueSanitaryQuarantine($quarantine['incident']);
$assert(
    'La cuarentena del mismo ciclo no vuelve a alarmar al mismo destinatario',
    count($fetchRows($quarantineId, 'SANITARY_QUARANTINE_TRIGGERED')) === $internalCount
        && count($fetchRows($quarantineId, 'SITE_SANITARY_QUARANTINE_NOTICE')) === 1
);

// ─────────────────────────────────────────────────────────────────────────────
// 12. RF-03.4 — Escalado por contención persistente (una alerta por episodio)
// ─────────────────────────────────────────────────────────────────────────────
$escalation = $fixture('PRODUCT_JAM', 'MEDIUM');
$escalationId = (int)$escalation['incident']->getId();
$episodeKey = '2026-10-17 10:00:00';
$enqueuer->enqueueSanitarySweepEscalated($escalation['incident'], 3, $episodeKey);
$escalatedRows = $fetchRows($escalationId, 'SANITARY_SWEEP_ESCALATED');
$assert('RF-03.4: el escalado de contención encola la alerta a Coordinación', count($escalatedRows) >= 1);
$assert(
    'La alerta de escalado explica el episodio y las pasadas consecutivas',
    (int)($payloadOf($escalatedRows[0])['contention_passes'] ?? 0) === 3
        && (string)($payloadOf($escalatedRows[0])['episode_key'] ?? '') === $episodeKey
);
$escalatedCount = count($escalatedRows);
$enqueuer->enqueueSanitarySweepEscalated($escalation['incident'], 4, $episodeKey);
$assert(
    'El mismo episodio de escalado no repite la alerta',
    count($fetchRows($escalationId, 'SANITARY_SWEEP_ESCALATED')) === $escalatedCount
);

// ─────────────────────────────────────────────────────────────────────────────
// 13. RF-03.1 / RF-06.5 — Canal corporativo de emergencias (Webhook)
// ─────────────────────────────────────────────────────────────────────────────
$webhookSettings = $settings->findCurrent();
$webhookSettings->updateConfiguration(
    'Canal de certificación T-NOTIF-06',
    'https://emergencias.example.org/hook-tnotif06',
    true,
    $coordinatorId
);
$settings->save($webhookSettings);

$webhook = $fixture('TEMPERATURE_COLD', 'CRITICAL', null, true);
$webhookId = (int)$webhook['incident']->getId();
$enqueuer->enqueueIncidentOpened($webhook['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_LOCATION_PORTAL, null);
$webhookRows = $fetchRows($webhookId, 'COLD_CHAIN_IMMEDIATE_ALERT');
$webhookOnly = array_values(array_filter(
    $webhookRows,
    static fn (array $row): bool => (string)$row['channel'] === 'WEBHOOK'
));
$assert('RF-03.1: la alerta de frío sale también por el canal corporativo', count($webhookOnly) === 1);
$assert(
    'La fila Webhook apunta a la URL configurada y no lleva destinatario de sede',
    (string)$webhookOnly[0]['recipient'] === 'https://emergencias.example.org/hook-tnotif06'
        && (string)$webhookOnly[0]['recipient_role'] === 'WEBHOOK'
        && $webhookOnly[0]['recipient_location_id'] === null
);

$disable = $settings->findCurrent();
$disable->updateConfiguration('Canal de certificación T-NOTIF-06', 'https://emergencias.example.org/hook-tnotif06', false, $coordinatorId);
$settings->save($disable);

// ─────────────────────────────────────────────────────────────────────────────
// 14. RF-01.2 — Atomicidad: si la transacción de negocio revierte, no queda aviso
// ─────────────────────────────────────────────────────────────────────────────
$atomic = $fixture('PRODUCT_JAM', 'MEDIUM');
$atomicId = (int)$atomic['incident']->getId();
try {
    $transactions->runInTransaction(function () use ($enqueuer, $atomic): void {
        $enqueuer->enqueueCancellation($atomic['incident'], false);
        throw new RuntimeException('Fallo simulado después de encolar para certificar el rollback.');
    });
    $assert('RF-01.2: la transacción simulada debía fallar', false);
} catch (RuntimeException) {
    $assert(
        'RF-01.2: un fallo posterior revierte el aviso encolado (nunca sale un correo de una transacción no confirmada)',
        $fetchRows($atomicId, 'INCIDENT_CANCELLED_SITE') === []
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// 15. T-NOTIF-10 — Censo del catálogo: los 19 eventos tienen sus 19 plantillas
// ─────────────────────────────────────────────────────────────────────────────
$expectedBusinessTemplates = array_values(array_filter(
    array_map(static fn (NotificationTemplate $template): string => $template->value, NotificationTemplate::cases()),
    static fn (string $value): bool => !in_array($value, [
        NotificationTemplate::SLA_IMMINENCE_2H_UNASSIGNED->value,
        NotificationTemplate::SLA_IMMINENCE_3H_UNSTARTED->value,
    ], true)
));
$observedTemplates = array_map(
    'strval',
    $pdo->query('SELECT DISTINCT template FROM notification_outbox WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_COLUMN)
);
sort($expectedBusinessTemplates);
sort($observedTemplates);

$assert(
    'T-NOTIF-10: el catálogo cerrado mantiene exactamente 19 plantillas',
    count(NotificationTemplate::cases()) === 19
);
$assert(
    'T-NOTIF-10: los 17 eventos de negocio del catálogo quedan encolados por esta suite',
    $observedTemplates === $expectedBusinessTemplates,
    'Faltan: [' . implode(', ', array_diff($expectedBusinessTemplates, $observedTemplates)) . ']'
        . ' · Sobran: [' . implode(', ', array_diff($observedTemplates, $expectedBusinessTemplates)) . ']'
);
$assert(
    'T-NOTIF-10: las dos plantillas de inminencia de SLA no nacen de un evento de negocio',
    !in_array(NotificationTemplate::SLA_IMMINENCE_2H_UNASSIGNED->value, $observedTemplates, true)
        && !in_array(NotificationTemplate::SLA_IMMINENCE_3H_UNSTARTED->value, $observedTemplates, true)
);

// ─────────────────────────────────────────────────────────────────────────────
// Cierre y limpieza
// ─────────────────────────────────────────────────────────────────────────────
$users->setOnCall(2, false);
TestDataCleaner::purge($pdo);

echo "\n";
echo "──────────────────────────────────────────────────────────────────────\n";
echo sprintf(" Total Aserciones: %d | Fallos: %d\n", $assertions, $failures);
echo "──────────────────────────────────────────────────────────────────────\n";

exit($failures === 0 ? 0 : 1);
