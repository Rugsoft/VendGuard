<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use VendGuard\Core\Domain\ValueObject\WorkshopRepairStatus;

/**
 * WorkshopTrayQueryDTO — filtros inmutables de la bandeja de taller (Módulo 27, RF-04).
 *
 * El DTO normaliza lo que llega por `$_GET` antes de que lo vea el repositorio:
 * estados y categorías en mayúsculas, búsqueda recortada, identificadores
 * positivos y paginación acotada. Nada de eso puede llegar a SQL en crudo, y el
 * repositorio puede confiar en los tipos sin repetir las comprobaciones.
 *
 * Dos decisiones que no son cosméticas:
 *
 *   1. **`status = 'ALL'` no es un estado.** Se traduce a `null` en
 *      `statusFilter()` para que el repositorio distinga «sin filtro» de «filtra
 *      por un estado concreto»; los KPIs, además, ignoran ese filtro por diseño
 *      (`QA 2`).
 *   2. **`perPage` está acotado a 1..100.** Un `per_page=100000` no es una
 *      consulta, es una denegación de servicio accidental sobre la bandeja.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final readonly class WorkshopTrayQueryDTO
{
    public const STATUS_ALL = 'ALL';
    public const CATEGORY_ALL = 'ALL';
    public const DEFAULT_PAGE = 1;
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    /** Categorías del catálogo de repuestos (`spare_part_category`). */
    public const ALLOWED_CATEGORIES = [
        'PAYMENT',
        'COOLING',
        'DISPENSING',
        'ELECTRONICS',
        'WATER_COFFEE',
        'OTHER',
    ];

    public string $status;
    public ?int $technicianId;
    public string $category;
    public ?string $search;
    public int $page;
    public int $perPage;

    public function __construct(
        string $status = self::STATUS_ALL,
        ?int $technicianId = null,
        string $category = self::CATEGORY_ALL,
        ?string $search = null,
        int $page = self::DEFAULT_PAGE,
        int $perPage = self::DEFAULT_PER_PAGE
    ) {
        $normalizedStatus = strtoupper(trim($status));
        if ($normalizedStatus === '') {
            $normalizedStatus = self::STATUS_ALL;
        }
        if ($normalizedStatus !== self::STATUS_ALL && WorkshopRepairStatus::tryFrom($normalizedStatus) === null) {
            throw new InvalidArgumentException(sprintf(
                'El estado «%s» no pertenece al ciclo de taller.',
                $status
            ));
        }

        $normalizedCategory = strtoupper(trim($category));
        if ($normalizedCategory === '') {
            $normalizedCategory = self::CATEGORY_ALL;
        }
        if ($normalizedCategory !== self::CATEGORY_ALL
            && !in_array($normalizedCategory, self::ALLOWED_CATEGORIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'La categoría «%s» no pertenece al catálogo de repuestos.',
                $category
            ));
        }

        if ($technicianId !== null && $technicianId <= 0) {
            throw new InvalidArgumentException('El técnico del filtro debe ser un identificador positivo.');
        }

        if ($page < 1) {
            throw new InvalidArgumentException('La página solicitada debe ser 1 o mayor.');
        }

        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException(sprintf(
                'El tamaño de página debe estar entre 1 y %d.',
                self::MAX_PER_PAGE
            ));
        }

        $normalizedSearch = $search !== null ? trim($search) : '';
        if (mb_strlen($normalizedSearch, 'UTF-8') > 120) {
            throw new InvalidArgumentException('La búsqueda no puede superar los 120 caracteres.');
        }

        $this->status = $normalizedStatus;
        $this->technicianId = $technicianId;
        $this->category = $normalizedCategory;
        $this->search = $normalizedSearch === '' ? null : $normalizedSearch;
        $this->page = $page;
        $this->perPage = $perPage;
    }

    /**
     * Construye el DTO desde los parámetros de consulta ya decodificados.
     *
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $status = $query['status'] ?? self::STATUS_ALL;
        $category = $query['category'] ?? self::CATEGORY_ALL;
        $search = $query['search'] ?? null;

        return new self(
            status: is_string($status) ? $status : self::STATUS_ALL,
            technicianId: self::toPositiveInt($query['technician_id'] ?? null),
            category: is_string($category) ? $category : self::CATEGORY_ALL,
            search: is_string($search) ? $search : null,
            page: self::toInt($query['page'] ?? self::DEFAULT_PAGE, self::DEFAULT_PAGE),
            perPage: self::toInt($query['per_page'] ?? self::DEFAULT_PER_PAGE, self::DEFAULT_PER_PAGE)
        );
    }

    /**
     * Estado concreto a filtrar, o `null` cuando la vista pide `ALL`.
     */
    public function statusFilter(): ?WorkshopRepairStatus
    {
        return $this->status === self::STATUS_ALL ? null : WorkshopRepairStatus::from($this->status);
    }

    /**
     * Categoría concreta a filtrar, o `null` cuando la vista pide `ALL`.
     */
    public function categoryFilter(): ?string
    {
        return $this->category === self::CATEGORY_ALL ? null : $this->category;
    }

    /** Desplazamiento calculado para el `LIMIT ... OFFSET` del repositorio. */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    private static function toPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return null;
        }

        $candidate = (int)$value;

        return $candidate > 0 ? $candidate : null;
    }

    private static function toInt(mixed $value, int $fallback): int
    {
        if (!is_scalar($value) || (string)$value === '') {
            return $fallback;
        }

        return (int)$value;
    }
}
