<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Incident;

/**
 * IncidentRowLockRepositoryInterface
 *
 * Puerto de dominio para la lectura SERIALIZADA del agregado `Incident`: recupera el
 * expediente tomando un bloqueo exclusivo por fila (`SELECT ... FOR UPDATE`) dentro de
 * la transacción de quien lo invoca.
 *
 * Existe como contrato propio —y no como un método más de `IncidentRepositoryInterface`—
 * por el mismo principio de segregación de interfaces que ya rige
 * `UserLockoutRepositoryInterface`: la inmensa mayoría de consumidores del repositorio
 * (triaje, hilos, historial, métricas) sólo lee y no debe verse forzada a implementar
 * ni a simular una capacidad de bloqueo que no usa. Sólo dos casos de uso del módulo 11
 * la necesitan, y los dos son transiciones de estado sobre un expediente pausado:
 * la reanudación manual (RF-02.2) y la reactivación por comentario de sede (RF-02.1),
 * donde el caso límite §6.2 del análisis funcional exige resolver la carrera simultánea
 * «atómicamente, sin estados inconsistentes ni errores bloqueantes».
 *
 * El bloqueo por fila es la única forma de que la segunda operación lea el expediente
 * DESPUÉS de que la primera haya confirmado: sin él, ambas leen el mismo `PENDING_INFO`,
 * ambas creen tener una pausa que cerrar y la última en escribir pisa a la otra —
 * duplicando el rastro inmutable y desplazando el vencimiento contractual dos veces.
 */
interface IncidentRowLockRepositoryInterface
{
    /**
     * Recupera una incidencia activa por su identificador tomando un bloqueo exclusivo
     * por fila que se mantiene hasta que la transacción en curso confirme o deshaga.
     *
     * Debe invocarse SIEMPRE dentro de una transacción abierta: fuera de ella el bloqueo
     * se libera al terminar la sentencia y la serialización que promete este puerto sería
     * ficticia (la implementación lo rechaza en vez de fingirlo).
     *
     * @param int $id Identificador primario de la incidencia.
     * @return Incident|null Entidad hidratada, o `null` si no existe o está borrada.
     */
    public function findByIdForUpdate(int $id): ?Incident;
}
