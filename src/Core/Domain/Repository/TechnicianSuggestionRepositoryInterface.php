<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * TechnicianSuggestionRepositoryInterface
 *
 * Puerto de **solo lectura** que entrega la foto operativa del recomendador de
 * asignación (Módulo 17, RF-04 a RF-06). No decide nada: reúne en un único
 * viaje los hechos que `TechnicianAssignmentSuggester` necesita para ponderar y
 * los devuelve con la forma que el caso de uso documenta.
 *
 * POR QUÉ UN PUERTO PROPIO
 *
 * El ranking necesita, para cada técnico, siete contadores y dos referencias de
 * posición que ningún repositorio existente publica juntos: la carga viva, la
 * pausa por sede, los preventivos de hoy, la parada en la sede averiada, las
 * paradas vivas en otras sedes de la misma zona, la última asignación formal y
 * la última posición confirmada. Resolverlo con los puertos actuales obligaría a
 * un viaje por técnico y por concepto —el N+1 que RNF-01 prohíbe— y a que la
 * capa de aplicación conociera el modelo de datos. Aquí el SQL se escribe una vez,
 * se mide una vez y el caso de uso sigue siendo puro y certificable sin motor.
 *
 * Se mantiene **separado** de `UserRepositoryInterface` e `IncidentRepositoryInterface`
 * a propósito: sus dobles de prueba son veinte y el recomendador no forma parte
 * de sus responsabilidades.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface TechnicianSuggestionRepositoryInterface
{
    /**
     * Foto completa del recomendador para una avería.
     *
     * Devuelve `null` cuando la avería no existe o está borrada lógicamente: el
     * consumidor responde `404` sin inventar un ranking vacío, que significaría
     * «hay técnicos pero ninguno sirve».
     *
     * FORMA
     *
     *   [
     *     'incident' => [
     *       'id', 'ticket_code', 'priority', 'machine_type',
     *       'location' => ['id', 'site_code', 'name', 'postal_code',
     *                      'zone_id', 'zone_name', 'latitude', 'longitude'],
     *     ],
     *     'technicians' => [[
     *       'technician_id', 'name', 'operator_code', 'is_on_call', 'is_active',
     *       'affinity', 'active_incidents', 'pending_info_incidents',
     *       'preventive_orders_today', 'has_live_stop_at_target_location',
     *       'live_stops_in_zone_other_locations', 'has_in_progress_elsewhere',
     *       'last_assigned_at', 'in_progress_location', 'last_completed_location',
     *     ], ...],
     *     'options' => ['base_latitude' => float|null, 'base_longitude' => float|null],
     *   ]
     *
     * `in_progress_location` y `last_completed_location` viajan como
     * `['location_id', 'location_name', 'latitude', 'longitude']` o `null`; la
     * base central puede faltar y el caso de uso aplica entonces la distancia
     * neutra de la resolución QA 5.
     *
     * Los técnicos incluyen únicamente usuarios con rol `TECHNICIAN`, activos y no
     * borrados: un técnico de baja se excluye aquí y el caso de uso lo vuelve a
     * excluir si la foto lo declarara inactivo (RF-04.1.5, RF-02.2).
     *
     * @param int $incidentId Avería cuyo despacho se está decidiendo.
     * @return array<string, mixed>|null Foto normalizada, o `null` si la avería no existe.
     */
    public function findSuggestionPhoto(int $incidentId): ?array;
}
