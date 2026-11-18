<?php

declare(strict_types=1);

/**
 * PdoMachineCashCollectionRepositoryTest · T-CCV-05
 *
 * Certifica en el motor real la persistencia del Módulo 35
 * (`35-cash-collection-vault-audit`): el alta de la Tabla 49, la idempotencia
 * de la cola offline, la lectura privada del último contador, el ciclo de
 * custodia, los 6 KPIs de Coordinación y el resumen opaco del reponedor.
 *
 *   1. **Alta en ruta (RF-01.1–RF-01.3, C1).** El precinto se normaliza y se
 *      conserva tal cual; el código, la mutación y el precinto son únicos; la
 *      fila devuelta trae `id`, sellos del motor y la sede resuelta.
 *   2. **Idempotencia (D4).** La misma mutación del mismo reponedor devuelve la
 *      misma recaudación; la de otro reponedor no colisiona; el repositorio
 *      traduce la carrera de unicidad a la fila ganadora o al conflicto de
 *      precinto, sin dejar escapar el `23505`.
 *   3. **Último contador (D2, C2).** `findLastForMachine()` devuelve el corte más
 *      reciente de esa máquina, sea cual sea su estado de custodia.
 *   4. **Ciclo de custodia (D4, Art. III.3).** `SEALED_IN_ROUTE → DELIVERED_TO_VAULT`
 *      sella la recepción; el arqueo sella el estado terminal (y la recepción en
 *      cámara si se hace en un solo paso); una fila terminal ya no transiciona.
 *   5. **Seis KPIs y filtros (QA 3).** Bolsas pendientes, arqueos conformes,
 *      descuadres, efectivo contado, descuadre neto y cambio/conserjería se
 *      recalculan sobre el filtro activo (restocker, máquina, estado, precinto
 *      y fechas).
 *   6. **Arqueo Ciego y resumen (C2, QA 3, Art. V.4).** La proyección del
 *      reponedor no contiene las cinco claves financieras privadas; el resumen
 *      solo suma cambio y conserjería declarados.
 *
 * CATEGORÍA: `new_engine`
 *   Exige un PostgreSQL disponible. Sin `PG_TEST_DSN` sale con código 2 y lo
 *   dice; no se omite en silencio.
 *
 * LIMPIEZA: esquema aislado `t_ccv05_repositorio` borrado con `CASCADE` al
 * terminar, incluso si una aserción falla.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php tests/integration/PdoMachineCashCollectionRepositoryTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';

use VendGuard\Core\Domain\Enum\CashCollectionStatus;
use VendGuard\Core\Domain\Exception\MachineCashCollectionUniqueViolationException;
use VendGuard\Core\Domain\Model\MachineCashCollection;
use VendGuard\Infrastructure\Repository\PdoMachineCashCollectionRepository;

const VENDGUARD_TEST_CATEGORY = 'new_engine';
const CCV05_SCHEMA = 't_ccv05_repositorio';

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

$expectInvalidArgument = static function (callable $operation): bool {
    try {
        $operation();
    } catch (InvalidArgumentException $exception) {
        return true;
    } catch (Throwable $exception) {
        return false;
    }

    return false;
};

$expectUniqueViolation = static function (callable $operation): ?MachineCashCollectionUniqueViolationException {
    try {
        $operation();
    } catch (MachineCashCollectionUniqueViolationException $exception) {
        return $exception;
    } catch (Throwable $exception) {
        return null;
    }

    return null;
};

echo "======================================================================\n";
echo " VENDGUARD · T-CCV-05 · Repositorio PDO de recaudaciones y arqueos\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pdo = PostgresFixtureBuilder::gateOrExit();
$crash = null;
$residue = null;

try {
    PostgresFixtureBuilder::createIsolationSchema($pdo, CCV05_SCHEMA);
    PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    // ─────────────────────────────────────────────────────────────────────
    // Escenario
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Escenario (sede con recepción, dos máquinas y tres actores) ---\n";

    $locationStmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, has_physical_reception)
         VALUES (:site_code, :site_code_key, :name, :address, TRUE)
         RETURNING id'
    );
    $locationStmt->execute([
        ':site_code' => 'SEDE-CCV05',
        ':site_code_key' => 'sede-ccv05',
        ':name' => 'Sede de certificación del repositorio',
        ':address' => 'Carrer de la Caixa 35, Barcelona',
    ]);
    $locationId = (int)$locationStmt->fetchColumn();

    $machineStmt = $pdo->prepare(
        "INSERT INTO machines (location_id, code, model, machine_type, floor_wing)
         VALUES (:location_id, :code, :model, 'COMBO', 'Planta baja')
         RETURNING id"
    );
    $machineIds = [];
    foreach (['A' => 'VEND-CCV05-A', 'B' => 'VEND-CCV05-B'] as $key => $code) {
        $machineStmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => 'Modelo de certificación ' . $code,
        ]);
        $machineIds[$key] = (int)$machineStmt->fetchColumn();
    }

    $userStmt = $pdo->prepare(
        'INSERT INTO users (name, email, email_key, password_hash, role)
         VALUES (:name, :email, :email_key, :password_hash, :role)
         RETURNING id'
    );
    $userIds = [];
    foreach ([
        'restocker_a' => ['Reponedora de certificación A', 'ccv05.a@gestion-vending.test', 'RESTOCKER'],
        'restocker_b' => ['Reponedor de certificación B', 'ccv05.b@gestion-vending.test', 'RESTOCKER'],
        'coordinator' => ['Coordinadora de Caja Central', 'ccv05.caja@gestion-vending.test', 'COORDINATOR'],
    ] as $key => [$name, $email, $role]) {
        $userStmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':email_key' => $email,
            ':password_hash' => password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 4]),
            ':role' => $role,
        ]);
        $userIds[$key] = (int)$userStmt->fetchColumn();
    }

    $repo = new PdoMachineCashCollectionRepository($pdo);

    $makeCollection = static function (
        string $code,
        string $seal,
        string $mutation,
        int $machineId,
        int $restockerId,
        string $restockerName,
        string $restockerCode,
        string $collectedAt,
        float $change,
        float $reception = 0.00,
        ?string $receptionist = null,
        float $sales = 125.50,
        ?float $previous = 100.00,
        float $expected = 25.50
    ) use ($machineIds): MachineCashCollection {
        return new MachineCashCollection(
            null,
            $code,
            null,
            $machineId,
            $machineId === $machineIds['A'] ? 'VEND-CCV05-A' : 'VEND-CCV05-B',
            $restockerId,
            $restockerName,
            $restockerCode,
            $seal,
            $change,
            $sales,
            $previous,
            0.00,
            false,
            null,
            $expected,
            $reception,
            $receptionist,
            null,
            null,
            CashCollectionStatus::SEALED_IN_ROUTE,
            $collectedAt,
            $mutation
        );
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Alta en ruta (RF-01.1–RF-01.3, C1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Alta en ruta y normalización del precinto ---\n";

    $first = $repo->insert($makeCollection(
        'MCC-2026-000101',
        'bag-2026-000101',
        'mutation-ccv05-a-001',
        $machineIds['A'],
        $userIds['restocker_a'],
        'Reponedora de certificación A',
        'REP-CCV05-A',
        '2026-11-10 10:00:00+00',
        20.00
    ));

    $assert('El alta devuelve la fila con su identificador', $first->getId() !== null && $first->getId() > 0);
    $assert(
        'El precinto se normaliza a mayúsculas y se conserva (C1)',
        $first->getBagSealCode() === 'BAG-2026-000101',
        $first->getBagSealCode()
    );
    $assert(
        'La captura nace en SEALED_IN_ROUTE con sus sellos del motor',
        $first->getStatus() === CashCollectionStatus::SEALED_IN_ROUTE
            && $first->getCreatedAt() !== null
            && $first->getExpectedCashboxEur() === 25.50
    );
    $assert(
        'La lectura resuelve la sede y el nombre del reponedor congelado',
        $first->getLocationName() === 'Sede de certificación del repositorio'
            && $first->getLocationCode() === 'SEDE-CCV05'
            && $first->getRestockerNameSnapshot() === 'Reponedora de certificación A'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Idempotencia y unicidad (D4, C1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Idempotencia del móvil y unicidad del precinto ---\n";

    $byMutation = $repo->findByClientMutationId($userIds['restocker_a'], ' mutation-ccv05-a-001 ');
    $assert(
        'La misma mutación del mismo reponedor resuelve la misma recaudación (D4)',
        $byMutation !== null && $byMutation->getId() === $first->getId()
    );
    $assert(
        'La mutación de otro reponedor no colisiona (D4)',
        $repo->findByClientMutationId($userIds['restocker_b'], 'mutation-ccv05-a-001') === null
    );
    $lowerSeal = $repo->findByBagSealCode(' bag-2026-000101 ');
    $assert(
        'El precinto se busca normalizado y es único en la historia (C1)',
        $lowerSeal !== null && $lowerSeal->getId() === $first->getId()
    );
    $assert(
        'Un precinto desconocido no devuelve fila',
        $repo->findByBagSealCode('BAG-2026-999999') === null
    );

    $duplicateMutation = $expectUniqueViolation(static fn () => $repo->insert($makeCollection(
        'MCC-2026-000102',
        'BAG-2026-000102',
        'mutation-ccv05-a-001',
        $machineIds['A'],
        $userIds['restocker_a'],
        'Reponedora de certificación A',
        'REP-CCV05-A',
        '2026-11-10 10:30:00+00',
        0.00
    )));
    $assert(
        'Una segunda fila con la misma mutación se traduce a la fila ganadora (D4)',
        $duplicateMutation !== null
            && $duplicateMutation->getReason() === MachineCashCollectionUniqueViolationException::DUPLICATE_MUTATION
            && $duplicateMutation->getExistingCollection()?->getId() === $first->getId(),
        $duplicateMutation?->getReason() ?? 'sin rechazo'
    );

    $duplicateSeal = $expectUniqueViolation(static fn () => $repo->insert($makeCollection(
        'MCC-2026-000102',
        'BAG-2026-000101',
        'mutation-ccv05-a-002',
        $machineIds['A'],
        $userIds['restocker_a'],
        'Reponedora de certificación A',
        'REP-CCV05-A',
        '2026-11-10 10:30:00+00',
        0.00
    )));
    $assert(
        'Un precinto ya consumido se traduce a un conflicto de negocio (C1)',
        $duplicateSeal !== null
            && $duplicateSeal->getReason() === MachineCashCollectionUniqueViolationException::DUPLICATE_SEAL,
        $duplicateSeal?->getReason() ?? 'sin rechazo'
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Escenario completo y último contador (D2, C2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Último contador por máquina ---\n";

    $second = $repo->insert($makeCollection(
        'MCC-2026-000102',
        'BAG-2026-000102',
        'mutation-ccv05-a-002',
        $machineIds['A'],
        $userIds['restocker_a'],
        'Reponedora de certificación A',
        'REP-CCV05-A',
        '2026-11-10 11:00:00+00',
        10.00,
        4.50,
        'Conserje de Recepción'
    ));

    $third = $repo->insert($makeCollection(
        'MCC-2026-000103',
        'BAG-2026-000103',
        'mutation-ccv05-a-003',
        $machineIds['B'],
        $userIds['restocker_a'],
        'Reponedora de certificación A',
        'REP-CCV05-A',
        '2026-11-10 12:00:00+00',
        5.00
    ));

    $fourth = $repo->insert($makeCollection(
        'MCC-2026-000104',
        'BAG-2026-000104',
        'mutation-ccv05-b-001',
        $machineIds['B'],
        $userIds['restocker_b'],
        'Reponedor de certificación B',
        'REP-CCV05-B',
        '2026-11-10 13:00:00+00',
        0.00
    ));

    $lastA = $repo->findLastForMachine($machineIds['A']);
    $assert(
        'La última lectura de la máquina A es el corte más reciente (D2)',
        $lastA !== null && $lastA->getId() === $second->getId()
            && $lastA->getSalesCounterReading() === 125.50,
        $lastA !== null ? (string)$lastA->getId() : 'null'
    );
    $assert(
        'La máquina B entrega su propio último corte',
        ($repo->findLastForMachine($machineIds['B'])?->getId()) === $fourth->getId()
    );
    $assert(
        'Una máquina sin historia no inventa lectura previa',
        $repo->findLastForMachine(999999) === null
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Listados, filtros y opacidad (QA 3, C2, Art. V.4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Listados, filtros y proyecciones segregadas ---\n";

    $restockerA = $repo->listForRestocker($userIds['restocker_a']);
    $restockerB = $repo->listForRestocker($userIds['restocker_b']);
    $assert('El historial del reponedor A contiene sus tres cortes', count($restockerA) === 3, (string)count($restockerA));
    $assert('El historial del reponedor B contiene su único corte', count($restockerB) === 1);

    $machineAFilter = $repo->listForRestocker($userIds['restocker_a'], ['machine_id' => $machineIds['A']]);
    $assert('El filtro por máquina acota el historial', count($machineAFilter) === 2);

    $dateMiss = $repo->listForRestocker($userIds['restocker_a'], ['date_from' => '2026-11-11']);
    $assert('El filtro de fecha excluye los cortes anteriores', $dateMiss === []);

    $statusMiss = $repo->listForRestocker($userIds['restocker_a'], ['status' => 'RECONCILED_OK']);
    $assert('El filtro por estado no adelanta arqueos inexistentes', $statusMiss === []);

    $coordinatorBook = $repo->listForCoordinator();
    $assert(
        'El libro de Coordinación contiene las cuatro recaudaciones',
        count($coordinatorBook['collections']) === 4,
        (string)count($coordinatorBook['collections'])
    );

    $restockerView = $restockerA[0]->toRestockerArray();
    $privateKeys = [
        'previous_sales_counter_reading',
        'expected_cashbox_eur',
        'vault_counted_eur',
        'discrepancy_eur',
        'discrepancy_justification',
    ];
    $leaked = array_values(array_intersect($privateKeys, array_keys($restockerView)));
    $assert(
        'La vista del reponedor no contiene ninguna clave financiera privada (C2, Art. V.4)',
        $leaked === [],
        implode(', ', $leaked)
    );
    $assert(
        'La vista de Coordinación sí contiene la lectura previa y el esperado (D2)',
        array_key_exists('previous_sales_counter_reading', $coordinatorBook['collections'][0]->toCoordinatorArray())
            && array_key_exists('expected_cashbox_eur', $coordinatorBook['collections'][0]->toCoordinatorArray())
    );

    $summaryA = $repo->summarizeForRestocker($userIds['restocker_a']);
    $assert(
        'El resumen del reponedor suma cambio y conserjería declarados (QA 3)',
        $summaryA['total_collections_count'] === 3
            && $summaryA['total_change_tubes_loaded_eur'] === 35.00
            && $summaryA['total_reception_cash_delivered_eur'] === 4.50
            && $summaryA['pending_vault_bags_count'] === 3
            && $summaryA['last_collected_at'] !== null,
        json_encode($summaryA)
    );
    $assert(
        'El resumen no expone el esperado ni el arqueo (C2, Art. V.4)',
        array_intersect($privateKeys, array_keys($summaryA)) === []
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Ciclo de custodia (RF-04.1, RF-04.2, D4, Art. III.3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Recepción en cámara y arqueo terminal ---\n";

    $delivered = $repo->markDeliveredToVault($second->getId(), $userIds['coordinator'], '2026-11-10 14:00:00+00');
    $assert(
        'La recepción en cámara sella DELIVERED_TO_VAULT con su actor (RF-04.1)',
        $delivered !== null
            && $delivered->getStatus() === CashCollectionStatus::DELIVERED_TO_VAULT
            && $delivered->getDeliveredToVaultBy() === $userIds['coordinator']
            && $delivered->getDeliveredToVaultAt() !== null
    );
    $assert(
        'Una bolsa ya recepcionada no vuelve a recepcionarse (D4)',
        $repo->markDeliveredToVault($second->getId(), $userIds['coordinator']) === null
    );

    $reconciledOk = $repo->reconcile(
        $second->getId(),
        $userIds['coordinator'],
        'Coordinadora de Caja Central',
        26.00,
        0.50,
        CashCollectionStatus::RECONCILED_OK,
        null,
        '2026-11-10 15:00:00+00'
    );
    $assert(
        'El arqueo dentro de tolerancia sella RECONCILED_OK (D2)',
        $reconciledOk !== null
            && $reconciledOk->getStatus() === CashCollectionStatus::RECONCILED_OK
            && $reconciledOk->getVaultCountedEur() === 26.00
            && $reconciledOk->getDiscrepancyEur() === 0.50
            && $reconciledOk->getVaultReconciledByNameSnapshot() === 'Coordinadora de Caja Central'
    );
    $assert(
        'Una fila terminal ya no se vuelve a arquear (D4, Art. III.3)',
        $repo->reconcile(
            $second->getId(),
            $userIds['coordinator'],
            'Coordinadora de Caja Central',
            26.00,
            0.50,
            CashCollectionStatus::RECONCILED_OK
        ) === null
    );

    $flagged = $repo->reconcile(
        $third->getId(),
        $userIds['coordinator'],
        'Coordinadora de Caja Central',
        20.00,
        -5.50,
        CashCollectionStatus::DISCREPANCY_FLAGGED,
        'Faltan cinco euros y medio en la bolsa precintada del corte.',
        '2026-11-10 16:00:00+00'
    );
    $assert(
        'El arqueo desde ruta sella la recepción y el descuadre justificado (D2, D4)',
        $flagged !== null
            && $flagged->getStatus() === CashCollectionStatus::DISCREPANCY_FLAGGED
            && $flagged->getDeliveredToVaultAt() !== null
            && $flagged->getDeliveredToVaultBy() === $userIds['coordinator']
            && $flagged->getDiscrepancyEur() === -5.50
            && $flagged->getDiscrepancyJustification() !== null
    );

    $flaggedB = $repo->reconcile(
        $fourth->getId(),
        $userIds['coordinator'],
        'Coordinadora de Caja Central',
        30.00,
        4.50,
        CashCollectionStatus::DISCREPANCY_FLAGGED,
        'Sobran cuatro euros y medio según el recuento físico de cámara.',
        '2026-11-10 17:00:00+00'
    );
    $assert('El segundo descuadre también queda sellado', $flaggedB !== null);

    $immutability = PostgresFixtureBuilder::expectRejection(static function () use ($pdo, $second): void {
        $stmt = $pdo->prepare('UPDATE machine_cash_collections SET expected_cashbox_eur = 99.99 WHERE id = :id');
        $stmt->execute([':id' => $second->getId()]);
    });
    $assert(
        'El motor rechaza reescribir una captura ya arqueada (Art. III.3)',
        $immutability !== null && $immutability['state'] === '45000',
        (string)($immutability['state'] ?? 'sin rechazo')
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Seis KPIs reactivos al filtro (QA 3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Seis KPIs de Coordinación ---\n";

    $kpis = $coordinatorBook['kpis'];
    $assert(
        'Los KPIs iniciales cuentan cuatro bolsas pendientes y ningún arqueo',
        $kpis['pending_vault_bags_count'] === 4
            && $kpis['reconciled_ok_count'] === 0
            && $kpis['discrepancy_flagged_count'] === 0
            && $kpis['total_vault_counted_eur'] === 0.0
            && $kpis['net_discrepancy_eur'] === 0.0
            && $kpis['total_route_cash_deployed_eur'] === 39.50,
        json_encode($kpis)
    );

    $after = $repo->listForCoordinator()['kpis'];
    $assert(
        'Tras los arqueos, los seis KPIs reflejan el libro completo (QA 3)',
        $after['pending_vault_bags_count'] === 1
            && $after['reconciled_ok_count'] === 1
            && $after['discrepancy_flagged_count'] === 2
            && $after['total_vault_counted_eur'] === 76.00
            && $after['net_discrepancy_eur'] === -0.50
            && $after['total_route_cash_deployed_eur'] === 39.50,
        json_encode($after)
    );

    $onlyOk = $repo->listForCoordinator(['status' => 'RECONCILED_OK']);
    $assert(
        'El filtro por estado recalcula KPIs y listado a la vez (QA 3)',
        count($onlyOk['collections']) === 1
            && $onlyOk['kpis']['pending_vault_bags_count'] === 0
            && $onlyOk['kpis']['reconciled_ok_count'] === 1
            && $onlyOk['kpis']['total_vault_counted_eur'] === 26.00
            && $onlyOk['kpis']['net_discrepancy_eur'] === 0.50,
        json_encode($onlyOk['kpis'])
    );

    $bySeal = $repo->listForCoordinator(['bag_seal_code' => 'bag-2026-000104']);
    $assert(
        'El filtro por precinto devuelve su única recaudación',
        count($bySeal['collections']) === 1
            && $bySeal['collections'][0]->getId() === $fourth->getId()
            && $bySeal['kpis']['discrepancy_flagged_count'] === 1
    );

    $byMachine = $repo->listForCoordinator(['machine_id' => $machineIds['A']]);
    $assert(
        'El filtro por máquina acota el libro y sus KPIs',
        count($byMachine['collections']) === 2
            && $byMachine['kpis']['pending_vault_bags_count'] === 1
            && $byMachine['kpis']['reconciled_ok_count'] === 1
            && $byMachine['kpis']['discrepancy_flagged_count'] === 0
            && $byMachine['kpis']['total_vault_counted_eur'] === 26.00
            && $byMachine['kpis']['total_route_cash_deployed_eur'] === 34.50,
        json_encode($byMachine['kpis'])
    );

    $byRestocker = $repo->listForCoordinator(['restocker_id' => $userIds['restocker_b']]);
    $assert(
        'El filtro por reponedor aísla su bolsa',
        count($byRestocker['collections']) === 1
            && $byRestocker['kpis']['discrepancy_flagged_count'] === 1
            && $byRestocker['kpis']['total_vault_counted_eur'] === 30.00
    );

    $outOfRange = $repo->listForCoordinator(['date_from' => '2026-11-11']);
    $assert(
        'El filtro de fecha vacía listado y KPIs a la vez',
        $outOfRange['collections'] === []
            && $outOfRange['kpis']['pending_vault_bags_count'] === 0
            && $outOfRange['kpis']['total_route_cash_deployed_eur'] === 0.0
    );

    $summaryAfter = $repo->summarizeForRestocker($userIds['restocker_a']);
    $assert(
        'El resumen del reponedor descuenta las bolsas ya entregadas (QA 3)',
        $summaryAfter['pending_vault_bags_count'] === 1
            && $summaryAfter['total_reception_cash_delivered_eur'] === 4.50
    );
    $summaryB = $repo->summarizeForRestocker($userIds['restocker_b']);
    $assert(
        'El resumen del reponedor B no mezcla operarios',
        $summaryB['total_collections_count'] === 1
            && $summaryB['total_change_tubes_loaded_eur'] === 0.0
            && $summaryB['pending_vault_bags_count'] === 0
    );

    $summaryMachineB = $repo->summarizeForRestocker(
        $userIds['restocker_a'],
        ['machine_id' => $machineIds['B']]
    );
    $summaryFuture = $repo->summarizeForRestocker(
        $userIds['restocker_a'],
        ['date_from' => '2026-11-11']
    );
    $assert(
        'El KPI de pendientes del reponedor respeta máquina y fechas (QA 3)',
        $summaryMachineB['total_collections_count'] === 1
            && $summaryMachineB['pending_vault_bags_count'] === 0
            && $summaryFuture['total_collections_count'] === 0
            && $summaryFuture['pending_vault_bags_count'] === 0,
        json_encode(['machine_b' => $summaryMachineB, 'future' => $summaryFuture])
    );

    $summaryStatusOk = $repo->summarizeForRestocker($userIds['restocker_a'], [
        'machine_id' => $machineIds['A'],
        'status' => 'RECONCILED_OK',
        'date_from' => '2026-11-10',
        'date_to' => '2026-11-10',
    ]);
    $summaryStatusSealed = $repo->summarizeForRestocker($userIds['restocker_a'], [
        'machine_id' => $machineIds['A'],
        'status' => 'SEALED_IN_ROUTE',
        'date_from' => '2026-11-10',
        'date_to' => '2026-11-10',
    ]);
    $assert(
        'El KPI de pendientes respeta el filtro de estado dentro de máquina y fecha (QA 3)',
        $summaryStatusOk['total_collections_count'] === 1
            && $summaryStatusOk['pending_vault_bags_count'] === 0
            && $summaryStatusSealed['total_collections_count'] === 1
            && $summaryStatusSealed['pending_vault_bags_count'] === 1,
        json_encode(['reconciled' => $summaryStatusOk, 'sealed' => $summaryStatusSealed])
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Validación de filtros y del arqueo
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- Validación de filtros y del arqueo ---\n";

    $assert(
        'Un estado desconocido se rechaza antes de tocar el motor',
        $expectInvalidArgument(static fn () => $repo->listForCoordinator(['status' => 'BOGUS']))
    );
    $assert(
        'Un límite no positivo se rechaza',
        $expectInvalidArgument(static fn () => $repo->listForRestocker($userIds['restocker_a'], ['limit' => 0]))
    );
    $assert(
        'Un offset negativo se rechaza',
        $expectInvalidArgument(static fn () => $repo->listForRestocker($userIds['restocker_a'], ['offset' => -1]))
    );
    $assert(
        'Una fecha mal formada se rechaza',
        $expectInvalidArgument(static fn () => $repo->listForCoordinator(['date_from' => '10/11/2026']))
    );
    $assert(
        'Un estado intermedio no puede sellarse como arqueo',
        $expectInvalidArgument(static fn () => $repo->reconcile(
            $first->getId(),
            $userIds['coordinator'],
            'Coordinadora de Caja Central',
            25.50,
            0.00,
            CashCollectionStatus::SEALED_IN_ROUTE
        ))
    );
    $assert(
        'Un arqueo sin nombre de coordinador se rechaza',
        $expectInvalidArgument(static fn () => $repo->reconcile(
            $first->getId(),
            $userIds['coordinator'],
            '   ',
            25.50,
            0.00,
            CashCollectionStatus::RECONCILED_OK
        ))
    );

    echo "\n--- Resultado ---\n";
    echo " Total Aserciones: {$assertions} | Fallos: {$failures}\n";
} catch (Throwable $exception) {
    $crash = $exception;
} finally {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, CCV05_SCHEMA);
        $probe = $pdo->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = :schema');
        $probe->execute([':schema' => CCV05_SCHEMA]);
        $residue = $probe->fetchColumn() === false ? null : ['state' => 'present'];
    } catch (Throwable $exception) {
        $residue = ['state' => 'drop_failed'];
    }
}

echo "\n--- Limpieza ---\n";
echo $residue === null
    ? "  [OK]  El esquema aislado se eliminó sin residuo\n"
    : "  [FAIL] Quedó residuo del esquema aislado\n";

if ($residue !== null) {
    $failures++;
}

if ($crash !== null) {
    fwrite(STDERR, "\n[FAIL] " . $crash->getMessage() . "\n");
    exit(1);
}

echo $failures === 0
    ? "\n[VERDE] T-CCV-05: repositorio de recaudaciones certificado.\n"
    : "\n[ROJO] T-CCV-05: {$failures} fallo(s).\n";

exit($failures === 0 ? 0 : 1);
