<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * RestockReassignmentReasonCode
 *
 * Causas tipificadas de un desvío puntual de parada (Módulo 37, columna
 * `restock_route_daily_overrides.reassignment_reason_code`, tipo nativo #50,
 * RF-02.1, QA-4).
 *
 * El desvío es de un solo día y no reescribe la agenda ni la custodia de los
 * tickets de Nivel 1 (`QA-4`). La causa explica por qué la parada cambia de
 * manos:
 *
 *   ZONE_UNCOVERED     ─ la zona quedó sin titular efectivo
 *   LOAD_BALANCING     ─ reparto de carga entre reponedores
 *   FEFO_RESCUE        ─ rescate sanitario de un lote próximo a caducar
 *   OTHER_REASSIGNMENT ─ otra causa operativa
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum RestockReassignmentReasonCode: string
{
    case ZONE_UNCOVERED = 'ZONE_UNCOVERED';
    case LOAD_BALANCING = 'LOAD_BALANCING';
    case FEFO_RESCUE = 'FEFO_RESCUE';
    case OTHER_REASSIGNMENT = 'OTHER_REASSIGNMENT';

    public function label(): string
    {
        return match ($this) {
            self::ZONE_UNCOVERED => 'Zona sin cobertura',
            self::LOAD_BALANCING => 'Equilibrio de carga',
            self::FEFO_RESCUE => 'Rescate FEFO',
            self::OTHER_REASSIGNMENT => 'Otro desvío',
        };
    }

    /**
     * ¿El desvío responde a una urgencia sanitaria (Art. II)?
     */
    public function isSanitaryRescue(): bool
    {
        return $this === self::FEFO_RESCUE;
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
                "Causa de desvío no válida: '%s'. Valores permitidos: %s.",
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
