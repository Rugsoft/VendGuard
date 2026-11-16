<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use InvalidArgumentException;

/**
 * CashCollectionStatus
 *
 * Estado de custodia de una recaudación de efectivo (Módulo 35, Tabla 49
 * `machine_cash_collections.status`, RF-01.1, D2, D4, QA 1, Art. V.4).
 *
 * El ciclo tiene cuatro estados y dos de ellos son terminales:
 *
 *   SEALED_IN_ROUTE ──▶ DELIVERED_TO_VAULT ──▶ RECONCILED_OK
 *                                            └▶ DISCREPANCY_FLAGGED
 *
 * `SEALED_IN_ROUTE` es el corte formal en ruta: la bolsa va precintada y todavía
 * nadie la ha abierto. `DELIVERED_TO_VAULT` acredita la entrada física en Caja
 * Central. Los dos estados de arqueo son terminales: la fila queda bloqueada
 * frente a cualquier `UPDATE` (`enforce_machine_cash_collections_immutability()`,
 * Art. III.3) y una corrección se registra como una recaudación nueva.
 *
 * **Estado público del reponedor (`C2`, Art. V.4):** el móvil nunca ve si el
 * arqueo cuadró o no. Los dos estados terminales se proyectan como
 * `PROCESSED_IN_VAULT`, de modo que el reponedor sabe que su bolsa ya se procesó
 * sin recibir el dictamen financiero de Caja Central.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum CashCollectionStatus: string
{
    case SEALED_IN_ROUTE = 'SEALED_IN_ROUTE';
    case DELIVERED_TO_VAULT = 'DELIVERED_TO_VAULT';
    case RECONCILED_OK = 'RECONCILED_OK';
    case DISCREPANCY_FLAGGED = 'DISCREPANCY_FLAGGED';

    /** Estado externo que ve el reponedor cuando la bolsa ya fue arqueada (C2). */
    public const PUBLIC_PROCESSED_STATUS = 'PROCESSED_IN_VAULT';

    public function label(): string
    {
        return match ($this) {
            self::SEALED_IN_ROUTE => 'Sellada en ruta',
            self::DELIVERED_TO_VAULT => 'Entregada en Caja Central',
            self::RECONCILED_OK => 'Arqueada sin descuadre',
            self::DISCREPANCY_FLAGGED => 'Arqueada con descuadre',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::SEALED_IN_ROUTE => '🚚',
            self::DELIVERED_TO_VAULT => '🏦',
            self::RECONCILED_OK => '✅',
            self::DISCREPANCY_FLAGGED => '🚨',
        };
    }

    /**
     * Estado que puede ver el reponedor en su móvil (C2, Art. V.4).
     *
     * Los dos desenlaces del arqueo se funden en `PROCESSED_IN_VAULT`: el
     * reponedor conoce la custodia de su bolsa, nunca el descuadre.
     */
    public function publicRestockerStatus(): string
    {
        return $this->isTerminal() ? self::PUBLIC_PROCESSED_STATUS : $this->value;
    }

    public function publicRestockerLabel(): string
    {
        return match ($this) {
            self::SEALED_IN_ROUTE => 'Sellada en ruta',
            self::DELIVERED_TO_VAULT => 'Entregada en Caja Central',
            self::RECONCILED_OK, self::DISCREPANCY_FLAGGED => 'Procesada en Caja Central',
        };
    }

    public function isSealedInRoute(): bool
    {
        return $this === self::SEALED_IN_ROUTE;
    }

    public function isDeliveredToVault(): bool
    {
        return $this === self::DELIVERED_TO_VAULT;
    }

    public function isReconciledOk(): bool
    {
        return $this === self::RECONCILED_OK;
    }

    public function isDiscrepancyFlagged(): bool
    {
        return $this === self::DISCREPANCY_FLAGGED;
    }

    /**
     * ¿El arqueo ya está sellado? Solo los dos desenlaces terminales.
     */
    public function isTerminal(): bool
    {
        return $this === self::RECONCILED_OK || $this === self::DISCREPANCY_FLAGGED;
    }

    /**
     * ¿Admite la recepción física en cámara? Solo una bolsa aún en ruta (D4).
     */
    public function allowsVaultReception(): bool
    {
        return $this === self::SEALED_IN_ROUTE;
    }

    /**
     * ¿Admite el arqueo de Caja Central? Una bolsa sellada o ya recepcionada;
     * nunca una fila terminal (D2, D4).
     */
    public function allowsReconciliation(): bool
    {
        return $this === self::SEALED_IN_ROUTE || $this === self::DELIVERED_TO_VAULT;
    }

    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $status = self::tryFrom(strtoupper(trim($value)));
        if ($status === null) {
            throw new InvalidArgumentException(sprintf(
                "Estado de recaudación no válido: '%s'. Valores permitidos: %s.",
                $value,
                implode(', ', self::values())
            ));
        }

        return $status;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
