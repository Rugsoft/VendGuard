<?php

declare(strict_types=1);

/**
 * SparePartDomainModelsTest
 * 
 * Verificación técnica unitaria de los enums y modelos de dominio del Módulo 06 (T-SPARE-02):
 * - SparePartCategory
 * - OldPartDestination
 * - SparePartRequestStatus
 * - SparePart
 * - SparePartRequest
 * - IncidentReplacedPart
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\SparePartCategory;
use VendGuard\Core\Domain\Model\OldPartDestination;
use VendGuard\Core\Domain\Model\SparePartRequestStatus;
use VendGuard\Core\Domain\Model\SparePart;
use VendGuard\Core\Domain\Model\SparePartRequest;
use VendGuard\Core\Domain\Model\IncidentReplacedPart;

echo "======================================================================\n";
echo " VendGuard: Verificación de Enums y Modelos de Dominio (T-SPARE-02)\n";
echo "======================================================================\n\n";

$assertions = 0;

function assertCondition(bool $cond, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$cond) {
        echo "  [FALLO] {$message}\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

try {
    // -------------------------------------------------------------
    // 1. Pruebas de SparePartCategory (Enum)
    // -------------------------------------------------------------
    echo "--- 1. Pruebas de SparePartCategory (Enum) ---\n";
    assertCondition(SparePartCategory::HYDRAULIC->value === 'HYDRAULIC', '1.1 Categoría HYDRAULIC existe');
    assertCondition(SparePartCategory::THERMAL->label() === 'Térmico y Refrigeración', '1.2 Etiqueta de THERMAL es correcta');
    assertCondition(SparePartCategory::isValid('ELECTRONIC'), '1.3 isValid() devuelve true para categoría válida');
    assertCondition(!SparePartCategory::isValid('INVALID_CAT'), '1.4 isValid() devuelve false para categoría inválida');
    assertCondition(count(SparePartCategory::cases()) === 7, '1.5 Hay exactamente 7 categorías en el enum');

    // -------------------------------------------------------------
    // 2. Pruebas de OldPartDestination (Enum)
    // -------------------------------------------------------------
    echo "\n--- 2. Pruebas de OldPartDestination (Enum) ---\n";
    assertCondition(OldPartDestination::DESGUACE->value === 'DESGUACE', '2.1 Destino DESGUACE existe');
    assertCondition(OldPartDestination::TALLER->value === 'TALLER', '2.2 Destino TALLER existe');
    assertCondition(OldPartDestination::isValid('DESGUACE'), '2.3 isValid() reconoce DESGUACE');
    assertCondition(OldPartDestination::isValid('TALLER'), '2.4 isValid() reconoce TALLER');
    assertCondition(!OldPartDestination::isValid('ALMACEN'), '2.5 isValid() rechaza valores no contemplados (Anti-Feature Creep)');
    assertCondition(count(OldPartDestination::cases()) === 2, '2.6 Clasificación cerrada con exactamente 2 destinos');

    // -------------------------------------------------------------
    // 3. Pruebas de SparePartRequestStatus (Enum)
    // -------------------------------------------------------------
    echo "\n--- 3. Pruebas de SparePartRequestStatus (Enum) ---\n";
    assertCondition(SparePartRequestStatus::PENDING->value === 'PENDING', '3.1 Estado PENDING existe');
    assertCondition(SparePartRequestStatus::ATTENDED->value === 'ATTENDED', '3.2 Estado ATTENDED existe');
    assertCondition(SparePartRequestStatus::CANCELLED->value === 'CANCELLED', '3.3 Estado CANCELLED existe');
    assertCondition(count(SparePartRequestStatus::cases()) === 3, '3.4 Hay exactamente 3 estados en el ciclo de vida');

    // -------------------------------------------------------------
    // 4. Pruebas de SparePart (Entidad)
    // -------------------------------------------------------------
    echo "\n--- 4. Pruebas de SparePart (Entidad) ---\n";
    $part = new SparePart(
        1,
        'VALV-ULKA-01',
        'Electroválvula 24V Ulka',
        SparePartCategory::HYDRAULIC,
        'Ulka',
        28.50,
        true,
        'Válvula de presión',
        ['Azkoyen Palma+', 'Fas Perla'],
        5,
        '2026-09-01 10:00:00',
        '2026-09-01 10:00:00'
    );

    assertCondition($part->getId() === 1, '4.1 ID es 1');
    assertCondition($part->getPartCode() === 'VALV-ULKA-01', '4.2 Código de pieza es VALV-ULKA-01');
    assertCondition($part->getReferenceCost() === 28.50, '4.3 Coste de referencia es 28.50');
    assertCondition($part->isCompatibleWithModel('Azkoyen Palma+'), '4.4 Es compatible con Azkoyen Palma+');
    assertCondition(!$part->isCompatibleWithModel('Modelo Inexistente'), '4.5 No es compatible con modelo no listado');
    assertCondition($part->getTotalInstalledUnits() === 5, '4.6 Registra 5 unidades instaladas');

    $partArray = $part->toArray();
    assertCondition($partArray['category'] === 'HYDRAULIC', '4.7 toArray() exporta categoría en formato string');
    assertCondition($partArray['reference_cost'] === 28.50, '4.8 toArray() incluye reference_cost');

    // Validaciones de invariantes en SparePart
    $emptyCodeCaught = false;
    try {
        new SparePart(2, '   ', 'Nombre', SparePartCategory::OTHER, 'Fab', 10.0);
    } catch (InvalidArgumentException $e) {
        $emptyCodeCaught = true;
    }
    assertCondition($emptyCodeCaught, '4.9 Rechaza código vacío');

    $shortNameCaught = false;
    try {
        new SparePart(3, 'COD-01', 'Ab', SparePartCategory::OTHER, 'Fab', 10.0);
    } catch (InvalidArgumentException $e) {
        $shortNameCaught = true;
    }
    assertCondition($shortNameCaught, '4.10 Rechaza nombre inferior a 3 caracteres');

    $negativeCostCaught = false;
    try {
        new SparePart(4, 'COD-01', 'Nombre Válido', SparePartCategory::OTHER, 'Fab', -5.0);
    } catch (InvalidArgumentException $e) {
        $negativeCostCaught = true;
    }
    assertCondition($negativeCostCaught, '4.11 Rechaza coste de referencia negativo');

    // -------------------------------------------------------------
    // 5. Pruebas de SparePartRequest (Entidad)
    // -------------------------------------------------------------
    echo "\n--- 5. Pruebas de SparePartRequest (Entidad) ---\n";
    $reqCatalog = new SparePartRequest(
        10,
        101,
        1,
        false,
        null,
        2,
        SparePartRequestStatus::PENDING,
        5,
        'VALV-ULKA-01',
        'Electroválvula 24V Ulka',
        'Jordi Técnico'
    );
    assertCondition($reqCatalog->getId() === 10, '5.1 ID solicitud es 10');
    assertCondition($reqCatalog->getQuantity() === 2, '5.2 Cantidad es 2');
    assertCondition(!$reqCatalog->isOutOfCatalog(), '5.3 No es fuera de catálogo');
    assertCondition($reqCatalog->getStatus() === SparePartRequestStatus::PENDING, '5.4 Estado es PENDING');

    $reqCustom = new SparePartRequest(
        11,
        102,
        null,
        true,
        'Sensor óptico especial para canal 4 con conector Molex',
        1,
        SparePartRequestStatus::PENDING,
        5
    );
    assertCondition($reqCustom->isOutOfCatalog(), '5.5 Es fuera de catálogo');
    assertCondition(mb_strlen($reqCustom->getCustomPartDescription() ?? '') >= 20, '5.6 Justificación tiene >= 20 caracteres');

    // Validaciones de invariantes en SparePartRequest
    $invalidQtyCaught = false;
    try {
        new SparePartRequest(12, 103, 1, false, null, 0, SparePartRequestStatus::PENDING, 5);
    } catch (InvalidArgumentException $e) {
        $invalidQtyCaught = true;
    }
    assertCondition($invalidQtyCaught, '5.7 Rechaza cantidad 0 (rango 1 a 50)');

    $excessQtyCaught = false;
    try {
        new SparePartRequest(13, 103, 1, false, null, 51, SparePartRequestStatus::PENDING, 5);
    } catch (InvalidArgumentException $e) {
        $excessQtyCaught = true;
    }
    assertCondition($excessQtyCaught, '5.8 Rechaza cantidad > 50 anti-errata');

    $shortJustificationCaught = false;
    try {
        new SparePartRequest(14, 103, null, true, 'Demasiado corta', 1, SparePartRequestStatus::PENDING, 5);
    } catch (InvalidArgumentException $e) {
        $shortJustificationCaught = true;
    }
    assertCondition($shortJustificationCaught, '5.9 Rechaza justificación fuera de catálogo < 20 caracteres');

    $missingPartIdCaught = false;
    try {
        new SparePartRequest(15, 103, null, false, null, 1, SparePartRequestStatus::PENDING, 5);
    } catch (InvalidArgumentException $e) {
        $missingPartIdCaught = true;
    }
    assertCondition($missingPartIdCaught, '5.10 Rechaza solicitud de catálogo sin spare_part_id');

    // -------------------------------------------------------------
    // 6. Pruebas de IncidentReplacedPart (Entidad con Snapshot)
    // -------------------------------------------------------------
    echo "\n--- 6. Pruebas de IncidentReplacedPart (Entidad) ---\n";
    $replaced = new IncidentReplacedPart(
        50,
        'INCIDENT',
        105,
        null,
        14,
        2,
        3,
        1,
        false,
        null,
        3,
        28.50,
        OldPartDestination::TALLER,
        'Enviada bobina para reacondicionar'
    );

    assertCondition($replaced->getId() === 50, '6.1 ID de consumo es 50');
    assertCondition($replaced->getInterventionType() === 'INCIDENT', '6.2 Tipo de intervención es INCIDENT');
    assertCondition($replaced->getQuantity() === 3, '6.3 Cantidad instalada es 3');
    assertCondition($replaced->getUnitCostSnapshot() === 28.50, '6.4 Snapshot unitario es 28.50');
    assertCondition($replaced->getTotalCostSnapshot() === 85.50, '6.5 Total congelado es 85.50 (3 * 28.50)');
    assertCondition($replaced->getOldPartDestination() === OldPartDestination::TALLER, '6.6 Destino es TALLER');

    $repArray = $replaced->toArray();
    assertCondition($repArray['total_cost_snapshot'] === 85.50, '6.7 toArray() exporta total_cost_snapshot');
    assertCondition($repArray['old_part_destination'] === 'TALLER', '6.8 toArray() exporta destino');

    // Invariante de preventivo
    $prevReplaced = new IncidentReplacedPart(
        51,
        'PREVENTIVE',
        null,
        201,
        14,
        2,
        3,
        8,
        false,
        null,
        2,
        8.50,
        OldPartDestination::DESGUACE
    );
    assertCondition($prevReplaced->getInterventionType() === 'PREVENTIVE', '6.9 Admite tipo PREVENTIVE');
    assertCondition($prevReplaced->getPreventiveOrderId() === 201, '6.10 Asocia preventiveOrderId correctamente');

    $invalidInterventionCaught = false;
    try {
        new IncidentReplacedPart(52, 'OTRO', null, null, 1, 1, 1, 1, false, null, 1, 10.0, OldPartDestination::DESGUACE);
    } catch (InvalidArgumentException $e) {
        $invalidInterventionCaught = true;
    }
    assertCondition($invalidInterventionCaught, '6.11 Rechaza tipo de intervención no reconocido');

    $negativeSnapshotCaught = false;
    try {
        new IncidentReplacedPart(53, 'INCIDENT', 1, null, 1, 1, 1, 1, false, null, 1, -1.0, OldPartDestination::DESGUACE);
    } catch (InvalidArgumentException $e) {
        $negativeSnapshotCaught = true;
    }
    assertCondition($negativeSnapshotCaught, '6.12 Rechaza unitCostSnapshot negativo');

    echo "\n======================================================================\n";
    echo " RESULTADO: 100% EN VERDE. ({$assertions} aserciones evaluadas)\n";
    echo " CONDICIÓN T-SPARE-02 CUMPLIDA SATISFACTORIAMENTE.\n";
    echo "======================================================================\n";
    exit(0);

} catch (Throwable $e) {
    echo "\n[ERROR INESPERADO]: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
