<?php

declare(strict_types=1);

/**
 * LocalFileUploaderTest
 * 
 * Test Unitario para LocalFileUploader (Tarea T-19).
 * Valida que:
 * 1. Los archivos mayores a 5 MB son rechazados con excepción y código 422 (RNF-05).
 * 2. Se verifica el tipo MIME real (image/jpeg, image/png, image/webp) mediante finfo.
 * 3. Se genera un nombre hash único e impredecible (sin extensiones spoofeadas).
 * 4. Se almacena físicamente en public/uploads/ devolviendo la ruta pública relativa.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Infrastructure\Storage\LocalFileUploader;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - LocalFileUploaderTest (T-19)\n";
echo "======================================================================\n\n";

$uploader = new LocalFileUploader();
$targetDir = $uploader->getTargetDir();

$failures = 0;
$createdTestFiles = [];

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
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

// Muestras binarias mínimas válidas reconocidas por finfo
$validPngBytes = hex2bin('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000a49444154789c63000100000500010d0a2db40000000049454e44ae426082');
$validJpgBytes = hex2bin('ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffda0008010100003f00d2cf20ffd9');
$validWebpBytes = hex2bin('524946461a00000057454250565038200e0000003001009d012a0100010002003425a400037000feef');

// =====================================================================
// CASO 1: Subida exitosa de imágenes válidas (JPEG, PNG, WebP)
// =====================================================================
echo "--- Caso 1: Subida exitosa y almacenamiento en public/uploads/ ---\n";

// 1.1 PNG Válido
$tmpPng = tempnam(sys_get_temp_dir(), 'test_png_');
file_put_contents($tmpPng, $validPngBytes);

$pngUpload = [
    'name' => 'rotura_pantalla.png',
    'type' => 'image/png',
    'tmp_name' => $tmpPng,
    'error' => UPLOAD_ERR_OK,
    'size' => strlen($validPngBytes),
];

$storedPngPath = $uploader->upload($pngUpload);
$createdTestFiles[] = $storedPngPath;

$assert(
    "1.1 upload() devuelve ruta relativa en formato '/uploads/{hash}.png'",
    (bool)preg_match('#^/uploads/[a-f0-9]{32}\.png$#', $storedPngPath),
    "Ruta devuelta: {$storedPngPath}"
);

$fullPngPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($storedPngPath);
$assert(
    "1.2 El archivo PNG existe físicamente en el directorio public/uploads/",
    file_exists($fullPngPath) && filesize($fullPngPath) === strlen($validPngBytes)
);

// 1.2 JPEG Válido
$tmpJpg = tempnam(sys_get_temp_dir(), 'test_jpg_');
file_put_contents($tmpJpg, $validJpgBytes);

$jpgUpload = [
    'name' => 'atasco_monedero.jpg',
    'type' => 'image/jpeg',
    'tmp_name' => $tmpJpg,
    'error' => UPLOAD_ERR_OK,
    'size' => strlen($validJpgBytes),
];

$storedJpgPath = LocalFileUploader::save($jpgUpload);
$createdTestFiles[] = $storedJpgPath;

$assert(
    "1.3 Subida de JPEG genera hash único con extensión '.jpg'",
    (bool)preg_match('#^/uploads/[a-f0-9]{32}\.jpg$#', $storedJpgPath)
);
$fullJpgPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($storedJpgPath);
$assert(
    "1.4 El archivo JPEG existe físicamente en public/uploads/",
    file_exists($fullJpgPath)
);

// 1.3 WebP Válido
$tmpWebp = tempnam(sys_get_temp_dir(), 'test_webp_');
file_put_contents($tmpWebp, $validWebpBytes);

$webpUpload = [
    'name' => 'foto_termostato.webp',
    'type' => 'image/webp',
    'tmp_name' => $tmpWebp,
    'error' => UPLOAD_ERR_OK,
    'size' => strlen($validWebpBytes),
];

$storedWebpPath = $uploader->upload($webpUpload);
$createdTestFiles[] = $storedWebpPath;

$assert(
    "1.5 Subida de WebP genera hash único con extensión '.webp'",
    (bool)preg_match('#^/uploads/[a-f0-9]{32}\.webp$#', $storedWebpPath)
);

// =====================================================================
// CASO 2: Validación estricta del límite de 5 MB (RNF-05)
// =====================================================================
echo "\n--- Caso 2: Validación de tamaño máximo de 5 MB (RNF-05) ---\n";

$tmpLarge = tempnam(sys_get_temp_dir(), 'test_large_');
// Crear archivo que supere ligeramente los 5 MB (5.242.880 bytes + 10 bytes)
$oversizeBytes = 5 * 1024 * 1024 + 10;
$fp = fopen($tmpLarge, 'w');
fseek($fp, $oversizeBytes - 1);
fwrite($fp, 'X');
fclose($fp);

$largeUpload = [
    'name' => 'foto_gigante.jpg',
    'type' => 'image/jpeg',
    'tmp_name' => $tmpLarge,
    'error' => UPLOAD_ERR_OK,
    'size' => $oversizeBytes,
];

$largeExceptionCaught = false;
$largeErrorCode = '';
try {
    $uploader->upload($largeUpload);
} catch (InvalidUploadException $e) {
    $largeExceptionCaught = true;
    $largeErrorCode = $e->getErrorCode();
} finally {
    if (file_exists($tmpLarge)) {
        unlink($tmpLarge);
    }
}

$assert(
    "2.1 Archivo mayor a 5 MB es rechazado con InvalidUploadException",
    $largeExceptionCaught
);
$assert(
    "2.2 Código de error es 'FILE_TOO_LARGE'",
    $largeErrorCode === 'FILE_TOO_LARGE',
    "Código recibido: {$largeErrorCode}"
);

// =====================================================================
// CASO 3: Detección y rechazo de tipos MIME falsificados o no permitidos
// =====================================================================
echo "\n--- Caso 3: Detección de tipos MIME reales y rechazo de malware/falsificaciones ---\n";

// 3.1 Script PHP disfrazado de imagen (.jpg)
$tmpFake = tempnam(sys_get_temp_dir(), 'test_fake_');
file_put_contents($tmpFake, '<?php echo "HACKED"; ?>');

$fakeUpload = [
    'name' => 'payload_malicioso.jpg',
    'type' => 'image/jpeg', // Tipo MIME declarado por el cliente (falso)
    'tmp_name' => $tmpFake,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tmpFake),
];

$fakeExceptionCaught = false;
$fakeErrorCode = '';
try {
    $uploader->upload($fakeUpload);
} catch (InvalidUploadException $e) {
    $fakeExceptionCaught = true;
    $fakeErrorCode = $e->getErrorCode();
} finally {
    if (file_exists($tmpFake)) {
        unlink($tmpFake);
    }
}

$assert(
    "3.1 Script PHP con extensión .jpg es detectado por MIME real y rechazado",
    $fakeExceptionCaught
);
$assert(
    "3.2 Código de error es 'INVALID_FILE_TYPE'",
    $fakeErrorCode === 'INVALID_FILE_TYPE'
);

// 3.2 Documento PDF no permitido
$tmpPdf = tempnam(sys_get_temp_dir(), 'test_pdf_');
file_put_contents($tmpPdf, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

$pdfUpload = [
    'name' => 'manual_tecnico.pdf',
    'type' => 'application/pdf',
    'tmp_name' => $tmpPdf,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tmpPdf),
];

$pdfExceptionCaught = false;
try {
    $uploader->upload($pdfUpload);
} catch (InvalidUploadException $e) {
    $pdfExceptionCaught = true;
} finally {
    if (file_exists($tmpPdf)) {
        unlink($tmpPdf);
    }
}

$assert(
    "3.3 Formato PDF no gráfico es rechazado",
    $pdfExceptionCaught
);

// =====================================================================
// CASO 4: Eliminación y limpieza segura (delete)
// =====================================================================
echo "\n--- Caso 4: Eliminación segura de evidencias físicas ---\n";

$deleteResult = $uploader->delete($storedPngPath);
$assert(
    "4.1 delete() elimina el archivo físico en public/uploads/",
    $deleteResult === true && !file_exists($fullPngPath)
);

// Limpieza de archivos temporales creados en los tests
foreach ($createdTestFiles as $p) {
    $uploader->delete($p);
}
if (file_exists($tmpPng)) {
    unlink($tmpPng);
}
if (file_exists($tmpJpg)) {
    unlink($tmpJpg);
}
if (file_exists($tmpWebp)) {
    unlink($tmpWebp);
}

// Resumen del test
echo "\n======================================================================\n";
echo " Total Aserciones Verificadas | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-19 CUMPLIDA CON ÉXITO.\n";
} else {
    echo " RESULTADO: {$failures} ASERCIONES HAN FALLADO.\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
