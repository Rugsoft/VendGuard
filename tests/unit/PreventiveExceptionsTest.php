<?php

declare(strict_types=1);

/**
 * PreventiveExceptionsTest
 * 
 * Suite de pruebas unitarias para las Excepciones de Dominio del Módulo 05 (T-PREV-03).
 * Valida los códigos HTTP (400, 409, 422), identificadores de error normalizados,
 * detalles contextuales y mensajes descriptivos en castellano para las reglas preventivas:
 * - Límite de frecuencia en alimentos perecederos (Art. II).
 * - Rango físico y formato térmico [-5.0, 25.0] °C (RNF-06).
 * - Checklist incompleto o temperatura omitida (EARS 3.5).
 * - Colisión en autoasignación in situ / Visita Oportunista (EARS 2.3).
 * - Remisión de checklist sin iniciar inspección.
 * - Reinspección térmica superior a 4.0 °C (Art. II y RF-PREV-08).
 * - Prohibición de emitir certificados en máquinas en cuarentena o vencidas (RF-PREV-07).
 * - Pausa estacional sin motivo justificado documentado (RF-PREV-01).
 * 
 * Cumple con Dogma Vanilla (PHP 8.2+ sin dependencias externas) y Dualismo Lingüístico.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\PerishableFrequencyLimitException;
use VendGuard\Core\Domain\Exception\InvalidTemperatureRangeException;
use VendGuard\Core\Domain\Exception\ChecklistIncompleteException;
use VendGuard\Core\Domain\Exception\PreventiveOrderAlreadyAssignedException;
use VendGuard\Core\Domain\Exception\PreventiveOrderNotInInspectionException;
use VendGuard\Core\Domain\Exception\ReinspectionTemperatureExceededException;
use VendGuard\Core\Domain\Exception\CannotIssueNonConformCertificateException;
use VendGuard\Core\Domain\Exception\SeasonalPauseMissingReasonException;

echo "======================================================================\n";
echo " VendGuard: Verificación Unitaria de Excepciones Preventivas (T-PREV-03)\n";
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
    // 1. PerishableFrequencyLimitException (Art. II, HTTP 422)
    // -------------------------------------------------------------
    echo "--- 1. PerishableFrequencyLimitException (Tope 15d Perecederos) ---\n";

    $e1 = new PerishableFrequencyLimitException();
    assertCondition($e1 instanceof DomainException, "1.1 Hereda de DomainException");
    assertCondition($e1->getErrorCode() === 'PERISHABLE_FREQUENCY_LIMIT_EXCEEDED', "1.2 Código PERISHABLE_FREQUENCY_LIMIT_EXCEEDED");
    assertCondition($e1->getHttpStatusCode() === 422, "1.3 Código HTTP 422 Unprocessable Entity");
    assertCondition(str_contains($e1->getMessage(), 'Artículo II de la Constitución'), "1.4 Mensaje por defecto cita el Artículo II");
    assertCondition(str_contains($e1->getMessage(), '15 días naturales'), "1.5 Mensaje por defecto cita el tope de 15 días");

    $e1Context = new PerishableFrequencyLimitException(
        'Mensaje personalizado de frecuencia',
        20,
        15,
        'PERISHABLE_FOOD'
    );
    assertCondition($e1Context->getAttemptedDays() === 20, "1.6 Captura attemptedDays = 20");
    assertCondition($e1Context->getMaximumAllowedDays() === 15, "1.7 Captura maximumAllowedDays = 15");
    assertCondition($e1Context->getMachineType() === 'PERISHABLE_FOOD', "1.8 Captura machineType = PERISHABLE_FOOD");
    $details1 = $e1Context->getDetails();
    assertCondition($details1['attempted_days'] === 20 && $details1['maximum_allowed_days'] === 15, "1.9 getDetails() estructura correctamente el payload");

    // -------------------------------------------------------------
    // 2. InvalidTemperatureRangeException (RNF-06, HTTP 422)
    // -------------------------------------------------------------
    echo "\n--- 2. InvalidTemperatureRangeException (Rango Térmico Físico) ---\n";

    $e2 = new InvalidTemperatureRangeException();
    assertCondition($e2 instanceof DomainException, "2.1 Hereda de DomainException");
    assertCondition($e2->getErrorCode() === 'INVALID_TEMPERATURE_RANGE', "2.2 Código INVALID_TEMPERATURE_RANGE");
    assertCondition($e2->getHttpStatusCode() === 422, "2.3 Código HTTP 422 Unprocessable Entity");
    assertCondition(str_contains($e2->getMessage(), '-5.0 °C y 25.0 °C'), "2.4 Mensaje cita el intervalo físico [-5.0, 25.0]");

    $e2Val = new InvalidTemperatureRangeException('Error térmico', 35.5, -5.0, 25.0);
    assertCondition($e2Val->getMeasuredTemperature() === 35.5, "2.5 Captura measuredTemperature = 35.5");
    assertCondition($e2Val->getMinTemperature() === -5.0, "2.6 Captura minTemperature = -5.0");
    assertCondition($e2Val->getMaxTemperature() === 25.0, "2.7 Captura maxTemperature = 25.0");
    $details2 = $e2Val->getDetails();
    assertCondition($details2['measured_temperature'] === 35.5, "2.8 getDetails() expone la temperatura anómala");

    // -------------------------------------------------------------
    // 3. ChecklistIncompleteException (EARS 3.5, HTTP 422)
    // -------------------------------------------------------------
    echo "\n--- 3. ChecklistIncompleteException (Checklist Incompleto) ---\n";

    $e3 = new ChecklistIncompleteException();
    assertCondition($e3 instanceof DomainException, "3.1 Hereda de DomainException");
    assertCondition($e3->getErrorCode() === 'CHECKLIST_INCOMPLETE', "3.2 Código CHECKLIST_INCOMPLETE");
    assertCondition($e3->getHttpStatusCode() === 422, "3.3 Código HTTP 422 Unprocessable Entity");
    assertCondition(str_contains($e3->getMessage(), 'puntos obligatorios'), "3.4 Mensaje descriptivo en castellano");

    $e3Items = new ChecklistIncompleteException('Faltan datos', ['TEMP_PROBE', 'EVAPORATOR_FROST']);
    assertCondition(count($e3Items->getMissingItems()) === 2, "3.5 Captura los ítems omitidos (conteo = 2)");
    assertCondition(in_array('TEMP_PROBE', $e3Items->getMissingItems(), true), "3.6 Incluye TEMP_PROBE en missingItems");
    $details3 = $e3Items->getDetails();
    assertCondition(is_array($details3['missing_items']), "3.7 getDetails() retorna array de ítems omitidos");

    // -------------------------------------------------------------
    // 4. PreventiveOrderAlreadyAssignedException (EARS 2.3, HTTP 409)
    // -------------------------------------------------------------
    echo "\n--- 4. PreventiveOrderAlreadyAssignedException (Colisión Visita Oportunista) ---\n";

    $e4 = new PreventiveOrderAlreadyAssignedException();
    assertCondition($e4 instanceof DomainException, "4.1 Hereda de DomainException");
    assertCondition($e4->getErrorCode() === 'ORDER_ALREADY_ASSIGNED', "4.2 Código ORDER_ALREADY_ASSIGNED");
    assertCondition($e4->getHttpStatusCode() === 409, "4.3 Código HTTP 409 Conflict");
    assertCondition(str_contains($e4->getMessage(), 'ya se encuentra asignada'), "4.4 Mensaje descriptivo de conflicto");

    $e4Context = new PreventiveOrderAlreadyAssignedException(
        'Orden ya asignada a otro técnico',
        89,
        'SCHEDULED',
        2
    );
    assertCondition($e4Context->getOrderId() === 89, "4.5 Captura orderId = 89");
    assertCondition($e4Context->getCurrentStatus() === 'SCHEDULED', "4.6 Captura currentStatus = SCHEDULED");
    assertCondition($e4Context->getAssignedTechnicianId() === 2, "4.7 Captura assignedTechnicianId = 2");
    $details4 = $e4Context->getDetails();
    assertCondition($details4['order_id'] === 89 && $details4['current_status'] === 'SCHEDULED', "4.8 getDetails() expone metadatos de colisión");

    // -------------------------------------------------------------
    // 5. PreventiveOrderNotInInspectionException (HTTP 409)
    // -------------------------------------------------------------
    echo "\n--- 5. PreventiveOrderNotInInspectionException (Orden no Iniciada) ---\n";

    $e5 = new PreventiveOrderNotInInspectionException();
    assertCondition($e5 instanceof DomainException, "5.1 Hereda de DomainException");
    assertCondition($e5->getErrorCode() === 'ORDER_NOT_IN_INSPECTION', "5.2 Código ORDER_NOT_IN_INSPECTION");
    assertCondition($e5->getHttpStatusCode() === 409, "5.3 Código HTTP 409 Conflict");
    assertCondition(str_contains($e5->getMessage(), 'EN_INSPECCION'), "5.4 Mensaje especifica estado EN_INSPECCION requerido");

    $e5Context = new PreventiveOrderNotInInspectionException('Estado incorrecto', 89, 'SCHEDULED');
    assertCondition($e5Context->getOrderId() === 89, "5.5 Captura orderId = 89");
    assertCondition($e5Context->getCurrentStatus() === 'SCHEDULED', "5.6 Captura currentStatus = SCHEDULED");
    $details5 = $e5Context->getDetails();
    assertCondition($details5['current_status'] === 'SCHEDULED', "5.7 getDetails() serializa el estado erróneo");

    // -------------------------------------------------------------
    // 6. ReinspectionTemperatureExceededException (Art. II, HTTP 422)
    // -------------------------------------------------------------
    echo "\n--- 6. ReinspectionTemperatureExceededException (Reinspección Frío > 4.0 °C) ---\n";

    $e6 = new ReinspectionTemperatureExceededException();
    assertCondition($e6 instanceof DomainException, "6.1 Hereda de DomainException");
    assertCondition($e6->getErrorCode() === 'REINSPECTION_TEMPERATURE_TOO_HIGH', "6.2 Código REINSPECTION_TEMPERATURE_TOO_HIGH");
    assertCondition($e6->getHttpStatusCode() === 422, "6.3 Código HTTP 422 Unprocessable Entity");
    assertCondition(str_contains($e6->getMessage(), '4.0 °C'), "6.4 Mensaje cita el límite legal de 4.0 °C");

    $e6Context = new ReinspectionTemperatureExceededException('Temperatura no conforme', 5.2, 4.0);
    assertCondition($e6Context->getMeasuredTemperature() === 5.2, "6.5 Captura measuredTemperature = 5.2");
    assertCondition($e6Context->getMaximumAllowedTemperature() === 4.0, "6.6 Captura maximumAllowedTemperature = 4.0");
    $details6 = $e6Context->getDetails();
    assertCondition($details6['measured_temperature'] === 5.2, "6.7 getDetails() expone la temperatura fallida");

    // -------------------------------------------------------------
    // 7. CannotIssueNonConformCertificateException (RF-PREV-07, HTTP 400)
    // -------------------------------------------------------------
    echo "\n--- 7. CannotIssueNonConformCertificateException (Bloqueo Certificado No Apta) ---\n";

    $e7 = new CannotIssueNonConformCertificateException();
    assertCondition($e7 instanceof DomainException, "7.1 Hereda de DomainException");
    assertCondition($e7->getErrorCode() === 'CANNOT_GENERATE_CERTIFICATE_NOT_CONFORM', "7.2 Código CANNOT_GENERATE_CERTIFICATE_NOT_CONFORM");
    assertCondition($e7->getHttpStatusCode() === 400, "7.3 Código HTTP 400 Bad Request");
    assertCondition(str_contains($e7->getMessage(), 'cuarentena sanitaria o con inspección vencida'), "7.4 Mensaje explica el motivo de inhabilitación");

    $e7Context = new CannotIssueNonConformCertificateException('Certificado denegado', 'VEND-0101', 'QUARANTINE');
    assertCondition($e7Context->getMachineCode() === 'VEND-0101', "7.5 Captura machineCode = VEND-0101");
    assertCondition($e7Context->getSanitaryStatus() === 'QUARANTINE', "7.6 Captura sanitaryStatus = QUARANTINE");
    $details7 = $e7Context->getDetails();
    assertCondition($details7['machine_code'] === 'VEND-0101' && $details7['sanitary_status'] === 'QUARANTINE', "7.7 getDetails() serializa datos de la máquina no apta");

    // -------------------------------------------------------------
    // 8. SeasonalPauseMissingReasonException (RF-PREV-01, HTTP 422)
    // -------------------------------------------------------------
    echo "\n--- 8. SeasonalPauseMissingReasonException (Pausa Estacional sin Justificación) ---\n";

    $e8 = new SeasonalPauseMissingReasonException();
    assertCondition($e8 instanceof DomainException, "8.1 Hereda de DomainException");
    assertCondition($e8->getErrorCode() === 'SEASONAL_PAUSE_REQUIRES_REASON', "8.2 Código SEASONAL_PAUSE_REQUIRES_REASON");
    assertCondition($e8->getHttpStatusCode() === 422, "8.3 Código HTTP 422 Unprocessable Entity");
    assertCondition(str_contains($e8->getMessage(), 'justificar documentalmente'), "8.4 Mensaje exige justificación documental");

    $e8Context = new SeasonalPauseMissingReasonException('Falta justificación vacacional', 14);
    assertCondition($e8Context->getMachineId() === 14, "8.5 Captura machineId = 14");
    $details8 = $e8Context->getDetails();
    assertCondition($details8['machine_id'] === 14, "8.6 getDetails() serializa machine_id");

    echo "\n======================================================================\n";
    echo " Total Aserciones: {$assertions}\n";
    echo " RESULTADO: 100% EN VERDE. Todas las excepciones de dominio verificadas.\n";
    echo " Condición T-PREV-03 CUMPLIDA SATISFACTORIAMENTE.\n";
    echo "======================================================================\n";
    exit(0);

} catch (Throwable $t) {
    echo "\n[ERROR INESPERADO]: " . $t->getMessage() . "\n";
    echo $t->getTraceAsString() . "\n";
    exit(1);
}
