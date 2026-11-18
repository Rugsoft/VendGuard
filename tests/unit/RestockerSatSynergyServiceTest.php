<?php

declare(strict_types=1);

/**
 * RestockerSatSynergyServiceTest · T-RSS-06
 *
 * Certifica el caso de uso de la sinergia reponedor ↔ SAT (Módulo 36) con
 * dobles en memoria y sin base de datos:
 *
 *   1. Despacho/conmutación L1↔L2: categoría prohibida, rol incoherente y
 *      delegación del nivel validado (RF-02.1, QA-CON-01, Art. II).
 *   2. Inicio y resolución in situ: guarda sanitaria, textos mínimos y
 *      snapshots de taxonomía (RF-03.1, QA-CONST-01, Art. V.1).
 *   3. Escalado al SAT: nota y foto obligatorias, sugerencia de técnico y
 *      aviso en outbox (RF-03.2, QA-CONST-02).
 *   4. Coincidencia en Sede, relevo y volcado de conflicto offline
 *      (RF-04.1, RF-04.2, RF-05.1, D4, C1, Art. III.3).
 *
 * CATEGORÍA: hermética (`tests/unit/`). No toca PostgreSQL, red ni sistema de
 * archivos; el doble implementa el mismo puerto que producción.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\RestockerSatSynergyService;
use VendGuard\Core\Domain\Exception\RestockerSatSynergyValidationException;
use VendGuard\Core\Domain\Repository\RestockerSatSynergyGatewayInterface;

$GLOBALS['assertions'] = 0;

function assertSynergy(bool $condition, string $message): void
{
    $GLOBALS['assertions']++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assertSynergyRejects(callable $operation, string $expectedCode, int $expectedHttp): void
{
    try {
        $operation();
    } catch (RestockerSatSynergyValidationException $exception) {
        assertSynergy(
            $exception->getErrorCode() === $expectedCode && $exception->getHttpStatusCode() === $expectedHttp,
            "código {$expectedCode}/{$expectedHttp}, recibido {$exception->getErrorCode()}/{$exception->getHttpStatusCode()}"
        );

        return;
    }

    fwrite(STDERR, "FAIL: se esperaba {$expectedCode} y no se lanzó nada.\n");
    exit(1);
}

final class SynergyGatewayDouble implements RestockerSatSynergyGatewayInterface
{
    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    public function startLevel1(int $incidentId, array $actor, ?array $mutation = null): array
    {
        $this->calls[] = ['method' => 'startLevel1', 'args' => compact('incidentId', 'actor', 'mutation')];

        return ['ok' => true, 'status' => 'IN_PROGRESS', 'idempotent_replay' => $mutation !== null];
    }

    public function resolveLevel1(int $incidentId, array $actor, array $resolution, ?array $mutation = null): array
    {
        $this->calls[] = ['method' => 'resolveLevel1', 'args' => compact('incidentId', 'actor', 'resolution', 'mutation')];

        return ['ok' => true, 'status' => 'RESOLVED'];
    }

    public function escalateToSat(int $machineId, array $actor, array $payload, ?array $mutation = null): array
    {
        $this->calls[] = ['method' => 'escalateToSat', 'args' => compact('machineId', 'actor', 'payload', 'mutation')];

        return ['ok' => true, 'incident_id' => 501, 'branch' => 'CREATED_NEW_SAT_TICKET'];
    }

    public function assignDispatchLevel(array $incident, string $dispatchLevel, array $assignee, array $actor): array
    {
        $this->calls[] = ['method' => 'assignDispatchLevel', 'args' => compact('incident', 'dispatchLevel', 'assignee', 'actor')];

        return ['ok' => true, 'incident_id' => (int)$incident['id'], 'dispatch_level' => $dispatchLevel];
    }

    public function recordConflictDump(array $actor, array $mutation, array $conflict): array
    {
        $this->calls[] = ['method' => 'recordConflictDump', 'args' => compact('actor', 'mutation', 'conflict')];

        return ['ok' => true, 'outcome_status' => 'CONFLICT_DUMPED'];
    }

    public function suggestRestockers(int $incidentId): array
    {
        $this->calls[] = ['method' => 'suggestRestockers', 'args' => compact('incidentId')];

        return ['ok' => true, 'data' => [['id' => 11, 'active_l1_incidents_count' => 2]]];
    }

    public function technicianCoincidences(int $technicianId, array $locationIds): array
    {
        $this->calls[] = ['method' => 'technicianCoincidences', 'args' => compact('technicianId', 'locationIds')];

        return ['ok' => true, 'data' => [['id' => 9]]];
    }

    public function restockerCoincidences(int $restockerId, array $locationIds): array
    {
        $this->calls[] = ['method' => 'restockerCoincidences', 'args' => compact('restockerId', 'locationIds')];

        return ['ok' => true, 'data' => [['id' => 12]]];
    }

    public function handoverLevel1(int $restockerId, string $cause, string $reason): array
    {
        $this->calls[] = ['method' => 'handoverLevel1', 'args' => compact('restockerId', 'cause', 'reason')];

        return ['ok' => true, 'released_count' => 3];
    }

    /** @return array<string, mixed> */
    public function lastCall(): array
    {
        return $this->calls[count($this->calls) - 1];
    }
}

$actor = ['id' => 4, 'name' => 'Lucía Reponedora', 'operator_code' => 'REP-004', 'role' => 'RESTOCKER'];
$restocker = ['id' => 4, 'name' => 'Lucía Reponedora', 'role' => 'RESTOCKER', 'is_active' => true];
$technician = ['id' => 77, 'name' => 'Mario Técnico', 'role' => 'TECHNICIAN', 'is_active' => true];

// ── 1. Despacho y conmutación L1↔L2 ────────────────────────────────────
$gateway = new SynergyGatewayDouble();
$notified = [];
$service = new RestockerSatSynergyService($gateway, null, static function (string $event, array $payload) use (&$notified): void {
    $notified[] = [$event, $payload];
});

assertSynergyRejects(
    static fn () => $service->dispatchLevel(
        ['id' => 10, 'category' => 'ELECTRICAL_OFF'],
        'L1_RESTOCKER',
        $restocker,
        $actor
    ),
    'L1_DISPATCH_FORBIDDEN_FOR_CATEGORY',
    422
);

assertSynergyRejects(
    static fn () => $service->dispatchLevel(
        ['id' => 10, 'category' => 'PRODUCT_JAM'],
        'L1_RESTOCKER',
        $technician,
        $actor
    ),
    'INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL',
    422
);

$dispatch = $service->dispatchLevel(
    ['id' => 10, 'category' => 'PRODUCT_JAM'],
    'L1_RESTOCKER',
    $restocker,
    $actor
);
assertSynergy(($dispatch['ok'] ?? false) === true, 'el despacho válido devuelve ok');
assertSynergy($dispatch['dispatch_level'] === 'L1_RESTOCKER', 'el despacho conserva el nivel L1');
assertSynergy($gateway->lastCall()['method'] === 'assignDispatchLevel', 'el despacho delega en el gateway');
assertSynergy($gateway->lastCall()['args']['assignee']['id'] === 4, 'el custodio llega íntegro al gateway');

// ── 2. Inicio y resolución in situ ─────────────────────────────────────
$started = $service->startLevel1(10, $actor, ['client_mutation_id' => 'mut-1']);
assertSynergy(($started['status'] ?? '') === 'IN_PROGRESS', 'el inicio deja el aviso IN_PROGRESS');
assertSynergy($started['idempotent_replay'] === true, 'el inicio propaga la idempotencia offline');

assertSynergyRejects(
    static fn () => $service->resolveLevel1(
        ['id' => 10, 'machine_id' => 3, 'sanitary_status' => 'QUARANTINE'],
        $actor,
        ['diagnosis' => 'Envase atascado en la espiral', 'action' => 'Liberación manual del envase']
    ),
    'SANITARY_L1_RESOLUTION_BLOCKED',
    409
);

assertSynergyRejects(
    static fn () => $service->resolveLevel1(
        ['id' => 10, 'machine_id' => 3, 'sanitary_status' => 'OK'],
        $actor,
        ['diagnosis' => 'Corto', 'action' => 'Nada']
    ),
    'RESOLUTION_TEXT_TOO_SHORT',
    422
);

$resolved = $service->resolveLevel1(
    ['id' => 10, 'machine_id' => 3, 'sanitary_status' => 'OK'],
    $actor,
    ['diagnosis' => 'Envase atascado en la espiral', 'action' => 'Liberación manual del envase'],
    ['client_mutation_id' => 'mut-2']
);
assertSynergy(($resolved['status'] ?? '') === 'RESOLVED', 'la resolución sella RESOLVED');
assertSynergy(
    $gateway->lastCall()['args']['resolution']['diagnosis'] === 'Envase atascado en la espiral',
    'los textos normalizados llegan al gateway'
);

// ── 3. Escalado al SAT ─────────────────────────────────────────────────
$escalation = [
    'internal_note' => 'La puerta deja de cerrar y el producto queda expuesto al exterior.',
    'photo_path' => '/uploads/incidents/rss06_door.jpg',
    'photo_size_bytes' => 120000,
    'photo_mime_type' => 'image/jpeg',
];
$gateway = new SynergyGatewayDouble();
$resolverCalls = 0;
$service = new RestockerSatSynergyService(
    $gateway,
    static function (int $machineId, array $payload) use (&$resolverCalls): array {
        $resolverCalls++;

        return ['id' => 77, 'name' => 'Mario Técnico'];
    },
    static function (string $event, array $payload) use (&$notified): void {
        $notified[] = [$event, $payload];
    }
);

assertSynergyRejects(
    static fn () => $service->escalateToSat(3, $actor, ['internal_note' => 'Avería']),
    'ESCALATION_INTERNAL_NOTE_REQUIRED',
    422
);

$escalated = $service->escalateToSat(3, $actor, $escalation, ['client_mutation_id' => 'mut-3']);
assertSynergy(($escalated['ok'] ?? false) === true, 'el escalado válido devuelve ok');
assertSynergy($resolverCalls === 1, 'el resolvedor de técnicos se invoca una sola vez');
assertSynergy(
    $gateway->lastCall()['args']['payload']['assign_technician_id'] === 77,
    'la sugerencia se propaga como assign_technician_id'
);
assertSynergy($notified[0][0] === RestockerSatSynergyService::EVENT_ESCALATED_TO_SAT, 'el escalado avisa al outbox');

$gateway = new SynergyGatewayDouble();
$service = new RestockerSatSynergyService($gateway, static function (): array {
    throw new RuntimeException('No debe sugerirse si el cliente fuerza técnico.');
});
$forced = $service->escalateToSat(3, $actor, $escalation + ['assign_technician_id' => 88]);
assertSynergy(($forced['ok'] ?? false) === true, 'el técnico forzado no bloquea el escalado');
assertSynergy(
    $gateway->lastCall()['args']['payload']['assign_technician_id'] === 88,
    'el técnico forzado se respeta'
);

// ── 4. Coincidencia, relevo y volcado offline ──────────────────────────
$gateway = new SynergyGatewayDouble();
$service = new RestockerSatSynergyService($gateway);
$coincidence = $service->siteCoincidence('TECHNICIAN', 77, [3, 4]);
assertSynergy(($coincidence['ok'] ?? false) === true, 'la coincidencia del técnico devuelve ok');
assertSynergy($gateway->lastCall()['method'] === 'technicianCoincidences', 'el rol técnico enruta a su consulta');
assertSynergy($gateway->lastCall()['args']['locationIds'] === [3, 4], 'las sedes llegan íntegras');

$service->siteCoincidence('RESTOCKER', 4, [3]);
assertSynergy($gateway->lastCall()['method'] === 'restockerCoincidences', 'el rol reponedor enruta a su consulta');

try {
    $service->siteCoincidence('COORDINATOR', 1, [3]);
    assertSynergy(false, 'el coordinador no debe tener Coincidencia en Sede');
} catch (InvalidArgumentException) {
    assertSynergy(true, 'el rol ajeno se rechaza');
}

$handover = $service->handoverLevel1(4, 'absence', '  Ausencia sobrevenida del reponedor titular.  ');
assertSynergy(($handover['released_count'] ?? 0) === 3, 'el relevo devuelve los liberados');
assertSynergy($gateway->lastCall()['args']['cause'] === 'ABSENCE', 'la causa del relevo se normaliza');
assertSynergy(
    $gateway->lastCall()['args']['reason'] === 'Ausencia sobrevenida del reponedor titular.',
    'el motivo del relevo se recorta'
);

try {
    $service->dumpOfflineConflict($actor, ['stop_id' => 10], ['http_status' => 409]);
    assertSynergy(false, 'el volcado sin client_mutation_id debe rechazarse');
} catch (InvalidArgumentException) {
    assertSynergy(true, 'el volcado sin identificador se rechaza');
}

$dump = $service->dumpOfflineConflict(
    $actor,
    ['client_mutation_id' => 'mut-9', 'stop_id' => 10],
    ['http_status' => 409, 'error_code' => 'SANITARY_L1_RESOLUTION_BLOCKED']
);
assertSynergy(($dump['outcome_status'] ?? '') === 'CONFLICT_DUMPED', 'el conflicto se archiva como volcado');
assertSynergy($gateway->lastCall()['method'] === 'recordConflictDump', 'el volcado delega en el gateway');

$suggestions = $service->suggestRestockers(10);
assertSynergy(($suggestions['data'][0]['active_l1_incidents_count'] ?? 0) === 2, 'la sugerencia expone la carga L1');

echo "======================================================================\n";
echo " Total Aserciones: {$GLOBALS['assertions']} | Fallidas: 0\n";
echo " RESULTADO: 100% EN VERDE. SERVICIO DE LA SINERGIA CERTIFICADO (T-RSS-06).\n";
echo "======================================================================\n";
