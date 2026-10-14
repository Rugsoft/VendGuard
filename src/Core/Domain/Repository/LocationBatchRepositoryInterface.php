<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Location;

/**
 * LocationBatchRepositoryInterface
 *
 * Puerto de dominio para leer un **conjunto** de sedes en una sola sentencia: recibe un lote de
 * identificadores y devuelve el mapa `id => Location` de las que existen.
 *
 * Existe como contrato propio —y no como un método más de `LocationRepositoryInterface`— por el
 * principio de segregación de interfaces ya declarado en el proyecto: la mayoría de consumidores
 * del catálogo de sedes (administración, mapa territorial, portal de sede, QR) resuelve una sede
 * por vez y no debe verse forzada a implementar ni a simular una capacidad que no usa. Su único
 * consumidor es la bandeja de ruta del técnico (`GET /api/technician/my-route`), que necesita la
 * sede de cada parada —nombre, dirección, teléfono de contacto y coordenadas para el botón de
 * navegación— y contra el motor remoto pagaba una ida y vuelta por parada (patrón N+1,
 * Módulo 12 · T-HYBRID-27).
 *
 * La lectura es pura y de solo consulta (Art. III.1): no escribe ni permite recuperar sedes dadas
 * de baja lógica, que es la garantía que ya presta `findById()`.
 */
interface LocationBatchRepositoryInterface
{
    /**
     * Sedes de un lote de identificadores, en una sola consulta.
     *
     * Semánticamente es la forma por lotes de `findById($id)`: mismas columnas y mismo descarte de
     * bajas lógicas. Un identificador sin fila simplemente no aparece en el mapa; quien consume
     * decide qué hacer con su ausencia, igual que con el `null` de `findById()`.
     *
     * @param array<int, int|string> $locationIds Identificadores pedidos; se deduplican y se
     *        descartan los que no son enteros positivos.
     * @return array<int, Location> Mapa `id de sede => Location`, sólo con las encontradas.
     */
    public function findManyByIds(array $locationIds): array;
}
