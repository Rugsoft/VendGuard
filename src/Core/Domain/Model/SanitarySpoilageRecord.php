<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use JsonSerializable;

/**
 * SanitarySpoilageRecord
 *
 * Entidad inmutable de un Acta de Merma y Destrucción de Lotes por Cuarentena
 * Sanitaria (Módulo 29, RF-01 a RF-05). Es la prueba documental de qué producto
 * expuesto se retiró, cuántas unidades y lotes se destruyeron, con qué lecturas
 * térmicas y con qué evidencia gráfica.
 *
 * Invariantes que muerde en el borde de la entidad:
 *   1. Los hechos sanitarios y físicos se conservan tal como se emitieron: la
 *      entidad no expone setters y cualquier cambio devuelve una copia. La
 *      inmutabilidad definitiva la impone además la Tabla 40 (RNF-01).
 *   2. El coste estimado es dato económico interno de Coordinación. Por eso la
 *      serialización está segregada: `toPublicArray()` y `toOfficialActArray()`
 *      lo omiten siempre, y sólo `toCoordinatorArray()` lo incorpora (Art. V.4,
 *      D2, C4).
 *   3. El acta oficial A4 se construye como documento normalizado y sin ningún
 *      campo económico, ni siquiera cuando la abre un Coordinador (C4, QA 3).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class SanitarySpoilageRecord implements JsonSerializable
{
    public const QUARANTINE_ORIGIN_BIOLOGICAL_CLOCK = 'BIOLOGICAL_CLOCK_4H';
    public const QUARANTINE_ORIGIN_PREVENTIVE_NON_CONFORMITY = 'PREVENTIVE_NON_CONFORMITY';
    public const QUARANTINE_ORIGIN_MANUAL_SANITARY_LOCK = 'MANUAL_SANITARY_LOCK';

    public const DISPOSAL_METHOD_ON_SITE_ORGANIC_SEALED = 'ON_SITE_ORGANIC_SEALED';
    public const DISPOSAL_METHOD_RETURN_TO_CENTRAL_BIOHAZARD = 'RETURN_TO_CENTRAL_BIOHAZARD';
    public const DISPOSAL_METHOD_AUTHORIZED_WASTE_MANAGER = 'AUTHORIZED_WASTE_MANAGER';
    public const DISPOSAL_METHOD_NOT_APPLICABLE_EMPTY = 'NOT_APPLICABLE_EMPTY';

    /** @var array<string, string> Etiquetas oficiales en castellano del origen de la cuarentena. */
    public const QUARANTINE_ORIGIN_LABELS = [
        self::QUARANTINE_ORIGIN_BIOLOGICAL_CLOCK => 'Reloj biológico > 4 h (Avería)',
        self::QUARANTINE_ORIGIN_PREVENTIVE_NON_CONFORMITY => 'Inspección preventiva NO CONFORME',
        self::QUARANTINE_ORIGIN_MANUAL_SANITARY_LOCK => 'Bloqueo cautelar sanitario',
    ];

    /** @var array<string, string> Etiquetas oficiales en castellano del método de destrucción. */
    public const DISPOSAL_METHOD_LABELS = [
        self::DISPOSAL_METHOD_ON_SITE_ORGANIC_SEALED => 'Destrucción e inutilización en contenedor sellado in situ',
        self::DISPOSAL_METHOD_RETURN_TO_CENTRAL_BIOHAZARD => 'Retirada precintada a base central para baja sanitaria',
        self::DISPOSAL_METHOD_AUTHORIZED_WASTE_MANAGER => 'Entrega a gestor autorizado de residuos orgánicos',
        self::DISPOSAL_METHOD_NOT_APPLICABLE_EMPTY => 'No aplica (Máquina verificada sin stock afectado)',
    ];

    public const OFFICIAL_ACT_TITLE = 'ACTA OFICIAL DE DESTRUCCIÓN DE GÉNERO PERECEDERO';

    public const OFFICIAL_ACT_LEGAL_NOTICE = 'Documento sanitario oficial que acredita la retirada y destrucción '
        . 'del género perecedero expuesto, conforme al protocolo APPCC de VendGuard. No contiene información '
        . 'económica y se entrega a los servicios de inspección sanitaria y a la sede afectada.';

    private ?int $id;

    private string $recordCode;

    private int $machineId;

    private ?int $incidentId;

    private ?int $maintenanceOrderId;

    private int $technicianId;

    private int $registeredBy;

    private string $quarantineOrigin;

    private int $discardedUnitsCount;

    private bool $emptyMachineConfirmed;

    private string $affectedBatchCodes;

    private float $cabinTempAtRemoval;

    private float $restoredStabilizedTempC;

    private string $disposalMethod;

    private string $evidencePhotoUrl;

    private string $sanitaryNotes;

    private float $estimatedMerchandiseLossEur;

    private ?int $lossAdjustedBy;

    private ?string $lossAdjustmentReason;

    private string $certificateCode;

    private ?string $createdAt;

    private ?string $updatedAt;

    /** @var array<string, mixed> Metadatos hidratados de la máquina y su sede. */
    private array $machineData;

    /** @var array<string, mixed> Metadatos hidratados del técnico certificador. */
    private array $technicianData;

    private ?string $registeredByName;

    private ?string $lossAdjustedByName;

    /**
     * @param array<string, mixed> $machineData    Claves admitidas: `machine_code`, `machine_model`, `location_name`.
     * @param array<string, mixed> $technicianData Claves admitidas: `name`, `operator_code`.
     */
    public function __construct(
        ?int $id,
        string $recordCode,
        int $machineId,
        ?int $incidentId,
        ?int $maintenanceOrderId,
        int $technicianId,
        int $registeredBy,
        string $quarantineOrigin,
        int $discardedUnitsCount,
        bool $emptyMachineConfirmed,
        string $affectedBatchCodes,
        float $cabinTempAtRemoval,
        float $restoredStabilizedTempC,
        string $disposalMethod,
        string $evidencePhotoUrl,
        string $sanitaryNotes,
        float $estimatedMerchandiseLossEur,
        ?int $lossAdjustedBy,
        ?string $lossAdjustmentReason,
        string $certificateCode,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        array $machineData = [],
        array $technicianData = [],
        ?string $registeredByName = null,
        ?string $lossAdjustedByName = null
    ) {
        $this->id = $id;
        $this->recordCode = trim($recordCode);
        $this->machineId = $machineId;
        $this->incidentId = $incidentId;
        $this->maintenanceOrderId = $maintenanceOrderId;
        $this->technicianId = $technicianId;
        $this->registeredBy = $registeredBy;
        $this->quarantineOrigin = trim($quarantineOrigin);
        $this->discardedUnitsCount = $discardedUnitsCount;
        $this->emptyMachineConfirmed = $emptyMachineConfirmed;
        $this->affectedBatchCodes = trim($affectedBatchCodes);
        $this->cabinTempAtRemoval = round($cabinTempAtRemoval, 1);
        $this->restoredStabilizedTempC = round($restoredStabilizedTempC, 1);
        $this->disposalMethod = trim($disposalMethod);
        $this->evidencePhotoUrl = trim($evidencePhotoUrl);
        $this->sanitaryNotes = trim($sanitaryNotes);
        $this->estimatedMerchandiseLossEur = round($estimatedMerchandiseLossEur, 2);
        $this->lossAdjustedBy = $lossAdjustedBy;
        $this->lossAdjustmentReason = $lossAdjustmentReason !== null ? trim($lossAdjustmentReason) : null;
        $this->certificateCode = trim($certificateCode);
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->machineData = $machineData;
        $this->technicianData = $technicianData;
        $this->registeredByName = $registeredByName;
        $this->lossAdjustedByName = $lossAdjustedByName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecordCode(): string
    {
        return $this->recordCode;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getIncidentId(): ?int
    {
        return $this->incidentId;
    }

    public function getMaintenanceOrderId(): ?int
    {
        return $this->maintenanceOrderId;
    }

    public function getTechnicianId(): int
    {
        return $this->technicianId;
    }

    public function getRegisteredBy(): int
    {
        return $this->registeredBy;
    }

    public function getQuarantineOrigin(): string
    {
        return $this->quarantineOrigin;
    }

    public function getDiscardedUnitsCount(): int
    {
        return $this->discardedUnitsCount;
    }

    public function isEmptyMachineConfirmed(): bool
    {
        return $this->emptyMachineConfirmed;
    }

    public function getAffectedBatchCodes(): string
    {
        return $this->affectedBatchCodes;
    }

    public function getCabinTempAtRemoval(): float
    {
        return $this->cabinTempAtRemoval;
    }

    public function getRestoredStabilizedTempC(): float
    {
        return $this->restoredStabilizedTempC;
    }

    public function getDisposalMethod(): string
    {
        return $this->disposalMethod;
    }

    public function getEvidencePhotoUrl(): string
    {
        return $this->evidencePhotoUrl;
    }

    public function getSanitaryNotes(): string
    {
        return $this->sanitaryNotes;
    }

    public function getEstimatedMerchandiseLossEur(): float
    {
        return $this->estimatedMerchandiseLossEur;
    }

    public function getLossAdjustedBy(): ?int
    {
        return $this->lossAdjustedBy;
    }

    public function getLossAdjustmentReason(): ?string
    {
        return $this->lossAdjustmentReason;
    }

    public function getCertificateCode(): string
    {
        return $this->certificateCode;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /** @return array<string, mixed> */
    public function getMachineData(): array
    {
        return $this->machineData;
    }

    /** @return array<string, mixed> */
    public function getTechnicianData(): array
    {
        return $this->technicianData;
    }

    public function getRegisteredByName(): ?string
    {
        return $this->registeredByName;
    }

    public function getLossAdjustedByName(): ?string
    {
        return $this->lossAdjustedByName;
    }

    public function getQuarantineOriginLabel(): string
    {
        return self::QUARANTINE_ORIGIN_LABELS[$this->quarantineOrigin] ?? $this->quarantineOrigin;
    }

    public function getDisposalMethodLabel(): string
    {
        return self::DISPOSAL_METHOD_LABELS[$this->disposalMethod] ?? $this->disposalMethod;
    }

    /**
     * Devuelve una copia con la clave primaria asignada por el motor.
     */
    public function withId(int $id): self
    {
        $copy = clone $this;
        $copy->id = $id;

        return $copy;
    }

    /**
     * Serialización pública del acta: datos sanitarios, técnicos y fotográficos.
     *
     * Omite deliberadamente `estimated_merchandise_loss_eur`, `loss_adjusted_by`,
     * `loss_adjusted_by_name` y `loss_adjustment_reason` (Art. V.4, D2, C4).
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'record_code' => $this->recordCode,
            'machine_id' => $this->machineId,
            'machine_code' => $this->machineData['machine_code'] ?? null,
            'machine_model' => $this->machineData['machine_model'] ?? null,
            'location_name' => $this->machineData['location_name'] ?? null,
            'incident_id' => $this->incidentId,
            'maintenance_order_id' => $this->maintenanceOrderId,
            'technician_id' => $this->technicianId,
            'technician_name' => $this->technicianData['name'] ?? null,
            'registered_by' => $this->registeredBy,
            'registered_by_name' => $this->registeredByName,
            'quarantine_origin' => $this->quarantineOrigin,
            'quarantine_origin_label' => $this->getQuarantineOriginLabel(),
            'discarded_units_count' => $this->discardedUnitsCount,
            'empty_machine_confirmed' => $this->emptyMachineConfirmed,
            'affected_batch_codes' => $this->affectedBatchCodes,
            'cabin_temp_at_removal' => $this->cabinTempAtRemoval,
            'restored_stabilized_temp_c' => $this->restoredStabilizedTempC,
            'disposal_method' => $this->disposalMethod,
            'disposal_method_label' => $this->getDisposalMethodLabel(),
            'evidence_photo_url' => $this->evidencePhotoUrl,
            'sanitary_notes' => $this->sanitaryNotes,
            'certificate_code' => $this->certificateCode,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Serialización exclusiva de Coordinación: añade los campos financieros
     * internos al payload público (Art. V.4, D2, C4).
     *
     * @return array<string, mixed>
     */
    public function toCoordinatorArray(): array
    {
        return array_merge($this->toPublicArray(), [
            'estimated_merchandise_loss_eur' => $this->estimatedMerchandiseLossEur,
            'loss_adjusted_by' => $this->lossAdjustedBy,
            'loss_adjusted_by_name' => $this->lossAdjustedByName,
            'loss_adjustment_reason' => $this->lossAdjustmentReason,
        ]);
    }

    /**
     * Estructura normalizada del Acta Oficial A4 de Destrucción de Género
     * Perecedero. No incluye ningún dato económico (Art. V.4, C4, QA 3).
     *
     * @return array<string, mixed>
     */
    public function toOfficialActArray(): array
    {
        return [
            'document_title' => self::OFFICIAL_ACT_TITLE,
            'record_code' => $this->recordCode,
            'certificate_code' => $this->certificateCode,
            'issued_at' => $this->createdAt,
            'machine' => [
                'id' => $this->machineId,
                'code' => $this->machineData['machine_code'] ?? null,
                'model' => $this->machineData['machine_model'] ?? null,
                'location_name' => $this->machineData['location_name'] ?? null,
            ],
            'incident_id' => $this->incidentId,
            'maintenance_order_id' => $this->maintenanceOrderId,
            'technician' => [
                'id' => $this->technicianId,
                'name' => $this->technicianData['name'] ?? null,
                'operator_code' => $this->technicianData['operator_code'] ?? null,
            ],
            'registered_by' => [
                'id' => $this->registeredBy,
                'name' => $this->registeredByName,
            ],
            'quarantine_origin' => $this->quarantineOrigin,
            'quarantine_origin_label' => $this->getQuarantineOriginLabel(),
            'discarded_units_count' => $this->discardedUnitsCount,
            'empty_machine_confirmed' => $this->emptyMachineConfirmed,
            'affected_batch_codes' => $this->affectedBatchCodes,
            'cabin_temp_at_removal' => $this->cabinTempAtRemoval,
            'restored_stabilized_temp_c' => $this->restoredStabilizedTempC,
            'disposal_method' => $this->disposalMethod,
            'disposal_method_label' => $this->getDisposalMethodLabel(),
            'evidence_photo_url' => $this->evidencePhotoUrl,
            'sanitary_notes' => $this->sanitaryNotes,
            'legal_notice' => self::OFFICIAL_ACT_LEGAL_NOTICE,
        ];
    }

    /**
     * La serialización JSON por defecto es la pública: un `json_encode()` casual
     * nunca puede filtrar el coste interno (Art. V.4).
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toPublicArray();
    }
}
