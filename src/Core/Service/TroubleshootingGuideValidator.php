<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Exception\TroubleshootingGuideValidationException;
use VendGuard\Core\Domain\Model\MachineTroubleshootingGuide;
use VendGuard\Core\Domain\Model\RootCause;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;

/**
 * TroubleshootingGuideValidator
 *
 * Validador puro de las guías rápidas de diagnóstico (Módulo 28, RF-01.1,
 * RF-04.1 y RF-04.2; `C1`–`C3`, `C5`, `QA 2`; Art. V.1).
 *
 * Reglas que muerde:
 *   1. **Normalización antes de medir (C5).** Modelo, título, títulos de paso,
 *      instrucciones y significados se recortan y colapsan sus espacios repetidos
 *      antes de contar caracteres: el Art. V.1 habla de caracteres reales, no de
 *      relleno. La clave del modelo se deriva de la etiqueta normalizada, nunca
 *      se recibe del cliente.
 *   2. **Cotas del contenido (C3).** Entre 2 y 10 pasos, cada uno con título de
 *      3 a 120 caracteres e instrucción de 20 a 500 con al menos 3 bloques
 *      alfanuméricos reales; entre 0 y 20 códigos de error con código único
 *      insensible a mayúsculas y significado de 5 a 200; entre 0 y 10 repuestos
 *      únicos que conservan el orden definido por Coordinación (`QA 3`).
 *   3. **Identidad inmutable (C1).** En la edición, un modelo o una categoría
 *      distintos de los de la guía se rechazan con `IMMUTABLE_GUIDE_IDENTITY`:
 *      no son una edición, serían otra guía ocupando el hueco de la anterior.
 *   4. **Afinidad de la causa raíz (QA 2).** La causa sugerida debe existir, estar
 *      activa y pertenecer a la misma categoría de la guía o a la transversal
 *      `OTHER`. La coherencia sólo se exige cuando el campo viaja en el cuerpo:
 *      una guía cuya causa se desactivó después sigue siendo editable en el resto
 *      de su contenido (`C2`).
 *
 * El validador no escribe ni consulta la base: recibe las entidades ya resueltas
 * y devuelve el payload normalizado que el repositorio persistirá.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class TroubleshootingGuideValidator
{
    public const MIN_STEP_TITLE_LENGTH = 3;

    public const MAX_STEP_TITLE_LENGTH = 120;

    public const MIN_INSTRUCTION_LENGTH = 20;

    public const MAX_INSTRUCTION_LENGTH = 500;

    public const MIN_ALPHANUMERIC_BLOCKS = 3;

    public const MIN_ERROR_CODE_LENGTH = 1;

    public const MAX_ERROR_CODE_LENGTH = 20;

    public const MIN_ERROR_MEANING_LENGTH = 5;

    public const MAX_ERROR_MEANING_LENGTH = 200;

    /**
     * Valida el alta completa de una guía y devuelve el payload normalizado.
     *
     * @param array<string, mixed> $payload
     * @param RootCause|null       $suggestedRootCause Causa resuelta por el repositorio, o null si no existe.
     *
     * @return array{
     *     machine_model: string,
     *     machine_model_key: string,
     *     breakdown_type: IncidentCategory,
     *     title: string,
     *     error_codes: list<array{code: string, meaning: string}>,
     *     steps: list<array{step_order: int, title: string, instruction: string}>,
     *     recommended_spare_part_ids: list<int>,
     *     suggested_root_cause_id: int|null
     * }
     *
     * @throws TroubleshootingGuideValidationException
     */
    public static function validateCreate(array $payload, ?RootCause $suggestedRootCause = null): array
    {
        $machineModel = self::requireModel($payload['machine_model'] ?? null);
        $breakdownType = self::requireCategory($payload['breakdown_type'] ?? null);
        $suggestedRootCauseId = self::validateRootCause(
            $payload['suggested_root_cause_id'] ?? null,
            $breakdownType,
            $suggestedRootCause
        );

        return [
            'machine_model' => $machineModel,
            'machine_model_key' => MachineTroubleshootingGuide::normalizeModelKey($machineModel),
            'breakdown_type' => $breakdownType,
            'title' => self::requireTitle($payload['title'] ?? null),
            'error_codes' => self::validateErrorCodes($payload['error_codes'] ?? []),
            'steps' => self::validateSteps($payload['steps'] ?? null),
            'recommended_spare_part_ids' => self::validateSparePartIds($payload['recommended_spare_part_ids'] ?? []),
            'suggested_root_cause_id' => $suggestedRootCauseId,
        ];
    }

    /**
     * Valida la edición de una guía existente y devuelve sólo los campos
     * editables normalizados. El modelo y la categoría no se devuelven: son la
     * identidad inmutable de la ficha (C1).
     *
     * @param array<string, mixed> $payload
     * @param RootCause|null       $suggestedRootCause Causa resuelta si el cuerpo la envía; null en otro caso.
     *
     * @return array{
     *     title: string,
     *     error_codes: list<array{code: string, meaning: string}>,
     *     steps: list<array{step_order: int, title: string, instruction: string}>,
     *     recommended_spare_part_ids: list<int>,
     *     suggested_root_cause_id: int|null
     * }
     *
     * @throws TroubleshootingGuideValidationException
     */
    public static function validateUpdate(
        array $payload,
        MachineTroubleshootingGuide $current,
        ?RootCause $suggestedRootCause = null
    ): array {
        $immutableFields = [];

        if (array_key_exists('machine_model', $payload)) {
            $requestedModel = is_string($payload['machine_model'])
                ? MachineTroubleshootingGuide::normalizeModelKey($payload['machine_model'])
                : '';
            if ($requestedModel !== $current->getMachineModelKey()) {
                $immutableFields[] = 'machine_model';
            }
        }

        if (array_key_exists('breakdown_type', $payload)) {
            $requestedCategory = self::tryCategory($payload['breakdown_type']);
            if ($requestedCategory !== $current->getBreakdownType()) {
                $immutableFields[] = 'breakdown_type';
            }
        }

        if ($immutableFields !== []) {
            throw TroubleshootingGuideValidationException::immutableIdentity($immutableFields);
        }

        $title = array_key_exists('title', $payload)
            ? self::requireTitle($payload['title'])
            : $current->getTitle();

        $steps = array_key_exists('steps', $payload)
            ? self::validateSteps($payload['steps'])
            : $current->getSteps();

        $errorCodes = array_key_exists('error_codes', $payload)
            ? self::validateErrorCodes($payload['error_codes'])
            : $current->getErrorCodes();

        $sparePartIds = array_key_exists('recommended_spare_part_ids', $payload)
            ? self::validateSparePartIds($payload['recommended_spare_part_ids'])
            : $current->getRecommendedSparePartIds();

        // La coherencia de la causa sólo se revalida si el campo viaja: una causa
        // desactivada después no puede impedir editar un título (C2).
        $suggestedRootCauseId = array_key_exists('suggested_root_cause_id', $payload)
            ? self::validateRootCause($payload['suggested_root_cause_id'], $current->getBreakdownType(), $suggestedRootCause)
            : $current->getSuggestedRootCauseId();

        return [
            'title' => $title,
            'error_codes' => $errorCodes,
            'steps' => $steps,
            'recommended_spare_part_ids' => $sparePartIds,
            'suggested_root_cause_id' => $suggestedRootCauseId,
        ];
    }

    /**
     * Valida y renumera los pasos de comprobación (2..10, Art. V.1, C3).
     *
     * @return list<array{step_order: int, title: string, instruction: string}>
     *
     * @throws TroubleshootingGuideValidationException
     */
    public static function validateSteps(mixed $steps): array
    {
        if (!is_array($steps)) {
            throw TroubleshootingGuideValidationException::invalidSteps('debe ser una lista de pasos.');
        }

        $steps = array_values($steps);
        $count = count($steps);
        if ($count < MachineTroubleshootingGuide::MIN_STEPS || $count > MachineTroubleshootingGuide::MAX_STEPS) {
            throw TroubleshootingGuideValidationException::invalidSteps(sprintf(
                'se esperaban entre %d y %d pasos; se recibieron %d.',
                MachineTroubleshootingGuide::MIN_STEPS,
                MachineTroubleshootingGuide::MAX_STEPS,
                $count
            ));
        }

        $normalized = [];
        foreach ($steps as $index => $step) {
            $position = $index + 1;
            if (!is_array($step)) {
                throw TroubleshootingGuideValidationException::invalidSteps(
                    sprintf('el paso %d no es un objeto con título e instrucción.', $position)
                );
            }

            $title = isset($step['title']) && is_string($step['title'])
                ? self::normalizeText($step['title'])
                : '';
            $titleLength = mb_strlen($title, 'UTF-8');
            if ($titleLength < self::MIN_STEP_TITLE_LENGTH || $titleLength > self::MAX_STEP_TITLE_LENGTH) {
                throw TroubleshootingGuideValidationException::invalidSteps(sprintf(
                    'el título del paso %d debe contener entre %d y %d caracteres reales; se recibieron %d.',
                    $position,
                    self::MIN_STEP_TITLE_LENGTH,
                    self::MAX_STEP_TITLE_LENGTH,
                    $titleLength
                ));
            }

            $instruction = isset($step['instruction']) && is_string($step['instruction'])
                ? self::normalizeText($step['instruction'])
                : '';
            $instructionLength = mb_strlen($instruction, 'UTF-8');
            if ($instructionLength < self::MIN_INSTRUCTION_LENGTH || $instructionLength > self::MAX_INSTRUCTION_LENGTH) {
                throw TroubleshootingGuideValidationException::invalidSteps(sprintf(
                    'la instrucción del paso %d debe contener entre %d y %d caracteres reales; se recibieron %d.',
                    $position,
                    self::MIN_INSTRUCTION_LENGTH,
                    self::MAX_INSTRUCTION_LENGTH,
                    $instructionLength
                ));
            }

            if (self::countAlphanumericBlocks($instruction) < self::MIN_ALPHANUMERIC_BLOCKS) {
                throw TroubleshootingGuideValidationException::invalidSteps(sprintf(
                    'la instrucción del paso %d debe contener al menos %d bloques alfanuméricos reales (Art. V.1).',
                    $position,
                    self::MIN_ALPHANUMERIC_BLOCKS
                ));
            }

            $normalized[] = [
                'step_order' => $position,
                'title' => $title,
                'instruction' => $instruction,
            ];
        }

        return $normalized;
    }

    /**
     * Valida los códigos de error del display (0..20, únicos sin distinguir
     * mayúsculas, C3).
     *
     * @return list<array{code: string, meaning: string}>
     *
     * @throws TroubleshootingGuideValidationException
     */
    public static function validateErrorCodes(mixed $errorCodes): array
    {
        if (!is_array($errorCodes)) {
            throw TroubleshootingGuideValidationException::invalidErrorCodes('debe ser una lista de códigos.');
        }

        $errorCodes = array_values($errorCodes);
        $count = count($errorCodes);
        if ($count > MachineTroubleshootingGuide::MAX_ERROR_CODES) {
            throw TroubleshootingGuideValidationException::invalidErrorCodes(sprintf(
                'se admiten como máximo %d códigos; se recibieron %d.',
                MachineTroubleshootingGuide::MAX_ERROR_CODES,
                $count
            ));
        }

        $normalized = [];
        $seenCodes = [];
        foreach ($errorCodes as $index => $entry) {
            $position = $index + 1;
            if (!is_array($entry)) {
                throw TroubleshootingGuideValidationException::invalidErrorCodes(
                    sprintf('la entrada %d no es un objeto con código y significado.', $position)
                );
            }

            $code = isset($entry['code']) && is_string($entry['code']) ? trim($entry['code']) : '';
            $codeLength = mb_strlen($code, 'UTF-8');
            if ($codeLength < self::MIN_ERROR_CODE_LENGTH || $codeLength > self::MAX_ERROR_CODE_LENGTH) {
                throw TroubleshootingGuideValidationException::invalidErrorCodes(sprintf(
                    'el código de la entrada %d debe contener entre %d y %d caracteres; se recibieron %d.',
                    $position,
                    self::MIN_ERROR_CODE_LENGTH,
                    self::MAX_ERROR_CODE_LENGTH,
                    $codeLength
                ));
            }

            $codeKey = mb_strtolower($code, 'UTF-8');
            if (isset($seenCodes[$codeKey])) {
                throw TroubleshootingGuideValidationException::invalidErrorCodes(sprintf(
                    'el código «%s» está repetido; los códigos deben ser únicos en la guía.',
                    $code
                ));
            }
            $seenCodes[$codeKey] = true;

            $meaning = isset($entry['meaning']) && is_string($entry['meaning'])
                ? self::normalizeText($entry['meaning'])
                : '';
            $meaningLength = mb_strlen($meaning, 'UTF-8');
            if ($meaningLength < self::MIN_ERROR_MEANING_LENGTH || $meaningLength > self::MAX_ERROR_MEANING_LENGTH) {
                throw TroubleshootingGuideValidationException::invalidErrorCodes(sprintf(
                    'el significado del código «%s» debe contener entre %d y %d caracteres reales; se recibieron %d.',
                    $code,
                    self::MIN_ERROR_MEANING_LENGTH,
                    self::MAX_ERROR_MEANING_LENGTH,
                    $meaningLength
                ));
            }

            $normalized[] = ['code' => $code, 'meaning' => $meaning];
        }

        return $normalized;
    }

    /**
     * Valida los repuestos recomendados (0..10 identificadores positivos únicos,
     * conservando el orden de Coordinación, QA 3).
     *
     * @return list<int>
     *
     * @throws TroubleshootingGuideValidationException
     */
    public static function validateSparePartIds(mixed $sparePartIds): array
    {
        if (!is_array($sparePartIds)) {
            throw TroubleshootingGuideValidationException::invalidSpareParts('debe ser una lista de identificadores.');
        }

        $sparePartIds = array_values($sparePartIds);
        $count = count($sparePartIds);
        if ($count > MachineTroubleshootingGuide::MAX_SPARE_PARTS) {
            throw TroubleshootingGuideValidationException::invalidSpareParts(sprintf(
                'se admiten como máximo %d repuestos; se recibieron %d.',
                MachineTroubleshootingGuide::MAX_SPARE_PARTS,
                $count
            ));
        }

        $normalized = [];
        $seenIds = [];
        foreach ($sparePartIds as $index => $value) {
            $position = $index + 1;
            $partId = is_int($value)
                ? $value
                : (is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int)$value : null);

            if ($partId === null || $partId <= 0) {
                throw TroubleshootingGuideValidationException::invalidSpareParts(sprintf(
                    'la referencia %d no es un identificador entero positivo.',
                    $position
                ));
            }

            if (isset($seenIds[$partId])) {
                throw TroubleshootingGuideValidationException::invalidSpareParts(sprintf(
                    'el repuesto %d está repetido; cada referencia debe aparecer una sola vez.',
                    $partId
                ));
            }
            $seenIds[$partId] = true;
            $normalized[] = $partId;
        }

        return $normalized;
    }

    /**
     * Normaliza texto libre: recorta extremos y colapsa espacios repetidos.
     */
    public static function normalizeText(string $value): string
    {
        return MachineTroubleshootingGuide::normalizeModelName($value);
    }

    /**
     * Bloques alfanuméricos reales de un texto (Art. V.1): secuencias de letras o
     * números separadas por cualquier otro carácter. Los signos repetidos no
     * cuentan como palabra.
     */
    public static function countAlphanumericBlocks(string $text): int
    {
        $blocks = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $blocks === false ? 0 : count($blocks);
    }

    /**
     * @throws TroubleshootingGuideValidationException
     */
    private static function requireModel(mixed $rawModel): string
    {
        $model = is_string($rawModel) ? MachineTroubleshootingGuide::normalizeModelName($rawModel) : '';
        $length = mb_strlen($model, 'UTF-8');

        if ($length < MachineTroubleshootingGuide::MIN_MODEL_LENGTH
            || $length > MachineTroubleshootingGuide::MAX_MODEL_LENGTH) {
            throw TroubleshootingGuideValidationException::invalidModel($length);
        }

        return $model;
    }

    /**
     * @throws TroubleshootingGuideValidationException
     */
    private static function requireCategory(mixed $rawCategory): IncidentCategory
    {
        $category = self::tryCategory($rawCategory);
        if ($category === null) {
            throw TroubleshootingGuideValidationException::invalidCategory(
                is_string($rawCategory) ? $rawCategory : get_debug_type($rawCategory)
            );
        }

        return $category;
    }

    private static function tryCategory(mixed $rawCategory): ?IncidentCategory
    {
        if (!is_string($rawCategory)) {
            return null;
        }

        return IncidentCategory::tryFrom(strtoupper(trim($rawCategory)));
    }

    /**
     * @throws TroubleshootingGuideValidationException
     */
    private static function requireTitle(mixed $rawTitle): string
    {
        $title = is_string($rawTitle) ? self::normalizeText($rawTitle) : '';
        $length = mb_strlen($title, 'UTF-8');

        if ($length < MachineTroubleshootingGuide::MIN_TITLE_LENGTH
            || $length > MachineTroubleshootingGuide::MAX_TITLE_LENGTH) {
            throw TroubleshootingGuideValidationException::invalidTitle($length);
        }

        return $title;
    }

    /**
     * @throws TroubleshootingGuideValidationException
     */
    private static function validateRootCause(
        mixed $rawRootCauseId,
        IncidentCategory $breakdownType,
        ?RootCause $rootCause
    ): ?int {
        if ($rawRootCauseId === null || $rawRootCauseId === '') {
            return null;
        }

        $rootCauseId = is_int($rawRootCauseId)
            ? $rawRootCauseId
            : (is_string($rawRootCauseId) && preg_match('/^\d+$/', $rawRootCauseId) === 1
                ? (int)$rawRootCauseId
                : null);

        if ($rootCauseId === null || $rootCauseId <= 0) {
            throw TroubleshootingGuideValidationException::invalidRootCause(
                'el identificador debe ser un entero positivo.'
            );
        }

        if ($rootCause === null || $rootCause->getId() === null) {
            throw TroubleshootingGuideValidationException::invalidRootCause(
                sprintf('no existe ninguna causa raíz con ID %d en el catálogo maestro.', $rootCauseId)
            );
        }

        if ($rootCause->getId() !== $rootCauseId) {
            throw TroubleshootingGuideValidationException::invalidRootCause(
                'la causa resuelta no coincide con el identificador enviado.'
            );
        }

        if (!$rootCause->isActive()) {
            throw TroubleshootingGuideValidationException::invalidRootCause(sprintf(
                'la causa raíz «%s» está desactivada; seleccione una entrada vigente del catálogo (C2).',
                $rootCause->getCode()
            ));
        }

        if ($rootCause->getCategory() !== $breakdownType && $rootCause->getCategory() !== IncidentCategory::OTHER) {
            throw TroubleshootingGuideValidationException::invalidRootCause(sprintf(
                'la causa raíz «%s» pertenece a «%s» y la guía es de «%s»; sólo se admite la misma categoría o la transversal OTHER (QA 2).',
                $rootCause->getCode(),
                $rootCause->getCategory()->value,
                $breakdownType->value
            ));
        }

        return $rootCauseId;
    }
}
