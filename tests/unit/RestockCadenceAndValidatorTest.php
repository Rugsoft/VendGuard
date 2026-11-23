<?php

declare(strict_types=1);

/**
 * RestockCadenceAndValidatorTest · T-RRP-04
 *
 * Certifica el calendario determinista del Módulo 37 y el contrato de las
 * excepciones diarias antes de que exista un servicio que los orqueste.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **`DAILY × SlaTier` (EARS 01.2, D6).** Lunes a viernes para `STANDARD` y
 *      `PRIORITY_CORPORATE`; todos los días en `CRITICAL_INFRASTRUCTURE_24_7` o
 *      con `include_weekends = TRUE`.
 *   2. **Cadencias fijas y semanal.** `MON_WED_FRI` (1/3/5), `TUE_THU` (2/4) y
 *      `WEEKLY` comparando el día ISO exacto de la fecha civil.
 *   3. **Quincenal DST-safe.** `BIWEEKLY` exige `service_date >= anchor_date` y
 *      una diferencia de días naturales múltiplo exacto de 14; las pruebas
 *      cruzan los dos cambios de hora de `Europe/Madrid` (marzo y octubre) para
 *      demostrar que el ancla a medianoche UTC no desplaza ni un día.
 *   4. **`nextScheduledDate`.** Primera fecha estrictamente posterior; ancla
 *      futura devuelta tal cual aunque diste más de 15 días; `null` con pausa
 *      estacional vigente o agenda inactiva.
 *   5. **Pausa estacional (QA-6).** Rige mientras `seasonal_pause_until` sea
 *      igual o posterior a la fecha, y de forma indefinida si no tiene fin.
 *   6. **`SKIPPED` (Art. V.1, QA-4).** `20..500` caracteres reales con al menos
 *      3 bloques alfanuméricos, causa tipificada y `RESTOCKER` solo para hoy.
 *   7. **`REASSIGNED` (QA-4).** Exclusivo de Coordinación, destino activo,
 *      prohibición de auto-desvío, causa del enum #50 y nota opcional acotada.
 *   8. **Idempotencia offline y conflictos.** `client_mutation_id` `8..64` con su
 *      patrón, y traducción exacta de los `409` del día.
 *
 * CATEGORÍA: hermética (`tests/unit/`). No toca base de datos, red ni reloj real.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Enum\RestockCadenceType;
use VendGuard\Core\Domain\Enum\RestockReassignmentReasonCode;
use VendGuard\Core\Domain\Enum\RestockSkipReasonCode;
use VendGuard\Core\Domain\Enum\RestockTimeWindow;
use VendGuard\Core\Domain\Exception\RestockRoutePlannerValidationException;
use VendGuard\Core\Domain\Model\MachineRestockSchedule;
use VendGuard\Core\Domain\ValueObject\SlaTier;
use VendGuard\Core\Service\RestockCadenceEvaluator;
use VendGuard\Core\Service\RestockRoutePlannerValidator;

$assertions = 0;

function assertRestockCadenceCondition(bool $condition, string $message): void
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
function assertRestockPlannerRejection(
    callable $operation,
    string $expectedCode,
    int $expectedStatus,
    string $message
): void {
    global $assertions;
    $assertions++;
    try {
        $operation();
    } catch (RestockRoutePlannerValidationException $exception) {
        if ($exception->getErrorCode() !== $expectedCode || $exception->getHttpStatusCode() !== $expectedStatus) {
            throw new RuntimeException(sprintf(
                '%s: se esperaba %s/%d y se obtuvo %s/%d.',
                $message,
                $expectedCode,
                $expectedStatus,
                $exception->getErrorCode(),
                $exception->getHttpStatusCode()
            ));
        }

        return;
    }

    throw new RuntimeException($message . ': no se lanzó ninguna excepción de validación.');
}

/**
 * @param callable(): void $operation
 */
function assertRestockCadenceInvalidArgument(callable $operation, string $message): void
{
    global $assertions;
    $assertions++;
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message . ': no se rechazó la fecha civil inválida.');
}

function makeCadenceSchedule(array $overrides = []): MachineRestockSchedule
{
    $isActive = array_key_exists('is_active', $overrides) ? $overrides['is_active'] : true;
    $deactivatedAt = array_key_exists('deactivated_at', $overrides)
        ? $overrides['deactivated_at']
        : ($isActive ? null : '2026-11-01 09:00:00+00');

    return new MachineRestockSchedule(
        id: array_key_exists('id', $overrides) ? $overrides['id'] : 1,
        machineId: 8,
        cadenceType: array_key_exists('cadence_type', $overrides) ? $overrides['cadence_type'] : RestockCadenceType::DAILY,
        dayOfWeek: array_key_exists('day_of_week', $overrides) ? $overrides['day_of_week'] : null,
        anchorDate: array_key_exists('anchor_date', $overrides) ? $overrides['anchor_date'] : null,
        preferredTimeWindow: array_key_exists('preferred_time_window', $overrides) ? $overrides['preferred_time_window'] : RestockTimeWindow::ANY,
        includeWeekends: array_key_exists('include_weekends', $overrides) ? $overrides['include_weekends'] : false,
        notes: null,
        isActive: $isActive,
        createdBy: 3,
        updatedBy: 3,
        deactivatedBy: $isActive ? null : 3,
        createdAt: null,
        updatedAt: null,
        deactivatedAt: $deactivatedAt,
    );
}

echo "======================================================================\n";
echo " VENDGUARD · T-RRP-04 · Cadencias y validador del planificador de rutas\n";
echo "======================================================================\n";

try {
    // ─────────────────────────────────────────────────────────────────────
    // 1. DAILY × SlaTier (EARS 01.2, D6)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Calendario de cadencias ---\n";

    $daily = makeCadenceSchedule();
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::appliesOnDate($daily, '2026-11-20', SlaTier::STANDARD)
            && RestockCadenceEvaluator::appliesOnDate($daily, '2026-11-16', SlaTier::PRIORITY_CORPORATE)
            && !RestockCadenceEvaluator::appliesOnDate($daily, '2026-11-21', SlaTier::STANDARD)
            && !RestockCadenceEvaluator::appliesOnDate($daily, '2026-11-22', SlaTier::PRIORITY_CORPORATE),
        'DAILY cubre de lunes a viernes en STANDARD y PRIORITY_CORPORATE'
    );
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::appliesOnDate($daily, '2026-11-21', SlaTier::CRITICAL_INFRASTRUCTURE_24_7)
            && RestockCadenceEvaluator::appliesOnDate($daily, '2026-11-22', SlaTier::CRITICAL_INFRASTRUCTURE_24_7),
        'DAILY cubre sábado y domingo en CRITICAL_INFRASTRUCTURE_24_7'
    );
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::appliesOnDate(
            makeCadenceSchedule(['include_weekends' => true]),
            '2026-11-21',
            SlaTier::STANDARD
        ),
        'DAILY con include_weekends cubre el sábado en STANDARD'
    );
    assertRestockCadenceCondition(
        !RestockCadenceEvaluator::appliesOnDate(
            makeCadenceSchedule(['is_active' => false]),
            '2026-11-20',
            SlaTier::STANDARD
        ),
        'Una agenda inactiva no aplica nunca'
    );
    assertRestockCadenceCondition(
        !RestockCadenceEvaluator::appliesOnDate($daily, '2026-11-20', SlaTier::STANDARD, true),
        'La pausa estacional vigente anula el calendario'
    );

    $monWedFri = makeCadenceSchedule(['cadence_type' => RestockCadenceType::MON_WED_FRI]);
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::appliesOnDate($monWedFri, '2026-11-16', SlaTier::STANDARD)
            && RestockCadenceEvaluator::appliesOnDate($monWedFri, '2026-11-18', SlaTier::STANDARD)
            && RestockCadenceEvaluator::appliesOnDate($monWedFri, '2026-11-20', SlaTier::STANDARD)
            && !RestockCadenceEvaluator::appliesOnDate($monWedFri, '2026-11-17', SlaTier::STANDARD)
            && !RestockCadenceEvaluator::appliesOnDate($monWedFri, '2026-11-21', SlaTier::STANDARD),
        'MON_WED_FRI aplica solo lunes, miércoles y viernes'
    );
    $tueThu = makeCadenceSchedule(['cadence_type' => RestockCadenceType::TUE_THU]);
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::appliesOnDate($tueThu, '2026-11-17', SlaTier::STANDARD)
            && RestockCadenceEvaluator::appliesOnDate($tueThu, '2026-11-19', SlaTier::STANDARD)
            && !RestockCadenceEvaluator::appliesOnDate($tueThu, '2026-11-18', SlaTier::STANDARD)
            && !RestockCadenceEvaluator::appliesOnDate($tueThu, '2026-11-22', SlaTier::STANDARD),
        'TUE_THU aplica solo martes y jueves'
    );

    $weekly = makeCadenceSchedule([
        'cadence_type' => RestockCadenceType::WEEKLY,
        'day_of_week' => 3,
    ]);
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::appliesOnDate($weekly, '2026-11-18', SlaTier::STANDARD)
            && !RestockCadenceEvaluator::appliesOnDate($weekly, '2026-11-19', SlaTier::STANDARD)
            && RestockCadenceEvaluator::appliesOnDate($weekly, '2026-11-25', SlaTier::STANDARD),
        'WEEKLY exige el día ISO exacto y se repite cada siete días'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. BIWEEKLY DST-safe (EARS 01.2, RNF-02)
    // ─────────────────────────────────────────────────────────────────────
    $biweekly = makeCadenceSchedule([
        'cadence_type' => RestockCadenceType::BIWEEKLY,
        'anchor_date' => '2026-11-02',
    ]);
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::appliesOnDate($biweekly, '2026-11-02', SlaTier::STANDARD)
            && !RestockCadenceEvaluator::appliesOnDate($biweekly, '2026-11-09', SlaTier::STANDARD)
            && RestockCadenceEvaluator::appliesOnDate($biweekly, '2026-11-16', SlaTier::STANDARD)
            && RestockCadenceEvaluator::appliesOnDate($biweekly, '2026-11-30', SlaTier::STANDARD),
        'BIWEEKLY aplica el ancla y cada 14 días naturales'
    );
    assertRestockCadenceCondition(
        !RestockCadenceEvaluator::appliesOnDate($biweekly, '2026-11-01', SlaTier::STANDARD)
            && !RestockCadenceEvaluator::appliesOnDate($biweekly, '2026-10-26', SlaTier::STANDARD),
        'BIWEEKLY no aplica antes de su fecha ancla'
    );
    $autumnAnchor = makeCadenceSchedule([
        'cadence_type' => RestockCadenceType::BIWEEKLY,
        'anchor_date' => '2026-10-19',
    ]);
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::appliesOnDate($autumnAnchor, '2026-11-02', SlaTier::STANDARD)
            && RestockCadenceEvaluator::daysBetween('2026-10-19', '2026-11-02') === 14,
        'BIWEEKLY cruza el fin del horario de verano sin desfase'
    );
    $springAnchor = makeCadenceSchedule([
        'cadence_type' => RestockCadenceType::BIWEEKLY,
        'anchor_date' => '2026-03-16',
    ]);
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::appliesOnDate($springAnchor, '2026-03-30', SlaTier::STANDARD)
            && RestockCadenceEvaluator::daysBetween('2026-03-16', '2026-03-30') === 14,
        'BIWEEKLY cruza el inicio del horario de verano sin desfase'
    );
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::daysBetween('2026-11-02', '2026-11-01') === -1
            && RestockCadenceEvaluator::daysBetween('2026-11-02', '2026-11-02') === 0,
        'daysBetween() conserva el signo y el cero'
    );
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::today(new DateTimeImmutable('2026-11-21T23:30:00+00:00')) === '2026-11-22'
            && RestockCadenceEvaluator::today(new DateTimeImmutable('2026-07-01T22:30:00+00:00')) === '2026-07-02',
        'today() resuelve la fecha civil en Europe/Madrid con y sin horario de verano'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. nextScheduledDate (EARS 01.2, QA-6)
    // ─────────────────────────────────────────────────────────────────────
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::nextScheduledDate($daily, '2026-11-20', SlaTier::STANDARD) === '2026-11-23'
            && RestockCadenceEvaluator::nextScheduledDate($daily, '2026-11-20', SlaTier::CRITICAL_INFRASTRUCTURE_24_7)
                === '2026-11-21'
            && RestockCadenceEvaluator::nextScheduledDate(
                makeCadenceSchedule(['include_weekends' => true]),
                '2026-11-20',
                SlaTier::STANDARD
            ) === '2026-11-21',
        'nextScheduledDate() salta el fin de semana solo cuando el perfil lo permite'
    );
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::nextScheduledDate($weekly, '2026-11-18', SlaTier::STANDARD) === '2026-11-25'
            && RestockCadenceEvaluator::nextScheduledDate($biweekly, '2026-11-16', SlaTier::STANDARD) === '2026-11-30',
        'nextScheduledDate() es estrictamente posterior para WEEKLY y BIWEEKLY'
    );
    $futureAnchor = makeCadenceSchedule([
        'cadence_type' => RestockCadenceType::BIWEEKLY,
        'anchor_date' => '2026-12-28',
    ]);
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::nextScheduledDate($futureAnchor, '2026-11-21', SlaTier::STANDARD) === '2026-12-28'
            && RestockCadenceEvaluator::daysBetween('2026-11-21', '2026-12-28') === 37,
        'Un ancla futura a más de 15 días se devuelve tal cual'
    );
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::nextScheduledDate($daily, '2026-11-20', SlaTier::STANDARD, true) === null
            && RestockCadenceEvaluator::nextScheduledDate(
                makeCadenceSchedule(['is_active' => false]),
                '2026-11-20',
                SlaTier::STANDARD
            ) === null,
        'nextScheduledDate() es null con pausa estacional o sin agenda activa'
    );
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::nextScheduledDate($monWedFri, '2026-11-16', SlaTier::STANDARD) === '2026-11-18',
        'nextScheduledDate() respeta las cadencias fijas'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Pausa estacional (QA-6)
    // ─────────────────────────────────────────────────────────────────────
    assertRestockCadenceCondition(
        RestockCadenceEvaluator::isSeasonalPauseActive(true, null, '2026-11-21')
            && RestockCadenceEvaluator::isSeasonalPauseActive(true, '2026-11-21', '2026-11-21')
            && !RestockCadenceEvaluator::isSeasonalPauseActive(true, '2026-11-20', '2026-11-21')
            && !RestockCadenceEvaluator::isSeasonalPauseActive(false, null, '2026-11-21'),
        'La pausa estacional rige hasta su último día y es indefinida sin fecha de fin'
    );
    echo "  [OK]   DAILY × SlaTier, cadencias fijas, quincenal DST-safe y pausa estacional\n";

    // ─────────────────────────────────────────────────────────────────────
    // 5. SKIPPED (RF-02.2, Art. V.1, QA-4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Validador de excepciones diarias ---\n";

    $validSkipReason = 'La conserjería permanece cerrada con persiana metálica bajada y sin personal.';
    $normalized = RestockRoutePlannerValidator::assertSkipRequest(
        'coordinator',
        '2026-11-25',
        '2026-11-21',
        RestockSkipReasonCode::LOCATION_CLOSED,
        '  La conserjería   permanece cerrada con persiana metálica bajada y sin personal.  '
    );
    assertRestockCadenceCondition(
        $normalized === $validSkipReason,
        'SKIPPED normaliza espacios y admite una fecha futura de Coordinación'
    );
    assertRestockCadenceCondition(
        RestockRoutePlannerValidator::assertSkipRequest(
            'RESTOCKER',
            '2026-11-21',
            '2026-11-21',
            RestockSkipReasonCode::NO_KEY_OR_ACCESS,
            $validSkipReason
        ) === $validSkipReason,
        'El reponedor puede omitir una parada de hoy'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertSkipRequest(
            'RESTOCKER',
            '2026-11-22',
            '2026-11-21',
            RestockSkipReasonCode::NO_KEY_OR_ACCESS,
            $validSkipReason
        ),
        RestockRoutePlannerValidationException::SKIP_FUTURE_DATE_FORBIDDEN_FOR_RESTOCKER,
        422,
        'El reponedor no puede omitir una fecha futura'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertSkipRequest(
            'COORDINATOR',
            '2026-11-20',
            '2026-11-21',
            RestockSkipReasonCode::LOCATION_CLOSED,
            $validSkipReason
        ),
        RestockRoutePlannerValidationException::PAST_SERVICE_DATE_FORBIDDEN,
        422,
        'Nadie registra excepciones en el pasado'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertSkipRequest(
            'TECHNICIAN',
            '2026-11-21',
            '2026-11-21',
            RestockSkipReasonCode::LOCATION_CLOSED,
            $validSkipReason
        ),
        RestockRoutePlannerValidationException::FORBIDDEN,
        403,
        'Un rol ajeno no registra omisiones'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertSkipRequest(
            'COORDINATOR',
            '2026-11-21',
            '2026-11-21',
            null,
            $validSkipReason
        ),
        RestockRoutePlannerValidationException::SKIP_REASON_INVALID,
        422,
        'La omisión exige causa tipificada'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertSkipRequest(
            'COORDINATOR',
            '2026-11-21',
            '2026-11-21',
            RestockSkipReasonCode::LOCATION_CLOSED,
            str_repeat('x', 19)
        ),
        RestockRoutePlannerValidationException::SKIP_REASON_INVALID,
        422,
        'Un motivo de 19 caracteres se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertSkipRequest(
            'COORDINATOR',
            '2026-11-21',
            '2026-11-21',
            RestockSkipReasonCode::LOCATION_CLOSED,
            str_repeat('x', 501)
        ),
        RestockRoutePlannerValidationException::SKIP_REASON_INVALID,
        422,
        'Un motivo de 501 caracteres se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertSkipRequest(
            'COORDINATOR',
            '2026-11-21',
            '2026-11-21',
            RestockSkipReasonCode::LOCATION_CLOSED,
            'aaaaaaaaaa bbbbbbbbbb'
        ),
        RestockRoutePlannerValidationException::SKIP_REASON_INVALID,
        422,
        'Un motivo con solo dos bloques alfanuméricos se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertSkipRequest(
            'COORDINATOR',
            '2026-11-21',
            '2026-11-21',
            RestockSkipReasonCode::LOCATION_CLOSED,
            '------------------------------'
        ),
        RestockRoutePlannerValidationException::SKIP_REASON_INVALID,
        422,
        'Un motivo sin bloques alfanuméricos reales se rechaza'
    );
    assertRestockCadenceCondition(
        RestockRoutePlannerValidator::countAlphanumericBlocks('Cierre total, sin llaves ni portero.') === 6,
        'countAlphanumericBlocks() cuenta palabras reales separadas por puntuación'
    );
    assertRestockCadenceCondition(
        RestockRoutePlannerValidator::assertSkipRequest(
            'COORDINATOR',
            '2026-11-21',
            '2026-11-21',
            RestockSkipReasonCode::LOCATION_CLOSED,
            'abc def ghi jkl mnop'
        ) === 'abc def ghi jkl mnop',
        'Un motivo de exactamente 20 caracteres con tres bloques se admite'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. REASSIGNED (RF-02.3, QA-4)
    // ─────────────────────────────────────────────────────────────────────
    assertRestockCadenceCondition(
        RestockRoutePlannerValidator::assertReassignmentRequest(
            'COORDINATOR',
            '2026-11-21',
            '2026-11-21',
            5,
            true,
            12,
            RestockReassignmentReasonCode::ZONE_UNCOVERED,
            null
        ) === null,
        'Un desvío válido admite nota nula'
    );
    assertRestockCadenceCondition(
        RestockRoutePlannerValidator::assertReassignmentRequest(
            'COORDINATOR',
            '2026-11-28',
            '2026-11-21',
            5,
            true,
            null,
            RestockReassignmentReasonCode::FEFO_RESCUE,
            '  Rescate de perecederos   antes del cierre.  '
        ) === 'Rescate de perecederos antes del cierre.',
        'Un desvío futuro normaliza su nota opcional'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertReassignmentRequest(
            'RESTOCKER',
            '2026-11-21',
            '2026-11-21',
            5,
            true,
            null,
            RestockReassignmentReasonCode::ZONE_UNCOVERED
        ),
        RestockRoutePlannerValidationException::FORBIDDEN,
        403,
        'El desvío es exclusivo de Coordinación'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertReassignmentRequest(
            'COORDINATOR',
            '2026-11-20',
            '2026-11-21',
            5,
            true,
            null,
            RestockReassignmentReasonCode::ZONE_UNCOVERED
        ),
        RestockRoutePlannerValidationException::PAST_SERVICE_DATE_FORBIDDEN,
        422,
        'El desvío no admite fechas pasadas'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertReassignmentRequest(
            'COORDINATOR',
            '2026-11-21',
            '2026-11-21',
            5,
            false,
            null,
            RestockReassignmentReasonCode::ZONE_UNCOVERED
        ),
        RestockRoutePlannerValidationException::INVALID_TARGET_RESTOCKER,
        422,
        'El destino debe ser un reponedor activo'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertReassignmentRequest(
            'COORDINATOR',
            '2026-11-21',
            '2026-11-21',
            0,
            true,
            null,
            RestockReassignmentReasonCode::ZONE_UNCOVERED
        ),
        RestockRoutePlannerValidationException::INVALID_TARGET_RESTOCKER,
        422,
        'Un destino sin identificador se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertReassignmentRequest(
            'COORDINATOR',
            '2026-11-21',
            '2026-11-21',
            5,
            true,
            5,
            RestockReassignmentReasonCode::LOAD_BALANCING
        ),
        RestockRoutePlannerValidationException::SELF_REASSIGNMENT_FORBIDDEN,
        422,
        'El auto-desvío al titular efectivo se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertReassignmentRequest(
            'COORDINATOR',
            '2026-11-21',
            '2026-11-21',
            5,
            true,
            null,
            null
        ),
        RestockRoutePlannerValidationException::REASSIGNMENT_REASON_INVALID,
        422,
        'El desvío exige causa tipificada'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertReassignmentRequest(
            'COORDINATOR',
            '2026-11-21',
            '2026-11-21',
            5,
            true,
            null,
            RestockReassignmentReasonCode::OTHER_REASSIGNMENT,
            str_repeat('x', 501)
        ),
        RestockRoutePlannerValidationException::REASSIGNMENT_REASON_INVALID,
        422,
        'La nota del desvío no supera 500 caracteres'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Idempotencia offline (C3, RF-02.1)
    // ─────────────────────────────────────────────────────────────────────
    assertRestockCadenceCondition(
        RestockRoutePlannerValidator::normalizeMutationId('mut-skip-0901', true) === 'mut-skip-0901'
            && RestockRoutePlannerValidator::normalizeMutationId(null, false) === null
            && RestockRoutePlannerValidator::normalizeMutationId('   ', false) === null,
        'client_mutation_id admite null y valores de 8..64 con su patrón'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::normalizeMutationId('short', true),
        RestockRoutePlannerValidationException::INVALID_CLIENT_MUTATION_ID,
        422,
        'Un client_mutation_id corto se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::normalizeMutationId(str_repeat('a', 65), false),
        RestockRoutePlannerValidationException::INVALID_CLIENT_MUTATION_ID,
        422,
        'Un client_mutation_id de 65 caracteres se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::normalizeMutationId('mut skip 0901', false),
        RestockRoutePlannerValidationException::INVALID_CLIENT_MUTATION_ID,
        422,
        'Un client_mutation_id con espacios se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::normalizeMutationId(null, true),
        RestockRoutePlannerValidationException::INVALID_CLIENT_MUTATION_ID,
        422,
        'La mutación móvil exige client_mutation_id'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Coherencia de agenda (RF-01.1)
    // ─────────────────────────────────────────────────────────────────────
    RestockRoutePlannerValidator::assertScheduleCadence(RestockCadenceType::WEEKLY, 3, null, false);
    RestockRoutePlannerValidator::assertScheduleCadence(RestockCadenceType::BIWEEKLY, null, '2026-11-02', false);
    RestockRoutePlannerValidator::assertScheduleCadence(RestockCadenceType::DAILY, null, null, true);
    RestockRoutePlannerValidator::assertScheduleCadence(RestockCadenceType::MON_WED_FRI, null, null, false);
    assertRestockCadenceCondition(true, 'Las cuatro combinaciones válidas de cadencia se aceptan');

    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertScheduleCadence(
            RestockCadenceType::WEEKLY,
            null,
            null,
            false
        ),
        RestockRoutePlannerValidationException::SCHEDULE_CADENCE_FIELDS_INVALID,
        422,
        'WEEKLY sin día ISO se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertScheduleCadence(
            RestockCadenceType::WEEKLY,
            3,
            '2026-11-02',
            false
        ),
        RestockRoutePlannerValidationException::SCHEDULE_CADENCE_FIELDS_INVALID,
        422,
        'WEEKLY con ancla se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertScheduleCadence(
            RestockCadenceType::WEEKLY,
            0,
            null,
            false
        ),
        RestockRoutePlannerValidationException::SCHEDULE_CADENCE_FIELDS_INVALID,
        422,
        'Un día ISO 0 se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertScheduleCadence(
            RestockCadenceType::BIWEEKLY,
            null,
            null,
            false
        ),
        RestockRoutePlannerValidationException::SCHEDULE_CADENCE_FIELDS_INVALID,
        422,
        'BIWEEKLY sin ancla se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertScheduleCadence(
            RestockCadenceType::BIWEEKLY,
            2,
            '2026-11-02',
            false
        ),
        RestockRoutePlannerValidationException::SCHEDULE_CADENCE_FIELDS_INVALID,
        422,
        'BIWEEKLY con día ISO se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertScheduleCadence(
            RestockCadenceType::TUE_THU,
            null,
            '2026-11-02',
            false
        ),
        RestockRoutePlannerValidationException::SCHEDULE_CADENCE_FIELDS_INVALID,
        422,
        'Una cadencia fija con ancla se rechaza'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertScheduleCadence(
            RestockCadenceType::WEEKLY,
            3,
            null,
            true
        ),
        RestockRoutePlannerValidationException::SCHEDULE_CADENCE_FIELDS_INVALID,
        422,
        'include_weekends fuera de DAILY se rechaza'
    );
    assertRestockCadenceInvalidArgument(
        static fn() => RestockRoutePlannerValidator::assertScheduleCadence(
            RestockCadenceType::BIWEEKLY,
            null,
            '2026-02-30',
            false
        ),
        'Un ancla inexistente se rechaza como fecha civil'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 9. Conflictos del día (C1, QA-6) y mapa HTTP
    // ─────────────────────────────────────────────────────────────────────
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertStopNotCompletedToday(true, 8, '2026-11-21'),
        RestockRoutePlannerValidationException::RESTOCK_STOP_ALREADY_COMPLETED_TODAY,
        409,
        'Una parada ya visitada hoy no admite excepciones'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertStopNotBlocked(true, 8, ['cuarentena sanitaria']),
        RestockRoutePlannerValidationException::RESTOCK_STOP_BLOCKED_BY_SANITARY_OR_SAT,
        409,
        'Una parada bloqueada por SAT no admite omisión'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertOverrideActive(false, 31, 'REVOKED'),
        RestockRoutePlannerValidationException::OVERRIDE_NOT_ACTIVE,
        409,
        'Una excepción ya revocada no se revoca dos veces'
    );
    assertRestockPlannerRejection(
        static fn() => RestockRoutePlannerValidator::assertRestockerOwnsStop(false, 8, 12),
        RestockRoutePlannerValidationException::RESTOCK_STOP_NOT_IN_RESTOCKER_ROUTE,
        403,
        'Una parada ajena no se omite'
    );
    assertRestockCadenceCondition(
        (static function (): bool {
            try {
                RestockRoutePlannerValidator::assertStopNotCompletedToday(true, 8, '2026-11-21');
            } catch (RestockRoutePlannerValidationException $exception) {
                return $exception->getField() === 'machine_id'
                    && ($exception->getContext()['service_date'] ?? null) === '2026-11-21'
                    && $exception->getCode() === 409;
            }

            return false;
        })(),
        'La excepción transporta campo, contexto y código de dominio'
    );
    echo "  [OK]   SKIPPED, REASSIGNED, idempotencia offline y conflictos del día\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
}

echo "======================================================================\n";
echo " VENDGUARD · T-RRP-04 · Cadencias y validador del planificador de rutas\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE\n";
echo "======================================================================\n";
exit(0);
