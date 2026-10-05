<?php

declare(strict_types=1);

/**
 * VendGuard - CoordinatorIncidentDetailConstitutionalTest (T-IDM-18)
 *
 * Constitutional shield and data-segregation suite for the incident detail module
 * (spec 09), exercising the real AppRouter over real MariaDB:
 *
 * 1. Art. III.1 - Data inviolability: zero `DELETE FROM` statements in the module
 *    production sources (static scan) plus the physical hard-delete guard: a real
 *    DELETE against `refund_requests` is rejected by the BEFORE DELETE trigger of
 *    migration 010 and the row survives untouched. The operational discard keeps the
 *    incident row on disk (soft delete, Art. III.2).
 * 2. Art. V.1 - Justified closure: discard reasons under 20 real characters
 *    (multibyte-safe after trim) are rejected with 422 CANCELLATION_REASON_TOO_SHORT
 *    without persisting anything, while the exact threshold is accepted.
 * 3. Art. V.4 / RNF-05 - Irrevocable masking: the enriched detail of an incident with
 *    a linked refund exposes only masked contact/payment data (first digit + last
 *    three of the Bizum phone, country + last four of the IBAN); the raw phone, the
 *    raw IBAN and the claimant contact never reach the JSON body, and no raw key
 *    (`bizum_phone`, `iban`) is part of the refund block.
 * 4. Art. V.4 - Access denial: authenticated technicians receive 403 FORBIDDEN on
 *    every module endpoint (detail, assign, cancel, comments) before the controller
 *    reads anything; site sessions cannot even cross the internal authentication and
 *    are answered 401 UNAUTHORIZED (stricter denial, same segregation guarantee as
 *    the refund constitutional precedent SiteManagerRefundDataSegregationTest).
 *
 * Dogma Vanilla: PHP 8.2 strict types, PDO against the real database, no external libraries.
 * Dualismo Linguistico: identifiers in English, test titles and messages in Spanish.
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
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test Constitucional - CoordinatorIncidentDetailConstitutionalTest (T-IDM-18)\n";
echo "======================================================================\n\n";

// ─── Bootstrap ───────────────────────────────────────────────────────────────
$pdo = ConnectionFactory::getConnection();

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router       = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);

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

// ─── Usuarios semilla y tokens de las tres identidades ───────────────────────
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician  = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$location1   = $locationRepo->findBySiteCode('SEDE-BCN-01');
$location2   = $locationRepo->findBySiteCode('SEDE-BCN-02');

$assert("0. Semillas: coordinador, técnico y sedes disponibles", $coordinator !== null && $technician !== null && $location1 !== null && $location2 !== null);
if ($coordinator === null || $technician === null || $location1 === null || $location2 === null) {
    echo "ERROR FATAL: semillas no disponibles.\n";
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken  = $authService->generateInternalToken($technician);
$siteToken        = $authService->generateSiteToken($location1);
$coordinatorId    = $coordinator->getId();
$authHdr          = ['Authorization' => "Bearer {$coordinatorToken}"];

$machines1 = $machineRepo->findActiveByLocationId($location1->getId());
$machines2 = $machineRepo->findActiveByLocationId($location2->getId());
$mach1 = $machines1[0];
$mach2 = $machines1[1];
$mach3 = $machines2[0];

// ─── Helper: crea incidencia usando el repositorio ────────────────────────────
$makeIncident = function (
    int $machineId,
    int $locationId,
    IncidentStatus $status = IncidentStatus::REGISTERED,
    UrgencyLevel $urgency = UrgencyLevel::CRITICAL
) use ($incidentRepo, $pdo): Incident {
    // Liberar ticket activo previo si existiese (candado de una avería por máquina)
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")->execute([$machineId]);

    // Sufijo en mayúsculas: la resolución de código de ticket normaliza con strtoupper
    $code = 'T18-' . strtoupper(uniqid());
    $inc = new Incident(
        id: null,
        ticketCode: $code,
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::OTHER,
        description: 'Expediente de prueba del blindaje constitucional del módulo de detalle.',
        urgency: $urgency,
        status: $status,
        assignedTechnicianId: null,
        createdAt: null
    );
    $created = $incidentRepo->create($inc);

    if ($status !== IncidentStatus::REGISTERED) {
        $pdo->prepare("UPDATE incidents SET status = :status WHERE id = :id")->execute([
            ':status' => $status->value,
            ':id'     => $created->getId(),
        ]);
        return $incidentRepo->findById($created->getId());
    }

    return $created;
};

// =========================================================================
// CASO 1: Art. III.1 - Inviolabilidad de datos (cero DELETE FROM)
// =========================================================================

echo "\n--- Caso 1: Inviolabilidad de datos (Art. III.1) ---\n";

// 1.1 Escaneo estático: ninguna fuente de producción del módulo contiene DELETE FROM.
$moduleSources = [
    __DIR__ . '/../../src/Presentation/Controller/CoordinatorController.php',
    __DIR__ . '/../../src/Application/Service/CoordinatorIncidentDetailService.php',
    __DIR__ . '/../../src/Application/DTO/CoordinatorIncidentDetailDto.php',
    __DIR__ . '/../../src/Infrastructure/Repository/PdoIncidentRepository.php',
    __DIR__ . '/../../src/Infrastructure/Repository/PdoRefundRequestRepository.php',
    __DIR__ . '/../../src/Infrastructure/Repository/PdoAuditLogRepository.php',
];
$allSourcesExist = true;
$scannedCount    = 0;
$violations      = [];
foreach ($moduleSources as $sourcePath) {
    if (!is_file($sourcePath)) {
        $allSourcesExist = false;
        continue;
    }
    $scannedCount++;
    if (preg_match('/DELETE\\s+FROM/i', (string)file_get_contents($sourcePath)) === 1) {
        $violations[] = basename($sourcePath);
    }
}
$assert(
    "1.1 Cero sentencias DELETE FROM en las {$scannedCount} fuentes del módulo (Art. III.1)",
    $allSourcesExist && $scannedCount === count($moduleSources) && $violations === [],
    'Ficheros con borrado físico: ' . implode(', ', $violations)
);

// 1.2 Guardia física real: el trigger BEFORE DELETE de la migración 010 rechaza el
// borrado físico de un expediente de reintegro (la única vía de salida es la válvula
// de purga @vendguard_purge, reservada al arnés de pruebas).
$incGuard = $makeIncident($mach1->getId(), $location1->getId());
$pdo->prepare(
    "INSERT INTO `refund_requests`
        (`incident_id`, `machine_id`, `location_id`, `claimant_name`, `claimant_contact`, `claimed_amount`,
         `product_attempted`, `compensation_method`, `bizum_phone`, `iban`, `status`, `tracking_token`)
     VALUES (:incident_id, :machine_id, :location_id, 'Ciudadano Anónimo', '600000000', 2.50,
             'Sándwich de pavo', 'BIZUM', :bizum_phone, :iban, 'REQUIRES_COORDINATOR_APPROVAL', :tracking_token)"
)->execute([
    ':incident_id'    => $incGuard->getId(),
    ':machine_id'     => $mach1->getId(),
    ':location_id'    => $location1->getId(),
    ':bizum_phone'    => '612345678',
    ':iban'           => 'ES9121000418450200051332',
    ':tracking_token' => 'tok-tidm18-' . $incGuard->getId(),
]);
$refundGuardId = (int)$pdo->lastInsertId();

$pdo->exec('SET @vendguard_purge = 0');
$deleteBlocked = false;
$deleteMessage = '';
try {
    $deletedRows = $pdo->exec("DELETE FROM refund_requests WHERE id = " . (int)$refundGuardId);
    if ($deletedRows === false) {
        $deleteBlocked = true;
        $deleteMessage = (string)($pdo->errorInfo()[2] ?? '');
    }
} catch (PDOException $guardException) {
    $deleteBlocked = true;
    $deleteMessage = $guardException->getMessage();
}
$assert(
    '1.2 DELETE físico sobre refund_requests bloqueado por el trigger (Art. III.1)',
    $deleteBlocked && str_contains($deleteMessage, 'III.1'),
    'Respuesta del servidor: ' . substr($deleteMessage, 0, 160)
);

$refundCountStmt = $pdo->prepare('SELECT COUNT(*) FROM `refund_requests` WHERE `id` = :id');
$refundCountStmt->execute([':id' => $refundGuardId]);
$assert(
    '1.3 La fila del reintegro sobrevive íntegra al intento de borrado físico',
    (int)$refundCountStmt->fetchColumn() === 1
);

// =========================================================================
// CASO 2: Art. V.1 - Cierre obligatoriamente justificado (umbral de 20 reales)
// =========================================================================

echo "\n--- Caso 2: Umbral de 20 caracteres reales en descartes (Art. V.1) ---\n";

$incV1 = $makeIncident($mach2->getId(), $location1->getId());

// 2.1 Motivo de 19 caracteres reales => 422 CANCELLATION_REASON_TOO_SHORT
$reasonShort = 'Descarte duplicados'; // 19 caracteres reales exactos
$assert(
    "2.1 Fixture: motivo de 19 caracteres reales",
    mb_strlen($reasonShort, 'UTF-8') === 19
);

$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incV1->getId()}/cancel",
    parsedBody: ['cancellation_reason' => $reasonShort],
    headers: $authHdr
);
$res = $router->dispatch($req);
$assert(
    '2.2 Motivo corto => 422 CANCELLATION_REASON_TOO_SHORT (Art. V.1)',
    $res->getStatusCode() === 422 && ($res->getDecodedBody()['error']['code'] ?? '') === 'CANCELLATION_REASON_TOO_SHORT',
    "Código HTTP: {$res->getStatusCode()}"
);

$row = $pdo->query("SELECT status, cancellation_reason, cancelled_at FROM incidents WHERE id = {$incV1->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert(
    '2.3 Rechazo sin persistir cambio alguno: estado, motivo y fecha intactos',
    ($row['status'] ?? null) === 'REGISTERED' && ($row['cancellation_reason'] ?? null) === null && ($row['cancelled_at'] ?? null) === null
);

// 2.4 Umbral exacto de 20 caracteres reales (con tilde: multibyte) => 200 CANCELLED
$reasonThreshold = 'Avería duplicada: sí'; // 20 caracteres reales exactos
$assert(
    "2.4 Fixture: 20 caracteres reales exactos con tilde",
    mb_strlen($reasonThreshold, 'UTF-8') === 20 && strlen($reasonThreshold) > mb_strlen($reasonThreshold, 'UTF-8')
);

$req = new Request(
    method: 'PATCH',
    path: "/api/coordinator/incidents/{$incV1->getId()}/cancel",
    parsedBody: ['cancellation_reason' => $reasonThreshold],
    headers: $authHdr
);
$res          = $router->dispatch($req);
$discardBody  = $res->getDecodedBody();
$assert(
    '2.5 Motivo en el umbral exacto => 200 OK con descarte efectivo',
    $res->getStatusCode() === 200 && ($discardBody['success'] ?? false) === true && ($discardBody['data']['status'] ?? null) === 'CANCELLED',
    "Código HTTP: {$res->getStatusCode()}"
);

// 2.6 Art. III.2: el descarte operativo preserva la fila en disco (borrado lógico)
$row = $pdo->query("SELECT status, cancellation_reason, deleted_at FROM incidents WHERE id = {$incV1->getId()}")->fetch(PDO::FETCH_ASSOC);
$assert(
    '2.6 Art. III.2: fila preservada físicamente, deleted_at NULL y motivo íntegro',
    $row !== false && ($row['status'] ?? null) === 'CANCELLED' && ($row['cancellation_reason'] ?? null) === $reasonThreshold && ($row['deleted_at'] ?? null) === null
);

// Evento inmutable INCIDENT_CANCELLED en audit_log (RNF-04, Art. III.3, RF-07.4)
$cancelAuditStmt = $pdo->prepare("SELECT * FROM audit_log WHERE entity_type = 'TICKET' AND entity_id = :id AND action = 'INCIDENT_CANCELLED' ORDER BY id DESC LIMIT 1");
$cancelAuditStmt->execute([':id' => $incV1->getId()]);
$cancelAudit = $cancelAuditStmt->fetch(PDO::FETCH_ASSOC);
$assert(
    '2.7 Evento INCIDENT_CANCELLED persistido en audit_log (RNF-04, Art. III.3)',
    $cancelAudit !== false,
    'Sin filas de auditoría del descarte.'
);
if ($cancelAudit !== false) {
    $cancelState    = json_decode((string)$cancelAudit['new_state'], true) ?? [];
    $cancelMetadata = json_decode((string)$cancelAudit['metadata'], true) ?? [];
    $assert(
        '2.7 audit_log: motivo íntegro, fecha de descarte y actor coordinador',
        ($cancelState['cancellation_reason'] ?? null) === $reasonThreshold && isset($cancelState['cancelled_at']) && (int)($cancelAudit['user_id'] ?? 0) === $coordinatorId && ($cancelMetadata['ticket_code'] ?? null) === $incV1->getTicketCode()
    );
}

// =========================================================================
// CASO 3: Art. V.4 / RNF-05 - Enmascaramiento irrevocable en la respuesta JSON
// El expediente lleva un reintegro BIZUM + transferencia con teléfono e IBAN
// reales en la base de datos; el JSON del detalle solo puede contener máscaras.
// =========================================================================

echo "\n--- Caso 3: Enmascaramiento irrevocable de teléfono e IBAN (Art. V.4) ---\n";

$incMasked = $makeIncident($mach3->getId(), $location2->getId());
$pdo->prepare(
    "INSERT INTO `refund_requests`
        (`incident_id`, `machine_id`, `location_id`, `claimant_name`, `claimant_contact`, `claimed_amount`,
         `product_attempted`, `compensation_method`, `bizum_phone`, `iban`, `status`, `tracking_token`)
     VALUES (:incident_id, :machine_id, :location_id, 'Ciudadano Anónimo', '600000000', 2.50,
             'Bocadillo mixto', 'BIZUM', :bizum_phone, :iban, 'REQUIRES_COORDINATOR_APPROVAL', :tracking_token)"
)->execute([
    ':incident_id'    => $incMasked->getId(),
    ':machine_id'     => $mach3->getId(),
    ':location_id'    => $location2->getId(),
    ':bizum_phone'    => '612345678',
    ':iban'           => 'ES9121000418450200051332',
    ':tracking_token' => 'tok-tidm18-mask-' . $incMasked->getId(),
]);

$req        = new Request(method: 'GET', path: "/api/coordinator/incidents/{$incMasked->getId()}/detail", headers: $authHdr);
$res        = $router->dispatch($req);
$body       = $res->getDecodedBody();
$detailData = $body['data'] ?? [];
$refundRow  = $detailData['refund'] ?? [];

// La proyección del cuerpo a nivel de hilo (exactamente lo que viajaría por HTTP)
$wire = json_encode($body, JSON_UNESCAPED_UNICODE);
$wire = is_string($wire) ? $wire : '';

$assert(
    '3.1 Detalle del expediente con reintegro => 200 OK con has_refund true',
    $res->getStatusCode() === 200 && ($refundRow['has_refund'] ?? null) === true,
    "Código HTTP: {$res->getStatusCode()}"
);

$assert(
    "3.2 Teléfono Bizum enmascarado con formato 6** *** 678",
    ($refundRow['contact_phone_masked'] ?? null) === '6** *** 678',
    'Valor recibido: ' . var_export($refundRow['contact_phone_masked'] ?? null, true)
);

$assert(
    "3.3 IBAN enmascarado con país y últimos cuatro dígitos",
    ($refundRow['iban_masked'] ?? null) === 'ES** **** **** **** **13 32',
    'Valor recibido: ' . var_export($refundRow['iban_masked'] ?? null, true)
);

$leaks = [];
foreach (['612345678', 'ES9121000418450200051332', '600000000'] as $secret) {
    if (str_contains($wire, $secret)) {
        $leaks[] = $secret;
    }
}
$assert(
    '3.4 Cero secretos crudos en el cuerpo JSON (teléfono, IBAN y contacto del reclamante)',
    $leaks === [],
    'Secretos filtrados al cuerpo: ' . implode(', ', $leaks)
);

$refundKeys  = array_keys(is_array($refundRow) ? $refundRow : []);
$rawKeyLeaks = array_intersect(['bizum_phone', 'iban', 'claimant_contact'], $refundKeys);
$assert(
    '3.5 El bloque refund no expone ninguna clave cruda de contacto o pago',
    $rawKeyLeaks === [],
    'Claves crudas presentes: ' . implode(', ', $rawKeyLeaks)
);

// =========================================================================
// CASO 4: Art. V.4 - Denegación de acceso para roles de sede y técnicos
// El técnico interno autenticado recibe 403 FORBIDDEN (rol insuficiente) en los
// cuatro endpoints del módulo; la sesión de sede ni siquiera atraviesa la
// autenticación interna y recibe 401 UNAUTHORIZED (denegación total, mismo
// criterio del precedente SiteManagerRefundDataSegregationTest).
// =========================================================================

echo "\n--- Caso 4: Denegación de acceso a sede y técnicos (Art. V.4) ---\n";

$guardedEndpoints = [
    'GET detalle' => ['GET', "/api/coordinator/incidents/{$incMasked->getId()}/detail", []],
    'PATCH asignar' => ['PATCH', "/api/coordinator/incidents/{$incMasked->getId()}/assign", ['technician_id' => $technician->getId()]],
    'PATCH descartar' => ['PATCH', "/api/coordinator/incidents/{$incMasked->getId()}/cancel", ['cancellation_reason' => 'Descarte bloqueado por rol insuficiente en la prueba.']],
    'POST comentar' => ['POST', "/api/coordinator/incidents/{$incMasked->getId()}/comments", ['comment_text' => 'Intento de nota con rol insuficiente.']],
];

foreach ($guardedEndpoints as $endpointLabel => [$method, $endpointPath, $endpointBody]) {
    $req = new Request(method: $method, path: $endpointPath, parsedBody: $endpointBody, headers: ['Authorization' => "Bearer {$technicianToken}"]);
    $res = $router->dispatch($req);
    $assert(
        "4.1 Técnico en {$endpointLabel} => 403 FORBIDDEN",
        $res->getStatusCode() === 403 && ($res->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN',
        "Código HTTP: {$res->getStatusCode()}"
    );
}

foreach ($guardedEndpoints as $endpointLabel => [$method, $endpointPath, $endpointBody]) {
    $req = new Request(method: $method, path: $endpointPath, parsedBody: $endpointBody, headers: ['Authorization' => "Bearer {$siteToken}"]);
    $res = $router->dispatch($req);
    $assert(
        "4.2 Sesión de sede en {$endpointLabel} => 401 (no atraviesa la auth interna)",
        $res->getStatusCode() === 401 && ($res->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED',
        "Código HTTP: {$res->getStatusCode()}"
    );
}

// La coordinadora legítima conserva el acceso completo sobre el mismo expediente
$req = new Request(method: 'GET', path: "/api/coordinator/incidents/{$incMasked->getId()}/detail", headers: $authHdr);
$res = $router->dispatch($req);
$assert(
    '4.3 La coordinadora legítima mantiene el acceso al expediente (control positivo)',
    $res->getStatusCode() === 200 && (($res->getDecodedBody()['data']['incident']['id'] ?? null) === $incMasked->getId())
);

// ─── RESULTADO FINAL ──────────────────────────────────────────────────────

echo "\n" . str_repeat('=', 70) . "\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE (0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo str_repeat('=', 70) . "\n";

exit($failures > 0 ? 1 : 0);
