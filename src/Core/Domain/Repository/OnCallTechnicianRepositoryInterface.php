<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\User;

/**
 * OnCallTechnicianRepositoryInterface
 *
 * Capacidad de guardia técnica del directorio de personal (RF-02.5, RF-02.6 y
 * RF-03.1/RF-03.2). Se declara aparte de `UserRepositoryInterface` para no
 * engordar el contrato principal con una responsabilidad del módulo 14.
 */
interface OnCallTechnicianRepositoryInterface
{
    /**
     * Activa o desactiva la condición de Técnico de Guardia sobre un usuario.
     *
     * Sólo un TECHNICIAN en activo puede lucir la marca.
     *
     * @return bool True cuando la fila de un técnico activo se actualizó.
     */
    public function setOnCall(int $userId, bool $onCall): bool;

    /**
     * Técnicos activos con la marca de guardia (destinatarios de las alertas
     * críticas sin técnico asignado).
     *
     * @return array<User>
     */
    public function findOnCallTechnicians(): array;

    /**
     * Guardias activos que pueden recibir el Nivel 1 sanitario en el instante
     * indicado: sin ausencia activa (salvo presencia física ON_SHIFT) y sin
     * pausa de comida. Un guardia en OFF_SHIFT sin ausencia sigue siendo
     * elegible para perecederas.
     *
     * @return array<User>
     */
    public function findAvailableOnCallTechnicians(DateTimeImmutable $at, ?int $excludeTechnicianId = null): array;

    /**
     * Número de técnicos activos de guardia para el resumen del panel.
     */
    public function countOnCallTechnicians(): int;
}
