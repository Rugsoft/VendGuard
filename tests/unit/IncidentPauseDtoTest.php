<?php

declare(strict_types=1);

/**
 * IncidentPauseDtoTest
 *
 * Suite unitaria de los DTO de pausa (módulo 11, tarea T-PAUSE-03). Certifica:
 *
 * 1. `IncidentPauseRequestDto` exige una causa del catálogo tipificado y una
 *    justificación de al menos 20 caracteres reales (RF-01.2, RF-01.3, Art. V.1).
 * 2. `IncidentPauseResponseDto` serializa siempre los cuatro campos que la
 *    interfaz necesita para pintar el reloj contractual congelado, con la causa
 *    derivada del value object y el desplazamiento de SLA declarado (RF-03.2).
 * 3. Ambos son inmutables de verdad (`final readonly`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\DTO\IncidentPauseRequestDto;
use VendGuard\Application\DTO\IncidentPauseResponseDto;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - IncidentPauseDtoTest (T-PAUSE-03)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

/** @return array{threw: bool, message: string} */
$capture = static function (callable $action): array {
    try {
        $action();
    } catch (InvalidArgumentException $exception) {
        return ['threw' => true, 'message' => $exception->getMessage()];
    }

    return ['threw' => false, 'message' => ''];
};

$longEnough = 'El edificio está cerrado por festivo local y conserjería sin personal.';

// =====================================================================
// GRUPO 1: Solicitud — causa tipificada obligatoria (RF-01.2)
// =====================================================================
echo "--- Grupo 1: Causa tipificada obligatoria ---\n";

$assert(
    '1.1 El umbral legal de justificación es de 20 caracteres reales',
    IncidentPauseRequestDto::MIN_REASON_TEXT_LENGTH === 20,
    (string)IncidentPauseRequestDto::MIN_REASON_TEXT_LENGTH
);

$request = new IncidentPauseRequestDto(IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS, $longEnough);
$assert(
    '1.2 Una solicitud válida conserva su causa tipificada',
    $request->reasonCategory === IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS
);

$fromPayload = IncidentPauseRequestDto::fromPayload([
    'reason_category' => 'external_power_cut',
    'reason_text' => '  Corte de suministro del inmueble ajeno a la máquina; el cuadro general está precintado.  ',
]);
$assert(
    '1.3 fromPayload() canonicaliza la causa y normaliza el texto sin espacios sobrantes',
    $fromPayload->reasonCategory === IncidentPauseReasonCategory::EXTERNAL_POWER_CUT
    && $fromPayload->normalizedReasonText() === 'Corte de suministro del inmueble ajeno a la máquina; el cuadro general está precintado.'
);
$assert(
    '1.4 El payload normalizado expone causa, etiqueta y texto',
    $fromPayload->toArray()['reason_category'] === 'EXTERNAL_POWER_CUT'
    && $fromPayload->toArray()['reason_category_label'] === 'Corte eléctrico o de suministro ajeno a la máquina'
);

$invalidCategory = $capture(static fn() => IncidentPauseRequestDto::fromPayload([
    'reason_category' => 'SITE_ON_FIRE',
    'reason_text' => $longEnough,
]));
$assert(
    '1.5 Una causa fuera del catálogo se rechaza con InvalidArgumentException',
    $invalidCategory['threw'] === true,
    $invalidCategory['message']
);

$missingCategory = $capture(static fn() => IncidentPauseRequestDto::fromPayload([
    'reason_text' => $longEnough,
]));
$assert(
    '1.6 La ausencia de causa se rechaza (no hay pausa sin causa auditable)',
    $missingCategory['threw'] === true,
    $missingCategory['message']
);

$categoryOfSpaces = $capture(static fn() => IncidentPauseRequestDto::fromPayload([
    'reason_category' => '   ',
    'reason_text' => $longEnough,
]));
$assert(
    '1.7 Una causa de solo espacios se rechaza',
    $categoryOfSpaces['threw'] === true,
    $categoryOfSpaces['message']
);

$missingText = $capture(static fn() => IncidentPauseRequestDto::fromPayload([
    'reason_category' => 'BUILDING_CLOSED_NO_ACCESS',
]));
$assert(
    '1.8 La ausencia de justificación se rechaza',
    $missingText['threw'] === true,
    $missingText['message']
);

// =====================================================================
// GRUPO 2: Solicitud — umbral de 20 caracteres reales (RF-01.3, Art. V.1)
// =====================================================================
echo "\n--- Grupo 2: Umbral de justificación (Art. V.1) ---\n";

$exactly20 = str_repeat('a', 20);
$borderline = new IncidentPauseRequestDto(IncidentPauseReasonCategory::PENDING_SITE_AUTHORIZATION, $exactly20);
$assert(
    '2.1 Una justificación de exactamente 20 caracteres reales se acepta',
    $borderline->reasonText === $exactly20
);

$nineteen = $capture(static fn() => new IncidentPauseRequestDto(
    IncidentPauseReasonCategory::PENDING_SITE_AUTHORIZATION,
    str_repeat('a', 19)
));
$assert(
    '2.2 Una justificación de 19 caracteres se rechaza con InvalidArgumentException',
    $nineteen['threw'] === true,
    $nineteen['message']
);
$assert(
    '2.3 El mensaje de error declara el mínimo legal exigido',
    str_contains($nineteen['message'], '20'),
    $nineteen['message']
);

$onlySpaces = $capture(static fn() => new IncidentPauseRequestDto(
    IncidentPauseReasonCategory::PENDING_SITE_AUTHORIZATION,
    str_repeat(' ', 40)
));
$assert(
    '2.4 Los espacios en blanco superfluos no cuentan como justificación',
    $onlySpaces['threw'] === true,
    $onlySpaces['message']
);

$padded = new IncidentPauseRequestDto(IncidentPauseReasonCategory::MACHINE_LOCATION_NOT_FOUND, '  ' . $exactly20 . '  ');
$assert(
    '2.5 Los espacios alrededor se ignoran al medir (se recorta antes de contar)',
    mb_strlen($padded->normalizedReasonText()) === 20
);

$multibyte = str_repeat('á', 20);
$accented = new IncidentPauseRequestDto(IncidentPauseReasonCategory::MACHINE_LOCATION_NOT_FOUND, $multibyte);
$assert(
    '2.6 La longitud se mide en caracteres reales, no en bytes (mb_strlen)',
    $accented->reasonText === $multibyte
    && mb_strlen($multibyte) === 20
    && strlen($multibyte) !== 20
);

// =====================================================================
// GRUPO 3: Respuesta — serialización del contrato de pausa (RF-03.2)
// =====================================================================
echo "\n--- Grupo 3: Serialización de la respuesta ---\n";

$pausedResponse = new IncidentPauseResponseDto(
    incidentId: 142,
    ticketCode: 'INC-2026-0142',
    status: IncidentStatus::PENDING_INFO,
    isSlaPaused: true,
    accumulatedPauseMinutes: 0,
    slaTargetAtOriginal: '2026-10-09 12:15:00',
    slaTargetAtShifted: '2026-10-09 12:15:00',
    pausedAt: '2026-10-09 10:15:00',
    reasonCategory: IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
    reasonText: $longEnough,
);
$pausedPayload = $pausedResponse->toArray();

$assert(
    '3.1 La respuesta emite siempre los cuatro campos del contrato',
    array_key_exists('is_sla_paused', $pausedPayload)
    && array_key_exists('accumulated_pause_minutes', $pausedPayload)
    && array_key_exists('sla_target_at_original', $pausedPayload)
    && array_key_exists('sla_target_at_shifted', $pausedPayload)
);
$assert(
    '3.2 El reloj contractual se declara congelado con su acumulado',
    $pausedPayload['is_sla_paused'] === true
    && $pausedPayload['accumulated_pause_minutes'] === 0
);
$assert(
    '3.3 Estado y etiqueta se derivan del enum de dominio',
    $pausedPayload['status'] === 'PENDING_INFO'
    && $pausedPayload['status_label'] === 'Pendiente de información'
);
$assert(
    '3.4 La causa viaja con su etiqueta castellana para la interfaz',
    $pausedPayload['reason_category'] === 'BUILDING_CLOSED_NO_ACCESS'
    && $pausedPayload['reason_category_label'] === 'Edificio cerrado / Sin acceso a instalaciones'
);
$assert(
    '3.5 La justificación registrada viaja íntegra en la respuesta',
    $pausedPayload['reason_text'] === $longEnough
);

$resumedResponse = new IncidentPauseResponseDto(
    incidentId: 142,
    ticketCode: 'INC-2026-0142',
    status: IncidentStatus::ASSIGNED,
    isSlaPaused: false,
    accumulatedPauseMinutes: 90,
    slaTargetAtOriginal: '2026-10-09 12:15:00',
    slaTargetAtShifted: '2026-10-09 13:45:00',
);
$resumedPayload = $resumedResponse->toArray();
$assert(
    '3.6 Tras reanudar, el reloj se reactiva y el SLA aparece desplazado',
    $resumedPayload['is_sla_paused'] === false
    && $resumedPayload['accumulated_pause_minutes'] === 90
    && $resumedPayload['sla_target_at_original'] === '2026-10-09 12:15:00'
    && $resumedPayload['sla_target_at_shifted'] === '2026-10-09 13:45:00'
);
$assert(
    '3.7 Sin causa vigente no se inventan campos de pausa',
    !array_key_exists('reason_category', $resumedPayload)
    && !array_key_exists('reason_text', $resumedPayload)
);

$withoutSla = (new IncidentPauseResponseDto(
    incidentId: 7,
    ticketCode: 'INC-2026-0007',
    status: IncidentStatus::PENDING_INFO,
    isSlaPaused: true,
    accumulatedPauseMinutes: 5,
    slaTargetAtOriginal: null,
    slaTargetAtShifted: null,
))->toArray();
$assert(
    '3.8 Una incidencia sin SLA emite null (no omite las claves)',
    array_key_exists('sla_target_at_original', $withoutSla)
    && $withoutSla['sla_target_at_original'] === null
    && array_key_exists('sla_target_at_shifted', $withoutSla)
    && $withoutSla['sla_target_at_shifted'] === null
);

$negativeMinutes = $capture(static fn() => new IncidentPauseResponseDto(
    incidentId: 7,
    ticketCode: 'INC-2026-0007',
    status: IncidentStatus::PENDING_INFO,
    isSlaPaused: true,
    accumulatedPauseMinutes: -1,
    slaTargetAtOriginal: null,
    slaTargetAtShifted: null,
));
$assert(
    '3.9 Un acumulado negativo se rechaza en construcción',
    $negativeMinutes['threw'] === true,
    $negativeMinutes['message']
);

$assert(
    '3.10 jsonSerialize() y toArray() coinciden y el JSON conserva el contrato',
    $pausedResponse->jsonSerialize() === $pausedPayload
    && str_contains((string)json_encode($pausedResponse), '"is_sla_paused"')
    && str_contains((string)json_encode($pausedResponse), '"sla_target_at_shifted"')
);

// =====================================================================
// GRUPO 4: Inmutabilidad real de ambos DTO
// =====================================================================
echo "\n--- Grupo 4: Inmutabilidad (`final readonly`) ---\n";

$responseIsReadonly = false;
try {
    $pausedResponse->accumulatedPauseMinutes = 999;
} catch (\Error) {
    $responseIsReadonly = true;
}
$assert('4.1 La respuesta es inmutable: no admite reasignación de campos', $responseIsReadonly);

$requestIsReadonly = false;
try {
    $request->reasonText = 'otra cosa';
} catch (\Error) {
    $requestIsReadonly = true;
}
$assert('4.2 La solicitud es inmutable: no admite reasignación de campos', $requestIsReadonly);

$assert(
    '4.3 Ambas clases están declaradas `final readonly`',
    (new ReflectionClass(IncidentPauseRequestDto::class))->isReadOnly()
    && (new ReflectionClass(IncidentPauseRequestDto::class))->isFinal()
    && (new ReflectionClass(IncidentPauseResponseDto::class))->isReadOnly()
    && (new ReflectionClass(IncidentPauseResponseDto::class))->isFinal()
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-03 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
