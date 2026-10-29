<?php

declare(strict_types=1);

/**
 * HttpsPolicyTest · RNF-06 / R2
 *
 * Certifica la política de transporte seguro del frontal: la PWA del técnico
 * necesita contexto seguro para Web Locks y Service Worker, de modo que el
 * despliegue no puede quedarse en HTTP plano por omisión.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **TLS ya presente.** `HTTPS=on` o un proxy con `X-Forwarded-Proto: https`
 *      no entran en bucle de redirección.
 *   2. **Bucle local exento.** `localhost`, `127.0.0.1`, `[::1]`, `0.0.0.0` y
 *      `*.localhost` son contexto seguro para el navegador y alojan el motor de
 *      desarrollo, así que no se redirigen.
 *   3. **HTTP público corregido.** Un host público sobre HTTP responde a la
 *      misma URL con `308`, conservando ruta y query.
 *   4. **Excepción explícita.** `VENDGUARD_ALLOW_INSECURE_HTTP=1` desactiva la
 *      redirección; cualquier otro valor la mantiene.
 *   5. **Saneamiento del host.** Un `Host` con saltos de línea, barras o puerto
 *      no numérico no produce redirección ni cabecera inyectable.
 *
 * Uso: php tests/unit/HttpsPolicyTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Presentation\Http\HttpsPolicy;

$assertions = 0;
$failures = 0;

$assert = static function (string $label, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "======================================================================\n";
echo " VENDGUARD · RNF-06 / R2 · Transporte seguro del frontal\n";
echo "======================================================================\n";

// Estado limpio de la excepción de desarrollo, sea cual sea el entorno.
putenv(HttpsPolicy::ALLOW_INSECURE_ENV);

// ─────────────────────────────────────────────────────────────────────────
// 1. Cuando ya hay TLS no hay redirección
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 1. TLS directo y terminado en proxy ---\n";

$assert(
    '1.1 HTTPS=on sobre un host público no redirige',
    HttpsPolicy::shouldRedirect(['HTTPS' => 'on', 'HTTP_HOST' => 'app.vendguard.example']) === false
);
$assert(
    '1.2 Un proxy con X-Forwarded-Proto: https no redirige',
    HttpsPolicy::shouldRedirect([
        'HTTP_HOST' => 'app.vendguard.example',
        'HTTP_X_FORWARDED_PROTO' => 'https'
    ]) === false
);
$assert(
    '1.3 La primera proto de una cadena de proxies es la que decide',
    HttpsPolicy::shouldRedirect([
        'HTTP_HOST' => 'app.vendguard.example',
        'HTTP_X_FORWARDED_PROTO' => 'https, http'
    ]) === false
);
$assert(
    '1.4 Sin HTTPS ni cabecera de proxy, el esquema es http',
    HttpsPolicy::requestScheme(['HTTP_HOST' => 'app.vendguard.example']) === 'http'
);

// ─────────────────────────────────────────────────────────────────────────
// 2. El bucle local no se redirige: ya es contexto seguro
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Bucle local y desarrollo ---\n";

$loopbackIndex = 0;
foreach ([
    'localhost:8000',
    '127.0.0.1:8000',
    '[::1]:8000',
    'app.localhost',
    '0.0.0.0:8080'
] as $loopback) {
    $loopbackIndex++;
    $assert(
        "2.{$loopbackIndex} El host local «{$loopback}» queda exento de la redirección",
        HttpsPolicy::shouldRedirect(['HTTP_HOST' => $loopback]) === false
    );
}

// ─────────────────────────────────────────────────────────────────────────
// 3. HTTP público: se corrige a la misma URL sobre HTTPS
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Redirección del host público ---\n";

$publicServer = [
    'HTTP_HOST' => 'app.vendguard.example',
    'REQUEST_URI' => '/api/technician/offline/bootstrap?full=1'
];

$assert(
    '3.1 Un host público sobre HTTP se redirige',
    HttpsPolicy::shouldRedirect($publicServer) === true
);
$assert(
    '3.2 El destino conserva ruta y query',
    HttpsPolicy::targetUrl($publicServer) === 'https://app.vendguard.example/api/technician/offline/bootstrap?full=1',
    HttpsPolicy::targetUrl($publicServer)
);
$assert(
    '3.3 El puerto 80 implícito no viaja al destino',
    HttpsPolicy::targetUrl(['HTTP_HOST' => 'app.vendguard.example:80', 'REQUEST_URI' => '/'])
        === 'https://app.vendguard.example/'
);
$assert(
    '3.4 Un puerto no estándar se conserva',
    HttpsPolicy::targetUrl(['HTTP_HOST' => 'app.vendguard.example:8443', 'REQUEST_URI' => '/x'])
        === 'https://app.vendguard.example:8443/x'
);

$assert(
    '3.5 La sonda de salud interna no se redirige',
    HttpsPolicy::shouldRedirect([
        'HTTP_HOST' => 'app.vendguard.example',
        'REQUEST_URI' => '/api/health'
    ]) === false
);

putenv(HttpsPolicy::ALLOW_INSECURE_ENV . '=1');
$assert(
    '3.6 La excepción explícita permite HTTP plano',
    HttpsPolicy::shouldRedirect($publicServer) === false && HttpsPolicy::allowsInsecureHttp() === true
);

putenv(HttpsPolicy::ALLOW_INSECURE_ENV . '=off');
$assert(
    '3.7 Un valor distinto de 1/true/yes/on no rebaja la política',
    HttpsPolicy::shouldRedirect($publicServer) === true && HttpsPolicy::allowsInsecureHttp() === false
);
putenv(HttpsPolicy::ALLOW_INSECURE_ENV);

// ─────────────────────────────────────────────────────────────────────────
// 4. Saneamiento: el host del cliente no arma cabeceras
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Host saneado ---\n";

$injectedHost = "evil.example\r\nX-Injected: 1";
$assert(
    '4.1 Un Host con saltos de línea no produce redirección',
    HttpsPolicy::requestHost(['HTTP_HOST' => $injectedHost]) === ''
        && HttpsPolicy::shouldRedirect(['HTTP_HOST' => $injectedHost]) === false
);
$assert(
    '4.2 Un Host con barras o espacios tampoco',
    HttpsPolicy::requestHost(['HTTP_HOST' => 'evil.example/path']) === ''
        && HttpsPolicy::requestHost(['HTTP_HOST' => 'bad host']) === ''
);
$assert(
    '4.3 Sin Host utilizable se recurre a SERVER_NAME',
    HttpsPolicy::requestHost([
        'HTTP_HOST' => 'evil.example/path',
        'SERVER_NAME' => 'app.vendguard.example'
    ]) === 'app.vendguard.example'
);
$assert(
    '4.4 Una ruta con saltos de línea no se cuela en la cabecera',
    HttpsPolicy::targetUrl([
        'HTTP_HOST' => 'app.vendguard.example',
        'REQUEST_URI' => "/x\r\nSet-Cookie: sid=1"
    ]) === 'https://app.vendguard.example/x'
);
$assert(
    '4.5 Un puerto no numérico invalida el host',
    HttpsPolicy::requestHost(['HTTP_HOST' => 'app.vendguard.example:8o80']) === ''
);
$assert(
    '4.6 Sin host válido no se construye destino vacío',
    HttpsPolicy::targetUrl(['HTTP_HOST' => '']) === ''
);

// ─────────────────────────────────────────────────────────────────────────
// 5. HSTS
// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 5. Cabecera HSTS ---\n";

$assert(
    '5.1 HSTS declara un año y cubre subdominios',
    str_contains(HttpsPolicy::HSTS_HEADER, 'max-age=31536000')
        && str_contains(HttpsPolicy::HSTS_HEADER, 'includeSubDomains')
        && str_starts_with(HttpsPolicy::HSTS_HEADER, 'Strict-Transport-Security:')
);

echo "\n======================================================================\n";
echo " Total Assertions: {$assertions} | Passed: " . ($assertions - $failures) . " | Failed: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% IN GREEN. R2 HTTPS POLICY CERTIFIED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILED IN {$failures} ASSERTIONS.\n";
echo "======================================================================\n";
exit(1);
