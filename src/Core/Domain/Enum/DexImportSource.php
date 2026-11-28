<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * DexImportSource
 *
 * Puerta de entrada del volcado `.dex` (Módulo 39, Tabla 52,
 * `machine_dex_audits.import_source`, enum #52, RF-01.1, D1, C2, QA-1).
 *
 * Las tres puertas del contrato no son intercambiables: la ruta del reponedor
 * (`RESTOCKER_ROUTE`) es la única bloqueada por la primacía sanitaria del
 * Art. II.2, la del técnico (`TECHNICIAN_ROUTE`) conserva el acceso forense y la
 * consola de Coordinación (`COORDINATOR_CONSOLE`) ve la auditoría completa. El
 * rol congelado del importador (`importer_role_snapshot`) viaja aparte, pero
 * esta correspondencia permite validar que la puerta declarada no contradice al
 * actor que firma el alta.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum DexImportSource: string
{
    case RESTOCKER_ROUTE = 'RESTOCKER_ROUTE';
    case TECHNICIAN_ROUTE = 'TECHNICIAN_ROUTE';
    case COORDINATOR_CONSOLE = 'COORDINATOR_CONSOLE';

    public function label(): string
    {
        return match ($this) {
            self::RESTOCKER_ROUTE => 'Ruta del Reponedor',
            self::TECHNICIAN_ROUTE => 'Ruta del Técnico SAT',
            self::COORDINATOR_CONSOLE => 'Consola de Coordinación',
        };
    }

    /**
     * Rol del actor que puede firmar un alta por esta puerta.
     */
    public function actorRole(): string
    {
        return match ($this) {
            self::RESTOCKER_ROUTE => 'RESTOCKER',
            self::TECHNICIAN_ROUTE => 'TECHNICIAN',
            self::COORDINATOR_CONSOLE => 'COORDINATOR',
        };
    }

    public function isRestockerRoute(): bool
    {
        return $this === self::RESTOCKER_ROUTE;
    }

    /**
     * ¿La puerta declarada es coherente con el rol congelado del importador?
     */
    public function matchesActorRole(string $roleSnapshot): bool
    {
        return strtoupper(trim($roleSnapshot)) === $this->actorRole();
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
        $source = self::tryFrom(strtoupper(trim($value)));
        if ($source === null) {
            throw new InvalidArgumentException(sprintf(
                "Puerta de importación .dex no válida: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $source;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
