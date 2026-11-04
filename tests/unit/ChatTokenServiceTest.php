<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\SiteAccessCodeRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserLockoutRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Presentation\Controller\AuthController;
use VendGuard\Presentation\Http\Request;

echo "======================================================================\n";
echo " VendGuard: Unit Test - ChatTokenServiceTest (RFC 7519 / HS256)\n";
echo "======================================================================\n\n";

$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
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

$coordinator = new User(
    id: 101,
    name: 'Carlos Coordinador',
    email: 'carlos@vendguard.internal',
    passwordHash: password_hash('Password123!', PASSWORD_BCRYPT),
    role: UserRole::COORDINATOR,
    phone: '+34600111222'
);

$technician = new User(
    id: 202,
    name: 'Jordi Técnico',
    email: 'jordi@vendguard.internal',
    passwordHash: password_hash('Password123!', PASSWORD_BCRYPT),
    role: UserRole::TECHNICIAN,
    phone: '+34600333444'
);

$dummyLocationRepo = new class implements LocationRepositoryInterface {
    public function findBySiteCode(string $siteCode, bool $onlyActive = true): ?Location { return null; }
    public function findById(int $id, bool $onlyActive = true): ?Location { return null; }
    public function findAllActive(): array { return []; }
    public function findAll(string $status = 'all', ?string $search = null): array { return []; }
    public function create(array $data): Location { throw new RuntimeException('Not implemented'); }
    public function update(int $id, array $data): bool { return true; }
    public function softDelete(int $id): bool { return true; }
    public function restore(int $id): bool { return true; }
    public function updateContactPhone(int $id, string $contactPhone): bool { return true; }
    public function countActiveMachines(int $locationId): int { return 0; }
};

$dummyUserRepo = new class($coordinator) implements UserRepositoryInterface, UserLockoutRepositoryInterface {
    public function __construct(private ?User $storedUser = null) {}
    public function setStoredUser(?User $user): void { $this->storedUser = $user; }
    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User { return $this->storedUser; }
    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User { return $this->storedUser; }
    public function findAllTechnicians(bool $onlyActive = true): array { return []; }
    public function findAllActive(): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function create(array $data): User { throw new RuntimeException('Not implemented'); }
    public function update(int $id, array $data): bool { return true; }
    public function updatePassword(int $id, string $newPassword): bool { return true; }
    public function resetPassword(int $id, string $newPassword): bool { return true; }
    public function softDelete(int $id): bool { return true; }
    public function restore(int $id): bool { return true; }
    public function countActiveByRole(UserRole|string $role): int { return 0; }
    public function countActiveAssignedIncidents(int $userId): int { return 0; }
    public function countPendingIncidents(int $technicianId): int { return 0; }
    public function findLoginLockoutState(int $userId): ?array { return null; }
    public function registerFailedLogin(int $userId, int $maxAttempts, int $lockSeconds): void {}
    public function resetLoginAttempts(int $userId): void {}
};

$dummyAccessCodeRepo = new class implements SiteAccessCodeRepositoryInterface {
    public function findAccessState(int $locationId): ?array { return null; }
    public function storeAccessCodeHash(int $locationId, string $hash): void {}
    public function registerFailedLogin(int $locationId, int $maxAttempts, int $lockSeconds): void {}
    public function resetLoginAttempts(int $locationId): void {}
};

$authSecret = 'test-secret-key-chat-token-12345';
$authService = new AuthService(
    locationRepo: $dummyLocationRepo,
    userRepo: $dummyUserRepo,
    secretKey: $authSecret,
    accessCodeRepo: $dummyAccessCodeRepo,
    userLockoutRepo: $dummyUserRepo
);

// ---------------------------------------------------------------------
// 1. Estructura estándar RFC 7519 (3 partes)
// ---------------------------------------------------------------------
echo "--- 1. Estructura estándar JWT RFC 7519 ---\n";
$chatToken = $authService->generateChatToken($coordinator);
$parts = explode('.', $chatToken);

$assert("El token generado tiene exactamente 3 partes separadas por '.'", count($parts) === 3);

// Base64Url decode header
$headerJson = base64_decode(strtr($parts[0], '-_', '+/'));
$header = json_decode($headerJson, true);
$assert("Header alg es HS256", ($header['alg'] ?? '') === 'HS256');
$assert("Header typ es JWT", ($header['typ'] ?? '') === 'JWT');

// ---------------------------------------------------------------------
// 2. Claims del payload
// ---------------------------------------------------------------------
echo "\n--- 2. Claims requeridos por el agente n8n ---\n";
$payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
$payload = json_decode($payloadJson, true);

$assert("Claim 'sub' coincide con el ID del usuario", ($payload['sub'] ?? '') === '101');
$assert("Claim 'name' coincide con el nombre", ($payload['name'] ?? '') === 'Carlos Coordinador');
$assert("Claim 'email' coincide con el email", ($payload['email'] ?? '') === 'carlos@vendguard.internal');
$assert("Claim 'role' mapea a 'coordinador'", ($payload['role'] ?? '') === 'coordinador');
$assert("Claim 'iat' es entero y reciente", isset($payload['iat']) && is_int($payload['iat']) && abs(time() - $payload['iat']) < 5);
$assert("Claim 'exp' es 8 horas posterior a iat (28800s)", isset($payload['exp']) && $payload['exp'] === $payload['iat'] + 28800);

// Comprobar rol de técnico
$techToken = $authService->generateChatToken($technician);
$techParts = explode('.', $techToken);
$techPayload = json_decode(base64_decode(strtr($techParts[1], '-_', '+/')), true);
$assert("Claim 'role' para técnico mapea a 'tecnico'", ($techPayload['role'] ?? '') === 'tecnico');

// ---------------------------------------------------------------------
// 3. Validación y firma criptográfica
// ---------------------------------------------------------------------
echo "\n--- 3. Firma criptográfica HS256 y verificación ---\n";
$validated = $authService->validateChatToken($chatToken);
$assert("validateChatToken valida exitosamente un token recién emitido", $validated !== null && ($validated['sub'] ?? '') === '101');

// Firma manual con hash_hmac raw binary base64url
$expectedSigRaw = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $authSecret, true);
$expectedSig = rtrim(strtr(base64_encode($expectedSigRaw), '+/', '-_'), '=');
$assert("Firma de 3er segmento coincide con HMAC-SHA256 binario RFC 7515", $parts[2] === $expectedSig);

// Token alterado (tampering)
$tamperedToken = $parts[0] . '.' . $parts[1] . '.tampered_signature';
$assert("validateChatToken rechaza un token manipulado", $authService->validateChatToken($tamperedToken) === null);

// Token expirado
$expiredToken = $authService->generateChatToken($coordinator, ttlSeconds: -10);
$assert("validateChatToken rechaza un token expirado", $authService->validateChatToken($expiredToken) === null);

// Token con otra clave secreta
$otherAuthService = new AuthService(
    locationRepo: $dummyLocationRepo,
    userRepo: $dummyUserRepo,
    secretKey: 'different-secret-key-999',
    accessCodeRepo: $dummyAccessCodeRepo,
    userLockoutRepo: $dummyUserRepo
);
$assert("validateChatToken con otra clave secreta rechaza el token", $otherAuthService->validateChatToken($chatToken) === null);

// ---------------------------------------------------------------------
// 4. Integración en AuthController::login (POST /api/auth/login)
// ---------------------------------------------------------------------
echo "\n--- 4. AuthController::login emite chat_token ---\n";
$controller = new AuthController($authService);
$request = new Request('POST', '/api/auth/login', [], [
    'email' => 'carlos@vendguard.internal',
    'password' => 'Password123!',
]);
$response = $controller->login($request);

$assert("AuthController::login responde 200 OK", $response->getStatusCode() === 200);
$body = $response->getDecodedBody();

$assert("Respuesta contiene 'token' de sesión tradicional", isset($body['data']['token']) && str_starts_with($body['data']['token'], 'auth_token_'));
$assert("Respuesta contiene 'chat_token' firmado", isset($body['data']['chat_token']) && count(explode('.', $body['data']['chat_token'])) === 3);

$validatedFromController = $authService->validateChatToken($body['data']['chat_token']);
$assert("chat_token retornado por AuthController es válido", $validatedFromController !== null && ($validatedFromController['name'] ?? '') === 'Carlos Coordinador');

echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: Todas las pruebas unitarias pasaron con éxito.\n";
} else {
    echo " RESULTADO: {$failures} pruebas fallaron.\n";
    exit(1);
}
echo "======================================================================\n";
