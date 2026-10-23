<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use JsonSerializable;

/**
 * CsatReportDto — agregado de satisfacción del Panel de Coordinación (Módulo 20).
 *
 * Es la forma exacta de `GET /api/coordinator/metrics/csat` (contratos §6.2):
 * resumen con media y distribución, tabla por sede, tabla por técnico y serie
 * mensual.
 *
 * Dos invariantes se imponen aquí, no en la consulta, para que ningún camino de
 * lectura pueda publicar una forma distinta:
 *
 *  - **La distribución siempre tiene las cinco claves** `"1"`…`"5"`, con `0`
 *    cuando no hay casos (contrato §6.3).
 *  - **Sin respuestas la media es `null`**, nunca `0`: la falta de valoración no
 *    imputa un cero que castigaría al servicio sin que nadie lo haya puntuado
 *    (RD-07).
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class CsatReportDto implements JsonSerializable
{
    /** Claves canónicas de la distribución, en orden ascendente de estrellas. */
    private const DISTRIBUTION_KEYS = ['1', '2', '3', '4', '5'];

    /**
     * Distribución normalizada: siempre las cinco claves.
     *
     * @var array<string, int>
     */
    public readonly array $distribution;

    /**
     * @param float|null $average Media redondeada a dos decimales, o `null` sin respuestas.
     * @param int $responses Total de valoraciones del periodo y filtros.
     * @param array<string, int> $distribution Conteo por nota (se normaliza a las cinco claves).
     * @param list<array{location_id: int, site_code: string, location_name: string, average: float, responses: int}> $bySite
     * @param list<array{technician_id: int|null, technician_name: string, average: float, responses: int}> $byTechnician
     * @param list<array{month: string, average: float, responses: int}> $monthlyTrend
     */
    public function __construct(
        public ?float $average,
        public int $responses,
        array $distribution,
        public array $bySite,
        public array $byTechnician,
        public array $monthlyTrend,
    ) {
        $normalized = array_fill_keys(self::DISTRIBUTION_KEYS, 0);

        foreach ($distribution as $key => $value) {
            $normalized[(string)$key] = (int)$value;
        }

        $this->distribution = $normalized;
    }

    /**
     * @return array{
     *     summary: array{average: float|null, responses: int, distribution: array<string, int>},
     *     by_site: list<array<string, mixed>>,
     *     by_technician: list<array<string, mixed>>,
     *     monthly_trend: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'summary' => [
                'average' => $this->average,
                'responses' => $this->responses,
                'distribution' => $this->distribution,
            ],
            'by_site' => $this->bySite,
            'by_technician' => $this->byTechnician,
            'monthly_trend' => $this->monthlyTrend,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
