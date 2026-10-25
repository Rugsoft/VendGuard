<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

/**
 * WorkHoursReportDto
 *
 * Proyección inmutable del informe de registro de jornada y horas efectivas
 * para Coordinación (Módulo 22, contrato técnico §5.1).
 *
 * El DTO no calcula nada: transporta el resultado ya resuelto por
 * `TechnicianWorkHoursService`, de modo que el controlador solo tenga que
 * serializarlo y la exportación CSV pueda reutilizar exactamente las mismas
 * cifras que la pantalla. Dos cálculos distintos acabarían discrepando.
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades readonly, sin dependencias.
 * Dualismo Lingüístico: código en inglés, claves y textos en castellano.
 */
final class WorkHoursReportDto
{
    /**
     * @param array{period: string, from_date: string, to_date: string, technician_id: int|null, timezone: string} $filter
     * @param array<string, int|string> $summary
     * @param list<array<string, mixed>> $orphanOpenShifts
     * @param list<array<string, mixed>> $byTechnician
     * @param list<array<string, mixed>> $sessions
     */
    public function __construct(
        private readonly array $filter,
        private readonly array $summary,
        private readonly array $orphanOpenShifts,
        private readonly array $byTechnician,
        private readonly array $sessions
    ) {
    }

    /**
     * @return array{period: string, from_date: string, to_date: string, technician_id: int|null, timezone: string}
     */
    public function filter(): array
    {
        return $this->filter;
    }

    /**
     * @return array<string, int|string>
     */
    public function summary(): array
    {
        return $this->summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function orphanOpenShifts(): array
    {
        return $this->orphanOpenShifts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byTechnician(): array
    {
        return $this->byTechnician;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sessions(): array
    {
        return $this->sessions;
    }

    /**
     * Payload completo del contrato §5.1.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'filter' => $this->filter,
            'summary' => $this->summary,
            'orphan_open_shifts' => $this->orphanOpenShifts,
            'by_technician' => $this->byTechnician,
            'sessions' => $this->sessions,
        ];
    }
}
