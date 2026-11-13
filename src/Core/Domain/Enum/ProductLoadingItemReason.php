<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * ProductLoadingItemReason
 *
 * Motivo por el que una línea entra en la orden de carga (Módulo 33, Tabla 46,
 * `product_loading_item_reason`, RF-02.1, D4, QA 4).
 *
 * El orden de los casos **es** la prelación de urgencia que exige la ordenación
 * determinista de la Hoja de Picking (EARS 02.2): agotado → bajo umbral → hueco
 * de ruta → adición manual. Un producto con varios carriles afectados se clasifica
 * por el más urgente, y esa comparación vive aquí para que propuesta, orden y
 * documento imprimible no puedan discrepar.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum ProductLoadingItemReason: string
{
    case STOCKOUT_ALERT = 'STOCKOUT_ALERT';
    case LOW_STOCK_ALERT = 'LOW_STOCK_ALERT';
    case ROUTE_GAP_FILL = 'ROUTE_GAP_FILL';
    case MANUAL_ADDITION = 'MANUAL_ADDITION';

    public function label(): string
    {
        return match ($this) {
            self::STOCKOUT_ALERT => 'Alerta de Agotado',
            self::LOW_STOCK_ALERT => 'Alerta de Stock Bajo',
            self::ROUTE_GAP_FILL => 'Relleno de Hueco de Ruta',
            self::MANUAL_ADDITION => 'Adición Manual',
        };
    }

    /**
     * Posición en la prelación de urgencia: 1 es la más urgente (EARS 02.2).
     */
    public function priority(): int
    {
        return match ($this) {
            self::STOCKOUT_ALERT => 1,
            self::LOW_STOCK_ALERT => 2,
            self::ROUTE_GAP_FILL => 3,
            self::MANUAL_ADDITION => 4,
        };
    }

    /**
     * ¿Este motivo es más urgente que el recibido?
     */
    public function outranks(self $other): bool
    {
        return $this->priority() < $other->priority();
    }

    /**
     * Motivo más urgente del conjunto (EARS 02.1, regla 3).
     *
     * Es la operación que usa la propuesta para clasificar un producto con varios
     * carriles afectados: gana el motivo de menor `priority()`, con independencia
     * del orden en que lleguen los carriles.
     *
     * @throws InvalidArgumentException Si no se recibe ningún motivo.
     */
    public static function mostUrgent(self ...$reasons): self
    {
        if ($reasons === []) {
            throw new InvalidArgumentException(
                'La clasificación de una línea exige al menos un motivo de inclusión.'
            );
        }

        $mostUrgent = $reasons[0];
        foreach ($reasons as $reason) {
            if ($reason->outranks($mostUrgent)) {
                $mostUrgent = $reason;
            }
        }

        return $mostUrgent;
    }

    /**
     * Motivos que calcula la propuesta automática; la adición manual no lo es.
     */
    public function isAutomatic(): bool
    {
        return $this !== self::MANUAL_ADDITION;
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
        $reason = self::tryFrom(strtoupper(trim($value)));
        if ($reason === null) {
            throw new InvalidArgumentException(sprintf(
                "Motivo de línea no válido: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $reason;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
