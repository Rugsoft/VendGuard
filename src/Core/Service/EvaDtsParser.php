<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Exception\DexAuditValidationException;

/**
 * EvaDtsParser
 *
 * Parser determinista de ficheros EVA-DTS `.dex` (Módulo 39, RF-02, RF-05.1,
 * D3, D4, C3, QA-4, QA-5, Art. I.3, Art. IV y Art. V.5).
 *
 * QUÉ GARANTIZA
 *
 *   1. **Seguridad del texto (QA-5).** Rechaza el payload vacío, el que supera
 *      1 048 576 bytes, los bytes nulos y los caracteres de control peligrosos
 *      (`0x00..0x08`, `0x0B..0x0C`, `0x0E..0x1F`, `0x7F`). Si el contenido no es
 *      UTF-8 válido, lo convierte de forma determinista desde Windows-1252 /
 *      ISO-8859-1 antes de normalizar y calcular la huella.
 *
 *   2. **Envoltorio y cabeceras.** Normaliza `\r\n` y `\r` a `\n`, recorta los
 *      espacios finales de cada línea, descarta las líneas en blanco extremas y
 *      exige que la primera línea no vacía empiece por `DXS*` y la última por
 *      `DXE*`. Un `VA1*` es obligatorio y `VA1`/`CA3`/`ID4` no pueden repetirse.
 *      Un `PA101` normalizado repetido dentro del mismo fichero se rechaza
 *      (`QA-4`).
 *
 *   3. **Lectura tolerante de firmware.** `ID4` fija la escala decimal (0..4,
 *      defecto 2) y todo importe entero se divide por `10^ID4`; también se admite
 *      un decimal explícito con `.`. `ID1` aporta el identificador de máquina
 *      (con `ID106` como respaldo) y, si se recibe el código esperado de
 *      `machines.code`, el parser marca `identity_mismatch_warning` sin bloquear.
 *      `CA3` es opcional; `PA1` se empareja con su `PA2` consecutivo y
 *      `EA1`/`EA2` aportan código, fecha cruda y contadores `EA202`/`EA203`/
 *      `EA205`. Los segmentos propietarios o desconocidos se omiten de forma
 *      segura y se cuentan en `ignored_unknown_lines_count` (Art. I.3).
 *
 *   4. **Huella canónica y original intacto.** `sha256_hash` se calcula sobre el
 *      texto ya convertido a UTF-8 y normalizado, sin espacios finales por línea:
 *      dos ficheros con los mismos segmentos y distintos saltos de línea producen
 *      la misma huella. El texto recibido, ya decodificado a UTF-8 pero sin
 *      normalizar, se devuelve en `raw_payload_received` para que la descarga de
 *      Coordinación sirva el `.dex` tal como llegó (C3, H25-04).
 *
 * El parser **no** decide el emparejamiento con el planograma, la cadena de
 * deltas, el perímetro del actor ni la primacía sanitaria: devuelve los hechos
 * del fichero y esas reglas viven en `DexSelectionMatcher`, `DexAuditValidator`
 * y el servicio de importación. Tampoco crea entidades: entrega un array
 * estructurado y estable que el servicio proyecta a `MachineDexAudit` y sus
 * líneas.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class EvaDtsParser
{
    /** Tope duro del fichero (1 MB, QA-5). */
    public const MAX_BYTES = 1048576;

    /** Escala decimal por defecto cuando el `.dex` no declara `ID4` (RF-02.2). */
    public const DEFAULT_ID4_DECIMAL_PLACES = 2;

    /** Caracteres de control prohibidos, salvo tabulador y saltos de línea (QA-5). */
    private const FORBIDDEN_CONTROLS_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    /** Cotas del contrato de esquema. */
    private const MAX_MONEY_EUR = 9999999999.99;
    private const MAX_UNITS = 999999999;

    /**
     * Parsea un volcado `.dex`.
     *
     * @param string $rawPayload Contenido crudo recibido (multipart o JSON Offline).
     * @param string|null $expectedMachineCode `machines.code` de la máquina destino, si se conoce.
     *
     * @return array{
     *     raw_payload_received: string,
     *     raw_payload_normalized: string,
     *     sha256_hash: string,
     *     byte_size: int,
     *     dxs_comm_id: string|null,
     *     dxs_version: string|null,
     *     dex_machine_identifier: string|null,
     *     identity_mismatch_warning: bool,
     *     id4_decimal_places: int,
     *     ignored_unknown_lines_count: int,
     *     va1_total_sales_eur: float,
     *     va1_total_vend_units: int,
     *     va1_reset_sales_eur: float|null,
     *     va1_reset_vend_units: int|null,
     *     ca3_cashbox_eur: float|null,
     *     ca3_coin_tubes_eur: float|null,
     *     ca3_bills_in_eur: float|null,
     *     selections: list<array<string, mixed>>,
     *     events: list<array<string, mixed>>
     * }
     *
     * @throws DexAuditValidationException Si el fichero no cumple el contrato.
     */
    public function parse(string $rawPayload, ?string $expectedMachineCode = null): array
    {
        $byteSize = strlen($rawPayload);

        if ($byteSize === 0) {
            throw new DexAuditValidationException(
                'El fichero .dex está vacío: no hay envoltorio DXS/DXE ni segmentos que leer.',
                'DEX_INVALID_TEXT_PAYLOAD',
                ['bytes' => 0]
            );
        }

        if ($byteSize > self::MAX_BYTES) {
            throw new DexAuditValidationException(
                sprintf('El fichero .dex supera el tope de 1 MB (%d bytes > %d).', $byteSize, self::MAX_BYTES),
                'DEX_FILE_TOO_LARGE',
                ['bytes' => $byteSize, 'max_bytes' => self::MAX_BYTES]
            );
        }

        if (preg_match(self::FORBIDDEN_CONTROLS_PATTERN, $rawPayload, $matches, PREG_OFFSET_CAPTURE) === 1) {
            throw new DexAuditValidationException(
                'El fichero .dex contiene bytes nulos o caracteres de control peligrosos.',
                'DEX_INVALID_TEXT_PAYLOAD',
                ['offset' => $matches[0][1], 'byte' => bin2hex($matches[0][0])]
            );
        }

        $text = $this->toUtf8($rawPayload);
        $lines = $this->normalizeLines($text);

        if (count($lines) < 2) {
            throw new DexAuditValidationException(
                'El .dex debe contener al menos la apertura DXS* y el cierre DXE*.',
                'DEX_INVALID_ENVELOPE',
                ['lines' => count($lines)]
            );
        }

        $lastIndex = count($lines) - 1;
        if (!str_starts_with($lines[0], 'DXS*') || !str_starts_with($lines[$lastIndex], 'DXE*')) {
            throw new DexAuditValidationException(
                'El envoltorio EVA-DTS es inválido: la primera línea debe empezar por DXS* y la última por DXE*.',
                'DEX_INVALID_ENVELOPE',
                ['first' => $this->preview($lines[0]), 'last' => $this->preview($lines[$lastIndex])]
            );
        }

        $canonical = implode("\n", $lines);

        $dxsCommId = null;
        $dxsVersion = null;
        $dexMachineIdentifier = null;
        $id4DecimalPlaces = self::DEFAULT_ID4_DECIMAL_PLACES;
        $ignoredUnknownLines = 0;
        $va1Count = 0;
        $ca3Count = 0;
        $id4Count = 0;
        $va1 = null;
        $ca3 = null;
        $selections = [];
        $events = [];
        $selectionSequence = 0;
        $eventSequence = 0;
        $seenSelectionCodes = [];

        $dxsFields = explode('*', $lines[0]);
        $dxsCommId = $this->optionalText($dxsFields[1] ?? null, 64);
        $dxsVersion = $this->optionalText($dxsFields[3] ?? null, 32);

        for ($index = 1; $index < $lastIndex; $index++) {
            $line = $lines[$index];
            $lineNumber = $index + 1;

            if (trim($line) === '') {
                continue;
            }

            if (str_starts_with($line, 'DXS*') || str_starts_with($line, 'DXE*')) {
                $ignoredUnknownLines++;
                continue;
            }

            if (str_starts_with($line, 'ID4*')) {
                $id4Count++;
                if ($id4Count > 1) {
                    throw new DexAuditValidationException(
                        'El segmento ID4 aparece más de una vez en el mismo .dex.',
                        'DEX_DUPLICATE_HEADER_SEGMENT',
                        ['segment' => 'ID4', 'line' => $lineNumber]
                    );
                }
                $id4DecimalPlaces = $this->parseId4($line, $lineNumber);
                continue;
            }

            if (str_starts_with($line, 'ID1*')) {
                $fields = explode('*', $line);
                $candidate = trim($fields[1] ?? '');
                if ($candidate === '') {
                    $candidate = trim($fields[6] ?? '');
                }
                $dexMachineIdentifier = $this->optionalText($candidate, 64);
                continue;
            }

            if (str_starts_with($line, 'VA1*')) {
                $va1Count++;
                if ($va1Count > 1) {
                    throw new DexAuditValidationException(
                        'El segmento VA1 aparece más de una vez en el mismo .dex.',
                        'DEX_DUPLICATE_HEADER_SEGMENT',
                        ['segment' => 'VA1', 'line' => $lineNumber]
                    );
                }
                $va1 = $this->parseVa1($line, $lineNumber, $id4DecimalPlaces);
                continue;
            }

            if (str_starts_with($line, 'CA3*')) {
                $ca3Count++;
                if ($ca3Count > 1) {
                    throw new DexAuditValidationException(
                        'El segmento CA3 aparece más de una vez en el mismo .dex.',
                        'DEX_DUPLICATE_HEADER_SEGMENT',
                        ['segment' => 'CA3', 'line' => $lineNumber]
                    );
                }
                $ca3 = $this->parseCa3($line, $lineNumber, $id4DecimalPlaces);
                continue;
            }

            if (str_starts_with($line, 'PA1*')) {
                $fields = explode('*', $line);
                $rawCode = trim($fields[1] ?? '');

                $nextIndex = $index + 1;
                $pairedFields = null;
                if ($nextIndex < $lastIndex && str_starts_with($lines[$nextIndex], 'PA2*')) {
                    $pairedFields = explode('*', $lines[$nextIndex]);
                    $index = $nextIndex;
                }

                if ($rawCode === '' || $pairedFields === null) {
                    $ignoredUnknownLines++;
                    continue;
                }

                $normalizedCode = strtoupper($rawCode);
                if (isset($seenSelectionCodes[$normalizedCode])) {
                    throw new DexAuditValidationException(
                        sprintf('El código de selección %s aparece repetido en el mismo .dex.', $normalizedCode),
                        'DEX_DUPLICATE_SELECTION_CODE',
                        ['selection_code' => $normalizedCode, 'line' => $lineNumber]
                    );
                }
                $seenSelectionCodes[$normalizedCode] = true;

                $selections[] = [
                    'line_sequence' => ++$selectionSequence,
                    'raw_selection_code' => $this->assertCodeLength($rawCode, 32, 'raw_selection_code', $lineNumber),
                    'normalized_selection_code' => $this->assertCodeLength($normalizedCode, 24, 'normalized_selection_code', $lineNumber),
                    'unit_price_eur' => $this->optionalMoney($fields[2] ?? null, $id4DecimalPlaces, 'PA102', $lineNumber),
                    'total_vend_units' => $this->requiredUnits($pairedFields[1] ?? null, 'PA201', $lineNumber),
                    'total_sales_eur' => $this->requiredMoney($pairedFields[2] ?? null, $id4DecimalPlaces, 'PA202', $lineNumber),
                    'reset_vend_units' => $this->optionalUnits($pairedFields[3] ?? null, 'PA203', $lineNumber),
                    'reset_sales_eur' => $this->optionalMoney($pairedFields[4] ?? null, $id4DecimalPlaces, 'PA204', $lineNumber),
                ];
                continue;
            }

            if (str_starts_with($line, 'EA1*') || str_starts_with($line, 'EA2*')) {
                $events[] = $this->parseEvent($line, $lineNumber, ++$eventSequence);
                continue;
            }

            $ignoredUnknownLines++;
        }

        if ($va1 === null) {
            throw new DexAuditValidationException(
                'El .dex no contiene el segmento obligatorio VA1.',
                'DEX_MISSING_VA1_SEGMENT'
            );
        }

        $identityMismatch = $this->detectIdentityMismatch($dexMachineIdentifier, $expectedMachineCode);

        return [
            'raw_payload_received' => $text,
            'raw_payload_normalized' => $canonical,
            'sha256_hash' => hash('sha256', $canonical),
            'byte_size' => $byteSize,
            'dxs_comm_id' => $dxsCommId,
            'dxs_version' => $dxsVersion,
            'dex_machine_identifier' => $dexMachineIdentifier,
            'identity_mismatch_warning' => $identityMismatch,
            'id4_decimal_places' => $id4DecimalPlaces,
            'ignored_unknown_lines_count' => $ignoredUnknownLines,
            'va1_total_sales_eur' => $va1['sales'],
            'va1_total_vend_units' => $va1['units'],
            'va1_reset_sales_eur' => $va1['reset_sales'],
            'va1_reset_vend_units' => $va1['reset_units'],
            'ca3_cashbox_eur' => $ca3['cashbox'] ?? null,
            'ca3_coin_tubes_eur' => $ca3['coin_tubes'] ?? null,
            'ca3_bills_in_eur' => $ca3['bills'] ?? null,
            'selections' => $selections,
            'events' => $events,
        ];
    }

    private function toUtf8(string $rawPayload): string
    {
        if (mb_check_encoding($rawPayload, 'UTF-8')) {
            return $rawPayload;
        }

        if (function_exists('mb_convert_encoding')) {
            return (string)mb_convert_encoding($rawPayload, 'UTF-8', 'Windows-1252');
        }

        throw new DexAuditValidationException(
            'El .dex no es UTF-8 válido y el entorno no permite convertirlo desde Windows-1252.',
            'DEX_INVALID_TEXT_PAYLOAD'
        );
    }

    /**
     * Normaliza saltos, recorta espacios finales y descarta blancos extremos.
     *
     * @return list<string>
     */
    private function normalizeLines(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_map(
            static fn (string $line): string => rtrim($line, " \t"),
            explode("\n", $normalized)
        );

        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }

        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        return array_values($lines);
    }

    /**
     * @return array{sales: float, units: int, reset_sales: float|null, reset_units: int|null}
     */
    private function parseVa1(string $line, int $lineNumber, int $scale): array
    {
        $fields = explode('*', $line);

        return [
            'sales' => $this->requiredMoney($fields[1] ?? null, $scale, 'VA101', $lineNumber),
            'units' => $this->requiredUnits($fields[2] ?? null, 'VA102', $lineNumber),
            'reset_sales' => $this->optionalMoney($fields[3] ?? null, $scale, 'VA103', $lineNumber),
            'reset_units' => $this->optionalUnits($fields[4] ?? null, 'VA104', $lineNumber),
        ];
    }

    /**
     * `CA3` es opcional y algunos firmwares omiten `CA301`: con cinco campos se
     * leen `CA302`/`CA303`/`CA304`; con cuatro, los tres valores tras la etiqueta.
     *
     * @return array{cashbox: float|null, coin_tubes: float|null, bills: float|null}
     */
    private function parseCa3(string $line, int $lineNumber, int $scale): array
    {
        $fields = explode('*', $line);
        $offset = count($fields) >= 5 ? 2 : 1;

        return [
            'cashbox' => $this->optionalMoney($fields[$offset] ?? null, $scale, 'CA302', $lineNumber),
            'coin_tubes' => $this->optionalMoney($fields[$offset + 1] ?? null, $scale, 'CA303', $lineNumber),
            'bills' => $this->optionalMoney($fields[$offset + 2] ?? null, $scale, 'CA304', $lineNumber),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseEvent(string $line, int $lineNumber, int $sequence): array
    {
        $fields = explode('*', $line);
        $block = substr($line, 0, 3);
        $code = $this->assertCodeLength(trim($fields[1] ?? ''), 32, 'event_code', $lineNumber);
        $classification = EvaDtsEventCatalog::describe($code);

        $countSinceReset = null;
        $countSinceInit = null;
        $isActive = false;
        $occurredAtRaw = null;
        $occurrenceCount = 1;

        if ($block === 'EA1') {
            $date = trim($fields[2] ?? '');
            $time = trim($fields[3] ?? '');
            $raw = trim($date . ' ' . $time);
            $occurredAtRaw = $raw === '' ? null : mb_substr($raw, 0, 32, 'UTF-8');
        } else {
            $countSinceReset = $this->optionalCounter($fields[2] ?? null, 'EA202', $lineNumber);
            $countSinceInit = $this->optionalCounter($fields[3] ?? null, 'EA203', $lineNumber);
            $isActive = trim($fields[5] ?? '') === '1';
            $occurrenceCount = max(1, $countSinceReset ?? $countSinceInit ?? 1);
        }

        return [
            'line_sequence' => $sequence,
            'source_block' => $block,
            'event_code' => $code,
            'event_category' => $classification['category']->value,
            'severity' => $classification['severity']->value,
            'occurrence_count' => $occurrenceCount,
            'count_since_reset' => $countSinceReset,
            'count_since_init' => $countSinceInit,
            'is_active_flag' => $isActive,
            'event_occurred_at_raw' => $occurredAtRaw,
            'description_es' => EvaDtsEventCatalog::describeInSpanish($code),
            'is_thermal_alert' => $classification['category']->allowsThermalAlert(),
        ];
    }

    private function parseId4(string $line, int $lineNumber): int
    {
        $fields = explode('*', $line);
        $candidate = trim($fields[1] ?? '');
        if ($candidate === '' && isset($fields[2]) && preg_match('/^\d+$/', trim($fields[2])) === 1) {
            $candidate = trim($fields[2]);
        }

        if (preg_match('/^\d$/', $candidate) !== 1 || (int)$candidate > 4) {
            throw new DexAuditValidationException(
                sprintf("El segmento ID4 declara una escala decimal inválida: '%s'. Debe ser un entero entre 0 y 4.", $candidate),
                'DEX_INVALID_ID4_DECIMAL',
                ['line' => $lineNumber, 'value' => $candidate]
            );
        }

        return (int)$candidate;
    }

    private function detectIdentityMismatch(?string $dexIdentifier, ?string $expectedMachineCode): bool
    {
        if ($dexIdentifier === null || $expectedMachineCode === null) {
            return false;
        }

        $normalized = strtoupper(trim($dexIdentifier));
        if ($normalized === '' || preg_match('/^0+$/', $normalized) === 1 || preg_match('/^X+$/', $normalized) === 1) {
            return false;
        }

        return $normalized !== strtoupper(trim($expectedMachineCode));
    }

    private function requiredMoney(?string $raw, int $scale, string $field, int $lineNumber): float
    {
        $value = $this->parseMoney($raw, $scale, $field, $lineNumber);
        if ($value === null) {
            throw new DexAuditValidationException(
                sprintf('El campo monetario %s es obligatorio y llega vacío.', $field),
                'DEX_INVALID_NUMERIC_BOUNDS',
                ['line' => $lineNumber, 'field' => $field]
            );
        }

        return $value;
    }

    private function optionalMoney(?string $raw, int $scale, string $field, int $lineNumber): ?float
    {
        return $this->parseMoney($raw, $scale, $field, $lineNumber);
    }

    private function parseMoney(?string $raw, int $scale, string $field, int $lineNumber): ?float
    {
        $clean = trim((string)$raw);
        if ($clean === '') {
            return null;
        }

        if (str_contains($clean, '.')) {
            if (preg_match('/^\d+(\.\d{1,2})?$/', $clean) !== 1) {
                throw $this->numericFailure($field, $lineNumber, $clean);
            }
            $value = (float)$clean;
        } else {
            if (preg_match('/^\d+$/', $clean) !== 1) {
                throw $this->numericFailure($field, $lineNumber, $clean);
            }
            $value = (int)$clean / (10 ** $scale);
        }

        $value = round($value, 2);
        if ($value < 0.0 || $value > self::MAX_MONEY_EUR) {
            throw $this->numericFailure($field, $lineNumber, $clean);
        }

        return $value;
    }

    private function requiredUnits(?string $raw, string $field, int $lineNumber): int
    {
        $value = $this->parseUnits($raw, $field, $lineNumber);
        if ($value === null) {
            throw new DexAuditValidationException(
                sprintf('El campo de unidades %s es obligatorio y llega vacío.', $field),
                'DEX_INVALID_NUMERIC_BOUNDS',
                ['line' => $lineNumber, 'field' => $field]
            );
        }

        return $value;
    }

    private function optionalUnits(?string $raw, string $field, int $lineNumber): ?int
    {
        return $this->parseUnits($raw, $field, $lineNumber);
    }

    private function parseUnits(?string $raw, string $field, int $lineNumber): ?int
    {
        $clean = trim((string)$raw);
        if ($clean === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $clean) !== 1) {
            throw $this->numericFailure($field, $lineNumber, $clean);
        }

        $value = (int)$clean;
        if ($value < 0 || $value > self::MAX_UNITS) {
            throw $this->numericFailure($field, $lineNumber, $clean);
        }

        return $value;
    }

    private function optionalCounter(?string $raw, string $field, int $lineNumber): ?int
    {
        $value = $this->parseUnits($raw, $field, $lineNumber);

        return $value;
    }

    private function numericFailure(string $field, int $lineNumber, string $value): DexAuditValidationException
    {
        return new DexAuditValidationException(
            sprintf('El campo %s tiene un valor numérico fuera de rango o no interpretable: «%s».', $field, $value),
            'DEX_INVALID_NUMERIC_BOUNDS',
            ['line' => $lineNumber, 'field' => $field, 'value' => $value]
        );
    }

    private function assertCodeLength(string $value, int $maxLength, string $field, int $lineNumber): string
    {
        $clean = trim($value);
        if ($clean === '' || mb_strlen($clean, 'UTF-8') > $maxLength) {
            throw new DexAuditValidationException(
                sprintf('El campo %s debe tener entre 1 y %d caracteres.', $field, $maxLength),
                'DEX_INVALID_CODE',
                ['line' => $lineNumber, 'field' => $field]
            );
        }

        return $clean;
    }

    private function optionalText(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);
        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, $maxLength, 'UTF-8');
    }

    private function preview(string $line): string
    {
        return mb_substr($line, 0, 32, 'UTF-8');
    }
}
