<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use JsonSerializable;

/**
 * MttrMetric
 * 
 * Value Object inmutable que modela el resultado matemático y formateo del MTTR (Mean Time To Resolve).
 * Da estricto cumplimiento a RF-01 (EARS 1.1 a 1.8), garantizando el tratamiento seguro
 * de muestra vacía ("N/A"), formateo legible en "Xh Ym" y cálculo decimal.
 * 
 * Cumple con el Dogma Vanilla (PHP 8.2+ estricto, sin librerías externas) y el Dualismo Lingüístico.
 */
class MttrMetric implements JsonSerializable
{
    private ?int $minutes;
    private string $formatted;
    private ?float $hours;

    /**
     * @param int|null $minutes Tiempo medio en minutos enteros, o null si la muestra está vacía.
     * @param string $formatted Representación legible ("3h 15m" o "N/A").
     * @param float|null $hours Tiempo medio en horas con un decimal, o null si la muestra está vacía.
     */
    public function __construct(?int $minutes, string $formatted, ?float $hours)
    {
        $this->minutes = $minutes;
        $this->formatted = $formatted;
        $this->hours = $hours;
    }

    /**
     * Calcula el MTTR determinista a partir de un listado de tickets o intervalos de tiempo (EARS 1.1 a 1.8).
     * Excluye tickets cancelados/duplicados y gestiona anomalías de reloj.
     * 
     * @param array<int, array<string, mixed>> $resolvedTickets Lista de tickets con 'created_at', 'resolved_at' y opcionalmente 'status'.
     * @return self
     */
    public static function fromTicketDurations(array $resolvedTickets): self
    {
        if (empty($resolvedTickets)) {
            return self::noData();
        }

        $totalMinutes = 0;
        $validCount = 0;

        foreach ($resolvedTickets as $ticket) {
            $status = isset($ticket['status']) ? strtoupper((string)$ticket['status']) : 'RESOLVED';
            // Exclusión estricta de cancelados y duplicados (EARS 1.4)
            if ($status === 'CANCELLED' || $status === 'DUPLICATE') {
                continue;
            }

            if (empty($ticket['created_at']) || empty($ticket['resolved_at'])) {
                continue;
            }

            $created = strtotime((string)$ticket['created_at']);
            $resolved = strtotime((string)$ticket['resolved_at']);

            if ($created === false || $resolved === false) {
                continue;
            }

            // Minutos transcurridos en tiempo natural continuo 24/7 (EARS 1.1)
            $diffSeconds = $resolved - $created;

            // Protección ante anomalía horaria (EARS 1.7)
            if ($diffSeconds < 0) {
                $diffSeconds = 0;
            }

            $diffMinutes = (int)round($diffSeconds / 60.0);
            $totalMinutes += $diffMinutes;
            $validCount++;
        }

        if ($validCount === 0) {
            return self::noData();
        }

        $avgMinutes = (int)round($totalMinutes / $validCount);
        return self::fromMinutes($avgMinutes);
    }

    /**
     * Crea una instancia de MTTR a partir de un valor de minutos (o null para muestra vacía).
     * 
     * @param int|null $minutes Minutos totales promedio de resolución.
     * @return self
     */
    public static function fromMinutes(?int $minutes): self
    {
        if ($minutes === null || $minutes < 0) {
            return new self(null, 'N/A', null);
        }

        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $formatted = sprintf('%dh %02dm', $h, $m);
        $hours = round($minutes / 60.0, 1);

        return new self($minutes, $formatted, $hours);
    }

    /**
     * Crea una instancia vacía estándar cuando no existen tickets resueltos en el periodo (EARS 1.6).
     * 
     * @return self
     */
    public static function noData(): self
    {
        return new self(null, 'N/A', null);
    }

    public function getMinutes(): ?int
    {
        return $this->minutes;
    }

    public function getFormatted(): string
    {
        return $this->formatted;
    }

    public function getHours(): ?float
    {
        return $this->hours;
    }

    public function hasData(): bool
    {
        return $this->minutes !== null;
    }

    public function toArray(): array
    {
        return [
            'minutes' => $this->minutes,
            'formatted' => $this->formatted,
            'hours' => $this->hours,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
