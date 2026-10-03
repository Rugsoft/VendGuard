<?php
declare(strict_types=1);

/**
 * VendGuard - SiteManagerRefundDataSegregationTest (T-REF-13, T-REF-23)
 *
 * Constitutional route-registration suite for the refund module (RF-REF-10,
 * RNF-REF-03, Constitution Art. V.4 and Art. III).
 *
 * It exercises all eleven refund-related routes through the real AppRouter and
 * real MariaDB repositories: public QR/report and token tracking, technician,
 * site reception, and coordinator finance. No controller is invoked directly
 * and no collaborators are injected, so missing route registrations,
 * middleware omissions, and production-constructor wiring cannot hide behind
 * a unit-only pass.
 *
 * Security properties checked:
 * - Coordinator financial endpoints return 401 without an internal token and
 *   403 for an authenticated technician before the controller reads money.
 * - Site routes require the site's supported authentication (Bearer or
 *   X-Site-Code); technician and coordinator endpoints require their own
 *   internal role.
 * - IBAN and Bizum phone exist in the real row, but are absent from the
 *   technician, reception, and public projections.
 * - The two public endpoints are intentionally public: the tracking token is
 *   their credential, not an internal session.
 * - `refund_requests` itself refuses physical deletion (Art. III.1). The
 *   prohibition is enforced by a BEFORE DELETE trigger (migration 010) rather
 *   than by convention, and it is checked by actually attempting the DELETE
 *   against a real row, not by reading the migration file. Soft cancellation
 *   via `is_active` remains the only legitimate way to retire a case.
 *
 * The test creates its site, machine, incident and claim inside one database
 * transaction and always rolls it back. `SeedRunner` runs before the
 * transaction because other integration suites may have purged shared seed
 * data; seeding itself owns a transaction.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();

$failures = 0;
$assertions = 0;

$assert = static function (string $label, bool $condition, string $detail = '') use (&$failures, &$assertions): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$label}\n";
    }
    if (!$condition) {
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
        $failures++;
    }
};

$pdo->beginTransaction();

try {
    $locations = new PdoLocationRepository($pdo);
    $machines = new PdoMachineRepository($pdo);
    $incidents = new PdoIncidentRepository($pdo);
    $users = new PdoUserRepository($pdo);
    $refunds = new PdoRefundRequestRepository($pdo);

    $location = $locations->findBySiteCode('SEDE-BCN-01');
    $coordinator = $users->findByEmail('coordinacion@vendguard.internal');
    $technician = $users->findByEmail('jordi.ruta@vendguard.internal');

    $assert('0.1 La sede, el coordinador y el técnico semilla existen',
        $location !== null && $coordinator !== null && $technician !== null);

    if ($location === null || $coordinator === null || $technician === null) {
        throw new RuntimeException('No hay suficientes semillas para la prueba de rutas.');
    }

    $machine = $machines->create([
        'location_id' => $location->getId(),
        'code' => 'TREF13-' . strtoupper(bin2hex(random_bytes(4))),
        'model' => 'VendGuard test machine',
        'machine_type' => MachineType::COMBO->value,
        'floor_wing' => 'Test floor',
        'notes' => 'Machine created inside the T-REF-13 rollback transaction.',
    ]);

    $incident = $incidents->create(new Incident(
        id: null,
        ticketCode: 'TREF13-' . strtoupper(bin2hex(random_bytes(4))),
        machineId: (int)$machine->getId(),
        locationId: (int)$location->getId(),
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'La máquina retuvo el pago y no entregó el producto solicitado.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::IN_PROGRESS,
        assignedTechnicianId: (int)$technician->getId(),
        reporterName: 'Laura Sanitaria',
        reporterPhone: '600111222'
    ));

    $management = new RefundManagementService($refunds);
    $refund = $management->createCase(new CreateRefundRequestDTO(
        incidentId: (int)$incident->getId(),
        machineId: (int)$machine->getId(),
        locationId: (int)$location->getId(),
        claimantName: 'Laura Sanitaria',
        claimantContact: '600111222',
        claimedAmount: 12.00,
        compensationMethod: CompensationMethod::TRANSFERENCIA_BANCARIA,
        productAttempted: 'Café con leche carril 2',
        iban: 'ES9121000418450200051332'
    ));

    $assert('0.2 La fila real contiene el IBAN que las proyecciones no autorizadas deben ocultar',
        $refund->getIban() === 'ES9121000418450200051332');

    $auth = new AuthService($locations, $users);
    $coordinatorToken = $auth->generateInternalToken($coordinator);
    $technicianToken = $auth->generateInternalToken($technician);
    $siteToken = $auth->generateSiteToken($location);
    $router = AppRouter::create();

    // El middleware de auth del controlador también rechaza por sí solo una
    // petición anónima; por eso se certifica además la RUTA en AppRouter,
    // incluido el middleware requerido. Estos contratos de fuente capturan una
    // regresión de cableado que las respuestas HTTP, por sí solas, enmascararían.
    $routerSource = (string)file_get_contents(__DIR__ . '/../../src/Presentation/Routing/AppRouter.php');
    $routeContracts = [
        'POST /api/qr/report público' => "\$router->post('/api/qr/report', [\\VendGuard\\Presentation\\Controller\\QrScanController::class, 'report']);",
        'GET /api/public/refunds/track público' => "\$router->get('/api/public/refunds/track', [PublicRefundController::class, 'track']);",
        'PATCH /api/public/refunds/track público' => "\$router->patch('/api/public/refunds/track', [PublicRefundController::class, 'rectify']);",
        'GET técnico con technicianAuth' => "\$router->get('/api/technician/incidents/{id}/refund', [TechnicianRefundController::class, 'show'], [\$technicianAuth]);",
        'POST resolve técnico con technicianAuth' => "\$router->post('/api/technician/incidents/{id}/resolve', [\\VendGuard\\Presentation\\Controller\\TechnicianController::class, 'resolveIncident'], [\$technicianAuth]);",
        'GET sede con siteAuth' => "\$router->get('/api/location/refunds', [LocationRefundController::class, 'index'], [\$siteAuth]);",
        'POST deliver sede con siteAuth' => "\$router->post('/api/location/refunds/{id}/deliver', [LocationRefundController::class, 'deliver'], [\$siteAuth]);",
        'GET coordinación con coordinatorAuth' => "\$router->get('/api/coordinator/refunds', [CoordinatorRefundController::class, 'index'], [\$coordinatorAuth]);",
        'POST approve con coordinatorAuth' => "\$router->post('/api/coordinator/refunds/{id}/approve', [CoordinatorRefundController::class, 'approve'], [\$coordinatorAuth]);",
        'POST pay con coordinatorAuth' => "\$router->post('/api/coordinator/refunds/{id}/pay', [CoordinatorRefundController::class, 'pay'], [\$coordinatorAuth]);",
        'POST reject con coordinatorAuth' => "\$router->post('/api/coordinator/refunds/{id}/reject', [CoordinatorRefundController::class, 'reject'], [\$coordinatorAuth]);",
    ];
    foreach ($routeContracts as $label => $registration) {
        $assert("Rutas registradas: {$label}", str_contains($routerSource, $registration));
    }

    $dispatch = static function (
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        ?string $token = null,
        ?string $siteCode = null
    ) use ($router) {
        $headers = ['content-type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        if ($siteCode !== null) {
            $headers['X-Site-Code'] = $siteCode;
        }

        return $router->dispatch(new Request($method, $path, $query, $body, $headers));
    };

    $decode = static function ($response): array {
        $decoded = json_decode($response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    };

    $refundId = (int)$refund->getId();
    $incidentId = (int)$incident->getId();
    $trackingToken = $refund->getTrackingToken();

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Reporte QR público ---\n";

    $qrReport = $dispatch('POST', '/api/qr/report');
    $qrBody = $decode($qrReport);
    $assert('1.1 POST /api/qr/report está registrado sin autenticación',
        $qrReport->getStatusCode() === 422
        && ($qrBody['error']['code'] ?? '') === 'MISSING_MACHINE_CODE',
        'HTTP ' . $qrReport->getStatusCode());

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Seguimiento público por token ---\n";

    $publicTrack = $dispatch('GET', '/api/public/refunds/track', ['token' => $trackingToken]);
    $publicBody = $decode($publicTrack);
    $publicRaw = $publicTrack->getBody();
    $assert('2.1 GET de seguimiento está registrado y funciona sin sesión interna',
        $publicTrack->getStatusCode() === 200
        && ($publicBody['data']['status'] ?? '') === 'PENDING_INSPECTION',
        'HTTP ' . $publicTrack->getStatusCode());
    $assert('2.2 El payload público omite IBAN y Bizum',
        !str_contains($publicRaw, '"iban"')
        && !str_contains($publicRaw, '"bizum_phone"')
        && !str_contains($publicRaw, 'ES9121000418450200051332'));

    $publicMissingToken = $dispatch('GET', '/api/public/refunds/track');
    $assert('2.3 El GET público sí llega al controlador (token requerido -> 422)',
        $publicMissingToken->getStatusCode() === 422,
        'HTTP ' . $publicMissingToken->getStatusCode());

    // El estado PENDING_INSPECTION puede rectificarse en el dominio; este PATCH
    // permite completar la vuelta por el router y valida que el camino público
    // no requiere una sesión interna. Sólo responde status, jamás devuelve el
    // IBAN corregido.
    $publicPatch = $dispatch('PATCH', '/api/public/refunds/track', ['token' => $trackingToken], [
        'iban' => 'ES9121000418450200051332',
    ]);
    $publicPatchRaw = $publicPatch->getBody();
    $assert('2.4 PATCH público está registrado y funciona sin sesión interna',
        $publicPatch->getStatusCode() === 200
        && ($decode($publicPatch)['data']['status'] ?? '') === 'VERIFIED_PENDING_PAYMENT',
        'HTTP ' . $publicPatch->getStatusCode() . ' ' . $publicPatchRaw);
    $assert('2.5 La respuesta pública de rectificación no repite el IBAN',
        !str_contains($publicPatchRaw, 'ES9121000418450200051332')
        && !str_contains($publicPatchRaw, '"iban"'));

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Endpoints del técnico: sesión, rol y DTO restringido ---\n";

    $techPath = "/api/technician/incidents/{$incidentId}/refund";
    $techAnonymous = $dispatch('GET', $techPath);
    $assert('3.1 GET técnico sin token responde 401', $techAnonymous->getStatusCode() === 401);

    // Sin el middleware, el controlador confiando sólo en atributos inyectados
    // serviría el caso si alguien forjara esos atributos desde otro componente.
    $forgedTechContext = new Request('GET', $techPath);
    $forgedTechContext->setAttribute('user_id', (int)$technician->getId());
    $forgedTechContext->setAttribute('user_role', 'TECHNICIAN');
    $forgedTechResponse = $router->dispatch($forgedTechContext);
    $assert('3.1b El middleware exige un token firmado aunque la petición traiga atributos forjados',
        $forgedTechResponse->getStatusCode() === 401);

    $techWrongRole = $dispatch('GET', $techPath, token: $coordinatorToken);
    $assert('3.2 GET técnico con token COORDINATOR responde 403', $techWrongRole->getStatusCode() === 403);

    $techView = $dispatch('GET', $techPath, token: $technicianToken);
    $techRaw = $techView->getBody();
    $techBody = $decode($techView);
    $assert('3.3 GET técnico con TECHNICIAN llega a la ruta y responde 200',
        $techView->getStatusCode() === 200
        && ($techBody['data']['has_refund_requests'] ?? false) === true,
        'HTTP ' . $techView->getStatusCode() . ' ' . $techRaw);
    $assert('3.4 La proyección técnica omite IBAN, Bizum y el número real',
        !str_contains($techRaw, '"iban"')
        && !str_contains($techRaw, '"bizum_phone"')
        && !str_contains($techRaw, 'ES9121000418450200051332')
        && !str_contains($techRaw, '600111222'));

    $resolvePath = "/api/technician/incidents/{$incidentId}/resolve";
    $resolveAnonymous = $dispatch('POST', $resolvePath);
    $assert('3.5 POST técnico resolve sin token responde 401', $resolveAnonymous->getStatusCode() === 401);
    $resolveWrongRole = $dispatch('POST', $resolvePath, token: $coordinatorToken);
    $assert('3.6 POST técnico resolve con COORDINATOR responde 403', $resolveWrongRole->getStatusCode() === 403);
    $resolveAllowed = $dispatch('POST', $resolvePath, body: [], token: $technicianToken);
    $assert('3.7 POST técnico resolve con TECHNICIAN llega al validador de dominio (422)',
        $resolveAllowed->getStatusCode() === 422
        && ($decode($resolveAllowed)['error']['code'] ?? '') === 'INVALID_RESOLUTION',
        'HTTP ' . $resolveAllowed->getStatusCode());

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Rutas de sede: acceso local y proyección anonimizada ---\n";

    $siteListAnonymous = $dispatch('GET', '/api/location/refunds');
    $assert('4.1 GET sede sin identidad responde 401', $siteListAnonymous->getStatusCode() === 401);

    $forgedSiteContext = new Request('GET', '/api/location/refunds');
    $forgedSiteContext->setAttribute('location_id', (int)$location->getId());
    $forgedSiteResponse = $router->dispatch($forgedSiteContext);
    $assert('4.1b El middleware bloquea un `location_id` forjado sin credencial de sede',
        $forgedSiteResponse->getStatusCode() === 401);

    $siteList = $dispatch('GET', '/api/location/refunds', token: $siteToken);
    $siteRaw = $siteList->getBody();
    $siteBody = $decode($siteList);
    $assert('4.2 GET sede con bearer site_token responde 200',
        $siteList->getStatusCode() === 200
        && (int)($siteBody['data']['total'] ?? 0) >= 1,
        'HTTP ' . $siteList->getStatusCode());
    $assert('4.3 La bandeja de sede no filtra datos bancarios ni datos de contacto',
        !str_contains($siteRaw, '"iban"')
        && !str_contains($siteRaw, '"bizum_phone"')
        && !str_contains($siteRaw, 'ES9121000418450200051332')
        && !str_contains($siteRaw, '600111222'));

    $deliverPath = "/api/location/refunds/{$refundId}/deliver";
    $deliverAnonymous = $dispatch('POST', $deliverPath);
    $assert('4.4 POST entrega en sede sin identidad responde 401', $deliverAnonymous->getStatusCode() === 401);
    $deliverAllowed = $dispatch('POST', $deliverPath, body: [], token: $siteToken);
    $assert('4.5 POST entrega en sede con bearer llega al validador de PIN (422)',
        $deliverAllowed->getStatusCode() === 422
        && ($decode($deliverAllowed)['error']['code'] ?? '') === 'MISSING_PICKUP_PIN',
        'HTTP ' . $deliverAllowed->getStatusCode());

    $siteCodeList = $dispatch('GET', '/api/location/refunds', siteCode: 'SEDE-BCN-01');
    $assert('4.6 GET sede acepta X-Site-Code según el contrato', $siteCodeList->getStatusCode() === 200);

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Coordinación: los cuatro endpoints exigen rol COORDINATOR ---\n";

    $coordIndex = $dispatch('GET', '/api/coordinator/refunds', token: $coordinatorToken);
    $coordBody = $decode($coordIndex);
    $coordRaw = $coordIndex->getBody();
    $assert('5.1 GET coordinación con COORDINATOR está registrado y responde 200',
        $coordIndex->getStatusCode() === 200
        && (int)($coordBody['data']['total'] ?? 0) >= 1,
        'HTTP ' . $coordIndex->getStatusCode());
    $assert('5.2 La proyección de coordinación sí incluye el IBAN al rol autorizado',
        str_contains($coordRaw, 'ES9121000418450200051332'));

    $coordinatorRoutes = [
        ['POST', "/api/coordinator/refunds/{$refundId}/approve", ['approved_amount' => 12.00], 'approve', 'MISSING_APPROVED_AMOUNT'],
        ['POST', "/api/coordinator/refunds/{$refundId}/pay", ['payment_reference' => ''], 'pay', 'MISSING_PAYMENT_REFERENCE'],
        ['POST', "/api/coordinator/refunds/{$refundId}/reject", ['rejection_reason' => ''], 'reject', 'MISSING_REJECTION_REASON'],
    ];

    foreach ($coordinatorRoutes as [$method, $path, $body, $action, $errorCode]) {
        $anonymous = $dispatch($method, $path, body: $body);
        $assert("5.3 {$action} sin token responde 401", $anonymous->getStatusCode() === 401);

        $technicianAccess = $dispatch($method, $path, body: $body, token: $technicianToken);
        $assert("5.4 {$action} con token TECHNICIAN responde 403", $technicianAccess->getStatusCode() === 403);

        $siteAccess = $dispatch($method, $path, body: $body, token: $siteToken);
        $assert("5.5 {$action} con token de sede no puede atravesar auth interno (401)", $siteAccess->getStatusCode() === 401);

        $coordinatorAccess = $dispatch($method, $path, body: [], token: $coordinatorToken);
        $coordinatorAccessBody = $decode($coordinatorAccess);
        $assert("5.6 {$action} con COORDINATOR llega al controlador ({$errorCode})",
            $coordinatorAccess->getStatusCode() === 422
            && ($coordinatorAccessBody['error']['code'] ?? '') === $errorCode,
            'HTTP ' . $coordinatorAccess->getStatusCode() . ' ' . $coordinatorAccess->getBody());
    }

    $coordIndexAnonymous = $dispatch('GET', '/api/coordinator/refunds');
    $assert('5.7 GET coordinación anónimo responde 401', $coordIndexAnonymous->getStatusCode() === 401);
    $forgedCoordinatorContext = new Request('GET', '/api/coordinator/refunds');
    $forgedCoordinatorContext->setAttribute('user_id', (int)$coordinator->getId());
    $forgedCoordinatorContext->setAttribute('user_role', 'COORDINATOR');
    $forgedCoordinatorResponse = $router->dispatch($forgedCoordinatorContext);
    $assert('5.7b El middleware bloquea atributos COORDINATOR forjados sin token firmado',
        $forgedCoordinatorResponse->getStatusCode() === 401);
    $coordIndexTechnician = $dispatch('GET', '/api/coordinator/refunds', token: $technicianToken);
    $assert('5.8 GET coordinación con TECHNICIAN responde 403', $coordIndexTechnician->getStatusCode() === 403);
    $coordIndexSite = $dispatch('GET', '/api/coordinator/refunds', token: $siteToken);
    $assert('5.9 GET coordinación con token de sede responde 401', $coordIndexSite->getStatusCode() === 401);

    // Confirma que la suite realmente crea datos de pago que se ocultaron antes.
    $assert('5.10 Control de no-vacuidad: el IBAN guardado sigue existiendo en MariaDB',
        $refunds->findById($refundId)?->getIban() === 'ES9121000418450200051332');

    // ─────────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Inviolabilidad: la tabla prohíbe el borrado físico (Art. III.1) ---\n";
    // ─────────────────────────────────────────────────────────────────────────
    // Hasta aquí "nunca se borra" era una convención del código: un DELETE
    // descuidado en cualquier ruta habría destruido un registro económico sin
    // que nada se quejara. La Constitución lo prohíbe sobre las ENTIDADES, no
    // sobre los caminos de código, así que la regla se instala donde vive el
    // dato. Se certifica contra MariaDB real, no leyendo el fichero de la
    // migración.

    $triggerName = 'trg_refund_requests_no_hard_delete';
    $triggerRow = $pdo->prepare('
        SELECT ACTION_TIMING, EVENT_MANIPULATION, EVENT_OBJECT_TABLE
        FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :name
    ');
    $triggerRow->execute([':name' => $triggerName]);
    $trigger = $triggerRow->fetch(PDO::FETCH_ASSOC) ?: [];

    $assert('6.1 La tabla `refund_requests` tiene montado su guardián BEFORE DELETE',
        ($trigger['ACTION_TIMING'] ?? '') === 'BEFORE'
        && ($trigger['EVENT_MANIPULATION'] ?? '') === 'DELETE'
        && ($trigger['EVENT_OBJECT_TABLE'] ?? '') === 'refund_requests',
        json_encode($trigger));

    // El objetivo real de esta sección: intentar BORRAR de verdad el expediente
    // que la suite acaba de crear (el mismo que lleva el IBAN) y comprobar que
    // la base de datos se niega.
    $hardDeleteRefused = false;
    $refusalMessage = '';
    try {
        $pdo->exec("DELETE FROM `refund_requests` WHERE `id` = " . (int)$refundId);
    } catch (Throwable $e) {
        $hardDeleteRefused = true;
        $refusalMessage = $e->getMessage();
    }

    $assert('6.2 Un DELETE FROM refund_requests sobre un expediente real es rechazado por la BD',
        $hardDeleteRefused,
        'el DELETE se ejecutó sin resistencia: el Art. III.1 no se está aplicando');
    $assert('6.2 El rechazo es un SQLSTATE 45000 y explica la regla constitucional',
        str_contains($refusalMessage, '45000') && str_contains($refusalMessage, 'Art. III.1'),
        'mensaje: ' . $refusalMessage);

    $assert('6.3 El expediente sobrevive intacto al intento de borrado',
        (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests` WHERE `id` = ' . (int)$refundId . ' AND `is_active` = 1')->fetchColumn() === 1
        && $refunds->findById($refundId)?->getIban() === 'ES9121000418450200051332');

    // Un borrado "de mentira" (0 filas afectadas) no dispara un trigger FOR EACH
    // ROW, así que se comprueba también sobre un id inexistente para que nadie
    // confunda un DELETE inocuo con un guardián funcionando.
    $noRowDelete = 'ok';
    try {
        $pdo->exec('DELETE FROM `refund_requests` WHERE `id` = 4294967295');
    } catch (Throwable $e) {
        $noRowDelete = 'rechazado';
    }
    $assert('6.4 Control de no-vacuidad: el guardián muerde sobre filas reales, no sobre un id inexistente',
        $noRowDelete === 'ok' && $hardDeleteRefused === true,
        'id inexistente: ' . $noRowDelete);

    // La anulación es la vía legítima: se conservan la fila y su motivo.
    $rejectedReason = 'Corte de caja sin cobro coincidente en la auditoría de ventas.';
    $pdo->prepare('
        UPDATE `refund_requests`
        SET `status` = \'REJECTED\', `is_active` = 0, `coordinator_justification` = :reason
        WHERE `id` = :id
    ')->execute([':reason' => $rejectedReason, ':id' => (int)$refundId]);

    $softDeleted = $pdo->prepare('
        SELECT `status`, `is_active`, `coordinator_justification`
        FROM `refund_requests` WHERE `id` = :id
    ');
    $softDeleted->execute([':id' => (int)$refundId]);
    $softRow = $softDeleted->fetch(PDO::FETCH_ASSOC) ?: [];

    $assert('6.5 La anulación lógica conserva la fila y su motivo para la auditoría (Art. III.2)',
        ($softRow['status'] ?? '') === 'REJECTED'
        && (int)($softRow['is_active'] ?? 1) === 0
        && ($softRow['coordinator_justification'] ?? '') === $rejectedReason);

    // A partir de aquí se usan filas desechables: el expediente original ya
    // ha hecho su ciclo y borrarlo de verdad dejaría sin datos las
    // comprobaciones siguientes. Estas copias existen sólo para pelearse con el
    // guardián y desaparecen con el rollback de la suite.
    $makeDisposableCase = static function (string $name) use ($pdo): int {
        $seed = $pdo->query('
            SELECT `incident_id`, `machine_id`, `location_id`
            FROM `refund_requests` ORDER BY `id` LIMIT 1
        ')->fetch(PDO::FETCH_ASSOC) ?: [];

        if ($seed === []) {
            return 0;
        }

        $pdo->prepare('
            INSERT INTO `refund_requests`
                (`incident_id`, `machine_id`, `location_id`, `claimant_name`, `claimant_contact`,
                 `claimed_amount`, `compensation_method`, `pickup_pin`, `tracking_token`, `status`, `is_active`)
            VALUES (:i, :m, :l, :n, :c, 1.00, :cm, :pin, :tok, :st, 1)
        ')->execute([
            ':i' => (int)$seed['incident_id'],
            ':m' => (int)$seed['machine_id'],
            ':l' => (int)$seed['location_id'],
            ':n' => $name,
            ':c' => '600111222',
            ':cm' => 'EN_MANO_SEDE',
            ':pin' => '1234',
            ':tok' => hash('sha256', $name),
            ':st' => 'PENDING_INSPECTION',
        ]);

        return (int)$pdo->lastInsertId();
    };

    // El arnés de pruebas debe poder seguir limpiando: es el único que puede
    // abrir la ventana, y su código vive fuera de src/.
    $purgeCaseId = $makeDisposableCase('Fila desechable para la ventana de purga');
    $pdo->exec('SET @vendguard_purge = 1');
    try {
        $pdo->exec('DELETE FROM `refund_requests` WHERE `id` = ' . $purgeCaseId);
        $purged = (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests` WHERE `id` = ' . $purgeCaseId)->fetchColumn() === 0;
    } finally {
        $pdo->exec('SET @vendguard_purge = 0');
    }
    $assert('6.6 Sólo la ventana explícita de purga del arnés puede vaciar la tabla',
        $purgeCaseId > 0 && $purged,
        'id: ' . $purgeCaseId);

    $controlCaseId = $makeDisposableCase('Fila de control tras cerrar la ventana');
    $refusedAgain = false;
    try {
        $pdo->exec('DELETE FROM `refund_requests` WHERE `id` = ' . $controlCaseId);
    } catch (Throwable $e) {
        $refusedAgain = true;
    }
    $assert('6.7 Tras cerrarla, el guardián vuelve a estar activo',
        $controlCaseId > 0
        && $refusedAgain
        && (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests` WHERE `id` = ' . $controlCaseId)->fetchColumn() === 1,
        'id: ' . $controlCaseId . ' rechazó: ' . var_export($refusedAgain, true));

    // La ventana de purga no puede quedar abierta para el resto de la corrida.
    $assert('6.8 La variable de purga queda desactivada al terminar la comprobación',
        (int)((($pdo->query('SELECT @vendguard_purge AS v')->fetch(PDO::FETCH_ASSOC) ?: [])['v']) ?? 0) === 0);

    // El guardián no puede abrirse desde el código de producción: la única
    // escritor de la variable vive en el arnés de pruebas, nunca en producción.
    $srcRoot = dirname(__DIR__, 2) . '/src';
    $srcOffenders = [];
    $directory = new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS);
    foreach (new RecursiveIteratorIterator($directory) as $fileInfo) {
        if ($fileInfo->getExtension() !== 'php') {
            continue;
        }
        if (str_contains((string)file_get_contents($fileInfo->getPathname()), '@vendguard_purge')) {
            $srcOffenders[] = $fileInfo->getFilename();
        }
    }
    $assert('6.9 Ningún fichero de src/ puede abrir la ventana de purga (Art. III.1)',
        $srcOffenders === [],
        'escritores: ' . json_encode($srcOffenders));

    $migrationSource = (string)file_get_contents(
        dirname(__DIR__, 2) . '/database/migrations/010_refund_hard_delete_guard.sql'
    );
    $assert('6.10 La migración del guardián está versionada y es idempotente',
        $migrationSource !== ''
        && str_contains($migrationSource, 'CREATE TRIGGER')
        && str_contains($migrationSource, 'information_schema.triggers')
        && str_contains($migrationSource, '45000'));
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: Blindaje RBAC, segregación financiera e inviolabilidad de datos verificados. T-REF-13 y T-REF-23 CUMPLIDAS.\n";
} else {
    echo " RESULTADO: {$failures} fallo(s) de {$assertions} aserciones.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
