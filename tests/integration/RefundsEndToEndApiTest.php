<?php

declare(strict_types=1);

/**
 * VendGuard — Prueba funcional E2E del módulo de Reintegros.
 *
 * Ejercita el flujo completo por la superficie HTTP REAL (AppRouter) contra
 * MariaDB real, dentro de una transacción que siempre se revierte.
 *
 * Cubre las tres correcciones que nacieron de esta misma prueba:
 * - RF-REF-08: reparto proporcional del efectivo recuperado entre varios
 *   reclamantes de una avería y tope agregado de liquidación por avería.
 * - RF-REF-04/09: dictamen por expediente sin bloqueo 409 y válvula de
 *   regularización de Coordinación para expedientes atascados.
 * - RF-REF-07: un expediente terminal no conserva importe pendiente de pago.
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
    } else {
        $failures++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
};

$locations = new PdoLocationRepository($pdo);
$machines = new PdoMachineRepository($pdo);
$incidents = new PdoIncidentRepository($pdo);
$refunds = new PdoRefundRequestRepository($pdo);
$users = new PdoUserRepository($pdo);
$refundService = new RefundManagementService($refunds);

$location = $locations->findBySiteCode('SEDE-BCN-01');
$technician = $users->findByEmail('jordi.ruta@vendguard.internal');
$coordinator = $users->findByEmail('coordinacion@vendguard.internal');

if ($location === null || $technician === null || $coordinator === null) {
    fwrite(STDERR, "Faltan semillas base.\n");
    exit(1);
}

$auth = new AuthService($locations, $users);
$siteToken = $auth->generateSiteToken($location);
$techToken = $auth->generateInternalToken($technician);
$coordToken = $auth->generateInternalToken($coordinator);

$router = AppRouter::create();

// `Request` recibe la query por separado de la ruta, así que el helper la
// extrae del propio path: sin esto, un `?stranded_only=1` se descartaría en
// silencio y la prueba afirmaría sobre una bandeja sin filtrar.
$dispatch = static function (string $method, string $path, ?array $body = null, ?array $headers = null) use ($router) {
    $query = [];
    $queryString = parse_url($path, PHP_URL_QUERY);
    if (is_string($queryString) && $queryString !== '') {
        parse_str($queryString, $query);
    }

    return $router->dispatch(new Request(
        $method,
        $path,
        $query,
        $body ?? [],
        $headers ?? ['content-type' => 'application/json']
    ));
};

$siteHeaders = ['authorization' => 'Bearer ' . $siteToken, 'content-type' => 'application/json'];
$techHeaders = ['authorization' => 'Bearer ' . $techToken, 'content-type' => 'application/json'];
$coordHeaders = ['authorization' => 'Bearer ' . $coordToken, 'content-type' => 'application/json'];

$decode = static fn ($response): array => json_decode((string)$response->getBody(), true) ?? [];
$errCode = static fn ($response): string => (string)($decode($response)['error']['code'] ?? '');

$makeMachine = static function (string $prefix) use ($machines, $location) {
    return $machines->create([
        'location_id' => $location->getId(),
        'code' => $prefix . strtoupper(bin2hex(random_bytes(3))),
        'model' => 'Sonda E2E refunds',
        'machine_type' => MachineType::HOT_DRINKS->value,
        'floor_wing' => 'Planta baja - Sonda',
        'notes' => 'Sonda funcional dentro de transacción reversible.',
    ]);
};

$techId = (int)$technician->getId();

$assignToRoute = static function (int $incidentId) use ($pdo, $techId): void {
    $pdo->prepare("
        UPDATE `incidents`
        SET `assigned_technician_id` = :tech_id,
            `status` = 'IN_PROGRESS',
            `assigned_at` = CURRENT_TIMESTAMP,
            `started_at` = CURRENT_TIMESTAMP
        WHERE `id` = :id
    ")->execute([':tech_id' => $techId, ':id' => $incidentId]);
};

$pdo->beginTransaction();

try {
    echo "======================================================================\n";
    echo " VendGuard: Test de Integración - RefundsEndToEndApiTest (Módulo 08)\n";
    echo "======================================================================\n\n";

    // ═══════════════════════════════════════════════════════════════════
    echo "--- ESCENARIO A: efectivo en mano ≤10 € (QR → dictamen → PIN) ---\n";
    // ═══════════════════════════════════════════════════════════════════

    $machineA = $makeMachine('SONDA-A-');

    $qr = $dispatch('POST', '/api/qr/report', [
        'machine_code' => $machineA->getCode(),
        'category' => 'PAYMENT_SYSTEM',
        'description' => 'Metí cinco euros y no entregó el producto ni el cambio.',
        'reporter_name' => 'Laura Sanitaria',
        'reporter_phone' => '600111222',
        'refund_requested' => true,
        'claimed_amount' => 5.00,
        'compensation_method' => 'EN_MANO_SEDE',
        'product_attempted' => 'Café con leche carril 2',
    ]);

    $receipt = $decode($qr)['data']['refund'] ?? [];
    $assert('A.1 El reporte QR abre el expediente (HTTP 201)', $qr->getStatusCode() === 201, 'HTTP ' . $qr->getStatusCode());
    $assert('A.2 El resguardo trae PIN de 4 dígitos', preg_match('/^[0-9]{4}$/', (string)($receipt['pickup_pin'] ?? '')) === 1);
    $assert('A.3 El resguardo trae token de 64 hex', preg_match('/^[0-9a-f]{64}$/', (string)($receipt['tracking_token'] ?? '')) === 1);
    $assert('A.4 El resguardo NO filtra IBAN ni teléfono (Art. V.4)',
        !array_key_exists('iban', $receipt) && !array_key_exists('bizum_phone', $receipt));

    $caseAId = (int)($receipt['id'] ?? 0);
    $pinA = (string)($receipt['pickup_pin'] ?? '');
    $incidentAId = (int)$pdo->query("SELECT incident_id FROM refund_requests WHERE id = {$caseAId}")->fetchColumn();
    $assignToRoute($incidentAId);

    $resolveA = $dispatch('POST', "/api/technician/incidents/{$incidentAId}/resolve", [
        'resolution_diagnosis' => 'Monedas atascadas en el embudo del selector mecánico de la máquina.',
        'resolution_action' => 'Desatasco del selector y limpieza completa de la canaleta de monedas.',
        'refund_inspection' => [
            'finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 5.00,
            'cash_custody_action' => 'LEFT_AT_RECEPTION',
            'receptionist_name' => 'Conserjería Planta Baja',
        ],
    ], $techHeaders);
    $assert('A.5 El dictamen técnico deja el efectivo en conserjería (HTTP 200)', $resolveA->getStatusCode() === 200,
        'HTTP ' . $resolveA->getStatusCode() . ' ' . (string)$resolveA->getBody());
    $assert('A.6 El expediente queda DEPOSITED_AT_RECEPTION',
        (string)$refunds->findById($caseAId)?->getStatus()->value === 'DEPOSITED_AT_RECEPTION');

    $listA = $dispatch('GET', '/api/location/refunds', null, $siteHeaders);
    $listARaw = (string)$listA->getBody();
    $assert('A.7 La conserjería ve el sobre pendiente', $listA->getStatusCode() === 200
        && str_contains($listARaw, (string)$caseAId));
    $assert('A.8 El listado de sede NO filtra iban/bizum/pin/token',
        !str_contains($listARaw, '"iban"') && !str_contains($listARaw, 'bizum_phone')
        && !str_contains($listARaw, 'pickup_pin') && !str_contains($listARaw, 'tracking_token'));

    $deliverA = $dispatch('POST', "/api/location/refunds/{$caseAId}/deliver", ['pickup_pin' => $pinA], $siteHeaders);
    $assert('A.9 La entrega con PIN correcto liquida en mano (HTTP 200)', $deliverA->getStatusCode() === 200,
        'HTTP ' . $deliverA->getStatusCode() . ' ' . (string)$deliverA->getBody());
    $assert('A.10 El expediente queda REFUNDED_IN_HAND',
        (string)$refunds->findById($caseAId)?->getStatus()->value === 'REFUNDED_IN_HAND');

    $deliverA2 = $dispatch('POST', "/api/location/refunds/{$caseAId}/deliver", ['pickup_pin' => $pinA], $siteHeaders);
    $assert('A.11 Una segunda entrega se rechaza con 409', $deliverA2->getStatusCode() === 409, 'HTTP ' . $deliverA2->getStatusCode());

    // ═══════════════════════════════════════════════════════════════════
    echo "\n--- ESCENARIO B: digital 12 € con déficit (visto bueno + pago) ---\n";
    // ═══════════════════════════════════════════════════════════════════

    $machineB = $makeMachine('SONDA-B-');
    $qrB = $dispatch('POST', '/api/qr/report', [
        'machine_code' => $machineB->getCode(),
        'category' => 'PAYMENT_SYSTEM',
        'description' => 'La máquina cobró con tarjeta doce euros y no entregó el producto.',
        'reporter_name' => 'Marc Ruibal',
        'reporter_phone' => '699888777',
        'refund_requested' => true,
        'claimed_amount' => 12.00,
        'compensation_method' => 'BIZUM',
        'bizum_phone' => '699888777',
        'product_attempted' => 'Sándwich mixto',
    ]);
    $receiptB = $decode($qrB)['data']['refund'] ?? [];
    $caseBId = (int)($receiptB['id'] ?? 0);
    $incidentBId = (int)$pdo->query("SELECT incident_id FROM refund_requests WHERE id = {$caseBId}")->fetchColumn();
    $assignToRoute($incidentBId);
    $assert('B.1 Expediente digital creado sin PIN (se liquida por caja central)',
        !isset($receiptB['pickup_pin']) || $receiptB['pickup_pin'] === null);

    $resolveB = $dispatch('POST', "/api/technician/incidents/{$incidentBId}/resolve", [
        'resolution_diagnosis' => 'Fallo del validador de tarjetas con saldo retenido en la placa.',
        'resolution_action' => 'Reinicio del validador y extracción del efectivo retenido en la máquina.',
        'refund_inspection' => [
            'finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 8.00,
            'cash_custody_action' => 'HELD_FOR_CENTRAL',
        ],
    ], $techHeaders);
    $assert('B.2 El dictamen eleva el caso a Coordinación por déficit (HTTP 200)', $resolveB->getStatusCode() === 200,
        'HTTP ' . $resolveB->getStatusCode() . ' ' . (string)$resolveB->getBody());
    $assert('B.3 El expediente queda REQUIRES_COORDINATOR_APPROVAL',
        (string)$refunds->findById($caseBId)?->getStatus()->value === 'REQUIRES_COORDINATOR_APPROVAL');

    $inboxB = $dispatch('GET', '/api/coordinator/refunds', null, $coordHeaders);
    $itemsB = $decode($inboxB)['data']['items'] ?? [];
    $rowB = null;
    foreach ($itemsB as $item) {
        if ((int)($item['id'] ?? 0) === $caseBId) { $rowB = $item; break; }
    }
    $assert('B.4 El expediente aparece en la bandeja de Coordinación', $rowB !== null);
    if ($rowB !== null) {
        $assert('B.5 La bandeja muestra el IBAN/Bizum SOLO a Coordinación', ($rowB['bizum_phone'] ?? null) === '699888777');
        $assert('B.6 El ratio de discrepancia es > 0 (8 vs 12)', (float)($rowB['discrepancy_ratio'] ?? 0) > 0.0,
            'ratio=' . var_export($rowB['discrepancy_ratio'] ?? null, true));
        $assert('B.7 Marca supervisión especial / requiere visto bueno', ($rowB['requires_approval'] ?? false) === true);
        $assert('B.8 payable_amount = reclamado (12,00 €) mientras no hay visto bueno',
            abs((float)($rowB['payable_amount'] ?? 0) - 12.00) < 0.001,
            'payable=' . var_export($rowB['payable_amount'] ?? null, true));
        $assert('B.9 La bandeja NO filtra el PIN ni el token', !str_contains((string)$inboxB->getBody(), 'pickup_pin')
            && !str_contains((string)$inboxB->getBody(), 'tracking_token'));
    }

    $approveB = $dispatch('POST', "/api/coordinator/refunds/{$caseBId}/approve", [
        'approved_amount' => 8.00,
        'notes' => 'Verificado el histórico de ventas; se autoriza la devolución de lo recuperado.',
    ], $coordHeaders);
    $assert('B.10 El visto bueno se registra (HTTP 200)', $approveB->getStatusCode() === 200,
        'HTTP ' . $approveB->getStatusCode() . ' ' . (string)$approveB->getBody());
    $assert('B.11 Tras el visto bueno queda VERIFIED_PENDING_PAYMENT',
        (string)$refunds->findById($caseBId)?->getStatus()->value === 'VERIFIED_PENDING_PAYMENT');

    $badPay = $dispatch('POST', "/api/coordinator/refunds/{$caseBId}/pay", [
        'payment_reference' => 'BIZUM-2026-TEST-0001',
        'paid_amount' => 12.00,
    ], $coordHeaders);
    $assert('B.12 Liquidar una cifra distinta de la aprobada se rechaza (422)', $badPay->getStatusCode() === 422,
        'HTTP ' . $badPay->getStatusCode() . ' ' . (string)$badPay->getBody());

    $payB = $dispatch('POST', "/api/coordinator/refunds/{$caseBId}/pay", [
        'payment_reference' => 'BIZUM-2026-TEST-0001',
        'paid_amount' => 8.00,
    ], $coordHeaders);
    $assert('B.13 El pago con la cifra exacta se registra (HTTP 200)', $payB->getStatusCode() === 200,
        'HTTP ' . $payB->getStatusCode() . ' ' . (string)$payB->getBody());
    $assert('B.14 El expediente queda PAID_DIGITAL',
        (string)$refunds->findById($caseBId)?->getStatus()->value === 'PAID_DIGITAL');

    // ═══════════════════════════════════════════════════════════════════
    echo "\n--- ESCENARIO C: dos reclamantes en la MISMA avería (RF-REF-08) ---\n";
    // ═══════════════════════════════════════════════════════════════════

    $machineC = $makeMachine('SONDA-C-');
    $incidentC = $incidents->create(new Incident(
        id: null,
        ticketCode: 'SONDA-C-' . strtoupper(bin2hex(random_bytes(3))),
        machineId: (int)$machineC->getId(),
        locationId: (int)$location->getId(),
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'La máquina acepta la moneda y no entrega el producto.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));
    $assignToRoute((int)$incidentC->getId());

    $caseC1 = $refundService->createCase(new CreateRefundRequestDTO(
        incidentId: (int)$incidentC->getId(),
        machineId: (int)$machineC->getId(),
        locationId: (int)$location->getId(),
        claimantName: 'Consumidor Uno',
        claimantContact: '611111111',
        claimedAmount: 3.00,
        compensationMethod: CompensationMethod::EN_MANO_SEDE,
        productAttempted: 'Agua'
    ));
    $caseC2 = $refundService->createCase(new CreateRefundRequestDTO(
        incidentId: (int)$incidentC->getId(),
        machineId: (int)$machineC->getId(),
        locationId: (int)$location->getId(),
        claimantName: 'Consumidor Dos',
        claimantContact: '622222222',
        claimedAmount: 2.00,
        compensationMethod: CompensationMethod::EN_MANO_SEDE,
        productAttempted: 'Zumo'
    ));
    $assert('C.1 Dos consumidores distintos abren expediente sobre la misma avería',
        $caseC1->getId() !== null && $caseC2->getId() !== null);

    $resolveC = $dispatch('POST', "/api/technician/incidents/{$incidentC->getId()}/resolve", [
        'resolution_diagnosis' => 'Monedas atascadas en la canaleta del selector de la máquina.',
        'resolution_action' => 'Desatasco del selector y limpieza de la canaleta de monedas.',
        'refund_inspection' => [
            'finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 3.00,
            'cash_custody_action' => 'LEFT_AT_RECEPTION',
            'receptionist_name' => 'Conserjería Planta Baja',
        ],
    ], $techHeaders);
    $assert('C.2 El dictamen único procesa ambos expedientes (HTTP 200)', $resolveC->getStatusCode() === 200,
        'HTTP ' . $resolveC->getStatusCode() . ' ' . (string)$resolveC->getBody());

    $statusC1 = (string)$refunds->findById((int)$caseC1->getId())?->getStatus()->value;
    $statusC2 = (string)$refunds->findById((int)$caseC2->getId())?->getStatus()->value;
    $assert('C.3 El déficit eleva ambos a Coordinación (RF-REF-08)',
        $statusC1 === 'REQUIRES_COORDINATOR_APPROVAL' && $statusC2 === 'REQUIRES_COORDINATOR_APPROVAL',
        "C1={$statusC1} C2={$statusC2}");

    // ── C.3b DEFECTO: recovered_amount guardado por expediente = total de la avería
    $storedC1 = (float)$pdo->query("SELECT recovered_amount FROM refund_requests WHERE id = " . (int)$caseC1->getId())->fetchColumn();
    $storedC2 = (float)$pdo->query("SELECT recovered_amount FROM refund_requests WHERE id = " . (int)$caseC2->getId())->fetchColumn();
    $ratioC2 = $refunds->findById((int)$caseC2->getId())?->discrepancyRatio();
    echo "    [INFO] C1 reclamado=3.00 recuperado={$storedC1}; C2 reclamado=2.00 recuperado={$storedC2} (ratio C2="
        . var_export($ratioC2, true) . ")
";
    $assert('C.3b Reparto proporcional: C1=1.80 y C2=1.20 sobre 3.00',
        $storedC1 === 1.80 && $storedC2 === 1.20,
        "C1={$storedC1} C2={$storedC2}");
    $assert('C.3b2 La suma de las partes es EXACTAMENTE el recuperado (3.00)',
        abs(($storedC1 + $storedC2) - 3.00) < 0.0001,
        'suma=' . ($storedC1 + $storedC2));
    $assert('C.3c El 2o reclamante ya no muestra discrepancia falsa (su parte, no el total)',
        $ratioC2 !== null && abs($ratioC2 - 0.4) < 0.001,
        'ratio=' . var_export($ratioC2, true));

    // ── C.3d RIESGO: ¿puede Coordinación pagar más que el efectivo recuperado?
    $approveC1 = $dispatch('POST', "/api/coordinator/refunds/" . (int)$caseC1->getId() . "/approve", [
        'approved_amount' => 3.00,
        'notes' => 'Se autoriza la devolución íntegra reclamada por el primer consumidor de la avería.',
    ], $coordHeaders);
    $approveC2 = $dispatch('POST', "/api/coordinator/refunds/" . (int)$caseC2->getId() . "/approve", [
        'approved_amount' => 2.00,
        'notes' => 'Se autoriza la devolución íntegra reclamada por el segundo consumidor de la avería.',
    ], $coordHeaders);
    $payC1 = $dispatch('POST', "/api/coordinator/refunds/" . (int)$caseC1->getId() . "/pay", [
        'payment_reference' => 'BIZUM-SONDA-C1',
        'paid_amount' => 3.00,
    ], $coordHeaders);
    $payC2 = $dispatch('POST', "/api/coordinator/refunds/" . (int)$caseC2->getId() . "/pay", [
        'payment_reference' => 'BIZUM-SONDA-C2',
        'paid_amount' => 2.00,
    ], $coordHeaders);
    $paidC1 = (float)$pdo->query("SELECT paid_amount FROM refund_requests WHERE id = " . (int)$caseC1->getId())->fetchColumn();
    $paidC2 = (float)$pdo->query("SELECT paid_amount FROM refund_requests WHERE id = " . (int)$caseC2->getId())->fetchColumn();
    echo "    [INFO] recuperado real=3.00; pagado C1={$paidC1} C2={$paidC2} (total=" . ($paidC1 + $paidC2) . ")
";
    $assert('C.3d Tope agregado: no se liquida mas que el efectivo recuperado de la averia',
        $approveC1->getStatusCode() === 200 && $payC1->getStatusCode() === 200
        && $approveC2->getStatusCode() === 422
        && ($paidC1 + $paidC2) <= 3.00,
        'approveC1=' . $approveC1->getStatusCode() . ' approveC2=' . $approveC2->getStatusCode()
        . ' payC1=' . $payC1->getStatusCode() . ' payC2=' . $payC2->getStatusCode()
        . ' total=' . ($paidC1 + $paidC2));

    // ── C.4 EL DEFECTO CONOCIDO: dictaminado + pendiente en la misma avería
    $machineD = $makeMachine('SONDA-D-');
    $incidentD = $incidents->create(new Incident(
        id: null,
        ticketCode: 'SONDA-D-' . strtoupper(bin2hex(random_bytes(3))),
        machineId: (int)$machineD->getId(),
        locationId: (int)$location->getId(),
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'La máquina acepta la moneda y no entrega el producto.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));
    $assignToRoute((int)$incidentD->getId());

    $caseD1 = $refundService->createCase(new CreateRefundRequestDTO(
        incidentId: (int)$incidentD->getId(),
        machineId: (int)$machineD->getId(),
        locationId: (int)$location->getId(),
        claimantName: 'Consumidor Dictaminado',
        claimantContact: '633333333',
        claimedAmount: 4.00,
        compensationMethod: CompensationMethod::EN_MANO_SEDE,
        productAttempted: 'Café'
    ));

    $resolveD1 = $dispatch('POST', "/api/technician/incidents/{$incidentD->getId()}/resolve", [
        'resolution_diagnosis' => 'Primera intervención: se recupera el efectivo atascado del selector.',
        'resolution_action' => 'Desatasco del selector y limpieza de la canaleta de la máquina.',
        'refund_inspection' => [
            'finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 4.00,
            'cash_custody_action' => 'LEFT_AT_RECEPTION',
            'receptionist_name' => 'Conserjería Planta Baja',
        ],
    ], $techHeaders);
    $assert('C.4 La primera intervención se resuelve (HTTP 200)', $resolveD1->getStatusCode() === 200,
        'HTTP ' . $resolveD1->getStatusCode() . ' ' . (string)$resolveD1->getBody());

    // Reapertura dentro de la ventana de 48 h (Art. V.6): la avería vuelve a
    // IN_PROGRESS y el técnico la atiende de nuevo.
    $pdo->prepare("
        UPDATE `incidents`
        SET `status` = 'IN_PROGRESS',
            `resolved_at` = NULL,
            `assigned_technician_id` = :tech_id,
            `started_at` = CURRENT_TIMESTAMP
        WHERE `id` = :id
    ")->execute([':tech_id' => $techId, ':id' => (int)$incidentD->getId()]);

    // Un segundo consumidor abre expediente tras la reapertura.
    $caseD2 = $refundService->createCase(new CreateRefundRequestDTO(
        incidentId: (int)$incidentD->getId(),
        machineId: (int)$machineD->getId(),
        locationId: (int)$location->getId(),
        claimantName: 'Consumidor Tardío',
        claimantContact: '644444444',
        claimedAmount: 5.00,
        compensationMethod: CompensationMethod::EN_MANO_SEDE,
        productAttempted: 'Snack'
    ));
    $statusD1 = (string)$refunds->findById((int)$caseD1->getId())?->getStatus()->value;
    $statusD2 = (string)$refunds->findById((int)$caseD2->getId())?->getStatus()->value;

    $resolveD2 = $dispatch('POST', "/api/technician/incidents/{$incidentD->getId()}/resolve", [
        'resolution_diagnosis' => 'Segunda intervención sobre la misma avería reabierta del parque.',
        'resolution_action' => 'Revisión del selector y verificación del mecanismo de devolución.',
        'refund_inspection' => [
            'finding' => 'CONFIRMED_NO_CASH',
            'cash_custody_action' => 'HELD_FOR_CENTRAL',
        ],
    ], $techHeaders);

    echo "    [INFO] D1={$statusD1} D2={$statusD2}; segundo resolve -> HTTP "
        . $resolveD2->getStatusCode() . ' ' . $errCode($resolveD2) . "\n";

    $assert('C.5 Sin bloqueo: el tecnico dictamina el pendiente sin 409',
        $resolveD2->getStatusCode() === 200,
        'HTTP ' . $resolveD2->getStatusCode() . ' ' . $errCode($resolveD2));
    $statusD2After = (string)$refunds->findById((int)$caseD2->getId())?->getStatus()->value;
    $assert('C.6 El expediente ya dictaminado se preserva inmutable (D1 intacto)',
        (string)$refunds->findById((int)$caseD1->getId())?->getStatus()->value === 'DEPOSITED_AT_RECEPTION',
        'D1=' . (string)$refunds->findById((int)$caseD1->getId())?->getStatus()->value);
    $assert('C.7 El expediente pendiente D2 queda dictaminado',
        $statusD2After !== 'PENDING_INSPECTION', "D2={$statusD2After}");

    // ── C.8 Válvula de Coordinación: regularizar un expediente atascado
    $machineE = $makeMachine('SONDA-E-');
    $incidentE = $incidents->create(new Incident(
        id: null,
        ticketCode: 'SONDA-E-' . strtoupper(bin2hex(random_bytes(3))),
        machineId: (int)$machineE->getId(),
        locationId: (int)$location->getId(),
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'Aviso cancelado por falsa alarma con reclamacion viva.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));
    $caseE = $refundService->createCase(new CreateRefundRequestDTO(
        incidentId: (int)$incidentE->getId(),
        machineId: (int)$machineE->getId(),
        locationId: (int)$location->getId(),
        claimantName: 'Consumidor Atascado',
        claimantContact: '655555555',
        claimedAmount: 6.00,
        compensationMethod: CompensationMethod::EN_MANO_SEDE,
        productAttempted: 'Bocadillo'
    ));
    $regularize = $dispatch('POST', '/api/coordinator/refunds/' . (int)$caseE->getId() . '/regularize', [
        'finding' => 'UNVERIFIED_NO_CASH',
        'cash_custody_action' => 'HELD_FOR_CENTRAL',
        'justification' => 'Averia cancelada por falsa alarma; se dictamina sin evidencia de saldo retenido.',
    ], $coordHeaders);
    $assert('C.8 Coordinacion regulariza un expediente atascado (HTTP 200)', $regularize->getStatusCode() === 200,
        'HTTP ' . $regularize->getStatusCode() . ' ' . (string)$regularize->getBody());
    $assert('C.9 El expediente regularizado sale de PENDING_INSPECTION',
        (string)$refunds->findById((int)$caseE->getId())?->getStatus()->value !== 'PENDING_INSPECTION',
        'E=' . (string)$refunds->findById((int)$caseE->getId())?->getStatus()->value);
    $regularizeAgain = $dispatch('POST', '/api/coordinator/refunds/' . (int)$caseE->getId() . '/regularize', [
        'finding' => 'CONFIRMED_NO_CASH',
    ], $coordHeaders);
    $assert('C.10 Regularizar dos veces se rechaza con 409',
        $regularizeAgain->getStatusCode() === 409 && $errCode($regularizeAgain) === 'INVALID_REFUND_STATE_TRANSITION',
        'HTTP ' . $regularizeAgain->getStatusCode() . ' ' . $errCode($regularizeAgain));

    // ═══════════════════════════════════════════════════════════════════
    echo "\n--- ESCENARIO D: proyección de terminales y totales ---\n";
    // ═══════════════════════════════════════════════════════════════════

    $inboxD = $dispatch('GET', '/api/coordinator/refunds', null, $coordHeaders);
    $itemsD = $decode($inboxD)['data']['items'] ?? [];

    $terminalWithDiscrepancy = [];
    foreach ($itemsD as $item) {
        if (in_array($item['status'] ?? '', ['PAID_DIGITAL', 'REFUNDED_IN_HAND', 'REJECTED'], true)) {
            if ((float)($item['payable_amount'] ?? 0) !== 0.0) {
                $terminalWithDiscrepancy[] = $item['id'] . '=' . $item['payable_amount'];
            }
        }
    }
    $assert('D.1 Ningún expediente terminal conserva importe "a pagar" > 0',
        $terminalWithDiscrepancy === [],
        implode(', ', $terminalWithDiscrepancy));

    $rowA = null;
    foreach ($itemsD as $item) {
        if ((int)($item['id'] ?? 0) === $caseAId) { $rowA = $item; break; }
    }
    $assert('D.2 El caso reembolsado en mano tiene payable_amount = 0',
        $rowA !== null && (float)($rowA['payable_amount'] ?? -1) === 0.0,
        'payable=' . var_export($rowA['payable_amount'] ?? null, true));

    $discrepancyZeroRatio = $rowA['discrepancy_ratio'] ?? null;
    $assert('D.4 Un ratio 0 no debe producir aviso de discrepancia en la UI',
        $discrepancyZeroRatio === null || (float)$discrepancyZeroRatio <= 0.0001,
        'ratio=' . var_export($discrepancyZeroRatio, true));

    // ═══════════════════════════════════════════════════════════════════
    echo "\n--- ESCENARIO E: filtro de atascados en inspeccion ---\n";
    // ═══════════════════════════════════════════════════════════════════

    // El caso E ya quedo regularizado en C.9 (sale de PENDING_INSPECTION), asi
    // que el atascado de esta prueba se construye aparte: una averia cancelada
    // con la reclamacion viva (RF-REF-09), que es exactamente lo que el filtro
    // debe localizar.
    $machineF = $makeMachine('SONDA-F-');
    $incidentF = $incidents->create(new Incident(
        id: null,
        ticketCode: 'SONDA-F-' . strtoupper(bin2hex(random_bytes(3))),
        machineId: (int)$machineF->getId(),
        locationId: (int)$location->getId(),
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'Averia cancelada por falsa alarma con reclamacion viva.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));
    $pdo->prepare("UPDATE incidents SET status = 'CANCELLED', is_active_ticket = 0 WHERE id = :id")
        ->execute([':id' => (int)$incidentF->getId()]);

    $caseF = $refundService->createCase(new CreateRefundRequestDTO(
        incidentId: (int)$incidentF->getId(),
        machineId: (int)$machineF->getId(),
        locationId: (int)$location->getId(),
        claimantName: 'Consumidor Atascado Filtro',
        claimantContact: '677777777',
        claimedAmount: 7.00,
        compensationMethod: CompensationMethod::EN_MANO_SEDE,
        productAttempted: 'Zumo'
    ));

    $stranded = $decode($dispatch('GET', '/api/coordinator/refunds', null, $coordHeaders));
    $strandedData = $stranded['data'] ?? [];
    $assert('E.1 El contador stranded_total sale de la respuesta',
        array_key_exists('stranded_total', $strandedData),
        'keys: ' . implode(',', array_keys($strandedData)));
    $assert('E.2 El caso atascado se cuenta como stranded',
        (int)($strandedData['stranded_total'] ?? 0) >= 1,
        'stranded_total=' . var_export($strandedData['stranded_total'] ?? null, true));

    // El atajo debe devolver SOLO atascados, y todos en PENDING_INSPECTION.
    $onlyStranded = $decode($dispatch('GET', '/api/coordinator/refunds?stranded_only=1', null, $coordHeaders));
    $strandedIds = array_map(static fn (array $item): int => (int)$item['id'], $onlyStranded['data']['items'] ?? []);
    $strandedStatuses = array_unique(array_map(
        static fn (array $item): string => (string)$item['status'],
        $onlyStranded['data']['items'] ?? []
    ));
    $assert('E.3 stranded_only=1 incluye el caso atascado', in_array((int)$caseF->getId(), $strandedIds, true),
        'ids=' . implode(',', $strandedIds));
    $assert('E.4 stranded_only=1 solo devuelve expedientes pendientes de inspeccion',
        $strandedStatuses === ['PENDING_INSPECTION'],
        'estados=' . implode(',', $strandedStatuses));
    $assert('E.5 stranded_only=1 no devuelve el expediente de averia viva',
        !in_array((int)$caseAId, $strandedIds, true));

    // El estado de la AVERIA viaja en cada item: sin el, la interfaz no puede
    // marcar la fila como atascada sin una consulta extra por expediente.
    $strandedItemStatus = null;
    foreach ($onlyStranded['data']['items'] ?? [] as $item) {
        if ((int)$item['id'] === (int)$caseF->getId()) {
            $strandedItemStatus = $item['incident_status'] ?? null;
        }
    }
    $assert('E.5b La proyeccion expone el estado terminal de la averia en el item atascado',
        $strandedItemStatus === 'CANCELLED',
        'incident_status=' . var_export($strandedItemStatus, true));

    // Coherencia con el conteo directo en SQL: el filtro no puede inventar filas.
    $directCount = (int)$pdo->query("
        SELECT COUNT(*) FROM refund_requests r
        JOIN incidents i ON i.id = r.incident_id
        WHERE r.is_active = 1 AND r.status = 'PENDING_INSPECTION'
          AND i.status IN ('CLOSED','CANCELLED')
    ")->fetchColumn();
    $assert('E.6 stranded_total coincide con el COUNT directo en SQL',
        $directCount === (int)($onlyStranded['data']['total'] ?? -1),
        "directo={$directCount} api=" . var_export($onlyStranded['data']['total'] ?? null, true));

    // Un estado de averia desconocido se rechaza en vez de ignorarse.
    $badIncidentStatus = $dispatch('GET', '/api/coordinator/refunds?incident_status=NOPE', null, $coordHeaders);
    $assert('E.7 Un incident_status desconocido se rechaza con 422',
        $badIncidentStatus->getStatusCode() === 422
        && $errCode($badIncidentStatus) === 'INVALID_REFUND_FILTER',
        'HTTP ' . $badIncidentStatus->getStatusCode() . ' ' . $errCode($badIncidentStatus));

    // El atajo y el de visto bueno responden preguntas distintas y no deben
    // anularse: activar uno deja el contador del otro intacto.
    $approvalInbox = $decode($dispatch('GET', '/api/coordinator/refunds?requires_approval_only=1', null, $coordHeaders));
    $assert('E.8 El contador de atascados no colapsa al filtrar por visto bueno',
        (int)($approvalInbox['data']['stranded_total'] ?? 0) >= 1,
        'stranded_total=' . var_export($approvalInbox['data']['stranded_total'] ?? null, true));

    echo "\n";
} catch (\Throwable $e) {
    $failures++;
    echo "  [FAIL] EXCEPCIÓN NO CONTROLADA: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "======================================================================\n";
echo " RefundsEndToEndApiTest — Total Aserciones: {$assertions} | Fallos: {$failures}\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
