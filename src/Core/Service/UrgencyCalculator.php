<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

/**
 * UrgencyCalculator
 * 
 * Servicio de Dominio que calcula automáticamente el nivel de urgencia
 * de una incidencia según el tipo de máquina dispensadora y la categoría de avería.
 * 
 * Regla de Oro Sanitaria (Constitución Art. II / EARS 3.2):
 * Toda pérdida de frío o fallo eléctrico en máquinas de alimentos perecederos
 * (PERISHABLE_FOOD) se cataloga de forma obligatoria e innegociable como CRITICAL.
 */
class UrgencyCalculator
{
    /**
     * Calcula el nivel de urgencia correspondiente a una avería.
     *
     * @param MachineType|string $machineType Tipo de máquina (Enum o string).
     * @param IncidentCategory|string $category Categoría de la avería (Enum o string).
     * @return UrgencyLevel Nivel de urgencia calculado.
     */
    public static function calculate(
        MachineType|string $machineType,
        IncidentCategory|string $category
    ): UrgencyLevel {
        $typeVal = $machineType instanceof MachineType 
            ? $machineType->value 
            : strtoupper(trim($machineType));

        $catVal = $category instanceof IncidentCategory 
            ? $category->value 
            : strtoupper(trim($category));

        // 1. Fallo de refrigeración / temperatura (EARS 3.2, 3.3)
        if ($catVal === IncidentCategory::TEMPERATURE_COLD->value) {
            if ($typeVal === MachineType::PERISHABLE_FOOD->value) {
                // Mandato Sanitario: riesgo inminente de proliferación bacteriana
                return UrgencyLevel::CRITICAL;
            }
            // Bebidas frías, calientes o mixtas sin riesgo de descomposición perecedera
            return UrgencyLevel::MEDIUM;
        }

        // 2. Fallo en sistemas de pago / monedero / datáfono (EARS 3.4)
        if ($catVal === IncidentCategory::PAYMENT_SYSTEM->value) {
            // Bloqueo total de transacciones comerciales
            return UrgencyLevel::HIGH;
        }

        // 3. Atasco mecánico de producto (EARS 3.5)
        if ($catVal === IncidentCategory::PRODUCT_JAM->value) {
            // Afecta habitualmente a un carril o espiral individual
            return UrgencyLevel::MEDIUM;
        }

        // 4. Máquina apagada / Fallo de suministro eléctrico (EARS 3.2 + Art. II)
        if ($catVal === IncidentCategory::ELECTRICAL_OFF->value) {
            if ($typeVal === MachineType::PERISHABLE_FOOD->value) {
                // Máquina apagada = pérdida inminente de cadena de frío
                return UrgencyLevel::CRITICAL;
            }
            // Máquina no perecedera apagada: detiene el servicio pero sin riesgo sanitario
            return UrgencyLevel::HIGH;
        }

        // 5. Desperfectos cosméticos o de iluminación decorativa (EARS 3.6)
        if ($catVal === 'COSMETIC_LIGHTING') {
            return UrgencyLevel::LOW;
        }

        // 6. Otras averías o categoría no tipificada (EARS 3.7)
        if ($catVal === IncidentCategory::OTHER->value) {
            return UrgencyLevel::MEDIUM;
        }

        // Valor de seguridad prudencial por defecto
        return UrgencyLevel::MEDIUM;
    }

    /**
     * Calcula y devuelve el valor escalar en string del nivel de urgencia.
     *
     * @param MachineType|string $machineType
     * @param IncidentCategory|string $category
     * @return string
     */
    public static function calculateValue(
        MachineType|string $machineType,
        IncidentCategory|string $category
    ): string {
        return self::calculate($machineType, $category)->value;
    }
}
