<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DomainException;
use VendGuard\Core\Domain\Exception\CannotIssueNonConformCertificateException;
use VendGuard\Core\Domain\Model\SanitaryCertificate;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitaryCertificateRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;

/**
 * SanitaryCertificateService
 *
 * Servicio de Aplicación responsable de:
 * - Emisión oficial de Certificados Higiénico-Sanitarios individuales (RF-PREV-07, EARS 7.1).
 * - Protección rigurosa de datos del personal técnico según Artículo V.4 de la Constitución (operator_code sin DNI ni teléfono privado).
 * - Generación del Certificado Global Consolidado de Sede con dictamen CONDICIONADO ante anomalías (EARS 7.2).
 * - Suspensión cautelar automática de certificados ante averías de frío sobrevenidas (Art. II y EARS 7.3).
 * - Bloqueo de emisión de credenciales de aptitud para máquinas no conformes o en cuarentena (EARS 7.4).
 */
class SanitaryCertificateService
{
    private SanitaryCertificateRepositoryInterface $certificateRepo;
    private PreventiveOrderRepositoryInterface $orderRepo;
    private PreventiveSettingsRepositoryInterface $settingsRepo;
    private MachineRepositoryInterface $machineRepo;
    private AuditLogger $auditLogger;

    public function __construct(
        SanitaryCertificateRepositoryInterface $certificateRepo,
        PreventiveOrderRepositoryInterface $orderRepo,
        PreventiveSettingsRepositoryInterface $settingsRepo,
        ?MachineRepositoryInterface $machineRepo = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->certificateRepo = $certificateRepo;
        $this->orderRepo = $orderRepo;
        $this->settingsRepo = $settingsRepo;
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Emite un certificado sanitario individual oficial tras una inspección conforme.
     * Bloquea la emisión si el resultado fue NO_CONFORME o la máquina está en cuarentena (EARS 7.4).
     *
     * @param int $orderId Identificador de la orden preventiva finalizada
     * @param array<string, mixed>|null $actor Datos del técnico autenticado
     * @return SanitaryCertificate
     * @throws CannotIssueNonConformCertificateException Si la máquina o inspección no es conforme
     */
    public function issueCertificate(int $orderId, ?array $actor = null): SanitaryCertificate
    {
        $order = $this->orderRepo->findById($orderId);
        if ($order === null) {
            throw new DomainException("Orden preventiva no encontrada con ID {$orderId}.");
        }

        $machineId = $order->getMachineId();
        $machineSettings = $this->settingsRepo->getMachineSettings($machineId);
        $machineCode = (string)($machineSettings['code'] ?? "VEND-{$machineId}");
        $sanitaryStatus = (string)($machineSettings['sanitary_status'] ?? 'OK');

        // Bloqueo estricto de emisión en máquinas no conformes (EARS 7.4)
        $orderResult = strtoupper((string)($order->getResult() ?? ''));
        if ($orderResult === 'NO_CONFORME' || $order->isQuarantineTriggered()) {
            throw new CannotIssueNonConformCertificateException(
                "No se puede emitir el Certificado Sanitario de Aptitud: la inspección de la máquina {$machineCode} resultó NO_CONFORME.",
                $machineCode,
                'QUARANTINE'
            );
        }

        if (in_array($sanitaryStatus, ['QUARANTINE', 'EXPIRED'], true)) {
            throw new CannotIssueNonConformCertificateException(
                "No se puede emitir el Certificado Sanitario de Aptitud para una máquina en estado de {$sanitaryStatus}.",
                $machineCode,
                $sanitaryStatus
            );
        }

        // Cumplimiento Artículo V.4: Identificación exclusiva por nombre profesional y Código de Operador Oficial
        $technicianId = $order->getAssignedTechnicianId() ?? (isset($actor['id']) ? (int)$actor['id'] : 1);
        $technicianName = (string)($actor['name'] ?? $order->getTechnicianData()['name'] ?? 'Técnico Autorizado');
        $operatorCode = (string)($actor['operator_code'] ?? $order->getTechnicianData()['operator_code'] ?? sprintf('OP-%02d', $technicianId));

        $inspectionDate = $order->getCompletedAt() ?? date('Y-m-d H:i:s');
        $frequencyDays = (int)($machineSettings['sanitary_frequency_days'] ?? $machineSettings['default_frequency_days'] ?? 15);
        $validUntil = date('Y-m-d', strtotime("{$inspectionDate} +{$frequencyDays} days"));

        $result = ($orderResult === SanitaryCertificate::RESULT_CONFORME_OBSERVACIONES)
            ? SanitaryCertificate::RESULT_CONFORME_OBSERVACIONES
            : SanitaryCertificate::RESULT_CONFORME;

        $certificate = $this->certificateRepo->createCertificate([
            'preventive_order_id' => $orderId,
            'machine_id' => $machineId,
            'location_id' => $order->getLocationId(),
            'technician_id' => $technicianId,
            'technician_name' => $technicianName,
            'technician_operator_code' => $operatorCode,
            'inspection_date' => $inspectionDate,
            'valid_until' => $validUntil,
            'temperature_measured' => $order->getTemperatureMeasured(),
            'result' => $result,
            'status' => SanitaryCertificate::STATUS_VALID,
        ]);

        // Registrar auditoría inmutable (Art. III)
        $user = [
            'id' => $actor['id'] ?? $technicianId,
            'role' => $actor['role'] ?? 'TECHNICIAN',
            'name' => $technicianName,
        ];

        $this->auditLogger->logMachineEvent(
            $machineId,
            'ISSUE_SANITARY_CERTIFICATE',
            $user,
            null,
            [
                'certificate_code' => $certificate->getCertificateCode(),
                'order_id' => $orderId,
                'result' => $result,
                'valid_until' => $validUntil,
                'operator_code' => $operatorCode,
            ]
        );

        return $certificate;
    }

    /**
     * Consulta el certificado sanitario vigente de una máquina para vista del cliente/sede (Art. V.4).
     * Oculta datos privados y valida aptitud (EARS 7.1, 7.4).
     *
     * @param string $machineCode Código rotulado de la máquina
     * @return array<string, mixed>
     * @throws CannotIssueNonConformCertificateException Si la máquina está vencida o en cuarentena
     */
    public function getIndividualCertificate(string $machineCode): array
    {
        $normalizedCode = strtoupper(trim($machineCode));
        $machine = $this->machineRepo->findByCode($normalizedCode);
        if ($machine === null) {
            throw new DomainException("Máquina con código '{$machineCode}' no encontrada.");
        }

        $machineSettings = $this->settingsRepo->getMachineSettings($machine->getId());
        $sanitaryStatus = (string)($machineSettings['sanitary_status'] ?? 'OK');

        // EARS 7.4: Bloqueo de certificado si está en cuarentena o vencida
        if (in_array($sanitaryStatus, ['QUARANTINE', 'EXPIRED'], true)) {
            throw new CannotIssueNonConformCertificateException(
                "No se puede emitir el Certificado Sanitario de Aptitud para una máquina en estado de {$sanitaryStatus}.",
                $machine->getCode(),
                $sanitaryStatus
            );
        }

        $cert = $this->certificateRepo->findActiveByMachineCode($normalizedCode);
        if ($cert === null) {
            throw new CannotIssueNonConformCertificateException(
                "No existe ningún Certificado Sanitario oficial vigente para la máquina {$normalizedCode}.",
                $machine->getCode(),
                $sanitaryStatus
            );
        }

        $locationData = $cert->getLocationData() ?? [];
        $machineData = $cert->getMachineData() ?? [];

        return [
            'certificate_code' => $cert->getCertificateCode(),
            'status' => $cert->getStatus(),
            'machine' => [
                'code' => $machine->getCode(),
                'model' => $machine->getModel(),
                'serial_number' => $machineData['serial_number'] ?? 'SN-' . substr(md5($machine->getCode()), 0, 8),
                'machine_type' => $machine->getMachineType()->value,
            ],
            'location' => [
                'name' => (string)($locationData['name'] ?? ''),
                'address' => (string)($locationData['address'] ?? ''),
            ],
            'inspector' => [
                'name' => $cert->getTechnicianName(),
                'operator_code' => $cert->getTechnicianOperatorCode(),
            ],
            'inspection_date' => $cert->getInspectionDate(),
            'valid_until' => $cert->getValidUntil(),
            'temperature_measured' => $cert->getTemperatureMeasured(),
            'result' => $cert->getResult(),
            'inspected_items' => $cert->getInspectedItems() ?? [],
            'verification_url' => "https://vendguard.internal/verify/{$cert->getCertificateCode()}",
        ];
    }

    /**
     * Genera el Certificado Global Consolidado de Sede (EARS 7.2).
     * Si alguna máquina falla, se encuentra vencida o en cuarentena, dictamina CONDICIONADO.
     *
     * @param int $locationId
     * @return array<string, mixed>
     */
    public function getGlobalSiteCertificate(int $locationId): array
    {
        $report = $this->certificateRepo->getGlobalSiteReport($locationId);
        if (empty($report)) {
            throw new DomainException("Sede no encontrada o sin máquinas vinculadas para el ID {$locationId}.");
        }

        return $report;
    }

    /**
     * Aplica la suspensión cautelar automática a los certificados sanitarios de una máquina
     * ante una avería sobrevenida de refrigeración o alerta sanitaria (Constitución Art. II y EARS 7.3).
     *
     * @param int $machineId
     * @param string $reason
     * @param array<string, mixed>|null $actor
     * @return int Número de certificados suspendidos
     */
    public function suspendCertificatesForColdBreach(int $machineId, string $reason, ?array $actor = null): int
    {
        $suspendedCount = $this->certificateRepo->suspendByMachineId($machineId, $reason);

        $user = [
            'id' => $actor['id'] ?? null,
            'role' => $actor['role'] ?? 'SYSTEM',
            'name' => $actor['name'] ?? 'Sistema Preventivo (VendGuard)',
        ];

        $this->auditLogger->logMachineEvent(
            $machineId,
            'SUSPEND_SANITARY_CERTIFICATE',
            $user,
            ['certificate_status' => SanitaryCertificate::STATUS_VALID],
            [
                'certificate_status' => SanitaryCertificate::STATUS_SUSPENDED,
                'reason' => $reason,
                'count' => $suspendedCount,
            ]
        );

        return $suspendedCount;
    }

    /**
     * Retorna el estado higiénico-sanitario y semáforos de todas las máquinas de la sede (RF-PREV-06, EARS 6.2).
     *
     * @param int $locationId
     * @return array<string, mixed>
     */
    public function getSiteSanitaryStatus(int $locationId): array
    {
        $global = $this->getGlobalSiteCertificate($locationId);

        $machines = array_map(function (array $m): array {
            return [
                'code' => $m['code'],
                'model' => $m['model'],
                'floor_wing' => $m['floor_wing'],
                'machine_type' => $m['machine_type'],
                'semaphore' => $m['semaphore'],
                'last_inspection_date' => $m['last_inspection_date'],
                'last_temperature_celsius' => $m['last_temperature_celsius'],
                'valid_until' => $m['valid_until'],
                'certificate_status' => $m['certificate_status'],
                'notice' => $m['detail'],
            ];
        }, $global['machines_breakdown']);

        return [
            'location' => $global['location'],
            'global_status' => $global['global_verdict'],
            'has_quarantine_or_expired' => $global['has_quarantine_or_expired'],
            'machines' => $machines,
        ];
    }
}
