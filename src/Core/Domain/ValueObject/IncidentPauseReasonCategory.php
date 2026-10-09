<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * IncidentPauseReasonCategory
 *
 * Causas tipificadas que habilitan la pausa de SLA de una incidencia
 * ("Pendiente de Información"): el bloqueo debe ser siempre imputable a la sede
 * cliente y estar entre las cuatro causas cerradas que reconoce la operación
 * (RF-01.2), nunca descrito con texto libre que impida auditar el parque.
 *
 * La categoría es el "porqué" tipificado; el texto explicativo obligatorio
 * (mínimo 20 caracteres reales, Art. V.1) acompaña a la pausa en la incidencia.
 */
enum IncidentPauseReasonCategory: string
{
    case BUILDING_CLOSED_NO_ACCESS = 'BUILDING_CLOSED_NO_ACCESS';
    case MACHINE_LOCATION_NOT_FOUND = 'MACHINE_LOCATION_NOT_FOUND';
    case EXTERNAL_POWER_CUT = 'EXTERNAL_POWER_CUT';
    case PENDING_SITE_AUTHORIZATION = 'PENDING_SITE_AUTHORIZATION';

    /**
     * Devuelve la etiqueta descriptiva en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::BUILDING_CLOSED_NO_ACCESS => 'Edificio cerrado / Sin acceso a instalaciones',
            self::MACHINE_LOCATION_NOT_FOUND => 'Máquina no localizada en la planta o zona indicada',
            self::EXTERNAL_POWER_CUT => 'Corte eléctrico o de suministro ajeno a la máquina',
            self::PENDING_SITE_AUTHORIZATION => 'Pendiente de autorización o contacto de sede',
        };
    }

    /**
     * Comprueba si una cadena de texto corresponde a una causa válida.
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    /**
     * Construye una instancia a partir de una cadena o lanza excepción.
     *
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        $instance = self::tryFrom($normalized);

        if ($instance === null) {
            throw new InvalidArgumentException(
                "Causa de pausa no válida: '{$value}'. Valores permitidos: " . implode(', ', self::values())
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
