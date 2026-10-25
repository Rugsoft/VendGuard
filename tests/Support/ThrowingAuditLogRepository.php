<?php

declare(strict_types=1);

use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;

/**
 * ThrowingAuditLogRepository · decorador de auditoría para las pruebas de atomicidad.
 *
 * Delega en el repositorio real y lanza una excepción en la llamada N indicada.
 * Así se puede certificar que la escritura de negocio y su traza comparten la
 * misma transacción: si la traza falla, la fila de negocio no queda confirmada.
 *
 * Código de arnés: no vive bajo `src/` y ningún camino de producción lo carga.
 */
final class ThrowingAuditLogRepository implements AuditLogRepositoryInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly AuditLogRepositoryInterface $inner,
        private readonly int $failOnCall = 1
    ) {
    }

    public function log(AuditEvent $event): AuditEvent
    {
        $this->calls++;

        if ($this->calls === $this->failOnCall) {
            throw new RuntimeException('Fallo de auditoría simulado por la prueba de atomicidad.');
        }

        return $this->inner->log($event);
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->inner->findEvents($filters, $limit, $offset);
    }

    public function countEvents(array $filters = []): int
    {
        return $this->inner->countEvents($filters);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return $this->inner->findByEntity($entityType, $entityId);
    }
}
