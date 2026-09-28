<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * OldPartDestination
 * 
 * Clasificación obligatoria del componente retirado durante la intervención (Art. VI: Anti-Feature Creep).
 */
enum OldPartDestination: string
{
    case DESGUACE = 'DESGUACE'; // Desecho definitivo para reciclaje / residuo
    case TALLER   = 'TALLER';   // Envío a taller técnico para comprobación o reacondicionamiento

    /**
     * Retorna la etiqueta legible en español para la interfaz de usuario.
     */
    public function label(): string
    {
        return match ($this) {
            self::DESGUACE => 'Desguace / Reciclaje',
            self::TALLER   => 'Taller / Reacondicionamiento',
        };
    }

    /**
     * Comprueba si un valor en string corresponde a un destino válido.
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }
}
