<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use VendGuard\Core\Domain\ValueObject\OpportunisticActionType;

/**
 * OpportunisticMachineCatalogDto — Proyección inmutable del **catálogo de
 * máquinas operativas de una sede habilitada** (Módulo 16, RF-01.4 y RF-01.5).
 *
 * Es la respuesta de `GET /api/technician/locations/{location_id}/opportunistic-machines`
 * una vez ensamblada por `OpportunisticIncidentService`: la sede con su tipo de
 * habilitación, cada máquina con la **acción exacta** que el técnico puede
 * ejecutar sobre ella, el conflicto de coexistencia que bloquea la visita
 * entera (RF-02.2) y la advertencia sanitaria de ruta (RF-03.1).
 *
 * La clasificación de la acción es una **función pura de hechos ya consultados**
 * (`classifyAction`): no decide nada nuevo sobre la base de datos, solo traduce
 * el expediente vivo que el repositorio entregó a la etiqueta que pinta la
 * interfaz. Se escribe aquí, una sola vez, para que la tarjeta del móvil y la
 * puerta de escritura del servicio no puedan discrepar.
 *
 * Decisiones de clasificación:
 *
 *  - Sin expediente → `CREATE_NEW` (Flujo A).
 *  - `REGISTERED` / `REOPENED` sin técnico → `ASSUME_DIRECT` (Flujo B, caso 1).
 *  - `ASSIGNED` / `PENDING_PARTS` / `PENDING_INFO` del propio técnico →
 *    `ASSUME_DIRECT`: es su propia parada, la reanuda en un toque.
 *  - `ASSIGNED` / `PENDING_*` de otro compañero → `ASSUME_WITH_CONFIRMATION`
 *    (RF-05.3), nunca un arrebato silencioso.
 *  - `IN_PROGRESS` del propio técnico → `CURRENT_TECHNICIAN_IN_PROGRESS`.
 *  - `IN_PROGRESS` de otro → `BLOCKED_OTHER_TECH_IN_PROGRESS` (Art. V.3),
 *    la única acción deshabilitada del catálogo.
 *  - `RESOLVED` en garantía de 48 h → `REOPEN_IN_SITU` (RF-05.4).
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, claves JSON y etiquetas en castellano.
 */
final readonly class OpportunisticMachineCatalogDto
{
    /**
     * @param array<string, mixed> $location Sede habilitada con su tipo de habilitación y gracia.
     * @param list<array<string, mixed>> $machines Máquinas con su acción oportunista ya clasificada.
     * @param array<string, mixed>|null $activeInterventionConflict Intervención abierta en otra sede, si la hay.
     * @param array{requires_confirmation: bool, pending_critical_stops: list<array<string, mixed>>} $coldChainRouteWarning
     */
    public function __construct(
        public array $location,
        public array $machines,
        public ?array $activeInterventionConflict = null,
        public array $coldChainRouteWarning = ['requires_confirmation' => false, 'pending_critical_stops' => []]
    ) {
    }

    /**
     * Clasifica la acción oportunista de una máquina a partir de su expediente
     * activo o en garantía y de la titularidad del técnico que consulta.
     *
     * @param array<string, mixed>|null $activeIncident Expediente devuelto por el catálogo, o `null`.
     * @param int $technicianId Técnico que consulta.
     */
    public static function classifyAction(?array $activeIncident, int $technicianId): OpportunisticActionType
    {
        if ($activeIncident === null) {
            return OpportunisticActionType::CREATE_NEW;
        }

        $status = strtoupper(trim((string)($activeIncident['status'] ?? '')));
        $assignedTechnicianId = isset($activeIncident['assigned_technician_id'])
            && is_numeric($activeIncident['assigned_technician_id'])
                ? (int)$activeIncident['assigned_technician_id']
                : null;

        return match ($status) {
            'IN_PROGRESS' => $assignedTechnicianId === $technicianId
                ? OpportunisticActionType::CURRENT_TECHNICIAN_IN_PROGRESS
                : OpportunisticActionType::BLOCKED_OTHER_TECH_IN_PROGRESS,
            'RESOLVED' => OpportunisticActionType::REOPEN_IN_SITU,
            'REGISTERED', 'REOPENED', 'ASSIGNED', 'PENDING_PARTS', 'PENDING_INFO' =>
                $assignedTechnicianId !== null && $assignedTechnicianId !== $technicianId
                    ? OpportunisticActionType::ASSUME_WITH_CONFIRMATION
                    : OpportunisticActionType::ASSUME_DIRECT,
            default => OpportunisticActionType::ASSUME_DIRECT,
        };
    }

    /**
     * Decora una máquina del catálogo con su acción, etiqueta y disponibilidad.
     *
     * @param array<string, mixed> $machine Fila cruda del repositorio.
     * @return array<string, mixed> Máquina enriquecida para la interfaz.
     */
    public static function decorateMachine(array $machine, int $technicianId): array
    {
        $action = self::classifyAction(
            isset($machine['active_incident']) && is_array($machine['active_incident'])
                ? $machine['active_incident']
                : null,
            $technicianId
        );

        $machine['opportunistic_action'] = $action->value;
        $machine['opportunistic_action_label'] = $action->label();
        $machine['is_available'] = $action->isAvailable();
        $machine['requires_confirmation'] = $action->requiresConfirmation();

        return $machine;
    }

    /**
     * ¿El catálogo exige confirmación expresa del Art. II antes de operar?
     */
    public function requiresColdChainConfirmation(): bool
    {
        return ($this->coldChainRouteWarning['requires_confirmation'] ?? false) === true;
    }

    /**
     * ¿El técnico dejó trabajo abierto en otra sede y no puede coexistir aquí?
     */
    public function hasActiveInterventionConflict(): bool
    {
        return $this->activeInterventionConflict !== null;
    }

    /**
     * Busca una máquina del catálogo por su identificador.
     *
     * @return array<string, mixed>|null
     */
    public function machineById(int $machineId): ?array
    {
        foreach ($this->machines as $machine) {
            if ((int)($machine['id'] ?? 0) === $machineId) {
                return $machine;
            }
        }

        return null;
    }

    /**
     * Proyección exacta del contrato HTTP (§4.2 de los contratos técnicos).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'location' => $this->location,
            'active_intervention_conflict' => $this->activeInterventionConflict,
            'cold_chain_route_warning' => $this->coldChainRouteWarning,
            'machines' => $this->machines,
        ];
    }
}
