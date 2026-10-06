<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

/**
 * IncidentCommentItemDto — Proyección inmutable de un mensaje individual del hilo
 * de conversación de una avería (Módulo 10, T-COM-01).
 *
 * Modela la proyección segura de un comentario según el perfil del llamante
 * (RF-02.1, RF-02.2, RF-02.3, RNF-01 · Art. V.4 de la Constitución):
 *
 * - Para TÉCNICO y COORDINADOR: exposición nominal completa y del indicador
 *   `is_internal` (candado "Nota Interna de Taller (Confidencial)", RF-02.4).
 * - Para SEDE (SITE_MANAGER): proyección con `isInternal = null` — el campo
 *   `is_internal` NO se serializa en `toArray()`/`jsonSerialize()`, de modo que
 *   la respuesta JSON no contiene el campo ni metadato alguno que permita
 *   deducir la existencia de notas internas (RNF-01). El enmascaramiento del
 *   nombre del técnico ("Servicio Técnico Oficial (Operador #XX)") lo aplica
 *   el servicio (T-COM-03) antes de construir este DTO.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class IncidentCommentItemDto implements \JsonSerializable
{
    public function __construct(
        public int $id,
        public string $authorType,
        public string $authorName,
        public string $commentText,
        public ?string $photoUrl,
        public ?string $createdAt,
        public bool $isOwnMessage,
        /**
         * Clasificación de confidencialidad del mensaje. Solo es significativa
         * para técnicos y coordinadores; ante la Sede vale `null` y se omite
         * completamente de la serialización (RNF-01 / Art. V.4).
         */
        public ?bool $isInternal = null,
    ) {
    }

    /**
     * Claves de confidencialidad excluidas de la serialización pública ante la Sede.
     *
     * @return list<string>
     */
    public static function siteExcludedKeys(): array
    {
        return ['is_internal'];
    }

    /**
     * Serialización canónica camelCase → snake_case del contrato API (plan.md §2.1).
     *
     * Ante la Sede (`isInternal === null`) el campo `is_internal` se OMITE por
     * completo del payload: ni valor nulo, ni metadato, ni indicio (RNF-01).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'author_type' => $this->authorType,
            'author_name' => $this->authorName,
            'comment_text' => $this->commentText,
            'photo_url' => $this->photoUrl,
            'created_at' => $this->createdAt,
            'is_own_message' => $this->isOwnMessage,
        ];

        // Segregación absoluta en servidor (Art. V.4 / RNF-01): el campo de
        // confidencialidad solo viaja si la proyección lo permite.
        if ($this->isInternal !== null) {
            $payload['is_internal'] = $this->isInternal;
        }

        return $payload;
    }

    /**
     * Serialización JSON nativa (json_encode) con la misma regla de omisión.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
