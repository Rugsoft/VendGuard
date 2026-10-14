<?php

declare(strict_types=1);

/**
 * VendGuard · Puerta de identidad del cliente local contra el motor de certificación
 *
 * RNF-05 · T-HYBRID-12(2).
 *
 * Qué certifica: que el cliente PostgreSQL del PHP local **verifica las dos formas** con
 * las que el certificado del motor declara su identidad —`DNS:localhost` e
 * `IP:127.0.0.1`— con `sslmode=verify-full` y CA declarada, y que **rechaza** un nombre
 * que el certificado no declara. Lo segundo es tan importante como lo primero: sin el caso
 * negativo, un cliente que no verificase nada también aprobaría el positivo.
 *
 * Por qué es una puerta aparte de `verify.php`: ahí se certifica el **motor** (está
 * disponible, el rol no es administrador, admite DDL y DML). Aquí se certifica el
 * **cliente**: qué versión de libpq enlaza y qué verificación de identidad ejerce de
 * verdad. La verificación de direcciones del SAN es una capacidad de libpq **desde la 12**;
 * un cliente anterior rechaza un certificado válido que declare la dirección
 * (`server certificate for "localhost" does not match host name "127.0.0.1"`), y el
 * cliente local quedaría verificando menos que la imagen desplegada. Ese es el desvío que
 * esta puerta convierte en fallo visible en vez de en matiz silencioso.
 *
 * Contrato de entorno: `PG_TEST_DSN`, el mismo de la categoría del motor nuevo. Sin él,
 * falla en cerrado: no se omite la comprobación, se declara no ejecutable.
 *
 * Uso:
 *   source deploy/local/postgres/.env.docker-tls && php deploy/local/postgres/check-local-libpq.php
 *
 * Códigos de salida:
 *   0 = cliente moderno y verificación de identidad ejercida en las dos formas del SAN.
 *   1 = cliente antiguo, verificación no ejercida, o motor/credencial ausente.
 */

const MIN_LIBPQ_MAJOR = 12;

/**
 * Reemplaza el host del DSN por otro, conservando el resto del contrato.
 */
function dsn_with_host(string $dsn, string $host): string
{
    $replaced = preg_replace('/host=[^;]*/', 'host=' . $host, $dsn, 1, $count);

    return ($count === 1) ? (string)$replaced : $dsn . ';host=' . $host;
}

function libpq_major(string $version): int
{
    return (int)explode('.', $version)[0];
}

$dsn = getenv('PG_TEST_DSN');

if ($dsn === false || trim($dsn) === '') {
    fwrite(STDERR, "[CLIENTE NO CERTIFICABLE] Falta PG_TEST_DSN en el entorno.\n");
    fwrite(STDERR, "  La puerta necesita el motor TLS en marcha para poder ejercer la verificación:\n");
    fwrite(STDERR, "    bash deploy/local/postgres/provision-tls-engine.sh\n");
    fwrite(STDERR, "    source deploy/local/postgres/.env.docker-tls\n");
    exit(1);
}

if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "[CLIENTE NO CERTIFICABLE] El driver pdo_pgsql no está cargado en este PHP.\n");
    exit(1);
}

echo "======================================================================\n";
echo " VendGuard · Identidad del cliente local contra el motor certificador\n";
echo "======================================================================\n\n";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 5,
];

$probe = static function (string $host) use ($dsn, $options): array {
    try {
        $pdo = new PDO(dsn_with_host($dsn, $host), null, null, $options);
        $tls = $pdo->query(
            'SELECT ssl, version, cipher FROM pg_stat_ssl WHERE pid = pg_backend_pid()'
        )->fetch();
        $server = $pdo->query('SHOW server_version')->fetchColumn();

        return [
            'connected' => true,
            'client' => (string)$pdo->getAttribute(PDO::ATTR_CLIENT_VERSION),
            'server' => (string)$server,
            'tls' => is_array($tls) ? $tls : [],
            'error' => '',
        ];
    } catch (Throwable $exception) {
        return ['connected' => false, 'client' => '', 'server' => '', 'tls' => [], 'error' => $exception->getMessage()];
    }
};

// --- 1. La forma con nombre del SAN ------------------------------------------
$byName = $probe('localhost');
if (!$byName['connected']) {
    fwrite(STDERR, "[FALLO] No puedo conectar por el nombre del SAN: " . $byName['error'] . "\n");
    fwrite(STDERR, "  El motor TLS no está disponible en el puerto del contrato.\n");
    exit(1);
}

$clientMajor = libpq_major($byName['client']);
printf("[1/3] Nombre del SAN : DNS:localhost verifica con verify-full · motor %s\n", $byName['server']);
printf("      Enlace TLS     : %s · %s\n", $byName['tls']['version'] ?? '—', $byName['tls']['cipher'] ?? '—');
printf("      Cliente libpq  : %s (leída de la conexión en vivo)\n", $byName['client']);

if (($byName['tls']['ssl'] ?? '') !== true && ($byName['tls']['ssl'] ?? '') !== 't' && (int)($byName['tls']['ssl'] ?? 0) !== 1) {
    fwrite(STDERR, "\n[FALLO] La conexión no viaja por TLS: la verificación de identidad no se está ejerciendo.\n");
    exit(1);
}

if ($clientMajor < MIN_LIBPQ_MAJOR) {
    fwrite(STDERR, "\n[FALLO] El cliente enlaza libpq {$byName['client']}: no verifica direcciones del SAN\n");
    fwrite(STDERR, "  (capacidad incorporada en PostgreSQL " . MIN_LIBPQ_MAJOR . "). El cliente local estaría verificando\n");
    fwrite(STDERR, "  menos que la imagen desplegada. Alinéalo con:\n");
    fwrite(STDERR, "    bash deploy/local/postgres/upgrade-local-libpq.sh\n");
    exit(1);
}

// --- 2. La forma con dirección del SAN --------------------------------------
$byAddress = $probe('127.0.0.1');
if (!$byAddress['connected']) {
    fwrite(STDERR, "\n[FALLO] La dirección del SAN no verifica: " . $byAddress['error'] . "\n");
    fwrite(STDERR, "  El certificado declara IP:127.0.0.1 y sslmode=verify-full debe aceptarla.\n");
    exit(1);
}

printf("[2/3] Dirección SAN  : IP:127.0.0.1 verifica con verify-full · motor %s\n", $byAddress['server']);

// --- 3. El caso negativo: un nombre que el certificado no declara ------------
// Se usa una dirección de loopback adicional (127.0.0.2): llega al mismo motor, así que un
// fallo sólo puede venir de la comprobación de identidad, no del transporte.
$rejected = $probe('127.0.0.2');
$expected = '/certificate|host name|no match/i';

if ($rejected['connected']) {
    fwrite(STDERR, "\n[FALLO] El cliente aceptó una dirección que el certificado no declara: no está verificando.\n");
    exit(1);
}

if (preg_match($expected, $rejected['error']) !== 1) {
    fwrite(STDERR, "\n[FALLO] El rechazo de 127.0.0.2 no provino de la verificación de identidad: "
        . $rejected['error'] . "\n");
    echo "      (Si esa dirección no es alcanzable en este equipo, la puerta no puede distinguir\n";
    echo "       entre «rechazado por el certificado» y «puerto cerrado».)\n";
    exit(1);
}

printf("[3/3] Caso negativo  : 127.0.0.2 rechazada por el certificado (%s)\n", trim($rejected['error']));

echo "\n" . str_repeat('=', 70) . "\n";
printf(" CLIENTE LOCAL CERTIFICADO · libpq %s · las dos formas del SAN verifican\n", $byName['client']);
echo str_repeat('=', 70) . "\n";

exit(0);
