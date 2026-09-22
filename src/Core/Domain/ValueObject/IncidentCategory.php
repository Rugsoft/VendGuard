<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * IncidentCategory
 * 
 * Categorías funcionales de avería para máquinas de vending.
 */
enum IncidentCategory: string
{
    case TEMPERATURE_COLD = 'TEMPERATURE_COLD';
    case PAYMENT_SYSTEM = 'PAYMENT_SYSTEM';
    case PRODUCT_JAM = 'PRODUCT_JAM';
    case ELECTRICAL_OFF = 'ELECTRICAL_OFF';
    case OTHER = 'OTHER';

    /**
     * Devuelve la etiqueta descriptiva en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::TEMPERATURE_COLD => 'Temperatura / Pérdida de frío',
            self::PAYMENT_SYSTEM => 'Fallo en sistema de pago / Monedero / TPV',
            self::PRODUCT_JAM => 'Atasco de producto en espiral',
            self::ELECTRICAL_OFF => 'Máquina apagada / Sin corriente',
            self::OTHER => 'Otras averías o anomalías',
        };
    }

    /**
     * Comprueba si una cadena de texto corresponde a una categoría válida.
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
                "Categoría de incidencia no válida: '{$value}'. Valores permitidos: " . implode(', ', self::values())
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
