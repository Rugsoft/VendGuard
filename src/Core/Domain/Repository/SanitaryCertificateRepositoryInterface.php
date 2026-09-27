<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\SanitaryCertificate;

/**
 * Interface SanitaryCertificateRepositoryInterface
 *
 * Contrato de repositorio para la emisión, consulta, suspensión cautelar
 * y generación de informes agregados de certificados sanitarios (Art. V.4 y RF-PREV-07).
 */
interface SanitaryCertificateRepositoryInterface
{
    /**
     * Emite y persiste un nuevo certificado sanitario oficial.
     *
     * @param array<string, mixed> $data Datos para la emisión del certificado:
     *      - preventive_order_id (int)
     *      - machine_id (int)
     *      - location_id (int)
     *      - technician_id (int)
     *      - technician_name (?string)
     *      - technician_operator_code (?string)
     *      - inspection_date (string)
     *      - valid_until (string)
     *      - temperature_measured (?float)
     *      - result (string)
     *      - certificate_code (?string)
     * @return SanitaryCertificate Entidad creada con código asignado
     */
    public function createCertificate(array $data): SanitaryCertificate;

    /**
     * Busca un certificado por su ID de clave primaria.
     *
     * @param int $id Identificador del certificado
     * @return SanitaryCertificate|null
     */
    public function findById(int $id): ?SanitaryCertificate;

    /**
     * Busca un certificado por su código oficial único (ej. 'CERT-2026-0001').
     *
     * @param string $code Código del certificado
     * @return SanitaryCertificate|null
     */
    public function findByCertificateCode(string $code): ?SanitaryCertificate;

    /**
     * Obtiene el certificado sanitario activo y vigente para una máquina según su código visible.
     *
     * @param string $machineCode Código visible de la máquina (ej: 'VEND-0101')
     * @return SanitaryCertificate|null Certificado vigente o null si está vencido/suspendido
     */
    public function findActiveByMachineCode(string $machineCode): ?SanitaryCertificate;

    /**
     * Obtiene el certificado sanitario activo y vigente para una máquina según su ID numérico.
     *
     * @param int $machineId Identificador numérico de la máquina
     * @return SanitaryCertificate|null Certificado vigente o null si está vencido/suspendido
     */
    public function findActiveByMachineId(int $machineId): ?SanitaryCertificate;

    /**
     * Recupera el certificado más reciente emitido para una máquina, independientemente de su estado.
     *
     * @param int $machineId Identificador de la máquina
     * @return SanitaryCertificate|null
     */
    public function findLatestByMachineId(int $machineId): ?SanitaryCertificate;

    /**
     * Aplica la suspensión cautelar automática a los certificados vigentes de una máquina
     * ante una avería sobrevenida de refrigeración o alerta sanitaria (Art. II y EARS 7.3).
     *
     * @param int $machineId Identificador de la máquina
     * @param string $reason Motivo justificado de la suspensión cautelar
     * @return int Número de certificados suspendidos
     */
    public function suspendByMachineId(int $machineId, string $reason): int;

    /**
     * Revoca formalmente los certificados de una máquina (ej. por baja definitiva o traslado).
     *
     * @param int $machineId Identificador de la máquina
     * @param string $reason Motivo de la revocación
     * @return int Número de certificados revocados
     */
    public function revokeByMachineId(int $machineId, string $reason): int;

    /**
     * Genera la estructura agregada para el Certificado Global Consolidado de Sede (EARS 7.2).
     *
     * Evalúa todas las máquinas instaladas en la ubicación para dictaminar si la sede
     * se califica como CONFORME, CONFORME_CON_OBSERVACIONES o CONDICIONADO.
     *
     * @param int $locationId Identificador de la sede
     * @return array<string, mixed> Informe global con desglose individual de máquinas
     */
    public function getGlobalSiteReport(int $locationId): array;
}
