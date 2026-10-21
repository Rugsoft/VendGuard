<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use JsonSerializable;

/**
 * VanStockEntryDto
 *
 * Fila de inventario ya unida a su técnico y a su pieza, tal y como la devuelven las dos
 * superficies de consulta del Módulo 18: la vista del técnico (RF-04.1) y el panel del
 * coordinador (RF-04.2).
 *
 * Las dos proyecciones existen porque los dos públicos no ven lo mismo (Art. V.4): el técnico no
 * necesita saber de quién es la furgoneta —es la suya—, y el coordinador sí, porque compara
 * furgonetas. Ninguna de las dos lleva importes ni costes: el coste del repuesto vive congelado en
 * la línea de la avería (RD-05).
 *
 * Las marcas `is_below_threshold` e `is_negative` se calculan aquí y no en el frontend: la
 * insignia de bajo umbral (RF-03.1) y la de descuadre (RF-03.3) tienen una única definición, y es
 * la de la entidad de dominio.
 *
 * Dogma Vanilla: `final readonly`, PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class VanStockEntryDto implements JsonSerializable
{
    /**
     * @param int $sparePartId Identificador de la pieza.
     * @param string $partCode Código del catálogo maestro.
     * @param string $name Nombre de la pieza.
     * @param string $category Categoría del catálogo (`HYDRAULIC`, `CONSUMABLE`, ...).
     * @param int $quantityAvailable Unidades declaradas; puede ser negativo (RD-04).
     * @param int $minThreshold Umbral mínimo de reposición declarado.
     * @param int $technicianId Técnico dueño de la furgoneta.
     * @param string $technicianName Nombre del técnico.
     * @param string|null $operatorCode Código de operador del técnico, si lo tiene.
     */
    public function __construct(
        public int $sparePartId,
        public string $partCode,
        public string $name,
        public string $category,
        public int $quantityAvailable,
        public int $minThreshold,
        public int $technicianId,
        public string $technicianName,
        public ?string $operatorCode
    ) {
    }

    /**
     * RF-03.1: bajo umbral cuando la cantidad no supera el umbral.
     */
    public function isBelowThreshold(): bool
    {
        return $this->quantityAvailable <= $this->minThreshold;
    }

    /**
     * RF-03.3: descuadre cuando la cantidad declarada es negativa.
     */
    public function isNegative(): bool
    {
        return $this->quantityAvailable < 0;
    }

    /**
     * Proyección del técnico autenticado (contrato §4.1): sin identidad de técnico ni marcas que
     * no usa, y sin coste en ningún caso.
     *
     * @return array<string, int|string|bool>
     */
    public function toTechnicianArray(): array
    {
        return [
            'spare_part_id' => $this->sparePartId,
            'part_code' => $this->partCode,
            'name' => $this->name,
            'category' => $this->category,
            'quantity_available' => $this->quantityAvailable,
            'min_threshold' => $this->minThreshold,
            'is_below_threshold' => $this->isBelowThreshold(),
        ];
    }

    /**
     * Proyección del coordinador (contrato §4.2): añade de quién es la furgoneta y la marca de
     * descuadre que ordena la alerta por severidad.
     *
     * @return array<string, int|string|bool|null>
     */
    public function toCoordinatorArray(): array
    {
        return [
            'technician_id' => $this->technicianId,
            'technician_name' => $this->technicianName,
            'operator_code' => $this->operatorCode,
            'spare_part_id' => $this->sparePartId,
            'part_code' => $this->partCode,
            'name' => $this->name,
            'quantity_available' => $this->quantityAvailable,
            'min_threshold' => $this->minThreshold,
            'is_below_threshold' => $this->isBelowThreshold(),
            'is_negative' => $this->isNegative(),
        ];
    }

    /**
     * Proyección por defecto: la del técnico, que es la más restrictiva (Art. V.4).
     *
     * @return array<string, int|string|bool>
     */
    public function jsonSerialize(): array
    {
        return $this->toTechnicianArray();
    }
}
