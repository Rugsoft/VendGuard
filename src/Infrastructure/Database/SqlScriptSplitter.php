<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

/**
 * SqlScriptSplitter
 *
 * Trocea un script SQL en sentencias individuales para poder ejecutarlas una a
 * una contra PDO.
 *
 * ## Por qué una a una y no de golpe
 * PDO::exec() con un fichero entero depende del protocolo de sentencias
 * múltiples, que no es universal: en este proyecto se midió un
 * SQLSTATE[HY000] General error: 1295 This command is not supported in the
 * prepared statement protocol yet al enviar un script completo. Ejecutar
 * sentencia a sentencia es lo que ya hacía el arranque del contenedor con el
 * DDL base, funciona igual en MariaDB, MySQL y TiDB, y permite informar del
 * error concretando qué sentencia falló.
 *
 * ## Qué respeta
 * - Comentarios de bloque y de línea (-- y #).
 * - Cadenas y identificadores entre comillas simples, dobles o invertidas.
 * - La comilla escapada duplicada, que es como SQL mete una comilla dentro de
 *   una cadena. Sin esto, el MESSAGE_TEXT de la migración 013 (que contiene un
 *   punto y coma dentro de un SIGNAL) se partía por la mitad.
 * - El escape por barra invertida mientras el servidor no corra con
 *   NO_BACKSLASH_ESCAPES.
 * - Bloques de sentencias compuestas (BEGIN ... END en CREATE TRIGGER),
 *   evitando que los puntos y coma internos dividan la sentencia antes de tiempo.
 *
 * Dogma Vanilla: sin dependencias, sin estado y sin tocar la base de datos.
 */
final class SqlScriptSplitter
{
    /**
     * Divide el script en sentencias, sin el punto y coma final de cada una.
     *
     * @param string $sql Contenido completo del fichero .sql.
     * @return list<string> Sentencias listas para PDO::exec() o PDO::query().
     */
    public static function split(string $sql): array
    {
        $sql = (string)preg_replace('!/\\*.*?\\*/!s', '', $sql);

        $keptLines = [];
        foreach (explode("\n", $sql) as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }
            $keptLines[] = $line;
        }

        $raw = implode("\n", $keptLines);
        $statements = [];
        $current = '';
        $quote = null;
        $blockDepth = 0;
        $length = strlen($raw);

        for ($index = 0; $index < $length; $index++) {
            $char = $raw[$index];

            if ($quote !== null) {
                if ($char === $quote) {
                    // El escape por barra invertida se comprueba ANTES que la
                    // duplicación, y no es un detalle de orden: en un literal
                    // con barra invertida seguida de comilla de cierre, mirar
                    // antes la duplicación se come el cierre.
                    if ($index > 0 && $raw[$index - 1] === '\\') {
                        $current .= $char;
                        continue;
                    }

                    // Comilla escapada por duplicación: sigue dentro de la cadena.
                    if ($index + 1 < $length && $raw[$index + 1] === $quote) {
                        $current .= $char . $raw[$index + 1];
                        $index++;
                        continue;
                    }

                    $quote = null;
                }

                $current .= $char;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            // Detección de bloques compuestos (BEGIN ... END) fuera de literales
            $tail = substr($raw, $index);
            if (preg_match('/^\\bBEGIN\\b/i', $tail)) {
                $blockDepth++;
                $current .= 'BEGIN';
                $index += 4;
                continue;
            }
            if (preg_match('/^\\bEND\\s+IF\\b/i', $tail, $m)) {
                $len = strlen($m[0]);
                $current .= $m[0];
                $index += $len - 1;
                continue;
            }
            if (preg_match('/^\\bEND\\b/i', $tail)) {
                if ($blockDepth > 0) {
                    $blockDepth--;
                }
                $current .= 'END';
                $index += 2;
                continue;
            }

            if ($char === ';' && $blockDepth === 0) {
                $statement = trim($current);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $remaining = trim($current);
        if ($remaining !== '') {
            $statements[] = $remaining;
        }

        return $statements;
    }
}
