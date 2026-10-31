<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;

/**
 * RootCause
 *
 * Entidad inmutable del catálogo maestro de causas raíz (Módulo 25, RF-01).
 *
 * Invariantes que muerde en el borde de la entidad, antes de que la fila llegue
 * al motor:
 *   1. `code` es un identificador canónico (`^[A-Z0-9-]{3,32}$`) y no se
 *      normaliza en silencio a partir de texto libre: se recorta y se pasa a
 *      mayúsculas, que es la forma que el índice único compara (C4, QA 1).
 *   2. `name` exige entre 5 y 120 caracteres reales; `description` admite hasta
 *      500 y un texto en blanco equivale a no declararla.
 *   3. Las soluciones que viajan en el agregado pertenecen siempre a esta causa
 *      (`standard_solutions.root_cause_id`), lo que evita que un alta atómica
 *      cuele una solución de otra rama del catálogo (D1).
 *
 * La entidad es inmutable: cada cambio devuelve una copia (`withId()`,
 * `withSolutions()`), nunca muta la instancia recibida.
 */
final class RootCause implements JsonSerializable
{
    /** Forma canónica del código de causa y de solución (C4, QA 1). */
    public const CODE_PATTERN = '/^[A-Z0-9-]{3,32}$/';

    public const MIN_NAME_LENGTH = 5;

    public const MAX_NAME_LENGTH = 120;

    public const MAX_DESCRIPTION_LENGTH = 500;

    private ?int $id;

    private string $code;

    private IncidentCategory $category;

    private string $name;

    private ?string $description;

    private bool $isActive;

    /** @var StandardSolution[] */
    private array $solutions;

    private ?string $createdAt;

    private ?string $updatedAt;

    /**
     * @param StandardSolution[] $solutions
     */
    public function __construct(
        ?int $id,
        string $code,
        IncidentCategory $category,
        string $name,
        ?string $description = null,
        bool $isActive = true,
        array $solutions = [],
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        $cleanCode = strtoupper(trim($code));
        if (preg_match(self::CODE_PATTERN, $cleanCode) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'El código de la causa raíz debe cumplir el formato ^[A-Z0-9-]{3,32}$; se recibió «%s».',
                $code
            ));
        }

        $cleanName = trim($name);
        $nameLength = mb_strlen($cleanName, 'UTF-8');
        if ($nameLength < self::MIN_NAME_LENGTH || $nameLength > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El nombre de la causa raíz debe contener entre %d y %d caracteres reales; se recibieron %d.',
                self::MIN_NAME_LENGTH,
                self::MAX_NAME_LENGTH,
                $nameLength
            ));
        }

        $cleanDescription = $description !== null ? trim($description) : null;
        if ($cleanDescription === '') {
            $cleanDescription = null;
        }
        if ($cleanDescription !== null && mb_strlen($cleanDescription, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'La descripción de la causa raíz no puede superar los %d caracteres reales.',
                self::MAX_DESCRIPTION_LENGTH
            ));
        }

        foreach ($solutions as $solution) {
            if (!$solution instanceof StandardSolution) {
                throw new InvalidArgumentException(
                    'El agregado de una causa raíz sólo admite soluciones estandarizadas.'
                );
            }
            if ($id !== null && $solution->getRootCauseId() !== $id) {
                throw new InvalidArgumentException(sprintf(
                    'La solución «%s» pertenece a la causa %d y no a la causa %d.',
                    $solution->getCode(),
                    $solution->getRootCauseId(),
                    $id
                ));
            }
        }

        $this->id = $id;
        $this->code = $cleanCode;
        $this->category = $category;
        $this->name = $cleanName;
        $this->description = $cleanDescription;
        $this->isActive = $isActive;
        $this->solutions = array_values($solutions);
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getCategory(): IncidentCategory
    {
        return $this->category;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return StandardSolution[]
     */
    public function getSolutions(): array
    {
        return $this->solutions;
    }

    /**
     * Solución activa marcada como predeterminada, o `null` si la causa no tiene
     * ninguna. La base de datos garantiza que no pueda haber dos (índice único
     * parcial `uq_standard_solutions_default_per_cause`).
     */
    public function getDefaultSolution(): ?StandardSolution
    {
        foreach ($this->solutions as $solution) {
            if ($solution->isDefault() && $solution->isActive()) {
                return $solution;
            }
        }

        return null;
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
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * @param StandardSolution[] $solutions
     */
    public function withSolutions(array $solutions): self
    {
        return new self(
            $this->id,
            $this->code,
            $this->category,
            $this->name,
            $this->description,
            $this->isActive,
            $solutions,
            $this->createdAt,
            $this->updatedAt
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->isActive,
            'default_solution_id' => $this->getDefaultSolution()?->getId(),
            'solutions' => array_map(
                static fn (StandardSolution $solution): array => $solution->toArray(),
                $this->solutions
            ),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
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
