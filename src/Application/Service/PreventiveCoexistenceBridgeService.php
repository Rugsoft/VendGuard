<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DomainException;
use VendGuard\Core\Domain\Exception\ReinspectionTemperatureExceededException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitaryCertificateRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Service\ResolutionValidator;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;

/**
 * PreventiveCoexistenceBridgeService
 *
 * Puente Constitucional de Coexistencia entre Mantenimiento Preventivo e Incidencias Correctivas.
 * Aplica:
 * - RF-PREV-05 / EARS 5.1-5.3: Coexistencia armónica sin tickets duplicados.
 * - Constitución Art. II: Seguridad alimentaria estricta y cuarentena ante roturas térmicas.
 * - Constitución Art. V.1: Cierre técnico justificado obligatorio (>= 20 caracteres cada campo).
 * - Constitución Art. V.2: Unicidad de ticket activo (prohibición terminante de tickets duplicados).
 * - RF-PREV-08 / EARS 8.1-8.3: Procedimiento estricto de reinspección (<= 4.0 °C) y levantamiento de cuarentena.
 */
class PreventiveCoexistenceBridgeService
{
    private IncidentRepositoryInterface $incidentRepo;
    private PreventiveOrderRepositoryInterface $orderRepo;
    private PreventiveSettingsRepositoryInterface $settingsRepo;
    private MachineRepositoryInterface $machineRepo;
    private ?SanitaryCertificateRepositoryInterface $certificateRepo;
    private AuditLogger $auditLogger;

    public function __construct(
        IncidentRepositoryInterface $incidentRepo,
        PreventiveOrderRepositoryInterface $orderRepo,
        PreventiveSettingsRepositoryInterface $settingsRepo,
        ?MachineRepositoryInterface $machineRepo = null,
        ?SanitaryCertificateRepositoryInterface $certificateRepo = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->incidentRepo = $incidentRepo;
        $this->orderRepo = $orderRepo;
        $this->settingsRepo = $settingsRepo;
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->certificateRepo = $certificateRepo;
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Procesa la no conformidad detectada en un preventivo.
     * Si no existe ticket activo en la máquina: crea una incidencia vinculada (Art. V.1) con urgencia CRITICAL.
     * Si ya existe un ticket activo: incorpora la evidencia a la bitácora sin duplicar ticket (Art. V.2)
     * y eleva la urgencia a CRITICAL ante rotura de frío o fallo crítico.
     *
     * @param int $orderId
     * @param int $technicianId
     * @param float|null $temperature
     * @param array<int, array<string, mixed>> $nonConformItems
     * @param string|null $notes
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function handleNonConformity(
        int $orderId,
        int $technicianId,
        ?float $temperature = null,
        array $nonConformItems = [],
        ?string $notes = null,
        ?array $actor = null
    ): array {
        $order = $this->orderRepo->findById($orderId);
        if ($order === null) {
            throw new DomainException("Orden preventiva no encontrada con ID {$orderId}.");
        }

        $machineId = $order->getMachineId();
        $locationId = $order->getLocationId();

        $machineConfig = $this->settingsRepo->getMachineSettings($machineId);
        $machineType = strtoupper((string)($machineConfig['machine_type'] ?? 'PERISHABLE_FOOD'));
        $isPerishable = in_array($machineType, ['PERISHABLE_FOOD', 'COMBO'], true);

        // Determinar criticidad (rotura de frío o ítems críticos)
        $isColdBreach = ($isPerishable && $temperature !== null && $temperature > 4.0);
        $hasCriticalItem = false;
        foreach ($nonConformItems as $item) {
            $isItemCrit = !empty($item['is_critical']);
            $itemStatus = strtoupper((string)($item['status'] ?? ''));
            if ($isItemCrit && ($itemStatus === 'FAIL' || $itemStatus === 'NO_CONFORME')) {
                $hasCriticalItem = true;
                break;
            }
        }

        $isCriticalFailure = $isColdBreach || $hasCriticalItem || ($temperature !== null && $temperature > 4.0);
        $urgency = $isCriticalFailure ? UrgencyLevel::CRITICAL : UrgencyLevel::HIGH;

        $category = match (true) {
            $isColdBreach || ($temperature !== null && $temperature > 4.0) => IncidentCategory::TEMPERATURE_COLD,
            default => IncidentCategory::OTHER,
        };

        // Art. V.2: Comprobación estricta de unicidad de ticket activo
        $activeIncident = $this->incidentRepo->findActiveByMachineId($machineId);

        if ($activeIncident === null) {
            // =========================================================================
            // CASO A: Sin ticket previo -> Crear incidencia correctiva vinculada (Art. V.1)
            // =========================================================================
            $ticketCode = TicketCode::generate()->value();

            $description = "Avería detectada durante la inspección preventiva {$order->getOrderCode()}.";
            if ($temperature !== null) {
                $description .= " Temperatura de sonda registrada: {$temperature} °C.";
            }

            if (!empty($nonConformItems)) {
                $itemDescs = [];
                foreach ($nonConformItems as $item) {
                    $itemDescs[] = ($item['title'] ?? $item['item_code'] ?? 'Comprobación') .
                        (!empty($item['observations']) ? " (" . trim((string)$item['observations']) . ")" : '');
                }
                $description .= " Comprobaciones no conformes: " . implode('; ', $itemDescs) . ".";
            }

            if ($notes !== null && trim($notes) !== '') {
                $description .= " Observaciones técnicas: " . trim($notes) . ".";
            }

            $newIncident = new Incident(
                id: null,
                ticketCode: $ticketCode,
                machineId: $machineId,
                locationId: $locationId,
                category: $category,
                description: $description,
                urgency: $urgency,
                status: IncidentStatus::IN_PROGRESS,
                assignedTechnicianId: $technicianId,
                reporterName: 'Sistema Preventivo (VendGuard)',
                reporterPhone: '000000000',
                assignedAt: date('Y-m-d H:i:s'),
                startedAt: date('Y-m-d H:i:s')
            );

            $createdIncident = $this->incidentRepo->create(
                $newIncident,
                $technicianId,
                "Apertura automática de correctivo desde orden preventiva {$order->getOrderCode()}"
            );

            $incidentId = (int)$createdIncident->getId();
            $this->orderRepo->linkIncident($order->getId(), $incidentId);

            $user = [
                'id' => $actor['id'] ?? $technicianId,
                'role' => $actor['role'] ?? 'TECHNICIAN',
                'name' => $actor['name'] ?? 'Técnico de Campo',
            ];

            $this->auditLogger->logTicketEvent(
                $incidentId,
                'PREVENTIVE_TRIGGERED_INCIDENT',
                $user,
                null,
                [
                    'preventive_order_id' => $order->getId(),
                    'order_code' => $order->getOrderCode(),
                    'urgency' => $urgency->value,
                    'category' => $category->value,
                    'temperature' => $temperature,
                ]
            );

            return [
                'mode' => 'CREATED_NEW_INCIDENT',
                'ticket_code' => $createdIncident->getTicketCode(),
                'incident_id' => $incidentId,
                'category' => $category->value,
                'urgency' => $urgency->value,
                'status' => 'IN_PROGRESS',
                'assigned_to' => $technicianId,
                'preventive_order_id' => $order->getId(),
                'message' => 'Incidencia correctiva vinculada creada automáticamente con urgencia ' . $urgency->value . '.',
            ];
        }

        // =========================================================================
        // CASO B: Concurrencia con ticket activo previo -> CERO DUPLICADOS (Art. V.2)
        // =========================================================================
        $commentText = "ALERTA PREVENTIVA (Art. V.2): En la revisión {$order->getOrderCode()} se detectó fallo no conforme.";
        if ($temperature !== null) {
            $commentText .= " Temperatura medida: {$temperature} °C.";
        }

        if (!empty($nonConformItems)) {
            $itemNotes = [];
            foreach ($nonConformItems as $item) {
                $itemNotes[] = ($item['title'] ?? $item['item_code'] ?? 'Ítem') .
                    (!empty($item['observations']) ? " (" . trim((string)$item['observations']) . ")" : '');
            }
            $commentText .= " Comprobaciones fallidas: " . implode('; ', $itemNotes) . ".";
        }

        if ($notes !== null && trim($notes) !== '') {
            $commentText .= " Anotaciones: " . trim($notes) . ".";
        }

        $photoPath = null;
        foreach ($nonConformItems as $item) {
            if (!empty($item['photo_path'])) {
                $photoPath = (string)$item['photo_path'];
                break;
            }
        }

        $comment = new IncidentComment(
            id: null,
            incidentId: (int)$activeIncident->getId(),
            authorType: 'TECHNICIAN',
            userId: $technicianId,
            authorName: $actor['name'] ?? 'Técnico de Campo',
            commentText: $commentText,
            photoPath: $photoPath,
            isInternal: false
        );

        $this->incidentRepo->addComment($comment);

        // Elevar a CRITICAL si es rotura de frío o fallo crítico y no lo estaba
        $previousUrgency = $activeIncident->getUrgency()->value;
        $newUrgency = $previousUrgency;
        $escalated = false;

        if ($isCriticalFailure && $previousUrgency !== UrgencyLevel::CRITICAL->value) {
            $newUrgency = UrgencyLevel::CRITICAL->value;
            $updatedIncident = new Incident(
                id: $activeIncident->getId(),
                ticketCode: $activeIncident->getTicketCode(),
                machineId: $activeIncident->getMachineId(),
                locationId: $activeIncident->getLocationId(),
                category: $activeIncident->getCategory(),
                description: $activeIncident->getDescription(),
                urgency: UrgencyLevel::CRITICAL,
                status: $activeIncident->getStatus(),
                assignedTechnicianId: $activeIncident->getAssignedTechnicianId(),
                reporterName: $activeIncident->getReporterName(),
                reporterPhone: $activeIncident->getReporterPhone(),
                retainedMoneyAmount: $activeIncident->getRetainedMoneyAmount(),
                photoPath: $activeIncident->getPhotoPath(),
                assignedAt: $activeIncident->getAssignedAt(),
                startedAt: $activeIncident->getStartedAt(),
                pendingPartsReason: $activeIncident->getPendingPartsReason(),
                resolutionDiagnosis: $activeIncident->getResolutionDiagnosis(),
                resolutionAction: $activeIncident->getResolutionAction(),
                resolvedAt: $activeIncident->getResolvedAt(),
                reopenReason: $activeIncident->getReopenReason(),
                reopenedAt: $activeIncident->getReopenedAt(),
                closedAt: $activeIncident->getClosedAt(),
                cancellationReason: $activeIncident->getCancellationReason(),
                cancelledAt: $activeIncident->getCancelledAt(),
                isActiveTicket: $activeIncident->getIsActiveTicket()
            );

            $this->incidentRepo->update($updatedIncident);
            $escalated = true;

            $user = [
                'id' => $actor['id'] ?? $technicianId,
                'role' => $actor['role'] ?? 'TECHNICIAN',
                'name' => $actor['name'] ?? 'Técnico de Campo',
            ];

            $this->auditLogger->logTicketEvent(
                (int)$activeIncident->getId(),
                'URGENCY_ESCALATED_CRITICAL_BY_PREVENTIVE',
                $user,
                ['urgency' => $previousUrgency],
                [
                    'urgency' => UrgencyLevel::CRITICAL->value,
                    'reason' => "Escalado preventivo por rotura de frío o fallo crítico en orden {$order->getOrderCode()}",
                ]
            );
        }

        $this->orderRepo->linkIncident($order->getId(), (int)$activeIncident->getId());

        return [
            'mode' => 'APPENDED_TO_EXISTING_INCIDENT',
            'ticket_code' => $activeIncident->getTicketCode(),
            'incident_id' => $activeIncident->getId(),
            'note' => 'Añadida evidencia en bitácora sin duplicar ticket (Art. V.2). Urgencia ' .
                ($escalated ? 'elevada a CRÍTICA por rotura de frío o fallo crítico.' : 'mantenida.'),
            'previous_urgency' => $previousUrgency,
            'new_urgency' => $newUrgency,
            'escalated' => $escalated,
            'preventive_order_id' => $order->getId(),
        ];
    }

    /**
     * Resuelve la incidencia correctiva vinculada exigiendo al menos 20 caracteres independientes
     * en diagnóstico y solución técnica (Constitución Art. V.1).
     *
     * @param int $incidentId
     * @param int $technicianId
     * @param string $diagnosis
     * @param string $solution
     * @return Incident
     */
    public function resolveCorrectiveIncident(
        int $incidentId,
        int $technicianId,
        string $diagnosis,
        string $solution
    ): Incident {
        // Validación obligatoria e independiente de 20 caracteres (Art. V.1)
        ResolutionValidator::validate($diagnosis, $solution);

        return $this->incidentRepo->resolve($incidentId, $technicianId, $diagnosis, $solution);
    }

    /**
     * Coordina la reinspección sanitaria in situ tras la subsanación de avería (RF-PREV-08).
     * Si la temperatura supera los 4.0 °C en perecederos, bloquea el levantamiento de cuarentena
     * con ReinspectionTemperatureExceededException (Art. II).
     * Si es conforme (<= 4.0 °C), finaliza orden de reinspección, restituye estado sanitario a 'OK'
     * y habilita el desbloqueo del QR público siempre que no existan otras incidencias activas.
     *
     * @param int $orderId Orden preventiva de origen
     * @param int $technicianId
     * @param float $temperature
     * @param string $notes
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     * @throws ReinspectionTemperatureExceededException
     */
    public function reinspectAfterSubsanacion(
        int $orderId,
        int $technicianId,
        float $temperature,
        string $notes,
        ?array $actor = null
    ): array {
        $order = $this->orderRepo->findById($orderId);
        if ($order === null) {
            throw new DomainException("Orden preventiva de referencia no encontrada con ID {$orderId}.");
        }

        $machineId = $order->getMachineId();
        $locationId = $order->getLocationId();

        $machineConfig = $this->settingsRepo->getMachineSettings($machineId);
        $machineType = strtoupper((string)($machineConfig['machine_type'] ?? 'PERISHABLE_FOOD'));
        $isPerishable = in_array($machineType, ['PERISHABLE_FOOD', 'COMBO'], true);

        // Blindaje térmico estricto en reinspección (Art. II)
        if ($isPerishable && $temperature > 4.0) {
            throw new ReinspectionTemperatureExceededException(
                "No es posible levantar la cuarentena: la temperatura medida ({$temperature} °C) excede el límite máximo reglamentario de 4.0 °C.",
                $temperature,
                4.0
            );
        }

        if ($machineType === 'COLD_DRINKS' && $temperature > 8.0) {
            throw new ReinspectionTemperatureExceededException(
                "No es posible levantar la cuarentena: la temperatura de bebidas frías ({$temperature} °C) excede el límite de 8.0 °C.",
                $temperature,
                8.0
            );
        }

        // Crear y completar la orden de reinspección
        $reinspectionOrder = $this->orderRepo->create([
            'machine_id' => $machineId,
            'location_id' => $locationId,
            'assigned_technician_id' => $technicianId,
            'status' => 'IN_INSPECTION',
            'order_type' => 'REINSPECTION',
            'scheduled_date' => date('Y-m-d'),
            'due_date' => date('Y-m-d'),
            'notes' => $notes,
        ]);

        $this->orderRepo->completeOrder(
            $reinspectionOrder->getId(),
            'CONFORME',
            $temperature,
            false,
            $order->getLinkedIncidentId(),
            $notes
        );

        // Restituir semáforo sanitario a OK
        $this->settingsRepo->updateSanitaryStatus($machineId, 'OK');

        // Calcular nueva fecha de vencimiento y registrar inspección válida
        $frequencyDays = (int)($machineConfig['sanitary_frequency_days'] ?? $machineConfig['default_frequency_days'] ?? 15);
        $nextDue = date('Y-m-d', strtotime("+{$frequencyDays} days"));
        $this->settingsRepo->updateMachineConfig($machineId, $frequencyDays, $nextDue);
        $this->machineRepo->update($machineId, [
            'last_sanitary_inspection_at' => date('Y-m-d H:i:s'),
        ]);

        // Comprobar si existen otras incidencias correctivas activas pendientes en la máquina (EARS 8.3)
        $linkedIncidentId = $order->getLinkedIncidentId();
        $hasOtherActiveTickets = false;
        $allIncidents = $this->incidentRepo->findAll(['machine_id' => $machineId]);
        foreach ($allIncidents as $inc) {
            if ($inc->getMachineId() !== $machineId) {
                continue;
            }
            // La incidencia que originó el preventivo ya fue subsanada si está en RESOLVED o CLOSED
            if ($linkedIncidentId !== null && $inc->getId() === $linkedIncidentId) {
                if ($inc->getStatus() === IncidentStatus::RESOLVED || $inc->getStatus() === IncidentStatus::CLOSED) {
                    continue;
                }
            }
            if (!in_array($inc->getStatus(), [IncidentStatus::RESOLVED, IncidentStatus::CLOSED, IncidentStatus::CANCELLED], true)) {
                $hasOtherActiveTickets = true;
                break;
            }
        }
        $qrUnblocked = !$hasOtherActiveTickets;

        $certificateData = [
            'certificate_code' => sprintf('CERT-%s-%04d', date('Y'), random_int(1000, 9999)),
            'valid_until' => $nextDue,
            'technician_operator_code' => $actor['operator_code'] ?? 'OP-' . sprintf('%02d', $technicianId),
        ];

        // Auditoría inmutable (Art. III)
        $user = [
            'id' => $actor['id'] ?? $technicianId,
            'role' => $actor['role'] ?? 'TECHNICIAN',
            'name' => $actor['name'] ?? 'Técnico de Campo',
        ];

        $this->auditLogger->logMachineEvent(
            $machineId,
            'REINSPECTION_COMPLETED',
            $user,
            ['sanitary_status' => 'QUARANTINE'],
            [
                'sanitary_status' => 'OK',
                'temperature_measured' => $temperature,
                'reinspection_order_id' => $reinspectionOrder->getId(),
                'qr_unblocked' => $qrUnblocked,
                'has_other_active_tickets' => $hasOtherActiveTickets,
            ]
        );

        return [
            'order_id' => $reinspectionOrder->getId(),
            'order_code' => $reinspectionOrder->getOrderCode(),
            'order_type' => 'REINSPECTION',
            'result' => 'CONFORME',
            'temperature_measured' => $temperature,
            'machine_sanitary_status' => 'OK',
            'qr_unblocked' => $qrUnblocked,
            'certificate' => $certificateData,
            'message' => 'Reinspección superada satisfactoriamente. Cuarentena levantada y máquina en servicio.',
        ];
    }
}
