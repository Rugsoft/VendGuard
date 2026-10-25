<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use PDO;
use VendGuard\Application\DTO\ShiftHandoverResultDto;
use VendGuard\Application\DTO\TechnicianShiftDto;
use VendGuard\Core\Domain\Exception\ActiveIncidentInProgressException;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Exception\JustificationTooShortException;
use VendGuard\Core\Domain\Exception\ShiftAdjustmentValidationException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRowLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\OnCallTechnicianRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\ShiftEventType;

/**
 * ShiftHandoverService
 *
 * Servicio de Aplicación responsable de ejecutar la transacción atómica de fin de turno,
 * traspaso de averías a compañeros y protocolo sanitario de frío (Art. II / Módulo 21).
 *
 * Mandatos constitucionales:
 *   - Art. II: Salvaguarda innegociable de cadena de frío en máquinas de perecederos.
 *   - Art. III.1: Cero borrado físico, inmutabilidad y atomicidad transaccional.
 *   - Art. V.4: Notas internas de traspaso estrictamente invisibles para la sede (is_internal = TRUE).
 *
 * Un solo motor de cierre:
 *   - `closeAndHandover()` valida el relevo y llama al núcleo.
 *   - `forceCloseShift()` valida la autoridad de Coordinación y llama al MISMO núcleo.
 *
 * Compartir el núcleo no es una comodidad: es la única forma de garantizar que el
 * cierre forzado no pueda olvidar la guardia de `IN_PROGRESS` ni el protocolo
 * sanitario, que son precisamente los dos defectos que la batería funcional
 * encontró en la implementación anterior.
 */
final class ShiftHandoverService
{
    public const MIN_HANDOVER_NOTES_LENGTH = 10;

    public const MIN_FORCE_CLOSE_REASON_LENGTH = 10;

    public function __construct(
        private readonly PDO $pdo,
        private readonly TechnicianShiftRepositoryInterface $shiftRepo,
        private readonly UserRepositoryInterface $userRepo,
        private readonly IncidentRepositoryInterface $incidentRepo,
        private readonly MachineRepositoryInterface $machineRepo,
        private readonly OnCallTechnicianRepositoryInterface $onCallRepo,
        private readonly AuditLogRepositoryInterface $auditRepo,
        private readonly HandoverTargetService $handoverTargets,
        private readonly ?NotificationOutboxEnqueuerService $notifications = null,
        private readonly ?TechnicianShiftLogRepositoryInterface $shiftLogRepo = null
    ) {
    }

    /**
     * Cierre de jornada del técnico con relevo opcional (RF-05 y RF-06).
     *
     * @param list<int> $incidentIdsToTransfer
     */
    public function closeAndHandover(
        int $technicianId,
        ?int $targetTechnicianId = null,
        array $incidentIdsToTransfer = [],
        ?string $handoverNotes = null,
        ?string $closeReason = null
    ): ShiftHandoverResultDto {
        $tech = $this->userRepo->findById($technicianId);
        if ($tech === null) {
            throw new UserNotFoundException($technicianId, "No se encontró el técnico con ID {$technicianId}.");
        }

        // 1. Precondición de turno abierto
        $activeShift = $this->shiftRepo->findActiveShiftByTechnician($technicianId);
        if ($activeShift === null || !$activeShift->isOpen()) {
            throw new InvalidShiftTransitionException(
                'El turno no existe o ya se encuentra cerrado (OFF_SHIFT).',
                'SHIFT_ALREADY_CLOSED',
                422
            );
        }

        // 2. Guardia fail-fast antes de validar el relevo (RF-04 / contrato §5)
        $this->assertNoInProgress($technicianId);

        // 3. Validación de relevo (RF-05)
        $targetTech = null;
        $cleanNotes = null;
        $hasTransfers = count($incidentIdsToTransfer) > 0;
        $hasTarget = $targetTechnicianId !== null;

        // Un receptor sin averías no es un traspaso: es un cierre sin relevo
        // mal formado. Se rechaza antes de tocar la ruta.
        if ($hasTarget && !$hasTransfers) {
            throw new InvalidShiftTransitionException(
                'Debe indicar al menos una avería para traspasar al técnico de relevo.',
                'TARGET_WITHOUT_TRANSFERS',
                422
            );
        }

        if ($hasTransfers && !$hasTarget) {
            throw new InvalidShiftTransitionException(
                'Debe indicar un técnico de relevo para traspasar averías.',
                'TARGET_TECHNICIAN_REQUIRED',
                422
            );
        }

        if ($hasTarget) {
            if ($targetTechnicianId === $technicianId) {
                throw new InvalidShiftTransitionException(
                    'El técnico de relevo no puede ser el mismo técnico saliente.',
                    'INVALID_TARGET_TECHNICIAN',
                    422
                );
            }

            $targetTech = $this->userRepo->findById($targetTechnicianId);
            if ($targetTech === null || !$targetTech->isActive() || $targetTech->getRole() !== UserRole::TECHNICIAN) {
                throw new InvalidShiftTransitionException(
                    "No se encontró el técnico de relevo con ID {$targetTechnicianId}.",
                    'TARGET_TECHNICIAN_NOT_FOUND',
                    404
                );
            }

            // El receptor debe estar disponible: ON_SHIFT —la presencia física
            // gana a una ausencia planificada (R4)— o guardia 24/7 sin ausencia
            // ni pausa. Un OFF_SHIFT sin guardia no recibe.
            $this->handoverTargets->assertCanReceiveHandover($targetTechnicianId);

            $cleanNotes = trim((string)$handoverNotes);
            if (mb_strlen($cleanNotes) < self::MIN_HANDOVER_NOTES_LENGTH) {
                throw new JustificationTooShortException(
                    $cleanNotes,
                    'La nota de traspaso debe tener al menos 10 caracteres.'
                );
            }
        }

        return $this->executeClose(
            technician: $tech,
            activeShift: $activeShift,
            targetTechnician: $targetTech,
            incidentIdsToTransfer: $incidentIdsToTransfer,
            handoverNotes: $cleanNotes,
            closeReason: $closeReason,
            closedBy: $technicianId,
            auditAction: 'CLOSE_TECHNICIAN_SHIFT'
        );
    }

    /**
     * Cierre administrativo forzado por Coordinación (RF-07).
     *
     * Aplica exactamente las mismas precondiciones y el mismo protocolo sanitario
     * que el cierre del técnico. La única diferencia es quién firma el cierre y el
     * evento de auditoría.
     */
    public function forceCloseShift(
        int $technicianId,
        int $coordinatorId,
        string $reason,
        ?string $effectiveClosedAtRaw = null
    ): ShiftHandoverResultDto {
        $tech = $this->userRepo->findById($technicianId);
        if ($tech === null) {
            throw new UserNotFoundException($technicianId, "No se encontró el técnico con ID {$technicianId}.");
        }

        $activeShift = $this->shiftRepo->findActiveShiftByTechnician($technicianId);
        if ($activeShift === null || !$activeShift->isOpen()) {
            throw new InvalidShiftTransitionException(
                'El técnico no tiene ningún turno abierto para cerrar.',
                'NO_OPEN_SHIFT_TO_CLOSE',
                409
            );
        }

        // Contrato §5: turno, averías en curso y después motivo.
        $this->assertNoInProgress($technicianId);

        $cleanReason = trim($reason);
        if (mb_strlen($cleanReason) < self::MIN_FORCE_CLOSE_REASON_LENGTH) {
            throw new JustificationTooShortException(
                $cleanReason,
                'El motivo de cierre forzado debe contener un mínimo de 10 caracteres descriptivos.'
            );
        }

        $coordinator = $this->userRepo->findById($coordinatorId);

        // El protocolo sanitario del Art. II ocurre siempre en el presente: la
        // hora efectiva corregida solo mueve las marcas laborales del turno.
        $executionTime = new DateTimeImmutable('now');
        $effectiveClosedAt = $this->resolveEffectiveClose(
            $activeShift,
            $effectiveClosedAtRaw,
            $cleanReason,
            $executionTime
        );

        return $this->executeClose(
            technician: $tech,
            activeShift: $activeShift,
            targetTechnician: null,
            incidentIdsToTransfer: [],
            handoverNotes: null,
            closeReason: $cleanReason,
            closedBy: $coordinatorId,
            auditAction: 'FORCE_CLOSE_TECHNICIAN_SHIFT',
            actorRole: $coordinator?->getRole()->value ?? 'COORDINATOR',
            actorName: $coordinator?->getName() ?? 'Coordinador',
            auditMetadata: [
                'coordinator_name' => $coordinator?->getName() ?? 'Coordinador',
                'coordinator_reason' => $cleanReason,
                'effective_closed_at' => $effectiveClosedAt?->format(DateTimeImmutable::ATOM),
                'execution_time' => $executionTime->format(DateTimeImmutable::ATOM),
            ],
            effectiveClosedAt: $effectiveClosedAt,
            isForceClosed: true,
            executionTime: $executionTime
        );
    }

    /**
     * Resuelve y valida la hora efectiva de salida de un cierre forzado
     * (contrato técnico §5.5, Resolución QA 2).
     *
     * La ventana se comprueba contra las marcas YA registradas del turno —inicio
     * y pausas— y contra los demás turnos del técnico; la fecha de ejecución real
     * no se toca nunca, porque es la que sella el protocolo sanitario.
     */
    private function resolveEffectiveClose(
        TechnicianShift $activeShift,
        ?string $effectiveClosedAtRaw,
        string $cleanReason,
        DateTimeImmutable $executionTime
    ): ?DateTimeImmutable {
        $raw = $effectiveClosedAtRaw !== null ? trim($effectiveClosedAtRaw) : '';
        if ($raw === '') {
            return null;
        }

        // Art. V.1: una hora efectiva reescribe la jornada y exige justificación
        // real de 20 caracteres, no la descripción mínima de un cierre normal.
        if (mb_strlen($cleanReason) < ShiftAdjustmentValidationException::MINIMUM_REASON_LENGTH) {
            throw ShiftAdjustmentValidationException::reasonTooShort();
        }

        try {
            $effectiveClosedAt = new DateTimeImmutable($raw);
        } catch (\Exception) {
            throw ShiftAdjustmentValidationException::invalidCloseOrder();
        }

        if ($effectiveClosedAt > $executionTime) {
            throw ShiftAdjustmentValidationException::futureTimestampNotAllowed();
        }

        if ($effectiveClosedAt <= $activeShift->startedAt()) {
            throw ShiftAdjustmentValidationException::invalidCloseOrder();
        }

        $lastActivity = $activeShift->startedAt();
        foreach ([$activeShift->mealBreakStartedAt(), $activeShift->mealBreakEndedAt()] as $mark) {
            if ($mark !== null && $mark > $lastActivity) {
                $lastActivity = $mark;
            }
        }

        $grossSeconds = $effectiveClosedAt->getTimestamp() - $activeShift->startedAt()->getTimestamp();
        if ($effectiveClosedAt < $lastActivity || $grossSeconds < $activeShift->mealBreakSeconds()) {
            throw ShiftAdjustmentValidationException::effectiveCloseBeforeLastActivity();
        }

        if ($grossSeconds > ShiftAdjustmentValidationException::MAX_SHIFT_DURATION_SECONDS) {
            throw ShiftAdjustmentValidationException::exceedsMaxShiftDuration();
        }

        $conflictId = $this->shiftRepo->findOverlappingShiftId(
            $activeShift->technicianId(),
            $activeShift->startedAt(),
            $effectiveClosedAt,
            $activeShift->id()
        );
        if ($conflictId !== null) {
            throw ShiftAdjustmentValidationException::overlappingShift($conflictId);
        }

        return $effectiveClosedAt;
    }

    /**
     * Núcleo transaccional único del cierre de jornada.
     *
     * Los llamadores ya validaron el turno y la guardia de `IN_PROGRESS` para
     * preservar la precedencia de errores del contrato; aquí se repite esa guardia
     * sobre las filas YA BLOQUEADAS, se resuelve la foto de la ruta, se aplica el
     * relevo o el protocolo sanitario, se cierra el turno y se encolan los avisos
     * en la misma transacción.
     *
     * @param list<int> $incidentIdsToTransfer
     * @param array<string, mixed> $auditMetadata
     */
    private function executeClose(
        User $technician,
        TechnicianShift $activeShift,
        ?User $targetTechnician = null,
        array $incidentIdsToTransfer = [],
        ?string $handoverNotes = null,
        ?string $closeReason = null,
        int $closedBy = 0,
        string $auditAction = 'CLOSE_TECHNICIAN_SHIFT',
        string $actorRole = 'TECHNICIAN',
        ?string $actorName = null,
        array $auditMetadata = [],
        ?DateTimeImmutable $effectiveClosedAt = null,
        bool $isForceClosed = false,
        ?DateTimeImmutable $executionTime = null
    ): ShiftHandoverResultDto {
        $technicianId = (int)$technician->getId();
        $targetTechnicianId = $targetTechnician?->getId();

        $this->pdo->beginTransaction();

        try {
            // Cerrojo de ruta (RF-05.3 / R9): se bloquean todas las averías
            // activas de la ruta —incluida IN_PROGRESS, no solo las elegibles—
            // dentro de la transacción. Así la guardia de IN_PROGRESS y la foto de
            // la ruta se leen sobre filas que nadie puede cambiar hasta el COMMIT.
            // La foto previa a la transacción ya no decide el relevo: si el ticket
            // cambió, la lista bloqueada lo refleja y la solicitud se rechaza con 409.
            $lockedIncidents = $this->lockAssignedIncidents($technicianId);

            $eligibleMap = [];
            $blockingCodes = [];
            foreach ($lockedIncidents as $incident) {
                $status = $incident->getStatus()->value;

                if ($status === IncidentStatus::IN_PROGRESS->value) {
                    $blockingCodes[] = (string)$incident->getTicketCode();
                }

                if (in_array($status, ['ASSIGNED', 'PENDING_PARTS', 'PENDING_INFO'], true)) {
                    $eligibleMap[(int)$incident->getId()] = $incident;
                }
            }

            // Repetición de la guardia de RF-04 con las filas ya bloqueadas: cierra
            // la ventana entre el fail-fast de los llamadores y el inicio del relevo.
            if ($blockingCodes !== []) {
                throw new ActiveIncidentInProgressException($blockingCodes);
            }

            // Detección de colisión concurrente (RF-05.3, R9): la lista solicitada
            // se contrasta con las filas bloqueadas, no con una foto anterior.
            foreach ($incidentIdsToTransfer as $transferId) {
                if (!isset($eligibleMap[$transferId])) {
                    throw new InvalidShiftTransitionException(
                        "El ticket con ID {$transferId} ya no está asignado al técnico o cambió de estado.",
                        'CONCURRENT_ROUTE_MODIFICATION',
                        409
                    );
                }
            }

            $transferred = [];
            $retained = [];
            $sanitaryTransfers = [];
            $sanitaryUnassigned = [];

            // 1. Relevo explícito de averías seleccionadas.
            foreach ($incidentIdsToTransfer as $id) {
                $inc = $eligibleMap[$id];
                $after = $this->incidentRepo->assign(
                    incidentId: $id,
                    technicianId: (int)$targetTechnicianId,
                    coordinatorId: null,
                    urgencyOverride: null,
                    urgencyReason: null,
                    reassignmentReason: $handoverNotes ?? 'Relevo de fin de turno'
                );

                $comment = new IncidentComment(
                    id: null,
                    incidentId: $id,
                    authorType: 'TECHNICIAN',
                    userId: $technicianId,
                    authorName: $technician->getName(),
                    commentText: "Traspaso de fin de turno de {$technician->getName()} a {$targetTechnician?->getName()}: {$handoverNotes}",
                    isInternal: true
                );
                $this->incidentRepo->addComment($comment);

                $event = new AuditEvent(
                    id: null,
                    entityType: AuditEvent::ENTITY_TICKET,
                    entityId: $id,
                    action: 'SHIFT_HANDOVER_REASSIGNMENT',
                    userId: $technicianId,
                    userRole: 'TECHNICIAN',
                    userName: $technician->getName(),
                    previousState: ['assigned_technician_id' => $technicianId],
                    newState: ['assigned_technician_id' => $targetTechnicianId],
                    metadata: [
                        'ticket_code' => $inc->getTicketCode(),
                        'target_technician_name' => $targetTechnician?->getName(),
                        'notes' => $handoverNotes,
                    ]
                );
                $this->auditRepo->log($event);

                // RF-05.4.4 / R8: aviso propio y prioritario de relevo al receptor
                // (email + Telegram si está vinculado). No es una asignación
                // ordinaria: la plantilla y la prioridad identifican el relevo.
                $this->notifications?->enqueueShiftHandoverAssignment($after);

                $transferred[] = [
                    'id' => $inc->getId(),
                    'ticket_code' => $inc->getTicketCode(),
                    'status' => $inc->getStatus()->value,
                    'machine_id' => $inc->getMachineId(),
                    'target_technician_id' => $targetTechnicianId,
                    'target_technician_name' => $targetTechnician?->getName(),
                ];
            }

            // 2. Averías no seleccionadas: retención ordinaria o protocolo sanitario.
            $remainingIds = array_diff(array_keys($eligibleMap), $incidentIdsToTransfer);
            $onCallList = null;

            foreach ($remainingIds as $remId) {
                $inc = $eligibleMap[$remId];
                $machine = $this->machineRepo->findById($inc->getMachineId());
                $isPerishable = $machine !== null && $machine->getMachineType()->requiresSanitaryWatch();

                if (!$isPerishable) {
                    $retained[] = [
                        'id' => $inc->getId(),
                        'ticket_code' => $inc->getTicketCode(),
                        'status' => $inc->getStatus()->value,
                        'machine_id' => $inc->getMachineId(),
                    ];
                    continue;
                }

                if ($onCallList === null) {
                    $onCallList = $this->onCallRepo->findAvailableOnCallTechnicians(
                        new \DateTimeImmutable('now'),
                        $technicianId
                    );
                }

                if ($onCallList !== []) {
                    // Nivel 1: traspaso a técnico de guardia 24/7.
                    $guard = $onCallList[0];
                    $after = $this->incidentRepo->assign(
                        incidentId: $remId,
                        technicianId: $guard->getId(),
                        coordinatorId: null,
                        urgencyOverride: null,
                        urgencyReason: null,
                        reassignmentReason: 'Protocolo sanitario Art. II: traspaso por fin de turno sin relevo a guardia de frío'
                    );

                    $comment = new IncidentComment(
                        id: null,
                        incidentId: $remId,
                        authorType: 'TECHNICIAN',
                        userId: $technicianId,
                        authorName: $technician->getName(),
                        commentText: "Reasignación automática por protocolo sanitario Art. II (cierre de turno sin relevo sobre máquina perecedera) a técnico de guardia {$guard->getName()}.",
                        isInternal: true
                    );
                    $this->incidentRepo->addComment($comment);

                    $event = new AuditEvent(
                        id: null,
                        entityType: AuditEvent::ENTITY_TICKET,
                        entityId: $remId,
                        action: 'SANITARY_SHIFT_CLOSE_FAILSAFE',
                        userId: $technicianId,
                        userRole: 'TECHNICIAN',
                        userName: $technician->getName(),
                        previousState: ['assigned_technician_id' => $technicianId],
                        newState: ['assigned_technician_id' => $guard->getId(), 'level' => 1],
                        metadata: [
                            'ticket_code' => $inc->getTicketCode(),
                            'on_call_technician' => $guard->getName(),
                            'reason' => 'FOOD_PERISHABLE at shift close without manual handover',
                        ]
                    );
                    $this->auditRepo->log($event);

                    // Nivel 1: el guardia hereda la parada por relevo de fin de
                    // turno. Debe recibir el aviso propio y prioritario del
                    // Módulo 21 —no la asignación ordinaria— para que el traspaso
                    // sanitario no compita con el lote estándar (R8 / contrato §4.1).
                    $this->notifications?->enqueueShiftHandoverAssignment($after, $machine);

                    $sanitaryTransfers[] = [
                        'id' => $inc->getId(),
                        'ticket_code' => $inc->getTicketCode(),
                        'machine_id' => $inc->getMachineId(),
                        'on_call_technician_id' => $guard->getId(),
                        'on_call_technician_name' => $guard->getName(),
                    ];
                    continue;
                }

                // Nivel 2: desasignación a REGISTERED con alarma crítica a Coordinación.
                $stmtUnassign = $this->pdo->prepare("
                    UPDATE incidents
                       SET status = 'REGISTERED',
                           assigned_technician_id = NULL,
                           assigned_at = NULL
                     WHERE id = :id
                ");
                $stmtUnassign->execute([':id' => $remId]);

                $this->incidentRepo->insertHistory(
                    $remId,
                    $technicianId,
                    $inc->getStatus()->value,
                    'REGISTERED',
                    'Desasignación automática por fin de turno sin guardia disponible (Protocolo Sanitario Art. II)'
                );

                $comment = new IncidentComment(
                    id: null,
                    incidentId: $remId,
                    authorType: 'TECHNICIAN',
                    userId: $technicianId,
                    authorName: $technician->getName(),
                    commentText: "🚨 ALERTA SANITARIA ART. II: Máquina perecedera desatendida por fin de turno sin guardia disponible. Ticket desasignado a REGISTERED para triaje urgente.",
                    isInternal: true
                );
                $this->incidentRepo->addComment($comment);

                $event = new AuditEvent(
                    id: null,
                    entityType: AuditEvent::ENTITY_TICKET,
                    entityId: $remId,
                    action: 'SANITARY_SHIFT_CLOSE_FAILSAFE',
                    userId: $technicianId,
                    userRole: 'TECHNICIAN',
                    userName: $technician->getName(),
                    previousState: ['assigned_technician_id' => $technicianId],
                    newState: ['assigned_technician_id' => null, 'status' => 'REGISTERED', 'level' => 2],
                    metadata: [
                        'ticket_code' => $inc->getTicketCode(),
                        'reason' => 'CRITICAL_NO_ON_CALL_AVAILABLE',
                        'fallback_reason' => 'NO_AVAILABLE_ON_CALL_TECHNICIAN',
                    ]
                );
                $this->auditRepo->log($event);

                // Alerta crítica a Coordinación. Se relee el expediente porque el
                // UPDATE ya lo dejó sin técnico; el payload debe reflejar ese estado.
                $unassigned = $this->incidentRepo->findById($remId) ?? $inc;
                $this->notifications?->enqueueSanitaryEmergencyAlert(
                    incident: $unassigned,
                    shiftId: (int)$activeShift->id(),
                    machine: $machine
                );

                $sanitaryUnassigned[] = [
                    'id' => $inc->getId(),
                    'ticket_code' => $inc->getTicketCode(),
                    'machine_id' => $inc->getMachineId(),
                    'sanitary_disposition' => 'UNASSIGNED_TO_REGISTERED',
                ];
            }

            // 3. Cierre del turno en technician_shifts.
            $defaultCloseReason = $targetTechnician === null && $incidentIdsToTransfer === []
                ? 'Cierre de turno sin relevo'
                : 'Cierre de turno con relevo';

            $closedShift = $this->shiftRepo->closeShift(
                shiftId: (int)$activeShift->id(),
                closedBy: $closedBy,
                closeReason: $closeReason ?? $defaultCloseReason,
                closedAt: $effectiveClosedAt,
                isForceClosed: $isForceClosed,
                executionTime: $executionTime
            );

            // Art. III.3: la bitácora de jornada se escribe en la misma transacción
            // que el cierre, con el estado previo y el resultante.
            $this->recordShiftLog(
                shift: $closedShift,
                eventType: $isForceClosed ? ShiftEventType::FORCE_CLOSE_SHIFT : ShiftEventType::CLOSE_SHIFT,
                actorUserId: $closedBy,
                effectiveAt: $executionTime ?? $closedShift->closedAt() ?? new DateTimeImmutable('now'),
                previousState: $activeShift->toArray(),
                reason: $closedShift->closeReason()
            );

            $event = new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_USER,
                entityId: $technicianId,
                action: $auditAction,
                userId: $closedBy,
                userRole: $actorRole,
                userName: $actorName ?? $technician->getName(),
                previousState: $activeShift->toArray(),
                newState: $closedShift->toArray(),
                metadata: array_merge(['technician_name' => $technician->getName()], $auditMetadata)
            );
            $this->auditRepo->log($event);

            $this->pdo->commit();

            $summary = sprintf(
                'Turno cerrado. %d averías traspasadas, %d retenidas, %d derivadas a guardia, %d en alarma crítica.',
                count($transferred),
                count($retained),
                count($sanitaryTransfers),
                count($sanitaryUnassigned)
            );

            return new ShiftHandoverResultDto(
                shift: TechnicianShiftDto::fromEntity($closedShift, $technician->getName()),
                transferredIncidents: $transferred,
                retainedIncidents: $retained,
                sanitaryEmergencyTransfers: $sanitaryTransfers,
                sanitaryEmergencyUnassigned: $sanitaryUnassigned,
                targetTechnicianId: $targetTechnician?->getId(),
                targetTechnicianName: $targetTechnician?->getName(),
                summary: $summary
            );
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Registra la entrada inmutable de bitácora del cierre de jornada.
     *
     * El puerto es opcional para no romper los dobles de prueba que solo
     * ejercitan el relevo y el protocolo sanitario; en producción la factoría
     * lo inyecta y la entrada viaja dentro de la misma transacción.
     *
     * @param array<string, mixed>|null $previousState
     */
    private function recordShiftLog(
        TechnicianShift $shift,
        ShiftEventType $eventType,
        int $actorUserId,
        DateTimeImmutable $effectiveAt,
        ?array $previousState,
        ?string $reason
    ): void {
        if ($this->shiftLogRepo === null || $shift->id() === null) {
            return;
        }

        $this->shiftLogRepo->record(
            shiftId: (int)$shift->id(),
            technicianId: $shift->technicianId(),
            eventType: $eventType,
            actorUserId: $actorUserId,
            effectiveAt: $effectiveAt,
            previousState: $previousState,
            newState: $shift->toArray(),
            reason: $reason
        );
    }

    /**
     * Bloquea la ruta completa del técnico dentro de la transacción abierta.
     *
     * En producción el repositorio PDO implementa el puerto de bloqueo. Los dobles
     * de prueba que solo implementan el contrato de lectura conservan la consulta
     * sin cerrojo: no se finge una serialización que el doble no puede ofrecer, y
     * la prueba de concurrencia real usa el repositorio PDO.
     *
     * @return list<Incident>
     */
    private function lockAssignedIncidents(int $technicianId): array
    {
        if ($this->incidentRepo instanceof IncidentRowLockRepositoryInterface) {
            return $this->incidentRepo->findAssignedToTechnicianForUpdate($technicianId);
        }

        return $this->incidentRepo->findAssignedToTechnician($technicianId);
    }

    /**
     * Guardia común de RF-04: ningún cierre, ordinario o forzado, puede avanzar
     * mientras el técnico tenga una intervención en curso.
     */
    private function assertNoInProgress(int $technicianId): void
    {
        $inProgress = $this->incidentRepo->findAssignedToTechnician($technicianId, ['IN_PROGRESS']);
        if ($inProgress !== []) {
            $blockingCodes = array_map(
                static fn (Incident $incident): string => (string)$incident->getTicketCode(),
                $inProgress
            );
            throw new ActiveIncidentInProgressException($blockingCodes);
        }
    }
}
