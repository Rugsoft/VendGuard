<?php

declare(strict_types=1);

/**
 * DesignTokensTest
 * 
 * Suite de pruebas unitarias para validar las variables CSS del Sistema de Diseño Docker
 * (RNF-06, design.md, T-31).
 * 
 * Valida la condición "Hecho cuando":
 * - El archivo design-tokens.css existe físicamente en public/assets/css/.
 * - Define --color-primary: #2560ff.
 * - Define --color-canvas: #f9fafb.
 * - Define --color-slate: #2c333f.
 * - Define bordes de 4px en elementos interactivos (--radius-interactive: 4px).
 * - Define bordes de 8px en tarjetas (--radius-card: 8px).
 * - Importa las fuentes web Inter y DM Sans.
 * 
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - DesignTokensTest (T-31)\n";
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

$cssFilePath = dirname(__DIR__, 2) . '/public/assets/css/design-tokens.css';

// =====================================================================
// GRUPO 1: Existencia e Integridad del Fichero CSS
// =====================================================================
echo "--- Grupo 1: Existencia e Integridad del Archivo CSS ---\n";

$fileExists = file_exists($cssFilePath);
$assert("1.1 El archivo public/assets/css/design-tokens.css existe", $fileExists, "Ruta no encontrada: {$cssFilePath}");

if (!$fileExists) {
    echo "\n[ERROR CRITICO] Archivo CSS no encontrado. Abortando pruebas.\n";
    exit(1);
}

$cssContent = (string) file_get_contents($cssFilePath);
$assert("1.2 El archivo CSS no está vacío", strlen(trim($cssContent)) > 0);

// =====================================================================
// GRUPO 2: Importación de Fuentes Web (Inter & DM Sans)
// =====================================================================
echo "\n--- Grupo 2: Tipografías Oficiales (Inter y DM Sans) ---\n";

$hasGoogleFontsImport = (bool) preg_match('/@import\s+url\([\'"][^\'"]*fonts\.googleapis\.com[^\'"]*[\'"]\);/i', $cssContent);
$assert("2.1 Importa fuentes web mediante @import de Google Fonts", $hasGoogleFontsImport);

$hasInterFont = stripos($cssContent, 'Inter') !== false;
$assert("2.2 Contiene la fuente Inter para cuerpo de texto y etiquetas", $hasInterFont);

$hasDmSansFont = stripos($cssContent, 'DM Sans') !== false;
$assert("2.3 Contiene la fuente DM Sans para titulares y display", $hasDmSansFont);

// =====================================================================
// GRUPO 3: Colores Principales del Sistema Docker (design.md)
// =====================================================================
echo "\n--- Grupo 3: Tokens de Color Principales ---\n";

$hasColorPrimary = (bool) preg_match('/--color-primary:\s*#2560ff\b/i', $cssContent);
$assert("3.1 Define --color-primary: #2560ff (Azul eléctrico / Voltaje)", $hasColorPrimary);

$hasColorCanvas = (bool) preg_match('/--color-canvas:\s*#f9fafb\b/i', $cssContent);
$assert("3.2 Define --color-canvas: #f9fafb (Fondo canvas)", $hasColorCanvas);

$hasColorSlate = (bool) preg_match('/--color-slate:\s*#2c333f\b/i', $cssContent);
$assert("3.3 Define --color-slate: #2c333f (Tinta pizarra principal)", $hasColorSlate);

// =====================================================================
// GRUPO 4: Radios de Borde Conservadores (4px interactivos / 8px tarjetas)
// =====================================================================
echo "\n--- Grupo 4: Radios de Borde Conservadores (RNF-06 / design.md) ---\n";

$hasRadiusInteractive = (bool) preg_match('/--radius-interactive:\s*4px\b/i', $cssContent);
$assert("4.1 Define --radius-interactive: 4px para elementos interactivos", $hasRadiusInteractive);

$hasRadiusCard = (bool) preg_match('/--radius-card:\s*8px\b/i', $cssContent);
$assert("4.2 Define --radius-card: 8px para tarjetas y paneles", $hasRadiusCard);

// =====================================================================
// GRUPO 5: Componentes Base y Clases de Utilidad
// =====================================================================
echo "\n--- Grupo 5: Clases Base de Componentes UI ---\n";

$hasCardClass = (bool) preg_match('/\.vg-card\s*\{[^}]*--radius-card/s', $cssContent);
$assert("5.1 La clase .vg-card aplica el radio de tarjeta (--radius-card)", $hasCardClass);

$hasBtnClass = (bool) preg_match('/\.vg-btn\s*\{[^}]*--radius-interactive/s', $cssContent);
$assert("5.2 La clase .vg-btn aplica el radio interactivo (--radius-interactive)", $hasBtnClass);

$hasInputClass = (bool) preg_match('/\.(vg-input|vg-select|vg-textarea)[^\{]*\{[^}]*--radius-interactive/s', $cssContent);
$assert("5.3 Los controles de formulario aplican el radio interactivo (--radius-interactive)", $hasInputClass);

$hasBadgeClass = (bool) preg_match('/\.vg-badge\s*\{[^}]*--radius-interactive/s', $cssContent);
$assert("5.4 Los badges de estado aplican el radio interactivo (--radius-interactive)", $hasBadgeClass);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-31 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
    echo "======================================================================\n";
    exit(1);
}
