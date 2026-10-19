<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\Repository\NotificationOutboxRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

/**
 * PdoNotificationOutboxRepository
 *
 * Repositorio nativo (Dogma Vanilla) de la bandeja de salida transaccional
 * (Tabla 21 · módulo 14).
 *
 * Decisiones que sostienen los requisitos:
 *
 *   - **Encolado idempotente (RF-05.4, RNF-06).** El `dedup_key` viaja con
 *     `ON CONFLICT ... DO NOTHING` y las ráfagas de `PENDING_INFO_SITE_REPLY`
 *     se serializan con un cerrojo de asesoría transaccional antes de buscar la
 *     fila `PENDING`, de modo que dos comentarios simultáneos no insertan dos
 *     correos.
 *   - **Rescate atómico (RF-01.4).** Una sola sentencia mueve TODAS las filas
 *     atrapadas en `PROCESSING` más de cinco minutos; el intento interrumpido
 *     se contabiliza y el quinto intento cae directamente en
 *     `FAILED_PERMANENT`.
 *   - **Reclamo sin esperas (RF-01.3, RNF-06).** `FOR UPDATE SKIP LOCKED`
 *     permite que dos despachadores solapados tomen lotes disjuntos, y el
 *     `ORDER BY` antepone siempre `CRITICAL_SANITARY` (Art. II).
 *   - **Sin borrado físico (Art. III.1).** Toda baja es lógica; el disparador
 *     `prevent_hard_delete()` del motor sigue siendo la última línea.
 */
class PdoNotificationOutboxRepository implements NotificationOutboxRepositoryInterface
{
    private const CANCELLABLE_STATUSES = ['PENDING', 'PENDING_RETRY'];

    private const RESCUE_ERROR = 'Tiempo de procesamiento agotado en tránsito: rescate automático tras más de %d minutos.';

    private PDO $pdo;

    private SqlDialectAdapter $dialect;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->dialect = SqlDialectAdapter::forConnection($this->pdo);
    }

    /**
     * {@inheritDoc}
     */
    public function enqueue(NotificationOutboxItem $item): ?NotificationOutboxItem
    {
        if ($item->getId() !== null) {
            throw new InvalidArgumentException('Una notificación nueva no puede traer identificador: usa save() para actualizarla.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            if ($this->isSiteReplyBurst($item)) {
                // El cerrojo se toma ANTES de buscar la fila PENDING: si dos
                // comentarios de sede llegan a la vez, el segundo espera al
                // primero y encuentra la fila que éste acaba de crear/actualizar.
                $this->acquireBurstLock((int)$item->getIncidentId(), (int)$item->getRecipientUserId());

                $existing = $this->findPendingBurstRow(
                    (int)$item->getIncidentId(),
                    (int)$item->getRecipientUserId()
                );

                if ($existing !== null) {
                    $updated = $this->updateBurstRow($existing['id'], $item);
                    if ($ownsTransaction) {
                        $this->pdo->commit();
                    }

                    return $updated;
                }
            }

            $persisted = $this->insertItem($item);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $persisted;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?NotificationOutboxItem
    {
        $sql = "
            SELECT *
            FROM notification_outbox
            WHERE id = :id
              AND deleted_at IS NULL
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : NotificationOutboxItem::fromDatabaseRow($row);
    }

    /**
     * {@inheritDoc}
     */
    public function hasTemplateForIncident(int $incidentId, string $template): bool
    {
        $sql = "
            SELECT 1
            FROM notification_outbox
            WHERE incident_id = :incident_id
              AND template = :template
              AND deleted_at IS NULL
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        $stmt->bindValue(':template', strtoupper(trim($template)), PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * {@inheritDoc}
     */
    public function save(NotificationOutboxItem $item): bool
    {
        if ($item->getId() === null) {
            return false;
        }

        $sql = "
            UPDATE notification_outbox SET
                recipient = :recipient,
                priority = :priority,
                subject = :subject,
                payload_json = :payload_json,
                status = :status,
                attempts = :attempts,
                max_attempts = :max_attempts,
                next_attempt_at = :next_attempt_at,
                processing_started_at = :processing_started_at,
                processed_at = :processed_at,
                last_error = :last_error,
                obsolete_reason = :obsolete_reason,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND deleted_at IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', (int)$item->getId(), PDO::PARAM_INT);
        $stmt->bindValue(':recipient', $item->getRecipient(), PDO::PARAM_STR);
        $stmt->bindValue(':priority', $item->getPriority()->value, PDO::PARAM_STR);
        $stmt->bindValue(':subject', $item->getSubject(), PDO::PARAM_STR);
        $stmt->bindValue(':payload_json', $this->encodePayload($item), PDO::PARAM_STR);
        $stmt->bindValue(':status', $item->getStatus()->value, PDO::PARAM_STR);
        $stmt->bindValue(':attempts', $item->getAttempts(), PDO::PARAM_INT);
        $stmt->bindValue(':max_attempts', $item->getMaxAttempts(), PDO::PARAM_INT);
        $stmt->bindValue(':next_attempt_at', $this->formatDate($item->getNextAttemptAt()), PDO::PARAM_STR);
        $this->bindNullableDate($stmt, ':processing_started_at', $item->getProcessingStartedAt());
        $this->bindNullableDate($stmt, ':processed_at', $item->getProcessedAt());
        $this->bindNullableString($stmt, ':last_error', $item->getLastError());
        $this->bindNullableString($stmt, ':obsolete_reason', $item->getObsoleteReason());

        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function claimReadyBatch(int $limit = 50): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('El tamaño del lote debe ser al menos 1.');
        }

        $now = $this->now();

        // El CTE bloquea únicamente las filas candidatas y `SKIP LOCKED` deja
        // fuera las que otro despachador ya tiene tomadas: dos procesos
        // solapados reparten la cola en lugar de esperarse.
        $sql = "
            WITH claimed AS (
                SELECT id
                FROM notification_outbox
                WHERE status IN ('PENDING', 'PENDING_RETRY')
                  AND next_attempt_at <= :ready_at
                  AND deleted_at IS NULL
                ORDER BY
                    CASE WHEN priority = 'CRITICAL_SANITARY' THEN 0 ELSE 1 END ASC,
                    next_attempt_at ASC,
                    id ASC
                LIMIT :batch_limit
                FOR UPDATE SKIP LOCKED
            )
            UPDATE notification_outbox o
            SET status = 'PROCESSING',
                processing_started_at = :processing_at,
                updated_at = CURRENT_TIMESTAMP
            FROM claimed c
            WHERE o.id = c.id
            RETURNING o.*
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':ready_at', $this->formatDate($now), PDO::PARAM_STR);
        $stmt->bindValue(':processing_at', $this->formatDate($now), PDO::PARAM_STR);
        $stmt->bindValue(':batch_limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map(
            static fn(array $row): NotificationOutboxItem => NotificationOutboxItem::fromDatabaseRow($row),
            $rows
        );

        // El orden de `RETURNING` no está garantizado por el motor: el `ORDER BY`
        // del CTE decide QUÉ filas entran en el lote, no el orden en que salen.
        // Se restituye aquí el contrato de prioridad para que el despachador
        // entregue siempre primero la emergencia sanitaria (Art. II).
        usort($items, static function (NotificationOutboxItem $left, NotificationOutboxItem $right): int {
            $leftCritical = $left->getPriority()->isCritical();
            $rightCritical = $right->getPriority()->isCritical();

            if ($leftCritical !== $rightCritical) {
                return $leftCritical ? -1 : 1;
            }

            $bySchedule = $left->getNextAttemptAt() <=> $right->getNextAttemptAt();

            return $bySchedule !== 0 ? $bySchedule : ((int)$left->getId() <=> (int)$right->getId());
        });

        return $items;
    }

    /**
     * {@inheritDoc}
     */
    public function rescueStuckProcessingItems(int $staleMinutes = 5, ?DateTimeImmutable $now = null): int
    {
        if ($staleMinutes < 1) {
            throw new InvalidArgumentException('La ventana de rescate debe ser de al menos un minuto.');
        }

        $reference = $now ?? $this->now();
        $cutoff = $reference->modify(sprintf('-%d minutes', $staleMinutes));

        $sql = "
            UPDATE notification_outbox
            SET status = CASE
                    WHEN attempts + 1 >= max_attempts THEN 'FAILED_PERMANENT'::notification_status
                    ELSE 'PENDING_RETRY'::notification_status
                END,
                attempts = attempts + 1,
                last_error = :rescue_error,
                processing_started_at = NULL,
                processed_at = CASE
                    WHEN attempts + 1 >= max_attempts THEN :failed_at
                    ELSE processed_at
                END,
                next_attempt_at = CASE
                    WHEN attempts + 1 >= max_attempts THEN next_attempt_at
                    ELSE :retry_at
                END,
                updated_at = CURRENT_TIMESTAMP
            WHERE status = 'PROCESSING'
              AND processing_started_at IS NOT NULL
              AND processing_started_at < :cutoff
              AND deleted_at IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':rescue_error', sprintf(self::RESCUE_ERROR, $staleMinutes), PDO::PARAM_STR);
        $stmt->bindValue(':failed_at', $this->formatDate($reference), PDO::PARAM_STR);
        $stmt->bindValue(':retry_at', $this->formatDate($reference), PDO::PARAM_STR);
        $stmt->bindValue(':cutoff', $this->formatDate($cutoff), PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * {@inheritDoc}
     */
    public function findForCoordinator(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $params = [];
        $where = $this->coordinatorWhere($filters, $params);

        $sql = "
            SELECT
                o.*,
                i.ticket_code
            FROM notification_outbox o
            LEFT JOIN incidents i ON i.id = o.incident_id
            WHERE {$where}
              AND o.deleted_at IS NULL
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT :page_limit OFFSET :page_offset
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value['value'], $value['type']);
        }
        $stmt->bindValue(':page_limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':page_offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * {@inheritDoc}
     */
    public function countForCoordinator(array $filters = []): int
    {
        $params = [];
        $where = $this->coordinatorWhere($filters, $params);

        $sql = "
            SELECT COUNT(*) AS total
            FROM notification_outbox o
            LEFT JOIN incidents i ON i.id = o.incident_id
            WHERE {$where}
              AND o.deleted_at IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value['value'], $value['type']);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * {@inheritDoc}
     */
    public function countByStatus(?DateTimeImmutable $now = null): array
    {
        $reference = $now ?? $this->now();

        $sql = "
            SELECT status, COUNT(*) AS total
            FROM notification_outbox
            WHERE deleted_at IS NULL
            GROUP BY status
        ";

        $counts = [
            NotificationStatus::PENDING->value => 0,
            NotificationStatus::PROCESSING->value => 0,
            NotificationStatus::PENDING_RETRY->value => 0,
            NotificationStatus::SENT->value => 0,
            NotificationStatus::FAILED_PERMANENT->value => 0,
            NotificationStatus::CANCELLED_OBSOLETE->value => 0,
        ];

        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string)$row['status'];
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int)$row['total'];
            }
        }

        $sentStmt = $this->pdo->prepare("
            SELECT COUNT(*) AS total
            FROM notification_outbox
            WHERE status = 'SENT'
              AND processed_at >= :cutoff
              AND deleted_at IS NULL
        ");
        $sentStmt->bindValue(':cutoff', $this->formatDate($reference->modify('-24 hours')), PDO::PARAM_STR);
        $sentStmt->execute();

        return [
            'PENDING' => $counts[NotificationStatus::PENDING->value],
            'PROCESSING' => $counts[NotificationStatus::PROCESSING->value],
            'PENDING_RETRY' => $counts[NotificationStatus::PENDING_RETRY->value],
            'SENT' => $counts[NotificationStatus::SENT->value],
            'FAILED_PERMANENT' => $counts[NotificationStatus::FAILED_PERMANENT->value],
            'CANCELLED_OBSOLETE' => $counts[NotificationStatus::CANCELLED_OBSOLETE->value],
            'in_queue' => $counts[NotificationStatus::PENDING->value]
                + $counts[NotificationStatus::PROCESSING->value]
                + $counts[NotificationStatus::PENDING_RETRY->value],
            'sent_last_24h' => (int)$sentStmt->fetchColumn(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function cancelPendingForIncident(
        int $incidentId,
        string $reason,
        array $preserveTemplates = []
    ): int {
        $params = [
            ':incident_id' => [$incidentId, PDO::PARAM_INT],
            ':reason' => [$reason, PDO::PARAM_STR],
            ':cancelled_at' => [$this->formatDate($this->now()), PDO::PARAM_STR],
        ];

        $preserve = '';
        foreach (array_values($preserveTemplates) as $index => $template) {
            NotificationTemplate::fromString($template); // valida el catálogo cerrado
            $preserve .= sprintf(' AND template <> :preserve_%d', $index);
            $params[':preserve_' . $index] = [$template, PDO::PARAM_STR];
        }

        $sql = "
            UPDATE notification_outbox
            SET status = 'CANCELLED_OBSOLETE',
                obsolete_reason = :reason,
                processed_at = :cancelled_at,
                processing_started_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE incident_id = :incident_id
              AND status IN ('PENDING', 'PENDING_RETRY')
              AND deleted_at IS NULL
              {$preserve}
        ";

        return $this->executeCancellation($sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    public function cancelPendingForRecipientUser(int $userId, string $reason): int
    {
        $sql = "
            UPDATE notification_outbox
            SET status = 'CANCELLED_OBSOLETE',
                obsolete_reason = :reason,
                processed_at = :cancelled_at,
                processing_started_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE recipient_user_id = :user_id
              AND status IN ('PENDING', 'PENDING_RETRY')
              AND deleted_at IS NULL
        ";

        return $this->executeCancellation($sql, [
            ':user_id' => [$userId, PDO::PARAM_INT],
            ':reason' => [$reason, PDO::PARAM_STR],
            ':cancelled_at' => [$this->formatDate($this->now()), PDO::PARAM_STR],
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function cancelPendingForIncidentRecipient(
        int $incidentId,
        int $recipientUserId,
        string $reason,
        array $templates = []
    ): int {
        $params = [
            ':incident_id' => [$incidentId, PDO::PARAM_INT],
            ':recipient_user_id' => [$recipientUserId, PDO::PARAM_INT],
            ':reason' => [$reason, PDO::PARAM_STR],
            ':cancelled_at' => [$this->formatDate($this->now()), PDO::PARAM_STR],
        ];

        $restriction = '';
        foreach (array_values($templates) as $index => $template) {
            NotificationTemplate::fromString($template); // valida el catálogo cerrado
            $restriction .= sprintf(' AND template = :template_%d', $index);
            $params[':template_' . $index] = [$template, PDO::PARAM_STR];
        }

        $sql = "
            UPDATE notification_outbox
            SET status = 'CANCELLED_OBSOLETE',
                obsolete_reason = :reason,
                processed_at = :cancelled_at,
                processing_started_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE incident_id = :incident_id
              AND recipient_user_id = :recipient_user_id
              AND status IN ('PENDING', 'PENDING_RETRY')
              AND deleted_at IS NULL
              {$restriction}
        ";

        return $this->executeCancellation($sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    public function cancelPendingWebhookItems(string $reason): int
    {
        $sql = "
            UPDATE notification_outbox
            SET status = 'CANCELLED_OBSOLETE',
                obsolete_reason = :reason,
                processed_at = :cancelled_at,
                processing_started_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE channel = 'WEBHOOK'
              AND status IN ('PENDING', 'PENDING_RETRY')
              AND deleted_at IS NULL
        ";

        return $this->executeCancellation($sql, [
            ':reason' => [$reason, PDO::PARAM_STR],
            ':cancelled_at' => [$this->formatDate($this->now()), PDO::PARAM_STR],
        ]);
    }

    /**
     * @param array<string, array{0: mixed, 1: int}> $params
     */
    private function executeCancellation(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => [$value, $type]) {
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function isSiteReplyBurst(NotificationOutboxItem $item): bool
    {
        return $item->getTemplate() === NotificationTemplate::PENDING_INFO_SITE_REPLY
            && $item->getIncidentId() !== null
            && $item->getRecipientUserId() !== null;
    }

    private function acquireBurstLock(int $incidentId, int $recipientUserId): void
    {
        if (!$this->dialect->isPostgres()) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
        $stmt->bindValue(':lock_key', sprintf('notif:burst:%d:%d', $incidentId, $recipientUserId), PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPendingBurstRow(int $incidentId, int $recipientUserId): ?array
    {
        $sql = "
            SELECT *
            FROM notification_outbox
            WHERE incident_id = :incident_id
              AND template = 'PENDING_INFO_SITE_REPLY'
              AND recipient_user_id = :recipient_user_id
              AND status = 'PENDING'
              AND deleted_at IS NULL
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':incident_id', $incidentId, PDO::PARAM_INT);
        $stmt->bindValue(':recipient_user_id', $recipientUserId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Actualiza la fila `PENDING` de la ráfaga con el último texto de la sede.
     */
    private function updateBurstRow(int $id, NotificationOutboxItem $item): NotificationOutboxItem
    {
        $sql = "
            UPDATE notification_outbox
            SET payload_json = :payload_json,
                subject = :subject,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            RETURNING *
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':payload_json', $this->encodePayload($item), PDO::PARAM_STR);
        $stmt->bindValue(':subject', $item->getSubject(), PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException('No se pudo actualizar la notificación agrupada de respuesta de sede.');
        }

        return NotificationOutboxItem::fromDatabaseRow($row);
    }

    private function insertItem(NotificationOutboxItem $item): ?NotificationOutboxItem
    {
        $conflictClause = $item->getDedupKey() !== null
            ? $this->dialect->upsertDoNothing(['dedup_key'])
            : '';

        $sql = "
            INSERT INTO notification_outbox (
                incident_id,
                channel,
                priority,
                template,
                recipient,
                recipient_role,
                recipient_user_id,
                recipient_location_id,
                subject,
                payload_json,
                dedup_key,
                status,
                attempts,
                max_attempts,
                next_attempt_at,
                processing_started_at,
                processed_at,
                last_error,
                obsolete_reason
            ) VALUES (
                :incident_id,
                :channel,
                :priority,
                :template,
                :recipient,
                :recipient_role,
                :recipient_user_id,
                :recipient_location_id,
                :subject,
                :payload_json,
                :dedup_key,
                :status,
                :attempts,
                :max_attempts,
                :next_attempt_at,
                :processing_started_at,
                :processed_at,
                :last_error,
                :obsolete_reason
            )
            {$conflictClause}
            RETURNING *
        ";

        $stmt = $this->pdo->prepare($sql);
        $this->bindNullableInt($stmt, ':incident_id', $item->getIncidentId());
        $stmt->bindValue(':channel', $item->getChannel()->value, PDO::PARAM_STR);
        $stmt->bindValue(':priority', $item->getPriority()->value, PDO::PARAM_STR);
        $stmt->bindValue(':template', $item->getTemplate()->value, PDO::PARAM_STR);
        $stmt->bindValue(':recipient', $item->getRecipient(), PDO::PARAM_STR);
        $stmt->bindValue(':recipient_role', $item->getRecipientRole(), PDO::PARAM_STR);
        $this->bindNullableInt($stmt, ':recipient_user_id', $item->getRecipientUserId());
        $this->bindNullableInt($stmt, ':recipient_location_id', $item->getRecipientLocationId());
        $stmt->bindValue(':subject', $item->getSubject(), PDO::PARAM_STR);
        $stmt->bindValue(':payload_json', $this->encodePayload($item), PDO::PARAM_STR);
        $this->bindNullableString($stmt, ':dedup_key', $item->getDedupKey());
        $stmt->bindValue(':status', $item->getStatus()->value, PDO::PARAM_STR);
        $stmt->bindValue(':attempts', $item->getAttempts(), PDO::PARAM_INT);
        $stmt->bindValue(':max_attempts', $item->getMaxAttempts(), PDO::PARAM_INT);
        $stmt->bindValue(':next_attempt_at', $this->formatDate($item->getNextAttemptAt()), PDO::PARAM_STR);
        $this->bindNullableDate($stmt, ':processing_started_at', $item->getProcessingStartedAt());
        $this->bindNullableDate($stmt, ':processed_at', $item->getProcessedAt());
        $this->bindNullableString($stmt, ':last_error', $item->getLastError());
        $this->bindNullableString($stmt, ':obsolete_reason', $item->getObsoleteReason());
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : NotificationOutboxItem::fromDatabaseRow($row);
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<string, array{value: mixed, type: int}> $params
     */
    private function coordinatorWhere(array $filters, array &$params): string
    {
        $clauses = ['1 = 1'];

        $status = $this->filterValue($filters, 'status');
        if ($status !== null && strtoupper($status) !== 'ALL') {
            $clauses[] = 'o.status = :status';
            $params[':status'] = [
                'value' => NotificationStatus::fromString($status)->value,
                'type' => PDO::PARAM_STR,
            ];
        }

        $channel = $this->filterValue($filters, 'channel');
        if ($channel !== null && strtoupper($channel) !== 'ALL') {
            $clauses[] = 'o.channel = :channel';
            $params[':channel'] = [
                'value' => \VendGuard\Core\Domain\ValueObject\NotificationChannel::fromString($channel)->value,
                'type' => PDO::PARAM_STR,
            ];
        }

        $priority = $this->filterValue($filters, 'priority');
        if ($priority !== null && strtoupper($priority) !== 'ALL') {
            $clauses[] = 'o.priority = :priority';
            $params[':priority'] = [
                'value' => NotificationPriority::fromString($priority)->value,
                'type' => PDO::PARAM_STR,
            ];
        }

        $ticket = $this->filterValue($filters, 'ticket_code');
        if ($ticket !== null) {
            $clauses[] = 'UPPER(i.ticket_code) LIKE :ticket_code';
            $params[':ticket_code'] = [
                'value' => '%' . strtoupper($ticket) . '%',
                'type' => PDO::PARAM_STR,
            ];
        }

        return implode(' AND ', $clauses);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function filterValue(array $filters, string $key): ?string
    {
        if (!array_key_exists($key, $filters) || $filters[$key] === null) {
            return null;
        }

        $value = trim((string)$filters[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodePayload(NotificationOutboxItem $item): string
    {
        $encoded = json_encode($item->getPayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new InvalidArgumentException('El payload de la notificación no se pudo serializar a JSON.');
        }

        return $encoded;
    }

    private function bindNullableInt(PDOStatement $stmt, string $name, ?int $value): void
    {
        $stmt->bindValue($name, $value, $value !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    }

    private function bindNullableString(PDOStatement $stmt, string $name, ?string $value): void
    {
        $stmt->bindValue($name, $value, $value !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    }

    private function bindNullableDate(PDOStatement $stmt, string $name, ?DateTimeImmutable $value): void
    {
        $stmt->bindValue(
            $name,
            $value !== null ? $this->formatDate($value) : null,
            $value !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
    }

    private function formatDate(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:sP');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
