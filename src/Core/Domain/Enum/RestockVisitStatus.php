<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * RestockVisitStatus
 *
 * Desenlace de una visita de reposición in situ (Módulo 34, Tabla 47,
 * `machine_restock_visits.status`, RF-01.2, D2, QA 4, Art. II).
 *
 * Solo hay dos desenlaces posibles y no son simétricos: una visita `COMPLETED` es
 * una carga de producto con la cadena de frío intacta, y una visita
 * `ABORTED_THERMAL_BREACH` es la prueba documental de que la puerta se abrió con
 * la cabina fuera de rango. La segunda no admite una sola unidad cargada
 * (`qty_added = 0` forzoso, `enforce_restock_visit_item_guard()`), exige el
 * protocolo sanitario completo (precinto, fotografía y nota de 20 caracteres
 * reales) y dispara la cuarentena y el ticket `CRITICAL` del SAT.
 *
 * Las respuestas derivadas (`allowsLoading`, `requiresSanitaryProtocol`) viven
 * aquí para que el servicio, el validador y el frontend lean la misma matriz y
 * ninguna capa vuelva a escribirla a mano.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum RestockVisitStatus: string
{
    case COMPLETED = 'COMPLETED';
    case ABORTED_THERMAL_BREACH = 'ABORTED_THERMAL_BREACH';

    public function label(): string
    {
        return match ($this) {
            self::COMPLETED => 'Completada',
            self::ABORTED_THERMAL_BREACH => 'Abortada por rotura de cadena de frío',
        };
    }

    /**
     * Icono del desenlace en las tablas de Coordinación y el historial del móvil.
     */
    public function icon(): string
    {
        return match ($this) {
            self::COMPLETED => '✅',
            self::ABORTED_THERMAL_BREACH => '🚨',
        };
    }

    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isThermalAbort(): bool
    {
        return $this === self::ABORTED_THERMAL_BREACH;
    }

    /**
     * ¿La visita admite cargar unidades? Solo la completada: en un aborto térmico
     * la máquina queda fuera de rango y el motor rechaza `qty_added > 0`.
     */
    public function allowsLoading(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * ¿Exige el protocolo sanitario de aborto (precinto, foto y nota interna)?
     */
    public function requiresSanitaryProtocol(): bool
    {
        return $this === self::ABORTED_THERMAL_BREACH;
    }

    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $status = self::tryFrom(strtoupper(trim($value)));
        if ($status === null) {
            throw new InvalidArgumentException(sprintf(
                "Estado de visita de reposición no válido: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $status;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
