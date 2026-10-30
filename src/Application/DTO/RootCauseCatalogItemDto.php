<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use JsonSerializable;
use VendGuard\Core\Domain\Model\RootCause;
use VendGuard\Core\Domain\Model\StandardSolution;

/**
 * RootCauseCatalogItemDto
 *
 * Proyección JSON de una causa raíz y sus soluciones estandarizadas para el
 * catálogo operativo del técnico, el paquete offline y la administración de
 * Coordinación (Módulo 25, RF-01, RF-02.3).
 *
 * La serialización es una **lista blanca**: sólo viajan los campos que el
 * contrato publica. La categoría se acompaña de su etiqueta legible y la
 * solución predeterminada se expone por identificador para que el selector de
 * 1 toque del técnico no tenga que buscarla en el array (`D1`, `EARS 02.2`).
 *
 * `is_suggested_for_category` lo decide el llamante —el servicio conoce la
 * categoría de la avería y el orden de sugerencia (`D3`)—; el DTO no reordena
 * ni filtra el catálogo.
 */
final class RootCauseCatalogItemDto implements JsonSerializable
{
    private RootCause $rootCause;

    private bool $isSuggestedForCategory;

    public function __construct(RootCause $rootCause, bool $isSuggestedForCategory = false)
    {
        $this->rootCause = $rootCause;
        $this->isSuggestedForCategory = $isSuggestedForCategory;
    }

    public function getRootCause(): RootCause
    {
        return $this->rootCause;
    }

    public function isSuggestedForCategory(): bool
    {
        return $this->isSuggestedForCategory;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->rootCause->getId(),
            'code' => $this->rootCause->getCode(),
            'category' => $this->rootCause->getCategory()->value,
            'category_label' => $this->rootCause->getCategory()->label(),
            'name' => $this->rootCause->getName(),
            'description' => $this->rootCause->getDescription(),
            'is_active' => $this->rootCause->isActive(),
            'is_suggested_for_category' => $this->isSuggestedForCategory,
            'default_solution_id' => $this->rootCause->getDefaultSolution()?->getId(),
            'solutions' => array_map(
                static fn (StandardSolution $solution): array => [
                    'id' => $solution->getId(),
                    'root_cause_id' => $solution->getRootCauseId(),
                    'code' => $solution->getCode(),
                    'name' => $solution->getName(),
                    'is_default' => $solution->isDefault(),
                    'is_active' => $solution->isActive(),
                ],
                $this->rootCause->getSolutions()
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
