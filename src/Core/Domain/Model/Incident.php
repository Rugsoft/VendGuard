<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use ArrayAccess;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

/**
 * Incident
 * 
 * Entidad Raíz de Agregado que modela una avería o incidencia en una máquina de vending.
 * Incorpora reglas de negocio, ciclo de vida, trazabilidad y enriquecimiento relacional.
 */
class Incident implements ArrayAccess, JsonSerializable
{
    /**
     * Ventana mínima de justificación real de la pausa (RF-01.3, Art. V.1).
     * Espejo de `IncidentPauseRequestDto::MIN_REASON_TEXT_LENGTH`: el DTO la aplica
     * al cuerpo HTTP crudo y la entidad la vuelve a exigir al cambiar de estado.
     */
    public const MIN_PAUSE_REASON_LENGTH = 20;

    private ?int $id;
    private string $ticketCode;
    private int $machineId;
    private int $locationId;
    private ?int $assignedTechnicianId;
    private ?string $reporterName;
    private ?string $reporterPhone;
    private IncidentCategory $category;
    private string $description;
    private ?float $retainedMoneyAmount;
    private ?string $photoPath;
    private UrgencyLevel $urgency;
    private IncidentStatus $status;
    private ?string $assignedAt;
    private ?string $startedAt;
    private ?string $pendingPartsReason;
    private ?string $resolutionDiagnosis;
    private ?string $resolutionAction;
    private ?string $resolvedAt;
    private ?string $reopenReason;
    private ?string $reopenedAt;
    private ?string $closedAt;
    private ?string $cancellationReason;
    private ?string $cancelledAt;
    private ?int $isActiveTicket;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $deletedAt;

    // Campos del estado operativo "Pendiente de Información" con pausa de SLA (módulo 11)
    private ?IncidentPauseReasonCategory $pendingInfoReasonCategory;
    private ?string $pendingInfoReasonText;
    private ?string $pausedAt;
    private int $totalPendingInfoSeconds;
    private ?string $slaTargetAt;

    // Campos enriquecidos opcionales (vía JOIN)
    private ?string $machineCode;
    private ?string $machineModel;
    private ?string $machineType;
    private ?string $locationName;
    private ?string $locationSiteCode;
    private ?string $technicianName;
    private ?string $technicianOperatorCode;

    public function __construct(
        ?int $id,
        string $ticketCode,
        int $machineId,
        int $locationId,
        IncidentCategory $category,
        string $description,
        UrgencyLevel $urgency,
        IncidentStatus $status = IncidentStatus::REGISTERED,
        ?int $assignedTechnicianId = null,
        ?string $reporterName = null,
        ?string $reporterPhone = null,
        ?float $retainedMoneyAmount = null,
        ?string $photoPath = null,
        ?string $assignedAt = null,
        ?string $startedAt = null,
        ?string $pendingPartsReason = null,
        ?string $resolutionDiagnosis = null,
        ?string $resolutionAction = null,
        ?string $resolvedAt = null,
        ?string $reopenReason = null,
        ?string $reopenedAt = null,
        ?string $closedAt = null,
        ?string $cancellationReason = null,
        ?string $cancelledAt = null,
        ?int $isActiveTicket = 1,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $deletedAt = null,
        ?string $machineCode = null,
        ?string $machineModel = null,
        ?string $machineType = null,
        ?string $locationName = null,
        ?string $locationSiteCode = null,
        ?string $technicianName = null,
        ?string $technicianOperatorCode = null,
        ?IncidentPauseReasonCategory $pendingInfoReasonCategory = null,
        ?string $pendingInfoReasonText = null,
        ?string $pausedAt = null,
        int $totalPendingInfoSeconds = 0,
        ?string $slaTargetAt = null
    ) {
        $this->id = $id;
        $this->ticketCode = strtoupper(trim($ticketCode));
        $this->machineId = $machineId;
        $this->locationId = $locationId;
        $this->category = $category;
        $this->description = trim($description);
        $this->urgency = $urgency;
        $this->status = $status;
        $this->assignedTechnicianId = $assignedTechnicianId;
        $this->reporterName = $reporterName !== null ? trim($reporterName) : null;
        $this->reporterPhone = $reporterPhone !== null ? trim($reporterPhone) : null;
        $this->retainedMoneyAmount = $retainedMoneyAmount;
        $this->photoPath = $photoPath;
        $this->assignedAt = $assignedAt;
        $this->startedAt = $startedAt;
        $this->pendingPartsReason = $pendingPartsReason;
        $this->resolutionDiagnosis = $resolutionDiagnosis;
        $this->resolutionAction = $resolutionAction;
        $this->resolvedAt = $resolvedAt;
        $this->reopenReason = $reopenReason;
        $this->reopenedAt = $reopenedAt;
        $this->closedAt = $closedAt;
        $this->cancellationReason = $cancellationReason;
        $this->cancelledAt = $cancelledAt;
        $this->isActiveTicket = $isActiveTicket;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->deletedAt = $deletedAt;
        $this->machineCode = $machineCode;
        $this->machineModel = $machineModel;
        $this->machineType = $machineType;
        $this->locationName = $locationName;
        $this->locationSiteCode = $locationSiteCode;
        $this->technicianName = $technicianName;
        $this->technicianOperatorCode = $technicianOperatorCode;
        $this->pendingInfoReasonCategory = $pendingInfoReasonCategory;
        $this->pendingInfoReasonText = $pendingInfoReasonText !== null ? trim($pendingInfoReasonText) : null;
        $this->pausedAt = $pausedAt;

        // El acumulador nace a cero y sólo crece (RF-04.1): un valor negativo
        // delataría una resta invertida y corrompería el descuento de MTTR, así
        // que se rechaza en el borde de la entidad. La longitud de la
        // justificación NO se valida aquí a propósito: la entidad debe poder
        // hidratar cualquier fila persistida (histórico, tickets reanudados) sin
        // reventar en lectura; esa regla muerde en `pausePendingInfo()`.
        if ($totalPendingInfoSeconds < 0) {
            throw new InvalidArgumentException('El tiempo acumulado en "Pendiente de Información" no puede ser negativo.');
        }

        $this->totalPendingInfoSeconds = $totalPendingInfoSeconds;
        $this->slaTargetAt = $slaTargetAt;
    }

    /**
     * Factoría para reconstruir la entidad desde una fila asociativa de base de datos.
     *
     * @param array<string, mixed> $row
     * @return self
     */
    public static function fromDatabaseRow(array $row): self
    {
        $category = IncidentCategory::fromString((string)$row['category']);
        $urgency = UrgencyLevel::fromString((string)$row['urgency']);
        $status = IncidentStatus::fromString((string)$row['status']);

        return new self(
            isset($row['id']) ? (int)$row['id'] : null,
            (string)$row['ticket_code'],
            (int)$row['machine_id'],
            (int)$row['location_id'],
            $category,
            (string)$row['description'],
            $urgency,
            $status,
            isset($row['assigned_technician_id']) && $row['assigned_technician_id'] !== null ? (int)$row['assigned_technician_id'] : null,
            isset($row['reporter_name']) && $row['reporter_name'] !== null ? (string)$row['reporter_name'] : null,
            isset($row['reporter_phone']) && $row['reporter_phone'] !== null ? (string)$row['reporter_phone'] : null,
            isset($row['retained_money_amount']) && $row['retained_money_amount'] !== null ? (float)$row['retained_money_amount'] : null,
            isset($row['photo_path']) && $row['photo_path'] !== null ? (string)$row['photo_path'] : null,
            isset($row['assigned_at']) && $row['assigned_at'] !== null ? (string)$row['assigned_at'] : null,
            isset($row['started_at']) && $row['started_at'] !== null ? (string)$row['started_at'] : null,
            isset($row['pending_parts_reason']) && $row['pending_parts_reason'] !== null ? (string)$row['pending_parts_reason'] : null,
            isset($row['resolution_diagnosis']) && $row['resolution_diagnosis'] !== null ? (string)$row['resolution_diagnosis'] : null,
            isset($row['resolution_action']) && $row['resolution_action'] !== null ? (string)$row['resolution_action'] : null,
            isset($row['resolved_at']) && $row['resolved_at'] !== null ? (string)$row['resolved_at'] : null,
            isset($row['reopen_reason']) && $row['reopen_reason'] !== null ? (string)$row['reopen_reason'] : null,
            isset($row['reopened_at']) && $row['reopened_at'] !== null ? (string)$row['reopened_at'] : null,
            isset($row['closed_at']) && $row['closed_at'] !== null ? (string)$row['closed_at'] : null,
            isset($row['cancellation_reason']) && $row['cancellation_reason'] !== null ? (string)$row['cancellation_reason'] : null,
            isset($row['cancelled_at']) && $row['cancelled_at'] !== null ? (string)$row['cancelled_at'] : null,
            isset($row['is_active_ticket']) && $row['is_active_ticket'] !== null ? (int)$row['is_active_ticket'] : null,
            isset($row['created_at']) ? (string)$row['created_at'] : null,
            isset($row['updated_at']) ? (string)$row['updated_at'] : null,
            isset($row['deleted_at']) && $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            isset($row['machine_code']) && $row['machine_code'] !== null ? (string)$row['machine_code'] : null,
            isset($row['machine_model']) && $row['machine_model'] !== null ? (string)$row['machine_model'] : null,
            isset($row['machine_type']) && $row['machine_type'] !== null ? (string)$row['machine_type'] : null,
            isset($row['location_name']) && $row['location_name'] !== null ? (string)$row['location_name'] : null,
            isset($row['location_site_code']) && $row['location_site_code'] !== null ? (string)$row['location_site_code'] : null,
            isset($row['technician_name']) && $row['technician_name'] !== null ? (string)$row['technician_name'] : null,
            isset($row['technician_operator_code']) && $row['technician_operator_code'] !== null ? (string)$row['technician_operator_code'] : null,
            isset($row['pending_info_reason_category']) && $row['pending_info_reason_category'] !== null && $row['pending_info_reason_category'] !== ''
                ? IncidentPauseReasonCategory::fromString((string)$row['pending_info_reason_category'])
                : null,
            isset($row['pending_info_reason_text']) && $row['pending_info_reason_text'] !== null ? (string)$row['pending_info_reason_text'] : null,
            isset($row['paused_at']) && $row['paused_at'] !== null ? (string)$row['paused_at'] : null,
            isset($row['total_pending_info_seconds']) ? (int)$row['total_pending_info_seconds'] : 0,
            isset($row['sla_target_at']) && $row['sla_target_at'] !== null ? (string)$row['sla_target_at'] : null
        );
    }

    /**
     * Retorna una nueva instancia con el identificador asignado tras la inserción.
     */
    public function withId(int $id): self
    {
        $clone = clone $this;
        $clone->id = $id;
        return $clone;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicketCode(): string
    {
        return $this->ticketCode;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function getAssignedTechnicianId(): ?int
    {
        return $this->assignedTechnicianId;
    }

    public function getReporterName(): ?string
    {
        return $this->reporterName;
    }

    public function getReporterPhone(): ?string
    {
        return $this->reporterPhone;
    }

    public function getCategory(): IncidentCategory
    {
        return $this->category;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getRetainedMoneyAmount(): ?float
    {
        return $this->retainedMoneyAmount;
    }

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function getUrgency(): UrgencyLevel
    {
        return $this->urgency;
    }

    public function getStatus(): IncidentStatus
    {
        return $this->status;
    }

    public function getAssignedAt(): ?string
    {
        return $this->assignedAt;
    }

    public function getStartedAt(): ?string
    {
        return $this->startedAt;
    }

    public function getPendingPartsReason(): ?string
    {
        return $this->pendingPartsReason;
    }

    public function getResolutionDiagnosis(): ?string
    {
        return $this->resolutionDiagnosis;
    }

    public function getResolutionAction(): ?string
    {
        return $this->resolutionAction;
    }

    public function getResolvedAt(): ?string
    {
        return $this->resolvedAt;
    }

    public function getReopenReason(): ?string
    {
        return $this->reopenReason;
    }

    public function getReopenedAt(): ?string
    {
        return $this->reopenedAt;
    }

    public function getClosedAt(): ?string
    {
        return $this->closedAt;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function getCancelledAt(): ?string
    {
        return $this->cancelledAt;
    }

    public function getIsActiveTicket(): ?int
    {
        return $this->isActiveTicket;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?string
    {
        return $this->deletedAt;
    }

    public function getMachineCode(): ?string
    {
        return $this->machineCode;
    }

    public function getMachineModel(): ?string
    {
        return $this->machineModel;
    }

    public function getMachineType(): ?string
    {
        return $this->machineType;
    }

    public function getLocationName(): ?string
    {
        return $this->locationName;
    }

    public function getLocationSiteCode(): ?string
    {
        return $this->locationSiteCode;
    }

    public function getTechnicianName(): ?string
    {
        return $this->technicianName;
    }

    public function getTechnicianOperatorCode(): ?string
    {
        return $this->technicianOperatorCode;
    }

    /**
     * Comprueba si la incidencia está activa (bloqueando nuevos avisos en la máquina).
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isResolved(): bool
    {
        return $this->status->isResolved();
    }

    public function isClosed(): bool
    {
        return $this->status === IncidentStatus::CLOSED;
    }

    /**
     * Indica si la incidencia está en el estado operativo "Pendiente de Información".
     */
    public function isPendingInfo(): bool
    {
        return $this->status === IncidentStatus::PENDING_INFO;
    }

    /**
     * Indica si la incidencia tiene un intervalo de pausa abierto en este momento.
     *
     * `paused_at` es la marca autoritativa del intervalo vivo: se escribe al
     * pausar y se limpia al reanudar (Algoritmo 2 del plan técnico).
     */
    public function isPaused(): bool
    {
        return $this->pausedAt !== null;
    }

    /**
     * Causa tipificada del bloqueo de sede (RF-01.2). Conserva el último motivo
     * conocido tras la reanudación; la bitácora inmutable de cada intervalo vive
     * en `incident_history` (Art. III, T-PAUSE-05).
     */
    public function getPendingInfoReasonCategory(): ?IncidentPauseReasonCategory
    {
        return $this->pendingInfoReasonCategory;
    }

    /**
     * Justificación textual del bloqueo (RF-01.3, Art. V.1).
     */
    public function getPendingInfoReasonText(): ?string
    {
        return $this->pendingInfoReasonText;
    }

    /**
     * Marca temporal del inicio del intervalo de pausa vivo, o `null` si no hay pausa.
     */
    public function getPausedAt(): ?string
    {
        return $this->pausedAt;
    }

    /**
     * Segundos acumulados en `PENDING_INFO` sumando todos los intervalos del ticket
     * (RF-03.1, RF-04.1). Es la cifra exacta que MTTR y el SLA contractual descuentan.
     */
    public function getTotalPendingInfoSeconds(): int
    {
        return $this->totalPendingInfoSeconds;
    }

    /**
     * Fecha límite contractual persistida, ya desplazada en horario comercial de la
     * sede (RF-03.3). `null` mientras el ticket nunca haya sufrido una pausa.
     */
    public function getSlaTargetAt(): ?string
    {
        return $this->slaTargetAt;
    }

    /**
     * Duración viva del intervalo de pausa abierto respecto a un instante de referencia.
     *
     * Alimenta tres lecturas del módulo: el reloj contractual visualmente congelado
     * (RF-03.2), la evaluación de la reactivación en caliente de 60 minutos (RF-02.1)
     * y el cómputo del desplazamiento comercial de SLA (RF-03.3). Sin pausa abierta
     * devuelve 0 y nunca un valor negativo, ni siquiera si la referencia es anterior
     * al inicio de la pausa (relojes desincronizados).
     */
    public function currentPauseDurationSeconds(DateTimeImmutable $reference): int
    {
        if (!$this->isPaused()) {
            return 0;
        }

        $pauseStartedAt = strtotime((string)$this->pausedAt);
        if ($pauseStartedAt === false) {
            return 0;
        }

        return max(0, $reference->getTimestamp() - $pauseStartedAt);
    }

    /**
     * Declara la pausa por bloqueo imputable a la sede y devuelve una NUEVA instancia
     * (RF-01.1, RF-01.2, RF-01.3, RF-01.4).
     *
     * Reglas que muerde este método:
     * 1. La transición debe ser legal según `IncidentStatus::canTransitionTo()`:
     *    sólo se pausa desde `ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS` o `REOPENED`,
     *    lo que además impide una doble pausa (PENDING_INFO -> PENDING_INFO).
     * 2. La justificación debe contener al menos 20 caracteres reales medidos con
     *    `mb_strlen()` sobre el texto recortado (Art. V.1).
     *
     * La instancia receptora no muta: el estado persistido sólo cambia cuando el
     * repositorio graba el resultado (T-PAUSE-05).
     *
     * @throws InvalidTransitionException si el estado de origen no admite la pausa.
     * @throws InvalidArgumentException si la justificación no alcanza el mínimo legal.
     */
    public function pausePendingInfo(
        IncidentPauseReasonCategory $reasonCategory,
        string $reasonText,
        ?DateTimeImmutable $pausedAt = null
    ): self {
        if (!$this->status->canTransitionTo(IncidentStatus::PENDING_INFO)) {
            throw new InvalidTransitionException(
                sprintf(
                    'No es legal pausar a "Pendiente de Información" desde el estado %s.',
                    $this->status->value
                ),
                $this->status,
                IncidentStatus::PENDING_INFO
            );
        }

        $normalizedReason = trim($reasonText);
        if (mb_strlen($normalizedReason) < self::MIN_PAUSE_REASON_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'La justificación de la pausa debe contener al menos %d caracteres reales; se recibieron %d.',
                self::MIN_PAUSE_REASON_LENGTH,
                mb_strlen($normalizedReason)
            ));
        }

        $clone = clone $this;
        $clone->status = IncidentStatus::PENDING_INFO;
        $clone->pendingInfoReasonCategory = $reasonCategory;
        $clone->pendingInfoReasonText = $normalizedReason;
        $clone->pausedAt = ($pausedAt ?? new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $clone;
    }

    /**
     * Cierra el intervalo de pausa vivo, acumula su duración y reingresa el ticket
     * al flujo operativo (RF-02.3, RF-04.1, RF-06.2).
     *
     * El destino sólo puede ser `IN_PROGRESS` (técnico in situ o reanudación en
     * caliente) o `ASSIGNED` (la respuesta llega tarde o el ticket fue reasignado);
     * `RESOLVED` queda expresamente prohibido (RF-06.2, Art. V.1).
     *
     * No desplaza `sla_target_at`: el cálculo del horario comercial depende del
     * calendario de la sede y vive en `IncidentPauseService` (T-PAUSE-06), que
     * encadena este método con `shiftSlaTarget()`. La causa y el texto de la pausa
     * se conservan como último bloqueo conocido; la auditoría inmutable de cada
     * intervalo la graba el repositorio en `incident_history` (Art. III).
     *
     * @throws InvalidTransitionException si no hay pausa abierta o el destino no es legal.
     */
    public function resumePendingInfo(
        IncidentStatus $targetStatus,
        ?DateTimeImmutable $resumedAt = null
    ): self {
        $resumedMoment = $resumedAt ?? new DateTimeImmutable();

        if (!$this->isPaused()) {
            throw new InvalidTransitionException(
                'La incidencia no tiene ninguna pausa abierta que reanudar.',
                $this->status,
                $targetStatus
            );
        }

        if (!$this->status->canTransitionTo($targetStatus)
            || !in_array($targetStatus, [IncidentStatus::IN_PROGRESS, IncidentStatus::ASSIGNED], true)
        ) {
            throw new InvalidTransitionException(
                sprintf(
                    'La reanudación sólo admite los estados %s o %s; se solicitó %s.',
                    IncidentStatus::IN_PROGRESS->value,
                    IncidentStatus::ASSIGNED->value,
                    $targetStatus->value
                ),
                $this->status,
                $targetStatus
            );
        }

        $clone = clone $this;
        $clone->status = $targetStatus;
        $clone->totalPendingInfoSeconds += $this->currentPauseDurationSeconds($resumedMoment);
        $clone->pausedAt = null;

        return $clone;
    }

    /**
     * Registra la nueva fecha límite contractual de SLA (RF-03.3).
     *
     * Este método es un mero grabador del resultado: el desplazamiento en ventana
     * comercial (08:00 a 18:00, lunes a viernes, saltando noches y fines de semana)
     * lo calcula el Algoritmo 3 en `IncidentPauseService` (T-PAUSE-06), que es quien
     * conoce la sede y su calendario. Se permite fijar la fecha aunque el ticket no
     * tuviera ninguna previa, para los tickets sin SLA contractual calculado.
     */
    public function shiftSlaTarget(DateTimeImmutable $newSlaTargetAt): self
    {
        $clone = clone $this;
        $clone->slaTargetAt = $newSlaTargetAt->format('Y-m-d H:i:s');

        return $clone;
    }

    public function isCancelled(): bool
    {
        return $this->status === IncidentStatus::CANCELLED;
    }

    /**
     * Valida si el ticket se encuentra dentro de la ventana de garantía legal (48 horas tras resolución).
     *
     * @param int $hours Ventana en horas (por defecto 48 según Art. V Constitución).
     * @return bool
     */
    public function isInWarranty(int $hours = 48): bool
    {
        if ($this->status !== IncidentStatus::RESOLVED || $this->resolvedAt === null) {
            return false;
        }

        $resolvedTime = strtotime($this->resolvedAt);
        if ($resolvedTime === false) {
            return false;
        }

        $now = time();
        $diffSeconds = $now - $resolvedTime;

        return $diffSeconds >= 0 && $diffSeconds <= ($hours * 3600);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ticket_code' => $this->ticketCode,
            'machine_id' => $this->machineId,
            'location_id' => $this->locationId,
            'assigned_technician_id' => $this->assignedTechnicianId,
            'reporter_name' => $this->reporterName,
            'reporter_phone' => $this->reporterPhone,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'description' => $this->description,
            'retained_money_amount' => $this->retainedMoneyAmount,
            'photo_path' => $this->photoPath,
            'urgency' => $this->urgency->value,
            'urgency_label' => $this->urgency->label(),
            'urgency_color' => $this->urgency->color(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'assigned_at' => $this->assignedAt,
            'started_at' => $this->startedAt,
            'pending_parts_reason' => $this->pendingPartsReason,
            'resolution_diagnosis' => $this->resolutionDiagnosis,
            'resolution_action' => $this->resolutionAction,
            'resolved_at' => $this->resolvedAt,
            'reopen_reason' => $this->reopenReason,
            'reopened_at' => $this->reopenedAt,
            'closed_at' => $this->closedAt,
            'cancellation_reason' => $this->cancellationReason,
            'cancelled_at' => $this->cancelledAt,
            'is_active_ticket' => $this->isActiveTicket,
            'is_active' => $this->isActive(),
            'is_in_warranty' => $this->isInWarranty(),
            'pending_info_reason_category' => $this->pendingInfoReasonCategory?->value,
            'pending_info_reason_category_label' => $this->pendingInfoReasonCategory?->label(),
            'pending_info_reason_text' => $this->pendingInfoReasonText,
            'paused_at' => $this->pausedAt,
            'is_paused' => $this->isPaused(),
            'total_pending_info_seconds' => $this->totalPendingInfoSeconds,
            'sla_target_at' => $this->slaTargetAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
            // Campos de visualización joined
            'machine_code' => $this->machineCode,
            'machine_model' => $this->machineModel,
            'machine_type' => $this->machineType,
            'location_name' => $this->locationName,
            'location_site_code' => $this->locationSiteCode,
            'technician_name' => $this->technicianName,
        ];
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
