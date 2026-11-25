<?php

declare(strict_types=1);

/**
 * DexAuditDomainModelsTest · T-DEX-03
 *
 * Certifica la capa de dominio del Módulo 39 sin motor ni base de datos: los cinco
 * enumerados #51–#55, las invariantes de las tres entidades inmutables y, sobre todo, la
 * segregación de proyecciones del Art. V.4 (`QA-1`, `QA-3`).
 *
 * QUÉ SE MIDE, Y POR QUÉ ASÍ
 *
 *   1. **El catálogo del motor, espejo exacto.** Los `values()` de cada enumerado reproducen el
 *      orden y las etiquetas de `035_eva_dts_dex_audit.sql`; una divergencia aquí se convierte
 *      en una fila que el motor rechaza o en un filtro que no encuentra nada.
 *
 *   2. **Invariantes que muerden antes de la base.** Código `DEX-AAAA-NNNN`, SHA-256 en
 *      minúsculas, tamaño 1..1 MB, coherencia de las cuatro ramas de `delta_source`, anulación
 *      con motivo real y enlace único. Son la misma regla que los `CHECK` y disparadores, en la
 *      capa que puede dar un mensaje útil (`RNF-02`).
 *
 *   3. **Arqueo Ciego (QA-1).** La vista del Reponedor en un `GET` histórico no contiene un solo
 *      euro; `prefill_sales_counter_reading_eur` aparece únicamente en la respuesta inmediata del
 *      `POST`. La vista del Técnico es la del historial en unidades. La financiera exige una
 *      llamada explícita a `toCoordinatorArray()`, y ni siquiera ella expone `raw_payload`.
 *
 *   4. **Coherencia térmica (QA-4, Art. II.2).** Solo un evento `THERMAL_REFRIGERATION` puede
 *      marcar `is_thermal_alert`, y el payload de escalado en un toque se construye de forma
 *      determinista a partir de esos eventos.
 *
 * Uso:
 *   php tests/unit/DexAuditDomainModelsTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Enum\DexAuditStatus;
use VendGuard\Core\Domain\Enum\DexEventCategory;
use VendGuard\Core\Domain\Enum\DexEventSeverity;
use VendGuard\Core\Domain\Enum\DexImportSource;
use VendGuard\Core\Domain\Enum\DexSelectionMatchStatus;
use VendGuard\Core\Domain\Model\MachineDexAudit;
use VendGuard\Core\Domain\Model\MachineDexEventLine;
use VendGuard\Core\Domain\Model\MachineDexSelectionLine;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$expectFailure = static function (callable $operation, string $needle, string $what) use ($assert): void {
    try {
        $operation();
        $assert($what, false, 'no se lanzó ninguna excepción');
    } catch (InvalidArgumentException | DomainException $exception) {
        $matches = $needle === '' || str_contains($exception->getMessage(), $needle);
        $assert($what, $matches, $matches ? '' : 'mensaje inesperado: ' . $exception->getMessage());
    }
};

$payload = "DXS*TEST*V1\nVA1*1*2550*41\nDXE*TEST\n";

$baseAudit = static fn (array $overrides = []): MachineDexAudit => new MachineDexAudit(...($overrides + [
    'id' => 10,
    'auditCode' => 'dex-2026-7001',
    'machineId' => 3,
    'machineCodeSnapshot' => 'MAQ-003',
    'machineTypeSnapshot' => 'COMBO',
    'importedBy' => 7,
    'importerRoleSnapshot' => 'coordinator',
    'importerNameSnapshot' => 'Coordinación de Pruebas',
    'importSource' => DexImportSource::COORDINATOR_CONSOLE,
    'originalFilename' => 'volcado.dex',
    'fileSizeBytes' => 512,
    'sha256Hash' => str_repeat('a', 64),
    'rawPayload' => $payload,
    'va1TotalSalesEur' => 25.50,
    'va1TotalVendUnits' => 41,
    'deltaSource' => 'INITIAL_BASELINE',
    'capturedAt' => '2026-11-20T09:00:00+00:00',
]));

echo "======================================================================\n";
echo " VENDGUARD · T-DEX-03 · Dominio inmutable del volcado EVA-DTS (.dex)\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 1. Enumerados #51–#55 (espejo del motor) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$assert(
    '1.1 `DexAuditStatus` declara ACTIVE y VOIDED en el orden del enum #51',
    DexAuditStatus::values() === ['ACTIVE', 'VOIDED']
);
$assert(
    '1.2 El estado anulado se etiqueta y es terminal',
    DexAuditStatus::VOIDED->label() === 'Anulado'
        && DexAuditStatus::VOIDED->isVoided()
        && !DexAuditStatus::VOIDED->canTransitionTo(DexAuditStatus::ACTIVE)
        && DexAuditStatus::ACTIVE->canTransitionTo(DexAuditStatus::VOIDED)
);
$assert(
    '1.3 `DexImportSource` declara las tres puertas del contrato (#52)',
    DexImportSource::values() === ['RESTOCKER_ROUTE', 'TECHNICIAN_ROUTE', 'COORDINATOR_CONSOLE']
);
$assert(
    '1.4 Cada puerta corresponde al rol que puede firmarla',
    DexImportSource::RESTOCKER_ROUTE->matchesActorRole('restocker')
        && DexImportSource::TECHNICIAN_ROUTE->actorRole() === 'TECHNICIAN'
        && !DexImportSource::COORDINATOR_CONSOLE->isRestockerRoute()
);
$assert(
    '1.5 `DexSelectionMatchStatus` declara los cinco estados (#53)',
    DexSelectionMatchStatus::values() === [
        'EXACT_MATCH',
        'LEADING_ZERO_MATCH',
        'AMBIGUOUS',
        'UNMAPPED',
        'HOT_DRINKS_SELECTION',
    ]
);
$assert(
    '1.6 Solo los dos emparejamientos reales exigen carril (C4)',
    DexSelectionMatchStatus::EXACT_MATCH->requiresSlotMatch()
        && DexSelectionMatchStatus::LEADING_ZERO_MATCH->requiresSlotMatch()
        && !DexSelectionMatchStatus::AMBIGUOUS->requiresSlotMatch()
        && !DexSelectionMatchStatus::UNMAPPED->requiresSlotMatch()
        && DexSelectionMatchStatus::HOT_DRINKS_SELECTION->isInformational()
);
$assert(
    '1.7 `DexEventCategory` declara las cinco familias (#54)',
    DexEventCategory::values() === [
        'THERMAL_REFRIGERATION',
        'PAYMENT_MDB',
        'MECHANICAL_VEND',
        'POWER_CONTROLLER',
        'OTHER',
    ]
);
$assert(
    '1.8 Solo la refrigeración habilita el aviso térmico (Art. II.2)',
    DexEventCategory::THERMAL_REFRIGERATION->allowsThermalAlert()
        && !DexEventCategory::PAYMENT_MDB->allowsThermalAlert()
);
$assert(
    '1.9 `DexEventSeverity` declara CRITICAL, WARNING e INFO con su prelación (#55)',
    DexEventSeverity::values() === ['CRITICAL', 'WARNING', 'INFO']
        && DexEventSeverity::CRITICAL->weight() > DexEventSeverity::WARNING->weight()
        && DexEventSeverity::WARNING->weight() > DexEventSeverity::INFO->weight()
        && DexEventSeverity::CRITICAL->isCritical()
);
$expectFailure(
    static fn () => DexAuditStatus::fromString('PENDING'),
    'no válido',
    '1.10 Un estado fuera del catálogo se rechaza con mensaje útil'
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 2. MachineDexAudit: invariantes de la cabecera ---\n";
// ─────────────────────────────────────────────────────────────────────────

$audit = $baseAudit();
$assert(
    '2.1 El alta mínima normaliza el código a mayúsculas y expone sus snapshots',
    $audit->getAuditCode() === 'DEX-2026-7001'
        && $audit->getMachineCodeSnapshot() === 'MAQ-003'
        && $audit->getImporterRoleSnapshot() === 'COORDINATOR'
        && $audit->isActive()
        && !$audit->isVoided()
);
$assert(
    '2.2 La línea base no arrastra deltas ni predecesor (RF-04.1)',
    $audit->getDeltaSource() === 'INITIAL_BASELINE'
        && $audit->getPreviousAuditId() === null
        && $audit->getDeltaSalesEur() === null
        && $audit->getDeltaVendUnits() === null
        && !$audit->isCounterResetDetected()
);
$expectFailure(
    static fn () => $baseAudit(['auditCode' => 'DEX-26-1']),
    'DEX-AAAA-NNNN',
    '2.3 Un código fuera de formato se rechaza antes de la base'
);
$expectFailure(
    static fn () => $baseAudit(['sha256Hash' => 'abc']),
    'SHA-256',
    '2.4 Una huella que no es SHA-256 hexadecimal se rechaza'
);
$expectFailure(
    static fn () => $baseAudit(['fileSizeBytes' => 0]),
    'tamaño del .dex',
    '2.5 Un fichero vacío o mayor de 1 MB se rechaza (QA-5)'
);
$expectFailure(
    static fn () => $baseAudit(['deltaSource' => 'PREVIOUS_AUDIT']),
    'no es coherente',
    '2.6 `PREVIOUS_AUDIT` sin predecesor ni deltas viola la coherencia del motor'
);
$expectFailure(
    static fn () => $baseAudit(['previousAuditId' => 2]),
    'no es coherente',
    '2.7 `INITIAL_BASELINE` con predecesor viola la coherencia del motor'
);
$expectFailure(
    static fn () => $baseAudit([
        'deltaSource' => 'UNAVAILABLE',
        'previousAuditId' => 2,
        'counterResetDetected' => true,
        'deltaVendUnits' => 3,
    ]),
    'no es coherente',
    '2.8 `UNAVAILABLE` con delta persistido viola la coherencia del motor'
);
$expectFailure(
    static fn () => $baseAudit([
        'deltaSource' => 'PREVIOUS_AUDIT',
        'previousAuditId' => 2,
        'counterResetDetected' => true,
        'deltaSalesEur' => 1.0,
        'deltaVendUnits' => 1,
    ]),
    'no es coherente',
    '2.9 Un delta de `PREVIOUS_AUDIT` con reseteo detectado se rechaza'
);
$expectFailure(
    static fn () => $baseAudit(['status' => DexAuditStatus::VOIDED]),
    'exige motivo',
    '2.10 Un volcado VOIDED sin motivo, autor y fecha se rechaza'
);
$expectFailure(
    static fn () => $baseAudit(['voidReason' => 'Residuo de anulación en una fila activa']),
    'residuos de anulación',
    '2.11 Un volcado ACTIVE no puede conservar residuos de anulación'
);
$expectFailure(
    static fn () => $baseAudit(['clientMutationId' => 'corto']),
    'client_mutation_id',
    '2.12 El identificador de mutación del móvil respeta longitud y alfabeto (C5)'
);
$expectFailure(
    static fn () => $baseAudit(['va1TotalSalesEur' => -0.01]),
    'va1_total_sales_eur',
    '2.13 Un total de ventas negativo se rechaza'
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Enlace único y anulación lógica (Art. III.3 y V.1) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$linked = $audit->withLinkToIncident(44);
$assert(
    '3.1 Un volcado ACTIVO se enlaza una vez con la incidencia',
    $linked->getIncidentId() === 44 && $audit->getIncidentId() === null
);
$expectFailure(
    static fn () => $linked->withLinkToIncident(45),
    'solo se fija una vez',
    '3.2 El vínculo ya fijado no se puede reescribir'
);
$voided = $audit->withVoid(
    'Reimportación correcta tras corregir el fichero original',
    7,
    'Coordinación de Pruebas',
    '2026-11-21T10:00:00+00:00'
);
$assert(
    '3.3 La anulación devuelve una copia VOIDED y deja intacta la fila activa',
    $voided->isVoided()
        && $voided->getVoidReason() !== null
        && $voided->getVoidedBy() === 7
        && $audit->isActive()
);
$expectFailure(
    static fn () => $voided->withVoid(
        'Segundo intento de anulación sobre la misma fila',
        7,
        'Coordinación de Pruebas',
        '2026-11-22T10:00:00+00:00'
    ),
    'terminal',
    '3.4 Un volcado anulado es terminal'
);
$expectFailure(
    static fn () => $voided->withLinkToCashCollection(9),
    'anulado',
    '3.5 Un volcado anulado no admite vínculos nuevos'
);
$expectFailure(
    static fn () => $audit->withVoid('Motivo corto', 7, 'Coordinación de Pruebas', '2026-11-21T10:00:00+00:00'),
    'entre 20 y 500',
    '3.6 La anulación exige un motivo real de 20 caracteres (Art. V.1)'
);
$expectFailure(
    static fn () => $audit->withVoid(
        'Reimportacioncorrecta correcciondelvolcado',
        7,
        'Coordinación de Pruebas',
        '2026-11-21T10:00:00+00:00'
    ),
    'bloques alfanuméricos',
    '3.7 El motivo exige al menos tres bloques alfanuméricos (Art. V.1)'
);
$assert(
    '3.8 Los enlaces pendientes se pueden fijar después de enlazar otro',
    $audit->withLinkToIncident(4)->withLinkToRestockVisit(8)->withLinkToCashCollection(9)->getCashCollectionId() === 9
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Líneas de selección y de evento ---\n";
// ─────────────────────────────────────────────────────────────────────────

$selection = new MachineDexSelectionLine(
    id: 1,
    dexAuditId: 10,
    lineSequence: 1,
    rawSelectionCode: 'PA101',
    normalizedSelectionCode: 'pa101',
    matchStatus: DexSelectionMatchStatus::EXACT_MATCH,
    matchedSlotId: 5,
    matchedSlotCodeSnapshot: 'A1',
    unitPriceEur: 1.50,
    totalVendUnits: 10,
    totalSalesEur: 15.00,
    deltaVendUnits: 9,
    deltaSalesEur: 13.50,
    slotCurrentEstimatedQty: 7
);
$assert(
    '4.1 La selección normaliza el código y conserva su carril y snapshot (C4)',
    $selection->getNormalizedSelectionCode() === 'PA101'
        && $selection->getMatchedSlotId() === 5
        && $selection->getMatchedSlotCodeSnapshot() === 'A1'
        && $selection->getSlotCurrentEstimatedQty() === 7
);
$expectFailure(
    static fn () => new MachineDexSelectionLine(
        id: null,
        dexAuditId: 10,
        lineSequence: 2,
        rawSelectionCode: 'PA102',
        normalizedSelectionCode: 'PA102',
        matchStatus: DexSelectionMatchStatus::EXACT_MATCH,
        totalVendUnits: 1,
        totalSalesEur: 1.0
    ),
    'exige carril',
    '4.2 `EXACT_MATCH` sin carril ni snapshot se rechaza'
);
$expectFailure(
    static fn () => new MachineDexSelectionLine(
        id: null,
        dexAuditId: 10,
        lineSequence: 3,
        rawSelectionCode: 'PA103',
        normalizedSelectionCode: 'PA103',
        matchStatus: DexSelectionMatchStatus::AMBIGUOUS,
        matchedSlotId: 5,
        matchedSlotCodeSnapshot: 'A1',
        totalVendUnits: 1,
        totalSalesEur: 1.0
    ),
    'no puede inventar',
    '4.3 Un emparejamiento ambiguo no puede inventar carril'
);
$expectFailure(
    static fn () => new MachineDexSelectionLine(
        id: null,
        dexAuditId: 10,
        lineSequence: 4,
        rawSelectionCode: 'PA104',
        normalizedSelectionCode: 'PA104',
        matchStatus: DexSelectionMatchStatus::UNMAPPED,
        totalVendUnits: 0,
        totalSalesEur: -1.0
    ),
    'total_sales_eur',
    '4.4 Un importe negativo se rechaza en la línea'
);
$unitProjection = $selection->toUnitProjection();
$assert(
    '4.5 La proyección de la línea no contiene un solo importe en euros (QA-1)',
    !array_key_exists('unit_price_eur', $unitProjection)
        && !array_key_exists('total_sales_eur', $unitProjection)
        && !array_key_exists('delta_sales_eur', $unitProjection)
        && $unitProjection['total_vend_units'] === 10
        && $unitProjection['delta_vend_units'] === 9
        && $unitProjection['slot_current_estimated_qty'] === 7
);
$assert(
    '4.6 La vista de Coordinación sí expone precio, ventas y deltas monetarios',
    $selection->toCoordinatorArray()['unit_price_eur'] === 1.50
        && $selection->toCoordinatorArray()['total_sales_eur'] === 15.00
        && $selection->toCoordinatorArray()['delta_sales_eur'] === 13.50
);
$assert(
    '4.7 El enriquecimiento de existencia devuelve copia y no muta la línea',
    $selection->withSlotCurrentEstimatedQty(3)->getSlotCurrentEstimatedQty() === 3
        && $selection->getSlotCurrentEstimatedQty() === 7
);

$event = new MachineDexEventLine(
    id: 1,
    dexAuditId: 10,
    lineSequence: 1,
    sourceBlock: 'ea1',
    eventCode: 'eg01',
    eventCategory: DexEventCategory::THERMAL_REFRIGERATION,
    severity: DexEventSeverity::CRITICAL,
    descriptionEs: 'Temperatura de cabina fuera del rango permitido',
    occurrenceCount: 2,
    countSinceReset: 1,
    countSinceInit: 3,
    isActiveFlag: true,
    isThermalAlert: true
);
$assert(
    '4.8 El evento normaliza bloque y código, y conserva los contadores EA202/EA203/EA205',
    $event->getSourceBlock() === 'EA1'
        && $event->getEventCode() === 'EG01'
        && $event->getCountSinceReset() === 1
        && $event->getCountSinceInit() === 3
        && $event->isActiveFlag()
        && $event->isThermalAlert()
);
$expectFailure(
    static fn () => new MachineDexEventLine(
        id: null,
        dexAuditId: 10,
        lineSequence: 2,
        sourceBlock: 'EA3',
        eventCode: 'EG02',
        eventCategory: DexEventCategory::THERMAL_REFRIGERATION,
        severity: DexEventSeverity::CRITICAL,
        descriptionEs: 'Bloque de origen inválido'
    ),
    'EA1 y EA2',
    '4.9 Un bloque de origen fuera de EA1/EA2 se rechaza'
);
$expectFailure(
    static fn () => new MachineDexEventLine(
        id: null,
        dexAuditId: 10,
        lineSequence: 3,
        sourceBlock: 'EA1',
        eventCode: 'EA999',
        eventCategory: DexEventCategory::OTHER,
        severity: DexEventSeverity::INFO,
        descriptionEs: 'Evento sin relación térmica',
        isThermalAlert: true
    ),
    'THERMAL_REFRIGERATION',
    '4.10 El aviso térmico solo cuelga de un evento térmico (QA-4)'
);
$assert(
    '4.11 La proyección del evento incluye clasificación y etiquetas, sin euros',
    $event->toProjection()['event_category'] === 'THERMAL_REFRIGERATION'
        && $event->toProjection()['severity_label'] === 'Crítica'
        && !array_key_exists('unit_price_eur', $event->toProjection())
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 5. Proyecciones del volcado (Art. V.4 / QA-1 / QA-3) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$auditWithLines = $baseAudit([
    'selections' => [$selection],
    'events' => [$event],
    'ca3CashboxEur' => 80.00,
    'ca3CoinTubesEur' => 12.00,
    'deltaSalesEur' => null,
]);

$history = $auditWithLines->toRestockerArray();
$assert(
    '5.1 El GET histórico del Reponedor omite el prefill del contador (QA-1)',
    !array_key_exists('prefill_sales_counter_reading_eur', $history)
);
$assert(
    '5.2 El GET histórico no expone CA3, ventas totales, delta monetario ni payload crudo',
    !array_key_exists('ca3_cashbox_eur', $history)
        && !array_key_exists('ca3_coin_tubes_eur', $history)
        && !array_key_exists('va1_total_sales_eur', $history)
        && !array_key_exists('delta_sales_eur', $history)
        && !array_key_exists('raw_payload', $history)
        && !array_key_exists('sha256_hash', $history)
);
$assert(
    '5.3 La vista del historial conserva unidades, eventos y alerta térmica',
    $history['va1_total_vend_units'] === 41
        && $history['selections'][0]['total_vend_units'] === 10
        && $history['events'][0]['event_code'] === 'EG01'
        && $history['has_thermal_alert'] === true
);
$assert(
    '5.4 La respuesta inmediata del POST incluye solo el prefill de VA1 (QA-1)',
    $auditWithLines->toRestockerArray(true)['prefill_sales_counter_reading_eur'] === 25.50
        && !array_key_exists('ca3_cashbox_eur', $auditWithLines->toRestockerArray(true))
);
$assert(
    '5.5 El Técnico recibe la vista de historial en unidades, sin prefill',
    $auditWithLines->toTechnicianArray() === $history
        && !array_key_exists('prefill_sales_counter_reading_eur', $auditWithLines->toTechnicianArray())
);
$assert(
    '5.6 `toArray()` y `jsonSerialize()` usan la proyección de mínimo privilegio',
    $auditWithLines->toArray() === $history && $auditWithLines->jsonSerialize() === $history
);

$coordinator = $auditWithLines->toCoordinatorArray();
$assert(
    '5.7 La vista de Coordinación expone VA1, CA3, deltas y precios por carril',
    $coordinator['va1_total_sales_eur'] === 25.50
        && $coordinator['ca3_cashbox_eur'] === 80.00
        && $coordinator['selections'][0]['unit_price_eur'] === 1.50
        && $coordinator['selections'][0]['total_sales_eur'] === 15.00
);
$assert(
    '5.8 La vista de Coordinación expone la cadena de deltas y el flag de descarga',
    $coordinator['delta_source'] === 'INITIAL_BASELINE'
        && $coordinator['out_of_order_sync'] === false
        && $coordinator['can_download_raw'] === true
        && array_key_exists('previous_audit_voided', $coordinator)
        && array_key_exists('effective_delta_sales_eur', $coordinator)
        && array_key_exists('m34_cross_check', $coordinator)
        && array_key_exists('m35_cross_check', $coordinator)
);
$assert(
    '5.9 Ni la vista de Coordinación expone el payload crudo (C3)',
    !array_key_exists('raw_payload', $coordinator)
);

$assert(
    '5.10 El payload de escalado en un toque se construye desde el evento térmico (QA-4)',
    is_array($history['suggested_escalation_payload'])
        && $history['suggested_escalation_payload']['category'] === 'TEMPERATURE_COLD'
        && $history['suggested_escalation_payload']['urgency'] === 'CRITICAL'
        && $history['suggested_escalation_payload']['dispatch_level'] === 'L2_SAT'
        && $history['suggested_escalation_payload']['event_codes'] === ['EG01']
        && $history['suggested_escalation_payload']['machine_id'] === 3
);
$assert(
    '5.11 Un volcado sin eventos térmicos no sugiere escalado',
    $audit->toRestockerArray()['suggested_escalation_payload'] === null
        && $audit->hasThermalAlert() === false
);

$withEffective = $auditWithLines->withPreviousAuditVoided(true, 12.75, 4);
$assert(
    '5.12 La Coordinación lee deltas efectivos cuando el predecesor fue anulado (QA-2)',
    $withEffective->isPreviousAuditVoided()
        && $withEffective->getEffectiveDeltaSalesEur() === 12.75
        && $withEffective->getEffectiveDeltaVendUnits() === 4
        && $withEffective->toCoordinatorArray()['effective_delta_sales_eur'] === 12.75
);
$expectFailure(
    static fn () => $auditWithLines->withPreviousAuditVoided(true),
    'delta efectivo',
    '5.13 Declarar el predecesor anulado sin delta efectivo se rechaza'
);
$assert(
    '5.14 Los cruces M34/M35 se adjuntan sin mutar la fila',
    $auditWithLines
        ->withCrossChecks(['observed_consumption_units' => 2], ['m35_check' => 'CA3_VS_VAULT'])
        ->getM35CrossCheck() === ['m35_check' => 'CA3_VS_VAULT']
        && $auditWithLines->getM34CrossCheck() === null
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 6. Cierre ---\n";
// ─────────────────────────────────────────────────────────────────────────

$otherAuditLine = new MachineDexSelectionLine(
    id: null,
    dexAuditId: 99,
    lineSequence: 1,
    rawSelectionCode: 'PA201',
    normalizedSelectionCode: 'PA201',
    matchStatus: DexSelectionMatchStatus::UNMAPPED,
    totalVendUnits: 0,
    totalSalesEur: 0.0
);
$expectFailure(
    static fn () => $auditWithLines->withSelections([$otherAuditLine]),
    'otro volcado',
    '6.1 Una línea no puede colgar de un volcado distinto'
);
$assert(
    '6.2 La serialización por defecto del volcado tampoco filtra euros',
    !array_key_exists('va1_total_sales_eur', $auditWithLines->jsonSerialize())
        && !array_key_exists('raw_payload', $auditWithLines->jsonSerialize())
);
$assert(
    '6.3 Las cinco entidades y enumerados del módulo se cargan sin dependencias externas',
    class_exists(MachineDexAudit::class)
        && class_exists(MachineDexSelectionLine::class)
        && class_exists(MachineDexEventLine::class)
        && count(DexEventCategory::values()) === 5
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-DEX-03 (dominio inmutable y proyecciones Art. V.4) CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL DOMINIO DEL VOLCADO .DEX.\n";
echo "======================================================================\n";
exit(1);
