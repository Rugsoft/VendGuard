<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use ArrayAccess;
use JsonSerializable;

/**
 * IncidentComment
 * 
 * Entidad de Dominio que modela una anotación o evidencia complementaria en la
 * bitácora de una incidencia (RF-02 / EARS 2.3).
 * 
 * Permite a informadores y personal técnico aportar detalles y fotografías sucesivas
 * preservando intacta la fotografía original del ticket inicial (Edge Case 6).
 */
class IncidentComment implements ArrayAccess, JsonSerializable
{
    private ?int $id;
    private int $incidentId;
    private string $authorType;
    private ?int $userId;
    private string $authorName;
    private string $commentText;
    private ?string $photoPath;
    private bool $isInternal;
    private ?string $createdAt;
    private ?string $ticketCode;

    public function __construct(
        ?int $id,
        int $incidentId,
        string $authorType,
        ?int $userId,
        string $authorName,
        string $commentText,
        ?string $photoPath = null,
        bool $isInternal = false,
        ?string $createdAt = null,
        ?string $ticketCode = null
    ) {
        $this->id = $id;
        $this->incidentId = $incidentId;
        $this->authorType = strtoupper(trim($authorType));
        $this->userId = $userId;
        $this->authorName = trim($authorName);
        $this->commentText = trim($commentText);
        $this->photoPath = $photoPath !== null ? trim($photoPath) : null;
        $this->isInternal = $isInternal;
        $this->createdAt = $createdAt;
        $this->ticketCode = $ticketCode !== null ? strtoupper(trim($ticketCode)) : null;
    }

    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int)$row['id'] : null,
            incidentId: (int)$row['incident_id'],
            authorType: (string)($row['author_type'] ?? 'REPORTER'),
            userId: isset($row['user_id']) && $row['user_id'] !== null ? (int)$row['user_id'] : null,
            authorName: (string)($row['author_name'] ?? 'Anónimo'),
            commentText: (string)($row['comment_text'] ?? ''),
            photoPath: isset($row['photo_path']) && $row['photo_path'] !== null ? (string)$row['photo_path'] : null,
            isInternal: !empty($row['is_internal']),
            createdAt: isset($row['created_at']) ? (string)$row['created_at'] : null,
            ticketCode: isset($row['ticket_code']) && $row['ticket_code'] !== null ? (string)$row['ticket_code'] : null
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIncidentId(): int
    {
        return $this->incidentId;
    }

    public function getAuthorType(): string
    {
        return $this->authorType;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function getCommentText(): string
    {
        return $this->commentText;
    }

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function isInternal(): bool
    {
        return $this->isInternal;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getTicketCode(): ?string
    {
        return $this->ticketCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'incident_id' => $this->incidentId,
            'author_type' => $this->authorType,
            'user_id' => $this->userId,
            'author_name' => $this->authorName,
            'comment_text' => $this->commentText,
            'photo_path' => $this->photoPath,
            'is_internal' => $this->isInternal,
            'created_at' => $this->createdAt,
        ];

        if ($this->ticketCode !== null) {
            $data['ticket_code'] = $this->ticketCode;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string)$offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        $arr = $this->toArray();
        return $arr[(string)$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Inmutable
    }

    public function offsetUnset(mixed $offset): void
    {
        // Inmutable
    }
}
