<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentDispatchLevel;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

/**
 * RestockerSatSynergyValidationException
 *
 * Única excepción de negocio de la sinergia reponedor ↔ SAT (Módulo 36).
 * Transporta el código exacto del contrato REST y el estado HTTP con el que la
 * capa de presentación debe responder, para que ningún controlador reinvente la
 * traducción de las reglas de `D2`, `D3`, `QA-CON-01`, `QA-LIM-03` ni
 * `QA-CONST-01`.
 *
 * Los constructores son semánticos: el código de error se elige llamando al
 * constructor que describe el fallo, nunca escribiendo el literal a mano.
 *
 * Códigos por estado HTTP:
 *   - `409`: `SANITARY_L1_RESOLUTION_BLOCKED` (máquina en cuarentena, Art. II)
 *     y `L1_PENDING_PARTS_FORBIDDEN` (repuestos en Nivel 1, `QA-LIM-03`).
 *   - `422`: categoría prohibida en Nivel 1 (Art. II), rol incoherente con el
 *     nivel (`QA-CON-01`), textos de resolución demasiado cortos (Art. V.1) y
 *     evidencia de escalado inválida (Art. V.1 y V.5).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class RestockerSatSynergyValidationException extends DomainException
{
    // ── 409 · guardas constitucionales y de repuestos ──────────────────────
    public const SANITARY_L1_RESOLUTION_BLOCKED = 'SANITARY_L1_RESOLUTION_BLOCKED';
    public const L1_PENDING_PARTS_FORBIDDEN = 'L1_PENDING_PARTS_FORBIDDEN';

    // ── 422 · elegibilidad, rol y evidencia ────────────────────────────────
    public const L1_DISPATCH_FORBIDDEN_FOR_CATEGORY = 'L1_DISPATCH_FORBIDDEN_FOR_CATEGORY';
    public const INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL = 'INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL';
    public const INVALID_LEVEL1_ISSUE_TYPE = 'INVALID_LEVEL1_ISSUE_TYPE';
    public const INVALID_URGENCY = 'INVALID_URGENCY';
    public const URGENCY_REASON_REQUIRED = 'URGENCY_REASON_REQUIRED';
    public const RESOLUTION_TEXT_TOO_SHORT = 'RESOLUTION_TEXT_TOO_SHORT';
    public const ESCALATION_INTERNAL_NOTE_REQUIRED = 'ESCALATION_INTERNAL_NOTE_REQUIRED';
    public const ESCALATION_PHOTO_REQUIRED = 'ESCALATION_PHOTO_REQUIRED';
    public const INVALID_EVIDENCE_IMAGE = 'INVALID_EVIDENCE_IMAGE';

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
    // 409 · guardas constitucionales y de repuestos
    // ─────────────────────────────────────────────────────────────────────

    public static function sanitaryL1ResolutionBlocked(string $sanitaryStatus, int $machineId): self
    {
        return new self(
            self::SANITARY_L1_RESOLUTION_BLOCKED,
            sprintf(
                'La máquina %d está en cuarentena sanitaria («%s») y no admite la resolución de un aviso de Nivel 1 (QA-CONST-01, Art. II).',
                $machineId,
                $sanitaryStatus
            ),
            409,
            'machine_id',
            ['machine_id' => $machineId, 'sanitary_status' => $sanitaryStatus]
        );
    }

    public static function l1PendingPartsForbidden(string $detail): self
    {
        return new self(
            self::L1_PENDING_PARTS_FORBIDDEN,
            'Un expediente de Nivel 1 no admite repuestos: ' . $detail . ' (QA-LIM-03).',
            409,
            'pending_parts_reason',
            ['detail' => $detail]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · elegibilidad, rol y evidencia
    // ─────────────────────────────────────────────────────────────────────

    public static function l1DispatchForbiddenForCategory(IncidentCategory $category): self
    {
        return new self(
            self::L1_DISPATCH_FORBIDDEN_FOR_CATEGORY,
            sprintf(
                'La categoría «%s» no puede despacharse en Nivel 1: exige Técnico SAT (Art. II).',
                $category->value
            ),
            422,
            'dispatch_level',
            ['category' => $category->value]
        );
    }

    public static function invalidAssigneeRoleForDispatchLevel(
        IncidentDispatchLevel $level,
        string $role,
        bool $isActive
    ): self {
        return new self(
            self::INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL,
            sprintf(
                'El nivel «%s» exige un usuario %s activo; se recibió «%s»%s (QA-CON-01).',
                $level->value,
                $level->requiresTechnician() ? 'TECHNICIAN' : 'RESTOCKER',
                $role === '' ? 'sin rol' : $role,
                $isActive ? '' : ' inactivo'
            ),
            422,
            'assigned_technician_id',
            ['dispatch_level' => $level->value, 'role' => $role, 'is_active' => $isActive]
        );
    }

    /**
     * El despacho manual a L1 exige un motivo compatible con la categoría del
     * expediente (H-36-07, QA-AMB-01).
     */
    public static function invalidLevel1IssueType(?string $issueType, IncidentCategory $category): self
    {
        $detail = $issueType === null || trim($issueType) === ''
            ? 'falta el motivo de Nivel 1'
            : sprintf('el motivo «%s» no es compatible con la categoría «%s»', $issueType, $category->value);

        return new self(
            self::INVALID_LEVEL1_ISSUE_TYPE,
            'No se puede despachar el expediente a Nivel 1: ' . $detail . ' (QA-AMB-01).',
            422,
            'level1_issue_type',
            ['level1_issue_type' => $issueType, 'category' => $category->value]
        );
    }

    public static function invalidUrgency(string $value): self
    {
        return new self(
            self::INVALID_URGENCY,
            sprintf(
                'Nivel de urgencia «%s» no reconocido. Valores aceptados: %s.',
                $value,
                implode(', ', UrgencyLevel::values())
            ),
            422,
            'urgency_override',
            ['urgency_override' => $value]
        );
    }

    public static function urgencyReasonRequired(): self
    {
        return new self(
            self::URGENCY_REASON_REQUIRED,
            'La reclasificación de urgencia exige un motivo justificado (urgency_override_reason obligatorio).',
            422,
            'urgency_override_reason'
        );
    }

    public static function resolutionTextTooShort(string $field, int $length, int $minimum): self
    {
        return new self(
            self::RESOLUTION_TEXT_TOO_SHORT,
            sprintf(
                'El campo «%s» debe contener al menos %d caracteres reales; se recibieron %d (Art. V.1).',
                $field,
                $minimum,
                $length
            ),
            422,
            $field,
            ['field' => $field, 'length' => $length, 'minimum' => $minimum]
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function escalationInternalNoteRequired(string $reason, array $context = []): self
    {
        return new self(
            self::ESCALATION_INTERNAL_NOTE_REQUIRED,
            'La nota interna de escalado no es válida: ' . $reason . ' (Art. V.1).',
            422,
            'workshop_internal_note',
            $context
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function escalationPhotoRequired(string $reason, array $context = []): self
    {
        return new self(
            self::ESCALATION_PHOTO_REQUIRED,
            'El escalado al SAT exige una fotografía de evidencia: ' . $reason . ' (Art. V.5).',
            422,
            'photo_path',
            $context
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function invalidEvidenceImage(string $reason, array $context = []): self
    {
        return new self(
            self::INVALID_EVIDENCE_IMAGE,
            'La fotografía de evidencia no supera la validación real: ' . $reason . ' (Art. V.5).',
            422,
            'photo_path',
            $context
        );
    }
}
