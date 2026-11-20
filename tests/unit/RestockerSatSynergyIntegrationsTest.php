<?php

declare(strict_types=1);

/**
 * RestockerSatSynergyIntegrationsTest · T-RSS-07
 *
 * Certifica las decisiones de integración del Módulo 36 sin base de datos:
 *
 *   1. Pre-clasificación L1 del alta QR y forzado a L2 por categoría,
 *      cuarentena, reclamación > 10 € o método digital (RF-01.2, D2).
 *   2. Auto-escalado en absorción QR grave (QA-LIM-01).
 *   3. Promoción a L2_SAT en reapertura de garantía < 48 h (QA-LIM-02).
 *   4. Relevo L1 en cierre de turno y ausencias (C1).
 *
 * CATEGORÍA: hermética (`tests/unit/`). Dobles en memoria, sin PostgreSQL.
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\RestockerSatSynergyService;
use VendGuard\Core\Domain\Repository\RestockerSatSynergyGatewayInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentDispatchLevel;
use VendGuard\Core\Domain\ValueObject\Level1IssueType;
use VendGuard\Core\Service\RestockerSatSynergyValidator;

$GLOBALS['assertions'] = 0;

function assertIntegration(bool $condition, string $message): void
{
    $GLOBALS['assertions']++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class IntegrationGatewayDouble implements RestockerSatSynergyGatewayInterface
{
    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    public function startLevel1(int $incidentId, array $actor, ?array $mutation = null): array
    {
        return ['ok' => true];
    }

    public function resolveLevel1(int $incidentId, array $actor, array $resolution, ?array $mutation = null): array
    {
        return ['ok' => true];
    }

    public function escalateToSat(int $machineId, array $actor, array $payload, ?array $mutation = null): array
    {
        return ['ok' => true];
    }

    public function promoteToSatOnQrMerge(int $incidentId, string $category, ?int $assignTechnicianId, array $actor): array
    {
        $this->calls[] = ['method' => 'promoteToSatOnQrMerge', 'args' => compact('incidentId', 'category', 'assignTechnicianId', 'actor')];

        return ['ok' => true, 'promoted' => true, 'dispatch_level' => 'L2_SAT'];
    }

    public function assignDispatchLevel(
        array $incident,
        string $dispatchLevel,
        array $assignee,
        array $actor,
        ?string $urgencyOverride = null,
        ?string $urgencyReason = null
    ): array {
        return ['ok' => true];
    }

    public function recordConflictDump(array $actor, array $mutation, array $conflict): array
    {
        return ['ok' => true];
    }

    public function suggestRestockers(int $incidentId): array
    {
        return ['ok' => true];
    }

    public function technicianCoincidences(int $technicianId, array $locationIds): array
    {
        return ['ok' => true];
    }

    public function restockerCoincidences(int $restockerId, array $locationIds): array
    {
        return ['ok' => true];
    }

    public function handoverLevel1(int $restockerId, string $cause, string $reason): array
    {
        $this->calls[] = ['method' => 'handoverLevel1', 'args' => compact('restockerId', 'cause', 'reason')];

        return ['ok' => true, 'released_count' => 2];
    }
}

// ── 1. Pre-clasificación QR ────────────────────────────────────────────
$classify = RestockerSatSynergyValidator::classifyCreation(
    Level1IssueType::MISSING_CONSUMABLES,
    IncidentCategory::PRODUCT_JAM
);
assertIntegration($classify['dispatch_level'] === IncidentDispatchLevel::L1_RESTOCKER, 'consumibles pre-clasifica L1');
assertIntegration($classify['category'] === IncidentCategory::OTHER, 'la categoría se mapea al tipo L1');
assertIntegration($classify['level1_issue_type'] === Level1IssueType::MISSING_CONSUMABLES, 'el tipo L1 se sella');

$forced = RestockerSatSynergyValidator::classifyCreation(
    Level1IssueType::MISSING_CONSUMABLES,
    IncidentCategory::TEMPERATURE_COLD
);
assertIntegration($forced['dispatch_level'] === IncidentDispatchLevel::L2_SAT, 'térmica fuerza L2');
assertIntegration($forced['forced_to_sat'] === true, 'el forzado se declara');
assertIntegration($forced['forced_reason'] === 'FORBIDDEN_CATEGORY', 'el motivo es la categoría prohibida');

$claim = RestockerSatSynergyValidator::classifyCreation(
    Level1IssueType::MISSING_CONSUMABLES,
    IncidentCategory::PRODUCT_JAM,
    null,
    15.0
);
assertIntegration($claim['dispatch_level'] === IncidentDispatchLevel::L2_SAT, 'reclamación > 10 € fuerza L2');
assertIntegration($claim['forced_reason'] === 'MONETARY_CLAIM_OVER_LIMIT', 'motivo monetario');

$digital = RestockerSatSynergyValidator::classifyCreation(
    Level1IssueType::MISSING_CONSUMABLES,
    IncidentCategory::PRODUCT_JAM,
    null,
    5.0,
    'BIZUM'
);
assertIntegration($digital['dispatch_level'] === IncidentDispatchLevel::L2_SAT, 'reintegro digital fuerza L2');
assertIntegration($digital['forced_reason'] === 'DIGITAL_REFUND_METHOD', 'motivo digital');

// ── 2. Auto-escalado QR ────────────────────────────────────────────────
assertIntegration(
    RestockerSatSynergyService::shouldAutoEscalateQrMerge(IncidentDispatchLevel::L1_RESTOCKER, IncidentCategory::ELECTRICAL_OFF),
    'el corte eléctrico sobre L1 auto-escala'
);
assertIntegration(
    RestockerSatSynergyService::shouldAutoEscalateQrMerge(IncidentDispatchLevel::L1_RESTOCKER, IncidentCategory::PRODUCT_JAM, 12.0),
    'la reclamación > 10 € sobre L1 auto-escala'
);
assertIntegration(
    RestockerSatSynergyService::shouldAutoEscalateQrMerge(IncidentDispatchLevel::L1_RESTOCKER, IncidentCategory::PRODUCT_JAM, 3.0, 'TRANSFERENCIA_BANCARIA'),
    'el método digital sobre L1 auto-escala'
);
assertIntegration(
    !RestockerSatSynergyService::shouldAutoEscalateQrMerge(IncidentDispatchLevel::L1_RESTOCKER, IncidentCategory::PRODUCT_JAM, 3.0),
    'una incidencia menor no auto-escala'
);
assertIntegration(
    !RestockerSatSynergyService::shouldAutoEscalateQrMerge(IncidentDispatchLevel::L2_SAT, IncidentCategory::TEMPERATURE_COLD),
    'un ticket ya L2 no se auto-escala'
);

$gateway = new IntegrationGatewayDouble();
$synergy = new RestockerSatSynergyService($gateway);
$promoted = $synergy->autoEscalateQrMerge(
    501,
    IncidentDispatchLevel::L1_RESTOCKER,
    IncidentCategory::TEMPERATURE_COLD,
    null,
    null,
    ['id' => 0, 'name' => 'QR', 'role' => 'SYSTEM']
);
assertIntegration(($promoted['promoted'] ?? false) === true, 'el auto-escalado delega la promoción');
assertIntegration($gateway->calls[0]['method'] === 'promoteToSatOnQrMerge', 'se invoca la promoción transaccional');
assertIntegration($gateway->calls[0]['args']['category'] === 'TEMPERATURE_COLD', 'la categoría grave llega al gateway');

$noPromotion = $synergy->autoEscalateQrMerge(
    501,
    IncidentDispatchLevel::L1_RESTOCKER,
    IncidentCategory::PRODUCT_JAM,
    3.0,
    null,
    ['id' => 0, 'name' => 'QR', 'role' => 'SYSTEM']
);
assertIntegration(($noPromotion['promoted'] ?? true) === false, 'sin gravedad no se promociona');
assertIntegration(count($gateway->calls) === 1, 'el no escalado no toca el gateway');

// ── 3. Reapertura < 48 h ───────────────────────────────────────────────
$reopenedAt = new DateTimeImmutable('2026-11-18 12:00:00+00:00');
assertIntegration(
    RestockerSatSynergyService::shouldPromoteOnReopen(
        ['dispatch_level' => 'L1_RESTOCKER', 'resolved_at' => '2026-11-18 08:00:00+00:00'],
        $reopenedAt
    ),
    'la reapertura a las 4 h promueve a L2'
);
assertIntegration(
    !RestockerSatSynergyService::shouldPromoteOnReopen(
        ['dispatch_level' => 'L1_RESTOCKER', 'resolved_at' => '2026-11-16 08:00:00+00:00'],
        $reopenedAt
    ),
    'la reapertura fuera de 48 h no promueve'
);
assertIntegration(
    !RestockerSatSynergyService::shouldPromoteOnReopen(
        ['dispatch_level' => 'L2_SAT', 'resolved_at' => '2026-11-18 08:00:00+00:00'],
        $reopenedAt
    ),
    'un ticket L2 no se promociona'
);

// ── 4. Relevo L1 ───────────────────────────────────────────────────────
$handover = $synergy->handoverLevel1(44, 'shift_close', 'Fin de jornada de reposición');
assertIntegration(($handover['released_count'] ?? 0) === 2, 'el relevo devuelve los avisos liberados');
assertIntegration($gateway->calls[1]['method'] === 'handoverLevel1', 'el relevo delega en el gateway');
assertIntegration($gateway->calls[1]['args']['cause'] === 'SHIFT_CLOSE', 'la causa del cierre se normaliza');
assertIntegration($gateway->calls[1]['args']['restockerId'] === 44, 'el reponedor del turno llega al gateway');

echo "======================================================================\n";
echo " Total Aserciones: {$GLOBALS['assertions']} | Fallidas: 0\n";
echo " RESULTADO: 100% EN VERDE. INTEGRACIONES DE LA SINERGIA CERTIFICADAS (T-RSS-07).\n";
echo "======================================================================\n";
