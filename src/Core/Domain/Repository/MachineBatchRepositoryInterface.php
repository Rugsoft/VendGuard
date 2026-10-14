<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Machine;

/**
 * MachineBatchRepositoryInterface
 *
 * Puerto de dominio para leer un **conjunto** de máquinas en una sola sentencia: recibe un lote
 * de identificadores y devuelve el mapa `id => Machine` de las que existen.
 *
 * Existe como contrato propio —y no como un método más de `MachineRepositoryInterface`— por el
 * mismo principio de segregación de interfaces que ya rigen `IncidentSiteResponseRepositoryInterface`,
 * `IncidentRowLockRepositoryInterface` y `UserLockoutRepositoryInterface`: la inmensa mayoría de
 * consumidores del repositorio (ficha de máquina, parque de sede, preventivos, QR) resuelve
 * máquinas **de una en una** y no debe verse forzada a implementar ni a simular una capacidad que
 * no usa. Su único consumidor es la bandeja de ruta del técnico
 * (`GET /api/technician/my-route`), que pinta todas las paradas de una vez y contra el motor
 * remoto pagaba **una ida y vuelta por parada** (patrón N+1, Módulo 12 · T-HYBRID-27): con el
 * presupuesto de RNF-01 medido en unas cinco vueltas por petición, tres lecturas por parada se
 * llevaban por delante la bandeja entera.
 *
 * La lectura es pura y de solo consulta (Art. III): no escribe, no transiciona y no audita nada.
 */
interface MachineBatchRepositoryInterface
{
    /**
     * Máquinas de un lote de identificadores, en una sola consulta.
     *
     * Semánticamente es la forma por lotes de `findById($id)`: mismas columnas, mismo
     * enriquecimiento del aviso activo (o en garantía) y mismo descarte de bajas lógicas. Un
     * identificador sin fila —o dado de baja lógica— simplemente no aparece en el mapa; quien
     * consume decide qué hacer con su ausencia, igual que con el `null` de `findById()`.
     *
     * @param array<int, int|string> $machineIds Identificadores pedidos; se deduplican y se
     *        descartan los que no son enteros positivos.
     * @return array<int, Machine> Mapa `id de máquina => Machine`, sólo con las encontradas.
     */
    public function findManyByIds(array $machineIds): array;
}
