<?php

declare(strict_types=1);

/**
 * MachineRestockVisitValidatorTest · T-RVF-04
 *
 * Certifica el **Triple Cerrojo Sanitario** del Módulo 34 antes de que exista el
 * servicio que lo orquesta: las tres barreras (estado de la máquina, lectura de
 * cabina y FEFO en carril y furgoneta), el checklist adaptativo, la evidencia
 * fotográfica con magic bytes reales y el rigor textual de la nota interna.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Cerrojo 1 (RF-02.1, Art. II).** Cuarentena sanitaria, fuera de servicio
 *      o avería térmica (`TEMPERATURE_COLD`, etiqueta funcional `THERMAL_BREAKDOWN`)
 *      abierta bloquean con `409
 *      SANITARY_RESTOCK_BLOCKED`; una máquina sana pasa.
 *
 *   2. **Cerrojo 2 (RF-02.2, QA 4).** Toda máquina refrigerada exige lectura; el
 *      rango físico es `[-5,0, 35,0] °C`; el techo sanitario —4,0 °C en perecedero
 *      y mixta, 8,0 °C en bebidas frías— muerde en una visita completada o con
 *      unidades cargadas, y no muerde en un aborto sin carga, que es justo el
 *      protocolo que se tramita por encima del techo.
 *
 *   3. **Cerrojo 3 en carril (RF-02.3, QA 1, C1).** Un lote caducado exige retirar
 *      todo el remanente aunque no se cargue nada; un lote que caduca en ≤ 48 h
 *      exige retirar todo antes de añadir; el movimiento nunca supera la capacidad
 *      ni las retiradas superan la existencia previa.
 *
 *   4. **Cerrojo 3 en furgoneta (QA 1).** El lote debe existir y tener saldo, no
 *      puede estar caducado y no puede adelantar a otro lote sano más próximo a
 *      vencer; el consumo de la propia visita agota el lote anterior y deja de
 *      bloquear.
 *
 *   5. **Checklist (D3, EARS 03.1).** Una completada exige los puntos de su
 *      tipología; un aborto no exige limpieza.
 *
 *   6. **Evidencia (EARS 03.2, Art. V.1 y V.5).** Con aborto o retiradas son
 *      obligatorias foto y nota de 20 caracteres reales; la foto se acota a 5 MB y
 *      a JPG/PNG/WebP, y la firma binaria real manda sobre la extensión y el MIME
 *      declarados.
 *
 * CATEGORÍA: hermética (`tests/unit/`). No toca base de datos, disco ni reloj:
 * todas las fechas se pasan como argumento explícito y los bytes de las imágenes
 * son literales del propio test.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Enum\RestockVisitStatus;
use VendGuard\Core\Domain\Exception\MachineRestockVisitValidationException;
use VendGuard\Core\Domain\Model\DailyHygieneChecklist;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Service\MachineRestockVisitValidator;

$assertions = 0;

function assertVisitCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertVisitAccepts(callable $action, string $message): void
{
    try {
        $action();
        assertVisitCondition(true, $message);
    } catch (Throwable $exception) {
        assertVisitCondition(false, $message . ' — se lanzó ' . $exception::class . ': ' . $exception->getMessage());
    }
}

function assertVisitRejects(
    callable $action,
    string $expectedCode,
    int $expectedHttpStatus,
    string $message,
    ?string $expectedField = null
): ?MachineRestockVisitValidationException {
    try {
        $action();
    } catch (MachineRestockVisitValidationException $exception) {
        $matches = $exception->getErrorCode() === $expectedCode
            && $exception->getHttpStatusCode() === $expectedHttpStatus
            && ($expectedField === null || $exception->getField() === $expectedField);

        assertVisitCondition(
            $matches,
            $message . ' — se lanzó ' . $exception->getErrorCode() . ' (' . $exception->getHttpStatusCode() . ')'
        );

        return $exception;
    } catch (Throwable $exception) {
        assertVisitCondition(false, $message . ' — excepción inesperada ' . $exception::class . ': ' . $exception->getMessage());
        return null;
    }

    assertVisitCondition(false, $message . ' — no se lanzó ninguna excepción');
    return null;
}

try {
    $today = new DateTimeImmutable('2026-11-14');

    $comboChecklist = DailyHygieneChecklist::fromArray([
        'coffee_grounds_emptied' => true,
        'liquid_waste_bucket_cleaned' => true,
        'mixers_and_ducts_rinsed' => true,
        'dispensing_tray_and_glass_cleaned' => true,
    ], MachineType::COMBO);

    $coldChecklist = DailyHygieneChecklist::fromArray([
        'dispensing_tray_and_glass_cleaned' => true,
        'cabin_spills_checked' => true,
    ], MachineType::PERISHABLE_FOOD);

    $validNotes = 'Apertura con 7,5 °C en cabina: máquina precintada y SAT avisado.';

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Cerrojo 1 · estado de la máquina (RF-02.1, Art. II)
    // ─────────────────────────────────────────────────────────────────────────
    assertVisitAccepts(
        static fn () => MachineRestockVisitValidator::assertMachineAvailable('OK', false, false, 42),
        'A healthy machine outside quarantine admits the restock visit'
    );
    $quarantined = assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertMachineAvailable('QUARANTINE', false, false, 42),
        'SANITARY_RESTOCK_BLOCKED',
        409,
        'A machine in sanitary quarantine is blocked (Art. II)',
        'machine_id'
    );
    assertVisitCondition(
        ($quarantined?->getContext()['machine_id'] ?? null) === 42
            && ($quarantined?->getContext()['reasons'] ?? []) !== [],
        'The block exception carries the machine and its reasons'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertMachineAvailable('SANITARY_QUARANTINE', false, false, 42),
        'SANITARY_RESTOCK_BLOCKED',
        409,
        'The functional SANITARY_QUARANTINE status is also blocked'
    );
    $outOfService = assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertMachineAvailable('OK', true, false, 7),
        'SANITARY_RESTOCK_BLOCKED',
        409,
        'A machine out of service is blocked'
    );
    assertVisitCondition(
        $outOfService !== null
            && in_array('está fuera de servicio', $outOfService->getContext()['reasons'] ?? [], true),
        'The block context names the out-of-service reason'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertMachineAvailable('OK', false, true, 7),
        'SANITARY_RESTOCK_BLOCKED',
        409,
        'An open TEMPERATURE_COLD incident (functional label THERMAL_BREAKDOWN) blocks the visit (D2)'
    );
    $allBlocked = assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertMachineAvailable('QUARANTINE', true, true, 7),
        'SANITARY_RESTOCK_BLOCKED',
        409,
        'Three simultaneous block reasons are reported together'
    );
    assertVisitCondition(
        $allBlocked !== null && count($allBlocked->getContext()['reasons'] ?? []) === 3,
        'The blocked machine reports every concurrent reason'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Cerrojo 2 · lectura de cabina y techo sanitario (QA 4, RF-02.2)
    // ─────────────────────────────────────────────────────────────────────────
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertCabinTemperature(MachineType::COMBO, null, RestockVisitStatus::COMPLETED),
        'INVALID_CABIN_TEMPERATURE',
        422,
        'A refrigerated machine without a cabin reading is rejected',
        'cabin_temp_celsius'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertCabinTemperature(MachineType::COLD_DRINKS, null, RestockVisitStatus::ABORTED_THERMAL_BREACH),
        'INVALID_CABIN_TEMPERATURE',
        422,
        'Even a thermal abort needs the reading that justifies it'
    );
    assertVisitAccepts(
        static fn () => MachineRestockVisitValidator::assertCabinTemperature(MachineType::SNACKS, null, RestockVisitStatus::COMPLETED),
        'A non-refrigerated machine accepts a visit without temperature'
    );
    assertVisitCondition(
        MachineRestockVisitValidator::assertCabinTemperature(MachineType::COMBO, 3.456, RestockVisitStatus::COMPLETED) === 3.5,
        'The cabin reading is rounded to the engine tenth'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertCabinTemperature(MachineType::COMBO, 36.0, RestockVisitStatus::COMPLETED),
        'INVALID_CABIN_TEMPERATURE',
        422,
        'A reading above 35,0 °C is outside the plausible physical range'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertCabinTemperature(MachineType::COMBO, -5.1, RestockVisitStatus::COMPLETED),
        'INVALID_CABIN_TEMPERATURE',
        422,
        'A reading below -5,0 °C is outside the plausible physical range'
    );
    assertVisitCondition(
        MachineRestockVisitValidator::assertCabinTemperature(MachineType::COMBO, 4.0, RestockVisitStatus::COMPLETED) === 4.0,
        'The 4,0 °C ceiling boundary is admitted in a combo machine'
    );
    $overCeiling = assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertCabinTemperature(MachineType::COMBO, 4.1, RestockVisitStatus::COMPLETED),
        'CABIN_TEMP_EXCEEDS_SANITARY_LIMIT',
        422,
        'A completed visit at 4,1 °C in a combo machine is rejected (Art. II)',
        'cabin_temp_celsius'
    );
    assertVisitCondition(
        ($overCeiling?->getContext()['ceiling'] ?? null) === 4.0,
        'The excess context freezes the 4,0 °C ceiling of the combo machine'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertCabinTemperature(MachineType::PERISHABLE_FOOD, 4.5, RestockVisitStatus::COMPLETED),
        'CABIN_TEMP_EXCEEDS_SANITARY_LIMIT',
        422,
        'A perishable machine applies the same 4,0 °C ceiling'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertCabinTemperature(MachineType::COMBO, 4.1, RestockVisitStatus::ABORTED_THERMAL_BREACH, 2),
        'CABIN_TEMP_EXCEEDS_SANITARY_LIMIT',
        422,
        'Loading above the ceiling is rejected even under an abort payload (RF-02.2)'
    );
    assertVisitCondition(
        MachineRestockVisitValidator::assertCabinTemperature(MachineType::COLD_DRINKS, 7.5, RestockVisitStatus::COMPLETED) === 7.5,
        'A cold drinks machine admits 7,5 °C when completed'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertCabinTemperature(MachineType::COLD_DRINKS, 8.1, RestockVisitStatus::COMPLETED),
        'CABIN_TEMP_EXCEEDS_SANITARY_LIMIT',
        422,
        'A cold drinks machine rejects 8,1 °C when completed'
    );
    assertVisitCondition(
        MachineRestockVisitValidator::assertCabinTemperature(MachineType::COMBO, 7.5, RestockVisitStatus::ABORTED_THERMAL_BREACH) === 7.5,
        'A thermal abort may be recorded above the ceiling: that is its protocol'
    );
    assertVisitCondition(
        MachineRestockVisitValidator::assertCabinTemperature(MachineType::SNACKS, 30.0, RestockVisitStatus::COMPLETED) === 30.0,
        'A non-refrigerated machine has no sanitary ceiling but still respects the physical range'
    );
    assertVisitCondition(
        MachineRestockVisitValidator::thermalCeilingFor(MachineType::PERISHABLE_FOOD) === 4.0
            && MachineRestockVisitValidator::thermalCeilingFor(MachineType::COMBO) === 4.0
            && MachineRestockVisitValidator::thermalCeilingFor(MachineType::COLD_DRINKS) === 8.0
            && MachineRestockVisitValidator::thermalCeilingFor(MachineType::HOT_DRINKS) === null
            && MachineRestockVisitValidator::thermalCeilingFor(MachineType::SNACKS) === null,
        'thermalCeilingFor matches the QA 4 matrix'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Precinto preventivo (RF-02.2, Art. II)
    // ─────────────────────────────────────────────────────────────────────────
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertPrecautionarySeal(RestockVisitStatus::ABORTED_THERMAL_BREACH, false),
        'PRECAUTIONARY_SEAL_REQUIRED',
        422,
        'A thermal abort without the precautionary seal is rejected',
        'precautionary_seal_applied'
    );
    assertVisitAccepts(
        static fn () => MachineRestockVisitValidator::assertPrecautionarySeal(RestockVisitStatus::ABORTED_THERMAL_BREACH, true),
        'A thermal abort with the seal passes the protocol'
    );
    assertVisitAccepts(
        static fn () => MachineRestockVisitValidator::assertPrecautionarySeal(RestockVisitStatus::COMPLETED, false),
        'A completed visit does not need a seal'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Checklist adaptativo (D3, EARS 03.1)
    // ─────────────────────────────────────────────────────────────────────────
    $emptyCombo = DailyHygieneChecklist::emptyFor(MachineType::COMBO);
    $missingChecklist = assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertHygieneChecklist(RestockVisitStatus::COMPLETED, MachineType::COMBO, $emptyCombo),
        'INCOMPLETE_DAILY_HYGIENE_CHECKLIST',
        422,
        'A completed visit with an empty checklist is rejected',
        'hygiene_checklist_json'
    );
    assertVisitCondition(
        count($missingChecklist?->getContext()['missing_items'] ?? []) === 4,
        'The checklist exception names the four missing combo points'
    );
    assertVisitAccepts(
        static fn () => MachineRestockVisitValidator::assertHygieneChecklist(RestockVisitStatus::COMPLETED, MachineType::COMBO, $comboChecklist),
        'The four combo points complete the checklist'
    );
    assertVisitAccepts(
        static fn () => MachineRestockVisitValidator::assertHygieneChecklist(RestockVisitStatus::COMPLETED, MachineType::PERISHABLE_FOOD, $coldChecklist),
        'The two cabin points complete a perishable checklist'
    );
    assertVisitAccepts(
        static fn () => MachineRestockVisitValidator::assertHygieneChecklist(RestockVisitStatus::ABORTED_THERMAL_BREACH, MachineType::COMBO, $emptyCombo),
        'A thermal abort does not demand cleaning points (D3)'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Evidencia fotográfica y nota interna (EARS 03.2, Art. V.1 y V.5)
    // ─────────────────────────────────────────────────────────────────────────
    $cleanEvidence = MachineRestockVisitValidator::assertEvidence(
        RestockVisitStatus::COMPLETED,
        0,
        null,
        null
    );
    assertVisitCondition(
        $cleanEvidence === ['photo_evidence_url' => null, 'internal_notes' => null],
        'A clean completed visit needs neither photo nor notes'
    );

    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertEvidence(RestockVisitStatus::COMPLETED, 2, null, $validNotes),
        'PHOTO_EVIDENCE_REQUIRED',
        422,
        'A visit with withdrawn units requires photo evidence',
        'photo_evidence_url'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertEvidence(RestockVisitStatus::ABORTED_THERMAL_BREACH, 0, null, $validNotes),
        'PHOTO_EVIDENCE_REQUIRED',
        422,
        'A thermal abort requires photo evidence'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertEvidence(RestockVisitStatus::ABORTED_THERMAL_BREACH, 0, '/uploads/visits/evidencia.gif', $validNotes),
        'PHOTO_EVIDENCE_REQUIRED',
        422,
        'An extension outside JPG/PNG/WebP is rejected (Art. V.5)'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertEvidence(
            RestockVisitStatus::ABORTED_THERMAL_BREACH,
            0,
            '/uploads/visits/evidencia.jpg',
            $validNotes,
            6 * 1024 * 1024
        ),
        'PHOTO_EVIDENCE_REQUIRED',
        422,
        'A photograph above 5 MB is rejected (Art. V.5)'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertEvidence(
            RestockVisitStatus::ABORTED_THERMAL_BREACH,
            0,
            '/uploads/visits/evidencia.jpg',
            $validNotes,
            1024,
            'image/gif'
        ),
        'PHOTO_EVIDENCE_REQUIRED',
        422,
        'A real GIF type is rejected even with a .jpg extension (Art. V.5)'
    );

    $pngBytes = "\x89PNG\r\n\x1a\n" . str_repeat('0', 120);
    $jpegBytes = "\xFF\xD8\xFF\xE0" . str_repeat('0', 120);
    $webpBytes = 'RIFF' . pack('V', 200) . 'WEBP' . str_repeat('0', 100);
    $gifBytes = 'GIF89a' . str_repeat('0', 120);

    assertVisitCondition(
        MachineRestockVisitValidator::detectImageMime($pngBytes) === 'image/png'
            && MachineRestockVisitValidator::detectImageMime($jpegBytes) === 'image/jpeg'
            && MachineRestockVisitValidator::detectImageMime($webpBytes) === 'image/webp'
            && MachineRestockVisitValidator::detectImageMime($gifBytes) === null,
        'detectImageMime reads real binary signatures and rejects a GIF (Art. V.5)'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertEvidence(
            RestockVisitStatus::ABORTED_THERMAL_BREACH,
            0,
            '/uploads/visits/evidencia.jpg',
            $validNotes,
            null,
            'image/jpeg',
            $pngBytes
        ),
        'PHOTO_EVIDENCE_REQUIRED',
        422,
        'The declared MIME cannot contradict the real signature'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertEvidence(
            RestockVisitStatus::ABORTED_THERMAL_BREACH,
            0,
            '/uploads/visits/evidencia.jpg',
            $validNotes,
            null,
            null,
            $gifBytes
        ),
        'PHOTO_EVIDENCE_REQUIRED',
        422,
        'A renamed GIF is rejected by its magic bytes'
    );

    $validEvidence = MachineRestockVisitValidator::assertEvidence(
        RestockVisitStatus::ABORTED_THERMAL_BREACH,
        0,
        '/uploads/visits/evidencia.jpg?v=2',
        '  Apertura   con 7,5 °C en cabina:  precintada.  ',
        null,
        'image/jpeg',
        $jpegBytes
    );
    assertVisitCondition(
        $validEvidence['photo_evidence_url'] === '/uploads/visits/evidencia.jpg?v=2'
            && $validEvidence['internal_notes'] === 'Apertura con 7,5 °C en cabina: precintada.',
        'A real JPEG with a query string is accepted and the note is normalized'
    );
    assertVisitCondition(
        MachineRestockVisitValidator::assertEvidence(
            RestockVisitStatus::COMPLETED,
            1,
            '/uploads/visits/evidencia.png',
            $validNotes,
            5 * 1024 * 1024,
            'image/png'
        )['photo_evidence_url'] === '/uploads/visits/evidencia.png',
        'The exact 5 MB boundary is accepted for a PNG photograph'
    );

    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertEvidence(RestockVisitStatus::COMPLETED, 3, '/uploads/visits/evidencia.jpg', 'caducó el lote'),
        'INVALID_JUSTIFICATION_LENGTH',
        422,
        'A withdrawal with a note under 20 real characters is rejected (Art. V.1)',
        'internal_notes'
    );
    assertVisitCondition(
        MachineRestockVisitValidator::assertEvidence(
            RestockVisitStatus::COMPLETED,
            2,
            '/uploads/visits/evidencia.jpg',
            str_repeat('á', 20)
        )['internal_notes'] === str_repeat('á', 20),
        'Twenty real multibyte characters justify a withdrawal (Art. V.1)'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertEvidence(
            RestockVisitStatus::COMPLETED,
            0,
            null,
            str_repeat('a', 501)
        ),
        'INVALID_JUSTIFICATION_LENGTH',
        422,
        'An optional note above the 500 character column is rejected'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Cerrojo 3 · movimiento del carril y FEFO (D1, QA 1, C1)
    // ─────────────────────────────────────────────────────────────────────────
    $expiredSlot = new MachinePlanogramSlot(null, 2, '11', 7, 16, 6, 5, true, null, null, 'LOTE-9', $today->modify('-1 day'));
    $expiringSlot = new MachinePlanogramSlot(null, 2, '12', 7, 16, 6, 5, true, null, null, 'LOTE-8', $today->modify('+2 days'));
    $freshSlot = new MachinePlanogramSlot(null, 2, '13', 7, 16, 6, 5, true, null, null, 'LOTE-7', $today->modify('+3 days'));
    $emptyStaleSlot = new MachinePlanogramSlot(null, 2, '14', 7, 16, 0, 5, true, null, null, 'LOTE-6', $today->modify('-1 day'));
    $narrowSlot = new MachinePlanogramSlot(null, 2, '15', 7, 10, 9, 5, true);

    assertVisitCondition(
        MachineRestockVisitValidator::assertSlotMovement($expiredSlot, 6, 0, 4, $today) === 4,
        'A fully withdrawn expired slot accepts the load and yields qty_after 4 (D1)'
    );
    $fefoExpired = assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertSlotMovement($expiredSlot, 2, 0, 0, $today),
        'FEFO_WITHDRAWAL_REQUIRED',
        422,
        'An expired slot demands the withdrawal of all its units even without loading (QA 1)',
        'slot_code'
    );
    assertVisitCondition(
        ($fefoExpired?->getContext()['qty_before'] ?? null) === 6,
        'The FEFO exception freezes the previous existence of the slot'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertSlotMovement($expiringSlot, 0, 0, 3, $today),
        'FEFO_WITHDRAWAL_REQUIRED',
        422,
        'A slot expiring within 48 h cannot be loaded without withdrawing its remainder (QA 1)'
    );
    assertVisitCondition(
        MachineRestockVisitValidator::assertSlotMovement($expiringSlot, 6, 0, 3, $today) === 3,
        'Withdrawing the remainder first allows the load in an expiring slot'
    );
    assertVisitCondition(
        MachineRestockVisitValidator::assertSlotMovement($freshSlot, 0, 0, 3, $today) === 9,
        'A slot beyond the 48 h window accepts a load without withdrawal'
    );
    assertVisitCondition(
        MachineRestockVisitValidator::assertSlotMovement($emptyStaleSlot, 0, 0, 3, $today) === 3,
        'An empty slot with a stale batch date can be loaded (EARS 01.1.2)'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertSlotMovement($expiredSlot, 7, 0, 0, $today),
        'SLOT_CAPACITY_OR_WITHDRAWAL_INVALID',
        422,
        'Withdrawing more units than the previous existence is rejected (C1)'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertSlotMovement($narrowSlot, 0, 0, 2, $today),
        'SLOT_CAPACITY_OR_WITHDRAWAL_INVALID',
        422,
        'The resulting existence cannot exceed the slot capacity (C1)'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertSlotMovement($expiredSlot, -1, 0, 0, $today),
        'SLOT_CAPACITY_OR_WITHDRAWAL_INVALID',
        422,
        'A negative withdrawal is rejected'
    );

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Cerrojo 3 · selección de lote en la furgoneta (QA 1)
    // ─────────────────────────────────────────────────────────────────────────
    $lotA = new RestockerVanProductStock(null, 12, 7, 'LOTE-A', $today->modify('+3 days'), 10, true);
    $lotB = new RestockerVanProductStock(null, 12, 7, 'LOTE-B', $today->modify('+1 day'), 5, true);
    $expiredLot = new RestockerVanProductStock(null, 12, 7, 'LOTE-OLD', $today->modify('-1 day'), 4, true);
    $otherProductLot = new RestockerVanProductStock(null, 12, 8, 'LOTE-C', $today->modify('+1 day'), 5, true);

    assertVisitCondition(
        MachineRestockVisitValidator::assertVanBatchSelection([$lotA], ' lote-a ', 4, [], $today)->getBatchCode() === 'LOTE-A',
        'The selected van lot is normalized and returned with enough stock'
    );
    $missingLot = assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertVanBatchSelection([$lotA], 'LOTE-X', 4, [], $today),
        'INSUFFICIENT_VAN_BATCH_STOCK',
        422,
        'A lot that does not exist in the van is rejected',
        'loaded_batch_code'
    );
    assertVisitCondition(
        ($missingLot?->getContext()['available'] ?? null) === 0,
        'A missing lot reports zero available units'
    );
    $shortStock = assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertVanBatchSelection([$lotA], 'LOTE-A', 11, [], $today),
        'INSUFFICIENT_VAN_BATCH_STOCK',
        422,
        'Loading more units than the lot holds is rejected'
    );
    assertVisitCondition(
        ($shortStock?->getContext()['available'] ?? null) === 10
            && ($shortStock?->getContext()['required'] ?? null) === 11,
        'The insufficient stock exception carries the real balance'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertVanBatchSelection([$expiredLot], 'LOTE-OLD', 1, [], $today),
        'EXPIRED_VAN_BATCH_FORBIDDEN',
        422,
        'An expired van lot cannot be loaded (QA 1, Art. II)',
        'loaded_batch_code'
    );
    assertVisitAccepts(
        static fn () => MachineRestockVisitValidator::assertVanBatchSelection([$expiredLot], 'LOTE-OLD', 0, [], $today),
        'Declaring an expired lot with zero load does not trip the guard'
    );
    $olderLot = assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertVanBatchSelection([$lotA, $lotB], 'LOTE-A', 2, [], $today),
        'FEFO_OLDER_VAN_BATCH_AVAILABLE',
        422,
        'A healthy older lot with balance must be consumed first (QA 1)'
    );
    assertVisitCondition(
        ($olderLot?->getContext()['older_batch_code'] ?? null) === 'LOTE-B',
        'The FEFO exception names the older lot that must be exhausted'
    );
    assertVisitAccepts(
        static fn () => MachineRestockVisitValidator::assertVanBatchSelection([$lotA, $lotB], 'LOTE-A', 2, ['LOTE-B' => 5], $today),
        'The same visit exhausting the older lot unlocks the newer one'
    );
    assertVisitRejects(
        static fn () => MachineRestockVisitValidator::assertVanBatchSelection([$lotA, $lotB], 'LOTE-A', 2, ['LOTE-B' => 4], $today),
        'FEFO_OLDER_VAN_BATCH_AVAILABLE',
        422,
        'One remaining unit of the older lot still blocks the newer one'
    );
    assertVisitAccepts(
        static fn () => MachineRestockVisitValidator::assertVanBatchSelection([$lotA, $expiredLot], 'LOTE-A', 2, [], $today),
        'An expired older lot is not healthy stock and does not block the load'
    );
    assertVisitAccepts(
        static fn () => MachineRestockVisitValidator::assertVanBatchSelection([$lotA, $otherProductLot], 'LOTE-A', 2, [], $today),
        'Lots of another product do not participate in the FEFO comparison'
    );
    assertVisitThrowsInvalidArgument(
        static fn () => MachineRestockVisitValidator::assertVanBatchSelection([$lotA], 'LOTE-A', -1, [], $today),
        'A negative load is rejected as a programming error'
    );

    echo "Machine restock visit validator tests passed. Total Assertions: {$assertions}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Machine restock visit validator test failed: {$exception->getMessage()}\n");
    exit(1);
}

/**
 * @param callable(): mixed $action
 */
function assertVisitThrowsInvalidArgument(callable $action, string $message): void
{
    try {
        $action();
    } catch (InvalidArgumentException) {
        assertVisitCondition(true, $message);
        return;
    }

    assertVisitCondition(false, $message . ' — no se lanzó InvalidArgumentException');
}
