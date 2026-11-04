<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

/**
 * WorkshopRestockDestination
 *
 * Destino de una pieza reacondicionada (`D3`). Se corresponde uno a uno con el
 * tipo `ENUM workshop_restock_destination` del motor.
 *
 * Sólo dos salidas son legales: el almacén central, que no exige más control, y
 * la furgoneta de un técnico, que exige una pieza de catálogo viva, un técnico
 * activo y hueco de stock (`C3`, `QA 3`). El servicio de aplicación es quien
 * comprueba esas condiciones contra la base; el enumerado sólo declara la forma.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum WorkshopRestockDestination: string
{
    case CENTRAL_WAREHOUSE = 'CENTRAL_WAREHOUSE';
    case TECHNICIAN_VAN = 'TECHNICIAN_VAN';

    public function label(): string
    {
        return match ($this) {
            self::CENTRAL_WAREHOUSE => 'Almacén Central',
            self::TECHNICIAN_VAN => 'Furgoneta de Técnico',
        };
    }

    /**
     * El reintegro a furgoneta es el único que exige técnico destinatario y
     * consume una unidad de `technician_van_stocks`.
     */
    public function requiresTechnician(): bool
    {
        return $this === self::TECHNICIAN_VAN;
    }
}
