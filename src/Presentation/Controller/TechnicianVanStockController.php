<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\Service\VanStockService;
use VendGuard\Infrastructure\Repository\PdoVanStockRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * TechnicianVanStockController
 *
 * Inventario de repuestos de la furgoneta del técnico autenticado (Módulo 18, RF-04.1).
 *
 * El endpoint **no acepta** ningún identificador de técnico en la petición: la furgoneta que se
 * devuelve es siempre la del sujeto autenticado, y cualquier `technician_id` que llegue por query
 * se ignora en silencio. No es laxitud, es la garantía de RF-04.3: un técnico no puede leer el
 * inventario de otro ni equivocándose de parámetro.
 *
 * La respuesta usa la proyección `toTechnicianArray()` del DTO, que no lleva identidad de técnico
 * ni coste alguno (Art. V.4): el técnico ve su inventario, no el de la flota ni el valor del parque.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
class TechnicianVanStockController
{
    private VanStockService $vanStockService;

    public function __construct(?VanStockService $vanStockService = null)
    {
        $this->vanStockService = $vanStockService ?? new VanStockService(new PdoVanStockRepository());
    }

    /**
     * GET /api/technician/van-stock
     *
     * Contrato §4.1. Estado vacío = `items: []` con `200`: un técnico sin stock declarado no tiene
     * un error, tiene una furgoneta que nadie ha cargado todavía.
     */
    public function getInventory(Request $request): Response
    {
        $technicianId = $request->getAttribute('user_id');
        if ($technicianId === null || !is_numeric($technicianId)) {
            return Response::error(
                'UNAUTHORIZED',
                'No se pudo identificar al técnico autenticado.',
                401
            );
        }

        $ownerId = (int)$technicianId;

        return Response::json([
            'technician_id' => $ownerId,
            'items' => array_map(
                static fn ($entry): array => $entry->toTechnicianArray(),
                $this->vanStockService->listByTechnician($ownerId)
            ),
        ], 200);
    }
}
