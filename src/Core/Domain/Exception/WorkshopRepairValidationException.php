<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;
use VendGuard\Core\Domain\ValueObject\WorkshopRepairStatus;

/**
 * WorkshopRepairValidationException
 *
 * Única excepción de negocio del Módulo 27. Transporta el código exacto que
 * espera el contrato REST y el estado HTTP con el que el controlador debe
 * responder, de modo que la capa de presentación no reinvente la traducción de
 * cada regla.
 *
 * Los constructores son semánticos a propósito: el código de error no se escribe
 * a mano en el servicio de aplicación, se elige llamando al constructor que
 * describe el fallo. Así un error nuevo no puede nacer con un código huérfano.
 *
 * Códigos por estado HTTP:
 *   - `404`: `WORKSHOP_ITEM_NOT_FOUND`, `TECHNICIAN_NOT_FOUND`.
 *   - `409`: `INVALID_WORKSHOP_STATUS_TRANSITION`, `WORKSHOP_ITEM_ALREADY_RESOLVED`.
 *   - `422`: integridad de recepción, dictamen, destino y coste.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class WorkshopRepairValidationException extends DomainException
{
    // ── 404 ────────────────────────────────────────────────────────────────
    public const WORKSHOP_ITEM_NOT_FOUND = 'WORKSHOP_ITEM_NOT_FOUND';
    public const TECHNICIAN_NOT_FOUND = 'TECHNICIAN_NOT_FOUND';

    // ── 409 ────────────────────────────────────────────────────────────────
    public const INVALID_WORKSHOP_STATUS_TRANSITION = 'INVALID_WORKSHOP_STATUS_TRANSITION';
    public const WORKSHOP_ITEM_ALREADY_RESOLVED = 'WORKSHOP_ITEM_ALREADY_RESOLVED';

    // ── 422 · recepción ────────────────────────────────────────────────────
    public const RECEPTION_NOTES_TOO_LONG = 'RECEPTION_NOTES_TOO_LONG';

    // ── 422 · dictamen ─────────────────────────────────────────────────────
    public const INVALID_RESOLUTION_OUTCOME = 'INVALID_RESOLUTION_OUTCOME';
    public const BENCH_DIAGNOSIS_TOO_SHORT = 'BENCH_DIAGNOSIS_TOO_SHORT';
    public const BENCH_DIAGNOSIS_TOO_LONG = 'BENCH_DIAGNOSIS_TOO_LONG';
    public const UNRECEIVED_PART_CANNOT_BE_REFURBISHED = 'UNRECEIVED_PART_CANNOT_BE_REFURBISHED';

    // ── 422 · reintegro ────────────────────────────────────────────────────
    public const INVALID_RESTOCK_DESTINATION = 'INVALID_RESTOCK_DESTINATION';
    public const OUT_OF_CATALOG_VAN_RESTOCK_FORBIDDEN = 'OUT_OF_CATALOG_VAN_RESTOCK_FORBIDDEN';
    public const INACTIVE_SPARE_PART_VAN_RESTOCK_FORBIDDEN = 'INACTIVE_SPARE_PART_VAN_RESTOCK_FORBIDDEN';
    public const RESTOCK_TECHNICIAN_REQUIRED = 'RESTOCK_TECHNICIAN_REQUIRED';
    public const RESTOCK_TECHNICIAN_INACTIVE = 'RESTOCK_TECHNICIAN_INACTIVE';
    public const VAN_STOCK_MAX_CAPACITY_REACHED = 'VAN_STOCK_MAX_CAPACITY_REACHED';

    // ── 422 · coste ────────────────────────────────────────────────────────
    public const INVALID_REFURBISHMENT_COST = 'INVALID_REFURBISHMENT_COST';

    /** Longitud máxima de la nota de recepción (`workshop_repair_items.reception_notes`). */
    public const RECEPTION_NOTES_MAX_LENGTH = 500;

    private string $errorCode;
    private int $httpStatusCode;

    /** @var array<string, mixed> Datos de contexto útiles para el log, nunca secretos. */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(string $errorCode, string $message, int $httpStatusCode, array $context = [])
    {
        parent::__construct($message, $httpStatusCode);
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
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

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 404
    // ─────────────────────────────────────────────────────────────────────
    public static function workshopItemNotFound(?int $workshopItemId = null): self
    {
        return new self(
            self::WORKSHOP_ITEM_NOT_FOUND,
            $workshopItemId !== null
                ? sprintf('No se encontró ningún expediente de taller con ID %d.', $workshopItemId)
                : 'No se encontró el expediente de taller indicado.',
            404,
            ['workshop_item_id' => $workshopItemId]
        );
    }

    public static function technicianNotFound(?int $technicianId = null): self
    {
        return new self(
            self::TECHNICIAN_NOT_FOUND,
            $technicianId !== null
                ? sprintf('No se encontró ningún técnico activo con ID %d.', $technicianId)
                : 'No se encontró el técnico indicado.',
            404,
            ['technician_id' => $technicianId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 409
    // ─────────────────────────────────────────────────────────────────────
    public static function invalidStatusTransition(WorkshopRepairStatus $current, WorkshopRepairStatus $target): self
    {
        return new self(
            self::INVALID_WORKSHOP_STATUS_TRANSITION,
            sprintf(
                'No se puede pasar de «%s» a «%s»: la transición no pertenece al ciclo de taller.',
                $current->label(),
                $target->label()
            ),
            409,
            ['current_status' => $current->value, 'target_status' => $target->value]
        );
    }

    public static function alreadyResolved(int $workshopItemId, WorkshopRepairStatus $status): self
    {
        return new self(
            self::WORKSHOP_ITEM_ALREADY_RESOLVED,
            sprintf(
                'El expediente de taller %d ya tiene dictamen terminal («%s») y no admite más cambios.',
                $workshopItemId,
                $status->label()
            ),
            409,
            ['workshop_item_id' => $workshopItemId, 'status' => $status->value]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · recepción
    // ─────────────────────────────────────────────────────────────────────
    public static function receptionNotesTooLong(int $length): self
    {
        return new self(
            self::RECEPTION_NOTES_TOO_LONG,
            sprintf(
                'La nota de recepción no puede superar %d caracteres (recibidos %d).',
                self::RECEPTION_NOTES_MAX_LENGTH,
                $length
            ),
            422,
            ['length' => $length, 'max_length' => self::RECEPTION_NOTES_MAX_LENGTH]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · dictamen
    // ─────────────────────────────────────────────────────────────────────
    public static function invalidResolutionOutcome(string $outcome): self
    {
        return new self(
            self::INVALID_RESOLUTION_OUTCOME,
            sprintf(
                'El dictamen «%s» no es válido: sólo se admite REFURBISHED_RESTORED o SCRAPPED_IRREPARABLE.',
                $outcome
            ),
            422,
            ['outcome_status' => $outcome]
        );
    }

    public static function benchDiagnosisTooShort(int $length, string $errorCode = self::BENCH_DIAGNOSIS_TOO_SHORT): self
    {
        return new self(
            $errorCode,
            sprintf(
                'El diagnóstico de banco debe tener al menos 20 caracteres reales tras colapsar espacios (recibidos %d).',
                $length
            ),
            422,
            ['length' => $length, 'min_length' => 20]
        );
    }

    public static function benchDiagnosisTooLong(int $length): self
    {
        return new self(
            self::BENCH_DIAGNOSIS_TOO_LONG,
            sprintf('El diagnóstico de banco no puede superar 1000 caracteres (recibidos %d).', $length),
            422,
            ['length' => $length, 'max_length' => 1000]
        );
    }

    public static function benchDiagnosisWithoutWords(int $tokens, string $errorCode = self::BENCH_DIAGNOSIS_TOO_SHORT): self
    {
        return new self(
            $errorCode,
            sprintf(
                'El diagnóstico de banco debe contener al menos 3 palabras o tokens alfanuméricos: el relleno de símbolos no acredita una revisión real (Art. V.1).',
                $tokens
            ),
            422,
            ['alnum_tokens' => $tokens, 'min_tokens' => 3]
        );
    }

    public static function unreceivedPartCannotBeRefurbished(int $workshopItemId): self
    {
        return new self(
            self::UNRECEIVED_PART_CANNOT_BE_REFURBISHED,
            sprintf(
                'La unidad %d aún no ha llegado al taller: no se puede dictaminar como reacondicionada sin haberla recibido.',
                $workshopItemId
            ),
            422,
            ['workshop_item_id' => $workshopItemId]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · reintegro
    // ─────────────────────────────────────────────────────────────────────
    public static function invalidRestockDestination(?string $value): self
    {
        return new self(
            self::INVALID_RESTOCK_DESTINATION,
            sprintf(
                'El destino de reintegro «%s» no es válido: sólo se admite CENTRAL_WAREHOUSE o TECHNICIAN_VAN.',
                (string)$value
            ),
            422,
            ['restock_destination' => $value]
        );
    }

    public static function outOfCatalogVanRestockForbidden(int $workshopItemId): self
    {
        return new self(
            self::OUT_OF_CATALOG_VAN_RESTOCK_FORBIDDEN,
            sprintf(
                'La unidad %d procede de una pieza fuera de catálogo y no puede reintegrarse a la furgoneta de un técnico.',
                $workshopItemId
            ),
            422,
            ['workshop_item_id' => $workshopItemId]
        );
    }

    public static function inactiveSparePartVanRestockForbidden(int $sparePartId): self
    {
        return new self(
            self::INACTIVE_SPARE_PART_VAN_RESTOCK_FORBIDDEN,
            sprintf(
                'La pieza de catálogo %d está desactivada y no puede reintegrarse a la furgoneta de un técnico.',
                $sparePartId
            ),
            422,
            ['spare_part_id' => $sparePartId]
        );
    }

    public static function restockTechnicianRequired(): self
    {
        return new self(
            self::RESTOCK_TECHNICIAN_REQUIRED,
            'El reintegro a furgoneta exige indicar el técnico destinatario.',
            422
        );
    }

    public static function restockTechnicianInactive(int $technicianId): self
    {
        return new self(
            self::RESTOCK_TECHNICIAN_INACTIVE,
            sprintf('El técnico destinatario %d no existe o no está activo.', $technicianId),
            422,
            ['technician_id' => $technicianId]
        );
    }

    public static function vanStockMaxCapacityReached(int $technicianId, ?string $partCode = null): self
    {
        return new self(
            self::VAN_STOCK_MAX_CAPACITY_REACHED,
            sprintf(
                'La furgoneta del técnico %d ya tiene el máximo de 9999 unidades%s.',
                $technicianId,
                $partCode !== null ? sprintf(' de la pieza %s', $partCode) : ''
            ),
            422,
            ['technician_id' => $technicianId, 'part_code' => $partCode]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 422 · coste
    // ─────────────────────────────────────────────────────────────────────
    public static function invalidRefurbishmentCost(mixed $value): self
    {
        return new self(
            self::INVALID_REFURBISHMENT_COST,
            sprintf(
                'El coste de reacondicionamiento «%s» no es válido: debe ser un importe entre 0.00 y 9999.99.',
                is_scalar($value) ? (string)$value : get_debug_type($value)
            ),
            422,
            ['refurbishment_cost_eur' => $value]
        );
    }
}
