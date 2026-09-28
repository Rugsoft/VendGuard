<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use DateTimeImmutable;
use PDO;
use VendGuard\Core\Domain\Model\SanitaryCertificate;
use VendGuard\Core\Domain\Repository\SanitaryCertificateRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * Repositorio PDO de Certificados Sanitarios Oficiales
 *
 * Implementa la emisión, búsqueda por código de máquina o sede, suspensión cautelar (Art. II)
 * y generación de informes consolidados de sede (Art. V.4 y RF-PREV-07) sin borrado físico (Art. III).
 */
class PdoSanitaryCertificateRepository implements SanitaryCertificateRepositoryInterface
{
    private PDO $pdo;

    public function __construct(
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function createCertificate(array $data): SanitaryCertificate
    {
        $code = $data['certificate_code'] ?? null;
        if (empty($code)) {
            $stmtSeq = $this->pdo->query("SELECT COALESCE(MAX(`id`), 0) + 1 FROM `sanitary_certificates`");
            $nextId = (int)$stmtSeq->fetchColumn();
            $code = sprintf('CERT-%s-%04d', date('Y'), $nextId);
        }

        $technicianName = $data['technician_name'] ?? null;
        $operatorCode = $data['technician_operator_code'] ?? null;

        if (empty($technicianName) || empty($operatorCode)) {
            $stmtU = $this->pdo->prepare("SELECT `name`, `operator_code` FROM `users` WHERE `id` = :id");
            $stmtU->execute([':id' => (int)$data['technician_id']]);
            $uRow = $stmtU->fetch(PDO::FETCH_ASSOC);
            if ($uRow) {
                if (empty($technicianName)) {
                    $technicianName = (string)$uRow['name'];
                }
                if (empty($operatorCode)) {
                    $operatorCode = !empty($uRow['operator_code']) 
                        ? (string)$uRow['operator_code'] 
                        : sprintf('OP-%02d', (int)$data['technician_id']);
                }
            } else {
                $technicianName = $technicianName ?? 'Técnico Autorizado';
                $operatorCode = $operatorCode ?? sprintf('OP-%02d', (int)$data['technician_id']);
            }
        }

        $sql = "
            INSERT INTO `sanitary_certificates` (
                `certificate_code`,
                `preventive_order_id`,
                `machine_id`,
                `location_id`,
                `technician_id`,
                `technician_name`,
                `technician_operator_code`,
                `inspection_date`,
                `valid_until`,
                `temperature_measured`,
                `result`,
                `status`,
                `created_at`,
                `updated_at`
            ) VALUES (
                :certificate_code,
                :preventive_order_id,
                :machine_id,
                :location_id,
                :technician_id,
                :technician_name,
                :technician_operator_code,
                :inspection_date,
                :valid_until,
                :temperature_measured,
                :result,
                :status,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ";

        $status = $data['status'] ?? SanitaryCertificate::STATUS_VALID;
        $result = $data['result'] ?? SanitaryCertificate::RESULT_CONFORME;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':certificate_code' => $code,
            ':preventive_order_id' => (int)$data['preventive_order_id'],
            ':machine_id' => (int)$data['machine_id'],
            ':location_id' => (int)$data['location_id'],
            ':technician_id' => (int)$data['technician_id'],
            ':technician_name' => $technicianName,
            ':technician_operator_code' => $operatorCode,
            ':inspection_date' => $data['inspection_date'],
            ':valid_until' => $data['valid_until'],
            ':temperature_measured' => isset($data['temperature_measured']) ? (float)$data['temperature_measured'] : null,
            ':result' => $result,
            ':status' => $status,
        ]);

        $newId = (int)$this->pdo->lastInsertId();

        return $this->findById($newId);
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?SanitaryCertificate
    {
        $sql = $this->getBaseSelectQuery() . " WHERE sc.`id` = :id LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $certificate = $this->hydrateCertificate($row);
        $certificate->setInspectedItems($this->getInspectedItemsForOrder($certificate->getPreventiveOrderId()));

        return $certificate;
    }

    /**
     * {@inheritdoc}
     */
    public function findByCertificateCode(string $code): ?SanitaryCertificate
    {
        $sql = $this->getBaseSelectQuery() . " WHERE sc.`certificate_code` = :code LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':code' => trim($code)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $certificate = $this->hydrateCertificate($row);
        $certificate->setInspectedItems($this->getInspectedItemsForOrder($certificate->getPreventiveOrderId()));

        return $certificate;
    }

    /**
     * {@inheritdoc}
     */
    public function findActiveByMachineCode(string $machineCode): ?SanitaryCertificate
    {
        $sql = $this->getBaseSelectQuery() . "
            WHERE m.`code` = :machine_code
              AND sc.`status` = 'VALID'
              AND sc.`valid_until` >= CURRENT_DATE()
              AND sc.`deleted_at` IS NULL
            ORDER BY sc.`inspection_date` DESC, sc.`id` DESC
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':machine_code' => trim($machineCode)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $certificate = $this->hydrateCertificate($row);
        $certificate->setInspectedItems($this->getInspectedItemsForOrder($certificate->getPreventiveOrderId()));

        return $certificate;
    }

    /**
     * {@inheritdoc}
     */
    public function findActiveByMachineId(int $machineId): ?SanitaryCertificate
    {
        $sql = $this->getBaseSelectQuery() . "
            WHERE sc.`machine_id` = :machine_id
              AND sc.`status` = 'VALID'
              AND sc.`valid_until` >= CURRENT_DATE()
              AND sc.`deleted_at` IS NULL
            ORDER BY sc.`inspection_date` DESC, sc.`id` DESC
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':machine_id' => $machineId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $certificate = $this->hydrateCertificate($row);
        $certificate->setInspectedItems($this->getInspectedItemsForOrder($certificate->getPreventiveOrderId()));

        return $certificate;
    }

    /**
     * {@inheritdoc}
     */
    public function findLatestByMachineId(int $machineId): ?SanitaryCertificate
    {
        $sql = $this->getBaseSelectQuery() . "
            WHERE sc.`machine_id` = :machine_id
              AND sc.`deleted_at` IS NULL
            ORDER BY sc.`inspection_date` DESC, sc.`id` DESC
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':machine_id' => $machineId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $certificate = $this->hydrateCertificate($row);
        $certificate->setInspectedItems($this->getInspectedItemsForOrder($certificate->getPreventiveOrderId()));

        return $certificate;
    }

    /**
     * {@inheritdoc}
     */
    public function suspendByMachineId(int $machineId, string $reason): int
    {
        $stmt = $this->pdo->prepare("
            UPDATE `sanitary_certificates`
            SET 
                `status` = 'SUSPENDED',
                `suspended_reason` = :reason,
                `suspended_at` = CURRENT_TIMESTAMP,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `machine_id` = :machine_id
              AND `status` = 'VALID'
              AND `deleted_at` IS NULL
        ");

        $stmt->execute([
            ':reason' => trim($reason),
            ':machine_id' => $machineId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function revokeByMachineId(int $machineId, string $reason): int
    {
        $stmt = $this->pdo->prepare("
            UPDATE `sanitary_certificates`
            SET 
                `status` = 'REVOKED',
                `suspended_reason` = :reason,
                `suspended_at` = CURRENT_TIMESTAMP,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `machine_id` = :machine_id
              AND `status` IN ('VALID', 'SUSPENDED')
              AND `deleted_at` IS NULL
        ");

        $stmt->execute([
            ':reason' => trim($reason),
            ':machine_id' => $machineId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function getGlobalSiteReport(int $locationId): array
    {
        // 1. Obtener datos de la sede
        $stmtLoc = $this->pdo->prepare("
            SELECT `id`, `site_code`, `name`, `address`
            FROM `locations`
            WHERE `id` = :id
            LIMIT 1
        ");
        $stmtLoc->execute([':id' => $locationId]);
        $loc = $stmtLoc->fetch(PDO::FETCH_ASSOC);

        if (!$loc) {
            return [];
        }

        // 2. Obtener todas las máquinas activas en la sede
        $stmtMach = $this->pdo->prepare("
            SELECT 
                m.`id`, 
                m.`code`, 
                m.`model`, 
                m.`machine_type`, 
                m.`floor_wing`, 
                m.`sanitary_status`,
                m.`next_sanitary_inspection_due`
            FROM `machines` m
            WHERE m.`location_id` = :location_id
              AND m.`is_active` = 1
              AND m.`deleted_at` IS NULL
            ORDER BY m.`floor_wing` ASC, m.`code` ASC
        ");
        $stmtMach->execute([':location_id' => $locationId]);
        $machines = $stmtMach->fetchAll(PDO::FETCH_ASSOC);

        $breakdown = [];
        $hasQuarantineOrExpired = false;
        $hasObservations = false;
        $issuesCount = 0;

        $stmtCert = $this->pdo->prepare("
            SELECT 
                sc.`id`,
                sc.`certificate_code`,
                sc.`inspection_date`,
                sc.`valid_until`,
                sc.`temperature_measured`,
                sc.`result`,
                sc.`status`,
                sc.`suspended_reason`
            FROM `sanitary_certificates` sc
            WHERE sc.`machine_id` = :machine_id
              AND sc.`deleted_at` IS NULL
            ORDER BY sc.`inspection_date` DESC, sc.`id` DESC
            LIMIT 1
        ");

        $today = date('Y-m-d');

        foreach ($machines as $mach) {
            $machId = (int)$mach['id'];
            $sanitaryStatus = (string)$mach['sanitary_status'];

            $stmtCert->execute([':machine_id' => $machId]);
            $certRow = $stmtCert->fetch(PDO::FETCH_ASSOC);

            $verdict = 'VENCIDO';
            $semaphore = 'RED';
            $quarantine = false;
            $detail = 'Inspección higiénico-sanitaria pendiente o vencida.';
            $certStatus = $certRow['status'] ?? null;

            if ($sanitaryStatus === 'QUARANTINE') {
                $verdict = 'NO_CONFORME';
                $semaphore = 'QUARANTINE';
                $quarantine = true;
                $hasQuarantineOrExpired = true;
                $issuesCount++;
                $detail = 'En cuarentena sanitaria por no conformidad higiénica o avería de frío.';
            } elseif ($sanitaryStatus === 'SEASONAL_PAUSE') {
                $verdict = 'PAUSA_ESTACIONAL';
                $semaphore = 'BLUE';
                $detail = 'Dispositivo en pausa estacional programada. Sin alimentos perecederos.';
            } elseif ($certRow && $certRow['status'] === 'SUSPENDED') {
                $verdict = 'SUSPENDIDO';
                $semaphore = 'QUARANTINE';
                $quarantine = true;
                $hasQuarantineOrExpired = true;
                $issuesCount++;
                $detail = 'Certificado suspendido cautelarmente: ' . ($certRow['suspended_reason'] ?? 'Avería sobrevenida');
            } elseif ($certRow && $certRow['status'] === 'VALID' && $certRow['valid_until'] >= $today) {
                $verdict = $certRow['result'];
                if ($verdict === 'CONFORME_CON_OBSERVACIONES') {
                    $semaphore = 'YELLOW';
                    $hasObservations = true;
                    $detail = 'Inspección conforme con observaciones de seguimiento. Vence el ' . $certRow['valid_until'] . '.';
                } else {
                    $semaphore = 'GREEN';
                    $detail = 'Inspección conforme en vigor hasta ' . $certRow['valid_until'] . '.';
                }
            } elseif ($certRow && $certRow['valid_until'] < $today) {
                // Certificado expirado
                $hasQuarantineOrExpired = true;
                $issuesCount++;
                $semaphore = 'RED';
                $verdict = 'VENCIDO';
                $detail = 'Certificado sanitario expirado el ' . $certRow['valid_until'] . '. Requiere inspección urgente.';
            } else {
                // Sin certificado previo registrado
                $nextDue = $mach['next_sanitary_inspection_due'] ?? null;
                if ($nextDue && $nextDue >= $today) {
                    $semaphore = 'YELLOW';
                    $verdict = 'PENDIENTE_INSPECCION';
                    $detail = 'Primera inspección sanitaria programada para el ' . $nextDue . '.';
                } else {
                    $hasQuarantineOrExpired = true;
                    $issuesCount++;
                    $semaphore = 'RED';
                    $verdict = 'VENCIDO';
                    $detail = 'Inspección sanitaria periódica no realizada o vencida.';
                }
            }

            $breakdown[] = [
                'code' => $mach['code'],
                'model' => $mach['model'],
                'machine_type' => $mach['machine_type'],
                'floor_wing' => $mach['floor_wing'],
                'verdict' => $verdict,
                'semaphore' => $semaphore,
                'quarantine' => $quarantine,
                'last_inspection_date' => $certRow['inspection_date'] ?? null,
                'last_temperature_celsius' => isset($certRow['temperature_measured']) ? (float)$certRow['temperature_measured'] : null,
                'valid_until' => $certRow['valid_until'] ?? null,
                'certificate_code' => $certRow['certificate_code'] ?? null,
                'certificate_status' => $certStatus,
                'detail' => $detail,
            ];
        }

        // Dictamen Global del Centro (EARS 7.2)
        if ($hasQuarantineOrExpired) {
            $globalVerdict = 'CONDICIONADO';
            $verdictExplanation = sprintf(
                'El centro dispone de %d máquina(s) en cuarentena sanitaria o con inspección vencida, sujetas a subsanación técnica.',
                $issuesCount
            );
        } elseif ($hasObservations) {
            $globalVerdict = 'CONFORME_CON_OBSERVACIONES';
            $verdictExplanation = 'Todas las máquinas cumplen los estándares normativos críticos con observaciones secundarias de seguimiento.';
        } else {
            $globalVerdict = 'CONFORME';
            $verdictExplanation = 'Todas las máquinas de la sede cuentan con certificación higiénico-sanitaria oficial en vigor.';
        }

        return [
            'global_certificate_code' => sprintf('%s-SAN-%s', $loc['site_code'], date('Y-m')),
            'location' => [
                'id' => (int)$loc['id'],
                'site_code' => (string)$loc['site_code'],
                'name' => (string)$loc['name'],
                'address' => (string)$loc['address'],
            ],
            'issue_date' => (new DateTimeImmutable())->format('c'),
            'global_verdict' => $globalVerdict,
            'verdict_explanation' => $verdictExplanation,
            'has_quarantine_or_expired' => $hasQuarantineOrExpired,
            'total_machines' => count($machines),
            'machines_breakdown' => $breakdown,
        ];
    }

    private function getBaseSelectQuery(): string
    {
        return "
            SELECT 
                sc.`id`,
                sc.`certificate_code`,
                sc.`preventive_order_id`,
                sc.`machine_id`,
                sc.`location_id`,
                sc.`technician_id`,
                sc.`technician_name`,
                sc.`technician_operator_code`,
                sc.`inspection_date`,
                sc.`valid_until`,
                sc.`temperature_measured`,
                sc.`result`,
                sc.`status`,
                sc.`suspended_reason`,
                sc.`suspended_at`,
                sc.`created_at`,
                sc.`updated_at`,
                sc.`deleted_at`,
                m.`code` AS `machine_code`,
                m.`model` AS `machine_model`,
                m.`machine_type`,
                l.`site_code` AS `location_site_code`,
                l.`name` AS `location_name`,
                l.`address` AS `location_address`,
                po.`order_code`
            FROM `sanitary_certificates` sc
            INNER JOIN `machines` m ON sc.`machine_id` = m.`id`
            INNER JOIN `locations` l ON sc.`location_id` = l.`id`
            INNER JOIN `preventive_orders` po ON sc.`preventive_order_id` = po.`id`
        ";
    }

    /**
     * @param array<string, mixed> $row
     * @return SanitaryCertificate
     */
    private function hydrateCertificate(array $row): SanitaryCertificate
    {
        $machineData = [
            'id' => (int)$row['machine_id'],
            'code' => (string)$row['machine_code'],
            'model' => (string)$row['machine_model'],
            'serial_number' => (string)($row['machine_serial_number'] ?? $row['machine_code']),
            'machine_type' => (string)$row['machine_type'],
        ];

        $locationData = [
            'id' => (int)$row['location_id'],
            'site_code' => (string)$row['location_site_code'],
            'name' => (string)$row['location_name'],
            'address' => (string)$row['location_address'],
        ];

        return new SanitaryCertificate(
            (int)$row['id'],
            (string)$row['certificate_code'],
            (int)$row['preventive_order_id'],
            (int)$row['machine_id'],
            (int)$row['location_id'],
            (int)$row['technician_id'],
            (string)$row['technician_name'],
            (string)$row['technician_operator_code'],
            (string)$row['inspection_date'],
            (string)$row['valid_until'],
            $row['temperature_measured'] !== null ? (float)$row['temperature_measured'] : null,
            (string)$row['result'],
            (string)$row['status'],
            $row['suspended_reason'] ?? null,
            $row['suspended_at'] ?? null,
            (string)$row['created_at'],
            (string)$row['updated_at'],
            $row['deleted_at'] ?? null,
            $machineData,
            $locationData
        );
    }

    /**
     * Obtiene el desglose de ítems normativos inspeccionados para adjuntar al certificado.
     *
     * @param int $orderId
     * @return array<int, array<string, mixed>>
     */
    private function getInspectedItemsForOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT `item_code`, `item_description`, `is_critical`, `status`, `observations`
            FROM `preventive_order_items`
            WHERE `preventive_order_id` = :order_id
            ORDER BY `is_critical` DESC, `id` ASC
        ");

        $stmt->execute([':order_id' => $orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'item' => $r['item_description'],
                'item_code' => $r['item_code'],
                'status' => $r['status'] === 'PASS' ? 'CONFORME' : ($r['status'] === 'WARN' ? 'OBSERVACIÓN' : 'NO_CONFORME'),
                'is_critical' => (bool)$r['is_critical'],
                'observations' => $r['observations'],
            ];
        }

        return $items;
    }
}
