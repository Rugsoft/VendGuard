<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;

/**
 * IncidentPauseResponseDto — Proyección inmutable del estado de pausa y del
 * recálculo de SLA (Módulo 11, T-PAUSE-03).
 *
 * Es la respuesta única de los cuatro caminos que alteran la pausa (pausa de
 * técnico, pausa de coordinador, reanudación manual y reactivación automática
 * por comentario de sede), de modo que la interfaz de usuario lea siempre la
 * misma forma:
 *
 * - `is_sla_paused`: el reloj contractual está congelado en este instante (RF-03.2).
 * - `accumulated_pause_minutes`: minutos descontables de SLA y MTTR, redondeados
 *   al minuto para la interfaz sobre la precisión de segundos que guarda la base
 *   de datos (RNF-01).
 * - `sla_target_at_original` y `sla_target_at_shifted`: la fecha límite antes y
 *   después de desplazarla en horario comercial de la sede (RF-03.3). El nombre
 *   `sla_target_at_shifted` es el que fija la tarea T-PAUSE-03; equivale al
 *   `new_sla_target_at` que el plan ilustra en el ejemplo de reanudación.
 *
 * Ambos extremos del desplazamiento viajan siempre, aunque sean nulos (una
 * incidencia sin compromiso de SLA), porque el contrato exige que la interfaz
 * pueda distinguir "sin fecha" de "campo ausente".
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class IncidentPauseResponseDto implements JsonSerializable
{
    /**
     * @param int $incidentId Identificador interno de la incidencia.
     * @param string $ticketCode Código funcional del expediente (INC-AAAA-NNNN).
     * @param IncidentStatus $status Estado resultante de la operación.
     * @param bool $isSlaPaused Si el reloj contractual queda congelado.
     * @param int $accumulatedPauseMinutes Minutos de pausa acumulados por el ticket.
     * @param string|null $slaTargetAtOriginal Fecha límite contractual antes del desplazamiento.
     * @param string|null $slaTargetAtShifted Fecha límite contractual tras el desplazamiento.
     * @param string|null $pausedAt Marca temporal de la pausa vigente (nula tras reanudar).
     * @param IncidentPauseReasonCategory|null $reasonCategory Causa tipificada vigente.
     * @param string|null $reasonText Justificación vigente de la pausa.
     *
     * @throws InvalidArgumentException Si los minutos acumulados son negativos.
     */
    public function __construct(
        public int $incidentId,
        public string $ticketCode,
        public IncidentStatus $status,
        public bool $isSlaPaused,
        public int $accumulatedPauseMinutes,
        public ?string $slaTargetAtOriginal,
        public ?string $slaTargetAtShifted,
        public ?string $pausedAt = null,
        public ?IncidentPauseReasonCategory $reasonCategory = null,
        public ?string $reasonText = null,
    ) {
        if ($accumulatedPauseMinutes < 0) {
            throw new InvalidArgumentException(
                'Los minutos acumulados de pausa no pueden ser negativos.'
            );
        }
    }

    /**
     * Serialización canónica del contrato (plan.md §2.1/§2.2): los cuatro campos
     * que la interfaz necesita para pintar el reloj congelado se emiten siempre,
     * y la causa solo viaja cuando existe una pausa con contexto declarado.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'incident_id' => $this->incidentId,
            'ticket_code' => $this->ticketCode,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_sla_paused' => $this->isSlaPaused,
            'accumulated_pause_minutes' => $this->accumulatedPauseMinutes,
            'sla_target_at_original' => $this->slaTargetAtOriginal,
            'sla_target_at_shifted' => $this->slaTargetAtShifted,
            'paused_at' => $this->pausedAt,
        ];

        if ($this->reasonCategory !== null) {
            $payload['reason_category'] = $this->reasonCategory->value;
            $payload['reason_category_label'] = $this->reasonCategory->label();
        }

        if ($this->reasonText !== null) {
            $payload['reason_text'] = $this->reasonText;
        }

        return $payload;
    }

    /**
     * Serialización JSON nativa (`json_encode`) con la misma estructura canónica.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
