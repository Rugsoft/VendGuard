<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Application\DTO\SuggestedTechnicianDto;
use VendGuard\Core\Domain\Repository\TechnicianSuggestionRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoTechnicianSuggestionRepository;

/**
 * SuggestedTechniciansService
 *
 * Caso de uso de la sugerencia asistida (Módulo 17, RF-04 a RF-06): reúne la foto
 * operativa y la entrega al motor de ponderación.
 *
 * La división de responsabilidades es deliberada y es la que hace medible RNF-01:
 *
 *   - `TechnicianAssignmentSuggester` decide. Es puro, no consulta y no lee el
 *     reloj; por eso su ranking se certifica con pruebas de dominio.
 *   - `TechnicianSuggestionRepositoryInterface` lee. Compone la foto en un número
 *     fijo de viajes, independiente del número de técnicos.
 *   - este servicio **une** ambas cosas y no añade reglas. Si añadiera alguna,
 *     habría dos sitios donde decidir el orden del ranking.
 *
 * Se pide desde dos superficies distintas y por eso se conserva como servicio:
 * el panel de triaje (`GET /suggested-technicians`) y la puerta constitucional de
 * la asignación (`PATCH /assign`), que necesita la misma recomendación para saber
 * si el coordinador está descartando al técnico de guardia. Una sola composición
 * garantiza que el panel y la puerta no puedan discrepar.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class SuggestedTechniciansService
{
    private TechnicianSuggestionRepositoryInterface $repository;

    private TechnicianAssignmentSuggester $suggester;

    public function __construct(
        ?TechnicianSuggestionRepositoryInterface $repository = null,
        ?TechnicianAssignmentSuggester $suggester = null
    ) {
        $this->repository = $repository ?? new PdoTechnicianSuggestionRepository();
        $this->suggester = $suggester ?? new TechnicianAssignmentSuggester();
    }

    /**
     * Ranking explicado de la avería, o `null` si el expediente no existe.
     *
     * @param int $incidentId Avería cuyo despacho se está decidiendo.
     * @param list<int> $excludeTechnicianIds Candidatos que no deben aparecer. La
     *        reasignación por ausencia excluye al titular ausente para que el motor
     *        no lo proponga como su propio suplente.
     * @return SuggestedTechnicianDto|null Ranking listo para publicar (contrato §3.6).
     */
    public function getSuggestions(int $incidentId, array $excludeTechnicianIds = []): ?SuggestedTechnicianDto
    {
        if ($incidentId < 1) {
            return null;
        }

        $photo = $this->repository->findSuggestionPhoto($incidentId);
        if ($photo === null) {
            return null;
        }

        $excluded = array_map('intval', $excludeTechnicianIds);
        $technicians = array_values(array_filter(
            $photo['technicians'],
            static fn (array $technician): bool => !in_array((int)$technician['technician_id'], $excluded, true)
        ));

        return $this->suggester->getSuggestionsForIncident(
            $photo['incident'],
            $technicians,
            $photo['options'] ?? []
        );
    }
}
