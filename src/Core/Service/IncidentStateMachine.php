<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;

/**
 * IncidentStateMachine
 * 
 * Guardián de Dominio para las transiciones de estado en el ciclo de vida de incidencias.
 * Implementa las reglas de negocio de los requisitos RF-05, RF-07, RF-08, RF-09 y RF-10.
 * 
 * Grafo estricto de transiciones:
 * - REGISTERED    -> ASSIGNED, CANCELLED
 * - ASSIGNED      -> IN_PROGRESS, ASSIGNED (reasignación), CANCELLED
 * - IN_PROGRESS   -> PENDING_PARTS, RESOLVED, CANCELLED
 * - PENDING_PARTS -> IN_PROGRESS, CANCELLED
 * - RESOLVED      -> CLOSED, REOPENED
 * - REOPENED      -> ASSIGNED, IN_PROGRESS, CANCELLED
 * - CLOSED        -> (Terminal)
 * - CANCELLED     -> (Terminal)
 */
class IncidentStateMachine
{
    /**
     * Mapa de equivalencia para soportar tanto nombres canónicos en inglés
     * como terminología funcional en castellano.
     */
    private const STATUS_SYNONYMS = [
        // Términos en Castellano
        'REGISTRADA'             => IncidentStatus::REGISTERED,
        'ASIGNADA'               => IncidentStatus::ASSIGNED,
        'EN_CURSO'               => IncidentStatus::IN_PROGRESS,
        'PENDIENTE_REPUESTO'     => IncidentStatus::PENDING_PARTS,
        'PENDIENTE_REPUESTOS'    => IncidentStatus::PENDING_PARTS,
        'PENDIENTE_DE_REPUESTOS' => IncidentStatus::PENDING_PARTS,
        'RESUELTA'               => IncidentStatus::RESOLVED,
        'REABIERTA'              => IncidentStatus::REOPENED,
        'CERRADA'                => IncidentStatus::CLOSED,
        'CANCELADA'              => IncidentStatus::CANCELLED,

        // Términos Canónicos en Inglés
        'REGISTERED'             => IncidentStatus::REGISTERED,
        'ASSIGNED'               => IncidentStatus::ASSIGNED,
        'IN_PROGRESS'            => IncidentStatus::IN_PROGRESS,
        'PENDING_PARTS'          => IncidentStatus::PENDING_PARTS,
        'RESOLVED'               => IncidentStatus::RESOLVED,
        'REOPENED'               => IncidentStatus::REOPENED,
        'CLOSED'                 => IncidentStatus::CLOSED,
        'CANCELLED'              => IncidentStatus::CANCELLED,
    ];

    /**
     * Valida si una transición entre dos estados es legal según el grafo.
     * Si la transición está permitida, devuelve true.
     * Si la transición no es válida o está prohibida, lanza InvalidTransitionException.
     *
     * @param IncidentStatus|string $from Estado origen (Enum o texto en EN/ES).
     * @param IncidentStatus|string $to Estado destino (Enum o texto en EN/ES).
     * @return bool True si la transición es permitida.
     * @throws InvalidTransitionException Si la transición no está autorizada.
     * @throws InvalidArgumentException Si algún estado no es reconocido.
     */
    public static function canTransition(IncidentStatus|string $from, IncidentStatus|string $to): bool
    {
        $fromEnum = self::normalizeStatus($from);
        $toEnum = self::normalizeStatus($to);

        if (!$fromEnum->canTransitionTo($toEnum)) {
            $allowed = $fromEnum->allowedTransitions();
            $allowedNames = empty($allowed)
                ? 'Ninguna (estado terminal)'
                : implode(', ', array_map(fn(IncidentStatus $s) => $s->name, $allowed));

            throw new InvalidTransitionException(
                sprintf(
                    "Transición de estado no autorizada: de '%s' a '%s'. Transiciones legales permitidas desde '%s': [%s].",
                    $fromEnum->name,
                    $toEnum->name,
                    $fromEnum->name,
                    $allowedNames
                ),
                $fromEnum,
                $toEnum
            );
        }

        return true;
    }

    /**
     * Comprueba si una transición es válida sin lanzar excepciones.
     *
     * @param IncidentStatus|string $from
     * @param IncidentStatus|string $to
     * @return bool
     */
    public static function isValid(IncidentStatus|string $from, IncidentStatus|string $to): bool
    {
        try {
            return self::canTransition($from, $to);
        } catch (InvalidTransitionException | InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Aserción estricta de transición permitida (lanza InvalidTransitionException si falla).
     *
     * @param IncidentStatus|string $from
     * @param IncidentStatus|string $to
     * @return void
     * @throws InvalidTransitionException
     */
    public static function assertCanTransition(IncidentStatus|string $from, IncidentStatus|string $to): void
    {
        self::canTransition($from, $to);
    }

    /**
     * Obtiene la lista de estados alcanzables legalmente desde un estado dado.
     *
     * @param IncidentStatus|string $status
     * @return array<IncidentStatus>
     */
    public static function getAllowedTransitions(IncidentStatus|string $status): array
    {
        $enum = self::normalizeStatus($status);
        return $enum->allowedTransitions();
    }

    /**
     * Normaliza un estado de entrada (instancia Enum o texto) al Enum correspondiente.
     *
     * @param IncidentStatus|string $status
     * @return IncidentStatus
     * @throws InvalidArgumentException
     */
    public static function normalizeStatus(IncidentStatus|string $status): IncidentStatus
    {
        if ($status instanceof IncidentStatus) {
            return $status;
        }

        $key = strtoupper(trim($status));

        if (isset(self::STATUS_SYNONYMS[$key])) {
            return self::STATUS_SYNONYMS[$key];
        }

        throw new InvalidArgumentException(
            "Estado de incidencia no reconocido: '{$status}'."
        );
    }
}
