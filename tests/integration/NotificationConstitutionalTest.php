<?php

declare(strict_types=1);

/**
 * NotificationConstitutionalTest — Certificación del «Hecho cuando» de T-NOTIF-11
 * en su dimensión constitucional.
 *
 * Certifica, sobre el motor único PostgreSQL y sobre las filas reales de la
 * bandeja de salida, las tres garantías que la tarea exige:
 *
 *  1. **Art. III.1 — Inviolabilidad histórica:** cualquier `DELETE FROM` sobre
 *     `notification_outbox` o `notification_channel_settings` es rechazado por el
 *     motor con `SQLSTATE 45000` y la marca `ART_III_1_VIOLATION`; ni siquiera el
 *     barrido completo de la tabla pasa. El borrado lógico (`deleted_at`) sí está
 *     permitido y reversible, y ninguna ruta de `src/` emite un borrado físico.
 *  2. **Art. III.3 — Doble destinatario de Sede:** una avería reportada desde el
 *     Portal de Sede con un correo de informador distinto del oficial encola la
 *     apertura, la resolución y la cancelación para **ambos** buzones, sin que el
 *     centro pierda su copia institucional.
 *  3. **Art. V.4 — Blindaje estricto de privacidad:** ninguna fila destinada a
 *     Sede o Ciudadano QR contiene diagnósticos, acciones de resolución, costes,
 *     notas internas ni la identidad personal del técnico, y el canal ciudadano
 *     jamás recibe plantillas de pausa ni enlaces al Portal de Sede.
 *
 * La suite lee el `payload_json` persistido —el artefacto que el despachador
 * enviará— y no una expectativa intermedia: si un productor futuro añadiese un
 * campo confidencial a la proyección externa, la aserción cae aquí.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\CoordinatorNotificationService;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Application\Service\NotificationOutboxEnqueuerService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Repository\NotificationOutboxRepositoryInterface;
use VendGuard\Core\Domain\Repository\NotificationSettingsRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Service\NotificationPayloadSanitizer;
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
echo " VendGuard: Integración - NotificationConstitutionalTest (T-NOTIF-11)\n";
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
        return;
    }

    $failures++;
    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
};

$coordinatorRows = $users->findAll(['status' => 'active', 'role' => 'COORDINATOR']);
$coordinatorId = (int)($coordinatorRows[0]['id'] ?? 1);
$coordinatorEmail = strtolower((string)($coordinatorRows[0]['email'] ?? ''));
$technician = $users->findById(2, true);
$technician2 = $users->findById(3, true);

$assert(
    '0.1 El escenario dispone de Coordinación y de dos técnicos activos',
    $coordinatorEmail !== '' && $technician !== null && $technician2 !== null
);

if ($technician === null || $technician2 === null || $coordinatorEmail === '') {
    echo "ERROR: no se pudo preparar el escenario base.\n";
    exit(1);
}

$users->setOnCall(2, true);

/**
 * Identidad personal del técnico: jamás puede viajar a una proyección externa.
 *
 * @return list<string>
 */
$technicianIdentityNeedles = static function () use ($technician, $technician2): array {
    $needles = [];
    foreach ([$technician, $technician2] as $tech) {
        if ($tech === null) {
            continue;
        }
        $needles[] = strtolower(trim($tech->getName()));
        $needles[] = strtolower(trim($tech->getEmail()));
        $phone = trim((string)$tech->getPhone());
        if ($phone !== '') {
            $needles[] = strtolower($phone);
            $needles[] = strtolower(preg_replace('/\D+/', '', $phone) ?? '');
        }
    }

    return array_values(array_unique(array_filter($needles, static fn (string $value): bool => $value !== '')));
};

// Banco de máquinas del parque: una avería activa por máquina como máximo.
$allMachines = array_map('intval', $pdo->query(
    "SELECT m.id
     FROM machines m
     JOIN locations l ON l.id = m.location_id
     WHERE m.deleted_at IS NULL AND m.is_active = TRUE
       AND l.deleted_at IS NULL AND l.is_active = TRUE
     ORDER BY m.id ASC"
)->fetchAll(PDO::FETCH_COLUMN));
$sanitaryMachines = array_map('intval', $pdo->query(
    "SELECT m.id
     FROM machines m
     JOIN locations l ON l.id = m.location_id
     WHERE m.deleted_at IS NULL AND m.is_active = TRUE
       AND l.deleted_at IS NULL AND l.is_active = TRUE
       AND m.machine_type IN ('PERISHABLE_FOOD', 'COMBO')
     ORDER BY m.id ASC"
)->fetchAll(PDO::FETCH_COLUMN));

$assert(
    '0.2 El parque sembrado ofrece máquinas libres y máquinas bajo vigilancia sanitaria',
    count($allMachines) >= 6 && count($sanitaryMachines) >= 2
);

if (count($allMachines) < 6 || count($sanitaryMachines) < 2) {
    echo "ERROR: el banco de máquinas no cubre los escenarios de la suite.\n";
    exit(1);
}

/** @var array<int, int> máquina => incident_id ocupada por esta suite. */
$usedMachines = [];

/**
 * Crea un expediente en una máquina libre y devuelve su contexto.
 *
 * @return array{incident: Incident, machine_id: int, official: string, site_code: string}
 */
$fixture = function (string $category, string $urgency, ?string $reporterEmail, bool $sanitary = false) use (
    &$usedMachines,
    $allMachines,
    $sanitaryMachines,
    $incidents,
    $machines,
    $locations
): array {
    $pool = $sanitary ? $sanitaryMachines : $allMachines;
    $machineId = null;
    foreach ($pool as $candidate) {
        if (!isset($usedMachines[$candidate])) {
            $machineId = $candidate;
            break;
        }
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
        description: 'Avería de certificación constitucional (T-NOTIF-11).',
        urgency: UrgencyLevel::fromString($urgency),
        status: IncidentStatus::REGISTERED,
        reporterName: 'Informador Constitucional',
        reporterPhone: '600999888',
        reporterEmail: $reporterEmail
    ), 1, 'Alta de certificación T-NOTIF-11');

    $usedMachines[$machineId] = (int)$incident->getId();

    return [
        'incident' => $incident,
        'machine_id' => $machineId,
        'official' => strtolower(trim((string)$location->getContactEmail())),
        'site_code' => $machine->getCode(),
    ];
};

/** @return list<array<string, mixed>> */
$fetchRows = function (int $incidentId, ?string $template = null) use ($pdo): array {
    $sql = 'SELECT * FROM notification_outbox WHERE incident_id = :incident_id AND deleted_at IS NULL';
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

$payloadOf = static fn (array $row): array => json_decode((string)$row['payload_json'], true) ?? [];

/** @param list<string> $emails */
$column = static fn (array $rows, string $field): array => array_map(
    static fn (array $row): string => (string)$row[$field],
    $rows
);

echo "--- 1. Art. III.1 — Prohibición absoluta de borrado físico (RF-07.2) ---\n";

$deleteAttempt = function (string $sql, array $params = []) use ($pdo): array {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $deleted = $stmt->rowCount();
        $pdo->rollBack();

        return ['rejected' => false, 'sqlstate' => null, 'message' => '', 'deleted' => $deleted];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'rejected' => true,
            'sqlstate' => (string)($e->errorInfo[0] ?? $e->getCode()),
            'message' => $e->getMessage(),
            'deleted' => 0,
        ];
    }
};

$guardedTriggers = (int)$pdo->query(
    "SELECT COUNT(*)
       FROM pg_trigger t
       INNER JOIN pg_class c ON c.oid = t.tgrelid
       INNER JOIN pg_namespace n ON n.oid = c.relnamespace
      WHERE NOT t.tgisinternal
        AND n.nspname = current_schema()
        AND t.tgname IN ('trg_no_delete_notification_outbox', 'trg_no_delete_notification_channel_settings')"
)->fetchColumn();
$assert('1.1 El motor declara el disparador de no borrado en las dos tablas', $guardedTriggers === 2);

$watchIncident = $fixture('PRODUCT_JAM', 'MEDIUM', null);
$watchId = (int)$watchIncident['incident']->getId();
$enqueuer->enqueueIncidentOpened($watchIncident['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_LOCATION_PORTAL, null);
$guardedRow = $fetchRows($watchId, 'INCIDENT_OPENED_SITE')[0] ?? null;
$assert('1.2 Hay una fila real de bandeja que intentar borrar', $guardedRow !== null);

if ($guardedRow === null) {
    echo "ERROR: no se pudo preparar la fila de la bandeja.\n";
    exit(1);
}

$guardedId = (int)$guardedRow['id'];
$readGuardedRow = function (int $id) use ($pdo): ?array {
    $stmt = $pdo->prepare('SELECT * FROM notification_outbox WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
};

$rowDelete = $deleteAttempt('DELETE FROM notification_outbox WHERE id = :id', [':id' => $guardedId]);
$assert(
    '1.3 DELETE de una fila de la bandeja => SQLSTATE 45000 con ART_III_1_VIOLATION',
    $rowDelete['rejected'] && $rowDelete['sqlstate'] === '45000' && str_contains($rowDelete['message'], 'ART_III_1_VIOLATION'),
    'SQLSTATE: ' . (string)$rowDelete['sqlstate'] . ' · ' . $rowDelete['message']
);
$assert('1.4 La fila sigue íntegra tras el intento de borrado', $readGuardedRow($guardedId) !== null);

$tableDelete = $deleteAttempt('DELETE FROM notification_outbox');
$assert(
    '1.5 Un barrido DELETE FROM notification_outbox completo también es rechazado',
    $tableDelete['rejected'] && $tableDelete['sqlstate'] === '45000' && str_contains($tableDelete['message'], 'ART_III_1_VIOLATION'),
    'SQLSTATE: ' . (string)$tableDelete['sqlstate'] . ' · ' . $tableDelete['message']
);
$assert(
    '1.6 Ninguna fila de la bandeja desapareció del barrido',
    count($fetchRows($watchId)) >= 1 && $readGuardedRow($guardedId) !== null
);

$settingsRow = $settings->findCurrent();
$settingsRowId = (int)$settingsRow->getId();
$assert('1.7 La configuración del canal tiene una fila real que intentar borrar', $settingsRowId > 0);

$settingsDelete = $deleteAttempt('DELETE FROM notification_channel_settings WHERE id = :id', [':id' => $settingsRowId]);
$assert(
    '1.8 DELETE de la configuración del canal => SQLSTATE 45000 con ART_III_1_VIOLATION',
    $settingsDelete['rejected'] && $settingsDelete['sqlstate'] === '45000' && str_contains($settingsDelete['message'], 'ART_III_1_VIOLATION'),
    'SQLSTATE: ' . (string)$settingsDelete['sqlstate'] . ' · ' . $settingsDelete['message']
);

$settingsTableDelete = $deleteAttempt('DELETE FROM notification_channel_settings');
$assert(
    '1.9 El barrido DELETE FROM notification_channel_settings también es rechazado',
    $settingsTableDelete['rejected'] && $settingsTableDelete['sqlstate'] === '45000',
    'SQLSTATE: ' . (string)$settingsTableDelete['sqlstate'] . ' · ' . $settingsTableDelete['message']
);

$settingsStillThere = (int)$pdo->query('SELECT COUNT(*) FROM notification_channel_settings WHERE id = ' . $settingsRowId)->fetchColumn();
$assert('1.10 La configuración sobrevive a los dos intentos de borrado', $settingsStillThere === 1);

// La vía legítima de retirada es lógica y reversible: se certifica que el
// disparador no la bloquea y que la fila puede volver a la bandeja.
$softDelete = $pdo->prepare('UPDATE notification_outbox SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id');
$softDelete->execute([':id' => $guardedId]);
$softRestore = $pdo->prepare('UPDATE notification_outbox SET deleted_at = NULL WHERE id = :id');
$softRestore->execute([':id' => $guardedId]);
$restoredRow = $readGuardedRow($guardedId);
$assert(
    '1.11 El borrado lógico (deleted_at) sí se admite y es reversible',
    $softDelete->rowCount() === 1
        && $softRestore->rowCount() === 1
        && $restoredRow !== null
        && $restoredRow['deleted_at'] === null
);

$sourceRoot = dirname(__DIR__, 2) . '/src';
$offendingStatements = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $source = (string)file_get_contents($file->getPathname());
    if (
        stripos($source, 'DELETE FROM notification_outbox') !== false
        || stripos($source, 'DELETE FROM notification_channel_settings') !== false
    ) {
        $offendingStatements[] = $file->getPathname();
    }
}
$assert(
    '1.12 Ninguna ruta de src/ emite un borrado físico sobre las tablas blindadas',
    $offendingStatements === [],
    'Archivos: ' . implode(', ', $offendingStatements)
);

$forbiddenMethods = [];
foreach ([NotificationOutboxRepositoryInterface::class, NotificationSettingsRepositoryInterface::class] as $interface) {
    foreach (get_class_methods($interface) ?: [] as $method) {
        if (preg_match('/^(delete|hardDelete|purge|truncate)/i', $method) === 1) {
            $forbiddenMethods[] = $interface . '::' . $method;
        }
    }
}
$assert(
    '1.13 Los repositorios del centro no exponen ninguna operación de borrado físico',
    $forbiddenMethods === [],
    'Métodos: ' . implode(', ', $forbiddenMethods)
);

echo "\n--- 2. Art. III.3 — Doble destinatario de Sede (RF-02.3) ---\n";

$site = $fixture('TEMPERATURE_COLD', 'CRITICAL', 'Informador.Sede@Externo.Example', true);
$siteIncident = $site['incident'];
$siteId = (int)$siteIncident->getId();
$siteOfficial = $site['official'];
$siteReporter = 'informador.sede@externo.example';

$siteOpenedCount = $enqueuer->enqueueIncidentOpened(
    $siteIncident,
    null,
    null,
    NotificationOutboxEnqueuerService::ORIGIN_LOCATION_PORTAL,
    $siteReporter
);
$siteOpeningRows = $fetchRows($siteId, 'INCIDENT_OPENED_SITE');
$siteOpeningRecipients = $column($siteOpeningRows, 'recipient');

$assert(
    '2.1 La apertura de Sede encola exactamente dos destinatarios',
    $siteOpenedCount >= 2 && count($siteOpeningRows) === 2,
    'Filas de apertura: ' . count($siteOpeningRows) . ' · total encolado: ' . $siteOpenedCount
);
$assert(
    '2.2 Los dos destinatarios son el buzón oficial y el correo del informador',
    in_array($siteOfficial, $siteOpeningRecipients, true) && in_array($siteReporter, $siteOpeningRecipients, true),
    'Destinatarios: ' . implode(', ', $siteOpeningRecipients)
);
$assert(
    '2.3 Ambas copias viajan con rol LOCATION y enlace al Portal de Sede',
    $column($siteOpeningRows, 'recipient_role') === ['LOCATION', 'LOCATION']
        && count(array_filter(
            $siteOpeningRows,
            static fn (array $row): bool => str_contains((string)($payloadOf($row)['tracking_url'] ?? ''), '/?site=')
        )) === 2
);

$siteAssigned = $incidents->assign($siteId, 2, $coordinatorId);
$enqueuer->enqueueAssignment($siteIncident, $siteAssigned);
$incidents->startIntervention($siteId, 2);
$siteResolved = $incidents->resolve(
    $siteId,
    2,
    'Diagnóstico interno de certificación: compresor degradado por sobretensión.',
    'Acción interna de certificación: compresor y termostato sustituidos.'
);
$enqueuer->enqueueResolution($siteResolved);
$siteResolvedRows = $fetchRows($siteId, 'INCIDENT_RESOLVED_SITE');
$siteResolvedRecipients = $column($siteResolvedRows, 'recipient');
$assert(
    '2.4 La resolución mantiene el doble destinatario con el correo actualizado',
    count($siteResolvedRows) === 2
        && in_array($siteOfficial, $siteResolvedRecipients, true)
        && in_array($siteReporter, $siteResolvedRecipients, true),
    'Destinatarios: ' . implode(', ', $siteResolvedRecipients)
);

$sameReporter = $fixture('PRODUCT_JAM', 'MEDIUM', null);
$sameReporterOfficial = $sameReporter['official'];
// El informador escribe exactamente el buzón oficial de la Sede: no hay segunda copia.
$enqueuer->enqueueIncidentOpened(
    $sameReporter['incident'],
    null,
    null,
    NotificationOutboxEnqueuerService::ORIGIN_LOCATION_PORTAL,
    $sameReporterOfficial
);
$sameRows = $fetchRows((int)$sameReporter['incident']->getId(), 'INCIDENT_OPENED_SITE');
$assert(
    '2.5 Si el informador usa el propio buzón oficial no se duplica la copia',
    count($sameRows) === 1 && (string)$sameRows[0]['recipient'] === $sameReporterOfficial,
    'Filas: ' . count($sameRows) . ' · destinatarios: ' . implode(', ', $column($sameRows, 'recipient'))
);

$blank = $fixture('PRODUCT_JAM', 'MEDIUM', null);
$enqueuer->enqueueIncidentOpened($blank['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_LOCATION_PORTAL, null);
$blankRows = $fetchRows((int)$blank['incident']->getId(), 'INCIDENT_OPENED_SITE');
$assert(
    '2.6 Sin correo de informador la Sede conserva su única copia oficial',
    count($blankRows) === 1 && (string)$blankRows[0]['recipient'] === $blank['official'],
    'Filas: ' . count($blankRows)
);

$cancelFixture = $fixture('PRODUCT_JAM', 'MEDIUM', 'Cancelacion.Informador@Externo.Example');
$cancelId = (int)$cancelFixture['incident']->getId();
$cancelReporter = 'cancelacion.informador@externo.example';
$enqueuer->enqueueIncidentOpened($cancelFixture['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_LOCATION_PORTAL, $cancelReporter);
$incidents->cancel($cancelId, 'Cancelación de certificación constitucional del expediente.', $coordinatorId);
$cancelledIncident = $incidents->findById($cancelId);
if ($cancelledIncident !== null) {
    $enqueuer->enqueueCancellation($cancelledIncident);
}
$cancelRows = $fetchRows($cancelId, 'INCIDENT_CANCELLED_SITE');
$cancelRecipients = $column($cancelRows, 'recipient');
$assert(
    '2.7 La cancelación informa al buzón oficial y al informador de la Sede',
    count($cancelRows) === 2
        && in_array($cancelFixture['official'], $cancelRecipients, true)
        && in_array($cancelReporter, $cancelRecipients, true),
    'Destinatarios: ' . implode(', ', $cancelRecipients)
);

echo "\n--- 3. Art. V.4 — Cero fugas en proyecciones de Sede y Ciudadano QR (RF-05.6) ---\n";

// Expediente ciudadano 1 · pausa por falta de acceso: la solicitud de acceso es
// exclusiva de la Sede y jamás puede llegar al buzón del ciudadano (RF-05.3).
$pauseService = new IncidentPauseService(
    incidentRepo: $incidents,
    notificationEnqueuer: $enqueuer
);
$qrPause = $fixture('TEMPERATURE_COLD', 'CRITICAL', 'Ciudadano.Pausa@Example.ORG', true);
$qrPauseId = (int)$qrPause['incident']->getId();
$enqueuer->enqueueIncidentOpened($qrPause['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_CITIZEN_QR, 'ciudadano.pausa@example.org');
$incidents->assign($qrPauseId, 2, $coordinatorId);
$pauseService->declarePendingInfoPause(
    $qrPauseId,
    $coordinatorId,
    IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
    'El edificio permanece cerrado fuera del horario de apertura autorizado.'
);
$qrPauseRows = $fetchRows($qrPauseId, 'PENDING_INFO_SITE_REQUEST');
$assert(
    '3.1 La solicitud de acceso por pausa llega sólo a la Sede, nunca al ciudadano',
    count($qrPauseRows) === 1 && (string)$qrPauseRows[0]['recipient_role'] === 'LOCATION'
);
$qrPauseCitizenRows = array_values(array_filter(
    $fetchRows($qrPauseId),
    static fn (array $row): bool => (string)$row['recipient_role'] === 'CITIZEN_QR'
));
$assert(
    '3.2 El buzón ciudadano de un expediente pausado sólo tiene su acuse de apertura',
    count($qrPauseCitizenRows) === 1
        && (string)$qrPauseCitizenRows[0]['template'] === NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR->value,
    'Plantillas: ' . implode(', ', array_column($qrPauseCitizenRows, 'template'))
);

// Expediente ciudadano 2 · resolución: el ciudadano recibe su confirmación
// acotada y nunca la proyección del Portal de Sede.
$qrResolve = $fixture('TEMPERATURE_COLD', 'CRITICAL', 'Ciudadano.Resuelto@Example.ORG', true);
$qrResolveId = (int)$qrResolve['incident']->getId();
$enqueuer->enqueueIncidentOpened($qrResolve['incident'], null, null, NotificationOutboxEnqueuerService::ORIGIN_CITIZEN_QR, 'ciudadano.resuelto@example.org');
$incidents->assign($qrResolveId, 2, $coordinatorId);
$incidents->startIntervention($qrResolveId, 2);
$qrResolved = $incidents->resolve(
    $qrResolveId,
    2,
    'Diagnóstico interno de certificación: sensor térmico descalibrado en cámara.',
    'Acción interna de certificación: sensor sustituido y calibrado con patrón.'
);
$enqueuer->enqueueResolution($qrResolved);
$assert(
    '3.3 El ciudadano recibe su confirmación de resolución acotada',
    count($fetchRows($qrResolveId, 'INCIDENT_RESOLVED_CITIZEN_QR')) === 1
    && count($fetchRows($qrResolveId, 'INCIDENT_RESOLVED_SITE')) === 1
);

// Censo completo de la bandeja, sin filtrar por expediente: es la auditoría que
// debe poder repetirse sobre cualquier estado del parque.
$allOutbox = $pdo->query('SELECT * FROM notification_outbox WHERE deleted_at IS NULL ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
$externalRows = array_values(array_filter(
    $allOutbox,
    static fn (array $row): bool => in_array((string)$row['recipient_role'], NotificationPayloadSanitizer::EXTERNAL_ROLES, true)
));
$locationRows = array_values(array_filter($externalRows, static fn (array $row): bool => (string)$row['recipient_role'] === 'LOCATION'));
$citizenRows = array_values(array_filter($externalRows, static fn (array $row): bool => (string)$row['recipient_role'] === 'CITIZEN_QR'));

$assert(
    '3.4 El censo tiene una población real de filas externas de ambos roles',
    count($locationRows) >= 8 && count($citizenRows) >= 2,
    'LOCATION: ' . count($locationRows) . ' · CITIZEN_QR: ' . count($citizenRows)
);

$auditViolations = [];
foreach ($externalRows as $row) {
    $violations = NotificationPayloadSanitizer::auditViolations((string)$row['recipient_role'], $payloadOf($row));
    foreach ($violations as $violation) {
        $auditViolations[] = '#' . (string)$row['id'] . ' · ' . $violation;
    }
}
$assert(
    '3.5 La auditoría de privacidad en origen da cero violaciones en toda la bandeja',
    $auditViolations === [],
    implode(' | ', $auditViolations)
);

$collectKeys = function (array $data) use (&$collectKeys): array {
    $keys = [];
    foreach ($data as $key => $value) {
        $keys[] = (string)$key;
        if (is_array($value)) {
            $keys = array_merge($keys, $collectKeys($value));
        }
    }

    return $keys;
};

$locationLeaks = [];
foreach ($locationRows as $row) {
    $keys = $collectKeys($payloadOf($row));
    $forbidden = array_values(array_intersect($keys, NotificationPayloadSanitizer::FORBIDDEN_KEYS_FOR_EXTERNAL_ROLES));
    if ($forbidden !== []) {
        $locationLeaks[] = '#' . (string)$row['id'] . ' · ' . implode(',', $forbidden);
    }
}
$assert(
    '3.6 Ninguna fila de Sede contiene claves confidenciales (diagnóstico, costes, técnico)',
    $locationLeaks === [],
    implode(' | ', $locationLeaks)
);

$citizenLeaks = [];
foreach ($citizenRows as $row) {
    $keys = $collectKeys($payloadOf($row));
    $forbidden = array_values(array_intersect(
        $keys,
        array_merge(
            NotificationPayloadSanitizer::FORBIDDEN_KEYS_FOR_EXTERNAL_ROLES,
            NotificationPayloadSanitizer::FORBIDDEN_KEYS_FOR_CITIZEN_QR
        )
    ));
    if ($forbidden !== []) {
        $citizenLeaks[] = '#' . (string)$row['id'] . ' · ' . implode(',', $forbidden);
    }
}
$assert(
    '3.7 Ninguna fila ciudadana contiene claves internas ni textos de terceros',
    $citizenLeaks === [],
    implode(' | ', $citizenLeaks)
);

$identityNeedles = $technicianIdentityNeedles();
$identityLeaks = [];
foreach ($externalRows as $row) {
    $haystack = strtolower((string)json_encode($payloadOf($row), JSON_UNESCAPED_UNICODE));
    foreach ($identityNeedles as $needle) {
        if ($needle !== '' && str_contains($haystack, $needle)) {
            $identityLeaks[] = '#' . (string)$row['id'] . ' · ' . $needle;
        }
    }
}
$assert(
    '3.8 Ninguna fila externa contiene nombre, correo o teléfono personal del técnico',
    $identityLeaks === [],
    implode(' | ', $identityLeaks)
);

$externalWithTechnicianLabel = array_values(array_filter(
    $externalRows,
    static fn (array $row): bool => array_key_exists('technician_label', $payloadOf($row))
));
$assert(
    '3.9 La etiqueta de técnico con nombre real no viaja a ninguna proyección externa',
    $externalWithTechnicianLabel === [],
    'Filas: ' . implode(', ', array_column($externalWithTechnicianLabel, 'id'))
);

$externalWithNeutralLabel = array_values(array_filter(
    $locationRows,
    static fn (array $row): bool => str_starts_with((string)($payloadOf($row)['technical_service_label'] ?? ''), 'Servicio Técnico Oficial')
));
$assert(
    '3.10 La Sede sí recibe el alias técnico neutro oficial en su lugar',
    $externalWithNeutralLabel !== []
);

$internalAssignmentRows = array_values(array_filter(
    $fetchRows($siteId, 'TECHNICIAN_ASSIGNED'),
    static fn (array $row): bool => (string)$row['recipient_role'] === 'TECHNICIAN'
));
$internalTechnicianLabels = array_values(array_filter(
    $internalAssignmentRows,
    static fn (array $row): bool => array_key_exists('technician_label', $payloadOf($row))
));
$assert(
    '3.11 El contraste interno: el aviso al técnico sí conserva su etiqueta',
    $internalAssignmentRows !== [] && $internalTechnicianLabels !== [],
    'Filas internas: ' . count($internalAssignmentRows)
);

$citizenForbiddenTemplates = array_values(array_filter(
    $citizenRows,
    static fn (array $row): bool => in_array((string)$row['template'], NotificationPayloadSanitizer::FORBIDDEN_TEMPLATES_FOR_CITIZEN_QR, true)
));
$assert(
    '3.12 El ciudadano nunca recibe plantillas de pausa ni de cuarentena',
    $citizenForbiddenTemplates === [],
    'Plantillas: ' . implode(', ', array_column($citizenForbiddenTemplates, 'template'))
);

$citizenTracking = array_map(static fn (array $row): string => (string)($payloadOf($row)['tracking_url'] ?? ''), $citizenRows);
$assert(
    '3.13 Todo enlace ciudadano apunta a la vista pública QR y ninguno al Portal de Sede',
    $citizenTracking !== []
        && count(array_filter($citizenTracking, static fn (string $url): bool => str_starts_with($url, '/?qr='))) === count($citizenTracking)
        && count(array_filter($citizenTracking, static fn (string $url): bool => str_contains($url, 'site='))) === 0,
    'Enlaces: ' . implode(', ', $citizenTracking)
);

$internalDiagnosisFragment = 'Diagnóstico interno de certificación';
$diagnosisLeaks = array_values(array_filter(
    $externalRows,
    static fn (array $row): bool => str_contains((string)$row['payload_json'], $internalDiagnosisFragment)
));
$assert(
    '3.14 Ni el diagnóstico ni la acción de resolución llegan a una proyección externa',
    $diagnosisLeaks === [],
    'Filas: ' . implode(', ', array_column($diagnosisLeaks, 'id'))
);

$illegalCitizenTemplateBlocked = false;
try {
    NotificationPayloadSanitizer::sanitize('CITIZEN_QR', 'PENDING_INFO_SITE_REQUEST', ['ticket_code' => 'TICK-NOTIF11']);
} catch (InvalidArgumentException) {
    $illegalCitizenTemplateBlocked = true;
}
$assert(
    '3.15 Un productor futuro que intente una plantilla de pausa al ciudadano es bloqueado en origen',
    $illegalCitizenTemplateBlocked
);

echo "\n--- 4. Art. III.3 — Trazabilidad de las acciones del centro (RF-07.3) ---\n";

$auditFloor = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();

$coordinatorActor = ['id' => $coordinatorId, 'role' => 'COORDINATOR', 'name' => 'Coordinación de Certificación'];
$emailTransport = new InMemoryNotificationTransport(NotificationChannel::EMAIL);
$webhookTransport = new InMemoryNotificationTransport(
    NotificationChannel::WEBHOOK,
    TransportDeliveryResult::success(200)
);
$notificationService = new CoordinatorNotificationService(
    outbox: $outbox,
    locations: $locations,
    settings: $settings,
    onCallTechnicians: $users,
    transports: new NotificationTransportRegistry($emailTransport, $webhookTransport),
    audit: new AuditLogger()
);

$notificationService->updateSettings(
    [
        'webhook_name' => 'Canal Constitucional de Emergencias',
        'webhook_url' => 'https://hooks.example.org/constitucional',
        'is_webhook_enabled' => false,
    ],
    $coordinatorActor
);
$notificationService->cancelItem(
    $guardedId,
    'Cancelación de certificación constitucional con motivo auditado.',
    $coordinatorActor
);

$auditRows = $pdo->prepare(
    'SELECT action, entity_type, entity_id, user_id, previous_state, new_state
     FROM audit_log
     WHERE id > :floor AND action IN (:updated, :cancelled)
     ORDER BY id ASC'
);
$auditRows->execute([
    ':floor' => $auditFloor,
    ':updated' => 'NOTIFICATION_WEBHOOK_UPDATED',
    ':cancelled' => 'NOTIFICATION_MANUAL_CANCEL',
]);
$writtenAudit = $auditRows->fetchAll(PDO::FETCH_ASSOC);

$assert(
    '4.1 La modificación del canal y la cancelación manual quedan en la bitácora',
    count($writtenAudit) === 2,
    'Eventos: ' . json_encode(array_column($writtenAudit, 'action'))
);

$byAction = [];
foreach ($writtenAudit as $row) {
    $byAction[(string)$row['action']] = $row;
}

$cancelAudit = $byAction['NOTIFICATION_MANUAL_CANCEL'] ?? null;
$updatedAudit = $byAction['NOTIFICATION_WEBHOOK_UPDATED'] ?? null;
$assert(
    '4.2 La cancelación se audita con actor, tipo de entidad y estado anterior/nuevo',
    $cancelAudit !== null
        && (int)$cancelAudit['entity_id'] === $guardedId
        && (int)$cancelAudit['user_id'] === $coordinatorId
        && $cancelAudit['previous_state'] !== null
        && $cancelAudit['new_state'] !== null
        && str_contains((string)$cancelAudit['new_state'], 'CANCELLED_OBSOLETE'),
    'Fila: ' . json_encode($cancelAudit)
);
$assert(
    '4.3 La configuración del canal se audita como entidad de notificaciones',
    $updatedAudit !== null
        && (string)$updatedAudit['entity_type'] === 'NOTIFICATION_CHANNEL_SETTING'
        && (int)$updatedAudit['user_id'] === $coordinatorId
        && $updatedAudit['previous_state'] !== null
        && $updatedAudit['new_state'] !== null,
    'Fila: ' . json_encode($updatedAudit)
);

echo "\n======================================================================\n";
echo " Aserciones evaluadas: {$assertions}\n";
echo ' Fallos: ' . $failures . "\n";
echo "======================================================================\n";

if ($failures > 0) {
    echo "\nRESULTADO: HAY FALLOS.\n";
    exit(1);
}

echo "\nRESULTADO: 100% EN VERDE.\n";
exit(0);
