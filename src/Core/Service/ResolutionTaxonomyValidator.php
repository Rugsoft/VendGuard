<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Exception\InvalidResolutionException;
use VendGuard\Core\Domain\Model\RootCause;
use VendGuard\Core\Domain\Model\StandardSolution;

/**
 * ResolutionTaxonomyValidator
 *
 * Validador puro de la clasificación de una avería en Causa Raíz y Solución
 * Estandarizada (Módulo 25, RF-02, D1, D2, QA 3).
 *
 * Reglas que muerde:
 *   1. **Presencia conjunta.** O llegan los dos identificadores o no llega
 *      ninguno. La excepción histórica (`requireTaxonomy = false` y ausencia de
 *      ambos) conserva la compatibilidad de las suites anteriores al Módulo 25;
 *      en cuanto se envía uno, el otro pasa a ser obligatorio.
 *   2. **Existencia y pertenencia jerárquica.** Ambas entidades deben haberse
 *      resuelto y `solution.rootCauseId` tiene que ser exactamente el id de la
 *      causa (`SOLUTION_DOES_NOT_BELONG_TO_ROOT_CAUSE`).
 *   3. **Vigencia según canal.** En cierre online la causa y la solución deben
 *      estar activas. En una sincronización diferida desde la cola offline se
 *      tolera que el coordinador las haya desactivado mientras el técnico
 *      trabajaba sin cobertura (`EARS 03.3`), porque el trabajo ya estaba hecho.
 *   4. **Independencia del Art. V.1.** Este validador **jamás** concatena el
 *      nombre de la causa ni de la solución a los textos de resolución, ni
 *      exime a `ResolutionValidator` del mínimo de 20 caracteres reales: la
 *      clasificación no maquilla el diagnóstico ni la acción correctiva.
 */
final class ResolutionTaxonomyValidator
{
    public const MISSING_ROOT_CAUSE_OR_SOLUTION = 'MISSING_ROOT_CAUSE_OR_SOLUTION';

    public const ROOT_CAUSE_OR_SOLUTION_NOT_FOUND = 'ROOT_CAUSE_OR_SOLUTION_NOT_FOUND';

    public const SOLUTION_DOES_NOT_BELONG_TO_ROOT_CAUSE = 'SOLUTION_DOES_NOT_BELONG_TO_ROOT_CAUSE';

    public const INACTIVE_ROOT_CAUSE_OR_SOLUTION = 'INACTIVE_ROOT_CAUSE_OR_SOLUTION';

    /**
     * Valida la clasificación recibida para el cierre de una avería.
     *
     * @param int|null             $rootCauseId          Identificador enviado en el cuerpo, si lo hay.
     * @param int|null             $standardSolutionId   Identificador enviado en el cuerpo, si lo hay.
     * @param RootCause|null       $rootCause            Entidad resuelta por el repositorio, o null si no existe.
     * @param StandardSolution|null $standardSolution    Entidad resuelta por el repositorio, o null si no existe.
     * @param bool                 $isOfflineDeferredSync `true` en sincronización diferida de la cola offline (D2, EARS 03.3).
     * @param bool                 $requireTaxonomy      `true` cuando el canal exige clasificar aunque no lleguen los identificadores.
     *
     * @return bool `true` si procede congelar los snapshots; `false` sólo en el cierre histórico sin taxonomía.
     *
     * @throws InvalidResolutionException Si la clasificación falta, no existe, no pertenece o está inactiva.
     */
    public static function validate(
        ?int $rootCauseId,
        ?int $standardSolutionId,
        ?RootCause $rootCause,
        ?StandardSolution $standardSolution,
        bool $isOfflineDeferredSync = false,
        bool $requireTaxonomy = false
    ): bool {
        $hasRootCauseId = $rootCauseId !== null && $rootCauseId > 0;
        $hasSolutionId = $standardSolutionId !== null && $standardSolutionId > 0;

        // Compatibilidad regresiva: el cierre histórico que no envía taxonomía y
        // cuyo canal no la exige sigue siendo válido. Las siete columnas quedan
        // en NULL y el expediente se declara «sin clasificar» en el Pareto (C3).
        if (!$hasRootCauseId && !$hasSolutionId && !$requireTaxonomy) {
            return false;
        }

        if (!$hasRootCauseId || !$hasSolutionId) {
            throw new InvalidResolutionException(
                'Debe seleccionar una causa raíz y una solución estandarizada para resolver la avería.',
                [self::MISSING_ROOT_CAUSE_OR_SOLUTION],
                !$hasRootCauseId ? 'root_cause_id' : 'standard_solution_id'
            );
        }

        if ($rootCause === null || $standardSolution === null) {
            throw new InvalidResolutionException(
                'La causa raíz o la solución estandarizada indicada no existe en el catálogo maestro.',
                [self::ROOT_CAUSE_OR_SOLUTION_NOT_FOUND]
            );
        }

        if ($rootCause->getId() === null || $standardSolution->getId() === null) {
            throw new InvalidResolutionException(
                'La causa raíz o la solución estandarizada indicada no está persistida en el catálogo maestro.',
                [self::ROOT_CAUSE_OR_SOLUTION_NOT_FOUND]
            );
        }

        if ($standardSolution->getRootCauseId() !== $rootCause->getId()) {
            throw new InvalidResolutionException(
                sprintf(
                    'La solución «%s» no pertenece a la causa raíz «%s».',
                    $standardSolution->getCode(),
                    $rootCause->getCode()
                ),
                [self::SOLUTION_DOES_NOT_BELONG_TO_ROOT_CAUSE]
            );
        }

        // En la sincronización diferida offline la baja sobrevenida del catálogo
        // no invalida un trabajo ya ejecutado (EARS 03.3); en online, sí.
        if (!$isOfflineDeferredSync && (!$rootCause->isActive() || !$standardSolution->isActive())) {
            throw new InvalidResolutionException(
                'La causa raíz o la solución estandarizada seleccionada está desactivada; seleccione una entrada vigente del catálogo.',
                [self::INACTIVE_ROOT_CAUSE_OR_SOLUTION]
            );
        }

        return true;
    }

    /**
     * Valida el cierre completo: primero los textos que exige el Art. V.1 sobre
     * las cadenas reales del técnico, después la clasificación.
     *
     * El orden no es accidental: una taxonomía impecable no puede rescatar un
     * diagnóstico de diez caracteres, y el validador no añade ni un carácter a
     * `resolution_diagnosis` ni a `resolution_action` (D2, QA 3).
     *
     * @throws InvalidResolutionException Si los textos o la clasificación no cumplen.
     */
    public static function validateResolution(
        string $diagnosis,
        string $action,
        ?int $rootCauseId,
        ?int $standardSolutionId,
        ?RootCause $rootCause,
        ?StandardSolution $standardSolution,
        bool $isOfflineDeferredSync = false,
        bool $requireTaxonomy = false
    ): bool {
        ResolutionValidator::validate($diagnosis, $action);

        return self::validate(
            $rootCauseId,
            $standardSolutionId,
            $rootCause,
            $standardSolution,
            $isOfflineDeferredSync,
            $requireTaxonomy
        );
    }
}
