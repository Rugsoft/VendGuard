<?php

declare(strict_types=1);

/**
 * VendGuard — Guion de Verificación Manual de Extremo a Extremo (E2E) (T-40)
 * 
 * Ejecuta el recorrido funcional completo de los 3 perfiles sobre el servidor local:
 * 
 * 1. Perfil 1 (Responsable de Sede):
 *    - Login con SEDE-BCN-01.
 *    - Consulta de catálogo de máquinas.
 *    - Creación de ticket en máquina de alimentos perecederos (cálculo automático de urgencia CRITICAL).
 *    - Comprobación preventiva de no-duplicidad (rechazo con HTTP 409).
 *    - Incorporación de comentarios/evidencias adicionales al ticket abierto.
 * 
 * 2. Perfil 2 (Coordinador de Operaciones):
 *    - Login con coordinacion@vendguard.internal (rol COORDINATOR).
 *    - Inspección de bandeja de triaje y visualización de avería sin asignar.
 *    - Asignación técnica a Jordi Técnico de Ruta.
 * 
 * 3. Perfil 3 (Técnico de Ruta de Campo):
 *    - Login con jordi.ruta@vendguard.internal (rol TECHNICIAN).
 *    - Consulta de la vista móvil "Mi Ruta".
 *    - Inicio de intervención in situ (transición a EN_CURSO y fijado de started_at).
 *    - Intento de resolución con textos breves (verificación de rechazo con HTTP 422 por < 20 chars).
 *    - Resolución formal documentada (diagnóstico >= 20 chars, acción >= 20 chars).
 *    - Transición a RESUELTA y activación del reloj de garantía de 48h.
 * 
 * 4. Ciclo de Garantía y Reapertura:
 *    - Responsable comprueba máquina en estado RESUELTA dentro de garantía.
 *    - Ejecución de reapertura con motivo obligatorio.
 *    - Verificación de desasignación automática del técnico y reinicio de la garantía.
 * 
 * Hecho cuando: Se completa con éxito el recorrido funcional: reporte por sede ➔ triaje/asignación
 *               ➔ intervención y resolución móvil ➔ comprobación de no-duplicidad y reapertura.
 * 
 * Dogma Vanilla: PHP 8.2+ puro con cURL nativo y PDO sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;

// Colores de salida en terminal
$colorBold  = "\033[1m";
$colorGreen = "\033[32m";
$colorRed   = "\033[31m";
$colorCyan  = "\033[36m";
$colorReset = "\033[0m";

echo "{$colorBold}======================================================================{$colorReset}\n";
echo "{$colorBold} VendGuard: Guion de Verificación E2E de los 3 Perfiles (T-40){$colorReset}\n";
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
        CURLOPT_TIMEOUT => 6,
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

    $decoded = json_decode((string)$raw, true) ?? [];
    return ['status' => $status, 'body' => $decoded, 'raw' => $raw];
}

// ─────────────────────────────────────────────────────────────────────────
// FASE 0: PREPARACIÓN DEL ENTORNO LIMPIO
// ─────────────────────────────────────────────────────────────────────────
echo "{$colorCyan}--- Paso 0: Preparación de Datos Semilla en MariaDB ---{$colorReset}\n";

$pdo = ConnectionFactory::getConnection();

// Limpiar histórico e incidencias previas para un test E2E determinista
$pdo->exec("DELETE FROM incident_comments");
$pdo->exec("DELETE FROM incident_history");
$pdo->exec("DELETE FROM incidents");

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$checkMachines = $pdo->query("SELECT id, code, machine_type FROM machines WHERE code = 'VEND-0101'")->fetch(PDO::FETCH_ASSOC);
$assert("0.1 Entorno inicializado y máquina VEND-0101 confirmada en MariaDB", !empty($checkMachines['id']));
$vend0101Id = (int)$checkMachines['id'];

// ─────────────────────────────────────────────────────────────────────────
// FASE 1: PERFIL 1 — RESPONSABLE DE UBICACIÓN (SEDE-BCN-01)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 1: Perfil Responsable de Ubicación (SEDE-BCN-01) ---{$colorReset}\n";

// 1.1 Login por código de sede (RF-01)
$resSiteLogin = makeHttpRequest('POST', "{$baseUrl}/api/auth/site-login", [
    'site_code' => 'SEDE-BCN-01'
]);
$assert("1.1 Login de Sede con SEDE-BCN-01 responde HTTP 200 OK", $resSiteLogin['status'] === 200);
$siteToken = $resSiteLogin['body']['data']['token'] ?? null;
$assert("1.2 Token de sede emitido correctamente", !empty($siteToken));

// 1.2 Consulta de catálogo de máquinas de la sede (RF-01)
$resMachines = makeHttpRequest('GET', "{$baseUrl}/api/locations/SEDE-BCN-01/machines", [], $siteToken);
$assert("1.3 Catálogo de máquinas devuelve HTTP 200 OK", $resMachines['status'] === 200);
$machinesList = $resMachines['body']['data'] ?? [];
$foundMachine = null;
foreach ($machinesList as $m) {
    if (($m['code'] ?? '') === 'VEND-0101') {
        $foundMachine = $m;
        break;
    }
}
$assert("1.4 Máquina VEND-0101 presente en catálogo de sede", $foundMachine !== null);
$assert("1.5 Máquina VEND-0101 no tiene avería activa previa", empty($foundMachine['active_incident']));

// 1.3 Creación de aviso de avería de frío en máquina de perecederos (RF-02, RF-03, Art. II)
$incidentPayload = [
    'machine_id'     => $vend0101Id,
    'site_code'      => 'SEDE-BCN-01',
    'category'       => 'TEMPERATURE_COLD',
    'description'    => 'Avería grave en compresor: el termómetro de la máquina marca 16ºC en la bandeja de ensaladas y sándwiches frescos.',
    'reporter_name'  => 'Laura Sanitaria',
    'reporter_phone' => '600111222'
];
$resCreate = makeHttpRequest('POST', "{$baseUrl}/api/incidents", $incidentPayload, $siteToken);
$assert("1.6 Creación de incidencia responde HTTP 201 Created", $resCreate['status'] === 201);
$createdIncident = $resCreate['body']['data'] ?? [];
$ticketCode = $createdIncident['ticket_code'] ?? '';
$incidentId = (int)($createdIncident['id'] ?? 0);
$assert("1.7 Ticket de avería generado con código único ({$ticketCode})", !empty($ticketCode) && $incidentId > 0);
$assert("1.8 Cálculo automático de urgencia asigna CRITICAL (Art. II Seguridad Alimentaria)", ($createdIncident['urgency'] ?? '') === 'CRITICAL');
$assert("1.9 Estado inicial de la incidencia es REGISTRADA (REGISTERED)", ($createdIncident['status'] ?? '') === 'REGISTERED');

// 1.4 Comprobación preventiva de no-duplicidad (RF-02 / EARS 2.1)
$resDuplicate = makeHttpRequest('POST', "{$baseUrl}/api/incidents", [
    'machine_id'     => $vend0101Id,
    'site_code'      => 'SEDE-BCN-01',
    'category'       => 'OTHER',
    'description'    => 'Segundo intento de reporte de la misma máquina por otro compañero.',
    'reporter_name'  => 'Carlos Compañero',
    'reporter_phone' => '600222333'
], $siteToken);
$assert("1.10 Intento de duplicado es rechazado con HTTP 409 Conflict", $resDuplicate['status'] === 409);
$assert("1.11 Código de error de duplicado es MACHINE_HAS_ACTIVE_INCIDENT", 
    ($resDuplicate['body']['error']['code'] ?? '') === 'MACHINE_HAS_ACTIVE_INCIDENT'
);

// 1.5 Anexar comentarios/evidencias adicionales al ticket abierto (RF-02 / EARS 2.3)
$resComment = makeHttpRequest('POST', "{$baseUrl}/api/incidents/{$ticketCode}/comments", [
    'comment_text'   => 'Se aprecia que el ventilador exterior no gira y desprende ligero olor a recalentado.',
    'author_name'    => 'Laura Sanitaria',
    'author_role'    => 'LOCATION_MANAGER'
], $siteToken);
$assert("1.12 Anexar comentario a ticket existente responde HTTP 201 Created", $resComment['status'] === 201);
$assert("1.13 Comentario anexado correctamente al ticket", ($resComment['body']['data']['ticket_code'] ?? '') === $ticketCode);

// ─────────────────────────────────────────────────────────────────────────
// FASE 2: PERFIL 2 — COORDINADOR DEL SERVICIO (TRIAGE Y ASIGNACIÓN)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 2: Perfil Coordinador de Operaciones (Triaje y Asignación) ---{$colorReset}\n";

// 2.1 Login de coordinador (RF-04)
$resCoordLogin = makeHttpRequest('POST', "{$baseUrl}/api/auth/login", [
    'email'    => 'coordinacion@vendguard.internal',
    'password' => 'Password123!'
]);
$assert("2.1 Login de coordinador responde HTTP 200 OK", $resCoordLogin['status'] === 200);
$coordToken = $resCoordLogin['body']['data']['token'] ?? null;
$coordUser = $resCoordLogin['body']['data']['user'] ?? [];
$assert("2.2 Rol de usuario es COORDINATOR", ($coordUser['role'] ?? '') === 'COORDINATOR');

// 2.2 Consulta de la bandeja de incidencias activas (RF-05 / RF-11)
$resCoordIncidents = makeHttpRequest('GET', "{$baseUrl}/api/coordinator/incidents", [], $coordToken);
$assert("2.3 Bandeja de triaje responde HTTP 200 OK", $resCoordIncidents['status'] === 200);
$allCoordIncidents = $resCoordIncidents['body']['data'] ?? [];
$foundInTriage = null;
foreach ($allCoordIncidents as $inc) {
    if (($inc['ticket_code'] ?? '') === $ticketCode) {
        $foundInTriage = $inc;
        break;
    }
}
$assert("2.4 Avería {$ticketCode} aparece en bandeja de triaje del coordinador", $foundInTriage !== null);
$assert("2.5 Avería se encuentra en estado REGISTERED y sin técnico", 
    ($foundInTriage['status'] ?? '') === 'REGISTERED' && empty($foundInTriage['assigned_technician_id'])
);

// 2.3 Asignación de técnico de ruta a Jordi Técnico (ID 2) (RF-05 / EARS 5.1)
$resAssign = makeHttpRequest('PATCH', "{$baseUrl}/api/coordinator/incidents/{$incidentId}/assign", [
    'technician_id' => 2
], $coordToken);
$assert("2.6 Asignación técnica a Jordi responde HTTP 200 OK", $resAssign['status'] === 200);
$assert("2.7 Estado transiciona a ASIGNADA (ASSIGNED)", ($resAssign['body']['data']['status'] ?? '') === 'ASSIGNED');
$assert("2.8 assigned_technician_id fijado a 2", (int)($resAssign['body']['data']['assigned_technician_id'] ?? 0) === 2);

// ─────────────────────────────────────────────────────────────────────────
// FASE 3: PERFIL 3 — TÉCNICO DE CAMPO (INTERVENCIÓN Y RESOLUCIÓN MÓVIL)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 3: Perfil Técnico de Ruta Móvil (Intervención y Resolución) ---{$colorReset}\n";

// 3.1 Login del técnico de ruta Jordi (RF-04)
$resTechLogin = makeHttpRequest('POST', "{$baseUrl}/api/auth/login", [
    'email'    => 'jordi.ruta@vendguard.internal',
    'password' => 'Password123!'
]);
$assert("3.1 Login del técnico Jordi responde HTTP 200 OK", $resTechLogin['status'] === 200);
$techToken = $resTechLogin['body']['data']['token'] ?? null;
$techUser = $resTechLogin['body']['data']['user'] ?? [];
$assert("3.2 Rol de usuario es TECHNICIAN", ($techUser['role'] ?? '') === 'TECHNICIAN');

// 3.2 Consulta de la vista móvil "Mi Ruta" (RF-07)
$resRoute = makeHttpRequest('GET', "{$baseUrl}/api/technician/my-route", [], $techToken);
$assert("3.3 Consulta de Mi Ruta responde HTTP 200 OK", $resRoute['status'] === 200);
$routeItems = $resRoute['body']['data'] ?? [];
$foundInRoute = null;
foreach ($routeItems as $item) {
    if (($item['ticket_code'] ?? '') === $ticketCode) {
        $foundInRoute = $item;
        break;
    }
}
$assert("3.4 Ticket {$ticketCode} aparece en la ruta del técnico", $foundInRoute !== null);
$assert("3.5 Datos de máquina y teléfono de conserjería disponibles en ruta", 
    !empty($foundInRoute['machine']['code']) && !empty($foundInRoute['location']['contact_phone'])
);

// 3.3 Inicio de intervención in situ (RF-07 / EARS 7.1)
$resStart = makeHttpRequest('PATCH', "{$baseUrl}/api/technician/incidents/{$incidentId}/start", [], $techToken);
$assert("3.6 Iniciar intervención responde HTTP 200 OK", $resStart['status'] === 200);
$assert("3.7 Estado transiciona a EN_CURSO (IN_PROGRESS)", ($resStart['body']['data']['status'] ?? '') === 'IN_PROGRESS');
$assert("3.8 started_at queda registrado con marca de tiempo", !empty($resStart['body']['data']['started_at']));

// 3.4 Rechazo de cierre sin justificación mínima (RF-08 / EARS 8.2)
$resInvalidResolve = makeHttpRequest('POST', "{$baseUrl}/api/technician/incidents/{$incidentId}/resolve", [
    'resolution_diagnosis' => 'Fallo compresor', // 15 chars (< 20)
    'resolution_action'    => 'Arreglado ok'      // 12 chars (< 20)
], $techToken);
$assert("3.9 Intento de cierre con < 20 caracteres es rechazado con HTTP 422", $resInvalidResolve['status'] === 422);
$assert("3.10 Código de error es INVALID_RESOLUTION", ($resInvalidResolve['body']['error']['code'] ?? '') === 'INVALID_RESOLUTION');

// 3.5 Resolución justificada válida (RF-08 / EARS 8.1, 8.3 / Art. V.1)
$resValidResolve = makeHttpRequest('POST', "{$baseUrl}/api/technician/incidents/{$incidentId}/resolve", [
    'resolution_diagnosis' => 'Termostato digital y condensador obstruidos por pelusa y polvo térmico acumulado.', // 82 chars
    'resolution_action'    => 'Limpieza a presión del serpentín, sustitución de relé térmico y calibración a 3.8ºC.' // 83 chars
], $techToken);
$assert("3.11 Resolución documentada válida responde HTTP 200 OK", $resValidResolve['status'] === 200);
$assert("3.12 Estado transiciona a RESUELTA (RESOLVED)", ($resValidResolve['body']['data']['status'] ?? '') === 'RESOLVED');
$assert("3.13 resolved_at fijado y activa el reloj de 48h de garantía", !empty($resValidResolve['body']['data']['resolved_at']));

// ─────────────────────────────────────────────────────────────────────────
// FASE 4: CICLO DE GARANTÍA Y REAPERTURA (RESPONSABLE DE UBICACIÓN)
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorCyan}--- Paso 4: Ciclo de Garantía de 48 Horas y Reapertura ---{$colorReset}\n";

// 4.1 Responsable consulta catálogo de sede tras la resolución
$resMachinesPostResolve = makeHttpRequest('GET', "{$baseUrl}/api/locations/SEDE-BCN-01/machines", [], $siteToken);
$assert("4.1 Catálogo de sede actualizado responde HTTP 200 OK", $resMachinesPostResolve['status'] === 200);
$foundMachinePost = null;
foreach ($resMachinesPostResolve['body']['data'] ?? [] as $m) {
    if (($m['code'] ?? '') === 'VEND-0101') {
        $foundMachinePost = $m;
        break;
    }
}
$assert("4.2 Máquina VEND-0101 muestra ticket en estado RESOLVED", ($foundMachinePost['active_incident']['status'] ?? '') === 'RESOLVED');

// 4.2 Reapertura dentro de la ventana de garantía de 48h (RF-09 / EARS 9.1 / QA 5)
$resReopen = makeHttpRequest('POST', "{$baseUrl}/api/incidents/{$ticketCode}/reopen", [
    'reopen_reason' => 'El termómetro de la máquina vuelve a subir a 11 grados tras dos horas del cierre técnico.'
], $siteToken);
$assert("4.3 Petición de reapertura responde HTTP 200 OK", $resReopen['status'] === 200);
$reopenedData = $resReopen['body']['data'] ?? [];
$assert("4.4 Estado de la incidencia pasa a REABIERTA (REOPENED)", ($reopenedData['status'] ?? '') === 'REABIERTA');
$assert("4.5 Técnico previo queda formalmente desasignado (assigned_technician_id = null)", $reopenedData['assigned_technician_id'] === null);

// 4.3 Comprobación en base de datos de la trazabilidad constitucional (Art. III)
$dbFinalRow = $pdo->query("SELECT status, assigned_technician_id, resolved_at FROM incidents WHERE id = {$incidentId}")->fetch(PDO::FETCH_ASSOC);
$assert("4.6 BD: status es REOPENED", ($dbFinalRow['status'] ?? '') === 'REOPENED');
$assert("4.7 BD: assigned_technician_id es NULL", $dbFinalRow['assigned_technician_id'] === null);
$assert("4.8 BD: resolved_at es NULL (reloj de garantía reiniciado a 0)", $dbFinalRow['resolved_at'] === null);

// Comprobar historial inmutable de auditoría
$histEntries = $pdo->query("SELECT * FROM incident_history WHERE incident_id = {$incidentId} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$assert("4.9 Historial inmutable contiene la trazabilidad de todos los estados del ciclo", count($histEntries) >= 4);

// ─────────────────────────────────────────────────────────────────────────
// RESUMEN Y CONCLUSIÓN DE LA VERIFICACIÓN E2E
// ─────────────────────────────────────────────────────────────────────────
echo "\n{$colorBold}======================================================================{$colorReset}\n";
echo " Total Aserciones E2E Evaluadas : {$assertions}\n";
echo " Pasadas con Éxito               : " . ($assertions - $failures) . "\n";
echo " Fallos Detectados               : {$failures}\n";
echo "{$colorBold}======================================================================{$colorReset}\n";

if ($failures === 0) {
    echo "{$colorBold}{$colorGreen} RESULTADO: RECORRIDO E2E DE LOS 3 PERFILES COMPLETADO CON CERO FALLOS.{$colorReset}\n";
    echo "{$colorBold}{$colorGreen} CONDICIÓN T-40 CUMPLIDA CON ÉXITO ABSOLUTO.{$colorReset}\n";
    echo "{$colorBold}======================================================================{$colorReset}\n";
    exit(0);
} else {
    echo "{$colorBold}{$colorRed} RESULTADO: DETECTADOS FALLOS EN EL RECORRIDO E2E.{$colorReset}\n";
    echo "{$colorBold}======================================================================{$colorReset}\n";
    exit(1);
}
