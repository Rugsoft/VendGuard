<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\LocationNotEnabledForOpportunisticException;

/**
 * OpportunisticLocationEligibilityPolicy — Regla **pura** de habilitación de una
 * sede para la avería oportunista in situ (Módulo 16, RF-01.1 a RF-01.3 y
 * Resolución QA 3).
 *
 * Decide si el técnico puede consultar el parque de una sede y abrir o asumir una
 * intervención en ella. Solo hay dos formas legítimas de estar habilitado:
 *
 * 1. **Parada activa (`ACTIVE_STOP`):** una incidencia correctiva en `ASSIGNED` o
 *    `IN_PROGRESS`, o una orden preventiva en `SCHEDULED` o `IN_INSPECTION`, del
 *    propio técnico en esa sede. Mientras exista, la habilitación no caduca.
 * 2. **Ventana de gracia (`GRACE_WINDOW_30M`):** el técnico cerró (`RESOLVED`) o
 *    pausó (`PENDING_PARTS` / `PENDING_INFO`) allí, o completó un preventivo
 *    (`COMPLETED`), hace **menos de 30 minutos naturales** (`1800 s`). El contrato
 *    concede **+5 minutos** (`2100 s`) cuando el formulario ya estaba abierto en
 *    pantalla (`formOpenedWithinGrace`), sin desplazar nunca la caducidad visible:
 *    `grace_expires_at` sigue siendo el minuto 30 y solo el envío se tolera.
 *
 * La política es deliberadamente **ciega al SQL**: recibe las filas ya consultadas
 * y el instante de referencia. Así el arnés puede certificar los bordes exactos
 * (1800 s, 1801 s, 2100 s, 2101 s) sin base de datos y el repositorio (T-OPP-05)
 * queda como un simple adaptador de `incident_history` y `preventive_orders`.
 *
 * **Un `PENDING_INFO` antiguo no habilita la sede.** El estado pausado no mantiene
 * la habilitación indefinida: solo arranca la ventana en el instante de la pausa.
 * Un ticket aparcado ayer no puede permitir abrir averías oportunistas hoy desde
 * la otra punta de la ciudad (Resolución QA 3, caso límite 3).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias, sin I/O, determinista.
 * Dualismo Lingüístico: código en inglés, documentación y motivos en castellano.
 */
final class OpportunisticLocationEligibilityPolicy
{
    /** Habilitación sostenida por una parada vigente del propio técnico. */
    public const ACTIVE_STOP = 'ACTIVE_STOP';

    /** Habilitación residual tras cerrar o pausar, con caducidad de 30 minutos. */
    public const GRACE_WINDOW_30M = 'GRACE_WINDOW_30M';

    /** Ventana canónica de gracia: 30 minutos naturales (RF-01.1.2). */
    public const GRACE_WINDOW_SECONDS = 1800;

    /** Tolerancia de envío con formulario ya abierto: 35 minutos (RF-01.3). */
    public const FORM_TOLERANCE_SECONDS = 2100;

    /** No hay parada activa ni salida previa registrada en la sede. */
    public const REASON_NO_ACTIVE_STOP = 'NO_ACTIVE_STOP';

    /** La última salida de la sede ya superó la ventana aplicable. */
    public const REASON_GRACE_WINDOW_EXPIRED = 'GRACE_WINDOW_EXPIRED';

    /** La marca de salida es futura respecto al reloj de la petición (desfase). */
    public const REASON_GRACE_REFERENCE_IN_FUTURE = 'GRACE_REFERENCE_IN_FUTURE';

    /** Estados correctivos que mantienen la sede habilitada (RF-01.1.1). */
    public const CORRECTIVE_ACTIVE_STATUSES = ['ASSIGNED', 'IN_PROGRESS'];

    /** Estados preventivos que mantienen la sede habilitada (RF-01.1.1). */
    public const PREVENTIVE_ACTIVE_STATUSES = ['SCHEDULED', 'IN_INSPECTION'];

    /**
     * Evalúa la habilitación de `locationId` para `technicianId` en `now`.
     *
     * Cada fila de `$correctiveStops` / `$preventiveOrders` es un array asociativo
     * con `status` y, opcionalmente, `location_id`. Las filas de otras sedes se
     * ignoran: una parada abierta en otro edificio no habilita este. Las filas sin
     * `location_id` se consideran de la sede consultada (el repositorio ya filtra).
     *
     * @param list<array<string, mixed>> $correctiveStops Incidencias del técnico (cualquier estado).
     * @param list<array<string, mixed>> $preventiveOrders Órdenes preventivas del técnico (cualquier estado).
     * @param DateTimeImmutable|null $lastStopExitAt Última salida del técnico en la sede
     *                                              (`RESOLVED`, `PENDING_*` o `COMPLETED`).
     * @param bool $formOpenedWithinGrace Si el formulario ya estaba abierto al entrar en gracia.
     * @return array{
     *     eligible: bool,
     *     enablement_type: ?string,
     *     grace_expires_at: ?string,
     *     grace_remaining_seconds: ?int,
     *     window_seconds: int,
     *     blocked_reason: ?string
     * }
     *
     * @throws InvalidArgumentException Si el técnico o la sede no son identificadores positivos.
     */
    public function evaluate(
        int $technicianId,
        int $locationId,
        DateTimeImmutable $now,
        array $correctiveStops = [],
        array $preventiveOrders = [],
        ?DateTimeImmutable $lastStopExitAt = null,
        bool $formOpenedWithinGrace = false
    ): array {
        if ($technicianId <= 0) {
            throw new InvalidArgumentException('El técnico debe tener un identificador positivo.');
        }

        if ($locationId <= 0) {
            throw new InvalidArgumentException('La sede debe tener un identificador positivo.');
        }

        if ($this->hasActiveStop($correctiveStops, $locationId, self::CORRECTIVE_ACTIVE_STATUSES)
            || $this->hasActiveStop($preventiveOrders, $locationId, self::PREVENTIVE_ACTIVE_STATUSES)
        ) {
            return $this->eligible(self::ACTIVE_STOP, null, null, 0);
        }

        if ($lastStopExitAt === null) {
            return $this->blocked(self::REASON_NO_ACTIVE_STOP);
        }

        $elapsedSeconds = $now->getTimestamp() - $lastStopExitAt->getTimestamp();

        // Una marca futura no se interpreta como «acaba de salir»: sería premiar un
        // desfase de relojes con una ventana de gracia que aún no ha empezado.
        if ($elapsedSeconds < 0) {
            return $this->blocked(self::REASON_GRACE_REFERENCE_IN_FUTURE);
        }

        $windowSeconds = $formOpenedWithinGrace
            ? self::FORM_TOLERANCE_SECONDS
            : self::GRACE_WINDOW_SECONDS;

        if ($elapsedSeconds > $windowSeconds) {
            return $this->blocked(self::REASON_GRACE_WINDOW_EXPIRED);
        }

        $graceExpiresAt = $lastStopExitAt->modify('+' . self::GRACE_WINDOW_SECONDS . ' seconds');

        return $this->eligible(
            self::GRACE_WINDOW_30M,
            $graceExpiresAt->format(DATE_ATOM),
            $this->graceRemainingSeconds($lastStopExitAt, $now),
            $windowSeconds
        );
    }

    /**
     * Atajo booleano para llamadores que solo necesitan saber si puede operar.
     *
     * @param list<array<string, mixed>> $correctiveStops
     * @param list<array<string, mixed>> $preventiveOrders
     */
    public function isEligible(
        int $technicianId,
        int $locationId,
        DateTimeImmutable $now,
        array $correctiveStops = [],
        array $preventiveOrders = [],
        ?DateTimeImmutable $lastStopExitAt = null,
        bool $formOpenedWithinGrace = false
    ): bool {
        return $this->evaluate(
            $technicianId,
            $locationId,
            $now,
            $correctiveStops,
            $preventiveOrders,
            $lastStopExitAt,
            $formOpenedWithinGrace
        )['eligible'];
    }

    /**
     * Igual que `evaluate()`, pero falla con la excepción de dominio (HTTP 403)
     * cuando la sede no está habilitada. Es la puerta que usará el servicio de
     * aplicación en T-OPP-08, de modo que el motivo del rechazo no se pierda.
     *
     * @param list<array<string, mixed>> $correctiveStops
     * @param list<array<string, mixed>> $preventiveOrders
     * @return array{
     *     eligible: bool,
     *     enablement_type: ?string,
     *     grace_expires_at: ?string,
     *     grace_remaining_seconds: ?int,
     *     window_seconds: int,
     *     blocked_reason: ?string
     * }
     *
     * @throws LocationNotEnabledForOpportunisticException Si la sede no está habilitada.
     */
    public function requireEligibility(
        int $technicianId,
        int $locationId,
        DateTimeImmutable $now,
        array $correctiveStops = [],
        array $preventiveOrders = [],
        ?DateTimeImmutable $lastStopExitAt = null,
        bool $formOpenedWithinGrace = false
    ): array {
        $decision = $this->evaluate(
            $technicianId,
            $locationId,
            $now,
            $correctiveStops,
            $preventiveOrders,
            $lastStopExitAt,
            $formOpenedWithinGrace
        );

        if (!$decision['eligible']) {
            throw new LocationNotEnabledForOpportunisticException($locationId, $technicianId);
        }

        return $decision;
    }

    /**
     * Segundos que quedan de la ventana visible de 30 minutos, nunca negativos.
     *
     * Se calcula siempre sobre `GRACE_WINDOW_SECONDS`, no sobre la tolerancia de
     * 35 minutos: la tarjeta de ruta anuncia el minuto 30 y el +5 es solo un
     * margen de cortesía para el formulario ya abierto (RF-01.2 y RF-01.3).
     */
    public function graceRemainingSeconds(DateTimeImmutable $lastStopExitAt, DateTimeImmutable $now): int
    {
        $elapsed = $now->getTimestamp() - $lastStopExitAt->getTimestamp();

        return max(0, min(self::GRACE_WINDOW_SECONDS, self::GRACE_WINDOW_SECONDS - $elapsed));
    }

    /**
     * Copy castellano del motivo de bloqueo, para no traducir en dos sitios.
     */
    public function blockedReasonLabel(?string $reason): string
    {
        return match ($reason) {
            self::REASON_NO_ACTIVE_STOP => 'La sede no tiene ninguna parada activa ni una visita reciente del técnico.',
            self::REASON_GRACE_WINDOW_EXPIRED => 'La ventana de gracia de 30 minutos para esta sede ya ha caducado.',
            self::REASON_GRACE_REFERENCE_IN_FUTURE => 'La última salida registrada en la sede es posterior al instante actual.',
            default => 'La sede está habilitada para la intervención oportunista.',
        };
    }

    /**
     * ¿Alguna fila de la sede consultada está en uno de los estados habilitantes?
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $statuses
     */
    private function hasActiveStop(array $rows, int $locationId, array $statuses): bool
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (isset($row['location_id']) && is_numeric($row['location_id'])
                && (int)$row['location_id'] !== $locationId
            ) {
                continue;
            }

            $status = strtoupper(trim((string)($row['status'] ?? '')));
            if (in_array($status, $statuses, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     eligible: true,
     *     enablement_type: string,
     *     grace_expires_at: ?string,
     *     grace_remaining_seconds: ?int,
     *     window_seconds: int,
     *     blocked_reason: null
     * }
     */
    private function eligible(
        string $enablementType,
        ?string $graceExpiresAt,
        ?int $graceRemainingSeconds,
        int $windowSeconds
    ): array {
        return [
            'eligible' => true,
            'enablement_type' => $enablementType,
            'grace_expires_at' => $graceExpiresAt,
            'grace_remaining_seconds' => $graceRemainingSeconds,
            'window_seconds' => $windowSeconds,
            'blocked_reason' => null,
        ];
    }

    /**
     * @return array{
     *     eligible: false,
     *     enablement_type: null,
     *     grace_expires_at: null,
     *     grace_remaining_seconds: null,
     *     window_seconds: 0,
     *     blocked_reason: string
     * }
     */
    private function blocked(string $reason): array
    {
        return [
            'eligible' => false,
            'enablement_type' => null,
            'grace_expires_at' => null,
            'grace_remaining_seconds' => null,
            'window_seconds' => 0,
            'blocked_reason' => $reason,
        ];
    }
}
