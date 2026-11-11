<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

/**
 * ZoneRestockerAssignmentDto
 *
 * Proyección de **una** vinculación viva del «📦 Cuadrante de Carga» de una
 * zona (Módulo 31, RF-02.4, D2, C3). Es la fila que el panel pinta: quién
 * cubre la demarcación, con qué papel y con qué insignia.
 *
 * La insignia no es decorativa. Una cuenta desactivada o un operario con una
 * ausencia vigente **siguen dentro del cuadrante** hasta que Coordinación los
 * retire: ocultarlos dejaría la baja sin ejecutar y el hueco invisible
 * (RF-02.4.2, `C3`). El DTO publica `availability_badge` con tres estados:
 *
 *   - `ACTIVO`  · cuenta activa y sin ausencia vigente.
 *   - `AUSENTE` · cuenta activa con una ausencia vigente en el instante leído.
 *   - `BAJA`    · cuenta desactivada (`is_active = FALSE`).
 *
 * `BAJA` prevalece sobre `AUSENTE`: una cuenta apagada no se reincorpora al
 * terminar la ausencia, y la etiqueta debe decir por qué no cubre.
 *
 * `email` no forma parte de la proyección: el panel no lo usa y una pantalla de
 * administración no amplía datos personales sin necesidad (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class ZoneRestockerAssignmentDto
{
    /** Insignia de una cuenta disponible. */
    public const BADGE_ACTIVE = 'ACTIVO';

    /** Insignia de una cuenta con ausencia vigente. */
    public const BADGE_ABSENT = 'AUSENTE';

    /** Insignia de una cuenta desactivada. */
    public const BADGE_INACTIVE = 'BAJA';

    public function __construct(
        public int $assignmentId,
        public int $restockerId,
        public string $name,
        public ?string $operatorCode,
        public string $roleInZone,
        public string $roleLabel,
        public bool $isPrimary,
        public bool $isActive,
        public string $availabilityBadge,
        public ?string $assignedAt
    ) {
    }

    /**
     * Compone la proyección desde la fila del repositorio.
     *
     * @param array{
     *     assignment_id: int,
     *     restocker_id: int,
     *     name: string,
     *     operator_code: string|null,
     *     role_in_zone: string,
     *     role_label?: string,
     *     is_primary: bool,
     *     is_active: bool,
     *     assigned_at: string|null
     * } $row
     * @param bool $hasActiveAbsence Ausencia vigente en el instante de lectura.
     */
    public static function fromRow(array $row, bool $hasActiveAbsence = false): self
    {
        $isActive = (bool)$row['is_active'];
        $isPrimary = (bool)$row['is_primary'];

        if (!$isActive) {
            $badge = self::BADGE_INACTIVE;
        } elseif ($hasActiveAbsence) {
            $badge = self::BADGE_ABSENT;
        } else {
            $badge = self::BADGE_ACTIVE;
        }

        return new self(
            (int)$row['assignment_id'],
            (int)$row['restocker_id'],
            (string)$row['name'],
            $row['operator_code'] !== null && $row['operator_code'] !== ''
                ? (string)$row['operator_code']
                : null,
            strtoupper((string)$row['role_in_zone']),
            isset($row['role_label']) && (string)$row['role_label'] !== ''
                ? (string)$row['role_label']
                : ($isPrimary ? 'Titular' : 'Suplente'),
            $isPrimary,
            $isActive,
            $badge,
            $row['assigned_at'] !== null ? (string)$row['assigned_at'] : null
        );
    }

    /**
     * Contrato JSON de la fila del cuadrante de carga.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'assignment_id' => $this->assignmentId,
            'restocker_id' => $this->restockerId,
            'name' => $this->name,
            'operator_code' => $this->operatorCode,
            'role_in_zone' => $this->roleInZone,
            'role_label' => $this->roleLabel,
            'is_primary' => $this->isPrimary,
            'is_active' => $this->isActive,
            'availability_badge' => $this->availabilityBadge,
            'assigned_at' => $this->assignedAt,
        ];
    }
}
