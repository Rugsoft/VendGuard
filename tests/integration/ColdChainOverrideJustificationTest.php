<?php

declare(strict_types=1);

/**
 * ColdChainOverrideJustificationTest · T-ZONE-10
 *
 * Certifica la **salvaguarda constitucional de la cadena de frío** en el despacho
 * real (`PATCH /api/coordinator/incidents/{id}/assign`, contrato §3.7, RF-05.2,
 * Art. II y Art. V.1, Resolución QA 8).
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Rechazo sin justificación:** descartar al técnico de guardia sugerido en
 *      una avería perecedera crítica responde `422
 *      COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED` y no escribe nada: ni cambio de
 *      responsable, ni evento de salvaguarda.
 *   2. **Umbral medido en caracteres reales:** 19 caracteres, sólo espacios y
 *      espacios de relleno se rechazan; **20 exactos** (con acentos) se aceptan.
 *   3. **Aceptación trazada:** la asignación justificada responde `200` y deja dos
 *      rastros inmutables distintos —`INCIDENT_ASSIGNED`/`INCIDENT_REASSIGNED` y
 *      `INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN`— con la justificación íntegra,
 *      el técnico sugerido y el técnico realmente enviado.
 *   4. **Sin fricción cuando no toca:** asignar al técnico sugerido no exige
 *      justificación, y una avería sin riesgo de frío tampoco la exige.
 *   5. **Convivencia con `reassignment_reason`:** la reasignación conserva su
 *      motivo obligatorio de 10 caracteres y, cuando además descarta la
 *      recomendación sanitaria, exige la justificación del Art. V.1 en la misma
 *      petición.
 *
 * CATEGORÍA: `new_engine`
 *   Exige el PostgreSQL local. El escenario vive en un esquema aislado sobre la
 *   conexión de la aplicación; sin motor, sale con código 2 en lugar de omitirse.
 *
 * LIMPIEZA: el esquema se retira con `CASCADE` al terminar, incluso si falla una
 * aserción.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/ColdChainOverrideJustificationTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado sobre la conexión de la aplicación. */
const COLD_OVERRIDE_TEST_SCHEMA = 't_zone10_override';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;
$crash = null;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-ZONE-10 · Justificación del descarte sanitario (Art. V.1)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

/** @var array<string, mixed> */
$json = static fn (Response $response): array => $response->getDecodedBody() ?? [];

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, COLD_OVERRIDE_TEST_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    // ─────────────────────────────────────────────────────────────────────
    // Semilla
    // ─────────────────────────────────────────────────────────────────────

    $createUser = static function (
        string $suffix,
        string $role,
        bool $isActive = true,
        bool $isOnCall = false
    ) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, :is_active, :is_on_call)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => ($role === 'COORDINATOR' ? 'Coordinación ' : 'Técnico ') . ucfirst($suffix),
            ':email' => 'zona10b.' . $suffix . '@vendguard.internal',
            ':email_key' => 'zona10b.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('zona10b-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => 'Z1B-' . mb_strtoupper($suffix),
            ':is_active' => $isActive ? 'true' : 'false',
            ':is_on_call' => $isOnCall ? 'true' : 'false',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createLocation = static function (
        string $siteCode,
        string $name,
        string $postalCode,
        ?int $zoneId,
        float $latitude,
        float $longitude
    ) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO locations (site_code, site_code_key, name, address, latitude, longitude, postal_code, zone_id)
             VALUES (:site, :site_key, :name, :address, :lat, :lng, :cp, :zone_id)
             RETURNING id'
        );
        $stmt->execute([
            ':site' => $siteCode,
            ':site_key' => $siteCode,
            ':name' => $name,
            ':address' => 'Calle de la Cadena de Frío 4',
            ':lat' => $latitude,
            ':lng' => $longitude,
            ':cp' => $postalCode,
            ':zone_id' => $zoneId,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createMachine = static function (int $locationId, string $code, string $type) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
             VALUES (:location_id, :code, :model, :type, :wing)
             RETURNING id'
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => 'Modelo Frío',
            ':type' => $type,
            ':wing' => 'Planta baja',
        ]);

        return (int)$stmt->fetchColumn();
    };

    $createIncident = static function (
        string $ticketCode,
        int $machineId,
        int $locationId,
        string $machineType,
        string $urgency,
        string $status,
        ?int $technicianId = null,
        ?string $assignedAt = null
    ) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO incidents (
                ticket_code, machine_id, machine_type_snapshot, location_id,
                assigned_technician_id, category, description, urgency, status, assigned_at
             ) VALUES (
                :ticket, :machine_id, :machine_type, :location_id,
                :technician_id, :category, :description, :urgency, :status, :assigned_at
             ) RETURNING id'
        );
        $stmt->execute([
            ':ticket' => $ticketCode,
            ':machine_id' => $machineId,
            ':machine_type' => $machineType,
            ':location_id' => $locationId,
            ':technician_id' => $technicianId,
            ':category' => $machineType === 'PERISHABLE_FOOD' ? 'TEMPERATURE_COLD' : 'PRODUCT_JAM',
            ':description' => 'Avería sembrada por la certificación del Art. V.1.',
            ':urgency' => $urgency,
            ':status' => $status,
            ':assigned_at' => $assignedAt,
        ]);

        return (int)$stmt->fetchColumn();
    };

    /** Eventos de auditoría de un expediente, del más antiguo al más reciente. */
    $auditActions = static function (int $incidentId) use ($pdo): array {
        $stmt = $pdo->prepare(
            'SELECT action, previous_state, new_state, metadata
             FROM audit_log
             WHERE entity_id = :id AND entity_type = \'TICKET\'
             ORDER BY id ASC'
        );
        $stmt->execute([':id' => $incidentId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'action' => (string)$row['action'],
                'previous_state' => $row['previous_state'] !== null ? json_decode((string)$row['previous_state'], true) : null,
                'new_state' => $row['new_state'] !== null ? json_decode((string)$row['new_state'], true) : null,
                'metadata' => $row['metadata'] !== null ? json_decode((string)$row['metadata'], true) : null,
            ];
        }

        return $rows;
    };

    /** Estado del expediente directamente en el motor. */
    $incidentRow = static function (int $incidentId) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT status, assigned_technician_id FROM incidents WHERE id = :id');
        $stmt->execute([':id' => $incidentId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    $pdo->exec("INSERT INTO route_settings (base_name, base_latitude, base_longitude) VALUES ('Base V1', 41.3935, 2.1701)");

    $coordinatorId = $createUser('coord', 'COORDINATOR');
    $onCall = $createUser('guardia', 'TECHNICIAN', true, true);
    $primaryBusy = $createUser('titular', 'TECHNICIAN');
    $otherTech = $createUser('otro', 'TECHNICIAN');

    $stmt = $pdo->prepare(
        "INSERT INTO operational_zones (code, name, color_hex) VALUES ('ZONA-FRIO-T10', 'Zona Frío T10', '#2560ff') RETURNING id"
    );
    $stmt->execute();
    $zoneId = (int)$stmt->fetchColumn();

    $assignZone = static function (int $technicianId, int $zone, bool $isPrimary) use ($pdo): void {
        $stmt = $pdo->prepare(
            'INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
             VALUES (:technician_id, :zone_id, :is_primary)'
        );
        $stmt->execute([
            ':technician_id' => $technicianId,
            ':zone_id' => $zone,
            ':is_primary' => $isPrimary ? 'true' : 'false',
        ]);
    };

    $assignZone($primaryBusy, $zoneId, true);
    $assignZone($otherTech, $zoneId, false);

    $targetLocation = $createLocation('SEDE-V1-01', 'Hospital Clínic T10', '08036', $zoneId, 41.3880, 2.1750);
    $otherLocation = $createLocation('SEDE-V1-02', 'Sede Anexa T10', '08037', $zoneId, 41.3990, 2.1810);

    $coldMachine = $createMachine($targetLocation, 'MAQ-V1-01', 'PERISHABLE_FOOD');
    $coldMachineTwo = $createMachine($targetLocation, 'MAQ-V1-02', 'PERISHABLE_FOOD');
    $coldMachineThree = $createMachine($targetLocation, 'MAQ-V1-03', 'PERISHABLE_FOOD');
    $drinksMachine = $createMachine($targetLocation, 'MAQ-V1-04', 'COLD_DRINKS');
    $otherMachine = $createMachine($otherLocation, 'MAQ-V1-05', 'COLD_DRINKS');

    // El titular principal está saturado: hay una parada viva en otra sede de su zona.
    $createIncident('INC-20261020-V1001', $otherMachine, $otherLocation, 'COLD_DRINKS', 'MEDIUM', 'IN_PROGRESS', $primaryBusy, (new DateTimeImmutable('-25 minutes'))->format('Y-m-d H:i:sP'));

    $criticalIncident = $createIncident('INC-20261020-V1002', $coldMachine, $targetLocation, 'PERISHABLE_FOOD', 'CRITICAL', 'REGISTERED');
    $criticalIncidentTwo = $createIncident('INC-20261020-V1003', $coldMachineTwo, $targetLocation, 'PERISHABLE_FOOD', 'CRITICAL', 'REGISTERED');
    $ordinaryIncident = $createIncident('INC-20261020-V1004', $drinksMachine, $targetLocation, 'COLD_DRINKS', 'HIGH', 'REGISTERED');
    $reassignmentIncident = $createIncident(
        'INC-20261020-V1005',
        $coldMachineThree,
        $targetLocation,
        'PERISHABLE_FOOD',
        'CRITICAL',
        'ASSIGNED',
        $onCall,
        (new DateTimeImmutable('-15 minutes'))->format('Y-m-d H:i:sP')
    );

    // ─────────────────────────────────────────────────────────────────────
    // Router real
    // ─────────────────────────────────────────────────────────────────────

    $userRepo = new PdoUserRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);
    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));

    $router = AppRouter::create();

    $call = static function (
        string $method,
        string $path,
        array $body = [],
        ?string $token = null,
        array $query = []
    ) use ($router): Response {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $router->dispatch(new Request($method, $path, $query, $body, $headers));
    };

    $assign = static function (int $incidentId, array $body) use ($call, $coordinatorToken): Response {
        return $call('PATCH', '/api/coordinator/incidents/' . $incidentId . '/assign', $body, $coordinatorToken);
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Rechazo: falta la justificación
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Descarte sanitario sin justificación ---\n";

    $withoutJustification = $assign($criticalIncident, ['technician_id' => $otherTech]);
    $withoutBody = $json($withoutJustification);
    $assert(
        '1.1 Descartar al técnico de guardia sin justificación responde 422 COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED',
        $withoutJustification->getStatusCode() === 422
            && ($withoutBody['error']['code'] ?? '') === 'COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED'
            && str_contains((string)($withoutBody['error']['message'] ?? ''), '20 caracteres'),
        json_encode($withoutBody)
    );

    $untouched = $incidentRow($criticalIncident);
    $assert(
        '1.2 El rechazo no escribe nada: el expediente sigue sin responsable',
        ($untouched['status'] ?? '') === 'REGISTERED' && ($untouched['assigned_technician_id'] ?? null) === null,
        json_encode($untouched)
    );

    $rejectedAudit = array_column($auditActions($criticalIncident), 'action');
    $assert(
        '1.3 El rechazo no deja rastro de salvaguarda en audit_log',
        !in_array('INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN', $rejectedAudit, true)
            && !in_array('INCIDENT_ASSIGNED', $rejectedAudit, true),
        json_encode($rejectedAudit)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Umbral de 20 caracteres reales
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Umbral medido en caracteres reales ---\n";

    $short = $assign($criticalIncident, ['technician_id' => $otherTech, 'override_justification' => 'Técnico ocupado ya.']);
    $assert(
        '2.1 Una justificación de 19 caracteres se rechaza',
        mb_strlen('Técnico ocupado ya.') < 20
            && $short->getStatusCode() === 422
            && ($json($short)['error']['code'] ?? '') === 'COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED',
        'longitud=' . mb_strlen('Técnico ocupado ya.')
    );

    $padded = $assign($criticalIncident, [
        'technician_id' => $otherTech,
        'override_justification' => '                   ' . 'abc',
    ]);
    $assert(
        '2.2 Los espacios de relleno no alcanzan el umbral',
        $padded->getStatusCode() === 422
            && ($json($padded)['error']['code'] ?? '') === 'COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Aceptación justificada y trazada
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Aceptación con justificación y auditoría ---\n";

    $justification = 'Justificación válida';
    $accepted = $assign($criticalIncident, [
        'technician_id' => $otherTech,
        'override_justification' => '   ' . $justification . '   ',
    ]);
    $acceptedBody = $json($accepted);
    $assert(
        '3.1 La justificación de 20 caracteres reales se acepta y asigna (200)',
        mb_strlen($justification) === 20
            && $accepted->getStatusCode() === 200
            && ($acceptedBody['success'] ?? false) === true
            && ($acceptedBody['data']['assigned_technician_id'] ?? null) === $otherTech
            && ($acceptedBody['data']['status'] ?? '') === 'ASSIGNED',
        json_encode($acceptedBody)
    );

    $events = $auditActions($criticalIncident);
    $overrideEvents = array_values(array_filter(
        $events,
        static fn (array $event): bool => $event['action'] === 'INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN'
    ));
    $overrideEvent = $overrideEvents[0] ?? [];

    $assert(
        '3.2 El descarte deja un único evento INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN',
        count($overrideEvents) === 1,
        json_encode(array_column($events, 'action'))
    );

    $assert(
        '3.3 El evento registra el técnico sugerido, el enviado y la justificación recortada',
        ($overrideEvent['previous_state']['suggested_technician_id'] ?? null) === $onCall
            && ($overrideEvent['new_state']['assigned_technician_id'] ?? null) === $otherTech
            && ($overrideEvent['new_state']['override_justification'] ?? '') === $justification
            && ($overrideEvent['metadata']['new_technician_id'] ?? null) === $otherTech,
        json_encode($overrideEvent)
    );

    $assert(
        '3.4 La salvaguarda se suma al evento de asignación, no lo sustituye',
        in_array('INCIDENT_ASSIGNED', array_column($events, 'action'), true),
        json_encode(array_column($events, 'action'))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Sin fricción cuando el caso no la pide
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Casos que no exigen justificación ---\n";

    $suggested = $assign($criticalIncidentTwo, ['technician_id' => $onCall]);
    $assert(
        '4.1 Asignar al técnico de guardia sugerido no exige justificación (200)',
        $suggested->getStatusCode() === 200
            && ($json($suggested)['data']['assigned_technician_id'] ?? null) === $onCall
            && !in_array(
                'INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN',
                array_column($auditActions($criticalIncidentTwo), 'action'),
                true
            ),
        json_encode($json($suggested))
    );

    $ordinary = $assign($ordinaryIncident, ['technician_id' => $otherTech]);
    $assert(
        '4.2 Una avería sin riesgo de frío asigna sin justificación aunque descarte el ranking',
        $ordinary->getStatusCode() === 200
            && ($json($ordinary)['data']['assigned_technician_id'] ?? null) === $otherTech
            && !in_array(
                'INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN',
                array_column($auditActions($ordinaryIncident), 'action'),
                true
            ),
        json_encode($json($ordinary))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Convivencia con reassignment_reason
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Reasignación: motivo de traspaso + justificación sanitaria ---\n";

    $reason = 'Reasignación por proximidad al centro con riesgo de frío.';
    $onlyReason = $assign($reassignmentIncident, [
        'technician_id' => $otherTech,
        'reassignment_reason' => $reason,
    ]);
    $assert(
        '5.1 La reasignación que descarta la guardia exige también la justificación del Art. V.1',
        $onlyReason->getStatusCode() === 422
            && ($json($onlyReason)['error']['code'] ?? '') === 'COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED'
            && ($incidentRow($reassignmentIncident)['assigned_technician_id'] ?? null) === $onCall,
        json_encode($json($onlyReason))
    );

    $both = $assign($reassignmentIncident, [
        'technician_id' => $otherTech,
        'reassignment_reason' => $reason,
        'override_justification' => 'El técnico de guardia está a 45 minutos del centro.',
    ]);
    $bothBody = $json($both);
    $reassignmentEvents = $auditActions($reassignmentIncident);
    $reassigned = array_values(array_filter(
        $reassignmentEvents,
        static fn (array $event): bool => $event['action'] === 'INCIDENT_REASSIGNED'
    ))[0] ?? [];

    $assert(
        '5.2 Con ambos campos la reasignación se ejecuta (200)',
        $both->getStatusCode() === 200
            && ($bothBody['data']['assigned_technician_id'] ?? null) === $otherTech
            && ($incidentRow($reassignmentIncident)['assigned_technician_id'] ?? null) === $otherTech,
        json_encode($bothBody)
    );

    $assert(
        '5.3 El motivo de traspaso se conserva íntegro en el rastro de reasignación',
        ($reassigned['new_state']['reassignment_reason'] ?? '') === $reason,
        json_encode($reassigned['new_state'] ?? null)
    );

    $assert(
        '5.4 La misma petición deja los dos rastros: traspaso y salvaguarda',
        in_array('INCIDENT_REASSIGNED', array_column($reassignmentEvents, 'action'), true)
            && in_array('INCIDENT_ASSIGNMENT_SUGGESTION_OVERRIDDEN', array_column($reassignmentEvents, 'action'), true),
        json_encode(array_column($reassignmentEvents, 'action'))
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, COLD_OVERRIDE_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }
}

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ZONE-10 (ART. V.1) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
