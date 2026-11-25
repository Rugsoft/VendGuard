<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Enum\DexEventCategory;
use VendGuard\Core\Domain\Enum\DexEventSeverity;

/**
 * EvaDtsEventCatalog
 *
 * Catálogo determinista de códigos `EA1`/`EA2` (Módulo 39, RF-05.1, D4, QA-4,
 * Art. II y Art. V.4).
 *
 * El orden de prelación es parte del contrato y no una casualidad: los alias
 * térmicos explícitos (`EA1_TEMP*`, `EA2_TEMP*`, `E_TEMP*`) empiezan por `EA`,
 * que también es prefijo de pago; si la familia térmica no se evaluara primero,
 * un aviso de cadena de frío se clasificaría como un fallo de monedero y no
 * dispararía la alerta sanitaria. La matriz es:
 *
 *   - `THERMAL_REFRIGERATION` · `CRITICAL`: prefijo `EG*` y códigos `TEMP_*`,
 *     `REFR_*`, `COOL_*`, `DEFROST_*`, `EA1_TEMP*`, `EA2_TEMP*`, `E_TEMP*`.
 *   - `PAYMENT_MDB` · `WARNING`: prefijos `EA*`, `EB*`, `EC*`, `EJ*` y códigos
 *     `MDB_*`, `COIN_*`, `BILL_*`, `TUBE_*`, `CASHLESS_*`.
 *   - `MECHANICAL_VEND` · `WARNING`: prefijos `EF*`, `EH*` y códigos `MOTOR_*`,
 *     `SPIRAL_*`, `DROP_*`, `SENSOR_*`, `VEND_*`.
 *   - `POWER_CONTROLLER` · `WARNING`: prefijos `ED*`, `EE*` y códigos `POWER_*`,
 *     `VMC_*`, `COMM_*`, `EEPROM_*`.
 *   - `OTHER` · `INFO`: cualquier otro código, conservado intacto y sin inventar
 *     diagnósticos (Art. I.3).
 *
 * La comparación es sobre el código plegado a mayúsculas y sin espacios. El
 * catálogo no depende de la base ni de configuración: dos instalaciones con el
 * mismo `.dex` producen la misma clasificación.
 *
 * Dogma Vanilla: PHP 8.2+ puro, funciones estáticas puras, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class EvaDtsEventCatalog
{
    /**
     * Alias térmicos explícitos. Se evalúan antes que los prefijos de pago.
     *
     * @var list<string>
     */
    private const THERMAL_CODE_PREFIXES = [
        'TEMP_',
        'REFR_',
        'COOL_',
        'DEFROST_',
        'EA1_TEMP',
        'EA2_TEMP',
        'E_TEMP',
    ];

    /** @var list<string> */
    private const PAYMENT_CODE_PREFIXES = ['MDB_', 'COIN_', 'BILL_', 'TUBE_', 'CASHLESS_'];

    /** @var list<string> */
    private const MECHANICAL_CODE_PREFIXES = ['MOTOR_', 'SPIRAL_', 'DROP_', 'SENSOR_', 'VEND_'];

    /** @var list<string> */
    private const POWER_CODE_PREFIXES = ['POWER_', 'VMC_', 'COMM_', 'EEPROM_'];

    /**
     * Clasificación completa de un código `EA1`/`EA2`.
     *
     * @return array{category: DexEventCategory, severity: DexEventSeverity}
     */
    public static function describe(string $eventCode): array
    {
        $code = self::normalize($eventCode);
        $category = self::category($code);
        $severity = match ($category) {
            DexEventCategory::THERMAL_REFRIGERATION => DexEventSeverity::CRITICAL,
            DexEventCategory::PAYMENT_MDB,
            DexEventCategory::MECHANICAL_VEND,
            DexEventCategory::POWER_CONTROLLER => DexEventSeverity::WARNING,
            DexEventCategory::OTHER => DexEventSeverity::INFO,
        };

        return ['category' => $category, 'severity' => $severity];
    }

    /**
     * Familia funcional del código, con la prelación del contrato.
     */
    public static function category(string $eventCode): DexEventCategory
    {
        $code = self::normalize($eventCode);

        if (str_starts_with($code, 'EG') || self::startsWithAny($code, self::THERMAL_CODE_PREFIXES)) {
            return DexEventCategory::THERMAL_REFRIGERATION;
        }

        if (self::startsWithAny($code, ['EA', 'EB', 'EC', 'EJ'])
            || self::startsWithAny($code, self::PAYMENT_CODE_PREFIXES)) {
            return DexEventCategory::PAYMENT_MDB;
        }

        if (self::startsWithAny($code, ['EF', 'EH'])
            || self::startsWithAny($code, self::MECHANICAL_CODE_PREFIXES)) {
            return DexEventCategory::MECHANICAL_VEND;
        }

        if (self::startsWithAny($code, ['ED', 'EE'])
            || self::startsWithAny($code, self::POWER_CODE_PREFIXES)) {
            return DexEventCategory::POWER_CONTROLLER;
        }

        return DexEventCategory::OTHER;
    }

    /**
     * Gravedad derivada de la familia: el térmico es el único crítico.
     */
    public static function severity(string $eventCode): DexEventSeverity
    {
        return self::describe($eventCode)['severity'];
    }

    /**
     * ¿El código pertenece a la familia que dispara el aviso sanitario?
     */
    public static function isThermal(string $eventCode): bool
    {
        return self::category($eventCode)->allowsThermalAlert();
    }

    /**
     * Descripción legible y determinista del evento para la línea persistida.
     */
    public static function describeInSpanish(string $eventCode): string
    {
        $code = self::normalize($eventCode);
        $category = self::category($code);

        return sprintf('Evento %s · %s', $category->label(), $code);
    }

    private static function normalize(string $eventCode): string
    {
        return strtoupper(trim($eventCode));
    }

    /**
     * @param list<string> $prefixes
     */
    private static function startsWithAny(string $code, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
