<?php

declare(strict_types=1);

/**
 * SparePartExceptionsTest
 * 
 * Suite de pruebas unitarias para las Excepciones de Dominio del Módulo 06 (T-SPARE-03):
 * - SparePartCodeExistsException (HTTP 409 Conflict)
 * - IncompatibleSparePartException (HTTP 422 Unprocessable Entity)
 * - InvalidOutOfCatalogJustificationException (HTTP 422 Unprocessable Entity)
 * - InvalidPartQuantityException (HTTP 422 Unprocessable Entity)
 * - SitePartsDataForbiddenException (HTTP 403 Forbidden, Constitución Art. V.4)
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\SparePartCodeExistsException;
use VendGuard\Core\Domain\Exception\IncompatibleSparePartException;
use VendGuard\Core\Domain\Exception\InvalidOutOfCatalogJustificationException;
use VendGuard\Core\Domain\Exception\InvalidPartQuantityException;
use VendGuard\Core\Domain\Exception\SitePartsDataForbiddenException;

echo "======================================================================\n";
echo " VendGuard: Verificación Unitaria de Excepciones de Repuestos (T-SPARE-03)\n";
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
    // 1. SparePartCodeExistsException (HTTP 409 Conflict)
    // -------------------------------------------------------------
    echo "--- 1. SparePartCodeExistsException (Código Duplicado, HTTP 409) ---\n";
    $e1 = new SparePartCodeExistsException('VALV-ULKA-01');
    assertCondition($e1 instanceof DomainException, '1.1 Hereda de DomainException');
    assertCondition($e1->getErrorCode() === 'SPARE_PART_CODE_EXISTS', '1.2 Código SPARE_PART_CODE_EXISTS');
    assertCondition($e1->getHttpStatusCode() === 409, '1.3 Código HTTP 409 Conflict');
    assertCondition($e1->getPartCode() === 'VALV-ULKA-01', '1.4 Preserva el código de pieza en conflicto');
    assertCondition(str_contains($e1->getMessage(), 'VALV-ULKA-01'), '1.5 Mensaje descriptivo contiene el código');

    // -------------------------------------------------------------
    // 2. IncompatibleSparePartException (HTTP 422 Unprocessable Entity)
    // -------------------------------------------------------------
    echo "\n--- 2. IncompatibleSparePartException (Incompatibilidad, HTTP 422) ---\n";
    $e2 = new IncompatibleSparePartException(5, 'MOT-ESP-01', 'Bianchi Gaia Espresso');
    assertCondition($e2 instanceof DomainException, '2.1 Hereda de DomainException');
    assertCondition($e2->getErrorCode() === 'INCOMPATIBLE_SPARE_PART', '2.2 Código INCOMPATIBLE_SPARE_PART');
    assertCondition($e2->getHttpStatusCode() === 422, '2.3 Código HTTP 422 Unprocessable Entity');
    assertCondition($e2->getSparePartId() === 5, '2.4 Preserva el ID de pieza');
    assertCondition($e2->getPartCode() === 'MOT-ESP-01', '2.5 Preserva el código de pieza');
    assertCondition($e2->getMachineModel() === 'Bianchi Gaia Espresso', '2.6 Preserva el modelo incompatible');
    assertCondition(str_contains($e2->getMessage(), 'MOT-ESP-01'), '2.7 Mensaje contiene código');
    assertCondition(str_contains($e2->getMessage(), 'Bianchi Gaia Espresso'), '2.8 Mensaje contiene modelo');

    // -------------------------------------------------------------
    // 3. InvalidOutOfCatalogJustificationException (HTTP 422)
    // -------------------------------------------------------------
    echo "\n--- 3. InvalidOutOfCatalogJustificationException (Justificante < 20 chars, HTTP 422) ---\n";
    $e3 = new InvalidOutOfCatalogJustificationException(12, 20);
    assertCondition($e3 instanceof DomainException, '3.1 Hereda de DomainException');
    assertCondition($e3->getErrorCode() === 'INVALID_OUT_OF_CATALOG_JUSTIFICATION', '3.2 Código INVALID_OUT_OF_CATALOG_JUSTIFICATION');
    assertCondition($e3->getHttpStatusCode() === 422, '3.3 Código HTTP 422 Unprocessable Entity');
    assertCondition($e3->getAttemptedLength() === 12, '3.4 Longitud intentada es 12');
    assertCondition($e3->getMinimumLength() === 20, '3.5 Longitud mínima requerida es 20');
    assertCondition(str_contains($e3->getMessage(), '20 caracteres'), '3.6 Mensaje indica el mínimo de 20 caracteres');

    // -------------------------------------------------------------
    // 4. InvalidPartQuantityException (HTTP 422)
    // -------------------------------------------------------------
    echo "\n--- 4. InvalidPartQuantityException (Cantidad fuera de [1, 50], HTTP 422) ---\n";
    $e4 = new InvalidPartQuantityException(99, 1, 50);
    assertCondition($e4 instanceof DomainException, '4.1 Hereda de DomainException');
    assertCondition($e4->getErrorCode() === 'INVALID_PART_QUANTITY', '4.2 Código INVALID_PART_QUANTITY');
    assertCondition($e4->getHttpStatusCode() === 422, '4.3 Código HTTP 422 Unprocessable Entity');
    assertCondition($e4->getAttemptedQuantity() === 99, '4.4 Cantidad intentada es 99');
    assertCondition($e4->getMinQuantity() === 1, '4.5 Mínimo es 1');
    assertCondition($e4->getMaxQuantity() === 50, '4.6 Máximo es 50');
    assertCondition(str_contains($e4->getMessage(), '1 y 50'), '4.7 Mensaje indica el rango 1 y 50');

    // -------------------------------------------------------------
    // 5. SitePartsDataForbiddenException (HTTP 403 Forbidden, Art. V.4)
    // -------------------------------------------------------------
    echo "\n--- 5. SitePartsDataForbiddenException (Blindaje Art. V.4, HTTP 403) ---\n";
    $e5 = new SitePartsDataForbiddenException('LOCATION_MANAGER', 'SPARE_PARTS_COST');
    assertCondition($e5 instanceof DomainException, '5.1 Hereda de DomainException');
    assertCondition($e5->getErrorCode() === 'SITE_PARTS_DATA_FORBIDDEN', '5.2 Código SITE_PARTS_DATA_FORBIDDEN');
    assertCondition($e5->getHttpStatusCode() === 403, '5.3 Código HTTP 403 Forbidden');
    assertCondition($e5->getAttemptedRole() === 'LOCATION_MANAGER', '5.4 Rol bloqueado es LOCATION_MANAGER');
    assertCondition($e5->getRequestedResource() === 'SPARE_PARTS_COST', '5.5 Recurso protegido es SPARE_PARTS_COST');
    assertCondition(str_contains($e5->getMessage(), 'Artículo V.4 de la Constitución'), '5.6 Mensaje cita expresamente el Artículo V.4');

    echo "\n======================================================================\n";
    echo " RESULTADO: 100% EN VERDE. ({$assertions} aserciones evaluadas)\n";
    echo " CONDICIÓN T-SPARE-03 CUMPLIDA SATISFACTORIAMENTE.\n";
    echo "======================================================================\n";
    exit(0);

} catch (Throwable $e) {
    echo "\n[ERROR INESPERADO]: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
