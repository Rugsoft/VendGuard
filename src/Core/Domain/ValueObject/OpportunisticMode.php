<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * OpportunisticMode
 *
 * Modalidad de una intervención oportunista in situ (Módulo 16).
 * Coincide exactamente con el enum `opportunistic_mode` de PostgreSQL.
 *
 * Las dos modalidades son excluyentes y describen de dónde nace el expediente:
 *  - `NEW`: el técnico presente en la sede abre el parte de avería en el acto.
 *  - `ASSUMED`: el técnico absorbe —o reabre— un expediente que ya existía.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas y mensajes en castellano.
 */
enum OpportunisticMode: string
{
    case NEW = 'NEW';
    case ASSUMED = 'ASSUMED';

    /**
     * Etiqueta canónica en castellano que leen el técnico y Coordinación.
     */
    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Oportunista In Situ · Nueva',
            self::ASSUMED => 'Oportunista In Situ · Asumida',
        };
    }

    public function isNew(): bool
    {
        return $this === self::NEW;
    }

    public function isAssumed(): bool
    {
        return $this === self::ASSUMED;
    }

    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    /**
     * @throws InvalidArgumentException Si el valor no pertenece al catálogo cerrado.
     */
    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        $mode = self::tryFrom($normalized);

        if ($mode === null) {
            throw new InvalidArgumentException(sprintf('Modalidad oportunista no válida: «%s»', $value));
        }

        return $mode;
    }
}
