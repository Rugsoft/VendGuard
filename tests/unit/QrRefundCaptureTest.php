<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Unit suite for the optional refund capture of `POST /api/qr/report` (T-REF-09).
 *
 * The "Hecho cuando" criterion asks for three things at once: the refund block is
 * processed when `refund_requested` is set, amounts / method / contact data are
 * validated, and the response carries the receipt with the four-digit PIN and the
 * tracking URL.
 *
 * Two properties matter as much as the happy path and are asserted first:
 *
 * 1. The block is genuinely OPTIONAL. A report without the flag, or with it
 *    explicitly false, must behave exactly as it did before this task, so the
 *    change cannot regress the endpoint millions of people already use to report
 *    a broken machine.
 * 2. Validation happens BEFORE any write. A rejected claim must leave the
 *    database untouched: an incident registered with the refund silently dropped
 *    is the single worst outcome for a consumer who is standing in front of a
 *    machine that ate their money.
 *
 * No database is touched: every repository here is an in-memory fake and the
 * real `QrReportService` and `RefundManagementService` run on top of them.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Application\DTO\RefundReceiptDTO;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IbanValidationService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\IncidentHistory;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Core\Service\QrReportService;
use VendGuard\Presentation\Controller\QrScanController;
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

/** Stands in for a controller call that blew up instead of answering. */
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

// ─────────────────────────────────────────────────────────────────────────────
// In-memory fakes
// ─────────────────────────────────────────────────────────────────────────────

final class CaptureMachineRepo implements MachineRepositoryInterface
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
        throw new LogicException('Not used in this suite.');
    }

    public function update(int $id, array $data): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool
    {
        throw new LogicException('Not used in this suite.');
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
        throw new LogicException('Not used in this suite.');
    }
}

final class CaptureLocationRepo implements LocationRepositoryInterface
{
    public function __construct(private ?Location $location = null)
    {
    }

    public function findById(int $id, bool $allowDeleted = false): ?Location
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
        throw new LogicException('Not used in this suite.');
    }

    public function update(int $id, array $data): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function softDelete(int $id): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function restore(int $id): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function updateContactPhone(int $id, string $contactPhone): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function countActiveMachines(int $locationId): int
    {
        return 1;
    }
}

final class CaptureSettingsRepo implements PreventiveSettingsRepositoryInterface
{
    public function findAll(): array
    {
        return [];
    }

    public function findByMachineType(string $machineType): ?PreventiveSetting
    {
        return null;
    }

    public function updateTypeSettings(
        string $machineType,
        int $defaultFrequencyDays,
        int $maxAllowedDays,
        int $advanceWarningDays = 5
    ): bool {
        throw new LogicException('Not used in this suite.');
    }

    public function updateMachineConfig(
        int $machineId,
        ?int $sanitaryFrequencyDays,
        ?string $nextSanitaryInspectionDue = null
    ): bool {
        throw new LogicException('Not used in this suite.');
    }

    public function setSeasonalPause(
        int $machineId,
        string $reason,
        ?string $pauseUntil = null
    ): bool {
        throw new LogicException('Not used in this suite.');
    }

    public function resumeSeasonalPause(int $machineId): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function getMachineSettings(int $machineId): ?array
    {
        return null;
    }

    public function updateSanitaryStatus(int $machineId, string $status): bool
    {
        throw new LogicException('Not used in this suite.');
    }
}

final class CaptureIncidentRepo implements IncidentRepositoryInterface
{
    /** @var array<int, Incident> */
    public array $rows = [];

    /** @var list<IncidentComment> */
    public array $comments = [];

    private int $nextId = 1;

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident
    {
        $id = $this->nextId++;
        $created = $incident->withId($id);
        $this->rows[$id] = $created;

        return $created;
    }

    public function findById(int $id): ?Incident
    {
        return $this->rows[$id] ?? null;
    }

    public function findByTicketCode(string $ticketCode): ?Incident
    {
        foreach ($this->rows as $row) {
            if ($row->getTicketCode() === $ticketCode) {
                return $row;
            }
        }

        return null;
    }

    public function findActiveByMachineId(int $machineId): ?Incident
    {
        foreach ($this->rows as $row) {
            if ($row->getMachineId() === $machineId && $row->getStatus()->isActive()) {
                return $row;
            }
        }

        return null;
    }

    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident
    {
        return $this->findActiveByMachineId($machineId);
    }

    public function findAllByLocation(int $locationId, bool $activeOnly = false): array
    {
        return array_values(array_filter($this->rows, static fn (Incident $i): bool => $i->getLocationId() === $locationId));
    }

    public function findAll(array $filters = []): array
    {
        return array_values($this->rows);
    }

    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array
    {
        return [];
    }

    public function update(Incident $incident): bool
    {
        return false;
    }

    public function softDelete(int $id): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function insertHistory(
        int $incidentId,
        ?int $userId,
        ?string $fromStatus,
        string $toStatus,
        ?string $actionNote = null
    ): int {
        return 1;
    }

    public function getHistory(int $incidentId): array
    {
        return [];
    }

    public function addComment(IncidentComment $comment): IncidentComment
    {
        $this->comments[] = $comment;

        return $comment;
    }

    public function getComments(int $incidentId, bool $includeInternal = true): array
    {
        return $this->comments;
    }

    public function countReopenEvents(int $incidentId): int
    {
        return 0;
    }

    public function markAsChronic(int $incidentId): bool
    {
        throw new LogicException('Not used in this suite.');
    }

    public function assign(
        int $incidentId,
        int $technicianId,
        ?int $coordinatorId = null,
        ?string $urgencyOverride = null,
        ?string $urgencyReason = null
    ): Incident {
        throw new LogicException('Not used in this suite.');
    }

    public function reopen(int $incidentId, string $reasonText): Incident
    {
        throw new LogicException('Not used in this suite.');
    }

    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident
    {
        throw new LogicException('Not used in this suite.');
    }

    public function startIntervention(int $incidentId, int $technicianId): Incident
    {
        throw new LogicException('Not used in this suite.');
    }

    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident
    {
        throw new LogicException('Not used in this suite.');
    }

    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident
    {
        throw new LogicException('Not used in this suite.');
    }

    public function autoCloseResolvedIncidents(int $hours = 48): array
    {
        return [];
    }
}

final class CaptureAuditRepo implements AuditLogRepositoryInterface
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

/** Rebuilds an immutable case with some columns replaced. */
function rebuildCapturedCase(RefundRequest $case, array $overrides = []): RefundRequest
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

final class CaptureRefundRepo implements RefundRequestRepositoryInterface
{
    /** @var array<int, RefundRequest> */
    public array $rows = [];

    private int $nextId = 1;

    public function insert(RefundRequest $refundRequest): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = rebuildCapturedCase($refundRequest, ['id' => $id]);

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

        $this->rows[$id] = rebuildCapturedCase($current, ['status' => $newStatus]);

        return true;
    }

    public function updateContactDetails(int $id, ?string $bizumPhone, ?string $iban): bool
    {
        return false;
    }

    public function deactivate(int $id): bool
    {
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Harness
// ─────────────────────────────────────────────────────────────────────────────

$refundRepo = new CaptureRefundRepo();
$auditRepo = new CaptureAuditRepo();
$incidentRepo = new CaptureIncidentRepo();

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
$machineRepo = new CaptureMachineRepo($machine);
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
$locationRepo = new CaptureLocationRepo($location);

$controller = new QrScanController(
    qrScanService: null,
    qrReportService: new QrReportService($machineRepo, $locationRepo, $incidentRepo),
    fileUploader: null,
    machineRepo: $machineRepo,
    settingsRepo: new CaptureSettingsRepo(),
    refundService: new RefundManagementService($refundRepo, new IbanValidationService(), new AuditLogger($auditRepo)),
    ibanValidator: new IbanValidationService()
);

/**
 * Builds a report body. `refund` fields are merged only when the key is present,
 * so each scenario states exactly what the consumer sent.
 */
$report = static function (array $refund = [], array $overrides = []) use ($machine): Request {
    return new Request(
        method: 'POST',
        path: '/api/qr/report',
        parsedBody: $overrides + [
            'machine_code' => $machine->getCode(),
            'category' => 'PAYMENT_SYSTEM',
            'description' => 'Metí una moneda de 2 euros y se quedó atascada sin dar producto.',
            'reporter_name' => 'Laura Sanitaria',
            'reporter_phone' => '600111222',
        ] + $refund,
        headers: ['content-type' => 'application/json']
    );
};

$invoke = static function (Request $request): Response|FaultedResponse {
    $controller = $GLOBALS['controller'];

    try {
        return $controller->report($request);
    } catch (Throwable $e) {
        return new FaultedResponse(get_class($e) . ': ' . $e->getMessage());
    }
};

$statusDetail = static fn (Response|FaultedResponse $response, string $prefix = 'estado: '): string => $response instanceof FaultedResponse
    ? 'fallo inesperado -> ' . $response->fault
    : $prefix . $response->getStatusCode();

/** Runs a rejected scenario and proves it left no trace anywhere. */
$expectNoWrites = function (string $label, Request $request, string $expectedCode, int $expectedStatus) use (
    &$assert,
    &$incidentRepo,
    &$refundRepo,
    $invoke,
    $statusDetail
): array {
    $incidentsBefore = count($incidentRepo->rows);
    $commentsBefore = count($incidentRepo->comments);
    $refundsBefore = count($refundRepo->rows);

    $response = $invoke($request);
    $body = $response->getDecodedBody();

    $assert(
        $label . ' se responde ' . $expectedStatus,
        $response->getStatusCode() === $expectedStatus,
        $statusDetail($response)
    );
    $assert(
        $label . ' expone el código ' . $expectedCode,
        ($body['error']['code'] ?? '') === $expectedCode,
        'error: ' . ($response instanceof FaultedResponse ? $response->fault : ($body['error']['code'] ?? 'AUSENTE'))
    );
    $assert(
        $label . ' NO registra ninguna avería huérfana',
        count($incidentRepo->rows) === $incidentsBefore,
        'incidencias: ' . count($incidentRepo->rows)
    );
    $assert(
        $label . ' NO anexa nada a la bitácora de ninguna avería',
        count($incidentRepo->comments) === $commentsBefore,
        'comentarios: ' . count($incidentRepo->comments)
    );
    $assert(
        $label . ' NO crea ningún expediente de reintegro',
        count($refundRepo->rows) === $refundsBefore,
        'expedientes: ' . count($refundRepo->rows)
    );

    return $body;
};

// ─────────────────────────────────────────────────────────────────────────────
// 0. Dogma Vanilla y contrato del resguardo
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 0. Dogma Vanilla y contrato del resguardo ---\n";

$controllerSource = (string)file_get_contents($baseDir . '/src/Presentation/Controller/QrScanController.php');
$receiptSource = (string)file_get_contents($baseDir . '/src/Application/DTO/RefundReceiptDTO.php');

$assert('0.1 El DTO del resguardo declara tipado estricto', str_contains($receiptSource, 'declare(strict_types=1);'));
$assert(
    '0.2 El DTO del resguardo NO ejecuta ningún DELETE FROM (Art. III)',
    !str_contains(strtoupper($receiptSource), 'DELETE FROM')
);
$assert(
    '0.3 El controlador NO ejecuta ningún DELETE FROM (Art. III)',
    !str_contains(strtoupper($controllerSource), 'DELETE FROM')
);
$assert('0.4 El prefijo del enlace público es el del contrato §4.1.1', RefundReceiptDTO::TRACKING_URL_PREFIX === '/?track=');
$assert('0.5 El mensaje de Telephone advice está declarado', str_contains(QrScanController::MESSAGE_OVER_TELEPHONE_ADVICE, 'atención al cliente'));

// ─────────────────────────────────────────────────────────────────────────────
// 1. El bloque es opcional: cero regresión sobre el reporte ya en producción
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. Sin `refund_requested` el reporte se comporta igual que antes ---\n";

$plain = $invoke($report());
$plainBody = $plain->getDecodedBody();

$assert('1.1 El reporte sin bloque de reintegro sigue devolviendo 201', $plain->getStatusCode() === 201, $statusDetail($plain));
$assert('1.2 Se conserva `ticket_code` del contrato histórico', ($plainBody['data']['ticket_code'] ?? '') !== '');
$assert('1.3 Se expone ahora `incident_id` (contrato §4.1.1)', (int)($plainBody['data']['incident_id'] ?? 0) > 0);
$assert('1.4 Se expone `incident_code` (contrato §4.1.1)', ($plainBody['data']['incident_code'] ?? '') === ($plainBody['data']['ticket_code'] ?? 'X'));
$assert('1.5 No aparece la clave `refund`', !array_key_exists('refund', $plainBody['data'] ?? []));
$assert('1.6 No se crea ningún expediente de reintegro', count($refundRepo->rows) === 0, 'expedientes: ' . count($refundRepo->rows));

// Los tres escenarios de "no" mandan un bloque de reintegro COMPLETAMENTE VÁLIDO
// a propósito: si mandaran campos vacíos, la falta de expediente en la respuesta
// probaría sólo que la validación falló, no que la bandera se respetó. Con datos
// válidos, cualquier expediente creado sería atribuible sin ambigüedad al flag.
$validBlock = [
    'claimed_amount' => 2.00,
    'compensation_method' => 'EN_MANO_SEDE',
    'product_attempted' => 'Café con leche carril 2',
];

$refundsBeforeNegatives = count($refundRepo->rows);

// Máquina limpia antes de cada escenario de "no", de modo que un `201` sea
// inequívoco y no dependa de si el reporte anterior dejó un ticket abierto.
$incidentRepo->rows = [];
$incidentRepo->comments = [];

$explicitFalse = $invoke($report(['refund_requested' => false] + $validBlock));
$explicitFalseBody = $explicitFalse->getDecodedBody();
$assert('1.7 `refund_requested: false` NO abre expediente', !array_key_exists('refund', $explicitFalseBody['data'] ?? []));
$assert('1.8 `refund_requested: false` ni siquiera molesta al resto de validaciones', $explicitFalse->getStatusCode() === 201, $statusDetail($explicitFalse));

$incidentRepo->rows = [];
$incidentRepo->comments = [];

$stringFalse = $invoke($report(['refund_requested' => 'false'] + $validBlock));
$stringFalseBody = $stringFalse->getDecodedBody();
$assert('1.9 El texto "false" NO cuenta como afirmación', !array_key_exists('refund', $stringFalseBody['data'] ?? []));
$assert('1.10 El texto "false" no invalida un bloque por lo demás correcto', $stringFalse->getStatusCode() === 201, $statusDetail($stringFalse));
$assert(
    '1.11 Ninguno de los tres "no" creó expediente de reintegro',
    count($refundRepo->rows) === $refundsBeforeNegatives,
    'expedientes: ' . count($refundRepo->rows)
);

$stringTrue = $invoke($report([
    'refund_requested' => 'true',
    'claimed_amount' => 2.00,
    'compensation_method' => 'EN_MANO_SEDE',
]));
$stringTrueBody = $stringTrue->getDecodedBody();
$assert('1.12 El texto "true" SÍ abre expediente (formulario multipart)', array_key_exists('refund', $stringTrueBody['data'] ?? []), 'data: ' . json_encode($stringTrueBody));

// ─────────────────────────────────────────────────────────────────────────────
// 2. Resguardo presencial con PIN de 4 dígitos y enlace de seguimiento
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Recogida en sede: el resguardo trae PIN y enlace ---\n";

// Cada bloque parte de una máquina limpia: si no, el segundo reporte se fusiona
// con el anterior y el bloque estaría probando otra cosa de la que dice probar.
$incidentRepo->rows = [];
$incidentRepo->comments = [];

// RF-REF-11: cada consumidor abre como mucho un expediente vivo por avería. Este
// caso en mano y el de Bizum comparten máquina, así que son dos personas
// distintas; Laura queda para la vía digital, que es la que comprueba que el
// teléfono y el nombre no se filtran en el resguardo.
$inHand = $invoke($report([
    'refund_requested' => true,
    'claimed_amount' => 2.00,
    'compensation_method' => 'EN_MANO_SEDE',
    'product_attempted' => 'Café con leche carril 2',
    'contact_name' => 'Nuria Vela',
    'contact_phone' => '600555443',
], ['reporter_name' => 'Nuria Vela', 'reporter_phone' => '600555443']));
$inHandBody = $inHand->getDecodedBody();
$inHandReceipt = $inHandBody['data']['refund'] ?? [];

$assert('2.1 El reporte con reintegro devuelve 201 Created', $inHand->getStatusCode() === 201, $statusDetail($inHand));
$assert('2.2 El resguardo trae identificador de expediente', (int)($inHandReceipt['id'] ?? 0) > 0);
$assert('2.3 El resguardo refleja el importe reclamado', (float)($inHandReceipt['claimed_amount'] ?? 0) === 2.00);
$assert('2.4 El resguardo refleja la vía elegida', ($inHandReceipt['compensation_method'] ?? '') === 'EN_MANO_SEDE');
$assert('2.5 El expediente nace en PENDING_INSPECTION', ($inHandReceipt['status'] ?? '') === 'PENDING_INSPECTION');
$assert(
    '2.6 El resguardo trae el PIN de recogida de 4 dígitos',
    preg_match('/^[0-9]{4}$/', (string)($inHandReceipt['pickup_pin'] ?? '')) === 1,
    'pin: ' . var_export($inHandReceipt['pickup_pin'] ?? 'AUSENTE', true)
);
$assert(
    '2.7 El token de seguimiento son 64 hexadecimales',
    preg_match('/^[0-9a-f]{64}$/', (string)($inHandReceipt['tracking_token'] ?? '')) === 1
);
$assert(
    '2.8 La URL de seguimiento apunta al token del expediente',
    ($inHandReceipt['tracking_url'] ?? '') === '/?track=' . ($inHandReceipt['tracking_token'] ?? ''),
    'url: ' . ($inHandReceipt['tracking_url'] ?? 'AUSENTE')
);

// Control de no-vacuidad: el PIN y el token del resguardo deben existir de verdad
// en la fila, o las aserciones anteriores pasarían por un campo siempre vacío.
$persistedInHand = $refundRepo->findById((int)($inHandReceipt['id'] ?? 0));
$assert('2.9 Control de no-vacuidad: el PIN existe en la fila persistida', preg_match('/^[0-9]{4}$/', (string)$persistedInHand?->getPickupPin()) === 1);
$assert('2.10 Control de no-vacuidad: el token existe en la fila persistida', hash_equals((string)$persistedInHand?->getTrackingToken(), (string)($inHandReceipt['tracking_token'] ?? '')));
$assert('2.11 El expediente queda anclado a la avería registrada', $persistedInHand?->getIncidentId() === (int)($inHandBody['data']['incident_id'] ?? 0));
$assert('2.12 El producto intentado queda registrado', $persistedInHand?->getProductAttempted() === 'Café con leche carril 2');
$assert('2.13 La vía presencial NO exige ni guarda IBAN ni Bizum', $persistedInHand?->getIban() === null && $persistedInHand?->getBizumPhone() === null);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Vías digitales: sin PIN, y sin devolver los datos que el usuario tecleó
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Bizum y transferencia: el resguardo nunca filtra lo tecleado ---\n";

$bizum = $invoke($report([
    'refund_requested' => true,
    'claimed_amount' => 2.00,
    'compensation_method' => 'BIZUM',
    'bizum_phone' => '600111222',
]));
$bizumBody = $bizum->getDecodedBody();
$bizumReceipt = $bizumBody['data']['refund'] ?? [];
$bizumJson = (string)json_encode($bizumBody, JSON_THROW_ON_ERROR);

$assert('3.1 El resguardo digital también trae token', preg_match('/^[0-9a-f]{64}$/', (string)($bizumReceipt['tracking_token'] ?? '')) === 1);
$assert('3.2 La clave `pickup_pin` existe y vale null en vía digital', array_key_exists('pickup_pin', $bizumReceipt) && $bizumReceipt['pickup_pin'] === null, 'valor: ' . var_export($bizumReceipt['pickup_pin'] ?? 'AUSENTE', true));
$assert('3.3 El teléfono de Bizum NO vuelve en la respuesta (Art. V.4)', !str_contains($bizumJson, '600111222'));
$assert('3.4 La clave `bizum_phone` no existe en el resguardo', !array_key_exists('bizum_phone', $bizumReceipt));
$assert('3.5 El nombre del reclamante NO vuelve en la respuesta', !str_contains($bizumJson, 'Laura Sanitaria'));
$assert('3.6 El teléfono SÍ queda persistido para el coordinating team', $refundRepo->findById((int)($bizumReceipt['id'] ?? 0))?->getBizumPhone() === '600111222');

// RF-REF-11: el mismo consumidor no acumula dos expedientes vivos, así que la
// vía de transferencia la abre OTRA persona sobre la misma máquina. El IBAN es
// lo que se está comprobando aquí, así que el cambio de reclamante no afecta a
// las aserciones 3.7 a 3.10.
$transfer = $invoke($report([
    'refund_requested' => true,
    'claimed_amount' => 15.00,
    'compensation_method' => 'TRANSFERENCIA_BANCARIA',
    'iban' => 'ES9121000418450200051332',
], ['reporter_name' => 'Marc Ruibal', 'reporter_phone' => '699888777']));
$transferBody = $transfer->getDecodedBody();
$transferReceipt = $transferBody['data']['refund'] ?? [];
$transferJson = (string)json_encode($transferBody, JSON_THROW_ON_ERROR);

$assert('3.7 El IBAN NO vuelve en la respuesta (Art. V.4)', !str_contains($transferJson, 'ES9121000418450200051332'));
$assert('3.8 La clave `iban` no existe en el resguardo', !array_key_exists('iban', $transferReceipt));
$assert('3.9 El IBAN SÍ queda persistido para el coordinating team', $refundRepo->findById((int)($transferReceipt['id'] ?? 0))?->getIban() === 'ES9121000418450200051332');
$assert('3.10 El importe de 15,00 € se acepta (lo bloqueante son los 50,00 €)', (float)($transferReceipt['claimed_amount'] ?? 0) === 15.00);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Multireclamación sobre la misma avería (RF-REF-08)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Dos consumidores sobre el mismo ticket ---\n";

$incidentRepo->rows = [];
$incidentRepo->comments = [];

$firstConsumer = $invoke($report([
    'refund_requested' => true,
    'claimed_amount' => 1.00,
    'compensation_method' => 'EN_MANO_SEDE',
], ['reporter_name' => 'Marc Ruibal', 'reporter_phone' => '699888777']));
$firstConsumerBody = $firstConsumer->getDecodedBody();
$firstReceipt = $firstConsumerBody['data']['refund'] ?? [];

$refundsBeforeSecond = count($refundRepo->rows);
$second = $invoke($report([
    'refund_requested' => true,
    'claimed_amount' => 3.00,
    'compensation_method' => 'EN_MANO_SEDE',
], ['reporter_name' => 'Rosa Palmera', 'reporter_phone' => '611223344']));
$secondBody = $second->getDecodedBody();
$secondReceipt = $secondBody['data']['refund'] ?? [];

$assert('4.1 El primer consumidor abre ticket y expediente', $firstConsumer->getStatusCode() === 201, $statusDetail($firstConsumer));
$assert('4.2 El segundo reporte sobre ticket abierto devuelve 200 (merge)', $second->getStatusCode() === 200, $statusDetail($second));
$assert('4.3 Se fusiona en el mismo ticket', ($secondBody['data']['merged'] ?? false) === true);
$assert('4.4 Aun así se abre un expediente de reintegro propio', count($refundRepo->rows) === $refundsBeforeSecond + 1);
$assert('4.5 El segundo expediente se ancla al ticket compartido', $refundRepo->findById((int)($secondReceipt['id'] ?? 0))?->getIncidentId() === (int)($secondBody['data']['incident_id'] ?? 0));
$assert(
    '4.6 Los dos consumidores NO comparten token de seguimiento',
    ($firstToken = (string)($firstReceipt['tracking_token'] ?? '')) !== '' && ($secondReceipt['tracking_token'] ?? '') !== $firstToken
);
$assert(
    '4.7 Los dos consumidores NO comparten PIN de recogida',
    (string)($firstReceipt['pickup_pin'] ?? '') !== (string)($secondReceipt['pickup_pin'] ?? '')
);
$assert('4.8 El segundo consumidor conserva su propia identidad', $refundRepo->findById((int)($secondReceipt['id'] ?? 0))?->getClaimantName() === 'Rosa Palmera');
$assert('4.9 El primer expediente NO se sobrescribe con el importe del segundo', (float)($firstReceipt['claimed_amount'] ?? 0) === 1.00 && $refundRepo->findById((int)($firstReceipt['id'] ?? 0))?->getClaimantName() === 'Marc Ruibal');
$assert('4.10 La observación se anexa a la bitácora del ticket', count($incidentRepo->comments) === 1);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Validación previa a cualquier escritura (fail-fast)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. Una reclamación mal formada NO debe dejar rastro ---\n";

// Máquina limpia y, sobre todo, SIN ninguna avería activa: si el reporte se
// fusionara con una anterior, "no se registró ninguna avería nueva" sería cierto
// por casualidad y no por merit defendida por el rechazo.
$incidentRepo->rows = [];
$incidentRepo->comments = [];

$overCap = $expectNoWrites(
    '5.1 Importe superior al tope de 50,00 €',
    $report([
        'refund_requested' => true,
        'claimed_amount' => 80.00,
        'compensation_method' => 'EN_MANO_SEDE',
    ]),
    'INVALID_REFUND_AMOUNT',
    422
);
$assert(
    '5.1b RF-REF-03 (EARS Excepción) remite a atención al cliente',
    str_contains((string)($overCap['error']['message'] ?? ''), 'atención al cliente'),
    'mensaje: ' . ($overCap['error']['message'] ?? 'AUSENTE')
);

$expectNoWrites(
    '5.2 Importe cero',
    $report([
        'refund_requested' => true,
        'claimed_amount' => 0,
        'compensation_method' => 'EN_MANO_SEDE',
    ]),
    'INVALID_REFUND_AMOUNT',
    422
);

$expectNoWrites(
    '5.3 Importe no numérico',
    $report([
        'refund_requested' => true,
        'claimed_amount' => 'dos euros',
        'compensation_method' => 'EN_MANO_SEDE',
    ]),
    QrScanController::ERROR_MISSING_REFUND_AMOUNT,
    422
);

$expectNoWrites(
    '5.4 Importe ausente',
    $report([
        'refund_requested' => true,
        'compensation_method' => 'EN_MANO_SEDE',
    ]),
    QrScanController::ERROR_MISSING_REFUND_AMOUNT,
    422
);

$expectNoWrites(
    '5.5 Vía de compensación inventada',
    $report([
        'refund_requested' => true,
        'claimed_amount' => 2.00,
        'compensation_method' => 'PAYPAL',
    ]),
    QrScanController::ERROR_INVALID_COMPENSATION_METHOD,
    422
);

$expectNoWrites(
    '5.6 Sin datos de contacto del reclamante',
    $report([
        'refund_requested' => true,
        'claimed_amount' => 2.00,
        'compensation_method' => 'EN_MANO_SEDE',
    ], ['reporter_name' => '', 'reporter_phone' => '']),
    QrScanController::ERROR_MISSING_REFUND_DATA,
    422
);

$expectNoWrites(
    '5.7 Bizum sin teléfono',
    $report([
        'refund_requested' => true,
        'claimed_amount' => 2.00,
        'compensation_method' => 'BIZUM',
    ]),
    QrScanController::ERROR_MISSING_REFUND_PAYMENT_DATA,
    422
);

$expectNoWrites(
    '5.8 Bizum con 8 dígitos',
    $report([
        'refund_requested' => true,
        'claimed_amount' => 2.00,
        'compensation_method' => 'BIZUM',
        'bizum_phone' => '60099988',
    ]),
    'INVALID_BIZUM_PHONE',
    422
);

$expectNoWrites(
    '5.9 Transferencia sin IBAN',
    $report([
        'refund_requested' => true,
        'claimed_amount' => 2.00,
        'compensation_method' => 'TRANSFERENCIA_BANCARIA',
    ]),
    QrScanController::ERROR_MISSING_REFUND_PAYMENT_DATA,
    422
);

$expectNoWrites(
    '5.10 IBAN con checksum falso',
    $report([
        'refund_requested' => true,
        'claimed_amount' => 2.00,
        'compensation_method' => 'TRANSFERENCIA_BANCARIA',
        'iban' => 'ES9121000418450200051399',
    ]),
    'INVALID_IBAN_FORMAT',
    422
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. Trazabilidad inmutable (RNF-REF-01)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. RNF-REF-01: la creación queda auditada ---\n";

$assert(
    '6.1 Cada apertura de expediente deja evento de auditoría',
    count(array_filter($auditRepo->events, static fn (AuditEvent $e): bool => $e->getAction() === 'REFUND_CASE_CREATED')) === count($refundRepo->rows),
    'eventos: ' . count($auditRepo->events) . ' vs expedientes: ' . count($refundRepo->rows)
);
$auditJson = (string)json_encode(array_map(static fn (AuditEvent $e): array => $e->toArray(), $auditRepo->events), JSON_THROW_ON_ERROR);
$assert('6.2 La auditoría NO arrastra el IBAN (Art. V.4)', !str_contains($auditJson, 'ES9121000418450200051332'));
$assert('6.3 La auditoría NO arrastra el teléfono de Bizum (Art. V.4)', !str_contains($auditJson, '600111222'));
$assert('6.4 La auditoría NO arrastra el nombre del reclamante (Art. V.4)', !str_contains($auditJson, 'Laura Sanitaria'));

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-09 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);
