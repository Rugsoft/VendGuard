<?php

declare(strict_types=1);

/**
 * IncidentPauseServiceSlaShiftTest
 *
 * Suite unitaria del Algoritmo 3 del módulo 11 (tarea T-PAUSE-06): el
 * desplazamiento del vencimiento contractual de SLA en horario hábil comercial
 * (RF-03.3, RNF-01). Certifica que el cálculo es exacto al segundo y que nunca
 * puede producir un vencimiento artificial:
 *
 * 1. Dentro de la misma jornada hábil el desplazamiento es aritmética directa
 *    (Jueves 12:15 + 90 min = Jueves 13:45).
 * 2. Salto nocturno: lo que no cabe antes del cierre (18:00) continúa a las
 *    08:00 del siguiente día hábil, y una fecha de madrugada o de noche arranca
 *    desde la apertura.
 * 3. Salto de fin de semana: viernes por la tarde continúa el lunes, y una
 *    pausa puede atravesar sábados y domingos sin consumir un solo segundo de
 *    ellos.
 * 4. La fecha devuelta vive siempre dentro de la ventana legal y en la zona
 *    operativa de la sede (Europe/Madrid), incluso si la entrada llega en UTC.
 * 5. La duración nula no altera el reloj (caso límite §6.1 de la especificación
 *    funcional) y la duración negativa o una sede inválida se rechazan de
 *    inmediato (Fail-Fast).
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

/** Sede semilla de trabajo; cualquier entero positivo es válido mientras el calendario sea contractual. */
$locationId = 1;

$madrid = static fn (string $moment): DateTimeImmutable => new DateTimeImmutable($moment, new DateTimeZone('Europe/Madrid'));

/** Desplaza y devuelve la fecha resultante formateada en hora local de sede. */
$shift = static function (string $deadline, int $seconds) use ($service, $locationId, $madrid): DateTimeImmutable {
    return $service->shiftSlaTargetInBusinessHours($madrid($deadline), $seconds, $locationId);
};

$shifted = static function (string $deadline, int $seconds) use ($service, $locationId, $madrid): string {
    return $service->shiftSlaTargetInBusinessHours($madrid($deadline), $seconds, $locationId)
        ->format('Y-m-d H:i:s');
};

// =====================================================================
// GRUPO 1: Sin salto — la pausa cabe en la jornada en curso
// =====================================================================
echo "--- Grupo 1: Desplazamiento dentro de la misma jornada hábil ---\n";

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
    '1.3 La jornada completa cabe hasta el borde de cierre: 08:00 + 36.000 s = 18:00',
    $shifted('2026-10-08 08:00:00', 36000) === '2026-10-08 18:00:00',
    $shifted('2026-10-08 08:00:00', 36000)
);

$assert(
    '1.4 Un minuto exacto no consume el borde de cierre: 17:59 + 60 s = 18:00',
    $shifted('2026-10-08 17:59:00', 60) === '2026-10-08 18:00:00',
    $shifted('2026-10-08 17:59:00', 60)
);

// =====================================================================
// GRUPO 2: Salto nocturno (entre las 18:00 y las 08:00 no se computa nada)
// =====================================================================
echo "\n--- Grupo 2: Salto nocturno ---\n";

$assert(
    '2.1 Jueves 17:30 + 60 min reparte 30 min hoy y 30 min mañana: Viernes 08:30',
    $shifted('2026-10-08 17:30:00', 3600) === '2026-10-09 08:30:00',
    $shifted('2026-10-08 17:30:00', 3600)
);

$assert(
    '2.2 Un vencimiento ya de noche (Jueves 23:00) arranca a la apertura: Viernes 08:10',
    $shifted('2026-10-08 23:00:00', 600) === '2026-10-09 08:10:00',
    $shifted('2026-10-08 23:00:00', 600)
);

$assert(
    '2.3 Un vencimiento de madrugada (Jueves 06:00) se normaliza a las 08:00 y suma: 08:15',
    $shifted('2026-10-08 06:00:00', 900) === '2026-10-08 08:15:00',
    $shifted('2026-10-08 06:00:00', 900)
);

$assert(
    '2.4 Un vencimiento exactamente a las 18:00 salta al día siguiente: Viernes 08:01',
    $shifted('2026-10-08 18:00:00', 60) === '2026-10-09 08:01:00',
    $shifted('2026-10-08 18:00:00', 60)
);

$assert(
    '2.5 Dos jornadas completas desde el lunes: Lunes 08:00 + 72.000 s = Martes 18:00',
    $shifted('2026-10-05 08:00:00', 72000) === '2026-10-06 18:00:00',
    $shifted('2026-10-05 08:00:00', 72000)
);

// =====================================================================
// GRUPO 3: Salto de fin de semana (sábado y domingo no son horas hábiles)
// =====================================================================
echo "\n--- Grupo 3: Salto de fin de semana ---\n";

$assert(
    '3.1 Viernes 17:00 + 120 min cruza el fin de semana: Lunes 09:00',
    $shifted('2026-10-09 17:00:00', 7200) === '2026-10-12 09:00:00',
    $shifted('2026-10-09 17:00:00', 7200)
);

$assert(
    '3.2 Un vencimiento en sábado se traslada al lunes: Sábado 10:00 + 60 min = Lunes 09:00',
    $shifted('2026-10-10 10:00:00', 3600) === '2026-10-12 09:00:00',
    $shifted('2026-10-10 10:00:00', 3600)
);

$assert(
    '3.3 Un vencimiento en domingo por la noche despierta el lunes: 08:30',
    $shifted('2026-10-11 20:00:00', 1800) === '2026-10-12 08:30:00',
    $shifted('2026-10-11 20:00:00', 1800)
);

$assert(
    '3.4 Cinco jornadas hábiles desde el lunes terminan el viernes a las 18:00',
    $shifted('2026-10-05 08:00:00', 180000) === '2026-10-09 18:00:00',
    $shifted('2026-10-05 08:00:00', 180000)
);

$assert(
    '3.5 Una pausa de 25 horas hábiles atravesada por un fin de semana: Viernes 09:00 + 90.000 s = Martes 14:00',
    $shifted('2026-10-09 09:00:00', 90000) === '2026-10-13 14:00:00',
    $shifted('2026-10-09 09:00:00', 90000)
);

$assert(
    '3.6 El fin de semana no consume ni un segundo: 36.000 s desde el viernes 09:00 acaban el lunes a las 09:00',
    $shifted('2026-10-09 09:00:00', 36000) === '2026-10-12 09:00:00',
    $shifted('2026-10-09 09:00:00', 36000)
);

// =====================================================================
// GRUPO 4: Pausa nula, inmutabilidad y ventana legal resultante
// =====================================================================
echo "\n--- Grupo 4: Pausa nula, inmutabilidad y ventana legal ---\n";

$assert(
    '4.1 Duración nula devuelve el vencimiento intacto, incluso de noche (§6.1)',
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
$service->shiftSlaTargetInBusinessHours($sourceDeadline, 5400, $locationId);
$assert(
    '4.3 El método no muta el vencimiento de entrada (inmutabilidad del cálculo)',
    $sourceDeadline->format('Y-m-d H:i:s') === $sourceDeadlineSnapshot,
    $sourceDeadline->format('Y-m-d H:i:s')
);

$legalWindowCases = [
    ['2026-10-08 12:15:00', 5400],
    ['2026-10-08 17:30:00', 3600],
    ['2026-10-08 23:00:00', 600],
    ['2026-10-08 06:00:00', 900],
    ['2026-10-09 17:00:00', 7200],
    ['2026-10-10 10:00:00', 3600],
    ['2026-10-11 20:00:00', 1800],
    ['2026-10-05 08:00:00', 180000],
    ['2026-10-09 09:00:00', 90000],
];

$illegalResults = [];
foreach ($legalWindowCases as [$caseDeadline, $caseSeconds]) {
    $result = $shift($caseDeadline, $caseSeconds);
    $weekday = (int)$result->format('N');
    $secondOfDay = ((int)$result->format('G')) * 3600 + ((int)$result->format('i')) * 60 + (int)$result->format('s');

    if ($weekday > 5 || $secondOfDay < 28800 || $secondOfDay > 64800) {
        $illegalResults[] = $caseDeadline . ' + ' . $caseSeconds . 's -> ' . $result->format('Y-m-d H:i:s (l)');
    }
}
$assert(
    '4.4 Ninguna fecha resultante cae en fin de semana ni fuera de la ventana 08:00-18:00',
    $illegalResults === [],
    implode(' | ', $illegalResults)
);

// =====================================================================
// GRUPO 5: Zona horaria operativa y validación Fail-Fast
// =====================================================================
echo "\n--- Grupo 5: Zona horaria de la sede y validación Fail-Fast ---\n";

$utcDeadline = new DateTimeImmutable('2026-10-08 15:15:00', new DateTimeZone('UTC'));
$utcShifted = $service->shiftSlaTargetInBusinessHours($utcDeadline, 3600, $locationId);
$assert(
    '5.1 Una entrada en UTC se interpreta como 17:15 de sede y vence el viernes a las 08:15',
    $utcShifted->format('Y-m-d H:i:s') === '2026-10-09 08:15:00',
    $utcShifted->format('Y-m-d H:i:s')
);

$assert(
    '5.2 La fecha devuelta se expresa en la zona operativa de la sede (Europe/Madrid)',
    $utcShifted->getTimezone()->getName() === 'Europe/Madrid',
    $utcShifted->getTimezone()->getName()
);

$negativeDuration = $capture(static fn () => $service->shiftSlaTargetInBusinessHours($madrid('2026-10-08 12:15:00'), -1, $locationId));
$assert(
    '5.3 Una duración de pausa negativa se rechaza de inmediato',
    $negativeDuration['threw'] === true,
    $negativeDuration['message']
);

$invalidLocation = $capture(static fn () => $service->shiftSlaTargetInBusinessHours($madrid('2026-10-08 12:15:00'), 600, 0));
$assert(
    '5.4 Una sede sin identificador válido se rechaza en lugar de aplicar el calendario por defecto',
    $invalidLocation['threw'] === true,
    $invalidLocation['message']
);

$negativeLocation = $capture(static fn () => $service->shiftSlaTargetInBusinessHours($madrid('2026-10-08 12:15:00'), 600, -7));
$assert(
    '5.5 Una sede con identificador negativo también se rechaza',
    $negativeLocation['threw'] === true,
    $negativeLocation['message']
);

$zeroPauseInvalidLocation = $capture(static fn () => $service->shiftSlaTargetInBusinessHours($madrid('2026-10-08 12:15:00'), 0, 0));
$assert(
    '5.6 La validación de sede precede al atajo de duración nula',
    $zeroPauseInvalidLocation['threw'] === true,
    $zeroPauseInvalidLocation['message']
);

$assert(
    '5.7 El servicio es final (sin herencia accidental) y no expone estado mutable interno',
    (new ReflectionClass(IncidentPauseService::class))->isFinal() === true
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
