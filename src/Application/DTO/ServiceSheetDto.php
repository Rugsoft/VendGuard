<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

/**
 * ServiceSheetDto
 *
 * Metadatos de la hoja en la respuesta del cierre (contratos §3.4). Existe solo
 * para decirle al técnico que su hoja quedó registrada, con quién la firmó y
 * cuándo: nunca transporta el trazo ni el documento congelado.
 */
final class ServiceSheetDto
{
    public function __construct(
        public readonly bool $signed,
        public readonly ?string $signerName,
        public readonly string $recordedAt,
    ) {
    }

    /**
     * @return array{signed: bool, signer_name: string|null, recorded_at: string}
     */
    public function toArray(): array
    {
        return [
            'signed' => $this->signed,
            'signer_name' => $this->signerName,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
