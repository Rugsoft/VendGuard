<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\RestockRouteDailyOverride;
use VendGuard\Core\Domain\Repository\RestockRoutePlannerGatewayInterface;
use VendGuard\Core\Service\RestockCadenceEvaluator;

/**
 * RestockRouteReassignmentRevoker
 *
 * Revocación automática de los desvíos `REASSIGNED` del día cuando el receptor
 * deja de poder atenderlos (Módulo 37; `QA-3`, `C1`, Art. III.3).
 *
 * El desvío habilita al receptor en M32, M34, M35 y M36 durante un único
 * `service_date`. Si antes de completar la visita cierra turno, registra una
 * ausencia vigente o causa baja, sus desvíos pendientes pasan a `'REVOKED'` en
 * la misma transacción que el hecho que los invalida y dejan su rastro en
 * `audit_log` bajo `RESTOCK_ROUTE_DAILY_OVERRIDE`.
 *
 * El revocador no decide el hecho: recibe la causa y el resolutor del servicio
 * que lo invoca. La lectura de desvíos se hace con `FOR UPDATE` en el puerto
 * para que una visita en curso y una baja no se crucen.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockRouteReassignmentRevoker
{
    /** Cierre de turno del receptor. */
    public const CAUSE_SHIFT_CLOSE = 'SHIFT_CLOSE';

    /** Ausencia vigente del receptor. */
    public const CAUSE_ABSENCE = 'ABSENCE';

    /** Baja de la cuenta del receptor. */
    public const CAUSE_DEACTIVATION = 'DEACTIVATION';

    /** Acción de auditoría de cada desvío revocado automáticamente. */
    public const AUDIT_ACTION = 'RESTOCK_ROUTE_REASSIGNMENT_AUTO_REVOKED';

    public function __construct(
        private readonly RestockRoutePlannerGatewayInterface $gateway,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * Revoca los desvíos vivos que apuntan al reponedor en la fecha civil.
     *
     * @param int $restockerId Receptor que deja de estar disponible.
     * @param string $cause Causa del catálogo (`SHIFT_CLOSE`, `ABSENCE`,
     *        `DEACTIVATION`).
     * @param int|null $resolverId Usuario que firma la revocación; por defecto,
     *        el propio receptor.
     * @param DateTimeImmutable|null $now Reloj inyectable para pruebas.
     *
     * @return int Desvíos transicionados a `REVOKED`.
     */
    public function revokeForRestocker(
        int $restockerId,
        string $cause,
        ?int $resolverId = null,
        ?DateTimeImmutable $now = null
    ): int {
        if ($restockerId <= 0) {
            return 0;
        }

        $instant = $now ?? new DateTimeImmutable('now');
        $serviceDate = RestockCadenceEvaluator::today($instant);
        $resolvedAt = $instant->format(DateTimeImmutable::ATOM);
        $resolver = $resolverId !== null && $resolverId > 0 ? $resolverId : $restockerId;

        $revoked = 0;
        foreach ($this->gateway->findActiveReassignmentsForRestocker($restockerId, $serviceDate) as $override) {
            if (!$override instanceof RestockRouteDailyOverride) {
                continue;
            }

            $affected = $this->gateway->revokeActiveOverrides(
                $override->getMachineId(),
                $serviceDate,
                $resolver,
                $resolvedAt
            );

            if ($affected <= 0) {
                continue;
            }

            $revoked += $affected;
            $this->audit->logEvent(new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_RESTOCK_ROUTE_DAILY_OVERRIDE,
                entityId: (int)$override->getId(),
                action: self::AUDIT_ACTION,
                userId: $resolver,
                userRole: 'SYSTEM',
                userName: 'Sistema (Módulo 37)',
                previousState: $override->toArray(),
                newState: [
                    'status' => 'REVOKED',
                    'resolved_by' => $resolver,
                    'resolved_at' => $resolvedAt,
                ],
                metadata: [
                    'cause' => $cause,
                    'target_restocker_id' => $restockerId,
                    'service_date' => $serviceDate,
                ]
            ));
        }

        return $revoked;
    }
}
