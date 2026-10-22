<?php

declare(strict_types=1);

/**
 * ServiceSheetPrivacyTest (T-SIGN-05)
 *
 * Certifica RD-04 / S10 sobre la carga útil serializada del albarán de servicio
 * (Módulo 19): el papel que se imprime no transporta costes, notas internas, SLA,
 * saldos ni identificadores internos distintos del código de ticket.
 *
 * El escenario es deliberadamente adverso: la avería tiene una pieza con coste
 * congelado y una nota interna escrita por el técnico, y la hoja se firma después.
 * La comprobación se hace sobre el `json_encode()` completo del modelo de lectura —
 * documento, hoja e historial—, no sobre campos sueltos, de modo que una consulta
 * futura que añada una columna sensible rompe esta suite.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentServiceSheet;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentServiceSheetRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

echo "======================================================================\n";
echo " VendGuard: Integración - ServiceSheetPrivacyTest (T-SIGN-05)\n";
echo "======================================================================\n\n";

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$incidentRepo = new PdoIncidentRepository($pdo);
$sheetRepo    = new PdoIncidentServiceSheetRepository($pdo);
$userRepo     = new PdoUserRepository($pdo);

$assertions = 0;
$failures   = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }

    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

$technician = $userRepo->findById((int)$pdo->query(
    "SELECT id FROM users WHERE role = 'TECHNICIAN' AND deleted_at IS NULL ORDER BY id LIMIT 1"
)->fetchColumn());

$coordinator = $userRepo->findByEmail('coordinacion@vendguard.internal');

if ($technician === null || $coordinator === null) {
    echo "ERROR FATAL: usuarios semilla no disponibles.\n";
    exit(1);
}

$technicianId  = (int)$technician->getId();
$coordinatorId = (int)$coordinator->getId();

$machine = $pdo->query(
    "SELECT m.id AS machine_id, m.location_id AS location_id
       FROM machines m
      WHERE m.is_active = TRUE
        AND NOT EXISTS (
            SELECT 1 FROM incidents i
             WHERE i.machine_id = m.id
               AND i.deleted_at IS NULL
               AND i.status NOT IN ('CLOSED', 'CANCELLED')
        )
      ORDER BY m.id
      LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

$sparePartId = (int)$pdo->query('SELECT id FROM spare_parts ORDER BY id LIMIT 1')->fetchColumn();

$assert(
    '0.1 Semilla disponible: máquina libre y pieza de catálogo',
    is_array($machine) && $sparePartId > 0,
    json_encode($machine) . ' pieza=' . $sparePartId
);

if (!is_array($machine) || $sparePartId === 0) {
    echo "ERROR FATAL: semilla insuficiente.\n";
    exit(1);
}

$incident = $incidentRepo->create(new Incident(
    id: null,
    ticketCode: 'TST-SIGN-PRIV-' . strtoupper(bin2hex(random_bytes(3))),
    machineId: (int)$machine['machine_id'],
    locationId: (int)$machine['location_id'],
    category: IncidentCategory::ELECTRICAL_OFF,
    description: 'Avería del banco de pruebas de privacidad del albarán.',
    urgency: UrgencyLevel::HIGH,
    status: IncidentStatus::REGISTERED,
    assignedTechnicianId: null,
    createdAt: null
));

$incidentId = (int)$incident->getId();
$incidentRepo->assign($incidentId, $technicianId, $coordinatorId);
$incidentRepo->startIntervention($incidentId, $technicianId);
$incidentRepo->resolve(
    $incidentId,
    $technicianId,
    'Bobina del relé térmico quemada por un pico de tensión en el cuadro.',
    'Sustituido el relé térmico y verificado el ciclo de frío estabilizado a 4 ºC.'
);

// Pieza con coste congelado y nota interna: ambos prohibidos en el papel (RD-04).
$pdo->prepare(
    'INSERT INTO incident_replaced_parts
        (intervention_type, incident_id, machine_id, location_id, technician_id,
         spare_part_id, is_out_of_catalog, quantity, unit_cost_snapshot, old_part_destination, notes)
     VALUES
        (\'INCIDENT\', :incident_id, :machine_id, :location_id, :technician_id,
         :spare_part_id, FALSE, 2, 12.34, \'TALLER\', \'NOTA-INTERNA-CONFIDENCIAL\')'
)->execute([
    ':incident_id'   => $incidentId,
    ':machine_id'    => (int)$machine['machine_id'],
    ':location_id'   => (int)$machine['location_id'],
    ':technician_id' => $technicianId,
    ':spare_part_id' => $sparePartId,
]);

$sheet = $sheetRepo->insert(new IncidentServiceSheet(
    id: null,
    incidentId: $incidentId,
    technicianId: $technicianId,
    signerName: 'María López Ferrer',
    signerDocument: '12345678Z',
    signaturePng: "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 152),
    absenceReason: null,
));

$read = $sheetRepo->findDocumentByIncidentId($incidentId);
$payload = json_encode($read, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

$assert('1.1 El modelo de lectura se compone', is_array($read) && ($read['sheet']['id'] ?? null) === $sheet->getId(), 'modelo vacío');

$parts = $read['document']['parts'] ?? [];
$assert('1.2 El documento incluye la pieza por nombre y cantidad', count($parts) === 1 && ($parts[0]['quantity'] ?? null) === 2 && ($parts[0]['name'] ?? '') !== '');
$assert('1.3 Cada pieza declara solo nombre y cantidad', array_keys($parts[0] ?? []) === ['name', 'quantity'], json_encode(array_keys($parts[0] ?? [])));

$forbiddenKeys = ['unit_cost', 'unit_cost_snapshot', 'total_cost', 'total_cost_snapshot', 'reference_cost', 'notes', 'sla', 'phone', 'contact_phone'];
$foundKeys = array_values(array_filter($forbiddenKeys, static fn (string $key): bool => str_contains($payload, $key)));
$assert('1.4 La carga útil no nombra ninguna clave sensible', $foundKeys === [], implode(', ', $foundKeys));

$forbiddenValues = ['12.34', 'NOTA-INTERNA-CONFIDENCIAL'];
$foundValues = array_values(array_filter($forbiddenValues, static fn (string $value): bool => str_contains($payload, $value)));
$assert('1.5 La carga útil no filtra el coste ni la nota interna', $foundValues === [], implode(', ', $foundValues));

$sheetKeys = array_keys($read['sheet'] ?? []);
$assert(
    '1.6 La hoja publica exactamente las claves del contrato §4.4',
    $sheetKeys === ['id', 'recorded_at', 'resolved_at', 'current', 'signed', 'signer_name', 'signer_document', 'png_base64', 'absence_reason'],
    json_encode($sheetKeys)
);

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. RD-04 CERTIFICADO.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);
