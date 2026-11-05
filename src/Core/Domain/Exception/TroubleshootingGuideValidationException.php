<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * TroubleshootingGuideValidationException
 *
 * Única excepción de negocio del Módulo 28. Transporta el código exacto que
 * espera el contrato REST y el estado HTTP con el que el controlador debe
 * responder, de modo que la capa de presentación no reinvente la traducción de
 * cada regla.
 *
 * Los constructores son semánticos a propósito: el código de error no se escribe
 * a mano en el servicio de aplicación, se elige llamando al constructor que
 * describe el fallo. Así un error nuevo no puede nacer con un código huérfano.
 *
 * Códigos por estado HTTP:
 *   - `404`: `TROUBLESHOOTING_GUIDE_NOT_FOUND`.
 *   - `409`: `DUPLICATE_TROUBLESHOOTING_GUIDE`.
 *   - `422`: identidad inmutable, modelo, categoría, título, pasos, códigos de
 *     error, repuestos recomendados y causa raíz sugerida.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class TroubleshootingGuideValidationException extends DomainException
{
    // ── 404 ────────────────────────────────────────────────────────────────
    public const TROUBLESHOOTING_GUIDE_NOT_FOUND = 'TROUBLESHOOTING_GUIDE_NOT_FOUND';

    // ── 409 ────────────────────────────────────────────────────────────────
    public const DUPLICATE_TROUBLESHOOTING_GUIDE = 'DUPLICATE_TROUBLESHOOTING_GUIDE';

    // ── 422 · identidad y contenido ────────────────────────────────────────
    public const IMMUTABLE_GUIDE_IDENTITY = 'IMMUTABLE_GUIDE_IDENTITY';
    public const INVALID_GUIDE_MODEL = 'INVALID_GUIDE_MODEL';
    public const INVALID_GUIDE_CATEGORY = 'INVALID_GUIDE_CATEGORY';
    public const INVALID_GUIDE_TITLE = 'INVALID_GUIDE_TITLE';
    public const INVALID_GUIDE_STEPS = 'INVALID_GUIDE_STEPS';
    public const INVALID_GUIDE_ERROR_CODES = 'INVALID_GUIDE_ERROR_CODES';
    public const INVALID_GUIDE_SPARE_PARTS = 'INVALID_GUIDE_SPARE_PARTS';
    public const INVALID_GUIDE_ROOT_CAUSE = 'INVALID_GUIDE_ROOT_CAUSE';

    private string $errorCode;

    private int $httpStatusCode;

    private ?string $field;

    /** @var array<string, mixed> Datos de contexto útiles para el log, nunca secretos. */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $errorCode,
        string $message,
        int $httpStatusCode,
        ?string $field = null,
        array $context = []
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
        $this->field = $field;
        $this->context = $context;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 404
    // ─────────────────────────────────────────────────────────────────────
    public static function guideNotFound(?int $guideId = null): self
    {
        return new self(
            self::TROUBLESHOOTING_GUIDE_NOT_FOUND,
            $guideId !== null
                ? sprintf('No se encontró ninguna guía de diagnóstico con ID %d.', $guideId)
                : 'No se encontró la guía de diagnóstico indicada.',
            404,
            'id',
            ['guide_id' => $guideId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 409
    // ─────────────────────────────────────────────────────────────────────
    public static function duplicateGuide(string $machineModelKey, string $breakdownType): self
    {
        return new self(
            self::DUPLICATE_TROUBLESHOOTING_GUIDE,
            sprintf(
                'Ya existe una guía de diagnóstico (activa o inactiva) para el modelo «%s» y la categoría «%s». '
                . 'Edite o reactive la guía existente en lugar de crear una segunda.',
                $machineModelKey,
                $breakdownType
            ),
            409,
            'machine_model',
            ['machine_model_key' => $machineModelKey, 'breakdown_type' => $breakdownType]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · identidad
    // ─────────────────────────────────────────────────────────────────────
    /**
     * @param list<string> $fields
     */
    public static function immutableIdentity(array $fields): self
    {
        return new self(
            self::IMMUTABLE_GUIDE_IDENTITY,
            sprintf(
                'El modelo de máquina y la categoría de avería son la identidad inmutable de una guía; '
                . 'no se pueden modificar tras el alta (campos afectados: %s).',
                implode(', ', $fields)
            ),
            422,
            $fields[0] ?? null,
            ['fields' => $fields]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · contenido
    // ─────────────────────────────────────────────────────────────────────
    public static function invalidModel(int $length): self
    {
        return new self(
            self::INVALID_GUIDE_MODEL,
            sprintf(
                'El modelo de máquina debe contener entre %d y %d caracteres reales; se recibieron %d.',
                2,
                100,
                $length
            ),
            422,
            'machine_model',
            ['length' => $length]
        );
    }

    public static function invalidCategory(string $category): self
    {
        return new self(
            self::INVALID_GUIDE_CATEGORY,
            sprintf(
                'La categoría «%s» no pertenece al catálogo de averías '
                . '(TEMPERATURE_COLD, PAYMENT_SYSTEM, PRODUCT_JAM, ELECTRICAL_OFF, OTHER).',
                $category
            ),
            422,
            'breakdown_type',
            ['breakdown_type' => $category]
        );
    }

    public static function invalidTitle(int $length): self
    {
        return new self(
            self::INVALID_GUIDE_TITLE,
            sprintf(
                'El título de la guía debe contener entre %d y %d caracteres reales; se recibieron %d.',
                5,
                150,
                $length
            ),
            422,
            'title',
            ['length' => $length]
        );
    }

    public static function invalidSteps(string $reason): self
    {
        return new self(
            self::INVALID_GUIDE_STEPS,
            'Los pasos de la guía no cumplen el contrato: ' . $reason,
            422,
            'steps'
        );
    }

    public static function invalidErrorCodes(string $reason): self
    {
        return new self(
            self::INVALID_GUIDE_ERROR_CODES,
            'Los códigos de error de la guía no cumplen el contrato: ' . $reason,
            422,
            'error_codes'
        );
    }

    public static function invalidSpareParts(string $reason): self
    {
        return new self(
            self::INVALID_GUIDE_SPARE_PARTS,
            'Los repuestos recomendados no cumplen el contrato: ' . $reason,
            422,
            'recommended_spare_part_ids'
        );
    }

    public static function invalidRootCause(string $reason): self
    {
        return new self(
            self::INVALID_GUIDE_ROOT_CAUSE,
            'La causa raíz sugerida no cumple el contrato: ' . $reason,
            422,
            'suggested_root_cause_id'
        );
    }
}
