<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Dialect;

use InvalidArgumentException;
use PDO;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * SqlDialectAdapter — punto único de traducción de dialecto **por operación** (RF-01.1,
 * RF-01.5, EARS-01.5, Constitución Art. I.3, T-HYBRID-25).
 *
 * **Por qué existe.** El módulo abandonó formalmente la premisa de «una consulta ANSI para
 * todo»: hay construcciones que **carecen** de forma estándar común y que ninguna redacción
 * en ANSI resuelve. Este adaptador es el único sitio donde vive esa traducción, de modo que
 * la **regla** se declara una vez y sólo cambian sus dos renderizados. Repartir la traducción
 * por los repositorios —o dejar que cada uno escriba su variante— es lo que este componente
 * prohíbe: duplicaría la definición de la regla y ninguna suite podría certificarla.
 *
 * **Qué traduce** (las tres familias que el proyecto declaró no portables):
 *
 * 1. **Cláusula de upsert.** PostgreSQL necesita declarar el objetivo del conflicto y leer los
 *    valores de la fila propuesta por su nombre (`ON CONFLICT (...) DO UPDATE SET c = EXCLUDED.c`);
 *    MariaDB no declara objetivo y lee esos valores por función (`ON DUPLICATE KEY UPDATE
 *    c = VALUES(c)`). Se ofrecen las tres formas que el proyecto usa de verdad: la que
 *    **reutiliza los valores insertados**, la que asigna **valores literales** y la que sólo
 *    **asegura la existencia** de la fila sin tocar nada más.
 * 2. **Extracción de JSON.** PostgreSQL encadena operadores sobre un tipo `json`/`jsonb`
 *    (`col -> 'a' ->> 'b'`); MariaDB llama a funciones y obliga a descomillar el resultado
 *    (`JSON_UNQUOTE(JSON_EXTRACT(col, '$.a.b'))`).
 * 3. **Aritmética de intervalos y diferencias temporales.** PostgreSQL escribe la aritmética
 *    con `INTERVAL` y la diferencia con `EXTRACT(EPOCH FROM ...)`; MariaDB usa `DATE_ADD`/
 *    `DATE_SUB` y `TIMESTAMPDIFF`. La equivalencia es exacta, incluido el truncamiento hacia
 *    cero que `TIMESTAMPDIFF` aplica y que `TRUNC` reproduce.
 *
 * **Qué no traduce, a propósito.** Todo lo que ya es común —`NOW()`, `CURRENT_DATE`,
 * `CURRENT_TIMESTAMP`, `COALESCE`, `SUM`, `ROUND`— se escribe igual en los dos motores y no
 * necesita pasar por aquí. El adaptador no envuelve lo portable: sólo lo que no lo es. Las
 * dos únicas grafías que sí normaliza son las que MariaDB admite con paréntesis y PostgreSQL
 * no (`CURRENT_DATE()`, `CURDATE()`, `CURRENT_TIMESTAMP()`), que pasan a su forma neutra.
 *
 * **Cómo se usa.** No abre conexiones ni conoce datos: es un renderizador puro y sin estado
 * más allá del motor. Los llamantes le entregan identificadores y valores **confiables** —los
 * suyos, no los de un usuario— y el adaptador los cita en la forma del motor. Un identificador
 * vacío, una unidad desconocida, un valor con comillas o un motor no soportado fallan en voz
 * alta antes de producir una sentencia: un SQL mal formado que viaja es peor que un error aquí.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas. Dualismo Lingüístico:
 * código en inglés, documentación y mensajes en castellano.
 */
final class SqlDialectAdapter
{
    /**
     * Marca de procedencia de una asignación de upsert: la columna se refresca con el valor
     * **propuesto** por la inserción (`VALUES(col)` en MariaDB, `EXCLUDED.col` en PostgreSQL).
     *
     * Se usa como valor en {@see upsertAssignments()}. Es una marca y no una cadena libre a
     * propósito: un `'NULL'` escrito por error asigna nulo, de modo que la intención tiene que
     * poder distinguirse de un literal sin ambigüedad.
     */
    public const PROPOSED_VALUE = '{PROPOSED}';

    /**
     * Segundos que contiene cada unidad temporal admitida.
     *
     * Es la tabla que hace exacta la equivalencia entre `TIMESTAMPDIFF` y `EXTRACT(EPOCH ...)`:
     * la unidad no es sólo una etiqueta, es el divisor de la resta de instantes.
     */
    private const UNIT_SECONDS = [
        'SECOND' => 1,
        'MINUTE' => 60,
        'HOUR' => 3600,
        'DAY' => 86400,
    ];

    /**
     * Grafías temporales que sólo un motor acepta, con su forma neutra.
     *
     * `CURRENT_DATE` y `NOW()` son válidas en los dos motores, así que normalizar aquí no
     * reescribe nada por capricho: convierte la grafía privativa en la que ambos entienden.
     */
    private const TEMPORAL_SPELLINGS = [
        'CURRENT_DATE()' => 'CURRENT_DATE',
        'CURDATE()' => 'CURRENT_DATE',
        'CURRENT_TIMESTAMP()' => 'CURRENT_TIMESTAMP',
        'NOW()' => 'NOW()',
        'CURRENT_DATE' => 'CURRENT_DATE',
        'CURRENT_TIMESTAMP' => 'CURRENT_TIMESTAMP',
    ];

    /** Columna propia o cualificada (`metadata`, `audit_log.metadata`). */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    /** Marcador de posición nombrado de PDO (`:period_days`). */
    private const PARAMETER_PATTERN = '/^:[A-Za-z_][A-Za-z0-9_]*$/';

    /** Clave de un camino JSON: se admite el guion porque el proyecto lo usa en sus claves. */
    private const JSON_KEY_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /** Valores literales admitidos en una asignación de upsert, ya renderizados. */
    private const LITERAL_PATTERN = '/^(NULL|TRUE|FALSE|-?[0-9]+(?:\.[0-9]+)?)$/i';
    private string $driver;

    /**
     * @param string $driver Motor activo: `mysql` (MariaDB/MySQL) o `pgsql` (PostgreSQL).
     *
     * @throws InvalidArgumentException Si el motor no tiene traducción declarada.
     */
    public function __construct(string $driver)
    {
        $normalized = strtolower(trim($driver));

        if (!in_array($normalized, [ConnectionFactory::DRIVER_MYSQL, ConnectionFactory::DRIVER_PGSQL], true)) {
            throw new InvalidArgumentException(sprintf(
                'Motor no admitido por el adaptador de dialecto («%s»): sólo %s y %s tienen traducción declarada (T-HYBRID-25).',
                $driver,
                ConnectionFactory::DRIVER_MYSQL,
                ConnectionFactory::DRIVER_PGSQL
            ));
        }

        $this->driver = $normalized;
    }

    /**
     * Adaptador del motor que sirve una conexión ya abierta.
     *
     * Es la vía natural para los repositorios, que siempre tienen su PDO a mano: el motor se
     * lee del propio driver en lugar de deducirse de la configuración o de una variable de
     * entorno que podría no coincidir con la conexión viva.
     */
    public static function forConnection(PDO $pdo): self
    {
        return new self((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /** Motor activo del adaptador (`mysql` o `pgsql`). */
    public function driver(): string
    {
        return $this->driver;
    }

    public function isPostgres(): bool
    {
        return $this->driver === ConnectionFactory::DRIVER_PGSQL;
    }

    public function isMysql(): bool
    {
        return $this->driver === ConnectionFactory::DRIVER_MYSQL;
    }

    /**
     * Cita un identificador —columna propia o cualificada— en la forma del motor.
     *
     * MariaDB delimita con acentos graves y PostgreSQL con comillas dobles. Se cita **siempre**,
     * aunque el nombre no lo necesite: un identificador que hoy no es palabra reservada puede
     * serlo mañana, y la cita uniforme evita que la sentencia dependa de esa casualidad.
     *
     * @throws InvalidArgumentException Si el identificador está vacío o no es un nombre simple
     *   o cualificado.
     */
    public function quoteIdentifier(string $identifier): string
    {
        $trimmed = trim($identifier);

        if ($trimmed === '') {
            throw new InvalidArgumentException('El adaptador de dialecto no admite un identificador vacío (T-HYBRID-25).');
        }

        if (preg_match(self::IDENTIFIER_PATTERN, $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Identificador no admitido («%s»): sólo se aceptan nombres simples o cualificados, nunca expresiones ni fragmentos de SQL (T-HYBRID-25).',
                $identifier
            ));
        }

        $quote = $this->isPostgres() ? '"' : '`';

        return implode('.', array_map(
            static fn (string $part): string => $quote . $part . $quote,
            explode('.', $trimmed)
        ));
    }

    /**
     * Familia 1 · Cláusula de upsert que **reutiliza los valores propuestos** por la inserción.
     *
     * Es la forma del sembrado idempotente: la fila se inserta y, si la clave ya existía, cada
     * columna se refresca con el valor que traía la propuesta (`VALUES(col)` / `EXCLUDED.col`).
     *
     * Es azúcar sobre {@see upsertAssignments()} para el caso mayoritario —afirmar la regla
     * entera con la fila propuesta— y existe con nombre propio porque así se lee la intención
     * en el llamante: «refresca estas columnas con lo que venía en la inserción».
     *
     * @param list<string> $conflictColumns Columnas que identifican la fila: en PostgreSQL son
     *   el objetivo obligatorio del conflicto, y deben corresponder a una clave única real. En
     *   MariaDB se aceptan para que la **misma** llamada describa la misma regla en los dos
     *   motores, aunque el motor las deduzca por sí mismo.
     * @param list<string> $updateColumns Columnas que se refrescan con el valor propuesto.
     *
     * @throws InvalidArgumentException Si alguna lista está vacía o un nombre no es válido.
     */
    public function upsertFromInsertedRow(array $conflictColumns, array $updateColumns): string
    {
        $proposed = [];
        foreach ($this->requireNonEmptyColumns($updateColumns, 'las columnas a refrescar') as $column) {
            $proposed[$column] = self::PROPOSED_VALUE;
        }

        return $this->upsertAssignments($conflictColumns, $proposed);
    }

    /**
     * Familia 1 · Cláusula de upsert con **asignaciones de procedencia mixta**.
     *
     * Es la forma que el catálogo declara de verdad, y por eso es la forma canónica: al
     * reencontrar la fila, unas columnas se refrescan con el valor propuesto por la inserción
     * y otras se fijan a un literal. El catálogo de sedes, máquinas y usuarios hace exactamente
     * eso —refresca sus campos de contacto y sanea `deleted_at = NULL`—, y separarlo en dos
     * métodos no describiría la regla: la regla es **una sola cláusula**.
     *
     * @param list<string> $conflictColumns Columnas que identifican la fila (objetivo del
     *   conflicto en PostgreSQL).
     * @param array<string, string> $assignments Columna => procedencia: {@see self::PROPOSED_VALUE}
     *   para copiar el valor propuesto, o un literal ya renderizado (`NULL`, `TRUE`, `FALSE`,
     *   números) o un marcador de posición (`:is_active`) que la sentencia enlazará después.
     *
     * @throws InvalidArgumentException Si la clave está vacía, si no hay ninguna asignación o
     *   si un valor no es una de las tres procedencias declaradas.
     */
    public function upsertAssignments(array $conflictColumns, array $assignments): string
    {
        $conflict = $this->quotedColumnList($conflictColumns, 'la clave del upsert');

        if ($assignments === []) {
            throw new InvalidArgumentException('El upsert del adaptador de dialecto necesita al menos una asignación (T-HYBRID-25).');
        }

        $rendered = [];
        foreach ($assignments as $column => $value) {
            $columnName = $this->quoteIdentifier((string)$column);
            $provenance = trim((string)$value);

            if ($provenance === self::PROPOSED_VALUE) {
                $rendered[] = $this->isPostgres()
                    ? sprintf('%s = EXCLUDED.%s', $columnName, $columnName)
                    : sprintf('%s = VALUES(%s)', $columnName, $columnName);
                continue;
            }

            if (preg_match(self::LITERAL_PATTERN, $provenance) !== 1 && preg_match(self::PARAMETER_PATTERN, $provenance) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Valor no admitido en la asignación del upsert de «%s»: sólo se aceptan literales (NULL, TRUE, FALSE, números), un marcador de posición o la marca de procedencia propuesta (T-HYBRID-25).',
                    (string)$column
                ));
            }

            $rendered[] = $columnName . ' = ' . $provenance;
        }

        $clause = implode(', ', $rendered);

        return $this->isPostgres()
            ? sprintf('ON CONFLICT (%s) DO UPDATE SET %s', implode(', ', $conflict), $clause)
            : 'ON DUPLICATE KEY UPDATE ' . $clause;
    }

    /**
     * Familia 1 · Cláusula de upsert que sólo **asegura la existencia** de la fila.
     *
     * Es la forma de las tablas cuyo reencuentro no refresca nada (`spare_part_compatibilities`,
     * y la fila *singleton* de `route_settings`, que en el motor nuevo se resuelve además con la
     * guarda portable `WHERE NOT EXISTS` declarada en las semillas).
     *
     * **Por qué MariaDB no escribe `DO NOTHING`.** No existe: la forma que el motor local declara
     * —y que el sembrado vivo ya usa— es reafirmar cada columna de la clave con el valor
     * propuesto. No es un truco: si el conflicto se ha disparado, esas columnas **ya** valen lo
     * propuesto en la fila existente, de modo que la asignación se resuelve en cero cambios y
     * ningún dato se pisa. Se descarta `INSERT IGNORE` porque no ignora sólo el duplicado: degrada
     * a aviso buena parte de los errores del motor, y esta aplicación quiere los fallos ruidosos.
     *
     * @param list<string> $conflictColumns Columnas que identifican la fila: en PostgreSQL son
     *   el objetivo del conflicto y en MariaDB, además, las que se reafirman.
     *
     * @throws InvalidArgumentException Si la clave está vacía o un nombre no es válido.
     */
    public function upsertDoNothing(array $conflictColumns): string
    {
        $conflict = $this->quotedColumnList($conflictColumns, 'la clave del upsert');

        if ($this->isPostgres()) {
            return sprintf('ON CONFLICT (%s) DO NOTHING', implode(', ', $conflict));
        }

        $reaffirmed = array_map(
            static fn (string $column): string => sprintf('%s = VALUES(%s)', $column, $column),
            $conflict
        );

        return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $reaffirmed);
    }

    /**
     * Familia 2 · Extracción de un camino JSON como texto.
     *
     * Equivale a descomillar el valor extraído: `$."order_code"` para MariaDB y la cadena de
     * operadores para PostgreSQL. Un camino de varios tramos se encadena (`a.b`), y la última
     * extracción es la que devuelve texto en lugar de JSON.
     *
     * @param string $column Columna (propia o cualificada) que guarda el JSON.
     * @param string $path Camino de claves separadas por puntos (`order_code`, `payload.a`),
     *   sin el prefijo `$.` que ya añade el renderizado de MariaDB.
     *
     * @throws InvalidArgumentException Si el camino está vacío o una clave no es válida.
     */
    public function jsonExtract(string $column, string $path): string
    {
        $identifier = $this->quoteIdentifier($column);
        $segments = $this->jsonPathSegments($path);

        if ($this->isPostgres()) {
            $last = array_pop($segments);
            $walk = $identifier;
            foreach ($segments as $segment) {
                $walk .= sprintf(" -> '%s'", $segment);
            }

            return $last === null ? $walk : sprintf("(%s ->> '%s')", $walk, $last);
        }

        return sprintf("JSON_UNQUOTE(JSON_EXTRACT(%s, '$.%s'))", $identifier, implode('.', $segments));
    }

    /**
     * Familia 3 · Resta un intervalo a un instante (`DATE_SUB` / `- INTERVAL`).
     *
     * @param string $expression Instante de partida: una columna o una grafía temporal
     *   (`NOW()`, `CURRENT_DATE()`, `CURRENT_TIMESTAMP()`), que se normaliza a la forma que
     *   ambos motores entienden.
     * @param int|string $amount Cantidad: un entero o un marcador de posición que la
     *   sentencia enlazará después. Nunca texto libre.
     * @param string $unit Unidad temporal (`SECOND`, `MINUTE`, `HOUR`, `DAY`).
     */
    public function subtractInterval(string $expression, int|string $amount, string $unit): string
    {
        return $this->interval('subtract', $expression, $amount, $unit);
    }

    /**
     * Familia 3 · Suma un intervalo a un instante (`DATE_ADD` / `+ INTERVAL`).
     *
     * @see subtractInterval() para el contrato de los tres parámetros.
     */
    public function addInterval(string $expression, int|string $amount, string $unit): string
    {
        return $this->interval('add', $expression, $amount, $unit);
    }

    /**
     * Familia 3 · Diferencia entre dos instantes en la unidad pedida, truncada hacia cero.
     *
     * Reproduce la semántica exacta de `TIMESTAMPDIFF`: el número de unidades **completas**
     * transcurridas, con truncamiento hacia cero también en diferencias negativas.
     *
     * @param string $unit Unidad del resultado (`SECOND`, `MINUTE`, `HOUR`, `DAY`).
     * @param string $startColumn Instante inicial (columna propia o cualificada).
     * @param string $endColumn Instante final (columna propia o cualificada).
     *
     * @throws InvalidArgumentException Si la unidad no está admitida.
     */
    public function timestampDifference(string $unit, string $startColumn, string $endColumn): string
    {
        $unitSpec = $this->unitSeconds($unit);
        $start = $this->quoteIdentifier($startColumn);
        $end = $this->quoteIdentifier($endColumn);

        if ($this->isMysql()) {
            return sprintf('TIMESTAMPDIFF(%s, %s, %s)', $unitSpec['unit'], $start, $end);
        }

        $elapsed = sprintf('TRUNC(EXTRACT(EPOCH FROM (%s - %s))', $end, $start);

        return $unitSpec['seconds'] === 1 ? $elapsed . ')' : sprintf('%s / %d)', $elapsed, $unitSpec['seconds']);
    }

    /**
     * Renderizado común de la aritmética de intervalos.
     *
     * MariaDB exige la función envolvente (`DATE_ADD`/`DATE_SUB`) y PostgreSQL la aritmética
     * directa con `INTERVAL`. En PostgreSQL la cantidad se castea a `double precision` a
     * propósito: un parámetro enlazado llega sin tipo declarado y el motor no resolvería la
     * multiplicación sin esa pista, de modo que la cota quedaría ambigua en tiempo de
     * preparación.
     */
    private function interval(string $operation, string $expression, int|string $amount, string $unit): string
    {
        $unitSpec = $this->unitSeconds($unit);
        $amountSql = $this->renderAmount($amount);

        if ($this->isMysql()) {
            return sprintf(
                '%s(%s, INTERVAL %s %s)',
                $operation === 'add' ? 'DATE_ADD' : 'DATE_SUB',
                $this->temporalExpression($expression),
                $amountSql,
                $unitSpec['unit']
            );
        }

        return sprintf(
            '(%s %s ((%s)::double precision * INTERVAL \'1 %s\'))',
            $this->temporalExpression($expression),
            $operation === 'add' ? '+' : '-',
            $amountSql,
            strtolower($unitSpec['unit'])
        );
    }

    /**
     * Unidad temporal admitida, con su equivalencia en segundos.
     *
     * @return array{unit: string, seconds: int}
     */
    private function unitSeconds(string $unit): array
    {
        $normalized = strtoupper(trim($unit));

        if (!array_key_exists($normalized, self::UNIT_SECONDS)) {
            throw new InvalidArgumentException(sprintf(
                'Unidad temporal no admitida («%s»): el adaptador sólo traduce %s (T-HYBRID-25).',
                $unit,
                implode(', ', array_keys(self::UNIT_SECONDS))
            ));
        }

        return ['unit' => $normalized, 'seconds' => self::UNIT_SECONDS[$normalized]];
    }

    /**
     * Cantidad de un intervalo: entero literal o marcador de posición, nunca texto libre.
     */
    private function renderAmount(int|string $amount): string
    {
        if (is_int($amount)) {
            return (string)$amount;
        }

        $trimmed = trim($amount);
        if (preg_match(self::PARAMETER_PATTERN, $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Cantidad no admitida («%s») en un intervalo: sólo se aceptan enteros o un marcador de posición de PDO (T-HYBRID-25).',
                $amount
            ));
        }

        return $trimmed;
    }

    /**
     * Instante de partida: una columna citada o una grafía temporal de la lista neutra.
     *
     * @throws InvalidArgumentException Si no es una de las dos cosas.
     */
    private function temporalExpression(string $expression): string
    {
        $trimmed = trim($expression);

        if ($trimmed === '') {
            throw new InvalidArgumentException('El adaptador de dialecto no admite un instante vacío (T-HYBRID-25).');
        }

        $canonical = self::TEMPORAL_SPELLINGS[strtoupper(str_replace(' ', '', $trimmed))] ?? null;
        if ($canonical !== null) {
            return $canonical;
        }

        return $this->quoteIdentifier($trimmed);
    }

    /**
     * Trocea y valida el camino JSON, que es el único dato con forma libre que entra aquí.
     *
     * @return list<string>
     */
    private function jsonPathSegments(string $path): array
    {
        $trimmed = trim($path, " \t\n\r\0\x0B.");
        if ($trimmed === '') {
            throw new InvalidArgumentException('El adaptador de dialecto necesita un camino JSON no vacío (T-HYBRID-25).');
        }

        $segments = explode('.', $trimmed);
        foreach ($segments as $segment) {
            if (preg_match(self::JSON_KEY_PATTERN, $segment) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Clave JSON no admitida («%s») en el camino «%s»: el adaptador no compone caminos con comillas ni operadores (T-HYBRID-25).',
                    $segment,
                    $path
                ));
            }
        }

        return $segments;
    }

    /**
     * Lista de columnas citadas, con el suelo de que no esté vacía.
     *
     * @param list<string> $columns
     * @return list<string>
     */
    private function quotedColumnList(array $columns, string $what): array
    {
        return array_map(
            fn (string $column): string => $this->quoteIdentifier($column),
            $this->requireNonEmptyColumns($columns, $what)
        );
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function requireNonEmptyColumns(array $columns, string $what): array
    {
        if ($columns === []) {
            throw new InvalidArgumentException(sprintf(
                'El adaptador de dialecto necesita %s, y la lista llegó vacía (T-HYBRID-25).',
                $what
            ));
        }

        return array_values($columns);
    }
}
