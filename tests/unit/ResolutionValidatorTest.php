<?php

declare(strict_types=1);

/**
 * ResolutionValidatorTest
 * 
 * Suite de pruebas unitarias para el validador de cierre de incidencias (RF-08 / T-09).
 * Comprueba el rechazo de textos cortos (19 caracteres, "ok", ".") y la aceptación con >= 20 caracteres.
 * 
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Service\ResolutionValidator;
use VendGuard\Core\Domain\Exception\InvalidResolutionException;

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - ResolutionValidatorTest (T-09)\n";
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

$validDiagnosis = "Sonda de temperatura descalibrada marcando +12ºC en cuba de frescos."; // 68 chars
$validAction = "Sustitución de sonda NTC y reprogramación de punto de consigna a +3ºC."; // 70 chars

$exact20CharsDiag = "12345678901234567890"; // 20 chars exactos
$exact20CharsAct  = "abcdefghijklmnopqrst"; // 20 chars exactos

$short19Chars = "1234567890123456789";  // 19 chars
$shortOk = "ok";
$shortDot = ".";
$shortSpaces = "   " . $short19Chars . "   ";

// =====================================================================
// GRUPO 1: Rechazo de textos cortos en Diagnóstico (< 20 caracteres)
// =====================================================================
echo "--- Grupo 1: Rechazo de textos insuficientes en Diagnóstico ---\n";

// 1.1: 19 caracteres
$caught = false;
try {
    ResolutionValidator::validate($short19Chars, $validAction);
} catch (InvalidResolutionException $e) {
    $caught = true;
}
$assert("1.1 Diagnóstico de 19 caracteres es rechazado", $caught);
$assert("1.2 isValid() devuelve false con diagnóstico de 19 caracteres", !ResolutionValidator::isValid($short19Chars, $validAction));

// 1.3: 'ok'
$caught = false;
try {
    ResolutionValidator::validate($shortOk, $validAction);
} catch (InvalidResolutionException $e) {
    $caught = true;
}
$assert("1.3 Diagnóstico 'ok' es rechazado", $caught);

// 1.4: '.'
$caught = false;
try {
    ResolutionValidator::validate($shortDot, $validAction);
} catch (InvalidResolutionException $e) {
    $caught = true;
}
$assert("1.4 Diagnóstico '.' es rechazado", $caught);

// 1.5: Espacios trampa (19 caracteres rodeados de espacios)
$caught = false;
try {
    ResolutionValidator::validate($shortSpaces, $validAction);
} catch (InvalidResolutionException $e) {
    $caught = true;
}
$assert("1.5 Diagnóstico de 19 chars con relleno de espacios es rechazado", $caught);

// =====================================================================
// GRUPO 2: Rechazo de textos cortos en Acción Correctiva (< 20 caracteres)
// =====================================================================
echo "\n--- Grupo 2: Rechazo de textos insuficientes en Acción Correctiva ---\n";

// 2.1: 19 caracteres
$caught = false;
try {
    ResolutionValidator::validate($validDiagnosis, $short19Chars);
} catch (InvalidResolutionException $e) {
    $caught = true;
}
$assert("2.1 Acción correctiva de 19 caracteres es rechazada", $caught);
$assert("2.2 isValid() devuelve false con acción de 19 caracteres", !ResolutionValidator::isValid($validDiagnosis, $short19Chars));

// 2.3: 'ok'
$caught = false;
try {
    ResolutionValidator::validate($validDiagnosis, $shortOk);
} catch (InvalidResolutionException $e) {
    $caught = true;
}
$assert("2.3 Acción correctiva 'ok' es rechazada", $caught);

// 2.4: '.'
$caught = false;
try {
    ResolutionValidator::validate($validDiagnosis, $shortDot);
} catch (InvalidResolutionException $e) {
    $caught = true;
}
$assert("2.4 Acción correctiva '.' es rechazada", $caught);

// 2.5: Ambos campos cortos simultáneamente
$caught = false;
$errorCount = 0;
try {
    ResolutionValidator::validate($shortOk, $shortDot);
} catch (InvalidResolutionException $e) {
    $caught = true;
    $errorCount = count($e->getErrors());
}
$assert("2.5 Ambos campos cortos son rechazados", $caught);
$assert("2.6 La excepción contiene exactamente 2 errores de validación", $errorCount === 2);

// =====================================================================
// GRUPO 3: Aceptación en el umbral exacto (20 caracteres) y superiores
// =====================================================================
echo "\n--- Grupo 3: Aceptación de textos válidos (>= 20 caracteres) ---\n";

// 3.1: Exactamente 20 caracteres en ambos campos
$validExact = false;
try {
    $validExact = ResolutionValidator::validate($exact20CharsDiag, $exact20CharsAct);
} catch (Throwable $e) {
    $validExact = false;
}
$assert("3.1 Textos con exactamente 20 caracteres son aceptados", $validExact === true);
$assert("3.2 isValid() devuelve true con exactamente 20 caracteres", ResolutionValidator::isValid($exact20CharsDiag, $exact20CharsAct));

// 3.3: Textos descriptivos realistas y multibyte
$validRealistic = false;
try {
    $validRealistic = ResolutionValidator::validate($validDiagnosis, $validAction);
} catch (Throwable $e) {
    $validRealistic = false;
}
$assert("3.3 Textos técnicos extensos y con caracteres UTF-8 son aceptados", $validRealistic === true);
$assert("3.4 getValidationErrors() devuelve array vacío para textos válidos", empty(ResolutionValidator::getValidationErrors($validDiagnosis, $validAction)));

// =====================================================================
// GRUPO 4: Declaraciones sanitarias obligatorias del cierre (T-PAUSE-24,
//          RF-03.5.2, Constitución Art. II)
// =====================================================================
// La puerta del cierre sobre máquina en cuarentena no es una formalidad: las
// tres declaraciones son actos positivos del técnico y este grupo fija el
// contrato exacto que consume tanto el controlador de ruta como el servicio de
// trazabilidad, con sus bordes medidos uno a uno.
echo "\n--- Grupo 4: Declaraciones sanitarias obligatorias del cierre (RF-03.5.2, Art. II) ---\n";

/** Bloque válido de referencia: una lectura plausible y las dos confirmaciones marcadas. */
$validDeclarations = [
    'temperature_c' => 3.5,
    'stock_destroyed' => true,
    'hygiene_checklist' => true,
];

// 4.1: El bloque ausente no puede pasar: sin declaraciones no hay cierre.
$caught = false;
try {
    ResolutionValidator::validateSanitaryDeclarations([]);
} catch (InvalidResolutionException $e) {
    $caught = true;
}
$assert("4.1 Un bloque vacío se rechaza con InvalidResolutionException", $caught);
$assert(
    "4.2 getSanitaryDeclarationErrors(null) acusa las tres declaraciones en un único mensaje",
    count(ResolutionValidator::getSanitaryDeclarationErrors(null)) === 1
        && str_contains(ResolutionValidator::getSanitaryDeclarationErrors(null)[0], 'temperatura')
        && str_contains(ResolutionValidator::getSanitaryDeclarationErrors(null)[0], 'destrucción')
        && str_contains(ResolutionValidator::getSanitaryDeclarationErrors(null)[0], 'higienización')
);

// 4.3-4.5: La temperatura tiene que ser una lectura numérica real.
$assert(
    "4.3 La temperatura vacía se rechaza",
    count(ResolutionValidator::getSanitaryDeclarationErrors(
        ['temperature_c' => '', 'stock_destroyed' => true, 'hygiene_checklist' => true]
    )) === 1
);
$assert(
    "4.4 Una temperatura no numérica ('abc') se rechaza",
    count(ResolutionValidator::getSanitaryDeclarationErrors(
        ['temperature_c' => 'abc', 'stock_destroyed' => true, 'hygiene_checklist' => true]
    )) === 1
);
$assert(
    "4.5 La coma decimal de la interfaz ('3,5') no es válida en el contrato HTTP",
    count(ResolutionValidator::getSanitaryDeclarationErrors(
        ['temperature_c' => '3,5', 'stock_destroyed' => true, 'hygiene_checklist' => true]
    )) === 1
);

// 4.6-4.9: Los bordes del rango plausible son inclusivos y el exterior se rechaza.
$assert(
    "4.6 El borde inferior −40,0 °C es plausible y se acepta",
    ResolutionValidator::validateSanitaryDeclarations([
        'temperature_c' => -40.0, 'stock_destroyed' => true, 'hygiene_checklist' => true,
    ]) === true
        && ResolutionValidator::MIN_PLAUSIBLE_TEMPERATURE_C === -40.0
);
$assert(
    "4.7 Un grado por debajo del borde (−40,1 °C) se rechaza",
    count(ResolutionValidator::getSanitaryDeclarationErrors([
        'temperature_c' => -40.1, 'stock_destroyed' => true, 'hygiene_checklist' => true,
    ])) === 1
);
$assert(
    "4.8 El borde superior 80,0 °C es plausible y se acepta",
    ResolutionValidator::validateSanitaryDeclarations([
        'temperature_c' => 80.0, 'stock_destroyed' => true, 'hygiene_checklist' => true,
    ]) === true
        && ResolutionValidator::MAX_PLAUSIBLE_TEMPERATURE_C === 80.0
);
$assert(
    "4.9 Una lectura increíble (120 °C) se rechaza nombrando el rango plausible",
    count(ResolutionValidator::getSanitaryDeclarationErrors([
        'temperature_c' => 120, 'stock_destroyed' => true, 'hygiene_checklist' => true,
    ])) === 1
        && str_contains(ResolutionValidator::getSanitaryDeclarationErrors([
            'temperature_c' => 120, 'stock_destroyed' => true, 'hygiene_checklist' => true,
        ])[0], '120.0')
);

// 4.10-4.13: Las dos confirmaciones exigen un acto positivo, no una omisión.
$assert(
    "4.10 La retirada del stock sin declarar se rechaza",
    count(ResolutionValidator::getSanitaryDeclarationErrors(
        ['temperature_c' => 3.5, 'hygiene_checklist' => true]
    )) === 1
);
$assert(
    "4.11 Un false explícito en la retirada del stock se rechaza igual que una omisión",
    count(ResolutionValidator::getSanitaryDeclarationErrors(
        ['temperature_c' => 3.5, 'stock_destroyed' => false, 'hygiene_checklist' => true]
    )) === 1
);
$assert(
    "4.12 La cadena 'false' y el 0 tampoco acreditan la retirada del stock",
    count(ResolutionValidator::getSanitaryDeclarationErrors([
        'temperature_c' => 3.5, 'stock_destroyed' => 'false', 'hygiene_checklist' => true,
    ])) === 1
        && count(ResolutionValidator::getSanitaryDeclarationErrors([
            'temperature_c' => 3.5, 'stock_destroyed' => 0, 'hygiene_checklist' => true,
        ])) === 1
);
$assert(
    "4.13 El checklist de higienización sin marcar se rechaza y marcado se acepta",
    count(ResolutionValidator::getSanitaryDeclarationErrors([
        'temperature_c' => 3.5, 'stock_destroyed' => true, 'hygiene_checklist' => false,
    ])) === 1
        && ResolutionValidator::validateSanitaryDeclarations([
            'temperature_c' => 3.5, 'stock_destroyed' => true, 'hygiene_checklist' => 'true',
        ]) === true
);

// 4.14-4.16: Acumulación de errores y forma del rechazo.
$assert(
    "4.14 Faltando las tres declaraciones, el validador acusa las tres y no sólo la primera",
    count(ResolutionValidator::getSanitaryDeclarationErrors([])) === 3
);
$assert(
    "4.15 El bloque válido de referencia no produce ningún error",
    ResolutionValidator::getSanitaryDeclarationErrors($validDeclarations) === []
        && ResolutionValidator::validateSanitaryDeclarations($validDeclarations) === true
);
$caught = false;
try {
    ResolutionValidator::validateSanitaryDeclarations(['temperature_c' => 3.5]);
} catch (InvalidResolutionException $e) {
    $caught = str_contains($e->getMessage(), 'Declaraciones sanitarias insuficientes')
        && count($e->getErrors()) === 2;
}
$assert("4.16 La excepción publica el recuento real de declaraciones faltantes", $caught);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-09 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
    echo "======================================================================\n";
    exit(1);
}
