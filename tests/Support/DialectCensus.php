<?php

declare(strict_types=1);

/**
 * DialectCensus — el censo del dialecto del SQL del repositorio, en un solo punto.
 *
 * Tarea: T-13-06 · Requisitos: EARS-13.4, EARS-13.10
 *
 * POR QUÉ VIVE APARTE DE LA GUARDA
 *
 * La guarda ([`SqlDialectPortabilityTest`](../unit/SqlDialectPortabilityTest.php)) **prohíbe** el
 * dialecto heredado y **exige** el del motor único; aquí viven las familias, el rasgo que las hace
 * contables y el rastreo. Separarlos tiene un motivo comprobable: el censo que la guarda congela y
 * cualquier medición del porte —el árbol anterior frente al árbol entregado— tienen que ser **el
 * mismo rule set**, o dos cifras de la misma clase no serían comparables entre sí.
 *
 * QUÉ SE CUENTA Y QUÉ NO
 *
 * La unidad es **el literal de cadena**, no la línea: un fichero se cuenta una vez por familia si
 * al menos un literal suyo contiene esa familia (es decir, el censo por familia es «literales con
 * la familia», y el total es su suma, de modo que un literal que declara dos familias cuenta en
 * las dos). Se rastrean los literales **tokenizados** de PHP —`token_get_all`—, de modo que
 * comentarios y docblocks quedan fuera: la documentación que cita sintaxis del motor heredado no
 * es dialecto, y contarla convertiría el censo en un recuento de menciones.
 *
 * Las cadenas con interpolación se cuentan **por fragmentos**, que es como las ve el intérprete:
 * un `"... FROM x WHERE a = '{$v}' AND b = 1 ..."` llega aquí como varios literales. Es una
 * propiedad del rastreo y está declarada a propósito, porque explica que una cita heredada pueda
 * quedar partida entre dos fragmentos y no se lea como la familia que es.
 *
 * DOS FAMILIAS SE MIDEN SÓLO EN LITERALES QUE PARECEN SQL
 *
 * Los identificadores entre acentos graves y el operador JSON aparecen en texto que no es SQL
 * —mensajes, plantillas de Markdown, nombres de fichero—, y contarlos ahí haría ruido. Sólo se
 * cuentan dentro de un literal que declara un verbo de SQL.
 *
 * ESTE FICHERO ESTÁ FUERA DEL RASTRO, Y NO COMO ESCONDITE
 *
 * Cita las grafías que persigue porque es su **definición**, igual que el adaptador es el sitio
 * donde el dialecto vive por diseño. La guarda lo declara y comprueba además que aquí no se
 * ejecuta SQL —ni PDO, ni `query`, ni `exec`, ni `prepare`— de modo que la exclusión no pueda
 * tapar una sentencia.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: identificadores y código en inglés, documentación y mensajes en castellano.
 */
final class DialectCensus
{
    /**
     * Familias del **dialecto heredado**: prohibidas fuera de lo declarado.
     *
     * Cada patrón reconoce la construcción, no su mención: se ancla en la forma que el motor
     * retirado exige para que un texto que *nombra* la familia —un docblock, el propio `rule set`—
     * no cuente como dialecto. Los patrones son sensibles a mayúsculas donde la grafía es el
     * defecto: `information_schema.COLUMNS` en mayúsculas es el catálogo del motor retirado, y en
     * minúsculas es una vista que el motor único **sí** tiene.
     *
     * @var array<string, string>
     */
    public const LEGACY_FAMILIES = [
        // Identificador entre acentos graves (el motor único cita con comillas dobles).
        'backtick' => '/`[A-Za-z_][A-Za-z0-9_]*`/',
        // Upsert del motor retirado (el motor único declara `ON CONFLICT`).
        'upsert' => '/\bON\s+DUPLICATE\s+KEY\b/i',
        // `VALUES(columna)` dentro de una asignación (el motor único lee `EXCLUDED.columna`).
        'values' => '/=\s*VALUES\s*\(/i',
        // Aritmética temporal del motor retirado (el motor único usa `INTERVAL`).
        'temporal' => '/\b(?:DATE_ADD|DATE_SUB|ADDDATE|SUBDATE|DATEDIFF|TIMESTAMPDIFF|TIMESTAMPADD|STR_TO_DATE|CURDATE|CURTIME)\s*\(/i',
        // Extracción de JSON del motor retirado (el motor único encadena `->` y `->>`).
        'json' => '/\b(?:JSON_EXTRACT|JSON_UNQUOTE|JSON_SET|JSON_OBJECT|JSON_ARRAY|JSON_KEYS)\s*\(/i',
        // Funciones del motor retirado (el motor único tiene `current_database()`, `string_agg`, …).
        'text_function' => '/\b(?:IFNULL|GROUP_CONCAT|LAST_INSERT_ID|SUBSTRING_INDEX|FIND_IN_SET|UNIX_TIMESTAMP)\s*\(/i',
        'legacy_database' => '/\bDATABASE\s*\(\s*\)/i',
        // Catálogo de metadatos en su grafía heredada (el motor único lee `pg_*`).
        'catalog' => '/information_schema\.[A-Z]+|\b(?:TABLE_SCHEMA|COLUMN_TYPE|SEQ_IN_INDEX|NON_UNIQUE)\b|@@[A-Za-z_]+/',
        // Introspección de columnas del motor retirado (el motor único lee `pg_enum`).
        'show' => '/\bSHOW\s+(?:COLUMNS|TABLES|INDEXES|INDEX)\b/i',
        // Ventana de purga del Arnés en su forma heredada (la única forma es `set_config(...)`).
        'purge_window' => '/SET\s+@vendguard_purge\b/i',
    ];

    /**
     * Familias del **dialecto del motor único**: exigidas donde el sistema realiza la construcción.
     *
     * @var array<string, string>
     */
    public const ENGINE_FAMILIES = [
        'conflict' => '/\bON\s+CONFLICT\b/i',
        'excluded' => '/\bEXCLUDED\./',
        'json_operator' => '/->>?\s*[\'"]/',
        'interval_literal' => "/\bINTERVAL\s+'/i",
        'epoch_extract' => '/\bEXTRACT\s*\(\s*EPOCH\b/i',
        'catalog_view' => '/\bpg_(?:catalog|enum|indexes|index|trigger|constraint|class|attribute|type|tables|namespace)\b/i',
        'current_database' => '/\bcurrent_database\s*\(\s*\)/i',
        'purge_setting' => "/set_config\s*\(\s*'vendguard\.purge'/i",
    ];

    /**
     * Familias que sólo cuentan dentro de un literal que parece SQL.
     *
     * @var list<string>
     */
    public const SQL_GATED_FAMILIES = ['backtick', 'json_operator'];

    /** Verbos que hacen que un literal se lea como SQL. */
    private const SQL_VERBS = '/\b(?:SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|JOIN|INTO|VALUES|CREATE|ALTER|SET|RETURNING)\b/i';

    /**
     * Los literales de cadena de un fichero PHP, con comentarios, docblocks y código fuera.
     *
     * @return list<string>
     */
    public static function literals(string $path): array
    {
        $literals = [];
        $inHeredoc = false;
        $buffer = '';

        foreach (token_get_all((string)file_get_contents($path)) as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;

                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }
                if ($id === T_START_HEREDOC) {
                    $inHeredoc = true;
                    $buffer = '';
                    continue;
                }
                if ($id === T_END_HEREDOC) {
                    $literals[] = $buffer;
                    $inHeredoc = false;
                    $buffer = '';
                    continue;
                }
                if ($inHeredoc) {
                    $buffer .= $text;
                    continue;
                }
                if ($id === T_CONSTANT_ENCAPSED_STRING) {
                    $literals[] = self::unescape(substr($text, 1, -1));
                    continue;
                }
                if ($id === T_ENCAPSED_AND_WHITESPACE) {
                    $literals[] = $text;
                }
                // Los cuerpos de heredoc y nowdoc se toman tal cual: no llevan comillas escapadas.
                continue;
            }

            if ($inHeredoc) {
                $buffer .= $token;
            }
        }

        return $literals;
    }

    /**
     * Devuelve el texto que el literal representa, no su código fuente.
     *
     * Un literal de comillas simples escribe su propia comilla escapada (`\'`), y las familias que
     * reconocen una construcción por la comilla que la sigue —el operador JSON del motor, el intervalo
     * con literal, la ventana de purga— no la verían. El censo mide SQL, no su fuente, así que la
     * comilla se resuelve aquí una vez para todas las familias.
     */
    private static function unescape(string $raw): string
    {
        return str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], $raw);
    }

    /** ¿El literal declara un verbo de SQL? */
    public static function looksLikeSql(string $literal): bool
    {
        return preg_match(self::SQL_VERBS, $literal) === 1;
    }

    /**
     * ¿El literal declara esa familia? Aplica la misma puerta que el censo, de modo que un espécimen
     * se mide con la regla viva y no con una copia suya.
     */
    public static function flags(string $literal, string $family): bool
    {
        $patterns = self::LEGACY_FAMILIES + self::ENGINE_FAMILIES;
        if (!isset($patterns[$family])) {
            throw new InvalidArgumentException("Familia de dialecto no declarada: «{$family}».");
        }
        if (in_array($family, self::SQL_GATED_FAMILIES, true) && !self::looksLikeSql($literal)) {
            return false;
        }

        return preg_match($patterns[$family], $literal) === 1;
    }

    /**
     * Los ficheros PHP de la superficie rastreada, en rutas relativas a la raíz del proyecto.
     *
     * @param list<string> $directories
     * @param list<string> $excluded
     * @return list<string>
     */
    public static function surface(string $projectRoot, array $directories, array $excluded = []): array
    {
        $excluded = array_flip($excluded);
        $surface = [];

        foreach ($directories as $directory) {
            $base = $projectRoot . '/' . $directory;
            if (!is_dir($base)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($projectRoot))), '/');
                if (!isset($excluded[$relative])) {
                    $surface[] = $relative;
                }
            }
        }

        sort($surface);

        return $surface;
    }

    /**
     * Rastrea un conjunto de familias sobre los literales de los ficheros PHP de unos directorios.
     *
     * @param list<string>          $directories Directorios relativos a la raíz del proyecto.
     * @param array<string, string> $families    Familia => patrón.
     * @param list<string>          $excluded    Rutas relativas que quedan fuera del rastro.
     * @return array<string, array<string, int>> Fichero => familia => literales que la contienen.
     */
    public static function inventory(string $projectRoot, array $directories, array $families, array $excluded = []): array
    {
        $inventory = [];
        $excluded = array_flip($excluded);

        foreach ($directories as $directory) {
            $base = $projectRoot . '/' . $directory;
            if (!is_dir($base)) {
                continue;
            }

            $paths = [];
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
            sort($paths);

            foreach ($paths as $path) {
                $relative = ltrim(str_replace('\\', '/', substr($path, strlen($projectRoot))), '/');
                if (isset($excluded[$relative])) {
                    continue;
                }

                foreach (self::literals($path) as $literal) {
                    $isSql = self::looksLikeSql($literal);

                    foreach ($families as $family => $pattern) {
                        if (in_array($family, self::SQL_GATED_FAMILIES, true) && !$isSql) {
                            continue;
                        }
                        if (preg_match($pattern, $literal) === 1) {
                            $inventory[$relative][$family] = ($inventory[$relative][$family] ?? 0) + 1;
                        }
                    }
                }
            }
        }

        ksort($inventory);

        foreach ($inventory as &$families) {
            ksort($families);
        }
        unset($families);

        return $inventory;
    }

    /**
     * Suma por familia y total de un inventario.
     *
     * @param array<string, array<string, int>> $inventory
     * @return array{families: array<string, int>, files: int, literals: int}
     */
    public static function totals(array $inventory): array
    {
        $families = [];
        $literals = 0;

        foreach ($inventory as $fileFamilies) {
            foreach ($fileFamilies as $family => $count) {
                $families[$family] = ($families[$family] ?? 0) + $count;
                $literals += $count;
            }
        }

        ksort($families);

        return ['families' => $families, 'files' => count($inventory), 'literals' => $literals];
    }
}
