<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\ValueObject\IncidentCategory;

/**
 * ResolutionTaxonomyHistoryParser
 *
 * Reconstruye los cierres anteriores preservados en `incident_history` cuando
 * una avería reabierta en garantía se vuelve a clasificar (Módulo 25, `C1`,
 * `EARS 03.1` y `EARS 04.1`, Art. III.3).
 *
 * Formato preferente: la nota humana de preservación termina con el marcador
 * `[TAXONOMY_SNAPSHOT]` y un objeto JSON con los cinco campos congelados. El
 * marcador es estable y no depende de los nombres libres del catálogo.
 *
 * Respaldo: las notas escritas antes del marcador conservan el texto
 * `[Causa Raíz: {code} - {name} | Solución: {code} - {name}]`; se aceptan con
 * `category = null` porque esa versión no congelaba la categoría.
 *
 * El parser es puro: no toca base de datos, no lanza por una nota corrupta y
 * descarta cualquier evento que no sea una preservación de clasificación.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, comentarios en castellano.
 */
final class ResolutionTaxonomyHistoryParser
{
    /** Prefijo que identifica el evento de preservación en la auditoría. */
    public const NOTE_MARKER = 'Clasificación previa preservada';

    /** Marcador del bloque máquina-legible añadido al final de la nota. */
    public const JSON_MARKER = '[TAXONOMY_SNAPSHOT]';

    /**
     * Extrae los cierres previos en el orden en que fueron preservados.
     *
     * @param list<array<string, mixed>> $history Filas de `incident_history`
     *        con `action_note` y `created_at`.
     * @return list<array{
     *   root_cause: array{code: string, category: string|null, category_label: string|null, name: string},
     *   standard_solution: array{code: string, name: string},
     *   preserved_at: string|null
     * }>
     */
    public function extractPreviousClassifications(array $history): array
    {
        $episodes = [];

        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }

            $note = $row['action_note'] ?? null;
            if (!is_string($note) || !str_contains($note, self::NOTE_MARKER)) {
                continue;
            }

            $episode = $this->parseNote($note, $row['created_at'] ?? null);
            if ($episode !== null) {
                $episodes[] = $episode;
            }
        }

        return $episodes;
    }

    /**
     * @param mixed $preservedAt Instante de la fila de auditoría.
     * @return array{
     *   root_cause: array{code: string, category: string|null, category_label: string|null, name: string},
     *   standard_solution: array{code: string, name: string},
     *   preserved_at: string|null
     * }|null
     */
    private function parseNote(string $note, mixed $preservedAt): ?array
    {
        $structured = $this->decodeJsonMarker($note);
        if ($structured !== null) {
            return $this->buildEpisode($structured, is_string($preservedAt) ? $preservedAt : null);
        }

        $legacy = $this->decodeLegacyNote($note);
        if ($legacy !== null) {
            return $this->buildEpisode($legacy, is_string($preservedAt) ? $preservedAt : null);
        }

        return null;
    }

    /**
     * @return array{root_cause_code: string, root_cause_category: string|null, root_cause_name: string, standard_solution_code: string, standard_solution_name: string}|null
     */
    private function decodeJsonMarker(string $note): ?array
    {
        $position = strpos($note, self::JSON_MARKER);
        if ($position === false) {
            return null;
        }

        $payload = trim(substr($note, $position + strlen(self::JSON_MARKER)));
        if ($payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        $stringValue = static function (array $source, string $key): ?string {
            $value = $source[$key] ?? null;

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        $causeCode = $stringValue($decoded, 'root_cause_code');
        $causeName = $stringValue($decoded, 'root_cause_name');
        $solutionCode = $stringValue($decoded, 'standard_solution_code');
        $solutionName = $stringValue($decoded, 'standard_solution_name');

        if ($causeCode === null || $causeName === null || $solutionCode === null || $solutionName === null) {
            return null;
        }

        return [
            'root_cause_code' => $causeCode,
            'root_cause_category' => $stringValue($decoded, 'root_cause_category'),
            'root_cause_name' => $causeName,
            'standard_solution_code' => $solutionCode,
            'standard_solution_name' => $solutionName,
        ];
    }

    /**
     * @return array{root_cause_code: string, root_cause_category: null, root_cause_name: string, standard_solution_code: string, standard_solution_name: string}|null
     */
    private function decodeLegacyNote(string $note): ?array
    {
        $pattern = '/\[Causa Raíz:\s*([A-Z0-9-]+)\s*-\s*(.*?)\s*\|\s*Solución:\s*([A-Z0-9-]+)\s*-\s*(.*?)\]\s*$/u';
        if (preg_match($pattern, $note, $matches) !== 1) {
            return null;
        }

        $causeCode = trim($matches[1]);
        $causeName = trim($matches[2]);
        $solutionCode = trim($matches[3]);
        $solutionName = trim($matches[4]);

        if ($causeCode === '' || $causeName === '' || $solutionCode === '' || $solutionName === '') {
            return null;
        }

        return [
            'root_cause_code' => $causeCode,
            'root_cause_category' => null,
            'root_cause_name' => $causeName,
            'standard_solution_code' => $solutionCode,
            'standard_solution_name' => $solutionName,
        ];
    }

    /**
     * @param array{root_cause_code: string, root_cause_category: string|null, root_cause_name: string, standard_solution_code: string, standard_solution_name: string} $decoded
     * @return array{
     *   root_cause: array{code: string, category: string|null, category_label: string|null, name: string},
     *   standard_solution: array{code: string, name: string},
     *   preserved_at: string|null
     * }
     */
    private function buildEpisode(array $decoded, ?string $preservedAt): array
    {
        $categoryValue = $decoded['root_cause_category'];
        $category = is_string($categoryValue) && trim($categoryValue) !== ''
            ? IncidentCategory::tryFrom(trim($categoryValue))
            : null;

        return [
            'root_cause' => [
                'code' => $decoded['root_cause_code'],
                'category' => $categoryValue !== null && trim($categoryValue) !== ''
                    ? trim($categoryValue)
                    : null,
                'category_label' => $category?->label(),
                'name' => $decoded['root_cause_name'],
            ],
            'standard_solution' => [
                'code' => $decoded['standard_solution_code'],
                'name' => $decoded['standard_solution_name'],
            ],
            'preserved_at' => $preservedAt,
        ];
    }
}
