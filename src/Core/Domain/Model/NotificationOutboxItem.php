<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Core\Domain\ValueObject\NotificationPriority;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;

/**
 * NotificationOutboxItem
 *
 * Entidad de dominio que representa una notificación en la bandeja de salida transaccional (Tabla 21).
 * Implementa el patrón Outbox para desacoplar el registro de la entrega externa (RF-01).
 */
class NotificationOutboxItem implements JsonSerializable
{
    private ?int $id;
    private ?int $incidentId;
    private NotificationChannel $channel;
    private NotificationPriority $priority;
    private NotificationTemplate $template;
    private string $recipient;
    private string $recipientRole;
    private ?int $recipientUserId;
    private ?int $recipientLocationId;
    private string $subject;
    /** @var array<string, mixed> */
    private array $payload;
    private ?string $dedupKey;
    private NotificationStatus $status;
    private int $attempts;
    private int $maxAttempts;
    private DateTimeImmutable $nextAttemptAt;
    private ?DateTimeImmutable $processingStartedAt;
    private ?DateTimeImmutable $processedAt;
    private ?string $lastError;
    private ?string $obsoleteReason;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;
    private ?DateTimeImmutable $deletedAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        ?int $id,
        ?int $incidentId,
        NotificationChannel $channel,
        NotificationPriority $priority,
        NotificationTemplate $template,
        string $recipient,
        string $recipientRole,
        ?int $recipientUserId,
        ?int $recipientLocationId,
        string $subject,
        array $payload,
        ?string $dedupKey = null,
        NotificationStatus $status = NotificationStatus::PENDING,
        int $attempts = 0,
        int $maxAttempts = 5,
        ?DateTimeImmutable $nextAttemptAt = null,
        ?DateTimeImmutable $processingStartedAt = null,
        ?DateTimeImmutable $processedAt = null,
        ?string $lastError = null,
        ?string $obsoleteReason = null,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null,
        ?DateTimeImmutable $deletedAt = null
    ) {
        $cleanRecipient = trim($recipient);
        if ($cleanRecipient === '') {
            throw new InvalidArgumentException('El destinatario de la notificación no puede estar vacío.');
        }

        $cleanSubject = trim($subject);
        if ($cleanSubject === '') {
            throw new InvalidArgumentException('El asunto de la notificación no puede estar vacío.');
        }

        $this->id = $id;
        $this->incidentId = $incidentId;
        $this->channel = $channel;
        $this->priority = $priority;
        $this->template = $template;
        $this->recipient = $cleanRecipient;
        $this->recipientRole = strtoupper(trim($recipientRole));
        $this->recipientUserId = $recipientUserId;
        $this->recipientLocationId = $recipientLocationId;
        $this->subject = $cleanSubject;
        $this->payload = $payload;
        $this->dedupKey = $dedupKey !== null && trim($dedupKey) !== '' ? trim($dedupKey) : null;
        $this->status = $status;
        $this->attempts = max(0, $attempts);
        $this->maxAttempts = max(1, $maxAttempts);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->nextAttemptAt = $nextAttemptAt ?? $now;
        $this->processingStartedAt = $processingStartedAt;
        $this->processedAt = $processedAt;
        $this->lastError = $lastError;
        $this->obsoleteReason = $obsoleteReason;
        $this->createdAt = $createdAt ?? $now;
        $this->updatedAt = $updatedAt ?? $now;
        $this->deletedAt = $deletedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIncidentId(): ?int
    {
        return $this->incidentId;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getPriority(): NotificationPriority
    {
        return $this->priority;
    }

    public function getTemplate(): NotificationTemplate
    {
        return $this->template;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getRecipientRole(): string
    {
        return $this->recipientRole;
    }

    public function getRecipientUserId(): ?int
    {
        return $this->recipientUserId;
    }

    public function getRecipientLocationId(): ?int
    {
        return $this->recipientLocationId;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getDedupKey(): ?string
    {
        return $this->dedupKey;
    }

    public function getStatus(): NotificationStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getNextAttemptAt(): DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function getProcessingStartedAt(): ?DateTimeImmutable
    {
        return $this->processingStartedAt;
    }

    public function getProcessedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getObsoleteReason(): ?string
    {
        return $this->obsoleteReason;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /**
     * Marca la notificación como en proceso por el despachador.
     */
    public function markProcessing(DateTimeImmutable $startedAt): void
    {
        if (!$this->status->canTransitionTo(NotificationStatus::PROCESSING)) {
            throw new InvalidArgumentException(sprintf(
                'Transición ilegal de estado: no se puede pasar de %s a PROCESSING',
                $this->status->value
            ));
        }

        $this->status = NotificationStatus::PROCESSING;
        $this->processingStartedAt = $startedAt;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Marca la notificación como enviada con éxito.
     */
    public function markSent(DateTimeImmutable $sentAt): void
    {
        if (!$this->status->canTransitionTo(NotificationStatus::SENT)) {
            throw new InvalidArgumentException(sprintf(
                'Transición ilegal de estado: no se puede pasar de %s a SENT',
                $this->status->value
            ));
        }

        $this->status = NotificationStatus::SENT;
        $this->attempts++;
        $this->processedAt = $sentAt;
        $this->lastError = null;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Registra un fallo transitorio y programa el siguiente reintento con backoff.
     */
    public function recordTransientFailure(string $errorMessage, DateTimeImmutable $nextAttemptAt): void
    {
        $this->attempts++;
        $this->lastError = $errorMessage;
        $this->nextAttemptAt = $nextAttemptAt;
        $this->status = NotificationStatus::PENDING_RETRY;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Registra un fallo permanente (agotamiento de 5 intentos o rechazo irreversible).
     */
    public function recordPermanentFailure(string $errorMessage, ?DateTimeImmutable $failedAt = null): void
    {
        $this->attempts++;
        $this->lastError = $errorMessage;
        $this->status = NotificationStatus::FAILED_PERMANENT;
        $this->processedAt = $failedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Cancela la notificación por obsolescencia o acción manual.
     */
    public function markCancelledObsolete(string $reason, ?DateTimeImmutable $cancelledAt = null): void
    {
        if (!$this->status->canTransitionTo(NotificationStatus::CANCELLED_OBSOLETE)) {
            throw new InvalidArgumentException(sprintf(
                'Transición ilegal de estado: no se puede cancelar una notificación en estado %s',
                $this->status->value
            ));
        }

        $this->status = NotificationStatus::CANCELLED_OBSOLETE;
        $this->obsoleteReason = $reason;
        $this->processedAt = $cancelledAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Restablece la notificación a PENDING para reintento manual (RF-06.4).
     */
    public function resetForManualRetry(string $freshRecipient, DateTimeImmutable $retryAt): void
    {
        if (!$this->status->canTransitionTo(NotificationStatus::PENDING)) {
            throw new InvalidArgumentException(sprintf(
                'Transición ilegal de estado: no se puede reintentar manualmente desde %s',
                $this->status->value
            ));
        }

        $cleanRecipient = trim($freshRecipient);
        if ($cleanRecipient !== '') {
            $this->recipient = $cleanRecipient;
        }

        $this->status = NotificationStatus::PENDING;
        $this->attempts = 0;
        $this->nextAttemptAt = $retryAt;
        $this->processingStartedAt = null;
        $this->processedAt = null;
        $this->lastError = null;
        $this->obsoleteReason = null;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Actualiza el payload de una notificación PENDING ante una ráfaga de respuestas (RF-05.4).
     *
     * @param array<string, mixed> $freshPayload
     */
    public function updatePayloadForBurst(array $freshPayload, string $freshSubject): void
    {
        if ($this->status !== NotificationStatus::PENDING) {
            throw new InvalidArgumentException('Solo se puede agrupar en ráfaga sobre notificaciones en estado PENDING');
        }

        $this->payload = $freshPayload;
        $this->subject = trim($freshSubject);
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incidentId,
            'channel' => $this->channel->value,
            'priority' => $this->priority->value,
            'template' => $this->template->value,
            'recipient' => $this->recipient,
            'recipient_role' => $this->recipientRole,
            'recipient_user_id' => $this->recipientUserId,
            'recipient_location_id' => $this->recipientLocationId,
            'subject' => $this->subject,
            'payload' => $this->payload,
            'dedup_key' => $this->dedupKey,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'next_attempt_at' => $this->nextAttemptAt->format('Y-m-d H:i:s'),
            'processing_started_at' => $this->processingStartedAt?->format('Y-m-d H:i:s'),
            'processed_at' => $this->processedAt?->format('Y-m-d H:i:s'),
            'last_error' => $this->lastError,
            'obsolete_reason' => $this->obsoleteReason,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
