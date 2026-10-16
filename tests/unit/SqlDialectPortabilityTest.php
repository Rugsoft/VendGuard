<?php

declare(strict_types=1);

/**
 * SqlDialectPortabilityTest — guarda del dialecto: **prohíbe** el del motor retirado y **exige** el único.
 *
 * Tarea: T-13-06 (invierte el inventario de T-HYBRID-25) · Requisitos: EARS-13.4, EARS-13.10
 * Constitución Art. I.3 · Contrato: [`single_engine_dialect_contract.md`](../../specs/technical/single_engine_dialect_contract.md)
 *
 * QUÉ CERTIFICA, Y EN QUÉ SENTIDO
 *
 *   1. **El punto único de traducción sigue siendo único.** Mientras la rama del motor retirado
 *      exista, el adaptador la renderiza y esta guarda la fija carácter a carácter: una regla, dos
 *      formas. Esa segunda forma es el **dialecto heredado declarado**, con dueño (T-13-14/T-13-16:
 *      la rama muere con su último consumidor) y no una tolerancia. Cuando la rama desaparezca, este
 *      grupo se reduce solo: su censo está declarado y no admite cifras que ya no sean ciertas.
 *
 *   2. **La ejecución real contra el motor vivo** (grupo 3) y **la conformidad con la tabla de upsert
 *      que declaran las semillas** (grupo 4) se conservan tal cual: son la certificación semántica del
 *      adaptador, y el motor sobre el que corren es el que sirve producción.
 *
 *   3. **La frontera, invertida (grupos 5, 6 y 7).** El inventario de excepciones toleradas se
 *      sustituye por una **prohibición con declaración**: el dialecto heredado no puede aparecer en
 *      ninguna de las cuatro superficies rastreadas —`src`, `database`, `bin` y **`tests`**— salvo en
 *      los sitios declarados, y cada sitio declarado tiene que decir **qué es** (una deuda que un lote
 *      todavía debe, una guarda que nombra el dialecto para prohibirlo, un contrato que compara contra
 *      el artefacto heredado que aún no se ha retirado, o un sitio fuera del alcance del motor único)
 *      y **quién lo cierra**. Una deuda cuyo dueño ya está cerrado en `tasks.md` pone la guarda en
 *      rojo: una excepción con el plazo cumplido es una excepción que ya debería estar pagada.
 *
 *      La prohibición se mide, no se declara en prosa: el censo de la clase —**literales de cadena
 *      tokenizados**, comentarios y docblocks fuera— lo publica `tests/Support/DialectCensus.php`,
 *      de modo que la guarda y su medición de cierre son el mismo *rule set*. El censo cubre la
 *      superficie entera y compara **exactamente**: un literal heredado nuevo, un literal que
 *      desaparece sin retirar su entrada, o una cifra que se queda atrás, ponen la suite en rojo.
 *
 *   4. **El detector distingue (grupo 6).** El censo se prueba contra especímenes: cada familia
 *      heredada tiene el suyo y **se dispara**; cada forma del motor único se comprueba contra las
 *      nueve familias prohibidas y **no se dispara**; la grafía minúscula del catálogo que el motor
 *      único sí tiene no cuenta y la mayúscula sí; un comentario no cuenta; y una cita heredada
 *      dentro de un **fragmento interpolado** se ve —que es como se nombra por primera vez el sitio
 *      de `CoordinatorPreventiveDetailApiTest`, invisible al censo del lote anterior—.
 *
 *   5. **El dialecto del motor único se exige (grupo 7).** No basta con que el heredado no esté: las
 *      construcciones que el sistema realiza tienen que estar escritas en la grafía que el motor
 *      entiende, y la ventana de purga del arnés tiene **una sola forma** (`set_config('vendguard.purge', …)`).
 *
 * QUÉ NO DECIDE
 *
 *   El porte de las deudas declaradas: cada una pertenece a su lote (T-13-09 a T-13-14) y se mide
 *   cuando le toca. Esta guarda las **nombra** con su dueño y su cifra exacta, que es lo que permite
 *   saber si el lote avanzó; mezclar aquí su reparación habría ocultado qué se estaba midiendo
 *   (EARS-13.10). Tampoco decide el respaldo SQLite del mapa territorial (`CoordinatorRouteMapController`),
 *   fuera del alcance declarado del adaptador, ni la retirada de la rama heredada del adaptador.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas ni bundlers.
 * Dualismo Lingüístico: identificadores y código en inglés, documentación y mensajes en castellano.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/DialectCensus.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

$projectRoot = dirname(__DIR__, 2);

// =====================================================================
// Arnés mínimo de aserciones (mismo contrato que el resto de la batería)
// =====================================================================

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - SqlDialectPortabilityTest (T-13-06 · guarda invertida)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
        return;
    }
    echo "  [FAIL] {$caseTitle}\n";
    if ($message !== '') {
        echo "         Motivo: {$message}\n";
    }
    $failures++;
};

/**
 * Afirma que la operación rechaza, en voz alta y en castellano, con un fragmento identificable.
 * El adaptador no produce SQL dudoso: falla antes de escribirlo, y esa es la conducta que se fija.
 */
$assertThrows = function (string $caseTitle, callable $operation, string $expectedFragment) use ($assert): void {
    $thrown = null;
    try {
        $operation();
    } catch (InvalidArgumentException $e) {
        $thrown = $e;
    } catch (Throwable $e) {
        $assert($caseTitle, false, 'Lanzó ' . $e::class . ' en lugar de InvalidArgumentException: ' . $e->getMessage());
        return;
    }

    if ($thrown === null) {
        $assert($caseTitle, false, 'No lanzó ninguna excepción: la construcción inválida se aceptó en silencio.');
        return;
    }

    $assert(
        $caseTitle,
        str_contains($thrown->getMessage(), $expectedFragment),
        sprintf('El mensaje «%s» no contiene «%s».', $thrown->getMessage(), $expectedFragment)
    );
};

$mysql = new SqlDialectAdapter('mysql');
$pgsql = new SqlDialectAdapter('pgsql');

// =====================================================================
// GRUPO 1 — Los dos renderizados de cada construcción sin forma común
// =====================================================================

echo "--- Grupo 1: los dos renderizados, carácter a carácter ---\n";

// 1.1-1.2 · Upsert que reutiliza la fila propuesta: la forma del sembrado idempotente.
$assert(
    '1.1 MariaDB refresca con VALUES() y no declara objetivo de conflicto',
    $mysql->upsertFromInsertedRow(['ticket_code'], ['machine_id', 'status'])
        === 'ON DUPLICATE KEY UPDATE `machine_id` = VALUES(`machine_id`), `status` = VALUES(`status`)',
    $mysql->upsertFromInsertedRow(['ticket_code'], ['machine_id', 'status'])
);
$assert(
    '1.2 PostgreSQL declara el objetivo del conflicto y lee la fila propuesta por EXCLUDED',
    $pgsql->upsertFromInsertedRow(['ticket_code'], ['machine_id', 'status'])
        === 'ON CONFLICT ("ticket_code") DO UPDATE SET "machine_id" = EXCLUDED."machine_id", "status" = EXCLUDED."status"',
    $pgsql->upsertFromInsertedRow(['ticket_code'], ['machine_id', 'status'])
);

// 1.3-1.5 · Asignaciones literales y de procedencia mixta: el resucitado lógico del catálogo.
$assert(
    '1.3 MariaDB asigna literales ya renderizados sin copiar la fila propuesta',
    $mysql->upsertAssignments(['email_key'], ['is_active' => '1', 'deleted_at' => 'NULL'])
        === 'ON DUPLICATE KEY UPDATE `is_active` = 1, `deleted_at` = NULL',
    $mysql->upsertAssignments(['email_key'], ['is_active' => '1', 'deleted_at' => 'NULL'])
);
$assert(
    '1.4 PostgreSQL mantiene su objetivo de conflicto con la misma asignación literal',
    $pgsql->upsertAssignments(['email_key'], ['is_active' => '1', 'deleted_at' => 'NULL'])
        === 'ON CONFLICT ("email_key") DO UPDATE SET "is_active" = 1, "deleted_at" = NULL',
    $pgsql->upsertAssignments(['email_key'], ['is_active' => '1', 'deleted_at' => 'NULL'])
);
$assert(
    '1.5 La procedencia mixta —fila propuesta y literal— convive en una sola cláusula',
    $pgsql->upsertAssignments(
        ['site_code_key'],
        ['site_code' => SqlDialectAdapter::PROPOSED_VALUE, 'deleted_at' => 'NULL']
    ) === 'ON CONFLICT ("site_code_key") DO UPDATE SET "site_code" = EXCLUDED."site_code", "deleted_at" = NULL'
        && $mysql->upsertAssignments(
            ['site_code_key'],
            ['site_code' => SqlDialectAdapter::PROPOSED_VALUE, 'deleted_at' => 'NULL']
        ) === 'ON DUPLICATE KEY UPDATE `site_code` = VALUES(`site_code`), `deleted_at` = NULL'
);

// 1.6-1.8 · Upsert que sólo asegura la existencia de la fila (el «DO NOTHING» declarado).
$assert(
    '1.6 MariaDB reafirma cada columna de la clave con su valor propuesto (no existe DO NOTHING)',
    $mysql->upsertDoNothing(['spare_part_id', 'machine_model'])
        === 'ON DUPLICATE KEY UPDATE `spare_part_id` = VALUES(`spare_part_id`), `machine_model` = VALUES(`machine_model`)',
    $mysql->upsertDoNothing(['spare_part_id', 'machine_model'])
);
$assert(
    '1.7 PostgreSQL declara DO NOTHING, que es la forma nativa de la misma regla',
    $pgsql->upsertDoNothing(['spare_part_id', 'machine_model'])
        === 'ON CONFLICT ("spare_part_id", "machine_model") DO NOTHING',
    $pgsql->upsertDoNothing(['spare_part_id', 'machine_model'])
);
$assert(
    '1.8 La clave de una sola columna reproduce la forma viva del sembrado local (id = VALUES(id))',
    $mysql->upsertDoNothing(['id']) === 'ON DUPLICATE KEY UPDATE `id` = VALUES(`id`)',
    $mysql->upsertDoNothing(['id'])
);

// 1.9-1.11 · Extracción de JSON como texto.
$assert(
    '1.9 MariaDB descomilla con JSON_UNQUOTE(JSON_EXTRACT(...)) y camina con $.',
    $mysql->jsonExtract('audit_log.metadata', 'order_code')
        === "JSON_UNQUOTE(JSON_EXTRACT(`audit_log`.`metadata`, '$.order_code'))",
    $mysql->jsonExtract('audit_log.metadata', 'order_code')
);
$assert(
    '1.10 PostgreSQL encadena operadores y cierra con ->> para obtener texto',
    $pgsql->jsonExtract('audit_log.metadata', 'order_code')
        === '("audit_log"."metadata" ->> \'order_code\')',
    $pgsql->jsonExtract('audit_log.metadata', 'order_code')
);
$assert(
    '1.11 Un camino anidado camina por tramos en los dos motores antes de extraer el último',
    $pgsql->jsonExtract('payload', 'payload.order_code')
        === '("payload" -> \'payload\' ->> \'order_code\')'
        && $mysql->jsonExtract('payload', 'payload.order_code')
            === "JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.payload.order_code'))"
);

// 1.12-1.14 · Aritmética de intervalos.
$assert(
    '1.12 MariaDB resta con DATE_SUB y acepta el marcador de posición de PDO',
    $mysql->subtractInterval('NOW()', ':period_days', 'DAY')
        === 'DATE_SUB(NOW(), INTERVAL :period_days DAY)',
    $mysql->subtractInterval('NOW()', ':period_days', 'DAY')
);
$assert(
    '1.13 PostgreSQL resta con la aritmética de INTERVAL y castea la cantidad a double precision',
    $pgsql->subtractInterval('NOW()', ':period_days', 'DAY')
        === "(NOW() - ((:period_days)::double precision * INTERVAL '1 day'))",
    $pgsql->subtractInterval('NOW()', ':period_days', 'DAY')
);
$assert(
    '1.14 La suma normaliza además las grafías privativas (CURRENT_DATE() pasa a CURRENT_DATE)',
    $mysql->addInterval('CURRENT_DATE()', 5, 'DAY') === 'DATE_ADD(CURRENT_DATE, INTERVAL 5 DAY)'
        && $pgsql->addInterval('CURRENT_DATE()', 5, 'DAY')
            === "(CURRENT_DATE + ((5)::double precision * INTERVAL '1 day'))",
    $mysql->addInterval('CURRENT_DATE()', 5, 'DAY')
);

// 1.15-1.17 · Diferencias temporales, con el truncamiento que las iguala.
$assert(
    '1.15 MariaDB usa TIMESTAMPDIFF con la unidad y las dos columnas citadas',
    $mysql->timestampDifference('MINUTE', 'created_at', 'resolved_at')
        === 'TIMESTAMPDIFF(MINUTE, `created_at`, `resolved_at`)',
    $mysql->timestampDifference('MINUTE', 'created_at', 'resolved_at')
);
$assert(
    '1.16 PostgreSQL divide la época por la unidad para reproducir la misma cifra entera',
    $pgsql->timestampDifference('MINUTE', 'created_at', 'resolved_at')
        === 'TRUNC(EXTRACT(EPOCH FROM ("resolved_at" - "created_at")) / 60)',
    $pgsql->timestampDifference('MINUTE', 'created_at', 'resolved_at')
);
$assert(
    '1.17 En segundos no hay divisor: la época ya es la unidad pedida',
    $pgsql->timestampDifference('SECOND', 'i.created_at', 'i.resolved_at')
        === 'TRUNC(EXTRACT(EPOCH FROM ("i"."resolved_at" - "i"."created_at")))'
        && $mysql->timestampDifference('SECOND', 'i.created_at', 'i.resolved_at')
            === 'TIMESTAMPDIFF(SECOND, `i`.`created_at`, `i`.`resolved_at`)'
);

// 1.18-1.19 · Cita de identificadores: la cuarta familia, y la que impide que el dialecto se cuele
//             como «comillas» en lugar de como funciones.
$assert(
    '1.18 MariaDB cita con acentos graves, también en nombres cualificados',
    $mysql->quoteIdentifier('audit_log.metadata') === '`audit_log`.`metadata`'
        && $mysql->quoteIdentifier('site_code_key') === '`site_code_key`'
);
$assert(
    '1.19 PostgreSQL cita con comillas dobles, que en MariaDB no serían un identificador',
    $pgsql->quoteIdentifier('audit_log.metadata') === '"audit_log"."metadata"'
        && $pgsql->quoteIdentifier('site_code_key') === '"site_code_key"'
);

// 1.20-1.21 · El motor se lee de la conexión viva, no de una suposición de configuración.
$livePdo = ConnectionFactory::getConnection();
$liveDialect = SqlDialectAdapter::forConnection($livePdo);
$assert(
    '1.20 forConnection toma el motor del propio PDO',
    $liveDialect->driver() === (string)$livePdo->getAttribute(PDO::ATTR_DRIVER_NAME),
    'driver del adaptador: ' . $liveDialect->driver()
);
$assert(
    '1.21 Los dos motores se distinguen por predicado, con el driver como única fuente',
    $mysql->isMysql() && !$mysql->isPostgres() && $pgsql->isPostgres() && !$pgsql->isMysql()
);

// =====================================================================
// GRUPO 2 — Rechazo en voz alta: el adaptador no produce SQL dudoso
// =====================================================================

echo "\n--- Grupo 2: rechazo en voz alta de lo que no sabe traducir ---\n";

/**
 * Doble de conexión: declara un motor que el adaptador no admite sin necesitar su driver.
 * Es la vía para certificar el fallo cerrado de `forConnection()` sin depender de que el puesto
 * tenga SQLite instalado.
 */
final class FakeSqliteConnection extends PDO
{
    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'sqlite' : null;
    }
}

$assertThrows(
    '2.1 Un motor sin traducción declarada es rechazado',
    static fn (): SqlDialectAdapter => new SqlDialectAdapter('sqlite'),
    'Motor no admitido'
);
$assertThrows(
    '2.2 El rechazo nombra la tarea que fijó el contrato del adaptador (la retirada de la rama la enmienda)',
    static fn (): SqlDialectAdapter => new SqlDialectAdapter('oracle'),
    'T-HYBRID-25'
);
$assertThrows(
    '2.3 forConnection rechaza una conexión de un tercer motor en lugar de traducir a ciegas',
    static fn (): SqlDialectAdapter => SqlDialectAdapter::forConnection(new FakeSqliteConnection()),
    'Motor no admitido'
);
$assertThrows(
    '2.4 Un identificador vacío no se cita: se rechaza',
    static fn (): string => $mysql->quoteIdentifier('   '),
    'identificador vacío'
);
$assertThrows(
    '2.5 Un identificador que no es un nombre —una expresión— se rechaza',
    static fn (): string => $mysql->quoteIdentifier('a, DROP'),
    'Identificador no admitido'
);
$assertThrows(
    '2.6 La lista de columnas a refrescar no puede llegar vacía',
    static fn (): string => $mysql->upsertFromInsertedRow(['id'], []),
    'la lista llegó vacía'
);
$assertThrows(
    '2.7 Una clave de conflicto vacía no produce un upsert a medias',
    static fn (): string => $pgsql->upsertDoNothing([]),
    'la lista llegó vacía'
);
$assertThrows(
    '2.8 Un upsert sin ninguna asignación se rechaza',
    static fn (): string => $mysql->upsertAssignments(['id'], []),
    'al menos una asignación'
);
$assertThrows(
    '2.9 Una unidad temporal no declarada se rechaza en lugar de heredarse del motor',
    static fn (): string => $mysql->subtractInterval('NOW()', 1, 'WEEK'),
    'Unidad temporal no admitida'
);
$assertThrows(
    '2.10 La cantidad de un intervalo no admite texto libre',
    static fn (): string => $mysql->subtractInterval('NOW()', '1 DAY); DROP TABLE incidents --', 'DAY'),
    'Cantidad no admitida'
);
$assertThrows(
    '2.11 Una asignación de upsert no admite expresiones, sólo literales, parámetros o la marca',
    static fn (): string => $mysql->upsertAssignments(['id'], ['updated_at' => 'NOW()']),
    'Valor no admitido'
);
$assertThrows(
    '2.12 Un camino JSON vacío se rechaza',
    static fn (): string => $mysql->jsonExtract('metadata', '  '),
    'camino JSON no vacío'
);
$assertThrows(
    '2.13 Una clave JSON no compone caminos con comillas ni operadores',
    static fn (): string => $mysql->jsonExtract('metadata', "a' OR '1'='1"),
    'Clave JSON no admitida'
);

// =====================================================================
// GRUPO 3 — Ejecución real contra el motor vivo
// =====================================================================

echo "\n--- Grupo 3: ejecución real contra el motor vivo (" . $liveDialect->driver() . ") ---\n";

$probeTable = 'vg_dialect_probe';

/**
 * Trocea una diferencia de segundos en unidades completas con truncamiento hacia cero, que es
 * la semántica que `TIMESTAMPDIFF` y `TRUNC(EXTRACT(EPOCH ...) / n)` comparten.
 */
$oracleDifference = static fn (int $seconds, int $unitSeconds): int => intdiv($seconds, $unitSeconds);

$livePdo->exec('DROP TABLE IF EXISTS ' . $probeTable);
$livePdo->exec(sprintf(
    'CREATE TEMPORARY TABLE %s ('
    . 'code VARCHAR(40) PRIMARY KEY, name VARCHAR(60) NOT NULL, payload %s, '
    . 'start_at TIMESTAMP NULL, end_at TIMESTAMP NULL)',
    $probeTable,
    $liveDialect->isPostgres() ? 'JSONB' : 'JSON'
));

try {
    // 3.1-3.4 · Upsert que refresca y upsert que sólo asegura la existencia.
    $refreshClause = $liveDialect->upsertFromInsertedRow(['code'], ['name']);
    $refresh = $livePdo->prepare(
        "INSERT INTO {$probeTable} (code, name, payload) VALUES (:code, :name, :payload) {$refreshClause}"
    );
    $payload = '{"order_code":"ORDEN-42","payload":{"order_code":"ANIDADO"}}';
    $refresh->execute([':code' => 'VG-01', ':name' => 'uno', ':payload' => $payload]);
    $refresh->execute([':code' => 'VG-01', ':name' => 'dos', ':payload' => $payload]);

    $rowCount = (int)$livePdo->query("SELECT COUNT(*) FROM {$probeTable}")->fetchColumn();
    $currentName = (string)$livePdo->query("SELECT name FROM {$probeTable} WHERE code = 'VG-01'")->fetchColumn();
    $assert(
        '3.1 El upsert que reutiliza la fila propuesta no duplica: una sola fila',
        $rowCount === 1,
        "filas: {$rowCount}"
    );
    $assert(
        '3.2 Y refresca la columna declarada con el valor propuesto',
        $currentName === 'dos',
        "name: {$currentName}"
    );

    $noopClause = $liveDialect->upsertDoNothing(['code']);
    $livePdo->prepare("INSERT INTO {$probeTable} (code, name) VALUES (:code, :name) {$noopClause}")
        ->execute([':code' => 'VG-01', ':name' => 'tres']);
    $rowCount = (int)$livePdo->query("SELECT COUNT(*) FROM {$probeTable}")->fetchColumn();
    $currentName = (string)$livePdo->query("SELECT name FROM {$probeTable} WHERE code = 'VG-01'")->fetchColumn();
    $assert(
        '3.3 El upsert de sólo existencia no duplica la fila',
        $rowCount === 1,
        "filas: {$rowCount}"
    );
    $assert(
        '3.4 Ni pisa el dato que ya estaba: la regla «asegurar» no refresca nada',
        $currentName === 'dos',
        "name: {$currentName}"
    );

    // 3.5-3.6 · Lectura de JSON: texto plano y camino anidado.
    $flatExtract = $liveDialect->jsonExtract('payload', 'order_code');
    $nestedExtract = $liveDialect->jsonExtract('payload', 'payload.order_code');
    $flatValue = $livePdo->query("SELECT {$flatExtract} FROM {$probeTable} WHERE code = 'VG-01'")->fetchColumn();
    $nestedValue = $livePdo->query("SELECT {$nestedExtract} FROM {$probeTable} WHERE code = 'VG-01'")->fetchColumn();
    $assert(
        '3.5 La extracción de JSON devuelve texto descomillado en el motor vivo',
        (string)$flatValue === 'ORDEN-42',
        'valor: ' . var_export($flatValue, true) . ' con ' . $flatExtract
    );
    $assert(
        '3.6 Y camina los tramos intermedios hasta la clave anidada',
        (string)$nestedValue === 'ANIDADO',
        'valor: ' . var_export($nestedValue, true) . ' con ' . $nestedExtract
    );

    // 3.7-3.10 · Intervalos: desplazamiento y sentido, contra el reloj del propio motor.
    $subtractClause = $liveDialect->subtractInterval('NOW()', ':days', 'DAY');
    $subtractStatement = $livePdo->prepare("SELECT NOW() AS base, {$subtractClause} AS shifted");
    $subtractStatement->bindValue(':days', 7, PDO::PARAM_INT);
    $subtractStatement->execute();
    $subtracted = $subtractStatement->fetch(PDO::FETCH_ASSOC);
    $assert(
        '3.7 La resta de intervalo con parámetro enlazado ejecuta y desplaza exactamente 7 días',
        $subtracted !== false
        && (new DateTimeImmutable((string)$subtracted['base']))
            ->diff(new DateTimeImmutable((string)$subtracted['shifted']))->days === 7,
        (string)json_encode($subtracted)
    );
    $assert(
        '3.8 Y va hacia atrás: la marca desplazada es anterior al reloj del motor',
        $subtracted !== false
        && new DateTimeImmutable((string)$subtracted['shifted']) < new DateTimeImmutable((string)$subtracted['base'])
    );

    $addClause = $liveDialect->addInterval('NOW()', 3, 'DAY');
    $added = $livePdo->query("SELECT NOW() AS base, {$addClause} AS shifted")->fetch(PDO::FETCH_ASSOC);
    $assert(
        '3.9 La suma de intervalo desplaza 3 días y no 2 ni 4',
        $added !== false
        && (new DateTimeImmutable((string)$added['base']))
            ->diff(new DateTimeImmutable((string)$added['shifted']))->days === 3,
        (string)json_encode($added)
    );
    $assert(
        '3.10 Y va hacia delante',
        $added !== false
        && new DateTimeImmutable((string)$added['shifted']) > new DateTimeImmutable((string)$added['base'])
    );

    // 3.11-3.20 · Diferencia temporal en los bordes, contra el oráculo independiente.
    // El truncamiento hacia cero es el punto delicado: `TIMESTAMPDIFF` y `TRUNC` coinciden
    // también en las diferencias negativas, y por eso la traducción es exacta y no aproximada.
    $differenceClause = $liveDialect->timestampDifference('MINUTE', 'start_at', 'end_at');
    $differenceStatement = $livePdo->prepare("SELECT {$differenceClause} FROM {$probeTable} WHERE code = :code");
    $insertDifference = $livePdo->prepare(
        "INSERT INTO {$probeTable} (code, name, start_at, end_at) VALUES (:code, :name, :start, :end)"
    );

    $boundaries = [0, 59, 60, 61, 119, 121, -59, -60, -61, 3661];
    // Ancla y extremos en la **zona del motor** (`EngineClock`): los dos bordes de la sonda viajan
    // a columnas con zona, y un render en hora local desplazaba ambos el mismo huso.
    $anchor = new DateTimeImmutable('2026-01-10 12:00:00', EngineClock::zone());
    foreach ($boundaries as $index => $offsetSeconds) {
        $code = sprintf('VG-D%02d', $index + 1);
        $insertDifference->execute([
            ':code' => $code,
            ':name' => 'borde',
            ':start' => EngineClock::stamp($anchor->modify(sprintf('%+d seconds', $offsetSeconds))),
            ':end' => EngineClock::stamp($anchor),
        ]);
        $differenceStatement->execute([':code' => $code]);
        $engineValue = $differenceStatement->fetchColumn();
        $expected = $oracleDifference(-$offsetSeconds, 60);
        $assert(
            sprintf(
                '3.%d La diferencia de %+d segundos da %d minuto(s), como el oráculo',
                11 + $index,
                $offsetSeconds,
                $expected
            ),
            (string)$engineValue === (string)$expected,
            sprintf('motor: %s · oráculo: %d', var_export($engineValue, true), $expected)
        );
    }
} finally {
    $livePdo->exec('DROP TABLE IF EXISTS ' . $probeTable);
}

// 3.21 · La sonda no deja residuo: la tabla temporal desaparece con la sesión que la creó.
$probeGone = false;
try {
    $livePdo->query("SELECT COUNT(*) FROM {$probeTable}");
} catch (PDOException) {
    $probeGone = true;
}
$assert(
    '3.21 La tabla temporal de la sonda se retira y no queda nada en la base',
    $probeGone,
    'la sonda seguía existiendo tras el cierre del grupo'
);

// =====================================================================
// GRUPO 4 — Conformidad con la tabla de upsert declarada en las semillas
// =====================================================================

echo "\n--- Grupo 4: el adaptador es conforme a la tabla de upsert de las semillas ---\n";

$seedsPath = $projectRoot . '/database/supabase_seeds.sql';
$seedsSql = is_file($seedsPath) ? (string)file_get_contents($seedsPath) : '';

/**
 * Normaliza una cláusula para compararla sin depender de cómo se cita un identificador —el
 * adaptador cita **siempre**, y la semilla escribe a mano— ni de los saltos de línea.
 */
$normalizeClause = static function (string $clause): string {
    $withoutQuotes = str_replace(['`', '"'], '', $clause);
    $collapsed = preg_replace('/\s+/', ' ', $withoutQuotes) ?? $withoutQuotes;

    return trim($collapsed, " \t\n\r;");
};

$assert(
    '4.1 El fichero de semillas maestras existe y declara su tabla de reglas de upsert',
    $seedsSql !== ''
        && str_contains($seedsSql, 'UNA SOLA REGLA DE UPSERT, DOS RENDERIZADOS')
        && str_contains($seedsSql, 'spare_part_compatibilities')
);

preg_match_all(
    '/ON CONFLICT \(([^)]+)\)\s*(DO UPDATE SET|DO NOTHING)(.*?);/s',
    $seedsSql,
    $declaredClauses,
    PREG_SET_ORDER
);

$assert(
    '4.2 Las semillas declaran seis cláusulas: cinco refrescos y una de sólo-existencia',
    count($declaredClauses) === 6,
    'encontradas: ' . count($declaredClauses)
);

$caseNumber = 2;
$doNothingRules = 0;
$refreshRules = 0;

foreach ($declaredClauses as $declared) {
    [, $declaredKey, $declaredAction, $declaredBody] = $declared;
    $keyColumns = array_map('trim', explode(',', $declaredKey));

    if ($declaredAction === 'DO NOTHING') {
        $doNothingRules++;

        $renderedPostgres = $pgsql->upsertDoNothing($keyColumns);
        $assert(
            sprintf('4.%d El adaptador reproduce el DO NOTHING declarado (%s)', ++$caseNumber, $declaredKey),
            $normalizeClause($renderedPostgres) === $normalizeClause($declared[0]),
            $normalizeClause($renderedPostgres) . ' ≠ ' . $normalizeClause($declared[0])
        );

        // La forma de MariaDB de esa misma regla no puede refrescar ninguna columna de datos:
        // sólo reafirma columnas de la clave, y por eso «asegurar» sigue significando asegurar.
        $renderedMysql = $mysql->upsertDoNothing($keyColumns);
        preg_match_all(
            '/([A-Za-z_][A-Za-z0-9_]*)\s*=\s*VALUES\(([A-Za-z_][A-Za-z0-9_]*)\)/',
            $renderedMysql,
            $reaffirmed
        );
        $assert(
            sprintf('4.%d La forma de MariaDB de esa regla sólo reafirma columnas de la clave', ++$caseNumber),
            $reaffirmed[1] === $reaffirmed[2] && array_diff($reaffirmed[1], $keyColumns) === [],
            $renderedMysql
        );
        continue;
    }

    $refreshRules++;

    // Procedencia mixta: la semilla refresca unas columnas con la fila propuesta y fija otras a
    // un literal. Ambas cosas tienen que leerse del fichero, no suponerse.
    preg_match_all(
        '/([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(EXCLUDED\.([A-Za-z_][A-Za-z0-9_]*)|NULL|TRUE|FALSE|-?[0-9]+(?:\.[0-9]+)?)/i',
        $declaredBody,
        $declaredAssignments,
        PREG_SET_ORDER
    );

    $assignments = [];
    $provenanceIsFaithful = true;
    foreach ($declaredAssignments as $assignment) {
        $column = $assignment[1];
        $value = $assignment[2];

        if (str_starts_with(strtoupper($value), 'EXCLUDED.')) {
            $provenanceIsFaithful = $provenanceIsFaithful && strcasecmp($assignment[3], $column) === 0;
            $assignments[$column] = SqlDialectAdapter::PROPOSED_VALUE;
            continue;
        }

        $assignments[$column] = strtoupper($value);
    }

    $assert(
        sprintf(
            '4.%d Cada asignación EXCLUDED de la clave <%s> copia la columna de la misma fila (%d columnas leídas)',
            ++$caseNumber,
            $declaredKey,
            count($assignments)
        ),
        $provenanceIsFaithful && $assignments !== [],
        'columnas: ' . (string)json_encode($assignments)
    );

    $renderedPostgres = $pgsql->upsertAssignments($keyColumns, $assignments);
    $assert(
        sprintf('4.%d El adaptador reproduce el upsert entero declarado (%s)', ++$caseNumber, $declaredKey),
        $normalizeClause($renderedPostgres) === $normalizeClause($declared[0]),
        $normalizeClause($renderedPostgres) . ' ≠ ' . $normalizeClause($declared[0])
    );

    // Misma regla, dos sintaxis: las mismas columnas, en el mismo orden y con el mismo origen del
    // valor. Si un dialecto se quedara corto o refrescara de más, los dos motores guardarían
    // cosas distintas y la regla dejaría de ser una sola.
    $expectedMysql = 'ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
        static fn (string $column, string $provenance): string => $provenance === SqlDialectAdapter::PROPOSED_VALUE
            ? sprintf('%s = VALUES(%s)', $column, $column)
            : sprintf('%s = %s', $column, $provenance),
        array_keys($assignments),
        array_values($assignments)
    ));
    $renderedMysql = $mysql->upsertAssignments($keyColumns, $assignments);
    $assert(
        sprintf('4.%d Los dos dialectos expresan la misma regla (%s)', ++$caseNumber, $declaredKey),
        $normalizeClause($renderedMysql) === $normalizeClause($expectedMysql),
        $normalizeClause($renderedMysql) . ' ≠ ' . $normalizeClause($expectedMysql)
    );
}

$assert(
    sprintf('4.%d Las semillas declaran una excepción de existencia y cinco refrescos', ++$caseNumber),
    $doNothingRules === 1 && $refreshRules === 5,
    sprintf('DO NOTHING: %d · refrescos: %d', $doNothingRules, $refreshRules)
);
// =====================================================================
// GRUPO 5 — La superficie rastreada y el dialecto heredado que queda declarado
// =====================================================================

echo "\n--- Grupo 5: la superficie rastreada y el dialecto declarado, con dueño ---\n";

/** Superficie rastreada: el código que ejecuta el sistema y la batería que lo mide. */
const TRACED_SURFACES = ['src', 'database', 'bin', 'tests'];

/** Donde el dialecto vive por diseño: el punto único de traducción, fuera del rastro del censo. */
const DIALECT_HOME = 'src/Infrastructure/Dialect/SqlDialectAdapter.php';

/** Donde viven las familias del censo: el *rule set*, y por el mismo motivo fuera del censo. */
const CENSUS_HOME = 'tests/Support/DialectCensus.php';

/** El plan de tareas del módulo: de ahí sale si el dueño de una deuda sigue abierto. */
const TASK_PLAN = 'specs/13-single-engine-supabase/tasks.md';

/** Las tareas de la retirada: las únicas que pueden cerrar una deuda de la superficie de ejecución. */
const RETIREMENT_TASKS = ['T-13-14', 'T-13-15', 'T-13-16'];

/** Naturaleza admitida de una aparición declarada del dialecto heredado. */
const KIND_DEBT = 'deuda';
const KIND_GUARD = 'guarda';
const KIND_CONTRACT = 'contrato';
const KIND_OUT_OF_SCOPE = 'fuera de alcance';
const KIND_DRAINED = 'drenado';

/** @var list<string> */
const DECLARED_KINDS = [KIND_DEBT, KIND_GUARD, KIND_CONTRACT, KIND_OUT_OF_SCOPE, KIND_DRAINED];

/**
 * Construcciones cuya **segunda grafía en el mismo fichero** delata un híbrido.
 *
 * Un fichero que pregunta lo mismo en los dos dialectos —el caso de una suite a medio portar— no es
 * una tolerancia: es un estado con dueño. Se declara con `'mixed' => true` y su motivo, y un fichero
 * que empiece a mezclar sin declararlo pone la guarda en rojo.
 *
 * @var list<array{0: string, 1: string}>
 */
const CONSTRUCTION_PAIRS = [
    ['upsert', 'conflict'],
    ['values', 'excluded'],
    ['json', 'json_operator'],
    ['temporal', 'interval_literal'],
    ['temporal', 'epoch_extract'],
    ['catalog', 'catalog_view'],
    ['show', 'catalog_view'],
    ['legacy_database', 'current_database'],
    ['purge_window', 'purge_setting'],
];

/**
 * Declaración del dialecto heredado que queda, fichero a fichero y familia a familia.
 *
 * `census` es **exacto**: el número de literales de ese fichero que contienen esa familia, medido por
 * el censo del repositorio. La declaración no admite cifras que ya no sean ciertas —ni al alza ni a
 * la baja— porque una cifra declarada que ha dejado de ser cierta no prueba nada.
 *
 * `drained_occurrences` es de otra clase a propósito: la cifra que midió el **tool del porte** de la
 * tanda que lo drenó (ocurrencias de la familia en el fichero, no literales). Es la prueba del
 * descenso, y no se compara con el censo de esta guarda.
 *
 * @var array<string, array{kind: string, owner: ?string, census: array<string, int>, why: string, mixed?: bool, drained_occurrences?: array<string, int>}>
 */
const LEGACY_DIALECT = [
    // ---------- Superficie de ejecución: `src`, `database` y `bin` ----------
    'bin/init_cloud_db.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-15', 'census' => [],
        'drained_occurrences' => ['catalog' => 1, 'legacy_database' => 1],
        'why' => 'la rama de arranque del motor heredado contaba columnas en el catálogo de ese motor y nombraba su base; T-13-15 deja una sola ruta de arranque y lee pg_attribute en PostgreSQL',
    ],
    'bin/migrate.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-14', 'census' => [],
        'drained_occurrences' => ['backtick' => 13, 'show' => 12],
        'why' => 'ejecutor de migraciones CLI adaptado al motor único con PostgresMigrationRunner: se retiran todas las introspecciones MySQL SHOW y los acentos graves',
    ],
    'database/DemoMetricsSeeder.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-16', 'census' => [],
        'drained_occurrences' => ['backtick' => 15, 'upsert' => 2, 'values' => 2],
        'why' => 'fixture de métricas del motor heredado retirado del repositorio; el histórico se siembra exclusivamente por DemoHistorySeeder',
    ],
    'src/Infrastructure/Database/MigrationRunner.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-14', 'census' => [],
        'drained_occurrences' => ['legacy_database' => 1],
        'why' => 'el ejecutor del motor heredado comprobaba la existencia de sus tablas en el catálogo de ese motor; se retira el archivo a favor de PostgresMigrationRunner',
    ],
    'src/Infrastructure/Database/PdoLockWaitBudget.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-14', 'census' => [],
        'drained_occurrences' => ['catalog' => 1],
        'why' => 'la ruta del motor heredado leía su presupuesto de espera del estado de sesión de ese motor; T-13-14 retira esa rama y conserva solo la ruta PostgreSQL',
    ],
    'src/Presentation/Controller/CoordinatorRouteMapController.php' => [
        'kind' => KIND_OUT_OF_SCOPE,
        'owner' => null,
        'census' => ['backtick' => 1],
        'why' => 'respaldo del mapa territorial sobre SQLite en memoria, fuera del alcance declarado del adaptador (que traduce los motores del sistema, no un tercero)',
    ],
    // ---------- Superficie de la batería: `tests` ----------
    'tests/integration/CloudDeploySchemaParityTest.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-13', 'census' => [],
        'drained_occurrences' => ['catalog' => 3, 'legacy_database' => 1],
        'why' => 'porte del lote de esquema y despliegue: la guarda construía dos bases de sonda del motor retirado —su DROP/CREATE DATABASE y su catálogo en la grafía de ese motor— para comparar el esquema del despliegue; ahora levanta esquemas de sonda del motor único, los construye con el DDL y el ejecutor del repositorio y lee el catálogo de PostgreSQL (pg_class, pg_attribute, pg_enum)',
    ],
    'tests/integration/ConnectionFactoryTest.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-14', 'census' => [],
        'drained_occurrences' => ['legacy_database' => 1],
        'why' => 'la suite certifica la factoría de conexión del motor único; se retiraron las ramas y consultas del motor heredado',
    ],
    'tests/integration/PendingInfoConcurrencyTest.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-11', 'census' => [],
        'drained_occurrences' => ['catalog' => 2],
        'why' => 'porte del lote de comentarios y pausas: la sonda de aislamiento de la carrera preguntaba el nivel de aislamiento al estado de sesión del motor heredado (las dos grafías de la variable de sesión) y ahora lo lee del catálogo del motor único; el «lector sucio» se mide en la orilla más permisiva que el motor admite, no en una que no implementa',
    ],
    'tests/integration/PostgresConstitutionalTest.php' => [
        'kind' => KIND_CONTRACT,
        'owner' => 'T-13-16',
        'census' => ['legacy_database' => 1],
        'why' => 'contrasta el blindaje constitucional contra el motor heredado; se retira con el contrato de correspondencia entre motores',
    ],
    'tests/integration/SanitaryClockConsumerRaceTest.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-12', 'census' => [],
        'drained_occurrences' => ['catalog' => 2],
        'why' => 'porte del lote de ruta, mapa y barrido sanitario: la sonda de contención leía el presupuesto de espera del estado de sesión del motor heredado (sus dos grafías) y ahora lo lee del motor único por el punto que lo traduce, además de reconocer la espera agotada por el SQLSTATE del motor y no por el errno del retirado',
    ],
    'tests/integration/SanitaryClockSweepEndpointTest.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-12', 'census' => [],
        'drained_occurrences' => ['catalog' => 1],
        'why' => 'la misma sonda de contención del barrido sanitario, en su endpoint',
    ],
    'tests/integration/SchemaConvergenceParityTest.php' => [
        'kind' => KIND_CONTRACT,
        'owner' => 'T-13-16',
        'mixed' => true,
        'census' => ['catalog' => 9, 'legacy_database' => 1, 'text_function' => 1],
        'why' => 'certifica la correspondencia entre los dos esquemas: lee el catálogo del motor heredado en su grafía y el del motor único, para compararlos; muere con el contrato de correspondencia',
    ],
    'tests/integration/SeedRunnerSingleEngineTest.php' => [
        'kind' => KIND_GUARD,
        'owner' => null,
        'mixed' => true,
        'census' => ['upsert' => 1],
        'why' => 'guarda del sembrador: nombra la cláusula heredada para prohibirla y exige la del motor único, que es su otra grafía',
    ],
    'tests/unit/PdoLockWaitBudgetTest.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-14', 'census' => [],
        'drained_occurrences' => ['catalog' => 6],
        'why' => 'la suite fijaba la sentencia de la ruta heredada contra el doble de conexión; T-13-14 retira esa ruta y certifica solo la del motor único',
    ],
    'tests/unit/RefundsModuleClosureTest.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-16', 'census' => [],
        'drained_occurrences' => ['backtick' => 1],
        'why' => 'la suite comparaba contra la migración heredada; T-13-16 retira esa migración y contrasta el guardián de borrado físico contra supabase_init.sql',
    ],
    'tests/unit/RouteMapMigrationContractTest.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-16', 'census' => [],
        'drained_occurrences' => ['backtick' => 6, 'upsert' => 1, 'values' => 1],
        'why' => 'la suite comparaba contra la migración heredada 007; T-13-16 retira la migración y certifica las coordenadas en supabase_init.sql y SeedRunner',
    ],
    'tests/unit/SqlDialectPortabilityTest.php' => [
        'kind' => KIND_GUARD,
        'owner' => null,
        'mixed' => true,
        'census' => [
            'backtick' => 7, 'catalog' => 1, 'json' => 5, 'legacy_database' => 1, 'purge_window' => 1,
            'show' => 1, 'temporal' => 5, 'text_function' => 1, 'upsert' => 8, 'values' => 7,
        ],
        'why' => 'es esta guarda: renderiza la forma del motor retirado para certificar el punto único de traducción y cita cada familia en su espécimen para probar que el detector distingue —de ahí una ocurrencia de cada familia, que se retira cuando muera la rama del adaptador—',
    ],
    // ---------- Drenado: a cero, y declarado para que un retorno ponga la guarda en rojo ----------
    'src/Infrastructure/Repository/PdoIncidentRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-09', 'census' => [],
        'drained_occurrences' => ['backtick' => 234, 'temporal' => 4], 'why' => 'porte de la tanda 1',
    ],
    'src/Infrastructure/Repository/PdoMachineRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-09', 'census' => [],
        'drained_occurrences' => ['backtick' => 182], 'why' => 'porte de la tanda 1',
    ],
    'src/Infrastructure/Repository/PdoLocationRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-09', 'census' => [],
        'drained_occurrences' => ['backtick' => 116], 'why' => 'porte de la tanda 1',
    ],
    'src/Infrastructure/Repository/PdoUserRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-09', 'census' => [],
        'drained_occurrences' => ['backtick' => 124, 'temporal' => 2], 'why' => 'porte de la tanda 1',
    ],
    'src/Infrastructure/Repository/PdoRefundRequestRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-10', 'census' => [],
        'drained_occurrences' => ['backtick' => 63], 'why' => 'porte de la tanda 2',
    ],
    'src/Infrastructure/Repository/PdoSparePartRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-10', 'census' => [],
        'drained_occurrences' => ['backtick' => 73, 'upsert' => 2], 'why' => 'porte de la tanda 2',
    ],
    'src/Infrastructure/Repository/PdoSparePartRequestRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-10', 'census' => [],
        'drained_occurrences' => ['backtick' => 31], 'why' => 'porte de la tanda 2',
    ],
    'src/Infrastructure/Repository/PdoIncidentReplacedPartRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-10', 'census' => [],
        'drained_occurrences' => ['backtick' => 48, 'temporal' => 5], 'why' => 'porte de la tanda 2',
    ],
    'src/Infrastructure/Repository/PdoMetricsRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-10', 'census' => [],
        'drained_occurrences' => ['backtick' => 144, 'temporal' => 9], 'why' => 'porte de la tanda 2',
    ],
    'src/Infrastructure/Repository/PdoPreventiveSettingsRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-11', 'census' => [],
        'drained_occurrences' => ['backtick' => 85], 'why' => 'porte de la tanda 3',
    ],
    'src/Infrastructure/Repository/PdoPreventiveOrderRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-11', 'census' => [],
        'drained_occurrences' => ['backtick' => 190, 'temporal' => 4], 'why' => 'porte de la tanda 3',
    ],
    'src/Infrastructure/Repository/PdoPreventiveItemRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-11', 'census' => [],
        'drained_occurrences' => ['backtick' => 42], 'why' => 'porte de la tanda 3',
    ],
    'src/Infrastructure/Repository/PdoRouteSettingsRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-11', 'census' => [],
        'drained_occurrences' => ['backtick' => 26], 'why' => 'porte de la tanda 3',
    ],
    'src/Infrastructure/Repository/PdoSanitaryCertificateRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-11', 'census' => [],
        'drained_occurrences' => ['backtick' => 136], 'why' => 'porte de la tanda 3',
    ],
    'src/Infrastructure/Repository/PdoSanitarySweepVisibilityRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-11', 'census' => [],
        'drained_occurrences' => ['backtick' => 63, 'upsert' => 1], 'why' => 'porte de la tanda 3',
    ],
    'src/Infrastructure/Repository/PdoAuditLogRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-11', 'census' => [],
        'drained_occurrences' => ['backtick' => 40], 'why' => 'porte de la tanda 3',
    ],
    'src/Infrastructure/Repository/PdoSiteAccessCodeRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-11', 'census' => [],
        'drained_occurrences' => ['backtick' => 30, 'temporal' => 1], 'why' => 'porte de la tanda 3',
    ],
    'src/Infrastructure/Repository/PdoUnclaimedCashFindingRepository.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-11', 'census' => [],
        'drained_occurrences' => ['backtick' => 20], 'why' => 'porte de la tanda 3',
    ],
    'src/Infrastructure/Database/SeedRunner.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-HYBRID-12', 'census' => [],
        'drained_occurrences' => ['backtick' => 275, 'json' => 2, 'temporal' => 19, 'upsert' => 9],
        'why' => 'el sembrador del arnés: de aquí el adaptador toma la sintaxis de upsert que las semillas declaran',
    ],
    // La superficie de la batería también se drena, y un retorno aquí también pone la guarda en rojo:
    // éste es el sitio que el censo ad hoc de T-13-05 no veía —una cita heredada dentro de un fragmento
    // interpolado—, y el lote preventivo lo portó.
    'tests/integration/CoordinatorPreventiveDetailApiTest.php' => [
        'kind' => KIND_DRAINED, 'owner' => 'T-13-09', 'census' => [],
        'drained_occurrences' => ['backtick' => 2, 'json' => 2],
        'why' => 'porte del lote preventivo: la traza de auditoría lee el metadato con el operador del motor único, los dos identificadores entre acentos graves van desnudos y la plantilla del censo de la lectura pura valida el nombre antes de interpolarlo',
    ],
];

/** Ficheros que la superficie rastreada tiene que estar viendo, además de los 18 repositorios PDO. */
const REQUIRED_IN_SURFACE = [
    DIALECT_HOME,
    CENSUS_HOME,
    'tests/bootstrap.php',
    'tests/run_all.php',
    'tests/Support/TestDataCleaner.php',
    'tests/diagnostics/PendingInfoSmokeWorkflowDiagnostic.php',
    'src/Infrastructure/Database/SeedRunner.php',
    'bin/migrate.php',
];

$legacyCensus = DialectCensus::inventory($projectRoot, TRACED_SURFACES, DialectCensus::LEGACY_FAMILIES, [DIALECT_HOME, CENSUS_HOME]);
$engineCensus = DialectCensus::inventory($projectRoot, TRACED_SURFACES, DialectCensus::ENGINE_FAMILIES, [DIALECT_HOME, CENSUS_HOME]);
$legacyTotals = DialectCensus::totals($legacyCensus);
$engineTotals = DialectCensus::totals($engineCensus);
$tracedSurface = DialectCensus::surface($projectRoot, TRACED_SURFACES);

$missingFromSurface = array_values(array_diff(REQUIRED_IN_SURFACE, $tracedSurface));
$assert(
    '5.1 La superficie rastreada cubre la ejecución del sistema y la batería que lo mide',
    $missingFromSurface === []
    && in_array('src/Infrastructure/Repository/PdoIncidentRepository.php', $tracedSurface, true),
    sprintf(
        '%d ficheros PHP rastreados · ausentes: %s',
        count($tracedSurface),
        $missingFromSurface === [] ? '(ninguno)' : implode(', ', $missingFromSurface)
    )
);

// El punto único y el rule set citan el dialecto por diseño, y por eso quedan fuera del censo. Una
// exclusión que no se comprueba es un escondite: ninguno de los dos puede estar ejecutando SQL.
$adapterSource = (string)file_get_contents($projectRoot . '/' . DIALECT_HOME);
$censusSource = (string)file_get_contents($projectRoot . '/' . CENSUS_HOME);
$executesSql = static fn (string $source): bool => preg_match('/new\s+PDO|->\s*(query|exec|prepare|fetchAll|fetchColumn)\s*\(/i', $source) === 1;
$assert(
    '5.2 El punto único del dialecto y el rule set del censo no ejecutan SQL (su exclusión no esconde una sentencia)',
    $executesSql($adapterSource) === false && $executesSql($censusSource) === false,
    'el adaptador y el censo tienen que ser definición, no ejecución'
);

$undeclared = array_values(array_diff(array_keys($legacyCensus), array_keys(LEGACY_DIALECT)));
$assert(
    '5.3 Todo fichero con dialecto heredado está declarado',
    $undeclared === [],
    'sin declarar: ' . implode(', ', $undeclared)
);

$declaredMismatch = [];
foreach (LEGACY_DIALECT as $path => $declaration) {
    $found = $legacyCensus[$path] ?? [];
    ksort($found);
    $declared = $declaration['census'];
    ksort($declared);
    if ($found !== $declared) {
        $declaredMismatch[] = sprintf('%s (declarado %s · real %s)', $path, json_encode($declared), json_encode($found));
    }
}
$assert(
    '5.4 El censo declarado coincide exactamente con el real, fichero a fichero y familia a familia',
    $declaredMismatch === [],
    implode(' · ', $declaredMismatch)
);

$drainedWithDialect = array_values(array_intersect(array_keys($legacyCensus), array_keys(array_filter(
    LEGACY_DIALECT,
    static fn (array $declaration): bool => $declaration['kind'] === KIND_DRAINED
))));
$drainedWithoutDescent = [];
foreach (LEGACY_DIALECT as $path => $declaration) {
    if ($declaration['kind'] !== KIND_DRAINED) {
        continue;
    }
    $occurrences = $declaration['drained_occurrences'] ?? [];
    if ($occurrences === [] || array_sum($occurrences) <= 0) {
        $drainedWithoutDescent[] = $path;
    }
}
$assert(
    '5.5 Ningún fichero drenado recupera dialecto, y todo drenado cita el descenso que declaró su deuda',
    $drainedWithDialect === [] && $drainedWithoutDescent === [],
    sprintf(
        'regresiones: %s · sin descenso citado: %s',
        $drainedWithDialect === [] ? '(ninguna)' : implode(', ', $drainedWithDialect),
        $drainedWithoutDescent === [] ? '(ninguno)' : implode(', ', $drainedWithoutDescent)
    )
);

// El plan de tareas es la autoridad del dueño: una deuda cuyo dueño ya está cerrado es una deuda que
// debería estar pagada, y por eso pone la guarda en rojo en lugar de quedarse declarada para siempre.
$taskPlanSource = (string)file_get_contents($projectRoot . '/' . TASK_PLAN);
preg_match_all('/^- \[( |x)\]\s+\*\*(T-13-\d+)/m', $taskPlanSource, $taskRows, PREG_SET_ORDER);
$openTasks = [];
$knownTasks = [];
foreach ($taskRows as $row) {
    $knownTasks[$row[2]] = true;
    if ($row[1] === ' ') {
        $openTasks[$row[2]] = true;
    }
}

$unknownKind = [];
$missingOwner = [];
$closedOwner = [];
$unexpectedOwner = [];
foreach (LEGACY_DIALECT as $path => $declaration) {
    $kind = $declaration['kind'];
    if (!in_array($kind, DECLARED_KINDS, true)) {
        $unknownKind[] = $path;
        continue;
    }
    $owner = $declaration['owner'];
    if ($kind === KIND_DEBT || $kind === KIND_CONTRACT) {
        // Un contrato también se atribuye: su dueño es la tarea que retira el artefacto heredado
        // contra el que compara, y hasta entonces el contrato tiene sentido.
        if ($owner === null || !isset($knownTasks[$owner])) {
            $missingOwner[] = $path . ' (' . (string)$owner . ')';
            continue;
        }
        if (!isset($openTasks[$owner])) {
            $closedOwner[] = $path . ' (' . $owner . ')';
        }
        continue;
    }
    if ($owner !== null && $kind !== KIND_DRAINED) {
        $unexpectedOwner[] = $path . ' (' . $owner . ')';
    }
}
$assert(
    '5.6 Toda aparición declara una naturaleza admitida (deuda, guarda, contrato, fuera de alcance o drenado)',
    $unknownKind === [],
    'naturalezas desconocidas: ' . implode(', ', $unknownKind)
);
$assert(
    '5.7 Toda deuda y todo contrato tienen dueño, y su dueño existe en el plan de tareas del módulo',
    $missingOwner === [],
    'sin dueño o con dueño inexistente: ' . implode(', ', $missingOwner)
);
$assert(
    '5.8 Ni una deuda ni un contrato tienen el dueño ya cerrado: una excepción con el plazo cumplido es una excepción sin pagar',
    $closedOwner === [],
    sprintf(
        'deudas con dueño cerrado (de %d tareas, %d abiertas): %s',
        count($knownTasks),
        count($openTasks),
        implode(', ', $closedOwner)
    )
);
$assert(
    '5.9 Ni una guarda ni un sitio fuera de alcance se atribuyen a una tarea del porte: no hay tarea que los cierre',
    $unexpectedOwner === [],
    'declaraciones con dueño improcedente: ' . implode(', ', $unexpectedOwner)
);

// La mezcla de las dos grafías de una misma construcción se declara: un híbrido sin declarar es un
// fichero midiendo el dialecto en lugar de la conducta.
$mixedUndeclared = [];
$mixedDeclared = [];
foreach (array_unique(array_merge(array_keys($legacyCensus), array_keys($engineCensus))) as $path) {
    $legacyFamilies = $legacyCensus[$path] ?? [];
    $engineFamilies = $engineCensus[$path] ?? [];
    $constructionMix = [];
    foreach (CONSTRUCTION_PAIRS as [$legacyFamily, $engineFamily]) {
        if (isset($legacyFamilies[$legacyFamily]) && isset($engineFamilies[$engineFamily])) {
            $constructionMix[] = $legacyFamily . '+' . $engineFamily;
        }
    }
    if ($constructionMix === []) {
        continue;
    }
    if ((LEGACY_DIALECT[$path]['mixed'] ?? false) === true) {
        $mixedDeclared[] = $path;
        continue;
    }
    $mixedUndeclared[] = $path . ' (' . implode(', ', $constructionMix) . ')';
}
$assert(
    '5.10 Ningún fichero mezcla las dos grafías de una misma construcción sin declararlo',
    $mixedUndeclared === [],
    'mezcla sin declarar: ' . implode(', ', $mixedUndeclared)
);

echo "\n  Dialecto heredado declarado (fichero · naturaleza · dueño · censo):\n";
foreach (LEGACY_DIALECT as $path => $declaration) {
    $censusLabel = $declaration['census'] === []
        ? 'a cero'
        : implode(' ', array_map(
            static fn (string $family, int $count): string => $family . '=' . $count,
            array_keys($declaration['census']),
            $declaration['census']
        ));
    echo sprintf("    · %-64s %-14s %-12s %s\n", $path, $declaration['kind'], $declaration['owner'] ?? '—', $censusLabel);
}
echo sprintf(
    "  Saldo declarado: %d literales en %d ficheros · %s\n",
    $legacyTotals['literals'],
    $legacyTotals['files'],
    implode(' · ', array_map(
        static fn (string $family, int $count): string => $family . '=' . $count,
        array_keys($legacyTotals['families']),
        $legacyTotals['families']
    ))
);
echo sprintf("  Mezcla de grafías declarada: %s\n", $mixedDeclared === [] ? '(ninguna)' : implode(', ', $mixedDeclared));

// =====================================================================
// GRUPO 6 — El detector distingue: especímenes con el defecto y con su cura
// =====================================================================

echo "\n--- Grupo 6: el detector distingue (especímenes, no suposiciones) ---\n";

$specimenDir = sys_get_temp_dir() . '/vg_dialect_specimen_' . bin2hex(random_bytes(4));
$inheritedSpecimenPath = $specimenDir . '/InheritedSpecimen.php';
$singleEngineSpecimenPath = $specimenDir . '/SingleEngineSpecimen.php';

// El espécimen del dialecto retirado: cada familia con su literal, un comentario que la cita sin ser
// dialecto medible y una cita heredada dentro de un **fragmento interpolado** —el defecto real que
// este censo nombra por primera vez en la batería—.
$inheritedSpecimen = <<<'PHP'
<?php

declare(strict_types=1);

// Comentario y docblock con el dialecto retirado, que NO cuenta: ON DUPLICATE KEY UPDATE, SHOW COLUMNS
// y una consulta de ejemplo con `code` entre acentos graves que tampoco es un literal medible.

$inherited = [
    'backtick' => 'SELECT `id` FROM `incidents` WHERE `status` = 1',
    'upsert' => 'INSERT INTO users (email) VALUES (:email) ON DUPLICATE KEY UPDATE email = :email',
    'values' => 'INSERT INTO spare_part_compatibilities (a) VALUES (1) ON DUPLICATE KEY UPDATE a = VALUES(a)',
    'temporal' => 'SELECT DATE_SUB(NOW(), INTERVAL 7 DAY) FROM incidents',
    'json' => 'SELECT JSON_UNQUOTE(JSON_EXTRACT(`metadata`, \'$.order_code\')) FROM audit_log',
    'text_function' => 'SELECT GROUP_CONCAT(name) FROM machines GROUP BY location_id',
    'legacy_database' => 'SELECT DATABASE() AS schema_name',
    'catalog' => 'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema',
    'show' => 'SHOW COLUMNS FROM incidents LIKE \'status\'',
    'purge_window' => 'SET @vendguard_purge = 1',
];

$fragment = "    AND JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.order_code')) = '{$orderCode}'\n";
PHP;

// El espécimen del motor único: la forma que el sistema tiene que usar en cada construcción, más dos
// casos que **no** pueden contar como dialecto —la grafía minúscula del catálogo, que el motor único
// sí tiene, y su propia forma de preguntar por la base— y acentos graves en prosa que no es SQL.
$singleEngineSpecimen = <<<'PHP'
<?php

declare(strict_types=1);

$singleEngine = [
    'conflict' => 'INSERT INTO users (email) VALUES (:email) ON CONFLICT (email) DO UPDATE SET email = EXCLUDED.email',
    'json_operator' => 'SELECT metadata ->> \'order_code\' FROM audit_log',
    'interval_literal' => 'SELECT NOW() - INTERVAL \'7 days\' FROM incidents',
    'epoch_extract' => 'SELECT EXTRACT(EPOCH FROM (resolved_at - created_at)) / 60 FROM incidents',
    'catalog_view' => 'SELECT enumlabel FROM pg_enum WHERE enumtypid = :enum_oid',
    'current_database' => 'SELECT current_database() AS schema_name',
    'purge_setting' => 'SELECT set_config(\'vendguard.purge\', \'on\', false)',
];

$honest = [
    'lowercase_catalog' => 'SELECT column_name FROM information_schema.columns WHERE table_name = :table',
    'prose' => 'usa `code` entre acentos graves como ejemplo de prosa, no como dialecto',
];
PHP;

/**
 * Censo declarado del espécimen del dialecto retirado: literales por familia.
 *
 * Las diez familias aparecen; `backtick`, `upsert` y `json` aparecen dos veces porque tres especímenes
 * llevan más de una —los acentos graves del espécimen de extracción de JSON y los del fragmento, y la
 * cláusula heredada del espécimen de `VALUES`—. Si el censo dejara entrar los comentarios, estas
 * cifras subirían: es la mitad del espécimen que prueba que los comentarios no cuentan.
 *
 * @var array<string, int>
 */
const INHERITED_SPECIMEN_CENSUS = [
    'backtick' => 2,
    'upsert' => 2,
    'values' => 1,
    'temporal' => 1,
    'json' => 2,
    'text_function' => 1,
    'legacy_database' => 1,
    'catalog' => 1,
    'show' => 1,
    'purge_window' => 1,
];

@mkdir($specimenDir, 0700, true);
file_put_contents($inheritedSpecimenPath, $inheritedSpecimen);
file_put_contents($singleEngineSpecimenPath, $singleEngineSpecimen);

try {
    $specimenLegacy = DialectCensus::inventory(sys_get_temp_dir(), [basename($specimenDir)], DialectCensus::LEGACY_FAMILIES);
    $specimenEngine = DialectCensus::inventory(sys_get_temp_dir(), [basename($specimenDir)], DialectCensus::ENGINE_FAMILIES);

    $inheritedKey = basename($specimenDir) . '/InheritedSpecimen.php';
    $singleEngineKey = basename($specimenDir) . '/SingleEngineSpecimen.php';

    $foundInherited = $specimenLegacy[$inheritedKey] ?? [];
    ksort($foundInherited);
    $expectedInherited = INHERITED_SPECIMEN_CENSUS;
    ksort($expectedInherited);

    $assert(
        '6.1 El espécimen del dialecto retirado dispara cada familia con la cifra declarada (y sin contar comentarios)',
        $foundInherited === $expectedInherited,
        sprintf('declarado %s · real %s', json_encode($expectedInherited), json_encode($foundInherited))
    );
    $assert(
        '6.2 El espécimen del motor único no dispara NINGUNA familia heredada (ni la grafía minúscula del catálogo, ni su forma de preguntar por su base, ni la prosa con acentos graves)',
        !isset($specimenLegacy[$singleEngineKey]),
        'falsos positivos: ' . json_encode($specimenLegacy[$singleEngineKey] ?? [])
    );

    $engineFamiliesMissing = array_values(array_diff(
        array_keys(DialectCensus::ENGINE_FAMILIES),
        array_keys($specimenEngine[$singleEngineKey] ?? [])
    ));
    $assert(
        '6.3 Y el espécimen del motor único declara las ocho construcciones que el sistema usa',
        $engineFamiliesMissing === [],
        'sin reconocer: ' . implode(', ', $engineFamiliesMissing)
    );

    // La cita heredada de un fragmento interpolado: no parece SQL y aun así es dialecto. Es el sitio
    // de `CoordinatorPreventiveDetailApiTest`, invisible al censo ad hoc del lote anterior.
    $inheritedLiterals = DialectCensus::literals($inheritedSpecimenPath);
    $fragmentsWithJsonCite = array_filter(
        $inheritedLiterals,
        static fn (string $literal): bool => DialectCensus::flags($literal, 'json') === true
            && DialectCensus::looksLikeSql($literal) === false
    );
    $assert(
        '6.4 Una cita heredada dentro de un fragmento interpolado se ve, aunque el fragmento no parezca SQL',
        count($fragmentsWithJsonCite) === 1,
        'fragmentos que la llevan sin parecer SQL: ' . count($fragmentsWithJsonCite)
    );
    $assert(
        '6.5 El comentario del espécimen no es un literal: el dialecto que cita no se cuenta',
        count(array_filter(
            $inheritedLiterals,
            static fn (string $literal): bool => str_contains($literal, 'Comentario y docblock')
        )) === 0
    );
} finally {
    @unlink($inheritedSpecimenPath);
    @unlink($singleEngineSpecimenPath);
    @rmdir($specimenDir);
}

$assert(
    '6.6 El espécimen no deja residuo: su directorio temporal se retira',
    !is_dir($specimenDir) && !is_file($inheritedSpecimenPath)
);

// =====================================================================
// GRUPO 7 — La exigencia: el dialecto del motor único es el que se escribe
// =====================================================================

echo "\n--- Grupo 7: el dialecto del motor único, exigido ---\n";

$absentEngineFamilies = array_values(array_diff(
    array_keys(DialectCensus::ENGINE_FAMILIES),
    array_keys($engineTotals['families'])
));
$assert(
    '7.1 Las ocho construcciones del motor único están presentes en la superficie rastreada',
    $absentEngineFamilies === [],
    'ausentes: ' . implode(', ', $absentEngineFamilies)
);
// La única aparición admitida de la ventana de purga heredada es la guarda que la cita en su
// espécimen; cualquier otra —una suite que la declare de verdad, la aplicación— no se declara: se repara.
$purgeWindowOutsideGuards = array_values(array_filter(
    array_keys($legacyCensus),
    static fn (string $path): bool => isset($legacyCensus[$path]['purge_window'])
        && LEGACY_DIALECT[$path]['kind'] !== KIND_GUARD
));
$assert(
    '7.2 La ventana de purga del arnés tiene una sola forma: la heredada no queda en ninguna superficie fuera de la guarda que la cita',
    $purgeWindowOutsideGuards === [] && ($engineTotals['families']['purge_setting'] ?? 0) > 0,
    sprintf(
        'purga heredada fuera de las guardas: %s · declaración del motor en %d ficheros',
        $purgeWindowOutsideGuards === [] ? '(ninguna)' : implode(', ', $purgeWindowOutsideGuards),
        $engineTotals['families']['purge_setting'] ?? 0
    )
);

// La superficie de ejecución no «tiene deuda»: solo está lo que la retirada va a retirar. Un sitio de
// dialecto en un fichero que se queda —un repositorio, un servicio, el arranque— no se declara: se repara.
$executionSurfaceDebts = [];
foreach ($legacyCensus as $path => $families) {
    if (!preg_match('#^(src|database|bin)/#', $path)) {
        continue;
    }
    $declaration = LEGACY_DIALECT[$path];
    if ($declaration['kind'] === KIND_OUT_OF_SCOPE) {
        continue;
    }
    if ($declaration['kind'] !== KIND_DEBT || !in_array($declaration['owner'], RETIREMENT_TASKS, true)) {
        $executionSurfaceDebts[] = $path . ' (' . $declaration['kind'] . ' · ' . (string)$declaration['owner'] . ')';
    }
}
$assert(
    '7.3 En la superficie de ejecución, el dialecto heredado solo permanece donde la retirada lo va a retirar',
    $executionSurfaceDebts === [],
    'sitios que no son de la retirada: ' . implode(', ', $executionSurfaceDebts)
);

// Y la batería no introduce dialecto nuevo: cada hallazgo suyo es una guarda que lo nombra, un contrato
// que compara contra el artefacto heredado o una deuda con lote abierto. «Fuera de alcance» no vale aquí.
$batchUndeclared = [];
foreach ($legacyCensus as $path => $families) {
    if (str_starts_with($path, 'tests/') === false) {
        continue;
    }
    $declaration = LEGACY_DIALECT[$path];
    if ($declaration['kind'] === KIND_OUT_OF_SCOPE || $declaration['kind'] === KIND_DRAINED) {
        $batchUndeclared[] = $path . ' (' . $declaration['kind'] . ')';
    }
}
$assert(
    '7.4 La batería no introduce dialecto nuevo: todo hallazgo suyo es guarda, contrato o deuda con lote abierto',
    $batchUndeclared === [],
    'hallazgos sin cobertura declarada: ' . implode(', ', $batchUndeclared)
);

$assert(
    '7.5 El adaptador es el único sitio que renderiza las cuatro familias del dialecto',
    str_contains($adapterSource, 'ON CONFLICT')
    && str_contains($adapterSource, 'ON DUPLICATE KEY UPDATE')
    && str_contains($adapterSource, 'JSON_UNQUOTE(JSON_EXTRACT')
    && str_contains($adapterSource, "->> '%s'")
    && str_contains($adapterSource, 'DATE_SUB')
    && str_contains($adapterSource, 'TIMESTAMPDIFF')
    && str_contains($adapterSource, 'EXTRACT(EPOCH FROM')
);
$assert(
    '7.6 El adaptador no declara un tercer motor ni modos de compatibilidad que no se usan',
    !str_contains($adapterSource, "'sqlite") && !str_contains($adapterSource, 'ANSI_QUOTES')
);

echo sprintf(
    "  Saldo del motor único: %d literales en %d ficheros · %s\n",
    $engineTotals['literals'],
    $engineTotals['files'],
    implode(' · ', array_map(
        static fn (string $family, int $count): string => $family . '=' . $count,
        array_keys($engineTotals['families']),
        $engineTotals['families']
    ))
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. GUARDA DEL DIALECTO INVERTIDA (T-13-06) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
