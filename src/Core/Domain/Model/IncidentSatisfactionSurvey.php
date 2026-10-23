<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use VendGuard\Core\Domain\Exception\SatisfactionSurveyValidationException;

/**
 * IncidentSatisfactionSurvey
 *
 * Valoración de la sede sobre un **episodio de resolución** de una avería
 * (Módulo 20): una nota entera de 1 a 5 estrellas y un comentario opcional de
 * hasta 500 caracteres.
 *
 * Es **inmutable por diseño y por base**: no hay setters, la tabla rechaza
 * `UPDATE` y `DELETE` con disparadores y una corrección exige valorar el
 * siguiente episodio (Art. III.1, RD-06).
 *
 * El ancla no es la avería sino la pareja `(incidentId, resolutionAt)`:
 * `resolutionAt` es copia exacta de `incidents.resolved_at`, de modo que una
 * reapertura (`resolved_at = NULL`) no borra la valoración anterior ni la
 * reutiliza para el servicio siguiente (RD-01).
 *
 * La fábrica `create()` valida y normaliza lo que llega del cuerpo HTTP; el
 * constructor y `fromDatabaseRow()` confían en filas ya validadas por el motor.
 * Ese reparto mantiene la regla de negocio fuera del controlador y lejos del
 * repositorio.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class IncidentSatisfactionSurvey
{
    /** Cota del comentario (RD-05, contratos §4.4). */
    public const COMMENT_MAX_LENGTH = 500;

    private function __construct(
        private readonly ?int $id,
        private readonly int $incidentId,
        private readonly int $locationId,
        private readonly ?int $technicianId,
        private readonly ?string $technicianNameSnapshot,
        private readonly string $resolutionAt,
        private readonly int $rating,
        private readonly ?string $comment,
        private readonly ?string $createdAt,
    ) {
    }

    /**
     * Construye una valoración a partir del cuerpo HTTP, validando y normalizando.
     *
     * El orden de validación es el del contrato (§4.2): primero la nota (6) y
     * después el comentario (7), para que un cuerpo con ambos defectos informe
     * del primero que el contrato declara.
     *
     * @param mixed $rating Nota cruda del cuerpo: entero o cadena `/^[1-5]$/` tras `trim`.
     * @param mixed $comment Comentario crudo: cadena, `null` o ausente.
     *
     * @throws SatisfactionSurveyValidationException Si la nota o el comentario no cumplen el contrato.
     */
    public static function create(
        int $incidentId,
        int $locationId,
        ?int $technicianId,
        ?string $technicianNameSnapshot,
        string $resolutionAt,
        mixed $rating,
        mixed $comment = null
    ): self {
        $validatedRating = self::normalizeRating($rating);
        $normalizedComment = self::normalizeComment($comment);

        return new self(
            id: null,
            incidentId: $incidentId,
            locationId: $locationId,
            technicianId: $technicianId,
            technicianNameSnapshot: $technicianNameSnapshot,
            resolutionAt: $resolutionAt,
            rating: $validatedRating,
            comment: $normalizedComment,
            createdAt: null,
        );
    }

    /**
     * Hidrata una fila del motor. No valida: el `CHECK` y el `NOT NULL` ya lo hicieron.
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int)$row['id'] : null,
            incidentId: (int)$row['incident_id'],
            locationId: (int)$row['location_id'],
            technicianId: isset($row['technician_id']) && $row['technician_id'] !== null
                ? (int)$row['technician_id']
                : null,
            technicianNameSnapshot: isset($row['technician_name_snapshot']) && $row['technician_name_snapshot'] !== null
                ? (string)$row['technician_name_snapshot']
                : null,
            resolutionAt: (string)$row['resolution_at'],
            rating: (int)$row['rating'],
            comment: isset($row['comment']) && $row['comment'] !== null ? (string)$row['comment'] : null,
            createdAt: isset($row['created_at']) && $row['created_at'] !== null ? (string)$row['created_at'] : null,
        );
    }

    /**
     * Devuelve una copia con la identidad que asignó el motor tras insertar.
     */
    public function withIdentity(int $id, string $createdAt): self
    {
        return new self(
            id: $id,
            incidentId: $this->incidentId,
            locationId: $this->locationId,
            technicianId: $this->technicianId,
            technicianNameSnapshot: $this->technicianNameSnapshot,
            resolutionAt: $this->resolutionAt,
            rating: $this->rating,
            comment: $this->comment,
            createdAt: $createdAt,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIncidentId(): int
    {
        return $this->incidentId;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function getTechnicianId(): ?int
    {
        return $this->technicianId;
    }

    public function getTechnicianNameSnapshot(): ?string
    {
        return $this->technicianNameSnapshot;
    }

    public function getResolutionAt(): string
    {
        return $this->resolutionAt;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * Nota: entero de 1 a 5 o cadena que lo represente (R9).
     *
     * Un `float` se rechaza aunque su valor sea entero (`4.0`): el contrato solo
     * admite enteros y cadenas de un dígito, y aceptar el `float` abriría la
     * puerta a `4.5` redondeado en silencio.
     *
     * @throws SatisfactionSurveyValidationException
     */
    private static function normalizeRating(mixed $rating): int
    {
        if (is_int($rating)) {
            $value = $rating;
        } elseif (is_string($rating) && preg_match('/^[1-5]$/', trim($rating)) === 1) {
            $value = (int)trim($rating);
        } else {
            throw SatisfactionSurveyValidationException::invalidRating($rating);
        }

        if ($value < 1 || $value > 5) {
            throw SatisfactionSurveyValidationException::invalidRating($rating);
        }

        return $value;
    }

    /**
     * Comentario: cadena, `null` o ausente; recortado en caracteres Unicode.
     *
     * `\p{Z}` cubre el espacio duro (`\u{00A0}`) que `trim()` no recorta; `\s`
     * cubre saltos de línea y tabulaciones. Solo se recortan los extremos: el
     * formato interno de quien escribe se respeta.
     *
     * @throws SatisfactionSurveyValidationException
     */
    private static function normalizeComment(mixed $comment): ?string
    {
        if ($comment === null) {
            return null;
        }

        if (!is_string($comment)) {
            throw SatisfactionSurveyValidationException::commentNotText($comment);
        }

        $normalized = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $comment);

        if ($normalized === null || $normalized === '') {
            return null;
        }

        $length = mb_strlen($normalized, 'UTF-8');

        if ($length > self::COMMENT_MAX_LENGTH) {
            throw SatisfactionSurveyValidationException::commentTooLong($length);
        }

        return $normalized;
    }
}
