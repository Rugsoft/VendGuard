<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use JsonSerializable;

/**
 * HealthCheckResponseDto — Respuesta canónica del diagnóstico técnico de enlace
 * (RF-07, EARS-07.1 a EARS-07.4; tarea T-HYBRID-13).
 *
 * Vive separada en dos proyecciones, y la separación es la regla, no un detalle
 * de presentación:
 * 1. **Proyección pública** (`toArray(false)`): sólo `status` y `timestamp_utc`.
 *    EARS-07.2 prohíbe publicar el motor, el agrupador, el host o la latencia
 *    exacta, porque eso es superficie de reconocimiento gratuita (Art. V.4 y
 *    Art. VI.1). Es también la proyección que devuelve `jsonSerialize()`, de modo
 *    que un `json_encode($dto)` despistado **no puede** publicar telemetría: lo
 *    seguro es el comportamiento por defecto y lo detallado hay que pedirlo.
 * 2. **Telemetría extendida** (`toArray(true)`): el mismo `status` y
 *    `timestamp_utc` más `database_driver`, `connection_pooler`,
 *    `response_time_ms` y `sanitary_clock` (`last_pass_at` y `last_result`), que es
 *    la señal con la que el vigilante externo detecta una ventana de barrido
 *    perdida (T-HYBRID-04, plan §2.1). Sólo viaja cuando la petición presenta la
 *    credencial de proceso batch o una sesión de Coordinador (EARS-07.3).
 *
 * `failure_reason` es un diagnóstico de **conjunto cerrado** —lo decide el
 * servicio a partir del TIPO del fallo, nunca de su texto— que existe porque el
 * endpoint no puede publicar el volcado de la excepción (EARS-07.4): un
 * operario necesita saber *por qué* está degradado, y el mensaje de un fallo de
 * conexión puede llevar host, usuario y credenciales dentro. Por eso el DTO no
 * tiene ningún campo capaz de transportar un DSN, un usuario o una contraseña:
 * la fuga no se filtra aquí, no se puede declarar.
 *
 * Estados y su invariante: `HEALTHY` es el único estado sin motivo; `DEGRADED`
 * significa que la verificación respondió pero algo no está completo (telemetría
 * que no se pudo leer, o latencia por encima del presupuesto de EARS-07.1); y
 * `UNHEALTHY` significa que el motor no confirmó la verificación, que es el caso
 * del `503` de EARS-07.4.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class HealthCheckResponseDto implements JsonSerializable
{
    /** El motor confirmó la verificación y la telemetría está completa. */
    public const STATUS_HEALTHY = 'HEALTHY';

    /** El motor respondió, pero la verificación no está completa o llegó tarde. */
    public const STATUS_DEGRADED = 'DEGRADED';

    /** El motor no confirmó la verificación: el endpoint responde `503`. */
    public const STATUS_UNHEALTHY = 'UNHEALTHY';

    /** Estados admitidos del contrato. */
    public const STATUSES = [self::STATUS_HEALTHY, self::STATUS_DEGRADED, self::STATUS_UNHEALTHY];

    /** Forma exigida del instante: ISO-8601 en UTC, sin ambigüedad de zona. */
    private const UTC_INSTANT_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    /**
     * @param string $status Estado del enlace (uno de `STATUSES`).
     * @param string $timestampUtc Instante ISO-8601 en UTC de la comprobación.
     * @param string|null $databaseDriver Motor activo, SÓLO en telemetría autenticada.
     * @param string|null $connectionPooler Agrupador declarado, SÓLO en telemetría autenticada.
     * @param float|null $responseTimeMs Latencia medida de la verificación, SÓLO en telemetría.
     * @param string|null $sanitaryClockLastPassAt Última pasada del barrido en ISO-8601 UTC, o `null`.
     * @param string|null $sanitaryClockLastResult Resultado de esa pasada, o `null` si no la hay.
     * @param string|null $failureReason Motivo de degradación del conjunto cerrado, o `null`.
     *
     * @throws InvalidArgumentException Si el estado, el instante o la coherencia
     *                                  de la telemetría no respetan el contrato.
     */
    public function __construct(
        public string $status,
        public string $timestampUtc,
        public ?string $databaseDriver = null,
        public ?string $connectionPooler = null,
        public ?float $responseTimeMs = null,
        public ?string $sanitaryClockLastPassAt = null,
        public ?string $sanitaryClockLastResult = null,
        public ?string $failureReason = null,
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(
                "Estado de salud no admitido: '{$status}'. Los válidos son " . implode(', ', self::STATUSES) . '.'
            );
        }

        if (preg_match(self::UTC_INSTANT_PATTERN, $timestampUtc) !== 1) {
            throw new InvalidArgumentException(
                "El instante del diagnóstico debe viajar en ISO-8601 UTC (p. ej. 2026-10-14T08:30:00Z): '{$timestampUtc}'."
            );
        }

        if (($status === self::STATUS_HEALTHY) !== ($failureReason === null)) {
            throw new InvalidArgumentException(
                'Un diagnóstico sano no declara motivo y uno degradado o no disponible siempre declara uno: '
                . "estado {$status} con motivo " . ($failureReason ?? 'ausente') . '.'
            );
        }

        if ($responseTimeMs !== null && $responseTimeMs < 0.0) {
            throw new InvalidArgumentException('La latencia medida no puede ser negativa.');
        }

        if ($databaseDriver !== null && $databaseDriver === '') {
            throw new InvalidArgumentException('El motor declarado en la telemetría no puede venir vacío.');
        }

        // La telemetría del barrido viaja entera o no viaja: un instante sin
        // resultado dejaría a la superficie de alerta adivinando el desenlace.
        if (($sanitaryClockLastPassAt === null) !== ($sanitaryClockLastResult === null)) {
            throw new InvalidArgumentException(
                'La telemetría del barrido declara la última pasada y su resultado, o ninguno de los dos.'
            );
        }

        if ($sanitaryClockLastPassAt !== null && preg_match(self::UTC_INSTANT_PATTERN, $sanitaryClockLastPassAt) !== 1) {
            throw new InvalidArgumentException(
                'La última pasada del barrido también viaja en ISO-8601 UTC, para que el vigilante externo '
                . "no compare contra un reloj desplazado: '{$sanitaryClockLastPassAt}'."
            );
        }
    }

    /**
     * ¿El enlace está disponible? Es la pregunta que decide entre `200` y `503`
     * (EARS-07.1 y EARS-07.4), y se responde aquí para que el controlador no
     * tenga que reinterpretar una cadena de estado.
     */
    public function isAvailable(): bool
    {
        return $this->status !== self::STATUS_UNHEALTHY;
    }

    /**
     * Serialización canónica del contrato (plan §2.1).
     *
     * @param bool $includeDetails Si es `true`, añade la telemetría detallada; si no,
     *                             devuelve la proyección pública mínima (EARS-07.2).
     * @return array<string, mixed>
     */
    public function toArray(bool $includeDetails = false): array
    {
        $public = [
            'status' => $this->status,
            'timestamp_utc' => $this->timestampUtc,
        ];

        if (!$includeDetails) {
            return $public;
        }

        return [
            'status' => $this->status,
            'database_driver' => $this->databaseDriver,
            'connection_pooler' => $this->connectionPooler,
            'response_time_ms' => $this->responseTimeMs,
            'sanitary_clock' => [
                'last_pass_at' => $this->sanitaryClockLastPassAt,
                'last_result' => $this->sanitaryClockLastResult,
            ],
            'failure_reason' => $this->failureReason,
            'timestamp_utc' => $this->timestampUtc,
        ];
    }

    /**
     * Proyección pública por defecto: lo detallado exige pedirlo.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray(false);
    }
}
