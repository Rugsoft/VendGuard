<?php

declare(strict_types=1);

/**
 * LocationPortalControllerTest
 * 
 * Test de Integración para LocationPortalController (Tarea T-21).
 * Valida que:
 * - GET /api/locations/{site_code}/machines devuelve el catálogo JSON de máquinas de la sede.
 * - Detecta si cada máquina tiene avería activa o en periodo de garantía (ticket_code, status).
 * - Aplica segregación de sedes (HTTP 403 ante SITE_MISMATCH).
 * - Funciona tanto en despacho interno del Router como vía HTTP real contra 127.0.0.1:8000.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

echo "======================================================================\n";
echo " VendGuard: Test de Integración - LocationPortalControllerTest (T-21)\n";
echo " Casos 1-6: portal de sede. Caso 7: reintegros de conserjería (T-REF-11).\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();

// Asegurar semillas limpias
$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();

$router = AppRouter::create();
$locationRepo = new PdoLocationRepository($pdo);
$machineRepo = new PdoMachineRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$authService = new AuthService($locationRepo);

$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

$location = $locationRepo->findBySiteCode('SEDE-BCN-01');
$assert("1. Sede SEDE-BCN-01 recuperada de base de datos", $location !== null);

if ($location !== null) {
    $locId = $location->getId();

    // Limpieza operacional segura (orden derivado del grafo de FKs).
    TestDataCleaner::purge($pdo);

    // Generar token de sede para autenticación Bearer
    $siteToken = $authService->generateSiteToken($location);

    // =====================================================================
    // CASO 1: Consulta de catálogo inicial de máquinas sin averías activas
    // =====================================================================
    echo "\n--- Caso 1: Catálogo de máquinas sin averías activas ---\n";

    $req1 = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
        'Authorization' => "Bearer {$siteToken}"
    ]);
    $res1 = $router->dispatch($req1);

    $assert("1.1 Petición responde HTTP 200 OK", $res1->getStatusCode() === 200);
    $body1 = $res1->getDecodedBody();

    $assert("1.2 Envolvente contiene success => true", ($body1['success'] ?? false) === true);
    $machinesData = $body1['data'] ?? [];
    $assert("1.3 Se devuelven al menos 2 máquinas en SEDE-BCN-01", count($machinesData) >= 2);

    $firstMachine = $machinesData[0] ?? [];
    $assert(
        "1.4 Campos requeridos presentes en la máquina (id, code, model, machine_type, floor_wing)",
        isset($firstMachine['id'], $firstMachine['code'], $firstMachine['model'], $firstMachine['machine_type'], $firstMachine['floor_wing'])
    );
    $assert("1.5 Inicialmente la máquina no tiene avería activa (active_incident === null)", $firstMachine['active_incident'] === null);

    // =====================================================================
    // CASO 2: Detección de avería activa en el catálogo
    // =====================================================================
    echo "\n--- Caso 2: Detección de aviso de avería activo en una máquina ---\n";

    $targetMachineId = (int)$firstMachine['id'];

    // Insertar incidencia activa para la primera máquina
    $testIncident = new Incident(
        null,
        'INC-TEST-T2101',
        $targetMachineId,
        $locId,
        IncidentCategory::TEMPERATURE_COLD,
        'Temperatura de refrigeración a 12 grados en máquina de sándwiches',
        UrgencyLevel::CRITICAL,
        IncidentStatus::REGISTERED
    );
    $createdIncident = $incidentRepo->create($testIncident, null, 'Aviso de prueba T-21');

    $req2 = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
        'Authorization' => "Bearer {$siteToken}"
    ]);
    $res2 = $router->dispatch($req2);

    $assert("2.1 Consulta autenticada por Bearer site_token responde HTTP 200 OK", $res2->getStatusCode() === 200);
    $body2 = $res2->getDecodedBody();
    $machinesData2 = $body2['data'] ?? [];

    $reportedMachine = null;
    $unaffectedMachine = null;
    foreach ($machinesData2 as $m) {
        if ($m['id'] === $targetMachineId) {
            $reportedMachine = $m;
        } else {
            $unaffectedMachine = $m;
        }
    }

    $assert(
        "2.2 La máquina averiada reporta active_incident no nulo",
        $reportedMachine !== null && $reportedMachine['active_incident'] !== null
    );

    $activeInc = $reportedMachine['active_incident'] ?? [];
    $assert(
        "2.3 active_incident contiene ticket_code ('INC-TEST-T2101') y status ('REGISTERED')",
        ($activeInc['ticket_code'] ?? '') === 'INC-TEST-T2101' && ($activeInc['status'] ?? '') === 'REGISTERED'
    );

    $assert(
        "2.4 Las demás máquinas de la sede permanecen libres de aviso (active_incident === null)",
        $unaffectedMachine !== null && $unaffectedMachine['active_incident'] === null
    );

    // =====================================================================
    // CASO 3: Detección de máquina con aviso en periodo de garantía (< 48h)
    // =====================================================================
    echo "\n--- Caso 3: Detección de máquina en garantía (RESOLVED) ---\n";

    $pdo->prepare("
        UPDATE incidents 
        SET status = 'RESOLVED',
            resolved_at = NOW(),
            resolution_diagnosis = 'Cambio de compresor y termostato digital',
            resolution_action = 'Carga de gas R134a y prueba de temperatura a 4C completada'
        WHERE id = :id
    ")->execute([':id' => $createdIncident->getId()]);

    $req3 = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
        'Authorization' => "Bearer {$siteToken}"
    ]);
    $res3 = $router->dispatch($req3);
    $body3 = $res3->getDecodedBody();

    $machineInWarranty = null;
    foreach ($body3['data'] ?? [] as $m) {
        if ($m['id'] === $targetMachineId) {
            $machineInWarranty = $m;
            break;
        }
    }

    $assert("3.1 Máquina reporta estado en garantía", $machineInWarranty !== null && $machineInWarranty['active_incident'] !== null);
    $warrantyInfo = $machineInWarranty['active_incident'] ?? [];
    $assert("3.2 Estado de la avería es 'RESOLVED'", ($warrantyInfo['status'] ?? '') === 'RESOLVED');
    $assert("3.3 Flag is_in_warranty es true", ($warrantyInfo['is_in_warranty'] ?? false) === true);

    // =====================================================================
    // CASO 4: Cierre definitivo (CLOSED) inactiva el aviso en la máquina
    // =====================================================================
    echo "\n--- Caso 4: Cierre definitivo (CLOSED) libera la máquina en el portal ---\n";

    $pdo->prepare("UPDATE incidents SET status = 'CLOSED', closed_at = NOW() WHERE id = :id")->execute([':id' => $createdIncident->getId()]);

    $req4 = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
        'Authorization' => "Bearer {$siteToken}"
    ]);
    $res4 = $router->dispatch($req4);
    $body4 = $res4->getDecodedBody();

    $machineClosed = null;
    foreach ($body4['data'] ?? [] as $m) {
        if ($m['id'] === $targetMachineId) {
            $machineClosed = $m;
            break;
        }
    }

    $assert("4.1 Tras CLOSED, la máquina vuelve a tener active_incident === null", $machineClosed !== null && $machineClosed['active_incident'] === null);

    // =====================================================================
    // CASO 5: Segregación constitucional de sedes (HTTP 403 SITE_MISMATCH)
    // =====================================================================
    echo "\n--- Caso 5: Segregación constitucional de datos entre centros (Art. V) ---\n";

    // Petición identificada como SEDE-BCN-02 intentando consultar máquinas de SEDE-BCN-01
    // (sesión de sede legítima de OTRO centro, vía token firmado)
    $otherLocation = $locationRepo->findBySiteCode('SEDE-BCN-02');
    $otherSiteToken = $otherLocation !== null ? $authService->generateSiteToken($otherLocation) : '';
    $reqMismatch = new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
        'Authorization' => "Bearer {$otherSiteToken}"
    ]);
    $resMismatch = $router->dispatch($reqMismatch);

    $assert("5.1 Intento de cruce de sede responde HTTP 403 Forbidden", $resMismatch->getStatusCode() === 403);
    $bodyMismatch = $resMismatch->getDecodedBody();
    $assert("5.2 Código de error es 'SITE_MISMATCH'", ($bodyMismatch['error']['code'] ?? '') === 'SITE_MISMATCH');

    // =====================================================================
    // CASO 6: Petición HTTP en vivo contra el servidor local (127.0.0.1:8000)
    // =====================================================================
    echo "\n--- Caso 6: Petición HTTP en vivo (127.0.0.1:8000) ---\n";

    $liveServerUrl = 'http://127.0.0.1:8000';
    $socket = @fsockopen('127.0.0.1', 8000, $errno, $errstr, 1);
    if ($socket) {
        fclose($socket);

        $liveOptions = [
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$siteToken}\r\nAccept: application/json\r\n",
                'ignore_errors' => true,
            ],
        ];
        $liveRaw = file_get_contents("{$liveServerUrl}/api/locations/SEDE-BCN-01/machines", false, stream_context_create($liveOptions));
        $liveDecoded = json_decode((string)$liveRaw, true);

        $assert("6.1 Servidor local responde HTTP 200 en GET /api/locations/SEDE-BCN-01/machines", ($liveDecoded['success'] ?? false) === true);
        $assert("6.2 Servidor devuelve catálogo de máquinas en vivo", count($liveDecoded['data'] ?? []) >= 2);
    } else {
        echo "  [INFO] Servidor local no accesible en puerto 8000.\n";
    }

    // =====================================================================
    // CASO 7: Reintegros de conserjería (T-REF-11)
    // Listado anonimizado y entrega presencial con PIN de 4 dígitos.
    //
    // Las rutas de reintegro se registran en T-REF-13, así que aquí se invoca
    // el controlador directamente. A propósito se construye SIN argumentos:
    // es el cableado real de producción, que es donde un colaborador mal
    // inyectado se escondería de una suite que sólo use dobles.
    // =====================================================================
    echo "\n--- Caso 7: Reintegros de la sede (T-REF-11) ---\n";

    $refundController = new \VendGuard\Presentation\Controller\LocationRefundController();
    $refundRepoPdo = new \VendGuard\Infrastructure\Repository\PdoRefundRequestRepository($pdo);
    $refundMgmtPdo = new \VendGuard\Application\Service\RefundManagementService($refundRepoPdo);

    $machinesForRefund = $machineRepo->findActiveByLocationId($locId);
    $assert("7.0 Hay máquinas de la sede para la prueba", count($machinesForRefund) >= 1);
    $machineForRefund = $machinesForRefund[0];

    $deskCase = $refundMgmtPdo->createCase(new \VendGuard\Application\DTO\CreateRefundRequestDTO(
        incidentId: (int)$createdIncident->getId(),
        machineId: $machineForRefund->getId(),
        locationId: $locId,
        claimantName: 'Laura Sanitaria',
        claimantContact: '600111222',
        claimedAmount: 2.50,
        compensationMethod: \VendGuard\Core\Domain\Model\CompensationMethod::EN_MANO_SEDE,
        productAttempted: 'Café con leche carril 2'
    ));
    $deskCaseId = (int)$deskCase->getId();

    // El técnico deja el sobre en conserjería (RF-REF-05).
    $refundRepoPdo->transitionStatus(
        $deskCaseId,
        \VendGuard\Core\Domain\Model\RefundStatus::PENDING_INSPECTION,
        \VendGuard\Core\Domain\Model\RefundStatus::DEPOSITED_AT_RECEPTION
    );
    $deskPin = (string)$refundRepoPdo->findById($deskCaseId)?->getPickupPin();
    $assert("7.1 El expediente de prueba tiene PIN de 4 dígitos", preg_match('/^[0-9]{4}$/', $deskPin) === 1, 'pin: ' . $deskPin);

    // 7.2 Listado con la identidad de sede que inyecta el middleware ya validado
    $listReq = new Request('GET', '/api/location/refunds');
    $listReq->setAttribute('authenticated_location', $location);
    $listReq->setAttribute('site_code', 'SEDE-BCN-01');
    $listRes = $refundController->index($listReq);
    $listBody = json_decode($listRes->getBody(), true);
    $listRaw = (string)$listRes->getBody();

    $assert("7.2 El listado responde HTTP 200", $listRes->getStatusCode() === 200, "HTTP {$listRes->getStatusCode()} " . $listRaw);
    $assert("7.3 Incluye el expediente de la sede", (int)($listBody['data']['total'] ?? 0) >= 1);
    $assert(
        "7.4 Lo marca como listo para recoger",
        ($listBody['data']['ready_for_pickup_total'] ?? 0) >= 1,
        'listo: ' . ($listBody['data']['ready_for_pickup_total'] ?? 'AUSENTE')
    );
    $assert(
        "7.5 El nombre del reclamante va anonimizado",
        str_contains($listRaw, 'Laura S.') && !str_contains($listRaw, 'Laura Sanitaria'),
        'respuesta: ' . $listRaw
    );
    $assert("7.6 Art. V.4: el PIN de recogida NO aparece en el listado", !str_contains($listRaw, $deskPin), 'pin: ' . $deskPin);
    $assert("7.7 Art. V.4: ninguna clave `iban` ni `bizum_phone` en el listado", !str_contains($listRaw, '"iban"') && !str_contains($listRaw, 'bizum_phone'));
    $assert("7.8 Art. V.4: el token de seguimiento tampoco", !str_contains($listRaw, 'tracking_token'));

    // 7.9 PIN incorrecto: no se suelta nada
    $wrongReq = new Request(
        'POST',
        "/api/location/refunds/{$deskCaseId}/deliver",
        [],
        ['pickup_pin' => '0000']
    );
    // Al invocar el controlador sin pasar por el router, el parámetro de ruta y la
    // identidad de sede hay que inyectarlos a mano.
    $wrongReq->setRouteParams(['id' => (string)$deskCaseId]);
    $wrongReq->setAttribute('authenticated_location', $location);
    $wrongReq->setAttribute('site_code', 'SEDE-BCN-01');
    $wrongRes = $refundController->deliver($wrongReq);
    $wrongBody = json_decode($wrongRes->getBody(), true);
    $assert("7.9 Un PIN incorrecto responde HTTP 422", $wrongRes->getStatusCode() === 422, "HTTP {$wrongRes->getStatusCode()} " . json_encode($wrongBody));
    $assert(
        "7.10 Expone INVALID_PICKUP_PIN",
        ($wrongBody['error']['code'] ?? '') === 'INVALID_PICKUP_PIN',
        'error: ' . ($wrongBody['error']['code'] ?? 'AUSENTE')
    );
    $assert(
        "7.11 BD: el sobre sigue en el mostrador",
        $refundRepoPdo->findById($deskCaseId)?->getStatus() === \VendGuard\Core\Domain\Model\RefundStatus::DEPOSITED_AT_RECEPTION
    );

    // 7.12 PIN correcto: entrega registrada
    $rightReq = new Request(
        'POST',
        "/api/location/refunds/{$deskCaseId}/deliver",
        [],
        ['pickup_pin' => $deskPin]
    );
    $rightReq->setRouteParams(['id' => (string)$deskCaseId]);
    $rightReq->setAttribute('authenticated_location', $location);
    $rightReq->setAttribute('site_code', 'SEDE-BCN-01');
    $rightRes = $refundController->deliver($rightReq);
    $rightBody = json_decode($rightRes->getBody(), true);
    $assert("7.12 Con el PIN correcto responde HTTP 200", $rightRes->getStatusCode() === 200, "HTTP {$rightRes->getStatusCode()} " . json_encode($rightBody));
    $assert(
        "7.13 El expediente pasa a REFUNDED_IN_HAND",
        ($rightBody['data']['status'] ?? '') === 'REFUNDED_IN_HAND',
        'estado: ' . ($rightBody['data']['status'] ?? 'AUSENTE')
    );
    $assert(
        "7.14 BD: el estado persistido es REFUNDED_IN_HAND",
        $refundRepoPdo->findById($deskCaseId)?->getStatus() === \VendGuard\Core\Domain\Model\RefundStatus::REFUNDED_IN_HAND
    );
    $assert(
        "7.15 BD: audit_log registra la entrega en mano",
        (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'REFUND_REQUEST' AND entity_id = {$deskCaseId} AND action = 'REFUND_DELIVERED_IN_HAND'")->fetchColumn() >= 1
    );

    // 7.16 Sin sede no hay ni lectura
    $anonRes = $refundController->index(new Request('GET', '/api/location/refunds'));
    $anonBody = json_decode($anonRes->getBody(), true);
    $assert("7.16 Sin sede autenticada responde HTTP 401", $anonRes->getStatusCode() === 401, "HTTP {$anonRes->getStatusCode()}");
    $assert("7.17 El 401 expone UNAUTHORIZED", ($anonBody['error']['code'] ?? '') === 'UNAUTHORIZED');

    // =====================================================================
    // CASO 8 (T-PAUSE-29): estado higiénico-sanitario del parque de la sede
    // =====================================================================
    echo "\n--- Caso 8: Publicación del estado sanitario y distintivo de riesgo térmico ---\n";

    $settingsRepo8 = new \VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository($pdo);

    /**
     * Claves internas de la pausa por falta de acceso que el canal de sede SÍ conoce
     * (RF-05.1) pero que no pueden aparecer fuera de la avería activa. El barrido
     * existe para certificar que el estado sanitario nuevo no arrastró consigo ningún
     * campo interno colado por otra vía.
     *
     * @var list<string> $forbiddenTopLevelKeys
     */
    $forbiddenTopLevelKeys = ['pending_info_reason_text_internal', 'cancellation_reason', 'internal_notes'];

    $fetchPark = function () use ($router, $siteToken): array {
        $response = $router->dispatch(new Request('GET', '/api/locations/SEDE-BCN-01/machines', [], [], [
            'Authorization' => "Bearer {$siteToken}"
        ]));

        return [
            'status' => $response->getStatusCode(),
            'rows'   => $response->getDecodedBody()['data'] ?? [],
            'raw'    => $response->getBody(),
        ];
    };

    $park = $fetchPark();
    $assert("8.1 La consulta del parque responde HTTP 200 OK", $park['status'] === 200);

    $allowedSanitaryStatuses = ['OK', 'ATTENTION_REQUIRED', 'EXPIRED', 'QUARANTINE', 'SEASONAL_PAUSE'];
    $rowsWithoutSanitaryStatus = array_values(array_filter(
        $park['rows'],
        static fn(array $row): bool => !array_key_exists('sanitary_status', $row)
            || !in_array($row['sanitary_status'], $allowedSanitaryStatuses, true)
    ));
    $assert(
        "8.2 Toda máquina del parque publica un estado sanitario del catálogo (RF-03.5.1)",
        $park['rows'] !== [] && $rowsWithoutSanitaryStatus === [],
        'filas sin estado válido: ' . count($rowsWithoutSanitaryStatus) . ' de ' . count($park['rows'])
    );
    $assert(
        "8.3 El parque limpio no enciende ninguna cuarentena sanitaria",
        array_values(array_filter($park['rows'], static fn(array $row): bool => $row['sanitary_status'] === 'QUARANTINE')) === []
    );

    // Se pone la primera máquina del parque en cuarentena REAL y se vuelve a consultar
    // el portal por la ruta autenticada: la máquina debe publicarse marcada, y sólo ella.
    $quarantineTarget = $park['rows'][0];
    $settingsRepo8->updateSanitaryStatus((int)$quarantineTarget['id'], 'QUARANTINE');

    $parkAfter = $fetchPark();
    $quarantinedRow = null;
    $otherRows = [];
    foreach ($parkAfter['rows'] as $row) {
        if ((int)$row['id'] === (int)$quarantineTarget['id']) {
            $quarantinedRow = $row;
            continue;
        }
        $otherRows[] = $row;
    }

    $assert(
        "8.4 La máquina en cuarentena publica sanitary_status = QUARANTINE",
        ($quarantinedRow['sanitary_status'] ?? '') === 'QUARANTINE',
        'sanitary_status: ' . ($quarantinedRow['sanitary_status'] ?? 'AUSENTE')
    );
    $assert(
        "8.5 El resto del parque sigue sin marca de riesgo térmico",
        $otherRows !== [] && array_values(array_filter(
            $otherRows,
            static fn(array $row): bool => ($row['sanitary_status'] ?? '') !== 'OK'
        )) === []
    );
    $assert(
        "8.6 El estado sanitario convive con la avería activa sin desplazarla",
        ($quarantinedRow['active_incident']['ticket_code'] ?? null) === ($quarantineTarget['active_incident']['ticket_code'] ?? null)
    );

    $parkPayload = json_encode($parkAfter['rows'], JSON_UNESCAPED_UNICODE) ?: '';
    $leakedKeys = array_values(array_filter(
        $forbiddenTopLevelKeys,
        static fn(string $key): bool => str_contains($parkPayload, $key)
    ));
    $assert(
        "8.7 El parque de la sede no filtra ningún campo interno ajeno al contrato",
        $leakedKeys === [],
        'claves filtradas: ' . implode(', ', $leakedKeys)
    );

    // Restitución del estado sanitario real de la máquina reservada.
    $settingsRepo8->updateSanitaryStatus((int)$quarantineTarget['id'], 'OK');
    $restored = $fetchPark();
    $restoredRow = null;
    foreach ($restored['rows'] as $row) {
        if ((int)$row['id'] === (int)$quarantineTarget['id']) {
            $restoredRow = $row;
        }
    }
    $assert(
        "8.8 La máquina reservada vuelve a OK al cerrar el caso",
        ($restoredRow['sanitary_status'] ?? '') === 'OK',
        'sanitary_status: ' . ($restoredRow['sanitary_status'] ?? 'AUSENTE')
    );

    // Limpieza de datos de prueba
    TestDataCleaner::purgeIncident($pdo, (int)$createdIncident->getId());
}

// Resumen del test
echo "\n======================================================================\n";
echo " Total Aserciones Verificadas | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-21 CUMPLIDA CON ÉXITO.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
