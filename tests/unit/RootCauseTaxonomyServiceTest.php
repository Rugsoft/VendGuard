<?php

declare(strict_types=1);

/**
 * RootCauseTaxonomyServiceTest · T-TAXONOMY-07
 *
 * Suite unitaria del orquestador transaccional del catálogo maestro del
 * Módulo 25. Certifica los invariantes que la Constitución y la especificación
 * exigen antes de que una fila llegue al motor:
 *
 *   1. **Alta atómica (`EARS 01.1`, `QA 1`):** la causa nace con una solución
 *      activa y predeterminada; si la solución no supera las invariantes, no se
 *      confirma nada ni se escribe auditoría.
 *   2. **Inmutabilidad (`C4`, `QA 1`):** `code` y `category` de la causa y
 *      `code` y `root_cause_id` de la solución se rechazan con `422`.
 *   3. **Vigencia segura (`C2`, `QA 1`):** última solución activa, predeterminada
 *      vigente y predeterminada sin relevo se bloquean con `409`; una causa
 *      huérfana no se reactiva sin designar una solución.
 *   4. **Conmutación atómica (`D1`):** nunca hay dos predeterminadas activas.
 *   5. **Auditoría antes/después (`Art. III.3`):** cada mutación deja su evento
 *      bajo `ROOT_CAUSE_TAXONOMY`, con el estado previo y el resultante.
 *
 * El repositorio y el registro de auditoría son dobles en memoria: la suite no
 * toca PostgreSQL ni la red. La transacción se ejecuta sobre un PDO SQLite en
 * memoria, que sólo aporta `BEGIN`/`COMMIT`/`ROLLBACK` reales para comprobar
 * que el servicio cierra su unidad de trabajo incluso cuando falla.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\RootCauseTaxonomyService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\RootCause;
use VendGuard\Core\Domain\Model\StandardSolution;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\RootCauseRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;

echo "======================================================================\n";
echo " VENDGUARD · Suite unitaria · RootCauseTaxonomyServiceTest (T-TAXONOMY-07)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

/**
 * Ejecuta una operación que debe fallar y comprueba clase y fragmento de mensaje.
 */
$assertThrows = function (
    string $caseTitle,
    callable $operation,
    string $expectedClass,
    string $expectedFragment
) use ($assert): void {
    try {
        $operation();
        $assert($caseTitle, false, 'No se lanzó ninguna excepción.');
    } catch (Throwable $exception) {
        $matches = $exception instanceof $expectedClass
            && str_contains($exception->getMessage(), $expectedFragment);
        $assert(
            $caseTitle,
            $matches,
            get_class($exception) . ': ' . $exception->getMessage()
        );
    }
};

/**
 * Repositorio en memoria que respeta la forma del contrato del catálogo.
 */
final class InMemoryRootCauseRepository implements RootCauseRepositoryInterface
{
    /** @var array<int, RootCause> */
    public array $causes = [];

    /** @var array<int, StandardSolution> */
    public array $solutions = [];

    public bool $failSolutionInsert = false;

    private int $causeSequence = 0;

    private int $solutionSequence = 0;

    public function findActiveCatalogWithSolutions(): array
    {
        $catalog = [];

        foreach ($this->causes as $cause) {
            if (!$cause->isActive()) {
                continue;
            }

            $solutions = array_values(array_filter(
                $this->solutions,
                static fn (StandardSolution $solution): bool =>
                    $solution->getRootCauseId() === $cause->getId() && $solution->isActive()
            ));

            usort($solutions, static function (StandardSolution $a, StandardSolution $b): int {
                if ($a->isDefault() !== $b->isDefault()) {
                    return $a->isDefault() ? -1 : 1;
                }

                return $a->getCode() <=> $b->getCode();
            });

            $catalog[] = $cause->withSolutions($solutions);
        }

        usort($catalog, static fn (RootCause $a, RootCause $b): int => $a->getCode() <=> $b->getCode());

        return $catalog;
    }

    public function findFullCatalogForAdmin(?IncidentCategory $category = null, ?bool $isActive = null): array
    {
        $rows = [];

        foreach ($this->causes as $cause) {
            if ($category !== null && $cause->getCategory() !== $category) {
                continue;
            }
            if ($isActive !== null && $cause->isActive() !== $isActive) {
                continue;
            }

            $solutions = [];
            foreach ($this->solutions as $solution) {
                if ($solution->getRootCauseId() !== $cause->getId()) {
                    continue;
                }
                $solutions[] = ['solution' => $solution, 'incidents_count' => 0];
            }

            $rows[] = [
                'root_cause' => $cause,
                'incidents_count' => 0,
                'solutions' => $solutions,
            ];
        }

        return $rows;
    }

    public function findRootCauseById(int $id): ?RootCause
    {
        return $this->causes[$id] ?? null;
    }

    public function findSolutionById(int $id): ?StandardSolution
    {
        return $this->solutions[$id] ?? null;
    }

    public function findRootCauseForUpdate(int $id): ?RootCause
    {
        return $this->findRootCauseById($id);
    }

    public function findSolutionForUpdate(int $id): ?StandardSolution
    {
        return $this->findSolutionById($id);
    }

    public function existsRootCauseCode(string $code): bool
    {
        $normalized = strtoupper(trim($code));

        foreach ($this->causes as $cause) {
            if ($cause->getCode() === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function existsSolutionCode(string $code): bool
    {
        $normalized = strtoupper(trim($code));

        foreach ($this->solutions as $solution) {
            if ($solution->getCode() === $normalized) {
                return true;
            }
        }

        return false;
    }

    public function hasActiveDefaultSolution(int $rootCauseId): bool
    {
        foreach ($this->solutions as $solution) {
            if ($solution->getRootCauseId() === $rootCauseId
                && $solution->isActive()
                && $solution->isDefault()
            ) {
                return true;
            }
        }

        return false;
    }

    public function countActiveSolutions(int $rootCauseId): int
    {
        $count = 0;

        foreach ($this->solutions as $solution) {
            if ($solution->getRootCauseId() === $rootCauseId && $solution->isActive()) {
                $count++;
            }
        }

        return $count;
    }

    public function clearActiveDefaultForCause(int $rootCauseId): void
    {
        foreach ($this->solutions as $id => $solution) {
            if ($solution->getRootCauseId() !== $rootCauseId
                || !$solution->isActive()
                || !$solution->isDefault()
            ) {
                continue;
            }

            $this->solutions[$id] = new StandardSolution(
                $solution->getId(),
                $solution->getRootCauseId(),
                $solution->getCode(),
                $solution->getName(),
                false,
                true,
                $solution->getCreatedAt(),
                $solution->getUpdatedAt()
            );
        }
    }

    public function insertRootCause(RootCause $rootCause): RootCause
    {
        $this->causeSequence++;
        $persisted = $rootCause->withId($this->causeSequence);
        $this->causes[$this->causeSequence] = $persisted;

        return $persisted;
    }

    public function insertStandardSolution(StandardSolution $standardSolution): StandardSolution
    {
        if ($this->failSolutionInsert) {
            throw new RuntimeException('Fallo simulado al insertar la solución.');
        }

        $this->solutionSequence++;
        $persisted = $standardSolution->withId($this->solutionSequence);
        $this->solutions[$this->solutionSequence] = $persisted;

        return $persisted;
    }

    public function updateRootCause(RootCause $rootCause): RootCause
    {
        $this->causes[(int) $rootCause->getId()] = $rootCause;

        return $rootCause;
    }

    public function updateStandardSolution(StandardSolution $standardSolution): StandardSolution
    {
        $this->solutions[(int) $standardSolution->getId()] = $standardSolution;

        return $standardSolution;
    }
}

/**
 * Registro de auditoría en memoria que conserva el orden de escritura.
 */
final class InMemoryTaxonomyAuditLog implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return array_slice($this->events, $offset, $limit);
    }

    public function countEvents(array $filters = []): int
    {
        return count($this->events);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (AuditEvent $event): bool =>
                $event->getEntityType() === $entityType && $event->getEntityId() === $entityId
        ));
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_map(static fn (AuditEvent $event): string => $event->getAction(), $this->events);
    }
}

/**
 * @return array{0: InMemoryRootCauseRepository, 1: InMemoryTaxonomyAuditLog, 2: PDO, 3: RootCauseTaxonomyService}
 */
function buildTaxonomyFixture(bool $failSolutionInsert = false): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $repository = new InMemoryRootCauseRepository();
    $repository->failSolutionInsert = $failSolutionInsert;

    $audit = new InMemoryTaxonomyAuditLog();

    return [$repository, $audit, $pdo, new RootCauseTaxonomyService($repository, $audit, $pdo)];
}

/**
 * @return array<string, mixed>
 */
function taxonomyCausePayload(
    string $code = 'RC-PAY-01',
    string $solutionCode = 'SOL-PAY-01A',
    string $category = 'PAYMENT_SYSTEM'
): array {
    return [
        'code' => $code,
        'category' => $category,
        'name' => 'Moneda doblada en el selector multimoneda',
        'description' => 'Cuerpo extraño que bloquea la tolva de admisión.',
        'initial_solution' => [
            'code' => $solutionCode,
            'name' => 'Apertura del selector, retirada del objeto y prueba de admisión',
        ],
    ];
}

// ---------------------------------------------------------------------------
// 1. Alta atómica de causa + solución predeterminada inicial (QA 1)
// ---------------------------------------------------------------------------

echo "· Alta atómica de causa raíz con solución predeterminada inicial\n";

[$repository, $audit, $pdo, $service] = buildTaxonomyFixture();

$created = $service->createRootCauseWithInitialSolution(
    taxonomyCausePayload(),
    7,
    'Ana Coordinadora'
);

$assert(
    'La causa recibe identificador y viaja con su solución inicial',
    $created->getId() === 1 && count($created->getSolutions()) === 1,
    'El agregado devuelto no incluye la solución inicial persistida.'
);

$initial = $created->getSolutions()[0] ?? null;
$assert(
    'La solución inicial nace activa y predeterminada',
    $initial instanceof StandardSolution
        && $initial->getRootCauseId() === 1
        && $initial->isActive()
        && $initial->isDefault()
);

$assert(
    'La transacción se confirma al terminar el alta',
    $pdo->inTransaction() === false
);

$assert(
    'Se registra un único evento de auditoría del alta',
    count($audit->events) === 1
        && $audit->events[0]->getEntityType() === 'ROOT_CAUSE_TAXONOMY'
        && $audit->events[0]->getEntityId() === 1
        && $audit->events[0]->getAction() === 'CREATE_ROOT_CAUSE'
        && $audit->events[0]->getPreviousState() === null
        && $audit->events[0]->getUserRole() === 'COORDINATOR'
        && $audit->events[0]->getUserId() === 7,
    'El evento no describe el alta completa bajo ROOT_CAUSE_TAXONOMY.'
);

$newState = $audit->events[0]->getNewState();
$assert(
    'El estado nuevo de la auditoría contiene causa y solución inicial',
    isset($newState['root_cause']['code'], $newState['initial_solution']['code'])
        && $newState['root_cause']['code'] === 'RC-PAY-01'
        && $newState['initial_solution']['code'] === 'SOL-PAY-01A'
        && $newState['initial_solution']['is_default'] === true
);

// --- Alta atómica: un fallo en la solución no confirma la causa ------------

[$repository, $audit, $pdo, $service] = buildTaxonomyFixture(true);

try {
    $service->createRootCauseWithInitialSolution(taxonomyCausePayload());
    $assert('Un fallo al insertar la solución aborta el alta', false, 'No se lanzó la excepción simulada.');
} catch (RuntimeException $exception) {
    $assert(
        'Un fallo al insertar la solución aborta el alta',
        str_contains($exception->getMessage(), 'Fallo simulado')
    );
}

$assert(
    'Tras el fallo no queda solución persistida ni evento de auditoría',
    count($repository->solutions) === 0 && count($audit->events) === 0
);
$assert(
    'Tras el fallo la transacción queda cerrada',
    $pdo->inTransaction() === false
);

// --- Validaciones de entrada ----------------------------------------------

[$repository, $audit, $pdo, $service] = buildTaxonomyFixture();

$assertThrows(
    'La ausencia de initial_solution se rechaza con el código del contrato',
    fn () => $service->createRootCauseWithInitialSolution(['code' => 'RC-PAY-02', 'category' => 'PAYMENT_SYSTEM', 'name' => 'Billete atrapado']),
    InvalidArgumentException::class,
    'INITIAL_DEFAULT_SOLUTION_REQUIRED'
);

$invalidSolution = taxonomyCausePayload('RC-PAY-02', 'SOL-PAY-02A');
$invalidSolution['initial_solution']['name'] = 'ABC';
$assertThrows(
    'Una solución inicial demasiado corta deshace el alta completa',
    fn () => $service->createRootCauseWithInitialSolution($invalidSolution),
    InvalidArgumentException::class,
    'entre 5 y 140'
);
$assert(
    'La causa inválida no deja solución ni auditoría',
    count($repository->solutions) === 0 && count($audit->events) === 0
);

$assertThrows(
    'Una categoría fuera del catálogo funcional se rechaza',
    fn () => $service->createRootCauseWithInitialSolution(taxonomyCausePayload('RC-PAY-03', 'SOL-PAY-03A', 'PAYMENT')),
    InvalidArgumentException::class,
    'INVALID_ROOT_CAUSE_CATEGORY'
);

$assertThrows(
    'Un código de causa fuera de la forma canónica se rechaza',
    fn () => $service->createRootCauseWithInitialSolution(taxonomyCausePayload('rc', 'SOL-PAY-03B')),
    InvalidArgumentException::class,
    'formato'
);

// --- Duplicidad de códigos (409) ------------------------------------------

[$repository, $audit, $pdo, $service] = buildTaxonomyFixture();
$service->createRootCauseWithInitialSolution(taxonomyCausePayload(), 7, 'Ana Coordinadora');

$assertThrows(
    'Un código de causa duplicado se rechaza con 409',
    fn () => $service->createRootCauseWithInitialSolution(taxonomyCausePayload('RC-PAY-01', 'SOL-PAY-01B')),
    DomainException::class,
    'DUPLICATE_TAXONOMY_CODE'
);

$assertThrows(
    'Un código de solución duplicado se rechaza con 409',
    fn () => $service->createRootCauseWithInitialSolution(taxonomyCausePayload('RC-PAY-02', 'SOL-PAY-01A')),
    DomainException::class,
    'DUPLICATE_TAXONOMY_CODE'
);

$assert(
    'Los intentos duplicados no añaden causas ni auditoría',
    count($repository->causes) === 1 && count($audit->events) === 1
);

// ---------------------------------------------------------------------------
// 2. Edición de la causa: inmutabilidad, vigencia y reactivación (C4, QA 1)
// ---------------------------------------------------------------------------

echo "\n· Edición de causa raíz, inmutabilidad y reactivación segura\n";

[$repository, $audit, $pdo, $service] = buildTaxonomyFixture();
$service->createRootCauseWithInitialSolution(taxonomyCausePayload(), 7, 'Ana Coordinadora');

$assertThrows(
    'Cambiar el código de la causa se rechaza con 422',
    fn () => $service->updateRootCause(1, ['code' => 'RC-PAY-99']),
    InvalidArgumentException::class,
    'TAXONOMY_IMMUTABLE_FIELD_MODIFICATION'
);

$assertThrows(
    'Cambiar la categoría de la causa se rechaza con 422',
    fn () => $service->updateRootCause(1, ['category' => 'PRODUCT_JAM']),
    InvalidArgumentException::class,
    'TAXONOMY_IMMUTABLE_FIELD_MODIFICATION'
);

$assert(
    'Los rechazos de inmutabilidad no escriben auditoría',
    count($audit->events) === 1 && $pdo->inTransaction() === false
);

$updated = $service->updateRootCause(1, [
    'name' => 'Moneda doblada o ficha en el selector multimoneda',
    'description' => 'Descripción actualizada del coordinador.',
], 7, 'Ana Coordinadora');

$assert(
    'La edición persiste nombre y descripción sin tocar el código',
    $updated->getName() === 'Moneda doblada o ficha en el selector multimoneda'
        && $updated->getDescription() === 'Descripción actualizada del coordinador.'
        && $updated->getCode() === 'RC-PAY-01'
        && $updated->getCategory() === IncidentCategory::PAYMENT_SYSTEM
);

$lastEvent = $audit->events[count($audit->events) - 1];
$assert(
    'La edición audita antes/después bajo UPDATE_ROOT_CAUSE',
    $lastEvent->getAction() === 'UPDATE_ROOT_CAUSE'
        && $lastEvent->getPreviousState()['name'] === 'Moneda doblada en el selector multimoneda'
        && $lastEvent->getNewState()['name'] === 'Moneda doblada o ficha en el selector multimoneda'
);

$deactivated = $service->updateRootCause(1, ['is_active' => false], 7, 'Ana Coordinadora');
$assert(
    'Dar de baja la causa se audita como DEACTIVATE_ROOT_CAUSE',
    $deactivated->isActive() === false
        && $audit->events[count($audit->events) - 1]->getAction() === 'DEACTIVATE_ROOT_CAUSE'
);

$reactivated = $service->updateRootCause(1, ['is_active' => true, 'name' => 'Moneda doblada en el selector'], 7, 'Ana Coordinadora');
$assert(
    'Reactivar una causa con predeterminada vigente se audita como REACTIVATE_ROOT_CAUSE',
    $reactivated->isActive() === true
        && $audit->events[count($audit->events) - 1]->getAction() === 'REACTIVATE_ROOT_CAUSE'
);

$assertThrows(
    'Editar una causa inexistente devuelve 404',
    fn () => $service->updateRootCause(999, ['name' => 'Causa fantasma inexistente']),
    DomainException::class,
    'ROOT_CAUSE_NOT_FOUND'
);

$assertThrows(
    'Un booleano de vigencia ilegible se rechaza con 422',
    fn () => $service->updateRootCause(1, ['is_active' => 'quizá']),
    InvalidArgumentException::class,
    'INVALID_TAXONOMY_PAYLOAD'
);

// --- Causa huérfana: sin predeterminada activa ----------------------------

[$repository, $audit, $pdo, $service] = buildTaxonomyFixture();
$service->createRootCauseWithInitialSolution(taxonomyCausePayload(), 7, 'Ana Coordinadora');

$cause = $repository->causes[1];
$solution = $repository->solutions[1];
$repository->updateRootCause(new RootCause(
    $cause->getId(),
    $cause->getCode(),
    $cause->getCategory(),
    $cause->getName(),
    $cause->getDescription(),
    false
));
$repository->updateStandardSolution(new StandardSolution(
    $solution->getId(),
    $solution->getRootCauseId(),
    $solution->getCode(),
    $solution->getName(),
    false,
    false
));

$assertThrows(
    'Reactivar una causa huérfana se bloquea con 409',
    fn () => $service->updateRootCause(1, ['is_active' => true]),
    DomainException::class,
    'CANNOT_REACTIVATE_ORPHAN_ROOT_CAUSE'
);

$recovered = $service->updateRootCause(1, ['is_active' => true, 'default_solution_id' => 1], 7, 'Ana Coordinadora');
$reactivatedSolution = $repository->solutions[1];
$assert(
    'La reactivación con default_solution_id activa la solución y la marca predeterminada',
    $recovered->isActive() === true
        && $reactivatedSolution->isActive() === true
        && $reactivatedSolution->isDefault() === true
        && $repository->hasActiveDefaultSolution(1)
);

// --- Predeterminada de otra causa -----------------------------------------

[$repository, $audit, $pdo, $service] = buildTaxonomyFixture();
$service->createRootCauseWithInitialSolution(taxonomyCausePayload('RC-PAY-01', 'SOL-PAY-01A'), 7, 'Ana Coordinadora');
$service->createRootCauseWithInitialSolution(taxonomyCausePayload('RC-PAY-02', 'SOL-PAY-02A'), 7, 'Ana Coordinadora');
$service->updateRootCause(2, ['is_active' => false], 7, 'Ana Coordinadora');

$assertThrows(
    'Designar como predeterminada una solución de otra causa devuelve 404',
    fn () => $service->updateRootCause(2, ['is_active' => true, 'default_solution_id' => 1]),
    DomainException::class,
    'STANDARD_SOLUTION_NOT_FOUND'
);

// ---------------------------------------------------------------------------
// 3. Alta de soluciones estandarizadas (EARS 01.2, D1)
// ---------------------------------------------------------------------------

echo "\n· Alta de soluciones estandarizadas y conmutación de la predeterminada\n";

[$repository, $audit, $pdo, $service] = buildTaxonomyFixture();
$service->createRootCauseWithInitialSolution(taxonomyCausePayload(), 7, 'Ana Coordinadora');

$second = $service->createStandardSolution(1, [
    'code' => 'SOL-PAY-01B',
    'name' => 'Sustitución del selector multimoneda por unidad revisada',
], 7, 'Ana Coordinadora');

$assert(
    'La nueva solución nace activa y no predeterminada',
    $second->getId() === 2 && $second->isActive() && !$second->isDefault()
);

$third = $service->createStandardSolution(1, [
    'code' => 'SOL-PAY-01C',
    'name' => 'Actualización de firmware de discriminación MDB',
    'is_default' => true,
], 7, 'Ana Coordinadora');

$assert(
    'Marcar una nueva solución como predeterminada desmarca la anterior',
    $third->isDefault() === true
        && $repository->solutions[1]->isDefault() === false
        && $repository->hasActiveDefaultSolution(1)
);

$defaultCount = count(array_filter(
    $repository->solutions,
    static fn (StandardSolution $solution): bool => $solution->isDefault() && $solution->isActive()
));
$assert('Nunca hay dos predeterminadas activas en la misma causa', $defaultCount === 1);

$lastEvent = $audit->events[count($audit->events) - 1];
$assert(
    'El alta de solución audita su estado nuevo bajo CREATE_STANDARD_SOLUTION',
    $lastEvent->getAction() === 'CREATE_STANDARD_SOLUTION'
        && $lastEvent->getEntityId() === 3
        && $lastEvent->getPreviousState() === null
        && $lastEvent->getNewState()['is_default'] === true
);

$assertThrows(
    'Un código de solución duplicado se rechaza con 409',
    fn () => $service->createStandardSolution(1, ['code' => 'SOL-PAY-01A', 'name' => 'Solución repetida del catálogo']),
    DomainException::class,
    'DUPLICATE_TAXONOMY_CODE'
);

$assertThrows(
    'Añadir una solución a una causa inexistente devuelve 404',
    fn () => $service->createStandardSolution(99, ['code' => 'SOL-PAY-99A', 'name' => 'Solución de causa inexistente']),
    DomainException::class,
    'ROOT_CAUSE_NOT_FOUND'
);

// ---------------------------------------------------------------------------
// 4. Edición de soluciones: inmutabilidad, vigencia y relevo (C2, QA 1)
// ---------------------------------------------------------------------------

echo "\n· Edición de soluciones: inmutabilidad, vigencia y relevo de la predeterminada\n";

[$repository, $audit, $pdo, $service] = buildTaxonomyFixture();
$service->createRootCauseWithInitialSolution(taxonomyCausePayload(), 7, 'Ana Coordinadora');
$service->createStandardSolution(1, [
    'code' => 'SOL-PAY-01B',
    'name' => 'Sustitución del selector multimoneda por unidad revisada',
], 7, 'Ana Coordinadora');

$assertThrows(
    'Cambiar el código de la solución se rechaza con 422',
    fn () => $service->updateStandardSolution(1, 1, ['code' => 'SOL-PAY-01Z']),
    InvalidArgumentException::class,
    'TAXONOMY_IMMUTABLE_FIELD_MODIFICATION'
);

$assertThrows(
    'Reasignar la solución a otra causa se rechaza con 422',
    fn () => $service->updateStandardSolution(1, 1, ['root_cause_id' => 2]),
    InvalidArgumentException::class,
    'TAXONOMY_IMMUTABLE_FIELD_MODIFICATION'
);

$assertThrows(
    'Desmarcar la predeterminada sin relevo se bloquea con 409',
    fn () => $service->updateStandardSolution(1, 1, ['is_default' => false]),
    DomainException::class,
    'CANNOT_UNSET_DEFAULT_WITHOUT_REPLACEMENT'
);

$assertThrows(
    'Desactivar la predeterminada vigente se bloquea con 409',
    fn () => $service->updateStandardSolution(1, 1, ['is_active' => false]),
    DomainException::class,
    'CANNOT_DEACTIVATE_DEFAULT_SOLUTION'
);

$renamed = $service->updateStandardSolution(1, 2, [
    'name' => 'Sustitución del selector y calibración de admisión',
], 7, 'Ana Coordinadora');
$assert(
    'La edición persiste el nombre y conserva la vigencia',
    $renamed->getName() === 'Sustitución del selector y calibración de admisión'
        && $renamed->isActive()
        && !$renamed->isDefault()
);

$switched = $service->updateStandardSolution(1, 2, ['is_default' => true], 7, 'Ana Coordinadora');
$assert(
    'Conmutar la predeterminada desmarca la anterior en la misma transacción',
    $switched->isDefault() === true
        && $repository->solutions[1]->isDefault() === false
        && $repository->hasActiveDefaultSolution(1)
);

$lastEvent = $audit->events[count($audit->events) - 1];
$assert(
    'La conmutación audita antes/después bajo UPDATE_STANDARD_SOLUTION',
    $lastEvent->getAction() === 'UPDATE_STANDARD_SOLUTION'
        && $lastEvent->getEntityId() === 2
        && $lastEvent->getPreviousState()['is_default'] === false
        && $lastEvent->getNewState()['is_default'] === true
);

// --- Última solución activa de una causa activa (C2) ----------------------

[$repository, $audit, $pdo, $service] = buildTaxonomyFixture();
$service->createRootCauseWithInitialSolution(taxonomyCausePayload(), 7, 'Ana Coordinadora');

$assertThrows(
    'Desactivar la última solución activa de una causa activa se bloquea con 409',
    fn () => $service->updateStandardSolution(1, 1, ['is_active' => false]),
    DomainException::class,
    'CANNOT_DEACTIVATE_LAST_ACTIVE_SOLUTION'
);

// --- Relevo y reactivación de una solución no predeterminada --------------

$service->createStandardSolution(1, [
    'code' => 'SOL-PAY-01B',
    'name' => 'Sustitución del selector multimoneda por unidad revisada',
], 7, 'Ana Coordinadora');

$deactivated = $service->updateStandardSolution(1, 2, ['is_active' => false], 7, 'Ana Coordinadora');
$assert(
    'Desactivar una solución no predeterminada con relevo activo se permite',
    $deactivated->isActive() === false && $deactivated->isDefault() === false
);

$reactivated = $service->updateStandardSolution(1, 2, ['is_active' => true], 7, 'Ana Coordinadora');
$assert(
    'Una solución reactivada reingresa como no predeterminada',
    $reactivated->isActive() === true && $reactivated->isDefault() === false
);

$reactivatedAsDefault = $service->updateStandardSolution(1, 2, ['is_default' => true], 7, 'Ana Coordinadora');
$assert(
    'La designación explícita al reactivar conmuta la predeterminada',
    $reactivatedAsDefault->isDefault() === true
        && $repository->solutions[1]->isDefault() === false
        && $repository->hasActiveDefaultSolution(1)
);

$assertThrows(
    'Desactivar la predeterminada conservando su marca también se bloquea con 409',
    fn () => $service->updateStandardSolution(1, 2, ['is_active' => false, 'is_default' => true]),
    DomainException::class,
    'CANNOT_DEACTIVATE_DEFAULT_SOLUTION'
);

$assertThrows(
    'Editar una solución de otra causa devuelve 404',
    fn () => $service->updateStandardSolution(2, 1, ['name' => 'Solución de otra rama del catálogo']),
    DomainException::class,
    'STANDARD_SOLUTION_NOT_FOUND'
);

// ---------------------------------------------------------------------------
// Resumen
// ---------------------------------------------------------------------------

$total = $assertions;
$passed = $assertions - $failures;

echo "\n----------------------------------------------------------------------\n";
printf(" Resultado: %d/%d comprobaciones en verde (%d fallos)\n", $passed, $total, $failures);
echo "----------------------------------------------------------------------\n";

exit($failures === 0 ? 0 : 1);
