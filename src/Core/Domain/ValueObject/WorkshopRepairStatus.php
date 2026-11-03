<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

/**
 * WorkshopRepairStatus
 *
 * Estado de una unidad dentro del ciclo de taller central (Módulo 27, Tabla 38).
 * Es un catálogo cerrado de cinco valores que se corresponde uno a uno con el
 * tipo `ENUM workshop_repair_status` del motor: un estado que no esté aquí no
 * puede escribirse en la base.
 *
 * El ciclo no es una escalera: admite un atajo (de `RECEIVED_IN_WORKSHOP` al
 * dictamen sin pasar por banco) y una vía excepcional (de `PENDING_DELIVERY` a
 * `SCRAPPED_IRREPARABLE` cuando la pieza nunca llega al taller, `C1`). Las dos
 * terminaciones son inmutables (`C5`): una vez dictaminada, la unidad no vuelve
 * a moverse.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum WorkshopRepairStatus: string
{
    case PENDING_DELIVERY = 'PENDING_DELIVERY';
    case RECEIVED_IN_WORKSHOP = 'RECEIVED_IN_WORKSHOP';
    case IN_BENCH_TEST = 'IN_BENCH_TEST';
    case REFURBISHED_RESTORED = 'REFURBISHED_RESTORED';
    case SCRAPPED_IRREPARABLE = 'SCRAPPED_IRREPARABLE';

    /**
     * Etiqueta legible que viaja como `status_label` en la bandeja y en el CSV.
     * Se declara aquí para que un estado nuevo no pueda llegar al motor sin su
     * nombre en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING_DELIVERY => 'Pendiente de Entrega en Taller',
            self::RECEIVED_IN_WORKSHOP => 'Recibida en Taller',
            self::IN_BENCH_TEST => 'En Banco de Pruebas',
            self::REFURBISHED_RESTORED => 'Reacondicionada y Restaurada',
            self::SCRAPPED_IRREPARABLE => 'Desguazada Irreparable',
        };
    }

    /**
     * Un dictamen terminal no admite más transiciones (`C5`, `409`).
     */
    public function isTerminal(): bool
    {
        return $this === self::REFURBISHED_RESTORED || $this === self::SCRAPPED_IRREPARABLE;
    }

    /**
     * Unidad todavía en curso dentro del circuito de taller.
     */
    public function isActive(): bool
    {
        return !$this->isTerminal();
    }

    /**
     * Transiciones legales del ciclo (contrato §2.2–§2.4).
     *
     * `PENDING_DELIVERY` sólo puede recibirse o darse de baja por extravío; llegar
     * a un dictamen de reacondicionado sin pasar por el taller es imposible porque
     * nadie ha podido comprobar la pieza (Art. V.1).
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PENDING_DELIVERY => in_array($target, [
                self::RECEIVED_IN_WORKSHOP,
                self::SCRAPPED_IRREPARABLE,
            ], true),
            self::RECEIVED_IN_WORKSHOP => in_array($target, [
                self::IN_BENCH_TEST,
                self::REFURBISHED_RESTORED,
                self::SCRAPPED_IRREPARABLE,
            ], true),
            self::IN_BENCH_TEST => in_array($target, [
                self::REFURBISHED_RESTORED,
                self::SCRAPPED_IRREPARABLE,
            ], true),
            self::REFURBISHED_RESTORED, self::SCRAPPED_IRREPARABLE => false,
        };
    }
}
