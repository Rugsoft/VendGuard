<?php

declare(strict_types=1);

/**
 * OperationalZoneServiceTest · T-ZONE-07
 *
 * Certifica el **caso de uso** del catálogo territorial contra el motor real: las
 * confirmaciones que el servicio exige y la atomicidad que el repositorio aplica
 * no se pueden certificar con un doble en memoria, porque lo que se prueba es
 * justamente que el territorio no queda a medias.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Alta y edición (RF-01.1).** El alta normaliza código y color, deja los
 *      contadores a cero y firma `ZONE_CREATED`; la edición firma `ZONE_UPDATED`
 *      con el estado anterior y el resultante.
 *   2. **CP libres y CP en conflicto (RF-01.2 / RF-01.3).** Los libres se dan de
 *      alta; los que ya viven en otra zona responden `409
 *      POSTAL_CODES_REQUIRE_CONFIRMATION` con la zona de origen y las sedes
 *      heredadas, y solo se mueven con `confirm_transfers = true`, arrastrando a
 *      las sedes heredadas y respetando las forzadas a mano (RF-03.3, QA 6).
 *   3. **Titularidad única (RF-02.1 / RF-02.2).** La segunda titularidad responde
 *      `409 TECHNICIAN_ALREADY_PRIMARY_IN_OTHER_ZONE`; con
 *      `confirm_primary_override` la anterior se degrada a suplencia y queda
 *      auditada con `TECHNICIAN_ZONE_PRIMARY_DEMOTED` (QA 9). La baja es lógica y
 *      el reingreso estrena vinculación (QA 10).
 *   4. **Excepción manual de sede (RF-03.2 / RF-03.3).** Forzar fija zona y
 *      bandera, restablecer devuelve la herencia y ambos dejan
 *      `LOCATION_ZONE_MANUAL_OVERRIDE` en el rastro.
 *   5. **Desactivación con liberación (RF-01.3 / QA 7 / QA 10 / QA 13).** Sin
 *      `confirm_release` responde `422`; con confirmación, en una sola transacción:
 *      sedes a `NULL`, CP con `released_at`, cuadrante con `removed_at`, zona
 *      inactiva y `ZONE_DEACTIVATED` con el balance. El CP liberado se reasigna a
 *      otra zona y la reactivación **no** lo recupera.
 *
 * CATEGORÍA: `new_engine`
 *   Exige el PostgreSQL local de `deploy/local/postgres/`. Sin `PG_TEST_DSN` la
 *   suite no se omite en silencio: lo dice y sale con código 2.
 *
 * LIMPIEZA: el escenario vive en su propio esquema y se borra con `CASCADE` al
 * terminar, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/OperationalZoneServiceTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\OperationalZoneService;
use VendGuard\Core\Domain\Exception\LocationNotFoundException;
use VendGuard\Core\Domain\Exception\PostalCodeConflictException;
use VendGuard\Core\Domain\Exception\PrimaryTechnicianConflictException;
use VendGuard\Core\Domain\Exception\ZoneNotFoundException;
use VendGuard\Core\Domain\Exception\ZoneValidationException;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoOperationalZoneRepository;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta suite. */
const ZONE_SERVICE_TEST_SCHEMA = 't_zone07_service';

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
echo " VENDGUARD · T-ZONE-07 · Servicio de zonas, CPs, cuadrante y liberación (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures: usuarios, zonas, sedes
// ─────────────────────────────────────────────────────────────────────────────

$createUser = static function (PDO $pdo, string $suffix, string $role): int {
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
         VALUES (:name, :email, :email_key, :hash, :role, :operator, :is_active, :is_on_call)
         RETURNING id'
    );
    $stmt->execute([
        ':name' => ($role === 'COORDINATOR' ? 'Coordinación ' : 'Técnico ') . ucfirst($suffix),
        ':email' => 'zona07.' . $suffix . '@vendguard.internal',
        ':email_key' => 'zona07.' . $suffix . '@vendguard.internal',
        ':hash' => password_hash('zona07-' . $suffix, PASSWORD_BCRYPT),
        ':role' => $role,
        ':operator' => 'Z07-' . mb_strtoupper($suffix),
        ':is_active' => 'true',
        ':is_on_call' => 'false',
    ]);

    return (int)$stmt->fetchColumn();
};

/** Cuenta eventos de auditoría, opcionalmente por acción y entidad. */
$auditCount = static function (PDO $pdo, string $action, ?int $entityId = null): int {
    if ($entityId === null) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :action');
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :action AND entity_id = :entity_id');
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
        $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
    }
    $stmt->execute();

    return (int)$stmt->fetchColumn();
};

/** Último evento de una acción, para leer su actor y su carga útil. */
$lastAudit = static function (PDO $pdo, string $action): ?array {
    $stmt = $pdo->prepare('SELECT * FROM audit_log WHERE action = :action ORDER BY id DESC LIMIT 1');
    $stmt->bindValue(':action', $action, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch();

    return $row === false ? null : $row;
};

$isManualOverride = static function (PDO $pdo, int $locationId): bool {
    $stmt = $pdo->prepare('SELECT is_manual_override FROM locations WHERE id = :id');
    $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchColumn() === true;
};

$zoneOfLocation = static function (PDO $pdo, int $locationId): ?int {
    $stmt = $pdo->prepare('SELECT zone_id FROM locations WHERE id = :id');
    $stmt->bindValue(':id', $locationId, PDO::PARAM_INT);
    $stmt->execute();
    $value = $stmt->fetchColumn();

    return $value === false || $value === null ? null : (int)$value;
};

/**
 * Siembra una máquina y una avería asignada en la sede indicada.
 *
 * Sirve para certificar que ninguna operación territorial —traslado, desactivación
 * o baja de cuadrante— toca el expediente: la avería conserva técnico, estado y
 * sede (RF-03.3, casos límite 3 y 4).
 *
 * @return array{incident_id: int, machine_id: int}
 */
$seedIncident = static function (PDO $pdo, int $locationId, int $technicianId, string $ticket): array {
    $machine = $pdo->prepare(
        'INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, :code, :model, :type, :wing) RETURNING id'
    );
    $machine->execute([
        ':location_id' => $locationId,
        ':code' => 'MAQ-' . $ticket,
        ':model' => 'Modelo de certificación territorial',
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
        ':description' => 'Avería sembrada para certificar la preservación de la ruta.',
        ':urgency' => 'HIGH',
        ':status' => 'ASSIGNED',
        ':assigned_at' => '2026-10-20T09:00:00+02:00',
    ]);

    return ['incident_id' => (int)$incident->fetchColumn(), 'machine_id' => $machineId];
};

/** Estado vivo de una avería, para comparar antes y después de la operación territorial. */
$incidentSnapshot = static function (PDO $pdo, int $incidentId): array {
    $stmt = $pdo->prepare(
        'SELECT assigned_technician_id, status, location_id, machine_id FROM incidents WHERE id = :id'
    );
    $stmt->execute([':id' => $incidentId]);

    return $stmt->fetch() ?: [];
};

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: DDL base + migraciones en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, ZONE_SERVICE_TEST_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);

    $zoneRepo = new PdoOperationalZoneRepository($pgPdo);
    $locationRepo = new PdoLocationRepository($pgPdo);
    $service = new OperationalZoneService($zoneRepo, $locationRepo);

    $coordinatorId = $createUser($pgPdo, 'coord', 'COORDINATOR');
    $technicianOne = $createUser($pgPdo, 'uno', 'TECHNICIAN');
    $technicianTwo = $createUser($pgPdo, 'dos', 'TECHNICIAN');

    // ─────────────────────────────────────────────────────────────────────
    // 1. Alta, edición y ficha (RF-01.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Alta, edición y ficha de zona ---\n";

    $assert('El catálogo arranca vacío', $service->listZones() === []);

    $north = $service->createZone([
        'code' => 'zona-norte-s07',
        'name' => 'Zona Norte de Certificación',
        'color_hex' => '#2560FF',
        'description' => 'Demarcación norte del arnés',
    ], $coordinatorId);

    $assert(
        'El alta normaliza el código y el color y devuelve la ficha con contadores a cero',
        $north->id > 0 && $north->code === 'ZONA-NORTE-S07' && $north->colorHex === '#2560ff'
            && $north->isActive === true && $north->postalCodes === []
            && $north->locationsCount === 0 && $north->primaryTechniciansCount === 0,
        json_encode($north->toArray())
    );

    $createdEvent = $lastAudit($pgPdo, 'ZONE_CREATED');
    $assert(
        'El alta queda auditada como ZONE_CREATED con el coordinador que la firma',
        $createdEvent !== null
            && (int)$createdEvent['entity_id'] === $north->id
            && $createdEvent['entity_type'] === 'OPERATIONAL_ZONE'
            && $createdEvent['user_role'] === 'COORDINATOR'
            && (int)$createdEvent['user_id'] === $coordinatorId
    );

    $notFound = null;
    try {
        $service->getZone(999999);
    } catch (ZoneNotFoundException $exception) {
        $notFound = $exception;
    }
    $assert(
        'Una zona inexistente responde ZONE_NOT_FOUND (404)',
        $notFound !== null && $notFound->getHttpStatusCode() === 404,
        $notFound?->getErrorCode() ?? 'no se lanzó'
    );

    $north = $service->updateZone($north->id, [
        'name' => 'Zona Norte Renombrada',
        'description' => 'Descripción editada',
    ], $coordinatorId);
    $updatedEvent = $lastAudit($pgPdo, 'ZONE_UPDATED');

    $assert(
        'La edición persiste los metadatos y firma ZONE_UPDATED con estado previo y nuevo',
        $north->name === 'Zona Norte Renombrada'
            && $north->description === 'Descripción editada'
            && $updatedEvent !== null
            && str_contains((string)$updatedEvent['previous_state'], 'Zona Norte de Certificación')
            && str_contains((string)$updatedEvent['new_state'], 'Zona Norte Renombrada')
    );

    $south = $service->createZone([
        'code' => 'ZONA-SUR-S07',
        'name' => 'Zona Sur de Certificación',
        'color_hex' => '#10b981',
    ], $coordinatorId);
    $east = $service->createZone([
        'code' => 'ZONA-ESTE-S07',
        'name' => 'Zona Este de Certificación',
        'color_hex' => '#dc2626',
    ], $coordinatorId);

    $assert(
        'El listado devuelve las tres zonas ordenadas por código con su forma de contrato',
        count($service->listZones()) === 3
            && array_keys($service->listZones()[0]->toArray()) === [
                'id', 'code', 'name', 'color_hex', 'description', 'is_active',
                'locations_count', 'primary_technicians_count', 'backup_technicians_count',
                'postal_codes_count', 'postal_codes', 'created_at', 'updated_at',
            ]
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Códigos postales: altas, conflictos y traslados (RF-01.2 / RF-01.3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Códigos postales: altas, conflictos y traslados ---\n";

    $assigned = $service->assignPostalCodes($north->id, ['08002', '08001', '08002'], $coordinatorId);

    $assert(
        'El alta de CP libres normaliza, deduplica y ordena el lote',
        $assigned->assignedPostalCodes === ['08001', '08002']
            && $assigned->transferredPostalCodes === []
            && $assigned->affectedLocationsCount === 0,
        json_encode($assigned->toArray())
    );
    $assert(
        'Los CP vivos aparecen en la ficha de la zona',
        $service->getZone($north->id)->postalCodes === ['08001', '08002']
            && $service->getZone($north->id)->postalCodesCount === 2
    );

    $invalidPostalCode = null;
    try {
        $service->assignPostalCodes($north->id, ['BARCELONA'], $coordinatorId);
    } catch (ZoneValidationException $exception) {
        $invalidPostalCode = $exception;
    }
    $assert(
        'Un CP no numérico se rechaza con BAD_REQUEST (400)',
        $invalidPostalCode !== null && $invalidPostalCode->getHttpStatusCode() === 400,
        $invalidPostalCode?->getErrorCode() ?? 'no se lanzó'
    );

    $emptyBatch = null;
    try {
        $service->assignPostalCodes($north->id, [], $coordinatorId);
    } catch (ZoneValidationException $exception) {
        $emptyBatch = $exception;
    }
    $assert(
        'Un lote vacío se rechaza con el campo postal_codes señalado',
        $emptyBatch !== null && isset($emptyBatch->getFieldErrors()['postal_codes'])
    );

    // Las sedes se dan de alta con el catálogo ya poblado: la herencia se resuelve
    // en el alta (RF-03.1), que es el momento en que el coordinador declara el CP.
    $inheritedLocation = $locationRepo->create([
        'site_code' => 'SEDE-Z07-HEREDADA',
        'name' => 'Sede heredada del CP 08001',
        'address' => 'Calle de la Certificación 1, Barcelona',
        'postal_code' => '08001',
    ]);
    $northLocation = $locationRepo->create([
        'site_code' => 'SEDE-Z07-NORTE',
        'name' => 'Sede norte del CP 08002',
        'address' => 'Calle de la Certificación 2, Barcelona',
        'postal_code' => '08002',
    ]);
    $forcedLocation = $locationRepo->create([
        'site_code' => 'SEDE-Z07-FORZADA',
        'name' => 'Sede forzada a Este',
        'address' => 'Calle de la Certificación 3, Barcelona',
        'postal_code' => '08001',
    ]);

    $assert(
        'Las sedes heredan la zona activa de su código postal en el alta',
        $inheritedLocation->getZoneId() === $north->id
            && $northLocation->getZoneId() === $north->id
            && $forcedLocation->getZoneId() === $north->id
    );

    // Excepción manual: la sede forzada a Este conserva su zona pese a heredar 08001.
    $forced = $service->overrideLocationZone($forcedLocation->getId(), $east->id, $coordinatorId, true);
    $assert(
        'La anulación manual fija la zona forzada y enciende is_manual_override',
        $forced['zone_id'] === $east->id && $forced['is_manual_override'] === true
            && $forced['resolution_mode'] === 'MANUAL_OVERRIDE'
            && $isManualOverride($pgPdo, $forcedLocation->getId()) === true,
        json_encode($forced)
    );
    $overrideEvent = $lastAudit($pgPdo, 'LOCATION_ZONE_MANUAL_OVERRIDE');
    $assert(
        'La anulación manual queda auditada sobre la sede',
        $overrideEvent !== null
            && (int)$overrideEvent['entity_id'] === $forcedLocation->getId()
            && $overrideEvent['entity_type'] === 'LOCATION'
            && str_contains((string)$overrideEvent['metadata'], 'MANUAL_OVERRIDE')
    );

    $reverted = $service->overrideLocationZone($forcedLocation->getId(), null, $coordinatorId, false);
    $zoneAfterReversion = $zoneOfLocation($pgPdo, $forcedLocation->getId());
    $assert(
        'Restablecer la herencia devuelve la sede al CP y apaga la bandera',
        $reverted['resolution_mode'] === 'INHERITED_BY_POSTAL_CODE'
            && $reverted['is_manual_override'] === false
            && $zoneAfterReversion === $north->id,
        json_encode($reverted)
    );

    // Volvemos a forzarla para certificar que el traslado la respeta.
    $service->overrideLocationZone($forcedLocation->getId(), $east->id, $coordinatorId, true);

    $missingLocation = null;
    try {
        $service->overrideLocationZone(999999, $east->id, $coordinatorId, true);
    } catch (LocationNotFoundException $exception) {
        $missingLocation = $exception;
    }
    $assert('Una sede inexistente responde LOCATION_NOT_FOUND (404)', $missingLocation !== null);

    $conflict = null;
    try {
        $service->assignPostalCodes($south->id, ['08001', '08003'], $coordinatorId);
    } catch (PostalCodeConflictException $exception) {
        $conflict = $exception;
    }
    $details = $conflict?->getConflicts() ?? [];
    $assert(
        'Un CP de otra zona exige confirmación (409) con su zona de origen y sedes heredadas',
        $conflict !== null
            && $conflict->getHttpStatusCode() === 409
            && $conflict->getErrorCode() === 'POSTAL_CODES_REQUIRE_CONFIRMATION'
            && $details === [[
                'postal_code' => '08001',
                'current_zone_id' => $north->id,
                'current_zone_name' => 'Zona Norte Renombrada',
                'affected_locations_count' => 1,
            ]],
        json_encode($details)
    );
    $assert(
        'Sin confirmación no se movió nada: el CP sigue en la zona de origen',
        $service->getZone($north->id)->postalCodes === ['08001', '08002']
    );

    $transfer = $service->assignPostalCodes($south->id, ['08001', '08003'], $coordinatorId, true);

    $assert(
        'Con confirmación el CP se traslada y el libre se da de alta en la misma operación',
        $transfer->zoneId === $south->id
            && $transfer->assignedPostalCodes === ['08001', '08003']
            && $transfer->transferredPostalCodes === [[
                'postal_code' => '08001',
                'previous_zone_id' => $north->id,
                'previous_zone_name' => 'Zona Norte Renombrada',
            ]]
            && $transfer->affectedLocationsCount === 1,
        json_encode($transfer->toArray())
    );
    $assert(
        'El CP salió de la zona de origen y la sede heredada siguió al CP; la forzada no se movió',
        $service->getZone($north->id)->postalCodes === ['08002']
            && $service->getZone($south->id)->postalCodes === ['08001', '08003']
            && $zoneOfLocation($pgPdo, $inheritedLocation->getId()) === $south->id
            && $zoneOfLocation($pgPdo, $forcedLocation->getId()) === $east->id
            && $zoneOfLocation($pgPdo, $northLocation->getId()) === $north->id
    );
    $assert(
        'El traslado deja el evento matriz y el evento hijo de la sede afectada',
        $auditCount($pgPdo, 'ZONE_POSTAL_CODE_TRANSFERRED', $south->id) === 1
            && $auditCount($pgPdo, 'LOCATION_ZONE_REASSIGNED', $inheritedLocation->getId()) === 1
    );

    $inactiveZone = null;
    try {
        $service->assignPostalCodes(999999, ['08010'], $coordinatorId);
    } catch (ZoneNotFoundException $exception) {
        $inactiveZone = $exception;
    }
    $assert('Asignar CP a una zona inexistente responde 404', $inactiveZone !== null);

    // ─────────────────────────────────────────────────────────────────────
    // 3. Cuadrante técnico: titularidad, degradación y baja lógica (RF-02)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Cuadrante técnico: titularidad, degradación y baja lógica ---\n";

    $assignment = $service->assignTechnician($technicianOne, $north->id, true, $coordinatorId);
    $assert(
        'La vinculación como titular devuelve la asignación con los datos del técnico',
        ($assignment['assignment']['technician_id'] ?? null) === $technicianOne
            && ($assignment['assignment']['zone_id'] ?? null) === $north->id
            && ($assignment['assignment']['is_primary'] ?? null) === true
            && is_string($assignment['assignment']['technician_name'] ?? null)
            && (int)$assignment['assignment']['id'] > 0,
        json_encode($assignment['assignment'])
    );
    $assert(
        'La vinculación queda auditada con TECHNICIAN_ZONE_ASSIGNED',
        $auditCount($pgPdo, 'TECHNICIAN_ZONE_ASSIGNED', (int)$assignment['assignment']['id']) === 1
    );

    $primaryConflict = null;
    try {
        $service->assignTechnician($technicianOne, $south->id, true, $coordinatorId);
    } catch (PrimaryTechnicianConflictException $exception) {
        $primaryConflict = $exception;
    }
    $assert(
        'La segunda titularidad sin confirmación responde 409 con la zona que se perdería',
        $primaryConflict !== null
            && $primaryConflict->getHttpStatusCode() === 409
            && $primaryConflict->getCurrentZoneId() === $north->id
            && $primaryConflict->getCurrentZoneName() === 'Zona Norte Renombrada'
    );

    $overridden = $service->assignTechnician($technicianOne, $south->id, true, $coordinatorId, true);
    $demotions = $zoneRepo->findTechnicianAssignments($north->id);
    $demotedRow = null;
    foreach ($demotions as $row) {
        if ($row['technician_id'] === $technicianOne) {
            $demotedRow = $row;
        }
    }
    $assert(
        'Con confirm_primary_override la titularidad previa se degrada sin extinguirse y se audita',
        $demotedRow !== null
            && $demotedRow['is_primary'] === false
            && ($overridden['assignment']['zone_id'] ?? null) === $south->id
            && $auditCount($pgPdo, 'TECHNICIAN_ZONE_PRIMARY_DEMOTED') === 1,
        json_encode($demotedRow)
    );

    // Caso límite 4: la baja del cuadrante no reasigna la ruta en curso del técnico.
    $routeIncident = $seedIncident(
        $pgPdo,
        (int)$inheritedLocation->getId(),
        $technicianOne,
        'INC-Z07-RUTA-01'
    );
    $routeBefore = $incidentSnapshot($pgPdo, $routeIncident['incident_id']);

    $removed = $service->removeTechnician($technicianOne, $south->id, $coordinatorId);
    $assert(
        'La baja del cuadrante es lógica y queda auditada',
        $removed === true && $auditCount($pgPdo, 'TECHNICIAN_ZONE_REMOVED') === 1
    );

    $routeAfter = $incidentSnapshot($pgPdo, $routeIncident['incident_id']);
    $assert(
        'Quitar al técnico del cuadrante conserva sus averías asignadas (caso límite 4)',
        $routeBefore !== []
            && $routeAfter === $routeBefore
            && (int)$routeAfter['assigned_technician_id'] === $technicianOne
            && $routeAfter['status'] === 'ASSIGNED',
        json_encode([$routeBefore, $routeAfter])
    );

    $reentry = $service->assignTechnician($technicianOne, $south->id, false, $coordinatorId);
    $liveCount = 0;
    $rowCount = 0;
    foreach ($zoneRepo->findTechnicianAssignments($south->id) as $row) {
        if ($row['technician_id'] === $technicianOne) {
            $rowCount++;
        }
    }
    $liveCount = $rowCount;
    $assert(
        'El reingreso estrena vinculación (la extinguida no se reactiva)',
        (int)$reentry['assignment']['id'] > 0
            && $liveCount === 1
            && $rowCount === 1
            && (int)$reentry['assignment']['id'] !== (int)$assignment['assignment']['id']
    );
    $historical = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM technician_zone_assignments WHERE technician_id = ' . $technicianOne
            . ' AND zone_id = ' . $south->id
    )->fetchColumn();
    $assert('El histórico conserva la vinculación extinguida', $historical === 2, 'filas: ' . $historical);

    $service->assignTechnician($technicianTwo, $north->id, true, $coordinatorId);

    // ─────────────────────────────────────────────────────────────────────
    // 4. Desactivación con liberación territorial (RF-01.3 / QA 7 / 13)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Desactivación con liberación territorial ---\n";

    $releaseGuard = null;
    try {
        $service->deactivateZone($north->id, $coordinatorId);
    } catch (ZoneValidationException $exception) {
        $releaseGuard = $exception;
    }
    $assert(
        'Desactivar una zona con CP, sedes y cuadrante exige confirm_release (422)',
        $releaseGuard !== null
            && $releaseGuard->getHttpStatusCode() === 422
            && isset($releaseGuard->getFieldErrors()['confirm_release'])
            && $service->getZone($north->id)->isActive === true,
        $releaseGuard?->getMessage() ?? 'no se lanzó'
    );

    $updateGuard = null;
    try {
        $service->updateZone($north->id, ['is_active' => false], $coordinatorId);
    } catch (ZoneValidationException $exception) {
        $updateGuard = $exception;
    }
    $assert(
        'La edición tampoco puede desactivar sin confirmación: delega en la misma guarda',
        $updateGuard !== null && $updateGuard->getHttpStatusCode() === 422
    );

    // Caso límite 3: desactivar la zona no toca las averías abiertas de sus sedes.
    $openIncident = $seedIncident(
        $pgPdo,
        (int)$northLocation->getId(),
        $technicianTwo,
        'INC-Z07-ABIERTA-01'
    );
    $openBefore = $incidentSnapshot($pgPdo, $openIncident['incident_id']);

    $deactivated = $service->deactivateZone($north->id, $coordinatorId, true);

    $releasedAt = $pgPdo->query(
        "SELECT released_at FROM operational_zone_postal_codes WHERE zone_id = " . $north->id
    )->fetchAll();
    $technicianRows = $pgPdo->query(
        'SELECT removed_at FROM technician_zone_assignments WHERE zone_id = ' . $north->id
    )->fetchAll();
    $stillExists = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM operational_zone_postal_codes WHERE zone_id = ' . $north->id
    )->fetchColumn();

    $assert(
        'La desactivación deja las sedes en «Sin Zona Asignada» y conserva su fila',
        $deactivated->isActive === false
            && $zoneOfLocation($pgPdo, $northLocation->getId()) === null
            && $zoneOfLocation($pgPdo, $inheritedLocation->getId()) === $south->id
            && $zoneOfLocation($pgPdo, $forcedLocation->getId()) === $east->id
    );
    $assert(
        'Los CP se liberan con released_at sin borrar la fila (QA 13)',
        $stillExists === 1
            && $releasedAt !== []
            && array_reduce($releasedAt, static fn (bool $carry, array $row): bool => $carry && $row['released_at'] !== null, true)
            && $service->getZone($north->id)->postalCodes === [],
        'filas: ' . $stillExists
    );
    $assert(
        'El cuadrante se extingue con removed_at',
        $technicianRows !== []
            && array_reduce($technicianRows, static fn (bool $carry, array $row): bool => $carry && $row['removed_at'] !== null, true)
            && $zoneRepo->findTechnicianAssignments($north->id) === []
    );

    $deactivatedEvent = $lastAudit($pgPdo, 'ZONE_DEACTIVATED');
    $assert(
        'La desactivación firma ZONE_DEACTIVATED con el balance de la liberación',
        $deactivatedEvent !== null
            && (int)$deactivatedEvent['entity_id'] === $north->id
            && str_contains((string)$deactivatedEvent['metadata'], 'released_postal_codes')
            && str_contains((string)$deactivatedEvent['metadata'], 'unassigned_locations_count')
    );

    $openAfter = $incidentSnapshot($pgPdo, $openIncident['incident_id']);
    $assert(
        'Desactivar la zona conserva las averías abiertas con su técnico y su estado (caso límite 3)',
        $openBefore !== []
            && $openAfter === $openBefore
            && (int)$openAfter['assigned_technician_id'] === $technicianTwo
            && $openAfter['status'] === 'ASSIGNED',
        json_encode([$openBefore, $openAfter])
    );

    $idempotent = $service->deactivateZone($north->id, $coordinatorId);
    $assert(
        'Repetir la desactivación es idempotente y no vuelve a auditar',
        $idempotent->isActive === false && $auditCount($pgPdo, 'ZONE_DEACTIVATED', $north->id) === 1
    );

    $reassignedAfterRelease = $service->assignPostalCodes($south->id, ['08002'], $coordinatorId);
    $assert(
        'Un CP liberado puede asignarse a otra zona sin confirmación de traslado',
        $reassignedAfterRelease->assignedPostalCodes === ['08002']
            && $reassignedAfterRelease->transferredPostalCodes === []
            && $service->getZone($south->id)->postalCodes === ['08001', '08002', '08003']
    );

    $inactiveGuard = null;
    try {
        $service->assignTechnician($technicianTwo, $north->id, false, $coordinatorId);
    } catch (ZoneValidationException $exception) {
        $inactiveGuard = $exception;
    }
    $assert(
        'Una zona desactivada no admite cuadrante',
        $inactiveGuard !== null && $inactiveGuard->getHttpStatusCode() === 422
    );

    $reactivated = $service->updateZone($north->id, ['is_active' => true], $coordinatorId);
    $assert(
        'Reactivar la zona no recupera los CP liberados',
        $reactivated->isActive === true && $reactivated->postalCodes === []
    );

    $renamedSouth = $service->updateZone($south->id, ['name' => 'Barcelona Sur'], $coordinatorId);
    $southAudit = $lastAudit($pgPdo, 'ZONE_UPDATED');
    $assert(
        'La edición de metadatos sin desactivación no libera el territorio',
        $renamedSouth->name === 'Barcelona Sur'
            && count($renamedSouth->postalCodes) === 3
            && $southAudit !== null
            && (int)$southAudit['entity_id'] === $south->id
    );

    $catalog = $service->listZones();
    $byCode = [];
    foreach ($catalog as $zone) {
        $byCode[$zone->code] = $zone;
    }
    $assert(
        'El listado refleja contadores y estados tras el ciclo completo',
        $byCode['ZONA-SUR-S07']->locationsCount === 1
            && $byCode['ZONA-SUR-S07']->postalCodesCount === 3
            && $byCode['ZONA-NORTE-S07']->isActive === true
            && $byCode['ZONA-NORTE-S07']->locationsCount === 0
            && $byCode['ZONA-NORTE-S07']->primaryTechniciansCount === 0,
        json_encode(array_map(
            static fn ($zone): array => [
                'sedes' => $zone->locationsCount,
                'cps' => $zone->postalCodesCount,
                'activa' => $zone->isActive,
                'titulares' => $zone->primaryTechniciansCount,
            ],
            $byCode
        ))
    );
} catch (Throwable $exception) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $exception->getMessage() . "\n";
    echo $exception->getTraceAsString() . "\n";
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, ZONE_SERVICE_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        echo "\n  [FAIL] No se pudo retirar el escenario: " . $cleanupFailure->getMessage() . "\n";
        $failures++;
    }
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ZONE-07 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
