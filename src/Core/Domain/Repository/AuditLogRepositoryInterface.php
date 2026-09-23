<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\AuditEvent;

/**
 * AuditLogRepositoryInterface
 * 
 * Contrato de repositorio de dominio para la persistencia y consulta cronológica
 * del registro de auditoría inmutable (Audit Log).
 * 
 * Cumple con el Principio de Inviolabilidad de Datos (Constitución Art. III.1 y III.3)
 * y el requisito RF-05 (EARS 5.3, 5.4). Es estrictamente append-only:
 * prohíbe explícitamente cualquier método de actualización o borrado físico/lógico.
 */
interface AuditLogRepositoryInterface
{
    /**
     * Inserta un nuevo evento de auditoría de forma atómica e inmutable (append-only).
     * 
     * @param AuditEvent $event Entidad con los datos del evento auditado.
     * @return AuditEvent Entidad persistida con su ID asignado.
     */
    public function log(AuditEvent $event): AuditEvent;

    /**
     * Consulta cronológica paginada y filtrable del registro de auditoría (EARS 5.5).
     * 
     * @param array<string, mixed> $filters Filtros opcionales:
     *   - entity_type: 'TICKET', 'MACHINE', 'LOCATION'
     *   - entity_id: int
     *   - action: string
     *   - user_id: int
     *   - from: string ('YYYY-MM-DD' o 'YYYY-MM-DD HH:MM:SS')
     *   - to: string ('YYYY-MM-DD' o 'YYYY-MM-DD HH:MM:SS')
     * @param int $limit Número máximo de registros a recuperar (default 50).
     * @param int $offset Desplazamiento para paginación (default 0).
     * @return list<AuditEvent>
     */
    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array;

    /**
     * Obtiene el conteo total de registros que coinciden con los filtros aplicados.
     * 
     * @param array<string, mixed> $filters Mismos filtros que findEvents.
     * @return int
     */
    public function countEvents(array $filters = []): int;

    /**
     * Obtiene el historial de auditoría de una entidad específica ordenado cronológicamente.
     * 
     * @param string $entityType Tipo de entidad ('TICKET', 'MACHINE', 'LOCATION').
     * @param int $entityId Identificador numérico de la entidad.
     * @return list<AuditEvent>
     */
    public function findByEntity(string $entityType, int $entityId): array;
}
