<?php

declare(strict_types=1);

/**
 * OpportunisticNotificationAndPrivacyTest (Módulo 16 · T-OPP-07)
 *
 * Certifica contra PostgreSQL la matriz de notificaciones de la avería oportunista
 * (RF-06.3), sus reglas de obsolescencia y el blindaje de privacidad del Art. V.4:
 *
 *   1. **Postergación de una parada crítica/frío** (`RF-03.2.2`, Resolución QA 1):
 *      `OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT` sale con prioridad
 *      `CRITICAL_SANITARY` a los coordinadores activos y al Webhook corporativo,
 *      con la parada postergada y el origen del hallazgo en el cuerpo.
 *   2. **Reapertura in situ en garantía** (`RF-06.3.2`): la Sede recibe
 *      `INCIDENT_REOPENED_IN_SITU_SITE` y Coordinación
 *      `INCIDENT_REOPENED_COORDINATOR`, ambos por correo y sin diagnóstico.
 *   3. **Asunción simple** (`RF-06.3.1`): cero correo redundante a la Sede cuando
 *      el técnico solo pasa a En Curso un expediente ya abierto.
 *   4. **Traspaso entre técnicos** (`RF-06.3.3` y Módulo 15 `RF-TG-06`): el aviso
 *      de «Nueva asignación» pendiente del reemplazado —correo y Telegram— se
 *      cancela por obsolescencia y la desasignación viaja SOLO por correo, sin botón.
 *   5. **Privacidad** (Resolución QA 5): el técnico aparece ante la Sede como
 *      `Servicio Técnico Oficial · Operador #[Código]`, la nota interna de traspaso
 *      no sale del expediente y el informante verbal nunca llega al Ciudadano QR.
 *
 * El escenario usa las mismas máquinas sembradas que la suite de escrituras
 * atómicas, purgadas antes de empezar, para que el índice único de avería activa
 * no interfiera entre casos.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\NotificationOutboxEnqueuerService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Domain\ValueObject\OpportunisticMode;
use VendGuard\Core\Domain\ValueObject\OpportunisticOriginReason;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Service\AppPublicUrl;
use VendGuard\Core\Service\NotificationObsolescencePolicy;
use VendGuard\Core\Service\NotificationPayloadSanitizer;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Notification\Template\NotificationMessageRenderer;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard · Módulo 16 · T-OPP-07 · Notificaciones y privacidad oportunistas\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();

$locations = new PdoLocationRepository($pdo);
$machines = new PdoMachineRepository($pdo);
$users = new PdoUserRepository($pdo);
$incidents = new PdoIncidentRepository($pdo);
$outbox = new PdoNotificationOutboxRepository($pdo);
$settings = new PdoNotificationSettingsRepository($pdo, $outbox);
$transactions = new PdoTransactionManager($pdo);

// El host público se declara ANTES de construir el renderizador: los botones se
// absolutizan en el momento de redactar y el arnés debe medir el correo real.
$previousBaseUrl = getenv('APP_BASE_URL');
$previousTelegramToken = getenv('VENDGUARD_TELEGRAM_BOT_TOKEN');
$previousTelegramAlias = getenv('TG_BOT_TOKEN');

putenv('APP_BASE_URL=https://vendguard.test');
putenv('VENDGUARD_TELEGRAM_BOT_TOKEN');
putenv('TG_BOT_TOKEN');

$renderer = new NotificationMessageRenderer(AppPublicUrl::fromEnvironment());

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

// ─────────────────────────────────────────────────────────────────────────────
// Contrato del entorno y del banco de fixtures
// ─────────────────────────────────────────────────────────────────────────────
$coordinatorRows = $users->findAll(['status' => 'active', 'role' => 'COORDINATOR']);
$coordinatorCount = count($coordinatorRows);
$coordinatorId = (int)($coordinatorRows[0]['id'] ?? 1);

$jordi = $users->findByEmail('jordi.ruta@vendguard.internal');
$marta = $users->findByEmail('marta.ruta@vendguard.internal');

$assert('0.1 Hay al menos un coordinador activo con correo', $coordinatorCount > 0);
$assert('0.2 Los dos técnicos de certificación existen', $jordi !== null && $marta !== null);

$timestamp = static fn (): string => (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');

/**
 * Crea un expediente limpio sobre una máquina sembrada y devuelve su contexto.
 *
 * @return array{incident: Incident, machine: object, location: object}
 */
$fixture = function (
    string $ticketCode,
    string $machineCode,
    IncidentStatus $status,
    ?int $technicianId,
    ?OpportunisticMode $mode = null,
    ?OpportunisticOriginReason $origin = null,
    ?string $informant = null,
    ?string $reporterEmail = null,
    bool $opportunisticCreation = false
) use ($machines, $locations, $incidents, $timestamp): array {
    $machine = $machines->findByCode($machineCode);
    if ($machine === null) {
        throw new RuntimeException("La máquina sembrada {$machineCode} no existe.");
    }

    $location = $locations->findById($machine->getLocationId());
    if ($location === null) {
        throw new RuntimeException("La sede de la máquina {$machineCode} no existe.");
    }

    $incident = new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: (int)$machine->getId(),
        locationId: (int)$location->getId(),
        category: IncidentCategory::PRODUCT_JAM,
        description: 'Atasco en espiral B4 detectado durante la visita a la sede.',
        urgency: UrgencyLevel::MEDIUM,
        status: $status,
        assignedTechnicianId: $technicianId,
        reporterName: 'Informador de certificación T-OPP-07',
        reporterEmail: $reporterEmail,
        assignedAt: $technicianId !== null ? $timestamp() : null,
        startedAt: $status === IncidentStatus::IN_PROGRESS ? $timestamp() : null,
        cycleStartedAt: $timestamp(),
        opportunisticMode: $mode,
        opportunisticOriginReason: $origin,
        opportunisticInformantName: $informant
    );

    $incident = $opportunisticCreation
        ? $incidents->createOpportunisticIncident(
            $incident,
            (int)$technicianId,
            'Alta Oportunista In Situ (Oportunista In Situ · Nueva · Aviso verbal en sede). Intervención iniciada en el acto.'
        )
        : $incidents->create($incident, null, 'Alta de certificación T-OPP-07');

    return ['incident' => $incident, 'machine' => $machine, 'location' => $location];
};

$machineCodes = ['VEND-0301', 'VEND-0302', 'VEND-0401', 'VEND-0501'];
foreach ($machineCodes as $code) {
    $machine = $machines->findByCode($code);
    if ($machine !== null) {
        TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machine->getId());
    }
}

/** @return list<array<string, mixed>> */
$fetchRows = function (int $incidentId, ?string $template = null) use ($pdo): array {
    $sql = "SELECT * FROM notification_outbox
            WHERE incident_id = :incident_id AND deleted_at IS NULL";
    $params = [':incident_id' => $incidentId];
    if ($template !== null) {
        $sql .= ' AND template = :template';
        $params[':template'] = $template;
    }
    $sql .= ' ORDER BY id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

/** @return array<string, mixed> */
$payloadOf = static fn (array $row): array => json_decode((string)($row['payload_json'] ?? '[]'), true) ?? [];

/** @param list<array<string, mixed>> $rows */
$column = static fn (array $rows, string $field): array => array_values(array_map(
    static fn (array $row): string => (string)($row[$field] ?? ''),
    $rows
));

// El canal corporativo parte apagado; se enciende solo para el caso de postergación.
$currentSettings = $settings->findCurrent();
$currentSettings->updateConfiguration('Canal de certificación T-OPP-07', null, false, $coordinatorId);
$settings->save($currentSettings);

if ($coordinatorCount > 0 && $jordi !== null && $marta !== null) {
    // ─────────────────────────────────────────────────────────────────────────
    // 1. RF-03.2.2 / Resolución QA 1 — Posponer una parada crítica de frío
    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- Caso 1: Alerta a Coordinación por posponer una parada crítica/frío ---\n";

    $opened = $fixture(
        'INC-2026-OPP07-A',
        'VEND-0301',
        IncidentStatus::IN_PROGRESS,
        $jordi->getId(),
        OpportunisticMode::NEW,
        OpportunisticOriginReason::SITE_VERBAL_NOTICE,
        'Marta Gómez (Conserjería)',
        null,
        true
    );
    $opportunistic = $opened['incident'];
    $opportunisticId = (int)$opportunistic->getId();

    $webhookSettings = $settings->findCurrent();
    $webhookSettings->updateConfiguration(
        'Canal de certificación T-OPP-07',
        'https://emergencias.example.org/hook-topp07',
        true,
        $coordinatorId
    );
    $settings->save($webhookSettings);

    $postponedStops = [[
        'ticket_code' => 'INC-2026-CRITICA-77',
        'location_name' => 'Sede Norte · Piso 2',
        'is_sanitary' => true,
    ]];

    $enqueuedPostponed = $enqueuer->enqueueOpportunisticColdRoutePostponed(
        $opportunistic,
        $postponedStops
    );
    $postponedRows = $fetchRows($opportunisticId, NotificationTemplate::OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT->value);
    $postponedEmailRows = array_values(array_filter(
        $postponedRows,
        static fn (array $row): bool => (string)$row['channel'] === NotificationChannel::EMAIL->value
    ));
    $postponedWebhookRows = array_values(array_filter(
        $postponedRows,
        static fn (array $row): bool => (string)$row['channel'] === NotificationChannel::WEBHOOK->value
    ));

    $assert(
        '1.1 La alerta se encola a cada coordinador y al Webhook corporativo',
        $enqueuedPostponed === $coordinatorCount + 1
            && count($postponedEmailRows) === $coordinatorCount
            && count($postponedWebhookRows) === 1,
        'Encoladas: ' . $enqueuedPostponed
    );
    $assert(
        '1.2 La prioridad es CRITICAL_SANITARY en todos los canales',
        array_unique(array_merge(
            $column($postponedEmailRows, 'priority'),
            $column($postponedWebhookRows, 'priority')
        )) === ['CRITICAL_SANITARY']
    );
    $assert(
        '1.3 El Webhook apunta al canal corporativo configurado',
        $postponedWebhookRows !== []
            && (string)$postponedWebhookRows[0]['recipient'] === 'https://emergencias.example.org/hook-topp07'
            && (string)$postponedWebhookRows[0]['recipient_role'] === 'WEBHOOK'
    );

    $postponedPayload = $payloadOf($postponedEmailRows[0] ?? []);
    $assert(
        '1.4 El payload identifica la parada postergada y el origen del hallazgo',
        (int)($postponedPayload['postponed_stops_count'] ?? 0) === 1
            && ($postponedPayload['postponed_stop_location'] ?? null) === 'Sede Norte · Piso 2'
            && ($postponedPayload['postponed_stop_ticket'] ?? null) === 'INC-2026-CRITICA-77'
            && ($postponedPayload['opportunistic_origin_label'] ?? null) === 'Aviso verbal en sede'
    );

    $postponedMessage = $renderer->render(
        NotificationTemplate::OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT,
        $postponedPayload
    );
    $assert(
        '1.5 El correo nombra la parada crítica y al técnico actuante',
        str_contains($postponedMessage->subject, 'Parada crítica pospuesta')
            && str_contains($postponedMessage->textBody, 'Sede Norte · Piso 2')
            && str_contains($postponedMessage->textBody, 'Servicio Técnico Oficial · Operador #')
    );

    $enqueuedDuplicate = $enqueuer->enqueueOpportunisticColdRoutePostponed($opportunistic, $postponedStops);
    $assert(
        '1.6 La alerta es idempotente por ciclo de atención',
        $enqueuedDuplicate === 0
            && count($fetchRows($opportunisticId, NotificationTemplate::OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT->value)) === count($postponedRows)
    );

    // Alta Oportunista: la Sede recibe la confirmación «ya atendida in situ» (RF-06.3.1)
    $enqueuedOpening = $enqueuer->enqueueIncidentOpened($opportunistic);
    $openingRows = $fetchRows($opportunisticId, NotificationTemplate::INCIDENT_OPENED_SITE->value);
    $openingPayload = $payloadOf($openingRows[0] ?? []);
    $openingMessage = $renderer->render(NotificationTemplate::INCIDENT_OPENED_SITE, $openingPayload);

    $assert(
        '1.7 El alta oportunista avisa a la Sede en el acto',
        $enqueuedOpening === 1
            && count($openingRows) === 1
            && (string)$openingRows[0]['recipient_role'] === 'LOCATION'
    );
    $assert(
        '1.8 El correo de alta dice que ya se atiende in situ y nombra al informante',
        str_contains($openingMessage->subject, 'atención in situ')
            && str_contains($openingMessage->textBody, 'Oportunista In Situ · Nueva')
            && str_contains($openingMessage->textBody, 'Marta Gómez (Conserjería)')
    );
    $assert(
        '1.9 La Sede ve al técnico solo con el alias neutro (Art. V.4)',
        ($openingPayload['technical_service_label'] ?? null) === 'Servicio Técnico Oficial · Operador #'
            . sprintf('%02d', $jordi->getId())
            && !array_key_exists('technician_label', $openingPayload)
            && !str_contains($openingMessage->textBody, $jordi->getName())
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 2. RF-06.3.2 — Reapertura in situ en garantía
    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- Caso 2: Reapertura in situ avisa a Sede y Coordinación ---\n";

    $reopenSeed = $fixture(
        'INC-2026-OPP07-B',
        'VEND-0302',
        IncidentStatus::IN_PROGRESS,
        $jordi->getId()
    );
    $reopenId = (int)$reopenSeed['incident']->getId();
    $resolved = $incidents->resolve(
        $reopenId,
        $jordi->getId(),
        'Atasco en la espiral B4 por producto mal encarrilado en el carril.',
        'Se desbloquea la espiral, se recoloca el producto y se prueba el ciclo completo.'
    );
    $reopened = $incidents->reopenOpportunisticIncident(
        $reopenId,
        $jordi->getId(),
        'El mismo atasco reaparece pocos minutos después durante la visita.'
    );

    $siteEmail = strtolower(trim((string)$reopenSeed['location']->getContactEmail()));
    $enqueuedReopen = $enqueuer->enqueueInSituAssumption($resolved, $reopened);
    $reopenSiteRows = $fetchRows($reopenId, NotificationTemplate::INCIDENT_REOPENED_IN_SITU_SITE->value);
    $reopenCoordinatorRows = $fetchRows($reopenId, NotificationTemplate::INCIDENT_REOPENED_COORDINATOR->value);
    $reopenPayload = $payloadOf($reopenSiteRows[0] ?? []);

    $assert(
        '2.1 La Sede recibe exactamente un aviso de reapertura in situ por correo',
        $enqueuedReopen === 1 + $coordinatorCount
            && count($reopenSiteRows) === 1
            && (string)$reopenSiteRows[0]['channel'] === NotificationChannel::EMAIL->value
            && (string)$reopenSiteRows[0]['recipient_role'] === 'LOCATION'
            && (string)$reopenSiteRows[0]['recipient'] === $siteEmail,
        'Encoladas: ' . $enqueuedReopen . ' · Site: ' . json_encode($column($reopenSiteRows, 'recipient'))
    );
    $assert(
        '2.2 Coordinación recibe su propia plantilla de reapertura',
        count($reopenCoordinatorRows) === $coordinatorCount
            && array_unique($column($reopenCoordinatorRows, 'recipient_role')) === ['COORDINATOR']
    );
    $assert(
        '2.3 Las dos plantillas viajan con prioridad STANDARD',
        array_unique(array_merge(
            $column($reopenSiteRows, 'priority'),
            $column($reopenCoordinatorRows, 'priority')
        )) === ['STANDARD']
    );
    $reopenMessage = $renderer->render(NotificationTemplate::INCIDENT_REOPENED_IN_SITU_SITE, $reopenPayload);
    $assert(
        '2.4 El enlace de la Sede es el Portal de Sede (ruta persistida y botón absoluto)',
        str_starts_with((string)($reopenPayload['tracking_url'] ?? ''), '/?site=')
            && str_starts_with((string)$reopenMessage->ctaUrl, 'https://vendguard.test/?site='),
        'Payload: ' . json_encode($reopenPayload['tracking_url'] ?? null)
            . ' · Botón: ' . json_encode($reopenMessage->ctaUrl)
    );
    $assert(
        '2.5 El payload a la Sede no filtra diagnóstico ni nota de traspaso',
        !array_key_exists('resolution_diagnosis', $reopenPayload)
            && !array_key_exists('resolution_action', $reopenPayload)
            && !array_key_exists('takeover_internal_note', $reopenPayload)
            && !array_key_exists('technician_label', $reopenPayload)
            && !array_key_exists('technician_id', $reopenPayload)
    );

    $assert(
        '2.6 El correo a la Sede nombra al técnico solo con el alias neutro',
        ($reopenPayload['technical_service_label'] ?? null) === 'Servicio Técnico Oficial · Operador #'
            . sprintf('%02d', $jordi->getId())
            && str_contains($reopenMessage->textBody, 'Servicio Técnico Oficial · Operador #')
            && !str_contains($reopenMessage->textBody, $jordi->getName())
    );
    $assert(
        '2.7 La reapertura in situ no duplica el correo de apertura',
        $fetchRows($reopenId, NotificationTemplate::INCIDENT_OPENED_SITE->value) === []
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 3. RF-06.3.1 — Cero correo redundante al asumir un ticket ya abierto
    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- Caso 3: La asunción simple no escribe a la Sede ---\n";

    $assumptionSeed = $fixture(
        'INC-2026-OPP07-C',
        'VEND-0401',
        IncidentStatus::REGISTERED,
        null
    );
    $assumptionId = (int)$assumptionSeed['incident']->getId();
    $claimed = $incidents->claimOpportunisticIncident($assumptionId, $jordi->getId());

    $enqueuedAssumption = $enqueuer->enqueueInSituAssumption($assumptionSeed['incident'], $claimed);
    $assumptionRows = $fetchRows($assumptionId);
    $siteAssumptionRows = array_values(array_filter(
        $assumptionRows,
        static fn (array $row): bool => (string)$row['recipient_role'] === 'LOCATION'
    ));

    $assert(
        '3.1 La asunción de un expediente ya abierto no genera ningún correo',
        $enqueuedAssumption === 0 && $assumptionRows === [],
        'Filas: ' . json_encode($column($assumptionRows, 'template'))
    );
    $assert(
        '3.2 La Sede no recibe un aviso redundante de apertura',
        $siteAssumptionRows === []
            && $fetchRows($assumptionId, NotificationTemplate::INCIDENT_OPENED_SITE->value) === []
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 4. RF-06.3.3 / RF-TG-06 — Traspaso entre técnicos
    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- Caso 4: Traspaso cancela la asignación del saliente y avisa solo por correo ---\n";

    $transferSeed = $fixture(
        'INC-2026-OPP07-D',
        'VEND-0501',
        IncidentStatus::REGISTERED,
        null
    );
    $transferId = (int)$transferSeed['incident']->getId();
    $assignedToMarta = $incidents->assign($transferId, $marta->getId(), $coordinatorId);

    putenv('VENDGUARD_TELEGRAM_BOT_TOKEN=123456:CERT-TOKEN');
    $users->update($marta->getId(), ['telegram_chat_id' => '987654321']);

    $enqueuer->enqueueAssignment($transferSeed['incident'], $assignedToMarta);
    $assignmentRows = $fetchRows($transferId, NotificationTemplate::TECHNICIAN_ASSIGNED->value);

    $assert(
        '4.0 El escenario parte de la asignación pendiente por correo y Telegram',
        count($assignmentRows) === 2
            && array_unique($column($assignmentRows, 'channel')) === ['EMAIL', 'TELEGRAM'],
        'Canales: ' . json_encode($column($assignmentRows, 'channel'))
    );

    $takeover = $incidents->takeOverOpportunisticIncident(
        $transferId,
        $jordi->getId(),
        'La máquina está en el pasillo contiguo; reviso el monedero por presencia física.'
    );
    $enqueuedTransfer = $enqueuer->enqueueInSituAssumption(
        $assignedToMarta,
        $takeover['incident'],
        (int)$takeover['previous_technician_id']
    );

    $assignmentRowsAfter = $fetchRows($transferId, NotificationTemplate::TECHNICIAN_ASSIGNED->value);
    $cancelledRows = array_values(array_filter(
        $assignmentRowsAfter,
        static fn (array $row): bool => (string)$row['status'] === 'CANCELLED_OBSOLETE'
    ));

    $assert(
        '4.1 Los dos avisos de asignación del reemplazado quedan CANCELLED_OBSOLETE',
        count($assignmentRowsAfter) === 2 && count($cancelledRows) === 2,
        'Filas: ' . json_encode(array_map(
            static fn (array $row): array => [
                'id' => $row['id'] ?? null,
                'incident_id' => $row['incident_id'] ?? null,
                'recipient_user_id' => $row['recipient_user_id'] ?? null,
                'channel' => $row['channel'] ?? null,
                'status' => $row['status'] ?? null,
                'template' => $row['template'] ?? null,
            ],
            $assignmentRowsAfter
        ))
    );
    $assert(
        '4.2 La obsolescencia documenta el motivo del traspaso in situ',
        array_unique($column($cancelledRows, 'obsolete_reason'))
            === [NotificationObsolescencePolicy::REASON_ASSIGNMENT_SUPERSEDED_IN_SITU]
    );

    $unassignedRows = $fetchRows($transferId, NotificationTemplate::TECHNICIAN_UNASSIGNED->value);
    $unassignedPayload = $payloadOf($unassignedRows[0] ?? []);
    $unassignedMessage = $renderer->render(NotificationTemplate::TECHNICIAN_UNASSIGNED, $unassignedPayload);

    $assert(
        '4.3 Se encola una única desasignación, exclusivamente por correo',
        $enqueuedTransfer === 1
            && count($unassignedRows) === 1
            && (string)$unassignedRows[0]['channel'] === NotificationChannel::EMAIL->value
            && (string)$unassignedRows[0]['recipient'] === strtolower((string)$marta->getEmail())
            && (int)$unassignedRows[0]['recipient_user_id'] === $marta->getId()
    );
    $assert(
        '4.4 Ningún canal Telegram transporta la desasignación (RF-TG-06)',
        array_values(array_filter(
            $unassignedRows,
            static fn (array $row): bool => (string)$row['channel'] === NotificationChannel::TELEGRAM->value
        )) === []
    );
    $assert(
        '4.5 La desasignación no lleva botón ni promete un expediente ajeno',
        !array_key_exists('tracking_url', $unassignedPayload)
            && !str_contains($unassignedMessage->textBody, 'https://')
            && !str_contains($unassignedMessage->subject, 'http')
    );
    $assert(
        '4.6 El traspaso tampoco escribe a la Sede (cero correo redundante)',
        $fetchRows($transferId, NotificationTemplate::INCIDENT_OPENED_SITE->value) === []
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Resolución QA 5 / Art. V.4 — Blindaje del payload
    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- Caso 5: Privacidad del informante y de la nota interna ---\n";

    $rawPayload = [
        'ticket_code' => 'INC-2026-OPP07-D',
        'machine_code' => 'VEND-0501',
        'site_code' => 'BCN-0501',
        'technician_id' => $jordi->getId(),
        'operator_code' => sprintf('%02d', $jordi->getId()),
        'technician_name' => $jordi->getName(),
        'technician_label' => $jordi->getName() . ' · Servicio Técnico Oficial · Operador #' . sprintf('%02d', $jordi->getId()),
        'takeover_internal_note' => 'Nota interna de traspaso: el monedero estaba bloqueado.',
        'opportunistic_informant_name' => 'Marta Gómez (Conserjería)',
        'opportunistic_origin_label' => 'Aviso verbal en sede',
        'description' => 'Atasco en espiral B4.',
    ];

    $sitePayload = NotificationPayloadSanitizer::sanitize(
        'LOCATION',
        NotificationTemplate::INCIDENT_REOPENED_IN_SITU_SITE->value,
        $rawPayload
    );
    $citizenPayload = NotificationPayloadSanitizer::sanitize(
        'CITIZEN_QR',
        NotificationTemplate::INCIDENT_RESOLVED_CITIZEN_QR->value,
        $rawPayload
    );

    $assert(
        '5.1 La Sede conserva el informante pero pierde la nota de traspaso',
        ($sitePayload['opportunistic_informant_name'] ?? null) === 'Marta Gómez (Conserjería)'
            && !array_key_exists('takeover_internal_note', $sitePayload)
            && !array_key_exists('technician_name', $sitePayload)
            && !array_key_exists('technician_id', $sitePayload)
    );
    $assert(
        '5.2 La Sede ve al técnico bajo el alias oficial',
        ($sitePayload['technical_service_label'] ?? null) === 'Servicio Técnico Oficial · Operador #'
            . sprintf('%02d', $jordi->getId())
    );
    $assert(
        '5.3 El payload sanitizado de la Sede supera la auditoría de privacidad',
        NotificationPayloadSanitizer::auditViolations('LOCATION', $sitePayload) === []
    );

    $assert(
        '5.4 El Ciudadano QR no recibe el nombre del informante ni la nota interna',
        !array_key_exists('opportunistic_informant_name', $citizenPayload)
            && !array_key_exists('opportunistic_origin_label', $citizenPayload)
            && !array_key_exists('takeover_internal_note', $citizenPayload)
            && !array_key_exists('technician_name', $citizenPayload)
    );
    $assert(
        '5.5 El enlace del Ciudadano QR se queda en la vista pública de la máquina',
        ($citizenPayload['tracking_url'] ?? null) === '/?qr=VEND-0501'
            && NotificationPayloadSanitizer::auditViolations('CITIZEN_QR', $citizenPayload) === []
    );

    $citizenMessage = $renderer->render(
        NotificationTemplate::INCIDENT_RESOLVED_CITIZEN_QR,
        $citizenPayload
    );
    $assert(
        '5.6 El correo ciudadano no contiene el nombre del informante',
        !str_contains($citizenMessage->textBody, 'Marta Gómez')
            && !str_contains($citizenMessage->textBody, 'Conserjería')
    );

    // Limpieza de las incidencias sembradas por la suite.
    foreach ($machineCodes as $code) {
        $machine = $machines->findByCode($code);
        if ($machine !== null) {
            TestDataCleaner::purgeIncidentsByMachine($pdo, (int)$machine->getId());
        }
    }
}

// Restablecer el entorno y el canal corporativo del arnés.
$restoreSettings = $settings->findCurrent();
$restoreSettings->updateConfiguration('Canal de certificación T-OPP-07', null, false, $coordinatorId);
$settings->save($restoreSettings);

if ($marta !== null) {
    $users->update($marta->getId(), ['telegram_chat_id' => null]);
}

putenv('APP_BASE_URL' . ($previousBaseUrl !== false ? '=' . $previousBaseUrl : ''));
putenv('VENDGUARD_TELEGRAM_BOT_TOKEN' . ($previousTelegramToken !== false ? '=' . $previousTelegramToken : ''));
if ($previousTelegramAlias !== false) {
    putenv('TG_BOT_TOKEN=' . $previousTelegramAlias);
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-OPP-07 CERTIFICADA.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
