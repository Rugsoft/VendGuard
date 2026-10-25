<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

/**
 * HandoverTargetAvailability
 *
 * Clasificación operativa de un técnico como receptor de un relevo de fin de
 * jornada o como guardia sanitario (Módulo 21).
 *
 * La presencia física prevalece sobre una ausencia planificada (Resolución R4):
 * un técnico con turno ON_SHIFT está disponible aunque tenga una ausencia
 * activa. La ausencia solo bloquea cuando no hay turno abierto.
 *
 * `UNAVAILABLE` es la proyección pública de una ausencia sin turno: el motivo y
 * las fechas nunca se publican en el listado de compañeros (Art. V.4).
 */
enum HandoverTargetAvailability: string
{
    case AVAILABLE = 'AVAILABLE';
    case ON_CALL = 'ON_CALL';
    case OFF_SHIFT = 'OFF_SHIFT';
    case MEAL_BREAK = 'MEAL_BREAK';
    case UNAVAILABLE = 'UNAVAILABLE';

    /**
     * Clasifica a un técnico a partir de su turno abierto, su ausencia activa y
     * su condición de guardia 24/7.
     *
     * El orden de las reglas es deliberado:
     *   1. `ON_SHIFT` gana a cualquier ausencia (R4).
     *   2. `MEAL_BREAK` no es disponible aunque sea guardia.
     *   3. Sin turno, una ausencia activa bloquea.
     *   4. Sin turno y sin ausencia, la guardia 24/7 solo sirve para perecederas.
     */
    public static function classify(
        ?string $shiftStatus,
        bool $hasActiveAbsence,
        bool $isOnCall
    ): self {
        $status = $shiftStatus ?? 'OFF_SHIFT';

        if ($status === 'ON_SHIFT') {
            return self::AVAILABLE;
        }

        if ($status === 'MEAL_BREAK') {
            return self::MEAL_BREAK;
        }

        if ($hasActiveAbsence) {
            return self::UNAVAILABLE;
        }

        if ($isOnCall) {
            return self::ON_CALL;
        }

        return self::OFF_SHIFT;
    }

    /**
     * ¿Puede recibir un traspaso manual de averías?
     *
     * La guardia 24/7 fuera de turno también puede recibir: su condición de
     * guardia es una disponibilidad operativa declarada (R6). Una ausencia sin
     * turno, una pausa o un OFF_SHIFT sin guardia no son receptores válidos.
     */
    public function canReceiveHandover(): bool
    {
        return $this === self::AVAILABLE || $this === self::ON_CALL;
    }

    /** ¿Puede recibir el Nivel 1 del protocolo sanitario? */
    public function canReceiveSanitary(): bool
    {
        return $this === self::AVAILABLE || $this === self::ON_CALL;
    }

    /** Orden canónico del listado: disponibles primero, no disponibles al final. */
    public function sortOrder(): int
    {
        return match ($this) {
            self::AVAILABLE => 0,
            self::ON_CALL => 1,
            self::OFF_SHIFT => 2,
            self::MEAL_BREAK => 3,
            self::UNAVAILABLE => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Disponible',
            self::ON_CALL => 'Guardia 24/7 · disponible',
            self::OFF_SHIFT => 'Fuera de turno',
            self::MEAL_BREAK => 'Pausa comida',
            self::UNAVAILABLE => 'No disponible',
        };
    }
}
