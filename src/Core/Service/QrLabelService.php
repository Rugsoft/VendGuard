<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Domain\Model\QrCodeData;
use VendGuard\Core\Domain\Model\QrLabelConfig;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Service\NativeSvgQrRenderer;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;

/**
 * QrLabelService
 * 
 * Servicio de Aplicación para la emisión, personalización y descarga de etiquetas QR
 * adhesivas (individuales y por lotes de sede en A4).
 * 
 * Permite ajustar el teléfono de asistencia técnica, actualizar opcionalmente el registro
 * maestro de la sede en BD (RF-01, EARS 1.2, 1.3), y componer el catálogo de etiquetas
 * vectoriales para impresión masiva (RF-02, EARS 2.2, 2.3).
 * 
 * Cumple con Dogma Vanilla y los Artículos I, II, IV y V de la Constitución.
 */
class QrLabelService
{
    private MachineRepositoryInterface $machineRepo;
    private LocationRepositoryInterface $locationRepo;
    private string $baseUrl;

    public function __construct(
        ?MachineRepositoryInterface $machineRepo = null,
        ?LocationRepositoryInterface $locationRepo = null,
        string $baseUrl = 'https://vendguard.onrender.com'
    ) {
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Genera la etiqueta individual en formato vectorial SVG para una máquina concreta.
     *
     * @param int $machineId Identificador único de la máquina.
     * @param string|null $customPhone Teléfono personalizado de asistencia para la etiqueta (opcional).
     * @param bool $updateLocation Si es true y se indicó teléfono, actualiza el registro maestro de la sede en BD.
     * @return array<string, mixed> Metadatos de máquina, sede, teléfono y código SVG generado.
     * @throws MachineNotFoundException Si la máquina o sede no existen o están inactivas.
     * @throws InvalidArgumentException Si los identificadores o parámetros son inválidos.
     */
    public function getMachineLabel(
        int $machineId,
        ?string $customPhone = null,
        bool $updateLocation = false
    ): array {
        if ($machineId <= 0) {
            throw new InvalidArgumentException('El identificador de máquina debe ser un entero positivo.');
        }

        // 1. Obtener la máquina del repositorio
        $machine = $this->machineRepo->findById($machineId);
        if ($machine === null || !$machine->isActive()) {
            throw new MachineNotFoundException(
                'MACHINE_NOT_FOUND_OR_INACTIVE',
                "La máquina solicitada (#{$machineId}) no existe o se encuentra inactiva."
            );
        }

        // 2. Obtener la sede física de la máquina
        $location = $this->locationRepo->findById($machine->getLocationId());
        if ($location === null || !$location->isActive()) {
            throw new MachineNotFoundException(
                'LOCATION_NOT_FOUND_OR_INACTIVE',
                "La sede vinculada a la máquina (#{$machine->getLocationId()}) no existe o está inactiva."
            );
        }

        // 3. Determinar el teléfono de soporte aplicable (EARS 1.2, 1.3)
        $cleanCustomPhone = $customPhone !== null ? trim($customPhone) : '';
        if ($cleanCustomPhone !== '') {
            $supportPhone = $cleanCustomPhone;
            if ($updateLocation) {
                // Actualizar registro maestro de la sede en BD (EARS 1.3)
                $this->locationRepo->updateContactPhone($location->getId(), $supportPhone);
            }
        } else {
            $supportPhone = $location->getContactPhone() ?? '900000000';
            if ($supportPhone === '') {
                $supportPhone = '900000000';
            }
        }

        // 4. Construir la URL codificada en el QR mediante QrCodeData (Value Object)
        $qrData = new QrCodeData($machine->getCode(), $location->getSiteCode(), $this->baseUrl);
        $targetUrl = $qrData->getTargetUrl();

        // 5. Configurar y renderizar la etiqueta adhesiva en SVG nativo
        $labelConfig = new QrLabelConfig(
            machineCode: $machine->getCode(),
            machineModel: $machine->getModel(),
            machineType: $machine->getMachineType()->value,
            locationName: $location->getName(),
            floorWing: $machine->getFloorWing(),
            supportPhone: $supportPhone,
            targetUrl: $targetUrl,
            isPerishable: $machine->getMachineType()->isPerishable()
        );

        $svgContent = NativeSvgQrRenderer::renderLabel($labelConfig);

        return [
            'machine' => [
                'id' => $machine->getId(),
                'code' => $machine->getCode(),
                'model' => $machine->getModel(),
                'machine_type' => $machine->getMachineType()->value,
                'floor_wing' => $machine->getFloorWing(),
            ],
            'location' => [
                'id' => $location->getId(),
                'site_code' => $location->getSiteCode(),
                'name' => $location->getName(),
                'phone' => ($updateLocation && $cleanCustomPhone !== '') ? $supportPhone : ($location->getContactPhone() ?? ''),
            ],
            'support_phone' => $supportPhone,
            'qr_target_url' => $targetUrl,
            'svg_content' => $svgContent,
        ];
    }

    /**
     * Genera el lote completo de etiquetas con código QR para todas las máquinas activas de una sede (A4 batch).
     *
     * @param int $locationId Identificador único de la sede cliente.
     * @return array<string, mixed> Colección de etiquetas de las máquinas de la sede en formato vectorial.
     * @throws MachineNotFoundException Si la sede no existe o se encuentra inactiva.
     * @throws InvalidArgumentException Si el ID de sede es inválido.
     */
    public function getLocationBatch(int $locationId): array
    {
        if ($locationId <= 0) {
            throw new InvalidArgumentException('El identificador de sede debe ser un entero positivo.');
        }

        // 1. Obtener y validar la sede cliente
        $location = $this->locationRepo->findById($locationId);
        if ($location === null || !$location->isActive()) {
            throw new MachineNotFoundException(
                'LOCATION_NOT_FOUND_OR_INACTIVE',
                "La sede solicitada (#{$locationId}) no existe o se encuentra inactiva."
            );
        }

        // 2. Recuperar máquinas activas de la sede
        $machines = $this->machineRepo->findActiveByLocationId($locationId);

        $supportPhone = $location->getContactPhone() ?? '900000000';
        if ($supportPhone === '') {
            $supportPhone = '900000000';
        }

        $items = [];
        foreach ($machines as $machine) {
            $qrData = new QrCodeData($machine->getCode(), $location->getSiteCode(), $this->baseUrl);
            $targetUrl = $qrData->getTargetUrl();

            $labelConfig = new QrLabelConfig(
                machineCode: $machine->getCode(),
                machineModel: $machine->getModel(),
                machineType: $machine->getMachineType()->value,
                locationName: $location->getName(),
                floorWing: $machine->getFloorWing(),
                supportPhone: $supportPhone,
                targetUrl: $targetUrl,
                isPerishable: $machine->getMachineType()->isPerishable()
            );

            $svgContent = NativeSvgQrRenderer::renderLabel($labelConfig);

            $items[] = [
                'machine_id' => $machine->getId(),
                'code' => $machine->getCode(),
                'model' => $machine->getModel(),
                'machine_type' => $machine->getMachineType()->value,
                'floor_wing' => $machine->getFloorWing(),
                'qr_target_url' => $targetUrl,
                'svg_content' => $svgContent,
            ];
        }

        return [
            'location' => [
                'id' => $location->getId(),
                'site_code' => $location->getSiteCode(),
                'name' => $location->getName(),
                'contact_phone' => $supportPhone,
            ],
            'total_machines' => count($items),
            'items' => $items,
        ];
    }
}
