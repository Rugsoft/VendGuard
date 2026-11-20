<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * IncidentDispatchLevel
 *
 * Nivel operativo con el que se despacha un expediente (Módulo 36, columna
 * `incidents.dispatch_level`, D1, D2, Art. V.4).
 *
 * El expediente es único (Art. IV): no hay dos tablas ni dos tickets paralelos.
 * Lo que cambia es quién interviene primero.
 *
 *   L1_RESTOCKER ──(⚡ escalar)──▶ L2_SAT
 *
 * `L1_RESTOCKER` atiende los avisos leves que el reponedor puede cerrar en su
 * ruta —producto agotado, espiral trabada, tubos de cambio vacíos o falta de
 * consumibles— sin repuestos ni albarán. `L2_SAT` es el despacho ordinario al
 * Técnico SAT y el único admitido para averías térmicas o eléctricas (Art. II).
 *
 * El valor histórico por defecto es `L2_SAT`: toda fila anterior al Módulo 36
 * se hidrata en el nivel que ya tenía de facto.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum IncidentDispatchLevel: string
{
    case L1_RESTOCKER = 'L1_RESTOCKER';
    case L2_SAT = 'L2_SAT';

    /** Nivel que hereda cualquier expediente sin declaración explícita. */
    public const DEFAULT = self::L2_SAT;

    public function label(): string
    {
        return match ($this) {
            self::L1_RESTOCKER => 'Nivel 1 · Reponedor',
            self::L2_SAT => 'Nivel 2 · Técnico SAT',
        };
    }

    public function isLevel1(): bool
    {
        return $this === self::L1_RESTOCKER;
    }

    public function isSatLevel(): bool
    {
        return $this === self::L2_SAT;
    }

    /**
     * ¿La intervención corresponde a un Técnico SAT? El Nivel 1 nunca exige
     * técnico mientras no se escale (D3).
     */
    public function requiresTechnician(): bool
    {
        return $this->isSatLevel();
    }

    /**
     * ¿La intervención corresponde a un Reponedor en ruta? Solo el Nivel 1.
     */
    public function requiresRestocker(): bool
    {
        return $this->isLevel1();
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
        $level = self::tryFrom(strtoupper(trim($value)));
        if ($level === null) {
            throw new InvalidArgumentException(sprintf(
                "Nivel de despacho no válido: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $level;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
