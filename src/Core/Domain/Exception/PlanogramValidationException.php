<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * PlanogramValidationException
 *
 * Única excepción de negocio del catálogo y del planograma (Módulo 32). Transporta
 * el código exacto del contrato REST y el estado HTTP con el que el controlador
 * debe responder, de modo que la capa de presentación no reinvente la traducción
 * de cada regla.
 *
 * Los constructores son semánticos a propósito: el código de error no se escribe
 * a mano en el servicio, se elige llamando al constructor que describe el fallo.
 *
 * Códigos por estado HTTP:
 *   - `409`: `SKU_CHANGE_FORBIDDEN`, `COLD_CHAIN_MACHINE_INCOMPATIBLE` (edición de
 *     producto), `PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE` (edición de producto) y
 *     `PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE` (edición de máquina).
 *   - `422`: cotas y coherencia del producto, cadena de frío en el carril,
 *     producto dado de baja y cotas del carril.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class PlanogramValidationException extends DomainException
{
    // ── 409 ────────────────────────────────────────────────────────────────
    public const SKU_CHANGE_FORBIDDEN = 'SKU_CHANGE_FORBIDDEN';
    public const COLD_CHAIN_MACHINE_INCOMPATIBLE = 'COLD_CHAIN_MACHINE_INCOMPATIBLE';
    public const PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE = 'PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE';
    public const PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE = 'PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE';

    // ── 422 · producto ─────────────────────────────────────────────────────
    public const INVALID_PRODUCT_SKU = 'INVALID_PRODUCT_SKU';
    public const INVALID_PRODUCT_NAME = 'INVALID_PRODUCT_NAME';
    public const INVALID_UNIT_COST = 'INVALID_UNIT_COST';
    public const INVALID_SALE_PRICE = 'INVALID_SALE_PRICE';
    public const INVALID_UNIT_TYPE = 'INVALID_UNIT_TYPE';
    public const PERISHABLE_REQUIRES_COLD_CHAIN_AND_SHELF_LIFE = 'PERISHABLE_REQUIRES_COLD_CHAIN_AND_SHELF_LIFE';
    public const INVALID_COLD_CHAIN_CATEGORY = 'INVALID_COLD_CHAIN_CATEGORY';
    public const INVALID_SHELF_LIFE = 'INVALID_SHELF_LIFE';
    public const PRODUCT_INACTIVE = 'PRODUCT_INACTIVE';

    // ── 422 · carril ───────────────────────────────────────────────────────
    public const INVALID_PLANOGRAM_SLOT_BOUNDS = 'INVALID_PLANOGRAM_SLOT_BOUNDS';

    private string $errorCode;

    private int $httpStatusCode;

    private ?string $field;

    /** @var array<string, mixed> Datos de contexto útiles para el log, nunca secretos. */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $errorCode,
        string $message,
        int $httpStatusCode,
        ?string $field = null,
        array $context = []
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
        $this->field = $field;
        $this->context = $context;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 409 · identidad y cerrojo inverso
    // ─────────────────────────────────────────────────────────────────────
    public static function skuChangeForbidden(string $currentSku, string $newSku): self
    {
        return new self(
            self::SKU_CHANGE_FORBIDDEN,
            sprintf(
                'El SKU «%s» es inmutable tras el alta; no se puede sustituir por «%s» (C1).',
                $currentSku,
                $newSku
            ),
            409,
            'sku',
            ['current_sku' => $currentSku, 'new_sku' => $newSku]
        );
    }

    public static function coldChainMachineIncompatible(
        string $machineType,
        string $productReference,
        int $httpStatus = 409
    ): self {
        return new self(
            self::COLD_CHAIN_MACHINE_INCOMPATIBLE,
            sprintf(
                'El producto %s requiere cadena de frío y la máquina de tipo %s no es PERISHABLE_FOOD ni COMBO (Art. II, D3).',
                $productReference,
                $machineType
            ),
            $httpStatus,
            'requires_cold_chain',
            ['machine_type' => $machineType, 'product' => $productReference]
        );
    }

    public static function productCategoryMachineIncompatible(
        string $machineType,
        string $productCategory,
        string $productReference,
        int $httpStatus = 409
    ): self {
        return new self(
            self::PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE,
            sprintf(
                'La categoría %s del producto %s no es compatible con la máquina de tipo %s (D3).',
                $productCategory,
                $productReference,
                $machineType
            ),
            $httpStatus,
            'category',
            ['machine_type' => $machineType, 'category' => $productCategory, 'product' => $productReference]
        );
    }

    /**
     * @param list<array{slot_code: string, sku: string, reason: string}> $conflicts
     */
    public static function planogramIncompatibleWithMachineType(string $machineType, array $conflicts): self
    {
        $references = array_map(
            static fn (array $conflict): string => $conflict['slot_code'] . ' (' . $conflict['sku'] . ')',
            $conflicts
        );

        return new self(
            self::PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE,
            sprintf(
                'La máquina no puede pasar al tipo %s: los carriles activos %s quedarían incompatibles (D3, QA 4).',
                $machineType,
                implode(', ', $references)
            ),
            409,
            'machine_type',
            ['machine_type' => $machineType, 'conflicts' => $conflicts]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · producto
    // ─────────────────────────────────────────────────────────────────────
    public static function invalidProductSku(string $sku): self
    {
        return new self(
            self::INVALID_PRODUCT_SKU,
            sprintf(
                'El SKU «%s» debe cumplir el formato ^[A-Z0-9_-]{3,32}$ y se normaliza en mayúsculas (C1, QA 1).',
                $sku
            ),
            422,
            'sku',
            ['sku' => $sku]
        );
    }

    public static function invalidProductName(int $length): self
    {
        return new self(
            self::INVALID_PRODUCT_NAME,
            sprintf('El nombre del producto debe contener entre 2 y 120 caracteres reales; se recibieron %d.', $length),
            422,
            'name',
            ['length' => $length]
        );
    }

    public static function invalidUnitCost(float $value): self
    {
        return new self(
            self::INVALID_UNIT_COST,
            sprintf('El coste unitario debe estar en (0,00 €, 9 999,99 €]; se recibió %.2f €.', $value),
            422,
            'unit_cost_eur',
            ['value' => $value]
        );
    }

    public static function invalidSalePrice(float $value, string $category): self
    {
        return new self(
            self::INVALID_SALE_PRICE,
            sprintf(
                'El PVP de la categoría %s debe ser mayor que 0,00 € y no superar 9 999,99 €; se recibió %.2f € (C2).',
                $category,
                $value
            ),
            422,
            'sale_price_eur',
            ['value' => $value, 'category' => $category]
        );
    }

    public static function invalidUnitType(string $category, string $unitType): self
    {
        return new self(
            self::INVALID_UNIT_TYPE,
            sprintf(
                'La unidad %s no es válida para la categoría %s; consulte las unidades admitidas del contrato (C2, QA 1).',
                $unitType,
                $category
            ),
            422,
            'unit_type',
            ['category' => $category, 'unit_type' => $unitType]
        );
    }

    public static function perishableRequiresColdChainAndShelfLife(string $sku, string $reason): self
    {
        return new self(
            self::PERISHABLE_REQUIRES_COLD_CHAIN_AND_SHELF_LIFE,
            sprintf('El producto %s no cumple la cadena de frío: %s (D3, QA 1).', $sku, $reason),
            422,
            'requires_cold_chain',
            ['sku' => $sku, 'reason' => $reason]
        );
    }

    public static function invalidColdChainCategory(string $category): self
    {
        return new self(
            self::INVALID_COLD_CHAIN_CATEGORY,
            sprintf(
                'La categoría %s no admite requires_cold_chain = TRUE; sólo PERISHABLE_FOOD, COLD_DRINK y SNACK (D3).',
                $category
            ),
            422,
            'requires_cold_chain',
            ['category' => $category]
        );
    }

    public static function invalidShelfLife(mixed $value): self
    {
        return new self(
            self::INVALID_SHELF_LIFE,
            sprintf('La vida útil debe ser nula o un entero entre 1 y 3650 días; se recibió %s (QA 1).', json_encode($value)),
            422,
            'max_shelf_life_days',
            ['value' => $value]
        );
    }

    public static function productInactive(string $sku): self
    {
        return new self(
            self::PRODUCT_INACTIVE,
            sprintf('El producto %s está dado de baja y no puede asignarse a un carril nuevo ni reactivarse en él (C1, RF-02.2).', $sku),
            422,
            'product_id',
            ['sku' => $sku]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · carril
    // ─────────────────────────────────────────────────────────────────────
    public static function invalidSlotBounds(string $field, string $reason): self
    {
        return new self(
            self::INVALID_PLANOGRAM_SLOT_BOUNDS,
            'Las cotas del carril no cumplen el contrato: ' . $reason,
            422,
            $field
        );
    }
}
