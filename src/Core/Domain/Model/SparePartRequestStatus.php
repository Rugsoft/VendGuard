<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * SparePartRequestStatus
 * 
 * Ciclo de vida de una solicitud estructurada de repuesto formulada durante una pausa técnica.
 */
enum SparePartRequestStatus: string
{
    case PENDING   = 'PENDING';   // Solicitud abierta y pendiente de aprovisionamiento/instalación
    case ATTENDED  = 'ATTENDED';  // Atendida e instalada al resolver la intervención
    case CANCELLED = 'CANCELLED'; // Avería cancelada o descartada (sin consumo real)

    /**
     * Retorna la etiqueta legible en español para la interfaz de usuario.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'Pendiente de Repuesto',
            self::ATTENDED  => 'Atendida e Instalada',
            self::CANCELLED => 'Cancelada',
        };
    }
}
