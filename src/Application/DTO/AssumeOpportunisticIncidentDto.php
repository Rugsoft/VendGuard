<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;

/**
 * AssumeOpportunisticIncidentDto — Solicitud inmutable del **Flujo B · Asunción
 * y Reapertura Oportunista In Situ** (Módulo 16, RF-05).
 *
 * Reúne las tres confirmaciones que la especificación exige antes de tocar un
 * expediente ajeno o resuelto, más la nota de traspaso:
 *
 * 1. **`confirmTakeoverFromOtherTech`** (RF-05.3): absorber el expediente de un
 *    compañero puede ahorrarle un desplazamiento en vano… o dejarle esperando en
 *    la puerta. Por eso el servidor no lo hace sin confirmación expresa.
 * 2. **`takeoverInternalNote`** (RF-05.3.3): texto opcional de hasta 500
 *    caracteres que se guarda como comentario **interno** (`is_internal = TRUE`),
 *    invisible para la Sede y para el Ciudadano QR (Art. V.4). Es la explicación
 *    que el técnico reemplazado leerá para entender por qué no le espera trabajo.
 * 3. **`reopenReason`** (RF-05.4): obligatorio y de al menos 10 caracteres
 *    reales cuando el expediente está `RESOLVED` dentro de la garantía de 48 h.
 *    La obligación se comprueba en el servicio —depende del estado real leído
 *    bajo bloqueo—, pero una longitud insuficiente se rechaza aquí mismo, en
 *    construcción.
 *
 * `confirmColdChainOverride` (RF-03.1) y `formOpenedWithinGrace` (RF-01.3)
 * completan las dos salvaguardas de ruta. La primera no se interpreta en el DTO:
 * el servicio la pasa a `OpportunisticColdChainGuardPolicy`, que conoce las
 * paradas realmente postergadas.
 *
 * Igual que en el Flujo A, la prohibición de campos bancarios se aplica sobre el
 * payload crudo en `OpportunisticIncidentService` (Art. V.4).
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final readonly class AssumeOpportunisticIncidentDto
{
    /** Mínimo legal del motivo de reincidencia (RF-05.4). */
    public const MIN_REOPEN_REASON_LENGTH = 10;

    /** Anchura máxima de la nota interna de traspaso (contrato §4.4). */
    public const MAX_TAKEOVER_NOTE_LENGTH = 500;

    public function __construct(
        public bool $confirmTakeoverFromOtherTech = false,
        public ?string $takeoverInternalNote = null,
        public ?string $reopenReason = null,
        public bool $confirmColdChainOverride = false,
        public bool $formOpenedWithinGrace = false
    ) {
        if ($takeoverInternalNote !== null
            && mb_strlen(trim($takeoverInternalNote)) > self::MAX_TAKEOVER_NOTE_LENGTH
        ) {
            throw new InvalidArgumentException(sprintf(
                'La nota interna de traspaso no puede superar los %d caracteres.',
                self::MAX_TAKEOVER_NOTE_LENGTH
            ));
        }

        if ($reopenReason !== null
            && trim($reopenReason) !== ''
            && mb_strlen(trim($reopenReason)) < self::MIN_REOPEN_REASON_LENGTH
        ) {
            throw new InvalidArgumentException(sprintf(
                'El motivo de reincidencia debe contener al menos %d caracteres reales.',
                self::MIN_REOPEN_REASON_LENGTH
            ));
        }
    }

    /**
     * Construye el DTO desde el cuerpo JSON ya decodificado.
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException Si un campo opcional presente no respeta su formato.
     */
    public static function fromPayload(array $payload): self
    {
        $takeoverNote = $payload['takeover_internal_note'] ?? null;
        if ($takeoverNote !== null && !is_string($takeoverNote)) {
            throw new InvalidArgumentException('La nota de traspaso debe ser texto.');
        }

        $reopenReason = $payload['reopen_reason'] ?? null;
        if ($reopenReason !== null && !is_string($reopenReason)) {
            throw new InvalidArgumentException('El motivo de reincidencia debe ser texto.');
        }

        return new self(
            confirmTakeoverFromOtherTech: self::toBool($payload['confirm_takeover_from_other_tech'] ?? false),
            takeoverInternalNote: $takeoverNote,
            reopenReason: $reopenReason,
            confirmColdChainOverride: self::toBool($payload['confirm_cold_chain_override'] ?? false),
            formOpenedWithinGrace: self::toBool($payload['form_opened_within_grace'] ?? false)
        );
    }

    /**
     * Nota interna normalizada, o `null` si no se aportó nada legible.
     */
    public function effectiveTakeoverNote(): ?string
    {
        $note = $this->takeoverInternalNote !== null ? trim($this->takeoverInternalNote) : '';

        return $note === '' ? null : $note;
    }

    /**
     * Motivo de reincidencia normalizado, o `null` si no se aportó nada legible.
     */
    public function effectiveReopenReason(): ?string
    {
        $reason = $this->reopenReason !== null ? trim($this->reopenReason) : '';

        return $reason === '' ? null : $reason;
    }

    /**
     * Proyección del payload normalizado, para auditoría.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'confirm_takeover_from_other_tech' => $this->confirmTakeoverFromOtherTech,
            'takeover_internal_note' => $this->effectiveTakeoverNote(),
            'reopen_reason' => $this->effectiveReopenReason(),
            'confirm_cold_chain_override' => $this->confirmColdChainOverride,
            'form_opened_within_grace' => $this->formOpenedWithinGrace,
        ];
    }

    /**
     * Normaliza los booleanos que pueden llegar como texto desde el cliente.
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes', 'si', 'sí'], true);
        }

        return false;
    }
}
