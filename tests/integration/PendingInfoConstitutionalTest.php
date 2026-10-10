<?php

declare(strict_types=1);

/**
 * PendingInfoConstitutionalTest (T-PAUSE-22)
 *
 * Certifica el BLINDAJE CONSTITUCIONAL del módulo 11 contra MariaDB real y a través
 * del enrutador con sus middlewares de verdad (sin invocar ningún controlador a mano),
 * cubriendo los cinco puntos del «Hecho cuando» de la tarea más la integridad del
 * Art. II que las decisiones ratificadas por el Product Owner en T-PAUSE-22 exigen:
 *
 *   1. **Art. II — Cuarentena sanitaria automática y bloqueo de resolución** (RF-03.5.1,
 *      RF-03.5.2): en una máquina bajo vigilancia sanitaria con 4 horas naturales
 *      continuas sin frío confirmado, el intento de cierre consume el Reloj Sanitario
 *      Biológico (estado `QUARANTINE` + evento `SANITARY_QUARANTINE_AUTO_TRIGGERED`) y
 *      la resolución queda bloqueada hasta declarar temperatura real, retirada y
 *      destrucción del stock perecedero y checklist de higienización. La enmienda
 *      ratificada extiende la vigilancia a la máquina mixta (`COMBO`) y deja fuera,
 *      a propósito, a las tipologías sin producto fresco.
 *   2. **Art. III — Inmutabilidad de `incident_history`**: ninguna sentencia de
 *      `UPDATE`/`DELETE` apunta al historial en todo `src/`, el único camino de
 *      escritura es la inserción, y las filas ya escritas de un expediente siguen
 *      idénticas byte a byte después de transiciones posteriores.
 *   3. **Art. V.1 — Justificación mínima y máquina fuera de servicio** (RF-04.3,
 *      RF-04.4): la cancelación de una espera prolongada exige 20 caracteres reales
 *      y no vuelve a "Operativa": `is_active = 0` + `is_blocked_no_access = 1` con
 *      estado operativo formal `BLOCKED_NO_ACCESS`. El cierre técnico también exige
 *      su justificación de 20 caracteres.
 *   4. **Art. V.2 — Confirmación de acceso en avisos sobre máquinas bloqueadas**
 *      (RF-04.6): el alta de la sede se intercepta con `422 ACCESS_CONFIRMATION_REQUIRED`
 *      si no marca la casilla; con la confirmación, el aviso nuevo devuelve la máquina
 *      al parque activo, anexa el levantamiento a las notas conservando el bloqueo y
 *      deja el evento inmutable `MACHINE_UNBLOCKED_BY_ACCESS_CONFIRMATION`.
 *   5. **Art. V.4 — Anonimización del QR ciudadano** (RF-06.1): el escaneo público de
 *      un expediente en `PENDING_INFO` sirve el estado neutral «En proceso de atención
 *      técnica» y jamás asoma el motivo interno de la pausa.
 *
 * Además certifica la **puerta trasera cerrada en el módulo 05**: el desbloqueo
 * estacional ya no puede retirar una cuarentena sanitaria (ni por la ruta HTTP del
 * coordinador ni por el repositorio), y el camino legítimo —una máquina realmente en
 * pausa estacional— sigue funcionando.
 *
 * Límites declarados: los desfases del reloj natural (5 h) se siembran con SQL
 * dirigido sobre el expediente propio, porque esperar horas reales no cabe en una
 * suite; y el parque maestro se restituye íntegro en el `finally` (estado, bloqueo,
 * semáforo sanitario y notas) además del purgado dirigido del arnés.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
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
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Integración - PendingInfoConstitutionalTest (T-PAUSE-22)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router        = AppRouter::create();
$incidentRepo  = new PdoIncidentRepository($pdo);
$machineRepo   = new PdoMachineRepository($pdo);
$locationRepo  = new PdoLocationRepository($pdo);
$userRepo      = new PdoUserRepository($pdo);
$settingsRepo  = new PdoPreventiveSettingsRepository($pdo);
$authService   = new AuthService($locationRepo, $userRepo);

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

$suffix   = strtoupper(bin2hex(random_bytes(3)));
$sequence = 0;

// -----------------------------------------------------------------------
// Grupo 0: banco de pruebas y actores
// -----------------------------------------------------------------------

/** @return list<array{machine_id: int, location_id: int, code: string}> */
$freeMachinesByType = function (string $machineType, int $limit) use ($pdo): array {
    $stmt = $pdo->prepare(
        "SELECT m.`id` AS machine_id, m.`location_id`, m.`code`
           FROM `machines` m
           JOIN `locations` l ON l.`id` = m.`location_id`
          WHERE m.`is_active` = 1
            AND m.`is_blocked_no_access` = 0
            AND m.`deleted_at` IS NULL
            AND l.`is_active` = 1
            AND l.`deleted_at` IS NULL
            AND m.`machine_type` = :machine_type
            AND NOT EXISTS (
                SELECT 1 FROM `incidents` i
                 WHERE i.`machine_id` = m.`id`
                   AND i.`deleted_at` IS NULL
                   AND i.`status` NOT IN ('CLOSED', 'CANCELLED')
            )
          ORDER BY m.`id`
          LIMIT {$limit}"
    );
    $stmt->execute([':machine_type' => $machineType]);

    return array_map(
        static fn (array $row): array => [
            'machine_id'  => (int)$row['machine_id'],
            'location_id' => (int)$row['location_id'],
            'code'        => (string)$row['code'],
        ],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
};

$perishablePool = $freeMachinesByType('PERISHABLE_FOOD', 3);
$comboPool      = $freeMachinesByType('COMBO', 2);
$snackPool      = $freeMachinesByType('SNACKS', 2);
$hotDrinkPool   = $freeMachinesByType('HOT_DRINKS', 2);

$assert(
    '0.1 Banco de pruebas suficiente: 1 perecedera, 1 mixta, 2 snacks y 2 bebidas calientes libres',
    count($perishablePool) >= 1 && count($comboPool) >= 1 && count($snackPool) >= 2 && count($hotDrinkPool) >= 2,
    sprintf(
        'Parque libre: perecederas=%d combos=%d snacks=%d calientes=%d',
        count($perishablePool),
        count($comboPool),
        count($snackPool),
        count($hotDrinkPool)
    )
);

if (count($perishablePool) < 1 || count($comboPool) < 1 || count($snackPool) < 2 || count($hotDrinkPool) < 2) {
    echo "ERROR FATAL: el parque no expone las tipologías que la suite necesita libres.\n";
    exit(1);
}

$technicianRows = $pdo->query(
    "SELECT `id` FROM `users`
      WHERE `role` = 'TECHNICIAN' AND `deleted_at` IS NULL
      ORDER BY `id` LIMIT 1"
)->fetchAll(PDO::FETCH_COLUMN);

$technician  = $userRepo->findById((int)($technicianRows[0] ?? 0));
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$assert(
    '0.2 Semilla disponible: técnico de ruta y coordinador de operaciones',
    $technician instanceof User && $coordinator instanceof User
);

if (!$technician instanceof User || !$coordinator instanceof User) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$techId        = (int)$technician->getId();
$coordinatorId = (int)$coordinator->getId();

$technicianToken  = $authService->generateInternalToken($technician);
$coordinatorToken = $authService->generateInternalToken($coordinator);
$authHeader       = static fn (string $token): array => ['Authorization' => 'Bearer ' . $token];

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

/** @return int Id del expediente creado en IN_PROGRESS y asignado al técnico. */
$createInProgressTicket = function (array $machine) use ($incidentRepo, $techId, $coordinatorId, $suffix, &$sequence): int {
    $sequence++;
    $ticketCode = sprintf('TST-P22-%s-%02d', $suffix, $sequence);

    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: $machine['machine_id'],
        locationId: $machine['location_id'],
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Expediente del blindaje constitucional del módulo 11 (T-PAUSE-22).',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentId = (int)$created->getId();
    $incidentRepo->assign($incidentId, $techId, $coordinatorId);
    $incidentRepo->startIntervention($incidentId, $techId);

    return $incidentId;
};

/** @return int Id del expediente creado y asignado (sin iniciar intervención). */
$createAssignedTicket = function (array $machine) use ($incidentRepo, $techId, $coordinatorId, $suffix, &$sequence): int {
    $sequence++;
    $ticketCode = sprintf('TST-P22-%s-%02d', $suffix, $sequence);

    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: $machine['machine_id'],
        locationId: $machine['location_id'],
        category: IncidentCategory::ELECTRICAL_OFF,
        description: 'Expediente del blindaje constitucional del módulo 11 (T-PAUSE-22).',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentId = (int)$created->getId();
    $incidentRepo->assign($incidentId, $techId, $coordinatorId);

    return $incidentId;
};

/** Siembra el envejecimiento natural del expediente propio (instrumento de la suite). */
$backdateOpening = function (int $incidentId, int $hours) use ($pdo): void {
    $pdo->prepare('UPDATE `incidents` SET `created_at` = NOW() - INTERVAL :hours HOUR WHERE `id` = :id')
        ->execute([':hours' => $hours, ':id' => $incidentId]);
};

/**
 * Envejece la espera de sede del expediente propio.
 *
 * Esperar 72 h hábiles reales no cabe en una suite: se siembran 14 días naturales
 * (dos semanas completas contienen 90 h de ventana comercial, así que la frontera
 * queda superada sea cual sea el día y la hora en que corra la suite).
 */
$backdatePause = function (int $incidentId, int $days) use ($pdo): void {
    $pdo->prepare('UPDATE `incidents` SET `paused_at` = NOW() - INTERVAL :days DAY WHERE `id` = :id')
        ->execute([':days' => $days, ':id' => $incidentId]);
};

$incidentRow = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incidents` WHERE `id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$machineRow = function (int $machineId) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT `id`, `code`, `machine_type`, `is_active`, `is_blocked_no_access`, `sanitary_status`, `notes` FROM `machines` WHERE `id` = :id'
    );
    $stmt->execute([':id' => $machineId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$historyRows = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT `id`, `incident_id`, `user_id`, `from_status`, `to_status`, `action_note`, `created_at`
           FROM `incident_history` WHERE `incident_id` = :id ORDER BY `id` ASC'
    );
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

/**
 * Cursor de auditoría: `audit_log` es append-only y el arnés NO la purga entre
 * suites, así que todo recuento de eventos se mide por delta sobre esta foto
 * (precedente ratificado en T-PAUSE-13/14 y declarado en T-PAUSE-21).
 */
$auditCursor = static function () use ($pdo): int {
    return (int)$pdo->query('SELECT COALESCE(MAX(`id`), 0) FROM `audit_log`')->fetchColumn();
};

/** @return list<array<string, mixed>> Eventos de auditoría nuevos desde el cursor. */
$auditEvents = function (string $entityType, int $entityId, string $action, int $sinceId = 0) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT `action`, `entity_type`, `entity_id`, `user_role`, `user_name`, `previous_state`, `new_state`, `metadata`, `created_at`
           FROM `audit_log`
          WHERE `entity_type` = :entity_type AND `entity_id` = :entity_id AND `action` = :action
            AND `id` > :since_id
          ORDER BY `id` ASC'
    );
    $stmt->execute([':entity_type' => $entityType, ':entity_id' => $entityId, ':action' => $action, ':since_id' => $sinceId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$machineSnapshot = function (int $machineId) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT `is_active`, `is_blocked_no_access`, `sanitary_status`, `notes`, `next_sanitary_inspection_due`
           FROM `machines` WHERE `id` = :id'
    );
    $stmt->execute([':id' => $machineId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$restoreMachine = function (int $machineId, array $snapshot) use ($pdo): void {
    if ($snapshot === []) {
        return;
    }

    $pdo->prepare(
        'UPDATE `machines`
            SET `is_active` = :is_active,
                `is_blocked_no_access` = :is_blocked,
                `sanitary_status` = :sanitary_status,
                `notes` = :notes,
                `next_sanitary_inspection_due` = :next_due
          WHERE `id` = :id'
    )->execute([
        ':is_active'       => (int)$snapshot['is_active'],
        ':is_blocked'      => (int)$snapshot['is_blocked_no_access'],
        ':sanitary_status' => (string)$snapshot['sanitary_status'],
        ':notes'           => $snapshot['notes'],
        ':next_due'        => $snapshot['next_sanitary_inspection_due'],
        ':id'              => $machineId,
    ]);
};

$validDiagnosis = 'Termostato descalibrado con lectura real de 11 grados en el recinto.';
$validAction    = 'Sustituida sonda NTC y estabilizado el ciclo de frío a 3,5 grados.';

$resolvePath           = static fn (int $id): string => "/api/technician/incidents/{$id}/resolve";
$myRoutePath           = '/api/technician/my-route';
$pausePath             = static fn (int $id): string => "/api/technician/incidents/{$id}/pause-pending-info";
$cancelPath            = static fn (int $id): string => "/api/coordinator/incidents/{$id}/cancel-inactivity";
$preventiveConfigPath  = static fn (int $id): string => "/api/coordinator/machines/{$id}/preventive-config";
$siteMachinesPath      = static fn (string $siteCode): string => "/api/locations/{$siteCode}/machines";
$reopenPath            = static fn (string $ticketCode): string => "/api/incidents/{$ticketCode}/reopen";
$qrScanPath            = static fn (string $code): string => "/api/qr/scan/{$code}";

/** @var array<int, array<string, mixed>> Instantáneas del parque tocado, para el finally. */
$touchedMachines = [];
$touchMachine = function (int $machineId) use (&$touchedMachines, $machineSnapshot): void {
    if (!isset($touchedMachines[$machineId])) {
        $touchedMachines[$machineId] = $machineSnapshot($machineId);
    }
};

$snackMachine      = $snackPool[0];
$quarantineMachine = null;

try {
    // =========================================================================
    // GRUPO 1: Art. II — Cuarentena automática y resolución bloqueada
    // =========================================================================
    echo "\n--- Grupo 1: Art. II — Reloj sanitario, cuarentena y bloqueo de resolución ---\n";

    $perishableMachine = $perishablePool[0];
    $touchMachine($perishableMachine['machine_id']);

    $sanitaryIncidentId = $createInProgressTicket($perishableMachine);
    $backdateOpening($sanitaryIncidentId, 5);

    $preClockRow = $machineRow($perishableMachine['machine_id']);
    $assert(
        '1.0 Premisa del banco: la perecedera está libre, con semáforo OK y 5 h naturales de avería sembradas',
        ($preClockRow['sanitary_status'] ?? '') === 'OK'
            && (int)($preClockRow['is_blocked_no_access'] ?? 0) === 0
            && ((new DateTimeImmutable((string)($incidentRow($sanitaryIncidentId)['created_at'] ?? 'now')))->getTimestamp() <= time() - 14400)
    );

    // 1.1 La ruta del técnico anticipa el bloqueo sin escribir nada al pintar la pantalla.
    $routeResponse = $router->dispatch(new Request(
        method: 'GET',
        path: $myRoutePath,
        headers: $authHeader($technicianToken)
    ));
    $routeStops = $routeResponse->getDecodedBody()['data'] ?? [];
    $routeStop  = null;
    foreach ($routeStops as $stop) {
        if ((int)($stop['id'] ?? 0) === $sanitaryIncidentId) {
            $routeStop = $stop;
            break;
        }
    }

    $assert(
        '1.1 La parada de Mi Ruta publica sanitary_resolution_required=true sin disparar la cuarentena todavía',
        $routeResponse->getStatusCode() === 200
            && is_array($routeStop)
            && ($routeStop['sanitary_resolution_required'] ?? false) === true
            && ($machineRow($perishableMachine['machine_id'])['sanitary_status'] ?? '') === 'OK'
    );

    // 1.2 El intento de cierre sin declaraciones consume el reloj y bloquea la resolución.
    $auditBeforeClock = $auditCursor();
    $resolveResponse = $router->dispatch(new Request(
        method: 'POST',
        path: $resolvePath($sanitaryIncidentId),
        parsedBody: [
            'resolution_diagnosis' => $validDiagnosis,
            'resolution_action'    => $validAction,
        ],
        headers: $authHeader($technicianToken)
    ));
    $resolveBody = $resolveResponse->getDecodedBody();

    $assert(
        '1.2 Resolver en cuarentena sin declaraciones => 422 SANITARY_CHECKLIST_REQUIRED (Art. II)',
        $resolveResponse->getStatusCode() === 422
            && ($resolveBody['error']['code'] ?? '') === 'SANITARY_CHECKLIST_REQUIRED'
            && ($incidentRow($sanitaryIncidentId)['status'] ?? '') === 'IN_PROGRESS'
    );

    $postClockRow = $machineRow($perishableMachine['machine_id']);
    $clockEvents  = $auditEvents('MACHINE', $perishableMachine['machine_id'], 'SANITARY_QUARANTINE_AUTO_TRIGGERED', $auditBeforeClock);
    $clockState   = json_decode((string)($clockEvents[0]['new_state'] ?? '{}'), true) ?: [];

    $assert(
        '1.3 El intento de cierre activó QUARANTINE y dejó el evento inmutable con umbral de 14.400 s',
        ($postClockRow['sanitary_status'] ?? '') === 'QUARANTINE'
            && count($clockEvents) === 1
            && ($clockState['sanitary_status'] ?? '') === 'QUARANTINE'
            && ($clockState['sanitary_checklist_required'] ?? false) === true
            && str_contains((string)($clockEvents[0]['metadata'] ?? ''), '14400')
            && str_contains((string)($clockEvents[0]['metadata'] ?? ''), (string)$sanitaryIncidentId)
    );

    // 1.4 Declaraciones incompletas o increíbles: la puerta no se abre.
    $incompleteResponse = $router->dispatch(new Request(
        method: 'POST',
        path: $resolvePath($sanitaryIncidentId),
        parsedBody: [
            'resolution_diagnosis' => $validDiagnosis,
            'resolution_action'    => $validAction,
            'sanitary_declarations' => [
                'stock_destroyed'   => true,
                'hygiene_checklist' => true,
            ],
        ],
        headers: $authHeader($technicianToken)
    ));

    $uncheckedResponse = $router->dispatch(new Request(
        method: 'POST',
        path: $resolvePath($sanitaryIncidentId),
        parsedBody: [
            'resolution_diagnosis' => $validDiagnosis,
            'resolution_action'    => $validAction,
            'sanitary_declarations' => [
                'temperature_c'     => 3.5,
                'stock_destroyed'   => true,
                'hygiene_checklist' => false,
            ],
        ],
        headers: $authHeader($technicianToken)
    ));

    $implausibleResponse = $router->dispatch(new Request(
        method: 'POST',
        path: $resolvePath($sanitaryIncidentId),
        parsedBody: [
            'resolution_diagnosis' => $validDiagnosis,
            'resolution_action'    => $validAction,
            'sanitary_declarations' => [
                'temperature_c'     => 120.0,
                'stock_destroyed'   => true,
                'hygiene_checklist' => true,
            ],
        ],
        headers: $authHeader($technicianToken)
    ));

    $assert(
        '1.4 Temperatura ausente, checklist sin marcar o lectura increíble (120 °C) => 422 y expediente intacto',
        $incompleteResponse->getStatusCode() === 422
            && ($incompleteResponse->getDecodedBody()['error']['code'] ?? '') === 'SANITARY_CHECKLIST_REQUIRED'
            && $uncheckedResponse->getStatusCode() === 422
            && $implausibleResponse->getStatusCode() === 422
            && ($incidentRow($sanitaryIncidentId)['status'] ?? '') === 'IN_PROGRESS'
    );

    // 1.5 Con las tres declaraciones válidas, el cierre procede y queda congelado en auditoría.
    $validResponse = $router->dispatch(new Request(
        method: 'POST',
        path: $resolvePath($sanitaryIncidentId),
        parsedBody: [
            'resolution_diagnosis' => $validDiagnosis,
            'resolution_action'    => $validAction,
            'sanitary_declarations' => [
                'temperature_c'     => 3.5,
                'stock_destroyed'   => true,
                'hygiene_checklist' => true,
            ],
        ],
        headers: $authHeader($technicianToken)
    ));
    $validData = $validResponse->getDecodedBody()['data'] ?? [];
    $resolveHistory = array_values(array_filter(
        $historyRows($sanitaryIncidentId),
        static fn (array $row): bool => $row['from_status'] === 'IN_PROGRESS' && $row['to_status'] === 'RESOLVED'
    ));
    $resolveNote = (string)($resolveHistory[0]['action_note'] ?? '');

    $assert(
        '1.5 Con las tres declaraciones válidas el cierre procede (200 RESOLVED) y el historial inmutable lo firma',
        $validResponse->getStatusCode() === 200
            && ($validData['status'] ?? '') === 'RESOLVED'
            && ($validData['sanitary_declarations']['temperature_c'] ?? null) === 3.5
            && ($validData['sanitary_declarations']['stock_destroyed'] ?? false) === true
            && ($validData['sanitary_declarations']['hygiene_checklist'] ?? false) === true
            && count($resolveHistory) === 1
            && (int)($resolveHistory[0]['user_id'] ?? 0) === $techId
            && str_contains($resolveNote, $validDiagnosis)
            && str_contains($resolveNote, $validAction),
        'HTTP ' . $validResponse->getStatusCode() . ' · respuesta: ' . json_encode($validResponse->getDecodedBody(), JSON_UNESCAPED_UNICODE)
            . ' · historial: ' . json_encode($resolveHistory, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '1.6 La máquina NO se desbloquea por declarar: la cuarentena sigue y solo la reinspección del módulo 05 la levanta',
        ($machineRow($perishableMachine['machine_id'])['sanitary_status'] ?? '') === 'QUARANTINE'
    );

    // 1.7 La enmienda del Product Owner: la máquina mixta (COMBO) también está bajo vigilancia sanitaria.
    $comboMachine = $comboPool[0];
    $touchMachine($comboMachine['machine_id']);

    $comboIncidentId = $createInProgressTicket($comboMachine);
    $backdateOpening($comboIncidentId, 5);

    $auditBeforeCombo = $auditCursor();
    $comboResponse = $router->dispatch(new Request(
        method: 'POST',
        path: $resolvePath($comboIncidentId),
        parsedBody: [
            'resolution_diagnosis' => $validDiagnosis,
            'resolution_action'    => $validAction,
        ],
        headers: $authHeader($technicianToken)
    ));

    $assert(
        '1.7 La mixta COMBO con 5 h naturales también bloquea el cierre y entra en QUARANTINE (enmienda ratificada)',
        $comboResponse->getStatusCode() === 422
            && ($comboResponse->getDecodedBody()['error']['code'] ?? '') === 'SANITARY_CHECKLIST_REQUIRED'
            && ($machineRow($comboMachine['machine_id'])['sanitary_status'] ?? '') === 'QUARANTINE'
            && count($auditEvents('MACHINE', $comboMachine['machine_id'], 'SANITARY_QUARANTINE_AUTO_TRIGGERED', $auditBeforeCombo)) === 1,
        'HTTP ' . $comboResponse->getStatusCode() . ' · estado sanitario: ' . ($machineRow($comboMachine['machine_id'])['sanitary_status'] ?? 'n/a')
            . ' · eventos nuevos: ' . count($auditEvents('MACHINE', $comboMachine['machine_id'], 'SANITARY_QUARANTINE_AUTO_TRIGGERED', $auditBeforeCombo))
    );

    // 1.8 La puerta no es universal: sin producto fresco el cierre no exige declaraciones sanitarias.
    $hotMachine = $hotDrinkPool[0];
    $touchMachine($hotMachine['machine_id']);

    $plainIncidentId = $createInProgressTicket($hotMachine);
    $backdateOpening($plainIncidentId, 5);

    $plainResponse = $router->dispatch(new Request(
        method: 'POST',
        path: $resolvePath($plainIncidentId),
        parsedBody: [
            'resolution_diagnosis' => $validDiagnosis,
            'resolution_action'    => $validAction,
        ],
        headers: $authHeader($technicianToken)
    ));

    $assert(
        '1.8 Una máquina de bebidas calientes con 5 h cierra sin declaraciones sanitarias (la vigilancia no es universal)',
        $plainResponse->getStatusCode() === 200
            && ($plainResponse->getDecodedBody()['data']['status'] ?? '') === 'RESOLVED'
            && ($machineRow($hotMachine['machine_id'])['sanitary_status'] ?? '') === 'OK'
    );

    // =========================================================================
    // GRUPO 2: Art. III — Inmutabilidad de incident_history
    // =========================================================================
    echo "\n--- Grupo 2: Art. III — Inmutabilidad del historial ---\n";

    $srcRoot = realpath(__DIR__ . '/../../src');
    $offendingFiles = [];
    $offendingStatements = [];
    if ($srcRoot !== false) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $source = (string)file_get_contents($file->getPathname());
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen((string)realpath(__DIR__ . '/../..')) + 1));

            if (preg_match('/\bUPDATE\s+`?incident_history`?/i', $source) === 1) {
                $offendingFiles[] = $relative;
                $offendingStatements[] = "{$relative} · UPDATE incident_history";
            }
            if (preg_match('/\bDELETE\s+FROM\s+`?incident_history`?/i', $source) === 1) {
                $offendingFiles[] = $relative;
                $offendingStatements[] = "{$relative} · DELETE FROM incident_history";
            }
        }
    }

    $assert(
        '2.1 Ninguna sentencia de src/ sobreescribe o borra incident_history (solo inserción)',
        $offendingStatements === [],
        'Sentencias prohibidas: ' . implode(', ', $offendingStatements)
    );

    // Comportamiento: las filas ya escritas siguen idénticas byte a byte tras una transición posterior.
    $historyBefore = $historyRows($plainIncidentId);
    $assert(
        '2.2 El expediente resuelto tiene su rastro firmado en el historial',
        count($historyBefore) >= 2,
        sprintf('Filas de historial del expediente: %d', count($historyBefore))
    );

    $reopenResponse = $router->dispatch(new Request(
        method: 'POST',
        path: $reopenPath((string)($incidentRow($plainIncidentId)['ticket_code'] ?? '')),
        parsedBody: ['reopen_reason' => 'El cliente informa de que el fallo persiste tras la reparación.'],
        headers: $authHeader($siteToken($hotMachine['location_id']))
    ));

    $historyAfter   = $historyRows($plainIncidentId);
    $preservedRows  = array_slice($historyAfter, 0, count($historyBefore));

    $assert(
        '2.3 Tras la reapertura (200) las filas previas del historial son idénticas byte a byte y solo se anexan filas',
        $reopenResponse->getStatusCode() === 200
            && count($historyAfter) > count($historyBefore)
            && json_encode($preservedRows, JSON_UNESCAPED_UNICODE) === json_encode($historyBefore, JSON_UNESCAPED_UNICODE),
        'HTTP ' . $reopenResponse->getStatusCode() . ' · cuerpo: ' . json_encode($reopenResponse->getDecodedBody(), JSON_UNESCAPED_UNICODE)
            . ' · filas antes=' . count($historyBefore) . ' después=' . count($historyAfter)
    );

    // =========================================================================
    // GRUPO 3: Art. V.1 — Justificación mínima y máquina fuera de servicio
    // =========================================================================
    echo "\n--- Grupo 3: Art. V.1 — Cancelación justificada y máquina fuera de servicio ---\n";

    $stalledMachine = $snackMachine;
    $touchMachine($stalledMachine['machine_id']);

    $stalledIncidentId = $createAssignedTicket($stalledMachine);
    $pauseResponse = $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($stalledIncidentId),
        parsedBody: [
            'reason_category' => 'PENDING_SITE_AUTHORIZATION',
            'reason_text'     => 'La sede no responde a las llamadas del servicio técnico para confirmar el acceso.',
        ],
        headers: $authHeader($technicianToken)
    ));

    $assert(
        '3.1 Premisa: el expediente queda en PENDING_INFO con la espera declarada (200)',
        $pauseResponse->getStatusCode() === 200
            && ($incidentRow($stalledIncidentId)['status'] ?? '') === 'PENDING_INFO',
        'HTTP ' . $pauseResponse->getStatusCode() . ' · cuerpo: ' . json_encode($pauseResponse->getDecodedBody(), JSON_UNESCAPED_UNICODE)
    );

    // La frontera administrativa son 72 h hábiles: se siembran 14 días naturales de
    // silencio de sede (dos semanas completas contienen 90 h de ventana comercial).
    $backdatePause($stalledIncidentId, 14);
    $stalledPauseHours = (int)$pdo->query(
        "SELECT TIMESTAMPDIFF(HOUR, `paused_at`, NOW()) FROM `incidents` WHERE `id` = {$stalledIncidentId}"
    )->fetchColumn();
    $assert(
        '3.1.b Premisa del banco: la espera sembrada supera con holgura la frontera de 72 h hábiles',
        $stalledPauseHours >= 168,
        "Horas naturales de espera: {$stalledPauseHours}"
    );

    $shortReasonResponse = $router->dispatch(new Request(
        method: 'POST',
        path: $cancelPath($stalledIncidentId),
        parsedBody: ['cancellation_reason' => 'Sin acceso al local'],
        headers: $authHeader($coordinatorToken)
    ));

    $reasonState = $machineRow($stalledMachine['machine_id']);
    $assert(
        '3.2 Motivo de 19 caracteres reales => 422 sin escribir: expediente en pausa y máquina intacta',
        $shortReasonResponse->getStatusCode() === 422
            && ($incidentRow($stalledIncidentId)['status'] ?? '') === 'PENDING_INFO'
            && (int)($reasonState['is_blocked_no_access'] ?? -1) === 0
            && (int)($reasonState['is_active'] ?? -1) === 1
    );

    $cancelReason = 'Cierre administrativo por inactividad y falta de acceso del cliente tras el plazo hábil.';
    $auditBeforeBlock = $auditCursor();
    $cancelResponse = $router->dispatch(new Request(
        method: 'POST',
        path: $cancelPath($stalledIncidentId),
        parsedBody: ['cancellation_reason' => $cancelReason],
        headers: $authHeader($coordinatorToken)
    ));
    $cancelData = $cancelResponse->getDecodedBody()['data'] ?? [];

    $cancelledRow   = $incidentRow($stalledIncidentId);
    $cancelledState = $machineRow($stalledMachine['machine_id']);

    $assert(
        '3.3 Con 20 caracteres reales el protocolo se ejecuta: CANCELLED, máquina fuera de servicio y nunca "Operativa"',
        $cancelResponse->getStatusCode() === 200
            && ($cancelledRow['status'] ?? '') === 'CANCELLED'
            && ($cancelledRow['cancellation_reason'] ?? '') === $cancelReason
            && (int)($cancelledState['is_active'] ?? -1) === 0
            && (int)($cancelledState['is_blocked_no_access'] ?? 0) === 1
            && ($cancelData['machine']['operational_status'] ?? '') === 'BLOCKED_NO_ACCESS'
            && count($auditEvents('MACHINE', $stalledMachine['machine_id'], 'MACHINE_BLOCKED_NO_ACCESS', $auditBeforeBlock)) === 1,
        'HTTP ' . $cancelResponse->getStatusCode() . ' · cuerpo: ' . json_encode($cancelResponse->getDecodedBody(), JSON_UNESCAPED_UNICODE)
            . ' · máquina: ' . json_encode($cancelledState, JSON_UNESCAPED_UNICODE)
    );

    // El cierre técnico exige su propia justificación de 20 caracteres (Art. V.1).
    $shortResolutionId = $createInProgressTicket($snackPool[1]);
    $touchMachine($snackPool[1]['machine_id']);
    $shortResolutionResponse = $router->dispatch(new Request(
        method: 'POST',
        path: $resolvePath($shortResolutionId),
        parsedBody: [
            'resolution_diagnosis' => 'Fallo eléctrico.',
            'resolution_action'    => $validAction,
        ],
        headers: $authHeader($technicianToken)
    ));

    $assert(
        '3.4 Diagnóstico de 16 caracteres => 422 INVALID_RESOLUTION y expediente todavía IN_PROGRESS',
        $shortResolutionResponse->getStatusCode() === 422
            && ($shortResolutionResponse->getDecodedBody()['error']['code'] ?? '') === 'INVALID_RESOLUTION'
            && ($incidentRow($shortResolutionId)['status'] ?? '') === 'IN_PROGRESS'
    );

    // =========================================================================
    // GRUPO 4: Art. V.2 — Confirmación de acceso en el aviso nuevo (RF-04.6)
    // =========================================================================
    echo "\n--- Grupo 4: Art. V.2 — Confirmación formal de acceso (RF-04.6) ---\n";

    $blockedMachineId  = $stalledMachine['machine_id'];
    $blockedLocationId = $stalledMachine['location_id'];
    $blockedSiteCode   = (string)($pdo->query("SELECT `site_code` FROM `locations` WHERE `id` = {$blockedLocationId}")->fetchColumn() ?: '');
    $blockedMachineRow = $machineRow($blockedMachineId);
    $blockAnnotation   = (string)($blockedMachineRow['notes'] ?? '');

    $siteListResponse = $router->dispatch(new Request(
        method: 'GET',
        path: $siteMachinesPath($blockedSiteCode),
        headers: $authHeader($siteToken($blockedLocationId))
    ));
    $siteMachines = $siteListResponse->getDecodedBody()['data'] ?? [];
    $blockedEntry = null;
    foreach ($siteMachines as $machineEntry) {
        if ((int)($machineEntry['id'] ?? 0) === $blockedMachineId) {
            $blockedEntry = $machineEntry;
            break;
        }
    }

    $assert(
        '4.1 La máquina bloqueada sigue visible en el portal de sede como BLOCKED_NO_ACCESS (Art. V.1)',
        $siteListResponse->getStatusCode() === 200
            && is_array($blockedEntry)
            && ($blockedEntry['is_blocked_no_access'] ?? false) === true
            && ($blockedEntry['operational_status'] ?? '') === 'BLOCKED_NO_ACCESS',
        'HTTP ' . $siteListResponse->getStatusCode() . ' · entrada: ' . json_encode($blockedEntry, JSON_UNESCAPED_UNICODE)
            . ' · listado: ' . json_encode($siteListResponse->getDecodedBody(), JSON_UNESCAPED_UNICODE)
    );

    $auditBeforeLift = $auditCursor();
    $unconfirmedResponse = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/incidents',
        parsedBody: [
            'machine_id'     => $blockedMachineId,
            'category'       => 'ELECTRICAL_OFF',
            'description'    => 'La máquina no enciende y la sala ya está abierta para el técnico.',
            'reporter_name'  => 'Conserjería del centro',
            'reporter_phone' => '600000000',
        ],
        headers: $authHeader($siteToken($blockedLocationId))
    ));

    $openTicketsAfterRejection = (int)$pdo->query(
        "SELECT COUNT(*) FROM `incidents`
          WHERE `machine_id` = {$blockedMachineId}
            AND `deleted_at` IS NULL
            AND `status` NOT IN ('CLOSED', 'CANCELLED')"
    )->fetchColumn();

    $assert(
        '4.2 Aviso sobre máquina bloqueada sin la casilla => 422 ACCESS_CONFIRMATION_REQUIRED y sin expediente nuevo',
        $unconfirmedResponse->getStatusCode() === 422
            && ($unconfirmedResponse->getDecodedBody()['error']['code'] ?? '') === 'ACCESS_CONFIRMATION_REQUIRED'
            && $openTicketsAfterRejection === 0
            && (int)($machineRow($blockedMachineId)['is_blocked_no_access'] ?? 0) === 1,
        'HTTP ' . $unconfirmedResponse->getStatusCode() . ' · cuerpo: ' . json_encode($unconfirmedResponse->getDecodedBody(), JSON_UNESCAPED_UNICODE)
            . ' · expedientes abiertos=' . $openTicketsAfterRejection
    );

    $confirmedResponse = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/incidents',
        parsedBody: [
            'machine_id'       => $blockedMachineId,
            'category'         => 'ELECTRICAL_OFF',
            'description'      => 'La máquina no enciende y la sala ya está abierta para el técnico.',
            'reporter_name'    => 'Conserjería del centro',
            'reporter_phone'   => '600000000',
            'access_confirmed' => true,
        ],
        headers: $authHeader($siteToken($blockedLocationId))
    ));
    $confirmedData = $confirmedResponse->getDecodedBody()['data'] ?? [];
    $liftedRow     = $machineRow($blockedMachineId);
    $liftEvents    = $auditEvents('MACHINE', $blockedMachineId, 'MACHINE_UNBLOCKED_BY_ACCESS_CONFIRMATION', $auditBeforeLift);
    $liftState     = json_decode((string)($liftEvents[0]['metadata'] ?? '{}'), true) ?: [];

    $assert(
        '4.3 Con la confirmación marcada el aviso se registra (201) y la máquina vuelve al parque activo',
        $confirmedResponse->getStatusCode() === 201
            && ($confirmedData['ticket_code'] ?? '') !== ''
            && (int)($liftedRow['is_active'] ?? 0) === 1
            && (int)($liftedRow['is_blocked_no_access'] ?? 1) === 0,
        'HTTP ' . $confirmedResponse->getStatusCode() . ' · cuerpo: ' . json_encode($confirmedResponse->getDecodedBody(), JSON_UNESCAPED_UNICODE)
            . ' · máquina: ' . json_encode($liftedRow, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '4.4 El desbloqueo queda auditado de forma inmutable y las notas conservan el bloqueo más el levantamiento',
        count($liftEvents) === 1
            && ($liftState['ticket_code'] ?? '') === ($confirmedData['ticket_code'] ?? '')
            && ($liftEvents[0]['previous_state'] !== null && str_contains((string)$liftEvents[0]['previous_state'], 'BLOCKED_NO_ACCESS'))
            && str_contains($blockAnnotation, 'Bloqueada por falta de acceso tras ticket')
            && str_contains((string)($liftedRow['notes'] ?? ''), 'Bloqueada por falta de acceso tras ticket')
            && str_contains((string)($liftedRow['notes'] ?? ''), 'Bloqueo por falta de acceso levantado con el ticket')
    );

    $duplicateAfterLift = $router->dispatch(new Request(
        method: 'POST',
        path: '/api/incidents',
        parsedBody: [
            'machine_id'     => $blockedMachineId,
            'category'       => 'ELECTRICAL_OFF',
            'description'    => 'Segundo aviso inmediato sobre la misma máquina recién desbloqueada.',
            'reporter_name'  => 'Conserjería del centro',
            'reporter_phone' => '600000000',
        ],
        headers: $authHeader($siteToken($blockedLocationId))
    ));

    $assert(
        '4.5 Con la máquina ya en servicio, un segundo aviso vuelve al flujo normal (409 de duplicado, sin casilla)',
        $duplicateAfterLift->getStatusCode() === 409
    );

    // =========================================================================
    // GRUPO 5: Art. V.4 — Anonimización del QR ciudadano
    // =========================================================================
    echo "\n--- Grupo 5: Art. V.4 — Anonimización del escaneo público ---\n";

    $qrMachine = $hotDrinkPool[1];
    $touchMachine($qrMachine['machine_id']);

    $qrIncidentId = $createAssignedTicket($qrMachine);
    $internalPauseText = 'Falta de llaves del cuarto de contadores del inmueble: la sede no responde.';
    $router->dispatch(new Request(
        method: 'POST',
        path: $pausePath($qrIncidentId),
        parsedBody: [
            'reason_category' => 'BUILDING_CLOSED_NO_ACCESS',
            'reason_text'     => $internalPauseText,
        ],
        headers: $authHeader($technicianToken)
    ));

    $qrResponse = $router->dispatch(new Request(
        method: 'GET',
        path: $qrScanPath($qrMachine['code'])
    ));
    $qrRaw = json_encode($qrResponse->getDecodedBody(), JSON_UNESCAPED_UNICODE) ?: '';
    $qrData = $qrResponse->getDecodedBody()['data'] ?? [];

    $assert(
        '5.1 El expediente pausado sigue siendo visible al ciudadano con el estado neutral (RF-06.1)',
        $qrResponse->getStatusCode() === 200
            && ($qrData['status_mode'] ?? '') === 'ACTIVE_INCIDENT'
            && ($qrData['active_incident']['public_status'] ?? '') === 'IN_PROGRESS'
            && ($qrData['active_incident']['status_label'] ?? '') === 'En proceso de atención técnica'
    );

    $assert(
        '5.2 La respuesta pública no filtra el motivo interno, la categoría de pausa ni el estado PENDING_INFO',
        !str_contains($qrRaw, 'BUILDING_CLOSED_NO_ACCESS')
            && !str_contains($qrRaw, 'llaves')
            && !str_contains($qrRaw, 'contadores')
            && !str_contains($qrRaw, 'PENDING_INFO')
            && !str_contains($qrRaw, 'pending_info_reason')
    );

    // =========================================================================
    // GRUPO 6: Art. II — Puerta trasera del módulo 05 cerrada
    // =========================================================================
    echo "\n--- Grupo 6: Art. II — El desbloqueo estacional no retira la cuarentena ---\n";

    $quarantineMachine = $perishableMachine;

    $auditBeforeBackdoor = $auditCursor();
    $backdoorResponse = $router->dispatch(new Request(
        method: 'PATCH',
        path: $preventiveConfigPath($quarantineMachine['machine_id']),
        parsedBody: ['is_seasonal_pause' => false],
        headers: $authHeader($coordinatorToken)
    ));

    $assert(
        '6.1 La ruta de configuración preventiva rechaza el desbloqueo estacional (400) y no toca la cuarentena',
        $backdoorResponse->getStatusCode() === 400
            && ($machineRow($quarantineMachine['machine_id'])['sanitary_status'] ?? '') === 'QUARANTINE'
            && count($auditEvents('MACHINE', $quarantineMachine['machine_id'], 'RESUME_SEASONAL_PAUSE', $auditBeforeBackdoor)) === 0,
        'HTTP ' . $backdoorResponse->getStatusCode() . ' · cuerpo: ' . json_encode($backdoorResponse->getDecodedBody(), JSON_UNESCAPED_UNICODE)
            . ' · estado sanitario: ' . ($machineRow($quarantineMachine['machine_id'])['sanitary_status'] ?? 'n/a')
    );

    $repositoryResume = $settingsRepo->resumeSeasonalPause($quarantineMachine['machine_id']);
    $assert(
        '6.2 El repositorio tampoco levanta el bloqueo: devuelve false y la máquina sigue en QUARANTINE',
        $repositoryResume === false
            && ($machineRow($quarantineMachine['machine_id'])['sanitary_status'] ?? '') === 'QUARANTINE'
    );

    $seasonalMachine = $snackPool[1];
    $touchMachine($seasonalMachine['machine_id']);
    $seasonalSet = $settingsRepo->setSeasonalPause(
        $seasonalMachine['machine_id'],
        'Cierre vacacional del centro para la guardia estacional del banco de pruebas.'
    );
    $seasonalResume = $settingsRepo->resumeSeasonalPause($seasonalMachine['machine_id']);

    $assert(
        '6.3 El camino legítimo sigue operativo: una máquina realmente en pausa estacional se reanuda (ATTENTION_REQUIRED)',
        $seasonalSet === true
            && $seasonalResume === true
            && ($machineRow($seasonalMachine['machine_id'])['sanitary_status'] ?? '') === 'ATTENTION_REQUIRED'
            && (int)($machineRow($seasonalMachine['machine_id'])['is_blocked_no_access'] ?? 1) === 0
    );
} finally {
    foreach ($touchedMachines as $machineId => $snapshot) {
        $restoreMachine((int)$machineId, is_array($snapshot) ? $snapshot : []);
    }

    TestDataCleaner::purge($pdo);
    echo "\n  Banco restituido: parque maestro restaurado y datos de prueba purgados.\n";
}

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. ART. II, ART. III, ART. V.1, ART. V.2 Y ART. V.4 CERTIFICADOS.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);
