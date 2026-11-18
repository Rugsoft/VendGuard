<?php

declare(strict_types=1);

/**
 * VendGuard — Pruebas de humo, funcionales y E2E del Módulo 35
 * (Recaudación, Cambio y Arqueo de Caja en Cámara)
 *
 * Ejecuta contra el servidor HTTP local (http://127.0.0.1:8000) con el contrato
 * del banco de la batería. Recorre la superficie completa de la rama
 * `feat/cash-collection-vault-audit`:
 *
 * 1. Humo de servidor, estáticos y PWA (JS ESM, cola Offline, service worker).
 * 2. Autenticación de los cuatro perfiles y segregación RBAC (Art. V.3/V.4).
 * 3. Bootstrap móvil del reponedor bajo Arqueo Ciego (C2).
 * 4. Captura independiente: alta, idempotencia, precinto, regresión de contador,
 *    tope de conserjería y recibo limpio.
 * 5. Visita in situ con recaudación embebida y cerrojo térmico (D1, Art. II).
 * 6. Libro de Coordinación: 6 KPIs, filtros, detalle y exportación CSV (QA 3, QA 4).
 * 7. Caja Central: recepción en cámara, arqueo ciego, descuadre justificado e
 *    inmutabilidad terminal (RF-04, D2, D4, Art. V.1).
 * 8. Volcado de conflicto Offline en auditoría (C4, Art. III.3).
 *
 * Es un artefacto de diagnóstico: lo que certifica el módulo en la batería son
 * `tests/integration/CashCollectionVaultAuditApiTest.php`,
 * `CashCollectionVaultAuditConstitutionalTest.php` y
 * `CashCollectionConcurrencyTest.php`. Este recorrido mide el mismo contrato por
 * el transporte real del servidor de desarrollo y limpia las filas operativas que crea.
 *
 * Vive en `tests/diagnostics/` y no en la batería a propósito: `tests/run_all.php`
 * descubre sus suites con glob sobre `unit/`, `integration/` y `Manual/`, y una suite
 * más ahí movería los totales que `DocMetricsGuard` audita. Es el hermano de
 * `PendingInfoSmokeWorkflowDiagnostic.php` (Módulo 11) y
 * `RestockerSmokeAndE2ERunner.php` (Módulo 31).
 *
 * Ejecución —el motor del banco tiene que estar arriba y nadie más usando el 8000—:
 *
 *     set -a; source deploy/local/postgres/.env.battery; set +a
 *     VENDGUARD_DEV_SITE_KEYS=1 php -S 127.0.0.1:8000 -t public public/index.php
 *     php tests/diagnostics/CashCollectionVaultAuditSmokeE2E.php
 *
 * Qué deja atrás: borra por la ventana de purga del arnés las capturas y visitas
 * marcadas con `ccv35-` (las suyas y las de una pasada anterior), pero **no** las filas
 * de `audit_log`: son inmutables por Art. III.3 y cada pasada deja rastro de los hechos
 * auditados. Por eso el catálogo del visor tiene que cubrir también
 * `CASH_COLLECTION_SEALED`, `CASH_COLLECTION_DELIVERED_TO_VAULT`,
 * `CASH_COLLECTION_RECONCILED` y `OFFLINE_CASH_COLLECTION_CONFLICT_DUMP` —lo vigila
 * `tests/unit/AuditActionCatalogTest.php` contra el propio registro persistido—.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;

const BASE_URL = 'http://127.0.0.1:8000';

$green = "\033[32m";
$red = "\033[31m";
$cyan = "\033[36m";
$bold = "\033[1m";
$reset = "\033[0m";

$assertions = 0;
$failures = 0;
$errorsList = [];

/**
 * @return array{status: int, body: array<string, mixed>, raw: string, contentType: string}
 */
function http(string $method, string $path, ?array $payload = null, ?string $token = null, array $headers = []): array
{
    $ch = curl_init(BASE_URL . $path);
    $requestHeaders = $headers === [] ? ['Accept: application/json'] : $headers;
    if ($token !== null) {
        $requestHeaders[] = 'Authorization: Bearer ' . $token;
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => 15,
    ];

    if ($payload !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    $options[CURLOPT_HTTPHEADER] = $requestHeaders;
    curl_setopt_array($ch, $options);

    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $decoded = json_decode($raw, true);

    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : [],
        'raw' => $raw,
        'contentType' => $contentType,
    ];
}

/**
 * @param array<string, mixed> $response
 * @return array<string, mixed>
 */
function dataOf(array $response): array
{
    $data = $response['body']['data'] ?? [];
    return is_array($data) ? $data : [];
}

/**
 * @param array<string, mixed> $response
 */
function errorCode(array $response): string
{
    $error = $response['body']['error'] ?? [];

    return (string)($error['code'] ?? $response['body']['code'] ?? '');
}

$assert = function (string $label, bool $condition, string $detail = '', string $importance = 'ALTA')
    use (&$assertions, &$failures, &$errorsList, $green, $red, $reset): void {
    $assertions++;
    if ($condition) {
        echo "  {$green}[PASS]{$reset} {$label}\n";
        return;
    }

    echo "  {$red}[FAIL]{$reset} {$label}\n";
    if ($detail !== '') {
        echo "         Detalle: {$detail}\n";
    }
    $failures++;
    $errorsList[] = ['step' => $label, 'detail' => $detail, 'importance' => $importance];
};

echo "{$bold}======================================================================{$reset}\n";
echo "{$bold} VendGuard · Humo + Funcionales + E2E · Módulo 35 (HTTP real){$reset}\n";
echo "{$bold}======================================================================{$reset}\n\n";

$pdo = ConnectionFactory::getConnection();

try {
    // ─────────────────────────────────────────────────────────────────────
    // 1. Humo de servidor, estáticos y PWA
    // ─────────────────────────────────────────────────────────────────────
    echo "{$cyan}--- 1. Humo de servidor, estáticos y PWA ---{$reset}\n";

    $health = http('GET', '/api/health');
    $assert(
        '1.1 GET /api/health responde 200 operational',
        $health['status'] === 200 && (dataOf($health)['status'] ?? '') === 'operational',
        'status=' . $health['status']
    );

    $status = http('GET', '/api/status');
    $assert('1.2 GET /api/status responde 200', $status['status'] === 200, 'status=' . $status['status']);

    $restockerView = http('GET', '/assets/js/components/RestockerRouteView.js');
    $assert(
        '1.3 RestockerRouteView.js publica la captura y el recibo de recaudación',
        $restockerView['status'] === 200
            && str_contains($restockerView['raw'], 'cash-collections')
            && str_contains($restockerView['raw'], 'reception-receipt'),
        'status=' . $restockerView['status']
    );

    $offlineQueue = http('GET', '/assets/js/utils/CashCollectionOfflineQueue.js');
    $assert(
        '1.4 CashCollectionOfflineQueue.js existe y usa IndexedDB (05.1)',
        $offlineQueue['status'] === 200 && stripos($offlineQueue['raw'], 'indexedDB') !== false,
        'status=' . $offlineQueue['status']
    );

    $coordinatorTab = http('GET', '/assets/js/components/CoordinatorProductLoadingTab.js');
    $assert(
        '1.5 CoordinatorProductLoadingTab.js incluye la sub-vista de recaudación y el CSV',
        $coordinatorTab['status'] === 200
            && str_contains($coordinatorTab['raw'], 'exportCashCollectionsCsv')
            && str_contains($coordinatorTab['raw'], 'vault_counted_eur'),
        'status=' . $coordinatorTab['status']
    );

    $apiJs = http('GET', '/assets/js/api.js');
    $assert(
        '1.6 api.js declara los endpoints del reponedor y de coordinación',
        $apiJs['status'] === 200
            && str_contains($apiJs['raw'], '/restocker/cash-collections')
            && str_contains($apiJs['raw'], '/coordinator/cash-collections'),
        'status=' . $apiJs['status']
    );

    $serviceWorker = http('GET', '/sw.js');
    $assert(
        '1.7 sw.js precachea la cola Offline de recaudación',
        $serviceWorker['status'] === 200 && str_contains($serviceWorker['raw'], 'CashCollectionOfflineQueue'),
        'status=' . $serviceWorker['status']
    );

    $styles = http('GET', '/assets/css/styles.css');
    $assert('1.8 styles.css responde 200', $styles['status'] === 200, 'status=' . $styles['status']);

    $manifest = http('GET', '/manifest.webmanifest');
    $assert('1.9 manifest.webmanifest responde 200', $manifest['status'] === 200, 'status=' . $manifest['status']);

    // ─────────────────────────────────────────────────────────────────────
    // 2. Autenticación y segregación RBAC
    // ─────────────────────────────────────────────────────────────────────
    echo "\n{$cyan}--- 2. Autenticación y segregación RBAC ---{$reset}\n";

    $coordLogin = http('POST', '/api/auth/login', [
        'email' => 'coordinacion@vendguard.internal',
        'password' => 'Password123!',
    ]);
    $coordToken = (string)(dataOf($coordLogin)['token'] ?? '');
    $assert(
        '2.1 Login de Coordinación responde 200 con rol COORDINATOR',
        $coordLogin['status'] === 200
            && (dataOf($coordLogin)['user']['role'] ?? '') === 'COORDINATOR'
            && $coordToken !== '',
        'status=' . $coordLogin['status']
    );

    $repoLogin = http('POST', '/api/auth/login', [
        'email' => 'lucia.repostaje@vendguard.internal',
        'password' => 'Password123!',
    ]);
    $repoToken = (string)(dataOf($repoLogin)['token'] ?? '');
    $assert(
        '2.2 Login de Reponedora responde 200 con rol RESTOCKER y REP-01',
        $repoLogin['status'] === 200
            && (dataOf($repoLogin)['user']['role'] ?? '') === 'RESTOCKER'
            && (dataOf($repoLogin)['user']['operator_code'] ?? '') === 'REP-01'
            && $repoToken !== '',
        'status=' . $repoLogin['status']
    );

    $techLogin = http('POST', '/api/auth/login', [
        'email' => 'jordi.ruta@vendguard.internal',
        'password' => 'Password123!',
    ]);
    $techToken = (string)(dataOf($techLogin)['token'] ?? '');
    $assert(
        '2.3 Login de Técnico SAT responde 200 con rol TECHNICIAN',
        $techLogin['status'] === 200 && (dataOf($techLogin)['user']['role'] ?? '') === 'TECHNICIAN',
        'status=' . $techLogin['status']
    );

    $siteLogin = http('POST', '/api/auth/site-login', [
        'site_code' => 'SEDE-BCN-01',
        'access_code' => SeedRunner::devAccessCode('SEDE-BCN-01'),
    ]);
    $siteToken = (string)(dataOf($siteLogin)['token'] ?? '');
    $assert('2.4 Login de Sede responde 200', $siteLogin['status'] === 200 && $siteToken !== '', 'status=' . $siteLogin['status']);

    $anon = http('GET', '/api/restocker/cash-collections');
    $assert('2.5 Anónimo bloqueado en la captura móvil con 401', $anon['status'] === 401, 'status=' . $anon['status']);

    $techOnMobile = http('GET', '/api/restocker/cash-collections', null, $techToken);
    $assert('2.6 Técnico SAT bloqueado en la captura móvil con 403', $techOnMobile['status'] === 403, 'status=' . $techOnMobile['status']);

    $coordOnMobile = http('GET', '/api/restocker/cash-collections', null, $coordToken);
    $assert('2.7 Coordinación bloqueada en la captura móvil con 403', $coordOnMobile['status'] === 403, 'status=' . $coordOnMobile['status']);

    $repoOnBook = http('GET', '/api/coordinator/cash-collections', null, $repoToken);
    $assert('2.8 Reponedora bloqueada en el libro de Caja Central con 403', $repoOnBook['status'] === 403, 'status=' . $repoOnBook['status']);

    // La credencial de sede no es un usuario interno: el middleware la rechaza como no
    // autenticada (401) antes de evaluar el rol. Cualquiera de los dos códigos deja la
    // puerta cerrada; lo que el contrato prohíbe es el 200.
    $siteOnBook = http('GET', '/api/coordinator/cash-collections', null, $siteToken);
    $assert(
        '2.9 Sede sin acceso al libro de Caja Central (401/403)',
        in_array($siteOnBook['status'], [401, 403], true),
        'status=' . $siteOnBook['status']
    );

    $anonOnBook = http('GET', '/api/coordinator/cash-collections');
    $assert('2.10 Anónimo bloqueado en el libro de Caja Central con 401', $anonOnBook['status'] === 401, 'status=' . $anonOnBook['status']);

    // ─────────────────────────────────────────────────────────────────────
    // 3. Bootstrap móvil bajo Arqueo Ciego (C2)
    // ─────────────────────────────────────────────────────────────────────
    echo "\n{$cyan}--- 3. Bootstrap móvil y Arqueo Ciego ---{$reset}\n";

    $myRoute = http('GET', '/api/restocker/my-route', null, $repoToken);
    $myRouteData = dataOf($myRoute);
    $assert(
        '3.1 my-route responde 200 con los contenedores de efectivo',
        $myRoute['status'] === 200
            && array_key_exists('machines', $myRouteData)
            && array_key_exists('recent_cash_collections', $myRouteData)
            && array_key_exists('cash_route_summary', $myRouteData)
            && array_key_exists('pending_reception_refunds', $myRouteData),
        'status=' . $myRoute['status']
    );

    $routeMachines = $myRouteData['machines'] ?? [];
    $routeMachineOk = is_array($routeMachines) && $routeMachines !== [];
    if ($routeMachineOk) {
        foreach ($routeMachines as $machine) {
            if (!array_key_exists('has_physical_reception', $machine) || !array_key_exists('has_prior_cash_collection', $machine)) {
                $routeMachineOk = false;
                break;
            }
        }
    }
    $assert(
        '3.2 Cada máquina de la ruta declara conserjería física e historial de bolsa',
        $routeMachineOk,
        'machines=' . count(is_array($routeMachines) ? $routeMachines : [])
    );

    $machineId = (int)($routeMachines[0]['id'] ?? 0);
    $secondMachineId = (int)($routeMachines[1]['id'] ?? 0);
    $assert(
        '3.3 La ruta devuelve al menos dos máquinas operables',
        $machineId > 0 && $secondMachineId > 0,
        "machine={$machineId} second={$secondMachineId}"
    );

    $repoHistory = http('GET', '/api/restocker/cash-collections', null, $repoToken);
    $repoHistoryData = dataOf($repoHistory);
    $assert(
        '3.4 El historial del reponedor publica bolsas y resumen',
        $repoHistory['status'] === 200
            && array_key_exists('cash_collections', $repoHistoryData)
            && array_key_exists('summary', $repoHistoryData),
        'status=' . $repoHistory['status']
    );

    $processedVisible = false;
    foreach (($repoHistoryData['cash_collections'] ?? []) as $collection) {
        if (($collection['status'] ?? '') === 'PROCESSED_IN_VAULT') {
            $processedVisible = true;
        }
    }
    $assert(
        '3.5 Una bolsa arqueada se publica como PROCESSED_IN_VAULT (estado externo)',
        $processedVisible,
        'estados=' . implode(',', array_map(
            static fn (array $c): string => (string)($c['status'] ?? '?'),
            is_array($repoHistoryData['cash_collections'] ?? null) ? $repoHistoryData['cash_collections'] : []
        ))
    );

    $summary = $repoHistoryData['summary'] ?? [];
    $assert(
        '3.6 El resumen del reponedor cuenta las bolsas listadas',
        (int)($summary['total_collections_count'] ?? -1) === count($repoHistoryData['cash_collections'] ?? []),
        'summary=' . json_encode($summary, JSON_UNESCAPED_UNICODE)
    );

    $privateKeys = ['expected_cashbox_eur', 'previous_sales_counter_reading', 'vault_counted_eur', 'discrepancy_eur', 'discrepancy_justification'];
    $applyLeaks = static function (array $keys) use ($privateKeys): array {
        return array_values(array_intersect($privateKeys, $keys));
    };
    $historyLeaks = $applyLeaks(array_keys($repoHistoryData));
    $routeLeaks = $applyLeaks(array_keys($myRouteData));
    $assert(
        '3.7 El bootstrap y el historial móvil no filtran datos del arqueo ciego (C2)',
        $historyLeaks === [] && $routeLeaks === [],
        'history=' . implode(',', $historyLeaks) . ' route=' . implode(',', $routeLeaks)
    );

    // ─────────────────────────────────────────────────────────────────────
    // 4. Captura independiente en ruta
    // ─────────────────────────────────────────────────────────────────────
    echo "\n{$cyan}--- 4. Captura independiente, precinto e idempotencia ---{$reset}\n";

    $stamp = (string)time();
    $mutationId = 'ccv35-smoke-' . $stamp . '-0001';
    $sealCode = 'BAG-CCV35-' . $stamp . '-01';

    $capturePayload = [
        'client_mutation_id' => $mutationId,
        'bag_seal_code' => $sealCode,
        'sales_counter_reading' => 120.00,
        'change_tubes_loaded_eur' => 10.00,
        'reception_cash_delivered_eur' => 5.00,
        'receptionist_name' => 'Conserje E2E Módulo 35',
        'route_notes' => 'Bolsa precintada y entrega parcial en conserjería durante el recorrido E2E.',
    ];

    $created = http('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', $capturePayload, $repoToken);
    $createdData = dataOf($created);
    $collectionId = (int)($createdData['id'] ?? 0);
    $assert(
        '4.1 La captura responde 201 SEALED_IN_ROUTE sin datos privados',
        $created['status'] === 201
            && $collectionId > 0
            && ($createdData['status'] ?? '') === 'SEALED_IN_ROUTE'
            && ($createdData['idempotent_replay'] ?? null) === false
            && $applyLeaks(array_keys($createdData)) === [],
        'status=' . $created['status'] . ' code=' . errorCode($created)
    );

    $replay = http('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', $capturePayload, $repoToken);
    $replayData = dataOf($replay);
    $assert(
        '4.2 El reenvío de la misma mutación responde 200 idempotent_replay',
        $replay['status'] === 200
            && (int)($replayData['id'] ?? 0) === $collectionId
            && ($replayData['idempotent_replay'] ?? false) === true,
        'status=' . $replay['status']
    );

    $duplicateSeal = http('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', array_merge($capturePayload, [
        'client_mutation_id' => 'ccv35-smoke-' . $stamp . '-0002',
    ]), $repoToken);
    $assert(
        '4.3 Un precinto ya consumido responde 409 BAG_SEAL_CODE_ALREADY_USED',
        $duplicateSeal['status'] === 409 && errorCode($duplicateSeal) === 'BAG_SEAL_CODE_ALREADY_USED',
        'status=' . $duplicateSeal['status'] . ' code=' . errorCode($duplicateSeal)
    );

    $regression = http('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', array_merge($capturePayload, [
        'client_mutation_id' => 'ccv35-smoke-' . $stamp . '-0003',
        'bag_seal_code' => 'BAG-CCV35-' . $stamp . '-03',
        'sales_counter_reading' => 100.00,
        'reception_cash_delivered_eur' => 0.00,
        'receptionist_name' => null,
    ]), $repoToken);
    $assert(
        '4.4 Una lectura inferior de contador responde 422 SALES_COUNTER_REGRESSION',
        $regression['status'] === 422 && errorCode($regression) === 'SALES_COUNTER_REGRESSION',
        'status=' . $regression['status'] . ' code=' . errorCode($regression)
    );

    $overLimit = http('POST', '/api/restocker/machines/' . $machineId . '/cash-collections', array_merge($capturePayload, [
        'client_mutation_id' => 'ccv35-smoke-' . $stamp . '-0004',
        'bag_seal_code' => 'BAG-CCV35-' . $stamp . '-04',
        'sales_counter_reading' => 130.00,
        'reception_cash_delivered_eur' => 12.00,
    ]), $repoToken);
    $assert(
        '4.5 Una entrega en conserjería superior a 10,00 € responde 422 RECEPTION_CASH_LIMIT_EXCEEDED',
        $overLimit['status'] === 422 && errorCode($overLimit) === 'RECEPTION_CASH_LIMIT_EXCEEDED',
        'status=' . $overLimit['status'] . ' code=' . errorCode($overLimit)
    );

    $receipt = http('GET', '/api/restocker/cash-collections/' . $collectionId . '/reception-receipt', null, $repoToken);
    $receiptRaw = $receipt['raw'];
    $assert(
        '4.6 El recibo de conserjería es limpio (sin bolsa, PIN ni esperado)',
        $receipt['status'] === 200
            && !str_contains($receiptRaw, 'bag_seal_code')
            && !str_contains($receiptRaw, 'pickup_pin')
            && !str_contains($receiptRaw, 'expected_cashbox_eur'),
        'status=' . $receipt['status']
    );

    $machineHistory = http('GET', '/api/restocker/cash-collections?machine_id=' . $machineId, null, $repoToken);
    $machineHistoryData = dataOf($machineHistory);
    $foundCreated = false;
    foreach (($machineHistoryData['cash_collections'] ?? []) as $collection) {
        if ((int)($collection['id'] ?? 0) === $collectionId) {
            $foundCreated = true;
        }
    }
    $assert(
        '4.7 El historial filtrado por máquina incluye la bolsa recién sellada',
        $machineHistory['status'] === 200 && $foundCreated,
        'status=' . $machineHistory['status']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 5. Visita in situ con recaudación embebida
    // ─────────────────────────────────────────────────────────────────────
    echo "\n{$cyan}--- 5. Visita in situ y cerrojo térmico (D1, Art. II) ---{$reset}\n";

    $visit = http('POST', '/api/restocker/machines/' . $secondMachineId . '/visits', [
        'status' => 'COMPLETED',
        'client_mutation_id' => 'ccv35-visit-' . $stamp . '-0001',
        'cabin_temp_celsius' => 3.0,
        // Documento canónico de cinco puntos: COMBO exige los cuatro de cafetera y el
        // resto de tipologías los dos de cabina. Marcarlos todos deja la visita limpia
        // para cualquier tipo de máquina (D3, EARS 03.1).
        'hygiene_checklist_json' => [
            'coffee_grounds_emptied' => true,
            'liquid_waste_bucket_cleaned' => true,
            'mixers_and_ducts_rinsed' => true,
            'dispensing_tray_and_glass_cleaned' => true,
            'cabin_spills_checked' => true,
        ],
        'items' => [],
        'cash_collection' => [
            'client_mutation_id' => 'ccv35-visit-cash-' . $stamp . '-0001',
            'bag_seal_code' => 'BAG-CCV35-' . $stamp . '-10',
            'sales_counter_reading' => 90.00,
            'change_tubes_loaded_eur' => 5.00,
        ],
    ], $repoToken);
    $visitData = dataOf($visit);
    $visitId = (int)($visitData['id'] ?? 0);
    $visitCollectionId = (int)($visitData['cash_collection']['id'] ?? 0);
    $assert(
        '5.1 La visita COMPLETED sella su recaudación en la misma transacción',
        $visit['status'] === 201
            && $visitId > 0
            && (int)($visitData['cash_collection']['restock_visit_id'] ?? 0) === $visitId
            && ($visitData['cash_collection']['status'] ?? '') === 'SEALED_IN_ROUTE',
        'status=' . $visit['status'] . ' code=' . errorCode($visit)
    );

    $thermal = http('POST', '/api/restocker/machines/' . $machineId . '/visits', [
        'status' => 'ABORTED_THERMAL_BREACH',
        'client_mutation_id' => 'ccv35-visit-thermal-' . $stamp . '-0001',
        'cabin_temp_celsius' => 9.5,
        'cash_collection' => [
            'client_mutation_id' => 'ccv35-thermal-cash-' . $stamp . '-0001',
            'bag_seal_code' => 'BAG-CCV35-' . $stamp . '-11',
            'sales_counter_reading' => 130.00,
        ],
    ], $repoToken);
    $assert(
        '5.2 Una visita abortada por frío no admite recaudación (422 THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN)',
        $thermal['status'] === 422 && errorCode($thermal) === 'THERMAL_ABORT_CASH_COLLECTION_FORBIDDEN',
        'status=' . $thermal['status'] . ' code=' . errorCode($thermal)
    );

    $visitDetail = http('GET', '/api/coordinator/restock-visits/' . $visitId, null, $coordToken);
    $visitDetailData = dataOf($visitDetail);
    $assert(
        '5.3 El detalle de visita de Coordinación publica cash_collection_summary sin datos privados',
        $visitDetail['status'] === 200
            && (int)($visitDetailData['cash_collection_summary']['id'] ?? 0) > 0
            && !str_contains((string)json_encode($visitDetailData['cash_collection_summary'] ?? []), 'expected_cashbox_eur'),
        'status=' . $visitDetail['status']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 6. Libro de Coordinación, KPIs, detalle y CSV
    // ─────────────────────────────────────────────────────────────────────
    echo "\n{$cyan}--- 6. Libro de Caja Central, KPIs y exportación (QA 3, QA 4) ---{$reset}\n";

    $book = http('GET', '/api/coordinator/cash-collections', null, $coordToken);
    $bookData = dataOf($book);
    $kpiKeys = [
        'pending_vault_bags_count',
        'reconciled_ok_count',
        'discrepancy_flagged_count',
        'total_vault_counted_eur',
        'net_discrepancy_eur',
        'total_route_cash_deployed_eur',
    ];
    $missingKpis = array_values(array_diff($kpiKeys, array_keys($bookData['kpis'] ?? [])));
    $assert(
        '6.1 El libro responde 200 con exactamente los 6 KPIs (QA 3)',
        $book['status'] === 200
            && count($bookData['kpis'] ?? []) === 6
            && $missingKpis === [],
        'status=' . $book['status'] . ' missing=' . implode(',', $missingKpis)
    );

    $bookFiltered = http('GET', '/api/coordinator/cash-collections?bag_seal_code=' . rawurlencode($sealCode), null, $coordToken);
    $bookFilteredData = dataOf($bookFiltered);
    $assert(
        '6.2 El filtro por precinto devuelve una única bolsa y su KPI se recalcula',
        $bookFiltered['status'] === 200
            && count($bookFilteredData['cash_collections'] ?? []) === 1
            && (int)($bookFilteredData['kpis']['pending_vault_bags_count'] ?? -1) === 1,
        'status=' . $bookFiltered['status'] . ' rows=' . count($bookFilteredData['cash_collections'] ?? [])
    );

    $coordinatorDetail = http('GET', '/api/coordinator/cash-collections/' . $collectionId, null, $coordToken);
    $detailData = dataOf($coordinatorDetail);
    $expectedCashbox = (float)($detailData['expected_cashbox_eur'] ?? -1);
    $assert(
        '6.3 El detalle de Caja Central publica el esperado y el snapshot de máquina/reponedor',
        $coordinatorDetail['status'] === 200
            && $expectedCashbox === 120.00
            && ($detailData['machine_code_snapshot'] ?? '') !== ''
            && ($detailData['restocker_code_snapshot'] ?? '') === 'REP-01',
        'status=' . $coordinatorDetail['status'] . ' expected=' . $expectedCashbox
    );

    $csv = http('GET', '/api/coordinator/cash-collections/export-csv', null, $coordToken);
    $assert(
        '6.4 La exportación CSV lleva BOM, separador y cabecera canónica (QA 4)',
        $csv['status'] === 200
            && str_starts_with($csv['raw'], "\xEF\xBB\xBF")
            && str_contains($csv['raw'], 'Código;"Fecha (Europe/Madrid)"')
            && str_contains($csv['contentType'], 'text/csv')
            && str_contains($csv['raw'], $sealCode),
        'status=' . $csv['status'] . ' type=' . $csv['contentType']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 7. Caja Central: recepción, arqueo e inmutabilidad
    // ─────────────────────────────────────────────────────────────────────
    echo "\n{$cyan}--- 7. Recepción en cámara, arqueo ciego e inmutabilidad ---{$reset}\n";

    $delivered = http('POST', '/api/coordinator/cash-collections/' . $collectionId . '/deliver-to-vault', null, $coordToken);
    $assert(
        '7.1 La recepción en cámara sella DELIVERED_TO_VAULT (RF-04.1)',
        $delivered['status'] === 200 && (dataOf($delivered)['status'] ?? '') === 'DELIVERED_TO_VAULT',
        'status=' . $delivered['status'] . ' code=' . errorCode($delivered)
    );

    $reconciled = http('POST', '/api/coordinator/cash-collections/' . $collectionId . '/reconcile', [
        'vault_counted_eur' => 120.00,
    ], $coordToken);
    $reconciledData = dataOf($reconciled);
    $assert(
        '7.2 El arqueo exacto sella RECONCILED_OK con descuadre 0,00 €',
        $reconciled['status'] === 200
            && ($reconciledData['status'] ?? '') === 'RECONCILED_OK'
            && (float)($reconciledData['discrepancy_eur'] ?? 1) === 0.0,
        'status=' . $reconciled['status'] . ' code=' . errorCode($reconciled)
    );

    $reReconciled = http('POST', '/api/coordinator/cash-collections/' . $collectionId . '/reconcile', [
        'vault_counted_eur' => 120.00,
    ], $coordToken);
    $assert(
        '7.3 Un segundo arqueo responde 409 CASH_COLLECTION_ALREADY_RECONCILED',
        $reReconciled['status'] === 409 && errorCode($reReconciled) === 'CASH_COLLECTION_ALREADY_RECONCILED',
        'status=' . $reReconciled['status'] . ' code=' . errorCode($reReconciled)
    );

    $coordinatorReceipt = http('GET', '/api/coordinator/cash-collections/' . $collectionId . '/reception-receipt', null, $coordToken);
    $assert(
        '7.4 Coordinación obtiene el mismo recibo limpio de conserjería',
        $coordinatorReceipt['status'] === 200
            && !str_contains($coordinatorReceipt['raw'], 'bag_seal_code')
            && !str_contains($coordinatorReceipt['raw'], 'pickup_pin'),
        'status=' . $coordinatorReceipt['status']
    );

    // Descuadre justificado sobre la bolsa nacida en la visita in situ.
    $flaggedWithoutNote = http('POST', '/api/coordinator/cash-collections/' . $visitCollectionId . '/reconcile', [
        'vault_counted_eur' => 85.00,
    ], $coordToken);
    $assert(
        '7.5 Un descuadre sin justificación responde 422 DISCREPANCY_JUSTIFICATION_REQUIRED',
        $flaggedWithoutNote['status'] === 422
            && errorCode($flaggedWithoutNote) === 'DISCREPANCY_JUSTIFICATION_REQUIRED',
        'status=' . $flaggedWithoutNote['status'] . ' code=' . errorCode($flaggedWithoutNote)
    );

    $flagged = http('POST', '/api/coordinator/cash-collections/' . $visitCollectionId . '/reconcile', [
        'vault_counted_eur' => 85.00,
        'discrepancy_justification' => '=SUM(1,2) descuadre real de cinco euros por monedas atascadas',
    ], $coordToken);
    $flaggedData = dataOf($flagged);
    $assert(
        '7.6 El descuadre justificado se sella DISCREPANCY_FLAGGED con su importe exacto',
        $flagged['status'] === 200
            && ($flaggedData['status'] ?? '') === 'DISCREPANCY_FLAGGED'
            && (float)($flaggedData['discrepancy_eur'] ?? 0) === -5.0,
        'status=' . $flagged['status'] . ' code=' . errorCode($flagged)
    );

    $csvFlagged = http('GET', '/api/coordinator/cash-collections/export-csv', null, $coordToken);
    $assert(
        '7.7 El CSV neutraliza la fórmula de la justificación (QA 4)',
        $csvFlagged['status'] === 200 && str_contains($csvFlagged['raw'], "'=SUM(1,2)"),
        'status=' . $csvFlagged['status']
    );

    // ─────────────────────────────────────────────────────────────────────
    // 8. Conflicto Offline en auditoría
    // ─────────────────────────────────────────────────────────────────────
    echo "\n{$cyan}--- 8. Cola Offline: volcado de conflicto (C4) ---{$reset}\n";

    $conflict = http('POST', '/api/restocker/cash-collections/conflict-dump', [
        'machine_id' => $machineId,
        'client_mutation_id' => 'ccv35-conflict-' . $stamp . '-0001',
        'bag_seal_code' => $sealCode,
        'reason' => 'La captura offline llegó después de consumirse el precinto.',
    ], $repoToken);
    $conflictData = dataOf($conflict);
    $assert(
        '8.1 El conflicto offline se archiva en auditoría (OFFLINE_CASH_COLLECTION_CONFLICT_DUMP)',
        $conflict['status'] === 201
            && ($conflictData['logged'] ?? false) === true
            && ($conflictData['action'] ?? '') === 'OFFLINE_CASH_COLLECTION_CONFLICT_DUMP',
        'status=' . $conflict['status'] . ' code=' . errorCode($conflict)
    );
} catch (Throwable $exception) {
    $failures++;
    $errorsList[] = [
        'step' => 'Excepción no controlada',
        'detail' => $exception::class . ': ' . $exception->getMessage(),
        'importance' => 'CRÍTICA',
    ];
    fwrite(STDERR, "\n[ERROR] " . $exception::class . ': ' . $exception->getMessage() . "\n");
} finally {
    // Limpieza dirigida: sólo las filas creadas por este recorrido, a través de la
    // ventana de purga del arnés (las capturas y las visitas son inmutables).
    try {
        // La marca de este recorrido es el prefijo `ccv35-` del `client_mutation_id`:
        // limpia lo creado ahora y cualquier huérfano de una pasada anterior, sin tocar
        // las semillas ni las recaudaciones de otros consumidores del banco.
        TestDataCleaner::withPurgeWindow($pdo, static function () use ($pdo): void {
            $pdo->exec("DELETE FROM machine_cash_collections WHERE client_mutation_id LIKE 'ccv35-%'");
            $pdo->exec(
                "DELETE FROM machine_restock_visit_items WHERE visit_id IN (SELECT id FROM machine_restock_visits WHERE client_mutation_id LIKE 'ccv35-%')"
            );
            $pdo->exec("DELETE FROM machine_restock_visits WHERE client_mutation_id LIKE 'ccv35-%'");
        });
        TestDataCleaner::setSessionPurgeSignal($pdo, false);
    } catch (Throwable $cleanupException) {
        fwrite(STDERR, '[LIMPIEZA] ' . $cleanupException->getMessage() . "\n");
    }
}

echo "\n{$bold}======================================================================{$reset}\n";
echo " Total Aserciones: {$assertions} | Aprobadas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " {$green}RESULTADO: 100% EN VERDE. MÓDULO 35 VERIFICADO POR HTTP REAL.{$reset}\n";
} else {
    echo " {$red}RESULTADO: {$failures} FALLO(S) DETECTADO(S).{$reset}\n";
    foreach ($errorsList as $error) {
        echo "   - [{$error['importance']}] {$error['step']}: {$error['detail']}\n";
    }
}
echo "{$bold}======================================================================{$reset}\n";

exit($failures === 0 ? 0 : 1);
