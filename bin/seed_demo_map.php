<?php

declare(strict_types=1);

/**
 * VendGuard — Siembra de averías activas para el Mapa Territorial de Coordinación.
 *
 * Uso:
 *   php bin/seed_demo_map.php
 *
 * Deja ocho averías vivas repartidas entre Jordi, Marta, Carlos y Elena, deja una sin asignar
 * y una sede multi-técnico, de modo que el filtro por técnico del mapa conserve sedes y el
 * estado vacío se pueda provocar eligiendo a Marc (sin trabajo). Es idempotente: una segunda
 * pasada no duplica nada y declara lo que ya existía.
 *
 * Script sin autoloader, como el resto de `bin/`: las dependencias de la cadena se declaran a
 * mano (`ConnectionFactory` arrastra `SecretProvider` por la política S-5).
 */

require_once __DIR__ . '/../src/Infrastructure/Config/SecretProvider.php';
require_once __DIR__ . '/../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../database/DemoMapSeeder.php';

use VendGuard\Database\DemoMapSeeder;
use VendGuard\Infrastructure\Database\ConnectionFactory;

echo "======================================================================\n";
echo " VendGuard: Averías de demostración del Mapa Territorial\n";
echo "======================================================================\n\n";

try {
    $pdo = ConnectionFactory::getConnection();
    $fixtures = DemoMapSeeder::fixtures();

    echo '[1/3] Conexión establecida. Sembrando ' . count($fixtures) . " averías activas...\n";

    $seeder = new DemoMapSeeder($pdo);
    $summary = $seeder->seed();

    echo '      - Nuevas: ' . count($summary['inserted']) . ' (' . implode(', ', $summary['inserted']) . ")\n";
    echo '      - Ya existentes: ' . count($summary['existing']) . "\n";
    if ($summary['skipped'] !== []) {
        echo '      - Omitidas: ' . implode(', ', $summary['skipped']) . "\n";
    }

    echo "\n[2/3] Lo que verá el coordinador en el mapa territorial:\n";
    echo "      ------------------------------------------------------------------\n";
    printf("      %-14s %-34s %-30s %s\n", 'Sede', 'Centro', 'Técnico', 'Averías');
    echo "      ------------------------------------------------------------------\n";
    foreach ($seeder->summary() as $row) {
        printf(
            "      %-14s %-34s %-30s %d\n",
            $row['site_code'],
            mb_strimwidth($row['site_name'], 0, 33, '…'),
            $row['technician'],
            $row['tickets']
        );
    }
    echo "      ------------------------------------------------------------------\n";

    echo "\n[3/3] Filtros que este juego de datos permite comprobar:\n";
    echo "      * Jordi, Marta, Carlos y Elena conservan sus sedes al filtrar.\n";
    echo "      * SEDE-BCN-03 queda multi-técnico (Jordi + Marta) y ofrece «Consolidar visita».\n";
    echo "      * Marc no tiene trabajo: es el técnico del estado vacío del filtro.\n";
    echo "      * SEDE-BCN-05 está sin asignar y SEDE-BCN-01/07 son críticas de cadena de frío.\n";

    echo "\n[LISTO] Abre la pestaña «Mapa Territorial» del cuadro de mando.\n";
    echo "======================================================================\n";
    exit(0);
} catch (Throwable $exception) {
    echo "\n[ERROR] No se pudieron sembrar las averías del mapa: " . $exception->getMessage() . "\n";
    exit(1);
}
