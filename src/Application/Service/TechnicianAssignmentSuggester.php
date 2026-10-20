<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use VendGuard\Application\DTO\SuggestedTechnicianDto;
use VendGuard\Core\Domain\ValueObject\ZoneAffinityType;
use VendGuard\Core\Service\TechnicianWorkloadScorer;

/**
 * TechnicianAssignmentSuggester
 *
 * Caso de uso de la sugerencia asistida de asignación (RF-04, RF-05, RF-06,
 * Módulo 17). Recibe una **foto** del estado operativo —la avería, los técnicos y
 * la base central— y devuelve el ranking explicado que el coordinador ve en el
 * panel de triaje. No escribe nada y no lee el reloj: dos llamadas con la misma
 * foto producen exactamente el mismo ranking (RNF-01).
 *
 * La foto se compone fuera, en el controlador, con los repositorios del módulo; el
 * caso de uso se concentra en decidir. Así el algoritmo se certifica sin base de
 * datos y el panel recibe siempre la misma forma.
 *
 * ORDEN DE DECISIÓN (y por qué no es solo la puntuación)
 *
 *   1. **Escalado sanitario (Art. II, RF-05.1, QA 1 y QA 9).** Si la avería es de
 *      frío o crítica y **ningún titular activo de la zona está libre** de paradas
 *      previas, el técnico de guardia 24/7 va delante de todos, aunque su
 *      puntuación sea menor: el mandato constitucional es anteponerlo, no premiarlo
 *      lo suficiente como para que gane «casi siempre». El `+100` del desglose
 *      explica el motivo; la precedencia lo garantiza.
 *   2. **Sin guardia disponible (QA 9).** Cuando el escalado está activo pero no hay
 *      ningún técnico de guardia, manda la menor carga viva y, a igual carga, la
 *      mayor proximidad Haversine. Es la alternativa que fija la resolución, aunque
 *      un titular puntúe más por afinidad territorial.
 *   3. **Zona desatendida (RF-06.1, QA 5 y QA 14).** Sin titulares ni suplentes
 *      activos —o con todo el cuadrante en el cupo de {@see self::MAX_LIVE_STOPS_PER_TECHNICIAN}
 *      paradas vivas— no hay afinidad que premie: el ranking se ordena por
 *      proximidad Haversine y, a igual distancia, por puntuación (menor carga). El
 *      técnico en el cupo sigue listado y asignable: el cupo cambia el orden y la
 *      advertencia, nunca bloquea (RF-06.3).
 *   4. **Caso ordinario (RF-04.2).** Puntuación descendente y, en caso de empate,
 *      mayor tiempo transcurrido desde la última asignación formal
 *      (`assigned_at` ascendente; sin asignaciones, `epoch`). El identificador
 *      ascendente cierra el empate para que el orden sea reproducible.
 *
 * La distancia usa el mismo Haversine del Módulo 12 (`GeoDistanceService`) sobre la
 * última posición **conocida y confirmada** del técnico —parada en curso, última
 * completada o Base Central—, nunca sobre un GPS en vivo (Art. V.4, QA 5). Una
 * coordenada inválida no rompe el ranking: computa {@see self::FALLBACK_DISTANCE_KM}
 * km y el desempate cae en la carga de trabajo.
 *
 * Un técnico inactivo o de baja se excluye del ranking (RF-04.1.5, RF-02.2): su
 * vinculación histórica se conserva, pero no se le puede enviar.
 *
 * FORMA DE LA FOTO
 *
 *   $incident = [
 *     'id' => 1042, 'ticket_code' => 'INC-...', 'priority' => 'CRITICAL',
 *     'is_cold_chain_risk' => true,            // opcional; se deriva si falta
 *     'machine_type' => 'COMBO',               // opcional; refuerza el riesgo de frío
 *     'location' => ['id'=>4,'site_code'=>'...','name'=>'...','postal_code'=>'08005',
 *                    'zone_id'=>1,'zone_name'=>'...','latitude'=>41.38,'longitude'=>2.19],
 *   ];
 *   $technicians = [[
 *     'technician_id'=>7,'name'=>'...','operator_code'=>'OP-774',
 *     'is_on_call'=>true,'is_active'=>true,'affinity'=>'PRIMARY',
 *     'active_incidents'=>2,'pending_info_incidents'=>0,'preventive_orders_today'=>0,
 *     'has_live_stop_at_target_location'=>false,'live_stops_in_zone_other_locations'=>1,
 *     'has_in_progress_elsewhere'=>false,      // opcional; se deriva si falta
 *     'last_assigned_at'=>'2026-10-20T08:45:00+02:00',
 *     'in_progress_location'=>['location_id'=>9,'location_name'=>'...','latitude'=>41.4,'longitude'=>2.2],
 *     'last_completed_location'=>['location_id'=>3,'location_name'=>'...','latitude'=>41.39,'longitude'=>2.18],
 *   ]];
 *   $options = ['base_latitude'=>41.3874,'base_longitude'=>2.1701];
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class TechnicianAssignmentSuggester
{
    /** Distancia neutra penalizada para coordenadas inválidas (QA 5). */
    public const FALLBACK_DISTANCE_KM = 999.0;

    /** Marca de referencia de un técnico sin asignaciones formales (QA 5). */
    public const NO_PRIOR_ASSIGNMENT_EPOCH = '1970-01-01T00:00:00+00:00';

    /** Insignia del técnico que encabeza el ranking. */
    public const BADGE_RECOMMENDED = 'RECOMENDADO';

    /** Insignia del técnico de guardia 24/7. */
    public const BADGE_ON_CALL = 'GUARDIA_24_7';

    /** Insignia del titular de la zona de la sede. */
    public const BADGE_PRIMARY_ZONE = 'TITULAR_ZONA';

    /** Insignia del suplente de la zona de la sede. */
    public const BADGE_BACKUP_ZONE = 'SUPLENTE_ZONA';

    /** Insignia de incompatibilidad territorial visible (RF-04.1.3). */
    public const BADGE_IN_PROGRESS_ELSEWHERE = 'EN_CURSO_OTRA_SEDE';

    /**
     * Cupo de paradas vivas por técnico para considerar desatendida la zona
     * (RF-06.1, Resolución QA 14).
     *
     * Es una constante de dominio y no configuración persistida: el cálculo tiene
     * que ser determinista y no depender de una tabla de ajustes que el MVP no
     * tiene. Una parada viva es una avería activa, una pausa por falta de acceso o
     * un preventivo de hoy —la misma carga con la que puntúa el scorer (QA 3)—.
     */
    public const MAX_LIVE_STOPS_PER_TECHNICIAN = 10;

    private GeoDistanceService $geoDistanceService;

    public function __construct(?GeoDistanceService $geoDistanceService = null)
    {
        $this->geoDistanceService = $geoDistanceService ?? new GeoDistanceService();
    }

    /**
     * Ranking explicado de técnicos para una avería (RF-04.1, RF-05.1, RF-06.1).
     *
     * @param array<string, mixed> $incident Avería con su sede.
     * @param array<int, array<string, mixed>> $technicians Candidatos con su estado operativo.
     * @param array<string, mixed> $options Base central (`base_latitude`, `base_longitude`).
     * @return SuggestedTechnicianDto Ranking con la recomendación y su explicación.
     *
     * @throws InvalidArgumentException Si la foto no describe una avería o unos
     *                                  contadores válidos.
     */
    public function getSuggestionsForIncident(array $incident, array $technicians, array $options = []): SuggestedTechnicianDto
    {
        $location = self::readLocation($incident['location'] ?? null, 'location');
        $isColdChainRisk = self::readColdChainRisk($incident);
        $base = self::readBase((string)($options['base_latitude'] ?? ''), (string)($options['base_longitude'] ?? ''));

        $candidates = [];
        foreach (array_values($technicians) as $index => $technician) {
            if (!is_array($technician)) {
                throw new InvalidArgumentException(
                    sprintf('El candidato en la posición %d no es una foto de técnico válida.', $index + 1)
                );
            }

            // Un técnico inactivo no se puede enviar: su vinculación sigue viva para
            // el histórico, pero no entra en el ranking (RF-04.1.5).
            if (!self::readFlag($technician, 'is_active', true)) {
                continue;
            }

            $candidates[] = self::normalizeTechnician($technician, $location);
        }

        $primaries = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['affinity'] === ZoneAffinityType::PRIMARY
        ));

        $coldChainEscalation = $isColdChainRisk && self::noPrimaryFree($primaries);

        // RF-06.1 / QA 14: la zona se queda sin nadie a quien enviar cuando no hay
        // cuadrante vivo o cuando todo él ha agotado su cupo. El profesional en el
        // cupo no se excluye del ranking: sigue asignable, solo deja de contar como
        // cobertura de la zona.
        $zoneTechnicians = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['affinity'] !== ZoneAffinityType::NONE
        ));
        $freeZoneTechnicians = array_filter(
            $zoneTechnicians,
            static fn (array $candidate): bool => self::liveStops($candidate) < self::MAX_LIVE_STOPS_PER_TECHNICIAN
        );
        $unattendedZone = $freeZoneTechnicians === [];
        $zoneAtCapacity = $unattendedZone && $zoneTechnicians !== [];

        $hasOnCall = array_filter($candidates, static fn (array $candidate): bool => $candidate['is_on_call']) !== [];

        $rows = [];
        foreach ($candidates as $candidate) {
            $breakdown = TechnicianWorkloadScorer::computeBreakdown([
                'affinity' => $candidate['affinity'],
                'has_live_stop_at_target_location' => $candidate['has_live_stop_at_target_location'],
                'live_stops_in_zone_other_locations' => $candidate['live_stops_in_zone_other_locations'],
                'has_in_progress_elsewhere' => $candidate['has_in_progress_elsewhere'],
                'active_incidents' => $candidate['active_incidents'],
                'pending_info_incidents' => $candidate['pending_info_incidents'],
                'preventive_orders_today' => $candidate['preventive_orders_today'],
                'is_cold_chain_risk' => $isColdChainRisk,
                'cold_chain_escalation_active' => $coldChainEscalation,
                'is_on_call' => $candidate['is_on_call'],
            ]);

            $rows[] = [
                'technician' => $candidate,
                'breakdown' => $breakdown,
                'score' => array_sum($breakdown),
                'workload' => $candidate['active_incidents']
                    + $candidate['pending_info_incidents']
                    + $candidate['preventive_orders_today'],
                'distance_km' => $this->referenceDistance($candidate, $location, $base),
            ];
        }

        usort($rows, $this->comparator($coldChainEscalation, $hasOnCall, $unattendedZone));

        $ranked = [];
        foreach ($rows as $index => $row) {
            $ranked[] = $this->projectRow($row, $index === 0);
        }

        $top = $ranked[0] ?? null;

        return new SuggestedTechnicianDto(
            [
                'id' => self::readNullableInt($incident, 'id'),
                'ticket_code' => isset($incident['ticket_code']) ? (string)$incident['ticket_code'] : null,
                'priority' => self::readPriority($incident),
                'is_cold_chain_risk' => $isColdChainRisk,
                'location' => $location,
            ],
            [
                'top_technician_id' => $top['technician_id'] ?? null,
                'is_cold_chain_escalation' => $coldChainEscalation,
                'unattended_zone' => $unattendedZone,
                'explanation' => $this->explain($coldChainEscalation, $hasOnCall, $unattendedZone, $top, $zoneAtCapacity),
            ],
            $ranked
        );
    }

    /**
     * Comparador determinista del ranking, con el orden de decisión documentado.
     *
     * @return callable(array<string, mixed>, array<string, mixed>): int
     */
    private function comparator(bool $coldChainEscalation, bool $hasOnCall, bool $unattendedZone): callable
    {
        if ($coldChainEscalation && $hasOnCall) {
            // Art. II: la guardia va delante, y dentro de cada grupo manda la
            // puntuación. El `+100` del desglose explica el motivo; esta comparación
            // lo convierte en garantía aunque la carga de la guardia sea alta.
            return static function (array $left, array $right): int {
                return ($right['technician']['is_on_call'] <=> $left['technician']['is_on_call'])
                    ?: ($right['score'] <=> $left['score'])
                    ?: ($left['distance_km'] <=> $right['distance_km'])
                    ?: self::compareLastAssignment($left, $right)
                    ?: ($left['technician']['technician_id'] <=> $right['technician']['technician_id']);
            };
        }

        if ($coldChainEscalation) {
            // QA 9: sin guardia, menor carga viva y, a igual carga, la más próxima.
            return static function (array $left, array $right): int {
                return ($left['workload'] <=> $right['workload'])
                    ?: ($left['distance_km'] <=> $right['distance_km'])
                    ?: ($right['score'] <=> $left['score'])
                    ?: self::compareLastAssignment($left, $right)
                    ?: ($left['technician']['technician_id'] <=> $right['technician']['technician_id']);
            };
        }

        if ($unattendedZone) {
            // RF-06.1: sin cuadrante propio, proximidad y después carga.
            return static function (array $left, array $right): int {
                return ($left['distance_km'] <=> $right['distance_km'])
                    ?: ($right['score'] <=> $left['score'])
                    ?: self::compareLastAssignment($left, $right)
                    ?: ($left['technician']['technician_id'] <=> $right['technician']['technician_id']);
            };
        }

        return static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score'])
                ?: self::compareLastAssignment($left, $right)
                ?: ($left['technician']['technician_id'] <=> $right['technician']['technician_id']);
        };
    }

    /**
     * Desempate por mayor tiempo sin asignación formal (RF-04.2, QA 5).
     *
     * Un técnico sin asignaciones registradas se trata como asignado en `epoch`, de
     * modo que es el primero en recibir trabajo y la fatiga se reparte.
     */
    private static function compareLastAssignment(array $left, array $right): int
    {
        return $left['technician']['last_assigned_at'] <=> $right['technician']['last_assigned_at'];
    }

    /**
     * Proyección de una fila al contrato del ranking (§3.6).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function projectRow(array $row, bool $isTop): array
    {
        $technician = $row['technician'];
        $affinity = $technician['affinity'];

        $badges = [];
        if ($isTop) {
            $badges[] = self::BADGE_RECOMMENDED;
        }
        if ($technician['is_on_call']) {
            $badges[] = self::BADGE_ON_CALL;
        }
        if ($affinity === ZoneAffinityType::PRIMARY) {
            $badges[] = self::BADGE_PRIMARY_ZONE;
        } elseif ($affinity === ZoneAffinityType::BACKUP) {
            $badges[] = self::BADGE_BACKUP_ZONE;
        }
        if ($technician['has_in_progress_elsewhere']) {
            $badges[] = self::BADGE_IN_PROGRESS_ELSEWHERE;
        }

        return [
            'technician_id' => $technician['technician_id'],
            'name' => $technician['name'],
            'operator_code' => $technician['operator_code'],
            'is_on_call' => $technician['is_on_call'],
            'affinity_type' => $affinity->value,
            'zone_affinity_label' => $affinity->label(),
            'total_score' => $row['score'],
            'score_breakdown' => $row['breakdown'],
            'active_workload' => [
                'active_tickets_count' => $technician['active_incidents'],
                'pending_info_count' => $technician['pending_info_incidents'],
                'preventive_orders_count' => $technician['preventive_orders_today'],
            ],
            'current_status' => [
                'has_in_progress_stop' => $technician['in_progress_location'] !== null,
                'in_progress_location_name' => $technician['in_progress_location']['location_name'] ?? null,
                'distance_km' => $row['distance_km'],
            ],
            'is_top_suggestion' => $isTop,
            'badges' => $badges,
        ];
    }

    /**
     * Frase que el panel muestra bajo la recomendación.
     *
     * @param array<string, mixed>|null $top
     */
    private function explain(
        bool $coldChainEscalation,
        bool $hasOnCall,
        bool $unattendedZone,
        ?array $top,
        bool $zoneAtCapacity
    ): string {
        if ($top === null) {
            return '⚠️ No hay técnicos activos disponibles en el parque: el despacho requiere incorporar o reactivar un técnico.';
        }

        if ($coldChainEscalation && $hasOnCall) {
            return sprintf(
                '🚨 Prioridad Sanitaria Art. II: ningún titular activo de la zona está libre de paradas previas; '
                    . 'se antepone al técnico de guardia 24/7 %s para asegurar el SLA < 4 h.',
                $top['name']
            );
        }

        if ($coldChainEscalation) {
            return sprintf(
                '🚨 Prioridad Sanitaria Art. II: sin técnico de guardia disponible, se antepone a %s por menor carga viva '
                    . 'y, a igual carga, por proximidad.',
                $top['name']
            );
        }

        if ($unattendedZone) {
            return sprintf(
                '⚠️ Sin técnicos disponibles en esta zona%s: %s encabeza la lista por proximidad Haversine y menor carga. '
                    . 'La asignación no queda bloqueada.',
                $zoneAtCapacity
                    ? sprintf(' (cuadrante completo en el cupo de %d paradas vivas)', self::MAX_LIVE_STOPS_PER_TECHNICIAN)
                    : '',
                $top['name']
            );
        }

        return sprintf(
            '⭐ Sugerido por Zona: %s (%s) con %d puntos. La sugerencia no es vinculante.',
            $top['name'],
            mb_strtolower($top['zone_affinity_label']),
            $top['total_score']
        );
    }

    /**
     * ¿Ningún titular activo de la zona está libre de paradas previas? (QA 1, QA 9)
     *
     * Sin titulares, la respuesta es «ninguno libre»: el escalado se activa porque no
     * hay nadie del cuadrante que pueda asumir la avería sin cola.
     *
     * @param list<array<string, mixed>> $primaries
     */
    private static function noPrimaryFree(array $primaries): bool
    {
        if ($primaries === []) {
            return true;
        }

        foreach ($primaries as $primary) {
            if (self::liveStops($primary) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Paradas vivas de un candidato: averías activas, pausas por falta de acceso y
     * preventivos de hoy (QA 3, QA 14).
     *
     * @param array<string, mixed> $candidate
     */
    private static function liveStops(array $candidate): int
    {
        return $candidate['active_incidents']
            + $candidate['pending_info_incidents']
            + $candidate['preventive_orders_today'];
    }

    /**
     * Distancia Haversine a la referencia conocida del técnico (QA 5).
     *
     * Se prefiere la parada `IN_PROGRESS`, después la última completada y, si aún no
     * ha empezado ruta, la Base Central. Las coordenadas inválidas de cualquiera de
     * los dos extremos computan 999 km, la penalización neutra de la resolución.
     *
     * @param array<string, mixed> $technician
     * @param array<string, mixed> $location
     * @param array{latitude: float, longitude: float}|null $base
     */
    private function referenceDistance(array $technician, array $location, ?array $base): float
    {
        $reference = $technician['in_progress_location']
            ?? $technician['last_completed_location']
            ?? $base;

        if ($reference === null) {
            return self::FALLBACK_DISTANCE_KM;
        }

        $fromLatitude = $location['latitude'];
        $fromLongitude = $location['longitude'];

        if ($fromLatitude === null || $fromLongitude === null) {
            return self::FALLBACK_DISTANCE_KM;
        }

        $toLatitude = $reference['latitude'] ?? null;
        $toLongitude = $reference['longitude'] ?? null;

        if ($toLatitude === null || $toLongitude === null) {
            return self::FALLBACK_DISTANCE_KM;
        }

        if (
            !$this->geoDistanceService->isInsideOperationalArea($fromLatitude, $fromLongitude)
            || !$this->geoDistanceService->isInsideOperationalArea($toLatitude, $toLongitude)
        ) {
            return self::FALLBACK_DISTANCE_KM;
        }

        return $this->geoDistanceService->calculateDistanceKm($fromLatitude, $fromLongitude, $toLatitude, $toLongitude);
    }

    /**
     * Normaliza un candidato con sus datos operativos y su referencia de distancia.
     *
     * @param array<string, mixed> $technician
     * @param array<string, mixed> $location
     * @return array<string, mixed>
     */
    private static function normalizeTechnician(array $technician, array $location): array
    {
        $technicianId = self::readRequiredInt($technician, 'technician_id');
        $affinity = $technician['affinity'] ?? null;
        $inProgress = self::readOptionalLocation($technician['in_progress_location'] ?? null, 'in_progress_location');

        $hasInProgressElsewhere = array_key_exists('has_in_progress_elsewhere', $technician)
            ? self::readFlag($technician, 'has_in_progress_elsewhere')
            : ($inProgress !== null && $inProgress['location_id'] !== $location['id']);

        return [
            'technician_id' => $technicianId,
            'name' => (string)($technician['name'] ?? ''),
            'operator_code' => isset($technician['operator_code']) ? (string)$technician['operator_code'] : null,
            'is_on_call' => self::readFlag($technician, 'is_on_call'),
            'affinity' => $affinity instanceof ZoneAffinityType
                ? $affinity
                : ZoneAffinityType::fromString($affinity === null ? 'NONE' : (string)$affinity),
            'active_incidents' => self::readCounter($technician, 'active_incidents'),
            'pending_info_incidents' => self::readCounter($technician, 'pending_info_incidents'),
            'preventive_orders_today' => self::readCounter($technician, 'preventive_orders_today'),
            'has_live_stop_at_target_location' => self::readFlag($technician, 'has_live_stop_at_target_location'),
            'live_stops_in_zone_other_locations' => self::readCounter($technician, 'live_stops_in_zone_other_locations'),
            'has_in_progress_elsewhere' => $hasInProgressElsewhere,
            'in_progress_location' => $inProgress,
            'last_completed_location' => self::readOptionalLocation(
                $technician['last_completed_location'] ?? null,
                'last_completed_location'
            ),
            'last_assigned_at' => self::parseTimestamp($technician['last_assigned_at'] ?? null),
        ];
    }

    /**
     * Sede averiada con su zona y coordenadas.
     *
     * @return array<string, mixed>
     */
    private static function readLocation(mixed $location, string $field): array
    {
        if (!is_array($location)) {
            throw new InvalidArgumentException("La avería debe incluir una sede válida en «{$field}».");
        }

        $latitude = self::readCoordinate($location['latitude'] ?? null, $field . '.latitude');
        $longitude = self::readCoordinate($location['longitude'] ?? null, $field . '.longitude');
        $zoneId = $location['zone_id'] ?? null;

        return [
            'id' => isset($location['id']) ? (int)$location['id'] : null,
            'site_code' => isset($location['site_code']) ? (string)$location['site_code'] : null,
            'name' => isset($location['name']) ? (string)$location['name'] : null,
            'postal_code' => isset($location['postal_code']) ? (string)$location['postal_code'] : null,
            'zone_id' => $zoneId !== null ? (int)$zoneId : null,
            'zone_name' => isset($location['zone_name']) ? (string)$location['zone_name'] : null,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    /**
     * Referencia opcional de distancia (parada en curso o última completada).
     *
     * @return array{location_id: int|null, location_name: string|null, latitude: float|null, longitude: float|null}|null
     */
    private static function readOptionalLocation(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException("La referencia «{$field}» debe ser una sede o nula.");
        }

        return [
            'location_id' => isset($value['location_id']) ? (int)$value['location_id'] : null,
            'location_name' => isset($value['location_name']) ? (string)$value['location_name'] : null,
            'latitude' => self::readCoordinate($value['latitude'] ?? null, $field . '.latitude'),
            'longitude' => self::readCoordinate($value['longitude'] ?? null, $field . '.longitude'),
        ];
    }

    /**
     * Riesgo de cadena de frío: declarado o derivado de prioridad y tipo de máquina.
     *
     * @param array<string, mixed> $incident
     */
    private static function readColdChainRisk(array $incident): bool
    {
        if (array_key_exists('is_cold_chain_risk', $incident)) {
            return self::readFlag($incident, 'is_cold_chain_risk');
        }

        $priority = self::readPriority($incident);
        $machineType = mb_strtoupper(trim((string)($incident['machine_type'] ?? '')));

        return $priority === 'CRITICAL' || in_array($machineType, ['PERISHABLE_FOOD', 'COMBO'], true);
    }

    /**
     * @param array<string, mixed> $incident
     */
    private static function readPriority(array $incident): string
    {
        $priority = mb_strtoupper(trim((string)($incident['priority'] ?? 'MEDIUM')));

        return $priority === '' ? 'MEDIUM' : $priority;
    }

    /**
     * Base central opcional; una base incompleta se trata como ausente.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    private static function readBase(string $latitude, string $longitude): ?array
    {
        if (trim($latitude) === '' || trim($longitude) === '') {
            return null;
        }

        return [
            'latitude' => self::readCoordinate($latitude, 'base_latitude'),
            'longitude' => self::readCoordinate($longitude, 'base_longitude'),
        ];
    }

    /**
     * Marca temporal en segundos desde epoch; sin asignaciones previas, `epoch`.
     */
    private static function parseTimestamp(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::timestampsEpoch();
        }

        $raw = (string)$value;

        try {
            return (new DateTimeImmutable($raw))->getTimestamp();
        } catch (Throwable) {
            throw new InvalidArgumentException(
                sprintf("La marca «%s» de la última asignación no es una fecha válida.", $raw)
            );
        }
    }

    /** Epoch como entero, sin depender del huso del proceso. */
    private static function timestampsEpoch(): int
    {
        return (new DateTimeImmutable(self::NO_PRIOR_ASSIGNMENT_EPOCH))->getTimestamp();
    }

    /**
     * Coordenada numérica o `null` cuando no hay dato: el Haversine decide entonces
     * la distancia neutra, nunca una invención.
     */
    private static function readCoordinate(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException("La coordenada «{$field}» no es numérica.");
        }

        return (float)$value;
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function readRequiredInt(array $source, string $key): int
    {
        if (!isset($source[$key]) || !is_numeric($source[$key]) || (int)$source[$key] <= 0) {
            throw new InvalidArgumentException("El candidato debe declarar un «{$key}» entero positivo.");
        }

        return (int)$source[$key];
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function readNullableInt(array $source, string $key): ?int
    {
        return isset($source[$key]) && is_numeric($source[$key]) ? (int)$source[$key] : null;
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function readCounter(array $source, string $key): int
    {
        $value = $source[$key] ?? 0;

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int)$value;
        }

        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException("El contador «{$key}» debe ser un entero no negativo.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function readFlag(array $source, string $key, bool $default = false): bool
    {
        $value = $source[$key] ?? $default;

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
}
