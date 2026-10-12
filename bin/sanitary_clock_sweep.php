<?php

declare(strict_types=1);

/**
 * VendGuard - Barrido de fondo del Reloj Sanitario Biológico
 *
 * T-PAUSE-38 · RF-03.5.1 · Constitución Art. II.
 *
 * Uso: php bin/sanitary_clock_sweep.php
 *
 * Activa la cuarentena sanitaria de las máquinas bajo vigilancia con más de 4 horas
 * naturales sin frío confirmado, **sin** que nadie intente cerrar la avería. Es el
 * equivalente de línea de comandos del endpoint `POST /api/cron/sanitary-clock-sweep`,
 * para programadores que no disponen de HTTP: la cadencia operativa ratificada es de
 * 15 minutos, de modo que la latencia de detección queda acotada en «4 h + hasta 15 min».
 *
 * El barrido no decide nada por su cuenta: entrega cada candidato al reloj
 * (`IncidentPauseService::evaluateSanitaryBiologicalClock()`), que es la única autoridad
 * sobre la regla y el único punto que escribe el estado y su rastro inmutable.
 *
 * Códigos de salida:
 *   0 = pasada completada sin fallos.
 *   1 = la pasada reportó fallos (el planificador debe poder alertar) o excepción fatal.
 */

require_once __DIR__ . '/../src/autoload.php';

use VendGuard\Application\Service\SanitaryClockSweepService;

echo "========================================================\n";
echo " VendGuard: Barrido del Reloj Sanitario Biológico\n";
echo "========================================================\n\n";

try {
    $result = (new SanitaryClockSweepService())->sweep();

    echo sprintf(
        "[1/2] Candidatos evaluados: %d de %d (umbral %d s naturales, %.2f ms)\n",
        $result->evaluated,
        $result->candidates,
        $result->thresholdSeconds,
        $result->durationMs
    );

    foreach ($result->quarantined as $entry) {
        echo sprintf(
            "      CUARENTENA · máquina %d · expediente %s\n",
            $entry['machine_id'],
            $entry['ticket_code']
        );
    }

    if ($result->failures !== []) {
        echo sprintf("[2/2] Fallos: %d\n", $result->failuresCount);
        foreach ($result->failures as $failure) {
            echo sprintf(
                "      · expediente %s (%s): %s\n",
                $failure['ticket_code'],
                $failure['incident_id'] === null ? 'sin id' : (string)$failure['incident_id'],
                $failure['reason']
            );
        }
    } else {
        echo "[2/2] Fallos: 0\n";
    }

    $exitCode = $result->failuresCount > 0 ? 1 : 0;

    echo "\n" . str_repeat('=', 56) . "\n";
    echo $exitCode === 0
        ? sprintf(" Barrido completado. Cuarentenas activadas: %d\n", count($result->quarantined))
        : " Barrido completado CON FALLOS: revisar los expedientes listados.\n";
    echo str_repeat('=', 56) . "\n";

    exit($exitCode);
} catch (Throwable $exception) {
    echo "\n[ERROR FATAL]: " . $exception->getMessage() . "\n";
    exit(1);
}
