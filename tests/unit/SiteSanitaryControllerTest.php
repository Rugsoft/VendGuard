<?php

declare(strict_types=1);

/**
 * SiteSanitaryControllerTest
 * 
 * Batería de pruebas unitarias y de enrutamiento para SiteSanitaryController (T-PREV-15).
 * Verifica:
 * 1. Registro de endpoints en AppRouter bajo protección de SiteAuthMiddleware.
 * 2. Bloqueo 401 Unauthorized a peticiones anónimas sin token de sede válido.
 * 3. Consulta de estado sanitario de sede (/api/site/sanitary-status) con semáforos de máquinas.
 * 4. Consulta de Certificado Sanitario Oficial individual (/api/site/certificates/machine/{code}):
 *    - 404 ante máquina inexistente.
 *    - 403 ante máquina perteneciente a otra sede.
 *    - 400 CANNOT_GENERATE_CERTIFICATE_NOT_CONFORM ante máquina en cuarentena o vencida (EARS 7.4).
 *    - 200 OK en JSON con operator_code (Art. V.4) sin datos privados.
 *    - 200 OK en vista HTML A4 imprimible (@media print, RNF-03) si se solicita Accept: text/html.
 * 5. Consulta de Certificado Global Consolidado de Sede (/api/site/certificates/global):
 *    - Dictamen CONDICIONADO ante máquinas en cuarentena o vencidas (EARS 7.2).
 *    - Respuesta JSON y vista HTML A4 para impresión formal.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\SanitaryCertificateService;
use VendGuard\Core\Domain\Exception\CannotIssueNonConformCertificateException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Model\SanitaryCertificate;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitaryCertificateRepositoryInterface;
use VendGuard\Presentation\Controller\SiteSanitaryController;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;
use VendGuard\Presentation\Routing\AppRouter;

// =============================================================================
// Infraestructura de Aserciones
// =============================================================================

$assertionsCount = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertionsCount;
    $assertionsCount++;
    if (!$condition) {
        echo "  [FAIL] {$message}\n";
        debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

function assertEquals(mixed $expected, mixed $actual, string $message): void
{
    $condition = (is_numeric($expected) && is_numeric($actual))
        ? ((float)$expected === (float)$actual)
        : ($expected === $actual);
    assertTrue($condition, "{$message} (Esperado: " . json_encode($expected) . ", Obtenido: " . json_encode($actual) . ")");
}

// =============================================================================
// Dobles de Prueba en Memoria
// =============================================================================

class MockLocationRepoForSite implements LocationRepositoryInterface
{
    /** @var array<int, Location> */
    public array $locations = [];

    public function findBySiteCode(string $siteCode): ?Location
    {
        foreach ($this->locations as $loc) {
            if ($loc->getSiteCode() === trim($siteCode)) {
                return $loc;
            }
        }
        return null;
    }

    public function findById(int $id): ?Location
    {
        return $this->locations[$id] ?? null;
    }

    public function findAllActive(): array { return array_values($this->locations); }
    public function findAll(string $status = 'all', ?string $search = null): array { return array_values($this->locations); }
    public function create(array $data): Location { throw new DomainException('Not implemented'); }
    public function update(int $id, array $data): bool { return true; }
    public function softDelete(int $id): bool { return true; }
    public function restore(int $id): bool { return true; }
    public function updateContactPhone(int $id, string $contactPhone): bool { return true; }
    public function countActiveMachines(int $locationId): int { return 0; }
}

class MockMachineRepoForSite implements MachineRepositoryInterface
{
    /** @var array<int, Machine> */
    public array $machines = [];

    public function findActiveByLocationId(int $locationId): array
    {
        $res = [];
        foreach ($this->machines as $m) {
            if ($m->getLocationId() === $locationId) {
                $res[] = $m;
            }
        }
        return $res;
    }

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machines[$id] ?? null;
    }

    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        foreach ($this->machines as $m) {
            if ($m->getCode() === strtoupper(trim($code))) {
                return $m;
            }
        }
        return null;
    }

    public function create(array $data): Machine { throw new DomainException('Not implemented'); }
    public function update(int $id, array $data): bool { return true; }
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { return true; }
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { return true; }
    public function findAll(array $filters = []): array { return array_values($this->machines); }
    public function hasActiveTicketOrWarranty(int $machineId): bool { return false; }
    public function getActiveTicketOrWarranty(int $machineId): ?array { return null; }
    public function softDelete(int $id): bool { return true; }
}

class MockPreventiveSettingsRepoForSite implements PreventiveSettingsRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $machines = [];

    public function findAll(): array { return []; }
    public function findByMachineType(string $machineType): ?PreventiveSetting { return null; }
    public function updateTypeSettings(string $machineType, int $defaultFrequencyDays, int $maxAllowedDays, int $advanceWarningDays = 5): bool { return true; }
    public function updateMachineConfig(int $machineId, ?int $sanitaryFrequencyDays, ?string $nextSanitaryInspectionDue = null): bool { return true; }
    public function setSeasonalPause(int $machineId, string $reason, ?string $pauseUntil = null): bool { return true; }
    public function resumeSeasonalPause(int $machineId): bool { return true; }

    public function getMachineSettings(int $machineId): ?array
    {
        return $this->machines[$machineId] ?? [
            'id' => $machineId,
            'code' => "VEND-BCN-{$machineId}",
            'machine_type' => 'PERISHABLE_FOOD',
            'sanitary_status' => 'OK',
            'sanitary_frequency_days' => 15,
            'default_frequency_days' => 15,
            'is_seasonal_pause' => 0,
        ];
    }

    public function updateSanitaryStatus(int $machineId, string $status): bool
    {
        if (isset($this->machines[$machineId])) {
            $this->machines[$machineId]['sanitary_status'] = $status;
        }
        return true;
    }
}

class MockPreventiveOrderRepoForSite implements PreventiveOrderRepositoryInterface
{
    public function create(array $data): PreventiveOrder { throw new DomainException('Not implemented'); }
    public function findById(int $id, bool $allowCancelled = true): ?PreventiveOrder { return null; }
    public function findByCode(string $orderCode, bool $allowCancelled = true): ?PreventiveOrder { return null; }
    public function hasActiveOrPendingOrder(int $machineId): bool { return false; }
    public function assignTechnician(int $orderId, int $technicianId, string $scheduledDate): bool { return true; }
    public function claimOrderOpportunistically(int $orderId, int $technicianId): bool { return true; }
    public function startInspection(int $orderId, int $technicianId): bool { return true; }
    public function completeOrder(int $orderId, string $result, ?float $temperatureMeasured, bool $isQuarantineTriggered, ?int $linkedIncidentId = null, ?string $notes = null): bool { return true; }
    public function linkIncident(int $orderId, int $incidentId): bool { return true; }
    public function softCancel(int $orderId, string $reason): bool { return true; }
    public function findForCoordinatorList(array $filters = []): array { return []; }
    public function countForCoordinatorList(array $filters = []): int { return 0; }
    public function findForTechnicianRoute(int $technicianId, ?int $locationId = null): array { return []; }
    public function getDashboardSummary(): array { return []; }
    public function expireOverdueOrders(): int { return 0; }
}

class MockSanitaryCertificateRepoForSite implements SanitaryCertificateRepositoryInterface
{
    /** @var array<int, SanitaryCertificate> */
    public array $certificates = [];
    public ?array $globalSiteReport = null;

    public function createCertificate(array $data): SanitaryCertificate { throw new DomainException('Not implemented'); }
    public function findById(int $id): ?SanitaryCertificate { return $this->certificates[$id] ?? null; }
    public function findByCertificateCode(string $code): ?SanitaryCertificate
    {
        foreach ($this->certificates as $c) {
            if ($c->getCertificateCode() === $code) {
                return $c;
            }
        }
        return null;
    }

    public function findActiveByMachineCode(string $machineCode): ?SanitaryCertificate
    {
        foreach ($this->certificates as $c) {
            if (isset($c->getMachineData()['code']) && $c->getMachineData()['code'] === $machineCode && $c->getStatus() === 'VALID') {
                return $c;
            }
        }
        return null;
    }

    public function findActiveByMachineId(int $machineId): ?SanitaryCertificate
    {
        foreach ($this->certificates as $c) {
            if ($c->getMachineId() === $machineId && $c->getStatus() === 'VALID') {
                return $c;
            }
        }
        return null;
    }

    public function findLatestByMachineId(int $machineId): ?SanitaryCertificate { return null; }
    public function suspendByMachineId(int $machineId, string $reason): int { return 1; }
    public function revokeByMachineId(int $machineId, string $reason): int { return 1; }

    public function getGlobalSiteReport(int $locationId): array
    {
        return $this->globalSiteReport ?? [];
    }
}

class SpyAuditLoggerForSite extends AuditLogger
{
    public function __construct() {}
    public function logMachineEvent(int $machineId, string $action, array $user, ?array $before, array $after, ?array $meta = null): AuditEvent
    {
        return new AuditEvent(1, 'MACHINE', $machineId, $action, $user['id'] ?? null, $user['role'] ?? 'SITE', $user['name'] ?? 'Site', $before, $after, $meta);
    }
}

// =============================================================================
// INICIO DE LA BATERÍA DE PRUEBAS
// =============================================================================

echo "====================================================================================\n";
echo " VendGuard: Pruebas Unitarias de SiteSanitaryController (T-PREV-15)\n";
echo "====================================================================================\n\n";

$locationRepo = new MockLocationRepoForSite();
$machineRepo = new MockMachineRepoForSite();
$settingsRepo = new MockPreventiveSettingsRepoForSite();
$orderRepo = new MockPreventiveOrderRepoForSite();
$certRepo = new MockSanitaryCertificateRepoForSite();
$auditLogger = new SpyAuditLoggerForSite();

$certService = new SanitaryCertificateService(
    certificateRepo: $certRepo,
    orderRepo: $orderRepo,
    settingsRepo: $settingsRepo,
    machineRepo: $machineRepo,
    auditLogger: $auditLogger
);

$controller = new SiteSanitaryController(
    certificateService: $certService,
    locationRepo: $locationRepo,
    machineRepo: $machineRepo
);

// Registrar datos de prueba
$location1 = new Location(1, 'SEDE-BCN-01', 'Hospital del Mar - Edificio Central', 'Passeig Marítim 25, Barcelona', '932223344', 'Pabellón A');
$locationRepo->locations[1] = $location1;

$location2 = new Location(2, 'SEDE-BCN-02', 'Facultad de Medicina', 'Carrer Casanova 143, Barcelona', '934445566', 'Edificio Principal');
$locationRepo->locations[2] = $location2;

// Máquina 1: Hospital del Mar, Perishable, Conforme
$machine1 = new Machine(1, 1, 'VEND-BCN-101', 'Sanden Vendo G-Drink', MachineType::PERISHABLE_FOOD, 'Planta Baja - Urgencias');
$machineRepo->machines[1] = $machine1;
$settingsRepo->machines[1] = [
    'id' => 1,
    'code' => 'VEND-BCN-101',
    'machine_type' => 'PERISHABLE_FOOD',
    'sanitary_status' => 'OK',
    'sanitary_frequency_days' => 15,
    'default_frequency_days' => 15,
];

// Máquina 2: Hospital del Mar, Hot Drinks, Conforme
$machine2 = new Machine(2, 1, 'VEND-BCN-102', 'Necta Krea Touch', MachineType::HOT_DRINKS, 'Planta 1 - Sala Médica');
$machineRepo->machines[2] = $machine2;
$settingsRepo->machines[2] = [
    'id' => 2,
    'code' => 'VEND-BCN-102',
    'machine_type' => 'HOT_DRINKS',
    'sanitary_status' => 'OK',
    'sanitary_frequency_days' => 30,
    'default_frequency_days' => 30,
];

// Máquina 3: Hospital del Mar, en Cuarentena
$machine3 = new Machine(3, 1, 'VEND-BCN-103', 'Sanden Vendo G-Drink', MachineType::PERISHABLE_FOOD, 'Planta 2 - Consultas');
$machineRepo->machines[3] = $machine3;
$settingsRepo->machines[3] = [
    'id' => 3,
    'code' => 'VEND-BCN-103',
    'machine_type' => 'PERISHABLE_FOOD',
    'sanitary_status' => 'QUARANTINE',
    'sanitary_frequency_days' => 15,
    'default_frequency_days' => 15,
];

// Máquina 4: Perteneciente a Sede 2
$machine4 = new Machine(4, 2, 'VEND-BCN-201', 'Necta Krea Touch', MachineType::HOT_DRINKS, 'Hall Principal');
$machineRepo->machines[4] = $machine4;
$settingsRepo->machines[4] = [
    'id' => 4,
    'code' => 'VEND-BCN-201',
    'machine_type' => 'HOT_DRINKS',
    'sanitary_status' => 'OK',
    'sanitary_frequency_days' => 30,
    'default_frequency_days' => 30,
];

// Certificado válido para Máquina 2
$cert102 = new SanitaryCertificate(
    id: 142,
    certificateCode: 'CERT-2026-0142',
    preventiveOrderId: 89,
    machineId: 2,
    locationId: 1,
    technicianId: 3,
    technicianName: 'Carlos Técnico',
    technicianOperatorCode: 'OP-03',
    inspectionDate: '2026-09-20 09:00:00',
    validUntil: '2026-10-20',
    temperatureMeasured: null,
    result: 'CONFORME',
    status: 'VALID',
    machineData: [
        'code' => 'VEND-BCN-102',
        'model' => 'Necta Krea Touch',
        'serial_number' => 'SN-77889900',
        'machine_type' => 'HOT_DRINKS',
    ],
    locationData: [
        'name' => 'Hospital del Mar - Edificio Central',
        'address' => 'Passeig Marítim 25, Barcelona',
    ],
    inspectedItems: [
        ['item' => 'Caldera y Circuito Hidráulico', 'status' => 'CONFORME'],
        ['item' => 'Desinfección de Batidores y Boquillas', 'status' => 'CONFORME'],
        ['item' => 'Filtro de Purificación de Agua', 'status' => 'CONFORME (Vigente)'],
        ['item' => 'Bandeja y Boya de Residuos', 'status' => 'CONFORME'],
    ]
);
$certRepo->certificates[142] = $cert102;

// Informe global para Sede 1 (Hospital del Mar) con dictamen CONDICIONADO por la máquina 3
$certRepo->globalSiteReport = [
    'global_certificate_code' => 'SEDE-BCN-01-SAN-2026-09',
    'location' => [
        'site_code' => 'SEDE-BCN-01',
        'name' => 'Hospital del Mar - Edificio Central',
        'address' => 'Passeig Marítim 25, Barcelona',
    ],
    'issue_date' => '2026-09-27 17:15:00',
    'global_verdict' => 'CONDICIONADO',
    'verdict_explanation' => 'El centro dispone de 1 máquina en cuarentena sanitaria (VEND-BCN-103) sujeta a subsanación técnica obligatoria.',
    'has_quarantine_or_expired' => true,
    'machines_breakdown' => [
        [
            'code' => 'VEND-BCN-101',
            'model' => 'Sanden Vendo G-Drink',
            'machine_type' => 'PERISHABLE_FOOD',
            'floor_wing' => 'Planta Baja - Urgencias',
            'verdict' => 'CONFORME',
            'quarantine' => false,
            'semaphore' => 'GREEN',
            'last_inspection_date' => '2026-09-20 08:30:00',
            'last_temperature_celsius' => 3.2,
            'valid_until' => '2026-10-05',
            'certificate_status' => 'VALID',
            'detail' => 'Inspección conforme vigente',
        ],
        [
            'code' => 'VEND-BCN-102',
            'model' => 'Necta Krea Touch',
            'machine_type' => 'HOT_DRINKS',
            'floor_wing' => 'Planta 1 - Sala Médica',
            'verdict' => 'CONFORME',
            'quarantine' => false,
            'semaphore' => 'GREEN',
            'last_inspection_date' => '2026-09-20 09:00:00',
            'last_temperature_celsius' => null,
            'valid_until' => '2026-10-20',
            'certificate_status' => 'VALID',
            'detail' => 'Inspección conforme vigente',
        ],
        [
            'code' => 'VEND-BCN-103',
            'model' => 'Sanden Vendo G-Drink',
            'machine_type' => 'PERISHABLE_FOOD',
            'floor_wing' => 'Planta 2 - Consultas',
            'verdict' => 'NO_CONFORME',
            'quarantine' => true,
            'semaphore' => 'QUARANTINE',
            'last_inspection_date' => '2026-09-27 10:30:00',
            'last_temperature_celsius' => 6.8,
            'valid_until' => null,
            'certificate_status' => 'SUSPENDED',
            'detail' => 'En cuarentena por revisión térmica. No apta para consumo.',
        ],
    ],
];

// Helper para crear peticiones autenticadas de sede
function makeSiteRequest(string $method, string $path, array $queryParams = [], array $headers = [], int $locationId = 1): Request
{
    $req = new Request($method, $path, $queryParams, [], $headers);
    $req->setAttribute('location_id', $locationId);
    $req->setAttribute('site_code', 'SEDE-BCN-01');
    return $req;
}

// -----------------------------------------------------------------------------
echo "--- 1. Semáforo Higiénico y Estado Sanitario de Sede (RF-PREV-06, EARS 6.2) ---\n";
// -----------------------------------------------------------------------------
$reqStatus = makeSiteRequest('GET', '/api/site/sanitary-status');
$resStatus = $controller->getSanitaryStatus($reqStatus);

assertEquals(200, $resStatus->getStatusCode(), '1.1 Consulta de estado sanitario responde HTTP 200');
$statusBody = json_decode($resStatus->getBody(), true);
assertTrue($statusBody['success'], '1.2 Envelope success es true');
assertEquals('CONDICIONADO', $statusBody['data']['global_status'], '1.3 Dictamen global es CONDICIONADO ante máquina en cuarentena');
assertTrue($statusBody['data']['has_quarantine_or_expired'], '1.4 has_quarantine_or_expired es true');
assertEquals(3, count($statusBody['data']['machines']), '1.5 Desglosa las 3 máquinas instaladas');
assertEquals('QUARANTINE', $statusBody['data']['machines'][2]['semaphore'], '1.6 Semáforo de máquina 3 es QUARANTINE');

// -----------------------------------------------------------------------------
echo "\n--- 2. Certificado Sanitario Oficial Individual (RF-PREV-07, EARS 7.1, 7.4) ---\n";
// -----------------------------------------------------------------------------

// 2.1 Máquina inexistente -> 404
$reqCertNotFound = makeSiteRequest('GET', '/api/site/certificates/machine/VEND-999');
$reqCertNotFound->setRouteParams(['code' => 'VEND-999']);
$resCertNotFound = $controller->getMachineCertificate($reqCertNotFound);
assertEquals(404, $resCertNotFound->getStatusCode(), '2.1 Máquina inexistente devuelve 404');

// 2.2 Máquina de otra sede -> 403 Forbidden
$reqCertForbidden = makeSiteRequest('GET', '/api/site/certificates/machine/VEND-BCN-201');
$reqCertForbidden->setRouteParams(['code' => 'VEND-BCN-201']);
$resCertForbidden = $controller->getMachineCertificate($reqCertForbidden);
assertEquals(403, $resCertForbidden->getStatusCode(), '2.2 Intento de consultar máquina de otra sede devuelve 403');

// 2.3 Máquina en cuarentena -> 400 CANNOT_GENERATE_CERTIFICATE_NOT_CONFORM (EARS 7.4)
$reqCertQuarantine = makeSiteRequest('GET', '/api/site/certificates/machine/VEND-BCN-103');
$reqCertQuarantine->setRouteParams(['code' => 'VEND-BCN-103']);
$resCertQuarantine = $controller->getMachineCertificate($reqCertQuarantine);
assertEquals(400, $resCertQuarantine->getStatusCode(), '2.3 Bloqueo de certificado en máquina no apta devuelve 400');
$quarantineBody = json_decode($resCertQuarantine->getBody(), true);
assertEquals('CANNOT_GENERATE_CERTIFICATE_NOT_CONFORM', $quarantineBody['error']['code'], '2.4 Código CANNOT_GENERATE_CERTIFICATE_NOT_CONFORM');

// 2.4 Certificado Conforme en formato JSON (Art. V.4)
$reqCertJson = makeSiteRequest('GET', '/api/site/certificates/machine/VEND-BCN-102');
$reqCertJson->setRouteParams(['code' => 'VEND-BCN-102']);
$resCertJson = $controller->getMachineCertificate($reqCertJson);
assertEquals(200, $resCertJson->getStatusCode(), '2.5 Consulta de certificado conforme responde HTTP 200');
$certJsonBody = json_decode($resCertJson->getBody(), true);
assertEquals('CERT-2026-0142', $certJsonBody['data']['certificate_code'], '2.6 Código de certificado coincide');
assertEquals('OP-03', $certJsonBody['data']['inspector']['operator_code'], '2.7 Identificación de inspector con operator_code (Art. V.4)');
assertTrue(!isset($certJsonBody['data']['inspector']['dni']), '2.8 Oculta DNI personal del técnico (Art. V.4)');
assertTrue(!isset($certJsonBody['data']['inspector']['phone']), '2.9 Oculta teléfono personal del técnico (Art. V.4)');
assertEquals(4, count($certJsonBody['data']['inspected_items']), '2.10 Incluye ítems inspeccionados');

// 2.5 Certificado Conforme en formato HTML A4 imprimible (@media print, RNF-03)
$reqCertHtml = makeSiteRequest(
    'GET',
    '/api/site/certificates/machine/VEND-BCN-102',
    [],
    ['Accept' => 'text/html']
);
$reqCertHtml->setRouteParams(['code' => 'VEND-BCN-102']);
$resCertHtml = $controller->getMachineCertificate($reqCertHtml);
assertEquals(200, $resCertHtml->getStatusCode(), '2.11 Vista HTML A4 responde HTTP 200');
assertTrue(str_contains($resCertHtml->getHeader('Content-Type') ?? '', 'text/html'), '2.12 Cabecera Content-Type es text/html');
$htmlBody = $resCertHtml->getBody();
assertTrue(str_contains($htmlBody, '@media print'), '2.13 Contiene estilos optimizados para impresión (@media print, RNF-03)');
assertTrue(str_contains($htmlBody, 'CERT-2026-0142'), '2.14 Contiene código oficial del certificado');
assertTrue(str_contains($htmlBody, 'OP-03'), '2.15 Contiene Código de Operador Técnico');

// -----------------------------------------------------------------------------
echo "\n--- 3. Certificado Global Consolidado de Sede (RF-PREV-07, EARS 7.2) ---\n";
// -----------------------------------------------------------------------------

// 3.1 Respuesta JSON con dictamen CONDICIONADO
$reqGlobalJson = makeSiteRequest('GET', '/api/site/certificates/global');
$resGlobalJson = $controller->getGlobalCertificate($reqGlobalJson);
assertEquals(200, $resGlobalJson->getStatusCode(), '3.1 Certificado global responde HTTP 200');
$globalJsonBody = json_decode($resGlobalJson->getBody(), true);
assertEquals('CONDICIONADO', $globalJsonBody['data']['global_verdict'], '3.2 Dictamen consolidado es CONDICIONADO');
assertEquals('SEDE-BCN-01-SAN-2026-09', $globalJsonBody['data']['global_certificate_code'], '3.3 Código de certificado global');
assertEquals(3, count($globalJsonBody['data']['machines_breakdown']), '3.4 Desglose de máquinas del edificio');

// 3.2 Vista HTML A4 imprimible del Certificado Global
$reqGlobalHtml = makeSiteRequest('GET', '/api/site/certificates/global', [], ['Accept' => 'text/html']);
$resGlobalHtml = $controller->getGlobalCertificate($reqGlobalHtml);
assertEquals(200, $resGlobalHtml->getStatusCode(), '3.5 Vista HTML global responde HTTP 200');
assertTrue(str_contains($resGlobalHtml->getHeader('Content-Type') ?? '', 'text/html'), '3.6 Cabecera Content-Type es text/html');
$globalHtmlBody = $resGlobalHtml->getBody();
assertTrue(str_contains($globalHtmlBody, '@media print'), '3.7 Contiene estilos de impresión landscape (@media print)');
assertTrue(str_contains($globalHtmlBody, 'CONDICIONADO'), '3.8 Exhibe dictamen global CONDICIONADO');
assertTrue(str_contains($globalHtmlBody, 'VEND-BCN-103'), '3.9 Desglosa máquina en cuarentena');

// -----------------------------------------------------------------------------
echo "\n--- 4. Verificación de Seguridad en AppRouter (Protección SiteAuthMiddleware) ---\n";
// -----------------------------------------------------------------------------
$router = AppRouter::create();

$routesToVerify = [
    ['GET', '/api/site/sanitary-status'],
    ['GET', '/api/site/certificates/machine/VEND-BCN-101'],
    ['GET', '/api/site/certificates/global'],
];

$pdoLocRepo = new \VendGuard\Infrastructure\Repository\PdoLocationRepository();
$dbLoc = $pdoLocRepo->findBySiteCode('SEDE-BCN-01', true) ?? ($pdoLocRepo->findAllActive()[0] ?? null);
$validSiteToken = $dbLoc !== null
    ? (new \VendGuard\Application\Service\AuthService())->generateSiteToken($dbLoc)
    : '';

foreach ($routesToVerify as [$method, $path]) {
    // 4.1 Petición Anónima (sin token de sede) -> 401 Unauthorized
    $anonReq = new Request($method, $path);
    $anonRes = $router->dispatch($anonReq);
    assertEquals(401, $anonRes->getStatusCode(), "4.1 Endpoint {$method} {$path} protegido contra acceso anónimo (401)");

    // 4.1b La cabecera retirada X-Site-Code ya no abre ninguna ruta de sede (hallazgo S-4)
    $retiredHeaderReq = new Request($method, $path, [], [], ['X-Site-Code' => $dbLoc !== null ? $dbLoc->getSiteCode() : 'SEDE-BCN-01']);
    assertEquals(401, $router->dispatch($retiredHeaderReq)->getStatusCode(), "4.1b Endpoint {$method} {$path} ignora la cabecera retirada X-Site-Code (401)");

    // 4.2 Petición con token de sede firmado de una sede real -> supera el middleware
    $validSiteReq = new Request($method, $path, [], [], ['Authorization' => "Bearer {$validSiteToken}"]);
    $validSiteRes = $router->dispatch($validSiteReq);
    // El resultado no puede ser 401 (puede ser 200, o 400/404 según datos en BD, pero NO 401 Unauthorized)
    assertTrue($validSiteRes->getStatusCode() !== 401, "4.2 Endpoint {$method} {$path} reconoce autenticación de sede válida (status: {$validSiteRes->getStatusCode()})");
}

// =============================================================================
// RESUMEN FINAL
// =============================================================================
echo "\n====================================================================================\n";
echo " Total Aserciones: {$assertionsCount}\n";
echo " RESULTADO: 100% EN VERDE. Todos los endpoints y reglas de T-PREV-15 verificados.\n";
echo " Condición T-PREV-15 CUMPLIDA SATISFACTORIAMENTE.\n";
echo "====================================================================================\n";
