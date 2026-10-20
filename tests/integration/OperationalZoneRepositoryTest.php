<?php

declare(strict_types=1);

/**
 * OperationalZoneRepositoryTest · T-ZONE-05
 *
 * Certifica **contra el motor real** la capa de persistencia del Módulo 17: el
 * catálogo de zonas con sus contadores, el cuadrante técnico y la herencia
 * territorial de la sede. No prueba el modelo de dominio de la zona —eso lo cerró
 * T-ZONE-03— ni el servicio de zonas —T-ZONE-07—, sino las garantías que ningún
 * camino de código puede saltarse:
 *
 *   1. **Listado en una sola sentencia (RNF-01).** Los contadores de sedes,
 *      titulares, suplentes y códigos postales viajan juntos: el número de
 *      sentencias no crece con el número de zonas.
 *   2. **Titularidad única (RF-02.1).** El intento de una segunda titularidad viva
 *      se rechaza con la zona que se perdería; extinguida la anterior, el mismo
 *      técnico puede asumir la nueva.
 *   3. **Cuadrante (RF-02.2).** Solo técnicos activos entran; la baja es lógica y
 *      el reingreso estrena vinculación (QA 10).
 *   4. **Herencia por CP (RF-03.1).** El alta con CP resuelve la zona activa y el
 *      CP sin zona queda en «Sin Zona Asignada», no en error.
 *   5. **Anulación manual (RF-03.2 / RF-03.3).** `setManualOverride()` fija zona y
 *      bandera de forma atómica, y mientras está activa el CP no recoloca la sede;
 *      `clearManualOverride()` devuelve la herencia.
 *   6. **Obligatoriedad del CP (QA 7, DoD-10).** `AdminLocationService` rechaza el
 *      alta y la edición sin CP y persiste el CP validado cuando lo hay.
 *   7. **Coste de la herencia.** `findInheritedZoneByPostalCode()` resuelve por
 *      clave primaria por debajo de 5 ms de mediana, que es el presupuesto que fija
 *      la tarea para el alta y la edición de sedes.
 *
 * CATEGORÍA: `new_engine`
 *   Exige el PostgreSQL local de `deploy/local/postgres/`. Sin `PG_TEST_DSN` la
 *   suite **no se omite en silencio**: lo dice y sale con código 2.
 *
 * LIMPIEZA: el escenario vive en su propio esquema y se borra con `CASCADE` al
 * terminar, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php tests/integration/OperationalZoneRepositoryTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Application\Service\AdminLocationService;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Core\Domain\Exception\PrimaryTechnicianConflictException;
use VendGuard\Core\Domain\Exception\ZoneValidationException;
use VendGuard\Infrastructure\Database\PostgresMigrationRunner;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoOperationalZoneRepository;
use VendGuard\Infrastructure\Repository\PdoSiteAccessCodeRepository;

/** Marca de categoría: `run_all.php` la lee para no confundir esta suite con la batería hermética. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado de esta suite. */
const ZONE_REPO_TEST_SCHEMA = 't_zone05_repository';

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

/**
 * PDO que cuenta las sentencias que el repositorio le pide.
 *
 * Es la forma de certificar «un solo round-trip» sin leer el código: si el
 * listado hiciera una consulta por zona, el contador crecería con el escenario.
 */
final class StatementCountingPdo extends PDO
{
    public int $statements = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->statements++;

        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->statements++;

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

echo "======================================================================\n";
echo " VENDGUARD · T-ZONE-05 · Repositorio de zonas y herencia territorial (PostgreSQL)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();
$dsn = (string)getenv('PG_TEST_DSN');

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures mínimos: zonas, CPs, técnicos y sedes
// ─────────────────────────────────────────────────────────────────────────────

/** Alta de técnico con rol `TECHNICIAN` y clave canónica. */
$createTechnician = static function (PDO $pdo, string $suffix, bool $isActive = true, bool $isOnCall = false): int {
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active, is_on_call)
         VALUES (:name, :email, :email_key, :hash, \'TECHNICIAN\', :operator, :is_active, :is_on_call)
         RETURNING id'
    );
    $stmt->execute([
        ':name' => 'Técnico de Certificación ' . ucfirst($suffix),
        ':email' => 'tecnico.zone05.' . $suffix . '@vendguard.internal',
        ':email_key' => 'tecnico.zone05.' . $suffix . '@vendguard.internal',
        ':hash' => password_hash('zona05-' . $suffix, PASSWORD_BCRYPT),
        ':operator' => 'Z05-' . mb_strtoupper($suffix),
        // Los booleanos viajan como literal porque `execute(array)` los enviaría
        // como cadena vacía cuando son falsos; el repositorio los enlaza con
        // `PDO::PARAM_BOOL`, que sí conserva el tipo.
        ':is_active' => $isActive ? 'true' : 'false',
        ':is_on_call' => $isOnCall ? 'true' : 'false',
    ]);

    return (int)$stmt->fetchColumn();
};

/** Vincula un CP a una zona (el alta de CPs pertenece a T-ZONE-06/T-ZONE-07). */
$linkPostalCode = static function (PDO $pdo, string $postalCode, int $zoneId): void {
    $stmt = $pdo->prepare(
        'INSERT INTO operational_zone_postal_codes (postal_code, zone_id) VALUES (:postal_code, :zone_id)'
    );
    $stmt->execute([':postal_code' => $postalCode, ':zone_id' => $zoneId]);
};

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario: el esquema del repositorio en un esquema aislado
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migración 007 en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, ZONE_REPO_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El DDL base y la migración 007 aplican sin errores y el esquema converge',
        in_array('007_operational_zones.sql', $schemaReport['migrations'], true)
            && in_array('008_zone_postal_code_release.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf('%d sentencias · migraciones: %s', $schemaReport['statements'], implode(', ', $schemaReport['migrations']))
    );

    $zoneRepo = new PdoOperationalZoneRepository($pgPdo);
    $locationRepo = new PdoLocationRepository($pgPdo);

    // El contador abre su propia conexión sobre el mismo esquema aislado: es el
    // único modo de medir lo que el repositorio pide al motor sin tocar el código.
    $countingPdo = new StatementCountingPdo($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]);
    $countingPdo->exec('SET search_path TO ' . ZONE_REPO_TEST_SCHEMA . ', public');
    $countingZoneRepo = new PdoOperationalZoneRepository($countingPdo);

    $zoneNorth = $zoneRepo->create([
        'code' => 'zona-norte-cert',
        'name' => 'Zona Norte de Certificación',
        'color_hex' => '#2560ff',
        'description' => 'Demarcación norte del arnés',
    ]);
    $zoneSouth = $zoneRepo->create([
        'code' => 'ZONA-SUR-CERT',
        'name' => 'Zona Sur de Certificación',
        'color_hex' => '#10B981',
    ]);
    $zoneClosed = $zoneRepo->create([
        'code' => 'ZONA-CERRADA-CERT',
        'name' => 'Zona Desactivada de Certificación',
        'is_active' => false,
    ]);

    $assert(
        'El alta normaliza el código a mayúsculas, el color a minúsculas y devuelve el id',
        $zoneNorth->getId() !== null && $zoneNorth->getCode() === 'ZONA-NORTE-CERT'
            && $zoneNorth->getColorHex() === '#2560ff'
            && $zoneSouth->getColorHex() === '#10b981'
            && $zoneClosed->isActive() === false
    );

    $duplicateCode = null;
    try {
        $zoneRepo->create(['code' => 'ZONA-NORTE-CERT', 'name' => 'Otra Zona Norte']);
    } catch (PDOException $exception) {
        $duplicateCode = $exception->getCode();
    }
    $assert(
        'El código único de zona lo impone el esquema (23505)',
        $duplicateCode === '23505',
        (string)$duplicateCode
    );

    $northId = (int)$zoneNorth->getId();
    $southId = (int)$zoneSouth->getId();
    $closedId = (int)$zoneClosed->getId();

    $assert(
        'findByCode recupera también una zona desactivada y discierne mayúsculas',
        $zoneRepo->findByCode(' zona-cerrada-cert ')?->getId() === $closedId
    );
    $assert(
        'findById excluye por defecto la zona desactivada y la devuelve con includeInactive',
        $zoneRepo->findById($closedId) === null && $zoneRepo->findById($closedId, true)?->getId() === $closedId
    );

    $linkPostalCode($pgPdo, '08001', $northId);
    $linkPostalCode($pgPdo, '08002', $northId);
    $linkPostalCode($pgPdo, '08003', $southId);

    $technicianPrimary = $createTechnician($pgPdo, 'titular');
    $technicianBackup = $createTechnician($pgPdo, 'suplente');
    $technicianOnCall = $createTechnician($pgPdo, 'guardia', true, true);
    $technicianInactive = $createTechnician($pgPdo, 'inactivo', false);

    $northLocationOne = $locationRepo->create([
        'site_code' => 'SEDE-Z05-NORTE-1',
        'name' => 'Sede Norte Uno',
        'address' => 'Calle de la Certificación 1, Barcelona',
        'postal_code' => '08001',
    ]);
    $locationRepo->create([
        'site_code' => 'SEDE-Z05-NORTE-2',
        'name' => 'Sede Norte Dos',
        'address' => 'Calle de la Certificación 2, Barcelona',
        'postal_code' => '08002',
    ]);
    $locationRepo->create([
        'site_code' => 'SEDE-Z05-SUR-1',
        'name' => 'Sede Sur Uno',
        'address' => 'Calle de la Certificación 3, Barcelona',
        'postal_code' => '08003',
    ]);
    $orphanLocation = $locationRepo->create([
        'site_code' => 'SEDE-Z05-SIN-ZONA',
        'name' => 'Sede sin demarcación',
        'address' => 'Calle de la Certificación 4, Barcelona',
        'postal_code' => '99999',
    ]);

    $assert(
        'El alta de sede hereda la zona activa de su CP y deja en «Sin Zona» el CP sin catálogo',
        $northLocationOne->getZoneId() === $northId
            && $northLocationOne->getPostalCode() === '08001'
            && $orphanLocation->getZoneId() === null
            && $orphanLocation->getPostalCode() === '99999'
    );
    $assert(
        'La sede nace sin anulación manual',
        $northLocationOne->isManualOverride() === false
    );

    $zoneRepo->assignTechnician($technicianPrimary, $northId, true);
    $zoneRepo->assignTechnician($technicianBackup, $northId, false);
    $zoneRepo->assignTechnician($technicianOnCall, $northId, false);

    // ─────────────────────────────────────────────────────────────────────
    // 1. Listado con contadores en una sola sentencia (RNF-01)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. Listado agregado: una sola sentencia ---\n";

    $rows = $countingZoneRepo->findAllWithCounts();
    $byCode = [];
    foreach ($rows as $row) {
        $byCode[$row['zone']->getCode()] = $row;
    }

    $assert(
        'Los contadores de sedes, titulares, suplentes y CPs son exactos por zona',
        $byCode['ZONA-NORTE-CERT']['locations_count'] === 2
            && $byCode['ZONA-NORTE-CERT']['primary_technicians_count'] === 1
            && $byCode['ZONA-NORTE-CERT']['backup_technicians_count'] === 2
            && $byCode['ZONA-NORTE-CERT']['postal_codes_count'] === 2
            && $byCode['ZONA-SUR-CERT']['locations_count'] === 1
            && $byCode['ZONA-SUR-CERT']['primary_technicians_count'] === 0
            && $byCode['ZONA-SUR-CERT']['postal_codes_count'] === 1,
        json_encode(array_map(
            static fn (array $row): array => [
                $row['locations_count'],
                $row['primary_technicians_count'],
                $row['backup_technicians_count'],
                $row['postal_codes_count'],
            ],
            $byCode
        ))
    );

    $assert(
        'Una zona sin sedes ni cuadrante aparece con ceros, no desaparece',
        $byCode['ZONA-CERRADA-CERT']['locations_count'] === 0
            && $byCode['ZONA-CERRADA-CERT']['backup_technicians_count'] === 0
            && $byCode['ZONA-CERRADA-CERT']['postal_codes_count'] === 0
    );

    $assert(
        'El listado completo incluye la zona desactivada y el filtrado la excluye',
        count($rows) === 3 && count($countingZoneRepo->findAllWithCounts(false)) === 2
    );

    // La medición se reinicia y se ejecuta una sola vez: es la sentencia del
    // listado la que debe bastar, no una casualidad del escenario anterior.
    $countingPdo->statements = 0;
    $countingZoneRepo->findAllWithCounts();
    $assert(
        'Los contadores no cuestan una consulta por zona: el listado es UNA sentencia',
        $countingPdo->statements === 1,
        'sentencias=' . $countingPdo->statements
    );

    $assert(
        'El listado ordena por código para que el panel sea estable',
        $rows[0]['zone']->getCode() === 'ZONA-CERRADA-CERT'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Cuadrante técnico (RF-02.1, RF-02.2, QA 10)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Cuadrante: titularidad única y baja lógica ---\n";

    $assignments = $zoneRepo->findTechnicianAssignments($northId);
    $backupAssignmentId = 0;
    foreach ($assignments as $assignment) {
        if ($assignment['technician_id'] === $technicianBackup) {
            $backupAssignmentId = $assignment['assignment_id'];
        }
    }

    $assert(
        'El cuadrante lista titulares antes que suplentes y sin perder la guardia',
        count($assignments) === 3
            && $assignments[0]['technician_id'] === $technicianPrimary
            && $assignments[0]['is_primary'] === true
            && $assignments[1]['is_primary'] === false
            && $backupAssignmentId > 0
            && in_array(true, array_column(array_slice($assignments, 1), 'is_on_call'), true)
    );

    $duplicateAssignment = $zoneRepo->assignTechnician($technicianBackup, $northId, false);
    $assert(
        'Volver a vincular al mismo suplente es idempotente y no duplica la fila',
        $duplicateAssignment === $backupAssignmentId
            && count($zoneRepo->findTechnicianAssignments($northId)) === 3
    );

    $primaryConflict = null;
    try {
        $zoneRepo->assignTechnician($technicianPrimary, $southId, true);
    } catch (PrimaryTechnicianConflictException $exception) {
        $primaryConflict = $exception;
    }
    $assert(
        'Una segunda titularidad viva se rechaza con la zona que se perdería',
        $primaryConflict !== null
            && $primaryConflict->getTechnicianId() === $technicianPrimary
            && $primaryConflict->getCurrentZoneId() === $northId
            && $primaryConflict->getCurrentZoneName() === 'Zona Norte de Certificación'
            && $primaryConflict->getHttpStatusCode() === 409
    );

    $assert(
        'La promoción de un suplente vivo reutiliza su vinculación (sin filas nuevas)',
        $zoneRepo->assignTechnician($technicianBackup, $northId, true) === $duplicateAssignment
            && $zoneRepo->findTechnicianAssignments($northId)[0]['technician_id'] === $technicianBackup
    );

    $inactiveRejected = null;
    try {
        $zoneRepo->assignTechnician($technicianInactive, $northId, false);
    } catch (ZoneValidationException $exception) {
        $inactiveRejected = $exception;
    }
    $assert(
        'Un usuario inactivo no puede entrar en el cuadrante (422)',
        $inactiveRejected !== null
            && $inactiveRejected->getHttpStatusCode() === 422
            && isset($inactiveRejected->getFieldErrors()['technician_id'])
    );

    $closedZoneRejected = null;
    try {
        $zoneRepo->assignTechnician($technicianOnCall, $closedId, false);
    } catch (ZoneValidationException $exception) {
        $closedZoneRejected = $exception;
    }
    $assert(
        'Una zona desactivada no admite vinculaciones',
        $closedZoneRejected !== null && $closedZoneRejected->getFieldErrors()['zone_id'] !== ''
    );

    // Baja lógica y reingreso del titular en la misma zona (QA 10).
    $assert(
        'La baja del titular extingue la vinculación y el reingreso estrena fila',
        $zoneRepo->removeTechnician($technicianBackup, $northId) === true
            && $zoneRepo->removeTechnician($technicianBackup, $northId) === false
            && $zoneRepo->assignTechnician($technicianBackup, $northId, true) !== $duplicateAssignment
    );

    $historicalRows = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM technician_zone_assignments WHERE technician_id = ' . $technicianBackup
    )->fetchColumn();
    $assert(
        'La vinculación extinguida se conserva con removed_at (Art. III.1)',
        $historicalRows === 2
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Herencia y anulación manual de la sede (RF-03.1, RF-03.2, RF-03.3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Herencia por CP y anulación manual ---\n";

    $northOneId = $northLocationOne->getId();

    $assert(
        'findInheritedZoneByPostalCode resuelve la zona activa y devuelve null sin catálogo',
        $locationRepo->findInheritedZoneByPostalCode('08003') === $southId
            && $locationRepo->findInheritedZoneByPostalCode('99999') === null
    );

    // ── Liberación lógica de CP (QA 13): sin DELETE y sin disparador tocado ──
    $releasingZone = $zoneRepo->create([
        'code' => 'ZONA-LIBERACION-CERT',
        'name' => 'Zona de Liberación de Certificación',
    ]);
    $releasingZoneId = (int)$releasingZone->getId();
    $linkPostalCode($pgPdo, '08050', $releasingZoneId);

    $assert(
        'releasePostalCodes marca el CP como liberado y lo retira de la ficha viva',
        $zoneRepo->releasePostalCodes($releasingZoneId) === 1
            && $zoneRepo->findPostalCodes($releasingZoneId) === []
            && $zoneRepo->releasePostalCodes($releasingZoneId) === 0
    );

    $historical = $pgPdo->query(
        "SELECT COUNT(*) FROM operational_zone_postal_codes WHERE postal_code = '08050' AND released_at IS NOT NULL"
    )->fetchColumn();
    $assert(
        'La fila histórica del CP liberado se conserva (Art. III.1)',
        (int)$historical === 1
    );

    $linkPostalCode($pgPdo, '08050', $northId);
    $assert(
        'El CP liberado se reasigna a otra zona y la herencia ya apunta a la nueva',
        $locationRepo->findInheritedZoneByPostalCode('08050') === $northId
    );

    $assert(
        'setPostalCode persiste el CP y recoloca la sede heredada',
        $locationRepo->setPostalCode($northOneId, '08003') === $southId
            && $locationRepo->findById($northOneId)?->getPostalCode() === '08003'
            && $locationRepo->findById($northOneId)?->getZoneId() === $southId
    );

    $assert(
        'setManualOverride fija zona y bandera en la misma escritura',
        $locationRepo->setManualOverride($northOneId, $closedId) === true
            && $locationRepo->findById($northOneId)?->getZoneId() === $closedId
            && $locationRepo->findById($northOneId)?->isManualOverride() === true
    );

    $assert(
        'Con anulación manual, cambiar el CP NO mueve la sede (RF-03.3)',
        $locationRepo->setPostalCode($northOneId, '08001') === $closedId
            && $locationRepo->findById($northOneId)?->getPostalCode() === '08001'
            && $locationRepo->findById($northOneId)?->getZoneId() === $closedId
    );

    $assert(
        'clearManualOverride devuelve la herencia y recalcula desde el CP guardado',
        $locationRepo->clearManualOverride($northOneId) === $northId
            && $locationRepo->findById($northOneId)?->isManualOverride() === false
            && $locationRepo->findById($northOneId)?->getZoneId() === $northId
    );

    $invalidPostalCode = null;
    try {
        $locationRepo->setPostalCode($northOneId, '8001');
    } catch (ZoneValidationException $exception) {
        $invalidPostalCode = $exception;
    }
    $assert(
        'El CP no conforme se rechaza antes de tocar la sede',
        $invalidPostalCode !== null
            && $invalidPostalCode->getFieldErrors()['postal_code'] !== ''
            && $locationRepo->findById($northOneId)?->getPostalCode() === '08001'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. CP obligatorio en el alta y la edición (QA 7, DoD-10)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. AdminLocationService exige el CP ---\n";

    $locationService = new AdminLocationService(
        $locationRepo,
        new AuditLogger(new PdoAuditLogRepository($pgPdo)),
        new PdoSiteAccessCodeRepository($pgPdo)
    );
    $actor = ['id' => $technicianPrimary, 'role' => 'COORDINATOR', 'name' => 'Coordinación de Certificación'];

    $missingPostalCode = null;
    try {
        $locationService->createLocation([
            'site_code' => 'SEDE-Z05-SIN-CP',
            'name' => 'Sede sin código postal',
            'address' => 'Calle de la Certificación 5, Barcelona',
            'latitude' => 41.385312,
            'longitude' => 2.193245,
        ], $actor);
    } catch (ZoneValidationException $exception) {
        $missingPostalCode = $exception;
    }
    $assert(
        'El alta sin CP se rechaza como 400 con el campo señalado',
        $missingPostalCode !== null
            && $missingPostalCode->getHttpStatusCode() === 400
            && isset($missingPostalCode->getFieldErrors()['postal_code'])
            && $locationRepo->findBySiteCode('SEDE-Z05-SIN-CP', false, true) === null
    );

    $createdWithPostalCode = $locationService->createLocation([
        'site_code' => 'SEDE-Z05-CON-CP',
        'name' => 'Sede con código postal',
        'address' => 'Calle de la Certificación 6, Barcelona',
        'postal_code' => '08002',
        'latitude' => 41.385312,
        'longitude' => 2.193245,
    ], $actor);

    $newLocationId = $createdWithPostalCode['location']->getId();
    $assert(
        'El alta con CP persiste el CP validado y hereda su zona',
        $createdWithPostalCode['location']->getPostalCode() === '08002'
            && $createdWithPostalCode['location']->getZoneId() === $northId
    );

    $auditState = $pgPdo->prepare(
        "SELECT new_state FROM audit_log WHERE action = 'LOCATION_CREATED' AND entity_id = :id ORDER BY id DESC LIMIT 1"
    );
    $auditState->execute([':id' => $newLocationId]);
    $storedState = json_decode((string)$auditState->fetchColumn(), true);
    $assert(
        'La auditoría del alta registra el CP y la zona heredada',
        ($storedState['postal_code'] ?? null) === '08002' && ($storedState['zone_id'] ?? null) === $northId
    );

    $updateWithoutPostalCode = null;
    try {
        $locationService->updateLocation($newLocationId, ['name' => 'Sede renombrada'], $actor);
    } catch (ZoneValidationException $exception) {
        $updateWithoutPostalCode = $exception;
    }
    $assert(
        'La edición sin CP se rechaza y no altera la sede',
        $updateWithoutPostalCode !== null
            && isset($updateWithoutPostalCode->getFieldErrors()['postal_code'])
            && $locationRepo->findById($newLocationId)?->getName() === 'Sede con código postal'
    );

    $updated = $locationService->updateLocation($newLocationId, [
        'name' => 'Sede reubicada',
        'postal_code' => '08003',
    ], $actor);
    $assert(
        'La edición con CP nuevo recoloca la zona heredada y lo deja auditado',
        $updated->getName() === 'Sede reubicada'
            && $updated->getPostalCode() === '08003'
            && $updated->getZoneId() === $southId
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Coste de la herencia: mediana por debajo de 5 ms
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Presupuesto de la herencia por CP ---\n";

    $locationRepo->findInheritedZoneByPostalCode('08001'); // calentamiento

    $samples = [];
    for ($iteration = 0; $iteration < 201; $iteration++) {
        $start = hrtime(true);
        $locationRepo->findInheritedZoneByPostalCode('08001');
        $samples[] = (hrtime(true) - $start) / 1_000_000;
    }
    sort($samples);
    $median = $samples[(int)floor(count($samples) / 2)];

    $assert(
        'findInheritedZoneByPostalCode resuelve por debajo de 5 ms de mediana',
        $median < 5.0,
        sprintf('mediana=%.3f ms · p95=%.3f ms · n=%d', $median, $samples[190], count($samples))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Ciclo de vida de la zona (RF-01.1, Art. III.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Ciclo de vida de la zona ---\n";

    $assert(
        'setActive reactiva la zona y el listado filtrado la recupera',
        $zoneRepo->setActive($closedId, true) === true
            && $zoneRepo->findById($closedId)?->isActive() === true
            && count($zoneRepo->findAllWithCounts(false)) === 4
    );

    $assert(
        'update cambia nombre, color y descripción validados',
        $zoneRepo->update($closedId, [
            'name' => 'Zona Reabierta de Certificación',
            'color_hex' => '#F59E0B',
            'description' => 'Reabierta por el coordinador',
        ]) === true
            && $zoneRepo->findById($closedId)?->getName() === 'Zona Reabierta de Certificación'
            && $zoneRepo->findById($closedId)?->getColorHex() === '#f59e0b'
    );

    $invalidColor = null;
    try {
        $zoneRepo->update($closedId, ['color_hex' => 'azul']);
    } catch (ZoneValidationException $exception) {
        $invalidColor = $exception;
    }
    $assert(
        'Un color fuera de #RRGGBB se rechaza y no toca la fila',
        $invalidColor !== null && $zoneRepo->findById($closedId)?->getColorHex() === '#f59e0b'
    );

    $assert(
        'update sobre una zona inexistente devuelve false',
        $zoneRepo->update(999999, ['name' => 'Zona Fantasma']) === false
    );

    // ─────────────────────────────────────────────────────────────────────
    // Resumen
    // ─────────────────────────────────────────────────────────────────────
    echo "\n======================================================================\n";
    echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

    if ($failures === 0) {
        echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ZONE-05 CUMPLIDA CON ÉXITO.\n";
        echo "======================================================================\n";
        exit(0);
    }

    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
    echo "======================================================================\n";
    exit(1);
} finally {
    PostgresFixtureBuilder::dropIsolationSchema($pgPdo, ZONE_REPO_TEST_SCHEMA);
}
