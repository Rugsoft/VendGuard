<?php

declare(strict_types=1);

/**
 * NotificationOutboxFactoryPdoTest · RF-05.4.4 / H8
 *
 * Certifica que `NotificationOutboxEnqueuerFactory::create($pdo)` compone todos
 * sus repositorios y su `PdoTransactionManager` sobre la conexión inyectada, no
 * sobre la singleton:
 *
 *   1. Se abre una conexión propia y se encola un aviso de relevo dentro de una
 *      transacción de esa conexión.
 *   2. Se hace `rollBack()`.
 *   3. La conexión singleton no ve ninguna fila del aviso.
 *   4. Un encolado posterior sin transacción sí queda visible, lo que confirma
 *      que la conexión inyectada apunta a la misma base.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Notification\NotificationOutboxEnqueuerFactory;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - NotificationOutboxFactoryPdoTest (RF-05.4.4 / H8)\n";
echo "======================================================================\n\n";

$singleton = ConnectionFactory::getConnection();
(new SeedRunner($singleton))->seedAll();
TestDataCleaner::purge($singleton);

$assertions = 0;
$failures = 0;
$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

// El técnico sembrado que se usa aquí conserva su identidad: si la suite lo
// renombra y muere antes de restituirlo, la restitución del parque lo vería como
// residuo, lo borraría y la siguiente siembra lo recrearía con otro id. El correo
// temporal se restituye siempre en el `finally`.
$originalTechnicianEmail = null;
$originalTechnicianEmailKey = null;
$technicianId = null;

try {
    $technicianRow = $singleton->query(
        "SELECT id, email, email_key FROM users WHERE role = 'TECHNICIAN' AND is_active = TRUE AND deleted_at IS NULL ORDER BY id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if ($technicianRow === false || (int)$technicianRow['id'] <= 0) {
        throw new RuntimeException('La prueba necesita un técnico sembrado.');
    }

    $technicianId = (int)$technicianRow['id'];
    $originalTechnicianEmail = (string)$technicianRow['email'];
    $originalTechnicianEmailKey = $technicianRow['email_key'] === null ? null : (string)$technicianRow['email_key'];

    // Correo válido para que el encolador produzca al menos una fila de email.
    $singleton->prepare("UPDATE users SET email = 'outbox.pdo@vending.test', email_key = 'outbox.pdo@vending.test' WHERE id = :id")
        ->execute([':id' => $technicianId]);

    $incidentInsert = $singleton->prepare(
        "INSERT INTO incidents (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
         VALUES ('INC-OUTBOX-PDO-01', 1, 1, 'PAYMENT_SYSTEM',
                 'Prueba de propagación del PDO al outbox', 'LOW', 'ASSIGNED', :technician_id, NOW())
         RETURNING id"
    );
    $incidentInsert->execute([':technician_id' => $technicianId]);
    $incidentId = (int)$incidentInsert->fetchColumn();

    $dsn = getenv('DATABASE_URL');
    if ($dsn === false || trim((string)$dsn) === '') {
        throw new RuntimeException('La prueba necesita DATABASE_URL.');
    }

    $injected = new PDO((string)$dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $incidentRepo = new PdoIncidentRepository($injected);
    $incident = $incidentRepo->findById($incidentId);
    if ($incident === null) {
        throw new RuntimeException('La incidencia de la prueba no se pudo releer.');
    }

    $enqueuer = NotificationOutboxEnqueuerFactory::create($injected);

    $injected->beginTransaction();
    $queuedInside = $enqueuer->enqueueShiftHandoverAssignment($incident);
    $injected->rollBack();

    $visibleAfterRollback = (int)$singleton->query(
        "SELECT COUNT(*) FROM notification_outbox WHERE incident_id = {$incidentId}"
    )->fetchColumn();

    $assert('El encolado dentro de la transacción inyectada produce filas', $queuedInside >= 1, 'filas=' . $queuedInside);
    $assert(
        'El rollBack de la conexión inyectada no deja rastro en la conexión singleton',
        $visibleAfterRollback === 0,
        'filas visibles=' . $visibleAfterRollback
    );

    // Encolado posterior sin transacción: la conexión inyectada sí escribe en la
    // misma base y el cambio queda visible para la singleton.
    $queuedOutside = $enqueuer->enqueueShiftHandoverAssignment($incident);
    $visibleAfterCommit = (int)$singleton->query(
        "SELECT COUNT(*) FROM notification_outbox WHERE incident_id = {$incidentId}"
    )->fetchColumn();

    $assert('El encolado fuera de transacción confirma en la conexión inyectada', $queuedOutside >= 1, 'filas=' . $queuedOutside);
    $assert('La conexión singleton ve el aviso confirmado', $visibleAfterCommit >= 1, 'filas visibles=' . $visibleAfterCommit);
} catch (Throwable $exception) {
    $assert('La prueba de propagación del PDO se ejecuta sin excepción de infraestructura', false, $exception->getMessage());
    echo $exception->getTraceAsString() . "\n";
} finally {
    if ($originalTechnicianEmail !== null && $technicianId !== null) {
        $singleton->prepare('UPDATE users SET email = :email, email_key = :email_key WHERE id = :id')
            ->execute([
                ':email' => $originalTechnicianEmail,
                ':email_key' => $originalTechnicianEmailKey,
                ':id' => $technicianId,
            ]);
    }
    TestDataCleaner::purge($singleton);
}

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Exitosas: %d | Fallidas: %d\n", $assertions, $assertions - $failures, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
