<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

/**
 * RestockingBiCalculator
 *
 * Motor matemático puro del Cuadro de Mando de Reposición, Roturas de Stock y
 * Mermas Comerciales (Módulo 38; `D1`–`D3`, `QA-2`, `QA-4`, `QA-5`, Art. II).
 *
 * No lee la base de datos, no consulta el reloj y no escribe nada: entran las
 * filas agregadas que `PdoRestockingBiAnalyticsRepository` ya ha resuelto por
 * fuente independiente (`RNF-02`) y salen tablas ordenadas, KPIs y series de
 * gráfico deterministas.
 *
 * Reglas que aplica, en el orden del contrato:
 *
 *   1. **Disponibilidad dual (`D1`, `RF-02`).** Sobre visitas `COMPLETED`:
 *      `visit_in_stock_rate_pct = ROUND(clean / completed * 100, 2)` y
 *      `visit_stockout_rate_pct = ROUND(100.00 - visit_in_stock_rate_pct, 2)`,
 *      de modo que ambas suman exactamente `100,00 %`. Las visitas `COMPLETED`
 *      con `0` líneas cuentan como *In-Stock*; `ABORTED_THERMAL_BREACH` queda
 *      fuera del denominador y se expone en `thermal_aborted_visits_count`
 *      (Art. II). Sin denominador, la tasa es `null`, jamás `0 %` ni `100 %`.
 *   2. **Carriles (`RF-02.2`).** `slot_stockout_rate_pct` sobre líneas
 *      auditadas y `low_threshold_slots_count`, que el repositorio ya calcula
 *      exigiendo `mps.product_id = mvi.product_id` (`QA-5`).
 *   3. **Merma comercial (`D2`, `RF-03.1`).** Caducidad FEFO más dañado en
 *      ruta, exactos al céntimo, con
 *      `commercial_spoilage_loss_eur = expired_loss_eur + damaged_loss_eur`.
 *   4. **Merma térmica (`D2`, `RF-03.2`).** Con `restocker_id` seleccionado la
 *      comparativa no es segmentable: todos los campos térmicos globales y por
 *      máquina son `null`, `thermal_haccp.segmentable = false` y se publica el
 *      aviso *«No segmentable por reponedor (ámbito máquina/SAT)»*. Sin filtro
 *      individual, todas las actas M29 del periodo cuentan (`QA-2`), incluidas
 *      las de máquina vacía y los tres orígenes de cuarentena.
 *   5. **Planograma sobredimensionado (`D3`, `RF-04`).**
 *      `expiry_rate_pct = ROUND(expired / (added + expired) * 100, 2)`
 *      excluyendo dañados; la insignia exige simultáneamente `>= 15,00 %`,
 *      `>= 2` visitas completadas y `>= 3` unidades caducadas.
 *   6. **Rendimiento por reponedor (`C3`, `C4`, `RF-05`).**
 *      `machines_per_shift` es `null` con filtro territorial
 *      (`NOT_SEGMENTABLE_BY_TERRITORY`) o sin turnos válidos
 *      (`NO_VALID_SHIFTS`); `avg_stop_duration_minutes` es el promedio real de
 *      parada sin recorte de atípicos; L1 resueltas y escalados al SAT viajan en
 *      columnas separadas; la exactitud de arqueos solo mira bolsas cerradas.
 *   7. **Inclusión y orden (`QA-2`, `QA-4`).** `by_machine` incluye toda máquina
 *      con al menos una visita o un acta térmica y ordena primero por
 *      incidencia sanitaria activa, después por planograma sobredimensionado,
 *      merma comercial, carriles en rotura y código. `by_product` ordena por
 *      merma comercial, rotura y SKU; `by_restocker` por visitas y nombre. Las
 *      series Top 10 solo admiten filas con rotura o caducidad reales.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias, sin E/S.
 * Dualismo Lingüístico: código en inglés, claves del contrato en castellano.
 */
final class RestockingBiCalculator
{
    /** Motivo y aviso de la térmica no segmentable por reponedor (`D2`). */
    public const THERMAL_NON_SEGMENTABLE_REASON = 'NOT_SEGMENTABLE_BY_RESTOCKER';
    public const THERMAL_NON_SEGMENTABLE_MESSAGE = 'No segmentable por reponedor (ámbito máquina/SAT)';

    /** Motivos por los que `machines_per_shift` no se publica (`C3`). */
    public const MACHINES_PER_SHIFT_NOT_SEGMENTABLE_BY_TERRITORY = 'NOT_SEGMENTABLE_BY_TERRITORY';
    public const MACHINES_PER_SHIFT_NO_VALID_SHIFTS = 'NO_VALID_SHIFTS';

    /** Umbral determinista de planograma sobredimensionado (`D3`). */
    public const OVERSIZED_EXPIRY_RATE_THRESHOLD = 15.00;
    public const OVERSIZED_MIN_COMPLETED_VISITS = 2;
    public const OVERSIZED_MIN_EXPIRED_UNITS = 3;

    /** Tamaño de las series Top 10 de los gráficos (`D5`). */
    public const TOP_CRITICAL_LIMIT = 10;

    /**
     * Tasa porcentual redondeada a dos decimales, o `null` sin denominador.
     */
    public static function ratePct(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round($numerator * 100 / $denominator, 2);
    }

    /**
     * Complemento exacto al `100,00 %` de la disponibilidad (`D1`, `QA-C2`).
     */
    public static function complementRatePct(?float $ratePct): ?float
    {
        return $ratePct === null ? null : round(100.00 - $ratePct, 2);
    }

    /**
     * Tasa de caducidad FEFO, excluyendo dañados (`D3`, `RF-04.1`).
     */
    public static function expiryRatePct(int $expiredUnits, int $addedUnits): ?float
    {
        $denominator = $addedUnits + $expiredUnits;

        if ($denominator <= 0) {
            return null;
        }

        return round($expiredUnits * 100 / $denominator, 2);
    }

    /**
     * Insignia determinista «Planograma sobredimensionado» (`D3`, `RF-04.2`).
     */
    public static function isOversizedPlanogram(
        ?float $expiryRatePct,
        int $completedVisitsCount,
        int $expiredUnits
    ): bool {
        if ($expiryRatePct === null) {
            return false;
        }

        return $expiryRatePct >= self::OVERSIZED_EXPIRY_RATE_THRESHOLD
            && $completedVisitsCount >= self::OVERSIZED_MIN_COMPLETED_VISITS
            && $expiredUnits >= self::OVERSIZED_MIN_EXPIRED_UNITS;
    }

    /**
     * Máquinas abastecidas por turno válido (`C3`, `EARS 05.1.3`).
     *
     * @return array{machines_per_shift: float|null, machines_per_shift_na_reason: string|null}
     */
    public static function machinesPerShift(
        int $completedVisitsCount,
        int $validShiftsCount,
        bool $hasTerritorialFilter
    ): array {
        if ($hasTerritorialFilter) {
            return [
                'machines_per_shift' => null,
                'machines_per_shift_na_reason' => self::MACHINES_PER_SHIFT_NOT_SEGMENTABLE_BY_TERRITORY,
            ];
        }

        if ($validShiftsCount <= 0) {
            return [
                'machines_per_shift' => null,
                'machines_per_shift_na_reason' => self::MACHINES_PER_SHIFT_NO_VALID_SHIFTS,
            ];
        }

        return [
            'machines_per_shift' => round($completedVisitsCount / $validShiftsCount, 2),
            'machines_per_shift_na_reason' => null,
        ];
    }

    /**
     * Exactitud de arqueos sobre bolsas cerradas (`RF-05.3`).
     */
    public static function cashAccuracyRatePct(int $reconciledOkCount, int $discrepancyFlaggedCount): ?float
    {
        return self::ratePct($reconciledOkCount, $reconciledOkCount + $discrepancyFlaggedCount);
    }

    /**
     * Tiempo medio real en parada, sin recorte de atípicos (`EARS 05.1.4`).
     */
    public static function averageStopDurationMinutes(int $completedVisitsCount, float $durationSecondsSum): ?float
    {
        if ($completedVisitsCount <= 0) {
            return null;
        }

        return round($durationSecondsSum / $completedVisitsCount / 60.0, 1);
    }

    /**
     * Totales comerciales exactos al céntimo (`RF-03.1`, `QA-A4`).
     *
     * @param list<array<string, mixed>> $slotItemRows
     *
     * @return array{
     *   fefo_units: int,
     *   fefo_loss_eur: float,
     *   damaged_units: int,
     *   damaged_loss_eur: float,
     *   total_units: int,
     *   total_loss_eur: float
     * }
     */
    public static function totalCommercial(array $slotItemRows): array
    {
        $fefoUnits = 0;
        $damagedUnits = 0;
        $fefoLoss = 0.0;
        $damagedLoss = 0.0;

        foreach ($slotItemRows as $row) {
            $fefoUnits += (int)($row['expired_units'] ?? 0);
            $damagedUnits += (int)($row['damaged_units'] ?? 0);
            $fefoLoss += (float)($row['expired_loss_eur'] ?? 0.0);
            $damagedLoss += (float)($row['damaged_loss_eur'] ?? 0.0);
        }

        $fefoLoss = round($fefoLoss, 2);
        $damagedLoss = round($damagedLoss, 2);

        return [
            'fefo_units' => $fefoUnits,
            'fefo_loss_eur' => $fefoLoss,
            'damaged_units' => $damagedUnits,
            'damaged_loss_eur' => $damagedLoss,
            'total_units' => $fefoUnits + $damagedUnits,
            'total_loss_eur' => round($fefoLoss + $damagedLoss, 2),
        ];
    }

    /**
     * Totales térmicos APPCC (M29), contando todas las actas del periodo (`QA-2`).
     *
     * @param list<array<string, mixed>> $thermalRows
     *
     * @return array{records_count: int, units: int, loss_eur: float}
     */
    public static function totalThermal(array $thermalRows): array
    {
        $records = 0;
        $units = 0;
        $loss = 0.0;

        foreach ($thermalRows as $row) {
            $records += (int)($row['records_count'] ?? 0);
            $units += (int)($row['discarded_units_count'] ?? 0);
            $loss += (float)($row['estimated_merchandise_loss_eur'] ?? 0.0);
        }

        return [
            'records_count' => $records,
            'units' => $units,
            'loss_eur' => round($loss, 2),
        ];
    }

    /**
     * Ranking de máquinas con prioridad sanitaria absoluta (`QA-2`, Art. II.2).
     *
     * Cada fila del catálogo es una máquina con actividad en la ventana; el
     * repositorio ya garantiza que una máquina con solo un acta térmica también
     * llega aquí (`QA-4`).
     *
     * @param list<array{machine_id: int, machine_code?: string, machine_type?: string, location_id?: int|null, location_name?: string|null, zone_id?: int|null, zone_name?: string|null, is_active?: bool}> $machineCatalog
     * @param list<array<string, mixed>> $visitHeaders Agregados por `(machine_id, restocker_id)`.
     * @param list<array<string, mixed>> $slotItems Agregados de carriles por `(machine_id, product_id)`.
     * @param list<array<string, mixed>> $thermalRecords Agregados M29 por máquina.
     * @param bool $thermalSegmentable `false` cuando se filtra por reponedor (`D2`).
     *
     * @return list<array<string, mixed>>
     */
    public static function buildMachineRows(
        array $machineCatalog,
        array $visitHeaders,
        array $slotItems,
        array $thermalRecords,
        bool $thermalSegmentable
    ): array {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = [];

        foreach ($machineCatalog as $machine) {
            $machineId = (int)($machine['machine_id'] ?? 0);
            if ($machineId <= 0) {
                continue;
            }
            $rows[$machineId] = self::emptyMachineRow($machine, $machineId);
        }

        foreach ($visitHeaders as $header) {
            $machineId = (int)($header['machine_id'] ?? 0);
            if ($machineId <= 0) {
                continue;
            }
            $rows[$machineId] ??= self::emptyMachineRow([], $machineId);
            $rows[$machineId]['completed_visits_count'] += (int)($header['completed_visits_count'] ?? 0);
            $rows[$machineId]['clean_in_stock_visits_count'] += (int)($header['clean_in_stock_visits_count'] ?? 0);
            $rows[$machineId]['thermal_aborted_visits_count'] += (int)($header['thermal_aborted_visits_count'] ?? 0);
        }

        foreach ($slotItems as $item) {
            $machineId = (int)($item['machine_id'] ?? 0);
            if ($machineId <= 0) {
                continue;
            }
            $rows[$machineId] ??= self::emptyMachineRow([], $machineId);
            $rows[$machineId]['audited_slots_count'] += (int)($item['audited_slots_count'] ?? 0);
            $rows[$machineId]['stockout_slots_count'] += (int)($item['stockout_slots_count'] ?? 0);
            $rows[$machineId]['low_threshold_slots_count'] += (int)($item['low_threshold_slots_count'] ?? 0);
            $rows[$machineId]['added_units'] += (int)($item['added_units'] ?? 0);
            $rows[$machineId]['expired_units'] += (int)($item['expired_units'] ?? 0);
            $rows[$machineId]['damaged_units'] += (int)($item['damaged_units'] ?? 0);
            $rows[$machineId]['expired_loss_eur'] += (float)($item['expired_loss_eur'] ?? 0.0);
            $rows[$machineId]['damaged_loss_eur'] += (float)($item['damaged_loss_eur'] ?? 0.0);
        }

        foreach ($thermalRecords as $record) {
            $machineId = (int)($record['machine_id'] ?? 0);
            if ($machineId <= 0) {
                continue;
            }
            $rows[$machineId] ??= self::emptyMachineRow([], $machineId);
            $rows[$machineId]['thermal_units_raw'] += (int)($record['discarded_units_count'] ?? 0);
            $rows[$machineId]['thermal_loss_eur_raw'] += (float)($record['estimated_merchandise_loss_eur'] ?? 0.0);
        }

        $finalRows = [];
        foreach ($rows as $row) {
            $finalRows[] = self::finalizeMachineRow($row, $thermalSegmentable);
        }

        usort($finalRows, static function (array $left, array $right): int {
            return self::sanitaryPriority($right) <=> self::sanitaryPriority($left)
                ?: ((int)$right['is_oversized_planogram'] <=> (int)$left['is_oversized_planogram'])
                ?: ((float)$right['commercial_spoilage_loss_eur'] <=> (float)$left['commercial_spoilage_loss_eur'])
                ?: ((int)$right['stockout_slots_count'] <=> (int)$left['stockout_slots_count'])
                ?: strcmp((string)$left['machine_code'], (string)$right['machine_code']);
        });

        return $finalRows;
    }

    /**
     * Ranking de productos/SKU (`RF-04`, contrato §3.2).
     *
     * @param list<array{product_id: int, sku?: string, name?: string, category?: string|null, is_active?: bool}> $productCatalog
     * @param list<array<string, mixed>> $slotItems Agregados de carriles por `(product_id)`.
     *
     * @return list<array<string, mixed>>
     */
    public static function buildProductRows(array $productCatalog, array $slotItems): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = [];

        foreach ($productCatalog as $product) {
            $productId = (int)($product['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $rows[$productId] = [
                'product_id' => $productId,
                'sku' => (string)($product['sku'] ?? ''),
                'name' => (string)($product['name'] ?? ''),
                'category' => $product['category'] ?? null,
                'is_active' => (bool)($product['is_active'] ?? true),
                'audited_slots_count' => 0,
                'stockout_slots_count' => 0,
                'low_threshold_slots_count' => 0,
                'added_units' => 0,
                'expired_units' => 0,
                'damaged_units' => 0,
                'expired_loss_eur' => 0.0,
                'damaged_loss_eur' => 0.0,
            ];
        }

        foreach ($slotItems as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $rows[$productId] ??= [
                'product_id' => $productId,
                'sku' => '',
                'name' => '',
                'category' => null,
                'is_active' => true,
                'audited_slots_count' => 0,
                'stockout_slots_count' => 0,
                'low_threshold_slots_count' => 0,
                'added_units' => 0,
                'expired_units' => 0,
                'damaged_units' => 0,
                'expired_loss_eur' => 0.0,
                'damaged_loss_eur' => 0.0,
            ];

            $rows[$productId]['audited_slots_count'] += (int)($item['audited_slots_count'] ?? 0);
            $rows[$productId]['stockout_slots_count'] += (int)($item['stockout_slots_count'] ?? 0);
            $rows[$productId]['low_threshold_slots_count'] += (int)($item['low_threshold_slots_count'] ?? 0);
            $rows[$productId]['added_units'] += (int)($item['added_units'] ?? 0);
            $rows[$productId]['expired_units'] += (int)($item['expired_units'] ?? 0);
            $rows[$productId]['damaged_units'] += (int)($item['damaged_units'] ?? 0);
            $rows[$productId]['expired_loss_eur'] += (float)($item['expired_loss_eur'] ?? 0.0);
            $rows[$productId]['damaged_loss_eur'] += (float)($item['damaged_loss_eur'] ?? 0.0);
        }

        $finalRows = [];
        foreach ($rows as $row) {
            $expiredLoss = round((float)$row['expired_loss_eur'], 2);
            $damagedLoss = round((float)$row['damaged_loss_eur'], 2);

            $finalRows[] = [
                'product_id' => (int)$row['product_id'],
                'sku' => (string)$row['sku'],
                'name' => (string)$row['name'],
                'category' => $row['category'],
                'is_active' => (bool)$row['is_active'],
                'audited_slots_count' => (int)$row['audited_slots_count'],
                'stockout_slots_count' => (int)$row['stockout_slots_count'],
                'slot_stockout_rate_pct' => self::ratePct(
                    (int)$row['stockout_slots_count'],
                    (int)$row['audited_slots_count']
                ),
                'low_threshold_slots_count' => (int)$row['low_threshold_slots_count'],
                'added_units' => (int)$row['added_units'],
                'expired_units' => (int)$row['expired_units'],
                'damaged_units' => (int)$row['damaged_units'],
                'expired_loss_eur' => $expiredLoss,
                'damaged_loss_eur' => $damagedLoss,
                'commercial_spoilage_loss_eur' => round($expiredLoss + $damagedLoss, 2),
                'expiry_rate_pct' => self::expiryRatePct((int)$row['expired_units'], (int)$row['added_units']),
            ];
        }

        usort($finalRows, static function (array $left, array $right): int {
            return ((float)$right['commercial_spoilage_loss_eur'] <=> (float)$left['commercial_spoilage_loss_eur'])
                ?: ((int)$right['stockout_slots_count'] <=> (int)$left['stockout_slots_count'])
                ?: strcmp((string)$left['sku'], (string)$right['sku']);
        });

        return $finalRows;
    }

    /**
     * Rendimiento operativo por reponedor (`RF-05`, `C3`, `C4`, `QA-4`).
     *
     * Inclusión contable exacta: sin filtro territorial entra todo `RESTOCKER`
     * con visita, recaudación, L1/escalado o turno válido; con filtro
     * territorial solo quien tenga actividad territorial (los turnos no
     * cuentan, porque no son segmentables por zona).
     *
     * @param list<array{restocker_id: int, operator_code?: string|null, name?: string, is_active?: bool}> $restockerCatalog
     * @param list<array<string, mixed>> $visitHeaders Agregados por `(machine_id, restocker_id)`.
     * @param list<array<string, mixed>> $slotItems Agregados de carriles por `restocker_id` opcional.
     * @param list<array<string, mixed>> $shiftRows Turnos válidos por `technician_id`.
     * @param list<array<string, mixed>> $cashRows Arqueos M35 por `restocker_id`.
     * @param list<array<string, mixed>> $l1Rows Incidencias L1 y escalados M36 por `restocker_id`.
     * @param bool $hasTerritorialFilter `zone_id` o `location_id` activos.
     *
     * @return list<array<string, mixed>>
     */
    public static function buildRestockerRows(
        array $restockerCatalog,
        array $visitHeaders,
        array $slotItems,
        array $shiftRows,
        array $cashRows,
        array $l1Rows,
        bool $hasTerritorialFilter
    ): array {
        /** @var array<int, array{completed: int, thermal: int, seconds: float}> $visits */
        $visits = [];
        foreach ($visitHeaders as $header) {
            $restockerId = (int)($header['restocker_id'] ?? 0);
            if ($restockerId <= 0) {
                continue;
            }
            $visits[$restockerId] ??= ['completed' => 0, 'thermal' => 0, 'seconds' => 0.0];
            $visits[$restockerId]['completed'] += (int)($header['completed_visits_count'] ?? 0);
            $visits[$restockerId]['thermal'] += (int)($header['thermal_aborted_visits_count'] ?? 0);
            $visits[$restockerId]['seconds'] += (float)($header['completed_duration_seconds_sum'] ?? 0.0);
        }

        /** @var array<int, array{expired: int, damaged: int, loss: float}> $lanes */
        $lanes = [];
        foreach ($slotItems as $item) {
            $restockerId = (int)($item['restocker_id'] ?? 0);
            if ($restockerId <= 0) {
                continue;
            }
            $commercialLoss = array_key_exists('commercial_spoilage_loss_eur', $item)
                ? (float)$item['commercial_spoilage_loss_eur']
                : (float)($item['expired_loss_eur'] ?? 0.0) + (float)($item['damaged_loss_eur'] ?? 0.0);

            $lanes[$restockerId] ??= ['expired' => 0, 'damaged' => 0, 'loss' => 0.0];
            $lanes[$restockerId]['expired'] += (int)($item['expired_units'] ?? 0);
            $lanes[$restockerId]['damaged'] += (int)($item['damaged_units'] ?? 0);
            $lanes[$restockerId]['loss'] += $commercialLoss;
        }

        /** @var array<int, int> $shifts */
        $shifts = [];
        foreach ($shiftRows as $shift) {
            $restockerId = (int)($shift['technician_id'] ?? 0);
            if ($restockerId <= 0) {
                continue;
            }
            $shifts[$restockerId] = ($shifts[$restockerId] ?? 0) + (int)($shift['valid_shifts_count'] ?? 0);
        }

        /** @var array<int, array{ok: int, flagged: int, pending: int, abs_eur: float}> $cash */
        $cash = [];
        foreach ($cashRows as $row) {
            $restockerId = (int)($row['restocker_id'] ?? 0);
            if ($restockerId <= 0) {
                continue;
            }
            $cash[$restockerId] ??= ['ok' => 0, 'flagged' => 0, 'pending' => 0, 'abs_eur' => 0.0];
            $cash[$restockerId]['ok'] += (int)($row['reconciled_ok_count'] ?? 0);
            $cash[$restockerId]['flagged'] += (int)($row['discrepancy_flagged_count'] ?? 0);
            $cash[$restockerId]['pending'] += (int)($row['pending_audit_count'] ?? 0);
            $cash[$restockerId]['abs_eur'] += (float)($row['total_abs_discrepancy_eur'] ?? 0.0);
        }

        /** @var array<int, array{l1: int, escalated: int}> $l1 */
        $l1 = [];
        foreach ($l1Rows as $row) {
            $restockerId = (int)($row['restocker_id'] ?? 0);
            if ($restockerId <= 0) {
                continue;
            }
            $l1[$restockerId] ??= ['l1' => 0, 'escalated' => 0];
            $l1[$restockerId]['l1'] += (int)($row['l1_resolved_count'] ?? 0);
            $l1[$restockerId]['escalated'] += (int)($row['escalated_to_sat_count'] ?? 0);
        }

        $rows = [];
        foreach ($restockerCatalog as $restocker) {
            $restockerId = (int)($restocker['restocker_id'] ?? 0);
            if ($restockerId <= 0) {
                continue;
            }

            $visit = $visits[$restockerId] ?? ['completed' => 0, 'thermal' => 0, 'seconds' => 0.0];
            $lane = $lanes[$restockerId] ?? ['expired' => 0, 'damaged' => 0, 'loss' => 0.0];
            $cashRow = $cash[$restockerId] ?? ['ok' => 0, 'flagged' => 0, 'pending' => 0, 'abs_eur' => 0.0];
            $l1Row = $l1[$restockerId] ?? ['l1' => 0, 'escalated' => 0];
            $validShifts = $shifts[$restockerId] ?? 0;

            $hasVisitActivity = ($visit['completed'] + $visit['thermal']) > 0;
            $hasCashActivity = ($cashRow['ok'] + $cashRow['flagged'] + $cashRow['pending']) > 0;
            $hasL1Activity = ($l1Row['l1'] + $l1Row['escalated']) > 0;
            $hasTerritorialActivity = $hasVisitActivity || $hasCashActivity || $hasL1Activity;

            if (!$hasTerritorialActivity && ($hasTerritorialFilter || $validShifts <= 0)) {
                continue;
            }

            $perShift = self::machinesPerShift($visit['completed'], $validShifts, $hasTerritorialFilter);

            $rows[] = [
                'restocker_id' => $restockerId,
                'operator_code' => $restocker['operator_code'] ?? null,
                'name' => (string)($restocker['name'] ?? ''),
                'is_active' => (bool)($restocker['is_active'] ?? true),
                'completed_visits_count' => $visit['completed'],
                'thermal_aborted_visits_count' => $visit['thermal'],
                'valid_shifts_count' => $validShifts,
                'machines_per_shift' => $perShift['machines_per_shift'],
                'machines_per_shift_na_reason' => $perShift['machines_per_shift_na_reason'],
                'avg_stop_duration_minutes' => self::averageStopDurationMinutes(
                    $visit['completed'],
                    $visit['seconds']
                ),
                'l1_resolved_count' => $l1Row['l1'],
                'escalated_to_sat_count' => $l1Row['escalated'],
                'reconciled_ok_count' => $cashRow['ok'],
                'discrepancy_flagged_count' => $cashRow['flagged'],
                'pending_audit_count' => $cashRow['pending'],
                'cash_accuracy_rate_pct' => self::cashAccuracyRatePct($cashRow['ok'], $cashRow['flagged']),
                'total_abs_discrepancy_eur' => round($cashRow['abs_eur'], 2),
                'expired_units' => $lane['expired'],
                'damaged_units' => $lane['damaged'],
                'commercial_spoilage_loss_eur' => round($lane['loss'], 2),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return ((int)$right['completed_visits_count'] <=> (int)$left['completed_visits_count'])
                ?: strcmp((string)$left['name'], (string)$right['name']);
        });

        return $rows;
    }

    /**
     * KPIs globales del informe (`RF-02`, `RF-03`, `RF-05`, `C5`).
     *
     * @param list<array<string, mixed>> $machineRows
     * @param list<array<string, mixed>> $restockerRows
     * @param array{fefo_units: int, fefo_loss_eur: float, damaged_units: int, damaged_loss_eur: float, total_units: int, total_loss_eur: float} $commercialTotals
     * @param array{records_count: int, units: int, loss_eur: float} $thermalTotals
     * @param bool $thermalSegmentable
     *
     * @return array<string, int|float|null>
     */
    public static function buildSummary(
        array $machineRows,
        array $restockerRows,
        array $commercialTotals,
        array $thermalTotals,
        bool $thermalSegmentable
    ): array {
        $completed = 0;
        $clean = 0;
        $thermalAborted = 0;
        $audited = 0;
        $stockout = 0;
        $lowThreshold = 0;
        $oversized = 0;

        foreach ($machineRows as $row) {
            $completed += (int)$row['completed_visits_count'];
            $clean += (int)$row['clean_in_stock_visits_count'];
            $thermalAborted += (int)$row['thermal_aborted_visits_count'];
            $audited += (int)$row['audited_slots_count'];
            $stockout += (int)$row['stockout_slots_count'];
            $lowThreshold += (int)$row['low_threshold_slots_count'];

            if (($row['is_oversized_planogram'] ?? false) === true) {
                $oversized++;
            }
        }

        $cashOk = 0;
        $cashFlagged = 0;
        $cashPending = 0;
        $l1Resolved = 0;
        $escalated = 0;

        foreach ($restockerRows as $row) {
            $cashOk += (int)$row['reconciled_ok_count'];
            $cashFlagged += (int)$row['discrepancy_flagged_count'];
            $cashPending += (int)$row['pending_audit_count'];
            $l1Resolved += (int)$row['l1_resolved_count'];
            $escalated += (int)$row['escalated_to_sat_count'];
        }

        $inStockRate = self::ratePct($clean, $completed);

        return [
            'completed_visits_count' => $completed,
            'clean_in_stock_visits_count' => $clean,
            'stockout_visits_count' => $completed - $clean,
            'visit_in_stock_rate_pct' => $inStockRate,
            'visit_stockout_rate_pct' => self::complementRatePct($inStockRate),
            'thermal_aborted_visits_count' => $thermalAborted,
            'audited_slots_count' => $audited,
            'stockout_slots_count' => $stockout,
            'slot_stockout_rate_pct' => self::ratePct($stockout, $audited),
            'low_threshold_slots_count' => $lowThreshold,
            'oversized_machines_count' => $oversized,
            'commercial_spoilage_eur' => $commercialTotals['total_loss_eur'],
            'commercial_spoilage_units' => $commercialTotals['total_units'],
            'thermal_spoilage_eur' => $thermalSegmentable ? $thermalTotals['loss_eur'] : null,
            'thermal_spoilage_units' => $thermalSegmentable ? $thermalTotals['units'] : null,
            'total_spoilage_eur' => $thermalSegmentable
                ? round((float)$commercialTotals['total_loss_eur'] + (float)$thermalTotals['loss_eur'], 2)
                : null,
            'cash_reconciled_ok_count' => $cashOk,
            'cash_discrepancy_flagged_count' => $cashFlagged,
            'cash_reconciled_count' => $cashOk + $cashFlagged,
            'cash_pending_audit_count' => $cashPending,
            'cash_accuracy_rate_pct' => self::cashAccuracyRatePct($cashOk, $cashFlagged),
            'l1_resolved_count' => $l1Resolved,
            'escalated_to_sat_count' => $escalated,
        ];
    }

    /**
     * Comparativa de mermas con bloqueo térmico por reponedor (`D2`, `RF-03.2`).
     *
     * @param array{fefo_units: int, fefo_loss_eur: float, damaged_units: int, damaged_loss_eur: float, total_units: int, total_loss_eur: float} $commercialTotals
     * @param array{records_count: int, units: int, loss_eur: float} $thermalTotals
     *
     * @return array<string, mixed>
     */
    public static function buildSpoilageComparison(
        array $commercialTotals,
        array $thermalTotals,
        bool $thermalSegmentable
    ): array {
        return [
            'commercial_fefo_expired' => [
                'units' => $commercialTotals['fefo_units'],
                'loss_eur' => $commercialTotals['fefo_loss_eur'],
            ],
            'commercial_damaged' => [
                'units' => $commercialTotals['damaged_units'],
                'loss_eur' => $commercialTotals['damaged_loss_eur'],
            ],
            'commercial_total' => [
                'units' => $commercialTotals['total_units'],
                'loss_eur' => $commercialTotals['total_loss_eur'],
            ],
            'thermal_haccp' => [
                'segmentable' => $thermalSegmentable,
                'non_segmentable_reason' => $thermalSegmentable ? null : self::THERMAL_NON_SEGMENTABLE_REASON,
                'non_segmentable_message' => $thermalSegmentable ? null : self::THERMAL_NON_SEGMENTABLE_MESSAGE,
                'records_count' => $thermalSegmentable ? $thermalTotals['records_count'] : null,
                'units' => $thermalSegmentable ? $thermalTotals['units'] : null,
                'loss_eur' => $thermalSegmentable ? $thermalTotals['loss_eur'] : null,
            ],
        ];
    }

    /**
     * Series de los dos gráficos Chart.js, ya ordenadas y recortadas (`D5`).
     *
     * @param list<array<string, mixed>> $machineRows
     * @param list<array<string, mixed>> $productRows
     * @param array<string, mixed> $spoilageComparison
     *
     * @return array<string, mixed>
     */
    public static function buildCharts(
        array $machineRows,
        array $productRows,
        array $spoilageComparison,
        bool $thermalSegmentable
    ): array {
        $fefo = $spoilageComparison['commercial_fefo_expired'];
        $damaged = $spoilageComparison['commercial_damaged'];
        $thermal = $spoilageComparison['thermal_haccp'];

        $labels = ['Caducidad FEFO', 'Dañado en ruta'];
        $keys = ['commercial_fefo_expired', 'commercial_damaged'];
        $eurValues = [(float)$fefo['loss_eur'], (float)$damaged['loss_eur']];
        $unitValues = [(int)$fefo['units'], (int)$damaged['units']];

        if ($thermalSegmentable) {
            $labels[] = 'Avería Térmica APPCC';
            $keys[] = 'thermal_haccp';
            $eurValues[] = (float)($thermal['loss_eur'] ?? 0.0);
            $unitValues[] = (int)($thermal['units'] ?? 0);
        }

        return [
            'spoilage_bars' => [
                'labels' => $labels,
                'keys' => $keys,
                'eur_values' => $eurValues,
                'unit_values' => $unitValues,
                'thermal_segmentable' => $thermalSegmentable,
            ],
            'top_critical_stockout' => [
                'by_machine' => self::topStockoutMachines($machineRows),
                'by_product' => self::topStockoutProducts($productRows),
            ],
            'top_critical_expiry' => [
                'by_machine' => self::topExpiryMachines($machineRows),
                'by_product' => self::topExpiryProducts($productRows),
            ],
        ];
    }

    /**
     * Prioridad sanitaria de una fila de máquina (`QA-2`, Art. II.2).
     *
     * @param array<string, mixed> $row
     */
    private static function sanitaryPriority(array $row): int
    {
        $thermalUnits = $row['thermal_spoilage_units'] ?? 0;

        return ((int)$row['thermal_aborted_visits_count'] > 0 || (int)$thermalUnits > 0) ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $machine
     *
     * @return array<string, mixed>
     */
    private static function emptyMachineRow(array $machine, int $machineId): array
    {
        return [
            'machine_id' => $machineId,
            'machine_code' => (string)($machine['machine_code'] ?? ''),
            'machine_type' => (string)($machine['machine_type'] ?? ''),
            'location_id' => isset($machine['location_id']) ? (int)$machine['location_id'] : null,
            'location_name' => $machine['location_name'] ?? null,
            'zone_id' => isset($machine['zone_id']) ? (int)$machine['zone_id'] : null,
            'zone_name' => $machine['zone_name'] ?? null,
            'is_active' => (bool)($machine['is_active'] ?? true),
            'completed_visits_count' => 0,
            'clean_in_stock_visits_count' => 0,
            'thermal_aborted_visits_count' => 0,
            'audited_slots_count' => 0,
            'stockout_slots_count' => 0,
            'low_threshold_slots_count' => 0,
            'added_units' => 0,
            'expired_units' => 0,
            'damaged_units' => 0,
            'expired_loss_eur' => 0.0,
            'damaged_loss_eur' => 0.0,
            'thermal_units_raw' => 0,
            'thermal_loss_eur_raw' => 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function finalizeMachineRow(array $row, bool $thermalSegmentable): array
    {
        $completed = (int)$row['completed_visits_count'];
        $expiredUnits = (int)$row['expired_units'];
        $expiredLoss = round((float)$row['expired_loss_eur'], 2);
        $damagedLoss = round((float)$row['damaged_loss_eur'], 2);
        $expiryRate = self::expiryRatePct($expiredUnits, (int)$row['added_units']);
        $inStockRate = self::ratePct((int)$row['clean_in_stock_visits_count'], $completed);

        return [
            'machine_id' => (int)$row['machine_id'],
            'machine_code' => (string)$row['machine_code'],
            'machine_type' => (string)$row['machine_type'],
            'location_id' => $row['location_id'],
            'location_name' => $row['location_name'],
            'zone_id' => $row['zone_id'],
            'zone_name' => $row['zone_name'],
            'is_active' => (bool)$row['is_active'],
            'completed_visits_count' => $completed,
            'clean_in_stock_visits_count' => (int)$row['clean_in_stock_visits_count'],
            'visit_in_stock_rate_pct' => $inStockRate,
            'thermal_aborted_visits_count' => (int)$row['thermal_aborted_visits_count'],
            'audited_slots_count' => (int)$row['audited_slots_count'],
            'stockout_slots_count' => (int)$row['stockout_slots_count'],
            'slot_stockout_rate_pct' => self::ratePct(
                (int)$row['stockout_slots_count'],
                (int)$row['audited_slots_count']
            ),
            'low_threshold_slots_count' => (int)$row['low_threshold_slots_count'],
            'added_units' => (int)$row['added_units'],
            'expired_units' => $expiredUnits,
            'damaged_units' => (int)$row['damaged_units'],
            'expired_loss_eur' => $expiredLoss,
            'damaged_loss_eur' => $damagedLoss,
            'commercial_spoilage_loss_eur' => round($expiredLoss + $damagedLoss, 2),
            'expiry_rate_pct' => $expiryRate,
            'is_oversized_planogram' => self::isOversizedPlanogram($expiryRate, $completed, $expiredUnits),
            'thermal_spoilage_units' => $thermalSegmentable ? (int)$row['thermal_units_raw'] : null,
            'thermal_spoilage_loss_eur' => $thermalSegmentable
                ? round((float)$row['thermal_loss_eur_raw'], 2)
                : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $machineRows
     *
     * @return list<array<string, mixed>>
     */
    private static function topStockoutMachines(array $machineRows): array
    {
        $candidates = array_values(array_filter(
            $machineRows,
            static fn(array $row): bool => (int)$row['stockout_slots_count'] > 0
        ));

        usort($candidates, static function (array $left, array $right): int {
            return ((float)($right['slot_stockout_rate_pct'] ?? 0.0) <=> (float)($left['slot_stockout_rate_pct'] ?? 0.0))
                ?: ((int)$right['stockout_slots_count'] <=> (int)$left['stockout_slots_count'])
                ?: strcmp((string)$left['machine_code'], (string)$right['machine_code']);
        });

        $series = [];
        foreach (array_slice($candidates, 0, self::TOP_CRITICAL_LIMIT) as $row) {
            $series[] = [
                'id' => (int)$row['machine_id'],
                'code' => (string)$row['machine_code'],
                'label' => self::machineLabel($row),
                'is_active' => (bool)$row['is_active'],
                'stockout_slots_count' => (int)$row['stockout_slots_count'],
                'audited_slots_count' => (int)$row['audited_slots_count'],
                'slot_stockout_rate_pct' => $row['slot_stockout_rate_pct'],
            ];
        }

        return $series;
    }

    /**
     * @param list<array<string, mixed>> $productRows
     *
     * @return list<array<string, mixed>>
     */
    private static function topStockoutProducts(array $productRows): array
    {
        $candidates = array_values(array_filter(
            $productRows,
            static fn(array $row): bool => (int)$row['stockout_slots_count'] > 0
        ));

        usort($candidates, static function (array $left, array $right): int {
            return ((float)($right['slot_stockout_rate_pct'] ?? 0.0) <=> (float)($left['slot_stockout_rate_pct'] ?? 0.0))
                ?: ((int)$right['stockout_slots_count'] <=> (int)$left['stockout_slots_count'])
                ?: strcmp((string)$left['sku'], (string)$right['sku']);
        });

        $series = [];
        foreach (array_slice($candidates, 0, self::TOP_CRITICAL_LIMIT) as $row) {
            $series[] = [
                'id' => (int)$row['product_id'],
                'code' => (string)$row['sku'],
                'label' => self::productLabel($row),
                'is_active' => (bool)$row['is_active'],
                'stockout_slots_count' => (int)$row['stockout_slots_count'],
                'audited_slots_count' => (int)$row['audited_slots_count'],
                'slot_stockout_rate_pct' => $row['slot_stockout_rate_pct'],
            ];
        }

        return $series;
    }

    /**
     * @param list<array<string, mixed>> $machineRows
     *
     * @return list<array<string, mixed>>
     */
    private static function topExpiryMachines(array $machineRows): array
    {
        $candidates = array_values(array_filter(
            $machineRows,
            static fn(array $row): bool => (int)$row['expired_units'] > 0
        ));

        usort($candidates, static function (array $left, array $right): int {
            return ((float)($right['expiry_rate_pct'] ?? 0.0) <=> (float)($left['expiry_rate_pct'] ?? 0.0))
                ?: ((int)$right['expired_units'] <=> (int)$left['expired_units'])
                ?: strcmp((string)$left['machine_code'], (string)$right['machine_code']);
        });

        $series = [];
        foreach (array_slice($candidates, 0, self::TOP_CRITICAL_LIMIT) as $row) {
            $series[] = [
                'id' => (int)$row['machine_id'],
                'code' => (string)$row['machine_code'],
                'label' => self::machineLabel($row),
                'is_active' => (bool)$row['is_active'],
                'expired_units' => (int)$row['expired_units'],
                'added_units' => (int)$row['added_units'],
                'expiry_rate_pct' => $row['expiry_rate_pct'],
                'is_oversized_planogram' => (bool)$row['is_oversized_planogram'],
            ];
        }

        return $series;
    }

    /**
     * @param list<array<string, mixed>> $productRows
     *
     * @return list<array<string, mixed>>
     */
    private static function topExpiryProducts(array $productRows): array
    {
        $candidates = array_values(array_filter(
            $productRows,
            static fn(array $row): bool => (int)$row['expired_units'] > 0
        ));

        usort($candidates, static function (array $left, array $right): int {
            return ((float)($right['expiry_rate_pct'] ?? 0.0) <=> (float)($left['expiry_rate_pct'] ?? 0.0))
                ?: ((int)$right['expired_units'] <=> (int)$left['expired_units'])
                ?: strcmp((string)$left['sku'], (string)$right['sku']);
        });

        $series = [];
        foreach (array_slice($candidates, 0, self::TOP_CRITICAL_LIMIT) as $row) {
            $series[] = [
                'id' => (int)$row['product_id'],
                'code' => (string)$row['sku'],
                'label' => self::productLabel($row),
                'is_active' => (bool)$row['is_active'],
                'expired_units' => (int)$row['expired_units'],
                'added_units' => (int)$row['added_units'],
                'expiry_rate_pct' => $row['expiry_rate_pct'],
            ];
        }

        return $series;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function machineLabel(array $row): string
    {
        $code = (string)$row['machine_code'];
        $location = $row['location_name'] ?? null;

        return is_string($location) && $location !== '' ? $code . ' · ' . $location : $code;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function productLabel(array $row): string
    {
        $sku = (string)$row['sku'];
        $name = (string)($row['name'] ?? '');

        return $name !== '' ? $sku . ' · ' . $name : $sku;
    }
}
