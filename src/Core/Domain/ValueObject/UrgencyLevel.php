<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * UrgencyLevel
 * 
 * Nivel de severidad asignado a una incidencia en VendGuard.
 * El nivel CRITICAL está reservado prioritariamente para riesgos de seguridad
 * alimentaria (rotura de cadena de frío en máquinas PERISHABLE_FOOD).
 */
enum UrgencyLevel: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';

    /**
     * Devuelve la etiqueta descriptiva en castellano para la interfaz de usuario.
     */
    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Baja',
            self::MEDIUM => 'Media',
            self::HIGH => 'Alta',
            self::CRITICAL => 'Crítica (Riesgo Alimentario)',
        };
    }

    /**
     * Indica si el nivel de urgencia es de severidad crítica (SLA de respuesta 60 min).
     */
    public function isCritical(): bool
    {
        return $this === self::CRITICAL;
    }

    /**
     * Rango numérico de prioridad para ordenación determinista (mayor número = mayor prioridad).
     */
    public function priorityRank(): int
    {
        return match ($this) {
            self::CRITICAL => 4,
            self::HIGH => 3,
            self::MEDIUM => 2,
            self::LOW => 1,
        };
    }

    /**
     * Tiempo máximo de SLA de respuesta en minutos (24/7 para incidencias críticas).
     */
    public function slaResponseMinutes(): int
    {
        return match ($this) {
            self::CRITICAL => 60,
            self::HIGH => 240,     // 4 horas
            self::MEDIUM => 1440,  // 24 horas
            self::LOW => 2880,     // 48 horas
        };
    }

    /**
     * Comprueba si una cadena de texto es un valor de severidad válido.
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    /**
     * Construye una instancia a partir de una cadena o lanza una excepción clara.
     *
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        $instance = self::tryFrom($normalized);

        if ($instance === null) {
            throw new InvalidArgumentException(
                "Nivel de urgencia no válido: '{$value}'. Valores permitidos: " . implode(', ', self::values())
            );
        }

        return $instance;
    }

    /**
     * Lista todos los valores escalares disponibles.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
