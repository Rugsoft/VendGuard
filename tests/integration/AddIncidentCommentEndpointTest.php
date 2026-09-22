<?php

declare(strict_types=1);

/**
 * AddIncidentCommentEndpointTest
 * 
 * Test de Integración para el Endpoint de Añadir Comentarios/Evidencias a Ticket Activo (Tarea T-23).
 * Requisitos: RF-02 (EARS 2.3), Edge Case 6 del MVP.
 * 
 * Valida que:
 * 1. POST /api/incidents/{ticket_code}/comments añade una entrada a la bitácora incident_comments (HTTP 201).
 * 2. CONDICIÓN CRÍTICA "Hecho cuando:": La fotografía original del ticket inicial (incidents.photo_path)
 *    PERMANECE INTACTA Y NUNCA SE SOBREESCRIBE al adjuntar una nueva foto en los comentarios.
 * 3. Admite subidas de evidencias fotográficas sucesivas multipart/form-data (RNF-05).
 * 4. Valida entradas (400 MISSING_COMMENT_TEXT, 422 COMMENT_TOO_SHORT, 404 INCIDENT_NOT_FOUND).
 * 5. Rechaza comentarios sobre tickets en estado terminal CLOSED o CANCELLED (422 INCIDENT_NOT_ACTIVE).
 * 6. Garantiza el aislamiento de sedes (403 SITE_MISMATCH).
 * 7. Ejecuta llamadas HTTP reales contra el servidor local en 127.0.0.1:8000.
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
echo " VendGuard: Test de Integración - AddIncidentCommentEndpointTest (T-23)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Limpiar bitácoras e incidencias previas para aislamiento del test
$pdo->exec("DELETE FROM incident_comments");
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

// 1. Localizar sede y máquina para la prueba
$location1 = $locationRepo->findBySiteCode('SEDE-BCN-01');
$location2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$assert("1. Sedes cargadas correctamente", $location1 !== null && $location2 !== null);

if ($location1 === null || $location2 === null) {
    echo "ERROR: Sedes no disponibles.\n";
    exit(1);
}

$machines = $machineRepo->findActiveByLocationId($location1->getId());
$targetMachine = $machines[0] ?? null;
$assert("2. Máquina objetivo en SEDE-BCN-01 encontrada", $targetMachine !== null);

$tokenLoc1 = $authService->generateSiteToken($location1);
$tokenLoc2 = $authService->generateSiteToken($location2);

// 2. Crear una incidencia inicial con fotografía original
$originalPhotoPath = '/uploads/evidencia_inicial_original.jpg';
$initialIncident = new Incident(
    id: null,
    ticketCode: 'INC-2026-T2301',
    machineId: $targetMachine->getId(),
    locationId: $location1->getId(),
    category: IncidentCategory::TEMPERATURE_COLD,
    description: 'Aviso inicial: máquina de alimentos perecederos sin refrigeración.',
    urgency: UrgencyLevel::CRITICAL,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    reporterName: 'Carlos Recepción',
    reporterPhone: '611222333',
    photoPath: $originalPhotoPath
);

$createdIncident = $incidentRepo->create($initialIncident, null, 'Incidencia inicial con fotografía original');
$assert("3. Incidencia inicial creada con éxito", $createdIncident->getId() > 0);
$assert("4. Fotografía inicial registrada en el ticket", $createdIncident->getPhotoPath() === $originalPhotoPath);

// =========================================================================
// CASO 1: Anexar comentario de texto (sin foto) a ticket activo (EARS 2.3)
// =========================================================================
echo "\n--- Caso 1: Anexar comentario de texto a ticket activo ---\n";

$req1 = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2301/comments',
    queryParams: [],
    parsedBody: [
        'author_name' => 'Marta Conserjería',
        'comment_text' => 'La máquina ha empezado a emitir un pitido continuo con error E04.',
    ],
    headers: [
        'Authorization' => "Bearer {$tokenLoc1}",
        'Content-Type' => 'application/x-www-form-urlencoded',
    ]
);

$res1 = $router->dispatch($req1);
$assert("1.1 Endpoint responde HTTP 201 Created", $res1->getStatusCode() === 201, "Status: {$res1->getStatusCode()}");
$body1 = $res1->getDecodedBody();
$assert("1.2 Envolvente contiene success => true", ($body1['success'] ?? false) === true);
$data1 = $body1['data'] ?? [];

$assert("1.3 ID de comentario generado autoincremental", (int)($data1['id'] ?? 0) > 0);
$assert("1.4 incident_id coincide con el ticket", (int)($data1['incident_id'] ?? 0) === $createdIncident->getId());
$assert("1.5 ticket_code coincide con INC-2026-T2301", ($data1['ticket_code'] ?? '') === 'INC-2026-T2301');
$assert("1.6 author_name registrado correctamente", ($data1['author_name'] ?? '') === 'Marta Conserjería');
$assert("1.7 comment_text registrado", str_contains((string)($data1['comment_text'] ?? ''), 'pitido continuo'));
$assert("1.8 photo_path es null en comentario de texto", ($data1['photo_path'] ?? null) === null);

// CONDICIÓN CRÍTICA "Hecho cuando:": Verificar que incidents.photo_path sigue intacta
$checkStmt1 = $pdo->prepare("SELECT photo_path FROM incidents WHERE id = :id");
$checkStmt1->execute([':id' => $createdIncident->getId()]);
$currentPhoto1 = $checkStmt1->fetchColumn();
$assert("1.9 [CONDICIÓN HECHO CUANDO] Foto original en incidents NO ha sido sobreescrita", $currentPhoto1 === $originalPhotoPath);

// =========================================================================
// CASO 2: Anexar comentario CON nueva fotografía adicional (multipart/form-data)
// =========================================================================
echo "\n--- Caso 2: Anexar comentario con fotografía adicional multipart/form-data ---\n";

// Crear imagen temporal binaria válida
$validJpgBytes = hex2bin('ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffda0008010100003f00d2cf20ffd9');
$tempJpg = tempnam(sys_get_temp_dir(), 'comment_img_') . '.jpg';
file_put_contents($tempJpg, $validJpgBytes);

$filePayload = [
    'photo' => [
        'name' => 'pantalla_error_e04.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $tempJpg,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tempJpg),
    ]
];

$req2 = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2301/comments',
    queryParams: [],
    parsedBody: [
        'author_name' => 'Marta Conserjería',
        'comment_text' => 'Adjunto fotografía adicional del display con el código de error.',
    ],
    headers: [
        'Authorization' => "Bearer {$tokenLoc1}",
        'Content-Type' => 'multipart/form-data',
    ],
    files: $filePayload
);

$res2 = $router->dispatch($req2);
$assert("2.1 Anexar comentario con foto responde HTTP 201 Created", $res2->getStatusCode() === 201, "Status: {$res2->getStatusCode()}");
$body2 = $res2->getDecodedBody();
$data2 = $body2['data'] ?? [];

$commentPhotoPath = (string)($data2['photo_path'] ?? '');
$assert("2.2 photo_path del comentario guardado en /uploads/...", str_starts_with($commentPhotoPath, '/uploads/') && str_ends_with($commentPhotoPath, '.jpg'));
$assert("2.3 photo_path del comentario es DIFERENTE a la foto original del ticket", $commentPhotoPath !== $originalPhotoPath);

$absoluteDiskPath = dirname(__DIR__, 2) . '/public' . $commentPhotoPath;
$assert("2.4 Archivo físico de la evidencia adicional existe en public/uploads/", file_exists($absoluteDiskPath));

// CONDICIÓN CRÍTICA "Hecho cuando:": Verificar de nuevo que incidents.photo_path SIGUE INTACTA
$checkStmt2 = $pdo->prepare("SELECT photo_path FROM incidents WHERE id = :id");
$checkStmt2->execute([':id' => $createdIncident->getId()]);
$currentPhoto2 = $checkStmt2->fetchColumn();
$assert("2.5 [CONDICIÓN HECHO CUANDO] Foto original del ticket sigue siendo la inicial tras añadir foto en comentario", $currentPhoto2 === $originalPhotoPath);

// Limpiar archivos temporales
if (file_exists($tempJpg)) {
    unlink($tempJpg);
}
if ($commentPhotoPath !== '' && file_exists($absoluteDiskPath) && is_file($absoluteDiskPath)) {
    unlink($absoluteDiskPath);
}

// =========================================================================
// CASO 3: Consulta de la bitácora pública de comentarios (GET)
// =========================================================================
echo "\n--- Caso 3: Consulta de comentarios asociados al ticket ---\n";

$req3 = new Request(
    method: 'GET',
    path: '/api/incidents/INC-2026-T2301/comments',
    queryParams: [],
    parsedBody: [],
    headers: [
        'Authorization' => "Bearer {$tokenLoc1}",
    ]
);

$res3 = $router->dispatch($req3);
$assert("3.1 GET comentarios responde HTTP 200 OK", $res3->getStatusCode() === 200);
$body3 = $res3->getDecodedBody();
$commentsList = $body3['data'] ?? [];
$assert("3.2 Se listan exactamente 2 comentarios añadidos", count($commentsList) === 2);
$assert("3.3 Primer comentario es el de texto", str_contains((string)($commentsList[0]['comment_text'] ?? ''), 'pitido continuo'));
$assert("3.4 Segundo comentario contiene la fotografía adicional", !empty($commentsList[1]['photo_path']));

// =========================================================================
// CASO 4: Validaciones de entrada (400 / 404 / 422)
// =========================================================================
echo "\n--- Caso 4: Validaciones de entrada y manejo de errores ---\n";

// 4.1 Ticket inexistente -> 404
$req4a = new Request('POST', '/api/incidents/INC-INEXISTENTE/comments', [], ['comment_text' => 'Comentario a nadie'], ['Authorization' => "Bearer {$tokenLoc1}"]);
$res4a = $router->dispatch($req4a);
$assert("4.1 Ticket no encontrado => HTTP 404 INCIDENT_NOT_FOUND", $res4a->getStatusCode() === 404 && ($res4a->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND');

// 4.2 Falta comment_text -> 400
$req4b = new Request('POST', '/api/incidents/INC-2026-T2301/comments', [], ['author_name' => 'Pepe'], ['Authorization' => "Bearer {$tokenLoc1}"]);
$res4b = $router->dispatch($req4b);
$assert("4.2 Falta comment_text => HTTP 400 MISSING_COMMENT_TEXT", $res4b->getStatusCode() === 400 && ($res4b->getDecodedBody()['error']['code'] ?? '') === 'MISSING_COMMENT_TEXT');

// 4.3 comment_text demasiado corto (< 3 caracteres) -> 422
$req4c = new Request('POST', '/api/incidents/INC-2026-T2301/comments', [], ['comment_text' => 'Ok'], ['Authorization' => "Bearer {$tokenLoc1}"]);
$res4c = $router->dispatch($req4c);
$assert("4.3 Comentario < 3 caracteres => HTTP 422 COMMENT_TOO_SHORT", $res4c->getStatusCode() === 422 && ($res4c->getDecodedBody()['error']['code'] ?? '') === 'COMMENT_TOO_SHORT');

// 4.4 Archivo no válido -> 422 INVALID_FILE_TYPE con datos preservados
$fakeFile = tempnam(sys_get_temp_dir(), 'fake_') . '.txt';
file_put_contents($fakeFile, "No soy una imagen");
$req4d = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2301/comments',
    queryParams: [],
    parsedBody: [
        'comment_text' => 'Texto preservado ante fallo de subida',
        'author_name' => 'Juan Informador',
    ],
    headers: ['Authorization' => "Bearer {$tokenLoc1}"],
    files: [
        'photo' => [
            'name' => 'falso.exe',
            'type' => 'text/plain',
            'tmp_name' => $fakeFile,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($fakeFile),
        ]
    ]
);
$res4d = $router->dispatch($req4d);
$body4d = $res4d->getDecodedBody();
$assert("4.4 Tipo de archivo no gráfico => HTTP 422 INVALID_FILE_TYPE", $res4d->getStatusCode() === 422 && ($body4d['error']['code'] ?? '') === 'INVALID_FILE_TYPE');
$assert("4.5 Datos de formulario preservados en error", ($body4d['error']['details']['form_data']['comment_text'] ?? '') === 'Texto preservado ante fallo de subida');
if (file_exists($fakeFile)) {
    unlink($fakeFile);
}

// =========================================================================
// CASO 5: Bloqueo de comentarios en ticket cerrado o cancelado (422)
// =========================================================================
echo "\n--- Caso 5: Bloqueo en tickets cerrados / cancelados (422 INCIDENT_NOT_ACTIVE) ---\n";

$pdo->prepare("UPDATE incidents SET status = 'CLOSED', is_active_ticket = NULL WHERE id = :id")
    ->execute([':id' => $createdIncident->getId()]);

$req5 = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2301/comments',
    queryParams: [],
    parsedBody: ['comment_text' => 'Intentando comentar en ticket cerrado.'],
    headers: ['Authorization' => "Bearer {$tokenLoc1}"]
);
$res5 = $router->dispatch($req5);
$assert("5.1 Ticket CLOSED no admite comentarios => HTTP 422 INCIDENT_NOT_ACTIVE", $res5->getStatusCode() === 422 && ($res5->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_ACTIVE');

// =========================================================================
// CASO 6: Segregación de sedes (403 Forbidden - SITE_MISMATCH)
// =========================================================================
echo "\n--- Caso 6: Segregación de sedes (HTTP 403 Forbidden) ---\n";

// Reactivar la incidencia para probar la restricción de sede
$pdo->prepare("UPDATE incidents SET status = 'REGISTERED', is_active_ticket = 1 WHERE id = :id")
    ->execute([':id' => $createdIncident->getId()]);

// Petición con credenciales de SEDE-BCN-02 a un ticket de SEDE-BCN-01
$req6 = new Request(
    method: 'POST',
    path: '/api/incidents/INC-2026-T2301/comments',
    queryParams: [],
    parsedBody: ['comment_text' => 'Intento no autorizado de otra sede.'],
    headers: ['Authorization' => "Bearer {$tokenLoc2}"]
);
$res6 = $router->dispatch($req6);
$assert("6.1 Comentario de otra sede responde HTTP 403 Forbidden", $res6->getStatusCode() === 403);
$assert("6.2 Código de error es SITE_MISMATCH", ($res6->getDecodedBody()['error']['code'] ?? '') === 'SITE_MISMATCH');

// =========================================================================
// CASO 7: Prueba HTTP real contra el servidor local (127.0.0.1:8000)
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
    $postData = [
        'author_name' => 'Operador Remoto',
        'comment_text' => 'Comentario enviado por HTTP real al servidor local en vivo.',
    ];

    $ch = curl_init('http://127.0.0.1:8000/api/incidents/INC-2026-T2301/comments');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$tokenLoc1}",
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $responseStr = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $assert("7.1 Llamada HTTP real responde HTTP 201 Created", $statusCode === 201, "Status: {$statusCode}, Body: {$responseStr}");
    $httpDecoded = json_decode((string)$responseStr, true);
    $assert("7.2 HTTP real devuelve success => true", ($httpDecoded['success'] ?? false) === true);
    $assert("7.3 Comentario persistido con autor 'Operador Remoto'", ($httpDecoded['data']['author_name'] ?? '') === 'Operador Remoto');

    // Comprobar una vez más la condición "Hecho cuando:" en BD tras la llamada HTTP real
    $checkStmtReal = $pdo->prepare("SELECT photo_path FROM incidents WHERE ticket_code = 'INC-2026-T2301'");
    $checkStmtReal->execute();
    $photoAfterReal = $checkStmtReal->fetchColumn();
    $assert("7.4 [CONDICIÓN HECHO CUANDO] Tras llamada HTTP real, incidents.photo_path permanece intacto", $photoAfterReal === $originalPhotoPath);
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
