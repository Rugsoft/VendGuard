<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use PDOException;
use RuntimeException;
use VendGuard\Core\Domain\Exception\SparePartCodeExistsException;
use VendGuard\Core\Domain\Model\SparePart;
use VendGuard\Core\Domain\Model\SparePartCategory;
use VendGuard\Core\Domain\Repository\SparePartRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\UniqueViolationDetector;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

/**
 * PdoSparePartRepository
 * 
 * Implementación PDO del repositorio de catálogo maestro de repuestos y compatibilidades.
 * Cumple con el Artículo III (prohibición de borrado físico, soft delete con deleted_at)
 * y garantiza transacciones atómicas para relaciones con modelos de máquinas.
 *
 * Los identificadores se escriben desnudos —los dos motores admiten así todo el esquema— y las
 * dos diferencias declaradas que este repositorio tiene se le piden al adaptador de dialecto
 * (RF-01.5, T-HYBRID-25): el literal booleano (D-3) y la cláusula de upsert de las
 * compatibilidades, que es **una regla** —afirmar la relación y sanear su baja lógica— escrita
 * una sola vez y renderizada en la forma de cada motor.
 */
class PdoSparePartRepository implements SparePartRepositoryInterface
{
    private PDO $pdo;

    private SqlDialectAdapter $dialect;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
        $this->dialect = SqlDialectAdapter::forConnection($this->pdo);
    }

    /**
     * Cláusula de upsert de las compatibilidades repuesto–modelo.
     *
     * La regla es una sola —reafirmar la relación y sanear su baja lógica si ya existía— y el
     * adaptador la renderiza en la forma de cada motor: `ON CONFLICT (spare_part_id, machine_model)
     * DO UPDATE ...` en PostgreSQL y `ON DUPLICATE KEY UPDATE ...` en MariaDB. Se declara aquí una
     * vez porque las dos altas que la usan —crear el repuesto y actualizarlo— no pueden divergir:
     * escribir la cláusula dos veces sería declarar dos reglas donde hay una.
     */
    private function compatibilityUpsertClause(): string
    {
        return $this->dialect->upsertAssignments(
            ['spare_part_id', 'machine_model'],
            [
                'is_active' => $this->dialect->booleanLiteral(true),
                'deleted_at' => 'NULL',
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function create(SparePart $sparePart): SparePart
    {
        $this->pdo->beginTransaction();

        try {
            $sql = "
                INSERT INTO spare_parts (
                    part_code,
                    name,
                    category,
                    manufacturer,
                    reference_cost,
                    is_active,
                    notes,
                    created_at,
                    updated_at
                ) VALUES (
                    :part_code,
                    :name,
                    :category,
                    :manufacturer,
                    :reference_cost,
                    :is_active,
                    :notes,
                    NOW(),
                    NOW()
                )
            ";

            // El booleano cruza como parámetro enlazado con `PDO::PARAM_BOOL`: es la forma que los
            // dos drivers traducen al tipo de cada motor (D-3), mientras que un `1`/`0` por el
            // array de ejecución llega al motor nuevo como texto y su tipo `BOOLEAN` lo rechaza.
            // Los valores se enlazan todos por nombre: mezclar `bindValue()` con un array de
            // ejecución hace que PDO pierda la cuenta de los parámetros ya enlazados (`HY093`).
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':part_code', $sparePart->getPartCode(), PDO::PARAM_STR);
            $stmt->bindValue(':name', $sparePart->getName(), PDO::PARAM_STR);
            $stmt->bindValue(':category', $sparePart->getCategory()->value, PDO::PARAM_STR);
            $stmt->bindValue(':manufacturer', $sparePart->getManufacturer(), PDO::PARAM_STR);
            $stmt->bindValue(':reference_cost', $sparePart->getReferenceCost(), PDO::PARAM_STR);
            $stmt->bindValue(':is_active', $sparePart->isActive(), PDO::PARAM_BOOL);
            $stmt->bindValue(':notes', $sparePart->getNotes(), $sparePart->getNotes() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->execute();

            $id = (int)$this->pdo->lastInsertId();

            // Insertar compatibilidades con modelos
            $compatSql = "
                INSERT INTO spare_part_compatibilities (spare_part_id, machine_model, is_active, created_at, deleted_at)
                VALUES (:spare_part_id, :machine_model, {$this->dialect->booleanLiteral(true)}, NOW(), NULL)
                {$this->compatibilityUpsertClause()}
            ";
            $compatStmt = $this->pdo->prepare($compatSql);

            foreach ($sparePart->getCompatibleModels() as $model) {
                $compatStmt->execute([
                    ':spare_part_id' => $id,
                    ':machine_model' => $model,
                ]);
            }

            $this->pdo->commit();

            return $this->findById($id) ?? new SparePart(
                $id,
                $sparePart->getPartCode(),
                $sparePart->getName(),
                $sparePart->getCategory(),
                $sparePart->getManufacturer(),
                $sparePart->getReferenceCost(),
                $sparePart->isActive(),
                $sparePart->getNotes(),
                $sparePart->getCompatibleModels()
            );
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Carrera de altas simultáneas sobre el catálogo maestro: la comprobación
            // previa del servicio no ve la fila sin confirmar del vecino y la colisión
            // aparece aquí. El detector único reconoce el SQLSTATE `23505` del motor
            // único y devuelve el 409 de negocio; el resto de fallos sigue siendo un
            // fallo de infraestructura en voz alta.
            if (UniqueViolationDetector::matches($e, 'uq_spare_parts_part_code', 'spare_parts_part_code')) {
                throw new SparePartCodeExistsException(
                    $sparePart->getPartCode(),
                    "Ya existe un repuesto registrado con el código '{$sparePart->getPartCode()}'."
                );
            }

            throw new RuntimeException("Error al crear repuesto: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update(SparePart $sparePart): bool
    {
        $this->pdo->beginTransaction();

        try {
            $sql = "
                UPDATE spare_parts SET
                    name = :name,
                    category = :category,
                    manufacturer = :manufacturer,
                    reference_cost = :reference_cost,
                    is_active = :is_active,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
            ";

            // El booleano cruza con `PDO::PARAM_BOOL` (D-3) y todos los valores se enlazan por
            // nombre: mezclar `bindValue()` con un array de ejecución hace que PDO pierda la
            // cuenta de los parámetros ya enlazados (`HY093`).
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $sparePart->getId(), PDO::PARAM_INT);
            $stmt->bindValue(':name', $sparePart->getName(), PDO::PARAM_STR);
            $stmt->bindValue(':category', $sparePart->getCategory()->value, PDO::PARAM_STR);
            $stmt->bindValue(':manufacturer', $sparePart->getManufacturer(), PDO::PARAM_STR);
            $stmt->bindValue(':reference_cost', $sparePart->getReferenceCost(), PDO::PARAM_STR);
            $stmt->bindValue(':is_active', $sparePart->isActive(), PDO::PARAM_BOOL);
            $notes = $sparePart->getNotes();
            $stmt->bindValue(':notes', $notes, $notes !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->execute();

            // Sincronizar compatibilidades sin borrado físico (Constitución Art. III)
            $deactivateCompat = $this->pdo->prepare("
                UPDATE spare_part_compatibilities
                SET is_active = {$this->dialect->booleanLiteral(false)}, deleted_at = NOW()
                WHERE spare_part_id = :id
            ");
            $deactivateCompat->execute([':id' => $sparePart->getId()]);

            $compatSql = "
                INSERT INTO spare_part_compatibilities (spare_part_id, machine_model, is_active, created_at, deleted_at)
                VALUES (:spare_part_id, :machine_model, {$this->dialect->booleanLiteral(true)}, NOW(), NULL)
                {$this->compatibilityUpsertClause()}
            ";
            $compatStmt = $this->pdo->prepare($compatSql);

            foreach ($sparePart->getCompatibleModels() as $model) {
                $compatStmt->execute([
                    ':spare_part_id' => $sparePart->getId(),
                    ':machine_model' => $model,
                ]);
            }

            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Error al actualizar repuesto: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function softDelete(int $id): bool
    {
        $sql = "
            UPDATE spare_parts SET
                is_active = {$this->dialect->booleanLiteral(false)},
                deleted_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStatus(int $id, bool $isActive): bool
    {
        $sql = "
            UPDATE spare_parts SET
                is_active = :is_active,
                deleted_at = :deleted_at,
                updated_at = NOW()
            WHERE id = :id
        ";

        // El booleano cruza con `PDO::PARAM_BOOL` (D-3) y todos los valores se enlazan por nombre:
        // mezclar `bindValue()` con un array de ejecución pierde la cuenta de los parámetros (`HY093`).
        $deletedAt = $isActive ? null : date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
        $stmt->bindValue(':deleted_at', $deletedAt, $deletedAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        return $stmt->execute();
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?SparePart
    {
        $sql = "
            SELECT 
                sp.*,
                COALESCE((SELECT SUM(quantity) FROM incident_replaced_parts WHERE spare_part_id = sp.id), 0) AS total_installed_units
            FROM spare_parts sp
            WHERE sp.id = :id
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $models = $this->fetchCompatibleModels($id);
        return $this->hydrateSparePart($row, $models);
    }

    /**
     * {@inheritdoc}
     */
    public function findByCode(string $partCode): ?SparePart
    {
        $sql = "
            SELECT 
                sp.*,
                COALESCE((SELECT SUM(quantity) FROM incident_replaced_parts WHERE spare_part_id = sp.id), 0) AS total_installed_units
            FROM spare_parts sp
            WHERE sp.part_code = :part_code
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':part_code' => trim($partCode)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $models = $this->fetchCompatibleModels((int)$row['id']);
        return $this->hydrateSparePart($row, $models);
    }

    /**
     * {@inheritdoc}
     */
    public function isCodeExists(string $partCode, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM spare_parts WHERE part_code = :part_code";
        $params = [':part_code' => trim($partCode)];

        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function findAll(
        ?string $search = null,
        ?string $category = null,
        ?string $machineModel = null,
        ?bool $isActive = null
    ): array {
        $sql = "
            SELECT 
                sp.*,
                COALESCE((SELECT SUM(quantity) FROM incident_replaced_parts WHERE spare_part_id = sp.id), 0) AS total_installed_units
            FROM spare_parts sp
            WHERE 1 = 1
        ";
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $sql .= " AND (sp.part_code LIKE :search_code OR sp.name LIKE :search_name OR sp.manufacturer LIKE :search_manu)";
            $params[':search_code'] = $term;
            $params[':search_name'] = $term;
            $params[':search_manu'] = $term;
        }

        if ($category !== null && trim($category) !== '' && SparePartCategory::isValid($category)) {
            $sql .= " AND sp.category = :category";
            $params[':category'] = $category;
        }

        if ($machineModel !== null && trim($machineModel) !== '') {
            $sql .= " AND sp.id IN (SELECT spare_part_id FROM spare_part_compatibilities WHERE machine_model = :machine_model AND is_active = {$this->dialect->booleanLiteral(true)})";
            $params[':machine_model'] = trim($machineModel);
        }

        if ($isActive !== null) {
            // El filtro cruzaba como parámetro enlazado con `1`/`0`, que el motor nuevo rechaza en
            // una columna `BOOLEAN` (D-3): la comparación se escribe con el literal de cada motor.
            $sql .= " AND sp.is_active = {$this->dialect->booleanLiteral($isActive)}";
        }

        $sql .= " ORDER BY sp.name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $partIds = array_map(fn($r) => (int)$r['id'], $rows);
        $allModelsMap = $this->fetchBatchCompatibleModels($partIds);

        $results = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $models = $allModelsMap[$id] ?? [];
            $results[] = $this->hydrateSparePart($row, $models);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function findCompatibleWithModel(string $machineModel, bool $onlyActive = true): array
    {
        $sql = "
            SELECT 
                sp.*,
                COALESCE((SELECT SUM(quantity) FROM incident_replaced_parts WHERE spare_part_id = sp.id), 0) AS total_installed_units
            FROM spare_parts sp
            INNER JOIN spare_part_compatibilities spc ON spc.spare_part_id = sp.id AND spc.is_active = {$this->dialect->booleanLiteral(true)}
            WHERE spc.machine_model = :machine_model
        ";
        $params = [':machine_model' => trim($machineModel)];

        if ($onlyActive) {
            $sql .= " AND sp.is_active = {$this->dialect->booleanLiteral(true)}";
        }

        $sql .= " ORDER BY sp.name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $partIds = array_map(fn($r) => (int)$r['id'], $rows);
        $allModelsMap = $this->fetchBatchCompatibleModels($partIds);

        $results = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $models = $allModelsMap[$id] ?? [];
            $results[] = $this->hydrateSparePart($row, $models);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function findDistinctMachineModels(): array
    {
        $sql = "
            SELECT DISTINCT model AS machine_model 
            FROM machines 
            WHERE deleted_at IS NULL
            UNION
            SELECT DISTINCT machine_model 
            FROM spare_part_compatibilities
            WHERE is_active = {$this->dialect->booleanLiteral(true)}
            ORDER BY machine_model ASC
        ";

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(array_map('trim', $rows)));
    }

    /**
     * Obtiene los modelos compatibles para una pieza específica.
     *
     * @param int $sparePartId
     * @return string[]
     */
    private function fetchCompatibleModels(int $sparePartId): array
    {
        $stmt = $this->pdo->prepare("SELECT machine_model FROM spare_part_compatibilities WHERE spare_part_id = :id AND is_active = {$this->dialect->booleanLiteral(true)} ORDER BY machine_model ASC");
        $stmt->execute([':id' => $sparePartId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Obtiene en lote los modelos compatibles para un grupo de IDs de repuesto.
     *
     * @param int[] $partIds
     * @return array<int, string[]>
     */
    private function fetchBatchCompatibleModels(array $partIds): array
    {
        if (empty($partIds)) {
            return [];
        }

        $inClause = implode(',', array_fill(0, count($partIds), '?'));
        $sql = "SELECT spare_part_id, machine_model FROM spare_part_compatibilities WHERE spare_part_id IN ({$inClause}) AND is_active = {$this->dialect->booleanLiteral(true)} ORDER BY machine_model ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($partIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $pId = (int)$row['spare_part_id'];
            $map[$pId][] = $row['machine_model'];
        }

        return $map;
    }

    /**
     * Hidrata una entidad SparePart desde la fila de base de datos.
     *
     * @param array<string, mixed> $row
     * @param string[] $models
     * @return SparePart
     */
    private function hydrateSparePart(array $row, array $models): SparePart
    {
        return new SparePart(
            (int)$row['id'],
            (string)$row['part_code'],
            (string)$row['name'],
            SparePartCategory::from((string)$row['category']),
            (string)$row['manufacturer'],
            (float)$row['reference_cost'],
            (bool)$row['is_active'],
            $row['notes'] !== null ? (string)$row['notes'] : null,
            $models,
            (int)($row['total_installed_units'] ?? 0),
            (string)$row['created_at'],
            (string)$row['updated_at'],
            $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null
        );
    }
}
