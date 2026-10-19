<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\OpportunisticMode;
use VendGuard\Core\Domain\ValueObject\OpportunisticOriginReason;

/**
 * CreateOpportunisticIncidentDto — Solicitud inmutable del **Flujo A · Alta
 * Oportunista In Situ** (Módulo 16, RF-04).
 *
 * Modela las decisiones que el técnico presente en la sede toma en el acto:
 * sobre qué máquina abre el parte, qué categoría declara, si la avería la
 * detectó él o se la comunicaron verbalmente, quién se la dijo (opcional) y con
 * qué descripción mínima queda registrada.
 *
 * Tres reglas se validan aquí, en construcción, y ninguna en la base de datos:
 *
 * 1. **Coherencia modalidad ↔ submotivo (RF-04.1.2):** el alta `NEW` solo admite
 *    `SITE_VERBAL_NOTICE`, `TECHNICIAN_DETECTION` o `WARRANTY_REOPEN_IN_SITU`.
 *    La misma regla que impone `chk_incidents_opportunistic_coherence`; se
 *    escribe una sola vez en el dominio y se falla antes de tocar el motor.
 * 2. **Descripción significativa (RF-04.1.3):** al menos 10 caracteres reales
 *    tras `trim()`. Un parte de una palabra no es trazabilidad, es ruido.
 * 3. **Informante ligado a su motivo:** el nombre de quien avisó solo se
 *    conserva cuando el submotivo es `SITE_VERBAL_NOTICE`; si el fallo lo
 *    detectó el propio técnico, no hay tercero a quien atribuirlo. Se recorta a
 *    100 caracteres, que es la anchura de la columna.
 *
 * La prohibición de datos bancarios (`iban`, `bizum_phone`, `refund_requested`)
 * **no** vive en este DTO sino en `OpportunisticIncidentService`: el rechazo se
 * produce sobre el payload crudo, para que el servicio pueda lanzar su excepción
 * tipada del Art. V.4 incluso cuando el campo llega con valor vacío.
 *
 * Dogma Vanilla: `final`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class CreateOpportunisticIncidentDto
{
    /** Mínimo legal de la descripción del parte (RF-04.1.3). */
    public const MIN_DESCRIPTION_LENGTH = 10;

    /** Anchura de `incidents.opportunistic_informant_name` (VARCHAR(100)). */
    public const MAX_INFORMANT_LENGTH = 100;

    public function __construct(
        public readonly int $machineId,
        public readonly IncidentCategory $category,
        public readonly OpportunisticOriginReason $originReason,
        public readonly string $description,
        public readonly ?string $informantName = null,
        public readonly ?string $photoPath = null,
        public readonly bool $confirmColdChainOverride = false,
        public readonly bool $formOpenedWithinGrace = false
    ) {
        if ($machineId < 1) {
            throw new InvalidArgumentException(
                'El alta oportunista exige una máquina persistida (machine_id positivo).'
            );
        }

        if (!$originReason->isAllowedFor(OpportunisticMode::NEW)) {
            throw new InvalidArgumentException(sprintf(
                'El submotivo «%s» no es coherente con un alta oportunista nueva (NEW).',
                $originReason->label()
            ));
        }

        if (mb_strlen(trim($description)) < self::MIN_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'La descripción del parte debe contener al menos %d caracteres reales.',
                self::MIN_DESCRIPTION_LENGTH
            ));
        }

        if ($informantName !== null && mb_strlen(trim($informantName)) > self::MAX_INFORMANT_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El nombre del informante no puede superar los %d caracteres.',
                self::MAX_INFORMANT_LENGTH
            ));
        }
    }

    /**
     * Construye el DTO desde el cuerpo crudo de la API (`application/json` o
     * `multipart/form-data` ya normalizado por el controlador).
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidArgumentException Si falta un campo obligatorio o su valor no es válido.
     */
    public static function fromPayload(array $payload): self
    {
        $machineId = $payload['machine_id'] ?? null;
        if (!is_numeric($machineId) || (int)$machineId < 1) {
            throw new InvalidArgumentException(
                'El alta oportunista requiere la máquina sobre la que se interviene (machine_id).'
            );
        }

        $category = $payload['category'] ?? null;
        if (!is_string($category) || trim($category) === '') {
            throw new InvalidArgumentException(
                'El alta oportunista requiere la categoría de la avería (category).'
            );
        }

        $originReason = $payload['origin_reason'] ?? null;
        if (!is_string($originReason) || trim($originReason) === '') {
            throw new InvalidArgumentException(
                'El alta oportunista requiere el motivo de origen (origin_reason).'
            );
        }

        $description = $payload['description'] ?? null;
        if (!is_string($description)) {
            throw new InvalidArgumentException(
                'El alta oportunista requiere la descripción del síntoma observado (description).'
            );
        }

        $informantName = $payload['informant_name'] ?? null;
        if ($informantName !== null && !is_string($informantName)) {
            throw new InvalidArgumentException('El nombre del informante debe ser texto.');
        }

        $photoPath = $payload['photo_path'] ?? null;
        if ($photoPath !== null && !is_string($photoPath)) {
            throw new InvalidArgumentException('La ruta de la fotografía adjunta debe ser texto.');
        }

        return new self(
            machineId: (int)$machineId,
            category: IncidentCategory::fromString($category),
            originReason: OpportunisticOriginReason::fromString($originReason),
            description: $description,
            informantName: $informantName,
            photoPath: $photoPath !== null && trim($photoPath) !== '' ? trim($photoPath) : null,
            confirmColdChainOverride: self::toBool($payload['confirm_cold_chain_override'] ?? false),
            formOpenedWithinGrace: self::toBool($payload['form_opened_within_grace'] ?? false)
        );
    }

    /**
     * Descripción normalizada que se persiste y se firma en el historial.
     */
    public function normalizedDescription(): string
    {
        return trim($this->description);
    }

    /**
     * Informante efectivo: solo se conserva cuando el aviso fue verbal en sede.
     */
    public function effectiveInformantName(): ?string
    {
        if ($this->originReason !== OpportunisticOriginReason::SITE_VERBAL_NOTICE) {
            return null;
        }

        $name = $this->informantName !== null ? trim($this->informantName) : '';

        return $name === '' ? null : $name;
    }

    /**
     * Nota inmutable del primer evento de historial (RF-04.2).
     */
    public function initialHistoryNote(): string
    {
        return sprintf(
            'Alta Oportunista In Situ (Oportunista In Situ · Nueva · %s). Intervención iniciada en el acto.',
            $this->originReason->label()
        );
    }

    /**
     * Proyección del payload normalizado, para auditoría y respuesta.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'machine_id' => $this->machineId,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'origin_reason' => $this->originReason->value,
            'origin_label' => $this->originReason->label(),
            'informant_name' => $this->effectiveInformantName(),
            'description' => $this->normalizedDescription(),
            'photo_path' => $this->photoPath,
            'confirm_cold_chain_override' => $this->confirmColdChainOverride,
            'form_opened_within_grace' => $this->formOpenedWithinGrace,
        ];
    }

    /**
     * Normaliza los booleanos que llegan como texto en `multipart/form-data`.
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
