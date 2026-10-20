<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;
use VendGuard\Core\Domain\Exception\PrimaryTechnicianConflictException;
use VendGuard\Core\Domain\Exception\ZoneNotFoundException;
use VendGuard\Core\Domain\Exception\ZoneValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\OperationalZone;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\OperationalZoneRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\PostalCode;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\UniqueViolationDetector;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

/**
 * PdoOperationalZoneRepository
 *
 * Implementación nativa con PDO del catálogo territorial del Módulo 17:
 * zonas operativas, sus códigos postales y su cuadrante técnico.
 *
 * Decisiones que el código hace explícitas:
 *
 *  - **El listado es una sola sentencia.** Los cuatro contadores (sedes,
 *    titulares, suplentes y códigos postales) viajan como subconsultas
 *    correlacionadas, de modo que el panel del coordinador no paga una consulta
 *    por zona por muy grande que sea el parque (RNF-01). Los códigos postales se
 *    leen aparte, con `findPostalCodes()`, porque agregar un array dentro de la
 *    misma sentencia exigiría una función de agregación distinta en cada motor y
 *    esta lectura fija **no** es un N+1: son dos consultas para cualquier número
 *    de zonas.
 *  - **La titularidad única se comprueba antes de escribir.** El índice parcial
 *    `uq_technician_primary_zone` la impone igualmente, pero el coordinador
 *    necesita saber *qué zona perdería* antes de confirmar, y eso el motor no lo
 *    cuenta al lanzar un `23505` (RF-02.2, QA 9).
 *  - **No hay `DELETE`.** La zona se desactiva, la vinculación se extingue con
 *    `removed_at`, y el histórico se conserva (Art. III.1, QA 10).
 *
 * Los identificadores se escriben desnudos y los booleanos cruzan enlazados con
 * `PDO::PARAM_BOOL`, que es la forma que los dos drivers del proyecto traducen al
 * tipo de cada motor (D-3, T-HYBRID-25).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoOperationalZoneRepository implements OperationalZoneRepositoryInterface
{
    /** Columnas del catálogo que proyecta la entidad. */
    private const ZONE_COLUMNS = 'id, code, name, color_hex, description, is_active, created_at, updated_at';

    private PDO $pdo;

    private SqlDialectAdapter $dialect;

    /**
     * Registro de auditoría usado por el traslado de CP.
     *
     * Debe compartir la conexión del repositorio: los eventos matriz e hijos se
     * escriben dentro de la misma transacción que el movimiento y se deshacen con
     * él (Resolución QA 6).
     */
    private AuditLogRepositoryInterface $auditRepo;

    public function __construct(?PDO $pdo = null, ?AuditLogRepositoryInterface $auditRepo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->dialect = SqlDialectAdapter::forConnection($this->pdo);
        $this->auditRepo = $auditRepo ?? new PdoAuditLogRepository($this->pdo);
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $zoneId, bool $includeInactive = false): ?OperationalZone
    {
        $sql = 'SELECT ' . self::ZONE_COLUMNS . ' FROM operational_zones WHERE id = :zone_id';

        if (!$includeInactive) {
            $sql .= ' AND is_active = ' . $this->dialect->booleanLiteral(true);
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : OperationalZone::fromDatabaseRow($row);
    }

    /**
     * {@inheritDoc}
     */
    public function findByCode(string $code): ?OperationalZone
    {
        $normalizedCode = mb_strtoupper(trim($code));
        if ($normalizedCode === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ZONE_COLUMNS . ' FROM operational_zones WHERE code = :code LIMIT 1'
        );
        $stmt->bindValue(':code', $normalizedCode, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row === false ? null : OperationalZone::fromDatabaseRow($row);
    }

    /**
     * {@inheritDoc}
     */
    public function findAllWithCounts(bool $includeInactive = true): array
    {
        return $this->fetchWithCounts(null, $includeInactive);
    }

    /**
     * {@inheritDoc}
     */
    public function findWithCounts(int $zoneId): ?array
    {
        $rows = $this->fetchWithCounts($zoneId, true);

        return $rows[0] ?? null;
    }

    /**
     * Sentencia única de catálogo con contadores, opcionalmente acotada a una zona.
     *
     * @return list<array{
     *     zone: OperationalZone,
     *     locations_count: int,
     *     primary_technicians_count: int,
     *     backup_technicians_count: int,
     *     postal_codes_count: int
     * }>
     */
    private function fetchWithCounts(?int $zoneId, bool $includeInactive): array
    {
        $booleanTrue = $this->dialect->booleanLiteral(true);
        $booleanFalse = $this->dialect->booleanLiteral(false);

        $sql = "
            SELECT
                z.id,
                z.code,
                z.name,
                z.color_hex,
                z.description,
                z.is_active,
                z.created_at,
                z.updated_at,
                (
                    SELECT COUNT(*)
                    FROM locations l
                    WHERE l.zone_id = z.id
                ) AS locations_count,
                (
                    SELECT COUNT(*)
                    FROM technician_zone_assignments t
                    WHERE t.zone_id = z.id
                      AND t.is_primary = {$booleanTrue}
                      AND t.removed_at IS NULL
                ) AS primary_technicians_count,
                (
                    SELECT COUNT(*)
                    FROM technician_zone_assignments t
                    WHERE t.zone_id = z.id
                      AND t.is_primary = {$booleanFalse}
                      AND t.removed_at IS NULL
                ) AS backup_technicians_count,
                (
                    SELECT COUNT(*)
                    FROM operational_zone_postal_codes p
                    WHERE p.zone_id = z.id
                      AND p.released_at IS NULL
                ) AS postal_codes_count
            FROM operational_zones z
        ";

        $conditions = [];
        if ($zoneId !== null) {
            $conditions[] = 'z.id = :zone_id';
        }
        if (!$includeInactive) {
            $conditions[] = "z.is_active = {$booleanTrue}";
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY z.code ASC';

        $stmt = $this->pdo->prepare($sql);
        if ($zoneId !== null) {
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $zones = [];
        foreach ($stmt->fetchAll() as $row) {
            $zones[] = [
                'zone' => OperationalZone::fromDatabaseRow($row),
                'locations_count' => (int)$row['locations_count'],
                'primary_technicians_count' => (int)$row['primary_technicians_count'],
                'backup_technicians_count' => (int)$row['backup_technicians_count'],
                'postal_codes_count' => (int)$row['postal_codes_count'],
            ];
        }

        return $zones;
    }

    /**
     * {@inheritDoc}
     */
    public function findPostalCodes(int $zoneId): array
    {
        // Solo las asignaciones vivas: un CP liberado pertenece al historial de la
        // zona y mostrarlo en su ficha sugeriría que todavía lo gobierna (QA 13).
        $stmt = $this->pdo->prepare(
            'SELECT postal_code
             FROM operational_zone_postal_codes
             WHERE zone_id = :zone_id
               AND released_at IS NULL
             ORDER BY postal_code ASC'
        );
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row): string => (string)$row['postal_code'],
            $stmt->fetchAll()
        );
    }

    /**
     * {@inheritDoc}
     */
    public function findPostalCodeConflicts(array $postalCodes, int $targetZoneId): array
    {
        $codes = self::normalizePostalCodes($postalCodes);
        if ($codes === []) {
            return [];
        }

        [$postalIn, $postalParams] = self::inClause('cp', $codes);

        // Las sedes afectadas se cuentan con la misma regla que aplicará el
        // traslado: solo las que heredan (`is_manual_override = FALSE`) cambian de
        // zona, así que prometer otro número en el modal sería mentir (RF-03.3).
        $sql = "
            SELECT
                p.postal_code,
                p.zone_id,
                z.name,
                (
                    SELECT COUNT(*)
                    FROM locations l
                    WHERE l.postal_code = p.postal_code
                      AND l.is_manual_override = " . $this->dialect->booleanLiteral(false) . '
                      AND l.deleted_at IS NULL
                ) AS affected_locations_count
            FROM operational_zone_postal_codes p
            INNER JOIN operational_zones z ON z.id = p.zone_id
            WHERE p.postal_code IN (' . $postalIn . ')
              AND p.released_at IS NULL
              AND p.zone_id <> :target_zone_id
            ORDER BY p.postal_code ASC
        ';

        $stmt = $this->pdo->prepare($sql);
        self::bindAll($stmt, $postalParams);
        $stmt->bindValue(':target_zone_id', $targetZoneId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'postal_code' => (string)$row['postal_code'],
            'current_zone_id' => (int)$row['zone_id'],
            'current_zone_name' => (string)$row['name'],
            'affected_locations_count' => (int)$row['affected_locations_count'],
        ], $stmt->fetchAll());
    }

    /**
     * {@inheritDoc}
     */
    public function assignPostalCodes(int $zoneId, array $postalCodes, int $userId): array
    {
        $this->assertActiveZone($zoneId, 'asignar códigos postales');

        $codes = self::normalizePostalCodes($postalCodes);
        if ($codes === []) {
            return [];
        }

        $actor = $this->resolveActor($userId);
        [$postalIn, $postalParams] = self::inClause('cp', $codes);

        $this->pdo->beginTransaction();

        try {
            // Un CP con asignación viva no se da de alta: se traslada. Bloquear la
            // fila convierte una carrera entre alta y traslado en un rechazo claro
            // en vez de un 23505 sobre el índice parcial (RNF-04).
            $stmt = $this->pdo->prepare(
                "SELECT postal_code
                 FROM operational_zone_postal_codes
                 WHERE postal_code IN ({$postalIn})
                   AND released_at IS NULL
                 FOR UPDATE"
            );
            self::bindAll($stmt, $postalParams);
            $stmt->execute();
            $taken = array_map(static fn (array $row): string => (string)$row['postal_code'], $stmt->fetchAll());

            if ($taken !== []) {
                throw ZoneValidationException::unprocessable(
                    'Los códigos postales con zona asignada deben trasladarse, no darse de alta: '
                        . implode(', ', $taken) . '.',
                    ['postal_codes' => 'Ya tienen zona: ' . implode(', ', $taken) . '.'],
                );
            }

            // La fila histórica liberada del mismo CP se queda como está: el CP
            // recupera una asignación viva nueva y el historial sigue siendo
            // auditable (QA 13).
            $insert = $this->pdo->prepare(
                'INSERT INTO operational_zone_postal_codes (postal_code, zone_id)
                 VALUES (:postal_code, :zone_id)'
            );
            foreach ($codes as $code) {
                $insert->bindValue(':postal_code', $code, PDO::PARAM_STR);
                $insert->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
                $insert->execute();
            }

            $this->auditRepo->log(new AuditEvent(
                null,
                AuditEvent::ENTITY_OPERATIONAL_ZONE,
                $zoneId,
                'ZONE_UPDATED',
                $userId,
                $actor['role'],
                $actor['name'],
                null,
                ['postal_codes_added' => $codes],
                ['operation' => 'POSTAL_CODES_ASSIGNED']
            ));

            $this->pdo->commit();

            return $codes;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function inspectReleaseImpact(int $zoneId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM operational_zone_postal_codes
                  WHERE zone_id = :zone_id AND released_at IS NULL) AS postal_codes,
                (SELECT COUNT(*) FROM technician_zone_assignments
                  WHERE zone_id = :zone_id AND removed_at IS NULL) AS technicians,
                (SELECT COUNT(*) FROM locations
                  WHERE zone_id = :zone_id AND deleted_at IS NULL) AS locations"
        );
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        // La cuenta de sedes incluye también las forzadas a mano: todas acaban en
        // «Sin Zona Asignada» al desactivar (RF-01.3).
        return [
            'postal_codes' => (int)($row['postal_codes'] ?? 0),
            'technicians' => (int)($row['technicians'] ?? 0),
            'locations' => (int)($row['locations'] ?? 0),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function deactivateZone(int $zoneId, int $userId): array
    {
        $actor = $this->resolveActor($userId);

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, code, name, is_active FROM operational_zones WHERE id = :zone_id FOR UPDATE'
            );
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();
            $zone = $stmt->fetch();

            if ($zone === false) {
                throw new ZoneNotFoundException($zoneId);
            }

            // Las sedes se sueltan primero: `locations.zone_id` referencia la zona
            // con `ON DELETE SET NULL`, y aquí no se borra nada, solo se dejan «Sin
            // Zona Asignada» conservando su historial (RF-01.3, Art. III.1).
            $stmt = $this->pdo->prepare(
                'UPDATE locations
                 SET zone_id = NULL
                 WHERE zone_id = :zone_id
                   AND deleted_at IS NULL'
            );
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();
            $unassignedLocations = $stmt->rowCount();

            $released = $this->releasePostalCodes($zoneId);

            $stmt = $this->pdo->prepare(
                'UPDATE technician_zone_assignments
                 SET removed_at = CURRENT_TIMESTAMP
                 WHERE zone_id = :zone_id
                   AND removed_at IS NULL'
            );
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();
            $removedTechnicians = $stmt->rowCount();

            $stmt = $this->pdo->prepare(
                'UPDATE operational_zones SET is_active = :is_active WHERE id = :zone_id'
            );
            $stmt->bindValue(':is_active', false, PDO::PARAM_BOOL);
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();

            $this->auditRepo->log(new AuditEvent(
                null,
                AuditEvent::ENTITY_OPERATIONAL_ZONE,
                $zoneId,
                'ZONE_DEACTIVATED',
                $userId,
                $actor['role'],
                $actor['name'],
                ['is_active' => self::toBoolean($zone['is_active']), 'code' => (string)$zone['code']],
                ['is_active' => false],
                [
                    'released_postal_codes' => $released,
                    'unassigned_locations_count' => $unassignedLocations,
                    'removed_technicians_count' => $removedTechnicians,
                ]
            ));

            $this->pdo->commit();

            return [
                'released_postal_codes' => $released,
                'unassigned_locations' => $unassignedLocations,
                'removed_technicians' => $removedTechnicians,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function releasePostalCodes(int $zoneId): int
    {
        // `released_at` es la baja lógica del CP: la fila sigue ahí, el CP deja de
        // estar gobernado por esta zona y el índice parcial libera la clave para
        // otra demarcación (QA 13). No hay DELETE que el disparador pueda rechazar.
        $stmt = $this->pdo->prepare(
            'UPDATE operational_zone_postal_codes
             SET released_at = CURRENT_TIMESTAMP
             WHERE zone_id = :zone_id
               AND released_at IS NULL'
        );
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * {@inheritDoc}
     */
    public function transferPostalCodes(int $newZoneId, array $postalCodes, int $userId): int
    {
        $target = $this->findById($newZoneId, true);
        if ($target === null || !$target->isActive()) {
            throw ZoneValidationException::unprocessable(
                'No se pueden trasladar códigos postales a una zona inexistente o desactivada.',
                ['zone_id' => 'La zona de destino no está activa.']
            );
        }

        $codes = self::normalizePostalCodes($postalCodes);
        if ($codes === []) {
            throw new InvalidArgumentException('El traslado requiere al menos un código postal.');
        }

        $actor = $this->resolveActor($userId);
        [$postalIn, $postalParams] = self::inClause('cp', $codes);

        $this->pdo->beginTransaction();

        try {
            // (0) Verificar y bloquear las asignaciones vivas: un CP inexistente o
            // ya liberado no se puede mover, y dos traslados simultáneos del mismo
            // CP no deben pisarse.
            $stmt = $this->pdo->prepare(
                "SELECT postal_code, zone_id
                 FROM operational_zone_postal_codes
                 WHERE postal_code IN ({$postalIn})
                   AND released_at IS NULL
                 FOR UPDATE"
            );
            self::bindAll($stmt, $postalParams);
            $stmt->execute();
            $sources = $stmt->fetchAll();

            if (count($sources) !== count($codes)) {
                $found = array_map(static fn (array $row): string => (string)$row['postal_code'], $sources);
                $missing = array_values(array_diff($codes, $found));
                throw ZoneValidationException::unprocessable(
                    'Uno o más códigos postales no tienen una asignación viva y no se pueden trasladar: '
                        . implode(', ', $missing) . '.',
                    ['postal_codes' => 'Sin asignación viva: ' . implode(', ', $missing) . '.'],
                );
            }

            // (1) Bloquear las sedes que heredan esos CPs. Las forzadas a mano no
            // entran: su zona es una decisión operativa y el traslado no la toca
            // (RF-03.3).
            $stmt = $this->pdo->prepare(
                "SELECT id, zone_id, postal_code
                 FROM locations
                 WHERE postal_code IN ({$postalIn})
                   AND is_manual_override = " . $this->dialect->booleanLiteral(false) . '
                   AND deleted_at IS NULL
                 FOR UPDATE'
            );
            self::bindAll($stmt, $postalParams);
            $stmt->execute();
            $locations = $stmt->fetchAll();

            // (2) Mover las filas del CP: UPDATE, nunca INSERT duplicado.
            $stmt = $this->pdo->prepare(
                "UPDATE operational_zone_postal_codes
                 SET zone_id = :new_zone_id
                 WHERE postal_code IN ({$postalIn})
                   AND released_at IS NULL"
            );
            self::bindAll($stmt, $postalParams);
            $stmt->bindValue(':new_zone_id', $newZoneId, PDO::PARAM_INT);
            $stmt->execute();

            // (3) Arrastrar en bloque las sedes heredadas.
            $reassigned = [];
            foreach ($locations as $location) {
                $previousZoneId = $location['zone_id'] !== null ? (int)$location['zone_id'] : null;
                if ($previousZoneId !== $newZoneId) {
                    $reassigned[] = [
                        'id' => (int)$location['id'],
                        'zone_id' => $previousZoneId,
                        'postal_code' => (string)$location['postal_code'],
                    ];
                }
            }

            if ($reassigned !== []) {
                [$locationIn, $locationParams] = self::inClause('loc', array_column($reassigned, 'id'));
                $stmt = $this->pdo->prepare(
                    "UPDATE locations
                     SET zone_id = :new_zone_id
                     WHERE id IN ({$locationIn})
                       AND deleted_at IS NULL"
                );
                self::bindAll($stmt, $locationParams);
                $stmt->bindValue(':new_zone_id', $newZoneId, PDO::PARAM_INT);
                $stmt->execute();
            }

            // (4) Evento matriz del traslado (Resolución QA 6).
            $sourceZoneIds = array_values(array_unique(array_map(
                static fn (array $row): int => (int)$row['zone_id'],
                $sources
            )));

            $this->auditRepo->log(new AuditEvent(
                null,
                AuditEvent::ENTITY_OPERATIONAL_ZONE,
                $newZoneId,
                'ZONE_POSTAL_CODE_TRANSFERRED',
                $userId,
                $actor['role'],
                $actor['name'],
                ['postal_codes' => $codes, 'zone_ids' => $sourceZoneIds],
                [
                    'zone_id' => $newZoneId,
                    'postal_codes' => $codes,
                    'affected_locations_count' => count($reassigned),
                ],
                ['source_zone_ids' => $sourceZoneIds]
            ));

            // (5) Un evento por sede reasignada, en la misma transacción.
            foreach ($reassigned as $location) {
                $this->auditRepo->log(new AuditEvent(
                    null,
                    AuditEvent::ENTITY_LOCATION,
                    $location['id'],
                    'LOCATION_ZONE_REASSIGNED',
                    $userId,
                    $actor['role'],
                    $actor['name'],
                    ['zone_id' => $location['zone_id']],
                    ['zone_id' => $newZoneId, 'postal_code' => $location['postal_code']],
                    [
                        'postal_code' => $location['postal_code'],
                        'origin' => 'ZONE_POSTAL_CODE_TRANSFERRED',
                    ]
                ));
            }

            $this->pdo->commit();

            return count($reassigned);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findTechnicianAssignments(int $zoneId): array
    {
        $sql = "
            SELECT
                a.id,
                a.technician_id,
                a.is_primary,
                a.assigned_at,
                u.name,
                u.email,
                u.is_on_call,
                u.is_active
            FROM technician_zone_assignments a
            INNER JOIN users u ON u.id = a.technician_id
            WHERE a.zone_id = :zone_id
              AND a.removed_at IS NULL
            ORDER BY a.is_primary DESC, u.name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'assignment_id' => (int)$row['id'],
            'technician_id' => (int)$row['technician_id'],
            'name' => (string)$row['name'],
            'email' => (string)$row['email'],
            'is_primary' => self::toBoolean($row['is_primary']),
            'is_on_call' => self::toBoolean($row['is_on_call']),
            'is_active' => self::toBoolean($row['is_active']),
            'assigned_at' => $row['assigned_at'] !== null ? (string)$row['assigned_at'] : null,
        ], $stmt->fetchAll());
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data, ?int $userId = null): OperationalZone
    {
        // La entidad es la única puerta de entrada al catálogo: valida código,
        // nombre y color con las mismas reglas que usa el resto del dominio.
        $zone = new OperationalZone(
            null,
            (string)($data['code'] ?? ''),
            (string)($data['name'] ?? ''),
            (string)($data['color_hex'] ?? OperationalZone::DEFAULT_COLOR_HEX),
            isset($data['description']) ? (string)$data['description'] : null,
            (bool)($data['is_active'] ?? true)
        );

        // El alta y su evento se confirman juntos: una zona sin rastro en
        // `audit_log` sería una configuración territorial invisible (RNF-03).
        $actor = $userId !== null ? $this->resolveActor($userId) : null;
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO operational_zones (code, name, color_hex, description, is_active)
                 VALUES (:code, :name, :color_hex, :description, :is_active)'
            );
            $stmt->bindValue(':code', $zone->getCode(), PDO::PARAM_STR);
            $stmt->bindValue(':name', $zone->getName(), PDO::PARAM_STR);
            $stmt->bindValue(':color_hex', $zone->getColorHex(), PDO::PARAM_STR);
            if ($zone->getDescription() === null) {
                $stmt->bindValue(':description', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':description', $zone->getDescription(), PDO::PARAM_STR);
            }
            $stmt->bindValue(':is_active', $zone->isActive(), PDO::PARAM_BOOL);
            $stmt->execute();

            $newId = (int)$this->pdo->lastInsertId();

            // `lastInsertId()` puede no estar disponible en algunos agrupadores; el
            // código es único, así que sirve de identidad de respaldo sin inventar nada.
            $created = $newId > 0 ? $this->findById($newId, true) : $this->findByCode($zone->getCode());
            if ($created === null) {
                throw new RuntimeException(
                    "Error al recuperar la zona operativa recién creada con código '{$zone->getCode()}'."
                );
            }

            if ($userId !== null && $actor !== null) {
                $this->auditRepo->log(new AuditEvent(
                    null,
                    AuditEvent::ENTITY_OPERATIONAL_ZONE,
                    (int)$created->getId(),
                    'ZONE_CREATED',
                    $userId,
                    $actor['role'],
                    $actor['name'],
                    null,
                    $created->toArray()
                ));
            }

            $this->pdo->commit();

            return $created;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $zoneId, array $data, ?int $userId = null): bool
    {
        $current = $this->findById($zoneId, true);
        if ($current === null) {
            return false;
        }

        // Se construye la entidad candidata para que las invariantes (longitud del
        // nombre, formato del color, estado booleano) se apliquen en un único sitio.
        $candidate = new OperationalZone(
            $current->getId(),
            $current->getCode(),
            array_key_exists('name', $data) ? (string)$data['name'] : $current->getName(),
            array_key_exists('color_hex', $data) ? (string)$data['color_hex'] : $current->getColorHex(),
            array_key_exists('description', $data)
                ? ($data['description'] !== null ? (string)$data['description'] : null)
                : $current->getDescription(),
            array_key_exists('is_active', $data) ? (bool)$data['is_active'] : $current->isActive()
        );

        $stmt = $this->pdo->prepare(
            'UPDATE operational_zones
             SET name = :name,
                 color_hex = :color_hex,
                 description = :description,
                 is_active = :is_active
             WHERE id = :zone_id'
        );
        $stmt->bindValue(':name', $candidate->getName(), PDO::PARAM_STR);
        $stmt->bindValue(':color_hex', $candidate->getColorHex(), PDO::PARAM_STR);
        if ($candidate->getDescription() === null) {
            $stmt->bindValue(':description', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':description', $candidate->getDescription(), PDO::PARAM_STR);
        }
        $actor = $userId !== null ? $this->resolveActor($userId) : null;
        $this->pdo->beginTransaction();

        try {
            $stmt->bindValue(':is_active', $candidate->isActive(), PDO::PARAM_BOOL);
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();

            if ($userId !== null && $actor !== null) {
                $this->auditRepo->log(new AuditEvent(
                    null,
                    AuditEvent::ENTITY_OPERATIONAL_ZONE,
                    $zoneId,
                    'ZONE_UPDATED',
                    $userId,
                    $actor['role'],
                    $actor['name'],
                    $current->toArray(),
                    $candidate->toArray()
                ));
            }

            $this->pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setActive(int $zoneId, bool $isActive): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE operational_zones SET is_active = :is_active WHERE id = :zone_id'
        );
        $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function assignTechnician(
        int $technicianId,
        int $zoneId,
        bool $isPrimary,
        ?int $userId = null,
        bool $demotePreviousPrimary = false
    ): int {
        $zone = $this->findById($zoneId, true);
        if ($zone === null) {
            throw ZoneValidationException::forField(
                'zone_id',
                sprintf('No existe la zona operativa %d.', $zoneId)
            );
        }
        if (!$zone->isActive()) {
            throw ZoneValidationException::unprocessable(
                'No se puede vincular a un técnico con una zona desactivada.',
                ['zone_id' => 'La zona está inactiva.']
            );
        }

        if (!$this->isActiveTechnician($technicianId)) {
            throw ZoneValidationException::unprocessable(
                'Solo un usuario activo con rol Técnico puede formar parte del cuadrante.',
                ['technician_id' => 'El usuario no es un técnico activo.']
            );
        }

        // La degradación de la titularidad previa solo se admite con la confirmación
        // expresa que pide RF-02.2; sin ella, el conflicto viaja al coordinador.
        $demotion = null;
        if ($isPrimary) {
            $demotion = $demotePreviousPrimary
                ? $this->findOtherPrimaryZone($technicianId, $zoneId)
                : null;

            if (!$demotePreviousPrimary) {
                $this->assertNoOtherPrimaryZone($technicianId, $zoneId);
            }
        }

        $actor = $userId !== null ? $this->resolveActor($userId) : null;
        $this->pdo->beginTransaction();

        try {
            if ($demotion !== null) {
                $stmt = $this->pdo->prepare(
                    'UPDATE technician_zone_assignments
                     SET is_primary = :is_primary
                     WHERE id = :assignment_id'
                );
                $stmt->bindValue(':is_primary', false, PDO::PARAM_BOOL);
                $stmt->bindValue(':assignment_id', $demotion['assignment_id'], PDO::PARAM_INT);
                $stmt->execute();

                if ($userId !== null && $actor !== null) {
                    $this->auditRepo->log(new AuditEvent(
                        null,
                        AuditEvent::ENTITY_TECHNICIAN_ZONE,
                        $demotion['assignment_id'],
                        'TECHNICIAN_ZONE_PRIMARY_DEMOTED',
                        $userId,
                        $actor['role'],
                        $actor['name'],
                        ['zone_id' => $demotion['zone_id'], 'is_primary' => true],
                        ['zone_id' => $demotion['zone_id'], 'is_primary' => false],
                        [
                            'technician_id' => $technicianId,
                            'demoted_zone_id' => $demotion['zone_id'],
                            'demoted_zone_name' => $demotion['zone_name'],
                            'new_primary_zone_id' => $zoneId,
                        ]
                    ));
                }
            }

            $live = $this->findLiveAssignment($technicianId, $zoneId);
            if ($live !== null) {
                if ($live['is_primary'] !== $isPrimary) {
                    $stmt = $this->pdo->prepare(
                        'UPDATE technician_zone_assignments SET is_primary = :is_primary WHERE id = :assignment_id'
                    );
                    $stmt->bindValue(':is_primary', $isPrimary, PDO::PARAM_BOOL);
                    $stmt->bindValue(':assignment_id', $live['id'], PDO::PARAM_INT);
                    $stmt->execute();
                }

                $this->pdo->commit();

                return $live['id'];
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO technician_zone_assignments (technician_id, zone_id, is_primary)
                 VALUES (:technician_id, :zone_id, :is_primary)'
            );
            $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->bindValue(':is_primary', $isPrimary, PDO::PARAM_BOOL);
            $stmt->execute();

            $assignmentId = (int)$this->pdo->lastInsertId();
            if ($assignmentId <= 0) {
                $created = $this->findLiveAssignment($technicianId, $zoneId);
                if ($created === null) {
                    throw new RuntimeException(
                        "Error al recuperar la vinculación recién creada del técnico {$technicianId} con la zona {$zoneId}."
                    );
                }
                $assignmentId = $created['id'];
            }

            if ($userId !== null && $actor !== null) {
                $this->auditRepo->log(new AuditEvent(
                    null,
                    AuditEvent::ENTITY_TECHNICIAN_ZONE,
                    $assignmentId,
                    'TECHNICIAN_ZONE_ASSIGNED',
                    $userId,
                    $actor['role'],
                    $actor['name'],
                    null,
                    ['technician_id' => $technicianId, 'zone_id' => $zoneId, 'is_primary' => $isPrimary]
                ));
            }

            $this->pdo->commit();

            return $assignmentId;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Dos peticiones simultáneas pueden pasar la comprobación previa: el
            // índice parcial las separa, y la que pierde merece el mismo 409 de
            // negocio que si hubiera llegado sola, no un 500 de infraestructura.
            if (UniqueViolationDetector::matches($exception, 'uq_technician_primary_zone')) {
                $this->assertNoOtherPrimaryZone($technicianId, $zoneId);
                throw new PrimaryTechnicianConflictException($technicianId);
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function removeTechnician(int $technicianId, int $zoneId, ?int $userId = null): bool
    {
        $actor = $userId !== null ? $this->resolveActor($userId) : null;
        $this->pdo->beginTransaction();

        try {
            $live = $this->findLiveAssignment($technicianId, $zoneId);

            $stmt = $this->pdo->prepare(
                'UPDATE technician_zone_assignments
                 SET removed_at = CURRENT_TIMESTAMP
                 WHERE technician_id = :technician_id
                   AND zone_id = :zone_id
                   AND removed_at IS NULL'
            );
            $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
            $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
            $stmt->execute();
            $removed = $stmt->rowCount() > 0;

            if ($removed && $userId !== null && $actor !== null && $live !== null) {
                $this->auditRepo->log(new AuditEvent(
                    null,
                    AuditEvent::ENTITY_TECHNICIAN_ZONE,
                    $live['id'],
                    'TECHNICIAN_ZONE_REMOVED',
                    $userId,
                    $actor['role'],
                    $actor['name'],
                    ['zone_id' => $zoneId, 'is_primary' => $live['is_primary'], 'removed_at' => null],
                    ['removed_at' => 'CURRENT_TIMESTAMP'],
                    ['technician_id' => $technicianId]
                ));
            }

            $this->pdo->commit();

            return $removed;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * ¿El usuario existe, es técnico y está activo? (RF-02.2)
     */
    private function isActiveTechnician(int $technicianId): bool
    {
        $sql = "
            SELECT 1
            FROM users
            WHERE id = :technician_id
              AND role = 'TECHNICIAN'
              AND is_active = " . $this->dialect->booleanLiteral(true) . '
              AND deleted_at IS NULL
            LIMIT 1
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Rechaza la segunda titularidad viva con el detalle de la zona que se perdería.
     *
     * @throws PrimaryTechnicianConflictException
     */
    private function assertNoOtherPrimaryZone(int $technicianId, int $zoneId): void
    {
        $sql = "
            SELECT a.zone_id, z.name
            FROM technician_zone_assignments a
            INNER JOIN operational_zones z ON z.id = a.zone_id
            WHERE a.technician_id = :technician_id
              AND a.is_primary = " . $this->dialect->booleanLiteral(true) . '
              AND a.removed_at IS NULL
              AND a.zone_id <> :zone_id
            LIMIT 1
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $conflict = $stmt->fetch();

        if ($conflict !== false) {
            throw new PrimaryTechnicianConflictException(
                $technicianId,
                (int)$conflict['zone_id'],
                (string)$conflict['name']
            );
        }
    }

    /**
     * Titularidad viva del técnico en **otra** zona, con su nombre para el rastro.
     *
     * Es la lectura que necesita la confirmación `confirm_primary_override`: la
     * degradación automática solo puede aplicarse si el coordinador sabe qué zona
     * pierde el técnico (RF-02.2, QA 9).
     *
     * @return array{assignment_id: int, zone_id: int, zone_name: string}|null
     */
    private function findOtherPrimaryZone(int $technicianId, int $zoneId): ?array
    {
        $sql = "
            SELECT a.id, a.zone_id, z.name
            FROM technician_zone_assignments a
            INNER JOIN operational_zones z ON z.id = a.zone_id
            WHERE a.technician_id = :technician_id
              AND a.is_primary = " . $this->dialect->booleanLiteral(true) . '
              AND a.removed_at IS NULL
              AND a.zone_id <> :zone_id
            LIMIT 1
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'assignment_id' => (int)$row['id'],
            'zone_id' => (int)$row['zone_id'],
            'zone_name' => (string)$row['name'],
        ];
    }

    /**
     * Comprueba que la zona existe y está activa antes de escribir en su territorio.
     *
     * @throws ZoneNotFoundException Si la zona no existe.
     * @throws ZoneValidationException Si está desactivada.
     */
    private function assertActiveZone(int $zoneId, string $operation): void
    {
        $zone = $this->findById($zoneId, true);
        if ($zone === null) {
            throw new ZoneNotFoundException($zoneId);
        }

        if (!$zone->isActive()) {
            throw ZoneValidationException::unprocessable(
                sprintf('No se pueden %s en una zona desactivada.', $operation),
                ['zone_id' => 'La zona está inactiva.']
            );
        }
    }

    /**
     * Vinculación viva del técnico con la zona, si existe.
     *
     * @return array{id: int, is_primary: bool}|null
     */
    private function findLiveAssignment(int $technicianId, int $zoneId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, is_primary
             FROM technician_zone_assignments
             WHERE technician_id = :technician_id
               AND zone_id = :zone_id
               AND removed_at IS NULL
             LIMIT 1'
        );
        $stmt->bindValue(':technician_id', $technicianId, PDO::PARAM_INT);
        $stmt->bindValue(':zone_id', $zoneId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int)$row['id'],
            'is_primary' => self::toBoolean($row['is_primary']),
        ];
    }

    /**
     * Normaliza, deduplica y ordena una lista de códigos postales.
     *
     * El Value Object es la única validación: un CP mal formado detiene el
     * traslado antes de abrir la transacción, no a mitad de ella.
     *
     * @param array<int, mixed> $postalCodes
     * @return list<string>
     *
     * @throws ZoneValidationException Si algún CP no tiene cinco dígitos.
     */
    private static function normalizePostalCodes(array $postalCodes): array
    {
        $normalized = [];
        foreach ($postalCodes as $raw) {
            $value = (new PostalCode(is_int($raw) ? $raw : (string)$raw))->value();
            $normalized[$value] = $value;
        }

        $codes = array_values($normalized);
        sort($codes, SORT_STRING);

        return $codes;
    }

    /**
     * Construye una lista de marcadores nombrados y sus valores enlazables.
     *
     * Los marcadores nacen de un prefijo controlado por este repositorio y un
     * índice entero, nunca de la entrada: la lista viaja siempre por parámetros.
     *
     * @param list<string> $values
     * @return array{0: string, 1: array<string, string>}
     */
    private static function inClause(string $prefix, array $values): array
    {
        $placeholders = [];
        $params = [];

        foreach (array_values($values) as $index => $value) {
            $key = ':' . $prefix . '_' . $index;
            $placeholders[] = $key;
            $params[$key] = (string)$value;
        }

        return [implode(', ', $placeholders), $params];
    }

    /**
     * Enlaza un lote de parámetros respetando el tipo de cada valor.
     *
     * @param array<string, mixed> $params
     */
    private static function bindAll(PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $statement->bindValue($key, $value, PDO::PARAM_INT);
                continue;
            }

            $statement->bindValue($key, (string)$value, PDO::PARAM_STR);
        }
    }

    /**
     * Rol y nombre del coordinador que firma el traslado en `audit_log`.
     *
     * @return array{role: string, name: string}
     *
     * @throws InvalidArgumentException Si el usuario no existe.
     */
    private function resolveActor(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT role, name FROM users WHERE id = :user_id LIMIT 1');
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false) {
            throw new InvalidArgumentException(
                "No existe el usuario {$userId} que pretende ejecutar el traslado de códigos postales."
            );
        }

        return [
            'role' => mb_strtoupper((string)$row['role']),
            'name' => (string)$row['name'],
        ];
    }

    /**
     * Booleano del motor, sin suponer la forma que devuelve el driver.
     */
    private static function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
