<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * SparePartCategory
 * 
 * Categorías técnicas normalizadas para clasificación de componentes y repuestos.
 */
enum SparePartCategory: string
{
    case HYDRAULIC      = 'HYDRAULIC';      // Bombas, electroválvulas, calderas, racores
    case THERMAL        = 'THERMAL';        // Sondas NTC, termostatos, compresores, ventiladores
    case ELECTRONIC     = 'ELECTRONIC';     // Placas CPU, fuentes de alimentación, displays
    case MECHANICAL     = 'MECHANICAL';     // Grupos de café, motores extractores, engranajes
    case PAYMENT_SYSTEM = 'PAYMENT_SYSTEM'; // Monederos, billeteros, lectores cashless
    case CONSUMABLE     = 'CONSUMABLE';     // Filtros, tubos teflón, juntas tóricas, descalcificadores
    case OTHER          = 'OTHER';          // Muelles, cerrajería, carcasas

    /**
     * Retorna la etiqueta legible en español para la interfaz de usuario.
     */
    public function label(): string
    {
        return match ($this) {
            self::HYDRAULIC      => 'Hidráulica y Presión',
            self::THERMAL        => 'Térmico y Refrigeración',
            self::ELECTRONIC     => 'Electrónica y Control',
            self::MECHANICAL     => 'Mecánica y Extracción',
            self::PAYMENT_SYSTEM => 'Sistemas de Pago',
            self::CONSUMABLE     => 'Consumibles y Juntas',
            self::OTHER          => 'Otros Componentes',
        };
    }

    /**
     * Comprueba si un valor en string corresponde a una categoría válida.
     */
    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }
}
