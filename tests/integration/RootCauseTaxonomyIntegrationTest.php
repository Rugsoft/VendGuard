<?php

declare(strict_types=1);

/**
 * RootCauseTaxonomyIntegrationTest · T-TAXONOMY-11
 *
 * Certifica de punta a punta la taxonomía de causas raíz y soluciones
 * estandarizadas (Módulo 25) contra PostgreSQL, con el router real, los
 * middleware RBAC, los controladores, los servicios transaccionales y los
 * repositorios PDO sobre un esquema aislado.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Catálogo maestro (`RF-01`, `QA 1`, `C2` y `C4`).**
 *      - Alta atómica de causa + solución predeterminada inicial (`201`).
 *      - Reversión completa si la solución inicial es inválida.
 *      - Inmutabilidad de `code`, `category` y `root_cause_id` (`422`).
 *      - Conmutación atómica de `is_default`.
 *      - Bloqueo al desactivar la última solución activa o la predeterminada
 *        vigente sin relevarla antes (`409`).
 *      - Bloqueo de reactivación de una causa huérfana (`409`).
 *      - Auditoría antes/después bajo `ROOT_CAUSE_TAXONOMY`.
 *
 *   2. **Resolución clasificada (`RF-02`, `D2`, `QA 3`, Art. V.1).**
 *      - Cierre técnico y administrativo con `root_cause_id` y
 *        `standard_solution_id`.
 *      - Rechazo `422` si falta taxonomía, si la solución no pertenece a la
 *        causa o si los textos libres no alcanzan 20 caracteres reales.
 *      - Congelación de los cinco snapshots en `incidents`.
 *      - Un renombrado posterior no altera el snapshot del ticket cerrado,
 *        aunque el Pareto lea el nombre vigente del catálogo.
 *
 *   3. **Reapertura en garantía (`C1`).** Una reapertura dentro de las 48 h
 *      que se vuelve a cerrar con otra causa actualiza la clasificación vigente
 *      para el Pareto y preserva el episodio anterior en `incident_history`.
 *
 *   4. **Offline PWA (`EARS 03.2` y `EARS 03.3`, `C5`).**
 *      - `root_cause_taxonomy` viaja en `GET /api/technician/offline/bootstrap`.
 *      - Una resolución diferida acepta una causa desactivada en el ínterin.
 *      - El volcado de conflicto conserva la clasificación declarada in situ.
 *
 *   5. **Pareto y CSV (`RF-05`, `D5`, `QA 2` y `QA 4`).** Curva acumulada con
 *      cierre exacto en `100.00`, Zona Vital 80/20 inclusiva, filtro por
 *      fabricante y exportación `UTF-8 BOM` en dos secciones (nombre vigente y
 *      nombre congelado).
 *
 *   6. **Privacidad y RBAC (`RF-04`, `Art. V.4`, `QA 5`).** `403 FORBIDDEN`
 *      para el técnico en el CRUD/Pareto/CSV; la sesión de sede no atraviesa la
 *      puerta interna; los controles de defensa en profundidad rechazan el rol
 *      `LOCATION_RESPONSIBLE`; y Portal de Sede, QR y Albarán A4 no serializan
 *      ninguna clave ni valor de taxonomía.
 *
 * CATEGORÍA: `new_engine`
 *   Exige PostgreSQL local. El escenario vive en un esquema aislado sobre la
 *   conexión de la aplicación, de modo que el router y los repositorios que éste
 *   construye trabajan sobre las mismas tablas efímeras. Sin motor, sale con
 *   código 2 en lugar de omitirse en silencio.
 *
 * LIMPIEZA: el esquema se retira con `CASCADE` al terminar, incluso si falla una
 * aserción.
 *
 * Uso:
 *   php tests/integration/RootCauseTaxonomyIntegrationTest.php
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
use VendGuard\Presentation\Controller\CoordinatorRootCauseController;
use VendGuard\Presentation\Controller\TechnicianController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

/** Marca de categoría: la batería global la lee para clasificar la suite. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado propio: no comparte escenario con las demás suites. */
const TAXONOMY_API_SCHEMA = 't_taxonomy25_api';

$projectRoot = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;
$crash = null;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? ' · ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · T-TAXONOMY-11 · Integración HTTP de la taxonomía (Módulo 25)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor no responde: " . $exception->getMessage() . "\n");
    exit(2);
}

$json = static fn (Response $response): array => $response->getDecodedBody() ?? [];
$data = static fn (Response $response): array => $json($response)['data'] ?? [];
$errorCode = static fn (Response $response): string => (string)($json($response)['error']['code'] ?? '');

/**
 * ¿Algún nodo del árbol JSON publica una clave interna de taxonomía?
 */
$hasTaxonomyKey = static function (mixed $node) use (&$hasTaxonomyKey): bool {
    if (!is_array($node)) {
        return false;
    }

    foreach ($node as $key => $value) {
        if (is_string($key) && preg_match('/^(root_cause|standard_solution)/', $key) === 1) {
            return true;
        }

        if ($hasTaxonomyKey($value)) {
            return true;
        }
    }

    return false;
};

/**
 * Búsqueda lineal de un código en el catálogo administrativo.
 *
 * @param list<array<string, mixed>> $items
 * @return array<string, mixed>|null
 */
$findByCode = static function (array $items, string $code): ?array {
    foreach ($items as $item) {
        if (($item['code'] ?? null) === $code) {
            return $item;
        }
    }

    return null;
};

try {
    echo "\n--- Escenario (DDL base + migraciones en un esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, TAXONOMY_API_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);

    $assert(
        'El esquema aislado se aplica entero, incluida la migración 021',
        $schemaReport['statements'] > 100
            && in_array('021_root_cause_taxonomy.sql', $schemaReport['migrations'], true),
        sprintf('%d sentencias · migraciones: %s', $schemaReport['statements'], implode(', ', $schemaReport['migrations']))
    );

    // ─────────────────────────────────────────────────────────────────────
    // Fixtures base
    // ─────────────────────────────────────────────────────────────────────

    $createUser = static function (string $suffix, string $role) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, email_key, password_hash, role, operator_code, is_active)
             VALUES (:name, :email, :email_key, :hash, :role, :operator, TRUE)
             RETURNING id'
        );
        $stmt->execute([
            ':name' => $role === 'COORDINATOR' ? 'Coordinación Taxonomía' : 'Técnico Taxonomía',
            ':email' => 'tax25.' . $suffix . '@vendguard.internal',
            ':email_key' => 'tax25.' . $suffix . '@vendguard.internal',
            ':hash' => password_hash('tax25-' . $suffix, PASSWORD_BCRYPT),
            ':role' => $role,
            ':operator' => 'T25-' . mb_strtoupper($suffix),
        ]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $createUser('coord', 'COORDINATOR');
    $technicianId = $createUser('tech', 'TECHNICIAN');

    $stmt = $pdo->prepare(
        'INSERT INTO locations (site_code, site_code_key, name, address, latitude, longitude, postal_code, contact_email)
         VALUES (:site, :site_key, :name, :address, 41.3850640, 2.1734035, :postal, :email)
         RETURNING id'
    );
    $stmt->execute([
        ':site' => 'SEDE-TAX-25',
        ':site_key' => 'sede-tax-25',
        ':name' => 'Hospital de Certificación Taxonomía',
        ':address' => 'Carrer de la Marina 100, Barcelona',
        ':postal' => '08018',
        ':email' => 'recepcion.tax25@hospital.cat',
    ]);
    $locationId = (int)$stmt->fetchColumn();

    $createMachine = static function (string $code, string $model, string $type, string $wing) use ($pdo, $locationId): int {
        $stmt = $pdo->prepare(
            'INSERT INTO machines (location_id, code, model, machine_type, floor_wing, sanitary_status)
             VALUES (:location_id, :code, :model, :type, :wing, \'OK\')
             RETURNING id'
        );
        $stmt->execute([
            ':location_id' => $locationId,
            ':code' => $code,
            ':model' => $model,
            ':type' => $type,
            ':wing' => $wing,
        ]);

        return (int)$stmt->fetchColumn();
    };

    // `uq_machine_active_ticket` admite un solo expediente no cerrado por
    // máquina: cada incidencia viva del escenario recibe su propia máquina.
    $machineNectaOne = $createMachine('TAX25-M01', 'Necta Samba Combo', 'SNACKS', 'Planta 1 · Recepción');
    $machineNectaTwo = $createMachine('TAX25-M02', 'Necta Samba Combo', 'SNACKS', 'Planta 1 · Pasillo');
    $machineNectaThree = $createMachine('TAX25-M03', 'Necta Samba Combo', 'SNACKS', 'Planta 2 · Espera');
    $machineNectaCold = $createMachine('TAX25-M04', 'Necta Astro', 'COLD_DRINKS', 'Sótano · Urgencias');
    $machineFasFirst = $createMachine('TAX25-M05', 'Fas Fast 900', 'SNACKS', 'Planta 2 · Cafetería');
    $machineFasSecond = $createMachine('TAX25-M06', 'Fas Perla', 'SNACKS', 'Planta 3 · Oficinas');
    $machineOther = $createMachine('TAX25-M07', 'Jofemar Vision', 'SNACKS', 'Planta 3 · Consultas');
    $machineInactive = $createMachine('TAX25-M08', 'Necta Astro', 'COLD_DRINKS', 'Planta 4 · Laboratorio');
    $machinePrivacy = $createMachine('TAX25-M09', 'Necta Samba Combo', 'SNACKS', 'Planta 1 · Dirección');

    $diagnosis = 'Diagnóstico técnico certificado: termostato descalibrado en el equipo.';
    $action = 'Sustitución de sonda, calibración y verificación funcional completa.';

    $createIncident = static function (
        string $ticket,
        int $machineId,
        string $machineType,
        int $technician,
        string $status = 'IN_PROGRESS',
        ?string $diagnosisText = null,
        ?string $actionText = null
    ) use ($pdo, $locationId): int {
        $resolved = in_array($status, ['RESOLVED', 'CLOSED'], true);
        $resolutionSql = $resolved ? 'CURRENT_TIMESTAMP - INTERVAL \'30 minutes\'' : 'NULL';
        $closedSql = $status === 'CLOSED' ? 'CURRENT_TIMESTAMP - INTERVAL \'30 minutes\'' : 'NULL';

        $stmt = $pdo->prepare(
            'INSERT INTO incidents (
                ticket_code, machine_id, machine_type_snapshot, location_id,
                assigned_technician_id, category, description, urgency, status,
                cycle_started_at, assigned_at, started_at,
                resolution_diagnosis, resolution_action, resolved_at, closed_at
             ) VALUES (
                :ticket, :machine_id, :machine_type, :location_id,
                :technician, \'PAYMENT_SYSTEM\', :description, \'HIGH\', :status,
                CURRENT_TIMESTAMP - INTERVAL \'2 hours\',
                CURRENT_TIMESTAMP - INTERVAL \'90 minutes\',
                CURRENT_TIMESTAMP - INTERVAL \'80 minutes\',
                :diagnosis, :action, ' . $resolutionSql . ', ' . $closedSql . '
             ) RETURNING id'
        );
        $stmt->execute([
            ':ticket' => $ticket,
            ':machine_id' => $machineId,
            ':machine_type' => $machineType,
            ':location_id' => $locationId,
            ':technician' => $technician,
            ':description' => 'Avería de certificación de la taxonomía M25.',
            ':status' => $status,
            ':diagnosis' => $resolved ? ($diagnosisText ?? 'Resolución histórica certificada sin clasificar.') : null,
            ':action' => $resolved ? ($actionText ?? 'Intervención histórica certificada sin clasificación.') : null,
        ]);

        return (int)$stmt->fetchColumn();
    };

    $incidentFirst = $createIncident('INC-TAX-0001', $machineNectaOne, 'SNACKS', $technicianId);
    $incidentCoordinator = $createIncident('INC-TAX-0002', $machineNectaTwo, 'SNACKS', $technicianId);
    $incidentReopen = $createIncident('INC-TAX-0003', $machineFasFirst, 'SNACKS', $technicianId);
    $incidentCauseC = $createIncident('INC-TAX-0004', $machineNectaCold, 'COLD_DRINKS', $technicianId);
    $incidentThirdA = $createIncident('INC-TAX-0005', $machineNectaThree, 'SNACKS', $technicianId);
    $incidentUnclassified = $createIncident(
        'INC-TAX-0006',
        $machineOther,
        'SNACKS',
        $technicianId,
        'CLOSED',
        $diagnosis,
        $action
    );
    $incidentOffline = $createIncident('INC-TAX-0008', $machineFasSecond, 'SNACKS', $technicianId);
    $incidentInactiveOnline = $createIncident('INC-TAX-0011', $machineInactive, 'COLD_DRINKS', $technicianId);
    $incidentRejected = $createIncident('INC-TAX-0012', $machinePrivacy, 'SNACKS', $technicianId);

    $userRepo = new PdoUserRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);

    $coordinatorToken = $authService->generateInternalToken($userRepo->findById($coordinatorId));
    $technicianToken = $authService->generateInternalToken($userRepo->findById($technicianId));
    $siteToken = $authService->generateSiteToken($locationRepo->findById($locationId));

    $router = AppRouter::create();

    /**
     * Petición al router real con las cabeceras y el cuerpo indicados.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array<string, string> $extraHeaders
     */
    $call = static function (
        string $method,
        string $path,
        array $body = [],
        ?string $token = null,
        array $query = [],
        array $extraHeaders = []
    ) use ($router): Response {
        $headers = ['Content-Type' => 'application/json'];
        foreach ($extraHeaders as $name => $value) {
            $headers[$name] = $value;
        }
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $router->dispatch(new Request($method, $path, $query, $body, $headers));
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. Catálogo maestro: alta atómica, inmutabilidad y guardas C2/C4
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 1. Catálogo maestro (RF-01, C2, C4, QA 1) ---\n";

    $aNameOld = 'Moneda atascada en validador de certificación';
    $a1NameOld = 'Limpieza de canal de admisión inicial';

    $createA = $call('POST', '/api/coordinator/root-causes', [
        'code' => 'RC-TAX-A',
        'category' => 'PAYMENT_SYSTEM',
        'name' => $aNameOld,
        'description' => 'Causa raíz de certificación del canal de pago.',
        'initial_solution' => [
            'code' => 'SOL-TAX-A1',
            'name' => $a1NameOld,
        ],
    ], $coordinatorToken);
    $aBody = $data($createA);
    $aId = (int)($aBody['id'] ?? 0);
    $aSolutions = $aBody['solutions'] ?? [];
    $a1Id = (int)($aSolutions[0]['id'] ?? 0);

    $assert(
        '1.1 El alta atómica responde 201 con causa y solución predeterminada inicial',
        $createA->getStatusCode() === 201
            && $aId > 0
            && $a1Id > 0
            && ($aSolutions[0]['code'] ?? '') === 'SOL-TAX-A1'
            && ($aSolutions[0]['is_default'] ?? false) === true
            && ($aBody['default_solution_id'] ?? null) === $a1Id,
        json_encode($aBody)
    );

    $auditCreate = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'ROOT_CAUSE_TAXONOMY'
            AND entity_id = :id
            AND action = 'CREATE_ROOT_CAUSE'
            AND new_state IS NOT NULL"
    );
    $auditCreate->execute([':id' => $aId]);
    $assert('1.2 El alta deja auditoría CREATE_ROOT_CAUSE con estado resultante', (int)$auditCreate->fetchColumn() === 1);

    $missingInitial = $call('POST', '/api/coordinator/root-causes', [
        'code' => 'RC-TAX-NO-INITIAL',
        'category' => 'OTHER',
        'name' => 'Causa sin solución inicial de certificación',
    ], $coordinatorToken);
    $assert(
        '1.3 Sin solución inicial responde 422 INITIAL_DEFAULT_SOLUTION_REQUIRED',
        $missingInitial->getStatusCode() === 422
            && $errorCode($missingInitial) === 'INITIAL_DEFAULT_SOLUTION_REQUIRED'
    );

    $atomic = $call('POST', '/api/coordinator/root-causes', [
        'code' => 'RC-TAX-ATOMIC',
        'category' => 'OTHER',
        'name' => 'Causa que no debe sobrevivir al fallo',
        'initial_solution' => [
            'code' => 'X',
            'name' => 'Solución inicial inválida',
        ],
    ], $coordinatorToken);
    $atomicCount = $pdo->prepare('SELECT COUNT(*) FROM root_causes WHERE code = :code');
    $atomicCount->execute([':code' => 'RC-TAX-ATOMIC']);
    $assert(
        '1.4 Una solución inicial inválida revierte la causa completa (sin huérfanas)',
        $atomic->getStatusCode() === 422 && (int)$atomicCount->fetchColumn() === 0,
        'HTTP ' . $atomic->getStatusCode() . ' · ' . json_encode($data($atomic))
    );

    $duplicate = $call('POST', '/api/coordinator/root-causes', [
        'code' => 'RC-TAX-A',
        'category' => 'OTHER',
        'name' => 'Causa duplicada de certificación',
        'initial_solution' => [
            'code' => 'SOL-TAX-DUP',
            'name' => 'Solución duplicada de certificación',
        ],
    ], $coordinatorToken);
    $assert(
        '1.5 Un código de causa duplicado responde 409 DUPLICATE_TAXONOMY_CODE',
        $duplicate->getStatusCode() === 409 && $errorCode($duplicate) === 'DUPLICATE_TAXONOMY_CODE'
    );

    $immutableCode = $call('PUT', '/api/coordinator/root-causes/' . $aId, [
        'code' => 'RC-TAX-A-MUTADO',
        'name' => $aNameOld,
    ], $coordinatorToken);
    $assert(
        '1.6 Alterar el código de una causa responde 422 IMMUTABLE_TAXONOMY_FIELD',
        $immutableCode->getStatusCode() === 422 && $errorCode($immutableCode) === 'IMMUTABLE_TAXONOMY_FIELD'
    );

    $immutableCategory = $call('PUT', '/api/coordinator/root-causes/' . $aId, [
        'category' => 'OTHER',
        'name' => $aNameOld,
    ], $coordinatorToken);
    $assert(
        '1.7 Alterar la categoría de una causa responde 422 IMMUTABLE_TAXONOMY_FIELD',
        $immutableCategory->getStatusCode() === 422 && $errorCode($immutableCategory) === 'IMMUTABLE_TAXONOMY_FIELD'
    );

    $immutableSolutionCode = $call(
        'PUT',
        '/api/coordinator/root-causes/' . $aId . '/solutions/' . $a1Id,
        ['code' => 'SOL-TAX-A1-MUTADA', 'name' => $a1NameOld],
        $coordinatorToken
    );
    $assert(
        '1.8 Alterar el código de una solución responde 422 IMMUTABLE_TAXONOMY_FIELD',
        $immutableSolutionCode->getStatusCode() === 422
            && $errorCode($immutableSolutionCode) === 'IMMUTABLE_TAXONOMY_FIELD'
    );

    $immutableSolutionParent = $call(
        'PUT',
        '/api/coordinator/root-causes/' . $aId . '/solutions/' . $a1Id,
        ['root_cause_id' => 999999, 'name' => $a1NameOld],
        $coordinatorToken
    );
    $assert(
        '1.9 Reasignar una solución a otra causa responde 422 IMMUTABLE_TAXONOMY_FIELD',
        $immutableSolutionParent->getStatusCode() === 422
            && $errorCode($immutableSolutionParent) === 'IMMUTABLE_TAXONOMY_FIELD'
    );

    $bName = 'Sonda térmica descalibrada en cámara de certificación';
    $createB = $call('POST', '/api/coordinator/root-causes', [
        'code' => 'RC-TAX-B',
        'category' => 'TEMPERATURE_COLD',
        'name' => $bName,
        'description' => 'Causa de frío usada por la reapertura y la sync offline.',
        'initial_solution' => [
            'code' => 'SOL-TAX-B1',
            'name' => 'Sustitución de sonda térmica certificada',
        ],
    ], $coordinatorToken);
    $bBody = $data($createB);
    $bId = (int)($bBody['id'] ?? 0);
    $b1Id = (int)($bBody['solutions'][0]['id'] ?? 0);
    $assert('1.10 La causa B se crea con su solución predeterminada', $createB->getStatusCode() === 201 && $bId > 0 && $b1Id > 0);

    $cName = 'Solución de pago con conmutación de predeterminada';
    $createC = $call('POST', '/api/coordinator/root-causes', [
        'code' => 'RC-TAX-C',
        'category' => 'OTHER',
        'name' => $cName,
        'initial_solution' => [
            'code' => 'SOL-TAX-C1',
            'name' => 'Solución inicial de certificación C',
        ],
    ], $coordinatorToken);
    $cBody = $data($createC);
    $cId = (int)($cBody['id'] ?? 0);
    $c1Id = (int)($cBody['solutions'][0]['id'] ?? 0);
    $assert('1.11 La causa C se crea con su solución inicial', $createC->getStatusCode() === 201 && $cId > 0 && $c1Id > 0);

    $dName = 'Causa de guardas de vigencia de certificación';
    $createD = $call('POST', '/api/coordinator/root-causes', [
        'code' => 'RC-TAX-D',
        'category' => 'OTHER',
        'name' => $dName,
        'initial_solution' => [
            'code' => 'SOL-TAX-D1',
            'name' => 'Solución D1 de certificación',
        ],
    ], $coordinatorToken);
    $dBody = $data($createD);
    $dId = (int)($dBody['id'] ?? 0);
    $d1Id = (int)($dBody['solutions'][0]['id'] ?? 0);
    $assert('1.12 La causa D se crea con su solución inicial', $createD->getStatusCode() === 201 && $dId > 0 && $d1Id > 0);

    $createC2 = $call('POST', '/api/coordinator/root-causes/' . $cId . '/solutions', [
        'code' => 'SOL-TAX-C2',
        'name' => 'Solución C2 que asume la predeterminación',
        'is_default' => true,
    ], $coordinatorToken);
    $c2Id = (int)($data($createC2)['id'] ?? 0);
    $assert(
        '1.13 Añadir una solución con is_default conmuta la predeterminada (D1)',
        $createC2->getStatusCode() === 201 && $c2Id > 0
    );

    $deactivateDefaultC2 = $call(
        'PUT',
        '/api/coordinator/root-causes/' . $cId . '/solutions/' . $c2Id,
        ['is_active' => false],
        $coordinatorToken
    );
    $assert(
        '1.14 Desactivar la predeterminada vigente responde 409 DEFAULT_SOLUTION_CANNOT_BE_DEACTIVATED',
        $deactivateDefaultC2->getStatusCode() === 409
            && $errorCode($deactivateDefaultC2) === 'DEFAULT_SOLUTION_CANNOT_BE_DEACTIVATED'
    );

    $createD2 = $call('POST', '/api/coordinator/root-causes/' . $dId . '/solutions', [
        'code' => 'SOL-TAX-D2',
        'name' => 'Solución D2 que asume la predeterminación',
        'is_default' => true,
    ], $coordinatorToken);
    $d2Id = (int)($data($createD2)['id'] ?? 0);

    $deactivateD1 = $call(
        'PUT',
        '/api/coordinator/root-causes/' . $dId . '/solutions/' . $d1Id,
        ['is_active' => false],
        $coordinatorToken
    );
    $assert(
        '1.15 Una solución no predeterminada puede darse de baja si queda otra activa',
        $deactivateD1->getStatusCode() === 200 && $d2Id > 0
    );

    $deactivateLastD2 = $call(
        'PUT',
        '/api/coordinator/root-causes/' . $dId . '/solutions/' . $d2Id,
        ['is_active' => false],
        $coordinatorToken
    );
    $assert(
        '1.16 Desactivar la última solución activa responde 409 LAST_ACTIVE_SOLUTION_CANNOT_BE_DEACTIVATED',
        $deactivateLastD2->getStatusCode() === 409
            && $errorCode($deactivateLastD2) === 'LAST_ACTIVE_SOLUTION_CANNOT_BE_DEACTIVATED'
    );

    $deactivateD = $call('PUT', '/api/coordinator/root-causes/' . $dId, [
        'is_active' => false,
    ], $coordinatorToken);
    $assert('1.17 La causa D puede desactivarse conservando su solución', $deactivateD->getStatusCode() === 200);

    $pdo->prepare(
        'UPDATE standard_solutions
            SET is_active = FALSE, is_default = FALSE
          WHERE id = :id'
    )->execute([':id' => $d2Id]);

    $reactivateOrphan = $call('PUT', '/api/coordinator/root-causes/' . $dId, [
        'is_active' => true,
    ], $coordinatorToken);
    $assert(
        '1.18 Reactivar una causa huérfana responde 409 CANNOT_REACTIVATE_ORPHAN_ROOT_CAUSE',
        $reactivateOrphan->getStatusCode() === 409
            && $errorCode($reactivateOrphan) === 'CANNOT_REACTIVATE_ORPHAN_ROOT_CAUSE'
    );

    $deleteBlocked = null;
    try {
        $pdo->exec('DELETE FROM root_causes WHERE id = ' . $aId);
    } catch (PDOException $exception) {
        $deleteBlocked = (string)$exception->getCode();
    }
    $assert(
        '1.19 El borrado físico del catálogo queda bloqueado por el disparador constitucional',
        $deleteBlocked === '45000',
        $deleteBlocked === null ? 'la fila se borró' : 'SQLSTATE ' . $deleteBlocked
    );

    // ─────────────────────────────────────────────────────────────────────
    // 2. Primer cierre técnico y congelación de snapshots
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 2. Cierre técnico con clasificación y snapshots (QA 3, C4) ---\n";

    $firstClose = $call('POST', '/api/technician/incidents/' . $incidentFirst . '/resolve', [
        'resolution_diagnosis' => $diagnosis,
        'resolution_action' => $action,
        'replaced_parts_declared' => false,
        'root_cause_id' => $aId,
        'standard_solution_id' => $a1Id,
    ], $technicianToken);
    $firstCloseData = $data($firstClose);

    $firstSnapshot = $pdo->prepare(
        'SELECT status, root_cause_id, standard_solution_id,
                root_cause_code_snapshot, root_cause_category_snapshot, root_cause_name_snapshot,
                standard_solution_code_snapshot, standard_solution_name_snapshot
           FROM incidents WHERE id = :id'
    );
    $firstSnapshot->execute([':id' => $incidentFirst]);
    $frozenFirst = $firstSnapshot->fetch(PDO::FETCH_ASSOC);

    $assert(
        '2.1 El técnico cierra con causa y solución congelando los cinco snapshots',
        $firstClose->getStatusCode() === 200
            && ($firstCloseData['status'] ?? '') === 'RESOLVED'
            && ($firstCloseData['root_cause']['code'] ?? '') === 'RC-TAX-A'
            && ($frozenFirst['status'] ?? '') === 'RESOLVED'
            && (int)($frozenFirst['root_cause_id'] ?? 0) === $aId
            && (int)($frozenFirst['standard_solution_id'] ?? 0) === $a1Id
            && ($frozenFirst['root_cause_code_snapshot'] ?? '') === 'RC-TAX-A'
            && ($frozenFirst['root_cause_category_snapshot'] ?? '') === 'PAYMENT_SYSTEM'
            && ($frozenFirst['root_cause_name_snapshot'] ?? '') === $aNameOld
            && ($frozenFirst['standard_solution_code_snapshot'] ?? '') === 'SOL-TAX-A1'
            && ($frozenFirst['standard_solution_name_snapshot'] ?? '') === $a1NameOld,
        json_encode(['response' => $firstCloseData, 'row' => $frozenFirst])
    );

    $assert(
        '2.2 La resolución conserva los textos libres sin añadidos de taxonomía',
        ($frozenFirst['status'] ?? '') === 'RESOLVED'
            && (string)$pdo->query('SELECT resolution_diagnosis FROM incidents WHERE id = ' . $incidentFirst)->fetchColumn() === $diagnosis
            && (string)$pdo->query('SELECT resolution_action FROM incidents WHERE id = ' . $incidentFirst)->fetchColumn() === $action
    );

    // Renombrado posterior: el snapshot no se reescribe y el Pareto usa el vigente.
    $aNameCurrent = 'Moneda doblada detectada en validador de certificación';
    $a1NameCurrent = 'Limpieza óptica del canal de admisión renovada';

    $renameA = $call('PUT', '/api/coordinator/root-causes/' . $aId, [
        'name' => $aNameCurrent,
        'description' => 'Nombre vigente tras el renombrado de certificación.',
    ], $coordinatorToken);
    $renameA1 = $call('PUT', '/api/coordinator/root-causes/' . $aId . '/solutions/' . $a1Id, [
        'name' => $a1NameCurrent,
    ], $coordinatorToken);
    $assert(
        '2.3 El renombrado de causa y solución se persiste (C4: code y parent intactos)',
        $renameA->getStatusCode() === 200
            && $renameA1->getStatusCode() === 200
            && ($data($renameA)['code'] ?? '') === 'RC-TAX-A'
            && ($data($renameA1)['code'] ?? '') === 'SOL-TAX-A1'
    );

    $firstSnapshot->execute([':id' => $incidentFirst]);
    $snapshotAfterRename = $firstSnapshot->fetch(PDO::FETCH_ASSOC);
    $assert(
        '2.4 El renombrado no altera el nombre congelado del ticket cerrado',
        ($snapshotAfterRename['root_cause_name_snapshot'] ?? '') === $aNameOld
            && ($snapshotAfterRename['standard_solution_name_snapshot'] ?? '') === $a1NameOld
    );

    $auditUpdate = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'ROOT_CAUSE_TAXONOMY'
            AND entity_id = :id
            AND action = 'UPDATE_ROOT_CAUSE'
            AND previous_state IS NOT NULL
            AND new_state IS NOT NULL"
    );
    $auditUpdate->execute([':id' => $aId]);
    $assert('2.5 La edición deja auditoría antes/después del catálogo', (int)$auditUpdate->fetchColumn() >= 1);

    // A2 nace después del renombrado y asume la predeterminación.
    $createA2 = $call('POST', '/api/coordinator/root-causes/' . $aId . '/solutions', [
        'code' => 'SOL-TAX-A2',
        'name' => 'Sustitución de validador con firmware certificado',
        'is_default' => true,
    ], $coordinatorToken);
    $a2Id = (int)($data($createA2)['id'] ?? 0);
    $assert(
        '2.6 Una segunda solución puede asumir la predeterminación de A (D1)',
        $createA2->getStatusCode() === 201 && $a2Id > 0
    );

    $catalog = $call('GET', '/api/coordinator/root-causes', [], $coordinatorToken);
    $catalogItems = $data($catalog)['items'] ?? [];
    $catalogA = $findByCode($catalogItems, 'RC-TAX-A');
    $assert(
        '2.7 El catálogo expone la predeterminada conmutada y las dos soluciones activas',
        $catalog->getStatusCode() === 200
            && is_array($catalogA)
            && (int)($catalogA['default_solution_id'] ?? 0) === $a2Id
            && (int)($catalogA['active_solutions_count'] ?? 0) === 2
            && (int)($catalogA['total_solutions_count'] ?? 0) === 2
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Resolución administrativa, rechazos independientes y reapertura C1
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 3. Resolución administrativa, textos y reapertura en garantía (C1) ---\n";

    $coordinatorMissingTaxonomy = $call('POST', '/api/coordinator/incidents/' . $incidentRejected . '/resolve', [
        'resolution_diagnosis' => $diagnosis,
        'resolution_action' => $action,
        'replaced_parts_declared' => false,
    ], $coordinatorToken);
    $assert(
        '3.1 Coordinación sin taxonomía responde 422 MISSING_ROOT_CAUSE_TAXONOMY',
        $coordinatorMissingTaxonomy->getStatusCode() === 422
            && $errorCode($coordinatorMissingTaxonomy) === 'MISSING_ROOT_CAUSE_TAXONOMY'
    );

    $technicianMissingSolution = $call('POST', '/api/technician/incidents/' . $incidentRejected . '/resolve', [
        'resolution_diagnosis' => $diagnosis,
        'resolution_action' => $action,
        'replaced_parts_declared' => false,
        'root_cause_id' => $aId,
    ], $technicianToken);
    $assert(
        '3.2 Una causa sin solución responde 422 MISSING_ROOT_CAUSE_TAXONOMY',
        $technicianMissingSolution->getStatusCode() === 422
            && $errorCode($technicianMissingSolution) === 'MISSING_ROOT_CAUSE_TAXONOMY'
    );

    $shortDiagnosis = $call('POST', '/api/technician/incidents/' . $incidentRejected . '/resolve', [
        'resolution_diagnosis' => 'Fallo en el sensor.',
        'resolution_action' => $action,
        'replaced_parts_declared' => false,
        'root_cause_id' => $aId,
        'standard_solution_id' => $a2Id,
    ], $technicianToken);
    $assert(
        '3.3 Un diagnóstico de 19 caracteres se rechaza pese a una taxonomía válida',
        $shortDiagnosis->getStatusCode() === 422 && $errorCode($shortDiagnosis) === 'INVALID_RESOLUTION'
    );

    $incompatible = $call('POST', '/api/technician/incidents/' . $incidentRejected . '/resolve', [
        'resolution_diagnosis' => $diagnosis,
        'resolution_action' => $action,
        'replaced_parts_declared' => false,
        'root_cause_id' => $aId,
        'standard_solution_id' => $c1Id,
    ], $technicianToken);
    $assert(
        '3.4 Una solución de otra causa responde 422 INCOMPATIBLE_STANDARD_SOLUTION',
        $incompatible->getStatusCode() === 422
            && $errorCode($incompatible) === 'INCOMPATIBLE_STANDARD_SOLUTION'
    );

    $rejectedRow = $pdo->query(
        'SELECT status, resolution_diagnosis, root_cause_id
           FROM incidents WHERE id = ' . $incidentRejected
    )->fetch(PDO::FETCH_ASSOC);
    $assert(
        '3.5 Ningún rechazo mueve el expediente ni autorrellena los textos',
        ($rejectedRow['status'] ?? '') === 'IN_PROGRESS'
            && ($rejectedRow['resolution_diagnosis'] ?? null) === null
            && ($rejectedRow['root_cause_id'] ?? null) === null
    );

    $coordinatorClose = $call('POST', '/api/coordinator/incidents/' . $incidentCoordinator . '/resolve', [
        'resolution_diagnosis' => $diagnosis,
        'resolution_action' => $action,
        'replaced_parts_declared' => false,
        'root_cause_id' => $aId,
        'standard_solution_id' => $a2Id,
    ], $coordinatorToken);
    $assert(
        '3.6 Coordinación cierra con clasificación obligatoria y conserva al técnico responsable',
        $coordinatorClose->getStatusCode() === 200
            && ($data($coordinatorClose)['status'] ?? '') === 'RESOLVED'
            && ($data($coordinatorClose)['root_cause']['code'] ?? '') === 'RC-TAX-A'
            && (int)$pdo->query('SELECT assigned_technician_id FROM incidents WHERE id = ' . $incidentCoordinator)->fetchColumn() === $technicianId
    );

    $thirdAClose = $call('POST', '/api/technician/incidents/' . $incidentThirdA . '/resolve', [
        'resolution_diagnosis' => $diagnosis,
        'resolution_action' => $action,
        'replaced_parts_declared' => false,
        'root_cause_id' => $aId,
        'standard_solution_id' => $a1Id,
    ], $technicianToken);
    $assert('3.7 El tercer ticket de A se cierra con la solución renombrada', $thirdAClose->getStatusCode() === 200);

    $firstBClose = $call('POST', '/api/technician/incidents/' . $incidentReopen . '/resolve', [
        'resolution_diagnosis' => 'Diagnóstico de sonda térmica desviada en la cámara de frío.',
        'resolution_action' => 'Sustitución de sonda NTC y calibración del rango térmico.',
        'replaced_parts_declared' => false,
        'root_cause_id' => $bId,
        'standard_solution_id' => $b1Id,
    ], $technicianToken);
    $assert('3.8 El primer cierre del ticket reabrible se clasifica con B', $firstBClose->getStatusCode() === 200);

    $reopen = $call(
        'POST',
        '/api/incidents/INC-TAX-0003/reopen',
        ['reopen_reason' => 'Persiste la desviación térmica tras la primera intervención.'],
        $siteToken
    );
    $assert(
        '3.9 La sede reabre dentro de la garantía de 48 h (C1)',
        $reopen->getStatusCode() === 200 && ($data($reopen)['status'] ?? '') === 'REOPENED',
        json_encode($json($reopen))
    );

    // Preparación de la segunda intervención: el técnico vuelve a tomar el
    // ticket desasignado por la reapertura. No se altera la taxonomía previa.
    $pdo->prepare(
        "UPDATE incidents
            SET status = 'IN_PROGRESS',
                assigned_technician_id = :technician,
                assigned_at = CURRENT_TIMESTAMP,
                started_at = CURRENT_TIMESTAMP
          WHERE id = :id"
    )->execute([':technician' => $technicianId, ':id' => $incidentReopen]);

    $secondClose = $call('POST', '/api/technician/incidents/' . $incidentReopen . '/resolve', [
        'resolution_diagnosis' => 'La desviación persiste por un fallo del compensador de puerta.',
        'resolution_action' => 'Ajuste del compensador y verificación de la cadena de frío.',
        'replaced_parts_declared' => false,
        'root_cause_id' => $cId,
        'standard_solution_id' => $c1Id,
    ], $technicianToken);
    $secondSnapshot = $pdo->query(
        'SELECT root_cause_id, standard_solution_id,
                root_cause_code_snapshot, root_cause_name_snapshot,
                standard_solution_code_snapshot, standard_solution_name_snapshot
           FROM incidents WHERE id = ' . $incidentReopen
    )->fetch(PDO::FETCH_ASSOC);
    $preservedNote = $pdo->query(
        "SELECT action_note FROM incident_history
          WHERE incident_id = " . $incidentReopen . "
            AND action_note LIKE 'Clasificación previa preservada%'
          ORDER BY id DESC LIMIT 1"
    )->fetchColumn();

    $assert(
        '3.10 El segundo cierre actualiza la causa vigente para el Pareto (C1)',
        $secondClose->getStatusCode() === 200
            && (int)($secondSnapshot['root_cause_id'] ?? 0) === $cId
            && (int)($secondSnapshot['standard_solution_id'] ?? 0) === $c1Id
            && ($secondSnapshot['root_cause_code_snapshot'] ?? '') === 'RC-TAX-C'
            && ($secondSnapshot['root_cause_name_snapshot'] ?? '') === $cName
            && ($secondSnapshot['standard_solution_code_snapshot'] ?? '') === 'SOL-TAX-C1'
    );

    $assert(
        '3.11 El episodio anterior queda preservado en el historial inmutable',
        is_string($preservedNote)
            && str_contains($preservedNote, 'RC-TAX-B')
            && str_contains($preservedNote, $bName)
            && str_contains($preservedNote, 'SOL-TAX-B1'),
        $preservedNote === false ? 'sin nota de preservación' : (string)$preservedNote
    );

    // El cierre anterior también es visible para Coordinación y para el propio
    // técnico responsable, sin exponer costes ni reintegros (EARS 04.1, C1).
    $coordinatorReopenDetail = $call(
        'GET',
        '/api/coordinator/incidents/' . $incidentReopen . '/detail',
        [],
        $coordinatorToken
    );
    $coordinatorReopenPrevious = $data($coordinatorReopenDetail)['timeline']['previous_classifications'] ?? [];
    $assert(
        '3.11.b El detalle de Coordinación publica el cierre anterior preservado',
        $coordinatorReopenDetail->getStatusCode() === 200
            && count($coordinatorReopenPrevious) === 1
            && ($coordinatorReopenPrevious[0]['root_cause']['code'] ?? '') === 'RC-TAX-B'
            && ($coordinatorReopenPrevious[0]['root_cause']['name'] ?? '') === $bName
            && ($coordinatorReopenPrevious[0]['root_cause']['category_label'] ?? '') === 'Temperatura / Pérdida de frío'
            && ($coordinatorReopenPrevious[0]['standard_solution']['code'] ?? '') === 'SOL-TAX-B1'
            && is_string($coordinatorReopenPrevious[0]['preserved_at'] ?? null),
        json_encode($coordinatorReopenPrevious)
    );

    $technicianReopenDetail = $call(
        'GET',
        '/api/technician/incidents/' . $incidentReopen . '/detail',
        [],
        $technicianToken
    );
    $technicianReopenData = $data($technicianReopenDetail);
    $technicianReopenPrevious = $technicianReopenData['previous_classifications'] ?? [];
    $technicianReopenPayload = strtolower(json_encode($technicianReopenData) ?: '');
    $assert(
        '3.11.c El detalle del técnico publica la causa vigente C y el cierre anterior B',
        $technicianReopenDetail->getStatusCode() === 200
            && ($technicianReopenData['resolution']['root_cause']['code'] ?? '') === 'RC-TAX-C'
            && ($technicianReopenData['resolution']['standard_solution']['code'] ?? '') === 'SOL-TAX-C1'
            && count($technicianReopenPrevious) === 1
            && ($technicianReopenPrevious[0]['root_cause']['code'] ?? '') === 'RC-TAX-B'
            && ($technicianReopenPrevious[0]['standard_solution']['code'] ?? '') === 'SOL-TAX-B1'
            && !str_contains($technicianReopenPayload, 'parts_cost')
            && !str_contains($technicianReopenPayload, 'refund'),
        json_encode($technicianReopenData)
    );

    $incidentCClose = $call('POST', '/api/technician/incidents/' . $incidentCauseC . '/resolve', [
        'resolution_diagnosis' => 'Diagnóstico de conmutación de predeterminada en el catálogo.',
        'resolution_action' => 'Aplicación de la solución C2 y prueba funcional del canal.',
        'replaced_parts_declared' => false,
        'root_cause_id' => $cId,
        'standard_solution_id' => $c2Id,
    ], $technicianToken);
    $assert('3.12 El ticket de C se cierra con la solución predeterminada C2', $incidentCClose->getStatusCode() === 200);

    // ─────────────────────────────────────────────────────────────────────
    // 4. Offline PWA: bootstrap, baja sobrevenida y volcado C5
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 4. Offline PWA y preservación de conflictos (EARS 03.2/03.3, C5) ---\n";

    $deactivateB = $call('PUT', '/api/coordinator/root-causes/' . $bId, [
        'is_active' => false,
    ], $coordinatorToken);
    $assert('4.1 La causa B se desactiva mientras la cola offline está en vuelo', $deactivateB->getStatusCode() === 200);

    $bootstrap = $call('GET', '/api/technician/offline/bootstrap', [], $technicianToken);
    $bootstrapData = $data($bootstrap);
    $bootstrapTaxonomy = is_array($bootstrapData['root_cause_taxonomy'] ?? null)
        ? $bootstrapData['root_cause_taxonomy']
        : [];
    $bootstrapCodes = array_column($bootstrapTaxonomy, 'code');

    $assert(
        '4.2 El paquete offline publica la clave raíz y el catálogo activo de taxonomía',
        $bootstrap->getStatusCode() === 200
            && array_key_exists('root_cause_taxonomy', $bootstrapData)
            && in_array('RC-TAX-A', $bootstrapCodes, true)
            && in_array('RC-TAX-C', $bootstrapCodes, true)
            && !in_array('RC-TAX-B', $bootstrapCodes, true)
            && !in_array('RC-TAX-D', $bootstrapCodes, true)
    );

    $bootstrapHasPricing = false;
    foreach ($bootstrapTaxonomy as $taxonomyEntry) {
        if (array_key_exists('reference_cost', $taxonomyEntry) || array_key_exists('unit_cost', $taxonomyEntry)) {
            $bootstrapHasPricing = true;
        }
    }
    $assert('4.3 El catálogo offline no transporta importes ni costes', !$bootstrapHasPricing);

    $onlineInactive = $call('POST', '/api/technician/incidents/' . $incidentInactiveOnline . '/resolve', [
        'resolution_diagnosis' => $diagnosis,
        'resolution_action' => $action,
        'replaced_parts_declared' => false,
        'root_cause_id' => $bId,
        'standard_solution_id' => $b1Id,
    ], $technicianToken);
    $assert(
        '4.4 Una causa desactivada se rechaza en cierre online (422 INACTIVE_ROOT_CAUSE_TAXONOMY)',
        $onlineInactive->getStatusCode() === 422
            && $errorCode($onlineInactive) === 'INACTIVE_ROOT_CAUSE_TAXONOMY'
    );

    $offlineMutationId = 'tax25.offline.resolve.0001';
    $offlineClose = $call('POST', '/api/technician/incidents/' . $incidentOffline . '/resolve', [
        'resolution_diagnosis' => 'Cierre redactado sin cobertura y sincronizado con la causa desactivada.',
        'resolution_action' => 'Trabajo ejecutado in situ y validado al recuperar la conexión.',
        'replaced_parts_declared' => false,
        'root_cause_id' => $bId,
        'standard_solution_id' => $b1Id,
        'client_mutation_id' => $offlineMutationId,
        'client_occurred_at' => (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP'),
    ], $technicianToken);
    $offlineData = $data($offlineClose);
    $offlineSnapshot = $pdo->query(
        'SELECT status, root_cause_id, root_cause_code_snapshot, root_cause_name_snapshot
           FROM incidents WHERE id = ' . $incidentOffline
    )->fetch(PDO::FETCH_ASSOC);

    $assert(
        '4.5 La sync diferida acepta la causa desactivada y congela sus snapshots (EARS 03.3)',
        $offlineClose->getStatusCode() === 200
            && ($offlineData['status'] ?? '') === 'RESOLVED'
            && ($offlineData['offline_sync']['client_mutation_id'] ?? '') === $offlineMutationId
            && ($offlineSnapshot['status'] ?? '') === 'RESOLVED'
            && (int)($offlineSnapshot['root_cause_id'] ?? 0) === $bId
            && ($offlineSnapshot['root_cause_code_snapshot'] ?? '') === 'RC-TAX-B'
            && ($offlineSnapshot['root_cause_name_snapshot'] ?? '') === $bName,
        json_encode($offlineData)
    );

    $dump = $call('POST', '/api/technician/offline/conflicts/dump', [
        'stop_type' => 'INCIDENT',
        'stop_id' => $incidentReopen,
        'operation_type' => 'RESOLVE_INCIDENT',
        'conflict_reason' => 'La parada fue rechazada por la central y el trabajo debe conservarse.',
        'work_summary' => [
            'diagnosis' => 'Diagnóstico in situ que no puede perderse en el volcado.',
            'action_taken' => 'Acción ejecutada in situ y documentada para el taller.',
            'offline_root_cause_classification' => [
                'root_cause_id' => $bId,
                'root_cause_code' => 'RC-TAX-B',
                'root_cause_name' => $bName,
                'standard_solution_id' => $b1Id,
                'standard_solution_code' => 'SOL-TAX-B1',
                'standard_solution_name' => 'Sustitución de sonda térmica certificada',
            ],
        ],
        'client_mutation_id' => 'tax25.conflict.dump.0001',
        'client_occurred_at' => (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP'),
    ], $technicianToken);
    $dumpData = $data($dump);
    $commentId = (int)($dumpData['internal_comment_id'] ?? 0);
    $commentText = $commentId > 0
        ? (string)$pdo->query('SELECT comment_text FROM incident_comments WHERE id = ' . $commentId)->fetchColumn()
        : '';

    $assert(
        '4.6 El volcado de conflicto conserva la clasificación declarada in situ (C5)',
        $dump->getStatusCode() === 200
            && $commentId > 0
            && str_contains($commentText, 'Causa raíz declarada in situ: RC-TAX-B')
            && str_contains($commentText, $bName)
            && str_contains($commentText, 'Solución estándar: SOL-TAX-B1')
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Pareto 80/20, fabricante y CSV en dos secciones
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 5. Motor Pareto y exportación CSV (D5, QA 2, QA 4) ---\n";

    $pareto = $call('GET', '/api/coordinator/metrics/root-causes-pareto', [], $coordinatorToken, [
        'period_days' => 'all',
    ]);
    $paretoData = $data($pareto);
    $summary = $paretoData['summary'] ?? [];
    $items = $paretoData['items'] ?? [];
    $itemByCode = [];
    foreach ($items as $item) {
        $itemByCode[(string)($item['root_cause_code'] ?? '')] = $item;
    }

    $assert(
        '5.1 El Pareto separa clasificadas e históricas sin clasificar (C3)',
        $pareto->getStatusCode() === 200
            && (int)($summary['classified_incidents_count'] ?? 0) === 6
            && (int)($summary['unclassified_historical_count'] ?? 0) === 1
            && (int)($summary['total_incidents_in_period'] ?? 0) === 7
    );

    $assert(
        '5.2 El ranking agrupa por causa vigente: A=3, C=2, B=1',
        (int)($itemByCode['RC-TAX-A']['incident_count'] ?? 0) === 3
            && (int)($itemByCode['RC-TAX-C']['incident_count'] ?? 0) === 2
            && (int)($itemByCode['RC-TAX-B']['incident_count'] ?? 0) === 1
    );

    $assert(
        '5.3 La curva cierra exactamente en 100,00 % y la Zona Vital cruza el 80 % inclusive',
        (float)($itemByCode['RC-TAX-A']['cumulative_percentage'] ?? 0) === 50.00
            && (float)($itemByCode['RC-TAX-C']['cumulative_percentage'] ?? 0) === 83.33
            && (float)($itemByCode['RC-TAX-B']['cumulative_percentage'] ?? 0) === 100.00
            && ($itemByCode['RC-TAX-A']['is_vital_few'] ?? false) === true
            && ($itemByCode['RC-TAX-C']['is_vital_few'] ?? false) === true
            && ($itemByCode['RC-TAX-B']['is_vital_few'] ?? false) === false
            && (int)($summary['vital_few_causes_count'] ?? 0) === 2
    );

    $assert(
        '5.4 El Pareto publica el nombre vigente y no el snapshot renombrado',
        ($itemByCode['RC-TAX-A']['root_cause_name'] ?? '') === $aNameCurrent
            && $aNameOld !== ($itemByCode['RC-TAX-A']['root_cause_name'] ?? '')
    );

    $assert(
        '5.5 El fabricante se normaliza y aparece en los filtros disponibles (QA 4)',
        in_array('Necta', $paretoData['available_filters']['manufacturers'] ?? [], true)
            && in_array('Necta Samba Combo', $paretoData['available_filters']['models'] ?? [], true)
    );

    $paretoNecta = $call('GET', '/api/coordinator/metrics/root-causes-pareto', [], $coordinatorToken, [
        'period_days' => 'all',
        'manufacturer' => 'necta',
    ]);
    $nectaSummary = $data($paretoNecta)['summary'] ?? [];
    $nectaItems = $data($paretoNecta)['items'] ?? [];
    $nectaCodes = array_column($nectaItems, 'root_cause_code');

    $assert(
        '5.6 El filtro por fabricante reduce el universo y conserva la base del 100 %',
        $paretoNecta->getStatusCode() === 200
            && (int)($nectaSummary['classified_incidents_count'] ?? 0) === 4
            && (int)($nectaSummary['total_incidents_in_period'] ?? 0) === 4
            && !in_array('RC-TAX-B', $nectaCodes, true),
        json_encode($nectaSummary)
    );

    $csv = $call('GET', '/api/coordinator/metrics/root-causes-pareto/export', [], $coordinatorToken, [
        'period_days' => 'all',
        'manufacturer' => 'Necta',
    ]);
    $csvBody = $csv->getBody();
    $section2Marker = 'SECCIÓN 2:';
    $section1 = str_contains($csvBody, $section2Marker)
        ? strstr($csvBody, $section2Marker, true)
        : '';
    $section2 = str_contains($csvBody, $section2Marker)
        ? substr($csvBody, strpos($csvBody, $section2Marker))
        : '';

    $assert(
        '5.7 El CSV se sirve como adjunto UTF-8 con BOM',
        $csv->getStatusCode() === 200
            && str_starts_with($csvBody, "\xEF\xBB\xBF")
            && str_contains((string)($csv->getHeaders()['Content-Type'] ?? ''), 'text/csv')
            && str_contains((string)($csv->getHeaders()['Content-Disposition'] ?? ''), 'attachment')
    );

    $assert(
        '5.8 El CSV contiene las dos secciones del contrato',
        str_contains($csvBody, 'SECCIÓN 1: TABLA AGREGADA DE PARETO')
            && str_contains($csvBody, 'SECCIÓN 2: DETALLE NOMINAL DE EXPEDIENTES')
            && count(array_filter([$section1, $section2])) === 2
    );

    $assert(
        '5.9 El CSV agrega con decimal castellano y respeta el filtro de fabricante',
        str_contains($section1, '75,00')
            && str_contains($section1, 'RC-TAX-A')
            && str_contains($section1, $aNameCurrent)
            && !str_contains($csvBody, 'Fas Fast 900')
            && !str_contains($csvBody, 'RC-TAX-B')
    );

    $assert(
        '5.10 La Sección 2 conserva nombre vigente y nombre congelado (QA 2)',
        str_contains($section2, 'INC-TAX-0001')
            && str_contains($section2, $aNameCurrent)
            && str_contains($section2, $aNameOld)
            && str_contains($section2, $a1NameOld)
            && !str_contains($section1, $aNameOld)
    );

    $exportAudit = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'ROOT_CAUSE_TAXONOMY'
            AND action = 'EXPORT_ROOT_CAUSE_PARETO_CSV'"
    );
    $exportAudit->execute();
    $assert('5.11 La exportación deja auditoría del evento', (int)$exportAudit->fetchColumn() >= 1);

    // ─────────────────────────────────────────────────────────────────────
    // 6. RBAC y opacidad total en Sede, QR y Albarán A4 (Art. V.4, QA 5)
    // ─────────────────────────────────────────────────────────────────────

    echo "\n--- 6. RBAC y opacidad Art. V.4 ---\n";

    $technicianDeniedEndpoints = [
        ['GET', '/api/coordinator/root-causes'],
        ['POST', '/api/coordinator/root-causes'],
        ['PUT', '/api/coordinator/root-causes/' . $aId],
        ['GET', '/api/coordinator/metrics/root-causes-pareto'],
        ['GET', '/api/coordinator/metrics/root-causes-pareto/export'],
    ];
    $allTechnicianDenied = true;
    foreach ($technicianDeniedEndpoints as [$method, $path]) {
        $deniedResponse = $call($method, $path, [], $technicianToken);
        if ($deniedResponse->getStatusCode() !== 403 || $errorCode($deniedResponse) !== 'FORBIDDEN') {
            $allTechnicianDenied = false;
            break;
        }
    }
    $assert('6.1 El técnico recibe 403 FORBIDDEN en CRUD, Pareto y CSV', $allTechnicianDenied);

    $siteDenied = $call('GET', '/api/coordinator/metrics/root-causes-pareto', [], $siteToken);
    $assert(
        '6.2 La sesión de sede no atraviesa la puerta interna (sin datos de catálogo)',
        in_array($siteDenied->getStatusCode(), [401, 403], true)
            && !$hasTaxonomyKey($json($siteDenied))
    );

    $locationRoleRequest = new Request('GET', '/api/coordinator/root-causes');
    $locationRoleRequest->setAttribute('user_role', 'LOCATION_RESPONSIBLE');
    $locationRoleResponse = (new CoordinatorRootCauseController())->listCatalog($locationRoleRequest);
    $assert(
        '6.3 La defensa en profundidad del catálogo rechaza LOCATION_RESPONSIBLE con 403',
        $locationRoleResponse->getStatusCode() === 403
            && ($locationRoleResponse->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
    );

    $locationTechnicianRequest = new Request('GET', '/api/technician/root-causes');
    $locationTechnicianRequest->setAttribute('user_role', 'LOCATION_RESPONSIBLE');
    $locationTechnicianResponse = (new TechnicianController())->listActiveRootCauses($locationTechnicianRequest);
    $assert(
        '6.4 El catálogo operativo del técnico rechaza LOCATION_RESPONSIBLE con 403',
        $locationTechnicianResponse->getStatusCode() === 403
            && ($locationTechnicianResponse->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
    );

    $catalogTechnician = $call('GET', '/api/technician/root-causes', [], $technicianToken, [
        'preferred_category' => 'PAYMENT_SYSTEM',
    ]);
    $technicianCatalog = $data($catalogTechnician)['items'] ?? [];
    $assert(
        '6.5 El técnico lee el catálogo activo con categoría preferente y sugerencias D3',
        $catalogTechnician->getStatusCode() === 200
            && ($technicianCatalog[0]['code'] ?? '') === 'RC-TAX-A'
            && ($technicianCatalog[0]['is_suggested_for_category'] ?? false) === true
            && !in_array('RC-TAX-B', array_column($technicianCatalog, 'code'), true)
    );

    // El expediente activo de la máquina viaja con siete campos de clasificación
    // completos para demostrar que la sede no recibe ninguno.
    $pdo->prepare(
        "UPDATE incidents
            SET root_cause_id = :cause_id,
                standard_solution_id = :solution_id,
                root_cause_code_snapshot = 'RC-TAX-A',
                root_cause_category_snapshot = 'PAYMENT_SYSTEM',
                root_cause_name_snapshot = :cause_name,
                standard_solution_code_snapshot = 'SOL-TAX-A1',
                standard_solution_name_snapshot = :solution_name
          WHERE id = :id"
    )->execute([
        ':cause_id' => $aId,
        ':solution_id' => $a1Id,
        ':cause_name' => $aNameCurrent,
        ':solution_name' => $a1NameCurrent,
        ':id' => $incidentRejected,
    ]);

    $siteMachines = $call('GET', '/api/locations/SEDE-TAX-25/machines', [], $siteToken);
    $siteMachinesData = $data($siteMachines);
    $siteMachineMain = null;
    foreach ($siteMachinesData as $machineRow) {
        if (($machineRow['code'] ?? '') === 'TAX25-M09') {
            $siteMachineMain = $machineRow;
            break;
        }
    }

    $assert(
        '6.6 El Portal de Sede devuelve la máquina con su expediente activo pero sin taxonomía',
        $siteMachines->getStatusCode() === 200
            && is_array($siteMachineMain)
            && is_array($siteMachineMain['active_incident'] ?? null)
            && (int)($siteMachineMain['active_incident']['id'] ?? 0) === $incidentRejected
            && !$hasTaxonomyKey($siteMachinesData)
            && !str_contains($siteMachines->getBody(), 'RC-TAX-')
            && !str_contains($siteMachines->getBody(), 'SOL-TAX-')
    );

    $qrScan = $call('GET', '/api/qr/scan/TAX25-M09');
    $qrData = $data($qrScan);
    $assert(
        '6.7 El seguimiento QR no publica ninguna referencia ni nombre de taxonomía',
        $qrScan->getStatusCode() === 200
            && !$hasTaxonomyKey($qrData)
            && !str_contains($qrScan->getBody(), 'RC-TAX-')
            && !str_contains($qrScan->getBody(), $aNameCurrent)
    );

    $serviceSheet = $call('GET', '/api/coordinator/incidents/' . $incidentFirst . '/service-sheet', [], $coordinatorToken);
    $serviceSheetData = $data($serviceSheet);
    $assert(
        '6.8 El Albarán A4 firmado no serializa taxonomía interna',
        $serviceSheet->getStatusCode() === 200
            && is_array($serviceSheetData['document'] ?? null)
            && ($serviceSheetData['ticket_code'] ?? '') === 'INC-TAX-0001'
            && !$hasTaxonomyKey($serviceSheetData)
            && !str_contains($serviceSheet->getBody(), 'RC-TAX-')
            && !str_contains($serviceSheet->getBody(), 'SOL-TAX-')
    );
} catch (Throwable $exception) {
    $crash = $exception;
    fwrite(STDERR, "\n[EXCEPCIÓN] " . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
} finally {
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        PostgresFixtureBuilder::dropIsolationSchema($pdo, TAXONOMY_API_SCHEMA);
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, "\n[LIMPIEZA] No se pudo retirar el esquema aislado: " . $cleanupError->getMessage() . "\n");
    }
}

echo "\n" . str_repeat('=', 70) . "\n";
if ($crash === null && $failures === 0) {
    echo sprintf(" RESULTADO: TODAS LAS PRUEBAS PASARON (%d aserciones, 0 fallos).\n", $assertions);
} else {
    echo sprintf(
        " RESULTADO: %d fallo(s) en %d aserciones%s.\n",
        $failures,
        $assertions,
        $crash !== null ? ' · excepción: ' . $crash->getMessage() : ''
    );
}
echo str_repeat('=', 70) . "\n";

exit(($crash === null && $failures === 0) ? 0 : 1);
