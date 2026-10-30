<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\RootCause;
use VendGuard\Core\Domain\Model\StandardSolution;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\RootCauseRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;

/**
 * RootCauseTaxonomyService
 *
 * Orquestador transaccional del catálogo maestro de causas raíz y soluciones
 * estandarizadas (Módulo 25, RF-01, D1/D3, C2/C4, QA 1).
 *
 * Reglas que este servicio hace cumplir, en una única unidad de trabajo por
 * operación (`BEGIN ... COMMIT`, con `SELECT ... FOR UPDATE` en las entidades
 * que va a mutar):
 *
 *   1. **Alta atómica (`EARS 01.1`):** una causa raíz no nace sin su primera
 *      solución estandarizada, activa y predeterminada. Si la solución no supera
 *      las invariantes de la entidad, la causa insertada se deshace con el
 *      `ROLLBACK`; jamás queda una causa huérfana en el catálogo.
 *   2. **Inmutabilidad (`C4`, `QA 1`):** `code` y `category` de la causa y
 *      `code` y `root_cause_id` de la solución no cambian tras el alta. La base
 *      de datos también los blinda con disparadores, pero el servicio rechaza
 *      antes de llegar a ellos para devolver un `422` legible.
 *   3. **Vigencia segura (`C2`, `QA 1`):** no se desactiva la última solución
 *      activa de una causa activa, ni la predeterminada vigente sin relevarla
 *      antes. Una causa huérfana no se reactiva sin designar una predeterminada.
 *   4. **Conmutación atómica (`D1`):** marcar una solución como predeterminada
 *      desmarca la anterior en la misma transacción, respetando el índice único
 *      parcial `uq_standard_solutions_default_per_cause`.
 *   5. **Auditoría antes/después (`Art. III.3`):** toda escritura deja su evento
 *      bajo `entity_type = 'ROOT_CAUSE_TAXONOMY'`, con el estado previo y el
 *      resultante. Si la auditoría falla, la mutación no se confirma.
 *
 * Los códigos simbólicos del contrato encabezan el mensaje de la excepción
 * (`CANNOT_DEACTIVATE_DEFAULT_SOLUTION: ...`) para que el controlador de T-10
 * los traduzca al `error.code` de la API sin reinterpretar el texto. Los
 * conflictos de estado viajan como `DomainException` con el código HTTP en el
 * código de la excepción; las violaciones de validación, como
 * `InvalidArgumentException` (`422`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 */
final class RootCauseTaxonomyService
{
    /** Tipo de entidad de auditoría del catálogo (Art. III.3, QA 1). */
    public const AUDIT_ENTITY_TYPE = AuditEvent::ENTITY_ROOT_CAUSE_TAXONOMY;

    private RootCauseRepositoryInterface $repository;

    private AuditLogRepositoryInterface $auditLog;

    private PDO $pdo;

    public function __construct(
        RootCauseRepositoryInterface $repository,
        AuditLogRepositoryInterface $auditLog,
        PDO $pdo
    ) {
        $this->repository = $repository;
        $this->auditLog = $auditLog;
        $this->pdo = $pdo;
    }

    /**
     * Alta transaccional de una causa raíz con su solución predeterminada
     * inicial (`EARS 01.1`, `QA 1`).
     *
     * @param array<string, mixed> $payload
     */
    public function createRootCauseWithInitialSolution(
        array $payload,
        ?int $coordinatorId = null,
        string $coordinatorName = 'Coordinación de Servicio'
    ): RootCause {
        $initialSolution = $payload['initial_solution'] ?? null;
        if (!is_array($initialSolution)) {
            throw new InvalidArgumentException(
                'INITIAL_DEFAULT_SOLUTION_REQUIRED: el alta de una causa raíz exige su solución predeterminada inicial.'
            );
        }

        $initialCode = $this->requiredString($initialSolution, 'code', 'INVALID_TAXONOMY_CODE');
        $initialName = $this->requiredString($initialSolution, 'name', 'INVALID_TAXONOMY_NAME_LENGTH');

        $cause = new RootCause(
            null,
            $this->requiredString($payload, 'code', 'INVALID_TAXONOMY_CODE'),
            $this->resolveCategory($payload['category'] ?? null),
            $this->requiredString($payload, 'name', 'INVALID_TAXONOMY_NAME_LENGTH'),
            $this->optionalString($payload, 'description'),
            true
        );

        return $this->transactional(function () use (
            $cause,
            $initialCode,
            $initialName,
            $coordinatorId,
            $coordinatorName
        ): RootCause {
            if ($this->repository->existsRootCauseCode($cause->getCode())) {
                throw new DomainException(
                    'DUPLICATE_TAXONOMY_CODE: ya existe una causa raíz con el código ' . $cause->getCode() . '.',
                    409
                );
            }
            if ($this->repository->existsSolutionCode($initialCode)) {
                throw new DomainException(
                    'DUPLICATE_TAXONOMY_CODE: ya existe una solución estandarizada con el código '
                    . strtoupper(trim($initialCode)) . '.',
                    409
                );
            }

            try {
                $persistedCause = $this->repository->insertRootCause($cause);

                $initialSolution = new StandardSolution(
                    null,
                    (int) $persistedCause->getId(),
                    $initialCode,
                    $initialName,
                    true,
                    true
                );

                $persistedSolution = $this->repository->insertStandardSolution($initialSolution);
            } catch (PDOException $exception) {
                throw $this->translateUniqueViolation($exception);
            }

            $this->recordAudit(
                'CREATE_ROOT_CAUSE',
                (int) $persistedCause->getId(),
                null,
                [
                    'root_cause' => $persistedCause->toArray(),
                    'initial_solution' => $persistedSolution->toArray(),
                ],
                $coordinatorId,
                $coordinatorName
            );

            return $persistedCause->withSolutions([$persistedSolution]);
        });
    }

    /**
     * Edición de los campos mutables de la causa y conmutación de la solución
     * predeterminada (`EARS 01.3`, `EARS 01.4`, `QA 1`).
     *
     * @param array<string, mixed> $payload
     */
    public function updateRootCause(
        int $rootCauseId,
        array $payload,
        ?int $coordinatorId = null,
        string $coordinatorName = 'Coordinación de Servicio'
    ): RootCause {
        return $this->transactional(function () use (
            $rootCauseId,
            $payload,
            $coordinatorId,
            $coordinatorName
        ): RootCause {
            $current = $this->repository->findRootCauseForUpdate($rootCauseId);
            if ($current === null) {
                throw new DomainException('ROOT_CAUSE_NOT_FOUND: la causa raíz solicitada no existe.', 404);
            }

            $this->assertCauseImmutability($current, $payload);

            $targetIsActive = $this->booleanField($payload, 'is_active', $current->isActive());
            $defaultSolutionId = $this->optionalPositiveInt($payload, 'default_solution_id');

            if ($targetIsActive && $defaultSolutionId !== null) {
                $this->designateDefaultSolution(
                    $current,
                    $defaultSolutionId,
                    $coordinatorId,
                    $coordinatorName
                );
            }

            if (!$current->isActive() && $targetIsActive && !$this->repository->hasActiveDefaultSolution($rootCauseId)) {
                throw new DomainException(
                    'CANNOT_REACTIVATE_ORPHAN_ROOT_CAUSE: la causa raíz no tiene una solución predeterminada activa; '
                    . 'designe una antes de reactivarla.',
                    409
                );
            }

            $updated = $this->repository->updateRootCause(new RootCause(
                $current->getId(),
                $current->getCode(),
                $current->getCategory(),
                array_key_exists('name', $payload) ? (string) $payload['name'] : $current->getName(),
                array_key_exists('description', $payload)
                    ? $this->optionalString($payload, 'description')
                    : $current->getDescription(),
                $targetIsActive,
                [],
                $current->getCreatedAt(),
                $current->getUpdatedAt()
            ));

            $action = 'UPDATE_ROOT_CAUSE';
            if ($current->isActive() !== $updated->isActive()) {
                $action = $updated->isActive() ? 'REACTIVATE_ROOT_CAUSE' : 'DEACTIVATE_ROOT_CAUSE';
            }

            $this->recordAudit(
                $action,
                $rootCauseId,
                $current->toArray(),
                $updated->toArray(),
                $coordinatorId,
                $coordinatorName
            );

            return $updated;
        });
    }

    /**
     * Alta de una solución estandarizada vinculada a una causa (`EARS 01.2`).
     *
     * @param array<string, mixed> $payload
     */
    public function createStandardSolution(
        int $rootCauseId,
        array $payload,
        ?int $coordinatorId = null,
        string $coordinatorName = 'Coordinación de Servicio'
    ): StandardSolution {
        return $this->transactional(function () use (
            $rootCauseId,
            $payload,
            $coordinatorId,
            $coordinatorName
        ): StandardSolution {
            $cause = $this->repository->findRootCauseForUpdate($rootCauseId);
            if ($cause === null) {
                throw new DomainException('ROOT_CAUSE_NOT_FOUND: la causa raíz solicitada no existe.', 404);
            }

            $isDefault = $this->booleanField($payload, 'is_default', false);
            $solution = new StandardSolution(
                null,
                $rootCauseId,
                $this->requiredString($payload, 'code', 'INVALID_TAXONOMY_CODE'),
                $this->requiredString($payload, 'name', 'INVALID_TAXONOMY_NAME_LENGTH'),
                $isDefault,
                true
            );

            if ($this->repository->existsSolutionCode($solution->getCode())) {
                throw new DomainException(
                    'DUPLICATE_TAXONOMY_CODE: ya existe una solución estandarizada con el código '
                    . $solution->getCode() . '.',
                    409
                );
            }

            if ($isDefault) {
                $this->repository->clearActiveDefaultForCause($rootCauseId);
            }

            try {
                $persisted = $this->repository->insertStandardSolution($solution);
            } catch (PDOException $exception) {
                throw $this->translateUniqueViolation($exception);
            }

            $this->recordAudit(
                'CREATE_STANDARD_SOLUTION',
                (int) $persisted->getId(),
                null,
                $persisted->toArray(),
                $coordinatorId,
                $coordinatorName
            );

            return $persisted;
        });
    }

    /**
     * Edición de los campos mutables de una solución, con las guardas de
     * vigencia y la conmutación atómica de la predeterminada (`C2`, `QA 1`).
     *
     * @param array<string, mixed> $payload
     */
    public function updateStandardSolution(
        int $rootCauseId,
        int $solutionId,
        array $payload,
        ?int $coordinatorId = null,
        string $coordinatorName = 'Coordinación de Servicio'
    ): StandardSolution {
        return $this->transactional(function () use (
            $rootCauseId,
            $solutionId,
            $payload,
            $coordinatorId,
            $coordinatorName
        ): StandardSolution {
            $cause = $this->repository->findRootCauseForUpdate($rootCauseId);
            $current = $this->repository->findSolutionForUpdate($solutionId);

            if ($cause === null || $current === null || $current->getRootCauseId() !== $rootCauseId) {
                throw new DomainException(
                    'STANDARD_SOLUTION_NOT_FOUND: la solución estandarizada no existe en la causa indicada.',
                    404
                );
            }

            $this->assertSolutionImmutability($current, $payload);

            $targetIsActive = $this->booleanField($payload, 'is_active', $current->isActive());
            $targetIsDefault = $this->booleanField($payload, 'is_default', $current->isDefault());

            // Caso 1: baja lógica de una solución activa (C2).
            if ($current->isActive() && !$targetIsActive) {
                if ($cause->isActive() && $this->repository->countActiveSolutions($rootCauseId) <= 1) {
                    throw new DomainException(
                        'CANNOT_DEACTIVATE_LAST_ACTIVE_SOLUTION: la causa raíz activa no puede quedarse sin soluciones activas.',
                        409
                    );
                }
                if ($current->isDefault()) {
                    throw new DomainException(
                        'CANNOT_DEACTIVATE_DEFAULT_SOLUTION: la solución predeterminada debe relevarse antes de desactivarla.',
                        409
                    );
                }
                $targetIsDefault = false;
            }

            // Caso 2: reactivación de una solución dada de baja (QA 1). Reingresa
            // como no predeterminada salvo designación explícita.
            if (!$current->isActive() && $targetIsActive) {
                if ($targetIsDefault) {
                    $this->repository->clearActiveDefaultForCause($rootCauseId);
                } else {
                    $targetIsDefault = false;
                }
            }

            // Caso 3: conmutación o intento de desmarcar en vacío la predeterminada.
            if ($current->isActive() && $targetIsActive) {
                if ($current->isDefault() && !$targetIsDefault) {
                    throw new DomainException(
                        'CANNOT_UNSET_DEFAULT_WITHOUT_REPLACEMENT: la causa raíz necesita una solución predeterminada; '
                        . 'designe otra antes de retirar la marca.',
                        409
                    );
                }
                if (!$current->isDefault() && $targetIsDefault) {
                    $this->repository->clearActiveDefaultForCause($rootCauseId);
                }
            }

            $updated = $this->repository->updateStandardSolution(new StandardSolution(
                $current->getId(),
                $current->getRootCauseId(),
                $current->getCode(),
                array_key_exists('name', $payload) ? (string) $payload['name'] : $current->getName(),
                $targetIsDefault,
                $targetIsActive,
                $current->getCreatedAt(),
                $current->getUpdatedAt()
            ));

            $this->recordAudit(
                'UPDATE_STANDARD_SOLUTION',
                $solutionId,
                $current->toArray(),
                $updated->toArray(),
                $coordinatorId,
                $coordinatorName
            );

            return $updated;
        });
    }

    /**
     * Activa y marca como predeterminada una solución concreta de la causa,
     * desmarcando la anterior en la misma transacción (`EARS 01.3.3`).
     */
    private function designateDefaultSolution(
        RootCause $cause,
        int $solutionId,
        ?int $coordinatorId,
        string $coordinatorName
    ): void {
        $rootCauseId = (int) $cause->getId();
        $solution = $this->repository->findSolutionForUpdate($solutionId);

        if ($solution === null || $solution->getRootCauseId() !== $rootCauseId) {
            throw new DomainException(
                'STANDARD_SOLUTION_NOT_FOUND: la solución designada no pertenece a la causa raíz.',
                404
            );
        }

        $this->repository->clearActiveDefaultForCause($rootCauseId);

        if ($solution->isActive() && $solution->isDefault()) {
            return;
        }

        $designated = $this->repository->updateStandardSolution(new StandardSolution(
            $solution->getId(),
            $solution->getRootCauseId(),
            $solution->getCode(),
            $solution->getName(),
            true,
            true,
            $solution->getCreatedAt(),
            $solution->getUpdatedAt()
        ));

        // La designación muta una segunda fila del catálogo: se audita también,
        // o la reactivación de la causa no dejaría rastro de qué solución pasó a
        // ser la predeterminada (Art. III.3, QA 1).
        $this->recordAudit(
            'UPDATE_STANDARD_SOLUTION',
            $solutionId,
            $solution->toArray(),
            $designated->toArray(),
            $coordinatorId,
            $coordinatorName
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertCauseImmutability(RootCause $current, array $payload): void
    {
        if (array_key_exists('code', $payload)
            && strtoupper(trim((string) $payload['code'])) !== $current->getCode()
        ) {
            throw new InvalidArgumentException(
                'TAXONOMY_IMMUTABLE_FIELD_MODIFICATION: el código de la causa raíz no se puede modificar tras su creación.'
            );
        }

        if (array_key_exists('category', $payload)
            && $this->resolveCategory($payload['category']) !== $current->getCategory()
        ) {
            throw new InvalidArgumentException(
                'TAXONOMY_IMMUTABLE_FIELD_MODIFICATION: la categoría de la causa raíz no se puede modificar tras su creación.'
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertSolutionImmutability(StandardSolution $current, array $payload): void
    {
        if (array_key_exists('code', $payload)
            && strtoupper(trim((string) $payload['code'])) !== $current->getCode()
        ) {
            throw new InvalidArgumentException(
                'TAXONOMY_IMMUTABLE_FIELD_MODIFICATION: el código de la solución no se puede modificar tras su creación.'
            );
        }

        if (array_key_exists('root_cause_id', $payload)
            && (int) $payload['root_cause_id'] !== $current->getRootCauseId()
        ) {
            throw new InvalidArgumentException(
                'TAXONOMY_IMMUTABLE_FIELD_MODIFICATION: la solución no se puede reasignar a otra causa raíz.'
            );
        }
    }

    /**
     * Ejecuta la operación en una transacción propia; si ya hay una abierta, se
     * suma a ella sin confirmarla (la unidad de trabajo la cierra quien la abrió).
     *
     * @template T
     * @param callable(): T $operation
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

    /**
     * Convierte una violación de unicidad del motor en el conflicto del contrato.
     */
    private function translateUniqueViolation(PDOException $exception): DomainException
    {
        if ((string) $exception->getCode() === '23505') {
            return new DomainException(
                'DUPLICATE_TAXONOMY_CODE: ya existe una entrada del catálogo con ese código.',
                409,
                $exception
            );
        }

        return new DomainException(
            'TAXONOMY_PERSISTENCE_ERROR: no fue posible persistir la entrada del catálogo.',
            500,
            $exception
        );
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $new
     */
    private function recordAudit(
        string $action,
        int $entityId,
        ?array $previous,
        array $new,
        ?int $coordinatorId,
        string $coordinatorName
    ): void {
        $this->auditLog->log(new AuditEvent(
            null,
            self::AUDIT_ENTITY_TYPE,
            $entityId,
            $action,
            $coordinatorId,
            'COORDINATOR',
            trim($coordinatorName) !== '' ? trim($coordinatorName) : 'Coordinación de Servicio',
            $previous,
            $new
        ));
    }

    private function resolveCategory(mixed $value): IncidentCategory
    {
        if ($value instanceof IncidentCategory) {
            return $value;
        }

        if (is_string($value) && IncidentCategory::isValid($value)) {
            return IncidentCategory::fromString($value);
        }

        throw new InvalidArgumentException(
            'INVALID_ROOT_CAUSE_CATEGORY: la categoría indicada no existe en el catálogo funcional de averías.'
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $key, string $errorCode): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf(
                '%s: el campo «%s» es obligatorio.',
                $errorCode,
                $key
            ));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        $value = trim((string) $payload[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function booleanField(array $payload, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $payload)) {
            return $default;
        }

        $value = filter_var($payload[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($value === null) {
            throw new InvalidArgumentException(
                'INVALID_TAXONOMY_PAYLOAD: el campo «' . $key . '» debe ser booleano.'
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalPositiveInt(array $payload, string $key): ?int
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
            return null;
        }

        $value = filter_var($payload[$key], FILTER_VALIDATE_INT);

        if ($value === false || $value <= 0) {
            throw new InvalidArgumentException(
                'INVALID_TAXONOMY_PAYLOAD: el campo «' . $key . '» debe ser un identificador positivo.'
            );
        }

        return $value;
    }
}
