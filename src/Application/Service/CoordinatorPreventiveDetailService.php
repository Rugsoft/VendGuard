<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use VendGuard\Application\DTO\CoordinatorPreventiveOrderDetailDto;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Model\PreventiveOrderItem;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveItemRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitaryCertificateRepositoryInterface;

/**
 * Assembles the coordinator's integral preventive order detail (module 05, RF-PD-01 a RF-PD-10).
 *
 * This service is the only place where the raw order, its checklist answers, its linked
 * corrective incident, the machine's latest sanitary certificate and its audit trail
 * become the nine blocks of the detail contract. It owns three rules:
 *
 * - The sanitary validity traffic light (RF-PD-03.3, Art. II): a live countdown on open
 *   orders, and the formal historical balance once the order is completed or cancelled.
 * - The compliance accounting of the normative checklist (RF-PD-05.2), including the
 *   critical failures that force quarantine.
 * - The read-only guarantee (RNF-PD-04, Art. III): it opens no transaction of its own,
 *   writes nothing and never emits audit events.
 *
 * The link to the corrective incident is taken exclusively from `linked_incident_id`;
 * active incidents of the machine that did not originate from this order are never
 * inferred (RF-PD-07.3).
 */
final class CoordinatorPreventiveDetailService
{
    /** Days before the due date that turn the traffic light amber (RF-PD-03.3, EARS 6.1). */
    private const WARNING_WINDOW_DAYS = 5;

    /**
     * Entity type under which the preventive cycle is audited. The writers of the
     * module (CoordinatorPreventiveController, TechnicianPreventiveController,
     * SanitaryCertificateService, PreventiveChecklistEvaluationService and the
     * coexistence bridge) all record on the audited machine via AuditLogger::logMachineEvent,
     * so the order's chronology is the machine trail filtered down to the preventive
     * vocabulary and attributed to one order by `metadata.order_code`.
     */
    private const AUDIT_ENTITY_TYPE = AuditEvent::ENTITY_MACHINE;

    /** Orders whose lifecycle already closed: no countdown, only historical balance. */
    private const CLOSED_STATUSES = ['COMPLETED', 'CANCELLED'];

    private const STATUS_LABELS = [
        'PENDING_ASSIGNMENT' => 'Pendiente de asignación',
        'SCHEDULED' => 'Programada',
        'IN_INSPECTION' => 'En inspección',
        'COMPLETED' => 'Completada',
        'EXPIRED' => 'Vencida',
        'CANCELLED' => 'Cancelada',
    ];

    private const ORDER_TYPE_LABELS = [
        'ROUTINE' => 'Ordinaria',
        'REINSPECTION' => 'Reinspección',
        'MANUAL_EXTRA' => 'Extraordinaria',
    ];

    private const RESULT_LABELS = [
        'CONFORME' => 'Conforme',
        'CONFORME_CON_OBSERVACIONES' => 'Conforme con observaciones',
        'NO_CONFORME' => 'No conforme',
        'NO_EVALUABLE_POR_CAUSA_EXTERNA' => 'No evaluable por causa externa',
    ];

    private const ITEM_STATUS_LABELS = [
        PreventiveOrderItem::STATUS_PASS => 'Conforme',
        PreventiveOrderItem::STATUS_WARN => 'Con observaciones',
        PreventiveOrderItem::STATUS_FAIL => 'No conforme',
        PreventiveOrderItem::STATUS_NOT_APPLICABLE => 'No aplicable',
    ];

    private const SANITARY_STATUS_LABELS = [
        'OK' => 'Operativa y vigente',
        'ATTENTION_REQUIRED' => 'Requiere atención sanitaria',
        'EXPIRED' => 'Inspección vencida',
        'QUARANTINE' => 'Cuarentena sanitaria',
        'SEASONAL_PAUSE' => 'Pausa estacional',
    ];

    private const CERTIFICATE_STATUS_LABELS = [
        'VALID' => 'Vigente',
        'SUSPENDED' => 'Suspendido cautelarmente',
        'REVOKED' => 'Revocado',
    ];

    private const VALIDITY_STATE_LABELS = [
        'VIGENTE' => 'Vigente',
        'PROXIMA_A_VENCER' => 'Próxima a vencer',
        'VENCIDA' => 'Vencida',
        'CUARENTENA' => 'Cuarentena sanitaria',
        'PAUSA_ESTACIONAL' => 'Pausa estacional',
        'CERRADA' => 'Inspección cerrada',
    ];

    /**
     * Spanish labels of the preventive audit actions, mirroring exactly what the
     * writers of the module emit. Unknown actions keep their technical code so no
     * trace is ever hidden (Art. III).
     */
    private const AUDIT_ACTION_LABELS = [
        'CREATE_PREVENTIVE_ORDER' => 'Creación de la orden preventiva',
        'ASSIGN_PREVENTIVE_ORDER' => 'Asignación de técnico',
        'CLAIM_PREVENTIVE_ORDER' => 'Autoasignación en visita oportunista',
        'START_PREVENTIVE_INSPECTION' => 'Inicio de la inspección',
        'EVALUATE_PREVENTIVE_CHECKLIST' => 'Finalización de la inspección',
        'CANCEL_PREVENTIVE_ORDER' => 'Cancelación lógica de la orden',
        'ISSUE_SANITARY_CERTIFICATE' => 'Emisión de certificado sanitario',
        'SUSPEND_SANITARY_CERTIFICATE' => 'Suspensión cautelar del certificado sanitario',
        'REINSPECTION_COMPLETED' => 'Reinspección sanitaria superada',
    ];

    public function __construct(
        private readonly PreventiveOrderRepositoryInterface $orderRepo,
        private readonly PreventiveItemRepositoryInterface $itemRepo,
        private readonly SanitaryCertificateRepositoryInterface $certificateRepo,
        private readonly IncidentRepositoryInterface $incidentRepo,
        private readonly AuditLogRepositoryInterface $auditRepo
    ) {
    }

    /**
     * Builds the full detail projection for one preventive order, or null when it does not exist.
     *
     * The optional `$now` exists so the validity traffic light is deterministic in tests.
     */
    public function buildDetail(int|string $identifier, ?DateTimeImmutable $now = null): ?CoordinatorPreventiveOrderDetailDto
    {
        $order = $this->resolveOrder($identifier);
        if ($order === null) {
            return null;
        }

        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($this->madridTimezone());
        $machineBlock = $this->buildMachineBlock($order);

        return new CoordinatorPreventiveOrderDetailDto(
            order: $this->buildOrderBlock($order),
            location: $this->buildLocationBlock($order),
            machine: $machineBlock,
            technician: $this->buildTechnicianBlock($order),
            validity: $this->buildValidityBlock($order, $machineBlock, $now),
            checklist: $this->buildChecklistBlock($order),
            linkedIncident: $this->buildLinkedIncidentBlock($order),
            certificate: $this->buildCertificateBlock($order),
            auditTrail: $this->buildAuditTrailBlock($order)
        );
    }

    /**
     * Resolves the route identifier: a positive primary key or an order code
     * (`PREV-2026-0001`, with an optional leading `#`).
     */
    private function resolveOrder(int|string $identifier): ?PreventiveOrder
    {
        if (is_int($identifier) || (is_string($identifier) && $identifier !== '' && ctype_digit($identifier))) {
            $orderId = (int)$identifier;
            if ($orderId <= 0) {
                return null;
            }

            return $this->orderRepo->findById($orderId);
        }

        $code = strtoupper(ltrim(trim((string)$identifier), '#'));
        if ($code === '') {
            return null;
        }

        return $this->orderRepo->findByCode($code);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderBlock(PreventiveOrder $order): array
    {
        $status = $order->getStatus();
        $orderType = $order->getOrderType();
        $result = $order->getResult();

        return [
            'id' => $order->getId(),
            'order_code' => $order->getOrderCode(),
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status] ?? $status,
            'order_type' => $orderType,
            'order_type_label' => self::ORDER_TYPE_LABELS[$orderType] ?? $orderType,
            'scheduled_date' => $order->getScheduledDate(),
            'due_date' => $order->getDueDate(),
            'created_at' => $order->getCreatedAt(),
            'updated_at' => $order->getUpdatedAt(),
            'started_at' => $order->getStartedAt(),
            'completed_at' => $order->getCompletedAt(),
            'temperature_measured' => $order->getTemperatureMeasured(),
            'result' => $result,
            'result_label' => $result !== null ? (self::RESULT_LABELS[$result] ?? $result) : null,
            'is_quarantine_triggered' => $order->isQuarantineTriggered(),
            'notes' => $order->getNotes(),
            'cancellation_reason' => $order->getCancellationReason(),
            'linked_incident_id' => $order->getLinkedIncidentId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLocationBlock(PreventiveOrder $order): array
    {
        $location = $order->getLocationData() ?? [];

        return [
            'id' => $order->getLocationId(),
            'name' => (string)($location['name'] ?? ''),
            'site_code' => (string)($location['site_code'] ?? ''),
            'address' => (string)($location['address'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMachineBlock(PreventiveOrder $order): array
    {
        $machine = $order->getMachineData() ?? [];
        $machineType = (string)($machine['machine_type'] ?? '');
        $type = MachineType::tryFrom($machineType);
        $sanitaryStatus = (string)($machine['sanitary_status'] ?? 'OK');

        return [
            'id' => $order->getMachineId(),
            'code' => (string)($machine['code'] ?? ''),
            'model' => (string)($machine['model'] ?? ''),
            'machine_type' => $machineType,
            'machine_type_label' => $type?->label() ?? $machineType,
            'floor_wing' => (string)($machine['floor_wing'] ?? ''),
            'has_perishables' => $type === MachineType::PERISHABLE_FOOD,
            'sanitary_status' => $sanitaryStatus,
            'sanitary_status_label' => self::SANITARY_STATUS_LABELS[$sanitaryStatus] ?? $sanitaryStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTechnicianBlock(PreventiveOrder $order): array
    {
        $technician = $order->getTechnicianData();

        return [
            'assigned' => $technician !== null,
            'id' => $technician !== null ? (int)($technician['id'] ?? 0) : null,
            'name' => $technician !== null ? (string)($technician['name'] ?? '') : null,
            'operator_code' => $technician !== null ? (string)($technician['operator_code'] ?? '') : null,
        ];
    }

    /**
     * Sanitary validity traffic light (RF-PD-03.3, Art. II).
     *
     * Precedence: quarantine and seasonal pause describe the machine's sanitary state,
     * so they win over the order's own lifecycle; closed orders publish the immutable
     * historical balance instead of a countdown.
     *
     * @param array<string, mixed> $machineBlock Machine block already composed.
     * @return array<string, mixed>
     */
    private function buildValidityBlock(PreventiveOrder $order, array $machineBlock, DateTimeImmutable $now): array
    {
        $sanitaryStatus = (string)$machineBlock['sanitary_status'];
        $isClosed = in_array($order->getStatus(), self::CLOSED_STATUSES, true);
        $daysRemaining = $isClosed ? null : $this->daysUntilDue($order->getDueDate(), $now);
        $isOverdue = $daysRemaining !== null && $daysRemaining < 0;

        if ($order->isQuarantineTriggered() || $sanitaryStatus === 'QUARANTINE') {
            $state = 'CUARENTENA';
        } elseif ($sanitaryStatus === 'SEASONAL_PAUSE') {
            $state = 'PAUSA_ESTACIONAL';
        } elseif ($isClosed) {
            $state = 'CERRADA';
        } elseif ($isOverdue) {
            $state = 'VENCIDA';
        } elseif ($daysRemaining !== null && $daysRemaining <= self::WARNING_WINDOW_DAYS) {
            $state = 'PROXIMA_A_VENCER';
        } else {
            $state = 'VIGENTE';
        }

        return [
            'state' => $state,
            'state_label' => self::VALIDITY_STATE_LABELS[$state] ?? $state,
            'days_remaining' => $daysRemaining,
            'is_overdue' => $isOverdue,
            'valid_until' => $order->getDueDate(),
            'balance_label' => $this->buildBalanceLabel($order),
        ];
    }

    /**
     * Historical balance shown when the order already closed its lifecycle.
     */
    private function buildBalanceLabel(PreventiveOrder $order): ?string
    {
        if ($order->getStatus() === 'COMPLETED') {
            $completedAt = $order->getCompletedAt();

            return $completedAt !== null
                ? 'Inspección completada el ' . $this->formatDateTime($completedAt)
                : 'Inspección completada';
        }

        if ($order->getStatus() === 'CANCELLED') {
            return 'Orden cancelada lógicamente: sin inspección ejecutada';
        }

        return null;
    }

    /**
     * Natural-day difference between today and the due date in Europe/Madrid.
     * Negative values mean the order is already overdue.
     */
    private function daysUntilDue(string $dueDate, DateTimeImmutable $now): ?int
    {
        $due = $this->parseDate($dueDate);
        if ($due === null) {
            return null;
        }

        $today = $now->setTime(0, 0, 0);

        return (int)$today->diff($due)->format('%r%a');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChecklistBlock(PreventiveOrder $order): array
    {
        $items = $this->itemRepo->findByOrderId($order->getId());

        $totals = [
            'total' => count($items),
            'pass' => 0,
            'warn' => 0,
            'fail' => 0,
            'not_applicable' => 0,
            'critical_failures' => 0,
        ];
        $serializedItems = [];

        foreach ($items as $item) {
            $status = $item->getStatus();
            switch ($status) {
                case PreventiveOrderItem::STATUS_PASS:
                    $totals['pass']++;
                    break;
                case PreventiveOrderItem::STATUS_WARN:
                    $totals['warn']++;
                    break;
                case PreventiveOrderItem::STATUS_FAIL:
                    $totals['fail']++;
                    break;
                case PreventiveOrderItem::STATUS_NOT_APPLICABLE:
                    $totals['not_applicable']++;
                    break;
            }

            if ($item->isCriticalFailure()) {
                $totals['critical_failures']++;
            }

            $serializedItems[] = [
                'item_code' => $item->getItemCode(),
                'item_description' => $item->getItemDescription(),
                'is_critical' => $item->isCritical(),
                'status' => $status,
                'status_label' => self::ITEM_STATUS_LABELS[$status] ?? $status,
                'observations' => $item->getObservations(),
                'photo_url' => $item->getPhotoPath(),
            ];
        }

        // El porcentaje mide los ítems estrictamente conformes sobre los evaluables:
        // los no aplicables no penalizan y las observaciones siguen contándose aparte.
        $evaluable = $totals['total'] - $totals['not_applicable'];
        $compliancePercent = $evaluable > 0
            ? (int)round($totals['pass'] / $evaluable * 100)
            : 0;

        return [
            'has_checklist' => $totals['total'] > 0,
            'totals' => $totals,
            'compliance_percent' => $compliancePercent,
            'items' => $serializedItems,
        ];
    }

    /**
     * Corrective incident opened from this inspection (RF-PD-07, Art. V.2).
     *
     * @return array<string, mixed>|null
     */
    private function buildLinkedIncidentBlock(PreventiveOrder $order): ?array
    {
        $incidentId = $order->getLinkedIncidentId();
        if ($incidentId === null) {
            return null;
        }

        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            return null;
        }

        $status = $incident->getStatus();
        $urgency = $incident->getUrgency();

        return [
            'id' => (int)$incident->getId(),
            'ticket_code' => $incident->getTicketCode(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'urgency' => $urgency->value,
            'urgency_label' => $urgency->label(),
            'created_at' => $incident->getCreatedAt(),
        ];
    }

    /**
     * Latest sanitary certificate of the machine, read-only (RF-PD-08, Art. V.4).
     *
     * @return array<string, mixed>|null
     */
    private function buildCertificateBlock(PreventiveOrder $order): ?array
    {
        $certificate = $this->certificateRepo->findLatestByMachineId($order->getMachineId());
        if ($certificate === null) {
            return null;
        }

        $status = $certificate->getStatus();
        $result = $certificate->getResult();

        return [
            'certificate_code' => $certificate->getCertificateCode(),
            'inspection_date' => $certificate->getInspectionDate(),
            'valid_until' => $certificate->getValidUntil(),
            'temperature_measured' => $certificate->getTemperatureMeasured(),
            'result' => $result,
            'result_label' => self::RESULT_LABELS[$result] ?? $result,
            'status' => $status,
            'status_label' => self::CERTIFICATE_STATUS_LABELS[$status] ?? $status,
            'inspector' => [
                'name' => $certificate->getTechnicianName(),
                'operator_code' => $certificate->getTechnicianOperatorCode(),
            ],
        ];
    }

    /**
     * Immutable audit chronology of the order (RF-PD-09, Art. III).
     *
     * The module's writers record each preventive action on the audited machine
     * (`entity_type = 'MACHINE'`), so the order's chronology is built from the
     * machine's trail, filtered down to the preventive action vocabulary and
     * attributed to this order by the `order_code` that every preventive writer
     * stores in `metadata` (or, while it does not, by the direct `order_id`
     * reference in `metadata`). The repository already returns the events in
     * ascending chronological order.
     *
     * @return list<array<string, mixed>>
     */
    private function buildAuditTrailBlock(PreventiveOrder $order): array
    {
        $trail = [];

        foreach ($this->auditRepo->findByEntity(self::AUDIT_ENTITY_TYPE, $order->getMachineId()) as $event) {
            if (!$this->eventBelongsToOrder($event, $order)) {
                continue;
            }

            $action = $event->getAction();
            $trail[] = [
                'action' => $action,
                'action_label' => self::AUDIT_ACTION_LABELS[$action] ?? $action,
                'created_at' => $event->getCreatedAt(),
                'user_name' => $event->getUserName(),
                'user_role' => $event->getUserRole(),
            ];
        }

        return $trail;
    }

    /**
     * Decides whether a machine-level preventive event belongs to this order.
     *
     * Attribution rules, in order of precision:
     * 1. `metadata.order_code` equals the order code — the direct reference every
     *    order-scoped writer stores.
     * 2. `metadata.order_id` equals the primary key — accepted as an equivalent
     *    direct reference.
     * 3. `EVALUATE_PREVENTIVE_CHECKLIST` and `REINSPECTION_COMPLETED` only carry
     *    `metadata.order_id` as the machine-level conclusion of an inspection,
     *    so they are attributed by it.
     */
    private function eventBelongsToOrder(AuditEvent $event, PreventiveOrder $order): bool
    {
        $metadata = $event->getMetadata();

        if (is_array($metadata)) {
            $orderCode = $metadata['order_code'] ?? null;
            if (is_string($orderCode) && $orderCode === $order->getOrderCode()) {
                return true;
            }

            $orderId = $metadata['order_id'] ?? null;
            if (is_numeric($orderId) && (int)$orderId === $order->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parses a `YYYY-MM-DD` contract date into Madrid midnight, or null when unusable.
     */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $due = DateTimeImmutable::createFromFormat('!Y-m-d', substr($normalized, 0, 10), $this->madridTimezone());

        return $due === false ? null : $due;
    }

    /**
     * Formats a `YYYY-MM-DD HH:MM:SS` timestamp as `DD/MM/YYYY HH:MM` without depending
     * on the host timezone (Dualismo Lingüístico: fechas para la interfaz en español).
     */
    private function formatDateTime(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $value, $matches) !== 1) {
            return $value;
        }

        return "{$matches[3]}/{$matches[2]}/{$matches[1]} {$matches[4]}:{$matches[5]}";
    }

    private function madridTimezone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Madrid');
    }
}
