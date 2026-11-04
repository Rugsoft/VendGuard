<?php

declare(strict_types=1);

/**
 * TroubleshootingGuideValidatorTest · T-GUIDE-05
 *
 * Suite unitaria del validador de las guías rápidas de diagnóstico (Módulo 28).
 * Certifica que la puerta del dominio muerde exactamente donde la especificación
 * manda, sin base de datos ni red:
 *
 *   1. **Normalización (`C5`, QA 1):** recorte de extremos, colapso de espacios
 *      repetidos y clave en minúsculas; el modelo se guarda en su forma canónica.
 *   2. **Cotas del contenido (`C3`, Art. V.1):** título de 5 a 150, entre 2 y 10
 *      pasos, títulos de 3 a 120 e instrucciones de 20 a 500 con al menos tres
 *      bloques alfanuméricos reales —el relleno de signos no cuenta—.
 *   3. **Documentos del display (`C3`):** hasta 20 códigos, únicos sin distinguir
 *      mayúsculas, con significado de 5 a 200; hasta 10 repuestos enteros
 *      positivos únicos que conservan el orden de Coordinación (`QA 3`).
 *   4. **Afinidad de la causa raíz (`QA 2`, `C2`):** la misma categoría y la
 *      transversal `OTHER` se aceptan; una categoría ajena, una causa inactiva o
 *      inexistente se rechazan con `INVALID_GUIDE_ROOT_CAUSE`.
 *   5. **Identidad inmutable (`C1`):** en la edición, cambiar el modelo o la
 *      categoría se rechaza con `IMMUTABLE_GUIDE_IDENTITY`; el resto del
 *      contenido se puede editar y los pasos se renumeran `1..N`.
 *
 * Los rechazos se capturan por la excepción del contrato y se comprueba su
 * código y su estado HTTP: cualquier otro fallo se propaga y rompe la suite, no
 * se disfraza de rechazo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Exception\TroubleshootingGuideValidationException;
use VendGuard\Core\Domain\Model\MachineTroubleshootingGuide;
use VendGuard\Core\Domain\Model\RootCause;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Service\TroubleshootingGuideValidator;

echo "======================================================================\n";
echo " VENDGUARD · Suite unitaria · TroubleshootingGuideValidatorTest (T-GUIDE-05)\n";
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

$rejectionOf = static function (callable $operation): ?TroubleshootingGuideValidationException {
    try {
        $operation();
    } catch (TroubleshootingGuideValidationException $exception) {
        return $exception;
    }

    return null;
};

$assertRejects = function (string $caseTitle, callable $operation, string $expectedCode) use ($assert, $rejectionOf): void {
    $exception = $rejectionOf($operation);
    $assert(
        $caseTitle . ' · ' . $expectedCode,
        $exception !== null
        && $exception->getErrorCode() === $expectedCode
        && $exception->getHttpStatusCode() === 422,
        $exception === null
            ? 'la operación se aceptó'
            : sprintf('código %s · HTTP %d', $exception->getErrorCode(), $exception->getHttpStatusCode())
    );
};

// ─────────────────────────────────────────────────────────────────────
// Escenario: carga válida de referencia
// ─────────────────────────────────────────────────────────────────────
$validSteps = static fn (): array => [
    [
        'title' => 'Comprobar la turbina',
        'instruction' => 'Abrir la puerta interior y verificar que la turbina gira sin bloqueo.',
    ],
    [
        'title' => 'Medir la sonda',
        'instruction' => 'Desconectar el conector y medir la resistencia de la sonda con polímetro.',
    ],
];

$payload = static function (array $overrides = []) use ($validSteps): array {
    return array_merge([
        'machine_model' => 'Sanden Vendo G-Drink',
        'breakdown_type' => 'TEMPERATURE_COLD',
        'title' => 'Cadena de frío: sonda NTC, turbina y batería',
        'error_codes' => [['code' => 'E04', 'meaning' => 'Sonda NTC descalibrada']],
        'steps' => $validSteps(),
        'recommended_spare_part_ids' => [3, 1],
        'suggested_root_cause_id' => null,
    ], $overrides);
};

$stepsWithCount = static function (int $count) use ($validSteps): array {
    $steps = [];
    for ($index = 0; $index < $count; $index++) {
        $steps[] = [
            'title' => 'Paso número ' . ($index + 1),
            'instruction' => 'Comprobar el componente número ' . ($index + 1) . ' antes de continuar con la prueba.',
        ];
    }

    return $steps === [] ? [] : $steps;
};

// ─────────────────────────────────────────────────────────────────────
// 1. Normalización del modelo (C5, QA 1)
// ─────────────────────────────────────────────────────────────────────
echo "--- 1. Normalización canónica del modelo (C5, QA 1) ---\n";

$assert(
    '1.1 normalizeModelName recorta extremos y colapsa espacios repetidos',
    MachineTroubleshootingGuide::normalizeModelName('  NECTA   Korinto  ') === 'NECTA Korinto',
    'obtenido: «' . MachineTroubleshootingGuide::normalizeModelName('  NECTA   Korinto  ') . '»'
);

$assert(
    '1.2 normalizeModelKey pliega a minúsculas sin tocar acentos',
    MachineTroubleshootingGuide::normalizeModelKey('  NECTA   Korinto  ') === 'necta korinto',
    'obtenido: «' . MachineTroubleshootingGuide::normalizeModelKey('  NECTA   Korinto  ') . '»'
);

$normalizedGuide = TroubleshootingGuideValidator::validateCreate($payload([
    'machine_model' => '  Azkoyen   Palma+ ',
    'title' => '  Bebidas frías: control  de temperatura ',
]));

$assert(
    '1.3 El alta devuelve el modelo y el título en su forma canónica',
    $normalizedGuide['machine_model'] === 'Azkoyen Palma+'
    && $normalizedGuide['machine_model_key'] === 'azkoyen palma+'
    && $normalizedGuide['title'] === 'Bebidas frías: control de temperatura',
    json_encode([$normalizedGuide['machine_model'], $normalizedGuide['machine_model_key'], $normalizedGuide['title']], JSON_UNESCAPED_UNICODE)
);

// ─────────────────────────────────────────────────────────────────────
// 2. Cotas del título (C3)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 2. Cotas del título (5..150) ---\n";

$assertRejects(
    '2.1 Un título de cuatro caracteres se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['title' => 'Guía'])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_TITLE
);

$assertRejects(
    '2.2 Un título de 151 caracteres se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['title' => str_repeat('a', 151)])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_TITLE
);

$minimumTitle = TroubleshootingGuideValidator::validateCreate($payload(['title' => 'Guíaa']));
$assert('2.3 Un título de cinco caracteres se acepta', $minimumTitle['title'] === 'Guíaa');

// ─────────────────────────────────────────────────────────────────────
// 3. Cotas de los pasos (C3, Art. V.1)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 3. Pasos, títulos e instrucciones (Art. V.1) ---\n";

$assertRejects(
    '3.1 Cero pasos se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['steps' => []])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_STEPS
);

$assertRejects(
    '3.2 Un solo paso se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['steps' => $stepsWithCount(1)])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_STEPS
);

$assertRejects(
    '3.3 Once pasos se rechazan',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['steps' => $stepsWithCount(11)])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_STEPS
);

$stepTitleTooTight = $validSteps();
$stepTitleTooTight[0]['title'] = 'Ir';
$assertRejects(
    '3.4 Un título de paso de dos caracteres se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['steps' => $stepTitleTooTight])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_STEPS
);

$stepTitleTooLong = $validSteps();
$stepTitleTooLong[1]['title'] = str_repeat('a', 121);
$assertRejects(
    '3.5 Un título de paso de 121 caracteres se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['steps' => $stepTitleTooLong])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_STEPS
);

$instructionTooShort = $validSteps();
$instructionTooShort[0]['instruction'] = str_repeat('a', 19);
$assertRejects(
    '3.6 Una instrucción de 19 caracteres se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['steps' => $instructionTooShort])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_STEPS
);

$instructionTooLong = $validSteps();
$instructionTooLong[0]['instruction'] = str_repeat('a', 501);
$assertRejects(
    '3.7 Una instrucción de 501 caracteres se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['steps' => $instructionTooLong])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_STEPS
);

$paddingTrap = $validSteps();
$paddingTrap[0]['instruction'] = str_repeat('.', 20);
$assertRejects(
    '3.8 El relleno de veinte signos sin bloques reales se rechaza (Art. V.1)',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['steps' => $paddingTrap])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_STEPS
);

$twoBlocks = $validSteps();
$twoBlocks[0]['instruction'] = 'Electroválvula caldera';
$assertRejects(
    '3.9 Una instrucción de veinte caracteres con sólo dos bloques se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['steps' => $twoBlocks])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_STEPS
);

$renumbered = TroubleshootingGuideValidator::validateCreate($payload([
    'steps' => [
        ['step_order' => 5, 'title' => 'Comprobar la turbina', 'instruction' => 'Abrir la puerta interior y verificar que la turbina gira sin bloqueo.'],
        ['step_order' => 9, 'title' => 'Medir la sonda', 'instruction' => 'Desconectar el conector y medir la resistencia de la sonda con polímetro.'],
    ],
]));
$assert(
    '3.10 Los pasos se renumeran correlativamente 1..N',
    array_column($renumbered['steps'], 'step_order') === [1, 2],
    json_encode(array_column($renumbered['steps'], 'step_order'))
);

// ─────────────────────────────────────────────────────────────────────
// 4. Códigos de error (C3)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 4. Códigos de error del display (0..20) ---\n";

$assertRejects(
    '4.1 Dos códigos iguales sin distinguir mayúsculas se rechazan',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload([
        'error_codes' => [
            ['code' => 'e04', 'meaning' => 'Sonda NTC descalibrada'],
            ['code' => 'E04', 'meaning' => 'Sonda NTC descalibrada'],
        ],
    ])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_ERROR_CODES
);

$tooManyCodes = [];
for ($index = 1; $index <= 21; $index++) {
    $tooManyCodes[] = ['code' => 'E' . $index, 'meaning' => 'Significado sintético número ' . $index];
}
$assertRejects(
    '4.2 Más de veinte códigos se rechazan',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['error_codes' => $tooManyCodes])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_ERROR_CODES
);

$assert(
    '4.3 Una guía sin códigos de error es válida',
    TroubleshootingGuideValidator::validateCreate($payload(['error_codes' => []]))['error_codes'] === []
);

// ─────────────────────────────────────────────────────────────────────
// 5. Repuestos recomendados (C3, QA 3)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 5. Repuestos recomendados (0..10, QA 3) ---\n";

$assertRejects(
    '5.1 Más de diez repuestos se rechazan',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['recommended_spare_part_ids' => range(1, 11)])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_SPARE_PARTS
);

$assertRejects(
    '5.2 Un repuesto repetido se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['recommended_spare_part_ids' => [2, 2]])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_SPARE_PARTS
);

$assertRejects(
    '5.3 Un identificador no positivo se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['recommended_spare_part_ids' => [0]])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_SPARE_PARTS
);

$orderedParts = TroubleshootingGuideValidator::validateCreate($payload(['recommended_spare_part_ids' => [9, 3, 5]]));
$assert(
    '5.4 Los repuestos conservan el orden definido por Coordinación',
    $orderedParts['recommended_spare_part_ids'] === [9, 3, 5],
    json_encode($orderedParts['recommended_spare_part_ids'])
);

// ─────────────────────────────────────────────────────────────────────
// 6. Afinidad de la causa raíz sugerida (QA 2, C2)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 6. Causa raíz sugerida (QA 2, C2) ---\n";

$coldCause = new RootCause(1, 'RC-COLD-01', IncidentCategory::TEMPERATURE_COLD, 'Sonda NTC descalibrada en evaporador');
$paymentCause = new RootCause(2, 'RC-PAY-01', IncidentCategory::PAYMENT_SYSTEM, 'Moneda doblada en el selector multimoneda');
$otherCause = new RootCause(3, 'RC-OTH-01', IncidentCategory::OTHER, 'Intervención no clasificada en el catálogo');
$inactiveColdCause = new RootCause(
    4,
    'RC-COLD-02',
    IncidentCategory::TEMPERATURE_COLD,
    'Ventilador de evaporador bloqueado',
    null,
    false
);

$sameCategory = TroubleshootingGuideValidator::validateCreate(
    $payload(['suggested_root_cause_id' => 1]),
    $coldCause
);
$assert('6.1 Una causa de la misma categoría se acepta', $sameCategory['suggested_root_cause_id'] === 1);

$transversal = TroubleshootingGuideValidator::validateCreate(
    $payload(['breakdown_type' => 'PAYMENT_SYSTEM', 'suggested_root_cause_id' => 3]),
    $otherCause
);
$assert('6.2 La causa transversal OTHER se acepta en cualquier guía', $transversal['suggested_root_cause_id'] === 3);

$assertRejects(
    '6.3 Una causa de categoría ajena se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate(
        $payload(['suggested_root_cause_id' => 2]),
        $paymentCause
    ),
    TroubleshootingGuideValidationException::INVALID_GUIDE_ROOT_CAUSE
);

$assertRejects(
    '6.4 Una causa desactivada se rechaza (C2)',
    static fn (): array => TroubleshootingGuideValidator::validateCreate(
        $payload(['suggested_root_cause_id' => 4]),
        $inactiveColdCause
    ),
    TroubleshootingGuideValidationException::INVALID_GUIDE_ROOT_CAUSE
);

$assertRejects(
    '6.5 Una causa inexistente se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['suggested_root_cause_id' => 99])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_ROOT_CAUSE
);

$assertRejects(
    '6.6 Un identificador de causa no positivo se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateCreate($payload(['suggested_root_cause_id' => 0])),
    TroubleshootingGuideValidationException::INVALID_GUIDE_ROOT_CAUSE
);

// ─────────────────────────────────────────────────────────────────────
// 7. Edición e identidad inmutable (C1)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 7. Edición e identidad inmutable (C1) ---\n";

$current = new MachineTroubleshootingGuide(
    1,
    'Fas Perla',
    IncidentCategory::OTHER,
    'Diagnóstico del circuito hidráulico',
    [],
    $validSteps(),
    [1],
    null,
    true
);

$assertRejects(
    '7.1 Cambiar el modelo en la edición se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateUpdate(['machine_model' => 'Fas Fast 900'], $current),
    TroubleshootingGuideValidationException::IMMUTABLE_GUIDE_IDENTITY
);

$assertRejects(
    '7.2 Cambiar la categoría en la edición se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateUpdate(['breakdown_type' => 'PRODUCT_JAM'], $current),
    TroubleshootingGuideValidationException::IMMUTABLE_GUIDE_IDENTITY
);

$edited = TroubleshootingGuideValidator::validateUpdate(
    ['title' => 'Diagnóstico de la caldera y de la bomba'],
    $current
);
$assert(
    '7.3 El título editable se actualiza sin devolver la identidad',
    $edited['title'] === 'Diagnóstico de la caldera y de la bomba'
    && !array_key_exists('machine_model', $edited)
    && !array_key_exists('breakdown_type', $edited),
    json_encode(array_keys($edited))
);

$assertRejects(
    '7.4 Un título inválido en la edición se rechaza',
    static fn (): array => TroubleshootingGuideValidator::validateUpdate(['title' => 'Guía'], $current),
    TroubleshootingGuideValidationException::INVALID_GUIDE_TITLE
);

$assert(
    '7.5 La edición que repite el mismo modelo normalizado no se considera identidad nueva',
    TroubleshootingGuideValidator::validateUpdate(['machine_model' => '  FAS   PERLA '], $current)['title'] === $current->getTitle()
);

// ─────────────────────────────────────────────────────────────────────
// 8. Constructores semánticos de la excepción
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 8. Constructores semánticos de la excepción ---\n";

$duplicate = TroubleshootingGuideValidationException::duplicateGuide('fas perla', 'OTHER');
$assert(
    '8.1 duplicateGuide responde 409 DUPLICATE_TROUBLESHOOTING_GUIDE',
    $duplicate->getHttpStatusCode() === 409
    && $duplicate->getErrorCode() === TroubleshootingGuideValidationException::DUPLICATE_TROUBLESHOOTING_GUIDE
);

$notFound = TroubleshootingGuideValidationException::guideNotFound(42);
$assert(
    '8.2 guideNotFound responde 404 TROUBLESHOOTING_GUIDE_NOT_FOUND',
    $notFound->getHttpStatusCode() === 404
    && $notFound->getErrorCode() === TroubleshootingGuideValidationException::TROUBLESHOOTING_GUIDE_NOT_FOUND
    && $notFound->getContext()['guide_id'] === 42
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-GUIDE-05 (validador de guías) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
