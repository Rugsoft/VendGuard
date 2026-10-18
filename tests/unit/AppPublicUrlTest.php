<?php

declare(strict_types=1);

/**
 * AppPublicUrlTest
 *
 * Suite unitaria del host público canónico de los enlaces que salen del sistema
 * (correos de seguimiento del Centro de Notificaciones):
 *   1. Sin host configurado la ruta se entrega tal cual (degradación explícita).
 *   2. Con `APP_BASE_URL` la ruta relativa se convierte en URL absoluta, con o
 *      sin barra inicial y sin duplicar barras.
 *   3. Los enlaces ya absolutos (`https://…`, `mailto:…`) no se reescriben.
 *   4. Sólo se aceptan orígenes `http`/`https`: un esquema peligroso en la
 *      variable de entorno se descarta y el botón no se convierte en un vector.
 *   5. Lectura de `APP_BASE_URL` desde el entorno del proceso.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin red, sin base de datos y sin reloj.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Service\AppPublicUrl;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - AppPublicUrlTest\n";
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

echo "--- 1. Sin host configurado ---\n";

$unconfigured = new AppPublicUrl();
$assert('1.1 Un origen vacío no se considera configurado', $unconfigured->isConfigured() === false);
$assert('1.2 Sin host, la ruta de Sede se entrega tal cual', $unconfigured->absolute('/?site=SEDE-BCN-01&ticket=INC-1') === '/?site=SEDE-BCN-01&ticket=INC-1');
$assert('1.3 Sin host, la ruta QR se entrega tal cual', $unconfigured->absolute('/?qr=VEND-0101') === '/?qr=VEND-0101');
$assert('1.4 Un enlace nulo sigue siendo nulo', $unconfigured->absolute(null) === null);
$assert('1.5 Un enlace vacío se descarta', $unconfigured->absolute('   ') === null);

echo "\n--- 2. Host público declarado ---\n";

$configured = new AppPublicUrl('https://vendguard.onrender.com');
$assert('2.1 El origen se normaliza sin barra final', $configured->baseUrl() === 'https://vendguard.onrender.com');
$assert('2.2 El origen declarado se considera configurado', $configured->isConfigured() === true);
$assert(
    '2.3 La ruta de Sede sale absoluta con su ticket',
    $configured->absolute('/?site=SEDE-BCN-01&ticket=INC-2026-000123')
        === 'https://vendguard.onrender.com/?site=SEDE-BCN-01&ticket=INC-2026-000123'
);
$assert(
    '2.4 La ruta QR sale absoluta',
    $configured->absolute('/?qr=VEND-0101') === 'https://vendguard.onrender.com/?qr=VEND-0101'
);
$assert(
    '2.5 Una ruta sin barra inicial también se prefija',
    $configured->absolute('?qr=VEND-0101') === 'https://vendguard.onrender.com/?qr=VEND-0101'
);
$assert(
    '2.6 Un origen con barra final no duplica la barra',
    (new AppPublicUrl('https://vendguard.example/'))->absolute('/?qr=VEND-0101') === 'https://vendguard.example/?qr=VEND-0101'
);
$assert(
    '2.7 Un origen con puerto y ruta conserva el prefijo',
    (new AppPublicUrl('http://127.0.0.1:8000/vendguard'))->absolute('/?qr=VEND-0101')
        === 'http://127.0.0.1:8000/vendguard/?qr=VEND-0101'
);
$assert(
    '2.8 Un enlace relativo al esquema recibe el esquema del host',
    $configured->absolute('//cdn.example.com/x') === 'https://cdn.example.com/x'
);

echo "\n--- 3. Los enlaces ya absolutos no se reescriben ---\n";

$assert(
    '3.1 Un tracking_url absoluto conserva su dominio de origen',
    $configured->absolute('https://otro-dominio.example/?site=X&ticket=Y') === 'https://otro-dominio.example/?site=X&ticket=Y'
);
$assert(
    '3.2 Un enlace mailto no se prefija',
    $configured->absolute('mailto:coordinacion@vendguard.internal') === 'mailto:coordinacion@vendguard.internal'
);
$assert(
    '3.3 Un esquema no navegable en el payload se descarta (no llega al href)',
    $configured->absolute('javascript:alert(1)') === null
    && $unconfigured->absolute('javascript:alert(1)') === null
);

echo "\n--- 4. Endurecimiento del origen declarado ---\n";

$javascriptOrigin = new AppPublicUrl('javascript:alert(1)//');
$assert('4.1 Un origen javascript: se descarta', $javascriptOrigin->isConfigured() === false);
$assert(
    '4.2 Con origen inseguro la ruta queda relativa y no se ejecuta nada',
    $javascriptOrigin->absolute('/?qr=VEND-0101') === '/?qr=VEND-0101'
);
$assert('4.3 Un origen ftp: se descarta', (new AppPublicUrl('ftp://files.example'))->isConfigured() === false);
$assert('4.4 Un origen con espacios internos se descarta', (new AppPublicUrl('https://vend guard.example'))->isConfigured() === false);
$assert('4.5 Un host ausente se descarta', (new AppPublicUrl('https://'))->isConfigured() === false);

echo "\n--- 5. Lectura del entorno ---\n";

$previousEnv = getenv(AppPublicUrl::ENV_NAME);
$previousAliasEnv = getenv('VENDGUARD_APP_URL');
putenv(AppPublicUrl::ENV_NAME . '=https://sede.vendguard.example/');
$fromEnv = AppPublicUrl::fromEnvironment();
$assert('5.1 APP_BASE_URL se lee del entorno del proceso', $fromEnv->baseUrl() === 'https://sede.vendguard.example');
$assert(
    '5.2 El host leído del entorno absolutiza el enlace de seguimiento',
    $fromEnv->absolute('/?site=SEDE-BCN-01&ticket=INC-1')
        === 'https://sede.vendguard.example/?site=SEDE-BCN-01&ticket=INC-1'
);

putenv(AppPublicUrl::ENV_NAME);
putenv('VENDGUARD_APP_URL=https://alias.vendguard.example');
$fromAliasEnv = AppPublicUrl::fromEnvironment();
$assert(
    '5.3 VENDGUARD_APP_URL sirve de respaldo cuando APP_BASE_URL no está declarada',
    $fromAliasEnv->baseUrl() === 'https://alias.vendguard.example'
);

if ($previousEnv === false) {
    putenv(AppPublicUrl::ENV_NAME);
} else {
    putenv(AppPublicUrl::ENV_NAME . '=' . $previousEnv);
}

if ($previousAliasEnv === false) {
    putenv('VENDGUARD_APP_URL');
} else {
    putenv('VENDGUARD_APP_URL=' . $previousAliasEnv);
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Pasadas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: VERDE. AppPublicUrl entrega enlaces absolutos sin inventar dominios.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLIDO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
