<?php

declare(strict_types=1);

/**
 * ResolutionTaxonomyValidatorTest · T-TAXONOMY-04
 *
 * Suite unitaria del validador de clasificación de resolución del Módulo 25.
 * Certifica que la puerta de la taxonomía vive en el dominio y que muerde
 * exactamente donde la especificación manda:
 *
 *   1. **Presencia conjunta (`D1`, EARS 02.3):** un identificador sin el otro se
 *      rechaza; el cierre histórico que no envía ninguno y cuyo canal no exige
 *      taxonomía sigue siendo válido (`false`).
 *   2. **Existencia y pertenencia (`EARS 02.4`):** una causa o solución
 *      inexistente y una solución de otra rama del catálogo se rechazan.
 *   3. **Vigencia según canal (`D2`):** en cierre online una causa o solución
 *      desactivada se rechaza; en sincronización diferida offline se acepta
 *      porque el trabajo ya estaba hecho cuando el coordinador la dio de baja
 *      (`EARS 03.3`).
 *   4. **Congelación (`Art. III.3`, EARS 02.5):** la clasificación aceptada se
 *      congela en los siete campos del expediente y no se reescribe cuando el
 *      catálogo cambia después.
 *   5. **Independencia del Art. V.1:** una taxonomía impecable no rescata un
 *      diagnóstico o una acción de menos de 20 caracteres reales, ni añade un
 *      solo carácter a los textos del técnico.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Exception\InvalidResolutionException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\RootCause;
use VendGuard\Core\Domain\Model\StandardSolution;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Service\ResolutionTaxonomyValidator;

echo "======================================================================\n";
echo " VENDGUARD · Suite unitaria · ResolutionTaxonomyValidatorTest (T-TAXONOMY-04)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

/**
 * Ejecuta la operación y devuelve la excepción de resolución capturada, o null
 * si la operación se aceptó. Sólo captura la excepción del contrato: cualquier
 * otro fallo debe propagarse y romper la suite, no disfrazarse de rechazo.
 */
$rejectionOf = static function (callable $operation): ?InvalidResolutionException {
    try {
        $operation();
    } catch (InvalidResolutionException $exception) {
        return $exception;
    }

    return null;
};

/** @param string[] $codes */
$hasAnyCode = static function (?InvalidResolutionException $exception, array $codes): bool {
    if ($exception === null) {
        return false;
    }

    return count(array_intersect($codes, $exception->getErrors())) > 0;
};

// ─────────────────────────────────────────────────────────────────────
// Escenario: catálogo en memoria
// ─────────────────────────────────────────────────────────────────────
$payment = IncidentCategory::PAYMENT_SYSTEM;

$activeCause = new RootCause(
    1,
    'RC-PAY-01',
    $payment,
    'Moneda doblada o cuerpo extraño en selector multimoneda'
);
$activeSolution = new StandardSolution(
    10,
    1,
    'SOL-PAY-01A',
    'Apertura de canal, retirada de objeto y limpieza óptica'
);

$otherCause = new RootCause(
    2,
    'RC-PAY-02',
    $payment,
    'Tubos de cambio agotados o atasco en casete'
);
$foreignSolution = new StandardSolution(
    11,
    2,
    'SOL-PAY-02A',
    'Desatasco de casete, nivelación y prueba de expulsión'
);

$inactiveCause = new RootCause(
    3,
    'RC-COLD-04',
    IncidentCategory::TEMPERATURE_COLD,
    'Disparo de termostato de seguridad en grupo térmico',
    null,
    false
);
$solutionOfInactiveCause = new StandardSolution(
    12,
    3,
    'SOL-COLD-04A',
    'Rearme manual de termostato y purga térmica'
);

$inactiveSolution = new StandardSolution(
    13,
    1,
    'SOL-PAY-01B',
    'Sustitución de monedero selector MDB',
    false,
    false
);

// ─────────────────────────────────────────────────────────────────────
// 1. Presencia conjunta y compatibilidad histórica
// ─────────────────────────────────────────────────────────────────────
echo "--- 1. Presencia conjunta (D1, EARS 02.3) ---\n";

$missingSolution = $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validate(
    1,
    null,
    $activeCause,
    null
));
$assert(
    '1.1 Se rechaza root_cause_id sin standard_solution_id',
    $hasAnyCode($missingSolution, [ResolutionTaxonomyValidator::MISSING_ROOT_CAUSE_OR_SOLUTION]),
    $missingSolution === null ? 'la clasificación se aceptó' : $missingSolution->getMessage()
);

$missingCause = $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validate(
    null,
    10,
    null,
    $activeSolution
));
$assert(
    '1.2 Se rechaza standard_solution_id sin root_cause_id',
    $hasAnyCode($missingCause, [ResolutionTaxonomyValidator::MISSING_ROOT_CAUSE_OR_SOLUTION]),
    $missingCause === null ? 'la clasificación se aceptó' : $missingCause->getMessage()
);

$assert(
    '1.3 El cierre histórico sin taxonomía y sin exigencia explícita se acepta',
    ResolutionTaxonomyValidator::validate(null, null, null, null) === false
);

$requiredMissing = $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validate(
    null,
    null,
    null,
    null,
    false,
    true
));
$assert(
    '1.4 Un canal que exige taxonomía rechaza el cierre sin clasificar',
    $hasAnyCode($requiredMissing, [ResolutionTaxonomyValidator::MISSING_ROOT_CAUSE_OR_SOLUTION]),
    $requiredMissing === null ? 'el cierre sin taxonomía se aceptó' : $requiredMissing->getMessage()
);

$assert(
    '1.5 Una clasificación completa y vigente se acepta',
    ResolutionTaxonomyValidator::validate(1, 10, $activeCause, $activeSolution) === true
);

// ─────────────────────────────────────────────────────────────────────
// 2. Existencia y pertenencia jerárquica
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 2. Existencia y pertenencia (EARS 02.4) ---\n";

$causeNotFound = $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validate(
    999,
    10,
    null,
    $activeSolution
));
$assert(
    '2.1 Una causa raíz inexistente se rechaza como no encontrada',
    $hasAnyCode($causeNotFound, [ResolutionTaxonomyValidator::ROOT_CAUSE_OR_SOLUTION_NOT_FOUND]),
    $causeNotFound === null ? 'la clasificación se aceptó' : $causeNotFound->getMessage()
);

$solutionNotFound = $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validate(
    1,
    999,
    $activeCause,
    null
));
$assert(
    '2.2 Una solución inexistente se rechaza como no encontrada',
    $hasAnyCode($solutionNotFound, [ResolutionTaxonomyValidator::ROOT_CAUSE_OR_SOLUTION_NOT_FOUND]),
    $solutionNotFound === null ? 'la clasificación se aceptó' : $solutionNotFound->getMessage()
);

$foreign = $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validate(
    1,
    11,
    $activeCause,
    $foreignSolution
));
$assert(
    '2.3 Se rechaza una solución que pertenece a otra causa raíz',
    $hasAnyCode($foreign, [ResolutionTaxonomyValidator::SOLUTION_DOES_NOT_BELONG_TO_ROOT_CAUSE]),
    $foreign === null ? 'la clasificación se aceptó' : $foreign->getMessage()
);

// ─────────────────────────────────────────────────────────────────────
// 3. Vigencia online y tolerancia offline
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 3. Vigencia online vs. sincronización diferida ---\n";

$inactiveCauseOnline = $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validate(
    3,
    12,
    $inactiveCause,
    $solutionOfInactiveCause
));
$assert(
    '3.1 En online se rechaza una causa raíz desactivada',
    $hasAnyCode($inactiveCauseOnline, [ResolutionTaxonomyValidator::INACTIVE_ROOT_CAUSE_OR_SOLUTION]),
    $inactiveCauseOnline === null ? 'la clasificación se aceptó' : $inactiveCauseOnline->getMessage()
);

$inactiveSolutionOnline = $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validate(
    1,
    13,
    $activeCause,
    $inactiveSolution
));
$assert(
    '3.2 En online se rechaza una solución desactivada',
    $hasAnyCode($inactiveSolutionOnline, [ResolutionTaxonomyValidator::INACTIVE_ROOT_CAUSE_OR_SOLUTION]),
    $inactiveSolutionOnline === null ? 'la clasificación se aceptó' : $inactiveSolutionOnline->getMessage()
);

$assert(
    '3.3 En sincronización offline se tolera la causa desactivada en el ínterin (EARS 03.3)',
    ResolutionTaxonomyValidator::validate(3, 12, $inactiveCause, $solutionOfInactiveCause, true) === true
);

$assert(
    '3.4 En sincronización offline se tolera la solución desactivada en el ínterin (EARS 03.3)',
    ResolutionTaxonomyValidator::validate(1, 13, $activeCause, $inactiveSolution, true) === true
);

$assert(
    '3.5 La tolerancia offline no exime la coherencia jerárquica',
    $hasAnyCode(
        $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validate(
            1,
            11,
            $activeCause,
            $foreignSolution,
            true
        )),
        [ResolutionTaxonomyValidator::SOLUTION_DOES_NOT_BELONG_TO_ROOT_CAUSE]
    )
);

// ─────────────────────────────────────────────────────────────────────
// 4. Congelación de los siete campos del expediente
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 4. Congelación de snapshots (Art. III.3, EARS 02.5) ---\n";

$incident = Incident::fromDatabaseRow([
    'ticket_code' => 'INC-2026-0001',
    'machine_id' => 1,
    'location_id' => 1,
    'category' => 'PAYMENT_SYSTEM',
    'description' => 'Moneda retenida en el validador del pago.',
    'urgency' => 'MEDIUM',
    'status' => 'RESOLVED',
]);

$assert('4.1 Un expediente histórico no clasificado no declara taxonomía', $incident->hasResolutionTaxonomy() === false);

$classified = $incident->applyResolutionTaxonomySnapshot($activeCause, $activeSolution);

$assert(
    '4.2 La clasificación congela los dos identificadores y los cinco snapshots',
    $classified->getRootCauseId() === 1
    && $classified->getStandardSolutionId() === 10
    && $classified->getRootCauseCodeSnapshot() === 'RC-PAY-01'
    && $classified->getRootCauseCategorySnapshot() === $payment
    && $classified->getRootCauseNameSnapshot() === 'Moneda doblada o cuerpo extraño en selector multimoneda'
    && $classified->getStandardSolutionCodeSnapshot() === 'SOL-PAY-01A'
    && $classified->getStandardSolutionNameSnapshot() === 'Apertura de canal, retirada de objeto y limpieza óptica'
    && $classified->hasResolutionTaxonomy() === true
);

$renamedCause = new RootCause(
    1,
    'RC-PAY-01',
    $payment,
    'Moneda doblada, ficha o cuerpo extraño en selector multimoneda'
);
$renamedSolution = new StandardSolution(
    10,
    1,
    'SOL-PAY-01A',
    'Apertura de canal, retirada de objeto y calibración óptica'
);
$assert(
    '4.3 Un renombrado posterior del catálogo no reescribe el snapshot del expediente',
    $classified->getRootCauseNameSnapshot() === 'Moneda doblada o cuerpo extraño en selector multimoneda'
    && $classified->getStandardSolutionNameSnapshot() === 'Apertura de canal, retirada de objeto y limpieza óptica'
    && $renamedCause->getName() !== $classified->getRootCauseNameSnapshot()
    && $renamedSolution->getName() !== $classified->getStandardSolutionNameSnapshot()
);

$assert(
    '4.4 El expediente original no muta al congelar la clasificación',
    $incident->hasResolutionTaxonomy() === false && $incident->getRootCauseCodeSnapshot() === null
);

$foreignSnapshot = null;
try {
    $incident->applyResolutionTaxonomySnapshot($activeCause, $foreignSolution);
} catch (InvalidArgumentException $exception) {
    $foreignSnapshot = $exception;
}
$assert(
    '4.5 Congelar una solución de otra causa raíz se rechaza en el dominio',
    $foreignSnapshot !== null
);

$unsavedSnapshot = null;
try {
    $incident->applyResolutionTaxonomySnapshot(
        new RootCause(null, 'RC-PAY-09', $payment, 'Causa aún no persistida en el catálogo'),
        new StandardSolution(90, 1, 'SOL-PAY-09A', 'Solución de una causa sin identificador')
    );
} catch (InvalidArgumentException $exception) {
    $unsavedSnapshot = $exception;
}
$assert(
    '4.6 Congelar una causa sin identificador se rechaza en el dominio',
    $unsavedSnapshot !== null
);

// ─────────────────────────────────────────────────────────────────────
// 5. Independencia estricta del Art. V.1
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 5. Independencia del mínimo de 20 caracteres (Art. V.1, QA 3) ---\n";

$validDiagnosis = 'Moneda de 50 céntimos doblada bloqueando el canal óptico del validador.';
$validAction = 'Se desmonta el casete superior, se retira la moneda y se calibra el sensor.';

$shortDiagnosis = $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validateResolution(
    'Moneda doblada.',
    $validAction,
    1,
    10,
    $activeCause,
    $activeSolution
));
$assert(
    '5.1 Un diagnóstico corto se rechaza aunque la taxonomía sea impecable',
    $shortDiagnosis !== null
    && str_contains($shortDiagnosis->getMessage(), 'diagnóstico técnico')
    && !$hasAnyCode($shortDiagnosis, [
        ResolutionTaxonomyValidator::MISSING_ROOT_CAUSE_OR_SOLUTION,
        ResolutionTaxonomyValidator::ROOT_CAUSE_OR_SOLUTION_NOT_FOUND,
        ResolutionTaxonomyValidator::SOLUTION_DOES_NOT_BELONG_TO_ROOT_CAUSE,
        ResolutionTaxonomyValidator::INACTIVE_ROOT_CAUSE_OR_SOLUTION,
    ]),
    $shortDiagnosis === null ? 'la resolución se aceptó' : $shortDiagnosis->getMessage()
);

$shortAction = $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validateResolution(
    $validDiagnosis,
    'Se limpia el canal.',
    1,
    10,
    $activeCause,
    $activeSolution
));
$assert(
    '5.2 Una acción correctiva corta se rechaza aunque la taxonomía sea impecable',
    $shortAction !== null && str_contains($shortAction->getMessage(), 'acción correctiva'),
    $shortAction === null ? 'la resolución se aceptó' : $shortAction->getMessage()
);

$assert(
    '5.3 Diecinueve caracteres reales no alcanzan el mínimo y veinte sí',
    $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validateResolution(
        str_repeat('x', 19),
        $validAction,
        1,
        10,
        $activeCause,
        $activeSolution
    )) !== null
    && ResolutionTaxonomyValidator::validateResolution(
        str_repeat('x', 20),
        $validAction,
        1,
        10,
        $activeCause,
        $activeSolution
    ) === true
);

$emptyTexts = $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validateResolution(
    '   ',
    "\t\n",
    1,
    10,
    $activeCause,
    $activeSolution
));
$assert(
    '5.4 Los nombres del catálogo no rellenan ni rescatan los textos vacíos del técnico',
    $emptyTexts !== null && str_contains($emptyTexts->getMessage(), 'diagnóstico técnico'),
    $emptyTexts === null ? 'la resolución se aceptó' : $emptyTexts->getMessage()
);

$assert(
    '5.5 Una resolución completa y clasificada se acepta sin alterar los textos',
    ResolutionTaxonomyValidator::validateResolution(
        $validDiagnosis,
        $validAction,
        1,
        10,
        $activeCause,
        $activeSolution
    ) === true
);

$legacyWithoutTaxonomy = $rejectionOf(static fn (): bool => ResolutionTaxonomyValidator::validateResolution(
    $validDiagnosis,
    $validAction,
    null,
    null,
    null,
    null
));
$assert(
    '5.6 Un cierre histórico con textos válidos no necesita taxonomía',
    $legacyWithoutTaxonomy === null
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-TAXONOMY-04 (validador) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
