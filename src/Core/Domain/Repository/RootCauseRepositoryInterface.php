<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\RootCause;
use VendGuard\Core\Domain\Model\StandardSolution;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;

/**
 * RootCauseRepositoryInterface
 *
 * Contrato de persistencia del catálogo maestro de causas raíz y soluciones
 * estandarizadas (Módulo 25, RF-01, D1/D3).
 *
 * El puerto separa tres lecturas que no pueden confundirse:
 *   - **Catálogo operativo** (`findActiveCatalogWithSolutions`): sólo entradas
 *     activas, para el técnico y el paquete offline.
 *   - **Catálogo administrativo** (`findFullCatalogForAdmin`): activas e
 *     inactivas, con el contador de usos históricos que exige la pantalla de
 *     Coordinación.
 *   - **Lectura con bloqueo** (`findRootCauseForUpdate`,
 *     `findSolutionForUpdate`): dentro de la transacción que cambia el catálogo,
 *     con `SELECT ... FOR UPDATE`, para que dos coordinadores no conmuten la
 *     solución predeterminada a la vez (QA 1).
 *
 * Las operaciones de escritura reciben entidades ya validadas por el dominio y
 * devuelven la entidad con el identificador y las marcas que asignó el motor.
 */
interface RootCauseRepositoryInterface
{
    /**
     * Catálogo vigente para técnicos y bootstrap offline: causas activas con sus
     * soluciones activas, ordenadas por `is_default DESC, code ASC`.
     *
     * @return RootCause[]
     */
    public function findActiveCatalogWithSolutions(): array;

    /**
     * Catálogo completo para Coordinación, con el contador de averías históricas
     * por causa y por solución.
     *
     * @return array<int, array{
     *     root_cause: RootCause,
     *     incidents_count: int,
     *     solutions: array<int, array{solution: StandardSolution, incidents_count: int}>
     * }>
     */
    public function findFullCatalogForAdmin(?IncidentCategory $category = null, ?bool $isActive = null): array;

    public function findRootCauseById(int $id): ?RootCause;

    public function findSolutionById(int $id): ?StandardSolution;

    /**
     * Lectura de la causa dentro de la transacción de escritura, con bloqueo
     * pesimista (`SELECT ... FOR UPDATE`).
     */
    public function findRootCauseForUpdate(int $id): ?RootCause;

    /**
     * Lectura de la solución dentro de la transacción de escritura, con bloqueo
     * pesimista (`SELECT ... FOR UPDATE`).
     */
    public function findSolutionForUpdate(int $id): ?StandardSolution;

    public function existsRootCauseCode(string $code): bool;

    public function existsSolutionCode(string $code): bool;

    /**
     * ¿La causa tiene una solución activa marcada como predeterminada? Es la
     * guarda que impide reactivar una causa huérfana (EARS 01.3.3, QA 1).
     */
    public function hasActiveDefaultSolution(int $rootCauseId): bool;

    /**
     * Soluciones activas de la causa: la guarda que impide desactivar la última
     * que queda (C2, QA 1).
     */
    public function countActiveSolutions(int $rootCauseId): int;

    /**
     * Desmarca la solución predeterminada vigente de la causa para poder marcar
     * otra en la misma transacción, respetando el índice único parcial
     * `uq_standard_solutions_default_per_cause`.
     */
    public function clearActiveDefaultForCause(int $rootCauseId): void;

    /**
     * Inserta la causa y devuelve la entidad con el id asignado por el motor.
     */
    public function insertRootCause(RootCause $rootCause): RootCause;

    /**
     * Inserta una solución vinculada y devuelve la entidad con el id asignado.
     */
    public function insertStandardSolution(StandardSolution $standardSolution): StandardSolution;

    /**
     * Persiste los campos mutables de la causa (nombre, descripción y vigencia).
     * `code` y `category` son inmutables por Constitución (C4, QA 1).
     */
    public function updateRootCause(RootCause $rootCause): RootCause;

    /**
     * Persiste los campos mutables de la solución (nombre, predeterminada y
     * vigencia). `code` y `root_cause_id` son inmutables (C4, QA 1).
     */
    public function updateStandardSolution(StandardSolution $standardSolution): StandardSolution;
}
