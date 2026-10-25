<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use JsonException;
use VendGuard\Core\Domain\ValueObject\ShiftEventType;

/**
 * TechnicianShiftLog
 *
 * Entidad de dominio inmutable que representa una entrada de la bitácora
 * `technician_shift_logs` (Módulo 22).
 *
 * Art. III.1 y III.3: la bitácora es append-only. No existe método de
 * actualización ni de borrado; una corrección se documenta con una nueva
 * entrada que conserva el estado previo y el nuevo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades readonly, métodos puros.
 * Dualismo Lingüístico: código en inglés, etiquetas y mensajes en castellano.
 */
final class TechnicianShiftLog
{
    /**
     * @param array<string, mixed>|null $previousState Snapshot previo del turno (JSONB).
     * @param array<string, mixed> $newState Snapshot resultante del turno (JSONB).
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $shiftId,
        private readonly int $technicianId,
        private readonly ShiftEventType $eventType,
        private readonly int $actorUserId,
        private readonly DateTimeImmutable $effectiveAt,
        private readonly ?array $previousState,
        private readonly array $newState,
        private readonly ?string $reason = null,
        private readonly ?DateTimeImmutable $createdAt = null
    ) {
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function shiftId(): int
    {
        return $this->shiftId;
    }

    public function technicianId(): int
    {
        return $this->technicianId;
    }

    public function eventType(): ShiftEventType
    {
        return $this->eventType;
    }

    public function actorUserId(): int
    {
        return $this->actorUserId;
    }

    public function effectiveAt(): DateTimeImmutable
    {
        return $this->effectiveAt;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function previousState(): ?array
    {
        return $this->previousState;
    }

    /**
     * @return array<string, mixed>
     */
    public function newState(): array
    {
        return $this->newState;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Hidrata la entidad desde una fila de `technician_shift_logs`.
     *
     * PDO devuelve las columnas JSONB como cadena, por lo que se aceptan tanto
     * cadenas JSON como arrays ya decodificados.
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int)$row['id'] : null,
            shiftId: (int)$row['shift_id'],
            technicianId: (int)$row['technician_id'],
            eventType: ShiftEventType::from((string)$row['event_type']),
            actorUserId: (int)$row['actor_user_id'],
            effectiveAt: new DateTimeImmutable((string)$row['effective_at']),
            previousState: self::decodeState($row['previous_state'] ?? null),
            newState: self::decodeState($row['new_state'] ?? []) ?? [],
            reason: isset($row['reason']) && $row['reason'] !== null ? (string)$row['reason'] : null,
            createdAt: isset($row['created_at']) && $row['created_at'] !== null ? new DateTimeImmutable((string)$row['created_at']) : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shift_id' => $this->shiftId,
            'technician_id' => $this->technicianId,
            'event_type' => $this->eventType->value,
            'event_type_label' => $this->eventType->label(),
            'actor_user_id' => $this->actorUserId,
            'effective_at' => $this->effectiveAt->format(DateTimeImmutable::ATOM),
            'previous_state' => $this->previousState,
            'new_state' => $this->newState,
            'reason' => $this->reason,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param mixed $value Cadena JSON, array o null.
     * @return array<string, mixed>|null
     */
    private static function decodeState(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        try {
            $decoded = json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
