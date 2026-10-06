<?php

declare(strict_types=1);

/**
 * CoordinatorPreventiveDetailServiceTest
 *
 * Batería de pruebas unitarias del servicio de ficha integral de órdenes preventivas
 * (T-PREV-27, RF-PD-03 a RF-PD-09, RNF-PD-01, RNF-PD-04, RNF-PD-05).
 *
 * Verifica:
 * 1. Bloques de sede, máquina y técnico con etiquetas en español (Dualismo Lingüístico).
 * 2. Semáforo de vigencia: frontera de 5 días, vencimiento, cierre, cuarentena y pausa.
 * 3. Contabilidad del checklist: contadores, porcentaje de cumplimiento y fallos críticos.
 * 4. Avería correctiva vinculada resuelta por ID y nunca inferida de la máquina.
 * 5. Certificado sanitario más reciente de la máquina, o nulo si no existe.
 * 6. Trazabilidad de auditoría traducida, conservando acciones desconocidas.
 * 7. Lectura pura: el servicio no ejecuta ninguna escritura (Art. III, RNF-PD-04).
 *
 * Dogma Vanilla: PHP 8.2 puro, dobles en memoria, cero dependencias externas.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\CoordinatorPreventiveDetailService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\PreventiveOrderItem;
use VendGuard\Core\Domain\Model\SanitaryCertificate;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveItemRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitaryCertificateRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

// =============================================================================
// Infraestructura de Aserciones
// =============================================================================

$assertions = 0;
$failures = 0;

function assertCase(string $description, bool $condition, string $details = ''): void
{
    global $assertions, $failures;
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        return;
    }
    echo "  [FAIL] {$description}\n";
    if ($details !== '') {
        echo "         Motivo: {$details}\n";
    }
    $failures++;
}

echo "======================================================================\n";
echo " VendGuard: Unit - CoordinatorPreventiveDetailServiceTest (T-PREV-27)\n";
echo "======================================================================\n\n";

// =============================================================================
// Dobles de prueba en memoria
// =============================================================================

class DetailOrderRepoDouble implements PreventiveOrderRepositoryInterface
{
    public int $writeCalls = 0;

    /** @param array<int, PreventiveOrder> $orders */
    public function __construct(private array $orders = [])
    {
    }

    public function create(array $data): PreventiveOrder
    {
        $this->writeCalls++;
        throw new RuntimeException('Escritura no permitida en una ficha de solo consulta.');
    }

    public function findById(int $id, bool $allowCancelled = true): ?PreventiveOrder
    {
        return $this->orders[$id] ?? null;
    }

    public function findByCode(string $orderCode, bool $allowCancelled = true): ?PreventiveOrder
    {
        foreach ($this->orders as $order) {
            if ($order->getOrderCode() === $orderCode) {
                return $order;
            }
        }

        return null;
    }

    public function hasActiveOrPendingOrder(int $machineId): bool
    {
        return false;
    }

    public function assignTechnician(int $orderId, int $technicianId, string $scheduledDate): bool
    {
        $this->writeCalls++;
        return false;
    }

    public function claimOrderOpportunistically(int $orderId, int $technicianId): bool
    {
        $this->writeCalls++;
        return false;
    }

    public function startInspection(int $orderId, int $technicianId): bool
    {
        $this->writeCalls++;
        return false;
    }

    public function completeOrder(
        int $orderId,
        string $result,
        ?float $temperatureMeasured,
        bool $isQuarantineTriggered,
        ?int $linkedIncidentId = null,
        ?string $notes = null
    ): bool {
        $this->writeCalls++;
        return false;
    }

    public function linkIncident(int $orderId, int $incidentId): bool
    {
        $this->writeCalls++;
        return false;
    }

    public function softCancel(int $orderId, string $reason): bool
    {
        $this->writeCalls++;
        return false;
    }

    /** @return array<PreventiveOrder> */
    public function findForCoordinatorList(array $filters = []): array
    {
        return array_values($this->orders);
    }

    public function countForCoordinatorList(array $filters = []): int
    {
        return count($this->orders);
    }

    /** @return array<PreventiveOrder> */
    public function findForTechnicianRoute(int $technicianId, ?int $locationId = null): array
    {
        return [];
    }

    public function getDashboardSummary(): array
    {
        return [];
    }

    public function expireOverdueOrders(): int
    {
        return 0;
    }
}

class DetailItemRepoDouble implements PreventiveItemRepositoryInterface
{
    public int $writeCalls = 0;

    /** @param array<int, PreventiveOrderItem> $items */
    public function __construct(private array $items = [])
    {
    }

    public function saveOrderItems(int $orderId, array $items): bool
    {
        $this->writeCalls++;
        return false;
    }

    /** @return array<PreventiveOrderItem> */
    public function findByOrderId(int $orderId): array
    {
        return $this->items;
    }

    public function findById(int $itemId): ?PreventiveOrderItem
    {
        return null;
    }

    public function attachPhoto(int $itemId, string $photoPath): bool
    {
        $this->writeCalls++;
        return false;
    }

    public function attachPhotoByItemCode(int $orderId, string $itemCode, string $photoPath): bool
    {
        $this->writeCalls++;
        return false;
    }

    /** @return array<PreventiveOrderItem> */
    public function findCriticalFailures(int $orderId): array
    {
        return [];
    }

    public function hasCriticalFailures(int $orderId): bool
    {
        return false;
    }
}

class DetailCertificateRepoDouble implements SanitaryCertificateRepositoryInterface
{
    public int $writeCalls = 0;

    public function __construct(private ?SanitaryCertificate $certificate = null)
    {
    }

    public function createCertificate(array $data): SanitaryCertificate
    {
        $this->writeCalls++;
        throw new RuntimeException('Emisión de certificados fuera del alcance de la ficha.');
    }

    public function findById(int $id): ?SanitaryCertificate
    {
        return $this->certificate;
    }

    public function findByCertificateCode(string $code): ?SanitaryCertificate
    {
        return $this->certificate;
    }

    public function findActiveByMachineCode(string $machineCode): ?SanitaryCertificate
    {
        return $this->certificate;
    }

    public function findActiveByMachineId(int $machineId): ?SanitaryCertificate
    {
        return $this->certificate;
    }

    public function findLatestByMachineId(int $machineId): ?SanitaryCertificate
    {
        return $this->certificate;
    }

    public function suspendByMachineId(int $machineId, string $reason): int
    {
        $this->writeCalls++;
        return 0;
    }

    public function revokeByMachineId(int $machineId, string $reason): int
    {
        $this->writeCalls++;
        return 0;
    }

    public function getGlobalSiteReport(int $locationId): array
    {
        return [];
    }
}

class DetailIncidentRepoDouble implements IncidentRepositoryInterface
{
    public int $writeCalls = 0;

    /** @param array<int, Incident> $incidents */
    public function __construct(private array $incidents = [])
    {
    }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident
    {
        $this->writeCalls++;
        throw new RuntimeException('Escritura no permitida en una ficha de solo consulta.');
    }

    public function findById(int $id): ?Incident
    {
        return $this->incidents[$id] ?? null;
    }

    public function findByTicketCode(string $ticketCode): ?Incident
    {
        foreach ($this->incidents as $incident) {
            if ($incident->getTicketCode() === $ticketCode) {
                return $incident;
            }
        }

        return null;
    }

    public function findActiveByMachineId(int $machineId): ?Incident
    {
        return null;
    }

    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident
    {
        return null;
    }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }

    /** @return array<Incident> */
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array
    {
        return [];
    }

    /** @return array<Incident> */
    public function findAll(array $filters = []): array
    {
        return [];
    }

    /** @return array<Incident> */
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array
    {
        return [];
    }

    public function findEnrichedDetailById(int|string $identifier): ?array
    {
        return null;
    }

    public function update(Incident $incident): bool
    {
        $this->writeCalls++;
        return false;
    }

    public function softDelete(int $id): bool
    {
        $this->writeCalls++;
        return false;
    }

    public function insertHistory(
        int $incidentId,
        ?int $userId,
        ?string $fromStatus,
        string $toStatus,
        ?string $actionNote = null
    ): int {
        $this->writeCalls++;
        return 0;
    }

    public function getHistory(int $incidentId): array
    {
        return [];
    }

    public function addComment(\VendGuard\Core\Domain\Model\IncidentComment $comment): \VendGuard\Core\Domain\Model\IncidentComment
    {
        $this->writeCalls++;
        throw new RuntimeException('Escritura no permitida en una ficha de solo consulta.');
    }

    public function getComments(int $incidentId, bool $includeInternal = true): array
    {
        return [];
    }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }

    public function countReopenEvents(int $incidentId): int
    {
        return 0;
    }

    public function markAsChronic(int $incidentId): bool
    {
        $this->writeCalls++;
        return false;
    }

    public function assign(
        int $incidentId,
        int $technicianId,
        ?int $coordinatorId = null,
        ?string $urgencyOverride = null,
        ?string $urgencyReason = null
    ): Incident {
        $this->writeCalls++;
        throw new RuntimeException('Escritura no permitida en una ficha de solo consulta.');
    }

    public function reopen(int $incidentId, string $reasonText): Incident
    {
        $this->writeCalls++;
        throw new RuntimeException('Escritura no permitida en una ficha de solo consulta.');
    }

    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident
    {
        $this->writeCalls++;
        throw new RuntimeException('Escritura no permitida en una ficha de solo consulta.');
    }

    public function startIntervention(int $incidentId, int $technicianId): Incident
    {
        $this->writeCalls++;
        throw new RuntimeException('Escritura no permitida en una ficha de solo consulta.');
    }

    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident
    {
        $this->writeCalls++;
        throw new RuntimeException('Escritura no permitida en una ficha de solo consulta.');
    }

    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident
    {
        $this->writeCalls++;
        throw new RuntimeException('Escritura no permitida en una ficha de solo consulta.');
    }

    /** @return array<Incident> */
    public function autoCloseResolvedIncidents(int $hours = 48): array
    {
        $this->writeCalls++;
        return [];
    }
}

class DetailAuditRepoDouble implements AuditLogRepositoryInterface
{
    public int $writeCalls = 0;

    /** @var list<array{entityType: string, entityId: int}> */
    public array $findByEntityCalls = [];

    /** @param array<int, AuditEvent> $events */
    public function __construct(private array $events = [])
    {
    }

    public function log(AuditEvent $event): AuditEvent
    {
        $this->writeCalls++;
        throw new RuntimeException('La ficha de consulta no emite eventos de auditoría.');
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->events;
    }

    public function countEvents(array $filters = []): int
    {
        return count($this->events);
    }

    /** @return list<AuditEvent> */
    public function findByEntity(string $entityType, int $entityId): array
    {
        $this->findByEntityCalls[] = ['entityType' => $entityType, 'entityId' => $entityId];

        return array_values(array_filter(
            $this->events,
            static fn (AuditEvent $event): bool =>
                $event->getEntityType() === $entityType && $event->getEntityId() === $entityId
        ));
    }
}

// =============================================================================
// Fixtures
// =============================================================================

/**
 * Construye una orden preventiva con los datos enriquecidos que publica el repositorio.
 */
function buildOrder(array $overrides = []): PreventiveOrder
{
    $defaults = [
        'id' => 1,
        'orderCode' => 'ORD-PREV-2026-0001',
        'machineId' => 7,
        'locationId' => 3,
        'assignedTechnicianId' => 2,
        'status' => 'COMPLETED',
        'orderType' => 'ROUTINE',
        'scheduledDate' => '2026-10-04',
        'dueDate' => '2026-10-06',
        'startedAt' => '2026-10-05 09:00:00',
        'completedAt' => '2026-10-05 09:35:00',
        'temperatureMeasured' => 3.2,
        'result' => 'CONFORME',
        'linkedIncidentId' => null,
        'isQuarantineTriggered' => false,
        'notes' => 'Inspección higiénico-sanitaria conforme.',
        'cancellationReason' => null,
        'createdAt' => '2026-09-22 12:00:00',
        'updatedAt' => '2026-10-05 09:35:00',
        'deletedAt' => null,
        'machineData' => [
            'id' => 7,
            'code' => 'VEND-0101',
            'model' => 'Sanden Vendo G-Drink',
            'machine_type' => 'PERISHABLE_FOOD',
            'floor_wing' => 'Planta Baja - Urgencias',
            'sanitary_status' => 'OK',
        ],
        'locationData' => [
            'id' => 3,
            'site_code' => 'SEDE-BCN-01',
            'name' => 'Hospital del Mar - Edificio Central',
            'address' => 'Passeig Marítim 25, Barcelona',
        ],
        'technicianData' => [
            'id' => 2,
            'name' => 'Jordi Técnico Ruta BCN',
            'operator_code' => 'OP-01',
        ],
    ];

    $data = array_merge($defaults, $overrides);

    return new PreventiveOrder(
        id: (int)$data['id'],
        orderCode: (string)$data['orderCode'],
        machineId: (int)$data['machineId'],
        locationId: (int)$data['locationId'],
        assignedTechnicianId: $data['assignedTechnicianId'] !== null ? (int)$data['assignedTechnicianId'] : null,
        status: (string)$data['status'],
        orderType: (string)$data['orderType'],
        scheduledDate: (string)$data['scheduledDate'],
        dueDate: (string)$data['dueDate'],
        startedAt: $data['startedAt'],
        completedAt: $data['completedAt'],
        temperatureMeasured: $data['temperatureMeasured'] !== null ? (float)$data['temperatureMeasured'] : null,
        result: $data['result'],
        linkedIncidentId: $data['linkedIncidentId'] !== null ? (int)$data['linkedIncidentId'] : null,
        isQuarantineTriggered: (bool)$data['isQuarantineTriggered'],
        notes: $data['notes'],
        cancellationReason: $data['cancellationReason'],
        createdAt: $data['createdAt'],
        updatedAt: $data['updatedAt'],
        deletedAt: $data['deletedAt'],
        machineData: $data['machineData'],
        locationData: $data['locationData'],
        technicianData: $data['technicianData']
    );
}

/**
 * Construye el servicio con dobles y devuelve los dobles para poder auditar escrituras.
 *
 * @return array{0: CoordinatorPreventiveDetailService, 1: array<string, object>}
 */
function buildService(
    array $orders = [],
    array $items = [],
    ?SanitaryCertificate $certificate = null,
    array $incidents = [],
    array $events = []
): array {
    $orderRepo = new DetailOrderRepoDouble($orders);
    $itemRepo = new DetailItemRepoDouble($items);
    $certificateRepo = new DetailCertificateRepoDouble($certificate);
    $incidentRepo = new DetailIncidentRepoDouble($incidents);
    $auditRepo = new DetailAuditRepoDouble($events);

    $service = new CoordinatorPreventiveDetailService(
        orderRepo: $orderRepo,
        itemRepo: $itemRepo,
        certificateRepo: $certificateRepo,
        incidentRepo: $incidentRepo,
        auditRepo: $auditRepo
    );

    return [$service, [
        'order' => $orderRepo,
        'item' => $itemRepo,
        'certificate' => $certificateRepo,
        'incident' => $incidentRepo,
        'audit' => $auditRepo,
    ]];
}

$now = new DateTimeImmutable('2026-10-06 08:00:00', new DateTimeZone('Europe/Madrid'));

// =============================================================================
// BLOQUE 1: Bloques de sede, máquina y técnico
// =============================================================================
echo "--- BLOQUE 1: Sede, máquina y técnico ---\n";

[$service, $doubles] = buildService(orders: [1 => buildOrder()]);
$detail = $service->buildDetail(1, $now);
assertCase('1.1 La orden existente devuelve ficha', $detail !== null);
$blocks = $detail->toArray();

assertCase('1.2 Código y estado traducido de la orden', $blocks['order']['order_code'] === 'ORD-PREV-2026-0001'
    && $blocks['order']['status'] === 'COMPLETED'
    && $blocks['order']['status_label'] === 'Completada');
assertCase('1.3 Tipo de orden traducido', $blocks['order']['order_type_label'] === 'Ordinaria'
    && $blocks['order']['order_type'] === 'ROUTINE');
assertCase('1.4 Dictamen traducido y temperatura publicados', $blocks['order']['result_label'] === 'Conforme'
    && $blocks['order']['temperature_measured'] === 3.2);
assertCase('1.5 Sede con nombre, código y dirección', $blocks['location']['name'] === 'Hospital del Mar - Edificio Central'
    && $blocks['location']['site_code'] === 'SEDE-BCN-01'
    && $blocks['location']['address'] === 'Passeig Marítim 25, Barcelona');
assertCase('1.6 Máquina con tipología traducida y distintivo de perecederos (Art. II)',
    $blocks['machine']['machine_type'] === 'PERISHABLE_FOOD'
    && $blocks['machine']['machine_type_label'] === 'Alimentos perecederos (Sándwiches y lácteos frescos)'
    && $blocks['machine']['has_perishables'] === true
    && $blocks['machine']['sanitary_status_label'] === 'Operativa y vigente');
assertCase('1.7 Técnico inspector con Código de Operador Oficial (Art. V.4)',
    $blocks['technician']['assigned'] === true
    && $blocks['technician']['name'] === 'Jordi Técnico Ruta BCN'
    && $blocks['technician']['operator_code'] === 'OP-01');
assertCase('1.8 Orden inexistente devuelve null', $service->buildDetail(999, $now) === null);

[$serviceByCode] = buildService(orders: [1 => buildOrder()]);
assertCase('1.9 El identificador acepta el código de orden sin almohadilla',
    $serviceByCode->buildDetail('ORD-PREV-2026-0001', $now) !== null);
assertCase('1.10 El identificador acepta el código de orden con almohadilla',
    $serviceByCode->buildDetail('#ORD-PREV-2026-0001', $now) !== null);

// =============================================================================
// BLOQUE 2: Semáforo de vigencia sanitaria (RF-PD-03.3, Art. II)
// =============================================================================
echo "\n--- BLOQUE 2: Semáforo de vigencia sanitaria ---\n";

[$serviceOpen] = buildService(orders: [2 => buildOrder([
    'id' => 2,
    'status' => 'SCHEDULED',
    'dueDate' => '2026-10-16',
    'completedAt' => null,
    'result' => null,
    'temperatureMeasured' => null,
])]);
$validityOpen = $serviceOpen->buildDetail(2, $now)->toArray()['validity'];
assertCase('2.1 Orden abierta a más de 5 días sigue vigente',
    $validityOpen['state'] === 'VIGENTE' && $validityOpen['days_remaining'] === 10 && $validityOpen['is_overdue'] === false);

[$serviceWarn] = buildService(orders: [3 => buildOrder([
    'id' => 3,
    'status' => 'SCHEDULED',
    'dueDate' => '2026-10-11',
    'completedAt' => null,
    'result' => null,
])]);
$validityWarn = $serviceWarn->buildDetail(3, $now)->toArray()['validity'];
assertCase('2.2 La frontera exacta de 5 días activa el aviso ámbar',
    $validityWarn['state'] === 'PROXIMA_A_VENCER' && $validityWarn['days_remaining'] === 5
    && $validityWarn['state_label'] === 'Próxima a vencer');

[$serviceDue] = buildService(orders: [4 => buildOrder([
    'id' => 4,
    'status' => 'SCHEDULED',
    'dueDate' => '2026-10-06',
    'completedAt' => null,
    'result' => null,
])]);
assertCase('2.3 La orden que vence hoy no cuenta como vencida',
    $serviceDue->buildDetail(4, $now)->toArray()['validity']['state'] === 'PROXIMA_A_VENCER');

[$serviceOverdue] = buildService(orders: [5 => buildOrder([
    'id' => 5,
    'status' => 'EXPIRED',
    'dueDate' => '2026-10-05',
    'completedAt' => null,
    'result' => null,
])]);
$validityOverdue = $serviceOverdue->buildDetail(5, $now)->toArray()['validity'];
assertCase('2.4 Orden vencida con días de retraso explícitos',
    $validityOverdue['state'] === 'VENCIDA' && $validityOverdue['days_remaining'] === -1
    && $validityOverdue['is_overdue'] === true && $validityOverdue['state_label'] === 'Vencida');

$validityClosed = $blocks['validity'];
assertCase('2.5 Orden completada publica balance histórico sin cuenta atrás',
    $validityClosed['state'] === 'CERRADA'
    && $validityClosed['state_label'] === 'Inspección cerrada'
    && $validityClosed['days_remaining'] === null
    && $validityClosed['is_overdue'] === false
    && $validityClosed['balance_label'] === 'Inspección completada el 05/10/2026 09:35');

[$serviceCancelled] = buildService(orders: [6 => buildOrder([
    'id' => 6,
    'status' => 'CANCELLED',
    'cancellationReason' => 'Máquina trasladada de sede (Art. III).',
    'completedAt' => null,
    'result' => null,
])]);
$blocksCancelled = $serviceCancelled->buildDetail(6, $now)->toArray();
assertCase('2.6 Orden cancelada conserva su motivo justificado',
    $blocksCancelled['validity']['state'] === 'CERRADA'
    && $blocksCancelled['validity']['balance_label'] === 'Orden cancelada lógicamente: sin inspección ejecutada'
    && $blocksCancelled['order']['cancellation_reason'] === 'Máquina trasladada de sede (Art. III).');

[$serviceQuarantine] = buildService(orders: [7 => buildOrder([
    'id' => 7,
    'isQuarantineTriggered' => true,
    'result' => 'NO_CONFORME',
])]);
$validityQuarantine = $serviceQuarantine->buildDetail(7, $now)->toArray()['validity'];
assertCase('2.7 La cuarentena sanitaria prevalece sobre el cierre (Art. II)',
    $validityQuarantine['state'] === 'CUARENTENA' && $validityQuarantine['state_label'] === 'Cuarentena sanitaria');

[$servicePause] = buildService(orders: [8 => buildOrder([
    'id' => 8,
    'status' => 'SCHEDULED',
    'dueDate' => '2026-10-20',
    'completedAt' => null,
    'result' => null,
    'machineData' => [
        'id' => 8,
        'code' => 'VEND-0102',
        'model' => 'Bianchi Gaia Espresso',
        'machine_type' => 'HOT_DRINKS',
        'floor_wing' => 'Planta 1',
        'sanitary_status' => 'SEASONAL_PAUSE',
    ],
])]);
$validityPause = $servicePause->buildDetail(8, $now)->toArray();
assertCase('2.8 La pausa estacional se refleja en el semáforo y en la máquina',
    $validityPause['validity']['state'] === 'PAUSA_ESTACIONAL'
    && $validityPause['machine']['sanitary_status_label'] === 'Pausa estacional'
    && $validityPause['machine']['has_perishables'] === false);

// =============================================================================
// BLOQUE 3: Checklist normativo (RF-PD-05)
// =============================================================================
echo "\n--- BLOQUE 3: Checklist normativo respondido ---\n";

$items = [
    new PreventiveOrderItem(1, 'TEMPERATURE_READING', 'Temperatura de sonda ≤ 4.0 °C', true, 'PASS', 'Sonda en 3.2 °C.'),
    new PreventiveOrderItem(1, 'BOILER_LEAK', 'Fuga en caldera', true, 'NOT_APPLICABLE', 'Sin caldera.'),
    new PreventiveOrderItem(1, 'ELECTRICAL_GROUNDING', 'Toma de tierra eléctrica', true, 'PASS', null),
    new PreventiveOrderItem(1, 'PEST_PRESENCE', 'Plagas o contaminación biológica', true, 'PASS', null),
    new PreventiveOrderItem(1, 'CASING_WEAR', 'Desgaste de carcasas', false, 'PASS', null),
    new PreventiveOrderItem(1, 'LED_LIGHTING', 'Iluminación LED degradada', false, 'WARN', 'Brillo reducido.', '/uploads/evidencia-led.jpg'),
    new PreventiveOrderItem(1, 'WATER_FILTER_LIFE', 'Filtro de agua', false, 'PASS', null),
];

[$serviceChecklist] = buildService(orders: [1 => buildOrder()], items: $items);
$checklist = $serviceChecklist->buildDetail(1, $now)->toArray()['checklist'];

assertCase('3.1 El checklist se marca como existente', $checklist['has_checklist'] === true);
assertCase('3.2 Contadores por estado del checklist',
    $checklist['totals']['total'] === 7
    && $checklist['totals']['pass'] === 5
    && $checklist['totals']['warn'] === 1
    && $checklist['totals']['fail'] === 0
    && $checklist['totals']['not_applicable'] === 1
    && $checklist['totals']['critical_failures'] === 0);
assertCase('3.3 Porcentaje de cumplimiento sobre ítems evaluables', $checklist['compliance_percent'] === 83);
assertCase('3.4 Ítems serializados con severidad, estado traducido y observaciones',
    $checklist['items'][0]['item_code'] === 'TEMPERATURE_READING'
    && $checklist['items'][0]['is_critical'] === true
    && $checklist['items'][0]['status_label'] === 'Conforme'
    && $checklist['items'][0]['observations'] === 'Sonda en 3.2 °C.'
    && $checklist['items'][0]['photo_url'] === null);
assertCase('3.5 La evidencia fotográfica y el aviso viajan en el contrato',
    $checklist['items'][5]['status'] === 'WARN'
    && $checklist['items'][5]['status_label'] === 'Con observaciones'
    && $checklist['items'][5]['photo_url'] === '/uploads/evidencia-led.jpg');

$criticalFailureItems = [
    new PreventiveOrderItem(9, 'PEST_PRESENCE', 'Plagas', true, 'FAIL', 'Indicios biológicos.'),
    new PreventiveOrderItem(9, 'CASING_WEAR', 'Desgaste', false, 'FAIL', 'Suciedad leve.'),
];
[$serviceCritical] = buildService(orders: [9 => buildOrder(['id' => 9, 'result' => 'NO_CONFORME', 'isQuarantineTriggered' => true])], items: $criticalFailureItems);
$criticalChecklist = $serviceCritical->buildDetail(9, $now)->toArray()['checklist'];
assertCase('3.6 Los fallos críticos se contabilizan aparte de los secundarios',
    $criticalChecklist['totals']['fail'] === 2 && $criticalChecklist['totals']['critical_failures'] === 1);

[$serviceNoChecklist] = buildService(orders: [10 => buildOrder(['id' => 10, 'status' => 'PENDING_ASSIGNMENT', 'completedAt' => null, 'result' => null])]);
$emptyChecklist = $serviceNoChecklist->buildDetail(10, $now)->toArray()['checklist'];
assertCase('3.7 Orden sin respuestas declara el checklist vacío',
    $emptyChecklist['has_checklist'] === false && $emptyChecklist['totals']['total'] === 0
    && $emptyChecklist['compliance_percent'] === 0 && $emptyChecklist['items'] === []);

// =============================================================================
// BLOQUE 4: Avería correctiva vinculada (RF-PD-07, Art. V.2)
// =============================================================================
echo "\n--- BLOQUE 4: Avería correctiva vinculada ---\n";

$linkedIncident = new Incident(
    id: 3661,
    ticketCode: 'INC-DEMO-0922',
    machineId: 7,
    locationId: 3,
    reporterName: 'Dra. Carmen Morales',
    reporterPhone: '611223344',
    category: IncidentCategory::TEMPERATURE_COLD,
    description: 'Temperatura alta en cuba de sándwiches.',
    urgency: UrgencyLevel::LOW,
    status: IncidentStatus::CLOSED,
    createdAt: '2026-09-22 09:15:00'
);

[$serviceLinked] = buildService(
    orders: [11 => buildOrder(['id' => 11, 'linkedIncidentId' => 3661])],
    incidents: [3661 => $linkedIncident]
);
$linkedBlock = $serviceLinked->buildDetail(11, $now)->toArray()['linked_incident'];
assertCase('4.1 La avería vinculada publica ticket, estado y urgencia traducidos',
    $linkedBlock !== null
    && $linkedBlock['id'] === 3661
    && $linkedBlock['ticket_code'] === 'INC-DEMO-0922'
    && $linkedBlock['status_label'] === 'Cerrada'
    && $linkedBlock['urgency'] === 'LOW'
    && $linkedBlock['urgency_label'] === 'Baja');

[$serviceNoLink] = buildService(orders: [12 => buildOrder(['id' => 12, 'linkedIncidentId' => null])]);
assertCase('4.2 Sin vínculo no se inventa ninguna avería activa de la máquina',
    $serviceNoLink->buildDetail(12, $now)->toArray()['linked_incident'] === null);

[$serviceDanglingLink] = buildService(orders: [13 => buildOrder(['id' => 13, 'linkedIncidentId' => 9999])]);
assertCase('4.3 Un vínculo huérfano se degrada a null sin romper la ficha',
    $serviceDanglingLink->buildDetail(13, $now)->toArray()['linked_incident'] === null);

// =============================================================================
// BLOQUE 5: Certificado sanitario vinculado (RF-PD-08, Art. V.4)
// =============================================================================
echo "\n--- BLOQUE 5: Certificado sanitario vinculado ---\n";

[$serviceNoCert] = buildService(orders: [14 => buildOrder(['id' => 14])]);
assertCase('5.1 Máquina sin certificado devuelve bloque nulo',
    $serviceNoCert->buildDetail(14, $now)->toArray()['certificate'] === null);

$certificate = new SanitaryCertificate(
    id: 1,
    certificateCode: 'CERT-2026-0001',
    preventiveOrderId: 1,
    machineId: 7,
    locationId: 3,
    technicianId: 2,
    technicianName: 'Jordi Técnico Ruta BCN',
    technicianOperatorCode: 'OP-01',
    inspectionDate: '2026-10-05 09:30:00',
    validUntil: '2026-10-19',
    temperatureMeasured: 3.2,
    result: 'CONFORME',
    status: 'VALID'
);
[$serviceCert] = buildService(orders: [15 => buildOrder(['id' => 15])], certificate: $certificate);
$certificateBlock = $serviceCert->buildDetail(15, $now)->toArray()['certificate'];
assertCase('5.2 El certificado se publica en modo consulta con inspector por código de operador',
    $certificateBlock !== null
    && $certificateBlock['certificate_code'] === 'CERT-2026-0001'
    && $certificateBlock['status'] === 'VALID'
    && $certificateBlock['status_label'] === 'Vigente'
    && $certificateBlock['result_label'] === 'Conforme'
    && $certificateBlock['temperature_measured'] === 3.2
    && $certificateBlock['inspector']['name'] === 'Jordi Técnico Ruta BCN'
    && $certificateBlock['inspector']['operator_code'] === 'OP-01');//=============================================================================
// BLOQUE 6: Trazabilidad de auditoría (RF-PD-09, Art. III)
//
// La cronología se compone desde la entidad MACHINE de la máquina de la orden
// (así la escriben los controladores y servicios reales del módulo), filtrada al
// vocabulario preventivo y atribuida por `metadata.order_code` / `metadata.order_id`.
//=============================================================================
echo "\n--- BLOQUE 6: Trazabilidad de auditoría ---\n";

$auditEvents = [
    // De la orden 1 (atribuida por order_code) sobre su máquina 7.
    new AuditEvent(
        id: 1,
        entityType: 'MACHINE',
        entityId: 7,
        action: 'ASSIGN_PREVENTIVE_ORDER',
        userId: 1,
        userRole: 'COORDINATOR',
        userName: 'Sara Coordinadora',
        previousState: null,
        newState: ['status' => 'SCHEDULED'],
        metadata: ['order_code' => 'ORD-PREV-2026-0001'],
        createdAt: '2026-10-04 10:00:00'
    ),
    // De la misma orden, atribuida por order_id (conclusión de la inspección).
    new AuditEvent(
        id: 2,
        entityType: 'MACHINE',
        entityId: 7,
        action: 'EVALUATE_PREVENTIVE_CHECKLIST',
        userId: 2,
        userRole: 'TECHNICIAN',
        userName: 'Jordi Técnico Ruta BCN',
        previousState: null,
        newState: ['status' => 'COMPLETED'],
        metadata: ['order_id' => 16],
        createdAt: '2026-10-05 09:35:00'
    ),
    // Acción preventiva desconocida de la propia orden: el código técnico se conserva.
    new AuditEvent(
        id: 3,
        entityType: 'MACHINE',
        entityId: 7,
        action: 'LEGACY_ACTION_42',
        userId: 1,
        userRole: 'COORDINATOR',
        userName: 'Sara Coordinadora',
        previousState: null,
        newState: [],
        metadata: ['order_code' => 'ORD-PREV-2026-0001'],
        createdAt: '2026-10-05 09:40:00'
    ),
    // Misma máquina, pero de OTRA orden: no debe entrar en la cronología.
    new AuditEvent(
        id: 4,
        entityType: 'MACHINE',
        entityId: 7,
        action: 'ASSIGN_PREVENTIVE_ORDER',
        userId: 1,
        userRole: 'COORDINATOR',
        userName: 'Sara Coordinadora',
        previousState: null,
        newState: ['status' => 'SCHEDULED'],
        metadata: ['order_code' => 'ORD-PREV-2026-0999'],
        createdAt: '2026-10-05 11:00:00'
    ),
    // Acción preventiva de OTRA máquina: no debe entrar en la cronología.
    new AuditEvent(
        id: 5,
        entityType: 'MACHINE',
        entityId: 8,
        action: 'CREATE_PREVENTIVE_ORDER',
        userId: 1,
        userRole: 'COORDINATOR',
        userName: 'Sara Coordinadora',
        previousState: null,
        newState: ['status' => 'PENDING_ASSIGNMENT'],
        metadata: ['order_code' => 'ORD-PREV-2026-0001'],
        createdAt: '2026-10-05 12:00:00'
    ),
    // Mantenimiento correctivo de la máquina (fuera del vocabulario preventivo): no entra.
    new AuditEvent(
        id: 6,
        entityType: 'MACHINE',
        entityId: 7,
        action: 'ASSIGN_TECHNICIAN',
        userId: 2,
        userRole: 'COORDINATOR',
        userName: 'Sara Coordinadora',
        previousState: null,
        newState: ['assigned_technician_id' => 2],
        metadata: ['ticket_code' => 'INC-DEMO-0810'],
        createdAt: '2026-10-05 13:00:00'
    ),
];

[$serviceAudit, $auditDoubles] = buildService(orders: [16 => buildOrder(['id' => 16])], events: $auditEvents);
$auditTrail = $serviceAudit->buildDetail(16, $now)->toArray()['audit_trail'];
$trailTimestamps = array_map(static fn (array $event): string => (string)$event['created_at'], $auditTrail);
assertCase('6.1 La cronología conserva el orden ascendente del repositorio',
    count($auditTrail) === 3
    && $auditTrail[0]['created_at'] === '2026-10-04 10:00:00'
    && $auditTrail[2]['created_at'] === '2026-10-05 09:40:00');
assertCase('6.2 Las acciones se traducen con su usuario y rol',
    $auditTrail[0]['action_label'] === 'Asignación de técnico'
    && $auditTrail[0]['user_name'] === 'Sara Coordinadora'
    && $auditTrail[0]['user_role'] === 'COORDINATOR'
    && $auditTrail[1]['action_label'] === 'Finalización de la inspección');
assertCase('6.3 Una acción desconocida conserva su código técnico (nunca se oculta traza)',
    $auditTrail[2]['action'] === 'LEGACY_ACTION_42' && $auditTrail[2]['action_label'] === 'LEGACY_ACTION_42');
assertCase('6.4 Un evento de la misma máquina pero de otra orden no entra en la cronología',
    !in_array('2026-10-05 11:00:00', $trailTimestamps, true));
assertCase('6.5 Un evento preventivo de otra máquina no entra en la cronología',
    !in_array('2026-10-05 12:00:00', $trailTimestamps, true));
assertCase('6.6 El mantenimiento correctivo de la máquina (acciones ajenas al vocabulario preventivo) no entra',
    !in_array('2026-10-05 13:00:00', $trailTimestamps, true));
assertCase('6.7 La cronología se lee del camino MACHINE de la máquina de la orden',
    count($auditDoubles['audit']->findByEntityCalls) === 1
    && $auditDoubles['audit']->findByEntityCalls[0] === ['entityType' => 'MACHINE', 'entityId' => 7]);

[$serviceNoAudit] = buildService(orders: [17 => buildOrder(['id' => 17])]);
assertCase('6.8 Sin eventos la cronología llega vacía, no nula',
    $serviceNoAudit->buildDetail(17, $now)->toArray()['audit_trail'] === []);

// =============================================================================
// BLOQUE 7: Lectura pura (RNF-PD-04, Art. III)
// =============================================================================
echo "\n--- BLOQUE 7: Lectura pura ---\n";

[$serviceReadOnly, $readOnlyDoubles] = buildService(
    orders: [18 => buildOrder(['id' => 18, 'linkedIncidentId' => 3661])],
    items: $items,
    certificate: $certificate,
    incidents: [3661 => $linkedIncident],
    events: $auditEvents
);

$serviceReadOnly->buildDetail(18, $now);
$serviceReadOnly->buildDetail('ORD-PREV-2026-0001', $now);
$serviceReadOnly->buildDetail(9999, $now);

$totalWrites = 0;
foreach ($readOnlyDoubles as $name => $double) {
    $totalWrites += $double->writeCalls;
}

assertCase('7.1 Ensamblar la ficha (incluso con identificadores inexistentes) no escribe nada (Art. III)',
    $totalWrites === 0, "Escrituras detectadas: {$totalWrites}");
assertCase('7.2 El camino de lectura no emite eventos de auditoría',
    $readOnlyDoubles['audit']->writeCalls === 0);

// =============================================================================
// Resultado final
// =============================================================================
echo "\n======================================================================\n";
$passed = $assertions - $failures;
echo " Total Assertions: {$assertions} | Passed: {$passed} | Failed: {$failures}\n";
if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. RF-PD-03 a RF-PD-09, RNF-PD-01 Y RNF-PD-04 VERIFICADOS.\n";
} else {
    echo " RESULT: FALLOS DETECTADOS. Revisar las aserciones anteriores.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
