<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use VendGuard\Application\DTO\TechnicianAbsenceDto;
use VendGuard\Core\Domain\Exception\InvalidAbsenceException;
use VendGuard\Core\Domain\Exception\UserNotFoundException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\TechnicianAbsence;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianAbsenceRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;

/**
 * TechnicianAbsenceService
 *
 * Servicio de Aplicación responsable de coordinar la gestión administrativa de ausencias
 * de técnicos desde el panel de Coordinación (Módulo 21).
 *
 * Casos de uso:
 *   - Alta de ausencia con comprobación de solapamiento y lista de tickets afectados (RF-01, RF-02).
 *   - Cancelación lógica auditada con motivo (Art. III.1).
 *   - Listado con filtros para la interfaz web.
 */
final class TechnicianAbsenceService
{
    /** Zona operativa del parque: la misma que usan el calendario y la auditoría. */
    private const OPERATIONAL_TIMEZONE = 'Europe/Madrid';

    public function __construct(
        private readonly TechnicianAbsenceRepositoryInterface $absenceRepo,
        private readonly UserRepositoryInterface $userRepo,
        private readonly IncidentRepositoryInterface $incidentRepo,
        private readonly AuditLogRepositoryInterface $auditRepo,
        private readonly TransactionManagerInterface $transactions,
        private readonly TechnicianLockRepositoryInterface $lockRepo,
        private readonly ?SuggestedTechniciansService $suggestions = null
    ) {
    }

    /**
     * @return array{absence: TechnicianAbsenceDto, affected_incidents: list<array<string, mixed>>}
     */
    public function createAbsence(
        int $technicianId,
        string $reason,
        string $startsAt,
        string $endsAt,
        ?string $notes,
        int $createdBy
    ): array {
        $tech = $this->userRepo->findById($technicianId);
        if ($tech === null || $tech->getRole() !== UserRole::TECHNICIAN) {
            throw new UserNotFoundException($technicianId, "No se encontró el técnico con ID {$technicianId}.");
        }

        $creator = $this->userRepo->findById($createdBy);

        // Tratamiento de fechas: una fecha pura abarca la jornada completa en la
        // zona operativa del parque. Antes se expandía en UTC y el fin de la
        // ausencia caía en el día siguiente local (A1).
        $starts = $this->parseBoundary($startsAt, false);
        $ends = $this->parseBoundary($endsAt, true);

        if ($ends < $starts) {
            throw InvalidAbsenceException::invalidDateRange();
        }

        // RF-01.1: las notas son opcionales pero nunca superan 500 caracteres.
        // El repositorio inserta directamente, así que la validación debe ocurrir
        // aquí: `TechnicianAbsence::create()` no participa en el alta (M-5).
        $cleanNotes = $notes !== null ? trim($notes) : null;
        if ($cleanNotes === '') {
            $cleanNotes = null;
        }

        if ($cleanNotes !== null && mb_strlen($cleanNotes) > TechnicianAbsence::MAX_NOTES_LENGTH) {
            throw InvalidAbsenceException::notesTooLong(TechnicianAbsence::MAX_NOTES_LENGTH);
        }

        try {
            $reasonEnum = AbsenceReason::from($reason);
        } catch (\ValueError) {
            throw new InvalidAbsenceException(
                "Motivo de ausencia no válido: {$reason}.",
                'INVALID_ABSENCE_REASON',
                422
            );
        }

        // El alta es una unidad de trabajo: el cerrojo por técnico serializa la
        // comprobación de solapamiento, el INSERT y la auditoría (Art. III.3).
        // La constraint de exclusión sigue siendo la última autoridad.
        $absence = $this->transactions->runInTransaction(function () use (
            $technicianId,
            $reasonEnum,
            $starts,
            $ends,
            $cleanNotes,
            $createdBy,
            $tech,
            $creator
        ): TechnicianAbsence {
            if (!$this->lockRepo->lockTechnicianForUpdate($technicianId)) {
                throw new UserNotFoundException($technicianId, "No se encontró el técnico con ID {$technicianId}.");
            }

            if ($this->absenceRepo->hasOverlappingAbsence($technicianId, $starts, $ends)) {
                throw InvalidAbsenceException::overlap();
            }

            $absence = $this->absenceRepo->create(
                technicianId: $technicianId,
                reason: $reasonEnum,
                startsAt: $starts,
                endsAt: $ends,
                notes: $cleanNotes,
                createdBy: $createdBy
            );

            $event = new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_USER,
                entityId: $technicianId,
                action: 'CREATE_TECHNICIAN_ABSENCE',
                userId: $createdBy,
                userRole: $creator?->getRole()->value ?? 'COORDINATOR',
                userName: $creator?->getName() ?? 'Coordinador',
                previousState: null,
                newState: $absence->toArray(),
                metadata: [
                    'technician_name' => $tech->getName(),
                    'reason' => $reasonEnum->value,
                    'starts_at' => $starts->format(DateTimeImmutable::ATOM),
                    'ends_at' => $ends->format(DateTimeImmutable::ATOM),
                ]
            );
            $this->auditRepo->log($event);

            return $absence;
        });

        // La ausencia ya está confirmada y auditada. La lectura de afectados es
        // posterior y no debe convertir un alta correcta en un 500 que invite a
        // repetirla: si falla, se devuelve la ausencia con lista vacía.
        try {
            $activeIncidents = $this->incidentRepo->findAssignedToTechnician(
                $technicianId,
                ['ASSIGNED', 'PENDING_PARTS', 'PENDING_INFO']
            );
        } catch (\Throwable $exception) {
            error_log('[vendguard] No se pudieron leer los tickets afectados por la ausencia: ' . $exception);
            $activeIncidents = [];
        }

        $affectedList = [];
        foreach ($activeIncidents as $inc) {
            $suggestion = null;
            if ($this->suggestions !== null) {
                try {
                    // El técnico ausente no puede ser su propio suplente: se excluye
                    // del ranking antes de que el motor elija al primero.
                    $suggestion = $this->suggestions->getSuggestions(
                        (int)$inc->getId(),
                        [(int)$technicianId]
                    );
                } catch (\Throwable) {
                    // La ausencia es el dato primario; una avería sin recomendación
                    // no debe impedir que Coordinación la registre.
                    $suggestion = null;
                }
            }

            $top = $suggestion?->rankedTechnicians[0] ?? null;
            $affectedList[] = [
                'id' => $inc->getId(),
                'ticket_code' => $inc->getTicketCode(),
                'status' => $inc->getStatus()->value,
                'machine_id' => $inc->getMachineId(),
                'location_id' => $inc->getLocationId(),
                'suggested_technician' => $top === null ? null : [
                    'id' => (int)$top['technician_id'],
                    'name' => (string)$top['name'],
                    'operator_code' => $top['operator_code'] ?? null,
                    'total_score' => (int)$top['total_score'],
                    'explanation' => $suggestion?->recommendation['explanation'] ?? null,
                    'badges' => $top['badges'] ?? [],
                    'current_status' => $top['current_status'] ?? null,
                ],
            ];
        }

        return [
            'absence' => TechnicianAbsenceDto::fromEntity(
                absence: $absence,
                technicianName: $tech->getName(),
                creatorName: $creator?->getName()
            ),
            'affected_incidents' => $affectedList,
        ];
    }

    /**
     * Traduce un límite ISO de la API a un instante en la zona operativa.
     *
     * Una fecha pura es una jornada completa local, no un rango UTC. Las fechas
     * imposibles (`2026-02-30`) se rechazan antes de que el motor las normalice.
     */
    private function parseBoundary(string $value, bool $isEnd): DateTimeImmutable
    {
        $value = trim($value);
        $timezone = new DateTimeZone(self::OPERATIONAL_TIMEZONE);

        if ($value === '') {
            throw InvalidAbsenceException::invalidDateRange();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            $hasErrors = $errors !== false
                && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

            if ($date === false || $hasErrors) {
                throw InvalidAbsenceException::invalidDateRange();
            }

            return $isEnd
                ? $date->setTime(23, 59, 59)
                : $date->setTime(0, 0, 0);
        }

        try {
            return new DateTimeImmutable($value, $timezone);
        } catch (\Exception) {
            throw InvalidAbsenceException::invalidDateRange();
        }
    }

    public function cancelAbsence(int $absenceId, int $cancelledBy, string $cancellationReason): TechnicianAbsenceDto
    {
        $absence = $this->absenceRepo->findById($absenceId);
        if ($absence === null) {
            throw new InvalidAbsenceException(
                "No se encontró la ausencia con ID {$absenceId}.",
                'ABSENCE_NOT_FOUND',
                404
            );
        }

        // La cancelación es una transición única: una segunda petición no puede
        // devolver 200 ni volver a auditar (M-2, contrato 409 ABSENCE_ALREADY_CANCELLED).
        if ($absence->isCancelled()) {
            throw InvalidAbsenceException::alreadyCancelled();
        }

        $cleanReason = trim($cancellationReason);
        if (mb_strlen($cleanReason) < TechnicianAbsence::MIN_CANCELLATION_REASON_LENGTH) {
            throw InvalidAbsenceException::cancellationReasonTooShort(
                TechnicianAbsence::MIN_CANCELLATION_REASON_LENGTH
            );
        }

        $canceller = $this->userRepo->findById($cancelledBy);
        $tech = $this->userRepo->findById($absence->technicianId());

        // El UPDATE condicionado a `cancelled_at IS NULL` es el árbitro de la
        // carrera: si otra petición ganó, no se escribe auditoría y se responde
        // el mismo 409 que en una segunda cancelación explícita. La relectura y
        // la auditoría viajan en la misma transacción que el UPDATE.
        $updated = $this->transactions->runInTransaction(function () use (
            $absenceId,
            $cancelledBy,
            $cleanReason,
            $canceller
        ): TechnicianAbsence {
            $current = $this->absenceRepo->findById($absenceId);
            if ($current === null) {
                throw new InvalidAbsenceException(
                    "No se encontró la ausencia con ID {$absenceId}.",
                    'ABSENCE_NOT_FOUND',
                    404
                );
            }

            if ($current->isCancelled()) {
                throw InvalidAbsenceException::alreadyCancelled();
            }

            if (!$this->absenceRepo->cancel($absenceId, $cancelledBy, $cleanReason)) {
                throw InvalidAbsenceException::alreadyCancelled();
            }

            $updated = $this->absenceRepo->findById($absenceId);
            if ($updated === null) {
                throw new InvalidAbsenceException(
                    "No se encontró la ausencia con ID {$absenceId}.",
                    'ABSENCE_NOT_FOUND',
                    404
                );
            }

            $event = new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_USER,
                entityId: $updated->technicianId(),
                action: 'CANCEL_TECHNICIAN_ABSENCE',
                userId: $cancelledBy,
                userRole: $canceller?->getRole()->value ?? 'COORDINATOR',
                userName: $canceller?->getName() ?? 'Coordinador',
                previousState: $current->toArray(),
                newState: $updated->toArray(),
                metadata: [
                    'absence_id' => $absenceId,
                    'cancellation_reason' => $cleanReason,
                ]
            );
            $this->auditRepo->log($event);

            return $updated;
        });

        return TechnicianAbsenceDto::fromEntity(
            absence: $updated,
            technicianName: $tech?->getName(),
            cancellerName: $canceller?->getName()
        );
    }

    /**
     * @return list<TechnicianAbsenceDto>
     */
    public function listAbsences(?int $technicianId = null, ?string $status = null): array
    {
        $absences = $this->absenceRepo->findAll($technicianId, $status);
        $dtos = [];

        foreach ($absences as $a) {
            $tech = $this->userRepo->findById($a->technicianId());
            $dtos[] = TechnicianAbsenceDto::fromEntity($a, $tech?->getName());
        }

        return $dtos;
    }
}
