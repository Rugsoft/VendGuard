<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * IncidentSiteResponseRepositoryInterface
 *
 * Puerto de dominio para saber si la sede ya respondió dentro de la pausa vigente de un
 * expediente: el responsable de sede publicó un mensaje público con posterioridad a
 * `incidents.paused_at`.
 *
 * La autoría se resuelve contra el rastro inmutable (`audit_log`: la acción
 * `INCIDENT_COMMENT_ADDED` firmada con rol `SITE_MANAGER`) y no contra
 * `incident_comments.author_type`, porque esa columna etiqueta igual al responsable de
 * sede y al informador ciudadano que reporta por QR (`REPORTER` en ambos casos, con
 * `user_id = NULL`): leerla encendería el aviso de RF-05.6 con un aviso ciudadano.
 *
 * Existe como contrato propio —y no como un método más de `IncidentRepositoryInterface`—
 * por el mismo principio de segregación de interfaces que ya rigen
 * `IncidentRowLockRepositoryInterface` y `UserLockoutRepositoryInterface`: la inmensa
 * mayoría de consumidores del repositorio (triaje, hilos, historial, métricas) no lee
 * esta marca y no debe verse forzada a implementar ni a simular una capacidad que no usa.
 * Su único consumidor es la ruta del técnico (`GET /api/technician/my-route`), que pinta
 * el aviso de RF-05.6 sobre la parada pausada.
 *
 * La marca es DERIVADA en lectura —no se persiste ni transiciona nada (enmienda del
 * Product Owner, E-1, 2026-10-11)—: el comentario de la sede informa al técnico de que la
 * respuesta que esperaba ya está en el hilo, y la pausa sólo la cierra la reanudación
 * manual (RF-02.2/RF-02.3) o la cancelación justificada por inactividad (RF-04.3).
 */
interface IncidentSiteResponseRepositoryInterface
{
    /**
     * ¿Publicó la sede un comentario público posterior al inicio de la pausa vigente?
     *
     * Compara contra `incidents.paused_at`, de modo que el formato temporal lo resuelve
     * el motor de base de datos y no el llamante; una incidencia sin pausa viva devuelve
     * `false` aunque tenga comentarios de sede anteriores.
     *
     * @param int $incidentId Identificador primario de la incidencia.
     * @return bool `true` sólo con pausa viva y respuesta pública posterior a su inicio.
     */
    public function hasSiteResponseSinceCurrentPause(int $incidentId): bool;
}
