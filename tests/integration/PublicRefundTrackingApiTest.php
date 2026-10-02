<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Integration suite for the public consumer journey (T-REF-19).
 *
 * It exercises the two public endpoints end to end through the real
 * `AppRouter` and real MariaDB, exactly as the anonymous consumer reaches them
 * (RF-REF-01, RF-REF-02, RF-REF-03):
 *
 * 1. `POST /api/qr/report` with the optional refund block, which must return the
 *    receipt with a four-digit pickup PIN and a 64-character tracking token, and
 *    persist both on the real row.
 * 2. `GET /api/public/refunds/track?token=...`, the only credential the claimant
 *    ever holds: it must resolve the case by token, echo the PIN only while the
 *    cash waits at reception, and never project the IBAN, the Bizum phone or the
 *    claimant's name (Art. V.4, RNF-REF-03).
 * 3. `PATCH /api/public/refunds/track?token=...` in `PENDING_CONTACT`, which must
 *    validate the IBAN with the native MOD 97 algorithm, persist the corrected
 *    instrument, return the case to `VERIFIED_PENDING_PAYMENT` and keep the
 *    financial data out of the response.
 *
 * No controller is invoked directly and no collaborator is injected: the router
 * is the production wiring, so a missing route registration, a middleware
 * mistake or a swapped constructor dependency cannot hide behind a unit pass.
 *
 * Everything is created inside one transaction that is always rolled back, and
 * `SeedRunner` runs before it because previous integration suites may have
 * purged the shared seed data.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
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
    } else {
        $failures++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
};

$pdo->beginTransaction();

try {
    $locations = new PdoLocationRepository($pdo);
    $machines = new PdoMachineRepository($pdo);
    $refunds = new PdoRefundRequestRepository($pdo);
    $users = new PdoUserRepository($pdo);

    $location = $locations->findBySiteCode('SEDE-BCN-01');
    $assert('0.1 La sede semilla existe', $location !== null);
    if ($location === null) {
        throw new RuntimeException('No hay sede semilla para la prueba pública.');
    }

    // The seed machines are shared with every other integration suite; this one
    // creates its own so that a parallel purge or a previous test can never
    // decide whether the QR report below finds an open incident.
    $machine = $machines->create([
        'location_id' => $location->getId(),
        'code' => 'TREF19-' . strtoupper(bin2hex(random_bytes(3))),
        'model' => 'VendGuard public refund machine',
        'machine_type' => MachineType::HOT_DRINKS->value,
        'floor_wing' => 'Planta baja - Vestíbulo',
        'notes' => 'Máquina creada dentro de la transacción reversible de T-REF-19.',
    ]);

    // Segunda máquina para la vía digital: un reporte sobre una máquina con
    // avería abierta se fusiona (EARS 4.5), y aquí hace falta una creación
    // limpia. Se crea dentro de la transacción, así que no toca datos ajenos.
    $digitalMachine = $machines->create([
        'location_id' => $location->getId(),
        'code' => 'TREF19D-' . strtoupper(bin2hex(random_bytes(3))),
        'model' => 'VendGuard public refund machine (digital)',
        'machine_type' => MachineType::SNACKS->value,
        'floor_wing' => 'Planta primera - Comedor',
        'notes' => 'Máquina creada dentro de la transacción reversible de T-REF-19.',
    ]);

    $assert(
        '0.2 Las dos máquinas de prueba se crean dentro de la transacción',
        $machine !== null && $machine->getId() > 0 && $digitalMachine !== null && $digitalMachine->getId() > 0
    );

    $router = AppRouter::create();

    $dispatch = static function (string $method, string $path, array $query = [], array $body = []) use ($router) {
        return $router->dispatch(new Request($method, $path, $query, $body, ['content-type' => 'application/json']));
    };

    $decode = static function ($response): array {
        $decoded = json_decode($response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    };

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Reporte QR con solicitud de reintegro (RF-REF-01, RF-REF-02) ---\n";

    $pinReceipt = $decode($dispatch('POST', '/api/qr/report', [], [
        'machine_code' => $machine->getCode(),
        'category' => 'PAYMENT_SYSTEM',
        'description' => 'Metí una moneda de 2 euros y la máquina no entregó el producto solicitado.',
        'reporter_name' => 'Laura Sanitaria',
        'reporter_phone' => '600111222',
        'refund_requested' => true,
        'claimed_amount' => 2.00,
        'compensation_method' => 'EN_MANO_SEDE',
        'product_attempted' => 'Café con leche carril 2',
    ]));

    $receipt = $pinReceipt['data']['refund'] ?? [];
    $trackingToken = (string)($receipt['tracking_token'] ?? '');
    $pickupPin = (string)($receipt['pickup_pin'] ?? '');

    $assert(
        '1.1 El reporte público abre el expediente y devuelve su resguardo',
        ($pinReceipt['success'] ?? false) === true && isset($receipt['id']),
        (string)$receipt['id']
    );
    $assert(
        '1.2 El resguardo incluye un PIN de recogida de exactamente 4 dígitos',
        preg_match('/^[0-9]{4}$/', $pickupPin) === 1,
        'pin: ' . var_export($pickupPin, true)
    );
    $assert(
        '1.3 El resguardo incluye un token de seguimiento de 64 caracteres hexadecimales',
        preg_match('/^[0-9a-f]{64}$/', $trackingToken) === 1,
        'token: ' . var_export($trackingToken, true)
    );
    $assert(
        '1.4 La URL de seguimiento viaja lista para compartir con el afectado',
        ($receipt['tracking_url'] ?? '') === '/?track=' . $trackingToken
    );
    $assert(
        '1.5 El expediente nace en PENDING_INSPECTION con la vía solicitada',
        ($receipt['status'] ?? '') === 'PENDING_INSPECTION'
        && ($receipt['compensation_method'] ?? '') === 'EN_MANO_SEDE'
    );
    $assert(
        '1.6 El resguardo no devuelve el nombre ni el contacto del reclamante (Art. V.4)',
        !str_contains((string)$response = json_encode($receipt), 'Laura Sanitaria')
        && !str_contains((string)$response, '600111222'),
        (string)$response
    );

    $caseRow = $refunds->findById((int)($receipt['id'] ?? 0));
    $storedToken = (string)$pdo->query(
        "SELECT `tracking_token` FROM `refund_requests` WHERE `id` = " . (int)($receipt['id'] ?? 0)
    )->fetchColumn();

    $assert(
        '1.7 El PIN del resguardo es el PIN realmente persistido en MariaDB',
        $caseRow !== null && $caseRow->getPickupPin() === $pickupPin,
        'resguardo: ' . $pickupPin . ' bd: ' . var_export($caseRow?->getPickupPin(), true)
    );
    $assert(
        '1.8 El token persistido coincide con el emitido (RF-REF-02)',
        $storedToken === $trackingToken
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Seguimiento público por token seguro (RF-REF-02) ---\n";

    $trackResponse = $dispatch('GET', '/api/public/refunds/track', ['token' => $trackingToken]);
    $trackBody = $decode($trackResponse);
    $trackRaw = (string)$trackResponse->getBody();

    $assert(
        '2.1 El token del resguardo resuelve el expediente sin sesión interna',
        $trackResponse->getStatusCode() === 200 && ($trackBody['data']['status'] ?? '') === 'PENDING_INSPECTION',
        'HTTP ' . $trackResponse->getStatusCode() . ' ' . $trackRaw
    );
    $assert(
        '2.2 El seguimiento describe el estado en castellano para el consumidor',
        str_contains((string)($trackBody['data']['status_description'] ?? ''), 'reclamación')
    );
    $assert(
        '2.3 Mientras espera inspección no se expone ningún PIN',
        array_key_exists('pickup_pin', $trackBody['data'] ?? []) && $trackBody['data']['pickup_pin'] === null
    );
    $assert(
        '2.4 Tampoco permite rectificar datos todavía',
        ($trackBody['data']['can_rectify_data'] ?? true) === false
    );
    $assert(
        '2.5 La proyección pública omite IBAN, teléfono de Bizum y nombre (Art. V.4, RNF-REF-03)',
        !str_contains($trackRaw, '"iban"')
        && !str_contains($trackRaw, '"bizum_phone"')
        && !str_contains($trackRaw, 'Laura Sanitaria')
        && !str_contains($trackRaw, '600111222')
    );
    $assert(
        '2.6 El identificador interno del expediente no viaja en el JSON público',
        !str_contains($trackRaw, '"incident_id"')
    );

    $pinOnlyCase = $refunds->findById((int)($receipt['id'] ?? 0));
    $assert(
        '2.7 Control de no-vacuidad: el IBAN vacío y el PIN sí existen en la fila real',
        $pinOnlyCase !== null && $pinOnlyCase->getIban() === null && $pickupPin !== ''
    );

    $unknownToken = $dispatch('GET', '/api/public/refunds/track', ['token' => str_repeat('a', 64)]);
    $assert(
        '2.8 Un token con formato válido pero inexistente devuelve 404 REFUND_NOT_FOUND',
        $unknownToken->getStatusCode() === 404
        && ($decode($unknownToken)['error']['code'] ?? '') === 'REFUND_NOT_FOUND',
        'HTTP ' . $unknownToken->getStatusCode()
    );

    $malformedToken = $dispatch('GET', '/api/public/refunds/track', ['token' => 'no-es-un-token']);
    $assert(
        '2.9 Un token con formato inválido se rechaza con 422 antes de consultar la base',
        $malformedToken->getStatusCode() === 422
        && ($decode($malformedToken)['error']['code'] ?? '') === 'INVALID_TRACKING_TOKEN',
        'HTTP ' . $malformedToken->getStatusCode()
    );

    $missingToken = $dispatch('GET', '/api/public/refunds/track');
    $assert(
        '2.10 Sin parámetro token la petición se rechaza con 422',
        $missingToken->getStatusCode() === 422
        && ($decode($missingToken)['error']['code'] ?? '') === 'INVALID_TRACKING_TOKEN'
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. PIN visible solo con el efectivo en conserjería (RF-REF-06) ---\n";

    $refunds->transitionStatus(
        (int)($receipt['id'] ?? 0),
        RefundStatus::PENDING_INSPECTION,
        RefundStatus::DEPOSITED_AT_RECEPTION,
        [
            'technician_finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 2.00,
            'cash_custody_action' => 'LEFT_AT_RECEPTION',
            'receptionist_name' => 'Ana Conserjería',
            'technician_justification' => 'Monedas recuperadas en el canal interno del monedero.',
        ]
    );

    $pickupTrack = $dispatch('GET', '/api/public/refunds/track', ['token' => $trackingToken]);
    $pickupBody = $decode($pickupTrack);
    $pickupRaw = (string)$pickupTrack->getBody();

    $assert(
        '3.1 El expediente refleja que el efectivo ya está en conserjería',
        $pickupTrack->getStatusCode() === 200
        && ($pickupBody['data']['status'] ?? '') === 'DEPOSITED_AT_RECEPTION',
        'HTTP ' . $pickupTrack->getStatusCode() . ' ' . $pickupRaw
    );
    $assert(
        '3.2 Solo entonces el enlace de seguimiento devuelve el PIN de recogida',
        ($pickupBody['data']['pickup_pin'] ?? null) === $pickupPin,
        'pin público: ' . var_export($pickupBody['data']['pickup_pin'] ?? null, true)
    );
    $assert(
        '3.3 Y explica al consumidor que puede pasar a recogerlo',
        str_contains((string)($pickupBody['data']['status_description'] ?? ''), 'recogerlo')
    );
    $assert(
        '3.4 El nombre del conserje que recibió el sobre nunca sale por la URL pública',
        !str_contains($pickupRaw, 'Ana Conserjería')
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Rectificación de datos en PENDING_CONTACT (RF-REF-07) ---\n";

    // A second consumer scanning the QR of another machine gets an independent
    // case with its own PIN and token, never a shared one (RF-REF-08).
    $digitalReceipt = $decode($dispatch('POST', '/api/qr/report', [], [
        'machine_code' => $digitalMachine->getCode(),
        'category' => 'PAYMENT_SYSTEM',
        'description' => 'La máquina cobró con tarjeta y no entregó el producto; pido transferencia.',
        'reporter_name' => 'Marc Ruibal',
        'reporter_phone' => '699888777',
        'refund_requested' => true,
        'claimed_amount' => 12.00,
        'compensation_method' => 'TRANSFERENCIA_BANCARIA',
        'iban' => 'ES9121000418450200051332',
        'product_attempted' => 'Sándwich mixto',
    ]))['data']['refund'] ?? [];

    $digitalToken = (string)($digitalReceipt['tracking_token'] ?? '');
    $digitalCaseId = (int)($digitalReceipt['id'] ?? 0);

    $assert(
        '4.1 Un segundo consumidor obtiene su propio expediente, con token distinto al primero',
        $digitalCaseId > 0 && $digitalToken !== '' && $digitalToken !== $trackingToken
    );
    $assert(
        '4.2 La vía digital no genera PIN de recogida',
        array_key_exists('pickup_pin', $digitalReceipt) && $digitalReceipt['pickup_pin'] === null
    );
    $assert(
        '4.3 El IBAN tecleado no vuelve en el resguardo público (Art. V.4)',
        !str_contains((string)json_encode($digitalReceipt), 'ES9121000418450200051332')
        && !str_contains((string)json_encode($digitalReceipt), 'Marc Ruibal')
    );

    // Coordination flags the wrong instrument: the case waits for the claimant.
    $refunds->transitionStatus(
        $digitalCaseId,
        RefundStatus::PENDING_INSPECTION,
        RefundStatus::VERIFIED_PENDING_PAYMENT,
        [
            'technician_finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 12.00,
            'cash_custody_action' => 'HELD_FOR_CENTRAL',
            'technician_justification' => 'Efectivo custodiado para caja central tras la inspección.',
        ]
    );
    $refunds->transitionStatus(
        $digitalCaseId,
        RefundStatus::VERIFIED_PENDING_PAYMENT,
        RefundStatus::PENDING_CONTACT,
        []
    );

    $pendingTrack = $dispatch('GET', '/api/public/refunds/track', ['token' => $digitalToken]);
    $pendingBody = $decode($pendingTrack);

    $assert(
        '4.4 El seguimiento refleja el expediente pendiente de contacto',
        ($pendingBody['data']['status'] ?? '') === 'PENDING_CONTACT',
        'HTTP ' . $pendingTrack->getStatusCode() . ' ' . (string)$pendingTrack->getBody()
    );
    $assert(
        '4.5 Y habilita el formulario público de rectificación',
        ($pendingBody['data']['can_rectify_data'] ?? false) === true
    );
    $assert(
        '4.6 El aviso pide corregir los datos de pago',
        str_contains((string)($pendingBody['data']['status_description'] ?? ''), 'corregir sus datos')
    );

    $correctIban = 'ES7100302053091234567895';
    $rectifyResponse = $dispatch('PATCH', '/api/public/refunds/track', ['token' => $digitalToken], [
        'iban' => $correctIban,
    ]);
    $rectifyBody = $decode($rectifyResponse);
    $rectifyRaw = (string)$rectifyResponse->getBody();

    $assert(
        '4.7 La rectificación con IBAN válido devuelve 200 y reanuda la tramitación',
        $rectifyResponse->getStatusCode() === 200
        && ($rectifyBody['data']['status'] ?? '') === 'VERIFIED_PENDING_PAYMENT',
        'HTTP ' . $rectifyResponse->getStatusCode() . ' ' . $rectifyRaw
    );
    $assert(
        '4.8 La respuesta pública no repite el IBAN corregido (Art. V.4)',
        !str_contains($rectifyRaw, $correctIban) && !str_contains($rectifyRaw, '"iban"')
    );

    $correctedRow = $refunds->findById($digitalCaseId);
    $assert(
        '4.9 El IBAN validado por el algoritmo Módulo 97 queda persistido en MariaDB',
        $correctedRow !== null && $correctedRow->getIban() === $correctIban,
        'iban bd: ' . var_export($correctedRow?->getIban(), true)
    );
    $assert(
        '4.10 El expediente vuelve a la vía de pago con su estado actualizado',
        $correctedRow?->getStatus() === RefundStatus::VERIFIED_PENDING_PAYMENT
    );

    $invalidIban = $dispatch('PATCH', '/api/public/refunds/track', ['token' => $digitalToken], [
        'iban' => 'ES0000000000000000000000',
    ]);
    $assert(
        '4.11 Un IBAN con checksum inválido se rechaza con 422 INVALID_IBAN_FORMAT',
        $invalidIban->getStatusCode() === 422
        && ($decode($invalidIban)['error']['code'] ?? '') === 'INVALID_IBAN_FORMAT',
        'HTTP ' . $invalidIban->getStatusCode() . ' ' . (string)$invalidIban->getBody()
    );
    $assert(
        '4.12 Y el dato bueno anterior no se ha perdido (Art. III)',
        $refunds->findById($digitalCaseId)?->getIban() === $correctIban
    );

    $noPayload = $dispatch('PATCH', '/api/public/refunds/track', ['token' => $digitalToken], []);
    $assert(
        '4.13 Una rectificación sin datos bancarios se rechaza con 422',
        $noPayload->getStatusCode() === 422
    );

    $wrongState = $dispatch('PATCH', '/api/public/refunds/track', ['token' => $trackingToken], [
        'iban' => $correctIban,
    ]);
    $assert(
        '4.14 Rectificar un expediente que no espera contacto devuelve 409',
        $wrongState->getStatusCode() === 409
        && ($decode($wrongState)['error']['code'] ?? '') === 'INVALID_REFUND_STATE_TRANSITION',
        'HTTP ' . $wrongState->getStatusCode() . ' ' . (string)$wrongState->getBody()
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Inviolabilidad y rastro de auditoría (Art. III, RNF-REF-01) ---\n";

    $refundCount = (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests`')->fetchColumn();
    $assert(
        '5.1 Los dos expedientes existen y ninguna operación pública ha borrado nada',
        $refundCount === 2,
        'expedientes: ' . $refundCount
    );

    $auditActions = $pdo->query(
        "SELECT `action` FROM `audit_log`
         WHERE `entity_type` = 'REFUND_REQUEST' AND `entity_id` IN ({$digitalCaseId}, " . (int)($receipt['id'] ?? 0) . ")"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert(
        '5.2 La apertura y la rectificación quedan auditadas de forma inmutable',
        in_array('REFUND_CASE_CREATED', $auditActions, true)
        && in_array('REFUND_CONTACT_RECTIFIED', $auditActions, true),
        'acciones: ' . implode(', ', $auditActions)
    );

    $auditBlob = implode("\n", $pdo->query(
        "SELECT CONCAT(COALESCE(`metadata`, ''), '|', COALESCE(`previous_state`, ''), '|', COALESCE(`new_state`, ''))
         FROM `audit_log` WHERE `entity_type` = 'REFUND_REQUEST'"
    )->fetchAll(PDO::FETCH_COLUMN));
    $assert(
        '5.3 El rastro de auditoría no arrastra datos bancarios (Art. V.4)',
        !str_contains($auditBlob, $correctIban) && !str_contains($auditBlob, 'ES9121000418450200051332'),
        'rastro: ' . $auditBlob
    );

    // Route wiring contracts: a missing registration or a wrongly applied
    // middleware would be invisible in the in-memory dispatch above if some
    // other component answered first.
    $routerSource = (string)file_get_contents(__DIR__ . '/../../src/Presentation/Routing/AppRouter.php');
    $assert(
        '5.4 Las rutas públicas siguen registradas sin middleware de sesión en AppRouter',
        str_contains($routerSource, "\$router->get('/api/public/refunds/track', [PublicRefundController::class, 'track']);")
        && str_contains($routerSource, "\$router->patch('/api/public/refunds/track', [PublicRefundController::class, 'rectify']);")
    );
    $assert(
        '5.5 El reporte QR sigue siendo público y sin autenticación',
        str_contains($routerSource, "\$router->post('/api/qr/report', [")
    );

    // The suite reaches the router with no credentials at all; this assertion
    // certifies that the route was not accidentally protected.
    $assert(
        '5.6 Un consumidor anónimo completa todo el recorrido sin ninguna credencial',
        $trackResponse->getStatusCode() === 200
        && $rectifyResponse->getStatusCode() === 200
        && $unknownToken->getStatusCode() === 404
    );
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Una sola reclamación viva por avería y consumidor (RF-REF-11) ---\n";
    // ─────────────────────────────────────────────────────────────────────

    // Machine dedicated to the duplicate rule: the two reports below must land on
    // the same incident, which is what turns the second attempt into a duplicate
    // rather than a separate claim.
    $duplicateMachine = $machines->create([
        'location_id' => $location->getId(),
        'code' => 'TREF19X-' . strtoupper(bin2hex(random_bytes(3))),
        'model' => 'VendGuard duplicate claim machine',
        'machine_type' => MachineType::HOT_DRINKS->value,
        'floor_wing' => 'Planta baja - Vestíbulo',
        'notes' => 'Máquina creada dentro de la transacción reversible de T-REF-19.',
    ]);

    $claimBody = static function (string $code, string $phone, string $name = 'Laura Sanitaria') use ($dispatch): array {
        return [
            'machine_code' => $code,
            'category' => 'PAYMENT_SYSTEM',
            'description' => 'Metí una moneda y la máquina no entregó el producto.',
            'reporter_name' => $name,
            'reporter_phone' => $phone,
            'refund_requested' => true,
            'claimed_amount' => 2.00,
            'compensation_method' => 'EN_MANO_SEDE',
            'product_attempted' => 'Café con leche carril 2',
        ];
    };

    $firstResponse = $dispatch('POST', '/api/qr/report', [], $claimBody($duplicateMachine->getCode(), '600 111 222'));
    $firstReceipt = $decode($firstResponse)['data']['refund'] ?? [];
    $firstToken = (string)($firstReceipt['tracking_token'] ?? '');

    $assert(
        '6.1 La primera reclamación del consumidor abre su expediente',
        $firstResponse->getStatusCode() >= 200 && $firstResponse->getStatusCode() < 300
        && (int)($firstReceipt['id'] ?? 0) > 0
        && $firstToken !== '',
        json_encode($decode($firstResponse)['error'] ?? null)
    );

    // Same consumer, same incident, but the phone typed the way a second person
    // would type it. RF-REF-11 compares the NORMALISED contact, so this has to
    // be read as the same claimant and not as a third party.
    $secondResponse = $dispatch('POST', '/api/qr/report', [], $claimBody($duplicateMachine->getCode(), '600-111-222'));
    $secondError = $decode($secondResponse)['error'] ?? [];

    $assert(
        '6.2 El mismo consumidor no acumula un segundo expediente con otro formato de teléfono',
        $secondResponse->getStatusCode() === 409
        && (string)($secondError['code'] ?? '') === 'DUPLICATE_REFUND_CLAIM',
        'HTTP ' . $secondResponse->getStatusCode() . ' ' . json_encode($secondError)
    );

    // El rechazo identifica el expediente pero NO entrega su token: el token es
    // la credencial que abre el sobre en la conserjería y permite rectificar el
    // Bizum, y esta respuesta sale por un endpoint público.
    $assert(
        '6.3 El rechazo identifica el expediente y NO devuelve su token de seguimiento',
        (int)($secondError['details']['existing_refund_id'] ?? 0) === (int)($firstReceipt['id'] ?? 0)
        && !array_key_exists('existing_tracking_token', $secondError['details'] ?? []),
        json_encode($secondError['details'] ?? null)
    );

    // Barrido del cuerpo entero, no sólo de `details`: un token filterse por
    // cualquier otro sitio de la respuesta seguiría siendo un robo de credencial
    // y un verde sobre la clave concreta no lo detectaría.
    $secondRaw = (string)$secondResponse->getBody();
    $assert(
        '6.3b La respuesta de rechazo no contiene NINGÚN token de seguimiento',
        preg_match('/[0-9a-f]{' . 64 . '}/', $secondRaw) !== 1
        && !str_contains($secondRaw, $firstToken),
        substr($secondRaw, 0, 300)
    );

    // Y la consecuencia práctica: sin el token, el intruso no llega al PIN.
    $stolen = (string)($secondError['details']['existing_tracking_token'] ?? '');
    $peek = $dispatch('GET', '/api/public/refunds/track', ['token' => $stolen !== '' ? $stolen : str_repeat('0', 64)]);
    $peekBody = $decode($peek)['data'] ?? [];
    $assert(
        '6.9 El rechazo no da acceso al expediente: ni el estado ni el PIN se pueden leer',
        ($stolen === '' && $peek->getStatusCode() !== 200)
        && !array_key_exists('pickup_pin', (array) $peekBody),
        'HTTP ' . $peek->getStatusCode() . ' ' . json_encode($peekBody)
    );

    // The decisive check: the rejection is not a soft warning. One incident, one
    // live case, and the rejected attempt left no row behind.
    $firstIncidentId = (int)($decode($firstResponse)['data']['incident_id'] ?? 0);
    $liveOnIncident = $refunds->findRestrictedByIncident($firstIncidentId);

    $assert(
        '6.4 La avería queda con un único expediente vivo tras el rechazo',
        $firstIncidentId > 0
        && count($liveOnIncident) === 1
        && (int)$liveOnIncident[0]->getId() === (int)($firstReceipt['id'] ?? 0),
        'expedientes vivos: ' . count($liveOnIncident)
    );

    // A DIFFERENT consumer on the same broken machine is the legitimate case
    // RF-REF-11 must not break: five people can each lose a coin to one fault.
    $otherResponse = $dispatch('POST', '/api/qr/report', [], $claimBody($duplicateMachine->getCode(), '699 888 777', 'Marc Ruibal'));
    $otherReceipt = $decode($otherResponse)['data']['refund'] ?? [];

    $assert(
        '6.5 Otro consumidor sí abre su propia reclamación sobre la misma avería',
        $otherResponse->getStatusCode() >= 200 && $otherResponse->getStatusCode() < 300
        && (int)($otherReceipt['id'] ?? 0) > 0
        && (int)($otherReceipt['id'] ?? 0) !== (int)($firstReceipt['id'] ?? 0)
        && (string)($otherReceipt['tracking_token'] ?? '') !== $firstToken,
        'HTTP ' . $otherResponse->getStatusCode() . ' ' . json_encode($decode($otherResponse)['error'] ?? null)
    );

    $assert(
        '6.6 La segunda reclamación tiene token y PIN propios, no compartidos',
        $otherReceipt !== []
        && (string)($otherReceipt['tracking_token'] ?? '') !== $firstToken
        && (string)($otherReceipt['pickup_pin'] ?? '') !== (string)($firstReceipt['pickup_pin'] ?? ''),
        json_encode($otherReceipt)
    );

    // Once the first case is rejected it is terminal, and the claimant is
    // entitled to claim again instead of being locked out forever.
    $refunds->transitionStatus(
        (int)($firstReceipt['id'] ?? 0),
        RefundStatus::PENDING_INSPECTION,
        RefundStatus::REJECTED,
        []
    );

    $retryResponse = $dispatch('POST', '/api/qr/report', [], $claimBody($duplicateMachine->getCode(), '600111222'));
    $retryReceipt = $decode($retryResponse)['data']['refund'] ?? [];

    // ─────────────────────────────────────────────────────────────────────
    // 6.b Escrituras del MISMO teléfono que el primer normalizador no cubría.
    // Cada una de ellas abría un segundo expediente vivo y, por tanto, un
    // segundo desembolso sobre la misma avería.
    // ─────────────────────────────────────────────────────────────────────
    $liveBeforeVariants = count($refunds->findRestrictedByIncident($firstIncidentId));

    $writeVariants = [
        '6.8a' => ['+34 600 111 222', 'con el prefijo del país'],
        '6.8b' => ["600\u{00A0}111\u{00A0}222", 'con espacios duros (U+00A0)'],
        '6.8c' => ['600_111_222', 'con guiones bajos'],
        '6.8d' => ['0034600111222', 'con el prefijo internacional'],
    ];

    foreach ($writeVariants as $label => [$variant, $why]) {
        $variantResponse = $dispatch('POST', '/api/qr/report', [], $claimBody($duplicateMachine->getCode(), $variant));
        $variantError = $decode($variantResponse)['error'] ?? [];

        $assert(
            $label . ' El mismo teléfono escrito ' . $why . ' NO abre un segundo expediente',
            $variantResponse->getStatusCode() === 409
            && (string)($variantError['code'] ?? '') === 'DUPLICATE_REFUND_CLAIM',
            'HTTP ' . $variantResponse->getStatusCode() . ' ' . json_encode($variantError)
        );
    }

    // La avería tiene legítimamente más de un expediente vivo (otro consumidor
    // y una reapertura), así que lo que se comprueba es que las variantes NO
    // hayan añadido ninguno, no que quede uno solo.
    $assert(
        '6.8e Ninguna de las escrituras alternativas añade un expediente vivo',
        count($refunds->findRestrictedByIncident($firstIncidentId)) === $liveBeforeVariants,
        'antes: ' . $liveBeforeVariants . ' | después: ' . count($refunds->findRestrictedByIncident($firstIncidentId))
    );

    $assert(
        '6.7 Tras una desestimación el consumidor puede volver a reclamar',
        $retryResponse->getStatusCode() >= 200 && $retryResponse->getStatusCode() < 300
        && (int)($retryReceipt['id'] ?? 0) > 0
        && (int)($retryReceipt['id'] ?? 0) !== (int)($firstReceipt['id'] ?? 0),
        'HTTP ' . $retryResponse->getStatusCode() . ' ' . json_encode($decode($retryResponse)['error'] ?? null)
    );

} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-19 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
