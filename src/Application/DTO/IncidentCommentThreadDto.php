<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

/**
 * IncidentCommentThreadDto — DTO consolidado del hilo de conversación de una
 * avería para el modal del Módulo 10 (T-COM-01).
 *
 * Agrupa: (1) la cabecera contextual del expediente (RF-01.4: ticket en
 * monospace, máquina, sede, estado operativo y sello de auditoría), (2) los
 * metadatos de paginación por cursor (RF-01.2, RF-01.3: `total_comments`,
 * `loaded_count`, `has_more_before`, `oldest_id`, `latest_id`) y (3) la lista
 * de mensajes ya proyectados y segregados como `IncidentCommentItemDto`
 * (enmascaramiento y exclusión de confidencialidad ya aplicados por el
 * servicio según el perfil del llamante, RNF-01 / Art. V.4).
 *
 * El DTO es agnóstico del rol: la segregación ocurre aguas arriba (servicio,
 * T-COM-03) y este objeto solo consolida la proyección resultante para
 * serializarla con la envolvente canónica de la API.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class IncidentCommentThreadDto implements \JsonSerializable
{
    /**
     * @param array{id: int, ticket_code: string, machine_code: string, machine_model: string, location_name: string, status: string, status_label: string, is_sealed: bool} $incident
     * @param array{total_comments: int, loaded_count: int, has_more_before: bool, oldest_id: ?int, latest_id: ?int} $pagination
     * @param list<IncidentCommentItemDto> $comments
     */
    public function __construct(
        public array $incident,
        public array $pagination,
        public array $comments,
    ) {
    }

    /**
     * Serialización canónica del hilo (plan.md §2.1): cabecera, cursor y lista
     * de mensajes delegando en la serialización segregada de cada
     * `IncidentCommentItemDto`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'incident' => $this->incident,
            'pagination' => $this->pagination,
            'comments' => array_map(
                static fn(IncidentCommentItemDto $comment): array => $comment->toArray(),
                $this->comments
            ),
        ];
    }

    /**
     * Serialización JSON nativa (json_encode) con la misma estructura canónica.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
