<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use JsonSerializable;

/**
 * NotificationDispatchResultDto — Resumen inmutable de una pasada del despachador
 * y vigilante del Centro de Notificaciones (T-NOTIF-07).
 *
 * Es la respuesta única de los dos consumidores de fondo —el endpoint
 * `POST /api/cron/notifications-dispatch` y el CLI `bin/send_notifications.php`—
 * y refleja exactamente las cuatro fases atómicas del algoritmo del contrato:
 *
 *   1. **Rescate (RF-01.4):** `rescued_stuck_count` cuenta las filas que llevaban
 *      más de 5 minutos en `PROCESSING` y volvieron a la cola (o cayeron a fallo
 *      definitivo al agotar su quinto intento).
 *   2. **Vigilancia de SLA 24/7 (RF-04):** `sla_2h_alerts_enqueued` y
 *      `sla_3h_alerts_enqueued` cuentan los avisos efectivamente encolados por
 *      cada umbral; el sellado previo de la marca por ciclo garantiza como
 *      máximo uno por hito (RF-04.4).
 *   3. **Reclamo por prioridad (RF-01.3):** `claimed_count` es el tamaño del lote
 *      tomado con `FOR UPDATE SKIP LOCKED`, siempre con `CRITICAL_SANITARY` por
 *      delante del correo ordinario (Art. II).
 *   4. **Entrega y política de fallos (RF-06.1, RF-06.2, RF-07.1):** el lote
 *      reclamado se reparte entre `sent_count`, `retry_scheduled_count`,
 *      `failed_permanent_count` y `cancelled_obsolete_count`. La identidad
 *      contable `claimed = sent + retry + permanent + obsolete` se verifica en el
 *      constructor, de modo que un resumen incoherente no puede llegar jamás al
 *      planificador como si la pasada hubiera cuadrados.
 *
 * `relative_tracking_links_count` (T-NOTIF-17) es un contador **de diagnóstico**,
 * no de reparto: cuenta los avisos entregados o reprogramados cuyo botón de
 * seguimiento viaja como ruta relativa porque el proceso no declara host público
 * alguno (`APP_BASE_URL`). La entrega no se bloquea —la Sede conserva el ticket y
 * el detalle en el texto—, pero un valor distinto de cero avisa de que los
 * correos externos llevan un botón que el cliente de correo resolverá contra su
 * propio dominio. Queda fuera de la identidad contable a propósito: no reparte el
 * lote, sólo lo describe.
 *
 * `duration_ms` permite vigilar la degradación del proceso batch (RNF-01).
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class NotificationDispatchResultDto implements JsonSerializable
{
    /**
     * @param int $rescuedStuckCount Filas rescatadas de `PROCESSING` > 5 minutos.
     * @param int $sla2hAlertsEnqueued Alertas de invariancia de 2h encoladas en la pasada.
     * @param int $sla3hAlertsEnqueued Alertas de invariancia de 3h encoladas en la pasada.
     * @param int $claimedCount Filas reclamadas para despacho en la pasada.
     * @param int $sentCount Entregas confirmadas por el proveedor externo.
     * @param int $retryScheduledCount Fallos transitorios reprogramados con backoff.
     * @param int $failedPermanentCount Fallos definitivos (5.º intento o rechazo irreversible).
     * @param int $cancelledObsoleteCount Envíos cancelados por obsolescencia antes de salir.
     * @param int $relativeTrackingLinksCount Avisos procesados cuyo enlace de seguimiento
     *                                        no pudo absolutizarse (sin `APP_BASE_URL`).
     * @param float $durationMs Duración medida de la pasada, en milisegundos.
     *
     * @throws InvalidArgumentException Si algún recuento es negativo o si la identidad
     *                                  contable del lote reclamado no cuadra.
     */
    public function __construct(
        public int $rescuedStuckCount,
        public int $sla2hAlertsEnqueued,
        public int $sla3hAlertsEnqueued,
        public int $claimedCount,
        public int $sentCount,
        public int $retryScheduledCount,
        public int $failedPermanentCount,
        public int $cancelledObsoleteCount,
        public int $relativeTrackingLinksCount,
        public float $durationMs,
    ) {
        foreach (get_object_vars($this) as $key => $value) {
            if ($key === 'durationMs') {
                continue;
            }
            if (!is_int($value) || $value < 0) {
                throw new InvalidArgumentException(sprintf(
                    'El contador «%s» del resumen de despacho no puede ser negativo.',
                    $key
                ));
            }
        }

        if ($durationMs < 0) {
            throw new InvalidArgumentException('La duración de la pasada de despacho no puede ser negativa.');
        }

        $accounted = $this->sentCount
            + $this->retryScheduledCount
            + $this->failedPermanentCount
            + $this->cancelledObsoleteCount;

        if ($accounted !== $this->claimedCount) {
            throw new InvalidArgumentException(sprintf(
                'El resumen de despacho no cuadra: el lote reclamado (%d) no coincide con la suma de enviadas (%d), reprogramadas (%d), fallidas definitivas (%d) y canceladas (%d).',
                $this->claimedCount,
                $this->sentCount,
                $this->retryScheduledCount,
                $this->failedPermanentCount,
                $this->cancelledObsoleteCount
            ));
        }
    }

    /**
     * Proyección plana con las claves exactas del contrato de la pasada batch.
     *
     * @return array{
     *     rescued_stuck_count: int,
     *     sla_2h_alerts_enqueued: int,
     *     sla_3h_alerts_enqueued: int,
     *     claimed_count: int,
     *     sent_count: int,
     *     retry_scheduled_count: int,
     *     failed_permanent_count: int,
     *     cancelled_obsolete_count: int,
     *     relative_tracking_links_count: int,
     *     duration_ms: float
     * }
     */
    public function toArray(): array
    {
        return [
            'rescued_stuck_count' => $this->rescuedStuckCount,
            'sla_2h_alerts_enqueued' => $this->sla2hAlertsEnqueued,
            'sla_3h_alerts_enqueued' => $this->sla3hAlertsEnqueued,
            'claimed_count' => $this->claimedCount,
            'sent_count' => $this->sentCount,
            'retry_scheduled_count' => $this->retryScheduledCount,
            'failed_permanent_count' => $this->failedPermanentCount,
            'cancelled_obsolete_count' => $this->cancelledObsoleteCount,
            'relative_tracking_links_count' => $this->relativeTrackingLinksCount,
            'duration_ms' => $this->durationMs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
