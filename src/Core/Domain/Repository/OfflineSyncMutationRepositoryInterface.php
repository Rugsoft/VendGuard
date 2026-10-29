<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\OfflineSyncMutation;

/**
 * OfflineSyncMutationRepositoryInterface
 *
 * Contrato de persistencia de las mutaciones sincronizadas desde la cola sin
 * conexión del técnico (Módulo 24, RF-02.1, RF-04.2, RF-04.3, RF-05.3 y RF-06.4).
 *
 * Reglas que este puerto impone a cualquier implementación:
 *
 *  - **Una operación, una fila.** `(technician_id, client_mutation_id)` es la
 *    clave de idempotencia: `findByTechnicianAndMutationId()` permite devolver el
 *    `response_payload` original en un reenvío, y la pareja
 *    `reserveAppliedMutation()` / `completeReservedMutation()` decide el dueño de
 *    la clave **antes** de ejecutar la transición de dominio, dentro de la misma
 *    transacción. La violación de `uq_offline_sync_tech_mutation` se traduce
 *    siempre a una repetición idempotente, nunca a un error de motor (RF-04.2).
 *  - **Nunca se borra.** No existe método de baja: el disparador
 *    `trg_no_delete_offline_sync_mutations` lo impide en el motor (Art. III.1).
 *  - **El volcado de conflicto es una excepción de auditoría.** Puede insertar
 *    la Nota Interna de Taller (`is_internal = TRUE`) aunque el técnico ya no
 *    sea el titular y aunque la avería esté en `CANCELLED` o `CLOSED` hace más
 *    de 48 horas; en preventivas anexa en `preventive_orders.notes`
 *    sin cambiar estado ni titularidad (Art. III.3, `QA 2`). El contenido
 *    volcado jamás se expone al Portal de Sede (Art. V.4).
 *  - **El volcado por baja de cuenta no aplica transiciones.** Registra el
 *    trabajo como nota interna o anotación de auditoría y deja las paradas como
 *    están (`EARS 06.4`, `QA 5`).
 *  - **Todo sello temporal se escribe en un solo formato.** Las columnas son
 *    `TIMESTAMPTZ`; las implementaciones convierten los `DateTimeImmutable` a
 *    `Y-m-d H:i:sP` y dejan que el motor normalice la zona.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
interface OfflineSyncMutationRepositoryInterface
{
    /**
     * Guarda de idempotencia (`RF-04.2`): devuelve la fila ya persistida para
     * ese técnico y esa clave, o `null` si la operación es nueva.
     *
     * El aislamiento es horizontal (Art. V.4): la misma clave en otro técnico es
     * otra operación y no se filtra su resultado.
     */
    public function findByTechnicianAndMutationId(int $technicianId, string $clientMutationId): ?OfflineSyncMutation;

    /**
     * Reserva la clave de idempotencia **dentro de la transacción del caso de
     * uso** (`RF-04.2`).
     *
     * Inserta la fila con un cuerpo provisional antes de que se ejecute la
     * transición de dominio, de modo que el índice único
     * `uq_offline_sync_tech_mutation` decida el ganador mientras la transición
     * todavía no ha ocurrido. Una petición simultánea con la misma clave espera
     * a que la primera confirme y recibe su fila: sólo el dueño de la reserva
     * ejecuta la transición.
     *
     * La implementación debe invocarse con una transacción ya abierta por el
     * llamador. Si la transición se rechaza o falla, el rollback libera la
     * clave y la operación puede reintentarse: un veredicto negativo no quema
     * la idempotencia.
     *
     * @return array{reserved: bool, existing: OfflineSyncMutation|null}
     *         `reserved = true` cuando esta llamada es la dueña de la clave;
     *         `existing` es la fila ganadora cuando ya existía (aplicada o
     *         volcada), para que el servicio replique su respuesta o rechace el
     *         reintento sobre un volcado.
     */
    public function reserveAppliedMutation(OfflineSyncMutation $mutation): array;

    /**
     * Confirma la reserva con el veredicto real de la transición y firma su
     * evento de auditoría, **en la misma transacción** (`Art. III.3`).
     *
     * Si la reserva ya no existe —rollback ajeno, fila borrada— la
     * implementación falla cerrado en lugar de escribir un resultado huérfano.
     *
     * @return OfflineSyncMutation La fila persistida, con el cuerpo definitivo
     *         y `created_at` asignado.
     */
    public function completeReservedMutation(OfflineSyncMutation $mutation): OfflineSyncMutation;

    /**
     * Última mutación `APPLIED` de inicio o reanudación de una parada
     * (`RF-04.3`, `QA 4`).
     *
     * Es el ancla del cierre encadenado: en una cadena con reanudación el
     * segmento activo empieza en la reanudación, no en el `started_at` original
     * de la incidencia. Devuelve `null` si el inicio fue online y no hay
     * mutación de cola que consultar.
     */
    public function findLatestAppliedChainStart(int $technicianId, string $stopType, int $stopId): ?OfflineSyncMutation;

    /**
     * Paquete consolidado de la ruta del técnico autenticado (`RF-02.1`), en un
     * número constante de consultas y sin N+1 por parada, máquina ni orden.
     *
     * Devuelve sólo el contenido operativo; la identidad del técnico y el
     * instante de sincronización los aporta el caso de uso que arma el DTO, de
     * modo que el paquete no pueda mezclar datos de dos técnicos (Art. V.4).
     *
     * @return array{
     *   route: array{
     *     stops: list<array<string, mixed>>,
     *     enabled_locations: list<array<string, mixed>>,
     *     pending_critical_stops_other_locations: list<array<string, mixed>>
     *   },
     *   preventive_orders: list<array<string, mixed>>,
     *   preventive_checklists_by_order: array<int, array{
     *     order: array<string, mixed>,
     *     items: list<array<string, mixed>>
     *   }>,
     *   spare_parts_catalog_by_machine: array<int, array{
     *     compatible_parts: list<array<string, mixed>>
     *   }>,
     *   van_stock: array{
     *     items: list<array<string, mixed>>,
     *     active_dispatched_order: array<string, mixed>|null,
     *     last_confirmed_order: array<string, mixed>|null
     *   }
     * }
     */
    public function fetchBootstrapSnapshotForTechnician(int $technicianId): array;

    /**
     * Registra una mutación aplicada con éxito y enriquece su evento de
     * auditoría con el bloque `offline_sync` (doble sello temporal,
     * `clock_skew_adjusted` y confirmación sanitaria, Art. III.3).
     *
     * La inserción y el evento forman una sola unidad de escritura. Si la fila
     * ya existe para `(technician_id, client_mutation_id)`, la implementación
     * devuelve la existente sin duplicar el evento (`RF-04.2`).
     *
     * No es la vía del caso de uso sin conexión: el servicio usa la pareja
     * `reserveAppliedMutation()` / `completeReservedMutation()` para que la
     * reserva de la clave y la transición del dominio compartan unidad
     * atómica (`H3`).
     *
     * @return OfflineSyncMutation La fila persistida, con identidad y
     *         `created_at` asignados.
     */
    public function recordAppliedMutation(OfflineSyncMutation $mutation): OfflineSyncMutation;

    /**
     * Vuelca en un solo toque el trabajo de una acción en conflicto
     * (`EARS 05.3`, `QA 1`, `QA 2`).
     *
     * En averías inserta una Nota Interna de Taller confidencial con el resumen
     * estructurado y la foto de evidencia opcional; en órdenes preventivas
     * anexa el resumen en `notes`. En ambos casos persiste la fila con
     * `outcome_status = 'CONFLICT_DUMPED'` y el evento
     * `OFFLINE_CONFLICT_DUMPED`. No altera estado ni titularidad.
     *
     * @param array<string, mixed> $workSummary Diagnóstico, acción, pausa,
     *        piezas y constancia de firma recogidos in situ.
     * @param string|null $photoPath Ruta de la evidencia ya validada por
     *        *magic bytes* y almacenada (Art. V.5), o `null`.
     * @return array{
     *   mutation: OfflineSyncMutation,
     *   internal_comment_id: int|null,
     *   audit_log_id: int
     * }
     */
    public function recordConflictDump(
        OfflineSyncMutation $mutation,
        string $conflictReason,
        array $workSummary,
        ?string $photoPath = null
    ): array;

    /**
     * Volcado atómico e idempotente de la cola pendiente de un técnico cuya
     * cuenta fue desactivada (`EARS 06.4`, `QA 5`).
     *
     * Cada elemento queda como Nota Interna de Taller o anotación de auditoría
     * con `outcome_status = 'DEACTIVATED_ACCOUNT_DUMP'`; no se aplican cambios
     * de estado ni de titularidad. Los elementos ya volcados se omiten para que
     * un reintento no duplique notas.
     *
     * @param list<OfflineSyncMutation> $mutations
     * @return array{
     *   dumped: int,
     *   mutations: list<OfflineSyncMutation>,
     *   internal_comment_ids: list<int>
     * }
     */
    public function recordDeactivatedAccountDumpBatch(int $technicianId, array $mutations): array;
}
