<?php

declare(strict_types=1);

/**
 * VendGuard - Guarda contra la deriva de las cifras documentadas (T-39)
 *
 * Problema de origen: varias cifras de la batería viven escritas a mano en la
 * documentación (insignia de estado del README, bloque de resumen por fases,
 * comentario del árbol de directorios y notas de informes vivos). Cada vez que
 * se añadía una suite quedaban desfasadas en silencio, y el desfase solo se
 * descubría releyendo el README. Este guardia convierte esa deriva en un fallo
 * de la batería: si las cifras documentadas no coinciden con lo que se acaba de
 * ejecutar, `php tests/run_all.php` termina en rojo.
 *
 * Política de comprobación (importante para no falsear la historia):
 * - Los informes de verificación históricos citan cifras congeladas de su fecha
 *   ("168 suites / 6.317 aserciones" al cerrar un módulo) y NO se auditan aquí.
 *   Reescribirlos falsearía su certificación.
 * - Solo se auditan dos cosas: los anclajes declarados del README (insignia,
 *   bloque de resumen y árbol) y la frase canónica
 *
 *       batería global: <N> suites · <M> aserciones
 *
 *   allí donde un documento vivo quiera declarar la cifra vigente. Debe existir
 *   al menos una frase canónica en la documentación versionada; si desaparecen
 *   todas, el guardia también falla (no se puede quedar sin fuente de verdad).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias. El listado de ficheros se
 * delega en `git ls-files` para no auditar documentos ignorados por el control
 * de versiones; si git no está disponible se emite un aviso, no un fallo.
 */

final class DocMetricsGuard
{
    /** Frase canónica de cifras vigentes, tolerante a la tilde y al tamaño de letra. */
    public const CANONICAL_PATTERN = '/bater[íi]a global:\s*(\d+)\s*suites\s*·\s*([\d.]+)\s*aserciones/u';

    /** Anclajes del README que declaran cifras de la batería. */
    private const README_PHASES = [
        'unit_php' => 'Suites de pruebas PHP Unit',
        'unit_mjs' => 'Suites de pruebas JS Unit',
        'integration_php' => 'Suites de Integración PHP',
        'manual_e2e' => 'Suites E2E Manuales',
    ];

    public function __construct(private readonly string $projectRoot)
    {
    }

    /** Frase canónica que corresponde a una ejecución dada, para los mensajes de error. */
    public static function canonicalSentence(int $totalSuites, int $totalAssertions): string
    {
        return sprintf('batería global: %d suites · %s aserciones', $totalSuites, self::formatNumber($totalAssertions));
    }

    /**
     * Compara las cifras documentadas con las realmente ejecutadas.
     *
     * @param array<string, array{total:int, passed:int, failed:int}> $stats
     * @return array{deviations: list<string>, warnings: list<string>}
     */
    public function verify(array $stats, int $totalSuites, int $totalAssertions): array
    {
        $deviations = [];
        $warnings = [];

        $tracked = $this->trackedMarkdownFiles($warnings);

        foreach ($tracked as $file) {
            $path = $this->projectRoot . '/' . $file;
            $content = @file_get_contents($path);
            if ($content === false) {
                $warnings[] = "No se pudo leer {$file} para auditar sus cifras.";
                continue;
            }

            preg_match_all(self::CANONICAL_PATTERN, $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $documentedSuites = (int)$match[1];
                $documentedAssertions = self::toInt($match[2]);
                if ($documentedSuites === $totalSuites && $documentedAssertions === $totalAssertions) {
                    continue;
                }

                $deviations[] = sprintf(
                    '%s · frase canónica desfasada: declara %d suites / %s aserciones y la batería ejecuta %d / %s. Línea esperada: `%s`',
                    $file,
                    $documentedSuites,
                    self::formatNumber($documentedAssertions),
                    $totalSuites,
                    self::formatNumber($totalAssertions),
                    self::canonicalSentence($totalSuites, $totalAssertions)
                );
            }
        }

        if ($tracked !== [] && !$this->hasCanonicalSentence($tracked)) {
            $deviations[] = sprintf(
                'Ningún documento versionado declara la frase canónica de cifras. Añádela donde corresponda: `%s`',
                self::canonicalSentence($totalSuites, $totalAssertions)
            );
        }

        foreach ($this->readmeDeviations($stats, $totalSuites, $totalAssertions) as $deviation) {
            $deviations[] = $deviation;
        }

        return ['deviations' => $deviations, 'warnings' => $warnings];
    }

    /** @param list<string> $tracked */
    private function hasCanonicalSentence(array $tracked): bool
    {
        foreach ($tracked as $file) {
            $content = @file_get_contents($this->projectRoot . '/' . $file);
            if ($content !== false && preg_match(self::CANONICAL_PATTERN, $content) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array{total:int, passed:int, failed:int}> $stats
     * @return list<string>
     */
    private function readmeDeviations(array $stats, int $totalSuites, int $totalAssertions): array
    {
        $file = 'README.md';
        $content = @file_get_contents($this->projectRoot . '/' . $file);
        if ($content === false) {
            return ['No se pudo leer README.md para auditar sus cifras.'];
        }

        $deviations = [];

        // 1. Insignia de estado de tests (shields.io): la cifra más visible del repositorio.
        if (preg_match('/Tests-(\d+)%20Suites%20%7C%20([\d.]+)%20Pass/', $content, $badge) === 1) {
            if ((int)$badge[1] !== $totalSuites || self::toInt($badge[2]) !== $totalAssertions) {
                $deviations[] = sprintf(
                    '%s · insignia de tests desfasada: declara %d suites / %s aserciones y la batería ejecuta %d / %s.',
                    $file,
                    (int)$badge[1],
                    $badge[2],
                    $totalSuites,
                    self::formatNumber($totalAssertions)
                );
            }
        } else {
            $deviations[] = "{$file} · no se encontró la insignia de estado de tests.";
        }

        // 2. Bloque de resumen por fases y totales (salida real pegada en el README).
        foreach (self::README_PHASES as $key => $label) {
            // La etiqueta puede llevar un sufijo entre paréntesis antes de los dos puntos
            // (p. ej. «Suites E2E Manuales (T-40): 1 / 1 pasadas»).
            $pattern = '/' . preg_quote($label, '/') . '(?:\s*\([^)]*\))?\s*:\s*(\d+)\s*\/\s*(\d+)\s*pasadas/';
            if (preg_match($pattern, $content, $phase) !== 1) {
                $deviations[] = "{$file} · falta la línea de la fase «{$label}» en el resumen de ejecución.";
                continue;
            }

            $expectedPassed = $stats[$key]['passed'] ?? 0;
            $expectedTotal = $stats[$key]['total'] ?? 0;
            if ((int)$phase[1] !== $expectedPassed || (int)$phase[2] !== $expectedTotal) {
                $deviations[] = sprintf(
                    '%s · fase «%s» desfasada: declara %d / %d pasadas y la batería ejecuta %d / %d.',
                    $file,
                    $label,
                    (int)$phase[1],
                    (int)$phase[2],
                    $expectedPassed,
                    $expectedTotal
                );
            }
        }

        $totals = [
            'Total Suites Ejecutadas' => $totalSuites,
            'Total Aserciones Evaluadas' => $totalAssertions,
        ];
        foreach ($totals as $label => $expected) {
            $pattern = '/' . preg_quote($label, '/') . '\s*:\s*([\d.]+)/';
            if (preg_match($pattern, $content, $total) !== 1) {
                $deviations[] = "{$file} · falta la línea «{$label}» en el resumen de ejecución.";
                continue;
            }

            if (self::toInt($total[1]) !== $expected) {
                $deviations[] = sprintf(
                    '%s · «%s» desfasada: declara %s y la batería ejecuta %s.',
                    $file,
                    $label,
                    $total[1],
                    self::formatNumber($expected)
                );
            }
        }

        // 3. Comentario del árbol de directorios.
        // Es obligatorio el modificador /u: «batería» lleva tilde y sin él la clase [íi]
        // se interpreta byte a byte y el anclaje nunca casa.
        if (preg_match('/bater[íi]a de (\d+) suites/iu', $content, $tree) === 1) {
            if ((int)$tree[1] !== $totalSuites) {
                $deviations[] = sprintf(
                    '%s · árbol de directorios desfasado: declara una batería de %d suites y se ejecutan %d.',
                    $file,
                    (int)$tree[1],
                    $totalSuites
                );
            }
        } else {
            $deviations[] = "{$file} · no se encontró el comentario del árbol con el tamaño de la batería.";
        }

        return $deviations;
    }

    /**
     * Documentos markdown versionados (los ignorados no se auditan).
     *
     * @param list<string> $warnings
     * @return list<string>
     */
    private function trackedMarkdownFiles(array &$warnings): array
    {
        $command = sprintf('git -C %s ls-files -- "*.md" 2>&1', escapeshellarg($this->projectRoot));
        $output = shell_exec($command);
        if (!is_string($output) || trim($output) === '') {
            $warnings[] = 'No se pudo enumerar los markdown versionados con git; la frase canónica no se audita en esta ejecución.';
            return [];
        }

        $files = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && str_ends_with(strtolower($line), '.md')) {
                $files[] = $line;
            }
        }

        return $files;
    }

    private static function toInt(string $documented): int
    {
        return (int)str_replace(['.', ' '], '', $documented);
    }

    private static function formatNumber(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
