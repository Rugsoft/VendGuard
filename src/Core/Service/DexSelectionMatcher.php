<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Enum\DexSelectionMatchStatus;
use VendGuard\Core\Domain\Exception\DexAuditValidationException;

/**
 * DexSelectionMatcher
 *
 * Emparejamiento determinista de las líneas `PA1`/`PA2` con los carriles del
 * planograma (Módulo 39, RF-04.2, C4, QA-3, Art. I.3).
 *
 * **Modo `HOT_DRINKS`.** La máquina de café no tiene espirales: todas las
 * selecciones se registran íntegras como `HOT_DRINKS_SELECTION`, sin carril, sin
 * snapshot y sin contarse como `UNMAPPED` (ni, por tanto, como alerta de
 * discrepancia de carril).
 *
 * **Dos pasos en el resto de tipologías.** Frente a los carriles activos:
 *
 *   1. Coincidencia exacta del código normalizado en mayúsculas con
 *      `slot_code` → `EXACT_MATCH`.
 *   2. Solo si no hubo exacta, se recortan los ceros a la izquierda (un código
 *      todo ceros se reduce a `'0'`) y se busca un único carril activo. Si hay
 *      uno → `LEADING_ZERO_MATCH`; si hay varios → `AMBIGUOUS`; si no hay
 *      ninguno → `UNMAPPED`.
 *
 * **Colisión dentro del volcado.** Si dos líneas del mismo `.dex` resuelven al
 * mismo `slot_id` —dos ceros a la izquierda sobre el mismo carril, o una exacta
 * y otra con ceros sobre ese mismo carril—, ambas se degradan a `AMBIGUOUS` y
 * pierden el carril: el fichero no permite decidir cuál es cuál, y el sistema no
 * inventa una correspondencia (C4).
 *
 * El matcher no consulta la base ni conoce el historial: recibe los carriles
 * activos y las selecciones ya parseadas, y devuelve una copia enriquecida. La
 * lectura de carriles y la escritura son del repositorio.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class DexSelectionMatcher
{
    /** Tipología de café: selecciones informativas, sin carril (C4). */
    public const MACHINE_TYPE_HOT_DRINKS = 'HOT_DRINKS';

    /**
     * Empareja las selecciones contra los carriles activos.
     *
     * @param string $machineType Tipología de la máquina (`machines.machine_type`).
     * @param list<array{id:int, slot_code:string, is_active?:bool}> $activeSlots
     *        Carriles candidatos. Los que traigan `is_active = false` se ignoran.
     * @param list<array<string, mixed>> $selections Salida de `EvaDtsParser`.
     *
     * @return list<array<string, mixed>> Selecciones con `matched_slot_id`,
     *         `matched_slot_code_snapshot` y `match_status` (`DexSelectionMatchStatus`).
     *
     * @throws DexAuditValidationException Si una selección o un carril no tienen forma válida.
     */
    public function match(string $machineType, array $activeSlots, array $selections): array
    {
        $type = strtoupper(trim($machineType));
        $prepared = [];
        foreach ($selections as $index => $selection) {
            if (!is_array($selection)) {
                throw new DexAuditValidationException(
                    'Cada selección del volcado debe ser un array asociativo.',
                    'DEX_INVALID_SELECTION_LINE',
                    ['index' => $index]
                );
            }

            $code = strtoupper(trim((string)($selection['normalized_selection_code'] ?? '')));
            if ($code === '' || mb_strlen($code, 'UTF-8') > 24) {
                throw new DexAuditValidationException(
                    'La selección no trae un código normalizado válido de 1 a 24 caracteres.',
                    'DEX_INVALID_SELECTION_LINE',
                    ['index' => $index, 'code' => $code]
                );
            }

            $prepared[] = $selection + [
                'normalized_selection_code' => $code,
                'matched_slot_id' => null,
                'matched_slot_code_snapshot' => null,
                'match_status' => DexSelectionMatchStatus::UNMAPPED,
            ];
        }

        if ($type === self::MACHINE_TYPE_HOT_DRINKS) {
            foreach ($prepared as $index => $selection) {
                $prepared[$index]['matched_slot_id'] = null;
                $prepared[$index]['matched_slot_code_snapshot'] = null;
                $prepared[$index]['match_status'] = DexSelectionMatchStatus::HOT_DRINKS_SELECTION;
            }

            return $prepared;
        }

        [$byExactCode, $byStrippedCode] = $this->indexSlots($activeSlots);

        foreach ($prepared as $index => $selection) {
            $code = $selection['normalized_selection_code'];
            $exact = $byExactCode[$code] ?? [];
            if ($exact !== []) {
                if (count($exact) === 1) {
                    $prepared[$index]['match_status'] = DexSelectionMatchStatus::EXACT_MATCH;
                    $prepared[$index]['matched_slot_id'] = $exact[0]['id'];
                    $prepared[$index]['matched_slot_code_snapshot'] = $exact[0]['slot_code'];
                } else {
                    $prepared[$index]['match_status'] = DexSelectionMatchStatus::AMBIGUOUS;
                }
                continue;
            }

            $candidates = $byStrippedCode[self::stripLeadingZeros($code)] ?? [];
            if (count($candidates) === 1) {
                $prepared[$index]['match_status'] = DexSelectionMatchStatus::LEADING_ZERO_MATCH;
                $prepared[$index]['matched_slot_id'] = $candidates[0]['id'];
                $prepared[$index]['matched_slot_code_snapshot'] = $candidates[0]['slot_code'];
            } elseif (count($candidates) > 1) {
                $prepared[$index]['match_status'] = DexSelectionMatchStatus::AMBIGUOUS;
            }
        }

        return $this->degradeCollisions($prepared);
    }

    /**
     * @param list<array{id:int, slot_code:string, is_active?:bool}> $slots
     *
     * @return array{0: array<string, list<array{id:int, slot_code:string}>>, 1: array<string, list<array{id:int, slot_code:string}>>}
     */
    private function indexSlots(array $slots): array
    {
        $byExactCode = [];
        $byStrippedCode = [];

        foreach ($slots as $index => $slot) {
            if (!is_array($slot)) {
                throw new DexAuditValidationException(
                    'Cada carril del planograma debe ser un array asociativo.',
                    'DEX_INVALID_PLANOGRAM_SLOT',
                    ['index' => $index]
                );
            }

            if (($slot['is_active'] ?? true) === false) {
                continue;
            }

            $id = (int)($slot['id'] ?? 0);
            $rawCode = strtoupper(trim((string)($slot['slot_code'] ?? '')));
            if ($id <= 0 || $rawCode === '' || mb_strlen($rawCode, 'UTF-8') > 24) {
                throw new DexAuditValidationException(
                    'El carril del planograma no trae identificador positivo o código válido de 1 a 24 caracteres.',
                    'DEX_INVALID_PLANOGRAM_SLOT',
                    ['index' => $index, 'slot_id' => $id]
                );
            }

            $entry = ['id' => $id, 'slot_code' => $rawCode];
            $byExactCode[$rawCode][] = $entry;
            $byStrippedCode[self::stripLeadingZeros($rawCode)][] = $entry;
        }

        return [$byExactCode, $byStrippedCode];
    }

    /**
     * Si dos selecciones provisionales apuntan al mismo carril, ambas pierden la
     * correspondencia: no hay forma determinista de decidir cuál es cuál (C4).
     *
     * @param list<array<string, mixed>> $selections
     *
     * @return list<array<string, mixed>>
     */
    private function degradeCollisions(array $selections): array
    {
        $bySlot = [];
        foreach ($selections as $index => $selection) {
            $slotId = $selection['matched_slot_id'] ?? null;
            if (is_int($slotId) && $slotId > 0) {
                $bySlot[$slotId][] = $index;
            }
        }

        foreach ($bySlot as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            foreach ($indexes as $index) {
                $selections[$index]['matched_slot_id'] = null;
                $selections[$index]['matched_slot_code_snapshot'] = null;
                $selections[$index]['match_status'] = DexSelectionMatchStatus::AMBIGUOUS;
            }
        }

        return array_values($selections);
    }

    /**
     * Recorta ceros a la izquierda preservando `'0'` cuando todo son ceros.
     */
    private static function stripLeadingZeros(string $code): string
    {
        $stripped = ltrim($code, '0');

        return $stripped === '' ? '0' : $stripped;
    }
}
