<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use JsonSerializable;

/**
 * TechnicianCsatDto · Proyección CSAT de la superficie del técnico (incremento 20.1).
 *
 * El agregado de coordinación trae sedes, técnicos y —en el modelo— comentarios. Esta
 * proyección es la barrera: solo deja pasar la valoración media, el conteo, la
 * distribución y la tendencia mensual **del propio técnico**. Nada de sedes, nada de
 * nombres y nada de texto libre (RF-20.1.6).
 *
 * El umbral de muestra (D4) no es cosmético: con una o dos respuestas una media es
 * ruido y, peor, identifica a quien resolvió una única avería. Por eso el umbral se
 * aplica a **cada media expuesta**, incluida la de cada mes de la tendencia.
 */
final readonly class TechnicianCsatDto implements JsonSerializable
{
    /** Muestras mínimas para publicar cualquier media (D4). */
    public const MIN_RESPONSES = 3;

    /** Distribución en cero: la forma se mantiene para que el cliente pinte las cinco barras. */
    private const ZERO_DISTRIBUTION = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];

    /**
     * @param array<string, int> $distribution Conteo por nota, siempre con las cinco claves
     * @param list<array{month: string, average: float, responses: int}> $monthlyTrend
     */
    public function __construct(
        public ?float $average,
        public int $responses,
        public array $distribution,
        public array $monthlyTrend,
        public bool $thresholdMet,
    ) {
    }

    /**
     * Proyecta el agregado de coordinación sobre la superficie del técnico.
     */
    public static function fromReport(CsatReportDto $report): self
    {
        if ($report->responses < self::MIN_RESPONSES) {
            return new self(
                average: null,
                responses: $report->responses,
                distribution: self::ZERO_DISTRIBUTION,
                monthlyTrend: [],
                thresholdMet: false,
            );
        }

        // Cada mes se juzga por su propio tamaño: un mes con dos respuestas dentro de un
        // periodo sano seguiría publicando una nota identificable.
        $trend = array_values(array_filter(
            $report->monthlyTrend,
            static fn (array $row): bool => (int)($row['responses'] ?? 0) >= self::MIN_RESPONSES
        ));

        return new self(
            average: $report->average,
            responses: $report->responses,
            distribution: $report->distribution,
            monthlyTrend: $trend,
            thresholdMet: true,
        );
    }

    /**
     * @return array{
     *     summary: array{average: float|null, responses: int, distribution: array<string, int>},
     *     monthly_trend: list<array{month: string, average: float, responses: int}>,
     *     threshold_met: bool
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
            'monthly_trend' => $this->monthlyTrend,
            'threshold_met' => $this->thresholdMet,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
