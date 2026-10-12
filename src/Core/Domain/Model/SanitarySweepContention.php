<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * SanitarySweepContention — Seguimiento inmutable del **aplazamiento por
 * contención de fila** de un expediente en el barrido del Reloj Sanitario
 * Biológico (T-PAUSE-41, RF-07.2 a RF-07.5, Art. II y Art. III).
 *
 * Problema que resuelve: la contención de fila es, por diseño, **invisible en el
 * rastro** —el perdedor de la carrera no escribe estado ni auditoría: es
 * exactamente lo que certifica `SanitaryClockConsumerRaceTest`—, así que la
 * pregunta operativa «¿este expediente lleva tres pasadas seguidas sin poder
 * evaluarse?» no se podía responder: un contador en memoria viviría dentro de un
 * proceso y la pasada siguiente corre en otro.
 *
 * Este modelo es a la vez:
 * 1. **Estado de seguimiento** (el que escribe el barrido): expediente, máquina,
 *    primera y última detección, pasadas consecutivas, total acumulado y marcas
 *    de escalado y resolución. En este camino los campos de presentación son
 *    nulos.
 * 2. **Fila de la superficie** (la que lee la Coordinación): los mismos campos
 *    más la presentación del expediente y de la máquina —código, sede, planta,
 *    estado del ticket y estado sanitario—, que sólo existen cuando la fila se
 *    lee unida a `incidents` y `machines`.
 *
 * **Episodios.** La clave de la tabla es única por expediente y se **reutiliza**:
 * un aplazamiento nuevo tras una resolución arranca un episodio nuevo
 * (`consecutive_passes` a 1, `escalated_at` y `resolved_at` limpios) conservando
 * el acumulado histórico en `total_passes`. Así la tabla no crece por máquina y
 * el histórico no se pierde (Art. III).
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class SanitarySweepContention implements JsonSerializable
{
    /**
     * @param int|null $id Identificador del seguimiento (`null` antes de persistirlo).
     * @param int $incidentId Expediente aplazado.
     * @param int $machineId Máquina cuyo bloqueo de fila sostiene el otro consumidor.
     * @param string $firstDetectedAt Primera detección del episodio vigente (`Y-m-d H:i:s`).
     * @param string $lastDetectedAt Última detección (`Y-m-d H:i:s`).
     * @param int $consecutivePasses Pasadas consecutivas del episodio vigente.
     * @param int $totalPasses Aplazamientos acumulados de todos los episodios.
     * @param string|null $escalatedAt Marca del escalado del episodio vigente.
     * @param string|null $resolvedAt Marca de cierre (nula mientras el episodio está abierto).
     * @param string|null $ticketCode Presentación: código funcional del expediente.
     * @param string|null $machineCode Presentación: código de la máquina.
     * @param string|null $locationName Presentación: sede de la máquina.
     * @param string|null $floorWing Presentación: planta o ala indicada.
     * @param string|null $incidentStatus Presentación: estado del expediente.
     * @param string|null $machineSanitaryStatus Presentación: estado sanitario de la máquina.
     *
     * @throws InvalidArgumentException Si los contadores o las marcas son incoherentes.
     */
    public function __construct(
        public ?int $id,
        public int $incidentId,
        public int $machineId,
        public string $firstDetectedAt,
        public string $lastDetectedAt,
        public int $consecutivePasses,
        public int $totalPasses,
        public ?string $escalatedAt = null,
        public ?string $resolvedAt = null,
        public ?string $ticketCode = null,
        public ?string $machineCode = null,
        public ?string $locationName = null,
        public ?string $floorWing = null,
        public ?string $incidentStatus = null,
        public ?string $machineSanitaryStatus = null,
    ) {
        if ($incidentId < 1 || $machineId < 1) {
            throw new InvalidArgumentException(
                'Un seguimiento de contención del barrido sanitario exige expediente y máquina (Art. III).'
            );
        }

        if ($consecutivePasses < 1 || $totalPasses < $consecutivePasses) {
            throw new InvalidArgumentException(
                'El seguimiento de contención no puede declarar menos pasadas consecutivas que una ni un acumulado menor que el episodio.'
            );
        }
    }

    /** ¿El episodio sigue abierto? (Una pasada posterior todavía no lo ha cerrado.) */
    public function isOpen(): bool
    {
        return $this->resolvedAt === null;
    }

    /** ¿Alcanzó el umbral de escalado en el episodio vigente? */
    public function isEscalated(): bool
    {
        return $this->escalatedAt !== null;
    }

    /** Minutos transcurridos desde la primera detección del episodio. */
    public function ageMinutes(DateTimeImmutable $now): int
    {
        return self::minutesBetween($this->firstDetectedAt, $now);
    }

    /** Minutos transcurridos desde la última detección. */
    public function minutesSinceLastDetection(DateTimeImmutable $now): int
    {
        return self::minutesBetween($this->lastDetectedAt, $now);
    }

    /** Minutos transcurridos desde el escalado (nulos si no escaló). */
    public function minutesSinceEscalation(DateTimeImmutable $now): ?int
    {
        return $this->escalatedAt === null ? null : self::minutesBetween($this->escalatedAt, $now);
    }

    private static function minutesBetween(string $moment, DateTimeImmutable $now): int
    {
        $then = new DateTimeImmutable($moment, $now->getTimezone());
        $seconds = $now->getTimestamp() - $then->getTimestamp();

        return $seconds < 0 ? 0 : (int)intdiv($seconds, 60);
    }

    /**
     * Serialización canónica de la fila de la superficie (RF-07.5): el estado de
     * escalado viaja ya resuelto (`escalated`) y las antigüedades calculadas,
     * para que la interfaz no vuelva a derivar la regla.
     *
     * @return array<string, mixed>
     */
    public function toArray(DateTimeImmutable $now): array
    {
        return [
            'id' => $this->id,
            'incident_id' => $this->incidentId,
            'ticket_code' => $this->ticketCode,
            'machine_id' => $this->machineId,
            'machine_code' => $this->machineCode,
            'location_name' => $this->locationName,
            'floor_wing' => $this->floorWing,
            'incident_status' => $this->incidentStatus,
            'machine_sanitary_status' => $this->machineSanitaryStatus,
            'first_detected_at' => $this->firstDetectedAt,
            'last_detected_at' => $this->lastDetectedAt,
            'consecutive_passes' => $this->consecutivePasses,
            'total_passes' => $this->totalPasses,
            'escalated' => $this->isEscalated(),
            'escalated_at' => $this->escalatedAt,
            'age_minutes' => $this->ageMinutes($now),
            'minutes_since_last_detection' => $this->minutesSinceLastDetection($now),
        ];
    }

    /**
     * Forma sin instante de referencia explícito: usa el reloj del proceso. La
     * superficie operativa NO la usa —pasa siempre un instante único para que
     * todas las filas de la respuesta midan la antigüedad contra el mismo
     * origen—, pero la implementación hace la clase serializable por sí sola.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray(new DateTimeImmutable('now'));
    }
}
