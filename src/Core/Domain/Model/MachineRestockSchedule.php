<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\Enum\RestockCadenceType;
use VendGuard\Core\Domain\Enum\RestockTimeWindow;

/**
 * MachineRestockSchedule
 *
 * Agenda periódica de reposición de una máquina (Módulo 37, Tabla 50
 * `machine_restock_schedules`, RF-01.1, D1, D2, D6, QA-2, Art. III.3).
 *
 * Invariantes que muerden en el borde de la entidad, antes de que la fila llegue
 * al motor:
 *   1. `WEEKLY` exige `day_of_week` ISO-8601 (`1..7`) y prohíbe `anchor_date`;
 *      `BIWEEKLY` exige `anchor_date` y prohíbe `day_of_week`; `DAILY`,
 *      `MON_WED_FRI` y `TUE_THU` prohíben ambos.
 *   2. `include_weekends = TRUE` solo se admite en `DAILY`.
 *   3. `notes` es opcional, pero si se declara exige de 1 a 500 caracteres
 *      reales tras recortar.
 *   4. Una agenda activa no conserva marca ni actor de baja; una inactiva exige
 *      `deactivated_at` y, si declara `deactivated_by`, debe ser un usuario
 *      positivo.
 *
 * Lo que la entidad **no** decide es si la cadencia aplica en una fecha civil ni
 * cuál es la próxima parada: esa aritmética DST-safe vive en
 * `RestockCadenceEvaluator` (T-RRP-04). Aquí solo se declara la forma coherente
 * de la fila.
 *
 * Es inmutable: cada ajuste devuelve una copia (`withId`, `deactivate`) y la
 * versión cerrada nunca se reescribe (Art. III.3, `QA-2`). La agenda no guarda
 * `zone_id` ni `assigned_restocker_id`: la zona se hereda de la sede y el
 * titular efectivo lo resuelve la cobertura del Módulo 31 (D2).
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades privadas y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class MachineRestockSchedule implements JsonSerializable
{
    /** Rango ISO-8601 del día de la semana (1 = lunes, 7 = domingo). */
    public const MIN_DAY_OF_WEEK = 1;
    public const MAX_DAY_OF_WEEK = 7;

    /** Longitud máxima de la nota de agenda. */
    public const MAX_NOTES_LENGTH = 500;

    /** Forma canónica de una fecha civil del contrato. */
    public const DATE_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';

    private ?int $id;
    private int $machineId;
    private RestockCadenceType $cadenceType;
    private ?int $dayOfWeek;
    private ?string $anchorDate;
    private RestockTimeWindow $preferredTimeWindow;
    private bool $includeWeekends;
    private ?string $notes;
    private bool $isActive;
    private int $createdBy;
    private int $updatedBy;
    private ?int $deactivatedBy;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $deactivatedAt;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param int $machineId Máquina abastecida.
     * @param RestockCadenceType $cadenceType Cadencia periódica.
     * @param int|null $dayOfWeek Día ISO-8601 (`1..7`), solo en `WEEKLY`.
     * @param string|null $anchorDate Fecha ancla `Y-m-d`, solo en `BIWEEKLY`.
     * @param RestockTimeWindow $preferredTimeWindow Franja preferida de acceso.
     * @param bool $includeWeekends Fines de semana incluidos, solo en `DAILY`.
     * @param string|null $notes Nota operativa de 1 a 500 caracteres.
     * @param bool $isActive Versión vigente de la agenda.
     * @param int $createdBy Usuario que creó la versión.
     * @param int $updatedBy Usuario que firmó la última edición.
     * @param int|null $deactivatedBy Usuario que cerró la versión.
     * @param string|null $createdAt Sello de alta del motor.
     * @param string|null $updatedAt Sello de última modificación.
     * @param string|null $deactivatedAt Sello de baja lógica.
     *
     * @throws InvalidArgumentException Si la cadencia, el día, el ancla, la nota
     *                                  o la coherencia de baja no cumplen el contrato.
     */
    public function __construct(
        ?int $id,
        int $machineId,
        RestockCadenceType $cadenceType,
        ?int $dayOfWeek = null,
        ?string $anchorDate = null,
        RestockTimeWindow $preferredTimeWindow = RestockTimeWindow::ANY,
        bool $includeWeekends = false,
        ?string $notes = null,
        bool $isActive = true,
        int $createdBy = 0,
        int $updatedBy = 0,
        ?int $deactivatedBy = null,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $deactivatedAt = null
    ) {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador de la agenda debe ser un entero positivo.');
        }

        if ($machineId <= 0) {
            throw new InvalidArgumentException('La agenda debe referenciar una máquina existente.');
        }

        if ($createdBy <= 0) {
            throw new InvalidArgumentException('La agenda debe declarar el usuario que la crea.');
        }

        if ($updatedBy <= 0) {
            throw new InvalidArgumentException('La agenda debe declarar el usuario que la actualiza.');
        }

        if ($deactivatedBy !== null && $deactivatedBy <= 0) {
            throw new InvalidArgumentException('El usuario de baja debe ser un identificador positivo o null.');
        }

        if ($dayOfWeek !== null
            && ($dayOfWeek < self::MIN_DAY_OF_WEEK || $dayOfWeek > self::MAX_DAY_OF_WEEK)) {
            throw new InvalidArgumentException(sprintf(
                'El día de la semana debe estar entre %d y %d (ISO-8601); se recibió %d.',
                self::MIN_DAY_OF_WEEK,
                self::MAX_DAY_OF_WEEK,
                $dayOfWeek
            ));
        }

        $cleanAnchor = self::normalizeDate($anchorDate);
        self::assertCadenceCoherence($cadenceType, $dayOfWeek, $cleanAnchor, $includeWeekends);

        $cleanNotes = self::normalizeText($notes);
        if ($cleanNotes !== null && mb_strlen($cleanNotes, 'UTF-8') > self::MAX_NOTES_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'La nota de la agenda no puede superar %d caracteres reales.',
                self::MAX_NOTES_LENGTH
            ));
        }

        self::assertDeactivationCoherence($isActive, $deactivatedAt, $deactivatedBy);

        $this->id = $id;
        $this->machineId = $machineId;
        $this->cadenceType = $cadenceType;
        $this->dayOfWeek = $dayOfWeek;
        $this->anchorDate = $cleanAnchor;
        $this->preferredTimeWindow = $preferredTimeWindow;
        $this->includeWeekends = $includeWeekends;
        $this->notes = $cleanNotes;
        $this->isActive = $isActive;
        $this->createdBy = $createdBy;
        $this->updatedBy = $updatedBy;
        $this->deactivatedBy = $deactivatedBy;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->deactivatedAt = $deactivatedAt;
    }

    /**
     * Reconstruye la entidad desde una fila del motor.
     *
     * @param array<string, mixed> $row
     *
     * @throws InvalidArgumentException Si la fila no describe una agenda válida.
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (int)($row['machine_id'] ?? 0),
            RestockCadenceType::fromString((string)($row['cadence_type'] ?? 'DAILY')),
            isset($row['day_of_week']) && $row['day_of_week'] !== null ? (int)$row['day_of_week'] : null,
            isset($row['anchor_date']) && $row['anchor_date'] !== null ? (string)$row['anchor_date'] : null,
            RestockTimeWindow::fromString((string)($row['preferred_time_window'] ?? 'ANY')),
            self::toBoolean($row['include_weekends'] ?? false),
            isset($row['notes']) && $row['notes'] !== null ? (string)$row['notes'] : null,
            self::toBoolean($row['is_active'] ?? true),
            (int)($row['created_by'] ?? 0),
            (int)($row['updated_by'] ?? 0),
            isset($row['deactivated_by']) && $row['deactivated_by'] !== null ? (int)$row['deactivated_by'] : null,
            isset($row['created_at']) && $row['created_at'] !== null ? (string)$row['created_at'] : null,
            isset($row['updated_at']) && $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
            isset($row['deactivated_at']) && $row['deactivated_at'] !== null ? (string)$row['deactivated_at'] : null
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

    public function getCadenceType(): RestockCadenceType
    {
        return $this->cadenceType;
    }

    public function getDayOfWeek(): ?int
    {
        return $this->dayOfWeek;
    }

    public function getAnchorDate(): ?string
    {
        return $this->anchorDate;
    }

    public function getPreferredTimeWindow(): RestockTimeWindow
    {
        return $this->preferredTimeWindow;
    }

    public function includesWeekends(): bool
    {
        return $this->includeWeekends;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedBy(): int
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): int
    {
        return $this->updatedBy;
    }

    public function getDeactivatedBy(): ?int
    {
        return $this->deactivatedBy;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function getDeactivatedAt(): ?string
    {
        return $this->deactivatedAt;
    }

    public function isWeekly(): bool
    {
        return $this->cadenceType === RestockCadenceType::WEEKLY;
    }

    public function isBiweekly(): bool
    {
        return $this->cadenceType === RestockCadenceType::BIWEEKLY;
    }

    public function isDaily(): bool
    {
        return $this->cadenceType === RestockCadenceType::DAILY;
    }

    public function withId(int $id): self
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('El identificador de la agenda debe ser un entero positivo.');
        }

        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * Cierra lógicamente la versión vigente (Art. III.3, `QA-2`).
     *
     * La fila cerrada conserva sus datos y sigue siendo referenciable por
     * `machine_restock_visits.route_schedule_id`; la versión nueva se inserta
     * aparte y el índice único parcial garantiza una sola activa por máquina.
     */
    public function deactivate(int $actorId, string $deactivatedAt): self
    {
        if (!$this->isActive) {
            throw new InvalidArgumentException('La agenda ya está cerrada: una versión no se desactiva dos veces.');
        }

        if ($actorId <= 0) {
            throw new InvalidArgumentException('La baja de la agenda exige un usuario responsable.');
        }

        return new self(
            $this->id,
            $this->machineId,
            $this->cadenceType,
            $this->dayOfWeek,
            $this->anchorDate,
            $this->preferredTimeWindow,
            $this->includeWeekends,
            $this->notes,
            false,
            $this->createdBy,
            $this->updatedBy,
            $actorId,
            $this->createdAt,
            $deactivatedAt,
            $deactivatedAt
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
            'cadence_type' => $this->cadenceType->value,
            'cadence_label' => $this->cadenceType->label(),
            'day_of_week' => $this->dayOfWeek,
            'anchor_date' => $this->anchorDate,
            'preferred_time_window' => $this->preferredTimeWindow->value,
            'preferred_time_window_label' => $this->preferredTimeWindow->label(),
            'include_weekends' => $this->includeWeekends,
            'notes' => $this->notes,
            'is_active' => $this->isActive,
            'created_by' => $this->createdBy,
            'updated_by' => $this->updatedBy,
            'deactivated_by' => $this->deactivatedBy,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deactivated_at' => $this->deactivatedAt,
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
     * Misma regla que `chk_mrs_cadence_coherence`, con la prueba de nulidad
     * explícita que el `CHECK` necesita para rechazar de verdad.
     *
     * @throws InvalidArgumentException
     */
    private static function assertCadenceCoherence(
        RestockCadenceType $cadenceType,
        ?int $dayOfWeek,
        ?string $anchorDate,
        bool $includeWeekends
    ): void {
        if ($cadenceType->requiresDayOfWeek() && $dayOfWeek === null) {
            throw new InvalidArgumentException('La cadencia WEEKLY exige day_of_week entre 1 y 7.');
        }

        if ($cadenceType->requiresAnchorDate() && $anchorDate === null) {
            throw new InvalidArgumentException('La cadencia BIWEEKLY exige anchor_date.');
        }

        if (!$cadenceType->requiresDayOfWeek() && $dayOfWeek !== null) {
            throw new InvalidArgumentException(sprintf(
                'day_of_week solo se admite en la cadencia WEEKLY; %s no lo declara.',
                $cadenceType->value
            ));
        }

        if (!$cadenceType->requiresAnchorDate() && $anchorDate !== null) {
            throw new InvalidArgumentException(sprintf(
                'anchor_date solo se admite en la cadencia BIWEEKLY; %s no lo declara.',
                $cadenceType->value
            ));
        }

        if ($includeWeekends && !$cadenceType->allowsWeekends()) {
            throw new InvalidArgumentException('include_weekends solo se admite en la cadencia DAILY.');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertDeactivationCoherence(
        bool $isActive,
        ?string $deactivatedAt,
        ?int $deactivatedBy
    ): void {
        if ($isActive && ($deactivatedAt !== null || $deactivatedBy !== null)) {
            throw new InvalidArgumentException(
                'Una agenda activa no puede declarar deactivated_at ni deactivated_by.'
            );
        }

        if (!$isActive && $deactivatedAt === null) {
            throw new InvalidArgumentException(
                'Una agenda inactiva exige deactivated_at (chk_mrs_deactivation_coherence).'
            );
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);
        if (preg_match(self::DATE_PATTERN, $clean) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "La fecha ancla «%s» debe cumplir el formato Y-m-d.",
                $value
            ));
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $clean);
        if ($parsed === false || $parsed->format('Y-m-d') !== $clean) {
            throw new InvalidArgumentException(sprintf(
                "La fecha ancla «%s» no es una fecha civil válida.",
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
