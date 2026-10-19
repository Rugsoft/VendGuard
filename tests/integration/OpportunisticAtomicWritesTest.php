<?php

declare(strict_types=1);

/**
 * OpportunisticAtomicWritesTest (Módulo 16 · T-OPP-06)
 *
 * Certifica contra PostgreSQL las cuatro escrituras con las que el técnico pasa de
 * consultar el catálogo a intervenir, y el bloque de métricas que las cuenta:
 *
 *   1. **Alta `NEW`:** nace directamente en `IN_PROGRESS`, con modalidad y submotivo
 *      persistidos y su primer evento inmutable en `incident_history`.
 *   2. **Asunción directa `ASSUMED`:** un expediente `REGISTERED` sin técnico pasa a
 *      `IN_PROGRESS` bajo titularidad del técnico presente, sin duplicar el ticket.
 *   3. **Traspaso con confirmación:** el expediente de otro compañero cambia de titular
 *      y deja la nota de traspaso como comentario **interno**, invisible para la Sede
 *      (Art. V.4).
 *   4. **Reapertura in situ (1.ª y 2.ª reincidencia):** el mismo expediente vuelve a
 *      `IN_PROGRESS`, limpia la resolución y acumula los eventos `REOPENED` que cuentan
 *      las reincidencias.
 *   5. **3.ª reincidencia · Avería Crónica:** el predecesor se cierra con la marca
 *      `[AVERÍA CRÓNICA]` y el sucesor limpio nace en `IN_PROGRESS` enlazado por
 *      `chronic_predecessor_incident_id`. Si el sucesor no se puede insertar, la
 *      transacción devuelve el predecesor a `RESOLVED`: nunca queda un hueco.
 *   6. **Métricas:** `PdoMetricsRepository::getOpportunisticInterventions()` publica
 *      el bloque `opportunistic_interventions` con las cuatro claves de origen.
 *
 * Dogma Vanilla: PHP 8.2 puro, PDO nativo, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\MetricFilter;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\OpportunisticMode;
use VendGuard\Core\Domain\ValueObject\OpportunisticOriginReason;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoMetricsRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard · Módulo 16 · T-OPP-06 · Escrituras atómicas oportunistas\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();

$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$userRepo = new PdoUserRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$metricsRepo = new PdoMetricsRepository($pdo);

$failures = 0;
$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
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

$jordi = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$marta = $userRepo->findByEmail('marta.ruta@vendguard.internal');
$assert('0.1 Técnicos semilla disponibles (Jordi y Marta)', $jordi !== null && $marta !== null);

$machineCodes = ['VEND-0301', 'VEND-0302', 'VEND-0401', 'VEND-0501', 'VEND-0502', 'VEND-0102'];
$machines = [];
$locations = [];

foreach ($machineCodes as $code) {
    $machine = $machineRepo->findByCode($code);
    $assert("0.2 Máquina semilla {$code} disponible", $machine !== null);
    if ($machine === null) {
        continue;
    }

    $machines[$code] = $machine;
    // Estado limpio: ninguna incidencia previa sobre estas máquinas puede ocupar el
    // índice único parcial de la máquina ni falsear el catálogo de la prueba.
    TestDataCleaner::purgeIncidentsByMachine($pdo, $machine->getId());
    $locations[$code] = $machine->getLocationId();
}

$timestamp = static fn (): string => (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');

$freshIncident = static function (
    string $ticketCode,
    int $machineId,
    int $locationId,
    IncidentStatus $status,
    ?int $technicianId,
    ?OpportunisticMode $mode = null,
    ?OpportunisticOriginReason $origin = null,
    ?int $predecessorId = null
) use ($timestamp): Incident {
    return new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: $machineId,
        locationId: $locationId,
        category: IncidentCategory::PRODUCT_JAM,
        description: 'Atasco en espiral B4 detectado durante la visita a la sede.',
        urgency: UrgencyLevel::MEDIUM,
        status: $status,
        assignedTechnicianId: $technicianId,
        reporterName: 'Técnico en ruta',
        assignedAt: $technicianId !== null ? $timestamp() : null,
        startedAt: $status === IncidentStatus::IN_PROGRESS ? $timestamp() : null,
        cycleStartedAt: $timestamp(),
        opportunisticMode: $mode,
        opportunisticOriginReason: $origin,
        chronicPredecessorIncidentId: $predecessorId
    );
};

$rawStatus = static function (PDO $pdo, int $incidentId): array {
    $stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = :id');
    $stmt->execute([':id' => $incidentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : [];
};

if (count($machines) === count($machineCodes) && $jordi !== null && $marta !== null) {
    // Foto previa del indicador restringida a la sede que certifica el Caso 7. El
    // delta —y no el valor absoluto— es la expectativa: la batería completa puede
    // haber contado intervenciones legítimas de otras suites en el mismo motor.
    $metricsFilter = MetricFilter::fromQueryParams([
        'period' => 'last_30_days',
        'location_id' => $locations['VEND-0301'],
        'technician_id' => $jordi->getId(),
    ]);
    $metricsBefore = $metricsRepo->getOpportunisticInterventions($metricsFilter);

    // -----------------------------------------------------------------
    // CASO 1 · Alta Oportunista NEW
    // -----------------------------------------------------------------
    echo "\n--- Caso 1: Alta oportunista NEW directamente en curso (RF-04) ---\n";

    $newIncident = $incidentRepo->createOpportunisticIncident(
        $freshIncident(
            'INC-2026-A01',
            $machines['VEND-0301']->getId(),
            $locations['VEND-0301'],
            IncidentStatus::IN_PROGRESS,
            $jordi->getId(),
            OpportunisticMode::NEW,
            OpportunisticOriginReason::TECHNICIAN_DETECTION
        ),
        $jordi->getId(),
        'Alta Oportunista In Situ (Oportunista In Situ · Nueva · Detección propia del técnico). Intervención iniciada en el acto.'
    );

    $assert('1.1 El alta devuelve un expediente persistido', $newIncident->getId() !== null && $newIncident->getId() > 0);
    $assert('1.2 Nace directamente en IN_PROGRESS', $newIncident->getStatus() === IncidentStatus::IN_PROGRESS);

    $rawNew = $rawStatus($pdo, (int)$newIncident->getId());
    $assert(
        '1.3 La modalidad y el submotivo quedan persistidos',
        ($rawNew['opportunistic_mode'] ?? null) === 'NEW'
        && ($rawNew['opportunistic_origin_reason'] ?? null) === 'TECHNICIAN_DETECTION'
    );
    $assert(
        '1.4 La máquina reconoce el ticket oportunista como activo',
        $incidentRepo->findActiveByMachineId($machines['VEND-0301']->getId())?->getId() === $newIncident->getId()
    );

    $newHistory = $incidentRepo->getHistory((int)$newIncident->getId());
    $assert(
        '1.5 El alta y su primer evento de historial comparten transacción',
        count($newHistory) === 1
        && $newHistory[0]->getFromStatus() === null
        && $newHistory[0]->getToStatus() === 'IN_PROGRESS'
    );

    // -----------------------------------------------------------------
    // CASO 2 · Asunción directa de un expediente sin técnico
    // -----------------------------------------------------------------
    echo "\n--- Caso 2: Asunción directa ASSUMED (RF-05.2) ---\n";

    $registered = $incidentRepo->create(
        $freshIncident(
            'INC-2026-B01',
            $machines['VEND-0302']->getId(),
            $locations['VEND-0302'],
            IncidentStatus::REGISTERED,
            null
        ),
        null,
        'Aviso registrado por la sede'
    );

    $claimed = $incidentRepo->claimOpportunisticIncident(
        (int)$registered->getId(),
        $jordi->getId()
    );

    $assert('2.1 El expediente pasa a IN_PROGRESS', $claimed->getStatus() === IncidentStatus::IN_PROGRESS);
    $assert('2.2 Queda asignado al técnico presente', $claimed->getAssignedTechnicianId() === $jordi->getId());
    $assert(
        '2.3 Se marca ASSUMED / EXISTING_TICKET_CLAIM',
        $claimed->getOpportunisticMode() === OpportunisticMode::ASSUMED
        && $claimed->getOpportunisticOriginReason() === OpportunisticOriginReason::EXISTING_TICKET_CLAIM
    );

    $claimHistory = $incidentRepo->getHistory((int)$registered->getId());
    $lastClaim = end($claimHistory);
    $assert(
        '2.4 El historial documenta la asunción desde el estado real',
        $lastClaim !== false
        && $lastClaim->getFromStatus() === 'REGISTERED'
        && $lastClaim->getToStatus() === 'IN_PROGRESS'
        && str_contains((string)$lastClaim->getActionNote(), 'Asunción Oportunista In Situ')
    );

    // -----------------------------------------------------------------
    // CASO 3 · Traspaso de otro técnico con nota interna
    // -----------------------------------------------------------------
    echo "\n--- Caso 3: Traspaso in situ desde otro técnico (RF-05.3) ---\n";

    $assignedToMarta = $incidentRepo->create(
        $freshIncident(
            'INC-2026-C01',
            $machines['VEND-0401']->getId(),
            $locations['VEND-0401'],
            IncidentStatus::ASSIGNED,
            $marta->getId()
        ),
        null,
        'Asignación ordinaria a Marta'
    );

    $takeover = $incidentRepo->takeOverOpportunisticIncident(
        (int)$assignedToMarta->getId(),
        $jordi->getId(),
        'La máquina está en el pasillo contiguo; reviso el monedero por presencia física.'
    );

    $assert('3.1 El traspaso informa del titular saliente', $takeover['previous_technician_id'] === $marta->getId());
    $assert('3.2 El expediente queda en curso y a nombre del técnico presente', $takeover['incident']->getStatus() === IncidentStatus::IN_PROGRESS && $takeover['incident']->getAssignedTechnicianId() === $jordi->getId());
    $assert(
        '3.3 Se marca ASSUMED / EXISTING_TICKET_CLAIM',
        $takeover['incident']->getOpportunisticMode() === OpportunisticMode::ASSUMED
        && $takeover['incident']->getOpportunisticOriginReason() === OpportunisticOriginReason::EXISTING_TICKET_CLAIM
    );

    $commentStmt = $pdo->prepare('SELECT * FROM incident_comments WHERE incident_id = :id ORDER BY id DESC LIMIT 1');
    $commentStmt->execute([':id' => $assignedToMarta->getId()]);
    $transferComment = $commentStmt->fetch(PDO::FETCH_ASSOC);
    $assert(
        '3.4 La nota de traspaso es un comentario INTERNO (Art. V.4)',
        $transferComment !== false
        && !empty($transferComment['is_internal'])
        && str_contains((string)$transferComment['comment_text'], 'Traspaso Oportunista In Situ')
    );
    $assert(
        '3.5 La nota la firma el técnico que asume, no el reemplazado',
        $transferComment !== false
        && (int)$transferComment['user_id'] === $jordi->getId()
        && (string)$transferComment['author_name'] === $jordi->getName()
    );

    // Un traspaso puede caer sobre un expediente PAUSADO por la sede. El intervalo
    // vivo debe cerrarse al cambiar de titular: sus segundos son el descuento que el
    // MTTR y el SLA contractual aplican, y dejar `paused_at` encendido lo falsearía.
    $pausedForMarta = $incidentRepo->create(
        $freshIncident(
            'INC-2026-C02',
            $machines['VEND-0502']->getId(),
            $locations['VEND-0502'],
            IncidentStatus::ASSIGNED,
            $marta->getId()
        ),
        null,
        'Asignación ordinaria que la sede pausará'
    );

    $pauseStmt = $pdo->prepare(
        "UPDATE incidents
            SET status = 'PENDING_INFO',
                paused_at = :paused_at
          WHERE id = :id"
    );
    $pauseStmt->execute([
        ':paused_at' => EngineClock::shift(-600),
        ':id' => $pausedForMarta->getId(),
    ]);

    $pausedTakeover = $incidentRepo->takeOverOpportunisticIncident(
        (int)$pausedForMarta->getId(),
        $jordi->getId()
    );

    $assert('3.6 El traspaso de un expediente pausado lo devuelve a curso', $pausedTakeover['incident']->getStatus() === IncidentStatus::IN_PROGRESS);
    $assert('3.7 El intervalo de pausa vivo se cierra al cambiar de titular', $pausedTakeover['incident']->getPausedAt() === null);
    $assert(
        '3.8 Los segundos de la pausa cerrada se acumulan para el MTTR',
        $pausedTakeover['incident']->getTotalPendingInfoSeconds() >= 600
        && $pausedTakeover['incident']->getTotalPendingInfoSeconds() < 720,
        'acumulado=' . $pausedTakeover['incident']->getTotalPendingInfoSeconds()
    );

    // -----------------------------------------------------------------
    // CASO 4 · Reapertura in situ en garantía (1.ª y 2.ª reincidencia)
    // -----------------------------------------------------------------
    echo "\n--- Caso 4: Reapertura in situ en garantía (RF-05.4.1) ---\n";

    $reopenCandidate = $incidentRepo->create(
        $freshIncident(
            'INC-2026-D01',
            $machines['VEND-0501']->getId(),
            $locations['VEND-0501'],
            IncidentStatus::REGISTERED,
            null
        ),
        null,
        'Aviso ordinario que reaparecerá en garantía'
    );

    $markResolved = static function (PDO $pdo, int $incidentId, int $secondsAgo): void {
        $stmt = $pdo->prepare(
            "UPDATE incidents
                SET status = 'RESOLVED',
                    resolved_at = :resolved_at,
                    resolution_diagnosis = 'Diagnóstico de la primera reparación.',
                    resolution_action = 'Acción correctiva aplicada en el primer servicio.'
              WHERE id = :id"
        );
        $stmt->execute([
            ':resolved_at' => EngineClock::shift($secondsAgo),
            ':id' => $incidentId,
        ]);
    };

    $markResolved($pdo, (int)$reopenCandidate->getId(), -3600);
    $firstReopen = $incidentRepo->reopenOpportunisticIncident(
        (int)$reopenCandidate->getId(),
        $jordi->getId(),
        'El monedero vuelve a no devolver cambio tras la reparación.'
    );

    $assert('4.1 La 1.ª reincidencia reabre en curso', $firstReopen->getStatus() === IncidentStatus::IN_PROGRESS);
    $assert('4.2 La resolución anterior queda limpia', $firstReopen->getResolvedAt() === null);
    $assert(
        '4.3 Se marca ASSUMED / WARRANTY_REOPEN_IN_SITU',
        $firstReopen->getOpportunisticMode() === OpportunisticMode::ASSUMED
        && $firstReopen->getOpportunisticOriginReason() === OpportunisticOriginReason::WARRANTY_REOPEN_IN_SITU
    );
    $assert('4.4 El historial cuenta la primera reapertura', $incidentRepo->countReopenEvents((int)$reopenCandidate->getId()) === 1);

    $markResolved($pdo, (int)$reopenCandidate->getId(), -1800);
    $secondReopen = $incidentRepo->reopenOpportunisticIncident(
        (int)$reopenCandidate->getId(),
        $jordi->getId(),
        'Segunda reincidencia: el mismo monedero vuelve a fallar.'
    );
    $assert('4.5 La 2.ª reincidencia sigue reabriendo el mismo ticket', $secondReopen->getId() === $firstReopen->getId() && $secondReopen->getStatus() === IncidentStatus::IN_PROGRESS);
    $assert('4.6 El historial acumula dos reaperturas', $incidentRepo->countReopenEvents((int)$reopenCandidate->getId()) === 2);

    // -----------------------------------------------------------------
    // CASO 5 · 3.ª reincidencia · Avería Crónica
    // -----------------------------------------------------------------
    echo "\n--- Caso 5: 3.ª reincidencia · Avería Crónica (RF-05.4.2) ---\n";

    $markResolved($pdo, (int)$reopenCandidate->getId(), -600);

    $successor = $freshIncident(
        'INC-2026-D02',
        $machines['VEND-0501']->getId(),
        $locations['VEND-0501'],
        IncidentStatus::IN_PROGRESS,
        $jordi->getId(),
        OpportunisticMode::NEW,
        OpportunisticOriginReason::WARRANTY_REOPEN_IN_SITU,
        (int)$reopenCandidate->getId()
    );

    $createdSuccessor = $incidentRepo->replaceChronicOpportunisticIncident(
        (int)$reopenCandidate->getId(),
        $successor,
        $jordi->getId()
    );

    $rawPredecessor = $rawStatus($pdo, (int)$reopenCandidate->getId());
    $assert('5.1 El predecesor queda CLOSED', ($rawPredecessor['status'] ?? null) === 'CLOSED');
    $assert('5.2 El predecesor conserva la marca [AVERÍA CRÓNICA]', str_contains((string)($rawPredecessor['reopen_reason'] ?? ''), '[AVERÍA CRÓNICA]'));
    $assert('5.3 El predecesor registra su cierre', ($rawPredecessor['closed_at'] ?? null) !== null);

    $assert('5.4 El sucesor nace en curso', $createdSuccessor->getId() !== null && $createdSuccessor->getStatus() === IncidentStatus::IN_PROGRESS);
    $assert(
        '5.5 El sucesor es NEW / WARRANTY_REOPEN_IN_SITU y enlaza al crónico',
        $createdSuccessor->getOpportunisticMode() === OpportunisticMode::NEW
        && $createdSuccessor->getOpportunisticOriginReason() === OpportunisticOriginReason::WARRANTY_REOPEN_IN_SITU
        && $createdSuccessor->getChronicPredecessorIncidentId() === $reopenCandidate->getId()
    );
    $assert(
        '5.6 La máquina queda con un único expediente activo: el sucesor',
        $incidentRepo->findActiveByMachineId($machines['VEND-0501']->getId())?->getId() === $createdSuccessor->getId()
    );

    $activeCountStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM incidents WHERE machine_id = :machine_id AND status NOT IN ('CLOSED', 'CANCELLED')"
    );
    $activeCountStmt->execute([':machine_id' => $machines['VEND-0501']->getId()]);
    $assert('5.7 No conviven dos expedientes activos en la misma máquina', (int)$activeCountStmt->fetchColumn() === 1);

    $predecessorHistory = $incidentRepo->getHistory((int)$reopenCandidate->getId());
    $closedEvent = end($predecessorHistory);
    $assert(
        '5.8 El cierre crónico deja rastro inmutable',
        $closedEvent !== false
        && $closedEvent->getFromStatus() === 'RESOLVED'
        && $closedEvent->getToStatus() === 'CLOSED'
        && str_contains((string)$closedEvent->getActionNote(), 'AVERÍA CRÓNICA')
    );

    // -----------------------------------------------------------------
    // CASO 6 · Atomicidad del reemplazo: si el sucesor falla, el crónico revive
    // -----------------------------------------------------------------
    echo "\n--- Caso 6: El reemplazo crónico revierte si el sucesor no se inserta ---\n";

    $rollbackCandidate = $incidentRepo->create(
        $freshIncident(
            'INC-2026-F01',
            $machines['VEND-0102']->getId(),
            $locations['VEND-0102'],
            IncidentStatus::REGISTERED,
            null
        ),
        null,
        'Expediente para certificar la atomicidad del reemplazo'
    );

    $markResolved($pdo, (int)$rollbackCandidate->getId(), -1200);
    $incidentRepo->insertHistory((int)$rollbackCandidate->getId(), $jordi->getId(), 'RESOLVED', 'REOPENED', 'Primera reapertura simulada.');
    $incidentRepo->insertHistory((int)$rollbackCandidate->getId(), $jordi->getId(), 'REOPENED', 'IN_PROGRESS', 'Reanudación simulada.');
    $incidentRepo->insertHistory((int)$rollbackCandidate->getId(), $jordi->getId(), 'IN_PROGRESS', 'RESOLVED', 'Nueva resolución simulada.');
    $incidentRepo->insertHistory((int)$rollbackCandidate->getId(), $jordi->getId(), 'RESOLVED', 'REOPENED', 'Segunda reapertura simulada.');
    $incidentRepo->insertHistory((int)$rollbackCandidate->getId(), $jordi->getId(), 'REOPENED', 'IN_PROGRESS', 'Reanudación simulada.');
    $incidentRepo->insertHistory((int)$rollbackCandidate->getId(), $jordi->getId(), 'IN_PROGRESS', 'RESOLVED', 'Resolución final simulada.');

    $duplicateSuccessor = $freshIncident(
        'INC-2026-F01',
        $machines['VEND-0102']->getId(),
        $locations['VEND-0102'],
        IncidentStatus::IN_PROGRESS,
        $jordi->getId(),
        OpportunisticMode::NEW,
        OpportunisticOriginReason::WARRANTY_REOPEN_IN_SITU,
        (int)$rollbackCandidate->getId()
    );

    $rollbackFailure = null;
    try {
        $incidentRepo->replaceChronicOpportunisticIncident(
            (int)$rollbackCandidate->getId(),
            $duplicateSuccessor,
            $jordi->getId()
        );
    } catch (Throwable $e) {
        $rollbackFailure = $e;
    }

    $rawRollback = $rawStatus($pdo, (int)$rollbackCandidate->getId());
    $assert('6.1 El sucesor inválido hace fallar la operación', $rollbackFailure !== null);
    $assert('6.2 El predecesor NO queda cerrado (la transacción revirtió)', ($rawRollback['status'] ?? null) === 'RESOLVED' && ($rawRollback['closed_at'] ?? null) === null);
    $assert(
        '6.3 La máquina conserva su expediente vivo y no aparecen huérfanos',
        $incidentRepo->findActiveOrResolvedByMachineId($machines['VEND-0102']->getId())?->getId() === $rollbackCandidate->getId()
    );

    // -----------------------------------------------------------------
    // CASO 7 · Métricas de desplazamientos evitados
    // -----------------------------------------------------------------
    echo "\n--- Caso 7: Bloque opportunistic_interventions (RF-06.4) ---\n";

    $after = $metricsRepo->getOpportunisticInterventions($metricsFilter);

    $assert(
        '7.1 El bloque publica las cuatro claves del contrato',
        array_keys($after) === ['total_avoided_dispatches', 'new_count', 'assumed_count', 'by_origin_reason']
    );
    $assert(
        '7.2 Los cuatro motivos de origen viajan siempre, con cero cuando no se usan',
        array_keys($after['by_origin_reason']) === [
            'SITE_VERBAL_NOTICE',
            'TECHNICIAN_DETECTION',
            'EXISTING_TICKET_CLAIM',
            'WARRANTY_REOPEN_IN_SITU',
        ]
    );
    $assert('7.3 Total = nuevas + asumidas', $after['total_avoided_dispatches'] === $after['new_count'] + $after['assumed_count']);

    $deltaNew = $after['new_count'] - $metricsBefore['new_count'];
    $deltaAssumed = $after['assumed_count'] - $metricsBefore['assumed_count'];
    $assert('7.4 La sede certificada aporta una intervención nueva', $deltaNew === 1, "delta new={$deltaNew}");
    $assert('7.5 La sede certificada aporta una intervención asumida', $deltaAssumed === 1, "delta assumed={$deltaAssumed}");
    $assert(
        '7.6 El desglose por motivo suma el total de la sede',
        array_sum($after['by_origin_reason']) === $after['total_avoided_dispatches']
    );

    // Limpieza de las incidencias sembradas por la suite.
    foreach ($machines as $machine) {
        TestDataCleaner::purgeIncidentsByMachine($pdo, $machine->getId());
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-OPP-06 CERTIFICADA.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
