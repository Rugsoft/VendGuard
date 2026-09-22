<?php

declare(strict_types=1);

/**
 * DomainEnumsTest
 * 
 * Verificación unitaria para Enums y Value Objects de Dominio (Tarea T-05).
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\Model\MachineType as ModelMachineType;
use VendGuard\Core\Domain\ValueObject\MachineType as VOMachineType;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\ValueObject\TicketCode;

echo "========================================================\n";
echo " VendGuard: Verificación de Enums y Value Objects (T-05)\n";
echo "========================================================\n\n";

$failures = 0;

// -------------------------------------------------------------
// 1. Verificación de UrgencyLevel
// -------------------------------------------------------------
echo "[1/4] Verificando UrgencyLevel:\n";
$expectedUrgencies = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
$actualUrgencies = UrgencyLevel::values();

if ($actualUrgencies === $expectedUrgencies) {
    echo "      - Casos de severidad exactamente 4 (LOW, MEDIUM, HIGH, CRITICAL): [OK]\n";
} else {
    echo "      - Casos de severidad inesperados: [FALLO]\n";
    $failures++;
}

if (UrgencyLevel::CRITICAL->isCritical() && !UrgencyLevel::HIGH->isCritical()) {
    echo "      - isCritical() devuelve true únicamente en CRITICAL: [OK]\n";
} else {
    echo "      - isCritical() falló: [FALLO]\n";
    $failures++;
}

if (UrgencyLevel::CRITICAL->priorityRank() > UrgencyLevel::HIGH->priorityRank()
    && UrgencyLevel::HIGH->priorityRank() > UrgencyLevel::MEDIUM->priorityRank()
    && UrgencyLevel::MEDIUM->priorityRank() > UrgencyLevel::LOW->priorityRank()) {
    echo "      - Jerarquía de prioridad matemática consistente: [OK]\n";
} else {
    echo "      - Jerarquía de prioridad errónea: [FALLO]\n";
    $failures++;
}

if (UrgencyLevel::isValid('CRITICAL') && UrgencyLevel::isValid('low') && !UrgencyLevel::isValid('INVALID')) {
    echo "      - UrgencyLevel::isValid() valida correctamente: [OK]\n";
} else {
    echo "      - UrgencyLevel::isValid() falló: [FALLO]\n";
    $failures++;
}

// -------------------------------------------------------------
// 2. Verificación de IncidentStatus (8 estados)
// -------------------------------------------------------------
echo "[2/4] Verificando IncidentStatus:\n";
$expectedStatuses = [
    'REGISTERED',
    'ASSIGNED',
    'IN_PROGRESS',
    'PENDING_PARTS',
    'RESOLVED',
    'REOPENED',
    'CLOSED',
    'CANCELLED',
];
$actualStatuses = IncidentStatus::values();

if ($actualStatuses === $expectedStatuses) {
    echo "      - Exactamente 8 estados del ciclo de vida presentes: [OK]\n";
} else {
    echo "      - Estados distintos a los 8 esperados: [FALLO]\n";
    $failures++;
}

// Comprobar isActive() (todos activos salvo CLOSED y CANCELLED)
$activeCheckOk = true;
foreach (IncidentStatus::cases() as $st) {
    if ($st === IncidentStatus::CLOSED || $st === IncidentStatus::CANCELLED) {
        if ($st->isActive()) {
            $activeCheckOk = false;
        }
    } else {
        if (!$st->isActive()) {
            $activeCheckOk = false;
        }
    }
}

if ($activeCheckOk) {
    echo "      - isActive() inactiva solo CLOSED y CANCELLED (paridad con MariaDB): [OK]\n";
} else {
    echo "      - isActive() falló en comprobar candado activo: [FALLO]\n";
    $failures++;
}

// Comprobar canTransitionTo()
$validRegisteredToAssigned = IncidentStatus::REGISTERED->canTransitionTo(IncidentStatus::ASSIGNED);
$invalidRegisteredToResolved = IncidentStatus::REGISTERED->canTransitionTo(IncidentStatus::RESOLVED);
$validResolvedToReopened = IncidentStatus::RESOLVED->canTransitionTo(IncidentStatus::REOPENED);
$invalidClosedToAny = IncidentStatus::CLOSED->canTransitionTo(IncidentStatus::IN_PROGRESS);

if ($validRegisteredToAssigned && !$invalidRegisteredToResolved && $validResolvedToReopened && !$invalidClosedToAny) {
    echo "      - canTransitionTo() valida transiciones legales e ilegales: [OK]\n";
} else {
    echo "      - canTransitionTo() lógica incorrecta: [FALLO]\n";
    $failures++;
}

// -------------------------------------------------------------
// 3. Verificación de MachineType (Model y ValueObject alias)
// -------------------------------------------------------------
echo "[3/4] Verificando MachineType:\n";
$expectedMachineTypes = ['HOT_DRINKS', 'COLD_DRINKS', 'SNACKS', 'PERISHABLE_FOOD', 'COMBO'];

if (ModelMachineType::values() === $expectedMachineTypes && VOMachineType::values() === $expectedMachineTypes) {
    echo "      - Los 5 tipos de máquina disponibles en ambos namespaces: [OK]\n";
} else {
    echo "      - Tipos de máquina incorrectos: [FALLO]\n";
    $failures++;
}

$perishableSafetyOk = ModelMachineType::PERISHABLE_FOOD->isPerishable()
    && ModelMachineType::PERISHABLE_FOOD->hasColdChainRisk()
    && !ModelMachineType::SNACKS->isPerishable()
    && !ModelMachineType::HOT_DRINKS->hasColdChainRisk();

if ($perishableSafetyOk) {
    echo "      - Mandato Constitucional de seguridad alimentaria en PERISHABLE_FOOD: [OK]\n";
} else {
    echo "      - Verificación de seguridad alimentaria falló: [FALLO]\n";
    $failures++;
}

$refrigOk = ModelMachineType::PERISHABLE_FOOD->requiresRefrigeration()
    && ModelMachineType::COLD_DRINKS->requiresRefrigeration()
    && ModelMachineType::COMBO->requiresRefrigeration()
    && !ModelMachineType::HOT_DRINKS->requiresRefrigeration()
    && !ModelMachineType::SNACKS->requiresRefrigeration();

if ($refrigOk) {
    echo "      - requiresRefrigeration() discrimina correctamente unidades de frío: [OK]\n";
} else {
    echo "      - requiresRefrigeration() falló: [FALLO]\n";
    $failures++;
}

// -------------------------------------------------------------
// 4. Verificación de IncidentCategory, UserRole y TicketCode
// -------------------------------------------------------------
echo "[4/4] Verificando Componentes Adicionales (Category, Role, TicketCode):\n";

if (count(IncidentCategory::cases()) === 5 && count(UserRole::cases()) === 2) {
    echo "      - IncidentCategory (5 categorías) y UserRole (2 roles): [OK]\n";
} else {
    echo "      - Conteo de categorías o roles erróneo: [FALLO]\n";
    $failures++;
}

$ticket = TicketCode::generate(42, 2026);
$ticketOther = new TicketCode('INC-2026-0042');
$ticketDiff = new TicketCode('INC-2026-0043');

if ($ticket->value() === 'INC-2026-0042' && $ticket->equals($ticketOther) && !$ticket->equals($ticketDiff)) {
    echo "      - TicketCode validación inmutable y equivalencia estructural: [OK]\n";
} else {
    echo "      - TicketCode falló: [FALLO]\n";
    $failures++;
}

// Validar que un formato corrupto arroje excepción
$caughtBadTicket = false;
try {
    new TicketCode('TICKET-INVALIDO');
} catch (InvalidArgumentException $e) {
    $caughtBadTicket = true;
}

if ($caughtBadTicket) {
    echo "      - TicketCode rechaza formatos corruptos con InvalidArgumentException: [OK]\n";
} else {
    echo "      - TicketCode aceptó un formato corrupto sin excepción: [FALLO]\n";
    $failures++;
}

echo "\n========================================================\n";
if ($failures === 0) {
    echo " TODAS LAS CONDICIONES DE T-05 CUMPLIDAS CON ÉXITO.\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo " ERROR: Se detectaron {$failures} fallos en la verificación de T-05.\n";
    echo "========================================================\n";
    exit(1);
}
