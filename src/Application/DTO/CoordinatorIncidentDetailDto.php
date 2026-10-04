<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

/**
 * Immutable consolidated projection of one incident file for the Coordinación triage board.
 *
 * This is the read side of module 09 (`specs/09-incident-detail-modal`): the single
 * enriched payload behind `GET /api/coordinator/incidents/{id}/detail` (technical plan
 * §2.1), shaped so the modal opens with one read transaction instead of a waterfall of
 * requests (RNF-01, render under 300 ms).
 *
 * Each block arrives already composed by its producer:
 *
 * - `PdoIncidentRepository::findEnrichedDetailById()` (T-IDM-02) gathers the raw file:
 *   incident, location, machine, technician, timeline, comments and the linked refund.
 * - `CoordinatorIncidentDetailService` (T-IDM-03) derives `sla` (active countdown or
 *   formal historical balance, Art. II), masks the refund contact and payment data
 *   server-side (Art. V.4) and computes `permissions` from the state machine.
 *
 * The DTO performs no I/O and never writes: visualising the modal must not alter a single
 * row (Art. III, RNF-04). An incident file is read as a whole, so its sections stay
 * together as ten typed blocks instead of one class per sub-section that only this
 * endpoint would ever consume. `toArray()` is the contract surface: the ten blocks, in
 * the order published in the plan.
 */
final readonly class CoordinatorIncidentDetailDto
{
    /**
     * @param array{
     *   id: int,
     *   ticket_code: string,
     *   status: string,
     *   status_label: string,
     *   urgency: string,
     *   urgency_label: string,
     *   is_reopened: bool,
     *   reopened_at: string|null,
     *   reopened_reason: string|null,
     *   description: string,
     *   report_channel: string,
     *   photo_url: string|null,
     *   created_at: string,
     *   updated_at: string
     * } $incident Header data of the ticket (RF-02).
     * @param array{
     *   id: int,
     *   name: string,
     *   code: string,
     *   address: string|null,
     *   floor_zone: string|null,
     *   has_physical_reception: bool
     * } $location Client site where the machine lives (RF-02).
     * @param array{
     *   id: int,
     *   code: string,
     *   model: string,
     *   manufacturer: string,
     *   type: string,
     *   type_label: string,
     *   has_perishables: bool
     * } $machine Machine metadata, including the sanitary indicator (RF-02, Art. II).
     * @param array{
     *   assigned: bool,
     *   technician_id: int|null,
     *   name: string|null,
     *   operator_code: string|null,
     *   assigned_at: string|null,
     *   assigned_by_name: string|null,
     *   reassignment_reason: string|null
     * } $technician Active technician assignment and reassignment motive (RF-04, Art. V.3).
     * @param array{
     *   created_at: string,
     *   assigned_at: string|null,
     *   started_at: string|null,
     *   paused_at: string|null,
     *   resolved_at: string|null,
     *   closed_at: string|null,
     *   time_to_assign_minutes: int|null,
     *   time_to_first_response_minutes: int|null,
     *   total_elapsed_minutes: int
     * } $timeline Life-cycle milestones with elapsed times (RF-03).
     * @param array{
     *   has_sla_limit: bool,
     *   sla_limit_hours?: float,
     *   is_active_countdown?: bool,
     *   is_breached?: bool,
     *   minutes_remaining?: int,
     *   historical_balance?: string,
     *   sla_target_at?: string|null
     * } $sla Cold-chain SLA evaluation, active or historical (RF-03, Art. II).
     * @param array{
     *   pause: array{
     *     is_paused: bool,
     *     reason: string|null,
     *     requested_parts: list<array{
     *       spare_part_id: int|null,
     *       part_code: string,
     *       description: string,
     *       quantity: int,
     *       is_out_of_catalog: bool,
     *       justification: string|null
     *     }>
     *   },
     *   resolution: array{
     *     is_resolved: bool,
     *     diagnosis: string|null,
     *     corrective_action: string|null,
     *     replaced_parts_declared: bool,
     *     replaced_parts: list<array{
     *       spare_part_id: int|null,
     *       part_code: string,
     *       description: string,
     *       quantity: int,
     *       is_out_of_catalog: bool,
     *       unit_cost_snapshot: float,
     *       total_cost_snapshot: float,
     *       old_part_destination: string,
     *       destination_label: string,
     *       notes: string|null
     *     }>,
     *     total_parts_cost: float
     *   },
     *   cancellation: array{
     *     is_cancelled: bool,
     *     cancelled_at: string|null,
     *     cancelled_by_name: string|null,
     *     reason: string|null
     *   }
     * } $technicalIntervention Pause, resolution with frozen costs, and justified discard (RF-04).
     * @param list<array{
     *   id: int,
     *   author_type: string,
     *   author_name: string,
     *   comment_text: string,
     *   is_internal: bool,
     *   created_at: string
     * }> $comments Chronological log mixing public comments and internal workshop notes (RF-05).
     * @param array{
     *   has_refund: bool,
     *   refund_id: int|null,
     *   claim_code: string|null,
     *   amount: float|null,
     *   compensation_method: string|null,
     *   compensation_method_label: string|null,
     *   status: string|null,
     *   status_label: string|null,
     *   contact_phone_masked: string|null,
     *   iban_masked: string|null,
     *   technician_finding: string|null,
     *   cash_custody_action: string|null,
     *   technician_notes: string|null,
     *   refund_tab_url: string|null
     * } $refund Linked refund case with masked contact and payment data (RF-06, Art. V.4).
     * @param array{
     *   can_assign: bool,
     *   can_reassign: bool,
     *   can_cancel: bool,
     *   can_add_comment: bool
     * } $permissions Direct actions allowed by the current state machine (RF-07).
     */
    public function __construct(
        public array $incident,
        public array $location,
        public array $machine,
        public array $technician,
        public array $timeline,
        public array $sla,
        public array $technicalIntervention,
        public array $comments,
        public array $refund,
        public array $permissions
    ) {
    }

    /**
     * Contract payload of the enriched detail endpoint, block by block.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'incident' => $this->incident,
            'location' => $this->location,
            'machine' => $this->machine,
            'technician' => $this->technician,
            'timeline' => $this->timeline,
            'sla' => $this->sla,
            'technical_intervention' => $this->technicalIntervention,
            'comments' => $this->comments,
            'refund' => $this->refund,
            'permissions' => $this->permissions,
        ];
    }
}
