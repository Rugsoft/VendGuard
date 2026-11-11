<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\SlaTier;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;

/**
 * SlaTargetCalculator
 *
 * Servicio de dominio puro y determinista que resuelve el objetivo de SLA de
 * un aviso (Módulo 30, RF-01, D1, QA 1). El techo sanitario del Art. II es la
 * primera regla y no admite excepciones: cualquier máquina de perecederos, o
 * una mixta con fallo térmico o prioridad crítica, recibe 240 minutos con
 * independencia del perfil de la sede y de la prioridad declarada.
 */
final class SlaTargetCalculator
{
    /** Techo innegociable de seguridad alimentaria (Art. II, QA 1). */
    public const COLD_CHAIN_CEILING_MINUTES = 240;

    /**
     * ¿El aviso queda bajo el techo de cadena de frío del Art. II?
     */
    public static function isColdChainRisk(
        MachineType $machineType,
        IncidentCategory $category,
        UrgencyLevel $urgency
    ): bool {
        if ($machineType === MachineType::PERISHABLE_FOOD) {
            return true;
        }

        return $machineType === MachineType::COMBO
            && ($category === IncidentCategory::TEMPERATURE_COLD || $urgency === UrgencyLevel::CRITICAL);
    }

    /**
     * Minutos objetivos para un aviso (RF-01.1 a RF-01.3).
     */
    public static function calculateTargetMinutes(
        SlaTier $slaTier,
        MachineType $machineType,
        IncidentCategory $category,
        UrgencyLevel $urgency
    ): int {
        if (self::isColdChainRisk($machineType, $category, $urgency)) {
            return self::COLD_CHAIN_CEILING_MINUTES;
        }

        return $slaTier->contractualTargetMinutes($urgency);
    }

    /**
     * Proyecta el vencimiento efectivo desde el alta, sin reiniciar el reloj (C2, C4, QA 3).
     *
     * Los segundos descontables (pausas de sede e intervalo cerrado de una
     * reapertura en garantía) sólo pueden empujar el vencimiento hacia adelante;
     * un valor negativo se trata como cero para que una resta invertida no
     * acorte el plazo contractual.
     */
    public static function computeEffectiveTargetAt(
        DateTimeImmutable $createdAt,
        int $slaTargetMinutes,
        int $totalDiscountableSeconds = 0
    ): DateTimeImmutable {
        $totalSeconds = ($slaTargetMinutes * 60) + max(0, $totalDiscountableSeconds);

        return $createdAt->modify("+{$totalSeconds} seconds");
    }
}