<?php

declare(strict_types=1);

/**
 * CoordinatorIncidentDetailServiceTest
 *
 * Suite de pruebas unitarias de la tarea T-IDM-04 (módulo 09, modal de detalle
 * integral de incidencias en triaje). Valida la condición "Hecho cuando":
 * - Cálculo de SLA de frío activo (cuenta atrás) y cerrado (balance histórico), RF-03 / Art. II.
 * - Enmascaramiento exacto de datos de pago y contacto, RF-06 / Art. V.4.
 * - Visualización de piezas de catálogo y fuera de catálogo (pausa y sustitución), RF-04.
 * - Matriz de permisos operativos por estado de la máquina de estados, RF-07.
 * - Ensamblado íntegro de los diez bloques del DTO (plan §2.1) sin exponer datos completos.
 *
 * T-IDM-21 amplía la cobertura con el motivo de la reasignación técnica leído del
 * historial inmutable que escribe el repositorio (RF-07.3, Art. III.1).
 *
 * Dogma Vanilla: PHP 8.2 puro, sin dependencias externas y sin base de datos; el
 * repositorio se sustituye por un doble implementando su contrato de dominio.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\CoordinatorIncidentDetailDto;
use VendGuard\Application\Service\CoordinatorIncidentDetailService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;

/**
 * Doble en memoria del contrato de repositorio: sólo sirve el expediente enriquecido.
 */
final class CoordinatorIncidentDetailServiceStubRepo implements IncidentRepositoryInterface
{
    /** @var array<string, mixed>|null */
    public ?array $fixture = null;

    /** @var list<int|string> */
    public array $identifiers = [];

    public function findEnrichedDetailById(int|string $identifier): ?array
    {
        $this->identifiers[] = $identifier;

        return $this->fixture;
    }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { throw new LogicException('Not used.'); }
    public function findById(int $id): ?Incident { return null; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return []; }
    public function update(Incident $incident): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 1; }
    public function getHistory(int $incidentId): array { return []; }
    public function addComment(IncidentComment $comment): IncidentComment { throw new LogicException('Not used.'); }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
    public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function markAsChronic(int $incidentId): bool { return false; }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident { throw new LogicException('Not used.'); }
    public function reopen(int $incidentId, string $reasonText): Incident { throw new LogicException('Not used.'); }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { throw new LogicException('Not used.'); }
    public function startIntervention(int $incidentId, int $technicianId): Incident { throw new LogicException('Not used.'); }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { throw new LogicException('Not used.'); }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { throw new LogicException('Not used.'); }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
    public function recordPauseEvent(int $incidentId, int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $fromStatus, \VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory $category, string $reasonText, \DateTimeImmutable $pausedAt): void { throw new LogicException('Not used.'); }
    public function recordResumeEvent(int $incidentId, ?int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, \DateTimeImmutable $resumedAt): void { throw new LogicException('Not used.'); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { throw new LogicException('Not used.'); }
}

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - Servicio de Detalle de Incidencia (T-IDM-04)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
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

// =====================================================================
// ARNÉS: expediente de ejemplo (plan §2.1) con pausa, sustitución y reintegro
// =====================================================================
$fixture = [
    'incident' => [
        'id' => 142, 'ticket_code' => 'TICK-2026-00142', 'machine_id' => 10, 'location_id' => 1,
        'assigned_technician_id' => 4, 'status' => 'PENDING_PARTS', 'urgency' => 'CRITICAL',
        'description' => 'El compresor no arranca y los sándwiches superan los 9°C.',
        'photo_path' => '/uploads/evidence/evidence_142.jpg',
        'pending_parts_reason' => 'Fallo en condensador de arranque y relé térmico del compresor.',
        'resolution_diagnosis' => null, 'resolution_action' => null,
        'reopen_reason' => 'La máquina volvió a fallar 2 horas después de la reparación del técnico.',
        'reopened_at' => '2026-10-02 11:30:00', 'resolved_at' => null, 'closed_at' => null,
        'cancelled_at' => null, 'cancellation_reason' => null,
        'assigned_at' => '2026-10-01 08:30:00', 'started_at' => '2026-10-01 09:10:00',
        'machine_type_snapshot' => 'PERISHABLE_FOOD',
        'created_at' => '2026-10-01 08:15:00', 'updated_at' => '2026-10-02 12:00:00',
    ],
    'machine' => ['id' => 10, 'location_id' => 1, 'code' => 'VEND-0101', 'model' => 'FAS Perla Fast Cold', 'machine_type' => 'PERISHABLE_FOOD', 'floor_wing' => 'Planta Baja - Urgencias', 'sanitary_status' => 'OK', 'notes' => null, 'is_active' => 1],
    'location' => ['id' => 1, 'site_code' => 'SEDE-BCN-01', 'name' => 'Hospital del Mar', 'address' => 'Passeig Marítim 25-29, Barcelona', 'has_physical_reception' => 1, 'latitude' => '41.3850640', 'longitude' => '2.1734035'],
    'technician' => ['id' => 4, 'name' => 'Jordi Cruz', 'operator_code' => 'OP-BCN-04', 'role' => 'TECHNICIAN', 'is_active' => 1],
    'history' => [
        ['id' => 1, 'user_id' => null, 'from_status' => null, 'to_status' => 'REGISTERED', 'action_note' => 'Aviso registrado mediante código QR', 'created_at' => '2026-10-01 08:15:00', 'user_name' => null, 'user_role' => null],
        ['id' => 2, 'user_id' => 2, 'from_status' => 'REGISTERED', 'to_status' => 'ASSIGNED', 'action_note' => 'Incidencia asignada al técnico ID 4.', 'created_at' => '2026-10-01 08:30:00', 'user_name' => 'Coordinación Central', 'user_role' => 'COORDINATOR'],
        ['id' => 3, 'user_id' => 4, 'from_status' => 'ASSIGNED', 'to_status' => 'PENDING_PARTS', 'action_note' => 'Pausa técnica por repuestos pendientes.', 'created_at' => '2026-10-01 09:45:00', 'user_name' => 'Jordi Cruz', 'user_role' => 'TECHNICIAN'],
    ],
    'comments' => [
        ['id' => 85, 'author_type' => 'REPORTER', 'user_id' => null, 'author_name' => 'Conserjería Hospital', 'comment_text' => 'El agua gotea por debajo de la máquina.', 'photo_path' => null, 'is_internal' => '0', 'created_at' => '2026-10-01 08:20:00'],
        ['id' => 86, 'author_type' => 'TECHNICIAN', 'user_id' => 4, 'author_name' => 'Jordi Cruz', 'comment_text' => 'Comprobada fuga en bandeja de desescarche.', 'photo_path' => null, 'is_internal' => '1', 'created_at' => '2026-10-01 09:46:00'],
    ],
    'requested_parts' => [
        ['id' => 1, 'spare_part_id' => 12, 'is_out_of_catalog' => '0', 'custom_part_description' => null, 'quantity' => '1', 'status' => 'PENDING', 'created_at' => '2026-10-01 09:45:00', 'part_code' => 'SP-FAS-RELAY-01', 'part_name' => 'Relé Térmico Compresor 230V'],
        ['id' => 2, 'spare_part_id' => null, 'is_out_of_catalog' => '1', 'custom_part_description' => 'Abrazadera reforzada antivibración para circuito de cobre', 'quantity' => '1', 'status' => 'PENDING', 'created_at' => '2026-10-01 09:45:00', 'part_code' => null, 'part_name' => null],
    ],
    'replaced_parts' => [
        ['id' => 9, 'spare_part_id' => 12, 'is_out_of_catalog' => '0', 'custom_part_name' => null, 'quantity' => '1', 'unit_cost_snapshot' => '28.50', 'total_cost_snapshot' => '28.50', 'old_part_destination' => 'DESGUACE', 'notes' => 'Enviada a desguace según protocolo.', 'installed_at' => '2026-10-01 09:40:00', 'created_at' => '2026-10-01 09:40:00', 'part_code' => 'SP-FAS-RELAY-01', 'part_name' => 'Relé Térmico Compresor 230V'],
    ],
    'refund' => ['id' => 5, 'incident_id' => 142, 'claimed_amount' => '2.50', 'compensation_method' => 'BIZUM', 'bizum_phone' => '612345789', 'iban' => null, 'status' => 'REQUIRES_COORDINATOR_APPROVAL', 'technician_finding' => 'FOUND_PHYSICAL', 'cash_custody_action' => 'HELD_FOR_CENTRAL', 'technician_justification' => 'Moneda de 2€ y 0.50€ retenidas en selector.', 'created_at' => '2026-10-01 08:20:00'],
];

$repo = new CoordinatorIncidentDetailServiceStubRepo();
$service = new CoordinatorIncidentDetailService($repo);
$at1115 = new DateTimeImmutable('2026-10-01 11:15:00');
$perishableMachine = $fixture['machine'];
$timeline = ['created_at' => '2026-10-01 08:15:00', 'resolved_at' => null];

// =====================================================================
// GRUPO 1: SLA de frío activo y cerrado (RF-03 / Art. II)
// =====================================================================
echo "--- Grupo 1: SLA de frío activo y balance histórico ---\n";

$slaActive = $service->computeSlaStatus($fixture['incident'], $perishableMachine, $timeline, $at1115);
$assert('1.1 Activo dentro de plazo: 60 min restantes y texto "Tiempo restante: 1 h"', $slaActive['minutes_remaining'] === 60 && $slaActive['historical_balance'] === 'Tiempo restante: 1 h', json_encode($slaActive));
$assert('1.2 Activo dentro de plazo: cuenta atrás, sin incumplimiento y límite de 4 h', $slaActive['has_sla_limit'] === true && $slaActive['is_active_countdown'] === true && $slaActive['is_breached'] === false && $slaActive['sla_limit_hours'] === 4.0);
$assert('1.3 Objetivo SLA calculado a 4 h de la creación (12:15:00)', $slaActive['sla_target_at'] === '2026-10-01 12:15:00');

$slaAtTarget = $service->computeSlaStatus($fixture['incident'], $perishableMachine, $timeline, new DateTimeImmutable('2026-10-01 12:15:00'));
$assert('1.4 Justo en el objetivo: 0 min y sin incumplimiento', $slaAtTarget['minutes_remaining'] === 0 && $slaAtTarget['is_breached'] === false && $slaAtTarget['historical_balance'] === 'Tiempo restante: 0 min');

$slaBreached = $service->computeSlaStatus($fixture['incident'], $perishableMachine, $timeline, new DateTimeImmutable('2026-10-01 13:20:00'));
$assert('1.5 Activo superado: -65 min y alerta "SLA superado hace 1 h 5 min"', $slaBreached['minutes_remaining'] === -65 && $slaBreached['is_breached'] === true && $slaBreached['historical_balance'] === 'SLA superado hace 1 h 5 min', json_encode($slaBreached));

$slaNoLimit = $service->computeSlaStatus($fixture['incident'], array_merge($perishableMachine, ['machine_type' => 'HOT_DRINKS']), $timeline, $at1115);
$assert('1.6 Máquina no perecedera: exactamente {has_sla_limit: false}', $slaNoLimit === ['has_sla_limit' => false]);

$slaNoDates = $service->computeSlaStatus(array_merge($fixture['incident'], ['created_at' => '']), $perishableMachine, ['created_at' => ''], $at1115);
$assert('1.7 Sin fecha de creación: sin SLA computable', $slaNoDates === ['has_sla_limit' => false]);

$resolvedIncident = array_merge($fixture['incident'], ['status' => 'RESOLVED', 'resolved_at' => '2026-10-01 10:30:00']);
$slaDone = $service->computeSlaStatus($resolvedIncident, $perishableMachine, ['created_at' => '2026-10-01 08:15:00', 'resolved_at' => '2026-10-01 10:30:00'], $at1115);
$assert('1.8 Resuelto en plazo: "Cumplido en 2 h 15 min" sin cuenta atrás', $slaDone['is_active_countdown'] === false && $slaDone['is_breached'] === false && $slaDone['historical_balance'] === 'Cumplido en 2 h 15 min' && $slaDone['minutes_remaining'] === 0, json_encode($slaDone));

$closedIncident = array_merge($fixture['incident'], ['status' => 'CLOSED', 'resolved_at' => '2026-10-01 12:50:00', 'closed_at' => '2026-10-01 14:00:00']);
$slaClosed = $service->computeSlaStatus($closedIncident, $perishableMachine, ['created_at' => '2026-10-01 08:15:00', 'resolved_at' => '2026-10-01 12:50:00'], $at1115);
$assert('1.9 Cerrado fuera de plazo: "Incumplido por 35 min"', $slaClosed['is_breached'] === true && $slaClosed['historical_balance'] === 'Incumplido por 35 min', json_encode($slaClosed));

$closedWithoutResolution = array_merge($fixture['incident'], ['status' => 'CLOSED', 'resolved_at' => null, 'closed_at' => '2026-10-01 12:50:00']);
$slaClosedFallback = $service->computeSlaStatus($closedWithoutResolution, $perishableMachine, ['created_at' => '2026-10-01 08:15:00', 'resolved_at' => null], $at1115);
$assert('1.10 Cerrado sin resolved_at: usa closed_at como cierre efectivo', $slaClosedFallback['historical_balance'] === 'Incumplido por 35 min');

$cancelledIncident = array_merge($fixture['incident'], ['status' => 'CANCELLED', 'cancelled_at' => '2026-10-01 11:00:00', 'updated_at' => '2026-10-01 11:00:00']);
$slaCancelled = $service->computeSlaStatus($cancelledIncident, $perishableMachine, $timeline, $at1115);
$assert('1.11 Cancelado en plazo: "Cumplido en 2 h 45 min"', $slaCancelled['historical_balance'] === 'Cumplido en 2 h 45 min' && $slaCancelled['is_breached'] === false, json_encode($slaCancelled));

$resolvedExactly = array_merge($fixture['incident'], ['status' => 'RESOLVED', 'resolved_at' => '2026-10-01 12:15:00']);
$slaExactly = $service->computeSlaStatus($resolvedExactly, $perishableMachine, ['created_at' => '2026-10-01 08:15:00', 'resolved_at' => '2026-10-01 12:15:00'], $at1115);
$assert('1.12 Formato sin minutos: cierre exacto en 4 h → "Cumplido en 4 h"', $slaExactly['historical_balance'] === 'Cumplido en 4 h');

// =====================================================================
// GRUPO 2: Enmascaramiento de datos de pago y contacto (RF-06 / Art. V.4)
// =====================================================================
echo "\n--- Grupo 2: Enmascaramiento de datos de pago y contacto ---\n";

$masked = $service->maskFinancialAndContactData($fixture['refund']);
$assert('2.1 Teléfono Bizum enmascarado exactamente como 6** *** 789', $masked['contact_phone_masked'] === '6** *** 789', json_encode($masked));
$assert('2.2 Código de reclamación derivado como REF-2026-00005', $masked['claim_code'] === 'REF-2026-00005');
$assert('2.3 Importe reclamado como float, método y estado presentes', $masked['amount'] === 2.5 && $masked['compensation_method'] === 'BIZUM' && $masked['status'] === 'REQUIRES_COORDINATOR_APPROVAL');
$assert('2.4 Hallazgo del técnico y custodia del efectivo presentes', $masked['technician_finding'] === 'FOUND_PHYSICAL' && $masked['cash_custody_action'] === 'HELD_FOR_CENTRAL');

$maskedIban = $service->maskFinancialAndContactData(['id' => 9, 'claimed_amount' => '10.00', 'compensation_method' => 'TRANSFERENCIA_BANCARIA', 'bizum_phone' => null, 'iban' => 'ES91 2100 0418 4502 0005 1332', 'status' => 'VERIFIED_PENDING_PAYMENT', 'created_at' => '2026-01-05 09:00:00']);
$assert('2.5 IBAN enmascarado conservando país y últimos cuatro: ES** **** **** **** **13 32', $maskedIban['iban_masked'] === 'ES** **** **** **** **13 32', json_encode($maskedIban));
$assert('2.6 Teléfono nulo: la máscara queda a null', $maskedIban['contact_phone_masked'] === null);

$assert('2.7 Expediente de reintegro inexistente: null', $service->maskFinancialAndContactData(null) === null);

$shortData = $service->maskFinancialAndContactData(['id' => 1, 'bizum_phone' => '123', 'iban' => 'ES12']);
$assert('2.8 Valores demasiado cortos: máscara total', $shortData['contact_phone_masked'] === '*** *** ***' && $shortData['iban_masked'] === '** **** **** **** ****');

$spacedPhone = $service->maskFinancialAndContactData(['id' => 2, 'bizum_phone' => '612 345 789']);
$assert('2.9 Teléfono con espacios: se recorta y conserva primer dígito y tres últimos', $spacedPhone['contact_phone_masked'] === '6** *** 789');

$noId = $service->maskFinancialAndContactData(['id' => 0, 'created_at' => '2026-01-01 00:00:00']);
$assert('2.10 Sin identificador de expediente no hay código de reclamación', $noId['claim_code'] === null);

// =====================================================================
// GRUPO 3: Matriz de permisos por estado (RF-07)
// =====================================================================
echo "\n--- Grupo 3: Matriz de permisos por estado ---\n";

$permissionsOf = function (string $status, ?string $resolvedAt = null) use ($service, $at1115): array {
    return $service->computePermissions(
        ['status' => $status, 'resolved_at' => $resolvedAt, 'updated_at' => $resolvedAt],
        $at1115
    );
};

$assert('3.1 REGISTERED (Reported en la especificación): asignar, descartar y comentar', $permissionsOf('REGISTERED') === ['can_assign' => true, 'can_reassign' => false, 'can_cancel' => true, 'can_add_comment' => true]);
$assert('3.2 REOPENED: vuelve a admitir asignación directa', $permissionsOf('REOPENED') === ['can_assign' => true, 'can_reassign' => false, 'can_cancel' => true, 'can_add_comment' => true]);
$assert('3.3 ASSIGNED: reasignar, descartar y comentar', $permissionsOf('ASSIGNED') === ['can_assign' => false, 'can_reassign' => true, 'can_cancel' => true, 'can_add_comment' => true]);
$assert('3.4 IN_PROGRESS: reasignar, descartar y comentar', $permissionsOf('IN_PROGRESS') === ['can_assign' => false, 'can_reassign' => true, 'can_cancel' => true, 'can_add_comment' => true]);
$assert('3.5 PENDING_PARTS: reasignar, descartar y comentar', $permissionsOf('PENDING_PARTS') === ['can_assign' => false, 'can_reassign' => true, 'can_cancel' => true, 'can_add_comment' => true]);
$assert('3.6 RESOLVED dentro de la ventana de 48 h: solo comentar', $permissionsOf('RESOLVED', '2026-10-01 10:00:00') === ['can_assign' => false, 'can_reassign' => false, 'can_cancel' => false, 'can_add_comment' => true]);
$assert('3.7 RESOLVED justo en el límite de 48 h (ventana inclusiva): aún admite comentarios', $permissionsOf('RESOLVED', '2026-09-29 11:15:00')['can_add_comment'] === true);
$assert('3.8 RESOLVED un segundo después del límite: sellado', $permissionsOf('RESOLVED', '2026-09-29 11:14:59')['can_add_comment'] === false);
$assert('3.9 CLOSED: consulta y auditoría sin acciones', $permissionsOf('CLOSED') === ['can_assign' => false, 'can_reassign' => false, 'can_cancel' => false, 'can_add_comment' => false]);
$assert('3.10 CANCELLED: consulta y auditoría sin acciones', $permissionsOf('CANCELLED') === ['can_assign' => false, 'can_reassign' => false, 'can_cancel' => false, 'can_add_comment' => false]);
$assert('3.11 Estado desconocido: sin ninguna acción', $permissionsOf('UNKNOWN') === ['can_assign' => false, 'can_reassign' => false, 'can_cancel' => false, 'can_add_comment' => false]);

// =====================================================================
// GRUPO 4: Piezas de catálogo, fuera de catálogo y sustitución (RF-04)
// =====================================================================
echo "\n--- Grupo 4: Piezas de pausa y sustitución declarada ---\n";

$repo->fixture = $fixture;
$blocks = $service->buildDetail(142, $at1115)->toArray();
$requestedParts = $blocks['technical_intervention']['pause']['requested_parts'];

$assert('4.1 Pieza de catálogo: referencia, descripción y unidades de la pausa', $requestedParts[0]['spare_part_id'] === 12 && $requestedParts[0]['part_code'] === 'SP-FAS-RELAY-01' && $requestedParts[0]['description'] === 'Relé Térmico Compresor 230V' && $requestedParts[0]['quantity'] === 1 && $requestedParts[0]['is_out_of_catalog'] === false && $requestedParts[0]['justification'] === null);
$assert('4.2 Pieza fuera de catálogo: descripción detallada y justificación obligatoria', $requestedParts[1]['spare_part_id'] === null && $requestedParts[1]['part_code'] === 'OUT_OF_CATALOG' && $requestedParts[1]['description'] === 'Abrazadera reforzada antivibración para circuito de cobre' && $requestedParts[1]['is_out_of_catalog'] === true && $requestedParts[1]['justification'] === 'Abrazadera reforzada antivibración para circuito de cobre');
$assert('4.3 Pausa en curso con motivo registrado', $blocks['technical_intervention']['pause']['is_paused'] === true && $blocks['technical_intervention']['pause']['reason'] === 'Fallo en condensador de arranque y relé térmico del compresor.');

$replaced = $blocks['technical_intervention']['resolution']['replaced_parts'][0];
$assert('4.4 Sustitución con coste unitario congelado y destino reglamentario', $replaced['unit_cost_snapshot'] === 28.5 && $replaced['total_cost_snapshot'] === 28.5 && $replaced['old_part_destination'] === 'DESGUACE' && $replaced['destination_label'] === 'Desguace / Reciclaje' && $replaced['notes'] === 'Enviada a desguace según protocolo.');
$assert('4.5 Declaración de sustitución y coste total acumulado', $blocks['technical_intervention']['resolution']['replaced_parts_declared'] === true && $blocks['technical_intervention']['resolution']['total_parts_cost'] === 28.5);

$noPartsFixture = $fixture;
$noPartsFixture['incident']['status'] = 'RESOLVED';
$noPartsFixture['incident']['resolution_diagnosis'] = 'Obstrucción por calcificación sin rotura mecánica.';
$noPartsFixture['incident']['resolution_action'] = 'Descalcificación manual in situ y purga del circuito.';
$noPartsFixture['requested_parts'] = [];
$noPartsFixture['replaced_parts'] = [];
$repo->fixture = $noPartsFixture;
$noPartsBlocks = $service->buildDetail(142, $at1115)->toArray();
$assert('4.6 Intervención sin material: listas vacías, sin declaración y coste 0.0', $noPartsBlocks['technical_intervention']['pause']['requested_parts'] === [] && $noPartsBlocks['technical_intervention']['resolution']['replaced_parts'] === [] && $noPartsBlocks['technical_intervention']['resolution']['replaced_parts_declared'] === false && $noPartsBlocks['technical_intervention']['resolution']['total_parts_cost'] === 0.0);
$assert('4.7 Resolución documentada con diagnóstico y acción correctiva', $noPartsBlocks['technical_intervention']['resolution']['is_resolved'] === true && $noPartsBlocks['technical_intervention']['resolution']['diagnosis'] === 'Obstrucción por calcificación sin rotura mecánica.' && $noPartsBlocks['technical_intervention']['resolution']['corrective_action'] === 'Descalcificación manual in situ y purga del circuito.');

// =====================================================================
// GRUPO 5: Ensamblado del DTO y privacidad del payload (plan §2.1)
// =====================================================================
echo "\n--- Grupo 5: Ensamblado del DTO y privacidad del payload ---\n";

$repo->fixture = $fixture;
$repo->identifiers = [];
$dto = $service->buildDetail(142, $at1115);

$assert('5.1 buildDetail devuelve el DTO con los diez bloques en orden contractual', $dto instanceof CoordinatorIncidentDetailDto && array_keys($dto->toArray()) === ['incident', 'location', 'machine', 'technician', 'timeline', 'sla', 'technical_intervention', 'comments', 'refund', 'permissions']);
$assert('5.2 El identificador recibido se delega al repositorio', $repo->identifiers === [142]);

$blocks = $dto->toArray();
$assert('5.3 Cabecera: etiquetas semánticas, reapertura y evidencia original', $blocks['incident']['status_label'] === 'Pendiente de repuestos' && $blocks['incident']['urgency_label'] === 'Crítica (Riesgo Alimentario)' && $blocks['incident']['is_reopened'] === true && $blocks['incident']['reopened_at'] === '2026-10-02 11:30:00' && $blocks['incident']['photo_url'] === '/uploads/evidence/evidence_142.jpg');
$assert('5.4 Canal de reporte ciudadano derivado del evento de creación', $blocks['incident']['report_channel'] === 'QR_CODE');

$portalFixture = $fixture;
$portalFixture['history'][0]['action_note'] = 'Aviso registrado desde el portal de sede';
$repo->fixture = $portalFixture;
$portalBlocks = $service->buildDetail(142, $at1115)->toArray();
$assert('5.5 Canal de reporte del portal de sede derivado del evento de creación', $portalBlocks['incident']['report_channel'] === 'LOCATION_PORTAL');

$assert('5.6 Sede: zona física desde floor_wing y recepción física', $blocks['location']['floor_zone'] === 'Planta Baja - Urgencias' && $blocks['location']['has_physical_reception'] === true && $blocks['location']['code'] === 'SEDE-BCN-01');
$assert('5.7 Máquina: tipología perecedera y fabricante ausente en el esquema', $blocks['machine']['type'] === 'PERISHABLE_FOOD' && $blocks['machine']['type_label'] === 'Alimentos perecederos (Sándwiches y lácteos frescos)' && $blocks['machine']['has_perishables'] === true && $blocks['machine']['manufacturer'] === null);
$assert('5.8 Técnico: operador oficial y actor de la asignación desde auditoría', $blocks['technician']['assigned'] === true && $blocks['technician']['name'] === 'Jordi Cruz' && $blocks['technician']['operator_code'] === 'OP-BCN-04' && $blocks['technician']['assigned_by_name'] === 'Coordinación Central');
$assert('5.9 Cronograma: hito de pausa desde historial y tiempos derivados', $blocks['timeline']['paused_at'] === '2026-10-01 09:45:00' && $blocks['timeline']['time_to_assign_minutes'] === 15 && $blocks['timeline']['time_to_first_response_minutes'] === 55 && $blocks['timeline']['total_elapsed_minutes'] === 180);
$assert('5.10 SLA embebido en su bloque', $blocks['sla']['minutes_remaining'] === 60 && $blocks['sla']['is_active_countdown'] === true && $blocks['sla']['sla_limit_hours'] === 4.0);
$assert('5.11 Bitácora: comentario público y nota interna diferenciados', count($blocks['comments']) === 2 && $blocks['comments'][0]['author_type'] === 'REPORTER' && $blocks['comments'][0]['is_internal'] === false && $blocks['comments'][1]['author_type'] === 'TECHNICIAN' && $blocks['comments'][1]['is_internal'] === true);
$assert('5.12 Reintegro vinculado: máscaras, etiquetas y enlace a la bandeja', $blocks['refund']['has_refund'] === true && $blocks['refund']['refund_id'] === 5 && $blocks['refund']['claim_code'] === 'REF-2026-00005' && $blocks['refund']['amount'] === 2.5 && $blocks['refund']['compensation_method_label'] === 'Bizum' && $blocks['refund']['status_label'] === 'Pendiente de aprobación de Coordinación' && $blocks['refund']['technician_notes'] === 'Moneda de 2€ y 0.50€ retenidas en selector.' && $blocks['refund']['refund_tab_url'] === '#refunds?id=5');
$assert('5.13 Permisos del estado en pausa embebidos en el DTO', $blocks['permissions'] === ['can_assign' => false, 'can_reassign' => true, 'can_cancel' => true, 'can_add_comment' => true]);

$payloadJson = (string)json_encode($dto->toArray());
$assert('5.14 El payload no expone el teléfono completo ni el IBAN (Art. V.4)', !str_contains($payloadJson, '612345789') && str_contains($payloadJson, '6** *** 789') && !str_contains($payloadJson, '"iban":'));

$noRefundFixture = $fixture;
$noRefundFixture['refund'] = null;
$repo->fixture = $noRefundFixture;
$noRefundBlock = $service->buildDetail(142, $at1115)->toArray()['refund'];
$assert('5.15 Sin reintegro: bloque limpio con has_refund false y resto a null', $noRefundBlock['has_refund'] === false && $noRefundBlock['refund_id'] === null && $noRefundBlock['claim_code'] === null && $noRefundBlock['contact_phone_masked'] === null && $noRefundBlock['refund_tab_url'] === null);

$repo->fixture = null;
$assert('5.16 Expediente inexistente: buildDetail devuelve null', $service->buildDetail('#TICK-2099-00001', $at1115) === null);
$assert('5.17 Los identificadores no encontrados también se delegan al repositorio', $repo->identifiers === [142, 142, 142, '#TICK-2099-00001']);

// =====================================================================
// GRUPO 6: Motivo de reasignación desde el historial inmutable (T-IDM-21 / RF-07.3)
// =====================================================================
echo "\n--- Grupo 6: Motivo de reasignación en el bloque de técnico ---\n";

$reassignedFixture = array_merge($fixture, [
    'incident' => array_merge($fixture['incident'], ['assigned_technician_id' => 9]),
    'technician' => ['id' => 9, 'name' => 'Marta Ruta', 'operator_code' => 'OP-BCN-09', 'role' => 'TECHNICIAN', 'is_active' => 1],
    'history' => array_merge($fixture['history'], [[
        'id' => 9, 'user_id' => 2, 'from_status' => 'PENDING_PARTS', 'to_status' => 'PENDING_PARTS',
        'action_note' => 'Reasignación técnica: del técnico ID 4 al técnico ID 9. Motivo: Proximidad geográfica al centro sanitario con riesgo de frío.',
        'created_at' => '2026-10-01 10:30:00', 'user_name' => 'Coordinación Central', 'user_role' => 'COORDINATOR',
    ]]),
]);
$repo->fixture = $reassignedFixture;
$reassignmentBlocks = $service->buildDetail(142, $at1115)->toArray();

$assert(
    '6.1 El motivo de reasignación se expone desde la nota inmutable del repositorio',
    $reassignmentBlocks['technician']['reassignment_reason'] === 'Proximidad geográfica al centro sanitario con riesgo de frío.',
    json_encode($reassignmentBlocks['technician'])
);
$assert(
    '6.2 El bloque apunta al nuevo responsable activo (Art. V.3)',
    $reassignmentBlocks['technician']['technician_id'] === 9
        && $reassignmentBlocks['technician']['name'] === 'Marta Ruta'
        && $reassignmentBlocks['technician']['operator_code'] === 'OP-BCN-09'
);

// La nota puede intercalar la reclasificación de urgencia: el motivo justificado cierra el texto.
$urgencyReassignmentFixture = array_merge($reassignedFixture, [
    'history' => array_merge($reassignedFixture['history'], [[
        'id' => 10, 'user_id' => 2, 'from_status' => 'PENDING_PARTS', 'to_status' => 'PENDING_PARTS',
        'action_note' => 'Reasignación técnica: del técnico ID 9 al técnico ID 4. Reclasificación de urgencia de MEDIUM a CRITICAL. Motivo: Rotura confirmada de la cadena de frío en la sala de urgencias.',
        'created_at' => '2026-10-01 11:05:00', 'user_name' => 'Coordinación Central', 'user_role' => 'COORDINATOR',
    ]]),
]);
$repo->fixture = $urgencyReassignmentFixture;
$latestReassignment = $service->buildDetail(142, $at1115)->toArray()['technician']['reassignment_reason'];

$assert(
    '6.3 Reclasificación intercalada: el motivo se aísla sin arrastrar el texto de la urgencia',
    $latestReassignment === 'Rotura confirmada de la cadena de frío en la sala de urgencias.',
    (string)$latestReassignment
);
$assert('6.4 La última reasignación prevalece sobre las anteriores', $latestReassignment !== 'Proximidad geográfica al centro sanitario con riesgo de frío.');

$repo->fixture = $fixture;
$assert(
    '6.5 Sin reasignaciones el contrato mantiene el campo a null',
    $service->buildDetail(142, $at1115)->toArray()['technician']['reassignment_reason'] === null
);

// =====================================================================
// GRUPO 7: Pausa contractual de la espera de sede (T-PAUSE-19 / RF-03.2, RF-04.2)
// =====================================================================
echo "\n--- Grupo 7: Pausa contractual, reloj congelado y espera prolongada ---\n";

/**
 * Expediente real en pausa por bloqueo de sede: la avería nació el jueves 1 a las
 * 08:15 (objetivo contractual a las 12:15) y el técnico encontró la puerta cerrada el
 * jueves 8 a las 09:00, así que el reloj de frío debe quedarse ahí.
 */
$sitePauseIncident = array_merge($fixture['incident'], [
    'status' => 'PENDING_INFO',
    'paused_at' => '2026-10-01 09:00:00',
    'total_pending_info_seconds' => 5400,
    'pending_info_reason_category' => 'BUILDING_CLOSED_NO_ACCESS',
    'pending_info_reason_text' => 'El conserje confirma que el centro permanece cerrado por obras toda la semana.',
]);

$sitePauseRepo = new CoordinatorIncidentDetailServiceStubRepo();
$sitePauseRepo->fixture = array_merge($fixture, ['incident' => $sitePauseIncident]);
$sitePauseService = new CoordinatorIncidentDetailService($sitePauseRepo);
$pauseNow = new DateTimeImmutable('2026-10-01 11:00:00');
$sitePauseDetail = $sitePauseService->buildDetail(142, $pauseNow)->toArray();
$sitePauseBlock = $sitePauseDetail['technical_intervention']['pause'];

$assert(
    '7.1 El bloque de pausa publica marca, causa tipificada, justificación y los minutos descontados',
    $sitePauseBlock['is_paused'] === true
        && $sitePauseBlock['is_sla_paused'] === true
        && $sitePauseBlock['paused_at'] === '2026-10-01 09:00:00'
        && $sitePauseBlock['paused_minutes'] === 120
        && $sitePauseBlock['accumulated_pause_minutes'] === 90
        && $sitePauseBlock['reason_category'] === 'BUILDING_CLOSED_NO_ACCESS'
        && $sitePauseBlock['reason_category_label'] === 'Edificio cerrado / Sin acceso a instalaciones'
        && $sitePauseBlock['reason_text'] === 'El conserje confirma que el centro permanece cerrado por obras toda la semana.',
    json_encode($sitePauseBlock)
);

$assert(
    '7.2 Reloj contractual congelado: la cuenta atrás se detiene en el instante de la pausa (RF-03.2)',
    $sitePauseDetail['sla']['is_frozen'] === true
        && $sitePauseDetail['sla']['frozen_at'] === '2026-10-01 09:00:00'
        && $sitePauseDetail['sla']['minutes_remaining'] === 195
        && $sitePauseDetail['sla']['historical_balance'] === 'Tiempo restante: 3 h 15 min'
        && $sitePauseDetail['sla']['is_breached'] === false,
    json_encode($sitePauseDetail['sla'])
);

// La prueba del algodón del doble reloj: el mismo expediente sin pausa consume 75 min a la
// misma hora, así que el congelado (195 min) no es un eco del reloj de pared.
$liveSla = $sitePauseService->computeSlaStatus(
    array_merge($sitePauseIncident, ['status' => 'IN_PROGRESS', 'paused_at' => null]),
    $fixture['machine'],
    ['created_at' => '2026-10-01 08:15:00', 'resolved_at' => null],
    $pauseNow
);
$assert(
    '7.2.b Sin pausa la misma avería consume 75 min: el congelado no es un eco del reloj de pared',
    $liveSla['is_frozen'] === false && $liveSla['minutes_remaining'] === 75,
    json_encode($liveSla)
);

$assert(
    '7.3 El vencimiento congelado es el vigente al pausar y el recalculado suma la espera en horario hábil',
    $sitePauseBlock['sla_target_frozen_at'] === '2026-10-01 12:15:00'
        && $sitePauseBlock['sla_target_recalculated_at'] === '2026-10-01 15:45:00',
    json_encode([$sitePauseBlock['sla_target_frozen_at'], $sitePauseBlock['sla_target_recalculated_at']])
);

$assert(
    '7.4 Las dos acciones supervisadas se habilitan con la pausa viva (RF-04.3, RF-06.2)',
    $sitePauseBlock['can_resume_pause'] === true
        && $sitePauseBlock['can_cancel_inactivity'] === true
        && $sitePauseBlock['inactivity_threshold_business_hours'] === \VendGuard\Application\Service\IncidentPauseService::PROLONGED_INACTIVITY_BUSINESS_HOURS
);

// Umbral de 72 horas hábiles (RF-04.2): de lunes 28-09 08:00 a lunes 05-10 10:00 son 60 h;
// las 72 h exactas se alcanzan el miércoles 07-10 a las 10:00 y el aviso salta un segundo después.
$longWaitIncident = array_merge($sitePauseIncident, ['paused_at' => '2026-09-28 08:00:00']);
$longWaitRepo = new CoordinatorIncidentDetailServiceStubRepo();
$longWaitService = new CoordinatorIncidentDetailService($longWaitRepo);
$longWaitRepo->fixture = array_merge($fixture, ['incident' => $longWaitIncident]);

$atExactThreshold = $longWaitService->buildDetail(142, new DateTimeImmutable('2026-10-07 10:00:00'))->toArray();
$oneSecondOver = $longWaitService->buildDetail(142, new DateTimeImmutable('2026-10-07 10:00:01'))->toArray();
$assert(
    '7.5 Justo en el umbral no hay alerta: la espera prolongada exige superar las 72 h hábiles (RF-04.2)',
    $atExactThreshold['technical_intervention']['pause']['is_prolonged_inactivity'] === false
        && $atExactThreshold['sla']['is_frozen'] === true
);
$assert(
    '7.6 Un segundo después del umbral la alerta prioritaria se enciende',
    $oneSecondOver['technical_intervention']['pause']['is_prolonged_inactivity'] === true,
    json_encode($oneSecondOver['technical_intervention']['pause'])
);

$assert(
    '7.7 La pausa por repuestos conserva su lectura y nunca ofrece las acciones de sede',
    $blocks['technical_intervention']['pause']['is_sla_paused'] === false
        && $blocks['technical_intervention']['pause']['can_resume_pause'] === false
        && $blocks['technical_intervention']['pause']['can_cancel_inactivity'] === false
        && $blocks['technical_intervention']['pause']['is_prolonged_inactivity'] === false
        && $blocks['technical_intervention']['pause']['sla_target_frozen_at'] === null
        && $blocks['technical_intervention']['pause']['sla_target_recalculated_at'] === null
        && $blocks['sla']['is_frozen'] === false
        && $blocks['sla']['frozen_at'] === null
);

$pendingWithoutPause = array_merge($sitePauseIncident, ['paused_at' => null, 'total_pending_info_seconds' => 0]);
$noPauseRepo = new CoordinatorIncidentDetailServiceStubRepo();
$noPauseRepo->fixture = array_merge($fixture, ['incident' => $pendingWithoutPause]);
$noPauseDetail = (new CoordinatorIncidentDetailService($noPauseRepo))->buildDetail(142, $pauseNow)->toArray();
$assert(
    '7.8 Sin marca de pausa no hay reloj congelado ni acciones supervisadas (fail-safe)',
    $noPauseDetail['sla']['is_frozen'] === false
        && $noPauseDetail['technical_intervention']['pause']['is_sla_paused'] === false
        && $noPauseDetail['technical_intervention']['pause']['can_cancel_inactivity'] === false
        && $noPauseDetail['technical_intervention']['pause']['paused_minutes'] === null
);

$nonPerishablePause = array_merge($fixture, [
    'incident' => $sitePauseIncident,
    'machine' => array_merge($fixture['machine'], ['machine_type' => 'HOT_DRINKS']),
]);
$noSlaRepo = new CoordinatorIncidentDetailServiceStubRepo();
$noSlaRepo->fixture = $nonPerishablePause;
$noSlaPauseBlock = (new CoordinatorIncidentDetailService($noSlaRepo))->buildDetail(142, $pauseNow)->toArray();
$assert(
    '7.9 Máquina sin objetivo de frío: la pausa se publica pero no se inventa una fecha contractual',
    $noSlaPauseBlock['sla'] === ['has_sla_limit' => false]
        && $noSlaPauseBlock['technical_intervention']['pause']['is_sla_paused'] === true
        && $noSlaPauseBlock['technical_intervention']['pause']['sla_target_frozen_at'] === null
        && $noSlaPauseBlock['technical_intervention']['pause']['sla_target_recalculated_at'] === null
);

$settledPauseRepo = new CoordinatorIncidentDetailServiceStubRepo();
$settledPauseRepo->fixture = array_merge($fixture, ['incident' => array_merge(
    $sitePauseIncident,
    ['status' => 'CANCELLED', 'cancelled_at' => '2026-10-09 10:00:00', 'updated_at' => '2026-10-09 10:00:00']
)]);
$settledPauseDetail = (new CoordinatorIncidentDetailService($settledPauseRepo))->buildDetail(142, $pauseNow)->toArray();
$assert(
    '7.10 Expediente cancelado tras la inactividad: pausa cerrada, sin acciones y con balance histórico',
    $settledPauseDetail['sla']['is_active_countdown'] === false
        && $settledPauseDetail['sla']['is_frozen'] === false
        && $settledPauseDetail['technical_intervention']['pause']['is_sla_paused'] === false
        && $settledPauseDetail['technical_intervention']['pause']['can_resume_pause'] === false
        && $settledPauseDetail['technical_intervention']['pause']['can_cancel_inactivity'] === false
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-IDM-04 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
