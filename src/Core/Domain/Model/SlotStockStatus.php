<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;

/**
 * SlotStockStatus
 *
 * Semáforo determinista de existencias de un carril de planograma (Módulo 32,
 * RF-02.3, C2): agotado, por debajo o igual al umbral, o por encima del umbral.
 * El cálculo no depende del reloj ni de la base: entra la existencia estimada y
 * el umbral, y sale el mismo estado en cualquier motor.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum SlotStockStatus: string
{
    case STOCKOUT = 'STOCKOUT';
    case LOW_STOCK = 'LOW_STOCK';
    case OK = 'OK';

    public function label(): string
    {
        return match ($this) {
            self::STOCKOUT => 'Agotado',
            self::LOW_STOCK => 'Bajo umbral',
            self::OK => 'Correcto',
        };
    }

    /**
     * Estado del carril a partir de su existencia estimada y su umbral (RF-02.3).
     *
     * @throws InvalidArgumentException si alguna cifra es negativa
     */
    public static function fromQuantities(int $currentEstimatedQty, int $minThreshold): self
    {
        if ($currentEstimatedQty < 0) {
            throw new InvalidArgumentException(sprintf(
                'La existencia estimada de un carril no puede ser negativa; se recibió %d.',
                $currentEstimatedQty
            ));
        }

        if ($minThreshold < 0) {
            throw new InvalidArgumentException(sprintf(
                'El umbral mínimo de un carril no puede ser negativo; se recibió %d.',
                $minThreshold
            ));
        }

        if ($currentEstimatedQty === 0) {
            return self::STOCKOUT;
        }

        return $currentEstimatedQty <= $minThreshold ? self::LOW_STOCK : self::OK;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
