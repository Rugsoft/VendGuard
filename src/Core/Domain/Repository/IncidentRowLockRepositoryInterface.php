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
 * ni a simular una capacidad de bloqueo que no usa. Sólo la necesitan las reanudaciones
 * manuales del módulo 11 —la del técnico in situ (RF-02.2) y la del coordinador desde
 * central—, transiciones de estado sobre un expediente pausado donde el caso límite §6.2
 * del análisis funcional exige resolver la carrera simultánea «atómicamente, sin estados
 * inconsistentes ni errores bloqueantes», y el cierre con relevo del módulo 21, que
 * valida la guardia de `IN_PROGRESS`, la foto de la ruta y la transferencia sobre las
 * mismas filas bloqueadas (RF-05.3 / R9).
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

    /**
     * Recupera y bloquea todas las averías activas asignadas a un técnico.
     *
     * El bloqueo se toma con `SELECT ... FOR UPDATE OF i` y se mantiene hasta
     * que la transacción en curso confirme o deshaga. El módulo 21 lo usa para
     * que la guardia de `IN_PROGRESS`, la foto de la ruta y el relevo se
     * resuelvan sobre las mismas filas: una reasignación concurrente no puede
     * colarse entre la validación y la escritura (RF-05.3 / R9).
     *
     * Debe invocarse SIEMPRE dentro de una transacción abierta; la implementación
     * lo rechaza fuera de ella en lugar de fingir un bloqueo que ya no existiría.
     *
     * @param int $technicianId Identificador del técnico saliente.
     * @return list<Incident> Averías asignadas al técnico, en orden de bloqueo estable.
     */
    public function findAssignedToTechnicianForUpdate(int $technicianId): array;
}
