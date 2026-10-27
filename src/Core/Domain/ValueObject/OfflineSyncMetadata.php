<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use VendGuard\Core\Domain\Exception\OfflineSyncValidationException;

/**
 * OfflineSyncMetadata
 *
 * Objeto de valor inmutable con los metadatos que acompañan a una operación de
 * parada sincronizada desde la cola sin conexión del técnico (Módulo 24,
 * contratos §6.2).
 *
 * Encapsula cinco datos: la clave de idempotencia (`clientMutationId`), el
 * instante declarado por el reloj del dispositivo (`clientOccurredAt`), los dos
 * extremos de la cadena de pasos de una misma parada (`chainStartedAt` y
 * `chainEndedAt`) y la confirmación expresa del precinto físico in situ
 * (`sanitarySealConfirmed`, Art. II).
 *
 * ## Por qué el cálculo del sello temporal vive aquí
 *
 * El reloj del teléfono no es confiable: un sótano no desajusta la hora, pero un
 * dispositivo mal configurado sí, y sin este cálculo una intervención real de 25
 * minutos se registraría con `0 segundos` de duración, ensuciando el MTTR y las
 * horas efectivas (`QA 4`, `DT-05`). El algoritmo de §5.1 de los contratos es
 * puro —no lee el reloj ni toca la base— y por eso se puede certificar sin motor:
 *
 *   1. Con `clientOccurredAt` en `[T_prev, T_sync + 5 min]`, se adopta tal cual
 *      (`clock_skew_adjusted = false`).
 *   2. Fuera de rango, el paso inicial de una cadena se ancla a
 *      `max(T_prev, T_sync − Δt)` con `Δt = chainEndedAt − clientOccurredAt`, y
 *      el paso final a `T_sync`, conservando la duración relativa entre ambos
 *      (hasta 12 horas).
 *   3. Una acción aislada fuera de rango se ancla a `T_sync`.
 *
 * `T_prev` es el hito previo del expediente (asignación, inicio ya registrado…)
 * y `T_sync` el instante de recepción en el servidor. Ambos los aporta el
 * llamador: este objeto no consulta el reloj del proceso.
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades readonly y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class OfflineSyncMetadata
{
    /** Longitud mínima de la clave de idempotencia (contrato §6.2). */
    public const MIN_MUTATION_ID_LENGTH = 8;

    /** Longitud máxima de la clave de idempotencia (contrato §6.2). */
    public const MAX_MUTATION_ID_LENGTH = 64;

    /** Alfabeto de la clave, idéntico a `chk_offline_sync_mutation_id_format`. */
    public const MUTATION_ID_PATTERN = '/^[A-Za-z0-9_.:-]+$/';

    /** Tolerancia hacia el futuro por desfase de reloj: 5 minutos (§5.1). */
    public const MAX_FUTURE_SKEW_SECONDS = 300;

    /** Intervalo relativo máximo que se conserva entre pasos: 12 horas (§5.1). */
    public const MAX_CHAIN_DELTA_SECONDS = 43200;

    /**
     * Forma ISO-8601 con hora obligatoria y zona opcional: acepta tanto
     * `2026-10-27T09:15:23.000Z` como `2026-10-27 09:15:23+00:00`, y rechaza
     * expresiones relativas («now», «tomorrow») que harían la hora dependiente
     * del momento de la prueba.
     */
    private const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?$/';

    public readonly string $clientMutationId;

    public readonly DateTimeImmutable $clientOccurredAt;

    public readonly ?DateTimeImmutable $chainStartedAt;

    public readonly ?DateTimeImmutable $chainEndedAt;

    public readonly bool $sanitarySealConfirmed;

    public function __construct(
        string $clientMutationId,
        DateTimeImmutable $clientOccurredAt,
        ?DateTimeImmutable $chainStartedAt = null,
        ?DateTimeImmutable $chainEndedAt = null,
        bool $sanitarySealConfirmed = false
    ) {
        $normalizedId = trim($clientMutationId);
        if (!self::isValidMutationId($normalizedId)) {
            throw OfflineSyncValidationException::invalidOfflineDumpPayload(
                'el identificador de la mutación debe tener entre '
                . self::MIN_MUTATION_ID_LENGTH . ' y ' . self::MAX_MUTATION_ID_LENGTH
                . ' caracteres de [A-Za-z0-9_.:-].'
            );
        }

        $this->clientMutationId = $normalizedId;
        $this->clientOccurredAt = $clientOccurredAt->setTimezone(new DateTimeZone('UTC'));
        $this->chainStartedAt = $chainStartedAt?->setTimezone(new DateTimeZone('UTC'));
        $this->chainEndedAt = $chainEndedAt?->setTimezone(new DateTimeZone('UTC'));
        $this->sanitarySealConfirmed = $sanitarySealConfirmed;
    }

    /**
     * Extrae los metadatos del cuerpo JSON/`FormData` o de las cabeceras
     * `X-Client-Mutation-Id`, `X-Client-Occurred-At`, `X-Chain-Started-At` y
     * `X-Chain-Ended-At` (contrato §6.2).
     *
     * Devuelve `null` cuando la petición no trae clave de idempotencia: es una
     * operación online ordinaria, sin cola de por medio. Si la clave existe, el
     * resto del contrato es obligatorio y su incumplimiento lanza
     * `INVALID_OFFLINE_DUMP_PAYLOAD` antes de tocar la base.
     *
     * @param array<string, mixed> $payload Cuerpo ya decodificado.
     * @param array<string, mixed> $headers Cabeceras HTTP tal cual llegan.
     */
    public static function fromArray(array $payload, array $headers = []): ?self
    {
        $clientMutationId = self::stringValue($payload, 'client_mutation_id')
            ?? self::headerValue($headers, 'X-Client-Mutation-Id');

        if ($clientMutationId === null || trim($clientMutationId) === '') {
            return null;
        }

        $clientOccurredAt = self::stringValue($payload, 'client_occurred_at')
            ?? self::headerValue($headers, 'X-Client-Occurred-At');

        if ($clientOccurredAt === null) {
            throw OfflineSyncValidationException::invalidOfflineDumpPayload(
                'falta `client_occurred_at` junto a `client_mutation_id`.'
            );
        }

        $chainStartedAt = self::stringValue($payload, 'chain_started_at')
            ?? self::headerValue($headers, 'X-Chain-Started-At');
        $chainEndedAt = self::stringValue($payload, 'chain_ended_at')
            ?? self::headerValue($headers, 'X-Chain-Ended-At');

        return new self(
            $clientMutationId,
            self::parseTimestamp($clientOccurredAt, 'client_occurred_at'),
            self::parseOptionalTimestamp($chainStartedAt, 'chain_started_at'),
            self::parseOptionalTimestamp($chainEndedAt, 'chain_ended_at'),
            self::booleanValue($payload['sanitary_seal_confirmed'] ?? false)
        );
    }

    /**
     * Formato canónico del identificador, compartido con la entidad y el motor.
     */
    public static function isValidMutationId(string $clientMutationId): bool
    {
        $value = trim($clientMutationId);
        $length = mb_strlen($value);

        return $length >= self::MIN_MUTATION_ID_LENGTH
            && $length <= self::MAX_MUTATION_ID_LENGTH
            && preg_match(self::MUTATION_ID_PATTERN, $value) === 1;
    }

    /**
     * Resuelve el sello temporal efectivo de la operación (§5.1, RF-04.3,
     * `QA 4`).
     *
     * No lee el reloj ni escribe nada: recibe el hito previo del expediente y el
     * instante de recepción, y devuelve el veredicto que el repositorio
     * persistirá junto al sello original del dispositivo.
     *
     * @param DateTimeImmutable $previousMilestoneAt Hito previo del expediente
     *        (asignación al técnico, inicio ya registrado…). Es el suelo del
     *        sello efectivo: una intervención nunca empieza antes de existir.
     * @param DateTimeImmutable $syncedAt Instante de recepción en el servidor
     *        (`CURRENT_TIMESTAMP` capturado por el caso de uso).
     * @return array{effective_occurred_at: DateTimeImmutable, clock_skew_adjusted: bool}
     */
    public function resolveEffectiveTimestamp(
        DateTimeImmutable $previousMilestoneAt,
        DateTimeImmutable $syncedAt
    ): array {
        $utc = new DateTimeZone('UTC');
        $previousMilestone = $previousMilestoneAt->setTimezone($utc);
        $synced = $syncedAt->setTimezone($utc);
        $clientAt = $this->clientOccurredAt;

        // Caso A: el reloj del dispositivo cae dentro del rango lógico.
        $latestAccepted = $synced->modify('+' . self::MAX_FUTURE_SKEW_SECONDS . ' seconds');
        if ($clientAt >= $previousMilestone && $clientAt <= $latestAccepted) {
            return [
                'effective_occurred_at' => $clientAt,
                'clock_skew_adjusted' => false,
            ];
        }

        // Caso B: reloj fuera de rango. Se busca una cadena coherente que
        // permita conservar la duración relativa Δt.
        $effective = null;

        // Paso inicial de una cadena: el cliente ya conoce el cierre y lo envía
        // en `chain_ended_at`; Δt = cierre − inicio se descuenta del sello.
        if ($this->chainEndedAt !== null) {
            $delta = $this->chainEndedAt->getTimestamp() - $clientAt->getTimestamp();
            if ($delta > 0 && $delta <= self::MAX_CHAIN_DELTA_SECONDS) {
                $effective = $synced->modify('-' . $delta . ' seconds');
            }
        }

        // Paso final de una cadena: el inicio ya quedó anclado a
        // max(T_prev, T_sync − Δt), de modo que el cierre se ancla a T_sync y la
        // diferencia entre ambos conserva exactamente el Δt real (QA 4).
        if ($effective === null && $this->chainStartedAt !== null) {
            $delta = $clientAt->getTimestamp() - $this->chainStartedAt->getTimestamp();
            if ($delta > 0 && $delta <= self::MAX_CHAIN_DELTA_SECONDS) {
                $effective = $synced;
            }
        }

        // Acción aislada fuera de rango: no hay cadena que preserve.
        if ($effective === null) {
            $effective = $synced;
        }

        if ($effective < $previousMilestone) {
            $effective = $previousMilestone;
        }

        return [
            'effective_occurred_at' => $effective,
            'clock_skew_adjusted' => true,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function stringValue(array $payload, string $key): ?string
    {
        if (!array_key_exists($key, $payload)) {
            return null;
        }

        $value = $payload[$key];
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return null;
    }

    /**
     * Búsqueda de cabecera insensible a mayúsculas: `X-Client-Mutation-Id` y
     * `x-client-mutation-id` son la misma cabecera.
     *
     * @param array<string, mixed> $headers
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, $name) !== 0) {
                continue;
            }

            if (is_string($value)) {
                return $value;
            }

            if (is_array($value) && isset($value[0]) && is_string($value[0])) {
                return $value[0];
            }
        }

        return null;
    }

    private static function parseOptionalTimestamp(?string $value, string $field): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::parseTimestamp($value, $field);
    }

    private static function parseTimestamp(string $value, string $field): DateTimeImmutable
    {
        $normalized = trim($value);
        if (preg_match(self::TIMESTAMP_PATTERN, $normalized) !== 1) {
            throw OfflineSyncValidationException::invalidOfflineDumpPayload(
                "`{$field}` debe ser una marca ISO-8601 con hora."
            );
        }

        try {
            $parsed = new DateTimeImmutable($normalized);
        } catch (Exception) {
            throw OfflineSyncValidationException::invalidOfflineDumpPayload(
                "`{$field}` no es una marca temporal válida."
            );
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    private static function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }
}
