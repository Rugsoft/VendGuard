<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;

/**
 * UserRole
 * 
 * Roles internos de usuario en la plataforma VendGuard.
 *
 * `RESTOCKER` es el cuarto actor operativo (Módulo 31): el Cargador/Reponedor de
 * Ruta abastece el parque de máquinas con demarcaciones territoriales propias y
 * queda fuera del escalafón SAT, de la guardia 24/7 y de la asignación de
 * averías electromecánicas (RF-01.1, D3, Art. V.3).
 */
enum UserRole: string
{
    case COORDINATOR = 'COORDINATOR';
    case TECHNICIAN = 'TECHNICIAN';
    case RESTOCKER = 'RESTOCKER';

    /**
     * Devuelve la etiqueta descriptiva en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::COORDINATOR => 'Coordinador de Servicios',
            self::TECHNICIAN => 'Técnico de Ruta',
            self::RESTOCKER => 'Cargador / Reponedor de Ruta',
        };
    }

    /**
     * Comprueba si el usuario tiene rol de coordinador.
     */
    public function isCoordinator(): bool
    {
        return $this === self::COORDINATOR;
    }

    /**
     * Comprueba si el usuario tiene rol de técnico de ruta.
     */
    public function isTechnician(): bool
    {
        return $this === self::TECHNICIAN;
    }

    /**
     * Comprueba si el usuario tiene rol de cargador/reponedor de ruta.
     */
    public function isRestocker(): bool
    {
        return $this === self::RESTOCKER;
    }

    /**
     * ¿El rol opera en ruta y ficha jornada propia? (Módulo 31, `D1`)
     *
     * Técnicos y reponedores comparten el ciclo de turno y la autoconsulta de
     * horas, pero no las mismas superficies: el reponedor nunca entra en el
     * escalafón SAT ni en la guardia 24/7 del Art. II.
     */
    public function isFieldOperator(): bool
    {
        return $this === self::TECHNICIAN || $this === self::RESTOCKER;
    }

    /**
     * Comprueba si una cadena de texto corresponde a un rol válido.
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
                "Rol de usuario no válido: '{$value}'. Valores permitidos: " . implode(', ', self::values())
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
