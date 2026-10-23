<?php

declare(strict_types=1);

/**
 * TechnicianCsatDtoTest · T-20.1-01
 *
 * Certifica la proyección CSAT de la superficie del técnico (incremento 20.1) sin base
 * de datos ni HTTP:
 *
 *   1. **Umbral de muestra (D4).** Por debajo de 3 respuestas no se publica ninguna
 *      media: `average` viaja a `null`, la distribución a cero y la tendencia vacía.
 *   2. **El umbral rige cada media expuesta.** Un mes con menos de 3 respuestas se cae
 *      de `monthly_trend` aunque el periodo completo supere el umbral; publicarlo
 *      filtraría por la puerta del mes lo que la media global tapa.
 *   3. **Superficie mínima (RF-20.1.6).** El payload solo lleva `summary`,
 *      `monthly_trend` y `threshold_met`: ni sedes, ni técnicos, ni comentarios.
 *   4. **Distribución canónica.** Siempre las cinco claves 1–5, rellenadas a cero.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\CsatReportDto;
use VendGuard\Application\DTO\TechnicianCsatDto;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

/**
 * Informe de coordinación de partida: trae sedes, técnicos y comentarios que la
 * superficie del técnico NO debe dejar pasar.
 */
$report = static function (
    int $responses,
    ?float $average,
    array $distribution = [],
    array $trend = []
): CsatReportDto {
    return new CsatReportDto(
        average: $average,
        responses: $responses,
        distribution: $distribution,
        bySite: [
            [
                'location_id' => 3,
                'site_code' => 'SEDE-BCN-03',
                'location_name' => 'Hospital Universitari de Bellvitge',
                'average' => 5.0,
                'responses' => 1,
            ],
        ],
        byTechnician: [
            [
                'technician_id' => 7,
                'technician_name' => 'Ana Ruiz',
                'average' => 4.0,
                'responses' => 1,
            ],
        ],
        monthlyTrend: $trend,
    );
};

// -------------------------------------------------------------
// 1. Umbral de muestra
// -------------------------------------------------------------
echo "\n--- 1. Umbral de muestra (D4) ---\n";

$assert('1.1 El umbral canónico es de 3 respuestas', TechnicianCsatDto::MIN_RESPONSES === 3);

$met = TechnicianCsatDto::fromReport($report(
    responses: 3,
    average: 4.33,
    distribution: ['3' => 1, '4' => 2],
    trend: [['month' => '2026-10', 'average' => 4.33, 'responses' => 3]],
));

$assert('1.2 Con 3 respuestas se publica la media', $met->average === 4.33 && $met->thresholdMet === true);
$assert('1.3 El conteo se publica siempre, aunque no llegue al umbral', $met->responses === 3);

$below = TechnicianCsatDto::fromReport($report(
    responses: 2,
    average: 1.5,
    distribution: ['1' => 1, '2' => 1],
    trend: [['month' => '2026-10', 'average' => 1.5, 'responses' => 2]],
));

$assert('1.4 Con 2 respuestas la media no se publica', $below->average === null);
$assert('1.5 Con 2 respuestas el umbral queda sin cumplir', $below->thresholdMet === false);
$assert(
    '1.6 Con 2 respuestas la distribución se publica a cero',
    array_sum($below->distribution) === 0,
    'suma=' . array_sum($below->distribution)
);
$assert(
    '1.7 Con 2 respuestas la tendencia llega vacía',
    $below->monthlyTrend === [],
    'meses=' . count($below->monthlyTrend)
);
$assert('1.8 El conteo sobrevive al umbral', $below->responses === 2);

$empty = TechnicianCsatDto::fromReport($report(responses: 0, average: null));

$assert('1.9 Sin respuestas no hay media ni umbral', $empty->average === null && $empty->thresholdMet === false);
$assert('1.10 Sin respuestas la distribución conserva las cinco claves', count($empty->distribution) === 5);

// -------------------------------------------------------------
// 2. El umbral rige cada mes, no solo el total
// -------------------------------------------------------------
echo "\n--- 2. Umbral por mes en la tendencia ---\n";

// Un mes con 2 respuestas de un periodo con 5: la media global se publica, pero ese
// mes se cae porque su nota es identificable.
$mixed = TechnicianCsatDto::fromReport($report(
    responses: 5,
    average: 4.0,
    distribution: ['1' => 1, '4' => 2, '5' => 2],
    trend: [
        ['month' => '2026-09', 'average' => 1.0, 'responses' => 2],
        ['month' => '2026-10', 'average' => 4.5, 'responses' => 3],
    ],
));

$months = array_column($mixed->monthlyTrend, 'month');

$assert('2.1 El mes con menos de 3 respuestas se cae de la tendencia', !in_array('2026-09', $months, true));
$assert('2.2 El mes que alcanza el umbral se conserva', in_array('2026-10', $months, true));
$assert('2.3 La tendencia queda reindexada como lista', array_keys($mixed->monthlyTrend) === [0]);

// -------------------------------------------------------------
// 3. Superficie mínima (RF-20.1.6)
// -------------------------------------------------------------
echo "\n--- 3. Superficie mínima ---\n";

$payload = $met->toArray();
$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

$assert('3.1 El payload solo expone tres claves', array_keys($payload) === ['summary', 'monthly_trend', 'threshold_met']);
$assert('3.2 No se filtran sedes', !array_key_exists('by_site', $payload) && strpos($encoded, 'SEDE-BCN-03') === false);
$assert('3.3 No se filtran técnicos', !array_key_exists('by_technician', $payload) && strpos($encoded, 'Ana Ruiz') === false);
$assert('3.4 El resumen lleva media, respuestas y distribución', array_keys($payload['summary']) === ['average', 'responses', 'distribution']);
$assert('3.5 jsonSerialize coincide con toArray', $met->jsonSerialize() === $payload);
$assert('3.6 El objeto no tiene campo de comentario', !property_exists($met, 'comment'));

// -------------------------------------------------------------
// 4. Distribución canónica
// -------------------------------------------------------------
echo "\n--- 4. Distribución canónica ---\n";

// PHP castea las claves numéricas a entero: la distribución de `CsatReportDto` (ya en
// `main`) las expone como `int`, y el JSON las serializa igualmente como "1".."5".
$assert(
    '4.1 Las cinco estrellas viajan siempre en orden ascendente',
    array_keys($met->distribution) === [1, 2, 3, 4, 5],
    implode(',', array_keys($met->distribution))
);
$assert(
    '4.2 Los conteos se respetan',
    $met->distribution === ['1' => 0, '2' => 0, '3' => 1, '4' => 2, '5' => 0],
    json_encode($met->distribution)
);

echo "\n======================================================================\n";
echo sprintf(" RESULTADO: %d aserciones · %d fallos\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
