<?php

declare(strict_types=1);

/**
 * QrMatrixGeneratorTest
 * 
 * Suite de pruebas unitarias para el generador nativo de matrices QR (T-QR-03).
 * Valida dimensiones cuadradas, quiet zone de seguridad, finder patterns,
 * determinismo, integridad de versiones y control de excepciones.
 * 
 * Cumple con RNF-01, RNF-04 y el Dogma Vanilla de VendGuard.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\QrCodeData;
use VendGuard\Core\Domain\Service\QrMatrixGenerator;

echo "======================================================================\n";
echo " VendGuard: Verificación Unitaria de QrMatrixGenerator (T-QR-03)\n";
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
    // 1. Dimensiones Cuadradas y Selección Automática de Versión
    // -------------------------------------------------------------
    echo "--- 1. Dimensiones Cuadradas y Versiones QR ---\n";

    // Versión 1: Texto muy corto (< 14 caracteres con ECC M) -> 21x21 + 8 quiet zone = 29x29
    $matrixV1 = QrMatrixGenerator::generate('VEND-01', QrMatrixGenerator::ECC_M, 4);
    $rowsV1 = count($matrixV1);
    $colsV1 = count($matrixV1[0]);
    assertCondition($rowsV1 === 29 && $colsV1 === 29, "1.1 Texto corto genera matriz de 29x29 (Versión 1 + 4 quiet zone)");
    assertCondition($rowsV1 === $colsV1, "1.2 Matriz de versión 1 es perfectamente cuadrada");

    // Versión 4: URL VendGuard típica (~58 caracteres con ECC M) -> 33x33 + 8 quiet zone = 41x41
    $vendGuardUrl = 'https://vendguard.onrender.com/?qr=VEND-0101&site=SEDE-BCN-01';
    $matrixV4 = QrMatrixGenerator::generate($vendGuardUrl, QrMatrixGenerator::ECC_M, 4);
    $rowsV4 = count($matrixV4);
    $colsV4 = count($matrixV4[0]);
    assertCondition($rowsV4 === 41 && $colsV4 === 41, "1.3 URL VendGuard (~58 chars) genera matriz de 41x41 (Versión 4 + 4 quiet zone)");
    assertCondition($rowsV4 === $colsV4, "1.4 Matriz de versión 4 es perfectamente cuadrada");

    // Comprobar que todas las filas tengan exactamente la misma cantidad de columnas
    $allRowsUniform = true;
    foreach ($matrixV4 as $row) {
        if (count($row) !== $colsV4) {
            $allRowsUniform = false;
            break;
        }
    }
    assertCondition($allRowsUniform, "1.5 Todas las filas de la matriz tienen longitud uniforme idéntica");

    // -------------------------------------------------------------
    // 2. Quiet Zone (Zona de Silencio perimetral)
    // -------------------------------------------------------------
    echo "\n--- 2. Quiet Zone y Márgenes de Seguridad ---\n";

    // Quiet zone por defecto (4 módulos): los primeros y últimos 4 renglones y columnas deben ser completamente blancos (false)
    $topQuietZoneOk = true;
    for ($r = 0; $r < 4; $r++) {
        foreach ($matrixV4[$r] as $col) {
            if ($col !== false) {
                $topQuietZoneOk = false;
                break 2;
            }
        }
    }
    assertCondition($topQuietZoneOk, "2.1 Primeras 4 filas son completamente blancas (quiet zone superior)");

    $bottomQuietZoneOk = true;
    for ($r = $rowsV4 - 4; $r < $rowsV4; $r++) {
        foreach ($matrixV4[$r] as $col) {
            if ($col !== false) {
                $bottomQuietZoneOk = false;
                break 2;
            }
        }
    }
    assertCondition($bottomQuietZoneOk, "2.2 Últimas 4 filas son completamente blancas (quiet zone inferior)");

    $leftQuietZoneOk = true;
    for ($r = 0; $r < $rowsV4; $r++) {
        for ($c = 0; $c < 4; $c++) {
            if ($matrixV4[$r][$c] !== false) {
                $leftQuietZoneOk = false;
                break 2;
            }
        }
    }
    assertCondition($leftQuietZoneOk, "2.3 Primeras 4 columnas de toda la matriz son blancas (quiet zone izquierda)");

    $rightQuietZoneOk = true;
    for ($r = 0; $r < $rowsV4; $r++) {
        for ($c = $colsV4 - 4; $c < $colsV4; $c++) {
            if ($matrixV4[$r][$c] !== false) {
                $rightQuietZoneOk = false;
                break 2;
            }
        }
    }
    assertCondition($rightQuietZoneOk, "2.4 Últimas 4 columnas de toda la matriz son blancas (quiet zone derecha)");

    // Quiet zone = 0 (sin margen)
    $matrixNoMargin = QrMatrixGenerator::generate($vendGuardUrl, QrMatrixGenerator::ECC_M, 0);
    assertCondition(count($matrixNoMargin) === 33 && count($matrixNoMargin[0]) === 33, "2.5 quietZone=0 genera dimensiones exactas del símbolo QR (33x33 para v4)");

    // Quiet zone = 2 (margen reducido de 2 módulos por lado)
    $matrixMargin2 = QrMatrixGenerator::generate($vendGuardUrl, QrMatrixGenerator::ECC_M, 2);
    assertCondition(count($matrixMargin2) === 37 && count($matrixMargin2[0]) === 37, "2.6 quietZone=2 genera dimensiones exactas (33 + 4 = 37x37)");

    // -------------------------------------------------------------
    // 3. Patrones de Búsqueda (Finder Patterns 7x7)
    // -------------------------------------------------------------
    echo "\n--- 3. Verificación de Finder Patterns 7x7 ---\n";

    // Plantilla estándar de un finder pattern 7x7:
    // 1 1 1 1 1 1 1
    // 1 0 0 0 0 0 1
    // 1 0 1 1 1 0 1
    // 1 0 1 1 1 0 1
    // 1 0 1 1 1 0 1
    // 1 0 0 0 0 0 1
    // 1 1 1 1 1 1 1
    $expectedFinder = [
        [true,  true,  true,  true,  true,  true,  true],
        [true,  false, false, false, false, false, true],
        [true,  false, true,  true,  true,  false, true],
        [true,  false, true,  true,  true,  false, true],
        [true,  false, true,  true,  true,  false, true],
        [true,  false, false, false, false, false, true],
        [true,  true,  true,  true,  true,  true,  true],
    ];

    $checkFinderAt = function (array $matrix, int $startRow, int $startCol) use ($expectedFinder): bool {
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                if ($matrix[$startRow + $r][$startCol + $c] !== $expectedFinder[$r][$c]) {
                    return false;
                }
            }
        }
        return true;
    };

    // En matrixNoMargin (quietZone = 0), los finder patterns están en:
    // Top-Left: (0, 0)
    // Top-Right: (0, size - 7)
    // Bottom-Left: (size - 7, 0)
    $sizeV4 = 33;
    $tlMatch = $checkFinderAt($matrixNoMargin, 0, 0);
    $trMatch = $checkFinderAt($matrixNoMargin, 0, $sizeV4 - 7);
    $blMatch = $checkFinderAt($matrixNoMargin, $sizeV4 - 7, 0);

    assertCondition($tlMatch, "3.1 Finder Pattern Top-Left 7x7 coincide exactamente con la norma ISO");
    assertCondition($trMatch, "3.2 Finder Pattern Top-Right 7x7 coincide exactamente con la norma ISO");
    assertCondition($blMatch, "3.3 Finder Pattern Bottom-Left 7x7 coincide exactamente con la norma ISO");

    // Con quietZone = 4, el Finder Pattern Top-Left debe estar en (4, 4)
    $tlWithQuietZone = $checkFinderAt($matrixV4, 4, 4);
    assertCondition($tlWithQuietZone, "3.4 Finder Pattern Top-Left posicionado con precisión tras la quiet zone de 4 módulos");

    // -------------------------------------------------------------
    // 4. Determinismo y Repetibilidad
    // -------------------------------------------------------------
    echo "\n--- 4. Determinismo y Repetibilidad ---\n";

    $run1 = QrMatrixGenerator::generate($vendGuardUrl, QrMatrixGenerator::ECC_M, 4);
    $run2 = QrMatrixGenerator::generate($vendGuardUrl, QrMatrixGenerator::ECC_M, 4);

    $isIdentical = ($run1 === $run2);
    assertCondition($isIdentical, "4.1 Dos ejecuciones con los mismos parámetros producen matrices estrictamente idénticas");

    // -------------------------------------------------------------
    // 5. Integración con QrCodeData (Value Object del Dominio)
    // -------------------------------------------------------------
    echo "\n--- 5. Integración con QrCodeData ---\n";

    $qrData = new QrCodeData('VEND-4022', 'SEDE-MAD-01', 'https://vendguard.onrender.com');
    $matrixFromDomain = QrMatrixGenerator::generate($qrData->getTargetUrl(), QrMatrixGenerator::ECC_M, 4);

    assertCondition(
        count($matrixFromDomain) === 41 && count($matrixFromDomain[0]) === 41,
        "5.1 QrMatrixGenerator genera la matriz a partir del deep link de QrCodeData"
    );

    // -------------------------------------------------------------
    // 6. Control de Excepciones y Casos Límite
    // -------------------------------------------------------------
    echo "\n--- 6. Control de Excepciones y Casos Límite ---\n";

    // 6.1 Texto vacío
    $emptyTextCaught = false;
    try {
        QrMatrixGenerator::generate('');
    } catch (InvalidArgumentException $e) {
        $emptyTextCaught = true;
    }
    assertCondition($emptyTextCaught, "6.1 Texto vacío lanza InvalidArgumentException");

    // 6.2 Nivel ECC inválido
    $invalidEccCaught = false;
    try {
        QrMatrixGenerator::generate('TEST', 'Z');
    } catch (InvalidArgumentException $e) {
        $invalidEccCaught = true;
    }
    assertCondition($invalidEccCaught, "6.2 Nivel de corrección de error desconocido ('Z') lanza InvalidArgumentException");

    // 6.3 Quiet zone negativa
    $negativeQuietZoneCaught = false;
    try {
        QrMatrixGenerator::generate('TEST', QrMatrixGenerator::ECC_M, -1);
    } catch (InvalidArgumentException $e) {
        $negativeQuietZoneCaught = true;
    }
    assertCondition($negativeQuietZoneCaught, "6.3 Quiet zone negativa lanza InvalidArgumentException");

    // 6.4 Texto excesivamente largo que sobrepasa la versión 10 en ECC M (> 216 bytes)
    $excessiveTextCaught = false;
    try {
        $tooLong = str_repeat('A', 300);
        QrMatrixGenerator::generate($tooLong, QrMatrixGenerator::ECC_M);
    } catch (InvalidArgumentException $e) {
        $excessiveTextCaught = true;
    }
    assertCondition($excessiveTextCaught, "6.4 Texto que sobrepasa la capacidad máxima (v10) lanza InvalidArgumentException");

    // -------------------------------------------------------------
    // 7. Niveles de Corrección de Error (L, M, Q, H)
    // -------------------------------------------------------------
    echo "\n--- 7. Niveles de Corrección de Error (L, M, Q, H) ---\n";

    foreach ([QrMatrixGenerator::ECC_L, QrMatrixGenerator::ECC_M, QrMatrixGenerator::ECC_Q, QrMatrixGenerator::ECC_H] as $ecc) {
        $mat = QrMatrixGenerator::generate('VEND-TEST', $ecc, 4);
        assertCondition(
            count($mat) > 0 && count($mat) === count($mat[0]),
            "7.1 Nivel ECC '{$ecc}' genera una matriz cuadrada válida"
        );
    }

    echo "\n======================================================================\n";
    echo " RESULTADO: {$assertions} aserciones pasadas con éxito. CONDICIÓN T-QR-03 CUMPLIDA.\n";
    echo "======================================================================\n";

} catch (Throwable $t) {
    echo "\n[ERROR INESPERADO]: " . $t->getMessage() . "\n";
    echo $t->getTraceAsString() . "\n";
    exit(1);
}
