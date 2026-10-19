<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\ActiveInterventionConflictException;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

/**
 * OpportunisticCoexistencePolicy — Regla **pura** de coexistencia de
 * intervenciones y de ordenación de la Parada #1 (Módulo 16, RF-02.1, RF-02.2 y
 * Resolución QA 4).
 *
 * Dos reglas complementarias viven aquí porque son la misma pregunta vista desde
 * dos lados: *¿dónde está trabajando el técnico ahora mismo?*
 *
 * 1. **Dentro de la misma sede se puede coexistir.** Varias máquinas `IN_PROGRESS`
 *    (y órdenes preventivas `IN_INSPECTION`) del propio técnico en el mismo
 *    edificio son legítimas: es precisamente el caso de uso de la avería
 *    oportunista. Se agrupan al frente de la ruta y se ordenan por prioridad
 *    estricta: primero las correctivas por urgencia y SLA restante, después las
 *    preventivas.
 * 2. **Entre sedes distintas no se puede coexistir.** Si el técnico dejó una
 *    intervención abierta en otro edificio, abrir trabajo en el actual falsearía
 *    el tiempo real de intervención (Art. III). El servicio debe bloquear con
 *    `409 ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION` hasta que la cierre o pause.
 *
 * La política no consulta nada: recibe las paradas que el repositorio ya trajo y
 * devuelve la decisión. El orden es totalmente determinista —urgencia, SLA, inicio
 * e identificador— para que la tarjeta de ruta no cambie de orden entre dos
 * consultas idénticas y para que el arnés pueda certificarlo sin base de datos.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias, sin I/O, determinista.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class OpportunisticCoexistencePolicy
{
    /** Parada correctiva abierta. */
    public const RECORD_INCIDENT = 'INCIDENT';

    /** Orden de mantenimiento preventivo en inspección. */
    public const RECORD_PREVENTIVE_ORDER = 'PREVENTIVE_ORDER';

    /** Estados de una parada correctiva que cuentan como intervención abierta. */
    public const OPEN_CORRECTIVE_STATUSES = ['IN_PROGRESS'];

    /** Estados de una orden preventiva que cuentan como intervención abierta. */
    public const OPEN_PREVENTIVE_STATUSES = ['IN_INSPECTION'];

    /**
     * Devuelve la intervención abierta que impide operar en `targetLocationId`,
     * o `null` si la coexistencia es posible.
     *
     * Solo cuentan las paradas realmente abiertas (`IN_PROGRESS` / `IN_INSPECTION`)
     * y de **otra** sede. Las filas sin sede atribuible se ignoran: no se puede
     * bloquear a un técnico por un dato que no identifica dónde está el conflicto.
     *
     * Si hubiera varios conflictos, se devuelve el más antiguo por `started_at`
     * (y por `id` como desempate) para que el mensaje sea estable entre llamadas.
     *
     * @param list<array<string, mixed>> $activeStops Paradas del técnico (incidencias y preventivos).
     * @return array<string, mixed>|null
     *
     * @throws InvalidArgumentException Si la sede destino no es un identificador positivo.
     */
    public function findBlockingIntervention(int $targetLocationId, array $activeStops): ?array
    {
        if ($targetLocationId <= 0) {
            throw new InvalidArgumentException('La sede destino debe tener un identificador positivo.');
        }

        $conflicts = [];

        foreach ($activeStops as $stop) {
            if (!is_array($stop) || !$this->isOpenStop($stop)) {
                continue;
            }

            $stopLocationId = $this->stopLocationId($stop);
            if ($stopLocationId === null || $stopLocationId === $targetLocationId) {
                continue;
            }

            $conflicts[] = $this->normalizeStop($stop, $stopLocationId);
        }

        if ($conflicts === []) {
            return null;
        }

        usort($conflicts, static function (array $a, array $b): int {
            return [
                self::timestampKey($a, 'started_at'),
                (int)($a['id'] ?? PHP_INT_MAX),
                (int)$a['location_id'],
            ] <=> [
                self::timestampKey($b, 'started_at'),
                (int)($b['id'] ?? PHP_INT_MAX),
                (int)$b['location_id'],
            ];
        });

        return $conflicts[0];
    }

    /**
     * Puerta de dominio de RF-02.2: lanza la excepción `409` con los datos del
     * expediente abierto en la otra sede.
     *
     * @param list<array<string, mixed>> $activeStops
     *
     * @throws ActiveInterventionConflictException Si hay una intervención abierta en otra sede.
     */
    public function assertNoBlockingIntervention(int $targetLocationId, array $activeStops): void
    {
        $conflict = $this->findBlockingIntervention($targetLocationId, $activeStops);

        if ($conflict !== null) {
            throw new ActiveInterventionConflictException($conflict);
        }
    }

    /**
     * Ordena las intervenciones abiertas de una misma sede para la Parada #1
     * (RF-02.1 y Resolución QA 4).
     *
     * Criterio, en este orden:
     * 1. Correctivas (`IN_PROGRESS`) antes que preventivas (`IN_INSPECTION`).
     * 2. Urgencia descendente (`CRITICAL` > `HIGH` > `MEDIUM` > `LOW`).
     * 3. `sla_target_at` ascendente (sin SLA al final).
     * 4. `started_at` ascendente (sin inicio al final).
     * 5. `id` ascendente como último desempate.
     *
     * La lista recibida no se modifica: se devuelve una copia ordenada. Una
     * urgencia desconocida o ausente se trata como la más baja, nunca como crítica.
     *
     * @param list<array<string, mixed>> $stops
     * @return list<array<string, mixed>>
     */
    public function orderInProgressStops(array $stops): array
    {
        $ordered = array_values(array_filter($stops, 'is_array'));

        usort($ordered, function (array $a, array $b): int {
            return [
                $this->recordRank($a),
                -$this->urgencyRank($a),
                self::timestampKey($a, 'sla_target_at'),
                self::timestampKey($a, 'started_at'),
                (int)($a['id'] ?? PHP_INT_MAX),
            ] <=> [
                $this->recordRank($b),
                -$this->urgencyRank($b),
                self::timestampKey($b, 'sla_target_at'),
                self::timestampKey($b, 'started_at'),
                (int)($b['id'] ?? PHP_INT_MAX),
            ];
        });

        return $ordered;
    }

    /**
     * ¿La fila describe una intervención abierta?
     */
    private function isOpenStop(array $stop): bool
    {
        $status = strtoupper(trim((string)($stop['status'] ?? '')));

        return in_array($status, self::OPEN_CORRECTIVE_STATUSES, true)
            || in_array($status, self::OPEN_PREVENTIVE_STATUSES, true);
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

    /**
     * Completa el conflicto con su tipo y su estado normalizado.
     *
     * @return array<string, mixed>
     */
    private function normalizeStop(array $stop, int $locationId): array
    {
        $status = strtoupper(trim((string)($stop['status'] ?? '')));
        $recordType = strtoupper(trim((string)($stop['record_type'] ?? '')));

        if ($recordType === '') {
            $recordType = $status === 'IN_INSPECTION'
                ? self::RECORD_PREVENTIVE_ORDER
                : self::RECORD_INCIDENT;
        }

        $stop['record_type'] = $recordType;
        $stop['status'] = $status;
        $stop['location_id'] = $locationId;

        return $stop;
    }

    /** Correctiva = 0, preventiva = 1: las correctivas siempre van primero. */
    private function recordRank(array $stop): int
    {
        $status = strtoupper(trim((string)($stop['status'] ?? '')));

        return $status === 'IN_INSPECTION' ? 1 : 0;
    }

    /**
     * Rango de urgencia; una urgencia ausente o desconocida vale 0 (la más baja).
     */
    private function urgencyRank(array $stop): int
    {
        $urgency = UrgencyLevel::tryFrom(strtoupper(trim((string)($stop['urgency'] ?? ''))));

        return $urgency?->priorityRank() ?? 0;
    }

    /**
     * Clave temporal comparable: época en segundos, `PHP_INT_MAX` cuando falta o
     * no es interpretable, para que los nulos queden al final en orden ascendente.
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
