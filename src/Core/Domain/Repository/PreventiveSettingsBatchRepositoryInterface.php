<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * PreventiveSettingsBatchRepositoryInterface
 *
 * Puerto de dominio para leer los **ajustes preventivos efectivos de un conjunto de máquinas** en
 * una sola sentencia: recibe un lote de identificadores de máquina y devuelve el mapa
 * `machine_id => ajustes` de las que existen, con la misma estructura que publica
 * `getMachineSettings()` para una sola máquina.
 *
 * Existe como contrato propio —y no como un método más de `PreventiveSettingsRepositoryInterface`—
 * por el principio de segregación de interfaces ya declarado en el proyecto: casi todos los
 * consumidores de los ajustes (servicio de preventivos, planificador de órdenes, certificados,
 * reinspecciones) trabaja sobre una máquina y no debe verse forzado a implementar ni a simular una
 * capacidad que no usa. Su único consumidor es la bandeja de ruta del técnico
 * (`GET /api/technician/my-route`), cuya puerta sanitaria anticipa, parada a parada, si el cierre
 * exigirá las declaraciones del Art. II: resolverlo leyendo los ajustes de una en una costaba una
 * ida y vuelta por parada contra el motor remoto (patrón N+1, Módulo 12 · T-HYBRID-27).
 *
 * La lectura es pura y de solo consulta (Art. III): la puerta sanitaria de la ruta no escribe ni
 * provoca la cuarentena —eso pertenece al reloj biológico del consumidor del cierre—, y este
 * contrato conserva esa misma promesa.
 */
interface PreventiveSettingsBatchRepositoryInterface
{
    /**
     * Ajustes preventivos efectivos de un lote de máquinas, en una sola consulta.
     *
     * Cada entrada es la misma estructura asociativa que entrega `getMachineSettings()`: valores
     * propios de la máquina combinados con los de su tipología, más el estado higiénico-sanitario.
     * Una máquina sin fila —o dada de baja lógica— no aparece en el mapa; quien consume decide qué
     * hacer con su ausencia, igual que con el `null` de `getMachineSettings()`.
     *
     * @param array<int, int|string> $machineIds Identificadores pedidos; se deduplican y se
     *        descartan los que no son enteros positivos.
     * @return array<int, array<string, mixed>> Mapa `id de máquina => ajustes`, sólo con las
     *         máquinas encontradas.
     */
    public function getMachineSettingsForMany(array $machineIds): array;
}
