<?php

declare(strict_types=1);

/**
 * VendGuard - Descubrimiento de las suites del canal `new_engine`
 *
 * Una suite pertenece al canal porque lo declara en su propio fichero:
 *
 *     const VENDGUARD_TEST_CATEGORY = 'new_engine';
 *
 * No hay lista paralela. La batería hermética (`tests/run_all.php`) usa la marca
 * para **no** ejecutarlas, el runner del canal (`tests/run_new_engine.php`) la usa
 * para ejecutarlas todas y la guarda documental
 * (`tests/unit/NewEngineChannelDocumentationTest.php`) la usa para comprobar que
 * el recuento publicado en el README sigue siendo el real. Que los tres lean la
 * misma fuente es lo que impide que el número de suites se desfase en silencio:
 * antes el README anunciaba 14 suites cuando ya había 27.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class NewEngineChannel
{
    /** Categoría declarada por las suites que exigen PostgreSQL real (RNF-04). */
    public const CATEGORY = 'new_engine';

    /**
     * Marca de categoría en el código de la suite.
     *
     * Se ancla a la declaración completa —`const`, nombre y literal— para no
     * confundirla con una mención en un comentario o en una cadena de texto.
     */
    public const MARKER_PATTERN = '/const\s+VENDGUARD_TEST_CATEGORY\s*=\s*\'([a-z0-9_]+)\';/i';

    /**
     * Categoría declarada por un fichero de suite, o `null` si no declara ninguna.
     */
    public static function categoryOf(string $filePath): ?string
    {
        $source = @file_get_contents($filePath);
        if ($source === false) {
            return null;
        }

        if (preg_match(self::MARKER_PATTERN, $source, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Suites del canal, ordenadas por nombre.
     *
     * @return array<string, string> nombre de fichero => ruta absoluta
     */
    public static function discover(string $integrationDir): array
    {
        $suites = [];
        foreach (glob(rtrim($integrationDir, '/\\') . '/*.php') ?: [] as $filePath) {
            if (self::categoryOf($filePath) !== self::CATEGORY) {
                continue;
            }

            $suites[basename($filePath)] = $filePath;
        }

        ksort($suites);

        return $suites;
    }
}
