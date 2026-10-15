<?php

declare(strict_types=1);

/**
 * SqlDialectPortabilityTest — certificación del punto único de traducción de dialecto.
 *
 * Tarea: T-HYBRID-25 · Requisitos: RF-01.1, RF-01.5, EARS-01.1, EARS-01.5 · Constitución Art. I.3.
 *
 * QUÉ SE CERTIFICA AQUÍ (cuatro cosas distintas, y por eso son cuatro grupos)
 *
 *   1. **Los dos renderizados de cada construcción sin forma común.** PostgreSQL y MariaDB
 *      escriben distinto el upsert, la lectura de JSON, la aritmética temporal y la cita de
 *      identificadores, y ninguna redacción ANSI los unifica. El adaptador es el único sitio
 *      donde vive esa traducción: una regla, dos formas. El grupo fija las formas exactas,
 *      carácter a carácter, para que un cambio de traducción no pueda pasar inadvertido.
 *
 *   2. **La ejecución real contra el motor vivo.** Las formas se *ejecutan* —tablas temporales
 *      de sesión, sin tocar esquema ni datos del dominio— y su resultado se contrasta con un
 *      oráculo independiente escrito en PHP: el upsert refresca y el de sólo-existencia no pisa
 *      nada, la extracción de JSON devuelve texto —también en caminos anidados—, los intervalos
 *      se desplazan en la cantidad pedida y con el sentido pedido, y la diferencia temporal
 *      reproduce el truncamiento **hacia cero** de `TIMESTAMPDIFF`, bordes incluidos (0, 59, 60,
 *      61, 119, 121 segundos y sus negativos). El grupo es agnóstico de motor a propósito:
 *      certifica el motor sobre el que corre la batería, y el mismo código certificaría el otro.
 *
 *   3. **La conformidad con la tabla de upsert declarada en las semillas.** `T-HYBRID-03`
 *      congeló en [`database/supabase_seeds.sql`](../../database/supabase_seeds.sql) la regla de
 *      upsert de cada tabla del catálogo y ordenó que el adaptador se escriba **conforme a
 *      ella**, no al revés. El grupo lee esa tabla del fichero real —sin copiarla aquí— y
 *      comprueba que el adaptador reproduce cada cláusula entera, con sus asignaciones de
 *      procedencia mixta (la fila propuesta y el literal `deleted_at = NULL` en la misma
 *      cláusula), y que **los dos dialectos expresan la misma regla**: mismas columnas, mismo
 *      orden, mismo origen del valor.
 *
 *   4. **La frontera: que no quede SQL privativo *nuevo* fuera del adaptador.** Un rastreador
 *      léxico (`token_get_all`) recorre los literales de cadena del código de aplicación
 *      —los comentarios no cuentan, y por eso el rastreo no se confunde con la documentación
 *      que cita sintaxis— y compara el inventario resultante con el **inventario pendiente
 *      declarado**. Mientras las tandas de normalización están sin hacer, el pendiente es
 *      exactamente su trabajo y está atribuido fichero a fichero a la tarea que lo drena
 *      (T-HYBRID-09/10/11 en los repositorios, T-HYBRID-12 en el sembrado); cuando esas tareas
 *      cierren, el pendiente queda a cero y esta misma afirmación se vuelve la ausencia absoluta
 *      que promete el «Hecho cuando». La comparación es **de igualdad estricta**, no de «no
 *      crezcas»: si el inventario baja y no se actualiza la declaración, la suite se pone en
 *      rojo, porque una cifra declarada que ya no es cierta deja de ser una prueba.
 *
 *      Un fichero drenado **no desaparece** de la declaración: pasa al inventario drenado, que
 *      cita la cifra que tenía cuando se declaró su deuda —la prueba del descenso— y convierte
 *      el cero en una afirmación activa: si el fichero recupera dialecto, la suite se pone en
 *      rojo por regresión, no por una cifra desactualizada. Sin esa mitad, «ya está drenado»
 *      sería una promesa que nadie vigila.
 *
 * QUÉ NO SE CERTIFICA AQUÍ (declarado, no omitido)
 *
 *   La ejecución semántica de la rama PostgreSQL **dentro de esta suite**. La batería es
 *   hermética (RNF-04) y no puede exigir un motor que no está en todos los puestos. La rama
 *   `pgsql` se certifica aquí de forma estructural (sus formas exactas y su conformidad con las
 *   semillas) y su semántica pertenece a la categoría del motor nuevo, con su canal y su
 *   informe (`T-HYBRID-21`, `T-HYBRID-24`). Además se certificó fuera de banda contra el
 *   PostgreSQL 16.15 local de [`deploy/local/postgres/`](../../deploy/local/postgres/README.md)
 *   el 13/10/2026: los cuatro escenarios del grupo 3 arrojaron los mismos valores que MariaDB
 *   en los mismos diez bordes de diferencia temporal, negativos incluidos. Esa corrida no se
 *   versiona —su sitio es el canal del motor nuevo— y no se cuenta como verde de esta suite.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas ni bundlers.
 * Dualismo Lingüístico: identificadores y código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Dialect\SqlDialectAdapter;

$projectRoot = dirname(__DIR__, 2);

// =====================================================================
// Arnés mínimo de aserciones (mismo contrato que el resto de la batería)
// =====================================================================

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - SqlDialectPortabilityTest (T-HYBRID-25)\n";
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
    '2.2 El rechazo nombra la tarea que fija el contrato',
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
// GRUPO 5 — La frontera: no queda SQL privativo *nuevo* fuera del adaptador
// =====================================================================

echo "\n--- Grupo 5: frontera del punto único de traducción (inventario pendiente declarado) ---\n";

/**
 * Inventario pendiente declarado.
 *
 * Cada entrada es el recuento de construcciones privativas que **quedan hoy** fuera del adaptador
 * en los literales de cadena de ese fichero, con la tarea que las drena. La comparación es de
 * igualdad estricta: un recuento nuevo pone la suite en rojo (nadie introduce dialecto fuera del
 * adaptador), y un recuento que baje sin actualizar esta declaración también (la cifra declarada
 * tiene que seguir siendo cierta).
 */
const PENDING_INVENTORY = [
    // Semilla de métricas del arnés: fixture local de MariaDB, sin tarea de normalización en el
    // módulo. Su SQL sigue siendo del motor local a propósito, y por eso está declarado, no oculto.
    'database/DemoMetricsSeeder.php' => ['backtick' => 144, 'upsert' => 2],
    // Ejecutor de migraciones del motor local, sin tarea de normalización en el módulo.
    'bin/migrate.php' => ['backtick' => 14],
    // Consulta de respaldo del mapa territorial, fuera del alcance declarado: la suite que la
    // ejercita corre sobre SQLite en memoria (tests/unit/CoordinatorRouteMapControllerTest.php) y
    // el adaptador sólo traduce los dos motores del sistema, a propósito.
    'src/Presentation/Controller/CoordinatorRouteMapController.php' => ['backtick' => 3],
];

/**
 * Inventario ya drenado, con la cifra que declaraba el pendiente en el momento de drenarlo.
 *
 * No es una expectativa —la expectativa es cero— sino la prueba del descenso y la mitad activa
 * del trinquete: el fichero sigue declarado aquí, de modo que volver a escribir dialecto en él
 * pone la suite en rojo en la aserción 5.4, y no en una cifra que ya nadie compara.
 *
 * `families` conserva el recuento histórico por familia rastreada; `drained_by` cita la tarea
 * que lo drenó, para que el descenso se pueda atribuir igual que antes se atribuía la deuda.
 */
const DRAINED_INVENTORY = [
    // Tanda 1 · Incidencias, Máquinas, Sedes, Usuarios (T-HYBRID-09)
    'src/Infrastructure/Repository/PdoIncidentRepository.php' => ['drained_by' => 'T-HYBRID-09', 'families' => ['backtick' => 234, 'temporal' => 4]],
    'src/Infrastructure/Repository/PdoMachineRepository.php' => ['drained_by' => 'T-HYBRID-09', 'families' => ['backtick' => 182]],
    'src/Infrastructure/Repository/PdoLocationRepository.php' => ['drained_by' => 'T-HYBRID-09', 'families' => ['backtick' => 116]],
    'src/Infrastructure/Repository/PdoUserRepository.php' => ['drained_by' => 'T-HYBRID-09', 'families' => ['backtick' => 124, 'temporal' => 2]],
    // Tanda 2 · Reintegros, Repuestos, Piezas Sustituidas, Métricas (T-HYBRID-10)
    'src/Infrastructure/Repository/PdoRefundRequestRepository.php' => ['drained_by' => 'T-HYBRID-10', 'families' => ['backtick' => 63]],
    'src/Infrastructure/Repository/PdoSparePartRepository.php' => ['drained_by' => 'T-HYBRID-10', 'families' => ['backtick' => 73, 'upsert' => 2]],
    'src/Infrastructure/Repository/PdoSparePartRequestRepository.php' => ['drained_by' => 'T-HYBRID-10', 'families' => ['backtick' => 31]],
    'src/Infrastructure/Repository/PdoIncidentReplacedPartRepository.php' => ['drained_by' => 'T-HYBRID-10', 'families' => ['backtick' => 48, 'temporal' => 5]],
    'src/Infrastructure/Repository/PdoMetricsRepository.php' => ['drained_by' => 'T-HYBRID-10', 'families' => ['backtick' => 144, 'temporal' => 9]],
    // Tanda 3 · Preventivo, Rutas, Sanitario, Auditoría (T-HYBRID-11)
    'src/Infrastructure/Repository/PdoPreventiveSettingsRepository.php' => ['drained_by' => 'T-HYBRID-11', 'families' => ['backtick' => 85]],
    'src/Infrastructure/Repository/PdoPreventiveOrderRepository.php' => ['drained_by' => 'T-HYBRID-11', 'families' => ['backtick' => 190, 'temporal' => 4]],
    'src/Infrastructure/Repository/PdoPreventiveItemRepository.php' => ['drained_by' => 'T-HYBRID-11', 'families' => ['backtick' => 42]],
    'src/Infrastructure/Repository/PdoRouteSettingsRepository.php' => ['drained_by' => 'T-HYBRID-11', 'families' => ['backtick' => 26]],
    'src/Infrastructure/Repository/PdoSanitaryCertificateRepository.php' => ['drained_by' => 'T-HYBRID-11', 'families' => ['backtick' => 136]],
    'src/Infrastructure/Repository/PdoSanitarySweepVisibilityRepository.php' => ['drained_by' => 'T-HYBRID-11', 'families' => ['backtick' => 63, 'upsert' => 1]],
    'src/Infrastructure/Repository/PdoAuditLogRepository.php' => ['drained_by' => 'T-HYBRID-11', 'families' => ['backtick' => 40]],
    'src/Infrastructure/Repository/PdoSiteAccessCodeRepository.php' => ['drained_by' => 'T-HYBRID-11', 'families' => ['backtick' => 30, 'temporal' => 1]],
    'src/Infrastructure/Repository/PdoUnclaimedCashFindingRepository.php' => ['drained_by' => 'T-HYBRID-11', 'families' => ['backtick' => 20]],
    // Sembrado (T-HYBRID-12): de aquí el adaptador toma la sintaxis de upsert que las semillas declaran.
    'src/Infrastructure/Database/SeedRunner.php' => ['drained_by' => 'T-HYBRID-12', 'families' => ['backtick' => 275, 'json' => 2, 'temporal' => 19, 'upsert' => 9]],
];

/** Fichero con hallazgos => tarea (o motivo declarado) que lo drena. */
const INVENTORY_OWNERS = [
    'src/Infrastructure/Repository/PdoIncidentRepository.php' => 'T-HYBRID-09',
    'src/Infrastructure/Repository/PdoMachineRepository.php' => 'T-HYBRID-09',
    'src/Infrastructure/Repository/PdoLocationRepository.php' => 'T-HYBRID-09',
    'src/Infrastructure/Repository/PdoUserRepository.php' => 'T-HYBRID-09',
    'src/Infrastructure/Repository/PdoRefundRequestRepository.php' => 'T-HYBRID-10',
    'src/Infrastructure/Repository/PdoSparePartRepository.php' => 'T-HYBRID-10',
    'src/Infrastructure/Repository/PdoSparePartRequestRepository.php' => 'T-HYBRID-10',
    'src/Infrastructure/Repository/PdoIncidentReplacedPartRepository.php' => 'T-HYBRID-10',
    'src/Infrastructure/Repository/PdoMetricsRepository.php' => 'T-HYBRID-10',
    'src/Infrastructure/Repository/PdoPreventiveSettingsRepository.php' => 'T-HYBRID-11',
    'src/Infrastructure/Repository/PdoPreventiveOrderRepository.php' => 'T-HYBRID-11',
    'src/Infrastructure/Repository/PdoPreventiveItemRepository.php' => 'T-HYBRID-11',
    'src/Infrastructure/Repository/PdoRouteSettingsRepository.php' => 'T-HYBRID-11',
    'src/Infrastructure/Repository/PdoSanitaryCertificateRepository.php' => 'T-HYBRID-11',
    'src/Infrastructure/Repository/PdoSanitarySweepVisibilityRepository.php' => 'T-HYBRID-11',
    'src/Infrastructure/Repository/PdoAuditLogRepository.php' => 'T-HYBRID-11',
    'src/Infrastructure/Repository/PdoSiteAccessCodeRepository.php' => 'T-HYBRID-11',
    'src/Infrastructure/Repository/PdoUnclaimedCashFindingRepository.php' => 'T-HYBRID-11',
    'src/Infrastructure/Database/SeedRunner.php' => 'T-HYBRID-12',
    'database/DemoMetricsSeeder.php' => 'fixture local del arnés (sin tarea asignada en el módulo)',
    'bin/migrate.php' => 'ejecutor de migraciones del motor local (sin tarea asignada en el módulo)',
    'src/Presentation/Controller/CoordinatorRouteMapController.php' => 'respaldo compatible con SQLite (fuera del alcance del adaptador)',
];

/** Dónde vive el dialecto: el fichero que se excluye del rastreo por ser el punto único. */
const DIALECT_HOME = 'src/Infrastructure/Dialect/SqlDialectAdapter.php';

/** Familias rastreadas y su patrón dentro de un literal de cadena. */
const PRIVATE_SQL_FAMILIES = [
    'temporal' => '/\b(DATE_SUB|DATE_ADD|ADDDATE|SUBDATE|DATEDIFF|TIMESTAMPDIFF|TIMESTAMPADD|EXTRACT\s*\(\s*EPOCH)\s*\(/i',
    'upsert' => '/\bON\s+(DUPLICATE\s+KEY\s+UPDATE|CONFLICT)\b/i',
    'json' => '/\b(JSON_EXTRACT|JSON_UNQUOTE|JSON_SET|JSON_OBJECT|JSON_ARRAY)\s*\(/i',
    'jsonop' => '/-\>\>?\s*[\'"][A-Za-z0-9_]/',
    'backtick' => '/`[A-Za-z_][A-Za-z0-9_]*`/',
];

/**
 * Rastrea los literales de cadena del código de aplicación —comentarios excluidos, de modo que la
 * documentación que cita sintaxis privativa no cuenta como dialecto— y devuelve el inventario por
 * fichero y familia.
 *
 * @param list<string> $directories
 * @return array<string, array<string, int>>
 */
function scanPrivateSqlInventory(string $projectRoot, array $directories): array
{
    $sqlVerbs = '/\b(SELECT|INSERT|UPDATE|DELETE|SET|FROM|WHERE|JOIN|INTO|VALUES|CREATE|ALTER)\b/i';
    $inventory = [];

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
            if ($relative === DIALECT_HOME) {
                continue; // Aquí es donde el dialecto tiene que estar: es el punto único.
            }

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
                        $literals[] = substr($text, 1, -1);
                        continue;
                    }
                    if ($id === T_ENCAPSED_AND_WHITESPACE) {
                        $literals[] = $text;
                    }
                    continue;
                }

                if ($inHeredoc) {
                    $buffer .= $token;
                }
            }

            foreach ($literals as $literal) {
                $looksLikeSql = preg_match($sqlVerbs, $literal) === 1;

                foreach (PRIVATE_SQL_FAMILIES as $family => $pattern) {
                    // Las comillas y los operadores JSON sólo cuentan dentro de un literal que es
                    // SQL: si no, cualquier copia con acentos graves parecería dialecto.
                    if (($family === 'backtick' || $family === 'jsonop') && !$looksLikeSql) {
                        continue;
                    }

                    $hits = preg_match_all($pattern, $literal);
                    if ($hits > 0) {
                        $inventory[$relative][$family] = ($inventory[$relative][$family] ?? 0) + $hits;
                    }
                }
            }
        }
    }

    ksort($inventory);

    return $inventory;
}

$inventory = scanPrivateSqlInventory($projectRoot, ['src', 'database', 'bin']);

$declaredRepositories = array_keys(array_filter(
    PENDING_INVENTORY + DRAINED_INVENTORY,
    static fn (string $path): bool => str_starts_with($path, 'src/Infrastructure/Repository/'),
    ARRAY_FILTER_USE_KEY
));
$repositoriesOnDisk = glob($projectRoot . '/src/Infrastructure/Repository/*.php') ?: [];
$assert(
    '5.1 La superficie rastreada incluye los 18 repositorios PDO del proyecto',
    count($declaredRepositories) === 18 && count($repositoriesOnDisk) === 18,
    sprintf('declarados: %d (pendientes %d + drenados %d) · en disco: %d', count($declaredRepositories), count(PENDING_INVENTORY), count(DRAINED_INVENTORY), count($repositoriesOnDisk))
);

$assert(
    '5.2 Todo fichero con hallazgos tiene declarado quién lo drena (o por qué queda fuera)',
    array_diff(array_keys($inventory), array_keys(INVENTORY_OWNERS)) === [],
    'sin atribución: ' . implode(', ', array_diff(array_keys($inventory), array_keys(INVENTORY_OWNERS)))
);

$assert(
    '5.3 El inventario no contiene ningún fichero fuera de los declarados (pendientes o drenados)',
    array_diff(array_keys($inventory), array_keys(PENDING_INVENTORY + DRAINED_INVENTORY)) === [],
    'nuevos: ' . implode(', ', array_diff(array_keys($inventory), array_keys(PENDING_INVENTORY + DRAINED_INVENTORY)))
);

$drainedWithDialect = array_values(array_intersect(array_keys(DRAINED_INVENTORY), array_keys($inventory)));
$pendingButClean = array_values(array_diff(array_keys(PENDING_INVENTORY), array_keys($inventory)));
$assert(
    '5.4 Ningún fichero drenado recupera dialecto, y ningún pendiente se queda limpio sin declararlo',
    $drainedWithDialect === [] && $pendingButClean === [],
    sprintf(
        'regresiones en drenados: %s · pendientes que se quedaron limpios: %s',
        $drainedWithDialect === [] ? '(ninguna)' : implode(', ', $drainedWithDialect),
        $pendingButClean === [] ? '(ninguno)' : implode(', ', $pendingButClean)
    )
);

$pendingTotals = ['backtick' => 0, 'temporal' => 0, 'upsert' => 0, 'json' => 0, 'jsonop' => 0];
$pendingByOwner = [];

foreach (PENDING_INVENTORY as $path => $declared) {
    $found = $inventory[$path] ?? [];
    ksort($found);
    $declaredSorted = $declared;
    ksort($declaredSorted);

    $owner = INVENTORY_OWNERS[$path] ?? '(sin atribución declarada)';
    foreach ($declaredSorted as $family => $count) {
        $pendingTotals[$family] += $count;
        $pendingByOwner[$owner] = ($pendingByOwner[$owner] ?? 0) + $count;
    }

    $assert(
        sprintf('5.5 %s · inventario declarado y real coinciden (%s)', $path, $owner),
        $found === $declaredSorted,
        sprintf('declarado: %s · real: %s', json_encode($declaredSorted), json_encode($found))
    );
}

echo "\n  Inventario pendiente declarado (la misma regla, aún sin traducir):\n";
foreach ($pendingByOwner as $owner => $count) {
    echo sprintf("    · %-60s %d construcción(es)\n", $owner, $count);
}
echo sprintf(
    "    · total: %d acentos graves, %d expresiones temporales, %d upserts y %d lecturas de JSON\n",
    $pendingTotals['backtick'],
    $pendingTotals['temporal'],
    $pendingTotals['upsert'],
    $pendingTotals['json'] + $pendingTotals['jsonop']
);

$drainedTotals = ['backtick' => 0, 'temporal' => 0, 'upsert' => 0, 'json' => 0, 'jsonop' => 0];
$drainedByOwner = [];
foreach (DRAINED_INVENTORY as $path => $drained) {
    foreach ($drained['families'] as $family => $count) {
        $drainedTotals[$family] += $count;
        $drainedByOwner[$drained['drained_by']] = ($drainedByOwner[$drained['drained_by']] ?? 0) + $count;
    }
}

echo "\n  Inventario ya drenado (a cero, con la cifra que declaró su deuda):\n";
foreach ($drainedByOwner as $owner => $count) {
    $files = 0;
    foreach (DRAINED_INVENTORY as $drained) {
        if ($drained['drained_by'] === $owner) {
            $files++;
        }
    }
    echo sprintf("    · %-60s %d fichero(s) · %d construcción(es)\n", $owner, $files, $count);
}
echo sprintf(
    "    · total drenado: %d acentos graves, %d expresiones temporales, %d upserts y %d lecturas de JSON\n",
    $drainedTotals['backtick'],
    $drainedTotals['temporal'],
    $drainedTotals['upsert'],
    $drainedTotals['json'] + $drainedTotals['jsonop']
);

// El punto único tiene que ser el punto único: las familias rastreadas viven todas en el adaptador,
// y el adaptador no las deja vivir en ningún otro sitio.
$dialectSource = (string)file_get_contents($projectRoot . '/' . DIALECT_HOME);
$assert(
    '5.6 El adaptador es el único fichero que renderiza las cuatro familias (y por eso se excluye)',
    str_contains($dialectSource, 'ON CONFLICT')
    && str_contains($dialectSource, 'ON DUPLICATE KEY UPDATE')
    && str_contains($dialectSource, 'JSON_UNQUOTE(JSON_EXTRACT')
    && str_contains($dialectSource, "->> '%s'")
    && str_contains($dialectSource, 'DATE_SUB')
    && str_contains($dialectSource, 'TIMESTAMPDIFF')
    && str_contains($dialectSource, 'EXTRACT(EPOCH FROM')
);

$assert(
    '5.7 El adaptador no declara un tercer motor ni modos de compatibilidad que no se usan',
    !str_contains($dialectSource, "'sqlite") && !str_contains($dialectSource, 'ANSI_QUOTES')
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-HYBRID-25 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
