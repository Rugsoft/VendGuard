<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Location;

/**
 * LocationZoneRepositoryInterface
 *
 * Capacidad territorial de la ficha maestra de Sede que necesita el Módulo 17:
 * resolver la zona heredada por código postal y conmutar la anulación manual
 * (RF-03.1, RF-03.2, RF-03.3).
 *
 * Vive como contrato separado —igual que `LocationBatchRepositoryInterface` y
 * `LocationContactEmailRepositoryInterface`— para no obligar a las veinte
 * implementaciones de sede que no participan en la zonificación a declarar
 * métodos que nunca usan.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface LocationZoneRepositoryInterface
{
    /**
     * Recupera una sede activa por su identificador primario.
     *
     * @param int $id Identificador de la sede.
     * @return Location|null Null si no existe o está dada de baja.
     */
    public function findById(int $id): ?Location;

    /**
     * Resuelve la zona activa que gobierna un código postal.
     *
     * Solo consulta asignaciones vivas: un CP liberado (`released_at`) ya no
     * arrastra sedes a la demarcación que lo tuvo (QA 13).
     *
     * @param string $postalCode Código postal de cinco dígitos.
     * @return int|null Zona activa, o null si el CP no pertenece a ninguna.
     */
    public function findInheritedZoneByPostalCode(string $postalCode): ?int;

    /**
     * Fuerza la zona de una sede y enciende `is_manual_override` (RF-03.2).
     *
     * Con `$zoneId = null` la sede queda forzada a «Sin Zona Asignada»: sigue
     * siendo una decisión operativa y no una herencia pendiente.
     *
     * @param int $locationId Identificador de la sede.
     * @param int|null $zoneId Zona forzada, o null para dejarla sin zona.
     * @param int|null $userId Coordinador que firma la excepción; con `null` no se audita.
     * @return bool True si la sede existía.
     */
    public function setManualOverride(int $locationId, ?int $zoneId, ?int $userId = null): bool;

    /**
     * Restablece la herencia automática desde el código postal (RF-03.3).
     *
     * @param int $locationId Identificador de la sede.
     * @param int|null $userId Coordinador que firma el cambio; con `null` no se audita.
     * @return int|null Zona heredada resultante, o null si el CP no pertenece a ninguna activa.
     */
    public function clearManualOverride(int $locationId, ?int $userId = null): ?int;
}
