<?php

declare(strict_types=1);

/**
 * CreateIncidentEndpointTest
 * 
 * Test de Integración para el Endpoint de Creación de Incidencias (Tarea T-22).
 * Valida los requisitos funcionales RF-02, RF-03 y RNF-02 / RNF-05:
 * 1. Recepción y procesamiento de datos multipart/form-data y application/json.
 * 2. Cálculo automático del nivel de urgencia según tipo de máquina y categoría de avería (EARS 3.2-3.7).
 *    - PERISHABLE_FOOD + TEMPERATURE_COLD => CRITICAL (Mandato Sanitario Art. II).
 *    - HOT_DRINKS + TEMPERATURE_COLD => MEDIUM (EARS 3.3).
 *    - PAYMENT_SYSTEM => HIGH (EARS 3.4).
 *    - PRODUCT_JAM => MEDIUM (EARS 3.5).
 * 3. Detección y bloqueo estricto de incidencias duplicadas con HTTP 409 Conflict (EARS 2.1).
 * 4. Bloqueo en ventana de garantía de 48h tras resolución con HTTP 409 Conflict (EARS 2.2).
 * 5. Validación de subida de evidencias fotográficas (<= 5 MB, JPEG/PNG/WebP) y conservación
 *    de campos del formulario en caso de error (EARS 3.9).
 * 6. Segregación de sedes (HTTP 403 Forbidden ante SITE_MISMATCH).
 * 7. Pruebas HTTP en vivo sobre el servidor web local en 127.0.0.1:8000.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - CreateIncidentEndpointTest (T-22)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Limpiar todas las incidencias previas para aislamiento del test
$pdo->exec("DELETE FROM incident_history");
$pdo->exec("DELETE FROM incidents");

// Asegurar semillas limpias
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$authService = new AuthService($locationRepo);

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

// 1. Obtener sedes y máquinas de prueba
$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$location2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$assert("1. Sede SEDE-BCN-01 encontrada", $location1 !== null);
$assert("2. Sede SEDE-BCN-02 encontrada", $location2 !== null);

if ($location1 === null || $location2 === null) {
    echo "ERROR: No se encontraron las sedes de prueba.\n";
    exit(1);
}

// Máquinas de SEDE-BCN-01
$machinesLoc1 = $machineRepo->findActiveByLocationId($location1->getId());
$perishableMachine = null;
$hotDrinksMachine = null;
foreach ($machinesLoc1 as $m) {
    if ($m->getMachineType()->value === 'PERISHABLE_FOOD') {
        $perishableMachine = $m;
    } elseif ($m->getMachineType()->value === 'HOT_DRINKS') {
        $hotDrinksMachine = $m;
    }
}

// Máquina de SEDE-BCN-02
$machinesLoc2 = $machineRepo->findActiveByLocationId($location2->getId());
$loc2Machine = $machinesLoc2[0] ?? null;

$assert("3. Máquina perecedera (PERISHABLE_FOOD) encontrada en SEDE-BCN-01", $perishableMachine !== null);
$assert("4. Máquina de bebidas calientes (HOT_DRINKS) encontrada en SEDE-BCN-01", $hotDrinksMachine !== null);
$assert("5. Máquina de SEDE-BCN-02 encontrada", $loc2Machine !== null);

$tokenLoc1 = $authService->generateSiteToken($location1);

// =========================================================================
// CASO 1: Creación exitosa en máquina perecedera (Cálculo CRITICAL - EARS 3.2)
// =========================================================================
echo "\n--- Caso 1: Creación de incidencia con urgencia automática CRITICAL (EARS 3.2) ---\n";

$req1 = new Request(
    method: 'POST',
    path: '/api/incidents',
    queryParams: [],
    parsedBody: [
        'machine_id' => $perishableMachine->getId(),
        'category' => 'TEMPERATURE_COLD',
        'description' => 'La máquina pierde temperatura y los sándwiches y ensaladas están calientes.',
        'reporter_name' => 'Carla Gómez',
        'reporter_phone' => '655123456',
        'retained_money_amount' => '4.50',
    ],
    headers: [
        'Authorization' => "Bearer {$tokenLoc1}",
        'Content-Type' => 'application/x-www-form-urlencoded',
    ]
);

$res1 = $router->dispatch($req1);
$assert("1.1 Endpoint responde HTTP 201 Created", $res1->getStatusCode() === 201, "Status obtenido: {$res1->getStatusCode()}");
$body1 = $res1->getDecodedBody();

$assert("1.2 Envolvente contiene success => true", ($body1['success'] ?? false) === true);
$assert("1.3 Mensaje descriptivo presente", ($body1['message'] ?? '') === 'Incidencia registrada con éxito');
$data1 = $body1['data'] ?? [];

$assert("1.4 Urgencia calculada automáticamente como CRITICAL", ($data1['urgency'] ?? '') === 'CRITICAL');
$assert("1.5 Estado inicial es REGISTERED", ($data1['status'] ?? '') === 'REGISTERED');
$assert("1.6 Código de ticket generado con prefijo INC-", str_starts_with((string)($data1['ticket_code'] ?? ''), 'INC-'));
$assert("1.7 Sede asignada correctamente", (int)($data1['location_id'] ?? 0) === $location1->getId());
$assert("1.8 Dinero retenido registrado correctamente (4.5)", (float)($data1['retained_money_amount'] ?? 0) === 4.5);

// Verificar persistencia en base de datos
$createdIncidentId = (int)($data1['id'] ?? 0);
$dbStmt = $pdo->prepare("SELECT * FROM incidents WHERE id = :id");
$dbStmt->execute([':id' => $createdIncidentId]);
$dbRow = $dbStmt->fetch();
$assert("1.9 Registro presente en tabla incidents", $dbRow !== false);
$assert("1.10 is_active_ticket = 1", (int)($dbRow['is_active_ticket'] ?? 0) === 1);

$histStmt = $pdo->prepare("SELECT * FROM incident_history WHERE incident_id = :id");
$histStmt->execute([':id' => $createdIncidentId]);
$histRow = $histStmt->fetch();
$assert("1.11 Historial inicial registrado atómicamente", $histRow !== false && ($histRow['to_status'] ?? '') === 'REGISTERED');

// =========================================================================
// CASO 2: Creación exitosa con subida de fotografía multipart/form-data
// =========================================================================
echo "\n--- Caso 2: Creación con adjunto fotográfico multipart/form-data (RNF-05) ---\n";

// Crear imagen JPEG binaria válida (sin requerir extensión GD)
$validJpgBytes = hex2bin('ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffda0008010100003f00d2cf20ffd9');
$tempJpg = tempnam(sys_get_temp_dir(), 'test_img_') . '.jpg';
file_put_contents($tempJpg, $validJpgBytes);

$filePayload = [
    'photo' => [
        'name' => 'evidencia_averia.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $tempJpg,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tempJpg),
    ]
];

$req2 = new Request(
    method: 'POST',
    path: '/api/incidents',
    queryParams: [],
    parsedBody: [
        'machine_id' => $hotDrinksMachine->getId(),
        'category' => 'TEMPERATURE_COLD',
        'description' => 'La máquina de café no calienta el agua correctamente.',
        'reporter_name' => 'Marc Soler',
    ],
    headers: [
        'Authorization' => "Bearer {$tokenLoc1}",
        'Content-Type' => 'multipart/form-data',
    ],
    files: $filePayload
);

$res2 = $router->dispatch($req2);
$assert("2.1 Creación con imagen responde HTTP 201 Created", $res2->getStatusCode() === 201, "Status obtenido: {$res2->getStatusCode()}");
$body2 = $res2->getDecodedBody();
$data2 = $body2['data'] ?? [];

$assert("2.2 Urgencia para HOT_DRINKS + TEMPERATURE_COLD calculada como MEDIUM", ($data2['urgency'] ?? '') === 'MEDIUM');
$photoPath = (string)($data2['photo_path'] ?? '');
$assert("2.3 photo_path guardado en el expediente (/uploads/...)", str_starts_with($photoPath, '/uploads/') && str_ends_with($photoPath, '.jpg'));

$absoluteDiskPath = dirname(__DIR__, 2) . '/public' . $photoPath;
$assert("2.4 Archivo físico de imagen existe en public/uploads/", file_exists($absoluteDiskPath));

// Limpiar archivos temporales
if (file_exists($tempJpg)) {
    unlink($tempJpg);
}
if ($photoPath !== '' && file_exists($absoluteDiskPath) && is_file($absoluteDiskPath)) {
    unlink($absoluteDiskPath);
}

// =========================================================================
// CASO 3: Rechazo de duplicado en máquina con ticket activo (409 Conflict)
// =========================================================================
echo "\n--- Caso 3: Rechazo de duplicado con HTTP 409 Conflict (EARS 2.1) ---\n";

$req3 = new Request(
    method: 'POST',
    path: '/api/incidents',
    queryParams: [],
    parsedBody: [
        'machine_id' => $perishableMachine->getId(), // Ya tiene el ticket del Caso 1
        'category' => 'PAYMENT_SYSTEM',
        'description' => 'Intento de abrir un segundo ticket concurrente.',
    ],
    headers: [
        'Authorization' => "Bearer {$tokenLoc1}",
    ]
);

$res3 = $router->dispatch($req3);
$assert("3.1 Solicitud duplicada responde HTTP 409 Conflict", $res3->getStatusCode() === 409, "Status: {$res3->getStatusCode()}");
$body3 = $res3->getDecodedBody();

$assert("3.2 success es false", ($body3['success'] ?? true) === false);
$assert("3.3 Código de error MACHINE_HAS_ACTIVE_INCIDENT", ($body3['error']['code'] ?? '') === 'MACHINE_HAS_ACTIVE_INCIDENT');
$assert("3.4 Detalles incluyen ticket_code activo", !empty($body3['error']['details']['ticket_code']));
$assert("3.5 Detalles incluyen ticket_status REGISTERED", ($body3['error']['details']['ticket_status'] ?? '') === 'REGISTERED');

// =========================================================================
// CASO 4: Rechazo en ventana de garantía de 48 horas (409 Conflict - EARS 2.2)
// =========================================================================
echo "\n--- Caso 4: Rechazo en ventana de garantía de 48 horas (EARS 2.2) ---\n";

// Simular resolución de la máquina de bebidas calientes
$pdo->prepare("
    UPDATE incidents 
    SET status = 'RESOLVED', resolved_at = NOW(), is_active_ticket = 1
    WHERE id = :id
")->execute([':id' => $data2['id']]);

$req4 = new Request(
    method: 'POST',
    path: '/api/incidents',
    queryParams: [],
    parsedBody: [
        'machine_id' => $hotDrinksMachine->getId(),
        'category' => 'PRODUCT_JAM',
        'description' => 'Avería ocurrida a las pocas horas de la resolución.',
    ],
    headers: [
        'Authorization' => "Bearer {$tokenLoc1}",
    ]
);

$res4 = $router->dispatch($req4);
$assert("4.1 Solicitud en garantía responde HTTP 409 Conflict", $res4->getStatusCode() === 409, "Status: {$res4->getStatusCode()}");
$body4 = $res4->getDecodedBody();

$assert("4.2 Código de error MACHINE_IN_WARRANTY", ($body4['error']['code'] ?? '') === 'MACHINE_IN_WARRANTY');
$assert("4.3 Mensaje instruye reabrir incidencia", str_contains((string)($body4['error']['message'] ?? ''), "Reabrir incidencia"));

// =========================================================================
// CASO 5: Validaciones de entrada (400 Bad Request / 422 Unprocessable)
// =========================================================================
echo "\n--- Caso 5: Validaciones de datos de entrada (400 / 422) ---\n";

// 5.1 Falta machine_id
$req5a = new Request('POST', '/api/incidents', [], ['category' => 'OTHER', 'description' => 'Sin maquina'], ['Authorization' => "Bearer {$tokenLoc1}"]);
$res5a = $router->dispatch($req5a);
$assert("5.1 Falta machine_id => HTTP 400 MISSING_MACHINE_ID", $res5a->getStatusCode() === 400 && ($res5a->getDecodedBody()['error']['code'] ?? '') === 'MISSING_MACHINE_ID');

// 5.2 Máquina no encontrada
$req5b = new Request('POST', '/api/incidents', [], ['machine_id' => 99999, 'category' => 'OTHER', 'description' => 'Maquina fantasma'], ['Authorization' => "Bearer {$tokenLoc1}"]);
$res5b = $router->dispatch($req5b);
$assert("5.2 Máquina inexistente => HTTP 404 MACHINE_NOT_FOUND", $res5b->getStatusCode() === 404 && ($res5b->getDecodedBody()['error']['code'] ?? '') === 'MACHINE_NOT_FOUND');

// 5.3 Categoría inválida
$req5c = new Request('POST', '/api/incidents', [], ['machine_id' => $perishableMachine->getId(), 'category' => 'INVENTADA', 'description' => 'Categoria falsa'], ['Authorization' => "Bearer {$tokenLoc1}"]);
$res5c = $router->dispatch($req5c);
$assert("5.3 Categoría no permitida => HTTP 400 INVALID_CATEGORY", $res5c->getStatusCode() === 400 && ($res5c->getDecodedBody()['error']['code'] ?? '') === 'INVALID_CATEGORY');

// 5.4 Descripción vacía
$req5d = new Request('POST', '/api/incidents', [], ['machine_id' => $perishableMachine->getId(), 'category' => 'OTHER', 'description' => '   '], ['Authorization' => "Bearer {$tokenLoc1}"]);
$res5d = $router->dispatch($req5d);
$assert("5.4 Descripción vacía => HTTP 400 MISSING_DESCRIPTION", $res5d->getStatusCode() === 400 && ($res5d->getDecodedBody()['error']['code'] ?? '') === 'MISSING_DESCRIPTION');

// 5.5 Descripción demasiado corta (< 5 caracteres)
$req5e = new Request('POST', '/api/incidents', [], ['machine_id' => $perishableMachine->getId(), 'category' => 'OTHER', 'description' => 'Aver'], ['Authorization' => "Bearer {$tokenLoc1}"]);
$res5e = $router->dispatch($req5e);
$assert("5.5 Descripción < 5 caracteres => HTTP 422 DESCRIPTION_TOO_SHORT", $res5e->getStatusCode() === 422 && ($res5e->getDecodedBody()['error']['code'] ?? '') === 'DESCRIPTION_TOO_SHORT');

// 5.6 Dinero retenido negativo
$req5f = new Request('POST', '/api/incidents', [], ['machine_id' => $perishableMachine->getId(), 'category' => 'OTHER', 'description' => 'Error de dinero', 'retained_money_amount' => '-2.50'], ['Authorization' => "Bearer {$tokenLoc1}"]);
$res5f = $router->dispatch($req5f);
$assert("5.6 Dinero negativo => HTTP 422 INVALID_MONEY_AMOUNT", $res5f->getStatusCode() === 422 && ($res5f->getDecodedBody()['error']['code'] ?? '') === 'INVALID_MONEY_AMOUNT');

// 5.7 Archivo no válido (EARS 3.9: preservación de datos de texto)
$fakeFile = tempnam(sys_get_temp_dir(), 'fake_') . '.txt';
file_put_contents($fakeFile, "Texto plano simulando ser imagen");
$req5g = new Request(
    method: 'POST',
    path: '/api/incidents',
    queryParams: [],
    parsedBody: [
        'machine_id' => $perishableMachine->getId(),
        'category' => 'OTHER',
        'description' => 'Descripción preservada ante fallo de archivo',
        'reporter_name' => 'Pedro Sánchez',
    ],
    headers: ['Authorization' => "Bearer {$tokenLoc1}"],
    files: [
        'photo' => [
            'name' => 'malicioso.php',
            'type' => 'text/plain',
            'tmp_name' => $fakeFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($fakeFile),
        ]
    ]
);
$res5g = $router->dispatch($req5g);
$body5g = $res5g->getDecodedBody();
$assert("5.7 Formato de archivo inválido => HTTP 422 INVALID_FILE_TYPE", $res5g->getStatusCode() === 422 && ($body5g['error']['code'] ?? '') === 'INVALID_FILE_TYPE');
$assert(
    "5.8 EARS 3.9: Datos del formulario preservados en el envelope de error",
    ($body5g['error']['details']['form_data']['description'] ?? '') === 'Descripción preservada ante fallo de archivo'
);
if (file_exists($fakeFile)) {
    unlink($fakeFile);
}

// =========================================================================
// CASO 6: Segregación y aislamiento de sedes (403 Forbidden)
// =========================================================================
echo "\n--- Caso 6: Segregación de sedes (HTTP 403 Forbidden) ---\n";

$req6 = new Request(
    method: 'POST',
    path: '/api/incidents',
    queryParams: [],
    parsedBody: [
        'machine_id' => $loc2Machine->getId(), // Máquina de SEDE-BCN-02 intentada por SEDE-BCN-01
        'category' => 'PAYMENT_SYSTEM',
        'description' => 'Intento cruzado no autorizado de reporte entre sedes distintas.',
    ],
    headers: [
        'Authorization' => "Bearer {$tokenLoc1}",
    ]
);

$res6 = $router->dispatch($req6);
$assert("6.1 Máquina de otra sede responde HTTP 403 Forbidden", $res6->getStatusCode() === 403);
$body6 = $res6->getDecodedBody();
$assert("6.2 Código de error SITE_MISMATCH", ($body6['error']['code'] ?? '') === 'SITE_MISMATCH');

// =========================================================================
// CASO 7: Prueba HTTP real contra el servidor local en ejecución (127.0.0.1:8000)
// =========================================================================
echo "\n--- Caso 7: Prueba HTTP real contra servidor en 127.0.0.1:8000 ---\n";

$serverAvailable = false;
$chCheck = @curl_init('http://127.0.0.1:8000/api/health');
if ($chCheck !== false) {
    curl_setopt($chCheck, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chCheck, CURLOPT_TIMEOUT, 2);
    $healthRes = curl_exec($chCheck);
    $httpCode = curl_getinfo($chCheck, CURLINFO_HTTP_CODE);
    curl_close($chCheck);
    if ($httpCode === 200) {
        $serverAvailable = true;
    }
}

if ($serverAvailable) {
    // 7.1 Limpiar incidencias de la máquina de SEDE-BCN-02
    $pdo->prepare("DELETE FROM incident_history WHERE incident_id IN (SELECT id FROM incidents WHERE machine_id = :mid)")
        ->execute([':mid' => $loc2Machine->getId()]);
    $pdo->prepare("DELETE FROM incidents WHERE machine_id = :mid")
        ->execute([':mid' => $loc2Machine->getId()]);

    $tokenLoc2 = $authService->generateSiteToken($location2);

    $postData = [
        'machine_id' => (string)$loc2Machine->getId(),
        'category' => 'PAYMENT_SYSTEM',
        'description' => 'Test HTTP Real: El datáfono no acepta pagos contactless.',
        'reporter_name' => 'Agente Remoto',
        'reporter_phone' => '677889900',
    ];

    $ch = curl_init('http://127.0.0.1:8000/api/incidents');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData); // Envía multipart/form-data automáticamente
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$tokenLoc2}",
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $responseStr = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $assert("7.1 Llamada HTTP real con multipart responde HTTP 201 Created", $statusCode === 201, "Status obtenido: {$statusCode}, Body: {$responseStr}");
    $httpDecoded = json_decode((string)$responseStr, true);
    $assert("7.2 HTTP real devuelve success => true", ($httpDecoded['success'] ?? false) === true);
    $assert("7.3 Urgencia PAYMENT_SYSTEM calculada como HIGH", ($httpDecoded['data']['urgency'] ?? '') === 'HIGH');

    // 7.4 Intento duplicado sobre la misma máquina por HTTP real
    $chDup = curl_init('http://127.0.0.1:8000/api/incidents');
    curl_setopt($chDup, CURLOPT_POST, true);
    curl_setopt($chDup, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($chDup, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$tokenLoc2}",
    ]);
    curl_setopt($chDup, CURLOPT_RETURNTRANSFER, true);
    $dupRes = curl_exec($chDup);
    $dupStatus = curl_getinfo($chDup, CURLINFO_HTTP_CODE);
    curl_close($chDup);

    $assert("7.4 Intento duplicado por HTTP real responde HTTP 409 Conflict", $dupStatus === 409, "Status obtenido: {$dupStatus}");
    $dupDecoded = json_decode((string)$dupRes, true);
    $assert("7.5 HTTP real 409 error code es MACHINE_HAS_ACTIVE_INCIDENT", ($dupDecoded['error']['code'] ?? '') === 'MACHINE_HAS_ACTIVE_INCIDENT');
} else {
    echo "  [SKIP] Servidor local no disponible en 127.0.0.1:8000 para Caso 7.\n";
}

// Resumen final
echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE (0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
