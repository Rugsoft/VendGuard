<?php

declare(strict_types=1);

namespace VendGuard\Tests\Unit;

require_once __DIR__ . '/../../src/autoload.php';

use DomainException;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\SanitaryCertificateService;
use VendGuard\Core\Domain\Exception\CannotIssueNonConformCertificateException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Model\SanitaryCertificate;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitaryCertificateRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\MachineType;

// =============================================================================
// Helper de Aserciones
// =============================================================================
$assertionsCount = 0;

function assertTrue(bool $condition, string $message): void
{
    global $assertionsCount;
    $assertionsCount++;
    if (!$condition) {
        echo "  [FAIL] {$message}\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

function assertEquals(mixed $expected, mixed $actual, string $message): void
{
    global $assertionsCount;
    $assertionsCount++;
    if ($expected !== $actual) {
        $expStr = var_export($expected, true);
        $actStr = var_export($actual, true);
        echo "  [FAIL] {$message} (Esperado: {$expStr}, Obtenido: {$actStr})\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

// =============================================================================
// Dobles de Prueba en Memoria
// =============================================================================

class InMemorySanitaryCertificateRepository implements SanitaryCertificateRepositoryInterface
{
    /** @var array<int, SanitaryCertificate> */
    public array $certificates = [];
    public int $nextId = 500;
    public ?MachineRepositoryInterface $machineRepo = null;

    public function __construct(?MachineRepositoryInterface $machineRepo = null)
    {
        $this->machineRepo = $machineRepo;
    }

    public function createCertificate(array $data): SanitaryCertificate
    {
        $id = $this->nextId++;
        $code = $data['certificate_code'] ?? sprintf('CERT-%s-%04d', date('Y'), $id);

        $machineCode = "VEND-MACH-{$data['machine_id']}";
        $machineModel = 'Sanden Vendo G-Drink';
        $machineType = 'PERISHABLE_FOOD';
        if ($this->machineRepo !== null) {
            $mach = $this->machineRepo->findById((int)$data['machine_id']);
            if ($mach !== null) {
                $machineCode = $mach->getCode();
                $machineModel = $mach->getModel();
                $machineType = $mach->getMachineType()->value;
            }
        }

        $cert = new SanitaryCertificate(
            id: $id,
            certificateCode: $code,
            preventiveOrderId: (int)$data['preventive_order_id'],
            machineId: (int)$data['machine_id'],
            locationId: (int)$data['location_id'],
            technicianId: (int)$data['technician_id'],
            technicianName: (string)$data['technician_name'],
            technicianOperatorCode: (string)$data['technician_operator_code'],
            inspectionDate: (string)$data['inspection_date'],
            validUntil: (string)$data['valid_until'],
            temperatureMeasured: isset($data['temperature_measured']) ? (float)$data['temperature_measured'] : null,
            result: (string)$data['result'],
            status: $data['status'] ?? SanitaryCertificate::STATUS_VALID,
            suspendedReason: null,
            suspendedAt: null,
            createdAt: date('Y-m-d H:i:s'),
            updatedAt: date('Y-m-d H:i:s'),
            deletedAt: null,
            machineData: [
                'code' => $machineCode,
                'model' => $machineModel,
                'serial_number' => 'SN-11223344',
                'machine_type' => $machineType,
            ],
            locationData: [
                'name' => 'Hospital Clínico Central',
                'address' => 'Gran Via 585, Barcelona',
            ],
            inspectedItems: [
                ['item' => 'Sonda Térmica Estabilizada', 'status' => 'CONFORME'],
                ['item' => 'Desinfección de Bandejas', 'status' => 'CONFORME'],
                ['item' => 'Control de Caducidades', 'status' => 'CONFORME'],
            ]
        );

        $this->certificates[$id] = $cert;
        return $cert;
    }

    public function findById(int $id): ?SanitaryCertificate
    {
        return $this->certificates[$id] ?? null;
    }

    public function findByCertificateCode(string $code): ?SanitaryCertificate
    {
        foreach ($this->certificates as $cert) {
            if ($cert->getCertificateCode() === trim($code)) {
                return $cert;
            }
        }
        return null;
    }

    public function findActiveByMachineCode(string $machineCode): ?SanitaryCertificate
    {
        foreach ($this->certificates as $cert) {
            $mCode = $cert->getMachineData()['code'] ?? '';
            if (strtoupper($mCode) === strtoupper(trim($machineCode)) && $cert->getStatus() === SanitaryCertificate::STATUS_VALID) {
                return $cert;
            }
        }
        return null;
    }

    public function findActiveByMachineId(int $machineId): ?SanitaryCertificate
    {
        foreach ($this->certificates as $cert) {
            if ($cert->getMachineId() === $machineId && $cert->getStatus() === SanitaryCertificate::STATUS_VALID) {
                return $cert;
            }
        }
        return null;
    }

    public function findLatestByMachineId(int $machineId): ?SanitaryCertificate
    {
        $latest = null;
        foreach ($this->certificates as $cert) {
            if ($cert->getMachineId() === $machineId) {
                $latest = $cert;
            }
        }
        return $latest;
    }

    public function suspendByMachineId(int $machineId, string $reason): int
    {
        $count = 0;
        foreach ($this->certificates as $id => $cert) {
            if ($cert->getMachineId() === $machineId && $cert->getStatus() === SanitaryCertificate::STATUS_VALID) {
                $this->certificates[$id] = new SanitaryCertificate(
                    id: $cert->getId(),
                    certificateCode: $cert->getCertificateCode(),
                    preventiveOrderId: $cert->getPreventiveOrderId(),
                    machineId: $cert->getMachineId(),
                    locationId: $cert->getLocationId(),
                    technicianId: $cert->getTechnicianId(),
                    technicianName: $cert->getTechnicianName(),
                    technicianOperatorCode: $cert->getTechnicianOperatorCode(),
                    inspectionDate: $cert->getInspectionDate(),
                    validUntil: $cert->getValidUntil(),
                    temperatureMeasured: $cert->getTemperatureMeasured(),
                    result: $cert->getResult(),
                    status: SanitaryCertificate::STATUS_SUSPENDED,
                    suspendedReason: $reason,
                    suspendedAt: date('Y-m-d H:i:s'),
                    createdAt: $cert->getCreatedAt(),
                    updatedAt: date('Y-m-d H:i:s'),
                    deletedAt: null,
                    machineData: $cert->getMachineData(),
                    locationData: $cert->getLocationData(),
                    inspectedItems: $cert->getInspectedItems()
                );
                $count++;
            }
        }
        return $count;
    }

    public function revokeByMachineId(int $machineId, string $reason): int
    {
        return 0;
    }

    public function getGlobalSiteReport(int $locationId): array
    {
        // Doble de prueba con soporte dinámico para diferentes dictámenes
        $hasQuarantine = false;
        $hasWarning = false;

        $breakdown = [];
        foreach ($this->certificates as $cert) {
            if ($cert->getLocationId() !== $locationId) {
                continue;
            }
            $isQuar = ($cert->getStatus() === SanitaryCertificate::STATUS_SUSPENDED);
            if ($isQuar) {
                $hasQuarantine = true;
            }
            if ($cert->getResult() === SanitaryCertificate::RESULT_CONFORME_OBSERVACIONES) {
                $hasWarning = true;
            }

            $breakdown[] = [
                'code' => $cert->getMachineData()['code'] ?? "VEND-{$cert->getMachineId()}",
                'model' => $cert->getMachineData()['model'] ?? 'Necta Touch',
                'machine_type' => $cert->getMachineData()['machine_type'] ?? 'PERISHABLE_FOOD',
                'floor_wing' => 'Planta 1',
                'verdict' => $isQuar ? 'NO_CONFORME' : $cert->getResult(),
                'semaphore' => $isQuar ? 'QUARANTINE' : ($cert->getResult() === 'CONFORME_CON_OBSERVACIONES' ? 'YELLOW' : 'GREEN'),
                'quarantine' => $isQuar,
                'last_inspection_date' => $cert->getInspectionDate(),
                'last_temperature_celsius' => $cert->getTemperatureMeasured(),
                'valid_until' => $cert->getValidUntil(),
                'certificate_code' => $cert->getCertificateCode(),
                'certificate_status' => $cert->getStatus(),
                'detail' => $isQuar ? 'Suspendido por rotura de frío.' : 'Inspección en vigor.',
            ];
        }

        if ($hasQuarantine) {
            $verdict = 'CONDICIONADO';
            $explanation = 'El centro dispone de máquinas con incidencias sanitarias o en cuarentena.';
        } elseif ($hasWarning) {
            $verdict = 'CONFORME_CON_OBSERVACIONES';
            $explanation = 'Todas las máquinas cumplen los estándares normativos críticos con observaciones secundarias.';
        } else {
            $verdict = 'CONFORME';
            $explanation = 'Todas las máquinas de la sede cuentan con certificación higiénico-sanitaria oficial en vigor.';
        }

        return [
            'global_certificate_code' => sprintf('SEDE-%02d-SAN-%s', $locationId, date('Y-m')),
            'location' => [
                'id' => $locationId,
                'site_code' => sprintf('SEDE-%02d', $locationId),
                'name' => 'Hospital Clínico Central',
                'address' => 'Gran Via 585, Barcelona',
            ],
            'issue_date' => date('c'),
            'global_verdict' => $verdict,
            'verdict_explanation' => $explanation,
            'has_quarantine_or_expired' => $hasQuarantine,
            'total_machines' => count($breakdown),
            'machines_breakdown' => $breakdown,
        ];
    }
}

class InMemoryOrderRepoForCert implements PreventiveOrderRepositoryInterface
{
    /** @var array<int, PreventiveOrder> */
    public array $orders = [];

    public function create(array $data): PreventiveOrder { throw new DomainException('Not implemented'); }
    public function findById(int $id, bool $allowCancelled = true): ?PreventiveOrder { return $this->orders[$id] ?? null; }
    public function findByCode(string $orderCode, bool $allowCancelled = true): ?PreventiveOrder { return null; }
    public function hasActiveOrPendingOrder(int $machineId): bool { return false; }
    public function assignTechnician(int $orderId, int $technicianId, string $scheduledDate): bool { return true; }
    public function claimOrderOpportunistically(int $orderId, int $technicianId): bool { return true; }
    public function startInspection(int $orderId, int $technicianId): bool { return true; }
    public function completeOrder(int $orderId, string $result, ?float $temp, bool $quarantine, ?int $incId = null, ?string $notes = null): bool { return true; }
    public function linkIncident(int $orderId, int $incidentId): bool { return true; }
    public function softCancel(int $orderId, string $reason): bool { return true; }
    public function findForCoordinatorList(array $filters = []): array { return []; }
    public function countForCoordinatorList(array $filters = []): int { return 0; }
    public function findForTechnicianRoute(int $technicianId, ?int $locationId = null): array { return []; }
    public function getDashboardSummary(): array { return []; }
    public function expireOverdueOrders(): int { return 0; }
}

class InMemorySettingsRepoForCert implements PreventiveSettingsRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $machines = [];

    public function findAll(): array { return []; }
    public function findByMachineType(string $machineType): ?PreventiveSetting { return null; }
    public function updateTypeSettings(string $type, int $def, int $max, int $adv = 5): bool { return true; }
    public function updateMachineConfig(int $id, ?int $days, ?string $due = null): bool { return true; }
    public function setSeasonalPause(int $id, string $reason, ?string $until = null): bool { return true; }
    public function resumeSeasonalPause(int $id): bool { return true; }
    public function getMachineSettings(int $machineId): ?array
    {
        return $this->machines[$machineId] ?? [
            'id' => $machineId,
            'code' => "VEND-MACH-{$machineId}",
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

class InMemoryMachineRepoForCert implements MachineRepositoryInterface
{
    /** @var array<int, Machine> */
    public array $machinesById = [];
    /** @var array<string, Machine> */
    public array $machinesByCode = [];

    public function findActiveByLocationId(int $locationId): array { return []; }
    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machinesById[$id] ?? null;
    }
    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        return $this->machinesByCode[strtoupper(trim($code))] ?? null;
    }
    public function create(array $data): Machine { throw new DomainException('Not implemented'); }
    public function update(int $id, array $data): bool { return true; }
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { return true; }
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { return true; }
    public function findAll(array $filters = []): array { return []; }
    public function hasActiveTicketOrWarranty(int $machineId): bool { return false; }
    public function getActiveTicketOrWarranty(int $machineId): ?array { return null; }
    public function softDelete(int $id): bool { return true; }
}

class SpyAuditLoggerForCert extends AuditLogger
{
    public array $machineEvents = [];

    public function __construct() {}

    public function logMachineEvent(int $machineId, string $action, array $user, ?array $previousState, array $newState, ?array $metadata = null): AuditEvent
    {
        $this->machineEvents[] = [
            'machine_id' => $machineId,
            'action' => $action,
            'user' => $user,
            'before' => $previousState,
            'after' => $newState,
            'metadata' => $metadata,
        ];
        return new AuditEvent(1, 'MACHINE', $machineId, $action, $user['id'] ?? null, $user['role'] ?? 'TECHNICIAN', $user['name'] ?? 'Técnico', $previousState, $newState, $metadata);
    }
}

// =============================================================================
// INICIO DE LA BATERÍA DE PRUEBAS
// =============================================================================

echo "====================================================================================\n";
echo " VendGuard: Pruebas Unitarias de SanitaryCertificateService (T-PREV-12)\n";
echo "====================================================================================\n\n";

$machineRepo = new InMemoryMachineRepoForCert();
$certRepo = new InMemorySanitaryCertificateRepository($machineRepo);
$orderRepo = new InMemoryOrderRepoForCert();
$settingsRepo = new InMemorySettingsRepoForCert();
$auditLogger = new SpyAuditLoggerForCert();

$certService = new SanitaryCertificateService(
    certificateRepo: $certRepo,
    orderRepo: $orderRepo,
    settingsRepo: $settingsRepo,
    machineRepo: $machineRepo,
    auditLogger: $auditLogger
);

// Registrar máquina 101 en repositorios
$machine101 = new Machine(
    id: 101,
    locationId: 10,
    code: 'VEND-BCN-101',
    model: 'Sanden Vendo G-Drink',
    machineType: MachineType::PERISHABLE_FOOD,
    floorWing: 'Planta Baja - Urgencias'
);
$machineRepo->machinesById[101] = $machine101;
$machineRepo->machinesByCode['VEND-BCN-101'] = $machine101;
$settingsRepo->machines[101] = [
    'id' => 101,
    'code' => 'VEND-BCN-101',
    'sanitary_status' => 'OK',
    'sanitary_frequency_days' => 15,
    'default_frequency_days' => 15,
];

// Registrar orden preventiva conforme completada
$orderRepo->orders[201] = new PreventiveOrder(
    id: 201,
    orderCode: 'PREV-2026-0201',
    machineId: 101,
    locationId: 10,
    assignedTechnicianId: 3,
    status: 'COMPLETED',
    orderType: 'ROUTINE',
    scheduledDate: '2026-09-27',
    dueDate: '2026-09-27',
    startedAt: '2026-09-27 10:00:00',
    completedAt: '2026-09-27 10:15:00',
    temperatureMeasured: 3.4,
    result: 'CONFORME',
    linkedIncidentId: null,
    isQuarantineTriggered: false,
    notes: 'Higienización correcta.',
    cancellationReason: null,
    createdAt: '2026-09-27 08:00:00',
    updatedAt: '2026-09-27 10:15:00',
    deletedAt: null,
    machineData: ['code' => 'VEND-BCN-101', 'model' => 'Sanden Vendo G-Drink'],
    locationData: ['name' => 'Hospital del Mar', 'address' => 'Passeig Marítim 25'],
    technicianData: ['id' => 3, 'name' => 'Carlos Técnico', 'operator_code' => 'OP-03']
);

// -----------------------------------------------------------------------------
echo "--- 1. Emisión Oficial de Certificado y Privacidad (RF-PREV-07, Art. V.4) ---\n";
// -----------------------------------------------------------------------------
$certificate = $certService->issueCertificate(
    orderId: 201,
    actor: ['id' => 3, 'name' => 'Carlos Técnico', 'operator_code' => 'OP-03']
);

assertTrue($certificate !== null, '1.1 Certificado emitido correctamente');
assertTrue(str_starts_with($certificate->getCertificateCode(), 'CERT-'), '1.2 Código asignado con prefijo CERT-');
assertEquals(SanitaryCertificate::STATUS_VALID, $certificate->getStatus(), '1.3 Estado inicial del certificado es VALID');
assertEquals(SanitaryCertificate::RESULT_CONFORME, $certificate->getResult(), '1.4 Dictamen registrado es CONFORME');
assertEquals(3.4, $certificate->getTemperatureMeasured(), '1.5 Temperatura registrada 3.4 °C');

// Blindaje de Privacidad del Técnico (Constitución Art. V.4)
assertEquals('Carlos Técnico', $certificate->getTechnicianName(), '1.6 Nombre profesional consignado');
assertEquals('OP-03', $certificate->getTechnicianOperatorCode(), '1.7 Código de Operador Técnico Oficial OP-03 consignado');

// Comprobar que en la entidad NO existen getters ni campos de DNI o teléfono personal
assertTrue(!method_exists($certificate, 'getTechnicianDni'), '1.8 Art. V.4: Ausencia de DNI en modelo de certificado');
assertTrue(!method_exists($certificate, 'getTechnicianPhone'), '1.9 Art. V.4: Ausencia de teléfono personal en modelo de certificado');

// Cálculo de vigencia (15 días en perecederos)
$expectedValidUntil = date('Y-m-d', strtotime('2026-09-27 10:15:00 +15 days'));
assertEquals($expectedValidUntil, $certificate->getValidUntil(), '1.10 Vigencia calculada sumando 15 días reglamentarios');

// Auditoría
$lastAuditEvent = end($auditLogger->machineEvents);
assertEquals('ISSUE_SANITARY_CERTIFICATE', $lastAuditEvent['action'], '1.11 Auditoría ISSUE_SANITARY_CERTIFICATE registrada');

// -----------------------------------------------------------------------------
echo "\n--- 2. Bloqueo de Emisión en Inspecciones No Conformes o Cuarentena (EARS 7.4) ---\n";
// -----------------------------------------------------------------------------
// Orden 202: Inspección no conforme por rotura de frío
$orderRepo->orders[202] = new PreventiveOrder(
    id: 202,
    orderCode: 'PREV-2026-0202',
    machineId: 101,
    locationId: 10,
    assignedTechnicianId: 3,
    status: 'COMPLETED',
    orderType: 'ROUTINE',
    scheduledDate: '2026-09-27',
    dueDate: '2026-09-27',
    startedAt: '2026-09-27 11:00:00',
    completedAt: '2026-09-27 11:15:00',
    temperatureMeasured: 6.8,
    result: 'NO_CONFORME',
    linkedIncidentId: null,
    isQuarantineTriggered: true,
    notes: 'Compresor averiado.',
    cancellationReason: null
);

$threwNonConform = false;
try {
    $certService->issueCertificate(orderId: 202);
} catch (CannotIssueNonConformCertificateException $e) {
    $threwNonConform = true;
    assertEquals(400, $e->getHttpStatusCode(), '2.1 Código HTTP es 400 Bad Request');
    assertEquals('CANNOT_GENERATE_CERTIFICATE_NOT_CONFORM', $e->getErrorCode(), '2.2 Código de error CANNOT_GENERATE_CERTIFICATE_NOT_CONFORM');
    assertTrue(str_contains($e->getMessage(), 'NO_CONFORME'), '2.3 Mensaje explica resultado NO_CONFORME');
}
assertTrue($threwNonConform, '2.4 EARS 7.4: Bloquea emisión de certificado de aptitud ante fallo no conforme');

// Intento de emitir para máquina en estado QUARANTINE
$settingsRepo->machines[101]['sanitary_status'] = 'QUARANTINE';
$threwQuarantine = false;
try {
    $certService->issueCertificate(orderId: 201);
} catch (CannotIssueNonConformCertificateException $e) {
    $threwQuarantine = true;
    assertEquals('QUARANTINE', $e->getSanitaryStatus(), '2.5 Captura estado QUARANTINE en detalles');
}
assertTrue($threwQuarantine, '2.6 EARS 7.4: Bloquea emisión para máquina en estado QUARANTINE');
$settingsRepo->machines[101]['sanitary_status'] = 'OK'; // Restaurar

// -----------------------------------------------------------------------------
echo "\n--- 3. Consulta de Certificado Individual Imprimible (EARS 7.1, 7.4) ---\n";
// -----------------------------------------------------------------------------
$individualCert = $certService->getIndividualCertificate('VEND-BCN-101');

assertEquals($certificate->getCertificateCode(), $individualCert['certificate_code'], '3.1 Código de certificado coincide');
assertEquals('VALID', $individualCert['status'], '3.2 Estado consultado es VALID');
assertEquals('VEND-BCN-101', $individualCert['machine']['code'], '3.3 Código de máquina correcto');
assertEquals('Carlos Técnico', $individualCert['inspector']['name'], '3.4 Nombre profesional de inspector visible');
assertEquals('OP-03', $individualCert['inspector']['operator_code'], '3.5 Código de operador visible');
assertTrue(!isset($individualCert['inspector']['dni']), '3.6 DNI privado oculto (Art. V.4)');
assertTrue(!isset($individualCert['inspector']['phone']), '3.7 Teléfono personal oculto (Art. V.4)');
assertTrue(str_contains($individualCert['verification_url'], $certificate->getCertificateCode()), '3.8 URL de verificación pública válida');

// Consulta de máquina en cuarentena arroja 400 (EARS 7.4)
$settingsRepo->machines[101]['sanitary_status'] = 'QUARANTINE';
$threwQueryQuarantine = false;
try {
    $certService->getIndividualCertificate('VEND-BCN-101');
} catch (CannotIssueNonConformCertificateException $e) {
    $threwQueryQuarantine = true;
    assertEquals(400, $e->getHttpStatusCode(), '3.9 Bloquea consulta con HTTP 400 ante cuarentena');
}
assertTrue($threwQueryQuarantine, '3.10 EARS 7.4: No entrega certificado de aptitud para máquina en cuarentena');
$settingsRepo->machines[101]['sanitary_status'] = 'OK';

// -----------------------------------------------------------------------------
echo "\n--- 4. Suspensión Cautelar por Avería de Frío Sobrevenida (Art. II, EARS 7.3) ---\n";
// -----------------------------------------------------------------------------
$suspendedCount = $certService->suspendCertificatesForColdBreach(
    machineId: 101,
    reason: 'Rotura de compresor detectada por telealarma o aviso correctivo (Art. II).'
);

assertEquals(1, $suspendedCount, '4.1 Suspendió exactamente 1 certificado vigente');

$certAfter = $certRepo->findById($certificate->getId());
assertEquals(SanitaryCertificate::STATUS_SUSPENDED, $certAfter->getStatus(), '4.2 Estado de certificado transicionado a SUSPENDED');
assertTrue(!empty($certAfter->getSuspendedReason()), '4.3 Motivo de suspensión almacenado');

// Comprobar que tras la suspensión, getIndividualCertificate no lo devuelve como apto
$threwSuspendedQuery = false;
try {
    $certService->getIndividualCertificate('VEND-BCN-101');
} catch (CannotIssueNonConformCertificateException $e) {
    $threwSuspendedQuery = true;
}
assertTrue($threwSuspendedQuery, '4.4 Certificado suspendido cautelarmente ya no es accesible como apto');

// Auditoría de suspensión
$lastAuditSusp = end($auditLogger->machineEvents);
assertEquals('SUSPEND_SANITARY_CERTIFICATE', $lastAuditSusp['action'], '4.5 Auditoría SUSPEND_SANITARY_CERTIFICATE registrada');

// -----------------------------------------------------------------------------
echo "\n--- 5. Certificado Global Consolidado de Sede (EARS 7.2) ---\n";
// -----------------------------------------------------------------------------
// Caso 5.1: Con 1 máquina suspendida en sede 10 -> Dictamen CONDICIONADO
$globalReportCond = $certService->getGlobalSiteCertificate(10);

assertEquals('CONDICIONADO', $globalReportCond['global_verdict'], '5.1 Dictamen global de sede es CONDICIONADO ante incidencias');
assertTrue($globalReportCond['has_quarantine_or_expired'], '5.2 has_quarantine_or_expired = true');
assertEquals('Hospital Clínico Central', $globalReportCond['location']['name'], '5.3 Nombre de sede presente');
assertEquals(1, count($globalReportCond['machines_breakdown']), '5.4 Desglose incluye máquinas del centro');
assertEquals('QUARANTINE', $globalReportCond['machines_breakdown'][0]['semaphore'], '5.5 Semáforo de máquina afectada en rojo/cuarentena');

// Caso 5.2: Reactivar máquina y emitir certificado conforme -> Dictamen CONFORME
$certRepo->certificates = []; // Limpiar
$orderRepo->orders[203] = new PreventiveOrder(
    id: 203,
    orderCode: 'PREV-2026-0203',
    machineId: 101,
    locationId: 10,
    assignedTechnicianId: 3,
    status: 'COMPLETED',
    orderType: 'REINSPECTION',
    scheduledDate: '2026-09-27',
    dueDate: '2026-09-27',
    startedAt: '2026-09-27 12:00:00',
    completedAt: '2026-09-27 12:15:00',
    temperatureMeasured: 3.1,
    result: 'CONFORME',
    linkedIncidentId: null,
    isQuarantineTriggered: false
);
$newCert = $certService->issueCertificate(orderId: 203, actor: ['id' => 3, 'name' => 'Carlos Técnico', 'operator_code' => 'OP-03']);

$globalReportOk = $certService->getGlobalSiteCertificate(10);
assertEquals('CONFORME', $globalReportOk['global_verdict'], '5.6 Dictamen global de sede pasa a CONFORME cuando todas las máquinas son aptas');
assertEquals(false, $globalReportOk['has_quarantine_or_expired'], '5.7 has_quarantine_or_expired = false');

// -----------------------------------------------------------------------------
echo "\n--- 6. Estado Sanitario y Semáforos de Sede (RF-PREV-06, EARS 6.2) ---\n";
// -----------------------------------------------------------------------------
$siteStatus = $certService->getSiteSanitaryStatus(10);

assertEquals('CONFORME', $siteStatus['global_status'], '6.1 Estado global de la sede es CONFORME');
assertEquals(1, count($siteStatus['machines']), '6.2 Lista máquinas asociadas');
assertEquals('GREEN', $siteStatus['machines'][0]['semaphore'], '6.3 Semáforo de máquina apta es GREEN');
assertEquals(3.1, $siteStatus['machines'][0]['last_temperature_celsius'], '6.4 Última temperatura registrada expuesta');
assertEquals('VALID', $siteStatus['machines'][0]['certificate_status'], '6.5 Estado de certificado VALID expuesto');

// =============================================================================
// RESUMEN FINAL
// =============================================================================
echo "\n====================================================================================\n";
echo " Total Aserciones: {$assertionsCount}\n";
echo " RESULTADO: 100% EN VERDE. Todas las reglas de SanitaryCertificateService verificadas.\n";
echo " Condición T-PREV-12 CUMPLIDA SATISFACTORIAMENTE.\n";
echo "====================================================================================\n";
