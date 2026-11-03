<?php

declare(strict_types=1);

/**
 * CustodyChannelResolverTest · F-04
 *
 * Certifica la regla pura que decide el canal de una reasignación de
 * Coordinación: solo es cobertura de ausencia cuando el técnico saliente
 * mantiene una ausencia vigente (EARS 02.1 / 02.2).
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Enum\IncidentAssignmentSource;
use VendGuard\Core\Domain\Enum\IncidentUnassignmentReasonType;
use VendGuard\Core\Domain\Service\CustodyChannelResolver;

echo "======================================================================\n";
echo " VendGuard: CustodyChannelResolverTest (F-04)\n";
echo "======================================================================\n\n";

$failures = 0;
$assertions = 0;

$assert = function (string $title, bool $condition, string $message = '') use (&$failures, &$assertions): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$title}\n";
    } else {
        echo "  [FAIL] {$title}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

[$source, $reason] = CustodyChannelResolver::resolve(true, true);
$assert(
    '1. Reasignación con ausencia vigente => canal y causa ABSENCE_COVERAGE_REASSIGN',
    $source === IncidentAssignmentSource::ABSENCE_COVERAGE_REASSIGN
        && $reason === IncidentUnassignmentReasonType::ABSENCE_COVERAGE_REASSIGN
);

[$source, $reason] = CustodyChannelResolver::resolve(true, false);
$assert(
    '2. Reasignación sin ausencia => sin canal propio (decide el repositorio)',
    $source === null && $reason === null
);

[$source, $reason] = CustodyChannelResolver::resolve(false, true);
$assert(
    '3. Asignación inicial de un técnico ausente no se marca como cobertura',
    $source === null && $reason === null
);

$assert(
    '4. La causa de cobertura suma al contador de reasignaciones (R2)',
    IncidentUnassignmentReasonType::ABSENCE_COVERAGE_REASSIGN->isUnresolvedTransferOrRelease() === true
);

echo "\n======================================================================\n";
echo sprintf(" Total Aserciones: %d | Fallidas: %d\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures > 0 ? 1 : 0);
