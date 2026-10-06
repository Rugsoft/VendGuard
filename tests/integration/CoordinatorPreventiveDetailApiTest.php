<?php

declare(strict_types=1);

/**
 * CoordinatorPreventiveDetailApiTest
 *
 * Test de Integración HTTP de la ficha integral de órdenes preventivas (T-PREV-28, T-PREV-31).
 * Requisitos: RF-PD-01 a RF-PD-10, RNF-PD-01, RNF-PD-04, RNF-PD-05, RNF-PD-07.
 *
 * Valida la condición "Hecho cuando:":
 * 1. Seguridad RBAC: acceso anónimo devuelve 401 y el rol TECHNICIAN devuelve 403 (Art. V.4).
 * 2. GET /api/coordinator/preventive/orders/{id}/detail devuelve 200 con los nueve bloques.
 * 3. La orden se resuelve tanto por ID primario como por código de orden.
 * 4. Identificador inexistente => 404 PREVENTIVE_ORDER_NOT_FOUND.
 * 5. La orden completada publica checklist respondido, dictamen, certificado y auditoría.
 * 6. La orden pendiente publica estados vacíos explícitos (checklist, avería, certificado).
 * 7. Lectura pura: varios GET no alteran ninguna fila (Art. III, RNF-PD-04).
 *
 * Dogma Vanilla: Cero dependencias externas (PHP 8.2+ puro).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test Integración - CoordinatorPreventiveDetailApiTest\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$userRepo = new PdoUserRepository($pdo);
$locationRepo = new PdoLocationRepository($pdo);
$authService = new AuthService($locationRepo, $userRepo);

$failures = 0;
$assertions = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures, &$assertions): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }
    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');
$assert('0.1 Usuarios semilla encontrados', $coordinator !== null && $technician !== null);
if ($coordinator === null || $technician === null) {
    echo "ERROR CRÍTICO: Usuarios semilla no encontrados.\n";
    exit(1);
}

$coordToken = $authService->generateInternalToken($coordinator);
$techToken = $authService->generateInternalToken($technician);

// Orden completada con checklist sembrado y certificado vinculado.
$completedOrderRow = $pdo->query("
    SELECT `id`, `order_code`
    FROM `preventive_orders`
    WHERE `order_code` = 'ORD-PREV-2026-0001'
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$assert('0.2 Orden completada semilla encontrada', $completedOrderRow !== false);
if ($completedOrderRow === false) {
    echo "ERROR CRÍTICO: La semilla preventiva no está cargada.\n";
    exit(1);
}

// Orden pendiente de asignación (sin checklist ni certificado asociado a la inspección).
$pendingOrderRow = $pdo->query("
    SELECT `id`, `order_code`
    FROM `preventive_orders`
    WHERE `order_code` = 'ORD-PREV-2026-0003'
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$assert('0.3 Orden pendiente semilla encontrada', $pendingOrderRow !== false);

$completedOrderId = (int)$completedOrderRow['id'];
$completedOrderCode = (string)$completedOrderRow['order_code'];
$pendingOrderId = $pendingOrderRow !== false ? (int)$pendingOrderRow['id'] : 0;

$detailUrl = "/api/coordinator/preventive/orders/{$completedOrderId}/detail";

// =========================================================================
// BLOQUE 1: Seguridad RBAC (401/403)
// =========================================================================
echo "\n--- BLOQUE 1: Seguridad RBAC en la ficha preventiva ---\n";

$resAnon = $router->dispatch(new Request('GET', $detailUrl));
$assert('1.1 Ficha anónima => 401', $resAnon->getStatusCode() === 401);

$resTech = $router->dispatch(new Request('GET', $detailUrl, [], [], ['Authorization' => 'Bearer ' . $techToken]));
$assert('1.2 Ficha con rol TECHNICIAN => 403 (Art. V.4)', $resTech->getStatusCode() === 403);

// =========================================================================
// BLOQUE 2: Ficha completa de una orden completada
// =========================================================================
echo "\n--- BLOQUE 2: Ficha de orden completada ---\n";

$resDetail = $router->dispatch(new Request('GET', $detailUrl, [], [], ['Authorization' => 'Bearer ' . $coordToken]));
$assert('2.1 La ficha responde HTTP 200', $resDetail->getStatusCode() === 200);

$bodyDetail = json_decode($resDetail->getBody(), true);
$assert('2.2 Envolvente canónica con success=true', ($bodyDetail['success'] ?? false) === true);

$data = $bodyDetail['data'] ?? [];
$expectedBlocks = ['order', 'location', 'machine', 'technician', 'validity', 'checklist', 'linked_incident', 'certificate', 'audit_trail'];
foreach ($expectedBlocks as $block) {
    $assert("2.3 Bloque '{$block}' presente en el contrato", array_key_exists($block, $data));
}

$assert('2.4 Cabecera con código, estado y tipo traducidos',
    ($data['order']['order_code'] ?? null) === $completedOrderCode
    && ($data['order']['status'] ?? null) === 'COMPLETED'
    && ($data['order']['status_label'] ?? null) === 'Completada'
    && ($data['order']['order_type_label'] ?? null) === 'Ordinaria');
$assert('2.5 Dictamen y temperatura de la inspección',
    ($data['order']['result'] ?? null) === 'CONFORME'
    && ($data['order']['result_label'] ?? null) === 'Conforme'
    && ($data['order']['temperature_measured'] ?? null) === 3.2);
$assert('2.6 Sede y máquina con código de sede y tipología',
    ($data['location']['site_code'] ?? null) === 'SEDE-BCN-01'
    && ($data['machine']['code'] ?? null) === 'VEND-0101'
    && ($data['machine']['has_perishables'] ?? false) === true);
$assert('2.7 Técnico inspector con Código de Operador Oficial',
    ($data['technician']['assigned'] ?? false) === true
    && ($data['technician']['operator_code'] ?? null) === 'OP-01');
$assert('2.8 Orden cerrada con balance histórico y sin cuenta atrás',
    ($data['validity']['state'] ?? null) === 'CERRADA'
    && ($data['validity']['days_remaining'] ?? null) === null
    && str_contains((string)($data['validity']['balance_label'] ?? ''), 'Inspección completada el'));

// =========================================================================
// BLOQUE 3: Checklist, certificado y auditoría de la orden sembrada
// =========================================================================
echo "\n--- BLOQUE 3: Checklist normativo sembrado ---\n";

$checklist = $data['checklist'] ?? [];
$assert('3.1 La orden completada publica checklist registrado', ($checklist['has_checklist'] ?? false) === true);
$assert('3.2 Contadores coherentes con la semilla (7 ítems: 5 PASS, 1 WARN, 1 N/A)',
    ($checklist['totals']['total'] ?? 0) === 7
    && ($checklist['totals']['pass'] ?? 0) === 5
    && ($checklist['totals']['warn'] ?? 0) === 1
    && ($checklist['totals']['not_applicable'] ?? 0) === 1
    && ($checklist['totals']['fail'] ?? -1) === 0
    && ($checklist['totals']['critical_failures'] ?? -1) === 0);
$assert('3.3 Porcentaje de cumplimiento sobre ítems evaluables', ($checklist['compliance_percent'] ?? null) === 83);
$assert('3.4 Ítems con severidad, etiqueta traducida y observaciones',
    count($checklist['items'] ?? []) === 7
    && ($checklist['items'][0]['is_critical'] ?? false) === true
    && ($checklist['items'][0]['status_label'] ?? '') !== ''
    && isset($checklist['items'][0]['observations']));

$itemStatuses = array_map(static fn (array $item): string => (string)$item['status'], $checklist['items'] ?? []);
$assert('3.5 El aviso secundario de la semilla está presente', in_array('WARN', $itemStatuses, true));

echo "\n--- BLOQUE 4: Certificado sanitario y trazabilidad ---\n";

$certificate = $data['certificate'] ?? null;
$assert('4.1 El certificado de la máquina se publica en modo consulta', $certificate !== null
    && ($certificate['certificate_code'] ?? null) === 'CERT-2026-0001'
    && ($certificate['status'] ?? null) === 'VALID'
    && ($certificate['inspector']['operator_code'] ?? null) === 'OP-01');
$assert('4.2 La avería vinculada es nula cuando la inspección no abrió correctivo',
    array_key_exists('linked_incident', $data) && $data['linked_incident'] === null);

// La cronología de la orden se compone desde la entidad MACHINE de su máquina
// (así la escriben los controladores y servicios reales del módulo), filtrada por
// acciones preventivas y atribuida por `metadata.order_code`.
$auditRows = $pdo->query("
    SELECT COUNT(*)
    FROM `audit_log`
    WHERE `entity_type` = 'MACHINE'
      AND `entity_id` = (SELECT `machine_id` FROM `preventive_orders` WHERE `id` = {$completedOrderId})
      AND `action` IN (
        'CREATE_PREVENTIVE_ORDER', 'ASSIGN_PREVENTIVE_ORDER', 'CLAIM_PREVENTIVE_ORDER',
        'START_PREVENTIVE_INSPECTION', 'EVALUATE_PREVENTIVE_CHECKLIST',
        'CANCEL_PREVENTIVE_ORDER', 'ISSUE_SANITARY_CERTIFICATE',
        'SUSPEND_SANITARY_CERTIFICATE', 'REINSPECTION_COMPLETED'
      )
      AND JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.\"order_code\"')) = '{$completedOrderCode}'
")->fetchColumn();
$assert('4.3 La cronología de auditoría refleja las filas reales de la orden (entidad MACHINE + order_code)',
    is_array($data['audit_trail'] ?? null)
    && count($data['audit_trail']) === (int)$auditRows
    && (int)$auditRows > 0);

// =========================================================================
// BLOQUE 5: Resolución por código y estados vacíos
// =========================================================================
echo "\n--- BLOQUE 5: Resolución por código y estados vacíos ---\n";

$resByCode = $router->dispatch(new Request(
    'GET',
    "/api/coordinator/preventive/orders/{$completedOrderCode}/detail",
    [],
    [],
    ['Authorization' => 'Bearer ' . $coordToken]
));
$assert('5.1 El endpoint acepta el código de orden como identificador', $resByCode->getStatusCode() === 200);
$bodyByCode = json_decode($resByCode->getBody(), true);
$assert('5.2 El código de orden devuelve la misma ficha',
    ($bodyByCode['data']['order']['id'] ?? null) === $completedOrderId);

$resByHashCode = $router->dispatch(new Request(
    'GET',
    "/api/coordinator/preventive/orders/%23{$completedOrderCode}/detail",
    [],
    [],
    ['Authorization' => 'Bearer ' . $coordToken]
));
$assert('5.3 El código admite la almohadilla inicial', $resByHashCode->getStatusCode() === 200);

$resMissing = $router->dispatch(new Request(
    'GET',
    '/api/coordinator/preventive/orders/999999/detail',
    [],
    [],
    ['Authorization' => 'Bearer ' . $coordToken]
));
$assert('5.4 Orden inexistente => 404', $resMissing->getStatusCode() === 404);
$bodyMissing = json_decode($resMissing->getBody(), true);
$assert('5.5 El error 404 publica el código PREVENTIVE_ORDER_NOT_FOUND',
    ($bodyMissing['error']['code'] ?? null) === 'PREVENTIVE_ORDER_NOT_FOUND');

if ($pendingOrderId > 0) {
    $resPending = $router->dispatch(new Request(
        'GET',
        "/api/coordinator/preventive/orders/{$pendingOrderId}/detail",
        [],
        [],
        ['Authorization' => 'Bearer ' . $coordToken]
    ));
    $pendingData = json_decode($resPending->getBody(), true)['data'] ?? [];
    $assert('5.6 La orden pendiente responde 200', $resPending->getStatusCode() === 200);
    // `?? 'missing'` no sirve aquí: el operador de coalescencia trata null como ausente y
    // devolvería 'missing' precisamente en el caso válido. Se comprueba la clave explícita.
    $assert('5.7 Orden pendiente: técnico sin asignar, checklist vacío y sin correctivo vinculado',
        ($pendingData['technician']['assigned'] ?? true) === false
        && ($pendingData['checklist']['has_checklist'] ?? true) === false
        && ($pendingData['checklist']['items'] ?? null) === []
        && array_key_exists('linked_incident', $pendingData)
        && $pendingData['linked_incident'] === null);
    $assert('5.7b La máquina pendiente de primera inspección aún no tiene certificado',
        array_key_exists('certificate', $pendingData) && $pendingData['certificate'] === null);
    $assert('5.8 Orden pendiente abierta con semáforo calculado',
        in_array($pendingData['validity']['state'] ?? '', ['VIGENTE', 'PROXIMA_A_VENCER', 'VENCIDA', 'CUARENTENA', 'PAUSA_ESTACIONAL'], true)
        && ($pendingData['validity']['days_remaining'] ?? null) !== null);
}

// =========================================================================
// BLOQUE 6: Lectura pura (Art. III, RNF-PD-04)
// =========================================================================
echo "\n--- BLOQUE 6: Lectura pura de la ficha ---\n";

$countRows = static function (PDO $pdo, string $table): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
};

$before = [
    'orders' => $countRows($pdo, 'preventive_orders'),
    'items' => $countRows($pdo, 'preventive_order_items'),
    'certificates' => $countRows($pdo, 'sanitary_certificates'),
    'audit' => $countRows($pdo, 'audit_log'),
];

for ($i = 0; $i < 3; $i++) {
    $router->dispatch(new Request('GET', $detailUrl, [], [], ['Authorization' => 'Bearer ' . $coordToken]));
    $router->dispatch(new Request('GET', "/api/coordinator/preventive/orders/{$completedOrderCode}/detail", [], [], ['Authorization' => 'Bearer ' . $coordToken]));
}

$after = [
    'orders' => $countRows($pdo, 'preventive_orders'),
    'items' => $countRows($pdo, 'preventive_order_items'),
    'certificates' => $countRows($pdo, 'sanitary_certificates'),
    'audit' => $countRows($pdo, 'audit_log'),
];

$assert('6.1 Seis lecturas consecutivas no alteran ninguna tabla (Art. III)',
    $before === $after,
    'Antes: ' . json_encode($before) . ' / Después: ' . json_encode($after));

// =========================================================================
// Resultado final
// =========================================================================
echo "\n======================================================================\n";
$passed = $assertions - $failures;
echo " Total Assertions: {$assertions} | Passed: {$passed} | Failed: {$failures}\n";
if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. T-PREV-28 Y T-PREV-31 VERIFICADOS.\n";
} else {
    echo " RESULT: FALLOS DETECTADOS. Revisar las aserciones anteriores.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
