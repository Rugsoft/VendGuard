<?php

declare(strict_types=1);

/**
 * QrDomainModelsTest
 * 
 * Verificación técnica unitaria de los modelos de dominio QrCodeData y QrLabelConfig (T-QR-01).
 * Comprueba inmutabilidad, validaciones de integridad, serialización y contratos.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\QrCodeData;
use VendGuard\Core\Domain\Model\QrLabelConfig;

echo "======================================================================\n";
echo " VendGuard: Verificación de Modelos de Dominio QR (T-QR-01)\n";
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
    // 1. Pruebas de QrCodeData
    // -------------------------------------------------------------
    echo "--- 1. Pruebas de QrCodeData (Value Object) ---\n";
    $qrData = new QrCodeData('vend-0101', 'sede-bcn-01', 'https://vendguard.onrender.com');

    assertCondition($qrData->getMachineCode() === 'VEND-0101', '1.1 Código de máquina se normaliza a mayúsculas');
    assertCondition($qrData->getSiteCode() === 'SEDE-BCN-01', '1.2 Código de sede se normaliza a mayúsculas');
    assertCondition($qrData->getBaseUrl() === 'https://vendguard.onrender.com', '1.3 URL base se almacena sin barra final');
    assertCondition(
        $qrData->getTargetUrl() === 'https://vendguard.onrender.com/?qr=VEND-0101&site=SEDE-BCN-01',
        '1.4 getTargetUrl() genera el formato deep link esperado'
    );

    // Serialización
    $arrayData = $qrData->toArray();
    assertCondition(isset($arrayData['target_url']) && $arrayData['machine_code'] === 'VEND-0101', '1.5 toArray() exporta campos');
    $jsonData = json_encode($qrData, JSON_THROW_ON_ERROR);
    assertCondition(str_contains($jsonData, 'VEND-0101'), '1.6 jsonSerialize() produce JSON válido');

    // Validación de excepciones
    $emptyMachineCaught = false;
    try {
        new QrCodeData('   ', 'SEDE-BCN-01');
    } catch (InvalidArgumentException $e) {
        $emptyMachineCaught = true;
    }
    assertCondition($emptyMachineCaught, '1.7 Rechaza código de máquina vacío con InvalidArgumentException');

    $emptySiteCaught = false;
    try {
        new QrCodeData('VEND-0101', '');
    } catch (InvalidArgumentException $e) {
        $emptySiteCaught = true;
    }
    assertCondition($emptySiteCaught, '1.8 Rechaza código de sede vacío con InvalidArgumentException');

    // -------------------------------------------------------------
    // 2. Pruebas de QrLabelConfig
    // -------------------------------------------------------------
    echo "\n--- 2. Pruebas de QrLabelConfig (DTO) ---\n";
    $labelConfig = new QrLabelConfig(
        machineCode: 'vend-0101',
        machineModel: 'Sanden Vendo G-Drink',
        machineType: 'PERISHABLE_FOOD',
        locationName: 'Hospital del Mar - Edificio Central',
        floorWing: 'Planta Baja - Urgencias',
        supportPhone: '600111222',
        targetUrl: 'https://vendguard.onrender.com/?qr=VEND-0101&site=SEDE-BCN-01'
    );

    assertCondition($labelConfig->getMachineCode() === 'VEND-0101', '2.1 Código de máquina normalizado');
    assertCondition($labelConfig->getMachineModel() === 'Sanden Vendo G-Drink', '2.2 Modelo almacenado correctamente');
    assertCondition($labelConfig->getMachineType() === 'PERISHABLE_FOOD', '2.3 Tipo de máquina almacenado');
    assertCondition($labelConfig->isPerishable() === true, '2.4 Infiere isPerishable=true en PERISHABLE_FOOD');
    assertCondition($labelConfig->getWidth() === 400 && $labelConfig->getHeight() === 600, '2.5 Dimensiones por defecto 400x600');
    assertCondition($labelConfig->getSupportPhone() === '600111222', '2.6 Teléfono de soporte configurado');

    // Inmutabilidad con withSupportPhone
    $updatedConfig = $labelConfig->withSupportPhone('933000999');
    assertCondition($labelConfig->getSupportPhone() === '600111222', '2.7 Instancia original no mutada');
    assertCondition($updatedConfig->getSupportPhone() === '933000999', '2.8 Nueva instancia con teléfono actualizado');
    assertCondition($updatedConfig->getMachineCode() === 'VEND-0101', '2.9 Nueva instancia preserva resto de propiedades');

    // Detección de no perecederos
    $nonPerishable = new QrLabelConfig(
        machineCode: 'VEND-0102',
        machineModel: 'Bianchi Gaia Espresso',
        machineType: 'HOT_DRINKS',
        locationName: 'Hospital del Mar',
        floorWing: 'Planta 1',
        supportPhone: '600111222',
        targetUrl: 'https://vendguard.onrender.com/?qr=VEND-0102&site=SEDE-BCN-01'
    );
    assertCondition($nonPerishable->isPerishable() === false, '2.10 Infiere isPerishable=false en HOT_DRINKS');

    // Validación de excepciones
    $emptyPhoneCaught = false;
    try {
        new QrLabelConfig('VEND-0101', 'Model', 'HOT_DRINKS', 'Loc', 'Floor', '   ', 'https://url');
    } catch (InvalidArgumentException) {
        $emptyPhoneCaught = true;
    }
    assertCondition($emptyPhoneCaught, '2.11 Rechaza teléfono vacío con InvalidArgumentException');

    $invalidDimCaught = false;
    try {
        new QrLabelConfig('VEND-0101', 'Model', 'HOT_DRINKS', 'Loc', 'Floor', '123', 'https://url', null, -10, 600);
    } catch (InvalidArgumentException) {
        $invalidDimCaught = true;
    }
    assertCondition($invalidDimCaught, '2.12 Rechaza dimensiones negativas con InvalidArgumentException');

    echo "\n======================================================================\n";
    echo " RESULTADO: {$assertions} aserciones pasadas con éxito. CONDICIÓN T-QR-01 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);

} catch (Throwable $e) {
    echo "\n[ERROR]: " . $e->getMessage() . "\n";
    exit(1);
}
