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
 * También resuelve el contrato de entorno **propio** de una suite
 * (`VENDGUARD_TEST_ENV_CONTRACT`): así la medición contra el agrupador remoto
 * recibe su `DATABASE_URL` y su CA sin que el resto del canal —que mide contra
 * el banco del arnés— herede un destinatario distinto.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class NewEngineChannel
{
    /** Categoría declarada por las suites que exigen PostgreSQL real (RNF-04). */
    public const CATEGORY = 'new_engine';

    /**
     * Marca del contrato de entorno propio de una suite.
     *
     * Una suite que mide contra un destinatario distinto del banco del arnés
     * —la medición de RNF-01 contra el agrupador remoto, por ejemplo— declara
     * aquí el fichero (ignorado por git) del que debe leer su contrato. El
     * canal lo resuelve para ese proceso hijo y solo para ese, de modo que dos
     * destinatarios conviven en una misma corrida sin pisarse la variable
     * `DATABASE_URL`.
     */
    public const ENV_CONTRACT_PATTERN = '/const\s+VENDGUARD_TEST_ENV_CONTRACT\s*=\s*\'([^\']+)\';/';

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
     * Contrato de entorno declarado por una suite, o `null` si usa el del canal.
     */
    public static function environmentContractOf(string $filePath): ?string
    {
        $source = @file_get_contents($filePath);
        if ($source === false) {
            return null;
        }

        if (preg_match(self::ENV_CONTRACT_PATTERN, $source, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Lee las claves admitidas de un contrato de entorno con formato shell.
     *
     * Se aceptan las dos formas que usa el proyecto (`export CLAVE='valor'` y
     * `CLAVE=valor`), se retiran las comillas envolventes y se expande `$HOME`
     * o `${HOME}` dentro de valores entrecomillados. El canal nunca imprime los
     * valores: solo los inyecta en el proceso de la suite que los declaró.
     *
     * @param list<string> $allowedKeys claves que el canal está dispuesto a inyectar
     * @return array<string, string>
     */
    public static function readEnvironmentContract(string $contractPath, array $allowedKeys): array
    {
        $contents = @file_get_contents($contractPath);
        if ($contents === false) {
            return [];
        }

        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            $profile = getenv('USERPROFILE');
            $home = is_string($profile) ? $profile : '';
        }

        $values = [];
        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $key = $match[1];
            if (!in_array($key, $allowedKeys, true)) {
                continue;
            }

            $value = trim($match[2]);
            $quotedDouble = strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"';
            $quotedSingle = strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'";
            if ($quotedDouble || $quotedSingle) {
                $value = substr($value, 1, -1);
            }

            if ($quotedDouble && $home !== '') {
                // El valor viaja dentro de un DSN de PDO, no por un shell: en Windows la ruta
                // se publica con barras normales para que no se interpreten como escapes.
                $normalizedHome = str_replace('\\', '/', $home);
                $value = str_replace(['${HOME}', '$HOME'], $normalizedHome, $value);
            }

            if ($value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
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
