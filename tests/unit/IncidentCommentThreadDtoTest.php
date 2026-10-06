<?php

declare(strict_types=1);

/**
 * IncidentCommentThreadDtoTest
 *
 * Suite de verificación unitaria de la tarea T-COM-04 (módulo 10, hilo de
 * comentarios bidireccional con notas internas confidenciales). Valida la
 * condición "Hecho cuando" sobre los DTOs de T-COM-01:
 * - Serialización inmutable: `jsonSerialize()`/`toArray()` de
 *   `IncidentCommentItemDto` e `IncidentCommentThreadDto` producen
 *   exactamente la estructura JSON contractual (plan.md §2.1).
 * - Perfil de Sede: cero notas internas y cero fuga del campo `is_internal`
 *   en el payload — la clave se omite por completo, sin valor nulo ni
 *   metadato deducible (RF-02.1 / RNF-01 / Art. V.4).
 * - Perfil Técnico/Coordinador: el campo `is_internal` viaja como candado de
 *   confidencialidad visible (RF-02.3, RF-02.4).
 * - Inmutabilidad efectiva de ambos DTOs (Art. III).
 *
 * Dogma Vanilla: PHP 8.2 puro, sin dependencias externas. No toca base de datos.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\IncidentCommentItemDto;
use VendGuard\Application\DTO\IncidentCommentThreadDto;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria de DTOs del Hilo de Comentarios (Módulo 10, T-COM-04)\n";
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

$itemDtoPath = dirname(__DIR__, 2) . '/src/Application/DTO/IncidentCommentItemDto.php';
$threadDtoPath = dirname(__DIR__, 2) . '/src/Application/DTO/IncidentCommentThreadDto.php';

// =====================================================================
// GRUPO 1: Existencia, tipado estricto e inmutabilidad estructural
// =====================================================================
echo "--- Grupo 1: Existencia, tipado estricto e inmutabilidad estructural ---\n";

$assert('1.1 Existe src/Application/DTO/IncidentCommentItemDto.php', file_exists($itemDtoPath), "Ruta no encontrada: {$itemDtoPath}");
$assert('1.2 Existe src/Application/DTO/IncidentCommentThreadDto.php', file_exists($threadDtoPath), "Ruta no encontrada: {$threadDtoPath}");

if (!file_exists($itemDtoPath) || !file_exists($threadDtoPath)) {
    echo "\n[ERROR CRITICO] Ficheros de los DTOs no encontrados. Abortando pruebas.\n";
    exit(1);
}

$itemSource = (string) file_get_contents($itemDtoPath);
$threadSource = (string) file_get_contents($threadDtoPath);

$assert('1.3 Ambos DTOs declaran tipado estricto (declare(strict_types=1))',
    (bool) preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $itemSource)
    && (bool) preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $threadSource)
);

$itemLoaded = class_exists(IncidentCommentItemDto::class);
$threadLoaded = class_exists(IncidentCommentThreadDto::class);
$assert('1.4 Ambas clases son autocargables', $itemLoaded && $threadLoaded);

if (!$itemLoaded || !$threadLoaded) {
    echo "\n[ERROR CRITICO] Clases de los DTOs no autocargables. Abortando pruebas.\n";
    exit(1);
}

$itemReflection = new ReflectionClass(IncidentCommentItemDto::class);
$threadReflection = new ReflectionClass(IncidentCommentThreadDto::class);

$assert('1.5 Ambas clases son final (no admiten herencia que rompa el contrato)', $itemReflection->isFinal() && $threadReflection->isFinal());
$assert('1.6 Ambas clases son readonly (inmutables de raíz, PHP 8.2+)', $itemReflection->isReadOnly() && $threadReflection->isReadOnly());
$assert('1.7 Ambas implementan JsonSerializable (serialización JSON nativa)',
    $itemReflection->implementsInterface(JsonSerializable::class) && $threadReflection->implementsInterface(JsonSerializable::class)
);
$assert('1.8 Ambas exponen toArray() como método público',
    $itemReflection->hasMethod('toArray') && $itemReflection->getMethod('toArray')->isPublic()
    && $threadReflection->hasMethod('toArray') && $threadReflection->getMethod('toArray')->isPublic()
);

// Tipado estricto propiedad a propiedad del ítem (contrato T-COM-01).
$expectedItemPropertyTypes = [
    'id' => 'int',
    'authorType' => 'string',
    'authorName' => 'string',
    'commentText' => 'string',
    'photoUrl' => '?string',
    'createdAt' => '?string',
    'isOwnMessage' => 'bool',
    'isInternal' => '?bool',
];
$nextIndex = 9;
foreach ($expectedItemPropertyTypes as $propertyName => $expectedType) {
    $isReadonlyTyped = false;
    if ($itemReflection->hasProperty($propertyName)) {
        $property = $itemReflection->getProperty($propertyName);
        $isReadonlyTyped = $property->isReadOnly() && (string) $property->getType() === $expectedType;
    }
    $assert(
        sprintf('1.%d La propiedad %s del ítem es readonly y de tipo %s', $nextIndex, $propertyName, $expectedType),
        $isReadonlyTyped,
        "La propiedad {$propertyName} no existe o no es readonly de tipo {$expectedType}"
    );
    $nextIndex++;
}

$threadPropertyTypesOk = true;
foreach (['incident', 'pagination', 'comments'] as $threadProperty) {
    if (!$threadReflection->hasProperty($threadProperty)) {
        $threadPropertyTypesOk = false;
        break;
    }
    $property = $threadReflection->getProperty($threadProperty);
    $threadPropertyTypesOk = $threadPropertyTypesOk && $property->isReadOnly() && (string) $property->getType() === 'array';
}
$assert('1.17 Las propiedades incident/pagination/comments del hilo son readonly y de tipo array', $threadPropertyTypesOk);

// =====================================================================
// GRUPO 2: Serialización del ítem para Técnicos y Coordinadores (candado)
// =====================================================================
echo "\n--- Grupo 2: Ítem interno expone el candado is_internal (RF-02.3, RF-02.4) ---\n";

$internalItem = new IncidentCommentItemDto(
    id: 35,
    authorType: 'TECHNICIAN',
    authorName: 'Carlos Pérez (Técnico)',
    commentText: 'Llegando al edificio. En 10 minutos accedo a conserjería para recoger la llave.',
    photoUrl: '/uploads/evidence_f8a92b.jpg',
    createdAt: '2026-10-06 11:30:12',
    isOwnMessage: true,
    isInternal: true
);

$expectedInternalKeys = ['id', 'author_type', 'author_name', 'comment_text', 'photo_url', 'created_at', 'is_own_message', 'is_internal'];
$internalPayload = $internalItem->toArray();

$assert('2.1 toArray() devuelve exactamente las 8 claves contractuales en orden (plan.md §2.1)',
    array_keys($internalPayload) === $expectedInternalKeys,
    'Claves obtenidas: ' . implode(', ', array_keys($internalPayload))
);
$assert('2.2 El candado is_internal=true viaja en el payload para notas de taller (RF-02.4)',
    ($internalPayload['is_internal'] ?? null) === true
);

$publicInternalItem = new IncidentCommentItemDto(
    id: 48,
    authorType: 'TECHNICIAN',
    authorName: 'Carlos Pérez (Técnico)',
    commentText: 'Llegando al edificio. Accedo por conserjería en 10 minutos.',
    photoUrl: null,
    createdAt: '2026-10-06 11:30:12',
    isOwnMessage: true,
    isInternal: false
);
$assert('2.3 is_internal=false también viaja de forma explícita (sin omitirse en perfiles internos)',
    ($publicInternalItem->toArray()['is_internal'] ?? null) === false
);

$assert('2.4 jsonSerialize() coincide exactamente con toArray()',
    $internalItem->jsonSerialize() === $internalPayload
);

$expectedInternalJson = '{"id":35,"author_type":"TECHNICIAN","author_name":"Carlos Pérez (Técnico)","comment_text":"Llegando al edificio. En 10 minutos accedo a conserjería para recoger la llave.","photo_url":"/uploads/evidence_f8a92b.jpg","created_at":"2026-10-06 11:30:12","is_own_message":true,"is_internal":true}';
$internalJson = json_encode($internalItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert('2.5 json_encode produce el JSON canónico exacto del contrato con el candado al final',
    $internalJson === $expectedInternalJson,
    'JSON obtenido: ' . (string) $internalJson
);

// =====================================================================
// GRUPO 3: Segregación absoluta del ítem para la Sede (RNF-01 / Art. V.4)
// =====================================================================
echo "\n--- Grupo 3: Ítem de Sede omite por completo is_internal (RNF-01) ---\n";

$siteItem = new IncidentCommentItemDto(
    id: 12,
    authorType: 'REPORTER',
    authorName: 'Conserjería Principal (Juan Gómez)',
    commentText: 'La máquina está en la 3ª planta junto a los ascensores B.',
    photoUrl: null,
    createdAt: '2026-10-06 10:15:30',
    isOwnMessage: true,
    isInternal: null
);

$expectedSiteKeys = ['id', 'author_type', 'author_name', 'comment_text', 'photo_url', 'created_at', 'is_own_message'];
$sitePayload = $siteItem->toArray();

$assert('3.1 toArray() devuelve exactamente las 7 claves públicas en orden (sin campo de confidencialidad)',
    array_keys($sitePayload) === $expectedSiteKeys,
    'Claves obtenidas: ' . implode(', ', array_keys($sitePayload))
);
$assert('3.2 La clave is_internal no existe en el array serializado (ni siquiera como null)',
    !array_key_exists('is_internal', $sitePayload)
);

$siteJson = json_encode($siteItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert('3.3 El JSON del perfil de Sede no contiene el metadato is_internal en ninguna forma (RNF-01)',
    $siteJson !== false && !str_contains($siteJson, 'is_internal'),
    'JSON obtenido: ' . (string) $siteJson
);

$expectedSiteJson = '{"id":12,"author_type":"REPORTER","author_name":"Conserjería Principal (Juan Gómez)","comment_text":"La máquina está en la 3ª planta junto a los ascensores B.","photo_url":null,"created_at":"2026-10-06 10:15:30","is_own_message":true}';
$assert('3.4 El JSON del ítem de Sede coincide exactamente con el contrato del plan.md §2.1.A',
    $siteJson === $expectedSiteJson,
    'JSON obtenido: ' . (string) $siteJson
);

$assert('3.5 siteExcludedKeys() declara is_internal como clave excluida del perfil de Sede',
    IncidentCommentItemDto::siteExcludedKeys() === ['is_internal']
);

// =====================================================================
// GRUPO 4: Serialización del hilo consolidado (contrato plan.md §2.1)
// =====================================================================
echo "\n--- Grupo 4: Hilo consolidado con cabecera, cursor y mensajes segregados ---\n";

$incidentHeader = [
    'id' => 142,
    'ticket_code' => 'TICK-2026-00142',
    'machine_code' => 'VEN-BCN-001',
    'machine_model' => 'CoffeMax Pro 3000',
    'location_name' => 'Hospital del Mar',
    'status' => 'IN_PROGRESS',
    'status_label' => 'En Reparación',
    'is_sealed' => false,
];
$paginationMeta = [
    'total_comments' => 3,
    'loaded_count' => 2,
    'has_more_before' => true,
    'oldest_id' => 12,
    'latest_id' => 48,
];

$siteThread = new IncidentCommentThreadDto(
    incident: $incidentHeader,
    pagination: $paginationMeta,
    comments: [
        $siteItem,
        new IncidentCommentItemDto(
            id: 48,
            authorType: 'TECHNICIAN',
            authorName: 'Servicio Técnico Oficial (Operador #55)',
            commentText: 'Llegando al edificio. Accedo por conserjería en 10 minutos.',
            photoUrl: null,
            createdAt: '2026-10-06 11:30:12',
            isOwnMessage: false,
            isInternal: null
        ),
    ]
);

$threadPayload = $siteThread->toArray();

$assert('4.1 toArray() devuelve exactamente los bloques incident, pagination y comments en orden',
    array_keys($threadPayload) === ['incident', 'pagination', 'comments'],
    'Claves obtenidas: ' . implode(', ', array_keys($threadPayload))
);
$assert('4.2 La cabecera conserva las 8 claves contractuales del expediente (RF-01.4)',
    array_keys($threadPayload['incident']) === ['id', 'ticket_code', 'machine_code', 'machine_model', 'location_name', 'status', 'status_label', 'is_sealed']
        && $threadPayload['incident'] === $incidentHeader
);
$assert('4.3 La paginación conserva las 5 claves contractuales del cursor (RF-01.2, RF-01.3)',
    array_keys($threadPayload['pagination']) === ['total_comments', 'loaded_count', 'has_more_before', 'oldest_id', 'latest_id']
        && $threadPayload['pagination'] === $paginationMeta
);
$assert('4.4 Cada mensaje del hilo delega en la serialización segregada de IncidentCommentItemDto',
    count($threadPayload['comments']) === 2
    && array_keys($threadPayload['comments'][0]) === $expectedSiteKeys
    && array_keys($threadPayload['comments'][1]) === $expectedSiteKeys
);

$siteThreadJson = json_encode($siteThread, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert('4.5 El JSON completo del hilo de Sede no contiene is_internal en ningún mensaje (cero fugas, Art. V.4)',
    $siteThreadJson !== false && !str_contains($siteThreadJson, 'is_internal'),
    'Apareció el metadato de confidencialidad en el payload de Sede'
);
$assert('4.6 El JSON del hilo de Sede muestra el técnico enmascarado y jamás su identidad nominal',
    $siteThreadJson !== false
    && str_contains($siteThreadJson, 'Servicio Técnico Oficial (Operador #55)')
    && !str_contains($siteThreadJson, 'Carlos Pérez')
);

$techThread = new IncidentCommentThreadDto(
    incident: $incidentHeader,
    pagination: ['total_comments' => 5, 'loaded_count' => 2, 'has_more_before' => false, 'oldest_id' => 12, 'latest_id' => 48],
    comments: [$internalItem, $publicInternalItem]
);
$techThreadJson = json_encode($techThread, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert('4.7 El JSON del hilo de Técnico/Coordinador expone el candado is_internal en cada mensaje (RF-02.4)',
    $techThreadJson !== false && substr_count($techThreadJson, '"is_internal"') === 2
);

// Ciclo completo encode/decode: el transporte JSON no degrada ningún valor.
$decodedSiteThread = $siteThreadJson === false ? null : json_decode($siteThreadJson, true);
$assert('4.8 El payload del hilo es serializable a JSON y su forma canónica re-codifica idéntica',
    $siteThreadJson !== false && is_array($decodedSiteThread) && json_encode($decodedSiteThread, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $siteThreadJson
);
$assert('4.9 El JSON decodificado conserva todos los valores del payload',
    is_array($decodedSiteThread) && $decodedSiteThread == $threadPayload,
    'Algún valor no sobrevivió al ciclo encode/decode'
);

// Caso límite 1 de la spec: expediente sin mensajes todavía.
$emptyThread = new IncidentCommentThreadDto(
    incident: $incidentHeader,
    pagination: ['total_comments' => 0, 'loaded_count' => 0, 'has_more_before' => false, 'oldest_id' => null, 'latest_id' => null],
    comments: []
);
$emptyThreadPayload = $emptyThread->toArray();
$assert('4.10 El hilo vacío serializa sin romper la estructura (estado vacío del modal)',
    array_keys($emptyThreadPayload) === ['incident', 'pagination', 'comments']
    && $emptyThreadPayload['comments'] === []
    && $emptyThreadPayload['pagination']['loaded_count'] === 0
    && $emptyThreadPayload['pagination']['oldest_id'] === null
    && $emptyThreadPayload['pagination']['latest_id'] === null
);

// =====================================================================
// GRUPO 5: Inmutabilidad efectiva (Art. III)
// =====================================================================
echo "\n--- Grupo 5: Inmutabilidad efectiva de ambos DTOs ---\n";

$assert('5.1 toArray() es determinista en el ítem (dos llamadas devuelven lo mismo)', $siteItem->toArray() === $siteItem->toArray());
$assert('5.2 toArray() es determinista en el hilo (dos llamadas devuelven lo mismo)', $siteThread->toArray() === $siteThread->toArray());

$itemMutationBlocked = false;
try {
    $siteItem->authorName = 'Nombre Manipulado';
} catch (\Error) {
    $itemMutationBlocked = true;
}
$assert('5.3 Escribir en una propiedad del ítem lanza Error (proyección no manipulable)', $itemMutationBlocked);

$threadMutationBlocked = false;
try {
    $siteThread->comments = [];
} catch (\Error) {
    $threadMutationBlocked = true;
}
$assert('5.4 Escribir en una propiedad del hilo lanza Error (proyección no manipulable)', $threadMutationBlocked);

$tamperedHeader = $incidentHeader;
$tamperResistantThread = new IncidentCommentThreadDto(
    incident: $tamperedHeader,
    pagination: $paginationMeta,
    comments: [$siteItem]
);
// Mutación del array de origen DESPUÉS de construir: el DTO debe permanecer intacto.
$tamperedHeader['status'] = 'CLOSED';
$tamperedHeader['is_sealed'] = true;
$assert('5.5 Mutar el array de origen tras construir no altera el DTO (copia defensiva por valor)',
    $tamperResistantThread->toArray()['incident']['status'] === 'IN_PROGRESS'
        && $tamperResistantThread->toArray()['incident']['is_sealed'] === false
);

// ---------------------------------------------------------------------
// RESUMEN DE EJECUCIÓN
// ---------------------------------------------------------------------
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-COM-04 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
