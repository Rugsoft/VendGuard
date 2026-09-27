<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\PreventiveOrderItem;

/**
 * Interface PreventiveItemRepositoryInterface
 *
 * Contrato de repositorio para la persistencia, consulta y vinculación de evidencias
 * fotográficas en las respuestas al checklist normativo de inspección preventiva.
 */
interface PreventiveItemRepositoryInterface
{
    /**
     * Guarda en lote los ítems del checklist de una orden preventiva.
     *
     * Si ya existen ítems previos para la orden, actualiza las respuestas o inserta las nuevas,
     * respetando la prohibición de borrado físico destructivo (Artículo III).
     *
     * @param int $orderId Identificador de la orden preventiva
     * @param array<int, PreventiveOrderItem|array<string, mixed>> $items Colección de ítems a registrar
     * @return bool True si la inserción en lote fue exitosa
     */
    public function saveOrderItems(int $orderId, array $items): bool;

    /**
     * Recupera todos los ítems del checklist completados para una orden dada.
     *
     * @param int $orderId Identificador de la orden preventiva
     * @return array<int, PreventiveOrderItem>
     */
    public function findByOrderId(int $orderId): array;

    /**
     * Recupera un ítem del checklist por su identificador único.
     *
     * @param int $itemId Identificador del ítem
     * @return PreventiveOrderItem|null
     */
    public function findById(int $itemId): ?PreventiveOrderItem;

    /**
     * Vincula una evidencia fotográfica a un ítem específico del checklist.
     *
     * @param int $itemId Identificador del ítem
     * @param string $photoPath Ruta relativa o URL segura de la imagen almacenada
     * @return bool True si la vinculación fue exitosa
     */
    public function attachPhoto(int $itemId, string $photoPath): bool;

    /**
     * Vincula una evidencia fotográfica a un ítem identificado por código dentro de la orden.
     *
     * @param int $orderId Identificador de la orden preventiva
     * @param string $itemCode Código normativo del ítem (ej. 'EVAPORATOR_FROST')
     * @param string $photoPath Ruta relativa o URL segura de la imagen almacenada
     * @return bool True si se actualizó el registro
     */
    public function attachPhotoByItemCode(int $orderId, string $itemCode, string $photoPath): bool;

    /**
     * Obtiene los ítems del checklist que presentan un fallo crítico (is_critical = 1 AND status = 'FAIL').
     *
     * @param int $orderId Identificador de la orden preventiva
     * @return array<int, PreventiveOrderItem> Colección de fallos críticos detectados
     */
    public function findCriticalFailures(int $orderId): array;

    /**
     * Comprueba si la orden preventiva tiene al menos un fallo crítico no conforme.
     *
     * @param int $orderId Identificador de la orden preventiva
     * @return bool True si existe fallo crítico
     */
    public function hasCriticalFailures(int $orderId): bool;
}
