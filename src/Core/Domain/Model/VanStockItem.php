<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;

/**
 * VanStockItem
 *
 * Saldo declarado de una pieza en la furgoneta de un técnico (RF-01 del Módulo 18).
 *
 * Dos decisiones que esta entidad hace explícitas:
 *
 *  - **La cantidad admite negativos.** Un negativo no es un error de captura: significa que el
 *    técnico declaró más unidades instaladas de las que constaban cargadas en su furgoneta. Ese
 *    descuadre es un dato de negocio (RF-03.3) y por eso no se trunca a cero ni bloquea nada
 *    (RD-04, Art. V.1). Las cotas solo existen para atrapar la errata de teclado.
 *  - **No hay coste.** El coste del repuesto se congela de forma inmutable al declarar la pieza
 *    en la avería (`incident_replaced_parts.unit_cost_snapshot`, RD-05); esta entidad solo cuenta
 *    unidades.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final readonly class VanStockItem
{
    /** Cota inferior de la cantidad: el descuadre máximo declarable. */
    public const QUANTITY_MIN = -999;

    /** Cota superior de la cantidad disponible. */
    public const QUANTITY_MAX = 9999;

    /** El umbral no admite negativos: no se avisa de una reposición por debajo de cero. */
    public const THRESHOLD_MIN = 0;

    /** Cota superior del umbral mínimo de reposición. */
    public const THRESHOLD_MAX = 9999;

    /**
     * @param int|null $id Identificador de la fila (null antes de persistir).
     * @param int $technicianId Técnico dueño de la furgoneta.
     * @param int $sparePartId Pieza del catálogo maestro.
     * @param int $quantityAvailable Unidades declaradas; puede ser negativo (descuadre, RD-04).
     * @param int $minThreshold Umbral mínimo de reposición.
     *
     * @throws InvalidArgumentException Si la cantidad o el umbral quedan fuera de sus cotas.
     */
    public function __construct(
        public ?int $id,
        public int $technicianId,
        public int $sparePartId,
        public int $quantityAvailable,
        public int $minThreshold
    ) {
        if ($quantityAvailable < self::QUANTITY_MIN || $quantityAvailable > self::QUANTITY_MAX) {
            throw new InvalidArgumentException(
                sprintf(
                    'La cantidad disponible debe estar entre %d y %d unidades (valor recibido: %d).',
                    self::QUANTITY_MIN,
                    self::QUANTITY_MAX,
                    $quantityAvailable
                )
            );
        }

        if ($minThreshold < self::THRESHOLD_MIN || $minThreshold > self::THRESHOLD_MAX) {
            throw new InvalidArgumentException(
                sprintf(
                    'El umbral mínimo debe estar entre %d y %d unidades (valor recibido: %d).',
                    self::THRESHOLD_MIN,
                    self::THRESHOLD_MAX,
                    $minThreshold
                )
            );
        }
    }

    /**
     * RF-03.1: la insignia se enciende cuando la cantidad **no supera** el umbral declarado.
     *
     * La igualdad cuenta: si el técnico fijó el umbral en 2 y le quedan 2, ya está en el límite que
     * él mismo declaró como «toca reponer».
     */
    public function isBelowThreshold(): bool
    {
        return $this->quantityAvailable <= $this->minThreshold;
    }

    /**
     * RF-03.3: descuadre cuando se declararon más unidades de las que constaban cargadas.
     *
     * Cero no es un descuadre: es una furgoneta sin esa pieza.
     */
    public function isNegative(): bool
    {
        return $this->quantityAvailable < 0;
    }
}
