<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DomainException;
use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\IncompatibleSparePartException;
use VendGuard\Core\Domain\Exception\InvalidOutOfCatalogJustificationException;
use VendGuard\Core\Domain\Exception\InvalidPartQuantityException;
use VendGuard\Core\Domain\Exception\InvalidResolutionException;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\Exception\SparePartNotFoundException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentReplacedPart;
use VendGuard\Core\Domain\Model\OldPartDestination;
use VendGuard\Core\Domain\Model\SparePartRequest;
use VendGuard\Core\Domain\Model\SparePartRequestStatus;
use VendGuard\Core\Domain\Repository\IncidentReplacedPartRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRequestRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Service\ResolutionValidator;

/**
 * SparePartTraceabilityService
 * 
 * Servicio de Aplicación responsable de coordinar la trazabilidad integral de piezas de repuesto
 * durante las intervenciones técnicas de correctivo y preventivo (RF-REP-03 a RF-REP-07).
 * 
 * Garantiza:
 * 1. Pausa técnica estructurada por repuestos con validación de compatibilidad o justificación fuera de catálogo (Art. V.1).
 * 2. Resolución de intervenciones exigiendo declaración explícita de piezas sustituidas (Sí/No).
 * 3. Congelación inmutable del coste unitario mediante snapshot histórico protegido contra fluctuaciones futuras (Art. III).
 * 4. Actualización del ciclo de vida de solicitudes estructuradas (PENDING -> ATTENDED / CANCELLED).
 */
class SparePartTraceabilityService
{
    private SparePartRequestRepositoryInterface $requestRepo;
    private IncidentReplacedPartRepositoryInterface $replacedPartRepo;
    private SparePartRepositoryInterface $sparePartRepo;
    private IncidentRepositoryInterface $incidentRepo;
    private MachineRepositoryInterface $machineRepo;
    private ?AuditLogger $auditLogger;

    public function __construct(
        SparePartRequestRepositoryInterface $requestRepo,
        IncidentReplacedPartRepositoryInterface $replacedPartRepo,
        SparePartRepositoryInterface $sparePartRepo,
        IncidentRepositoryInterface $incidentRepo,
        MachineRepositoryInterface $machineRepo,
        ?AuditLogger $auditLogger = null
    ) {
        $this->requestRepo = $requestRepo;
        $this->replacedPartRepo = $replacedPartRepo;
        $this->sparePartRepo = $sparePartRepo;
        $this->incidentRepo = $incidentRepo;
        $this->machineRepo = $machineRepo;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Pausa temporalmente una incidencia en curso por falta de repuestos (RF-REP-03 / RF-REP-04).
     * Transiciona la avería a PENDING_PARTS y genera solicitudes estructuradas en spare_part_requests.
     *
     * @param int $incidentId Identificador de la incidencia a pausar.
     * @param int $technicianId Identificador del técnico autenticado responsable.
     * @param array<string, mixed> $data Datos de solicitud (requested_parts, is_out_of_catalog, custom_part_description).
     * @param array{id: int|null, role: string, name: string}|null $actor Metadatos del usuario actuante para auditoría.
     * @return array<string, mixed>
     * 
     * @throws DomainException Si la incidencia no existe o no está asignada al técnico.
     * @throws InvalidTransitionException Si la incidencia no está en un estado que admita pausa técnica (IN_PROGRESS).
     * @throws InvalidOutOfCatalogJustificationException Si la justificación excepcional tiene menos de 20 caracteres.
     * @throws IncompatibleSparePartException Si alguna pieza seleccionada no es compatible con el modelo de la máquina.
     * @throws InvalidPartQuantityException Si alguna cantidad está fuera del rango permitido (1 a 50).
     * @throws SparePartNotFoundException Si algún repuesto del catálogo no existe.
     * @throws InvalidArgumentException Si no se selecciona ningún repuesto o faltan parámetros requeridos.
     */
    public function pauseIncidentWithParts(
        int $incidentId,
        int $technicianId,
        array $data,
        ?array $actor = null
    ): array {
        // 1. Validar existencia y asignación de la incidencia
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            throw new DomainException("No se encontró ninguna incidencia con ID {$incidentId}.");
        }

        if ($incident->getAssignedTechnicianId() !== $technicianId) {
            throw new DomainException("La incidencia no está asignada al técnico indicado.");
        }

        // 2. Validar transición de estado legal a PENDING_PARTS
        if (!$incident->getStatus()->canTransitionTo(IncidentStatus::PENDING_PARTS)) {
            throw new InvalidTransitionException(
                "No se puede pausar por repuestos una incidencia en estado {$incident->getStatus()->value}. La intervención debe estar previamente en curso.",
                $incident->getStatus(),
                IncidentStatus::PENDING_PARTS
            );
        }

        $isOutOfCatalog = !empty($data['is_out_of_catalog']);
        /** @var SparePartRequest[] $createdRequests */
        $createdRequests = [];
        $pendingPartsReason = '';

        if ($isOutOfCatalog) {
            // Modalidad fuera de catálogo (RF-REP-04)
            $customDesc = trim((string)($data['custom_part_description'] ?? ''));
            $descLength = mb_strlen($customDesc);

            if ($descLength < 20) {
                throw new InvalidOutOfCatalogJustificationException(
                    attemptedLength: $descLength,
                    minimumLength: 20
                );
            }

            $quantity = isset($data['quantity']) ? (int)$data['quantity'] : 1;
            if ($quantity < 1 || $quantity > 50) {
                throw new InvalidPartQuantityException(
                    attemptedQuantity: $quantity,
                    minQuantity: 1,
                    maxQuantity: 50
                );
            }

            $requestEntity = new SparePartRequest(
                id: null,
                incidentId: $incidentId,
                sparePartId: null,
                isOutOfCatalog: true,
                customPartDescription: $customDesc,
                quantity: $quantity,
                status: SparePartRequestStatus::PENDING,
                requestedByUserId: $technicianId
            );

            $createdReq = $this->requestRepo->createRequest($requestEntity);
            $createdRequests[] = $createdReq;
            $pendingPartsReason = "Pieza fuera de catálogo: {$customDesc}";
        } else {
            // Modalidad catálogo normal (RF-REP-03)
            $requestedParts = $data['requested_parts'] ?? [];
            if (!is_array($requestedParts) || empty($requestedParts)) {
                throw new InvalidArgumentException(
                    'Debe seleccionar al menos un repuesto compatible o activar la opción de pieza fuera de catálogo.'
                );
            }

            // Obtener modelo de máquina para validar compatibilidad
            $machineModel = $incident->getMachineModel();
            if ($machineModel === null || $machineModel === '') {
                $machine = $this->machineRepo->findById($incident->getMachineId());
                $machineModel = $machine?->getModel() ?? '';
            }

            $partsNotes = [];

            foreach ($requestedParts as $item) {
                $partId = isset($item['spare_part_id']) ? (int)$item['spare_part_id'] : 0;
                if ($partId <= 0) {
                    throw new InvalidArgumentException('Debe especificar un ID de repuesto válido del catálogo.');
                }

                $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 1;
                if ($quantity < 1 || $quantity > 50) {
                    throw new InvalidPartQuantityException(
                        attemptedQuantity: $quantity,
                        minQuantity: 1,
                        maxQuantity: 50
                    );
                }

                $sparePart = $this->sparePartRepo->findById($partId);
                if ($sparePart === null) {
                    throw new SparePartNotFoundException("No se encontró el repuesto solicitado en el catálogo (ID {$partId}).");
                }

                // Validación estricta de compatibilidad de modelo
                if ($machineModel !== '' && !$sparePart->isCompatibleWithModel($machineModel)) {
                    throw new IncompatibleSparePartException(
                        sparePartId: $sparePart->getId(),
                        partCode: $sparePart->getPartCode(),
                        machineModel: $machineModel
                    );
                }

                $requestEntity = new SparePartRequest(
                    id: null,
                    incidentId: $incidentId,
                    sparePartId: $sparePart->getId(),
                    isOutOfCatalog: false,
                    customPartDescription: null,
                    quantity: $quantity,
                    status: SparePartRequestStatus::PENDING,
                    requestedByUserId: $technicianId,
                    partCode: $sparePart->getPartCode(),
                    partName: $sparePart->getName()
                );

                $createdReq = $this->requestRepo->createRequest($requestEntity);
                $createdRequests[] = $createdReq;
                $partsNotes[] = "{$sparePart->getPartCode()} ({$quantity} ud" . ($quantity > 1 ? 's' : '') . ")";
            }

            $pendingPartsReason = "Repuestos solicitados: " . implode(', ', $partsNotes);
        }

        // 3. Ejecutar transición de estado a PENDING_PARTS en el repositorio de incidencias
        $pausedIncident = $this->incidentRepo->pauseIntervention($incidentId, $technicianId, $pendingPartsReason);

        // 4. Registro de auditoría
        if ($this->auditLogger !== null) {
            $this->auditLogger->logTicketEvent(
                ticketId: $incidentId,
                action: 'PAUSE_PENDING_PARTS',
                user: $actor ?? ['id' => $technicianId, 'role' => 'TECHNICIAN', 'name' => 'Técnico de Ruta'],
                previousState: ['status' => IncidentStatus::IN_PROGRESS->value],
                newState: [
                    'status' => IncidentStatus::PENDING_PARTS->value,
                    'pending_parts_reason' => $pendingPartsReason,
                ],
                metadata: [
                    'is_out_of_catalog' => $isOutOfCatalog,
                    'requests_count' => count($createdRequests),
                ]
            );
        }

        return [
            'id' => $pausedIncident->getId(),
            'status' => $pausedIncident->getStatus()->value,
            'pending_parts_reason' => $pendingPartsReason,
            'requests' => array_map(fn(SparePartRequest $r) => $r->toArray(), $createdRequests),
        ];
    }

    /**
     * Resuelve una incidencia correctiva incorporando el registro obligatorio de sustitución de componentes
     * y la congelación inmutable de snapshot de costes (RF-REP-05, RF-REP-06, Constitución Art. III, Art. V.1).
     *
     * @param int $incidentId Identificador de la incidencia a resolver.
     * @param int $technicianId Identificador del técnico autenticado responsable.
     * @param array<string, mixed> $data Datos de resolución (diagnóstico, acción, replaced_parts_declared, replaced_parts).
     * @param array{id: int|null, role: string, name: string}|null $actor Metadatos del usuario actuante.
     * @return array<string, mixed>
     * 
     * @throws DomainException Si la incidencia no existe o no está asignada al técnico.
     * @throws InvalidTransitionException Si la incidencia no está en estado IN_PROGRESS.
     * @throws InvalidResolutionException Si el diagnóstico o la acción no alcanzan los 20 caracteres reglamentarios.
     * @throws InvalidArgumentException Si falta la declaración Sí/No o si la lista de piezas es inconsistente.
     * @throws InvalidPartQuantityException Si alguna cantidad está fuera de [1, 50].
     * @throws SparePartNotFoundException Si algún repuesto indicado no existe en el catálogo.
     */
    public function resolveIncidentWithParts(
        int $incidentId,
        int $technicianId,
        array $data,
        ?array $actor = null
    ): array {
        // 1. Validar existencia y asignación de la incidencia
        $incident = $this->incidentRepo->findById($incidentId);
        if ($incident === null) {
            throw new DomainException("No se encontró ninguna incidencia con ID {$incidentId}.");
        }

        if ($incident->getAssignedTechnicianId() !== $technicianId) {
            throw new DomainException("La incidencia no está asignada al técnico indicado.");
        }

        // 2. Validar que la incidencia admita resolución (debe estar en IN_PROGRESS)
        if (!$incident->getStatus()->canTransitionTo(IncidentStatus::RESOLVED)) {
            throw new InvalidTransitionException(
                "No se puede resolver una incidencia en estado {$incident->getStatus()->value}. La intervención debe estar previamente en curso (IN_PROGRESS).",
                $incident->getStatus(),
                IncidentStatus::RESOLVED
            );
        }

        // 3. Validar textos reglamentarios de justificación técnica (Constitución Art. V.1 / EARS 8.1)
        $diagnosis = trim((string)($data['resolution_diagnosis'] ?? ($data['diagnosis'] ?? '')));
        $action = trim((string)($data['resolution_action'] ?? ($data['action_taken'] ?? '')));

        ResolutionValidator::validate($diagnosis, $action);

        // 4. Validar declaración obligatoria de sustitución de componentes (RF-REP-05)
        if (!isset($data['replaced_parts_declared'])) {
            throw new InvalidArgumentException(
                'Debe indicar si la intervención conllevó sustitución física de componentes (campo replaced_parts_declared obligatorio).'
            );
        }

        $replacedPartsDeclared = filter_var($data['replaced_parts_declared'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($replacedPartsDeclared === null) {
            throw new InvalidArgumentException(
                'El valor de replaced_parts_declared debe ser un booleano válido (true o false).'
            );
        }

        /** @var IncidentReplacedPart[] $insertedParts */
        $insertedParts = [];
        $totalPartsCost = 0.00;

        if ($replacedPartsDeclared) {
            $replacedPartsList = $data['replaced_parts'] ?? [];
            if (!is_array($replacedPartsList) || empty($replacedPartsList)) {
                throw new InvalidArgumentException(
                    'Ha indicado que se sustituyeron componentes pero no ha registrado ninguna pieza.'
                );
            }

            foreach ($replacedPartsList as $item) {
                $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 1;
                if ($quantity < 1 || $quantity > 50) {
                    throw new InvalidPartQuantityException(
                        attemptedQuantity: $quantity,
                        minQuantity: 1,
                        maxQuantity: 50
                    );
                }

                $rawDestination = strtoupper(trim((string)($item['old_part_destination'] ?? '')));
                if (!in_array($rawDestination, ['DESGUACE', 'TALLER'], true)) {
                    throw new InvalidArgumentException(
                        'El destino del componente retirado debe ser DESGUACE o TALLER.'
                    );
                }
                $destination = OldPartDestination::from($rawDestination);

                $isOutOfCatalog = !empty($item['is_out_of_catalog']);
                $notes = isset($item['notes']) ? trim((string)$item['notes']) : null;

                if ($isOutOfCatalog) {
                    $customPartName = trim((string)($item['custom_part_name'] ?? ''));
                    if (mb_strlen($customPartName) < 3) {
                        throw new InvalidArgumentException(
                            'El nombre de la pieza fuera de catálogo debe tener al menos 3 caracteres.'
                        );
                    }

                    $partEntity = new IncidentReplacedPart(
                        id: null,
                        interventionType: 'INCIDENT',
                        incidentId: $incidentId,
                        preventiveOrderId: null,
                        machineId: $incident->getMachineId(),
                        locationId: $incident->getLocationId(),
                        technicianId: $technicianId,
                        sparePartId: null,
                        isOutOfCatalog: true,
                        customPartName: $customPartName,
                        quantity: $quantity,
                        unitCostSnapshot: 0.00,
                        oldPartDestination: $destination,
                        notes: $notes,
                        installedAt: date('Y-m-d H:i:s'),
                        partCode: 'OUT_OF_CATALOG',
                        partName: $customPartName
                    );
                } else {
                    $partId = isset($item['spare_part_id']) ? (int)$item['spare_part_id'] : 0;
                    if ($partId <= 0) {
                        throw new InvalidArgumentException(
                            'Debe especificar un ID de repuesto válido del catálogo.'
                        );
                    }

                    $sparePart = $this->sparePartRepo->findById($partId);
                    if ($sparePart === null) {
                        throw new SparePartNotFoundException(
                            'No se encontró el repuesto solicitado en el catálogo.'
                        );
                    }

                    // Snapshot inmutable: congelamos el reference_cost vigente en este milisegundo (Art. III)
                    $unitCostSnapshot = $sparePart->getReferenceCost();

                    $partEntity = new IncidentReplacedPart(
                        id: null,
                        interventionType: 'INCIDENT',
                        incidentId: $incidentId,
                        preventiveOrderId: null,
                        machineId: $incident->getMachineId(),
                        locationId: $incident->getLocationId(),
                        technicianId: $technicianId,
                        sparePartId: $sparePart->getId(),
                        isOutOfCatalog: false,
                        customPartName: null,
                        quantity: $quantity,
                        unitCostSnapshot: $unitCostSnapshot,
                        oldPartDestination: $destination,
                        notes: $notes,
                        installedAt: date('Y-m-d H:i:s'),
                        partCode: $sparePart->getPartCode(),
                        partName: $sparePart->getName()
                    );

                    $totalPartsCost += round($quantity * $unitCostSnapshot, 2);
                }

                $inserted = $this->replacedPartRepo->insertReplacedPart($partEntity);
                $insertedParts[] = $inserted;
            }

            // Marcar solicitudes previas de repuestos de esta incidencia como atendidas (RF-REP-06)
            $this->requestRepo->markAttendedByIncident($incidentId);
        } else {
            // No se declararon piezas sustituidas: si existían solicitudes previas en PENDING, se cancelan
            $this->requestRepo->markCancelledByIncident($incidentId);
        }

        // 5. Transicionar formalmente la incidencia a RESOLVED en el repositorio
        $resolvedIncident = $this->incidentRepo->resolve($incidentId, $technicianId, $diagnosis, $action);

        // 6. Registro inmutable en auditoría
        if ($this->auditLogger !== null) {
            $this->auditLogger->logTicketEvent(
                ticketId: $incidentId,
                action: 'RESOLVE_INCIDENT',
                user: $actor ?? ['id' => $technicianId, 'role' => 'TECHNICIAN', 'name' => 'Técnico de Ruta'],
                previousState: ['status' => IncidentStatus::IN_PROGRESS->value],
                newState: [
                    'status' => IncidentStatus::RESOLVED->value,
                    'resolution_diagnosis' => $diagnosis,
                    'resolution_action' => $action,
                    'replaced_parts_declared' => $replacedPartsDeclared,
                    'total_parts_cost' => round($totalPartsCost, 2),
                ],
                metadata: [
                    'replaced_parts_count' => count($insertedParts),
                    'total_parts_cost' => round($totalPartsCost, 2),
                ]
            );
        }

        return [
            'id' => $resolvedIncident->getId(),
            'ticket_code' => $resolvedIncident->getTicketCode(),
            'status' => IncidentStatus::RESOLVED->value,
            'resolved_at' => $resolvedIncident->getResolvedAt() ?? date('Y-m-d H:i:s'),
            'replaced_parts_count' => count($insertedParts),
            'total_parts_cost' => round($totalPartsCost, 2),
            'replaced_parts' => array_map(fn(IncidentReplacedPart $p) => $p->toArray(), $insertedParts),
        ];
    }

    /**
     * Registra las piezas de repuesto sustituidas durante una intervención preventiva periódica (RF-REP-07).
     * Congela snapshots de costes sin alterar el estado de incidencias correctivas.
     *
     * @param int $preventiveOrderId Identificador de la orden preventiva de trabajo.
     * @param int $technicianId Identificador del técnico autenticado.
     * @param int $machineId Identificador de la máquina intervenida.
     * @param int $locationId Identificador de la sede donde se ubica la máquina.
     * @param array<int, array<string, mixed>> $replacedParts Listado de piezas declaradas.
     * @param array{id: int|null, role: string, name: string}|null $actor Metadatos del usuario actuante.
     * @return array<string, mixed>
     * 
     * @throws InvalidPartQuantityException Si alguna cantidad está fuera de rango [1, 50].
     * @throws SparePartNotFoundException Si algún repuesto del catálogo no existe.
     * @throws InvalidArgumentException Si los datos de las piezas son incompletos o erróneos.
     */
    public function recordPreventiveReplacedParts(
        int $preventiveOrderId,
        int $technicianId,
        int $machineId,
        int $locationId,
        array $replacedParts,
        ?array $actor = null
    ): array {
        if (empty($replacedParts)) {
            return [
                'preventive_order_id' => $preventiveOrderId,
                'replaced_parts_count' => 0,
                'total_parts_cost' => 0.00,
                'replaced_parts' => [],
            ];
        }

        /** @var IncidentReplacedPart[] $insertedParts */
        $insertedParts = [];
        $totalPartsCost = 0.00;

        foreach ($replacedParts as $item) {
            $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 1;
            if ($quantity < 1 || $quantity > 50) {
                throw new InvalidPartQuantityException(
                    attemptedQuantity: $quantity,
                    minQuantity: 1,
                    maxQuantity: 50
                );
            }

            $rawDestination = strtoupper(trim((string)($item['old_part_destination'] ?? 'DESGUACE')));
            if (!in_array($rawDestination, ['DESGUACE', 'TALLER'], true)) {
                throw new InvalidArgumentException(
                    'El destino del componente retirado debe ser DESGUACE o TALLER.'
                );
            }
            $destination = OldPartDestination::from($rawDestination);

            $isOutOfCatalog = !empty($item['is_out_of_catalog']);
            $notes = isset($item['notes']) ? trim((string)$item['notes']) : null;

            if ($isOutOfCatalog) {
                $customPartName = trim((string)($item['custom_part_name'] ?? ''));
                if (mb_strlen($customPartName) < 3) {
                    throw new InvalidArgumentException(
                        'El nombre de la pieza fuera de catálogo debe tener al menos 3 caracteres.'
                    );
                }

                $partEntity = new IncidentReplacedPart(
                    id: null,
                    interventionType: 'PREVENTIVE',
                    incidentId: null,
                    preventiveOrderId: $preventiveOrderId,
                    machineId: $machineId,
                    locationId: $locationId,
                    technicianId: $technicianId,
                    sparePartId: null,
                    isOutOfCatalog: true,
                    customPartName: $customPartName,
                    quantity: $quantity,
                    unitCostSnapshot: 0.00,
                    oldPartDestination: $destination,
                    notes: $notes,
                    installedAt: date('Y-m-d H:i:s'),
                    partCode: 'OUT_OF_CATALOG',
                    partName: $customPartName
                );
            } else {
                $partId = isset($item['spare_part_id']) ? (int)$item['spare_part_id'] : 0;
                if ($partId <= 0) {
                    throw new InvalidArgumentException(
                        'Debe especificar un ID de repuesto válido del catálogo.'
                    );
                }

                $sparePart = $this->sparePartRepo->findById($partId);
                if ($sparePart === null) {
                    throw new SparePartNotFoundException(
                        "No se encontró el repuesto solicitado en el catálogo (ID {$partId})."
                    );
                }

                $unitCostSnapshot = $sparePart->getReferenceCost();

                $partEntity = new IncidentReplacedPart(
                    id: null,
                    interventionType: 'PREVENTIVE',
                    incidentId: null,
                    preventiveOrderId: $preventiveOrderId,
                    machineId: $machineId,
                    locationId: $locationId,
                    technicianId: $technicianId,
                    sparePartId: $sparePart->getId(),
                    isOutOfCatalog: false,
                    customPartName: null,
                    quantity: $quantity,
                    unitCostSnapshot: $unitCostSnapshot,
                    oldPartDestination: $destination,
                    notes: $notes,
                    installedAt: date('Y-m-d H:i:s'),
                    partCode: $sparePart->getPartCode(),
                    partName: $sparePart->getName()
                );

                $totalPartsCost += round($quantity * $unitCostSnapshot, 2);
            }

            $inserted = $this->replacedPartRepo->insertReplacedPart($partEntity);
            $insertedParts[] = $inserted;
        }

        if ($this->auditLogger !== null) {
            $this->auditLogger->logMachineEvent(
                machineId: $machineId,
                action: 'PREVENTIVE_REPLACED_PARTS',
                user: $actor ?? ['id' => $technicianId, 'role' => 'TECHNICIAN', 'name' => 'Técnico de Ruta'],
                previousState: null,
                newState: [
                    'preventive_order_id' => $preventiveOrderId,
                    'replaced_parts_count' => count($insertedParts),
                    'total_parts_cost' => round($totalPartsCost, 2),
                ],
                metadata: [
                    'location_id' => $locationId,
                ]
            );
        }

        return [
            'preventive_order_id' => $preventiveOrderId,
            'replaced_parts_count' => count($insertedParts),
            'total_parts_cost' => round($totalPartsCost, 2),
            'replaced_parts' => array_map(fn(IncidentReplacedPart $p) => $p->toArray(), $insertedParts),
        ];
    }

    /**
     * Cancela todas las solicitudes pendientes de repuestos asociadas a una incidencia (Caso Límite 3).
     *
     * @param int $incidentId
     * @return int Número de solicitudes canceladas
     */
    public function cancelRequestsForIncident(int $incidentId): int
    {
        return $this->requestRepo->markCancelledByIncident($incidentId);
    }

    /**
     * Obtiene el listado de solicitudes estructuradas de repuesto emitidas en una incidencia.
     *
     * @param int $incidentId
     * @return SparePartRequest[]
     */
    public function getRequestsByIncident(int $incidentId): array
    {
        return $this->requestRepo->findByIncidentId($incidentId);
    }

    /**
     * Obtiene los componentes físicos sustituidos en una avería correctiva.
     *
     * @param int $incidentId
     * @return IncidentReplacedPart[]
     */
    public function getReplacedPartsByIncident(int $incidentId): array
    {
        return $this->replacedPartRepo->findByIncidentId($incidentId);
    }

    /**
     * Obtiene los componentes físicos sustituidos en una orden preventiva.
     *
     * @param int $preventiveOrderId
     * @return IncidentReplacedPart[]
     */
    public function getReplacedPartsByPreventiveOrder(int $preventiveOrderId): array
    {
        return $this->replacedPartRepo->findByPreventiveOrderId($preventiveOrderId);
    }

    /**
     * Obtiene la bandeja de solicitudes de piezas fuera de catálogo pendientes de revisión para el coordinador.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPendingOutOfCatalogReviews(): array
    {
        return $this->requestRepo->findPendingOutOfCatalogReviews();
    }
}
