#!/usr/bin/env bash
# =============================================================================
# VendGuard · Alinear el cliente libpq del PHP local con el de la imagen
# =============================================================================
# RNF-05 · T-HYBRID-12(2) · T-HYBRID-21.
#
# Por qué existe: la ruta PostgreSQL de `ConnectionFactory` exige `sslmode=verify-full`
# con CA declarada y **prohíbe degradar** la verificación de identidad (RNF-05, sin
# excepción de loopback). El certificado del motor de certificación declara la identidad
# en las dos formas del SAN —`IP:127.0.0.1` y `DNS:localhost`—, de modo que las dos
# formas tienen que verificar. El PHP de XAMPP enlaza **libpq 11.4**, que comprueba el
# nombre contra los `dNSName` del SAN pero **no** contra las direcciones `iPAddress`
# (soportadas desde PostgreSQL 12): con esa librería, conectar por `127.0.0.1` rechaza un
# certificado que sí es válido. El cliente local quedaba así verificando **menos** que la
# imagen desplegada (`php:8.2-cli-alpine`, libpq moderna), que es la asimetría que este
# script cierra.
#
# Qué hace, en orden:
#   1. Resuelve el PHP objetivo, su directorio y la versión de libpq que enlaza hoy.
#   2. Comprueba que el cliente PostgreSQL de origen existe y es **>= 12** (suelo de la
#      capacidad que se quiere ganar).
#   3. Respalda en `libpq-backup-<versión respaldada>` los ficheros que va a sustituir y
#      anota en `MANIFEST.txt` qué sustituyó y qué añadió. El directorio del respaldo queda
#      registrado en `libpq-alignment.state`: es lo que permite volver atrás más tarde, con
#      la versión que sea, sin adivinar el nombre del directorio.
#   4. Copia `libpq.dll` y las dependencias que la nueva librería **importa de verdad**
#      —se leen de su tabla de importación, no se suponen— y que el PHP no tiene ya.
#   5. Verifica en ejecución: la extensión sigue cargando, el driver `pgsql` sigue
#      disponible y, contra el motor TLS, las **dos** formas del SAN verifican con
#      `verify-full`. Si la actualización deja el PHP peor que estaba, **restaura el
#      respaldo** y sale con error; no se queda un cliente a medias.
#
# Es idempotente: si el PHP ya enlaza la versión del cliente de origen, no sustituye nada
# y sólo certifica; y una pasada posterior sobre un PHP ya alineado **reutiliza** el
# respaldo de la primera alineación en vez de fabricar otro.
#
# Qué NO toca: `libcrypto-3-x64.dll` ni `libssl-3-x64.dll` del PHP. La verificación de
# direcciones es una capacidad de **libpq**, no de OpenSSL (la implementa libpq con
# `X509_check_ip_asc`, presente desde OpenSSL 1.0.2), y sustituir OpenSSL perturbaría a
# `php_openssl`, compilado contra 3.0.x. El libpq nuevo se enlaza contra el OpenSSL que el
# PHP ya carga.
#
# Uso:
#   bash deploy/local/postgres/upgrade-local-libpq.sh          # alinea y certifica
#   bash deploy/local/postgres/upgrade-local-libpq.sh --check  # sólo comprueba
#   bash deploy/local/postgres/upgrade-local-libpq.sh --restore # vuelve al respaldo
#
# Variables:
#   PHP_BIN            Opcional. Intérprete objetivo. Por defecto el `php` del PATH.
#   PG_CLIENT_BIN      Opcional. Directorio del cliente PostgreSQL de origen (el que aporta
#                      la libpq moderna). Por defecto la instalación nativa
#                      `C:\Program Files\PostgreSQL\16\bin` (ninguna descarga: ya está aquí).
#   LIBPQ_BACKUP_DIR   Opcional. Respaldo concreto a restaurar, si hay más de uno y el
#                      estado no basta para desambiguar.
#   PG_TEST_DSN        Contrato del motor de certificación. Si no está en el entorno, el
#                      script carga `deploy/local/postgres/.env.docker-tls` cuando existe.
#
# Códigos de salida:
#   0 = cliente actualizado (o ya moderno) y las dos formas del SAN certificadas.
#   1 = fallo. Con reversión si el fallo fue al certificar un cambio ya aplicado.
#   2 = cambio aplicado, pero la certificación de identidad no pudo ejecutarse porque el
#       motor TLS no está disponible (el cambio se conserva; se avisa, no se finge).
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
PG_CLIENT_BIN="${PG_CLIENT_BIN:-/c/Program Files/PostgreSQL/16/bin}"
LIBPQ_BACKUP_DIR="${LIBPQ_BACKUP_DIR:-}"
PG_TEST_DSN="${PG_TEST_DSN:-}"
MIN_LIBPQ_MAJOR=12

fail() {
    echo "[LIBPQ FALLIDO] $1" >&2
    exit 1
}

ok() {
    echo "      ✓ $1"
}

# --- 0. Intérprete objetivo ---------------------------------------------------
[ -n "${PHP_BIN}" ] || fail "No encuentro el intérprete PHP (define PHP_BIN o ponlo en el PATH)."

PHP_DIR="$(cd "$(dirname "${PHP_BIN}")" && pwd)"
PHP_EXE="${PHP_DIR}/$(basename "${PHP_BIN}")"
STATE_FILE="${PHP_DIR}/libpq-alignment.state"

[ -f "${PHP_DIR}/libpq.dll" ] \
    || fail "En ${PHP_DIR} no hay libpq.dll: no es un PHP de Windows con la extensión pgsql, o la ruta no es la esperada."
[ -w "${PHP_DIR}" ] \
    || fail "No puedo escribir en ${PHP_DIR}. Ejecuta el script con permiso sobre esa carpeta."

# La versión que enlaza el PHP sale de su propio phpinfo: es la fuente que después se
# vuelve a leer para comprobar que el cambio surtió efecto.
libpq_version() {
    "${PHP_EXE}" -i 2>/dev/null | tr -d '\r' \
        | sed -n 's/^PostgreSQL(libpq) Version => //p' | head -1 || true
}

# --- 1. Estado y respaldo -----------------------------------------------------
# El respaldo de una alineación anterior no se puede localizar por la versión actual —al
# volver atrás, o al repetir la pasada, la versión actual ya no es la respaldada—, así que
# el directorio queda anotado en el estado.

backup_dir_from_state() {
    [ -f "${STATE_FILE}" ] || return 1
    local dir
    dir="$(sed -n 's/^BACKUP_DIR=//p' "${STATE_FILE}" | head -1)"
    if [ -n "${dir}" ] && [ -f "${dir}/MANIFEST.txt" ]; then
        printf '%s' "${dir}"
        return 0
    fi
    return 1
}

backup_dir_unique() {
    local dir found=""
    for dir in "${PHP_DIR}"/libpq-backup-*; do
        if [ -d "${dir}" ] && [ -f "${dir}/MANIFEST.txt" ]; then
            [ -n "${found}" ] && return 1  # más de uno: no se adivina
            found="${dir}"
        fi
    done
    if [ -n "${found}" ]; then
        printf '%s' "${found}"
        return 0
    fi
    return 1
}

resolve_backup_dir() {
    local dir
    if [ -n "${LIBPQ_BACKUP_DIR}" ]; then
        printf '%s' "${LIBPQ_BACKUP_DIR}"
        return 0
    fi
    if dir="$(backup_dir_from_state)"; then
        printf '%s' "${dir}"
        return 0
    fi
    if dir="$(backup_dir_unique)"; then
        printf '%s' "${dir}"
        return 0
    fi
    return 1
}

write_state() {  # $1 = versión que enlaza el PHP ahora · $2 = versión respaldada
    (
        umask 077
        printf '%s\n' \
            "# Estado de la alineación del cliente PostgreSQL del PHP local (generado)." \
            "# Reversión: bash deploy/local/postgres/upgrade-local-libpq.sh --restore" \
            "BACKUP_DIR=${BACKUP_DIR}" \
            "BACKED_UP_VERSION=$2" \
            "APPLIED_VERSION=$1" \
            "UPDATED_AT=$(date -Iseconds 2>/dev/null || date)" \
            > "${STATE_FILE}"
    )
}

# La reversión se apoya en el manifiesto: qué ficheros se sustituyeron (se copian de
# vuelta) y qué ficheros se añadieron (se retiran). Sin manifiesto no hay reversión, y sin
# reversión una actualización fallida dejaría el PHP sin driver.
restore_backup() {
    [ -f "${MANIFEST}" ] || fail "No hay respaldo con manifiesto en ${BACKUP_DIR}: no puedo revertir."
    local kind name
    while read -r kind name; do
        [ -n "${name}" ] || continue
        case "${kind}" in
            replaced)
                cp -f "${BACKUP_DIR}/${name}" "${PHP_DIR}/${name}"
                ;;
            added)
                rm -f "${PHP_DIR}/${name}"
                ;;
        esac
    done < <(sed -n '/^\(replaced\|added\) /p' "${MANIFEST}")
}

CURRENT_VERSION="$(libpq_version || true)"
[ -n "${CURRENT_VERSION}" ] \
    || fail "No puedo leer la versión de libpq de ${PHP_EXE}: la extensión pgsql no carga. Repara el PHP antes de actualizar."
CURRENT_MAJOR="${CURRENT_VERSION%%.*}"

echo "======================================================================"
echo " VendGuard · libpq del PHP local, alineada con la imagen desplegada"
echo "======================================================================"
echo
echo "=== 1/5 · Cliente objetivo ==="
echo "      PHP       : ${PHP_EXE}"
echo "      Directorio : ${PHP_DIR}"
ok "libpq enlazada hoy: ${CURRENT_VERSION}"

# --- Reversión explícita ------------------------------------------------------
if [ "${1:-}" = "--restore" ]; then
    echo
    echo "=== Reversión al respaldo ==="
    BACKUP_DIR="$(resolve_backup_dir)" \
        || fail "No encuentro ningún respaldo que restaurar. Indica su directorio con LIBPQ_BACKUP_DIR."
    MANIFEST="${BACKUP_DIR}/MANIFEST.txt"
    # Leer el estado sin que la ausencia del fichero cuente como fallo: sed sale con 2 si
    # no existe el fichero de entrada, y con `pipefail` eso mataría el script en silencio.
    BACKED_UP_VERSION=""
    if [ -f "${STATE_FILE}" ]; then
        BACKED_UP_VERSION="$(sed -n 's/^BACKED_UP_VERSION=//p' "${STATE_FILE}" | head -1)"
    fi
    # Sin estado (respaldo hecho a mano o por una versión anterior del script), la versión
    # respaldada se lee de la cabecera del propio manifiesto.
    if [ -z "${BACKED_UP_VERSION}" ] || [ "${BACKED_UP_VERSION}" = "(desconocida)" ]; then
        BACKED_UP_VERSION="$(sed -n 's/^# Respaldo del PHP local antes de alinear su libpq (\([0-9][0-9.]*\) →.*/\1/p' "${MANIFEST}" | head -1)"
    fi
    [ -n "${BACKED_UP_VERSION}" ] || BACKED_UP_VERSION="(desconocida)"
    restore_backup
    RESTORED="$(libpq_version)"
    # La reversión tiene que notarse: si el PHP sigue enlazando la versión de la que se
    # quería volver, el respaldo no sirvió y hay que saberlo aquí, no al conectar.
    if [ "${BACKED_UP_VERSION}" != "(desconocida)" ] && [ "${RESTORED}" != "${BACKED_UP_VERSION}" ]; then
        fail "La reversión no surtió efecto: el PHP sigue enlazando ${RESTORED} y el respaldo es ${BACKED_UP_VERSION}."
    fi
    ok "Respaldo de ${BACKED_UP_VERSION} restaurado desde ${BACKUP_DIR}"
    ok "libpq enlazada ahora: ${CURRENT_VERSION} → ${RESTORED}"
    write_state "${RESTORED}" "${BACKED_UP_VERSION}"
    echo
    echo " Para volver a alinearlo: bash deploy/local/postgres/upgrade-local-libpq.sh"
    exit 0
fi

check_only=0
[ "${1:-}" = "--check" ] && check_only=1
applied=0

# --- 2. Cliente de origen -----------------------------------------------------
echo
echo "=== 2/5 · Cliente PostgreSQL de origen (el que aporta la libpq moderna) ==="
[ -f "${PG_CLIENT_BIN}/libpq.dll" ] \
    || fail "En ${PG_CLIENT_BIN} no hay libpq.dll. Instala el cliente PostgreSQL o apunta PG_CLIENT_BIN a su directorio bin."
[ -x "${PG_CLIENT_BIN}/psql" ] \
    || fail "En ${PG_CLIENT_BIN} no hay psql: no puedo certificar la versión de origen."

SOURCE_VERSION="$("${PG_CLIENT_BIN}/psql" --version | tr -d '\r' \
    | sed -n 's/^psql (PostgreSQL) \([0-9][0-9.]*\).*/\1/p')"
[ -n "${SOURCE_VERSION}" ] || fail "No puedo leer la versión de ${PG_CLIENT_BIN}/psql."
SOURCE_MAJOR="${SOURCE_VERSION%%.*}"

[ "${SOURCE_MAJOR}" -ge "${MIN_LIBPQ_MAJOR}" ] \
    || fail "El cliente de origen es ${SOURCE_VERSION}: hace falta ${MIN_LIBPQ_MAJOR}+ para verificar direcciones del SAN."
ok "Cliente de origen: PostgreSQL ${SOURCE_VERSION} en ${PG_CLIENT_BIN}"

needs_alignment=1
if [ "${CURRENT_VERSION}" = "${SOURCE_VERSION}" ]; then
    needs_alignment=0
fi

# --- 3. Dependencias reales del libpq entrante --------------------------------
# Las dependencias se leen de la tabla de importación del propio binario en vez de
# suponerse: si una versión futura del cliente añade una DLL, el conjunto lo refleja. Se
# omiten las DLL del sistema y las que el PHP ya tiene (ahí están libcrypto/libssl, que
# no se tocan a propósito).
PE_HELPER="$(mktemp)"
trap 'rm -f "${PE_HELPER}"' EXIT

cat > "${PE_HELPER}" <<'PHP'
<?php
// Lee la tabla de importación de un PE (x86/x64) y devuelve las DLL que importa.
function pe_imports(string $file): array
{
    $d = @file_get_contents($file);
    if ($d === false || strlen($d) < 0x40) {
        fwrite(STDERR, "No puedo leer {$file}\n");
        exit(3);
    }
    $e_lfanew = unpack('V', substr($d, 0x3C, 4))[1];
    if (substr($d, $e_lfanew, 4) !== "PE\0\0") {
        fwrite(STDERR, "{$file} no es un PE válido\n");
        exit(3);
    }
    $coff = $e_lfanew + 4;
    $numSections = unpack('v', substr($d, $coff + 2, 2))[1];
    $optSize = unpack('v', substr($d, $coff + 16, 2))[1];
    $opt = $coff + 20;
    $magic = unpack('v', substr($d, $opt, 2))[1];
    $ddOff = $opt + ($magic === 0x20b ? 112 : 96);
    $impRva = unpack('V', substr($d, $ddOff + 8, 4))[1];

    $sections = [];
    $secOff = $opt + $optSize;
    for ($i = 0; $i < $numSections; $i++) {
        $s = $secOff + $i * 40;
        $sections[] = [
            'vsize' => unpack('V', substr($d, $s + 8, 4))[1],
            'vaddr' => unpack('V', substr($d, $s + 12, 4))[1],
            'rawsize' => unpack('V', substr($d, $s + 16, 4))[1],
            'rawptr' => unpack('V', substr($d, $s + 20, 4))[1],
        ];
    }
    $rva2off = static function (int $rva) use ($sections): ?int {
        foreach ($sections as $s) {
            if ($rva >= $s['vaddr'] && $rva < $s['vaddr'] + max($s['vsize'], $s['rawsize'])) {
                return $s['rawptr'] + ($rva - $s['vaddr']);
            }
        }
        return null;
    };

    $names = [];
    for ($i = 0; ; $i++) {
        $desc = $rva2off($impRva + $i * 20);
        if ($desc === null) {
            break;
        }
        $nameRva = unpack('V', substr($d, $desc + 12, 4))[1];
        if ($nameRva === 0) {
            break;
        }
        $off = $rva2off($nameRva);
        $end = strpos($d, "\0", $off);
        $names[] = substr($d, $off, $end - $off);
    }
    return $names;
}

// Cierre transitivo desde libpq.dll: sólo lo que hay que copiar al directorio del PHP.
$root = $argv[1];
$target = $argv[2];
$source = dirname($root);

$system = array_flip([
    'kernel32.dll', 'advapi32.dll', 'shell32.dll', 'shlwapi.dll', 'user32.dll', 'gdi32.dll',
    'ole32.dll', 'oleaut32.dll', 'ws2_32.dll', 'wldap32.dll', 'secur32.dll', 'ntdll.dll',
    'msvcrt.dll', 'vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll', 'ucrtbase.dll',
    'crypt32.dll', 'bcrypt.dll', 'version.dll', 'iphlpapi.dll', 'dnsapi.dll', 'netapi32.dll',
    'userenv.dll', 'rpcrt4.dll', 'sechost.dll', 'winmm.dll', 'imm32.dll', 'comdlg32.dll',
    'mswsock.dll', 'wsock32.dll', 'netmsg.dll', 'winsock.dll',
]);

$copy = [];
$queue = [$root];
$seen = [];
$unknown = [];

while ($queue) {
    $file = array_shift($queue);
    $key = strtolower(basename($file));
    if (isset($seen[$key])) {
        continue;
    }
    $seen[$key] = true;

    foreach (pe_imports($file) as $dep) {
        $lower = strtolower($dep);
        if (isset($system[$lower]) || str_starts_with($lower, 'api-ms-win-') || str_starts_with($lower, 'ext-ms-win-')) {
            continue;
        }
        if (is_file($target . DIRECTORY_SEPARATOR . $dep)) {
            continue; // el PHP ya la tiene (libcrypto/libssl se conservan a propósito)
        }
        if (!is_file($source . DIRECTORY_SEPARATOR . $dep)) {
            $unknown[] = $dep;
            continue;
        }
        $copy[$lower] = $dep;
        $queue[] = $source . DIRECTORY_SEPARATOR . $dep;
    }
}

if ($unknown !== []) {
    fwrite(STDERR, "Dependencias sin resolver: " . implode(', ', array_unique($unknown)) . "\n");
    exit(3);
}

foreach (array_values($copy) as $dep) {
    echo $dep, "\n";
}
PHP

echo
echo "=== 3/5 · Dependencias de la libpq entrante (leídas del binario) ==="
PE_OUT="$(mktemp)"
if ! "${PHP_EXE}" "${PE_HELPER}" "${PG_CLIENT_BIN}/libpq.dll" "${PHP_DIR}" > "${PE_OUT}"; then
    rm -f "${PE_OUT}"
    fail "No pude resolver las dependencias del libpq entrante (ver el error de arriba)."
fi
EXTRA_DLLS=()
while IFS= read -r dep; do
    [ -n "${dep}" ] && EXTRA_DLLS+=("${dep}")
done < "${PE_OUT}"
rm -f "${PE_OUT}"
ok "libpq.dll"
for dep in "${EXTRA_DLLS[@]}"; do
    ok "${dep}"
done

# --- 4. Sustitución con respaldo ---------------------------------------------
if [ "${needs_alignment}" -eq 0 ]; then
    echo
    echo "=== 4/5 · Sustitución ==="
    ok "El PHP ya enlaza libpq ${CURRENT_VERSION}: no hay nada que sustituir"
elif [ "${check_only}" -eq 1 ]; then
    echo
    echo "=== 4/5 · Sustitución ==="
    ok "Modo comprobación: no se toca nada (habría que sustituir ${CURRENT_VERSION} → ${SOURCE_VERSION})"
else
    echo
    echo "=== 4/5 · Sustitución ==="
    # Respaldo: el de la primera alineación si ya existe; si no, uno nuevo nombrado por la
    # versión que se está respaldando ahora.
    if BACKUP_DIR="$(resolve_backup_dir)"; then
        ok "Respaldo existente reutilizado: ${BACKUP_DIR}"
    else
        BACKUP_DIR="${PHP_DIR}/libpq-backup-${CURRENT_VERSION}"
        ok "Respaldo nuevo en ${BACKUP_DIR}"
    fi
    MANIFEST="${BACKUP_DIR}/MANIFEST.txt"
    mkdir -p "${BACKUP_DIR}"
    [ -f "${MANIFEST}" ] || : > "${MANIFEST}"

    # Sustituido: ya existía y se sobrescribe. Añadido: no existía y se incorpora.
    if [ ! -f "${BACKUP_DIR}/libpq.dll" ]; then
        cp -f "${PHP_DIR}/libpq.dll" "${BACKUP_DIR}/libpq.dll" \
            || fail "No pude respaldar libpq.dll antes de sustituirlo."
    fi
    grep -qx 'replaced libpq.dll' "${MANIFEST}" || echo 'replaced libpq.dll' >> "${MANIFEST}"

    for dep in "${EXTRA_DLLS[@]}"; do
        if [ -f "${PHP_DIR}/${dep}" ]; then
            [ -f "${BACKUP_DIR}/${dep}" ] || cp -f "${PHP_DIR}/${dep}" "${BACKUP_DIR}/${dep}"
            grep -qx "replaced ${dep}" "${MANIFEST}" || echo "replaced ${dep}" >> "${MANIFEST}"
        else
            grep -qx "added ${dep}" "${MANIFEST}" || echo "added ${dep}" >> "${MANIFEST}"
        fi
    done

    printf '%s\n' \
        "# Respaldo del PHP local antes de alinear su libpq (${CURRENT_VERSION} → ${SOURCE_VERSION})." \
        "# Formato: <replaced|added> <fichero>. Reversión: bash upgrade-local-libpq.sh --restore" \
        >> "${MANIFEST}"

    cp -f "${PG_CLIENT_BIN}/libpq.dll" "${PHP_DIR}/libpq.dll" \
        || fail "No pude sustituir libpq.dll en ${PHP_DIR}."
    for dep in "${EXTRA_DLLS[@]}"; do
        cp -f "${PG_CLIENT_BIN}/${dep}" "${PHP_DIR}/${dep}" \
            || fail "No pude copiar ${dep} a ${PHP_DIR}."
    done
    applied=1
    ok "Ficheros sustituidos; respaldo y manifiesto en ${BACKUP_DIR}"
fi

# --- 5. Verificación en ejecución --------------------------------------------
echo
echo "=== 5/5 · Verificación en ejecución ==="
NEW_VERSION="$(libpq_version || true)"
[ -n "${NEW_VERSION}" ] \
    || fail "Tras el cambio la extensión pgsql no carga: el PHP queda peor que estaba."

"${PHP_EXE}" -r 'exit(in_array("pgsql", PDO::getAvailableDrivers(), true) ? 0 : 1);' \
    || fail "El driver pdo_pgsql desapareció tras el cambio (revisa las dependencias copiadas)."

ok "libpq enlazada ahora: ${CURRENT_VERSION} → ${NEW_VERSION}"
ok "Driver pdo_pgsql disponible: ${PHP_EXE} -r 'echo implode(\",\", PDO::getAvailableDrivers());' → $( "${PHP_EXE}" -r 'echo implode(",", PDO::getAvailableDrivers());' )"

if [ "${applied}" -eq 1 ]; then
    write_state "${NEW_VERSION}" "${CURRENT_VERSION}"
    ok "Estado anotado en ${STATE_FILE}"
fi

# La puerta de identidad necesita el motor TLS: es la única forma de certificar que un
# certificado con `IP:127.0.0.1` verifica de verdad. Sin motor no se finge: se avisa.
if [ -z "${PG_TEST_DSN}" ] && [ -f "${SCRIPT_DIR}/.env.docker-tls" ]; then
    PG_TEST_DSN="$(sed -n "s/^export PG_TEST_DSN='\(.*\)'$/\1/p" "${SCRIPT_DIR}/.env.docker-tls" | head -1)"
    export PG_TEST_DSN
fi

if [ -z "${PG_TEST_DSN}" ]; then
    echo
    echo "[AVISO] El cambio está aplicado, pero no puedo certificar la verificación de"
    echo "  identidades sin el motor TLS: falta PG_TEST_DSN. Levántalo y repite la"
    echo "  comprobación sin tocar nada más:"
    echo "    bash deploy/local/postgres/provision-tls-engine.sh"
    echo "    bash deploy/local/postgres/upgrade-local-libpq.sh --check"
    exit 2
fi

if ! PG_TEST_DSN="${PG_TEST_DSN}" "${PHP_EXE}" "${SCRIPT_DIR}/check-local-libpq.php"; then
    echo >&2
    if [ "${applied}" -eq 1 ]; then
        echo "[FALLO] El cliente no certifica las dos formas del SAN. Revierto el cambio para" >&2
        echo "  no dejar el PHP con un libpq que no cumple:" >&2
        restore_backup
        echo "      libpq enlazada de nuevo: $(libpq_version)" >&2
    else
        echo "[FALLO] El cliente actual no certifica las dos formas del SAN (no había nada que revertir)." >&2
    fi
    exit 1
fi

echo
echo "======================================================================"
echo " CLIENTE LOCAL ALINEADO · libpq ${CURRENT_VERSION} → ${NEW_VERSION}"
echo "======================================================================"
echo " Las dos formas del SAN del certificado verifican con verify-full:"
echo "   · DNS:localhost   (nombre)"
echo "   · IP:127.0.0.1    (dirección, capacidad desde libpq 12)"
echo
echo " Reversión: bash deploy/local/postgres/upgrade-local-libpq.sh --restore"
if [ "${applied}" -eq 1 ]; then
    echo " Respaldo : ${BACKUP_DIR}"
fi
echo "======================================================================"
