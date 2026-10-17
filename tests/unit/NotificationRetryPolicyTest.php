<?php

declare(strict_types=1);

/**
 * NotificationRetryPolicyTest
 *
 * Suite de pruebas unitarias para la política pura de reintentos y backoff (T-NOTIF-03, RF-06.1, RF-06.2).
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\ValueObject\NotificationStatus;
use VendGuard\Core\Service\NotificationRetryPolicy;

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - NotificationRetryPolicyTest\n";
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

$baseTime = new DateTimeImmutable('2026-10-17 10:00:00');

// 1. Curva de esperas progresivas ante fallos transitorios
echo "--- Curva de esperas progresivas ante fallos transitorios (RF-06.1) ---\n";

$res1 = NotificationRetryPolicy::evaluateFailure(1, false, $baseTime);
$assert('1.º fallo transitorio: estado PENDING_RETRY', $res1['status'] === NotificationStatus::PENDING_RETRY);
$assert('1.º fallo transitorio: retardo de 60 segundos (1m)', $res1['delay_seconds'] === 60);
$assert(
    '1.º fallo transitorio: next_attempt_at a los 60s exactos',
    $res1['next_attempt_at']?->format('Y-m-d H:i:s') === '2026-10-17 10:01:00'
);

$res2 = NotificationRetryPolicy::evaluateFailure(2, false, $baseTime);
$assert('2.º fallo transitorio: estado PENDING_RETRY', $res2['status'] === NotificationStatus::PENDING_RETRY);
$assert('2.º fallo transitorio: retardo de 300 segundos (5m)', $res2['delay_seconds'] === 300);
$assert(
    '2.º fallo transitorio: next_attempt_at a los 300s exactos',
    $res2['next_attempt_at']?->format('Y-m-d H:i:s') === '2026-10-17 10:05:00'
);

$res3 = NotificationRetryPolicy::evaluateFailure(3, false, $baseTime);
$assert('3.º fallo transitorio: estado PENDING_RETRY', $res3['status'] === NotificationStatus::PENDING_RETRY);
$assert('3.º fallo transitorio: retardo de 900 segundos (15m)', $res3['delay_seconds'] === 900);
$assert(
    '3.º fallo transitorio: next_attempt_at a los 900s exactos',
    $res3['next_attempt_at']?->format('Y-m-d H:i:s') === '2026-10-17 10:15:00'
);

$res4 = NotificationRetryPolicy::evaluateFailure(4, false, $baseTime);
$assert('4.º fallo transitorio: estado PENDING_RETRY', $res4['status'] === NotificationStatus::PENDING_RETRY);
$assert('4.º fallo transitorio: retardo de 3600 segundos (60m)', $res4['delay_seconds'] === 3600);
$assert(
    '4.º fallo transitorio: next_attempt_at a los 3600s exactos',
    $res4['next_attempt_at']?->format('Y-m-d H:i:s') === '2026-10-17 11:00:00'
);

// 2. Paso a FAILED_PERMANENT en el 5.º intento
echo "--- Paso a FAILED_PERMANENT tras 5.º intento fallido (RF-06.2) ---\n";

$res5 = NotificationRetryPolicy::evaluateFailure(5, false, $baseTime);
$assert('5.º fallo: paso directo a FAILED_PERMANENT', $res5['status'] === NotificationStatus::FAILED_PERMANENT);
$assert('5.º fallo: next_attempt_at es null', $res5['next_attempt_at'] === null);
$assert('5.º fallo: delay_seconds es null', $res5['delay_seconds'] === null);
$assert('hasExceededMaxAttempts(5) es true', NotificationRetryPolicy::hasExceededMaxAttempts(5) === true);
$assert('hasExceededMaxAttempts(4) es false', NotificationRetryPolicy::hasExceededMaxAttempts(4) === false);

// 3. Paso directo a FAILED_PERMANENT ante rechazo permanente en cualquier intento
echo "--- Paso directo a FAILED_PERMANENT ante rechazo permanente (RF-06.2) ---\n";

$resPerm1 = NotificationRetryPolicy::evaluateFailure(1, true, $baseTime);
$assert('1.º fallo con rechazo permanente: paso directo a FAILED_PERMANENT', $resPerm1['status'] === NotificationStatus::FAILED_PERMANENT);
$assert('1.º fallo con rechazo permanente: next_attempt_at es null', $resPerm1['next_attempt_at'] === null);

$resPerm3 = NotificationRetryPolicy::evaluateFailure(3, true, $baseTime);
$assert('3.º fallo con rechazo permanente: paso directo a FAILED_PERMANENT', $resPerm3['status'] === NotificationStatus::FAILED_PERMANENT);

echo "\nResultado: {$assertions} evaluadas, {$failures} fallidas.\n";
if ($failures > 0) {
    exit(1);
}
