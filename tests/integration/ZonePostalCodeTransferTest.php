<?php

declare(strict_types=1);

/**
 * ZonePostalCodeTransferTest · T-ZONE-06
 *
 * Certifica **contra el motor real** el traslado de códigos postales entre zonas
 * operativas: el movimiento de filas, el arrastre de las sedes que los heredan y
 * la auditoría dual matriz-sede, todo dentro de una única transacción.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Mover, nunca duplicar (RF-01.2 / RF-01.3).** Transferir dos CP mueve sus
 *      filas (`zone_id`) y deja exactamente una fila viva por CP, sin `INSERT`.
 *   2. **Arrastre de sedes heredadas (RF-03.3).** Las sedes sin anulación manual
 *      pasan a la zona destino; la sede forzada a mano conserva la suya.
 *   3. **Auditoría dual (Resolución QA 6 / Art. III).** Un evento matriz
 *      `ZONE_POSTAL_CODE_TRANSFERRED` sobre `OPERATIONAL_ZONE` y un
 *      `LOCATION_ZONE_REASSIGNED` por sede afectada, firmados por el coordinador.
 *   4. **Idempotencia.** Repetir el traslado no duplica filas ni reasigna de nuevo.
 *   5. **Fail-fast y atomicidad.** Un CP liberado, una zona destino inactiva o un
 *      fallo en mitad de la auditoría deshacen el traslado completo: ni CP, ni
 *      sedes, ni eventos a medias.
 *
 * CATEGORÍA: `new_engine`
 *   Exige el PostgreSQL local de `deploy/local/postgres/`. Sin `PG_TEST_DSN` la
 *   suite **no se omite en silencio**: lo dice y sale con código 2.
 *
 * LIMPIEZA: el escenario vive en su propio esquema y se borra con `CASCADE` al
 * terminar, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/ZonePostalCodeTransferTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Core\Domain\Exception\ZoneValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Infrastructure\Database\PostgresMigrationRunner;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoOperationalZoneRepository;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta suite. */
const TRANSFER_TEST_SCHEMA = 't_zone06_transfer';

/**
 * Auditoría que falla en la llamada indicada.
 *
 * Simula un fallo real en mitad de la transacción (por ejemplo, el motor remoto
 * cortando la conexión al escribir el segundo evento) para certificar que el
 * traslado se deshace entero y no queda un estado a medias.
 */
final class FailingAuditLogRepository implements AuditLogRepositoryInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly AuditLogRepositoryInterface $inner,
        private readonly int $failOnCall
    ) {
    }

    public function log(AuditEvent $event): AuditEvent
    {
        $this->calls++;

        if ($this->calls === $this->failOnCall) {
            throw new RuntimeException('Fallo simulado al escribir la auditoría del traslado.');
        }

        return $this->inner->log($event);
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->inner->findEvents($filters, $limit, $offset);
    }

    public function countEvents(array $filters = []): int
    {
        return $this->inner->countEvents($filters);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return $this->inner->findByEntity($entityType, $entityId);
    }
}

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-ZONE-06 · Traslado transaccional de CP y auditoría dual\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

/** Alta de un usuario interno y devolución de su id. */
$createUser = static function (PDO $pdo, string $suffix, string $role): int {
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, operator_code)
         VALUES (:name, :email, :email_key, :hash, :role, :operator)
         RETURNING id'
    );
    $stmt->execute([
        ':name' => 'Usuario de Certificación ' . ucfirst($suffix),
        ':email' => 'transfer.zone06.' . $suffix . '@vendguard.internal',
        ':email_key' => 'transfer.zone06.' . $suffix . '@vendguard.internal',
        ':hash' => password_hash('zona06-' . $suffix, PASSWORD_BCRYPT),
        ':role' => $role,
        ':operator' => 'Z06-' . mb_strtoupper($suffix),
    ]);

    return (int)$stmt->fetchColumn();
};

/** Vincula un CP a una zona (el alta de CPs pertenece a T-ZONE-07). */
$linkPostalCode = static function (PDO $pdo, string $postalCode, int $zoneId): void {
    $stmt = $pdo->prepare(
        'INSERT INTO operational_zone_postal_codes (postal_code, zone_id) VALUES (:postal_code, :zone_id)'
    );
    $stmt->execute([':postal_code' => $postalCode, ':zone_id' => $zoneId]);
};

/**
 * Siembra una avería asignada en una sede, para certificar que el traslado del CP
 * no reasigna la ruta en curso (RF-03.3).
 *
 * @return int identificador del expediente
 */
$seedIncident = static function (PDO $pdo, int $locationId, int $technicianId, string $ticket): int {
    $machine = $pdo->prepare(
        'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, :code, :model, :type, :wing) RETURNING id'
    );
    $machine->execute([
        ':location_id' => $locationId,
        ':code' => 'MAQ-' . $ticket,
        ':model' => 'Modelo de certificación del traslado',
        ':type' => 'COLD_DRINKS',
        ':wing' => 'Vestíbulo',
    ]);
    $machineId = (int)$machine->fetchColumn();

    $incident = $pdo->prepare(
        'INSERT INTO incidents (
            ticket_code, machine_id, machine_type_snapshot, location_id, assigned_technician_id,
            category, description, urgency, status, assigned_at
         ) VALUES (
            :ticket, :machine_id, :machine_type, :location_id, :technician_id,
            :category, :description, :urgency, :status, :assigned_at
         ) RETURNING id'
    );
    $incident->execute([
        ':ticket' => $ticket,
        ':machine_id' => $machineId,
        ':machine_type' => 'COLD_DRINKS',
        ':location_id' => $locationId,
        ':technician_id' => $technicianId,
        ':category' => 'PRODUCT_JAM',
        ':description' => 'Avería sembrada para certificar que el traslado no mueve rutas.',
        ':urgency' => 'HIGH',
        ':status' => 'ASSIGNED',
        ':assigned_at' => '2026-10-20T08:30:00+02:00',
    ]);

    return (int)$incident->fetchColumn();
};

/** Cuenta filas de auditoría por acción. */
$auditCount = static function (PDO $pdo, string $action): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :action');
    $stmt->execute([':action' => $action]);

    return (int)$stmt->fetchColumn();
};

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, TRANSFER_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El esquema del repositorio converge con la migración de liberación de CP aplicada',
        in_array('008_zone_postal_code_release.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf('%d sentencias', $schemaReport['statements'])
    );

    $zoneRepo = new PdoOperationalZoneRepository($pgPdo);
    $locationRepo = new PdoLocationRepository($pgPdo);

    $zoneNorth = $zoneRepo->create(['code' => 'ZONA-06-NORTE', 'name' => 'Zona Norte de Traslado']);
    $zoneSouth = $zoneRepo->create(['code' => 'ZONA-06-SUR', 'name' => 'Zona Sur de Traslado', 'color_hex' => '#10b981']);
    $zoneForced = $zoneRepo->create(['code' => 'ZONA-06-FORZADA', 'name' => 'Zona Forzada de Traslado', 'color_hex' => '#f59e0b']);
    $zoneClosed = $zoneRepo->create(['code' => 'ZONA-06-CERRADA', 'name' => 'Zona Cerrada de Traslado', 'is_active' => false]);

    $northId = (int)$zoneNorth->getId();
    $southId = (int)$zoneSouth->getId();
    $forcedId = (int)$zoneForced->getId();
    $closedId = (int)$zoneClosed->getId();

    $linkPostalCode($pgPdo, '08001', $northId);
    $linkPostalCode($pgPdo, '08002', $northId);
    $linkPostalCode($pgPdo, '08003', $southId);

    $coordinator = $createUser($pgPdo, 'coordinacion', 'COORDINATOR');
    $technician = $createUser($pgPdo, 'tecnico', 'TECHNICIAN');

    $inheritedOne = $locationRepo->create([
        'site_code' => 'SEDE-06-HEREDADA-1',
        'name' => 'Sede Heredada Uno',
        'address' => 'Calle del Traslado 1, Barcelona',
        'postal_code' => '08001',
    ]);
    $inheritedTwo = $locationRepo->create([
        'site_code' => 'SEDE-06-HEREDADA-2',
        'name' => 'Sede Heredada Dos',
        'address' => 'Calle del Traslado 2, Barcelona',
        'postal_code' => '08002',
    ]);
    $forcedLocation = $locationRepo->create([
        'site_code' => 'SEDE-06-FORZADA',
        'name' => 'Sede Forzada',
        'address' => 'Calle del Traslado 3, Barcelona',
        'postal_code' => '08001',
    ]);
    $southLocation = $locationRepo->create([
        'site_code' => 'SEDE-06-SUR',
        'name' => 'Sede Sur',
        'address' => 'Calle del Traslado 4, Barcelona',
        'postal_code' => '08003',
    ]);

    $locationRepo->setManualOverride((int)$forcedLocation->getId(), $forcedId);

    $movingIncident = $seedIncident(
        $pgPdo,
        (int)$inheritedOne->getId(),
        $technician,
        'INC-Z06-TRASLADO-01'
    );

    $assert(
        'El escenario siembra dos sedes heredadas, una forzada y una del sur',
        $inheritedOne->getZoneId() === $northId
            && $inheritedTwo->getZoneId() === $northId
            && $locationRepo->findById((int)$forcedLocation->getId())?->getZoneId() === $forcedId
            && $southLocation->getZoneId() === $southId
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Traslado de dos CP con arrastre de sedes heredadas
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Traslado norte → sur y auditoría dual ---\n";

    $moved = $zoneRepo->transferPostalCodes($southId, ['08001', '08001', '08002'], $coordinator);

    $assert(
        'El traslado mueve las dos sedes heredadas y devuelve su recuento',
        $moved === 2,
        "sedes reasignadas={$moved}"
    );

    $assert(
        'Las sedes heredadas pasan a la zona destino',
        $locationRepo->findById((int)$inheritedOne->getId())?->getZoneId() === $southId
            && $locationRepo->findById((int)$inheritedTwo->getId())?->getZoneId() === $southId
    );

    $assert(
        'La sede con anulación manual conserva su zona (RF-03.3)',
        $locationRepo->findById((int)$forcedLocation->getId())?->getZoneId() === $forcedId
            && $locationRepo->findById((int)$forcedLocation->getId())?->isManualOverride() === true
    );

    $liveRows = $pgPdo->query(
        "SELECT postal_code, zone_id FROM operational_zone_postal_codes WHERE released_at IS NULL ORDER BY postal_code"
    )->fetchAll();
    $liveByCode = [];
    foreach ($liveRows as $row) {
        $liveByCode[(string)$row['postal_code']] = (int)$row['zone_id'];
    }
    $assert(
        'Mover nunca duplica: cada CP tiene una sola fila viva y apunta al destino',
        count($liveRows) === 3
            && $liveByCode['08001'] === $southId
            && $liveByCode['08002'] === $southId
            && $liveByCode['08003'] === $southId,
        json_encode($liveByCode)
    );

    $northPostalCodes = $zoneRepo->findPostalCodes($northId);
    $southPostalCodes = $zoneRepo->findPostalCodes($southId);
    $assert(
        'Las fichas vivas reflejan el movimiento (norte vacío, sur con tres)',
        $northPostalCodes === [] && $southPostalCodes === ['08001', '08002', '08003'],
        'norte=' . implode(',', $northPostalCodes) . ' · sur=' . implode(',', $southPostalCodes)
    );

    $assert(
        'La herencia por CP ya resuelve la zona destino',
        $locationRepo->findInheritedZoneByPostalCode('08001') === $southId
    );

    // ── Auditoría dual (QA 6) ──
    $matrixStmt = $pgPdo->prepare(
        "SELECT entity_type, entity_id, user_id, user_role, user_name, previous_state, new_state, metadata
         FROM audit_log WHERE action = 'ZONE_POSTAL_CODE_TRANSFERRED'"
    );
    $matrixStmt->execute();
    $matrixEvents = $matrixStmt->fetchAll();

    $matrix = $matrixEvents[0] ?? null;
    $matrixPrevious = $matrix !== null ? json_decode((string)$matrix['previous_state'], true) : null;
    $matrixNew = $matrix !== null ? json_decode((string)$matrix['new_state'], true) : null;

    $assert(
        'Se emite un único evento matriz sobre OPERATIONAL_ZONE con el balance del traslado',
        count($matrixEvents) === 1
            && $matrix['entity_type'] === 'OPERATIONAL_ZONE'
            && (int)$matrix['entity_id'] === $southId
            && (int)$matrix['user_id'] === $coordinator
            && $matrix['user_role'] === 'COORDINATOR'
            && $matrixNew['zone_id'] === $southId
            && $matrixNew['affected_locations_count'] === 2
            && $matrixPrevious['postal_codes'] === ['08001', '08002']
            && $matrixPrevious['zone_ids'] === [$northId],
        $matrix === null ? 'no se escribió el evento matriz' : json_encode($matrixNew)
    );

    $locationEvents = $pgPdo->query(
        "SELECT entity_id, previous_state, new_state FROM audit_log
         WHERE action = 'LOCATION_ZONE_REASSIGNED' ORDER BY entity_id"
    )->fetchAll();
    $reassignedIds = array_map(static fn (array $row): int => (int)$row['entity_id'], $locationEvents);

    $assert(
        'Cada sede afectada recibe su LOCATION_ZONE_REASSIGNED con zona anterior y nueva',
        count($locationEvents) === 2
            && $reassignedIds === [(int)$inheritedOne->getId(), (int)$inheritedTwo->getId()]
            && json_decode((string)$locationEvents[0]['previous_state'], true)['zone_id'] === $northId
            && json_decode((string)$locationEvents[0]['new_state'], true)['zone_id'] === $southId
    );

    $assert(
        'La sede forzada no genera evento de reasignación',
        $auditCount($pgPdo, 'LOCATION_ZONE_REASSIGNED') === 2
            && !in_array((int)$forcedLocation->getId(), $reassignedIds, true)
    );

    // RF-03.3: el traslado recoloca el territorio, no la ruta. Una avería asignada
    // en una sede heredada conserva técnico, estado y sede tras el movimiento.
    $incidentAfterTransfer = $pgPdo->prepare(
        'SELECT assigned_technician_id, status, location_id FROM incidents WHERE id = :id'
    );
    $incidentAfterTransfer->execute([':id' => $movingIncident]);
    $incidentRow = $incidentAfterTransfer->fetch();
    $assert(
        'El traslado del CP no reasigna la avería en curso de la sede heredada (RF-03.3)',
        $incidentRow !== false
            && (int)$incidentRow['assigned_technician_id'] === $technician
            && $incidentRow['status'] === 'ASSIGNED'
            && (int)$incidentRow['location_id'] === (int)$inheritedOne->getId(),
        json_encode($incidentRow)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Idempotencia: repetir el traslado no duplica ni reasigna
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Repetición del traslado ---\n";

    $movedAgain = $zoneRepo->transferPostalCodes($southId, ['08001'], $coordinator);
    $rowsForCode = (int)$pgPdo->query(
        "SELECT COUNT(*) FROM operational_zone_postal_codes WHERE postal_code = '08001'"
    )->fetchColumn();

    $assert(
        'Repetir el traslado con el CP ya en destino no reasigna sedes ni duplica filas',
        $movedAgain === 0 && $rowsForCode === 1,
        "sedes={$movedAgain} · filas 08001={$rowsForCode}"
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Fail-fast: CP liberado y zona destino inactiva
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Rechazos sin efectos ---\n";

    $linkPostalCode($pgPdo, '08009', $northId);
    $zoneRepo->releasePostalCodes($northId);

    $releasedRejected = null;
    try {
        $zoneRepo->transferPostalCodes($southId, ['08009'], $coordinator);
    } catch (ZoneValidationException $exception) {
        $releasedRejected = $exception;
    }
    $assert(
        'Un CP liberado no se puede trasladar (422) y su fila no se toca',
        $releasedRejected !== null
            && $releasedRejected->getHttpStatusCode() === 422
            && (int)$pgPdo->query(
                "SELECT COUNT(*) FROM operational_zone_postal_codes WHERE postal_code = '08009' AND released_at IS NOT NULL"
            )->fetchColumn() === 1
    );

    $inactiveRejected = null;
    try {
        $zoneRepo->transferPostalCodes($closedId, ['08003'], $coordinator);
    } catch (ZoneValidationException $exception) {
        $inactiveRejected = $exception;
    }
    $assert(
        'Una zona destino inactiva se rechaza y el CP sigue en el sur',
        $inactiveRejected !== null
            && $inactiveRejected->getFieldErrors()['zone_id'] !== ''
            && $locationRepo->findInheritedZoneByPostalCode('08003') === $southId
    );

    $missingUserRejected = null;
    try {
        $zoneRepo->transferPostalCodes($southId, ['08003'], 999999);
    } catch (InvalidArgumentException $exception) {
        $missingUserRejected = $exception;
    }
    $assert(
        'Sin usuario firmante no se abre la transacción ni se mueve nada',
        $missingUserRejected !== null
            && $locationRepo->findInheritedZoneByPostalCode('08003') === $southId
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Atomicidad: un fallo de auditoría deshace el traslado completo
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Rollback completo ante un fallo en mitad de la transacción ---\n";

    $failingAudit = new FailingAuditLogRepository(new PdoAuditLogRepository($pgPdo), 2);
    $failingRepo = new PdoOperationalZoneRepository($pgPdo, $failingAudit);

    $eventsBefore = $auditCount($pgPdo, 'ZONE_POSTAL_CODE_TRANSFERRED');
    $crash = null;
    try {
        $failingRepo->transferPostalCodes($northId, ['08003'], $coordinator);
    } catch (Throwable $exception) {
        $crash = $exception;
    }

    $southLocationZone = $locationRepo->findById((int)$southLocation->getId())?->getZoneId();
    $eventsAfter = $auditCount($pgPdo, 'ZONE_POSTAL_CODE_TRANSFERRED');

    $assert(
        'El fallo se propaga y no queda ni CP movido, ni sede reasignada, ni evento matriz',
        $crash !== null
            && $failingAudit->calls === 2
            && $locationRepo->findInheritedZoneByPostalCode('08003') === $southId
            && $southLocationZone === $southId
            && $eventsAfter === $eventsBefore,
        $crash === null ? 'el traslado no lanzó' : $crash->getMessage()
    );

    $assert(
        'La transacción queda cerrada y el repositorio sigue operativo',
        $pgPdo->inTransaction() === false
            && $zoneRepo->transferPostalCodes($northId, ['08003'], $coordinator) === 1
            && $locationRepo->findInheritedZoneByPostalCode('08003') === $northId
    );

    // ─────────────────────────────────────────────────────────────────────
    // Resumen
    // ─────────────────────────────────────────────────────────────────────
    echo "\n======================================================================\n";
    echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

    if ($failures === 0) {
        echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ZONE-06 CUMPLIDA CON ÉXITO.\n";
        echo "======================================================================\n";
        exit(0);
    }

    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
    echo "======================================================================\n";
    exit(1);
} finally {
    PostgresFixtureBuilder::dropIsolationSchema($pgPdo, TRANSFER_TEST_SCHEMA);
}
