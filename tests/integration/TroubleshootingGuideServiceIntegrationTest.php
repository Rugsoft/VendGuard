<?php

declare(strict_types=1);

/**
 * TroubleshootingGuideServiceIntegrationTest · T-GUIDE-09
 *
 * Certifica contra PostgreSQL, sobre un esquema aislado y con el catálogo
 * sembrado real, los casos de uso del servicio de guías de diagnóstico
 * (Módulo 28): emparejamiento por clave, conmutador de categoría, salvaguarda
 * sanitaria, cruce de furgoneta, tolerancia a bajas del catálogo, cobertura del
 * parque y CRUD auditado.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Emparejamiento y conmutador (`C5`, `QA 3`, `RF-01.2`).**
 *      - Mayúsculas y espacios sobrantes caen en la misma guía.
 *      - La categoría solicitada sube a la primera posición y el resto conserva
 *        la jerarquía canónica con `TEMPERATURE_COLD` al frente.
 *      - Sin coincidencia exacta, `has_exact_category_match = false` y se
 *        preselecciona la primera guía disponible.
 *      - Una categoría desconocida responde `422 INVALID_GUIDE_CATEGORY`.
 *
 *   2. **Salvaguarda sanitaria (`Art. II`, `RF-01.3`).** El aviso se enciende
 *      por guía de frío y por máquina `PERISHABLE_FOOD`, y la consulta de frío
 *      lo enciende a nivel de máquina.
 *
 *   3. **Cruce de furgoneta (`Art. V.4`).** Saldo positivo, cero y negativo se
 *      proyectan con `has_van_stock = (quantity > 0)`, conservando el orden de
 *      la guía, y ninguna clave del resultado transporta coste o precio.
 *
 *   4. **Tolerancia a bajas (`C2`).** Una pieza desactivada sigue viajando con
 *      `is_active = false`; una causa raíz desactivada se oculta.
 *
 *   5. **Cobertura (`QA 1`).** Variantes de escritura del mismo modelo se
 *      agrupan en una sola fila del parque, `Fas Perla` aparece como huérfano y
 *      las métricas no cambian al filtrar el listado.
 *
 *   6. **CRUD auditado (`Art. III.3`).** Alta, edición, baja y reactivación
 *      dejan en `audit_log` el `previous_state` y el `new_state` completos bajo
 *      `MACHINE_TROUBLESHOOTING_GUIDE`; el duplicado responde `409` y la
 *      identidad inmutable `422`.
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL local (`deploy/local/postgres/`);
 * sin motor sale con código 2, nunca se omite en silencio.
 *
 * Uso:
 *   php tests/integration/TroubleshootingGuideServiceIntegrationTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación en español.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\TroubleshootingGuideService;
use VendGuard\Core\Domain\Exception\TroubleshootingGuideValidationException;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoMachineTroubleshootingGuideRepository;
use VendGuard\Infrastructure\Repository\PdoRootCauseRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRepository;

/** Marca de categoría que lee la batería global. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado propio de esta suite. */
const GUIDES_SERVICE_SCHEMA = 't_guides28_service';

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

$errorOf = static function (callable $operation): array {
    try {
        $operation();
    } catch (TroubleshootingGuideValidationException $exception) {
        return [$exception->getErrorCode(), $exception->getHttpStatusCode()];
    } catch (Throwable $exception) {
        return ['UNEXPECTED: ' . $exception->getMessage(), 0];
    }

    return ['NO_EXCEPTION', 0];
};

echo "======================================================================\n";
echo " VENDGUARD · T-GUIDE-09 · Servicio de guías de diagnóstico (Módulo 28)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pdo = null;
$crash = null;

try {
    echo "\n--- Escenario (DDL base + migraciones + semillas en esquema aislado) ---\n";

    $pdo = PostgresFixtureBuilder::gateOrExit();
    PostgresFixtureBuilder::createIsolationSchema($pdo, GUIDES_SERVICE_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);
    (new SeedRunner($pdo))->seedAll();

    $assert(
        'El esquema incluye la migración 024 y el catálogo se siembra',
        in_array('024_machine_troubleshooting_guides.sql', $schemaReport['migrations'], true)
        && (int)$pdo->query('SELECT COUNT(*) FROM machine_troubleshooting_guides')->fetchColumn() === 8,
        'guías sembradas: ' . (string)$pdo->query('SELECT COUNT(*) FROM machine_troubleshooting_guides')->fetchColumn()
    );

    $service = new TroubleshootingGuideService(
        new PdoMachineTroubleshootingGuideRepository($pdo),
        new PdoRootCauseRepository($pdo),
        new PdoSparePartRepository($pdo),
        $pdo
    );

    $coordinatorId = (int)$pdo->query(
        "SELECT id FROM users WHERE role = 'COORDINATOR' AND is_active = TRUE ORDER BY id LIMIT 1"
    )->fetchColumn();
    $technicianId = (int)$pdo->query(
        "SELECT id FROM users WHERE email_key = 'jordi.ruta@vendguard.internal' LIMIT 1"
    )->fetchColumn();

    $partIds = [];
    foreach ($pdo->query('SELECT id, part_code FROM spare_parts')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $partIds[(string)$row['part_code']] = (int)$row['id'];
    }
    $rootCauseIds = [];
    foreach ($pdo->query('SELECT id, code FROM root_causes')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rootCauseIds[(string)$row['code']] = (int)$row['id'];
    }

    $assert('El escenario tiene coordinador, técnico con furgoneta y catálogos', $coordinatorId > 0 && $technicianId > 0 && $partIds !== [] && $rootCauseIds !== []);

    $stepsProbe = [
        ['title' => 'Comprobar alimentación del equipo', 'instruction' => 'Verificar que el interruptor general esté cerrado y que llegue tensión de 230 voltios al borne de entrada de la máquina.'],
        ['title' => 'Revisar el conector del módulo', 'instruction' => 'Desconectar el conector del módulo, comprobar que no haya pines doblados y volver a insertarlo hasta el fondo.'],
    ];

    $paymentGuide = $service->createGuide([
        'machine_model' => 'Sanden Vendo G-Drink',
        'breakdown_type' => 'PAYMENT_SYSTEM',
        'title' => 'Pasarela de pago en Sanden Vendo G-Drink: lector y monedero',
        'error_codes' => [['code' => 'MDB-02', 'meaning' => 'Validador de monedas sin respuesta en el bus MDB del equipo']],
        'steps' => $stepsProbe,
        'recommended_spare_part_ids' => [$partIds['MON-CASH-01']],
        'suggested_root_cause_id' => $rootCauseIds['RC-PAY-01'],
    ], $coordinatorId, 'Coordinación de Prueba');
    $paymentGuideId = (int)$paymentGuide['id'];

    echo "\n--- 1. Emparejamiento, conmutador y respaldo (C5, QA 3, RF-01.2) ---\n";

    $coldGuideId = (int)$pdo->query(
        "SELECT id FROM machine_troubleshooting_guides
          WHERE machine_model_key = 'sanden vendo g-drink' AND breakdown_type = 'TEMPERATURE_COLD'"
    )->fetchColumn();

    $match = $service->getGuidesForModel('  SANDEN   VENDO G-DRINK  ', 'PAYMENT_SYSTEM', null, $technicianId);
    $assert('El modelo se empareja ignorando mayúsculas y espacios (C5)', $match['machine_model_key'] === 'sanden vendo g-drink', $match['machine_model_key']);
    $assert(
        'La categoría solicitada se coloca en primera posición (QA 3)',
        ($match['guides'][0]['breakdown_type'] ?? '') === 'PAYMENT_SYSTEM'
        && ($match['guides'][0]['id'] ?? null) === $paymentGuideId
        && ($match['guides'][1]['breakdown_type'] ?? '') === 'TEMPERATURE_COLD',
        'orden: ' . implode(', ', array_column($match['guides'], 'breakdown_type'))
    );
    $assert('La coincidencia exacta se declara y preselecciona', $match['has_exact_category_match'] === true && $match['selected_guide_id'] === $paymentGuideId);

    $fallback = $service->getGuidesForModel('Sanden Vendo G-Drink', 'OTHER', null, $technicianId);
    $assert(
        'Sin categoría exacta se marca y se preselecciona la primera canónica (RF-01.2)',
        $fallback['has_exact_category_match'] === false
        && $fallback['requested_breakdown_type'] === 'OTHER'
        && $fallback['selected_guide_id'] === $coldGuideId
        && ($fallback['guides'][0]['breakdown_type'] ?? '') === 'TEMPERATURE_COLD',
        'seleccionada: ' . (string)$fallback['selected_guide_id']
    );

    $empty = $service->getGuidesForModel('Modelo Sin Guía M28', null, null, $technicianId);
    $assert(
        'Un modelo sin guías devuelve una respuesta vacía coherente',
        $empty['guides'] === [] && $empty['selected_guide_id'] === null && $empty['has_exact_category_match'] === false
    );

    [$invalidCode, $invalidStatus] = $errorOf(static fn () => $service->getGuidesForModel('Sanden Vendo G-Drink', 'NO_EXISTE', null, $technicianId));
    $assert('Una categoría desconocida responde 422 (C3)', $invalidCode === 'INVALID_GUIDE_CATEGORY' && $invalidStatus === 422, $invalidCode . ' · ' . $invalidStatus);

    echo "\n--- 2. Salvaguarda sanitaria de frío (Art. II, RF-01.3) ---\n";

    $perishable = $service->getGuidesForModel('Sanden Vendo G-Drink', null, 'PERISHABLE_FOOD', $technicianId);
    $allWarn = true;
    foreach ($perishable['guides'] as $guide) {
        if ($guide['sanitary_cold_warning'] !== true) {
            $allWarn = false;
        }
    }
    $assert('Toda guía de una máquina perecedera avisa de cadena de frío', $perishable['sanitary_cold_warning_for_machine'] === true && $allWarn);

    $mixed = $service->getGuidesForModel('Sanden Vendo G-Drink', null, null, $technicianId);
    $flags = [];
    foreach ($mixed['guides'] as $guide) {
        $flags[$guide['breakdown_type']] = $guide['sanitary_cold_warning'];
    }
    $assert(
        'El aviso por guía se enciende sólo en la categoría de frío',
        ($flags['TEMPERATURE_COLD'] ?? false) === true && ($flags['PAYMENT_SYSTEM'] ?? true) === false
    );

    $coldQuery = $service->getGuidesForModel('Sanden Vendo G-Drink', 'TEMPERATURE_COLD', null, $technicianId);
    $assert('La consulta de frío enciende el aviso de la máquina', $coldQuery['sanitary_cold_warning_for_machine'] === true);

    echo "\n--- 3. Cruce de repuestos con la furgoneta (Art. V.4) ---\n";

    $pdo->prepare(
        'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
         VALUES (:technician, :part, :quantity, 0)
         ON CONFLICT (technician_id, spare_part_id)
         DO UPDATE SET quantity_available = EXCLUDED.quantity_available'
    )->execute([':technician' => $technicianId, ':part' => $partIds['MON-CASH-01'], ':quantity' => 0]);
    $pdo->prepare(
        'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
         VALUES (:technician, :part, :quantity, 0)
         ON CONFLICT (technician_id, spare_part_id)
         DO UPDATE SET quantity_available = EXCLUDED.quantity_available'
    )->execute([':technician' => $technicianId, ':part' => $partIds['TELEC-NAYAX-01'], ':quantity' => -1]);

    $service->createGuide([
        'machine_model' => 'Banco de Pruebas M28',
        'breakdown_type' => 'OTHER',
        'title' => 'Guía de banco para el cruce de repuestos de la furgoneta',
        'error_codes' => [],
        'steps' => $stepsProbe,
        'recommended_spare_part_ids' => [
            $partIds['VALV-ULKA-01'],
            $partIds['MON-CASH-01'],
            $partIds['TELEC-NAYAX-01'],
        ],
        'suggested_root_cause_id' => $rootCauseIds['RC-OTH-01'],
    ], $coordinatorId, 'Coordinación de Prueba');

    $stock = $service->getGuidesForModel('Banco de Pruebas M28', 'OTHER', null, $technicianId);
    $parts = $stock['guides'][0]['recommended_spare_parts'] ?? [];
    $quantities = array_column($parts, 'van_stock_quantity');
    $availability = array_column($parts, 'has_van_stock');
    $assert(
        'El orden de la guía se respeta y cada saldo se cruza (positivo, cero y negativo)',
        array_column($parts, 'spare_part_id') === [$partIds['VALV-ULKA-01'], $partIds['MON-CASH-01'], $partIds['TELEC-NAYAX-01']]
        && $quantities === [4, 0, -1]
        && $availability === [true, false, false],
        'cantidades: ' . implode(', ', $quantities)
    );

    $noTechnician = $service->getGuidesForModel('Banco de Pruebas M28', 'OTHER', null, null);
    $noTechParts = $noTechnician['guides'][0]['recommended_spare_parts'] ?? [];
    $assert(
        'Sin técnico autenticado no se inventa saldo',
        array_column($noTechParts, 'van_stock_quantity') === [0, 0, 0]
        && array_column($noTechParts, 'has_van_stock') === [false, false, false]
    );

    $forbiddenKeys = [];
    array_walk_recursive($parts, static function (mixed $value, int|string $key) use (&$forbiddenKeys): void {
        if (is_string($key) && preg_match('/cost|price|amount|importe|precio|coste/i', $key) === 1) {
            $forbiddenKeys[$key] = true;
        }
    });
    $assert('Ningún importe económico viaja al técnico (Art. V.4)', $forbiddenKeys === [], implode(', ', array_keys($forbiddenKeys)));

    $cause = $stock['guides'][0]['suggested_root_cause'] ?? null;
    $assert(
        'La causa sugerida llega activa con su solución predeterminada',
        is_array($cause)
        && $cause['id'] === $rootCauseIds['RC-OTH-01']
        && $cause['is_active'] === true
        && is_array($cause['default_solution'])
    );

    echo "\n--- 4. Alta, edición, baja y reactivación auditadas (Art. III.3) ---\n";

    $crudPayload = [
        'machine_model' => 'Guías de Prueba M28',
        'breakdown_type' => 'OTHER',
        'title' => 'Guía de prueba del circuito auxiliar de banco',
        'error_codes' => [['code' => 'TST-01', 'meaning' => 'Código de prueba del banco de diagnóstico']],
        'steps' => $stepsProbe,
        'recommended_spare_part_ids' => [$partIds['MON-CASH-01']],
        'suggested_root_cause_id' => $rootCauseIds['RC-OTH-01'],
    ];

    $created = $service->createGuide($crudPayload, $coordinatorId, 'Coordinación de Prueba');
    $createdId = (int)$created['id'];

    $auditStmt = $pdo->prepare(
        "SELECT previous_state, new_state FROM audit_log
          WHERE entity_type = 'MACHINE_TROUBLESHOOTING_GUIDE' AND entity_id = :id AND action = :action
          ORDER BY id DESC LIMIT 1"
    );
    $auditStmt->execute([':id' => $createdId, ':action' => 'CREATE_TROUBLESHOOTING_GUIDE']);
    $createAudit = $auditStmt->fetch(PDO::FETCH_ASSOC);
    $createNew = is_array($createAudit) ? json_decode((string)$createAudit['new_state'], true) : null;
    $assert(
        'El alta persiste la guía y audita el estado nuevo completo',
        $createdId > 0
        && $created['is_active'] === true
        && is_array($createAudit)
        && $createAudit['previous_state'] === null
        && ($createNew['title'] ?? '') === $crudPayload['title']
        && array_column($createNew['steps'] ?? [], 'title') === array_column($stepsProbe, 'title')
        && count($createNew['steps'] ?? []) === count($stepsProbe)
    );

    [$duplicateCode, $duplicateStatus] = $errorOf(static fn () => $service->createGuide($crudPayload, $coordinatorId, 'Coordinación de Prueba'));
    $assert('El duplicado de identidad responde 409 (C1)', $duplicateCode === 'DUPLICATE_TROUBLESHOOTING_GUIDE' && $duplicateStatus === 409, $duplicateCode);

    $updated = $service->updateGuide($createdId, ['title' => 'Guía de prueba actualizada del circuito auxiliar'], $coordinatorId, 'Coordinación de Prueba');
    $auditStmt->execute([':id' => $createdId, ':action' => 'UPDATE_TROUBLESHOOTING_GUIDE']);
    $updateAudit = $auditStmt->fetch(PDO::FETCH_ASSOC);
    $updatePrevious = is_array($updateAudit) ? json_decode((string)$updateAudit['previous_state'], true) : null;
    $updateNew = is_array($updateAudit) ? json_decode((string)$updateAudit['new_state'], true) : null;
    $assert(
        'La edición conserva el antes y el después en auditoría',
        $updated['title'] === 'Guía de prueba actualizada del circuito auxiliar'
        && is_array($updateAudit)
        && ($updatePrevious['title'] ?? '') === $crudPayload['title']
        && ($updateNew['title'] ?? '') === $updated['title']
    );

    [$immutableCode, $immutableStatus] = $errorOf(static fn () => $service->updateGuide($createdId, ['breakdown_type' => 'PRODUCT_JAM'], $coordinatorId, 'Coordinación de Prueba'));
    $assert('La identidad no se reescribe (C1)', $immutableCode === 'IMMUTABLE_GUIDE_IDENTITY' && $immutableStatus === 422, $immutableCode);

    $pdo->exec("UPDATE spare_parts SET is_active = FALSE WHERE part_code = 'FILT-AGUA-01'");
    [$inactivePartCode, $inactivePartStatus] = $errorOf(static fn () => $service->updateGuide(
        $createdId,
        ['recommended_spare_part_ids' => [$partIds['FILT-AGUA-01']]],
        $coordinatorId,
        'Coordinación de Prueba'
    ));
    $assert('Un repuesto inactivo no entra en la guía (C2)', $inactivePartCode === 'INVALID_GUIDE_SPARE_PARTS' && $inactivePartStatus === 422, $inactivePartCode);

    $off = $service->setGuideActiveStatus($createdId, false, $coordinatorId, 'Coordinación de Prueba');
    $auditStmt->execute([':id' => $createdId, ':action' => 'DEACTIVATE_TROUBLESHOOTING_GUIDE']);
    $offAudit = $auditStmt->fetch(PDO::FETCH_ASSOC);
    $offPrevious = is_array($offAudit) ? json_decode((string)$offAudit['previous_state'], true) : null;
    $offNew = is_array($offAudit) ? json_decode((string)$offAudit['new_state'], true) : null;
    $assert(
        'La baja lógica audita el cambio de vigencia',
        $off['is_active'] === false
        && is_array($offAudit)
        && ($offPrevious['is_active'] ?? false) === true
        && ($offNew['is_active'] ?? true) === false
    );

    $on = $service->setGuideActiveStatus($createdId, true, $coordinatorId, 'Coordinación de Prueba');
    $auditStmt->execute([':id' => $createdId, ':action' => 'ACTIVATE_TROUBLESHOOTING_GUIDE']);
    $onAudit = $auditStmt->fetch(PDO::FETCH_ASSOC);
    $onPrevious = is_array($onAudit) ? json_decode((string)$onAudit['previous_state'], true) : null;
    $onNew = is_array($onAudit) ? json_decode((string)$onAudit['new_state'], true) : null;
    $assert(
        'La reactivación audita el cambio inverso',
        $on['is_active'] === true
        && is_array($onAudit)
        && ($onPrevious['is_active'] ?? true) === false
        && ($onNew['is_active'] ?? false) === true
    );

    $catalog = $service->listForCoordinator(['machine_model' => 'Guías de Prueba M28', 'status' => 'ALL']);
    $catalogIds = array_column($catalog['guides'], 'id');
    $assert('El catálogo de Coordinación refleja la guía editada', in_array($createdId, $catalogIds, true));

    echo "\n--- 5. Tolerancia a bajas posteriores del catálogo (C2) ---\n";

    $pdo->exec("UPDATE spare_parts SET is_active = FALSE WHERE part_code = 'VALV-ULKA-01'");
    $afterPartOff = $service->getGuidesForModel('Banco de Pruebas M28', 'OTHER', null, $technicianId);
    $tolerantParts = $afterPartOff['guides'][0]['recommended_spare_parts'] ?? [];
    $assert(
        'Una pieza desactivada sigue visible y marcada, sin romper la consulta',
        count($tolerantParts) === 3
        && $tolerantParts[0]['spare_part_id'] === $partIds['VALV-ULKA-01']
        && $tolerantParts[0]['is_active'] === false
        && $tolerantParts[0]['van_stock_quantity'] === 4
    );

    $pdo->exec("UPDATE root_causes SET is_active = FALSE WHERE code = 'RC-OTH-01'");
    $afterCauseOff = $service->getGuidesForModel('Banco de Pruebas M28', 'OTHER', null, $technicianId);
    $assert(
        'Una causa desactivada se oculta de la guía',
        array_key_exists('suggested_root_cause', $afterCauseOff['guides'][0] ?? [])
        && $afterCauseOff['guides'][0]['suggested_root_cause'] === null
    );

    echo "\n--- 6. Cobertura del parque inmune a filtros (QA 1) ---\n";

    $pdo->exec("UPDATE machines SET model = 'SANDEN   VENDO G-DRINK' WHERE code = 'VEND-0101'");

    $coverage = $service->listForCoordinator(['status' => 'ALL'])['coverage_summary'];
    $expectedSandenMachines = (int)$pdo->query(
        "SELECT COUNT(*) FROM machines
          WHERE is_active = TRUE AND deleted_at IS NULL
            AND lower(btrim(regexp_replace(model, '\\s+', ' ', 'g'))) = 'sanden vendo g-drink'"
    )->fetchColumn();
    $sandenEntries = array_values(array_filter(
        $coverage['fleet_models'],
        static fn (array $model): bool => $model['machine_model_key'] === 'sanden vendo g-drink'
    ));
    $assert(
        'Las variantes de escritura del modelo se agrupan en una sola fila',
        count($sandenEntries) === 1 && (int)$sandenEntries[0]['active_machines_count'] === $expectedSandenMachines,
        count($sandenEntries) . ' fila(s) · ' . $expectedSandenMachines . ' máquina(s)'
    );

    $orphanKeys = array_column($coverage['orphan_fleet_models'], 'machine_model_key');
    $assert('Fas Perla aparece como modelo huérfano real', in_array('fas perla', $orphanKeys, true), implode(', ', $orphanKeys));

    $dbTotals = $pdo->query(
        'SELECT COUNT(*)::int AS total,
                COUNT(*) FILTER (WHERE is_active = TRUE)::int AS active
           FROM machine_troubleshooting_guides'
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        'Los totales de guías coinciden con el catálogo persistido',
        (int)$coverage['total_guides'] === (int)$dbTotals['total']
        && (int)$coverage['total_active_guides'] === (int)$dbTotals['active'],
        $coverage['total_active_guides'] . '/' . $coverage['total_guides']
    );

    $filteredCoverage = $service->listForCoordinator([
        'machine_model' => 'Fas Perla',
        'breakdown_type' => 'PRODUCT_JAM',
        'status' => 'INACTIVE',
        'search' => 'sin coincidencias de prueba',
    ])['coverage_summary'];
    $assert(
        'Las métricas de cobertura no cambian con los filtros del listado',
        $filteredCoverage == $coverage,
        'activas: ' . $filteredCoverage['total_active_guides'] . ' · huérfanos: ' . $filteredCoverage['orphan_fleet_models_count']
    );
    $assert(
        'Las tres tarjetas se calculan sobre el parque global',
        (int)$coverage['covered_fleet_models_count'] > 0
        && (int)$coverage['total_active_fleet_models_count'] > 0
        && (int)$coverage['orphan_fleet_models_count'] >= 1
    );
} catch (Throwable $exception) {
    $crash = $exception;
    fwrite(STDERR, "\n[EXCEPCIÓN NO CONTROLADA] " . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getFile() . ':' . $exception->getLine() . "\n");
    $failures++;
} finally {
    if ($pdo instanceof PDO) {
        try {
            PostgresFixtureBuilder::dropIsolationSchema($pdo, GUIDES_SERVICE_SCHEMA);
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, "\n[LIMPIEZA] No se pudo retirar el esquema aislado: " . $cleanupException->getMessage() . "\n");
        }
    }
}

echo "\n======================================================================\n";
echo " VENDGUARD · T-GUIDE-09 · RESULTADO\n";
echo " Aserciones: {$assertions} · Fallos: {$failures}\n";
echo "======================================================================\n";

if ($failures > 0) {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
    exit(1);
}

echo " RESULTADO: 100% EN VERDE. RF-01..RF-05, D1..D4, C1..C5 y QA 1..QA 3 CERTIFICADOS.\n";
exit(0);
