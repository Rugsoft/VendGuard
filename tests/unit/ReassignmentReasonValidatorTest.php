<?php

declare(strict_types=1);

/**
 * ReassignmentReasonValidatorTest · T-CUSTODY-04
 *
 * Suite unitaria de la puerta única del motivo de reasignación (Módulo 26, R1,
 * QA 1). Certifica lo que la regla **quiere decir**, no sólo que exista:
 *
 *  1. Un motivo nulo, vacío o compuesto sólo de espacios no es una explicación.
 *  2. La longitud se mide sobre el texto normalizado: diez espacios intercalados
 *     en «a        b» no convierten tres caracteres reales en diez.
 *  3. El relleno de símbolos no pasa el control de contenido: diez guiones o
 *     once puntos tienen longitud pero no dicen nada.
 *  4. Un motivo válido se devuelve **ya normalizado** (extremos recortados y
 *     secuencias internas colapsadas): lo que se guarda es lo que se midió.
 *  5. Los límites son exactos: nueve caracteres reales se rechazan, diez se
 *     aceptan, y las letras acentuadas cuentan como letras.
 *
 * La salvaguarda sanitaria de 20 caracteres del `override_justification` (Art. II
 * y Art. V.1) es otra puerta y no se toca aquí: mezclarlas permitiría que un
 * motivo largo eludiera el control sanitario.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Service\ReassignmentReasonValidator;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - ReassignmentReasonValidatorTest (T-CUSTODY-04)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $title, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$title}\n";

        return;
    }

    echo "  [FAIL] {$title}\n";
    if ($detail !== '') {
        echo "         Motivo: {$detail}\n";
    }
    $failures++;
};

/**
 * Ejecuta el validador y describe el desenlace sin dejar subir la excepción.
 *
 * @return array{accepted: bool, value: string|null, message: string|null}
 */
$attempt = static function (?string $raw): array {
    try {
        return ['accepted' => true, 'value' => ReassignmentReasonValidator::normalizeAndValidate($raw), 'message' => null];
    } catch (InvalidArgumentException $exception) {
        return ['accepted' => false, 'value' => null, 'message' => $exception->getMessage()];
    }
};

echo "--- Grupo 1: Motivos ausentes o sin contenido real ---\n";

$rejected = [
    'nulo' => null,
    'cadena vacía' => '',
    'doce espacios' => '            ',
    'tabuladores y saltos' => " \t\n\r  ",
    'corto con espacios intercalados' => 'a        b',
    'diez guiones' => '----------',
    'once puntos' => '...........',
    'sólo símbolos' => '!@#$%^&*()_+',
];

foreach ($rejected as $label => $raw) {
    $result = $attempt($raw);
    $assert(
        "1.x Se rechaza el motivo {$label}",
        $result['accepted'] === false && $result['message'] !== null,
        $result['accepted'] ? 'el validador lo aceptó como «' . $result['value'] . '»' : ''
    );
}

echo "\n--- Grupo 2: Límites exactos de longitud real ---\n";

$nineCharacters = $attempt('ABCD 1234');
$assert(
    '2.1 Nueve caracteres reales tras colapsar espacios se rechazan (por debajo del mínimo)',
    $nineCharacters['accepted'] === false
);

$tenCharacters = $attempt('  ABCD   12345  ');
$assert(
    '2.2 Diez caracteres reales tras colapsar espacios se aceptan en el límite',
    $tenCharacters['accepted'] === true && $tenCharacters['value'] === 'ABCD 12345',
    $tenCharacters['accepted'] ? 'normalizado: «' . $tenCharacters['value'] . '»' : (string)$tenCharacters['message']
);

$accented = $attempt('áéíóúñü 1234');
$assert(
    '2.3 Las letras acentuadas cuentan como caracteres y como contenido alfanumérico',
    $accented['accepted'] === true && $accented['value'] === 'áéíóúñü 1234'
);

$assert(
    '2.4 El mínimo declarado por el validador es diez caracteres',
    ReassignmentReasonValidator::MIN_LENGTH === 10
);

echo "\n--- Grupo 3: Normalización de motivos válidos ---\n";

$messy = $attempt('   Cambio    de técnico   por   baja médica   ');
$assert(
    '3.1 Se recortan los extremos y se colapsan las secuencias internas de espacios',
    $messy['accepted'] === true && $messy['value'] === 'Cambio de técnico por baja médica',
    $messy['accepted'] ? 'normalizado: «' . $messy['value'] . '»' : (string)$messy['message']
);

$tabs = $attempt("Traspaso\tmanual\npor  ausencia");
$assert(
    '3.2 Tabuladores y saltos de línea se colapsan como un único espacio',
    $tabs['accepted'] === true && $tabs['value'] === 'Traspaso manual por ausencia',
    $tabs['accepted'] ? 'normalizado: «' . $tabs['value'] . '»' : (string)$tabs['message']
);

$alreadyClean = $attempt('Reasignación por ausencia del titular');
$assert(
    '3.3 Un motivo ya limpio se devuelve sin alteraciones',
    $alreadyClean['accepted'] === true && $alreadyClean['value'] === 'Reasignación por ausencia del titular'
);

$withDigits = $attempt('Traspaso al técnico 07 por vacaciones');
$assert(
    '3.4 Un dígito cuenta como carácter alfanumérico válido',
    $withDigits['accepted'] === true
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-CUSTODY-04 (validador de motivo) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
