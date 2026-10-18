<?php

declare(strict_types=1);

/**
 * AdminUserTelegramLinkTest
 *
 * Certifica la vinculación de Telegram desde la administración (Módulo 15, T-TG-05;
 * RF-TG-07 y RF-TG-10): formato del identificador, restricción al rol técnico,
 * unicidad de chat entre usuarios vivos y rastro de auditoría con el valor
 * enmascarado.
 *
 * Se ejercita el servicio real contra el motor único: la unicidad la impone el índice
 * parcial de la base y el servicio la traduce a un mensaje de negocio, así que probar
 * con un doble no certificaría nada de lo que ocurre en producción.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AdminUserService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - AdminUserTelegramLinkTest (Módulo 15)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();
TestDataCleaner::purge($pdo);

$users = new PdoUserRepository($pdo);
$service = new AdminUserService($users);
$actor = ['id' => 1, 'role' => 'COORDINATOR', 'name' => 'Coordinación de Certificación'];

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

$expectRejection = function (string $caseTitle, callable $call, string $expectedFragment) use ($assert): void {
    try {
        $call();
        $assert($caseTitle, false, 'Se esperaba un rechazo y no hubo ninguno.');
    } catch (InvalidArgumentException $exception) {
        $assert($caseTitle, str_contains($exception->getMessage(), $expectedFragment), $exception->getMessage());
    }
};

$technicians = $users->findAll(['status' => 'active', 'role' => 'TECHNICIAN']);
$coordinatorRows = $users->findAll(['status' => 'active', 'role' => 'COORDINATOR']);
$technicianId = (int)($technicians[0]['id'] ?? 0);
$secondTechnicianId = (int)($technicians[1]['id'] ?? 0);
$coordinatorId = (int)($coordinatorRows[0]['id'] ?? 0);

$assert('El escenario dispone de dos técnicos activos', $technicianId > 0 && $secondTechnicianId > 0);

// ─────────────────────────────────────────────────────────────────────────────
// 1. Vinculación válida y rastro de auditoría
// ─────────────────────────────────────────────────────────────────────────────
$updated = $service->updateUser($technicianId, ['telegram_chat_id' => '987654321'], $actor, '127.0.0.1');
$assert(
    '1.1 Un técnico puede vincular su chat de Telegram',
    $updated->getTelegramChatId() === '987654321' && $updated->hasTelegramLink()
);
$assert(
    '1.2 El listado administrativo publica el vínculo y su estado',
    (static function () use ($users, $technicianId): bool {
        foreach ($users->findAll(['status' => 'all']) as $row) {
            if ((int)$row['id'] === $technicianId) {
                return $row['telegram_chat_id'] === '987654321' && $row['telegram_linked'] === true;
            }
        }

        return false;
    })()
);

$auditRow = $pdo->query(
    "SELECT previous_state, new_state, metadata FROM audit_log
      WHERE action = 'USER_UPDATED' AND entity_id = " . $technicianId . "
      ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$auditDump = json_encode($auditRow, JSON_UNESCAPED_UNICODE) ?: '';
$assert(
    '1.3 La auditoría registra el cambio con el chat enmascarado, nunca completo',
    str_contains($auditDump, '***4321')
    && !str_contains($auditDump, '987654321')
);
$assert(
    '1.4 El campo modificado queda declarado en los metadatos',
    str_contains($auditDump, 'telegram_chat_id')
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Rechazos: formato, rol y unicidad
// ─────────────────────────────────────────────────────────────────────────────
$expectRejection(
    '2.1 Un chat que no es identificador numérico se rechaza',
    fn () => $service->updateUser($technicianId, ['telegram_chat_id' => 'jordi.ruta'], $actor),
    'formato válido'
);

if ($coordinatorId > 0) {
    $expectRejection(
        '2.2 Sólo un técnico de campo puede vincular un chat',
        fn () => $service->updateUser($coordinatorId, ['telegram_chat_id' => '123456789'], $actor),
        'Sólo un técnico de campo'
    );
}

$expectRejection(
    '2.3 Un chat no puede pertenecer a dos técnicos vivos',
    fn () => $service->updateUser($secondTechnicianId, ['telegram_chat_id' => '987654321'], $actor),
    'ya está vinculado a otro usuario'
);

$assert(
    '2.4 El rechazo no muta la fila del segundo técnico',
    $users->findById($secondTechnicianId, false)->getTelegramChatId() === null
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Desvinculación
// ─────────────────────────────────────────────────────────────────────────────
$cleared = $service->updateUser($technicianId, ['telegram_chat_id' => null], $actor);
$assert(
    '3.1 Vaciar el campo desvincula al técnico sin tocar el resto de su ficha',
    $cleared->getTelegramChatId() === null
    && !$cleared->hasTelegramLink()
    && $cleared->isActive()
);

TestDataCleaner::purge($pdo);

echo "\n──────────────────────────────────────────────────────────────────────\n";
echo sprintf(" Total Aserciones: %d | Fallos: %d\n", $assertions, $failures);
echo "──────────────────────────────────────────────────────────────────────\n";

exit($failures === 0 ? 0 : 1);
