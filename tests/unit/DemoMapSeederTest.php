<?php

declare(strict_types=1);

/**
 * DemoMapSeederTest — certificación del juego de datos del Mapa Territorial (RF-MAP-09).
 *
 * QUÉ CERTIFICA Y POR QUÉ ASÍ
 *
 * El sembrador escribe directamente contra el motor, así que sus fixtures tienen que cumplir
 * por sí mismas las reglas que el motor impone y, sobre todo, tienen que servir para lo que
 * existen: comprobar el filtro por técnico del mapa. Esta suite no toca la base —eso lo hace
 * el propio script al ejecutarse— y certifica los invariantes del juego de datos:
 *
 *   1. **Idempotencia por clave natural:** códigos de ticket únicos y de la familia
 *      `INC-MAPA-*`, que es lo que permite volver a ejecutar el sembrador sin duplicar.
 *
 *   2. **Una máquina por avería:** el índice parcial `uq_machine_active_ticket` solo admite un
 *      ticket vivo por máquina; dos fixtures sobre la misma máquina harían fallar la siembra.
 *
 *   3. **Cobertura de los filtros del mapa:** cuatro técnicos con trabajo, una avería sin
 *      asignar, una crítica de cadena de frío y una sede multi-técnico. Marc se queda sin
 *      trabajo a propósito: es el técnico con el que se comprueba el estado vacío.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: identificadores en inglés, documentación y mensajes en castellano.
 */

require_once dirname(__DIR__, 2) . '/database/DemoMapSeeder.php';

use VendGuard\Database\DemoMapSeeder;

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - DemoMapSeederTest\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    $failures++;
    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
};

$fixtures = DemoMapSeeder::fixtures();
$ticketCodes = array_column($fixtures, 'ticket_code');
$machineCodes = array_column($fixtures, 'machine_code');
$technicianEmails = array_values(array_unique(array_filter(
    array_column($fixtures, 'technician_email'),
    static fn ($email): bool => $email !== null
)));

echo "--- Grupo 1: Integridad del fixture ---\n";

$assert(
    '1.1 El juego de datos declara ocho averías activas',
    count($fixtures) === 8,
    'Declaradas: ' . count($fixtures)
);

$assert(
    '1.2 Los códigos de ticket son únicos (clave natural de la siembra)',
    count($ticketCodes) === count(array_unique($ticketCodes))
);

$assert(
    '1.3 Todos los códigos pertenecen a la familia INC-MAPA-',
    array_filter($ticketCodes, static fn (string $code): bool => !str_starts_with($code, 'INC-MAPA-')) === []
);

$assert(
    '1.4 Cada avería ocupa una máquina distinta (uq_machine_active_ticket)',
    count($machineCodes) === count(array_unique($machineCodes)),
    'Máquinas repetidas: ' . implode(', ', array_diff_assoc($machineCodes, array_unique($machineCodes)))
);

$validCategories = ['TEMPERATURE_COLD', 'PAYMENT_SYSTEM', 'PRODUCT_JAM', 'ELECTRICAL_OFF', 'OTHER'];
$validUrgencies = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
$validStatuses = ['REGISTERED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'PENDING_INFO', 'RESOLVED', 'REOPENED'];

$assert(
    '1.5 Categorías, urgencias y estados pertenecen a los enums del motor',
    array_filter($fixtures, static fn (array $fixture): bool => !in_array($fixture['category'], $validCategories, true)
        || !in_array($fixture['urgency'], $validUrgencies, true)
        || !in_array($fixture['status'], $validStatuses, true)) === []
);

$assert(
    '1.6 Toda avería describe el aviso y trae persona de contacto',
    array_filter($fixtures, static fn (array $fixture): bool => mb_strlen((string)$fixture['description']) < 20
        || trim((string)$fixture['reporter_name']) === ''
        || trim((string)$fixture['reporter_phone']) === '') === []
);

echo "\n--- Grupo 2: Cobertura de los filtros del Mapa Territorial ---\n";

$assert(
    '2.1 Cuatro técnicos distintos tienen trabajo asignado',
    count($technicianEmails) === 4 && $technicianEmails === [
        'jordi.ruta@vendguard.internal',
        'marta.ruta@vendguard.internal',
        'carlos.ruta@vendguard.internal',
        'elena.ruta@vendguard.internal',
    ],
    'Técnicos: ' . implode(', ', $technicianEmails)
);

$assert(
    '2.2 Marc se queda sin trabajo para poder comprobar el vacío del filtro',
    !in_array('marc.ruta@vendguard.internal', $technicianEmails, true)
);

$unassigned = array_values(array_filter($fixtures, static fn (array $fixture): bool => $fixture['technician_email'] === null));
$assert(
    '2.3 Una avería sin asignar sostiene el filtro «Solo sin asignar»',
    count($unassigned) === 1
        && $unassigned[0]['status'] === 'REGISTERED'
        && $unassigned[0]['assign_delay_minutes'] === null
);

$criticalFixtures = array_values(array_filter(
    $fixtures,
    static fn (array $fixture): bool => $fixture['category'] === 'TEMPERATURE_COLD' && $fixture['urgency'] === 'CRITICAL'
));
$assert(
    '2.4 Hay averías críticas de cadena de frío para el filtro de criticidad',
    count($criticalFixtures) >= 2,
    'Críticas declaradas: ' . count($criticalFixtures)
);

$siteGroups = [];
foreach ($fixtures as $fixture) {
    $sitePrefix = substr((string)$fixture['machine_code'], 0, 7);
    $siteGroups[$sitePrefix][] = $fixture['technician_email'];
}

$multiTechnicianSites = array_filter(
    $siteGroups,
    static fn (array $emails): bool => count(array_unique(array_filter($emails, static fn ($email): bool => $email !== null))) > 1
);
$assert(
    '2.5 Alguna sede queda multi-técnico para el distintivo de consolidación',
    $multiTechnicianSites !== [],
    'Sedes multi-técnico: ' . implode(', ', array_keys($multiTechnicianSites))
);

$assignedWithoutTimestamp = array_filter(
    $fixtures,
    static fn (array $fixture): bool => $fixture['technician_email'] !== null && $fixture['assign_delay_minutes'] === null
);
$assert(
    '2.6 Toda avería asignada declara su demora de asignación',
    $assignedWithoutTimestamp === []
);

$inProgress = array_values(array_filter($fixtures, static fn (array $fixture): bool => $fixture['status'] === 'IN_PROGRESS'));
$assert(
    '2.7 La avería en curso declara también su inicio de intervención',
    count($inProgress) === 1 && isset($inProgress[0]['start_delay_minutes'])
);

echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE ({$assertions} aserciones)!\n";
    echo " CONDICIÓN DEMO-MAP CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: {$failures} FALLO(S) DE {$assertions} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
