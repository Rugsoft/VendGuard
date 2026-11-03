<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use VendGuard\Core\Domain\Enum\IncidentAssignmentSource;
use VendGuard\Core\Domain\Enum\IncidentUnassignmentReasonType;

/**
 * IncidentAssignment — un tramo de custodia técnica de una avería (Módulo 26).
 *
 * Cada fila de `incident_assignments` es la fotografía de una tenencia: quién
 * respondía de la avería, desde cuándo, por qué canal entró y, si ya terminó,
 * quién la cerró, cuándo y con qué causa. La secuencia de tramos es la cadena de
 * custodia; el expediente sólo conserva el custodio actual y no puede
 * reconstruirla (RF-01).
 *
 * **Inmutabilidad de dominio.** La clase es `readonly`: el ciclo de vida de un
 * tramo es abrirse una vez y cerrarse una vez, nunca reescribirse. Los snapshots
 * de nombre y código viven aquí, no se leen del directorio de usuarios, porque
 * un renombrado o una baja reescribirían el pasado (D2, Art. III.3).
 *
 * **Coherencia de cierre.** O el tramo está abierto y no tiene ningún dato de
 * salida, o está cerrado y los tiene todos: el estado intermedio no existe en el
 * motor (`chk_incident_assignments_closure_coherence`) y tampoco debe poder
 * construirse en memoria sin que el dominio lo denuncie.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class IncidentAssignment
{
    /**
     * @param int|null $id Identificador persistido; `null` antes de insertar.
     * @param int $incidentId Avería a la que pertenece el tramo.
     * @param int $technicianId Técnico custodio.
     * @param string $technicianNameSnapshot Nombre del técnico en el instante de apertura.
     * @param string $technicianCodeSnapshot Código de operario congelado (`SIN-CODIGO` si no tenía).
     * @param IncidentAssignmentSource $assignmentSource Canal que abrió el tramo.
     * @param int|null $assignedBy Usuario que asignó; `null` en altas del sistema o históricas.
     * @param string $assignedByNameSnapshot Autor legible de la apertura.
     * @param DateTimeImmutable $assignedAt Instante de apertura del tramo.
     * @param DateTimeImmutable|null $unassignedAt Instante de cierre; `null` mientras está activo.
     * @param int|null $unassignedBy Usuario que cerró; `null` en guardias y liberaciones del sistema.
     * @param string|null $unassignedByNameSnapshot Autor legible del cierre.
     * @param IncidentUnassignmentReasonType|null $unassignmentReasonType Causa tipificada del cierre.
     * @param string|null $reassignmentReason Motivo o diagnóstico que explica el cierre.
     *
     * @throws InvalidArgumentException Si los identificadores, los snapshots, la
     *                                  coherencia de cierre o el orden temporal no son válidos.
     */
    public function __construct(
        private ?int $id,
        private int $incidentId,
        private int $technicianId,
        private string $technicianNameSnapshot,
        private string $technicianCodeSnapshot,
        private IncidentAssignmentSource $assignmentSource,
        private ?int $assignedBy,
        private string $assignedByNameSnapshot,
        private DateTimeImmutable $assignedAt,
        private ?DateTimeImmutable $unassignedAt = null,
        private ?int $unassignedBy = null,
        private ?string $unassignedByNameSnapshot = null,
        private ?IncidentUnassignmentReasonType $unassignmentReasonType = null,
        private ?string $reassignmentReason = null,
    ) {
        if ($id !== null && $id < 1) {
            throw new InvalidArgumentException('El tramo de custodia requiere un identificador estrictamente positivo.');
        }

        if ($incidentId < 1 || $technicianId < 1) {
            throw new InvalidArgumentException('Los identificadores de incidencia y técnico del tramo deben ser enteros positivos.');
        }

        if ($assignedBy !== null && $assignedBy < 1) {
            throw new InvalidArgumentException('El autor de la apertura del tramo debe ser un usuario válido.');
        }

        if ($unassignedBy !== null && $unassignedBy < 1) {
            throw new InvalidArgumentException('El autor del cierre del tramo debe ser un usuario válido.');
        }

        if (trim($this->technicianNameSnapshot) === ''
            || trim($this->technicianCodeSnapshot) === ''
            || trim($this->assignedByNameSnapshot) === '') {
            throw new InvalidArgumentException('Los snapshots de apertura del tramo no pueden quedar vacíos: un tramo sin nombre legible no es auditable (R5).');
        }

        if ($this->isActive()) {
            if ($this->unassignedBy !== null
                || $this->unassignedByNameSnapshot !== null
                || $this->unassignmentReasonType !== null
                || $this->reassignmentReason !== null) {
                throw new InvalidArgumentException('Un tramo de custodia abierto no puede declarar datos de cierre (coherencia de cierre, EARS 01.2).');
            }

            return;
        }

        if ($this->unassignmentReasonType === null) {
            throw new InvalidArgumentException('Un tramo de custodia cerrado debe declarar su causa de cierre (coherencia de cierre, EARS 01.2).');
        }

        if ($this->unassignedByNameSnapshot === null || trim($this->unassignedByNameSnapshot) === '') {
            throw new InvalidArgumentException('Un tramo de custodia cerrado debe conservar el nombre de quien lo cerró (D2).');
        }

        if ($this->reassignmentReason === null || trim($this->reassignmentReason) === '') {
            throw new InvalidArgumentException('Un tramo de custodia cerrado debe explicar su cierre: el motivo o diagnóstico es obligatorio (R5).');
        }

        if ($this->unassignedAt < $this->assignedAt) {
            throw new InvalidArgumentException('El cierre de un tramo de custodia no puede ser anterior a su apertura (QA 4).');
        }
    }

    /** ¿El técnico sigue respondiendo de la avería? */
    public function isActive(): bool
    {
        return $this->unassignedAt === null;
    }

    /**
     * Segundos de custodia **en tiempo natural continuo**: desde la apertura hasta
     * el cierre, o hasta `$now` si el tramo sigue activo. No descuenta pausas,
     * esperas de repuesto ni periodos en `PENDING_INFO`: la responsabilidad del
     * técnico no se interrumpe mientras la avería está en su cartera (D4).
     *
     * El suelo temporal devuelve `0` en lugar de un negativo cuando el cierre
     * llega con la hora de un cliente móvil anterior a la apertura (QA 4).
     */
    public function retentionSecondsAt(DateTimeImmutable $now): int
    {
        $end = $this->unassignedAt ?? $now;
        $seconds = $end->getTimestamp() - $this->assignedAt->getTimestamp();

        return $seconds > 0 ? $seconds : 0;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIncidentId(): int
    {
        return $this->incidentId;
    }

    public function getTechnicianId(): int
    {
        return $this->technicianId;
    }

    public function getTechnicianNameSnapshot(): string
    {
        return $this->technicianNameSnapshot;
    }

    public function getTechnicianCodeSnapshot(): string
    {
        return $this->technicianCodeSnapshot;
    }

    public function getAssignmentSource(): IncidentAssignmentSource
    {
        return $this->assignmentSource;
    }

    public function getAssignedBy(): ?int
    {
        return $this->assignedBy;
    }

    public function getAssignedByNameSnapshot(): string
    {
        return $this->assignedByNameSnapshot;
    }

    public function getAssignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function getUnassignedAt(): ?DateTimeImmutable
    {
        return $this->unassignedAt;
    }

    public function getUnassignedBy(): ?int
    {
        return $this->unassignedBy;
    }

    public function getUnassignedByNameSnapshot(): ?string
    {
        return $this->unassignedByNameSnapshot;
    }

    public function getUnassignmentReasonType(): ?IncidentUnassignmentReasonType
    {
        return $this->unassignmentReasonType;
    }

    public function getReassignmentReason(): ?string
    {
        return $this->reassignmentReason;
    }
}
