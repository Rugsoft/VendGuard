<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use VendGuard\Core\Domain\Enum\BatchExpiryStatus;
use VendGuard\Core\Domain\Enum\RestockVisitStatus;
use VendGuard\Core\Domain\Exception\MachineRestockVisitValidationException;
use VendGuard\Core\Domain\Model\DailyHygieneChecklist;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineRestockVisit;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;

/**
 * MachineRestockVisitValidator
 *
 * Validador puro del **Triple Cerrojo Sanitario** de la visita de reposición in
 * situ (Módulo 34; RF-02.1–RF-02.3, RF-03.1, RF-03.2; D1–D3; C1; QA 1; QA 4;
 * Art. II, Art. V.1 y Art. V.5).
 *
 * Reglas que muerde, en el orden en que las aplica el servicio:
 *
 *   1. **Cerrojo 1 · estado de la máquina (409).** Una máquina en cuarentena
 *      sanitaria, fuera de servicio o con una avería térmica (`TEMPERATURE_COLD`,
 *      etiqueta funcional `THERMAL_BREAKDOWN`) abierta no admite una sola unidad
 *      (`SANITARY_RESTOCK_BLOCKED`, Art. II). El
 *      perímetro territorial y el rol son autorización de servicio (`C2`) y no
 *      se mezclan aquí: este validador no conoce usuarios ni zonas.
 *   2. **Cerrojo 2 · lectura de cabina (422, QA 4).** Toda máquina refrigerada
 *      (`PERISHABLE_FOOD`, `COMBO`, `COLD_DRINKS`) exige lectura al abrir la
 *      puerta y el valor debe caber en `[-5,0, 35,0] °C`. Una visita `COMPLETED` o
 *      con unidades cargadas no puede superar el techo sanitario —4,0 °C en
 *      `PERISHABLE_FOOD`/`COMBO`, 8,0 °C en `COLD_DRINKS`—; por encima solo cabe
 *      el protocolo `ABORTED_THERMAL_BREACH`.
 *   3. **Cerrojo 3 · FEFO de carril (422, QA 1).** Un lote caducado exige retirada
 *      total aunque no se cargue nada; un lote que caduca en ≤ 48 h exige retirar
 *      todo el remanente antes de añadir producto. El movimiento debe caber en la
 *      capacidad del carril (`SLOT_CAPACITY_OR_WITHDRAWAL_INVALID`, C1).
 *   4. **Cerrojo 3 · FEFO de furgoneta (422, QA 1).** El lote elegido debe existir
 *      con saldo suficiente, no puede estar caducado y no puede adelantar a otro
 *      lote sano del mismo producto que vence antes y aún tiene existencias
 *      (`INSUFFICIENT_VAN_BATCH_STOCK`, `EXPIRED_VAN_BATCH_FORBIDDEN`,
 *      `FEFO_OLDER_VAN_BATCH_AVAILABLE`).
 *   5. **Checklist adaptativo (D3, EARS 03.1).** Una visita completada exige los
 *      cuatro puntos de cafetera o los dos de cabina según `MachineType`; el
 *      aborto térmico no exige limpieza, exige el protocolo sanitario.
 *   6. **Evidencia y rigor textual (Art. V.1 y V.5, EARS 03.2).** Con aborto o
 *      con unidades retiradas son obligatorias la fotografía y una nota interna de
 *      20 a 500 caracteres reales. La fotografía se acota a 5 MB y a JPG/JPEG,
 *      PNG o WebP, y cuando el pipeline entrega los bytes se verifica su **firma
 *      binaria real** (magic bytes): un `.jpg` que no empieza por `FF D8 FF` no es
 *      una fotografía.
 *
 * El validador no escribe ni consulta la base: entran entidades y cifras, y salen
 * los valores normalizados que el servicio persistirá, o una excepción con el
 * código exacto del contrato. Esa es la única traducción de reglas a códigos HTTP
 * del módulo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class MachineRestockVisitValidator
{
    /** Cota de la fotografía de evidencia (Art. V.5). */
    public const MAX_PHOTO_SIZE_BYTES = 5 * 1024 * 1024;

    /** Formatos admitidos, alineados con el cargador del servidor (Art. V.5). */
    public const ALLOWED_PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    public const ALLOWED_PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Rigor textual de las notas internas (Art. V.1, columna VARCHAR(500)). */
    public const MIN_JUSTIFICATION_LENGTH = 20;
    public const MAX_JUSTIFICATION_LENGTH = 500;

    /** Estatus sanitarios del motor que bloquean la reposición (RF-02.1). */
    public const BLOCKING_SANITARY_STATUSES = ['QUARANTINE', 'SANITARY_QUARANTINE'];

    /**
     * Cerrojo 1: ¿la máquina admite una visita de reposición? (RF-02.1, Art. II)
     *
     * @param string|null $sanitaryStatus Valor de `machines.sanitary_status` o el
     *                                    estado funcional `SANITARY_QUARANTINE`.
     * @param bool $isOutOfService Functional `OUT_OF_SERVICE`, derived from
     *                             `machines.is_blocked_no_access`.
     * @param bool $hasOpenThermalBreakdown Open thermal incident (functional label
     *                                      `THERMAL_BREAKDOWN`; persisted category
     *                                      `TEMPERATURE_COLD`).
     *
     * @throws MachineRestockVisitValidationException `409 SANITARY_RESTOCK_BLOCKED`
     */
    public static function assertMachineAvailable(
        ?string $sanitaryStatus,
        bool $isOutOfService,
        bool $hasOpenThermalBreakdown,
        int $machineId = 0
    ): void {
        $reasons = [];
        $normalizedSanitary = strtoupper(trim((string)$sanitaryStatus));

        if (in_array($normalizedSanitary, self::BLOCKING_SANITARY_STATUSES, true)) {
            $reasons[] = sprintf('está en cuarentena sanitaria (%s)', $normalizedSanitary);
        }

        if ($isOutOfService) {
            $reasons[] = 'está fuera de servicio';
        }

        if ($hasOpenThermalBreakdown) {
            $reasons[] = 'tiene una avería térmica abierta (TEMPERATURE_COLD)';
        }

        if ($reasons !== []) {
            throw MachineRestockVisitValidationException::sanitaryRestockBlocked($reasons, $machineId);
        }
    }

    /**
     * Cerrojo 2: normaliza y valida la lectura de cabina (QA 4, RF-02.2).
     *
     * @param int $addedUnitsTotal Unidades que la visita pretende cargar en total;
     *                             con una sola unidad el techo ya es obligatorio.
     *
     * @return float|null Lectura normalizada a la décima del motor.
     *
     * @throws MachineRestockVisitValidationException
     */
    public static function assertCabinTemperature(
        MachineType $machineType,
        ?float $cabinTemperature,
        RestockVisitStatus $status,
        int $addedUnitsTotal = 0
    ): ?float {
        $normalized = $cabinTemperature === null ? null : round($cabinTemperature, 1);

        if ($normalized !== null
            && ($normalized < MachineRestockVisit::MIN_CABIN_TEMP_CELSIUS
                || $normalized > MachineRestockVisit::MAX_CABIN_TEMP_CELSIUS)) {
            throw MachineRestockVisitValidationException::invalidCabinTemperature(
                $normalized,
                $machineType->value,
                sprintf(
                    'está fuera del rango físico plausible [%.1f, %.1f] °C',
                    MachineRestockVisit::MIN_CABIN_TEMP_CELSIUS,
                    MachineRestockVisit::MAX_CABIN_TEMP_CELSIUS
                )
            );
        }

        if ($machineType->requiresRefrigeration() && $normalized === null) {
            throw MachineRestockVisitValidationException::invalidCabinTemperature(
                null,
                $machineType->value,
                'una máquina refrigerada exige la lectura al abrir la puerta'
            );
        }

        $ceiling = self::thermalCeilingFor($machineType);
        if ($ceiling !== null
            && $normalized !== null
            && ($status->isCompleted() || $addedUnitsTotal > 0)
            && $normalized > $ceiling) {
            throw MachineRestockVisitValidationException::cabinTempExceedsSanitaryLimit(
                $normalized,
                $machineType->value,
                $ceiling
            );
        }

        return $normalized;
    }

    /**
     * Techo sanitario de cabina por tipología: 4,0 °C en perecedero y mixta,
     * 8,0 °C en bebidas frías y sin techo en las no refrigeradas (QA 4).
     */
    public static function thermalCeilingFor(MachineType $machineType): ?float
    {
        return match ($machineType) {
            MachineType::PERISHABLE_FOOD, MachineType::COMBO => 4.0,
            MachineType::COLD_DRINKS => 8.0,
            MachineType::HOT_DRINKS, MachineType::SNACKS => null,
        };
    }

    /**
     * Precinto preventivo obligatorio en un aborto térmico (RF-02.2, Art. II).
     *
     * @throws MachineRestockVisitValidationException
     */
    public static function assertPrecautionarySeal(RestockVisitStatus $status, bool $sealApplied): void
    {
        if ($status->requiresSanitaryProtocol() && !$sealApplied) {
            throw MachineRestockVisitValidationException::precautionarySealRequired();
        }
    }

    /**
     * Checklist diario adaptativo de una visita completada (EARS 03.1, D3).
     *
     * @throws MachineRestockVisitValidationException
     */
    public static function assertHygieneChecklist(
        RestockVisitStatus $status,
        MachineType $machineType,
        DailyHygieneChecklist $checklist
    ): void {
        if (!$status->isCompleted()) {
            return;
        }

        $missing = $checklist->missingItemsFor($machineType);
        if ($missing !== []) {
            throw MachineRestockVisitValidationException::incompleteDailyHygieneChecklist(
                $missing,
                $machineType->value
            );
        }
    }

    /**
     * Evidencia fotográfica y nota interna (EARS 03.2, QA 4, Art. V.1 y V.5).
     *
     * Con aborto térmico o con unidades retiradas ambas son obligatorias. La
     * fotografía se valida por extensión, tamaño y tipo real: si llegan los bytes
     * (el pipeline ya los leyó para subirlos), la firma binaria manda sobre
     * cualquier cabecera declarada.
     *
     * @param int $withdrawnUnitsTotal `SUM(qty_withdrawn_expired + qty_withdrawn_damaged)`.
     * @param string|null $photoBytes Primeros bytes del fichero; opcional, para
     *                                verificar los magic bytes sin tocar el disco.
     *
     * @return array{photo_evidence_url: string|null, internal_notes: string|null}
     *
     * @throws MachineRestockVisitValidationException
     */
    public static function assertEvidence(
        RestockVisitStatus $status,
        int $withdrawnUnitsTotal,
        ?string $photoEvidenceUrl,
        ?string $internalNotes,
        ?int $photoSizeBytes = null,
        ?string $photoMimeType = null,
        ?string $photoBytes = null
    ): array {
        $required = $status->requiresSanitaryProtocol() || $withdrawnUnitsTotal > 0;
        $requirement = $status->requiresSanitaryProtocol()
            ? 'la visita es un aborto por rotura de cadena de frío'
            : 'la visita retira unidades por caducidad o daño';

        $cleanPhoto = self::normalizeText((string)($photoEvidenceUrl ?? ''));
        if ($cleanPhoto === '') {
            if ($required) {
                throw MachineRestockVisitValidationException::photoEvidenceRequired($requirement);
            }
            $cleanPhoto = null;
        }

        if ($cleanPhoto !== null) {
            self::assertPhotoFormat($cleanPhoto, $photoSizeBytes, $photoMimeType, $photoBytes);
        }

        $cleanNotes = self::normalizeText((string)($internalNotes ?? ''));
        if ($cleanNotes === '') {
            $cleanNotes = null;
        }

        if ($required && ($cleanNotes === null
            || mb_strlen($cleanNotes, 'UTF-8') < self::MIN_JUSTIFICATION_LENGTH)) {
            throw MachineRestockVisitValidationException::invalidJustificationLength(
                'internal_notes',
                $cleanNotes === null ? 0 : mb_strlen($cleanNotes, 'UTF-8'),
                self::MIN_JUSTIFICATION_LENGTH,
                self::MAX_JUSTIFICATION_LENGTH
            );
        }

        if ($cleanNotes !== null
            && mb_strlen($cleanNotes, 'UTF-8') > self::MAX_JUSTIFICATION_LENGTH) {
            throw MachineRestockVisitValidationException::invalidJustificationLength(
                'internal_notes',
                mb_strlen($cleanNotes, 'UTF-8'),
                0,
                self::MAX_JUSTIFICATION_LENGTH
            );
        }

        return [
            'photo_evidence_url' => $cleanPhoto,
            'internal_notes' => $cleanNotes,
        ];
    }

    /**
     * Cerrojo 3 en el carril: cotas del C1 y retirada FEFO obligatoria (QA 1).
     *
     * `qty_before` es la existencia **real bloqueada** del carril, no la que
     * declare el cliente: el servicio la lee con `SELECT ... FOR UPDATE` y la pasa
     * aquí ya congelada (EARS 03.3).
     *
     * @return int Existencia resultante del carril (`qty_after`, D1).
     *
     * @throws MachineRestockVisitValidationException
     */
    public static function assertSlotMovement(
        MachinePlanogramSlot $slot,
        int $qtyWithdrawnExpired,
        int $qtyWithdrawnDamaged,
        int $qtyAdded,
        ?DateTimeImmutable $today = null
    ): int {
        $slotCode = $slot->getSlotCode();
        $qtyBefore = $slot->getCurrentEstimatedQty();

        if ($qtyWithdrawnExpired < 0 || $qtyWithdrawnDamaged < 0 || $qtyAdded < 0) {
            throw MachineRestockVisitValidationException::slotCapacityOrWithdrawalInvalid(
                $slotCode,
                'las cantidades de la línea no pueden ser negativas',
                $qtyBefore,
                $qtyBefore,
                $slot->getMaxCapacity()
            );
        }

        $withdrawn = $qtyWithdrawnExpired + $qtyWithdrawnDamaged;
        if ($withdrawn > $qtyBefore) {
            throw MachineRestockVisitValidationException::slotCapacityOrWithdrawalInvalid(
                $slotCode,
                'las retiradas superan la existencia previa del carril',
                $qtyBefore,
                $qtyBefore - $withdrawn + $qtyAdded,
                $slot->getMaxCapacity()
            );
        }

        $qtyAfter = $qtyBefore - $withdrawn + $qtyAdded;
        if ($qtyAfter < 0 || $qtyAfter > $slot->getMaxCapacity()) {
            throw MachineRestockVisitValidationException::slotCapacityOrWithdrawalInvalid(
                $slotCode,
                'la existencia resultante no cabe en la capacidad del carril',
                $qtyBefore,
                $qtyAfter,
                $slot->getMaxCapacity()
            );
        }

        $expiryStatus = $slot->slotExpiryStatus($today);

        if ($expiryStatus === BatchExpiryStatus::EXPIRED && $withdrawn < $qtyBefore) {
            throw MachineRestockVisitValidationException::fefoWithdrawalRequired(
                $slotCode,
                $qtyBefore > 0
                    ? 'el lote vigente está caducado y debe retirarse en su totalidad, aunque no se cargue producto'
                    : 'el carril declara un lote caducado',
                [
                    'qty_before' => $qtyBefore,
                    'withdrawn_units' => $withdrawn,
                    'expiration_date' => $slot->getCurrentExpirationDate()?->format('Y-m-d'),
                ]
            );
        }

        if ($expiryStatus === BatchExpiryStatus::EXPIRING_SOON && $qtyAdded > 0 && $withdrawn < $qtyBefore) {
            throw MachineRestockVisitValidationException::fefoWithdrawalRequired(
                $slotCode,
                'el lote vigente caduca en 48 horas o menos y se intenta cargar sin retirar todo el remanente',
                [
                    'qty_before' => $qtyBefore,
                    'withdrawn_units' => $withdrawn,
                    'qty_added' => $qtyAdded,
                    'expiration_date' => $slot->getCurrentExpirationDate()?->format('Y-m-d'),
                ]
            );
        }

        return $qtyAfter;
    }

    /**
     * Cerrojo 3 en la furgoneta: lote existente, sano, con saldo y FEFO (QA 1).
     *
     * Los lotes recibidos son los del reponedor para el producto del carril. El
     * consumo agregado de la visita por lote (`$consumedByBatch`) permite no
     * bloquear por un lote anterior que esta misma visita agota.
     *
     * @param list<RestockerVanProductStock> $lots
     * @param array<string, int> $consumedByBatch Lote => unidades ya cargadas en esta visita.
     *
     * @throws MachineRestockVisitValidationException
     * @throws InvalidArgumentException Si la cantidad pedida es negativa.
     */
    public static function assertVanBatchSelection(
        array $lots,
        string $loadedBatchCode,
        int $qtyAdded,
        array $consumedByBatch = [],
        ?DateTimeImmutable $today = null
    ): RestockerVanProductStock {
        if ($qtyAdded < 0) {
            throw new InvalidArgumentException('Las unidades cargadas no pueden ser negativas.');
        }

        $reference = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $cleanBatch = strtoupper(trim($loadedBatchCode));

        $selected = null;
        foreach ($lots as $lot) {
            if (!$lot instanceof RestockerVanProductStock) {
                throw new InvalidArgumentException(
                    'Cada lote de furgoneta debe ser un RestockerVanProductStock.'
                );
            }

            if ($lot->getBatchCode() === $cleanBatch) {
                $selected = $lot;
                break;
            }
        }

        if ($selected === null) {
            throw MachineRestockVisitValidationException::insufficientVanBatchStock(
                $cleanBatch,
                0,
                $qtyAdded
            );
        }

        if ($qtyAdded === 0) {
            return $selected;
        }

        if ($selected->getQuantityAvailable() < $qtyAdded) {
            throw MachineRestockVisitValidationException::insufficientVanBatchStock(
                $cleanBatch,
                $selected->getQuantityAvailable(),
                $qtyAdded
            );
        }

        $selectedExpiration = $selected->getExpirationDate()?->setTime(0, 0);
        if ($selectedExpiration !== null && $selectedExpiration <= $reference) {
            throw MachineRestockVisitValidationException::expiredVanBatchForbidden(
                $cleanBatch,
                $selectedExpiration->format('Y-m-d')
            );
        }

        if ($selectedExpiration === null) {
            return $selected;
        }

        foreach ($lots as $lot) {
            if (!$lot instanceof RestockerVanProductStock
                || $lot->getBatchCode() === $cleanBatch
                || $lot->getProductId() !== $selected->getProductId()) {
                continue;
            }

            $expiration = $lot->getExpirationDate()?->setTime(0, 0);
            if ($expiration === null || $expiration <= $reference || $expiration >= $selectedExpiration) {
                continue;
            }

            $consumed = (int)($consumedByBatch[$lot->getBatchCode()] ?? 0);
            if ($lot->getQuantityAvailable() - $consumed > 0) {
                throw MachineRestockVisitValidationException::fefoOlderVanBatchAvailable(
                    $lot->getBatchCode(),
                    $expiration->format('Y-m-d'),
                    $cleanBatch
                );
            }
        }

        return $selected;
    }

    /**
     * Detecta el tipo real de una imagen por su firma binaria (Art. V.5).
     *
     * Es la comprobación que no se puede falsificar renombrando el fichero: un
     * GIF con extensión `.jpg` no empieza por `FF D8 FF` y aquí no pasa.
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
     * Formato, tamaño y tipo real de la fotografía (Art. V.5).
     *
     * @throws MachineRestockVisitValidationException
     */
    private static function assertPhotoFormat(
        string $photoEvidenceUrl,
        ?int $photoSizeBytes,
        ?string $photoMimeType,
        ?string $photoBytes
    ): void {
        $path = parse_url($photoEvidenceUrl, PHP_URL_PATH);
        $extension = strtolower(pathinfo(is_string($path) ? $path : $photoEvidenceUrl, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_PHOTO_EXTENSIONS, true)) {
            throw MachineRestockVisitValidationException::photoEvidenceRequired(
                sprintf(
                    'solo se admiten las extensiones %s y se recibió «%s»',
                    implode(', ', self::ALLOWED_PHOTO_EXTENSIONS),
                    $extension === '' ? 'sin extensión' : $extension
                ),
                ['photo_evidence_url' => $photoEvidenceUrl]
            );
        }

        $size = $photoSizeBytes;
        if ($photoBytes !== null) {
            $size = strlen($photoBytes);
            $detected = self::detectImageMime($photoBytes);

            if ($detected === null) {
                throw MachineRestockVisitValidationException::photoEvidenceRequired(
                    'los bytes del fichero no declaran una firma de imagen JPG, PNG o WebP',
                    ['photo_evidence_url' => $photoEvidenceUrl]
                );
            }

            if ($photoMimeType !== null && strtolower(trim($photoMimeType)) !== $detected) {
                throw MachineRestockVisitValidationException::photoEvidenceRequired(
                    sprintf(
                        'el tipo declarado «%s» no coincide con la firma real «%s»',
                        $photoMimeType,
                        $detected
                    ),
                    ['photo_evidence_url' => $photoEvidenceUrl]
                );
            }

            $photoMimeType = $detected;
        }

        if ($size !== null && ($size <= 0 || $size > self::MAX_PHOTO_SIZE_BYTES)) {
            throw MachineRestockVisitValidationException::photoEvidenceRequired(
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
            throw MachineRestockVisitValidationException::photoEvidenceRequired(
                sprintf(
                    'el tipo real «%s» no está entre los admitidos (%s)',
                    $photoMimeType,
                    implode(', ', self::ALLOWED_PHOTO_MIME_TYPES)
                ),
                ['mime_type' => $photoMimeType]
            );
        }
    }
}
