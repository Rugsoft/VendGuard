<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;
use VendGuard\Core\Domain\Exception\TroubleshootingGuideValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\MachineTroubleshootingGuide;
use VendGuard\Core\Domain\Model\RootCause;
use VendGuard\Core\Domain\Model\SparePart;
use VendGuard\Core\Domain\Repository\MachineTroubleshootingGuideRepositoryInterface;
use VendGuard\Core\Domain\Repository\RootCauseRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Service\TroubleshootingGuideValidator;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;

/**
 * TroubleshootingGuideService
 *
 * Casos de uso del catálogo de guías rápidas de diagnóstico (Módulo 28,
 * `RF-01`–`RF-05`, `D1`–`D4`, `C1`–`C5`, `QA 1`–`QA 4`, Art. II, III.3 y V.4).
 *
 * Decisiones que este servicio hace cumplir:
 *
 *   1. **El emparejamiento es por clave, no por texto (`C5`).** El modelo se
 *      normaliza antes de consultar, de modo que «Sanden  Vendo G-Drink» y
 *      «sanden vendo g-drink» caen en la misma ficha. La categoría solicitada se
 *      sitúa en primera posición y el resto conserva la jerarquía canónica que
 *      prioriza la seguridad alimentaria (`QA 3`); si no hay coincidencia,
 *      `has_exact_category_match = false` y se preselecciona la primera guía
 *      disponible (`RF-01.2`).
 *   2. **La salvaguarda sanitaria no depende del cliente (`Art. II`).** El aviso
 *      de frío se enciende en toda guía de `TEMPERATURE_COLD` y en toda consulta
 *      sobre una máquina `PERISHABLE_FOOD`, aunque la categoría reportada sea
 *      otra (`RF-01.3`).
 *   3. **La furgoneta se cruza, el dinero no viaja (`Art. V.4`).** Los repuestos
 *      recomendados conservan el orden definido por Coordinación (`QA 3`) y se
 *      enriquecen con el saldo del técnico autenticado; ninguna respuesta
 *      incluye coste, precio ni importe.
 *   4. **Las bajas del catálogo se toleran (`C2`).** Una pieza o una causa raíz
 *      desactivada después sigue sin romper la consulta: la pieza viaja marcada
 *      `is_active = false` y la causa sugerida se oculta, porque ya no es una
 *      recomendación vigente.
 *   5. **La cobertura es inmune a los filtros (`QA 1`).** El resumen se calcula
 *      siempre sobre el parque global; filtrar la tabla inferior no puede apagar
 *      las tres métricas de Coordinación.
 *   6. **Toda escritura es atómica y auditada (`Art. III.1–III.3`).** Alta,
 *      edición y cambio de vigencia comparten una unidad de trabajo con su
 *      evento `MACHINE_TROUBLESHOOTING_GUIDE`, con el antes y el después
 *      completos. Si la auditoría falla, la mutación no se confirma.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class TroubleshootingGuideService
{
    /** Tipo de entidad de auditoría del catálogo (Art. III.3). */
    public const AUDIT_ENTITY_TYPE = AuditEvent::ENTITY_MACHINE_TROUBLESHOOTING_GUIDE;

    public const AUDIT_ACTION_CREATE = 'CREATE_TROUBLESHOOTING_GUIDE';

    public const AUDIT_ACTION_UPDATE = 'UPDATE_TROUBLESHOOTING_GUIDE';

    public const AUDIT_ACTION_ACTIVATE = 'ACTIVATE_TROUBLESHOOTING_GUIDE';

    public const AUDIT_ACTION_DEACTIVATE = 'DEACTIVATE_TROUBLESHOOTING_GUIDE';

    /** Tipo de máquina que activa la salvaguarda de frío (Art. II). */
    private const PERISHABLE_MACHINE_TYPE = 'PERISHABLE_FOOD';

    private MachineTroubleshootingGuideRepositoryInterface $guideRepository;

    private RootCauseRepositoryInterface $rootCauseRepository;

    private SparePartRepositoryInterface $sparePartRepository;

    private PDO $pdo;

    private ?AuditLogger $auditLogger;

    public function __construct(
        MachineTroubleshootingGuideRepositoryInterface $guideRepository,
        RootCauseRepositoryInterface $rootCauseRepository,
        SparePartRepositoryInterface $sparePartRepository,
        PDO $pdo,
        ?AuditLogger $auditLogger = null
    ) {
        $this->guideRepository = $guideRepository;
        $this->rootCauseRepository = $rootCauseRepository;
        $this->sparePartRepository = $sparePartRepository;
        $this->pdo = $pdo;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Guías activas de un modelo, conmutador de categoría y cruce de furgoneta
     * (`RF-01.1`–`RF-01.3`, `RF-02.1`–`RF-02.3`, `D1`, `D3`, `C2`, `C5`, `QA 3`).
     *
     * @return array{
     *     machine_model: string,
     *     machine_model_key: string,
     *     requested_breakdown_type: string|null,
     *     has_exact_category_match: bool,
     *     selected_guide_id: int|null,
     *     sanitary_cold_warning_for_machine: bool,
     *     guides: list<array<string, mixed>>
     * }
     *
     * @throws TroubleshootingGuideValidationException Si la categoría solicitada no existe.
     */
    public function getGuidesForModel(
        string $machineModel,
        ?string $breakdownType,
        ?string $machineType,
        ?int $technicianId
    ): array {
        $normalizedModel = MachineTroubleshootingGuide::normalizeModelName($machineModel);
        if ($normalizedModel === '') {
            throw TroubleshootingGuideValidationException::invalidModel(0);
        }

        $modelKey = MachineTroubleshootingGuide::normalizeModelKey($normalizedModel);
        $requestedCategory = $this->parseRequestedCategory($breakdownType);
        $isPerishable = $machineType !== null
            && strtoupper(trim($machineType)) === self::PERISHABLE_MACHINE_TYPE;

        $guides = $this->guideRepository->findActiveByModelKey($modelKey);

        // La coincidencia exacta sube a la primera posición; el resto conserva el
        // orden canónico que ya devolvió el repositorio (QA 3).
        $exactMatch = null;
        if ($requestedCategory !== null) {
            foreach ($guides as $index => $guide) {
                if ($guide->getBreakdownType() === $requestedCategory) {
                    $exactMatch = $guide;
                    unset($guides[$index]);
                    break;
                }
            }
        }

        $orderedGuides = $exactMatch !== null
            ? array_merge([$exactMatch], array_values($guides))
            : array_values($guides);

        $vanStock = $this->fetchVanStock($technicianId);
        $rootCauseCache = [];
        $payload = [];
        foreach ($orderedGuides as $guide) {
            $payload[] = $this->enrichGuide($guide, $vanStock, $isPerishable, $rootCauseCache);
        }

        $warningForMachine = $isPerishable
            || $requestedCategory === IncidentCategory::TEMPERATURE_COLD
            || $this->containsColdWarningGuide($orderedGuides);

        return [
            'machine_model' => $normalizedModel,
            'machine_model_key' => $modelKey,
            'requested_breakdown_type' => $requestedCategory?->value,
            'has_exact_category_match' => $exactMatch !== null,
            'selected_guide_id' => isset($orderedGuides[0]) ? $orderedGuides[0]->getId() : null,
            'sanitary_cold_warning_for_machine' => $warningForMachine,
            'guides' => $payload,
        ];
    }

    /**
     * Catálogo de Coordinación con su resumen de cobertura (`RF-04.1`–`RF-04.3`, `QA 1`).
     *
     * El resumen no recibe los filtros a propósito: se calcula sobre el parque
     * global y no cae a cero cuando la tabla inferior está filtrada.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{
     *     coverage_summary: array<string, mixed>,
     *     guides: list<array<string, mixed>>
     * }
     */
    public function listForCoordinator(array $filters): array
    {
        $machineModel = isset($filters['machine_model']) && is_string($filters['machine_model'])
            ? $filters['machine_model']
            : null;
        $breakdownType = isset($filters['breakdown_type']) && is_string($filters['breakdown_type'])
            ? $filters['breakdown_type']
            : null;
        $status = isset($filters['status']) && is_string($filters['status'])
            ? $filters['status']
            : 'ALL';
        $search = isset($filters['search']) && is_string($filters['search'])
            ? $filters['search']
            : null;

        $guides = $this->guideRepository->findFilteredForCoordinator(
            $machineModel,
            $breakdownType,
            $status,
            $search
        );

        return [
            'coverage_summary' => $this->guideRepository->fetchFleetCoverageSummary(),
            'guides' => array_map(
                static fn (MachineTroubleshootingGuide $guide): array => $guide->jsonSerialize(),
                $guides
            ),
        ];
    }

    /**
     * Alta auditada de una guía (`RF-05.1`, `C1`–`C3`, `QA 2`, Art. III.3).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws TroubleshootingGuideValidationException
     */
    public function createGuide(array $payload, int $actorId, string $actorName): array
    {
        $this->assertActor($actorId);

        $rootCause = $this->resolveRootCause($payload['suggested_root_cause_id'] ?? null);
        $normalized = TroubleshootingGuideValidator::validateCreate($payload, $rootCause);

        $existing = $this->guideRepository->findByModelKeyAndCategory(
            $normalized['machine_model_key'],
            $normalized['breakdown_type']->value
        );
        if ($existing !== null) {
            throw TroubleshootingGuideValidationException::duplicateGuide(
                $normalized['machine_model_key'],
                $normalized['breakdown_type']->value
            );
        }

        $this->assertActiveSpareParts($normalized['recommended_spare_part_ids']);

        $guide = new MachineTroubleshootingGuide(
            null,
            $normalized['machine_model'],
            $normalized['breakdown_type'],
            $normalized['title'],
            $normalized['error_codes'],
            $normalized['steps'],
            $normalized['recommended_spare_part_ids'],
            $normalized['suggested_root_cause_id'],
            true,
            $actorId,
            $actorId
        );

        try {
            return $this->transactional(function () use ($guide, $actorId, $actorName): array {
                $created = $this->guideRepository->create($guide);

                $this->audit(
                    $created,
                    self::AUDIT_ACTION_CREATE,
                    $actorId,
                    $actorName,
                    null,
                    $created->jsonSerialize()
                );

                return $created->jsonSerialize();
            });
        } catch (PDOException $exception) {
            // Carrera entre la comprobación previa y el INSERT: la clave única
            // sigue siendo la última palabra (C1).
            if ($this->isDuplicateKeyViolation($exception)) {
                throw TroubleshootingGuideValidationException::duplicateGuide(
                    $normalized['machine_model_key'],
                    $normalized['breakdown_type']->value
                );
            }

            throw $exception;
        }
    }

    /**
     * Edición auditada de los campos mutables (`RF-05.1`, `C1`, `C2`, Art. III.3).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws TroubleshootingGuideValidationException
     */
    public function updateGuide(int $id, array $payload, int $actorId, string $actorName): array
    {
        $this->assertActor($actorId);

        $current = $this->guideRepository->findById($id);
        if ($current === null) {
            throw TroubleshootingGuideValidationException::guideNotFound($id);
        }

        $rootCause = array_key_exists('suggested_root_cause_id', $payload)
            ? $this->resolveRootCause($payload['suggested_root_cause_id'])
            : null;

        $normalized = TroubleshootingGuideValidator::validateUpdate($payload, $current, $rootCause);

        // Sólo se exige vigencia a las piezas que el cuerpo vuelve a declarar: una
        // pieza desactivada después no puede impedir editar el título (C2).
        if (array_key_exists('recommended_spare_part_ids', $payload)) {
            $this->assertActiveSpareParts($normalized['recommended_spare_part_ids']);
        }

        $updated = new MachineTroubleshootingGuide(
            $current->getId(),
            $current->getMachineModel(),
            $current->getBreakdownType(),
            $normalized['title'],
            $normalized['error_codes'],
            $normalized['steps'],
            $normalized['recommended_spare_part_ids'],
            $normalized['suggested_root_cause_id'],
            $current->isActive(),
            $current->getCreatedBy(),
            $actorId,
            $current->getCreatedAt(),
            null
        );

        return $this->transactional(function () use ($current, $updated, $actorId, $actorName): array {
            $persisted = $this->guideRepository->update($updated);

            $this->audit(
                $persisted,
                self::AUDIT_ACTION_UPDATE,
                $actorId,
                $actorName,
                $current->jsonSerialize(),
                $persisted->jsonSerialize()
            );

            return $persisted->jsonSerialize();
        });
    }

    /**
     * Activación o baja lógica auditada (`RF-05.1`, Art. III.1–III.3).
     *
     * @return array<string, mixed>
     *
     * @throws TroubleshootingGuideValidationException
     */
    public function setGuideActiveStatus(int $id, bool $isActive, int $actorId, string $actorName): array
    {
        $this->assertActor($actorId);

        $current = $this->guideRepository->findById($id);
        if ($current === null) {
            throw TroubleshootingGuideValidationException::guideNotFound($id);
        }

        $updated = $current->withStatus($isActive, $actorId);

        return $this->transactional(function () use ($current, $updated, $isActive, $actorId, $actorName): array {
            $persisted = $this->guideRepository->update($updated);

            $this->audit(
                $persisted,
                $isActive ? self::AUDIT_ACTION_ACTIVATE : self::AUDIT_ACTION_DEACTIVATE,
                $actorId,
                $actorName,
                $current->jsonSerialize(),
                $persisted->jsonSerialize()
            );

            return $persisted->jsonSerialize();
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Enriquecimiento de lectura
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Proyecta una guía con su aviso sanitario, repuestos cruzados con la
     * furgoneta y causa sugerida vigente.
     *
     * @param array<int, int>                $vanStock
     * @param array<int, RootCause|null>     $rootCauseCache
     *
     * @return array<string, mixed>
     */
    private function enrichGuide(
        MachineTroubleshootingGuide $guide,
        array $vanStock,
        bool $isPerishableMachine,
        array &$rootCauseCache
    ): array {
        $parts = [];
        foreach ($guide->getRecommendedSparePartIds() as $partId) {
            $part = $this->sparePartRepository->findById($partId);
            if ($part === null) {
                // La pieza desapareció del maestro: no se inventa una fila vacía.
                continue;
            }

            $quantity = $vanStock[$partId] ?? 0;
            $parts[] = [
                'spare_part_id' => (int)$part->getId(),
                'part_code' => $part->getPartCode(),
                'name' => $part->getName(),
                'category' => $part->getCategory()->value,
                'is_active' => $part->isActive(),
                'van_stock_quantity' => $quantity,
                'has_van_stock' => $quantity > 0,
            ];
        }

        $rootCauseId = $guide->getSuggestedRootCauseId();
        if ($rootCauseId !== null && !array_key_exists($rootCauseId, $rootCauseCache)) {
            $rootCauseCache[$rootCauseId] = $this->rootCauseRepository->findRootCauseById($rootCauseId);
        }

        return [
            'id' => $guide->getId(),
            'machine_model' => $guide->getMachineModel(),
            'machine_model_key' => $guide->getMachineModelKey(),
            'breakdown_type' => $guide->getBreakdownType()->value,
            'breakdown_type_label' => $guide->getBreakdownType()->label(),
            'title' => $guide->getTitle(),
            'sanitary_cold_warning' => $isPerishableMachine
                || $guide->getBreakdownType() === IncidentCategory::TEMPERATURE_COLD,
            'error_codes' => $guide->getErrorCodes(),
            'steps' => $guide->getSteps(),
            'recommended_spare_parts' => $parts,
            'suggested_root_cause' => $this->projectRootCause(
                $rootCauseId !== null ? ($rootCauseCache[$rootCauseId] ?? null) : null
            ),
        ];
    }

    /**
     * Causa sugerida visible sólo mientras siga activa en el catálogo (`C2`).
     *
     * @return array<string, mixed>|null
     */
    private function projectRootCause(?RootCause $rootCause): ?array
    {
        if ($rootCause === null || !$rootCause->isActive()) {
            return null;
        }

        $defaultSolution = $rootCause->getDefaultSolution();
        $defaultPayload = null;
        if ($defaultSolution !== null && $defaultSolution->isActive()) {
            $defaultPayload = [
                'id' => $defaultSolution->getId(),
                'code' => $defaultSolution->getCode(),
                'name' => $defaultSolution->getName(),
            ];
        }

        return [
            'id' => $rootCause->getId(),
            'code' => $rootCause->getCode(),
            'category' => $rootCause->getCategory()->value,
            'name' => $rootCause->getName(),
            'is_active' => true,
            'default_solution' => $defaultPayload,
        ];
    }

    /**
     * Saldo de la furgoneta del técnico autenticado, indexado por repuesto.
     *
     * @return array<int, int>
     */
    private function fetchVanStock(?int $technicianId): array
    {
        if ($technicianId === null || $technicianId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT spare_part_id, quantity_available
               FROM technician_van_stocks
              WHERE technician_id = :technician_id'
        );
        $stmt->execute([':technician_id' => $technicianId]);

        $stock = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stock[(int)$row['spare_part_id']] = (int)$row['quantity_available'];
        }

        return $stock;
    }

    /**
     * Una consulta sin categoría de frío sigue avisando si el modelo tiene alguna
     * guía de cadena de frío entre sus activas (Art. II).
     *
     * @param list<MachineTroubleshootingGuide> $guides
     */
    private function containsColdWarningGuide(array $guides): bool
    {
        foreach ($guides as $guide) {
            if ($guide->getBreakdownType() === IncidentCategory::TEMPERATURE_COLD) {
                return true;
            }
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Guardas de escritura
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param list<int> $sparePartIds
     *
     * @throws TroubleshootingGuideValidationException
     */
    private function assertActiveSpareParts(array $sparePartIds): void
    {
        foreach ($sparePartIds as $partId) {
            /** @var SparePart|null $part */
            $part = $this->sparePartRepository->findById($partId);
            if ($part === null || !$part->isActive()) {
                throw TroubleshootingGuideValidationException::invalidSpareParts(sprintf(
                    'el repuesto %d no existe o está desactivado en el catálogo maestro (C2).',
                    $partId
                ));
            }
        }
    }

    private function resolveRootCause(mixed $rawRootCauseId): ?RootCause
    {
        if ($rawRootCauseId === null || $rawRootCauseId === '') {
            return null;
        }

        $rootCauseId = is_int($rawRootCauseId)
            ? $rawRootCauseId
            : (is_string($rawRootCauseId) && preg_match('/^\d+$/', $rawRootCauseId) === 1
                ? (int)$rawRootCauseId
                : 0);

        // El validador decide si el hueco es legítimo o un 422; aquí sólo se
        // resuelve la entidad cuando el identificador es plausible.
        return $rootCauseId > 0
            ? $this->rootCauseRepository->findRootCauseById($rootCauseId)
            : null;
    }

    /**
     * @throws TroubleshootingGuideValidationException
     */
    private function parseRequestedCategory(?string $breakdownType): ?IncidentCategory
    {
        if ($breakdownType === null || trim($breakdownType) === '') {
            return null;
        }

        $category = IncidentCategory::tryFrom(strtoupper(trim($breakdownType)));
        if ($category === null) {
            throw TroubleshootingGuideValidationException::invalidCategory($breakdownType);
        }

        return $category;
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('La operación exige un coordinador autenticado.');
        }
    }

    /**
     * @param array<string, mixed>|null $previousState
     * @param array<string, mixed>      $newState
     */
    private function audit(
        MachineTroubleshootingGuide $guide,
        string $action,
        int $actorId,
        string $actorName,
        ?array $previousState,
        array $newState
    ): void {
        $guideId = $guide->getId();
        if ($guideId === null) {
            throw new InvalidArgumentException('No se puede auditar una guía sin identificador persistido.');
        }

        $this->auditLogger()->logEvent(new AuditEvent(
            null,
            self::AUDIT_ENTITY_TYPE,
            $guideId,
            $action,
            $actorId,
            'COORDINATOR',
            trim($actorName) !== '' ? trim($actorName) : 'Coordinación de Servicio',
            $previousState,
            $newState
        ));
    }

    private function auditLogger(): AuditLogger
    {
        return $this->auditLogger ??= new AuditLogger(new PdoAuditLogRepository($this->pdo));
    }

    /**
     * Ejecuta la operación en una unidad de trabajo propia, tolerando el anidamiento.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $operation();

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function isDuplicateKeyViolation(PDOException $exception): bool
    {
        return (string)$exception->getCode() === '23505'
            && str_contains($exception->getMessage(), 'uq_troubleshooting_guides_model_category');
    }
}
