<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Exception\MachineOutsideRestockerZonesException;
use VendGuard\Core\Domain\Exception\PlanogramValidationException;
use VendGuard\Core\Domain\Exception\ProductNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Core\Domain\Repository\MachinePlanogramRepositoryInterface;
use VendGuard\Core\Domain\Repository\VendingProductRepositoryInterface;
use VendGuard\Core\Service\ModelPlanogramPresetCatalog;
use VendGuard\Core\Service\PlanogramCompatibilityValidator;

/**
 * MachinePlanogramService
 *
 * Caso de uso del planograma por máquina (Módulo 32, RF-02.2–RF-02.3,
 * RF-03.1, RF-04.1–RF-04.2, RF-05.2, D1–D3, C1–C3, QA 2, QA 3, Art. II,
 * Art. V.4).
 *
 * Reglas que concentra:
 *   1. **Guardado sin `DELETE` (C1).** El mapa completo y el carril individual
 *      pasan por `UPSERT`; los carriles ausentes se desactivan en el repositorio.
 *   2. **Cerrojo directo (RF-03.1, D3).** Cada carril activo se valida contra la
 *      tipología de la máquina antes de persistirse (422 de frío o categoría);
 *      un producto inactivo sólo puede conservarse en un carril preexistente.
 *   3. **Presets (D1).** La plantilla del modelo o de la tipología se empareja
 *      con referencias activas por categoría, unidad y etiqueta; el stock inicial
 *      es `ZERO` o `FULL`, y se preserva la existencia de un carril que mantiene
 *      el mismo producto.
 *   4. **Clonación (RF-04.2, QA 3, C3).** Se omiten productos dados de baja, se
 *      aborta con 422 `SOURCE_PLANOGRAM_EMPTY` si no queda ninguno clonable, se
 *      valida cada carril contra la máquina destino y jamás se copia la
 *      existencia de origen: se preserva la del destino o se aplica el modo.
 *   5. **Segregación de costes (D2, Art. V.4).** La lectura de Coordinación
 *      enriquece cada carril con `unit_cost_eur` y margen; la del reponedor no
 *      los incluye ni en la ficha ni en los carriles.
 *   6. **Perímetro del reponedor (QA 2).** Una máquina fuera de sus zonas vivas
 *      lanza 403 `MACHINE_OUTSIDE_RESTOCKER_ZONES`.
 *   7. **Auditoría (C3).** Guardados, bajas, presets y clonaciones se firman bajo
 *      `MACHINE_PLANOGRAM` con antes/después y metadatos.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, mensajes y documentación en castellano.
 */
final class MachinePlanogramService
{
    private const STOCK_ZERO = 'ZERO';
    private const STOCK_FULL = 'FULL';

    /** Palabras de etiqueta que no identifican una referencia del catálogo. */
    private const LABEL_STOPWORDS = [
        'carton', 'madera', 'blanco', 'fresco', 'fresca', 'envasada', 'soluble',
        'grano', 'polvo', 'salado', 'energetica', 'fria', 'frio', 'mineral',
        'para', 'con', 'del', 'las', 'los', 'una', 'uno',
    ];

    private MachinePlanogramRepositoryInterface $planogramRepository;

    private VendingProductRepositoryInterface $productRepository;

    private ?AuditLogger $auditLogger;

    public function __construct(
        MachinePlanogramRepositoryInterface $planogramRepository,
        VendingProductRepositoryInterface $productRepository,
        ?AuditLogger $auditLogger = null
    ) {
        $this->planogramRepository = $planogramRepository;
        $this->productRepository = $productRepository;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Lectura completa para Coordinación, con coste y margen por carril (D2).
     *
     * @return array{machine: array<string, mixed>, summary: array<string, mixed>, slots: list<array<string, mixed>>}
     *
     * @throws MachineNotFoundException
     */
    public function getCoordinatorPlanogram(int $machineId): array
    {
        $machine = $this->requireMachine($machineId);
        $slots = $this->enrichWithCommercialData(
            $this->planogramRepository->findSlotsByMachine($machineId, true)
        );

        return [
            'machine' => $machine,
            'summary' => $this->planogramRepository->summarize($machineId),
            'slots' => $slots,
        ];
    }

    /**
     * Reemplaza el mapa completo de carriles (PUT /planogram).
     *
     * @param list<array<string, mixed>> $slotsPayload
     * @param array{id: int|null, role: string, name: string}|null $actor
     *
     * @return array{machine: array<string, mixed>, summary: array<string, mixed>, slots: list<array<string, mixed>>}
     */
    public function saveMachinePlanogram(int $machineId, array $slotsPayload, ?array $actor = null): array
    {
        $machine = $this->requireMachine($machineId);
        $machineType = $machine['machine_type'];
        if (!$machineType instanceof MachineType) {
            throw new InvalidArgumentException('La máquina no declara una tipología válida.');
        }

        $existing = [];
        foreach ($this->planogramRepository->findSlotsByMachine($machineId, false) as $row) {
            $existing[$row['slot_code']] = $row;
        }

        $entities = [];
        $seenCodes = [];
        foreach ($slotsPayload as $slotData) {
            if (!is_array($slotData)) {
                throw new InvalidArgumentException('Cada carril del mapa debe ser un objeto.');
            }

            $slotCode = strtoupper(trim((string)($slotData['slot_code'] ?? '')));
            if (isset($seenCodes[$slotCode])) {
                throw PlanogramValidationException::invalidSlotBounds('slot_code', "el carril «{$slotCode}» está duplicado en el mapa.");
            }
            $seenCodes[$slotCode] = true;

            $productId = $this->intField($slotData, 'product_id');
            $product = $this->productRepository->findById($productId);
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }

            $isActive = array_key_exists('is_active', $slotData)
                ? $this->boolField($slotData['is_active'], 'is_active')
                : true;

            if ($isActive) {
                $previous = $existing[$slotCode] ?? null;
                $isNewAssignment = $previous === null
                    || !$previous['is_active']
                    || $previous['product_id'] !== $productId;
                PlanogramCompatibilityValidator::assertSlotAssignment($machineType, $product, $isNewAssignment);
            }

            $entities[] = new MachinePlanogramSlot(
                $existing[$slotCode]['id'] ?? null,
                $machineId,
                $slotCode,
                $productId,
                $this->intField($slotData, 'max_capacity'),
                $this->intField($slotData, 'current_estimated_qty', 0),
                $this->intField($slotData, 'min_threshold', 2),
                $isActive
            );
        }

        $this->planogramRepository->replaceMachinePlanogram($machineId, $entities);

        $this->audit(
            $machineId,
            'MACHINE_PLANOGRAM_SAVED',
            $actor,
            ['slots' => array_values($existing)],
            ['slots' => array_map(static fn (MachinePlanogramSlot $slot): array => $slot->toArray(), $entities)],
            ['slots_count' => count($entities)]
        );

        return $this->getCoordinatorPlanogram($machineId);
    }

    /**
     * Crea o actualiza un carril individual (PUT /planogram/slots/{slot_code}).
     *
     * @param array<string, mixed> $data
     * @param array{id: int|null, role: string, name: string}|null $actor
     *
     * @return array<string, mixed> Carril persistido con coste y margen
     */
    public function saveSlot(int $machineId, string $slotCode, array $data, ?array $actor = null): array
    {
        $machine = $this->requireMachine($machineId);
        $machineType = $machine['machine_type'];
        if (!$machineType instanceof MachineType) {
            throw new InvalidArgumentException('La máquina no declara una tipología válida.');
        }

        $normalizedCode = strtoupper(trim($slotCode));
        $previous = $this->planogramRepository->findSlot($machineId, $normalizedCode);

        $productId = $this->intField($data, 'product_id', $previous['product_id'] ?? null);
        $product = $this->productRepository->findById($productId);
        if ($product === null) {
            throw ProductNotFoundException::forId($productId);
        }

        $isActive = array_key_exists('is_active', $data)
            ? $this->boolField($data['is_active'], 'is_active')
            : true;

        if ($isActive) {
            $isNewAssignment = $previous === null
                || !$previous['is_active']
                || $previous['product_id'] !== $productId;
            PlanogramCompatibilityValidator::assertSlotAssignment($machineType, $product, $isNewAssignment);
        }

        $slot = new MachinePlanogramSlot(
            $previous['id'] ?? null,
            $machineId,
            $normalizedCode,
            $productId,
            $this->intField($data, 'max_capacity', $previous['max_capacity'] ?? null),
            $this->intField($data, 'current_estimated_qty', $previous['current_estimated_qty'] ?? 0),
            $this->intField($data, 'min_threshold', $previous['min_threshold'] ?? 2),
            $isActive
        );

        $persisted = $this->planogramRepository->upsertSlot($slot);
        $persisted = $this->enrichSlotWithCommercialData($persisted);

        $this->audit(
            $machineId,
            'MACHINE_PLANOGRAM_SLOT_SAVED',
            $actor,
            $previous,
            $persisted,
            ['slot_code' => $normalizedCode]
        );

        return $persisted;
    }

    /**
     * Baja lógica idempotente de un carril (DELETE lógico del contrato).
     *
     * @param array{id: int|null, role: string, name: string}|null $actor
     */
    public function deactivateSlot(int $machineId, string $slotCode, ?array $actor = null): bool
    {
        $this->requireMachine($machineId);
        $normalizedCode = strtoupper(trim($slotCode));
        $previous = $this->planogramRepository->findSlot($machineId, $normalizedCode);

        if ($previous === null) {
            return false;
        }

        $deactivated = $this->planogramRepository->deactivateSlot($machineId, $normalizedCode);

        if ($deactivated) {
            $this->audit(
                $machineId,
                'MACHINE_PLANOGRAM_SLOT_DEACTIVATED',
                $actor,
                $previous,
                ['slot_code' => $normalizedCode, 'is_active' => false],
                ['slot_code' => $normalizedCode]
            );
        }

        return $deactivated;
    }

    /**
     * Aplica la plantilla del modelo o, en su defecto, de la tipología (D1).
     *
     * @param array{id: int|null, role: string, name: string}|null $actor
     *
     * @return array<string, mixed> Planograma resultante más metadatos del preset
     */
    public function applyPreset(int $machineId, string $initialStockMode = self::STOCK_ZERO, ?array $actor = null): array
    {
        $machine = $this->requireMachine($machineId);
        $machineType = $machine['machine_type'];
        if (!$machineType instanceof MachineType) {
            throw new InvalidArgumentException('La máquina no declara una tipología válida.');
        }

        $stockMode = $this->normalizeStockMode($initialStockMode);
        $preset = ModelPlanogramPresetCatalog::forMachine((string)$machine['model'], $machineType);
        $activeProducts = $this->productRepository->findAll(null, null, true);

        $matched = $this->matchPresetProducts($preset['slots'], $activeProducts);
        if ($matched === []) {
            throw new InvalidArgumentException('El catálogo activo no ofrece referencias compatibles con la plantilla del modelo.');
        }

        $existing = [];
        foreach ($this->planogramRepository->findSlotsByMachine($machineId, true) as $row) {
            $existing[$row['slot_code']] = $row;
        }

        $entities = [];
        foreach ($matched as $pair) {
            /** @var array<string, mixed> $presetSlot */
            $presetSlot = $pair['slot'];
            /** @var VendingProduct $product */
            $product = $pair['product'];
            $code = (string)$presetSlot['slot_code'];
            $capacity = (int)$presetSlot['max_capacity'];
            $previous = $existing[$code] ?? null;

            if ($previous !== null && $previous['product_id'] === (int)$product->getId()) {
                $quantity = min((int)$previous['current_estimated_qty'], $capacity);
            } else {
                $quantity = $stockMode === self::STOCK_FULL ? $capacity : 0;
            }

            $entities[] = new MachinePlanogramSlot(
                $previous['id'] ?? null,
                $machineId,
                $code,
                (int)$product->getId(),
                $capacity,
                $quantity,
                (int)$presetSlot['min_threshold'],
                true
            );
        }

        $this->planogramRepository->replaceMachinePlanogram($machineId, $entities);

        $this->audit(
            $machineId,
            'PLANOGRAM_PRESET_APPLIED',
            $actor,
            ['slots' => array_values($existing)],
            ['slots' => array_map(static fn (MachinePlanogramSlot $slot): array => $slot->toArray(), $entities)],
            [
                'preset_source' => $preset['source'],
                'preset_model' => $preset['model'],
                'initial_stock_mode' => $stockMode,
                'slots_count' => count($entities),
            ]
        );

        $planogram = $this->getCoordinatorPlanogram($machineId);
        $planogram['preset'] = [
            'source' => $preset['source'],
            'model' => $preset['model'],
            'initial_stock_mode' => $stockMode,
            'applied_slots_count' => count($entities),
        ];

        return $planogram;
    }

    /**
     * Clona el planograma activo de otra máquina (RF-04.2, QA 3, C3).
     *
     * @param array{id: int|null, role: string, name: string}|null $actor
     *
     * @return array<string, mixed> Planograma destino, origen y carriles omitidos
     */
    public function clonePlanogram(
        int $targetMachineId,
        int $sourceMachineId,
        string $initialStockMode = self::STOCK_ZERO,
        ?array $actor = null
    ): array {
        if ($targetMachineId === $sourceMachineId) {
            throw new InvalidArgumentException('La máquina destino y la máquina origen deben ser distintas.');
        }

        $targetMachine = $this->requireMachine($targetMachineId);
        $sourceMachine = $this->requireMachine($sourceMachineId);
        $targetType = $targetMachine['machine_type'];
        if (!$targetType instanceof MachineType) {
            throw new InvalidArgumentException('La máquina destino no declara una tipología válida.');
        }

        $stockMode = $this->normalizeStockMode($initialStockMode);
        $sourceSlots = $this->planogramRepository->findSlotsByMachine($sourceMachineId, true);

        $skipped = [];
        $clonable = [];
        foreach ($sourceSlots as $sourceSlot) {
            $product = $this->productRepository->findById((int)$sourceSlot['product_id']);
            if ($product === null || !$product->isActive() || ($sourceSlot['product_is_active'] ?? true) === false) {
                $skipped[] = [
                    'slot_code' => (string)$sourceSlot['slot_code'],
                    'product_sku' => (string)$sourceSlot['product_sku'],
                    'product_name' => (string)$sourceSlot['product_name'],
                ];
                continue;
            }

            PlanogramCompatibilityValidator::assertSlotAssignment($targetType, $product, true);
            $clonable[] = ['slot' => $sourceSlot, 'product' => $product];
        }

        if ($clonable === []) {
            throw PlanogramValidationException::sourcePlanogramEmpty($sourceMachineId);
        }

        $existingTarget = [];
        foreach ($this->planogramRepository->findSlotsByMachine($targetMachineId, true) as $row) {
            $existingTarget[$row['slot_code']] = $row;
        }

        $entities = [];
        foreach ($clonable as $pair) {
            $sourceSlot = $pair['slot'];
            $product = $pair['product'];
            $code = (string)$sourceSlot['slot_code'];
            $capacity = (int)$sourceSlot['max_capacity'];
            $previous = $existingTarget[$code] ?? null;

            if ($previous !== null && $previous['product_id'] === (int)$product->getId()) {
                $quantity = min((int)$previous['current_estimated_qty'], $capacity);
            } else {
                $quantity = $stockMode === self::STOCK_FULL ? $capacity : 0;
            }

            $entities[] = new MachinePlanogramSlot(
                $previous['id'] ?? null,
                $targetMachineId,
                $code,
                (int)$product->getId(),
                $capacity,
                $quantity,
                (int)$sourceSlot['min_threshold'],
                true
            );
        }

        $this->planogramRepository->replaceMachinePlanogram($targetMachineId, $entities);

        $this->audit(
            $targetMachineId,
            'PLANOGRAM_CLONED',
            $actor,
            ['slots' => array_values($existingTarget)],
            ['slots' => array_map(static fn (MachinePlanogramSlot $slot): array => $slot->toArray(), $entities)],
            [
                'source_machine_id' => $sourceMachineId,
                'source_machine_code' => $sourceMachine['code'],
                'initial_stock_mode' => $stockMode,
                'skipped_inactive_slots' => $skipped,
            ]
        );

        $planogram = $this->getCoordinatorPlanogram($targetMachineId);
        $planogram['source_machine'] = [
            'id' => (int)$sourceMachine['id'],
            'code' => (string)$sourceMachine['code'],
        ];
        $planogram['skipped_inactive_slots'] = $skipped;
        $planogram['initial_stock_mode'] = $stockMode;

        return $planogram;
    }

    /**
     * Máquinas activas de las zonas vivas del reponedor (QA 2).
     *
     * @param array{zone_id?: mixed, stock_alert_only?: mixed} $filters
     *
     * @return list<array<string, mixed>>
     */
    public function listRestockerMachines(int $restockerId, array $filters = []): array
    {
        if ($restockerId <= 0) {
            return [];
        }

        $machines = $this->planogramRepository->findMachinesForRestocker($restockerId);

        $zoneId = null;
        if (isset($filters['zone_id']) && trim((string)$filters['zone_id']) !== '') {
            if (!is_numeric($filters['zone_id']) || (int)$filters['zone_id'] <= 0) {
                throw new InvalidArgumentException('El filtro zone_id debe ser un identificador positivo.');
            }
            $zoneId = (int)$filters['zone_id'];
        }

        $stockAlertOnly = isset($filters['stock_alert_only'])
            && $this->boolField($filters['stock_alert_only'], 'stock_alert_only');

        return array_values(array_filter($machines, static function (array $machine) use ($zoneId, $stockAlertOnly): bool {
            if ($zoneId !== null && (int)$machine['zone_id'] !== $zoneId) {
                return false;
            }
            if ($stockAlertOnly
                && ((int)$machine['stockout_slots_count'] + (int)$machine['low_stock_slots_count']) === 0) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Planograma de solo lectura para el reponedor, sin coste ni margen.
     *
     * @return array<string, mixed>
     *
     * @throws MachineOutsideRestockerZonesException 403
     */
    public function getRestockerPlanogram(int $restockerId, int $machineId): array
    {
        $machine = $this->planogramRepository->findMachineForRestocker($machineId, $restockerId);
        if ($machine === null) {
            throw new MachineOutsideRestockerZonesException($machineId, $restockerId);
        }

        $slots = array_map([$this, 'stripCommercialData'], $this->planogramRepository->findSlotsByMachine($machineId, true));

        return [
            'machine' => $machine,
            'slots' => $slots,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireMachine(int $machineId): array
    {
        $machine = $this->planogramRepository->findMachineById($machineId);

        if ($machine === null) {
            throw new MachineNotFoundException(
                'MACHINE_NOT_FOUND',
                "No existe la máquina {$machineId} en el parque."
            );
        }

        return $machine;
    }

    /**
     * @param list<array<string, mixed>> $slots
     *
     * @return list<array<string, mixed>>
     */
    private function enrichWithCommercialData(array $slots): array
    {
        return array_map(fn (array $slot): array => $this->enrichSlotWithCommercialData($slot), $slots);
    }

    /**
     * Añade coste y margen al carril de Coordinación (D2). La lectura del
     * reponedor nunca pasa por este método.
     *
     * @param array<string, mixed> $slot
     *
     * @return array<string, mixed>
     */
    private function enrichSlotWithCommercialData(array $slot): array
    {
        $product = $this->productRepository->findById((int)$slot['product_id']);
        if ($product !== null) {
            $slot['unit_cost_eur'] = $product->getUnitCostEur();
            $slot['unit_margin_eur'] = $product->getUnitMarginEur();
            $slot['sale_price_eur'] = $product->getSalePriceEur();
        }

        return $slot;
    }

    /**
     * @param array<string, mixed> $slot
     *
     * @return array<string, mixed>
     */
    private function stripCommercialData(array $slot): array
    {
        unset($slot['unit_cost_eur'], $slot['unit_margin_eur'], $slot['margin_eur']);

        return $slot;
    }

    /**
     * Empareja cada carril de la plantilla con una referencia activa.
     *
     * Prioriza la coincidencia de etiqueta (café, leche, vasos…) y, si no la
     * hay, la primera referencia libre de la misma categoría y unidad. Nunca
     * reutiliza una referencia mientras queden libres de su categoría.
     *
     * @param list<array<string, mixed>> $presetSlots
     * @param list<VendingProduct> $activeProducts
     *
     * @return list<array{slot: array<string, mixed>, product: VendingProduct}>
     */
    private function matchPresetProducts(array $presetSlots, array $activeProducts): array
    {
        $used = [];
        $matched = [];

        foreach ($presetSlots as $presetSlot) {
            $category = $presetSlot['product_category'];
            $unitType = $presetSlot['unit_type'];
            if (!$category instanceof ProductCategory || !$unitType instanceof ProductUnitType) {
                continue;
            }

            $candidates = array_values(array_filter(
                $activeProducts,
                static fn (VendingProduct $product): bool => $product->getCategory() === $category
                    && $product->getUnitType() === $unitType
                    && $product->isActive()
                    && !isset($used[(int)$product->getId()])
            ));

            if ($candidates === []) {
                $candidates = array_values(array_filter(
                    $activeProducts,
                    static fn (VendingProduct $product): bool => $product->getCategory() === $category
                        && $product->isActive()
                        && !isset($used[(int)$product->getId()])
                ));
            }

            if ($candidates === []) {
                continue;
            }

            $selected = $this->selectByLabel((string)($presetSlot['label'] ?? ''), $candidates) ?? $candidates[0];
            $used[(int)$selected->getId()] = true;
            $matched[] = ['slot' => $presetSlot, 'product' => $selected];
        }

        return $matched;
    }

    /**
     * @param list<VendingProduct> $candidates
     */
    private function selectByLabel(string $label, array $candidates): ?VendingProduct
    {
        $keywords = $this->labelKeywords($label);
        if ($keywords === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $name = $this->normalizeText($candidate->getName());
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function labelKeywords(string $label): array
    {
        $normalized = $this->normalizeText($label);
        $words = preg_split('/[^a-z0-9]+/', $normalized) ?: [];
        $keywords = [];
        foreach ($words as $word) {
            if (mb_strlen($word, 'UTF-8') >= 4 && !in_array($word, self::LABEL_STOPWORDS, true)) {
                $keywords[] = $word;
            }
        }

        return $keywords;
    }

    private function normalizeText(string $value): string
    {
        $ascii = strtr(mb_strtolower($value, 'UTF-8'), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i',
            'ò' => 'o', 'ù' => 'u', 'ç' => 'c',
        ]);

        return preg_replace('/\s+/u', ' ', $ascii) ?? $ascii;
    }

    private function normalizeStockMode(string $mode): string
    {
        $normalized = strtoupper(trim($mode));
        if (!in_array($normalized, [self::STOCK_ZERO, self::STOCK_FULL], true)) {
            throw new InvalidArgumentException("El modo de stock inicial '{$mode}' no es válido; use ZERO o FULL.");
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function intField(array $data, string $field, ?int $default = null): int
    {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            if ($default !== null) {
                return $default;
            }

            throw PlanogramValidationException::invalidSlotBounds($field, 'el campo es obligatorio.');
        }

        if (!is_numeric($data[$field]) || (float)$data[$field] !== (float)(int)$data[$field]) {
            throw PlanogramValidationException::invalidSlotBounds($field, 'el campo debe ser un entero.');
        }

        return (int)$data[$field];
    }

    private function boolField(mixed $raw, string $field): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new InvalidArgumentException("El campo {$field} debe ser booleano.");
        }

        return $value;
    }

    /**
     * @param array{id: int|null, role: string, name: string}|null $actor
     * @param array<string, mixed>|null $previousState
     * @param array<string, mixed> $newState
     * @param array<string, mixed>|null $metadata
     */
    private function audit(
        int $machineId,
        string $action,
        ?array $actor,
        ?array $previousState,
        array $newState,
        ?array $metadata = null
    ): void {
        if ($this->auditLogger === null) {
            return;
        }

        $this->auditLogger->logEvent(new AuditEvent(
            id: null,
            entityType: AuditEvent::ENTITY_MACHINE_PLANOGRAM,
            entityId: $machineId,
            action: $action,
            userId: $actor['id'] ?? null,
            userRole: $actor['role'] ?? 'COORDINATOR',
            userName: $actor['name'] ?? 'Coordinación',
            previousState: $previousState,
            newState: $newState,
            metadata: $metadata
        ));
    }
}
