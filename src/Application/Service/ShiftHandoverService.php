<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use PDO;
use VendGuard\Application\DTO\ShiftHandoverResultDto;
use VendGuard\Application\DTO\TechnicianShiftDto;
use VendGuard\Core\Domain\Exception\ActiveIncidentInProgressException;
use VendGuard\Core\Domain\Exception\InvalidShiftTransitionException;
use VendGuard\Core\Domain\Exception\JustificationTooShortException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\OnCallTechnicianRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;

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
 */
final class ShiftHandoverService
{
    public const MIN_HANDOVER_NOTES_LENGTH = 10;

    public function __construct(
        private readonly PDO $pdo,
        private readonly TechnicianShiftRepositoryInterface $shiftRepo,
        private readonly UserRepositoryInterface $userRepo,
        private readonly IncidentRepositoryInterface $incidentRepo,
        private readonly MachineRepositoryInterface $machineRepo,
        private readonly OnCallTechnicianRepositoryInterface $onCallRepo,
        private readonly AuditLogRepositoryInterface $auditRepo
    ) {
    }

    /**
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

        // 2. Precondición de averías en curso (RF-04 / Bloqueo fail-fast)
        $inProgress = $this->incidentRepo->findAssignedToTechnician($technicianId, ['IN_PROGRESS']);
        if (count($inProgress) > 0) {
            $blockingCodes = array_map(static fn ($i): string => (string)$i->getTicketCode(), $inProgress);
            throw new ActiveIncidentInProgressException($blockingCodes);
        }

        // 3. Validación de relevo (RF-05)
        $targetTech = null;
        $cleanNotes = null;
        $hasTransfers = count($incidentIdsToTransfer) > 0;

        if ($hasTransfers || $targetTechnicianId !== null) {
            if ($targetTechnicianId === null) {
                throw new InvalidShiftTransitionException(
                    'Debe indicar un técnico de relevo para traspasar averías.',
                    'TARGET_TECHNICIAN_REQUIRED',
                    422
                );
            }

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

            if ($hasTransfers) {
                $cleanNotes = trim((string)$handoverNotes);
                if (mb_strlen($cleanNotes) < self::MIN_HANDOVER_NOTES_LENGTH) {
                    throw new JustificationTooShortException(
                        $cleanNotes,
                        'La nota de traspaso debe tener al menos 10 caracteres.'
                    );
                }
            }
        }

        // 4. Averías elegibles asignadas al técnico saliente
        $eligibleIncidents = $this->incidentRepo->findAssignedToTechnician(
            $technicianId,
            ['ASSIGNED', 'PENDING_PARTS', 'PENDING_INFO']
        );

        $eligibleMap = [];
        foreach ($eligibleIncidents as $inc) {
            $eligibleMap[(int)$inc->getId()] = $inc;
        }

        // Detección de colisión concurrente (RF-05.3, R9)
        foreach ($incidentIdsToTransfer as $transferId) {
            if (!isset($eligibleMap[$transferId])) {
                throw new InvalidShiftTransitionException(
                    "El ticket con ID {$transferId} ya no está asignado al técnico o cambió de estado.",
                    'CONCURRENT_ROUTE_MODIFICATION',
                    409
                );
            }
        }

        // 5. Transacción atómica
        $this->pdo->beginTransaction();

        try {
            $transferred = [];
            $retained = [];
            $sanitaryTransfers = [];
            $sanitaryUnassigned = [];

            // 5.a Relevo explícito de averías seleccionadas
            foreach ($incidentIdsToTransfer as $id) {
                $inc = $eligibleMap[$id];
                $this->incidentRepo->assign(
                    incidentId: $id,
                    technicianId: $targetTechnicianId,
                    coordinatorId: null,
                    urgencyOverride: null,
                    urgencyReason: null,
                    reassignmentReason: $cleanNotes ?? 'Relevo de fin de turno'
                );

                // Comentario interno obligatorio y confidencial (Art. V.4)
                $comment = new IncidentComment(
                    id: null,
                    incidentId: $id,
                    authorType: 'TECHNICIAN',
                    userId: $technicianId,
                    authorName: $tech->getName(),
                    commentText: "Traspaso de fin de turno de {$tech->getName()} a {$targetTech->getName()}: {$cleanNotes}",
                    isInternal: true
                );
                $this->incidentRepo->addComment($comment);

                // Auditoría de relevo
                $event = new AuditEvent(
                    id: null,
                    entityType: AuditEvent::ENTITY_TICKET,
                    entityId: $id,
                    action: 'SHIFT_HANDOVER_REASSIGNMENT',
                    userId: $technicianId,
                    userRole: 'TECHNICIAN',
                    userName: $tech->getName(),
                    previousState: ['assigned_technician_id' => $technicianId],
                    newState: ['assigned_technician_id' => $targetTechnicianId],
                    metadata: [
                        'ticket_code' => $inc->getTicketCode(),
                        'target_technician_name' => $targetTech->getName(),
                        'notes' => $cleanNotes,
                    ]
                );
                $this->auditRepo->log($event);

                $transferred[] = [
                    'id' => $inc->getId(),
                    'ticket_code' => $inc->getTicketCode(),
                    'status' => $inc->getStatus()->value,
                    'machine_id' => $inc->getMachineId(),
                    'target_technician_id' => $targetTechnicianId,
                    'target_technician_name' => $targetTech->getName(),
                ];
            }

            // 5.b Averías no seleccionadas: retención ordinaria o protocolo sanitario (RF-06, Art. II)
            $remainingIds = array_diff(array_keys($eligibleMap), $incidentIdsToTransfer);
            $onCallList = null;

            foreach ($remainingIds as $remId) {
                $inc = $eligibleMap[$remId];
                $machine = $this->machineRepo->findById($inc->getMachineId());
                $isPerishable = $machine !== null && $machine->getMachineType()->requiresSanitaryWatch();

                if ($isPerishable) {
                    if ($onCallList === null) {
                        $onCallList = $this->onCallRepo->findOnCallTechnicians();
                    }

                    if (!empty($onCallList)) {
                        // Nivel 1: Traspaso a técnico de guardia 24/7
                        $guard = $onCallList[0];
                        $this->incidentRepo->assign(
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
                            authorName: $tech->getName(),
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
                            userName: $tech->getName(),
                            previousState: ['assigned_technician_id' => $technicianId],
                            newState: ['assigned_technician_id' => $guard->getId(), 'level' => 1],
                            metadata: [
                                'ticket_code' => $inc->getTicketCode(),
                                'on_call_technician' => $guard->getName(),
                                'reason' => 'FOOD_PERISHABLE at shift close without manual handover',
                            ]
                        );
                        $this->auditRepo->log($event);

                        $sanitaryTransfers[] = [
                            'id' => $inc->getId(),
                            'ticket_code' => $inc->getTicketCode(),
                            'machine_id' => $inc->getMachineId(),
                            'on_call_technician_id' => $guard->getId(),
                            'on_call_technician_name' => $guard->getName(),
                        ];
                    } else {
                        // Nivel 2: Desasignación a REGISTERED con alarma crítica a Coordinación
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
                            authorName: $tech->getName(),
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
                            userName: $tech->getName(),
                            previousState: ['assigned_technician_id' => $technicianId],
                            newState: ['assigned_technician_id' => null, 'status' => 'REGISTERED', 'level' => 2],
                            metadata: [
                                'ticket_code' => $inc->getTicketCode(),
                                'reason' => 'CRITICAL_NO_ON_CALL_AVAILABLE',
                            ]
                        );
                        $this->auditRepo->log($event);

                        $sanitaryUnassigned[] = [
                            'id' => $inc->getId(),
                            'ticket_code' => $inc->getTicketCode(),
                            'machine_id' => $inc->getMachineId(),
                            'sanitary_disposition' => 'UNASSIGNED_TO_REGISTERED',
                        ];
                    }
                } else {
                    // Avería ordinaria: se retiene para la siguiente jornada
                    $retained[] = [
                        'id' => $inc->getId(),
                        'ticket_code' => $inc->getTicketCode(),
                        'status' => $inc->getStatus()->value,
                        'machine_id' => $inc->getMachineId(),
                    ];
                }
            }

            // 5.c Cierre del turno en technician_shifts
            $closedShift = $this->shiftRepo->closeShift(
                shiftId: $activeShift->id(),
                closedBy: $technicianId,
                closeReason: $closeReason ?? 'Cierre de turno con relevo'
            );

            $event = new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_USER,
                entityId: $technicianId,
                action: 'CLOSE_TECHNICIAN_SHIFT',
                userId: $technicianId,
                userRole: 'TECHNICIAN',
                userName: $tech->getName(),
                previousState: $activeShift->toArray(),
                newState: $closedShift->toArray()
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
                shift: TechnicianShiftDto::fromEntity($closedShift, $tech->getName()),
                transferredIncidents: $transferred,
                retainedIncidents: $retained,
                sanitaryEmergencyTransfers: $sanitaryTransfers,
                sanitaryEmergencyUnassigned: $sanitaryUnassigned,
                targetTechnicianId: $targetTechnicianId,
                targetTechnicianName: $targetTech?->getName(),
                summary: $summary
            );
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
