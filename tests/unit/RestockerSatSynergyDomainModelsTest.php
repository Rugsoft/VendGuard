<?php

declare(strict_types=1);

/**
 * RestockerSatSynergyDomainModelsTest · T-RSS-03
 *
 * Certifica la capa de dominio del Módulo 36 antes de que exista un servicio:
 * los dos niveles de despacho, la taxonomía de motivos de Nivel 1 y la
 * evolución del expediente único con su rastro de escalado.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Niveles de despacho (D1, D2).** El enumerado declara exactamente los
 *      dos niveles del motor, distingue quién interviene en cada uno y hereda
 *      `L2_SAT` cuando no se declara nada: toda fila anterior al Módulo 36 se
 *      hidrata con el nivel que ya tenía de facto.
 *
 *   2. **Taxonomía de Nivel 1 (RF-01.1, RF-01.2, QA-AMB-01).** Los cuatro
 *      motivos rápidos mapean de forma determinista a su categoría, a la causa
 *      raíz semilla `RC-L1-*` y a su solución `SS-L1-*`. La pareja incoherente
 *      se detecta con `isCompatibleWith()` antes de persistir.
 *
 *   3. **Guarda de coherencia en el borde (Art. II, QA-LIM-03).** Un expediente
 *      `L1_RESTOCKER` no se construye térmico, eléctrico, en `PENDING_PARTS` ni
 *      con motivo de repuestos. Es el espejo de
 *      `chk_incidents_l1_dispatch_coherence`: la emergencia alimentaria no
 *      depende del cliente.
 *
 *   4. **Rastro de escalado todo-o-nada (D2).** El custodio de origen y la
 *      marca temporal se declaran juntos o no se declaran; media traza delata
 *      una escritura a medias y no construye la entidad. Un expediente ya
 *      escalado conserva el motivo L1 como rastro histórico.
 *
 *   5. **Opacidad de la proyección genérica (QA-CONST-03, Art. V.4).** El
 *      nivel, el motivo de Nivel 1 y el escalado no aparecen en `toArray()`,
 *      `jsonSerialize()` ni en el acceso por array: la Sede y el QR serializan
 *      esa vista y jamás deben verlos, ni siquiera por accidente.
 *
 * CATEGORÍA: hermética (`tests/unit/`). No toca la base de datos ni el reloj.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentDispatchLevel;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\Level1IssueType;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

$assertions = 0;

function assertRssCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param callable(): void $operation
 */
function assertRssRejects(callable $operation, string $message): void
{
    global $assertions;
    $assertions++;
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($message);
}

/**
 * @param array<string, mixed> $overrides
 */
function makeRssIncident(array $overrides = []): Incident
{
    return new Incident(
        id: null,
        ticketCode: $overrides['ticket_code'] ?? 'INC-RSS-0001',
        machineId: 7,
        locationId: 3,
        category: $overrides['category'] ?? IncidentCategory::PRODUCT_JAM,
        description: 'Aviso de prueba para la sinergia reponedor-SAT.',
        urgency: UrgencyLevel::MEDIUM,
        status: $overrides['status'] ?? IncidentStatus::REGISTERED,
        pendingPartsReason: $overrides['pending_parts_reason'] ?? null,
        dispatchLevel: $overrides['dispatch_level'] ?? null,
        level1IssueType: $overrides['level1_issue_type'] ?? null,
        escalatedFromRestockerId: $overrides['escalated_from_restocker_id'] ?? null,
        escalatedToSatAt: $overrides['escalated_to_sat_at'] ?? null,
    );
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function rssRow(array $overrides = []): array
{
    return array_merge([
        'ticket_code' => 'INC-RSS-9001',
        'machine_id' => 7,
        'location_id' => 3,
        'category' => 'PRODUCT_JAM',
        'description' => 'Aviso hidratado desde el motor.',
        'urgency' => 'MEDIUM',
        'status' => 'REGISTERED',
    ], $overrides);
}

try {
    echo "--- Niveles de despacho (D1, D2) ---\n";

    $levels = array_column(IncidentDispatchLevel::cases(), 'value');
    assertRssCondition(
        $levels === ['L1_RESTOCKER', 'L2_SAT'],
        'El nivel de despacho declara exactamente los dos valores del motor'
    );
    assertRssCondition(
        IncidentDispatchLevel::DEFAULT === IncidentDispatchLevel::L2_SAT,
        'El nivel heredado sin declaración es L2_SAT'
    );
    assertRssCondition(
        IncidentDispatchLevel::L1_RESTOCKER->label() === 'Nivel 1 · Reponedor'
            && IncidentDispatchLevel::L2_SAT->label() === 'Nivel 2 · Técnico SAT',
        'Cada nivel declara su etiqueta en castellano'
    );
    assertRssCondition(
        IncidentDispatchLevel::L1_RESTOCKER->isLevel1()
            && !IncidentDispatchLevel::L1_RESTOCKER->isSatLevel()
            && IncidentDispatchLevel::L2_SAT->isSatLevel()
            && !IncidentDispatchLevel::L2_SAT->isLevel1(),
        'Los predicados de nivel distinguen L1 de SAT'
    );
    assertRssCondition(
        IncidentDispatchLevel::L1_RESTOCKER->requiresRestocker()
            && !IncidentDispatchLevel::L1_RESTOCKER->requiresTechnician()
            && IncidentDispatchLevel::L2_SAT->requiresTechnician()
            && !IncidentDispatchLevel::L2_SAT->requiresRestocker(),
        'Cada nivel exige el rol que interviene: reponedor en L1, técnico en SAT'
    );
    assertRssCondition(
        IncidentDispatchLevel::fromString(' l1_restocker ') === IncidentDispatchLevel::L1_RESTOCKER,
        'El nivel se hidrata normalizando espacios y mayúsculas'
    );
    assertRssCondition(
        IncidentDispatchLevel::isValid('L2_SAT') && !IncidentDispatchLevel::isValid('L3_EMERGENCY'),
        'isValid acepta solo los dos niveles declarados'
    );
    assertRssRejects(
        static fn () => IncidentDispatchLevel::fromString('L3_EMERGENCY'),
        'Un nivel desconocido se rechaza en el borde'
    );
    echo "  [OK]   Dos niveles, rol exigido, etiquetas y normalización\n";

    echo "--- Taxonomía de Nivel 1 (RF-01.2, QA-AMB-01) ---\n";

    $types = array_column(Level1IssueType::cases(), 'value');
    sort($types);
    assertRssCondition(
        $types === ['EMPTY_CHANGE_TUBES', 'MINOR_SPIRAL_JAM', 'MISSING_CONSUMABLES', 'STOCKOUT_EMPTY_SLOT'],
        'El motivo de Nivel 1 declara exactamente los cuatro valores del motor'
    );
    assertRssCondition(
        Level1IssueType::STOCKOUT_EMPTY_SLOT->label() === 'Producto agotado / Carril vacío'
            && Level1IssueType::MISSING_CONSUMABLES->label() === 'Falta de consumibles'
            && Level1IssueType::EMPTY_CHANGE_TUBES->label() === 'Tubos de cambio vacíos'
            && Level1IssueType::MINOR_SPIRAL_JAM->label() === 'Atasco leve de producto en espiral',
        'Cada motivo declara su etiqueta en castellano'
    );
    assertRssCondition(
        Level1IssueType::STOCKOUT_EMPTY_SLOT->mappedCategory() === IncidentCategory::PRODUCT_JAM
            && Level1IssueType::MINOR_SPIRAL_JAM->mappedCategory() === IncidentCategory::PRODUCT_JAM
            && Level1IssueType::EMPTY_CHANGE_TUBES->mappedCategory() === IncidentCategory::PAYMENT_SYSTEM
            && Level1IssueType::MISSING_CONSUMABLES->mappedCategory() === IncidentCategory::OTHER,
        'El mapeo motivo → categoría es el determinista de QA-AMB-01'
    );
    assertRssCondition(
        Level1IssueType::STOCKOUT_EMPTY_SLOT->rootCauseCode() === 'RC-L1-STOCKOUT'
            && Level1IssueType::MINOR_SPIRAL_JAM->rootCauseCode() === 'RC-L1-SPIRAL-RELEASE'
            && Level1IssueType::EMPTY_CHANGE_TUBES->rootCauseCode() === 'RC-L1-EMPTY-TUBES'
            && Level1IssueType::MISSING_CONSUMABLES->rootCauseCode() === 'RC-L1-CONSUMABLES',
        'Cada motivo apunta a su causa raíz semilla'
    );
    assertRssCondition(
        Level1IssueType::STOCKOUT_EMPTY_SLOT->solutionCode() === 'SS-L1-RESTOCK-SLOT'
            && Level1IssueType::MINOR_SPIRAL_JAM->solutionCode() === 'SS-L1-CLEAR-SPIRAL'
            && Level1IssueType::EMPTY_CHANGE_TUBES->solutionCode() === 'SS-L1-REFILL-TUBES'
            && Level1IssueType::MISSING_CONSUMABLES->solutionCode() === 'SS-L1-REFILL-CUPS-SUGAR',
        'Cada motivo apunta a su solución estandarizada'
    );
    assertRssCondition(
        Level1IssueType::EMPTY_CHANGE_TUBES->isCompatibleWith(IncidentCategory::PAYMENT_SYSTEM)
            && !Level1IssueType::EMPTY_CHANGE_TUBES->isCompatibleWith(IncidentCategory::OTHER),
        'isCompatibleWith detecta la pareja incoherente antes de persistir'
    );
    assertRssCondition(
        Level1IssueType::fromString('minor_spiral_jam') === Level1IssueType::MINOR_SPIRAL_JAM
            && Level1IssueType::isValid('EMPTY_CHANGE_TUBES')
            && !Level1IssueType::isValid('BROKEN_DOOR'),
        'El motivo se normaliza y solo admite los cuatro valores'
    );
    assertRssRejects(
        static fn () => Level1IssueType::fromString('BROKEN_DOOR'),
        'Un motivo desconocido se rechaza en el borde'
    );
    echo "  [OK]   Cuatro motivos, mapeo determinista, semillas y compatibilidad\n";

    echo "--- Evolución del expediente (D1, D2) ---\n";

    $legacy = makeRssIncident();
    assertRssCondition(
        $legacy->getDispatchLevel() === IncidentDispatchLevel::L2_SAT
            && $legacy->isDispatchedToSat()
            && !$legacy->isLevel1()
            && $legacy->getLevel1IssueType() === null
            && !$legacy->wasEscalatedToSat(),
        'Un expediente sin nivel explícito nace en L2_SAT y sin rastro de Nivel 1'
    );

    $level1 = makeRssIncident([
        'dispatch_level' => IncidentDispatchLevel::L1_RESTOCKER,
        'level1_issue_type' => Level1IssueType::MINOR_SPIRAL_JAM,
    ]);
    assertRssCondition(
        $level1->isLevel1()
            && $level1->getDispatchLevel() === IncidentDispatchLevel::L1_RESTOCKER
            && $level1->getLevel1IssueType() === Level1IssueType::MINOR_SPIRAL_JAM
            && !$level1->wasEscalatedToSat(),
        'Un aviso leve se despacha en L1_RESTOCKER con su motivo rápido'
    );

    $escalated = makeRssIncident([
        'category' => IncidentCategory::PRODUCT_JAM,
        'dispatch_level' => IncidentDispatchLevel::L2_SAT,
        'level1_issue_type' => Level1IssueType::STOCKOUT_EMPTY_SLOT,
        'escalated_from_restocker_id' => 42,
        'escalated_to_sat_at' => '2026-11-18 10:30:00+00',
        'status' => IncidentStatus::REOPENED,
    ]);
    assertRssCondition(
        $escalated->isDispatchedToSat()
            && $escalated->getLevel1IssueType() === Level1IssueType::STOCKOUT_EMPTY_SLOT
            && $escalated->getEscalatedFromRestockerId() === 42
            && $escalated->getEscalatedToSatAt() === '2026-11-18 10:30:00+00'
            && $escalated->wasEscalatedToSat(),
        'El expediente escalado conserva el motivo de origen y el rastro completo'
    );

    $hydratedLegacy = Incident::fromDatabaseRow(rssRow());
    assertRssCondition(
        $hydratedLegacy->getDispatchLevel() === IncidentDispatchLevel::L2_SAT
            && $hydratedLegacy->getLevel1IssueType() === null
            && !$hydratedLegacy->wasEscalatedToSat(),
        'Una fila anterior al Módulo 36 se hidrata en L2_SAT sin inventar rastro'
    );

    $hydratedLevel1 = Incident::fromDatabaseRow(rssRow([
        'dispatch_level' => 'L1_RESTOCKER',
        'level1_issue_type' => 'STOCKOUT_EMPTY_SLOT',
    ]));
    assertRssCondition(
        $hydratedLevel1->isLevel1()
            && $hydratedLevel1->getLevel1IssueType() === Level1IssueType::STOCKOUT_EMPTY_SLOT,
        'La hidratación lee el nivel y el motivo rápido del motor'
    );

    $hydratedEscalated = Incident::fromDatabaseRow(rssRow([
        'dispatch_level' => 'L2_SAT',
        'level1_issue_type' => 'EMPTY_CHANGE_TUBES',
        'escalated_from_restocker_id' => '42',
        'escalated_to_sat_at' => '2026-11-18 10:30:00+00',
    ]));
    assertRssCondition(
        $hydratedEscalated->isDispatchedToSat()
            && $hydratedEscalated->getLevel1IssueType() === Level1IssueType::EMPTY_CHANGE_TUBES
            && $hydratedEscalated->getEscalatedFromRestockerId() === 42
            && $hydratedEscalated->getEscalatedToSatAt() === '2026-11-18 10:30:00+00',
        'La hidratación conserva el motivo histórico y el escalado tras pasar a L2_SAT'
    );

    $withId = $level1->withId(77);
    assertRssCondition(
        $withId->getId() === 77
            && $withId->getDispatchLevel() === IncidentDispatchLevel::L1_RESTOCKER
            && $withId->getLevel1IssueType() === Level1IssueType::MINOR_SPIRAL_JAM,
        'withId conserva el nivel y el motivo rápido del expediente'
    );
    echo "  [OK]   Nacimiento, hidratación, escalado histórico y clonado\n";

    echo "--- Guarda de coherencia en el borde (Art. II, QA-LIM-03) ---\n";

    assertRssRejects(
        static fn () => makeRssIncident([
            'category' => IncidentCategory::TEMPERATURE_COLD,
            'dispatch_level' => IncidentDispatchLevel::L1_RESTOCKER,
        ]),
        'Un aviso térmico no puede quedar en L1_RESTOCKER'
    );
    assertRssRejects(
        static fn () => makeRssIncident([
            'category' => IncidentCategory::ELECTRICAL_OFF,
            'dispatch_level' => IncidentDispatchLevel::L1_RESTOCKER,
        ]),
        'Un aviso eléctrico no puede quedar en L1_RESTOCKER'
    );
    assertRssRejects(
        static fn () => makeRssIncident([
            'dispatch_level' => IncidentDispatchLevel::L1_RESTOCKER,
            'status' => IncidentStatus::PENDING_PARTS,
        ]),
        'Un L1_RESTOCKER no puede estar en PENDING_PARTS'
    );
    assertRssRejects(
        static fn () => makeRssIncident([
            'dispatch_level' => IncidentDispatchLevel::L1_RESTOCKER,
            'pending_parts_reason' => 'Falta una pieza del monedero.',
        ]),
        'Un L1_RESTOCKER no puede declarar motivo de repuestos'
    );
    assertRssRejects(
        static fn () => makeRssIncident(['escalated_from_restocker_id' => 42]),
        'El custodio de origen sin marca temporal se rechaza'
    );
    assertRssRejects(
        static fn () => makeRssIncident(['escalated_to_sat_at' => '2026-11-18 10:30:00+00']),
        'La marca de escalado sin custodio de origen se rechaza'
    );
    $fullTrail = makeRssIncident([
        'escalated_from_restocker_id' => 42,
        'escalated_to_sat_at' => '2026-11-18 10:30:00+00',
    ]);
    assertRssCondition(
        $fullTrail->wasEscalatedToSat(),
        'El rastro completo se acepta sobre un expediente L2_SAT'
    );
    echo "  [OK]   Térmico, eléctrico, repuestos y rastro a medias rechazados\n";

    echo "--- Opacidad de la proyección genérica (QA-CONST-03, Art. V.4) ---\n";

    $payload = $escalated->toArray();
    $forbiddenKeys = [
        'dispatch_level',
        'dispatch_level_label',
        'level1_issue_type',
        'level1_issue_label',
        'escalated_from_restocker_id',
        'escalated_to_sat_at',
    ];
    $leakedKeys = array_values(array_filter(
        $forbiddenKeys,
        static fn (string $key): bool => array_key_exists($key, $payload)
    ));
    assertRssCondition(
        $leakedKeys === [],
        'toArray() no publica el nivel, el motivo ni el rastro de escalado'
    );
    assertRssCondition(
        !$escalated->offsetExists('dispatch_level')
            && !$escalated->offsetExists('level1_issue_type')
            && $escalated['dispatch_level'] === null,
        'El acceso por array tampoco filtra los metadatos internos'
    );
    $serialized = json_encode($escalated->jsonSerialize(), JSON_THROW_ON_ERROR);
    assertRssCondition(
        !str_contains($serialized, 'L1_RESTOCKER')
            && !str_contains($serialized, 'L2_SAT')
            && !str_contains($serialized, 'STOCKOUT_EMPTY_SLOT')
            && !str_contains($serialized, 'RC-L1-')
            && !str_contains($serialized, '2026-11-18 10:30:00'),
        'La serialización JSON no filtra ningún valor de Nivel 1 ni del escalado'
    );
    assertRssCondition(
        ($payload['ticket_code'] ?? null) === $escalated->getTicketCode()
            && ($payload['category'] ?? null) === $escalated->getCategory()->value,
        'La proyección genérica conserva el contrato público del expediente'
    );
    echo "  [OK]   Sin claves, sin acceso por array y sin valores filtrados en JSON\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
}

echo "======================================================================\n";
echo " VENDGUARD · T-RSS-03 · Modelos de dominio de la sinergia reponedor-SAT\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE\n";
echo "======================================================================\n";
exit(0);
