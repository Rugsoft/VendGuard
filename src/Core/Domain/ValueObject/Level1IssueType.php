<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Level1IssueType
 *
 * Motivo rápido con el que se pre-clasifica un aviso de Nivel 1 (Módulo 36,
 * columna `incidents.level1_issue_type`, RF-01.1, RF-01.2, D2, QA-AMB-01).
 *
 * Cuatro motivos leves, todos resolubles por el reponedor en su ruta. El
 * mapeo a categoría es determinista y no depende del cliente: la misma pareja
 * `level1_issue_type` → `category` se siembra en la base de datos (semillas
 * `RC-L1-*` / `SS-L1-*`) y se exige aquí antes de persistir. Esa pareja es la
 * que alimenta el 1-Tap de resolución y la taxonomía de causas raíz (Art. V.4:
 * ni la Sede ni el QR ven este dato).
 *
 * El motivo es rastro de origen: tras escalar el expediente a `L2_SAT` puede
 * conservarse aunque la categoría pase a térmica o eléctrica (RF-01.1).
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum Level1IssueType: string
{
    case STOCKOUT_EMPTY_SLOT = 'STOCKOUT_EMPTY_SLOT';
    case MISSING_CONSUMABLES = 'MISSING_CONSUMABLES';
    case EMPTY_CHANGE_TUBES = 'EMPTY_CHANGE_TUBES';
    case MINOR_SPIRAL_JAM = 'MINOR_SPIRAL_JAM';

    public function label(): string
    {
        return match ($this) {
            self::STOCKOUT_EMPTY_SLOT => 'Producto agotado / Carril vacío',
            self::MISSING_CONSUMABLES => 'Falta de consumibles',
            self::EMPTY_CHANGE_TUBES => 'Tubos de cambio vacíos',
            self::MINOR_SPIRAL_JAM => 'Atasco leve de producto en espiral',
        };
    }

    /**
     * Categoría canónica que el motivo impone en el alta (QA-AMB-01).
     */
    public function mappedCategory(): IncidentCategory
    {
        return match ($this) {
            self::STOCKOUT_EMPTY_SLOT, self::MINOR_SPIRAL_JAM => IncidentCategory::PRODUCT_JAM,
            self::EMPTY_CHANGE_TUBES => IncidentCategory::PAYMENT_SYSTEM,
            self::MISSING_CONSUMABLES => IncidentCategory::OTHER,
        };
    }

    /**
     * Causa raíz semilla que el 1-Tap preselecciona al resolver.
     */
    public function rootCauseCode(): string
    {
        return match ($this) {
            self::STOCKOUT_EMPTY_SLOT => 'RC-L1-STOCKOUT',
            self::MISSING_CONSUMABLES => 'RC-L1-CONSUMABLES',
            self::EMPTY_CHANGE_TUBES => 'RC-L1-EMPTY-TUBES',
            self::MINOR_SPIRAL_JAM => 'RC-L1-SPIRAL-RELEASE',
        };
    }

    /**
     * Solución estandarizada que acompaña a la causa semilla.
     */
    public function solutionCode(): string
    {
        return match ($this) {
            self::STOCKOUT_EMPTY_SLOT => 'SS-L1-RESTOCK-SLOT',
            self::MISSING_CONSUMABLES => 'SS-L1-REFILL-CUPS-SUGAR',
            self::EMPTY_CHANGE_TUBES => 'SS-L1-REFILL-TUBES',
            self::MINOR_SPIRAL_JAM => 'SS-L1-CLEAR-SPIRAL',
        };
    }

    /**
     * ¿La categoría declarada en el alta es la que el motivo impone? Un `false`
     * delata una pareja incoherente (`STOCKOUT_EMPTY_SLOT` con `PAYMENT_SYSTEM`,
     * por ejemplo) que la base también rechaza.
     */
    public function isCompatibleWith(IncidentCategory $category): bool
    {
        return $this->mappedCategory() === $category;
    }

    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $type = self::tryFrom(strtoupper(trim($value)));
        if ($type === null) {
            throw new InvalidArgumentException(sprintf(
                "Motivo de Nivel 1 no válido: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $type;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
