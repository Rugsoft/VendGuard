<?php

declare(strict_types=1);

/**
 * IncidentCommentsPagedRepositoryTest
 *
 * Prueba de integración del repositorio para el módulo del hilo de comentarios
 * (Módulo 10, T-COM-02 · RF-01.1, RF-01.2, RF-01.3, RNF-02, Art. V.4).
 *
 * Valida la condición "Hecho cuando" contra MariaDB real:
 * - `getCommentsPaged()` devuelve bloques cronológicos de los mensajes más
 *   recientes con paginación retrospectiva por cursor (`beforeId`), sin
 *   duplicados ni huecos entre bloques.
 * - La segregación de notas internas ocurre en la capa de datos
 *   (`includeInternal = false` ⇒ cero filas `is_internal = 1`, Art. V.4).
 * - `countComments()` produce los recuentos segregados: solo públicos para la
 *   Sede y total público + interno para Técnicos y Coordinadores.
 * - La tabla usa el índice `idx_comments_incident` (clave indexada exigida
 *   por la tarea para evitar escaneos completos, RNF-02).
 *
 * Dogma Vanilla: PHP 8.2 + PDO nativo contra la base local real.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - IncidentCommentsPagedRepositoryTest (T-COM-02)\n";
echo "======================================================================\n\n";

// ─── Bootstrap ───────────────────────────────────────────────────────────────
$pdo = ConnectionFactory::getConnection();

TestDataCleaner::purge($pdo);

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$locationRepo = new PdoLocationRepository($pdo);
$machineRepo  = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
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

// ─── Expediente de prueba con su máquina ────────────────────────────────────
$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$machine  = $machineRepo->findActiveByLocationId($location->getId())[1];

// Liberar cualquier aviso activo previo de la máquina (regla anti-duplicados).
$pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")
    ->execute([$machine->getId()]);

$incident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: 'COM-' . uniqid(),
    machineId: (int)$machine->getId(),
    locationId: (int)$location->getId(),
    category: IncidentCategory::PRODUCT_JAM,
    description: 'Expediente para validar la paginación cursorizada del hilo de comentarios.',
    urgency: UrgencyLevel::MEDIUM,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    createdAt: null
));
$incidentId = (int)$incident->getId();

// ─── Semilla de 7 comentarios: 4 públicos y 3 notas internas ────────────────
// created_at crecientes y únicos para fijar el orden cronológico inequívoco.
$commentsSeed = [
    [1, 'REPORTER',   0, '2026-10-01 09:00:00'],
    [2, 'TECHNICIAN', 0, '2026-10-01 10:00:00'],
    [3, 'TECHNICIAN', 1, '2026-10-01 11:00:00'],
    [4, 'REPORTER',   0, '2026-10-02 09:00:00'],
    [5, 'COORDINATOR', 1, '2026-10-02 10:00:00'],
    [6, 'TECHNICIAN', 0, '2026-10-03 09:00:00'],
    [7, 'TECHNICIAN', 1, '2026-10-03 10:00:00'],
];
$insert = $pdo->prepare("
    INSERT INTO `incident_comments`
        (`incident_id`, `author_type`, `user_id`, `author_name`, `comment_text`, `is_internal`, `created_at`)
    VALUES
        (:incident_id, :author_type, NULL, :author_name, :comment_text, :is_internal, :created_at)
");
$seedIds = [];
foreach ($commentsSeed as [$seq, $authorType, $isInternal, $createdAt]) {
    $insert->execute([
        ':incident_id'  => $incidentId,
        ':author_type'  => $authorType,
        ':author_name'  => "Autor de prueba {$seq}",
        ':comment_text' => "Mensaje de prueba número {$seq} del hilo de comentarios.",
        ':is_internal'  => $isInternal,
        ':created_at'   => $createdAt,
    ]);
    $seedIds[$seq] = (int)$pdo->lastInsertId();
}
$allSeedIds = array_values($seedIds);
$publicSeedIds = array_values(array_filter(
    $seedIds,
    fn(int $seq) => $commentsSeed[$seq - 1][2] === 0,
    ARRAY_FILTER_USE_KEY
));

// =========================================================================
// CASO 1: Recuperación completa cronológica (RF-01.2, estado base del hilo)
// =========================================================================
echo "\n--- Caso 1: Bloque completo cronológico (includeInternal = true) ---\n";

$all = $incidentRepo->getCommentsPaged($incidentId, true);
$allIds = array_map(fn($c) => $c->getId(), $all);

$assert("1.1 Devuelve los 7 comentarios del expediente", count($all) === 7);
$assert("1.2 Orden cronológico ascendente (secuencia de inserción 1..7)", $allIds === $allSeedIds);
$assert("1.3 Entidades IncidentComment hidratadas desde la BD",
    $all[0] instanceof \VendGuard\Core\Domain\Model\IncidentComment && $all[6]->getCommentText() === 'Mensaje de prueba número 7 del hilo de comentarios.');

// =========================================================================
// CASO 2: Segregación estricta en capa de datos (Art. V.4 / RNF-01)
// =========================================================================
echo "\n--- Caso 2: Segregación estricta de notas internas (Art. V.4) ---\n";

$publics = $incidentRepo->getCommentsPaged($incidentId, false);
$publicIds = array_map(fn($c) => $c->getId(), $publics);
$internalSeedIds = array_values(array_diff($allSeedIds, $publicSeedIds));

$assert("2.1 Devuelve únicamente los 4 comentarios públicos", count($publics) === 4 && $publicIds === $publicSeedIds);
$assert("2.2 Ninguna nota interna (is_internal = 1) aparece en la proyección pública",
    array_reduce($internalSeedIds, fn(bool $carry, int $id) => $carry && !in_array($id, $publicIds, true), true));
$allPublicInternalFlags = true;
foreach ($publics as $publicComment) {
    if ($publicComment->isInternal() !== false) { $allPublicInternalFlags = false; break; }
}
$assert("2.3 Cada entidad pública reporta isInternal() = false", $allPublicInternalFlags);

// =========================================================================
// CASO 3: Paginación cursorizada hacia atrás (RF-01.2, RF-01.3)
// =========================================================================
echo "\n--- Caso 3: Paginación retrospectiva por cursor (limit = 3) ---\n";

$page1 = $incidentRepo->getCommentsPaged($incidentId, true, 3);
$page1Ids = array_map(fn($c) => $c->getId(), $page1);
$assert("3.1 Primer bloque = los 3 mensajes MÁS RECIENTES (5, 6, 7)", $page1Ids === array_slice($allSeedIds, 4, 3));

$cursor = (int)$page1Ids[0];
$page2 = $incidentRepo->getCommentsPaged($incidentId, true, 3, $cursor);
$page2Ids = array_map(fn($c) => $c->getId(), $page2);
$assert("3.2 Cursor beforeId=seq5 devuelve el bloque anterior (2, 3, 4) sin duplicados", $page2Ids === array_slice($allSeedIds, 1, 3));

$page3 = $incidentRepo->getCommentsPaged($incidentId, true, 3, (int)$page2Ids[0]);
$page3Ids = array_map(fn($c) => $c->getId(), $page3);
$assert("3.3 Cursor beforeId=seq2 devuelve el resto (1) y se agota el hilo", $page3Ids === array_slice($allSeedIds, 0, 1));

// Recorrido completo del hilo por bloques: unión = hilo íntegro, sin repetir.
$walkedIds = [...$page3Ids, ...$page2Ids, ...$page1Ids];
$assert("3.4 Recorrido por bloques reconstruye el hilo íntegro (seq 1..7) sin duplicados ni huecos",
    $walkedIds === $allSeedIds);

// La paginación respeta también la segregación de internas.
$publicPage = $incidentRepo->getCommentsPaged($incidentId, false, 3);
$publicPageIds = array_map(fn($c) => $c->getId(), $publicPage);
$assert("3.5 Bloque público paginado = solo públicos más recientes (seq 2, 4, 6)",
    $publicPageIds === array_slice($publicSeedIds, -3, 3));

// =========================================================================
// CASO 4: Recuentos segregados (RF-01.1)
// =========================================================================
echo "\n--- Caso 4: Recuentos segregados (countComments) ---\n";

$assert("4.1 Total para Técnico/Coordinador (públicos + internos) = 7",
    $incidentRepo->countComments($incidentId, true) === 7);
$assert("4.2 Recuento para Sede (solo públicos, is_internal = 0) = 4",
    $incidentRepo->countComments($incidentId, false) === 4);

// =========================================================================
// CASO 5: Clave indexada exigida por la tarea (RNF-02)
// =========================================================================
echo "\n--- Caso 5: Índice idx_comments_incident presente ---\n";

$indexRow = $pdo->query("
    SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_csv
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'incident_comments'
      AND INDEX_NAME = 'idx_comments_incident'
    GROUP BY INDEX_NAME
")->fetch(PDO::FETCH_ASSOC);

$assert("5.1 El índice idx_comments_incident existe en incident_comments", $indexRow !== false);
$assert("5.2 El índice está liderado por incident_id (filtro del hilo)",
    $indexRow !== false && str_starts_with((string)$indexRow['columns_csv'], 'incident_id'));

// =========================================================================
// CASO 6: Expediente sin comentarios (caso límite del hilo vacío)
// =========================================================================
echo "\n--- Caso 6: Expediente sin comentarios ---\n";

$pdo->prepare("UPDATE incidents SET status = 'CANCELLED', deleted_at = NOW() WHERE machine_id = ? AND deleted_at IS NULL AND status NOT IN ('CLOSED', 'CANCELLED')")
    ->execute([$machine->getId()]);

$emptyIncident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: 'COM2-' . uniqid(),
    machineId: (int)$machine->getId(),
    locationId: (int)$location->getId(),
    category: IncidentCategory::OTHER,
    description: 'Expediente vacío para el caso límite del hilo sin comentarios.',
    urgency: UrgencyLevel::LOW,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    createdAt: null
));

$assert("6.1 Hilo vacío: getCommentsPaged devuelve lista vacía",
    $incidentRepo->getCommentsPaged((int)$emptyIncident->getId(), true) === []);
$assert("6.2 Hilo vacío: recuentos a cero en ambas segregaciones",
    $incidentRepo->countComments((int)$emptyIncident->getId(), true) === 0
        && $incidentRepo->countComments((int)$emptyIncident->getId(), false) === 0);

// ─── Limpieza final (convención de la batería) ─────────────────────────────
// La suite deja la base resembrada para que la siguiente prueba del runner
// encuentre el estado canónico de semillas (evita colisiones del arnés de
// otras suites que no purgan al arrancar y de la regla anti-duplicados).
TestDataCleaner::purge($pdo);
$seedRunner->seedAll();
echo "\nBase de datos restablecida a las semillas (limpieza de la suite).\n";

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n======================================================================\n";
echo " Total Assertions: {$assertions} | Passed: " . ($assertions - $failures) . " | Failed: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% IN GREEN. PAGED COMMENTS REPOSITORY (T-COM-02) FULFILLED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILURES DETECTED IN TEST SUITE.\n";
echo "======================================================================\n";
exit(1);
