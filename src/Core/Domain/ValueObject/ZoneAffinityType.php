<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * ZoneAffinityType
 *
 * Relación de un técnico con la zona operativa de una sede, tal y como la lee el
 * motor de sugerencia (RF-04.1) y el cuadrante de cobertura (RF-02.1):
 *
 *  - `PRIMARY`: es Titular Principal de la zona de la sede.
 *  - `BACKUP`:  es Suplente de esa zona.
 *  - `NONE`:    no pertenece a la zona.
 *
 * El tipo describe **la relación**, no la persona: un mismo técnico puede ser
 * `PRIMARY` frente a su zona titular y `BACKUP` frente a la de al lado en la
 * misma evaluación. Por eso es un enumerado y no una columna del usuario.
 *
 * Los pesos de idoneidad (+50 / +25 / 0) no viven aquí: son política de dominio
 * del `TechnicianWorkloadScorer` (T-ZONE-04), y separarlos evita que un cambio de
 * puntuación obligue a tocar el vocabulario del cuadrante.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
enum ZoneAffinityType: string
{
    case PRIMARY = 'PRIMARY';
    case BACKUP = 'BACKUP';
    case NONE = 'NONE';

    /**
     * Etiqueta en castellano que se muestra al coordinador en el panel de triaje.
     */
    public function label(): string
    {
        return match ($this) {
            self::PRIMARY => 'Titular de zona',
            self::BACKUP => 'Suplente de zona',
            self::NONE => 'Sin zona asignada',
        };
    }

    /**
     * Comprueba si una cadena corresponde a un tipo de afinidad válido.
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
                "Tipo de afinidad territorial no válido: '{$value}'. Valores permitidos: " . implode(', ', self::values())
            );
        }

        return $instance;
    }

    /**
     * Lista todos los valores escalares disponibles.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
