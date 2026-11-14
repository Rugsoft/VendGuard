<?php

declare(strict_types=1);

/**
 * InSituRestockVisitDomainModelsTest · T-RVF-03
 *
 * Certifica la capa de dominio del Módulo 34 antes de que exista un solo servicio:
 * el desenlace de la visita (`RestockVisitStatus`), el checklist adaptativo de
 * higiene (`DailyHygieneChecklist`), las dos entidades históricas
 * (`MachineRestockVisit` y `MachineRestockVisitItem`) y la evolución del carril
 * del planograma con lote y caducidad vigentes (`D1`, EARS 01.1.2).
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Checklist adaptativo (D3, EARS 03.1).** `HOT_DRINKS`/`COMBO` exigen los
 *      cuatro puntos de final de carga; `COLD_DRINKS`/`SNACKS`/`PERISHABLE_FOOD`
 *      exigen dos y normalizan los tres indicadores de café a `null`, de modo que
 *      una máquina sin cafetera no declare un incumplimiento imposible.
 *
 *   2. **Cabecera histórica (RF-01.2, D4, QA 4, Art. II).** El código `MRV-AAAA-NNNN`
 *      y la clave de idempotencia del móvil muerden en el borde; la lectura de
 *      cabina se acota al rango físico plausible y se redondea a la décima del
 *      motor; el cierre no puede preceder a la apertura; y un aborto térmico sin
 *      precinto, lectura, foto y nota de 20 caracteres reales no se construye.
 *
 *   3. **Línea de visita (D1, C1, QA 3).** `qty_after` y la merma comercial se
 *      **calculan**, no se aceptan del cliente: la misma identidad aritmética que
 *      impone el motor. Con `qty_added > 0` el lote es obligatorio y los lotes
 *      respetan el formato cerrado del contrato.
 *
 *   4. **Opacidad de costes (Art. V.4, QA 3).** `toArray()` y `jsonSerialize()`
 *      omiten `unit_cost_snapshot_eur` y `commercial_spoilage_loss_eur`; solo la
 *      proyección explícita de Coordinación los incluye.
 *
 *   5. **Semáforo sanitario del carril (EARS 01.1.2, QA 1).** `EXPIRED` cubre la
 *      caducidad de hoy, `EXPIRING_SOON` los dos días siguientes y un carril vacío
 *      o sin fecha nunca alerta: no hay producto que retirar.
 *
 * CATEGORÍA: hermética (`tests/unit/`). No toca la base de datos ni el reloj del
 * sistema: todas las fechas se declaran como argumento explícito.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Enum\BatchExpiryStatus;
use VendGuard\Core\Domain\Enum\RestockVisitStatus;
use VendGuard\Core\Domain\Model\DailyHygieneChecklist;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineRestockVisit;
use VendGuard\Core\Domain\Model\MachineRestockVisitItem;
use VendGuard\Core\Domain\Model\MachineType;

$assertions = 0;

function assertInSituCondition(bool $condition, string $message): void
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
function assertInSituThrows(callable $action, string $message, string $expected = InvalidArgumentException::class): void
{
    try {
        $action();
    } catch (Throwable $exception) {
        assertInSituCondition(
            $exception instanceof $expected,
            $message . ' (se lanzó ' . $exception::class . ': ' . $exception->getMessage() . ')'
        );
        return;
    }

    assertInSituCondition(false, $message . ' (no se lanzó ninguna excepción)');
}

try {
    $today = new DateTimeImmutable('2026-11-14');

    // ─────────────────────────────────────────────────────────────────────────
    // 1. RestockVisitStatus (Tabla 47, D2, QA 4)
    // ─────────────────────────────────────────────────────────────────────────
    assertInSituCondition(
        RestockVisitStatus::values() === ['COMPLETED', 'ABORTED_THERMAL_BREACH'],
        'RestockVisitStatus exposes the two outcomes of the contract in order'
    );
    assertInSituCondition(
        RestockVisitStatus::COMPLETED->label() === 'Completada'
            && RestockVisitStatus::ABORTED_THERMAL_BREACH->label() === 'Abortada por rotura de cadena de frío',
        'RestockVisitStatus carries its Spanish labels'
    );
    assertInSituCondition(
        RestockVisitStatus::COMPLETED->isCompleted()
            && !RestockVisitStatus::COMPLETED->isThermalAbort()
            && RestockVisitStatus::COMPLETED->allowsLoading()
            && !RestockVisitStatus::COMPLETED->requiresSanitaryProtocol(),
        'A completed visit allows loading and needs no sanitary protocol'
    );
    assertInSituCondition(
        RestockVisitStatus::ABORTED_THERMAL_BREACH->isThermalAbort()
            && !RestockVisitStatus::ABORTED_THERMAL_BREACH->allowsLoading()
            && RestockVisitStatus::ABORTED_THERMAL_BREACH->requiresSanitaryProtocol(),
        'A thermal abort forbids loading and demands the sanitary protocol'
    );
    assertInSituCondition(
        RestockVisitStatus::fromString(' aborted_thermal_breach ') === RestockVisitStatus::ABORTED_THERMAL_BREACH
            && RestockVisitStatus::isValid('COMPLETED')
            && !RestockVisitStatus::isValid('PARTIAL'),
        'RestockVisitStatus parses canonical values and rejects unknown ones'
    );
    assertInSituThrows(
        static fn() => RestockVisitStatus::fromString('PARTIAL'),
        'RestockVisitStatus rejects an unknown outcome'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 2. DailyHygieneChecklist adaptativo (D3, EARS 03.1)
    // ─────────────────────────────────────────────────────────────────────────
    assertInSituCondition(
        DailyHygieneChecklist::requiresCoffeeItems(MachineType::HOT_DRINKS)
            && DailyHygieneChecklist::requiresCoffeeItems(MachineType::COMBO)
            && !DailyHygieneChecklist::requiresCoffeeItems(MachineType::COLD_DRINKS)
            && !DailyHygieneChecklist::requiresCoffeeItems(MachineType::SNACKS)
            && !DailyHygieneChecklist::requiresCoffeeItems(MachineType::PERISHABLE_FOOD),
        'Only hot drinks and combo machines carry the coffee checklist'
    );
    assertInSituCondition(
        count(DailyHygieneChecklist::requiredItemsFor(MachineType::COMBO)) === 4
            && count(DailyHygieneChecklist::requiredItemsFor(MachineType::SNACKS)) === 2,
        'Combo requires four points and snacks requires two'
    );

    $emptyCombo = DailyHygieneChecklist::emptyFor(MachineType::COMBO);
    assertInSituCondition(
        !$emptyCombo->isCompleteFor(MachineType::COMBO)
            && $emptyCombo->missingItemsFor(MachineType::COMBO) === DailyHygieneChecklist::HOT_REQUIRED_ITEMS,
        'An empty checklist misses the four mandatory points of a combo machine'
    );
    assertInSituCondition(
        DailyHygieneChecklist::ALL_ITEMS === [
            'coffee_grounds_emptied',
            'liquid_waste_bucket_cleaned',
            'mixers_and_ducts_rinsed',
            'dispensing_tray_and_glass_cleaned',
            'cabin_spills_checked',
        ],
        'The canonical document declares the five keys in contract order'
    );

    $hotChecklist = DailyHygieneChecklist::fromArray([
        'coffee_grounds_emptied' => true,
        'liquid_waste_bucket_cleaned' => 'true',
        'mixers_and_ducts_rinsed' => 1,
        'dispensing_tray_and_glass_cleaned' => 't',
    ], MachineType::HOT_DRINKS);
    assertInSituCondition(
        $hotChecklist->isCompleteFor(MachineType::HOT_DRINKS)
            && $hotChecklist->missingItemsFor(MachineType::HOT_DRINKS) === []
            && $hotChecklist->isChecked('mixers_and_ducts_rinsed') === true,
        'The four hot points can arrive as bool, string or integer and complete the checklist'
    );

    $incompleteHot = $hotChecklist->withChecked('coffee_grounds_emptied', false);
    assertInSituCondition(
        !$incompleteHot->isCompleteFor(MachineType::HOT_DRINKS)
            && $incompleteHot->missingItemsFor(MachineType::HOT_DRINKS) === ['coffee_grounds_emptied']
            && $hotChecklist->isChecked('coffee_grounds_emptied') === true,
        'withChecked returns a copy and names the missing point'
    );

    $coldChecklist = DailyHygieneChecklist::fromArray([
        'coffee_grounds_emptied' => true,
        'liquid_waste_bucket_cleaned' => true,
        'mixers_and_ducts_rinsed' => true,
        'dispensing_tray_and_glass_cleaned' => true,
        'cabin_spills_checked' => true,
    ], MachineType::COLD_DRINKS);
    assertInSituCondition(
        $coldChecklist->toArray() === [
            'coffee_grounds_emptied' => null,
            'liquid_waste_bucket_cleaned' => null,
            'mixers_and_ducts_rinsed' => null,
            'dispensing_tray_and_glass_cleaned' => true,
            'cabin_spills_checked' => true,
        ],
        'A machine without coffee normalizes the three coffee points to null (D3)'
    );
    assertInSituCondition(
        $coldChecklist->isCompleteFor(MachineType::COLD_DRINKS),
        'The two cabin points complete a cold drinks checklist'
    );

    $incompleteCold = DailyHygieneChecklist::fromArray([
        'dispensing_tray_and_glass_cleaned' => true,
        'cabin_spills_checked' => false,
    ], MachineType::PERISHABLE_FOOD);
    assertInSituCondition(
        !$incompleteCold->isCompleteFor(MachineType::PERISHABLE_FOOD)
            && $incompleteCold->missingItemsFor(MachineType::PERISHABLE_FOOD) === ['cabin_spills_checked'],
        'A false cabin point leaves the perishable checklist incomplete'
    );

    $jsonChecklist = DailyHygieneChecklist::fromJson(
        '{"dispensing_tray_and_glass_cleaned":true,"cabin_spills_checked":true,"coffee_grounds_emptied":null}',
        MachineType::SNACKS
    );
    assertInSituCondition(
        $jsonChecklist->isCompleteFor(MachineType::SNACKS)
            && $jsonChecklist->toJson() === '{"coffee_grounds_emptied":null,"liquid_waste_bucket_cleaned":null,"mixers_and_ducts_rinsed":null,"dispensing_tray_and_glass_cleaned":true,"cabin_spills_checked":true}',
        'The persisted JSONB round-trips into the canonical five-key document'
    );
    assertInSituCondition(
        json_decode($jsonChecklist->toJson(), true, 8, JSON_THROW_ON_ERROR) === $jsonChecklist->toArray(),
        'jsonSerialize and toJson agree on the canonical document'
    );
    assertInSituThrows(
        static fn() => DailyHygieneChecklist::fromArray(['cofee_grounds_emptied' => true], MachineType::COMBO),
        'An unknown checklist key is rejected instead of silently ignored'
    );
    assertInSituThrows(
        static fn() => DailyHygieneChecklist::fromArray(['cabin_spills_checked' => 'quizá'], MachineType::SNACKS),
        'A checklist point that is not a boolean is rejected'
    );
    assertInSituThrows(
        static fn() => DailyHygieneChecklist::fromJson('no-es-json', MachineType::SNACKS),
        'A corrupted persisted checklist is rejected'
    );
    assertInSituThrows(
        static fn() => $jsonChecklist->isChecked('unknown_point'),
        'Reading a key outside the checklist is rejected'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 3. MachineRestockVisit (Tabla 47, RF-01.2, D4, QA 4, Art. II)
    // ─────────────────────────────────────────────────────────────────────────
    $completedChecklist = DailyHygieneChecklist::fromArray([
        'coffee_grounds_emptied' => true,
        'liquid_waste_bucket_cleaned' => true,
        'mixers_and_ducts_rinsed' => true,
        'dispensing_tray_and_glass_cleaned' => true,
    ], MachineType::COMBO);

    $completedVisit = new MachineRestockVisit(
        id: null,
        visitCode: ' mrv-2026-0001 ',
        machineId: 42,
        restockerId: 12,
        status: RestockVisitStatus::COMPLETED,
        clientMutationId: '  mutation-abc-0001  ',
        hygieneChecklist: $completedChecklist,
        cabinTempCelsius: 3.456,
        startedAt: '2026-11-14T08:00:00+00:00',
        completedAt: '2026-11-14T08:20:00+00:00'
    );
    assertInSituCondition(
        $completedVisit->getVisitCode() === 'MRV-2026-0001'
            && $completedVisit->getClientMutationId() === 'mutation-abc-0001'
            && $completedVisit->getCabinTempCelsius() === 3.5
            && $completedVisit->getStatus() === RestockVisitStatus::COMPLETED
            && $completedVisit->isCompleted()
            && $completedVisit->getHygieneChecklist() === $completedChecklist,
        'A completed visit normalizes its code, trims the mutation key and rounds the cabin reading'
    );
    assertInSituCondition(
        !$completedVisit->isPrecautionarySealApplied()
            && $completedVisit->getPhotoEvidenceUrl() === null
            && $completedVisit->getInternalNotes() === null
            && $completedVisit->getLoadingOrderId() === null
            && $completedVisit->getTriggeredIncidentId() === null,
        'A clean completed visit needs no seal, photo, notes or links'
    );
    assertInSituCondition(
        !$completedVisit->requiresPhotoEvidence(false)
            && $completedVisit->requiresPhotoEvidence(true)
            && !$completedVisit->requiresInternalNotes(false)
            && $completedVisit->requiresInternalNotes(true),
        'Photo and notes are required only for a thermal abort or when units were withdrawn (EARS 03.2)'
    );

    assertInSituThrows(
        static fn() => new MachineRestockVisit(null, 'MRV-26-1', 42, 12, RestockVisitStatus::COMPLETED, 'mutation-abc', $completedChecklist),
        'A visit code outside MRV-AAAA-NNNN is rejected'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisit(null, 'MRV-2026-0001', 0, 12, RestockVisitStatus::COMPLETED, 'mutation-abc', $completedChecklist),
        'A visit requires an existing machine'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisit(null, 'MRV-2026-0001', 42, 0, RestockVisitStatus::COMPLETED, 'mutation-abc', $completedChecklist),
        'A visit requires an existing restocker'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisit(null, 'MRV-2026-0001', 42, 12, RestockVisitStatus::COMPLETED, 'abcde', $completedChecklist),
        'A client mutation id shorter than six characters is rejected (D4)'
    );
    assertInSituCondition(
        (new MachineRestockVisit(null, 'MRV-2026-0002', 42, 12, RestockVisitStatus::COMPLETED, 'abcdef', $completedChecklist))->getClientMutationId() === 'abcdef',
        'The lower mutation id bound of six characters is accepted'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisit(null, 'MRV-2026-0001', 42, 12, RestockVisitStatus::COMPLETED, 'mutation-abc', $completedChecklist, 36.0),
        'A cabin reading above 35,0 °C is rejected (QA 4)'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisit(null, 'MRV-2026-0001', 42, 12, RestockVisitStatus::COMPLETED, 'mutation-abc', $completedChecklist, -5.1),
        'A cabin reading below -5,0 °C is rejected (QA 4)'
    );
    assertInSituCondition(
        (new MachineRestockVisit(null, 'MRV-2026-0003', 42, 12, RestockVisitStatus::COMPLETED, 'mutation-abc', $completedChecklist, -5.0))->getCabinTempCelsius() === -5.0
            && (new MachineRestockVisit(null, 'MRV-2026-0004', 42, 12, RestockVisitStatus::COMPLETED, 'mutation-abc', $completedChecklist, 35.0))->getCabinTempCelsius() === 35.0,
        'Both plausible physical bounds are accepted'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisit(
            null,
            'MRV-2026-0001',
            42,
            12,
            RestockVisitStatus::COMPLETED,
            'mutation-abc',
            $completedChecklist,
            3.0,
            false,
            null,
            null,
            null,
            null,
            '2026-11-14T09:00:00+00:00',
            '2026-11-14T08:00:00+00:00'
        ),
        'A visit closed before it started is rejected'
    );
    assertInSituCondition(
        (new MachineRestockVisit(
            null,
            'MRV-2026-0005',
            42,
            12,
            RestockVisitStatus::COMPLETED,
            'mutation-abc',
            $completedChecklist,
            3.0,
            false,
            null,
            null,
            null,
            null,
            '2026-11-14T08:00:00+00:00',
            '2026-11-14T08:00:00+00:00'
        ))->getCompletedAt() === '2026-11-14T08:00:00+00:00',
        'An instant visit (start equals completion) is accepted'
    );

    $abortNotes = 'Apertura con 7,5 °C en cabina: precintada y SAT avisado.';
    assertInSituThrows(
        static fn() => new MachineRestockVisit(
            null,
            'MRV-2026-0006',
            42,
            12,
            RestockVisitStatus::ABORTED_THERMAL_BREACH,
            'mutation-abort',
            $completedChecklist,
            7.5,
            false,
            '/uploads/abort.jpg',
            $abortNotes
        ),
        'A thermal abort without the precautionary seal is rejected (Art. II)'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisit(
            null,
            'MRV-2026-0007',
            42,
            12,
            RestockVisitStatus::ABORTED_THERMAL_BREACH,
            'mutation-abort',
            $completedChecklist,
            null,
            true,
            '/uploads/abort.jpg',
            $abortNotes
        ),
        'A thermal abort without the cabin reading is rejected'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisit(
            null,
            'MRV-2026-0008',
            42,
            12,
            RestockVisitStatus::ABORTED_THERMAL_BREACH,
            'mutation-abort',
            $completedChecklist,
            7.5,
            true,
            null,
            $abortNotes
        ),
        'A thermal abort without photo evidence is rejected (Art. V.5)'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisit(
            null,
            'MRV-2026-0009',
            42,
            12,
            RestockVisitStatus::ABORTED_THERMAL_BREACH,
            'mutation-abort',
            $completedChecklist,
            7.5,
            true,
            '/uploads/abort.jpg',
            'caducó el lote'
        ),
        'A thermal abort with a note under 20 real characters is rejected (Art. V.1)'
    );

    $abortedVisit = new MachineRestockVisit(
        null,
        'MRV-2026-0010',
        42,
        12,
        RestockVisitStatus::ABORTED_THERMAL_BREACH,
        'mutation-abort',
        $completedChecklist,
        7.5,
        true,
        '/uploads/abort.jpg',
        str_repeat('á', 20)
    );
    assertInSituCondition(
        $abortedVisit->isThermalAbort()
            && $abortedVisit->isPrecautionarySealApplied()
            && $abortedVisit->requiresPhotoEvidence(false)
            && $abortedVisit->getPhotoEvidenceUrl() === '/uploads/abort.jpg',
        'A complete thermal abort with twenty real multibyte characters is accepted'
    );

    assertInSituCondition(
        $completedVisit->withId(91)->getId() === 91 && $completedVisit->getId() === null,
        'withId assigns the persisted identifier without mutating the original'
    );
    assertInSituCondition(
        $abortedVisit->withTriggeredIncident(555)->getTriggeredIncidentId() === 555
            && $abortedVisit->getTriggeredIncidentId() === null,
        'withTriggeredIncident links the SAT ticket in a copy (QA 2)'
    );
    assertInSituCondition(
        $completedVisit->withLoadingOrder(33)->getLoadingOrderId() === 33,
        'withLoadingOrder links the loading order that supplied the visit (EARS 03.3)'
    );
    assertInSituThrows(
        static fn() => $completedVisit->withLoadingOrder(0),
        'A zero loading order identifier is rejected'
    );

    assertInSituCondition(
        $completedVisit->toArray()['status'] === 'COMPLETED'
            && $completedVisit->toArray()['status_label'] === 'Completada'
            && $completedVisit->toArray()['hygiene_checklist'] === $completedChecklist->toArray()
            && $completedVisit->toArray()['is_thermal_abort'] === false
            && $abortedVisit->toArray()['is_thermal_abort'] === true,
        'MachineRestockVisit projects its status, checklist and thermal flag'
    );
    assertInSituCondition(
        json_decode((string)json_encode($completedVisit, JSON_THROW_ON_ERROR), true)['visit_code'] === 'MRV-2026-0001',
        'MachineRestockVisit serializes to JSON'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 4. MachineRestockVisitItem (Tabla 48, D1, C1, QA 3, Art. V.4)
    // ─────────────────────────────────────────────────────────────────────────
    $emptyLine = new MachineRestockVisitItem(null, 91, 4, '11', 7, 0, 0, 0, 0, 1.15);
    assertInSituCondition(
        $emptyLine->getQtyAfter() === 0
            && $emptyLine->getCommercialSpoilageLossEur() === 0.0
            && !$emptyLine->hasWithdrawals()
            && !$emptyLine->keepsHealthyRemainder()
            && $emptyLine->getLoadedBatchCode() === null,
        'A line without movements derives zero existence and zero loss'
    );

    $fefoLine = new MachineRestockVisitItem(
        id: null,
        visitId: 91,
        slotId: 4,
        slotCode: '11',
        productId: 7,
        qtyBefore: 6,
        qtyWithdrawnExpired: 6,
        qtyWithdrawnDamaged: 0,
        qtyAdded: 4,
        unitCostSnapshotEur: 1.15,
        loadedBatchCode: ' lote-sw-01 ',
        loadedExpirationDate: $today->modify('+3 days')
    );
    assertInSituCondition(
        $fefoLine->getQtyAfter() === 4
            && $fefoLine->getCommercialSpoilageLossEur() === 6.9
            && $fefoLine->getLoadedBatchCode() === 'LOTE-SW-01'
            && $fefoLine->hasWithdrawals()
            && !$fefoLine->keepsHealthyRemainder(),
        'The FEFO line derives qty_after 4 and a 6,90 € loss from six withdrawn units (QA 3)'
    );

    $damagedLine = new MachineRestockVisitItem(
        null,
        91,
        5,
        'a1',
        7,
        5,
        1,
        2,
        0,
        1.15,
        null,
        null,
        'lote-old',
        $today->modify('-1 day')
    );
    assertInSituCondition(
        $damagedLine->getSlotCode() === 'A1'
            && $damagedLine->getQtyAfter() === 2
            && $damagedLine->withdrawnUnitsTotal() === 3
            && $damagedLine->getCommercialSpoilageLossEur() === 3.45
            && $damagedLine->keepsHealthyRemainder()
            && $damagedLine->getWithdrawnBatchCode() === 'LOTE-OLD'
            && $damagedLine->getWithdrawnExpirationDate()?->format('Y-m-d') === '2026-11-13',
        'Expired and damaged units add up and the slot keeps its healthy remainder (D1)'
    );

    assertInSituThrows(
        static fn() => new MachineRestockVisitItem(null, 91, 4, '11', 7, 2, 3, 0, 0, 1.15),
        'Withdrawing more units than the previous existence is rejected (C1)'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisitItem(null, 91, 4, '11', 7, -1, 0, 0, 0, 1.15),
        'A negative previous existence is rejected'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisitItem(null, 91, 4, '11', 7, 0, 0, 0, 10001, 1.15, 'LOTE-1'),
        'Loading more than the contract bound of 10000 units is rejected'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisitItem(null, 91, 4, '11', 7, 0, 0, 0, 2, 1.15),
        'Loading units without the origin batch is rejected'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisitItem(null, 91, 4, '11', 7, 0, 0, 0, 2, 1.15, 'LOTE 01'),
        'A batch code outside the closed format is rejected'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisitItem(null, 91, 4, 'A-1', 7, 0, 0, 0, 0, 1.15),
        'A slot code outside the planogram pattern is rejected'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisitItem(null, 0, 4, '11', 7, 0, 0, 0, 0, 1.15),
        'A line requires an existing visit'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisitItem(null, 91, 0, '11', 7, 0, 0, 0, 0, 1.15),
        'A line requires an existing slot'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisitItem(null, 91, 4, '11', 0, 0, 0, 0, 0, 1.15),
        'A line requires an existing product'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisitItem(null, 91, 4, '11', 7, 0, 0, 0, 0, -0.01),
        'A negative unit cost is rejected'
    );
    assertInSituThrows(
        static fn() => new MachineRestockVisitItem(null, 91, 4, '11', 7, 0, 0, 0, 0, 10000.0),
        'A unit cost above 9 999,99 € is rejected'
    );

    $roundedCostLine = new MachineRestockVisitItem(null, 91, 4, '11', 7, 4, 4, 0, 0, 1.155);
    assertInSituCondition(
        $roundedCostLine->getUnitCostSnapshotEur() === 1.16
            && $roundedCostLine->getCommercialSpoilageLossEur() === 4.64,
        'The frozen cost rounds to two decimals before the loss is derived (QA 3)'
    );

    assertInSituCondition(
        !array_key_exists('unit_cost_snapshot_eur', $fefoLine->toArray())
            && !array_key_exists('commercial_spoilage_loss_eur', $fefoLine->toArray()),
        'The default projection hides the financial snapshot (Art. V.4)'
    );
    assertInSituCondition(
        ($fefoLine->toArray(true)['unit_cost_snapshot_eur'] ?? null) === 1.15
            && ($fefoLine->toArray(true)['commercial_spoilage_loss_eur'] ?? null) === 6.9,
        'The coordinator projection exposes both frozen amounts'
    );
    assertInSituCondition(
        !str_contains((string)json_encode($fefoLine, JSON_THROW_ON_ERROR), 'commercial_spoilage_loss_eur'),
        'Serializing the line never leaks the commercial loss (QA 3)'
    );
    assertInSituCondition(
        $fefoLine->toArray()['qty_after'] === 4
            && $fefoLine->toArray()['loaded_expiration_date'] === '2026-11-17',
        'The line projects the derived existence and the loaded expiration date'
    );
    assertInSituCondition(
        $fefoLine->withId(301)->getId() === 301 && $fefoLine->getId() === null,
        'withId assigns the persisted identifier without mutating the original line'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 5. MachinePlanogramSlot con lote vigente (D1, EARS 01.1.2, QA 1)
    // ─────────────────────────────────────────────────────────────────────────
    $plainSlot = new MachinePlanogramSlot(null, 2, '11', 7, 16, 6, 5, true);
    assertInSituCondition(
        $plainSlot->getCurrentBatchCode() === null
            && $plainSlot->getCurrentExpirationDate() === null
            && $plainSlot->slotExpiryStatus($today) === BatchExpiryStatus::OK,
        'A slot without batch data declares no expiry alert'
    );

    $expiredSlot = new MachinePlanogramSlot(null, 2, '11', 7, 16, 6, 5, true, null, null, ' lote-9 ', $today->modify('-1 day'));
    assertInSituCondition(
        $expiredSlot->getCurrentBatchCode() === 'LOTE-9'
            && $expiredSlot->getCurrentExpirationDate()?->format('Y-m-d') === '2026-11-13'
            && $expiredSlot->slotExpiryStatus($today) === BatchExpiryStatus::EXPIRED,
        'A slot with units and an expiry in the past is EXPIRED'
    );
    assertInSituCondition(
        (new MachinePlanogramSlot(null, 2, '11', 7, 16, 6, 5, true, null, null, 'LOTE-9', $today))->slotExpiryStatus($today) === BatchExpiryStatus::EXPIRED,
        'A batch that expires today is already EXPIRED in the slot'
    );
    assertInSituCondition(
        (new MachinePlanogramSlot(null, 2, '11', 7, 16, 6, 5, true, null, null, 'LOTE-9', $today->modify('+1 day')))->slotExpiryStatus($today) === BatchExpiryStatus::EXPIRING_SOON
            && (new MachinePlanogramSlot(null, 2, '11', 7, 16, 6, 5, true, null, null, 'LOTE-9', $today->modify('+2 days')))->slotExpiryStatus($today) === BatchExpiryStatus::EXPIRING_SOON,
        'The 48 hour window marks EXPIRING_SOON including its boundary'
    );
    assertInSituCondition(
        (new MachinePlanogramSlot(null, 2, '11', 7, 16, 6, 5, true, null, null, 'LOTE-9', $today->modify('+3 days')))->slotExpiryStatus($today) === BatchExpiryStatus::OK,
        'Beyond the window the slot is OK'
    );
    assertInSituCondition(
        (new MachinePlanogramSlot(null, 2, '11', 7, 16, 0, 5, true, null, null, 'LOTE-9', $today->modify('-1 day')))->slotExpiryStatus($today) === BatchExpiryStatus::OK,
        'An empty slot never alerts even with a stale batch date'
    );
    assertInSituThrows(
        static fn() => new MachinePlanogramSlot(null, 2, '11', 7, 16, 6, 5, true, null, null, 'LOTE 9', $today),
        'A slot batch outside the closed format is rejected',
        DomainException::class
    );
    assertInSituCondition(
        $expiredSlot->toArray()['current_batch_code'] === 'LOTE-9'
            && $expiredSlot->toArray()['slot_expiry_status'] === 'EXPIRED'
            && $expiredSlot->toArray()['slot_expiry_status_icon'] === '🔴'
            && $expiredSlot->toArray()['slot_expiry_status_label'] === 'Caducado',
        'The slot projects its batch, expiry status and sanitary traffic light'
    );
    assertInSituCondition(
        $expiredSlot->withStatus(false)->getCurrentBatchCode() === 'LOTE-9'
            && $expiredSlot->withId(77)->getCurrentExpirationDate()?->format('Y-m-d') === '2026-11-13',
        'withStatus and withId preserve the batch data of the slot'
    );
    assertInSituCondition(
        $plainSlot->stockStatus()->value === 'OK'
            && $plainSlot->restockNeededQty() === 10
            && abs($plainSlot->fillRatePercent() - 37.5) < 0.01,
        'The stock semaphores of the slot keep their previous behaviour'
    );

    echo "In-situ restock visit domain model tests passed. Total Assertions: {$assertions}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "In-situ restock visit domain model test failed: {$exception->getMessage()}\n");
    exit(1);
}
