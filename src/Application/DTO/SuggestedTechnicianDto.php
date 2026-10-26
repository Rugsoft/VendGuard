<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use JsonSerializable;

/**
 * SuggestedTechnicianDto
 *
 * Respuesta del motor de sugerencia de asignación (RF-04, contrato §3.6). Es la
 * única forma en que el panel de triaje recibe el ranking, de modo que el
 * controlador no recomponga la respuesta y el frontend no dependa de la forma
 * interna del caso de uso.
 *
 * Tres bloques, y cada uno responde a una pregunta distinta del coordinador:
 *
 *  - `incident`: sobre qué avería se está recomendando (prioridad, riesgo de frío
 *    y sede con su zona). Incluye la zona **solo** para Coordinación: el portal de
 *    sede no consume este DTO (RF-07.1, Art. V.4).
 *  - `recommendation`: quién encabeza la lista y por qué, con las dos banderas que
 *    explican el contexto —escalado sanitario y zona desatendida— y la frase que se
 *    muestra al coordinador.
 *  - `ranked_technicians`: el ranking completo con el desglose exacto de la
 *    puntuación, la carga viva y la distancia de referencia de cada técnico. Cuando
 *    la foto trae el contexto de furgoneta (Módulo 23), cada fila añade además el
 *    bloque de solo lectura `van_stock_match` y, si procede, su insignia de
 *    cobertura en `badges`. Ese bloque **no** participa en `total_score` ni en el
 *    orden: es información para el coordinador, nunca un criterio de prelación
 *    (`D2`, Art. II).
 *
 * La identidad contable se comprueba en el constructor (Fail-Fast): si la
 * recomendación no coincide con la primera fila del ranking, el panel mostraría
 * dos «elegidos» distintos y el error debe verse aquí, no en la UI.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class SuggestedTechnicianDto implements JsonSerializable
{
    /** Insignia de cobertura completa de repuestos en la furgoneta (`RF-06.2`). */
    public const BADGE_STOCK_COMPATIBLE = 'STOCK_COMPATIBLE';

    /** Insignia de cobertura parcial (`RF-06.2`, `C1`). */
    public const BADGE_STOCK_PARCIAL = 'STOCK_PARCIAL';

    /** Insignia de ausencia de repuestos compatibles en la furgoneta (`RF-06.1`). */
    public const BADGE_SIN_STOCK_COMPATIBLE = 'SIN_STOCK_COMPATIBLE';

    /**
     * @param array<string, mixed> $incident Bloque de la avería.
     * @param array<string, mixed> $recommendation Bloque de la recomendación.
     * @param list<array<string, mixed>> $rankedTechnicians Ranking completo, ya ordenado.
     *
     * @throws InvalidArgumentException Si la recomendación no encabeza el ranking o si
     *                                  hay puntuaciones que no son enteras.
     */
    public function __construct(
        public array $incident,
        public array $recommendation,
        public array $rankedTechnicians
    ) {
        foreach ($rankedTechnicians as $index => $technician) {
            if (!array_key_exists('total_score', $technician) || !is_int($technician['total_score'])) {
                throw new InvalidArgumentException(
                    sprintf('El técnico en la posición %d no declara una puntuación entera.', $index + 1)
                );
            }

            if (!array_key_exists('technician_id', $technician) || !is_int($technician['technician_id'])) {
                throw new InvalidArgumentException(
                    sprintf('El técnico en la posición %d no declara un identificador entero.', $index + 1)
                );
            }
        }

        $topId = $recommendation['top_technician_id'] ?? null;
        $firstId = $rankedTechnicians[0]['technician_id'] ?? null;

        if ($topId !== $firstId) {
            throw new InvalidArgumentException(
                'La recomendación debe coincidir con el primer técnico del ranking.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'incident' => $this->incident,
            'recommendation' => $this->recommendation,
            'ranked_technicians' => $this->rankedTechnicians,
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
