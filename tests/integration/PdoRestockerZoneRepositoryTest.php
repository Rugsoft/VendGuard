<?php

declare(strict_types=1);

/**
 * PdoRestockerZoneRepositoryTest · T-RES-05
 *
 * Certifica el cuadrante de carga del Módulo 31 en el repositorio PDO real: la
 * Tabla 41 `restocker_zone_assignments`, sus contadores por zona (`QA 2`) y su
 * auditoría `RESTOCKER_ZONE` (RF-02.1–RF-02.4, `D3`, `QA 1`, `QA 4`, Art. III.1).
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Alta de titularidad (RF-02.1).** El alta explícita devuelve la vinculación
 *      viva, escribe `role_in_zone` e `is_primary` sincronizados y audita
 *      `RESTOCKER_ZONE_ASSIGNED`.
 *   2. **Titularidad única con confirmación expresa (RF-02.2).** La segunda
 *      titularidad se rechaza con `409 PRIMARY_ZONE_ALREADY_ASSIGNED` y la zona que
 *      se perdería; con la degradación confirmada, la titularidad previa pasa a
 *      `BACKUP` en la misma transacción y se audita `RESTOCKER_ZONE_PRIMARY_DEMOTED`.
 *   3. **Suplencias sin límite e idempotencia.** Un reponedor acumula suplencias
 *      vivas en varias zonas, repetir la misma vinculación devuelve el mismo
 *      identificador sin duplicar fila ni auditoría, y cambiar de rol reutiliza la
 *      vinculación viva.
 *   4. **Contadores por zona (`QA 2`).** `primary_restockers_count` y
 *      `backup_restockers_count` viajan en la misma sentencia del catálogo y solo
 *      cuentan vinculaciones vivas; el cuadrante SAT no los altera.
 *   5. **Validación de extremos (RF-02.2).** Un técnico, un reponedor inactivo, una
 *      zona inactiva o inexistente y un usuario inexistente se rechazan antes de
 *      escribir.
 *   6. **Baja lógica e idempotencia (`QA 4`, Art. III.1).** La baja marca
 *      `removed_at`, repetirla devuelve `false` sin duplicar auditoría, la fila
 *      histórica se conserva y el reingreso estrena vinculación.
 *   7. **Lectura inversa (RF-02.4).** El cuadrante por reponedor incluye al activo
 *      sin zona y al inactivo con vinculación viva, excluye al inactivo sin zona y
 *      ordena titularidad antes que suplencias.
 *
 * CATEGORÍA: `new_engine` (canal propio, motor único PostgreSQL; fuera de la
 * batería hermética RNF-04). Sin `PG_TEST_DSN` sale con código 2.
 *
 * LIMPIEZA: escenario en esquema aislado, borrado con `CASCADE` al terminar.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/PdoRestockerZoneRepositoryTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Core\Domain\Exception\PrimaryRestockerConflictException;
use VendGuard\Core\Domain\Exception\ZoneValidationException;
use VendGuard\Infrastructure\Database\PostgresMigrationRunner;
use VendGuard\Infrastructure\Repository\PdoOperationalZoneRepository;

const VENDGUARD_TEST_CATEGORY = 'new_engine';

const RESTOCKER_REPO_TEST_SCHEMA = 'pdo_res05_cuadrante';

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
echo " VENDGUARD · T-RES-05 · Repositorio PDO del cuadrante de carga (Módulo 31)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pgPdo = PostgresFixtureBuilder::gateOrExit();

$createZone = static function (PDO $pdo, string $code, string $name, string $color = '#2560ff'): int {
    $stmt = $pdo->prepare(
        'INSERT INTO operational_zones (code, name, color_hex) VALUES (:code, :name, :color) RETURNING id'
    );
    $stmt->execute([':code' => $code, ':name' => $name, ':color' => $color]);

    return (int)$stmt->fetchColumn();
};

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
        ':password_hash' => password_hash('carga-' . $operatorCode, PASSWORD_BCRYPT),
        ':role' => $role,
        ':operator_code' => $operatorCode,
        ':is_active' => $isActive ? 'TRUE' : 'FALSE',
    ]);

    return (int)$stmt->fetchColumn();
};

$crash = null;
$residueProbe = null;

try {
    // ─────────────────────────────────────────────────────────────────────
    // Escenario
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pgPdo, RESTOCKER_REPO_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pgPdo, $projectRoot);
    $convergence = (new PostgresMigrationRunner($pgPdo))->convergenceReport();

    $assert(
        'El esquema aplica y converge con la Tabla 41 disponible',
        in_array('027_restocker_role_and_zone_assignments.sql', $schemaReport['migrations'], true)
            && $convergence['converged'] === true
            && PostgresFixtureBuilder::columns($pgPdo, 'restocker_zone_assignments') !== [],
        sprintf('%d sentencias · %d migraciones', $schemaReport['statements'], count($schemaReport['migrations']))
    );

    $repo = new PdoOperationalZoneRepository($pgPdo);

    $zoneA = $createZone($pgPdo, 'ZONA-R05-A', 'Zona de Carga Alfa');
    $zoneB = $createZone($pgPdo, 'ZONA-R05-B', 'Zona de Carga Beta', '#10b981');
    $zoneC = $createZone($pgPdo, 'ZONA-R05-C', 'Zona de Carga Gamma', '#f59e0b');
    $zoneInactive = $createZone($pgPdo, 'ZONA-R05-D', 'Zona de Carga Inactiva');
    $pgPdo->exec('UPDATE operational_zones SET is_active = FALSE WHERE id = ' . $zoneInactive);

    $coordinator = $createUser($pgPdo, 'Coordinación de Certificación', 'coord.res05@vendguard.internal', 'COORDINATOR', 'CO-R05');
    $restockerOne = $createUser($pgPdo, 'Lucía Repostaje Uno', 'rep.res05.uno@vendguard.internal', 'RESTOCKER', 'REP-501');
    $restockerTwo = $createUser($pgPdo, 'Óscar Repostaje Dos', 'rep.res05.dos@vendguard.internal', 'RESTOCKER', 'REP-502');
    $restockerThree = $createUser($pgPdo, 'Marta Repostaje Tres', 'rep.res05.tres@vendguard.internal', 'RESTOCKER', 'REP-503');
    $restockerInactive = $createUser($pgPdo, 'Baja Repostaje Cuatro', 'rep.res05.cuatro@vendguard.internal', 'RESTOCKER', 'REP-504', false);
    $technician = $createUser($pgPdo, 'Técnico de Certificación', 'tec.res05@vendguard.internal', 'TECHNICIAN', 'TEC-505');

    $assert(
        'El escenario siembra zonas, coordinación, reponedores y un técnico',
        $zoneA > 0 && $zoneB > 0 && $zoneC > 0 && $zoneInactive > 0
            && $coordinator > 0 && $restockerOne > 0 && $restockerTwo > 0
            && $restockerThree > 0 && $restockerInactive > 0 && $technician > 0
    );

    // ─────────────────────────────────────────────────────────────────────
    // 1. Contadores vacíos (QA 2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Contadores del catálogo antes del cuadrante (QA 2) ---\n";

    $initialCounts = $repo->findWithCounts($zoneA);

    $assert(
        'Una zona sin cuadrante de carga declara cero titulares y cero suplentes',
        $initialCounts !== null
            && $initialCounts['primary_restockers_count'] === 0
            && $initialCounts['backup_restockers_count'] === 0,
        json_encode($initialCounts['primary_restockers_count'] ?? null)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Alta de titularidad y auditoría
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Alta de titularidad con auditoría (RF-02.1) ---\n";

    $primaryA = $repo->assignRestocker($restockerOne, $zoneA, true, $coordinator);
    $listingA = $repo->findRestockerAssignments($zoneA);

    $assert(
        'El alta devuelve una vinculación viva con rol PRIMARY y su etiqueta',
        $primaryA > 0
            && count($listingA) === 1
            && $listingA[0]['assignment_id'] === $primaryA
            && $listingA[0]['restocker_id'] === $restockerOne
            && $listingA[0]['role_in_zone'] === 'PRIMARY'
            && $listingA[0]['role_label'] === 'Titular'
            && $listingA[0]['is_primary'] === true
            && $listingA[0]['is_active'] === true,
        json_encode($listingA)
    );

    $auditRows = $pgPdo->query(
        "SELECT action, entity_id FROM audit_log WHERE entity_type = 'RESTOCKER_ZONE' ORDER BY id"
    )->fetchAll();

    $assert(
        'El alta se audita como RESTOCKER_ZONE_ASSIGNED sobre la vinculación creada',
        $auditRows !== []
            && $auditRows[0]['action'] === 'RESTOCKER_ZONE_ASSIGNED'
            && (int)$auditRows[0]['entity_id'] === $primaryA,
        json_encode($auditRows)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Titularidad única con confirmación expresa (RF-02.2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Titularidad única y traslado confirmado (RF-02.2) ---\n";

    $conflict = null;
    try {
        $repo->assignRestocker($restockerOne, $zoneB, true, $coordinator);
    } catch (PrimaryRestockerConflictException $exception) {
        $conflict = $exception;
    }

    $assert(
        'La segunda titularidad se rechaza con 409 PRIMARY_ZONE_ALREADY_ASSIGNED y la zona que se perdería',
        $conflict !== null
            && $conflict->getErrorCode() === 'PRIMARY_ZONE_ALREADY_ASSIGNED'
            && $conflict->getHttpStatusCode() === 409
            && $conflict->getDetails()['current_primary_zone']['code'] === 'ZONA-R05-A',
        $conflict === null ? 'la segunda titularidad se admitió' : json_encode($conflict->getDetails())
    );

    $assert(
        'El rechazo no deja fila en la zona rechazada',
        $repo->findRestockerAssignments($zoneB) === []
    );

    $primaryB = $repo->assignRestocker($restockerOne, $zoneB, true, $coordinator, true);
    $demotedA = $repo->findRestockerAssignments($zoneA);
    $promotedB = $repo->findRestockerAssignments($zoneB);

    $assert(
        'Con la degradación confirmada, la titularidad previa pasa a suplencia viva',
        $primaryB > 0
            && count($demotedA) === 1
            && $demotedA[0]['role_in_zone'] === 'BACKUP'
            && $demotedA[0]['is_primary'] === false
            && $demotedA[0]['role_label'] === 'Suplente'
            && $promotedB[0]['role_in_zone'] === 'PRIMARY',
        json_encode(['a' => $demotedA, 'b' => $promotedB])
    );

    $auditActions = array_column(
        $pgPdo->query("SELECT action FROM audit_log WHERE entity_type = 'RESTOCKER_ZONE' ORDER BY id")->fetchAll(),
        'action'
    );

    $assert(
        'El traslado audita la degradación y el nuevo alta, en ese orden',
        array_values(array_filter($auditActions, static fn (string $action): bool => $action === 'RESTOCKER_ZONE_PRIMARY_DEMOTED')) !== []
            && array_slice($auditActions, -2) === ['RESTOCKER_ZONE_PRIMARY_DEMOTED', 'RESTOCKER_ZONE_ASSIGNED'],
        json_encode($auditActions)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Contadores por zona (QA 2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Contadores de carga por zona (QA 2) ---\n";

    $countsA = $repo->findWithCounts($zoneA);
    $countsB = $repo->findWithCounts($zoneB);

    $assert(
        'Los contadores separan titular y suplente y no tocan el cuadrante SAT',
        $countsA['primary_restockers_count'] === 0
            && $countsA['backup_restockers_count'] === 1
            && $countsB['primary_restockers_count'] === 1
            && $countsB['backup_restockers_count'] === 0
            && $countsA['primary_technicians_count'] === 0
            && $countsA['backup_technicians_count'] === 0,
        json_encode([
            'a' => [$countsA['primary_restockers_count'], $countsA['backup_restockers_count']],
            'b' => [$countsB['primary_restockers_count'], $countsB['backup_restockers_count']],
        ])
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Suplencias múltiples, idempotencia y cambio de rol
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Suplencias sin límite e idempotencia ---\n";

    $backupR2A = $repo->assignRestocker($restockerTwo, $zoneA, false, $coordinator);
    $backupR2AAgain = $repo->assignRestocker($restockerTwo, $zoneA, false, $coordinator);
    $restockerOneInC = $repo->assignRestocker($restockerOne, $zoneC, false, $coordinator);
    $restockerThreeInC = $repo->assignRestocker($restockerThree, $zoneC, true, $coordinator);

    $assert(
        'Repetir la misma vinculación devuelve el mismo identificador sin duplicar fila',
        $backupR2A === $backupR2AAgain
            && count(array_filter(
                $repo->findRestockerAssignments($zoneA),
                static fn (array $row): bool => $row['restocker_id'] === $restockerTwo
            )) === 1
    );

    $assert(
        'El mismo reponedor acumula suplencias vivas en zonas distintas',
        $restockerOneInC > 0
            && $repo->findRestockerQuadrantsByTechnician()[0]['restocker_id'] !== null
    );

    $roleChanged = $repo->assignRestocker($restockerTwo, $zoneA, true, $coordinator);
    $changedRow = array_values(array_filter(
        $repo->findRestockerAssignments($zoneA),
        static fn (array $row): bool => $row['restocker_id'] === $restockerTwo
    ))[0];

    $assert(
        'El cambio de rol reutiliza la vinculación viva y actualiza las dos formas del vínculo',
        $roleChanged === $backupR2A
            && $changedRow['role_in_zone'] === 'PRIMARY'
            && $changedRow['is_primary'] === true,
        json_encode($changedRow)
    );

    $listingC = $repo->findRestockerAssignments($zoneC);

    $assert(
        'El listado de zona pone la titularidad antes que las suplencias',
        count($listingC) === 2
            && $listingC[0]['restocker_id'] === $restockerThree
            && $listingC[0]['is_primary'] === true
            && $listingC[1]['restocker_id'] === $restockerOne
            && $listingC[1]['is_primary'] === false,
        json_encode(array_column($listingC, 'restocker_id'))
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Validación de extremos (RF-02.2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Validación de extremos del cuadrante ---\n";

    $rejects = static function (callable $operation): ?string {
        try {
            $operation();
        } catch (ZoneValidationException $exception) {
            return $exception->getMessage();
        }

        return null;
    };

    $technicianRejection = $rejects(static fn () => $repo->assignRestocker($technician, $zoneC, false, $coordinator));
    $inactiveRestockerRejection = $rejects(static fn () => $repo->assignRestocker($restockerInactive, $zoneC, false, $coordinator));
    $inactiveZoneRejection = $rejects(static fn () => $repo->assignRestocker($restockerInactive, $zoneInactive, false, $coordinator));
    $unknownZoneRejection = $rejects(static fn () => $repo->assignRestocker($restockerThree, 999999, false, $coordinator));
    $unknownRestockerRejection = $rejects(static fn () => $repo->assignRestocker(999999, $zoneC, false, $coordinator));

    $assert(
        'Un técnico no puede entrar en el cuadrante de carga',
        $technicianRejection !== null && str_contains($technicianRejection, 'Reponedor')
    );
    $assert(
        'Un reponedor inactivo no puede entrar en el cuadrante de carga',
        $inactiveRestockerRejection !== null && str_contains($inactiveRestockerRejection, 'rol Reponedor')
    );
    $assert(
        'Una zona inactiva rechaza la vinculación',
        $inactiveZoneRejection !== null && str_contains($inactiveZoneRejection, 'desactivada')
    );
    $assert(
        'Una zona inexistente y un usuario inexistente se rechazan antes de escribir',
        $unknownZoneRejection !== null && $unknownRestockerRejection !== null
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Baja lógica idempotente y reingreso (QA 4, Art. III.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Baja lógica idempotente y reingreso ---\n";

    $removed = $repo->removeRestocker($restockerTwo, $zoneA, $coordinator);
    $removedAgain = $repo->removeRestocker($restockerTwo, $zoneA, $coordinator);

    $historicalRows = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM restocker_zone_assignments WHERE restocker_id = ' . $restockerTwo . ' AND zone_id = ' . $zoneA
    )->fetchColumn();

    $assert(
        'La baja lógica extingue la vinculación, se conserva la fila y repetirla no hace nada',
        $removed === true
            && $removedAgain === false
            && $historicalRows === 1
            && array_filter(
                $repo->findRestockerAssignments($zoneA),
                static fn (array $row): bool => $row['restocker_id'] === $restockerTwo
            ) === [],
        "filas={$historicalRows}"
    );

    $removedAudits = (int)$pgPdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE entity_type = 'RESTOCKER_ZONE' AND action = 'RESTOCKER_ZONE_REMOVED'"
    )->fetchColumn();

    $assert(
        'La baja idempotente audita un solo RESTOCKER_ZONE_REMOVED',
        $removedAudits === 1,
        'eventos=' . $removedAudits
    );

    $reentry = $repo->assignRestocker($restockerTwo, $zoneA, false, $coordinator);
    $rowsAfterReentry = (int)$pgPdo->query(
        'SELECT COUNT(*) FROM restocker_zone_assignments WHERE restocker_id = ' . $restockerTwo . ' AND zone_id = ' . $zoneA
    )->fetchColumn();

    $assert(
        'El reingreso estrena vinculación y conserva la histórica',
        $reentry > 0 && $reentry !== $backupR2A && $rowsAfterReentry === 2,
        "filas={$rowsAfterReentry} · nueva={$reentry} · anterior={$backupR2A}"
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Lectura inversa del cuadrante (RF-02.4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Cuadrante inverso por reponedor (RF-02.4) ---\n";

    $quadrants = $repo->findRestockerQuadrantsByTechnician();
    $quadrantById = [];

    foreach ($quadrants as $quadrant) {
        $quadrantById[$quadrant['restocker_id']] = $quadrant;
    }

    $assert(
        'El cuadrante incluye al reponedor activo sin zona y al inactivo con vinculación viva',
        isset($quadrantById[$restockerThree])
            && $quadrantById[$restockerThree]['zones'] !== []
            && isset($quadrantById[$restockerInactive]) === false
            && !in_array($technician, array_keys($quadrantById), true)
    );

    $assert(
        'La titularidad encabeza las zonas del reponedor y el histórico no aparece',
        isset($quadrantById[$restockerOne])
            && $quadrantById[$restockerOne]['zones'][0]['is_primary'] === true
            && count(array_filter(
                $quadrantById[$restockerOne]['zones'],
                static fn (array $zone): bool => $zone['zone_id'] === $zoneA
            )) === 1
            && $quadrantById[$restockerOne]['zones'][0]['zone_id'] === $zoneB,
        json_encode($quadrantById[$restockerOne] ?? null)
    );

    $assert(
        'Un reponedor activo sin zona se publica con la lista vacía, no se oculta',
        isset($quadrantById[$restockerInactive]) === false
            && (static function (array $quadrants) use ($restockerInactive): bool {
                foreach ($quadrants as $quadrant) {
                    if ($quadrant['restocker_id'] === $restockerInactive) {
                        return true;
                    }
                }

                return false;
            })($quadrants) === false
    );

    $assert(
        'El contador de titulares de la zona C refleja la lectura inversa',
        $repo->findWithCounts($zoneC)['primary_restockers_count'] === 1
            && $repo->findWithCounts($zoneC)['backup_restockers_count'] === 1
    );
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pgPdo, RESTOCKER_REPO_TEST_SCHEMA);
    } catch (Throwable $cleanupFailure) {
        $crash ??= $cleanupFailure;
    }

    $residueProbe = $pgPdo->query("SELECT to_regclass('" . RESTOCKER_REPO_TEST_SCHEMA . ".restocker_zone_assignments')")->fetchColumn();
}

echo "\n--- Limpieza y residuo del escenario ---\n";

$assert(
    'El esquema aislado y su Tabla 41 se retiran al terminar',
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
    echo " RESULTADO: 100% EN VERDE. REPOSITORIO DEL CUADRANTE DE CARGA CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL REPOSITORIO DEL CUADRANTE DE CARGA.\n";
echo "======================================================================\n";
exit(1);
