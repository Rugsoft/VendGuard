<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

/**
 * ManufacturerNormalizer
 *
 * Normalizador determinista del fabricante a partir de `machines.model`
 * (Módulo 25, RF-05, QA 4).
 *
 * El parque real escribe el modelo de formas incompatibles entre sí
 * (`NECTA KIKKO MAX`, `necta   krea`, `Jofemar Vision ES Plus`, `Fasel`). Para
 * que el filtro y la agregación de Pareto no fragmenten la misma marca en cinco
 * filas, la extracción es una función pura y sin heurísticas ocultas:
 *
 *   1. `trim()` del modelo completo (los espacios interiores se colapsan al
 *      partir por cualquier secuencia de espacios).
 *   2. Primer token antes del primer espacio; si el modelo es una sola palabra,
 *      esa palabra entera.
 *   3. Conversión a Formato Título con `MB_CASE_TITLE` sobre UTF-8.
 *   4. `'Sin Marca Especificada'` cuando el modelo es nulo o está en blanco.
 */
final class ManufacturerNormalizer
{
    /** Etiqueta canónica del parque sin modelo declarado (QA 4). */
    public const UNKNOWN_MANUFACTURER = 'Sin Marca Especificada';

    /**
     * Extrae el fabricante en Formato Título a partir del modelo de la máquina.
     */
    public static function extractManufacturer(?string $machineModel): string
    {
        if ($machineModel === null) {
            return self::UNKNOWN_MANUFACTURER;
        }

        $trimmed = trim($machineModel);
        if ($trimmed === '') {
            return self::UNKNOWN_MANUFACTURER;
        }

        $tokens = preg_split('/\s+/u', $trimmed);
        $firstToken = $tokens !== false && isset($tokens[0]) ? trim((string)$tokens[0]) : '';
        if ($firstToken === '') {
            return self::UNKNOWN_MANUFACTURER;
        }

        return mb_convert_case(mb_strtolower($firstToken, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Comprueba si un modelo pertenece al fabricante filtrado, sin distinguir
     * mayúsculas ni espacios sobrantes. Un filtro nulo o en blanco no filtra.
     */
    public static function matchesFilter(?string $machineModel, ?string $manufacturerFilter): bool
    {
        $cleanFilter = $manufacturerFilter !== null ? trim($manufacturerFilter) : '';
        if ($cleanFilter === '') {
            return true;
        }

        return mb_strtolower(self::extractManufacturer($machineModel), 'UTF-8')
            === mb_strtolower($cleanFilter, 'UTF-8');
    }
}
