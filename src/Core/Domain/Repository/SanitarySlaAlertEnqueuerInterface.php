<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Incident;

/**
 * SanitarySlaAlertEnqueuerInterface
 *
 * Capacidad de encolado que el vigilante de inminencia de SLA necesita
 * (RF-04.1, RF-04.2). El despachador en segundo plano no conoce el grafo
 * completo de siete repositorios de `NotificationOutboxEnqueuerService`: sólo
 * necesita encolar las dos alertas del reloj sanitario.
 *
 * Se declara como contrato estrecho por dos motivos:
 *
 *   1. Respeto a la firma publicada del servicio de encolado, que sigue siendo
 *      el único punto de conversión de eventos de negocio en filas de la cola.
 *   2. Determinismo del arnés: las suites del despachador inyectan un doble que
 *      registra exactamente qué alerta se pidió y cuándo, sin montar el grafo
 *      PDO completo ni abrir transacciones propias.
 *
 * La idempotencia por ciclo vive en los `dedup_key` de cada fila y en las
 * marcas `sla_2h_alert_sent_at` / `sla_3h_alert_sent_at` del expediente: el
 * vigilante sella la marca y sólo entonces pide el encolado.
 */
interface SanitarySlaAlertEnqueuerInterface
{
    /**
     * Encola la Alerta de Inminencia de SLA de 2 horas sin técnico asignado
     * (`SLA_IMMINENCE_2H_UNASSIGNED`, prioridad `CRITICAL_SANITARY`) para todos
     * los Coordinadores activos y el canal corporativo de emergencias.
     *
     * @return int Filas efectivamente encoladas (las deduplicadas no cuentan).
     */
    public function enqueueSla2hUnassignedAlert(Incident $incident): int;

    /**
     * Encola la Alerta Crítica de Inminencia de SLA de 3 horas sin haber
     * iniciado la intervención (`SLA_IMMINENCE_3H_UNSTARTED`, prioridad
     * `CRITICAL_SANITARY`) para todos los Coordinadores activos, el técnico
     * asignado (si existe) y el canal corporativo de emergencias.
     *
     * @return int Filas efectivamente encoladas (las deduplicadas no cuentan).
     */
    public function enqueueSla3hUnstartedAlert(Incident $incident): int;
}
