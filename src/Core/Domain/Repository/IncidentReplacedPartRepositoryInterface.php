<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\IncidentReplacedPart;

/**
 * IncidentReplacedPartRepositoryInterface
 * 
 * Contrato de persistencia para el registro de piezas y componentes físicos
 * sustituidos durante intervenciones correctivas y preventivas (RF-REP-06, RF-REP-07, RF-REP-08).
 * Garantiza inmutabilidad del snapshot de costes y consultas para analítica y alertas crónicas.
 */
interface IncidentReplacedPartRepositoryInterface
{
    /**
     * Inserta una pieza sustituida registrando el snapshot congelado de coste (Art. III).
     *
     * @param IncidentReplacedPart $part
     * @return IncidentReplacedPart
     */
    public function insertReplacedPart(IncidentReplacedPart $part): IncidentReplacedPart;

    /**
     * Inserta en lote un conjunto de piezas sustituidas dentro de una misma transacción.
     *
     * @param IncidentReplacedPart[] $parts
     * @return IncidentReplacedPart[]
     */
    public function insertManyReplacedParts(array $parts): array;

    /**
     * Busca un consumo de repuesto por su identificador primario único.
     *
     * @param int $id
     * @return IncidentReplacedPart|null
     */
    public function findById(int $id): ?IncidentReplacedPart;

    /**
     * Obtiene el listado de componentes sustituidos asociados a una incidencia correctiva.
     *
     * @param int $incidentId
     * @return IncidentReplacedPart[]
     */
    public function findByIncidentId(int $incidentId): array;

    /**
     * Obtiene el listado de componentes sustituidos asociados a una orden preventiva.
     *
     * @param int $preventiveOrderId
     * @return IncidentReplacedPart[]
     */
    public function findByPreventiveOrderId(int $preventiveOrderId): array;

    /**
     * Obtiene el resumen de costes acumulados y unidades agrupados por modelo de máquina.
     *
     * @param int|null $periodDays Ventana temporal en días naturales (null para todo el histórico)
     * @return array<int, array<string, mixed>>
     */
    public function getCostSummaryByMachineModel(?int $periodDays = null): array;

    /**
     * Obtiene el resumen de costes acumulados y unidades agrupados por sede/ubicación.
     *
     * @param int|null $periodDays Ventana temporal en días naturales (null para todo el histórico)
     * @return array<int, array<string, mixed>>
     */
    public function getCostSummaryByLocation(?int $periodDays = null): array;

    /**
     * Obtiene el ranking de piezas más sustituidas en el período con desglose de destinos.
     *
     * @param int|null $periodDays
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getTopReplacedParts(?int $periodDays = null, int $limit = 10): array;

    /**
     * Detecta alertas de fallo crónico (> threshold sustituciones de la misma pieza en una máquina en windowDays días).
     *
     * @param int $windowDays Ventana temporal (defecto 90 días)
     * @param int $threshold Umbral mínimo de sustituciones (defecto 3)
     * @return array<int, array<string, mixed>>
     */
    public function findChronicFailureAlerts(int $windowDays = 90, int $threshold = 3): array;

    /**
     * Obtiene los registros planos consolidados para exportación a formato CSV (RF-REP-09).
     *
     * @param int|null $periodDays
     * @return array<int, array<string, mixed>>
     */
    public function findAllForExport(?int $periodDays = null): array;
}
