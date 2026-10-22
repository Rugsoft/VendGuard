<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use finfo;
use VendGuard\Core\Domain\Exception\InvalidServiceSheetException;

/**
 * ServiceSheetValidator
 *
 * Validación pura del bloque `service_sheet` que viaja en el cierre técnico
 * (Módulo 19, RF-01, RF-02 y RNF-02). No toca base de datos, reloj ni disco:
 * entra un array y salen los cuatro valores normalizados, o una excepción con
 * una entrada por regla incumplida.
 *
 * Lo que el servidor **sí** puede comprobar es que el trazo sea una imagen PNG
 * real y de tamaño acotado. Lo que **no** puede comprobar es que tenga tinta:
 * decodificar píxeles exigiría GD o Imagick en la imagen de producción, y esa
 * dependencia de sistema está descartada (Q4, RD-12). Esa comprobación vive en
 * el componente del técnico y su límite está declarado.
 *
 * Art. V.5 fija 5 MB y formatos gráficos seguros para las imágenes del usuario;
 * aquí se aplica como **cota superior** y se endurece: solo PNG y 300 KiB.
 */
final class ServiceSheetValidator
{
    /** Tope operativo de la firma: 300 KiB (RNF-02). */
    public const MAX_PNG_BYTES = 307200;

    /** Defensa frente a bombas de descompresión: 2 Mpx leídos del IHDR. */
    public const MAX_PIXELS = 2000000;

    /** Un PNG de 1×1 ocupa ~67 bytes; por debajo no hay imagen real. */
    public const MIN_PNG_BYTES = 64;

    public const MIN_SIGNER_NAME = 3;

    /** Cota de la columna `signer_name VARCHAR(120)`: mejor 422 que un error de motor. */
    public const MAX_SIGNER_NAME = 120;

    public const MIN_DOCUMENT = 5;
    public const MAX_DOCUMENT = 20;
    public const MIN_ABSENCE_REASON = 10;

    /** Cota de la columna `signer_document VARCHAR(20)` y del motivo (`TEXT` se acota igual). */
    public const MAX_ABSENCE_REASON = 500;

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const DATA_PREFIX = 'data:image/png;base64,';

    /** El contrato es cerrado: un campo mal escrito no puede pasar por «sin firma». */
    private const ALLOWED_KEYS = [
        'signer_name',
        'signer_document',
        'signature_png_base64',
        'absence_reason',
    ];

    /**
     * @return array{signer_name: ?string, signer_document: ?string,
     *               signature_png: ?string, absence_reason: ?string}
     *
     * @throws InvalidServiceSheetException
     */
    public static function normalize(mixed $payload): array
    {
        if (!is_array($payload) || $payload === []) {
            throw new InvalidServiceSheetException([
                'service_sheet: el bloque de firma está vacío.',
            ]);
        }

        $unknownKeys = array_values(array_diff(array_keys($payload), self::ALLOWED_KEYS));
        if ($unknownKeys !== []) {
            throw new InvalidServiceSheetException(array_map(
                static fn(string|int $key): string => sprintf(
                    'service_sheet.%s: campo no reconocido por el contrato.',
                    (string)$key
                ),
                $unknownKeys
            ));
        }

        $signerName = self::blankToNull($payload['signer_name'] ?? null);
        $signerDocument = self::blankToNull($payload['signer_document'] ?? null);
        $absenceReason = self::blankToNull($payload['absence_reason'] ?? null);
        $signaturePng = self::blankToNull($payload['signature_png_base64'] ?? null);

        if ($signerDocument !== null) {
            $signerDocument = self::normalizeDocument($signerDocument);
        }

        if ($signaturePng !== null) {
            $signaturePng = self::decodePng($signaturePng);
        }

        $errors = [];

        if ($signaturePng !== null) {
            if ($absenceReason !== null) {
                $errors[] = 'service_sheet: la firma y el motivo de ausencia son excluyentes.';
            }

            if ($signerName === null || mb_strlen($signerName) < self::MIN_SIGNER_NAME) {
                $errors[] = sprintf(
                    'service_sheet.signer_name: obligatorio y de al menos %d caracteres.',
                    self::MIN_SIGNER_NAME
                );
            } elseif (mb_strlen($signerName) > self::MAX_SIGNER_NAME) {
                $errors[] = sprintf(
                    'service_sheet.signer_name: máximo %d caracteres.',
                    self::MAX_SIGNER_NAME
                );
            }

            if ($signerDocument === null) {
                $errors[] = 'service_sheet.signer_document: obligatorio cuando hay firma.';
            }
        } elseif ($absenceReason !== null) {
            if ($signerName !== null) {
                $errors[] = 'service_sheet.signer_name: la ausencia de responsable no lleva firmante.';
            }

            if ($signerDocument !== null) {
                $errors[] = 'service_sheet.signer_document: la ausencia de responsable no lleva documento.';
            }

            if (mb_strlen($absenceReason) < self::MIN_ABSENCE_REASON) {
                $errors[] = sprintf(
                    'service_sheet.absence_reason: obligatorio y de al menos %d caracteres.',
                    self::MIN_ABSENCE_REASON
                );
            } elseif (mb_strlen($absenceReason) > self::MAX_ABSENCE_REASON) {
                $errors[] = sprintf(
                    'service_sheet.absence_reason: máximo %d caracteres.',
                    self::MAX_ABSENCE_REASON
                );
            }
        } else {
            $errors[] = 'service_sheet: se exige una firma o un motivo de ausencia, no ambos vacíos.';
        }

        if ($errors !== []) {
            throw new InvalidServiceSheetException($errors);
        }

        return [
            'signer_name' => $signaturePng !== null ? $signerName : null,
            'signer_document' => $signaturePng !== null ? $signerDocument : null,
            'signature_png' => $signaturePng,
            'absence_reason' => $signaturePng !== null ? null : $absenceReason,
        ];
    }

    /**
     * Documento de identidad: mayúsculas y **sin ningún espacio**, tampoco los
     * internos. Alguien que firma con «12345678 Z» está declarando el mismo
     * documento que «12345678Z»; y también firman pasaportes y documentos
     * extranjeros, así que el formato es deliberadamente laxo (RD-07).
     */
    private static function normalizeDocument(string $document): string
    {
        $normalized = mb_strtoupper((string)preg_replace('/[\s\x{00A0}]+/u', '', trim($document)));

        if (!preg_match('/^[A-Z0-9-]{' . self::MIN_DOCUMENT . ',' . self::MAX_DOCUMENT . '}$/', $normalized)) {
            throw new InvalidServiceSheetException([
                sprintf(
                    'service_sheet.signer_document: entre %d y %d caracteres del alfabeto A-Z, 0-9 y guion (recibido: «%s»).',
                    self::MIN_DOCUMENT,
                    self::MAX_DOCUMENT,
                    $normalized
                ),
            ]);
        }

        return $normalized;
    }

    /**
     * Decodifica el base64 en modo estricto y certifica que el resultado es un
     * PNG real, de tamaño acotado y con dimensiones declaradas plausibles.
     */
    private static function decodePng(string $value): string
    {
        $value = trim($value);

        if (stripos($value, 'data:') === 0) {
            if (stripos($value, self::DATA_PREFIX) !== 0) {
                throw new InvalidServiceSheetException([
                    'service_sheet.signature_png_base64: solo se admite el prefijo data:image/png;base64,.',
                ]);
            }

            $value = substr($value, strlen(self::DATA_PREFIX));
        }

        // Algunos clientes parten el base64 en líneas de 76 u 80 columnas.
        $clean = (string)preg_replace('/\s+/', '', $value);
        if ($clean === '') {
            throw new InvalidServiceSheetException([
                'service_sheet.signature_png_base64: el trazo está vacío.',
            ]);
        }

        $bytes = base64_decode($clean, true);
        if ($bytes === false || $bytes === '') {
            throw new InvalidServiceSheetException([
                'service_sheet.signature_png_base64: el trazo no es base64 válido.',
            ]);
        }

        if (!str_starts_with($bytes, self::PNG_SIGNATURE)) {
            throw new InvalidServiceSheetException([
                'service_sheet.signature_png_base64: el trazo no es una imagen PNG.',
            ]);
        }

        if (strlen($bytes) < self::MIN_PNG_BYTES) {
            throw new InvalidServiceSheetException([
                'service_sheet.signature_png_base64: la imagen es demasiado pequeña para ser un trazo.',
            ]);
        }

        if (strlen($bytes) > self::MAX_PNG_BYTES) {
            throw new InvalidServiceSheetException([
                sprintf(
                    'service_sheet.signature_png_base64: el trazo supera los %d bytes admitidos.',
                    self::MAX_PNG_BYTES
                ),
            ]);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($mime !== 'image/png') {
            throw new InvalidServiceSheetException([
                'service_sheet.signature_png_base64: el tipo real de la imagen no es image/png.',
            ]);
        }

        // El IHDR es el primer chunk tras la firma de 8 bytes: longitud (4),
        // tipo (4) y, en su datos, ancho y alto big-endian.
        if (strlen($bytes) < 24 || substr($bytes, 12, 4) !== 'IHDR') {
            throw new InvalidServiceSheetException([
                'service_sheet.signature_png_base64: el PNG no declara una cabecera IHDR legible.',
            ]);
        }

        /** @var array{width: int, height: int} $dimensions */
        $dimensions = unpack('Nwidth/Nheight', substr($bytes, 16, 8));
        $width = (int)$dimensions['width'];
        $height = (int)$dimensions['height'];

        if ($width <= 0 || $height <= 0 || $width * $height > self::MAX_PIXELS) {
            throw new InvalidServiceSheetException([
                sprintf(
                    'service_sheet.signature_png_base64: dimensiones no admitidas (%d×%d, máximo %d píxeles).',
                    $width,
                    $height,
                    self::MAX_PIXELS
                ),
            ]);
        }

        return $bytes;
    }

    private static function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidServiceSheetException([
                'service_sheet: todos los campos del bloque son cadenas de texto.',
            ]);
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
