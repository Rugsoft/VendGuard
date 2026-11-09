<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * SlaTier
 *
 * Perfil contractual de servicio de una sede (Módulo 30, RF-01, D1).
 * La matriz de minutos objetivos vive aquí para que la base de datos, el
 * dominio y la ruta compartan una única fuente de verdad; el techo sanitario
 * de 240 minutos del Art. II se aplica en SlaTargetCalculator, no en este VO.
 */
enum SlaTier: string
{
    case STANDARD = 'STANDARD';
    case PRIORITY_CORPORATE = 'PRIORITY_CORPORATE';
    case CRITICAL_INFRASTRUCTURE_24_7 = 'CRITICAL_INFRASTRUCTURE_24_7';

    /**
     * Etiqueta visible en Coordinación, Ruta y Portal de Sede.
     */
    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'Estándar',
            self::PRIORITY_CORPORATE => 'Prioritario Corporativo',
            self::CRITICAL_INFRASTRUCTURE_24_7 => 'Infraestructura Crítica 24x7',
        };
    }

    /**
     * Horas nominales de referencia para el desglose por sede (RF-06).
     */
    public function nominalReferenceHours(): float
    {
        return match ($this) {
            self::STANDARD => 24.0,
            self::PRIORITY_CORPORATE => 12.0,
            self::CRITICAL_INFRASTRUCTURE_24_7 => 8.0,
        };
    }

    /**
     * Minutos objetivos para un aviso sin riesgo de cadena de frío (RF-01.3, D1).
     */
    public function contractualTargetMinutes(UrgencyLevel $urgency): int
    {
        return match ($this) {
            self::STANDARD => match ($urgency) {
                UrgencyLevel::CRITICAL => 480,
                UrgencyLevel::HIGH => 720,
                UrgencyLevel::MEDIUM, UrgencyLevel::LOW => 1440,
            },
            self::PRIORITY_CORPORATE => match ($urgency) {
                UrgencyLevel::CRITICAL => 360,
                UrgencyLevel::HIGH => 480,
                UrgencyLevel::MEDIUM, UrgencyLevel::LOW => 720,
            },
            self::CRITICAL_INFRASTRUCTURE_24_7 => match ($urgency) {
                UrgencyLevel::CRITICAL, UrgencyLevel::HIGH => 240,
                UrgencyLevel::MEDIUM, UrgencyLevel::LOW => 480,
            },
        };
    }

    /**
     * Comprueba si una cadena corresponde a un perfil oficial (C5).
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    /**
     * Construye el perfil desde su valor persistido o rechaza el valor desconocido.
     *
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        $tier = self::tryFrom($normalized);

        if ($tier === null) {
            throw new InvalidArgumentException("Nivel de SLA no válido: '{$value}'.");
        }

        return $tier;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}