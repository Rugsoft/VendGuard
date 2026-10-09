<?php

declare(strict_types=1);

/**
 * PendingInfoRepositoryAuditTest
 *
 * Test de integración del contrato de pausa del repositorio de incidencias
 * (tarea T-PAUSE-05) contra MariaDB real. Certifica cuatro cosas que sólo se
 * pueden demostrar con la base de datos delante:
 *
 * 1. `recordPauseEvent()` escribe una fila inmutable en `incident_history` con el
 *    estado de origen, el actor, la causa tipificada, la justificación y la marca
 *    temporal EXACTA del inicio del intervalo (RF-01.4, RNF-01, Art. III); y no
 *    toca el estado del expediente, que sigue siendo responsabilidad de `update()`.
 * 2. `recordResumeEvent()` cierra el intervalo con su destino real, su duración
 *    exacta en segundos y admite actor `null` cuando la reactivación es automática
 *    por comentario de la sede (RF-02.1, RF-02.4).
 * 3. `incident_history` es de SOLO ADICIÓN: cada evento añade exactamente una fila y
 *    las anteriores quedan intactas byte a byte, sin `UPDATE` ni `DELETE` en el
 *    repositorio (Art. III / RNF-05).
 * 4. El agregado y su fila son el mismo dato: `pausePendingInfo()` + `update()` +
 *    `findById()` devuelve la pausa hidratada, y `getPendingInfoIncidentsOlderThanHours()`
 *    localiza las esperas que superan el umbral y descarta las recientes (RF-04.2).
 *
 * Dogma Vanilla: PHP 8.2+ puro con PDO nativo, sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - PendingInfoRepositoryAuditTest (T-PAUSE-05)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);

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

const T05_TICKET = 'INC-TEST-T0501';
const T05_TICKET_RECENT = 'INC-TEST-T0502';
const T05_REASON = 'Edificio cerrado por festivo local; conserjería sin personal hasta mañana.';

TestDataCleaner::purgeIncidentsMatchingTicket($pdo, 'INC-TEST-T05%');

$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$machines = $location !== null ? $machineRepo->findActiveByLocationId($location->getId()) : [];
$machine = null;
foreach ($machines as $candidate) {
    if ($candidate->getCode() === 'VEND-0102') {
        $machine = $candidate;
        break;
    }
}
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

// Segunda máquina de la misma sede SIN ticket activo: la regla de prevención de
// duplicados de la Constitución (Art. V.2) impide dos avisos vivos en el mismo equipo,
// de modo que la consulta de inactividad se contrasta sobre otro punto de servicio.
$secondMachine = null;
foreach ($machines as $candidate) {
    if ($machine !== null && $candidate->getId() === $machine->getId()) {
        continue;
    }
    if ($incidentRepo->findActiveByMachineId($candidate->getId()) === null) {
        $secondMachine = $candidate;
        break;
    }
}

// =====================================================================
// GRUPO 1: El contrato existe y es el que fija la tarea
// =====================================================================
echo "--- Grupo 1: Contrato del repositorio ---\n";

$interface = new ReflectionClass(IncidentRepositoryInterface::class);
$assert(
    '1.1 El contrato declara los tres métodos de pausa exigidos por la tarea',
    $interface->hasMethod('recordPauseEvent')
    && $interface->hasMethod('recordResumeEvent')
    && $interface->hasMethod('getPendingInfoIncidentsOlderThanHours'),
    'métodos: ' . implode(', ', array_map(
        static fn(ReflectionMethod $m): string => $m->getName(),
        $interface->getMethods()
    ))
);

$assert(
    '1.2 La implementación PDO satisface el contrato',
    in_array(IncidentRepositoryInterface::class, class_implements(PdoIncidentRepository::class), true)
    && $machine !== null
    && $secondMachine !== null
    && $location !== null
    && $technician !== null
    && $coordinator !== null
);

if ($machine === null || $secondMachine === null || $location === null || $technician === null || $coordinator === null) {
    echo "\n[ABORTADO] No se pudo preparar el entorno de prueba (semillas incompletas).\n";
    exit(1);
}

$created = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: T05_TICKET,
    machineId: $machine->getId(),
    locationId: $location->getId(),
    category: IncidentCategory::PRODUCT_JAM,
    description: 'Avería de prueba del contrato de pausa de SLA (T-PAUSE-05).',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::ASSIGNED,
    assignedTechnicianId: $technician->getId(),
    assignedAt: '2026-10-08 09:00:00'
), $coordinator->getId(), 'Aviso creado para la suite de pausa.');

$incidentId = (int)$created->getId();
$pausedAt = new DateTimeImmutable('2026-10-08 10:15:00');
$resumedAt = new DateTimeImmutable('2026-10-08 11:45:00');

// =====================================================================
// GRUPO 2: recordPauseEvent() escribe el evento inmutable (RF-01.4)
// =====================================================================
echo "\n--- Grupo 2: Registro inmutable del evento de pausa ---\n";

$historyBefore = $incidentRepo->getHistory($incidentId);
$incidentRepo->recordPauseEvent(
    $incidentId,
    $technician->getId(),
    IncidentStatus::ASSIGNED,
    IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
    T05_REASON,
    $pausedAt
);
$historyAfterPause = $incidentRepo->getHistory($incidentId);

$assert(
    '2.1 La pausa añade exactamente una fila al historial (solo adición)',
    count($historyBefore) === 1 && count($historyAfterPause) === 2,
    'antes: ' . count($historyBefore) . ' · después: ' . count($historyAfterPause)
);

$rawPause = $pdo->query(
    "SELECT * FROM incident_history WHERE incident_id = {$incidentId} ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

$assert(
    '2.2 El evento documenta el estado de origen y el destino PENDING_INFO',
    $rawPause !== false
    && $rawPause['from_status'] === IncidentStatus::ASSIGNED->value
    && $rawPause['to_status'] === IncidentStatus::PENDING_INFO->value
);

$assert(
    '2.3 El evento acredita al usuario responsable de la pausa',
    $rawPause !== false && (int)$rawPause['user_id'] === $technician->getId(),
    'user_id: ' . var_export($rawPause['user_id'] ?? null, true)
);

$assert(
    '2.4 La marca temporal es EXACTA al segundo declarado (RNF-01)',
    $rawPause !== false && $rawPause['created_at'] === $pausedAt->format('Y-m-d H:i:s'),
    'created_at: ' . var_export($rawPause['created_at'] ?? null, true)
);

$pauseNote = (string)($rawPause['action_note'] ?? '');
$assert(
    '2.5 La nota canónica lleva la causa tipificada con su etiqueta y su código',
    str_contains($pauseNote, 'Edificio cerrado / Sin acceso a instalaciones')
    && str_contains($pauseNote, '[BUILDING_CLOSED_NO_ACCESS]')
    && str_contains($pauseNote, 'Justificación: ' . T05_REASON),
    $pauseNote
);

$assert(
    '2.6 La nota de pausa NO usa el marcador « Motivo: » de las reasignaciones',
    !str_contains($pauseNote, ' Motivo: '),
    'el marcador « Motivo: » pertenece a CoordinatorIncidentDetailService::findReassignmentReason()'
);

$rawIncident = $pdo->query("SELECT status, paused_at FROM incidents WHERE id = {$incidentId}")->fetch(PDO::FETCH_ASSOC);
$assert(
    '2.7 Registrar el evento NO cambia el estado: la transición es de update()',
    $rawIncident !== false
    && $rawIncident['status'] === IncidentStatus::ASSIGNED->value
    && $rawIncident['paused_at'] === null
);

// =====================================================================
// GRUPO 3: recordResumeEvent() cierra el intervalo (RF-02.4)
// =====================================================================
echo "\n--- Grupo 3: Registro inmutable de la reanudación ---\n";

$incidentRepo->recordResumeEvent(
    $incidentId,
    $technician->getId(),
    IncidentStatus::IN_PROGRESS,
    5400,
    'Conserjería entrega la llave al técnico de guardia.',
    $resumedAt
);

$rawResume = $pdo->query(
    "SELECT * FROM incident_history WHERE incident_id = {$incidentId} ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

$assert(
    '3.1 La reanudación documenta el cierre del intervalo y su destino real',
    $rawResume !== false
    && $rawResume['from_status'] === IncidentStatus::PENDING_INFO->value
    && $rawResume['to_status'] === IncidentStatus::IN_PROGRESS->value
);

$resumeNote = (string)($rawResume['action_note'] ?? '');
$assert(
    '3.2 La duración del intervalo queda auditada en segundos exactos',
    str_contains($resumeNote, 'Duración del intervalo de pausa: 5400 s'),
    $resumeNote
);

$assert(
    '3.3 La nota opcional de reanudación se anexa con su marcador canónico',
    str_contains($resumeNote, 'Nota: Conserjería entrega la llave al técnico de guardia.')
    && $rawResume['created_at'] === $resumedAt->format('Y-m-d H:i:s'),
    $resumeNote
);

// Reactivación automática por comentario de sede (RF-02.1): no hay usuario humano.
$incidentRepo->recordResumeEvent(
    $incidentId,
    null,
    IncidentStatus::ASSIGNED,
    1800,
    null,
    new DateTimeImmutable('2026-10-08 12:30:00')
);
$rawAutoResume = $pdo->query(
    "SELECT user_id, action_note FROM incident_history WHERE incident_id = {$incidentId} ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

$assert(
    '3.4 Una reanudación automática se audita sin actor y sin nota espuria',
    $rawAutoResume !== false
    && $rawAutoResume['user_id'] === null
    && str_contains((string)$rawAutoResume['action_note'], 'Duración del intervalo de pausa: 1800 s')
    && !str_contains((string)$rawAutoResume['action_note'], 'Nota: ')
);

$invalidRejected = 0;
foreach ([
    static fn() => $incidentRepo->recordPauseEvent(0, $technician->getId(), IncidentStatus::ASSIGNED, IncidentPauseReasonCategory::EXTERNAL_POWER_CUT, T05_REASON, $pausedAt),
    static fn() => $incidentRepo->recordPauseEvent($incidentId, $technician->getId(), IncidentStatus::ASSIGNED, IncidentPauseReasonCategory::EXTERNAL_POWER_CUT, '   ', $pausedAt),
    static fn() => $incidentRepo->recordResumeEvent($incidentId, null, IncidentStatus::ASSIGNED, -1, null, $resumedAt),
    static fn() => $incidentRepo->getPendingInfoIncidentsOlderThanHours(0),
] as $invalidCall) {
    try {
        $invalidCall();
    } catch (InvalidArgumentException) {
        $invalidRejected++;
    }
}
$assert(
    '3.5 Los cuatro bordes inválidos (id 0, justificación vacía, duración negativa, umbral 0) se rechazan',
    $invalidRejected === 4,
    "rechazados: {$invalidRejected}/4"
);

// =====================================================================
// GRUPO 4: Inmutabilidad histórica (Art. III / RNF-05)
// =====================================================================
echo "\n--- Grupo 4: Inviolabilidad del historial (Art. III) ---\n";

$snapshotBefore = $pdo->query(
    "SELECT id, incident_id, user_id, from_status, to_status, action_note, created_at
     FROM incident_history WHERE incident_id = {$incidentId} ORDER BY id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$incidentRepo->recordPauseEvent(
    $incidentId,
    $coordinator->getId(),
    IncidentStatus::IN_PROGRESS,
    IncidentPauseReasonCategory::EXTERNAL_POWER_CUT,
    'Corte de suministro general del inmueble durante la prueba de encendido.',
    new DateTimeImmutable('2026-10-08 13:00:00')
);

$snapshotAfter = $pdo->query(
    "SELECT id, incident_id, user_id, from_status, to_status, action_note, created_at
     FROM incident_history WHERE incident_id = {$incidentId} ORDER BY id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$assert(
    '4.1 Cada evento añade exactamente una fila y ninguna desaparece',
    count($snapshotAfter) === count($snapshotBefore) + 1
);

$preservedRows = array_slice($snapshotAfter, 0, count($snapshotBefore));
$assert(
    '4.2 Las filas previas quedan intactas byte a byte (sin sobreescrituras)',
    $preservedRows === $snapshotBefore,
    'diferencia detectada en el historial previo'
);

$repositorySource = (string)file_get_contents(__DIR__ . '/../../src/Infrastructure/Repository/PdoIncidentRepository.php');
$assert(
    '4.3 El repositorio no contiene ningún UPDATE ni DELETE sobre incident_history',
    !preg_match('/UPDATE\s+`incident_history`/i', $repositorySource)
    && !preg_match('/DELETE\s+FROM\s+`incident_history`/i', $repositorySource)
);

// =====================================================================
// GRUPO 5: Estado del agregado hidratado y consulta de espera prolongada
// =====================================================================
echo "\n--- Grupo 5: Pausa persistida y consulta de inactividad (RF-04.2) ---\n";

$freshRow = $pdo->query("SELECT status FROM incidents WHERE id = {$incidentId}")->fetchColumn();
$hydrated = $incidentRepo->findById($incidentId);

$pausedIncident = $hydrated
    ->pausePendingInfo(
        IncidentPauseReasonCategory::MACHINE_LOCATION_NOT_FOUND,
        'La máquina fue reubicada en la planta sin avisar al servicio técnico.',
        $pausedAt
    )
    ->resumePendingInfo(IncidentStatus::IN_PROGRESS, $resumedAt)
    ->shiftSlaTarget(new DateTimeImmutable('2026-10-08 13:45:00'));
$updateOk = $incidentRepo->update($pausedIncident);

$reloaded = $incidentRepo->findById($incidentId);
$assert(
    '5.1 update() persiste el agregado completo y findById() lo hidrata sin pérdida',
    $updateOk
    && $freshRow === IncidentStatus::ASSIGNED->value
    && $reloaded->getStatus() === IncidentStatus::IN_PROGRESS
    && $reloaded->isPaused() === false
    && $reloaded->getTotalPendingInfoSeconds() === 5400
    && $reloaded->getPendingInfoReasonCategory() === IncidentPauseReasonCategory::MACHINE_LOCATION_NOT_FOUND
    && $reloaded->getPendingInfoReasonText() === 'La máquina fue reubicada en la planta sin avisar al servicio técnico.'
    && $reloaded->getSlaTargetAt() === '2026-10-08 13:45:00',
    json_encode($reloaded->toArray(), JSON_UNESCAPED_UNICODE)
);

// Pausa VIVA y antigua (80 h naturales) -> debe aparecer en la auditoría de inactivos.
$pausedNow = $reloaded->pausePendingInfo(
    IncidentPauseReasonCategory::BUILDING_CLOSED_NO_ACCESS,
    T05_REASON,
    new DateTimeImmutable('2026-10-08 14:00:00')
);
$incidentRepo->update($pausedNow);
$pdo->exec("UPDATE incidents SET paused_at = DATE_SUB(NOW(), INTERVAL 80 HOUR) WHERE id = {$incidentId}");

$oldRows = $incidentRepo->getPendingInfoIncidentsOlderThanHours(72);
$matching = array_values(array_filter($oldRows, static fn(array $row): bool => $row['id'] === $incidentId));

$assert(
    '5.2 Una pausa de 80 horas naturales supera el umbral de 72 y entra en la consulta',
    count($matching) === 1,
    'filas devueltas: ' . count($oldRows)
);

$assert(
    '5.3 La fila trae el contexto completo que necesita el triaje del coordinador',
    $matching !== []
    && $matching[0]['ticket_code'] === T05_TICKET
    && $matching[0]['status'] === IncidentStatus::PENDING_INFO->value
    && $matching[0]['machine_id'] === $machine->getId()
    && $matching[0]['machine_code'] === 'VEND-0102'
    && $matching[0]['machine_type'] === $machine->getMachineType()->value
    && $matching[0]['location_name'] !== null
    && $matching[0]['assigned_technician_id'] === $technician->getId()
    && $matching[0]['pending_info_reason_category'] === 'BUILDING_CLOSED_NO_ACCESS'
    && $matching[0]['pending_info_reason_category_label'] === 'Edificio cerrado / Sin acceso a instalaciones'
    && $matching[0]['pending_info_reason_text'] === T05_REASON
    && $matching[0]['total_pending_info_seconds'] === 5400,
    json_encode($matching[0] ?? [], JSON_UNESCAPED_UNICODE)
);

// Segunda incidencia: pausa reciente (2 h) que NO debe aparecer con umbral 72.
$recent = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: T05_TICKET_RECENT,
    machineId: $secondMachine->getId(),
    locationId: $location->getId(),
    category: IncidentCategory::PAYMENT_SYSTEM,
    description: 'Segunda avería de prueba para la consulta de inactividad (T-PAUSE-05).',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::ASSIGNED,
    assignedTechnicianId: $technician->getId()
), $coordinator->getId(), 'Aviso de control de umbral.');

$recentId = (int)$recent->getId();
$recentRepo = new PdoIncidentRepository($pdo);
$recentRepo->update($recentRepo->findById($recentId)->pausePendingInfo(
    IncidentPauseReasonCategory::PENDING_SITE_AUTHORIZATION,
    'Falta la autorización de seguridad para entrar en la sala restringida.',
    new DateTimeImmutable('2026-10-08 12:00:00')
));
$pdo->exec("UPDATE incidents SET paused_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = {$recentId}");

$oldRowsAfter = $incidentRepo->getPendingInfoIncidentsOlderThanHours(72);
$recentIds = array_column($oldRowsAfter, 'id');

$assert(
    '5.4 Una pausa de 2 horas NO entra en el umbral de 72 (pre-filtro sin falsos positivos)',
    !in_array($recentId, array_map('intval', $recentIds), true),
    'ids devueltos: ' . implode(',', $recentIds)
);

$sortedPauseDates = array_column($oldRowsAfter, 'paused_at');
$ascendingDates = $sortedPauseDates;
sort($ascendingDates);

$assert(
    '5.5 La consulta ordena la espera más larga primero y sólo incluye PENDING_INFO',
    in_array($incidentId, array_map('intval', $recentIds), true)
    && $sortedPauseDates === $ascendingDates
    && count(array_filter($oldRowsAfter, static fn(array $row): bool => $row['status'] !== 'PENDING_INFO')) === 0,
    'paused_at: ' . implode(', ', $sortedPauseDates)
);

$assert(
    '5.6 La consulta sólo devuelve pausas VIVAS: al reanudar, el ticket sale del listado',
    (function () use ($incidentRepo, $pdo, $incidentId): bool {
        $live = $incidentRepo->findById($incidentId);
        $incidentRepo->update($live->resumePendingInfo(IncidentStatus::ASSIGNED, new DateTimeImmutable('2026-10-08 15:00:00')));

        return !in_array($incidentId, array_map('intval', array_column($incidentRepo->getPendingInfoIncidentsOlderThanHours(72), 'id')), true);
    })()
);

TestDataCleaner::purgeIncident($pdo, $incidentId);
TestDataCleaner::purgeIncident($pdo, $recentId);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-05 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
