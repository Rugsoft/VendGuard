<?php

declare(strict_types=1);

/**
 * SatisfactionSurveyDomainTest · T-CSAT-02
 *
 * Certifica la entidad de dominio de la encuesta CSAT (Módulo 20) sin base de datos:
 *
 *   1. **Nota (RF-02.3, R9).** Entero de 1 a 5, o cadena que cumpla `/^[1-5]$/` tras `trim`.
 *      Un `float` (incluido `4.0`), un `bool`, `null`, un array o un objeto se rechazan.
 *   2. **Normalización del comentario (RF-02.4).** Se recorta con espacios Unicode; una
 *      cadena vacía se guarda como `null`, nunca como `''`; los saltos de línea y las
 *      tabulaciones internas se conservan.
 *   3. **Longitud del comentario (RD-05).** Se mide en caracteres (`mb_strlen`), no en
 *      bytes: 500 caracteres se admiten y 501 se rechazan.
 *   4. **Tipo del comentario.** Un tipo no textual responde `COMMENT_TOO_LONG` con el
 *      mensaje «El comentario debe ser texto.» (contratos §4.4), sin ampliar el catálogo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\SatisfactionSurveyValidationException;
use VendGuard\Core\Domain\Model\IncidentSatisfactionSurvey;

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
 * Construye una encuesta válida y sustituye solo lo que cada caso quiere probar.
 */
$build = static function (mixed $rating, mixed $comment = null): IncidentSatisfactionSurvey {
    return IncidentSatisfactionSurvey::create(
        incidentId: 1841,
        locationId: 3,
        technicianId: 7,
        technicianNameSnapshot: 'Ana Ruiz',
        resolutionAt: '2026-10-22 17:04:11+02',
        rating: $rating,
        comment: $comment,
    );
};

/**
 * Ejecuta la operación y devuelve la excepción de dominio, o `null` si no la hubo.
 */
$rejection = static function (callable $operation): ?SatisfactionSurveyValidationException {
    try {
        $operation();
    } catch (SatisfactionSurveyValidationException $exception) {
        return $exception;
    }

    return null;
};

echo "======================================================================\n";
echo " VENDGUARD · T-CSAT-02 · Entidad de dominio de la encuesta CSAT\n";
echo "======================================================================\n";

// ──────────────────────────────────────────────────────────────────────────
// 1. Nota: entero de 1 a 5, o cadena numérica (RF-02.3, R9)
// ──────────────────────────────────────────────────────────────────────────
echo "\n--- Nota: entero o cadena de 1 a 5 ---\n";

$minimum = $build(1);
$assert(
    'La nota entera mínima (1) se admite',
    $minimum->getRating() === 1,
    'nota: ' . var_export($minimum->getRating(), true)
);

$maximum = $build(5);
$assert(
    'La nota entera máxima (5) se admite',
    $maximum->getRating() === 5,
    'nota: ' . var_export($maximum->getRating(), true)
);

$stringRating = $build('5');
$assert(
    'La cadena «5» se convierte en el entero 5',
    $stringRating->getRating() === 5,
    'nota: ' . var_export($stringRating->getRating(), true)
);

$paddedString = $build(' 5 ');
$assert(
    'La cadena « 5 » se admite tras recortar espacios',
    $paddedString->getRating() === 5,
    'nota: ' . var_export($paddedString->getRating(), true)
);

foreach ([0, 6, -1, '0', '6', '4.0', 4.0, 5.0, true, false, null, [4], ['v' => 4]] as $invalidRating) {
    $exception = $rejection(static fn() => $build($invalidRating));
    $assert(
        'La nota ' . json_encode($invalidRating) . ' se rechaza con 422 INVALID_RATING',
        $exception !== null
            && $exception->getErrorCode() === SatisfactionSurveyValidationException::INVALID_RATING_CODE
            && $exception->getHttpStatusCode() === 422,
        $exception === null ? 'se admitió' : $exception->getErrorCode()
    );
}

// ──────────────────────────────────────────────────────────────────────────
// 2. Comentario: normalización Unicode (RF-02.4)
// ──────────────────────────────────────────────────────────────────────────
echo "\n--- Comentario: normalización ---\n";

$noComment = $build(4);
$assert(
    'Un comentario ausente se guarda como null',
    $noComment->getComment() === null,
    'comentario: ' . var_export($noComment->getComment(), true)
);

foreach (['', '   ', "\u{00A0}", "\u{00A0} \t\n"] as $blankComment) {
    $normalized = $build(4, $blankComment);
    $assert(
        'El comentario en blanco ' . json_encode($blankComment) . ' se normaliza a null',
        $normalized->getComment() === null,
        'comentario: ' . var_export($normalized->getComment(), true)
    );
}

$trimmed = $build(4, "  Vinieron el mismo día.  ");
$assert(
    'El comentario se recorta por ambos extremos',
    $trimmed->getComment() === 'Vinieron el mismo día.',
    'comentario: ' . var_export($trimmed->getComment(), true)
);

$multiline = $build(4, "  Vinieron el mismo día.\nDejaron la máquina\tlimpia.  ");
$assert(
    'Los saltos de línea y las tabulaciones internas se conservan',
    $multiline->getComment() === "Vinieron el mismo día.\nDejaron la máquina\tlimpia.",
    'comentario: ' . json_encode($multiline->getComment())
);

// ──────────────────────────────────────────────────────────────────────────
// 3. Longitud: caracteres, no bytes (RD-05)
// ──────────────────────────────────────────────────────────────────────────
echo "\n--- Comentario: longitud en caracteres ---\n";

$fiveHundred = $build(4, str_repeat('a', 500));
$assert(
    'Un comentario de 500 caracteres se admite',
    mb_strlen((string)$fiveHundred->getComment(), 'UTF-8') === 500,
    'longitud: ' . mb_strlen((string)$fiveHundred->getComment(), 'UTF-8')
);

$fiveHundredAccents = $build(4, str_repeat('ñ', 500));
$assert(
    '500 caracteres multibyte (1000 bytes) se admiten: la cota se mide en caracteres',
    mb_strlen((string)$fiveHundredAccents->getComment(), 'UTF-8') === 500
        && strlen((string)$fiveHundredAccents->getComment()) === 1000,
    'bytes: ' . strlen((string)$fiveHundredAccents->getComment())
);

$tooLong = $rejection(static fn() => $build(4, str_repeat('a', 501)));
$assert(
    'Un comentario de 501 caracteres se rechaza con 422 COMMENT_TOO_LONG',
    $tooLong !== null
        && $tooLong->getErrorCode() === SatisfactionSurveyValidationException::COMMENT_TOO_LONG_CODE
        && $tooLong->getHttpStatusCode() === 422,
    $tooLong === null ? 'se admitió' : $tooLong->getErrorCode()
);

// ──────────────────────────────────────────────────────────────────────────
// 4. Tipo del comentario (contratos §4.4)
// ──────────────────────────────────────────────────────────────────────────
echo "\n--- Comentario: tipo no textual ---\n";

foreach ([[1, 2], 4, 4.5, true, false] as $invalidComment) {
    $exception = $rejection(static fn() => $build(4, $invalidComment));
    $assert(
        'El comentario ' . json_encode($invalidComment) . ' se rechaza con COMMENT_TOO_LONG y el mensaje de tipo',
        $exception !== null
            && $exception->getErrorCode() === SatisfactionSurveyValidationException::COMMENT_TOO_LONG_CODE
            && $exception->getMessage() === 'El comentario debe ser texto.',
        $exception === null ? 'se admitió' : $exception->getMessage()
    );
}

// ──────────────────────────────────────────────────────────────────────────
// 5. Precedencia interna: la nota se valida antes que el comentario
// ──────────────────────────────────────────────────────────────────────────
echo "\n--- Precedencia: nota antes que comentario (R9) ---\n";

$bothInvalid = $rejection(static fn() => $build(9, str_repeat('a', 501)));
$assert(
    'Con nota y comentario inválidos gana INVALID_RATING',
    $bothInvalid !== null
        && $bothInvalid->getErrorCode() === SatisfactionSurveyValidationException::INVALID_RATING_CODE,
    $bothInvalid === null ? 'se admitió' : $bothInvalid->getErrorCode()
);

echo "\n======================================================================\n";
echo sprintf(" RESULTADO: %d aserciones · %d fallos\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
