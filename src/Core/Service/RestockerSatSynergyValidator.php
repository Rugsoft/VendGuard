<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use DateTimeImmutable;

use VendGuard\Core\Domain\Exception\RestockerSatSynergyValidationException;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentDispatchLevel;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\Level1IssueType;

/**
 * RestockerSatSynergyValidator
 *
 * Validador puro de la sinergia reponedor ↔ SAT (Módulo 36; RF-01.2, RF-02.1,
 * RF-03.1, RF-03.2; D2, D3; QA-AMB-01, QA-CON-01, QA-LIM-03, QA-CONST-01;
 * Art. II, Art. V.1 y Art. V.5).
 *
 * Reglas que muerde, en el orden en que las aplica el servicio:
 *
 *   1. **Elegibilidad de Nivel 1 (`D2`, `QA-AMB-01`).** Un aviso solo se
 *      pre-clasifica `L1_RESTOCKER` cuando la categoría no es térmica ni
 *      eléctrica, la máquina no está en cuarentena y no hay reclamación
 *      monetaria superior a 10,00 € ni devolución digital. Si concurre una
 *      exclusión, el nivel se fuerza a `L2_SAT` y el motivo rápido declarado se
 *      conserva como rastro de origen. La categoría canónica del motivo sale de
 *      `Level1IssueType::mappedCategory()`, nunca del cliente.
 *   2. **Guarda constitucional (Art. II, `QA-CONST-01`).** Un despacho
 *      explícito a `L1_RESTOCKER` sobre `TEMPERATURE_COLD` o `ELECTRICAL_OFF`
 *      se rechaza con `422 L1_DISPATCH_FORBIDDEN_FOR_CATEGORY`, y la resolución
 *      de un Nivel 1 sobre una máquina en cuarentena con
 *      `409 SANITARY_L1_RESOLUTION_BLOCKED`.
 *   3. **Coherencia rol/nivel (`QA-CON-01`).** `L1_RESTOCKER` exige un usuario
 *      `RESTOCKER` activo; `L2_SAT`, un `TECHNICIAN` activo. Cualquier otra
 *      pareja se rechaza con `422 INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL`.
 *   4. **Sin repuestos en Nivel 1 (`QA-LIM-03`).** Un `L1_RESTOCKER` no puede
 *      pasar a `PENDING_PARTS` ni declarar `pending_parts_reason`
 *      (`409 L1_PENDING_PARTS_FORBIDDEN`).
 *   5. **Rigor textual (Art. V.1).** Diagnóstico y acción de resolución exigen
 *      al menos 20 caracteres reales (`422 RESOLUTION_TEXT_TOO_SHORT`); la nota
 *      interna de escalado exige de 20 a 1000 caracteres reales con al menos
 *      tres bloques alfanuméricos (`422 ESCALATION_INTERNAL_NOTE_REQUIRED`).
 *   6. **Evidencia fotográfica (Art. V.5).** El escalado exige fotografía de
 *      hasta 5 MB en JPG, PNG o WebP, verificada por firma binaria real cuando
 *      llegan los bytes (`422 ESCALATION_PHOTO_REQUIRED` /
 *      `INVALID_EVIDENCE_IMAGE`).
 *
 * El validador no escribe ni consulta la base ni conoce usuarios completos:
 * entran valores y salen valores normalizados o una excepción con el código
 * exacto del contrato. Esa es la única traducción de reglas a códigos HTTP del
 * módulo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class RestockerSatSynergyValidator
{
    /** Rigor textual mínimo de los textos de resolución (Art. V.1). */
    public const MIN_TEXT_LENGTH = 20;

    /** Techo de la nota interna de escalado (Art. V.1). */
    public const MAX_INTERNAL_NOTE_LENGTH = 1000;

    /** Bloques alfanuméricos mínimos de la nota de escalado (Art. V.1). */
    public const MIN_ALPHANUMERIC_BLOCKS = 3;

    /** Cota de la fotografía de evidencia (Art. V.5). */
    public const MAX_PHOTO_SIZE_BYTES = 5 * 1024 * 1024;

    /** Formatos admitidos por el cargador del servidor (Art. V.5). */
    public const ALLOWED_PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    public const ALLOWED_PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Estatus sanitarios que bloquean la resolución de Nivel 1 (Art. II). */
    public const BLOCKING_SANITARY_STATUSES = ['QUARANTINE', 'SANITARY_QUARANTINE'];

    /** Categorías que jamás pueden quedar en Nivel 1 (Art. II). */
    public const FORBIDDEN_LEVEL1_CATEGORIES = [
        IncidentCategory::TEMPERATURE_COLD,
        IncidentCategory::ELECTRICAL_OFF,
    ];

    /** Importe retenido a partir del cual la reclamación exige SAT (D2). */
    public const MAX_LEVEL1_CLAIM_EUR = 10.0;

    /** Métodos de devolución digitales que exigen SAT (D2). */
    public const DIGITAL_REFUND_METHODS = ['BIZUM', 'TRANSFERENCIA_BANCARIA'];

    /**
     * ¿El aviso reúne las condiciones para despacharse en Nivel 1? (D2)
     *
     * Es el predicado que comparten el alta QR/Portal/Coordinación y el
     * auto-escalado: una sola definición evita que el borde y el servicio
     * decidan distinto.
     */
    public static function isLevel1Eligible(
        IncidentCategory $category,
        ?string $sanitaryStatus = null,
        ?float $retainedMoneyAmount = null,
        ?string $refundMethod = null
    ): bool {
        if (in_array($category, self::FORBIDDEN_LEVEL1_CATEGORIES, true)) {
            return false;
        }

        if (in_array(self::normalizeStatus($sanitaryStatus), self::BLOCKING_SANITARY_STATUSES, true)) {
            return false;
        }

        if ($retainedMoneyAmount !== null && $retainedMoneyAmount > self::MAX_LEVEL1_CLAIM_EUR) {
            return false;
        }

        $method = strtoupper(trim((string)$refundMethod));

        return !in_array($method, self::DIGITAL_REFUND_METHODS, true);
    }

    /**
     * Resuelve nivel, categoría y motivo de un alta con pre-clasificación (D2).
     *
     * @return array{
     *     dispatch_level: IncidentDispatchLevel,
     *     category: IncidentCategory,
     *     level1_issue_type: ?Level1IssueType,
     *     forced_to_sat: bool,
     *     forced_reason: ?string
     * }
     */
    public static function classifyCreation(
        ?Level1IssueType $level1IssueType,
        IncidentCategory $requestedCategory,
        ?string $sanitaryStatus = null,
        ?float $retainedMoneyAmount = null,
        ?string $refundMethod = null
    ): array {
        if ($level1IssueType === null) {
            return [
                'dispatch_level' => IncidentDispatchLevel::L2_SAT,
                'category' => $requestedCategory,
                'level1_issue_type' => null,
                'forced_to_sat' => false,
                'forced_reason' => null,
            ];
        }

        $mappedCategory = $level1IssueType->mappedCategory();
        $normalizedSanitary = self::normalizeStatus($sanitaryStatus);
        $method = strtoupper(trim((string)$refundMethod));

        $forcedReason = null;
        if (in_array($requestedCategory, self::FORBIDDEN_LEVEL1_CATEGORIES, true)) {
            $forcedReason = 'FORBIDDEN_CATEGORY';
        } elseif (in_array($normalizedSanitary, self::BLOCKING_SANITARY_STATUSES, true)) {
            $forcedReason = 'SANITARY_QUARANTINE';
        } elseif ($retainedMoneyAmount !== null && $retainedMoneyAmount > self::MAX_LEVEL1_CLAIM_EUR) {
            $forcedReason = 'MONETARY_CLAIM_OVER_LIMIT';
        } elseif (in_array($method, self::DIGITAL_REFUND_METHODS, true)) {
            $forcedReason = 'DIGITAL_REFUND_METHOD';
        }

        if ($forcedReason !== null) {
            return [
                'dispatch_level' => IncidentDispatchLevel::L2_SAT,
                'category' => $requestedCategory,
                'level1_issue_type' => $level1IssueType,
                'forced_to_sat' => true,
                'forced_reason' => $forcedReason,
            ];
        }

        return [
            'dispatch_level' => IncidentDispatchLevel::L1_RESTOCKER,
            'category' => $mappedCategory,
            'level1_issue_type' => $level1IssueType,
            'forced_to_sat' => false,
            'forced_reason' => null,
        ];
    }

    /**
     * ¿Una reapertura en garantía debe promover el expediente a L2_SAT?
     * (QA-LIM-02, Art. V.6). Solo aplica a un expediente resuelto en L1 dentro
     * de las 48 horas posteriores a su resolución.
     */
    public static function isReopenPromotionRequired(
        string $dispatchLevel,
        ?string $resolvedAt,
        DateTimeImmutable $reopenedAt,
        int $warrantyHours = 48
    ): bool {
        if (IncidentDispatchLevel::tryFrom(strtoupper(trim($dispatchLevel))) !== IncidentDispatchLevel::L1_RESTOCKER) {
            return false;
        }

        if ($resolvedAt === null || trim($resolvedAt) === '') {
            return false;
        }

        try {
            $resolved = new DateTimeImmutable($resolvedAt);
        } catch (\Exception) {
            return false;
        }

        $elapsed = $reopenedAt->getTimestamp() - $resolved->getTimestamp();

        return $elapsed >= 0 && $elapsed < $warrantyHours * 3600;
    }

    /**
     * Un despacho explícito a Nivel 1 sobre térmica o eléctrica se rechaza
     * (Art. II, QA-CONST-01).
     *
     * @throws RestockerSatSynergyValidationException `422 L1_DISPATCH_FORBIDDEN_FOR_CATEGORY`
     */
    public static function assertExplicitLevel1Allowed(IncidentCategory $category): void
    {
        if (in_array($category, self::FORBIDDEN_LEVEL1_CATEGORIES, true)) {
            throw RestockerSatSynergyValidationException::l1DispatchForbiddenForCategory($category);
        }
    }

    /**
     * Coherencia rol/nivel del custodio técnico (QA-CON-01).
     *
     * @throws RestockerSatSynergyValidationException `422 INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL`
     */
    public static function assertAssigneeRole(
        IncidentDispatchLevel $dispatchLevel,
        string $role,
        bool $isActive = true
    ): void {
        $normalizedRole = strtoupper(trim($role));
        $expectedRole = $dispatchLevel->requiresTechnician() ? 'TECHNICIAN' : 'RESTOCKER';

        if (!$isActive || $normalizedRole !== $expectedRole) {
            throw RestockerSatSynergyValidationException::invalidAssigneeRoleForDispatchLevel(
                $dispatchLevel,
                $normalizedRole,
                $isActive
            );
        }
    }

    /**
     * Resolución de Nivel 1 sobre máquina en cuarentena (QA-CONST-01, Art. II).
     *
     * @throws RestockerSatSynergyValidationException `409 SANITARY_L1_RESOLUTION_BLOCKED`
     */
    public static function assertLevel1ResolutionAllowed(
        ?string $sanitaryStatus,
        int $machineId = 0
    ): void {
        $normalized = self::normalizeStatus($sanitaryStatus);
        if (in_array($normalized, self::BLOCKING_SANITARY_STATUSES, true)) {
            throw RestockerSatSynergyValidationException::sanitaryL1ResolutionBlocked(
                $normalized,
                $machineId
            );
        }
    }

    /**
     * El Nivel 1 no admite repuestos ni el estado `PENDING_PARTS` (QA-LIM-03).
     *
     * @throws RestockerSatSynergyValidationException `409 L1_PENDING_PARTS_FORBIDDEN`
     */
    public static function assertNotPendingParts(
        IncidentDispatchLevel $dispatchLevel,
        ?string $targetStatus = null,
        ?string $pendingPartsReason = null
    ): void {
        if (!$dispatchLevel->isLevel1()) {
            return;
        }

        $normalizedStatus = strtoupper(trim((string)$targetStatus));

        if ($normalizedStatus === IncidentStatus::PENDING_PARTS->value) {
            throw RestockerSatSynergyValidationException::l1PendingPartsForbidden(
                'no puede transicionar a PENDING_PARTS'
            );
        }

        if ($pendingPartsReason !== null && trim($pendingPartsReason) !== '') {
            throw RestockerSatSynergyValidationException::l1PendingPartsForbidden(
                'no puede declarar pending_parts_reason'
            );
        }
    }

    /**
     * Diagnóstico y acción de resolución con 20 caracteres reales cada uno
     * (Art. V.1).
     *
     * @return array{diagnosis: string, action: string} Textos normalizados.
     *
     * @throws RestockerSatSynergyValidationException `422 RESOLUTION_TEXT_TOO_SHORT`
     */
    public static function assertResolutionTexts(?string $diagnosis, ?string $action): array
    {
        $cleanDiagnosis = self::normalizeText((string)$diagnosis);
        $cleanAction = self::normalizeText((string)$action);

        if (mb_strlen($cleanDiagnosis) < self::MIN_TEXT_LENGTH) {
            throw RestockerSatSynergyValidationException::resolutionTextTooShort(
                'resolution_diagnosis',
                mb_strlen($cleanDiagnosis),
                self::MIN_TEXT_LENGTH
            );
        }

        if (mb_strlen($cleanAction) < self::MIN_TEXT_LENGTH) {
            throw RestockerSatSynergyValidationException::resolutionTextTooShort(
                'resolution_action',
                mb_strlen($cleanAction),
                self::MIN_TEXT_LENGTH
            );
        }

        return ['diagnosis' => $cleanDiagnosis, 'action' => $cleanAction];
    }

    /**
     * Nota interna de escalado: 20 a 1000 caracteres reales y tres bloques
     * alfanuméricos (Art. V.1).
     *
     * @return string Nota normalizada.
     *
     * @throws RestockerSatSynergyValidationException `422 ESCALATION_INTERNAL_NOTE_REQUIRED`
     */
    public static function assertEscalationInternalNote(?string $note): string
    {
        $clean = self::normalizeText((string)$note);
        $length = mb_strlen($clean);

        if ($length < self::MIN_TEXT_LENGTH) {
            throw RestockerSatSynergyValidationException::escalationInternalNoteRequired(
                sprintf(
                    'debe contener al menos %d caracteres reales; se recibieron %d',
                    self::MIN_TEXT_LENGTH,
                    $length
                ),
                ['length' => $length, 'minimum' => self::MIN_TEXT_LENGTH]
            );
        }

        if ($length > self::MAX_INTERNAL_NOTE_LENGTH) {
            throw RestockerSatSynergyValidationException::escalationInternalNoteRequired(
                sprintf(
                    'no puede superar %d caracteres reales; se recibieron %d',
                    self::MAX_INTERNAL_NOTE_LENGTH,
                    $length
                ),
                ['length' => $length, 'maximum' => self::MAX_INTERNAL_NOTE_LENGTH]
            );
        }

        $blocks = self::countAlphanumericBlocks($clean);
        if ($blocks < self::MIN_ALPHANUMERIC_BLOCKS) {
            throw RestockerSatSynergyValidationException::escalationInternalNoteRequired(
                sprintf(
                    'debe contener al menos %d bloques alfanuméricos; se recibieron %d',
                    self::MIN_ALPHANUMERIC_BLOCKS,
                    $blocks
                ),
                ['blocks' => $blocks, 'minimum_blocks' => self::MIN_ALPHANUMERIC_BLOCKS]
            );
        }

        return $clean;
    }

    /**
     * Fotografía de evidencia obligatoria del escalado (Art. V.5).
     *
     * Cuando llegan los bytes se comprueba la firma real: renombrar un GIF a
     * `.jpg` no lo convierte en una fotografía.
     *
     * @param string|null $photoBytes Contenido binario cuando el pipeline lo entrega.
     *
     * @throws RestockerSatSynergyValidationException
     */
    public static function assertEscalationPhoto(
        ?string $photoPath,
        ?int $photoSizeBytes = null,
        ?string $photoMimeType = null,
        ?string $photoBytes = null
    ): void {
        $cleanPath = trim((string)$photoPath);

        if ($cleanPath === '' && ($photoBytes === null || $photoBytes === '')) {
            throw RestockerSatSynergyValidationException::escalationPhotoRequired(
                'falta photo_path y no se recibió ningún archivo'
            );
        }

        if ($cleanPath !== '') {
            $path = parse_url($cleanPath, PHP_URL_PATH);
            $extension = strtolower(pathinfo(is_string($path) ? $path : $cleanPath, PATHINFO_EXTENSION));

            if (!in_array($extension, self::ALLOWED_PHOTO_EXTENSIONS, true)) {
                throw RestockerSatSynergyValidationException::invalidEvidenceImage(
                    sprintf(
                        'solo se admiten las extensiones %s y se recibió «%s»',
                        implode(', ', self::ALLOWED_PHOTO_EXTENSIONS),
                        $extension === '' ? 'sin extensión' : $extension
                    ),
                    ['photo_path' => $cleanPath]
                );
            }
        }

        $size = $photoSizeBytes;

        if ($photoBytes !== null && $photoBytes !== '') {
            $size = strlen($photoBytes);
            $detected = self::detectImageMime($photoBytes);

            if ($detected === null) {
                throw RestockerSatSynergyValidationException::invalidEvidenceImage(
                    'los bytes no declaran una firma de imagen JPG, PNG o WebP',
                    ['photo_path' => $cleanPath]
                );
            }

            if ($photoMimeType !== null
                && strtolower(trim($photoMimeType)) !== $detected) {
                throw RestockerSatSynergyValidationException::invalidEvidenceImage(
                    sprintf(
                        'el tipo declarado «%s» no coincide con la firma real «%s»',
                        $photoMimeType,
                        $detected
                    ),
                    ['photo_path' => $cleanPath]
                );
            }

            $photoMimeType = $detected;
        }

        if ($size !== null && ($size <= 0 || $size > self::MAX_PHOTO_SIZE_BYTES)) {
            throw RestockerSatSynergyValidationException::invalidEvidenceImage(
                sprintf(
                    'la fotografía debe pesar entre 1 byte y %d MB; se recibieron %d bytes',
                    intdiv(self::MAX_PHOTO_SIZE_BYTES, 1024 * 1024),
                    $size
                ),
                ['size_bytes' => $size]
            );
        }

        if ($photoMimeType !== null
            && !in_array(strtolower(trim($photoMimeType)), self::ALLOWED_PHOTO_MIME_TYPES, true)) {
            throw RestockerSatSynergyValidationException::invalidEvidenceImage(
                sprintf(
                    'el tipo real «%s» no está entre los admitidos (%s)',
                    $photoMimeType,
                    implode(', ', self::ALLOWED_PHOTO_MIME_TYPES)
                ),
                ['mime_type' => $photoMimeType]
            );
        }
    }

    /**
     * Detecta el tipo real de una imagen por su firma binaria (Art. V.5).
     */
    public static function detectImageMime(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (strlen($bytes) >= 12
            && substr($bytes, 0, 4) === 'RIFF'
            && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    /**
     * Recorta extremos y colapsa espacios repetidos sin tocar los acentos.
     */
    public static function normalizeText(string $value): string
    {
        $trimmed = trim($value);
        $collapsed = preg_replace('/\s+/u', ' ', $trimmed);

        return $collapsed ?? $trimmed;
    }

    /**
     * Cuenta los bloques con al menos una letra o un dígito (Art. V.1).
     */
    public static function countAlphanumericBlocks(string $text): int
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $blocks);

        return count($blocks[0]);
    }

    private static function normalizeStatus(?string $status): string
    {
        return strtoupper(trim((string)$status));
    }
}
