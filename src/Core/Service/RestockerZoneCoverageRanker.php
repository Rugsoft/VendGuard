<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * RestockerZoneCoverageRanker
 *
 * Calculador puro y determinista del escalafón de cobertura de carga de una zona
 * operativa (Módulo 31, RF-03.1, RF-03.2, C2, QA 3).
 *
 * Recibe una **foto** del cuadrante de carga —la vinculación viva de cada
 * reponedor con su rol y su estado de turno— y devuelve el mismo orden siempre:
 * no lee el reloj, no consulta la base de datos y no muta la entrada (RNF-01).
 * El servicio de aplicación compondrá la foto con los repositorios y se limitará
 * a proyectar este resultado.
 *
 * ESCALAFÓN (7 escalones, 6 activos)
 *
 *   1. `PRIMARY_ON_SHIFT`            Titular en turno.
 *   2. `BACKUP_PROMOTED_ON_SHIFT`    Suplente en turno (promoción automática).
 *   3. `PRIMARY_MEAL_BREAK`          Titular en pausa de comida.
 *   4. `BACKUP_PROMOTED_MEAL_BREAK`  Suplente en pausa de comida (promoción).
 *   5. `PRIMARY_PLANNED_OFF_SHIFT`   Titular fuera de turno y sin ausencia.
 *   6. `BACKUP_PROMOTED_OFF_SHIFT`   Suplente fuera de turno y sin ausencia.
 *   7. `UNAVAILABLE`                 Cuenta inactiva o fuera de turno con ausencia.
 *
 * El orden no es una preferencia estética: la presencia física en turno manda
 * sobre la planificación (`C2`). Un reponedor con una ausencia vigente que ha
 * fichado entrada ocupa su escalón de turno y se marca `has_overridden_absence`,
 * porque está en ruta; la ausencia planificada solo lo aparta cuando además está
 * fuera de turno.
 *
 * DESEMPATE (RF-03.2)
 *
 * Dentro del mismo escalón: mayor antigüedad en la zona (`assigned_at ASC`),
 * después `operator_code ASC` y por último `restocker_id ASC`. El identificador
 * cierra el empate para que dos fotos idénticas produzcan el mismo orden.
 *
 * EL `#1 RECOMENDADO ⭐`
 *
 * Encabeza el primer candidato de los seis escalones activos. Si es un `BACKUP`,
 * la etiqueta declara la promoción automática; si es un `PRIMARY`, la etiqueta
 * declara la titularidad. Con todos los candidatos en `UNAVAILABLE` la zona queda
 * `UNCOVERED` y no hay recomendación: inventar una seria peor que decirlo.
 *
 * FORMA DE LA FOTO
 *
 *   $candidates = [[
 *     'restocker_id' => 12, 'name' => 'Lucía Gómez', 'operator_code' => 'REP-01',
 *     'role_in_zone' => 'PRIMARY',            // PRIMARY | BACKUP
 *     'is_primary' => true,                   // opcional; se deriva y se contrasta
 *     'is_active' => true,
 *     'shift_status' => 'ON_SHIFT',           // ON_SHIFT | MEAL_BREAK | OFF_SHIFT
 *     'has_active_absence' => false,          // ausencia vigente en la zona
 *     'absence_reason' => 'VACATION',         // motivo legible, opcional
 *     'assigned_at' => '2026-11-01T08:00:00+01:00',
 *   ]];
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockerZoneCoverageRanker
{
    /** Escalón 1: titular en turno. */
    public const TIER_PRIMARY_ON_SHIFT = 'PRIMARY_ON_SHIFT';

    /** Escalón 2: suplente en turno, promovido automáticamente. */
    public const TIER_BACKUP_PROMOTED_ON_SHIFT = 'BACKUP_PROMOTED_ON_SHIFT';

    /** Escalón 3: titular en pausa de comida. */
    public const TIER_PRIMARY_MEAL_BREAK = 'PRIMARY_MEAL_BREAK';

    /** Escalón 4: suplente en pausa de comida, promovido automáticamente. */
    public const TIER_BACKUP_PROMOTED_MEAL_BREAK = 'BACKUP_PROMOTED_MEAL_BREAK';

    /** Escalón 5: titular fuera de turno y sin ausencia. */
    public const TIER_PRIMARY_PLANNED_OFF_SHIFT = 'PRIMARY_PLANNED_OFF_SHIFT';

    /** Escalón 6: suplente fuera de turno y sin ausencia. */
    public const TIER_BACKUP_PROMOTED_OFF_SHIFT = 'BACKUP_PROMOTED_OFF_SHIFT';

    /** Escalón 7: sin disponibilidad efectiva. */
    public const TIER_UNAVAILABLE = 'UNAVAILABLE';

    /** La zona está cubierta por su titular. */
    public const COVERAGE_COVERED_BY_PRIMARY = 'COVERED_BY_PRIMARY';

    /** La zona está cubierta por un suplente promovido automáticamente. */
    public const COVERAGE_COVERED_BY_PROMOTED_BACKUP = 'COVERED_BY_PROMOTED_BACKUP';

    /** Ningún reponedor del cuadrante puede cubrir la zona. */
    public const COVERAGE_UNCOVERED = 'UNCOVERED';

    /** Prefijo de la designación del primer candidato. */
    public const RECOMMENDATION_PREFIX = '#1 RECOMENDADO ⭐';

    /** Etiqueta de promoción automática del suplente (`D3`, `QA 3`). */
    public const PROMOTION_LABEL = 'Suplente en Cobertura Activa (Promoción automática)';

    /** Etiqueta de cobertura del titular. */
    public const PRIMARY_COVERAGE_LABEL = 'Titular de Zona en Cobertura Activa';

    /** Motivo de indisponibilidad de una cuenta desactivada (`C3`). */
    public const UNAVAILABLE_INACTIVE = 'Cuenta inactiva';

    /** Motivo de indisponibilidad por una ausencia vigente. */
    public const UNAVAILABLE_ABSENCE_PREFIX = 'Ausencia activa: ';

    /** Motivo cuando la ausencia vigente no declara su causa. */
    public const UNAVAILABLE_ABSENCE_UNKNOWN = 'Sin motivo declarado';

    /** Turnos admitidos en la foto. */
    private const SHIFT_STATUSES = ['ON_SHIFT', 'MEAL_BREAK', 'OFF_SHIFT'];

    /**
     * Catálogo de escalones: rango, etiqueta, promoción automática y disponibilidad.
     *
     * @var array<string, array{0: int, 1: string, 2: bool, 3: bool}>
     */
    private const TIER_CATALOG = [
        self::TIER_PRIMARY_ON_SHIFT => [1, 'Titular en turno', false, true],
        self::TIER_BACKUP_PROMOTED_ON_SHIFT => [2, 'Suplente en turno (promoción automática)', true, true],
        self::TIER_PRIMARY_MEAL_BREAK => [3, 'Titular en pausa de comida', false, true],
        self::TIER_BACKUP_PROMOTED_MEAL_BREAK => [4, 'Suplente en pausa (promoción automática)', true, true],
        self::TIER_PRIMARY_PLANNED_OFF_SHIFT => [5, 'Titular fuera de turno', false, true],
        self::TIER_BACKUP_PROMOTED_OFF_SHIFT => [6, 'Suplente fuera de turno (promoción automática)', true, true],
        self::TIER_UNAVAILABLE => [7, 'No disponible', false, false],
    ];

    /**
     * Ordena el cuadrante de carga y resuelve la cobertura de la zona.
     *
     * @param array<int, array<string, mixed>> $candidates Foto del cuadrante vivo.
     * @return array{
     *   coverage_status: string,
     *   recommended_restocker: array<string, mixed>|null,
     *   candidates: list<array<string, mixed>>
     * }
     *
     * @throws InvalidArgumentException Si un candidato no describe un reponedor válido.
     */
    public static function rank(array $candidates): array
    {
        $rows = [];
        foreach (array_values($candidates) as $index => $candidate) {
            if (!is_array($candidate)) {
                throw new InvalidArgumentException(
                    sprintf('El candidato en la posición %d no es una foto de reponedor válida.', $index + 1)
                );
            }

            $rows[] = self::evaluate($candidate, $index);
        }

        usort($rows, static function (array $left, array $right): int {
            return ($left['tier_rank'] <=> $right['tier_rank'])
                ?: ($left['assigned_at']->getTimestamp() <=> $right['assigned_at']->getTimestamp())
                ?: ($left['assigned_at']->format('u') <=> $right['assigned_at']->format('u'))
                ?: strcmp((string)$left['operator_code'], (string)$right['operator_code'])
                ?: ($left['restocker_id'] <=> $right['restocker_id']);
        });

        $ranked = [];
        $recommended = null;
        $position = 0;

        foreach ($rows as $row) {
            $position++;
            $isRecommended = $position === 1 && $row['is_available'];

            $projection = [
                'restocker_id' => $row['restocker_id'],
                'name' => $row['name'],
                'operator_code' => $row['operator_code'],
                'role_in_zone' => $row['role_in_zone'],
                'role_label' => $row['is_primary'] ? 'Titular' : 'Suplente',
                'is_primary' => $row['is_primary'],
                'is_active' => $row['is_active'],
                'shift_status' => $row['shift_status'],
                'coverage_tier' => $row['coverage_tier'],
                'coverage_tier_label' => self::TIER_CATALOG[$row['coverage_tier']][1],
                'tier_rank' => $row['tier_rank'],
                'is_available' => $row['is_available'],
                'is_recommended' => $isRecommended,
                'is_promoted_backup' => $row['is_promoted_backup'],
                'has_overridden_absence' => $row['has_overridden_absence'],
                'unavailable_reason' => $row['unavailable_reason'],
                'assigned_at' => $row['assigned_at']->format(DateTimeImmutable::ATOM),
            ];

            if ($isRecommended) {
                $recommended = $projection + [
                    'recommendation_label' => self::RECOMMENDATION_PREFIX . ' · '
                        . ($row['is_promoted_backup'] ? self::PROMOTION_LABEL : self::PRIMARY_COVERAGE_LABEL),
                ];
            }

            $ranked[] = $projection;
        }

        if ($recommended === null) {
            $status = self::COVERAGE_UNCOVERED;
        } elseif ($recommended['is_promoted_backup']) {
            $status = self::COVERAGE_COVERED_BY_PROMOTED_BACKUP;
        } else {
            $status = self::COVERAGE_COVERED_BY_PRIMARY;
        }

        return [
            'coverage_status' => $status,
            'recommended_restocker' => $recommended,
            'candidates' => $ranked,
        ];
    }

    /**
     * Clasifica un candidato en su escalón, sin ordenar todavía.
     *
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private static function evaluate(array $candidate, int $index): array
    {
        $restockerId = self::readRequiredInt($candidate, 'restocker_id', $index);
        $roleInZone = mb_strtoupper(trim((string)($candidate['role_in_zone'] ?? '')));

        if (!in_array($roleInZone, ['PRIMARY', 'BACKUP'], true)) {
            throw new InvalidArgumentException(
                sprintf(
                    "El candidato %d declara un role_in_zone no válido: '%s'. Se admite PRIMARY o BACKUP.",
                    $restockerId,
                    $roleInZone
                )
            );
        }

        $isPrimary = $roleInZone === 'PRIMARY';
        if (array_key_exists('is_primary', $candidate) && $candidate['is_primary'] !== null) {
            $declaredPrimary = self::readFlag($candidate, 'is_primary');
            if ($declaredPrimary !== $isPrimary) {
                throw new InvalidArgumentException(
                    sprintf(
                        "El candidato %d se contradice: role_in_zone '%s' con is_primary = %s.",
                        $restockerId,
                        $roleInZone,
                        $declaredPrimary ? 'TRUE' : 'FALSE'
                    )
                );
            }
        }

        $isActive = self::readFlag($candidate, 'is_active', true);
        $shiftStatus = mb_strtoupper(trim((string)($candidate['shift_status'] ?? 'OFF_SHIFT')));
        if ($shiftStatus === '') {
            $shiftStatus = 'OFF_SHIFT';
        }
        if (!in_array($shiftStatus, self::SHIFT_STATUSES, true)) {
            throw new InvalidArgumentException(
                sprintf("El candidato %d declara un turno no válido: '%s'.", $restockerId, $shiftStatus)
            );
        }

        $hasAbsence = self::readFlag($candidate, 'has_active_absence');
        $absenceReason = trim((string)($candidate['absence_reason'] ?? ''));
        $absenceReason = $absenceReason === '' ? self::UNAVAILABLE_ABSENCE_UNKNOWN : $absenceReason;

        $tier = self::TIER_UNAVAILABLE;
        $unavailableReason = null;
        $hasOverriddenAbsence = false;

        if (!$isActive) {
            $unavailableReason = self::UNAVAILABLE_INACTIVE;
        } elseif ($shiftStatus === 'ON_SHIFT') {
            $tier = $isPrimary ? self::TIER_PRIMARY_ON_SHIFT : self::TIER_BACKUP_PROMOTED_ON_SHIFT;
            $hasOverriddenAbsence = $hasAbsence;
        } elseif ($shiftStatus === 'MEAL_BREAK') {
            $tier = $isPrimary ? self::TIER_PRIMARY_MEAL_BREAK : self::TIER_BACKUP_PROMOTED_MEAL_BREAK;
            $hasOverriddenAbsence = $hasAbsence;
        } elseif ($hasAbsence) {
            $unavailableReason = self::UNAVAILABLE_ABSENCE_PREFIX . $absenceReason;
        } else {
            $tier = $isPrimary ? self::TIER_PRIMARY_PLANNED_OFF_SHIFT : self::TIER_BACKUP_PROMOTED_OFF_SHIFT;
        }

        $catalog = self::TIER_CATALOG[$tier];

        return [
            'restocker_id' => $restockerId,
            'name' => trim((string)($candidate['name'] ?? '')),
            'operator_code' => isset($candidate['operator_code']) && trim((string)$candidate['operator_code']) !== ''
                ? trim((string)$candidate['operator_code'])
                : null,
            'role_in_zone' => $roleInZone,
            'is_primary' => $isPrimary,
            'is_active' => $isActive,
            'shift_status' => $shiftStatus,
            'coverage_tier' => $tier,
            'tier_rank' => $catalog[0],
            'is_available' => $catalog[3],
            'is_promoted_backup' => $catalog[2],
            'has_overridden_absence' => $hasOverriddenAbsence,
            'unavailable_reason' => $unavailableReason,
            'assigned_at' => self::readAssignedAt($candidate, $restockerId),
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private static function readAssignedAt(array $candidate, int $restockerId): DateTimeImmutable
    {
        $raw = $candidate['assigned_at'] ?? null;

        if ($raw instanceof DateTimeImmutable) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            throw new InvalidArgumentException(
                sprintf('El candidato %d debe declarar su assigned_at para poder desempatar.', $restockerId)
            );
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new InvalidArgumentException(
                sprintf("El candidato %d declara un assigned_at ilegible: '%s'.", $restockerId, $raw)
            );
        }
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private static function readRequiredInt(array $candidate, string $key, int $index): int
    {
        $value = $candidate[$key] ?? null;

        if (!is_numeric($value) || (int)$value <= 0) {
            throw new InvalidArgumentException(
                sprintf("El candidato en la posición %d no declara un '%s' positivo.", $index + 1, $key)
            );
        }

        return (int)$value;
    }

    /**
     * Bandera booleana tolerante a las formas del motor (`t`/`f`/`1`/`0`).
     *
     * @param array<string, mixed> $candidate
     */
    private static function readFlag(array $candidate, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $candidate) || $candidate[$key] === null) {
            return $default;
        }

        $value = $candidate[$key];
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
