<?php

declare(strict_types=1);

/**
 * RestockRoutePlannerDomainModelsTest · T-RRP-03
 *
 * Certifica la capa de dominio del Módulo 37 antes de que exista un servicio:
 * los seis enumerados del contrato, la ampliación de `ProductLoadingDemandMode`
 * con `TODAY_ROUTE` y las dos entidades del modelo híbrido.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Enumerados #45–#50 (RF-01.1, RF-02.1, D6, QA-4).** Declaran exactamente
 *      los valores del motor, sus etiquetas y los predicados estructurales que
 *      usan las entidades: qué cadencia exige día ISO, cuál exige ancla, cuál
 *      admite fines de semana y cómo se ordenan las franjas.
 *
 *   2. **`TODAY_ROUTE` (D5, QA-5).** El tercer modo de demanda existe, conserva
 *      los dos anteriores y no altera el alcance de flota de `includesAllGaps()`:
 *      la restricción a la ruta efectiva la aplica el servicio del Módulo 33.
 *
 *   3. **Agenda versionada (Tabla 50, QA-2, Art. III.3).** La entidad reproduce
 *      `chk_mrs_cadence_coherence` con la prueba de nulidad explícita,
 *      `chk_mrs_deactivation_coherence` y la cota de la nota; `deactivate()`
 *      devuelve una copia cerrada sin mutar la versión vigente.
 *
 *   4. **Excepción diaria (Tabla 51, C1, Art. V.1).** `SKIPPED` exige causa y
 *      motivo de 20 caracteres reales; `REASSIGNED` exige destino y causa y
 *      admite nota opcional; el estado terminal sella su coherencia; la
 *      idempotencia offline se acota a `8..64` con su patrón.
 *
 *   5. **Reconstrucción desde el motor.** `fromDatabaseRow()` hidrata las dos
 *      entidades con los tipos nativos ya convertidos y `toArray()` publica el
 *      contrato público con etiquetas en castellano.
 *
 * CATEGORÍA: hermética (`tests/unit/`). No toca la base de datos ni el reloj.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Enum\ProductLoadingDemandMode;
use VendGuard\Core\Domain\Enum\RestockCadenceType;
use VendGuard\Core\Domain\Enum\RestockOverrideStatus;
use VendGuard\Core\Domain\Enum\RestockOverrideType;
use VendGuard\Core\Domain\Enum\RestockReassignmentReasonCode;
use VendGuard\Core\Domain\Enum\RestockSkipReasonCode;
use VendGuard\Core\Domain\Enum\RestockTimeWindow;
use VendGuard\Core\Domain\Model\MachineRestockSchedule;
use VendGuard\Core\Domain\Model\RestockRouteDailyOverride;

$assertions = 0;

function assertRestockPlannerCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param callable(): void $operation
 */
function assertRestockPlannerRejects(callable $operation, string $message): void
{
    global $assertions;
    $assertions++;
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

/**
 * @param array<string, mixed> $overrides
 */
function makeRestockSchedule(array $overrides = []): MachineRestockSchedule
{
    // `array_key_exists` y no `??`: un `null` explícito debe limpiar el campo,
    // no caer al valor por defecto del ayudante.
    return new MachineRestockSchedule(
        id: array_key_exists('id', $overrides) ? $overrides['id'] : null,
        machineId: array_key_exists('machine_id', $overrides) ? $overrides['machine_id'] : 8,
        cadenceType: array_key_exists('cadence_type', $overrides) ? $overrides['cadence_type'] : RestockCadenceType::DAILY,
        dayOfWeek: array_key_exists('day_of_week', $overrides) ? $overrides['day_of_week'] : null,
        anchorDate: array_key_exists('anchor_date', $overrides) ? $overrides['anchor_date'] : null,
        preferredTimeWindow: array_key_exists('preferred_time_window', $overrides) ? $overrides['preferred_time_window'] : RestockTimeWindow::ANY,
        includeWeekends: array_key_exists('include_weekends', $overrides) ? $overrides['include_weekends'] : false,
        notes: array_key_exists('notes', $overrides) ? $overrides['notes'] : null,
        isActive: array_key_exists('is_active', $overrides) ? $overrides['is_active'] : true,
        createdBy: array_key_exists('created_by', $overrides) ? $overrides['created_by'] : 3,
        updatedBy: array_key_exists('updated_by', $overrides) ? $overrides['updated_by'] : 3,
        deactivatedBy: array_key_exists('deactivated_by', $overrides) ? $overrides['deactivated_by'] : null,
        createdAt: array_key_exists('created_at', $overrides) ? $overrides['created_at'] : null,
        updatedAt: array_key_exists('updated_at', $overrides) ? $overrides['updated_at'] : null,
        deactivatedAt: array_key_exists('deactivated_at', $overrides) ? $overrides['deactivated_at'] : null,
    );
}

/**
 * @param array<string, mixed> $overrides
 */
function makeRestockOverride(array $overrides = []): RestockRouteDailyOverride
{
    // `array_key_exists` y no `??`: un `null` explícito debe limpiar el campo,
    // no caer al valor por defecto del ayudante.
    return new RestockRouteDailyOverride(
        id: array_key_exists('id', $overrides) ? $overrides['id'] : null,
        machineId: array_key_exists('machine_id', $overrides) ? $overrides['machine_id'] : 8,
        serviceDate: array_key_exists('service_date', $overrides) ? $overrides['service_date'] : '2026-11-14',
        overrideType: array_key_exists('override_type', $overrides) ? $overrides['override_type'] : RestockOverrideType::SKIPPED,
        status: array_key_exists('status', $overrides) ? $overrides['status'] : RestockOverrideStatus::ACTIVE,
        originalRestockerId: array_key_exists('original_restocker_id', $overrides) ? $overrides['original_restocker_id'] : null,
        targetRestockerId: array_key_exists('target_restocker_id', $overrides) ? $overrides['target_restocker_id'] : null,
        skipReasonCode: array_key_exists('skip_reason_code', $overrides) ? $overrides['skip_reason_code'] : RestockSkipReasonCode::LOCATION_CLOSED,
        reassignmentReasonCode: array_key_exists('reassignment_reason_code', $overrides) ? $overrides['reassignment_reason_code'] : null,
        reasonText: array_key_exists('reason_text', $overrides) ? $overrides['reason_text'] : 'Sede cerrada por inventario anual.',
        hadActiveFefoAlert: array_key_exists('had_active_fefo_alert', $overrides) ? $overrides['had_active_fefo_alert'] : false,
        triggeredNotificationId: array_key_exists('triggered_notification_id', $overrides) ? $overrides['triggered_notification_id'] : null,
        clientMutationId: array_key_exists('client_mutation_id', $overrides) ? $overrides['client_mutation_id'] : null,
        supersededByVisitId: array_key_exists('superseded_by_visit_id', $overrides) ? $overrides['superseded_by_visit_id'] : null,
        createdBy: array_key_exists('created_by', $overrides) ? $overrides['created_by'] : 3,
        resolvedBy: array_key_exists('resolved_by', $overrides) ? $overrides['resolved_by'] : null,
        createdAt: array_key_exists('created_at', $overrides) ? $overrides['created_at'] : null,
        resolvedAt: array_key_exists('resolved_at', $overrides) ? $overrides['resolved_at'] : null,
        updatedAt: array_key_exists('updated_at', $overrides) ? $overrides['updated_at'] : null,
    );
}

echo "======================================================================\n";
echo " VENDGUARD · T-RRP-03 · Modelos de dominio del planificador de rutas\n";
echo "======================================================================\n";

try {
    // ─────────────────────────────────────────────────────────────────────
    // 1. Enumerados #45–#50 (RF-01.1, RF-02.1, D6, QA-4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Enumerados del contrato ---\n";

    assertRestockPlannerCondition(
        RestockCadenceType::values() === ['DAILY', 'MON_WED_FRI', 'TUE_THU', 'WEEKLY', 'BIWEEKLY'],
        'RestockCadenceType declara las cinco cadencias en el orden del motor'
    );
    $labelsComplete = true;
    foreach ([
        RestockCadenceType::cases(),
        RestockTimeWindow::cases(),
        RestockOverrideType::cases(),
        RestockOverrideStatus::cases(),
        RestockSkipReasonCode::cases(),
        RestockReassignmentReasonCode::cases(),
    ] as $cases) {
        foreach ($cases as $case) {
            $labelsComplete = $labelsComplete && trim($case->label()) !== '';
        }
    }
    assertRestockPlannerCondition(
        $labelsComplete,
        'Los seis enumerados declaran etiqueta en castellano para todos sus valores'
    );
    assertRestockPlannerCondition(
        RestockCadenceType::WEEKLY->requiresDayOfWeek()
            && !RestockCadenceType::DAILY->requiresDayOfWeek()
            && RestockCadenceType::BIWEEKLY->requiresAnchorDate()
            && RestockCadenceType::DAILY->allowsWeekends()
            && !RestockCadenceType::WEEKLY->allowsWeekends()
            && RestockCadenceType::MON_WED_FRI->isFixedWeekdayPattern(),
        'RestockCadenceType distingue día ISO, ancla y fines de semana por cadencia'
    );
    assertRestockPlannerCondition(
        RestockCadenceType::fromString(' weekly ') === RestockCadenceType::WEEKLY
            && RestockCadenceType::isValid('BIWEEKLY')
            && !RestockCadenceType::isValid('EVERY_DAY'),
        'RestockCadenceType parsea valores canónicos y rechaza los ajenos'
    );
    assertRestockPlannerRejects(
        static fn() => RestockCadenceType::fromString('EVERY_DAY'),
        'RestockCadenceType.fromString rechaza una cadencia desconocida'
    );

    assertRestockPlannerCondition(
        RestockTimeWindow::values() === ['MORNING', 'MIDDAY', 'AFTERNOON', 'ANY']
            && RestockTimeWindow::MORNING->windowOrder() === 1
            && RestockTimeWindow::MIDDAY->windowOrder() === 2
            && RestockTimeWindow::AFTERNOON->windowOrder() === 3
            && RestockTimeWindow::ANY->windowOrder() === 4
            && RestockTimeWindow::ANY->isAny()
            && !RestockTimeWindow::MORNING->isAny(),
        'RestockTimeWindow ordena las franjas y deja ANY al final'
    );
    assertRestockPlannerCondition(
        RestockOverrideType::SKIPPED->requiresSkipReasonCode()
            && !RestockOverrideType::SKIPPED->requiresTargetRestocker()
            && RestockOverrideType::REASSIGNED->requiresTargetRestocker()
            && RestockOverrideType::SKIPPED->requiresReasonText(),
        'RestockOverrideType declara los campos obligatorios de cada rama'
    );
    assertRestockPlannerCondition(
        RestockOverrideStatus::values() === ['ACTIVE', 'SUPERSEDED', 'REVOKED']
            && RestockOverrideStatus::ACTIVE->isActive()
            && !RestockOverrideStatus::ACTIVE->isTerminal()
            && RestockOverrideStatus::SUPERSEDED->isTerminal()
            && RestockOverrideStatus::REVOKED->isRevoked(),
        'RestockOverrideStatus declara el ciclo y sus estados terminales'
    );
    assertRestockPlannerCondition(
        RestockSkipReasonCode::values() === [
            'LOCATION_CLOSED',
            'NO_KEY_OR_ACCESS',
            'LOCAL_HOLIDAY',
            'FLEET_CAPACITY_SHORTAGE',
            'OTHER_OPERATIONAL',
        ]
            && RestockSkipReasonCode::OTHER_OPERATIONAL->requiresWrittenExplanation()
            && !RestockSkipReasonCode::LOCATION_CLOSED->requiresWrittenExplanation(),
        'RestockSkipReasonCode declara las cinco causas tipificadas'
    );
    assertRestockPlannerCondition(
        RestockReassignmentReasonCode::values() === [
            'ZONE_UNCOVERED',
            'LOAD_BALANCING',
            'FEFO_RESCUE',
            'OTHER_REASSIGNMENT',
        ]
            && RestockReassignmentReasonCode::FEFO_RESCUE->isSanitaryRescue()
            && !RestockReassignmentReasonCode::LOAD_BALANCING->isSanitaryRescue(),
        'RestockReassignmentReasonCode declara las cuatro causas y el rescate FEFO'
    );
    assertRestockPlannerCondition(
        ProductLoadingDemandMode::values() === ['ALERTS_ONLY', 'ALL_GAPS', 'TODAY_ROUTE']
            && ProductLoadingDemandMode::TODAY_ROUTE->label() === 'Ruta efectiva de hoy'
            && ProductLoadingDemandMode::TODAY_ROUTE->isTodayRoute()
            && !ProductLoadingDemandMode::ALL_GAPS->isTodayRoute(),
        'ProductLoadingDemandMode incorpora TODAY_ROUTE con su etiqueta'
    );
    assertRestockPlannerCondition(
        ProductLoadingDemandMode::ALL_GAPS->includesAllGaps()
            && !ProductLoadingDemandMode::TODAY_ROUTE->includesAllGaps()
            && ProductLoadingDemandMode::ALERTS_ONLY->isAlertsOnly(),
        'TODAY_ROUTE no altera el alcance de flota de includesAllGaps()'
    );
    echo "  [OK]   Seis enumerados, etiquetas y predicados estructurales\n";

    // ─────────────────────────────────────────────────────────────────────
    // 2. Agenda versionada (Tabla 50, QA-2, Art. III.3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- MachineRestockSchedule ---\n";

    $daily = makeRestockSchedule([
        'notes' => '  Reposición diaria de la sede central.  ',
        'preferred_time_window' => RestockTimeWindow::MORNING,
    ]);
    assertRestockPlannerCondition(
        $daily->getCadenceType() === RestockCadenceType::DAILY
            && $daily->getDayOfWeek() === null
            && $daily->getAnchorDate() === null
            && $daily->getPreferredTimeWindow() === RestockTimeWindow::MORNING
            && $daily->isActive()
            && $daily->isDaily()
            && $daily->getNotes() === 'Reposición diaria de la sede central.',
        'Una agenda DAILY nace sin día ni ancla, activa y con la nota recortada'
    );

    $weekly = makeRestockSchedule([
        'cadence_type' => RestockCadenceType::WEEKLY,
        'day_of_week' => 3,
    ]);
    assertRestockPlannerCondition(
        $weekly->isWeekly() && $weekly->getDayOfWeek() === 3,
        'Una agenda WEEKLY conserva su día ISO'
    );

    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule(['cadence_type' => RestockCadenceType::WEEKLY]),
        'WEEKLY sin day_of_week se rechaza en el borde (chk_mrs_cadence_coherence)'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule([
            'cadence_type' => RestockCadenceType::WEEKLY,
            'day_of_week' => 3,
            'anchor_date' => '2026-11-02',
        ]),
        'WEEKLY con anchor_date se rechaza en el borde'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule(['cadence_type' => RestockCadenceType::BIWEEKLY]),
        'BIWEEKLY sin anchor_date se rechaza en el borde'
    );
    $biweekly = makeRestockSchedule([
        'cadence_type' => RestockCadenceType::BIWEEKLY,
        'anchor_date' => '2026-11-02',
    ]);
    assertRestockPlannerCondition(
        $biweekly->isBiweekly() && $biweekly->getAnchorDate() === '2026-11-02',
        'Una agenda BIWEEKLY conserva su fecha ancla'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule([
            'cadence_type' => RestockCadenceType::BIWEEKLY,
            'anchor_date' => '2026-11-02',
            'day_of_week' => 1,
        ]),
        'BIWEEKLY con day_of_week se rechaza en el borde'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule(['day_of_week' => 2]),
        'Una cadencia fija con day_of_week se rechaza en el borde'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule(['day_of_week' => 0]),
        'Un día ISO fuera de 1..7 se rechaza'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule([
            'cadence_type' => RestockCadenceType::WEEKLY,
            'day_of_week' => 8,
        ]),
        'Un día ISO 8 se rechaza'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule([
            'cadence_type' => RestockCadenceType::BIWEEKLY,
            'anchor_date' => '02/11/2026',
        ]),
        'Una fecha ancla con formato ajeno se rechaza'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule([
            'cadence_type' => RestockCadenceType::BIWEEKLY,
            'anchor_date' => '2026-02-30',
        ]),
        'Una fecha ancla inexistente se rechaza'
    );
    assertRestockPlannerCondition(
        makeRestockSchedule(['include_weekends' => true])->includesWeekends(),
        'DAILY admite include_weekends = TRUE'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule([
            'cadence_type' => RestockCadenceType::WEEKLY,
            'day_of_week' => 3,
            'include_weekends' => true,
        ]),
        'include_weekends fuera de DAILY se rechaza'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule(['notes' => str_repeat('x', 501)]),
        'Una nota de 501 caracteres se rechaza'
    );
    assertRestockPlannerCondition(
        makeRestockSchedule(['notes' => '   '])->getNotes() === null,
        'Una nota en blanco se normaliza a null'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule([
            'is_active' => true,
            'deactivated_at' => '2026-11-14 09:00:00+00',
        ]),
        'Una agenda activa no puede declarar deactivated_at'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule([
            'is_active' => false,
            'deactivated_at' => null,
        ]),
        'Una agenda inactiva exige deactivated_at (chk_mrs_deactivation_coherence)'
    );
    $closed = makeRestockSchedule([
        'id' => 41,
        'is_active' => false,
        'deactivated_at' => '2026-11-14 09:00:00+00',
        'deactivated_by' => 3,
    ]);
    assertRestockPlannerCondition(
        !$closed->isActive() && $closed->getDeactivatedBy() === 3,
        'Una agenda cerrada conserva su actor y su sello de baja'
    );

    $deactivatedCopy = $daily->deactivate(3, '2026-11-14 10:00:00+00');
    assertRestockPlannerCondition(
        $daily->isActive()
            && !$deactivatedCopy->isActive()
            && $deactivatedCopy->getDeactivatedAt() === '2026-11-14 10:00:00+00'
            && $deactivatedCopy->getMachineId() === $daily->getMachineId(),
        'deactivate() devuelve una copia cerrada sin mutar la versión vigente'
    );
    assertRestockPlannerRejects(
        static fn() => $deactivatedCopy->deactivate(3, '2026-11-14 11:00:00+00'),
        'Una versión ya cerrada no se desactiva dos veces'
    );

    $hydratedSchedule = MachineRestockSchedule::fromDatabaseRow([
        'id' => '17',
        'machine_id' => '8',
        'cadence_type' => 'WEEKLY',
        'day_of_week' => '3',
        'anchor_date' => null,
        'preferred_time_window' => 'MORNING',
        'include_weekends' => 'f',
        'notes' => 'Agenda hidratada desde el motor.',
        'is_active' => 't',
        'created_by' => '3',
        'updated_by' => '4',
        'deactivated_by' => null,
        'created_at' => '2026-11-14 08:00:00+00',
        'updated_at' => '2026-11-14 08:30:00+00',
        'deactivated_at' => null,
    ]);
    $schedulePayload = $hydratedSchedule->toArray();
    assertRestockPlannerCondition(
        $hydratedSchedule->getId() === 17
            && $hydratedSchedule->isActive()
            && $hydratedSchedule->isWeekly()
            && $schedulePayload['cadence_label'] === 'Semanal'
            && $schedulePayload['preferred_time_window_label'] === 'Mañana'
            && $schedulePayload['include_weekends'] === false,
        'fromDatabaseRow() hidrata la agenda y toArray() publica sus etiquetas'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule(['machine_id' => 0]),
        'La agenda exige una máquina existente'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockSchedule(['created_by' => 0]),
        'La agenda exige el usuario que la crea'
    );
    echo "  [OK]   Coherencia de cadencia, baja lógica y versionado inmutable\n";

    // ─────────────────────────────────────────────────────────────────────
    // 3. Excepción diaria (Tabla 51, C1, Art. V.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- RestockRouteDailyOverride ---\n";

    $skipped = makeRestockOverride([
        'original_restocker_id' => 12,
        'had_active_fefo_alert' => true,
        'triggered_notification_id' => 900,
    ]);
    assertRestockPlannerCondition(
        $skipped->isSkipped()
            && $skipped->isActive()
            && $skipped->hadActiveFefoAlert()
            && $skipped->getTriggeredNotificationId() === 900
            && $skipped->getServiceDate() === '2026-11-14'
            && $skipped->getSkipReasonCode() === RestockSkipReasonCode::LOCATION_CLOSED,
        'Una omisión SKIPPED válida conserva causa, sello FEFO y aviso interno'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride(['reason_text' => str_repeat('x', 19)]),
        'Una omisión con 19 caracteres reales se rechaza (Art. V.1)'
    );
    assertRestockPlannerCondition(
        makeRestockOverride(['reason_text' => str_repeat('x', 20)])->getReasonText() === str_repeat('x', 20),
        'Una omisión con exactamente 20 caracteres reales se admite'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride(['reason_text' => str_repeat('x', 501)]),
        'Una omisión de 501 caracteres se rechaza'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride(['skip_reason_code' => null]),
        'Una omisión sin causa tipificada se rechaza'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride(['target_restocker_id' => 5]),
        'Una omisión no puede declarar reponedor destino'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride([
            'reassignment_reason_code' => RestockReassignmentReasonCode::ZONE_UNCOVERED,
        ]),
        'Una omisión no puede declarar causa de desvío'
    );

    $reassigned = makeRestockOverride([
        'override_type' => RestockOverrideType::REASSIGNED,
        'skip_reason_code' => null,
        'target_restocker_id' => 5,
        'reassignment_reason_code' => RestockReassignmentReasonCode::ZONE_UNCOVERED,
        'reason_text' => null,
    ]);
    assertRestockPlannerCondition(
        $reassigned->isReassigned()
            && $reassigned->getTargetRestockerId() === 5
            && $reassigned->getReassignmentReasonCode() === RestockReassignmentReasonCode::ZONE_UNCOVERED
            && $reassigned->getReasonText() === null,
        'Un desvío REASSIGNED admite nota opcional y exige destino y causa'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride([
            'override_type' => RestockOverrideType::REASSIGNED,
            'skip_reason_code' => null,
            'reassignment_reason_code' => RestockReassignmentReasonCode::ZONE_UNCOVERED,
        ]),
        'Un desvío sin target_restocker_id se rechaza'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride([
            'override_type' => RestockOverrideType::REASSIGNED,
            'skip_reason_code' => null,
            'target_restocker_id' => 5,
        ]),
        'Un desvío sin reassignment_reason_code se rechaza'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride([
            'override_type' => RestockOverrideType::REASSIGNED,
            'target_restocker_id' => 5,
            'reassignment_reason_code' => RestockReassignmentReasonCode::ZONE_UNCOVERED,
        ]),
        'Un desvío no puede conservar skip_reason_code'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride([
            'override_type' => RestockOverrideType::REASSIGNED,
            'skip_reason_code' => null,
            'target_restocker_id' => 5,
            'reassignment_reason_code' => RestockReassignmentReasonCode::ZONE_UNCOVERED,
            'reason_text' => str_repeat('x', 501),
        ]),
        'Una nota de desvío de 501 caracteres se rechaza'
    );

    assertRestockPlannerCondition(
        makeRestockOverride(['client_mutation_id' => null])->getClientMutationId() === null
            && makeRestockOverride(['client_mutation_id' => 'skip.2026-11-14.0001'])->getClientMutationId()
                === 'skip.2026-11-14.0001'
            && makeRestockOverride(['client_mutation_id' => str_repeat('a', 64)])->getClientMutationId()
                === str_repeat('a', 64),
        'client_mutation_id admite null y el rango 8..64 del contrato offline'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride(['client_mutation_id' => 'skip.01']),
        'client_mutation_id de menos de 8 caracteres se rechaza'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride(['client_mutation_id' => str_repeat('a', 65)]),
        'client_mutation_id de 65 caracteres se rechaza'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride(['client_mutation_id' => 'skip 2026/11/14']),
        'client_mutation_id con caracteres ajenos al patrón se rechaza'
    );

    assertRestockPlannerRejects(
        static fn() => makeRestockOverride(['resolved_at' => '2026-11-14 09:00:00+00']),
        'Una excepción ACTIVE no puede declarar resolved_at'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride([
            'status' => RestockOverrideStatus::SUPERSEDED,
            'resolved_at' => '2026-11-14 09:00:00+00',
        ]),
        'Una excepción SUPERSEDED exige superseded_by_visit_id'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride([
            'override_type' => RestockOverrideType::REASSIGNED,
            'skip_reason_code' => null,
            'target_restocker_id' => 5,
            'reassignment_reason_code' => RestockReassignmentReasonCode::LOAD_BALANCING,
            'status' => RestockOverrideStatus::SUPERSEDED,
            'superseded_by_visit_id' => 77,
            'resolved_at' => '2026-11-14 09:00:00+00',
        ]),
        'Solo una omisión SKIPPED puede quedar SUPERSEDED'
    );
    $superseded = makeRestockOverride([
        'status' => RestockOverrideStatus::SUPERSEDED,
        'superseded_by_visit_id' => 77,
        'resolved_at' => '2026-11-14 09:00:00+00',
        'resolved_by' => 3,
    ]);
    assertRestockPlannerCondition(
        $superseded->isSuperseded()
            && $superseded->getSupersededByVisitId() === 77
            && !$superseded->isActive(),
        'Una omisión SUPERSEDED conserva la visita que la superó'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride([
            'status' => RestockOverrideStatus::REVOKED,
        ]),
        'Una excepción REVOKED exige resolved_at'
    );
    $revoked = makeRestockOverride([
        'status' => RestockOverrideStatus::REVOKED,
        'resolved_at' => '2026-11-14 09:00:00+00',
        'resolved_by' => 3,
    ]);
    assertRestockPlannerCondition(
        $revoked->isRevoked() && $revoked->getResolvedBy() === 3,
        'Una excepción REVOKED conserva su actor y su sello'
    );

    $revokedCopy = $skipped->revoke(4, '2026-11-14 10:15:00+00');
    assertRestockPlannerCondition(
        $skipped->isActive()
            && $revokedCopy->isRevoked()
            && $revokedCopy->getResolvedBy() === 4
            && $revokedCopy->getResolvedAt() === '2026-11-14 10:15:00+00',
        'revoke() devuelve una copia revocada sin mutar la excepción viva'
    );
    assertRestockPlannerRejects(
        static fn() => $revokedCopy->revoke(4, '2026-11-14 11:00:00+00'),
        'Una excepción ya resuelta no se revoca dos veces'
    );
    assertRestockPlannerRejects(
        static fn() => $reassigned->supersede(77, 3, '2026-11-14 11:00:00+00'),
        'Un desvío no puede quedar superado por una visita'
    );

    $supersededCopy = $skipped->supersede(88, 3, '2026-11-14 12:00:00+00');
    assertRestockPlannerCondition(
        $supersededCopy->isSuperseded()
            && $supersededCopy->getSupersededByVisitId() === 88
            && $skipped->isActive(),
        'supersede() devuelve una copia con la visita y no muta la omisión viva'
    );

    $hydratedOverride = RestockRouteDailyOverride::fromDatabaseRow([
        'id' => '31',
        'machine_id' => '8',
        'service_date' => '2026-11-14',
        'override_type' => 'REASSIGNED',
        'status' => 'ACTIVE',
        'original_restocker_id' => null,
        'target_restocker_id' => '5',
        'skip_reason_code' => null,
        'reassignment_reason_code' => 'FEFO_RESCUE',
        'reason_text' => null,
        'had_active_fefo_alert' => 'f',
        'triggered_notification_id' => null,
        'client_mutation_id' => 'reassign.2026-11-14.0001',
        'superseded_by_visit_id' => null,
        'created_by' => '3',
        'resolved_by' => null,
        'created_at' => '2026-11-14 08:00:00+00',
        'resolved_at' => null,
        'updated_at' => '2026-11-14 08:00:00+00',
    ]);
    $overridePayload = $hydratedOverride->toArray();
    assertRestockPlannerCondition(
        $hydratedOverride->getId() === 31
            && $hydratedOverride->isReassigned()
            && $hydratedOverride->getReassignmentReasonCode() === RestockReassignmentReasonCode::FEFO_RESCUE
            && $overridePayload['override_type_label'] === 'Reasignada'
            && $overridePayload['reassignment_reason_label'] === 'Rescate FEFO'
            && $overridePayload['status_label'] === 'Activa'
            && $overridePayload['is_reassigned'] === true,
        'fromDatabaseRow() hidrata la excepción y toArray() publica sus etiquetas'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride(['service_date' => '2026-13-01']),
        'Una service_date imposible se rechaza'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride(['service_date' => '14/11/2026']),
        'Una service_date con formato ajeno se rechaza'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride(['machine_id' => 0]),
        'La excepción exige una máquina existente'
    );
    assertRestockPlannerRejects(
        static fn() => makeRestockOverride(['created_by' => 0]),
        'La excepción exige el usuario que la registra'
    );
    echo "  [OK]   Ramas SKIPPED/REASSIGNED, idempotencia y ciclo de resolución\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
}

echo "======================================================================\n";
echo " VENDGUARD · T-RRP-03 · Modelos de dominio del planificador de rutas\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE\n";
echo "======================================================================\n";
exit(0);
