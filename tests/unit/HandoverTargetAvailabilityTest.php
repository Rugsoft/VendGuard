<?php

declare(strict_types=1);

/**
 * HandoverTargetAvailabilityTest · RF-05.5 / RF-06.3
 *
 * Tabla de verdad de la clasificación de un técnico como receptor de relevo:
 *
 *   | Turno       | Ausencia | Guardia | Resultado   | Manual | Sanitario |
 *   | ON_SHIFT    | sí       | —       | AVAILABLE   | sí     | sí        |
 *   | MEAL_BREAK  | —        | sí      | MEAL_BREAK  | no     | no        |
 *   | OFF_SHIFT   | sí       | sí      | UNAVAILABLE | no     | no        |
 *   | OFF_SHIFT   | no       | sí      | ON_CALL     | sí     | sí        |
 *   | OFF_SHIFT   | no       | no      | OFF_SHIFT   | no     | no        |
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\ValueObject\HandoverTargetAvailability;

echo "======================================================================\n";
echo " VendGuard · HandoverTargetAvailability (RF-05.5 / RF-06.3)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$cases = [
    ['ON_SHIFT', true, false, HandoverTargetAvailability::AVAILABLE, true, true],
    ['ON_SHIFT', true, true, HandoverTargetAvailability::AVAILABLE, true, true],
    ['MEAL_BREAK', false, true, HandoverTargetAvailability::MEAL_BREAK, false, false],
    ['OFF_SHIFT', true, true, HandoverTargetAvailability::UNAVAILABLE, false, false],
    ['OFF_SHIFT', false, true, HandoverTargetAvailability::ON_CALL, true, true],
    ['OFF_SHIFT', false, false, HandoverTargetAvailability::OFF_SHIFT, false, false],
];

$labels = [
    HandoverTargetAvailability::AVAILABLE->value => 'Disponible',
    HandoverTargetAvailability::ON_CALL->value => 'Guardia 24/7 · disponible',
    HandoverTargetAvailability::OFF_SHIFT->value => 'Fuera de turno',
    HandoverTargetAvailability::MEAL_BREAK->value => 'Pausa comida',
    HandoverTargetAvailability::UNAVAILABLE->value => 'No disponible',
];

foreach ($cases as [$shiftStatus, $hasAbsence, $isOnCall, $expected, $manual, $sanitary]) {
    $actual = HandoverTargetAvailability::classify($shiftStatus, $hasAbsence, $isOnCall);
    $description = sprintf(
        'turno=%s · ausencia=%s · guardia=%s => %s',
        $shiftStatus,
        $hasAbsence ? 'sí' : 'no',
        $isOnCall ? 'sí' : 'no',
        $expected->value
    );

    $assert($description . ' (clasificación)', $actual === $expected, 'obtenido: ' . $actual->value);
    $assert($description . ' (receptor manual)', $actual->canReceiveHandover() === $manual);
    $assert($description . ' (receptor sanitario)', $actual->canReceiveSanitary() === $sanitary);
    $assert($description . ' (etiqueta)', $actual->label() === $labels[$expected->value]);
}

$order = [
    HandoverTargetAvailability::AVAILABLE,
    HandoverTargetAvailability::ON_CALL,
    HandoverTargetAvailability::OFF_SHIFT,
    HandoverTargetAvailability::MEAL_BREAK,
    HandoverTargetAvailability::UNAVAILABLE,
];
$orders = array_map(static fn (HandoverTargetAvailability $value): int => $value->sortOrder(), $order);
$sorted = $orders;
sort($sorted);

$assert('El orden canónico coloca disponibles primero y no disponibles al final', $orders === $sorted, implode(',', $orders));

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Exitosas: %d | Fallidas: %d\n", $assertions, $assertions - $failures, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
