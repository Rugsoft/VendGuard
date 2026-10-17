<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

/**
 * NotificationChannelSettings
 *
 * Entidad de dominio que representa la configuración del Canal Corporativo de Emergencias (Tabla 22 · RF-06.5).
 */
class NotificationChannelSettings implements JsonSerializable
{
    private ?int $id;
    private string $webhookName;
    private ?string $webhookUrl;
    private bool $isWebhookEnabled;
    private ?DateTimeImmutable $lastTestedAt;
    private ?string $lastTestStatus;
    private ?string $lastTestError;
    private ?int $updatedBy;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;
    private ?DateTimeImmutable $deletedAt;

    public function __construct(
        ?int $id,
        string $webhookName = 'Canal Central de Emergencias',
        ?string $webhookUrl = null,
        bool $isWebhookEnabled = false,
        ?DateTimeImmutable $lastTestedAt = null,
        ?string $lastTestStatus = null,
        ?string $lastTestError = null,
        ?int $updatedBy = null,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null,
        ?DateTimeImmutable $deletedAt = null
    ) {
        $cleanName = trim($webhookName);
        if ($cleanName === '') {
            throw new InvalidArgumentException('El nombre del canal de emergencias no puede estar vacío.');
        }

        $this->id = $id;
        $this->webhookName = $cleanName;
        $this->webhookUrl = $webhookUrl !== null && trim($webhookUrl) !== '' ? trim($webhookUrl) : null;
        $this->isWebhookEnabled = $isWebhookEnabled;
        $this->lastTestedAt = $lastTestedAt;
        $this->lastTestStatus = $lastTestStatus;
        $this->lastTestError = $lastTestError;
        $this->updatedBy = $updatedBy;

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->createdAt = $createdAt ?? $now;
        $this->updatedAt = $updatedAt ?? $now;
        $this->deletedAt = $deletedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWebhookName(): string
    {
        return $this->webhookName;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function isWebhookEnabled(): bool
    {
        return $this->isWebhookEnabled;
    }

    public function isConfiguredAndEnabled(): bool
    {
        return $this->isWebhookEnabled && $this->webhookUrl !== null && $this->webhookUrl !== '';
    }

    public function getLastTestedAt(): ?DateTimeImmutable
    {
        return $this->lastTestedAt;
    }

    public function getLastTestStatus(): ?string
    {
        return $this->lastTestStatus;
    }

    public function getLastTestError(): ?string
    {
        return $this->lastTestError;
    }

    public function getUpdatedBy(): ?int
    {
        return $this->updatedBy;
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
     * Actualiza los datos de configuración del Webhook (RF-06.5).
     */
    public function updateConfiguration(string $name, ?string $url, bool $enabled, ?int $updatedBy = null): void
    {
        $cleanName = trim($name);
        if ($cleanName === '') {
            throw new InvalidArgumentException('El nombre del webhook no puede estar vacío.');
        }

        $this->webhookName = $cleanName;
        $this->webhookUrl = $url !== null && trim($url) !== '' ? trim($url) : null;
        $this->isWebhookEnabled = $enabled;
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Registra el resultado de una prueba interactiva directa (RF-06.5.2).
     */
    public function recordTestResult(bool $success, ?string $error = null, ?DateTimeImmutable $testedAt = null): void
    {
        $this->lastTestedAt = $testedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->lastTestStatus = $success ? 'SUCCESS' : 'FAILED';
        $this->lastTestError = $success ? null : $error;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'webhook_name' => $this->webhookName,
            'webhook_url' => $this->webhookUrl,
            'is_webhook_enabled' => $this->isWebhookEnabled,
            'last_tested_at' => $this->lastTestedAt?->format('Y-m-d H:i:s'),
            'last_test_status' => $this->lastTestStatus,
            'last_test_error' => $this->lastTestError,
            'updated_by' => $this->updatedBy,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
