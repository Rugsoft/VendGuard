<?php

declare(strict_types=1);

/**
 * MachineDexAuditServiceTest · T-DEX-07
 *
 * Certifica la orquestación del Módulo 39 (RF-02–RF-07, D1–D6, C1–C6,
 * QA-1–QA-5, Art. II, III.3, V.1 y V.4) con dobles en memoria de los puertos:
 * repositorio, gateway y outbox.
 *
 *   1. **Tres puertas y perímetro.** Cada `import_source` exige su rol; el
 *      Reponedor fuera de zona recibe `403` y en cuarentena o avería térmica
 *      abierta `409 SANITARY_DEX_BLOCKED`; Técnico y Coordinación conservan el
 *      acceso forense.
 *   2. **Deltas y selecciones.** Base inicial, parciales de reseteo, delta por
 *      carril y detección de volcado posterior.
 *   3. **Alerta térmica anti-spam.** Solo en `PERISHABLE_FOOD`/`COMBO` y solo
 *      ante evento nuevo o `count_since_init` incrementado; `dedup_key`
 *      `dex_thermal:{machineId}:{auditId}` y plantilla `DEX_THERMAL_EVENT_ALERT`.
 *   4. **Sugerencia y Arqueo Ciego.** `suggested_escalation_payload` con códigos
 *      y `prefill_sales_counter_reading_eur` solo para el Reponedor inmediato.
 *   5. **Offline y anulación.** Replay idempotente, mutación usada en otra
 *      máquina, duplicado de huella, motivo de anulación y estado terminal.
 *   6. **CSV.** BOM UTF-8, separador `;`, fecha `Europe/Madrid` y neutralización
 *      de fórmulas.
 *
 * Uso:
 *   php tests/unit/MachineDexAuditServiceTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\MachineDexAuditService;
use VendGuard\Core\Domain\Exception\DexAuditConflictException;
use VendGuard\Core\Domain\Exception\DexAuditForbiddenException;
use VendGuard\Core\Domain\Exception\DexAuditValidationException;
use VendGuard\Core\Domain\Model\MachineDexAudit;
use VendGuard\Core\Domain\Model\NotificationOutboxItem;
use VendGuard\Core\Domain\Repository\MachineDexAuditGatewayInterface;
use VendGuard\Core\Domain\Repository\MachineDexAuditRepositoryInterface;
use VendGuard\Core\Domain\Repository\NotificationOutboxRepositoryInterface;
use VendGuard\Core\Service\DexAuditValidator;
use VendGuard\Core\Service\DexSelectionMatcher;
use VendGuard\Core\Service\EvaDtsParser;

/** Repositorio en memoria con la misma semántica de deltas que el PDO. */
final class Dex07FakeRepository implements MachineDexAuditRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $audits = [];
    /** @var array<int, list<array<string, mixed>>> */
    public array $selections = [];
    /** @var array<int, list<array<string, mixed>>> */
    public array $events = [];

    private int $nextId = 1;
    public int $createCalls = 0;

    /** @param array<string, mixed> $header */
    public function seed(array $header, array $selections = [], array $events = []): int
    {
        $id = (int)($header['id'] ?? $this->nextId++);
        $header['id'] = $id;
        $header += [
            'status' => 'ACTIVE',
            'machine_type_snapshot' => 'COMBO',
            'machine_code_snapshot' => 'VEND-DEX07',
            'identity_mismatch_warning' => false,
            'counter_reset_detected' => false,
            'out_of_order_sync' => false,
            'has_thermal_alert' => false,
            'import_source' => 'RESTOCKER_ROUTE',
            'sha256_hash' => '',
        ];
        $this->audits[$id] = $header;
        $this->selections[$id] = $selections;
        $this->events[$id] = $events;
        if ($id >= $this->nextId) {
            $this->nextId = $id + 1;
        }

        return $id;
    }

    public function reserveAuditCode(?DateTimeImmutable $now = null): string
    {
        return sprintf('DEX-2026-%04d', count($this->audits) + 1);
    }

    public function create(MachineDexAudit $audit, array $selectionLines = [], array $eventLines = []): MachineDexAudit
    {
        $this->createCalls++;
        $id = $this->nextId++;
        $this->audits[$id] = [
            'id' => $id,
            'audit_code' => $audit->getAuditCode(),
            'machine_id' => $audit->getMachineId(),
            'machine_code_snapshot' => $audit->getMachineCodeSnapshot(),
            'machine_type_snapshot' => $audit->getMachineTypeSnapshot(),
            'imported_by' => $audit->getImportedBy(),
            'importer_role_snapshot' => $audit->getImporterRoleSnapshot(),
            'import_source' => $audit->getImportSource()->value,
            'status' => $audit->getStatus()->value,
            'sha256_hash' => $audit->getSha256Hash(),
            'client_mutation_id' => $audit->getClientMutationId(),
            'captured_at' => $audit->getCapturedAt(),
            'va1_total_sales_eur' => $audit->getVa1TotalSalesEur(),
            'va1_total_vend_units' => $audit->getVa1TotalVendUnits(),
            'delta_source' => $audit->getDeltaSource(),
            'delta_sales_eur' => $audit->getDeltaSalesEur(),
            'delta_vend_units' => $audit->getDeltaVendUnits(),
            'previous_audit_id' => $audit->getPreviousAuditId(),
            'counter_reset_detected' => $audit->isCounterResetDetected(),
            'out_of_order_sync' => $audit->isOutOfOrderSync(),
            'identity_mismatch_warning' => $audit->hasIdentityMismatchWarning(),
            'has_thermal_alert' => false,
            'ca3_cashbox_eur' => $audit->getCa3CashboxEur(),
        ];
        $this->selections[$id] = $selectionLines;
        $this->events[$id] = $eventLines;

        return $audit->withId($id);
    }

    public function findById(int $auditId): ?array
    {
        if (!isset($this->audits[$auditId])) {
            return null;
        }
        $audit = $this->audits[$auditId];
        $audit['selections'] = array_map(
            static function (array $line): array {
                if (isset($line['match_status']) && is_object($line['match_status'])) {
                    $line['match_status'] = $line['match_status']->value;
                }

                return $line;
            },
            $this->selections[$auditId] ?? []
        );
        $audit['events'] = $this->events[$auditId] ?? [];
        $audit['has_thermal_alert'] = $this->hasThermal($auditId);

        return $audit;
    }

    public function findRawPayload(int $auditId): ?array
    {
        return isset($this->audits[$auditId])
            ? ['raw_payload' => 'DXS*X', 'audit_code' => $this->audits[$auditId]['audit_code'], 'original_filename' => 'x.dex', 'captured_at' => $this->audits[$auditId]['captured_at']]
            : null;
    }

    public function findActiveIdByHash(int $machineId, string $sha256Hash): ?int
    {
        foreach ($this->audits as $id => $audit) {
            if ($audit['machine_id'] === $machineId
                && $audit['sha256_hash'] === $sha256Hash
                && $audit['status'] === 'ACTIVE') {
                return $id;
            }
        }

        return null;
    }

    public function findByActorMutation(int $importedBy, string $clientMutationId): ?array
    {
        foreach ($this->audits as $id => $audit) {
            if ((int)$audit['imported_by'] === $importedBy
                && ($audit['client_mutation_id'] ?? null) === $clientMutationId) {
                return ['id' => $id, 'machine_id' => (int)$audit['machine_id'], 'status' => (string)$audit['status']];
            }
        }

        return null;
    }

    public function findPreviousActive(int $machineId, string $capturedAt, ?int $excludeAuditId = null): ?array
    {
        $best = null;
        foreach ($this->audits as $id => $audit) {
            if ((int)$audit['machine_id'] !== $machineId || $audit['status'] !== 'ACTIVE') {
                continue;
            }
            if ($excludeAuditId !== null && $id === $excludeAuditId) {
                continue;
            }
            if (strtotime((string)$audit['captured_at']) > strtotime($capturedAt)) {
                continue;
            }
            if ($best === null
                || strtotime((string)$audit['captured_at']) > strtotime((string)$best['captured_at'])
                || (strtotime((string)$audit['captured_at']) === strtotime((string)$best['captured_at']) && $id > $best['id'])) {
                $best = [
                    'id' => $id,
                    'captured_at' => $audit['captured_at'],
                    'va1_total_sales_eur' => $audit['va1_total_sales_eur'],
                    'va1_total_vend_units' => $audit['va1_total_vend_units'],
                ];
            }
        }

        return $best;
    }

    public function hasLaterActive(int $machineId, string $capturedAt): bool
    {
        foreach ($this->audits as $audit) {
            if ((int)$audit['machine_id'] === $machineId
                && $audit['status'] === 'ACTIVE'
                && strtotime((string)$audit['captured_at']) > strtotime($capturedAt)) {
                return true;
            }
        }

        return false;
    }

    public function findSelectionsByAudit(int $auditId): array
    {
        return $this->selections[$auditId] ?? [];
    }

    public function listForCoordinator(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $rows = [];
        foreach ($this->audits as $audit) {
            if (isset($filters['machine_id']) && (int)$filters['machine_id'] !== (int)$audit['machine_id']) {
                continue;
            }
            $status = strtoupper((string)($filters['status'] ?? 'ACTIVE'));
            if ($status !== 'ALL' && $status !== '' && $audit['status'] !== $status) {
                continue;
            }
            $audit['has_thermal_alert'] = $this->hasThermal((int)$audit['id']);
            $rows[] = $audit;
        }

        return array_slice($rows, $offset, $limit);
    }

    public function kpisForCoordinator(array $filters = []): array
    {
        $active = array_filter($this->audits, static fn (array $a): bool => $a['status'] === 'ACTIVE');

        return [
            'total_active' => count($active),
            'thermal_alert_count' => 0,
            'warning_count' => 0,
            'cash_deviation_count' => 0,
            'voided_count' => count($this->audits) - count($active),
        ];
    }

    public function findLinkTargetForUpdate(int $auditId): ?array
    {
        if (!isset($this->audits[$auditId])) {
            return null;
        }
        $audit = $this->audits[$auditId];

        return [
            'id' => $auditId,
            'machine_id' => (int)$audit['machine_id'],
            'status' => (string)$audit['status'],
            'restock_visit_id' => isset($audit['restock_visit_id']) ? (int)$audit['restock_visit_id'] : null,
            'cash_collection_id' => isset($audit['cash_collection_id']) ? (int)$audit['cash_collection_id'] : null,
            'incident_id' => isset($audit['incident_id']) ? (int)$audit['incident_id'] : null,
        ];
    }

    public function linkToRestockVisit(int $auditId, int $restockVisitId): void
    {
        $this->audits[$auditId]['restock_visit_id'] = $restockVisitId;
    }

    public function linkToCashCollection(int $auditId, int $cashCollectionId): void
    {
        $this->audits[$auditId]['cash_collection_id'] = $cashCollectionId;
    }

    public function linkToIncident(int $auditId, int $incidentId): void
    {
        $this->audits[$auditId]['incident_id'] = $incidentId;
    }

    public function void(int $auditId, string $reason, int $voidedBy, string $voidedByName, string $voidedAt): array
    {
        $this->audits[$auditId]['status'] = 'VOIDED';
        $this->audits[$auditId]['void_reason'] = $reason;
        $this->audits[$auditId]['voided_by'] = $voidedBy;
        $this->audits[$auditId]['voided_at'] = $voidedAt;

        return $this->findById($auditId);
    }

    private function hasThermal(int $auditId): bool
    {
        foreach ($this->events[$auditId] ?? [] as $event) {
            if (($event['is_thermal_alert'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}

/** Gateway configurable por máquina. */
final class Dex07FakeGateway implements MachineDexAuditGatewayInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $contexts = [];
    /** @var array<int, list<array<string, mixed>>> */
    public array $slots = [];

    public function lockMachineContext(int $machineId, int $actorId): ?array
    {
        return $this->contexts[$machineId] ?? null;
    }

    public function lockRestockVisit(int $visitId, int $machineId): ?array
    {
        return null;
    }

    public function lockCashCollection(int $cashCollectionId, int $machineId): ?array
    {
        return null;
    }

    public function lockIncident(int $incidentId, int $machineId): ?array
    {
        return null;
    }

    public function findNearestCashCollectionSameDay(int $machineId, string $capturedAt): ?array
    {
        return null;
    }

    public function findCompletedRestockVisitSameDay(int $machineId, string $capturedAt, ?int $linkedVisitId = null): ?array
    {
        return null;
    }

    public function findPreviousCompletedRestockVisit(int $machineId, int $referenceVisitId): ?array
    {
        return null;
    }

    public function findVisitSlotQuantities(int $visitId): array
    {
        return [];
    }

    public function findActivePlanogramSlots(int $machineId): array
    {
        return $this->slots[$machineId] ?? [];
    }
}

/** Outbox en memoria con deduplicación por clave. */
final class Dex07FakeOutbox implements NotificationOutboxRepositoryInterface
{
    /** @var list<NotificationOutboxItem> */
    public array $items = [];

    public function enqueue(NotificationOutboxItem $item): ?NotificationOutboxItem
    {
        foreach ($this->items as $existing) {
            if ($item->getDedupKey() !== null && $existing->getDedupKey() === $item->getDedupKey()) {
                return null;
            }
        }
        $this->items[] = $item;

        return $item;
    }

    public function findById(int $id): ?NotificationOutboxItem
    {
        return null;
    }

    public function save(NotificationOutboxItem $item): bool
    {
        return false;
    }

    public function claimReadyBatch(int $limit = 50): array
    {
        return [];
    }

    public function rescueStuckProcessingItems(int $staleMinutes = 5, ?DateTimeImmutable $now = null): int
    {
        return 0;
    }

    public function findForCoordinator(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return [];
    }

    public function countForCoordinator(array $filters = []): int
    {
        return 0;
    }

    public function countByStatus(?DateTimeImmutable $now = null): array
    {
        return [];
    }

    public function hasTemplateForIncident(int $incidentId, string $template): bool
    {
        return false;
    }

    public function cancelPendingForIncident(int $incidentId, string $reason, array $preserveTemplates = []): int
    {
        return 0;
    }

    public function cancelPendingForRecipientUser(int $userId, string $reason): int
    {
        return 0;
    }

    public function cancelPendingForIncidentRecipient(int $incidentId, int $recipientUserId, string $reason, array $templates = []): int
    {
        return 0;
    }

    public function cancelPendingWebhookItems(string $reason): int
    {
        return 0;
    }
}

$assertions = 0;
$failures = 0;
$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$expect = static function (callable $operation, string $errorCode, int $httpStatus): bool {
    try {
        $operation();
    } catch (DexAuditForbiddenException | DexAuditConflictException | DexAuditValidationException $exception) {
        return $exception->getErrorCode() === $errorCode && $exception->getHttpStatusCode() === $httpStatus;
    } catch (Throwable) {
        return false;
    }

    return false;
};

$repository = new Dex07FakeRepository();
$gateway = new Dex07FakeGateway();
$outbox = new Dex07FakeOutbox();
$service = new MachineDexAuditService(
    $repository,
    $gateway,
    new EvaDtsParser(),
    new DexSelectionMatcher(),
    new DexAuditValidator(),
    $outbox
);

$context = static fn (string $type, string $role, array $overrides = []): array => $overrides + [
    'id' => 1,
    'machine_code' => 'VEND-DEX07',
    'machine_type' => $type,
    'sanitary_status' => 'OK',
    'is_covered_restocker' => true,
    'is_covered_technician' => true,
    'has_open_thermal_breakdown' => false,
    'actor_role' => $role,
    'actor_name' => 'Actor DEX 07',
    'actor_is_active' => true,
];

for ($m = 1; $m <= 20; $m++) {
    $gateway->contexts[$m] = $context('COMBO', 'RESTOCKER');
    $gateway->slots[$m] = [['id' => 10, 'slot_code' => 'A1', 'current_estimated_qty' => 7]];
}

$payload = static fn (string $va1, string $extra = ''): string =>
    "DXS*COMM-DEX07*VA*DEX 6.0\nID4*2\nVA1*{$va1}\nPA1*A1*150\nPA2*10*1500\n{$extra}DXE*FIN";

$request = static fn (int $machineId, int $actorId, string $source, string $raw, array $extra = []): array => $extra + [
    'machine_id' => $machineId,
    'actor_id' => $actorId,
    'import_source' => $source,
    'raw_payload' => $raw,
    'original_filename' => 'volcado.dex',
    'captured_at' => '2026-11-20 10:00:00+00',
];

echo "======================================================================\n";
echo " VENDGUARD · T-DEX-07 · Servicio de auditorías EVA-DTS (.dex)\n";
echo "======================================================================\n";

echo "\n--- 1. Puerta del Reponedor, deltas y alerta térmica (QA-1, QA-4) ---\n";

$first = $service->importDex($request(
    1,
    5,
    'RESTOCKER_ROUTE',
    $payload('2550*41', "EA2*EG01*1*1*0*1\n"),
    ['client_mutation_id' => 'dex07-mut-0001']
));

$assert('1.1 El volcado del Reponedor se persiste con código y delta inicial', ($first['audit']['id'] ?? 0) === 1 && $first['audit']['delta_source'] === 'INITIAL_BASELINE');
$assert('1.2 La selección PA1 se empareja con el carril A1', ($first['audit']['selections'][0]['match_status'] ?? '') === 'EXACT_MATCH' && ($first['audit']['selections'][0]['matched_slot_id'] ?? 0) === 10);
$assert('1.3 El prefill VA1 solo viaja al Reponedor', $first['prefill_sales_counter_reading_eur'] === 25.50);
$assert('1.4 El evento térmico activa el banner sanitario', $first['has_thermal_alert'] === true);
$assert('1.5 La alerta térmica se encola una sola vez con dedup_key', count($outbox->items) === 1
    && $outbox->items[0]->getTemplate()->value === 'DEX_THERMAL_EVENT_ALERT'
    && $outbox->items[0]->getDedupKey() === 'dex_thermal:1:1'
    && $first['thermal_alert_enqueued'] === true);
$assert('1.6 La sugerencia de escalado lleva categoría y códigos', $first['suggested_escalation_payload']['action'] === 'ESCALATE_TO_SAT'
    && $first['suggested_escalation_payload']['breakdown_category'] === 'TEMPERATURE_COLD'
    && str_contains($first['suggested_escalation_payload']['internal_note'], 'EG01')
    && $first['suggested_escalation_payload']['is_internal'] === true);

$replay = $service->importDex($request(1, 5, 'RESTOCKER_ROUTE', $payload('2550*41'), ['client_mutation_id' => 'dex07-mut-0001']));
$assert('1.7 El replay del móvil es idempotente y no duplica filas', $replay['idempotent_replay'] === true && $repository->createCalls === 1);

$assert('1.8 La misma mutación en otra máquina es 409', $expect(
    static fn () => $service->importDex($request(2, 5, 'RESTOCKER_ROUTE', $payload('1000*10'), ['client_mutation_id' => 'dex07-mut-0001'])),
    'CLIENT_MUTATION_ID_ALREADY_USED',
    409
));
$assert('1.9 La misma huella vigente es 409 DEX_DUPLICATE_HASH', $expect(
    static fn () => $service->importDex($request(1, 5, 'RESTOCKER_ROUTE', $payload('2550*41', "EA2*EG01*1*1*0*1\n"), ['captured_at' => '2026-11-22 10:00:00+00'])),
    'DEX_DUPLICATE_HASH',
    409
));

echo "\n--- 2. Puertas, perímetro y primacía sanitaria (RF-03.1, C2) ---\n";

$assert('2.1 El origen de Técnico no puede usarlo un Reponedor', $expect(
    static fn () => $service->importDex($request(3, 5, 'TECHNICIAN_ROUTE', $payload('1000*10'))),
    'DEX_IMPORT_SOURCE_ROLE_MISMATCH',
    403
));
$gateway->contexts[4] = $context('COMBO', 'RESTOCKER', ['is_covered_restocker' => false]);
$assert('2.2 El Reponedor fuera de zona recibe 403', $expect(
    static fn () => $service->importDex($request(4, 5, 'RESTOCKER_ROUTE', $payload('1000*10'))),
    'MACHINE_OUTSIDE_RESTOCKER_ZONES',
    403
));
$gateway->contexts[5] = $context('COMBO', 'RESTOCKER', ['sanitary_status' => 'QUARANTINE']);
$assert('2.3 El Reponedor en cuarentena recibe 409 sanitario', $expect(
    static fn () => $service->importDex($request(5, 5, 'RESTOCKER_ROUTE', $payload('1000*10'))),
    'SANITARY_DEX_BLOCKED',
    409
));
$gateway->contexts[6] = $context('PERISHABLE_FOOD', 'TECHNICIAN', ['sanitary_status' => 'QUARANTINE', 'has_open_thermal_breakdown' => true]);
$technician = $service->importDex($request(6, 6, 'TECHNICIAN_ROUTE', $payload('1000*10', "EA2*EF01*1*1*0*1\n")));
$assert('2.4 El Técnico conserva acceso forense en cuarentena', ($technician['audit']['id'] ?? 0) > 0 && $technician['prefill_sales_counter_reading_eur'] === null);
$assert('2.5 El Técnico recibe la acción de alta oportunista M16', ($technician['suggested_escalation_payload']['action'] ?? null) === 'OPEN_OPPORTUNISTIC_INCIDENT'
    && ($technician['suggested_escalation_payload']['breakdown_category'] ?? null) === 'PRODUCT_JAM');
$gateway->contexts[7] = $context('COMBO', 'COORDINATOR', ['sanitary_status' => 'QUARANTINE', 'has_open_thermal_breakdown' => true]);
$assert('2.6 Coordinación importa en cualquier estado sanitario', ($service->importDex($request(7, 7, 'COORDINATOR_CONSOLE', $payload('1000*10')))['audit']['id'] ?? 0) > 0);
$gateway->contexts[8] = $context('COMBO', 'RESTOCKER', ['actor_is_active' => false]);
$assert('2.7 Un actor inactivo no puede importar', $expect(
    static fn () => $service->importDex($request(8, 8, 'RESTOCKER_ROUTE', $payload('1000*10'))),
    'DEX_ACTOR_INACTIVE',
    403
));
$assert('2.8 Una máquina inexistente es 422', $expect(
    static fn () => $service->importDex($request(99, 9, 'RESTOCKER_ROUTE', $payload('1000*10'))),
    'MACHINE_NOT_FOUND',
    422
));

echo "\n--- 3. Anti-spam térmico y gating por tipología (QA-4, D4) ---\n";

$repository->seed(
    ['id' => 100, 'machine_id' => 9, 'status' => 'ACTIVE', 'captured_at' => '2026-11-19 10:00:00+00', 'va1_total_sales_eur' => 10.0, 'va1_total_vend_units' => 10, 'audit_code' => 'DEX-2026-0100'],
    [],
    [['event_code' => 'EG01', 'is_thermal_alert' => true, 'count_since_init' => 2]]
);
$before = count($outbox->items);
$service->importDex($request(9, 9, 'RESTOCKER_ROUTE', $payload('1000*10', "EA2*EG01*2*2*0*1\n")));
$assert('3.1 Un evento térmico congelado no repite la alerta', count($outbox->items) === $before);
$service->importDex($request(9, 9, 'RESTOCKER_ROUTE', $payload('1100*11', "EA2*EG01*3*3*0*1\n"), ['captured_at' => '2026-11-21 10:00:00+00']));
$assert('3.2 Un contador EA203 incrementado sí encola la alerta', count($outbox->items) === $before + 1);

$gateway->contexts[10] = $context('SNACKS', 'RESTOCKER');
$snack = $service->importDex($request(10, 9, 'RESTOCKER_ROUTE', $payload('1000*10', "EA2*EG01*1*1*0*1\n")));
$assert('3.3 Una máquina SNACKS no activa el aviso sanitario ni encola', $snack['has_thermal_alert'] === false && count($outbox->items) === $before + 1);

echo "\n--- 4. Reseteos y delta por carril (C1, QA-2) ---\n";

$repository->seed(
    ['id' => 200, 'machine_id' => 11, 'status' => 'ACTIVE', 'captured_at' => '2026-11-19 10:00:00+00', 'va1_total_sales_eur' => 100.00, 'va1_total_vend_units' => 500, 'audit_code' => 'DEX-2026-0200'],
    [['normalized_selection_code' => 'A1', 'total_vend_units' => 8, 'total_sales_eur' => 10.00]]
);
$reset = $service->importDex($request(11, 9, 'RESTOCKER_ROUTE', $payload('1000*10')));
$assert('4.1 Un retroceso sin parciales queda UNAVAILABLE', $reset['audit']['counter_reset_detected'] === true && $reset['audit']['delta_source'] === 'UNAVAILABLE');
$resetFallback = $service->importDex($request(11, 9, 'RESTOCKER_ROUTE', $payload('500*5*500*5'), ['captured_at' => '2026-11-21 10:00:00+00']));
$assert('4.2 Con VA103/VA104 el delta cae a RESET_FALLBACK', $resetFallback['audit']['delta_source'] === 'RESET_FALLBACK' && $resetFallback['audit']['delta_sales_eur'] === 5.0);
$repository->seed(
    ['id' => 201, 'machine_id' => 12, 'status' => 'ACTIVE', 'captured_at' => '2026-11-19 10:00:00+00', 'va1_total_sales_eur' => 10.00, 'va1_total_vend_units' => 5, 'audit_code' => 'DEX-2026-0201'],
    [['normalized_selection_code' => 'A1', 'total_vend_units' => 8, 'total_sales_eur' => 10.00]]
);
$delta = $service->importDex($request(12, 9, 'RESTOCKER_ROUTE', $payload('1500*10'), ['captured_at' => '2026-11-20 10:00:00+00']));
$assert('4.3 El delta por carril resta el volcado previo', $delta['audit']['selections'][0]['delta_vend_units'] === 2 && $delta['audit']['selections'][0]['delta_sales_eur'] === 5.00, json_encode($delta['audit']['selections'][0] ?? []));

echo "\n--- 5. Anulación y CSV (C6, Art. V.1) ---\n";

$voided = $service->voidAudit(1, 'Anulación por duplicado operativo', 7, 'Coordinadora DEX 07', '2026-12-31 10:00:00+00');
$assert('5.1 La anulación válida sella el volcado', $voided['status'] === 'VOIDED' && $voided['void_reason'] === 'Anulación por duplicado operativo');
$assert('5.2 Un volcado ya anulado es terminal', $expect(
    static fn () => $service->voidAudit(1, 'Segundo intento de anulación', 7, 'Coordinadora DEX 07', '2026-12-31 10:05:00+00'),
    'DEX_AUDIT_ALREADY_VOIDED',
    409
));
$repository->seed(['id' => 300, 'machine_id' => 13, 'status' => 'ACTIVE', 'captured_at' => '2026-11-20 10:00:00+00', 'va1_total_sales_eur' => 1.0, 'va1_total_vend_units' => 1, 'audit_code' => 'DEX-2026-0300']);
$assert('5.3 Un motivo demasiado corto es 422', $expect(
    static fn () => $service->voidAudit(300, 'Motivo corto', 7, 'Coordinadora DEX 07', '2026-12-31 10:00:00+00'),
    'VOID_REASON_REQUIRED',
    422
));
$repository->seed(['id' => 400, 'machine_id' => 14, 'status' => 'ACTIVE', 'captured_at' => '2026-11-20 10:00:00+00', 'va1_total_sales_eur' => 1.0, 'va1_total_vend_units' => 1, 'audit_code' => '=DEX-2026-9999']);
$csv = $service->exportCsv();
$assert('5.4 El CSV lleva BOM UTF-8 y separador ;', str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'audit_code;machine_code;captured_at'));
$assert('5.5 La fecha se presenta en Europe/Madrid', str_contains($csv, '20/11/2026 11:00'));
$assert('5.6 Una celda con fórmula se neutraliza', str_contains($csv, "'=DEX-2026-9999"));

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. T-DEX-07 (servicio de auditorías .dex) CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL SERVICIO DE AUDITORÍAS .DEX.\n";
echo "======================================================================\n";
exit(1);
