<?php

declare(strict_types=1);

/**
 * PdoNotificationSettingsRepositoryTest
 *
 * Certificación de integración de T-NOTIF-04 para la configuración del Canal
 * Corporativo de Emergencias (Tabla 22):
 *   - lectura de la fila vigente y creación idempotente cuando falta;
 *   - persistencia de nombre, URL y estado del Webhook;
 *   - registro del último resultado de prueba;
 *   - cancelación por obsolescencia de los envíos Webhook pendientes al
 *     desactivar el canal (RF-07.1.f), sin borrado físico (Art. III.1).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Core\Domain\Model\NotificationChannelSettings;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationSettingsRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - PdoNotificationSettingsRepositoryTest (T-NOTIF-04)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();

$settingsRepo = new PdoNotificationSettingsRepository($pdo);
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

$marker = 'TNOTIF04SET-' . time() . '-' . bin2hex(random_bytes(3));

// El aviso del canal corporativo no necesita expediente (`incident_id` es
// opcional en la Tabla 21): así la suite certifica la configuración sin
// depender de que exista una avería viva.
$incidentId = null;

$coordinatorId = $pdo->query("
    SELECT id FROM users
    WHERE role = 'COORDINATOR' AND is_active = TRUE AND deleted_at IS NULL
    ORDER BY id ASC LIMIT 1
")->fetchColumn();

$coordinatorId = $coordinatorId !== false ? (int)$coordinatorId : null;

$resetOutbox = static function () use ($pdo): void {
    TestDataCleaner::withPurgeWindow($pdo, static function () use ($pdo): void {
        $pdo->exec('DELETE FROM notification_outbox');
    });
};

$resetOutbox();

$original = $settingsRepo->findCurrent();

$assert('1.1 findCurrent devuelve la fila vigente con identificador', $original->getId() !== null && $original->getId() > 0);
$assert('1.2 La configuración tiene nombre institucional', trim($original->getWebhookName()) !== '');

$webhookUrl = 'https://emergencias.example.org/hook-' . strtolower($marker);

$pendingWebhook = $outboxRepo->enqueue(new NotificationOutboxItem(
    id: null,
    incidentId: $incidentId,
    channel: NotificationChannel::WEBHOOK,
    priority: NotificationPriority::CRITICAL_SANITARY,
    template: NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT,
    recipient: $webhookUrl,
    recipientRole: 'WEBHOOK',
    recipientUserId: null,
    recipientLocationId: null,
    subject: "[TEST {$marker}] Alerta de frío al canal corporativo",
    payload: ['marker' => $marker],
    dedupKey: "settings:{$marker}"
));

$enabled = $original;
$enabled->updateConfiguration('Canal de Pruebas ' . $marker, $webhookUrl, true, $coordinatorId);
$saved = $settingsRepo->save($enabled);

$assert('2.1 save persiste el nombre del canal', $saved->getWebhookName() === 'Canal de Pruebas ' . $marker);
$assert('2.2 save persiste la URL https del Webhook', $saved->getWebhookUrl() === $webhookUrl);
$assert('2.3 save persiste el estado activo', $saved->isWebhookEnabled() === true);
$assert('2.4 El canal activo conserva los envíos pendientes', $outboxRepo->findById((int)$pendingWebhook->getId())?->getStatus() === NotificationStatus::PENDING);

$saved->recordTestResult(true, null, new DateTimeImmutable('now'));
$tested = $settingsRepo->save($saved);

$assert('3.1 La prueba exitosa se registra como SUCCESS', $tested->getLastTestStatus() === 'SUCCESS');
$assert('3.2 La prueba exitosa no deja error residual', $tested->getLastTestError() === null);
$assert('3.3 La marca temporal de la prueba se persiste', $tested->getLastTestedAt() !== null);

$disabled = $tested;
$disabled->updateConfiguration($tested->getWebhookName(), $webhookUrl, false, $coordinatorId);
$disabledSaved = $settingsRepo->save($disabled);

$assert('4.1 save persiste el canal desactivado', $disabledSaved->isWebhookEnabled() === false);

$cancelledWebhook = $outboxRepo->findById((int)$pendingWebhook->getId());
$assert('4.2 Desactivar el canal cancela el envío Webhook pendiente', $cancelledWebhook?->getStatus() === NotificationStatus::CANCELLED_OBSOLETE);
$assert(
    '4.3 El motivo documenta la desactivación del canal',
    $cancelledWebhook !== null && str_contains((string)$cancelledWebhook->getObsoleteReason(), 'desactivado por Coordinación')
);
$assert(
    '4.4 La fila cancelada sigue existiendo físicamente (Art. III.1)',
    (int)$pdo->query("SELECT COUNT(*) FROM notification_outbox WHERE id = " . (int)$pendingWebhook->getId())->fetchColumn() === 1
);

$restored = new NotificationChannelSettings(
    $disabledSaved->getId(),
    $original->getWebhookName(),
    $original->getWebhookUrl(),
    $original->isWebhookEnabled(),
    $original->getLastTestedAt(),
    $original->getLastTestStatus(),
    $original->getLastTestError(),
    $original->getUpdatedBy()
);
$restoredSaved = $settingsRepo->save($restored);

$assert('5.1 La configuración original se restituye', $restoredSaved->getWebhookName() === $original->getWebhookName());
$assert('5.2 El estado original del canal se restituye', $restoredSaved->isWebhookEnabled() === $original->isWebhookEnabled());

$resetOutbox();

echo "\n----------------------------------------------------------------------\n";
echo "Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo "Resultado: [OK] Todos los tests de PdoNotificationSettingsRepository pasaron con éxito.\n";
    exit(0);
}

echo "Resultado: [FALLO] Se detectaron {$failures} fallos en la configuración del canal de emergencias.\n";
exit(1);
