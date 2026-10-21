<?php

declare(strict_types=1);

/**
 * VanStockItemTest · T-VAN-02
 *
 * Certifica la entidad de saldo declarado de la furgoneta (Módulo 18, RF-01.2, RF-03.1,
 * RF-03.3):
 *
 *   1. **Cotas anti-errata (RD-04).** La cantidad admite negativos dentro de la cota —un
 *      negativo es un descuadre declarado, no un error— y rechaza lo que queda fuera; el
 *      umbral nunca es negativo.
 *   2. **Bajo umbral no es «por debajo de cero».** La insignia de RF-03.1 se enciende cuando
 *      la cantidad **no supera** el umbral, incluido el caso de igualdad: un técnico con 2
 *      unidades y umbral 2 ya está en el límite que él mismo declaró.
 *   3. **Descuadre es negativo estricto (RF-03.3).** Una cantidad `0` no es un descuadre:
 *      es una furgoneta sin esa pieza.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\VanStockItem;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$assertThrows = static function (string $what, callable $operation) use ($assert): void {
    try {
        $operation();
        $assert($what, false, 'no se lanzó ninguna excepción');
    } catch (InvalidArgumentException $exception) {
        $assert($what, true, $exception->getMessage());
    }
};

echo "======================================================================\n";
echo " VendGuard: Test Unitario - VanStockItemTest (T-VAN-02)\n";
echo "======================================================================\n\n";

echo "--- 1. Construcción y lectura del saldo ---\n";

$item = new VanStockItem(null, 7, 3, 5, 2);
$assert('La entidad conserva técnico, pieza, cantidad y umbral', $item->technicianId === 7 && $item->sparePartId === 3 && $item->quantityAvailable === 5 && $item->minThreshold === 2);
$assert('Sin persistir, el identificador es nulo', $item->id === null);
$assert('Las cotas declaradas coinciden con el DDL (-999..9999 y 0..9999)', VanStockItem::QUANTITY_MIN === -999 && VanStockItem::QUANTITY_MAX === 9999 && VanStockItem::THRESHOLD_MIN === 0 && VanStockItem::THRESHOLD_MAX === 9999);

echo "\n--- 2. Cotas de cantidad (RD-04) ---\n";

$assert('Una cantidad negativa dentro de la cota es válida: es un descuadre declarado', (new VanStockItem(null, 7, 3, -4, 0))->quantityAvailable === -4);
$assert('Los límites exactos de la cota se admiten', (new VanStockItem(null, 7, 3, -999, 9999))->quantityAvailable === -999);

$assertThrows('Una cantidad por debajo de la cota se rechaza', static fn () => new VanStockItem(null, 7, 3, -1000, 0));
$assertThrows('Una cantidad por encima de la cota se rechaza', static fn () => new VanStockItem(null, 7, 3, 10000, 0));

echo "\n--- 3. Cotas del umbral ---\n";

$assert('Un umbral de cero es válido: no avisar de reposición es una decisión del coordinador', (new VanStockItem(null, 7, 3, 1, 0))->minThreshold === 0);
$assertThrows('Un umbral negativo se rechaza', static fn () => new VanStockItem(null, 7, 3, 1, -1));
$assertThrows('Un umbral por encima de la cota se rechaza', static fn () => new VanStockItem(null, 7, 3, 1, 10000));

echo "\n--- 4. Insignia de bajo umbral (RF-03.1) ---\n";

$assert('Cantidad por debajo del umbral: bajo umbral', (new VanStockItem(null, 7, 3, 1, 2))->isBelowThreshold());
$assert('Cantidad igual al umbral: bajo umbral (el límite declarado ya está alcanzado)', (new VanStockItem(null, 7, 3, 2, 2))->isBelowThreshold());
$assert('Cantidad por encima del umbral: no está bajo umbral', !(new VanStockItem(null, 7, 3, 3, 2))->isBelowThreshold());
$assert('Cantidad negativa con umbral cero: bajo umbral', (new VanStockItem(null, 7, 3, -1, 0))->isBelowThreshold());

echo "\n--- 5. Descuadre (RF-03.3) ---\n";

$assert('Una cantidad negativa es un descuadre', (new VanStockItem(null, 7, 3, -1, 0))->isNegative());
$assert('Cantidad cero no es un descuadre: es una furgoneta sin esa pieza', !(new VanStockItem(null, 7, 3, 0, 0))->isNegative());
$assert('Cantidad positiva no es un descuadre', !(new VanStockItem(null, 7, 3, 4, 0))->isNegative());

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-VAN-02 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
