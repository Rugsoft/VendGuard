<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Unit suite for `TechnicianRefundService` (T-REF-07).
 *
 * The "Hecho cuando" criterion requires green coverage of the three closed
 * verdicts, of the custody being forced towards the central safe above 10,00 €
 * or on digital channels, and of on-site cash being recorded when no consumer
 * claimed it.
 *
 * Beyond the happy path the suite certifies three things the specification is
 * explicit about and that are easy to get subtly wrong:
 *
 *  - An EXPLICIT attempt to leave digital or elevated cash at the reception desk
 *    is refused with 422, not silently rewritten. A silently rewritten choice
 *    would leave an audit trail attributing to the technician a decision they did
 *    not make (Art. III.3).
 *  - `UNVERIFIED_NO_CASH` without a written justification of at least 20
 *    characters is refused (RF-REF-04).
 *  - With several claimants and not enough cash in the hopper, EVERY case is
 *    escalated and nothing is auto-distributed (RF-REF-08).
 *
 * No database is touched: both repositories and the audit sink are in-memory
 * fakes living only in this file.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Application\DTO\TechnicianRefundInspectionDTO;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IbanValidationService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Application\Service\TechnicianRefundService;
use VendGuard\Core\Domain\Exception\JustificationTooShortException;
use VendGuard\Core\Domain\Exception\ReceptionDeliveryNotAllowedException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\UnclaimedCashFinding;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Core\Domain\Repository\UnclaimedCashFindingRepositoryInterface;

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

/** Reconstructs an immutable case with some columns replaced. */
function rebuildRefund(RefundRequest $case, array $overrides = []): RefundRequest
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

final class TechRefundRepo implements RefundRequestRepositoryInterface
{
    /** @var array<int, RefundRequest> */
    public array $rows = [];

    /** @var array<int, array<string, mixed>> */
    public array $sideColumns = [];

    private int $nextId = 1;

    public function insert(RefundRequest $refundRequest): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = rebuildRefund($refundRequest, ['id' => $id]);
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
        $found = array_values(array_filter(
            $this->rows,
            static fn (RefundRequest $r): bool => $r->getIncidentId() === $incidentId
        ));

        // Same ordering rule as the PDO repository: the most recent first.
        usort($found, static fn (RefundRequest $a, RefundRequest $b): int => $b->getId() <=> $a->getId());

        return $found;
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
            $name = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', (string)$column))));
            // Las columnas de enum llegan como string desde el repositorio
            // real; la entidad exige la instancia tipada.
            $entityFields[$name] = match ($name) {
                'technicianFinding' => $value === null ? null : TechnicianFinding::from((string)$value),
                'cashCustodyAction' => $value === null ? null : CashCustodyAction::from((string)$value),
                default => $value,
            };
            $this->sideColumns[$id][(string)$column] = $value;
        }

        $this->rows[$id] = rebuildRefund($current, $entityFields + ['status' => $newStatus]);

        return true;
    }

    public function updateContactDetails(int $id, ?string $bizumPhone, ?string $iban): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }

        $this->rows[$id] = rebuildRefund($this->rows[$id], [
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

        $this->rows[$id] = rebuildRefund($this->rows[$id], ['isActive' => false]);

        return true;
    }
}

final class TechFindingRepo implements UnclaimedCashFindingRepositoryInterface
{
    /** @var array<int, UnclaimedCashFinding> */
    public array $rows = [];

    private int $nextId = 1;

    public function insert(UnclaimedCashFinding $finding): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = new UnclaimedCashFinding(
            id: $id,
            incidentId: $finding->getIncidentId(),
            machineId: $finding->getMachineId(),
            technicianId: $finding->getTechnicianId(),
            amount: $finding->getAmount(),
            notes: $finding->getNotes(),
            createdAt: $finding->getCreatedAt()
        );

        return $id;
    }

    public function findById(int $id): ?UnclaimedCashFinding
    {
        return $this->rows[$id] ?? null;
    }

    public function findByIncident(int $incidentId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (UnclaimedCashFinding $f): bool => $f->getIncidentId() === $incidentId
        ));
    }

    public function findByTechnician(int $technicianId, int $limit = 50): array
    {
        return array_slice(array_values(array_filter(
            $this->rows,
            static fn (UnclaimedCashFinding $f): bool => $f->getTechnicianId() === $technicianId
        )), 0, $limit);
    }

    public function sumAmountByMachine(int $machineId): float
    {
        $total = 0.0;
        foreach ($this->rows as $row) {
            if ($row->getMachineId() === $machineId) {
                $total += $row->getAmount();
            }
        }

        return $total;
    }
}

final class TechAuditRepo implements AuditLogRepositoryInterface
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

/**
 * Minimal site double: the reception rule (RF-REF-05) needs to know whether a
 * site has a desk, and only `hasPhysicalReception()` is ever consulted. The rest
 * of the interface throws instead of pretending to work.
 */
final class TechLocationRepo implements LocationRepositoryInterface
{
    /** @var array<int, Location> */
    public array $rows = [];

    public function findById(int $id, bool $allowDeleted = false): ?Location
    {
        return $this->rows[$id] ?? null;
    }

    public function findBySiteCode(string $siteCode): ?Location
    {
        foreach ($this->rows as $location) {
            if ($location->getSiteCode() === $siteCode) {
                return $location;
            }
        }

        return null;
    }

    public function findAllActive(): array
    {
        return array_values($this->rows);
    }

    public function findAll(string $status = 'all', ?string $search = null): array
    {
        return array_values($this->rows);
    }

    public function create(array $data): Location
    {
        throw new LogicException('No se usa en esta suite.');
    }

    public function update(int $id, array $data): bool
    {
        throw new LogicException('No se usa en esta suite.');
    }

    public function softDelete(int $id): bool
    {
        throw new LogicException('No se usa en esta suite.');
    }

    public function restore(int $id): bool
    {
        throw new LogicException('No se usa en esta suite.');
    }

    public function updateContactPhone(int $id, string $contactPhone): bool
    {
        throw new LogicException('No se usa en esta suite.');
    }

    public function countActiveMachines(int $locationId): int
    {
        return 0;
    }
}

$refundRepo = new TechRefundRepo();
$findingRepo = new TechFindingRepo();
$auditRepo = new TechAuditRepo();
$management = new RefundManagementService($refundRepo, new IbanValidationService(), new AuditLogger($auditRepo));
$service = new TechnicianRefundService($refundRepo, $findingRepo, $management, new AuditLogger($auditRepo));

// La MISMA regla con la sede disponible: es la configuracion de produccion,
// donde `TechnicianController` inyecta el repositorio real de sedes.
$locationRepo = new TechLocationRepo();
$locationRepo->rows[1] = new Location(
    id: 1,
    siteCode: 'SEDE-CON-RECEPCION',
    name: 'Campus con conserjeria',
    address: 'Carrer de la Reception 1',
    hasPhysicalReception: true
);
$locationRepo->rows[2] = new Location(
    id: 2,
    siteCode: 'SEDE-SIN-RECEPCION',
    name: 'Aeropuerto sin conserjeria',
    address: 'Terminal sin mostrador 2',
    hasPhysicalReception: false
);
$siteAwareService = new TechnicianRefundService(
    $refundRepo,
    $findingRepo,
    $management,
    new AuditLogger($auditRepo),
    $locationRepo
);

$technician = ['id' => 7, 'role' => 'TECHNICIAN', 'name' => 'Jordi Prats'];
$validJustification = 'Se desmontó el embudo y no se localizó ninguna moneda ni rastro de saldo retenido.';

/**
 * Opens a claim so the inspection has something to work on.
 *
 * Each call gets its own incident unless one is given explicitly: an inspection
 * walks EVERY claim attached to an incident, so reusing one id would make the
 * cases of independent scenarios collide.
 */
$incidentSeq = 100;
// `$contact` permite abrir dos reclamaciones sobre la MISMA avería, que es el
// caso legítimo de RF-REF-08: son dos personas distintas y, por tanto, dos
// expedientes. Lo que RF-REF-11 prohíbe es que el MISMO consumidor repita.
// `$locationId` permite abrir el reclamo en una sede concreta: la regla de
// conserjería (RF-REF-05) se decide por la sede, no por el expediente.
$openClaim = static function (float $amount, CompensationMethod $method, ?int $incidentId = null, ?string $contact = null, int $locationId = 1) use ($management, &$incidentSeq): RefundRequest {
    $incidentId ??= ++$incidentSeq;
    $contact ??= '600111222';

    $dto = new CreateRefundRequestDTO(
        incidentId: $incidentId,
        machineId: 11,
        locationId: $locationId,
        claimantName: 'Laura Sanitaria',
        claimantContact: $contact,
        claimedAmount: $amount,
        compensationMethod: $method,
        productAttempted: 'Café con leche',
        bizumPhone: $method->requiresBizumPhone() ? '600111222' : null,
        iban: $method->requiresIban() ? 'ES9121000418450200051332' : null
    );

    return $management->createCase($dto);
};

// ─────────────────────────────────────────────────────────────────────────────
// 0. Dogma Vanilla e inviolabilidad
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 0. Dogma Vanilla (Art. IV) e inviolabilidad (Art. III) ---\n";

$source = (string)file_get_contents($baseDir . '/src/Application/Service/TechnicianRefundService.php');

$assert('0.1 Existe `TechnicianRefundService` en `Application/Service`', class_exists(TechnicianRefundService::class));
$assert('0.2 El servicio declara tipado estricto', str_contains($source, 'declare(strict_types=1);'));
$assert(
    '0.3 No importa ningún símbolo ajeno al proyecto (cero Composer)',
    preg_match('/^use\s+(?!VendGuard)/m', $source) !== 1
);
$assert(
    '0.4 El servicio NO ejecuta ningún DELETE FROM (Art. III)',
    !str_contains(strtoupper($source), 'DELETE FROM')
);
$assert(
    '0.5 El límite presencial reutiliza la constante del dominio',
    TechnicianRefundService::RECEPTION_DELIVERY_LIMIT === 10.00
);

// ─────────────────────────────────────────────────────────────────────────────
// 1. Los tres dictámenes cerrados
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. Dictamen con los tres valores cerrados del catálogo ---\n";

$case = $openClaim(3.00, CompensationMethod::EN_MANO_SEDE);
$result = $service->inspectBalance(
    $case->getIncidentId(),
    new TechnicianRefundInspectionDTO(
        finding: TechnicianFinding::FOUND_PHYSICAL,
        recoveredAmount: 3.00,
        cashCustodyAction: CashCustodyAction::LEFT_AT_RECEPTION,
        receptionistName: 'Laura (Conserjería Planta Baja)'
    ),
    11,
    $technician
);
$stored = $refundRepo->findById($case->getId());

$assert('1.1 FOUND_PHYSICAL con efectivo presencial queda en DEPOSITED_AT_RECEPTION', $stored?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION);
$assert('1.2 El dictamen técnico queda registrado', $stored?->getTechnicianFinding() === TechnicianFinding::FOUND_PHYSICAL);
$assert('1.3 El importe recuperado queda registrado', (float)$stored?->getRecoveredAmount() === 3.00);
$assert('1.4 El nombre del conserje queda registrado', $stored?->getReceptionistName() === 'Laura (Conserjería Planta Baja)');
$assert('1.5 El técnico firmante queda registrado', ($refundRepo->sideColumns[$case->getId()]['technician_id'] ?? null) === 7);
$assert('1.6 Se informa de un único expediente procesado', $result['processed'] === 1);

$noCash = $openClaim(2.00, CompensationMethod::EN_MANO_SEDE);
$service->inspectBalance(
    $noCash->getIncidentId(),
    new TechnicianRefundInspectionDTO(
        finding: TechnicianFinding::CONFIRMED_NO_CASH,
        recoveredAmount: 0.00,
        cashCustodyAction: CashCustodyAction::HELD_FOR_CENTRAL
    ),
    11,
    $technician
);
$assert(
    '1.7 CONFIRMED_NO_CASH sin efectivo recuperado escala por discrepancia (plan §3.3)',
    $refundRepo->findById($noCash->getId())?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL,
    'estado: ' . $refundRepo->findById($noCash->getId())?->getStatus()->value
);
$assert(
    '1.7b CONFIRMED_NO_CASH sin discrepancia sí va directo a VERIFIED_PENDING_PAYMENT',
    (static function () use ($openClaim, $service, $technician, $refundRepo): bool {
        $c = $openClaim(2.00, CompensationMethod::EN_MANO_SEDE);
        $service->inspectBalance(
            $c->getIncidentId(),
            new TechnicianRefundInspectionDTO(
                finding: TechnicianFinding::CONFIRMED_NO_CASH,
                recoveredAmount: 2.00,
                cashCustodyAction: CashCustodyAction::HELD_FOR_CENTRAL
            ),
            11,
            $technician
        );

        return $refundRepo->findById($c->getId())?->getStatus() === RefundStatus::VERIFIED_PENDING_PAYMENT;
    })()
);

$unverified = $openClaim(2.00, CompensationMethod::EN_MANO_SEDE);
$service->inspectBalance(
    $unverified->getIncidentId(),
    new TechnicianRefundInspectionDTO(
        finding: TechnicianFinding::UNVERIFIED_NO_CASH,
        recoveredAmount: 0.00,
        cashCustodyAction: CashCustodyAction::HELD_FOR_CENTRAL,
        justification: $validJustification
    ),
    11,
    $technician
);
$assert(
    '1.8 UNVERIFIED_NO_CASH escala a REQUIRES_COORDINATOR_APPROVAL',
    $refundRepo->findById($unverified->getId())?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL
);
$assert(
    '1.9 La justificación técnica queda registrada',
    $refundRepo->findById($unverified->getId())?->getTechnicianJustification() === $validJustification
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Justificación obligatoria para UNVERIFIED_NO_CASH (RF-REF-04)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. UNVERIFIED_NO_CASH exige justificación de 20 caracteres ---\n";

$shortJustification = null;
try {
    $service->inspectBalance(
        ++$incidentSeq,
        new TechnicianRefundInspectionDTO(
            finding: TechnicianFinding::UNVERIFIED_NO_CASH,
            recoveredAmount: 0.00,
            justification: 'No hay nada'
        ),
        11,
        $technician
    );
} catch (JustificationTooShortException $e) {
    $shortJustification = $e;
}

$assert(
    '2.1 Una justificación de 12 caracteres se rechaza con 422 JUSTIFICATION_TOO_SHORT',
    $shortJustification !== null
        && $shortJustification->getHttpStatusCode() === 422
        && $shortJustification->getErrorCode() === 'JUSTIFICATION_TOO_SHORT'
);
$assert(
    '2.2 El mínimo exigido es de 20 caracteres',
    JustificationTooShortException::MINIMUM_LENGTH === 20
);
$assert(
    '2.3 Una justificación de 20 caracteres EXACTOS se acepta',
    (static function () use ($service, $technician): bool {
        $c = null;
        try {
            $service->inspectBalance(
                999,
                new TechnicianRefundInspectionDTO(
                    finding: TechnicianFinding::UNVERIFIED_NO_CASH,
                    recoveredAmount: 0.00,
                    justification: str_repeat('a', 20)
                ),
                11,
                $technician
            );
            return true;
        } catch (Throwable $e) {
            $c = $e;
            return false;
        }
    })()
);
$assert(
    '2.4 Los otros dos dictámenes NO exigen justificación escrita',
    (static function () use ($service, $technician): bool {
        try {
            $service->inspectBalance(
                998,
                new TechnicianRefundInspectionDTO(
                    finding: TechnicianFinding::CONFIRMED_NO_CASH,
                    recoveredAmount: 0.00
                ),
                11,
                $technician
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    })()
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Custodia forzada hacia caja central (RF-REF-05)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Custodia forzada y rechazo del depósito indebido ---\n";

$digital = $openClaim(3.00, CompensationMethod::BIZUM);
$service->inspectBalance(
    $digital->getIncidentId(),
    new TechnicianRefundInspectionDTO(
        finding: TechnicianFinding::FOUND_PHYSICAL,
        recoveredAmount: 3.00
    ),
    11,
    $technician
);
$assert(
    '3.1 Un canal digital con custodia sin especificar se fuerza a HELD_FOR_CENTRAL',
    $refundRepo->findById($digital->getId())?->getCashCustodyAction() === CashCustodyAction::HELD_FOR_CENTRAL,
    'custodia: ' . var_export($refundRepo->findById($digital->getId())?->getCashCustodyAction(), true)
);

$elevated = $openClaim(25.00, CompensationMethod::EN_MANO_SEDE, 302);
$service->inspectBalance(
    $elevated->getIncidentId(),
    new TechnicianRefundInspectionDTO(
        finding: TechnicianFinding::FOUND_PHYSICAL,
        recoveredAmount: 25.00
    ),
    11,
    $technician
);
$assert(
    '3.2 Un importe > 10,00 € con custodia sin especificar se fuerza a HELD_FOR_CENTRAL',
    $refundRepo->findById($elevated->getId())?->getCashCustodyAction() === CashCustodyAction::HELD_FOR_CENTRAL
);
$assert(
    '3.3 Un importe > 10,00 € además escala a REQUIRES_COORDINATOR_APPROVAL',
    $refundRepo->findById($elevated->getId())?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL
);

$rejected = $openClaim(3.00, CompensationMethod::BIZUM, 303);
$attempt = null;
try {
    $service->inspectBalance(
        $rejected->getIncidentId(),
        new TechnicianRefundInspectionDTO(
            finding: TechnicianFinding::FOUND_PHYSICAL,
            recoveredAmount: 3.00,
            cashCustodyAction: CashCustodyAction::LEFT_AT_RECEPTION
        ),
        11,
        $technician
    );
} catch (Throwable $e) {
    $attempt = $e;
}
$assert(
    '3.4 Elegir explícitamente conserjería para un canal digital se rechaza con 422',
    $attempt instanceof ReceptionDeliveryNotAllowedException && $attempt->getHttpStatusCode() === 422,
    'excepción real: ' . ($attempt === null ? 'ninguna' : get_class($attempt))
);
$assert(
    '3.5 El rechazo NO reescribe en silencio la elección del técnico',
    $refundRepo->findById($rejected->getId())?->getStatus() === RefundStatus::PENDING_INSPECTION
        && $refundRepo->findById($rejected->getId())?->getCashCustodyAction() === null
);

// El reclamo se eleva a 18,00 € para que el techo de `recovered_amount`
// (que no puede superar lo reclamado) sea el happy path y la custodia en
// conserjería sea el motivo real del rechazo (RF-REF-05).
$rejectedHigh = $openClaim(18.00, CompensationMethod::EN_MANO_SEDE, 304);
$attemptHigh = null;
try {
    $service->inspectBalance(
        $rejectedHigh->getIncidentId(),
        new TechnicianRefundInspectionDTO(
            finding: TechnicianFinding::FOUND_PHYSICAL,
            recoveredAmount: 18.00,
            cashCustodyAction: CashCustodyAction::LEFT_AT_RECEPTION
        ),
        11,
        $technician
    );
} catch (Throwable $e) {
    $attemptHigh = $e;
}
$assert(
    '3.6 Elegir conserjería con 18,00 € recuperados se rechaza con 422',
    $attemptHigh instanceof ReceptionDeliveryNotAllowedException && $attemptHigh->getHttpStatusCode() === 422,
    'excepción real: ' . ($attemptHigh === null ? 'ninguna' : get_class($attemptHigh))
);

$allowed = $openClaim(5.00, CompensationMethod::EN_MANO_SEDE, 305);
$service->inspectBalance(
    $allowed->getIncidentId(),
    new TechnicianRefundInspectionDTO(
        finding: TechnicianFinding::FOUND_PHYSICAL,
        recoveredAmount: 5.00,
        cashCustodyAction: CashCustodyAction::LEFT_AT_RECEPTION,
        receptionistName: 'Conserjería Planta Baja'
    ),
    11,
    $technician
);
$assert(
    '3.7 Un importe <= 10,00 € en presencial SÍ puede quedar en conserjería',
    $refundRepo->findById($allowed->getId())?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
);

// ─────────────────────────────────────────────────────────────────────────────
// 3.b La sede decide si existe conserjería (RF-REF-05, `has_physical_reception`)
// ─────────────────────────────────────────────────────────────────────────────

$noDesk = $openClaim(5.00, CompensationMethod::EN_MANO_SEDE, 306, null, 2);
$attemptNoDesk = null;
try {
    $siteAwareService->inspectBalance(
        $noDesk->getIncidentId(),
        new TechnicianRefundInspectionDTO(
            finding: TechnicianFinding::FOUND_PHYSICAL,
            recoveredAmount: 5.00,
            cashCustodyAction: CashCustodyAction::LEFT_AT_RECEPTION,
            receptionistName: 'Mostrador del aeropuerto'
        ),
        11,
        $technician
    );
} catch (Throwable $e) {
    $attemptNoDesk = $e;
}
$assert(
    '3.8 Una sede sin conserjería física NO admite depósito en recepción',
    $attemptNoDesk instanceof ReceptionDeliveryNotAllowedException && $attemptNoDesk->getHttpStatusCode() === 422,
    'excepción real: ' . ($attemptNoDesk === null ? 'ninguna' : get_class($attemptNoDesk))
);
$assert(
    '3.9 El rechazo NO reescribe la custodia: el sobre sigue sin dueño declarado',
    $refundRepo->findById($noDesk->getId())?->getStatus() === RefundStatus::PENDING_INSPECTION
        && $refundRepo->findById($noDesk->getId())?->getCashCustodyAction() === null
);
$assert(
    '3.10 El motivo explica que el efectivo va a caja central',
    $attemptNoDesk instanceof ReceptionDeliveryNotAllowedException
        && str_contains($attemptNoDesk->getMessage(), 'HELD_FOR_CENTRAL')
);

$withDesk = $openClaim(5.00, CompensationMethod::EN_MANO_SEDE, 307, null, 1);
$siteAwareService->inspectBalance(
    $withDesk->getIncidentId(),
    new TechnicianRefundInspectionDTO(
        finding: TechnicianFinding::FOUND_PHYSICAL,
        recoveredAmount: 5.00,
        cashCustodyAction: CashCustodyAction::LEFT_AT_RECEPTION,
        receptionistName: 'Conserjería Campus'
    ),
    11,
    $technician
);
$assert(
    '3.11 La MISMA regla en una sede CON conserjería sí deja el sobre en recepción',
    $refundRepo->findById($withDesk->getId())?->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Discrepancia con reclamaciones múltiples (RF-REF-08)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Reclamaciones múltiples y dinero insuficiente (RF-REF-08) ---\n";

// Dos consumidores distintos sobre la avería 200: Laura reclama 4,00 € y Marc
// 3,00 €, así que 7,00 € es lo que hay que cubrir con el efectivo recuperado.
$a = $openClaim(4.00, CompensationMethod::EN_MANO_SEDE, 200, '600111222');
$b = $openClaim(3.00, CompensationMethod::EN_MANO_SEDE, 200, '600999888');

$multi = $service->inspectBalance(
    200,
    new TechnicianRefundInspectionDTO(
        finding: TechnicianFinding::FOUND_PHYSICAL,
        recoveredAmount: 5.00,
        cashCustodyAction: CashCustodyAction::HELD_FOR_CENTRAL
    ),
    11,
    $technician
);

$assert('4.1 Se procesan TODOS los expedientes de la incidencia', $multi['processed'] === 2);
$assert('4.2 Se informa del total reclamado (7,00 €)', $multi['claimed_total'] === 7.00);
$assert('4.3 Se informa del total recuperado (5,00 €)', $multi['recovered_total'] === 5.00);
$assert('4.4 Se marca la discrepancia de shortfall', $multi['discrepancy'] === true);
$assert(
    '4.5 TODOS los expedientes escalan a REQUIRES_COORDINATOR_APPROVAL',
    array_unique($multi['statuses']) === [RefundStatus::REQUIRES_COORDINATOR_APPROVAL->value],
    'estados: ' . implode(',', $multi['statuses'])
);
$assert(
    '4.6 Ningún expediente se reparte automáticamente el efectivo',
    $refundRepo->findById($a->getId())?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL
);

// RF-REF-08: cada expediente guarda SU PARTE proporcional del efectivo
// recuperado, no el total de la avería. Antes ambos guardaban 5,00 € y el
// segundo reclamante mostraba una discrepancia falsa; peor aún, Coordinación
// podía liquidar el importe íntegro de cada uno y pagar más que lo recuperado.
// 4,00 y 3,00 sobre 5,00 € recuperados dan 2,857… y 2,142…, que en céntimos
// enteros son 2,86 € y 2,14 € (la suma exacta es 5,00 €).
$partA = $refundRepo->findById($a->getId())?->getRecoveredAmount();
$partB = $refundRepo->findById($b->getId())?->getRecoveredAmount();
$assert(
    '4.6b El reparto proporcional asigna 2,86 € al reclamante de 4,00 €',
    $partA === 2.86,
    'parte A: ' . var_export($partA, true)
);
$assert(
    '4.6c El reparto proporcional asigna 2,14 € al reclamante de 3,00 €',
    $partB === 2.14,
    'parte B: ' . var_export($partB, true)
);
$assert(
    '4.6d La suma de las partes es EXACTAMENTE el efectivo recuperado',
    abs(($partA + $partB) - 5.00) < 0.0001,
    'suma: ' . ($partA + $partB)
);
$assert(
    '4.6e Ningún expediente registra más de lo que él mismo reclamó',
    $partA <= 4.00 && $partB <= 3.00,
    "A={$partA}/4.00 B={$partB}/3.00"
);

$exactDiscrepancy = null;
$repeatInspection = null;
try {
    $exact = $service->inspectBalance(
        200,
        new TechnicianRefundInspectionDTO(
            finding: TechnicianFinding::FOUND_PHYSICAL,
            recoveredAmount: 7.00,
            cashCustodyAction: CashCustodyAction::HELD_FOR_CENTRAL
        ),
        11,
        $technician
    );
    $exactDiscrepancy = $exact['discrepancy'];
} catch (\VendGuard\Core\Domain\Exception\InvalidRefundStateTransitionException $e) {
    $repeatInspection = $e;
}

// Un expediente ya dictaminado no admite un segundo dictamen, pero tampoco
// bloquea la resolución: `inspectBalance()` solo dictamina los pendientes y
// preserva los ya dictaminados (Art. III.3). Antes, convivir un dictaminado con
// un pendiente abortaba con 409 y dejaba al expediente atascado sin salida.
$assert(
    '4.7 Un segundo dictamen no reprocesa los expedientes ya dictaminados',
    $repeatInspection === null && $exact['processed'] === 0,
    'excepción: ' . ($repeatInspection === null ? 'ninguna' : get_class($repeatInspection))
);
$assert(
    '4.7b Los expedientes ya escalados conservan su estado',
    $refundRepo->findById($a->getId())?->getStatus() === RefundStatus::REQUIRES_COORDINATOR_APPROVAL,
    'estado: ' . ($refundRepo->findById($a->getId())?->getStatus()->value ?? 'N/D')
);

// El caso más delicado: un expediente YA entregado en mano, re-inspeccionado con
// shortfall. La clasificación se salta porque el cortocircuito de discrepancia
// va antes, así que la única defensa es la comprobación de arista. Sin ella, el
// Sobrescribiría en silencio un expediente ya liquidado al usuario.
$settled = $openClaim(3.00, CompensationMethod::EN_MANO_SEDE, 400);
$service->inspectBalance(
    400,
    new TechnicianRefundInspectionDTO(
        finding: TechnicianFinding::FOUND_PHYSICAL,
        recoveredAmount: 3.00,
        cashCustodyAction: CashCustodyAction::LEFT_AT_RECEPTION,
        receptionistName: 'Conserjería'
    ),
    11,
    $technician
);
$management->deliverInHand($settled->getId(), (string)$refundRepo->findById($settled->getId())->getPickupPin());

$overwrite = null;
try {
    $service->inspectBalance(
        400,
        new TechnicianRefundInspectionDTO(
            finding: TechnicianFinding::UNVERIFIED_NO_CASH,
            recoveredAmount: 0.00,
            justification: $validJustification
        ),
        11,
        $technician
    );
} catch (Throwable $e) {
    $overwrite = $e;
}
$assert(
    '4.8 Un expediente ya entregado en mano NO puede ser sobrescrito por un nuevo dictamen',
    $overwrite === null && $refundRepo->findById($settled->getId())?->getStatus() === RefundStatus::REFUNDED_IN_HAND,
    'excepción real: ' . ($overwrite === null ? 'ninguna' : get_class($overwrite))
);
$assert(
    '4.9 Tras el intento fallido el expediente sigue entregado en mano',
    $refundRepo->findById($settled->getId())?->getStatus() === RefundStatus::REFUNDED_IN_HAND
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Hallazgo de monedas de oficio sin reclamación previa (RF-REF-04)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. Efectivo atascado recuperado de oficio (RF-REF-04) ---\n";

$noClaim = $service->inspectBalance(
    777,
    new TechnicianRefundInspectionDTO(finding: TechnicianFinding::CONFIRMED_NO_CASH, recoveredAmount: 0.00),
    11,
    $technician
);
$assert('5.1 Una incidencia sin reclamación no procesa ningún expediente', $noClaim['processed'] === 0);

$finding = $service->registerUnclaimedCash(777, 11, 7, 1.50, 'Moneda de 1 € y 50 cts en el selector.', $technician);

$assert('5.2 El hallazgo se persiste con identificador generado', ($finding->getId() ?? 0) > 0);
$assert('5.3 El importe del hallazgo queda registrado', $finding->getAmount() === 1.50);
$assert('5.4 El hallazgo queda ligado al técnico que lo recuperó', $finding->getTechnicianId() === 7);
$assert('5.5 El hallazgo queda ligado a la incidencia', $finding->getIncidentId() === 777);
$assert(
    '5.6 El hallazgo NO se adjunta a ningún expediente de reintegro',
    count($findingRepo->findByIncident(777)) === 1 && $findingRepo->findByIncident(777)[0]->getId() === $finding->getId()
);
$assert(
    '5.7 El hallazgo se audita bajo su propia entidad',
    (bool) array_filter($auditRepo->events, static fn (AuditEvent $e): bool
        => $e->getEntityType() === AuditEvent::ENTITY_UNCLAIMED_CASH_FINDING)
);
$assert(
    '5.8 Un importe de 0,00 € se rechaza: un hallazgo vacío no registra nada',
    (static function () use ($service, $technician): bool {
        try {
            $service->registerUnclaimedCash(777, 11, 7, 0.0, '', $technician);
            return false;
        } catch (Throwable $e) {
            return true;
        }
    })()
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. Auditoría y confidencialidad (Art. III y Art. V.4)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. Trazabilidad del dictamen (Art. III) ---\n";

$inspectEvents = array_values(array_filter(
    $auditRepo->events,
    static fn (AuditEvent $e): bool => $e->getAction() === 'REFUND_INSPECTED'
));
$assert('6.1 Cada dictamen filed queda auditado', count($inspectEvents) > 0);
$assert(
    '6.2 Los eventos de dictamen atribuyen el rol de técnico de campo',
    count(array_filter($inspectEvents, static fn (AuditEvent $e): bool
        => $e->getUserRole() !== 'TECHNICIAN')) === 0
);

$serialized = json_encode(array_map(static fn (AuditEvent $e): array => $e->toArray(), $auditRepo->events), JSON_THROW_ON_ERROR);
$assert('6.3 El IBAN nunca aparece en la auditoría', !str_contains($serialized, 'ES9121000418450200051332'));
$assert('6.4 El teléfono privado nunca aparece en la auditoría', !str_contains($serialized, '600111222'));

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-07 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);