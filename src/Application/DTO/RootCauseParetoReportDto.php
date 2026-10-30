<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use JsonSerializable;

/**
 * RootCauseParetoReportDto
 *
 * Informe completo del Diagrama de Pareto 80/20 (Módulo 25, RF-05, `D5`).
 *
 * La forma pública es la del contrato §6.1: `filters`, `available_filters`,
 * `summary` e `items`. El DTO no calcula nada: recibe del servicio los cuatro
 * bloques ya resueltos y se limita a garantizar que la respuesta no pierda
 * ninguno de ellos ni publique un resumen incompleto. Los importes viajan en
 * euros con dos decimales y los porcentajes como número de coma flotante; el
 * formateo con coma decimal es responsabilidad del CSV y del cliente.
 */
final class RootCauseParetoReportDto implements JsonSerializable
{
    /** Claves obligatorias del bloque `summary` (contrato §6.1). */
    public const SUMMARY_KEYS = [
        'classified_incidents_count',
        'unclassified_historical_count',
        'total_incidents_in_period',
        'vital_few_causes_count',
        'total_causes_with_incidents',
        'total_replaced_parts_units',
        'total_replaced_parts_cost_eur',
    ];

    /** @var array<string, mixed> */
    private array $filters;

    /** @var array<string, mixed> */
    private array $availableFilters;

    /** @var array<string, mixed> */
    private array $summary;

    /** @var list<array<string, mixed>> */
    private array $items;

    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $availableFilters
     * @param array<string, mixed> $summary
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        array $filters,
        array $availableFilters,
        array $summary,
        array $items
    ) {
        foreach (self::SUMMARY_KEYS as $key) {
            if (!array_key_exists($key, $summary)) {
                throw new InvalidArgumentException(sprintf(
                    'El resumen del informe Pareto no contiene la clave obligatoria «%s».',
                    $key
                ));
            }
        }

        $this->filters = $filters;
        $this->availableFilters = $availableFilters;
        $this->summary = $summary;
        $this->items = array_values($items);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAvailableFilters(): array
    {
        return $this->availableFilters;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return $this->summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Estado vacío seguro (`C6`): no hay ninguna avería clasificada que pueda
     * servir de base al 100 % del Pareto.
     */
    public function isEmpty(): bool
    {
        return (int) $this->summary['classified_incidents_count'] === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'filters' => $this->filters,
            'available_filters' => $this->availableFilters,
            'summary' => $this->summary,
            'items' => $this->items,
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
