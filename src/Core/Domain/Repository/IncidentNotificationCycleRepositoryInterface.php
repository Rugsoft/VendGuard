<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;

/**
 * IncidentNotificationCycleRepositoryInterface
 *
 * Capacidad de marcas del ciclo de atención que el vigilante de SLA y de
 * seguridad alimentaria necesita (RF-03.1, RF-04.4, RF-04.5):
 *
 *   - `cold_alert_sent_at` sella la Alerta Inmediata de Frío del ciclo vigente.
 *   - `sla_2h_alert_sent_at` y `sla_3h_alert_sent_at` sellan cada umbral una
 *     sola vez por ciclo, que es el blindaje anti-spam de RF-04.4.
 *
 * La reapertura en garantía reinicia las tres marcas y arranca un ciclo nuevo
 * (`cycle_started_at`), de modo que el evaluador vuelve a tener derecho a emitir
 * los avisos del nuevo ciclo.
 */
interface IncidentNotificationCycleRepositoryInterface
{
    /**
     * Sella la alerta inmediata de frío del ciclo vigente.
     */
    public function markColdAlertSent(int $incidentId, ?DateTimeImmutable $sentAt = null): bool;

    /**
     * Sella la alerta de inminencia de SLA del umbral indicado.
     *
     * @param int $hours Umbral en horas naturales: sólo 2 o 3.
     * @throws \InvalidArgumentException Si el umbral no es 2 ni 3.
     */
    public function markSlaAlertSent(int $incidentId, int $hours, ?DateTimeImmutable $sentAt = null): bool;
}
