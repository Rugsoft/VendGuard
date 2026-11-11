<?php

declare(strict_types=1);

/**
 * VendGuard — Batería de Pruebas Funcionales, de Humo y E2E para el Rol RESTOCKER (Módulo 31)
 *
 * Ejecuta contra el servidor HTTP en local (http://127.0.0.1:8000):
 * 1. Humo de servidor y estáticos (HTML, JS ESM, CSS, Webmanifest, Service Worker).
 * 2. Autenticación y obtención de tokens de los 4 perfiles.
 * 3. Segregación RBAC estricta (Art. V.3/V.4).
 * 4. CRUD de Personal Interno (/api/coordinator/staff): alta RESTOCKER, rechazo is_on_call, inmutabilidad C1.
 * 5. Cuadrante de Carga en Zonas Operativas: contadores QA 2, asignación, conflicto 409 y traslado confirmado, cobertura 6 niveles, baja idempotente QA 4.
 * 6. Flujo móvil del Reponedor: my-route, ciclo de turno (start, pause-meal, resume, close directo D1), autoconsulta Mis Horas.
 * 7. Ausencias y Registro Horario: filtro por rol, promoted_zones, CSV con columna Rol y UTF-8 BOM.
 * 8. Desactivación de cuenta con turno abierto y cierre automático C3 (FORCE_CLOSE_SHIFT).
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
$colorYellow = "\033[33m";
$colorReset = "\033[0m";

echo "{$colorBold}======================================================================{$colorReset}\n";
echo "{$colorBold} VendGuard: Pruebas Funcionales, de Humo y E2E · Rol RESTOCKER (Módulo 31){$colorReset}\n";
echo "{$colorBold}======================================================================{$colorReset}\n\n";

$baseUrl = 'http://127.0.0.1:8000';
$assertions = 0;
$failures = 0;
$errorsList = [];

$assert = function (string $step, bool $condition, string $detail = '', string $importance = 'ALTA') use (&$assertions, &$failures, &$errorsList, $colorGreen, $colorRed, $colorReset): void {
    $assertions++;
    if ($condition) {
        echo "  {$colorGreen}[PASS]{$colorReset} {$step}\n";
    } else {
        echo "  {$colorRed}[FAIL]{$colorReset} {$step}\n";
        if ($detail !== '') {
            echo "         Detalle: {$detail}\n";
        }
        $failures++;
        $errorsList[] = [
            'step' => $step,
            'detail' => $detail,
            'importance' => $importance
        ];
    }
};

function httpRequest(string $method, string $url, array $data = [], ?string $token = null, array $customHeaders = []): array
{
    $ch = curl_init($url);
    $headers = empty($customHeaders) ? ['Accept: application/json'] : $customHeaders;
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_TIMEOUT => 8,
    ];

    if (!empty($data) || in_array(strtoupper($method), ['POST', 'PATCH', 'PUT'], true)) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $decoded = json_decode((string)$raw, true) ?? [];
    return ['status' => $status, 'body' => $decoded, 'raw' => (string)$raw, 'contentType' => $contentType];
}

// ─────────────────────────────────────────────────────────────────────────
// BLOQUE 1: HUMO DE SERVIDOR Y ASSETS ESTÁTICOS
// ─────────────────────────────────────────────────────────────────────────
echo "{$colorCyan}--- Bloque 1: Humo de Servidor y Estáticos ---{$colorReset}\n";

$resRootHtml = httpRequest('GET', "{$baseUrl}/", [], null, ['Accept: text/html']);
$assert("1.1 GET / con Accept: text/html responde HTTP 200 OK", $resRootHtml['status'] === 200, "Status: {$resRootHtml['status']}");
$assert("1.2 HTML principal contiene título VendGuard", str_contains($resRootHtml['raw'], 'VendGuard') && str_contains($resRootHtml['raw'], '<title>'), "No se encontró el título");
$assert("1.3 HTML contiene contenedor #app para Vue 3", str_contains($resRootHtml['raw'], 'id="app"'), "Falta #app");

$resRestockerView = httpRequest('GET', "{$baseUrl}/assets/js/components/RestockerRouteView.js");
$assert("1.4 GET /assets/js/components/RestockerRouteView.js responde HTTP 200 OK", $resRestockerView['status'] === 200, "Status: {$resRestockerView['status']}");
$assert("1.5 RestockerRouteView.js es un módulo ESM con componente Vue", str_contains($resRestockerView['raw'], 'export default') && str_contains($resRestockerView['raw'], 'name: \'RestockerRouteView\''));

$resAppJs = httpRequest('GET', "{$baseUrl}/assets/js/app.js");
$assert("1.6 GET /assets/js/app.js responde HTTP 200 OK e importa RestockerRouteView", $resAppJs['status'] === 200 && str_contains($resAppJs['raw'], 'RestockerRouteView'));

$resStoreJs = httpRequest('GET', "{$baseUrl}/assets/js/store.js");
$assert("1.7 GET /assets/js/store.js responde HTTP 200 OK y publica isRestocker", $resStoreJs['status'] === 200 && str_contains($resStoreJs['raw'], 'isRestocker'));

$resCss = httpRequest('GET', "{$baseUrl}/assets/css/styles.css");
$assert("1.8 GET /assets/css/styles.css responde HTTP 200 OK", $resCss['status'] === 200);

$resManifest = httpRequest('GET', "{$baseUrl}/manifest.webmanifest");
$assert("1.9 GET /manifest.webmanifest responde HTTP 200 OK", $resManifest['status'] === 200);

// ─────────────────────────────────────────────────────────────────────────
// BLOQUE 2: AUTENTICACIÓN Y ROLES DEL SISTEMA
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Bloque 2: Autenticación de Perfiles (Coordinador, Técnico, Reponedor, Sede) ---{$colorReset}\n";

// Login Coordinador
$resLoginCoord = httpRequest('POST', "{$baseUrl}/api/auth/login", [
    'email' => 'coordinacion@vendguard.internal',
    'password' => 'Password123!'
]);
$assert("2.1 Login Coordinador responde HTTP 200 OK", $resLoginCoord['status'] === 200, "Status: {$resLoginCoord['status']}");
$coordToken = $resLoginCoord['body']['data']['token'] ?? null;
$coordRole = $resLoginCoord['body']['data']['user']['role'] ?? null;
$assert("2.2 Token de Coordinador válido y rol COORDINATOR", !empty($coordToken) && $coordRole === 'COORDINATOR', "Rol: {$coordRole}");

// Login Técnico
$resLoginTech = httpRequest('POST', "{$baseUrl}/api/auth/login", [
    'email' => 'jordi.ruta@vendguard.internal',
    'password' => 'Password123!'
]);
$assert("2.3 Login Técnico responde HTTP 200 OK", $resLoginTech['status'] === 200, "Status: {$resLoginTech['status']}");
$techToken = $resLoginTech['body']['data']['token'] ?? null;
$techRole = $resLoginTech['body']['data']['user']['role'] ?? null;
$assert("2.4 Token de Técnico válido y rol TECHNICIAN", !empty($techToken) && $techRole === 'TECHNICIAN', "Rol: {$techRole}");

// Login Reponedor (Lucía de las semillas)
$resLoginRestocker = httpRequest('POST', "{$baseUrl}/api/auth/login", [
    'email' => 'lucia.repostaje@vendguard.internal',
    'password' => 'Password123!'
]);
$assert("2.5 Login Reponedor (Lucía) responde HTTP 200 OK", $resLoginRestocker['status'] === 200, "Status: {$resLoginRestocker['status']}");
$restockerToken = $resLoginRestocker['body']['data']['token'] ?? null;
$restockerRole = $resLoginRestocker['body']['data']['user']['role'] ?? null;
$assert("2.6 Token de Reponedor válido, rol RESTOCKER y operator_code en login", !empty($restockerToken) && $restockerRole === 'RESTOCKER' && ($resLoginRestocker['body']['data']['user']['operator_code'] ?? '') === 'REP-01', "Rol: {$restockerRole}");

// Login Sede
$resSiteLogin = httpRequest('POST', "{$baseUrl}/api/auth/site-login", [
    'site_code' => 'SEDE-BCN-01',
    'access_code' => SeedRunner::devAccessCode('SEDE-BCN-01')
]);
$assert("2.7 Login Sede responde HTTP 200 OK", $resSiteLogin['status'] === 200, "Status: {$resSiteLogin['status']}");
$siteToken = $resSiteLogin['body']['data']['token'] ?? null;

// ─────────────────────────────────────────────────────────────────────────
// BLOQUE 3: SEGREGACIÓN RBAC ESTRICTA (Art. V.3 y V.4)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Bloque 3: Segregación RBAC (Art. V.3 y V.4) ---{$colorReset}\n";

$satSurfaces = [
    'Ruta SAT (/api/technician/my-route)' => "{$baseUrl}/api/technician/my-route",
    'Stock de furgoneta SAT (/api/technician/van-stock)' => "{$baseUrl}/api/technician/van-stock",
    'Repuestos (/api/technician/spare-parts/catalog)' => "{$baseUrl}/api/technician/spare-parts/catalog",
    'Preventivos SAT (/api/technician/preventive/route)' => "{$baseUrl}/api/technician/preventive/route",
    'Métricas SAT (/api/technician/my-metrics)' => "{$baseUrl}/api/technician/my-metrics",
    'Relevo SAT (/api/technician/shift/close-handover)' => "{$baseUrl}/api/technician/shift/close-handover",
];

foreach ($satSurfaces as $name => $url) {
    $res = httpRequest(str_contains($url, 'close-handover') ? 'POST' : 'GET', $url, [], $restockerToken);
    $assert("3.1 Reponedor bloqueado en {$name} con HTTP 403 Forbidden", $res['status'] === 403, "Status recibido: {$res['status']}");
}

// Reponedor no puede acceder a Coordinación
$resCoordZones = httpRequest('GET', "{$baseUrl}/api/coordinator/zones", [], $restockerToken);
$assert("3.2 Reponedor bloqueado en /api/coordinator/zones con HTTP 403 Forbidden", $resCoordZones['status'] === 403, "Status: {$resCoordZones['status']}");

// Técnico y Coordinador no pueden acceder a la ruta móvil del Reponedor
$resTechMyRoute = httpRequest('GET', "{$baseUrl}/api/restocker/my-route", [], $techToken);
$assert("3.3 Técnico bloqueado en /api/restocker/my-route con HTTP 403 Forbidden", $resTechMyRoute['status'] === 403, "Status: {$resTechMyRoute['status']}");

$resCoordMyRoute = httpRequest('GET', "{$baseUrl}/api/restocker/my-route", [], $coordToken);
$assert("3.4 Coordinador bloqueado en /api/restocker/my-route con HTTP 403 Forbidden", $resCoordMyRoute['status'] === 403, "Status: {$resCoordMyRoute['status']}");

// Anónimo bloqueado
$resAnonMyRoute = httpRequest('GET', "{$baseUrl}/api/restocker/my-route");
$assert("3.5 Anónimo bloqueado en /api/restocker/my-route con HTTP 401 Unauthorized", $resAnonMyRoute['status'] === 401, "Status: {$resAnonMyRoute['status']}");

// ─────────────────────────────────────────────────────────────────────────
// BLOQUE 4: CRUD DE PERSONAL INTERNO (/api/coordinator/staff)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Bloque 4: CRUD de Personal Interno (/api/coordinator/staff) ---{$colorReset}\n";

// 4.1 Rechazo de is_on_call = true para RESTOCKER
$resOnCallReject = httpRequest('POST', "{$baseUrl}/api/coordinator/staff", [
    'name' => 'Reponedor Guardia Ilegal',
    'email' => 'guardia.ilegal@vendguard.internal',
    'password' => 'Password123!',
    'role' => 'RESTOCKER',
    'operator_code' => 'REP-98',
    'is_on_call' => true
], $coordToken);
$errCode = $resOnCallReject['body']['error']['code'] ?? $resOnCallReject['body']['code'] ?? '';
$assert("4.1 Rechazo de is_on_call=true en alta de RESTOCKER con HTTP 422 RESTOCKER_CANNOT_BE_ON_CALL", 
    $resOnCallReject['status'] === 422 && $errCode === 'RESTOCKER_CANNOT_BE_ON_CALL',
    "Status: {$resOnCallReject['status']}, Code: {$errCode}");

// 4.2 Rechazo de falta de operator_code
$resMissingCode = httpRequest('POST', "{$baseUrl}/api/coordinator/staff", [
    'name' => 'Reponedor Sin Codigo',
    'email' => 'sincodigo@vendguard.internal',
    'password' => 'Password123!',
    'role' => 'RESTOCKER'
], $coordToken);
$assert("4.2 Rechazo de falta de operator_code en RESTOCKER con HTTP 400", $resMissingCode['status'] === 400);

// 4.3 Alta válida de RESTOCKER
$smokeRepEmail = 'reponedor.smoke.' . time() . '@vendguard.internal';
$smokeCode = 'REP-' . substr((string)time(), -3);
$resCreateStaff = httpRequest('POST', "{$baseUrl}/api/coordinator/staff", [
    'name' => 'Ramiro Reponedor E2E',
    'email' => $smokeRepEmail,
    'password' => 'Password123!',
    'role' => 'RESTOCKER',
    'operator_code' => $smokeCode,
    'phone' => '688990011'
], $coordToken);
$assert("4.3 Alta exitosa de RESTOCKER responde HTTP 201 Created", $resCreateStaff['status'] === 201, "Status: {$resCreateStaff['status']}, Body: " . $resCreateStaff['raw']);
$newStaffId = $resCreateStaff['body']['data']['id'] ?? null;
$assert("4.4 Nuevo reponedor tiene id válido e is_on_call=false", !empty($newStaffId) && ($resCreateStaff['body']['data']['is_on_call'] ?? null) === false);

// 4.4 Inmutabilidad estricta del rol (C1)
$resMutateRole = httpRequest('PUT', "{$baseUrl}/api/coordinator/staff/{$newStaffId}", [
    'name' => 'Ramiro Mutado',
    'role' => 'TECHNICIAN'
], $coordToken);
$mutateErrCode = $resMutateRole['body']['error']['code'] ?? $resMutateRole['body']['code'] ?? '';
$assert("4.5 Intento de mutar rol RESTOCKER -> TECHNICIAN responde HTTP 409 ROLE_CHANGE_FORBIDDEN (C1)",
    $resMutateRole['status'] === 409 && $mutateErrCode === 'ROLE_CHANGE_FORBIDDEN',
    "Status: {$resMutateRole['status']}, Code: {$mutateErrCode}");

// Reenviar mismo rol sí se admite
$resSameRole = httpRequest('PUT', "{$baseUrl}/api/coordinator/staff/{$newStaffId}", [
    'name' => 'Ramiro Nombre Actualizado',
    'role' => 'RESTOCKER'
], $coordToken);
$assert("4.6 Reenviar el mismo rol en edición responde HTTP 200 OK", $resSameRole['status'] === 200, "Status: {$resSameRole['status']}");

// 4.5 Listado con filtro por rol
$resListStaff = httpRequest('GET', "{$baseUrl}/api/coordinator/staff?role=RESTOCKER", [], $coordToken);
$assert("4.7 GET /api/coordinator/staff?role=RESTOCKER responde HTTP 200 OK y contiene reponedores",
    $resListStaff['status'] === 200 && !empty($resListStaff['body']['data']),
    "Status: {$resListStaff['status']}");

// ─────────────────────────────────────────────────────────────────────────
// BLOQUE 5: ZONIFICACIÓN DE CARGA Y COBERTURA DETERMINISTA
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Bloque 5: Cuadrante de Carga y Cobertura Determinista (RF-02, RF-03) ---{$colorReset}\n";

$resZones = httpRequest('GET', "{$baseUrl}/api/coordinator/zones", [], $coordToken);
$assert("5.1 GET /api/coordinator/zones responde HTTP 200 OK", $resZones['status'] === 200);
$zonesList = $resZones['body']['data']['zones'] ?? [];
$assert("5.2 Zonas operativas incluyen contadores primary_restockers_count y backup_restockers_count (QA 2)",
    !empty($zonesList) && isset($zonesList[0]['primary_restockers_count'], $zonesList[0]['backup_restockers_count']),
    "Contadores ausentes en primera zona");

$firstZoneId = $zonesList[0]['id'] ?? null;
$secondZoneId = $zonesList[1]['id'] ?? null;

// Asignar a Ramiro como PRIMARY en Zona 1
$resAssignPrimary = httpRequest('POST', "{$baseUrl}/api/coordinator/zones/{$firstZoneId}/restockers", [
    'restocker_id' => $newStaffId,
    'role_in_zone' => 'PRIMARY'
], $coordToken);
$assert("5.3 Asignación de Titular (PRIMARY) a Zona {$firstZoneId} responde HTTP 200 OK",
    $resAssignPrimary['status'] === 200, "Status: {$resAssignPrimary['status']}");

// Intentar asignar a Ramiro como PRIMARY en Zona 2 sin confirmación (Conflicto 409)
$resAssignConflict = httpRequest('POST', "{$baseUrl}/api/coordinator/zones/{$secondZoneId}/restockers", [
    'restocker_id' => $newStaffId,
    'role_in_zone' => 'PRIMARY'
], $coordToken);
$conflictErrCode = $resAssignConflict['body']['error']['code'] ?? $resAssignConflict['body']['code'] ?? '';
$assert("5.4 Intento de segunda titularidad sin confirmar responde HTTP 409 PRIMARY_ZONE_ALREADY_ASSIGNED",
    $resAssignConflict['status'] === 409 && $conflictErrCode === 'PRIMARY_ZONE_ALREADY_ASSIGNED',
    "Status: {$resAssignConflict['status']}, Code: {$conflictErrCode}");

// Con confirm_primary_transfer = true, debe degradar la anterior y asignar la nueva
$resAssignTransfer = httpRequest('POST', "{$baseUrl}/api/coordinator/zones/{$secondZoneId}/restockers", [
    'restocker_id' => $newStaffId,
    'role_in_zone' => 'PRIMARY',
    'confirm_primary_transfer' => true
], $coordToken);
$assert("5.5 Traslado confirmado con confirm_primary_transfer=true responde HTTP 200 OK",
    $resAssignTransfer['status'] === 200, "Status: {$resAssignTransfer['status']}");

// Consultar cobertura de carga de Zona 2
$resCoverage = httpRequest('GET', "{$baseUrl}/api/coordinator/zones/{$secondZoneId}/restocker-coverage", [], $coordToken);
$assert("5.6 GET /zones/{id}/restocker-coverage responde HTTP 200 OK con escalafón de cobertura",
    $resCoverage['status'] === 200 && isset($resCoverage['body']['data']['coverage_status']),
    "Status: {$resCoverage['status']}");
$coverageData = $resCoverage['body']['data'] ?? [];
$assert("5.7 Cobertura incluye candidates y recommended_restocker",
    isset($coverageData['candidates']) && array_key_exists('recommended_restocker', $coverageData));

// Consulta inversa por operario
$resQuadrants = httpRequest('GET', "{$baseUrl}/api/coordinator/restockers/quadrants", [], $coordToken);
$assert("5.8 GET /api/coordinator/restockers/quadrants responde HTTP 200 OK (consulta inversa)",
    $resQuadrants['status'] === 200 && is_array($resQuadrants['body']['data'] ?? null));

// Baja lógica idempotente (QA 4)
$resRemove1 = httpRequest('DELETE', "{$baseUrl}/api/coordinator/zones/{$secondZoneId}/restockers/{$newStaffId}", [], $coordToken);
$assert("5.9 Baja lógica de reponedor en zona responde HTTP 200 OK", $resRemove1['status'] === 200, "Status: {$resRemove1['status']}");
$resRemove2 = httpRequest('DELETE', "{$baseUrl}/api/coordinator/zones/{$secondZoneId}/restockers/{$newStaffId}", [], $coordToken);
$assert("5.10 Segunda baja (idempotente) responde HTTP 200 OK sin errores (QA 4)", $resRemove2['status'] === 200, "Status: {$resRemove2['status']}");

// ─────────────────────────────────────────────────────────────────────────
// BLOQUE 6: VISTA MÓVIL Y CICLO DE TURNO DEL REPONEDOR
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Bloque 6: Vista Móvil y Ciclo de Turno del Reponedor (RF-04, D1) ---{$colorReset}\n";

// Login con el reponedor recién creado
$resLoginRamiro = httpRequest('POST', "{$baseUrl}/api/auth/login", [
    'email' => $smokeRepEmail,
    'password' => 'Password123!'
]);
$ramiroToken = $resLoginRamiro['body']['data']['token'] ?? null;
$assert("6.1 Login de nuevo reponedor responde HTTP 200 OK", !empty($ramiroToken));

// Bootstrap móvil
$resMyRoute = httpRequest('GET', "{$baseUrl}/api/restocker/my-route", [], $ramiroToken);
$assert("6.2 GET /api/restocker/my-route responde HTTP 200 OK", $resMyRoute['status'] === 200, "Status: {$resMyRoute['status']}");
$myRouteData = $resMyRoute['body']['data'] ?? [];
$assert("6.3 my-route devuelve perfil con operator_code, zones y shift",
    isset($myRouteData['restocker']['operator_code'], $myRouteData['shift'], $myRouteData['zones']),
    "Estructura devuelta: " . json_encode(array_keys($myRouteData)));

// Ciclo de turno: Iniciar Turno
$resStartShift = httpRequest('POST', "{$baseUrl}/api/technician/shift/start", [], $ramiroToken);
$assert("6.4 Reponedor inicia turno (/api/technician/shift/start) responde HTTP 200 OK",
    $resStartShift['status'] === 200, "Status: {$resStartShift['status']}");

// Pausa para comer
$resPauseMeal = httpRequest('POST', "{$baseUrl}/api/technician/shift/pause-meal", [], $ramiroToken);
$assert("6.5 Reponedor inicia pausa de comida (/api/technician/shift/pause-meal) responde HTTP 200 OK",
    $resPauseMeal['status'] === 200, "Status: {$resPauseMeal['status']}");

// Reanudar turno
$resResumeShift = httpRequest('POST', "{$baseUrl}/api/technician/shift/resume", [], $ramiroToken);
$assert("6.6 Reponedor reanuda turno (/api/technician/shift/resume) responde HTTP 200 OK",
    $resResumeShift['status'] === 200, "Status: {$resResumeShift['status']}");

// Cierre directo sin relevo SAT (D1)
$resDirectClose = httpRequest('POST', "{$baseUrl}/api/technician/shift/close", [], $ramiroToken);
$assert("6.7 Reponedor ejecuta cierre directo (/api/technician/shift/close) responde HTTP 200 OK (D1)",
    $resDirectClose['status'] === 200, "Status: {$resDirectClose['status']}");

// Intento de cierre directo por parte de un Técnico SAT debe ser rechazado
$resTechDirectClose = httpRequest('POST', "{$baseUrl}/api/technician/shift/close", [], $techToken);
$techCloseErrCode = $resTechDirectClose['body']['error']['code'] ?? $resTechDirectClose['body']['code'] ?? '';
$assert("6.8 Técnico SAT recibe HTTP 409 SHIFT_DIRECT_CLOSE_FORBIDDEN al intentar cierre directo",
    $resTechDirectClose['status'] === 409 && $techCloseErrCode === 'SHIFT_DIRECT_CLOSE_FORBIDDEN',
    "Status: {$resTechDirectClose['status']}, Code: {$techCloseErrCode}");

// Autoconsulta Mis Horas
$resMyHours = httpRequest('GET', "{$baseUrl}/api/technician/shift/my-hours", [], $ramiroToken);
$assert("6.9 Autoconsulta «Mis Horas» responde HTTP 200 OK",
    $resMyHours['status'] === 200 && isset($resMyHours['body']['data']['sessions']),
    "Status: {$resMyHours['status']}");

// ─────────────────────────────────────────────────────────────────────────
// BLOQUE 7: AUSENCIAS Y REGISTRO HORARIO EN COORDINACIÓN
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Bloque 7: Ausencias y Registro Horario (RF-05, D2, QA 4) ---{$colorReset}\n";

// Registrar ausencia para el reponedor
$resCreateAbsence = httpRequest('POST', "{$baseUrl}/api/coordinator/absences", [
    'technician_id' => $newStaffId,
    'reason' => 'VACATION',
    'starts_at' => date('Y-m-d', strtotime('+7 days')) . 'T08:00:00+01:00',
    'ends_at' => date('Y-m-d', strtotime('+8 days')) . 'T17:00:00+01:00',
    'is_full_day' => true
], $coordToken);
$assert("7.1 Alta de ausencia para RESTOCKER responde HTTP 201 Created y omite asistente SAT",
    $resCreateAbsence['status'] === 201 && isset($resCreateAbsence['body']['data']['promoted_zones']),
    "Status: {$resCreateAbsence['status']}");

// Consulta de ausencias filtrando por RESTOCKER
$resListAbsences = httpRequest('GET', "{$baseUrl}/api/coordinator/absences?role=RESTOCKER", [], $coordToken);
$assert("7.2 GET /api/coordinator/absences?role=RESTOCKER responde HTTP 200 OK",
    $resListAbsences['status'] === 200 && is_array($resListAbsences['body']['data'] ?? null));

// Informe de Registro de Jornada con filtro RESTOCKER
$resWorkHours = httpRequest('GET', "{$baseUrl}/api/coordinator/metrics/work-hours?role=RESTOCKER", [], $coordToken);
$assert("7.3 GET /api/coordinator/metrics/work-hours?role=RESTOCKER responde HTTP 200 OK",
    $resWorkHours['status'] === 200, "Status: {$resWorkHours['status']}");
$workHoursData = $resWorkHours['body']['data'] ?? [];
$byTechRows = $workHoursData['by_technician'] ?? [];
$foundRestocker = false;
foreach ($byTechRows as $row) {
    if (($row['technician_role'] ?? '') === 'RESTOCKER' && ($row['resolved_interventions_count'] ?? -1) === 0) {
        $foundRestocker = true;
        break;
    }
}
$assert("7.4 Informe incluye by_technician de reponedores con technician_role=RESTOCKER y 0 intervenciones",
    $foundRestocker,
    "No se encontró reponedor en by_technician");

// Exportación CSV
$resCsv = httpRequest('GET', "{$baseUrl}/api/coordinator/metrics/work-hours/export?role=RESTOCKER", [], $coordToken);
$assert("7.5 GET /api/coordinator/metrics/work-hours/export?role=RESTOCKER responde HTTP 200 OK y text/csv",
    $resCsv['status'] === 200 && str_contains($resCsv['contentType'], 'text/csv'),
    "Status: {$resCsv['status']}, ContentType: {$resCsv['contentType']}");
$assert("7.6 Exportación CSV arranca con UTF-8 BOM y contiene columna Rol",
    str_starts_with($resCsv['raw'], "\xEF\xBB\xBF") && str_contains($resCsv['raw'], ';Rol;'),
    "CSV no contiene BOM o columna Rol");

// ─────────────────────────────────────────────────────────────────────────
// BLOQUE 8: DESACTIVACIÓN CON TURNO ABIERTO Y CIERRE AUTOMÁTICO (C3)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Bloque 8: Desactivación con Turno Abierto y Cierre Automático (C3) ---{$colorReset}\n";

// Abrir un nuevo turno para Ramiro
$resStartShift2 = httpRequest('POST', "{$baseUrl}/api/technician/shift/start", [], $ramiroToken);
$assert("8.1 Reponedor abre nuevo turno para prueba de baja", $resStartShift2['status'] === 200);

// Coordinador desactiva la cuenta de Ramiro
$resDeactivate = httpRequest('PUT', "{$baseUrl}/api/coordinator/staff/{$newStaffId}", [
    'is_active' => false
], $coordToken);
$assert("8.2 Desactivación de cuenta con turno abierto responde HTTP 200 OK",
    $resDeactivate['status'] === 200, "Status: {$resDeactivate['status']}");

// Comprobar que en el informe de Coordinación la última sesión de Ramiro quedó cerrada con FORCE_CLOSE_SHIFT
$resWorkHoursCheck = httpRequest('GET', "{$baseUrl}/api/coordinator/metrics/work-hours?role=RESTOCKER", [], $coordToken);
$allSessions = $resWorkHoursCheck['body']['data']['sessions'] ?? [];
$closedSession = null;
foreach ($allSessions as $s) {
    if (($s['technician_id'] ?? null) === $newStaffId && ($s['is_force_closed'] ?? false) === true) {
        $closedSession = $s;
        break;
    }
}
$assert("8.3 El turno abierto fue cerrado automáticamente con is_force_closed=true y motivo contractual (C3)",
    $closedSession !== null && ($closedSession['close_reason'] ?? '') === 'Cierre automático por desactivación de cuenta de usuario',
    "Sesión cerrada forzosamente no encontrada o motivo incorrecto");

// ─────────────────────────────────────────────────────────────────────────
// RESUMEN Y RESULTADO FINAL
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorBold}======================================================================{$colorReset}\n";
echo " Total Aserciones E2E: {$assertions} | Aprobadas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";
if ($failures === 0) {
    echo " {$colorGreen}RESULTADO: 100% EN VERDE. PRUEBAS FUNCIONALES Y E2E SUPERADAS.{$colorReset}\n";
} else {
    echo " {$colorRed}RESULTADO: SE DETECTARON {$failures} FALLOS.{$colorReset}\n";
}
echo "{$colorBold}======================================================================{$colorReset}\n";

file_put_contents(__DIR__ . '/restocker_e2e_result.json', json_encode([
    'total' => $assertions,
    'passed' => $assertions - $failures,
    'failed' => $failures,
    'errors' => $errorsList
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

exit($failures === 0 ? 0 : 1);
