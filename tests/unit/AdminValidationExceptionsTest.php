<?php

declare(strict_types=1);

/**
 * AdminValidationExceptionsTest
 * 
 * Suite de pruebas unitarias para las Excepciones de Dominio del Módulo 04 (T-ADM-03).
 * Valida los códigos HTTP (403, 409), identificadores de error normalizados,
 * banderas de reactivación y mensajes descriptivos en castellano para las reglas de bloqueo:
 * - Auto-desactivación de coordinador en sesión (Caso Límite 8).
 * - Guardia mínima operativa por rol (Caso Límite 9).
 * - Baja de técnico con incidencias asignadas pendientes (Decisión QA 2).
 * - Baja de sede con máquinas activas asociadas (RF-01, EARS 1.4).
 * - Traslado o baja de máquina con incidencias activas o en garantía de 48h (Decisión QA 1, Art. V.6).
 * - Modificación de tipología sanitaria con tickets activos o en garantía (Decisión QA 3, Art. II).
 * - Detección de colisión de unicidad con registros inactivos (Decisión QA 5).
 * 
 * Cumple con Dogma Vanilla (PHP 8.2+ sin dependencias externas) y Dualismo Lingüístico.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\CannotDeactivateSelfException;
use VendGuard\Core\Domain\Exception\MinimumActiveStaffException;
use VendGuard\Core\Domain\Exception\PendingIncidentsBlockedException;
use VendGuard\Core\Domain\Exception\ActiveMachinesBlockedException;
use VendGuard\Core\Domain\Exception\MachineTransferBlockedException;
use VendGuard\Core\Domain\Exception\MachineTypeChangeBlockedException;
use VendGuard\Core\Domain\Exception\InactiveRecordCollisionException;

echo "======================================================================\n";
echo " VendGuard: Verificación Unitaria de Excepciones de Dominio (T-ADM-03)\n";
echo "======================================================================\n\n";

$assertions = 0;

function assertCondition(bool $cond, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$cond) {
        echo "  [FALLO] {$message}\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

try {
    // -------------------------------------------------------------
    // 1. CannotDeactivateSelfException (Caso Límite 8, HTTP 403)
    // -------------------------------------------------------------
    echo "--- 1. CannotDeactivateSelfException (Auto-baja de Coordinador) ---\n";

    $e1 = new CannotDeactivateSelfException();
    assertCondition($e1 instanceof DomainException, "1.1 Hereda de DomainException");
    assertCondition($e1->getErrorCode() === 'CANNOT_DEACTIVATE_SELF', "1.2 Código de error CANNOT_DEACTIVATE_SELF");
    assertCondition($e1->getHttpStatusCode() === 403, "1.3 Código HTTP 403 Forbidden");
    assertCondition(str_contains($e1->getMessage(), 'no puede desactivar su propio usuario'), "1.4 Mensaje por defecto en castellano");

    $e1Custom = new CannotDeactivateSelfException('Mensaje personalizado', 'CUSTOM_CODE', 403);
    assertCondition($e1Custom->getMessage() === 'Mensaje personalizado', "1.5 Admite mensaje personalizado");
    assertCondition($e1Custom->getErrorCode() === 'CUSTOM_CODE', "1.6 Admite código de error personalizado");

    // -------------------------------------------------------------
    // 2. MinimumActiveStaffException (Caso Límite 9, HTTP 409)
    // -------------------------------------------------------------
    echo "\n--- 2. MinimumActiveStaffException (Guardia Mínima Operativa) ---\n";

    $e2Tech = new MinimumActiveStaffException('Guardia mínima de técnicos', 'TECHNICIAN');
    assertCondition($e2Tech instanceof DomainException, "2.1 Hereda de DomainException");
    assertCondition($e2Tech->getErrorCode() === 'MINIMUM_ACTIVE_STAFF_BREACH', "2.2 Código MINIMUM_ACTIVE_STAFF_BREACH");
    assertCondition($e2Tech->getHttpStatusCode() === 409, "2.3 Código HTTP 409 Conflict");
    assertCondition($e2Tech->getRole() === 'TECHNICIAN', "2.4 Captura rol de técnico");

    $e2Coord = new MinimumActiveStaffException('Guardia mínima de coordinadores', 'COORDINATOR');
    assertCondition($e2Coord->getRole() === 'COORDINATOR', "2.5 Captura rol de coordinador");
    assertCondition(str_contains($e2Coord->getMessage(), 'Guardia mínima'), "2.6 Mensaje descriptivo preservado");

    // -------------------------------------------------------------
    // 3. PendingIncidentsBlockedException (Decisión QA 2, HTTP 409)
    // -------------------------------------------------------------
    echo "\n--- 3. PendingIncidentsBlockedException (Técnico con Averías Activas) ---\n";

    $e3 = new PendingIncidentsBlockedException(3, 5);
    assertCondition($e3 instanceof DomainException, "3.1 Hereda de DomainException");
    assertCondition($e3->getErrorCode() === 'TECHNICIAN_HAS_PENDING_INCIDENTS', "3.2 Código TECHNICIAN_HAS_PENDING_INCIDENTS");
    assertCondition($e3->getHttpStatusCode() === 409, "3.3 Código HTTP 409 Conflict");
    assertCondition($e3->getPendingIncidentsCount() === 3, "3.4 Recuento de incidencias pendientes igual a 3");
    assertCondition($e3->getTechnicianId() === 5, "3.5 ID de técnico preservado (5)");
    assertCondition(str_contains($e3->getMessage(), '3 averías asignadas pendientes'), "3.6 Mensaje auto-generado interpola recuento");

    $e3Custom = new PendingIncidentsBlockedException(1, 2, 'Bloqueo urgente manual');
    assertCondition($e3Custom->getMessage() === 'Bloqueo urgente manual', "3.7 Admite mensaje personalizado");

    // -------------------------------------------------------------
    // 4. ActiveMachinesBlockedException (RF-01, EARS 1.4, HTTP 409)
    // -------------------------------------------------------------
    echo "\n--- 4. ActiveMachinesBlockedException (Sede con Máquinas Activas) ---\n";

    $e4 = new ActiveMachinesBlockedException(4, 1);
    assertCondition($e4 instanceof DomainException, "4.1 Hereda de DomainException");
    assertCondition($e4->getErrorCode() === 'LOCATION_HAS_ACTIVE_MACHINES', "4.2 Código LOCATION_HAS_ACTIVE_MACHINES");
    assertCondition($e4->getHttpStatusCode() === 409, "4.3 Código HTTP 409 Conflict");
    assertCondition($e4->getActiveMachinesCount() === 4, "4.4 Recuento de máquinas activas igual a 4");
    assertCondition($e4->getLocationId() === 1, "4.5 ID de sede preservado (1)");
    assertCondition(str_contains($e4->getMessage(), '4 máquinas activas asociadas'), "4.6 Mensaje auto-generado interpola recuento");

    // -------------------------------------------------------------
    // 5. MachineTransferBlockedException (Decisión QA 1, Art. V.6, HTTP 409)
    // -------------------------------------------------------------
    echo "\n--- 5. MachineTransferBlockedException (Máquina con Avería o Garantía) ---\n";

    $e5Transfer = new MachineTransferBlockedException(
        "No se puede trasladar la máquina",
        "INC-2026-0045",
        "RESOLVED",
        15
    );
    assertCondition($e5Transfer instanceof DomainException, "5.1 Hereda de DomainException");
    assertCondition($e5Transfer->getErrorCode() === 'MACHINE_TRANSFER_BLOCKED', "5.2 Código por defecto MACHINE_TRANSFER_BLOCKED");
    assertCondition($e5Transfer->getHttpStatusCode() === 409, "5.3 Código HTTP 409 Conflict");
    assertCondition($e5Transfer->getTicketCode() === "INC-2026-0045", "5.4 Código de ticket preservado");
    assertCondition($e5Transfer->getTicketStatus() === "RESOLVED", "5.5 Estado de ticket preservado");
    assertCondition($e5Transfer->getMachineId() === 15, "5.6 ID de máquina preservado");

    $e5Deact = new MachineTransferBlockedException(
        "No se puede dar de baja la máquina",
        "INC-2026-0048",
        "PENDING_PARTS",
        15,
        'MACHINE_DEACTIVATION_BLOCKED'
    );
    assertCondition($e5Deact->getErrorCode() === 'MACHINE_DEACTIVATION_BLOCKED', "5.7 Admite código MACHINE_DEACTIVATION_BLOCKED para bajas");

    // -------------------------------------------------------------
    // 6. MachineTypeChangeBlockedException (Decisión QA 3, Art. II, HTTP 409)
    // -------------------------------------------------------------
    echo "\n--- 6. MachineTypeChangeBlockedException (Cambio de Tipología Sanitaria) ---\n";

    $e6 = new MachineTypeChangeBlockedException(
        "Bloqueo por avería activa de frío",
        "INC-2026-0042",
        "IN_PROGRESS",
        20
    );
    assertCondition($e6 instanceof DomainException, "6.1 Hereda de DomainException");
    assertCondition($e6->getErrorCode() === 'MACHINE_TYPE_CHANGE_BLOCKED', "6.2 Código MACHINE_TYPE_CHANGE_BLOCKED");
    assertCondition($e6->getHttpStatusCode() === 409, "6.3 Código HTTP 409 Conflict");
    assertCondition($e6->getTicketCode() === "INC-2026-0042", "6.4 Código de ticket asignado");
    assertCondition($e6->getTicketStatus() === "IN_PROGRESS", "6.5 Estado de ticket asignado");
    assertCondition($e6->getMachineId() === 20, "6.6 ID de máquina preservado (20)");

    // -------------------------------------------------------------
    // 7. InactiveRecordCollisionException (Decisión QA 5, HTTP 409)
    // -------------------------------------------------------------
    echo "\n--- 7. InactiveRecordCollisionException (Colisión con Registro Inactivo) ---\n";

    $e7Location = new InactiveRecordCollisionException(
        'LOCATION_ALREADY_EXISTS_INACTIVE',
        'El código de sede ya existe dado de baja.',
        8,
        'LOCATION',
        true
    );
    assertCondition($e7Location instanceof DomainException, "7.1 Hereda de DomainException");
    assertCondition($e7Location->getErrorCode() === 'LOCATION_ALREADY_EXISTS_INACTIVE', "7.2 Código LOCATION_ALREADY_EXISTS_INACTIVE");
    assertCondition($e7Location->getEntityId() === 8, "7.3 ID de entidad preservado (8)");
    assertCondition($e7Location->getEntityType() === 'LOCATION', "7.4 Tipo de entidad 'LOCATION'");
    assertCondition($e7Location->canReactivate() === true, "7.5 Bandera canReactivate() es true para registros inactivos");
    assertCondition($e7Location->getHttpStatusCode() === 409, "7.6 Código HTTP 409 Conflict");

    $e7MachineActive = new InactiveRecordCollisionException(
        'MACHINE_ALREADY_EXISTS_ACTIVE',
        'El código de máquina ya está en uso.',
        12,
        'MACHINE',
        false
    );
    assertCondition($e7MachineActive->canReactivate() === false, "7.7 canReactivate() es false si el registro colisiona activo");
    assertCondition($e7MachineActive->getErrorCode() === 'MACHINE_ALREADY_EXISTS_ACTIVE', "7.8 Código MACHINE_ALREADY_EXISTS_ACTIVE");

    $e7User = new InactiveRecordCollisionException(
        'USER_ALREADY_EXISTS_INACTIVE',
        'El email pertenece a un usuario inactivo.',
        3,
        'USER',
        true
    );
    assertCondition($e7User->getEntityType() === 'USER', "7.9 Soporte para entidad USER en colisión de email");

    // -------------------------------------------------------------
    // RESUMEN GLOBAL DE LA SUITE
    // -------------------------------------------------------------
    echo "\n======================================================================\n";
    echo " RESULTADO: {$assertions} aserciones evaluadas. 100% EN VERDE.\n";
    echo " Total Aserciones: {$assertions}\n";
    echo "======================================================================\n";
    exit(0);

} catch (Throwable $e) {
    echo "\n[ERROR INESPERADO]: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
