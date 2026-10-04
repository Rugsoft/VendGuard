<?php

declare(strict_types=1);

/**
 * QrLabelHostResolutionIntegrationTest
 *
 * Test de Integración de la Enmienda 1 (T-QR-19) — contrato `qr_codes_contracts.md` §1.2.
 *
 * Valida sobre HTTP in-memory real (router + middleware de coordinador + MariaDB):
 * 1. Host efectivo de la petición tras proxy TLS (Host + X-Forwarded-Proto: https).
 * 2. Petición local directa (http://127.0.0.1:8000).
 * 3. Precedencia de la variable de entorno APP_BASE_URL.
 * 4. Fallback de desarrollo http://localhost sin host ni variable.
 * 5. Aplicación del host resuelto también al lote A4 de la sede.
 * 6. Pie del SVG con el host resuelto y ausencia de dominios embebidos (RNF-05).
 *
 * Cumple con la Enmienda 1 aprobada el 2026-10-04 y los Artículos I, IV y V de la Constitución.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - Resolución de Host QR (Enmienda 1 §1.2)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

// Restauración de semillas.
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$authService = new AuthService($locationRepo, $userRepo);

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

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
if ($coordinator === null) {
    echo "ERROR CRÍTICO: Coordinador semilla no encontrado.\n";
    exit(1);
}
$coordToken = $authService->generateInternalToken($coordinator);
$machine = $machineRepo->findByCode('VEND-0101');
$locationBcn1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$assert("0.1 Máquina y sede semilla encontradas", $machine !== null && $locationBcn1 !== null);
if ($machine === null || $locationBcn1 === null) {
    exit(1);
}

$machineId = $machine->getId();
$locationId = $locationBcn1->getId();

// La resolución dinámica sólo aplica cuando APP_BASE_URL no está definida en el entorno.
$previousEnvBaseUrl = getenv('APP_BASE_URL');
putenv('APP_BASE_URL');

/**
 * Emite la etiqueta individual con las cabeceras indicadas y devuelve el cuerpo decodificado.
 *
 * @param array<string, string> $headers
 * @return array<string, mixed>
 */
$fetchLabel = function (array $headers) use ($router, $machineId, $coordToken): array {
    $request = new Request(
        'GET',
        "/api/coordinator/machines/{$machineId}/qr-label",
        [],
        [],
        array_merge(['authorization' => 'Bearer ' . $coordToken], $headers)
    );
    $response = $router->dispatch($request);
    return json_decode($response->getBody(), true) ?? [];
};

try {
    // ---------------------------------------------------------------------
    // 1. Host efectivo tras proxy TLS (Render)
    // ---------------------------------------------------------------------
    echo "\n--- 1. Host Efectivo de la Petición (Proxy TLS) ---\n";

    $bodyProxy = $fetchLabel(['host' => 'vendguard.onrender.com', 'x-forwarded-proto' => 'https']);
    $targetProxy = (string)($bodyProxy['data']['qr_target_url'] ?? '');
    $assert(
        "1.1 Host + X-Forwarded-Proto: https ⇒ https://vendguard.onrender.com/?qr=...",
        str_starts_with($targetProxy, 'https://vendguard.onrender.com/?qr=VEND-0101&site=SEDE-BCN-01'),
        $targetProxy
    );
    $assert(
        "1.2 El pie del SVG muestra el host resuelto",
        str_contains((string)($bodyProxy['data']['svg_content'] ?? ''), 'VendGuard Security &amp; Safety · vendguard.onrender.com'),
        'pie sin host resuelto'
    );
    $assert(
        "1.3 La etiqueta no arrastra dominios distintos del resuelto (sin localhost embebido)",
        stripos((string)($bodyProxy['data']['svg_content'] ?? ''), 'localhost') === false
            && stripos((string)($bodyProxy['data']['svg_content'] ?? ''), '127.0.0.1') === false,
        'dominio residual detectado'
    );

    // ---------------------------------------------------------------------
    // 2. Petición local directa (sin proxy TLS)
    // ---------------------------------------------------------------------
    echo "\n--- 2. Petición Local Directa ---\n";

    $bodyLocal = $fetchLabel(['host' => '127.0.0.1:8000']);
    $targetLocal = (string)($bodyLocal['data']['qr_target_url'] ?? '');
    $assert(
        "2.1 Host local ⇒ http://127.0.0.1:8000/?qr=...",
        str_starts_with($targetLocal, 'http://127.0.0.1:8000/?qr=VEND-0101&site=SEDE-BCN-01'),
        $targetLocal
    );
    $assert(
        "2.2 El pie del SVG refleja el host local con puerto",
        str_contains((string)($bodyLocal['data']['svg_content'] ?? ''), '127.0.0.1:8000'),
        'pie sin puerto local'
    );
    $assert(
        "2.3 Emisión local sin rastro del dominio anterior embebido",
        stripos((string)($bodyLocal['data']['svg_content'] ?? ''), 'onrender') === false,
        'dominio embebido detectado'
    );

    // ---------------------------------------------------------------------
    // 3. Precedencia de la variable de entorno APP_BASE_URL
    // ---------------------------------------------------------------------
    echo "\n--- 3. Variable de Entorno APP_BASE_URL ---\n";

    putenv('APP_BASE_URL=https://qr.vendguard.example');
    $bodyEnv = $fetchLabel(['host' => '127.0.0.1:8000']);
    $targetEnv = (string)($bodyEnv['data']['qr_target_url'] ?? '');
    $assert(
        "3.1 APP_BASE_URL prevalece sobre el host de la petición",
        str_starts_with($targetEnv, 'https://qr.vendguard.example/?qr=VEND-0101&site=SEDE-BCN-01'),
        $targetEnv
    );
    putenv('APP_BASE_URL');

    // ---------------------------------------------------------------------
    // 4. Fallback de desarrollo sin host ni variable de entorno
    // ---------------------------------------------------------------------
    echo "\n--- 4. Fallback de Desarrollo ---\n";

    $bodyFallback = $fetchLabel([]);
    $targetFallback = (string)($bodyFallback['data']['qr_target_url'] ?? '');
    $assert(
        "4.1 Sin host ni APP_BASE_URL ⇒ http://localhost/?qr=...",
        str_starts_with($targetFallback, 'http://localhost/?qr=VEND-0101&site=SEDE-BCN-01'),
        $targetFallback
    );

    // ---------------------------------------------------------------------
    // 5. Lote A4 de la sede con host resuelto
    // ---------------------------------------------------------------------
    echo "\n--- 5. Lote de Sede (A4) con Host Resuelto ---\n";

    $batchRequest = new Request(
        'GET',
        "/api/coordinator/locations/{$locationId}/qr-batch",
        [],
        [],
        [
            'authorization' => 'Bearer ' . $coordToken,
            'host' => 'vendguard.onrender.com',
            'x-forwarded-proto' => 'https',
        ]
    );
    $batchResponse = $router->dispatch($batchRequest);
    $bodyBatch = json_decode($batchResponse->getBody(), true) ?? [];
    $items = $bodyBatch['data']['items'] ?? [];
    $assert("5.1 El lote responde con las máquinas de la sede", count($items) === 2, 'items: ' . count($items));

    $allItemsResolved = true;
    foreach ($items as $item) {
        if (!str_starts_with((string)($item['qr_target_url'] ?? ''), 'https://vendguard.onrender.com/?qr=')) {
            $allItemsResolved = false;
            break;
        }
    }
    $assert("5.2 Cada etiqueta del lote codifica el host resuelto de la petición", $allItemsResolved);
} finally {
    // Restauración del entorno previo de la suite.
    if ($previousEnvBaseUrl !== false) {
        putenv('APP_BASE_URL=' . $previousEnvBaseUrl);
    }
}

// Resumen final
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS DE RESOLUCIÓN DE HOST PASARON (0 fallos)!\n";
    echo " CONDICIÓN T-QR-19 CUMPLIDA.\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
