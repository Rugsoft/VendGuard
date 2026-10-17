<?php

declare(strict_types=1);

/**
 * VendGuard - Worker CLI del Centro de Notificaciones
 *
 * Módulo 14 · T-NOTIF-09 · RF-01.2, RF-01.3, RF-01.4, RF-04 y RF-06.
 *
 * Uso:
 *   php bin/send_notifications.php [--batch-size=N]
 *
 * Ejecuta UNA pasada del despachador (`NotificationDispatcherService::runPass()`)
 * desde línea de comandos: rescata los envíos atrapados en `PROCESSING` durante
 * más de 5 minutos, evalúa los umbrales de SLA sanitario de 2 h y 3 h, reclama
 * el lote con prioridad `CRITICAL_SANITARY` por delante de cualquier aviso
 * ordinario y entrega con backoff progresivo. Es el mismo `runPass()` que
 * consume `POST /api/cron/notifications-dispatch`: la composición del grafo vive
 * en `NotificationDispatcherFactory`, de modo que el planificador HTTP y el
 * programador de sistema no pueden divergir en transportes ni en auditoría.
 *
 * **Contrato de salida.** La salida estándar contiene exclusivamente el resumen
 * JSON de la pasada (las nueve claves de `NotificationDispatchResultDto`), de
 * forma que un programador pueda canalizarla a `jq` sin recortar banners. Los
 * errores también salen como JSON, con `success = false` y su código, para que
 * quien invoque el worker no tenga que distinguir formatos según el resultado.
 *
 * **Códigos de salida:**
 *   0 = pasada completada. Los fallos de entrega individuales (rechazo
 *       permanente, backoff) se informan en el resumen y en la bandeja de
 *       supervisión; no son un fallo del proceso, y convertir la caída de un
 *       proveedor en un rojo del planificador sólo entrenaría a ignorarlo.
 *   1 = excepción fatal (motor inaccesible, composición rota).
 *   2 = argumentos inválidos (`--batch-size` fuera de 1..200 o no numérico).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO, cero dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */

require_once __DIR__ . '/../src/autoload.php';

use VendGuard\Application\Service\NotificationDispatcherService;
use VendGuard\Infrastructure\Notification\NotificationDispatcherFactory;

const EXIT_OK = 0;
const EXIT_FATAL = 1;
const EXIT_INVALID_ARGUMENTS = 2;

/**
 * Emite un objeto JSON en la salida estándar y termina con el código indicado.
 *
 * @param array<string, mixed> $payload
 */
$emit = static function (array $payload, int $exitCode): void {
    $encoded = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    echo ($encoded !== false ? $encoded : '{"success":false,"error":{"code":"ENCODING_FAILURE"}}') . PHP_EOL;

    exit($exitCode);
};

/**
 * Error de argumentos con el mismo sobre JSON que el resto de la salida.
 */
$failArguments = static function (string $code, string $message) use ($emit): void {
    $emit([
        'success' => false,
        'error' => ['code' => $code, 'message' => $message],
    ], EXIT_INVALID_ARGUMENTS);
};

$usage = static function (): string {
    return 'Uso: php bin/send_notifications.php [--batch-size=N] '
        . '(N entero entre 1 y 200; por defecto '
        . NotificationDispatcherService::DEFAULT_BATCH_SIZE
        . ').';
};

$batchSize = NotificationDispatcherService::DEFAULT_BATCH_SIZE;
$arguments = array_slice($argv ?? [], 1);

for ($index = 0; $index < count($arguments); $index++) {
    $argument = (string)$arguments[$index];

    if ($argument === '--help' || $argument === '-h') {
        $emit([
            'success' => true,
            'usage' => $usage(),
            'batch_size_default' => NotificationDispatcherService::DEFAULT_BATCH_SIZE,
            'batch_size_range' => [1, 200],
        ], EXIT_OK);
    }

    $rawValue = null;
    if ($argument === '--batch-size') {
        // Forma separada: `--batch-size 25`. Sin valor se rechaza en lugar de
        // caer silenciosamente al lote por defecto: un operador que escribe la
        // bandera espera que se aplique.
        $rawValue = $arguments[$index + 1] ?? null;
        if ($rawValue === null || !is_scalar($rawValue)) {
            $failArguments('MISSING_BATCH_SIZE_VALUE', 'La opción --batch-size requiere un valor entero.');
        }
        $index++;
    } elseif (str_starts_with($argument, '--batch-size=')) {
        $rawValue = substr($argument, strlen('--batch-size='));
    } else {
        $failArguments('INVALID_OPTION', sprintf('Opción no reconocida: «%s». %s', $argument, $usage()));
    }

    $candidate = trim((string)$rawValue);
    if ($candidate === '' || !ctype_digit($candidate) || (int)$candidate < 1 || (int)$candidate > 200) {
        $failArguments(
            'INVALID_BATCH_SIZE',
            sprintf('El tamaño del lote debe ser un entero entre 1 y 200 (recibido: «%s»).', (string)$rawValue)
        );
    }

    $batchSize = (int)$candidate;
}

try {
    $result = NotificationDispatcherFactory::create()->runPass($batchSize);
} catch (Throwable $exception) {
    $emit([
        'success' => false,
        'error' => [
            'code' => 'DISPATCH_PASS_FAILED',
            'message' => $exception->getMessage(),
        ],
    ], EXIT_FATAL);
}

$emit(['success' => true, 'data' => $result->toArray()], EXIT_OK);
