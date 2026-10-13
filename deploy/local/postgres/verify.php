<?php

declare(strict_types=1);

/**
 * VendGuard - Sonda de disponibilidad del motor nuevo (categoría de certificación)
 *
 * RNF-04 · spec §9 duda 1 · T-HYBRID-21, T-HYBRID-24, T-HYBRID-27.
 *
 * La batería hermética (`php tests/run_all.php`) corre sin red contra el motor local y
 * NO lee esta variable: las suites que exigen PostgreSQL pertenecen a la categoría de
 * contrato del motor nuevo, que tiene su propio canal y su propio informe. Esta sonda
 * es la puerta de esa categoría: comprueba que el motor está disponible **de verdad**
 * —no que la variable exista— antes de que ninguna suite intente ejecutarse.
 *
 * Contrato de entorno (un único valor, sin valor por defecto y sin credenciales en el
 * código versionado):
 *   PG_TEST_DSN = pgsql:host=...;port=...;dbname=...;user=...;password=...
 * Su valor lo genera `provision.sh` en `deploy/local/postgres/.env.local`, que no se
 * versiona. Sin variable, la sonda falla en cerrado: la categoría no se omite en
 * silencio, se declara no ejecutable.
 *
 * Uso:
 *   source deploy/local/postgres/.env.local && php deploy/local/postgres/verify.php
 *
 * Códigos de salida:
 *   0 = motor disponible y utilizable (conexión, versión, privilegio de DDL y de DML).
 *   1 = motor no disponible, driver ausente o credenciales sin declarar.
 */

$dsn = getenv('PG_TEST_DSN');

if ($dsn === false || trim($dsn) === '') {
    fwrite(STDERR, "[MOTOR NUEVO NO DISPONIBLE] Falta PG_TEST_DSN en el entorno.\n");
    fwrite(STDERR, "  Genera y carga el contrato local con:\n");
    fwrite(STDERR, "    bash deploy/local/postgres/provision.sh\n");
    fwrite(STDERR, "    source deploy/local/postgres/.env.local\n");
    fwrite(STDERR, "  La categoría de certificación del motor nuevo queda declarada como no\n");
    fwrite(STDERR, "  ejecutable; no se omite ninguna suite de su categoría en silencio.\n");
    exit(1);
}

if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "[MOTOR NUEVO NO DISPONIBLE] El driver pdo_pgsql no está cargado en este PHP.\n");
    fwrite(STDERR, "  Activa la extensión y repite la comprobación; ver deploy/local/postgres/README.md.\n");
    exit(1);
}

echo "========================================================\n";
echo " VendGuard · Motor nuevo (categoría de certificación)\n";
echo "========================================================\n\n";

try {
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::ATTR_TIMEOUT => 5,
    ]);
} catch (Throwable $exception) {
    fwrite(STDERR, "[MOTOR NUEVO NO DISPONIBLE] No se pudo conectar: " . $exception->getMessage() . "\n");
    fwrite(STDERR, "  Comprueba que el servicio está en marcha y que el DSN apunta al puerto correcto.\n");
    exit(1);
}

$identity = $pdo->query(
    "SELECT current_database() AS database, current_user AS db_user,"
    . " current_setting('server_version') AS server_version,"
    . " inet_server_addr()::text AS server_addr,"
    . " (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) AS is_superuser"
)->fetch();

printf("[1/3] Conexión   : %s@%s · PostgreSQL %s\n", $identity['db_user'], $identity['database'], $identity['server_version']);
printf("      Servidor   : %s (loopback del motor local)\n", $identity['server_addr'] ?? 'socket local');
printf("      Superusuario: %s\n", $identity['is_superuser'] === true || $identity['is_superuser'] === 't' ? 'SÍ (no apto para la categoría)' : 'no');

if ($identity['is_superuser'] === true || $identity['is_superuser'] === 't') {
    fwrite(STDERR, "\n[FALLO] La categoría debe ejecutarse con un rol de aplicación, no con el superusuario\n");
    fwrite(STDERR, "  del motor (política S-5 del proyecto: sin acceso administrativo implícito).\n");
    exit(1);
}

// Privilegios reales del rol de aplicación: DDL y DML dentro de una transacción que
// siempre se revierte, de modo que la sonda no deja residuo en la base de la categoría.
try {
    $pdo->beginTransaction();
    $pdo->exec('CREATE TABLE vendguard_engine_probe (probe_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, noted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO vendguard_engine_probe (noted_at) VALUES (CURRENT_TIMESTAMP)");
    $rows = (int)$pdo->query('SELECT COUNT(*) FROM vendguard_engine_probe')->fetchColumn();
    $pdo->rollBack();

    // La reversión es parte de la comprobación: si el DDL no se deshiciera, la sonda
    // habría dejado una tabla huérfana en la base que después usaría la categoría.
    $residue = $pdo->query("SELECT to_regclass('public.vendguard_engine_probe') IS NOT NULL AS present")->fetchColumn();
    $hasResidue = $residue === true || $residue === 't' || (int)$residue === 1;
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FALLO] El rol conecta pero no puede crear ni escribir tablas: " . $exception->getMessage() . "\n");
    exit(1);
}

printf("[2/3] DDL y DML : tabla creada y fila escrita dentro de una transacción (%d fila)\n", $rows);
printf("[3/3] Limpieza  : reversión %s\n", $hasResidue ? 'FALLIDA — queda residuo en la base' : 'correcta, sin residuo');

if ($hasResidue) {
    fwrite(STDERR, "\n[FALLO] La sonda ha dejado residuo: revisa la base antes de ejecutar la categoría.\n");
    exit(1);
}

echo "\n" . str_repeat('=', 56) . "\n";
printf(" MOTOR NUEVO DISPONIBLE · motor_nuevo=%s usuario=%s base=%s\n", $identity['server_version'], $identity['db_user'], $identity['database']);
echo str_repeat('=', 56) . "\n";

exit(0);
