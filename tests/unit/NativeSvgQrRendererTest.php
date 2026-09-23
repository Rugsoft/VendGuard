<?php

declare(strict_types=1);

/**
 * NativeSvgQrRendererTest
 * 
 * Suite de pruebas unitarias para el renderizador vectorial SVG nativo (T-QR-05).
 * Valida la corrección sintáctica XML, dimensiones 400x600 px, inclusión del código QR
 * (> 40x40 mm), distintivos sanitarios de perecederos (Art. II), teléfono de soporte y
 * mitigación de inyección XML.
 * 
 * Cumple con RF-01, RF-02, RNF-03, RNF-04 y el Dogma Vanilla de VendGuard.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\QrLabelConfig;
use VendGuard\Core\Domain\Service\NativeSvgQrRenderer;
use VendGuard\Core\Domain\Service\QrMatrixGenerator;

echo "======================================================================\n";
echo " VendGuard: Verificación Unitaria de NativeSvgQrRenderer (T-QR-05)\n";
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
    // 1. Validez Sintáctica XML y Estructura Base (400x600 px)
    // -------------------------------------------------------------
    echo "--- 1. Validez Sintáctica XML y Geometría SVG ---\n";

    $configPerishable = new QrLabelConfig(
        'VEND-0101',
        'Sanden Vendo G-Drink',
        'PERISHABLE_FOOD',
        'Hospital del Mar - Edificio Central',
        'Planta Baja - Urgencias',
        '600111222',
        'https://vendguard.onrender.com/?qr=VEND-0101&site=SEDE-BCN-01'
    );

    $svg = NativeSvgQrRenderer::renderLabel($configPerishable);

    $dom = new DOMDocument();
    $isValidXml = @$dom->loadXML($svg);
    assertCondition($isValidXml === true, "1.1 El SVG generado es un documento XML 100% válido y parseable");

    assertCondition(str_contains($svg, 'viewBox="0 0 400 600"'), "1.2 Contiene viewBox='0 0 400 600' estándar");
    assertCondition(str_contains($svg, 'width="400"'), "1.3 Contiene width='400'");
    assertCondition(str_contains($svg, 'height="600"'), "1.4 Contiene height='600'");
    assertCondition(str_contains($svg, 'xmlns="http://www.w3.org/2000/svg"'), "1.5 Declara el namespace XML estándar de SVG");

    // -------------------------------------------------------------
    // 2. Elementos Gráficos e Identificativos de la Etiqueta (RF-01)
    // -------------------------------------------------------------
    echo "\n--- 2. Identificación Visual y Logotipo VendGuard ---\n";

    assertCondition(str_contains($svg, 'VENDGUARD'), "2.1 Contiene el isotipo/título de marca VENDGUARD");
    assertCondition(str_contains($svg, 'SISTEMA INTELIGENTE DE CONTROL DE VENDING'), "2.2 Contiene el subtítulo corporativo oficial");
    assertCondition(str_contains($svg, 'VEND-0101'), "2.3 Contiene el código de máquina visible destacado");
    assertCondition(str_contains($svg, 'Sanden Vendo G-Drink'), "2.4 Contiene el modelo de la máquina");
    assertCondition(str_contains($svg, 'Hospital del Mar - Edificio Central'), "2.5 Contiene el nombre de la sede");
    assertCondition(str_contains($svg, 'Planta Baja - Urgencias'), "2.6 Contiene la ubicación física de planta/ala");

    // -------------------------------------------------------------
    // 3. Distintivos Sanitarios según Tipo de Máquina (Art. II)
    // -------------------------------------------------------------
    echo "\n--- 3. Seguridad Alimentaria y Distintivos (Art. II) ---\n";

    // Caso A: Alimentos perecederos (isPerishable = true)
    assertCondition(
        str_contains($svg, 'ALIMENTOS FRESCOS') && str_contains($svg, 'ROTURA DE FRÍO CRÍTICA'),
        "3.1 Máquina de perecederos incluye badge crítico de advertencia sanitaria"
    );

    // Caso B: Bebidas / Snacks convencionales (isPerishable = false)
    $configNonPerishable = new QrLabelConfig(
        'VEND-0202',
        'Necta Canto Touch',
        'HOT_DRINKS',
        'Campus Nord UPC',
        'Edificio A - Hall',
        '934010000',
        'https://vendguard.onrender.com/?qr=VEND-0202&site=SEDE-BCN-02'
    );
    $svgNonPerishable = NativeSvgQrRenderer::renderLabel($configNonPerishable);

    $domNonPerishable = new DOMDocument();
    assertCondition(@$domNonPerishable->loadXML($svgNonPerishable) === true, "3.2 Etiqueta de máquina no perecedera es XML válido");
    assertCondition(
        str_contains($svgNonPerishable, 'BEBIDAS Y SNACKS'),
        "3.3 Máquina estándar incluye badge de bebidas y snacks"
    );
    assertCondition(
        !str_contains($svgNonPerishable, 'ROTURA DE FRÍO CRÍTICA'),
        "3.4 Máquina estándar no muestra advertencia sanitaria de rotura de frío"
    );

    // -------------------------------------------------------------
    // 4. Código QR Centralizado (> 40x40 mm) y Vectorial Puro
    // -------------------------------------------------------------
    echo "\n--- 4. Código QR Vectorial Centralizado (> 40x40 mm) ---\n";

    // Debe contener elemento <path con comandos M y h
    assertCondition(str_contains($svg, '<path d="M'), "4.1 El código QR se renderiza como trazado vectorial <path>");
    assertCondition(str_contains($svg, 'fill="#0f172a"'), "4.2 Trazado de alto contraste oscuro (#0f172a)");

    // El área física mínima requerida en EARS 1.4 es 40x40 mm.
    // A 96 DPI estándar de pantalla/SVG (1 pulgada = 25.4 mm):
    // 40 mm * (96 px / 25.4 mm) = 151.18 px.
    // En NativeSvgQrRenderer usamos 200.0 px (52.9 mm), cumpliendo con holgura.
    assertCondition(str_contains($svg, 'width="212" height="212"'), "4.3 Marco del código QR reserva 212x212 px (superior a 40x40 mm)");

    // -------------------------------------------------------------
    // 5. Llamada a la Acción y Teléfono de Asistencia Técnica
    // -------------------------------------------------------------
    echo "\n--- 5. Llamada a la Acción y Asistencia Telefónica ---\n";

    assertCondition(str_contains($svg, '¿AVERÍA O DINERO RETENIDO?'), "5.1 Contiene encabezado de llamada a la acción");
    assertCondition(str_contains($svg, 'avisar de una avería o reclamar dinero retenido'), "5.2 Contiene texto explicativo de EARS 1.4");
    assertCondition(str_contains($svg, '600111222'), "5.3 Contiene el teléfono de asistencia técnica configurado");
    assertCondition(str_contains($svg, 'Asistencia Técnica y Atención Telefónica'), "5.4 Contiene etiqueta de contacto de soporte");

    // -------------------------------------------------------------
    // 6. Sanitización Anti-XSS e Inyección XML
    // -------------------------------------------------------------
    echo "\n--- 6. Sanitización Anti-XSS y Seguridad XML ---\n";

    $configInjection = new QrLabelConfig(
        'VEND-9999',
        'Máquina <test> & "spec"',
        'SNACKS',
        'Sede con <script>alert(1)</script> & ampersand',
        'Planta 1 > Ala Este & Oeste',
        '+34 900 000 000 & 900 111 222',
        'https://vendguard.onrender.com/?qr=VEND-9999&site=SEDE-MAD-01'
    );
    $svgInjection = NativeSvgQrRenderer::renderLabel($configInjection);

    $domInjection = new DOMDocument();
    $validInjectionXml = @$domInjection->loadXML($svgInjection);
    assertCondition($validInjectionXml === true, "6.1 Textos con caracteres XML conflictivos (<, >, &, \") no rompen el parser XML");
    assertCondition(!str_contains($svgInjection, '<script>'), "6.2 Etiquetas <script> han sido escapadas a &lt;script&gt;");
    assertCondition(str_contains($svgInjection, '&amp;'), "6.3 Los ampersands han sido codificados como &amp;");

    // -------------------------------------------------------------
    // 7. Renderizador Autónomo de Código QR (renderStandaloneQr)
    // -------------------------------------------------------------
    echo "\n--- 7. Renderizador Autónomo de Código QR ---\n";

    $standaloneSvg = NativeSvgQrRenderer::renderStandaloneQr(
        'https://vendguard.onrender.com/?qr=VEND-0101&site=SEDE-BCN-01',
        300,
        QrMatrixGenerator::ECC_M,
        4
    );

    $domStandalone = new DOMDocument();
    assertCondition(@$domStandalone->loadXML($standaloneSvg) === true, "7.1 renderStandaloneQr genera XML SVG válido");
    assertCondition(str_contains($standaloneSvg, 'viewBox="0 0 300 300"'), "7.2 viewBox coincide con el tamaño solicitado (300x300)");
    assertCondition(str_contains($standaloneSvg, '<path d="M'), "7.3 Contiene el trazado vectorial <path>");

    // Excepción con tamaño <= 0
    $invalidSizeCaught = false;
    try {
        NativeSvgQrRenderer::renderStandaloneQr('TEST', 0);
    } catch (InvalidArgumentException $e) {
        $invalidSizeCaught = true;
    }
    assertCondition($invalidSizeCaught, "7.4 renderStandaloneQr con tamaño <= 0 lanza InvalidArgumentException");

    echo "\n======================================================================\n";
    echo " RESULTADO: {$assertions} aserciones pasadas con éxito. CONDICIÓN T-QR-05 CUMPLIDA.\n";
    echo "======================================================================\n";

} catch (Throwable $t) {
    echo "\n[ERROR INESPERADO]: " . $t->getMessage() . "\n";
    echo $t->getTraceAsString() . "\n";
    exit(1);
}
