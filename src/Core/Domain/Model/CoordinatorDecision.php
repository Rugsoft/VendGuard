<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * CoordinatorDecision
 *
 * Decisión formal de Coordinación sobre una reclamación de dinero retenido.
 *
 * Es el cierre administrativo del expediente: o se autoriza una cuantía que la
 * empresa debe abonar, o se desestima con un motivo escrito (RF-REF-03,
 * RF-REF-08). Los dos valores son terminales a efectos de decisión: una vez
 * escrito, la arista de la máquina de estados impide que el mismo expediente
 * vuelva a un estado con decisión abierta.
 *
 * La distinción importa en la auditoría contable. Un `APPROVED` dice que
 * existe una deuda reconocida por la empresa; un `REJECTED` dice que se
 *AUDITÓ y no se debe nada. Por eso el motivo del rechazo es obligatorio y de
 * 20 caracteres como mínimo (Art. V.1).
 */
enum CoordinatorDecision: string
{
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';

    /**
     * Devuelve la etiqueta descriptiva en castellano para la interfaz de
     * Coordinación.
     */
    public function label(): string
    {
        return match ($this) {
            self::APPROVED => 'Visto bueno concedido',
            self::REJECTED => 'Reclamación desestimada',
        };
    }

    /**
     * Si la decisión autoriza un desembolso a favor del reclamante.
     */
    public function authorisesPayment(): bool
    {
        return $this === self::APPROVED;
    }
}