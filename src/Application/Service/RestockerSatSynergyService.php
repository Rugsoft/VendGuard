<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Repository\RestockerSatSynergyGatewayInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentDispatchLevel;
use VendGuard\Core\Service\RestockerSatSynergyValidator;

/**
 * RestockerSatSynergyService
 *
 * Caso de uso de la sinergia reponedor ↔ SAT (Módulo 36; Fase 4, T-RSS-06).
 *
 * Orquesta el gateway transaccional con las reglas de negocio que no dependen
 * del motor: despacho y conmutación L1↔L2 con coherencia de rol y categoría,
 * inicio/resolución in situ, escalado «⚡ Escalar Avería al SAT» con sugerencia
 * de técnico y aviso en el outbox, Coincidencia en Sede, relevo de turno y
 * volcado de conflicto offline (RF-02.1, RF-03.1, RF-03.2, RF-04.1, RF-04.2,
 * RF-05.1, D1–D4, C1–C2).
 *
 * El servicio no compone SQL ni decide el estado HTTP final: valida, delega y
 * enriquece. Las dependencias opcionales son callables para que la capa
 * hermética pueda certificarlo sin PostgreSQL:
 *
 *  - `technicianResolver(int $machineId, array $payload): ?array` devuelve la
 *    foto del técnico sugerido cuando el cliente no fuerza uno.
 *  - `notifier(string $event, array $payload): void` encola el aviso en el
 *    outbox sin acoplar el caso de uso a su implementación concreta.
 *
 * Dogma Vanilla: PHP 8.2+ puro, `final`, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockerSatSynergyService
{
    public const EVENT_ESCALATED_TO_SAT = 'RESTOCKER_ESCALATION_TO_SAT';

    /** @var callable(int, array<string, mixed>): (array<string, mixed>|null)|null */
    private $technicianResolver;

    /** @var callable(string, array<string, mixed>): void|null */
    private $notifier;

    public function __construct(
        private readonly RestockerSatSynergyGatewayInterface $gateway,
        ?callable $technicianResolver = null,
        ?callable $notifier = null
    ) {
        $this->technicianResolver = $technicianResolver;
        $this->notifier = $notifier;
    }

    /**
     * Despacha o conmuta el nivel operativo del expediente (RF-02.1).
     *
     * @param array<string, mixed> $incident
     * @param array<string, mixed> $assignee
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function dispatchLevel(array $incident, string $targetLevel, array $assignee, array $actor): array
    {
        $level = IncidentDispatchLevel::tryFrom(strtoupper(trim($targetLevel)));
        if ($level === null) {
            throw new InvalidArgumentException('El nivel de despacho solicitado no existe.');
        }

        $category = IncidentCategory::tryFrom((string)($incident['category'] ?? ''));
        if ($category === null) {
            throw new InvalidArgumentException('El expediente no declara una categoría válida.');
        }

        if ($level->isLevel1()) {
            RestockerSatSynergyValidator::assertExplicitLevel1Allowed($category);
        }

        RestockerSatSynergyValidator::assertAssigneeRole(
            $level,
            (string)($assignee['role'] ?? ''),
            (bool)($assignee['is_active'] ?? true)
        );
        RestockerSatSynergyValidator::assertNotPendingParts($level);

        return $this->gateway->assignDispatchLevel($incident, $level->value, $assignee, $actor);
    }

    /**
     * Inicio del aviso L1 asignado al reponedor (EARS 03.1, C2).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>
     */
    public function startLevel1(int $incidentId, array $actor, ?array $mutation = null): array
    {
        if ($incidentId <= 0) {
            throw new InvalidArgumentException('El expediente del inicio L1 no es válido.');
        }

        return $this->gateway->startLevel1($incidentId, $actor, $mutation);
    }

    /**
     * Resolución in situ del aviso L1 (EARS 03.1, QA-CONST-01).
     *
     * @param array<string, mixed> $incident
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $resolution
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>
     */
    public function resolveLevel1(
        array $incident,
        array $actor,
        array $resolution,
        ?array $mutation = null
    ): array {
        $incidentId = (int)($incident['id'] ?? 0);
        if ($incidentId <= 0) {
            throw new InvalidArgumentException('El expediente de la resolución L1 no es válido.');
        }

        RestockerSatSynergyValidator::assertLevel1ResolutionAllowed(
            isset($incident['sanitary_status']) ? (string)$incident['sanitary_status'] : null,
            (int)($incident['machine_id'] ?? 0)
        );
        RestockerSatSynergyValidator::assertNotPendingParts(IncidentDispatchLevel::L1_RESTOCKER);

        $texts = RestockerSatSynergyValidator::assertResolutionTexts(
            isset($resolution['diagnosis']) ? (string)$resolution['diagnosis'] : null,
            isset($resolution['action']) ? (string)$resolution['action'] : null
        );
        $resolution['diagnosis'] = $texts['diagnosis'];
        $resolution['action'] = $texts['action'];

        return $this->gateway->resolveLevel1($incidentId, $actor, $resolution, $mutation);
    }

    /**
     * Escalado «⚡ Escalar Avería al SAT» (RF-03.2, QA-CONST-02).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>
     */
    public function escalateToSat(
        int $machineId,
        array $actor,
        array $payload,
        ?array $mutation = null
    ): array {
        if ($machineId <= 0) {
            throw new InvalidArgumentException('La máquina del escalado no es válida.');
        }

        $payload['internal_note'] = RestockerSatSynergyValidator::assertEscalationInternalNote(
            isset($payload['internal_note']) ? (string)$payload['internal_note'] : null
        );
        RestockerSatSynergyValidator::assertEscalationPhoto(
            isset($payload['photo_path']) ? (string)$payload['photo_path'] : null,
            isset($payload['photo_size_bytes']) ? (int)$payload['photo_size_bytes'] : null,
            isset($payload['photo_mime_type']) ? (string)$payload['photo_mime_type'] : null
        );

        if ((int)($payload['assign_technician_id'] ?? 0) <= 0 && $this->technicianResolver !== null) {
            $technician = ($this->technicianResolver)($machineId, $payload);
            if (is_array($technician) && (int)($technician['id'] ?? 0) > 0) {
                $payload['assign_technician_id'] = (int)$technician['id'];
                $payload['suggested_technician'] = $technician;
            }
        }

        $result = $this->gateway->escalateToSat($machineId, $actor, $payload, $mutation);
        // C2: un reenvío idempotente ya encoló su aviso la primera vez; repetirlo
        // duplicaría la notificación al técnico y al reponedor.
        if (($result['ok'] ?? false) === true
            && ($result['idempotent_replay'] ?? false) !== true
            && $this->notifier !== null) {
            ($this->notifier)(self::EVENT_ESCALATED_TO_SAT, $result);
        }

        return $result;
    }

    /**
     * Reponedores recomendados con su carga L1 (RF-02.1).
     *
     * @return array<string, mixed>
     */
    public function suggestRestockers(int $incidentId): array
    {
        return $this->gateway->suggestRestockers($incidentId);
    }

    /**
     * Coincidencia en Sede según el rol consultante (D4, Art. V.4).
     *
     * @param list<int> $locationIds
     * @return array<string, mixed>
     */
    public function siteCoincidence(string $role, int $userId, array $locationIds): array
    {
        $normalized = strtoupper(trim($role));
        if ($normalized === 'TECHNICIAN') {
            return $this->gateway->technicianCoincidences($userId, $locationIds);
        }
        if ($normalized === 'RESTOCKER') {
            return $this->gateway->restockerCoincidences($userId, $locationIds);
        }

        throw new InvalidArgumentException('La Coincidencia en Sede solo está disponible para reponedores y técnicos.');
    }

    /**
     * Relevo automático de un L1 asignado (C1).
     *
     * @return array<string, mixed>
     */
    public function handoverLevel1(int $restockerId, string $cause, string $reason): array
    {
        if ($restockerId <= 0) {
            throw new InvalidArgumentException('El reponedor del relevo no es válido.');
        }

        return $this->gateway->handoverLevel1($restockerId, strtoupper(trim($cause)), trim($reason));
    }

    /**
     * Volcado de conflicto de una mutación offline rechazada (RF-05.1.2, Art. III.3).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $mutation
     * @param array<string, mixed> $conflict
     * @return array<string, mixed>
     */
    public function dumpOfflineConflict(array $actor, array $mutation, array $conflict): array
    {
        if (trim((string)($mutation['client_mutation_id'] ?? '')) === '') {
            throw new InvalidArgumentException('El volcado offline exige client_mutation_id.');
        }

        return $this->gateway->recordConflictDump($actor, $mutation, $conflict);
    }
}
