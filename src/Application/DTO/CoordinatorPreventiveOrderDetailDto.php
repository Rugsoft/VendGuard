<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

/**
 * Immutable consolidated projection of one preventive inspection order for the
 * Coordinación board (module 05, RF-PD-01 to RF-PD-10).
 *
 * This is the read side behind `GET /api/coordinator/preventive/orders/{id}/detail`:
 * the single aggregated payload that lets the fichas modal open with one read
 * transaction instead of a waterfall of requests (RNF-PD-01, render under 300 ms).
 *
 * Each block arrives already composed by its producer:
 *
 * - `PdoPreventiveOrderRepository::findById()` gathers the order with its location,
 *   machine (type and sanitary status) and inspector.
 * - `CoordinatorPreventiveDetailService` derives `validity` (sanitary traffic light,
 *   Art. II), composes `checklist` from `PdoPreventiveItemRepository::findByOrderId()`,
 *   resolves the linked corrective incident (Art. V.2), the latest sanitary
 *   certificate (Art. V.4) and the immutable audit trail of the preventive cycle
 *   (Art. III), composed from the machine events that the module's writers record.
 *
 * The DTO performs no I/O and never writes: visualising the order must not alter a
 * single row (RNF-PD-04). `toArray()` is the contract surface: the nine blocks, in
 * the order published in `specs/technical/preventive_order_detail_contracts.md`.
 */
final readonly class CoordinatorPreventiveOrderDetailDto
{
    /**
     * @param array{
     *   id: int,
     *   order_code: string,
     *   status: string,
     *   status_label: string,
     *   order_type: string,
     *   order_type_label: string,
     *   scheduled_date: string,
     *   due_date: string,
     *   created_at: string|null,
     *   updated_at: string|null,
     *   started_at: string|null,
     *   completed_at: string|null,
     *   temperature_measured: float|null,
     *   result: string|null,
     *   result_label: string|null,
     *   is_quarantine_triggered: bool,
     *   notes: string|null,
     *   cancellation_reason: string|null,
     *   linked_incident_id: int|null
     * } $order Order header and inspection outcome (RF-PD-02, RF-PD-06).
     * @param array{
     *   id: int,
     *   name: string,
     *   site_code: string,
     *   address: string
     * } $location Client site hosting the machine (RF-PD-03).
     * @param array{
     *   id: int,
     *   code: string,
     *   model: string,
     *   machine_type: string,
     *   machine_type_label: string,
     *   floor_wing: string,
     *   has_perishables: bool,
     *   sanitary_status: string,
     *   sanitary_status_label: string
     * } $machine Machine context with the sanitary indicator (RF-PD-03, Art. II).
     * @param array{
     *   assigned: bool,
     *   id: int|null,
     *   name: string|null,
     *   operator_code: string|null
     * } $technician Inspector identified by name and official operator code (RF-PD-04, Art. V.4).
     * @param array{
     *   state: string,
     *   state_label: string,
     *   days_remaining: int|null,
     *   is_overdue: bool,
     *   valid_until: string,
     *   balance_label: string|null
     * } $validity Sanitary validity traffic light, active or historical (RF-PD-03.3).
     * @param array{
     *   has_checklist: bool,
     *   totals: array{
     *     total: int,
     *     pass: int,
     *     warn: int,
     *     fail: int,
     *     not_applicable: int,
     *     critical_failures: int
     *   },
     *   compliance_percent: int,
     *   items: list<array{
     *     item_code: string,
     *     item_description: string,
     *     is_critical: bool,
     *     status: string,
     *     status_label: string,
     *     observations: string|null,
     *     photo_url: string|null
     *   }>
     * } $checklist Normative checklist answers (RF-PD-05).
     * @param array{
     *   id: int,
     *   ticket_code: string,
     *   status: string,
     *   status_label: string,
     *   urgency: string,
     *   urgency_label: string,
     *   created_at: string|null
     * }|null $linkedIncident Corrective incident opened from this inspection (RF-PD-07, Art. V.2).
     * @param array{
     *   certificate_code: string,
     *   inspection_date: string,
     *   valid_until: string,
     *   temperature_measured: float|null,
     *   result: string,
     *   result_label: string,
     *   status: string,
     *   status_label: string,
     *   inspector: array{name: string, operator_code: string}
     * }|null $certificate Latest sanitary certificate of the machine (RF-PD-08, Art. V.4).
     * @param list<array{
     *   action: string,
     *   action_label: string,
     *   created_at: string,
     *   user_name: string,
     *   user_role: string
     * }> $auditTrail Chronological immutable audit events (RF-PD-09, Art. III).
     */
    public function __construct(
        public array $order,
        public array $location,
        public array $machine,
        public array $technician,
        public array $validity,
        public array $checklist,
        public ?array $linkedIncident,
        public ?array $certificate,
        public array $auditTrail
    ) {
    }

    /**
     * Contract payload of the preventive detail endpoint, block by block.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'location' => $this->location,
            'machine' => $this->machine,
            'technician' => $this->technician,
            'validity' => $this->validity,
            'checklist' => $this->checklist,
            'linked_incident' => $this->linkedIncident,
            'certificate' => $this->certificate,
            'audit_trail' => $this->auditTrail,
        ];
    }
}
