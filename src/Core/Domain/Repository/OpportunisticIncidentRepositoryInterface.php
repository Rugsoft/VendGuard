<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\Incident;

/**
 * OpportunisticIncidentRepositoryInterface
 *
 * Puerto de lectura del **Módulo 16 · Avería Oportunista In Situ**. Reúne las
 * consultas que alimentan el catálogo de máquinas de la sede, la habilitación de
 * la visita y las dos salvaguardas de ruta (coexistencia entre sedes y cadena de
 * frío del Art. II), sin escribir ni transicionar nada: abrir el catálogo o
 * pintar el aviso jamás debe mutar un expediente (Art. III y RNF-04).
 *
 * POR QUÉ ES UN CONTRATO PROPIO
 *
 *   Igual que `TechnicianRouteBoardRepositoryInterface` o
 *   `IncidentSiteResponseRepositoryInterface`, la capacidad vive en su propia
 *   interfaz para no forzar a los dobles de prueba ni a los demás consumidores de
 *   `IncidentRepositoryInterface` a implementar lecturas que solo usa la ruta
 *   oportunista. `PdoIncidentRepository` la declara y `TechnicianController`
 *   publica el catálogo cuando el repositorio la implementa.
 *
 * QUÉ DECIDE Y QUÉ NO
 *
 *   El repositorio entrega **hechos ya consultados** con la forma exacta que
 *   esperan las políticas puras de `Core\Service` (T-OPP-04): las paradas con su
 *   estado, la última salida de la sede, las máquinas con su expediente activo o
 *   en garantía, la intervención abierta en otro edificio y las paradas críticas o
 *   sanitarias que esperan al técnico. La habilitación de 30/35 minutos, el orden
 *   de la Parada #1 y la exigencia de confirmación del Art. II se deciden en esas
 *   políticas, no aquí: el adaptador no duplica la regla del dominio.
 *
 *   La única clasificación que el puerto publica es `enablement_type`
 *   (`ACTIVE_STOP` o `GRACE_WINDOW_30M`), porque nace de la propia consulta —la
 *   parada vigente frente a la ventana de gracia— y el servicio la necesita para
 *   pintar la tarjeta temporal sin volver a preguntar.
 *
 * ESCRITURAS ATÓMICAS
 *
 *   El mismo puerto reúne las cuatro escrituras con las que el técnico cierra el
 *   paso de leer a intervenir (`RF-04`, `RF-05`, `RF-06.4`). Todas toman el
 *   expediente con bloqueo pesimista `FOR UPDATE` y escriben estado, metadatos
 *   oportunistas y rastro inmutable dentro de una sola unidad de trabajo, de modo
 *   que un fallo a mitad no pueda dejar un ticket a medias ni liberar el índice
 *   único `uq_machine_active_ticket` antes de que exista su sucesor.
 *
 *   La frontera es la del resto del proyecto: si el llamante ya abrió una
 *   transacción, la primitiva se une a ella y no confirma; si no, la abre y la
 *   cierra por su cuenta. Así el servicio de aplicación puede encolar avisos o
 *   auditar en el mismo instante que el cambio de estado (Módulos 14 y 15).
 *
 *   Ninguna primitiva decide la habilitación de la sede, la coexistencia entre
 *   edificios ni la salvaguarda de cadena de frío: esas reglas viven en las
 *   políticas puras de `Core\Service` y las aplica el caso de uso antes de
 *   escribir. Aquí solo se comprueba que el expediente esté en el estado que la
 *   primitiva sabe transicionar, y se falla antes de tocar nada (Fail-Fast).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y campos en español.
 */
interface OpportunisticIncidentRepositoryInterface
{
    /**
     * Sedes habilitadas para una intervención oportunista del técnico.
     *
     * `ACTIVE_STOP` cuando tiene una incidencia correctiva en `ASSIGNED` /
     * `IN_PROGRESS` o una orden preventiva en `SCHEDULED` / `IN_INSPECTION` en la
     * sede. `GRACE_WINDOW_30M` cuando cerró (`RESOLVED`), pausó
     * (`PENDING_PARTS` / `PENDING_INFO`) o completó un preventivo
     * (`COMPLETED`) allí dentro de la ventana aplicable: 30 minutos por defecto y
     * 35 minutos cuando el formulario ya estaba abierto (`RF-01.3`).
     *
     * La sede gana siempre la parada vigente sobre la gracia: una sede con trabajo
     * activo no anuncia caducidad. Un `PENDING_INFO` antiguo, en cambio, no aparece:
     * el estado pausado solo abre la ventana en el instante en que se pausó
     * (Resolución QA 3).
     *
     * @param int $technicianId Técnico autenticado.
     * @param DateTimeImmutable $now Instante de la petición.
     * @param bool $formOpenedWithinGrace Si el formulario ya estaba abierto al entrar en gracia.
     * @return list<array{
     *     location_id: int,
     *     location_name: string,
     *     location_address: string,
     *     enablement_type: string,
     *     active_stop_count: int,
     *     grace_expires_at: ?string,
     *     grace_remaining_seconds: ?int,
     *     last_intervention_reference: ?string,
     *     last_intervention_transition_at: ?string
     * }> Sedes habilitadas, con la parada vigente primero y después por nombre.
     */
    public function findOpportunisticEnabledLocations(
        int $technicianId,
        DateTimeImmutable $now,
        bool $formOpenedWithinGrace = false
    ): array;

    /**
     * Hechos de visita de una sede concreta para `OpportunisticLocationEligibilityPolicy`.
     *
     * Devuelve las paradas del técnico en la sede y la última salida registrada, de
     * modo que el servicio pueda revalidar la habilitación en el instante exacto de
     * crear o asumir (y no solo al pintar la ruta), aplicando el mismo margen de
     * 35 minutos si el formulario venía abierto.
     *
     * @return array{
     *     corrective_stops: list<array{id: int, status: string, location_id: int, ticket_code: string}>,
     *     preventive_orders: list<array{id: int, status: string, location_id: int, order_code: string}>,
     *     last_stop_exit_at: ?string,
     *     last_intervention_reference: ?string
     * }
     */
    public function findLocationVisitFacts(int $technicianId, int $locationId): array;

    /**
     * Catálogo de máquinas operativas de la sede con su expediente activo o en garantía.
     *
     * Excluye las bajas definitivas (`is_active = false` **y**
     * `is_blocked_no_access = false`, `RF-01.5`) y las borradas lógicamente. Para
     * cada máquina devuelve el expediente que ocuparía la acción oportunista:
     * el activo si existe; si no, el `RESOLVED` dentro de la garantía de 48 horas
     * (Art. V.6). `reopen_count` cuenta los eventos `REOPENED` del historial y
     * `will_trigger_chronic_replacement` avisa de que la siguiente reapertura
     * supera el límite de dos y crea el sucesor limpio (RF-05.4.2).
     *
     * @param int $locationId Sede consultada.
     * @param DateTimeImmutable $now Instante de la petición (referencia de la garantía).
     * @return list<array{
     *     id: int,
     *     code: string,
     *     model: string,
     *     machine_type: string,
     *     floor_wing: string,
     *     is_active: bool,
     *     is_blocked_no_access: bool,
     *     sanitary_status: string,
     *     active_incident: ?array<string, mixed>
     * }> Máquinas ordenadas por ubicación y código.
     */
    public function findLocationMachineCatalog(int $locationId, DateTimeImmutable $now): array;

    /**
     * Intervenciones abiertas del técnico en **otra** sede (`RF-02.2`).
     *
     * Incluye incidencias en `IN_PROGRESS` y órdenes preventivas en
     * `IN_INSPECTION`. La sede destino queda fuera a propósito: coexistir dentro
     * del mismo edificio es legítimo. El servicio la convierte en
     * `409 ACTIVE_INTERVENTION_AT_DIFFERENT_LOCATION` con el conflicto más antiguo.
     *
     * @param int $technicianId Técnico autenticado.
     * @param int $targetLocationId Sede donde se quiere operar.
     * @return list<array{
     *     id: int,
     *     record_type: string,
     *     ticket_code: string,
     *     status: string,
     *     urgency: ?string,
     *     started_at: ?string,
     *     location_id: int,
     *     location_name: ?string,
     *     machine_id: int,
     *     machine_code: ?string,
     *     floor_wing: ?string
     * }> Paradas abiertas en otras sedes.
     */
    public function findActiveInterventionsInOtherLocations(int $technicianId, int $targetLocationId): array;

    /**
     * Paradas pendientes del técnico en otra sede que disparan la advertencia del Art. II.
     *
     * Solo devuelve las que siguen esperando (`ASSIGNED` / `PENDING_INFO`) y
     * cumplen al menos una condición de `RF-03.1`: urgencia `CRITICAL`, máquina de
     * perecederos o combo con fallo térmico o corte eléctrico, o máquina en
     * cuarentena sanitaria del Módulo 11. La decisión de exigir confirmación y de
     * encolar la alerta se toma después, en `OpportunisticColdChainGuardPolicy`.
     *
     * @param int $technicianId Técnico autenticado.
     * @param int $targetLocationId Sede donde se quiere operar.
     * @return list<array{
     *     id: int,
     *     incident_id: int,
     *     ticket_code: string,
     *     status: string,
     *     urgency: string,
     *     category: string,
     *     machine_id: int,
     *     machine_code: ?string,
     *     machine_type: string,
     *     sanitary_status: string,
     *     location_id: int,
     *     location_name: ?string,
     *     sla_target_at: ?string
     * }> Paradas críticas o sanitarias postergadas, por urgencia y SLA.
     */
    public function findPendingCriticalStopsInOtherLocations(int $technicianId, int $targetLocationId): array;

    /**
     * Flujo A · Alta Oportunista In Situ (`RF-04`).
     *
     * Persiste el expediente `NEW` tal y como lo construyó el servicio: ya en
     * `IN_PROGRESS`, asignado al técnico presente y con su modalidad y submotivo
     * de origen. El alta y su primer evento de historial comparten transacción, y
     * el índice único de la máquina sigue siendo la última palabra: si otro canal
     * abrió un ticket entre la lectura del catálogo y esta escritura, el choque se
     * traduce en `DuplicateIncidentException` para que el cliente ofrezca asumirlo
     * en un toque (`409 ACTIVE_INCIDENT_ALREADY_EXISTS_ASSUME_INSTEAD`).
     *
     * @param Incident $incident Expediente oportunista ya validado por el caso de uso.
     * @param int|null $userId Usuario que firma el alta (el técnico autenticado).
     * @param string|null $initialNote Nota inmutable del primer evento.
     * @return Incident Expediente persistido con su identificador.
     *
     * @throws \InvalidArgumentException Si el agregado no es oportunista o no nace en curso.
     * @throws \VendGuard\Core\Domain\Exception\DuplicateIncidentException Si la máquina ya tiene
     *         un expediente activo o en garantía.
     */
    public function createOpportunisticIncident(
        Incident $incident,
        ?int $userId = null,
        ?string $initialNote = null
    ): Incident;

    /**
     * Asunción directa de un expediente sin técnico (`RF-05.2`, caso 1).
     *
     * Transiciona `REGISTERED` / `REOPENED` a `IN_PROGRESS` bajo titularidad del
     * técnico presente, marcándolo como `ASSUMED` con submotivo
     * `EXISTING_TICKET_CLAIM`. Conserva el `assigned_at` que ya tuviera y solo fija
     * los instantes que faltaban, para no reescribir la antigüedad de la parada.
     *
     * No envía ningún aviso a la Sede: el ticket ya estaba abierto y la Sede ya
     * conocía su existencia (`RF-06.3.1`).
     *
     * @param int $incidentId Expediente a asumir.
     * @param int $technicianId Técnico presente en la sede.
     * @param DateTimeImmutable|null $now Instante de la asunción (inyectable en pruebas).
     * @return Incident Expediente ya en curso.
     *
     * @throws \DomainException Si el expediente no existe.
     * @throws \VendGuard\Core\Domain\Exception\InvalidTransitionException Si su estado no es
     *         `REGISTERED` ni `REOPENED`.
     */
    public function claimOpportunisticIncident(
        int $incidentId,
        int $technicianId,
        ?DateTimeImmutable $now = null
    ): Incident;

    /**
     * Traspaso in situ de un expediente asignado o pausado a otro técnico (`RF-05.3`).
     *
     * Exige que el expediente esté en `ASSIGNED`, `PENDING_PARTS` o `PENDING_INFO`
     * y a nombre de **otro** compañero; la confirmación expresa la valida el caso de
     * uso antes de llamar aquí. La primitiva desasigna al titular anterior, asigna
     * al técnico presente, pasa a `IN_PROGRESS` y marca `ASSUMED` /
     * `EXISTING_TICKET_CLAIM`.
     *
     * Si el expediente venía de `PENDING_INFO`, cierra el intervalo de pausa vivo
     * acumulando sus segundos de pared en `total_pending_info_seconds`, que es lo
     * que descuentan MTTR y SLA. El desplazamiento del vencimiento en horario
     * comercial **no** se decide aquí: depende del calendario de la sede y lo
     * compone `IncidentPauseService` en la misma unidad de trabajo antes de ceder
     * el expediente.
     *
     * La nota de traspaso, si llega, se guarda como comentario **interno**
     * (`is_internal = TRUE`), invisible para la Sede y para el Ciudadano QR
     * (Art. V.4, Resolución QA 5).
     *
     * @param int $incidentId Expediente a traspasar.
     * @param int $technicianId Técnico que lo asume in situ.
     * @param string|null $takeoverInternalNote Nota interna opcional del traspaso.
     * @param DateTimeImmutable|null $now Instante del traspaso.
     * @return array{
     *   incident: Incident,
     *   previous_technician_id: int,
     *   previous_technician_name: string|null
     * } Expediente traspasado y titular saliente, para que el servicio cancele sus avisos.
     *
     * @throws \DomainException Si el expediente no existe.
     * @throws \VendGuard\Core\Domain\Exception\InvalidTransitionException Si no está a nombre de
     *         otro técnico o su estado no admite traspaso.
     */
    public function takeOverOpportunisticIncident(
        int $incidentId,
        int $technicianId,
        ?string $takeoverInternalNote = null,
        ?DateTimeImmutable $now = null
    ): array;

    /**
     * Reapertura in situ en garantía, 1.ª o 2.ª reincidencia (`RF-05.4.1`).
     *
     * Reabre el **mismo** expediente `RESOLVED` dentro de las 48 horas
     * (Art. V.6) y lo deja `IN_PROGRESS` con el técnico presente: limpia la
     * resolución, reinicia el ciclo, fija el motivo y marca `ASSUMED` /
     * `WARRANTY_REOPEN_IN_SITU`. El historial registra la transición a `REOPENED`
     * —que es la que cuenta reincidencias— seguida de la entrada en curso.
     *
     * La 3.ª reincidencia no pasa por aquí: el caso de uso debe llamar a
     * `replaceChronicOpportunisticIncident()`.
     *
     * @param int $incidentId Expediente resuelto en garantía.
     * @param int $technicianId Técnico presente en la sede.
     * @param string $reopenReason Motivo de la reincidencia (≥ 10 caracteres, ya validado).
     * @param DateTimeImmutable|null $now Instante de la reapertura.
     * @return Incident Expediente reabierto y en curso.
     *
     * @throws \DomainException Si el expediente no existe.
     * @throws \VendGuard\Core\Domain\Exception\InvalidTransitionException Si no está `RESOLVED`
     *         o ya acumula dos reaperturas.
     * @throws \VendGuard\Core\Domain\Exception\WarrantyExpiredException Si superó la garantía de 48 h.
     */
    public function reopenOpportunisticIncident(
        int $incidentId,
        int $technicianId,
        string $reopenReason,
        ?DateTimeImmutable $now = null
    ): Incident;

    /**
     * Sustitución atómica por Avería Crónica en la 3.ª reincidencia (`RF-05.4.2`).
     *
     * Cierra el expediente crónico con la marca `[AVERÍA CRÓNICA]` y `CLOSED`
     * —liberando en el mismo acto el índice único `uq_machine_active_ticket`— e
     * inserta inmediatamente el sucesor limpio que el caso de uso ya construyó: en
     * `IN_PROGRESS`, `NEW`, con submotivo `WARRANTY_REOPEN_IN_SITU` y su
     * `chronic_predecessor_incident_id` apuntando al cerrado.
     *
     * Si la inserción del sucesor falla, la transacción revierte el cierre: nunca
     * puede quedar una máquina sin expediente vivo por un fallo a mitad del
     * reemplazo (Art. III y Art. V.2).
     *
     * @param int $predecessorIncidentId Expediente resuelto que se cierra como crónico.
     * @param Incident $successor Expediente limpio ya construido por el caso de uso.
     * @param int $technicianId Técnico que firma el cierre y la apertura.
     * @param DateTimeImmutable|null $now Instante de la sustitución.
     * @return Incident Sucesor persistido con su identificador.
     *
     * @throws \DomainException Si el predecesor no existe.
     * @throws \InvalidArgumentException Si el sucesor no declara el predecesor o no nace en curso.
     * @throws \VendGuard\Core\Domain\Exception\InvalidTransitionException Si el predecesor no está
     *         `RESOLVED` o no acumula las dos reaperturas que hacen crónica la tercera.
     */
    public function replaceChronicOpportunisticIncident(
        int $predecessorIncidentId,
        Incident $successor,
        int $technicianId,
        ?DateTimeImmutable $now = null
    ): Incident;
}
