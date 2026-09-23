<?php

declare(strict_types=1);

/**
 * CoordinatorAdminRoutesVerificationTest
 * 
 * Verificación de registro de rutas para T-ADM-10:
 * Comprueba que los 20 endpoints administrativos de sedes, máquinas y usuarios
 * están registrados en AppRouter.php y protegidos por InternalAuthMiddleware(COORDINATOR).
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

$router = AppRouter::create();
$assertions = 0;

function assertCheck(bool $condition, string $msg): void {
    global $assertions;
    $assertions++;
    if (!$condition) {
        echo "  [FAIL] {$msg}\n";
        exit(1);
    }
    echo "  [PASS] {$msg}\n";
}

echo "======================================================================\n";
echo " VendGuard: Verificación de Rutas - CoordinatorAdminController (T-ADM-10)\n";
echo "======================================================================\n\n";

$routes = [
    // Sedes
    ['GET', '/api/coordinator/locations'],
    ['POST', '/api/coordinator/locations'],
    ['GET', '/api/coordinator/locations/1'],
    ['PATCH', '/api/coordinator/locations/1'],
    ['PATCH', '/api/coordinator/locations/1/deactivate'],
    ['PATCH', '/api/coordinator/locations/1/reactivate'],
    // Máquinas
    ['GET', '/api/coordinator/machines'],
    ['POST', '/api/coordinator/machines'],
    ['GET', '/api/coordinator/machines/1'],
    ['PATCH', '/api/coordinator/machines/1'],
    ['PATCH', '/api/coordinator/machines/1/transfer'],
    ['PATCH', '/api/coordinator/machines/1/deactivate'],
    ['PATCH', '/api/coordinator/machines/1/reactivate'],
    // Personal Interno
    ['GET', '/api/coordinator/users'],
    ['POST', '/api/coordinator/users'],
    ['GET', '/api/coordinator/users/1'],
    ['PATCH', '/api/coordinator/users/1'],
    ['PATCH', '/api/coordinator/users/1/reset-password'],
    ['PATCH', '/api/coordinator/users/1/deactivate'],
    ['PATCH', '/api/coordinator/users/1/reactivate'],
];

foreach ($routes as [$method, $path]) {
    // Petición anónima (sin Authorization): debe ser rechazada con 401 por InternalAuthMiddleware
    $req = new Request(method: $method, path: $path);
    $res = $router->dispatch($req);
    assertCheck($res->getStatusCode() === 401, "Endpoint {$method} {$path} protegido por Auth (401 Unauthorized sin token)");
}

echo "\n======================================================================\n";
echo " RESULTADO: 100% EN VERDE. ({$assertions} endpoints verificados y protegidos)\n";
echo " CONDICIÓN T-ADM-10 CUMPLIDA SATISFACTORIAMENTE.\n";
echo "======================================================================\n\n";
