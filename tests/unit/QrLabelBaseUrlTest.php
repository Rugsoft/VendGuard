<?php

declare(strict_types=1);

/**
 * QrLabelBaseUrlTest
 *
 * Suite de pruebas unitarias de la Enmienda 1 (T-QR-17) del módulo QR.
 * Valida el contrato técnico §1.2 (qr_codes_contracts.md):
 * 1. Detección del esquema real de la petición (X-Forwarded-Proto / HTTPS directo).
 * 2. Fallback de desarrollo del servicio de etiquetas (http://localhost).
 * 3. Host dinámico en el pie de la etiqueta SVG, derivado del target URL.
 * 4. Ausencia de dominios embebidos en el código de producción del módulo (RF-06 / RNF-05).
 *
 * Cumple con la Enmienda 1 aprobada el 2026-10-04 y el Dogma Vanilla.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\QrLabelConfig;
use VendGuard\Core\Domain\Service\NativeSvgQrRenderer;
use VendGuard\Application\Service\QrLabelService;
use VendGuard\Presentation\Controller\QrLabelController;
use VendGuard\Presentation\Http\Request;

echo "======================================================================\n";
echo " VendGuard: Verificación Unitaria de Resolución de Host QR (T-QR-17)\n";
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
    // 1. Esquema real de la petición (Request::getScheme)
    // -------------------------------------------------------------
    echo "--- 1. Detección de Esquema y Proxy TLS ---\n";

    $reqForwarded = new Request('GET', '/', [], [], [
        'Host' => 'vendguard.onrender.com',
        'X-Forwarded-Proto' => 'https',
    ]);
    assertCondition($reqForwarded->getScheme() === 'https', '1.1 X-Forwarded-Proto: https ⇒ esquema https');
    assertCondition($reqForwarded->getHeader('Host') === 'vendguard.onrender.com', '1.2 Cabecera Host accesible');

    $reqForwardedList = new Request('GET', '/', [], [], [
        'X-Forwarded-Proto' => 'https, http',
    ]);
    assertCondition($reqForwardedList->getScheme() === 'https', '1.3 Lista de proxios toma el primer valor (https)');

    $reqLocal = new Request('GET', '/', [], [], ['Host' => '127.0.0.1:8000']);
    assertCondition($reqLocal->getScheme() === 'http', '1.4 Petición local directa ⇒ esquema http');

    $reqProxyHttp = new Request('GET', '/', [], [], ['X-Forwarded-Proto' => 'http']);
    assertCondition($reqProxyHttp->getScheme() === 'http', '1.5 Proxy declarando http ⇒ esquema http');

    $reqSecureDirect = new Request('GET', '/', [], [], [], [], true);
    assertCondition($reqSecureDirect->getScheme() === 'https', '1.6 HTTPS directo (sin proxy) ⇒ esquema https');

    // -------------------------------------------------------------
    // 2. Fallback de desarrollo del servicio (contrato §1.2)
    // -------------------------------------------------------------
    echo "\n--- 2. Precedencia y Fallback de Desarrollo ---\n";

    $serviceReflection = new ReflectionClass(QrLabelService::class);
    $constructor = $serviceReflection->getConstructor();
    $baseUrlParam = $constructor !== null ? ($constructor->getParameters()[2] ?? null) : null;
    assertCondition(
        $baseUrlParam !== null && $baseUrlParam->getName() === 'baseUrl' && $baseUrlParam->getDefaultValue() === 'http://localhost',
        '2.1 Sin APP_BASE_URL ni host, el servicio usa el fallback de desarrollo http://localhost'
    );

    $labelMethod = $serviceReflection->getMethod('getMachineLabel');
    $labelParams = $labelMethod->getParameters();
    assertCondition(
        count($labelParams) === 4 && $labelParams[3]->getName() === 'baseUrl' && $labelParams[3]->isDefaultValueAvailable() && $labelParams[3]->getDefaultValue() === null,
        '2.2 getMachineLabel acepta la URL base contextual resuelta por el controlador'
    );

    $batchMethod = $serviceReflection->getMethod('getLocationBatch');
    $batchParams = $batchMethod->getParameters();
    assertCondition(
        count($batchParams) === 2 && $batchParams[1]->getName() === 'baseUrl' && $batchParams[1]->isDefaultValueAvailable(),
        '2.3 getLocationBatch acepta la URL base contextual resuelta por el controlador'
    );

    // -------------------------------------------------------------
    // 3. Pie de la etiqueta con host dinámico (sin dominios embebidos)
    // -------------------------------------------------------------
    echo "\n--- 3. Pie SVG con Host Resuelto ---\n";

    $config = new QrLabelConfig(
        'VEND-0101',
        'Sanden Vendo G-Drink',
        'PERISHABLE_FOOD',
        'Hospital del Mar - Edificio Central',
        'Planta Baja - Urgencias',
        '600111222',
        'https://qr.vendguard.example/?qr=VEND-0101&site=SEDE-BCN-01'
    );
    $svg = NativeSvgQrRenderer::renderLabel($config);

    assertCondition(
        str_contains($svg, 'VendGuard Security &amp; Safety · qr.vendguard.example'),
        '3.1 El pie de la etiqueta muestra el host del target URL resuelto'
    );
    assertCondition(!str_contains($svg, 'onrender'), '3.2 El SVG no contiene ningún dominio embebido');

    $configLocal = new QrLabelConfig(
        'VEND-0102',
        'Bianchi Gaia Espresso',
        'HOT_DRINKS',
        'Campus Nord UPC',
        'Edificio A - Hall',
        '934010000',
        'http://127.0.0.1:8000/?qr=VEND-0102&site=SEDE-BCN-02'
    );
    $svgLocal = NativeSvgQrRenderer::renderLabel($configLocal);
    assertCondition(str_contains($svgLocal, '127.0.0.1:8000'), '3.3 Una emisión local refleja el host local en el pie');

    // -------------------------------------------------------------
    // 4. Ausencia de dominios embebidos en el código de producción
    // -------------------------------------------------------------
    echo "\n--- 4. Auditoría de Dominios Embebidos (Enmienda 1) ---\n";

    $baseDir = __DIR__ . '/../../src';
    $productionFiles = [
        $baseDir . '/Application/Service/QrLabelService.php',
        $baseDir . '/Core/Domain/Service/NativeSvgQrRenderer.php',
        $baseDir . '/Core/Domain/Model/QrCodeData.php',
        $baseDir . '/Presentation/Controller/QrLabelController.php',
    ];

    foreach ($productionFiles as $index => $filePath) {
        $content = (string)file_get_contents($filePath);
        $fileName = basename($filePath);
        $assertionNumber = '4.' . ($index + 1);
        assertCondition(
            stripos($content, 'onrender') === false,
            "{$assertionNumber} {$fileName} no contiene dominios hardcodeados"
        );
    }

    echo "\n======================================================================\n";
    echo " RESULTADO: {$assertions} aserciones pasadas con éxito. CONDICIÓN T-QR-17 CUMPLIDA.\n";
    echo "======================================================================\n";
} catch (Throwable $t) {
    echo "\n[ERROR INESPERADO]: " . $t->getMessage() . "\n";
    echo $t->getTraceAsString() . "\n";
    exit(1);
}
