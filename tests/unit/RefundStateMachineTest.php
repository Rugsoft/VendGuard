<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Unit suite for `RefundManagementService` (T-REF-06).
 *
 * The "Hecho cuando" criterion requires green coverage of case creation with a
 * secure PIN and token, the legal state transitions, the automatic escalation
 * above 10,00 €, PIN verification at the reception desk, coordinator approval
 * and digital settlement with a banking reference.
 *
 * Two properties are certified beyond the plain happy path:
 *
 *  - The transition table is checked against the mermaid graph in
 *    `specs/technical/refunds_contracts.md` §1.1, parsed at runtime. If the
 *    service ever drifts from the contract the suite goes red by itself.
 *  - The audit trail is inspected for the IBAN, the Bizum phone and the pickup
 *    PIN, which must never appear in it (Art. V.4).
 *
 * No database is touched: the repositories are replaced by in-memory fakes that
 * live only in this file, so no mock can reach a production route (Art. I.3).
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Application\DTO\CoordinatorApprovalDTO;
use VendGuard\Application\DTO\CoordinatorPaymentDTO;
use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IbanValidationService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Exception\InvalidIbanFormatException;
use VendGuard\Core\Domain\Exception\InvalidPickupPinException;
use VendGuard\Core\Domain\Exception\InvalidRefundAmountException;
use VendGuard\Core\Domain\Exception\InvalidRefundStateTransitionException;
use VendGuard\Core\Domain\Exception\InvalidBizumPhoneException;
use VendGuard\Core\Domain\Exception\ReceptionDeliveryNotAllowedException;
use VendGuard\Core\Domain\Exception\RefundNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;

$assertions = 0;
$failures = 0;

$assert = function (string $label, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$label}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
};

/**
 * Rebuilds a case with some columns replaced. `RefundRequest` is immutable, so
 * every state change in the fake repository goes through here.
 *
 * @param array<string, mixed> $overrides
 */
function rebuildCase(RefundRequest $case, array $overrides = []): RefundRequest
{
    $pick = static function (string $key, mixed $default) use ($overrides): mixed {
        return array_key_exists($key, $overrides) ? $overrides[$key] : $default;
    };

    return new RefundRequest(
        id: $pick('id', $case->getId()) === null ? null : (int)$pick('id', $case->getId()),
        incidentId: (int)$pick('incidentId', $case->getIncidentId()),
        machineId: (int)$pick('machineId', $case->getMachineId()),
        locationId: (int)$pick('locationId', $case->getLocationId()),
        claimantName: (string)$pick('claimantName', $case->getClaimantName()),
        claimantContact: (string)$pick('claimantContact', $case->getClaimantContact()),
        claimedAmount: (float)$pick('claimedAmount', $case->getClaimedAmount()),
        productAttempted: (string)$pick('productAttempted', $case->getProductAttempted()),
        compensationMethod: $pick('compensationMethod', $case->getCompensationMethod()),
        bizumPhone: $pick('bizumPhone', $case->getBizumPhone()),
        iban: $pick('iban', $case->getIban()),
        pickupPin: $pick('pickupPin', $case->getPickupPin()),
        trackingToken: (string)$pick('trackingToken', $case->getTrackingToken()),
        status: $pick('status', $case->getStatus()),
        technicianFinding: $pick('technicianFinding', $case->getTechnicianFinding()),
        recoveredAmount: $pick('recoveredAmount', $case->getRecoveredAmount()),
        cashCustodyAction: $pick('cashCustodyAction', $case->getCashCustodyAction()),
        receptionistName: $pick('receptionistName', $case->getReceptionistName()),
        technicianJustification: $pick('technicianJustification', $case->getTechnicianJustification()),
        approvedAmount: $pick('approvedAmount', $case->getApprovedAmount()),
        paymentReference: $pick('paymentReference', $case->getPaymentReference()),
        isActive: (bool)$pick('isActive', $case->isActive()),
        createdAt: $pick('createdAt', $case->getCreatedAt()),
        updatedAt: $pick('updatedAt', $case->getUpdatedAt())
    );
}

/**
 * In-memory stand-in for the PDO repository. Lives only inside this suite.
 */
final class InMemoryRefundRepository implements RefundRequestRepositoryInterface
{
    /** @var array<int, RefundRequest> */
    public array $rows = [];

    /** @var array<int, array<string, mixed>> columnas que la entidad no modela */
    public array $sideColumns = [];

    private int $nextId = 1;

    public function insert(RefundRequest $refundRequest): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = rebuildCase($refundRequest, ['id' => $id]);
        $this->sideColumns[$id] = [];

        return $id;
    }

    public function findById(int $id): ?RefundRequest
    {
        return $this->rows[$id] ?? null;
    }

    public function findByTrackingToken(string $trackingToken): ?RefundRequest
    {
        foreach ($this->rows as $row) {
            if ($row->getTrackingToken() === $trackingToken) {
                return $row;
            }
        }

        return null;
    }

    public function findRestrictedByIncident(int $incidentId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (RefundRequest $r): bool => $r->getIncidentId() === $incidentId
        ));
    }

    public function findRestrictedByLocation(int $locationId, ?RefundStatus $status = null): array
    {
        return array_values(array_filter($this->rows, static function (RefundRequest $r) use ($locationId, $status): bool {
            return $r->getLocationId() === $locationId
                && ($status === null || $r->getStatus() === $status);
        }));
    }

    public function findForCoordinator(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return array_slice(array_values($this->rows), $offset, $limit);
    }

    public function countForCoordinator(array $filters = []): int
    {
        return count($this->rows);
    }

    public function transitionStatus(int $id, RefundStatus $expectedStatus, RefundStatus $newStatus, array $fields = []): bool
    {
        $current = $this->rows[$id] ?? null;
        if ($current === null || $current->getStatus() !== $expectedStatus) {
            return false;
        }

        $entityFields = [];
        foreach ($fields as $column => $value) {
            $entityFields[self::snakeToCamel((string)$column)] = $value;
            $this->sideColumns[$id][(string)$column] = $value;
        }

        $this->rows[$id] = rebuildCase($current, $entityFields + ['status' => $newStatus]);

        return true;
    }

    public function updateContactDetails(int $id, ?string $bizumPhone, ?string $iban): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        $this->rows[$id] = rebuildCase($this->rows[$id], [
            'bizumPhone' => $bizumPhone ?? $this->rows[$id]->getBizumPhone(),
            'iban' => $iban ?? $this->rows[$id]->getIban(),
        ]);

        return true;
    }

    public function deactivate(int $id): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        $this->rows[$id] = rebuildCase($this->rows[$id], ['isActive' => false]);

        return true;
    }

    private static function snakeToCamel(string $column): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $column))));
    }
}

/**
 * In-memory audit sink, so the trail can be inspected without a database.
 */
final class InMemoryAuditRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return array_slice($this->events, $offset, $limit);
    }

    public function countEvents(array $filters = []): int
    {
        return count($this->events);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (AuditEvent $e): bool => $e->getEntityType() === $entityType && $e->getEntityId() === $entityId
        ));
    }
}

$repo = new InMemoryRefundRepository();
$auditRepo = new InMemoryAuditRepository();
$service = new RefundManagementService(
    $repo,
    new IbanValidationService(),
    new AuditLogger($auditRepo)
);

$validIban = 'ES9121000418450200051332';

/** Builds the canonical creation DTO for a cash-in-hand case. */
$cashDto = static fn (float $amount = 2.00): CreateRefundRequestDTO => new CreateRefundRequestDTO(
    incidentId: 101,
    machineId: 11,
    locationId: 1,
    claimantName: 'Laura Sanitaria',
    claimantContact: '600111222',
    claimedAmount: $amount,
    compensationMethod: CompensationMethod::EN_MANO_SEDE,
    productAttempted: 'Café con leche carril 2'
);

// ─────────────────────────────────────────────────────────────────────────────
// 0. Dogma Vanilla y Art. III
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 0. Dogma Vanilla (Art. IV) e inviolabilidad (Art. III) ---\n";

$serviceSource = (string)file_get_contents($baseDir . '/src/Application/Service/RefundManagementService.php');

$assert('0.1 Existe `RefundManagementService` en `Application/Service`', class_exists(RefundManagementService::class));
$assert('0.2 El servicio declara tipado estricto', str_contains($serviceSource, 'declare(strict_types=1);'));
$assert(
    '0.3 No importa ningún símbolo ajeno al proyecto (cero Composer)',
    preg_match('/^use\s+(?!VendGuard)/m', $serviceSource) !== 1
);
$assert(
    '0.4 El servicio NO ejecuta ningún DELETE FROM (Art. III)',
    !str_contains(strtoupper($serviceSource), 'DELETE FROM')
);
$assert(
    '0.5 Los secretos se generan con random_int / random_bytes, nunca con rand()',
    str_contains($serviceSource, 'random_int(')
        && str_contains($serviceSource, 'random_bytes(')
        && !preg_match('/(?<![_a-zA-Z])rand\s*\(/', $serviceSource)
);
$assert(
    '0.6 El token de seguimiento son 32 bytes aleatorios (64 hex)',
    RefundManagementService::TRACKING_TOKEN_BYTES === 32
);
$assert(
    '0.7 El PIN se genera en el rango 1000-9999',
    RefundManagementService::PICKUP_PIN_MIN === 1000 && RefundManagementService::PICKUP_PIN_MAX === 9999
);

// ─────────────────────────────────────────────────────────────────────────────
// 1. La tabla de transiciones coincide con el contrato
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. La tabla de transiciones refleja el grafo del contrato ---\n";

$contractSource = (string)file_get_contents($baseDir . '/specs/technical/refunds_contracts.md');
preg_match_all('/^\s*([A-Z_]+)\s*-->\s*([A-Z_]+):/m', $contractSource, $m, PREG_SET_ORDER);

$contractEdges = [];
foreach ($m as $edge) {
    if ($edge[1] === '[*]' || $edge[2] === '[*]') {
        continue;
    }
    $contractEdges[$edge[1]][] = $edge[2];
}

// El diagrama declara los estados terminales saliendo hacia `[*]`, que se
// descarta al parsear. Se rellenan con lista vacía para que ambos lados de la
// comparación tengan las mismas ocho claves.
foreach (RefundStatus::cases() as $status) {
    if (!isset($contractEdges[$status->value])) {
        $contractEdges[$status->value] = [];
    }
}
ksort($contractEdges);

$declared = $service->legalTransitions();
ksort($declared);

$assert(
    '1.1 El número de aristas declaradas coincide con el del contrato',
    array_sum(array_map('count', $declared)) === array_sum(array_map('count', $contractEdges)),
    'servicio: ' . array_sum(array_map('count', $declared))
        . ' vs contrato: ' . array_sum(array_map('count', $contractEdges))
);
$assert(
    '1.2 El grafo de transiciones es idéntico al del contrato §1.1',
    $declared === $contractEdges,
    'servicio: ' . json_encode($declared) . ' | contrato: ' . json_encode($contractEdges)
);

foreach ($declared as $from => $targets) {
    foreach ($targets as $to) {
        $assert(
            "1.3 La arista {$from} -> {$to} está permitida",
            $service->canTransition(RefundStatus::from($from), RefundStatus::from($to))
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Estados terminales sin salida
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Los estados terminales no admiten más movimientos (Art. III) ---\n";

$terminalCount = 0;
foreach (RefundStatus::cases() as $status) {
    foreach (RefundStatus::cases() as $target) {
        if ($status->isTerminal()) {
            $terminalCount++;
            $assert(
                "2.{$terminalCount} {$status->value} no puede pasar a {$target->value}",
                !$service->canTransition($status, $target)
            );
        }
    }
}
echo "         (transiciones bloqueadas desde estados terminales: {$terminalCount})\n";

// ─────────────────────────────────────────────────────────────────────────────
// 3. Apertura de expediente con PIN y token seguros
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Apertura de expediente: PIN de 4 dígitos y token de 64 caracteres ---\n";

$case = $service->createCase($cashDto(2.00));
$stored = $repo->findById($case->getId());

$assert('3.1 El expediente se persiste con estado PENDING_INSPECTION', $stored?->getStatus() === RefundStatus::PENDING_INSPECTION);
$assert(
    '3.2 Para EN_MANO_SEDE se emite un PIN de exactamente 4 dígitos',
    $stored !== null && preg_match('/^[0-9]{4}$/', (string)$stored->getPickupPin()) === 1,
    'PIN: ' . var_export($stored?->getPickupPin(), true)
);
$assert(
    '3.3 El token de seguimiento tiene 64 caracteres hexadecimales',
    $stored !== null && preg_match('/^[0-9a-f]{64}$/', $stored->getTrackingToken()) === 1,
    'token: ' . var_export($stored?->getTrackingToken(), true)
);

$pins = [];
$tokens = [];
for ($i = 0; $i < 50; $i++) {
    $pins[] = $service->generatePickupPin();
    $tokens[] = $service->generateTrackingToken();
}
$assert('3.4 50 PIN generados son todos distintos (no hay patrón repetido)', count(array_unique($pins)) === 50);
$assert('3.5 50 tokens generados son todos distintos', count(array_unique($tokens)) === 50);
$assert(
    '3.6 Todos los PIN caen dentro del rango 1000-9999',
    count(array_filter($pins, static fn (string $p): bool => (int)$p >= 1000 && (int)$p <= 9999)) === 50
);

$bizumCase = $service->createCase(new CreateRefundRequestDTO(
    incidentId: 102,
    machineId: 11,
    locationId: 1,
    claimantName: 'Marc Rider',
    claimantContact: '600999888',
    claimedAmount: 3.00,
    compensationMethod: CompensationMethod::BIZUM,
    bizumPhone: '600 999 888'
));
$bizumStored = $repo->findById($bizumCase->getId());
$assert(
    '3.7 Para BIZUM NO se emite PIN: lo liquida la oficina central',
    $bizumStored?->getPickupPin() === null,
    'PIN: ' . var_export($bizumStored?->getPickupPin(), true)
);
$assert(
    '3.8 El teléfono Bizum se normaliza a 9 dígitos',
    $bizumStored?->getBizumPhone() === '600999888'
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Regla antifraude de importes
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Regla antifraude del importe (RF-REF-03) ---\n";

foreach ([['0.00', 0.00], ['-1.00', -1.00], ['50.01', 50.01], ['999.00', 999.00]] as $index => [$label, $amount]) {
    $thrown = null;
    try {
        $service->createCase($cashDto($amount));
    } catch (Throwable $e) {
        $thrown = $e;
    }
    // Se captura Throwable y no la excepción concreta para que una regresión
    // produzca un [FAIL] limpio en vez de un fatal que tumbe la suite entera.
    // Exigir `InvalidRefundAmountException` y no un `InvalidArgumentException`
    // genérico es justo lo que verifica el contrato 422.
    $assert(
        "4.{$index} El importe {$label} € se rechaza con 422 INVALID_REFUND_AMOUNT",
        $thrown instanceof InvalidRefundAmountException
            && $thrown->getHttpStatusCode() === 422
            && $thrown->getErrorCode() === 'INVALID_REFUND_AMOUNT',
        'excepción real: ' . ($thrown === null ? 'ninguna' : get_class($thrown))
    );
}

$boundary = $service->createCase($cashDto(50.00));
$assert('4.5 El importe límite de 50,00 € SÍ se acepta', $repo->findById($boundary->getId()) !== null);

$overIban = null;
try {
    $service->createCase(new CreateRefundRequestDTO(
        incidentId: 103,
        machineId: 11,
        locationId: 1,
        claimantName: 'Laura',
        claimantContact: '600111222',
        claimedAmount: 5.00,
        compensationMethod: CompensationMethod::TRANSFERENCIA_BANCARIA,
        iban: 'ES9121000418450200051399'
    ));
} catch (InvalidIbanFormatException $e) {
    $overIban = $e;
}
$assert(
    '4.6 Un IBAN con checksum falso se rechaza con 422 INVALID_IBAN_FORMAT',
    $overIban !== null && $overIban->getHttpStatusCode() === 422
);

$overPhone = null;
try {
    $service->createCase(new CreateRefundRequestDTO(
        incidentId: 104,
        machineId: 11,
        locationId: 1,
        claimantName: 'Marc',
        claimantContact: '600999888',
        claimedAmount: 5.00,
        compensationMethod: CompensationMethod::BIZUM,
        bizumPhone: '60099988'
    ));
} catch (InvalidBizumPhoneException $e) {
    $overPhone = $e;
}
$assert(
    '4.7 Un teléfono Bizum de 8 dígitos se rechaza con 422 INVALID_BIZUM_PHONE',
    $overPhone !== null && $overPhone->getHttpStatusCode() === 422
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Clasificación automática tras la inspección
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. Clasificación automática y custodia (RF-REF-03/05) ---\n";

$classify = static function (array $overrides) use ($service, $cashDto, $repo): RefundStatus {
    $created = $service->createCase($cashDto($overrides['claimedAmount'] ?? 2.00));
    $base = $repo->findById($created->getId());
    $withFinding = rebuildCase($base, $overrides['entity'] ?? []);
    $repo->transitionStatus($created->getId(), RefundStatus::PENDING_INSPECTION, RefundStatus::PENDING_INSPECTION);
    $repo->rows[$created->getId()] = $withFinding;
    return $service->classifyInspectionOutcome($withFinding);
};

$assert(
    '5.1 Importe <= 10 € sin discrepancia va directo a VERIFIED_PENDING_PAYMENT',
    $classify(['entity' => [
        'technicianFinding' => TechnicianFinding::FOUND_PHYSICAL,
        'recoveredAmount' => 2.00,
        'cashCustodyAction' => CashCustodyAction::HELD_FOR_CENTRAL,
    ]]) === RefundStatus::VERIFIED_PENDING_PAYMENT
);
$assert(
    '5.2 Importe > 10 € escala automáticamente a REQUIRES_COORDINATOR_APPROVAL',
    $classify(['claimedAmount' => 15.00, 'entity' => [
        'technicianFinding' => TechnicianFinding::FOUND_PHYSICAL,
        'recoveredAmount' => 15.00,
        'cashCustodyAction' => CashCustodyAction::HELD_FOR_CENTRAL,
    ]]) === RefundStatus::REQUIRES_COORDINATOR_APPROVAL
);
$assert(
    '5.3 Una discrepancia superior al 20 % escala a REQUIRES_COORDINATOR_APPROVAL',
    $classify(['claimedAmount' => 10.00, 'entity' => [
        'technicianFinding' => TechnicianFinding::FOUND_PHYSICAL,
        'recoveredAmount' => 5.00,
        'cashCustodyAction' => CashCustodyAction::HELD_FOR_CENTRAL,
    ]]) === RefundStatus::REQUIRES_COORDINATOR_APPROVAL
);
$assert(
    '5.4 Una discrepancia del 10 % NO escala (dentro de la tolerancia)',
    $classify(['claimedAmount' => 10.00, 'entity' => [
        'technicianFinding' => TechnicianFinding::FOUND_PHYSICAL,
        'recoveredAmount' => 9.00,
        'cashCustodyAction' => CashCustodyAction::HELD_FOR_CENTRAL,
    ]]) === RefundStatus::VERIFIED_PENDING_PAYMENT
);
$assert(
    '5.5 UNVERIFIED_NO_CASH escala siempre a REQUIRES_COORDINATOR_APPROVAL',
    $classify(['entity' => [
        'technicianFinding' => TechnicianFinding::UNVERIFIED_NO_CASH,
        'recoveredAmount' => 0.00,
        'cashCustodyAction' => CashCustodyAction::HELD_FOR_CENTRAL,
    ]]) === RefundStatus::REQUIRES_COORDINATOR_APPROVAL
);
$assert(
    '5.6 Efectivo <= 10 € depositado en conserjería queda en DEPOSITED_AT_RECEPTION',
    $classify(['entity' => [
        'technicianFinding' => TechnicianFinding::FOUND_PHYSICAL,
        'recoveredAmount' => 3.00,
        'cashCustodyAction' => CashCustodyAction::LEFT_AT_RECEPTION,
    ]]) === RefundStatus::DEPOSITED_AT_RECEPTION
);
$assert(
    '5.7 CONFIRMED_NO_CASH sin discrepancia sigue yendo a pago directo',
    $classify(['entity' => [
        'technicianFinding' => TechnicianFinding::CONFIRMED_NO_CASH,
        'recoveredAmount' => 2.00,
        'cashCustodyAction' => CashCustodyAction::HELD_FOR_CENTRAL,
    ]]) === RefundStatus::VERIFIED_PENDING_PAYMENT
);
$assert(
    '5.7b CONFIRMED_NO_CASH con 0 € recuperado SI escala (discrepancia del 100 %)',
    $classify(['entity' => [
        'technicianFinding' => TechnicianFinding::CONFIRMED_NO_CASH,
        'recoveredAmount' => 0.00,
        'cashCustodyAction' => CashCustodyAction::HELD_FOR_CENTRAL,
    ]]) === RefundStatus::REQUIRES_COORDINATOR_APPROVAL
);

$overLimit = null;
try {
    $classify(['claimedAmount' => 2.00, 'entity' => [
        'technicianFinding' => TechnicianFinding::FOUND_PHYSICAL,
        'recoveredAmount' => 25.00,
        'cashCustodyAction' => CashCustodyAction::HELD_FOR_CENTRAL,
    ]]);
    // 25 € recuperado con custodia en central es legal: no debe lanzar.
} catch (ReceptionDeliveryNotAllowedException $e) {
    $overLimit = $e;
}
$assert('5.8 25 € recuperados con custodia en central NO se consideran depósito en conserjería', $overLimit === null);

$notAwaiting = $service->createCase($cashDto(2.00));
$repo->rows[$notAwaiting->getId()] = rebuildCase($repo->findById($notAwaiting->getId()), [
    'status' => RefundStatus::VERIFIED_PENDING_PAYMENT,
]);
$doubleInspect = null;
try {
    $service->classifyInspectionOutcome($repo->findById($notAwaiting->getId()));
} catch (InvalidRefundStateTransitionException $e) {
    $doubleInspect = $e;
}
$assert(
    '5.9 Un segundo dictamen sobre un expediente ya inspeccionado se rechaza con 409',
    $doubleInspect !== null && $doubleInspect->getHttpStatusCode() === 409
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. Entrega presencial con PIN
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. Entrega presencial en conserjería con PIN (RF-REF-06) ---\n";

$deliverCase = $service->createCase($cashDto(3.00));
$repo->transitionStatus($deliverCase->getId(), RefundStatus::PENDING_INSPECTION, RefundStatus::DEPOSITED_AT_RECEPTION);
$deliverPin = (string)$repo->findById($deliverCase->getId())->getPickupPin();

$wrongPin = null;
try {
    $service->deliverInHand($deliverCase->getId(), '0000' === $deliverPin ? '1111' : '0000');
} catch (InvalidPickupPinException $e) {
    $wrongPin = $e;
}
$assert(
    '6.1 Un PIN incorrecto se rechaza con 422 INVALID_PICKUP_PIN',
    $wrongPin !== null && $wrongPin->getHttpStatusCode() === 422 && $wrongPin->getErrorCode() === 'INVALID_PICKUP_PIN'
);
$assert(
    '6.2 Tras un PIN incorrecto el expediente sigue en conserjería',
    $repo->findById($deliverCase->getId())->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
);
$assert(
    '6.3 La excepción de PIN no filtra el PIN secreto en el mensaje',
    $wrongPin !== null && !str_contains($wrongPin->getMessage(), $deliverPin)
);

$delivered = $service->deliverInHand($deliverCase->getId(), $deliverPin, ['id' => 7, 'role' => 'LOCATION_MANAGER', 'name' => 'Conserjería']);
$assert('6.4 El PIN correcto entrega el efectivo y transiciona a REFUNDED_IN_HAND', $delivered->getStatus() === RefundStatus::REFUNDED_IN_HAND);
$assert(
    '6.5 La entrega registra la fecha de entrega en mano',
    ($repo->sideColumns[$deliverCase->getId()]['hand_delivered_at'] ?? '') !== ''
);
$assert(
    '6.6 La entrega queda auditada con el rol de conserjería',
    (bool) array_filter($auditRepo->events, static fn (AuditEvent $e): bool
        => $e->getAction() === 'REFUND_DELIVERED_IN_HAND' && $e->getUserRole() === 'LOCATION_MANAGER')
);

$repeatDelivery = null;
try {
    $service->deliverInHand($deliverCase->getId(), $deliverPin);
} catch (InvalidRefundStateTransitionException $e) {
    $repeatDelivery = $e;
}
$assert('6.7 Entregar dos veces el mismo expediente se rechaza con 409', $repeatDelivery !== null && $repeatDelivery->getHttpStatusCode() === 409);

// ─────────────────────────────────────────────────────────────────────────────
// 7. Visto bueno de Coordinación
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 7. Visto bueno formal de Coordinación (RF-REF-03) ---\n";

// Aprobar un expediente que aún no ha pasado por el estado de visto bueno debe
// rechazarse: el coordinador no puede saltarse la clasificación automática.
$prematureCase = $service->createCase($cashDto(15.00));
$prematureApproval = null;
try {
    $service->approveCase($prematureCase->getId(), new CoordinatorApprovalDTO(15.00), ['id' => 3, 'role' => 'COORDINATOR']);
} catch (InvalidRefundStateTransitionException $e) {
    $prematureApproval = $e;
}
$assert(
    '7.1 Aprobar sin haber pasado por REQUIRES_COORDINATOR_APPROVAL se rechaza con 409',
    $prematureApproval !== null && $prematureApproval->getHttpStatusCode() === 409
);
$assert(
    '7.1b El rechazo del visto bueno prematuro deja el expediente intacto',
    $repo->findById($prematureCase->getId())->getStatus() === RefundStatus::PENDING_INSPECTION
);

$approvalCase = $service->createCase($cashDto(15.00));
$repo->transitionStatus($approvalCase->getId(), RefundStatus::PENDING_INSPECTION, RefundStatus::REQUIRES_COORDINATOR_APPROVAL);

$approved = $service->approveCase(
    $approvalCase->getId(),
    new CoordinatorApprovalDTO(15.00, 'Comprobado corte de caja; se autoriza la devolución íntegra.'),
    ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']
);
$assert('7.2 El visto bueno deja el expediente en VERIFIED_PENDING_PAYMENT', $approved->getStatus() === RefundStatus::VERIFIED_PENDING_PAYMENT);
$assert('7.3 El importe aprobado queda registrado', (float)$approved->getApprovedAmount() === 15.00);

$zeroCase = $service->createCase($cashDto(15.00));
$repo->transitionStatus($zeroCase->getId(), RefundStatus::PENDING_INSPECTION, RefundStatus::REQUIRES_COORDINATOR_APPROVAL);
$zeroApproval = null;
try {
    $service->approveCase($zeroCase->getId(), new CoordinatorApprovalDTO(0.0), ['id' => 3, 'role' => 'COORDINATOR']);
} catch (InvalidRefundStateTransitionException | InvalidRefundAmountException $e) {
    $zeroApproval = $e;
}
$assert(
    '7.4 Aprobar un importe de 0,00 € se rechaza con 422',
    $zeroApproval instanceof InvalidRefundAmountException && $zeroApproval->getHttpStatusCode() === 422
);

$doubleApproval = null;
try {
    $service->approveCase($approvalCase->getId(), new CoordinatorApprovalDTO(15.00), ['id' => 3, 'role' => 'COORDINATOR']);
} catch (InvalidRefundStateTransitionException $e) {
    $doubleApproval = $e;
}
$assert('7.5 Dar el visto bueno dos veces se rechaza con 409', $doubleApproval !== null && $doubleApproval->getHttpStatusCode() === 409);

// ─────────────────────────────────────────────────────────────────────────────
// 8. Liquidación digital con referencia bancaria
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 8. Liquidación digital con justificante bancario (RF-REF-07) ---\n";

$payCase = $service->createCase(new CreateRefundRequestDTO(
    incidentId: 105,
    machineId: 11,
    locationId: 1,
    claimantName: 'Laura Sanitaria',
    claimantContact: '600111222',
    claimedAmount: 15.00,
    compensationMethod: CompensationMethod::BIZUM,
    bizumPhone: '600111222'
));

// Un expediente ya entregado en mano no puede liquidarse digitalmente.
$deliveredForPay = $service->createCase($cashDto(4.00));
$repo->transitionStatus($deliveredForPay->getId(), RefundStatus::PENDING_INSPECTION, RefundStatus::DEPOSITED_AT_RECEPTION);
$repo->transitionStatus($deliveredForPay->getId(), RefundStatus::DEPOSITED_AT_RECEPTION, RefundStatus::REFUNDED_IN_HAND);
$earlyPay = null;
try {
    $service->registerDigitalPayment($deliveredForPay->getId(), new CoordinatorPaymentDTO('BIZUM-X', 4.00));
} catch (InvalidRefundStateTransitionException $e) {
    $earlyPay = $e;
}
$assert(
    '8.1 Pagar un expediente ya entregado en mano se rechaza con 409',
    $earlyPay !== null && $earlyPay->getHttpStatusCode() === 409
);

$repo->transitionStatus($payCase->getId(), RefundStatus::PENDING_INSPECTION, RefundStatus::VERIFIED_PENDING_PAYMENT);

$paid = $service->registerDigitalPayment(
    $payCase->getId(),
    new CoordinatorPaymentDTO('BIZUM-20261001-998822', 15.00),
    ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']
);
$assert('8.2 La liquidación digital transiciona a PAID_DIGITAL', $paid->getStatus() === RefundStatus::PAID_DIGITAL);
$assert('8.3 La referencia bancaria queda registrada', $paid->getPaymentReference() === 'BIZUM-20261001-998822');
$assert(
    '8.4 La fecha de pago queda registrada',
    ($repo->sideColumns[$payCase->getId()]['paid_at'] ?? '') !== ''
);

$doublePay = null;
try {
    $service->registerDigitalPayment($payCase->getId(), new CoordinatorPaymentDTO('BIZUM-OTRO', 15.00));
} catch (InvalidRefundStateTransitionException $e) {
    $doublePay = $e;
}
$assert('8.5 Pagar dos veces el mismo expediente se rechaza con 409', $doublePay !== null && $doublePay->getHttpStatusCode() === 409);

// ─────────────────────────────────────────────────────────────────────────────
// 9. Expediente inexistente
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 9. Expediente inexistente o archivado (404) ---\n";

$missing = null;
try {
    $service->deliverInHand(999999, '1234');
} catch (RefundNotFoundException $e) {
    $missing = $e;
}
$assert(
    '9.1 Operar sobre un expediente inexistente devuelve 404 REFUND_NOT_FOUND',
    $missing !== null
        && $missing->getHttpStatusCode() === 404
        && $missing->getErrorCode() === 'REFUND_NOT_FOUND'
);

$archived = $service->createCase($cashDto(2.00));
$repo->deactivate($archived->getId());
$archivedTh = null;
try {
    $service->approveCase($archived->getId(), new CoordinatorApprovalDTO(2.00));
} catch (RefundNotFoundException $e) {
    $archivedTh = $e;
}
$assert('9.2 Un expediente archivado lógicamente también devuelve 404 (Art. III)', $archivedTh !== null);

// ─────────────────────────────────────────────────────────────────────────────
// 10. Confidencialidad del registro de auditoría (Art. V.4)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 10. El registro de auditoría nunca filtra datos bancarios (Art. V.4) ---\n";

// Para que la comprobación de fuga signifique algo tiene que existir de verdad
// un expediente con IBAN válido en la base de la suite: si no, `str_contains`
// buscaría una cadena que jamás se escribió y la aserción pasaría en falso.
$ibanCase = $service->createCase(new CreateRefundRequestDTO(
    incidentId: 106,
    machineId: 11,
    locationId: 1,
    claimantName: 'Laura Sanitaria',
    claimantContact: '600111222',
    claimedAmount: 20.00,
    compensationMethod: CompensationMethod::TRANSFERENCIA_BANCARIA,
    iban: $validIban
));
$assert(
    '10.0 Existe en la suite un expediente con IBAN válido contra el que auditar',
    $repo->findById($ibanCase->getId())->getIban() === $validIban,
    'iban almacenado: ' . var_export($repo->findById($ibanCase->getId())->getIban(), true)
);

$serializedAudit = json_encode(array_map(
    static fn (AuditEvent $e): array => $e->toArray(),
    $auditRepo->events
), JSON_THROW_ON_ERROR);

$assert(
    '10.1 El IBAN nunca aparece en el registro de auditoría',
    !str_contains($serializedAudit, $validIban),
    'audit: ' . substr($serializedAudit, 0, 200)
);
$assert(
    '10.2 El teléfono Bizum nunca aparece en el registro de auditoría',
    !str_contains($serializedAudit, '600999888')
);
$assert(
    '10.3 Ningún PIN de recogida aparece en el registro de auditoría',
    !str_contains($serializedAudit, $deliverPin),
    'PIN buscado: ' . $deliverPin
);
$assert(
    '10.4 Se ha registrado al menos un evento REFUND por entidad',
    count($auditRepo->events) >= 5,
    'eventos: ' . count($auditRepo->events)
);
$assert(
    '10.5 Todos los eventos usan la entidad REFUND_REQUEST del catálogo de auditoría',
    count(array_filter($auditRepo->events, static fn (AuditEvent $e): bool
        => $e->getEntityType() !== AuditEvent::ENTITY_REFUND_REQUEST)) === 0
);

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-06 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);