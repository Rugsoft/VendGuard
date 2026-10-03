<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Integration suite for the reception desk handover (T-REF-21).
 *
 * It exercises the site-facing endpoints through the real `AppRouter` and real
 * MariaDB, exactly as the concierge reaches them on a shared terminal at the
 * counter (RF-REF-06, RF-REF-10, RNF-REF-05):
 *
 * 1. `GET /api/location/refunds`, which must list the envelopes waiting on the
 *    counter with the claimant anonymized, sort the ones ready for pickup to
 *    the top, and never project the IBAN, the Bizum phone, the pickup PIN or
 *    the tracking token (Art. V.4, RNF-REF-03).
 * 2. `POST /api/location/refunds/{id}/deliver` with the correct four-digit PIN,
 *    which must move the case to `REFUNDED_IN_HAND`, stamp the handover time
 *    and leave an immutable audit trail.
 * 3. The same endpoint with a wrong PIN, which must be refused with
 *    `422 INVALID_PICKUP_PIN` while leaving the cash exactly where it was, so
 *    the consumer can still come back and try again.
 * 4. The site segregation: a case that belongs to another building answers
 *    `403 SITE_MISMATCH` and never shows up in someone else's list.
 *
 * The cash waiting at the desk is not stubbed into place: every envelope is put
 * there through the real technician verdict endpoint first, so the suite walks
 * the same journey the system actually produces (consumer claim -> technician
 * deposits the envelope -> concierge releases it).
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

use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RefundStatus;
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

echo "======================================================================\n";
echo " VendGuard: Test de Integración - LocationRefundDeliveryApiTest (T-REF-21)\n";
echo "======================================================================\n";

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
    $incidents = new PdoIncidentRepository($pdo);
    $refunds = new PdoRefundRequestRepository($pdo);
    $users = new PdoUserRepository($pdo);
    $refundService = new RefundManagementService($refunds);

    $location = $locations->findBySiteCode('SEDE-BCN-01');
    $otherLocation = $locations->findBySiteCode('SEDE-BCN-02');
    $assert(
        '0.1 Las dos sedes semilla existen',
        $location !== null && $otherLocation !== null
    );
    if ($location === null || $otherLocation === null) {
        throw new RuntimeException('No hay sedes semilla para la prueba de conserjería.');
    }

    $technician = $users->findByEmail('jordi.ruta@vendguard.internal');
    $assert('0.2 El técnico de ruta semilla existe', $technician !== null);
    if ($technician === null) {
        throw new RuntimeException('No hay técnico semilla para la prueba de conserjería.');
    }

    $techId = (int)$technician->getId();
    $authService = new AuthService($locations, $users);
    $techToken = $authService->generateInternalToken($technician);
    $siteToken = $authService->generateSiteToken($location);
    $otherSiteToken = $authService->generateSiteToken($otherLocation);

    $router = AppRouter::create();
    $routerSource = (string)file_get_contents(__DIR__ . '/../../src/Presentation/Routing/AppRouter.php');

    $siteHeaders = ['authorization' => 'Bearer ' . $siteToken, 'content-type' => 'application/json'];
    $otherSiteHeaders = ['authorization' => 'Bearer ' . $otherSiteToken, 'content-type' => 'application/json'];
    $techHeaders = ['authorization' => 'Bearer ' . $techToken, 'content-type' => 'application/json'];

    /**
     * @param array<string, mixed>|null $body
     */
    $dispatch = static function (
        string $method,
        string $path,
        ?array $body = null,
        ?array $headers = null
    ) use ($router): \VendGuard\Presentation\Http\Response {
        return $router->dispatch(new Request(
            $method,
            $path,
            [],
            $body ?? [],
            $headers ?? ['content-type' => 'application/json']
        ));
    };

    $errorCode = static function ($response): string {
        return (string)($response->getDecodedBody()['error']['code'] ?? '');
    };

    $data = static function ($response): array {
        $data = $response->getDecodedBody()['data'] ?? [];

        return is_array($data) ? $data : [];
    };

    $makeMachine = static function (string $prefix, $site) use ($machines) {
        return $machines->create([
            'location_id' => $site->getId(),
            'code' => $prefix . strtoupper(bin2hex(random_bytes(3))),
            'model' => 'VendGuard reception desk machine',
            'machine_type' => MachineType::HOT_DRINKS->value,
            'floor_wing' => 'Planta baja - Conserjería',
            'notes' => 'Máquina creada dentro de la transacción reversible de T-REF-21.',
        ]);
    };

    $makeIncident = static function ($machine, $site, int $techId, string $prefix) use ($incidents, $pdo): Incident {
        $created = $incidents->create(new Incident(
            id: null,
            ticketCode: $prefix . strtoupper(bin2hex(random_bytes(3))),
            machineId: (int)$machine->getId(),
            locationId: (int)$site->getId(),
            category: IncidentCategory::PAYMENT_SYSTEM,
            description: 'La máquina acepta la moneda y no entrega el producto.',
            urgency: UrgencyLevel::HIGH,
            status: IncidentStatus::REGISTERED,
            assignedTechnicianId: null,
            createdAt: null
        ));

        $pdo->prepare('
            UPDATE `incidents`
            SET `status` = :status,
                `assigned_technician_id` = :tech_id,
                `assigned_at` = CURRENT_TIMESTAMP,
                `started_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id
        ')->execute([
            ':status' => IncidentStatus::IN_PROGRESS->value,
            ':tech_id' => $techId,
            ':id' => (int)$created->getId(),
        ]);

        return $incidents->findById((int)$created->getId());
    };

    // Puts a real envelope on the counter: the consumer claims in cash, the
    // assigned technician rules on the money and leaves it at reception. The
    // PIN only exists because the claimant chose `EN_MANO_SEDE` (RF-REF-02).
    $buildDeskCase = static function (
        $machine,
        $site,
        int $techId,
        string $prefix,
        string $claimantName,
        float $amount,
        array $techHeaders,
        $dispatch,
        $makeIncident
    ) use ($refundService): array {
        $incident = $makeIncident($machine, $site, $techId, $prefix);

        $case = $refundService->createCase(new CreateRefundRequestDTO(
            incidentId: (int)$incident->getId(),
            machineId: (int)$machine->getId(),
            locationId: (int)$site->getId(),
            claimantName: $claimantName,
            claimantContact: '600111222',
            claimedAmount: $amount,
            compensationMethod: CompensationMethod::EN_MANO_SEDE,
            productAttempted: 'Café con leche carril 2'
        ));

        // El técnico declara el efectivo encontrado y lo deja en el mostrador.
        $resolution = $dispatch('POST', "/api/technician/incidents/{$incident->getId()}/resolve", [
            'resolution_diagnosis' => 'Moneda de dos euros atascada en el embudo del selector mecánico.',
            'resolution_action' => 'Desatasco del selector y limpieza de la canaleta de la máquina.',
            'refund_inspection' => [
                'finding' => 'FOUND_PHYSICAL',
                'recovered_amount' => $amount,
                'cash_custody_action' => 'LEFT_AT_RECEPTION',
                'receptionist_name' => 'Laura Sanitaria (Conserjería Planta Baja)',
            ],
        ], $techHeaders);

        if ($resolution->getStatusCode() !== 200) {
            throw new RuntimeException(
                'No se pudo dejar el sobre en conserjería: HTTP ' . $resolution->getStatusCode()
                . ' ' . (string)$resolution->getBody()
            );
        }

        return [(int)$case->getId(), (string)$case->getPickupPin(), $incident];
    };

    $deskMachine = $makeMachine('TREF21A-', $location);
    $secondDeskMachine = $makeMachine('TREF21B-', $location);
    $otherSiteMachine = $makeMachine('TREF21C-', $otherLocation);
    // Tercera máquina de la misma sede: el escenario de fuerza bruta necesita
    // su propio expediente, porque cada sobre lleva su propio PIN.
    $thirdDeskMachine = $makeMachine('TREF21D-', $location);

    $assert(
        '0.3 Las tres máquinas de prueba se crean dentro de la transacción',
        $deskMachine !== null && $secondDeskMachine !== null && $otherSiteMachine !== null
    );

    $assert(
        '0.4 El token de sede se emite con el prefijo propio y es distinto del interno',
        $siteToken !== '' && $otherSiteToken !== '' && $siteToken !== $techToken
    );

    $assert(
        '0.5 Las rutas de conserjería siguen registradas con SiteAuthMiddleware',
        str_contains($routerSource, "\$router->get('/api/location/refunds'")
        && str_contains($routerSource, "\$router->post('/api/location/refunds/{id}/deliver'")
        && str_contains($routerSource, '$siteAuth')
    );

    // Dos sobres en SEDE-BCN-01 y uno en SEDE-BCN-02, los tres realmente
    // depositados por el técnico a través del endpoint de dictamen.
    [$readyCaseId, $readyPin] = $buildDeskCase(
        $deskMachine,
        $location,
        $techId,
        'TREF21-READY-',
        'Laura Sanitaria',
        2.50,
        $techHeaders,
        $dispatch,
        $makeIncident
    );

    [$secondCaseId, $secondPin] = $buildDeskCase(
        $secondDeskMachine,
        $location,
        $techId,
        'TREF21-SECOND-',
        'Marc Ruibal Prat',
        4.00,
        $techHeaders,
        $dispatch,
        $makeIncident
    );

    [$foreignCaseId] = $buildDeskCase(
        $otherSiteMachine,
        $otherLocation,
        $techId,
        'TREF21-FOREIGN-',
        'Sara Puig Serra',
        1.75,
        $techHeaders,
        $dispatch,
        $makeIncident
    );

    $assert(
        '0.6 Los tres sobres quedan esperando en conserjería con su PIN de 4 dígitos',
        $refunds->findById($readyCaseId)?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
        && $refunds->findById($secondCaseId)?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
        && $refunds->findById($foreignCaseId)?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
    );
    $assert(
        '0.6 El PIN se emite con exactamente 4 dígitos para la retirada presencial',
        preg_match('/^[0-9]{4}$/', $readyPin) === 1
        && preg_match('/^[0-9]{4}$/', $secondPin) === 1
        && $readyPin !== $secondPin
    );

    $refundRowsBefore = (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests`')->fetchColumn();

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Listado anonimizado de conserjería (RF-REF-06, RF-REF-10, Art. V.4) ---\n";

    $anonymous = $dispatch('GET', '/api/location/refunds');
    $assert(
        '1.1 Sin token ni cabecera de sede la conserjería no ve nada',
        $anonymous->getStatusCode() === 401 && $errorCode($anonymous) === 'UNAUTHORIZED',
        "HTTP {$anonymous->getStatusCode()} " . json_encode($errorCode($anonymous))
    );

    $forgedToken = $dispatch('GET', '/api/location/refunds', null, [
        'authorization' => 'Bearer sede_falsa_falsa_falsa',
        'content-type' => 'application/json',
    ]);
    $assert(
        '1.2 Un token de sede inválido o manipulado responde 401',
        $forgedToken->getStatusCode() === 401 && $errorCode($forgedToken) === 'UNAUTHORIZED',
        "HTTP {$forgedToken->getStatusCode()} " . json_encode($errorCode($forgedToken))
    );

    $headerOnly = $dispatch('GET', '/api/location/refunds', null, [
        'x-site-code' => $location->getSiteCode(),
        'content-type' => 'application/json',
    ]);
    $assert(
        '1.3 La cabecera X-Site-Code también abre la conserjería de su propio centro',
        $headerOnly->getStatusCode() === 200 && (int)($data($headerOnly)['total'] ?? 0) >= 2,
        "HTTP {$headerOnly->getStatusCode()} " . json_encode($data($headerOnly))
    );

    $listing = $dispatch('GET', '/api/location/refunds', null, $siteHeaders);
    $listingData = $data($listing);
    $assert('1.4 El conserje autenticado lee el listado de su sede con HTTP 200', $listing->getStatusCode() === 200);

    $refundsInList = $listingData['refunds'] ?? [];
    $listedIds = array_map(static fn (array $r): int => (int)($r['id'] ?? 0), $refundsInList);
    $assert(
        '1.4 El listado incluye los sobres de su sede y cuenta los listos para recoger',
        in_array($readyCaseId, $listedIds, true)
        && in_array($secondCaseId, $listedIds, true)
        && (int)($listingData['ready_for_pickup_total'] ?? 0)
            === count(array_filter(
                $refundsInList,
                static fn (array $r): bool => ($r['ready_for_pickup'] ?? false) === true
            ))
    );

    $readyRow = [];
    foreach ($refundsInList as $row) {
        if ((int)($row['id'] ?? 0) === $readyCaseId) {
            $readyRow = $row;
        }
    }
    $assert(
        '1.5 El sobre se identifica con el nombre anonimizado del reclamante (Art. V.4)',
        ($readyRow['claimant_name_anon'] ?? '') === 'Laura S.',
        'anon: ' . ($readyRow['claimant_name_anon'] ?? 'AUSENTE')
    );
    $assert(
        '1.5 El listado dice cuánto hay en el sobre y en qué estado está',
        (float)($readyRow['claimed_amount'] ?? 0) === 2.50
        && ($readyRow['status'] ?? '') === 'DEPOSITED_AT_RECEPTION'
        && ($readyRow['compensation_method'] ?? '') === 'EN_MANO_SEDE'
        && ($readyRow['ready_for_pickup'] ?? false) === true
    );
    $assert(
        '1.5 El estado llega traducido para el mostrador (RNF-REF-05)',
        ($readyRow['status_label'] ?? '') === 'Efectivo depositado en conserjería',
        'label: ' . ($readyRow['status_label'] ?? 'AUSENTE')
    );
    $assert(
        '1.5 El expediente se ancla a la avería y a la máquina para no entregar a ciegas',
        trim((string)($readyRow['incident_code'] ?? '')) !== ''
        && trim((string)($readyRow['machine_code'] ?? '')) !== '',
        json_encode([$readyRow['incident_code'] ?? null, $readyRow['machine_code'] ?? null])
    );

    $notReadyAfterReady = false;
    $seenReady = false;
    foreach ($refundsInList as $row) {
        if (($row['ready_for_pickup'] ?? false) === true) {
            $seenReady = true;
        } elseif ($seenReady) {
            $notReadyAfterReady = true;
        }
    }
    $assert(
        '1.6 Los sobres que hay que entregar van primero en el listado del turno',
        $notReadyAfterReady === false
    );

    $forbiddenKeys = ['iban', 'bizum_phone', 'pickup_pin', 'tracking_token', 'claimant_name', 'claimant_contact'];
    $leakedKeys = [];
    foreach ($forbiddenKeys as $key) {
        if (array_key_exists($key, $readyRow) || str_contains((string)$listing->getBody(), '"' . $key . '"')) {
            $leakedKeys[] = $key;
        }
    }
    $assert(
        '1.7 El listado no proyecta IBAN, Bizum, PIN, token ni datos de contacto (Art. V.4)',
        $leakedKeys === [],
        'claves filtradas: ' . json_encode($leakedKeys)
    );
    $assert(
        '1.7 Ni el nombre completo, ni el teléfono, ni el PIN real salen del mostrador',
        !str_contains((string)$listing->getBody(), 'Laura Sanitaria')
        && !str_contains((string)$listing->getBody(), '600111222')
        && !str_contains((string)$listing->getBody(), (string)$readyPin)
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Entrega presencial con PIN correcto (RF-REF-06) ---\n";

    $delivered = $dispatch('POST', "/api/location/refunds/{$secondCaseId}/deliver", [
        'pickup_pin' => $secondPin,
    ], $siteHeaders);
    $deliveredData = $data($delivered);
    $assert(
        '2.1 El PIN correcto libera el sobre y registra la entrega en mano',
        $delivered->getStatusCode() === 200,
        "HTTP {$delivered->getStatusCode()} " . json_encode($delivered->getDecodedBody())
    );
    $assert(
        '2.1 La respuesta confirma el estado terminal y el importe entregado',
        ($deliveredData['status'] ?? '') === 'REFUNDED_IN_HAND'
        && (int)($deliveredData['id'] ?? 0) === $secondCaseId
        && (float)($deliveredData['claimed_amount'] ?? 0) === 4.00
        && ($deliveredData['claimant_name_anon'] ?? '') === 'Marc R.'
    );
    $assert(
        '2.1 El mensaje de confirmación va en castellano (Dualismo Lingüístico)',
        str_contains((string)$delivered->getDecodedBody()['message'] ?? '', 'Entrega presencial registrada')
    );

    $deliveredRow = $refunds->findById($secondCaseId);
    $assert(
        '2.2 El expediente queda terminalmente reembolsado en mano',
        $deliveredRow?->getStatus() === RefundStatus::REFUNDED_IN_HAND
        && $deliveredRow->getStatus()->isTerminal()
    );

    $handover = $pdo->prepare('
        SELECT `hand_delivered_at`, `is_active`
        FROM `refund_requests`
        WHERE `id` = :id
    ');
    $handover->execute([':id' => $secondCaseId]);
    $handoverRow = $handover->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        '2.2 Queda sellada la hora de la entrega y el expediente sigue activo para la auditoría',
        trim((string)($handoverRow['hand_delivered_at'] ?? '')) !== ''
        && (int)($handoverRow['is_active'] ?? 0) === 1
    );

    $handoverAudit = $pdo->prepare('
        SELECT `previous_state`, `new_state`, `user_role`
        FROM `audit_log`
        WHERE `entity_type` = \'REFUND_REQUEST\'
          AND `entity_id` = :id
          AND `action` = \'REFUND_DELIVERED_IN_HAND\'
        ORDER BY `id` DESC
        LIMIT 1
    ');
    $handoverAudit->execute([':id' => $secondCaseId]);
    $handoverAuditRow = $handoverAudit->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        '2.3 La auditoría inmutable deja constancia de la entrega y de quién la hizo (RNF-REF-01)',
        str_contains((string)($handoverAuditRow['previous_state'] ?? ''), 'DEPOSITED_AT_RECEPTION')
        && str_contains((string)($handoverAuditRow['new_state'] ?? ''), 'REFUNDED_IN_HAND')
        && ($handoverAuditRow['user_role'] ?? '') === 'LOCATION_MANAGER'
    );

    $afterDelivery = $dispatch('GET', '/api/location/refunds', null, $siteHeaders);
    $stillReady = [];
    $untouched = [];
    foreach ($data($afterDelivery)['refunds'] ?? [] as $row) {
        if ((int)($row['id'] ?? 0) === $secondCaseId) {
            $stillReady = $row;
        }
        if ((int)($row['id'] ?? 0) === $readyCaseId) {
            $untouched = $row;
        }
    }
    $assert(
        '2.4 El sobre entregado deja de marcarse como listo para recoger',
        ($stillReady['ready_for_pickup'] ?? true) === false
        && ($stillReady['status'] ?? '') === 'REFUNDED_IN_HAND'
    );
    $assert(
        '2.4 El otro sobre de la misma sede sigue esperando su turno en el mostrador',
        ($untouched['ready_for_pickup'] ?? false) === true
        && ($untouched['status'] ?? '') === 'DEPOSITED_AT_RECEPTION'
    );

    $doubleDelivery = $dispatch('POST', "/api/location/refunds/{$secondCaseId}/deliver", [
        'pickup_pin' => $secondPin,
    ], $siteHeaders);
    $assert(
        '2.5 Un sobre ya entregado no se puede volver a soltar (409)',
        $doubleDelivery->getStatusCode() === 409
        && $errorCode($doubleDelivery) === 'INVALID_REFUND_STATE_TRANSITION',
        "HTTP {$doubleDelivery->getStatusCode()} " . json_encode($errorCode($doubleDelivery))
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Rechazo con PIN erróneo (RF-REF-06) ---\n";

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        // Un PIN equivocado de 4 dígitos, distinto del real en la primera cifra.
        $wrongPin = (string)((int)$readyPin[0] === 9 ? 1 : 9) . substr($readyPin, 1);
        $rejected = $dispatch('POST', "/api/location/refunds/{$readyCaseId}/deliver", [
            'pickup_pin' => $wrongPin,
        ], $siteHeaders);

        $assert(
            "3.1 Intento {$attempt} con PIN equivocado responde 422 INVALID_PICKUP_PIN",
            $rejected->getStatusCode() === 422
            && $errorCode($rejected) === 'INVALID_PICKUP_PIN',
            "HTTP {$rejected->getStatusCode()} " . json_encode($errorCode($rejected))
        );
    }

    $assert(
        '3.1 El aviso de PIN erróneo está redactado en castellano (contrato §5)',
        str_contains(
            (string)($rejected->getDecodedBody()['error']['message'] ?? ''),
            'PIN de recogida introducido no coincide'
        )
    );

    $intactRow = $refunds->findById($readyCaseId);
    $assert(
        '3.2 Tras el rechazo el dinero sigue en custodia, intacto y sin entregar',
        $intactRow?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
        && !$intactRow->getStatus()->isTerminal()
    );
    $intactHandover = $pdo->prepare('SELECT `hand_delivered_at` FROM `refund_requests` WHERE `id` = :id');
    $intactHandover->execute([':id' => $readyCaseId]);
    $assert(
        '3.2 No se ha estampado ninguna hora de entrega tras los intentos fallidos',
        trim((string)(($intactHandover->fetch(PDO::FETCH_ASSOC) ?: [])['hand_delivered_at'] ?? '')) === ''
    );
    $assert(
        '3.3 Un PIN erróneo no deja ni un solo apunte de entrega en la auditoría',
        (int)$pdo->query('
            SELECT COUNT(*) FROM `audit_log`
            WHERE `entity_type` = \'REFUND_REQUEST\'
              AND `entity_id` = ' . $readyCaseId . '
              AND `action` = \'REFUND_DELIVERED_IN_HAND\'
        ')->fetchColumn() === 0
    );

    $malformedPin = $dispatch('POST', "/api/location/refunds/{$readyCaseId}/deliver", [
        'pickup_pin' => '12ab',
    ], $siteHeaders);
    $assert(
        '3.4 Un PIN que no son 4 dígitos se rechaza antes de comparar nada',
        $malformedPin->getStatusCode() === 422
        && $errorCode($malformedPin) === 'INVALID_PICKUP_PIN',
        "HTTP {$malformedPin->getStatusCode()} " . json_encode($errorCode($malformedPin))
    );

    $missingPin = $dispatch('POST', "/api/location/refunds/{$readyCaseId}/deliver", [], $siteHeaders);
    $assert(
        '3.5 Sin PIN el conserje recibe una instrucción clara, no un PIN incorrecto',
        $missingPin->getStatusCode() === 422 && $errorCode($missingPin) === 'MISSING_PICKUP_PIN',
        "HTTP {$missingPin->getStatusCode()} " . json_encode($errorCode($missingPin))
    );

    $retry = $dispatch('POST', "/api/location/refunds/{$readyCaseId}/deliver", [
        'pickup_pin' => $readyPin,
    ], $siteHeaders);
    $assert(
        '3.6 El consumidor puede venir con el PIN correcto y el sobre se entrega igualmente',
        $retry->getStatusCode() === 200
        && ($data($retry)['status'] ?? '') === 'REFUNDED_IN_HAND',
        "HTTP {$retry->getStatusCode()} " . json_encode($retry->getDecodedBody())
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Segregación entre sedes (Art. V.4, RF-REF-10) ---\n";

    $foreignListing = $dispatch('GET', '/api/location/refunds', null, $otherSiteHeaders);
    $foreignIds = array_map(
        static fn (array $r): int => (int)($r['id'] ?? 0),
        $data($foreignListing)['refunds'] ?? []
    );
    $assert(
        '4.1 La conserjería de otro edificio no ve los sobres de nuestra sede',
        $foreignListing->getStatusCode() === 200
        && in_array($foreignCaseId, $foreignIds, true)
        && !in_array($readyCaseId, $foreignIds, true)
        && !in_array($secondCaseId, $foreignIds, true),
        json_encode($foreignIds)
    );

    $crossDelivery = $dispatch('POST', "/api/location/refunds/{$foreignCaseId}/deliver", [
        'pickup_pin' => '1234',
    ], $siteHeaders);
    $assert(
        '4.2 Entregar un sobre de otra sede responde 403 SITE_MISMATCH',
        $crossDelivery->getStatusCode() === 403 && $errorCode($crossDelivery) === 'SITE_MISMATCH',
        "HTTP {$crossDelivery->getStatusCode()} " . json_encode($errorCode($crossDelivery))
    );
    $assert(
        '4.2 El intento en sede ajena no ha tocado el expediente del otro centro',
        $refunds->findById($foreignCaseId)?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
    );

    $unknownCase = $dispatch('POST', '/api/location/refunds/99999999/deliver', [
        'pickup_pin' => '1234',
    ], $siteHeaders);
    $assert(
        '4.3 Un expediente inexistente responde 404 sin revelar nada más',
        $unknownCase->getStatusCode() === 404 && $errorCode($unknownCase) === 'REFUND_NOT_FOUND',
        "HTTP {$unknownCase->getStatusCode()} " . json_encode($errorCode($unknownCase))
    );

    $badRouteId = $dispatch('POST', '/api/location/refunds/abc/deliver', [
        'pickup_pin' => '1234',
    ], $siteHeaders);
    $assert(
        '4.4 Un identificador de expediente no numérico se rechaza con 400',
        $badRouteId->getStatusCode() === 400 && $errorCode($badRouteId) === 'INVALID_REFUND_ID',
        "HTTP {$badRouteId->getStatusCode()} " . json_encode($errorCode($badRouteId))
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Blindaje constitucional de la entrega (Art. III, Art. V.4) ---\n";

    $refundRowsAfter = (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests`')->fetchColumn();
    $assert(
        '5.1 Ni una entrega ni un rechazo ha borrado físicamente un expediente (Art. III.1)',
        $refundRowsAfter === $refundRowsBefore,
        "antes: {$refundRowsBefore} | después: {$refundRowsAfter}"
    );
    $assert(
        '5.1 Los tres expedientes siguen existiendo y con su PIN original (Art. III.2)',
        (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests` WHERE `id` IN ('
            . (int)$readyCaseId . ', ' . (int)$secondCaseId . ', ' . (int)$foreignCaseId
            . ') AND `pickup_pin` IS NOT NULL')->fetchColumn() === 3
    );

    $allSiteResponses = implode('', [
        $listing->getBody(),
        $afterDelivery->getBody(),
        $delivered->getBody(),
        $retry->getBody(),
        $rejected->getBody(),
        $crossDelivery->getBody(),
    ]);
    $assert(
        '5.2 Ninguna respuesta de conserjería filtra IBAN ni teléfono de Bizum',
        !str_contains($allSiteResponses, 'iban')
        && !str_contains($allSiteResponses, 'bizum_phone')
    );
    $assert(
        '5.2 La entrega en mano tampoco devuelve el PIN del expediente',
        !str_contains((string)$delivered->getBody(), (string)$secondPin)
    );

    $pinSource = (string)file_get_contents(__DIR__ . '/../../src/Core/Domain/Model/RefundRequest.php');
    $assert(
        '5.3 El PIN se compara en tiempo constante contra fugas de temporización',
        str_contains($pinSource, 'hash_equals($this->pickupPin')
    );

    // Se cuentan sólo los expedientes de esta suite: otras pruebas del módulo
    // confirman entregas reales y dejan sus propios apuntes en la misma tabla.
    $audits = (int)$pdo->query('
        SELECT COUNT(*) FROM `audit_log`
        WHERE `entity_type` = \'REFUND_REQUEST\'
          AND `action` = \'REFUND_DELIVERED_IN_HAND\'
          AND `entity_id` IN (' . (int)$readyCaseId . ', ' . (int)$secondCaseId . ')
    ')->fetchColumn();
    $assert(
        '5.4 Sólo las dos entregas de esta suite dejaron apunte en la auditoría',
        $audits === 2,
        "REFUND_DELIVERED_IN_HAND de esta suite: {$audits}"
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. La fuerza bruta contra el PIN de recogida se topa con el freno (RF-REF-02) ---\n";
    // ─────────────────────────────────────────────────────────────────────

    // Antes de este requisito el endpoint toleraba 3000 PIN incorrectos en 1,02
    // segundos (0,34 ms por intento): con 10.000 combinaciones, el espacio
    // entero era alcanzable en menos de cuatro minutos. Se reproduce aquí el
    // ataque sobre el endpoint HTTP real, no sobre el servicio.
    [$bruteCaseId, $brutePin] = $buildDeskCase(
        $thirdDeskMachine,
        $location,
        $techId,
        'TREF21D-FUERZA-BRUTA-',
        'Álvaro Exhausto',
        3.00,
        $techHeaders,
        $dispatch,
        $makeIncident
    );

    $bruteStatuses = [];
    $lockedResponse = null;
    for ($guess = 0; $guess < RefundRequest::PICKUP_PIN_MAX_ATTEMPTS; $guess++) {
        // Se esquivan el PIN real a propósito: adivinarlo en el quinto intento
        // sería una prueba de suerte, no una prueba del freno.
        $candidate = (string)(1000 + (($brutePin - 1000 + 1 + $guess) % 9000));
        $attempt = $dispatch('POST', "/api/location/refunds/{$bruteCaseId}/deliver", [
            'pickup_pin' => $candidate,
        ], $siteHeaders);
        $bruteStatuses[] = $attempt->getStatusCode();

        if ($attempt->getStatusCode() === 423) {
            $lockedResponse = $attempt;
        }
    }

    $assert(
        '6.1 Los primeros intentos son 422 y el último cierra la puerta con 423',
        $bruteStatuses === array_merge(
            array_fill(0, RefundRequest::PICKUP_PIN_MAX_ATTEMPTS - 1, 422),
            [423]
        ),
        'estados: ' . json_encode($bruteStatuses)
    );
    $assert(
        '6.2 El bloqueo se identifica como PICKUP_PIN_LOCKED y dice cuándo se libera',
        $lockedResponse !== null
        && $errorCode($lockedResponse) === 'PICKUP_PIN_LOCKED'
        && (string)($lockedResponse->getDecodedBody()['error']['details']['locked_until'] ?? '') !== '',
        'detalle: ' . json_encode($lockedResponse?->getDecodedBody()['error'] ?? null)
    );

    $afterLock = $dispatch('POST', "/api/location/refunds/{$bruteCaseId}/deliver", [
        'pickup_pin' => $brutePin,
    ], $siteHeaders);
    $bruteRow = $pdo->prepare('SELECT `status`, `pickup_attempts`, `pickup_locked_until` FROM `refund_requests` WHERE `id` = :id');
    $bruteRow->execute([':id' => $bruteCaseId]);
    $bruteRowData = $bruteRow->fetch(PDO::FETCH_ASSOC) ?: [];

    $assert(
        '6.3 El PIN CORRECTO ya no abre el sobre: el sobre sigue en el mostrador',
        $afterLock->getStatusCode() === 423
        && (string)($bruteRowData['status'] ?? '') === 'DEPOSITED_AT_RECEPTION',
        "HTTP {$afterLock->getStatusCode()} | estado: " . (string)($bruteRowData['status'] ?? '')
    );
    $assert(
        '6.4 El contador y el bloqueo quedan persistidos en la base de datos',
        (int)($bruteRowData['pickup_attempts'] ?? 0) === RefundRequest::PICKUP_PIN_MAX_ATTEMPTS
        && trim((string)($bruteRowData['pickup_locked_until'] ?? '')) !== '',
        'intentos: ' . (string)($bruteRowData['pickup_attempts'] ?? '')
    );

    // Seguir insistiendo no cuela: 200 intentos más siguen sin mover el expediente.
    $persistence = [];
    for ($extra = 0; $extra < 200; $extra++) {
        $persistence[] = $dispatch('POST', "/api/location/refunds/{$bruteCaseId}/deliver", [
            'pickup_pin' => (string)(1000 + ($extra % 9000)),
        ], $siteHeaders)->getStatusCode();
    }
    $assert(
        '6.5 Doscientos intentos más no cambian la respuesta ni abren el expediente',
        array_unique($persistence) === [423]
        && $refunds->findById($bruteCaseId)?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION,
        'estados distintos: ' . json_encode(array_values(array_unique($persistence)))
    );

    $deskListing = $dispatch('GET', '/api/location/refunds', null, $siteHeaders);
    $assert(
        '6.6 La conserjería NO ve el contador de intentos ni la fecha de bloqueo',
        !str_contains((string)$deskListing->getBody(), 'pickup_attempts')
        && !str_contains((string)$deskListing->getBody(), 'pickup_locked_until')
        && !str_contains((string)$afterLock->getBody(), 'pickup_attempts'),
        'respuesta: ' . substr((string)$deskListing->getBody(), 0, 200)
    );

    // El bloqueo es temporal: agotado el plazo, el consumidor vuelve a cobrar.
    $pdo->prepare('UPDATE `refund_requests` SET `pickup_locked_until` = :until WHERE `id` = :id')
        ->execute([
            ':until' => date('Y-m-d H:i:s', strtotime('-1 minute')),
            ':id' => $bruteCaseId,
        ]);

    $recovered = $dispatch('POST', "/api/location/refunds/{$bruteCaseId}/deliver", [
        'pickup_pin' => $brutePin,
    ], $siteHeaders);
    $recoveredRow = $refunds->findById($bruteCaseId);

    $assert(
        '6.7 Vencido el bloqueo, el PIN correcto vuelve a entregar el sobre',
        $recovered->getStatusCode() === 200
        && $recoveredRow?->getStatus() === RefundStatus::REFUNDED_IN_HAND,
        "HTTP {$recovered->getStatusCode()} " . json_encode($recovered->getDecodedBody())
    );
    $assert(
        '6.8 La entrega correcta deja el contador a cero',
        $recoveredRow?->getPickupAttempts() === 0 && $recoveredRow?->getPickupLockedUntil() === null,
        'intentos: ' . var_export($recoveredRow?->getPickupAttempts(), true)
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-21 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
