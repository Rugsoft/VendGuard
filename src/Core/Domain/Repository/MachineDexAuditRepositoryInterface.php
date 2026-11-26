<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\MachineDexAudit;

/**
 * MachineDexAuditRepositoryInterface
 *
 * Contrato de persistencia del libro de auditorías EVA-DTS `.dex` (Módulo 39,
 * Tablas 52–54; RF-01.1, RF-04.1, RF-07.1, RF-07.2, RNF-01, D1, C1, C3, C6,
 * QA-1, QA-2 y Art. III).
 *
 * **Escritura de una sola pieza.** `create()` inserta la cabecera inmutable y
 * sus líneas `PA1`/`PA2` y `EA1`/`EA2` en una transacción. Nunca actualiza la
 * captura: corregir un volcado es importar otro (Art. III.3). La única escritura
 * posterior es `void()`, la transición `ACTIVE → VOIDED` con motivo real.
 *
 * **Lectura sin payload.** Todas las consultas de listado, detalle y KPIs
 * devuelven arrays sin `raw_payload` (RNF-01). El contenido crudo solo sale por
 * `findRawPayload()`, que alimenta el endpoint aislado de Coordinación con
 * `nosniff` (C3, QA-5).
 *
 * **Cadena de deltas.** `findPreviousActive()` busca el último volcado `ACTIVE`
 * con `captured_at <=` el nuevo (orden `captured_at DESC, id DESC`), y
 * `hasLaterActive()` detecta el volcado posterior que marca
 * `out_of_order_sync` (QA-2). Los volcados `VOIDED` no participan.
 *
 * Dogma Vanilla: PHP 8.2+ puro, contrato sin dependencias de infraestructura.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface MachineDexAuditRepositoryInterface
{
    /**
     * Reserva el siguiente código `DEX-AAAA-NNNN` del año operativo (Europe/Madrid).
     */
    public function reserveAuditCode(?DateTimeImmutable $now = null): string;

    /**
     * Inserta cabecera y líneas en una transacción y devuelve la cabecera con `id`.
     *
     * @param array<int, array<string, mixed>> $selectionLines Salida de `DexSelectionMatcher`.
     * @param array<int, array<string, mixed>> $eventLines Salida de `EvaDtsParser`.
     */
    public function create(
        MachineDexAudit $audit,
        array $selectionLines = [],
        array $eventLines = []
    ): MachineDexAudit;

    /**
     * Detalle completo sin `raw_payload`, con selecciones, eventos y `has_thermal_alert`.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $auditId): ?array;

    /**
     * Lectura aislada del `.dex` original para descarga de Coordinación (C3).
     *
     * @return array{raw_payload:string, audit_code:string, original_filename:string, captured_at:string}|null
     */
    public function findRawPayload(int $auditId): ?array;

    /**
     * Identificador del volcado `ACTIVE` con esa huella, o `null` (D3).
     */
    public function findActiveIdByHash(int $machineId, string $sha256Hash): ?int;

    /**
     * Volcado de un actor con esa mutación de cliente, para el replay Offline (C5).
     *
     * @return array{id:int, machine_id:int, status:string}|null
     */
    public function findByActorMutation(int $importedBy, string $clientMutationId): ?array;

    /**
     * Último `ACTIVE` de la máquina con `captured_at <= $capturedAt` (QA-2).
     *
     * `$excludeAuditId` permite calcular el predecesor efectivo de un volcado ya
     * persistido sin que él mismo se devuelva como su propio predecesor.
     *
     * @return array<string, mixed>|null
     */
    public function findPreviousActive(int $machineId, string $capturedAt, ?int $excludeAuditId = null): ?array;

    /**
     * ¿Existe algún `ACTIVE` posterior al instante indicado? (QA-2).
     */
    public function hasLaterActive(int $machineId, string $capturedAt): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function findSelectionsByAudit(int $auditId): array;

    /**
     * Listado paginado de Coordinación, sin `raw_payload` (RF-07.1, RNF-01).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listForCoordinator(array $filters = [], int $limit = 100, int $offset = 0): array;

    /**
     * Cinco KPIs sobre el filtro activo, sin `raw_payload` (RF-07.1).
     *
     * @param array<string, mixed> $filters
     * @return array{total_active:int, thermal_alert_count:int, warning_count:int, cash_deviation_count:int, voided_count:int}
     */
    public function kpisForCoordinator(array $filters = []): array;

    /**
     * Cabecera mínima del volcado bajo cerrojo, para validar y enlazar M34/M35/M36 (QA-5).
     *
     * @return array{id:int, audit_code:string, machine_id:int, status:string, restock_visit_id:int|null, cash_collection_id:int|null, incident_id:int|null}|null
     */
    public function findLinkTargetForUpdate(int $auditId): ?array;

    /**
     * Enlaza el volcado con una visita M34 si el vínculo sigue libre.
     */
    public function linkToRestockVisit(int $auditId, int $restockVisitId): void;

    /**
     * Enlaza el volcado con una recaudación M35 si el vínculo sigue libre.
     */
    public function linkToCashCollection(int $auditId, int $cashCollectionId): void;

    /**
     * Enlaza el volcado con un expediente M36 si el vínculo sigue libre.
     */
    public function linkToIncident(int $auditId, int $incidentId): void;

    /**
     * Anula lógicamente un volcado `ACTIVE` y devuelve el detalle resultante.
     *
     * @return array<string, mixed>
     */
    public function void(
        int $auditId,
        string $reason,
        int $voidedBy,
        string $voidedByName,
        string $voidedAt
    ): array;
}
