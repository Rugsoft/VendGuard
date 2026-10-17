<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification\Transport;

use InvalidArgumentException;

/**
 * TransportDeliveryResult — Resultado tipado de un intento de entrega (T-NOTIF-05).
 *
 * Distingue las tres únicas salidas que el despachador necesita para decidir el
 * siguiente estado de la bandeja (RF-01.5, RF-06.1 y RF-06.2):
 *
 *   1. **SUCCESS**: el proveedor externo confirmó la recepción. El despachador
 *      transiciona la notificación a `SENT` con su `processed_at`.
 *   2. **TRANSIENT_FAILURE**: caída temporal de red, tiempo de espera agotado o
 *      indisponibilidad momentánea del servidor remoto (4xx recuperables, 5xx de
 *      SMTP/HTTP, errores de conexión). Procede programar el reintento con la
 *      espera progresiva de `NotificationRetryPolicy`.
 *   3. **PERMANENT_FAILURE**: rechazo irreversible de formato o destino (buzón
 *      inexistente, credenciales rechazadas, URL de Webhook malformada o no
 *      permitida). Procede pasar directamente a `FAILED_PERMANENT`, sin agotar
 *      los cinco intentos.
 *
 * El mensaje de error se conserva íntegro (acotado a 500 caracteres para no
 * desbordar la columna `last_error`) y nunca contiene credenciales ni la URL
 * completa del Webhook: los transportes lo depuran antes de construirlo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class TransportDeliveryResult
{
    public const OUTCOME_SUCCESS = 'SUCCESS';
    public const OUTCOME_TRANSIENT_FAILURE = 'TRANSIENT_FAILURE';
    public const OUTCOME_PERMANENT_FAILURE = 'PERMANENT_FAILURE';

    /** Longitud máxima del diagnóstico conservado (columna `last_error`). */
    public const MAX_ERROR_LENGTH = 500;

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $errorMessage,
        public readonly ?int $providerStatusCode,
    ) {
    }

    /**
     * Entrega confirmada por el proveedor externo.
     */
    public static function success(?int $providerStatusCode = null): self
    {
        return new self(self::OUTCOME_SUCCESS, null, $providerStatusCode);
    }

    /**
     * Fallo recuperable: procede reintento con espera progresiva.
     */
    public static function transientFailure(string $errorMessage, ?int $providerStatusCode = null): self
    {
        return new self(
            self::OUTCOME_TRANSIENT_FAILURE,
            self::normalizeError($errorMessage),
            $providerStatusCode
        );
    }

    /**
     * Fallo irreversible: procede el paso directo a `FAILED_PERMANENT`.
     */
    public static function permanentFailure(string $errorMessage, ?int $providerStatusCode = null): self
    {
        return new self(
            self::OUTCOME_PERMANENT_FAILURE,
            self::normalizeError($errorMessage),
            $providerStatusCode
        );
    }

    /**
     * Reconstruye el resultado desde su representación persistida.
     *
     * @throws InvalidArgumentException si el resultado declarado no es canónico.
     */
    public static function fromOutcome(string $outcome, ?string $errorMessage = null, ?int $providerStatusCode = null): self
    {
        return match (strtoupper(trim($outcome))) {
            self::OUTCOME_SUCCESS => self::success($providerStatusCode),
            self::OUTCOME_TRANSIENT_FAILURE => self::transientFailure((string)$errorMessage, $providerStatusCode),
            self::OUTCOME_PERMANENT_FAILURE => self::permanentFailure((string)$errorMessage, $providerStatusCode),
            default => throw new InvalidArgumentException(sprintf(
                'Resultado de entrega no válido: «%s».',
                $outcome
            )),
        };
    }

    public function isSuccess(): bool
    {
        return $this->outcome === self::OUTCOME_SUCCESS;
    }

    public function isTransientFailure(): bool
    {
        return $this->outcome === self::OUTCOME_TRANSIENT_FAILURE;
    }

    public function isPermanentFailure(): bool
    {
        return $this->outcome === self::OUTCOME_PERMANENT_FAILURE;
    }

    /**
     * Indica si el despachador debe programar un nuevo intento con backoff.
     */
    public function isRetryable(): bool
    {
        return $this->isTransientFailure();
    }

    /**
     * Proyección plana para la auditoría y la bandeja de supervisión.
     *
     * @return array{outcome: string, error_message: ?string, provider_status_code: ?int}
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'error_message' => $this->errorMessage,
            'provider_status_code' => $this->providerStatusCode,
        ];
    }

    private static function normalizeError(string $errorMessage): string
    {
        $message = trim(preg_replace('/\s+/u', ' ', $errorMessage) ?? $errorMessage);

        return $message === '' ? 'Fallo de entrega sin diagnóstico del proveedor.' : mb_substr(
            $message,
            0,
            self::MAX_ERROR_LENGTH
        );
    }
}
