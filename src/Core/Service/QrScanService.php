<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;

/**
 * QrScanService
 * 
 * Servicio de Aplicación para la resolución de accesos mediante escaneo de código QR.
 * Valida la existencia de la máquina en el parque activo, resuelve su sede física real
 * vigente de forma transparente (EARS 5.1), y determina el modo operativo adecuado:
 * 
 * 1. CAN_REPORT: Máquina limpia lista para nuevo aviso (con alerta de frío si es perecedera).
 * 2. ACTIVE_INCIDENT: Aviso activo preexistente con blindaje estricto de privacidad (Art. V.4).
 * 3. UNDER_WARRANTY: Avería resuelta dentro de la ventana legal de garantía de 48h (EARS 4.3).
 * 4. MachineNotFoundException (404): Si la máquina no existe o está dada de baja (EARS 5.2).
 * 
 * Cumple con Dogma Vanilla y los Artículos II, IV y V de la Constitución de VendGuard.
 */
class QrScanService
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
     * Resuelve el contexto completo de una máquina escaneada a partir de su código visible.
     *
     * @param string $machineCode Código alfanumérico visible rotulado en la máquina (ej: 'VEND-0101').
     * @param string|null $siteCode Código de sede opcional recibido en la URL (para detección de reubicación).
     * @return array<string, mixed> Estructura estandarizada con status_mode, datos de máquina, sede e incidencia.
     * @throws MachineNotFoundException Si la máquina no existe, está inactiva o su sede está dada de baja.
     */
    public function resolve(string $machineCode, ?string $siteCode = null): array
    {
        $cleanMachineCode = strtoupper(trim($machineCode));
        if ($cleanMachineCode === '') {
            throw new MachineNotFoundException();
        }

        // 1. Localizar la máquina en el repositorio
        $machine = $this->machineRepo->findByCode($cleanMachineCode);
        if ($machine === null || !$machine->isActive()) {
            throw new MachineNotFoundException();
        }

        // 2. Resolver la sede física real vigente (EARS 5.1: Transparente ante reubicaciones)
        $location = $this->locationRepo->findById($machine->getLocationId());
        if ($location === null || !$location->isActive()) {
            throw new MachineNotFoundException();
        }

        // 3. Inspeccionar el estado de incidencias vinculadas
        // A) Buscar aviso en curso activo
        $activeIncident = $this->incidentRepo->findActiveByMachineId($machine->getId());

        if ($activeIncident !== null) {
            $status = $activeIncident->getStatus();

            // Si está resuelta, evaluar periodo de garantía de 48 horas (EARS 4.3)
            if ($status->isResolved()) {
                if ($activeIncident->isInWarranty(48)) {
                    return $this->buildUnderWarrantyResponse($machine, $location, $activeIncident);
                }
                // Si la garantía venció, la máquina se considera limpia
                return $this->buildCanReportResponse($machine, $location);
            }

            // Si es un estado terminal (CLOSED o CANCELLED), la máquina está limpia (EARS 4.4)
            if ($status->isTerminal()) {
                return $this->buildCanReportResponse($machine, $location);
            }

            // Avería activa en curso: Blindaje estricto de privacidad del Artículo V.4
            return $this->buildActiveIncidentResponse($machine, $location, $activeIncident);
        }

        // B) Si no hay ticket activo, verificar si existe una avería resuelta recientemente en garantía
        $resolvedIncident = $this->incidentRepo->findActiveOrResolvedByMachineId($machine->getId());
        if ($resolvedIncident !== null && $resolvedIncident->getStatus()->isResolved() && $resolvedIncident->isInWarranty(48)) {
            return $this->buildUnderWarrantyResponse($machine, $location, $resolvedIncident);
        }

        // C) Máquina limpia sin avisos en curso: lista para emitir nuevo reporte (EARS 3.1)
        return $this->buildCanReportResponse($machine, $location);
    }

    /**
     * Construye la respuesta para el modo CAN_REPORT (formulario limpio de aviso).
     */
    private function buildCanReportResponse(Machine $machine, Location $location): array
    {
        return [
            'status_mode' => 'CAN_REPORT',
            'machine' => $this->formatMachineData($machine),
            'location' => $this->formatLocationData($location),
            'active_incident' => null,
        ];
    }

    /**
     * Construye la respuesta para el modo ACTIVE_INCIDENT aplicando el blindaje constitucional de privacidad.
     * 
     * Regla Constitucional de Oro (Art. V.4):
     * Los datos personales de técnicos, notas de taller, teléfonos privados y costes
     * son eliminados en origen de esta carga útil.
     */
    private function buildActiveIncidentResponse(Machine $machine, Location $location, Incident $incident): array
    {
        return [
            'status_mode' => 'ACTIVE_INCIDENT',
            'machine' => $this->formatMachineData($machine),
            'location' => $this->formatLocationData($location),
            'active_incident' => [
                'ticket_code' => $incident->getTicketCode(),
                'category' => $incident->getCategory()->value,
                'public_status' => $incident->getStatus()->value,
                'status_label' => $this->getPublicStatusLabel($incident->getStatus()),
                'reported_at' => $incident->getCreatedAt(),
            ],
        ];
    }

    /**
     * Construye la respuesta para el modo UNDER_WARRANTY (máquina recién reparada con opción de reapertura).
     */
    private function buildUnderWarrantyResponse(Machine $machine, Location $location, Incident $incident): array
    {
        $resolvedAt = $incident->getResolvedAt();
        $warrantyExpiresAt = null;

        if ($resolvedAt !== null) {
            $time = strtotime($resolvedAt);
            if ($time !== false) {
                $warrantyExpiresAt = gmdate('Y-m-d\TH:i:s\Z', $time + (48 * 3600));
            }
        }

        return [
            'status_mode' => 'UNDER_WARRANTY',
            'machine' => $this->formatMachineData($machine),
            'location' => $this->formatLocationData($location),
            'resolved_incident' => [
                'ticket_code' => $incident->getTicketCode(),
                'category' => $incident->getCategory()->value,
                'resolved_at' => $resolvedAt,
                'warranty_expires_at' => $warrantyExpiresAt,
            ],
        ];
    }

    /**
     * Formatea los datos identificativos y públicos de la máquina dispensadora.
     */
    private function formatMachineData(Machine $machine): array
    {
        $machineType = $machine->getMachineType();

        return [
            'id' => $machine->getId(),
            'code' => $machine->getCode(),
            'model' => $machine->getModel(),
            'machine_type' => $machineType->value,
            'floor_wing' => $machine->getFloorWing(),
            'is_perishable' => $machineType->isPerishable(),
            'notes' => $machine->getNotes(),
        ];
    }

    /**
     * Formatea los datos públicos de la sede física cliente.
     */
    private function formatLocationData(Location $location): array
    {
        return [
            'id' => $location->getId(),
            'site_code' => $location->getSiteCode(),
            'name' => $location->getName(),
            'contact_phone' => $location->getContactPhone() ?? '',
        ];
    }

    /**
     * Mapea un estado técnico a una etiqueta amigable y tranquilizadora para el consumidor final.
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
