<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Integration suite for the field technician verdict (T-REF-20).
 *
 * It exercises the on-site money verdict through the real `AppRouter` and real
 * MariaDB, exactly as the technician reaches it from a phone in a foreign site
 * (RF-REF-04, RF-REF-05, RF-REF-09, RNF-REF-04):
 *
 * 1. `GET /api/technician/incidents/{id}/refund`, which must tell the technician
 *    that a verdict is blocking the repair, publish the three closed findings
 *    for the touch UI, explain where the cash has to go, and never project the
 *    IBAN, the Bizum phone or the claimant identity (Art. V.4, RNF-REF-03).
 * 2. `POST /api/technician/incidents/{id}/resolve` with a `FOUND_PHYSICAL`
 *    verdict, which must move the case to `DEPOSITED_AT_RECEPTION` when the
 *    claimant chose the desk and the amount is at most 10,00 €.
 * 3. The forced central custody: an explicit `LEFT_AT_RECEPTION` for a digital
 *    channel or for more than 10,00 € is refused with
 *    `RECEPTION_DELIVERY_NOT_ALLOWED`, and an unset custody is decided by the
 *    rules, which pin the cash to the central safe (RF-REF-05).
 * 4. The decoupling (RF-REF-09): the incident may reach `RESOLVED` while the
 *    case stays open for the coordinator, and the case row survives untouched.
 * 5. The on-site cash finding with no prior claim, persisted in
 *    `unclaimed_cash_findings` and audited (RF-REF-04).
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
echo " VendGuard: Test de Integración - TechnicianRefundInspectionApiTest (T-REF-20)\n";
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
    $assert('0.1 La sede semilla existe', $location !== null);
    if ($location === null) {
        throw new RuntimeException('No hay sede semilla para la prueba de dictamen técnico.');
    }

    $technician = $users->findByEmail('jordi.ruta@vendguard.internal');
    $coordinator = $users->findByEmail('coordinacion@vendguard.internal');
    $assert(
        '0.2 El técnico de ruta y el coordinador semilla existen',
        $technician !== null && $coordinator !== null
    );
    if ($technician === null || $coordinator === null) {
        throw new RuntimeException('No hay usuarios semilla para la prueba de dictamen técnico.');
    }

    $techId = (int)$technician->getId();
    $authService = new AuthService($locations, $users);
    $techToken = $authService->generateInternalToken($technician);
    $coordinatorToken = $authService->generateInternalToken($coordinator);

    $router = AppRouter::create();
    $routerSource = (string)file_get_contents(__DIR__ . '/../../src/Presentation/Routing/AppRouter.php');

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

    // Cada escenario necesita su propia máquina: las máquinas semilla se
    // comparten con el resto de suites y una purga paralela jamás debe decidir
    // si la incidencia de este caso encuentra o no una reclamación abierta.
    $makeMachine = static function (string $prefix, MachineType $type) use ($machines, $location) {
        return $machines->create([
            'location_id' => $location->getId(),
            'code' => $prefix . strtoupper(bin2hex(random_bytes(3))),
            'model' => 'VendGuard technician verdict machine',
            'machine_type' => $type->value,
            'floor_wing' => 'Planta baja - Vestíbulo',
            'notes' => 'Máquina creada dentro de la transacción reversible de T-REF-20.',
        ]);
    };

    // La resolución exige `IN_PROGRESS` (EARS 8.2) y que la avería sea de este
    // técnico, así que la incidencia nace registrada y se entrega a la ruta
    // antes de que el técnico llame al endpoint.
    $makeIncident = static function ($machine, int $techId, string $prefix) use ($incidents, $location, $pdo): Incident {
        $created = $incidents->create(new Incident(
            id: null,
            ticketCode: $prefix . strtoupper(bin2hex(random_bytes(3))),
            machineId: (int)$machine->getId(),
            locationId: (int)$location->getId(),
            category: IncidentCategory::PAYMENT_SYSTEM,
            description: 'La máquina accepts la moneda y no entrega el producto.',
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

    $openCase = static function (
        Incident $incident,
        $machine,
        CompensationMethod $method,
        float $amount,
        ?string $bizumPhone = null,
        ?string $iban = null
    ) use ($refundService, $location) {
        return $refundService->createCase(new CreateRefundRequestDTO(
            incidentId: (int)$incident->getId(),
            machineId: (int)$machine->getId(),
            locationId: (int)$location->getId(),
            claimantName: 'Laura Sanitaria',
            claimantContact: '600111222',
            claimedAmount: $amount,
            compensationMethod: $method,
            productAttempted: 'Café con leche carril 2',
            bizumPhone: $bizumPhone,
            iban: $iban
        ));
    };

    $validDiagnosis = 'Moneda de dos euros atascada en el embudo del selector mecánico.';
    $validAction = 'Desatasco del selector, limpieza de canaleta y prueba de monedas satisfactoria.';

    $deskMachine = $makeMachine('TREF20A-', MachineType::HOT_DRINKS);
    $digitalMachine = $makeMachine('TREF20B-', MachineType::SNACKS);
    $elevatedMachine = $makeMachine('TREF20C-', MachineType::COLD_DRINKS);
    $noCashMachine = $makeMachine('TREF20D-', MachineType::SNACKS);
    $unverifiedMachine = $makeMachine('TREF20E-', MachineType::HOT_DRINKS);
    $officeMachine = $makeMachine('TREF20F-', MachineType::PERISHABLE_FOOD);

    $assert(
        '0.3 Las seis máquinas de prueba se crean dentro de la transacción',
        $deskMachine !== null
        && $digitalMachine !== null
        && $elevatedMachine !== null
        && $noCashMachine !== null
        && $unverifiedMachine !== null
        && $officeMachine !== null
    );

    $assert(
        '0.4 Los tokens internos de técnico y coordinador se emiten y son distintos',
        $techToken !== '' && $coordinatorToken !== '' && $techToken !== $coordinatorToken
    );

    $assert(
        '0.5 Las rutas del técnico siguen registradas con su middleware de rol en AppRouter',
        str_contains($routerSource, "\$router->get('/api/technician/incidents/{id}/refund'")
        && str_contains($routerSource, "\$router->post('/api/technician/incidents/{id}/resolve'")
        && str_contains($routerSource, '$technicianAuth')
    );

    $refundRowsBefore = (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests`')->fetchColumn();

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Consulta del técnico antes de(dictaminar (RF-REF-04, Art. V.4) ---\n";

    // Se crea ya para que el bloque 1 pueda certificar también la vista de una
    // avería sin ninguna reclamación colgando.
    $officeIncident = $makeIncident($officeMachine, $techId, 'TREF20-OFFICE-');

    $deskIncident = $makeIncident($deskMachine, $techId, 'TREF20-DESK-');
    $deskCase = $openCase($deskIncident, $deskMachine, CompensationMethod::EN_MANO_SEDE, 2.00);
    $deskCaseId = (int)$deskCase->getId();

    $digitalIncident = $makeIncident($digitalMachine, $techId, 'TREF20-DIG-');
    $digitalCase = $openCase(
        $digitalIncident,
        $digitalMachine,
        CompensationMethod::BIZUM,
        3.00,
        bizumPhone: '600111222'
    );
    $digitalCaseId = (int)$digitalCase->getId();

    $assert(
        '1.0 Las dos reclamaciones quedan abiertas en PENDING_INSPECTION',
        $deskCase->getStatus() === RefundStatus::PENDING_INSPECTION
        && $digitalCase->getStatus() === RefundStatus::PENDING_INSPECTION
    );

    $unauthenticated = $dispatch('GET', "/api/technician/incidents/{$deskIncident->getId()}/refund");
    $assert(
        '1.1 Sin token el técnico recibe 401 UNAUTHORIZED',
        $unauthenticated->getStatusCode() === 401 && $errorCode($unauthenticated) === 'UNAUTHORIZED',
        "HTTP {$unauthenticated->getStatusCode()} " . json_encode($errorCode($unauthenticated))
    );

    $wrongRole = $dispatch(
        'GET',
        "/api/technician/incidents/{$deskIncident->getId()}/refund",
        null,
        ['authorization' => 'Bearer ' . $coordinatorToken, 'content-type' => 'application/json']
    );
    $assert(
        '1.2 Un rol que no es técnico recibe 403 FORBIDDEN',
        $wrongRole->getStatusCode() === 403 && $errorCode($wrongRole) === 'FORBIDDEN',
        "HTTP {$wrongRole->getStatusCode()} " . json_encode($errorCode($wrongRole))
    );

    $unknownIncident = $dispatch(
        'GET',
        '/api/technician/incidents/99999999/refund',
        null,
        $techHeaders
    );
    $assert(
        '1.3 Una incidencia inexistente responde 404 INCIDENT_NOT_FOUND',
        $unknownIncident->getStatusCode() === 404
        && $errorCode($unknownIncident) === 'INCIDENT_NOT_FOUND',
        "HTTP {$unknownIncident->getStatusCode()} " . json_encode($errorCode($unknownIncident))
    );

    $deskView = $dispatch('GET', "/api/technician/incidents/{$deskIncident->getId()}/refund", null, $techHeaders);
    $deskPayload = $data($deskView);
    $assert('1.4 El técnico asignado lee su reclamación con HTTP 200', $deskView->getStatusCode() === 200);
    $assert(
        '1.4 La vista anuncia que hay una reclamación pendiente de dictaminar',
        ($deskPayload['has_refund_requests'] ?? null) === true
        && ($deskPayload['total_requests'] ?? null) === 1
        && (float)($deskPayload['total_claimed_amount'] ?? 0) === 2.00
    );
    $assert(
        '1.4 has_pending_verdict bloquea el botón de resolver en la vista móvil',
        ($deskPayload['has_pending_verdict'] ?? null) === true
    );

    $deskRequest = $deskPayload['requests'][0] ?? [];
    $assert(
        '1.4 La proyección trae lo operativo y nada más',
        ($deskRequest['id'] ?? null) === $deskCaseId
        && (float)($deskRequest['claimed_amount'] ?? 0) === 2.00
        && ($deskRequest['compensation_method'] ?? '') === 'EN_MANO_SEDE'
        && ($deskRequest['status'] ?? '') === 'PENDING_INSPECTION'
        && ($deskRequest['product_attempted'] ?? '') !== ''
    );
    $assert(
        '1.5 Presa ≤ 10 € en mano: la instrucción apunta a la conserjería',
        str_contains((string)($deskRequest['custody_instruction'] ?? ''), 'recepción'),
        'instrucción: ' . ($deskRequest['custody_instruction'] ?? 'AUSENTE')
    );

    $findings = $deskPayload['available_findings'] ?? [];
    $findingValues = array_map(static fn (array $f): string => (string)($f['value'] ?? ''), $findings);
    sort($findingValues);
    $assert(
        '1.6 La vista móvil recibe las tres opciones cerradas de dictamen (RF-REF-04)',
        $findingValues === ['CONFIRMED_NO_CASH', 'FOUND_PHYSICAL', 'UNVERIFIED_NO_CASH'],
        json_encode($findingValues)
    );
    $justified = [];
    foreach ($findings as $finding) {
        if (($finding['requires_justification'] ?? false) === true) {
            $justified[] = (string)($finding['value'] ?? '');
        }
    }
    $assert(
        '1.6 Sólo el dictamen sin evidencia técnica exige justificación escrita',
        $justified === ['UNVERIFIED_NO_CASH'],
        json_encode($justified)
    );
    $assert(
        '1.7 Los tres dictámenes llegan traducidos para el técnico (RNF-REF-04)',
        count(array_filter(
            $findings,
            static fn (array $f): bool => mb_strlen(trim((string)($f['label'] ?? ''))) > 0
        )) === 3
    );

    $digitalView = $dispatch('GET', "/api/technician/incidents/{$digitalIncident->getId()}/refund", null, $techHeaders);
    $digitalRequest = $data($digitalView)['requests'][0] ?? [];
    $assert(
        '1.8 Canal digital: la instrucción prohíbe la conserjería y manda a caja central',
        str_contains((string)($digitalRequest['custody_instruction'] ?? ''), 'caja central')
        && !str_contains((string)($digitalRequest['custody_instruction'] ?? ''), 'recepción'),
        'instrucción: ' . ($digitalRequest['custody_instruction'] ?? 'AUSENTE')
    );

    $forbiddenKeys = ['iban', 'bizum_phone', 'claimant_name', 'claimant_contact', 'pickup_pin', 'tracking_token'];
    $projection = json_encode($data($digitalView), JSON_UNESCAPED_UNICODE);
    $leakedKeys = [];
    foreach ($forbiddenKeys as $key) {
        if (array_key_exists($key, $digitalRequest) || str_contains((string)$projection, '"' . $key . '"')) {
            $leakedKeys[] = $key;
        }
    }
    $assert(
        '1.9 El técnico no recibe IBAN, Bizum, contacto, PIN ni token de seguimiento (Art. V.4)',
        $leakedKeys === [],
        'claves filtradas: ' . json_encode($leakedKeys)
    );
    $assert(
        '1.9 Ni el teléfono Bizum ni el nombre del reclamante aparecen en el cuerpo',
        !str_contains((string)$digitalView->getBody(), '600111222')
        && !str_contains((string)$digitalView->getBody(), 'Laura')
    );

    $officeView = $dispatch('GET', "/api/technician/incidents/{$officeIncident->getId()}/refund", null, $techHeaders);
    $officePayload = $data($officeView);
    $assert(
        '1.10 Una avería sin reclamación no inventa expedientes ni bloquea la resolución',
        ($officePayload['has_refund_requests'] ?? null) === false
        && ($officePayload['total_requests'] ?? null) === 0
        && ($officePayload['has_pending_verdict'] ?? null) === false
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Dictamen FOUND_PHYSICAL y efectivo en conserjería (RF-REF-04, RF-REF-05) ---\n";

    $noVerdict = $dispatch('POST', "/api/technician/incidents/{$deskIncident->getId()}/resolve", [
        'resolution_diagnosis' => $validDiagnosis,
        'resolution_action' => $validAction,
    ], $techHeaders);
    $assert(
        '2.1 Con una reclamación pendiente la avería no se cierra sin dictamen',
        $noVerdict->getStatusCode() === 422
        && $errorCode($noVerdict) === 'REFUND_INSPECTION_REQUIRED',
        "HTTP {$noVerdict->getStatusCode()} " . json_encode($errorCode($noVerdict))
    );
    $assert(
        '2.1 Ni la avería ni el expediente se han tocado tras el rechazo',
        $incidents->findById((int)$deskIncident->getId())?->getStatus() === IncidentStatus::IN_PROGRESS
        && $refunds->findById($deskCaseId)?->getStatus() === RefundStatus::PENDING_INSPECTION
    );

    $amountless = $dispatch('POST', "/api/technician/incidents/{$deskIncident->getId()}/resolve", [
        'resolution_diagnosis' => $validDiagnosis,
        'resolution_action' => $validAction,
        'refund_inspection' => ['finding' => 'FOUND_PHYSICAL', 'cash_custody_action' => 'LEFT_AT_RECEPTION'],
    ], $techHeaders);
    $assert(
        '2.2 FOUND_PHYSICAL sin importe se rechaza: es una contradicción, no una omisión',
        $amountless->getStatusCode() === 422
        && $errorCode($amountless) === 'INVALID_REFUND_INSPECTION',
        "HTTP {$amountless->getStatusCode()} " . json_encode($errorCode($amountless))
    );

    $deskResolution = $dispatch('POST', "/api/technician/incidents/{$deskIncident->getId()}/resolve", [
        'resolution_diagnosis' => $validDiagnosis,
        'resolution_action' => $validAction,
        'refund_inspection' => [
            'finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 2.00,
            'cash_custody_action' => 'LEFT_AT_RECEPTION',
            'receptionist_name' => 'Laura Sanitaria (Conserjería Planta Baja)',
        ],
    ], $techHeaders);
    $deskBody = $data($deskResolution);
    $assert(
        '2.3 El dictamen válido cierra la avería y el expediente a la vez',
        $deskResolution->getStatusCode() === 200,
        "HTTP {$deskResolution->getStatusCode()} " . json_encode($deskResolution->getDecodedBody())
    );
    $assert(
        '2.3 La respuesta expone el estado económico alcanzado (contrato §4.2.2)',
        ($deskBody['refund_status'] ?? '') === 'DEPOSITED_AT_RECEPTION'
        && (int)($deskBody['refund_processed'] ?? 0) === 1
        && (float)($deskBody['recovered_total'] ?? 0) === 2.00
        && (float)($deskBody['claimed_total'] ?? 0) === 2.00
        && ($deskBody['refund_discrepancy'] ?? true) === false
    );
    $assert(
        '2.3 La avería queda RESOLVED con su diagnóstico registrado (Art. V.1)',
        $incidents->findById((int)$deskIncident->getId())?->getStatus() === IncidentStatus::RESOLVED
    );

    $deskRow = $refunds->findById($deskCaseId);
    $assert(
        '2.4 El expediente queda DEPOSITED_AT_RECEPTION esperando la recogida con PIN',
        $deskRow?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
    );
    $assert(
        '2.4 Se persiste el dictamen, el importe y el nombre del conserje que recibe el sobre',
        $deskRow?->getTechnicianFinding()?->value === 'FOUND_PHYSICAL'
        && $deskRow?->getRecoveredAmount() === 2.00
        && $deskRow?->getCashCustodyAction()?->value === 'LEFT_AT_RECEPTION'
        && $deskRow?->getReceptionistName() === 'Laura Sanitaria (Conserjería Planta Baja)'
    );

    $deskStored = $pdo->prepare('
        SELECT `technician_id`, `technician_inspected_at`
        FROM `refund_requests`
        WHERE `id` = :id
    ');
    $deskStored->execute([':id' => $deskCaseId]);
    $deskAuditRow = $deskStored->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        '2.4 El dictamen queda firmado por el técnico con su fecha de inspección',
        (int)($deskAuditRow['technician_id'] ?? 0) === $techId
        && trim((string)($deskAuditRow['technician_inspected_at'] ?? '')) !== ''
    );

    $deskAudit = $pdo->prepare('
        SELECT `previous_state`, `new_state`, `user_id`, `user_role`
        FROM `audit_log`
        WHERE `entity_type` = \'REFUND_REQUEST\' AND `entity_id` = :id AND `action` = \'REFUND_INSPECTED\'
        ORDER BY `id` DESC
        LIMIT 1
    ');
    $deskAudit->execute([':id' => $deskCaseId]);
    $deskAuditRow = $deskAudit->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        '2.5 audit_log conserva el estado de partida y el de llegada del dictamen (Art. III.3)',
        (int)($deskAuditRow['user_id'] ?? 0) === $techId
        && ($deskAuditRow['user_role'] ?? '') === 'TECHNICIAN'
        && str_contains((string)($deskAuditRow['previous_state'] ?? ''), 'PENDING_INSPECTION')
        && str_contains((string)($deskAuditRow['new_state'] ?? ''), 'DEPOSITED_AT_RECEPTION')
    );

    $deskRecheck = $dispatch('GET', "/api/technician/incidents/{$deskIncident->getId()}/refund", null, $techHeaders);
    $deskRecheckRequest = $data($deskRecheck)['requests'][0] ?? [];
    $assert(
        '2.6 Dictaminado el expediente, la vista deja de pedir dinero y lo dice',
        ($data($deskRecheck)['has_pending_verdict'] ?? null) === false
        && ($deskRecheckRequest['status'] ?? '') === 'DEPOSITED_AT_RECEPTION'
        && str_contains((string)($deskRecheckRequest['custody_instruction'] ?? ''), 'ya está registrado')
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Custodia forzada a caja central (RF-REF-05) ---\n";

    $digitalReception = $dispatch('POST', "/api/technician/incidents/{$digitalIncident->getId()}/resolve", [
        'resolution_diagnosis' => $validDiagnosis,
        'resolution_action' => $validAction,
        'refund_inspection' => [
            'finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 3.00,
            'cash_custody_action' => 'LEFT_AT_RECEPTION',
        ],
    ], $techHeaders);
    $assert(
        '3.1 Un canal digital no puede quedarse en la conserjería',
        $digitalReception->getStatusCode() === 422
        && $errorCode($digitalReception) === 'RECEPTION_DELIVERY_NOT_ALLOWED',
        "HTTP {$digitalReception->getStatusCode()} " . json_encode($errorCode($digitalReception))
    );
    $assert(
        '3.1 El rechazo no pisa nada: la avería sigue abierta para que el técnico reintente',
        $incidents->findById((int)$digitalIncident->getId())?->getStatus() === IncidentStatus::IN_PROGRESS
        && $refunds->findById($digitalCaseId)?->getStatus() === RefundStatus::PENDING_INSPECTION
    );

    $digitalResolution = $dispatch('POST', "/api/technician/incidents/{$digitalIncident->getId()}/resolve", [
        'resolution_diagnosis' => $validDiagnosis,
        'resolution_action' => $validAction,
        'refund_inspection' => [
            'finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 3.00,
        ],
    ], $techHeaders);
    $digitalBody = $data($digitalResolution);
    $assert(
        '3.2 Sin custodia elegida mandan las reglas: el efectivo va a caja central',
        $digitalResolution->getStatusCode() === 200
        && $refunds->findById($digitalCaseId)?->getCashCustodyAction()?->value === 'HELD_FOR_CENTRAL',
        "HTTP {$digitalResolution->getStatusCode()} " . json_encode($digitalResolution->getDecodedBody())
    );
    $assert(
        '3.2 El expediente digital queda a la espera de liquidación por Coordinación',
        ($digitalBody['refund_status'] ?? '') === 'VERIFIED_PENDING_PAYMENT'
        && $refunds->findById($digitalCaseId)?->getStatus() === RefundStatus::VERIFIED_PENDING_PAYMENT
    );

    $elevatedIncident = $makeIncident($elevatedMachine, $techId, 'TREF20-ELEV-');
    $elevatedCase = $openCase($elevatedIncident, $elevatedMachine, CompensationMethod::EN_MANO_SEDE, 15.00);
    $elevatedCaseId = (int)$elevatedCase->getId();

    $elevatedReception = $dispatch('POST', "/api/technician/incidents/{$elevatedIncident->getId()}/resolve", [
        'resolution_diagnosis' => $validDiagnosis,
        'resolution_action' => $validAction,
        'refund_inspection' => [
            'finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 15.00,
            'cash_custody_action' => 'LEFT_AT_RECEPTION',
            'receptionist_name' => 'Laura Sanitaria (Conserjería Planta Baja)',
        ],
    ], $techHeaders);
    $assert(
        '3.3 Más de 10,00 € en mano tampoco puede quedarse en la conserjería',
        $elevatedReception->getStatusCode() === 422
        && $errorCode($elevatedReception) === 'RECEPTION_DELIVERY_NOT_ALLOWED',
        "HTTP {$elevatedReception->getStatusCode()} " . json_encode($errorCode($elevatedReception))
    );
    $assert(
        '3.3 Un sobre de 15,00 € no se ha depositado en ningún cajón de la sede',
        $incidents->findById((int)$elevatedIncident->getId())?->getStatus() === IncidentStatus::IN_PROGRESS
        && $refunds->findById($elevatedCaseId)?->getCashCustodyAction() === null
    );

    $elevatedResolution = $dispatch('POST', "/api/technician/incidents/{$elevatedIncident->getId()}/resolve", [
        'resolution_diagnosis' => $validDiagnosis,
        'resolution_action' => $validAction,
        'refund_inspection' => [
            'finding' => 'FOUND_PHYSICAL',
            'recovered_amount' => 15.00,
        ],
    ], $techHeaders);
    $assert(
        '3.4 El importe elevado se custodia en caja central y pide visto bueno',
        $elevatedResolution->getStatusCode() === 200
        && $refunds->findById($elevatedCaseId)?->getCashCustodyAction()?->value === 'HELD_FOR_CENTRAL'
        && $refunds->findById($elevatedCaseId)?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL,
        "HTTP {$elevatedResolution->getStatusCode()} " . json_encode($elevatedResolution->getDecodedBody())
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Dictámenes sin efectivo recuperado (RF-REF-04) ---\n";

    $noCashIncident = $makeIncident($noCashMachine, $techId, 'TREF20-NOCASH-');
    $noCashCase = $openCase($noCashIncident, $noCashMachine, CompensationMethod::EN_MANO_SEDE, 4.00);
    $noCashCaseId = (int)$noCashCase->getId();

    $noCashResolution = $dispatch('POST', "/api/technician/incidents/{$noCashIncident->getId()}/resolve", [
        'resolution_diagnosis' => $validDiagnosis,
        'resolution_action' => $validAction,
        'refund_inspection' => ['finding' => 'CONFIRMED_NO_CASH'],
    ], $techHeaders);
    $assert(
        '4.1 Fallo verificado de cobro sin monedas: se registra sin inventar custodia',
        $noCashResolution->getStatusCode() === 200
        && $refunds->findById($noCashCaseId)?->getTechnicianFinding()?->value === 'CONFIRMED_NO_CASH'
        && $refunds->findById($noCashCaseId)?->getCashCustodyAction() === null,
        "HTTP {$noCashResolution->getStatusCode()} " . json_encode($noCashResolution->getDecodedBody())
    );
    $assert(
        '4.1 Sin efectivo recuperado no se deposita nada en la conserjería',
        $refunds->findById($noCashCaseId)?->getStatus() !== RefundStatus::DEPOSITED_AT_RECEPTION
        && $incidents->findById((int)$noCashIncident->getId())?->getStatus() === IncidentStatus::RESOLVED
    );

    $unverifiedIncident = $makeIncident($unverifiedMachine, $techId, 'TREF20-UNVER-');
    $unverifiedCase = $openCase($unverifiedIncident, $unverifiedMachine, CompensationMethod::EN_MANO_SEDE, 2.50);
    $unverifiedCaseId = (int)$unverifiedCase->getId();

    $shortJustification = $dispatch('POST', "/api/technician/incidents/{$unverifiedIncident->getId()}/resolve", [
        'resolution_diagnosis' => $validDiagnosis,
        'resolution_action' => $validAction,
        'refund_inspection' => [
            'finding' => 'UNVERIFIED_NO_CASH',
            'justification' => 'No vi nada',
        ],
    ], $techHeaders);
    $assert(
        '4.2 El dictamen sin evidencia técnica exige justificación de 20 caracteres',
        $shortJustification->getStatusCode() === 422
        && $errorCode($shortJustification) === 'JUSTIFICATION_TOO_SHORT',
        "HTTP {$shortJustification->getStatusCode()} " . json_encode($errorCode($shortJustification))
    );
    $assert(
        '4.2 Sin justificación suficiente no se dictamina ni se cierra la avería',
        $incidents->findById((int)$unverifiedIncident->getId())?->getStatus() === IncidentStatus::IN_PROGRESS
        && $refunds->findById($unverifiedCaseId)?->getStatus() === RefundStatus::PENDING_INSPECTION
    );

    $unverifiedResolution = $dispatch('POST', "/api/technician/incidents/{$unverifiedIncident->getId()}/resolve", [
        'resolution_diagnosis' => $validDiagnosis,
        'resolution_action' => $validAction,
        'refund_inspection' => [
            'finding' => 'UNVERIFIED_NO_CASH',
            'justification' => 'No localizo monedas en embudo ni canaleta tras desmontar el selector.',
        ],
    ], $techHeaders);
    $unverifiedRow = $refunds->findById($unverifiedCaseId);
    $assert(
        '4.3 Justificado, el dictamen se acepta y se escala a Coordinación',
        $unverifiedResolution->getStatusCode() === 200
        && $unverifiedRow?->getTechnicianFinding()?->value === 'UNVERIFIED_NO_CASH'
        && $unverifiedRow?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL,
        "HTTP {$unverifiedResolution->getStatusCode()} " . json_encode($unverifiedResolution->getDecodedBody())
    );
    $assert(
        '4.3 La justificación escrita queda archivada junto al dictamen',
        str_contains((string)$unverifiedRow?->getTechnicianJustification(), 'embudo')
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Desacoplamiento del ciclo técnico (RF-REF-09, Art. III) ---\n";

    $assert(
        '5.1 La avería se resuelve aunque el expediente siga pendiente de dinero',
        $incidents->findById((int)$digitalIncident->getId())?->getStatus() === IncidentStatus::RESOLVED
        && $refunds->findById($digitalCaseId)?->getStatus() === RefundStatus::VERIFIED_PENDING_PAYMENT
    );
    $assert(
        '5.1 Cerrar la avería no liquida, ni rechaza, ni da por entregado el expediente',
        !$refunds->findById($digitalCaseId)?->getStatus()->isTerminal()
    );
    $assert(
        '5.2 El expediente sobrevive al cierre técnico y sigue activo (Art. III.1)',
        (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests` WHERE `id` = ' . $digitalCaseId
            . ' AND `is_active` = 1')->fetchColumn() === 1
    );

    $coordinatorInbox = $dispatch(
        'GET',
        '/api/coordinator/refunds',
        null,
        ['authorization' => 'Bearer ' . $coordinatorToken, 'content-type' => 'application/json']
    );
    $inboxIds = [];
    foreach ($data($coordinatorInbox)['items'] ?? [] as $entry) {
        $inboxIds[] = (int)($entry['id'] ?? 0);
    }
    $assert(
        '5.2 El expediente superviviente sigue en la bandeja de Coordinación',
        $coordinatorInbox->getStatusCode() === 200
        && in_array($digitalCaseId, $inboxIds, true)
        && in_array($elevatedCaseId, $inboxIds, true),
        "HTTP {$coordinatorInbox->getStatusCode()} " . json_encode($data($coordinatorInbox))
    );

    $refundRowsAfter = (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests`')->fetchColumn();
    $assert(
        '5.3 Ni un solo expediente se ha borrado físicamente en todo el recorrido (Art. III.1)',
        $refundRowsAfter === $refundRowsBefore + 5,
        "antes: {$refundRowsBefore} | después: {$refundRowsAfter}"
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Hallazgo de monedas de oficio (RF-REF-04) ---\n";

    $withoutAmount = $dispatch('POST', "/api/technician/incidents/{$officeIncident->getId()}/resolve", [
        'resolution_diagnosis' => $validDiagnosis,
        'resolution_action' => $validAction,
        'unclaimed_cash_found' => ['amount' => 0, 'notes' => 'Moneda suelta en la canaleta.'],
    ], $techHeaders);
    $assert(
        '6.1 Un hallazgo de oficio debe tener un importe mayor que cero',
        $withoutAmount->getStatusCode() === 422
        && $errorCode($withoutAmount) === 'INVALID_UNCLAIMED_CASH',
        "HTTP {$withoutAmount->getStatusCode()} " . json_encode($errorCode($withoutAmount))
    );
    $assert(
        '6.1 Un importe inválido no deja ni un céntimo sin registrar ni cierra la avería',
        $incidents->findById((int)$officeIncident->getId())?->getStatus() === IncidentStatus::IN_PROGRESS
        && (int)$pdo->query('SELECT COUNT(*) FROM `unclaimed_cash_findings` WHERE `incident_id` = '
            . (int)$officeIncident->getId())->fetchColumn() === 0
    );

    $officeResolution = $dispatch('POST', "/api/technician/incidents/{$officeIncident->getId()}/resolve", [
        'resolution_diagnosis' => $validDiagnosis,
        'resolution_action' => $validAction,
        'unclaimed_cash_found' => [
            'amount' => 1.50,
            'notes' => 'Moneda de un euro y céntimos en la canaleta de la máquina.',
        ],
    ], $techHeaders);
    $officeBody = $data($officeResolution);
    $findingId = (int)($officeBody['unclaimed_cash_finding_id'] ?? 0);
    $assert(
        '6.2 Sin reclamación previa la avería se resuelve sin exigir dictamen de saldo',
        $officeResolution->getStatusCode() === 200
        && !array_key_exists('refund_status', $officeBody)
        && $incidents->findById((int)$officeIncident->getId())?->getStatus() === IncidentStatus::RESOLVED,
        "HTTP {$officeResolution->getStatusCode()} " . json_encode($officeResolution->getDecodedBody())
    );
    $assert('6.2 La respuesta expone el hallazgo de oficio registrado', $findingId > 0);

    $findingStatement = $pdo->prepare('
        SELECT `incident_id`, `machine_id`, `technician_id`, `amount`, `notes`
        FROM `unclaimed_cash_findings`
        WHERE `id` = :id
    ');
    $findingStatement->execute([':id' => $findingId]);
    $findingRow = $findingStatement->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        '6.3 El sobrante queda anclado a la avería, la máquina y el técnico que lo encontró',
        (int)($findingRow['incident_id'] ?? 0) === (int)$officeIncident->getId()
        && (int)($findingRow['machine_id'] ?? 0) === (int)$officeMachine->getId()
        && (int)($findingRow['technician_id'] ?? 0) === $techId
    );
    $assert(
        '6.3 El importe y la nota del hallazgo se conservan literalmente',
        (float)($findingRow['amount'] ?? 0) === 1.50
        && str_contains((string)($findingRow['notes'] ?? ''), 'canaleta')
    );
    $assert(
        '6.4 El hallazgo de oficio se audita como entidad propia (Art. III.3)',
        (int)$pdo->query('
            SELECT COUNT(*) FROM `audit_log`
            WHERE `entity_type` = \'UNCLAIMED_CASH_FINDING\'
              AND `entity_id` = ' . $findingId . '
              AND `action` = \'UNCLAIMED_CASH_RECORDED\'
              AND `user_id` = ' . $techId
        )->fetchColumn() === 1
    );
    $assert(
        '6.4 El efectivo de oficio no se confunde con un reintegro de un reclamante',
        (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests` WHERE `id` = ' . $findingId)->fetchColumn() === 0
            && (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests` WHERE `incident_id` = '
                . (int)$officeIncident->getId() . ' AND `is_active` = 1')->fetchColumn() === 0
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 7. Blindaje constitucional del flujo (Art. III, Art. V.4, RNF-REF-04) ---\n";

    $allTechnicianResponses = implode('', [
        $deskView->getBody(),
        $digitalView->getBody(),
        $deskResolution->getBody(),
        $digitalResolution->getBody(),
        $elevatedResolution->getBody(),
        $noCashResolution->getBody(),
        $unverifiedResolution->getBody(),
        $officeResolution->getBody(),
    ]);
    $assert(
        '7.1 Ninguna respuesta del técnico filtra IBAN ni teléfono Bizum',
        !str_contains($allTechnicianResponses, 'bizum_phone')
        && !str_contains($allTechnicianResponses, 'iban')
    );

    $inspectedCount = (int)$pdo->query('
        SELECT COUNT(*) FROM `audit_log`
        WHERE `entity_type` = \'REFUND_REQUEST\' AND `action` = \'REFUND_INSPECTED\'
    ')->fetchColumn();
    $assert(
        '7.2 Cada uno de los cinco dictamines emitidos dejó su rastro inmutable',
        $inspectedCount >= 5,
        "REFUND_INSPECTED en audit_log: {$inspectedCount}"
    );

    $deskHistory = $pdo->prepare('
        SELECT `action_note`
        FROM `incident_history`
        WHERE `incident_id` = :id
        ORDER BY `id` DESC
        LIMIT 1
    ');
    $deskHistory->execute([':id' => (int)$deskIncident->getId()]);
    $historyNote = (string)(($deskHistory->fetch(PDO::FETCH_ASSOC) ?: [])['action_note'] ?? '');
    $assert(
        '7.3 El cierre técnico conserva diagnóstico y solución (Art. V.1)',
        str_contains($historyNote, $validDiagnosis) && str_contains($historyNote, $validAction)
    );

    $assert(
        '7.4 La ruta de técnico rechaza un identificador de incidencia no numérico',
        $dispatch('GET', '/api/technician/incidents/abc/refund', null, $techHeaders)->getStatusCode() === 400
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-20 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
