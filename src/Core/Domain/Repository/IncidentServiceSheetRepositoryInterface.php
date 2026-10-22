<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\IncidentServiceSheet;

/**
 * IncidentServiceSheetRepositoryInterface
 *
 * Contrato de persistencia de las hojas de servicio del Módulo 19.
 *
 * Solo hay tres operaciones y **ninguna** modifica ni borra: la hoja es prueba
 * documental (Art. III.1). La escritura se hace con una única sentencia que
 * compone el documento congelado desde las filas que la transacción del cierre
 * acaba de escribir, de modo que el papel firmado no pueda contar algo distinto
 * de lo que se guardó (RD-10).
 */
interface IncidentServiceSheetRepositoryInterface
{
    /**
     * Inserta la hoja dentro de la transacción del cierre y devuelve la fila
     * con su identificador, su instante de registro y el documento congelado.
     *
     * @throws \RuntimeException Si la sentencia no escribe ninguna fila.
     */
    public function insert(IncidentServiceSheet $sheet): IncidentServiceSheet;

    /**
     * Compone el modelo de lectura del albarán (contratos §4.4).
     *
     * Devuelve el documento congelado de la hoja vigente o la solicitada, o la
     * proyección viva de la incidencia cuando no hay hoja. El parámetro
     * `$sheetId` permite imprimir una visita anterior tras una reapertura (Q2).
     *
     * @return array{
     *     ticket_code: string,
     *     status: string,
     *     current: bool,
     *     sheet: array<string, mixed>|null,
     *     document: array<string, mixed>,
     *     history: list<array<string, mixed>>
     * }|null Null si la incidencia no existe
     */
    public function findDocumentByIncidentId(int $incidentId, ?int $sheetId = null): ?array;

    /**
     * Autorización del técnico sobre el albarán (RD-17, contratos §6.4).
     *
     * Verdadero cuando la avería está asignada a ese técnico **o** alguna de sus
     * hojas la firmó él. El firmante sigue leyendo su papel después de que la
     * sede reabra y coordinación reasigne el expediente: la firma es su prueba
     * de haber intervenido.
     */
    public function isReadableByTechnician(int $incidentId, int $technicianId): bool;

    /**
     * Historial de hojas de la incidencia, de la más reciente a la más antigua.
     *
     * @return list<array<string, mixed>>
     */
    public function listByIncidentId(int $incidentId): array;
}
