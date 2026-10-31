<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use RuntimeException;
use VendGuard\Core\Domain\Model\RootCause;
use VendGuard\Core\Domain\Model\StandardSolution;
use VendGuard\Core\Domain\Repository\RootCauseRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoRootCauseRepository
 *
 * Implementación PDO/PostgreSQL del catálogo maestro de causas raíz y soluciones
 * estandarizadas (Módulo 25, RF-01, D1 y D3).
 *
 * Decisiones de persistencia:
 *   1. **Sin N+1 en el catálogo operativo.** `findActiveCatalogWithSolutions()`
 *      trae causas y soluciones en una sola consulta agrupada; el agrupado se
 *      hace en PHP porque la forma del agregado (`RootCause` con sus
 *      `StandardSolution`) no es una fila plana.
 *   2. **Bloqueo pesimista explícito.** Las lecturas `*ForUpdate()` exigen una
 *      transacción abierta y usan `SELECT ... FOR UPDATE`: la conmutación de la
 *      solución predeterminada se serializa antes de tocar el índice único
 *      parcial `uq_standard_solutions_default_per_cause` (QA 1).
 *   3. **La transacción la abre el servicio.** Este repositorio nunca hace
 *      `BEGIN`/`COMMIT` por su cuenta: el alta atómica de causa + primera
 *      solución + auditoría es una unidad de trabajo que orquesta la capa de
 *      aplicación (T-07). Abrir aquí una transacción propia rompería esa
 *      atomicidad.
 *   4. **La base de datos es la última palabra en inmutabilidad.** Los
 *      `UPDATE` escriben sólo las columnas mutables (nombre, descripción,
 *      vigencia y marca de predeterminada); `code`, `category` y
 *      `root_cause_id` ni siquiera viajan en el `SET` y los disparadores
 *      `enforce_*_immutability` los blindan de todos modos (C4, QA 1).
 */
class PdoRootCauseRepository implements RootCauseRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * @return RootCause[]
     */
    public function findActiveCatalogWithSolutions(): array
    {
        $sql = "
            SELECT
                rc.id,
                rc.code,
                rc.category,
                rc.name,
                rc.description,
                rc.is_active,
                rc.created_at,
                rc.updated_at,
                ss.id AS solution_id,
                ss.root_cause_id AS solution_root_cause_id,
                ss.code AS solution_code,
                ss.name AS solution_name,
                ss.is_default AS solution_is_default,
                ss.is_active AS solution_is_active,
                ss.created_at AS solution_created_at,
                ss.updated_at AS solution_updated_at
            FROM root_causes rc
            LEFT JOIN standard_solutions ss
                   ON ss.root_cause_id = rc.id
                  AND ss.is_active = TRUE
            WHERE rc.is_active = TRUE
            ORDER BY rc.code ASC, ss.is_default DESC, ss.code ASC
        ";

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $causes = [];
        $solutionsByCause = [];

        foreach ($rows as $row) {
            $causeId = (int)$row['id'];
            if (!isset($causes[$causeId])) {
                $causes[$causeId] = $this->hydrateRootCause($row);
                $solutionsByCause[$causeId] = [];
            }

            if ($row['solution_id'] !== null) {
                $solutionsByCause[$causeId][] = $this->hydrateSolution($row, 'solution_');
            }
        }

        $catalog = [];
        foreach ($causes as $causeId => $cause) {
            // Una causa activa sin soluciones activas no es una opción resoluble:
            // el alta atómica garantiza la primera solución (EARS 01.1) y la
            // guarda C2 impide dejar la causa sin ninguna. Se omite para no
            // ofrecer al técnico una causa que no puede cerrar.
            if ($solutionsByCause[$causeId] === []) {
                continue;
            }

            $catalog[] = $cause->withSolutions($solutionsByCause[$causeId]);
        }

        return $catalog;
    }

    /**
     * @return array<int, array{
     *     root_cause: RootCause,
     *     incidents_count: int,
     *     solutions: array<int, array{solution: StandardSolution, incidents_count: int}>
     * }>
     */
    public function findFullCatalogForAdmin(?IncidentCategory $category = null, ?bool $isActive = null): array
    {
        $sql = "
            SELECT
                rc.id,
                rc.code,
                rc.category,
                rc.name,
                rc.description,
                rc.is_active,
                rc.created_at,
                rc.updated_at,
                COALESCE(ic.incidents_count, 0) AS incidents_count,
                ss.id AS solution_id,
                ss.root_cause_id AS solution_root_cause_id,
                ss.code AS solution_code,
                ss.name AS solution_name,
                ss.is_default AS solution_is_default,
                ss.is_active AS solution_is_active,
                ss.created_at AS solution_created_at,
                ss.updated_at AS solution_updated_at,
                COALESCE(sc.incidents_count, 0) AS solution_incidents_count
            FROM root_causes rc
            LEFT JOIN standard_solutions ss
                   ON ss.root_cause_id = rc.id
            LEFT JOIN (
                SELECT root_cause_id, COUNT(*) AS incidents_count
                  FROM incidents
                 WHERE deleted_at IS NULL
                   AND root_cause_id IS NOT NULL
                 GROUP BY root_cause_id
            ) ic ON ic.root_cause_id = rc.id
            LEFT JOIN (
                SELECT standard_solution_id, COUNT(*) AS incidents_count
                  FROM incidents
                 WHERE deleted_at IS NULL
                   AND standard_solution_id IS NOT NULL
                 GROUP BY standard_solution_id
            ) sc ON sc.standard_solution_id = ss.id
        ";

        $where = [];
        $params = [];

        if ($category !== null) {
            $where[] = 'rc.category = :category';
            $params[':category'] = $category->value;
        }

        if ($isActive !== null) {
            $where[] = 'rc.is_active = :is_active';
            $params[':is_active'] = $isActive ? 'true' : 'false';
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY rc.is_active DESC, rc.code ASC, ss.is_default DESC, ss.code ASC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $catalog = [];

        foreach ($rows as $row) {
            $causeId = (int)$row['id'];
            if (!isset($catalog[$causeId])) {
                $catalog[$causeId] = [
                    'root_cause' => $this->hydrateRootCause($row),
                    'incidents_count' => (int)$row['incidents_count'],
                    'solutions' => [],
                ];
            }

            if ($row['solution_id'] !== null) {
                $catalog[$causeId]['solutions'][] = [
                    'solution' => $this->hydrateSolution($row, 'solution_'),
                    'incidents_count' => (int)$row['solution_incidents_count'],
                ];
            }
        }

        return array_values($catalog);
    }

    public function findRootCauseById(int $id): ?RootCause
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, category, name, description, is_active, created_at, updated_at
               FROM root_causes
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateRootCause($row);
    }

    public function findSolutionById(int $id): ?StandardSolution
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, root_cause_id, code, name, is_default, is_active, created_at, updated_at
               FROM standard_solutions
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateSolution($row);
    }

    public function findRootCauseForUpdate(int $id): ?RootCause
    {
        $this->assertOpenTransaction('findRootCauseForUpdate');

        $stmt = $this->pdo->prepare(
            'SELECT id, code, category, name, description, is_active, created_at, updated_at
               FROM root_causes
              WHERE id = :id
                FOR UPDATE'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateRootCause($row);
    }

    public function findSolutionForUpdate(int $id): ?StandardSolution
    {
        $this->assertOpenTransaction('findSolutionForUpdate');

        $stmt = $this->pdo->prepare(
            'SELECT id, root_cause_id, code, name, is_default, is_active, created_at, updated_at
               FROM standard_solutions
              WHERE id = :id
                FOR UPDATE'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateSolution($row);
    }

    public function existsRootCauseCode(string $code): bool
    {
        return $this->existsCode('root_causes', $code);
    }

    public function existsSolutionCode(string $code): bool
    {
        return $this->existsCode('standard_solutions', $code);
    }

    public function hasActiveDefaultSolution(int $rootCauseId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT EXISTS (
                SELECT 1
                  FROM standard_solutions
                 WHERE root_cause_id = :root_cause_id
                   AND is_default = TRUE
                   AND is_active = TRUE
            )'
        );
        $stmt->bindValue(':root_cause_id', $rootCauseId, PDO::PARAM_INT);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }

    public function countActiveSolutions(int $rootCauseId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM standard_solutions
              WHERE root_cause_id = :root_cause_id
                AND is_active = TRUE'
        );
        $stmt->bindValue(':root_cause_id', $rootCauseId, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    public function clearActiveDefaultForCause(int $rootCauseId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE standard_solutions
                SET is_default = FALSE
              WHERE root_cause_id = :root_cause_id
                AND is_default = TRUE
                AND is_active = TRUE'
        );
        $stmt->bindValue(':root_cause_id', $rootCauseId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function insertRootCause(RootCause $rootCause): RootCause
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO root_causes (code, category, name, description, is_active)
             VALUES (:code, :category, :name, :description, :is_active)
             RETURNING id, created_at, updated_at'
        );
        $stmt->bindValue(':code', $rootCause->getCode(), PDO::PARAM_STR);
        $stmt->bindValue(':category', $rootCause->getCategory()->value, PDO::PARAM_STR);
        $stmt->bindValue(':name', $rootCause->getName(), PDO::PARAM_STR);
        $stmt->bindValue(':description', $rootCause->getDescription(), $rootCause->getDescription() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':is_active', $rootCause->isActive() ? 'true' : 'false', PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('El motor no devolvió la fila de la causa raíz recién insertada.');
        }

        return new RootCause(
            (int)$row['id'],
            $rootCause->getCode(),
            $rootCause->getCategory(),
            $rootCause->getName(),
            $rootCause->getDescription(),
            $rootCause->isActive(),
            [],
            $row['created_at'] !== null ? (string)$row['created_at'] : null,
            $row['updated_at'] !== null ? (string)$row['updated_at'] : null
        );
    }

    public function insertStandardSolution(StandardSolution $standardSolution): StandardSolution
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO standard_solutions (root_cause_id, code, name, is_default, is_active)
             VALUES (:root_cause_id, :code, :name, :is_default, :is_active)
             RETURNING id, created_at, updated_at'
        );
        $stmt->bindValue(':root_cause_id', $standardSolution->getRootCauseId(), PDO::PARAM_INT);
        $stmt->bindValue(':code', $standardSolution->getCode(), PDO::PARAM_STR);
        $stmt->bindValue(':name', $standardSolution->getName(), PDO::PARAM_STR);
        $stmt->bindValue(':is_default', $standardSolution->isDefault() ? 'true' : 'false', PDO::PARAM_STR);
        $stmt->bindValue(':is_active', $standardSolution->isActive() ? 'true' : 'false', PDO::PARAM_STR);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('El motor no devolvió la fila de la solución estandarizada recién insertada.');
        }

        return new StandardSolution(
            (int)$row['id'],
            $standardSolution->getRootCauseId(),
            $standardSolution->getCode(),
            $standardSolution->getName(),
            $standardSolution->isDefault(),
            $standardSolution->isActive(),
            $row['created_at'] !== null ? (string)$row['created_at'] : null,
            $row['updated_at'] !== null ? (string)$row['updated_at'] : null
        );
    }

    public function updateRootCause(RootCause $rootCause): RootCause
    {
        $causeId = $rootCause->getId();
        if ($causeId === null) {
            throw new RuntimeException('No se puede actualizar una causa raíz que no está persistida.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE root_causes
                SET name = :name,
                    description = :description,
                    is_active = :is_active
              WHERE id = :id
              RETURNING updated_at'
        );
        $stmt->bindValue(':name', $rootCause->getName(), PDO::PARAM_STR);
        $stmt->bindValue(':description', $rootCause->getDescription(), $rootCause->getDescription() !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':is_active', $rootCause->isActive() ? 'true' : 'false', PDO::PARAM_STR);
        $stmt->bindValue(':id', $causeId, PDO::PARAM_INT);
        $stmt->execute();

        $updatedAt = $stmt->fetchColumn();
        if ($updatedAt === false) {
            throw new RuntimeException(sprintf('La causa raíz %d no existe en el catálogo maestro.', $causeId));
        }

        return new RootCause(
            $causeId,
            $rootCause->getCode(),
            $rootCause->getCategory(),
            $rootCause->getName(),
            $rootCause->getDescription(),
            $rootCause->isActive(),
            $rootCause->getSolutions(),
            $rootCause->getCreatedAt(),
            (string)$updatedAt
        );
    }

    public function updateStandardSolution(StandardSolution $standardSolution): StandardSolution
    {
        $solutionId = $standardSolution->getId();
        if ($solutionId === null) {
            throw new RuntimeException('No se puede actualizar una solución estandarizada que no está persistida.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE standard_solutions
                SET name = :name,
                    is_default = :is_default,
                    is_active = :is_active
              WHERE id = :id
              RETURNING updated_at'
        );
        $stmt->bindValue(':name', $standardSolution->getName(), PDO::PARAM_STR);
        $stmt->bindValue(':is_default', $standardSolution->isDefault() ? 'true' : 'false', PDO::PARAM_STR);
        $stmt->bindValue(':is_active', $standardSolution->isActive() ? 'true' : 'false', PDO::PARAM_STR);
        $stmt->bindValue(':id', $solutionId, PDO::PARAM_INT);
        $stmt->execute();

        $updatedAt = $stmt->fetchColumn();
        if ($updatedAt === false) {
            throw new RuntimeException(sprintf('La solución estandarizada %d no existe en el catálogo maestro.', $solutionId));
        }

        return new StandardSolution(
            $solutionId,
            $standardSolution->getRootCauseId(),
            $standardSolution->getCode(),
            $standardSolution->getName(),
            $standardSolution->isDefault(),
            $standardSolution->isActive(),
            $standardSolution->getCreatedAt(),
            (string)$updatedAt
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRootCause(array $row): RootCause
    {
        return new RootCause(
            isset($row['id']) ? (int)$row['id'] : null,
            (string)$row['code'],
            IncidentCategory::fromString((string)$row['category']),
            (string)$row['name'],
            isset($row['description']) && $row['description'] !== null ? (string)$row['description'] : null,
            (bool)$row['is_active'],
            [],
            isset($row['created_at']) && $row['created_at'] !== null ? (string)$row['created_at'] : null,
            isset($row['updated_at']) && $row['updated_at'] !== null ? (string)$row['updated_at'] : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSolution(array $row, string $prefix = ''): StandardSolution
    {
        return new StandardSolution(
            isset($row[$prefix . 'id']) ? (int)$row[$prefix . 'id'] : null,
            (int)$row[$prefix . 'root_cause_id'],
            (string)$row[$prefix . 'code'],
            (string)$row[$prefix . 'name'],
            (bool)$row[$prefix . 'is_default'],
            (bool)$row[$prefix . 'is_active'],
            isset($row[$prefix . 'created_at']) && $row[$prefix . 'created_at'] !== null
                ? (string)$row[$prefix . 'created_at']
                : null,
            isset($row[$prefix . 'updated_at']) && $row[$prefix . 'updated_at'] !== null
                ? (string)$row[$prefix . 'updated_at']
                : null
        );
    }

    private function existsCode(string $table, string $code): bool
    {
        if (!in_array($table, ['root_causes', 'standard_solutions'], true)) {
            throw new RuntimeException(sprintf('Tabla de catálogo no reconocida: «%s».', $table));
        }

        $normalizedCode = strtoupper(trim($code));
        if ($normalizedCode === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            sprintf('SELECT EXISTS (SELECT 1 FROM %s WHERE code = :code)', $table)
        );
        $stmt->bindValue(':code', $normalizedCode, PDO::PARAM_STR);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }

    private function assertOpenTransaction(string $operation): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException(sprintf(
                '%s exige una transacción abierta: sin ella el bloqueo por fila se libera al terminar la sentencia y la conmutación de la solución predeterminada dejaría de estar serializada.',
                $operation
            ));
        }
    }
}
