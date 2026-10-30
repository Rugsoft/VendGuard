<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;

/**
 * ParetoFilterDto
 *
 * Filtros validados del Diagrama de Pareto 80/20 (Módulo 25, RF-05, EARS 05.4).
 *
 * El DTO es la frontera de validación del controlador: una fecha imposible
 * (`2026-02-31`), un rango invertido, una categoría fuera del catálogo o un
 * identificador no positivo se rechazan aquí con `INVALID_PARETO_FILTER` antes
 * de que la consulta analítica llegue al motor. El repositorio y el servicio
 * pueden asumir entonces que las fechas son reales y que el rango tiene orden.
 *
 * Se admiten las dos formas de ventana que declara el contrato: `period_days`
 * (`30`, `90`, `180`, `365` o `all`) y el par `from`/`to` ya materializado. El
 * DTO no calcula la ventana a partir de los días: eso es responsabilidad del
 * controlador que conoce el reloj operativo; aquí sólo se valida y se publica.
 */
final class ParetoFilterDto implements JsonSerializable
{
    /** Periodos admitidos por el contrato de la API (EARS 05.4). */
    public const ALLOWED_PERIOD_DAYS = [30, 90, 180, 365];

    private string $startDate;

    private string $endDate;

    private int|string|null $periodDays;

    private ?string $manufacturer;

    private ?string $machineModel;

    private ?IncidentCategory $category;

    private ?int $locationId;

    private ?int $technicianId;

    private bool $vitalFewOnly;

    public function __construct(
        string $startDate,
        string $endDate,
        int|string|null $periodDays = null,
        ?string $manufacturer = null,
        ?string $machineModel = null,
        ?IncidentCategory $category = null,
        ?int $locationId = null,
        ?int $technicianId = null,
        bool $vitalFewOnly = false
    ) {
        $this->startDate = $this->assertRealDate($startDate, 'inicio');
        $this->endDate = $this->assertRealDate($endDate, 'fin');

        if ($this->startDate > $this->endDate) {
            throw new InvalidArgumentException(
                'INVALID_PARETO_FILTER: la fecha de inicio no puede ser posterior a la fecha de fin.'
            );
        }

        $this->periodDays = $this->normalizePeriodDays($periodDays);
        $this->manufacturer = $this->nullableTrimmed($manufacturer);
        $this->machineModel = $this->nullableTrimmed($machineModel);
        $this->category = $category;
        $this->locationId = $this->assertPositiveId($locationId, 'location_id');
        $this->technicianId = $this->assertPositiveId($technicianId, 'technician_id');
        $this->vitalFewOnly = $vitalFewOnly;
    }

    public function getStartDate(): string
    {
        return $this->startDate;
    }

    public function getEndDate(): string
    {
        return $this->endDate;
    }

    public function getPeriodDays(): int|string|null
    {
        return $this->periodDays;
    }

    public function getManufacturer(): ?string
    {
        return $this->manufacturer;
    }

    public function getMachineModel(): ?string
    {
        return $this->machineModel;
    }

    public function getCategory(): ?IncidentCategory
    {
        return $this->category;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function getTechnicianId(): ?int
    {
        return $this->technicianId;
    }

    public function isVitalFewOnly(): bool
    {
        return $this->vitalFewOnly;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period_days' => $this->periodDays,
            'from' => $this->startDate,
            'to' => $this->endDate,
            'manufacturer' => $this->manufacturer,
            'machine_model' => $this->machineModel,
            'category' => $this->category?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function assertRealDate(string $value, string $edge): string
    {
        $normalized = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);

        // La ida y vuelta al mismo texto distingue una fecha real de una
        // corrección silenciosa del calendario (`2026-02-31` → `2026-03-03`).
        if ($parsed === false || $parsed->format('Y-m-d') !== $normalized) {
            throw new InvalidArgumentException(sprintf(
                'INVALID_PARETO_FILTER: la fecha de %s debe ser una fecha real en formato YYYY-MM-DD; se recibió «%s».',
                $edge,
                $value
            ));
        }

        return $normalized;
    }

    private function normalizePeriodDays(int|string|null $periodDays): int|string|null
    {
        if ($periodDays === null) {
            return null;
        }

        if (is_int($periodDays)) {
            if (!in_array($periodDays, self::ALLOWED_PERIOD_DAYS, true)) {
                throw new InvalidArgumentException(
                    'INVALID_PARETO_FILTER: period_days sólo admite 30, 90, 180, 365 o all.'
                );
            }

            return $periodDays;
        }

        $normalized = strtolower(trim($periodDays));
        if ($normalized === 'all') {
            return 'all';
        }

        if (preg_match('/^\d+$/', $normalized) !== 1) {
            throw new InvalidArgumentException(
                'INVALID_PARETO_FILTER: period_days sólo admite 30, 90, 180, 365 o all.'
            );
        }

        $days = (int) $normalized;
        if (!in_array($days, self::ALLOWED_PERIOD_DAYS, true)) {
            throw new InvalidArgumentException(
                'INVALID_PARETO_FILTER: period_days sólo admite 30, 90, 180, 365 o all.'
            );
        }

        return $days;
    }

    private function nullableTrimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertPositiveId(?int $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf(
                'INVALID_PARETO_FILTER: %s debe ser un identificador positivo.',
                $field
            ));
        }

        return $value;
    }
}
