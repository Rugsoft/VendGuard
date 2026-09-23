<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\ActiveMachinesBlockedException;
use VendGuard\Core\Domain\Exception\InactiveRecordCollisionException;
use VendGuard\Core\Domain\Exception\LocationNotFoundException;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;

/**
 * AdminLocationService
 * 
 * Servicio de Aplicación responsable de orquestar la administración integral del maestro de Sedes.
 * Aplica reglas de negocio estrictas, detección de colisiones con registros inactivos y auditoría inmutable (RF-01, RF-05).
 */
class AdminLocationService
{
    private LocationRepositoryInterface $locationRepo;
    private AuditLogger $auditLogger;

    public function __construct(
        LocationRepositoryInterface $locationRepo,
        ?AuditLogger $auditLogger = null
    ) {
        $this->locationRepo = $locationRepo;
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Recupera el catálogo de sedes con filtros de estado y búsqueda parcial (RF-01, EARS 1.1).
     *
     * @param string $status 'active', 'inactive' o 'all'.
     * @param string|null $search Término de búsqueda opcional.
     * @return array<array<string, mixed>>
     */
    public function listLocations(string $status = 'all', ?string $search = null): array
    {
        return $this->locationRepo->findAll($status, $search);
    }

    /**
     * Recupera una sede por su identificador primario (RF-01).
     *
     * @param int $id
     * @param bool $allowDeleted Si es true, permite recuperar registros inactivos o dados de baja.
     * @return Location
     * @throws LocationNotFoundException Si la sede no existe.
     */
    public function getLocation(int $id, bool $allowDeleted = false): Location
    {
        $location = $this->locationRepo->findById($id, $allowDeleted);
        if ($location === null) {
            throw new LocationNotFoundException($id);
        }

        return $location;
    }

    /**
     * Da de alta una nueva sede cliente con validación de código único y emisión de auditoría (RF-01, EARS 1.2, 1.6).
     *
     * @param array<string, mixed> $data Datos de la sede a registrar.
     * @param array{id: int|null, role: string, name: string} $actor Usuario coordinador actuante.
     * @param string|null $clientIp Dirección IP del cliente para metadatos de auditoría.
     * @return Location Sede recién creada.
     * @throws InvalidArgumentException Si algún dato no cumple las especificaciones requeridas.
     * @throws InactiveRecordCollisionException Si el código ya existe (activo o inactivo).
     */
    public function createLocation(array $data, array $actor, ?string $clientIp = null): Location
    {
        $siteCode = strtoupper(trim((string)($data['site_code'] ?? '')));
        $name = trim((string)($data['name'] ?? ''));
        $address = trim((string)($data['address'] ?? ''));
        $contactName = isset($data['contact_name']) && $data['contact_name'] !== '' ? trim((string)$data['contact_name']) : null;
        $contactPhone = isset($data['contact_phone']) && $data['contact_phone'] !== '' ? trim((string)$data['contact_phone']) : null;

        $this->validateSiteCode($siteCode);
        $this->validateName($name);
        $this->validateAddress($address);
        if ($contactPhone !== null) {
            $this->validatePhone($contactPhone);
        }

        // Detección de colisiones de código único (RF-01, EARS 1.6)
        $existing = $this->locationRepo->findBySiteCode($siteCode, onlyActive: false, allowDeleted: true);
        if ($existing !== null) {
            if ($existing->isActive()) {
                throw new InactiveRecordCollisionException(
                    'LOCATION_ALREADY_EXISTS_ACTIVE',
                    "El código de sede '{$siteCode}' ya se encuentra registrado y activo.",
                    $existing->getId(),
                    'LOCATION',
                    false
                );
            }

            throw new InactiveRecordCollisionException(
                'LOCATION_ALREADY_EXISTS_INACTIVE',
                "El código de sede '{$siteCode}' ya existe pero se encuentra dado de baja.",
                $existing->getId(),
                'LOCATION',
                true
            );
        }

        $created = $this->locationRepo->create([
            'site_code'     => $siteCode,
            'name'          => $name,
            'address'       => $address,
            'contact_name'  => $contactName,
            'contact_phone' => $contactPhone,
        ]);

        // Auditoría inmutable sincrónica (RF-05, EARS 4.1)
        $metadata = [];
        if ($clientIp !== null && $clientIp !== '') {
            $metadata['ip'] = $clientIp;
        }

        $this->auditLogger->logLocationEvent(
            $created->getId(),
            'LOCATION_CREATED',
            $actor,
            null,
            [
                'site_code'     => $created->getSiteCode(),
                'name'          => $created->getName(),
                'address'       => $created->getAddress(),
                'contact_name'  => $created->getContactName(),
                'contact_phone' => $created->getContactPhone(),
            ],
            !empty($metadata) ? $metadata : null
        );

        return $created;
    }

    /**
     * Actualiza los datos descriptivos de una sede cliente existente (RF-01, EARS 1.3).
     * El código de sede (site_code) es inmutable.
     *
     * @param int $id Identificador numérico de la sede.
     * @param array<string, mixed> $data Campos a modificar.
     * @param array{id: int|null, role: string, name: string} $actor Usuario coordinador actuante.
     * @return Location Sede actualizada.
     * @throws LocationNotFoundException Si la sede no existe o está dada de baja.
     * @throws InvalidArgumentException Si los datos introducidos no son válidos.
     */
    public function updateLocation(int $id, array $data, array $actor): Location
    {
        $location = $this->locationRepo->findById($id, allowDeleted: false);
        if ($location === null) {
            throw new LocationNotFoundException($id);
        }

        $cleanData = [];
        $changedFields = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string)$data['name']);
            $this->validateName($name);
            $cleanData['name'] = $name;
            if ($name !== $location->getName()) {
                $changedFields[] = 'name';
            }
        }

        if (array_key_exists('address', $data)) {
            $address = trim((string)$data['address']);
            $this->validateAddress($address);
            $cleanData['address'] = $address;
            if ($address !== $location->getAddress()) {
                $changedFields[] = 'address';
            }
        }

        if (array_key_exists('contact_name', $data)) {
            $contactName = $data['contact_name'] !== null && $data['contact_name'] !== '' ? trim((string)$data['contact_name']) : null;
            $cleanData['contact_name'] = $contactName;
            if ($contactName !== $location->getContactName()) {
                $changedFields[] = 'contact_name';
            }
        }

        if (array_key_exists('contact_phone', $data)) {
            $contactPhone = $data['contact_phone'] !== null && $data['contact_phone'] !== '' ? trim((string)$data['contact_phone']) : null;
            if ($contactPhone !== null) {
                $this->validatePhone($contactPhone);
            }
            $cleanData['contact_phone'] = $contactPhone;
            if ($contactPhone !== $location->getContactPhone()) {
                $changedFields[] = 'contact_phone';
            }
        }

        if (empty($changedFields)) {
            return $location;
        }

        $previousState = [
            'name'          => $location->getName(),
            'address'       => $location->getAddress(),
            'contact_name'  => $location->getContactName(),
            'contact_phone' => $location->getContactPhone(),
        ];

        $this->locationRepo->update($id, $cleanData);
        $updated = $this->locationRepo->findById($id, allowDeleted: false);
        if ($updated === null) {
            throw new LocationNotFoundException($id);
        }

        $newState = [
            'name'          => $updated->getName(),
            'address'       => $updated->getAddress(),
            'contact_name'  => $updated->getContactName(),
            'contact_phone' => $updated->getContactPhone(),
        ];

        // Auditoría inmutable sincrónica (RF-05, EARS 4.2)
        $this->auditLogger->logLocationEvent(
            $id,
            'LOCATION_UPDATED',
            $actor,
            $previousState,
            $newState,
            ['changed_fields' => $changedFields]
        );

        return $updated;
    }

    /**
     * Da de baja lógica a una sede comprobando que no tenga máquinas activas asociadas (RF-01, EARS 1.4, 1.5).
     *
     * @param int $id Identificador numérico de la sede.
     * @param array{id: int|null, role: string, name: string} $actor Usuario coordinador actuante.
     * @return bool
     * @throws LocationNotFoundException Si la sede no existe o ya está dada de baja.
     * @throws ActiveMachinesBlockedException Si la sede contiene máquinas activas vinculadas.
     */
    public function deactivateLocation(int $id, array $actor): bool
    {
        $location = $this->locationRepo->findById($id, allowDeleted: false);
        if ($location === null) {
            throw new LocationNotFoundException($id);
        }

        // Verificación de dependencia referencial e integridad (EARS 1.4)
        $activeMachinesCount = $this->locationRepo->countActiveMachines($id);
        if ($activeMachinesCount > 0) {
            throw new ActiveMachinesBlockedException($activeMachinesCount, $id);
        }

        $this->locationRepo->softDelete($id);

        // Auditoría inmutable sincrónica (RF-05, EARS 4.3)
        $this->auditLogger->logLocationEvent(
            $id,
            'LOCATION_DEACTIVATED',
            $actor,
            ['is_active' => true],
            ['is_active' => false],
            ['reason' => 'Baja administrativa']
        );

        return true;
    }

    /**
     * Reactiva una sede cliente previamente dada de baja lógica (RF-01, EARS 1.6).
     *
     * @param int $id Identificador numérico de la sede.
     * @param array{id: int|null, role: string, name: string} $actor Usuario coordinador actuante.
     * @return Location Sede reactivada.
     * @throws LocationNotFoundException Si la sede no existe.
     */
    public function reactivateLocation(int $id, array $actor): Location
    {
        $location = $this->locationRepo->findById($id, allowDeleted: true);
        if ($location === null) {
            throw new LocationNotFoundException($id);
        }

        if ($location->isActive()) {
            return $location;
        }

        $this->locationRepo->restore($id);
        $reactivated = $this->locationRepo->findById($id, allowDeleted: false);
        if ($reactivated === null) {
            throw new LocationNotFoundException($id);
        }

        // Auditoría inmutable sincrónica (RF-05, EARS 4.4)
        $this->auditLogger->logLocationEvent(
            $id,
            'LOCATION_REACTIVATED',
            $actor,
            ['is_active' => false],
            ['is_active' => true],
            ['reason' => 'Reactivación administrativa']
        );

        return $reactivated;
    }

    /**
     * Valida el formato del código alfanumérico único de sede cliente.
     */
    private function validateSiteCode(string $code): void
    {
        if (!preg_match('/^[A-Z0-9-]{3,32}$/', $code)) {
            throw new InvalidArgumentException(
                "El código de sede '{$code}' no es válido. Debe contener entre 3 y 32 caracteres alfanuméricos en mayúsculas o guiones (ej: SEDE-VAL-01)."
            );
        }
    }

    /**
     * Valida la longitud y consistencia del nombre de la sede.
     */
    private function validateName(string $name): void
    {
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            throw new InvalidArgumentException(
                'El nombre de la sede es obligatorio y debe tener entre 3 y 150 caracteres.'
            );
        }
    }

    /**
     * Valida la longitud y consistencia de la dirección postal de la sede.
     */
    private function validateAddress(string $address): void
    {
        if (mb_strlen($address) < 5 || mb_strlen($address) > 255) {
            throw new InvalidArgumentException(
                'La dirección física de la sede es obligatoria y debe tener entre 5 y 255 caracteres.'
            );
        }
    }

    /**
     * Valida el formato de teléfono de contacto.
     */
    private function validatePhone(string $phone): void
    {
        if (!preg_match('/^[0-9+\s\-]{9,20}$/', $phone)) {
            throw new InvalidArgumentException(
                "El número de teléfono de contacto '{$phone}' no tiene un formato válido."
            );
        }
    }
}
