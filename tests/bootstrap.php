<?php

declare(strict_types=1);

/**
 * VendGuard - Bootstrap de Pruebas Automatizadas
 * 
 * Configuración común para las suites de pruebas unitarias e integración.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../src/autoload.php';
