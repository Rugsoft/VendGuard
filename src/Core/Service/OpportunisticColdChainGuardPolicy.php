<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\ColdChainRouteWarningConfirmationException;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

/**
 * OpportunisticColdChainGuardPolicy — Salvaguarda pura de la prioridad sanitaria
 * y de la cadena de frío (Módulo 16, RF-03.1, RF-03.2 y Resolución QA 1;
 * Constitución Art. II).
 *
 * El técnico que está a punto de abrir o asumir una avería oportunista puede tener
 * esperando en **otra sede** una parada que no admite demora. La política decide
 * qué paradas disparan la advertencia destacada antes de dejarle continuar:
 *
 * 1. Cualquier parada pendiente con urgencia `CRITICAL`.
 * 2. Cualquier parada sobre máquina bajo vigilancia sanitaria
 *    (`PERISHABLE_FOOD` o `COMBO`) con fallo térmico (`TEMPERATURE_COLD`) o corte
 *    eléctrico (`ELECTRICAL_OFF`), que es el escenario de pérdida de frío del
 *    Art. II.
 * 3. Cualquier máquina en cuarentena sanitaria (`sanitary_status = 'QUARANTINE'`),
 *    con independencia de su tipología.
 *
 * No bloquea: RF-03.2 quiere que el técnico decida con conocimiento y que, si
 * confirma quedarse, Coordinación reciba la alerta inmediata para reasignar la
 * urgencia. Por eso la política separa **detectar** (`findPendingCriticalStops`)
 * de **exigir confirmación** (`assertNoUnconfirmedWarning`), y el servicio de
 * aplicación decide cuándo encolar la alerta tras la confirmación.
 *
 * Las paradas se filtran por estado pendiente (`ASSIGNED` / `PENDING_INFO`), por
 * sede distinta a la destino y por el criterio sanitario. Solo se consideran las
 * que siguen vivas: un ticket `CLOSED`, `CANCELLED` o `RESOLVED` ya no espera al
 * técnico y no puede retenerlo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias, sin I/O, determinista.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class OpportunisticColdChainGuardPolicy
{
    /** Disparador: urgencia crítica en otra sede (RF-03.1.1). */
    public const TRIGGER_CRITICAL_URGENCY = 'CRITICAL_URGENCY';

    /** Disparador: vigilancia sanitaria de frío en otra sede (RF-03.1.2). */
    public const TRIGGER_COLD_CHAIN_WATCH = 'COLD_CHAIN_WATCH';

    /** Disparador: máquina en cuarentena sanitaria del Módulo 11 (Art. II). */
    public const TRIGGER_SANITARY_QUARANTINE = 'SANITARY_QUARANTINE';

    /** Estados que describen una parada aún pendiente para el técnico. */
    public const PENDING_STOP_STATUSES = ['ASSIGNED', 'PENDING_INFO'];

    /** Tipologías sujetas al reloj biológico de cadena de frío. */
    public const SANITARY_MACHINE_TYPES = ['PERISHABLE_FOOD', 'COMBO'];

    /** Categorías que comprometen la refrigeración de esas tipologías. */
    public const COLD_CHAIN_CATEGORIES = ['TEMPERATURE_COLD', 'ELECTRICAL_OFF'];

    /** Estado de cuarentena sanitaria preventiva (Módulo 11). */
    public const SANITARY_QUARANTINE = 'QUARANTINE';

    /**
     * ¿Esta parada dispara por sí sola la advertencia del Art. II?
     *
     * @param array<string, mixed> $stop
     */
    public function isCriticalOrSanitaryStop(array $stop): bool
    {
        return $this->warningTrigger($stop) !== null;
    }

    /**
     * Motivo por el que la parada dispara la advertencia, o `null` si no la dispara.
     *
     * @param array<string, mixed> $stop
     */
    public function warningTrigger(array $stop): ?string
    {
        if (strtoupper(trim((string)($stop['urgency'] ?? ''))) === UrgencyLevel::CRITICAL->value) {
            return self::TRIGGER_CRITICAL_URGENCY;
        }

        if (strtoupper(trim((string)($stop['sanitary_status'] ?? ''))) === self::SANITARY_QUARANTINE) {
            return self::TRIGGER_SANITARY_QUARANTINE;
        }

        $machineType = strtoupper(trim((string)($stop['machine_type'] ?? '')));
        $category = strtoupper(trim((string)($stop['category'] ?? '')));

        if (in_array($machineType, self::SANITARY_MACHINE_TYPES, true)
            && in_array($category, self::COLD_CHAIN_CATEGORIES, true)
        ) {
            return self::TRIGGER_COLD_CHAIN_WATCH;
        }

        return null;
    }

    /**
     * Paradas pendientes críticas o sanitarias que esperan al técnico en una sede
     * distinta de `targetLocationId`, ordenadas por urgencia y SLA.
     *
     * Cada elemento conserva los datos recibidos y añade:
     *  - `warning_trigger`: motivo de la advertencia (`CRITICAL_URGENCY`,
     *    `COLD_CHAIN_WATCH` o `SANITARY_QUARANTINE`).
     *
     * @param list<array<string, mixed>> $pendingStops Paradas del técnico en su ruta.
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException Si la sede destino no es un identificador positivo.
     */
    public function findPendingCriticalStops(int $targetLocationId, array $pendingStops): array
    {
        if ($targetLocationId <= 0) {
            throw new InvalidArgumentException('La sede destino debe tener un identificador positivo.');
        }

        $flagged = [];

        foreach ($pendingStops as $stop) {
            if (!is_array($stop) || !$this->isPendingStop($stop)) {
                continue;
            }

            $locationId = $this->stopLocationId($stop);
            if ($locationId === null || $locationId === $targetLocationId) {
                continue;
            }

            $trigger = $this->warningTrigger($stop);
            if ($trigger === null) {
                continue;
            }

            $stop['warning_trigger'] = $trigger;
            $stop['location_id'] = $locationId;
            $flagged[] = $stop;
        }

        usort($flagged, static function (array $a, array $b): int {
            return [
                -self::urgencyRank($a),
                self::timestampKey($a, 'sla_target_at'),
                (int)($a['id'] ?? PHP_INT_MAX),
            ] <=> [
                -self::urgencyRank($b),
                self::timestampKey($b, 'sla_target_at'),
                (int)($b['id'] ?? PHP_INT_MAX),
            ];
        });

        return $flagged;
    }

    /**
     * ¿Hay que exigir confirmación expresa antes de continuar en la sede actual?
     *
     * @param list<array<string, mixed>> $pendingStops
     */
    public function requiresConfirmation(int $targetLocationId, array $pendingStops): bool
    {
        return $this->findPendingCriticalStops($targetLocationId, $pendingStops) !== [];
    }

    /**
     * Puerta de dominio de RF-03.1: si hay una emergencia esperando en otra sede y
     * el técnico no ha confirmado expresamente, lanza la excepción `409` con la
     * lista de paradas postergadas para que la interfaz pinte el modal del Art. II.
     *
     * @param list<array<string, mixed>> $pendingStops
     * @return list<array<string, mixed>> Paradas que dispararon la advertencia
     *                                     (vacía si no había ninguna).
     *
     * @throws ColdChainRouteWarningConfirmationException Si falta la confirmación expresa.
     */
    public function assertNoUnconfirmedWarning(
        int $targetLocationId,
        array $pendingStops,
        bool $confirmed
    ): array {
        $flagged = $this->findPendingCriticalStops($targetLocationId, $pendingStops);

        if ($flagged !== [] && !$confirmed) {
            throw new ColdChainRouteWarningConfirmationException($flagged);
        }

        return $flagged;
    }

    /**
     * Copy castellano del disparador, para el banner del modal y los avisos.
     */
    public function warningTriggerLabel(?string $trigger): string
    {
        return match ($trigger) {
            self::TRIGGER_CRITICAL_URGENCY => 'Parada crítica pendiente en otra sede',
            self::TRIGGER_COLD_CHAIN_WATCH => 'Máquina de perecederos con riesgo de cadena de frío en otra sede',
            self::TRIGGER_SANITARY_QUARANTINE => 'Máquina en cuarentena sanitaria en otra sede',
            default => 'Sin riesgo sanitario pendiente en otras sedes',
        };
    }

    /**
     * ¿La parada sigue pendiente para el técnico?
     *
     * Si la fila no declara estado se acepta: el llamador ya la habrá obtenido de
     * la consulta de paradas pendientes. Un estado explícito distinto de
     * `ASSIGNED` / `PENDING_INFO` queda fuera.
     */
    private function isPendingStop(array $stop): bool
    {
        if (!array_key_exists('status', $stop)) {
            return true;
        }

        $status = strtoupper(trim((string)$stop['status']));

        return in_array($status, self::PENDING_STOP_STATUSES, true);
    }

    /**
     * Sede de la parada, admitiendo `location_id` plano o anidado en `location.id`.
     */
    private function stopLocationId(array $stop): ?int
    {
        if (isset($stop['location_id']) && is_numeric($stop['location_id'])) {
            return (int)$stop['location_id'];
        }

        if (isset($stop['location']['id']) && is_numeric($stop['location']['id'])) {
            return (int)$stop['location']['id'];
        }

        return null;
    }

    private static function urgencyRank(array $stop): int
    {
        $urgency = UrgencyLevel::tryFrom(strtoupper(trim((string)($stop['urgency'] ?? ''))));

        return $urgency?->priorityRank() ?? 0;
    }

    /**
     * Clave temporal comparable: época en segundos, `PHP_INT_MAX` cuando falta o
     * no es interpretable, para que los nulos queden al final.
     */
    private static function timestampKey(array $row, string $field): int
    {
        $value = $row[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return PHP_INT_MAX;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? PHP_INT_MAX : $timestamp;
    }
}
