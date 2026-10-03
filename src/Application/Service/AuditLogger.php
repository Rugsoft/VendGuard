<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\InvalidResolutionException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Service\ResolutionValidator;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;

/**
 * AuditLogger
 * 
 * Servicio de Aplicación responsable de coordinar el registro inmutable de auditoría (audit_log).
 * Da cumplimiento estricto a RF-05 (EARS 5.1 a 5.4), RNF-03 y a los mandatos constitucionales:
 * - Artículo III.3: Auditabilidad completa y trazabilidad de piezas/repuestos reparados.
 * - Artículo V.1: Cierre obligatoriamente justificado (diagnóstico y solución técnica indispensable).
 * - Artículo V.6: Respeto a la ventana de 48 horas en reaperturas.
 */
class AuditLogger
{
    private AuditLogRepositoryInterface $auditRepo;

    public function __construct(?AuditLogRepositoryInterface $auditRepo = null)
    {
        $this->auditRepo = $auditRepo ?? new PdoAuditLogRepository();
    }

    /**
     * Registra un evento de cambio de estado o ciclo de vida de un ticket (EARS 5.1).
     * Si la acción es de resolución (RESOLVED o CLOSED), valida obligatoriamente que se
     * proporcione el diagnóstico y la solución técnica requeridos por el Art. V.1.
     * 
     * @param int $ticketId ID numérico del ticket.
     * @param string $action Acción ejecutada (ej. 'CREATE_TICKET', 'STATUS_CHANGE', 'RESOLVE_INCIDENT', 'REOPEN_TICKET').
     * @param array{id: int|null, role: string, name: string} $user Datos del usuario causante.
     * @param array<string, mixed>|null $previousState Estado anterior.
     * @param array<string, mixed> $newState Estado resultante.
     * @param array<string, mixed>|null $metadata Información adicional (ej. IP, tiempo de respuesta).
     * @return AuditEvent Evento persistido con ID generado.
     * @throws InvalidResolutionException Si el cierre/resolución carece de justificación válida.
     */
    public function logTicketEvent(
        int $ticketId,
        string $action,
        array $user,
        ?array $previousState,
        array $newState,
        ?array $metadata = null
    ): AuditEvent {
        $actionUpper = strtoupper(trim($action));

        // Verificación de justificación obligatoria al resolver (Art. V.1 y EARS 5.1.3)
        $newStatus = isset($newState['status']) ? strtoupper((string)$newState['status']) : '';
        if ($actionUpper === 'RESOLVE_INCIDENT' || in_array($newStatus, ['RESOLVED', 'CLOSED'], true)) {
            $diagnosis = isset($newState['diagnosis']) ? (string)$newState['diagnosis'] : ($newState['resolution_diagnosis'] ?? '');
            $solution = isset($newState['solution']) ? (string)$newState['solution'] : ($newState['resolution_action'] ?? '');

            ResolutionValidator::validate($diagnosis, $solution);
        }

        $event = new AuditEvent(
            id: null,
            entityType: AuditEvent::ENTITY_TICKET,
            entityId: $ticketId,
            action: $actionUpper,
            userId: $user['id'] ?? null,
            userRole: $user['role'] ?? 'SYSTEM',
            userName: $user['name'] ?? 'Sistema',
            previousState: $previousState,
            newState: $newState,
            metadata: $metadata
        );

        return $this->auditRepo->log($event);
    }

    /**
     * Registra un evento administrativo o maestro en una máquina dispensadora (EARS 5.2).
     * 
     * @param int $machineId ID de la máquina.
     * @param string $action Acción ejecutada (ej. 'CREATE_MACHINE', 'UPDATE_MACHINE', 'DEACTIVATE_MACHINE').
     * @param array{id: int|null, role: string, name: string} $user Datos del usuario coordinador.
     * @param array<string, mixed>|null $previousState
     * @param array<string, mixed> $newState
     * @param array<string, mixed>|null $metadata
     * @return AuditEvent
     */
    public function logMachineEvent(
        int $machineId,
        string $action,
        array $user,
        ?array $previousState,
        array $newState,
        ?array $metadata = null
    ): AuditEvent {
        $event = new AuditEvent(
            id: null,
            entityType: AuditEvent::ENTITY_MACHINE,
            entityId: $machineId,
            action: strtoupper(trim($action)),
            userId: $user['id'] ?? null,
            userRole: $user['role'] ?? 'COORDINATOR',
            userName: $user['name'] ?? 'Coordinación',
            previousState: $previousState,
            newState: $newState,
            metadata: $metadata
        );

        return $this->auditRepo->log($event);
    }

    /**
     * Registra un evento administrativo o maestro en una sede cliente (EARS 5.2).
     * 
     * @param int $locationId ID de la sede.
     * @param string $action Acción ejecutada (ej. 'CREATE_LOCATION', 'UPDATE_PHONE', 'DEACTIVATE_LOCATION').
     * @param array{id: int|null, role: string, name: string} $user Datos del usuario coordinador.
     * @param array<string, mixed>|null $previousState
     * @param array<string, mixed> $newState
     * @param array<string, mixed>|null $metadata
     * @return AuditEvent
     */
    public function logLocationEvent(
        int $locationId,
        string $action,
        array $user,
        ?array $previousState,
        array $newState,
        ?array $metadata = null
    ): AuditEvent {
        $event = new AuditEvent(
            id: null,
            entityType: AuditEvent::ENTITY_LOCATION,
            entityId: $locationId,
            action: strtoupper(trim($action)),
            userId: $user['id'] ?? null,
            userRole: $user['role'] ?? 'COORDINATOR',
            userName: $user['name'] ?? 'Coordinación',
            previousState: $previousState,
            newState: $newState,
            metadata: $metadata
        );

        return $this->auditRepo->log($event);
    }

    /**
     * Registra un evento administrativo o maestro en un usuario interno (EARS 4.1).
     * 
     * @param int $userId ID del usuario afectado.
     * @param string $action Acción ejecutada (ej. 'USER_CREATED', 'USER_UPDATED', 'USER_DEACTIVATED', 'USER_REACTIVATED', 'USER_PASSWORD_RESET').
     * @param array{id: int|null, role: string, name: string} $actor Datos del usuario coordinador actuante.
     * @param array<string, mixed>|null $previousState
     * @param array<string, mixed> $newState
     * @param array<string, mixed>|null $metadata
     * @return AuditEvent
     */
    public function logUserEvent(
        int $userId,
        string $action,
        array $actor,
        ?array $previousState,
        array $newState,
        ?array $metadata = null
    ): AuditEvent {
        $event = new AuditEvent(
            id: null,
            entityType: AuditEvent::ENTITY_USER,
            entityId: $userId,
            action: strtoupper(trim($action)),
            userId: $actor['id'] ?? null,
            userRole: $actor['role'] ?? 'COORDINATOR',
            userName: $actor['name'] ?? 'Coordinación',
            previousState: $previousState,
            newState: $newState,
            metadata: $metadata
        );

        return $this->auditRepo->log($event);
    }

    /**
     * Registra un evento sobre una orden de mantenimiento preventivo (Módulo 05).
     *
     * Permite emitir eventos/historyicos nuevos con la entidad correcta sin
     * alterar los ya escritos bajo `MACHINE`/`TICKET` (Art. III.3).
     *
     * @param int $preventiveOrderId ID de la orden en `preventive_orders`.
     * @param string $action Acción ejecutada (ej. 'CREATE_PREVENTIVE_ORDER', 'COMPLETE_PREVENTIVE_ORDER').
     * @param array{id: int|null, role: string, name: string} $actor Datos del causante.
     * @param array<string, mixed>|null $previousState Estado anterior.
     * @param array<string, mixed> $newState Estado resultante.
     * @param array<string, mixed>|null $metadata Información adicional.
     * @return AuditEvent Evento persistido con ID generado.
     */
    public function logPreventiveOrderEvent(
        int $preventiveOrderId,
        string $action,
        array $actor,
        ?array $previousState,
        array $newState,
        ?array $metadata = null
    ): AuditEvent {
        $event = new AuditEvent(
            id: null,
            entityType: AuditEvent::ENTITY_PREVENTIVE_ORDER,
            entityId: $preventiveOrderId,
            action: strtoupper(trim($action)),
            userId: $actor['id'] ?? null,
            userRole: $actor['role'] ?? 'SYSTEM',
            userName: $actor['name'] ?? 'Sistema',
            previousState: $previousState,
            newState: $newState,
            metadata: $metadata
        );

        return $this->auditRepo->log($event);
    }

    /**
     * Registra un evento sobre un certificado sanitario (Módulo 05 / Art. II).
     *
     * @param int $certificateId ID del certificado en `sanitary_certificates`.
     * @param string $action Acción ejecutada (ej. 'ISSUE_SANITARY_CERTIFICATE', 'SUSPEND_SANITARY_CERTIFICATE').
     * @param array{id: int|null, role: string, name: string} $technician Datos del técnico actuante.
     * @param array<string, mixed> $newState Estado resultante.
     * @param array<string, mixed>|null $metadata Información adicional.
     * @return AuditEvent Evento persistido con ID generado.
     */
    public function logSanitaryCertificateEvent(
        int $certificateId,
        string $action,
        array $technician,
        array $newState,
        ?array $metadata = null
    ): AuditEvent {
        $event = new AuditEvent(
            id: null,
            entityType: AuditEvent::ENTITY_SANITARY_CERTIFICATE,
            entityId: $certificateId,
            action: strtoupper(trim($action)),
            userId: $technician['id'] ?? null,
            userRole: $technician['role'] ?? 'TECHNICIAN',
            userName: $technician['name'] ?? 'Técnico',
            previousState: null,
            newState: $newState,
            metadata: $metadata
        );

        return $this->auditRepo->log($event);
    }

    /**
     * Registra un evento del ciclo economico de un expediente de reintegro (RNF-REF-01).
     *
     * Cubre la trazabilidad completa exigida por el Art. III.3 sobre el dinero:
     * creacion, dictamen tecnico, deposito en conserjeria, validacion de PIN,
     * aprobacion, rechazo y liquidacion digital con referencia bancaria.
     *
     * @param int $refundRequestId ID del expediente en `refund_requests`.
     * @param string $action Accion ejecutada (ej. 'CREATE_REFUND_REQUEST',
     *   'VALIDATE_PICKUP_PIN', 'APPROVE_REFUND', 'PAY_REFUND', 'REJECT_REFUND').
     * @param array{id: int|null, role: string, name: string} $actor Datos del causante.
     * @param array<string, mixed>|null $previousState Estado anterior.
     * @param array<string, mixed> $newState Estado resultante.
     * @param array<string, mixed>|null $metadata Informacion adicional.
     * @return AuditEvent Evento persistido con ID generado.
     */
    public function logRefundEvent(
        int $refundRequestId,
        string $action,
        array $actor,
        ?array $previousState,
        array $newState,
        ?array $metadata = null
    ): AuditEvent {
        $event = new AuditEvent(
            id: null,
            entityType: AuditEvent::ENTITY_REFUND_REQUEST,
            entityId: $refundRequestId,
            action: strtoupper(trim($action)),
            userId: $actor['id'] ?? null,
            userRole: $actor['role'] ?? 'SYSTEM',
            userName: $actor['name'] ?? 'Sistema',
            previousState: $previousState,
            newState: $newState,
            metadata: $metadata
        );

        return $this->auditRepo->log($event);
    }

    /**
     * Registra el hallazgo de efectivo recuperado sin reclamacion previa (RF-REF-04).
     *
     * El dinero atascado encontrado de oficio tambien exige trazabilidad
     * contable inmutable, aunque no llegue a liquidarse nunca.
     *
     * @param int $findingId ID del hallazgo en `unclaimed_cash_findings`.
     * @param string $action Accion ejecutada (ej. 'REGISTER_UNCLAIMED_CASH').
     * @param array{id: int|null, role: string, name: string} $technician Datos del tecnico actuante.
     * @param array<string, mixed> $newState Estado resultante.
     * @param array<string, mixed>|null $metadata Informacion adicional.
     * @return AuditEvent Evento persistido con ID generado.
     */
    public function logUnclaimedCashEvent(
        int $findingId,
        string $action,
        array $technician,
        array $newState,
        ?array $metadata = null
    ): AuditEvent {
        $event = new AuditEvent(
            id: null,
            entityType: AuditEvent::ENTITY_UNCLAIMED_CASH_FINDING,
            entityId: $findingId,
            action: strtoupper(trim($action)),
            userId: $technician['id'] ?? null,
            userRole: $technician['role'] ?? 'TECHNICIAN',
            userName: $technician['name'] ?? 'Tecnico',
            previousState: null,
            newState: $newState,
            metadata: $metadata
        );

        return $this->auditRepo->log($event);
    }
}
