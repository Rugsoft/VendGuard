<?php

declare(strict_types=1);

/**
 * OfflineSyncClockAndQuotaTest · T-OFFLINE-04
 *
 * Certifica el sello temporal efectivo de las mutaciones sin conexión y la
 * guarda sanitaria que acompaña a cada operación (Módulo 24, RF-03.4, RF-04.2,
 * RF-04.3, D2, QA 1 y QA 4).
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Rango lógico (RF-04.3, QA 4).** Un `client_occurred_at` situado entre
 *      el hito previo del expediente y el instante de sincronización más cinco
 *      minutos se adopta tal cual, con `clock_skew_adjusted = false`; los
 *      extremos del intervalo entran y un segundo fuera de él ya no entra.
 *   2. **Preservación de Δt (RF-04.3, QA 4).** Con el reloj del teléfono
 *      atrasado 24 horas o adelantado 2 horas, una cadena *Iniciar* → *Resolver*
 *      de 25 minutos conserva exactamente esos 25 minutos: el cierre se ancla en
 *      `T_sync` y el inicio en `max(T_prev, T_sync − Δt)`. El suelo del hito
 *      previo recorta la duración cuando la asignación es más reciente, en lugar
 *      de inventar tiempo anterior al expediente.
 *   3. **Guarda de precinto (QA 1, Art. II).** El value object transporta la
 *      confirmación sin inventarla —`false` si el cuerpo no la trae— y la
 *      excepción expone el código y el estado `422` que el servicio debe lanzar
 *      cuando falta en una pausa de riesgo sobre `FOOD_PERISHABLE`.
 *   4. **Clave de idempotencia (RF-04.2).** El identificador de operación
 *      respeta el alfabeto y la longitud que el motor exige, porque es la clave
 *      única por técnico.
 *
 * La cuota de 5 fotografías / 25 MB no se certifica aquí: vive en `IndexedDB` y
 * la cubre la suite ESM `OfflineStorageAndSyncManagerTest.mjs` (T-OFFLINE-13).
 * El nombre de este fichero lo fija el plan de tareas, no su alcance.
 *
 * Esta suite es hermética: no toca el motor ni la base de datos.
 *
 * Uso: php tests/unit/OfflineSyncClockAndQuotaTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\OfflineSyncValidationException;
use VendGuard\Core\Domain\ValueObject\OfflineSyncMetadata;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($ok) {
        echo "  [PASS] {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";

        return;
    }
    $failures++;
    echo "  [FAIL] {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
};

echo "======================================================================\n";
echo " VENDGUARD · T-OFFLINE-04 · Reloj efectivo y guarda sanitaria (Módulo 24)\n";
echo "======================================================================\n";

$utc = new DateTimeZone('UTC');

/** Marca compacta para los mensajes de detalle. */
$stamp = static fn (DateTimeImmutable $value): string => $value->setTimezone($utc)->format('Y-m-d H:i:s');

$synced = new DateTimeImmutable('2026-10-27T11:00:00Z');
$previousMilestone = new DateTimeImmutable('2026-10-27T08:00:00Z');

// =============================================================================
// 1. Rango lógico: [T_prev, T_sync + 5 min]
// =============================================================================
echo "\n--- 1. Rango lógico del sello declarado (RF-04.3) ---\n";

$atPreviousMilestone = new OfflineSyncMetadata(
    'mut_clock_boundary_prev',
    $previousMilestone
);
$boundaryStart = $atPreviousMilestone->resolveEffectiveTimestamp($previousMilestone, $synced);
$assert(
    '1.1 El instante exactamente igual al hito previo entra sin ajuste',
    $boundaryStart['clock_skew_adjusted'] === false
        && $boundaryStart['effective_occurred_at']->getTimestamp() === $previousMilestone->getTimestamp(),
    $stamp($boundaryStart['effective_occurred_at']) . ' · adjusted=' . ($boundaryStart['clock_skew_adjusted'] ? 'true' : 'false')
);

$atFutureTolerance = new OfflineSyncMetadata(
    'mut_clock_boundary_future',
    $synced->modify('+300 seconds')
);
$boundaryFuture = $atFutureTolerance->resolveEffectiveTimestamp($previousMilestone, $synced);
$assert(
    '1.2 El extremo de tolerancia T_sync + 5 min entra sin ajuste',
    $boundaryFuture['clock_skew_adjusted'] === false
        && $boundaryFuture['effective_occurred_at']->getTimestamp() === $synced->getTimestamp() + 300,
    $stamp($boundaryFuture['effective_occurred_at'])
);

$inRange = new OfflineSyncMetadata(
    'mut_20261027_091523_a8f49c12b3e14d90',
    $synced->modify('-55 minutes')
);
$inRangeDecision = $inRange->resolveEffectiveTimestamp($previousMilestone, $synced);
$assert(
    '1.3 Un instante intermedio se adopta literalmente',
    $inRangeDecision['clock_skew_adjusted'] === false
        && $inRangeDecision['effective_occurred_at']->getTimestamp() === $synced->getTimestamp() - 3300,
    $stamp($inRangeDecision['effective_occurred_at'])
);

$oneSecondBeyondTolerance = new OfflineSyncMetadata(
    'mut_clock_isolated_future',
    $synced->modify('+301 seconds')
);
$isolatedDecision = $oneSecondBeyondTolerance->resolveEffectiveTimestamp($previousMilestone, $synced);
$assert(
    '1.4 Un segundo más allá de la tolerancia ya se ancla a T_sync',
    $isolatedDecision['clock_skew_adjusted'] === true
        && $isolatedDecision['effective_occurred_at']->getTimestamp() === $synced->getTimestamp(),
    $stamp($isolatedDecision['effective_occurred_at'])
);

$beforePreviousMilestone = new OfflineSyncMetadata(
    'mut_clock_isolated_past',
    $previousMilestone->modify('-1 second')
);
$pastDecision = $beforePreviousMilestone->resolveEffectiveTimestamp($previousMilestone, $synced);
// Fuera de rango por el pasado, y sin cadena que preserve: el contrato ancla la
// acción aislada en T_sync; el sello del dispositivo no retrocede el expediente.
$assert(
    '1.5 Un instante anterior al hito previo tampoco se adopta: la acción aislada cae a T_sync',
    $pastDecision['clock_skew_adjusted'] === true
        && $pastDecision['effective_occurred_at']->getTimestamp() === $synced->getTimestamp(),
    $stamp($pastDecision['effective_occurred_at'])
);

// =============================================================================
// 2. Preservación de Δt en cadenas con el reloj desajustado (QA 4)
// =============================================================================
echo "\n--- 2. Cadenas Iniciar → Resolver con reloj desajustado (QA 4) ---\n";

/**
 * Construye la cadena de 25 minutos con el desfase indicado y devuelve los dos
 * veredictos: el del paso inicial y el del cierre.
 *
 * @return array{start: array{effective_occurred_at: DateTimeImmutable, clock_skew_adjusted: bool}, close: array{effective_occurred_at: DateTimeImmutable, clock_skew_adjusted: bool}}
 */
$resolveChain = static function (
    DateTimeImmutable $syncedAt,
    DateTimeImmutable $previous,
    DateInterval $skew
) use ($utc): array {
    $lostMinutes = 25;
    $realStart = $syncedAt->sub(new DateInterval('PT' . $lostMinutes . 'M'));

    // El dispositivo cree que empezó en otro instante: su reloj va desplazado,
    // pero el intervalo entre los dos pasos sigue siendo de 25 minutos.
    $deviceStart = $realStart->add($skew);
    $deviceEnd = $deviceStart->add(new DateInterval('PT' . $lostMinutes . 'M'));

    $startMetadata = new OfflineSyncMetadata(
        'mut_chain_start_0001',
        $deviceStart,
        null,
        $deviceEnd
    );
    $closeMetadata = new OfflineSyncMetadata(
        'mut_chain_close_0001',
        $deviceEnd,
        $deviceStart
    );

    // En producción el cierre recibe como hito previo el `started_at` que dejó
    // el inicio (su instante efectivo), no la asignación original. Reproducirlo
    // evita medir una cadena que en el servidor no existe así.
    $start = $startMetadata->resolveEffectiveTimestamp($previous, $syncedAt);

    return [
        'start' => $start,
        'close' => $closeMetadata->resolveEffectiveTimestamp($start['effective_occurred_at'], $syncedAt),
    ];
};

foreach ([
    'atrasado 24 horas' => new DateInterval('PT24H'),
    'adelantado 2 horas' => new DateInterval('PT2H'),
] as $label => $skew) {
    $chain = $resolveChain($synced, $previousMilestone, $skew);
    $start = $chain['start'];
    $close = $chain['close'];
    $deltaSeconds = $close['effective_occurred_at']->getTimestamp() - $start['effective_occurred_at']->getTimestamp();

    $assert(
        "2.x Con el reloj {$label}, el inicio se ancla a T_sync − 25 min",
        $start['clock_skew_adjusted'] === true
            && $start['effective_occurred_at']->getTimestamp() === $synced->getTimestamp() - 1500,
        $stamp($start['effective_occurred_at'])
    );
    $assert(
        "2.x Con el reloj {$label}, el cierre se ancla a T_sync",
        $close['clock_skew_adjusted'] === true
            && $close['effective_occurred_at']->getTimestamp() === $synced->getTimestamp(),
        $stamp($close['effective_occurred_at'])
    );
    $assert(
        "2.x Con el reloj {$label}, la intervención conserva exactamente 25 minutos",
        $deltaSeconds === 1500,
        "Δt={$deltaSeconds} s"
    );
}

// El suelo del hito previo manda cuando la asignación es más reciente que
// T_sync − Δt: se conserva lo que el expediente permite, no tiempo inventado.
$recentMilestone = $synced->modify('-10 minutes');
$recentChain = $resolveChain($synced, $recentMilestone, new DateInterval('PT24H'));
$recentDelta = $recentChain['close']['effective_occurred_at']->getTimestamp()
    - $recentChain['start']['effective_occurred_at']->getTimestamp();
$assert(
    '2.5 El hito previo recorta Δt cuando la asignación es más reciente que T_sync − Δt',
    $recentChain['start']['effective_occurred_at']->getTimestamp() === $recentMilestone->getTimestamp()
        && $recentChain['close']['effective_occurred_at']->getTimestamp() === $synced->getTimestamp()
        && $recentDelta === 600,
    $stamp($recentChain['start']['effective_occurred_at']) . ' → ' . $stamp($recentChain['close']['effective_occurred_at'])
);

// Una cadena más larga que el tope de 12 horas no puede reutilizarse: no hay
// Δt coherente que preservar y el paso cae a T_sync.
$tooLongMetadata = new OfflineSyncMetadata(
    'mut_chain_too_long_01',
    $synced->modify('-13 hours'),
    $synced->modify('-13 hours'),
    $synced->modify('-30 minutes')
);
$tooLongDecision = $tooLongMetadata->resolveEffectiveTimestamp($previousMilestone, $synced);
$assert(
    '2.6 Un Δt superior a 12 horas no se usa como cadena coherente',
    $tooLongDecision['clock_skew_adjusted'] === true
        && $tooLongDecision['effective_occurred_at']->getTimestamp() === $synced->getTimestamp(),
    $stamp($tooLongDecision['effective_occurred_at'])
);

// =============================================================================
// 3. Guarda sanitaria: precinto físico in situ (QA 1, Art. II)
// =============================================================================
echo "\n--- 3. Confirmación de precinto en máquinas perecederas (QA 1) ---\n";

$sealedMutation = OfflineSyncMetadata::fromArray([
    'client_mutation_id' => 'mut_20261027_091523_a8f49c12b3e14d90',
    'client_occurred_at' => '2026-10-27T09:15:23.000Z',
    'sanitary_seal_confirmed' => true,
]);
$assert(
    '3.1 El value object conserva la confirmación expresa del precinto',
    $sealedMutation instanceof OfflineSyncMetadata && $sealedMutation->sanitarySealConfirmed === true
);

$unsealedMutation = OfflineSyncMetadata::fromArray([
    'client_mutation_id' => 'mut_20261027_091523_a8f49c12b3e14d90',
    'client_occurred_at' => '2026-10-27T09:15:23.000Z',
]);
$assert(
    '3.2 Sin el campo, la confirmación es falsa: el value object no la inventa',
    $unsealedMutation instanceof OfflineSyncMetadata && $unsealedMutation->sanitarySealConfirmed === false
);

$sealRejection = null;
try {
    throw OfflineSyncValidationException::sanitarySealConfirmationRequired();
} catch (OfflineSyncValidationException $exception) {
    $sealRejection = $exception;
}
$assert(
    '3.3 La falta de precinto se rechaza con 422 SANITARY_SEAL_CONFIRMATION_REQUIRED',
    $sealRejection instanceof OfflineSyncValidationException
        && $sealRejection->getErrorCode() === OfflineSyncValidationException::SANITARY_SEAL_CONFIRMATION_REQUIRED
        && $sealRejection->getHttpStatusCode() === 422
);
$assert(
    '3.4 El mensaje pide confirmar el precinto físico antes de guardar',
    $sealRejection instanceof OfflineSyncValidationException
        && str_contains(mb_strtolower($sealRejection->getMessage()), 'precintado')
);

// =============================================================================
// 4. Clave de idempotencia (RF-04.2)
// =============================================================================
echo "\n--- 4. Formato de la clave de idempotencia (RF-04.2) ---\n";

$assert(
    '4.1 Se aceptan longitudes de 8 a 64 sobre el alfabeto contractual',
    OfflineSyncMetadata::isValidMutationId('mut_0001')
        && OfflineSyncMetadata::isValidMutationId(str_repeat('a', 64))
        && OfflineSyncMetadata::isValidMutationId('mut_20261027_091523_a8f49c12b3e14d90')
);

$invalidIdRejection = null;
try {
    new OfflineSyncMetadata('mut_1', new DateTimeImmutable('2026-10-27T09:15:23Z'));
} catch (OfflineSyncValidationException $exception) {
    $invalidIdRejection = $exception;
}
$assert(
    '4.2 Un identificador corto o fuera de alfabeto se rechaza con 422',
    OfflineSyncMetadata::isValidMutationId('mut_1') === false
        && OfflineSyncMetadata::isValidMutationId('mut con espacios') === false
        && $invalidIdRejection instanceof OfflineSyncValidationException
        && $invalidIdRejection->getErrorCode() === OfflineSyncValidationException::INVALID_OFFLINE_DUMP_PAYLOAD
        && $invalidIdRejection->getHttpStatusCode() === 422
);

// =============================================================================
// Resumen de Ejecución
// =============================================================================
echo "\n==========================================================================\n";
echo " RESUMEN: {$assertions} aserciones evaluadas. ";
if ($failures === 0) {
    echo "TODAS LAS PRUEBAS PASARON (100% OK).\n";
} else {
    echo "{$failures} FALLOS DETECTADOS.\n";
}
echo "==========================================================================\n";

exit($failures === 0 ? 0 : 1);
