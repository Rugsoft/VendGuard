<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\InactiveRecordCollisionException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Exception\MachineTransferBlockedException;
use VendGuard\Core\Domain\Exception\MachineTypeChangeBlockedException;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;

/**
 * AdminMachineService
 * 
 * Servicio de Aplicación responsable de orquestar la gestión del parque de máquinas dispensadoras.
 * Aplica salvaguardas de tipología sanitaria (Art. II), bloqueo ante averías abiertas o en garantía de 48h (Art. V.6),
 * traslados asistidos y auditoría inmutable en MariaDB (RF-02, RF-05).
 */
class AdminMachineService
{
    private MachineRepositoryInterface $machineRepo;
    private LocationRepositoryInterface $locationRepo;
    private AuditLogger $auditLogger;

    public function __construct(
        MachineRepositoryInterface $machineRepo,
        LocationRepositoryInterface $locationRepo,
        ?AuditLogger $auditLogger = null
    ) {
        $this->machineRepo = $machineRepo;
        $this->locationRepo = $locationRepo;
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Recupera el catálogo integral de máquinas con filtros de estado, sede, tipología y búsqueda (RF-02, EARS 2.2).
     *
     * @param array<string, mixed> $filters
     * @return array<array<string, mixed>>
     */
    public function listMachines(array $filters = []): array
    {
        return $this->machineRepo->findAll($filters);
    }

    /**
     * Recupera una máquina por su identificador primario (RF-02).
     *
     * @param int $id
     * @param bool $allowDeleted
     * @return Machine
     * @throws MachineNotFoundException Si no existe.
     */
    public function getMachine(int $id, bool $allowDeleted = false): Machine
    {
        $machine = $this->machineRepo->findById($id, withIncident: true, allowDeleted: $allowDeleted);
        if ($machine === null) {
            throw new MachineNotFoundException('MACHINE_NOT_FOUND', "La máquina con ID {$id} no existe.");
        }

        return $machine;
    }

    /**
     * Da de alta una nueva máquina dispensadora en una sede activa (RF-02, EARS 2.1).
     *
     * @param array<string, mixed> $data
     * @param array{id: int|null, role: string, name: string} $actor
     * @param string|null $clientIp
     * @return Machine
     * @throws InvalidArgumentException
     * @throws InactiveRecordCollisionException
     */
    public function createMachine(array $data, array $actor, ?string $clientIp = null): Machine
    {
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        $model = trim((string)($data['model'] ?? ''));
        $machineTypeRaw = trim((string)($data['machine_type'] ?? ''));
        $locationId = (int)($data['location_id'] ?? 0);
        $floorWing = trim((string)($data['floor_wing'] ?? ''));
        $notes = isset($data['notes']) && $data['notes'] !== '' ? trim((string)$data['notes']) : null;

        $this->validateCode($code);
        $this->validateModel($model);
        $machineType = $this->validateMachineType($machineTypeRaw);
        $this->validateFloorWing($floorWing);

        if ($locationId <= 0) {
            throw new InvalidArgumentException('Debe indicar una sede cliente válida.');
        }

        // Comprobar que la sede existe y está activa (EARS 2.1)
        $location = $this->locationRepo->findById($locationId, allowDeleted: false);
        if ($location === null || !$location->isActive()) {
            throw new InvalidArgumentException(
                "La sede asignada (ID {$locationId}) no existe o no se encuentra activa."
            );
        }

        // Detección de colisiones de código de máquina (EARS 2.1)
        $existing = $this->machineRepo->findByCode($code, withIncident: false, allowDeleted: true);
        if ($existing !== null) {
            if ($existing->isActive()) {
                throw new InactiveRecordCollisionException(
                    'MACHINE_ALREADY_EXISTS_ACTIVE',
                    "El código de máquina '{$code}' ya se encuentra registrado y activo.",
                    $existing->getId(),
                    'MACHINE',
                    false
                );
            }

            throw new InactiveRecordCollisionException(
                'MACHINE_ALREADY_EXISTS_INACTIVE',
                "El código de máquina '{$code}' ya existe pero se encuentra dado de baja.",
                $existing->getId(),
                'MACHINE',
                true
            );
        }

        $created = $this->machineRepo->create([
            'location_id'  => $locationId,
            'code'         => $code,
            'model'        => $model,
            'machine_type' => $machineType->value,
            'floor_wing'   => $floorWing,
            'notes'        => $notes,
        ]);

        // Auditoría inmutable sincrónica (RF-05, EARS 4.1)
        $metadata = [];
        if ($clientIp !== null && $clientIp !== '') {
            $metadata['ip'] = $clientIp;
        }

        $this->auditLogger->logMachineEvent(
            $created->getId(),
            'MACHINE_CREATED',
            $actor,
            null,
            [
                'code'         => $created->getCode(),
                'model'        => $created->getModel(),
                'machine_type' => $created->getMachineType()->value,
                'location_id'  => $created->getLocationId(),
                'floor_wing'   => $created->getFloorWing(),
                'notes'        => $created->getNotes(),
            ],
            !empty($metadata) ? $metadata : null
        );

        return $created;
    }

    /**
     * Actualiza modelo, tipología sanitaria, ubicación física interna y notas (RF-02, EARS 2.3, 2.5).
     * El código es inmutable. El cambio de tipología se bloquea si hay averías activas o en garantía.
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @param array{id: int|null, role: string, name: string} $actor
     * @return Machine
     * @throws MachineNotFoundException
     * @throws MachineTypeChangeBlockedException
     * @throws InvalidArgumentException
     */
    public function updateMachine(int $id, array $data, array $actor): Machine
    {
        $machine = $this->machineRepo->findById($id, withIncident: false, allowDeleted: false);
        if ($machine === null) {
            throw new MachineNotFoundException('MACHINE_NOT_FOUND', "La máquina con ID {$id} no existe o está inactiva.");
        }

        $cleanData = [];
        $changedFields = [];

        if (array_key_exists('model', $data)) {
            $model = trim((string)$data['model']);
            $this->validateModel($model);
            $cleanData['model'] = $model;
            if ($model !== $machine->getModel()) {
                $changedFields[] = 'model';
            }
        }

        if (array_key_exists('floor_wing', $data)) {
            $floorWing = trim((string)$data['floor_wing']);
            $this->validateFloorWing($floorWing);
            $cleanData['floor_wing'] = $floorWing;
            if ($floorWing !== $machine->getFloorWing()) {
                $changedFields[] = 'floor_wing';
            }
        }

        if (array_key_exists('notes', $data)) {
            $notes = $data['notes'] !== null && $data['notes'] !== '' ? trim((string)$data['notes']) : null;
            $cleanData['notes'] = $notes;
            if ($notes !== $machine->getNotes()) {
                $changedFields[] = 'notes';
            }
        }

        // Comprobación de cambio de tipología sanitaria (EARS 2.5)
        if (array_key_exists('machine_type', $data)) {
            $newType = $this->validateMachineType((string)$data['machine_type']);
            if ($newType->value !== $machine->getMachineType()->value) {
                // Verificar si tiene tickets activos o en garantía de 48h
                $ticket = $this->machineRepo->getActiveTicketOrWarranty($id);
                if ($ticket !== null) {
                    throw new MachineTypeChangeBlockedException(
                        "No se puede modificar la tipología sanitaria de la máquina mientras tenga una avería activa o en periodo de garantía de 48 horas.",
                        $ticket['ticket_code'],
                        $ticket['status'],
                        $id
                    );
                }

                $cleanData['machine_type'] = $newType->value;
                $changedFields[] = 'machine_type';
            }
        }

        if (empty($changedFields)) {
            return $machine;
        }

        $previousState = [
            'model'        => $machine->getModel(),
            'machine_type' => $machine->getMachineType()->value,
            'floor_wing'   => $machine->getFloorWing(),
            'notes'        => $machine->getNotes(),
        ];

        $this->machineRepo->update($id, $cleanData);
        $updated = $this->machineRepo->findById($id, withIncident: false, allowDeleted: false);
        if ($updated === null) {
            throw new MachineNotFoundException('MACHINE_NOT_FOUND', "Error al recuperar la máquina {$id} tras actualizar.");
        }

        $newState = [
            'model'        => $updated->getModel(),
            'machine_type' => $updated->getMachineType()->value,
            'floor_wing'   => $updated->getFloorWing(),
            'notes'        => $updated->getNotes(),
        ];

        // Auditoría inmutable sincrónica (RF-05, EARS 4.2)
        $this->auditLogger->logMachineEvent(
            $id,
            'MACHINE_UPDATED',
            $actor,
            $previousState,
            $newState,
            ['changed_fields' => $changedFields]
        );

        return $updated;
    }

    /**
     * Traslada una máquina operativa a otra sede cliente activa (RF-02, EARS 2.7, 2.8).
     * Bloqueado si tiene averías activas o en garantía de 48h.
     *
     * @param int $id
     * @param int $targetLocationId
     * @param string $floorWing
     * @param string|null $notes
     * @param array{id: int|null, role: string, name: string} $actor
     * @return Machine
     * @throws MachineNotFoundException
     * @throws MachineTransferBlockedException
     * @throws InvalidArgumentException
     */
    public function transferMachine(
        int $id,
        int $targetLocationId,
        string $floorWing,
        ?string $notes,
        array $actor
    ): Machine {
        $machine = $this->machineRepo->findById($id, withIncident: false, allowDeleted: false);
        if ($machine === null) {
            throw new MachineNotFoundException('MACHINE_NOT_FOUND', "La máquina con ID {$id} no existe o está inactiva.");
        }

        // Bloqueo estricto si tiene tickets activos o en garantía (EARS 2.8)
        $ticket = $this->machineRepo->getActiveTicketOrWarranty($id);
        if ($ticket !== null) {
            throw new MachineTransferBlockedException(
                "No se puede trasladar la máquina mientras tenga incidencias activas o en garantía de 48h (ticket {$ticket['ticket_code']}, estado {$ticket['status']}).",
                $ticket['ticket_code'],
                $ticket['status'],
                $id,
                'MACHINE_TRANSFER_BLOCKED'
            );
        }

        // Comprobar que la sede destino existe y está activa (EARS 2.7)
        $targetLocation = $this->locationRepo->findById($targetLocationId, allowDeleted: false);
        if ($targetLocation === null || !$targetLocation->isActive()) {
            throw new InvalidArgumentException(
                "La sede de destino (ID {$targetLocationId}) no existe o no se encuentra activa."
            );
        }

        $cleanFloorWing = trim($floorWing);
        $this->validateFloorWing($cleanFloorWing);

        $previousState = [
            'location_id' => $machine->getLocationId(),
            'floor_wing'  => $machine->getFloorWing(),
            'notes'       => $machine->getNotes(),
        ];

        $this->machineRepo->transfer($id, $targetLocationId, $cleanFloorWing, $notes);
        $transferred = $this->machineRepo->findById($id, withIncident: false, allowDeleted: false);
        if ($transferred === null) {
            throw new MachineNotFoundException('MACHINE_NOT_FOUND', "Error al recuperar máquina {$id} tras el traslado.");
        }

        $newState = [
            'location_id' => $transferred->getLocationId(),
            'floor_wing'  => $transferred->getFloorWing(),
            'notes'       => $transferred->getNotes(),
        ];

        // Auditoría inmutable sincrónica (RF-05, EARS 4.2)
        $this->auditLogger->logMachineEvent(
            $id,
            'MACHINE_TRANSFERRED',
            $actor,
            $previousState,
            $newState,
            [
                'source_location_id' => $machine->getLocationId(),
                'target_location_id' => $targetLocationId,
            ]
        );

        return $transferred;
    }

    /**
     * Da de baja lógica a una máquina operativa (RF-02, EARS 2.10, 2.11).
     * Bloqueado si tiene averías activas o en garantía de 48h.
     *
     * @param int $id
     * @param array{id: int|null, role: string, name: string} $actor
     * @return bool
     * @throws MachineNotFoundException
     * @throws MachineTransferBlockedException
     */
    public function deactivateMachine(int $id, array $actor): bool
    {
        $machine = $this->machineRepo->findById($id, withIncident: false, allowDeleted: false);
        if ($machine === null) {
            throw new MachineNotFoundException('MACHINE_NOT_FOUND', "La máquina con ID {$id} no existe o ya está dada de baja.");
        }

        // Bloqueo estricto si tiene tickets activos o en garantía (EARS 2.10)
        $ticket = $this->machineRepo->getActiveTicketOrWarranty($id);
        if ($ticket !== null) {
            throw new MachineTransferBlockedException(
                "No se puede dar de baja la máquina porque tiene la incidencia activa {$ticket['ticket_code']} en estado {$ticket['status']}.",
                $ticket['ticket_code'],
                $ticket['status'],
                $id,
                'MACHINE_DEACTIVATION_BLOCKED'
            );
        }

        $this->machineRepo->softDelete($id);

        // Auditoría inmutable sincrónica (RF-05, EARS 4.3)
        $this->auditLogger->logMachineEvent(
            $id,
            'MACHINE_DEACTIVATED',
            $actor,
            ['is_active' => true],
            ['is_active' => false],
            ['reason' => 'Baja administrativa']
        );

        return true;
    }

    /**
     * Reactiva una máquina dada de baja con reubicación obligatoria si la sede original está inactiva (RF-02, EARS 2.12, 2.13).
     *
     * @param int $id
     * @param array{id: int|null, role: string, name: string} $actor
     * @param int|null $targetLocationId Sede activa destino si se reubica.
     * @param string|null $floorWing Nueva planta/ala si se reubica.
     * @return Machine
     * @throws MachineNotFoundException
     * @throws InvalidArgumentException
     */
    public function reactivateMachine(
        int $id,
        array $actor,
        ?int $targetLocationId = null,
        ?string $floorWing = null
    ): Machine {
        $machine = $this->machineRepo->findById($id, withIncident: false, allowDeleted: true);
        if ($machine === null) {
            throw new MachineNotFoundException('MACHINE_NOT_FOUND', "La máquina con ID {$id} no existe.");
        }

        if ($machine->isActive()) {
            return $machine;
        }

        // Verificar si la sede original está activa
        $origLocation = $this->locationRepo->findById($machine->getLocationId(), allowDeleted: false);
        $origLocationActive = ($origLocation !== null && $origLocation->isActive());

        $transferredOnReactivation = false;

        if (!$origLocationActive) {
            // Sede original inactiva: reubicación obligatoria (EARS 2.13)
            if ($targetLocationId === null || $floorWing === null || trim($floorWing) === '') {
                throw new InvalidArgumentException(
                    "La sede original de la máquina está dada de baja. Debe especificar obligatoriamente una sede activa (target_location_id) y una planta/ala (floor_wing) para reactivarla."
                );
            }

            $targetLoc = $this->locationRepo->findById($targetLocationId, allowDeleted: false);
            if ($targetLoc === null || !$targetLoc->isActive()) {
                throw new InvalidArgumentException(
                    "La nueva sede de asignación (ID {$targetLocationId}) no existe o no se encuentra activa."
                );
            }

            $cleanFloorWing = trim($floorWing);
            $this->validateFloorWing($cleanFloorWing);

            $this->machineRepo->restoreWithLocation($id, $targetLocationId, $cleanFloorWing);
            $transferredOnReactivation = true;
        } else {
            // Sede original activa: reubicación opcional
            if ($targetLocationId !== null && $targetLocationId !== $machine->getLocationId()) {
                $targetLoc = $this->locationRepo->findById($targetLocationId, allowDeleted: false);
                if ($targetLoc === null || !$targetLoc->isActive()) {
                    throw new InvalidArgumentException(
                        "La nueva sede de asignación (ID {$targetLocationId}) no existe o no se encuentra activa."
                    );
                }

                $cleanFloorWing = trim((string)$floorWing);
                $this->validateFloorWing($cleanFloorWing);

                $this->machineRepo->restoreWithLocation($id, $targetLocationId, $cleanFloorWing);
                $transferredOnReactivation = true;
            } else {
                $this->machineRepo->restoreWithLocation($id, null, null);
            }
        }

        $reactivated = $this->machineRepo->findById($id, withIncident: false, allowDeleted: false);
        if ($reactivated === null) {
            throw new MachineNotFoundException('MACHINE_NOT_FOUND', "Error al recuperar la máquina {$id} tras reactivarla.");
        }

        // Auditoría inmutable sincrónica (RF-05, EARS 4.4)
        $this->auditLogger->logMachineEvent(
            $id,
            'MACHINE_REACTIVATED',
            $actor,
            [
                'is_active'   => false,
                'location_id' => $machine->getLocationId(),
            ],
            [
                'is_active'   => true,
                'location_id' => $reactivated->getLocationId(),
            ],
            ['transferred_on_reactivation' => $transferredOnReactivation]
        );

        return $reactivated;
    }

    private function validateCode(string $code): void
    {
        if (!preg_match('/^[A-Z0-9-]{3,32}$/', $code)) {
            throw new InvalidArgumentException(
                "El código de máquina '{$code}' no es válido. Debe contener entre 3 y 32 caracteres alfanuméricos en mayúsculas o guiones (ej: VEND-0101)."
            );
        }
    }

    private function validateModel(string $model): void
    {
        if (mb_strlen($model) < 2 || mb_strlen($model) > 100) {
            throw new InvalidArgumentException(
                'El modelo de máquina es obligatorio y debe tener entre 2 y 100 caracteres.'
            );
        }
    }

    private function validateFloorWing(string $floorWing): void
    {
        if (mb_strlen($floorWing) < 2 || mb_strlen($floorWing) > 100) {
            throw new InvalidArgumentException(
                'La ubicación física interna (planta/ala) es obligatoria y debe tener entre 2 y 100 caracteres.'
            );
        }
    }

    private function validateMachineType(string $type): MachineType
    {
        try {
            return MachineType::fromString($type);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException(
                "La tipología sanitaria '{$type}' no es válida. Tipos permitidos: " . implode(', ', MachineType::values())
            );
        }
    }
}
