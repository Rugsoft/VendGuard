<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;

/**
 * UserRole
 * 
 * Roles internos de usuario en la plataforma VendGuard.
 */
enum UserRole: string
{
    case COORDINATOR = 'COORDINATOR';
    case TECHNICIAN = 'TECHNICIAN';

    /**
     * Devuelve la etiqueta descriptiva en castellano.
     */
    public function label(): string
    {
        return match ($this) {
            self::COORDINATOR => 'Coordinador de Servicios',
            self::TECHNICIAN => 'Técnico de Ruta',
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
