<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Unit suite for `PublicRefundController` (T-REF-08).
 *
 * The "Hecho cuando" criterion requires the public endpoint to expose a case
 * anonymously by its secure token, to allow rectifying the IBAN or the Bizum
 * phone while the case sits in `PENDING_CONTACT`, and to reject unknown tokens
 * with 404 and malformed ones with 422.
 *
 * Because this link is the only credential a consumer ever receives, the suite
 * also proves the negative space: that no route to the IBAN, the Bizum phone, the
 * claimant's name or the internal justification exists anywhere in the response,
 * whatever the status and whatever the caller sends.
 *
 * No database is touched: repositories are in-memory fakes living only here.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IbanValidationService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Presentation\Controller\PublicRefundController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

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

/** Rebuilds an immutable case with some columns replaced. */
function rebuildTrackingCase(RefundRequest $case, array $overrides = []): RefundRequest
{
    $pick = static function (string $key, mixed $default) use ($overrides): mixed {
        return array_key_exists($key, $overrides) ? $overrides[$key] : $default;
    };

    return new RefundRequest(
        id: $pick('id', $case->getId()),
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

final class TrackingRefundRepo implements RefundRequestRepositoryInterface
{
    /** @var array<int, RefundRequest> */
    public array $rows = [];

    private int $nextId = 1;

    public function insert(RefundRequest $refundRequest): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = rebuildTrackingCase($refundRequest, ['id' => $id]);

        return $id;
    }

    public function findById(int $id): ?RefundRequest
    {
        return $this->rows[$id] ?? null;
    }

    public function findByTrackingToken(string $trackingToken): ?RefundRequest
    {
        foreach ($this->rows as $row) {
            if (hash_equals($row->getTrackingToken(), $trackingToken)) {
                return $row;
            }
        }

        return null;
    }

    public function findRestrictedByIncident(int $incidentId): array
    {
        return array_values(array_filter($this->rows, static fn (RefundRequest $r): bool => $r->getIncidentId() === $incidentId));
    }

    public function findRestrictedByLocation(int $locationId, ?RefundStatus $status = null): array
    {
        return array_values(array_filter($this->rows, static function (RefundRequest $r) use ($locationId, $status): bool {
            return $r->getLocationId() === $locationId && ($status === null || $r->getStatus() === $status);
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
            $entityFields[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', (string)$column))))] = $value;
        }

        $this->rows[$id] = rebuildTrackingCase($current, $entityFields + ['status' => $newStatus]);

        return true;
    }

    public function updateContactDetails(int $id, ?string $bizumPhone, ?string $iban): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        $this->rows[$id] = rebuildTrackingCase($this->rows[$id], [
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

        $this->rows[$id] = rebuildTrackingCase($this->rows[$id], ['isActive' => false]);

        return true;
    }
}

final class TrackingMachineRepo implements MachineRepositoryInterface
{
    public function __construct(private ?Machine $machine = null)
    {
    }

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machine;
    }

    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machine;
    }

    public function findActiveByLocationId(int $locationId): array
    {
        return $this->machine !== null ? [$this->machine] : [];
    }

    public function create(array $data): Machine
    {
        throw new LogicException('No used in this suite.');
    }

    public function update(int $id, array $data): bool
    {
        throw new LogicException('No used in this suite.');
    }

    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool
    {
        throw new LogicException('No used in this suite.');
    }

    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool
    {
        throw new LogicException('No used in this suite.');
    }

    public function findAll(array $filters = []): array
    {
        return $this->machine !== null ? [$this->machine] : [];
    }

    public function hasActiveTicketOrWarranty(int $machineId): bool
    {
        return false;
    }

    public function getActiveTicketOrWarranty(int $machineId): ?array
    {
        return null;
    }

    public function softDelete(int $id): bool
    {
        throw new LogicException('No used in this suite.');
    }
}

final class TrackingLocationRepo implements LocationRepositoryInterface
{
    public function __construct(private ?Location $location = null)
    {
    }

    public function findById(int $id): ?Location
    {
        return $this->location;
    }

    public function findBySiteCode(string $siteCode): ?Location
    {
        return $this->location;
    }

    public function findAllActive(): array
    {
        return $this->location !== null ? [$this->location] : [];
    }

    public function findAll(string $status = 'all', ?string $search = null): array
    {
        return $this->location !== null ? [$this->location] : [];
    }

    public function create(array $data): Location
    {
        throw new LogicException('No used in this suite.');
    }

    public function update(int $id, array $data): bool
    {
        throw new LogicException('No used in this suite.');
    }

    public function softDelete(int $id): bool
    {
        throw new LogicException('No used in this suite.');
    }

    public function restore(int $id): bool
    {
        throw new LogicException('No used in this suite.');
    }

    public function updateContactPhone(int $id, string $contactPhone): bool
    {
        throw new LogicException('No used in this suite.');
    }

    public function countActiveMachines(int $locationId): int
    {
        return 0;
    }
}

final class TrackingAuditRepo implements AuditLogRepositoryInterface
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
        return array_values(array_filter($this->events, static fn (AuditEvent $e): bool => $e->getEntityType() === $entityType));
    }
}

$refundRepo = new TrackingRefundRepo();
$auditRepo = new TrackingAuditRepo();
$management = new RefundManagementService($refundRepo, new IbanValidationService(), new AuditLogger($auditRepo));

$machine = new Machine(
    id: 11,
    locationId: 1,
    code: 'VEND-0101',
    model: 'VendGuard 300',
    machineType: MachineType::COMBO,
    floorWing: 'Planta 0',
    isActive: true,
    createdAt: '2026-10-01 09:00:00'
);
$location = new Location(
    id: 1,
    siteCode: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Carrer del Mar, 1',
    isActive: true,
    createdAt: '2026-01-01 09:00:00',
    latitude: 41.38,
    longitude: 2.18
);

$controller = new PublicRefundController(
    $refundRepo,
    $management,
    new TrackingMachineRepo($machine),
    new TrackingLocationRepo($location)
);

/**
 * Opens a case and walks it along an explicit legal route to the state under
 * test. The routes are written out instead of being inferred, so a scenario can
 * never silently end up in a different state than the one being asserted.
 */
$routes = [
    'PENDING_INSPECTION' => [],
    'DEPOSITED_AT_RECEPTION' => ['DEPOSITED_AT_RECEPTION'],
    'VERIFIED_PENDING_PAYMENT' => ['VERIFIED_PENDING_PAYMENT'],
    'REQUIRES_COORDINATOR_APPROVAL' => ['REQUIRES_COORDINATOR_APPROVAL'],
    'PENDING_CONTACT' => ['VERIFIED_PENDING_PAYMENT', 'PENDING_CONTACT'],
    'PAID_DIGITAL' => ['VERIFIED_PENDING_PAYMENT', 'PAID_DIGITAL'],
    'REFUNDED_IN_HAND' => ['DEPOSITED_AT_RECEPTION', 'REFUNDED_IN_HAND'],
];

$makeCase = static function (RefundStatus $status, CompensationMethod $method = CompensationMethod::EN_MANO_SEDE) use ($management, $refundRepo, $routes): RefundRequest {
    $case = $management->createCase(new CreateRefundRequestDTO(
        incidentId: 101,
        machineId: 11,
        locationId: 1,
        claimantName: 'Laura Sanitaria',
        claimantContact: '600111222',
        claimedAmount: 2.00,
        compensationMethod: $method,
        productAttempted: 'Café con leche carril 2',
        bizumPhone: $method->requiresBizumPhone() ? '600111222' : null,
        iban: $method->requiresIban() ? 'ES9121000418450200051332' : null
    ));

    $current = RefundStatus::PENDING_INSPECTION;
    foreach ($routes[$status->value] as $step) {
        $refundRepo->transitionStatus((int)$case->getId(), $current, RefundStatus::from($step));
        $current = RefundStatus::from($step);
    }

    return $refundRepo->findById((int)$case->getId());
};

$getRequest = static fn (?string $token): Request => new Request(
    method: 'GET',
    path: '/api/public/refunds/track',
    queryParams: $token === null ? [] : ['token' => $token]
);
$patchRequest = static fn (?string $token, array $body): Request => new Request(
    method: 'PATCH',
    path: '/api/public/refunds/track',
    queryParams: $token === null ? [] : ['token' => $token],
    parsedBody: $body
);

/**
 * Stands in for a controller call that blew up instead of answering.
 *
 * A controller that returns the wrong status has to be reported as a failed
 * assertion, not as a fatal that aborts the suite halfway and hides the rest of
 * the evidence: an escaping `TypeError` would leave every later section
 * unverified while still looking like "no failures".
 */
final class FaultedResponse
{
    public function __construct(public readonly string $fault)
    {
    }

    public function getStatusCode(): int
    {
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDecodedBody(): array
    {
        return [];
    }
}

/**
 * Invokes the controller isolating any throwable it lets escape, so a regression
 * degrades into `[FAIL]` lines instead of killing the whole run.
 */
$invoke = static function (callable $controllerCall): Response|FaultedResponse {
    try {
        return $controllerCall();
    } catch (Throwable $e) {
        return new FaultedResponse(get_class($e) . ': ' . $e->getMessage());
    }
};

/** Explains a status assertion, naming the escaped throwable when there was one. */
$statusDetail = static fn (Response|FaultedResponse $response, string $prefix = 'estado: '): string => $response instanceof FaultedResponse
    ? 'fallo inesperado -> ' . $response->fault
    : $prefix . $response->getStatusCode();

// ─────────────────────────────────────────────────────────────────────────────
// 0. Dogma Vanilla y contrato de la URL
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 0. Dogma Vanilla y contrato del enlace público ---\n";

$source = (string)file_get_contents($baseDir . '/src/Presentation/Controller/PublicRefundController.php');

$assert('0.1 Existe `PublicRefundController`', class_exists(PublicRefundController::class));
$assert('0.2 El controlador declara tipado estricto', str_contains($source, 'declare(strict_types=1);'));
// Se admiten los símbolos propios del proyecto y las clases globales nativas de
// PHP; cualquier otra cosa sería una dependencia externa (Art. IV).
$phpBuiltins = ['Throwable', 'InvalidArgumentException', 'LogicException', 'DomainException', 'RuntimeException', 'PDO'];
preg_match_all('/^use\s+([^;]+);/m', $source, $useMatches);
$foreignImports = array_values(array_filter($useMatches[1], static function (string $import) use ($phpBuiltins): bool {
    return !str_starts_with($import, 'VendGuard\\') && !in_array($import, $phpBuiltins, true);
}));
$assert(
    '0.3 No importa ninguna dependencia externa (cero Composer)',
    $foreignImports === [],
    'imports externos: ' . implode(', ', $foreignImports)
);
$assert(
    '0.4 El controlador NO ejecuta ningún DELETE FROM (Art. III)',
    !str_contains(strtoupper($source), 'DELETE FROM')
);
$assert('0.5 El token público son 64 caracteres hexadecimales', PublicRefundController::TRACKING_TOKEN_LENGTH === 64);

// ─────────────────────────────────────────────────────────────────────────────
// 1. Consulta anónima del estado
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. GET: consulta anónima por token seguro ---\n";

$deposited = $makeCase(RefundStatus::DEPOSITED_AT_RECEPTION);
$response = $invoke(static fn (): Response => $controller->track($getRequest($deposited->getTrackingToken())));
$body = $response->getDecodedBody();

$assert('1.1 El seguimiento devuelve HTTP 200', $response->getStatusCode() === 200, $statusDetail($response));
$assert('1.2 La respuesta declara éxito', ($body['success'] ?? false) === true);
$assert('1.3 Expone el estado del expediente', ($body['data']['status'] ?? '') === 'DEPOSITED_AT_RECEPTION');
$assert('1.4 Expone la etiqueta en castellano del estado', ($body['data']['status_label'] ?? '') !== '');
$assert('1.5 Expone una descripción legible del estado', mb_strlen((string)($body['data']['status_description'] ?? '')) > 20);
$assert('1.6 Expone el importe reclamado', (float)($body['data']['claimed_amount'] ?? 0) === 2.00);
$assert('1.7 Expone el código de la máquina', ($body['data']['machine_code'] ?? '') === 'VEND-0101');
$assert('1.8 Expone el nombre de la sede', ($body['data']['location_name'] ?? '') === 'Hospital del Mar - Edificio Central');
$assert(
    '1.9 Con efectivo en conserjería muestra el PIN de recogida',
    preg_match('/^[0-9]{4}$/', (string)($body['data']['pickup_pin'] ?? '')) === 1
);
$assert('1.10 No permite rectificar datos si no está en PENDING_CONTACT', ($body['data']['can_rectify_data'] ?? true) === false);
$assert(
    '1.11 Control de no-vacuidad: el PIN existe en el dominio, no es un campo vacío',
    preg_match('/^[0-9]{4}$/', (string)$deposited->getPickupPin()) === 1,
    'pin persistido: ' . var_export($deposited->getPickupPin(), true)
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Blindaje de datos por el enlace público (Art. V.4)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. El enlace público NUNCA expone datos bancarios ni identidad ---\n";

$ibanCase = $makeCase(RefundStatus::VERIFIED_PENDING_PAYMENT, CompensationMethod::TRANSFERENCIA_BANCARIA);
$ibanBody = $invoke(static fn (): Response => $controller->track($getRequest($ibanCase->getTrackingToken())))->getDecodedBody();
$ibanJson = (string)json_encode($ibanBody, JSON_THROW_ON_ERROR);

$assert(
    '2.1 El IBAN nunca aparece en la respuesta pública',
    !str_contains($ibanJson, 'ES9121000418450200051332')
);
$assert('2.2 La clave `iban` no existe en el payload', !array_key_exists('iban', $ibanBody['data'] ?? []));
$assert('2.3 La clave `bizum_phone` no existe en el payload', !array_key_exists('bizum_phone', $ibanBody['data'] ?? []));

$bizumCase = $makeCase(RefundStatus::VERIFIED_PENDING_PAYMENT, CompensationMethod::BIZUM);
$bizumJson = (string)json_encode($invoke(static fn (): Response => $controller->track($getRequest($bizumCase->getTrackingToken())))->getDecodedBody(), JSON_THROW_ON_ERROR);
$assert('2.4 El teléfono de Bizum nunca aparece en la respuesta pública', !str_contains($bizumJson, '600111222'));
$assert('2.5 El nombre del reclamante nunca aparece en la respuesta pública', !str_contains($bizumJson, 'Laura Sanitaria'));
$assert('2.6 El código de error interno del expediente no se filtra', !str_contains($ibanJson, 'tracking_token'));

$pending = $makeCase(RefundStatus::PENDING_CONTACT, CompensationMethod::TRANSFERENCIA_BANCARIA);
$pendingJson = (string)json_encode($invoke(static fn (): Response => $controller->track($getRequest($pending->getTrackingToken())))->getDecodedBody(), JSON_THROW_ON_ERROR);
$assert('2.7 Ni en PENDING_CONTACT se filtra el IBAN', !str_contains($pendingJson, 'ES9121000418450200051332'));
$assert('2.8 En PENDING_CONTACT se ofrece la rectificación', ($pending->getStatus()->allowsContactRectification()) === true);

$awaiting = $invoke(static fn (): Response => $controller->track($getRequest($pending->getTrackingToken())))->getDecodedBody();
$assert('2.9 can_rectify_data es true en PENDING_CONTACT', ($awaiting['data']['can_rectify_data'] ?? false) === true);
$assert(
    '2.10 El PIN NO se muestra cuando el efectivo no está en conserjería',
    array_key_exists('pickup_pin', $awaiting['data'] ?? []) && $awaiting['data']['pickup_pin'] === null,
    'valor: ' . var_export($awaiting['data']['pickup_pin'] ?? 'AUSENTE', true)
);

// El PIN sólo se emite para efectivo en mano, así que "el PIN no sale" sería una
// aserción VACUA si el caso consultado nunca lo tuvo. Estos dos escenarios lo
// llevan persistido de verdad y aun así exigen que la URL pública no lo suelte:
// uno con el PIN recién emitido y el efectivo todavía no en conserjería, otro con
// el PIN ya gastado tras la entrega en mano (seguir filtrándolo daría al portador
// del enlace un secreto que ya no sirve, pero que sí sirve para social engineering).
$waiting = $makeCase(RefundStatus::PENDING_INSPECTION);
$assert(
    '2.11 Control de no-vacuidad: el PIN se emite aunque el efectivo aún no esté en conserjería',
    preg_match('/^[0-9]{4}$/', (string)$waiting->getPickupPin()) === 1,
    'pin persistido: ' . var_export($waiting->getPickupPin(), true)
);
$waitingBody = $invoke(static fn (): Response => $controller->track($getRequest($waiting->getTrackingToken())))->getDecodedBody();
$assert(
    '2.12 Con el PIN emitido pero el efectivo aún NO en conserjería, la URL no lo muestra',
    array_key_exists('pickup_pin', $waitingBody['data'] ?? []) && $waitingBody['data']['pickup_pin'] === null,
    'valor: ' . var_export($waitingBody['data']['pickup_pin'] ?? 'AUSENTE', true)
);

$delivered = $makeCase(RefundStatus::REFUNDED_IN_HAND);
$assert(
    '2.13 Control de no-vacuidad: tras entregar en mano el PIN sigue persistido en la fila',
    preg_match('/^[0-9]{4}$/', (string)$delivered->getPickupPin()) === 1,
    'pin persistido: ' . var_export($delivered->getPickupPin(), true)
);
$deliveredBody = $invoke(static fn (): Response => $controller->track($getRequest($delivered->getTrackingToken())))->getDecodedBody();
$assert(
    '2.14 Una vez entregado, el PIN jamás se expone por la URL pública',
    array_key_exists('pickup_pin', $deliveredBody['data'] ?? []) && $deliveredBody['data']['pickup_pin'] === null,
    'valor: ' . var_export($deliveredBody['data']['pickup_pin'] ?? 'AUSENTE', true)
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Tokens inválidos e inexistentes
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Token ausente, mal formado o inexistente ---\n";

$missing = $invoke(static fn (): Response => $controller->track($getRequest(null)));
$assert('3.1 Sin parámetro `token` se responde 422', $missing->getStatusCode() === 422, $statusDetail($missing));

$short = $invoke(static fn (): Response => $controller->track($getRequest('abc123')));
$assert('3.2 Un token demasiado corto se responde 422', $short->getStatusCode() === 422, $statusDetail($short));

$nonHex = $invoke(static fn (): Response => $controller->track($getRequest(str_repeat('z', 64))));
$assert('3.3 Un token no hexadecimal se responde 422', $nonHex->getStatusCode() === 422, $statusDetail($nonHex));

$upperCase = $invoke(static fn (): Response => $controller->track($getRequest(strtoupper($deposited->getTrackingToken()))));
$assert(
    '3.4 Un token en mayúsculas no resuelve el expediente',
    $upperCase->getStatusCode() === 422,
    $statusDetail($upperCase)
);

$unknown = $invoke(static fn (): Response => $controller->track($getRequest(str_repeat('a', 64))));
$unknownBody = $unknown->getDecodedBody();
$assert('3.5 Un token inexistente pero bien formado se responde 404', $unknown->getStatusCode() === 404, $statusDetail($unknown));
$assert(
    '3.6 El 404 expone el código REFUND_NOT_FOUND',
    ($unknownBody['error']['code'] ?? '') === 'REFUND_NOT_FOUND',
    'error: ' . ($unknown instanceof FaultedResponse ? $unknown->fault : ($unknownBody['error']['code'] ?? 'AUSENTE'))
);

$archived = $makeCase(RefundStatus::PENDING_INSPECTION);
$refundRepo->deactivate((int)$archived->getId());
$archivedLookup = $invoke(static fn (): Response => $controller->track($getRequest($archived->getTrackingToken())));
$assert(
    '3.7 Un expediente archivado lógicamente también se responde 404 (Art. III)',
    $archivedLookup->getStatusCode() === 404,
    $statusDetail($archivedLookup)
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Rectificación de datos en PENDING_CONTACT (RF-REF-07)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. PATCH: rectificación de IBAN y teléfono en PENDING_CONTACT ---\n";

$toFix = $makeCase(RefundStatus::PENDING_CONTACT, CompensationMethod::TRANSFERENCIA_BANCARIA);
$token = $toFix->getTrackingToken();

$fixed = $invoke(static fn (): Response => $controller->rectify($patchRequest($token, ['iban' => 'ES7921000813610123456789', 'bizum_phone' => null])));
$fixedBody = $fixed->getDecodedBody();

$assert('4.1 La rectificación devuelve HTTP 200', $fixed->getStatusCode() === 200, $statusDetail($fixed));
$assert('4.2 El expediente vuelve a VERIFIED_PENDING_PAYMENT', ($fixedBody['data']['status'] ?? '') === 'VERIFIED_PENDING_PAYMENT');
$assert(
    '4.3 El IBAN corregido se persiste',
    $refundRepo->findById((int)$toFix->getId())?->getIban() === 'ES7921000813610123456789'
);
$assert(
    '4.4 La respuesta NO devuelve el IBAN corregido',
    !str_contains((string)json_encode($fixedBody, JSON_THROW_ON_ERROR), 'ES7921000813610123456789')
);
$assert('4.5 La respuesta informa en castellano', str_contains((string)($fixedBody['message'] ?? ''), 'Datos de pago actualizados'));

$phoneCase = $makeCase(RefundStatus::PENDING_CONTACT, CompensationMethod::BIZUM);
$fixedPhone = $invoke(static fn (): Response => $controller->rectify($patchRequest($phoneCase->getTrackingToken(), ['bizum_phone' => '600 999 888'])));
$assert('4.6 Se puede corregir el teléfono de Bizum', $fixedPhone->getStatusCode() === 200, $statusDetail($fixedPhone));
$assert(
    '4.7 El teléfono corregido se normaliza a 9 dígitos',
    $refundRepo->findById((int)$phoneCase->getId())?->getBizumPhone() === '600999888'
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Rechazos de la rectificación
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. La rectificación rechaza lo que no corresponde ---\n";

$wrongState = $makeCase(RefundStatus::VERIFIED_PENDING_PAYMENT, CompensationMethod::BIZUM);
$rejectedState = $invoke(static fn (): Response => $controller->rectify($patchRequest($wrongState->getTrackingToken(), ['bizum_phone' => '600999888'])));
$assert(
    '5.1 Rectificar fuera de PENDING_CONTACT se responde 409',
    $rejectedState->getStatusCode() === 409,
    $statusDetail($rejectedState)
);

$badIbanCase = $makeCase(RefundStatus::PENDING_CONTACT, CompensationMethod::TRANSFERENCIA_BANCARIA);
$badIban = $invoke(static fn (): Response => $controller->rectify($patchRequest($badIbanCase->getTrackingToken(), ['iban' => 'ES9121000418450200051399'])));
$badIbanBody = $badIban->getDecodedBody();
$assert('5.2 Un IBAN con checksum falso se responde 422', $badIban->getStatusCode() === 422, $statusDetail($badIban));
$assert(
    '5.3 El 422 expone el código INVALID_IBAN_FORMAT',
    ($badIbanBody['error']['code'] ?? '') === 'INVALID_IBAN_FORMAT'
);
$assert(
    '5.4 Un IBAN inválido NO se persiste',
    $refundRepo->findById((int)$badIbanCase->getId())?->getIban() === 'ES9121000418450200051332'
);

$badPhoneCase = $makeCase(RefundStatus::PENDING_CONTACT, CompensationMethod::BIZUM);
$badPhone = $invoke(static fn (): Response => $controller->rectify($patchRequest($badPhoneCase->getTrackingToken(), ['bizum_phone' => '60099988'])));
$badPhoneBody = $badPhone->getDecodedBody();
$assert('5.5 Un teléfono de 8 dígitos se responde 422', $badPhone->getStatusCode() === 422, $statusDetail($badPhone));
$assert(
    '5.6 El 422 expone el código INVALID_BIZUM_PHONE',
    ($badPhoneBody['error']['code'] ?? '') === 'INVALID_BIZUM_PHONE'
);

$emptyBody = $makeCase(RefundStatus::PENDING_CONTACT, CompensationMethod::BIZUM);
$empty = $invoke(static fn (): Response => $controller->rectify($patchRequest($emptyBody->getTrackingToken(), [])));
$assert('5.7 Un cuerpo vacío se responde 422', $empty->getStatusCode() === 422, $statusDetail($empty));

$patchMissingToken = $invoke(static fn (): Response => $controller->rectify($patchRequest(null, ['bizum_phone' => '600999888'])));
$assert('5.8 Sin token la rectificación se responde 422', $patchMissingToken->getStatusCode() === 422, $statusDetail($patchMissingToken));

$patchUnknown = $invoke(static fn (): Response => $controller->rectify($patchRequest(str_repeat('b', 64), ['bizum_phone' => '600999888'])));
$assert('5.9 Con token inexistente la rectificación se responde 404', $patchUnknown->getStatusCode() === 404, $statusDetail($patchUnknown));

$unchanged = $makeCase(RefundStatus::PENDING_CONTACT, CompensationMethod::BIZUM);
$invoke(static fn (): Response => $controller->rectify($patchRequest($unchanged->getTrackingToken(), ['bizum_phone' => '60099988'])));
$assert(
    '5.10 Tras un rechazo por formato el expediente sigue en PENDING_CONTACT',
    $refundRepo->findById((int)$unchanged->getId())?->getStatus() === RefundStatus::PENDING_CONTACT
);

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-08 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);