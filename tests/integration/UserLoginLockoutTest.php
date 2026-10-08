<?php

declare(strict_types=1);

namespace VendGuard\Tests\Integration;

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;
use RuntimeException;

/**
 * UserLoginLockoutTest (Hallazgo S-3)
 *
 * Valida el freno contra ataques de fuerza bruta en cuentas de usuarios internos:
 * 1. Intentos fallidos consecutivos incrementan el contador.
 * 2. Al alcanzar 5 intentos fallidos consecutivos, la cuenta queda bloqueada con HTTP 423 ACCOUNT_LOCKED.
 * 3. Si la cuenta está bloqueada, incluso con la contraseña correcta se rechaza con 423 ACCOUNT_LOCKED.
 * 4. Correos inexistentes responden 401 INVALID_CREDENTIALS sin generar oráculo de existencia ni afectar a otras cuentas.
 * 5. Un acceso correcto reinicia el contador de intentos a 0.
 * 6. Expiración del bloqueo transcurrido el tiempo pactado.
 */

require_once __DIR__ . '/../bootstrap.php';

$pdo = ConnectionFactory::getConnection();

echo "======================================================================\n";
echo " VendGuard: Test de Integración - UserLoginLockoutTest (S-3)\n";
echo "======================================================================\n";

$assertionCount = 0;
$assert = function (string $desc, bool $cond) use (&$assertionCount): void {
    $assertionCount++;
    if (!$cond) {
        throw new RuntimeException("FALLO EN ASERCIÓN [{$assertionCount}]: {$desc}");
    }
    echo "  [PASS] Aserción {$assertionCount}: {$desc}\n";
};

$userRepo = new PdoUserRepository($pdo);
$authService = new AuthService(userRepo: $userRepo);
$router = AppRouter::create();

// Seleccionar un usuario de prueba (Jordi Técnico)
$targetEmail = 'jordi.ruta@vendguard.internal';
$user = $userRepo->findByEmail($targetEmail);
$assert('El usuario objetivo de prueba existe', $user !== null);
$userId = $user->getId();

// Limpiar estado previo
$userRepo->resetLoginAttempts($userId);

// 1. Verificar estado inicial
$state = $userRepo->findLoginLockoutState($userId);
$assert('Estado inicial sin intentos y no bloqueado', $state !== null && $state['attempts'] === 0 && !$state['is_locked']);

// 2. Ejecutar 4 intentos fallidos
for ($i = 1; $i <= 4; $i++) {
    $req = new Request('POST', '/api/auth/login', [], [
        'email' => $targetEmail,
        'password' => 'WrongPassword!' . $i,
    ]);
    $res = $router->dispatch($req);
    $assert("Intento fallido #{$i} responde 401 INVALID_CREDENTIALS", $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'INVALID_CREDENTIALS');
    
    $curState = $userRepo->findLoginLockoutState($userId);
    $assert("Contador de intentos en {$i}", $curState['attempts'] === $i && !$curState['is_locked']);
}

// 3. Quinto intento fallido activa bloqueo inmediatamente (HTTP 423 ACCOUNT_LOCKED)
$req5 = new Request('POST', '/api/auth/login', [], [
    'email' => $targetEmail,
    'password' => 'WrongPassword!5',
]);
$res5 = $router->dispatch($req5);
$assert('Quinto intento fallido responde HTTP 423 ACCOUNT_LOCKED', $res5->getStatusCode() === 423 && ($res5->getDecodedBody()['error']['code'] ?? '') === 'ACCOUNT_LOCKED');

$curState = $userRepo->findLoginLockoutState($userId);
$assert('Estado indica is_locked = true tras 5 fallos', $curState['is_locked'] === true && $curState['attempts'] >= 5);

// 4. Con la cuenta bloqueada, un intento con la contraseña CORRECTA también es rechazado con 423
$reqGoodWhileLocked = new Request('POST', '/api/auth/login', [], [
    'email' => $targetEmail,
    'password' => 'Password123!',
]);
$resGoodWhileLocked = $router->dispatch($reqGoodGoodWhileLocked = $reqGoodWhileLocked);
$assert('Contraseña correcta mientras está bloqueada responde HTTP 423 ACCOUNT_LOCKED', $resGoodWhileLocked->getStatusCode() === 423 && ($resGoodWhileLocked->getDecodedBody()['error']['code'] ?? '') === 'ACCOUNT_LOCKED');

// 5. Correo inexistente no genera bloqueo y responde 401
$reqUnknown = new Request('POST', '/api/auth/login', [], [
    'email' => 'noexiste@vendguard.internal',
    'password' => 'Cualquiera!',
]);
$resUnknown = $router->dispatch($reqUnknown);
$assert('Correo inexistente responde 401 INVALID_CREDENTIALS', $resUnknown->getStatusCode() === 401 && ($resUnknown->getDecodedBody()['error']['code'] ?? '') === 'INVALID_CREDENTIALS');

// 6. Simular expiración del bloqueo y acceso correcto que reinicia contador
$pdo->prepare("UPDATE `users` SET `login_locked_until` = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE `id` = :id")
    ->execute([':id' => $userId]);

$reqGood = new Request('POST', '/api/auth/login', [], [
    'email' => $targetEmail,
    'password' => 'Password123!',
]);
$resGood = $router->dispatch($reqGood);
$assert('Tras expirar el bloqueo, contraseña correcta responde HTTP 200', $resGood->getStatusCode() === 200);

$finalState = $userRepo->findLoginLockoutState($userId);
$assert('Acceso correcto reinicia contador a 0 y limpia bloqueo', $finalState !== null && $finalState['attempts'] === 0 && !$finalState['is_locked']);

echo "\n======================================================================\n";
echo " RESUMEN: {$assertionCount} aserciones superadas exitosamente (100% PASS).\n";
echo " HALLAZGO S-3 VERIFICADO SATISFACTORIAMENTE.\n";
echo "======================================================================\n";
