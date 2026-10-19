<?php

declare(strict_types=1);

/**
 * OpportunisticLocationEligibilityPolicyTest
 *
 * Suite de pruebas unitarias de la política pura de habilitación de sede del
 * Módulo 16 (T-OPP-04, RF-01.1 a RF-01.3 y Resolución QA 3).
 *
 * Certifica los bordes exactos del contrato sin base de datos:
 *  - habilitación por parada correctiva (`ASSIGNED` / `IN_PROGRESS`) o preventiva
 *    (`SCHEDULED` / `IN_INSPECTION`);
 *  - ventana de gracia de `1800 s` desde la última salida (`RESOLVED`,
 *    `PENDING_PARTS`, `PENDING_INFO` o `COMPLETED`);
 *  - tolerancia de `2100 s` solo cuando el formulario ya estaba abierto;
 *  - rechazo de un `PENDING_INFO` antiguo (la sede no queda habilitada para
 *    siempre) y de marcas de salida futuras.
 *
 * Dogma Vanilla: ejecutable nativamente sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Exception\LocationNotEnabledForOpportunisticException;
use VendGuard\Core\Service\OpportunisticLocationEligibilityPolicy;

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - OpportunisticLocationEligibilityPolicyTest\n";
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

$policy = new OpportunisticLocationEligibilityPolicy();

$now = new DateTimeImmutable('2026-10-19 09:00:00', new DateTimeZone('Europe/Madrid'));
$locationId = 3;
$technicianId = 4;

$stop = static fn (string $status, int $location = 3): array => [
    'status' => $status,
    'location_id' => $location,
];

// =====================================================================
// GRUPO 1: Habilitación por parada correctiva activa (RF-01.1.1)
// =====================================================================
echo "--- Grupo 1: Parada correctiva activa ---\n";

foreach ([
    'ASSIGNED' => 'Asignada',
    'IN_PROGRESS' => 'En Curso',
] as $status => $label) {
    $decision = $policy->evaluate($technicianId, $locationId, $now, [$stop($status)]);
    $assert(
        "1.{$status} habilita la sede como ACTIVE_STOP ({$label})",
        $decision['eligible'] === true
            && $decision['enablement_type'] === OpportunisticLocationEligibilityPolicy::ACTIVE_STOP
    );
    $assert(
        "1.{$status} no anuncia caducidad ni segundos de gracia",
        $decision['grace_expires_at'] === null
            && $decision['grace_remaining_seconds'] === null
            && $decision['window_seconds'] === 0
            && $decision['blocked_reason'] === null
    );
}

$decision = $policy->evaluate($technicianId, $locationId, $now, [$stop('ASSIGNED', 9)]);
$assert(
    '1.3 Una parada correctiva en OTRA sede no habilita esta',
    $decision['eligible'] === false
        && $decision['blocked_reason'] === OpportunisticLocationEligibilityPolicy::REASON_NO_ACTIVE_STOP
);

$decision = $policy->evaluate(
    $technicianId,
    $locationId,
    $now,
    [$stop('PENDING_INFO'), $stop('ASSIGNED')]
);
$assert(
    '1.4 Una parada pausada convive con la activa: la sede sigue habilitada',
    $decision['eligible'] === true
        && $decision['enablement_type'] === OpportunisticLocationEligibilityPolicy::ACTIVE_STOP
);

// =====================================================================
// GRUPO 2: Habilitación por orden preventiva activa (RF-01.1.1)
// =====================================================================
echo "\n--- Grupo 2: Orden preventiva activa ---\n";

foreach ([
    'SCHEDULED' => 'Programada',
    'IN_INSPECTION' => 'En inspección',
] as $status => $label) {
    $decision = $policy->evaluate($technicianId, $locationId, $now, [], [$stop($status)]);
    $assert(
        "2.{$status} habilita la sede como ACTIVE_STOP ({$label})",
        $decision['eligible'] === true
            && $decision['enablement_type'] === OpportunisticLocationEligibilityPolicy::ACTIVE_STOP
    );
}

$decision = $policy->evaluate($technicianId, $locationId, $now, [], [$stop('COMPLETED')]);
$assert(
    '2.3 Una orden preventiva completada sin marca de salida no habilita por sí sola',
    $decision['eligible'] === false
);

// =====================================================================
// GRUPO 3: Estados pausados sin ventana vigente no habilitan (QA 3)
// =====================================================================
echo "\n--- Grupo 3: Estados pausados o terminales sin visita reciente ---\n";

foreach (['PENDING_INFO', 'PENDING_PARTS', 'RESOLVED', 'COMPLETED', 'CLOSED', 'CANCELLED'] as $status) {
    $decision = $policy->evaluate($technicianId, $locationId, $now, [$stop($status)]);
    $assert(
        "3.{$status} sin marca de salida no habilita la sede (403 lógico)",
        $decision['eligible'] === false
            && $decision['blocked_reason'] === OpportunisticLocationEligibilityPolicy::REASON_NO_ACTIVE_STOP
    );
}

$assert(
    '3.7 El motivo de bloqueo tiene copy castellano específico',
    str_contains(
        $policy->blockedReasonLabel(OpportunisticLocationEligibilityPolicy::REASON_NO_ACTIVE_STOP),
        'parada activa'
    )
);

// =====================================================================
// GRUPO 4: Ventana exacta de 1800 s (RF-01.1.2, Resolución QA 3)
// =====================================================================
echo "\n--- Grupo 4: Ventana de gracia exacta de 1800 s ---\n";

foreach ([0, 900, 1799, 1800] as $elapsed) {
    $exitAt = $now->modify("-{$elapsed} seconds");
    $decision = $policy->evaluate(
        $technicianId,
        $locationId,
        $now,
        [$stop('RESOLVED')],
        [],
        $exitAt
    );

    $assert(
        "4.{$elapsed} Con {$elapsed} s desde el cierre la sede sigue habilitada",
        $decision['eligible'] === true
            && $decision['enablement_type'] === OpportunisticLocationEligibilityPolicy::GRACE_WINDOW_30M
    );
    $assert(
        "4.{$elapsed}b La caducidad visible es cierre + 30 min",
        $decision['grace_expires_at'] === $exitAt->modify('+1800 seconds')->format(DATE_ATOM)
    );
    $assert(
        "4.{$elapsed}c Quedan " . max(0, 1800 - $elapsed) . " s anunciados",
        $decision['grace_remaining_seconds'] === max(0, 1800 - $elapsed)
            && $decision['window_seconds'] === 1800
    );
}

$decision = $policy->evaluate(
    $technicianId,
    $locationId,
    $now,
    [$stop('RESOLVED')],
    [],
    $now->modify('-1801 seconds')
);
$assert(
    '4.5 Con 1801 s y sin formulario abierto la sede queda bloqueada',
    $decision['eligible'] === false
        && $decision['blocked_reason'] === OpportunisticLocationEligibilityPolicy::REASON_GRACE_WINDOW_EXPIRED
);
$assert(
    '4.6 El bloqueo por caducidad documenta el estado pausado previo',
    str_contains(
        $policy->blockedReasonLabel(OpportunisticLocationEligibilityPolicy::REASON_GRACE_WINDOW_EXPIRED),
        'ventana de gracia'
    )
);

// =====================================================================
// GRUPO 5: Tolerancia de +5 min con formulario abierto (RF-01.3)
// =====================================================================
echo "\n--- Grupo 5: Tolerancia de 2100 s con formulario abierto ---\n";

foreach ([1801, 2000, 2099, 2100] as $elapsed) {
    $exitAt = $now->modify("-{$elapsed} seconds");
    $decision = $policy->evaluate(
        $technicianId,
        $locationId,
        $now,
        [$stop('PENDING_INFO')],
        [],
        $exitAt,
        true
    );

    $assert(
        "5.{$elapsed} Con {$elapsed} s y formulario abierto el envío se tolera",
        $decision['eligible'] === true
            && $decision['enablement_type'] === OpportunisticLocationEligibilityPolicy::GRACE_WINDOW_30M
    );
    $assert(
        "5.{$elapsed}b La tolerancia no desplaza la caducidad visible del minuto 30",
        $decision['grace_expires_at'] === $exitAt->modify('+1800 seconds')->format(DATE_ATOM)
            && $decision['grace_remaining_seconds'] === 0
            && $decision['window_seconds'] === 2100
    );
}

$decision = $policy->evaluate(
    $technicianId,
    $locationId,
    $now,
    [$stop('PENDING_INFO')],
    [],
    $now->modify('-2101 seconds'),
    true
);
$assert(
    '5.5 Con 2101 s ni siquiera el formulario abierto salva el envío',
    $decision['eligible'] === false
        && $decision['blocked_reason'] === OpportunisticLocationEligibilityPolicy::REASON_GRACE_WINDOW_EXPIRED
);

$decision = $policy->evaluate(
    $technicianId,
    $locationId,
    $now,
    [$stop('PENDING_PARTS')],
    [],
    $now->modify('-1801 seconds'),
    false
);
$assert(
    '5.6 Con 1801 s pero sin formulario abierto se rechaza igualmente',
    $decision['eligible'] === false
);

$decision = $policy->evaluate(
    $technicianId,
    $locationId,
    $now,
    [],
    [],
    null,
    true
);
$assert(
    '5.7 El flag de formulario abierto no inventa una ventana sin salida registrada',
    $decision['eligible'] === false
        && $decision['blocked_reason'] === OpportunisticLocationEligibilityPolicy::REASON_NO_ACTIVE_STOP
);

// =====================================================================
// GRUPO 6: PENDING_INFO antiguo y desfase de relojes (QA 3, caso límite 3)
// =====================================================================
echo "\n--- Grupo 6: Pausa antigua y marcas futuras ---\n";

$decision = $policy->evaluate(
    $technicianId,
    $locationId,
    $now,
    [$stop('PENDING_INFO')],
    [],
    $now->modify('-1 day')
);
$assert(
    '6.1 Un PENDING_INFO de ayer no habilita la sede hoy',
    $decision['eligible'] === false
        && $decision['blocked_reason'] === OpportunisticLocationEligibilityPolicy::REASON_GRACE_WINDOW_EXPIRED
);

$decision = $policy->evaluate(
    $technicianId,
    $locationId,
    $now,
    [],
    [],
    $now->modify('+60 seconds')
);
$assert(
    '6.2 Una salida registrada en el futuro no concede gracia por desfase',
    $decision['eligible'] === false
        && $decision['blocked_reason'] === OpportunisticLocationEligibilityPolicy::REASON_GRACE_REFERENCE_IN_FUTURE
);
$assert(
    '6.3 El motivo de desfase tiene copy castellano específico',
    str_contains(
        $policy->blockedReasonLabel(OpportunisticLocationEligibilityPolicy::REASON_GRACE_REFERENCE_IN_FUTURE),
        'posterior al instante actual'
    )
);

$decision = $policy->evaluate($technicianId, $locationId, $now, [], [], $now);
$assert(
    '6.4 Una salida en el instante exacto abre la ventana completa',
    $decision['eligible'] === true
        && $decision['grace_remaining_seconds'] === 1800
);

$assert(
    '6.5 La etiqueta por defecto describe la sede habilitada',
    str_contains($policy->blockedReasonLabel(null), 'habilitada')
);

// =====================================================================
// GRUPO 7: Puerta de excepción tipada y atajos
// =====================================================================
echo "\n--- Grupo 7: requireEligibility() e isEligible() ---\n";

$thrown = null;
try {
    $policy->requireEligibility($technicianId, $locationId, $now);
} catch (LocationNotEnabledForOpportunisticException $e) {
    $thrown = $e;
} catch (Throwable $e) {
    $thrown = $e;
}

$assert(
    '7.1 requireEligibility lanza LocationNotEnabledForOpportunisticException',
    $thrown instanceof LocationNotEnabledForOpportunisticException,
    $thrown === null ? 'No se lanzó ninguna excepción' : get_class($thrown)
);
$assert(
    '7.2 La excepción conserva código de error y estado HTTP 403',
    $thrown instanceof LocationNotEnabledForOpportunisticException
        && $thrown->getErrorCode() === 'LOCATION_NOT_ENABLED_FOR_OPPORTUNISTIC'
        && $thrown->getHttpStatusCode() === 403
);
$assert(
    '7.3 La excepción identifica sede y técnico',
    $thrown instanceof LocationNotEnabledForOpportunisticException
        && $thrown->getLocationId() === $locationId
        && $thrown->getTechnicianId() === $technicianId
);
$assert(
    '7.4 El mensaje de la excepción explica la ventana de 30 minutos en castellano',
    $thrown instanceof LocationNotEnabledForOpportunisticException
        && str_contains($thrown->getMessage(), '30 minutos')
);

$decision = $policy->requireEligibility($technicianId, $locationId, $now, [$stop('ASSIGNED')]);
$assert(
    '7.5 Con parada activa requireEligibility devuelve la decisión sin lanzar',
    $decision['eligible'] === true
        && $decision['enablement_type'] === OpportunisticLocationEligibilityPolicy::ACTIVE_STOP
);

$assert(
    '7.6 isEligible refleja la misma decisión que evaluate',
    $policy->isEligible($technicianId, $locationId, $now, [$stop('ASSIGNED')]) === true
        && $policy->isEligible($technicianId, $locationId, $now) === false
);

// =====================================================================
// GRUPO 8: Entradas inválidas
// =====================================================================
echo "\n--- Grupo 8: Identificadores inválidos ---\n";

$rejectedTechnician = false;
try {
    $policy->evaluate(0, $locationId, $now);
} catch (InvalidArgumentException $e) {
    $rejectedTechnician = true;
}

$rejectedLocation = false;
try {
    $policy->evaluate($technicianId, -1, $now);
} catch (InvalidArgumentException $e) {
    $rejectedLocation = true;
}

$assert('8.1 Un técnico sin identificador positivo se rechaza', $rejectedTechnician);
$assert('8.2 Una sede sin identificador positivo se rechaza', $rejectedLocation);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-OPP-04 (HABILITACIÓN) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
