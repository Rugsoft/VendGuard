<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use VendGuard\Core\Domain\Model\MachineTroubleshootingGuide;
use VendGuard\Core\Domain\Repository\MachineTroubleshootingGuideRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * PdoMachineTroubleshootingGuideRepository — persistencia PDO/PostgreSQL del
 * catálogo de guías de diagnóstico (Módulo 28, Tabla 39).
 *
 * Decisiones de persistencia:
 *
 *   1. **La identidad no se reescribe.** `update()` selecciona sólo las columnas
 *      mutables: título, documentos, causa sugerida, vigencia y actor. El modelo y
 *      la categoría ni siquiera viajan en el `SET`, y el disparador
 *      `trg_immutable_machine_troubleshooting_guides` los blindaría igualmente
 *      (`C1`, Art. III.3).
 *
 *   2. **El orden canónico vive en una sola constante.** El conmutador del
 *      técnico y el catálogo de Coordinación comparten la jerarquía que prioriza
 *      la seguridad alimentaria (`QA 3`): `TEMPERATURE_COLD`, `PAYMENT_SYSTEM`,
 *      `PRODUCT_JAM`, `ELECTRICAL_OFF` y `OTHER`.
 *
 *   3. **La cobertura se calcula en el motor.** `fetchFleetCoverageSummary()`
 *      agrupa el parque por la clave normalizada de PostgreSQL
 *      (`lower(btrim(regexp_replace(model, '\s+', ' ', 'g')))`), que es la misma
 *      forma que produce `MachineTroubleshootingGuide::normalizeModelKey()` en
 *      PHP. Así dos variantes de escritura del mismo modelo cuentan como una
 *      (`QA 1`), y el cálculo no depende de que la aplicación traiga el parque
 *      entero a memoria.
 *
 *   4. **Los JSONB se escriben con `CAST`.** PDO envía los documentos como texto
 *      y PostgreSQL los convierte en el tipo declarado; la hidratación los vuelve
 *      a decodificar como listas, nunca como cadenas.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PdoMachineTroubleshootingGuideRepository implements MachineTroubleshootingGuideRepositoryInterface
{
    /** Jerarquía canónica del conmutador y del catálogo (`QA 3`). */
    private const CATEGORY_RANK_SQL = "CASE breakdown_type
        WHEN 'TEMPERATURE_COLD' THEN 1
        WHEN 'PAYMENT_SYSTEM' THEN 2
        WHEN 'PRODUCT_JAM' THEN 3
        WHEN 'ELECTRICAL_OFF' THEN 4
        ELSE 5
    END";

    /** Proyección única: la tabla no tiene columnas derivadas ni secretos. */
    private const SELECT_ALL = 'SELECT * FROM machine_troubleshooting_guides';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? ConnectionFactory::getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?MachineTroubleshootingGuide
    {
        $stmt = $this->pdo->prepare(self::SELECT_ALL . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $this->hydrateOrNull($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * {@inheritdoc}
     */
    public function findByModelKeyAndCategory(string $modelKey, string $breakdownType): ?MachineTroubleshootingGuide
    {
        $stmt = $this->pdo->prepare(
            self::SELECT_ALL . ' WHERE machine_model_key = :model_key AND breakdown_type = :breakdown_type LIMIT 1'
        );
        $stmt->execute([
            ':model_key' => MachineTroubleshootingGuide::normalizeModelKey($modelKey),
            ':breakdown_type' => $this->requireCategory($breakdownType)->value,
        ]);

        return $this->hydrateOrNull($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * {@inheritdoc}
     */
    public function findActiveByModelKey(string $modelKey): array
    {
        $sql = self::SELECT_ALL . '
            WHERE machine_model_key = :model_key
              AND is_active = TRUE
            ORDER BY ' . self::CATEGORY_RANK_SQL . ' ASC, breakdown_type ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':model_key' => MachineTroubleshootingGuide::normalizeModelKey($modelKey)]);

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * {@inheritdoc}
     */
    public function findAllActive(): array
    {
        $sql = self::SELECT_ALL . '
            WHERE is_active = TRUE
            ORDER BY machine_model ASC, ' . self::CATEGORY_RANK_SQL . ' ASC, id ASC';

        $stmt = $this->pdo->query($sql);

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * {@inheritdoc}
     */
    public function findFilteredForCoordinator(
        ?string $machineModel,
        ?string $breakdownType,
        string $status = 'ALL',
        ?string $search = null
    ): array {
        $where = [];
        $params = [];

        if ($machineModel !== null && trim($machineModel) !== '') {
            $where[] = 'machine_model_key = :model_key';
            $params[':model_key'] = MachineTroubleshootingGuide::normalizeModelKey($machineModel);
        }

        if ($breakdownType !== null && trim($breakdownType) !== '') {
            $where[] = 'breakdown_type = :breakdown_type';
            $params[':breakdown_type'] = $this->requireCategory($breakdownType)->value;
        }

        $normalizedStatus = strtoupper(trim($status));
        if (!in_array($normalizedStatus, ['ACTIVE', 'INACTIVE', 'ALL'], true)) {
            throw new InvalidArgumentException(sprintf(
                'El filtro de estado «%s» no es válido; use ACTIVE, INACTIVE o ALL.',
                $status
            ));
        }
        if ($normalizedStatus === 'ACTIVE') {
            $where[] = 'is_active = TRUE';
        } elseif ($normalizedStatus === 'INACTIVE') {
            $where[] = 'is_active = FALSE';
        }

        if ($search !== null && trim($search) !== '') {
            // `!` como escape: un `%` tecleado por Coordinación busca un porcentaje
            // y no «cualquier cosa».
            $where[] = "(title ILIKE :search ESCAPE '!' OR machine_model ILIKE :search ESCAPE '!')";
            $params[':search'] = '%' . str_replace(
                ['!', '%', '_'],
                ['!!', '!%', '!_'],
                trim($search)
            ) . '%';
        }

        $sql = self::SELECT_ALL
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY is_active DESC, machine_model ASC, ' . self::CATEGORY_RANK_SQL . ' ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * {@inheritdoc}
     */
    public function fetchFleetCoverageSummary(): array
    {
        $fleetSql = <<<'SQL'
            WITH fleet AS (
                SELECT
                    lower(btrim(regexp_replace(m.model, '\s+', ' ', 'g'))) AS model_key,
                    MIN(btrim(regexp_replace(m.model, '\s+', ' ', 'g'))) AS machine_model,
                    COUNT(*)::int AS active_machines_count
                  FROM machines m
                 WHERE m.is_active = TRUE
                   AND m.deleted_at IS NULL
                 GROUP BY 1
            ),
            active_guides AS (
                SELECT machine_model_key AS model_key, COUNT(*)::int AS active_guides_count
                  FROM machine_troubleshooting_guides
                 WHERE is_active = TRUE
                 GROUP BY 1
            )
            SELECT
                fleet.model_key,
                fleet.machine_model,
                fleet.active_machines_count,
                COALESCE(active_guides.active_guides_count, 0)::int AS active_guides_count,
                (active_guides.active_guides_count IS NOT NULL) AS is_covered
              FROM fleet
              LEFT JOIN active_guides ON active_guides.model_key = fleet.model_key
             ORDER BY fleet.machine_model ASC
        SQL;

        $fleetRows = $this->pdo->query($fleetSql)->fetchAll(PDO::FETCH_ASSOC);

        $fleetModels = [];
        $orphanModels = [];
        $coveredCount = 0;

        foreach ($fleetRows as $row) {
            $activeGuides = (int)$row['active_guides_count'];
            $isCovered = $activeGuides > 0;

            $model = [
                'machine_model' => (string)$row['machine_model'],
                'machine_model_key' => (string)$row['model_key'],
                'active_machines_count' => (int)$row['active_machines_count'],
                'active_guides_count' => $activeGuides,
                'is_covered' => $isCovered,
            ];
            $fleetModels[] = $model;

            if ($isCovered) {
                $coveredCount++;
            } else {
                $orphanModels[] = [
                    'machine_model' => $model['machine_model'],
                    'machine_model_key' => $model['machine_model_key'],
                    'active_machines_count' => $model['active_machines_count'],
                ];
            }
        }

        $totals = $this->pdo->query(
            'SELECT COUNT(*)::int AS total_guides,
                    COUNT(*) FILTER (WHERE is_active = TRUE)::int AS total_active_guides
               FROM machine_troubleshooting_guides'
        )->fetch(PDO::FETCH_ASSOC);
        $totals = is_array($totals) ? $totals : ['total_guides' => 0, 'total_active_guides' => 0];

        return [
            'total_active_guides' => (int)$totals['total_active_guides'],
            'total_guides' => (int)$totals['total_guides'],
            'covered_fleet_models_count' => $coveredCount,
            'total_active_fleet_models_count' => count($fleetModels),
            'orphan_fleet_models_count' => count($orphanModels),
            'orphan_fleet_models' => $orphanModels,
            'fleet_models' => $fleetModels,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function create(MachineTroubleshootingGuide $guide): MachineTroubleshootingGuide
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO machine_troubleshooting_guides (
                machine_model, machine_model_key, breakdown_type, title,
                error_codes_json, steps_json, recommended_spare_part_ids,
                suggested_root_cause_id, is_active, created_by, updated_by
            ) VALUES (
                :machine_model, :machine_model_key, :breakdown_type, :title,
                CAST(:error_codes AS JSONB), CAST(:steps AS JSONB), CAST(:spare_parts AS JSONB),
                :suggested_root_cause_id, :is_active, :created_by, :updated_by
            ) RETURNING *'
        );

        $stmt->execute($this->writeParams($guide));

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('El motor no devolvió la guía de diagnóstico creada.');
        }

        return $this->hydrate($row);
    }

    /**
     * {@inheritdoc}
     */
    public function update(MachineTroubleshootingGuide $guide): MachineTroubleshootingGuide
    {
        $id = $guide->getId();
        if ($id === null) {
            throw new InvalidArgumentException('No se puede actualizar una guía de diagnóstico sin identificador.');
        }

        // El modelo y la categoría no viajan: son la identidad inmutable (C1).
        $stmt = $this->pdo->prepare(
            'UPDATE machine_troubleshooting_guides SET
                title = :title,
                error_codes_json = CAST(:error_codes AS JSONB),
                steps_json = CAST(:steps AS JSONB),
                recommended_spare_part_ids = CAST(:spare_parts AS JSONB),
                suggested_root_cause_id = :suggested_root_cause_id,
                is_active = :is_active,
                updated_by = :updated_by
             WHERE id = :id
             RETURNING *'
        );

        // Mapa propio de la edición: el del alta incluye modelo, categoría y
        // `created_by`, que aquí no son tokens y PDO rechazaría por parámetros
        // sobrantes (HY093).
        $stmt->execute([
            ':title' => $guide->getTitle(),
            ':error_codes' => (string)json_encode($guide->getErrorCodes(), JSON_UNESCAPED_UNICODE),
            ':steps' => (string)json_encode($guide->getSteps(), JSON_UNESCAPED_UNICODE),
            ':spare_parts' => (string)json_encode($guide->getRecommendedSparePartIds()),
            ':suggested_root_cause_id' => $guide->getSuggestedRootCauseId(),
            ':is_active' => $guide->isActive() ? 'true' : 'false',
            ':updated_by' => $guide->getUpdatedBy(),
            ':id' => $id,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException(sprintf(
                'La guía de diagnóstico %d ya no existe y no se pudo actualizar.',
                $id
            ));
        }

        return $this->hydrate($row);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Interno
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Parámetros de escritura comunes al alta y a la edición.
     *
     * @return array<string, mixed>
     */
    private function writeParams(MachineTroubleshootingGuide $guide): array
    {
        return [
            ':machine_model' => $guide->getMachineModel(),
            ':machine_model_key' => $guide->getMachineModelKey(),
            ':breakdown_type' => $guide->getBreakdownType()->value,
            ':title' => $guide->getTitle(),
            ':error_codes' => (string)json_encode($guide->getErrorCodes(), JSON_UNESCAPED_UNICODE),
            ':steps' => (string)json_encode($guide->getSteps(), JSON_UNESCAPED_UNICODE),
            ':spare_parts' => (string)json_encode($guide->getRecommendedSparePartIds()),
            ':suggested_root_cause_id' => $guide->getSuggestedRootCauseId(),
            ':is_active' => $guide->isActive() ? 'true' : 'false',
            ':created_by' => $guide->getCreatedBy(),
            ':updated_by' => $guide->getUpdatedBy(),
        ];
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function hydrateOrNull(array|false $row): ?MachineTroubleshootingGuide
    {
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<MachineTroubleshootingGuide>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(fn (array $row): MachineTroubleshootingGuide => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MachineTroubleshootingGuide
    {
        $errorCodes = json_decode((string)$row['error_codes_json'], true);
        $steps = json_decode((string)$row['steps_json'], true);
        $spareParts = json_decode((string)$row['recommended_spare_part_ids'], true);

        return new MachineTroubleshootingGuide(
            (int)$row['id'],
            (string)$row['machine_model'],
            IncidentCategory::from((string)$row['breakdown_type']),
            (string)$row['title'],
            is_array($errorCodes) ? array_values($errorCodes) : [],
            is_array($steps) ? array_values($steps) : [],
            is_array($spareParts) ? array_values($spareParts) : [],
            $row['suggested_root_cause_id'] !== null ? (int)$row['suggested_root_cause_id'] : null,
            self::asBool($row['is_active']),
            $row['created_by'] !== null ? (int)$row['created_by'] : null,
            $row['updated_by'] !== null ? (int)$row['updated_by'] : null,
            $row['created_at'] !== null ? (string)$row['created_at'] : null,
            $row['updated_at'] !== null ? (string)$row['updated_at'] : null
        );
    }

    /**
     * PDO/PostgreSQL puede devolver un booleano como `bool` o como `'t'`/`'f'`;
     * convertir `'f'` con `(bool)` daría `true`, así que la forma se resuelve aquí.
     */
    private static function asBool(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't'
            || $value === 'true';
    }

    private function requireCategory(string $breakdownType): IncidentCategory
    {
        $category = IncidentCategory::tryFrom(strtoupper(trim($breakdownType)));
        if ($category === null) {
            throw new InvalidArgumentException(sprintf(
                'La categoría «%s» no pertenece al catálogo de averías.',
                $breakdownType
            ));
        }

        return $category;
    }
}
