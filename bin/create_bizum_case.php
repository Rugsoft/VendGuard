<?php

declare(strict_types=1);

/**
 * VendGuard - Script CLI para crear un caso demo de reintegro por Bizum.
 *
 * Crea una incidencia y su correspondiente solicitud de reintegro por Bizum
 * por un importe de 18.50 € (> 10.00 €), lo cual:
 * 1. Exige obligatoriamente inspección técnica.
 * 2. Si se inspecciona con recuperación o dictamen, escala automáticamente
 *    a REQUIRES_COORDINATOR_APPROVAL (doble visto bueno de Coordinación).
 * 3. Permite al Coordinador aprobar la cuantía y liquidarla mediante Bizum
 *    introduciendo el identificador de transacción bancaria.
 *
 * Uso:
 *   php bin/create_bizum_case.php
 *   php bin/create_bizum_case.php --inspected=1   (simula también el dictamen del técnico para dejarlo en REQUIRES_COORDINATOR_APPROVAL)
 */

require_once __DIR__ . '/../tests/bootstrap.php';
require_once __DIR__ . '/../src/Infrastructure/Database/ConnectionFactory.php';

use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Application\DTO\TechnicianRefundInspectionDTO;
use VendGuard\Application\Service\IbanValidationService;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Application\Service\TechnicianRefundService;
use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\TicketCode;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;
use VendGuard\Infrastructure\Repository\PdoUnclaimedCashFindingRepository;

echo "======================================================================\n";
echo " VendGuard: Generando Caso Demo de Reintegro por Bizum (HU-05)\n";
echo "======================================================================\n\n";

try {
    $pdo = ConnectionFactory::getConnection();

    $machineRepo = new PdoMachineRepository($pdo);
    $locationRepo = new PdoLocationRepository($pdo);
    $incidentRepo = new PdoIncidentRepository($pdo);
    $refundRepo = new PdoRefundRequestRepository($pdo);
    $ibanValidator = new IbanValidationService();
    $refundService = new RefundManagementService($refundRepo, $ibanValidator);

    // 1. Obtener la sede semilla y una máquina suya SIN incidencia activa.
    //
    // Antes esto usaba `findActiveByLocationId(1)` / `(2)` con identificadores
    // fijos y, si todas las máquinas tenían aviso, un `UPDATE ... WHERE
    // machine_id = :mid` que cerraba a ciegas los tickets de esa máquina. Eso
    // dejaba el escenario a merced de qué fila fuese la 1 y podía alterar
    // averías ajenas. Ahora se ancla a la sede semilla y se aborta si no hay una
    // máquina libre, en lugar de tocar datos existentes.
    $location = $locationRepo->findBySiteCode('SEDE-BCN-01');
    if ($location === null) {
        throw new RuntimeException("No se encontró la sede semilla SEDE-BCN-01.");
    }

    $allMachines = $machineRepo->findActiveByLocationId((int)$location->getId());

    $machine = null;
    foreach ($allMachines as $candidate) {
        $activeInc = $incidentRepo->findActiveByMachineId((int)$candidate->getId());
        if ($activeInc === null) {
            $machine = $candidate;
            break;
        }
    }

    if ($machine === null) {
        throw new RuntimeException(
            'Todas las máquinas de ' . $location->getName()
            . ' tienen una avería activa. Cierre o atienda una avería antes de generar este caso demo.'
        );
    }

    // 2. Crear una nueva incidencia
    $ticketCode = TicketCode::generate()->value();
    $claimedAmount = 18.50; // > 10.00 € para disparar doble visto bueno
    $bizumPhone = '654987321';
    $claimantName = 'Sofía Valenzuela';

    $incident = new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: $machine->getId(),
        locationId: $location->getId(),
        category: IncidentCategory::PAYMENT_SYSTEM,
        description: 'Lector de billetes tragó billete de 20€ para compra de café y solo devolvió 1.50€.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::REGISTERED,
        assignedTechnicianId: null,
        reporterName: $claimantName,
        reporterPhone: $bizumPhone,
        retainedMoneyAmount: $claimedAmount,
        photoPath: null
    );

    $createdIncident = $incidentRepo->create($incident, null, 'Aviso de prueba Bizum generado por CLI');
    echo "[OK] Incidencia creada con éxito:\n";
    echo "     - Ticket Code: {$createdIncident->getTicketCode()}\n";
    echo "     - Máquina: {$machine->getCode()} ({$machine->getModel()})\n";
    echo "     - Sede: {$location->getName()}\n\n";

    // 3. Abrir el expediente formal de reintegro por Bizum
    $case = $refundService->createCase(new CreateRefundRequestDTO(
        incidentId: (int)$createdIncident->getId(),
        machineId: (int)$machine->getId(),
        locationId: (int)$location->getId(),
        claimantName: $claimantName,
        claimantContact: $bizumPhone,
        claimedAmount: $claimedAmount,
        compensationMethod: CompensationMethod::BIZUM,
        productAttempted: 'Café Espresso Doble',
        bizumPhone: $bizumPhone,
        iban: null
    ));

    echo "[OK] Expediente de reintegro por Bizum abierto:\n";
    echo "     - Expediente ID: #{$case->getId()}\n";
    echo "     - Reclamante: {$case->getClaimantName()}\n";
    echo "     - Vía de compensación: BIZUM (Móvil: {$bizumPhone})\n";
    echo "     - Importe reclamado: " . number_format($case->getClaimedAmount(), 2) . " €\n";
    echo "     - Estado inicial: {$case->getStatus()->value}\n";
    echo "     - Token de seguimiento: {$case->getTrackingToken()}\n\n";

    // 4. Si se solicita simular la inspección del técnico, avanzar a REQUIRES_COORDINATOR_APPROVAL
    $options = getopt('', ['inspected::']);
    $shouldInspect = isset($options['inspected']) || in_array('--inspected', $argv, true) || in_array('-i', $argv, true);

    if ($shouldInspect) {
        $findingRepo = new PdoUnclaimedCashFindingRepository($pdo);
        $techRefundService = new TechnicianRefundService(
            $refundRepo,
            $findingRepo,
            $refundService,
            null,
            $locationRepo
        );

        $dto = new TechnicianRefundInspectionDTO(
            finding: TechnicianFinding::FOUND_PHYSICAL,
            recoveredAmount: 18.50,
            cashCustodyAction: CashCustodyAction::HELD_FOR_CENTRAL, // Como es digital, va a caja central
            receptionistName: '',
            justification: ''
        );

        $techRefundService->inspectBalance(
            (int)$createdIncident->getId(),
            $dto,
            (int)$machine->getId(),
            ['id' => 2, 'role' => 'TECHNICIAN', 'name' => 'Jordi Técnico']
        );

        $updatedCase = $refundRepo->findById((int)$case->getId());
        echo "[OK] Inspección técnica aplicada con éxito:\n";
        echo "     - Dictamen: FOUND_PHYSICAL (18.50 € recuperados)\n";
        echo "     - Custodia: HELD_FOR_CENTRAL\n";
        echo "     - Nuevo estado: {$updatedCase->getStatus()->value}\n";
        echo "     - Requiere aprobación de Coordinación: " . ($updatedCase->requiresSpecialSupervision() ? 'SÍ (>10€)' : 'NO') . "\n\n";
    }

    echo "======================================================================\n";
    echo " ¡Caso listo! Puedes verlo ahora en la pestaña 'Reintegros' del Coordinador.\n";
    if ($shouldInspect) {
        echo " Pasos para probar en la interfaz:\n";
        echo " 1. En la fila de '{$claimantName}' verás el botón azul 'Firmar visto bueno'.\n";
        echo " 2. Pulsa en él, valida los 18.50 € y escribe una justificación.\n";
        echo " 3. Una vez aprobado, aparecerá el botón 'Liquidar Bizum'.\n";
        echo " 4. Introduce la referencia bancaria (ej: BZM-2026-998811) para completar el pago.\n";
    } else {
        echo " Nota: El expediente está en 'Pendiente de inspección técnica'.\n";
        echo " Puedes simular la inspección técnica ejecutando:\n";
        echo "   php bin/create_bizum_case.php --inspected=1\n";
    }
    echo "======================================================================\n";

} catch (Throwable $e) {
    echo "\n[ERROR] " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
