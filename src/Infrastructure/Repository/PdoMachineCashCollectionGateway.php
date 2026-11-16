<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Repository\MachineCashCollectionGatewayInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoMachineCashCollectionGateway
 *
 * Cerrojos PDO del Módulo 35 (Tabla 49 `machine_cash_collections`; RF-02.1,
 * RF-03.1, D3, D4, C4, Art. II.2, Art. V.4).
 *
 * Tres fotografías, siempre con `SELECT ... FOR UPDATE`:
 *   1. La máquina y su sede, con el estado sanitario, la cobertura territorial
 *      del reponedor, la conserjería física y los snapshots del operario.
 *   2. La visita in situ que se vincula al corte (`D1`, `C4`).
 *   3. El expediente de reintegro que se liquida en conserjería (`D3`).
 *
 * No decide nada: devuelve hechos. Las reglas viven en
 * `MachineCashCollectionService` y en `MachineCashCollectionValidator`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoMachineCashCollectionGateway implements MachineCashCollectionGatewayInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function lockMachineContext(int $machineId, int $restockerId): ?array
    {
        if ($machineId <= 0 || $restockerId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                m.id,
                m.code AS machine_code,
                m.location_id,
                m.sanitary_status,
                m.is_blocked_no_access,
                l.has_physical_reception,
                EXISTS (
                    SELECT 1
                    FROM restocker_zone_assignments rza
                    INNER JOIN operational_zones oz ON oz.id = rza.zone_id
                    WHERE rza.restocker_id = :cover_restocker_id
                      AND rza.zone_id = l.zone_id
                      AND rza.removed_at IS NULL
                      AND oz.is_active = TRUE
                ) AS is_covered,
                EXISTS (
                    SELECT 1
                    FROM incidents i
                    WHERE i.machine_id = m.id
                      AND i.deleted_at IS NULL
                      AND i.status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')
                      AND i.category = 'TEMPERATURE_COLD'
                ) AS has_open_thermal_breakdown,
                u.name AS restocker_name,
                u.operator_code AS restocker_code
            FROM machines m
            INNER JOIN locations l ON l.id = m.location_id
            INNER JOIN users u ON u.id = :actor_restocker_id
            WHERE m.id = :machine_id
              AND m.is_active = TRUE
              AND m.deleted_at IS NULL
            FOR UPDATE OF m
        ");
        $stmt->bindValue(':cover_restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->bindValue(':actor_restocker_id', $restockerId, PDO::PARAM_INT);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'machine_code' => (string)$row['machine_code'],
            'location_id' => (int)$row['location_id'],
            'sanitary_status' => (string)$row['sanitary_status'],
            'is_out_of_service' => self::toBool($row['is_blocked_no_access']),
            'is_covered' => self::toBool($row['is_covered']),
            'has_open_thermal_breakdown' => self::toBool($row['has_open_thermal_breakdown']),
            'has_physical_reception' => self::toBool($row['has_physical_reception']),
            'restocker_name' => (string)$row['restocker_name'],
            'restocker_code' => trim((string)($row['restocker_code'] ?? '')) !== ''
                ? (string)$row['restocker_code']
                : 'REP-000',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function lockRestockVisit(int $visitId, int $machineId): ?array
    {
        if ($visitId <= 0 || $machineId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('
            SELECT id, machine_id, restocker_id, status
            FROM machine_restock_visits
            WHERE id = :visit_id
              AND machine_id = :machine_id
            FOR UPDATE
        ');
        $stmt->bindValue(':visit_id', $visitId, PDO::PARAM_INT);
        $stmt->bindValue(':machine_id', $machineId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'machine_id' => (int)$row['machine_id'],
            'restocker_id' => (int)$row['restocker_id'],
            'status' => (string)$row['status'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function lockRefundForReception(int $refundRequestId): ?array
    {
        if ($refundRequestId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('
            SELECT id, machine_id, status, compensation_method, claimed_amount, is_active
            FROM refund_requests
            WHERE id = :refund_request_id
            FOR UPDATE
        ');
        $stmt->bindValue(':refund_request_id', $refundRequestId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'machine_id' => (int)$row['machine_id'],
            'status' => (string)$row['status'],
            'compensation_method' => (string)$row['compensation_method'],
            'claimed_amount' => round((float)$row['claimed_amount'], 2),
            'is_active' => self::toBool($row['is_active']),
        ];
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 't', 'true', 'y', 'yes'], true);
    }
}
