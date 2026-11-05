<?php

declare(strict_types=1);

/**
 * TroubleshootingGuideApiIntegrationTest · T-GUIDE-11
 *
 * Certifica de punta a punta la superficie HTTP del catálogo de guías rápidas
 * de diagnóstico (Módulo 28) contra PostgreSQL, con el router real, middleware
 * RBAC, controladores, servicios y repositorios PDO sobre un esquema aislado
 * sembrado con el catálogo real del parque.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **RBAC y opacidad (`Art. V.4`, `RF-04`).**
 *      - Sin sesión, `GET /api/troubleshooting-guides/by-model` responde `401`.
 *      - La credencial de sede no cruza la puerta interna (`401`) y, si llegara
 *        al controlador con rol `LOCATION_RESPONSIBLE`, la guarda responde `403`.
 *      - `TECHNICIAN` recibe `403` en los cuatro endpoints de Coordinación.
 *
 *   2. **Consulta por modelo (`RF-01.1`–`RF-01.3`, `QA 3`).**
 *      - Técnico y coordinador obtienen `200` con la guía exacta en primera
 *        posición, la etiqueta de categoría y la alerta sanitaria de frío.
 *      - El cruce de furgoneta devuelve unidades físicas (`4`, `0`) sin exponer
 *        ningún coste, precio o importe.
 *      - El coordinador consulta sin saldo propio y el modelo huérfano no
 *        inventa guías (`guides = []`).
 *      - Categoría desconocida y modelo ausente responden `422`.
 *
 *   3. **Paquete offline (`D3`, `RF-03.1`).** `GET
 *      /api/technician/offline/bootstrap` incorpora `troubleshooting_guides`
 *      con las ocho guías enriquecidas y el stock real del técnico.
 *
 *   4. **CRUD auditado (`Art. III.3`, `C1`–`C3`, `QA 1`–`QA 2`).**
 *      - `POST` válido responde `201` y audita `CREATE`; el duplicado `409`.
 *      - `PUT` edita con `200`, rechaza el cambio de identidad con `422
 *        IMMUTABLE_GUIDE_IDENTITY` y el id inexistente con `404`.
 *      - `422` para pasos, códigos de error, repuesto inactivo y causa raíz
 *        inactiva.
 *      - `PATCH` desactiva y reactiva con `200` y auditoría; `404` si no existe.
 *      - El resumen de cobertura no se apaga al filtrar el listado (`QA 1`).
 *
 * CATEGORÍA: `new_engine`. Exige PostgreSQL local (`deploy/local/postgres/`);
 * sin motor sale con código 2, nunca se omite en silencio.
 *
 * Uso:
 *   php tests/integration/TroubleshootingGuideApiIntegrationTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación en español.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/PostgresFixtureBuilder.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SqlScriptSplitter.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/PostgresMigrationRunner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Controller\TroubleshootingGuideController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

/** Marca de categoría que lee la batería global. */
const VENDGUARD_TEST_CATEGORY = 'new_engine';

/** Esquema aislado propio de esta suite. */
const GUIDES_API_TEST_SCHEMA = 't_guides28_api';

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
echo " VENDGUARD · T-GUIDE-11 · API de guías de diagnóstico (Módulo 28)\n";
echo " Categoría: new_engine (canal propio, fuera de la batería hermética)\n";
echo "======================================================================\n";

$pdo = null;
$crash = null;

try {
    $pdo = ConnectionFactory::getConnection();
    $pdo->query('SELECT 1');

    echo "\n--- Escenario (DDL base + migraciones + semillas en esquema aislado) ---\n";

    PostgresFixtureBuilder::createIsolationSchema($pdo, GUIDES_API_TEST_SCHEMA);
    $schemaReport = PostgresFixtureBuilder::applyRepositorySchema($pdo, $projectRoot);
    (new SeedRunner($pdo))->seedAll();

    $guideCount = (int)$pdo->query('SELECT COUNT(*) FROM machine_troubleshooting_guides')->fetchColumn();
    $assert(
        'El esquema incluye la migración 024 y el catálogo siembra las ocho guías',
        in_array('024_machine_troubleshooting_guides.sql', $schemaReport['migrations'], true)
        && $guideCount === 8,
        'guías: ' . $guideCount
    );

    $userRepo = new PdoUserRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $authService = new AuthService($locationRepo, $userRepo);

    $userIdByEmail = static function (string $email) use ($pdo): int {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email_key = :email');
        $stmt->execute([':email' => $email]);

        return (int)$stmt->fetchColumn();
    };

    $coordinatorId = $userIdByEmail('coordinacion@vendguard.internal');
    $technicianId = $userIdByEmail('jordi.ruta@vendguard.internal');
    $coordinator = $userRepo->findById($coordinatorId);
    $technician = $userRepo->findById($technicianId);
    $locationId = (int)$pdo->query('SELECT id FROM locations ORDER BY id LIMIT 1')->fetchColumn();
    $location = $locationRepo->findById($locationId);

    if ($coordinator === null || $technician === null || $location === null) {
        throw new RuntimeException('No se pudieron resolver las identidades sembradas para la suite.');
    }

    $coordToken = $authService->generateInternalToken($coordinator);
    $techToken = $authService->generateInternalToken($technician);
    $siteToken = $authService->generateSiteToken($location);

    $partIds = [];
    foreach ($pdo->query('SELECT id, part_code FROM spare_parts') as $row) {
        $partIds[(string)$row['part_code']] = (int)$row['id'];
    }

    $rootCauseIds = [];
    foreach ($pdo->query('SELECT id, code FROM root_causes') as $row) {
        $rootCauseIds[(string)$row['code']] = (int)$row['id'];
    }

    $monId = $partIds['MON-CASH-01'] ?? 0;
    $valveId = $partIds['VALV-ULKA-01'] ?? 0;
    $filterId = $partIds['FILT-AGUA-01'] ?? 0;
    $rootCauseOtherId = $rootCauseIds['RC-OTH-01'] ?? 0;
    $sandenMachineId = (int)$pdo->query("SELECT id FROM machines WHERE code = 'VEND-0101'")->fetchColumn();
    $bianchiMachineId = (int)$pdo->query("SELECT id FROM machines WHERE code = 'VEND-0102'")->fetchColumn();

    if ($monId === 0 || $valveId === 0 || $filterId === 0 || $rootCauseOtherId === 0) {
        throw new RuntimeException('Faltan referencias del catálogo sembrado (repuestos o causa raíz).');
    }

    $router = AppRouter::create();

    $call = static function (
        string $method,
        string $path,
        array $body = [],
        ?string $token = null,
        array $query = []
    ) use ($router): Response {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $router->dispatch(new Request($method, $path, $query, $body, $headers));
    };

    $json = static fn (Response $response): array => $response->getDecodedBody() ?? [];
    $data = static fn (Response $response): array => $json($response)['data'] ?? [];
    $errorCode = static fn (Response $response): string => (string)($json($response)['error']['code'] ?? '');
    $hasMonetaryKey = static function (array $payload) use (&$hasMonetaryKey): bool {
        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match('/cost|price|precio|importe|amount/i', $key) === 1) {
                return true;
            }
            if (is_array($value) && $hasMonetaryKey($value)) {
                return true;
            }
        }

        return false;
    };

    // ─────────────────────────────────────────────────────────────────────
    // 1. RBAC y opacidad (Art. V.4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. RBAC y opacidad (Art. V.4) ---\n";

    $resAnon = $call('GET', '/api/troubleshooting-guides/by-model', [], null, [
        'machine_model' => 'Sanden Vendo G-Drink',
    ]);
    $assert(
        '1.1 GET /by-model sin sesión responde 401 UNAUTHORIZED',
        $resAnon->getStatusCode() === 401 && $errorCode($resAnon) === 'UNAUTHORIZED'
    );

    $resSite = $call('GET', '/api/troubleshooting-guides/by-model', [], $siteToken, [
        'machine_model' => 'Sanden Vendo G-Drink',
    ]);
    $assert(
        '1.2 La credencial de sede no cruza la puerta interna (401, opacidad total)',
        $resSite->getStatusCode() === 401 && $errorCode($resSite) === 'UNAUTHORIZED'
    );

    $locationRequest = new Request('GET', '/api/troubleshooting-guides/by-model', [
        'machine_model' => 'Sanden Vendo G-Drink',
    ]);
    $locationRequest->setAttribute('user_role', 'LOCATION_RESPONSIBLE');
    $resLocationGuard = (new TroubleshootingGuideController())->getByModel($locationRequest);
    $assert(
        '1.3 La guarda de lectura rechaza al LOCATION_RESPONSIBLE con 403 FORBIDDEN',
        $resLocationGuard->getStatusCode() === 403
            && (string)($resLocationGuard->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
    );

    $resTechRead = $call('GET', '/api/troubleshooting-guides/by-model', [], $techToken, [
        'machine_model' => 'Bianchi Gaia Espresso',
    ]);
    $assert(
        '1.4 El técnico puede consultar las guías por modelo (200)',
        $resTechRead->getStatusCode() === 200,
        'status: ' . $resTechRead->getStatusCode()
    );

    $resCoordRead = $call('GET', '/api/troubleshooting-guides/by-model', [], $coordToken, [
        'machine_model' => 'Bianchi Gaia Espresso',
    ]);
    $assert(
        '1.5 El coordinador puede consultar las guías por modelo (200)',
        $resCoordRead->getStatusCode() === 200,
        'status: ' . $resCoordRead->getStatusCode()
    );

    $coordinatorEndpoints = [
        ['GET', '/api/coordinator/troubleshooting-guides'],
        ['POST', '/api/coordinator/troubleshooting-guides'],
        ['PUT', '/api/coordinator/troubleshooting-guides/1'],
        ['PATCH', '/api/coordinator/troubleshooting-guides/1/status'],
    ];

    foreach ($coordinatorEndpoints as $index => [$method, $path]) {
        $resTechAdmin = $call($method, $path, [], $techToken);
        $assert(
            '1.6.' . ($index + 1) . " El técnico recibe 403 en {$method} {$path}",
            $resTechAdmin->getStatusCode() === 403 && $errorCode($resTechAdmin) === 'FORBIDDEN',
            'status: ' . $resTechAdmin->getStatusCode()
        );

        $resSiteAdmin = $call($method, $path, [], $siteToken);
        $assert(
            '1.7.' . ($index + 1) . " La sede recibe 401 en {$method} {$path}",
            $resSiteAdmin->getStatusCode() === 401 && $errorCode($resSiteAdmin) === 'UNAUTHORIZED',
            'status: ' . $resSiteAdmin->getStatusCode()
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Consulta por modelo, stock, sanidad y cobertura (Art. II, V.4)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Consulta por modelo, stock y sanidad (Art. II, V.4) ---\n";

    $resCold = $call('GET', '/api/troubleshooting-guides/by-model', [], $techToken, [
        'machine_model' => '  sanden vendo g-drink  ',
        'breakdown_type' => 'TEMPERATURE_COLD',
        'machine_type' => 'PERISHABLE_FOOD',
    ]);
    $cold = $data($resCold);
    $coldGuides = $cold['guides'] ?? [];
    $coldGuide = $coldGuides[0] ?? [];
    $coldPartCodes = array_map(
        static fn (array $part): string => (string)($part['part_code'] ?? ''),
        $coldGuide['recommended_spare_parts'] ?? []
    );
    $assert(
        '2.1 Técnico: la guía de frío sube a la primera posición y preselecciona su id',
        $resCold->getStatusCode() === 200
            && ($cold['machine_model_key'] ?? null) === 'sanden vendo g-drink'
            && ($cold['has_exact_category_match'] ?? null) === true
            && ($cold['selected_guide_id'] ?? null) === ($coldGuide['id'] ?? null)
            && count($coldGuides) === 1
    );
    $assert(
        '2.2 La guía de frío conserva códigos, pasos y las dos piezas compatibles',
        ($coldGuide['breakdown_type'] ?? null) === 'TEMPERATURE_COLD'
            && ($coldGuide['breakdown_type_label'] ?? '') !== ''
            && count($coldGuide['error_codes'] ?? []) === 2
            && count($coldGuide['steps'] ?? []) === 3
            && in_array('SOND-NTC-01', $coldPartCodes, true)
            && in_array('VENT-EVAP-01', $coldPartCodes, true)
    );
    $assert(
        '2.3 El aviso sanitario se enciende para máquina perecedera y guía de frío',
        ($cold['sanitary_cold_warning_for_machine'] ?? null) === true
            && ($coldGuide['sanitary_cold_warning'] ?? null) === true
    );
    $assert(
        '2.4 La causa sugerida viaja con su solución predeterminada',
        ($coldGuide['suggested_root_cause']['code'] ?? null) === 'RC-COLD-01'
            && isset($coldGuide['suggested_root_cause']['default_solution']['code'])
    );

    $resWarm = $call('GET', '/api/troubleshooting-guides/by-model', [], $techToken, [
        'machine_model' => 'Bianchi Gaia Espresso',
        'breakdown_type' => 'OTHER',
    ]);
    $warm = $data($resWarm);
    $warmParts = [];
    foreach ($warm['guides'][0]['recommended_spare_parts'] ?? [] as $part) {
        $warmParts[(string)($part['part_code'] ?? '')] = $part;
    }
    $assert(
        '2.5 Cruce de furgoneta del técnico: VALV-ULKA-01 con 4 unidades y stock positivo',
        ($warmParts['VALV-ULKA-01']['van_stock_quantity'] ?? null) === 4
            && ($warmParts['VALV-ULKA-01']['has_van_stock'] ?? null) === true
            && ($warmParts['FILT-AGUA-01']['van_stock_quantity'] ?? null) === 0
            && ($warmParts['FILT-AGUA-01']['has_van_stock'] ?? null) === false
    );
    $assert(
        '2.6 Ninguna respuesta de guías expone coste, precio o importe',
        !$hasMonetaryKey($cold) && !$hasMonetaryKey($warm)
    );

    $resCoordModel = $call('GET', '/api/troubleshooting-guides/by-model', [], $coordToken, [
        'machine_model' => 'Bianchi Gaia Espresso',
    ]);
    $coordParts = [];
    foreach ($data($resCoordModel)['guides'][0]['recommended_spare_parts'] ?? [] as $part) {
        $coordParts[(string)($part['part_code'] ?? '')] = $part;
    }
    $assert(
        '2.7 El coordinador consulta sin saldo propio (stock 0, sin cruce de furgoneta)',
        $resCoordModel->getStatusCode() === 200
            && ($coordParts['VALV-ULKA-01']['van_stock_quantity'] ?? null) === 0
            && ($coordParts['VALV-ULKA-01']['has_van_stock'] ?? null) === false
    );

    $resOrphan = $call('GET', '/api/troubleshooting-guides/by-model', [], $techToken, [
        'machine_model' => 'Fas Perla',
    ]);
    $orphanData = $data($resOrphan);
    $assert(
        '2.8 Un modelo del parque sin guía devuelve 200 con la lista vacía',
        $resOrphan->getStatusCode() === 200
            && $orphanData['guides'] === []
            && $orphanData['selected_guide_id'] === null
    );

    $resMissingModel = $call('GET', '/api/troubleshooting-guides/by-model', [], $techToken);
    $assert(
        '2.9 La consulta sin machine_model responde 422 INVALID_GUIDE_MODEL',
        $resMissingModel->getStatusCode() === 422 && $errorCode($resMissingModel) === 'INVALID_GUIDE_MODEL'
    );

    $resBadCategory = $call('GET', '/api/troubleshooting-guides/by-model', [], $techToken, [
        'machine_model' => 'Sanden Vendo G-Drink',
        'breakdown_type' => 'NOT_A_CATEGORY',
    ]);
    $assert(
        '2.10 Una categoría desconocida responde 422 INVALID_GUIDE_CATEGORY',
        $resBadCategory->getStatusCode() === 422 && $errorCode($resBadCategory) === 'INVALID_GUIDE_CATEGORY'
    );

    $resCoverage = $call('GET', '/api/coordinator/troubleshooting-guides', [], $coordToken);
    $coverage = $data($resCoverage)['coverage_summary'] ?? [];
    $orphanModels = array_map(
        static fn (array $row): string => (string)($row['machine_model'] ?? ''),
        $coverage['orphan_fleet_models'] ?? []
    );
    $assert(
        '2.11 Cobertura global: ocho guías activas y Fas Perla como modelo huérfano',
        $resCoverage->getStatusCode() === 200
            && ($coverage['total_active_guides'] ?? null) === 8
            && ($coverage['total_guides'] ?? null) === 8
            && in_array('Fas Perla', $orphanModels, true)
            && ($coverage['orphan_fleet_models_count'] ?? null) >= 1
    );

    $resFiltered = $call('GET', '/api/coordinator/troubleshooting-guides', [], $coordToken, [
        'machine_model' => 'Sanden Vendo G-Drink',
    ]);
    $filtered = $data($resFiltered);
    $filteredModels = array_unique(array_map(
        static fn (array $guide): string => (string)($guide['machine_model_key'] ?? ''),
        $filtered['guides'] ?? []
    ));
    $assert(
        '2.12 El filtro del listado no apaga el resumen de cobertura (QA 1)',
        $resFiltered->getStatusCode() === 200
            && count($filtered['guides'] ?? []) === 1
            && $filteredModels === ['sanden vendo g-drink']
            && ($filtered['coverage_summary']['total_active_guides'] ?? null) === 8
    );

    // ─────────────────────────────────────────────────────────────────────
    // 3. Paquete offline del técnico (D3, RF-03.1)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Paquete offline del técnico (D3) ---\n";

    $resBootstrap = $call('GET', '/api/technician/offline/bootstrap', [], $techToken);
    $bootstrap = $data($resBootstrap);
    $bootstrapGuides = $bootstrap['troubleshooting_guides'] ?? [];
    $bootstrapValveStock = null;
    foreach ($bootstrapGuides as $guide) {
        if (($guide['machine_model_key'] ?? null) !== 'bianchi gaia espresso') {
            continue;
        }
        foreach ($guide['recommended_spare_parts'] ?? [] as $part) {
            if (($part['part_code'] ?? null) === 'VALV-ULKA-01') {
                $bootstrapValveStock = $part['van_stock_quantity'] ?? null;
            }
        }
    }
    $assert(
        '3.1 El paquete offline incluye las ocho guías enriquecidas y sin precios',
        $resBootstrap->getStatusCode() === 200
            && count($bootstrapGuides) === 8
            && !$hasMonetaryKey($bootstrapGuides)
    );
    $assert(
        '3.2 Cada guía del paquete conserva pasos y repuestos listos para IndexedDB',
        count(array_filter(
            $bootstrapGuides,
            static fn (array $guide): bool => isset($guide['steps'], $guide['recommended_spare_parts'])
                && count($guide['steps']) >= 2
        )) === 8
    );
    $assert(
        '3.3 El stock del paquete es el del técnico autenticado (VALV-ULKA-01 = 4)',
        $bootstrapValveStock === 4
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. CRUD auditado del catálogo (Art. III.3, C1–C3)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. CRUD auditado del catálogo (Art. III.3) ---\n";

    $createPayload = static fn (array $overrides = []): array => array_merge([
        'machine_model' => 'M28 API Certification',
        'breakdown_type' => 'OTHER',
        'title' => 'Circuito hidráulico: certificación HTTP del catálogo de guías',
        'error_codes' => [
            ['code' => 'E28', 'meaning' => 'Fallo de certificación del circuito hidráulico de entrada'],
        ],
        'steps' => [
            [
                'title' => 'Comprobar la presión de entrada',
                'instruction' => 'Verificar la presión de la red y el estado del cartucho antical antes de abrir el circuito.',
            ],
            [
                'title' => 'Medir la electroválvula',
                'instruction' => 'Medir la tensión de la bobina y confirmar que abre al solicitar un ciclo de lavado.',
            ],
        ],
        'recommended_spare_part_ids' => [$monId, $valveId],
        'suggested_root_cause_id' => $rootCauseOtherId,
    ], $overrides);

    $resCreate = $call('POST', '/api/coordinator/troubleshooting-guides', $createPayload(), $coordToken);
    $createdGuide = $data($resCreate)['guide'] ?? [];
    $createdId = (int)($createdGuide['id'] ?? 0);
    $assert(
        '4.1 POST válido responde 201 con la guía normalizada y activa',
        $resCreate->getStatusCode() === 201
            && $createdId > 0
            && ($createdGuide['machine_model_key'] ?? null) === 'm28 api certification'
            && ($createdGuide['is_active'] ?? null) === true
            && ($createdGuide['created_by'] ?? null) === $coordinatorId
    );

    $createAudit = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_log
          WHERE entity_type = 'MACHINE_TROUBLESHOOTING_GUIDE'
            AND action = 'CREATE_TROUBLESHOOTING_GUIDE'
            AND entity_id = :id"
    );
    $createAudit->execute([':id' => $createdId]);
    $assert(
        '4.2 El alta deja rastro de auditoría con la entidad del catálogo',
        (int)$createAudit->fetchColumn() === 1
    );

    $resDuplicate = $call('POST', '/api/coordinator/troubleshooting-guides', $createPayload(), $coordToken);
    $assert(
        '4.3 El duplicado de modelo y categoría responde 409 DUPLICATE_TROUBLESHOOTING_GUIDE',
        $resDuplicate->getStatusCode() === 409
            && $errorCode($resDuplicate) === 'DUPLICATE_TROUBLESHOOTING_GUIDE'
    );

    $resUpdate = $call(
        'PUT',
        '/api/coordinator/troubleshooting-guides/' . $createdId,
        $createPayload(['title' => 'Circuito hidráulico: título editado por certificación HTTP']),
        $coordToken
    );
    $assert(
        '4.4 PUT edita los campos mutables y responde 200 con el nuevo título',
        $resUpdate->getStatusCode() === 200
            && ($data($resUpdate)['guide']['title'] ?? null) === 'Circuito hidráulico: título editado por certificación HTTP'
    );

    $resImmutable = $call(
        'PUT',
        '/api/coordinator/troubleshooting-guides/' . $createdId,
        $createPayload(['machine_model' => 'M28 Renamed Model']),
        $coordToken
    );
    $assert(
        '4.5 Cambiar el modelo en PUT responde 422 IMMUTABLE_GUIDE_IDENTITY (C1)',
        $resImmutable->getStatusCode() === 422
            && $errorCode($resImmutable) === 'IMMUTABLE_GUIDE_IDENTITY'
    );

    $resUpdateMissing = $call(
        'PUT',
        '/api/coordinator/troubleshooting-guides/999999',
        $createPayload(),
        $coordToken
    );
    $assert(
        '4.6 PUT sobre un id inexistente responde 404 TROUBLESHOOTING_GUIDE_NOT_FOUND',
        $resUpdateMissing->getStatusCode() === 404
            && $errorCode($resUpdateMissing) === 'TROUBLESHOOTING_GUIDE_NOT_FOUND'
    );

    $resInvalidSteps = $call(
        'POST',
        '/api/coordinator/troubleshooting-guides',
        $createPayload([
            'machine_model' => 'M28 Invalid Steps',
            'steps' => [['title' => 'Paso único', 'instruction' => 'Un solo paso no cumple el mínimo de dos pasos exigidos.']],
        ]),
        $coordToken
    );
    $assert(
        '4.7 Menos de dos pasos responde 422 INVALID_GUIDE_STEPS',
        $resInvalidSteps->getStatusCode() === 422 && $errorCode($resInvalidSteps) === 'INVALID_GUIDE_STEPS'
    );

    $resInvalidCodes = $call(
        'POST',
        '/api/coordinator/troubleshooting-guides',
        $createPayload([
            'machine_model' => 'M28 Invalid Codes',
            'error_codes' => [
                ['code' => 'DUP-01', 'meaning' => 'Primera definición del código duplicado'],
                ['code' => 'dup-01', 'meaning' => 'Segunda definición del código duplicado'],
            ],
        ]),
        $coordToken
    );
    $assert(
        '4.8 Códigos de error duplicados responden 422 INVALID_GUIDE_ERROR_CODES (C3)',
        $resInvalidCodes->getStatusCode() === 422 && $errorCode($resInvalidCodes) === 'INVALID_GUIDE_ERROR_CODES'
    );

    $pdo->prepare('UPDATE spare_parts SET is_active = FALSE WHERE id = :id')
        ->execute([':id' => $filterId]);
    $resInactivePart = $call(
        'POST',
        '/api/coordinator/troubleshooting-guides',
        $createPayload([
            'machine_model' => 'M28 Inactive Part',
            'recommended_spare_part_ids' => [$filterId],
        ]),
        $coordToken
    );
    $assert(
        '4.9 Una pieza inactiva responde 422 INVALID_GUIDE_SPARE_PARTS (C2)',
        $resInactivePart->getStatusCode() === 422 && $errorCode($resInactivePart) === 'INVALID_GUIDE_SPARE_PARTS'
    );
    $pdo->prepare('UPDATE spare_parts SET is_active = TRUE WHERE id = :id')
        ->execute([':id' => $filterId]);

    $pdo->prepare('UPDATE root_causes SET is_active = FALSE WHERE id = :id')
        ->execute([':id' => $rootCauseOtherId]);
    $resInactiveRootCause = $call(
        'POST',
        '/api/coordinator/troubleshooting-guides',
        $createPayload(['machine_model' => 'M28 Inactive Root Cause']),
        $coordToken
    );
    $assert(
        '4.10 Una causa raíz inactiva responde 422 INVALID_GUIDE_ROOT_CAUSE (C2, QA 2)',
        $resInactiveRootCause->getStatusCode() === 422
            && $errorCode($resInactiveRootCause) === 'INVALID_GUIDE_ROOT_CAUSE'
    );
    $pdo->prepare('UPDATE root_causes SET is_active = TRUE WHERE id = :id')
        ->execute([':id' => $rootCauseOtherId]);

    $resDeactivate = $call(
        'PATCH',
        '/api/coordinator/troubleshooting-guides/' . $createdId . '/status',
        ['is_active' => false],
        $coordToken
    );
    $assert(
        '4.11 PATCH desactiva la guía con 200 y deja is_active=false',
        $resDeactivate->getStatusCode() === 200
            && ($data($resDeactivate)['guide']['is_active'] ?? null) === false
    );

    $resInactiveList = $call('GET', '/api/coordinator/troubleshooting-guides', [], $coordToken, [
        'status' => 'INACTIVE',
    ]);
    $inactiveIds = array_map(
        static fn (array $guide): int => (int)($guide['id'] ?? 0),
        $data($resInactiveList)['guides'] ?? []
    );
    $assert(
        '4.12 El filtro INACTIVE recupera la guía dada de baja',
        $resInactiveList->getStatusCode() === 200 && in_array($createdId, $inactiveIds, true)
    );

    $resActivate = $call(
        'PATCH',
        '/api/coordinator/troubleshooting-guides/' . $createdId . '/status',
        ['is_active' => true],
        $coordToken
    );
    $assert(
        '4.13 PATCH reactiva la guía con 200 y deja is_active=true',
        $resActivate->getStatusCode() === 200
            && ($data($resActivate)['guide']['is_active'] ?? null) === true
    );

    $resStatusMissing = $call(
        'PATCH',
        '/api/coordinator/troubleshooting-guides/999999/status',
        ['is_active' => false],
        $coordToken
    );
    $assert(
        '4.14 PATCH sobre un id inexistente responde 404 TROUBLESHOOTING_GUIDE_NOT_FOUND',
        $resStatusMissing->getStatusCode() === 404
            && $errorCode($resStatusMissing) === 'TROUBLESHOOTING_GUIDE_NOT_FOUND'
    );

    $auditActions = [];
    foreach (['UPDATE_TROUBLESHOOTING_GUIDE', 'DEACTIVATE_TROUBLESHOOTING_GUIDE', 'ACTIVATE_TROUBLESHOOTING_GUIDE'] as $action) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_log
              WHERE entity_type = 'MACHINE_TROUBLESHOOTING_GUIDE'
                AND action = :action
                AND entity_id = :id"
        );
        $stmt->execute([':action' => $action, ':id' => $createdId]);
        $auditActions[$action] = (int)$stmt->fetchColumn();
    }
    $assert(
        '4.15 Edición, baja y reactivación quedan auditadas con un evento cada una',
        $auditActions['UPDATE_TROUBLESHOOTING_GUIDE'] === 1
            && $auditActions['DEACTIVATE_TROUBLESHOOTING_GUIDE'] === 1
            && $auditActions['ACTIVATE_TROUBLESHOOTING_GUIDE'] === 1
    );

    // La tipología sanitaria no puede depender de la query del cliente. Se crea
    // una guía no fría para el modelo perecedero Sanden y se consulta sin
    // `machine_type`: la base de datos debe seguir encendiendo el aviso.
    $resPerishableGuide = $call('POST', '/api/coordinator/troubleshooting-guides', $createPayload([
        'machine_model' => 'Sanden Vendo G-Drink',
        'breakdown_type' => 'OTHER',
        'title' => 'Circuito hidráulico: guía no fría de una máquina perecedera',
    ]), $coordToken);
    $assert(
        '4.16 Se prepara una guía no fría para el modelo perecedero Sanden',
        $resPerishableGuide->getStatusCode() === 201
    );

    $resWithoutClientType = $call('GET', '/api/troubleshooting-guides/by-model', [], $techToken, [
        'machine_model' => 'Sanden Vendo G-Drink',
        'breakdown_type' => 'OTHER',
    ]);
    $withoutClientType = $data($resWithoutClientType);
    $withoutClientTypeGuide = $withoutClientType['guides'][0] ?? [];
    $assert(
        '4.17 Omitir machine_type no apaga el aviso de una máquina perecedera',
        $resWithoutClientType->getStatusCode() === 200
            && ($withoutClientTypeGuide['breakdown_type'] ?? null) === 'OTHER'
            && ($withoutClientTypeGuide['sanitary_cold_warning'] ?? null) === true
            && ($withoutClientType['sanitary_cold_warning_for_machine'] ?? null) === true
    );

    $resMachineAuthority = $call('GET', '/api/troubleshooting-guides/by-model', [], $techToken, [
        'machine_model' => 'Sanden Vendo G-Drink',
        'breakdown_type' => 'OTHER',
        'machine_id' => $sandenMachineId,
        'machine_type' => 'HOT_DRINKS',
    ]);
    $machineAuthorityGuide = $data($resMachineAuthority)['guides'][0] ?? [];
    $assert(
        '4.18 machine_id prevalece sobre un machine_type falso del cliente',
        $resMachineAuthority->getStatusCode() === 200
            && ($machineAuthorityGuide['sanitary_cold_warning'] ?? null) === true
    );

    $resNonPerishableLie = $call('GET', '/api/troubleshooting-guides/by-model', [], $techToken, [
        'machine_model' => 'Bianchi Gaia Espresso',
        'breakdown_type' => 'OTHER',
        'machine_id' => $bianchiMachineId,
        'machine_type' => 'PERISHABLE_FOOD',
    ]);
    $nonPerishableLie = $data($resNonPerishableLie);
    $nonPerishableLieGuide = $nonPerishableLie['guides'][0] ?? [];
    $assert(
        '4.19 Un machine_type falso no enciende el aviso de una máquina no perecedera',
        $resNonPerishableLie->getStatusCode() === 200
            && ($nonPerishableLieGuide['sanitary_cold_warning'] ?? true) === false
            && ($nonPerishableLie['sanitary_cold_warning_for_machine'] ?? true) === false
    );
} catch (Throwable $exception) {
    $crash = $exception;
}

echo "\n======================================================================\n";
echo ' RESULTADO: ' . ($assertions - $failures) . '/' . $assertions . " aserciones en verde\n";
echo "======================================================================\n";

if ($pdo instanceof PDO) {
    try {
        PostgresFixtureBuilder::dropIsolationSchema($pdo, GUIDES_API_TEST_SCHEMA);
    } catch (Throwable) {
        // La limpieza no debe ocultar el veredicto de la suite.
    }
}

if ($crash !== null) {
    fwrite(STDERR, "\n[CRASH] " . $crash->getMessage() . "\n" . $crash->getTraceAsString() . "\n");
    exit(1);
}

exit($failures > 0 ? 1 : 0);
