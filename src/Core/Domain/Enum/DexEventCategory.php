<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * DexEventCategory
 *
 * Familia funcional de un evento `EA1`/`EA2` del volcado `.dex` (Módulo 39,
 * Tabla 54, `machine_dex_event_lines.event_category`, enum #54, RF-05.1, D4,
 * QA-4, Art. II).
 *
 * El catálogo lo asigna `EvaDtsEventCatalog` a partir del prefijo del código
 * (`EG*` térmico, `EA*`/`EB*`/`EC*`/`EJ*` pago, `EF*`/`EH*` mecánica,
 * `ED*`/`EE*` energía/controlador, resto `OTHER`); este enumerado solo declara la
 * familia y su lectura. `THERMAL_REFRIGERATION` es la única que habilita
 * `is_thermal_alert = TRUE` y, con él, la alerta `DEX_THERMAL_EVENT_ALERT` y el
 * escalado en un toque (Art. II.2).
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum DexEventCategory: string
{
    case THERMAL_REFRIGERATION = 'THERMAL_REFRIGERATION';
    case PAYMENT_MDB = 'PAYMENT_MDB';
    case MECHANICAL_VEND = 'MECHANICAL_VEND';
    case POWER_CONTROLLER = 'POWER_CONTROLLER';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::THERMAL_REFRIGERATION => 'Refrigeración / Cadena de Frío',
            self::PAYMENT_MDB => 'Sistema de Pago (MDB)',
            self::MECHANICAL_VEND => 'Mecánica de Expendición',
            self::POWER_CONTROLLER => 'Energía y Controlador',
            self::OTHER => 'Otros Eventos',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::THERMAL_REFRIGERATION => '🌡️',
            self::PAYMENT_MDB => '💳',
            self::MECHANICAL_VEND => '⚙️',
            self::POWER_CONTROLLER => '⚡',
            self::OTHER => '📌',
        };
    }

    public function isThermalRefrigeration(): bool
    {
        return $this === self::THERMAL_REFRIGERATION;
    }

    /**
     * ¿Esta familia puede disparar el aviso sanitario del Art. II.2?
     */
    public function allowsThermalAlert(): bool
    {
        return $this->isThermalRefrigeration();
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
        $category = self::tryFrom(strtoupper(trim($value)));
        if ($category === null) {
            throw new InvalidArgumentException(sprintf(
                "Categoría de evento .dex no válida: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $category;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
