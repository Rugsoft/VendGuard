<?php

declare(strict_types=1);

/**
 * WebhookUrlSecurityValidatorTest
 *
 * Suite de pruebas unitarias para el validador de seguridad anti-SSRF de Webhooks (T-NOTIF-03, RF-06.5.1, RNF-04).
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Service\WebhookUrlSecurityValidator;

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - WebhookUrlSecurityValidatorTest\n";
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

$assertThrows = function (string $caseTitle, string $url, string $expectedMessageContains = '') use ($assert): void {
    try {
        WebhookUrlSecurityValidator::validate($url);
        $assert($caseTitle, false, 'Expected InvalidArgumentException was not thrown for URL: ' . $url);
    } catch (InvalidArgumentException $e) {
        $msgOk = $expectedMessageContains === '' || str_contains($e->getMessage(), $expectedMessageContains);
        $assert($caseTitle, $msgOk, "Message was: {$e->getMessage()}");
    }
};

// 1. Esquema HTTPS obligatorio y rechazo de esquemas inseguros
echo "--- Esquema HTTPS obligatorio vs esquemas no seguros ---\n";
$assertThrows('Rechazo de http://', 'http://webhook.vendguard.com/alerts', 'Only secure HTTPS');
$assertThrows('Rechazo de ftp://', 'ftp://files.example.com/webhook', 'Only secure HTTPS');
$assertThrows('Rechazo de esquema vacío / relativo', '/api/emergency-channel', 'Malformed webhook URL');
$assertThrows('Rechazo de string vacío', '', 'cannot be empty');

// 2. Bloqueo de localhost y nombres de dominios internos
echo "--- Bloqueo de localhost y redes locales por nombre ---\n";
$assertThrows('Rechazo de localhost', 'https://localhost/webhook', 'Localhost and local network domains');
$assertThrows('Rechazo de localhost.localdomain', 'https://localhost.localdomain/hook', 'Localhost and local network domains');
$assertThrows('Rechazo de subdominio .localhost', 'https://sub.localhost/hook', 'Localhost and local network domains');
$assertThrows('Rechazo de dominio .local', 'https://my-service.local/hook', 'Localhost and local network domains');
$assertThrows('Rechazo de dominio .internal', 'https://kube.internal/hook', 'Localhost and local network domains');
$assertThrows('Rechazo de host simple sin punto', 'https://internal-server/hook', 'Single-label hosts');

// 3. Bloqueo de loopback e IPs privadas IPv4 (RFC 1918 / RFC 3927)
echo "--- Bloqueo de direcciones IP de bucle e IPs privadas IPv4 ---\n";
$assertThrows('Bloqueo de 127.0.0.1 (Loopback)', 'https://127.0.0.1/webhook', 'Private, loopback and link-local');
$assertThrows('Bloqueo de 127.0.0.2', 'https://127.0.0.2:8443/webhook', 'Private, loopback and link-local');
$assertThrows('Bloqueo de 0.0.0.0', 'https://0.0.0.0/webhook', 'Private, loopback and link-local');
$assertThrows('Bloqueo de 10.0.0.1 (Clase A privada)', 'https://10.0.0.1/webhook', 'Private, loopback and link-local');
$assertThrows('Bloqueo de 10.254.254.1', 'https://10.254.254.1/webhook', 'Private, loopback and link-local');
$assertThrows('Bloqueo de 172.16.0.1 (Clase B privada)', 'https://172.16.0.1/webhook', 'Private, loopback and link-local');
$assertThrows('Bloqueo de 172.31.255.254 (Límite Clase B)', 'https://172.31.255.254/webhook', 'Private, loopback and link-local');
$assertThrows('Bloqueo de 192.168.1.1 (Clase C privada)', 'https://192.168.1.1/webhook', 'Private, loopback and link-local');
$assertThrows('Bloqueo de 169.254.169.254 (Cloud Metadata / Link-local)', 'https://169.254.169.254/latest/meta-data', 'Private, loopback and link-local');
$assertThrows('Bloqueo de 100.64.0.1 (Carrier Grade NAT)', 'https://100.64.0.1/webhook', 'Private, loopback and link-local');

// 4. Bloqueo de IPv6 privadas y loopback
echo "--- Bloqueo de IPv6 privadas, loopback y link-local ---\n";
$assertThrows('Bloqueo de [::1] (IPv6 loopback)', 'https://[::1]/webhook', 'Private, loopback and link-local');
$assertThrows('Bloqueo de fe80:: (IPv6 link-local)', 'https://[fe80::1]/webhook', 'Private, loopback and link-local');
$assertThrows('Bloqueo de fc00:: (IPv6 unique local)', 'https://[fc00::1]/webhook', 'Private, loopback and link-local');

// 5. Aceptación de URLs válidas y dominios públicos
echo "--- Aceptación de URLs HTTPS públicas válidas ---\n";
$valid1 = 'https://webhook.example.com/alerts/dispatch';
$assert('Webhook HTTPS genérico válido aceptado', WebhookUrlSecurityValidator::validate($valid1) === $valid1);
$assert('Webhook HTTPS genérico isValid() devuelve true', WebhookUrlSecurityValidator::isValid($valid1));

$valid2 = 'https://events.example.org/webhook-receiver';
$assert('Webhook HTTPS institucional válido aceptado', WebhookUrlSecurityValidator::validate($valid2) === $valid2);

$valid3 = 'https://notifications.service.net/vending-channel';
$assert('Webhook HTTPS alternativo válido aceptado', WebhookUrlSecurityValidator::validate($valid3) === $valid3);

$valid4 = 'https://alerts.my-company.org:8443/vending/emergency';
$assert('Webhook con puerto HTTPS explícito aceptado', WebhookUrlSecurityValidator::validate($valid4) === $valid4);

$validIp = 'https://93.184.216.34/webhook'; // IP pública de example.com
$assert('IP pública válida aceptada', WebhookUrlSecurityValidator::validate($validIp) === $validIp);

echo "\nResultado: {$assertions} evaluadas, {$failures} fallidas.\n";
if ($failures > 0) {
    exit(1);
}
