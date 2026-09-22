<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Storage;

use finfo;
use VendGuard\Core\Domain\Exception\InvalidUploadException;

/**
 * LocalFileUploader
 * 
 * Gestor seguro de subida y almacenamiento local de archivos adjuntos (fotografías).
 * Cumple con RNF-05 (EARS 3.9) y el Artículo V de la Constitución:
 * - Valida que el archivo no supere los 5 MB (5.242.880 bytes).
 * - Verifica la cabecera e inspección MIME real mediante finfo (image/jpeg, image/png, image/webp).
 * - Genera nombres hash criptográficos únicos inmunes a inyecciones de ruta o extensiones maliciosas.
 * - Almacena las evidencias en public/uploads/.
 */
class LocalFileUploader
{
    public const MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5 MB exactos

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    private string $targetDir;
    private int $maxSizeBytes;

    public function __construct(?string $targetDir = null, int $maxSizeBytes = self::MAX_SIZE_BYTES)
    {
        $this->targetDir = $targetDir ?? dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
        $this->maxSizeBytes = $maxSizeBytes;
    }

    /**
     * Valida, procesa y almacena un archivo subido.
     *
     * @param array<string, mixed> $file Array representativo de $_FILES['photo'].
     * @return string Ruta relativa pública normalizada (ej: /uploads/abc123xyz.jpg).
     * @throws InvalidUploadException Si el archivo supera 5MB, no es una imagen válida o falla el almacenamiento.
     */
    public function upload(array $file): string
    {
        $this->validate($file);

        $tmpPath = (string)$file['tmp_name'];
        $realMime = $this->detectRealMimeType($tmpPath);
        $extension = self::ALLOWED_MIME_TYPES[$realMime] ?? 'jpg';

        // Generar nombre hash único e impredecible (32 caracteres hexadecimales)
        $uniqueHash = bin2hex(random_bytes(16));
        $filename = "{$uniqueHash}.{$extension}";

        // Asegurar que el directorio de subidas exista
        if (!is_dir($this->targetDir)) {
            if (!mkdir($this->targetDir, 0755, true) && !is_dir($this->targetDir)) {
                throw new InvalidUploadException(
                    'DIRECTORY_CREATION_FAILED',
                    'No se pudo inicializar el directorio de almacenamiento de archivos.',
                    500
                );
            }
        }

        $destination = rtrim($this->targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        // Trasladar el archivo al destino final
        if (is_uploaded_file($tmpPath)) {
            $success = move_uploaded_file($tmpPath, $destination);
        } else {
            $success = copy($tmpPath, $destination);
        }

        if (!$success || !file_exists($destination)) {
            throw new InvalidUploadException(
                'STORAGE_WRITE_ERROR',
                'No se pudo guardar físicamente el archivo en el servidor.',
                500
            );
        }

        return "/uploads/{$filename}";
    }

    /**
     * Alias estático de conveniencia para upload().
     *
     * @param array<string, mixed> $file
     * @param string|null $targetDir
     * @return string
     */
    public static function save(array $file, ?string $targetDir = null): string
    {
        $uploader = new self($targetDir);
        return $uploader->upload($file);
    }

    /**
     * Realiza la validación estricta de límites de tamaño y tipo de archivo sin guardarlo.
     *
     * @param array<string, mixed> $file
     * @throws InvalidUploadException
     */
    public function validate(array $file): void
    {
        // 1. Validar presencia de datos básicos
        if (empty($file) || !isset($file['tmp_name'])) {
            throw new InvalidUploadException(
                'NO_FILE_PROVIDED',
                'No se ha recibido ningún archivo adjunto.',
                400
            );
        }

        // 2. Verificar código de error de PHP
        $error = (int)($file['error'] ?? UPLOAD_ERR_OK);
        if ($error !== UPLOAD_ERR_OK) {
            $this->handleUploadError($error);
        }

        $tmpPath = (string)$file['tmp_name'];
        if (!file_exists($tmpPath)) {
            throw new InvalidUploadException(
                'FILE_NOT_FOUND',
                'El archivo temporal no se encuentra en el servidor.',
                400
            );
        }

        // 3. Validar límite máximo de tamaño (5 MB según RNF-05)
        $fileSize = isset($file['size']) && (int)$file['size'] > 0 ? (int)$file['size'] : filesize($tmpPath);
        if ($fileSize === false || $fileSize <= 0) {
            throw new InvalidUploadException(
                'EMPTY_FILE',
                'El archivo proporcionado está vacío.',
                422
            );
        }

        if ($fileSize > $this->maxSizeBytes) {
            throw new InvalidUploadException(
                'FILE_TOO_LARGE',
                'El archivo adjunto supera el tamaño máximo permitido de 5 MB (RNF-05).',
                422
            );
        }

        // 4. Validar el tipo MIME real mediante análisis de cabecera binaria (finfo)
        $realMime = $this->detectRealMimeType($tmpPath);
        if ($realMime === null || !array_key_exists($realMime, self::ALLOWED_MIME_TYPES)) {
            throw new InvalidUploadException(
                'INVALID_FILE_TYPE',
                'Formato de archivo no permitido. Solo se aceptan imágenes JPEG, PNG o WebP seguras.',
                422
            );
        }
    }

    /**
     * Detecta el tipo MIME real leyendo los números mágicos del archivo temporal.
     */
    public function detectRealMimeType(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return $mime !== false ? strtolower(trim($mime)) : null;
    }

    /**
     * Elimina un archivo físico previamente subido si existe.
     */
    public function delete(string $publicPath): bool
    {
        $filename = basename($publicPath);
        $fullPath = rtrim($this->targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($fullPath) && is_file($fullPath)) {
            return unlink($fullPath);
        }

        return false;
    }

    public function getTargetDir(): string
    {
        return $this->targetDir;
    }

    private function handleUploadError(int $errorCode): void
    {
        $message = match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo recibido supera el límite de tamaño permitido por el servidor.',
            UPLOAD_ERR_PARTIAL => 'La subida del archivo se interrumpió de forma incompleta.',
            UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo para subir.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal en la configuración del servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Error de permisos al escribir el archivo temporal en disco.',
            default => "Error desconocido durante la subida de archivo (código {$errorCode}).",
        };

        throw new InvalidUploadException('UPLOAD_ERROR', $message, 422);
    }
}
