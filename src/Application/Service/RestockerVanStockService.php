<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Core\Domain\Exception\ProductLoadingValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\RestockerVanStockRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\Repository\VendingProductRepositoryInterface;
use VendGuard\Core\Service\ProductLoadingValidator;

/**
 * RestockerVanStockService
 *
 * Casos de uso del inventario de producto por lote en la furgoneta del reponedor
 * (Módulo 33; RF-01.2, RF-01.3, RF-05.2; D2, D4; QA 2, QA 3; Art. III.3,
 * Art. V.1 y Art. V.4).
 *
 * QUÉ HACE Y QUÉ NO
 *
 *  - **Consulta de Coordinación (`getCoordinatorInventory`).** Devuelve el
 *    inventario FEFO con filtros de reponedor, categoría, semáforo y búsqueda,
 *    más KPIs de lotes, unidades, caducados y reponedores afectados.
 *  - **Consulta del reponedor (`getOwnVanStock`).** Alimenta la superficie móvil
 *    con el stock del propio reponedor, el semáforo sanitario y la alerta de
 *    lotes caducados. El llamador pasa el identificador autenticado; el servicio
 *    no acepta un reponedor distinto.
 *  - **Ajuste manual auditado (`adjustStock`).** Valida reponedor activo,
 *    producto, motivo de al menos 20 caracteres reales, lote y caducidad
 *    (`QA 2`), inmutabilidad de la fecha de un lote existente (`QA 3`) y cotas
 *    `0..99999`. Persiste el `UPSERT` y firma `RESTOCKER_VAN_STOCK_ADJUSTED` con
 *    saldo previo, saldo nuevo y motivo (`Art. III.3`).
 *  - **Cero coste (Art. V.4).** Las proyecciones se construyen campo a campo:
 *    aunque el repositorio añadiera una columna económica, este servicio no la
 *    publicaría. No hay `unit_cost_eur`, precio ni margen en ninguna respuesta.
 *
 * La validación dura vive en `ProductLoadingValidator`; este servicio compone,
 * resuelve el producto y el reponedor, y persiste. No duplica la regla térmica.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockerVanStockService
{
    public const AUDIT_ENTITY_TYPE = AuditEvent::ENTITY_RESTOCKER_VAN_STOCK;
    public const AUDIT_ACTION_ADJUSTED = 'RESTOCKER_VAN_STOCK_ADJUSTED';

    private AuditLogger $auditLogger;

    public function __construct(
        private readonly RestockerVanStockRepositoryInterface $stockRepository,
        private readonly VendingProductRepositoryInterface $productRepository,
        private readonly UserRepositoryInterface $userRepository,
        ?AuditLogger $auditLogger = null,
        private readonly ?PDO $pdo = null
    ) {
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Inventario de Coordinación con filtros y KPIs (RF-01.2, RF-05.2, D4).
     *
     * @return array{kpis: array<string, int>, stocks: list<array<string, mixed>>}
     */
    public function getCoordinatorInventory(
        ?int $restockerId = null,
        ?string $category = null,
        ?string $expiryStatus = null,
        ?string $search = null
    ): array {
        $rows = $this->stockRepository->search($restockerId, $category, $expiryStatus, $search);
        $stocks = array_map(fn (array $row): array => $this->projectStock($row), $rows);

        return [
            'kpis' => $this->summarize($stocks),
            'stocks' => $stocks,
        ];
    }

    /**
     * Stock FEFO del propio reponedor, sin coste y con alerta de caducados
     * (RF-01.2, RF-05.2, Art. II, Art. V.4).
     *
     * @return array{
     *   kpis: array<string, int>,
     *   stocks: list<array<string, mixed>>,
     *   has_expired_lots: bool
     * }
     */
    public function getOwnVanStock(int $restockerId): array
    {
        if ($restockerId <= 0) {
            throw new InvalidArgumentException('El reponedor autenticado debe ser un usuario existente.');
        }

        $rows = $this->stockRepository->findByRestocker($restockerId);
        $stocks = array_map(fn (array $row): array => $this->projectStock($row), $rows);
        $kpis = $this->summarize($stocks);

        return [
            'kpis' => $kpis,
            'stocks' => $stocks,
            'has_expired_lots' => $kpis['expired_lots'] > 0,
        ];
    }

    /**
     * Alta o ajuste manual de un lote desde Coordinación (EARS 01.3).
     *
     * @param array<string, mixed> $payload Claves `product_id`, `batch_code`,
     *        `expiration_date`, `quantity_available` y `reason`.
     * @param array{id?: int|null, role?: string, name?: string} $actor Coordinador autenticado.
     *
     * @return array{
     *   stock: array<string, mixed>,
     *   previous_quantity: int|null,
     *   reason: string,
     *   audit_event_id: int|null
     * }
     *
     * @throws ProductLoadingValidationException
     */
    public function adjustStock(int $restockerId, array $payload, array $actor): array
    {
        // La lectura bloqueante y el UPSERT deben vivir en la misma transacción:
        // de lo contrario el `FOR UPDATE` se libera al terminar el autocommit y
        // dos ajustes concurrentes podrían cambiar la cantidad de un lote con
        // fechas distintas sin que ninguno viera la colisión (QA 3). El servicio
        // respeta una transacción ya abierta por el llamador.
        $ownsTransaction = $this->pdo !== null && !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $this->performAdjustment($restockerId, $payload, $actor);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{id?: int|null, role?: string, name?: string} $actor
     *
     * @return array{
     *   stock: array<string, mixed>,
     *   previous_quantity: int|null,
     *   reason: string,
     *   audit_event_id: int|null
     * }
     */
    private function performAdjustment(int $restockerId, array $payload, array $actor): array
    {
        $restocker = $this->userRepository->findById($restockerId, false, false);
        if ($restocker === null) {
            throw ProductLoadingValidationException::invalidRestockerRecipient(
                $restockerId,
                'el usuario no existe.'
            );
        }

        if ($restocker->getRole() !== UserRole::RESTOCKER) {
            throw ProductLoadingValidationException::invalidRestockerRecipient(
                $restockerId,
                'su rol no es RESTOCKER.'
            );
        }

        if (!$restocker->isActive()) {
            throw ProductLoadingValidationException::invalidRestockerRecipient(
                $restockerId,
                'la cuenta está inactiva.'
            );
        }

        $productId = $this->readProductId($payload);
        $product = $this->productRepository->findById($productId);
        if ($product === null) {
            throw new InvalidArgumentException(sprintf(
                'El producto %d no existe en el catálogo maestro.',
                $productId
            ));
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Madrid'));
        $batch = ProductLoadingValidator::normalizeBatchCode(
            isset($payload['batch_code']) && is_string($payload['batch_code'])
                ? $payload['batch_code']
                : null,
            $product->requiresColdChain()
        );

        $existing = $this->stockRepository->findLot($restockerId, $productId, $batch, true);
        $normalized = ProductLoadingValidator::assertAdjustment($payload, $product, $existing, $today);

        // QA 3: si el lote ya existía, su fecha es inmutable. El validador cubre
        // el cambio entre dos fechas no nulas; aquí se cierra también la frontera
        // null ↔ fecha para que el `UPSERT` no ignore en silencio una alteración.
        if ($existing !== null) {
            $existingDate = $existing->getExpirationDate()?->format('Y-m-d');
            $givenDate = $normalized['expiration_date']?->format('Y-m-d');
            if ($existingDate !== $givenDate) {
                throw ProductLoadingValidationException::batchExpirationMismatch(
                    $normalized['batch_code'],
                    $existingDate ?? 'sin fecha',
                    $givenDate ?? 'sin fecha'
                );
            }
        }

        $stock = new RestockerVanProductStock(
            $existing?->getId(),
            $restockerId,
            $productId,
            $normalized['batch_code'],
            $normalized['expiration_date'],
            $normalized['quantity_available'],
            $product->requiresColdChain()
        );

        $persisted = $this->stockRepository->upsertLot($stock);

        $event = $this->auditLogger->logEvent(new AuditEvent(
            null,
            self::AUDIT_ENTITY_TYPE,
            (int)$persisted->getId(),
            self::AUDIT_ACTION_ADJUSTED,
            isset($actor['id']) ? (int)$actor['id'] : null,
            (string)($actor['role'] ?? 'COORDINATOR'),
            (string)($actor['name'] ?? 'Coordinación'),
            $existing === null ? null : [
                'batch_code' => $existing->getBatchCode(),
                'expiration_date' => $existing->getExpirationDate()?->format('Y-m-d'),
                'quantity_available' => $existing->getQuantityAvailable(),
            ],
            [
                'restocker_id' => $restockerId,
                'product_id' => $productId,
                'product_sku' => $product->getSku(),
                'batch_code' => $persisted->getBatchCode(),
                'expiration_date' => $persisted->getExpirationDate()?->format('Y-m-d'),
                'quantity_available' => $persisted->getQuantityAvailable(),
                'reason' => $normalized['reason'],
            ],
            [
                'restocker_id' => $restockerId,
                'restocker_name' => $restocker->getName(),
                'product_id' => $productId,
            ]
        ));

        return [
            'stock' => array_merge($persisted->toArray(), [
                'product_sku' => $product->getSku(),
                'product_name' => $product->getName(),
                'product_category' => $product->getCategory()->value,
                'product_category_label' => $product->getCategory()->label(),
                'product_unit_type' => $product->getUnitType()->value,
                'requires_cold_chain' => $product->requiresColdChain(),
                'max_shelf_life_days' => $product->getMaxShelfLifeDays(),
                'restocker_name' => $restocker->getName(),
                'restocker_operator_code' => $restocker->getOperatorCode(),
            ]),
            'previous_quantity' => $existing?->getQuantityAvailable(),
            'reason' => $normalized['reason'],
            'audit_event_id' => $event->getId(),
        ];
    }

    /**
     * Proyección explícita y sin campos económicos (Art. V.4).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function projectStock(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'restocker_id' => (int)$row['restocker_id'],
            'restocker_name' => (string)($row['restocker_name'] ?? ''),
            'restocker_operator_code' => $row['restocker_operator_code'] ?? null,
            'product_id' => (int)$row['product_id'],
            'product_sku' => (string)($row['product_sku'] ?? ''),
            'product_name' => (string)($row['product_name'] ?? ''),
            'product_category' => (string)($row['product_category'] ?? ''),
            'product_category_label' => (string)($row['product_category_label'] ?? ''),
            'product_unit_type' => (string)($row['product_unit_type'] ?? ''),
            'requires_cold_chain' => (bool)($row['requires_cold_chain'] ?? false),
            'max_shelf_life_days' => $row['max_shelf_life_days'] === null
                ? null
                : (int)$row['max_shelf_life_days'],
            'batch_code' => (string)$row['batch_code'],
            'expiration_date' => $row['expiration_date'] === null ? null : (string)$row['expiration_date'],
            'quantity_available' => (int)$row['quantity_available'],
            'expiry_status' => (string)$row['expiry_status'],
            'expiry_status_label' => (string)($row['expiry_status_label'] ?? ''),
            'expiry_status_icon' => (string)($row['expiry_status_icon'] ?? ''),
            'is_expired' => (bool)($row['is_expired'] ?? false),
            'is_usable' => (bool)($row['is_usable'] ?? false),
            'usable_quantity' => (int)($row['usable_quantity'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $stocks
     *
     * @return array<string, int>
     */
    private function summarize(array $stocks): array
    {
        $totalUnits = 0;
        $usableUnits = 0;
        $expiredLots = 0;
        $expiredUnits = 0;
        $expiringSoonLots = 0;
        $restockers = [];

        foreach ($stocks as $stock) {
            $quantity = (int)$stock['quantity_available'];
            $totalUnits += $quantity;
            $usableUnits += (int)$stock['usable_quantity'];
            $restockers[(int)$stock['restocker_id']] = true;

            if ($stock['expiry_status'] === 'EXPIRED') {
                $expiredLots++;
                $expiredUnits += $quantity;
            } elseif ($stock['expiry_status'] === 'EXPIRING_SOON') {
                $expiringSoonLots++;
            }
        }

        return [
            'total_lots' => count($stocks),
            'total_units' => $totalUnits,
            'usable_units' => $usableUnits,
            'expired_lots' => $expiredLots,
            'expired_units' => $expiredUnits,
            'expiring_soon_lots' => $expiringSoonLots,
            'affected_restockers_count' => count($restockers),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readProductId(array $payload): int
    {
        $raw = $payload['product_id'] ?? null;
        $productId = is_int($raw)
            ? $raw
            : (is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1 ? (int)trim($raw) : 0);

        if ($productId <= 0) {
            throw new InvalidArgumentException('El ajuste debe referenciar un producto existente.');
        }

        return $productId;
    }
}
