<?php

declare(strict_types=1);

/**
 * NotificationRecipientExtensionsTest
 *
 * Certificación de integración de las extensiones que T-NOTIF-04 añade a los
 * repositorios existentes:
 *   - `locations.contact_email` editable por Coordinación (RF-02.1);
 *   - `users.is_on_call` con designación y baja limpias (RF-02.5, RF-02.6) y
 *     cancelación de avisos pendientes al desactivar al técnico;
 *   - `incidents.reporter_email`, `cycle_started_at` y las marcas de alerta
 *     sanitaria con reinicio de ciclo en la reapertura (RF-02.2, RF-03.1,
 *     RF-04.4, RF-04.5) y métodos de marcado del evaluador de SLA.
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
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - NotificationRecipientExtensionsTest (T-NOTIF-04)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();

$locationRepo = new PdoLocationRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$outboxRepo = new PdoNotificationOutboxRepository($pdo);

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

$marker = 'TNOTIF04EXT-' . time() . '-' . bin2hex(random_bytes(3));

$resetOutbox = static function () use ($pdo): void {
    TestDataCleaner::withPurgeWindow($pdo, static function () use ($pdo): void {
        $pdo->exec('DELETE FROM notification_outbox');
    });
};

// =====================================================================
// 1. Correo oficial de la sede
// =====================================================================
echo "--- 1. locations.contact_email (RF-02.1) ---\n";

$location = $locationRepo->findAllActive()[0] ?? null;

if ($location === null) {
    echo "  [ERROR] El sembrado no dejó ninguna sede activa.\n";
    exit(1);
}

$locationId = $location->getId();
$originalEmail = $location->getContactEmail();
$newEmail = 'contacto.' . strtolower($marker) . '@hospital.example.org';

$assert('1.1 La sede expone un correo oficial por defecto', $originalEmail !== '');
$assert('1.2 updateContactEmail persiste el nuevo correo', $locationRepo->updateContactEmail($locationId, $newEmail) === true);
$assert('1.3 La relectura devuelve el correo corregido', $locationRepo->findById($locationId)?->getContactEmail() === $newEmail);

$invalidRejected = false;
try {
    $locationRepo->updateContactEmail($locationId, 'correo-sin-formato');
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
$assert('1.4 Un correo inválido se rechaza antes de tocar el motor', $invalidRejected);
$assert('1.5 El correo válido sigue vigente tras el rechazo', $locationRepo->findById($locationId)?->getContactEmail() === $newEmail);

$locationRepo->updateContactEmail($locationId, $originalEmail);
$assert('1.6 La suite restituye el correo original', $locationRepo->findById($locationId)?->getContactEmail() === $originalEmail);

// =====================================================================
// 2. Técnico de guardia y baja limpia
// =====================================================================
echo "\n--- 2. users.is_on_call y baja del técnico (RF-02.5, RF-02.6, RF-07.1.f) ---\n";
$resetOutbox();

$technician = $userRepo->create([
    'name' => 'Técnico Guardia ' . $marker,
    'email' => strtolower($marker) . '.tecnico@vendguard.test',
    'password' => 'Secreto-1234',
    'role' => 'TECHNICIAN',
]);
$technicianId = $technician->getId();

$assert('2.1 El alta crea el técnico en activo', $technician->isActive() === true);
$assert('2.2 El técnico nace sin marca de guardia', $technician->isOnCall() === false);

$assert('2.3 setOnCall activa la marca de guardia', $userRepo->setOnCall($technicianId, true) === true);
$assert('2.4 La relectura devuelve is_on_call = true', $userRepo->findById($technicianId)?->isOnCall() === true);
$directoryRow = null;
foreach ($userRepo->findAll(['role' => 'TECHNICIAN', 'status' => 'active']) as $row) {
    if ((int)$row['id'] === $technicianId) {
        $directoryRow = $row;
        break;
    }
}
$assert('2.5 El directorio de personal expone is_on_call = true', ($directoryRow['is_on_call'] ?? null) === true);

$onCallTechnicians = $userRepo->findOnCallTechnicians();
$onCallIds = array_map(static fn($user): int => $user->getId(), $onCallTechnicians);
$assert('2.6 findOnCallTechnicians incluye al técnico designado', in_array($technicianId, $onCallIds, true));
$assert('2.7 countOnCallTechnicians cuenta al menos a ese técnico', $userRepo->countOnCallTechnicians() >= 1);

$pendingForTechnician = $outboxRepo->enqueue(new NotificationOutboxItem(
    id: null,
    incidentId: null,
    channel: NotificationChannel::EMAIL,
    priority: NotificationPriority::STANDARD,
    template: NotificationTemplate::TECHNICIAN_ASSIGNED,
    recipient: $technician->getEmail(),
    recipientRole: 'TECHNICIAN',
    recipientUserId: $technicianId,
    recipientLocationId: null,
    subject: "[TEST {$marker}] Aviso pendiente del técnico",
    payload: ['marker' => $marker]
));

$assert('2.8 El aviso pendiente del técnico está en cola', $outboxRepo->findById((int)$pendingForTechnician->getId())?->getStatus() === NotificationStatus::PENDING);
$assert('2.9 La baja lógica del técnico se aplica', $userRepo->softDelete($technicianId) === true);

$deactivated = $userRepo->findById($technicianId, false, true);
$assert('2.10 La baja retira automáticamente la marca de guardia', $deactivated?->isOnCall() === false);
$assert('2.11 El técnico deja de estar en activo', $deactivated?->isActive() === false);
$assert('2.12 El aviso pendiente se cancela por obsolescencia', $outboxRepo->findById((int)$pendingForTechnician->getId())?->getStatus() === NotificationStatus::CANCELLED_OBSOLETE);
$assert('2.13 La fila del aviso no se borra físicamente (Art. III.1)', $outboxRepo->findById((int)$pendingForTechnician->getId()) !== null);

// Limpieza: primero las filas de la bandeja y después el usuario desechable.
$resetOutbox();
TestDataCleaner::withPurgeWindow($pdo, static function () use ($pdo, $technicianId): void {
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $technicianId]);
});
PdoUserRepository::clearCache();
$assert('2.14 El usuario desechable de la prueba se retira', $userRepo->findById($technicianId, false, true) === null);

// =====================================================================
// 3. Ciclo sanitario del expediente
// =====================================================================
echo "\n--- 3. incidents.reporter_email y marcas de alerta (RF-02.2, RF-03.1, RF-04.4, RF-04.5) ---\n";

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

$ticketCode = 'INC-NOTIF04EXT-' . time();
$reporterEmail = 'ciudadano.' . strtolower($marker) . '@example.org';

$incident = $incidentRepo->create(
    new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: (int)$machine['id'],
        locationId: (int)$machine['location_id'],
        category: IncidentCategory::fromString('TEMPERATURE_COLD'),
        description: 'Expediente autocontenido de la suite T-NOTIF-04 de extensiones.',
        urgency: UrgencyLevel::fromString('CRITICAL'),
        status: IncidentStatus::RESOLVED,
        resolvedAt: EngineClock::stamp(EngineClock::now()),
        reporterEmail: $reporterEmail
    ),
    null,
    'Expediente creado por la suite T-NOTIF-04'
);

$incidentId = (int)$incident->getId();

$reloaded = $incidentRepo->findById($incidentId);
$assert('3.1 El correo del informador se persiste', $reloaded?->getReporterEmail() === $reporterEmail);
$assert('3.2 El ciclo de atención nace fechado', $reloaded?->getCycleStartedAt() !== null);

$assert('3.3 markColdAlertSent marca la alerta de frío', $incidentRepo->markColdAlertSent($incidentId) === true);
$assert('3.4 La marca de frío se lee del expediente', $incidentRepo->findById($incidentId)?->getColdAlertSentAt() !== null);
$assert('3.5 markSlaAlertSent marca el umbral de 2 horas', $incidentRepo->markSlaAlertSent($incidentId, 2) === true);
$assert('3.6 markSlaAlertSent marca el umbral de 3 horas', $incidentRepo->markSlaAlertSent($incidentId, 3) === true);

$slaReloaded = $incidentRepo->findById($incidentId);
$assert('3.7 Ambas marcas de SLA quedan persistidas', $slaReloaded?->getSla2hAlertSentAt() !== null && $slaReloaded?->getSla3hAlertSentAt() !== null);

$invalidThresholdRejected = false;
try {
    $incidentRepo->markSlaAlertSent($incidentId, 1);
} catch (InvalidArgumentException) {
    $invalidThresholdRejected = true;
}
$assert('3.8 Un umbral distinto de 2 o 3 horas se rechaza', $invalidThresholdRejected);

$reopened = $incidentRepo->reopen($incidentId, 'Reincidencia detectada durante la prueba de extensión del ciclo sanitario.');

$assert('3.9 La reapertura reinicia el estado del expediente', $reopened->getStatus() === IncidentStatus::REOPENED);
$assert('3.10 La reapertura reinicia las marcas del ciclo anterior', $reopened->getColdAlertSentAt() === null && $reopened->getSla2hAlertSentAt() === null && $reopened->getSla3hAlertSentAt() === null);
$assert('3.11 La reapertura fija un nuevo ciclo de atención', $reopened->getCycleStartedAt() !== null);

TestDataCleaner::purgeIncident($pdo, $incidentId);

echo "\n----------------------------------------------------------------------\n";
echo "Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo "Resultado: [OK] Todas las extensiones de repositorios pasaron con éxito.\n";
    exit(0);
}

echo "Resultado: [FALLO] Se detectaron {$failures} fallos en las extensiones de repositorios.\n";
exit(1);
