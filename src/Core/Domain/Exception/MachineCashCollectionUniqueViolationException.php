<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;
use VendGuard\Core\Domain\Model\MachineCashCollection;

/**
 * MachineCashCollectionUniqueViolationException
 *
 * Señal de dominio para las carreras que pierden contra los índices únicos de
 * `machine_cash_collections` (C1, D4, RF-01.2). El repositorio la lanza cuando
 * `INSERT ... ON CONFLICT DO NOTHING` no inserta y localiza la fila ganadora,
 * de modo que el servicio puede responder con un replay idempotente o con un
 * 409 de negocio en lugar de dejar escapar el `23505` como error interno.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class MachineCashCollectionUniqueViolationException extends DomainException
{
    public const DUPLICATE_MUTATION = 'DUPLICATE_MUTATION';
    public const DUPLICATE_SEAL = 'DUPLICATE_SEAL';

    private string $reason;

    private ?MachineCashCollection $existingCollection;

    private function __construct(string $reason, string $message, ?MachineCashCollection $existingCollection)
    {
        parent::__construct($message);

        $this->reason = $reason;
        $this->existingCollection = $existingCollection;
    }

    public static function duplicateMutation(MachineCashCollection $existing): self
    {
        return new self(
            self::DUPLICATE_MUTATION,
            sprintf(
                'La mutación %s ya fue registrada por el reponedor %d en la recaudación %s (D4).',
                $existing->getClientMutationId(),
                $existing->getRestockerId(),
                (string)$existing->getCollectionCode()
            ),
            $existing
        );
    }

    public static function duplicateSeal(string $bagSealCode): self
    {
        return new self(
            self::DUPLICATE_SEAL,
            sprintf('El precinto %s ya fue consumido por otra recaudación (C1).', $bagSealCode),
            null
        );
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getExistingCollection(): ?MachineCashCollection
    {
        return $this->existingCollection;
    }

    public function isDuplicateMutation(): bool
    {
        return $this->reason === self::DUPLICATE_MUTATION;
    }

    public function isDuplicateSeal(): bool
    {
        return $this->reason === self::DUPLICATE_SEAL;
    }
}
