<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\WorkshopRepairValidationException;

/**
 * ReceiveWorkshopItemRequestDTO — cuerpo de `POST /api/coordinator/workshop-items/{id}/receive`
 * (Módulo 27, RF-02.1).
 *
 * Sólo transporta lo que el contrato admite: la unidad y el actor los pone la
 * ruta y el token, nunca el cuerpo, de modo que un Coordinador no pueda recibir
 * una pieza «en nombre de» otro usuario. La nota de recepción es opcional y se
 * mide sobre su texto recortado: 500 caracteres es el ancho de la columna
 * `reception_notes`, y pasarse no se recorta en silencio, se rechaza con `422`.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final readonly class ReceiveWorkshopItemRequestDTO
{
    public function __construct(
        public int $workshopItemId,
        public int $actorUserId,
        public ?string $receptionNotes = null
    ) {
        if ($workshopItemId <= 0) {
            throw new InvalidArgumentException('El identificador del expediente de taller debe ser positivo.');
        }

        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('El usuario actor de la recepción debe ser un identificador positivo.');
        }

        if ($receptionNotes !== null
            && mb_strlen(trim($receptionNotes), 'UTF-8') > WorkshopRepairValidationException::RECEPTION_NOTES_MAX_LENGTH) {
            throw WorkshopRepairValidationException::receptionNotesTooLong(
                mb_strlen(trim($receptionNotes), 'UTF-8')
            );
        }
    }

    /**
     * @param array<string, mixed> $payload Cuerpo JSON ya decodificado.
     */
    public static function fromPayload(int $workshopItemId, int $actorUserId, array $payload): self
    {
        $notes = $payload['reception_notes'] ?? null;
        if ($notes !== null && !is_string($notes)) {
            throw new InvalidArgumentException('La nota de recepción debe ser texto.');
        }

        return new self($workshopItemId, $actorUserId, $notes);
    }

    /**
     * Nota normalizada, o `null` si no aporta contenido.
     */
    public function effectiveReceptionNotes(): ?string
    {
        $notes = $this->receptionNotes !== null ? trim($this->receptionNotes) : '';

        return $notes === '' ? null : $notes;
    }
}
