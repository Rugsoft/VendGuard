<?php

declare(strict_types=1);

/**
 * SanitaryQuarantineSafeguardEndpointTest (T-HYBRID-15)
 *
 * Suite de integración de RF-10 (EARS-10.1 y EARS-10.2, Art. II, Art. III y Art. V.1),
 * despachada por el enrutador real —con sus middlewares— contra MariaDB:
 *
 *   - **Cuarentena sobrevenida (EARS-10.1).** La central declara la cuarentena mientras el
 *     técnico tiene la avería en curso; su resolución ordinaria no puede cerrar, pero
 *     tampoco se pierde: responde `422 SANITARY_QUARANTINE_OVERTAKEN` con
 *     `draft_safeguarded = true`, y ni el expediente, ni la máquina, ni el historial, ni
 *     las piezas sustituidas se tocan.
 *   - **El parte no se destruye.** El diagnóstico, la acción correctiva y las piezas
 *     redactadas se guardan como evento inmutable sobre el expediente (`Art. III`), con el
 *     actor técnico y sin transición de estado: la avería sigue `IN_PROGRESS`.
 *   - **Idempotencia.** Reintentar el mismo formulario no añade un segundo evento: el parte
 *     se guarda una vez por contenido.
 *   - **Recuperación (EARS-10.2).** Cuando el técnico vuelve con el protocolo sanitario
 *     firmado, el cierre se completa con el parte a resguardo —los textos y las piezas que
 *     ya había escrito— y **lo que la petición trae gana sobre el resguardo**.
 *   - **Controles negativos.** Una máquina sin vigilancia sanitaria cierra por el camino
 *     ordinario sin escribir resguardo alguno, y un informe que no llega al mínimo legal
 *     (`Art. V.1`) se rechaza sin salvaguardar nada: el resguardo no es un salvoconducto.
 *   - **Contrato de interfaz.** La acción de auditoría que firma el resguardo tiene su
 *     etiqueta en el catálogo que consume el visor del coordinador.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\SanitaryReportSafeguardService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - SanitaryQuarantineSafeguardEndpointTest (T-HYBRID-15)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$router      = AppRouter::create();
$incidentRepo = new PdoIncidentRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);
$authService  = new AuthService($locationRepo, $userRepo);
$cronSecret   = SecretProvider::cronSecret();

$failures   = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
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

// ─── Banco de pruebas ─────────────────────────────────────────────────────────

/** @var array<int, string> Estado sanitario de TODO el parque (restituido en el finally). */
$sanitarySnapshot = [];
foreach ($pdo->query('SELECT `id`, `sanitary_status` FROM `machines` WHERE `deleted_at` IS NULL') as $row) {
    $sanitarySnapshot[(int)$row['id']] = (string)$row['sanitary_status'];
}

/**
 * Máquinas de una tipología sin expediente vivo (candidatas a siembra limpia).
 *
 * @return list<array{machine_id: int, location_id: int, code: string, machine_type: string}>
 */
$freeMachinesByType = function (string $machineType, int $limit = 3) use ($pdo): array {
    $stmt = $pdo->prepare(
        "SELECT m.`id` AS machine_id, m.`location_id`, m.`code`, m.`machine_type`
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
            'machine_id'   => (int)$row['machine_id'],
            'location_id'  => (int)$row['location_id'],
            'code'         => (string)$row['code'],
            'machine_type' => (string)$row['machine_type'],
        ],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
};

$perishablePool = $freeMachinesByType('PERISHABLE_FOOD');
$hotDrinkPool   = $freeMachinesByType('HOT_DRINKS');

$technicianRow = $pdo->query(
    "SELECT `id` FROM `users` WHERE `role` = 'TECHNICIAN' AND `deleted_at` IS NULL ORDER BY `id` LIMIT 1"
)->fetchColumn();
$technician  = $technicianRow !== false ? $userRepo->findById((int)$technicianRow) : null;
$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

$partRow = $pdo->query('SELECT `id` FROM `spare_parts` WHERE `deleted_at` IS NULL ORDER BY `id` LIMIT 1')->fetchColumn();

echo "--- Caso 0: Banco de pruebas ---\n";

$assert(
    '0.1 El parque expone dos perecederas libres (bajo vigilancia) y una máquina fuera de vigilancia',
    count($perishablePool) >= 2 && $hotDrinkPool !== [],
    sprintf('perecederas=%d calientes=%d', count($perishablePool), count($hotDrinkPool))
);

$assert(
    '0.2 Semilla disponible: técnico de ruta y repuesto del catálogo maestro',
    $technician instanceof User && $partRow !== false,
    sprintf('técnico=%s repuesto=%s', var_export($technician?->getId(), true), var_export($partRow, true))
);

if (!$technician instanceof User || !$coordinator instanceof User || $partRow === false
    || count($perishablePool) < 2 || $hotDrinkPool === []) {
    echo "ERROR FATAL: el banco de pruebas no está disponible.\n";
    exit(1);
}

$techId           = (int)$technician->getId();
$coordinatorId    = (int)$coordinator->getId();
$partId           = (int)$partRow;
$technicianToken  = $authService->generateInternalToken($technician);
$authHeader       = static fn (string $token): array => ['Authorization' => 'Bearer ' . $token];

$diagnosis = 'Sonda NTC descalibrada con lectura real de 14 grados en el recinto térmico.';
$action    = 'Sustitución de la sonda NTC y verificación del ciclo completo de frío a 3,5 grados.';

// ─── Instrumentos de siembra y de medición ────────────────────────────────────

$sequence = 0;
$suffix   = substr((string)time(), -5);

/** @return int Id del expediente creado en IN_PROGRESS, asignado al técnico y envejecido. */
$createBackdatedTicket = function (array $machine, int $hours = 5) use (
    $incidentRepo,
    $techId,
    $coordinatorId,
    $pdo,
    $suffix,
    &$sequence
): int {
    $sequence++;
    $created = $incidentRepo->create(new Incident(
        id: null,
        ticketCode: sprintf('TST-TH15-%s-%02d', $suffix, $sequence),
        machineId: $machine['machine_id'],
        locationId: $machine['location_id'],
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'Expediente de la salvaguarda del parte ante cuarentena sobrevenida (T-HYBRID-15).',
        urgency: UrgencyLevel::CRITICAL,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        createdAt: null
    ));

    $incidentId = (int)$created->getId();
    $incidentRepo->assign($incidentId, $techId, $coordinatorId);
    $incidentRepo->startIntervention($incidentId, $techId);
    $pdo->prepare('UPDATE `incidents` SET `created_at` = NOW() - INTERVAL :hours HOUR WHERE `id` = :id')
        ->execute([':hours' => $hours, ':id' => $incidentId]);

    return $incidentId;
};

$incidentRow = function (int $incidentId) use ($pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM `incidents` WHERE `id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$machineStatus = function (int $machineId) use ($pdo): string {
    $stmt = $pdo->prepare('SELECT `sanitary_status` FROM `machines` WHERE `id` = :id');
    $stmt->execute([':id' => $machineId]);

    return (string)$stmt->fetchColumn();
};

$historyCount = function (int $incidentId) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `incident_history` WHERE `incident_id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return (int)$stmt->fetchColumn();
};

$partsCount = function (int $incidentId) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `incident_replaced_parts` WHERE `incident_id` = :id');
    $stmt->execute([':id' => $incidentId]);

    return (int)$stmt->fetchColumn();
};

/**
 * Cursor de auditoría: `audit_log` es append-only y el arnés NO la purga entre suites,
 * así que todo recuento se mide por delta sobre esta foto.
 */
$auditCursor = static function () use ($pdo): int {
    return (int)$pdo->query('SELECT COALESCE(MAX(`id`), 0) FROM `audit_log`')->fetchColumn();
};

/** @return list<array<string, mixed>> Eventos nuevos desde el cursor, del más antiguo al más nuevo. */
$auditEvents = function (string $entityType, int $entityId, string $action, int $sinceId = 0) use ($pdo): array {
    $stmt = $pdo->prepare(
        'SELECT `action`, `entity_type`, `entity_id`, `user_id`, `user_role`, `previous_state`, `new_state`, `metadata`
           FROM `audit_log`
          WHERE `entity_type` = :entity_type AND `entity_id` = :entity_id AND `action` = :action
            AND `id` > :since_id
          ORDER BY `id` ASC'
    );
    $stmt->execute([
        ':entity_type' => $entityType,
        ':entity_id'   => $entityId,
        ':action'      => $action,
        ':since_id'    => $sinceId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$draftEventsFor = function (int $incidentId, int $sinceId = 0) use ($auditEvents): array {
    return $auditEvents(AuditEvent::ENTITY_TICKET, $incidentId, SanitaryReportSafeguardService::DRAFT_ACTION, $sinceId);
};

$resolvePath = static fn (int $incidentId): string => '/api/technician/incidents/' . $incidentId . '/resolve';

$resolve = function (int $incidentId, array $body) use ($router, $resolvePath, $authHeader, $technicianToken) {
    return $router->dispatch(new Request(
        method: 'POST',
        path: $resolvePath($incidentId),
        parsedBody: $body,
        headers: $authHeader($technicianToken)
    ));
};

$sweep = function () use ($router, $cronSecret) {
    return $router->dispatch(new Request(
        method: 'POST',
        path: '/api/cron/sanitary-clock-sweep',
        headers: ['X-Cron-Secret' => $cronSecret]
    ));
};

$ordinaryReport = [
    // Forma admitida por alias a propósito: la salvaguarda debe guardar el parte canónico
    // sea cual sea la forma por la que el cliente lo mandó.
    'resolution_diagnosis' => $diagnosis,
    'resolution_action'    => $action,
    'replaced_parts_declared' => true,
    'replaced_parts' => [
        ['spare_part_id' => $partId, 'quantity' => 1, 'old_part_destination' => 'DESGUACE'],
    ],
];

try {
    // =====================================================================
    // CASO 1: Cuarentena sobrevenida — el parte se salvaguarda (EARS-10.1)
    // =====================================================================
    echo "\n--- Caso 1: Cuarentena sobrevenida y salvaguarda del parte (EARS-10.1, Art. II) ---\n";

    $machine = $perishablePool[0];
    $machineId = $machine['machine_id'];
    $incidentId = $createBackdatedTicket($machine);
    $historyBefore = $historyCount($incidentId);

    $assert(
        '1.0 Premisa del banco: la perecedera arranca en OK con la avería en curso y 5 h naturales',
        $machineStatus($machineId) === 'OK'
        && ($incidentRow($incidentId)['status'] ?? '') === 'IN_PROGRESS'
        && $partsCount($incidentId) === 0
    );

    // La central declara la cuarentena mientras el técnico trabaja: ése es el hecho que
    // distingue la cuarentena sobrevenida de la que dispara su propio intento de cierre.
    $sweepResponse = $sweep();
    $assert(
        '1.1 El barrido de la central deja la máquina en CUARENTENA con el expediente todavía en curso',
        $sweepResponse->getStatusCode() === 200
        && $machineStatus($machineId) === 'QUARANTINE'
        && ($incidentRow($incidentId)['status'] ?? '') === 'IN_PROGRESS'
    );

    $cursorBeforeResolve = $auditCursor();
    $overtakenResponse = $resolve($incidentId, $ordinaryReport);
    $overtakenBody = $overtakenResponse->getDecodedBody();
    $overtakenDetails = $overtakenBody['error']['details'] ?? [];

    $assert(
        '1.2 La resolución ordinaria sobre una máquina ya en cuarentena responde 422 SANITARY_QUARANTINE_OVERTAKEN',
        $overtakenResponse->getStatusCode() === 422
        && ($overtakenBody['error']['code'] ?? '') === 'SANITARY_QUARANTINE_OVERTAKEN'
        && ($overtakenDetails['draft_safeguarded'] ?? false) === true,
        'HTTP ' . $overtakenResponse->getStatusCode() . ' · respuesta: ' . json_encode($overtakenBody, JSON_UNESCAPED_UNICODE)
    );

    $safeguardedFields = $overtakenDetails['safeguarded_fields'] ?? [];
    sort($safeguardedFields);
    // El informe ordinario llega con el diagnóstico, la acción y la declaración de repuestos:
    // la lista blanca del resguardo es la que decide qué entra, y aquí entra exactamente eso
    // —nada más—, ni un campo de saldo que el técnico no haya dictaminado.
    $expectedFields = ['action_taken', 'diagnosis', 'replaced_parts', 'replaced_parts_declared'];

    $assert(
        '1.3 El 422 confirma el parte salvaguardado y enumera exactamente los campos guardados',
        $safeguardedFields === $expectedFields
        && array_diff($safeguardedFields, SanitaryReportSafeguardService::DRAFT_FIELDS) === [],
        sprintf(
            'campos=%s · lista blanca=%s',
            json_encode($safeguardedFields, JSON_UNESCAPED_UNICODE),
            json_encode(SanitaryReportSafeguardService::DRAFT_FIELDS, JSON_UNESCAPED_UNICODE)
        )
    );

    $assert(
        '1.4 El 422 exige las tres declaraciones del protocolo de higienización (Art. II)',
        count($overtakenDetails['errors'] ?? []) === 1
        && str_contains((string)($overtakenDetails['errors'][0] ?? ''), 'higienización'),
        json_encode($overtakenDetails['errors'] ?? [], JSON_UNESCAPED_UNICODE)
    );

    $incidentAfterOvertaken = $incidentRow($incidentId);
    $assert(
        '1.5 Nada se ha cerrado: expediente en curso, sin resolución escrita, sin piezas y sin historial nuevo',
        ($incidentAfterOvertaken['status'] ?? '') === 'IN_PROGRESS'
        && trim((string)($incidentAfterOvertaken['resolution_diagnosis'] ?? '')) === ''
        && trim((string)($incidentAfterOvertaken['resolution_action'] ?? '')) === ''
        && $partsCount($incidentId) === 0
        && $historyCount($incidentId) === $historyBefore,
        json_encode([
            'status' => $incidentAfterOvertaken['status'] ?? null,
            'diagnosis' => $incidentAfterOvertaken['resolution_diagnosis'] ?? null,
            'piezas' => $partsCount($incidentId),
            'historial' => $historyCount($incidentId),
        ], JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '1.6 La máquina sigue en cuarentena: la salvaguarda no levanta ni repite el bloqueo sanitario',
        $machineStatus($machineId) === 'QUARANTINE'
        && $auditEvents('MACHINE', $machineId, 'SANITARY_QUARANTINE_AUTO_TRIGGERED', $cursorBeforeResolve) === []
    );

    $draftEventsAfter = $draftEventsFor($incidentId);
    $draftEvent = $draftEventsAfter[0] ?? [];
    $draftState = json_decode((string)($draftEvent['new_state'] ?? '{}'), true) ?: [];
    $draftReport = $draftState[SanitaryReportSafeguardService::STATE_KEY] ?? [];
    $draftMetadata = json_decode((string)($draftEvent['metadata'] ?? '{}'), true) ?: [];

    $assert(
        '1.7 El parte queda como evento inmutable sobre el expediente, con el actor técnico (Art. III)',
        count($draftEventsAfter) === 1
        && (int)($draftEvent['user_id'] ?? 0) === $techId
        && (string)($draftEvent['user_role'] ?? '') === 'TECHNICIAN'
        && (int)($draftEvent['entity_id'] ?? 0) === $incidentId,
        'eventos: ' . json_encode($draftEventsAfter, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '1.8 El resguardo guarda el parte canónico —diagnóstico, acción y repuestos— sin transicionar el expediente',
        ($draftReport['diagnosis'] ?? '') === $diagnosis
        && ($draftReport['action_taken'] ?? '') === $action
        && ($draftReport['replaced_parts_declared'] ?? false) === true
        && (int)($draftReport['replaced_parts'][0]['spare_part_id'] ?? 0) === $partId
        && ($draftState['status'] ?? '') === 'IN_PROGRESS'
        && ($draftState['sanitary_quarantine_overtaken'] ?? false) === true,
        json_encode($draftReport, JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '1.9 El evento nombra el expediente y el motivo, sin duplicar la regla del reloj',
        (string)($draftMetadata['ticket_code'] ?? '') === (string)($incidentAfterOvertaken['ticket_code'] ?? '')
        && (int)($draftMetadata['machine_id'] ?? 0) === $machineId
        && str_contains((string)($draftMetadata['reason'] ?? ''), 'cuarentena'),
        json_encode($draftMetadata, JSON_UNESCAPED_UNICODE)
    );

    $retrySameResponse = $resolve($incidentId, $ordinaryReport);
    $assert(
        '1.10 Reintentar el mismo formulario responde igual y NO añade un segundo evento (idempotencia)',
        $retrySameResponse->getStatusCode() === 422
        && ($retrySameResponse->getDecodedBody()['error']['code'] ?? '') === 'SANITARY_QUARANTINE_OVERTAKEN'
        && count($draftEventsFor($incidentId)) === 1
        && $auditCursor() === $cursorBeforeResolve + 1,
        'eventos=' . count($draftEventsFor($incidentId)) . ' · cursor=' . $auditCursor()
            . ' (esperado ' . ($cursorBeforeResolve + 1) . ')'
    );

    // =====================================================================
    // CASO 2: Recuperación del parte — sólo falta el protocolo (EARS-10.2)
    // =====================================================================
    echo "\n--- Caso 2: Recuperación del parte redactado (EARS-10.2) ---\n";

    $recoveredResponse = $resolve($incidentId, [
        'sanitary_declarations' => [
            'temperature_c'     => 3.5,
            'stock_destroyed'   => true,
            'hygiene_checklist' => true,
        ],
    ]);
    $recoveredData = $recoveredResponse->getDecodedBody()['data'] ?? [];
    $incidentAfterRecovery = $incidentRow($incidentId);

    $assert(
        '2.1 Sólo con el protocolo sanitario el cierre se consolida (200 RESOLVED) sin volver a redactar el informe',
        $recoveredResponse->getStatusCode() === 200
        && ($recoveredData['status'] ?? '') === 'RESOLVED',
        'HTTP ' . $recoveredResponse->getStatusCode() . ' · respuesta: '
            . json_encode($recoveredResponse->getDecodedBody(), JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '2.2 El diagnóstico y la acción persistidos son literalmente los del parte a resguardo',
        (string)($incidentAfterRecovery['resolution_diagnosis'] ?? '') === $diagnosis
        && (string)($incidentAfterRecovery['resolution_action'] ?? '') === $action,
        json_encode([
            'diagnosis' => $incidentAfterRecovery['resolution_diagnosis'] ?? null,
            'action' => $incidentAfterRecovery['resolution_action'] ?? null,
        ], JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '2.3 Las piezas sustituidas del resguardo se registran con su snapshot de coste (Art. III)',
        $partsCount($incidentId) === 1
        && ($recoveredData['replaced_parts_count'] ?? 0) === 1
        && (int)($recoveredData['replaced_parts'][0]['spare_part_id'] ?? 0) === $partId,
        json_encode($recoveredData['replaced_parts'] ?? [], JSON_UNESCAPED_UNICODE)
    );

    $assert(
        '2.4 Las declaraciones sanitarias del protocolo quedan eco en la respuesta y en el cierre',
        ($recoveredData['sanitary_declarations']['temperature_c'] ?? null) === 3.5
        && ($recoveredData['sanitary_declarations']['stock_destroyed'] ?? false) === true
        && ($recoveredData['sanitary_declarations']['hygiene_checklist'] ?? false) === true
        && $historyCount($incidentId) - $historyBefore >= 1
    );

    // =====================================================================
    // CASO 3: La palabra más reciente gana sobre el resguardo (EARS-10.2)
    // =====================================================================
    echo "\n--- Caso 3: La petición manda sobre el resguardo (EARS-10.2) ---\n";

    $machineB = $perishablePool[1] ?? $perishablePool[0];
    $machineBId = $machineB['machine_id'];
    $incidentBId = $createBackdatedTicket($machineB);
    $sweep();
    $overtakenB = $resolve($incidentBId, $ordinaryReport);
    $newDiagnosis = 'Compresor con arranque intermitente y pérdida de frío confirmada por termómetro patrón.';
    $recoveredB = $resolve($incidentBId, [
        'diagnosis' => $newDiagnosis,
        'sanitary_declarations' => [
            'temperature_c'     => 4.0,
            'stock_destroyed'   => true,
            'hygiene_checklist' => true,
        ],
    ]);
    $incidentBRow = $incidentRow($incidentBId);

    $assert(
        '3.0 El banco repite la cuarentena sobrevenida sobre una segunda máquina (premisa del caso)',
        $overtakenB->getStatusCode() === 422
        && ($overtakenB->getDecodedBody()['error']['code'] ?? '') === 'SANITARY_QUARANTINE_OVERTAKEN'
        && $machineBId !== $machineId
    );

    $assert(
        '3.1 El diagnóstico nuevo del técnico prevalece sobre el del resguardo y la acción ausente se recupera',
        $recoveredB->getStatusCode() === 200
        && (string)($incidentBRow['resolution_diagnosis'] ?? '') === $newDiagnosis
        && (string)($incidentBRow['resolution_action'] ?? '') === $action
        && $partsCount($incidentBId) === 1,
        'HTTP ' . $recoveredB->getStatusCode() . ' · expediente: '
            . json_encode([
                'diagnosis' => $incidentBRow['resolution_diagnosis'] ?? null,
                'action' => $incidentBRow['resolution_action'] ?? null,
            ], JSON_UNESCAPED_UNICODE)
    );

    // =====================================================================
    // CASO 4: Controles negativos — el resguardo no es un salvoconducto
    // =====================================================================
    echo "\n--- Caso 4: Controles negativos ---\n";

    $machineC = $perishablePool[2] ?? $machineB;
    $incidentCId = $createBackdatedTicket($machineC);
    $sweep();
    $cursorBeforeInvalid = $auditCursor();
    $invalidResponse = $resolve($incidentCId, [
        'resolution_diagnosis' => 'Sonda rota.',
        'resolution_action'    => 'Cambiada.',
    ]);

    $assert(
        '4.1 Un informe por debajo del mínimo legal se rechaza sin salvaguardar nada (Art. V.1)',
        $invalidResponse->getStatusCode() === 422
        && ($invalidResponse->getDecodedBody()['error']['code'] ?? '') === 'INVALID_RESOLUTION'
        && $draftEventsFor($incidentCId, $cursorBeforeInvalid) === []
        && ($incidentRow($incidentCId)['status'] ?? '') === 'IN_PROGRESS'
    );

    $plainMachine = $hotDrinkPool[0];
    $plainIncidentId = $createBackdatedTicket($plainMachine, 6);
    $plainCursor = $auditCursor();
    $plainResponse = $resolve($plainIncidentId, [
        'resolution_diagnosis' => $diagnosis,
        'resolution_action'    => $action,
    ]);

    $assert(
        '4.2 Sin vigilancia sanitaria el cierre ordinario sigue siendo el de siempre, sin resguardo',
        $plainResponse->getStatusCode() === 200
        && ($plainResponse->getDecodedBody()['data']['status'] ?? '') === 'RESOLVED'
        && $draftEventsFor($plainIncidentId, $plainCursor) === []
        && $machineStatus($plainMachine['machine_id']) === 'OK'
    );

    // =====================================================================
    // CASO 5: Contrato de interfaz del evento salvaguardado
    // =====================================================================
    echo "\n--- Caso 5: Contrato de interfaz del resguardo ---\n";

    $catalogPath = __DIR__ . '/../../public/assets/js/utils/AuditActionLabels.js';
    $catalogSource = is_file($catalogPath) ? (string)file_get_contents($catalogPath) : '';

    $assert(
        '5.1 La acción del resguardo tiene etiqueta en el catálogo que consume el visor del coordinador',
        $catalogSource !== ''
        && preg_match(
            "/code: '" . preg_quote(SanitaryReportSafeguardService::DRAFT_ACTION, '/') . "', label: '([^']+)'/",
            $catalogSource,
            $matches
        ) === 1
        && $matches[1] !== '',
        'Acción sin etiqueta: el visor del coordinador mostraría el código crudo.'
    );

    $assert(
        '5.2 El evento inmutable que firma el resguardo usa exactamente esa acción',
        SanitaryReportSafeguardService::DRAFT_ACTION === 'SANITARY_QUARANTINE_REPORT_SAFEGUARDED'
        && SanitaryReportSafeguardService::STATE_KEY === 'safeguarded_report'
        && (string)($draftEvent['action'] ?? '') === SanitaryReportSafeguardService::DRAFT_ACTION,
        'La acción escrita como literal y la constante del servicio no pueden separarse.'
    );

    $technicianViewSource = (string)file_get_contents(__DIR__ . '/../../public/assets/js/views/TechnicianRouteView.js');
    $assert(
        '5.3 La vista del técnico reabre la puerta sanitaria con el código nuevo (RF-10.1, ergonomía de campo)',
        str_contains($technicianViewSource, "err?.code === 'SANITARY_QUARANTINE_OVERTAKEN'"),
        'Sin ese camino, el técnico vería un error sin el bloque que le falta para cerrar.'
    );
} finally {
    if ($sanitarySnapshot !== []) {
        $restore = $pdo->prepare('UPDATE `machines` SET `sanitary_status` = :status WHERE `id` = :id');
        foreach ($sanitarySnapshot as $snapshotMachineId => $status) {
            $restore->execute([':status' => $status, ':id' => $snapshotMachineId]);
        }
    }
}

// ─── RESULTADO FINAL ──────────────────────────────────────────────────────────

echo "\n" . str_repeat('=', 70) . "\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-HYBRID-15 (INTEGRACIÓN) CUMPLIDA.\n";
} else {
    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
}
echo str_repeat('=', 70) . "\n";

exit($failures > 0 ? 1 : 0);
