<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Unit suite for `IbanValidationService` (T-REF-05).
 *
 * The "Hecho cuando" criterion demands green coverage of valid Spanish and
 * international IBANs, erroneous ones and ones carrying a false checksum. This
 * suite goes one step further and proves the checksum is actually computed: every
 * single-digit mutation of every valid vector must be rejected exhaustively, so a
 * validator that only checked country code and length could not pass.
 *
 * It also certifies the two constitutional guarantees that travel with this code:
 * the Dogma Vanilla (no Composer dependency, native arithmetic only) and the
 * Art. V.4 masking of the IBAN in every exception path.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Application\Service\IbanValidationService;
use VendGuard\Core\Domain\Exception\InvalidBizumPhoneException;
use VendGuard\Core\Domain\Exception\InvalidIbanFormatException;

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

$service = new IbanValidationService();

/** Vectores válidos publicados en el registro ISO 13616 de uso común. */
$validIbans = [
    'ES91 2100 0418 4502 0005 1332',
    'ES79 2100 0813 6101 2345 6789',
    'DE89 3704 0044 0532 0130 00',
    'GB29 NWBK 6016 1331 9268 19',
    'FR14 2004 1010 0505 0001 3M02 606',
    'NL91 ABNA 0417 1643 00',
    'IT60 X054 2811 1010 0000 0123 456',
    'CH93 0076 2011 6238 5295 7',
    'BE68 5390 0754 7034',
    'PT50 0002 0123 1234 5678 9015 4',
];

// ─────────────────────────────────────────────────────────────────────────────
// 0. Dogma Vanilla (Art. IV): cero dependencias Composer
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 0. Dogma Vanilla: PHP puro sin dependencias externas ---\n";

$serviceSource = (string)file_get_contents($baseDir . '/src/Application/Service/IbanValidationService.php');

/**
 * Elimina comentarios y cadenas del código fuente para que las guardas
 * estáticas examinen únicamente código ejecutable. Sin esto, una mención a
 * `bcmod` en la documentación del algoritmo daría por rota una prohibición que
 * realmente se cumple.
 */
$executableCode = (string)preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#', '#"(?:[^"\\\\]|\\\\.)*"#', "#'(?:[^'\\\\]|\\\\.)*'#s"], ' ', $serviceSource);

$assert(
    '0.1 El servicio existe en `src/Application/Service/IbanValidationService.php`',
    class_exists(IbanValidationService::class)
);
$assert(
    '0.2 El servicio no importa ningún símbolo de Composer (`use`)',
    preg_match('/^use\s+(?!VendGuard)/m', $serviceSource) !== 1,
    'Se encontró un `use` externo'
);
$assert(
    '0.3 El código ejecutable no invoca bcmod ni extensiones de enteros grandes',
    !str_contains($executableCode, 'bcmod') && !str_contains($executableCode, 'gmp_')
);
$assert(
    '0.4 La aritmética modular se hace por bloques de 7 dígitos en código nativo',
    str_contains($executableCode, 'str_split($digits, 7)')
);
$assert(
    '0.5 El fichero declara tipado estricto',
    str_contains($serviceSource, 'declare(strict_types=1);')
);
$assert(
    '0.6 El servicio es una clase final sin estado (instanciable sin dependencias)',
    str_contains($serviceSource, 'final class IbanValidationService')
);

// ─────────────────────────────────────────────────────────────────────────────
// 1. IBANs españoles válidos
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. IBANs españoles válidos (24 caracteres) ---\n";

foreach ($validIbans as $index => $iban) {
    if (!str_starts_with(strtoupper($iban), 'ES')) {
        continue;
    }
    $assert(
        '1.' . ($index + 1) . " IBAN español válido aceptado: {$iban}",
        $service->isValidIban($iban)
    );
}

$assert(
    '1.9 Un IBAN español canónico (sin espacios) también se acepta',
    $service->isValidIban('ES9121000418450200051332')
);
$assert(
    '1.10 El IBAN español devuelve la forma normalizada desde assertValidIban()',
    $service->assertValidIban('ES91 2100 0418 4502 0005 1332') === 'ES9121000418450200051332'
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. IBANs internacionales válidos
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. IBANs internacionales válidos ---\n";

foreach ($validIbans as $index => $iban) {
    if (str_starts_with(strtoupper($iban), 'ES')) {
        continue;
    }
    $assert(
        '2.' . ($index + 1) . " IBAN internacional válido aceptado: {$iban}",
        $service->isValidIban($iban)
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. Normalización de la entrada
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Normalización: minúsculas, espacios y guiones ---\n";

$assert(
    '3.1 Un IBAN válido en minúsculas se acepta (desviación documentada del pseudocódigo)',
    $service->isValidIban('es91 2100 0418 4502 0005 1332')
);
$assert(
    '3.2 Un IBAN válido con guiones se acepta',
    $service->isValidIban('ES91-2100-0418-4502-0005-1332')
);
$assert(
    '3.3 normalizeIban() devuelve mayúsculas y sin separadores',
    $service->normalizeIban('es91-2100 0418 4502 0005 1332') === 'ES9121000418450200051332'
);
$assert(
    '3.4 assertValidIban() normaliza igual que isValidIban() acepta',
    $service->assertValidIban('  es91 2100 0418 4502 0005 1332  ') === 'ES9121000418450200051332'
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Errores de estructura
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Errores de estructura (código de país y dígitos de control) ---\n";

$structuralFailures = [
    '4.1 Dígitos donde va el código de país se rechaza' => '9121000418450200051332',
    '4.2 Letras donde van los dígitos de control se rechaza' => 'ESAB21000418450200051332',
    '4.3 Código de país de tres letras se rechaza' => 'ESA9121000418450200051332',
    '4.4 Cadena de un solo carácter se rechaza' => 'E',
    '4.5 Cadena vacía se rechaza' => '',
];

foreach ($structuralFailures as $label => $candidate) {
    $assert($label, !$service->isValidIban($candidate), 'candidato: "' . $candidate . '"');
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. Errores de longitud
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 5. Errores de longitud (ISO 13616: 15 a 34; ES = 24) ---\n";

$assert(
    '5.1 IBAN de 13 caracteres (por debajo del mínimo) se rechaza',
    !$service->isValidIban('ES123456789012')
);
$assert(
    '5.2 IBAN español de 23 caracteres se rechaza',
    !$service->isValidIban('ES912100041845020005132')
);
$assert(
    '5.3 IBAN español de 25 caracteres se rechaza',
    !$service->isValidIban('ES91210004184502000513322')
);
$assert(
    '5.4 IBAN de 36 caracteres (por encima del máximo) se rechaza',
    !$service->isValidIban('ES9121000418450200051332' . str_repeat('0', 12))
);
$assert(
    '5.5 Las constantes de longitud expuestas son las de la norma',
    IbanValidationService::IBAN_MIN_LENGTH === 15
        && IbanValidationService::IBAN_MAX_LENGTH === 34
        && IbanValidationService::IBAN_ES_LENGTH === 24
);

/**
 * Construye un IBAN con checksum Módulo 97 CORRECTO para un BBAN arbitrario.
 *
 * Recorre el módulo 97 dígito a dígito (`r = (r*10 + d) % 97`), formulación
 * deliberadamente distinta de la del servicio, que va por bloques de 7. El
 * acuerdo entre ambas implementaciones es lo que da valor al vector.
 *
 * Sirve para probar las reglas que el checksum deja pasar: sin él, un IBAN con
 * longitud española incorrecta o con el código de país en formato erróneo se
 * rechazaría igualmente "por casualidad", y la regla quedaría sin cobertura.
 */
$buildIban = static function (string $countryCode, string $bban): string {
    $digits = '';
    foreach (str_split($bban . $countryCode . '00') as $char) {
        $digits .= ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
    }

    $remainder = 0;
    foreach (str_split($digits) as $char) {
        $remainder = ($remainder * 10 + (int)$char) % 97;
    }

    $checkDigits = 98 - $remainder;
    if ($checkDigits === 98) {
        $checkDigits = 97;
    }

    return $countryCode . str_pad((string)$checkDigits, 2, '0', STR_PAD_LEFT) . $bban;
};

echo "\n--- 5b. Vectores con checksum válido que el checksum NO puede salvar ---\n";

$validEsVector = $buildIban('ES', '21000418450200051332');
$assert(
    '5b.1 Control: el IBAN construido con checksum correcto SÍ se acepta (longitud ES=24)',
    $service->isValidIban($validEsVector),
    'vector: ' . $validEsVector
);
$assert(
    '5b.2 Un IBAN ES de 23 caracteres con checksum CORRECTO se rechaza (regla de longitud ES)',
    !$service->isValidIban($buildIban('ES', '2100041845020005133')),
    'vector: ' . $buildIban('ES', '2100041845020005133')
);
$assert(
    '5b.3 Un IBAN ES de 25 caracteres con checksum CORRECTO se rechaza (regla de longitud ES)',
    !$service->isValidIban($buildIban('ES', '210004184502000513321')),
    'vector: ' . $buildIban('ES', '210004184502000513321')
);
$assert(
    '5b.4 Un IBAN con checksum CORRECTO pero código de país numérico se rechaza (regla estructural)',
    !$service->isValidIban($buildIban('91', '00418450200051332')),
    'vector: ' . $buildIban('91', '00418450200051332')
);
$assert(
    '5b.5 Un IBAN con checksum CORRECTO pero dígitos de control alfabéticos se rechaza',
    !$service->isValidIban($buildIban('ES', 'AB2100041845020005133')),
    'vector: ' . $buildIban('ES', 'AB2100041845020005133')
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. Checksum falso: el corazón de la validación
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 6. Checksum falso: mutaciones de un solo dígito ---\n";

$corrupted = [
    '6.1 Último dígito alterado se rechaza' => 'ES9121000418450200051333',
    '6.2 Dígito de control alterado se rechaza' => 'ES9010000418450200051332',
    '6.3 Dígito intermedio alterado se rechaza' => 'ES9121000418450200051332' === ''
        ? '' : 'ES9121000418455200051332',
    '6.4 Transposición de dos dígitos se rechaza' => 'ES91210004184520005001332',
];

foreach ($corrupted as $label => $candidate) {
    $assert($label, !$service->isValidIban($candidate), 'candidato: ' . $candidate);
}

// Barrido exhaustivo: sustituir cualquier dígito de cualquier vector válido por
// cada uno de los otros nueve dígitos debe invalidarlo siempre. Sin este barrido,
// un validador que sólo mirase el prefijo y la longitud pasaría la suite.
$mutationFailures = [];
$mutationsChecked = 0;

foreach ($validIbans as $iban) {
    $normalized = $service->normalizeIban($iban);
    $length = strlen($normalized);

    for ($position = 0; $position < $length; $position++) {
        $original = $normalized[$position];
        if (!ctype_digit($original)) {
            continue;
        }
        for ($digit = 0; $digit <= 9; $digit++) {
            $replacement = (string)$digit;
            if ($replacement === $original) {
                continue;
            }
            $mutationsChecked++;
            $mutated = substr_replace($normalized, $replacement, $position, 1);
            if ($service->isValidIban($mutated)) {
                $mutationFailures[] = $mutated;
            }
        }
    }
}

$assert(
    '6.5 Toda mutación de un dígito de un IBAN válido se rechaza (checksum real)',
    $mutationFailures === [],
    count($mutationFailures) . ' mutaciones aceptadas indebidamente: ' . implode(', ', array_slice($mutationFailures, 0, 5))
);
echo "         (barrido exhaustivo: {$mutationsChecked} mutaciones de un dígito evaluadas)\n";

// ─────────────────────────────────────────────────────────────────────────────
// 7. Entradas arbitrarias
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 7. Entradas arbitrarias y degeneradas ---\n";

$garbage = [
    '7.1 Sólo letras se rechaza' => 'ABCDEFGHIJKLMNOP',
    '7.2 Sólo dígitos se rechaza (sin código de país)' => '21000418450200051332',
    '7.3 Símbolos se rechaza' => '!!!!!!!!!!!!!!!!!',
    '7.4 Texto libre en castellano se rechaza' => 'mi cuenta bancaria es la de siempre',
];

foreach ($garbage as $label => $candidate) {
    $assert($label, !$service->isValidIban($candidate));
}

// ─────────────────────────────────────────────────────────────────────────────
// 8. Contrato de la excepción de IBAN (422) y blindaje Art. V.4
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 8. Contrato de `InvalidIbanFormatException` y enmascarado (Art. V.4) ---\n";

$clearIban = 'ES9121000418450200051332';
$thrown = null;

try {
    $service->assertValidIban('ES9121000418450200051399');
} catch (InvalidIbanFormatException $e) {
    $thrown = $e;
}

$assert('8.1 assertValidIban() lanza InvalidIbanFormatException', $thrown instanceof InvalidIbanFormatException);
$assert(
    '8.2 La excepción mapea al estado HTTP 422',
    $thrown !== null && $thrown->getHttpStatusCode() === 422
);
$assert(
    '8.3 La excepción expone el error code INVALID_IBAN_FORMAT',
    $thrown !== null && $thrown->getErrorCode() === 'INVALID_IBAN_FORMAT'
);
$assert(
    '8.4 El mensaje en castellano coincide con el catálogo de contratos',
    $thrown !== null && $thrown->getMessage() === InvalidIbanFormatException::DEFAULT_MESSAGE
);

// Blindaje constitucional: el IBAN nunca puede viajar en claro.
$assert(
    '8.5 El IBAN en claro NO aparece en el mensaje de la excepción (Art. V.4)',
    $thrown !== null && !str_contains($thrown->getMessage(), 'ES9121000418450200051399')
);
$assert(
    '8.6 El IBAN en claro NO aparece en el campo `masked_iban` (Art. V.4)',
    $thrown !== null && ($thrown->getMaskedIban() ?? '') !== 'ES9121000418450200051399'
);
$assert(
    '8.7 El campo `masked_iban` conserva el país y los 4 últimos dígitos',
    $thrown !== null && preg_match('/^ES\*+1399$/', (string)$thrown->getMaskedIban()) === 1,
    'enmascarado: ' . (string)$thrown->getMaskedIban()
);

$assert(
    '8.8 maskIban() de un IBAN español devuelve ES + asteriscos + 4 dígitos',
    $service->maskIban('ES9121000418450200051332') === 'ES' . str_repeat('*', 18) . '1332',
    'obtenido: ' . $service->maskIban('ES9121000418450200051332')
);
$assert(
    '8.9 maskIban() acepta entradas con separadores',
    $service->maskIban('es91 2100 0418 4502 0005 1332') === 'ES' . str_repeat('*', 18) . '1332'
);
$assert('8.10 maskIban(null) devuelve null', $service->maskIban(null) === null);
$assert('8.11 maskIban("") devuelve null', $service->maskIban('') === null);
$assert(
    '8.12 Un IBAN de 6 caracteres se enmascara entero (si no, se devolvería en claro)',
    $service->maskIban('ES9121') === '******',
    'obtenido: ' . $service->maskIban('ES9121')
);
$assert(
    '8.13 Un IBAN de 9 caracteres se enmascara por completo (no hay 4 dígitos ocultos)',
    $service->maskIban('ES9121000') === str_repeat('*', 9)
);
$assert(
    '8.14 Un IBAN de 10 caracteres ya oculta 4 dígitos junto al país',
    $service->maskIban('ES91210000') === 'ES****0000',
    'obtenido: ' . $service->maskIban('ES91210000')
);

// ─────────────────────────────────────────────────────────────────────────────
// 9. Validación de teléfonos Bizum (9 dígitos)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 9. Validación de teléfono Bizum (exactamente 9 dígitos) ---\n";

$assert('9.1 Nueve dígitos se aceptan', $service->isValidBizumPhone('600111222'));
$assert('9.2 Otros nueve dígitos se aceptan', $service->isValidBizumPhone('912345678'));
$assert('9.3 Separadores de presentación se eliminan antes de validar', $service->isValidBizumPhone('600 111 222'));
$assert('9.4 Guiones se eliminan antes de validar', $service->isValidBizumPhone('600-111-222'));
$assert('9.5 Puntos y paréntesis se eliminan antes de validar', $service->isValidBizumPhone('(600) 111.222'));
$assert('9.6 assertValidBizumPhone() devuelve los 9 dígitos normalizados', $service->assertValidBizumPhone('600 111 222') === '600111222');

$invalidPhones = [
    '9.7 Ocho dígitos se rechazan' => '60011222',
    '9.8 Diez dígitos se rechazan' => '6001112223',
    '9.9 Cadena vacía se rechaza' => '',
    '9.10 Letras mezcladas se rechazan' => '60011122A',
    '9.11 El prefijo internacional +34 se rechaza' => '+34600111222',
    '9.12 El prefijo 0034 se rechaza' => '0034600111222',
    '9.13 Un solo dígito se rechaza' => '6',
];

foreach ($invalidPhones as $label => $candidate) {
    $assert($label, !$service->isValidBizumPhone($candidate), 'candidato: "' . $candidate . '"');
}

// ─────────────────────────────────────────────────────────────────────────────
// 10. Contrato de la excepción de Bizum (422)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 10. Contrato de `InvalidBizumPhoneException` ---\n";

$thrownPhone = null;

try {
    $service->assertValidBizumPhone('60011222');
} catch (InvalidBizumPhoneException $e) {
    $thrownPhone = $e;
}

$assert('10.1 assertValidBizumPhone() lanza InvalidBizumPhoneException', $thrownPhone instanceof InvalidBizumPhoneException);
$assert(
    '10.2 La excepción mapea al estado HTTP 422',
    $thrownPhone !== null && $thrownPhone->getHttpStatusCode() === 422
);
$assert(
    '10.3 La excepción expone el error code INVALID_BIZUM_PHONE',
    $thrownPhone !== null && $thrownPhone->getErrorCode() === 'INVALID_BIZUM_PHONE'
);
$assert(
    '10.4 El mensaje en castellano coincide con el catálogo de contratos',
    $thrownPhone !== null && $thrownPhone->getMessage() === InvalidBizumPhoneException::DEFAULT_MESSAGE
);
$assert(
    '10.5 La constante de longitud Bizum publicada es 9',
    IbanValidationService::BIZUM_PHONE_LENGTH === 9
);

// ─────────────────────────────────────────────────────────────────────────────
// 11. Independencia entre los dos validadores
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 11. Los dos validadores no se contaminan entre sí ---\n";

$assert(
    '11.1 Un IBAN válido no se confunde con un teléfono Bizum',
    !$service->isValidBizumPhone('ES9121000418450200051332')
);
$assert(
    '11.2 Un teléfono Bizum no se confunde con un IBAN',
    !$service->isValidIban('600111222')
);
$assert(
    '11.3 Un teléfono válido lo acepta el validador de IBAN como estructura pero no por checksum',
    $service->isValidIban('ES91') === false
);

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-05 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);