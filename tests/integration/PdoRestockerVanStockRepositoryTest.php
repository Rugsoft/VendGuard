<?php

declare(strict_types=1);

/**
 * PdoRestockerVanStockRepositoryTest · T-PLD-05
 *
 * Suite de integración del stock por lote en la furgoneta del reponedor contra
 * PostgreSQL real (Módulo 33, Tabla 44, RF-01.1–RF-01.3, D1, D2, D4, QA 3,
 * Art. III.1, Art. V.4).
 *
 * Certifica:
 *   1. `UPSERT` por `(restocker_id, product_id, batch_code)` sin duplicar filas y
 *      sin reescribir la fecha de caducidad de un lote ya existente (QA 3).
 *   2. Ordenación FEFO con `NULLS LAST` y desempate estable (D1, D4).
 *   3. Semáforo sanitario derivado (`EXPIRED`, `EXPIRING_SOON`, `OK`) y stock
 *      útil que excluye los lotes caducados (EARS 01.2).
 *   4. Filtros de Coordinación por reponedor, categoría, estado de caducidad y
 *      búsqueda libre, con rechazo de valores fuera de catálogo.
 *   5. `findLot()` con y sin `SELECT ... FOR UPDATE`.
 *   6. Opacidad estructural de `unit_cost_eur` en todas las filas (Art. V.4).
 *   7. Prohibición de borrado físico del motor (Art. III.1).
 *
 * La suite crea dos referencias `TEST-PLD-T5-*`, cuatro lotes propios y los
 * retira en el `finally` con la ventana de purga del arnés. No toca ninguna fila
 * semilla: los reponedores se resuelven por rol y los productos por SKU.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/PdoRestockerVanStockRepositoryTest.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Core\Domain\Model\ProductCategory;
use VendGuard\Core\Domain\Model\ProductUnitType;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Domain\Model\VendingProduct;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoRestockerVanStockRepository;
use VendGuard\Infrastructure\Repository\PdoVendingProductRepository;

echo "======================================================================\n";
echo " VendGuard · Integración · PdoRestockerVanStockRepositoryTest (T-PLD-05)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
$repo = new PdoRestockerVanStockRepository($pdo);
$productRepo = new PdoVendingProductRepository($pdo);

$restockers = $pdo->query("
    SELECT id, name, operator_code
    FROM users
    WHERE role = 'RESTOCKER' AND is_active = TRUE
    ORDER BY id ASC
    LIMIT 2
")->fetchAll(PDO::FETCH_ASSOC);

if (count($restockers) < 2) {
    fwrite(STDERR, "La suite exige dos reponedores activos en el banco de certificación.\n");
    exit(2);
}

$restockerA = (int)$restockers[0]['id'];
$restockerB = (int)$restockers[1]['id'];

$today = new DateTimeImmutable('today');
$coldExpiration = $today->modify('+2 days')->format('Y-m-d');
$expiredExpiration = $today->modify('-1 day')->format('Y-m-d');
$otherExpiration = $today->modify('+4 days')->format('Y-m-d');

$cleanup = static function (PDO $pdo): void {
    TestDataCleaner::withPurgeWindow($pdo, static function () use ($pdo): void {
        $pdo->exec("
            DELETE FROM restocker_van_product_stocks
            WHERE product_id IN (SELECT id FROM vending_products WHERE sku LIKE 'TEST-PLD-T5%')
        ");
        $pdo->exec("DELETE FROM vending_products WHERE sku LIKE 'TEST-PLD-T5%'");
    });
};

$cleanup($pdo);

$failures = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
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

try {
    // =================================================================
    // 1. Referencias de certificación
    // =================================================================
    echo "--- 1. Referencias de certificación ---\n";

    $coldProduct = $productRepo->create(new VendingProduct(
        null,
        'TEST-PLD-T5-COLD',
        'Producto T5 refrigerado',
        ProductCategory::PERISHABLE_FOOD,
        ProductUnitType::UNIT,
        1.50,
        3.00,
        true,
        5,
        true
    ));
    $ambientProduct = $productRepo->create(new VendingProduct(
        null,
        'TEST-PLD-T5-AMB',
        'Producto T5 ambiente',
        ProductCategory::COLD_DRINK,
        ProductUnitType::UNIT,
        0.80,
        1.50,
        false,
        null,
        true
    ));

    $coldProductId = (int)$coldProduct->getId();
    $ambientProductId = (int)$ambientProduct->getId();

    $assert(
        '1.1 Las dos referencias de certificación se persisten',
        $coldProductId > 0 && $ambientProductId > 0,
        "cold={$coldProductId} ambient={$ambientProductId}"
    );

    // =================================================================
    // 2. UPSERT por lote y fecha inmutable (QA 3, Art. III.1)
    // =================================================================
    echo "\n--- 2. UPSERT por (restocker_id, product_id, batch_code) ---\n";

    $coldStock = $repo->upsertLot(new RestockerVanProductStock(
        null,
        $restockerA,
        $coldProductId,
        'test-t5-cold-a',
        new DateTimeImmutable($coldExpiration),
        5,
        true
    ));

    $assert(
        '2.1 upsertLot crea el lote, normaliza el código y deriva el semáforo',
        $coldStock->getId() !== null
        && $coldStock->getId() > 0
        && $coldStock->getBatchCode() === 'TEST-T5-COLD-A'
        && $coldStock->getQuantityAvailable() === 5
        && $coldStock->getExpirationDate()?->format('Y-m-d') === $coldExpiration
        && $coldStock->expiryStatus($today)->value === 'EXPIRING_SOON'
        && $coldStock->usableQuantity($today) === 5,
        json_encode($coldStock->toArray())
    );

    $updated = $repo->upsertLot(new RestockerVanProductStock(
        null,
        $restockerA,
        $coldProductId,
        'TEST-T5-COLD-A',
        new DateTimeImmutable($coldExpiration),
        9,
        true
    ));

    $lotCount = (int)$pdo->query("
        SELECT COUNT(*)
        FROM restocker_van_product_stocks
        WHERE restocker_id = {$restockerA}
          AND product_id = {$coldProductId}
          AND batch_code = 'TEST-T5-COLD-A'
    ")->fetchColumn();

    $assert(
        '2.2 El segundo guardado actualiza la cantidad sin duplicar la fila',
        $lotCount === 1 && $updated->getQuantityAvailable() === 9,
        "filas={$lotCount} cantidad=" . $updated->getQuantityAvailable()
    );

    $dateAttempt = $repo->upsertLot(new RestockerVanProductStock(
        null,
        $restockerA,
        $coldProductId,
        'TEST-T5-COLD-A',
        new DateTimeImmutable($otherExpiration),
        4,
        true
    ));

    $assert(
        '2.3 Un lote existente conserva su fecha de caducidad (QA 3)',
        $dateAttempt->getExpirationDate()?->format('Y-m-d') === $coldExpiration
        && $dateAttempt->getQuantityAvailable() === 4,
        'fecha=' . (string)$dateAttempt->getExpirationDate()?->format('Y-m-d')
    );

    $expiredStock = $repo->upsertLot(new RestockerVanProductStock(
        null,
        $restockerA,
        $coldProductId,
        'TEST-T5-COLD-OLD',
        new DateTimeImmutable($expiredExpiration),
        3,
        true
    ));

    $assert(
        '2.4 El lote caducado se persiste con semáforo EXPIRED y stock útil cero',
        $expiredStock->expiryStatus($today)->value === 'EXPIRED'
        && $expiredStock->isExpired($today)
        && $expiredStock->usableQuantity($today) === 0
        && $expiredStock->getQuantityAvailable() === 3
    );

    $ambientStock = $repo->upsertLot(new RestockerVanProductStock(
        null,
        $restockerA,
        $ambientProductId,
        'SIN-LOTE',
        null,
        7,
        false
    ));

    $assert(
        '2.5 El producto sin frío viaja con SIN-LOTE, sin caducidad y es usable',
        $ambientStock->getBatchCode() === 'SIN-LOTE'
        && $ambientStock->getExpirationDate() === null
        && $ambientStock->expiryStatus($today)->value === 'OK'
        && $ambientStock->usableQuantity($today) === 7
    );

    // =================================================================
    // 3. Lectura FEFO y opacidad de costes (D1, D4, Art. V.4)
    // =================================================================
    echo "\n--- 3. Orden FEFO y proyección sin coste unitario ---\n";

    $restockerRows = $repo->findByRestocker($restockerA, null, null, 'TEST-PLD-T5');

    $assert(
        '3.1 findByRestocker devuelve los tres lotes propios',
        count($restockerRows) === 3,
        (string)count($restockerRows)
    );
    $assert(
        '3.2 Las filas llegan en orden FEFO con NULLS LAST',
        array_column($restockerRows, 'batch_code') === [
            'TEST-T5-COLD-OLD',
            'TEST-T5-COLD-A',
            'SIN-LOTE',
        ],
        json_encode(array_column($restockerRows, 'batch_code'))
    );
    $assert(
        '3.3 Cada fila hidrata producto, reponedor y semáforo',
        $restockerRows[1]['product_sku'] === 'TEST-PLD-T5-COLD'
        && $restockerRows[1]['product_category'] === ProductCategory::PERISHABLE_FOOD->value
        && $restockerRows[1]['requires_cold_chain'] === true
        && $restockerRows[1]['restocker_name'] === (string)$restockers[0]['name']
        && $restockerRows[1]['expiry_status'] === 'EXPIRING_SOON'
        && $restockerRows[1]['usable_quantity'] === 4,
        json_encode($restockerRows[1])
    );
    $assert(
        '3.4 Ninguna fila expone unit_cost_eur ni márgenes (Art. V.4)',
        array_reduce(
            $restockerRows,
            static fn (bool $carry, array $row): bool => $carry
                && !array_key_exists('unit_cost_eur', $row)
                && !array_key_exists('unit_margin_eur', $row),
            true
        )
    );

    $assert(
        '3.5 Un reponedor inválido no consulta nada',
        $repo->findByRestocker(0) === [] && $repo->sumUsableQuantityByProduct(0) === []
    );

    // =================================================================
    // 4. Stock útil no caducado por producto (D1, EARS 01.2)
    // =================================================================
    echo "\n--- 4. Agregación de stock útil por producto ---\n";

    $usable = $repo->sumUsableQuantityByProduct($restockerA, $today);

    $assert(
        '4.1 El lote caducado queda fuera de la suma útil',
        ($usable[$coldProductId] ?? 0) === 4,
        'refrigerado=' . ($usable[$coldProductId] ?? 0)
    );
    $assert(
        '4.2 El producto ambiente suma sus unidades vigentes',
        ($usable[$ambientProductId] ?? 0) === 7,
        'ambiente=' . ($usable[$ambientProductId] ?? 0)
    );

    // =================================================================
    // 5. Filtros de Coordinación
    // =================================================================
    echo "\n--- 5. Filtros por categoría, caducidad y búsqueda ---\n";

    $coldOnly = $repo->findByRestocker($restockerA, 'PERISHABLE_FOOD', null, 'TEST-PLD-T5');
    $assert(
        '5.1 El filtro de categoría deja solo los dos lotes refrigerados',
        count($coldOnly) === 2
        && array_reduce(
            $coldOnly,
            static fn (bool $carry, array $row): bool => $carry
                && $row['product_category'] === ProductCategory::PERISHABLE_FOOD->value,
            true
        ),
        (string)count($coldOnly)
    );

    $expiredOnly = $repo->findByRestocker($restockerA, null, 'EXPIRED', 'TEST-PLD-T5');
    $assert(
        '5.2 expiry_status=EXPIRED devuelve únicamente el lote vencido',
        count($expiredOnly) === 1 && $expiredOnly[0]['batch_code'] === 'TEST-T5-COLD-OLD',
        json_encode(array_column($expiredOnly, 'batch_code'))
    );

    $soonOnly = $repo->findByRestocker($restockerA, null, 'EXPIRING_SOON', 'TEST-PLD-T5');
    $assert(
        '5.3 expiry_status=EXPIRING_SOON devuelve únicamente el lote en ventana',
        count($soonOnly) === 1 && $soonOnly[0]['batch_code'] === 'TEST-T5-COLD-A',
        json_encode(array_column($soonOnly, 'batch_code'))
    );

    $okOnly = $repo->findByRestocker($restockerA, null, 'OK', 'TEST-PLD-T5');
    $assert(
        '5.4 expiry_status=OK devuelve el lote sin caducidad',
        count($okOnly) === 1 && $okOnly[0]['batch_code'] === 'SIN-LOTE',
        json_encode(array_column($okOnly, 'batch_code'))
    );

    $allStatuses = $repo->findByRestocker($restockerA, null, 'ALL', 'TEST-PLD-T5');
    $assert(
        '5.5 expiry_status=ALL no filtra por caducidad',
        count($allStatuses) === 3,
        (string)count($allStatuses)
    );

    $byBatch = $repo->findByRestocker($restockerA, null, null, 'TEST-T5-COLD-OLD');
    $assert(
        '5.6 La búsqueda libre alcanza el código de lote',
        count($byBatch) === 1 && $byBatch[0]['batch_code'] === 'TEST-T5-COLD-OLD'
    );

    $assertInvalidFilter = static function () use ($repo, $restockerA): bool {
        try {
            $repo->findByRestocker($restockerA, 'NOT_A_CATEGORY');
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    };
    $assert(
        '5.7 Una categoría fuera del catálogo se rechaza en voz alta',
        $assertInvalidFilter()
    );

    $assertInvalidStatus = static function () use ($repo, $restockerA): bool {
        try {
            $repo->findByRestocker($restockerA, null, 'SOON');
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    };
    $assert(
        '5.8 Un estado de caducidad desconocido se rechaza en voz alta',
        $assertInvalidStatus()
    );

    // =================================================================
    // 6. Búsqueda de Coordinación entre reponedores
    // =================================================================
    echo "\n--- 6. Inventario agregado de Coordinación ---\n";

    $repo->upsertLot(new RestockerVanProductStock(
        null,
        $restockerB,
        $ambientProductId,
        'TEST-T5-B-01',
        null,
        2,
        false
    ));

    $coordinatorRows = $repo->search(null, null, null, 'TEST-PLD-T5');

    $assert(
        '6.1 search() reúne los lotes de ambos reponedores',
        count($coordinatorRows) === 4
        && count(array_unique(array_column($coordinatorRows, 'restocker_id'))) === 2,
        (string)count($coordinatorRows)
    );
    $assert(
        '6.2 El ámbito de un reponedor no ve el lote del otro',
        count($repo->findByRestocker($restockerB, null, null, 'TEST-PLD-T5')) === 1
        && $repo->findByRestocker($restockerB, null, null, 'TEST-PLD-T5')[0]['batch_code'] === 'TEST-T5-B-01'
    );

    // =================================================================
    // 7. findLot y bloqueo pesimista (D3)
    // =================================================================
    echo "\n--- 7. findLot con y sin bloqueo ---\n";

    $lot = $repo->findLot($restockerA, $coldProductId, 'test-t5-cold-a');
    $assert(
        '7.1 findLot normaliza el lote y devuelve la entidad hidratada',
        $lot !== null
        && $lot->getBatchCode() === 'TEST-T5-COLD-A'
        && $lot->getQuantityAvailable() === 4
        && $lot->getExpirationDate()?->format('Y-m-d') === $coldExpiration
    );
    $assert(
        '7.2 findLot devuelve null para un lote o unos extremos inexistentes',
        $repo->findLot($restockerA, $coldProductId, 'NO-EXISTE') === null
        && $repo->findLot(0, $coldProductId, 'TEST-T5-COLD-A') === null
        && $repo->findLot($restockerA, 0, 'TEST-T5-COLD-A') === null
    );

    $pdo->beginTransaction();
    try {
        $locked = $repo->findLot($restockerA, $coldProductId, 'TEST-T5-COLD-A', true);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    $assert(
        '7.3 findLot(forUpdate) bloquea la fila dentro de una transacción',
        $locked !== null && $locked->getId() === $lot?->getId()
    );

    // =================================================================
    // 8. Art. III.1 y limpieza
    // =================================================================
    echo "\n--- 8. Prohibición de borrado físico y limpieza ---\n";

    $deleteRejection = PostgresFixtureBuilder::expectRejection(static function () use ($pdo, $coldStock): void {
        $pdo->exec('DELETE FROM restocker_van_product_stocks WHERE id = ' . (int)$coldStock->getId());
    });

    $assert(
        '8.1 El motor rechaza el DELETE físico con SQLSTATE 45000',
        $deleteRejection !== null
        && $deleteRejection['state'] === '45000'
        && str_contains($deleteRejection['message'], 'ART_III_1_VIOLATION'),
        $deleteRejection === null ? 'el DELETE se admitió' : 'estado=' . $deleteRejection['state']
    );

    $cleanup($pdo);

    $remaining = (int)$pdo->query("
        SELECT COUNT(*)
        FROM restocker_van_product_stocks
        WHERE product_id IN ({$coldProductId}, {$ambientProductId})
    ")->fetchColumn();
    $productsRemaining = (int)$pdo->query("
        SELECT COUNT(*) FROM vending_products WHERE sku LIKE 'TEST-PLD-T5%'
    ")->fetchColumn();

    $assert(
        '8.2 La suite retira sus lotes y sus referencias transitorias',
        $remaining === 0 && $productsRemaining === 0,
        "lotes={$remaining} productos={$productsRemaining}"
    );

    echo "\n======================================================================\n";
    echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

    if ($failures === 0) {
        echo " RESULTADO: 100% EN VERDE. REPOSITORIO DE STOCK POR LOTE CERTIFICADO.\n";
        echo "======================================================================\n";
        exit(0);
    }

    echo " RESULTADO: FALLOS DETECTADOS EN EL REPOSITORIO DE STOCK POR LOTE.\n";
    echo "======================================================================\n";
    exit(1);
} catch (Throwable $exception) {
    $cleanup($pdo);
    fwrite(STDERR, "PdoRestockerVanStockRepositoryTest failed: {$exception->getMessage()}\n");
    exit(1);
}
