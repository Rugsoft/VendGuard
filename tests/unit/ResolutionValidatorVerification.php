<?php

declare(strict_types=1);

/**
 * ResolutionValidatorVerification
 * 
 * Verificación específica para la condición "Hecho cuando:" de la tarea T-08.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Service\ResolutionValidator;
use VendGuard\Core\Domain\Exception\InvalidResolutionException;

echo "========================================================\n";
echo " VendGuard: Verificación de ResolutionValidator (T-08)\n";
echo "========================================================\n\n";

$failures = 0;

$validDiagnosis = "Compresor de frío bloqueado por acumulación de hielo en evaporador."; // 68 chars
$validAction = "Descongelación térmica forzada y calibración de termostato digital."; // 67 chars

$shortText19 = "1234567890123456789"; // 19 chars
$shortOk = "ok";
$shortDot = ".";
$spacesPadding = "   " . $shortText19 . "    "; // 19 chars after trim

// 1. Ambos superan el umbral (>= 20 chars) => Devuelve true
try {
    $result = ResolutionValidator::validate($validDiagnosis, $validAction);
    if ($result === true) {
        echo "1. validate() con ambos campos >= 20 caracteres devuelve true: [OK]\n";
    } else {
        echo "1. validate() no devolvió true: [FALLO]\n";
        $failures++;
    }
} catch (Throwable $e) {
    echo "1. validate() arrojó excepción inesperada: [FALLO] {$e->getMessage()}\n";
    $failures++;
}

// 2. Diagnóstico < 20 chars (19 caracteres) => Arroja error
$caughtShortDiag19 = false;
try {
    ResolutionValidator::validate($shortText19, $validAction);
} catch (InvalidResolutionException $e) {
    $caughtShortDiag19 = true;
}
if ($caughtShortDiag19) {
    echo "2. validate() con diagnóstico de 19 caracteres arroja error: [OK]\n";
} else {
    echo "2. validate() no arrojó error con diagnóstico de 19 caracteres: [FALLO]\n";
    $failures++;
}

// 3. Diagnóstico 'ok' => Arroja error
$caughtShortDiagOk = false;
try {
    ResolutionValidator::validate($shortOk, $validAction);
} catch (InvalidResolutionException $e) {
    $caughtShortDiagOk = true;
}
if ($caughtShortDiagOk) {
    echo "3. validate() con diagnóstico 'ok' arroja error: [OK]\n";
} else {
    echo "3. validate() no arrojó error con diagnóstico 'ok': [FALLO]\n";
    $failures++;
}

// 4. Acción < 20 chars (19 caracteres) => Arroja error
$caughtShortAction19 = false;
try {
    ResolutionValidator::validate($validDiagnosis, $shortText19);
} catch (InvalidResolutionException $e) {
    $caughtShortAction19 = true;
}
if ($caughtShortAction19) {
    echo "4. validate() con acción correctiva de 19 caracteres arroja error: [OK]\n";
} else {
    echo "4. validate() no arrojó error con acción de 19 caracteres: [FALLO]\n";
    $failures++;
}

// 5. Acción '.' => Arroja error
$caughtShortActionDot = false;
try {
    ResolutionValidator::validate($validDiagnosis, $shortDot);
} catch (InvalidResolutionException $e) {
    $caughtShortActionDot = true;
}
if ($caughtShortActionDot) {
    echo "5. validate() con acción correctiva '.' arroja error: [OK]\n";
} else {
    echo "5. validate() no arrojó error con acción '.': [FALLO]\n";
    $failures++;
}

// 6. Ambos campos cortos (< 20 caracteres) => Arroja error
$caughtBothShort = false;
try {
    ResolutionValidator::validate($shortOk, $shortDot);
} catch (InvalidResolutionException $e) {
    $caughtBothShort = true;
}
if ($caughtBothShort) {
    echo "6. validate() con ambos campos cortos arroja error: [OK]\n";
} else {
    echo "6. validate() no arrojó error con ambos campos cortos: [FALLO]\n";
    $failures++;
}

// 7. Texto con relleno de espacios (19 caracteres reales) => Arroja error
$caughtSpaces = false;
try {
    ResolutionValidator::validate($spacesPadding, $validAction);
} catch (InvalidResolutionException $e) {
    $caughtSpaces = true;
}
if ($caughtSpaces) {
    echo "7. validate() ignora espacios en blanco en la cuenta de caracteres: [OK]\n";
} else {
    echo "7. validate() contó espacios en blanco: [FALLO]\n";
    $failures++;
}

echo "\n========================================================\n";
if ($failures === 0) {
    echo " TODAS LAS CONDICIONES DE T-08 CUMPLIDAS CON ÉXITO.\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo " ERROR: Se detectaron {$failures} fallos en la verificación de T-08.\n";
    echo "========================================================\n";
    exit(1);
}
