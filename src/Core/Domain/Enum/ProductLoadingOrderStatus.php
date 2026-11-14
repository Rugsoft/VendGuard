<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * ProductLoadingOrderStatus
 *
 * Ciclo de vida de una orden de carga de furgoneta (Módulo 33, Tabla 45,
 * `product_loading_order_status`, RF-03.1, D3, QA 4).
 *
 * El orden de los casos es el del enumerado del motor y el de la prelación
 * operativa: preparación → despacho → recepción en furgoneta → liquidación al
 * cierre, con `CANCELLED` como única salida justificada. Las respuestas derivadas
 * (`isActive`, `canBe…`) son la verdad única que comparten los servicios, el
 * controlador y el frontend; ninguna capa vuelve a escribir la matriz a mano.
 *
 * `isActive()` coincide exactamente con el predicado del índice único parcial
 * `uq_product_loading_orders_active_restocker`: un reponedor solo puede tener una
 * orden en estos tres estados, y las cerradas o canceladas son histórico (D3).
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum ProductLoadingOrderStatus: string
{
    case PENDING_PICKING = 'PENDING_PICKING';
    case DISPATCHED = 'DISPATCHED';
    case CONFIRMED_BY_RESTOCKER = 'CONFIRMED_BY_RESTOCKER';
    case RECONCILED_AT_CLOSE = 'RECONCILED_AT_CLOSE';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_PICKING => 'En preparación (Picking)',
            self::DISPATCHED => 'Despachada',
            self::CONFIRMED_BY_RESTOCKER => 'Recibida en Furgoneta',
            self::RECONCILED_AT_CLOSE => 'Liquidada al Cierre',
            self::CANCELLED => 'Cancelada',
        };
    }

    /**
     * Estados que ocupan la plaza de orden activa del reponedor (D3).
     */
    public function isActive(): bool
    {
        return in_array($this, [
            self::PENDING_PICKING,
            self::DISPATCHED,
            self::CONFIRMED_BY_RESTOCKER,
        ], true);
    }

    /**
     * Estados que ya no admiten transiciones: liquidada o cancelada.
     */
    public function isTerminal(): bool
    {
        return !$this->isActive();
    }

    /**
     * Solo la preparación se edita: `UPSERT` de líneas y baja lógica (C1).
     */
    public function canBeEdited(): bool
    {
        return $this === self::PENDING_PICKING;
    }

    /**
     * Solo una orden en preparación se despacha desde almacén (RF-03.3).
     */
    public function canBeDispatched(): bool
    {
        return $this === self::PENDING_PICKING;
    }

    /**
     * Solo una orden despachada se recepciona en un toque (RF-04.1).
     */
    public function canBeConfirmed(): bool
    {
        return $this === self::DISPATCHED;
    }

    /**
     * Solo una orden recibida en furgoneta se liquida al cierre (RF-04.2).
     */
    public function canBeReconciled(): bool
    {
        return $this === self::CONFIRMED_BY_RESTOCKER;
    }

    /**
     * Cancelables únicamente antes de recepcionar la mercancía (EARS 03.4.2).
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::PENDING_PICKING, self::DISPATCHED], true);
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
        $status = self::tryFrom(strtoupper(trim($value)));
        if ($status === null) {
            throw new InvalidArgumentException(sprintf(
                "Estado de orden de carga no válido: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $status;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
