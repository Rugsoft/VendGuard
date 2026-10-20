<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

/**
 * ZoneTechnicianAssignmentDto
 *
 * Proyección de **una** vinculación viva del cuadrante de una zona (RF-02.3,
 * Resolución QA 15). Es lo que el panel necesita para *nombrar* a quien cubre la
 * demarcación en lugar de contar cuántos hay.
 *
 * El `email` no forma parte del contrato a propósito: el panel no lo usa y una
 * pantalla de administración no debe ampliar la superficie de datos personales
 * sin una necesidad operativa (QA 15). `operator_code` es el identificador que
 * el coordinador reconoce y puede ser `null` en usuarios anteriores al campo; el
 * cliente cae entonces al identificador numérico, igual que el selector previo.
 *
 * `is_active = false` no significa «fuera del cuadrante»: la vinculación sigue
 * viva hasta que se extingue con `removed_at` y el coordinador debe poder
 * retirarla desde la lista (QA 10).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class ZoneTechnicianAssignmentDto
{
    public function __construct(
        public int $assignmentId,
        public int $technicianId,
        public string $name,
        public ?string $operatorCode,
        public bool $isPrimary,
        public bool $isOnCall,
        public bool $isActive,
        public ?string $assignedAt
    ) {
    }

    /**
     * Compone la proyección desde la fila del repositorio.
     *
     * @param array{
     *     assignment_id: int,
     *     technician_id: int,
     *     name: string,
     *     operator_code: string|null,
     *     is_primary: bool,
     *     is_on_call: bool,
     *     is_active: bool,
     *     assigned_at: string|null
     * } $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)$row['assignment_id'],
            (int)$row['technician_id'],
            (string)$row['name'],
            $row['operator_code'] !== null && $row['operator_code'] !== '' ? (string)$row['operator_code'] : null,
            (bool)$row['is_primary'],
            (bool)$row['is_on_call'],
            (bool)$row['is_active'],
            $row['assigned_at'] !== null ? (string)$row['assigned_at'] : null
        );
    }

    /**
     * Contrato JSON de la fila (§3.8 del contrato técnico).
     *
     * @return array{
     *     assignment_id: int,
     *     technician_id: int,
     *     name: string,
     *     operator_code: string|null,
     *     is_primary: bool,
     *     is_on_call: bool,
     *     is_active: bool,
     *     assigned_at: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'assignment_id' => $this->assignmentId,
            'technician_id' => $this->technicianId,
            'name' => $this->name,
            'operator_code' => $this->operatorCode,
            'is_primary' => $this->isPrimary,
            'is_on_call' => $this->isOnCall,
            'is_active' => $this->isActive,
            'assigned_at' => $this->assignedAt,
        ];
    }
}
