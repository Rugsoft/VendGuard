<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;

/**
 * IncidentPauseRequestDto — Solicitud inmutable de pausa a "Pendiente de Información"
 * (Módulo 11, T-PAUSE-03).
 *
 * Modela las dos únicas decisiones que toma quien pausa (RF-01.2, RF-01.3):
 * una **causa tipificada** del catálogo cerrado `IncidentPauseReasonCategory` y un
 * **texto explicativo** de al menos 20 caracteres reales. Sin causa no hay pausa
 * auditable; sin justificación suficiente la pausa no es defendible ante una
 * reclamación contractual (Art. V.1).
 *
 * Validación temprana (validate-before-persist, AGENTS.md §4.3): el DTO es la
 * única puerta de entrada y falla en construcción, de modo que ni el servicio ni
 * el repositorio pueden recibir una pausa inválida ya construida.
 *
 * Contrato HTTP (plan.md §2.1): la ausencia de campos es responsabilidad del
 * controlador (`400 MISSING_PAUSE_FIELDS`); lo que este DTO rechaza son los
 * valores inválidos —causa fuera del catálogo o justificación corta—, que el
 * controlador mapea a `422` capturando `InvalidArgumentException`, exactamente
 * igual que el resto de controladores del proyecto.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class IncidentPauseRequestDto
{
    /**
     * Longitud mínima real de la justificación (sin espacios en blanco
     * superfluos): el umbral que fija el Art. V.1 y repite RF-01.3.
     */
    public const MIN_REASON_TEXT_LENGTH = 20;

    /**
     * @param IncidentPauseReasonCategory $reasonCategory Causa tipificada del bloqueo.
     * @param string $reasonText Justificación escrita por quien pausa.
     *
     * @throws InvalidArgumentException Si la justificación no alcanza el mínimo legal.
     */
    public function __construct(
        public IncidentPauseReasonCategory $reasonCategory,
        public string $reasonText,
    ) {
        if (mb_strlen(trim($reasonText)) < self::MIN_REASON_TEXT_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'La justificación de la pausa debe contener al menos %d caracteres reales (se recibieron %d).',
                self::MIN_REASON_TEXT_LENGTH,
                mb_strlen(trim($reasonText))
            ));
        }
    }

    /**
     * Construye el DTO desde el cuerpo crudo de la API (`reason_category`,
     * `reason_text`), convirtiendo las cadenas de texto en el catálogo tipado.
     *
     * @param array<string, mixed> $payload Cuerpo JSON ya decodificado.
     *
     * @throws InvalidArgumentException Si falta un campo o su valor no es válido.
     */
    public static function fromPayload(array $payload): self
    {
        $category = $payload['reason_category'] ?? null;
        if (!is_string($category) || trim($category) === '') {
            throw new InvalidArgumentException(
                'La pausa requiere una causa tipificada obligatoria (reason_category).'
            );
        }

        $reasonText = $payload['reason_text'] ?? null;
        if (!is_string($reasonText)) {
            throw new InvalidArgumentException(
                'La pausa requiere un texto explicativo obligatorio (reason_text).'
            );
        }

        return new self(IncidentPauseReasonCategory::fromString($category), $reasonText);
    }

    /**
     * Texto normalizado que se persiste en la incidencia y se sella en el historial
     * de auditoría: sin espacios sobrantes para no auditar ruido (RF-01.4).
     */
    public function normalizedReasonText(): string
    {
        return trim($this->reasonText);
    }

    /**
     * Proyección del contrato de pausa, por si el llamante necesita eco del payload
     * normalizado (p. ej. para construir el evento inmutable de auditoría).
     *
     * @return array{reason_category: string, reason_category_label: string, reason_text: string}
     */
    public function toArray(): array
    {
        return [
            'reason_category' => $this->reasonCategory->value,
            'reason_category_label' => $this->reasonCategory->label(),
            'reason_text' => $this->normalizedReasonText(),
        ];
    }
}
