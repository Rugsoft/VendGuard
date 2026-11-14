<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use VendGuard\Application\Service\IncidentTicketAllocator;
use VendGuard\Application\Service\NotificationOutboxEnqueuerService;
use VendGuard\Application\Service\SuggestedTechniciansService;
use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Repository\InSituRestockVisitGatewayInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoInSituRestockVisitGateway
 *
 * Implementación PDO del puerto de contexto y efectos sanitarios de la visita de
 * reposición in situ (Módulo 34, RF-02.1–RF-02.3, RF-03.3, C2, QA 2, Art. II).
 *
 * Decisiones:
 *   1. **Lecturas bloqueadas.** La máquina y sus carriles se leen con `FOR UPDATE`
 *      dentro de la transacción de la visita, de modo que el cerrojo territorial,
 *      el estado sanitario y las existencias no cambian entre validar y escribir.
 *   2. **OUT_OF_SERVICE real.** El esquema no tiene un estado funcional
 *      `OUT_OF_SERVICE`: se materializa con `is_blocked_no_access`, el mismo
 *      predicado que usa el Módulo 33. Las máquinas refrigeradas de perecederos y
 *      mixtas pasan a `sanitary_status = 'QUARANTINE'`.
 *   3. **Ticket térmico idempotente (`QA 2`).** Si hay incidencia activa se eleva a
 *      `CRITICAL` y se anexa una nota interna con temperatura, foto y notas; si no,
 *      se crea una incidencia térmica `REGISTERED` con la política única de códigos.
 *      La foto se guarda en el expediente y en la nota interna (Art. V.4).
 *   4. **Asignación automática (`RF-02.2`).** El ticket nuevo se entrega al primer
 *      candidato del recomendador si está disponible o es un técnico de guardia
 *      fuera de turno; si no hay candidato utilizable permanece `REGISTERED`. La
 *      asignación usa la cadena de custodia existente y viaja dentro de la misma
 *      transacción de la visita. La fuente de custodia es la ya existente
 *      `COORDINATOR_ASSIGN`: el sistema actúa en nombre de Coordinación y no se
 *      inventa un valor fuera del enum del motor.
 *   5. **Coste interno.** `unit_cost_eur` solo se lee para congelar el snapshot de
 *      la línea; este método no forma parte de ninguna proyección del reponedor
 *      (Art. V.4).
 */
final class PdoInSituRestockVisitGateway implements InSituRestockVisitGatewayInterface
{
    private PDO $pdo;

    private IncidentRepositoryInterface $incidents;

    private IncidentTicketAllocator $tickets;

    private ?NotificationOutboxEnqueuerService $notifications;

    private SuggestedTechniciansService $suggestions;

    public function __construct(
        ?PDO $pdo = null,
        ?IncidentRepositoryInterface $incidents = null,
        ?IncidentTicketAllocator $tickets = null,
        ?NotificationOutboxEnqueuerService $notifications = null,
        ?SuggestedTechniciansService $suggestions = null
    ) {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->incidents = $incidents ?? new PdoIncidentRepository($this->pdo);
        $this->tickets = $tickets ?? new IncidentTicketAllocator();
        $this->notifications = $notifications;
        $this->suggestions = $suggestions
            ?? new SuggestedTechniciansService(new PdoTechnicianSuggestionRepository($this->pdo));
    }

    /**
     * {@inheritdoc}
     */
    public function lockMachineContext(int $machineId, int $restockerId): ?array
    {
        if ($machineId <= 0 || $restockerId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                m.id,
                m.code AS machine_code,
                m.machine_type,
                m.location_id,
                m.sanitary_status,
                m.is_blocked_no_access,
                EXISTS (
                    SELECT 1
                    FROM restocker_zone_assignments rza
                    INNER JOIN operational_zones oz ON oz.id = rza.zone_id
                    WHERE rza.restocker_id = :restocker_id
                      AND rza.zone_id = l.zone_id
                      AND rza.removed_at IS NULL
                      AND oz.is_active = TRUE
                ) AS is_covered,
                (
                    SELECT i.id
                    FROM incidents i
                    WHERE i.machine_id = m.id
                      AND i.deleted_at IS NULL
                      AND i.status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')
                    ORDER BY i.id DESC
                    LIMIT 1
                ) AS active_incident_id,
                EXISTS (
                    SELECT 1
                    FROM incidents i
                    WHERE i.machine_id = m.id
                      AND i.deleted_at IS NULL
                      AND i.status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')
                      AND i.category = 'TEMPERATURE_COLD'
                ) AS has_open_thermal_breakdown,
                (
                    SELECT o.id
                    FROM product_loading_orders o
                    WHERE o.restocker_id = :restocker_id
                      AND o.status = 'CONFIRMED_BY_RESTOCKER'
                    ORDER BY o.id DESC
                    LIMIT 1
                ) AS loading_order_id
            FROM machines m
            INNER JOIN locations l ON l.id = m.location_id
            WHERE m.id = :machine_id
              AND m.is_active = TRUE
              AND m.deleted_at IS NULL
            FOR UPDATE OF m
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $machineType = MachineType::tryFrom((string)$row['machine_type']);
        if ($machineType === null) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'machine_code' => (string)$row['machine_code'],
            'machine_type' => $machineType,
            'location_id' => (int)$row['location_id'],
            'sanitary_status' => (string)$row['sanitary_status'],
            'is_out_of_service' => self::toBool($row['is_blocked_no_access']),
            'is_covered' => self::toBool($row['is_covered']),
            'has_open_thermal_breakdown' => self::toBool($row['has_open_thermal_breakdown']),
            'active_incident_id' => $row['active_incident_id'] !== null ? (int)$row['active_incident_id'] : null,
            'loading_order_id' => $row['loading_order_id'] !== null ? (int)$row['loading_order_id'] : null,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function lockActiveSlots(int $machineId): array
    {
        if ($machineId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT
                id,
                machine_id,
                slot_code,
                product_id,
                max_capacity,
                current_estimated_qty,
                min_threshold,
                is_active,
                current_batch_code,
                current_expiration_date,
                created_at,
                updated_at
            FROM machine_planogram_slots
            WHERE machine_id = :machine_id
              AND is_active = TRUE
            ORDER BY slot_code ASC
            FOR UPDATE
        ");
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        $slots = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $slots[] = new MachinePlanogramSlot(
                (int)$row['id'],
                (int)$row['machine_id'],
                (string)$row['slot_code'],
                (int)$row['product_id'],
                (int)$row['max_capacity'],
                (int)$row['current_estimated_qty'],
                (int)$row['min_threshold'],
                self::toBool($row['is_active']),
                $row['created_at'] !== null ? (string)$row['created_at'] : null,
                $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
                $row['current_batch_code'] !== null ? (string)$row['current_batch_code'] : null,
                self::parseDate($row['current_expiration_date'])
            );
        }

        return $slots;
    }

    /**
     * {@inheritdoc}
     */
    public function findProductUnitCost(int $productId): float
    {
        $stmt = $this->pdo->prepare('SELECT unit_cost_eur FROM vending_products WHERE id = :id');
        $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
        $stmt->execute();
        $cost = $stmt->fetchColumn();

        if ($cost === false || $cost === null) {
            throw new RuntimeException(sprintf('El producto %d no tiene coste unitario configurado (QA 3).', $productId));
        }

        return round((float)$cost, 2);
    }

    /**
     * {@inheritdoc}
     */
    public function lockMachineSanitarily(int $machineId, MachineType $machineType, string $reason): void
    {
        if ($machineType === MachineType::COLD_DRINKS) {
            $stmt = $this->pdo->prepare("
                UPDATE machines
                SET is_blocked_no_access = TRUE,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :machine_id
            ");
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE machines
                SET sanitary_status = 'QUARANTINE',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :machine_id
            ");
        }

        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() < 1) {
            throw new RuntimeException(sprintf('No se pudo bloquear sanitariamente la máquina %d: %s.', $machineId, $reason));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function resolveThermalIncident(
        int $machineId,
        string $machineCode,
        int $locationId,
        ?int $existingIncidentId,
        float $cabinTemp,
        string $photoUrl,
        string $notes,
        array $actor
    ): int {
        $actorId = isset($actor['id']) ? (int)$actor['id'] : null;
        $actorName = trim((string)($actor['name'] ?? 'Reposición in situ'));
        if ($actorName === '') {
            $actorName = 'Reposición in situ';
        }

        $description = sprintf(
            'Rotura de cadena de frío detectada en la visita in situ de la máquina %s: %.1f °C. %s',
            $machineCode,
            round($cabinTemp, 1),
            trim($notes)
        );
        $description = mb_substr($description, 0, 500, 'UTF-8');

        if ($existingIncidentId !== null) {
            $incident = $this->incidents->findById($existingIncidentId);
            if ($incident !== null) {
                $this->escalate($incident, $description, $photoUrl, $actorId, $actorName);

                return (int)$incident->getId();
            }
        }

        try {
            $created = $this->tickets->persistWithUniqueTicket(
                fn (string $ticketCode): Incident => $this->incidents->create(
                    new Incident(
                        null,
                        $ticketCode,
                        $machineId,
                        $locationId,
                        IncidentCategory::TEMPERATURE_COLD,
                        $description,
                        UrgencyLevel::CRITICAL,
                        IncidentStatus::REGISTERED,
                        null,
                        $actorName,
                        null,
                        null,
                        $photoUrl
                    ),
                    $actorId,
                    $description
                )
            );
        } catch (DuplicateIncidentException $conflict) {
            $existing = $this->incidents->findActiveByMachineId($machineId);
            if ($existing === null || $existing->getId() === null) {
                throw $conflict;
            }

            $this->escalate($existing, $description, $photoUrl, $actorId, $actorName);

            return (int)$existing->getId();
        }

        $incidentId = $created->getId();
        if ($incidentId === null) {
            throw new RuntimeException('El ticket térmico se creó sin devolver una clave primaria.');
        }

        $this->assignBestTechnician($created);

        // La alerta se compone con la incidencia ya asignada para que el bloque
        // técnico del outbox apunte al responsable real y no a la guardia genérica.
        $incidentForAlert = $this->incidents->findById((int)$incidentId) ?? $created;
        $this->notifications?->enqueueIncidentOpened($incidentForAlert);

        return (int)$incidentId;
    }

    /**
     * Entrega el ticket térmico al primer candidato utilizable del recomendador.
     *
     * El recomendador puede encabezar la lista con un técnico fuera de turno si
     * está de guardia (RF-08.5/R6); ese caso sí es asignable. Una ausencia activa
     * o una pausa de comida, en cambio, no lo son. Sin candidato utilizable el
     * ticket conserva el estado `REGISTERED` y el alta sigue siendo válida.
     */
    private function assignBestTechnician(Incident $incident): void
    {
        $incidentId = $incident->getId();
        if ($incidentId === null) {
            return;
        }

        $suggestion = $this->suggestions->getSuggestions((int)$incidentId);
        $top = $suggestion?->rankedTechnicians[0] ?? null;
        if (!is_array($top)) {
            return;
        }

        $technicianId = (int)($top['technician_id'] ?? 0);
        if ($technicianId <= 0 || !$this->isAssignable($top)) {
            return;
        }

        // `assign()` abre el tramo de custodia, sella `assigned_at` y mueve el
        // expediente a `ASSIGNED` dentro de la transacción de la visita. La fuente
        // por defecto es `COORDINATOR_ASSIGN`, el valor existente que representa
        // esta entrega en nombre de Coordinación.
        $this->incidents->assign((int)$incidentId, $technicianId);
    }

    /**
     * Un técnico es asignable si está disponible o si, estando de guardia, solo
     * está fuera de turno. La guardia cubre la emergencia; la ausencia y la pausa
     * no se puentean.
     *
     * @param array<string, mixed> $technician Fila del ranking del recomendador.
     */
    private function isAssignable(array $technician): bool
    {
        $currentStatus = is_array($technician['current_status'] ?? null)
            ? $technician['current_status']
            : [];

        if (($currentStatus['is_available'] ?? false) === true) {
            return true;
        }

        if (($technician['is_on_call'] ?? false) !== true) {
            return false;
        }

        $reason = $currentStatus['unavailability_reason'] ?? null;

        return $reason === null || $reason === 'Fuera de turno';
    }

    private function escalate(
        Incident $incident,
        string $description,
        string $photoUrl,
        ?int $actorId,
        string $actorName
    ): void {
        $incidentId = $incident->getId();
        if ($incidentId === null) {
            throw new RuntimeException('La incidencia activa no tiene identificador.');
        }

        $status = $incident->getStatus()->value;
        $escalated = $incident->withUrgency(UrgencyLevel::CRITICAL);

        if (!$this->incidents->update($escalated)) {
            throw new RuntimeException(sprintf('No se pudo escalar la incidencia %d a CRITICAL.', $incidentId));
        }

        $this->incidents->insertHistory(
            $incidentId,
            $actorId,
            $status,
            $status,
            'ESCALATED_TO_CRITICAL_BY_IN_SITU_THERMAL_BREACH: ' . $description
        );

        $this->incidents->addComment(new IncidentComment(
            null,
            $incidentId,
            'SYSTEM',
            $actorId,
            $actorName,
            $description,
            $photoUrl !== '' ? $photoUrl : null,
            true
        ));

        $this->notifications?->enqueueColdChainAlert($escalated);
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 't', 'true', 'y', 'yes'], true);
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)$value, 0, 10));

        return $date === false ? null : $date;
    }
}
