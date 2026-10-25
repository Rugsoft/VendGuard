<?php

declare(strict_types=1);

/**
 * AbsenceShiftE2ETest · Batería E2E, Humo y Funcional
 * Módulo 21: Shift Handover & Absence Coverage
 *
 * Ejecuta pruebas integrales contra el servidor HTTP en vivo (o router interno)
 * certificando la arquitectura y reglas de negocio de principio a fin.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/TestDataCleaner.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

function httpCall(string $method, string $path, ?array $data = null, ?string $token = null): array {
    $url = 'http://127.0.0.1:8000' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$res, true)];
}

$total = 0;
$passed = 0;
$failed = 0;

$check = function (string $title, bool $condition, string $extra = '') use (&$total, &$passed, &$failed): void {
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$title}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$title}" . ($extra !== '' ? " ({$extra})" : '') . "\n";
    }
};

echo "======================================================================\n";
echo " VendGuard: Batería E2E, Humo y Funcional - Absence & Shift Handover\n";
echo " Objetivo: http://127.0.0.1:8000\n";
echo "======================================================================\n\n";

// --------------------------------------------------------------------------
// 1. SMOKE TESTS
// --------------------------------------------------------------------------
echo "--- 1. SMOKE TESTS (Disponibilidad del Servidor y Assets) ---\n";
$health = httpCall('GET', '/api/health');
$check('1.1 Endpoint de salud /api/health responde HTTP 200', $health['code'] === 200);
$check('1.2 Estado del servicio es operational', ($health['body']['data']['status'] ?? '') === 'operational');

$chRoot = curl_init('http://127.0.0.1:8000/');
curl_setopt($chRoot, CURLOPT_RETURNTRANSFER, true);
curl_exec($chRoot);
$codeRoot = curl_getinfo($chRoot, CURLINFO_HTTP_CODE);
curl_close($chRoot);
$check('1.3 SPA index.html responde HTTP 200', $codeRoot === 200);

$chAbs = curl_init('http://127.0.0.1:8000/assets/js/components/CoordinatorAbsencesTab.js');
curl_setopt($chAbs, CURLOPT_RETURNTRANSFER, true);
curl_exec($chAbs);
$codeAbs = curl_getinfo($chAbs, CURLINFO_HTTP_CODE);
curl_close($chAbs);
$check('1.4 Componente CoordinatorAbsencesTab.js responde HTTP 200', $codeAbs === 200);

$chShift = curl_init('http://127.0.0.1:8000/assets/js/views/TechnicianRouteView.js');
curl_setopt($chShift, CURLOPT_RETURNTRANSFER, true);
curl_exec($chShift);
$codeShift = curl_getinfo($chShift, CURLINFO_HTTP_CODE);
curl_close($chShift);
$check('1.5 Vista TechnicianRouteView.js responde HTTP 200', $codeShift === 200);

$badAuth = httpCall('POST', '/api/auth/login', ['email' => 'fake@vendguard.internal', 'password' => 'Wrong']);
$check('1.6 Autenticación con credenciales erróneas rechazada (HTTP 401)', $badAuth['code'] === 401);

$loginCoord = httpCall('POST', '/api/auth/login', ['email' => 'coordinacion@vendguard.internal', 'password' => 'Password123!']);
$check('1.7 Login de Coordinación exitoso (HTTP 200)', $loginCoord['code'] === 200);
$coordToken = $loginCoord['body']['data']['token'] ?? null;
$check('1.8 Token JWT de Coordinador generado', $coordToken !== null);

$loginTech = httpCall('POST', '/api/auth/login', ['email' => 'jordi.ruta@vendguard.internal', 'password' => 'Password123!']);
$check('1.9 Login de Técnico Jordi exitoso (HTTP 200)', $loginTech['code'] === 200);
$techToken = $loginTech['body']['data']['token'] ?? null;
$check('1.10 Token JWT de Técnico generado', $techToken !== null);

$handoverTargets = httpCall('GET', '/api/technician/handover-targets', null, $techToken);
$targetsData = $handoverTargets['body']['data'] ?? [];
$check(
    '1.11 El técnico obtiene compañeros de relevo desde su propio endpoint',
    $handoverTargets['code'] === 200
        && count($targetsData) >= 1
        && !array_key_exists('email', $targetsData[0] ?? [])
);

echo "\n";

// --------------------------------------------------------------------------
// 2. GESTIÓN FUNCIONAL DE AUSENCIAS (COORDINACIÓN)
// --------------------------------------------------------------------------
echo "--- 2. FUNCIONAL: GESTIÓN DE AUSENCIAS Y SELECTOR DE HORAS ---\n";
$techList = httpCall('GET', '/api/coordinator/users?role=TECHNICIAN&status=active', null, $coordToken);
$check('2.1 Listado de personal técnico activo responde HTTP 200', $techList['code'] === 200);
$check('2.2 Retorna los 5 técnicos de plantilla disponibles', count($techList['body']['data'] ?? []) >= 5);

// El arnés no asume IDs fijos: la batería limpia y resiembra la base, así que
// resuelve la plantilla por correo y usa tres técnicos distintos según el caso.
$jordiId = (int)$pdo->query("SELECT id FROM users WHERE email = 'jordi.ruta@vendguard.internal'")->fetchColumn();
$otherTechIds = array_values(array_map('intval', array_column(array_filter(
    $techList['body']['data'] ?? [],
    static fn(array $row): bool => (int)($row['id'] ?? 0) !== $jordiId
), 'id')));
if ($jordiId <= 0 || count($otherTechIds) < 2) {
    fwrite(STDERR, "No se pudo resolver la plantilla técnica sembrada para la batería E2E.\n");
    exit(1);
}
$absenceTechId = $otherTechIds[0];
$partialTechId = $otherTechIds[1];
$multiDayTechId = $otherTechIds[2] ?? $otherTechIds[0];

// 2.3 Alta jornada completa
$fullDay = httpCall('POST', "/api/coordinator/technicians/{$absenceTechId}/absences", [
    'reason' => 'VACATION',
    'starts_at' => '2026-11-20',
    'ends_at' => '2026-11-22',
    'notes' => 'Vacaciones planificadas E2E'
], $coordToken);
$check('2.3 Alta ausencia jornada completa responde HTTP 201', $fullDay['code'] === 201);
$fullDayId = $fullDay['body']['data']['absence']['id'] ?? null;
$check('2.4 ID de ausencia autogenerado', $fullDayId !== null);
$check('2.5 Expansión automática a 00:00:00 y 23:59:59 en hora de Madrid (R5/A1)',
    ($fullDay['body']['data']['absence']['starts_at'] ?? '') === '2026-11-20T00:00:00+01:00'
    && ($fullDay['body']['data']['absence']['ends_at'] ?? '') === '2026-11-22T23:59:59+01:00'
    && ($fullDay['body']['data']['absence']['is_full_day'] ?? false) === true
);

// 2.6 Alta por horas (permiso médico)
$partial = httpCall('POST', "/api/coordinator/technicians/{$partialTechId}/absences", [
    'reason' => 'SICK_LEAVE',
    'starts_at' => '2026-11-25 09:30:00',
    'ends_at' => '2026-11-25 13:00:00',
    'notes' => 'Permiso médico especialista mañana'
], $coordToken);
$check('2.6 Alta ausencia con franja horaria responde HTTP 201', $partial['code'] === 201);
$partialId = $partial['body']['data']['absence']['id'] ?? null;

// 2.7 Coexistencia el mismo día sin solape
$sameDayTarde = httpCall('POST', "/api/coordinator/technicians/{$partialTechId}/absences", [
    'reason' => 'TRAINING',
    'starts_at' => '2026-11-25 15:30:00',
    'ends_at' => '2026-11-25 18:00:00',
    'notes' => 'Formación de tarde no solapada'
], $coordToken);
$check('2.7 Segunda ausencia el mismo día en horario de tarde responde HTTP 201', $sameDayTarde['code'] === 201);
$tardeId = $sameDayTarde['body']['data']['absence']['id'] ?? null;

// 2.8 Detección estricta de solape en horas
$overlap = httpCall('POST', "/api/coordinator/technicians/{$partialTechId}/absences", [
    'reason' => 'PERSONAL_LEAVE',
    'starts_at' => '2026-11-25 12:00:00',
    'ends_at' => '2026-11-25 14:00:00',
    'notes' => 'Intento solapado'
], $coordToken);
$check('2.8 Detección de solapamiento en franja horaria devuelve HTTP 409', $overlap['code'] === 409);
$check('2.9 Error code es ABSENCE_OVERLAP', ($overlap['body']['error']['code'] ?? '') === 'ABSENCE_OVERLAP');

// 2.10 Validación fecha/hora invertida
$inverted = httpCall('POST', "/api/coordinator/technicians/{$partialTechId}/absences", [
    'reason' => 'SICK_LEAVE',
    'starts_at' => '2026-11-25 14:00:00',
    'ends_at' => '2026-11-25 10:00:00'
], $coordToken);
$check('2.10 Rango de horas invertido devuelve HTTP 422 INVALID_DATE_RANGE', $inverted['code'] === 422);

// 2.11-2.12: una franja de madrugada del día siguiente no puede solapar con la
// jornada completa anterior, porque el fin local es 23:59:59 y no UTC.
$fullDaySingle = httpCall('POST', "/api/coordinator/technicians/{$multiDayTechId}/absences", [
    'reason' => 'VACATION',
    'starts_at' => '2026-12-01',
    'ends_at' => '2026-12-01'
], $coordToken);
$fullDaySingleId = $fullDaySingle['body']['data']['absence']['id'] ?? null;
$check(
    '2.11 Jornada completa de un solo día queda anclada en Madrid',
    $fullDaySingle['code'] === 201
        && ($fullDaySingle['body']['data']['absence']['starts_at'] ?? '') === '2026-12-01T00:00:00+01:00'
        && ($fullDaySingle['body']['data']['absence']['ends_at'] ?? '') === '2026-12-01T23:59:59+01:00'
        && ($fullDaySingle['body']['data']['absence']['is_full_day'] ?? false) === true
);

$earlyNextDay = httpCall('POST', "/api/coordinator/technicians/{$multiDayTechId}/absences", [
    'reason' => 'SICK_LEAVE',
    'starts_at' => '2026-12-02 00:30:00',
    'ends_at' => '2026-12-02 01:30:00'
], $coordToken);
$earlyNextDayId = $earlyNextDay['body']['data']['absence']['id'] ?? null;
$check(
    '2.12 Franja de madrugada del día siguiente no solapa con la jornada completa',
    $earlyNextDay['code'] === 201
);

if ($fullDaySingleId) httpCall('DELETE', '/api/coordinator/absences/' . $fullDaySingleId, ['cancellation_reason' => 'Limpieza suite'], $coordToken);
if ($earlyNextDayId) httpCall('DELETE', '/api/coordinator/absences/' . $earlyNextDayId, ['cancellation_reason' => 'Limpieza suite'], $coordToken);

echo "\n";

// --------------------------------------------------------------------------
// 3. FUNCIONAL: CICLO DE TURNO MÓVIL (TÉCNICO)
// --------------------------------------------------------------------------
echo "--- 3. FUNCIONAL: CICLO DE TURNO MÓVIL DEL TÉCNICO ---\n";
// Reset previo de turno si hubiera quedado abierto
httpCall('POST', "/api/coordinator/technicians/{$jordiId}/shifts/force-close", ['reason' => 'Limpieza de turno para test'], $coordToken);

$sInit = httpCall('GET', '/api/technician/shift/status', null, $techToken);
$check('3.1 Estado inicial del turno es OFF_SHIFT', ($sInit['body']['data']['status'] ?? '') === 'OFF_SHIFT');

// Crear avería asignada a Jordi para verificar bloqueo de inicio sin turno
$stmtPre = $pdo->prepare("INSERT INTO incidents (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
    VALUES ('INC-PRE-SHIFT-TEST', 1, 1, 'PAYMENT_SYSTEM', 'Prueba de bloqueo sin turno', 'MEDIUM', 'ASSIGNED', {$jordiId}, NOW())
    RETURNING id");
$stmtPre->execute();
$incPreId = (int)$stmtPre->fetchColumn();

// Intento de iniciar intervención estando en OFF_SHIFT
$failStartOff = httpCall('PATCH', "/api/technician/incidents/{$incPreId}/start", null, $techToken);
$check('3.1b Iniciar intervención en OFF_SHIFT bloqueado con HTTP 422', $failStartOff['code'] === 422);
$check('3.1c Código de error es SHIFT_NOT_ACTIVE', ($failStartOff['body']['error']['code'] ?? '') === 'SHIFT_NOT_ACTIVE');

$sStart = httpCall('POST', '/api/technician/shift/start', null, $techToken);
$check('3.2 Iniciar turno responde HTTP 200', $sStart['code'] === 200);
$check('3.3 Estado pasa a ON_SHIFT', ($sStart['body']['data']['status'] ?? '') === 'ON_SHIFT');
$check('3.4 Técnico disponible (is_available = true)', ($sStart['body']['data']['is_available'] ?? false) === true);

$sPause = httpCall('POST', '/api/technician/shift/pause-meal', null, $techToken);
$check('3.5 Pausa comida responde HTTP 200', $sPause['code'] === 200);
$check('3.6 Estado pasa a MEAL_BREAK', ($sPause['body']['data']['status'] ?? '') === 'MEAL_BREAK');

// Intento de iniciar intervención en MEAL_BREAK
$failStartMeal = httpCall('PATCH', "/api/technician/incidents/{$incPreId}/start", null, $techToken);
$check('3.6b Iniciar intervención en MEAL_BREAK bloqueado con HTTP 422', $failStartMeal['code'] === 422);
$check('3.6c Código de error es SHIFT_MEAL_BREAK', ($failStartMeal['body']['error']['code'] ?? '') === 'SHIFT_MEAL_BREAK');

$sResume = httpCall('POST', '/api/technician/shift/resume', null, $techToken);
$check('3.7 Reanudar turno responde HTTP 200', $sResume['code'] === 200);
$check('3.8 Estado vuelve a ON_SHIFT', ($sResume['body']['data']['status'] ?? '') === 'ON_SHIFT');

// Ahora sí, en ON_SHIFT, iniciar intervención debe tener éxito
$okStartOn = httpCall('PATCH', "/api/technician/incidents/{$incPreId}/start", null, $techToken);
$check('3.9 Iniciar intervención en ON_SHIFT responde HTTP 200', $okStartOn['code'] === 200);
$check('3.10 Incidencia pasa a IN_PROGRESS', ($okStartOn['body']['data']['status'] ?? '') === 'IN_PROGRESS');

// Pausar y restablecer para no dejar avería en curso bloqueante
$pdo->exec("UPDATE incidents SET status = 'RESOLVED', resolution_diagnosis = 'Diagnóstico válido de más de veinte caracteres', resolution_action = 'Acción técnica correctiva de más de veinte caracteres' WHERE id = {$incPreId}");

echo "\n";

// --------------------------------------------------------------------------
// 4. E2E: RELEVO ATÓMICO, BLOQUEO IN_PROGRESS Y NOTA INTERNA (ART. V.4)
// --------------------------------------------------------------------------
echo "--- 4. E2E: RELEVO ATÓMICO, BLOQUEO IN_PROGRESS Y BLINDAJE ART. V.4 ---\n";

// El receptor debe estar en turno: R3 rechaza relevos a compañeros no disponibles.
$handoverTargetId = $otherTechIds[2] ?? $otherTechIds[0];
$handoverTargetEmail = null;
foreach ($techList['body']['data'] ?? [] as $row) {
    if ((int)($row['id'] ?? 0) === $handoverTargetId) {
        $handoverTargetEmail = (string)($row['email'] ?? '');
        break;
    }
}
if ($handoverTargetEmail === '') {
    fwrite(STDERR, "No se pudo resolver el correo del técnico receptor del relevo.\n");
    exit(1);
}
$loginTarget = httpCall('POST', '/api/auth/login', ['email' => $handoverTargetEmail, 'password' => 'Password123!']);
$targetToken = $loginTarget['body']['data']['token'] ?? null;
$targetShift = $targetToken === null
    ? ['code' => 0, 'body' => []]
    : httpCall('POST', '/api/technician/shift/start', null, $targetToken);
$check(
    '4.0 El técnico receptor está en turno antes del relevo',
    $targetShift['code'] === 200
        && ($targetShift['body']['data']['status'] ?? '') === 'ON_SHIFT'
);

// Creamos avería asignada a Jordi en IN_PROGRESS (usando máquina 2 para no chocar con uq_machine_active_ticket)
$stmt = $pdo->prepare("INSERT INTO incidents (ticket_code, location_id, machine_id, category, description, urgency, status, assigned_technician_id, assigned_at)
    VALUES ('INC-E2E-HANDOVER', 1, 2, 'PAYMENT_SYSTEM', 'Avería activa para prueba de relevo', 'HIGH', 'IN_PROGRESS', {$jordiId}, NOW())
    RETURNING id");
$stmt->execute();
$incId = (int)$stmt->fetchColumn();

// Intento de cerrar turno con ticket en IN_PROGRESS (Bloqueo fail-fast)
$failHandover = httpCall('POST', '/api/technician/shift/close-handover', [
    'target_technician_id' => $handoverTargetId,
    'incident_ids' => [$incId],
    'handover_notes' => 'Traspaso de avería no permitida en curso',
    'close_reason' => 'Fin de turno'
], $techToken);
$check('4.1 Bloqueo fail-fast: HTTP 422 ante avería en curso (IN_PROGRESS)', $failHandover['code'] === 422);
$check('4.2 Error code es HAS_IN_PROGRESS_INCIDENTS', ($failHandover['body']['error']['code'] ?? '') === 'HAS_IN_PROGRESS_INCIDENTS');

// Pasamos ticket a ASSIGNED para permitir traspaso limpio
$pdo->exec("UPDATE incidents SET status = 'ASSIGNED' WHERE id = {$incId}");

// Relevo atómico con traspaso al compañero reservado para el relevo
$okHandover = httpCall('POST', '/api/technician/shift/close-handover', [
    'target_technician_id' => $handoverTargetId,
    'incident_ids' => [$incId],
    'handover_notes' => 'Te traspaso este monedero para revisión prioritaria turno matutino',
    'close_reason' => 'Fin de turno regular'
], $techToken);
$check('4.3 Relevo atómico de fin de turno responde HTTP 200', $okHandover['code'] === 200);
$check('4.4 Estado del turno de Jordi pasa a OFF_SHIFT', ($okHandover['body']['data']['shift']['status'] ?? '') === 'OFF_SHIFT');
$check('4.5 Resumen confirma 1 avería traspasada', count($okHandover['body']['data']['transferred_incidents'] ?? []) === 1);

// Verificación de reasignación en base de datos
$rowInc = $pdo->query("SELECT assigned_technician_id, status FROM incidents WHERE id = {$incId}")->fetch(PDO::FETCH_ASSOC);
$check('4.6 Avería en BD ahora asignada al técnico receptor', (int)$rowInc['assigned_technician_id'] === $handoverTargetId);

$outboxReceptor = $pdo->prepare("SELECT COUNT(*) FROM notification_outbox
    WHERE incident_id = :incident_id
      AND template = 'TELEGRAM_INCIDENT_ASSIGNMENT'
      AND priority = 'PRIORITY'
      AND recipient_user_id = :recipient
      AND deleted_at IS NULL");
$outboxReceptor->execute([':incident_id' => $incId, ':recipient' => $handoverTargetId]);
$check(
    '4.6b El técnico receptor recibe el aviso propio y prioritario de relevo',
    (int)$outboxReceptor->fetchColumn() >= 1
);

// Verificación de comentario interno de relevo (Art. V.4)
$comment = $pdo->query("SELECT comment_text, is_internal FROM incident_comments WHERE incident_id = {$incId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$check('4.7 Comentario de relevo registrado en el ticket', $comment !== false);
$check('4.8 Blindaje Art. V.4: is_internal es estrictamente TRUE', (bool)$comment['is_internal'] === true);

echo "\n";

// --------------------------------------------------------------------------
// 5. FUNCIONAL: CIERRE FORZADO Y ANULACIÓN LÓGICA (ART. III.1)
// --------------------------------------------------------------------------
echo "--- 5. FUNCIONAL: CIERRE FORZADO ADMINISTRATIVO Y ANULACIÓN LÓGICA ---\n";

// Jordi inicia nuevo turno (soporte turno partido R3)
httpCall('POST', '/api/technician/shift/start', null, $techToken);

// Coordinador fuerza cierre de turno (D9)
$forceClose = httpCall('POST', "/api/coordinator/technicians/{$jordiId}/shifts/force-close", [
    'reason' => 'Cierre forzado administrativo por fin de horario de ruta'
], $coordToken);
$check('5.1 Cierre forzado por Coordinación responde HTTP 200', $forceClose['code'] === 200);
$check('5.2 Estado del turno forzado queda en OFF_SHIFT', ($forceClose['body']['data']['status'] ?? '') === 'OFF_SHIFT');

// Anulación lógica de ausencia con motivo corto (< 5 chars)
$cancelShort = httpCall('DELETE', '/api/coordinator/absences/' . $fullDayId, ['cancellation_reason' => 'abc'], $coordToken);
$check('5.3 Anulación con motivo < 5 caracteres rechazada con HTTP 422', $cancelShort['code'] === 422);

// Anulación lógica con motivo válido
$cancelOk = httpCall('DELETE', '/api/coordinator/absences/' . $fullDayId, [
    'cancellation_reason' => 'Anulación voluntaria por necesidades del servicio'
], $coordToken);
$check('5.4 Anulación con motivo válido responde HTTP 200', $cancelOk['code'] === 200);
$check('5.5 Respuesta confirma cancelled = true', ($cancelOk['body']['data']['cancelled'] ?? false) === true);

// Limpiar ausencias creadas en test
if ($partialId) httpCall('DELETE', '/api/coordinator/absences/' . $partialId, ['cancellation_reason' => 'Limpieza suite'], $coordToken);
if ($tardeId) httpCall('DELETE', '/api/coordinator/absences/' . $tardeId, ['cancellation_reason' => 'Limpieza suite'], $coordToken);

// Restaurar BD limpia
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();
echo "  [INFO] Base de datos restaurada y limpia.\n\n";

echo "======================================================================\n";
echo " Total Aserciones: {$total} | Exitosas: {$passed} | Fallidas: {$failed}\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
