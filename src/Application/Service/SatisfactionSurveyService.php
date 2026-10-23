<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Application\DTO\SatisfactionSurveyDto;
use VendGuard\Core\Domain\Exception\SatisfactionSurveyValidationException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentSatisfactionSurvey;
use VendGuard\Core\Domain\Repository\IncidentRowLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\SatisfactionSurveyRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoSatisfactionSurveyRepository;

/**
 * SatisfactionSurveyService
 *
 * Caso de uso «valorar un servicio resuelto» del Módulo 20.
 *
 * Decisiones que el código hace explícitas:
 *
 *  - **La disponibilidad se decide sobre la relectura bloqueada.** El expediente
 *    se lee con `SELECT … FOR UPDATE` dentro de la transacción del alta; si una
 *    reapertura concurrente dejó `resolved_at = NULL`, esta lectura lo ve y la
 *    petición responde `422 SURVEY_NOT_AVAILABLE` (RF-02.6). No se confía en lo
 *    que el controlador hubiera leído antes.
 *  - **El ancla se copia, no se calcula.** `resolution_at` sale de
 *    `incidents.resolved_at` leído bajo bloqueo. `now()` no interviene: la
 *    valoración pertenece al servicio que se prestó, no al día en que se pulsó
 *    la estrella (RD-01).
 *  - **La precedencia es la del contrato (§4.2).** Primero el expediente, la
 *    sede y la disponibilidad (1–4); después el cuerpo (5–7); solo con carga útil
 *    válida se consulta el duplicado (8).
 *  - **La carrera la cierra el motor.** Si dos peticiones superan la comprobación
 *    previa, el `ON CONFLICT` del repositorio devuelve `null` y el servicio
 *    responde `409`, nunca `500` (RF-02.7).
 *  - **La auditoría es parte de la misma unidad de trabajo.** El evento
 *    `SUBMIT_SATISFACTION_SURVEY` se firma sobre `TICKET` con el nombre de la
 *    sede y la misma transacción que inserta la encuesta: no puede quedar una
 *    respuesta sin rastro ni un rastro sin respuesta (RF-03.3).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class SatisfactionSurveyService
{
    /** Rol con el que se firma el evento: la sede, nunca una persona (R2). */
    private const AUDIT_ROLE = 'SITE_RESPONSIBLE';

    public function __construct(
        private ?SatisfactionSurveyRepositoryInterface $surveys = null,
        private ?IncidentRowLockRepositoryInterface $incidents = null,
        private ?AuditLogger $audit = null,
        private ?TransactionManagerInterface $transactions = null,
    ) {
    }

    /**
     * Registra la valoración de la sede sobre el episodio de resolución vigente.
     *
     * @param int $incidentId Expediente indicado en la ruta.
     * @param int $locationId Sede autenticada (nunca un parámetro del cuerpo).
     * @param string $locationName Nombre actual de la sede, para la auditoría.
     * @param array<string, mixed> $payload Cuerpo JSON ya decodificado.
     *
     * @throws SatisfactionSurveyValidationException Si el contrato §4.2 no se cumple.
     */
    public function submit(
        int $incidentId,
        int $locationId,
        string $locationName,
        array $payload
    ): SatisfactionSurveyDto {
        return $this->transactions()->runInTransaction(function () use (
            $incidentId,
            $locationId,
            $locationName,
            $payload
        ): SatisfactionSurveyDto {
            $incident = $this->incidents()->findByIdForUpdate($incidentId);

            if ($incident === null) {
                throw SatisfactionSurveyValidationException::incidentNotFound();
            }

            if ($incident->getLocationId() !== $locationId) {
                throw SatisfactionSurveyValidationException::siteMismatch();
            }

            $resolutionAt = $incident->getResolvedAt();

            if ($resolutionAt === null || !$this->isResolved($incident)) {
                throw SatisfactionSurveyValidationException::notAvailable();
            }

            if (!array_key_exists('rating', $payload)) {
                throw SatisfactionSurveyValidationException::missingRating();
            }

            $survey = IncidentSatisfactionSurvey::create(
                incidentId: $incidentId,
                locationId: $locationId,
                technicianId: $incident->getAssignedTechnicianId(),
                technicianNameSnapshot: $incident->getTechnicianName(),
                resolutionAt: $resolutionAt,
                rating: $payload['rating'],
                comment: $payload['comment'] ?? null,
            );

            if ($this->surveys()->findEpisode($incidentId, $resolutionAt) !== null) {
                throw SatisfactionSurveyValidationException::alreadySubmitted();
            }

            $saved = $this->surveys()->create($survey);

            if ($saved === null) {
                throw SatisfactionSurveyValidationException::alreadySubmitted();
            }

            $this->audit()->logTicketEvent(
                $incidentId,
                'SUBMIT_SATISFACTION_SURVEY',
                ['id' => null, 'role' => self::AUDIT_ROLE, 'name' => $locationName],
                null,
                [
                    'survey_id' => (int)$saved->getId(),
                    'rating' => $saved->getRating(),
                    'resolution_at' => $saved->getResolutionAt(),
                    'technician_id' => $saved->getTechnicianId(),
                ],
                [
                    'incident_id' => $incidentId,
                    'location_id' => $locationId,
                    'ticket_code' => $incident->getTicketCode(),
                ]
            );

            return SatisfactionSurveyDto::fromEntity($saved, $incident->getTicketCode());
        });
    }

    /**
     * Episodios pendientes de valorar de la sede autenticada (contratos §3).
     *
     * El tope de 20 es de presentación: `pending_total` informa del total real
     * para que la interfaz sepa que quedan más aunque la página esté llena (R3).
     *
     * @return array{
     *     items: list<array{incident_id: int, ticket_code: string, machine_code: string, machine_model: string|null, resolved_at: string}>,
     *     pending_total: int
     * }
     */
    public function pendingFor(int $locationId): array
    {
        return [
            'items' => $this->surveys()->findPendingByLocation($locationId),
            'pending_total' => $this->surveys()->countPendingByLocation($locationId),
        ];
    }

    /**
     * Una avería admite valoración solo en un estado con resolución vigente.
     */
    private function isResolved(Incident $incident): bool
    {
        return in_array($incident->getStatus(), [IncidentStatus::RESOLVED, IncidentStatus::CLOSED], true);
    }

    private function surveys(): SatisfactionSurveyRepositoryInterface
    {
        return $this->surveys ??= new PdoSatisfactionSurveyRepository();
    }

    private function incidents(): IncidentRowLockRepositoryInterface
    {
        return $this->incidents ??= new PdoIncidentRepository();
    }

    private function audit(): AuditLogger
    {
        return $this->audit ??= new AuditLogger();
    }

    private function transactions(): TransactionManagerInterface
    {
        return $this->transactions ??= new PdoTransactionManager();
    }
}
