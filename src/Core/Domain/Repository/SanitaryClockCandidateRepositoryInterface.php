<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Incident;

/**
 * SanitaryClockCandidateRepositoryInterface
 *
 * Puerto de dominio para la SELECCIÓN de expedientes candidatos al barrido de fondo
 * del Reloj Sanitario Biológico (T-PAUSE-38, RF-03.5.1, Art. II): el proceso que
 * activa la cuarentena sanitaria **sin** que nadie intente cerrar la avería.
 *
 * Existe como contrato propio —y no como un método más de `IncidentRepositoryInterface`—
 * por el mismo principio de segregación de interfaces que ya rigen
 * `IncidentRowLockRepositoryInterface` y `UserLockoutRepositoryInterface`: el barrido
 * necesita una única capacidad —una lista acotada de expedientes vivos, con más de
 * cuatro horas naturales, sobre máquinas bajo vigilancia sanitaria y todavía sin
 * cuarentena— y no tiene por qué forzar a los dobles del repositorio general (triaje,
 * hilos, historial, métricas) a implementar ni a simular una consulta que no usan.
 *
 * Este puerto **no decide**: publica los candidatos que un prefiltro de capacidad
 * considera plausibles para que el reloj
 * —`IncidentPauseService::evaluateSanitaryBiologicalClock()`, única autoridad de la
 * regla— decida sobre cada uno. Un candidato devuelto aquí puede perfectamente no
 * entrar en cuarentena —umbral no alcanzado en la relectura, expediente liquidado en
 * carrera con otro consumidor— y eso no es un fallo del puerto ni del barrido.
 */
interface SanitaryClockCandidateRepositoryInterface
{
    /**
     * Devuelve los expedientes vivos cuya máquina está bajo vigilancia sanitaria y cuya
     * apertura supera el umbral indicado, excluyendo las máquinas que ya están en
     * cuarentena (sobre ellas el barrido no tiene nada que hacer).
     *
     * La implementación hidrata entidades `Incident` completas —no filas sueltas—,
     * porque el consumidor las entrega tal cual al reloj.
     *
     * @param int $thresholdSeconds Umbral del reloj en segundos naturales (14.400 s = 4 h).
     * @param int $limit Cota superior del lote, para acotar el coste de una pasada.
     * @return list<Incident> Candidatos ordenados por antigüedad de apertura.
     *
     * @throws \InvalidArgumentException Si el umbral o la cota no son valores positivos.
     */
    public function findSanitaryClockCandidates(int $thresholdSeconds, int $limit = 500): array;
}
