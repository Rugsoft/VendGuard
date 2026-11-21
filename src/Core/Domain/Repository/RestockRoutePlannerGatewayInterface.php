<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\MachineRestockSchedule;
use VendGuard\Core\Domain\Model\RestockRouteDailyOverride;

/**
 * RestockRoutePlannerGatewayInterface
 *
 * Puerto de persistencia del Planificador de Rutas Periódicas de Carga
 * (Módulo 37; RF-01.1, RF-01.3, RF-02.1–RF-02.3, RF-03.1, RF-05.1, QA-1–QA-3,
 * QA-6, Art. II, Art. III.1 y Art. III.3).
 *
 * El puerto devuelve hechos y escribe filas; no decide reglas de negocio. La
 * evaluación de cadencias, el rigor textual de `SKIPPED`, la precedencia de
 * propietarios y la composición de la ruta viven en los servicios de dominio y
 * aplicación. Aquí solo se concentra el acceso transaccional:
 *
 *   - **Lectura acotada.** La agenda viva de una máquina o de todo el parque en
 *     una sola consulta, la excepción viva del día y su búsqueda por
 *     `client_mutation_id` para la idempotencia offline.
 *   - **Versionado atómico de agendas (`QA-2`, Art. III.3).** `saveScheduleVersion()`
 *     cierra la versión vigente e inserta la nueva dentro de la misma
 *     transacción; la fila cerrada permanece inmutable y sigue sirviendo al
 *     enlace histórico `machine_restock_visits.route_schedule_id`.
 *   - **Ciclo de la excepción (`C1`).** Inserción, revocación de la excepción
 *     viva y superación de una omisión por la visita física real.
 *   - **Salvaguarda sanitaria (`QA-1`, Art. II).** Sello
 *     `machines.sanitary_status = 'EXPIRED'` y encolado de la alerta interna
 *     `RESTOCK_FEFO_STOP_SKIPPED_ALERT` con prioridad `CRITICAL_SANITARY`.
 *   - **Cola offline (`C3`).** Registro y recuperación de la mutación
 *     `SKIP_RESTOCK_STOP` por `(restocker_id, client_mutation_id)`.
 *
 * Ningún método ejecuta `DELETE FROM`: el motor lo impide con
 * `prevent_hard_delete()` y el puerto ni siquiera lo intenta (Art. III.1).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
interface RestockRoutePlannerGatewayInterface
{
    /**
     * Bloquea la máquina y devuelve sus hechos de contexto.
     *
     * `null` cuando la máquina no existe o está dada de baja. La fila queda
     * bloqueada con `FOR UPDATE OF m` hasta el cierre de la transacción.
     *
     * @return array{
     *     id: int,
     *     machine_code: string,
     *     machine_type: string,
     *     location_id: int,
     *     location_name: string,
     *     site_code: string,
     *     zone_id: ?int,
     *     sla_tier: string,
     *     latitude: float,
     *     longitude: float,
     *     sanitary_status: string,
     *     is_out_of_service: bool,
     *     is_seasonal_pause: bool,
     *     seasonal_pause_until: ?string,
     *     is_active: bool
     * }|null
     */
    public function lockMachine(int $machineId): ?array;

    /**
     * Agenda viva de una máquina, o `null` si no tiene.
     */
    public function findActiveSchedule(int $machineId): ?MachineRestockSchedule;

    /**
     * Agendas vivas del parque indexadas por `machine_id`.
     *
     * Una sola consulta para toda la composición diaria: evita el N+1 del
     * tablero de Coordinación y de la hoja móvil.
     *
     * @return array<int, MachineRestockSchedule>
     */
    public function listActiveSchedules(): array;

    /**
     * Cierra la versión vigente e inserta la nueva en una única transacción.
     *
     * @return int Identificador de la nueva versión activa.
     */
    public function saveScheduleVersion(MachineRestockSchedule $schedule): int;

    /**
     * Baja lógica de una agenda por identificador.
     *
     * @return bool `false` si la agenda no existía o ya estaba cerrada.
     */
    public function deactivateSchedule(int $scheduleId, int $actorId, string $deactivatedAt): bool;

    /**
     * Excepción viva (`ACTIVE`) de una máquina en una fecha civil.
     */
    public function findActiveOverride(int $machineId, string $serviceDate): ?RestockRouteDailyOverride;

    /**
     * Excepción asociada a una mutación offline, para responder de forma
     * idempotente al reintento del móvil (`C3`).
     */
    public function findOverrideByClientMutationId(string $clientMutationId): ?RestockRouteDailyOverride;

    /**
     * Inserta una excepción diaria y devuelve su identificador.
     */
    public function insertOverride(RestockRouteDailyOverride $override): int;

    /**
     * Revoca todas las excepciones vivas de la máquina y fecha.
     *
     * @return int Filas transicionadas a `REVOKED`.
     */
    public function revokeActiveOverrides(
        int $machineId,
        string $serviceDate,
        int $resolverId,
        string $resolvedAt
    ): int;

    /**
     * Supera las omisiones vivas de la máquina y fecha con la visita real.
     *
     * @return int Filas transicionadas a `SUPERSEDED`.
     */
    public function supersedeSkippedOverrides(
        int $machineId,
        string $serviceDate,
        int $visitId,
        ?int $resolverId,
        string $resolvedAt
    ): int;

    /**
     * Sella la máquina como `EXPIRED` salvo que ya esté en cuarentena.
     *
     * @return bool `true` si la fila cambió de estado.
     */
    public function markMachineSanitaryExpired(int $machineId): bool;

    /**
     * Encola la alerta interna de Coordinación al omitir una parada con FEFO.
     *
     * La plantilla y la prioridad son invariantes del módulo; el canal, el
     * destinatario y el payload los aporta la configuración de Coordinación
     * (Art. V.4: nunca canales de sede ni públicos).
     *
     * @param array<string, mixed> $extraPayload
     *
     * @return int Identificador de la fila en `notification_outbox`.
     */
    public function enqueueFefoSkipAlert(
        int $machineId,
        string $serviceDate,
        int $overrideId,
        string $recipient,
        string $channel = 'EMAIL',
        array $extraPayload = []
    ): int;

    /**
     * Mutación offline ya registrada, o `null` si es la primera vez.
     *
     * @return array{id: int, http_status: int, payload: array<string, mixed>}|null
     */
    public function findOfflineMutation(int $restockerId, string $clientMutationId): ?array;

    /**
     * Registra la mutación `SKIP_RESTOCK_STOP` aplicada.
     *
     * El índice único `(technician_id, client_mutation_id)` es la última red:
     * un reintento devuelve el identificador ya existente sin duplicar la fila.
     *
     * @param array<string, mixed> $responsePayload
     *
     * @return int Identificador de la fila en `offline_sync_mutations`.
     */
    public function recordOfflineMutation(
        int $restockerId,
        string $clientMutationId,
        int $machineId,
        string $clientOccurredAt,
        array $responsePayload,
        int $httpStatus = 200
    ): int;
}
