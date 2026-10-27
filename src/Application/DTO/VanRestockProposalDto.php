<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use JsonSerializable;
use VendGuard\Core\Domain\Model\VanRestockOrder;

/**
 * VanRestockProposalDto
 *
 * Propuesta de reposición pre-calculada para una furgoneta (Módulo 23, RF-02,
 * contrato §5.2). Es una proyección de lectura: no persiste nada ni emite
 * órdenes por sí misma.
 *
 * Las líneas se ordenan en el constructor con la prelación determinista de
 * `QA 5` —motivo (`BOTH` -> `PENDING_REQUEST` -> `LOW_STOCK` -> `MANUAL`),
 * menor saldo disponible (los descuadres negativos encabezan su bloque) y
 * código de pieza ascendente— de modo que la propuesta, la orden emitida y el
 * Albarán de Picking A4 compartan un único orden y no dependan de la consulta
 * SQL ni del navegador.
 *
 * Las banderas `technician_is_active` y `recipient_currently_absent` viajan
 * calculadas desde la base (QA 4): Coordinación necesita saber que la caja se
 * prepara para alguien ausente, y la propuesta en lote necesita excluirlo.
 * `open_order_id` y `open_order_status` identifican la orden que ya ocupa el
 * hueco abierto del técnico (D3), si existe.
 *
 * Ninguna línea incluye coste, precio ni importe: el pre-cálculo opera sobre
 * unidades físicas (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, inmutable y sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class VanRestockProposalDto implements JsonSerializable
{
    /** @var list<array<string, int|string>> Líneas en el orden determinista de `QA 5`. */
    public array $items;

    /**
     * @param list<array<string, int|string>> $items Líneas de la propuesta con las
     *        claves `spare_part_id`, `part_code`, `part_name`, `category`,
     *        `current_quantity`, `min_threshold`, `pending_request_qty`,
     *        `suggested_qty`, `max_allowed_qty` y `reason`.
     */
    public function __construct(
        public int $technicianId,
        public string $technicianName,
        public bool $technicianIsActive,
        public bool $recipientCurrentlyAbsent,
        public ?int $openOrderId,
        public ?string $openOrderStatus,
        array $items
    ) {
        $ordered = array_values($items);
        usort($ordered, [self::class, 'compareLines']);
        $this->items = $ordered;
    }

    /**
     * Orden total de líneas de `QA 5`, reutilizable por el servicio al emitir la
     * orden y por la Hoja de Carga A4.
     *
     * @param array<string, int|string> $left
     * @param array<string, int|string> $right
     */
    public static function compareLines(array $left, array $right): int
    {
        $priority = VanRestockOrder::reasonPriority((string)($left['reason'] ?? ''))
            <=> VanRestockOrder::reasonPriority((string)($right['reason'] ?? ''));
        if ($priority !== 0) {
            return $priority;
        }

        $quantity = (int)($left['current_quantity'] ?? 0) <=> (int)($right['current_quantity'] ?? 0);
        if ($quantity !== 0) {
            return $quantity;
        }

        return strcmp((string)($left['part_code'] ?? ''), (string)($right['part_code'] ?? ''));
    }

    /**
     * Indica si el cálculo automático encontró déficit. En falso, Coordinación
     * puede añadir líneas manuales o cerrar la propuesta (C4).
     */
    public function hasLines(): bool
    {
        return $this->items !== [];
    }

    /**
     * Indica si el técnico ya tiene una orden abierta (D3): en lote se excluye y
     * en la creación individual se informa de su código (EARS 01.1).
     */
    public function hasOpenOrder(): bool
    {
        return $this->openOrderId !== null;
    }

    /**
     * Proyección del contrato §5.2.
     *
     * @return array<string, int|string|bool|array<int, array<string, int|string>>|null>
     */
    public function toArray(): array
    {
        return [
            'technician_id' => $this->technicianId,
            'technician_name' => $this->technicianName,
            'technician_is_active' => $this->technicianIsActive,
            'recipient_currently_absent' => $this->recipientCurrentlyAbsent,
            'open_order_id' => $this->openOrderId,
            'open_order_status' => $this->openOrderStatus,
            'items' => $this->items,
        ];
    }

    /**
     * @return array<string, int|string|bool|array<int, array<string, int|string>>|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
