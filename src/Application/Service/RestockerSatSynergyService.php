<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Exception\RestockerSatSynergyValidationException;
use VendGuard\Core\Domain\Repository\RestockerSatSynergyGatewayInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentDispatchLevel;
use VendGuard\Core\Domain\ValueObject\Level1IssueType;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Service\RestockerSatSynergyValidator;
use VendGuard\Infrastructure\Storage\LocalFileUploader;

/**
 * RestockerSatSynergyService
 *
 * Caso de uso de la sinergia reponedor ↔ SAT (Módulo 36; Fase 4, T-RSS-06).
 *
 * Orquesta el gateway transaccional con las reglas de negocio que no dependen
 * del motor: despacho y conmutación L1↔L2 con coherencia de rol y categoría,
 * inicio/resolución in situ, escalado «⚡ Escalar Avería al SAT» con sugerencia
 * de técnico y aviso en el outbox, Coincidencia en Sede, relevo de turno y
 * volcado de conflicto offline (RF-02.1, RF-03.1, RF-03.2, RF-04.1, RF-04.2,
 * RF-05.1, D1–D4, C1–C2).
 *
 * El servicio no compone SQL ni decide el estado HTTP final: valida, delega y
 * enriquece. Las dependencias opcionales son callables para que la capa
 * hermética pueda certificarlo sin PostgreSQL:
 *
 *  - `technicianResolver(int $machineId, array $payload): ?array` devuelve la
 *    foto del técnico sugerido cuando el cliente no fuerza uno.
 *  - `notifier(string $event, array $payload): void` encola el aviso en el
 *    outbox sin acoplar el caso de uso a su implementación concreta.
 *
 * Dogma Vanilla: PHP 8.2+ puro, `final`, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockerSatSynergyService
{
    public const EVENT_ESCALATED_TO_SAT = 'RESTOCKER_ESCALATION_TO_SAT';

    /** @var callable(int, array<string, mixed>): (array<string, mixed>|null)|null */
    private $technicianResolver;

    /** @var callable(string, array<string, mixed>): void|null */
    private $notifier;

    private LocalFileUploader $uploader;

    public function __construct(
        private readonly RestockerSatSynergyGatewayInterface $gateway,
        ?callable $technicianResolver = null,
        ?callable $notifier = null,
        ?LocalFileUploader $uploader = null
    ) {
        $this->technicianResolver = $technicianResolver;
        $this->notifier = $notifier;
        $this->uploader = $uploader ?? new LocalFileUploader();
    }

    /**
     * Despacha o conmuta el nivel operativo del expediente (RF-02.1).
     *
     * @param array<string, mixed> $incident
     * @param array<string, mixed> $assignee
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function dispatchLevel(
        array $incident,
        string $targetLevel,
        array $assignee,
        array $actor,
        ?string $urgencyOverride = null,
        ?string $urgencyReason = null
    ): array
    {
        $level = IncidentDispatchLevel::tryFrom(strtoupper(trim($targetLevel)));
        if ($level === null) {
            throw new InvalidArgumentException('El nivel de despacho solicitado no existe.');
        }

        $category = IncidentCategory::tryFrom((string)($incident['category'] ?? ''));
        if ($category === null) {
            throw new InvalidArgumentException('El expediente no declara una categoría válida.');
        }

        if ($level->isLevel1()) {
            RestockerSatSynergyValidator::assertExplicitLevel1Allowed($category);
        }

        RestockerSatSynergyValidator::assertAssigneeRole(
            $level,
            (string)($assignee['role'] ?? ''),
            (bool)($assignee['is_active'] ?? true)
        );
        RestockerSatSynergyValidator::assertNotPendingParts($level);

        if ($level->isLevel1()) {
            // H-36-07: un despacho manual a L1 no puede dejar el expediente sin
            // motivo. La taxonomía se valida antes de tocar la custodia y debe
            // ser la pareja determinista de la categoría (QA-AMB-01).
            $rawIssueType = strtoupper(trim((string)($incident['level1_issue_type'] ?? '')));
            $level1IssueType = $rawIssueType !== '' ? Level1IssueType::tryFrom($rawIssueType) : null;
            if ($level1IssueType === null || !$level1IssueType->isCompatibleWith($category)) {
                throw RestockerSatSynergyValidationException::invalidLevel1IssueType(
                    $rawIssueType !== '' ? $rawIssueType : null,
                    $category
                );
            }

            $incident['level1_issue_type'] = $level1IssueType->value;
        } else {
            // En L2 el motivo es rastro histórico, no una decisión del despacho:
            // se conserva el que ya tuviera el expediente y se ignora el cuerpo.
            unset($incident['level1_issue_type']);
        }

        // La urgencia elegida por Coordinación no puede viajar sin su rastro de
        // auditoría. La validación vive aquí para que cualquier cliente HTTP
        // reciba el mismo 422 antes de tocar la custodia (EARS 5.3).
        $cleanUrgency = $urgencyOverride !== null ? strtoupper(trim($urgencyOverride)) : '';
        $cleanReason = $urgencyReason !== null ? trim($urgencyReason) : '';
        if ($cleanUrgency !== '') {
            if (!UrgencyLevel::isValid($cleanUrgency)) {
                throw RestockerSatSynergyValidationException::invalidUrgency($cleanUrgency);
            }
            if ($cleanReason === '') {
                throw RestockerSatSynergyValidationException::urgencyReasonRequired();
            }
        } else {
            $cleanUrgency = null;
            $cleanReason = null;
        }

        return $this->gateway->assignDispatchLevel(
            $incident,
            $level->value,
            $assignee,
            $actor,
            $cleanUrgency,
            $cleanReason
        );
    }

    /**
     * Inicio del aviso L1 asignado al reponedor (EARS 03.1, C2).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>
     */
    public function startLevel1(int $incidentId, array $actor, ?array $mutation = null): array
    {
        if ($incidentId <= 0) {
            throw new InvalidArgumentException('El expediente del inicio L1 no es válido.');
        }

        return $this->gateway->startLevel1($incidentId, $actor, $mutation);
    }

    /**
     * Resolución in situ del aviso L1 (EARS 03.1, QA-CONST-01).
     *
     * @param array<string, mixed> $incident
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $resolution
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>
     */
    public function resolveLevel1(
        array $incident,
        array $actor,
        array $resolution,
        ?array $mutation = null
    ): array {
        $incidentId = (int)($incident['id'] ?? 0);
        if ($incidentId <= 0) {
            throw new InvalidArgumentException('El expediente de la resolución L1 no es válido.');
        }

        RestockerSatSynergyValidator::assertLevel1ResolutionAllowed(
            isset($incident['sanitary_status']) ? (string)$incident['sanitary_status'] : null,
            (int)($incident['machine_id'] ?? 0)
        );
        RestockerSatSynergyValidator::assertNotPendingParts(IncidentDispatchLevel::L1_RESTOCKER);

        $texts = RestockerSatSynergyValidator::assertResolutionTexts(
            isset($resolution['diagnosis']) ? (string)$resolution['diagnosis'] : null,
            isset($resolution['action']) ? (string)$resolution['action'] : null
        );
        $resolution['diagnosis'] = $texts['diagnosis'];
        $resolution['action'] = $texts['action'];
        $resolution['resolution_diagnosis'] = $texts['diagnosis'];
        $resolution['resolution_action'] = $texts['action'];

        return $this->gateway->resolveLevel1($incidentId, $actor, $resolution, $mutation);
    }

    /**
     * Escalado «⚡ Escalar Avería al SAT» (RF-03.2, QA-CONST-02).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>
     */
    public function escalateToSat(
        int $machineId,
        array $actor,
        array $payload,
        ?array $mutation = null
    ): array {
        if ($machineId <= 0) {
            throw new InvalidArgumentException('La máquina del escalado no es válida.');
        }

        // El contrato HTTP habla de `workshop_internal_note`; la clave corta se
        // conserva para los llamadores internos y ambas viajan al gateway.
        $rawNote = $payload['workshop_internal_note'] ?? $payload['internal_note'] ?? null;
        $validatedNote = RestockerSatSynergyValidator::assertEscalationInternalNote(
            $rawNote !== null ? (string)$rawNote : null
        );
        $payload['workshop_internal_note'] = $validatedNote;
        $payload['internal_note'] = $validatedNote;

        // PR22-05: un photo_path no es una prueba por sí mismo. Se resuelve en el
        // almacenamiento local y se exige que exista, respete el tope de 5 MB y
        // tenga una firma real coherente con su extensión (Art. V.5).
        $cleanPhotoPath = isset($payload['photo_path']) ? trim((string)$payload['photo_path']) : '';
        if ($cleanPhotoPath !== '') {
            try {
                $payload['photo_path'] = $this->uploader->validateStoredImage($cleanPhotoPath);
            } catch (InvalidUploadException $exception) {
                throw RestockerSatSynergyValidationException::invalidEvidenceImage(
                    $exception->getMessage(),
                    ['photo_path' => $cleanPhotoPath]
                );
            }
        }

        RestockerSatSynergyValidator::assertEscalationPhoto(
            isset($payload['photo_path']) ? (string)$payload['photo_path'] : null,
            isset($payload['photo_size_bytes']) ? (int)$payload['photo_size_bytes'] : null,
            isset($payload['photo_mime_type']) ? (string)$payload['photo_mime_type'] : null
        );

        if ((int)($payload['assign_technician_id'] ?? 0) <= 0 && $this->technicianResolver !== null) {
            $technician = ($this->technicianResolver)($machineId, $payload);
            if (is_array($technician) && (int)($technician['id'] ?? 0) > 0) {
                $payload['assign_technician_id'] = (int)$technician['id'];
                $payload['suggested_technician'] = $technician;
            }
        }

        $result = $this->gateway->escalateToSat($machineId, $actor, $payload, $mutation);
        // C2: un reenvío idempotente ya encoló su aviso la primera vez; repetirlo
        // duplicaría la notificación al técnico y al reponedor.
        if (($result['ok'] ?? false) === true
            && ($result['idempotent_replay'] ?? false) !== true
            && $this->notifier !== null) {
            ($this->notifier)(self::EVENT_ESCALATED_TO_SAT, $result);
        }

        return $result;
    }

    /**
     * ¿La absorción QR de un ticket L1 debe auto-escalarlo? (QA-LIM-01)
     */
    public static function shouldAutoEscalateQrMerge(
        IncidentDispatchLevel $currentLevel,
        IncidentCategory $incomingCategory,
        ?float $retainedMoneyAmount = null,
        ?string $refundMethod = null
    ): bool {
        if (!$currentLevel->isLevel1()) {
            return false;
        }

        if (in_array($incomingCategory, RestockerSatSynergyValidator::FORBIDDEN_LEVEL1_CATEGORIES, true)) {
            return true;
        }

        if ($retainedMoneyAmount !== null && $retainedMoneyAmount > RestockerSatSynergyValidator::MAX_LEVEL1_CLAIM_EUR) {
            return true;
        }

        return in_array(strtoupper(trim((string)$refundMethod)), RestockerSatSynergyValidator::DIGITAL_REFUND_METHODS, true);
    }

    /**
     * Auto-escalado inmediato a L2_SAT en absorción QR grave (QA-LIM-01).
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function autoEscalateQrMerge(
        int $incidentId,
        IncidentDispatchLevel $currentLevel,
        IncidentCategory $incomingCategory,
        ?float $retainedMoneyAmount,
        ?string $refundMethod,
        array $actor,
        ?int $assignTechnicianId = null
    ): array {
        if (!self::shouldAutoEscalateQrMerge($currentLevel, $incomingCategory, $retainedMoneyAmount, $refundMethod)) {
            return ['ok' => true, 'promoted' => false, 'dispatch_level' => $currentLevel->value];
        }

        return $this->gateway->promoteToSatOnQrMerge(
            $incidentId,
            $incomingCategory->value,
            $assignTechnicianId,
            $actor
        );
    }

    /**
     * Promoción a L2_SAT en reapertura de garantía < 48 h (QA-LIM-02).
     */
    public static function shouldPromoteOnReopen(array $incident, ?DateTimeImmutable $reopenedAt = null): bool
    {
        return RestockerSatSynergyValidator::isReopenPromotionRequired(
            (string)($incident['dispatch_level'] ?? ''),
            isset($incident['resolved_at']) ? (string)$incident['resolved_at'] : null,
            $reopenedAt ?? new \DateTimeImmutable('now')
        );
    }

    /**
     * Reponedores recomendados con su carga L1 (RF-02.1).
     *
     * @return array<string, mixed>
     */
    public function suggestRestockers(int $incidentId): array
    {
        return $this->gateway->suggestRestockers($incidentId);
    }

    /**
     * Coincidencia en Sede según el rol consultante (D4, Art. V.4).
     *
     * @param list<int> $locationIds
     * @return array<string, mixed>
     */
    public function siteCoincidence(string $role, int $userId, array $locationIds): array
    {
        $normalized = strtoupper(trim($role));
        if ($normalized === 'TECHNICIAN') {
            return $this->gateway->technicianCoincidences($userId, $locationIds);
        }
        if ($normalized === 'RESTOCKER') {
            return $this->gateway->restockerCoincidences($userId, $locationIds);
        }

        throw new InvalidArgumentException('La Coincidencia en Sede solo está disponible para reponedores y técnicos.');
    }

    /**
     * Relevo automático de un L1 asignado (C1).
     *
     * @return array<string, mixed>
     */
    public function handoverLevel1(int $restockerId, string $cause, string $reason): array
    {
        if ($restockerId <= 0) {
            throw new InvalidArgumentException('El reponedor del relevo no es válido.');
        }

        return $this->gateway->handoverLevel1($restockerId, strtoupper(trim($cause)), trim($reason));
    }

    /**
     * Volcado de conflicto de una mutación offline rechazada (RF-05.1.2, Art. III.3).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $mutation
     * @param array<string, mixed> $conflict
     * @return array<string, mixed>
     */
    public function dumpOfflineConflict(array $actor, array $mutation, array $conflict): array
    {
        if (trim((string)($mutation['client_mutation_id'] ?? '')) === '') {
            throw new InvalidArgumentException('El volcado offline exige client_mutation_id.');
        }

        return $this->gateway->recordConflictDump($actor, $mutation, $conflict);
    }
}
