<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

/**
 * RestockingBiFilterDto
 *
 * Filtros validados del Cuadro de Mando de Reposición, Roturas de Stock y
 * Mermas Comerciales (Módulo 38; RF-01.1, RF-01.2, C1, C5, QA-5).
 *
 * El DTO es la frontera de validación del controlador: un par `from`/`to`
 * invertido, una fecha civil inexistente (`2026-02-30`), un rango de más de
 * `366` días naturales o un identificador no positivo se rechazan aquí con
 * `400 INVALID_FILTER_PARAMS` antes de que una sola consulta agregada llegue al
 * motor. El repositorio y el servicio pueden asumir entonces que las fechas son
 * reales, que el rango tiene orden y que los identificadores son positivos.
 *
 * La ventana se publica en las dos formas que el contrato necesita:
 *
 *   - `from()` / `to()`: fechas civiles de días naturales inclusivos.
 *   - `fromAt()` / `toExclusiveAt()`: intervalo semiabierto
 *     `[from 00:00:00, (to + 1 día) 00:00:00)` en `Europe/Madrid` (`C1`), para
 *     que ninguna consulta pierda los últimos segundos del día `to` ni sufra
 *     desfases en los cambios de hora de marzo y octubre.
 *
 * El cómputo de días naturales se ancla a medianoche UTC a propósito: contar
 * horas reales en `Europe/Madrid` daría `365` días a un rango civil de `366`
 * cuando cruza el cambio de hora. Los instantes de consulta, en cambio, sí se
 * construyen en la zona de negocio.
 *
 * `to > today` está expresamente admitido (`QA-5`): el DTO no conoce el reloj,
 * solo exige orden y techo de rango. El defecto de `30` días lo resuelve
 * `RestockingBiFilterValidator`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades `readonly`, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class RestockingBiFilterDto implements JsonSerializable
{
    /** Zona horaria de negocio del módulo (`C1`). */
    public const TIMEZONE = 'Europe/Madrid';

    /** Ventana por defecto cuando no se informa el par `from`/`to` (`RF-01.1`). */
    public const DEFAULT_WINDOW_DAYS = 30;

    /** Techo de días naturales inclusivos admitido (`RF-01.2`, `C5`). */
    public const MAX_INCLUSIVE_DAYS = 366;

    /** Código del contrato REST para el filtro inválido (`C5`). */
    public const INVALID_FILTER_PARAMS = 'INVALID_FILTER_PARAMS';

    private readonly string $from;

    private readonly string $to;

    private readonly int $inclusiveDays;

    private readonly DateTimeImmutable $fromAt;

    private readonly DateTimeImmutable $toExclusiveAt;

    private readonly ?int $zoneId;

    private readonly ?int $locationId;

    private readonly ?int $restockerId;

    /**
     * @throws InvalidArgumentException Con `INVALID_FILTER_PARAMS` si el par de
     *                                  fechas no es un rango civil válido o si
     *                                  algún identificador no es positivo.
     */
    public function __construct(
        string $from,
        string $to,
        ?int $zoneId = null,
        ?int $locationId = null,
        ?int $restockerId = null
    ) {
        $this->from = self::assertCivilDate($from, 'from');
        $this->to = self::assertCivilDate($to, 'to');

        $fromAt = new DateTimeImmutable($this->from . ' 00:00:00', new DateTimeZone(self::TIMEZONE));
        $toAt = new DateTimeImmutable($this->to . ' 00:00:00', new DateTimeZone(self::TIMEZONE));

        if ($fromAt > $toAt) {
            throw new InvalidArgumentException(sprintf(
                '%s: la fecha from no puede ser posterior a to; se recibió «%s» > «%s» (field: from).',
                self::INVALID_FILTER_PARAMS,
                $this->from,
                $this->to
            ));
        }

        $inclusiveDays = self::countInclusiveDays($this->from, $this->to);
        if ($inclusiveDays > self::MAX_INCLUSIVE_DAYS) {
            throw new InvalidArgumentException(sprintf(
                '%s: el rango temporal máximo permitido es de %d días naturales; se solicitaron %d (field: to).',
                self::INVALID_FILTER_PARAMS,
                self::MAX_INCLUSIVE_DAYS,
                $inclusiveDays
            ));
        }

        $this->inclusiveDays = $inclusiveDays;
        $this->fromAt = $fromAt;
        $this->toExclusiveAt = $toAt->modify('+1 day');
        $this->zoneId = self::assertPositiveId($zoneId, 'zone_id');
        $this->locationId = self::assertPositiveId($locationId, 'location_id');
        $this->restockerId = self::assertPositiveId($restockerId, 'restocker_id');
    }

    public function from(): string
    {
        return $this->from;
    }

    public function to(): string
    {
        return $this->to;
    }

    /** Días naturales inclusivos del rango (`(to - from) + 1`). */
    public function inclusiveDays(): int
    {
        return $this->inclusiveDays;
    }

    public function fromAt(): DateTimeImmutable
    {
        return $this->fromAt;
    }

    public function toExclusiveAt(): DateTimeImmutable
    {
        return $this->toExclusiveAt;
    }

    public function zoneId(): ?int
    {
        return $this->zoneId;
    }

    public function locationId(): ?int
    {
        return $this->locationId;
    }

    public function restockerId(): ?int
    {
        return $this->restockerId;
    }

    public function timezone(): string
    {
        return self::TIMEZONE;
    }

    /** `zone_id` o `location_id` activos: la térmica y `machines_per_shift` dejan de segmentarse (`C3`). */
    public function hasTerritorialFilter(): bool
    {
        return $this->zoneId !== null || $this->locationId !== null;
    }

    /** Filtro individual por reponedor: bloquea la merma térmica APPCC (`D2`, `QA-A5`). */
    public function isRestockerScoped(): bool
    {
        return $this->restockerId !== null;
    }

    /**
     * Bloque `filters` del contrato JSON (§3.1).
     *
     * @return array{from: string, to: string, zone_id: int|null, location_id: int|null, restocker_id: int|null, timezone: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'zone_id' => $this->zoneId,
            'location_id' => $this->locationId,
            'restocker_id' => $this->restockerId,
            'timezone' => self::TIMEZONE,
        ];
    }

    /**
     * @return array{from: string, to: string, zone_id: int|null, location_id: int|null, restocker_id: int|null, timezone: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Fecha civil gregoriana real en formato estricto `YYYY-MM-DD`.
     *
     * La ida y vuelta al mismo texto distingue una fecha real de una corrección
     * silenciosa del calendario (`2026-02-30` → `2026-03-02`).
     *
     * @throws InvalidArgumentException
     */
    private static function assertCivilDate(string $value, string $field): string
    {
        $normalized = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);

        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $normalized) !== 1
            || $parsed === false
            || $parsed->format('Y-m-d') !== $normalized
        ) {
            throw new InvalidArgumentException(sprintf(
                '%s: %s debe ser una fecha civil real en formato YYYY-MM-DD; se recibió «%s» (field: %s).',
                self::INVALID_FILTER_PARAMS,
                $field,
                $value,
                $field
            ));
        }

        return $normalized;
    }

    /**
     * Días naturales inclusivos, contados sobre medianoche UTC.
     *
     * @throws InvalidArgumentException
     */
    private static function countInclusiveDays(string $from, string $to): int
    {
        $utc = new DateTimeZone('UTC');
        $fromDate = new DateTimeImmutable($from . ' 00:00:00', $utc);
        $toDate = new DateTimeImmutable($to . ' 00:00:00', $utc);

        return (int)$fromDate->diff($toDate)->days + 1;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertPositiveId(?int $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value < 1) {
            throw new InvalidArgumentException(sprintf(
                '%s: %s debe ser un entero estrictamente positivo; se recibió «%d» (field: %s).',
                self::INVALID_FILTER_PARAMS,
                $field,
                $value,
                $field
            ));
        }

        return $value;
    }
}
