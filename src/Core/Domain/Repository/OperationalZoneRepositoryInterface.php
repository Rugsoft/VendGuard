<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Exception\PrimaryTechnicianConflictException;
use VendGuard\Core\Domain\Exception\ZoneValidationException;
use VendGuard\Core\Domain\Model\OperationalZone;

/**
 * OperationalZoneRepositoryInterface
 *
 * Puerto de persistencia del catálogo territorial del Módulo 17: la zona
 * operativa, sus códigos postales y su cuadrante técnico (RF-01.1, RF-02.1).
 *
 * El contrato **no** declara borrados físicos porque no existen: una zona se
 * desactiva, una vinculación se extingue con `removed_at` y el histórico no se
 * pierde nunca (Art. III.1, Resolución QA 10). Por eso aquí no hay `delete()`.
 *
 * Lecturas y escrituras se separan a propósito. El listado agregado existe para
 * que el panel del coordinador no pague una consulta por zona: los contadores de
 * sedes, titulares, suplentes y códigos postales viajan en la misma sentencia
 * (RNF-01).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface OperationalZoneRepositoryInterface
{
    /**
     * Recupera una zona por su identificador primario.
     *
     * @param int $zoneId Identificador de la zona.
     * @param bool $includeInactive Si es false, una zona desactivada se trata como inexistente.
     * @return OperationalZone|null
     */
    public function findById(int $zoneId, bool $includeInactive = false): ?OperationalZone;

    /**
     * Recupera una zona por su código identificativo, sin distinguir mayúsculas.
     *
     * Devuelve también las zonas desactivadas: el código es único en todo el
     * catálogo y quien comprueba una colisión necesita ver la fila que la provoca.
     *
     * @param string $code Código alfanumérico (ej. `ZONA-NORTE-BCN`).
     * @return OperationalZone|null
     */
    public function findByCode(string $code): ?OperationalZone;

    /**
     * Listado del catálogo con sus contadores agregados, en **una sola** sentencia.
     *
     * Una zona sin sedes, sin técnicos o sin códigos postales aparece con ceros:
     * el panel del coordinador necesita verla para poder configurarla.
     *
     * @param bool $includeInactive Si es false, solo devuelve zonas activas.
     * @return list<array{
     *     zone: OperationalZone,
     *     locations_count: int,
     *     primary_technicians_count: int,
     *     backup_technicians_count: int,
     *     postal_codes_count: int
     * }>
     */
    public function findAllWithCounts(bool $includeInactive = true): array;

    /**
     * Una zona con sus contadores agregados, leída igual que el listado (RNF-01).
     *
     * Existe para que el alta, la edición y la desactivación devuelvan la ficha
     * completa sin recorrer el catálogo entero ni inventar contadores a cero.
     *
     * @param int $zoneId Identificador de la zona.
     * @return array{
     *     zone: OperationalZone,
     *     locations_count: int,
     *     primary_technicians_count: int,
     *     backup_technicians_count: int,
     *     postal_codes_count: int
     * }|null Null si la zona no existe.
     */
    public function findWithCounts(int $zoneId): ?array;

    /**
     * Códigos postales **vivos** de una zona, ordenados.
     *
     * Un CP liberado (`released_at`) pertenece al historial y no aparece aquí.
     *
     * @param int $zoneId Identificador de la zona.
     * @return list<string>
     */
    public function findPostalCodes(int $zoneId): array;

    /**
     * Conflictos de un lote de códigos postales con las demás zonas (RF-01.3).
     *
     * Un CP que ya vive en la zona destino no es un conflicto: volver a enviarlo
     * es idempotente. Solo se informa de los que pertenecen a **otra** zona viva,
     * junto con la zona que los gobierna y cuántas sedes heredadas arrastraría el
     * traslado, que es exactamente lo que el modal de confirmación necesita.
     *
     * @param list<string> $postalCodes Códigos postales normalizados.
     * @param int $targetZoneId Zona de destino del lote.
     * @return list<array{
     *     postal_code: string,
     *     current_zone_id: int,
     *     current_zone_name: string,
     *     affected_locations_count: int
     * }>
     */
    public function findPostalCodeConflicts(array $postalCodes, int $targetZoneId): array;

    /**
     * Da de alta los códigos postales **libres** de un lote en la zona indicada.
     *
     * Solo admite CP sin asignación viva: los que pertenecen a otra zona se mueven
     * con `transferPostalCodes()`, y este método lo rechaza para que no existan dos
     * caminos de escritura sobre la misma clave territorial (RNF-04).
     *
     * La fila nueva se inserta aunque exista una fila histórica liberada del mismo
     * CP: el histórico se conserva y el CP vuelve a estar gobernado (QA 13).
     *
     * @param int $zoneId Zona activa de destino.
     * @param list<string> $postalCodes Códigos postales libres.
     * @param int $userId Coordinador que firma la operación.
     * @return list<string> Códigos postales efectivamente asignados, ordenados.
     *
     * @throws ZoneValidationException Si la zona no está activa o algún CP ya tiene
     *   asignación viva.
     */
    public function assignPostalCodes(int $zoneId, array $postalCodes, int $userId): array;

    /**
     * Fotografía de lo que la desactivación de una zona arrastraría (RF-01.3).
     *
     * Se consulta **antes** de desactivar para poder exigir `confirm_release` con
     * cifras en la mano: el coordinador debe saber cuántos CP se liberan, cuántas
     * sedes quedan «Sin Zona Asignada» y cuántos técnicos salen del cuadrante.
     *
     * @param int $zoneId Identificador de la zona.
     * @return array{postal_codes: int, technicians: int, locations: int}
     */
    public function inspectReleaseImpact(int $zoneId): array;

    /**
     * Desactiva la zona y libera su territorio en **una sola** transacción
     * (RF-01.3, QA 7, QA 10, QA 13).
     *
     * Pasos, todos o ninguno: las sedes (heredadas y forzadas) quedan con
     * `zone_id = NULL`, los CP vivos se liberan con `released_at`, el cuadrante se
     * extingue con `removed_at`, la zona pasa a inactiva y se registra
     * `ZONE_DEACTIVATED` con el balance. Nunca se borra una fila (Art. III.1).
     *
     * @param int $zoneId Identificador de la zona.
     * @param int $userId Coordinador que firma la desactivación.
     * @return array{released_postal_codes: int, unassigned_locations: int, removed_technicians: int}
     *
     * @throws ZoneValidationException Si la zona no existe.
     */
    public function deactivateZone(int $zoneId, int $userId): array;

    /**
     * Libera los códigos postales vivos de una zona dejándolos disponibles para
     * otras demarcaciones (RF-01.2, Resolución QA 13).
     *
     * La liberación es **lógica**: la fila se conserva con `released_at` porque el
     * borrado físico está prohibido por el Art. III.1. Reactivar la zona no los
     * recupera; volver a asignarlos es una operación explícita.
     *
     * @param int $zoneId Identificador de la zona.
     * @return int Número de códigos postales liberados.
     */
    public function releasePostalCodes(int $zoneId): int;

    /**
     * Traslada códigos postales vivos a otra zona en una única transacción,
     * moviendo sus filas y arrastrando las sedes que los heredaban (RF-01.3,
     * Resolución QA 6).
     *
     * Nunca duplica una fila de CP: la actualiza. Las sedes con
     * `is_manual_override = TRUE` conservan su zona. Emite el evento matriz
     * `ZONE_POSTAL_CODE_TRANSFERRED` sobre `OPERATIONAL_ZONE` y un
     * `LOCATION_ZONE_REASSIGNED` por cada sede afectada, todo dentro de la misma
     * transacción; cualquier fallo deshace el traslado completo.
     *
     * @param int $newZoneId Zona activa de destino.
     * @param list<string> $postalCodes Códigos postales a mover.
     * @param int $userId Coordinador que ejecuta el traslado.
     * @return int Número de sedes cuya zona se recalculó.
     *
     * @throws ZoneValidationException Si la zona no es válida o algún CP no tiene
     *   asignación viva.
     * @throws \InvalidArgumentException Si la lista está vacía o el usuario no existe.
     */
    public function transferPostalCodes(int $newZoneId, array $postalCodes, int $userId): int;

    /**
     * Cuadrante vivo de una zona, con los datos del técnico que pinta el panel.
     *
     * Incluye tanto titulares como suplentes y **también** a los técnicos
     * desactivados o de baja: su vinculación sigue viva hasta que se extingue, y
     * es el motor de sugerencias quien los excluye de las recomendaciones
     * (RF-02.2). Ocultarlos aquí haría que el coordinador no pudiera darles de baja.
     *
     * @param int $zoneId Identificador de la zona.
     * @return list<array{
     *     assignment_id: int,
     *     technician_id: int,
     *     name: string,
     *     email: string,
     *     is_primary: bool,
     *     is_on_call: bool,
     *     is_active: bool,
     *     assigned_at: string|null
     * }>
     */
    public function findTechnicianAssignments(int $zoneId): array;

    /**
     * Da de alta una zona operativa.
     *
     * La entidad valida código, nombre y color antes de tocar el motor (RF-01.1);
     * la unicidad de código y nombre la impone el esquema.
     *
     * @param array<string, mixed> $data Claves: `code`, `name`, `color_hex`, `description`, `is_active`.
     * @param int|null $userId Coordinador que firma el alta; con `null` no se audita
     *   (solo lo usan las suites de persistencia que no ejercen el caso de uso).
     * @return OperationalZone Zona persistida con su identificador.
     *
     * @throws ZoneValidationException Si algún atributo no cumple el contrato.
     */
    public function create(array $data, ?int $userId = null): OperationalZone;

    /**
     * Actualiza los metadatos editables de una zona. El código es inmutable.
     *
     * @param int $zoneId Identificador de la zona.
     * @param array<string, mixed> $data Claves admitidas: `name`, `color_hex`, `description`, `is_active`.
     * @param int|null $userId Coordinador que firma la edición; con `null` no se audita.
     * @return bool True si la zona existía y se actualizó.
     *
     * @throws ZoneValidationException Si algún atributo no cumple el contrato.
     */
    public function update(int $zoneId, array $data, ?int $userId = null): bool;

    /**
     * Conmuta el estado operativo de la zona (baja lógica, Art. III.1).
     *
     * @param int $zoneId Identificador de la zona.
     * @param bool $isActive Estado destino.
     * @return bool True si la zona existía.
     */
    public function setActive(int $zoneId, bool $isActive): bool;

    /**
     * Vincula a un técnico con la zona como titular o suplente (RF-02.1, RF-02.2).
     *
     * Es idempotente sobre una vinculación viva: si el técnico ya pertenece a la
     * zona, solo se ajusta su condición de titular. Un técnico solo puede ser
     * titular vivo de una zona, así que el intento de una segunda titularidad se
     * rechaza con el detalle de la zona que perdería (RF-02.2).
     *
     * Con `$demotePreviousPrimary = true` y `$isPrimary = true`, la titularidad
     * viva que el técnico tuviera en otra zona se degrada a suplencia dentro de la
     * misma transacción y se audita con `TECHNICIAN_ZONE_PRIMARY_DEMOTED`, que es la
     * confirmación expresa `confirm_primary_override` del contrato (QA 9).
     *
     * @param int $technicianId Usuario con rol `TECHNICIAN` activo.
     * @param int $zoneId Zona activa de destino.
     * @param bool $isPrimary True para titular principal, false para suplente.
     * @param int|null $userId Coordinador que firma la vinculación; con `null` no se audita.
     * @param bool $demotePreviousPrimary Degrada la titularidad previa en lugar de rechazarla.
     * @return int Identificador de la vinculación viva.
     *
     * @throws PrimaryTechnicianConflictException Si el técnico ya es titular de otra zona
     *   y no se pidió la degradación.
     * @throws ZoneValidationException Si la zona no existe, está inactiva o el usuario no es un técnico activo.
     */
    public function assignTechnician(
        int $technicianId,
        int $zoneId,
        bool $isPrimary,
        ?int $userId = null,
        bool $demotePreviousPrimary = false
    ): int;

    /**
     * Extingue lógicamente la vinculación de un técnico con una zona (QA 10).
     *
     * La fila se conserva con `removed_at`; un reingreso posterior estrena
     * vinculación nueva y auditada.
     *
     * @param int $technicianId Identificador del técnico.
     * @param int $zoneId Identificador de la zona.
     * @param int|null $userId Coordinador que firma la baja; con `null` no se audita.
     * @return bool True si había una vinculación viva y se extinguió.
     */
    public function removeTechnician(int $technicianId, int $zoneId, ?int $userId = null): bool;
}
