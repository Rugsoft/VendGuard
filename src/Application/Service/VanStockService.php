<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Application\DTO\VanStockAdjustmentResultDto;
use VendGuard\Application\DTO\VanStockEntryDto;
use VendGuard\Core\Domain\Exception\VanStockValidationException;
use VendGuard\Core\Domain\Model\VanStockItem;
use VendGuard\Core\Domain\Repository\VanStockRepositoryInterface;

/**
 * VanStockService
 *
 * Casos de uso del stock de repuestos de la furgoneta (Módulo 18): ajuste absoluto del
 * coordinador, consumo por pieza declarada y las dos consultas de inventario.
 *
 * El servicio **no escribe SQL y no abre transacciones**: valida el destino y las cotas, y deja la
 * atomicidad donde vive el dato. Esa separación no es decorativa:
 *
 *  - **El consumo no puede fallar por inventario (RD-04, Art. V.1).** `consume()` delega una
 *    única sentencia atómica. Si la furgoneta no tenía la fila, se crea en negativo —un descuadre
 *    declarado— y la resolución de la avería sigue adelante. Aquí no hay ninguna comprobación que
 *    pueda lanzar una excepción por falta de existencias.
 *  - **El ajuste del coordinador sí es un caso de uso con reglas.** El destino tiene que existir,
 *    tiene que ser un técnico y las cifras tienen que caber en las cotas anti-errata; si no, el
 *    coordinador recibe el código del contrato y corrige antes de escribir nada.
 *  - **El orden por severidad es de la consulta, no de PHP (RF-03.3).** El listado de alerta llega
 *    ya ordenado desde el puerto; reordenarlo aquí sería una segunda definición de «más grave».
 *
 * El descuento automático **no genera evento de auditoría**: el consumo queda trazado por la línea
 * de pieza sustituida de la avería (`incident_replaced_parts`), que es el libro inmutable, y
 * duplicarlo en `audit_log` haría que el rastro contase dos veces el mismo hecho (RF-05.2).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class VanStockService
{
    public function __construct(private readonly VanStockRepositoryInterface $stockRepository)
    {
    }

    /**
     * Alta o ajuste absoluto del saldo de una furgoneta (RF-01.1, RF-05.1).
     *
     * La cantidad es **absoluta, no un delta**: el coordinador declara cuántas unidades hay. El
     * delta se deriva del saldo previo y viaja al evento de auditoría y a la respuesta.
     *
     * @param int $technicianId Técnico destino; debe existir y ejercer de técnico.
     * @param int $sparePartId Pieza del catálogo maestro; debe existir.
     * @param int $quantityAvailable Unidades declaradas (admite negativos: descuadre, RD-04).
     * @param int $minThreshold Umbral mínimo de reposición.
     * @param int|null $actorUserId Coordinador que ejecuta el ajuste; `null` solo en procesos internos.
     *
     * @throws VanStockValidationException Si el payload, las cotas o el destino no cumplen el contrato.
     */
    public function setStock(
        int $technicianId,
        int $sparePartId,
        int $quantityAvailable,
        int $minThreshold,
        ?int $actorUserId = null
    ): VanStockAdjustmentResultDto {
        // Fail-fast sobre lo que no necesita consultar la base: la errata de teclado se responde
        // sin tocar el motor.
        if (
            $quantityAvailable < VanStockItem::QUANTITY_MIN
            || $quantityAvailable > VanStockItem::QUANTITY_MAX
            || $minThreshold < VanStockItem::THRESHOLD_MIN
            || $minThreshold > VanStockItem::THRESHOLD_MAX
        ) {
            throw VanStockValidationException::invalidQuantity($quantityAvailable, $minThreshold);
        }

        $technician = $this->stockRepository->findTechnician($technicianId);
        if ($technician === null) {
            throw VanStockValidationException::targetNotFound(
                'No se encontró el técnico indicado.',
                ['technician_id' => sprintf('No existe el técnico %d o está dado de baja.', $technicianId)]
            );
        }

        if (strtoupper($technician['role']) !== 'TECHNICIAN') {
            throw VanStockValidationException::roleNotTechnician($technicianId);
        }

        if (!$this->stockRepository->sparePartExists($sparePartId)) {
            throw VanStockValidationException::targetNotFound(
                'No se encontró la pieza indicada.',
                ['spare_part_id' => sprintf('No existe la pieza %d en el catálogo maestro.', $sparePartId)]
            );
        }

        $previous = $this->stockRepository->findByPair($technicianId, $sparePartId);
        $saved = $this->stockRepository->adjust(
            new VanStockItem(
                $previous?->id,
                $technicianId,
                $sparePartId,
                $quantityAvailable,
                $minThreshold
            ),
            $previous?->quantityAvailable,
            $actorUserId
        );

        $previousQuantity = $previous?->quantityAvailable;

        return new VanStockAdjustmentResultDto(
            $saved,
            $previousQuantity,
            $saved->quantityAvailable - ($previousQuantity ?? 0)
        );
    }

    /**
     * Descuento por consumo declarado (RF-02.1, RD-02).
     *
     * Único punto de descuento del sistema: lo invocan los dos caminos que ya persisten líneas de
     * pieza sustituida (resolución de avería y preventivo). No lanza excepciones de negocio: el
     * inventario no puede impedir el cierre (RD-04).
     *
     * @param int $quantity Unidades instaladas; siempre positivo.
     *
     * @throws InvalidArgumentException Si la cantidad no es un entero positivo (error de programación).
     */
    public function consume(int $technicianId, int $sparePartId, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                sprintf('El consumo de stock debe descontar al menos una unidad (recibido: %d).', $quantity)
            );
        }

        $this->stockRepository->consume($technicianId, $sparePartId, $quantity);
    }

    /**
     * Parejas bajo umbral, ordenadas por severidad (RF-03.2).
     *
     * @return list<VanStockEntryDto>
     */
    public function listBelowThreshold(?int $technicianId = null): array
    {
        return $this->stockRepository->listBelowThreshold($technicianId);
    }

    /**
     * Inventario completo de una furgoneta (RF-04.1).
     *
     * @return list<VanStockEntryDto>
     */
    public function listByTechnician(int $technicianId): array
    {
        return $this->stockRepository->listByTechnician($technicianId);
    }

    /**
     * Inventario del parque, o de una furgoneta concreta (contrato §4.2).
     *
     * @return list<VanStockEntryDto>
     */
    public function listInventory(?int $technicianId = null): array
    {
        return $this->stockRepository->listInventory($technicianId);
    }
}
