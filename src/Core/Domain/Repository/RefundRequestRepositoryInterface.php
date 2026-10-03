<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;

/**
 * Persistence contract for consumer refund cases.
 *
 * Reads come in two flavours on purpose. The full projection carries the IBAN
 * and the Bizum phone and is reserved for Coordination; every method meant for
 * the site desk or the field technician returns a restricted projection that
 * omits those columns in SQL, so the segregation of Art. V.4 is enforced by the
 * query itself rather than by remembering to strip fields afterwards.
 */
interface RefundRequestRepositoryInterface
{
    /**
     * Persists a new refund case and returns its generated identifier.
     */
    public function insert(RefundRequest $refundRequest): int;

    /**
     * Retrieves a case with full financial detail. Coordination only.
     */
    public function findById(int $id): ?RefundRequest;

    /**
     * Retrieves a case by its public tracking token, without financial data.
     *
     * Used by the anonymous tracking page, which must never expose the IBAN or
     * the Bizum phone to whoever holds the link.
     */
    public function findByTrackingToken(string $trackingToken): ?RefundRequest;

    /**
     * Lists the cases attached to an incident, without financial data.
     */
    public function findRestrictedByIncident(int $incidentId): array;

    /**
     * Lists the cases of a site, without financial data.
     */
    public function findRestrictedByLocation(int $locationId, ?RefundStatus $status = null): array;

    /**
     * Lists cases for the coordination inbox with financial detail.
     *
     * @param array<string, mixed> $filters Optional filters: `status`,
     *   `location_id`, `machine_id`, `from`, `to`.
     * @param int $limit Maximum number of rows to return.
     * @param int $offset Rows to skip for pagination.
     * @return list<RefundRequest>
     */
    public function findForCoordinator(array $filters = [], int $limit = 50, int $offset = 0): array;

    /**
     * Counts the cases matching the coordination filters.
     *
     * @param array<string, mixed> $filters Same filters as findForCoordinator.
     */
    public function countForCoordinator(array $filters = []): int;

    /**
     * Moves a case to a new state only if it is still in the expected one.
     *
     * The conditional WHERE clause makes the update atomic: two concurrent
     * requests cannot both win, so a case can never be paid twice or delivered
     * and paid at the same time.
     *
     * @param array<string, mixed> $fields Columns to write alongside the status.
     * @return bool True when the transition was applied.
     */
    public function transitionStatus(
        int $id,
        RefundStatus $expectedStatus,
        RefundStatus $newStatus,
        array $fields = []
    ): bool;

    /**
     * Replaces the claimant payment details after a contact rectification.
     *
     * @param string|null $bizumPhone New Bizum phone, or null to keep it.
     * @param string|null $iban New IBAN, or null to keep it.
     */
    public function updateContactDetails(int $id, ?string $bizumPhone, ?string $iban): bool;

    /**
     * Logically cancels a case. Rows are never physically deleted (Art. III).
     */
    public function deactivate(int $id): bool;
}
