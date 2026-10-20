<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\ValueObject\ZoneAffinityType;

/**
 * TechnicianWorkloadScorer
 *
 * Política **pura** de idoneidad de un técnico para una avería concreta (RF-04.1,
 * Módulo 17). No consulta la base de datos, no escribe nada y no depende del reloj:
 * recibe una foto del estado operativo y devuelve una puntuación determinista. Dos
 * llamadas con el mismo contexto dan siempre el mismo número, que es lo que hace
 * reproducible el desempate y certificable el recomendador (RNF-01).
 *
 * Las reglas, tal y como las fija el contrato (§4 del contrato técnico):
 *
 *   1. **Afinidad territorial.** Titular de la zona de la sede: +50. Suplente: +25.
 *      Sin relación con la zona: 0.
 *   2. **Presencia in situ.** Parada viva (`ASSIGNED` o `IN_PROGRESS`) en la misma
 *      sede averiada: +40 (atención inmediata sin desplazamiento).
 *   3. **Proximidad de zona.** Paradas vivas hoy en otras sedes de la misma zona:
 *      +20, **una sola vez**. Es **excluyente** con la presencia in situ: estar en
 *      la sede no suma además el bono de zona (Resolución QA 12).
 *   4. **Incompatibilidad entre sedes.** `IN_PROGRESS` abierto en otra sede: -15.
 *      No bloquea la asignación, pero el coordinador debe saber que el técnico
 *      tendrá que pausar o cerrar antes de empezar (RF-04.1.3, Módulo 16).
 *   5. **Carga operativa viva.** -5 por cada avería activa (`ASSIGNED`,
 *      `IN_PROGRESS`, `PENDING_PARTS`) o preventivo de hoy (`IN_INSPECTION`,
 *      `SCHEDULED`), y -2 por cada avería pausada por falta de acceso
 *      (`PENDING_INFO`), que no exige dedicación física inmediata (QA 3).
 *   6. **Salvaguarda de frío.** +100 al técnico de guardia (`is_on_call`) cuando la
 *      avería es de cadena de frío o crítica **y** el escalado del Art. II está
 *      activo, es decir, cuando ningún titular activo de la zona está libre de
 *      paradas previas (RF-05.1, QA 1 y QA 9). El escalado lo decide el caso de uso
 *      porque necesita el cuadrante completo; este scorer solo aplica el bono al
 *      técnico que efectivamente está de guardia.
 *
 * El contexto es un array asociativo con estas claves (todas opcionales; el
 * escenario vacío puntúa 0):
 *
 *   - `affinity` (ZoneAffinityType|string|null): relación con la zona de la sede.
 *   - `has_live_stop_at_target_location` (bool): parada viva en la sede averiada.
 *   - `live_stops_in_zone_other_locations` (int): paradas vivas en otras sedes de la zona.
 *   - `has_in_progress_elsewhere` (bool): intervención en curso en otra sede.
 *   - `active_incidents` (int): averías activas asignadas al técnico.
 *   - `pending_info_incidents` (int): averías pausadas por falta de acceso.
 *   - `preventive_orders_today` (int): preventivos de hoy asignados.
 *   - `is_cold_chain_risk` (bool): avería perecedera o `CRITICAL`.
 *   - `cold_chain_escalation_active` (bool): ningún titular activo libre en la zona.
 *   - `is_on_call` (bool): técnico de guardia 24/7 (`users.is_on_call`).
 *
 * Los contadores negativos y las afinidades desconocidas se rechazan en voz alta:
 * un dato imposible no debe producir una puntuación plausible y silenciosa.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class TechnicianWorkloadScorer
{
    /** Titular principal de la zona de la sede. */
    public const TERRITORIAL_PRIMARY_POINTS = 50;

    /** Suplente de la zona de la sede. */
    public const TERRITORIAL_BACKUP_POINTS = 25;

    /** Parada viva en la misma sede averiada (atención sin desplazamiento). */
    public const ONSITE_PRESENCE_POINTS = 40;

    /** Paradas vivas en otras sedes de la misma zona (excluyente con in situ). */
    public const SAME_ZONE_STOPS_POINTS = 20;

    /** Intervención en curso en una sede distinta. */
    public const INTER_LOCATION_PENALTY = -15;

    /** Cada avería activa o preventivo de hoy en la hoja de ruta. */
    public const ACTIVE_WORKLOAD_PENALTY = -5;

    /** Cada avería pausada por falta de acceso (`PENDING_INFO`). */
    public const PENDING_INFO_PENALTY = -2;

    /** Guardia 24/7 durante un escalado de cadena de frío (Art. II). */
    public const COLD_CHAIN_ON_CALL_BONUS = 100;

    /** Claves del desglose que consume el contrato de la API (§3.6). */
    public const BREAKDOWN_KEYS = [
        'territorial_affinity',
        'onsite_presence',
        'same_zone_stops',
        'active_workload_penalty',
        'inter_location_penalty',
        'cold_chain_on_call_bonus',
    ];

    /**
     * Puntuación total de idoneidad del técnico.
     *
     * Es la suma exacta de {@see computeBreakdown()}: no hay una segunda fórmula
     * que pueda divergir del desglose que ve el coordinador.
     *
     * @param array<string, mixed> $context Foto del estado operativo del técnico.
     *
     * @throws InvalidArgumentException Si un contador es negativo o la afinidad no existe.
     */
    public static function computeScore(array $context): int
    {
        return array_sum(self::computeBreakdown($context));
    }

    /**
     * Desglose de la puntuación por componente, con las claves del contrato.
     *
     * @param array<string, mixed> $context Foto del estado operativo del técnico.
     * @return array{
     *     territorial_affinity: int,
     *     onsite_presence: int,
     *     same_zone_stops: int,
     *     active_workload_penalty: int,
     *     inter_location_penalty: int,
     *     cold_chain_on_call_bonus: int
     * }
     *
     * @throws InvalidArgumentException Si un contador es negativo o la afinidad no existe.
     */
    public static function computeBreakdown(array $context): array
    {
        $affinity = self::readAffinity($context['affinity'] ?? null);
        $hasOnsiteStop = self::readFlag($context, 'has_live_stop_at_target_location');
        $sameZoneStops = self::readCounter($context, 'live_stops_in_zone_other_locations');
        $hasInProgressElsewhere = self::readFlag($context, 'has_in_progress_elsewhere');
        $activeIncidents = self::readCounter($context, 'active_incidents');
        $pendingInfoIncidents = self::readCounter($context, 'pending_info_incidents');
        $preventiveOrdersToday = self::readCounter($context, 'preventive_orders_today');
        $isColdChainRisk = self::readFlag($context, 'is_cold_chain_risk');
        $escalationActive = self::readFlag($context, 'cold_chain_escalation_active');
        $isOnCall = self::readFlag($context, 'is_on_call');

        return [
            'territorial_affinity' => self::territorialPoints($affinity),
            'onsite_presence' => $hasOnsiteStop ? self::ONSITE_PRESENCE_POINTS : 0,
            // Excluyente con la presencia in situ (QA 12): el bono de zona premia
            // estar cerca, y quien ya está en la sede no necesita acercarse.
            'same_zone_stops' => (!$hasOnsiteStop && $sameZoneStops > 0) ? self::SAME_ZONE_STOPS_POINTS : 0,
            'active_workload_penalty' => self::ACTIVE_WORKLOAD_PENALTY * ($activeIncidents + $preventiveOrdersToday)
                + self::PENDING_INFO_PENALTY * $pendingInfoIncidents,
            'inter_location_penalty' => $hasInProgressElsewhere ? self::INTER_LOCATION_PENALTY : 0,
            'cold_chain_on_call_bonus' => ($isColdChainRisk && $escalationActive && $isOnCall)
                ? self::COLD_CHAIN_ON_CALL_BONUS
                : 0,
        ];
    }

    /**
     * Puntos de la afinidad territorial declarada (RF-04.1.1).
     */
    public static function territorialPoints(ZoneAffinityType $affinity): int
    {
        return match ($affinity) {
            ZoneAffinityType::PRIMARY => self::TERRITORIAL_PRIMARY_POINTS,
            ZoneAffinityType::BACKUP => self::TERRITORIAL_BACKUP_POINTS,
            ZoneAffinityType::NONE => 0,
        };
    }

    /**
     * Normaliza la afinidad: ausente o nula es «sin zona»; una cadena se convierte
     * con el vocabulario del enumerado y falla si no pertenece a él.
     */
    private static function readAffinity(mixed $value): ZoneAffinityType
    {
        if ($value === null) {
            return ZoneAffinityType::NONE;
        }

        if ($value instanceof ZoneAffinityType) {
            return $value;
        }

        return ZoneAffinityType::fromString((string)$value);
    }

    /**
     * Booleano tolerante a la forma del driver (`t`/`f`/`1`/`0`), pero no a un
     * texto arbitrario: un flag mal escrito no debe convertirse en `true`.
     *
     * @param array<string, mixed> $context
     */
    private static function readFlag(array $context, string $key): bool
    {
        $value = $context[$key] ?? false;

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['1', 't', 'true', 'y', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'f', 'false', 'n', 'no', 'off', ''], true)) {
            return false;
        }

        throw new InvalidArgumentException("El indicador «{$key}» no es un booleano válido.");
    }

    /**
     * Contador entero no negativo; acepta la forma numérica que devuelve el motor.
     *
     * @param array<string, mixed> $context
     */
    private static function readCounter(array $context, string $key): int
    {
        $value = $context[$key] ?? 0;

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int)$value;
        }

        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException("El contador «{$key}» debe ser un entero no negativo.");
        }

        return $value;
    }
}
