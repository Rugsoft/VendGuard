<?php

declare(strict_types=1);

/**
 * VendGuard - Script CLI para sembrar datos demo de métricas, reparaciones y auditoría.
 * 
 * Uso: php bin/seed_demo_metrics.php
 */

require_once __DIR__ . '/../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../src/Infrastructure/Database/SeedRunner.php';
require_once __DIR__ . '/../database/DemoMetricsSeeder.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Database\DemoMetricsSeeder;

echo "======================================================================\n";
echo " VendGuard: Sembrando Historial de Métricas, Reparaciones y Auditoría\n";
echo "======================================================================\n\n";

try {
    $pdo = ConnectionFactory::getConnection();

    // 1. Asegurar catálogo base de sedes, máquinas y usuarios
    $seedRunner = new SeedRunner($pdo);
    $baseSummary = $seedRunner->seedAll('Password123!');
    echo "[OK] Catálogo base verificado:\n";
    echo "     - Sedes: {$baseSummary['locations']}\n";
    echo "     - Máquinas: {$baseSummary['machines']}\n";
    echo "     - Usuarios: {$baseSummary['users']}\n\n";

    // 2. Sembrar incidencias históricas, reparaciones con diagnósticos y eventos de auditoría
    $demoSeeder = new DemoMetricsSeeder($pdo);
    $demoSummary = $demoSeeder->seed();
    echo "[OK] Historial de averías y reparaciones cargado con éxito:\n";
    echo "     - Incidencias históricas (resueltas/garantía): {$demoSummary['incidents']}\n";
    echo "     - Eventos en log de auditoría (audit_log): {$demoSummary['audit_events']}\n\n";

    // 3. Resumen de KPIs calculados
    echo "======================================================================\n";
    echo " Resumen de Métricas Disponibles en la Base de Datos:\n";
    echo "======================================================================\n";

    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_resolved,
            ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at))) as avg_mttr_minutes
        FROM incidents
        WHERE resolved_at IS NOT NULL AND status IN ('RESOLVED', 'CLOSED')
    ");
    $global = $stmt->fetch(PDO::FETCH_ASSOC);
    $mttrMin = (int)($global['avg_mttr_minutes'] ?? 0);
    $h = intdiv($mttrMin, 60);
    $m = $mttrMin % 60;

    echo " * Total Incidencias Resueltas : {$global['total_resolved']}\n";
    echo " * MTTR Promedio Calculado      : {$h}h {$m}m ({$mttrMin} minutos)\n";

    $stmtPerish = $pdo->query("
        SELECT 
            COUNT(*) as total_perish,
            ROUND(AVG(TIMESTAMPDIFF(MINUTE, i.created_at, i.resolved_at))) as avg_mttr_minutes
        FROM incidents i
        JOIN machines m ON i.machine_id = m.id
        WHERE i.resolved_at IS NOT NULL 
          AND i.status IN ('RESOLVED', 'CLOSED')
          AND m.machine_type = 'PERISHABLE_FOOD'
    ");
    $perish = $stmtPerish->fetch(PDO::FETCH_ASSOC);
    $pMin = (int)($perish['avg_mttr_minutes'] ?? 0);
    $ph = intdiv($pMin, 60);
    $pm = $pMin % 60;
    echo " * MTTR Alimentos Perecederos  : {$ph}h {$pm}m (SLA < 4.0h: " . ($pMin <= 240 ? "CUMPLIDO" : "INCUMPLIDO") . ")\n";

    $totalAudit = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
    echo " * Total Registros de Auditoría: {$totalAudit}\n";

    echo "\n[LISTO] Ya puedes abrir el Cuadro de Mandos de Métricas y comprobar los valores.\n";
    echo "======================================================================\n";

} catch (Throwable $e) {
    echo "\n[ERROR] Falla al sembrar datos: " . $e->getMessage() . "\n";
    exit(1);
}
