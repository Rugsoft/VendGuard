<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\Enum\RestockOverrideStatus;
use VendGuard\Core\Domain\Enum\RestockOverrideType;
use VendGuard\Core\Domain\Enum\RestockReassignmentReasonCode;
use VendGuard\Core\Domain\Enum\RestockSkipReasonCode;

/**
 * RestockRouteDailyOverride
 *
 * Excepción diaria trazable de la ruta de reposición (Módulo 37, Tabla 51
 * `restock_route_daily_overrides`, RF-02.1, C1, QA-1, QA-4, Art. III.1,
 * Art. III.3, Art. V.1).
 *
 * Invariantes que muerden en el borde de la entidad, antes de que la fila llegue
 * al motor:
 *   1. `SKIPPED` exige `skip_reason_code` y un `reason_text` de 20 a 500
 *      caracteres reales tras recortar; prohíbe destino y causa de desvío
 *      (Art. V.1).
 *   2. `REASSIGNED` exige `target_restocker_id` y `reassignment_reason_code`;
 *      prohíbe la causa de omisión y admite una nota de hasta 500 caracteres.
 *   3. `client_mutation_id` es opcional en línea, pero si viaja desde la cola
 *      offline exige de 8 a 64 caracteres y el patrón `^[A-Za-z0-9_.:-]+$`.
 *   4. `ACTIVE` no conserva resolución; `SUPERSEDED` exige visita que la supera
 *      y solo nace de una omisión; `REVOKED` exige instante de resolución.
 *
 * Lo que la entidad **no** decide es quién puede omitir o desviar, ni si la
 * parada tiene una visita física previa: eso depende del rol, de la fecha civil
 * y del estado del día, y lo resuelve `RestockRoutePlannerValidator` (T-RRP-04).
 * Aquí solo se declara la forma coherente de la fila y sus transiciones
 * legítimas.
 *
 * Es inmutable: `revoke()` y `supersede()` devuelven una copia y el histórico
 * nunca se reescribe ni se borra (Art. III.1 y III.3).
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades privadas y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockRouteDailyOverride implements JsonSerializable
{
    /** Longitud mínima del motivo escrito de una omisión (Art. V.1). */
    public const MIN_SKIP_REASON_LENGTH = 20;

    /** Longitud máxima de cualquier nota de la excepción. */
    public const MAX_REASON_LENGTH = 500;

    /** Longitud mínima y máxima del identificador de mutación offline (C3). */
    public const MIN_MUTATION_ID_LENGTH = 8;
    public const MAX_MUTATION_ID_LENGTH = 64;

    /** Forma canónica del identificador de mutación offline (C3). */
    public const MUTATION_ID_PATTERN = '/^[A-Za-z0-9_.:-]+$/';

    /** Forma canónica de una fecha civil del contrato. */
    public const DATE_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';

    private ?int $id;
    private int $machineId;
    private string $serviceDate;
    private RestockOverrideType $overrideType;
    private RestockOverrideStatus $status;
    private ?int $originalRestockerId;
    private ?int $targetRestockerId;
    private ?RestockSkipReasonCode $skipReasonCode;
    private ?RestockReassignmentReasonCode $reassignmentReasonCode;
    private ?string $reasonText;
    private bool $hadActiveFefoAlert;
    private ?int $triggeredNotificationId;
    private ?string $clientMutationId;
    private ?int $supersededByVisitId;
    private int $createdBy;
    private ?int $resolvedBy;
    private ?string $createdAt;
    private ?string $resolvedAt;
    private ?string $updatedAt;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param int $machineId Máquina afectada por la excepción.
     * @param string $serviceDate Día civil `Y-m-d` de la excepción.
     * @param RestockOverrideType $overrideType Omisión o desvío.
     * @param RestockOverrideStatus $status Estado del ciclo de vida.
     * @param int|null $originalRestockerId Titular previo; `null` si no había cobertura.
     * @param int|null $targetRestockerId Reponedor destino, obligatorio en `REASSIGNED`.
     * @param RestockSkipReasonCode|null $skipReasonCode Causa tipificada de omisión.
     * @param RestockReassignmentReasonCode|null $reassignmentReasonCode Causa del desvío.
     * @param string|null $reasonText Motivo escrito (20..500 en la omisión).
     * @param bool $hadActiveFefoAlert Sello FEFO del momento de la omisión.
     * @param int|null $triggeredNotificationId Aviso interno encolado.
     * @param string|null $clientMutationId Idempotencia del móvil (8..64).
     * @param int|null $supersededByVisitId Visita física que supera la omisión.
     * @param int $createdBy Usuario que registró la excepción.
     * @param int|null $resolvedBy Usuario que la revocó.
     * @param string|null $createdAt Sello de alta del motor.
     * @param string|null $resolvedAt Sello de resolución.
     * @param string|null $updatedAt Sello de última modificación.
     *
     * @throws InvalidArgumentException Si la rama del tipo, el estado, el motivo o
     *                                  la idempotencia no cumplen el contrato.
     */
    public function __construct(
        ?int $id,
        int $machineId,
        string $serviceDate,
        RestockOverrideType $overrideType,
        RestockOverrideStatus $status = RestockOverrideStatus::ACTIVE,
        ?int $originalRestockerId = null,
        ?int $targetRestockerId = null,
        ?RestockSkipReasonCode $skipReasonCode = null,
        ?RestockReassignmentReasonCode $reassignmentReasonCode = null,
        ?string $reasonText = null,
        bool $hadActiveFefoAlert = false,
        ?int $triggeredNotificationId = null,
        ?string $clientMutationId = null,
        ?int $supersededByVisitId = null,
        int $createdBy = 0,
        ?int $resolvedBy = null,
        ?string $createdAt = null,
        ?string $resolvedAt = null,
        ?string $updatedAt = null
    ) {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador de la excepción debe ser un entero positivo.');
        }

        if ($machineId <= 0) {
            throw new InvalidArgumentException('La excepción debe referenciar una máquina existente.');
        }

        if ($createdBy <= 0) {
            throw new InvalidArgumentException('La excepción debe declarar el usuario que la registra.');
        }

        $cleanServiceDate = self::normalizeDate($serviceDate, 'service_date');

        foreach ([
            'original_restocker_id' => $originalRestockerId,
            'target_restocker_id' => $targetRestockerId,
            'triggered_notification_id' => $triggeredNotificationId,
            'superseded_by_visit_id' => $supersededByVisitId,
            'resolved_by' => $resolvedBy,
        ] as $field => $value) {
            if ($value !== null && $value <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'El campo %s debe ser un identificador positivo o null; se recibió %d.',
                    $field,
                    $value
                ));
            }
        }

        $cleanReason = self::normalizeText($reasonText);
        self::assertTypeCoherence($overrideType, $targetRestockerId, $skipReasonCode, $reassignmentReasonCode, $cleanReason);
        self::assertStatusCoherence($status, $overrideType, $resolvedAt, $supersededByVisitId, $resolvedBy);

        $this->id = $id;
        $this->machineId = $machineId;
        $this->serviceDate = $cleanServiceDate;
        $this->overrideType = $overrideType;
        $this->status = $status;
        $this->originalRestockerId = $originalRestockerId;
        $this->targetRestockerId = $targetRestockerId;
        $this->skipReasonCode = $skipReasonCode;
        $this->reassignmentReasonCode = $reassignmentReasonCode;
        $this->reasonText = $cleanReason;
        $this->hadActiveFefoAlert = $hadActiveFefoAlert;
        $this->triggeredNotificationId = $triggeredNotificationId;
        $this->clientMutationId = self::normalizeMutationId($clientMutationId);
        $this->supersededByVisitId = $supersededByVisitId;
        $this->createdBy = $createdBy;
        $this->resolvedBy = $resolvedBy;
        $this->createdAt = $createdAt;
        $this->resolvedAt = $resolvedAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Reconstruye la entidad desde una fila del motor.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException Si la fila no describe una excepción válida.
     */
    public static function fromDatabaseRow(array $row): self
    {
        $skipReason = isset($row['skip_reason_code']) && $row['skip_reason_code'] !== null
            ? RestockSkipReasonCode::fromString((string)$row['skip_reason_code'])
            : null;
        $reassignmentReason = isset($row['reassignment_reason_code']) && $row['reassignment_reason_code'] !== null
            ? RestockReassignmentReasonCode::fromString((string)$row['reassignment_reason_code'])
            : null;

        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (int)($row['machine_id'] ?? 0),
            (string)($row['service_date'] ?? ''),
            RestockOverrideType::fromString((string)($row['override_type'] ?? 'SKIPPED')),
            RestockOverrideStatus::fromString((string)($row['status'] ?? 'ACTIVE')),
            isset($row['original_restocker_id']) && $row['original_restocker_id'] !== null ? (int)$row['original_restocker_id'] : null,
            isset($row['target_restocker_id']) && $row['target_restocker_id'] !== null ? (int)$row['target_restocker_id'] : null,
            $skipReason,
            $reassignmentReason,
            isset($row['reason_text']) && $row['reason_text'] !== null ? (string)$row['reason_text'] : null,
            self::toBoolean($row['had_active_fefo_alert'] ?? false),
            isset($row['triggered_notification_id']) && $row['triggered_notification_id'] !== null ? (int)$row['triggered_notification_id'] : null,
            isset($row['client_mutation_id']) && $row['client_mutation_id'] !== null ? (string)$row['client_mutation_id'] : null,
            isset($row['superseded_by_visit_id']) && $row['superseded_by_visit_id'] !== null ? (int)$row['superseded_by_visit_id'] : null,
            (int)($row['created_by'] ?? 0),
            isset($row['resolved_by']) && $row['resolved_by'] !== null ? (int)$row['resolved_by'] : null,
            isset($row['created_at']) && $row['created_at'] !== null ? (string)$row['created_at'] : null,
            isset($row['resolved_at']) && $row['resolved_at'] !== null ? (string)$row['resolved_at'] : null,
            isset($row['updated_at']) && $row['updated_at'] !== null ? (string)$row['updated_at'] : null
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getServiceDate(): string
    {
        return $this->serviceDate;
    }

    public function getOverrideType(): RestockOverrideType
    {
        return $this->overrideType;
    }

    public function getStatus(): RestockOverrideStatus
    {
        return $this->status;
    }

    public function getOriginalRestockerId(): ?int
    {
        return $this->originalRestockerId;
    }

    public function getTargetRestockerId(): ?int
    {
        return $this->targetRestockerId;
    }

    public function getSkipReasonCode(): ?RestockSkipReasonCode
    {
        return $this->skipReasonCode;
    }

    public function getReassignmentReasonCode(): ?RestockReassignmentReasonCode
    {
        return $this->reassignmentReasonCode;
    }

    public function getReasonText(): ?string
    {
        return $this->reasonText;
    }

    public function hadActiveFefoAlert(): bool
    {
        return $this->hadActiveFefoAlert;
    }

    public function getTriggeredNotificationId(): ?int
    {
        return $this->triggeredNotificationId;
    }

    public function getClientMutationId(): ?string
    {
        return $this->clientMutationId;
    }

    public function getSupersededByVisitId(): ?int
    {
        return $this->supersededByVisitId;
    }

    public function getCreatedBy(): int
    {
        return $this->createdBy;
    }

    public function getResolvedBy(): ?int
    {
        return $this->resolvedBy;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?string
    {
        return $this->resolvedAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isSkipped(): bool
    {
        return $this->overrideType->isSkipped();
    }

    public function isReassigned(): bool
    {
        return $this->overrideType->isReassigned();
    }

    public function isSuperseded(): bool
    {
        return $this->status->isSuperseded();
    }

    public function isRevoked(): bool
    {
        return $this->status->isRevoked();
    }

    public function withId(int $id): self
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('El identificador de la excepción debe ser un entero positivo.');
        }

        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * Vincula el aviso interno encolado al omitir una parada con FEFO viva
     * (RF-02.2, Art. V.4).
     */
    public function withNotification(?int $triggeredNotificationId): self
    {
        if ($triggeredNotificationId !== null && $triggeredNotificationId <= 0) {
            throw new InvalidArgumentException('El aviso disparado debe ser un identificador positivo o null.');
        }

        $clone = clone $this;
        $clone->triggeredNotificationId = $triggeredNotificationId;

        return $clone;
    }

    /**
     * Revocación manual de Coordinación o automática por baja del receptor
     * (`QA-3`): la fila conserva su histórico y deja de estar viva.
     */
    public function revoke(int $resolvedBy, string $resolvedAt): self
    {
        if (!$this->isActive()) {
            throw new InvalidArgumentException('Solo una excepción activa puede revocarse.');
        }

        if ($resolvedBy <= 0) {
            throw new InvalidArgumentException('La revocación exige el usuario responsable.');
        }

        return new self(
            $this->id,
            $this->machineId,
            $this->serviceDate,
            $this->overrideType,
            RestockOverrideStatus::REVOKED,
            $this->originalRestockerId,
            $this->targetRestockerId,
            $this->skipReasonCode,
            $this->reassignmentReasonCode,
            $this->reasonText,
            $this->hadActiveFefoAlert,
            $this->triggeredNotificationId,
            $this->clientMutationId,
            null,
            $this->createdBy,
            $resolvedBy,
            $this->createdAt,
            $resolvedAt,
            $resolvedAt
        );
    }

    /**
     * Prevalencia física de la visita real (`C1`, `QA-6`): una omisión viva se
     * supera cuando la visita se completa o aborta por rotura térmica.
     */
    public function supersede(int $visitId, ?int $resolvedBy, string $resolvedAt): self
    {
        if (!$this->isActive()) {
            throw new InvalidArgumentException('Solo una excepción activa puede quedar superada.');
        }

        if (!$this->isSkipped()) {
            throw new InvalidArgumentException('Solo una omisión SKIPPED puede quedar superada por una visita.');
        }

        if ($visitId <= 0) {
            throw new InvalidArgumentException('La visita que supera la omisión debe ser un identificador positivo.');
        }

        if ($resolvedBy !== null && $resolvedBy <= 0) {
            throw new InvalidArgumentException('El usuario de resolución debe ser un identificador positivo o null.');
        }

        return new self(
            $this->id,
            $this->machineId,
            $this->serviceDate,
            $this->overrideType,
            RestockOverrideStatus::SUPERSEDED,
            $this->originalRestockerId,
            $this->targetRestockerId,
            $this->skipReasonCode,
            $this->reassignmentReasonCode,
            $this->reasonText,
            $this->hadActiveFefoAlert,
            $this->triggeredNotificationId,
            $this->clientMutationId,
            $visitId,
            $this->createdBy,
            $resolvedBy,
            $this->createdAt,
            $resolvedAt,
            $resolvedAt
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'machine_id' => $this->machineId,
            'service_date' => $this->serviceDate,
            'override_type' => $this->overrideType->value,
            'override_type_label' => $this->overrideType->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'original_restocker_id' => $this->originalRestockerId,
            'target_restocker_id' => $this->targetRestockerId,
            'skip_reason_code' => $this->skipReasonCode?->value,
            'skip_reason_label' => $this->skipReasonCode?->label(),
            'reassignment_reason_code' => $this->reassignmentReasonCode?->value,
            'reassignment_reason_label' => $this->reassignmentReasonCode?->label(),
            'reason_text' => $this->reasonText,
            'had_active_fefo_alert' => $this->hadActiveFefoAlert,
            'triggered_notification_id' => $this->triggeredNotificationId,
            'client_mutation_id' => $this->clientMutationId,
            'superseded_by_visit_id' => $this->supersededByVisitId,
            'created_by' => $this->createdBy,
            'resolved_by' => $this->resolvedBy,
            'created_at' => $this->createdAt,
            'resolved_at' => $this->resolvedAt,
            'updated_at' => $this->updatedAt,
            'is_active' => $this->isActive(),
            'is_skipped' => $this->isSkipped(),
            'is_reassigned' => $this->isReassigned(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Misma regla que `chk_rrdo_type_fields_coherence`.
     *
     * @throws InvalidArgumentException
     */
    private static function assertTypeCoherence(
        RestockOverrideType $overrideType,
        ?int $targetRestockerId,
        ?RestockSkipReasonCode $skipReasonCode,
        ?RestockReassignmentReasonCode $reassignmentReasonCode,
        ?string $reasonText
    ): void {
        if ($overrideType->isSkipped()) {
            if ($skipReasonCode === null) {
                throw new InvalidArgumentException('Una omisión SKIPPED exige skip_reason_code.');
            }
            if ($reassignmentReasonCode !== null) {
                throw new InvalidArgumentException('Una omisión SKIPPED no puede declarar reassignment_reason_code.');
            }
            if ($targetRestockerId !== null) {
                throw new InvalidArgumentException('Una omisión SKIPPED no puede declarar target_restocker_id.');
            }
            if ($reasonText === null
                || mb_strlen($reasonText, 'UTF-8') < self::MIN_SKIP_REASON_LENGTH) {
                throw new InvalidArgumentException(sprintf(
                    'Una omisión SKIPPED exige reason_text de al menos %d caracteres reales (Art. V.1).',
                    self::MIN_SKIP_REASON_LENGTH
                ));
            }
            if (mb_strlen($reasonText, 'UTF-8') > self::MAX_REASON_LENGTH) {
                throw new InvalidArgumentException(sprintf(
                    'El motivo de la omisión no puede superar %d caracteres reales.',
                    self::MAX_REASON_LENGTH
                ));
            }

            return;
        }

        if ($targetRestockerId === null) {
            throw new InvalidArgumentException('Un desvío REASSIGNED exige target_restocker_id.');
        }
        if ($reassignmentReasonCode === null) {
            throw new InvalidArgumentException('Un desvío REASSIGNED exige reassignment_reason_code.');
        }
        if ($skipReasonCode !== null) {
            throw new InvalidArgumentException('Un desvío REASSIGNED no puede declarar skip_reason_code.');
        }
        if ($reasonText !== null && mb_strlen($reasonText, 'UTF-8') > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'La nota del desvío no puede superar %d caracteres reales.',
                self::MAX_REASON_LENGTH
            ));
        }
    }

    /**
     * Misma regla que `chk_rrdo_status_resolution_coherence`.
     *
     * @throws InvalidArgumentException
     */
    private static function assertStatusCoherence(
        RestockOverrideStatus $status,
        RestockOverrideType $overrideType,
        ?string $resolvedAt,
        ?int $supersededByVisitId,
        ?int $resolvedBy
    ): void {
        if ($status->isActive()) {
            if ($resolvedAt !== null || $supersededByVisitId !== null || $resolvedBy !== null) {
                throw new InvalidArgumentException(
                    'Una excepción ACTIVE no puede declarar resolución ni visita que la supere.'
                );
            }

            return;
        }

        if ($resolvedAt === null) {
            throw new InvalidArgumentException(sprintf(
                'Una excepción %s exige resolved_at.',
                $status->value
            ));
        }

        if ($status->isSuperseded()) {
            if ($supersededByVisitId === null) {
                throw new InvalidArgumentException('Una excepción SUPERSEDED exige superseded_by_visit_id.');
            }
            if (!$overrideType->isSkipped()) {
                throw new InvalidArgumentException('Solo una omisión SKIPPED puede quedar SUPERSEDED.');
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function normalizeMutationId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);
        $length = mb_strlen($clean, 'UTF-8');

        if ($length < self::MIN_MUTATION_ID_LENGTH || $length > self::MAX_MUTATION_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El client_mutation_id debe tener entre %d y %d caracteres; se recibieron %d.',
                self::MIN_MUTATION_ID_LENGTH,
                self::MAX_MUTATION_ID_LENGTH,
                $length
            ));
        }

        if (preg_match(self::MUTATION_ID_PATTERN, $clean) !== 1) {
            throw new InvalidArgumentException(
                'El client_mutation_id solo admite letras, dígitos, punto, guion bajo, dos puntos y guion.'
            );
        }

        return $clean;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function normalizeDate(string $value, string $field): string
    {
        $clean = trim($value);
        if (preg_match(self::DATE_PATTERN, $clean) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "El campo %s «%s» debe cumplir el formato Y-m-d.",
                $field,
                $value
            ));
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $clean);
        if ($parsed === false || $parsed->format('Y-m-d') !== $clean) {
            throw new InvalidArgumentException(sprintf(
                'El campo %s «%s» no es una fecha civil válida.',
                $field,
                $value
            ));
        }

        return $clean;
    }

    private static function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);

        return $clean === '' ? null : $clean;
    }

    private static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
