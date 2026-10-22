<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\InvalidServiceSheetException;
use VendGuard\Core\Service\ServiceSheetValidator;

$assertions = 0;

function assertSheetCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Builds a structurally sound PNG: signature, IHDR with the requested dimensions,
 * a tEXt padding chunk and IEND. The validator only reads the magic bytes, the
 * IHDR dimensions and the total size, which is exactly what this fixture needs.
 */
function pngFixture(int $width, int $height, int $paddingBytes = 0): string
{
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };

    $ihdr = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr);

    if ($paddingBytes > 0) {
        $png .= $chunk('tEXt', 'Padding' . "\x00" . str_repeat('x', $paddingBytes));
    }

    return $png . $chunk('IEND', '');
}

function expectSheetRejection(callable $operation, string $label): array
{
    try {
        $operation();
    } catch (InvalidServiceSheetException $exception) {
        assertSheetCondition(
            $exception->getErrorCode() === 'INVALID_SERVICE_SHEET',
            "{$label}: error code is INVALID_SERVICE_SHEET"
        );
        assertSheetCondition(
            $exception->getHttpStatusCode() === 422,
            "{$label}: HTTP status is 422"
        );
        assertSheetCondition($exception->getErrors() !== [], "{$label}: errors are not empty");

        return $exception->getErrors();
    }

    throw new RuntimeException("{$label}: expected InvalidServiceSheetException, none was thrown");
}

try {
    $png = pngFixture(1, 1, 40);
    $pngBase64 = base64_encode($png);

    assertSheetCondition(strlen($png) >= 64, 'The PNG fixture is above the 64-byte floor');
    assertSheetCondition(
        str_starts_with((new finfo(FILEINFO_MIME_TYPE))->buffer($png), 'image/png'),
        'The PNG fixture is recognised as image/png by finfo'
    );

    // ── Bloque no válido ────────────────────────────────────────────────
    expectSheetRejection(static fn() => ServiceSheetValidator::normalize(null), 'null payload');
    expectSheetRejection(static fn() => ServiceSheetValidator::normalize([]), 'empty array');
    expectSheetRejection(static fn() => ServiceSheetValidator::normalize('firma'), 'scalar payload');
    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize(['signer_name' => 'Ana Ruiz']),
        'empty block'
    );

    // ── Claves cerradas ─────────────────────────────────────────────────
    $unknownKeyErrors = expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'signer_document' => '12345678Z',
            'signature_png_base64' => $pngBase64,
            'signer_dni' => '12345678Z',
        ]),
        'unknown key'
    );
    assertSheetCondition(
        str_contains(implode(' | ', $unknownKeyErrors), 'signer_dni'),
        'unknown key error names the offending key'
    );

    // ── Base64 y PNG ────────────────────────────────────────────────────
    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'signer_document' => '12345678Z',
            'signature_png_base64' => 'esto no es base64 !!',
        ]),
        'invalid base64'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'signer_document' => '12345678Z',
            'signature_png_base64' => base64_encode('no soy un png, soy texto plano de relleno para pasar el tamaño'),
        ]),
        'non-PNG payload'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'signer_document' => '12345678Z',
            'signature_png_base64' => 'data:image/jpeg;base64,' . $pngBase64,
        ]),
        'JPEG data prefix'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'signer_document' => '12345678Z',
            'signature_png_base64' => base64_encode(pngFixture(1, 1, ServiceSheetValidator::MAX_PNG_BYTES)),
        ]),
        'PNG above 300 KiB'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'signer_document' => '12345678Z',
            'signature_png_base64' => base64_encode(pngFixture(2000, 2000, 40)),
        ]),
        'PNG above 2 megapixels'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'signer_document' => '12345678Z',
            'signature_png_base64' => base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('A', 60)),
        ]),
        'truncated IHDR'
    );

    // ── Datos del firmante ──────────────────────────────────────────────
    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_document' => '12345678Z',
            'signature_png_base64' => $pngBase64,
        ]),
        'signature without name'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => '  Al  ',
            'signer_document' => '12345678Z',
            'signature_png_base64' => $pngBase64,
        ]),
        'name shorter than three characters after trimming'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'signature_png_base64' => $pngBase64,
        ]),
        'signature without document'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'signer_document' => '1234',
            'signature_png_base64' => $pngBase64,
        ]),
        'document with four characters'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'signer_document' => str_repeat('1', 21),
            'signature_png_base64' => $pngBase64,
        ]),
        'document with twenty-one characters'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'signer_document' => '12345678@',
            'signature_png_base64' => $pngBase64,
        ]),
        'document with an invalid character'
    );

    // ── Ramas excluyentes y ausencia ────────────────────────────────────
    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([]),
        'neither signature nor absence'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'signer_document' => '12345678Z',
            'signature_png_base64' => $pngBase64,
            'absence_reason' => 'No había nadie en la sede',
        ]),
        'signature and absence together'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize(['absence_reason' => 'Corto']),
        'absence reason shorter than ten characters'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize(['absence_reason' => '           ']),
        'blank absence reason'
    );

    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize([
            'signer_name' => 'Ana Ruiz',
            'absence_reason' => 'Sede sin conserjería, acceso por llave',
        ]),
        'absence with a signer name'
    );

    // ── Casos válidos ───────────────────────────────────────────────────
    $signed = ServiceSheetValidator::normalize([
        'signer_name' => '  Ana Ruiz  ',
        'signer_document' => ' 12345678 z ',
        'signature_png_base64' => 'data:image/png;base64,' . $pngBase64,
    ]);
    assertSheetCondition($signed['signer_name'] === 'Ana Ruiz', 'signer name is trimmed');
    assertSheetCondition($signed['signer_document'] === '12345678Z', 'document is uppercased and stripped of spaces');
    assertSheetCondition($signed['signature_png'] === $png, 'decoded signature is the original PNG bytes');
    assertSheetCondition($signed['absence_reason'] === null, 'signed branch has no absence reason');

    $wrappedAtEighty = chunk_split($pngBase64, 80, "\r\n");
    $wrapped = ServiceSheetValidator::normalize([
        'signer_name' => 'Ana Ruiz',
        'signer_document' => 'X1234567L',
        'signature_png_base64' => $wrappedAtEighty,
    ]);
    assertSheetCondition($wrapped['signature_png'] === $png, 'base64 split into lines is decoded byte for byte');

    $absence = ServiceSheetValidator::normalize([
        'absence_reason' => '  Sede sin conserjería, acceso por llave  ',
        'signer_name' => '   ',
        'signer_document' => null,
    ]);
    assertSheetCondition($absence['absence_reason'] === 'Sede sin conserjería, acceso por llave', 'absence reason is trimmed');
    assertSheetCondition($absence['signer_name'] === null, 'blank signer name is treated as absent');
    assertSheetCondition($absence['signature_png'] === null, 'absence branch has no signature');
    assertSheetCondition($absence['signer_document'] === null, 'absence branch has no document');

    $multibyte = ServiceSheetValidator::normalize([
        'signer_name' => 'Íñi',
        'signer_document' => '12345678-Z',
        'signature_png_base64' => $pngBase64,
    ]);
    assertSheetCondition($multibyte['signer_name'] === 'Íñi', 'multibyte name of three characters is accepted');

    assertSheetCondition(
        ServiceSheetValidator::MAX_PNG_BYTES === 307200
            && ServiceSheetValidator::MAX_PIXELS === 2000000
            && ServiceSheetValidator::MIN_SIGNER_NAME === 3
            && ServiceSheetValidator::MIN_DOCUMENT === 5
            && ServiceSheetValidator::MAX_DOCUMENT === 20
            && ServiceSheetValidator::MIN_ABSENCE_REASON === 10,
        'declared limits match the contract'
    );

    // ── Bordes del contrato (R3-2) ───────────────────────────────────────
    // Cobertura de regresión: fija cada cota en su valor exacto para que un cambio de
    // constante no pase inadvertido. No introduce reglas nuevas.
    $signed = static fn(array $overrides = []): array => ServiceSheetValidator::normalize(array_merge([
        'signer_name' => 'Ana Ruiz',
        'signer_document' => '12345678Z',
        'signature_png_base64' => $pngBase64,
    ], $overrides));

    assertSheetCondition(strlen($signed(['signer_name' => str_repeat('A', 120)])['signer_name']) === 120, 'name of 120 characters is accepted');
    expectSheetRejection(static fn() => $signed(['signer_name' => str_repeat('A', 121)]), 'name of 121 characters');

    assertSheetCondition(strlen($signed(['signer_document' => str_repeat('A', 20)])['signer_document']) === 20, 'document of 20 characters is accepted');
    expectSheetRejection(static fn() => $signed(['signer_document' => str_repeat('A', 21)]), 'document of 21 characters');

    assertSheetCondition(
        strlen(ServiceSheetValidator::normalize(['absence_reason' => str_repeat('A', 500)])['absence_reason']) === 500,
        'absence reason of exactly 500 characters is accepted'
    );
    expectSheetRejection(
        static fn() => ServiceSheetValidator::normalize(['absence_reason' => str_repeat('A', 501)]),
        'absence reason of 501 characters'
    );

    // El tope del trazo se mide sobre los bytes del PNG, no sobre el base64.
    $exact = pngFixture(1, 1, 307200 - 65);
    assertSheetCondition(strlen($exact) === 307200, 'fixture is exactly 307200 bytes');
    assertSheetCondition(
        strlen($signed(['signature_png_base64' => base64_encode($exact)])['signature_png']) === 307200,
        'a PNG of exactly 307200 bytes is accepted'
    );
    expectSheetRejection(
        static fn() => $signed(['signature_png_base64' => base64_encode(pngFixture(1, 1, 307201 - 65))]),
        'PNG of 307201 bytes'
    );
    // Pre-cota: un cuerpo sobredimensionado se rechaza antes de decodificarlo.
    expectSheetRejection(
        static fn() => $signed(['signature_png_base64' => str_repeat('A', 409605)]),
        'base64 above the pre-decode ceiling'
    );

    echo "ServiceSheetValidator tests passed. Total Assertions: {$assertions}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "ServiceSheetValidator test failed: {$exception->getMessage()}\n");
    exit(1);
}
