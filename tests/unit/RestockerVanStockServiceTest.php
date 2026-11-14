<?php

declare(strict_types=1);

/**
 * RestockerVanStockServiceTest · T-PLD-07
 *
 * Certifica el servicio de inventario por lote en la furgoneta del reponedor
 * (Módulo 33, RF-01.2, RF-01.3, RF-05.2, D2, D4, QA 2, QA 3, Art. III.3,
 * Art. V.1 y Art. V.4) sin PostgreSQL.
 *
 * QUÉ SE CERTIFICA
 *   1. Consulta de Coordinación con filtros, KPIs y semáforo sanitario.
 *   2. Consulta del propio reponedor, aislada y con alerta de caducados.
 *   3. Ajuste manual: motivo ≥ 20 caracteres reales, lote y caducidad de frío,
 *      ventana de vida útil, inmutabilidad de la fecha del lote existente,
 *      reducción de caducados y cotas.
 *   4. Auditoría `RESTOCKER_VAN_STOCK_ADJUSTED` con saldo previo, saldo nuevo
 *      y motivo, sin escribir nada cuando la validación falla.
 *   5. Opacidad estructural de `unit_cost_eur` y precios (Art. V.4).
 *
 * Los dobles mantienen el estado en memoria con la semántica observable de los
 * puertos; la transacción real ya está cubierta por las suites de integración de
 * `PdoRestockerVanStockRepositoryTest` y `PdoProductLoadingOrderRepositoryTest`.
 *
 * Uso:
 *   php tests/unit/RestockerVanStockServiceTest.php
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\RestockerVanStockService;
use VendGuard\Core\Domain\Enum\BatchExpiryStatus;
use VendGuard\Core\Domain\Exception\ProductLoadingValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\RestockerVanStockRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\Repository\VendingProductRepositoryInterface;

/**
 * Repositorio de stock en memoria con la misma semántica observable del puerto.
 */
final class FakeRestockerVanStockRepositoryForServiceTest implements RestockerVanStockRepositoryInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    /** @var array<int, VendingProduct> */
    private array $products;

    /** @var array<int, User> */
    private array $restockers;

    private int $nextId = 100;

    /**
     * @param array<int, VendingProduct> $products
     * @param array<int, User> $restockers
     */
    public function __construct(array $products, array $restockers)
    {
        $this->products = $products;
        $this->restockers = $restockers;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function seed(array $row): void
    {
        $this->rows[$this->key(
            (int)$row['restocker_id'],
            (int)$row['product_id'],
            (string)$row['batch_code']
        )] = $row;
    }

    public function search(
        ?int $restockerId = null,
        ?string $category = null,
        ?string $expiryStatus = null,
        ?string $search = null
    ): array {
        $term = $search === null ? '' : mb_strtolower(trim($search));
        $status = $expiryStatus === null ? null : strtoupper(trim($expiryStatus));

        $result = [];
        foreach ($this->rows as $row) {
            if ($restockerId !== null && (int)$row['restocker_id'] !== $restockerId) {
                continue;
            }
            if ($category !== null && (string)$row['product_category'] !== $category) {
                continue;
            }
            $rowWithStatus = $this->withStatus($row);
            if ($status !== null && $status !== 'ALL' && (string)$rowWithStatus['expiry_status'] !== $status) {
                continue;
            }
            if ($term !== '') {
                $haystack = mb_strtolower(
                    (string)$row['product_sku'] . ' ' . (string)$row['product_name'] . ' ' . (string)$row['batch_code']
                );
                if (!str_contains($haystack, $term)) {
                    continue;
                }
            }

            $result[] = $rowWithStatus;
        }

        usort($result, static function (array $left, array $right): int {
            $leftDate = $left['expiration_date'];
            $rightDate = $right['expiration_date'];
            if ($leftDate === null && $rightDate === null) {
                return (int)$left['product_id'] <=> (int)$right['product_id'];
            }
            if ($leftDate === null) {
                return 1;
            }
            if ($rightDate === null) {
                return -1;
            }

            return strcmp((string)$leftDate, (string)$rightDate);
        });

        return $result;
    }

    public function findByRestocker(
        int $restockerId,
        ?string $category = null,
        ?string $expiryStatus = null,
        ?string $search = null
    ): array {
        return $this->search($restockerId, $category, $expiryStatus, $search);
    }

    public function findLot(
        int $restockerId,
        int $productId,
        string $batchCode,
        bool $forUpdate = false
    ): ?RestockerVanProductStock {
        $key = $this->key($restockerId, $productId, strtoupper(trim($batchCode)));

        return isset($this->rows[$key]) ? $this->hydrate($this->rows[$key]) : null;
    }

    public function upsertLot(RestockerVanProductStock $stock): RestockerVanProductStock
    {
        $key = $this->key($stock->getRestockerId(), $stock->getProductId(), $stock->getBatchCode());
        $existing = $this->rows[$key] ?? null;
        $product = $this->products[$stock->getProductId()];
        $restocker = $this->restockers[$stock->getRestockerId()];

        $row = $existing ?? [
            'id' => $this->nextId++,
            'restocker_id' => $stock->getRestockerId(),
            'product_id' => $stock->getProductId(),
            'batch_code' => $stock->getBatchCode(),
            'expiration_date' => $stock->getExpirationDate()?->format('Y-m-d'),
            'quantity_available' => 0,
            'product_sku' => $product->getSku(),
            'product_name' => $product->getName(),
            'product_category' => $product->getCategory()->value,
            'product_category_label' => $product->getCategory()->label(),
            'product_unit_type' => $product->getUnitType()->value,
            'requires_cold_chain' => $product->requiresColdChain(),
            'max_shelf_life_days' => $product->getMaxShelfLifeDays(),
            'restocker_name' => $restocker->getName(),
            'restocker_operator_code' => $restocker->getOperatorCode(),
            'created_at' => '2026-11-13T08:00:00+01:00',
            'updated_at' => '2026-11-13T08:00:00+01:00',
        ];

        // QA 3: la fecha del lote existente no se reescribe.
        $row['quantity_available'] = $stock->getQuantityAvailable();
        $row['updated_at'] = '2026-11-13T09:00:00+01:00';
        $this->rows[$key] = $row;

        return $this->hydrate($row);
    }

    public function sumUsableQuantityByProduct(int $restockerId, ?DateTimeImmutable $today = null): array
    {
        $result = [];
        foreach ($this->findByRestocker($restockerId) as $row) {
            $result[(int)$row['product_id']] = ($result[(int)$row['product_id']] ?? 0)
                + (int)$row['usable_quantity'];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function allRows(): array
    {
        return array_map(fn (array $row): array => $this->withStatus($row), array_values($this->rows));
    }

    private function key(int $restockerId, int $productId, string $batch): string
    {
        return $restockerId . ':' . $productId . ':' . $batch;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function withStatus(array $row): array
    {
        $expiration = $row['expiration_date'] === null ? null : new DateTimeImmutable((string)$row['expiration_date']);
        $status = BatchExpiryStatus::fromExpirationDate($expiration);
        $quantity = (int)$row['quantity_available'];

        return array_merge($row, [
            'expiry_status' => $status->value,
            'expiry_status_label' => $status->label(),
            'expiry_status_icon' => $status->icon(),
            'is_expired' => $status === BatchExpiryStatus::EXPIRED,
            'is_usable' => $expiration === null || $status !== BatchExpiryStatus::EXPIRED,
            'usable_quantity' => $status === BatchExpiryStatus::EXPIRED ? 0 : $quantity,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RestockerVanProductStock
    {
        return RestockerVanProductStock::fromDatabaseRow($row);
    }
}

/**
 * Catálogo en memoria: solo `findById` tiene semántica en este servicio.
 */
final class FakeVendingProductRepositoryForServiceTest implements VendingProductRepositoryInterface
{
    /** @var array<int, VendingProduct> */
    private array $products;

    /** @param array<int, VendingProduct> $products */
    public function __construct(array $products)
    {
        $this->products = $products;
    }

    public function create(VendingProduct $product): VendingProduct
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function update(VendingProduct $product): bool
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function updateStatus(int $id, bool $isActive): bool
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function findById(int $id): ?VendingProduct
    {
        return $this->products[$id] ?? null;
    }

    public function findBySku(string $sku): ?VendingProduct
    {
        foreach ($this->products as $product) {
            if ($product->getSku() === strtoupper($sku)) {
                return $product;
            }
        }

        return null;
    }

    public function isSkuUsed(string $sku, ?int $excludeId = null): bool
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function findAll(
        ?string $search = null,
        ?ProductCategory $category = null,
        ?bool $isActive = null
    ): array {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function countActiveSlots(int $productId): int
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function findMachineAssignments(int $productId, bool $onlyActive = true): array
    {
        throw new RuntimeException('No usado por el servicio.');
    }
}

/**
 * Directorio de usuarios en memoria: solo `findById`.
 */
final class FakeUserRepositoryForServiceTest implements UserRepositoryInterface
{
    /** @var array<int, User> */
    private array $users;

    /** @param array<int, User> $users */
    public function __construct(array $users)
    {
        $this->users = $users;
    }

    public function findByEmail(string $email, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function findById(int $id, bool $onlyActive = true, bool $allowDeleted = false): ?User
    {
        $user = $this->users[$id] ?? null;

        return $user !== null && (!$onlyActive || $user->isActive()) ? $user : null;
    }

    public function findAllTechnicians(bool $onlyActive = true): array
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function create(array $data): User
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function update(int $id, array $data): bool
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function updatePassword(int $id, string $newPassword): bool
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function resetPassword(int $id, string $newPassword): bool
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function softDelete(int $id): bool
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function restore(int $id): bool
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function findAll(array $filters = []): array
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function countActiveByRole(UserRole|string $role): int
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function countActiveAssignedIncidents(int $userId): int
    {
        throw new RuntimeException('No usado por el servicio.');
    }

    public function countPendingIncidents(int $technicianId): int
    {
        throw new RuntimeException('No usado por el servicio.');
    }
}

/**
 * Libro de auditoría en memoria, estrictamente append-only.
 */
final class FakeAuditLogRepositoryForServiceTest implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $persisted = new AuditEvent(
            count($this->events) + 1,
            $event->getEntityType(),
            $event->getEntityId(),
            $event->getAction(),
            $event->getUserId(),
            $event->getUserRole(),
            $event->getUserName(),
            $event->getPreviousState(),
            $event->getNewState(),
            $event->getMetadata(),
            $event->getCreatedAt()
        );
        $this->events[] = $persisted;

        return $persisted;
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
            static fn (AuditEvent $event): bool => $event->getEntityType() === $entityType
                && $event->getEntityId() === $entityId
        ));
    }
}

$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid'));
$date = static fn (int $offset): string => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

$coldProduct = new VendingProduct(
    10,
    'TEST-PLD-T7-COLD',
    'Sándwich T7',
    ProductCategory::PERISHABLE_FOOD,
    ProductUnitType::UNIT,
    1.50,
    3.00,
    true,
    5,
    true
);
$ambientProduct = new VendingProduct(
    11,
    'TEST-PLD-T7-AMB',
    'Refresco T7',
    ProductCategory::COLD_DRINK,
    ProductUnitType::UNIT,
    0.80,
    1.50,
    false,
    null,
    true
);

$restocker = new User(
    7,
    'Reponedor Siete',
    'rep7@vendguard.test',
    'hash',
    UserRole::RESTOCKER,
    null,
    true,
    null,
    null,
    null,
    false,
    null,
    'REP-07'
);
$otherRestocker = new User(
    8,
    'Reponedor Ocho',
    'rep8@vendguard.test',
    'hash',
    UserRole::RESTOCKER,
    null,
    true,
    null,
    null,
    null,
    false,
    null,
    'REP-08'
);
$inactiveRestocker = new User(
    9,
    'Reponedor Inactivo',
    'rep9@vendguard.test',
    'hash',
    UserRole::RESTOCKER,
    null,
    false,
    null,
    null,
    null,
    false,
    null,
    'REP-09'
);
$coordinator = new User(
    3,
    'Coordinación',
    'coord@vendguard.test',
    'hash',
    UserRole::COORDINATOR,
    null,
    true,
    null,
    null,
    null,
    false,
    null,
    'COORD-03'
);

$products = [10 => $coldProduct, 11 => $ambientProduct];
$restockers = [7 => $restocker, 8 => $otherRestocker, 9 => $inactiveRestocker, 3 => $coordinator];

$repo = new FakeRestockerVanStockRepositoryForServiceTest($products, $restockers);
$seed = static function (int $id, int $restockerId, VendingProduct $product, string $batch, ?string $expiration, int $quantity) use ($repo): void {
    $repo->seed([
        'id' => $id,
        'restocker_id' => $restockerId,
        'product_id' => (int)$product->getId(),
        'batch_code' => $batch,
        'expiration_date' => $expiration,
        'quantity_available' => $quantity,
        'product_sku' => $product->getSku(),
        'product_name' => $product->getName(),
        'product_category' => $product->getCategory()->value,
        'product_category_label' => $product->getCategory()->label(),
        'product_unit_type' => $product->getUnitType()->value,
        'requires_cold_chain' => $product->requiresColdChain(),
        'max_shelf_life_days' => $product->getMaxShelfLifeDays(),
        'restocker_name' => $restockerId === 8 ? 'Reponedor Ocho' : 'Reponedor Siete',
        'restocker_operator_code' => $restockerId === 8 ? 'REP-08' : 'REP-07',
        'created_at' => '2026-11-13T08:00:00+01:00',
        'updated_at' => '2026-11-13T08:00:00+01:00',
    ]);
};

$seed(1, 7, $coldProduct, 'LOTE-A', $date(1), 8);
$seed(2, 7, $ambientProduct, 'SIN-LOTE', null, 7);
$seed(3, 7, $coldProduct, 'LOTE-OLD', $date(-1), 4);
$seed(4, 8, $coldProduct, 'LOTE-B', $date(-1), 5);

$auditRepo = new FakeAuditLogRepositoryForServiceTest();
$service = new RestockerVanStockService(
    $repo,
    new FakeVendingProductRepositoryForServiceTest($products),
    new FakeUserRepositoryForServiceTest($restockers),
    new AuditLogger($auditRepo)
);

$failures = 0;
$assertions = 0;

$assert = function (string $title, bool $condition, string $message = '') use (&$failures, &$assertions): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$title}\n";
    } else {
        echo "  [FAIL] {$title}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

$assertNoCost = static function (array $payload): bool {
    return !array_key_exists('unit_cost_eur', $payload)
        && !array_key_exists('sale_price_eur', $payload)
        && !array_key_exists('unit_margin_eur', $payload);
};

/**
 * @param callable(): mixed $call
 */
$assertValidation = function (
    string $title,
    callable $call,
    string $expectedCode,
    int $expectedHttp
) use ($assert): void {
    try {
        $call();
        $assert($title, false, 'no lanzó excepción');
    } catch (ProductLoadingValidationException $exception) {
        $assert(
            $title,
            $exception->getErrorCode() === $expectedCode
            && $exception->getHttpStatusCode() === $expectedHttp,
            'código=' . $exception->getErrorCode() . ' http=' . $exception->getHttpStatusCode()
        );
    }
};

echo "======================================================================\n";
echo " VendGuard · Unit · RestockerVanStockServiceTest (T-PLD-07)\n";
echo "======================================================================\n\n";

// =====================================================================
// 1. Consulta de Coordinación (RF-01.2, D4)
// =====================================================================
echo "--- 1. Consulta de Coordinación ---\n";

$inventory = $service->getCoordinatorInventory();
$stocks = $inventory['stocks'];
$kpis = $inventory['kpis'];

$assert(
    '1.1 El inventario devuelve los cuatro lotes en orden FEFO',
    count($stocks) === 4
    && array_column($stocks, 'batch_code') === ['LOTE-OLD', 'LOTE-B', 'LOTE-A', 'SIN-LOTE'],
    json_encode(array_column($stocks, 'batch_code'))
);
$assert(
    '1.2 Los KPIs suman lotes, unidades, caducados y reponedores afectados',
    $kpis['total_lots'] === 4
    && $kpis['total_units'] === 24
    && $kpis['usable_units'] === 15
    && $kpis['expired_lots'] === 2
    && $kpis['expired_units'] === 9
    && $kpis['expiring_soon_lots'] === 1
    && $kpis['affected_restockers_count'] === 2,
    json_encode($kpis)
);
$assert(
    '1.3 El semáforo viaja con etiqueta e icono y sin coste (Art. V.4)',
    $stocks[0]['expiry_status'] === 'EXPIRED'
    && $stocks[0]['expiry_status_label'] === 'Caducado'
    && $stocks[0]['expiry_status_icon'] === '🔴'
    && $stocks[0]['usable_quantity'] === 0
    && $assertNoCost($stocks[0])
);
$assert(
    '1.4 El filtro de categoría se delega al puerto',
    count($service->getCoordinatorInventory(null, 'PERISHABLE_FOOD')['stocks']) === 3
);
$assert(
    '1.5 El filtro de búsqueda alcanza el código de lote',
    count($service->getCoordinatorInventory(null, null, null, 'LOTE-A')['stocks']) === 1
);
$assert(
    '1.6 El filtro de caducidad devuelve solo los vencidos',
    count($service->getCoordinatorInventory(null, null, 'EXPIRED')['stocks']) === 2
);

// =====================================================================
// 2. Consulta del reponedor (RF-01.2, RF-05.2, Art. V.4)
// =====================================================================
echo "\n--- 2. Consulta del propio reponedor ---\n";

$own = $service->getOwnVanStock(7);
$assert(
    '2.1 El reponedor solo ve sus tres lotes',
    count($own['stocks']) === 3
    && array_reduce(
        $own['stocks'],
        static fn (bool $carry, array $row): bool => $carry && $row['restocker_id'] === 7,
        true
    )
);
$assert(
    '2.2 La alerta de caducados se activa con un lote vencido',
    $own['has_expired_lots'] === true && $own['kpis']['expired_lots'] === 1
);
$assert(
    '2.3 La proyección móvil no expone coste ni precio (Art. V.4)',
    array_reduce($own['stocks'], static fn (bool $carry, array $row): bool => $carry && $assertNoCost($row), true)
);
$assert(
    '2.4 El reponedor sin stock devuelve KPIs a cero y sin alerta',
    $service->getOwnVanStock(9)['kpis']['total_lots'] === 0
    && $service->getOwnVanStock(9)['has_expired_lots'] === false
);

// =====================================================================
// 3. Ajuste manual auditado (EARS 01.3)
// =====================================================================
echo "\n--- 3. Ajuste manual auditado ---\n";

$created = $service->adjustStock(7, [
    'product_id' => '10',
    'batch_code' => ' lote-nuevo-01 ',
    'expiration_date' => $date(2),
    'quantity_available' => '12',
    'reason' => '  Alta   inicial por recuento físico  ',
], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']);

$assert(
    '3.1 Un lote nuevo de frío se normaliza y se persiste',
    $created['stock']['batch_code'] === 'LOTE-NUEVO-01'
    && $created['stock']['quantity_available'] === 12
    && $created['stock']['expiration_date'] === $date(2)
    && $created['previous_quantity'] === null
    && $created['stock']['requires_cold_chain'] === true,
    json_encode($created)
);
$assert(
    '3.2 El ajuste deja un evento con saldo previo, saldo nuevo y motivo',
    count($auditRepo->events) === 1
    && $auditRepo->events[0]->getEntityType() === AuditEvent::ENTITY_RESTOCKER_VAN_STOCK
    && $auditRepo->events[0]->getAction() === RestockerVanStockService::AUDIT_ACTION_ADJUSTED
    && $auditRepo->events[0]->getEntityId() === (int)$created['stock']['id']
    && $auditRepo->events[0]->getPreviousState() === null
    && $auditRepo->events[0]->getNewState()['quantity_available'] === 12
    && $auditRepo->events[0]->getNewState()['reason'] === 'Alta inicial por recuento físico'
    && $auditRepo->events[0]->getUserId() === 3
    && $auditRepo->events[0]->getUserName() === 'Coordinación'
);
$assert(
    '3.3 El resultado del ajuste sigue sin campos económicos (Art. V.4)',
    $assertNoCost($created['stock'])
);
$assert(
    '3.4 El ajuste devuelve el identificador del evento de auditoría',
    $created['audit_event_id'] === 1
);

$reduced = $service->adjustStock(7, [
    'product_id' => 10,
    'batch_code' => 'LOTE-A',
    'expiration_date' => $date(1),
    'quantity_available' => 3,
    'reason' => 'Reducción por recuento físico del almacén',
], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']);

$assert(
    '3.5 Un lote existente se reduce conservando su fecha (QA 3)',
    $reduced['previous_quantity'] === 8
    && $reduced['stock']['quantity_available'] === 3
    && $reduced['stock']['expiration_date'] === $date(1)
    && $auditRepo->events[1]->getPreviousState()['quantity_available'] === 8
);
$assert(
    '3.6 La reducción no duplica la fila del lote',
    count(array_filter(
        $repo->allRows(),
        static fn (array $row): bool => $row['restocker_id'] === 7 && $row['batch_code'] === 'LOTE-A'
    )) === 1
);

$assertValidation(
    '3.7 Un motivo de menos de 20 caracteres reales se rechaza (Art. V.1)',
    static fn () => $service->adjustStock(7, [
        'product_id' => 10,
        'batch_code' => 'LOTE-A',
        'expiration_date' => $date(1),
        'quantity_available' => 2,
        'reason' => '   Recuento   corto   ',
    ], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']),
    ProductLoadingValidationException::INVALID_JUSTIFICATION_LENGTH,
    422
);
$assert(
    '3.8 El rechazo no escribe stock ni auditoría',
    count($auditRepo->events) === 2
    && count(array_filter(
        $repo->allRows(),
        static fn (array $row): bool => $row['batch_code'] === 'LOTE-A' && (int)$row['quantity_available'] === 3
    )) === 1
);

$assertValidation(
    '3.9 El frío exige lote real (Art. II)',
    static fn () => $service->adjustStock(7, [
        'product_id' => 10,
        'batch_code' => 'SIN-LOTE',
        'expiration_date' => $date(1),
        'quantity_available' => 2,
        'reason' => 'Intento de alta sin lote real en frío',
    ], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']),
    ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
    422
);
$assertValidation(
    '3.10 El frío exige fecha de caducidad (Art. II)',
    static fn () => $service->adjustStock(7, [
        'product_id' => 10,
        'batch_code' => 'LOTE-SIN-FECHA',
        'quantity_available' => 2,
        'reason' => 'Intento de alta sin fecha de caducidad',
    ], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']),
    ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
    422
);
$assertValidation(
    '3.11 Un lote caducado no puede darse de alta con unidades (QA 2)',
    static fn () => $service->adjustStock(7, [
        'product_id' => 10,
        'batch_code' => 'LOTE-NUEVO-VIEJO',
        'expiration_date' => $date(-1),
        'quantity_available' => 2,
        'reason' => 'Intento de alta de un lote ya caducado',
    ], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']),
    ProductLoadingValidationException::EXPIRED_OR_INVALID_BATCH,
    422
);
$assertValidation(
    '3.12 La caducidad no puede superar max_shelf_life_days (QA 2)',
    static fn () => $service->adjustStock(7, [
        'product_id' => 10,
        'batch_code' => 'LOTE-FUTURO',
        'expiration_date' => $date(6),
        'quantity_available' => 2,
        'reason' => 'Intento de alta fuera de la vida útil',
    ], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']),
    ProductLoadingValidationException::EXPIRATION_EXCEEDS_MAX_SHELF_LIFE,
    422
);
$assertValidation(
    '3.13 Un lote existente no puede cambiar su fecha (QA 3)',
    static fn () => $service->adjustStock(7, [
        'product_id' => 10,
        'batch_code' => 'LOTE-A',
        'expiration_date' => $date(2),
        'quantity_available' => 3,
        'reason' => 'Intento de cambio de fecha del lote',
    ], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']),
    ProductLoadingValidationException::BATCH_EXPIRATION_MISMATCH,
    409
);
$assertValidation(
    '3.14 Un lote existente sin fecha tampoco admite que se le imponga una (QA 3)',
    static fn () => $service->adjustStock(7, [
        'product_id' => 11,
        'batch_code' => 'SIN-LOTE',
        'expiration_date' => $date(3),
        'quantity_available' => 7,
        'reason' => 'Intento de fechar un lote que ya existía',
    ], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']),
    ProductLoadingValidationException::BATCH_EXPIRATION_MISMATCH,
    409
);
$assertValidation(
    '3.15 Un reponedor inactivo no puede recibir ajustes (C2)',
    static fn () => $service->adjustStock(9, [
        'product_id' => 10,
        'batch_code' => 'LOTE-A',
        'expiration_date' => $date(1),
        'quantity_available' => 1,
        'reason' => 'Intento de ajuste sobre cuenta inactiva',
    ], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']),
    ProductLoadingValidationException::INVALID_RESTOCKER_RECIPIENT,
    422
);
$assertValidation(
    '3.16 Un usuario que no es reponedor no puede recibir ajustes (C2)',
    static fn () => $service->adjustStock(3, [
        'product_id' => 10,
        'batch_code' => 'LOTE-A',
        'expiration_date' => $date(1),
        'quantity_available' => 1,
        'reason' => 'Intento de ajuste sobre un coordinador',
    ], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']),
    ProductLoadingValidationException::INVALID_RESTOCKER_RECIPIENT,
    422
);
$assertValidation(
    '3.17 Las cotas de cantidad se respetan (C1)',
    static fn () => $service->adjustStock(7, [
        'product_id' => 10,
        'batch_code' => 'LOTE-A',
        'expiration_date' => $date(1),
        'quantity_available' => 100000,
        'reason' => 'Intento de cantidad fuera de las cotas',
    ], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']),
    ProductLoadingValidationException::INVALID_LOADING_QUANTITY,
    422
);

try {
    $service->adjustStock(7, [
        'product_id' => 999,
        'batch_code' => 'LOTE-A',
        'expiration_date' => $date(1),
        'quantity_available' => 1,
        'reason' => 'Intento de ajuste de un producto inexistente',
    ], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']);
    $assert('3.18 Un producto inexistente se rechaza', false, 'no lanzó excepción');
} catch (InvalidArgumentException) {
    $assert('3.18 Un producto inexistente se rechaza', true);
}

$expiredReduction = $service->adjustStock(7, [
    'product_id' => 10,
    'batch_code' => 'LOTE-OLD',
    'expiration_date' => $date(-1),
    'quantity_available' => 0,
    'reason' => 'Retirada completa del lote caducado',
], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']);

$assert(
    '3.19 Un lote caducado puede ajustarse a cero para registrar su retirada',
    $expiredReduction['stock']['quantity_available'] === 0
    && $expiredReduction['previous_quantity'] === 4
    && $auditRepo->events[2]->getPreviousState()['quantity_available'] === 4
);

$ambient = $service->adjustStock(7, [
    'product_id' => 11,
    'quantity_available' => 9,
    'reason' => 'Reposición de refrescos sin lote',
], ['id' => 3, 'role' => 'COORDINATOR', 'name' => 'Coordinación']);

$assert(
    '3.20 Un producto sin frío se normaliza a SIN-LOTE y sin caducidad',
    $ambient['stock']['batch_code'] === 'SIN-LOTE'
    && $ambient['stock']['expiration_date'] === null
    && $ambient['stock']['quantity_available'] === 9
    && $ambient['stock']['requires_cold_chain'] === false
);

$assert(
    '3.21 Los rechazos no han añadido eventos: solo hay cuatro ajustes auditados',
    count($auditRepo->events) === 4
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. SERVICIO DE STOCK DE FURGONETA CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL SERVICIO DE STOCK DE FURGONETA.\n";
echo "======================================================================\n";
exit(1);
