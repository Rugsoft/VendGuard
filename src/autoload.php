<?php

declare(strict_types=1);

/**
 * VendGuard - Autoloader PSR-4 Nativo (Dogma Vanilla)
 * 
 * Registra un autoloader nativo sin dependencias de Composer.
 * Mapea el prefijo 'VendGuard\' al directorio 'src/'.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'VendGuard\\';
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
