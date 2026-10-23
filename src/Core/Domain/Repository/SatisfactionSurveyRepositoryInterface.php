<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Application\DTO\CsatReportDto;
use VendGuard\Core\Domain\Model\IncidentSatisfactionSurvey;
use VendGuard\Core\Domain\Model\MetricFilter;

/**
 * SatisfactionSurveyRepositoryInterface
 *
 * Contrato de persistencia de las valoraciones de sede (Módulo 20).
 *
 * No declara `update` ni `delete`: la respuesta es inmutable y el motor lo
 * impone con disparadores (Art. III.1, RD-06). Tampoco declara consultas de
 * técnico: el Módulo 20 no tiene superficie para el técnico (R2).
 *
 * `create()` devuelve `null` en vez de lanzar cuando el índice único
 * `uq_survey_episode` decide una carrera: el servicio lo traduce a `409`, y así
 * la violación del motor nunca llega al cliente como `500` (RF-02.7).
 */
interface SatisfactionSurveyRepositoryInterface
{
    /**
     * Busca la valoración de un episodio concreto.
     *
     * La pareja `(incident_id, resolution_at)` —y no solo la avería— es lo que
     * distingue la valoración vigente de las históricas tras una reapertura
     * (RD-01). Se invoca dentro de la transacción del alta, con el expediente
     * bloqueado.
     */
    public function findEpisode(int $incidentId, string $resolutionAt): ?IncidentSatisfactionSurvey;

    /**
     * Inserta la valoración y devuelve la fila con su identidad.
     *
     * `ON CONFLICT (incident_id, resolution_at) DO NOTHING`: si dos peticiones
     * superan la comprobación previa, solo una escribe y la otra recibe `null`
     * (RF-02.7).
     */
    public function create(IncidentSatisfactionSurvey $survey): ?IncidentSatisfactionSurvey;

    /**
     * Episodios pendientes de valorar de una sede (contratos §3.2).
     *
     * Solo incluye averías `RESOLVED`/`CLOSED` con `resolved_at` no nulo y sin
     * fila de encuesta para la pareja vigente. Orden `resolved_at DESC,
     * incident_id DESC`.
     *
     * @return list<array{
     *     incident_id: int,
     *     ticket_code: string,
     *     machine_code: string,
     *     machine_model: string|null,
     *     resolved_at: string
     * }>
     */
    public function findPendingByLocation(int $locationId, int $limit = 20): array;

    /**
     * Total real de episodios pendientes de la sede, sin tope (R3).
     */
    public function countPendingByLocation(int $locationId): int;

    /**
     * Agregado CSAT del periodo y los filtros declarados (contratos §6).
     *
     * Sin respuestas devuelve `average = null` y `responses = 0`: la falta de
     * valoración nunca puntúa como cero (RD-07).
     */
    public function aggregate(MetricFilter $filter): CsatReportDto;
}
