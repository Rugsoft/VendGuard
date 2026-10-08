<?php

declare(strict_types=1);

/**
 * SiteAccessCodeTest (hallazgo S-4)
 *
 * Contrato completo de la credencial de sede sobre MariaDB real y el AppRouter real:
 * emisión en el alta, acceso, error genérico, fallo en cerrado, rotación con
 * invalidación inmediata, freno de intentos y retirada de `X-Site-Code`.
 *
 * Todo el escenario corre dentro de una transacción que termina en rollback: la
 * batería no hereda sedes de prueba, claves reemitidas ni bloqueos provocados aquí.
 * La única escritura que sobrevive es la de las semillas (claves de desarrollo), que
 * se ejecuta antes de abrir la transacción y es idempotente.
 *
 * Requirements: RF-01 (EARS 1.1, 1.2, 1.4, 1.5), Art. III (auditoría inmutable),
 * Art. V.4 (una sola puerta de sede).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Service\SiteAccessCodeGenerator;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - SiteAccessCodeTest (hallazgo S-4)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();

$router       = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);

$assertions = 0;
$failures = 0;

$assert = function (string $title, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$title}\n";
        return;
    }
    echo "  [FAIL] {$title}\n";
    if ($detail !== '') {
        echo "         Motivo: {$detail}\n";
    }
    $failures++;
};

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
if ($coordinator === null) {
    echo "ERROR FATAL: no hay usuario coordinador semilla.\n";
    exit(1);
}
$coordinatorToken = $authService->generateInternalToken($coordinator);

/** Lanza un login de sede por el router real. */
$siteLogin = function (string $siteCode, string $accessCode) use ($router) {
    return $router->dispatch(new Request('POST', '/api/auth/site-login', [], [
        'site_code' => $siteCode,
        'access_code' => $accessCode,
    ]));
};

/** Lee el estado del freno de una sede. */
$loginState = function (string $siteCode) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT login_attempts, login_locked_until FROM locations WHERE site_code = :code LIMIT 1');
    $stmt->execute([':code' => $siteCode]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$siteCode = 'SEDE-BCN-01';
$devCode = SeedRunner::devAccessCode($siteCode);

$assert(
    '0.1 Las claves de centro de desarrollo están sembradas en el contexto de pruebas',
    SeedRunner::devAccessCodeSeedingEnabled() && $devCode === 'DEV-SEDE-BCN-01',
    'devCode=' . $devCode
);

$pdo->beginTransaction();

try {
    // ─────────────────────────────────────────────────────────────────────────
    echo "--- 1. Acceso con las dos credenciales (EARS 1.1) ---\n";
    // ─────────────────────────────────────────────────────────────────────────

    $ok = $siteLogin($siteCode, ' dev-sede-bcn-01 ');
    $okBody = $ok->getDecodedBody();
    $token = (string)($okBody['data']['token'] ?? '');

    $assert('1.1 Código de sede + clave de desarrollo correcta responde 200', $ok->getStatusCode() === 200, 'HTTP ' . $ok->getStatusCode());
    $assert('1.2 El token emitido es de sede y de la sede pedida', str_starts_with($token, 'site_token_')
        && ($okBody['data']['location']['site_code'] ?? '') === $siteCode);
    $assert('1.3 La clave se verifica normalizada (espacios, guiones y minúsculas)',
        $authService->validateSiteToken($token) !== null);

    $payload = $authService->validateSiteToken($token) ?? [];
    $assert('1.4 La sesión de sede sigue siendo de 24 h (V-6 intacto)',
        isset($payload['exp']) && abs(((int)$payload['exp']) - (time() + 86400)) <= 30,
        'exp=' . json_encode($payload['exp'] ?? null));

    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Error único y genérico (EARS 1.2) ---\n";
    // ─────────────────────────────────────────────────────────────────────────

    $genericMessage = 'Código o clave no reconocidos. Contacte con el servicio técnico.';

    $wrongKey = $siteLogin($siteCode, 'K7M4P-2QX9R');
    $unknownSite = $siteLogin('SEDE-INVENTADA-99', $devCode);
    $emptyKey = $siteLogin($siteCode, '');

    $wrongKeyBody = $wrongKey->getDecodedBody();
    $unknownSiteBody = $unknownSite->getDecodedBody();

    $assert('2.1 Clave incorrecta responde 401 INVALID_SITE_CREDENTIALS', $wrongKey->getStatusCode() === 401
        && ($wrongKeyBody['error']['code'] ?? '') === 'INVALID_SITE_CREDENTIALS');
    $assert('2.2 Sede inexistente responde el mismo 401 y el mismo código', $unknownSite->getStatusCode() === 401
        && ($unknownSiteBody['error']['code'] ?? '') === 'INVALID_SITE_CREDENTIALS');
    $assert('2.3 El mensaje es idéntico en los dos casos (sin oráculo de existencia)',
        ($wrongKeyBody['error']['message'] ?? '') === $genericMessage
        && ($unknownSiteBody['error']['message'] ?? '') === $genericMessage);
    $assert('2.4 Clave vacía se rechaza como petición incompleta (400)', $emptyKey->getStatusCode() === 400
        && ($emptyKey->getDecodedBody()['error']['code'] ?? '') === 'MISSING_SITE_CREDENTIALS');

    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Fallo en cerrado y sede inactiva ---\n";
    // ─────────────────────────────────────────────────────────────────────────

    $pdo->prepare("
        INSERT INTO `locations` (`site_code`, `name`, `address`, `latitude`, `longitude`, `contact_name`, `contact_phone`, `is_active`)
        VALUES ('SEDE-TEST-NOKEY', 'Sede de prueba sin clave S-4', 'Carrer de Prova 1, Barcelona', 41.3850640, 2.1734035, 'Prueba', '600000000', 1)
    ")->execute();

    $noKey = $siteLogin('SEDE-TEST-NOKEY', $devCode);
    $assert('3.1 Una sede sin clave emitida no puede entrar (fallo en cerrado)',
        $noKey->getStatusCode() === 401
        && ($noKey->getDecodedBody()['error']['code'] ?? '') === 'INVALID_SITE_CREDENTIALS');

    $pdo->prepare("
        INSERT INTO `locations` (`site_code`, `name`, `address`, `latitude`, `longitude`, `contact_name`, `contact_phone`, `is_active`, `access_code_hash`, `access_code_issued_at`)
        VALUES ('SEDE-TEST-OFF', 'Sede de prueba inactiva S-4', 'Carrer de Prova 2, Barcelona', 41.3850640, 2.1734035, 'Prueba', '600000001', 0, :hash, NOW())
    ")->execute([':hash' => password_hash(SiteAccessCodeGenerator::normalize('OFF-SEDE-KEY01'), PASSWORD_BCRYPT, ['cost' => 4])]);

    $inactive = $siteLogin('SEDE-TEST-OFF', 'OFF-SEDE-KEY01');
    $assert('3.2 Una sede inactiva no puede entrar aunque su clave sea correcta',
        $inactive->getStatusCode() === 401
        && ($inactive->getDecodedBody()['error']['code'] ?? '') === 'INVALID_SITE_CREDENTIALS');

    $pendingList = $router->dispatch(new Request('GET', '/api/coordinator/locations', ['search' => 'SEDE-TEST-NOKEY'], [], [
        'Authorization' => "Bearer {$coordinatorToken}",
    ]));
    $pendingRows = $pendingList->getDecodedBody()['data'] ?? [];
    $pendingRow = $pendingRows[0] ?? [];
    $assert('3.3 El panel de coordinación marca las sedes pendientes de entrega',
        $pendingList->getStatusCode() === 200
        && ($pendingRow['site_code'] ?? '') === 'SEDE-TEST-NOKEY'
        && ($pendingRow['has_access_code'] ?? null) === false);

    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- 4. `X-Site-Code` retirada: una sola puerta (Art. V.4) ---\n";
    // ─────────────────────────────────────────────────────────────────────────

    $byHeader = $router->dispatch(new Request('GET', '/api/location/refunds', [], [], ['X-Site-Code' => $siteCode]));
    $headerMessage = (string)($byHeader->getDecodedBody()['error']['message'] ?? '');

    $assert('4.1 La cabecera X-Site-Code ya no autentica (401)', $byHeader->getStatusCode() === 401);
    $assert('4.2 El 401 no nombra la cabecera retirada (no se anuncia la vía antigua)',
        $headerMessage !== '' && !str_contains($headerMessage, 'X-Site-Code'));
    $assert('4.3 El token de sede sí abre la misma ruta (control positivo)',
        $router->dispatch(new Request('GET', '/api/location/refunds', [], [], [
            'Authorization' => "Bearer {$token}",
        ]))->getStatusCode() === 200);

    $middlewareSource = (string)file_get_contents(__DIR__ . '/../../src/Presentation/Http/Middleware/SiteAuthMiddleware.php');
    $portalSource = (string)file_get_contents(__DIR__ . '/../../src/Presentation/Controller/LocationPortalController.php');
    $refundSource = (string)file_get_contents(__DIR__ . '/../../src/Presentation/Controller/LocationRefundController.php');

    $assert('4.4 El middleware de sede ya no declara la cabecera retirada',
        !str_contains($middlewareSource, 'X-Site-Code'));
    $assert('4.5 Ningún controlador de sede conserva el respaldo por cabecera',
        !str_contains($portalSource, 'X-Site-Code') && !str_contains($refundSource, 'X-Site-Code'));

    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Freno de intentos por sede ---\n";
    // ─────────────────────────────────────────────────────────────────────────

    $guardedSite = 'SEDE-BCN-02';
    $guardedDevKey = SeedRunner::devAccessCode($guardedSite);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $siteLogin($guardedSite, 'K7M4P-2QX9R');
    }
    $lockedState = $loginState($guardedSite);
    $assert('5.1 Cinco fallos consecutivos dejan la sede bloqueada',
        (int)($lockedState['login_attempts'] ?? 0) >= 5 && ($lockedState['login_locked_until'] ?? null) !== null,
        json_encode($lockedState));

    $lockedCorrect = $siteLogin($guardedSite, $guardedDevKey);
    $assert('5.2 Con la sede bloqueada, la clave correcta tampoco entra',
        $lockedCorrect->getStatusCode() === 401
        && ($lockedCorrect->getDecodedBody()['error']['code'] ?? '') === 'INVALID_SITE_CREDENTIALS');

    // El bloqueo es temporal: al expirar, el contador se reinicia con el siguiente fallo.
    $pdo->prepare('UPDATE locations SET login_locked_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE site_code = :code')
        ->execute([':code' => $guardedSite]);

    $siteLogin($guardedSite, 'K7M4P-2QX9R');
    $healedState = $loginState($guardedSite);
    $assert('5.3 Un bloqueo expirado se reinicia con un solo fallo (no vuelve a bloquear de inmediato)',
        (int)($healedState['login_attempts'] ?? 0) === 1 && ($healedState['login_locked_until'] ?? null) === null,
        json_encode($healedState));

    $unlocked = $siteLogin($guardedSite, $guardedDevKey);
    $afterSuccess = $loginState($guardedSite);
    $assert('5.4 El acierto limpia contador y bloqueo',
        $unlocked->getStatusCode() === 200
        && (int)($afterSuccess['login_attempts'] ?? -1) === 0
        && ($afterSuccess['login_locked_until'] ?? null) === null);

    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Emisión en el alta y rotación (EARS 1.4 y 1.5) ---\n";
    // ─────────────────────────────────────────────────────────────────────────

    $created = $router->dispatch(new Request('POST', '/api/coordinator/locations', [], [
        'site_code' => 'SEDE-TEST-NEW',
        'name' => 'Sede de prueba S-4',
        'address' => 'Carrer de Prova 3, Barcelona',
        'latitude' => 41.3850640,
        'longitude' => 2.1734035,
    ], ['Authorization' => "Bearer {$coordinatorToken}"]));
    $createdBody = $created->getDecodedBody();
    $createdCode = (string)($createdBody['data']['access_code'] ?? '');

    $assert('6.1 El alta de sede responde 201 con una clave de centro de formato válido',
        $created->getStatusCode() === 201 && SiteAccessCodeGenerator::isValid($createdCode),
        'HTTP ' . $created->getStatusCode() . ' · code=' . $createdCode);
    $assert('6.2 La clave recién emitida inicia sesión de esa sede',
        $siteLogin('SEDE-TEST-NEW', $createdCode)->getStatusCode() === 200);

    $storedHash = (string)$pdo->query("SELECT access_code_hash FROM locations WHERE site_code = 'SEDE-TEST-NEW'")->fetchColumn();
    $assert('6.3 La base de datos solo guarda la huella bcrypt, nunca la clave',
        $storedHash !== '' && str_starts_with($storedHash, '$2y$') && !str_contains($storedHash, SiteAccessCodeGenerator::normalize($createdCode)));

    $locationId = (int)$pdo->query("SELECT id FROM locations WHERE site_code = '{$siteCode}'")->fetchColumn();
    $reissued = $router->dispatch(new Request('POST', "/api/coordinator/locations/{$locationId}/access-code", [], [], [
        'Authorization' => "Bearer {$coordinatorToken}",
    ]));
    $reissuedBody = $reissued->getDecodedBody();
    $newCode = (string)($reissuedBody['data']['access_code'] ?? '');

    $assert('6.4 La reemisión responde 200 con una clave nueva válida',
        $reissued->getStatusCode() === 200 && SiteAccessCodeGenerator::isValid($newCode) && $newCode !== $devCode,
        'HTTP ' . $reissued->getStatusCode() . ' · code=' . $newCode);
    $assert('6.5 La clave anterior deja de valer al instante',
        $siteLogin($siteCode, $devCode)->getStatusCode() === 401);
    $assert('6.6 La clave nueva entra',
        $siteLogin($siteCode, $newCode)->getStatusCode() === 200);

    $auditRow = $pdo->prepare("
        SELECT action, metadata FROM audit_log
        WHERE entity_type = 'LOCATION' AND entity_id = :id AND action = 'LOCATION_ACCESS_CODE_REISSUED'
        ORDER BY id DESC LIMIT 1
    ");
    $auditRow->execute([':id' => $locationId]);
    $audit = $auditRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $auditJson = (string)($audit['metadata'] ?? '');

    $assert('6.7 La rotación queda registrada en la auditoría inmutable (Art. III)',
        ($audit['action'] ?? '') === 'LOCATION_ACCESS_CODE_REISSUED');
    $assert('6.8 La auditoría no contiene la clave en claro en ninguna de sus formas',
        !str_contains($auditJson, $newCode) && !str_contains($auditJson, SiteAccessCodeGenerator::normalize($newCode)));

    $loginBody = (string)$ok->getBody();
    $assert('6.9 La respuesta de login nunca devuelve la clave de centro',
        !str_contains($loginBody, $devCode) && !str_contains($loginBody, SiteAccessCodeGenerator::normalize($devCode)));

} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// El rollback deja el estado de semillas intacto para el resto de la batería.
$restored = (string)$pdo->query("SELECT access_code_hash FROM locations WHERE site_code = 'SEDE-BCN-01'")->fetchColumn();
$assert('7.1 El escenario de prueba no deja rastro: la clave de desarrollo sigue vigente',
    password_verify(SiteAccessCodeGenerator::normalize('DEV-SEDE-BCN-01'), $restored));
$assert('7.2 La sede de prueba no sobrevive al rollback',
    (int)$pdo->query("SELECT COUNT(*) FROM locations WHERE site_code = 'SEDE-TEST-NEW'")->fetchColumn() === 0);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CREDENCIAL DE SEDE (S-4) CERRADA.\n";
    echo "======================================================================\n";
    exit(0);
}
echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
echo "======================================================================\n";
exit(1);
