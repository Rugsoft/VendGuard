<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;

/**
 * NotificationOutboxRepositoryInterface
 *
 * Persistence port of the transactional notification outbox (Table 21).
 *
 * The contract separates the four responsibilities the background dispatcher
 * depends on (RF-01.3, RF-01.4, RF-06.3 and RF-07.1):
 *
 *   1. Idempotent enqueue, including burst aggregation of site replies.
 *   2. Atomic rescue of rows stranded in `PROCESSING` for more than five minutes.
 *   3. Batch claiming with `FOR UPDATE SKIP LOCKED`, sanitary priority first.
 *   4. Supervised queries, KPI counters and logical cancellation.
 *
 * No method physically deletes a row: Art. III.1 forbids it and the database
 * trigger enforces it.
 */
interface NotificationOutboxRepositoryInterface
{
    /**
     * Enqueues a notification idempotently.
     *
     * A non-null `dedup_key` that already exists is a no-op. For
     * `PENDING_INFO_SITE_REPLY`, an existing `PENDING` row for the same
     * `(incident_id, template, recipient_user_id)` is updated in place instead
     * of inserting a duplicate email (RF-05.4).
     *
     * @return NotificationOutboxItem|null The persisted row, or null when the
     *   deduplication key already existed.
     */
    public function enqueue(NotificationOutboxItem $item): ?NotificationOutboxItem;

    /**
     * Retrieves a single outbox row by its identifier.
     */
    public function findById(int $id): ?NotificationOutboxItem;

    /**
     * Persists the mutable state of an enqueued row (status, attempts, error,
     * schedule and payload). Returns false when the row does not exist.
     */
    public function save(NotificationOutboxItem $item): bool;

    /**
     * Claims the next ready batch and marks it as `PROCESSING`.
     *
     * Rows are ordered by `CRITICAL_SANITARY` first and then by
     * `next_attempt_at`/`id`. `FOR UPDATE SKIP LOCKED` lets concurrent
     * dispatchers take disjoint batches without waiting for each other
     * (RF-01.3, RNF-06).
     *
     * @param int $limit Maximum number of rows to claim.
     * @return list<NotificationOutboxItem>
     */
    public function claimReadyBatch(int $limit = 50): array;

    /**
     * Rescues rows stuck in `PROCESSING` beyond the stale window and moves them
     * to `PENDING_RETRY`, counting the interrupted attempt. A row that reaches
     * its fifth attempt becomes `FAILED_PERMANENT` (RF-01.4).
     *
     * @param int $staleMinutes Minutes after which a `PROCESSING` row is stale.
     * @param DateTimeImmutable|null $now Reference instant (tests inject it).
     * @return int Number of rescued rows.
     */
    public function rescueStuckProcessingItems(int $staleMinutes = 5, ?DateTimeImmutable $now = null): int;

    /**
     * Lists the outbox for the coordination panel.
     *
     * Supported filters: `status`, `channel`, `priority` and `ticket_code`
     * (partial or exact). Returns raw rows enriched with `ticket_code` so the
     * controller can render the supervision table without an extra query.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function findForCoordinator(array $filters = [], int $limit = 50, int $offset = 0): array;

    /**
     * Counts the rows matching the coordination filters.
     *
     * @param array<string, mixed> $filters Same filters as findForCoordinator.
     */
    public function countForCoordinator(array $filters = []): int;

    /**
     * KPI counters for the six canonical states plus the operational summary
     * (RF-06.3).
     *
     * @return array{
     *   PENDING:int, PROCESSING:int, PENDING_RETRY:int, SENT:int,
     *   FAILED_PERMANENT:int, CANCELLED_OBSOLETE:int,
     *   in_queue:int, sent_last_24h:int
     * }
     */
    public function countByStatus(?DateTimeImmutable $now = null): array;

    /**
     * True when the incident already has a non-deleted row of the given
     * template, whatever its dispatch status.
     *
     * The enqueuer uses this fingerprint —never `reporter_email`— to decide
     * whether an expediente originated in the citizen QR channel and therefore
     * may receive the restricted public projection (RF-02.4, RF-05.9, Art. V.4).
     */
    public function hasTemplateForIncident(int $incidentId, string $template): bool;

    /**
     * Cancels by obsolescence every pending row of an incident (RF-05.8,
     * RF-07.1). The cancellation notice of the incident itself can be preserved
     * so the closure email still leaves.
     *
     * @param list<string> $preserveTemplates Templates that must survive.
     * @return int Number of cancelled rows.
     */
    public function cancelPendingForIncident(
        int $incidentId,
        string $reason,
        array $preserveTemplates = []
    ): int;

    /**
     * Cancels by obsolescence every pending row addressed to an internal user
     * that has been deactivated or replaced (RF-07.1.f).
     *
     * @return int Number of cancelled rows.
     */
    public function cancelPendingForRecipientUser(int $userId, string $reason): int;

    /**
     * Cancels by obsolescence every pending Webhook row after the corporate
     * channel is disabled (RF-07.1.f).
     *
     * @return int Number of cancelled rows.
     */
    public function cancelPendingWebhookItems(string $reason): int;
}
