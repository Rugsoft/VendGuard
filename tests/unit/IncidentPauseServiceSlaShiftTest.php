<?php

declare(strict_types=1);

/**
 * IncidentPauseServiceSlaShiftTest
 *
 * Suite unitaria del Algoritmo 3 del módulo 11 (tarea T-PAUSE-06): el
 * desplazamiento del vencimiento contractual de SLA en segundos naturales
 * continuos (EARS 02.3.1, C6, RF-03.3, RNF-01). Certifica que:
 *
 * 1. El desplazamiento es aritmética directa de segundos, dentro o fuera de la
 *    jornada: no se recorta a las 08:00-18:00 ni salta noches o fines de semana.
 * 2. La duración nula no altera el reloj y la negativa se rechaza.
 * 3. La fecha devuelta se expresa en la zona operativa de la sede, incluso si
 *    la entrada llega en UTC.
 * 4. El cálculo usa el instante absoluto, de modo que un cambio de hora
 *    estacional no altera la duración contratada.
 * 5. El alias histórico `shiftSlaTargetInBusinessHours()` conserva la firma
 *    publicada, pero delega en el cálculo continuo.
 * 6. La espera natural (`pauseSecondsSince`) no se confunde con la espera hábil
 *    de 72 horas que sigue usando el protocolo de inactividad de sede.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin librerías externas ni red.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\IncidentPauseService;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - IncidentPauseServiceSlaShiftTest (T-PAUSE-06)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
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

/** @return array{threw: bool, message: string} */
$capture = static function (callable $action): array {
    try {
        $action();
    } catch (InvalidArgumentException $exception) {
        return ['threw' => true, 'message' => $exception->getMessage()];
    }

    return ['threw' => false, 'message' => ''];
};

$service = new IncidentPauseService();

/** Sede semilla de trabajo; cualquier entero positivo es válido. */
$locationId = 1;

$madrid = static fn (string $moment): DateTimeImmutable => new DateTimeImmutable($moment, new DateTimeZone('Europe/Madrid'));

/** Desplaza y devuelve la fecha resultante. */
$shift = static function (string $deadline, int $seconds) use ($service, $locationId, $madrid): DateTimeImmutable {
    return $service->shiftSlaTargetBySeconds($madrid($deadline), $seconds, $locationId);
};

$shifted = static function (string $deadline, int $seconds) use ($shift): string {
    return $shift($deadline, $seconds)->format('Y-m-d H:i:s');
};

// =====================================================================
// GRUPO 1: Aritmética directa, sin recortes de jornada
// =====================================================================
echo "--- Grupo 1: Desplazamiento continuo de segundos ---\n";

$assert(
    '1.1 Jueves 12:15 + 90 minutos = Jueves 13:45 (caso base del módulo)',
    $shifted('2026-10-08 12:15:00', 5400) === '2026-10-08 13:45:00',
    $shifted('2026-10-08 12:15:00', 5400)
);

$assert(
    '1.2 Precisión de segundos: Jueves 09:00 + 1 segundo = 09:00:01 (RNF-01)',
    $shifted('2026-10-08 09:00:00', 1) === '2026-10-08 09:00:01',
    $shifted('2026-10-08 09:00:00', 1)
);

$assert(
    '1.3 08:00 + 36.000 s = 18:00, sin normalización de borde',
    $shifted('2026-10-08 08:00:00', 36000) === '2026-10-08 18:00:00',
    $shifted('2026-10-08 08:00:00', 36000)
);

$assert(
    '1.4 17:59 + 60 s = 18:00, sin consumir un segundo de más',
    $shifted('2026-10-08 17:59:00', 60) === '2026-10-08 18:00:00',
    $shifted('2026-10-08 17:59:00', 60)
);

// =====================================================================
// GRUPO 2: La noche no detiene el reloj contractual
// =====================================================================
echo "\n--- Grupo 2: Noche y madrugada cuentan igual ---\n";

$assert(
    '2.1 Jueves 17:30 + 60 min = Jueves 18:30, sin saltar al día siguiente',
    $shifted('2026-10-08 17:30:00', 3600) === '2026-10-08 18:30:00',
    $shifted('2026-10-08 17:30:00', 3600)
);

$assert(
    '2.2 Jueves 23:00 + 10 min = Jueves 23:10, sin esperar a la apertura',
    $shifted('2026-10-08 23:00:00', 600) === '2026-10-08 23:10:00',
    $shifted('2026-10-08 23:00:00', 600)
);

$assert(
    '2.3 Jueves 06:00 + 15 min = Jueves 06:15, sin normalizar a las 08:00',
    $shifted('2026-10-08 06:00:00', 900) === '2026-10-08 06:15:00',
    $shifted('2026-10-08 06:00:00', 900)
);

$assert(
    '2.4 Jueves 18:00 + 60 s = Jueves 18:01, sin saltar al día siguiente',
    $shifted('2026-10-08 18:00:00', 60) === '2026-10-08 18:01:00',
    $shifted('2026-10-08 18:00:00', 60)
);

$assert(
    '2.5 Lunes 08:00 + 72.000 s = Martes 04:00, atravesando la noche',
    $shifted('2026-10-05 08:00:00', 72000) === '2026-10-06 04:00:00',
    $shifted('2026-10-05 08:00:00', 72000)
);

// =====================================================================
// GRUPO 3: El fin de semana tampoco detiene el reloj
// =====================================================================
echo "\n--- Grupo 3: Fin de semana continuo ---\n";

$assert(
    '3.1 Viernes 17:00 + 120 min = Viernes 19:00, sin saltar al lunes',
    $shifted('2026-10-09 17:00:00', 7200) === '2026-10-09 19:00:00',
    $shifted('2026-10-09 17:00:00', 7200)
);

$assert(
    '3.2 Sábado 10:00 + 60 min = Sábado 11:00, sin normalizar al lunes',
    $shifted('2026-10-10 10:00:00', 3600) === '2026-10-10 11:00:00',
    $shifted('2026-10-10 10:00:00', 3600)
);

$assert(
    '3.3 Domingo 20:00 + 30 min = Domingo 20:30, sin normalizar al lunes',
    $shifted('2026-10-11 20:00:00', 1800) === '2026-10-11 20:30:00',
    $shifted('2026-10-11 20:00:00', 1800)
);

$assert(
    '3.4 Lunes 08:00 + 50 h = Miércoles 10:00, contando días naturales',
    $shifted('2026-10-05 08:00:00', 180000) === '2026-10-07 10:00:00',
    $shifted('2026-10-05 08:00:00', 180000)
);

$assert(
    '3.5 Viernes 09:00 + 25 h = Sábado 10:00, contando el sábado',
    $shifted('2026-10-09 09:00:00', 90000) === '2026-10-10 10:00:00',
    $shifted('2026-10-09 09:00:00', 90000)
);

$assert(
    '3.6 Viernes 09:00 + 72 h = Lunes 09:00, sin descontar el fin de semana',
    $shifted('2026-10-09 09:00:00', 259200) === '2026-10-12 09:00:00',
    $shifted('2026-10-09 09:00:00', 259200)
);

// =====================================================================
// GRUPO 4: Pausa nula, inmutabilidad y alias de compatibilidad
// =====================================================================
echo "\n--- Grupo 4: Pausa nula, inmutabilidad y alias ---\n";

$assert(
    '4.1 Duración nula devuelve el vencimiento intacto, incluso de noche',
    $shifted('2026-10-08 23:30:00', 0) === '2026-10-08 23:30:00',
    $shifted('2026-10-08 23:30:00', 0)
);

$assert(
    '4.2 Duración nula en fin de semana tampoco normaliza la fecha',
    $shifted('2026-10-10 03:00:00', 0) === '2026-10-10 03:00:00',
    $shifted('2026-10-10 03:00:00', 0)
);

$sourceDeadline = $madrid('2026-10-08 12:15:00');
$sourceDeadlineSnapshot = $sourceDeadline->format('Y-m-d H:i:s');
$service->shiftSlaTargetBySeconds($sourceDeadline, 5400, $locationId);
$assert(
    '4.3 El método no muta el vencimiento de entrada (inmutabilidad del cálculo)',
    $sourceDeadline->format('Y-m-d H:i:s') === $sourceDeadlineSnapshot,
    $sourceDeadline->format('Y-m-d H:i:s')
);

$assert(
    '4.4 El alias histórico delega en el cálculo continuo y conserva la firma de T-PAUSE-06',
    $service->shiftSlaTargetInBusinessHours($madrid('2026-10-09 17:00:00'), 7200, $locationId)
        ->format('Y-m-d H:i:s') === '2026-10-09 19:00:00'
);

// =====================================================================
// GRUPO 5: Zona horaria operativa y validación Fail-Fast
// =====================================================================
echo "\n--- Grupo 5: Zona horaria de la sede y validación Fail-Fast ---\n";

$utcDeadline = new DateTimeImmutable('2026-10-08 15:15:00', new DateTimeZone('UTC'));
$utcShifted = $service->shiftSlaTargetBySeconds($utcDeadline, 3600, $locationId);
$assert(
    '5.1 Una entrada en UTC se interpreta como 17:15 de sede y suma una hora natural: 18:15',
    $utcShifted->format('Y-m-d H:i:s') === '2026-10-08 18:15:00',
    $utcShifted->format('Y-m-d H:i:s')
);

$assert(
    '5.2 La fecha devuelta se expresa en la zona operativa de la sede (Europe/Madrid)',
    $utcShifted->getTimezone()->getName() === 'Europe/Madrid',
    $utcShifted->getTimezone()->getName()
);

$negativeDuration = $capture(static fn () => $service->shiftSlaTargetBySeconds($madrid('2026-10-08 12:15:00'), -1, $locationId));
$assert(
    '5.3 Una duración de pausa negativa se rechaza de inmediato',
    $negativeDuration['threw'] === true,
    $negativeDuration['message']
);

$invalidLocation = $capture(static fn () => $service->shiftSlaTargetBySeconds($madrid('2026-10-08 12:15:00'), 600, 0));
$assert(
    '5.4 Una sede sin identificador válido se rechaza',
    $invalidLocation['threw'] === true,
    $invalidLocation['message']
);

$negativeLocation = $capture(static fn () => $service->shiftSlaTargetBySeconds($madrid('2026-10-08 12:15:00'), 600, -7));
$assert(
    '5.5 Una sede con identificador negativo también se rechaza',
    $negativeLocation['threw'] === true,
    $negativeLocation['message']
);

$zeroPauseInvalidLocation = $capture(static fn () => $service->shiftSlaTargetBySeconds($madrid('2026-10-08 12:15:00'), 0, 0));
$assert(
    '5.6 La validación de sede precede al atajo de duración nula',
    $zeroPauseInvalidLocation['threw'] === true,
    $zeroPauseInvalidLocation['message']
);

$dstStart = $madrid('2026-10-24 12:00:00');
$dstShifted = $service->shiftSlaTargetBySeconds($dstStart, 86400, $locationId);
$assert(
    '5.7 Un cambio de hora estacional no altera la duración absoluta',
    $dstShifted->getTimestamp() - $dstStart->getTimestamp() === 86400
        && $dstShifted->format('Y-m-d H:i:s') === '2026-10-25 11:00:00',
    $dstShifted->format('Y-m-d H:i:s')
);

$assert(
    '5.8 El servicio es final (sin herencia accidental) y no expone estado mutable interno',
    (new ReflectionClass(IncidentPauseService::class))->isFinal() === true
);

// =====================================================================
// GRUPO 6: La espera natural no se confunde con el umbral de 72 h hábiles
// =====================================================================
echo "\n--- Grupo 6: Espera natural frente a espera hábil ---\n";

$weekendPauseNatural = $service->pauseSecondsSince('2026-10-09 17:00:00', $madrid('2026-10-12 09:00:00'));
$weekendPauseBusiness = $service->pauseBusinessSecondsSince('2026-10-09 17:00:00', $madrid('2026-10-12 09:00:00'));
$assert(
    '6.1 La espera natural cuenta 64 h y la hábil 2 h para el mismo fin de semana',
    $weekendPauseNatural === 64 * 3600 && $weekendPauseBusiness === 2 * 3600,
    "natural={$weekendPauseNatural} s · hábil={$weekendPauseBusiness} s"
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-PAUSE-06 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
