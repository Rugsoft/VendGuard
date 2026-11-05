<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\MachineTroubleshootingGuide;

/**
 * MachineTroubleshootingGuideRepositoryInterface — contrato de persistencia del
 * catálogo de guías rápidas de diagnóstico (Módulo 28, Tabla 39).
 *
 * El repositorio **no decide el contenido**: recibe guías ya validadas por
 * `TroubleshootingGuideValidator` y las escribe. Lo que sí garantiza es la forma
 * del dato y el orden de los hechos:
 *
 *   - La identidad `(machine_model_key, breakdown_type)` la impone la clave única
 *     `uq_troubleshooting_guides_model_category`: una segunda guía del mismo par
 *     —activa o inactiva— choca en el motor y el caso de uso la traduce a `409`
 *     (`C1`).
 *   - `update()` **nunca** escribe el modelo ni la categoría: son la identidad
 *     inmutable de la ficha y el disparador `trg_immutable_machine_troubleshooting_guides`
 *     los blindaría de todos modos (`C1`, Art. III.3).
 *   - Las lecturas del técnico devuelven sólo guías activas y en la jerarquía
 *     canónica que prioriza la seguridad alimentaria (`QA 3`): `TEMPERATURE_COLD`
 *     primero. La del catálogo de Coordinación incluye también las inactivas,
 *     porque la baja es lógica (Art. III.1).
 *   - `fetchFleetCoverageSummary()` calcula el universo del parque sobre
 *     `machines` activas y no borradas, agrupadas por su clave normalizada, de
 *     modo que `"Necta Korinto"` y `"NECTA  KORINTO"` cuentan como un solo modelo
 *     (`QA 1`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface MachineTroubleshootingGuideRepositoryInterface
{
    /**
     * Guía por identificador, esté activa o inactiva, o `null` si no existe.
     */
    public function findById(int $id): ?MachineTroubleshootingGuide;

    /**
     * Guía por la pareja identitaria normalizada, esté activa o inactiva.
     *
     * Es la consulta que usa el alta para responder `409` antes de chocar contra
     * la clave única, y la que permite reactivar una guía dada de baja (`C1`).
     */
    public function findByModelKeyAndCategory(string $modelKey, string $breakdownType): ?MachineTroubleshootingGuide;

    /**
     * Guías activas del modelo, en el orden canónico del conmutador (`QA 3`).
     *
     * @return list<MachineTroubleshootingGuide>
     */
    public function findActiveByModelKey(string $modelKey): array;

    /**
     * Catálogo activo completo, ordenado por modelo y jerarquía canónica.
     *
     * Es la lectura del paquete offline del técnico (`D3`): no filtra por modelo.
     *
     * @return list<MachineTroubleshootingGuide>
     */
    public function findAllActive(): array;

    /**
     * Catálogo del panel de Coordinación con sus filtros opcionales.
     *
     * @param string $status `ACTIVE`, `INACTIVE` o `ALL` (por defecto).
     *
     * @return list<MachineTroubleshootingGuide>
     */
    public function findFilteredForCoordinator(
        ?string $machineModel,
        ?string $breakdownType,
        string $status = 'ALL',
        ?string $search = null
    ): array;

    /**
     * Indicadores de cobertura del parque (`QA 1`).
     *
     * El universo de modelos se calcula siempre sobre el parque global, sin
     * aceptar filtros: las tres tarjetas de Coordinación no pueden caer a cero
     * porque la tabla inferior esté filtrada.
     *
     * @return array{
     *     total_active_guides: int,
     *     total_guides: int,
     *     covered_fleet_models_count: int,
     *     total_active_fleet_models_count: int,
     *     orphan_fleet_models_count: int,
     *     orphan_fleet_models: list<array{machine_model: string, machine_model_key: string, active_machines_count: int}>,
     *     fleet_models: list<array{machine_model: string, machine_model_key: string, active_machines_count: int, active_guides_count: int, is_covered: bool}>
     * }
     */
    public function fetchFleetCoverageSummary(): array;

    /**
     * Alta de una guía y devolución de la fila persistida (con `id` y sellos).
     */
    public function create(MachineTroubleshootingGuide $guide): MachineTroubleshootingGuide;

    /**
     * Edición de los campos mutables y devolución de la fila persistida.
     *
     * Escribe título, documentos, causa sugerida, vigencia y actor de la última
     * edición. El modelo y la categoría no viajan en el `SET` (`C1`).
     *
     * @throws \RuntimeException Si la guía ya no existe.
     */
    public function update(MachineTroubleshootingGuide $guide): MachineTroubleshootingGuide;
}
