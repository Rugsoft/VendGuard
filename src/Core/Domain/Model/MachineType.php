<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;

/**
 * MachineType
 * 
 * Tipos de máquinas dispensadoras del parque de vending.
 * El tipo PERISHABLE_FOOD tiene consideración especial de seguridad alimentaria
 * bajo la Constitución del proyecto (riesgo de proliferación bacteriana).
 */
enum MachineType: string
{
    case HOT_DRINKS = 'HOT_DRINKS';
    case COLD_DRINKS = 'COLD_DRINKS';
    case SNACKS = 'SNACKS';
    case PERISHABLE_FOOD = 'PERISHABLE_FOOD';
    case COMBO = 'COMBO';

    /**
     * Devuelve la etiqueta descriptiva en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::HOT_DRINKS => 'Bebidas calientes (Café e infusiones)',
            self::COLD_DRINKS => 'Bebidas frías (Refrescos y aguas)',
            self::SNACKS => 'Snacks y frutos secos',
            self::PERISHABLE_FOOD => 'Alimentos perecederos (Sándwiches y lácteos frescos)',
            self::COMBO => 'Mixta / Combinada',
        };
    }

    /**
     * Determina si la máquina dispensa alimentos perecederos con riesgo sanitario.
     */
    public function isPerishable(): bool
    {
        return $this === self::PERISHABLE_FOOD;
    }

    /**
     * Determina si una avería de frío o apagado eléctrico supone riesgo alimentario crítico.
     */
    public function hasColdChainRisk(): bool
    {
        return $this === self::PERISHABLE_FOOD;
    }

    /**
     * Determina si la máquina incorpora unidad de refrigeración activa.
     */
    public function requiresRefrigeration(): bool
    {
        return $this === self::PERISHABLE_FOOD 
            || $this === self::COLD_DRINKS 
            || $this === self::COMBO;
    }

    /**
     * Comprueba si una cadena de texto corresponde a un tipo de máquina válido.
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    /**
     * Construye una instancia a partir de una cadena o lanza excepción.
     *
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        $instance = self::tryFrom($normalized);

        if ($instance === null) {
            throw new InvalidArgumentException(
                "Tipo de máquina no válido: '{$value}'. Valores permitidos: " . implode(', ', self::values())
            );
        }

        return $instance;
    }

    /**
     * Lista todos los valores escalares disponibles.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
