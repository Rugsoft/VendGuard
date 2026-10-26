<?php

declare(strict_types=1);

/**
 * TechnicianRouteBatchReadsTest (Módulo 12 · T-HYBRID-27 · RNF-01)
 *
 * Certificación de la cura por LOTES de la bandeja de ruta del técnico
 * (`GET /api/technician/my-route`). Contra el motor remoto, la ruta pagaba **tres lecturas por
 * parada** —máquina, sede y ajustes preventivos de la puerta sanitaria— además del recuento de
 * conversaciones, y medía 1.739,3 ms de mediana con 13 paradas: el presupuesto de RNF-01 son
 * 250 ms. Las tres se resuelven ahora una sola vez para la ruta completa.
 *
 * Condición "Hecho cuando" cubierta en servidor:
 * - **Una sola lectura por lote, cero por parada:** con un repositorio que declara la capacidad
 *   (`MachineBatchRepositoryInterface`, `LocationBatchRepositoryInterface`,
 *   `PreventiveSettingsBatchRepositoryInterface`), el controlador pide las máquinas, las sedes y
 *   los ajustes de toda la ruta en UNA llamada cada uno, deduplicando identificadores; las
 *   lecturas individuales quedan en cero.
 * - **El contrato publicado no cambia:** el payload de la parada —código y modelo de máquina,
 *   planta/ala, sede, dirección, teléfono y coordenadas— es idéntico al que se sirve cuando el
 *   repositorio sólo declara el contrato principal (camino de reserva, que es el de los dobles de
 *   prueba del resto de la batería).
 * - **La regla sanitaria es una sola:** `sanitary_resolution_required` sale por parada del MISMO
 *   predicado puro, leído por lote o de una en una.
 * - **Los repositorios reales declaran la capacidad:** sin eso, el camino de lote no correría
 *   nunca y el N+1 volvería en silencio. Se certifica por reflexión, sin abrir conexión.
 * - **La segregación de interfaces se mantiene:** el método de lote NO se añadió a las interfaces
 *   principales, así que ningún consumidor de máquina/sede/ajustes de una en una se ve obligado a
 *   implementarlo (misma política que `IncidentSiteResponseRepositoryInterface`).
 *
 * Dogma Vanilla: PHP 8.2 puro con dobles en memoria, sin librerías externas y sin base de datos.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Model\SparePartRequest;
use VendGuard\Core\Domain\Model\SparePartRequestStatus;
use VendGuard\Core\Domain\Model\VanStockItem;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationBatchRepositoryInterface;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineBatchRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsBatchRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRequestBatchRepositoryInterface;
use VendGuard\Core\Domain\Repository\SparePartRequestRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianRouteBoardRepositoryInterface;
use VendGuard\Core\Domain\Repository\VanStockRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoSparePartRequestRepository;
use VendGuard\Presentation\Controller\TechnicianController;
use VendGuard\Presentation\Http\Request;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - Lecturas por lote de la ruta (T-HYBRID-27)\n";
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
 * Doble del repositorio de expedientes que SÍ declara el tablero de ruta.
 *
 * El contrato principal se delega en el doble en memoria (`$inner`) y la lectura del tablero se
 * sirve de un fixture explícito: así el camino del tablero se certifica sin tocar la base de datos
 * y sin duplicar el juego completo de métodos del contrato.
 */
final class RouteBoardStubIncidentRepo implements IncidentRepositoryInterface, TechnicianRouteBoardRepositoryInterface
{
    /** @var list<array{technician: int, statuses: list<string>}> */
    public array $boardCalls = [];

    /**
     * @param IncidentRepositoryInterface $inner Doble del contrato principal.
     * @param list<array<string, mixed>> $board Fixture del tablero, ya en el orden de la ruta.
     */
    public function __construct(private IncidentRepositoryInterface $inner, private array $board)
    {
    }

    public function findRouteBoard(int $technicianId, array $statuses = []): array
    {
        $this->boardCalls[] = ['technician' => $technicianId, 'statuses' => array_values($statuses)];

        return $this->board;
    }

    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return $this->inner->findAssignedToTechnician($technicianId, $statuses); }
    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { return $this->inner->create($incident, $userId, $initialNote); }
    public function findById(int $id): ?Incident { return $this->inner->findById($id); }
    public function findByTicketCode(string $ticketCode): ?Incident { return $this->inner->findByTicketCode($ticketCode); }
    public function findActiveByMachineId(int $machineId): ?Incident { return $this->inner->findActiveByMachineId($machineId); }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return $this->inner->findActiveOrResolvedByMachineId($machineId); }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return $this->inner->findAllByLocation($locationId, $activeOnly); }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return $this->inner->findAllByMachineId($machineId, $excludeStatuses); }
    public function findAll(array $filters = []): array { return $this->inner->findAll($filters); }
    public function findEnrichedDetailById(int|string $identifier): ?array { return $this->inner->findEnrichedDetailById($identifier); }
    public function update(Incident $incident): bool { return $this->inner->update($incident); }
    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool { return $this->inner->clearNoAccessBlock($machineId, $ticketCode); }
    public function blockForNoAccess(int $machineId, string $ticketCode): bool { return $this->inner->blockForNoAccess($machineId, $ticketCode); }
    public function softDelete(int $id): bool { return $this->inner->softDelete($id); }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return $this->inner->insertHistory($incidentId, $userId, $fromStatus, $toStatus, $actionNote); }
    public function getHistory(int $incidentId): array { return $this->inner->getHistory($incidentId); }
    public function addComment(IncidentComment $comment): IncidentComment { return $this->inner->addComment($comment); }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return $this->inner->getComments($incidentId, $includeInternal); }
    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return $this->inner->getCommentsPaged($incidentId, $includeInternal, $limit, $beforeId); }
    public function countCommentsForIncidents(array $incidentIds, bool $includeInternal): array { return $this->inner->countCommentsForIncidents($incidentIds, $includeInternal); }
    public function countComments(int $incidentId, bool $includeInternal): int { return $this->inner->countComments($incidentId, $includeInternal); }
    public function countReopenEvents(int $incidentId): int { return $this->inner->countReopenEvents($incidentId); }
    public function markAsChronic(int $incidentId): bool { return $this->inner->markAsChronic($incidentId); }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident { return $this->inner->assign($incidentId, $technicianId, $coordinatorId, $urgencyOverride, $urgencyReason); }
    public function reopen(int $incidentId, string $reasonText): Incident { return $this->inner->reopen($incidentId, $reasonText); }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { return $this->inner->cancel($incidentId, $cancellationReason, $coordinatorId); }
    public function startIntervention(int $incidentId, int $technicianId): Incident { return $this->inner->startIntervention($incidentId, $technicianId); }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { return $this->inner->pauseIntervention($incidentId, $technicianId, $pendingPartsReason); }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { return $this->inner->resolve($incidentId, $technicianId, $diagnosis, $action); }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return $this->inner->autoCloseResolvedIncidents($hours); }
    public function recordPauseEvent(int $incidentId, int $userId, IncidentStatus $fromStatus, \VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory $category, string $reasonText, \DateTimeImmutable $pausedAt): void { $this->inner->recordPauseEvent($incidentId, $userId, $fromStatus, $category, $reasonText, $pausedAt); }
    public function recordResumeEvent(int $incidentId, ?int $userId, IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, \DateTimeImmutable $resumedAt): void { $this->inner->recordResumeEvent($incidentId, $userId, $targetStatus, $pauseDurationSeconds, $note, $resumedAt); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { return $this->inner->getPendingInfoIncidentsOlderThanHours($hours); }
}

// =====================================================================
// GRUPO 0: los repositorios reales declaran la capacidad, sin ensanchar
//          las interfaces principales (segregación de interfaces)
// =====================================================================

echo "--- Grupo 0: contratos de lote y segregación de interfaces ---\n";

$assert(
    '0.1 El repositorio real de máquinas declara la lectura por lote',
    (new ReflectionClass(PdoMachineRepository::class))->implementsInterface(MachineBatchRepositoryInterface::class),
    'Sin la capacidad, «Mi Ruta» volvería a leer máquina a máquina sin que nada lo delatara.'
);

$assert(
    '0.2 El repositorio real de sedes declara la lectura por lote',
    (new ReflectionClass(PdoLocationRepository::class))->implementsInterface(LocationBatchRepositoryInterface::class)
);

$assert(
    '0.3 El repositorio real de ajustes preventivos declara la lectura por lote',
    (new ReflectionClass(PdoPreventiveSettingsRepository::class))->implementsInterface(PreventiveSettingsBatchRepositoryInterface::class)
);

$assert(
    '0.4 El método de lote NO se añadió a las interfaces principales (ningún doble se ve forzado)',
    !method_exists(MachineRepositoryInterface::class, 'findManyByIds')
        && !method_exists(LocationRepositoryInterface::class, 'findManyByIds')
        && !method_exists(PreventiveSettingsRepositoryInterface::class, 'getMachineSettingsForMany'),
    'La capacidad de lote vive en contratos propios, como IncidentSiteResponseRepositoryInterface.'
);

$assert(
    '0.5 El repositorio real de expedientes declara el tablero de ruta (una sola consulta)',
    (new ReflectionClass(PdoIncidentRepository::class))->implementsInterface(TechnicianRouteBoardRepositoryInterface::class),
    'Sin el tablero, la bandeja no puede bajar de las cinco lecturas fijas y el presupuesto no cabe.'
);

$assert(
    '0.6 El tablero tampoco se añadió a la interfaz principal de expedientes',
    !method_exists(IncidentRepositoryInterface::class, 'findRouteBoard'),
    'La bandeja móvil no puede forzar a los demás consumidores del repositorio a implementarla.'
);

// =====================================================================
// Fixtures compartidas por los dos caminos
// =====================================================================

$stopOne = new Incident(
    id: 601,
    ticketCode: 'INC-2026-0601',
    machineId: 10,
    locationId: 1,
    category: IncidentCategory::PAYMENT_SYSTEM,
    description: 'El monedero traga monedas de 1 euro y no da cambio.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::ASSIGNED
);

$stopTwo = new Incident(
    id: 602,
    ticketCode: 'INC-2026-0602',
    machineId: 11,
    locationId: 1,
    category: IncidentCategory::TEMPERATURE_COLD,
    description: 'Máquina de sándwiches a 14 ºC con alerta de frío alimentario.',
    urgency: UrgencyLevel::CRITICAL,
    status: IncidentStatus::IN_PROGRESS,
    startedAt: '2026-10-07 08:30:00'
);

$buildLocation = static fn (): Location => new Location(
    id: 1,
    siteCode: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Passeig Marítim 25, Barcelona',
    contactPhone: '933001122',
    latitude: 41.3851,
    longitude: 2.1734
);

$buildMachines = static fn (): array => [
    10 => new Machine(10, 1, 'VEND-BCN-001', 'Vendo ColdDrink 800', MachineType::COLD_DRINKS, 'Planta 1 · Cafetería'),
    11 => new Machine(11, 1, 'VEND-BCN-002', 'Vendo FreshMeal 400', MachineType::PERISHABLE_FOOD, 'Planta Baja · Vestíbulo'),
];

/** Ajustes de la máquina 10 en cuarentena (la puerta sanitaria muerde) y de la 11 en orden. */
$buildSettings = static fn (): array => [
    10 => [
        'machine_id' => 10,
        'machine_code' => 'VEND-BCN-001',
        'machine_type' => 'COLD_DRINKS',
        'sanitary_status' => 'QUARANTINE',
        'sanitary_frequency_days' => 30,
        'default_frequency_days' => 30,
        'is_seasonal_pause' => false,
    ],
    11 => [
        'machine_id' => 11,
        'machine_code' => 'VEND-BCN-002',
        'machine_type' => 'PERISHABLE_FOOD',
        'sanitary_status' => 'OK',
        'sanitary_frequency_days' => 7,
        'default_frequency_days' => 7,
        'is_seasonal_pause' => false,
    ],
];

/**
 * Doble del repositorio de incidencias: sirve las dos paradas de la ruta.
 * Copia la forma ya certificada por `TechnicianRouteCommentsCounterTest`.
 */
$buildIncidentRepo = static function (array $stops): IncidentRepositoryInterface {
    return new class($stops) implements IncidentRepositoryInterface {
        /** @var list<array{ids: list<int>, includeInternal: bool}> */
        public array $batchCalls = [];

        /** @param list<Incident> $stops */
        public function __construct(private array $stops)
        {
        }

        public function findAssignedToTechnician(int $technicianId, array $statuses = []): array
        {
            return $this->stops;
        }

        /**
         * El hilo de la parada 601 tiene 7 mensajes (3 públicos + 4 notas internas, RF-02.3) y la
         * 602 ninguno, igual que en el fixture del tablero: así los dos caminos deben publicar
         * exactamente el mismo `comments_count`.
         */
        public function countCommentsForIncidents(array $incidentIds, bool $includeInternal): array
        {
            $this->batchCalls[] = ['ids' => array_map('intval', array_values($incidentIds)), 'includeInternal' => $includeInternal];

            return in_array(601, array_map('intval', $incidentIds), true) ? [601 => 7] : [];
        }

        public function countComments(int $incidentId, bool $includeInternal): int { return 0; }
        public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { return $incident; }
        public function findById(int $id): ?Incident { return null; }
        public function findByTicketCode(string $ticketCode): ?Incident { return null; }
        public function findActiveByMachineId(int $machineId): ?Incident { return null; }
        public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
        public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
        public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
        public function findAll(array $filters = []): array { return []; }
        public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
        public function update(Incident $incident): bool { return false; }
        public function clearNoAccessBlock(int $machineId, string $ticketCode): bool { return true; }
        public function blockForNoAccess(int $machineId, string $ticketCode): bool { return true; }
        public function softDelete(int $id): bool { return false; }
        public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 1; }
        public function getHistory(int $incidentId): array { return []; }
        public function addComment(IncidentComment $comment): IncidentComment { return $comment; }
        public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
        public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array { return []; }
        public function countReopenEvents(int $incidentId): int { return 0; }
        public function markAsChronic(int $incidentId): bool { return false; }
        public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident { throw new RuntimeException('Stub.'); }
        public function reopen(int $incidentId, string $reasonText): Incident { throw new RuntimeException('Stub.'); }
        public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { throw new RuntimeException('Stub.'); }
        public function startIntervention(int $incidentId, int $technicianId): Incident { throw new RuntimeException('Stub.'); }
        public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { throw new RuntimeException('Stub.'); }
        public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { throw new RuntimeException('Stub.'); }
        public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
        public function recordPauseEvent(int $incidentId, int $userId, IncidentStatus $fromStatus, \VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory $category, string $reasonText, \DateTimeImmutable $pausedAt): void { throw new LogicException('Not used.'); }
        public function recordResumeEvent(int $incidentId, ?int $userId, IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, \DateTimeImmutable $resumedAt): void { throw new LogicException('Not used.'); }
        public function getPendingInfoIncidentsOlderThanHours(int $hours): array { throw new LogicException('Not used.'); }
    };
};

/** Doble de máquinas CON capacidad de lote: registra llamadas por lote y por parada. */
$machinesWithBatch = static function (array $machines): MachineRepositoryInterface & MachineBatchRepositoryInterface {
    return new class($machines) implements MachineRepositoryInterface, MachineBatchRepositoryInterface {
        /** @var list<list<int>> */
        public array $batchCalls = [];

        /** @var list<int> */
        public array $singleCalls = [];

        /** @param array<int, Machine> $machines */
        public function __construct(private array $machines)
        {
        }

        public function findManyByIds(array $machineIds): array
        {
            $this->batchCalls[] = array_map(static fn ($id): int => (int)$id, array_values($machineIds));

            $found = [];
            foreach (array_unique($machineIds) as $machineId) {
                $id = (int)$machineId;
                if (isset($this->machines[$id])) {
                    $found[$id] = $this->machines[$id];
                }
            }

            return $found;
        }

        public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
        {
            $this->singleCalls[] = $id;

            return $this->machines[$id] ?? null;
        }

        public function findActiveByLocationId(int $locationId): array { return array_values($this->machines); }
        public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine { return null; }
        public function create(array $data): Machine { throw new RuntimeException('Stub.'); }
        public function update(int $id, array $data): bool { return false; }
        public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { return false; }
        public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { return false; }
        public function findAll(array $filters = []): array { return []; }
        public function hasActiveTicketOrWarranty(int $machineId): bool { return false; }
        public function getActiveTicketOrWarranty(int $machineId): ?array { return null; }
        public function clearNoAccessBlock(int $machineId, string $ticketCode): bool { return true; }
        public function blockForNoAccess(int $machineId, string $ticketCode): bool { return true; }
        public function softDelete(int $id): bool { return false; }
    };
};

/** Doble de sedes CON capacidad de lote. */
$locationsWithBatch = static function (Location $location): LocationRepositoryInterface & LocationBatchRepositoryInterface {
    return new class($location) implements LocationRepositoryInterface, LocationBatchRepositoryInterface {
        /** @var list<list<int>> */
        public array $batchCalls = [];

        /** @var list<int> */
        public array $singleCalls = [];

        public function __construct(private Location $location)
        {
        }

        public function findManyByIds(array $locationIds): array
        {
            $this->batchCalls[] = array_map(static fn ($id): int => (int)$id, array_values($locationIds));

            $found = [];
            foreach (array_unique($locationIds) as $locationId) {
                if ((int)$locationId === $this->location->getId()) {
                    $found[(int)$locationId] = $this->location;
                }
            }

            return $found;
        }

        public function findById(int $id): ?Location
        {
            $this->singleCalls[] = $id;

            return $id === $this->location->getId() ? $this->location : null;
        }

        public function findBySiteCode(string $siteCode): ?Location { return null; }
        public function findAllActive(): array { return [$this->location]; }
        public function findAll(string $status = 'all', ?string $search = null): array { return []; }
        public function create(array $data): Location { throw new RuntimeException('Stub.'); }
        public function update(int $id, array $data): bool { return false; }
        public function softDelete(int $id): bool { return false; }
        public function restore(int $id): bool { return false; }
        public function updateContactPhone(int $id, string $contactPhone): bool { return false; }
        public function countActiveMachines(int $locationId): int { return 0; }
    };
};

/** Doble de ajustes CON capacidad de lote. */
$settingsWithBatch = static function (array $settings): PreventiveSettingsRepositoryInterface & PreventiveSettingsBatchRepositoryInterface {
    return new class($settings) implements PreventiveSettingsRepositoryInterface, PreventiveSettingsBatchRepositoryInterface {
        /** @var list<list<int>> */
        public array $batchCalls = [];

        /** @var list<int> */
        public array $singleCalls = [];

        /** @param array<int, array<string, mixed>> $settings */
        public function __construct(private array $settings)
        {
        }

        public function getMachineSettingsForMany(array $machineIds): array
        {
            $this->batchCalls[] = array_map(static fn ($id): int => (int)$id, array_values($machineIds));

            $found = [];
            foreach (array_unique($machineIds) as $machineId) {
                $id = (int)$machineId;
                if (isset($this->settings[$id])) {
                    $found[$id] = $this->settings[$id];
                }
            }

            return $found;
        }

        public function getMachineSettings(int $machineId): ?array
        {
            $this->singleCalls[] = $machineId;

            return $this->settings[$machineId] ?? null;
        }

        public function findAll(): array { return []; }
        public function findByMachineType(string $machineType): ?PreventiveSetting { return null; }
        public function updateTypeSettings(string $machineType, int $defaultFrequencyDays, int $maxAllowedDays, int $advanceWarningDays = 5): bool { return true; }
        public function updateMachineConfig(int $machineId, ?int $sanitaryFrequencyDays, ?string $nextSanitaryInspectionDue = null): bool { return true; }
        public function setSeasonalPause(int $machineId, string $reason, ?string $pauseUntil = null): bool { return true; }
        public function resumeSeasonalPause(int $machineId): bool { return true; }
        public function updateSanitaryStatus(int $machineId, string $status): bool { return true; }
    };
};

// =====================================================================
// GRUPO 1: con la capacidad declarada, UNA lectura por lote y cero por parada
// =====================================================================

echo "\n--- Grupo 1: una sola lectura por lote (el camino del motor remoto) ---\n";

$incidentRepo = $buildIncidentRepo([$stopOne, $stopTwo]);
$machineRepo  = $machinesWithBatch($buildMachines());
$locationRepo = $locationsWithBatch($buildLocation());
$settingsRepo = $settingsWithBatch($buildSettings());

$controller = new TechnicianController(
    incidentRepo: $incidentRepo,
    machineRepo: $machineRepo,
    locationRepo: $locationRepo,
    pauseService: new IncidentPauseService(settingsRepo: $settingsRepo)
);

$request = new Request('GET', '/api/technician/my-route');
$request->setAttribute('user_id', 77);
$request->setAttribute('user_role', 'TECHNICIAN');

$response = $controller->getMyRoute($request);
$body = $response->getDecodedBody() ?? [];
$stops = $body['data'] ?? [];
$batchPathJson = (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$assert(
    '1.1 La ruta responde HTTP 200 OK con sus dos paradas',
    $response->getStatusCode() === 200 && ($body['success'] ?? false) === true && count((array)$stops) === 2,
    'Código recibido: ' . $response->getStatusCode() . ' · paradas: ' . count((array)$stops)
);

$assert(
    '1.2 Las máquinas de la ruta se leen en UNA sola llamada, con los identificadores deduplicados',
    $machineRepo->batchCalls === [[10, 11]],
    'Llamadas por lote: ' . json_encode($machineRepo->batchCalls)
);

$assert(
    '1.3 El controlador ya no lee máquina a máquina (cero consultas por parada)',
    $machineRepo->singleCalls === [],
    'Consultas individuales: ' . json_encode($machineRepo->singleCalls)
);

$assert(
    '1.4 La sede compartida por las dos paradas se lee UNA vez, no una por parada',
    $locationRepo->batchCalls === [[1]] && $locationRepo->singleCalls === [],
    'Lote: ' . json_encode($locationRepo->batchCalls) . ' · individuales: ' . json_encode($locationRepo->singleCalls)
);

$assert(
    '1.5 Los ajustes preventivos de la puerta sanitaria se leen en UNA sola llamada',
    $settingsRepo->batchCalls === [[10, 11]] && $settingsRepo->singleCalls === [],
    'Lote: ' . json_encode($settingsRepo->batchCalls) . ' · individuales: ' . json_encode($settingsRepo->singleCalls)
);

$assert(
    '1.6 La parada conserva el contrato publicado por el camino de lote',
    ($stops[0]['machine']['code'] ?? '') === 'VEND-BCN-001'
        && ($stops[0]['machine']['model'] ?? '') === 'Vendo ColdDrink 800'
        && ($stops[0]['machine']['floor_wing'] ?? '') === 'Planta 1 · Cafetería'
        && ($stops[0]['location']['name'] ?? '') === 'Hospital del Mar - Edificio Central'
        && ($stops[0]['location']['address'] ?? '') === 'Passeig Marítim 25, Barcelona'
        && ($stops[0]['location']['contact_phone'] ?? '') === '933001122'
        && (float)($stops[0]['location']['latitude'] ?? 0.0) === 41.3851
        && (float)($stops[0]['location']['longitude'] ?? 0.0) === 2.1734
        && ($stops[1]['machine']['code'] ?? '') === 'VEND-BCN-002'
        && ($stops[1]['started_at'] ?? '') === '2026-10-07 08:30:00',
    'Parada real: ' . json_encode($stops[0] ?? [], JSON_UNESCAPED_UNICODE)
);

$assert(
    '1.7 La puerta sanitaria sale por parada y de la regla única (cuarentena sí, máquina en orden no)',
    ($stops[0]['sanitary_resolution_required'] ?? null) === true
        && ($stops[1]['sanitary_resolution_required'] ?? null) === false,
    'Valores: ' . var_export($stops[0]['sanitary_resolution_required'] ?? null, true)
        . ' / ' . var_export($stops[1]['sanitary_resolution_required'] ?? null, true)
);

// =====================================================================
// GRUPO 2: sin la capacidad declarada, el camino de reserva no cambia el contrato
// =====================================================================

echo "\n--- Grupo 2: reserva por parada cuando el repositorio no declara la capacidad ---\n";

$plainMachineRepo = new class($buildMachines()) implements MachineRepositoryInterface {
    /** @var list<int> */
    public array $singleCalls = [];

    /** @param array<int, Machine> $machines */
    public function __construct(private array $machines)
    {
    }

    public function findById(int $id, bool $withIncident = true, bool $allowDeleted = false): ?Machine
    {
        $this->singleCalls[] = $id;

        return $this->machines[$id] ?? null;
    }

    public function findActiveByLocationId(int $locationId): array { return array_values($this->machines); }
    public function findByCode(string $code, bool $withIncident = true, bool $allowDeleted = false): ?Machine { return null; }
    public function create(array $data): Machine { throw new RuntimeException('Stub.'); }
    public function update(int $id, array $data): bool { return false; }
    public function transfer(int $id, int $targetLocationId, string $floorWing, ?string $notes = null): bool { return false; }
    public function restoreWithLocation(int $id, ?int $newLocationId = null, ?string $newFloorWing = null): bool { return false; }
    public function findAll(array $filters = []): array { return []; }
    public function hasActiveTicketOrWarranty(int $machineId): bool { return false; }
    public function getActiveTicketOrWarranty(int $machineId): ?array { return null; }
    public function clearNoAccessBlock(int $machineId, string $ticketCode): bool { return true; }
    public function blockForNoAccess(int $machineId, string $ticketCode): bool { return true; }
    public function softDelete(int $id): bool { return false; }
};

$plainLocationRepo = new class($buildLocation()) implements LocationRepositoryInterface {
    /** @var list<int> */
    public array $singleCalls = [];

    public function __construct(private Location $location)
    {
    }

    public function findById(int $id): ?Location
    {
        $this->singleCalls[] = $id;

        return $id === $this->location->getId() ? $this->location : null;
    }

    public function findBySiteCode(string $siteCode): ?Location { return null; }
    public function findAllActive(): array { return [$this->location]; }
    public function findAll(string $status = 'all', ?string $search = null): array { return []; }
    public function create(array $data): Location { throw new RuntimeException('Stub.'); }
    public function update(int $id, array $data): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function restore(int $id): bool { return false; }
    public function updateContactPhone(int $id, string $contactPhone): bool { return false; }
    public function countActiveMachines(int $locationId): int { return 0; }
};

$plainSettingsRepo = new class($buildSettings()) implements PreventiveSettingsRepositoryInterface {
    /** @var list<int> */
    public array $singleCalls = [];

    /** @param array<int, array<string, mixed>> $settings */
    public function __construct(private array $settings)
    {
    }

    public function getMachineSettings(int $machineId): ?array
    {
        $this->singleCalls[] = $machineId;

        return $this->settings[$machineId] ?? null;
    }

    public function findAll(): array { return []; }
    public function findByMachineType(string $machineType): ?PreventiveSetting { return null; }
    public function updateTypeSettings(string $machineType, int $defaultFrequencyDays, int $maxAllowedDays, int $advanceWarningDays = 5): bool { return true; }
    public function updateMachineConfig(int $machineId, ?int $sanitaryFrequencyDays, ?string $nextSanitaryInspectionDue = null): bool { return true; }
    public function setSeasonalPause(int $machineId, string $reason, ?string $pauseUntil = null): bool { return true; }
    public function resumeSeasonalPause(int $machineId): bool { return true; }
    public function updateSanitaryStatus(int $machineId, string $status): bool { return true; }
};

$reserveController = new TechnicianController(
    incidentRepo: $buildIncidentRepo([$stopOne, $stopTwo]),
    machineRepo: $plainMachineRepo,
    locationRepo: $plainLocationRepo,
    pauseService: new IncidentPauseService(settingsRepo: $plainSettingsRepo)
);

$reserveResponse = $reserveController->getMyRoute($request);
$reserveBody = $reserveResponse->getDecodedBody() ?? [];
$reserveJson = (string)json_encode($reserveBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$assert(
    '2.1 El camino de reserva sirve EXACTAMENTE el mismo payload que el camino de lote',
    $reserveResponse->getStatusCode() === 200 && $reserveJson === $batchPathJson,
    'Payload distinto entre los dos caminos.'
);

$assert(
    '2.2 Sin capacidad de lote, la máquina se lee una vez por máquina ÚNICA (no una por parada)',
    $plainMachineRepo->singleCalls === [10, 11],
    'Consultas individuales: ' . json_encode($plainMachineRepo->singleCalls)
);

$assert(
    '2.3 Sin capacidad de lote, la sede se lee una sola vez aunque la compartan dos paradas',
    $plainLocationRepo->singleCalls === [1],
    'Consultas individuales: ' . json_encode($plainLocationRepo->singleCalls)
);

$assert(
    '2.4 Sin capacidad de lote, los ajustes se leen una vez por máquina única',
    $plainSettingsRepo->singleCalls === [10, 11],
    'Consultas individuales: ' . json_encode($plainSettingsRepo->singleCalls)
);

$assert(
    '2.5 La regla sanitaria publica el mismo veredicto por los dos caminos',
    ($reserveBody['data'][0]['sanitary_resolution_required'] ?? null) === true
        && ($reserveBody['data'][1]['sanitary_resolution_required'] ?? null) === false,
    'Valores: ' . var_export($reserveBody['data'][0]['sanitary_resolution_required'] ?? null, true)
);

// =====================================================================
// GRUPO 3: una ruta sin paradas no lee nada
// =====================================================================

echo "\n--- Grupo 3: ruta sin paradas, cero lecturas ---\n";

$emptyMachineRepo  = $machinesWithBatch($buildMachines());
$emptyLocationRepo = $locationsWithBatch($buildLocation());
$emptySettingsRepo = $settingsWithBatch($buildSettings());

$emptyController = new TechnicianController(
    incidentRepo: $buildIncidentRepo([]),
    machineRepo: $emptyMachineRepo,
    locationRepo: $emptyLocationRepo,
    pauseService: new IncidentPauseService(settingsRepo: $emptySettingsRepo)
);

$emptyResponse = $emptyController->getMyRoute($request);
$emptyBody = $emptyResponse->getDecodedBody() ?? [];

$assert(
    '3.1 Una ruta vacía responde 200 con lista vacía',
    $emptyResponse->getStatusCode() === 200 && ($emptyBody['data'] ?? null) === [],
    'Cuerpo: ' . json_encode($emptyBody)
);

$assert(
    '3.2 Una ruta vacía no dispara ninguna lectura (ni por lote ni por parada)',
    $emptyMachineRepo->batchCalls === [] && $emptyMachineRepo->singleCalls === []
        && $emptyLocationRepo->batchCalls === [] && $emptyLocationRepo->singleCalls === []
        && $emptySettingsRepo->batchCalls === [] && $emptySettingsRepo->singleCalls === [],
    'Lecturas registradas sobre una ruta sin paradas.'
);

// =====================================================================
// GRUPO 4: el tablero de UNA consulta (el camino del motor real)
// =====================================================================
// Con el tablero declarado, la bandeja entera llega en una sola lectura: no se pide el recuento de
// conversaciones, ni las máquinas, ni las sedes, ni los ajustes, porque todo viaja en el mismo
// tablero. Es la cura que hace entrar la bandeja en el presupuesto de RNF-01: las cinco lecturas
// fijas del camino por lotes no caben en las 5,7 vueltas que admite el enlace medido.

echo "\n--- Grupo 4: tablero de una sola consulta (camino del motor real) ---\n";

$fixtureMachines  = $buildMachines();
$fixtureLocation  = $buildLocation();
$fixtureSettings  = $buildSettings();
$fixtureIncidents = $buildIncidentRepo([$stopOne, $stopTwo]);

/** @return list<array<string, mixed>> */
$boardFixture = static function (bool $siteResponseOnSecondStop) use ($stopOne, $stopTwo, $fixtureMachines, $fixtureLocation, $fixtureSettings): array {
    return [
        [
            'incident' => $stopOne,
            'machine' => $fixtureMachines[10],
            'location' => $fixtureLocation,
            'settings' => $fixtureSettings[10],
            'comments_total' => 7,
            'site_response_since_pause' => false,
        ],
        [
            'incident' => $stopTwo,
            'machine' => $fixtureMachines[11],
            'location' => $fixtureLocation,
            'settings' => $fixtureSettings[11],
            'comments_total' => 0,
            'site_response_since_pause' => $siteResponseOnSecondStop,
        ],
    ];
};

$boardIncidentRepo = new RouteBoardStubIncidentRepo($fixtureIncidents, $boardFixture(false));
$boardMachineRepo  = $machinesWithBatch($fixtureMachines);
$boardLocationRepo = $locationsWithBatch($fixtureLocation);
$boardSettingsRepo = $settingsWithBatch($fixtureSettings);

$boardController = new TechnicianController(
    incidentRepo: $boardIncidentRepo,
    machineRepo: $boardMachineRepo,
    locationRepo: $boardLocationRepo,
    pauseService: new IncidentPauseService(settingsRepo: $boardSettingsRepo)
);

$boardResponse = $boardController->getMyRoute($request);
$boardBody = $boardResponse->getDecodedBody() ?? [];
$boardJson = (string)json_encode($boardBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$assert(
    '4.1 El tablero responde HTTP 200 OK con sus dos paradas',
    $boardResponse->getStatusCode() === 200 && count((array)($boardBody['data'] ?? [])) === 2,
    'Código recibido: ' . $boardResponse->getStatusCode()
);

$assert(
    '4.2 La bandeja se lee con UNA sola llamada al tablero, con los estados de ruta',
    $boardIncidentRepo->boardCalls === [[
        'technician' => 77,
        'statuses' => ['ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'PENDING_INFO'],
    ]],
    'Llamadas al tablero: ' . json_encode($boardIncidentRepo->boardCalls)
);

$assert(
    '4.3 El camino del tablero no lee máquinas, sedes ni ajustes por separado',
    $boardMachineRepo->batchCalls === [] && $boardMachineRepo->singleCalls === []
        && $boardLocationRepo->batchCalls === [] && $boardLocationRepo->singleCalls === []
        && $boardSettingsRepo->batchCalls === [] && $boardSettingsRepo->singleCalls === [],
    'Lecturas registradas: máquinas ' . json_encode($boardMachineRepo->batchCalls)
        . ' · sedes ' . json_encode($boardLocationRepo->batchCalls)
        . ' · ajustes ' . json_encode($boardSettingsRepo->batchCalls)
);

$assert(
    '4.4 El tablero publica EXACTAMENTE el mismo payload que el camino por lotes',
    $boardJson === $batchPathJson,
    'Payload distinto entre el tablero y la composición por lotes.'
);

$assert(
    '4.5 La insignia de conversación viaja del tablero (7 y 0) sin recuento aparte',
    ($boardBody['data'][0]['comments_count'] ?? null) === 7
        && ($boardBody['data'][1]['comments_count'] ?? null) === 0
        && $fixtureIncidents->batchCalls === [],
    'Valores: ' . var_export($boardBody['data'][0]['comments_count'] ?? null, true)
        . ' / ' . var_export($boardBody['data'][1]['comments_count'] ?? null, true)
);

$assert(
    '4.6 La puerta sanitaria se resuelve con los ajustes del tablero, sin volver a leerlos',
    ($boardBody['data'][0]['sanitary_resolution_required'] ?? null) === true
        && ($boardBody['data'][1]['sanitary_resolution_required'] ?? null) === false
        && $boardSettingsRepo->singleCalls === [],
    'Veredictos: ' . var_export($boardBody['data'][0]['sanitary_resolution_required'] ?? null, true)
        . ' / ' . var_export($boardBody['data'][1]['sanitary_resolution_required'] ?? null, true)
);

// Controles negativos: la marca de respuesta de la sede y el recuento de conversaciones NO se
// consultan aparte, así que si el tablero los trae mal, la tarjeta lo publica mal. El doble no
// declara `IncidentSiteResponseRepositoryInterface`, de modo que un `true` publicado sólo puede
// venir del tablero.
$responseBoardController = new TechnicianController(
    incidentRepo: new RouteBoardStubIncidentRepo($fixtureIncidents, $boardFixture(true)),
    machineRepo: $boardMachineRepo,
    locationRepo: $boardLocationRepo,
    pauseService: new IncidentPauseService(settingsRepo: $boardSettingsRepo)
);
$responseBoardBody = $responseBoardController->getMyRoute($request)->getDecodedBody() ?? [];

$assert(
    '4.7 La marca de respuesta de la sede viaja resuelta por el tablero (sin consulta por parada)',
    ($responseBoardBody['data'][1]['site_response_since_pause'] ?? null) === true
        && ($responseBoardBody['data'][0]['site_response_since_pause'] ?? null) === false,
    'Valores: ' . var_export($responseBoardBody['data'][1]['site_response_since_pause'] ?? null, true)
);

// =====================================================================
// GRUPO 5: la readiness de la ruta lee las solicitudes en UNA sola llamada
// =====================================================================
// La insignia `van_stock_readiness` necesita las solicitudes PENDING de todas
// las paradas. Leerlas una por avería reintroducía el N+1 que T-HYBRID-27
// eliminó: con 13 paradas eran 13 viajes extra antes de calcular nada.

echo "\n--- Grupo 5: lectura por lote de solicitudes para la readiness ---\n";

$readinessRequests = new class implements SparePartRequestRepositoryInterface, SparePartRequestBatchRepositoryInterface {
    /** @var list<list<int>> */
    public array $batchCalls = [];

    /** @var list<int> */
    public array $singleCalls = [];

    /** @var array<int, list<SparePartRequest>> */
    private array $byIncident;

    public function __construct()
    {
        $this->byIncident = [
            601 => [new SparePartRequest(1, 601, 14, false, null, 2, SparePartRequestStatus::PENDING, 77, 'COMP-TH-01', 'Termostato', 'Carlos')],
            602 => [new SparePartRequest(2, 602, 21, false, null, 1, SparePartRequestStatus::PENDING, 77, 'VALV-HY-02', 'Electroválvula', 'Carlos')],
        ];
    }

    public function findPendingByIncidentIds(array $incidentIds): array
    {
        $this->batchCalls[] = array_values($incidentIds);
        $grouped = [];
        foreach ($incidentIds as $incidentId) {
            if (isset($this->byIncident[(int)$incidentId])) {
                $grouped[(int)$incidentId] = $this->byIncident[(int)$incidentId];
            }
        }

        return $grouped;
    }

    public function findByIncidentId(int $incidentId): array
    {
        $this->singleCalls[] = $incidentId;

        return $this->byIncident[$incidentId] ?? [];
    }

    public function createRequest(SparePartRequest $request): SparePartRequest { return $request; }
    public function findById(int $id): ?SparePartRequest { return null; }
    public function markAttendedByIncident(int $incidentId): int { return 0; }
    public function markCancelledByIncident(int $incidentId): int { return 0; }
    public function findPendingOutOfCatalogReviews(): array { return []; }
};

$readinessStock = new class implements VanStockRepositoryInterface {
    /** @var list<int> */
    public array $listCalls = [];

    public function listByTechnician(int $technicianId): array
    {
        $this->listCalls[] = $technicianId;

        return [
            new VanStockItem(1, $technicianId, 14, 5, 0),
            new VanStockItem(2, $technicianId, 21, 1, 0),
        ];
    }

    public function listInventory(?int $technicianId = null): array { return $this->listByTechnician((int)$technicianId); }
    public function listBelowThreshold(?int $technicianId = null): array { return []; }
    public function findByPair(int $technicianId, int $sparePartId): ?VanStockItem { return null; }
    public function adjust(VanStockItem $item, ?int $previousQuantity, ?int $actorUserId): VanStockItem { return $item; }
    public function consume(int $technicianId, int $sparePartId, int $quantity): void {}
    public function findTechnician(int $technicianId): ?array { return null; }
    public function sparePartExists(int $sparePartId): bool { return true; }
};

$readinessController = new TechnicianController(
    incidentRepo: $buildIncidentRepo([$stopOne, $stopTwo]),
    machineRepo: $machinesWithBatch($buildMachines()),
    locationRepo: $locationsWithBatch($buildLocation()),
    pauseService: new IncidentPauseService(settingsRepo: $settingsWithBatch($buildSettings())),
    sparePartRequestRepo: $readinessRequests,
    vanStockRepo: $readinessStock
);

$readinessBody = $readinessController->getMyRoute($request)->getDecodedBody() ?? [];
$readinessStops = $readinessBody['data'] ?? [];

$assert(
    '5.1 Las solicitudes PENDING de las dos paradas se leen en UNA sola llamada',
    $readinessRequests->batchCalls === [[601, 602]] && $readinessRequests->singleCalls === [],
    'Lotes: ' . json_encode($readinessRequests->batchCalls) . ' · individuales: ' . json_encode($readinessRequests->singleCalls)
);

$assert(
    '5.2 La insignia se calcula con esa única lectura y sin consultas por parada',
    ($readinessStops[0]['van_stock_readiness']['status'] ?? '') === 'READY_IN_VAN'
        && ($readinessStops[1]['van_stock_readiness']['status'] ?? '') === 'READY_IN_VAN'
        && $readinessStock->listCalls === [77],
    json_encode(array_map(static fn (array $stop): array => $stop['van_stock_readiness'] ?? [], $readinessStops))
);

$assert(
    '5.3 El repositorio PDO declara la capacidad de lote sin ensanchar la interfaz principal',
    (new ReflectionClass(PdoSparePartRequestRepository::class))->implementsInterface(SparePartRequestBatchRepositoryInterface::class)
        && !method_exists(SparePartRequestRepositoryInterface::class, 'findPendingByIncidentIds'),
    'La capacidad debe vivir en un contrato segregado, como las lecturas por lote de Mi Ruta.'
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. LECTURAS POR LOTE DE «MI RUTA» CERTIFICADAS.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
