<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

/**
 * TechnicianQuadrantDto
 *
 * Lectura **inversa** del cuadrante: un técnico con todas sus vinculaciones
 * vivas, cada una con la zona a la que pertenece y el papel que juega en ella
 * (RF-02.3, Resolución QA 15).
 *
 * `zones = []` es información, no ausencia de datos: significa que el técnico
 * está activo y **no cubre ninguna demarcación**, que es justo lo que el panel
 * debe señalar para que alguien lo asigne.
 *
 * El orden no lo decide el cliente: las zonas llegan con la titular primero y
 * después las suplencias por nombre de zona, y los técnicos por nombre.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class TechnicianQuadrantDto
{
    /**
     * @param list<array{
     *     zone_id: int,
     *     code: string,
     *     name: string,
     *     color_hex: string,
     *     is_primary: bool,
     *     assigned_at: string|null
     * }> $zones
     */
    public function __construct(
        public int $technicianId,
        public string $name,
        public ?string $operatorCode,
        public bool $isActive,
        public bool $isOnCall,
        public array $zones
    ) {
    }

    /**
     * Compone la proyección desde la fila agregada del repositorio.
     *
     * @param array{
     *     technician_id: int,
     *     name: string,
     *     operator_code: string|null,
     *     is_active: bool,
     *     is_on_call: bool,
     *     zones: list<array{
     *         zone_id: int,
     *         code: string,
     *         name: string,
     *         color_hex: string,
     *         is_primary: bool,
     *         assigned_at: string|null
     *     }>
     * } $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)$row['technician_id'],
            (string)$row['name'],
            $row['operator_code'] !== null && $row['operator_code'] !== '' ? (string)$row['operator_code'] : null,
            (bool)$row['is_active'],
            (bool)$row['is_on_call'],
            array_values($row['zones'] ?? [])
        );
    }

    /**
     * Contrato JSON del técnico y sus zonas (§3.9 del contrato técnico).
     *
     * @return array{
     *     technician_id: int,
     *     name: string,
     *     operator_code: string|null,
     *     is_active: bool,
     *     is_on_call: bool,
     *     zones: list<array{
     *         zone_id: int,
     *         code: string,
     *         name: string,
     *         color_hex: string,
     *         is_primary: bool,
     *         assigned_at: string|null
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'technician_id' => $this->technicianId,
            'name' => $this->name,
            'operator_code' => $this->operatorCode,
            'is_active' => $this->isActive,
            'is_on_call' => $this->isOnCall,
            'zones' => $this->zones,
        ];
    }
}
