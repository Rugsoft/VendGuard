<?php

declare(strict_types=1);

/**
 * PdoWorkHoursRoleFilterTest · T-RES-06
 *
 * Certifica el filtrado por rol (`ALL` | `TECHNICIAN` | `RESTOCKER`) en los
 * repositorios de Personal, Ausencias, Turnos y Horas (Módulo 31, `D2`, `QA 4`,
 * RF-05.1, RF-05.2). La consola de Coordinación filtra en el motor, no en PHP:
 * filtrar después de paginar entregaría cifras falsas.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Personal (`PdoUserRepository::findAll`).** `ALL` no restringe,
 *      `TECHNICIAN` y `RESTOCKER` acotan exactamente por rol, el valor llega en
 *      cualquier caja y un rol inventado se rechaza en voz alta.
 *   2. **Ausencias (`PdoTechnicianAbsenceRepository::findAll`).** El filtro de
 *      rol convive con el de operario y estado, y `ALL`/null devuelven el mismo
 *      conjunto.
 *   3. **Turnos (`findShiftsByDateRange`, `findOrphanOpenShiftsBefore`).** Los
 *      turnos imputados al periodo y los huérfanos se acotan por rol sin perder
 *      el orden cronológico.
 *   4. **Horas (`getActiveShiftsMap`, `getClosedInterventionMetricsByTechnician`).**
 *      El mapa de turnos abiertos respeta el rol y el cruce de intervención
 *      acepta el filtro sin romper su consulta agregada.
 *   5. **Contrato del filtro.** Un rol fuera del catálogo lanza
 *      `InvalidArgumentException` en los cuatro puntos de lectura.
 *
 * CATEGORÍA: `new_engine` (motor único PostgreSQL, canal propio; fuera de la
 * batería hermética RNF-04). Sin `PG_TEST_DSN` sale con código 2.
 *
 * LIMPIEZA: escenario en esquema aislado, borrado con `CASCADE` al terminar.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/PdoWorkHoursRoleFilterTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Core\Domain\ValueObject\AbsenceReason;
use VendGuard\Infrastructure\Database\PostgresMigrationRunner;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

const VENDGUARD_TEST_CATEGORY = 'new_engine';

const WORK_HOURS_ROLE_TEST_SCHEMA = 'pdo_res06_role_filter';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-RES-06 · Filtrado por rol en Personal, Ausencias, Turnos y Horas\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$createUser = static function (
    PDO $pdo,
    string $name,
    string $email,
    string $role,
    string $operatorCode,
    bool $isActive = true
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active)
         VALUES (:name, :email, :email_key, :password_hash, :role, :operator_code, :is_active)
         RETURNING id'
    );
    $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':email_key' => $email,
        ':password_hash' => password_hash('rol-' . $operatorCode, PASSWORD_BCRYPT),
        ':role' => $role,
        ':operator_code' => $operatorCode,
        ':is_active' => $isActive ? 'TRUE' : 'FALSE',
    ]);

    return (int)$stmt->fetchColumn();
};

$rejects = static function (callable $operation): bool {
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return true;
    } catch (Throwable) {
        return false;
    }

    return false;
};

$crash = null;
$residueProbe = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, WORK_HOURS_ROLE_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El esquema aplica y converge con la Tabla 41 disponible',
        in_array('027_restocker_role_and_zone_assignments.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true,
        sprintf('%d sentencias · %d migraciones', $schemaReport['statements'], count($schemaReport['migrations']))
    );

    $userRepo = new PdoUserRepository($pgPdo);
    $absenceRepo = new PdoTechnicianAbsenceRepository($pgPdo);
    $shiftRepo = new PdoTechnicianShiftRepository($pgPdo);

    $coordinator = $createUser($pgPdo, 'Coordinación de Jornada', 'coord.res06@vendguard.internal', 'COORDINATOR', 'CO-R06');
    $techOne = $createUser($pgPdo, 'Técnico Jornada Uno', 'tec.res06.uno@vendguard.internal', 'TECHNICIAN', 'TEC-601');
    $techTwo = $createUser($pgPdo, 'Técnico Jornada Dos', 'tec.res06.dos@vendguard.internal', 'TECHNICIAN', 'TEC-602');
    $restockerOne = $createUser($pgPdo, 'Reponedor Jornada Uno', 'rep.res06.uno@vendguard.internal', 'RESTOCKER', 'REP-601');
    $restockerTwo = $createUser($pgPdo, 'Reponedor Jornada Dos', 'rep.res06.dos@vendguard.internal', 'RESTOCKER', 'REP-602');

    $assert(
        'El escenario siembra coordinación, dos técnicos y dos reponedores',
        $coordinator > 0 && $techOne > 0 && $techTwo > 0 && $restockerOne > 0 && $restockerTwo > 0
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Personal (PdoUserRepository::findAll)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Filtro de rol en el listado de personal ---\n";

    $allStaff = $userRepo->findAll(['role' => 'ALL']);
    $technicianStaff = $userRepo->findAll(['role' => 'TECHNICIAN']);
    $restockerStaff = $userRepo->findAll(['role' => 'RESTOCKER']);
    $lowercaseRestockers = $userRepo->findAll(['role' => '  restocker  ']);

    $assert(
        'ALL no restringe: entran los tres roles del catálogo',
        count($allStaff) === 5
            && count(array_filter($allStaff, static fn (array $row): bool => $row['role'] === 'COORDINATOR')) === 1,
        'filas=' . count($allStaff)
    );

    $sortedIds = static function (array $rows): array {
        $ids = array_map(static fn (array $row): int => (int)$row['id'], $rows);
        sort($ids);

        return $ids;
    };

    $assert(
        'TECHNICIAN devuelve solo técnicos y RESTOCKER solo reponedores',
        array_column($technicianStaff, 'role') === ['TECHNICIAN', 'TECHNICIAN']
            && array_column($restockerStaff, 'role') === ['RESTOCKER', 'RESTOCKER']
            && $sortedIds($technicianStaff) === [$techOne, $techTwo]
            && $sortedIds($restockerStaff) === [$restockerOne, $restockerTwo],
        json_encode([array_column($technicianStaff, 'id'), array_column($restockerStaff, 'id')])
    );

    $assert(
        'El rol llega en cualquier caja y con espacios, y la etiqueta del rol nuevo viaja resuelta',
        $sortedIds($lowercaseRestockers) === [$restockerOne, $restockerTwo]
            && $restockerStaff[0]['role_label'] === 'Cargador / Reponedor de Ruta',
        (string)$restockerStaff[0]['role_label']
    );

    $assert(
        'Un rol inventado se rechaza en lugar de devolver una lista vacía',
        $rejects(static fn () => $userRepo->findAll(['role' => 'ADMIN']))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Ausencias (PdoTechnicianAbsenceRepository::findAll)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Filtro de rol en el listado de ausencias ---\n";

    $now = new DateTimeImmutable('now');
    $absenceWindow = [
        'startsAt' => $now->modify('-1 day'),
        'endsAt' => $now->modify('+10 days'),
    ];

    $technicianAbsence = $absenceRepo->create(
        $techOne,
        AbsenceReason::VACATION,
        $absenceWindow['startsAt'],
        $absenceWindow['endsAt'],
        'Vacaciones del técnico',
        $coordinator
    );

    $restockerAbsence = $absenceRepo->create(
        $restockerOne,
        AbsenceReason::PERSONAL_LEAVE,
        $absenceWindow['startsAt'],
        $absenceWindow['endsAt'],
        'Asunto personal del reponedor',
        $coordinator
    );

    $allAbsences = $absenceRepo->findAll(null, 'all', 'ALL');
    $technicianAbsences = $absenceRepo->findAll(null, 'all', 'TECHNICIAN');
    $restockerAbsences = $absenceRepo->findAll(null, 'all', 'RESTOCKER');
    $restockerAbsenceByUser = $absenceRepo->findAll($restockerOne, 'all', 'RESTOCKER');
    $mismatchedFilters = $absenceRepo->findAll($restockerOne, 'all', 'TECHNICIAN');

    $assert(
        'ALL devuelve las dos ausencias y null se comporta igual que ALL',
        count($allAbsences) === 2
            && count($absenceRepo->findAll(null, 'all', null)) === 2,
        'filas=' . count($allAbsences)
    );

    $assert(
        'Cada rol recupera solo su ausencia',
        count($technicianAbsences) === 1
            && $technicianAbsences[0]->id() === $technicianAbsence->id()
            && $technicianAbsences[0]->technicianId() === $techOne
            && count($restockerAbsences) === 1
            && $restockerAbsences[0]->id() === $restockerAbsence->id()
            && $restockerAbsences[0]->technicianId() === $restockerOne,
        json_encode([$technicianAbsences[0]->technicianId(), $restockerAbsences[0]->technicianId()])
    );

    $assert(
        'El filtro de rol convive con el de operario y no mezcla criterios contradictorios',
        count($restockerAbsenceByUser) === 1 && $mismatchedFilters === []
    );

    $assert(
        'Un rol inventado se rechaza también en ausencias',
        $rejects(static fn () => $absenceRepo->findAll(null, 'all', 'ADMIN'))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Turnos (findShiftsByDateRange y findOrphanOpenShiftsBefore)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Filtro de rol en turnos y huérfanos ---\n";

    $madrid = new DateTimeZone('Europe/Madrid');
    $today = (new DateTimeImmutable('now', $madrid))->format('Y-m-d');
    $yesterdayStart = new DateTimeImmutable('yesterday 09:00', $madrid);

    $techTodayShift = $shiftRepo->startShift($techOne);
    $restockerTodayShift = $shiftRepo->startShift($restockerOne);
    $techOrphanShift = $shiftRepo->startShift($techTwo, $yesterdayStart);
    $restockerOrphanShift = $shiftRepo->startShift($restockerTwo, $yesterdayStart);

    $todayShifts = $shiftRepo->findShiftsByDateRange($today, $today);
    $todayTechnicianShifts = $shiftRepo->findShiftsByDateRange($today, $today, null, 'TECHNICIAN');
    $todayRestockerShifts = $shiftRepo->findShiftsByDateRange($today, $today, null, 'RESTOCKER');

    $assert(
        'Los turnos del día se acotan por rol sin duplicar ni perder filas',
        count($todayShifts) === 2
            && count($todayTechnicianShifts) === 1
            && $todayTechnicianShifts[0]->technicianId() === $techOne
            && $todayTechnicianShifts[0]->id() === $techTodayShift->id()
            && count($todayRestockerShifts) === 1
            && $todayRestockerShifts[0]->technicianId() === $restockerOne
            && $todayRestockerShifts[0]->id() === $restockerTodayShift->id(),
        json_encode([
            array_map(static fn ($shift) => $shift->technicianId(), $todayTechnicianShifts),
            array_map(static fn ($shift) => $shift->technicianId(), $todayRestockerShifts),
        ])
    );

    $orphans = $shiftRepo->findOrphanOpenShiftsBefore($today);
    $technicianOrphans = $shiftRepo->findOrphanOpenShiftsBefore($today, null, 'TECHNICIAN');
    $restockerOrphans = $shiftRepo->findOrphanOpenShiftsBefore($today, null, 'RESTOCKER');

    $assert(
        'Los turnos huérfanos se acotan por rol y conservan su orden',
        count($orphans) === 2
            && count($technicianOrphans) === 1
            && $technicianOrphans[0]->id() === $techOrphanShift->id()
            && count($restockerOrphans) === 1
            && $restockerOrphans[0]->id() === $restockerOrphanShift->id(),
        json_encode([$technicianOrphans[0]->technicianId(), $restockerOrphans[0]->technicianId()])
    );

    $assert(
        'El filtro por operario y por rol conviven en el rango de turnos',
        count($shiftRepo->findShiftsByDateRange($today, $today, $techOne, 'TECHNICIAN')) === 1
            && $shiftRepo->findShiftsByDateRange($today, $today, $techOne, 'RESTOCKER') === []
    );

    $assert(
        'Un rol inventado se rechaza en el rango y en los huérfanos',
        $rejects(static fn () => $shiftRepo->findShiftsByDateRange($today, $today, null, 'ADMIN'))
            && $rejects(static fn () => $shiftRepo->findOrphanOpenShiftsBefore($today, null, 'ADMIN'))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Horas (getActiveShiftsMap y métricas de intervención)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Filtro de rol en el mapa de turnos abiertos y en las métricas ---\n";

    $allActive = $shiftRepo->getActiveShiftsMap();
    $technicianActive = $shiftRepo->getActiveShiftsMap('TECHNICIAN');
    $restockerActive = $shiftRepo->getActiveShiftsMap('RESTOCKER');

    $assert(
        'El mapa de turnos abiertos separa técnicos y reponedores',
        count($allActive) === 4
            && array_keys($technicianActive) === [$techOne, $techTwo]
            && array_keys($restockerActive) === [$restockerOne, $restockerTwo]
            && $restockerActive[$restockerOne] === 'ON_SHIFT',
        json_encode([array_keys($technicianActive), array_keys($restockerActive)])
    );

    $from = $now->modify('-1 day');
    $to = $now->modify('+1 day');

    $technicianMetrics = $shiftRepo->getClosedInterventionMetricsByTechnician($from, $to, null, 'TECHNICIAN');
    $restockerMetrics = $shiftRepo->getClosedInterventionMetricsByTechnician($from, $to, null, 'RESTOCKER');

    $assert(
        'El cruce de intervención acepta el filtro de rol sin romper su agregación',
        is_array($technicianMetrics)
            && is_array($restockerMetrics)
            && $restockerMetrics === []
            && $shiftRepo->getClosedInterventionMetricsByTechnician($from, $to) === [],
        json_encode([$technicianMetrics, $restockerMetrics])
    );

    $assert(
        'Un rol inventado se rechaza en el mapa y en las métricas',
        $rejects(static fn () => $shiftRepo->getActiveShiftsMap('ADMIN'))
            && $rejects(static fn () => $shiftRepo->getClosedInterventionMetricsByTechnician($from, $to, null, 'ADMIN'))
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, WORK_HOURS_ROLE_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueProbe = $pgPdo->query("SELECT to_regclass('" . WORK_HOURS_ROLE_TEST_SCHEMA . ".technician_shifts')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El esquema aislado se retira al terminar',
    $residueProbe === null || $residueProbe === false,
    'residuo=' . json_encode($residueProbe)
);

if ($crash !== null) {
    $failures++;
    $assertions++;
    echo "\n  [FAIL] La certificación se interrumpió: " . $crash->getMessage() . "\n";
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. FILTRADO POR ROL CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL FILTRADO POR ROL.\n";
echo "======================================================================\n";
exit(1);
