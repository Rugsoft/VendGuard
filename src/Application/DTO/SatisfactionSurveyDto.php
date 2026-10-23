<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use JsonSerializable;
use VendGuard\Core\Domain\Model\IncidentSatisfactionSurvey;

/**
 * SatisfactionSurveyDto — respuesta de la valoración recién enviada (Módulo 20).
 *
 * Es la proyección que lee la sede: la nota y el comentario que acaba de enviar,
 * con el ticket y la fecha. **No** incluye `technician_id` ni el nombre del
 * técnico: la sede no puede ver quién intervino (R2, contratos §8).
 *
 * `ticket_code` viaja desde el expediente que el servicio ya releyó bajo bloqueo,
 * de modo que el repositorio no necesita un `JOIN` para componer la respuesta.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class SatisfactionSurveyDto implements JsonSerializable
{
    public function __construct(
        public int $id,
        public int $incidentId,
        public string $ticketCode,
        public int $rating,
        public ?string $comment,
        public string $createdAt,
    ) {
    }

    /**
     * Proyecta la entidad persistida con el código funcional del expediente.
     */
    public static function fromEntity(IncidentSatisfactionSurvey $survey, string $ticketCode): self
    {
        return new self(
            id: (int)$survey->getId(),
            incidentId: $survey->getIncidentId(),
            ticketCode: $ticketCode,
            rating: $survey->getRating(),
            comment: $survey->getComment(),
            createdAt: (string)$survey->getCreatedAt(),
        );
    }

    /**
     * @return array{
     *     id: int,
     *     incident_id: int,
     *     ticket_code: string,
     *     rating: int,
     *     comment: string|null,
     *     created_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incidentId,
            'ticket_code' => $this->ticketCode,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'created_at' => $this->createdAt,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
