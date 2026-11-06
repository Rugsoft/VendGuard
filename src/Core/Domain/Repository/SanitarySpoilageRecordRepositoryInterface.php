<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\SanitarySpoilageRecord;

/**
 * Contrato de persistencia del Acta de Merma y Destrucción de Lotes por
 * Cuarentena Sanitaria (Módulo 29, Tabla 40).
 *
 * El contrato concentra las lecturas y escrituras que el caso de uso de
 * levantamiento necesita para ser atómico (RF-03.1, D1, C1 y C6):
 *
 *   1. **Lectura bloqueante de la máquina.** `lockAndInspectMachineQuarantineState()`
 *      abre la transacción con un `SELECT ... FOR UPDATE` sobre `machines`, de
 *      modo que dos levantamientos concurrentes de la misma máquina no pueden
 *      validar a la vez el estado `QUARANTINE`.
 *
 *   2. **Guardas de integridad.** `hasOpenCorrectiveIncident()` y
 *      `isActiveTechnician()` resuelven en el motor las dos condiciones que no
 *      pueden quedar en manos del cliente: que no queden averías correctivas
 *      abiertas (`C1`) y que el técnico certificador siga activo (`QA 1`).
 *
 *   3. **Determinación determinista del origen.** `resolveLatestQuarantineEpisodeLinks()`
 *      busca, desde el último levantamiento previo de la máquina, la avería
 *      resuelta con cuarentena térmica y la orden preventiva no conforme más
 *      recientes. La jerarquía `BIOLOGICAL_CLOCK_4H` →
 *      `PREVENTIVE_NON_CONFORMITY` → `MANUAL_SANITARY_LOCK` se decide con esos
 *      vínculos, nunca con el orden en que el navegador los envía (`QA 2`).
 *
 *   4. **Numeración oficial segura.** `nextRecordCode()` reserva el siguiente
 *      correlativo `ACTA-MERMA-AAAA-NNNN` bajo un bloqueo de asesoría de
 *      PostgreSQL. Debe invocarse dentro de la misma transacción que `create()`
 *      para que el hueco reservado no quede expuesto a otra sesión.
 *
 *   5. **Levantamiento y certificado.** `completePendingReinspectionAndRestoreMachineOk()`
 *      cierra las órdenes de reinspección pendientes, restituye la máquina a
 *      `OK`, actualiza las fechas normativas y emite el Certificado Higiénico
 *      oficial. `sanitary_certificates.preventive_order_id` es obligatorio: si
 *      no había ninguna reinspección pendiente, el adaptador documenta la
 *      inspección creando una orden de reinspección ya completada, nunca
 *      inventando un vínculo con una orden de otro tipo.
 *
 * La configuración sanitaria vive en columnas de `machines`
 * (`sanitary_status`, `sanitary_frequency_days`, `last_sanitary_inspection_at`,
 * `next_sanitary_inspection_due`). El borrador de la tarea citaba
 * `machine_sanitary_settings`, una tabla que no existe en el esquema canónico;
 * este contrato lee la fuente real y mantiene el mismo significado.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface SanitarySpoilageRecordRepositoryInterface
{
    /**
     * Bloquea la fila de la máquina y devuelve su estado sanitario real.
     *
     * La lectura incluye `is_active`, `deleted_at`, `machine_type`,
     * `sanitary_status`, `sanitary_frequency_days` y la frecuencia efectiva
     * (la de la máquina o, en su defecto, la predeterminada de su tipología).
     *
     * @param int $machineId Identificador de la máquina.
     * @return array<string, mixed>|null Estado bloqueado, o `null` si la máquina no existe.
     */
    public function lockAndInspectMachineQuarantineState(int $machineId): ?array;

    /**
     * Indica si la máquina conserva alguna avería correctiva abierta.
     *
     * Una avería está abierta mientras su estado no sea `RESOLVED`, `CLOSED` o
     * `CANCELLED` y no haya sido borrada lógicamente. El levantamiento queda
     * bloqueado mientras exista una (`C1`).
     *
     * @param int $machineId Identificador de la máquina.
     * @return bool `true` si existe al menos una avería correctiva abierta.
     */
    public function hasOpenCorrectiveIncident(int $machineId): bool;

    /**
     * Comprueba que el usuario sea un técnico activo y no eliminado.
     *
     * Se usa para certificar la autoría real del técnico ejecutor in situ
     * cuando quien registra el acta es un Coordinador (`QA 1`).
     *
     * @param int $userId Identificador del usuario.
     * @return bool `true` si el usuario es un técnico activo.
     */
    public function isActiveTechnician(int $userId): bool;

    /**
     * Resuelve los vínculos del episodio de cuarentena que se está levantando.
     *
     * Toma como frontera el `created_at` del último acta de la máquina y busca,
     * estrictamente después de esa frontera:
     *   - la avería resuelta más reciente con cuarentena térmica;
     *   - la orden preventiva completada `NO_CONFORME` más reciente.
     *
     * Devuelve además el origen determinista: `BIOLOGICAL_CLOCK_4H` si hay
     * avería térmica, `PREVENTIVE_NON_CONFORMITY` si hay orden no conforme y
     * `MANUAL_SANITARY_LOCK` en cualquier otro caso (`QA 2`).
     *
     * @param int $machineId Identificador de la máquina.
     * @return array<string, mixed> Claves `quarantine_origin`, `incident_id`,
     *         `maintenance_order_id`, `episode_started_at`,
     *         `latest_resolved_incident` y `latest_non_conform_order`.
     */
    public function resolveLatestQuarantineEpisodeLinks(int $machineId): array;

    /**
     * Reserva el siguiente código oficial correlativo del año indicado.
     *
     * La reserva se apoya en `pg_advisory_xact_lock`, por lo que debe llamarse
     * dentro de la transacción que insertará el acta. El formato devuelto es
     * `ACTA-MERMA-AAAA-NNNN`.
     *
     * @param int $year Año natural del acta.
     * @return string Código oficial reservado.
     */
    public function nextRecordCode(int $year): string;

    /**
     * Reserva el siguiente código oficial de certificado sanitario del año.
     *
     * La reserva usa el mismo bloqueo de asesoría que `nextRecordCode()` y debe
     * invocarse dentro de la transacción que insertará el certificado. El
     * formato devuelto es `CERT-AAAA-NNNN` y nunca reutiliza un código ya
     * emitido, incluidos los que provengan de otros flujos de inspección.
     *
     * @param int $year Año natural del certificado.
     * @return string Código oficial reservado.
     */
    public function nextCertificateCode(int $year): string;

    /**
     * Persiste un acta y devuelve la entidad hidratada con su clave primaria.
     *
     * @param array<string, mixed> $data Hechos sanitarios y vínculos validados.
     * @return SanitarySpoilageRecord Acta creada.
     */
    public function create(array $data): SanitarySpoilageRecord;

    /**
     * Busca un acta por su clave primaria.
     *
     * @param int $id Identificador del acta.
     * @return SanitarySpoilageRecord|null
     */
    public function findById(int $id): ?SanitarySpoilageRecord;

    /**
     * Busca un acta por su clave primaria y bloquea su fila hasta el fin de la
     * transacción. Es la lectura que usa el ajuste económico de Coordinación
     * para comparar el importe vigente con el nuevo (`C5`, `QA 4`).
     *
     * @param int $id Identificador del acta.
     * @return SanitarySpoilageRecord|null
     */
    public function findByIdForUpdate(int $id): ?SanitarySpoilageRecord;

    /**
     * Devuelve el historial completo de actas de una máquina.
     *
     * El orden es determinista: fecha de emisión descendente y, como
     * desempate, identificador descendente (`QA 4`).
     *
     * @param int $machineId Identificador de la máquina.
     * @return list<SanitarySpoilageRecord>
     */
    public function findByMachineId(int $machineId): array;

    /**
     * Calcula los cuatro indicadores globales del panel de Coordinación.
     *
     * Los indicadores son inmunes a los filtros de la tabla inferior: se
     * calculan siempre sobre el histórico completo y sobre el parque activo
     * (`QA 3`). `active_quarantined_machines_count` excluye las máquinas dadas
     * de baja lógica o inactivas; las actas históricas de esas máquinas siguen
     * contando en los otros tres acumulados (Art. III.3).
     *
     * @return array<string, mixed> Claves `active_quarantined_machines_count`,
     *         `total_records_count`, `total_discarded_units_count` y
     *         `total_estimated_loss_eur`.
     */
    public function fetchKpiSummary(): array;

    /**
     * Lista las máquinas activas que están actualmente en cuarentena.
     *
     * Cada fila incluye los datos de la máquina y la sede y, cuando existe,
     * la avería correctiva abierta que bloquea el levantamiento (`C1`). El
     * orden es por código de máquina ascendente (`QA 4`).
     *
     * @return list<array<string, mixed>> Claves `machine_id`, `machine_code`,
     *         `machine_model`, `machine_type`, `location_id`, `location_name`,
     *         `sanitary_status`, `last_sanitary_inspection_at`,
     *         `next_sanitary_inspection_due`, `has_open_incident`,
     *         `open_incident_id`, `open_incident_code` y `open_incident_status`.
     */
    public function fetchActiveQuarantinedMachines(): array;

    /**
     * Devuelve el libro de actas filtrado para el panel de Coordinación.
     *
     * Los filtros son combinables y todos opcionales: `machine_id`,
     * `quarantine_origin`, `disposal_method`, `date_from` (`YYYY-MM-DD`),
     * `date_to` (`YYYY-MM-DD`) y `search`. La búsqueda textual se aplica con
     * `ILIKE` sobre el código de acta, los lotes, el certificado, el código y
     * modelo de la máquina y el nombre de la sede. El orden es siempre
     * `created_at DESC, id DESC` (`QA 4`).
     *
     * @param array<string, mixed> $filters Filtros opcionales del panel.
     * @return list<SanitarySpoilageRecord>
     */
    public function findFilteredForCoordinator(array $filters): array;

    /**
     * Actualiza el coste estimado y registra el ajuste auditado.
     *
     * Solo escribe las tres columnas que el disparador de inmutabilidad permite
     * modificar (`estimated_merchandise_loss_eur`, `loss_adjusted_by` y
     * `loss_adjustment_reason`). La validación semántica previa corresponde al
     * caso de uso y al validador de dominio.
     *
     * @param int $id Identificador del acta.
     * @param float $lossEur Nuevo importe estimado.
     * @param int $adjustedByUserId Coordinador que autoriza el ajuste.
     * @param string $reason Justificación auditada.
     * @return SanitarySpoilageRecord Acta actualizada.
     */
    public function updateLossEstimate(
        int $id,
        float $lossEur,
        int $adjustedByUserId,
        string $reason
    ): SanitarySpoilageRecord;

    /**
     * Cierra la cuarentena y expide el Certificado Higiénico oficial.
     *
     * En una sola transacción lógica:
     *   1. cierra como `CONFORME` las órdenes de reinspección pendientes de la
     *      máquina;
     *   2. si no había ninguna, crea una orden de reinspección ya completada
     *      para que el certificado tenga el anclaje obligatorio que exige
     *      `sanitary_certificates.preventive_order_id`;
     *   3. restituye `machines.sanitary_status` a `OK`, actualiza
     *      `last_sanitary_inspection_at` y calcula `next_sanitary_inspection_due`;
     *   4. inserta el certificado sanitario con el código oficial recibido.
     *
     * @param int $machineId Identificador de la máquina.
     * @param int $technicianId Técnico ejecutor in situ.
     * @param float $restoredTempC Temperatura estabilizada de reapertura.
     * @param string $certificateCode Código oficial del nuevo certificado.
     * @param string $notes Observaciones sanitarias del acta.
     * @return array<string, mixed> Resultado del levantamiento con las claves
     *         `reinspection_orders_closed`, `reinspection_order_id`,
     *         `reinspection_order_code`, `reinspection_order_created`,
     *         `certificate_id`, `certificate_code`, `valid_until`,
     *         `next_sanitary_inspection_due`, `last_sanitary_inspection_at`,
     *         `frequency_days` y `machine_sanitary_status`.
     */
    public function completePendingReinspectionAndRestoreMachineOk(
        int $machineId,
        int $technicianId,
        float $restoredTempC,
        string $certificateCode,
        string $notes
    ): array;
}
