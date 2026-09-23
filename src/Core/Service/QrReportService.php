<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;

/**
 * QrReportService
 * 
 * Servicio de Aplicación para el registro de avisos de avería iniciados desde código QR.
 * Gestiona de forma atómica la concurrencia y la prevención de expedientes duplicados:
 * 
 * 1. Si la máquina está limpia, calcula la urgencia según criticidad y tipo (Art. II),
 *    genera un código de ticket único y crea el nuevo expediente (merged: false).
 * 2. Si detecta una avería activa preexistente o un envío concurrente casi simultáneo (EARS 4.5),
 *    anexa amigablemente las observaciones en la bitácora del ticket abierto (merged: true),
 *    garantizando cero errores técnicos y preservando la regla constitucional de unicidad.
 * 
 * Cumple con RF-03 (EARS 3.4), RF-04 (EARS 4.5), Dogma Vanilla y los Artículos II, IV y V de la Constitución.
 */
class QrReportService
{
    private MachineRepositoryInterface $machineRepo;
    private LocationRepositoryInterface $locationRepo;
    private IncidentRepositoryInterface $incidentRepo;

    public function __construct(
        ?MachineRepositoryInterface $machineRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?IncidentRepositoryInterface $incidentRepo = null
    ) {
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
    }

    /**
     * Procesa y registra el reporte de avería procedente del flujo QR.
     *
     * @param array<string, mixed> $payload Datos del formulario (machine_code, category, description, etc.).
     * @return array<string, mixed> Resultado del reporte con ticket_code, merged (bool), estado y mensajes.
     * @throws MachineNotFoundException Si la máquina o su sede no existen o están inactivas.
     * @throws InvalidArgumentException Si los datos del reporte no superan las validaciones de integridad.
     */
    public function report(array $payload): array
    {
        // 1. Validar y normalizar código de máquina
        $machineCodeRaw = $payload['machine_code'] ?? null;
        if ($machineCodeRaw === null || trim((string)$machineCodeRaw) === '') {
            throw new InvalidArgumentException('El código de máquina (machine_code) es obligatorio.');
        }

        $cleanMachineCode = strtoupper(trim((string)$machineCodeRaw));
        $machine = $this->machineRepo->findByCode($cleanMachineCode);
        if ($machine === null || !$machine->isActive()) {
            throw new MachineNotFoundException();
        }

        // 2. Resolver y validar sede activa
        $location = $this->locationRepo->findById($machine->getLocationId());
        if ($location === null || !$location->isActive()) {
            throw new MachineNotFoundException();
        }

        // 3. Validar categoría de avería
        $categoryRaw = $payload['category'] ?? null;
        if ($categoryRaw === null || trim((string)$categoryRaw) === '') {
            throw new InvalidArgumentException('La categoría de avería es obligatoria.');
        }

        $cleanCategory = strtoupper(trim((string)$categoryRaw));
        if (!IncidentCategory::isValid($cleanCategory)) {
            throw new InvalidArgumentException("Categoría de avería no válida: '{$categoryRaw}'.");
        }
        $category = IncidentCategory::fromString($cleanCategory);

        // 4. Validar descripción
        $description = trim((string)($payload['description'] ?? ''));
        if ($description === '') {
            throw new InvalidArgumentException('La descripción de la avería es obligatoria.');
        }
        if (mb_strlen($description) < 5) {
            throw new InvalidArgumentException('La descripción de la avería debe contener al menos 5 caracteres.');
        }

        // 5. Parámetros opcionales del informador
        $reporterName = isset($payload['reporter_name']) && trim((string)$payload['reporter_name']) !== ''
            ? trim((string)$payload['reporter_name'])
            : null;

        $reporterPhone = isset($payload['reporter_phone']) && trim((string)$payload['reporter_phone']) !== ''
            ? trim((string)$payload['reporter_phone'])
            : null;

        $retainedMoney = null;
        if (isset($payload['retained_money_amount']) && $payload['retained_money_amount'] !== null && trim((string)$payload['retained_money_amount']) !== '') {
            if (!is_numeric($payload['retained_money_amount'])) {
                throw new InvalidArgumentException('El importe de dinero retenido debe ser numérico.');
            }
            $val = (float)$payload['retained_money_amount'];
            if ($val < 0) {
                throw new InvalidArgumentException('El importe de dinero retenido no puede ser negativo.');
            }
            $retainedMoney = $val;
        }

        $photoPath = isset($payload['photo_path']) && trim((string)$payload['photo_path']) !== ''
            ? trim((string)$payload['photo_path'])
            : null;

        // 6. Comprobación previa de concurrencia (EARS 4.5)
        $activeIncident = $this->incidentRepo->findActiveByMachineId($machine->getId());
        if ($activeIncident !== null && $activeIncident->getStatus()->isActive() && !$activeIncident->getStatus()->isTerminal()) {
            return $this->mergeIntoExistingIncident(
                $activeIncident,
                $machine,
                $location,
                $description,
                $reporterName,
                $retainedMoney,
                $photoPath
            );
        }

        // 7. Cálculo automático de urgencia (Constitución Art. II, RF-03 / EARS 3.2-3.7)
        $urgency = UrgencyCalculator::calculate($machine->getMachineType(), $category);

        // 8. Generar código de ticket único (INC-YYYY-XXXX)
        $ticketCode = TicketCode::generate()->value();

        // 9. Construir la nueva entidad Incident
        $newIncident = new Incident(
            id: null,
            ticketCode: $ticketCode,
            machineId: $machine->getId(),
            locationId: $location->getId(),
            category: $category,
            description: $description,
            urgency: $urgency,
            status: IncidentStatus::REGISTERED,
            assignedTechnicianId: null,
            reporterName: $reporterName,
            reporterPhone: $reporterPhone,
            retainedMoneyAmount: $retainedMoney,
            photoPath: $photoPath
        );

        // 10. Persistencia atómica con captura de condición de carrera
        try {
            $created = $this->incidentRepo->create($newIncident, null, 'Aviso registrado mediante código QR');

            return [
                'ticket_code' => $created->getTicketCode(),
                'merged' => false,
                'status' => $created->getStatus()->value,
                'status_label' => $this->getPublicStatusLabel($created->getStatus()),
                'urgency' => $created->getUrgency()->value,
                'urgency_label' => $created->getUrgency()->label(),
                'machine' => [
                    'code' => $machine->getCode(),
                    'model' => $machine->getModel(),
                    'floor_wing' => $machine->getFloorWing(),
                ],
                'location' => [
                    'name' => $location->getName(),
                    'contact_phone' => $location->getContactPhone() ?? '',
                ],
                'message' => 'Aviso registrado con éxito. Un técnico ha sido notificado.',
            ];
        } catch (DuplicateIncidentException $e) {
            // Carrera ganada por otro usuario milisegundos antes: fusión atómica amigable
            $latestActive = $this->incidentRepo->findActiveByMachineId($machine->getId());
            if ($latestActive !== null) {
                return $this->mergeIntoExistingIncident(
                    $latestActive,
                    $machine,
                    $location,
                    $description,
                    $reporterName,
                    $retainedMoney,
                    $photoPath,
                    true
                );
            }

            // Fallback si la excepción no deja recuperar el ticket activo
            throw $e;
        }
    }

    /**
     * Anexa observaciones a un ticket existente ante un escaneo o envío concurrente (EARS 4.5).
     */
    private function mergeIntoExistingIncident(
        Incident $incident,
        Machine $machine,
        Location $location,
        string $description,
        ?string $reporterName,
        ?float $retainedMoney,
        ?string $photoPath,
        bool $isRaceCondition = false
    ): array {
        $prefix = $isRaceCondition
            ? 'Aviso adicional recibido de forma concurrente mediante código QR: '
            : 'Aviso adicional recibido mediante código QR: ';

        $commentText = $prefix . $description;
        if ($retainedMoney !== null) {
            $commentText .= sprintf(' (Importe retenido reclamado: %.2f €)', $retainedMoney);
        }

        $author = $reporterName ?? 'Usuario Informador (QR)';

        $comment = new IncidentComment(
            id: null,
            incidentId: (int)$incident->getId(),
            authorType: 'REPORTER',
            userId: null,
            authorName: $author,
            commentText: $commentText,
            photoPath: $photoPath,
            isInternal: false,
            createdAt: null,
            ticketCode: $incident->getTicketCode()
        );

        $this->incidentRepo->addComment($comment);

        return [
            'ticket_code' => $incident->getTicketCode(),
            'merged' => true,
            'status' => $incident->getStatus()->value,
            'status_label' => $this->getPublicStatusLabel($incident->getStatus()),
            'urgency' => $incident->getUrgency()->value,
            'urgency_label' => $incident->getUrgency()->label(),
            'machine' => [
                'code' => $machine->getCode(),
                'model' => $machine->getModel(),
                'floor_wing' => $machine->getFloorWing(),
            ],
            'location' => [
                'name' => $location->getName(),
                'contact_phone' => $location->getContactPhone() ?? '',
            ],
            'message' => "Otro usuario acaba de reportar una avería en esta máquina hace un momento (Ticket {$incident->getTicketCode()}). Hemos registrado tus observaciones en dicho ticket.",
        ];
    }

    /**
     * Devuelve una etiqueta amigable y tranquilizadora del estado para el consumidor.
     */
    private function getPublicStatusLabel(IncidentStatus $status): string
    {
        return match ($status) {
            IncidentStatus::REGISTERED => 'Aviso registrado - En espera de asignación técnica',
            IncidentStatus::ASSIGNED => 'Técnico de guardia asignado en camino',
            IncidentStatus::IN_PROGRESS => 'Técnico interviniendo en la máquina',
            IncidentStatus::PENDING_PARTS => 'Aviso en espera de repuesto especializado',
            IncidentStatus::REOPENED => 'Incidencia reabierta en revisión técnica',
            default => 'Aviso en gestión por el servicio técnico',
        };
    }
}
