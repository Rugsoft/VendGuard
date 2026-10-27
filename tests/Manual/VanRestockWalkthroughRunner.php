<?php

declare(strict_types=1);

/**
 * VendGuard — Guion de Verificación Funcional del Módulo 23 por HTTP real
 *
 * Ejecuta el recorrido funcional completo de las órdenes de carga de furgoneta
 * sobre el servidor local, por el transporte real (cURL contra `php -S`), no
 * por el router en proceso: así se ejercitan las cabeceras, el enrutado, la
 * serialización, el sobre `{success, data}` y los códigos de estado tal y como
 * los ve el navegador.
 *
 * Recorrido:
 *
 * 0. Preparación: purga operativa y siembra del catálogo.
 * 1. Login de los tres perfiles (coordinación, técnico y sede).
 * 2. Propuesta de reposición por déficit de umbral (`RF-02`).
 * 3. Alta individual en `PENDING_PICKING` (`RF-01.1`, `RF-01.2`, `RF-02.3`).
 * 4. Bloqueo de una segunda orden abierta (`RF-01.2`, decisión `D3`).
 * 5. Cancelación auditada con motivo y liberación del hueco único (`RF-03.4`, Art. V.1).
 * 6. Detalle y estructura del Albarán de Picking A4, sin importes (`RF-03.1`, Art. V.4).
 * 7. Edición con baja lógica de las líneas retiradas (`RF-01.3`, `RF-01.4`, `QA 1`).
 * 8. Expedición parcial desde almacén (`RF-03.2`).
 * 9. Recepción móvil en 1 toque y suma atómica al saldo (`RF-04.2`, `RNF-02`).
 * 10. Doble toque rechazado sin duplicar el incremento (`RNF-02`).
 * 11. Comprobante de la última recepción (`QA 5`).
 * 12. Privacidad del Portal de Sede y segregación de roles (`RF-06`, Art. V.4).
 * 13. Insignias de cobertura en tarjetas y recomendador de triaje (`RF-04.3`, `RF-05`).
 *
 * Los datos de partida son los de la semilla: cuatro órdenes, una por estado del
 * ciclo, y un déficit real (JUNT-TOR-01 por debajo de su umbral en la furgoneta
 * de Jordi). El ciclo de vida se recorre sobre la orden `PENDING_PICKING`
 * sembrada de Jordi, y el alta y la cancelación sobre un técnico sin órdenes
 * previas, de modo que cada requisito se demuestre sobre un caso real y no
 * sobre un escenario inventado.
 *
 * La baja lógica de líneas (`QA 1`) no es visible en la respuesta —el contrato
 * publica solo las líneas activas—, así que se certifica en el motor: las filas
 * retiradas permanecen con su marca y el total de filas nunca disminuye.
 *
 * Reparto de la evidencia: este guion certifica el recorrido y el transporte.
 * La matriz exhaustiva de estados de `van_stock_readiness` (`READY_IN_VAN`,
 * `SHARED_IN_VAN`, `NOT_READY`, `HAS_OUT_OF_CATALOG`) y la del cruce
 * `van_stock_match` viven en `VanRestockOrderApiTest` 9.x y 10.x, que trabajan
 * sobre esquema aislado. Aquí se provoca un caso real de cada una.
 *
 * Nota de arnés: el paso 13 siembra la solicitud de repuesto pendiente por SQL
 * directo, porque el flujo «el técnico pide una pieza» pertenece al Módulo 06 y
 * no al 23; la insignia que esa solicitud provoca sí se lee por HTTP.
 *
 * Hecho cuando: el ciclo propuesta ➔ alta ➔ bloqueo ➔ cancelación ➔ A4 ➔ baja
 *               lógica ➔ expedición ➔ recepción ➔ comprobante ➔ privacidad ➔
 *               insignias se completa con cero fallos sobre el servidor vivo.
 *
 * Requisitos: servidor escuchando en http://127.0.0.1:8000 con el mismo motor
 *             que este proceso (ver «Cómo probar» del README).
 *
 * Este guion es una suite de la **Fase 4** de `php tests/run_all.php`: el
 * ejecutor arranca el servidor y lo recorre sin intervención, junto al guion
 * E2E de los tres perfiles. El bloque de uso de abajo sirve para correrlo solo.
 *
 * Uso:
 *   source deploy/local/postgres/.env.battery
 *   php -S 127.0.0.1:8000 -t public public/index.php
 *   php tests/Manual/VanRestockWalkthroughRunner.php
 *
 * Dogma Vanilla: PHP 8.2+ puro con cURL nativo y PDO sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;

$colorBold  = "\033[1m";
$colorGreen = "\033[32m";
$colorRed   = "\033[31m";
$colorCyan  = "\033[36m";
$colorReset = "\033[0m";

echo "{$colorBold}======================================================================{$colorReset}\n";
echo "{$colorBold} VendGuard: Recorrido funcional del Módulo 23 por HTTP real{$colorReset}\n";
echo "{$colorBold} Órdenes de carga de furgoneta y cruce de stock en triaje{$colorReset}\n";
echo "{$colorBold}======================================================================{$colorReset}\n\n";

$baseUrl = 'http://127.0.0.1:8000';
$assertions = 0;
$failures = 0;

$assert = function (string $step, bool $condition, string $detail = '') use (&$assertions, &$failures, $colorGreen, $colorRed, $colorReset): void {
    $assertions++;
    if ($condition) {
        echo "  {$colorGreen}[PASS]{$colorReset} {$step}\n";
    } else {
        echo "  {$colorRed}[FAIL]{$colorReset} {$step}\n";
        if ($detail !== '') {
            echo "         Motivo: {$detail}\n";
        }
        $failures++;
    }
};

/**
 * Cliente HTTP ligero basado en cURL nativo.
 */
function makeHttpRequest(string $method, string $url, array $data = [], ?string $token = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => 10,
    ];

    if (!empty($data) || in_array(strtoupper($method), ['POST', 'PATCH', 'PUT'], true)) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string)$raw, true);

    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : [], 'raw' => (string)$raw];
}

/**
 * Cuerpo útil de una respuesta correcta: el sobre `{success, data}` ya abierto.
 *
 * @param array<string, mixed> $response
 * @return array<string, mixed>
 */
function payload(array $response): array
{
    $data = $response['body']['data'] ?? [];

    return is_array($data) ? $data : [];
}

/**
 * ¿Aparece alguna de estas claves en cualquier profundidad del payload?
 *
 * Se usa para demostrar la opacidad del Portal de Sede (Art. V.4) sin depender
 * de la forma exacta de la respuesta.
 *
 * @param mixed              $node
 * @param array<int, string> $forbiddenKeys
 */
function payloadContainsKey($node, array $forbiddenKeys): ?string
{
    if (!is_array($node)) {
        return null;
    }

    foreach ($node as $key => $value) {
        if (is_string($key) && in_array($key, $forbiddenKeys, true)) {
            return $key;
        }

        $nested = payloadContainsKey($value, $forbiddenKeys);
        if ($nested !== null) {
            return $nested;
        }
    }

    return null;
}

/**
 * Localiza una línea por referencia dentro de una lista de líneas.
 *
 * @param array<int, mixed> $items
 * @return array<string, mixed>|null
 */
function findLineByPart(array $items, int $sparePartId): ?array
{
    foreach ($items as $item) {
        if (is_array($item) && (int)($item['spare_part_id'] ?? 0) === $sparePartId) {
            return $item;
        }
    }

    return null;
}

// ─────────────────────────────────────────────────────────────────────────
// PASO 0: PREPARACIÓN DEL ENTORNO SEMBRADO
// ─────────────────────────────────────────────────────────────────────────
echo "{$colorCyan}--- Paso 0: Preparación del entorno sembrado ---{$colorReset}\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$machineRow = $pdo->query("SELECT id FROM machines WHERE code = 'VEND-0101' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$machineId = is_array($machineRow) ? (int)$machineRow['id'] : 0;
$assert('0.1 Entorno sembrado y máquina VEND-0101 disponible para el escenario', $machineId > 0);

$userIdByEmail = static function (string $email) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email_key = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $id = $stmt->fetchColumn();

    return $id === false ? 0 : (int)$id;
};

$sparePartIdByCode = static function (string $code) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT id FROM spare_parts WHERE part_code = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    $id = $stmt->fetchColumn();

    return $id === false ? 0 : (int)$id;
};

$technicianId = $userIdByEmail('jordi.ruta@vendguard.internal');
$assert('0.2 Técnico titular de la orden sembrada identificado (Jordi)', $technicianId > 0);

// El déficit lo pone la propia semilla: JUNT-TOR-01 queda en 1 con umbral 2.
$deficitPartId = $sparePartIdByCode('JUNT-TOR-01');
$deficitRow = $pdo->query(
    "SELECT quantity_available, min_threshold FROM technician_van_stocks
      WHERE technician_id = {$technicianId} AND spare_part_id = {$deficitPartId}"
)->fetch(PDO::FETCH_ASSOC);
$assert(
    '0.3 La semilla deja un déficit demostrable (JUNT-TOR-01 bajo su umbral)',
    is_array($deficitRow) && (int)$deficitRow['quantity_available'] < (int)$deficitRow['min_threshold'],
    'saldo=' . var_export($deficitRow['quantity_available'] ?? null, true)
        . ' umbral=' . var_export($deficitRow['min_threshold'] ?? null, true)
);

$manualPartId = $sparePartIdByCode('VALV-ULKA-01');
$assert('0.4 Repuesto para las líneas manuales disponible (VALV-ULKA-01)', $manualPartId > 0);

// Técnico sin orden sembrada: sobre él se demuestran el alta y la cancelación.
$freeTechnicianId = $userIdByEmail('marc.ruta@vendguard.internal');
$seededFreeOrders = (int)$pdo->query(
    "SELECT COUNT(*) FROM van_restock_orders WHERE technician_id = {$freeTechnicianId}"
)->fetchColumn();
$assert(
    '0.5 Existe un técnico sin órdenes previas para el alta y la cancelación (Marc)',
    $freeTechnicianId > 0 && $seededFreeOrders === 0,
    'órdenes previas=' . $seededFreeOrders
);

$seededJordiOrders = (int)$pdo->query(
    "SELECT COUNT(*) FROM van_restock_orders
      WHERE technician_id = {$technicianId} AND status IN ('PENDING_PICKING', 'DISPATCHED')"
)->fetchColumn();
$assert('0.6 La semilla deja a Jordi con una orden abierta que se recorrerá entera', $seededJordiOrders === 1);

// ─────────────────────────────────────────────────────────────────────────
// PASO 1: LOGIN DE LOS TRES PERFILES
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 1: Login de los tres perfiles ---{$colorReset}\n";

$resCoordLogin = makeHttpRequest('POST', "{$baseUrl}/api/auth/login", [
    'email' => 'coordinacion@vendguard.internal',
    'password' => 'Password123!',
]);
$assert('1.1 Login de coordinación responde HTTP 200 OK', $resCoordLogin['status'] === 200);
$coordToken = payload($resCoordLogin)['token'] ?? null;
$assert(
    '1.2 El token de coordinación identifica el rol COORDINATOR',
    !empty($coordToken) && (payload($resCoordLogin)['user']['role'] ?? '') === 'COORDINATOR'
);

$resTechLogin = makeHttpRequest('POST', "{$baseUrl}/api/auth/login", [
    'email' => 'jordi.ruta@vendguard.internal',
    'password' => 'Password123!',
]);
$assert('1.3 Login del técnico Jordi responde HTTP 200 OK', $resTechLogin['status'] === 200);
$techToken = payload($resTechLogin)['token'] ?? null;
$assert(
    '1.4 El token del técnico identifica el rol TECHNICIAN',
    !empty($techToken) && (payload($resTechLogin)['user']['role'] ?? '') === 'TECHNICIAN'
);

$resSiteLogin = makeHttpRequest('POST', "{$baseUrl}/api/auth/site-login", [
    'site_code' => 'SEDE-BCN-01',
    'access_code' => SeedRunner::devAccessCode('SEDE-BCN-01'),
]);
$assert('1.5 Login de la sede SEDE-BCN-01 responde HTTP 200 OK', $resSiteLogin['status'] === 200);
$siteToken = payload($resSiteLogin)['token'] ?? null;
$assert('1.6 La sede recibe su token de centro', !empty($siteToken));

// ─────────────────────────────────────────────────────────────────────────
// PASO 2: PROPUESTA DE REPOSICIÓN (RF-02)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 2: Propuesta de reposición por déficit ---{$colorReset}\n";

$resProposals = makeHttpRequest(
    'GET',
    "{$baseUrl}/api/coordinator/van-restock-orders/proposals?technician_id={$technicianId}",
    [],
    $coordToken
);
$assert('2.1 El pre-cálculo de propuestas responde HTTP 200 OK', $resProposals['status'] === 200);

$jordiProposal = null;
foreach (payload($resProposals)['proposals'] ?? [] as $proposal) {
    if (is_array($proposal) && (int)($proposal['technician_id'] ?? 0) === $technicianId) {
        $jordiProposal = $proposal;
        break;
    }
}
$assert('2.2 La propuesta incluye al técnico destinatario', is_array($jordiProposal));
$assert(
    '2.3 La propuesta declara la orden abierta de la semilla en lugar de proponer una nueva',
    is_array($jordiProposal) && (int)($jordiProposal['open_order_id'] ?? 0) > 0
        && ($jordiProposal['open_order_status'] ?? '') === 'PENDING_PICKING',
    'orden abierta=' . var_export($jordiProposal['open_order_id'] ?? null, true)
);

$seededOrderId = is_array($jordiProposal) ? (int)($jordiProposal['open_order_id'] ?? 0) : 0;

$deficitLine = is_array($jordiProposal) ? findLineByPart($jordiProposal['items'] ?? [], $deficitPartId) : null;
$assert(
    '2.4 La pieza por debajo del umbral aparece sugerida con motivo de déficit',
    is_array($deficitLine) && in_array($deficitLine['reason'] ?? '', ['LOW_STOCK', 'BOTH'], true),
    'línea=' . json_encode($deficitLine, JSON_UNESCAPED_UNICODE)
);
$assert(
    '2.5 La sugerencia respeta el tope doble (9999 menos el saldo vivo) con cantidad positiva',
    is_array($deficitLine)
        && (int)($deficitLine['suggested_qty'] ?? 0) >= 1
        && (int)($deficitLine['max_allowed_qty'] ?? -1)
            === 9999 - (int)($deficitLine['current_quantity'] ?? 0),
    'tope=' . var_export($deficitLine['max_allowed_qty'] ?? null, true)
        . ' saldo=' . var_export($deficitLine['current_quantity'] ?? null, true)
);

// ─────────────────────────────────────────────────────────────────────────
// PASO 3: ALTA INDIVIDUAL (RF-01.1, RF-01.2, RF-02.3)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 3: Alta individual en PENDING_PICKING ---{$colorReset}\n";

$resCreate = makeHttpRequest('POST', "{$baseUrl}/api/coordinator/van-restock-orders", [
    'technician_id' => $freeTechnicianId,
    'notes' => 'Recorrido funcional: preparar en jaula 3 antes de las 08:30.',
    'items' => [
        ['spare_part_id' => $manualPartId, 'requested_qty' => 2, 'reason' => 'MANUAL'],
    ],
], $coordToken);
$assert(
    '3.1 El alta de la orden responde HTTP 201 Created',
    $resCreate['status'] === 201,
    'status=' . $resCreate['status'] . ' body=' . substr($resCreate['raw'], 0, 240)
);

$createdOrder = payload($resCreate)['order'] ?? [];
$newOrderId = (int)($createdOrder['id'] ?? 0);
$newOrderCode = (string)($createdOrder['order_code'] ?? '');
$assert(
    '3.2 La orden nace en PENDING_PICKING con código correlativo RO-',
    $newOrderId > 0 && ($createdOrder['status'] ?? '') === 'PENDING_PICKING'
        && preg_match('/^RO-\d{6}$/', $newOrderCode) === 1,
    'código=' . $newOrderCode . ' estado=' . ($createdOrder['status'] ?? 'sin estado')
);

$createdLines = $createdOrder['items'] ?? [];
$assert(
    '3.3 La orden materializa su línea manual con la cantidad declarada',
    count($createdLines) === 1
        && (int)($createdLines[0]['spare_part_id'] ?? 0) === $manualPartId
        && (int)($createdLines[0]['requested_qty'] ?? 0) === 2
        && ($createdLines[0]['reason'] ?? '') === 'MANUAL',
    'líneas=' . count($createdLines)
);
$assert(
    '3.4 Los totales de la cabecera cuadran con la línea',
    (int)($createdOrder['active_items_count'] ?? 0) === 1
        && (int)($createdOrder['total_requested_units'] ?? 0) === 2,
    'unidades=' . var_export($createdOrder['total_requested_units'] ?? null, true)
);
$assert(
    '3.5 El destinatario queda marcado como activo y no ausente',
    ($createdOrder['recipient_currently_absent'] ?? null) === false
        && ($createdOrder['technician_is_active'] ?? null) === true
);

// ─────────────────────────────────────────────────────────────────────────
// PASO 4: BLOQUEO DE UNA SEGUNDA ORDEN ABIERTA (RF-01.2, D3)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 4: Una sola orden abierta por técnico ---{$colorReset}\n";

$resSecondForFree = makeHttpRequest('POST', "{$baseUrl}/api/coordinator/van-restock-orders", [
    'technician_id' => $freeTechnicianId,
    'items' => [
        ['spare_part_id' => $manualPartId, 'requested_qty' => 1, 'reason' => 'MANUAL'],
    ],
], $coordToken);
$assert(
    '4.1 Una segunda orden para el mismo técnico es rechazada con HTTP 409',
    $resSecondForFree['status'] === 409
        && ($resSecondForFree['body']['error']['code'] ?? '') === 'OPEN_RESTOCK_ORDER_ALREADY_EXISTS',
    'status=' . $resSecondForFree['status'] . ' código=' . ($resSecondForFree['body']['error']['code'] ?? 'ninguno')
);

$resSecondForJordi = makeHttpRequest('POST', "{$baseUrl}/api/coordinator/van-restock-orders", [
    'technician_id' => $technicianId,
    'items' => [
        ['spare_part_id' => $deficitPartId, 'requested_qty' => 1, 'reason' => 'LOW_STOCK'],
    ],
], $coordToken);
$assert(
    '4.2 La orden sembrada de Jordi también bloquea un alta nueva',
    $resSecondForJordi['status'] === 409
        && ($resSecondForJordi['body']['error']['code'] ?? '') === 'OPEN_RESTOCK_ORDER_ALREADY_EXISTS',
    'status=' . $resSecondForJordi['status'] . ' código=' . ($resSecondForJordi['body']['error']['code'] ?? 'ninguno')
);

$resOpenList = makeHttpRequest(
    'GET',
    "{$baseUrl}/api/coordinator/van-restock-orders?status=OPEN&technician_id={$freeTechnicianId}",
    [],
    $coordToken
);
$openOrders = payload($resOpenList)['items'] ?? [];
$assert(
    '4.3 El listado filtrado por abiertas devuelve exactamente la orden recién creada',
    $resOpenList['status'] === 200 && count($openOrders) === 1
        && (int)($openOrders[0]['id'] ?? 0) === $newOrderId,
    'abiertas=' . count($openOrders)
);

// ─────────────────────────────────────────────────────────────────────────
// PASO 5: CANCELACIÓN AUDITADA Y HUECO ÚNICO LIBERADO (RF-03.4, Art. V.1)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 5: Cancelación auditada con motivo ---{$colorReset}\n";

$resShortCancel = makeHttpRequest(
    'POST',
    "{$baseUrl}/api/coordinator/van-restock-orders/{$newOrderId}/cancel",
    ['cancellation_reason' => 'Error de ruta'],
    $coordToken
);
$assert(
    '5.1 Un motivo por debajo de 20 caracteres reales es rechazado con HTTP 422',
    $resShortCancel['status'] === 422
        && ($resShortCancel['body']['error']['code'] ?? '') === 'INVALID_RESTOCK_ORDER_PAYLOAD',
    'status=' . $resShortCancel['status'] . ' código=' . ($resShortCancel['body']['error']['code'] ?? 'ninguno')
);

$cancelReason = 'Error en la asignación de ruta; el material se preparará para el turno de tarde.';
$resCancel = makeHttpRequest(
    'POST',
    "{$baseUrl}/api/coordinator/van-restock-orders/{$newOrderId}/cancel",
    ['cancellation_reason' => $cancelReason],
    $coordToken
);
$assert(
    '5.2 La cancelación justificada responde HTTP 200 OK',
    $resCancel['status'] === 200,
    'status=' . $resCancel['status'] . ' body=' . substr($resCancel['raw'], 0, 240)
);

$cancelledOrder = payload($resCancel)['order'] ?? [];
$assert(
    '5.3 La orden queda CANCELLED con motivo, actor e instante inmutables',
    ($cancelledOrder['status'] ?? '') === 'CANCELLED'
        && ($cancelledOrder['cancellation_reason'] ?? '') === $cancelReason
        && !empty($cancelledOrder['cancelled_at'])
        && (int)($cancelledOrder['cancelled_by'] ?? 0) > 0,
    'estado=' . ($cancelledOrder['status'] ?? 'sin estado')
);

$freeTechStockRows = (int)$pdo->query(
    "SELECT COUNT(*) FROM technician_van_stocks WHERE technician_id = {$freeTechnicianId}"
)->fetchColumn();
$assert(
    '5.4 La cancelación no toca el stock de la furgoneta',
    $freeTechStockRows === 0,
    'filas de saldo del técnico=' . $freeTechStockRows
);

$resRecreate = makeHttpRequest('POST', "{$baseUrl}/api/coordinator/van-restock-orders", [
    'technician_id' => $freeTechnicianId,
    'items' => [
        ['spare_part_id' => $manualPartId, 'requested_qty' => 1, 'reason' => 'MANUAL'],
    ],
], $coordToken);
$assert(
    '5.5 El hueco único queda libre: un alta nueva para el mismo técnico vuelve a pasar',
    $resRecreate['status'] === 201,
    'status=' . $resRecreate['status'] . ' body=' . substr($resRecreate['raw'], 0, 200)
);

$reCreatedOrderId = (int)(payload($resRecreate)['order']['id'] ?? 0);
$resCancelAgain = makeHttpRequest(
    'POST',
    "{$baseUrl}/api/coordinator/van-restock-orders/{$reCreatedOrderId}/cancel",
    ['cancellation_reason' => 'Sondeo del hueco único; se retira la orden para no dejar ruido abierto.'],
    $coordToken
);
$assert(
    '5.6 La orden de sondeo se retira con su propio motivo auditado',
    $resCancelAgain['status'] === 200 && (payload($resCancelAgain)['order']['status'] ?? '') === 'CANCELLED'
);

// ─────────────────────────────────────────────────────────────────────────
// PASO 6: DETALLE PARA EL ALBARÁN DE PICKING A4 (RF-03.1, Art. V.4)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 6: Detalle y estructura del Albarán A4 ---{$colorReset}\n";

$resDetail = makeHttpRequest('GET', "{$baseUrl}/api/coordinator/van-restock-orders/{$seededOrderId}", [], $coordToken);
$assert(
    '6.1 El detalle de la orden responde HTTP 200 OK',
    $resDetail['status'] === 200,
    'status=' . $resDetail['status'] . ' body=' . substr($resDetail['raw'], 0, 200)
);

$detail = payload($resDetail);
$detailLines = $detail['items'] ?? [];
$detailItem = $detailLines[0] ?? [];
// Claves que imprime la hoja A4 (CoordinatorVanStockTab.js): el saldo y el umbral
// viajan como instantáneas congeladas en el alta de la línea.
$sheetColumns = [
    'part_code', 'part_name', 'category', 'current_quantity_snapshot',
    'min_threshold_snapshot', 'requested_qty', 'reason',
];
$missingColumns = array_values(array_filter(
    $sheetColumns,
    static fn (string $column): bool => !array_key_exists($column, $detailItem)
));
$assert(
    '6.2 La línea activa trae las columnas que imprime la hoja (referencia, pieza, categoría, saldo, umbral, cantidad y motivo)',
    $detailItem !== [] && $missingColumns === [],
    'faltan=' . implode(',', $missingColumns)
);

$forbiddenCostKeys = [
    'cost', 'costs', 'price', 'prices', 'unit_cost', 'unit_price', 'total_cost', 'total_price',
    'amount', 'amounts', 'importe', 'importes', 'price_eur', 'cost_eur', 'currency',
];
$leakedKey = payloadContainsKey($detail, $forbiddenCostKeys);
$assert(
    '6.3 El documento no transporta ningún campo de coste ni de importe (Art. V.4)',
    $leakedKey === null,
    'clave filtrada=' . (string)$leakedKey
);

$assert(
    '6.4 El destinatario y la nota de almacén viajan en el detalle impreso',
    (int)($detail['technician_id'] ?? 0) === $technicianId
        && ($detail['technician_operator_code'] ?? '') !== ''
        && ($detail['notes'] ?? '') !== '',
    'operador=' . ($detail['technician_operator_code'] ?? 'vacío')
);

// ─────────────────────────────────────────────────────────────────────────
// PASO 7: EDICIÓN CON BAJA LÓGICA (RF-01.3, RF-01.4, QA 1)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 7: Edición con baja lógica de las líneas retiradas ---{$colorReset}\n";

$rowsBeforeEdit = (int)$pdo->query(
    "SELECT COUNT(*) FROM van_restock_order_items WHERE order_id = {$seededOrderId}"
)->fetchColumn();
$activeBeforeEdit = (int)$pdo->query(
    "SELECT COUNT(*) FROM van_restock_order_items WHERE order_id = {$seededOrderId} AND removed_at IS NULL"
)->fetchColumn();
$keptPartId = (int)($detailItem['spare_part_id'] ?? 0);
$editQty = 5;

$resEdit = makeHttpRequest('PUT', "{$baseUrl}/api/coordinator/van-restock-orders/{$seededOrderId}", [
    'notes' => 'Recorrido funcional: se retira la demanda no cubierta y se ajusta la cantidad.',
    'items' => [
        ['spare_part_id' => $keptPartId, 'requested_qty' => $editQty, 'reason' => 'LOW_STOCK'],
    ],
], $coordToken);
$assert(
    '7.1 La edición en PENDING_PICKING responde HTTP 200 OK',
    $resEdit['status'] === 200,
    'status=' . $resEdit['status'] . ' body=' . substr($resEdit['raw'], 0, 240)
);

$editedOrder = payload($resEdit)['order'] ?? [];
$editedLines = $editedOrder['items'] ?? [];
$assert(
    '7.2 El contrato publica una sola línea activa con la cantidad actualizada',
    count($editedLines) === 1
        && (int)($editedLines[0]['spare_part_id'] ?? 0) === $keptPartId
        && (int)($editedLines[0]['requested_qty'] ?? 0) === $editQty
        && (int)($editedOrder['active_items_count'] ?? 0) === 1,
    'líneas publicadas=' . count($editedLines) . ' activas=' . var_export($editedOrder['active_items_count'] ?? null, true)
);

$activeAfterEdit = (int)$pdo->query(
    "SELECT COUNT(*) FROM van_restock_order_items WHERE order_id = {$seededOrderId} AND removed_at IS NULL"
)->fetchColumn();
$removedAfterEdit = (int)$pdo->query(
    "SELECT COUNT(*) FROM van_restock_order_items WHERE order_id = {$seededOrderId} AND removed_at IS NOT NULL"
)->fetchColumn();
$assert(
    '7.3 Las líneas retiradas quedan con baja lógica en el motor, no borradas',
    $activeAfterEdit === 1
        && $activeBeforeEdit - 1 === $removedAfterEdit - ($rowsBeforeEdit - $activeBeforeEdit),
    'activas antes=' . $activeBeforeEdit . ' activas después=' . $activeAfterEdit
        . ' retiradas después=' . $removedAfterEdit
);

$rowsAfterEdit = (int)$pdo->query(
    "SELECT COUNT(*) FROM van_restock_order_items WHERE order_id = {$seededOrderId}"
)->fetchColumn();
$assert(
    '7.4 En el motor no desaparece ninguna fila (Art. III.1: cero borrado físico)',
    $rowsAfterEdit === $rowsBeforeEdit,
    'filas antes=' . $rowsBeforeEdit . ' después=' . $rowsAfterEdit
);

// ─────────────────────────────────────────────────────────────────────────
// PASO 8: EXPEDICIÓN PARCIAL (RF-03.2)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 8: Expedición parcial ---{$colorReset}\n";

$dispatchedQty = 3;
$resDispatch = makeHttpRequest('POST', "{$baseUrl}/api/coordinator/van-restock-orders/{$seededOrderId}/dispatch", [
    'notes' => 'Recorrido funcional: entregado en mostrador de almacén.',
    'items' => [
        ['spare_part_id' => $keptPartId, 'dispatched_qty' => $dispatchedQty],
    ],
], $coordToken);
$assert(
    '8.1 La expedición parcial responde HTTP 200 OK',
    $resDispatch['status'] === 200,
    'status=' . $resDispatch['status'] . ' body=' . substr($resDispatch['raw'], 0, 240)
);

$dispatchedOrder = payload($resDispatch)['order'] ?? [];
$assert(
    '8.2 La orden transiciona a DISPATCHED y cierra su marca de expedición',
    ($dispatchedOrder['status'] ?? '') === 'DISPATCHED'
        && !empty($dispatchedOrder['dispatched_at'])
        && (int)($dispatchedOrder['dispatched_by'] ?? 0) > 0
);
$assert(
    '8.3 Lo servido es menor que lo solicitado y el total expedido lo refleja',
    (int)($dispatchedOrder['total_dispatched_units'] ?? 0) === $dispatchedQty
        && $dispatchedQty < $editQty
        && (int)($dispatchedOrder['total_requested_units'] ?? 0) === $editQty,
    'expedido=' . var_export($dispatchedOrder['total_dispatched_units'] ?? null, true)
        . ' solicitado=' . var_export($dispatchedOrder['total_requested_units'] ?? null, true)
);

$resDispatchAgain = makeHttpRequest('POST', "{$baseUrl}/api/coordinator/van-restock-orders/{$seededOrderId}/dispatch", [
    'items' => [
        ['spare_part_id' => $keptPartId, 'dispatched_qty' => 1],
    ],
], $coordToken);
$assert(
    '8.4 Volver a expedir una orden ya expedida es rechazado con HTTP 409',
    $resDispatchAgain['status'] === 409
        && ($resDispatchAgain['body']['error']['code'] ?? '') === 'INVALID_RESTOCK_ORDER_TRANSITION',
    'status=' . $resDispatchAgain['status'] . ' código=' . ($resDispatchAgain['body']['error']['code'] ?? 'ninguno')
);

// ─────────────────────────────────────────────────────────────────────────
// PASO 9: RECEPCIÓN MÓVIL EN 1 TOQUE (RF-04.2, RNF-02)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 9: Recepción móvil y suma atómica al saldo ---{$colorReset}\n";

$stockBefore = (int)$pdo->query(
    "SELECT quantity_available FROM technician_van_stocks
      WHERE technician_id = {$technicianId} AND spare_part_id = {$keptPartId}"
)->fetchColumn();

$resVanStockBefore = makeHttpRequest('GET', "{$baseUrl}/api/technician/van-stock", [], $techToken);
$assert('9.1 El inventario móvil responde HTTP 200 OK', $resVanStockBefore['status'] === 200);

$pendingOrder = payload($resVanStockBefore)['active_dispatched_order'] ?? null;
$assert(
    '9.2 El técnico ve su orden pendiente de recibir con las unidades servidas',
    is_array($pendingOrder)
        && (int)($pendingOrder['id'] ?? 0) === $seededOrderId
        && ($pendingOrder['status'] ?? '') === 'DISPATCHED'
        && (int)($pendingOrder['total_dispatched_units'] ?? 0) === $dispatchedQty,
    'orden=' . json_encode($pendingOrder === null ? null : [
        'id' => $pendingOrder['id'] ?? null,
        'status' => $pendingOrder['status'] ?? null,
        'total' => $pendingOrder['total_dispatched_units'] ?? null,
    ], JSON_UNESCAPED_UNICODE)
);
$assert(
    '9.3 Las líneas del aviso móvil llevan referencia, pieza y cantidad servida',
    is_array($pendingOrder)
        && ($pendingOrder['items'][0]['part_code'] ?? '') !== ''
        && ($pendingOrder['items'][0]['part_name'] ?? '') !== ''
        && (int)($pendingOrder['items'][0]['dispatched_qty'] ?? 0) === $dispatchedQty
);

$resConfirm = makeHttpRequest(
    'POST',
    "{$baseUrl}/api/technician/van-stock/restock-orders/{$seededOrderId}/confirm",
    ['reception_note' => 'Recorrido funcional: recibido completo en almacén.'],
    $techToken
);
$assert(
    '9.4 La confirmación en 1 toque responde HTTP 200 OK',
    $resConfirm['status'] === 200,
    'status=' . $resConfirm['status'] . ' body=' . substr($resConfirm['raw'], 0, 240)
);

$confirmedOrder = payload($resConfirm)['order'] ?? [];
$assert(
    '9.5 La orden cierra como CONFIRMED_BY_TECH con su comprobante de unidades',
    ($confirmedOrder['status'] ?? '') === 'CONFIRMED_BY_TECH'
        && !empty($confirmedOrder['confirmed_at'])
        && (int)($confirmedOrder['total_received_units'] ?? 0) === $dispatchedQty
);

$openShifts = (int)$pdo->query(
    "SELECT COUNT(*) FROM technician_shifts
      WHERE technician_id = {$technicianId} AND status IN ('ON_SHIFT', 'MEAL_BREAK')"
)->fetchColumn();
$assert(
    '9.6 La recepción se permite sin jornada abierta (QA 4)',
    $openShifts === 0 && $resConfirm['status'] === 200,
    'turnos abiertos=' . $openShifts
);

$confirmLine = payload($resConfirm)['updated_van_stock'][0] ?? [];
$assert(
    '9.7 La traza de saldo cuadra: saldo anterior más lo servido es el saldo nuevo',
    is_array($confirmLine)
        && (int)($confirmLine['previous_quantity_available'] ?? -1) === $stockBefore
        && (int)($confirmLine['previous_quantity_available'] ?? -1) + $dispatchedQty
            === (int)($confirmLine['new_quantity_available'] ?? -2),
    'antes=' . var_export($confirmLine['previous_quantity_available'] ?? null, true)
        . ' después=' . var_export($confirmLine['new_quantity_available'] ?? null, true)
);

$stockAfter = (int)$pdo->query(
    "SELECT quantity_available FROM technician_van_stocks
      WHERE technician_id = {$technicianId} AND spare_part_id = {$keptPartId}"
)->fetchColumn();
$assert(
    '9.8 El motor confirma el incremento exacto del saldo en la furgoneta',
    $stockAfter === $stockBefore + $dispatchedQty,
    'antes=' . $stockBefore . ' después=' . $stockAfter . ' servido=' . $dispatchedQty
);

$confirmMessage = (string)(payload($resConfirm)['message'] ?? '');
$expectedMessage = sprintf(
    'Carga confirmada. Se han sumado %d %s al stock de tu furgoneta.',
    $dispatchedQty,
    $dispatchedQty === 1 ? 'unidad' : 'unidades'
);
$assert(
    '9.9 El comprobante concuerda en número («unidad» / «unidades»)',
    $confirmMessage === $expectedMessage,
    'mensaje=' . $confirmMessage
);

// ─────────────────────────────────────────────────────────────────────────
// PASO 10: DOBLE TOQUE RECHAZADO SIN DUPLICAR EL INCREMENTO (RNF-02)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 10: Doble toque rechazado sin doble incremento ---{$colorReset}\n";

$resDoubleConfirm = makeHttpRequest(
    'POST',
    "{$baseUrl}/api/technician/van-stock/restock-orders/{$seededOrderId}/confirm",
    [],
    $techToken
);
$assert(
    '10.1 Un segundo toque sobre la misma orden es rechazado con HTTP 409',
    $resDoubleConfirm['status'] === 409
        && ($resDoubleConfirm['body']['error']['code'] ?? '') === 'INVALID_RESTOCK_ORDER_TRANSITION',
    'status=' . $resDoubleConfirm['status'] . ' código=' . ($resDoubleConfirm['body']['error']['code'] ?? 'ninguno')
);

$stockAfterDouble = (int)$pdo->query(
    "SELECT quantity_available FROM technician_van_stocks
      WHERE technician_id = {$technicianId} AND spare_part_id = {$keptPartId}"
)->fetchColumn();
$assert(
    '10.2 El saldo no se volvió a incrementar (idempotencia del toque repetido)',
    $stockAfterDouble === $stockAfter,
    'saldo=' . $stockAfterDouble . ' esperado=' . $stockAfter
);

$auditRows = (int)$pdo->query(
    "SELECT COUNT(*) FROM audit_log
      WHERE entity_type = 'VAN_RESTOCK_ORDER' AND entity_id = {$seededOrderId}
        AND action = 'CONFIRM_VAN_RESTOCK_ORDER'"
)->fetchColumn();
$assert(
    '10.3 El motor registró una sola confirmación inmutable en la auditoría',
    $auditRows === 1,
    'eventos de confirmación=' . $auditRows
);

// ─────────────────────────────────────────────────────────────────────────
// PASO 11: COMPROBANTE DE LA ÚLTIMA RECEPCIÓN (QA 5)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 11: Comprobante de la última recepción ---{$colorReset}\n";

$resVanStockAfter = makeHttpRequest('GET', "{$baseUrl}/api/technician/van-stock", [], $techToken);
$vanStockAfter = payload($resVanStockAfter);
$assert(
    '11.1 Tras confirmar, el aviso pendiente desaparece del inventario móvil',
    ($vanStockAfter['active_dispatched_order'] ?? null) === null
);

$receipt = $vanStockAfter['last_confirmed_order'] ?? null;
$assert(
    '11.2 El comprobante identifica la orden recibida y sus unidades',
    is_array($receipt)
        && (int)($receipt['id'] ?? 0) === $seededOrderId
        && (int)($receipt['total_received_units'] ?? 0) === $dispatchedQty,
    'comprobante=' . json_encode($receipt, JSON_UNESCAPED_UNICODE)
);
$assert(
    '11.3 El comprobante detalla las referencias servidas para consulta del técnico',
    is_array($receipt)
        && (int)($receipt['items'][0]['spare_part_id'] ?? 0) === $keptPartId
        && (int)($receipt['items'][0]['dispatched_qty'] ?? 0) === $dispatchedQty
);

$lineAfter = findLineByPart($vanStockAfter['items'] ?? [], $keptPartId);
$assert(
    '11.4 El saldo publicado al técnico refleja la suma aplicada en el motor',
    is_array($lineAfter) && (int)$lineAfter['quantity_available'] === $stockAfter,
    'publicado=' . var_export($lineAfter['quantity_available'] ?? null, true) . ' motor=' . $stockAfter
);

// ─────────────────────────────────────────────────────────────────────────
// PASO 12: PRIVACIDAD DE SEDE Y SEGREGACIÓN DE ROLES (RF-06, Art. V.4)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 12: Privacidad del Portal de Sede y roles ---{$colorReset}\n";

$resSiteCatalog = makeHttpRequest('GET', "{$baseUrl}/api/locations/SEDE-BCN-01/machines", [], $siteToken);
$assert('12.1 La sede consulta su catálogo con HTTP 200 OK', $resSiteCatalog['status'] === 200);

$forbiddenSiteKeys = [
    'van_stock_readiness', 'van_stock_match', 'active_dispatched_order', 'last_confirmed_order',
    'restock_order', 'restock_orders', 'quantity_available', 'min_threshold', 'is_below_threshold',
];
$siteLeak = payloadContainsKey($resSiteCatalog['body'], $forbiddenSiteKeys);
$assert(
    '12.2 El catálogo de sede no filtra stock, órdenes ni el cruce de triaje',
    $siteLeak === null,
    'clave filtrada=' . (string)$siteLeak
);

$resSiteOrders = makeHttpRequest('GET', "{$baseUrl}/api/coordinator/van-restock-orders", [], $siteToken);
$assert(
    '12.3 La sede no puede listar órdenes de carga (401/403)',
    in_array($resSiteOrders['status'], [401, 403], true),
    'status=' . $resSiteOrders['status']
);

$resTechOrders = makeHttpRequest('GET', "{$baseUrl}/api/coordinator/van-restock-orders", [], $techToken);
$assert(
    '12.4 Un técnico no puede usar la API de coordinación (403 FORBIDDEN)',
    $resTechOrders['status'] === 403
        && ($resTechOrders['body']['error']['code'] ?? '') === 'FORBIDDEN',
    'status=' . $resTechOrders['status'] . ' código=' . ($resTechOrders['body']['error']['code'] ?? 'ninguno')
);

$resProposalsAsTech = makeHttpRequest(
    'GET',
    "{$baseUrl}/api/coordinator/van-restock-orders/proposals?technician_id={$technicianId}",
    [],
    $techToken
);
$assert(
    '12.5 El pre-cálculo de propuestas también queda cerrado al técnico',
    $resProposalsAsTech['status'] === 403,
    'status=' . $resProposalsAsTech['status']
);

$resConfirmForeign = makeHttpRequest(
    'POST',
    "{$baseUrl}/api/technician/van-stock/restock-orders/{$newOrderId}/confirm",
    [],
    $techToken
);
$assert(
    '12.6 Un técnico no puede confirmar la orden de otro (403/404)',
    in_array($resConfirmForeign['status'], [403, 404], true),
    'status=' . $resConfirmForeign['status'] . ' código=' . ($resConfirmForeign['body']['error']['code'] ?? 'ninguno')
);

// ─────────────────────────────────────────────────────────────────────────
// PASO 13: INSIGNIAS DE COBERTURA EN TARJETAS Y TRIAJE (RF-04.3, RF-05)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 13: Insignias de cobertura en tarjetas y recomendador ---{$colorReset}\n";

$resNewIncident = makeHttpRequest('POST', "{$baseUrl}/api/incidents", [
    'machine_id' => $machineId,
    'site_code' => 'SEDE-BCN-01',
    'category' => 'OTHER',
    'description' => 'Recorrido funcional Módulo 23: verificación de la insignia de cobertura en furgoneta.',
    'reporter_name' => 'Laura Sanitaria',
    'reporter_phone' => '600111222',
], $siteToken);
$assert(
    '13.1 La sede reporta una avería nueva para el escenario de triaje',
    $resNewIncident['status'] === 201,
    'status=' . $resNewIncident['status'] . ' body=' . substr($resNewIncident['raw'], 0, 200)
);

$newIncidentId = (int)(payload($resNewIncident)['id'] ?? 0);

$resAssign = makeHttpRequest('PATCH', "{$baseUrl}/api/coordinator/incidents/{$newIncidentId}/assign", [
    'technician_id' => $technicianId,
], $coordToken);
$assert(
    '13.2 Coordinación asigna la avería al técnico que ya lleva la pieza',
    $resAssign['status'] === 200 && (payload($resAssign)['status'] ?? '') === 'ASSIGNED',
    'status=' . $resAssign['status']
);

// Precondición sembrada por SQL: la solicitud de repuesto pertenece al Módulo 06,
// no al recorrido de órdenes de carga. La insignia que provoca se lee por HTTP.
$pendingRequestStmt = $pdo->prepare(
    "INSERT INTO spare_part_requests (incident_id, spare_part_id, is_out_of_catalog, quantity, status, requested_by_user_id)
     VALUES (:incident_id, :spare_part_id, FALSE, :quantity, 'PENDING', :requested_by)"
);
$pendingRequestStmt->execute([
    ':incident_id' => $newIncidentId,
    ':spare_part_id' => $keptPartId,
    ':quantity' => 1,
    ':requested_by' => $technicianId,
]);
$assert('13.3 La avería queda con una solicitud de repuesto pendiente', $pendingRequestStmt->rowCount() === 1);

$resCoordIncidents = makeHttpRequest('GET', "{$baseUrl}/api/coordinator/incidents", [], $coordToken);
$coordRow = null;
foreach (payload($resCoordIncidents) as $row) {
    if (is_array($row) && (int)($row['id'] ?? 0) === $newIncidentId) {
        $coordRow = $row;
        break;
    }
}
$readiness = is_array($coordRow) ? ($coordRow['van_stock_readiness'] ?? []) : [];
$assert(
    '13.4 La tarjeta de la avería publica READY_IN_VAN con la pieza ya en furgoneta',
    ($readiness['status'] ?? '') === 'READY_IN_VAN'
        && ($readiness['badge_label'] ?? '') === '📦 Repuesto ya en furgoneta'
        && ($readiness['has_out_of_catalog_requests'] ?? true) === false,
    'insignia=' . json_encode($readiness, JSON_UNESCAPED_UNICODE)
);

$resSuggest = makeHttpRequest(
    'GET',
    "{$baseUrl}/api/coordinator/incidents/{$newIncidentId}/suggested-technicians",
    [],
    $coordToken
);
$suggestions = payload($resSuggest)['ranked_technicians'] ?? [];
$rankedTech = null;
foreach ($suggestions as $suggestion) {
    if (is_array($suggestion) && (int)($suggestion['technician_id'] ?? 0) === $technicianId) {
        $rankedTech = $suggestion;
        break;
    }
}
$assert(
    '13.5 El recomendador de triaje devuelve el ranking con el técnico y su bloque de cruce de stock',
    $resSuggest['status'] === 200 && $suggestions !== [] && is_array($rankedTech),
    'status=' . $resSuggest['status'] . ' sugerencias=' . count($suggestions)
);

$match = is_array($rankedTech) ? ($rankedTech['van_stock_match'] ?? null) : null;
$assert(
    '13.6 El técnico con la pieza en furgoneta se marca con cobertura completa del nivel evaluado',
    is_array($match)
        && ($match['status'] ?? '') === 'FULL_MATCH'
        && in_array($match['evaluation_level'] ?? '', ['EXPLICIT_REQUESTS', 'CATEGORY_COMPATIBILITY'], true)
        && ($match['badge'] ?? '') === 'STOCK_COMPATIBLE'
        && in_array('STOCK_COMPATIBLE', $rankedTech['badges'] ?? [], true),
    'cruce=' . json_encode($match, JSON_UNESCAPED_UNICODE)
);

$assert(
    '13.7 El cruce es informativo: el ranking conserva puntuación, desglose y recomendación (Art. II)',
    is_array($rankedTech)
        && is_numeric($rankedTech['total_score'] ?? null)
        && is_array($rankedTech['score_breakdown'] ?? null)
        && is_bool($rankedTech['is_top_suggestion'] ?? null)
        && !array_key_exists('van_stock_match', $rankedTech['score_breakdown'] ?? []),
    'puntuación=' . var_export($rankedTech['total_score'] ?? null, true)
        . ' desglose=' . json_encode(array_keys($rankedTech['score_breakdown'] ?? []))
);

// ─────────────────────────────────────────────────────────────────────────
// RESUMEN Y CONCLUSIÓN
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorBold}======================================================================{$colorReset}\n";
echo " Recorrido funcional del Módulo 23 por HTTP real\n";
echo " Orden del ciclo: {$seededOrderId} · alta y cancelación: {$newOrderCode}\n";
echo " Total Aserciones Evaluadas : {$assertions}\n";
echo " Pasadas con Éxito           : " . ($assertions - $failures) . "\n";
echo " Fallos Detectados           : {$failures}\n";
echo "{$colorBold}======================================================================{$colorReset}\n";

if ($failures === 0) {
    echo "{$colorBold}{$colorGreen} RESULTADO: RECORRIDO FUNCIONAL COMPLETO CON CERO FALLOS.{$colorReset}\n";
    echo "{$colorBold}{$colorGreen} Propuesta ➔ alta ➔ bloqueo ➔ cancelación ➔ A4 ➔ baja lógica ➔ expedición ➔ recepción ➔ comprobante ➔ privacidad ➔ insignias.{$colorReset}\n";
    echo "{$colorBold}======================================================================{$colorReset}\n";
    exit(0);
}

echo "{$colorBold}{$colorRed} RESULTADO: DETECTADOS FALLOS EN EL RECORRIDO FUNCIONAL.{$colorReset}\n";
echo "{$colorBold}======================================================================{$colorReset}\n";
exit(1);
