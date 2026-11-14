<?php

declare(strict_types=1);

/**
 * VendGuard — Batería de Pruebas Funcionales, de Humo y E2E para el Módulo 33
 * (Carga de Producto, Picking en Frío y Ciclo Móvil 1-Tap)
 *
 * Ejecuta contra el servidor HTTP en local (http://127.0.0.1:8000).
 */

require_once __DIR__ . '/../bootstrap.php';

function req(string $method, string $url, ?array $data = null, ?string $token = null): array
{
    $ch = curl_init('http://127.0.0.1:8000' . $url);
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode($raw, true), 'raw' => $raw];
}

$assertions = 0;
$failures = 0;
$errors = [];

$assert = function (string $label, bool $condition, string $detail = '', string $importance = 'ALTA') use (&$assertions, &$failures, &$errors): void {
    $assertions++;
    if ($condition) {
        echo "  \033[32m[PASS]\033[0m {$label}\n";
    } else {
        echo "  \033[31m[FAIL]\033[0m {$label}" . ($detail ? " · Detalle: {$detail}" : '') . "\n";
        $failures++;
        $errors[] = [
            'test' => $label,
            'detail' => $detail,
            'importance' => $importance
        ];
    }
};

echo "======================================================================\n";
echo " VendGuard: Pruebas Funcionales, de Humo y E2E · Módulo 33 (HTTP Real)\n";
echo "======================================================================\n\n";

// 1. Humo de servidor y estáticos
echo "--- 1. Humo de servidor y recursos del Módulo 33 ---\n";
$health = req('GET', '/api/health');
$assert('1.1 GET /api/health responde HTTP 200 operational', $health['code'] === 200 && ($health['json']['data']['status'] ?? '') === 'operational');

$componentLoading = req('GET', '/assets/js/components/CoordinatorProductLoadingTab.js');
$assert('1.2 Componente CoordinatorProductLoadingTab.js responde HTTP 200', $componentLoading['code'] === 200 && str_contains($componentLoading['raw'], 'CoordinatorProductLoadingTab'));

$componentRestocker = req('GET', '/assets/js/components/RestockerRouteView.js');
$assert('1.3 Componente RestockerRouteView.js responde HTTP 200', $componentRestocker['code'] === 200 && str_contains($componentRestocker['raw'], 'RestockerRouteView'));

// 2. Autenticación de roles
echo "\n--- 2. Autenticación de perfiles ---\n";
$coordLogin = req('POST', '/api/auth/login', ['email' => 'coordinacion@vendguard.internal', 'password' => 'Password123!']);
$coordToken = $coordLogin['json']['data']['token'] ?? null;
$assert('2.1 Login Coordinador responde HTTP 200 y rol COORDINATOR', $coordLogin['code'] === 200 && ($coordLogin['json']['data']['user']['role'] ?? '') === 'COORDINATOR');

$repoLogin = req('POST', '/api/auth/login', ['email' => 'lucia.repostaje@vendguard.internal', 'password' => 'Password123!']);
$repoToken = $repoLogin['json']['data']['token'] ?? null;
$repoId = $repoLogin['json']['data']['user']['id'] ?? null;
$assert('2.2 Login Reponedor responde HTTP 200, rol RESTOCKER y operator_code', $repoLogin['code'] === 200 && ($repoLogin['json']['data']['user']['role'] ?? '') === 'RESTOCKER' && !empty($repoLogin['json']['data']['user']['operator_code']));

$satLogin = req('POST', '/api/auth/login', ['email' => 'jordi.ruta@vendguard.internal', 'password' => 'Password123!']);
$satToken = $satLogin['json']['data']['token'] ?? null;
$assert('2.3 Login Técnico SAT responde HTTP 200 y rol TECHNICIAN', $satLogin['code'] === 200 && ($satLogin['json']['data']['user']['role'] ?? '') === 'TECHNICIAN');

// 3. RBAC y Segregación
echo "\n--- 3. Segregación RBAC (Art. V.3/V.4) ---\n";
$satOnRestocker = req('GET', '/api/restocker/van-stocks', null, $satToken);
$assert('3.1 Técnico SAT bloqueado en inventario de reponedor con HTTP 403', $satOnRestocker['code'] === 403);

$satOnCoord = req('GET', '/api/coordinator/product-loading-orders', null, $satToken);
$assert('3.2 Técnico SAT bloqueado en órdenes de carga de coordinador con HTTP 403', $satOnCoord['code'] === 403);

$repoOnCoord = req('GET', '/api/coordinator/product-loading-orders', null, $repoToken);
$assert('3.3 Reponedor bloqueado en gestión de órdenes de coordinador con HTTP 403', $repoOnCoord['code'] === 403);

$repoOnSat = req('GET', '/api/technician/my-route', null, $repoToken);
$assert('3.4 Reponedor bloqueado en ruta técnica SAT con HTTP 403', $repoOnSat['code'] === 403);

$anonOnRestocker = req('GET', '/api/restocker/van-stocks');
$assert('3.5 Acceso anónimo rechazado con HTTP 401', $anonOnRestocker['code'] === 401);

// 4. Propuesta y Emisión de Orden
echo "\n--- 4. Propuesta matutina y emisión de orden de carga ---\n";

// Limpiar orden activa previa si existiera (para que la prueba sea idempotente)
$existingActive = req('GET', '/api/restocker/my-route', null, $repoToken);
$priorOrderId = $existingActive['json']['data']['active_loading_order']['order']['id'] ?? null;
$priorStatus = $existingActive['json']['data']['active_loading_order']['order']['status'] ?? null;
if ($priorOrderId !== null) {
    if ($priorStatus === 'CONFIRMED_BY_RESTOCKER') {
        req('POST', '/api/restocker/product-loading-orders/' . $priorOrderId . '/reconcile', [
            'reconciliation_notes' => 'Liquidación de orden previa para ejecución limpia de la prueba E2E.',
            'items' => array_map(function ($it) {
                return [
                    'product_id' => $it['product_id'],
                    'restocked_in_machines_qty' => $it['dispatched_qty'] ?? 0,
                    'withdrawn_spoilage_qty' => 0,
                    'counted_return_qty' => 0
                ];
            }, $existingActive['json']['data']['active_loading_order']['items'] ?? [])
        ], $repoToken);
    } else {
        req('POST', '/api/coordinator/product-loading-orders/' . $priorOrderId . '/cancel', [
            'cancel_reason' => 'Cancelación de orden previa para ejecución limpia de la prueba E2E.'
        ], $coordToken);
    }
}

$prop = req('GET', '/api/coordinator/product-loading-orders/proposal?restocker_id=' . $repoId . '&demand_mode=ALL_GAPS&safety_buffer_pct=10', null, $coordToken);
$assert('4.1 Propuesta matutina responde HTTP 200 con zona y líneas', $prop['code'] === 200 && isset($prop['json']['data']['items']));

// Obtener un producto del catálogo para la orden de prueba
$products = req('GET', '/api/coordinator/restocker-van-stocks', null, $coordToken);
$firstLot = $products['json']['data']['lots'][0] ?? null;
$productId = $firstLot['product_id'] ?? 1;

$createRes = req('POST', '/api/coordinator/product-loading-orders', [
    'restocker_id' => $repoId,
    'demand_mode' => 'ALL_GAPS',
    'safety_buffer_pct' => 10,
    'notes' => 'Orden de prueba funcional HTTP E2E',
    'items' => [
        [
            'product_id' => $productId,
            'requested_qty' => 12,
            'reason' => 'ROUTE_GAP_FILL'
        ]
    ]
], $coordToken);

$createdOrder = $createRes['json']['data']['order'] ?? null;
$orderId = $createdOrder['id'] ?? null;
$assert('4.2 Alta de orden de carga responde HTTP 201 en estado PENDING_PICKING', $createRes['code'] === 201 && ($createdOrder['status'] ?? '') === 'PENDING_PICKING', 'order_id=' . $orderId);

// Bloqueo de segunda orden activa (D3)
$createDup = req('POST', '/api/coordinator/product-loading-orders', [
    'restocker_id' => $repoId,
    'demand_mode' => 'ALL_GAPS',
    'safety_buffer_pct' => 10,
    'items' => [['product_id' => $productId, 'requested_qty' => 6, 'reason' => 'ROUTE_GAP_FILL']]
], $coordToken);
$assert('4.3 Segunda orden activa para el mismo reponedor rechazada con HTTP 409 (D3)', $createDup['code'] === 409);

// 5. Hoja A4 de Picking y cerrojo de frío
echo "\n--- 5. Hoja A4 de picking y cerrojo de frío (Art. II) ---\n";
$picking = req('GET', '/api/coordinator/product-loading-orders/' . $orderId . '/picking-sheet', null, $coordToken);
$assert('5.1 Hoja A4 de picking responde HTTP 200 con secciones', $picking['code'] === 200 && isset($picking['json']['data']['sections']));

$badTemp = req('POST', '/api/coordinator/product-loading-orders/' . $orderId . '/dispatch', [
    'cold_chamber_exit_temp_celsius' => 5.5,
    'items' => [
        [
            'product_id' => $productId,
            'dispatched_qty' => 12,
            'batch_code' => 'L-E2E-01',
            'expiration_date' => '2027-06-30'
        ]
    ]
], $coordToken);
$assert('5.2 Despacho con temperatura > 4.0 °C rechazado con HTTP 422 (Art. II)', $badTemp['code'] === 422);

$goodDispatch = req('POST', '/api/coordinator/product-loading-orders/' . $orderId . '/dispatch', [
    'cold_chamber_exit_temp_celsius' => 3.2,
    'items' => [
        [
            'product_id' => $productId,
            'dispatched_qty' => 12,
            'batch_code' => 'L-E2E-01',
            'expiration_date' => '2027-06-30'
        ]
    ]
], $coordToken);
$assert('5.3 Despacho con temperatura <= 4.0 °C transiciona a DISPATCHED con HTTP 200', $goodDispatch['code'] === 200 && ($goodDispatch['json']['data']['order']['status'] ?? '') === 'DISPATCHED');

// 6. Ciclo móvil del Reponedor
echo "\n--- 6. Ciclo móvil del reponedor (1-Tap recepción y liquidación) ---\n";
$myRoute = req('GET', '/api/restocker/my-route', null, $repoToken);
$activeOrderInRoute = $myRoute['json']['data']['active_loading_order']['order'] ?? null;
$assert('6.1 Reponedor ve la orden despachada en su ruta móvil', $myRoute['code'] === 200 && ($activeOrderInRoute['status'] ?? '') === 'DISPATCHED');

$satConfirm = req('POST', '/api/restocker/product-loading-orders/' . $orderId . '/confirm', null, $satToken);
$assert('6.2 Usuario SAT no puede confirmar la orden con HTTP 403', $satConfirm['code'] === 403);

$confirmRes = req('POST', '/api/restocker/product-loading-orders/' . $orderId . '/confirm', null, $repoToken);
$assert('6.3 Recepción móvil 1-Tap responde HTTP 200 y pasa a CONFIRMED_BY_RESTOCKER', $confirmRes['code'] === 200 && ($confirmRes['json']['data']['order']['status'] ?? '') === 'CONFIRMED_BY_RESTOCKER');

$dupConfirm = req('POST', '/api/restocker/product-loading-orders/' . $orderId . '/confirm', null, $repoToken);
$assert('6.4 Doble toque de confirmación rechazado con HTTP 409', $dupConfirm['code'] === 409);

$vanStock = req('GET', '/api/restocker/van-stocks', null, $repoToken);
$assert('6.5 Stock de furgoneta refleja las unidades recibidas con semáforo FEFO', $vanStock['code'] === 200 && !empty($vanStock['json']['data']['stocks']));

$badReconcile = req('POST', '/api/restocker/product-loading-orders/' . $orderId . '/reconcile', [
    'reconciliation_notes' => 'Corto',
    'items' => [
        [
            'product_id' => $productId,
            'restocked_in_machines_qty' => 6,
            'withdrawn_spoilage_qty' => 0,
            'counted_return_qty' => 5
        ]
    ]
], $repoToken);
$assert('6.6 Liquidación con descuadre y nota < 20 caracteres rechazada con HTTP 422', $badReconcile['code'] === 422);

$goodReconcile = req('POST', '/api/restocker/product-loading-orders/' . $orderId . '/reconcile', [
    'notes' => 'Se detectó una lata abollada durante la reposición que no pudo colocarse en el carril.',
    'items' => [
        [
            'product_id' => $productId,
            'restocked_in_machines_qty' => 6,
            'withdrawn_spoilage_qty' => 1,
            'counted_return_qty' => 5
        ]
    ]
], $repoToken);
$assert('6.7 Liquidación aceptando alias «notes» responde HTTP 200 y pasa a RECONCILED_AT_CLOSE', $goodReconcile['code'] === 200 && ($goodReconcile['json']['data']['order']['status'] ?? '') === 'RECONCILED_AT_CLOSE');

echo "\n======================================================================\n";
echo " Total Aserciones E2E: {$assertions} | Aprobadas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " \033[32mRESULTADO: 100% EN VERDE. PRUEBAS FUNCIONALES, DE HUMO Y E2E SUPERADAS.\033[0m\n";
} else {
    echo " \033[31mRESULTADO: SE ENCONTRARON FALLOS EN LA EJECUCIÓN.\033[0m\n";
}
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
