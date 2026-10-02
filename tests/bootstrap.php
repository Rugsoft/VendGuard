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

// Limpiador compartido de datos de prueba. Se carga aquí para que TODAS las
// suites lo tengan disponible sin un `require_once` propio: el orden de borrado
// seguro se deriva del grafo real de claves foráneas, de modo que ninguna tabla
// hija con ON DELETE RESTRICT (refund_requests, spare_part_requests,
// incident_replaced_parts, unclaimed_cash_findings) puede volver a reventar un
// `DELETE FROM incidents`. Ver specs/technical/testing_cleanup_contract.md
require_once __DIR__ . '/Support/TestDataCleaner.php';
