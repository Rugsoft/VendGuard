<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use VendGuard\Application\DTO\AssumeOpportunisticIncidentDto;
use VendGuard\Application\DTO\CreateOpportunisticIncidentDto;
use VendGuard\Application\DTO\OpportunisticMachineCatalogDto;
use VendGuard\Core\Domain\Exception\CashRefundPrivacyViolationException;
use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Exception\IncidentAlreadyInProgressByOtherTechnicianException;
use VendGuard\Core\Domain\Exception\IncidentNotFoundException;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\Exception\LocationNotFoundException;
use VendGuard\Core\Domain\Exception\MachineInactiveException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Exception\TakeoverConfirmationRequiredException;
use VendGuard\Core\Domain\Exception\WarrantyExpiredException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\OpportunisticIncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\OpportunisticMode;
use VendGuard\Core\Domain\ValueObject\OpportunisticOriginReason;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Service\OpportunisticCoexistencePolicy;
use VendGuard\Core\Service\OpportunisticColdChainGuardPolicy;
use VendGuard\Core\Service\OpportunisticLocationEligibilityPolicy;
use VendGuard\Core\Service\UrgencyCalculator;

/**
 * OpportunisticIncidentService — caso de uso orquestador de la **Avería
 * Oportunista In Situ** (Módulo 16, RF-01 a RF-06).
 *
 * Es la única puerta por la que la operación móvil convierte «el técnico está
 * delante de una máquina» en un hecho de negocio. Coordina, en una sola unidad
 * de trabajo, cuatro familias de decisiones que ninguna pieza decide sola:
 *
 * 1. **Consultar el catálogo de la sede** (`getLocationOpportunisticCatalog`):
 *    revalida la habilitación con `OpportunisticLocationEligibilityPolicy`,
 *    clasifica cada máquina con su acción exacta, publica el conflicto de
 *    coexistencia de otra sede (RF-02.2) y la advertencia sanitaria de ruta
 *    (RF-03.1). Es una lectura: no escribe ni transiciona nada.
 * 2. **Alta Oportunista** (`createOpportunisticIncident`, Flujo A, RF-04):
 *    rechaza de entrada cualquier campo bancario del Art. V.4, valida la
 *    coherencia modalidad ↔ submotivo, calcula la urgencia con
 *    `UrgencyCalculator` —la regla sanitaria del Art. II incluida—, abre el
 *    expediente `NEW` directamente en `IN_PROGRESS` y, si la máquina acaba de
 *    recibir un ticket concurrente, devuelve `409
 *    ACTIVE_INCIDENT_ALREADY_EXISTS_ASSUME_INSTEAD` con el expediente existente
 *    para asumirlo de un toque.
 * 3. **Asunción y reapertura** (`assumeOpportunisticIncident`, Flujo B, RF-05):
 *    decide la rama exacta por estado y titular —asunción directa, traspaso con
 *    confirmación y nota interna, reanudación propia, reapertura en garantía o
 *    sustitución atómica por Avería Crónica en la 3.ª reincidencia— y encola los
 *    avisos que la matriz de RF-06.3 permite, sin correos redundantes.
 * 4. **Auditoría** (Art. III): cada desenlace se firma en `audit_log` con el
 *    vocabulario cerrado de `AuditLogger::logOpportunisticEvent()`, dentro de la
 *    misma transacción que el cambio de estado.
 *
 * FRONTERA CON LOS REPOSITORIOS
 *
 *   Las primitivas de `OpportunisticIncidentRepositoryInterface` ya toman el
 *   expediente con bloqueo pesimista `FOR UPDATE` y escriben su historial; este
 *   servicio **no reimplementa esas transiciones**, las elige. La habilitación,
 *   la coexistencia y la salvaguarda sanitaria se deciden en las políticas puras
 *   de `Core\Service`, y aquí solo se aplican en el orden correcto antes de
 *   persistir (Fail-Fast, AGENTS.md §4.3).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de puertos, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class OpportunisticIncidentService
{
    /** Garantía legal de reapertura in situ (Art. V.6). */
    private const WARRANTY_WINDOW_HOURS = 48;

    /** Reincidencias que hacen crónica la siguiente reapertura (RF-05.4.2). */
    private const CHRONIC_REOPEN_THRESHOLD = 2;

    /**
     * Bandas de vencimiento contractual por urgencia.
     *
     * El ejemplo del contrato técnico (§4.3) fija un `HIGH` a +8 h y el umbral
     * de frío del Art. II usa 4 h para `CRITICAL` (el mismo valor que
     * `KpiSummary` y `CoordinatorIncidentDetailService`). Las bandas media y baja
     * siguen el Manual 0 (24-48 h).
     *
     * @var array<string, int>
     */
    private const SLA_HOURS_BY_URGENCY = [
        'CRITICAL' => 4,
        'HIGH' => 8,
        'MEDIUM' => 24,
        'LOW' => 48,
    ];

    private OpportunisticIncidentRepositoryInterface $opportunistic;
    private MachineRepositoryInterface $machines;
    private LocationRepositoryInterface $locations;
    private IncidentRepositoryInterface $incidents;
    private TransactionManagerInterface $transactions;
    private AuditLogger $auditLogger;
    private ?NotificationOutboxEnqueuerService $notifications;
    private IncidentPauseService $pauses;
    private OpportunisticLocationEligibilityPolicy $eligibility;
    private OpportunisticCoexistencePolicy $coexistence;
    private OpportunisticColdChainGuardPolicy $coldChain;

    public function __construct(
        OpportunisticIncidentRepositoryInterface $opportunistic,
        MachineRepositoryInterface $machines,
        LocationRepositoryInterface $locations,
        IncidentRepositoryInterface $incidents,
        TransactionManagerInterface $transactions,
        AuditLogger $auditLogger,
        ?NotificationOutboxEnqueuerService $notifications = null,
        ?IncidentPauseService $pauses = null,
        ?OpportunisticLocationEligibilityPolicy $eligibility = null,
        ?OpportunisticCoexistencePolicy $coexistence = null,
        ?OpportunisticColdChainGuardPolicy $coldChain = null
    ) {
        $this->opportunistic = $opportunistic;
        $this->machines = $machines;
        $this->locations = $locations;
        $this->incidents = $incidents;
        $this->transactions = $transactions;
        $this->auditLogger = $auditLogger;
        $this->notifications = $notifications;
        $this->pauses = $pauses ?? new IncidentPauseService();
        $this->eligibility = $eligibility ?? new OpportunisticLocationEligibilityPolicy();
        $this->coexistence = $coexistence ?? new OpportunisticCoexistencePolicy();
        $this->coldChain = $coldChain ?? new OpportunisticColdChainGuardPolicy();
    }

    /**
     * Catálogo de máquinas operativas de una sede habilitada (`GET
     * /api/technician/locations/{location_id}/opportunistic-machines`, §4.2).
     *
     * @param int $technicianId Técnico autenticado.
     * @param int $locationId Sede consultada.
     * @param bool $formOpenedWithinGrace Si el formulario ya estaba abierto (margen de 35 min).
     * @param DateTimeImmutable|null $now Instante de la petición (inyectable en pruebas).
     *
     * @throws LocationNotFoundException Si la sede no existe o está inactiva.
     * @throws \VendGuard\Core\Domain\Exception\LocationNotEnabledForOpportunisticException Si no está habilitada (403).
     */
    public function getLocationOpportunisticCatalog(
        int $technicianId,
        int $locationId,
        bool $formOpenedWithinGrace = false,
        ?DateTimeImmutable $now = null
    ): OpportunisticMachineCatalogDto {
        if ($technicianId < 1 || $locationId < 1) {
            throw new InvalidArgumentException(
                'El catálogo oportunista exige un técnico y una sede persistidos (identificadores positivos).'
            );
        }

        $now ??= new DateTimeImmutable('now');

        $location = $this->locations->findById($locationId);
        if ($location === null || !$location->isActive() || $location->isSoftDeleted()) {
            throw new LocationNotFoundException(
                $locationId,
                'La sede indicada no existe o está inactiva.'
            );
        }

        $decision = $this->requireEligibility($technicianId, $locationId, $formOpenedWithinGrace, $now);

        $machines = array_map(
            static fn (array $machine): array => OpportunisticMachineCatalogDto::decorateMachine($machine, $technicianId),
            $this->opportunistic->findLocationMachineCatalog($locationId, $now)
        );

        $conflict = $this->coexistence->findBlockingIntervention(
            $locationId,
            $this->opportunistic->findActiveInterventionsInOtherLocations($technicianId, $locationId)
        );

        $pendingCriticalStops = $this->coldChain->findPendingCriticalStops(
            $locationId,
            $this->opportunistic->findPendingCriticalStopsInOtherLocations($technicianId, $locationId)
        );

        return new OpportunisticMachineCatalogDto(
            location: [
                'id' => $location->getId(),
                'site_code' => $location->getSiteCode(),
                'name' => $location->getName(),
                'address' => $location->getAddress(),
                'enablement_type' => $decision['enablement_type'],
                'grace_expires_at' => $decision['grace_expires_at'],
                'grace_remaining_seconds' => $decision['grace_remaining_seconds'],
            ],
            machines: $machines,
            activeInterventionConflict: $conflict,
            coldChainRouteWarning: [
                'requires_confirmation' => $pendingCriticalStops !== [],
                'pending_critical_stops' => array_map(
                    $this->contractPendingStop(...),
                    $pendingCriticalStops
                ),
            ]
        );
    }

    /**
     * Flujo A · Alta Oportunista In Situ (`POST /api/technician/opportunistic/incidents`, §4.3).
     *
     * El rechazo de datos bancarios se aplica sobre el **payload crudo** y antes
     * de cualquier lectura: un `iban` vacío también es un intento de abrir un
     * canal que el Art. V.4 prohíbe.
     *
     * @param User $technician Técnico presente en la sede.
     * @param array<string, mixed> $payload Cuerpo crudo de la petición.
     * @param DateTimeImmutable|null $now Instante de la operación (inyectable en pruebas).
     * @return array<string, mixed> Proyección del expediente creado para la respuesta `201`.
     *
     * @throws CashRefundPrivacyViolationException Si el parte incluye campos bancarios (422).
     * @throws InvalidArgumentException Si un campo obligatorio falta o es inválido (422).
     * @throws MachineNotFoundException Si la máquina no existe (404).
     * @throws MachineInactiveException Si la máquina está dada de baja definitiva (422).
     * @throws \VendGuard\Core\Domain\Exception\LocationNotEnabledForOpportunisticException Si la sede no está habilitada (403).
     * @throws \VendGuard\Core\Domain\Exception\ActiveInterventionConflictException Si hay trabajo abierto en otra sede (409).
     * @throws \VendGuard\Core\Domain\Exception\ColdChainRouteWarningConfirmationException Si falta la confirmación sanitaria (409).
     * @throws DuplicateIncidentException Si la máquina acaba de recibir otro expediente (409).
     */
    public function createOpportunisticIncident(
        User $technician,
        array $payload,
        ?DateTimeImmutable $now = null
    ): array {
        $this->assertNoFinancialFields($payload);
        $dto = CreateOpportunisticIncidentDto::fromPayload($payload);
        $now ??= new DateTimeImmutable('now');

        return $this->transactions->runInTransaction(function () use ($technician, $dto, $now): array {
            $machine = $this->machines->findById($dto->machineId, false);
            if ($machine === null || $machine->isSoftDeleted()) {
                throw new MachineNotFoundException(
                    'MACHINE_NOT_FOUND',
                    'La máquina indicada no existe en el parque de la sede.'
                );
            }

            if (!$machine->isActive() && !$machine->isBlockedNoAccess()) {
                throw new MachineInactiveException($machine->getId());
            }

            $locationId = $machine->getLocationId();
            $this->requireEligibility($technician->getId(), $locationId, $dto->formOpenedWithinGrace, $now);
            $this->assertCoexistenceAvailable($technician->getId(), $locationId);
            $flaggedStops = $this->resolveColdChainWarning(
                $technician->getId(),
                $locationId,
                $dto->confirmColdChainOverride
            );

            $location = $this->locations->findById($locationId);
            $urgency = UrgencyCalculator::calculate($machine->getMachineType(), $dto->category);
            $stamp = $now->format('Y-m-d\TH:i:sP');
            $informantName = $dto->effectiveInformantName();

            $incident = new Incident(
                id: null,
                ticketCode: TicketCode::generate()->value(),
                machineId: $machine->getId(),
                locationId: $locationId,
                category: $dto->category,
                description: $dto->normalizedDescription(),
                urgency: $urgency,
                status: IncidentStatus::IN_PROGRESS,
                assignedTechnicianId: $technician->getId(),
                reporterName: $informantName ?? $technician->getName(),
                photoPath: $dto->photoPath,
                assignedAt: $stamp,
                startedAt: $stamp,
                cycleStartedAt: $stamp,
                slaTargetAt: $this->slaTargetFor($urgency, $now),
                opportunisticMode: OpportunisticMode::NEW,
                opportunisticOriginReason: $dto->originReason,
                opportunisticInformantName: $informantName
            );

            try {
                $created = $this->opportunistic->createOpportunisticIncident(
                    $incident,
                    $technician->getId(),
                    $dto->initialHistoryNote()
                );
            } catch (DuplicateIncidentException $conflict) {
                // Caso límite #4: otro canal abrió el ticket entre la lectura del
                // catálogo y esta escritura. La respuesta no es un error seco: el
                // cliente recibe el expediente para asumirlo con un toque.
                $existing = $this->incidents->findActiveByMachineId($dto->machineId);

                throw new DuplicateIncidentException(
                    'ACTIVE_INCIDENT_ALREADY_EXISTS_ASSUME_INSTEAD',
                    sprintf(
                        'La máquina %s acaba de recibir un expediente activo (%s). Asímalo in situ para intervenir en el acto.',
                        $machine->getCode(),
                        (string)$conflict->getTicketCode()
                    ),
                    $conflict->getTicketCode(),
                    $conflict->getTicketStatus(),
                    409,
                    $existing !== null
                        ? [
                            'existing_incident' => $existing->toArray(),
                            'opportunistic_action' => OpportunisticMachineCatalogDto::classifyAction(
                                $existing->toArray(),
                                $technician->getId()
                            )->value,
                        ]
                        : []
                );
            }

            $this->auditLogger->logOpportunisticEvent(
                ticketId: (int)$created->getId(),
                action: 'INCIDENT_CREATED_OPPORTUNISTIC',
                user: $this->actorFor($technician),
                previousState: null,
                newState: [
                    'status' => IncidentStatus::IN_PROGRESS->value,
                    'opportunistic_mode' => OpportunisticMode::NEW->value,
                    'opportunistic_origin_reason' => $dto->originReason->value,
                ],
                metadata: [
                    'ticket_code' => $created->getTicketCode(),
                    'machine_id' => $machine->getId(),
                    'machine_code' => $machine->getCode(),
                    'location_id' => $locationId,
                    'urgency' => $urgency->value,
                    'category' => $dto->category->value,
                    'cold_chain_override_confirmed' => $flaggedStops !== [],
                ]
            );

            if ($dto->confirmColdChainOverride && $flaggedStops !== []) {
                $this->auditLogger->logOpportunisticEvent(
                    ticketId: (int)$created->getId(),
                    action: 'COLD_CHAIN_ROUTE_POSTPONED_CONFIRMED',
                    user: $this->actorFor($technician),
                    previousState: null,
                    newState: ['status' => IncidentStatus::IN_PROGRESS->value],
                    metadata: [
                        'postponed_stops_count' => count($flaggedStops),
                        'postponed_tickets' => array_map(
                            static fn (array $stop): string => (string)($stop['ticket_code'] ?? ''),
                            $flaggedStops
                        ),
                        'location_id' => $locationId,
                    ]
                );
            }

            $this->notifications?->enqueueIncidentOpened($created, $machine, $location);
            $this->alertIfColdChainPostponed($technician, $created, $flaggedStops);

            return [
                'id' => $created->getId(),
                'ticket_code' => $created->getTicketCode(),
                'status' => $created->getStatus()->value,
                'urgency' => $created->getUrgency()->value,
                'category' => $created->getCategory()->value,
                'description' => $created->getDescription(),
                'machine_id' => $created->getMachineId(),
                'machine_code' => $machine->getCode(),
                'location_id' => $locationId,
                'assigned_technician_id' => $created->getAssignedTechnicianId(),
                'opportunistic_mode' => OpportunisticMode::NEW->value,
                'opportunistic_mode_label' => OpportunisticMode::NEW->label(),
                'opportunistic_origin_reason' => $dto->originReason->value,
                'opportunistic_origin_label' => $dto->originReason->label(),
                'opportunistic_informant_name' => $created->getOpportunisticInformantName(),
                'assigned_at' => $created->getAssignedAt(),
                'started_at' => $created->getStartedAt(),
                'sla_target_at' => $created->getSlaTargetAt(),
                'cold_chain_override_alerted' => $flaggedStops !== [],
            ];
        });
    }

    /**
     * Flujo B · Asunción / Reapertura Oportunista In Situ (`POST
     * /api/technician/opportunistic/incidents/{id}/assume`, §4.4).
     *
     * @param User $technician Técnico presente en la sede.
     * @param int $incidentId Expediente a asumir o reabrir.
     * @param array<string, mixed> $payload Cuerpo crudo de la petición.
     * @param DateTimeImmutable|null $now Instante de la operación (inyectable en pruebas).
     * @return array<string, mixed> Proyección del expediente resultante (o de su sucesor crónico).
     *
     * @throws CashRefundPrivacyViolationException Si el cuerpo incluye campos bancarios (422).
     * @throws InvalidArgumentException Si falta una confirmación o un motivo obligatorio (422).
     * @throws IncidentNotFoundException Si el expediente no existe (404).
     * @throws MachineInactiveException Si la máquina está dada de baja definitiva (422).
     * @throws \VendGuard\Core\Domain\Exception\LocationNotEnabledForOpportunisticException Si la sede no está habilitada (403).
     * @throws \VendGuard\Core\Domain\Exception\ActiveInterventionConflictException Si hay trabajo abierto en otra sede (409).
     * @throws \VendGuard\Core\Domain\Exception\ColdChainRouteWarningConfirmationException Si falta la confirmación sanitaria (409).
     * @throws TakeoverConfirmationRequiredException Si el expediente es de otro técnico y no se confirmó (409).
     * @throws InvalidTransitionException Si el estado no admite asunción (409/422).
     * @throws WarrantyExpiredException Si la garantía de 48 h expiró (422).
     */
    public function assumeOpportunisticIncident(
        User $technician,
        int $incidentId,
        array $payload,
        ?DateTimeImmutable $now = null
    ): array {
        $this->assertNoFinancialFields($payload);
        $dto = AssumeOpportunisticIncidentDto::fromPayload($payload);
        $now ??= new DateTimeImmutable('now');

        return $this->transactions->runInTransaction(function () use ($technician, $incidentId, $dto, $now): array {
            $incident = $this->incidents->findById($incidentId);
            if ($incident === null) {
                throw new IncidentNotFoundException($incidentId);
            }

            $machine = $this->machines->findById($incident->getMachineId(), false);
            if ($machine === null || $machine->isSoftDeleted()) {
                throw new MachineNotFoundException(
                    'MACHINE_NOT_FOUND',
                    'La máquina del expediente indicado ya no existe en el parque.'
                );
            }

            if (!$machine->isActive() && !$machine->isBlockedNoAccess()) {
                throw new MachineInactiveException($machine->getId());
            }

            $locationId = $incident->getLocationId();
            $this->requireEligibility($technician->getId(), $locationId, $dto->formOpenedWithinGrace, $now);
            $this->assertCoexistenceAvailable($technician->getId(), $locationId);
            $flaggedStops = $this->resolveColdChainWarning(
                $technician->getId(),
                $locationId,
                $dto->confirmColdChainOverride
            );

            $location = $this->locations->findById($locationId);
            $status = $incident->getStatus();
            $previousTechnicianId = null;
            $chronicReplacementCreated = false;
            $predecessorTicketCode = null;

            if (in_array($status, [IncidentStatus::REGISTERED, IncidentStatus::REOPENED], true)) {
                $result = $this->claimWithoutTechnician($technician, $incident, $incidentId);
            } elseif (in_array($status, [
                IncidentStatus::ASSIGNED,
                IncidentStatus::PENDING_PARTS,
                IncidentStatus::PENDING_INFO,
            ], true)) {
                if ($incident->getAssignedTechnicianId() === $technician->getId()) {
                    $result = $this->resumeOwnStop($technician, $incident, $incidentId, $status, $now);
                } else {
                    $previousTechnicianId = $incident->getAssignedTechnicianId();
                    $result = $this->takeOverFromColleague($technician, $incident, $incidentId, $dto);
                }
            } elseif ($status === IncidentStatus::IN_PROGRESS
                && $incident->getAssignedTechnicianId() !== $technician->getId()
            ) {
                // Caso 5 (RF-05.5, Art. V.3): bloqueo absoluto. El compañero está
                // físicamente delante de la máquina; no existe confirmación que
                // legitime un arrebato silencioso.
                throw new IncidentAlreadyInProgressByOtherTechnicianException(
                    $incident->getAssignedTechnicianId(),
                    $incident->getTechnicianName()
                );
            } elseif ($status === IncidentStatus::RESOLVED) {
                $reopenCount = $this->incidents->countReopenEvents($incidentId);
                if ($reopenCount >= self::CHRONIC_REOPEN_THRESHOLD) {
                    $chronicReplacementCreated = true;
                    $predecessorTicketCode = $incident->getTicketCode();
                    $result = $this->replaceChronicBreakdown($technician, $incident, $machine, $dto, $now);
                } else {
                    $result = $this->reopenInWarranty($technician, $incident, $incidentId, $dto, $now);
                }
            } else {
                throw new InvalidTransitionException(
                    sprintf(
                        'El expediente %s está en estado %s y no admite una asunción oportunista in situ.',
                        $incident->getTicketCode(),
                        $status->value
                    ),
                    $status,
                    IncidentStatus::IN_PROGRESS
                );
            }

            /** @var Incident $resultIncident */
            $resultIncident = $result['incident'];

            if ($dto->confirmColdChainOverride && $flaggedStops !== []) {
                $this->auditLogger->logOpportunisticEvent(
                    ticketId: (int)$resultIncident->getId(),
                    action: 'COLD_CHAIN_ROUTE_POSTPONED_CONFIRMED',
                    user: $this->actorFor($technician),
                    previousState: ['status' => $status->value],
                    newState: ['status' => $resultIncident->getStatus()->value],
                    metadata: [
                        'postponed_stops_count' => count($flaggedStops),
                        'postponed_tickets' => array_map(
                            static fn (array $stop): string => (string)($stop['ticket_code'] ?? ''),
                            $flaggedStops
                        ),
                        'location_id' => $locationId,
                    ]
                );
            }

            $this->alertIfColdChainPostponed($technician, $resultIncident, $flaggedStops);

            if ($result['notification'] !== null) {
                ($result['notification'])();
            }

            return [
                'id' => $resultIncident->getId(),
                'ticket_code' => $resultIncident->getTicketCode(),
                'status' => $resultIncident->getStatus()->value,
                'urgency' => $resultIncident->getUrgency()->value,
                'category' => $resultIncident->getCategory()->value,
                'machine_id' => $resultIncident->getMachineId(),
                'machine_code' => $machine->getCode(),
                'location_id' => $resultIncident->getLocationId(),
                'assigned_technician_id' => $resultIncident->getAssignedTechnicianId(),
                'previous_technician_id' => $previousTechnicianId,
                'opportunistic_mode' => $resultIncident->getOpportunisticMode()?->value,
                'opportunistic_mode_label' => $resultIncident->getOpportunisticMode()?->label(),
                'opportunistic_origin_reason' => $resultIncident->getOpportunisticOriginReason()?->value,
                'opportunistic_origin_label' => $resultIncident->getOpportunisticOriginReason()?->label(),
                'chronic_replacement_created' => $chronicReplacementCreated,
                'chronic_predecessor_incident_id' => $resultIncident->getChronicPredecessorIncidentId(),
                'chronic_predecessor_ticket_code' => $predecessorTicketCode,
                'started_at' => $resultIncident->getStartedAt(),
                'cold_chain_override_alerted' => $flaggedStops !== [],
            ];
        });
    }

    /**
     * Caso 1 (RF-05.2): expediente `REGISTERED` / `REOPENED` sin técnico.
     *
     * @return array{incident: Incident, notification: ?callable}
     */
    private function claimWithoutTechnician(User $technician, Incident $incident, int $incidentId): array
    {
        $claimed = $this->opportunistic->claimOpportunisticIncident($incidentId, $technician->getId());

        $this->auditLogger->logOpportunisticEvent(
            ticketId: $incidentId,
            action: 'INCIDENT_ASSUMED_OPPORTUNISTIC',
            user: $this->actorFor($technician),
            previousState: ['status' => $incident->getStatus()->value],
            newState: [
                'status' => IncidentStatus::IN_PROGRESS->value,
                'opportunistic_mode' => OpportunisticMode::ASSUMED->value,
                'opportunistic_origin_reason' => OpportunisticOriginReason::EXISTING_TICKET_CLAIM->value,
            ],
            metadata: [
                'ticket_code' => $claimed->getTicketCode(),
                'takeover' => false,
                'site_notice_sent' => false,
            ]
        );

        // Asumir un ticket ya abierto no genera correo nuevo a la Sede (RF-06.3.1).
        return ['incident' => $claimed, 'notification' => null];
    }

    /**
     * Caso 2 (RF-05.2): parada propia de `ASSIGNED` / `PENDING_PARTS` / `PENDING_INFO`.
     *
     * Conserva la modalidad previa —una parada ordinaria asignada a este técnico
     * no se convierte en oportunista por empezarla in situ— y reanuda la pausa de
     * información con `IncidentPauseService`, que acumula los segundos y desplaza
     * el vencimiento contractual en horario comercial (RF-03.3).
     *
     * @return array{incident: Incident, notification: ?callable}
     */
    private function resumeOwnStop(
        User $technician,
        Incident $incident,
        int $incidentId,
        IncidentStatus $status,
        DateTimeImmutable $now
    ): array {
        if ($status === IncidentStatus::PENDING_INFO) {
            $this->pauses->resumePendingInfoManually(
                $incidentId,
                $technician->getId(),
                IncidentStatus::IN_PROGRESS,
                'Reanudación Oportunista In Situ: el técnico presente en la sede retoma su propia parada pausada.'
            );
            $resumed = $this->incidents->findById($incidentId);
        } else {
            $resumed = $this->incidents->startIntervention($incidentId, $technician->getId());
        }

        if ($resumed === null) {
            throw new IncidentNotFoundException($incidentId);
        }

        $this->auditLogger->logOpportunisticEvent(
            ticketId: $incidentId,
            action: 'INCIDENT_ASSUMED_OPPORTUNISTIC',
            user: $this->actorFor($technician),
            previousState: ['status' => $status->value],
            newState: ['status' => IncidentStatus::IN_PROGRESS->value],
            metadata: [
                'ticket_code' => $resumed->getTicketCode(),
                'takeover' => false,
                'own_stop_resumed' => true,
                'resumed_at' => $now->format('Y-m-d\TH:i:sP'),
                'site_notice_sent' => false,
            ]
        );

        return ['incident' => $resumed, 'notification' => null];
    }

    /**
     * Caso 3 (RF-05.3): expediente de otro compañero. Exige confirmación expresa,
     * guarda la nota de traspaso como interna y activa la matriz de
     * obsolescencia + desasignación solo por correo.
     *
     * @return array{incident: Incident, notification: ?callable}
     */
    private function takeOverFromColleague(
        User $technician,
        Incident $incident,
        int $incidentId,
        AssumeOpportunisticIncidentDto $dto
    ): array {
        if (!$dto->confirmTakeoverFromOtherTech) {
            throw new TakeoverConfirmationRequiredException(
                $incident->getAssignedTechnicianId(),
                $incident->getTechnicianName()
            );
        }

        $takeover = $this->opportunistic->takeOverOpportunisticIncident(
            $incidentId,
            $technician->getId(),
            $dto->effectiveTakeoverNote()
        );

        $previousTechnicianId = $takeover['previous_technician_id'];
        $taken = $takeover['incident'];

        $this->auditLogger->logOpportunisticEvent(
            ticketId: $incidentId,
            action: 'INCIDENT_TAKEOVER_OPPORTUNISTIC',
            user: $this->actorFor($technician),
            previousState: [
                'status' => $incident->getStatus()->value,
                'assigned_technician_id' => $previousTechnicianId,
            ],
            newState: [
                'status' => IncidentStatus::IN_PROGRESS->value,
                'assigned_technician_id' => $technician->getId(),
                'opportunistic_mode' => OpportunisticMode::ASSUMED->value,
                'opportunistic_origin_reason' => OpportunisticOriginReason::EXISTING_TICKET_CLAIM->value,
            ],
            metadata: [
                'ticket_code' => $taken->getTicketCode(),
                'takeover' => true,
                'internal_note_recorded' => $dto->effectiveTakeoverNote() !== null,
                'site_notice_sent' => false,
                'unassigned_notice_channel' => 'EMAIL',
            ]
        );

        $before = $incident;
        $notifications = $this->notifications;

        return [
            'incident' => $taken,
            'notification' => $notifications === null
                ? null
                : static function () use ($notifications, $before, $taken, $previousTechnicianId): void {
                    $notifications->enqueueInSituAssumption($before, $taken, $previousTechnicianId);
                },
        ];
    }

    /**
     * Caso 4A (RF-05.4.1): reapertura in situ en garantía, 1.ª o 2.ª reincidencia.
     *
     * @return array{incident: Incident, notification: ?callable}
     */
    private function reopenInWarranty(
        User $technician,
        Incident $incident,
        int $incidentId,
        AssumeOpportunisticIncidentDto $dto,
        DateTimeImmutable $now
    ): array {
        $this->assertWarrantyAlive($incident, $now);

        $reopenReason = $dto->effectiveReopenReason();
        if ($reopenReason === null) {
            throw new InvalidArgumentException(
                'La reapertura in situ en garantía exige el motivo de reincidencia (reopen_reason).'
            );
        }

        $reopenCountBefore = $this->incidents->countReopenEvents($incidentId);

        $reopened = $this->opportunistic->reopenOpportunisticIncident(
            $incidentId,
            $technician->getId(),
            $reopenReason,
            $now
        );

        $this->auditLogger->logOpportunisticEvent(
            ticketId: $incidentId,
            action: 'INCIDENT_REOPENED_IN_SITU',
            user: $this->actorFor($technician),
            previousState: ['status' => IncidentStatus::RESOLVED->value, 'resolved_at' => $incident->getResolvedAt()],
            newState: [
                'status' => IncidentStatus::IN_PROGRESS->value,
                'opportunistic_mode' => OpportunisticMode::ASSUMED->value,
                'opportunistic_origin_reason' => OpportunisticOriginReason::WARRANTY_REOPEN_IN_SITU->value,
            ],
            metadata: [
                'ticket_code' => $reopened->getTicketCode(),
                'reopen_reason' => $reopenReason,
                'reopen_count_before' => $reopenCountBefore,
                'site_notice_sent' => true,
            ]
        );

        $before = $incident;
        $notifications = $this->notifications;

        return [
            'incident' => $reopened,
            'notification' => $notifications === null
                ? null
                : static function () use ($notifications, $before, $reopened): void {
                    $notifications->enqueueInSituAssumption($before, $reopened);
                },
        ];
    }

    /**
     * Caso 4B (RF-05.4.2): 3.ª reincidencia · Avería Crónica.
     *
     * Cierra el predecesor con la marca `[AVERÍA CRÓNICA]` y abre el sucesor
     * limpio en `IN_PROGRESS` en la misma transacción (la primitiva garantiza que
     * nunca queda un hueco), alerta a Coordinación y avisa a la Sede del nuevo
     * expediente.
     *
     * @return array{incident: Incident, notification: ?callable}
     */
    private function replaceChronicBreakdown(
        User $technician,
        Incident $incident,
        Machine $machine,
        AssumeOpportunisticIncidentDto $dto,
        DateTimeImmutable $now
    ): array {
        $this->assertWarrantyAlive($incident, $now);

        $reopenReason = $dto->effectiveReopenReason();
        if ($reopenReason === null) {
            throw new InvalidArgumentException(
                'La sustitución por Avería Crónica exige el motivo de la tercera reincidencia (reopen_reason).'
            );
        }

        $urgency = UrgencyCalculator::calculate($machine->getMachineType(), $incident->getCategory());
        $stamp = $now->format('Y-m-d\TH:i:sP');

        $successor = new Incident(
            id: null,
            ticketCode: TicketCode::generate()->value(),
            machineId: $incident->getMachineId(),
            locationId: $incident->getLocationId(),
            category: $incident->getCategory(),
            description: $reopenReason,
            urgency: $urgency,
            status: IncidentStatus::IN_PROGRESS,
            assignedTechnicianId: $technician->getId(),
            reporterName: $incident->getReporterName() ?? $technician->getName(),
            photoPath: null,
            assignedAt: $stamp,
            startedAt: $stamp,
            cycleStartedAt: $stamp,
            slaTargetAt: $this->slaTargetFor($urgency, $now),
            opportunisticMode: OpportunisticMode::NEW,
            opportunisticOriginReason: OpportunisticOriginReason::WARRANTY_REOPEN_IN_SITU,
            chronicPredecessorIncidentId: (int)$incident->getId()
        );

        $created = $this->opportunistic->replaceChronicOpportunisticIncident(
            (int)$incident->getId(),
            $successor,
            $technician->getId(),
            $now
        );

        $this->auditLogger->logOpportunisticEvent(
            ticketId: (int)$incident->getId(),
            action: 'INCIDENT_CHRONIC_REPLACED',
            user: $this->actorFor($technician),
            previousState: ['status' => IncidentStatus::RESOLVED->value, 'resolved_at' => $incident->getResolvedAt()],
            newState: [
                'status' => IncidentStatus::CLOSED->value,
                // El cierre crónico sustituye, no resuelve: las piezas de la
                // resolución vigente viajan para que el validador del Art. V.1
                // deje firmar el hecho sin inventar un diagnóstico nuevo.
                'diagnosis' => $incident->getResolutionDiagnosis(),
                'solution' => $incident->getResolutionAction(),
                'opportunistic_mode' => OpportunisticMode::NEW->value,
                'opportunistic_origin_reason' => OpportunisticOriginReason::WARRANTY_REOPEN_IN_SITU->value,
            ],
            metadata: [
                'predecessor_ticket_code' => $incident->getTicketCode(),
                'successor_ticket_code' => $created->getTicketCode(),
                'reopen_reason' => $reopenReason,
                'reopen_count_before' => self::CHRONIC_REOPEN_THRESHOLD,
                'site_notice_sent' => true,
            ]
        );

        $predecessor = $incident;
        $notifications = $this->notifications;
        $location = $this->locations->findById($incident->getLocationId());

        return [
            'incident' => $created,
            'notification' => $notifications === null
                ? null
                : static function () use ($notifications, $predecessor, $created, $machine, $location): void {
                    $notifications->enqueueChronicBreakdown($predecessor);
                    $notifications->enqueueIncidentOpened($created, $machine, $location);
                },
        ];
    }

    /**
     * Puerta de habilitación de sede con el motivo de rechazo ya tipado (403).
     *
     * @return array{
     *     eligible: bool,
     *     enablement_type: ?string,
     *     grace_expires_at: ?string,
     *     grace_remaining_seconds: ?int,
     *     window_seconds: int,
     *     blocked_reason: ?string
     * }
     */
    private function requireEligibility(
        int $technicianId,
        int $locationId,
        bool $formOpenedWithinGrace,
        DateTimeImmutable $now
    ): array {
        $facts = $this->opportunistic->findLocationVisitFacts($technicianId, $locationId);

        return $this->eligibility->requireEligibility(
            $technicianId,
            $locationId,
            $now,
            is_array($facts['corrective_stops'] ?? null) ? $facts['corrective_stops'] : [],
            is_array($facts['preventive_orders'] ?? null) ? $facts['preventive_orders'] : [],
            $this->parseDateTime($facts['last_stop_exit_at'] ?? null),
            $formOpenedWithinGrace
        );
    }

    /**
     * Bloqueo de coexistencia entre sedes (RF-02.2, 409).
     */
    private function assertCoexistenceAvailable(int $technicianId, int $locationId): void
    {
        $this->coexistence->assertNoBlockingIntervention(
            $locationId,
            $this->opportunistic->findActiveInterventionsInOtherLocations($technicianId, $locationId)
        );
    }

    /**
     * Salvaguarda sanitaria del Art. II (RF-03.1): exige confirmación si hay una
     * parada crítica o de frío esperando en otra sede y devuelve las paradas que
     * dispararon la advertencia.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \VendGuard\Core\Domain\Exception\ColdChainRouteWarningConfirmationException Si falta la confirmación.
     */
    private function resolveColdChainWarning(
        int $technicianId,
        int $locationId,
        bool $confirmed
    ): array {
        return $this->coldChain->assertNoUnconfirmedWarning(
            $locationId,
            $this->opportunistic->findPendingCriticalStopsInOtherLocations($technicianId, $locationId),
            $confirmed
        );
    }

    /**
     * Encola la alerta a Coordinación cuando el técnico confirmó posponer una
     * parada crítica o sanitaria (RF-03.2.2) y firma ya la confirmación.
     *
     * @param list<array<string, mixed>> $flaggedStops
     */
    private function alertIfColdChainPostponed(User $technician, Incident $incident, array $flaggedStops): void
    {
        if ($flaggedStops === []) {
            return;
        }

        $postponed = array_map(
            static fn (array $stop): array => [
                'ticket_code' => (string)($stop['ticket_code'] ?? ''),
                'location_name' => (string)($stop['location_name'] ?? ''),
                'is_sanitary' => in_array(
                    (string)($stop['warning_trigger'] ?? ''),
                    [
                        OpportunisticColdChainGuardPolicy::TRIGGER_COLD_CHAIN_WATCH,
                        OpportunisticColdChainGuardPolicy::TRIGGER_SANITARY_QUARANTINE,
                    ],
                    true
                ),
            ],
            $flaggedStops
        );

        $this->notifications?->enqueueOpportunisticColdRoutePostponed($incident, $postponed);
    }

    /**
     * Garantía de 48 horas del Art. V.6 antes de reabrir o sustituir.
     *
     * @throws WarrantyExpiredException Si el expediente no está resuelto o superó la ventana.
     */
    private function assertWarrantyAlive(Incident $incident, DateTimeImmutable $now): void
    {
        if ($incident->getStatus() !== IncidentStatus::RESOLVED || $incident->getResolvedAt() === null) {
            throw new WarrantyExpiredException(
                'REOPEN_WINDOW_EXPIRED',
                'El expediente ya no está en la ventana de garantía de 48 horas.'
            );
        }

        $resolvedAt = $this->parseDateTime($incident->getResolvedAt());
        if ($resolvedAt === null
            || ($now->getTimestamp() - $resolvedAt->getTimestamp()) > self::WARRANTY_WINDOW_HOURS * 3600
        ) {
            throw new WarrantyExpiredException();
        }
    }

    /**
     * Rechazo del Art. V.4 sobre el payload crudo: los campos prohibidos se
     * detectan por presencia, aunque lleguen vacíos.
     *
     * @param array<string, mixed> $payload
     *
     * @throws CashRefundPrivacyViolationException
     */
    private function assertNoFinancialFields(array $payload): void
    {
        $violating = [];

        foreach (CashRefundPrivacyViolationException::FORBIDDEN_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $violating[] = $field;
            }
        }

        if ($violating !== []) {
            throw new CashRefundPrivacyViolationException($violating);
        }
    }

    /**
     * Vencimiento contractual del expediente recién abierto.
     */
    private function slaTargetFor(UrgencyLevel $urgency, DateTimeImmutable $now): string
    {
        $hours = self::SLA_HOURS_BY_URGENCY[$urgency->value] ?? self::SLA_HOURS_BY_URGENCY['MEDIUM'];

        return $now->modify('+' . $hours . ' hours')->format(DATE_ATOM);
    }

    /**
     * Actor de auditoría tal y como lo espera `AuditLogger`.
     *
     * @return array{id: int, role: string, name: string}
     */
    private function actorFor(User $technician): array
    {
        return [
            'id' => $technician->getId(),
            'role' => $technician->getRole()->value,
            'name' => $technician->getName(),
        ];
    }

    /**
     * Proyección contractual de una parada postergada (§4.2), sin claves internas.
     *
     * @param array<string, mixed> $stop
     * @return array<string, mixed>
     */
    private function contractPendingStop(array $stop): array
    {
        return [
            'incident_id' => isset($stop['incident_id']) ? (int)$stop['incident_id'] : (int)($stop['id'] ?? 0),
            'ticket_code' => (string)($stop['ticket_code'] ?? ''),
            'urgency' => (string)($stop['urgency'] ?? ''),
            'category' => (string)($stop['category'] ?? ''),
            'machine_code' => isset($stop['machine_code']) ? (string)$stop['machine_code'] : null,
            'machine_type' => (string)($stop['machine_type'] ?? ''),
            'location_id' => (int)($stop['location_id'] ?? 0),
            'location_name' => isset($stop['location_name']) ? (string)$stop['location_name'] : null,
            'sla_target_at' => isset($stop['sla_target_at']) ? (string)$stop['sla_target_at'] : null,
        ];
    }

    /**
     * Interpreta una marca temporal de base de datos o ISO-8601.
     */
    private function parseDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
