<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Unit suite for `ClaimantIdentity`, the canonical form of a claimant contact.
 *
 * ## Why a whole suite for a value object
 * The duplicate rule (RF-REF-11) is only as strong as this comparison, and its
 * first version was defeated by typing the same phone number differently. A
 * green suite that only proves "600 111 222 equals 600-111-222" would have
 * missed the `+34`, the non-breaking space and the underscore, which is exactly
 * what happened before. So the cases that defeated the old implementation are
 * first-class citizens here, alongside the ones that must stay DIFFERENT:
 * canonicalisation that merges two real people is a worse bug than the one it
 * fixes.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Core\Domain\ValueObject\ClaimantIdentity;

$assertions = 0;
$failures = 0;

$assert = function (string $label, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$label}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
};

echo "\n--- 1. El mismo teléfono escrito de cinco formas es la misma persona ---\n";

$samePhone = [
    '1.1a' => ['600123456', '600 123 456', 'El número tal cual lo teclea el usuario'],
    '1.1b' => ['600123456', '600-123-456', 'Con guiones'],
    '1.1c' => ['600123456', '600.123.456', 'Con puntos'],
    '1.1d' => ['600123456', '+34 600 123 456', 'Con el prefijo del país'],
    '1.1e' => ['600123456', '0034 600123456', 'Con el prefijo internacional'],
    '1.1f' => ['600123456', "600\u{00A0}123\u{00A0}456", 'Con espacio duro (U+00A0)'],
    '1.1g' => ['600123456', "600\u{2009}123456", 'Con espacio fino (U+2009)'],
    '1.1h' => ['600123456', '600_123_456', 'Con guiones bajos'],
    '1.1i' => ['600123456', '  600123456  ', 'Con espacios exteriores'],
    '1.1j' => ['600123456', '600123456', 'Idéntico'],
];

foreach ($samePhone as $label => [$left, $right, $why]) {
    $assert(
        $label . ' ' . $right . ' es la MISMA persona que ' . $left . ' (' . $why . ')',
        ClaimantIdentity::from($left)->equals(ClaimantIdentity::from($right))
    );
}

echo "\n--- 2. Personas distintas NO se fusionan ---\n";

$differentPhones = [
    '2.1' => ['600111222', '600999888'],
    '2.2' => ['600111222', '699888777'],
    '2.3' => ['600111222', '600111223'],
];

foreach ($differentPhones as $label => [$left, $right]) {
    $assert(
        $label . ' ' . $left . ' y ' . $right . ' son personas DISTINTAS',
        !ClaimantIdentity::from($left)->equals(ClaimantIdentity::from($right))
    );
}

// Un prefijo que no deja nueve dígitos NO es un prefijo: `34 123 456` son siete
// dígitos y su identidad no puede recortarse a los seis últimos.
$assert(
    '2.4 Un número corto que empieza por 34 NO pierde dígitos por el prefijo',
    ClaimantIdentity::from('34 123 456')->key() === '34123456',
    'clave: ' . ClaimantIdentity::from('34 123 456')->key()
);

$assert(
    '2.5 Un número de nueve dígitos que empieza por 34 conserva sus dígitos',
    ClaimantIdentity::from('341112222')->key() === '341112222',
    'clave: ' . ClaimantIdentity::from('341112222')->key()
);

echo "\n--- 3. El correo se normaliza sin destruir sus signos ---\n";

$assert(
    '3.1 El correo sólo pierde mayúsculas y espacios exteriores',
    ClaimantIdentity::from('  Laura.Sanitaria@Example.COM ')->equals(
        ClaimantIdentity::from('laura.sanitaria@example.com')
    )
);

$assert(
    '3.2 Un punto de más NO es el mismo correo (el punto es significativo)',
    !ClaimantIdentity::from('laura.sanitaria@example.com')->equals(
        ClaimantIdentity::from('laurasanitaria@example.com')
    )
);

$assert(
    '3.3 Un correo nunca colisiona con un teléfono',
    !ClaimantIdentity::from('laura@example.com')->equals(ClaimantIdentity::from('600123456'))
);

echo "\n--- 4. Contactos sin sentido no colisionan entre sí ---\n";

$assert(
    '4.1 Un contacto sin dígitos usa el texto recortado en minúsculas',
    ClaimantIdentity::from('  Sin Numero  ')->key() === 'sin numero',
    'clave: ' . ClaimantIdentity::from('  Sin Numero  ')->key()
);

$assert(
    '4.2 Dos contactos sin dígitos distintos NO son la misma persona',
    !ClaimantIdentity::from('Sin Numero')->equals(ClaimantIdentity::from('Otro Apodo'))
);

$assert(
    '4.3 Dos guiones sin dígitos NO son todos la misma persona',
    !ClaimantIdentity::from('---')->equals(ClaimantIdentity::from('...'))
);

$rejectedBlank = null;
try {
    ClaimantIdentity::from('   ');
} catch (Throwable $e) {
    $rejectedBlank = $e;
}
$assert(
    '4.4 Un contacto vacío se rechaza: no es una identidad',
    $rejectedBlank instanceof InvalidArgumentException
);

echo "\n--- 5. Lo que se guarda NO es lo que se compara ---\n";

$typed = ClaimantIdentity::from('+34 600 123 456');
$assert(
    '5.1 La clave canónica no lleva el prefijo del país',
    $typed->key() === '600123456',
    'clave: ' . $typed->key()
);
$assert(
    '5.2 El contacto tal como lo escribió la persona se conserva intacto',
    $typed->typedContact() === '+34 600 123 456',
    'contacto: ' . $typed->typedContact()
);
$assert(
    '5.3 La identidad se reconstruye estable desde el mismo contacto',
    ClaimantIdentity::from('+34 600 123 456')->equals(ClaimantIdentity::from($typed->typedContact()))
    && $typed->typedContact() === '+34 600 123 456'
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Identidad canónica CERTIFICADA (RF-REF-11).\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);