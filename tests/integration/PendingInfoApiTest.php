<?php

declare(strict_types=1);

/**
 * PendingInfoApiTest (T-PAUSE-21)
 *
 * Certifica de extremo a extremo, contra MariaDB real y a través del enrutador con
 * sus middlewares de verdad (sin invocar ningún controlador a mano), el ciclo de
 * vida completo del módulo 11 que exige la condición "Hecho cuando" de la tarea:
 *
 *   1. **Flujo completo de pausa por técnico y coordinador** (RF-01.1): el técnico
 *      asignado pausa su parada y el coordinador pausa desde central, con la causa
 *      tipificada y la justificación de 20 caracteres reales (Art. V.1).
 *   2. **Reanudación manual** (RF-02.2, RF-02.3): el técnico in situ devuelve el
 *      expediente a `IN_PROGRESS` y el coordinador elige el destino explícito.
 *   3. **Reactivación condicional automática tras comentario de sede** (RF-02.1):
 *      en caliente (< 60 min) vuelve a `IN_PROGRESS`; desfasada (>= 60 min) a
 *      `ASSIGNED`. La rama desfasada se siembra con SQL dirigido sobre el
 *      expediente propio, porque esperar una hora real no cabe en una suite.
 *   4. **Desplazamiento exacto de SLA en horario hábil** (RF-03.3, RNF-01): el
 *      vencimiento sembrado en jornada comercial se desplaza al segundo los
 *      intervalos acumulados, incluida la suma de pausas sucesivas del mismo
 *      expediente (RF-04.1), y nunca aterriza fuera de 08:00-18:00 de lunes a
 *      viernes.
 *   5. **Cancelación formal por inactividad de 72 h hábiles** (RF-04.2 a RF-04.5):
 *      tras una espera real de más de 72 horas hábiles, el coordinador cancela el
 *      expediente con motivo, la máquina queda fuera de servicio por falta de
 *      acceso, el reintegro del consumidor se preserva desvinculado y el hilo
 *      queda sellado (RF-05.4).
 *   6. **RNF-02 medido**: la pausa y la reanudación se completan por debajo de los
 *      200 ms. La medición es en proceso (router + PDO contra MariaDB local), de
 *      modo que mide la transición real de negocio sin sumar la latencia de red
 *      de un cliente remoto; la mediana de siete muestras es el estadístico que
 *      exige el proyecto (precedente de `IncidentCommentsPerformanceAndSecurityTest`).
 *
 * Las suites hermanas (T-PAUSE-12, T-PAUSE-13 y T-PAUSE-14) fijan el contrato HTTP
 * de cada endpoint por separado, con todos sus códigos de error; ésta recorre el
 * expediente como lo haría un cliente real encadenando llamadas —pausa, respuesta
 * de sede, nueva pausa, reanudación manual, cancelación— y aporta la cobertura que
 * ninguna de ellas mira por sí sola: la acumulación de intervalos a lo largo de la
 * vida del ticket con el desplazamiento acumulativo exacto del vencimiento, la
 * latencia de las transiciones (RNF-02) y la cancelación sobre un expediente que
 * ya había pausado y reanudado antes.
 *
 * El banco se toma de la flota activa y libre del parque (la clave única condicional
 * `uq_machine_active_ticket` impide dos tickets activos por equipo), así que cada
 * escenario estrena máquina y la sesión de sede se emite para la ubicación de la
 * máquina que le toca. Todo el banco se purga al terminar con `TestDataCleaner`; la
 * máquina bloqueada por la cancelación se restituye y el reintegro desvinculado se
 * reengancha a su avería para que el purgado dirigido lo arrastre como hija, porque
 * las filas maestras (sedes, máquinas y usuarios) las protege el arnés.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Integración - PendingInfoApiTest (T-PAUSE-21)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router       = AppRouter::create();
$incidentRepo = new PdoIncidentRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);
$pauseService = new IncidentPauseService(
    incidentRepo: $incidentRepo,
    machineRepo: $machineRepo
);

$assertions = 0;
$failures   = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

// Presupuesto de RNF-02 para la transición de pausa o reanudación, y su guardián
// anti-outlier (3×), con el mismo patrón que las suites de rendimiento del proyecto.
const TRANSITION_BUDGET_MS = 200.0;
const TRANSITION_OUTLIER_CEILING_MS = 600.0;

// -----------------------------------------------------------------------
// Grupo 0: banco de pruebas sobre la flota activa y libre del parque
// -----------------------------------------------------------------------
$machinePool = $pdo->query(
    "SELECT m.`id` AS machine_id, m.`location_id`
       FROM `machines` m
       JOIN `locations` l ON l.`id` = m.`location_id`
      WHERE m.`is_active` = 1
        AND m.`is_blocked_no_access` = 0
        AND m.`deleted_at` IS NULL
        AND l.`is_active` = 1
        AND l.`deleted_at` IS NULL
        AND NOT EXISTS (
            SELECT 1 FROM `incidents` i
             WHERE i.`machine_id` = m.`id`
               AND i.`deleted_at` IS NULL
               AND i.`status` NOT IN ('CLOSED', 'CANCELLED')
        )
      ORDER BY m.`id`"
)->fetchAll(PDO::FETCH_ASSOC);

$assert(
    '0.1 Flota activa y libre suficiente para el banco de pruebas (>= 5 máquinas)',
    count($machinePool) >= 5,
    sprintf('El parque expone %d máquinas activas y libres.', count($machinePool))
);

if (count($machinePool) < 5) {
    echo "ERROR FATAL: no hay máquinas libres suficientes para el banco de pruebas.\n";
    exit(1);
}

$technicianRows = $pdo->query(
    "SELECT `id` FROM `users`
      WHERE `role` = 'TECHNICIAN' AND `deleted_at` IS NULL
      ORDER BY `id` LIMIT 2"
)->fetchAll(PDO::FETCH_COLUMN);

$primaryTech = isset($technicianRows[0]) ? $userRepo->findById((int)$technicianRows[0]) : null;
$otherTech   = isset($technicianRows[1]) ? $userRepo->findById((int)$technicianRows[1]) : null;
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert(
    '0.2 Semilla disponible: coordinador y dos técnicos distintos',
    $primaryTech instanceof User && $otherTech instanceof User && $coordinator instanceof User
);

if (!$primaryTech instanceof User || !$otherTech instanceof User || !$coordinator instanceof User) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$techId        = (int)$primaryTech->getId();
$otherTechId   = (int)$otherTech->getId();
$coordinatorId = (int)$coordinator->getId();

// El técnico primario no debe arrastrar otra intervención activa: sería él quien
// degradara la primera reactivación a ASSIGNED y la suite mediría una causa ajena
// a la rama en caliente que quiere certificar.
$activeForPrimary = (int)$pdo->query(
    "SELECT COUNT(*) FROM `incidents`
      WHERE `assigned_technician_id` = {$techId}
        AND `deleted_at` IS NULL
        AND `status` NOT IN ('CLOSED', 'CANCELLED')"
)->fetchColumn();

$assert('0.3 El técnico primario no arrastra intervenciones activas previas', $activeForPrimary === 0);

$coordinatorToken  = $authService->generateInternalToken($coordinator);
$technicianToken   = $authService->generateInternalToken($primaryTech);
$otherTechToken    = $authService->generateInternalToken($otherTech);
$authHeader        = static fn (string $token): array => ['Authorization' => 'Bearer ' . $token];

/** Caché de sesiones de sede: una por ubicación, emitida bajo demanda. */
$siteTokenCache = [];
$siteToken = function (int $locationId) use (&$siteTokenCache, $authService, $locationRepo): string {
    if (!isset($siteTokenCache[$locationId])) {
        $location = $locationRepo->findById($locationId, true);
        if (!$location instanceof Location) {
            throw new RuntimeException("La sede {$locationId} del banco de pruebas no existe.");
        }
        $siteTokenCache[$locationId] = $authService->generateSiteToken($location);
    }

    return $siteTokenCache[$locationId];
};

$madrid    = new DateTimeZone('Europe/Madrid');
$suffix    = strtoupper(bin2hex(random_bytes(3)));
$sequence  = 0;

/** @return array{machine_id: int, location_id: int} */
$nextMachine = function () use (&$machinePool): array {
    $machine = array_shift($machinePool);
    if ($machine === null) {
        throw new RuntimeException('Banco de pruebas agotado: la suite necesita una máquina libre por expediente.');
    }

    return [
        'machine_id'  => (int)$machine['machine_id'],
        'location_id' => (int)$machine['location_id'],
    ];
};

/** @return array{0: int, 1: string, 2: int, 3: int} Id, ticket, máquina y sede. */
$createAssignedTicket = function (int $technicianId) use (
    $incidentRepo,
    $coordinatorId,
    $suffix,
    &$sequence,
    $nextMachine
): array {
    $sequence++;
    $machine = $nextMachine();
    $ticketCode = sprintf('TST-P21-%s-%02d', $suffix, $sequence);

    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: $machine['machine_id'],
        locationId: $machine['location_id'],
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Avería del banco de pruebas del ciclo de vida HTTP del módulo 11.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentRepo->assign((int)$created->getId(), $technicianId, $coordinatorId);

    return [
        (int)$created->getId(),
        $ticketCode,
        $machine['machine_id'],
        $machine['location_id'],
    ];
};

$incidentRow = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incidents` WHERE `id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$historyRows = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incident_history` WHERE `incident_id` = :id ORDER BY `id` ASC');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$commentCount = function (int $incidentId) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `incident_comments` WHERE `incident_id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return (int)$stmt->fetchColumn();
};

/** Siembra el vencimiento contractual del expediente propio (instrumento de la suite). */
$seedDeadline = function (int $incidentId, string $deadline) use ($pdo): void {
    $pdo->prepare('UPDATE `incidents` SET `sla_target_at` = :deadline WHERE `id` = :id')
        ->execute([':deadline' => $deadline, ':id' => $incidentId]);
};

/**
 * Segundos transcurridos entre dos marcas de la base interpretadas como hora local
 * de sede; es la aritmética con la que el desplazamiento comercial se mide al segundo.
 */
$deadlineSeconds = static function (string $from, string $to) use ($madrid): int {
    return (new DateTimeImmutable($to, $madrid))->getTimestamp()
        - (new DateTimeImmutable($from, $madrid))->getTimestamp();
};

$pausePath            = static fn (int $id): string => "/api/technician/incidents/{$id}/pause-pending-info";
$resumePath           = static fn (int $id): string => "/api/technician/incidents/{$id}/resume-pending-info";
$coordinatorPausePath = static fn (int $id): string => "/api/coordinator/incidents/{$id}/pause-pending-info";
$coordinatorResumePath = static fn (int $id): string => "/api/coordinator/incidents/{$id}/resume-pending-info";
$cancelPath           = static fn (int $id): string => "/api/coordinator/incidents/{$id}/cancel-inactivity";
$commentPath          = static fn (int $id): string => "/api/location/incidents/{$id}/comments";
$pauseBody            = static fn (string $category, string $reason): array => [
    'reason_category' => $category,
    'reason_text'     => $reason,
];

// El vencimiento comercial de la suite: martes a las 12:15, dentro de la ventana
// 08:00-18:00 y con margen de sobra para que los desplazamientos de los escenarios
// (segundos o 90 minutos) aterricen en el mismo día hábil.
$commercialDeadline = (new DateTimeImmutable('next tuesday 12:15', $madrid))->format('Y-m-d H:i:s');
$deadlineMoment     = new DateTimeImmutable($commercialDeadline, $madrid);

$cycleId        = 0;
$cycleMachineId = 0;
$cycleLocationId = 0;
$refundIds      = [];

try {
    // =========================================================================
    // GRUPO 1: ciclo completo del técnico — pausa, respuesta de sede en caliente,
    //          segunda pausa y reanudación manual con desplazamiento acumulativo
    //          exacto de SLA (RF-01.1, RF-02.1, RF-02.2, RF-03.3, RF-04.1, RNF-01)
    // =========================================================================
    echo "\n--- Grupo 1: ciclo del técnico con respuesta de sede en caliente ---\n";

    [$cycleId, $cycleTicketCode, $cycleMachineId, $cycleLocationId] = $createAssignedTicket($techId);
    $seedDeadline($cycleId, $commercialDeadline);

    $assert(
        '1.0 Premisa del banco: el vencimiento sembrado cae en jornada comercial (martes 12:15)',
        (int)$deadlineMoment->format('N') === 2 && (int)$deadlineMoment->format('G') === 12
    );

    $pauseReason = 'El cuarto de máquinas está cerrado y el conserje no tiene llaves hasta mañana.';

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($cycleId),
        parsedBody: $pauseBody('BUILDING_CLOSED_NO_ACCESS', $pauseReason),
        headers: $authHeader($technicianToken)
    ));
    $pauseData = $res->getDecodedBody()['data'] ?? [];

    $assert(
        '1.1 Pausa #1 del técnico asignado => 200 OK con estado PENDING_INFO y etiqueta',
        $res->getStatusCode() === 200
            && ($res->getDecodedBody()['success'] ?? false) === true
            && ($pauseData['status'] ?? '') === 'PENDING_INFO'
            && ($pauseData['status_label'] ?? '') === 'Pendiente de información'
            && ($pauseData['ticket_code'] ?? '') === $cycleTicketCode
    );
    $assert(
        '1.2 La pausa congela el reloj y publica causa, etiqueta, justificación y SLA original intacto',
        ($pauseData['is_sla_paused'] ?? false) === true
            && ($pauseData['sla_target_at_original'] ?? '') === $commercialDeadline
            && array_key_exists('sla_target_at_shifted', $pauseData)
            && $pauseData['sla_target_at_shifted'] === null
            && ($pauseData['reason_category'] ?? '') === 'BUILDING_CLOSED_NO_ACCESS'
            && ($pauseData['reason_category_label'] ?? '') === 'Edificio cerrado / Sin acceso a instalaciones'
            && ($pauseData['reason_text'] ?? '') === $pauseReason
            && is_string($pauseData['paused_at'] ?? null)
            && $pauseData['paused_at'] !== ''
    );

    $cycleRow = $incidentRow($cycleId);
    $assert(
        '1.3 Persistencia real de la pausa: estado, marca, causa, texto, acumulador en cero y vencimiento intacto',
        ($cycleRow['status'] ?? '') === 'PENDING_INFO'
            && $cycleRow['paused_at'] !== null
            && ($cycleRow['pending_info_reason_category'] ?? '') === 'BUILDING_CLOSED_NO_ACCESS'
            && ($cycleRow['pending_info_reason_text'] ?? '') === $pauseReason
            && (int)($cycleRow['total_pending_info_seconds'] ?? -1) === 0
            && ($cycleRow['sla_target_at'] ?? '') === $commercialDeadline
            && (int)$pdo->query("SELECT `is_active` FROM `machines` WHERE `id` = {$cycleMachineId}")->fetchColumn() === 1
    );

    $pauseRows = array_values(array_filter(
        $historyRows($cycleId),
        static fn (array $row): bool => $row['from_status'] === 'ASSIGNED' && $row['to_status'] === 'PENDING_INFO'
    ));
    $assert(
        '1.4 El intervalo queda auditado con una única fila firmada por el técnico y su justificación (Art. III)',
        count($pauseRows) === 1
            && (int)($pauseRows[0]['user_id'] ?? 0) === $techId
            && str_contains((string)($pauseRows[0]['action_note'] ?? ''), ' Causa: ')
            && str_contains((string)($pauseRows[0]['action_note'] ?? ''), $pauseReason)
    );

    usleep(1_100_000); // Garantiza un intervalo de al menos un segundo real (RNF-01).

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $commentPath($cycleId),
        parsedBody: ['comment_text' => 'La conserjería ya ha abierto la sala; el técnico puede acceder ahora mismo.'],
        headers: $authHeader($siteToken($cycleLocationId))
    ));
    $hotData = $res->getDecodedBody()['data'] ?? [];

    $assert(
        '1.5 El comentario de sede en caliente reactiva el expediente a IN_PROGRESS (RF-02.1)',
        $res->getStatusCode() === 201
            && ($hotData['auto_resumed'] ?? false) === true
            && ($hotData['pause']['status'] ?? '') === 'IN_PROGRESS'
            && ($hotData['incident']['status'] ?? '') === 'IN_PROGRESS'
    );

    $hotRow              = $incidentRow($cycleId);
    $firstIntervalSeconds = (int)($hotRow['total_pending_info_seconds'] ?? 0);
    $shifted1            = (string)($hotData['pause']['sla_target_at_shifted'] ?? '');
    $assert(
        '1.6 El primer intervalo desplaza el vencimiento comercial al segundo exacto (RF-03.3, RNF-01)',
        $firstIntervalSeconds >= 1
            && $shifted1 !== ''
            && $deadlineSeconds($commercialDeadline, $shifted1) === $firstIntervalSeconds
            && $hotRow['paused_at'] === null
            && ($hotRow['sla_target_at'] ?? '') === $shifted1,
        sprintf(
            'intervalo=%d s, desplazamiento=%d s',
            $firstIntervalSeconds,
            $shifted1 === '' ? -1 : $deadlineSeconds($commercialDeadline, $shifted1)
        )
    );

    $shifted1Moment = new DateTimeImmutable($shifted1, $madrid);
    $assert(
        '1.7 El vencimiento desplazado sigue en jornada hábil 08:00-18:00 del mismo día',
        $shifted1 !== ''
            && (int)$shifted1Moment->format('N') <= 5
            && $shifted1Moment->format('Y-m-d') === $deadlineMoment->format('Y-m-d')
            && (int)$shifted1Moment->format('G') >= 8
            && (int)$shifted1Moment->format('G') < 18
    );
    $assert(
        '1.8 El contrato conserva los segundos en base y redondea los minutos de la interfaz (RNF-01)',
        (int)($hotData['pause']['accumulated_pause_minutes'] ?? -1) === (int)round($firstIntervalSeconds / 60)
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($cycleId),
        parsedBody: $pauseBody('EXTERNAL_POWER_CUT', 'La prueba en el cuadro eléctrico exige esperar al electricista del inmueble.'),
        headers: $authHeader($technicianToken)
    ));
    $pause2Data = $res->getDecodedBody()['data'] ?? [];

    $assert(
        '1.9 La segunda pausa es legal desde IN_PROGRESS y conserva el acumulador previo (RF-04.1)',
        $res->getStatusCode() === 200
            && ($pause2Data['status'] ?? '') === 'PENDING_INFO'
            && (int)($pause2Data['accumulated_pause_minutes'] ?? -1) === (int)round($firstIntervalSeconds / 60)
            && ($pause2Data['sla_target_at_original'] ?? '') === $shifted1
            && $pause2Data['sla_target_at_shifted'] === null
            && (int)($incidentRow($cycleId)['total_pending_info_seconds'] ?? -1) === $firstIntervalSeconds
    );

    usleep(1_100_000);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $resumePath($cycleId),
        parsedBody: ['resume_note' => 'Acceso concedido: reanudo el trabajo delante de la máquina.'],
        headers: $authHeader($technicianToken)
    ));
    $resume2Data = $res->getDecodedBody()['data'] ?? [];

    $assert(
        '1.10 Reanudación manual del técnico in situ => 200 con retorno a IN_PROGRESS (RF-02.2)',
        $res->getStatusCode() === 200
            && ($resume2Data['status'] ?? '') === 'IN_PROGRESS'
            && ($resume2Data['is_sla_paused'] ?? true) === false
            && $resume2Data['paused_at'] === null
            && ($resume2Data['sla_target_at_original'] ?? '') === $shifted1
    );

    $cycleFinalRow = $incidentRow($cycleId);
    $totalSeconds  = (int)($cycleFinalRow['total_pending_info_seconds'] ?? 0);
    $finalDeadline = (string)($cycleFinalRow['sla_target_at'] ?? '');
    $assert(
        '1.11 El desplazamiento acumulativo es la suma exacta de los dos intervalos (RF-04.1, RNF-01)',
        $totalSeconds >= 2
            && $totalSeconds > $firstIntervalSeconds
            && $finalDeadline !== ''
            && $deadlineSeconds($commercialDeadline, $finalDeadline) === $totalSeconds
            && ($resume2Data['sla_target_at_shifted'] ?? '') === $finalDeadline
            && ($cycleFinalRow['status'] ?? '') === 'IN_PROGRESS',
        sprintf(
            'total=%d s, primer intervalo=%d s, desplazamiento=%d s',
            $totalSeconds,
            $firstIntervalSeconds,
            $finalDeadline === '' ? -1 : $deadlineSeconds($commercialDeadline, $finalDeadline)
        )
    );

    $finalMoment = new DateTimeImmutable($finalDeadline, $madrid);
    $assert(
        '1.12 El vencimiento final sigue en la jornada comercial del mismo día hábil',
        $finalDeadline !== ''
            && (int)$finalMoment->format('N') <= 5
            && $finalMoment->format('Y-m-d') === $deadlineMoment->format('Y-m-d')
            && (int)$finalMoment->format('G') >= 8
            && (int)$finalMoment->format('G') < 18
    );

    $manualRows = array_values(array_filter(
        $historyRows($cycleId),
        static fn (array $row): bool => $row['from_status'] === 'PENDING_INFO'
            && $row['to_status'] === 'IN_PROGRESS'
            && (int)($row['user_id'] ?? 0) === $techId
            && str_contains((string)($row['action_note'] ?? ''), 'Acceso concedido')
    ));
    $assert(
        '1.13 El rastro de la reanudación manual viaja con la nota del técnico (Art. III)',
        count($manualRows) === 1
    );

    $siteRows = array_values(array_filter(
        $historyRows($cycleId),
        static fn (array $row): bool => $row['from_status'] === 'PENDING_INFO'
            && $row['to_status'] === 'IN_PROGRESS'
            && $row['user_id'] === null
    ));
    $assert(
        '1.14 La reactivación automática queda auditada como actor de sede sin usuario interno (Art. III)',
        count($siteRows) === 1
            && str_contains((string)($siteRows[0]['action_note'] ?? ''), sprintf('actor de sede #%d', $cycleLocationId))
    );

    // =========================================================================
    // GRUPO 2: pausa de coordinación y reactivación desfasada (>= 60 min)
    //          con desplazamiento comercial exacto (RF-02.1, RF-03.3)
    // =========================================================================
    echo "\n--- Grupo 2: pausa de coordinación y respuesta desfasada de la sede ---\n";

    [$coldId, $coldTicketCode, , $coldLocationId] = $createAssignedTicket($otherTechId);
    $seedDeadline($coldId, $commercialDeadline);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $coordinatorPausePath($coldId),
        parsedBody: $pauseBody('PENDING_SITE_AUTHORIZATION', 'La sede debe autorizar el acceso al área restringida antes de intervenir.'),
        headers: $authHeader($coordinatorToken)
    ));
    $coldPauseData = $res->getDecodedBody()['data'] ?? [];

    $assert(
        '2.1 Pausa por coordinación => 200 con reloj congelado y causa tipificada (RF-01.1)',
        $res->getStatusCode() === 200
            && ($coldPauseData['status'] ?? '') === 'PENDING_INFO'
            && ($coldPauseData['is_sla_paused'] ?? false) === true
            && ($coldPauseData['reason_category'] ?? '') === 'PENDING_SITE_AUTHORIZATION'
            && ($coldPauseData['sla_target_at_original'] ?? '') === $commercialDeadline
            && ($incidentRow($coldId)['status'] ?? '') === 'PENDING_INFO'
    );

    // Silencio de sede sembrado: 90 minutos naturales desde la pausa. Rebasar en
    // tiempo real la ventana en caliente de 60 minutos no cabe en una suite; el
    // instrumento es SQL dirigido sobre el expediente propio, como en las hermanas.
    $pdo->prepare('UPDATE `incidents` SET `paused_at` = DATE_SUB(NOW(), INTERVAL 90 MINUTE) WHERE `id` = :id')
        ->execute([':id' => $coldId]);

    $assert(
        '2.2 Premisa del desfase: el intervalo vivo ya supera los 60 minutos (RF-02.1)',
        (int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND, `paused_at`, NOW()) FROM `incidents` WHERE `id` = {$coldId}")->fetchColumn() >= 3600
    );

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $commentPath($coldId),
        parsedBody: ['comment_text' => 'Recepción confirma que el responsable del centro ya firmó la autorización de acceso.'],
        headers: $authHeader($siteToken($coldLocationId))
    ));
    $coldData = $res->getDecodedBody()['data'] ?? [];

    $assert(
        '2.3 La respuesta desfasada devuelve el expediente a ASSIGNED con auto_resumed (RF-02.1)',
        $res->getStatusCode() === 201
            && ($coldData['auto_resumed'] ?? false) === true
            && ($coldData['pause']['status'] ?? '') === 'ASSIGNED'
            && ($coldData['incident']['status'] ?? '') === 'ASSIGNED'
            && ($coldData['pause']['ticket_code'] ?? '') === $coldTicketCode
    );

    $coldRow     = $incidentRow($coldId);
    $coldSeconds = (int)($coldRow['total_pending_info_seconds'] ?? 0);
    $coldShifted = (string)($coldData['pause']['sla_target_at_shifted'] ?? '');
    $assert(
        '2.4 El desplazamiento comercial equivale al segundo a los 90 minutos pausados (RF-03.3, RNF-01)',
        $coldSeconds >= 5400
            && $coldShifted !== ''
            && $deadlineSeconds($commercialDeadline, $coldShifted) === $coldSeconds
            && ($coldRow['sla_target_at'] ?? '') === $coldShifted
            && $coldRow['paused_at'] === null
            && (int)($coldData['pause']['accumulated_pause_minutes'] ?? -1) === (int)round($coldSeconds / 60),
        sprintf(
            'acumulado=%d s, desplazamiento=%d s',
            $coldSeconds,
            $coldShifted === '' ? -1 : $deadlineSeconds($commercialDeadline, $coldShifted)
        )
    );

    $coldMoment = new DateTimeImmutable($coldShifted, $madrid);
    $assert(
        '2.5 El nuevo vencimiento cae en día hábil dentro de la jornada 08:00-18:00',
        $coldShifted !== ''
            && (int)$coldMoment->format('N') <= 5
            && $coldMoment->format('Y-m-d') === $deadlineMoment->format('Y-m-d')
            && (int)$coldMoment->format('G') >= 8
            && (int)$coldMoment->format('G') < 18
    );

    $coldRows = array_values(array_filter(
        $historyRows($coldId),
        static fn (array $row): bool => $row['from_status'] === 'PENDING_INFO'
            && $row['to_status'] === 'ASSIGNED'
            && str_contains((string)($row['action_note'] ?? ''), 'fuera de la ventana de 60 minutos')
    ));
    $assert(
        '2.6 El rastro explica la reactivación desfasada y actúa sin usuario interno (Art. III)',
        count($coldRows) === 1 && $coldRows[0]['user_id'] === null
    );

    // =========================================================================
    // GRUPO 3: reanudación manual de coordinación con destino explícito (RF-02.3)
    // =========================================================================
    echo "\n--- Grupo 3: reanudación manual de coordinación con destino elegido ---\n";

    [$manualId, , , ] = $createAssignedTicket($techId);
    $seedDeadline($manualId, $commercialDeadline);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $coordinatorPausePath($manualId),
        parsedBody: $pauseBody('MACHINE_LOCATION_NOT_FOUND', 'La máquina no aparece en la planta indicada por la sede; se requiere aclaración.'),
        headers: $authHeader($coordinatorToken)
    ));
    $assert(
        '3.1 Pausa por coordinación sobre un expediente en ruta => 200 PENDING_INFO',
        $res->getStatusCode() === 200
            && ($res->getDecodedBody()['data']['status'] ?? '') === 'PENDING_INFO'
    );

    usleep(1_100_000);

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $coordinatorResumePath($manualId),
        parsedBody: [
            'target_status' => 'IN_PROGRESS',
            'resume_note'   => 'Central confirma que el técnico ya está delante del equipo.',
        ],
        headers: $authHeader($coordinatorToken)
    ));
    $manualData = $res->getDecodedBody()['data'] ?? [];
    $manualRow  = $incidentRow($manualId);

    $assert(
        '3.2 La reanudación manual respeta el destino elegido y cierra el reloj (RF-02.3)',
        $res->getStatusCode() === 200
            && ($manualData['status'] ?? '') === 'IN_PROGRESS'
            && ($manualRow['status'] ?? '') === 'IN_PROGRESS'
            && ($manualData['is_sla_paused'] ?? true) === false
    );

    $manualSeconds  = (int)($manualRow['total_pending_info_seconds'] ?? 0);
    $manualOriginal = (string)($manualData['sla_target_at_original'] ?? '');
    $manualShifted  = (string)($manualData['sla_target_at_shifted'] ?? '');
    $assert(
        '3.3 El desplazamiento del intervalo manual es exacto y queda persistido (RF-03.3, RNF-01)',
        $manualSeconds >= 1
            && $manualOriginal === $commercialDeadline
            && $manualShifted !== ''
            && $deadlineSeconds($commercialDeadline, $manualShifted) === $manualSeconds
            && ($manualRow['sla_target_at'] ?? '') === $manualShifted
    );

    $manualMoment = new DateTimeImmutable($manualShifted, $madrid);
    $assert(
        '3.4 El vencimiento manual queda dentro de la ventana comercial del mismo día',
        $manualShifted !== ''
            && (int)$manualMoment->format('N') <= 5
            && $manualMoment->format('Y-m-d') === $deadlineMoment->format('Y-m-d')
            && (int)$manualMoment->format('G') >= 8
            && (int)$manualMoment->format('G') < 18
    );

    $coordinatorRows = array_values(array_filter(
        $historyRows($manualId),
        static fn (array $row): bool => $row['from_status'] === 'PENDING_INFO'
            && $row['to_status'] === 'IN_PROGRESS'
            && (int)($row['user_id'] ?? 0) === $coordinatorId
            && str_contains((string)($row['action_note'] ?? ''), 'Central confirma')
    ));
    $assert(
        '3.5 La nota del coordinador queda auditada en el rastro inmutable (Art. III)',
        count($coordinatorRows) === 1
    );

    // =========================================================================
    // GRUPO 4: cancelación formal por inactividad de 72 h hábiles (RF-04.2 a RF-04.5)
    //          sobre el expediente que ya acumuló pausas y reanudaciones
    // =========================================================================
    echo "\n--- Grupo 4: cancelación por inactividad tras una espera real de 72 h hábiles ---\n";

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($cycleId),
        parsedBody: $pauseBody('BUILDING_CLOSED_NO_ACCESS', 'El edificio vuelve a estar cerrado sin conserjería ni llaves de acceso.'),
        headers: $authHeader($technicianToken)
    ));
    $assert(
        '4.1 El expediente vuelve a pausarse conservando el acumulador de los intervalos previos (RF-04.1)',
        $res->getStatusCode() === 200
            && ($res->getDecodedBody()['data']['status'] ?? '') === 'PENDING_INFO'
            && (int)($incidentRow($cycleId)['total_pending_info_seconds'] ?? -1) === $totalSeconds
    );

    // Catorce días naturales contienen siempre 90 horas de ventana comercial (dos
    // semanas completas menos el recorte de los extremos), de modo que la alerta de
    // espera prolongada es verdadera sin depender del día en que corra la suite.
    $pdo->prepare('UPDATE `incidents` SET `paused_at` = DATE_SUB(NOW(), INTERVAL 14 DAY) WHERE `id` = :id')
        ->execute([':id' => $cycleId]);
    $cyclePausedRow = $incidentRow($cycleId);

    $assert(
        '4.2 La espera sembrada supera las 72 horas hábiles reales (RF-04.2)',
        $pauseService->isProlongedInactivitySince(
            (string)($cyclePausedRow['paused_at'] ?? ''),
            ($cyclePausedRow['status'] ?? '') === 'PENDING_INFO'
        ) === true
    );

    // Reintegro del consumidor en inspección: debe sobrevivir a la cancelación.
    $pdo->prepare("
        INSERT INTO `refund_requests` (
            `incident_id`, `machine_id`, `location_id`, `claimant_name`, `claimant_contact`,
            `claimed_amount`, `product_attempted`, `compensation_method`, `bizum_phone`,
            `tracking_token`, `status`
        ) VALUES (
            :incident, :machine, :location, 'Consumidor de la suite de ciclo de vida', '600000023',
            3.20, 'Bocadillo de atún', 'BIZUM', '600000023',
            :token, 'PENDING_INSPECTION'
        )
    ")->execute([
        ':incident' => $cycleId,
        ':machine'  => $cycleMachineId,
        ':location' => $cycleLocationId,
        ':token'    => 'tstp21-' . $suffix,
    ]);
    $refundId = (int)$pdo->lastInsertId();
    $refundIds[] = $refundId;

    $assert('4.3 Reintegro del consumidor vinculado al expediente antes de cancelar', $refundId > 0);

    // La cuenta de rastros se toma como DELTA sobre la foto previa: `audit_log` es
    // append-only y el arnés no la purga (inviolabilidad del Art. III), así que una
    // corrida anterior puede dejar filas huérfanas que reutilicen los mismos
    // identificadores tras el resembrado.
    $auditCount = function (string $action, int $entityId, string $entityType = 'TICKET') use ($pdo): int {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM `audit_log` WHERE `action` = :action AND `entity_type` = :type AND `entity_id` = :entity');
        $stmt->execute([':action' => $action, ':type' => $entityType, ':entity' => $entityId]);

        return (int)$stmt->fetchColumn();
    };
    $auditBaseline = [
        'incident' => $auditCount('INCIDENT_CANCELLED', $cycleId),
        'machine'  => $auditCount('MACHINE_BLOCKED_NO_ACCESS', $cycleMachineId, 'MACHINE'),
        'refund'   => $auditCount('REFUND_DETACHED_BY_INACTIVITY', $refundId, 'REFUND_REQUEST'),
    ];

    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $cancelPath($cycleId),
        parsedBody: ['cancellation_reason' => 'Cierre administrativo por inactividad y falta de acceso del cliente tras el plazo hábil.'],
        headers: $authHeader($coordinatorToken)
    ));
    $cancelData = $res->getDecodedBody()['data'] ?? [];

    $assert(
        '4.4 Cancelación por inactividad => 200 con el protocolo confirmado',
        $res->getStatusCode() === 200
            && ($cancelData['status'] ?? '') === 'CANCELLED'
            && ($cancelData['machine']['is_blocked_no_access'] ?? false) === true
            && ($cancelData['refunds_preserved'] ?? false) === true
    );

    $cancelledRow = $incidentRow($cycleId);
    $assert(
        '4.5 El ticket queda cancelado con motivo y fecha, y conserva el acumulador de pausas previas (RF-04.3)',
        ($cancelledRow['status'] ?? '') === 'CANCELLED'
            && ($cancelledRow['cancelled_at'] ?? null) !== null
            && str_contains((string)($cancelledRow['cancellation_reason'] ?? ''), 'Cierre administrativo')
            && (int)($cancelledRow['total_pending_info_seconds'] ?? -1) === $totalSeconds
    );

    $machineRow = $pdo->query('SELECT `is_active`, `is_blocked_no_access` FROM `machines` WHERE `id` = ' . $cycleMachineId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        '4.6 La máquina NO vuelve a Operativa: fuera de servicio por falta de acceso (RF-04.4, Art. V.1)',
        (int)($machineRow['is_active'] ?? 1) === 0
            && (int)($machineRow['is_blocked_no_access'] ?? 0) === 1
            && ($cancelData['machine']['operational_status'] ?? '') === 'BLOCKED_NO_ACCESS'
    );

    $refundRow = $pdo->query('SELECT * FROM `refund_requests` WHERE `id` = ' . $refundId)->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        '4.7 El reintegro del consumidor se preserva desvinculado y activo (RF-04.5)',
        array_key_exists('incident_id', $refundRow)
            && $refundRow['incident_id'] === null
            && ($refundRow['status'] ?? '') === 'REQUIRES_COORDINATOR_APPROVAL'
            && (int)($refundRow['is_active'] ?? 0) === 1
    );

    $assert(
        '4.8 El protocolo deja sus tres rastros inmutables (Art. III)',
        $auditCount('INCIDENT_CANCELLED', $cycleId) - $auditBaseline['incident'] === 1
            && $auditCount('MACHINE_BLOCKED_NO_ACCESS', $cycleMachineId, 'MACHINE') - $auditBaseline['machine'] === 1
            && $auditCount('REFUND_DETACHED_BY_INACTIVITY', $refundId, 'REFUND_REQUEST') - $auditBaseline['refund'] === 1
    );

    $commentsBefore = $commentCount($cycleId);
    $historyBefore  = count($historyRows($cycleId));
    $res = $router->dispatch(new Request(
        method: 'POST',
        path: $commentPath($cycleId),
        parsedBody: ['comment_text' => 'La sede intenta responder después del cierre administrativo.'],
        headers: $authHeader($siteToken($cycleLocationId))
    ));
    $assert(
        '4.9 El hilo sellado rechaza el comentario de sede sin escribir nada (RF-05.4)',
        $res->getStatusCode() === 403
            && ($res->getDecodedBody()['error']['code'] ?? '') === 'CONVERSATION_SEALED'
            && $commentCount($cycleId) === $commentsBefore
            && count($historyRows($cycleId)) === $historyBefore
    );

    // =========================================================================
    // GRUPO 5: RNF-02 medido — latencia de pausa y reanudación por el router real
    // =========================================================================
    echo "\n--- Grupo 5: RNF-02 — latencia de las transiciones (< 200 ms) ---\n";

    [$benchId, , , ] = $createAssignedTicket($otherTechId);

    // Sin `sla_target_at`: la avería no arrastra compromiso contractual y el módulo
    // no puede inventarle uno al pausar ni al reanudar (decisión de T-PAUSE-12/13).
    $benchPause = static fn (): Request => new Request(
        method: 'POST',
        path: $pausePath($benchId),
        parsedBody: $pauseBody('MACHINE_LOCATION_NOT_FOUND', 'Prueba de latencia de la transición de pausa del módulo 11.'),
        headers: $authHeader($otherTechToken)
    );
    $benchResume = static fn (): Request => new Request(
        method: 'POST',
        path: $resumePath($benchId),
        parsedBody: [],
        headers: $authHeader($otherTechToken)
    );

    // Calentamiento fuera de la muestra.
    $warmPause  = $router->dispatch($benchPause());
    $warmResume = $router->dispatch($benchResume());
    $warmData   = $warmResume->getDecodedBody()['data'] ?? [];

    $benchRow = $incidentRow($benchId);
    $assert(
        '5.0 Una avería sin compromiso de SLA no finge vencimiento al pausar ni al reanudar (RNF-01)',
        $warmPause->getStatusCode() === 200
            && $warmResume->getStatusCode() === 200
            && array_key_exists('sla_target_at_original', $warmData)
            && $warmData['sla_target_at_original'] === null
            && array_key_exists('sla_target_at_shifted', $warmData)
            && $warmData['sla_target_at_shifted'] === null
            && array_key_exists('sla_target_at', $benchRow)
            && $benchRow['sla_target_at'] === null
    );

    $pauseSamples    = [];
    $resumeSamples   = [];
    $pauseAllOk      = true;
    $resumeAllOk     = true;
    for ($i = 0; $i < 7; $i++) {
        $startedAt = microtime(true);
        $pauseResponse = $router->dispatch($benchPause());
        $pauseSamples[] = (microtime(true) - $startedAt) * 1000;
        $pauseAllOk = $pauseAllOk && $pauseResponse->getStatusCode() === 200;

        $startedAt = microtime(true);
        $resumeResponse = $router->dispatch($benchResume());
        $resumeSamples[] = (microtime(true) - $startedAt) * 1000;
        $resumeAllOk = $resumeAllOk && $resumeResponse->getStatusCode() === 200;
    }

    sort($pauseSamples);
    sort($resumeSamples);
    $pauseMedian  = $pauseSamples[intdiv(count($pauseSamples), 2)];
    $resumeMedian = $resumeSamples[intdiv(count($resumeSamples), 2)];

    echo sprintf(
        "  [MEDICIÓN] Pausa: min %.2f ms | mediana %.2f ms | max %.2f ms | muestras [%s]\n",
        $pauseSamples[0],
        $pauseMedian,
        $pauseSamples[count($pauseSamples) - 1],
        implode(', ', array_map(static fn (float $ms): string => number_format($ms, 2), $pauseSamples))
    );
    echo sprintf(
        "  [MEDICIÓN] Reanudación: min %.2f ms | mediana %.2f ms | max %.2f ms | muestras [%s]\n",
        $resumeSamples[0],
        $resumeMedian,
        $resumeSamples[count($resumeSamples) - 1],
        implode(', ', array_map(static fn (float $ms): string => number_format($ms, 2), $resumeSamples))
    );

    $assert(
        '5.1 RNF-02: las 7 pausas responden 200 y su mediana queda por debajo de 200 ms',
        $pauseAllOk && $pauseMedian < TRANSITION_BUDGET_MS,
        sprintf('mediana real: %.2f ms (presupuesto %.0f ms)', $pauseMedian, TRANSITION_BUDGET_MS)
    );
    $assert(
        '5.2 RNF-02: las 7 reanudaciones responden 200 y su mediana queda por debajo de 200 ms',
        $resumeAllOk && $resumeMedian < TRANSITION_BUDGET_MS,
        sprintf('mediana real: %.2f ms (presupuesto %.0f ms)', $resumeMedian, TRANSITION_BUDGET_MS)
    );
    $assert(
        '5.3 Ninguna muestra supera el guardián anti-outlier de 3× el presupuesto',
        $pauseSamples[count($pauseSamples) - 1] < TRANSITION_OUTLIER_CEILING_MS
            && $resumeSamples[count($resumeSamples) - 1] < TRANSITION_OUTLIER_CEILING_MS,
        sprintf(
            'peores casos: pausa %.2f ms, reanudación %.2f ms (techo %.0f ms)',
            $pauseSamples[count($pauseSamples) - 1],
            $resumeSamples[count($resumeSamples) - 1],
            TRANSITION_OUTLIER_CEILING_MS
        )
    );

} finally {
    echo "\n--- Limpieza de los expedientes propios de la suite ---\n";

    // Los reintegros desvinculados quedan con `incident_id = NULL`: se reenganchan a su
    // avería para que el purgado dirigido los arrastre como hijas.
    foreach ($refundIds as $refundId) {
        $pdo->prepare('UPDATE `refund_requests` SET `incident_id` = :incident WHERE `id` = :refund')
            ->execute([':incident' => $cycleId, ':refund' => $refundId]);
    }

    // La cancelación por inactividad deja la máquina fuera de servicio (RF-04.4): se
    // restituye para no desviar el inventario de las suites posteriores.
    if ($cycleMachineId > 0) {
        $pdo->prepare('UPDATE `machines` SET `is_active` = 1, `is_blocked_no_access` = 0 WHERE `id` = :id')
            ->execute([':id' => $cycleMachineId]);
    }

    $purged = TestDataCleaner::purgeIncidentsMatchingTicket($pdo, 'TST-P21-%');
    echo '  [OK] Expedientes purgados con TestDataCleaner (patrón TST-P21-%) · filas de incidencias eliminadas: '
        . array_sum($purged) . " (máquinas y sedes intactas)\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
echo $failures === 0
    ? " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-21 CUMPLIDA.\n"
    : " RESULTADO: HAY FALLOS. CONDICIÓN T-PAUSE-21 NO CUMPLIDA.\n";
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
