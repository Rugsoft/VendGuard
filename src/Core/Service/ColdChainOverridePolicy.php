<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

/**
 * ColdChainOverridePolicy
 *
 * Política de dominio pura que decide **cuándo** el coordinador debe justificar el
 * descarte de la recomendación sanitaria de frío (Art. II y Art. V.1, RF-05.2,
 * Resolución QA 8) y **qué** cuenta como justificación suficiente.
 *
 * La regla, al pie de la letra del contrato §3.7:
 *
 *   El campo `override_justification` (≥ 20 caracteres reales) es obligatorio
 *   **sólo** cuando se dan las cuatro condiciones a la vez:
 *
 *     1. la avería presenta riesgo de cadena de frío (`is_cold_chain_risk`);
 *     2. la recomendación sanitaria está activa (`is_cold_chain_escalation`), es
 *        decir, ningún titular activo de la zona estaba libre;
 *     3. el primer puesto del ranking es el técnico de guardia 24/7 (`is_on_call`);
 *     4. el coordinador asigna a otro técnico distinto de ese primer puesto.
 *
 * Fuera de ese caso no se pide nada: exigir la justificación en una avería
 * ordinaria convertiría un mandato sanitario en burocracia y el coordinador
 * acabaría escribiendo cualquier cosa para salir del paso —el efecto contrario al
 * que el Art. V.1 persigue—. `reassignment_reason` es un campo distinto y sigue
 * siendo obligatorio por su propia regla (RF-07.3): uno razona el descarte
 * sanitario y el otro el cambio de titular, y pueden convivir en la misma
 * reasignación.
 *
 * POR QUÉ VIVE EN `Core`
 *
 * No consulta la base, no lee el reloj y no conoce HTTP: recibe el ranking ya
 * compuesto por `TechnicianAssignmentSuggester` y responde con un booleano. Así la
 * salvaguarda constitucional se certifica con pruebas de dominio y no depende de
 * que el controlador recuerde aplicarla.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class ColdChainOverridePolicy
{
    /**
     * Longitud mínima de la justificación, en caracteres reales (Art. V.1).
     *
     * Se mide con `mb_strlen()` sobre el texto recortado: un acento cuenta uno y
     * los espacios de relleno no sirven para alcanzar el umbral.
     */
    public const MINIMUM_JUSTIFICATION_LENGTH = 20;

    /** Prioridad de la avería que activa el riesgo sanitario por sí sola. */
    private const COLD_CHAIN_PRIORITY = 'CRITICAL';

    /** Tipos de máquina bajo vigilancia de cadena de frío (RF-03.1). */
    private const COLD_CHAIN_MACHINE_TYPES = ['PERISHABLE_FOOD', 'COMBO'];

    /**
     * ¿El despacho hacia `$technicianId` descarta la recomendación sanitaria?
     *
     * @param array<string, mixed> $suggestion Ranking del recomendador ya serializado
     *                                          (`incident`, `recommendation`,
     *                                          `ranked_technicians`), tal como lo
     *                                          publica el contrato §3.6.
     * @param int $technicianId Técnico que el coordinador intenta asignar.
     *
     * @return bool `true` cuando falta (o no basta) la justificación del Art. V.1.
     */
    public function requiresOverride(array $suggestion, int $technicianId): bool
    {
        $incident = $suggestion['incident'] ?? [];
        $recommendation = $suggestion['recommendation'] ?? [];
        $ranked = $suggestion['ranked_technicians'] ?? [];

        if (!is_array($incident) || !is_array($recommendation) || !is_array($ranked)) {
            return false;
        }

        if (!$this->isColdChainRisk($incident)) {
            return false;
        }

        if (self::readFlag($recommendation, 'is_cold_chain_escalation') !== true) {
            return false;
        }

        $top = $ranked[0] ?? null;
        if (!is_array($top)) {
            return false;
        }

        // La salvaguarda protege al técnico de guardia **sugerido**: si el ranking lo
        // encabeza un titular libre, descartarlo no pone en riesgo la cadena de frío.
        if (self::readFlag($top, 'is_on_call') !== true) {
            return false;
        }

        return (int)($top['technician_id'] ?? 0) !== $technicianId;
    }

    /**
     * ¿La justificación alcanza el mínimo constitucional?
     *
     * El texto se recorta antes de medir: 20 espacios no son una justificación.
     */
    public function isValidJustification(mixed $justification): bool
    {
        return mb_strlen($this->normalize($justification), 'UTF-8') >= self::MINIMUM_JUSTIFICATION_LENGTH;
    }

    /**
     * Texto de la justificación ya recortado, listo para la auditoría.
     *
     * Devuelve cadena vacía cuando no hay justificación: quien la consume decide si
     * es un error o la ausencia legítima de un caso no sanitario.
     */
    public function normalize(mixed $justification): string
    {
        if (!is_string($justification)) {
            return '';
        }

        return trim($justification);
    }

    /**
     * Riesgo de cadena de frío declarado o derivado de prioridad y máquina.
     *
     * Se respeta la bandera del ranking cuando viaja; si el consumidor compuso el
     * bloque a mano, la política deriva el riesgo con el mismo criterio del
     * recomendador (`priority` crítica o máquina perecedera/`COMBO`).
     *
     * @param array<string, mixed> $incident
     */
    private function isColdChainRisk(array $incident): bool
    {
        if (array_key_exists('is_cold_chain_risk', $incident)) {
            return self::readFlag($incident, 'is_cold_chain_risk') === true;
        }

        return self::deriveColdChainRisk(
            (string)($incident['priority'] ?? ''),
            isset($incident['machine_type']) ? (string)$incident['machine_type'] : null
        );
    }

    /**
     * Riesgo de frío a partir de la prioridad y el tipo de máquina del expediente.
     *
     * Es la **misma** derivación que aplica `TechnicianAssignmentSuggester`, y vive
     * aquí para que la guarda barata del controlador —que evita componer el ranking
     * en despachos ordinarios— no acabe declarando otra regla distinta de la que
     * gobierna el ranking y la justificación. Una prioridad `CRITICAL` o una máquina
     * `PERISHABLE_FOOD`/`COMBO` activan la vigilancia del Art. II.
     */
    public static function deriveColdChainRisk(string $priority, ?string $machineType): bool
    {
        if (mb_strtoupper(trim($priority)) === self::COLD_CHAIN_PRIORITY) {
            return true;
        }

        return in_array(mb_strtoupper(trim((string)$machineType)), self::COLD_CHAIN_MACHINE_TYPES, true);
    }

    /**
     * Booleano tolerante con la forma en que viaja el indicador.
     *
     * @param array<string, mixed> $source
     */
    private static function readFlag(array $source, string $key): ?bool
    {
        if (!array_key_exists($key, $source)) {
            return null;
        }

        $value = $source[$key];

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));

        return in_array($normalized, ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
