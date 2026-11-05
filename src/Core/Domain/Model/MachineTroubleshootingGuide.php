<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;

/**
 * MachineTroubleshootingGuide
 *
 * Entidad inmutable de una guía rápida de diagnóstico por modelo y categoría de
 * avería (Módulo 28, RF-01.1 y RF-04.1).
 *
 * Invariantes que muerde en el borde de la entidad:
 *   1. El modelo se guarda dos veces: la etiqueta visible normalizada (extremos
 *      recortados y espacios repetidos colapsados) y su clave de emparejamiento
 *      en minúsculas (`C5`). La clave no se recibe del cliente: se deriva del
 *      modelo, de modo que no pueden divergir.
 *   2. El título se normaliza igual y no puede quedar vacío; las cotas completas
 *      de longitud, pasos y documentos son responsabilidad de
 *      `TroubleshootingGuideValidator`, que es la puerta única del alta y la
 *      edición.
 *   3. Los pasos, códigos y repuestos se conservan en el orden en que llegan: el
 *      orden de los repuestos lo define Coordinación (`QA 3`) y el de los pasos
 *      es el de comprobación en ruta.
 *
 * La entidad es inmutable: cada cambio devuelve una copia (`withId()`,
 * `withStatus()`), nunca muta la instancia recibida.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class MachineTroubleshootingGuide implements JsonSerializable
{
    /** Cotas de la Tabla 39 que la entidad declara para que nadie las repita a mano. */
    public const MIN_MODEL_LENGTH = 2;

    public const MAX_MODEL_LENGTH = 100;

    public const MIN_TITLE_LENGTH = 5;

    public const MAX_TITLE_LENGTH = 150;

    public const MIN_STEPS = 2;

    public const MAX_STEPS = 10;

    public const MAX_ERROR_CODES = 20;

    public const MAX_SPARE_PARTS = 10;

    private ?int $id;

    private string $machineModel;

    private string $machineModelKey;

    private IncidentCategory $breakdownType;

    private string $title;

    /** @var list<array{code: string, meaning: string}> */
    private array $errorCodes;

    /** @var list<array{step_order: int, title: string, instruction: string}> */
    private array $steps;

    /** @var list<int> */
    private array $recommendedSparePartIds;

    private ?int $suggestedRootCauseId;

    private bool $isActive;

    private ?int $createdBy;

    private ?int $updatedBy;

    private ?string $createdAt;

    private ?string $updatedAt;

    /**
     * @param list<array{code: string, meaning: string}>                          $errorCodes
     * @param list<array{step_order: int, title: string, instruction: string}>    $steps
     * @param list<int>                                                           $recommendedSparePartIds
     */
    public function __construct(
        ?int $id,
        string $machineModel,
        IncidentCategory $breakdownType,
        string $title,
        array $errorCodes = [],
        array $steps = [],
        array $recommendedSparePartIds = [],
        ?int $suggestedRootCauseId = null,
        bool $isActive = true,
        ?int $createdBy = null,
        ?int $updatedBy = null,
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        $normalizedModel = self::normalizeModelName($machineModel);
        if ($normalizedModel === '') {
            throw new InvalidArgumentException(
                'El modelo de máquina de una guía de diagnóstico no puede estar vacío.'
            );
        }

        $this->id = $id;
        $this->machineModel = $normalizedModel;
        $this->machineModelKey = self::normalizeModelKey($normalizedModel);
        $this->breakdownType = $breakdownType;
        $this->title = self::normalizeModelName($title);
        $this->errorCodes = array_values($errorCodes);
        $this->steps = array_values($steps);
        $this->recommendedSparePartIds = array_values(array_map(
            static fn (mixed $partId): int => (int)$partId,
            $recommendedSparePartIds
        ));
        $this->suggestedRootCauseId = $suggestedRootCauseId;
        $this->isActive = $isActive;
        $this->createdBy = $createdBy;
        $this->updatedBy = $updatedBy;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Etiqueta canónica del modelo (C5): recorta extremos y colapsa espacios
     * repetidos a uno solo, sin tocar mayúsculas ni acentos.
     */
    public static function normalizeModelName(string $rawModel): string
    {
        $trimmed = trim($rawModel);
        $collapsed = preg_replace('/\s+/u', ' ', $trimmed);

        return $collapsed ?? $trimmed;
    }

    /**
     * Clave de emparejamiento insensible a mayúsculas y espacios (C5, QA 1).
     */
    public static function normalizeModelKey(string $rawModel): string
    {
        return mb_strtolower(self::normalizeModelName($rawModel), 'UTF-8');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMachineModel(): string
    {
        return $this->machineModel;
    }

    public function getMachineModelKey(): string
    {
        return $this->machineModelKey;
    }

    public function getBreakdownType(): IncidentCategory
    {
        return $this->breakdownType;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** @return list<array{code: string, meaning: string}> */
    public function getErrorCodes(): array
    {
        return $this->errorCodes;
    }

    /** @return list<array{step_order: int, title: string, instruction: string}> */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /** @return list<int> */
    public function getRecommendedSparePartIds(): array
    {
        return $this->recommendedSparePartIds;
    }

    public function getSuggestedRootCauseId(): ?int
    {
        return $this->suggestedRootCauseId;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?int
    {
        return $this->updatedBy;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function withId(int $id): self
    {
        $copy = clone $this;
        $copy->id = $id;

        return $copy;
    }

    public function withStatus(bool $isActive, ?int $updatedBy = null): self
    {
        $copy = clone $this;
        $copy->isActive = $isActive;
        if ($updatedBy !== null) {
            $copy->updatedBy = $updatedBy;
        }

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'machine_model' => $this->machineModel,
            'machine_model_key' => $this->machineModelKey,
            'breakdown_type' => $this->breakdownType->value,
            'title' => $this->title,
            'error_codes' => $this->errorCodes,
            'steps' => $this->steps,
            'recommended_spare_part_ids' => $this->recommendedSparePartIds,
            'suggested_root_cause_id' => $this->suggestedRootCauseId,
            'is_active' => $this->isActive,
            'created_by' => $this->createdBy,
            'updated_by' => $this->updatedBy,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
