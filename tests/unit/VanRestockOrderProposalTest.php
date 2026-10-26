<?php

declare(strict_types=1);

/**
 * VanRestockOrderProposalTest · T-RESTOCK-08
 *
 * Certifica el motor de pre-cálculo de reposición (Módulo 23, RF-02.1,
 * RF-02.3, RF-02.4, QA 1, QA 5, C2, C3) sin base de datos ni reloj.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **LOW_STOCK.** Sin demanda y con saldo en umbral o por debajo; incluye
 *      los descuadres negativos (`C2`) y la ausencia de sugerencia cuando no
 *      hay fila de stock ni umbral declarado.
 *   2. **PENDING_REQUEST.** Solo con umbral `0` y demanda superior al saldo;
 *      incluye la pieza que nunca se declaró en la furgoneta y el caso en que
 *      el saldo ya cubre la demanda.
 *   3. **BOTH.** Demanda con saldo en umbral, o con umbral positivo que quedaría
 *      comprometido al restar la demanda; incluye la precedencia sobre
 *      `PENDING_REQUEST` cuando el saldo ya está en umbral `0`.
 *   4. **Tope doble `9999` (`QA 1`).** `min(9999, 9999 - disponible, bruto)`,
 *      omisión cuando la furgoneta ya está llena y `max_allowed_qty` acotado.
 *   5. **Orden determinista (`QA 5`).** Motivo, saldo ascendente y código de
 *      pieza; se compara la secuencia literal de identificadores.
 *   6. **Foto y contrato.** Aislamiento entre técnicos, banderas de ausencia y
 *      orden abierta, técnico inactivo, forma exacta de las diez claves de línea
 *      y de las siete de la propuesta, pureza y determinismo.
 *
 * El repositorio se sustituye por un doble que solo devuelve la foto: el motor
 * no toca PDO, no abre transacción y no consulta el reloj.
 *
 * Uso:
 *   php tests/unit/VanRestockOrderProposalTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\VanRestockProposalDto;
use VendGuard\Application\Service\VanRestockOrderService;
use VendGuard\Core\Domain\Exception\VanRestockOrderValidationException;
use VendGuard\Core\Domain\Model\VanRestockOrder;
use VendGuard\Core\Domain\Model\VanRestockOrderItem;
use VendGuard\Core\Domain\Repository\VanRestockOrderRepositoryInterface;

/**
 * Doble de lectura del puerto de órdenes.
 *
 * `fetchProposalInputs()` filtra por técnico como el repositorio real; el resto
 * de métodos no se usan en esta suite y fallan en voz alta si alguien los llama.
 */
final class FakeVanRestockOrderRepositoryForProposalTest implements VanRestockOrderRepositoryInterface
{
    /** @var array<string, mixed> */
    private array $photo;

    public int $fetchCalls = 0;

    public int $writeAttempts = 0;

    /**
     * @param array<string, mixed> $photo
     */
    public function __construct(array $photo)
    {
        $this->photo = $photo;
    }

    /**
     * @return array<string, mixed>
     */
    public function photo(): array
    {
        return $this->photo;
    }

    public function fetchProposalInputs(?int $technicianId = null): array
    {
        $this->fetchCalls++;

        if ($technicianId === null) {
            $photo = $this->photo;
            // El puerto real solo devuelve la plantilla activa cuando no se
            // filtra por técnico; el doble replica esa frontera.
            $photo['technicians'] = array_values(array_filter(
                $this->photo['technicians'],
                static fn (array $technician): bool => (bool)$technician['is_active']
            ));

            return $photo;
        }

        $photo = $this->photo;
        $photo['technicians'] = array_values(array_filter(
            $this->photo['technicians'],
            static fn (array $technician): bool => (int)$technician['technician_id'] === $technicianId
        ));

        return $photo;
    }

    public function createOrder(
        int $technicianId,
        ?string $notes,
        array $items,
        int $actorUserId
    ): VanRestockOrder {
        $this->writeAttempts++;
        throw new LogicException('createOrder no se usa en la suite de propuestas.');
    }

    public function updatePendingOrderWithSoftDelete(
        int $orderId,
        ?string $notes,
        array $items,
        int $actorUserId
    ): VanRestockOrder {
        $this->writeAttempts++;
        throw new LogicException('updatePendingOrderWithSoftDelete no se usa en la suite de propuestas.');
    }

    public function markDispatched(
        int $orderId,
        array $dispatchedQuantities,
        int $actorUserId
    ): VanRestockOrder {
        $this->writeAttempts++;
        throw new LogicException('markDispatched no se usa en la suite de propuestas.');
    }

    public function markCancelled(
        int $orderId,
        string $cancellationReason,
        int $actorUserId
    ): VanRestockOrder {
        $this->writeAttempts++;
        throw new LogicException('markCancelled no se usa en la suite de propuestas.');
    }

    public function confirmOrderAndIncrementVanStock(
        int $orderId,
        int $authenticatedTechnicianId,
        ?string $receptionNote = null
    ): array {
        $this->writeAttempts++;
        throw new LogicException('confirmOrderAndIncrementVanStock no se usa en la suite de propuestas.');
    }

    public function findById(int $orderId): ?VanRestockOrder
    {
        throw new LogicException('findById no se usa en la suite de propuestas.');
    }

    public function findOpenOrderForTechnician(int $technicianId): ?VanRestockOrder
    {
        throw new LogicException('findOpenOrderForTechnician no se usa en la suite de propuestas.');
    }

    public function findAllForCoordinator(
        ?int $technicianId = null,
        ?string $status = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        throw new LogicException('findAllForCoordinator no se usa en la suite de propuestas.');
    }

    public function findActiveDispatchedForTechnician(int $technicianId): ?VanRestockOrder
    {
        throw new LogicException('findActiveDispatchedForTechnician no se usa en la suite de propuestas.');
    }

    public function findLastConfirmedForTechnician(int $technicianId): ?VanRestockOrder
    {
        throw new LogicException('findLastConfirmedForTechnician no se usa en la suite de propuestas.');
    }
}

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

/**
 * @param list<VanRestockProposalDto> $proposals
 */
$proposalFor = static function (array $proposals, int $technicianId): ?VanRestockProposalDto {
    foreach ($proposals as $proposal) {
        if ($proposal->technicianId === $technicianId) {
            return $proposal;
        }
    }

    return null;
};

/**
 * @return array<string, int|string>|null
 */
$lineFor = static function (VanRestockProposalDto $proposal, int $sparePartId): ?array {
    foreach ($proposal->items as $line) {
        if ((int)$line['spare_part_id'] === $sparePartId) {
            return $line;
        }
    }

    return null;
};

/**
 * @param list<VanRestockProposalDto> $proposals
 * @return list<int>
 */
$lineIds = static function (VanRestockProposalDto $proposal): array {
    return array_map(static fn (array $line): int => (int)$line['spare_part_id'], $proposal->items);
};

echo "======================================================================\n";
echo " VENDGUARD · T-RESTOCK-08 · Motor de propuestas de reposición\n";
echo "======================================================================\n";

$part = static fn (int $id, string $code, string $name, string $category): array => [
    'spare_part_id' => $id,
    'part_code' => $code,
    'part_name' => $name,
    'category' => $category,
];

$photo = [
    'technicians' => [
        [
            'technician_id' => 7,
            'technician_name' => 'Carlos Mendoza',
            'operator_code' => 'TEC-07',
            'is_active' => true,
            'is_currently_absent' => false,
            'open_order_id' => null,
            'open_order_status' => null,
        ],
        [
            'technician_id' => 8,
            'technician_name' => 'Marta Técnica',
            'operator_code' => 'TEC-08',
            'is_active' => true,
            'is_currently_absent' => true,
            'open_order_id' => 42,
            'open_order_status' => VanRestockOrder::STATUS_DISPATCHED,
        ],
        [
            'technician_id' => 9,
            'technician_name' => 'Técnico Inactivo',
            'operator_code' => 'TEC-09',
            'is_active' => false,
            'is_currently_absent' => false,
            'open_order_id' => null,
            'open_order_status' => null,
        ],
        [
            'technician_id' => 10,
            'technician_name' => 'Técnico del Tope',
            'operator_code' => 'TEC-10',
            'is_active' => true,
            'is_currently_absent' => false,
            'open_order_id' => null,
            'open_order_status' => null,
        ],
        [
            'technician_id' => 11,
            'technician_name' => 'Técnico del Orden',
            'operator_code' => 'TEC-11',
            'is_active' => true,
            'is_currently_absent' => false,
            'open_order_id' => null,
            'open_order_status' => null,
        ],
    ],
    'spare_parts' => [
        $part(101, 'AAA-TH-01', 'Termostato Digital', 'THERMAL'),
        $part(102, 'BBB-VAL-02', 'Electroválvula 24V', 'HYDRAULIC'),
        $part(103, 'CCC-BOM-03', 'Bomba de Agua', 'MECHANICAL'),
        $part(104, 'DDD-SEN-04', 'Sensor de Nivel', 'ELECTRONIC'),
        $part(105, 'EEE-PAG-05', 'Monedero Electrónico', 'PAYMENT_SYSTEM'),
        $part(106, 'FFF-CAP-06', 'Capacitor 470uF', 'ELECTRONIC'),
        $part(107, 'GGG-JUN-07', 'Junta Tórica', 'CONSUMABLE'),
        $part(108, 'HHH-MOT-08', 'Motor Dispensador', 'MECHANICAL'),
    ],
    'van_stock' => [
        7 => [
            101 => ['quantity_available' => 2, 'min_threshold' => 2],
            102 => ['quantity_available' => -1, 'min_threshold' => 2],
            103 => ['quantity_available' => 0, 'min_threshold' => 0],
            105 => ['quantity_available' => 1, 'min_threshold' => 0],
            106 => ['quantity_available' => 5, 'min_threshold' => 0],
            107 => ['quantity_available' => 3, 'min_threshold' => 0],
            108 => ['quantity_available' => 2, 'min_threshold' => 2],
        ],
        8 => [
            101 => ['quantity_available' => 0, 'min_threshold' => 0],
            102 => ['quantity_available' => 3, 'min_threshold' => 2],
            103 => ['quantity_available' => 9, 'min_threshold' => 2],
            108 => ['quantity_available' => 4, 'min_threshold' => 2],
        ],
        10 => [
            101 => ['quantity_available' => 9999, 'min_threshold' => 9999],
            102 => ['quantity_available' => 9998, 'min_threshold' => 9999],
            103 => ['quantity_available' => -500, 'min_threshold' => 9999],
            105 => ['quantity_available' => 9990, 'min_threshold' => 9995],
            106 => ['quantity_available' => 9999, 'min_threshold' => 9999],
        ],
        11 => [
            101 => ['quantity_available' => 10, 'min_threshold' => 0],
            102 => ['quantity_available' => 10, 'min_threshold' => 0],
            105 => ['quantity_available' => 1, 'min_threshold' => 2],
            106 => ['quantity_available' => 1, 'min_threshold' => 2],
            107 => ['quantity_available' => 1, 'min_threshold' => 2],
        ],
    ],
    'pending_requests' => [
        7 => [
            104 => 3,
            105 => 3,
            106 => 3,
            107 => 3,
            108 => 1,
        ],
        8 => [
            101 => 3,
            102 => 2,
            108 => 1,
        ],
        10 => [
            104 => 20000,
            105 => 5,
            106 => 2,
        ],
        11 => [
            101 => 20,
            102 => 25,
            103 => 2,
            104 => 4,
            105 => 2,
            106 => 1,
            107 => 3,
        ],
    ],
];

$repository = new FakeVanRestockOrderRepositoryForProposalTest($photo);
$service = new VanRestockOrderService($repository);

// =====================================================================
// 1. LOW_STOCK: umbral, descuadre negativo y ausencia de fila
// =====================================================================
echo "\n--- 1. LOW_STOCK ---\n";

$proposals = $service->getProposals(7);
$carlos = $proposalFor($proposals, 7);
$assert('1.1 La propuesta del técnico existe', $carlos instanceof VanRestockProposalDto);
$assert('1.2 Sin ausencia ni orden abierta', $carlos !== null
    && $carlos->recipientCurrentlyAbsent === false
    && $carlos->openOrderId === null
    && $carlos->openOrderStatus === null);
$assert('1.3 Seis líneas sugeridas', $carlos !== null && count($carlos->items) === 6, (string)count($carlos?->items ?? []));

$line101 = $carlos !== null ? $lineFor($carlos, 101) : null;
$assert('1.4 Pieza en umbral => LOW_STOCK', $line101 !== null && $line101['reason'] === VanRestockOrder::REASON_LOW_STOCK);
$assert('1.5 Bruto (2 - 2) + 1 = 1', ($line101['suggested_qty'] ?? null) === 1, (string)($line101['suggested_qty'] ?? 'null'));
$assert('1.6 Tope disponible 9999 - 2', ($line101['max_allowed_qty'] ?? null) === 9997);

$line102 = $carlos !== null ? $lineFor($carlos, 102) : null;
$assert('1.7 Descuadre negativo => LOW_STOCK', $line102 !== null
    && $line102['reason'] === VanRestockOrder::REASON_LOW_STOCK
    && $line102['current_quantity'] === -1);
$assert('1.8 Bruto (2 - (-1)) + 1 = 4', ($line102['suggested_qty'] ?? null) === 4, (string)($line102['suggested_qty'] ?? 'null'));
$assert('1.9 El tope se acota a 9999 con saldo negativo', ($line102['max_allowed_qty'] ?? null) === 9999);

$line103 = $carlos !== null ? $lineFor($carlos, 103) : null;
$assert('1.10 Fila en 0/0 sugiere una unidad de reserva', $line103 !== null
    && $line103['reason'] === VanRestockOrder::REASON_LOW_STOCK
    && $line103['suggested_qty'] === 1);

$line104 = $carlos !== null ? $lineFor($carlos, 104) : null;
$assert(
    '1.11 Sin fila de stock no hay LOW_STOCK',
    $line104 === null || $line104['reason'] !== VanRestockOrder::REASON_LOW_STOCK
);

$line106 = $carlos !== null ? $lineFor($carlos, 106) : null;
$assert('1.12 Por encima del umbral y sin déficit no sugiere', $line106 === null);

// =====================================================================
// 2. PENDING_REQUEST: solo con umbral 0 y demanda superior al saldo
// =====================================================================
echo "\n--- 2. PENDING_REQUEST ---\n";

$line104 = $carlos !== null ? $lineFor($carlos, 104) : null;
$assert('2.1 Pieza nunca declarada con demanda => PENDING_REQUEST', $line104 !== null
    && $line104['reason'] === VanRestockOrder::REASON_PENDING_REQUEST
    && $line104['current_quantity'] === 0
    && $line104['min_threshold'] === 0);
$assert('2.2 Sin saldo, bruto = demanda (3)', ($line104['suggested_qty'] ?? null) === 3);

$line105 = $carlos !== null ? $lineFor($carlos, 105) : null;
$assert('2.3 Saldo positivo en umbral 0 y demanda mayor => PENDING_REQUEST', $line105 !== null
    && $line105['reason'] === VanRestockOrder::REASON_PENDING_REQUEST
    && $line105['current_quantity'] === 1
    && $line105['pending_request_qty'] === 3);
$assert('2.4 Bruto 3 - max(0, 1) = 2', ($line105['suggested_qty'] ?? null) === 2);
$assert('2.5 Tope 9999 - 1 = 9998', ($line105['max_allowed_qty'] ?? null) === 9998);

$line107 = $carlos !== null ? $lineFor($carlos, 107) : null;
$assert('2.6 El saldo cubre la demanda => sin línea', $line107 === null);

$marta = $proposalFor($service->getProposals(8), 8);
$assert('2.7 La demanda de otro técnico no contamina al primero', $marta !== null
    && count($marta->items) === 2
    && $lineFor($marta, 101)['reason'] === VanRestockOrder::REASON_BOTH);

// =====================================================================
// 3. BOTH: umbral comprometido por la demanda
// =====================================================================
echo "\n--- 3. BOTH ---\n";

$line108 = $carlos !== null ? $lineFor($carlos, 108) : null;
$assert('3.1 Saldo en umbral con demanda => BOTH', $line108 !== null
    && $line108['reason'] === VanRestockOrder::REASON_BOTH
    && $line108['current_quantity'] === 2
    && $line108['min_threshold'] === 2
    && $line108['pending_request_qty'] === 1);
$assert('3.2 Bruto (2 + 1 + 1) - 2 = 2', ($line108['suggested_qty'] ?? null) === 2);

$marta = $proposalFor($service->getProposals(8), 8);
$lineMarta101 = $marta !== null ? $lineFor($marta, 101) : null;
$lineMarta102 = $marta !== null ? $lineFor($marta, 102) : null;
$assert('3.3 Umbral 0 y saldo 0 con demanda: BOTH precede a PENDING_REQUEST', $lineMarta101 !== null
    && $lineMarta101['reason'] === VanRestockOrder::REASON_BOTH
    && $lineMarta101['suggested_qty'] === 4);
$assert('3.4 Umbral positivo que quedaría comprometido => BOTH', $lineMarta102 !== null
    && $lineMarta102['reason'] === VanRestockOrder::REASON_BOTH
    && $lineMarta102['suggested_qty'] === 2);
$assert('3.5 Umbral positivo con colchón suficiente no sugiere', $marta !== null && $lineFor($marta, 108) === null);

// =====================================================================
// 4. Tope doble 9999 (QA 1)
// =====================================================================
echo "\n--- 4. Tope doble 9999 ---\n";

$cap = $proposalFor($service->getProposals(10), 10);
$assert('4.1 La propuesta del tope existe', $cap !== null);
$capIds = $cap !== null ? $lineIds($cap) : [];
$assert('4.2 Orden del tope: BOTH, PENDING, LOW ascendente', $capIds === [105, 104, 103, 102], implode(',', $capIds));
$assert('4.3 La furgoneta llena no genera línea', $cap !== null && $lineFor($cap, 101) === null);
$assert('4.4 Un BOTH que ya está a 9999 tampoco genera línea', $cap !== null && $lineFor($cap, 106) === null);

$cap102 = $cap !== null ? $lineFor($cap, 102) : null;
$assert('4.5 MAX_ADDABLE 1 acota la sugerencia de 2 a 1', $cap102 !== null
    && $cap102['suggested_qty'] === 1
    && $cap102['max_allowed_qty'] === 1);

$cap103 = $cap !== null ? $lineFor($cap, 103) : null;
$assert('4.6 Saldo negativo: tope superior 9999, no 10499', $cap103 !== null
    && $cap103['suggested_qty'] === 9999
    && $cap103['max_allowed_qty'] === 9999);

$cap104 = $cap !== null ? $lineFor($cap, 104) : null;
$assert('4.7 Demanda 20000 se acota a 9999', $cap104 !== null
    && $cap104['suggested_qty'] === 9999
    && $cap104['pending_request_qty'] === 20000);

$cap105 = $cap !== null ? $lineFor($cap, 105) : null;
$assert('4.8 BOTH con solo 9 unidades de hueco', $cap105 !== null
    && $cap105['suggested_qty'] === 9
    && $cap105['max_allowed_qty'] === 9);

// =====================================================================
// 5. Orden determinista QA 5
// =====================================================================
echo "\n--- 5. Orden determinista ---\n";

$ordered = $proposalFor($service->getProposals(11), 11);
$assert('5.1 La propuesta ordenada existe', $ordered !== null);
$orderedIds = $ordered !== null ? $lineIds($ordered) : [];
$assert(
    '5.2 BOTH (código) -> PENDING (saldo ascendente, luego código)',
    $orderedIds === [105, 106, 107, 103, 104, 101, 102],
    implode(',', $orderedIds)
);
$reasons = $ordered !== null ? array_map(static fn (array $line): string => (string)$line['reason'], $ordered->items) : [];
$assert(
    '5.3 Los motivos respetan la prelación',
    $reasons === [
        VanRestockOrder::REASON_BOTH,
        VanRestockOrder::REASON_BOTH,
        VanRestockOrder::REASON_BOTH,
        VanRestockOrder::REASON_PENDING_REQUEST,
        VanRestockOrder::REASON_PENDING_REQUEST,
        VanRestockOrder::REASON_PENDING_REQUEST,
        VanRestockOrder::REASON_PENDING_REQUEST,
    ],
    implode(',', $reasons)
);
$assert('5.4 BOTH con el mismo saldo se ordena por código', $ordered !== null
    && array_map(static fn (array $line): string => (string)$line['part_code'], array_slice($ordered->items, 0, 3)) === ['EEE-PAG-05', 'FFF-CAP-06', 'GGG-JUN-07']);
$assert('5.5 PENDING con el mismo saldo se ordena por código', $ordered !== null
    && array_map(static fn (array $line): string => (string)$line['part_code'], array_slice($ordered->items, 3, 2)) === ['CCC-BOM-03', 'DDD-SEN-04']);
$assert('5.6 Las dos líneas de saldo 10 se ordenan por código', $ordered !== null
    && array_map(static fn (array $line): int => (int)$line['spare_part_id'], array_slice($ordered->items, 5, 2)) === [101, 102]);

// =====================================================================
// 6. Contrato, banderas y aislamiento
// =====================================================================
echo "\n--- 6. Contrato, banderas y aislamiento ---\n";

$all = $service->getProposals();
$assert('6.1 Una propuesta por técnico activo de la foto', count($all) === 4, (string)count($all));

$carlos = $proposalFor($all, 7);
$marta = $proposalFor($all, 8);
$inactive = $proposalFor($service->getProposals(9), 9);
$assert('6.2 Técnico activo sin marcas', $carlos !== null
    && $carlos->technicianIsActive === true
    && $carlos->recipientCurrentlyAbsent === false
    && !$carlos->hasOpenOrder());
$assert('6.3 Técnico ausente con orden abierta', $marta !== null
    && $marta->recipientCurrentlyAbsent === true
    && $marta->openOrderId === 42
    && $marta->openOrderStatus === VanRestockOrder::STATUS_DISPATCHED
    && $marta->hasOpenOrder());
$assert('6.4 Técnico inactivo visible con su bandera y sin líneas', $inactive !== null
    && $inactive->technicianIsActive === false
    && !$inactive->hasLines());
$assert('6.5 Un técnico inexistente no inventa propuesta', $service->getProposals(999) === []);

$topLevelKeys = $carlos !== null ? array_keys($carlos->toArray()) : [];
$assert(
    '6.6 Claves exactas de la propuesta (§5.2)',
    $topLevelKeys === [
        'technician_id',
        'technician_name',
        'technician_is_active',
        'recipient_currently_absent',
        'open_order_id',
        'open_order_status',
        'items',
    ],
    implode(',', $topLevelKeys)
);

$lineKeys = $carlos !== null && $carlos->items !== [] ? array_keys($carlos->items[0]) : [];
$assert(
    '6.7 Claves exactas de la línea (§5.2)',
    $lineKeys === [
        'spare_part_id',
        'part_code',
        'part_name',
        'category',
        'current_quantity',
        'min_threshold',
        'pending_request_qty',
        'suggested_qty',
        'max_allowed_qty',
        'reason',
    ],
    implode(',', $lineKeys)
);

$assert('6.8 jsonSerialize coincide con toArray', $carlos !== null && $carlos->jsonSerialize() === $carlos->toArray());

$invariantsOk = true;
foreach ($all as $proposal) {
    foreach ($proposal->items as $line) {
        $suggested = (int)$line['suggested_qty'];
        $maxAllowed = (int)$line['max_allowed_qty'];
        $current = (int)$line['current_quantity'];

        if ($suggested < 1 || $suggested > $maxAllowed || $maxAllowed > VanRestockOrder::QUANTITY_MAX) {
            $invariantsOk = false;
        }

        if ($maxAllowed !== min(VanRestockOrder::QUANTITY_MAX, VanRestockOrder::QUANTITY_MAX - $current)) {
            $invariantsOk = false;
        }
    }
}
$assert('6.9 1 <= sugerido <= hueco <= 9999 en todas las líneas', $invariantsOk);

// =====================================================================
// 7. Pureza, determinismo y validación de la foto
// =====================================================================
echo "\n--- 7. Pureza, determinismo y validación ---\n";

$photoBefore = $repository->photo();
$first = array_map(static fn (VanRestockProposalDto $proposal): array => $proposal->toArray(), $service->getProposals(7));
$second = array_map(static fn (VanRestockProposalDto $proposal): array => $proposal->toArray(), $service->getProposals(7));
$assert('7.1 Dos ejecuciones devuelven lo mismo', $first === $second);
$assert('7.2 La foto recibida no se muta', $repository->photo() === $photoBefore);
$callsBefore = $repository->fetchCalls;
$service->getProposals(7);
$assert('7.3 Una ejecución es una sola lectura de la foto', $repository->fetchCalls === $callsBefore + 1);

$invalidId = false;
try {
    $service->getProposals(0);
} catch (InvalidArgumentException $exception) {
    $invalidId = str_contains($exception->getMessage(), 'entero positivo');
}
$assert('7.4 Un identificador no positivo se rechaza', $invalidId);

$emptyRepository = new FakeVanRestockOrderRepositoryForProposalTest([
    'technicians' => [],
    'spare_parts' => [],
    'van_stock' => [],
    'pending_requests' => [],
]);
$emptyService = new VanRestockOrderService($emptyRepository);
$assert('7.5 Una foto vacía devuelve cero propuestas', $emptyService->getProposals() === []);

$assert('7.6 El motor de propuestas no invoca ninguna escritura', $repository->writeAttempts === 0);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. MOTOR DE PROPUESTAS CERTIFICADO (T-RESTOCK-08).\n"
    : " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
