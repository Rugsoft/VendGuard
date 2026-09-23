<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

/**
 * MetricFilter
 * 
 * Value Object inmutable que encapsula los parámetros de filtrado temporal y dimensional
 * para consultas de métricas y auditoría.
 * 
 * Valida formatos de fecha, orden cronológico de rangos (RF-02, Casos Límite)
 * y resuelve los periodos predeterminados ('last_7_days', 'last_30_days', 'current_month', 'last_month').
 */
class MetricFilter implements JsonSerializable
{
    public const PERIOD_LAST_7_DAYS   = 'last_7_days';
    public const PERIOD_LAST_30_DAYS  = 'last_30_days';
    public const PERIOD_CURRENT_MONTH = 'current_month';
    public const PERIOD_LAST_MONTH    = 'last_month';
    public const PERIOD_CUSTOM        = 'custom';

    private string $periodKey;
    private DateTimeImmutable $from;
    private DateTimeImmutable $to;
    private ?int $locationId;
    private ?int $technicianId;

    /**
     * @param string $periodKey Clave de periodo ('last_7_days', 'last_30_days', 'current_month', 'last_month', 'custom')
     * @param DateTimeImmutable $from Fecha y hora de inicio (inclusiva)
     * @param DateTimeImmutable $to Fecha y hora de fin (inclusiva)
     * @param int|null $locationId Filtro opcional por sede
     * @param int|null $technicianId Filtro opcional por técnico
     */
    public function __construct(
        string $periodKey,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $locationId = null,
        ?int $technicianId = null
    ) {
        if ($from > $to) {
            throw new InvalidArgumentException('La fecha de inicio (Desde) no puede ser posterior a la fecha de fin (Hasta).');
        }

        $this->periodKey = $periodKey;
        $this->from = $from;
        $this->to = $to;
        $this->locationId = $locationId;
        $this->technicianId = $technicianId;
    }

    /**
     * Factoría para construir un filtro a partir de parámetros HTTP query.
     * 
     * @param array<string, mixed> $params
     * @param DateTimeImmutable|null $now Referencia temporal inmutable para testing/determinismo.
     * @return self
     * @throws InvalidArgumentException Si el rango es inválido o las fechas tienen formato erróneo.
     */
    public static function fromQueryParams(array $params, ?DateTimeImmutable $now = null): self
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));
        $period = isset($params['period']) ? (string)$params['period'] : self::PERIOD_LAST_30_DAYS;
        $locationId = !empty($params['location_id']) ? (int)$params['location_id'] : null;
        $technicianId = !empty($params['technician_id']) ? (int)$params['technician_id'] : null;

        if ($period === self::PERIOD_CUSTOM || (!empty($params['from']) && !empty($params['to']))) {
            $fromStr = (string)($params['from'] ?? '');
            $toStr = (string)($params['to'] ?? '');

            if ($fromStr === '' || $toStr === '') {
                throw new InvalidArgumentException('Para el periodo personalizado deben especificarse las fechas "from" y "to".');
            }

            $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', substr($fromStr, 0, 10), new DateTimeZone('Europe/Madrid'));
            $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', substr($toStr, 0, 10), new DateTimeZone('Europe/Madrid'));

            if ($fromDate === false || $toDate === false) {
                throw new InvalidArgumentException('Formato de fecha inválido. Se requiere el formato YYYY-MM-DD.');
            }

            // Inicio a las 00:00:00 y fin a las 23:59:59
            $from = $fromDate->setTime(0, 0, 0);
            $to = $toDate->setTime(23, 59, 59);

            return new self(self::PERIOD_CUSTOM, $from, $to, $locationId, $technicianId);
        }

        switch ($period) {
            case self::PERIOD_LAST_7_DAYS:
                $from = $now->modify('-6 days')->setTime(0, 0, 0);
                $to = $now->setTime(23, 59, 59);
                break;

            case self::PERIOD_CURRENT_MONTH:
                $from = $now->modify('first day of this month')->setTime(0, 0, 0);
                $to = $now->setTime(23, 59, 59);
                break;

            case self::PERIOD_LAST_MONTH:
                $from = $now->modify('first day of last month')->setTime(0, 0, 0);
                $to = $now->modify('last day of last month')->setTime(23, 59, 59);
                break;

            case self::PERIOD_LAST_30_DAYS:
                $period = self::PERIOD_LAST_30_DAYS;
                $from = $now->modify('-29 days')->setTime(0, 0, 0);
                $to = $now->setTime(23, 59, 59);
                break;

            default:
                throw new InvalidArgumentException("Periodo de consulta no reconocido: '{$period}'.");
        }

        return new self($period, $from, $to, $locationId, $technicianId);
    }

    /**
     * Calcula el filtro para el periodo equivalente inmediatamente anterior (misma duración en días).
     * Utilizado para calcular la variación porcentual de tendencia (EARS 3.1.1).
     * 
     * @return self
     */
    public function getPreviousEquivalentPeriod(): self
    {
        $durationSeconds = $this->to->getTimestamp() - $this->from->getTimestamp() + 1;
        $prevTo = $this->from->modify('-1 second');
        $prevFrom = $prevTo->modify('-' . ($durationSeconds - 1) . ' seconds');

        return new self('previous_' . $this->periodKey, $prevFrom, $prevTo, $this->locationId, $this->technicianId);
    }

    public function getPeriodKey(): string
    {
        return $this->periodKey;
    }

    public function getFrom(): DateTimeImmutable
    {
        return $this->from;
    }

    public function getTo(): DateTimeImmutable
    {
        return $this->to;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function getTechnicianId(): ?int
    {
        return $this->technicianId;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->periodKey,
            'from' => $this->from->format('Y-m-d H:i:s'),
            'to' => $this->to->format('Y-m-d H:i:s'),
            'location_id' => $this->locationId,
            'technician_id' => $this->technicianId,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
