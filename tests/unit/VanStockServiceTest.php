<?php

declare(strict_types=1);

/**
 * VanStockServiceTest · T-VAN-03 / T-VAN-04
 *
 * Certifica el servicio de aplicación del stock de furgoneta (Módulo 18) contra un puerto en
 * memoria, sin base de datos:
 *
 *   1. **Ajuste absoluto, no delta (RF-01.1).** El coordinador declara cuántas unidades hay; el
 *      servicio lee el saldo previo para poder auditar el delta y devolverlo en la respuesta.
 *   2. **Catálogo de errores (contratos §5).** Cotas fuera de rango, técnico o pieza
 *      inexistentes y usuario que no es técnico se rechazan con el código y el HTTP declarados.
 *   3. **Cero lógica de inventario en los caminos de consulta (RF-03, RF-04).** El servicio solo
 *      delega el filtro y devuelve lo que el puerto entrega: ni ordena ni filtra en PHP, porque el
 *      orden por severidad es de la consulta (`ORDER BY` de los contratos §6.3).
 *   4. **Un solo punto de descuento (RF-02.1).** `consume()` delega una única llamada al puerto y
 *      no compone ni audita nada: el consumo queda trazado por `incident_replaced_parts`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\VanStockEntryDto;
use VendGuard\Application\Service\VanStockService;
use VendGuard\Core\Domain\Exception\VanStockValidationException;
use VendGuard\Core\Domain\Model\VanStockItem;
use VendGuard\Core\Domain\Repository\VanStockRepositoryInterface;

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
 * Puerto en memoria: registra lo que el servicio le pide y devuelve lo que la prueba dispone.
 */
final class InMemoryVanStockRepository implements VanStockRepositoryInterface
{
    /** @var array<string, VanStockItem> */
    public array $items = [];

    /** @var list<array{technician_id: int, spare_part_id: int, quantity_available: int, min_threshold: int, previous: ?int, actor: ?int}> */
    public array $adjustments = [];

    /** @var list<array{technician_id: int, spare_part_id: int, quantity: int}> */
    public array $consumptions = [];

    /** @var array<int, array{id: int, name: string, role: string, operator_code: ?string}> */
    public array $technicians = [];

    /** @var array<int, true> */
    public array $spareParts = [];

    /** @var list<VanStockEntryDto> */
    public array $technicianEntries = [];

    /** @var list<VanStockEntryDto> */
    public array $belowThresholdEntries = [];

    /** @var list<int|null> */
    public array $belowThresholdFilters = [];

    /** @var list<int> */
    public array $technicianListings = [];

    private int $nextId = 100;

    private static function key(int $technicianId, int $sparePartId): string
    {
        return $technicianId . ':' . $sparePartId;
    }

    public function findByPair(int $technicianId, int $sparePartId): ?VanStockItem
    {
        return $this->items[self::key($technicianId, $sparePartId)] ?? null;
    }

    public function adjust(VanStockItem $item, ?int $previousQuantity, ?int $actorUserId): VanStockItem
    {
        $this->adjustments[] = [
            'technician_id' => $item->technicianId,
            'spare_part_id' => $item->sparePartId,
            'quantity_available' => $item->quantityAvailable,
            'min_threshold' => $item->minThreshold,
            'previous' => $previousQuantity,
            'actor' => $actorUserId,
        ];

        $saved = new VanStockItem(
            $item->id ?? $this->nextId++,
            $item->technicianId,
            $item->sparePartId,
            $item->quantityAvailable,
            $item->minThreshold
        );
        $this->items[self::key($item->technicianId, $item->sparePartId)] = $saved;

        return $saved;
    }

    public function consume(int $technicianId, int $sparePartId, int $quantity): void
    {
        $this->consumptions[] = [
            'technician_id' => $technicianId,
            'spare_part_id' => $sparePartId,
            'quantity' => $quantity,
        ];
    }

    public function listByTechnician(int $technicianId): array
    {
        $this->technicianListings[] = $technicianId;

        return $this->technicianEntries;
    }

    public function listBelowThreshold(?int $technicianId = null): array
    {
        $this->belowThresholdFilters[] = $technicianId;

        return $this->belowThresholdEntries;
    }

    public function findTechnician(int $technicianId): ?array
    {
        return $this->technicians[$technicianId] ?? null;
    }

    public function sparePartExists(int $sparePartId): bool
    {
        return isset($this->spareParts[$sparePartId]);
    }
}

$assertRejected = static function (
    string $what,
    callable $operation,
    string $expectedCode,
    int $expectedStatus
) use ($assert): void {
    try {
        $operation();
        $assert($what, false, 'no se rechazó la operación');
    } catch (VanStockValidationException $exception) {
        $assert(
            $what,
            $exception->getErrorCode() === $expectedCode && $exception->getHttpStatusCode() === $expectedStatus,
            $exception->getErrorCode() . ' · HTTP ' . $exception->getHttpStatusCode()
        );
    }
};

echo "======================================================================\n";
echo " VendGuard: Test Unitario - VanStockServiceTest (T-VAN-03 / T-VAN-04)\n";
echo "======================================================================\n\n";

$repository = new InMemoryVanStockRepository();
$repository->technicians = [
    7 => ['id' => 7, 'name' => 'Jordi Técnico Ruta BCN', 'role' => 'TECHNICIAN', 'operator_code' => 'OP-01'],
    8 => ['id' => 8, 'name' => 'Sara Coordinadora', 'role' => 'COORDINATOR', 'operator_code' => null],
];
$repository->spareParts = [1 => true, 9 => true];

$service = new VanStockService($repository);

echo "--- 1. Alta y ajuste absoluto con delta auditado (RF-01.1, RF-05.1) ---\n";

$created = $service->setStock(7, 1, 5, 2, 8);
$assert(
    'El alta deja el saldo declarado y devuelve el identificador persistido',
    $created->item->quantityAvailable === 5 && $created->item->minThreshold === 2 && $created->item->id !== null,
    'id: ' . (string)$created->item->id
);
$assert('En un alta no hay cantidad previa (la fila no existía)', $created->previousQuantityAvailable === null);
$assert('En un alta el delta es la cantidad declarada', $created->delta === 5, 'delta: ' . $created->delta);
$assert('El ajuste llega al puerto con el actor que lo ejecuta', ($repository->adjustments[0]['actor'] ?? null) === 8);
$assert('El ajuste llega al puerto sin cantidad previa', array_key_exists('previous', $repository->adjustments[0]) && $repository->adjustments[0]['previous'] === null);
$assert('Con cantidad 5 y umbral 2 el saldo no está bajo umbral', !$created->item->isBelowThreshold());

$adjusted = $service->setStock(7, 1, 1, 2, 8);
$assert(
    'El ajuste sobre una fila existente devuelve la cantidad previa que espera el panel',
    $adjusted->previousQuantityAvailable === 5,
    'previa: ' . (string)$adjusted->previousQuantityAvailable
);
$assert('El delta del ajuste es la diferencia con la cantidad previa', $adjusted->delta === -4, 'delta: ' . $adjusted->delta);
$assert('Tras el ajuste el saldo queda bajo umbral', $adjusted->item->isBelowThreshold());

echo "\n--- 2. Cotas anti-errata (RD-04) ---\n";

$assertRejected(
    'Una cantidad por encima de la cota se rechaza con INVALID_VAN_STOCK_QUANTITY (422)',
    static fn () => $service->setStock(7, 1, 10000, 0, 8),
    'INVALID_VAN_STOCK_QUANTITY',
    422
);
$assertRejected(
    'Una cantidad por debajo de la cota se rechaza con INVALID_VAN_STOCK_QUANTITY (422)',
    static fn () => $service->setStock(7, 1, -1000, 0, 8),
    'INVALID_VAN_STOCK_QUANTITY',
    422
);
$assertRejected(
    'Un umbral negativo se rechaza con INVALID_VAN_STOCK_QUANTITY (422)',
    static fn () => $service->setStock(7, 1, 1, -1, 8),
    'INVALID_VAN_STOCK_QUANTITY',
    422
);

$negative = $service->setStock(7, 9, -1, 0, 8);
$assert('Control positivo: un descuadre declarado se acepta y queda marcado', $negative->item->isNegative() && $negative->item->isBelowThreshold());

echo "\n--- 3. Destino del ajuste (contratos §4.3, §5) ---\n";

$assertRejected(
    'Un técnico inexistente se rechaza con VAN_STOCK_TARGET_NOT_FOUND (404)',
    static fn () => $service->setStock(999, 1, 1, 0, 8),
    'VAN_STOCK_TARGET_NOT_FOUND',
    404
);
$assertRejected(
    'Un usuario que no es técnico se rechaza con VAN_STOCK_ROLE_NOT_TECHNICIAN (422)',
    static fn () => $service->setStock(8, 1, 1, 0, 8),
    'VAN_STOCK_ROLE_NOT_TECHNICIAN',
    422
);
$assertRejected(
    'Una pieza inexistente se rechaza con VAN_STOCK_TARGET_NOT_FOUND (404)',
    static fn () => $service->setStock(7, 404, 1, 0, 8),
    'VAN_STOCK_TARGET_NOT_FOUND',
    404
);
$assert('Un rechazo no escribe nada en el puerto', count($repository->adjustments) === 3, 'ajustes: ' . count($repository->adjustments));

echo "\n--- 4. Consumo: un solo punto de descuento, sin auditoría duplicada (RF-02.1, RF-05.2) ---\n";

$service->consume(7, 1, 3);
$assert(
    'El consumo delega una única llamada al puerto con técnico, pieza y cantidad',
    $repository->consumptions === [['technician_id' => 7, 'spare_part_id' => 1, 'quantity' => 3]],
    json_encode($repository->consumptions)
);

try {
    $service->consume(7, 1, 0);
    $assert('Una cantidad de consumo no positiva se rechaza', false, 'no se rechazó');
} catch (InvalidArgumentException $exception) {
    $assert('Una cantidad de consumo no positiva se rechaza', true, $exception->getMessage());
}

echo "\n--- 5. Consultas delegadas (RF-03, RF-04) ---\n";

$entry = new VanStockEntryDto(
    sparePartId: 9,
    partCode: 'JUN-TEFL-09',
    name: 'Junta de teflón del grupo de infusión',
    category: 'CONSUMABLE',
    quantityAvailable: -1,
    minThreshold: 0,
    technicianId: 7,
    technicianName: 'Jordi Técnico Ruta BCN',
    operatorCode: 'OP-01'
);
$repository->technicianEntries = [$entry];
$repository->belowThresholdEntries = [$entry];

$technicianView = $service->listByTechnician(7);
$assert('La vista del técnico delega con el técnico autenticado', $repository->technicianListings === [7]);
$assert('La vista del técnico devuelve las filas del puerto sin recomponerlas', $technicianView === [$entry]);

$assert('El listado de alerta sin filtro delega un filtro nulo', $service->listBelowThreshold() === [$entry] && $repository->belowThresholdFilters === [null]);
$assert('El listado de alerta con filtro delega el técnico indicado', $service->listBelowThreshold(7) === [$entry] && $repository->belowThresholdFilters === [null, 7]);

echo "\n--- 6. Proyecciones de respuesta (contratos §4.1, §4.2) ---\n";

$technicianPayload = $entry->toTechnicianArray();
$assert(
    'La proyección del técnico lleva pieza, cantidad y umbral, sin identidad de técnico',
    $technicianPayload === [
        'spare_part_id' => 9,
        'part_code' => 'JUN-TEFL-09',
        'name' => 'Junta de teflón del grupo de infusión',
        'category' => 'CONSUMABLE',
        'quantity_available' => -1,
        'min_threshold' => 0,
        'is_below_threshold' => true,
    ],
    json_encode($technicianPayload)
);
$assert(
    'La proyección del técnico no filtra ningún importe ni coste (Art. V.4)',
    !array_key_exists('unit_cost', $technicianPayload) && !array_key_exists('reference_cost', $technicianPayload)
);

$coordinatorPayload = $entry->toCoordinatorArray();
$assert(
    'La proyección del coordinador añade identidad del técnico y la marca de descuadre',
    $coordinatorPayload === [
        'technician_id' => 7,
        'technician_name' => 'Jordi Técnico Ruta BCN',
        'operator_code' => 'OP-01',
        'spare_part_id' => 9,
        'part_code' => 'JUN-TEFL-09',
        'name' => 'Junta de teflón del grupo de infusión',
        'quantity_available' => -1,
        'min_threshold' => 0,
        'is_below_threshold' => true,
        'is_negative' => true,
    ],
    json_encode($coordinatorPayload)
);

$adjustmentPayload = $adjusted->jsonSerialize();
$assert(
    'La respuesta del ajuste declara cantidad, umbral, insignia y cantidad previa',
    $adjustmentPayload === [
        'technician_id' => 7,
        'spare_part_id' => 1,
        'quantity_available' => 1,
        'min_threshold' => 2,
        'is_below_threshold' => true,
        'previous_quantity_available' => 5,
    ],
    json_encode($adjustmentPayload)
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIONES T-VAN-03 y T-VAN-04 CUMPLIDAS.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
