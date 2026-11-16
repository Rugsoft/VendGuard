<?php

declare(strict_types=1);

/**
 * CashCollectionDomainModelsTest · T-CCV-03
 *
 * Certifica la capa de dominio del Módulo 35 antes de que exista un solo
 * servicio: el estado de custodia (`CashCollectionStatus`) y la entidad
 * inmutable `MachineCashCollection` con sus tres proyecciones segregadas.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Ciclo de custodia y estado público (RF-01.1, D4, C2, Art. V.4).** El
 *      enumerado declara los cuatro desenlaces en orden, distingue los dos
 *      terminales y funde el dictamen financiero en `PROCESSED_IN_VAULT` para el
 *      móvil del reponedor: el arqueo nunca se filtra.
 *
 *   2. **Identidades del corte (C1, D4).** El código `MCC-AAAA-NNNN` y el
 *      precinto global muerden en el borde, con normalización a mayúsculas y el
 *      bloque numérico de tres dígitos; la mutación del móvil exige seis
 *      caracteres reales.
 *
 *   3. **Aritmética del arqueo ciego (D2, C3).** `expected_cashbox_eur` no se
 *      acepta: se comprueba contra la diferencia real del contador, con lectura
 *      previa, con base inicial y con reseteo justificado por una nota de 20
 *      caracteres y tres bloques alfanuméricos (Art. V.1). Una lectura regresiva
 *      sin reseteo no construye la entidad.
 *
 *   4. **Entrega en conserjería (D3).** Sin entrega no hay conserje ni
 *      expediente vinculado; con entrega, el nombre del conserje es obligatorio
 *      y el importe no supera 10,00 €.
 *
 *   5. **Ciclo de vida del arqueo (D2, D4, QA 1).** Cada estado declara sus
 *      marcas, la cronología no retrocede, el descuadre es la resta exacta y la
 *      tolerancia de ±1,00 € decide entre `RECONCILED_OK` y
 *      `DISCREPANCY_FLAGGED`, que exige justificación real.
 *
 *   6. **Proyecciones segregadas (C2, QA 2, Art. V.4).** La vista del reponedor
 *      omite las cinco claves financieras privadas; el DTO de aplicación publica
 *      solo su lista blanca; la de Coordinación las incluye; el recibo de
 *      conserjería expone exactamente los nueve campos del contrato y ni un dato
 *      de la bolsa, de recaudación o PII del reclamante. `toArray()` y
 *      `jsonSerialize()` son la vista de mínimo privilegio.
 *
 * CATEGORÍA: hermética (`tests/unit/`). No toca la base de datos ni el reloj del
 * sistema: todos los instantes se declaran como argumento explícito.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\RestockerCashCollectionViewDTO;
use VendGuard\Core\Domain\Enum\CashCollectionStatus;
use VendGuard\Core\Domain\Model\MachineCashCollection;

$assertions = 0;

function assertCashCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param class-string<Throwable> $expected
 */
function assertCashThrows(callable $action, string $message, string $expected = InvalidArgumentException::class): void
{
    try {
        $action();
    } catch (Throwable $exception) {
        assertCashCondition(
            $exception instanceof $expected,
            $message . ' (se lanzó ' . $exception::class . ': ' . $exception->getMessage() . ')'
        );
        return;
    }

    assertCashCondition(false, $message . ' (no se lanzó ninguna excepción)');
}

/**
 * Argumentos canónicos de una recaudación sellada en ruta, listos para el
 * constructor con nombre. Cada prueba sobrescribe solo lo que certifica.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function cashCollectionArgs(array $overrides = []): array
{
    return array_merge([
        'id' => null,
        'collectionCode' => 'MCC-2026-0001',
        'restockVisitId' => null,
        'machineId' => 41,
        'machineCodeSnapshot' => 'VEND-CCV-001',
        'restockerId' => 7,
        'restockerNameSnapshot' => 'Lucía Ferrer',
        'restockerCodeSnapshot' => 'REP-007',
        'bagSealCode' => 'BAG-2026-000001',
        'changeTubesLoadedEur' => 20.00,
        'salesCounterReading' => 125.50,
        'previousSalesCounterReading' => 100.00,
        'initialBaselineReadingEur' => 0.00,
        'isCounterReset' => false,
        'counterResetNotes' => null,
        'expectedCashboxEur' => 25.50,
        'receptionCashDeliveredEur' => 0.00,
        'receptionistName' => null,
        'linkedRefundRequestId' => null,
        'routeNotes' => null,
        'status' => CashCollectionStatus::SEALED_IN_ROUTE,
        'collectedAt' => '2026-11-10 10:00:00+00',
        'clientMutationId' => 'mutation-ccv-0001',
        'locationName' => 'Sede de certificación',
        'locationCode' => 'SEDE-CCV-01',
    ], $overrides);
}

/**
 * @param array<string, mixed> $overrides
 */
function cashCollection(array $overrides = []): MachineCashCollection
{
    return new MachineCashCollection(...cashCollectionArgs($overrides));
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function deliveredCollectionArgs(array $overrides = []): array
{
    return cashCollectionArgs(array_merge([
        'status' => CashCollectionStatus::DELIVERED_TO_VAULT,
        'deliveredToVaultAt' => '2026-11-10 12:00:00+00',
        'deliveredToVaultBy' => 9,
    ], $overrides));
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function reconciledCollectionArgs(array $overrides = []): array
{
    return deliveredCollectionArgs(array_merge([
        'status' => CashCollectionStatus::RECONCILED_OK,
        'vaultCountedEur' => 26.00,
        'discrepancyEur' => 0.50,
        'vaultReconciledBy' => 9,
        'vaultReconciledByNameSnapshot' => 'Coordinadora de Caja',
        'vaultReconciledAt' => '2026-11-10 13:00:00+00',
    ], $overrides));
}

try {
    // ─────────────────────────────────────────────────────────────────────────
    // 1. CashCollectionStatus (Tabla 49, D4, C2, Art. V.4)
    // ─────────────────────────────────────────────────────────────────────────
    assertCashCondition(
        CashCollectionStatus::values() === [
            'SEALED_IN_ROUTE',
            'DELIVERED_TO_VAULT',
            'RECONCILED_OK',
            'DISCREPANCY_FLAGGED',
        ],
        'CashCollectionStatus exposes the four custody states in contract order'
    );
    assertCashCondition(
        CashCollectionStatus::SEALED_IN_ROUTE->label() === 'Sellada en ruta'
            && CashCollectionStatus::DELIVERED_TO_VAULT->label() === 'Entregada en Caja Central'
            && CashCollectionStatus::RECONCILED_OK->label() === 'Arqueada sin descuadre'
            && CashCollectionStatus::DISCREPANCY_FLAGGED->label() === 'Arqueada con descuadre',
        'CashCollectionStatus carries its Spanish labels'
    );
    assertCashCondition(
        CashCollectionStatus::SEALED_IN_ROUTE->publicRestockerStatus() === 'SEALED_IN_ROUTE'
            && CashCollectionStatus::DELIVERED_TO_VAULT->publicRestockerStatus() === 'DELIVERED_TO_VAULT'
            && CashCollectionStatus::RECONCILED_OK->publicRestockerStatus() === 'PROCESSED_IN_VAULT'
            && CashCollectionStatus::DISCREPANCY_FLAGGED->publicRestockerStatus() === 'PROCESSED_IN_VAULT',
        'The restocker only sees PROCESSED_IN_VAULT once the vault has settled the bag (C2)'
    );
    assertCashCondition(
        CashCollectionStatus::RECONCILED_OK->publicRestockerLabel() === 'Procesada en Caja Central'
            && CashCollectionStatus::DISCREPANCY_FLAGGED->publicRestockerLabel() === 'Procesada en Caja Central',
        'Both terminal outcomes share the same opaque public label (Art. V.4)'
    );
    assertCashCondition(
        CashCollectionStatus::RECONCILED_OK->isTerminal()
            && CashCollectionStatus::DISCREPANCY_FLAGGED->isTerminal()
            && !CashCollectionStatus::SEALED_IN_ROUTE->isTerminal()
            && !CashCollectionStatus::DELIVERED_TO_VAULT->isTerminal(),
        'Only the two reconciliation outcomes are terminal'
    );
    assertCashCondition(
        CashCollectionStatus::SEALED_IN_ROUTE->allowsVaultReception()
            && !CashCollectionStatus::DELIVERED_TO_VAULT->allowsVaultReception()
            && !CashCollectionStatus::RECONCILED_OK->allowsVaultReception(),
        'Only a sealed-in-route bag can be received in the vault (D4)'
    );
    assertCashCondition(
        CashCollectionStatus::SEALED_IN_ROUTE->allowsReconciliation()
            && CashCollectionStatus::DELIVERED_TO_VAULT->allowsReconciliation()
            && !CashCollectionStatus::DISCREPANCY_FLAGGED->allowsReconciliation(),
        'A terminal row cannot be reconciled twice (D4)'
    );
    assertCashCondition(
        CashCollectionStatus::fromString(' delivered_to_vault ') === CashCollectionStatus::DELIVERED_TO_VAULT
            && CashCollectionStatus::isValid('RECONCILED_OK')
            && !CashCollectionStatus::isValid('PROCESSED_IN_VAULT'),
        'CashCollectionStatus parses canonical values and rejects the synthetic public one'
    );
    assertCashThrows(
        static fn() => CashCollectionStatus::fromString('PENDING'),
        'CashCollectionStatus rejects an unknown custody state'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Alta mínima, normalización e inmutabilidad (C1, D4)
    // ─────────────────────────────────────────────────────────────────────────
    $sealed = cashCollection(['bagSealCode' => 'bag-2026-000001']);
    assertCashCondition(
        $sealed->getCollectionCode() === 'MCC-2026-0001'
            && $sealed->getBagSealCode() === 'BAG-2026-000001'
            && $sealed->getMachineId() === 41
            && $sealed->getRestockerId() === 7
            && $sealed->getExpectedCashboxEur() === 25.50
            && $sealed->getStatus() === CashCollectionStatus::SEALED_IN_ROUTE
            && $sealed->getId() === null,
        'The sealed collection keeps its identities and computes the private expected cashbox'
    );
    $identified = $sealed->withId(17);
    assertCashCondition(
        $identified->getId() === 17 && $sealed->getId() === null,
        'withId returns a copy and never rewrites the original entity'
    );
    assertCashCondition(
        $sealed->hasReceptionDelivery() === false && $sealed->isTerminal() === false,
        'A sealed collection has no reception delivery and is not terminal'
    );

    assertCashThrows(
        static fn() => cashCollection(['collectionCode' => 'MCC-26-1']),
        'A collection code outside MCC-AAAA-NNNN is rejected'
    );
    assertCashThrows(
        static fn() => cashCollection(['bagSealCode' => 'BAG-ABC-DEF']),
        'A bag seal without a three-digit numeric block is rejected (C1)'
    );
    assertCashThrows(
        static fn() => cashCollection(['bagSealCode' => 'BAG 2026 000001']),
        'A bag seal with spaces is rejected (C1)'
    );
    assertCashThrows(
        static fn() => cashCollection(['clientMutationId' => 'abc']),
        'A mutation identifier shorter than six characters is rejected (D4)'
    );
    assertCashThrows(
        static fn() => cashCollection(['machineCodeSnapshot' => '   ']),
        'An empty machine snapshot is rejected by the non-empty contract'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Aritmética del contador con y sin reseteo (D2, C3, Art. V.1)
    // ─────────────────────────────────────────────────────────────────────────
    assertCashThrows(
        static fn() => cashCollection(['expectedCashboxEur' => 20.00]),
        'An expected cashbox that is not the real counter difference is rejected'
    );
    assertCashThrows(
        static fn() => cashCollection([
            'salesCounterReading' => 90.00,
            'previousSalesCounterReading' => 100.00,
            'expectedCashboxEur' => -10.00,
        ]),
        'A counter regression without reset is rejected (C3)'
    );
    assertCashThrows(
        static fn() => cashCollection([
            'salesCounterReading' => 40.00,
            'previousSalesCounterReading' => null,
            'initialBaselineReadingEur' => 50.00,
            'expectedCashboxEur' => -10.00,
        ]),
        'An initial baseline above the counter reading is rejected (D2)'
    );
    assertCashThrows(
        static fn() => cashCollection([
            'isCounterReset' => true,
            'previousSalesCounterReading' => null,
            'counterResetNotes' => 'Monedero nuevo.',
            'expectedCashboxEur' => 125.50,
        ]),
        'A reset with a short note is rejected (Art. V.1)'
    );
    assertCashThrows(
        static fn() => cashCollection([
            'isCounterReset' => true,
            'previousSalesCounterReading' => null,
            'counterResetNotes' => null,
            'expectedCashboxEur' => 125.50,
        ]),
        'A reset without a note is rejected (C3)'
    );
    assertCashThrows(
        static fn() => cashCollection([
            'isCounterReset' => true,
            'previousSalesCounterReading' => null,
            'counterResetNotes' => 'Sustitución del monedero por avería del lector.',
            'expectedCashboxEur' => 100.00,
        ]),
        'A reset must use the full counter reading as expected cashbox'
    );

    $reset = cashCollection([
        'isCounterReset' => true,
        'previousSalesCounterReading' => null,
        'counterResetNotes' => '  Reseteo autorizado del contador tras sustitución del monedero.  ',
        'expectedCashboxEur' => 125.50,
    ]);
    assertCashCondition(
        $reset->isCounterReset()
            && $reset->getInitialBaselineReadingEur() === 0.0
            && $reset->getCounterResetNotes() === 'Reseteo autorizado del contador tras sustitución del monedero.'
            && $reset->getExpectedCashboxEur() === 125.50,
        'A justified reset normalizes its note and starts the counter from zero (D2, C3)'
    );

    $firstCollection = cashCollection([
        'salesCounterReading' => 180.00,
        'previousSalesCounterReading' => null,
        'initialBaselineReadingEur' => 50.00,
        'expectedCashboxEur' => 130.00,
    ]);
    assertCashCondition(
        $firstCollection->getInitialBaselineReadingEur() === 50.0
            && $firstCollection->getExpectedCashboxEur() === 130.00,
        'The first historical collection subtracts its declared baseline (D2)'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Entrega en conserjería (D3)
    // ─────────────────────────────────────────────────────────────────────────
    assertCashThrows(
        static fn() => cashCollection(['receptionCashDeliveredEur' => 4.50]),
        'A reception delivery without a receptionist is rejected (D3)'
    );
    assertCashThrows(
        static fn() => cashCollection([
            'receptionCashDeliveredEur' => 12.00,
            'receptionistName' => 'Carlos Ruiz',
        ]),
        'A reception delivery above 10,00 € is rejected (D3)'
    );
    assertCashThrows(
        static fn() => cashCollection([
            'receptionCashDeliveredEur' => 0.00,
            'receptionistName' => 'Carlos Ruiz',
        ]),
        'A receptionist without a delivery is rejected (D3)'
    );

    $reception = cashCollection([
        'receptionCashDeliveredEur' => 4.50,
        'receptionistName' => 'Carlos Ruiz',
        'linkedRefundRequestId' => 88,
    ]);
    assertCashCondition(
        $reception->hasReceptionDelivery()
            && $reception->getReceptionistName() === 'Carlos Ruiz'
            && $reception->getLinkedRefundRequestId() === 88,
        'The reception delivery keeps its receptionist and linked refund (D3)'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Ciclo de vida del arqueo (D2, D4, QA 1)
    // ─────────────────────────────────────────────────────────────────────────
    assertCashThrows(
        static fn() => cashCollection([
            'deliveredToVaultAt' => '2026-11-10 12:00:00+00',
            'deliveredToVaultBy' => 9,
        ]),
        'A sealed collection cannot declare vault delivery marks'
    );
    assertCashThrows(
        static fn() => new MachineCashCollection(...deliveredCollectionArgs([
            'deliveredToVaultAt' => '2026-11-10 09:00:00+00',
        ])),
        'A vault delivery before the collection instant is rejected'
    );
    assertCashThrows(
        static fn() => new MachineCashCollection(...deliveredCollectionArgs([
            'vaultCountedEur' => 25.50,
            'discrepancyEur' => 0.00,
        ])),
        'A delivered bag cannot declare vault counting before reconciliation'
    );
    assertCashThrows(
        static fn() => new MachineCashCollection(...reconciledCollectionArgs([
            'vaultCountedEur' => 27.50,
            'discrepancyEur' => 2.00,
        ])),
        'RECONCILED_OK above the ±1,00 € tolerance is rejected (D2)'
    );
    assertCashThrows(
        static fn() => new MachineCashCollection(...reconciledCollectionArgs([
            'vaultCountedEur' => 20.00,
            'discrepancyEur' => -5.50,
            'status' => CashCollectionStatus::DISCREPANCY_FLAGGED,
        ])),
        'DISCREPANCY_FLAGGED without a real justification is rejected (Art. V.1)'
    );
    assertCashThrows(
        static fn() => new MachineCashCollection(...reconciledCollectionArgs([
            'discrepancyEur' => 1.00,
        ])),
        'The discrepancy must be the exact subtraction of the vault count and the expected cashbox'
    );

    $delivered = new MachineCashCollection(...deliveredCollectionArgs());
    assertCashCondition(
        $delivered->getStatus() === CashCollectionStatus::DELIVERED_TO_VAULT
            && $delivered->getDeliveredToVaultAt() === '2026-11-10 12:00:00+00'
            && $delivered->getVaultCountedEur() === null
            && !$delivered->isTerminal(),
        'A delivered bag carries its vault marks and stays open for reconciliation (D4)'
    );

    $reconciled = new MachineCashCollection(...reconciledCollectionArgs());
    assertCashCondition(
        $reconciled->getStatus() === CashCollectionStatus::RECONCILED_OK
            && $reconciled->getDiscrepancyEur() === 0.50
            && $reconciled->getVaultReconciledBy() === 9
            && $reconciled->getVaultReconciledByNameSnapshot() === 'Coordinadora de Caja'
            && $reconciled->isTerminal(),
        'A reconciled bag inside tolerance keeps the computed discrepancy and becomes terminal (D2)'
    );

    $flagged = new MachineCashCollection(...reconciledCollectionArgs([
        'status' => CashCollectionStatus::DISCREPANCY_FLAGGED,
        'vaultCountedEur' => 20.00,
        'discrepancyEur' => -5.50,
        'discrepancyJustification' => 'Faltan cinco euros y cincuenta céntimos en la bolsa precintada.',
    ]));
    assertCashCondition(
        $flagged->getStatus() === CashCollectionStatus::DISCREPANCY_FLAGGED
            && $flagged->getDiscrepancyEur() === -5.50
            && $flagged->getDiscrepancyJustification() === 'Faltan cinco euros y cincuenta céntimos en la bolsa precintada.',
        'A flagged discrepancy stores its negative amount and justified note (D2, Art. V.1)'
    );

    $reconciledWithNote = new MachineCashCollection(...reconciledCollectionArgs([
        'discrepancyJustification' => 'Pequeño ajuste de cambio.',
    ]));
    assertCashCondition(
        $reconciledWithNote->getDiscrepancyJustification() === 'Pequeño ajuste de cambio.',
        'A reconciled bag accepts an optional short observation (D2)'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Proyección del reponedor: Arqueo Ciego estricto (C2, Art. V.4)
    // ─────────────────────────────────────────────────────────────────────────
    $privateKeys = [
        'previous_sales_counter_reading',
        'initial_baseline_reading_eur',
        'expected_cashbox_eur',
        'vault_counted_eur',
        'discrepancy_eur',
        'discrepancy_justification',
    ];

    $restockerView = $flagged->toRestockerArray();
    foreach ($privateKeys as $key) {
        assertCashCondition(
            !array_key_exists($key, $restockerView),
            'The restocker projection must never contain ' . $key . ' (C2)'
        );
    }

    $dto = RestockerCashCollectionViewDTO::fromMachineCashCollection($flagged);
    assertCashCondition(
        $dto instanceof RestockerCashCollectionViewDTO,
        'The mobile boundary exposes a concrete RestockerCashCollectionViewDTO (RNF-02.3)'
    );
    $dtoView = $dto->toArray();
    assertCashCondition(
        array_keys($dtoView) === [
            'id',
            'collection_code',
            'restock_visit_id',
            'machine_id',
            'machine_code',
            'bag_seal_code',
            'sales_counter_reading',
            'change_tubes_loaded_eur',
            'is_counter_reset',
            'counter_reset_notes',
            'reception_cash_delivered_eur',
            'receptionist_name',
            'linked_refund_request_id',
            'has_reception_receipt',
            'status',
            'status_label',
            'collected_at',
            'created_at',
        ],
        'The DTO publishes exactly its whitelist of public fields (RNF-02.3)'
    );
    assertCashCondition(
        $dtoView === $restockerView,
        'The DTO and the domain projection agree on the mobile payload (RNF-02.3)'
    );
    foreach ($privateKeys as $key) {
        assertCashCondition(
            !array_key_exists($key, $dtoView),
            'The DTO must never contain ' . $key . ' (RNF-02.3, Art. V.4)'
        );
    }

    assertCashCondition(
        $restockerView['collection_code'] === 'MCC-2026-0001'
            && $restockerView['bag_seal_code'] === 'BAG-2026-000001'
            && $restockerView['machine_code'] === 'VEND-CCV-001'
            && $restockerView['sales_counter_reading'] === 125.50
            && $restockerView['change_tubes_loaded_eur'] === 20.00
            && $restockerView['status'] === 'PROCESSED_IN_VAULT'
            && $restockerView['status_label'] === 'Procesada en Caja Central',
        'The restocker sees what he declared plus the opaque custody status (C2)'
    );

    $coordinatorView = $flagged->toCoordinatorArray();
    assertCashCondition(
        $coordinatorView['previous_sales_counter_reading'] === 100.00
            && $coordinatorView['expected_cashbox_eur'] === 25.50
            && $coordinatorView['vault_counted_eur'] === 20.00
            && $coordinatorView['discrepancy_eur'] === -5.50
            && $coordinatorView['discrepancy_justification'] === 'Faltan cinco euros y cincuenta céntimos en la bolsa precintada.'
            && $coordinatorView['status'] === 'DISCREPANCY_FLAGGED'
            && $coordinatorView['is_terminal'] === true
            && $coordinatorView['client_mutation_id'] === 'mutation-ccv-0001',
        'The coordinator projection carries the full financial audit view (D2, D4, QA 1)'
    );

    assertCashCondition(
        $flagged->toArray() === $restockerView
            && $flagged->jsonSerialize() === $restockerView,
        'toArray and jsonSerialize default to the least-privilege restocker view'
    );
    $encoded = json_encode($flagged, JSON_THROW_ON_ERROR);
    assertCashCondition(
        !str_contains($encoded, 'expected_cashbox_eur')
            && !str_contains($encoded, 'vault_counted_eur')
            && !str_contains($encoded, 'discrepancy_eur')
            && !str_contains($encoded, 'previous_sales_counter_reading'),
        'A default JSON serialization leaks no private financial key (Art. V.4)'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Recibo de conserjería: nueve campos y nada más (QA 2, Art. V.4)
    // ─────────────────────────────────────────────────────────────────────────
    assertCashThrows(
        static fn() => $sealed->toReceptionReceiptArray(),
        'A collection without reception delivery has no receipt to issue (QA 2)',
        LogicException::class
    );

    $receipt = $reception->toReceptionReceiptArray();
    assertCashCondition(
        array_keys($receipt) === [
            'collection_code',
            'collected_at',
            'location_code',
            'location_name',
            'machine_code',
            'restocker_name',
            'receptionist_name',
            'reception_cash_delivered_eur',
            'linked_refund_request_id',
        ],
        'The reception receipt exposes exactly the nine contract fields (QA 2)'
    );
    assertCashCondition(
        $receipt['collection_code'] === 'MCC-2026-0001'
            && $receipt['location_code'] === 'SEDE-CCV-01'
            && $receipt['location_name'] === 'Sede de certificación'
            && $receipt['machine_code'] === 'VEND-CCV-001'
            && $receipt['restocker_name'] === 'Lucía Ferrer'
            && $receipt['receptionist_name'] === 'Carlos Ruiz'
            && $receipt['reception_cash_delivered_eur'] === 4.50
            && $receipt['linked_refund_request_id'] === 88,
        'The receipt carries the site, machine, restocker and receptionist without financial bag data'
    );
    foreach ([
        'bag_seal_code',
        'sales_counter_reading',
        'previous_sales_counter_reading',
        'change_tubes_loaded_eur',
        'expected_cashbox_eur',
        'vault_counted_eur',
        'discrepancy_eur',
        'discrepancy_justification',
        'claimant_name',
        'claimant_contact',
        'iban',
        'bizum_phone',
        'pickup_pin',
    ] as $forbidden) {
        assertCashCondition(
            !array_key_exists($forbidden, $receipt),
            'The reception receipt must not expose ' . $forbidden . ' (QA 2, Art. V.4)'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. Guarda documental FIX-35-07: paridad SDD y 4 resoluciones QA
    // ─────────────────────────────────────────────────────────────────────────
    $root = dirname(__DIR__, 2);
    $specDocs = [
        'spec' => (string)file_get_contents($root . '/specs/35-cash-collection-vault-audit/spec.md'),
        'mirror' => (string)file_get_contents($root . '/specs/functional/cash_collection_vault_audit_spec.md'),
        'contracts' => (string)file_get_contents($root . '/specs/technical/cash_collection_vault_audit_contracts.md'),
        'plan' => (string)file_get_contents($root . '/specs/35-cash-collection-vault-audit/plan.md'),
        'tasks' => (string)file_get_contents($root . '/specs/35-cash-collection-vault-audit/tasks.md'),
        'matrix' => (string)file_get_contents($root . '/specs/35-cash-collection-vault-audit/traceability-matrix.md'),
        'odd' => (string)file_get_contents($root . '/odd/tasks/cash-collection-vault-audit.md'),
    ];

    assertCashCondition(
        $specDocs['spec'] !== '' && $specDocs['spec'] === $specDocs['mirror'],
        'The canonical spec and functional mirror for Module 35 stay synchronized (FIX-35-07)'
    );

    foreach ($specDocs as $docKey => $content) {
        assertCashCondition(
            preg_match('/\b17\s+(?:puntos|resoluciones)\b/iu', $content) === 0,
            'Module 35 document «' . $docKey . '» must not retain unverified 17-point QA claims (FIX-35-07)'
        );
    }

    assertCashCondition(
        !str_contains($specDocs['odd'], '3 resoluciones de auditoría QA'),
        'The Module 35 ODD log must not retain the stale 3-resolution QA count (FIX-35-07)'
    );

    assertCashCondition(
        str_contains($specDocs['spec'], '**Versión:** 2.1')
            && str_contains($specDocs['plan'], '**Versión:** 2.1')
            && str_contains($specDocs['tasks'], '**Versión:** 2.1')
            && str_contains($specDocs['contracts'], '(v2.1')
            && str_contains($specDocs['matrix'], '(v2.1')
            && str_contains($specDocs['odd'], 'v2.1'),
        'Module 35 SDD artifacts declare the amended v2.1 alignment (FIX-35-07)'
    );

    assertCashCondition(
        str_contains($specDocs['spec'], 'QA 1')
            && str_contains($specDocs['spec'], 'QA 2')
            && str_contains($specDocs['spec'], 'QA 3')
            && str_contains($specDocs['spec'], 'QA 4')
            && str_contains($specDocs['spec'], '4 resoluciones de auditoría QA agregadas'),
        'The Module 35 spec records the 4 aggregated QA resolutions consistently (FIX-35-07)'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
}

echo "======================================================================\n";
echo " VENDGUARD · T-CCV-03 · Modelos de dominio de la recaudación\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE\n";
echo "======================================================================\n";
exit(0);
