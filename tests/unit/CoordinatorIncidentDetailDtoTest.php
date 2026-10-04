<?php

declare(strict_types=1);

/**
 * CoordinatorIncidentDetailDtoTest
 *
 * Suite de verificación unitaria de la tarea T-IDM-01 (módulo 09, modal de detalle
 * integral de incidencias en triaje). Valida la condición "Hecho cuando":
 * - Existe `src/Application/DTO/CoordinatorIncidentDetailDto.php`.
 * - Declara tipado estricto PHP 8.2+ y es una clase final e inmutable (readonly).
 * - Expone los diez bloques del contrato de `specs/09-incident-detail-modal/plan.md` §2.1.
 * - `toArray()` respeta el orden, las claves y los valores de cada bloque, y es
 *   serializable a JSON sin pérdida.
 *
 * Dogma Vanilla: PHP 8.2 puro, sin dependencias externas. No toca base de datos.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\CoordinatorIncidentDetailDto;

echo "======================================================================\n";
echo " VendGuard: Verificación Unitaria del DTO de Detalle de Incidencia (T-IDM-01)\n";
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

$dtoPath = dirname(__DIR__, 2) . '/src/Application/DTO/CoordinatorIncidentDetailDto.php';

// =====================================================================
// GRUPO 1: Existencia, tipado estricto e inmutabilidad estructural
// =====================================================================
echo "--- Grupo 1: Existencia, tipado estricto e inmutabilidad estructural ---\n";

$fileExists = file_exists($dtoPath);
$assert('1.1 Existe src/Application/DTO/CoordinatorIncidentDetailDto.php', $fileExists, "Ruta no encontrada: {$dtoPath}");

if (!$fileExists) {
    echo "\n[ERROR CRITICO] Fichero del DTO no encontrado. Abortando pruebas.\n";
    exit(1);
}

$source = (string) file_get_contents($dtoPath);
$assert('1.2 El fichero no está vacío', strlen(trim($source)) > 0);
$assert(
    '1.3 Declara tipado estricto (declare(strict_types=1))',
    (bool) preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $source)
);
$assert('1.4 La clase CoordinatorIncidentDetailDto es autocargable', class_exists(CoordinatorIncidentDetailDto::class));

$reflection = new ReflectionClass(CoordinatorIncidentDetailDto::class);
$assert('1.5 La clase es final (no admite herencia que rompa el contrato)', $reflection->isFinal());
$assert('1.6 La clase es readonly (inmutable de raíz, PHP 8.2+)', $reflection->isReadOnly());
$assert(
    '1.7 Declara toArray() como método público',
    $reflection->hasMethod('toArray') && $reflection->getMethod('toArray')->isPublic()
);

// Orden contractual de los diez bloques (plan.md §2.1) y su propiedad camelCase.
$contractBlocks = [
    'incident',
    'location',
    'machine',
    'technician',
    'timeline',
    'sla',
    'technical_intervention',
    'comments',
    'refund',
    'permissions',
];
$propertyNameByBlock = [
    'incident' => 'incident',
    'location' => 'location',
    'machine' => 'machine',
    'technician' => 'technician',
    'timeline' => 'timeline',
    'sla' => 'sla',
    'technical_intervention' => 'technicalIntervention',
    'comments' => 'comments',
    'refund' => 'refund',
    'permissions' => 'permissions',
];

$nextIndex = 8;
foreach ($contractBlocks as $blockKey) {
    $propertyName = $propertyNameByBlock[$blockKey];
    $isReadonlyTypedArray = false;
    $detail = "La propiedad {$propertyName} no existe o no es readonly de tipo array";

    if ($reflection->hasProperty($propertyName)) {
        $property = $reflection->getProperty($propertyName);
        $isReadonlyTypedArray = $property->isReadOnly() && (string) $property->getType() === 'array';
    }

    $assert(
        sprintf('1.%d La propiedad %s es readonly y de tipo array', $nextIndex, $propertyName),
        $isReadonlyTypedArray,
        $detail
    );
    $nextIndex++;
}

// =====================================================================
// GRUPO 2: Contrato de bloques del plan.md §2.1
// =====================================================================
echo "\n--- Grupo 2: Estructura contractual de los diez bloques ---\n";

// Expediente de ejemplo del contrato: avería en pausa por repuestos, reabierta en
// garantía, con reintegro vinculado y datos de pago ya enmascarados en servidor.
$sample = [
    'incident' => [
        'id' => 142,
        'ticket_code' => 'TICK-2026-00142',
        'status' => 'PENDING_PARTS',
        'status_label' => 'Pendiente de Repuestos',
        'urgency' => 'CRITICAL',
        'urgency_label' => 'Crítica',
        'is_reopened' => true,
        'reopened_at' => '2026-10-02 11:30:00',
        'reopened_reason' => 'La máquina volvió a fallar 2 horas después de la reparación del técnico.',
        'description' => 'El compresor no arranca y los sándwiches superan los 9°C.',
        'report_channel' => 'QR_CODE',
        'photo_url' => '/uploads/evidence/evidence_142.jpg',
        'created_at' => '2026-10-01 08:15:00',
        'updated_at' => '2026-10-02 12:00:00',
    ],
    'location' => [
        'id' => 1,
        'name' => 'Hospital del Mar',
        'code' => 'SEDE-BCN-01',
        'address' => 'Passeig Marítim 25-29, Barcelona',
        'floor_zone' => 'Planta 1 - Urgencias Sala de Espera',
        'has_physical_reception' => true,
    ],
    'machine' => [
        'id' => 10,
        'code' => 'VEND-0101',
        'model' => 'FAS Perla Fast Cold',
        'manufacturer' => 'FAS International',
        'type' => 'PERISHABLE_FOOD',
        'type_label' => 'Alimentos Perecederos',
        'has_perishables' => true,
    ],
    'technician' => [
        'assigned' => true,
        'technician_id' => 4,
        'name' => 'Jordi Cruz',
        'operator_code' => 'OP-BCN-04',
        'assigned_at' => '2026-10-01 08:30:00',
        'assigned_by_name' => 'Coordinación Central',
        'reassignment_reason' => null,
    ],
    'timeline' => [
        'created_at' => '2026-10-01 08:15:00',
        'assigned_at' => '2026-10-01 08:30:00',
        'started_at' => '2026-10-01 09:10:00',
        'paused_at' => '2026-10-01 09:45:00',
        'resolved_at' => null,
        'closed_at' => null,
        'time_to_assign_minutes' => 15,
        'time_to_first_response_minutes' => 55,
        'total_elapsed_minutes' => 1665,
    ],
    'sla' => [
        'has_sla_limit' => true,
        'sla_limit_hours' => 4.0,
        'is_active_countdown' => true,
        'is_breached' => true,
        'minutes_remaining' => -65,
        'historical_balance' => 'Incumplido por 1 h 5 min',
        'sla_target_at' => '2026-10-01 12:15:00',
    ],
    'technical_intervention' => [
        'pause' => [
            'is_paused' => true,
            'reason' => 'Fallo en condensador de arranque y relé térmico del compresor.',
            'requested_parts' => [
                [
                    'spare_part_id' => 12,
                    'part_code' => 'SP-FAS-RELAY-01',
                    'description' => 'Relé Térmico Compresor 230V',
                    'quantity' => 1,
                    'is_out_of_catalog' => false,
                    'justification' => null,
                ],
                [
                    'spare_part_id' => null,
                    'part_code' => 'OUT_OF_CATALOG',
                    'description' => 'Abrazadera reforzada antivibración para circuito de cobre',
                    'quantity' => 1,
                    'is_out_of_catalog' => true,
                    'justification' => 'Tubería de cobre suelta genera resonancia y fatiga de material en soporte.',
                ],
            ],
        ],
        'resolution' => [
            'is_resolved' => false,
            'diagnosis' => null,
            'corrective_action' => null,
            'replaced_parts_declared' => false,
            'replaced_parts' => [],
            'total_parts_cost' => 0.00,
        ],
        'cancellation' => [
            'is_cancelled' => false,
            'cancelled_at' => null,
            'cancelled_by_name' => null,
            'reason' => null,
        ],
    ],
    'comments' => [
        [
            'id' => 85,
            'author_type' => 'REPORTER',
            'author_name' => 'Conserjería Hospital',
            'comment_text' => 'El agua gotea por debajo de la máquina.',
            'is_internal' => false,
            'created_at' => '2026-10-01 08:20:00',
        ],
        [
            'id' => 86,
            'author_type' => 'TECHNICIAN',
            'author_name' => 'Jordi Cruz',
            'comment_text' => 'Comprobada fuga en bandeja de desescarche. Pauso aviso esperando recambio.',
            'is_internal' => true,
            'created_at' => '2026-10-01 09:46:00',
        ],
    ],
    'refund' => [
        'has_refund' => true,
        'refund_id' => 5,
        'claim_code' => 'REF-2026-00005',
        'amount' => 2.50,
        'compensation_method' => 'BIZUM',
        'compensation_method_label' => 'Bizum',
        'status' => 'REQUIRES_COORDINATOR_APPROVAL',
        'status_label' => 'Pendiente de Visto Bueno',
        'contact_phone_masked' => '6** *** 789',
        'iban_masked' => null,
        'technician_finding' => 'FOUND_PHYSICAL',
        'cash_custody_action' => 'HELD_FOR_CENTRAL',
        'technician_notes' => 'Moneda de 2€ y 0.50€ retenidas en selector mecánico recuperadas.',
        'refund_tab_url' => '#refunds?id=5',
    ],
    'permissions' => [
        'can_assign' => true,
        'can_reassign' => true,
        'can_cancel' => true,
        'can_add_comment' => true,
    ],
];

$requiredKeysByBlock = [
    'incident' => [
        'id', 'ticket_code', 'status', 'status_label', 'urgency', 'urgency_label',
        'is_reopened', 'reopened_at', 'reopened_reason', 'description', 'report_channel',
        'photo_url', 'created_at', 'updated_at',
    ],
    'location' => ['id', 'name', 'code', 'address', 'floor_zone', 'has_physical_reception'],
    'machine' => ['id', 'code', 'model', 'manufacturer', 'type', 'type_label', 'has_perishables'],
    'technician' => ['assigned', 'technician_id', 'name', 'operator_code', 'assigned_at', 'assigned_by_name', 'reassignment_reason'],
    'timeline' => [
        'created_at', 'assigned_at', 'started_at', 'paused_at', 'resolved_at', 'closed_at',
        'time_to_assign_minutes', 'time_to_first_response_minutes', 'total_elapsed_minutes',
    ],
    'sla' => ['has_sla_limit', 'sla_limit_hours', 'is_active_countdown', 'is_breached', 'minutes_remaining', 'historical_balance', 'sla_target_at'],
    'technical_intervention' => ['pause', 'resolution', 'cancellation'],
    'comments' => [],
    'refund' => [
        'has_refund', 'refund_id', 'claim_code', 'amount', 'compensation_method',
        'compensation_method_label', 'status', 'status_label', 'contact_phone_masked',
        'iban_masked', 'technician_finding', 'cash_custody_action', 'technician_notes',
        'refund_tab_url',
    ],
    'permissions' => ['can_assign', 'can_reassign', 'can_cancel', 'can_add_comment'],
];

$dto = new CoordinatorIncidentDetailDto(
    incident: $sample['incident'],
    location: $sample['location'],
    machine: $sample['machine'],
    technician: $sample['technician'],
    timeline: $sample['timeline'],
    sla: $sample['sla'],
    technicalIntervention: $sample['technical_intervention'],
    comments: $sample['comments'],
    refund: $sample['refund'],
    permissions: $sample['permissions']
);

$payload = $dto->toArray();

$assert(
    '2.1 toArray() devuelve exactamente los diez bloques del contrato, en orden',
    array_keys($payload) === $contractBlocks,
    'Claves obtenidas: ' . implode(', ', array_keys($payload))
);

$contractIndex = 2;
foreach ($contractBlocks as $blockKey) {
    if ($blockKey === 'comments') {
        // La bitácora es una lista cronológica, no un bloque asociativo: se valida
        // como lista y el contenido de cada entrada se comprueba más abajo.
        $assert(
            sprintf('2.%d El bloque comments conserva sus %d entradas en orden', $contractIndex, count($sample['comments'])),
            array_is_list($payload['comments']) && count($payload['comments']) === count($sample['comments'])
        );
        $contractIndex++;
        continue;
    }

    $assert(
        sprintf('2.%d El bloque %s conserva sus %d claves contractuales en orden', $contractIndex, $blockKey, count($requiredKeysByBlock[$blockKey])),
        array_keys($payload[$blockKey]) === $requiredKeysByBlock[$blockKey]
    );
    $contractIndex++;
}

foreach ($contractBlocks as $blockKey) {
    $assert(
        sprintf('2.%d El bloque %s conserva sus valores íntegros (sin transformación)', $contractIndex, $blockKey),
        $payload[$blockKey] === $sample[$blockKey]
    );
    $contractIndex++;
}

$assert(
    sprintf('2.%d technical_intervention.pause expone is_paused/reason/requested_parts', $contractIndex++),
    array_keys($payload['technical_intervention']['pause']) === ['is_paused', 'reason', 'requested_parts']
);
$assert(
    sprintf('2.%d pause.requested_parts expone piezas de catálogo y fuera de catálogo con justificación', $contractIndex++),
    array_keys($payload['technical_intervention']['pause']['requested_parts'][0]) === ['spare_part_id', 'part_code', 'description', 'quantity', 'is_out_of_catalog', 'justification']
        && $payload['technical_intervention']['pause']['requested_parts'][1]['is_out_of_catalog'] === true
        && $payload['technical_intervention']['pause']['requested_parts'][1]['justification'] !== null
);
$assert(
    sprintf('2.%d technical_intervention.resolution expone diagnóstico, acción, declaración y coste congelado', $contractIndex++),
    array_keys($payload['technical_intervention']['resolution']) === ['is_resolved', 'diagnosis', 'corrective_action', 'replaced_parts_declared', 'replaced_parts', 'total_parts_cost']
);
$assert(
    sprintf('2.%d technical_intervention.cancellation expone fecha, operador y motivo de descarte', $contractIndex++),
    array_keys($payload['technical_intervention']['cancellation']) === ['is_cancelled', 'cancelled_at', 'cancelled_by_name', 'reason']
);
$assert(
    sprintf('2.%d Cada comentario expone autor, visibilidad y fecha', $contractIndex++),
    array_keys($payload['comments'][0]) === ['id', 'author_type', 'author_name', 'comment_text', 'is_internal', 'created_at']
        && $payload['comments'][0]['is_internal'] === false
        && $payload['comments'][1]['is_internal'] === true
);

$encoded = json_encode($payload);
$decodedPayload = $encoded === false ? null : json_decode($encoded, true);

$assert(
    sprintf('2.%d El payload es serializable a JSON y su forma canónica re-codifica idéntica', $contractIndex++),
    $encoded !== false && is_array($decodedPayload) && json_encode($decodedPayload) === $encoded,
    $encoded === false
        ? 'json_encode falló: ' . json_last_error_msg()
        : 'El JSON decodificado no se re-codifica de forma idéntica'
);

// JSON define un único tipo numérico: un float integral (4.0) viaja como `4` y vuelve
// como int. La igualdad laxa certifica que todos los valores cruzan el transporte;
// los tipos PHP exactos ya quedaron probados bloque a bloque en 2.12–2.21.
$assert(
    sprintf('2.%d El JSON decodificado conserva todos los valores del payload', $contractIndex++),
    $decodedPayload == $payload,
    'Algún valor no sobrevivió al ciclo encode/decode'
);

// =====================================================================
// GRUPO 3: Inmutabilidad efectiva y formas opcionales
// =====================================================================
echo "\n--- Grupo 3: Inmutabilidad efectiva y resiliencia del contrato ---\n";

$assert('3.1 toArray() es determinista (dos llamadas devuelven lo mismo)', $dto->toArray() === $dto->toArray());

$mutated = false;
try {
    $dto->incident = [];
} catch (\Error $e) {
    $mutated = true;
}
$assert('3.2 Escribir en una propiedad del DTO lanza Error (Art. III: vista no destructiva)', $mutated);

$sample['incident']['status'] = 'TAMPERED';
$assert('3.3 Mutar el array de origen tras construir no altera el DTO', $dto->toArray()['incident']['status'] === 'PENDING_PARTS');

$minimalDto = new CoordinatorIncidentDetailDto(
    incident: $sample['incident'],
    location: $sample['location'],
    machine: array_merge($sample['machine'], ['has_perishables' => false]),
    technician: ['assigned' => false, 'technician_id' => null, 'name' => null, 'operator_code' => null, 'assigned_at' => null, 'assigned_by_name' => null, 'reassignment_reason' => null],
    timeline: $sample['timeline'],
    sla: ['has_sla_limit' => false],
    technicalIntervention: $sample['technical_intervention'],
    comments: [],
    refund: ['has_refund' => false],
    permissions: ['can_assign' => true, 'can_reassign' => false, 'can_cancel' => true, 'can_add_comment' => true]
);
$assert(
    '3.4 Acepta el caso mínimo (sin SLA de frío, sin reintegro) y lo devuelve tal cual',
    $minimalDto->toArray()['sla'] === ['has_sla_limit' => false]
        && $minimalDto->toArray()['refund'] === ['has_refund' => false]
        && $minimalDto->toArray()['comments'] === []
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-IDM-01 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
