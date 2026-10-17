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
 * Qué separa esta salida (T-PAUSE-39):
 *   - **Contención de fila** (`contended`): otro consumidor —el cierre técnico, otro barrido
 *     o cualquier escritor del parque— sostenía el bloqueo de esa máquina, así que su
 *     transición quedó aplazada. Es operación normal, no un error: se informa aparte y el
 *     candidato se reintenta en la pasada siguiente. **No** enciende la alerta.
 *   - **Fallo real** (`failures`): la evaluación no se pudo decidir o la escritura no se pudo
 *     persistir. Es lo único que hace terminar el proceso en rojo.
 *
 * La decisión de qué alerta no se toma aquí: la dicta
 * `SanitaryClockSweepResultDto::requiresOperatorAttention()`, para que el endpoint y el CLI
 * compartan un único criterio.
 *
 * Códigos de salida:
 *   0 = pasada completada sin fallos reales (con o sin contención de fila).
 *   1 = la pasada reportó fallos reales (el planificador debe poder alertar) o excepción fatal.
 */

require_once __DIR__ . '/../src/autoload.php';

use VendGuard\Application\Service\SanitaryClockSweepService;
use VendGuard\Infrastructure\Notification\NotificationOutboxEnqueuerFactory;

echo "========================================================\n";
echo " VendGuard: Barrido del Reloj Sanitario Biológico\n";
echo "========================================================\n\n";

try {
    $result = (new SanitaryClockSweepService(
        notificationEnqueuer: NotificationOutboxEnqueuerFactory::createOrNull()
    ))->sweep();

    echo sprintf(
        "[1/3] Candidatos evaluados: %d de %d (umbral %d s naturales, %.2f ms)\n",
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

    // Aplazamiento por contención de fila: informativo, nunca motivo de alerta.
    foreach ($result->contended as $entry) {
        echo sprintf(
            "      APLAZADO  · expediente %s (%s): la fila está bloqueada por otro consumidor; se reintenta en la pasada siguiente\n",
            $entry['ticket_code'],
            $entry['incident_id'] === null ? 'sin id' : (string)$entry['incident_id']
        );
    }

    if ($result->failures !== []) {
        echo sprintf("[2/3] Fallos reales: %d\n", $result->failuresCount);
        foreach ($result->failures as $failure) {
            echo sprintf(
                "      · expediente %s (%s): %s\n",
                $failure['ticket_code'],
                $failure['incident_id'] === null ? 'sin id' : (string)$failure['incident_id'],
                $failure['reason']
            );
        }
    } else {
        echo "[2/3] Fallos reales: 0\n";
    }

    echo sprintf(
        "[3/3] Contención de fila: %d aplazados (no es un fallo; se reintentan en la pasada siguiente)\n",
        $result->contendedCount
    );

    // Regla de alerta única: vive en el DTO, no aquí.
    $exitCode = $result->requiresOperatorAttention() ? 1 : 0;

    echo "\n" . str_repeat('=', 56) . "\n";
    if ($exitCode !== 0) {
        echo " Barrido completado CON FALLOS: revisar los expedientes listados.\n";
    } elseif ($result->hasContention()) {
        echo sprintf(
            " Barrido completado con contención de fila: %d aplazado(s), %d cuarentena(s) activada(s).\n",
            $result->contendedCount,
            count($result->quarantined)
        );
    } else {
        echo sprintf(" Barrido completado. Cuarentenas activadas: %d\n", count($result->quarantined));
    }
    echo str_repeat('=', 56) . "\n";

    exit($exitCode);
} catch (Throwable $exception) {
    echo "\n[ERROR FATAL]: " . $exception->getMessage() . "\n";
    exit(1);
}
