<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\TechnicianShiftLog;
use VendGuard\Core\Domain\ValueObject\ShiftEventType;

/**
 * TechnicianShiftLogRepositoryInterface
 *
 * Puerto de persistencia de la bitácora inmutable `technician_shift_logs`
 * (Módulo 22, contrato técnico §3.2).
 *
 * Art. III.1 y Art. III.3: la bitácora solo admite altas y lecturas. El puerto
 * no declara —y no puede declarar— actualización ni borrado; una corrección se
 * documenta como una entrada nueva que conserva el estado previo.
 *
 * Dogma Vanilla: interfaz pura de dominio, sin dependencias de infraestructura.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
interface TechnicianShiftLogRepositoryInterface
{
    /**
     * Registra una entrada de la bitácora.
     *
     * El actor se persiste por su identificador (`actor_user_id`); la resolución
     * de su nombre y rol es una proyección de presentación.
     *
     * @param array<string, mixed>|null $previousState Snapshot previo del turno.
     * @param array<string, mixed>      $newState      Snapshot resultante del turno.
     */
    public function record(
        int $shiftId,
        int $technicianId,
        ShiftEventType $eventType,
        int $actorUserId,
        DateTimeImmutable $effectiveAt,
        ?array $previousState,
        array $newState,
        ?string $reason = null
    ): TechnicianShiftLog;

    /**
     * Historial cronológico de una sesión de turno (orden ascendente por
     * `created_at` y, a igualdad de instante, por `id`).
     *
     * @return list<TechnicianShiftLog>
     */
    public function findByShiftId(int $shiftId): array;
}
