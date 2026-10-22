<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * IncidentServiceSheet
 *
 * Hoja de servicio de una resolución correctiva (Módulo 19): la conformidad
 * firmada por el responsable de la sede, o la atestación de su ausencia.
 *
 * Es **inmutable por diseño y por base**: no hay setters, la tabla no admite
 * `UPDATE` ni `DELETE` y una corrección exige una resolución nueva (Art. III.1,
 * RD-01). Por eso una reapertura añade otra hoja en lugar de reescribir la
 * anterior, y por eso el albarán de una visita pasada sigue imprimiéndose igual
 * años después (RD-11).
 *
 * `signaturePng` son **bytes crudos**, nunca base64: el base64 es un formato de
 * transporte y decodificarlo dos veces es una fuente clásica de firmas
 * corruptas. `signedDocument` y `resolutionAt` llegan hidratados al leer; al
 * escribir los calcula la base en la misma sentencia (RD-10), porque el
 * documento congelado se compone de filas que esa misma transacción acaba de
 * escribir.
 *
 * La coherencia de las dos ramas (firma XOR ausencia) la garantiza
 * `ServiceSheetValidator` antes de construir la entidad, y el `CHECK` de rama
 * del motor como última línea de defensa.
 */
final class IncidentServiceSheet
{
    /**
     * @param string|null $signaturePng Bytes crudos del PNG
     * @param array<string, mixed>|null $signedDocument Documento congelado al leer
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $incidentId,
        private readonly int $technicianId,
        private readonly ?string $signerName,
        private readonly ?string $signerDocument,
        private readonly ?string $signaturePng,
        private readonly ?string $absenceReason,
        private readonly ?string $resolutionAt = null,
        private readonly ?array $signedDocument = null,
        private readonly ?string $recordedAt = null,
    ) {
    }

    public function isSigned(): bool
    {
        return $this->signaturePng !== null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIncidentId(): int
    {
        return $this->incidentId;
    }

    public function getTechnicianId(): int
    {
        return $this->technicianId;
    }

    public function getSignerName(): ?string
    {
        return $this->signerName;
    }

    public function getSignerDocument(): ?string
    {
        return $this->signerDocument;
    }

    public function getSignaturePng(): ?string
    {
        return $this->signaturePng;
    }

    public function getAbsenceReason(): ?string
    {
        return $this->absenceReason;
    }

    public function getResolutionAt(): ?string
    {
        return $this->resolutionAt;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSignedDocument(): ?array
    {
        return $this->signedDocument;
    }

    public function getRecordedAt(): ?string
    {
        return $this->recordedAt;
    }

    /**
     * Metadatos de la hoja para la respuesta del cierre.
     *
     * Nunca transporta el trazo ni el documento congelado: el trazo es un dato
     * personal de un tercero y solo viaja en el albarán, que se lee con permiso
     * y deja auditoría (RD-18).
     *
     * @return array{signed: bool, signer_name: string|null, recorded_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'signed' => $this->isSigned(),
            'signer_name' => $this->signerName,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
