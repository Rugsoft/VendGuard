<?php

declare(strict_types=1);

/**
 * RouteMapRoutesVerificationTest
 *
 * Routing verification for task T-MAP-11 (RF-MAP-01, RF-MAP-02, RF-MAP-07, RF-MAP-09):
 * 1. The technician and coordinator route map endpoints are registered in AppRouter.php
 *    and bound to the proper InternalAuthMiddleware RBAC profile.
 * 2. Anonymous requests are rejected with 401 Unauthorized by the router.
 * 3. Valid tokens from a wrong role are rejected with 403 Forbidden by the middleware.
 *
 * Vanilla doctrine: native PHP only, no external dependencies, no database required.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Presentation\Http\Middleware\InternalAuthMiddleware;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

$assertions = 0;

function assertRoutingCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "  [FAIL] {$message}\n");
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

echo "======================================================================\n";
echo " VendGuard: Verificación de Rutas - Mapa de Rutas (T-MAP-11)\n";
echo "======================================================================\n\n";

// =========================================================================
// BLOQUE 1: Registro estático de rutas con perfil RBAC en AppRouter.php
// =========================================================================
echo "--- BLOQUE 1: Registro de rutas y binding RBAC en AppRouter.php ---\n";

$routerSource = (string)file_get_contents(__DIR__ . '/../../src/Presentation/Routing/AppRouter.php');

$expectedRegistrations = [
    "\$router->get('/api/coordinator/map/active-incidents', [CoordinatorRouteMapController::class, 'getActiveIncidents'], [\$coordinatorAuth]);",
    "\$router->get('/api/coordinator/route/settings', [CoordinatorRouteMapController::class, 'getRouteSettings'], [\$coordinatorAuth]);",
    "\$router->put('/api/coordinator/route/settings', [CoordinatorRouteMapController::class, 'updateRouteSettings'], [\$coordinatorAuth]);",
    "\$router->get('/api/technician/route/map', [TechnicianRouteMapController::class, 'getRouteMap'], [\$technicianAuth]);",
];

foreach ($expectedRegistrations as $registration) {
    assertRoutingCondition(
        str_contains($routerSource, $registration),
        'Route registration present: ' . $registration
    );
    $routeSignature = explode(', [', $registration)[0] . ', [';
    assertRoutingCondition(
        substr_count($routerSource, $routeSignature) === 1,
        'Route registered exactly once: ' . $routeSignature
    );
}

// =========================================================================
// BLOQUE 2: Rechazo anónimo (401) a través del router real
// =========================================================================
echo "\n--- BLOQUE 2: 401 Unauthorized para peticiones sin token ---\n";

$router = AppRouter::create();
$protectedRoutes = [
    ['GET', '/api/coordinator/map/active-incidents'],
    ['GET', '/api/coordinator/route/settings'],
    ['PUT', '/api/coordinator/route/settings'],
    ['GET', '/api/technician/route/map'],
];

foreach ($protectedRoutes as [$method, $path]) {
    $anonymousResponse = $router->dispatch(new Request(method: $method, path: $path));
    assertRoutingCondition(
        $anonymousResponse->getStatusCode() === 401,
        "Endpoint {$method} {$path} rejects anonymous requests with 401"
    );
    $invalidTokenResponse = $router->dispatch(
        new Request($method, $path, [], [], ['Authorization' => 'Bearer invalid'])
    );
    assertRoutingCondition(
        $invalidTokenResponse->getStatusCode() === 401,
        "Endpoint {$method} {$path} rejects invalid bearer tokens with 401"
    );
}

// =========================================================================
// BLOQUE 3: Rechazo RBAC (403) con token válido de rol incorrecto
// =========================================================================
echo "\n--- BLOQUE 3: 403 Forbidden para tokens de rol incorrecto ---\n";

final class RouteMapRoutesLocationRepositoryStub implements LocationRepositoryInterface
{
    public function findBySiteCode(string $siteCode): ?Location { return null; }
    public function findById(int $id): ?Location { return null; }
    public function findAllActive(): array { return []; }
    public function findAll(string $status = 'all', ?string $search = null): array { return []; }
    public function create(array $data): Location { throw new LogicException(); }
    public function update(int $id, array $data): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function restore(int $id): bool { return false; }
    public function updateContactPhone(int $id, string $contactPhone): bool { return false; }
    public function countActiveMachines(int $locationId): int { return 0; }
}

final class RouteMapRoutesUserRepositoryStub implements UserRepositoryInterface
{
    public function __construct(
        private readonly User $coordinator,
        private readonly User $technician
    ) {}

    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User { return null; }
    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        return match ($id) {
            $this->coordinator->getId() => $this->coordinator,
            $this->technician->getId() => $this->technician,
            default => null,
        };
    }
    public function findAllTechnicians(bool $onlyActive = true): array { return []; }
    public function create(array $data): User { throw new LogicException(); }
    public function update(int $id, array $data): bool { return false; }
    public function updatePassword(int $id, string $newPassword): bool { return false; }
    public function resetPassword(int $id, string $newPassword): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function restore(int $id): bool { return false; }
    public function findAll(array $filters = []): array { return []; }
    public function countActiveByRole(UserRole|string $role): int { return 0; }
    public function countActiveAssignedIncidents(int $userId): int { return 0; }
    public function countPendingIncidents(int $technicianId): int { return 0; }
}

$userRepository = new RouteMapRoutesUserRepositoryStub(
    new User(8, 'Coordinador de prueba', 'coordinator@example.test', 'hash', UserRole::COORDINATOR),
    new User(9, 'Técnico de prueba', 'technician@example.test', 'hash', UserRole::TECHNICIAN)
);
$authService = new AuthService(new RouteMapRoutesLocationRepositoryStub(), $userRepository);
$coordinatorToken = $authService->generateInternalToken($userRepository->findById(8));
$technicianToken = $authService->generateInternalToken($userRepository->findById(9));

$coordinatorMiddleware = new InternalAuthMiddleware(UserRole::COORDINATOR, $authService, $userRepository);
$technicianMiddleware = new InternalAuthMiddleware(UserRole::TECHNICIAN, $authService, $userRepository);
$nextHandler = static fn(Request $request): Response => Response::json(['reached' => true]);

// Coordinator routes: coordinator token passes, technician token is forbidden.
foreach (['/api/coordinator/map/active-incidents', '/api/coordinator/route/settings'] as $path) {
    $request = static fn(string $token): Request => new Request(
        'GET',
        $path,
        [],
        [],
        ['Authorization' => "Bearer {$token}"]
    );
    $accepted = $coordinatorMiddleware->handle($request($coordinatorToken), $nextHandler);
    assertRoutingCondition(
        $accepted->getStatusCode() === 200,
        "Endpoint GET {$path} accepts a valid coordinator token"
    );
    $forbidden = $coordinatorMiddleware->handle($request($technicianToken), $nextHandler);
    assertRoutingCondition(
        $forbidden->getStatusCode() === 403 && str_contains((string)$forbidden->getBody(), 'FORBIDDEN'),
        "Endpoint GET {$path} rejects a technician token with 403"
    );
}

// PUT settings uses the same coordinator RBAC profile with a request body.
$putRequest = static fn(string $token): Request => new Request(
    'PUT',
    '/api/coordinator/route/settings',
    [],
    ['base_name' => 'Base Central VendGuard'],
    ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json']
);
$putAccepted = $coordinatorMiddleware->handle($putRequest($coordinatorToken), $nextHandler);
assertRoutingCondition(
    $putAccepted->getStatusCode() === 200,
    'Endpoint PUT /api/coordinator/route/settings accepts a valid coordinator token'
);
$putForbidden = $coordinatorMiddleware->handle($putRequest($technicianToken), $nextHandler);
assertRoutingCondition(
    $putForbidden->getStatusCode() === 403 && str_contains((string)$putForbidden->getBody(), 'FORBIDDEN'),
    'Endpoint PUT /api/coordinator/route/settings rejects a technician token with 403'
);

// Technician route: technician token passes, coordinator token is forbidden.
$mapRequest = static fn(string $token): Request => new Request(
    'GET',
    '/api/technician/route/map',
    [],
    [],
    ['Authorization' => "Bearer {$token}"]
);
$mapAccepted = $technicianMiddleware->handle($mapRequest($technicianToken), $nextHandler);
assertRoutingCondition(
    $mapAccepted->getStatusCode() === 200,
    'Endpoint GET /api/technician/route/map accepts a valid technician token'
);
$mapForbidden = $technicianMiddleware->handle($mapRequest($coordinatorToken), $nextHandler);
assertRoutingCondition(
    $mapForbidden->getStatusCode() === 403 && str_contains((string)$mapForbidden->getBody(), 'FORBIDDEN'),
    'Endpoint GET /api/technician/route/map rejects a coordinator token with 403'
);

echo "\n======================================================================\n";
echo " RESULTADO: 100% EN VERDE. ({$assertions} aserciones de enrutamiento pasadas)\n";
echo " CONDICIÓN T-MAP-11 CUMPLIDA SATISFACTORIAMENTE.\n";
echo "======================================================================\n";
