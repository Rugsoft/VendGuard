<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Incident;

/**
 * SanitarySlaWatchRepositoryInterface
 *
 * Capacidad de lectura que el vigilante de inminencia de SLA necesita (RF-04.1,
 * RF-04.2): los expedientes **activos** cuyo parque está bajo vigilancia
 * sanitaria (`PERISHABLE_FOOD` y `COMBO`, Art. II).
 *
 * Se declara como contrato de capacidad —patrón ya usado por
 * `LocationContactEmailRepositoryInterface` y `OnCallTechnicianRepositoryInterface`—
 * para no engordar la firma publicada de `IncidentRepositoryInterface`, cuyos
 * dobles de prueba no tienen por qué conocer el reloj sanitario.
 *
 * La condición de umbral (2 horas en tiempo natural continuo 24/7) se resuelve
 * aquí para no arrastrar al vigilante expedientes que aún no pueden disparar
 * ninguna alerta; el juicio fino de cada umbral (2h sin asignar y 3h sin inicio)
 * vive en `NotificationDispatcherService`.
 */
interface SanitarySlaWatchRepositoryInterface
{
    /**
     * Expedientes activos bajo vigilancia sanitaria que ya han alcanzado el
     * umbral mínimo de 2 horas naturales desde el inicio del ciclo vigente.
     *
     * Se incluyen tanto los expedientes sin técnico asignado (candidatos a la
     * alerta de 2h) como los asignados sin intervención iniciada (candidatos a
     * la alerta de 3h). La medida es la del reloj biológico 24/7: un periodo en
     * `PENDING_INFO` no detiene el cómputo (RF-04.3).
     *
     * @param \DateTimeImmutable|null $now Referencia temporal inyectable para
     *   que la batería sea determinista; `null` usa el reloj del sistema.
     * @return list<Incident>
     */
    public function findActiveSanitaryWatchIncidentsForSlaWatch(?\DateTimeImmutable $now = null): array;
}
