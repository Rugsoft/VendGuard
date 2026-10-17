<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use DateTimeImmutable;
use VendGuard\Core\Domain\ValueObject\NotificationStatus;

/**
 * NotificationRetryPolicy — Política pura de reintentos y cálculo de backoff progresivo (T-NOTIF-03).
 *
 * Cumple con los requisitos RF-06.1 y RF-06.2:
 * 1. Curva de espera progresiva ante fallos transitorios:
 *    - Intento 1 fallido (attempts = 1 tras el 1.º fallo): espera de 60 segundos (1 minuto).
 *    - Intento 2 fallido (attempts = 2 tras el 2.º fallo): espera de 300 segundos (5 minutos).
 *    - Intento 3 fallido (attempts = 3 tras el 3.º fallo): espera de 900 segundos (15 minutos).
 *    - Intento 4 fallido (attempts = 4 tras el 4.º fallo): espera de 3600 segundos (60 minutos / 1 hora).
 * 2. Paso a FAILED_PERMANENT:
 *    - Si attempts >= 5 (máximo de 5 intentos fallidos alcanzado).
 *    - O si isPermanentFailure === true (rechazo permanente e irreversible: dirección inválida, buzón inexistente, URL malformada, etc.).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas, sin I/O ni reloj oculto.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class NotificationRetryPolicy
{
    /** Máximo número de intentos permitidos antes de pasar a fallo definitivo. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Curva de segundos de retraso indexada por el número de intentos ya realizados (1..4).
     * @var array<int, int>
     */
    public const BACKOFF_DELAYS_SECONDS = [
        1 => 60,    // 1 minuto
        2 => 300,   // 5 minutos
        3 => 900,   // 15 minutos
        4 => 3600,  // 60 minutos
    ];

    /**
     * Evalúa el resultado de un intento fallido y devuelve el nuevo estado canónico y la fecha del próximo intento.
     *
     * @param int $currentAttempts Número de intentos ya ejecutados (incluyendo el actual que acaba de fallar).
     * @param bool $isPermanentFailure Indica si el fallo recibido fue permanente/irreversible.
     * @param DateTimeImmutable $referenceTime Marca temporal de referencia (por inyección para determinismo).
     * @return array{status: NotificationStatus, next_attempt_at: ?DateTimeImmutable, delay_seconds: ?int}
     */
    public static function evaluateFailure(
        int $currentAttempts,
        bool $isPermanentFailure,
        DateTimeImmutable $referenceTime
    ): array {
        if ($isPermanentFailure || $currentAttempts >= self::MAX_ATTEMPTS) {
            return [
                'status' => NotificationStatus::FAILED_PERMANENT,
                'next_attempt_at' => null,
                'delay_seconds' => null,
            ];
        }

        $delay = self::getDelaySecondsForAttempt($currentAttempts);
        $nextAttemptAt = $referenceTime->modify(sprintf('+%d seconds', $delay));

        return [
            'status' => NotificationStatus::PENDING_RETRY,
            'next_attempt_at' => $nextAttemptAt,
            'delay_seconds' => $delay,
        ];
    }

    /**
     * Devuelve el retardo en segundos para un determinado intento fallido (1..4).
     */
    public static function getDelaySecondsForAttempt(int $attempt): int
    {
        return self::BACKOFF_DELAYS_SECONDS[$attempt] ?? 3600;
    }

    /**
     * Indica si un número de intentos ha alcanzado o superado el umbral máximo de reintentos.
     */
    public static function hasExceededMaxAttempts(int $attempts): bool
    {
        return $attempts >= self::MAX_ATTEMPTS;
    }
}
