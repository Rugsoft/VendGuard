#!/usr/bin/env bash
# =============================================================================
# VendGuard · Motor PostgreSQL 16 con TLS en contenedor + ejecución del inicializador
# =============================================================================
# RNF-05 · spec §9 duda 1 · T-HYBRID-12(2), T-HYBRID-21.
#
# Por qué existe: la ruta PostgreSQL de `ConnectionFactory` exige `sslmode=verify-full`
# con CA declarada y prohíbe degradar la verificación de identidad (RNF-05), sin
# excepción de loopback; el motor local del equipo no sirve TLS. Sin un motor que hable
# TLS, el camino feliz de `bin/init_cloud_db.php` —DDL base, migraciones del motor,
# semillas y puerta de verificación— no se podía ejecutar contra PostgreSQL en local y
# su certificación quedaba en inspección de código.
#
# Qué hace, en orden:
#   1. Genera (idempotente) la CA y el certificado del servidor con SAN IP:127.0.0.1.
#   2. Construye la imagen `deploy/local/postgres/tls/Dockerfile` (PostgreSQL 16 + TLS).
#   3. Levanta el contenedor con un rol de aplicación **no superusuario** y su base.
#   4. Espera a que el motor acepte conexiones, crea el rol de aplicación (sin SUPERUSER/
#      CREATEDB/CREATEROLE/INHERIT) y le cede la propiedad de la base, para que la
#      certificación corra como el mismo tipo de rol que en el motor local (política S-5)
#      y no con la credencial administrativa del motor.
#   5. Escribe el contrato de entorno en `.env.docker-tls` (no versionado, modo 600) con
#      `PG_TEST_DSN` y `DATABASE_URL` ya en forma TLS.
#   6. Ejecuta la puerta de identidad del cliente local (`check-local-libpq.php`: libpq
#      moderna y las dos formas del SAN verificando con `verify-full`), después la sonda
#      `verify.php` y después el inicializador completo, y comprueba el resultado por su
#      cuenta: inventario de tablas, libro de migraciones y guardián anti-borrado del
#      Art. III.1 respondiendo con `45000`.
#   7. Aprovisiona el **banco de la batería hermética**: una base propia (por defecto
#      `vendguard_battery`) con el **mismo** esquema y catálogo canónicos, en arranque en
#      limpio estricto (`VENDGUARD_DEMO_HISTORY=0`), y escribe su contrato en `.env.battery`
#      (no versionado, modo 600) con `DATABASE_URL` en forma TLS. Base distinta de la de la
#      categoría: el arnés crea sedes, máquinas y averías, y no puede contaminar la base
#      sobre la que la categoría mide el catálogo sembrado.
#
# Uso:
#   bash deploy/local/postgres/provision-tls-engine.sh
#   source deploy/local/postgres/.env.docker-tls
#   bash deploy/local/postgres/provision-tls-engine.sh --down    # retira el contenedor
#
# Banco de la batería (módulo 13, RNF-04):
#   source deploy/local/postgres/.env.battery     # declara DATABASE_URL (TLS verify-full)
#
# Variables:
#   PG_TLS_PORT       Opcional. Puerto publicado en el host. Por defecto 55433 (el 5432
#                     lo ocupa el motor local aprovisionado por `provision.sh`).
#   PG_TLS_CONTAINER  Opcional. Nombre del contenedor. Por defecto `vendguard-pg-tls`.
#   PG_TLS_VOLUME     Opcional. Volumen de datos. Por defecto `vendguard_pg_tls_data`.
#   PG_TLS_IMAGE      Opcional. Etiqueta de la imagen. Por defecto `vendguard-postgres-tls:16`.
#   DOCKER_BIN        Opcional. Directorio del cliente de Docker. Por defecto la
#                     instalación de Docker Desktop en /c/Program Files/Docker/Docker/resources/bin.
#
# Efectos: la primera pasada crea el volumen de datos; las siguientes lo reutilizan y
# rotan la contraseña del rol de aplicación. La clave privada del certificado vive en
# `tls/certs/`, que no se versiona.
#
# Códigos de salida:
#   0 = motor en marcha, inicializador verificado y guardián comprobado.
#   1 = Docker ausente, motor no disponible, inicializador fallido o esquema no verificado.
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
TLS_DIR="${SCRIPT_DIR}/tls"
CERTS_DIR="${TLS_DIR}/certs"
PASSWORD_FILE="${CERTS_DIR}/app-password"
BOOTSTRAP_PASSWORD_FILE="${CERTS_DIR}/bootstrap-password"
ENV_FILE="${SCRIPT_DIR}/.env.docker-tls"

CONTAINER_NAME="${PG_TLS_CONTAINER:-vendguard-pg-tls}"
VOLUME_NAME="${PG_TLS_VOLUME:-vendguard_pg_tls_data}"
IMAGE_NAME="${PG_TLS_IMAGE:-vendguard-postgres-tls:16}"
HOST_PORT="${PG_TLS_PORT:-55433}"
DOCKER_BIN="${DOCKER_BIN:-/c/Program Files/Docker/Docker/resources/bin}"

APP_ROLE="vendguard_pg"
APP_DATABASE="vendguard_pg_test"
TLS_HOST="127.0.0.1"

# Host con el que **el cliente** abre la conexión: la **dirección** que el certificado
# declara en su SAN (`IP:127.0.0.1`). Antes no era posible usarla: el PHP de XAMPP enlazaba
# libpq 11.4, que verifica el nombre contra los `dNSName` del SAN pero **no** contra las
# direcciones (`iPAddress`, soportadas desde PostgreSQL 12), así que el contrato tenía que
# abrir por `localhost` —nombre que el certificado también declara— para que la
# verificación pasara. El cliente local se alineó con la imagen desplegada
# (`upgrade-local-libpq.sh`, T-HYBRID-12) y el paso 6 exige ahora que **las dos** formas
# del SAN verifiquen y que un nombre no declarado sea rechazado. Con un cliente antiguo la
# puerta falla en cerrado con la instrucción para actualizarlo; no se degrada ninguna
# verificación para que el aprovisionamiento «pase».
TLS_HOST_CLIENT="${TLS_HOST}"

# El rol que la imagen crea con `POSTGRES_USER` es el **superusuario de arranque** del
# clúster, y PostgreSQL 16 se niega a degradarlo (no se le puede retirar SUPERUSER). Por
# eso el motor se levanta con una credencial administrativa propia y el rol de aplicación
# se crea aparte, ya sin privilegios: es el mismo reparto que hace `provision.sh` en el
# motor local, y el que la política S-5 exige para que la certificación sea honesta.
BOOTSTRAP_ROLE="${PG_TLS_BOOTSTRAP_ROLE:-vendguard_bootstrap}"

fail() {
    echo "[MOTOR TLS FALLIDO] $1" >&2
    exit 1
}

ok() {
    echo "      ✓ $1"
}

# openssl se invoca con ficheros de configuración en vez de con `-subj`: el bash de Git
# convierte los argumentos que empiezan por `/` en rutas de Windows, así que un sujeto
# como `/CN=127.0.0.1` llegaría a openssl como `C:/Program Files/Git/CN=127.0.0.1` y la
# orden moriría con «subject name is expected to be in the format /type0=value0/...».
# Con `-config` no hay ningún argumento que empiece por barra y las rutas POSIX siguen
# convirtiéndose como es debido. El error real se muestra en lugar de silenciarse.
tls_openssl() {
    local log
    log="$(mktemp)"

    if ! openssl "$@" >/dev/null 2>"${log}"; then
        tr -d '\r' < "${log}" >&2
        rm -f "${log}"
        fail "openssl falló en: openssl $*"
    fi

    rm -f "${log}"
}

# --- 0. Cliente de Docker y motor en marcha -----------------------------------
if ! command -v docker >/dev/null 2>&1; then
    if [ -x "${DOCKER_BIN}/docker.exe" ]; then
        PATH="${DOCKER_BIN}:${PATH}"
        export PATH
    fi
fi

command -v docker >/dev/null 2>&1 \
    || fail "No encuentro el cliente de Docker. Docker Desktop no está instalado o su directorio no es ${DOCKER_BIN}."

if ! docker info >/dev/null 2>&1; then
    fail "Docker responde pero el motor no: abre Docker Desktop y repite (el daemon tiene que estar en marcha)."
fi

# --- Retirada explícita -------------------------------------------------------
if [ "${1:-}" = "--down" ]; then
    if docker ps -a --format '{{.Names}}' | grep -qx "${CONTAINER_NAME}"; then
        docker rm -f "${CONTAINER_NAME}" >/dev/null
        echo "Contenedor «${CONTAINER_NAME}» retirado. El volumen «${VOLUME_NAME}» se conserva;"
        echo "para borrar también los datos: docker volume rm ${VOLUME_NAME}"
    else
        echo "No hay ningún contenedor «${CONTAINER_NAME}» que retirar."
    fi
    exit 0
fi

echo "======================================================================"
echo " VendGuard · Motor PostgreSQL 16 con TLS (categoría del motor nuevo)"
echo "======================================================================"
echo

# --- 1. Material TLS ----------------------------------------------------------
echo "=== 1/7 · Material TLS (CA y certificado del servidor con SAN IP:${TLS_HOST}) ==="
mkdir -p "${CERTS_DIR}"

# Consulta dentro del contenedor como el rol de aplicación, nunca como el superusuario del
# motor: es el rol con el que la categoría certifica y con el que tiene que pasar todo. La
# conexión va por el socket local del contenedor, que la imagen deja en `trust`; el camino
# real de la aplicación (TCP + SCRAM + TLS desde el host) lo ejerce el inicializador.
psql_app() {
    docker exec "${CONTAINER_NAME}" \
        psql -U "${APP_ROLE}" -d "${APP_DATABASE}" -v ON_ERROR_STOP=1 "$@"
}

# Credencial administrativa del motor: sólo para crear el rol de aplicación y cederle la
# propiedad de la base. Ninguna comprobación de la certificación se ejecuta con ella.
psql_admin() {
    docker exec "${CONTAINER_NAME}" \
        psql -U "${BOOTSTRAP_ROLE}" -d "${APP_DATABASE}" -v ON_ERROR_STOP=1 "$@"
}

certificates_are_valid() {
    [ -f "${CERTS_DIR}/ca.crt" ] && [ -f "${CERTS_DIR}/server.crt" ] \
        && openssl verify -CAfile "${CERTS_DIR}/ca.crt" "${CERTS_DIR}/server.crt" >/dev/null 2>&1
}

if certificates_are_valid; then
    ok "Certificados ya presentes y verificados contra su CA"
else
    command -v openssl >/dev/null 2>&1 \
        || fail "No encuentro openssl para generar el material TLS (viene con Git for Windows)."

    # El certificado del servidor declara el nombre al que apunta el cliente: sin el SAN
    # `verify-full` rechazaría la conexión, que es exactamente lo que debe hacer.
    cat > "${CERTS_DIR}/ca.cnf" <<'CNF'
[ req ]
distinguished_name = dn
x509_extensions    = v3_ca
prompt             = no

[ dn ]
CN = VendGuard Motor Nuevo CA local de la categoria

[ v3_ca ]
basicConstraints = critical,CA:TRUE
keyUsage         = critical,keyCertSign,cRLSign
CNF

    cat > "${CERTS_DIR}/server.cnf" <<CNF
[ req ]
distinguished_name = dn
prompt             = no

[ dn ]
CN = ${TLS_HOST}

[ v3_server ]
basicConstraints = CA:FALSE
keyUsage         = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName   = IP:${TLS_HOST},DNS:localhost
CNF

    # La CA es local y efímera: sólo existe para que `verify-full` tenga una raíz de
    # confianza con la que comprobar la identidad de este motor de certificación.
    tls_openssl genrsa -out "${CERTS_DIR}/ca.key" 3072
    tls_openssl req -x509 -new -key "${CERTS_DIR}/ca.key" -sha256 -days 3650 \
        -out "${CERTS_DIR}/ca.crt" -config "${CERTS_DIR}/ca.cnf"

    tls_openssl genrsa -out "${CERTS_DIR}/server.key" 3072
    tls_openssl req -new -key "${CERTS_DIR}/server.key" -out "${CERTS_DIR}/server.csr" \
        -config "${CERTS_DIR}/server.cnf"
    tls_openssl x509 -req -in "${CERTS_DIR}/server.csr" \
        -CA "${CERTS_DIR}/ca.crt" -CAkey "${CERTS_DIR}/ca.key" -CAcreateserial \
        -out "${CERTS_DIR}/server.crt" -days 3650 -sha256 \
        -extfile "${CERTS_DIR}/server.cnf" -extensions v3_server

    certificates_are_valid || fail "El certificado generado no verifica contra su propia CA."

    ok "CA y certificado del servidor generados (SAN IP:${TLS_HOST})"
fi

# --- 2. Imagen ----------------------------------------------------------------
echo
echo "=== 2/7 · Imagen del motor (PostgreSQL 16 + TLS) ==="
docker build -t "${IMAGE_NAME}" "${TLS_DIR}" >/dev/null \
    || fail "La construcción de la imagen falló (revisa deploy/local/postgres/tls/Dockerfile)."
ok "Imagen «${IMAGE_NAME}» construida"

# --- 3. Contenedor ------------------------------------------------------------
echo
echo "=== 3/7 · Contenedor «${CONTAINER_NAME}» en el puerto ${HOST_PORT} ==="
if [ -f "${PASSWORD_FILE}" ]; then
    APP_PASSWORD="$(cat "${PASSWORD_FILE}")"
else
    APP_PASSWORD="$(openssl rand -hex 16)"
    (umask 077 && printf '%s' "${APP_PASSWORD}" > "${PASSWORD_FILE}")
fi
[ -n "${APP_PASSWORD}" ] || fail "No se pudo resolver la contraseña del rol de aplicación."

if [ -f "${BOOTSTRAP_PASSWORD_FILE}" ]; then
    BOOTSTRAP_PASSWORD="$(cat "${BOOTSTRAP_PASSWORD_FILE}")"
else
    BOOTSTRAP_PASSWORD="$(openssl rand -hex 16)"
    (umask 077 && printf '%s' "${BOOTSTRAP_PASSWORD}" > "${BOOTSTRAP_PASSWORD_FILE}")
fi
[ -n "${BOOTSTRAP_PASSWORD}" ] || fail "No se pudo resolver la contraseña administrativa del motor."

if docker ps -a --format '{{.Names}}' | grep -qx "${CONTAINER_NAME}"; then
    docker start "${CONTAINER_NAME}" >/dev/null
    ok "Contenedor existente reiniciado (datos conservados en el volumen «${VOLUME_NAME}»)"
else
    docker run -d \
        --name "${CONTAINER_NAME}" \
        -e POSTGRES_USER="${BOOTSTRAP_ROLE}" \
        -e POSTGRES_PASSWORD="${BOOTSTRAP_PASSWORD}" \
        -e POSTGRES_DB="${APP_DATABASE}" \
        -p "${HOST_PORT}:5432" \
        -v "${VOLUME_NAME}:/var/lib/postgresql/data" \
        "${IMAGE_NAME}" >/dev/null
    ok "Contenedor creado (base «${APP_DATABASE}» y rol «${APP_ROLE}» del motor)"
fi

# --- 4. Disponibilidad y política S-5 ----------------------------------------
echo
echo "=== 4/7 · Disponibilidad del motor y rol de aplicación ==="
ready=0
for _attempt in $(seq 1 30); do
    if docker exec "${CONTAINER_NAME}" pg_isready -h "${TLS_HOST}" -q >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 2
done
[ "${ready}" -eq 1 ] || fail "El motor no acepta conexiones tras 60 s (revisa: docker logs ${CONTAINER_NAME})."

# Rol de aplicación: sin privilegios administrativos y propietario de su base, para que la
# certificación corra exactamente como en el motor local aprovisionado (política S-5).
ROLE_EXISTS="$(psql_admin -tA -c "SELECT 1 FROM pg_roles WHERE rolname = '${APP_ROLE}'")"
if [ "${ROLE_EXISTS}" = "1" ]; then
    psql_admin -q -c "ALTER ROLE ${APP_ROLE} WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT PASSWORD '${APP_PASSWORD}'" >/dev/null \
        || fail "No se pudo rebajar el rol de aplicación a la política S-5."
else
    psql_admin -q -c "CREATE ROLE ${APP_ROLE} WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT PASSWORD '${APP_PASSWORD}'" >/dev/null \
        || fail "No se pudo crear el rol de aplicación."
fi
psql_admin -q -c "ALTER DATABASE ${APP_DATABASE} OWNER TO ${APP_ROLE}" >/dev/null \
    || fail "No se pudo ceder la propiedad de la base al rol de aplicación."

ROLE_FLAGS="$(psql_app -tA \
    -c "SELECT rolsuper::text || '|' || rolcreatedb::text || '|' || rolcreaterole::text || '|' || rolinherit::text FROM pg_roles WHERE rolname = '${APP_ROLE}'")"
[ "${ROLE_FLAGS}" = "false|false|false|false" ] \
    || fail "El rol «${APP_ROLE}» conserva privilegios administrativos (${ROLE_FLAGS})."
ok "Motor disponible y rol «${APP_ROLE}» sin SUPERUSER, CREATEDB, CREATEROLE ni INHERIT"

# --- 5. Contrato de entorno ---------------------------------------------------
echo
echo "=== 5/7 · Contrato de entorno de la categoría (no versionado, modo 600) ==="
CERTS_WIN="$(cd "${CERTS_DIR}" && pwd -W)" \
    || fail "No se pudo resolver la ruta de Windows del material TLS (¿bash de Git?)."

TLS_DSN="pgsql:host=${TLS_HOST_CLIENT};port=${HOST_PORT};dbname=${APP_DATABASE};user=${APP_ROLE};password=${APP_PASSWORD};sslmode=verify-full;sslrootcert=${CERTS_WIN}/ca.crt"

(
    umask 077
    printf '%s\n' \
        "# Generado por deploy/local/postgres/provision-tls-engine.sh — NO versionado." \
        "# Motor PostgreSQL 16 con TLS en contenedor, para la ruta PostgreSQL de la" \
        "# aplicación (RNF-05: verify-full con CA declarada, sin degradar)." \
        "#" \
        "# Sólo se declara PG_TEST_DSN, la variable de la categoría del motor nuevo." \
        "# DATABASE_URL NO se exporta a propósito: ConnectionFactory la lee antes que nada y" \
        "# haría que la batería hermética (MariaDB) resolviera contra este motor. El" \
        "# inicializador se invoca con la variable delante, en su propio proceso:" \
        "#   DATABASE_URL=\"\${PG_TEST_DSN}\" php bin/init_cloud_db.php" \
        "# Uso: source deploy/local/postgres/.env.docker-tls" \
        "# Canal completo: php tests/run_new_engine.php" \
        "export PG_TEST_DSN='${TLS_DSN}'" \
        > "${ENV_FILE}"
)
chmod 600 "${ENV_FILE}" 2>/dev/null || true
ok "Escrito ${ENV_FILE} (modo 600, ignorado por git)"
echo "      Canal de la categoría: php tests/run_new_engine.php"

export PG_TEST_DSN="${TLS_DSN}"

# Puerta de identidad del cliente local: exige una libpq que verifique direcciones del SAN
# (capacidad desde PostgreSQL 12) y ejerce la verificación contra el certificado real. Es lo
# que garantiza que este motor se use con la **misma** verificación de identidad que la
# imagen desplegada, y no con una más laxa.
php "${SCRIPT_DIR}/check-local-libpq.php" \
    || fail "El cliente PostgreSQL del PHP local no certifica las dos formas del SAN. Alínealo con deploy/local/postgres/upgrade-local-libpq.sh y repite."
ok "Puerta de identidad del cliente local en verde (libpq 16 y las dos formas del SAN)"

php "${SCRIPT_DIR}/verify.php" >/dev/null \
    || fail "La sonda del motor nuevo no pasó contra el motor TLS."
ok "Sonda verify.php en verde contra el motor TLS"

# --- 6. Inicializador completo ------------------------------------------------
echo
echo "=== 6/7 · Inicializador completo contra el motor (DDL + migraciones + semillas) ==="
INIT_LOG="$(mktemp)"
# DATABASE_URL se declara sólo para este proceso: la batería hermética del shell no la ve.
if ! DATABASE_URL="${TLS_DSN}" php "${PROJECT_ROOT}/bin/init_cloud_db.php" >"${INIT_LOG}" 2>&1; then
    cat "${INIT_LOG}" >&2
    rm -f "${INIT_LOG}"
    fail "El inicializador salió con error: el esquema no quedó verificado."
fi
sed -n 's/^/      /p' "${INIT_LOG}"
rm -f "${INIT_LOG}"

# Comprobación independiente del propio inicializador: inventario, libro y guardián.
TABLES="$(psql_app -tA \
    -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'")"
LEDGER="$(psql_app -tA -c 'SELECT COUNT(*) FROM schema_migrations')"
echo "      · Tablas en el esquema public: ${TABLES} (22 del dominio + schema_migrations = 23)"
[ "${TABLES}" = "23" ] || [ "${TABLES}" = "21" ] || fail "Inventario inesperado tras el inicializador (${TABLES} tablas)."
[ "${LEDGER}" -ge 1 ] || fail "El libro de migraciones quedó vacío."

# El guardián del Art. III.1 tiene que morder en el motor recién inicializado, y la ventana
# de purga (señal de sesión, en la forma del motor activo) seguir funcionando sobre él. Las
# dos comprobaciones usan una fila desechable: ninguna fila sembrada se toca.
psql_app -q -c "INSERT INTO locations (site_code, site_code_key, name, address, latitude, longitude, contact_name, contact_phone, is_active) VALUES ('SEDE-TEST-PURGE', 'sede-test-purge', 'Sede desechable de la comprobacion', 'Carrer Prova 1, Barcelona', 41.39, 2.19, 'Contacto Prueba', '600000999', TRUE)" >/dev/null \
    || fail "No se pudo plantar la fila desechable de la comprobación del guardián."

if psql_app -q -c "DELETE FROM locations WHERE site_code = 'SEDE-TEST-PURGE'" >/dev/null 2>&1; then
    fail "El guardián anti-borrado del Art. III.1 no rechazó un DELETE en el motor inicializado."
fi

# La señal se declara en la sesión (`set_config(..., false)`) y la sentencia va detrás en la
# misma sesión: es exactamente el camino que usan el limpiador del arnés y las suites.
psql_app -q \
    -c "SELECT set_config('vendguard.purge', 'on', false)" \
    -c "DELETE FROM locations WHERE site_code = 'SEDE-TEST-PURGE'" >/dev/null 2>&1 \
    || fail "La ventana de purga del arnés no permitió el borrado en el motor inicializado."

PURGED="$(psql_app -tA -c "SELECT COUNT(*) FROM locations WHERE site_code = 'SEDE-TEST-PURGE'")"
[ "${PURGED}" = "0" ] || fail "La ventana de purga no retiró la fila desechable (${PURGED} restantes)."

SEEDED="$(psql_app -tA -c 'SELECT COUNT(*) FROM locations WHERE deleted_at IS NULL')"
[ "${SEEDED}" = "10" ] || fail "El catálogo sembrado no quedó intacto tras las comprobaciones (${SEEDED} sedes)."

# --- 7. Banco de la batería hermética ----------------------------------------
# La batería hermética (RNF-04) necesita su **propio** banco. Corre las suites del arnés,
# que crean sedes, máquinas, usuarios y averías; si compartiera base con la categoría, una
# corrida dejaría a la categoría midiendo un catálogo contaminado —`SchemaConvergenceParityTest`
# cuenta precisamente las 10 sedes sembradas—. Mismo esquema y mismo catálogo canónicos
# (los aplica el inicializador), base distinta.
echo
echo "=== 7/7 · Banco de la batería hermética (base propia del arnés) ==="

BATTERY_DATABASE="${PG_BATTERY_DATABASE:-vendguard_battery}"
BATTERY_ENV_FILE="${SCRIPT_DIR}/.env.battery"

BATTERY_EXISTS="$(docker exec "${CONTAINER_NAME}" \
    psql -U "${BOOTSTRAP_ROLE}" -d postgres -tA \
    -c "SELECT 1 FROM pg_database WHERE datname = '${BATTERY_DATABASE}'")"
if [ "${BATTERY_EXISTS}" = "1" ]; then
    ok "Base «${BATTERY_DATABASE}» ya existía"
else
    docker exec "${CONTAINER_NAME}" \
        psql -U "${BOOTSTRAP_ROLE}" -d postgres -v ON_ERROR_STOP=1 \
        -c "CREATE DATABASE ${BATTERY_DATABASE} OWNER ${APP_ROLE} ENCODING 'UTF8' TEMPLATE template0" >/dev/null \
        || fail "No se pudo crear la base de la batería (${BATTERY_DATABASE})."
    ok "Base «${BATTERY_DATABASE}» creada con «${APP_ROLE}» como propietario"
fi

# El DSN del banco se reconstruye en vez de retocar el de la categoría: así el puerto, la
# CA y la verificación son literalmente los mismos y ninguna sustitución de cadena puede
# dejarlos apuntando a otro sitio.
BATTERY_DSN="pgsql:host=${TLS_HOST_CLIENT};port=${HOST_PORT};dbname=${BATTERY_DATABASE};user=${APP_ROLE};password=${APP_PASSWORD};sslmode=verify-full;sslrootcert=${CERTS_WIN}/ca.crt"

# Arranque en limpio estricto (EARS-02.6): catálogo maestro completo y **ninguna** avería de
# demostración. Las diecisiete `INC-DEMO-*` ocupan su máquina durante la ventana de garantía
# de 48 h (`uq_machine_active_ticket`, Art. V.2 y V.6) y las fixtures del arnés crean sus
# averías sobre las máquinas del catálogo: con historial, el banco no sería más completo,
# sería un banco con colisiones.
BATTERY_LOG="$(mktemp)"
if ! VENDGUARD_DEMO_HISTORY=0 DATABASE_URL="${BATTERY_DSN}" \
    php "${PROJECT_ROOT}/bin/init_cloud_db.php" >"${BATTERY_LOG}" 2>&1; then
    cat "${BATTERY_LOG}" >&2
    rm -f "${BATTERY_LOG}"
    fail "El inicializador falló contra la base de la batería: el banco no quedó verificado."
fi
sed -n 's/^/      /p' "${BATTERY_LOG}"
rm -f "${BATTERY_LOG}"

# Comprobación independiente del inicializador, y en la base de la batería: inventario,
# libro de migraciones, catálogo del arranque en limpio y ausencia de histórico.
psql_battery() {
    docker exec "${CONTAINER_NAME}" \
        psql -U "${APP_ROLE}" -d "${BATTERY_DATABASE}" -v ON_ERROR_STOP=1 "$@"
}

BATTERY_TABLES="$(psql_battery -tA -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public'")"
BATTERY_LEDGER="$(psql_battery -tA -c 'SELECT COUNT(*) FROM schema_migrations')"
BATTERY_LOCATIONS="$(psql_battery -tA -c 'SELECT COUNT(*) FROM locations WHERE deleted_at IS NULL')"
BATTERY_MACHINES="$(psql_battery -tA -c 'SELECT COUNT(*) FROM machines WHERE deleted_at IS NULL')"
BATTERY_USERS="$(psql_battery -tA -c 'SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')"
BATTERY_INCIDENTS="$(psql_battery -tA -c 'SELECT COUNT(*) FROM incidents')"

echo "      · Esquema: ${BATTERY_TABLES} tablas en public · libro: ${BATTERY_LEDGER} migración(es)"
echo "      · Catálogo: ${BATTERY_LOCATIONS} sedes · ${BATTERY_MACHINES} máquinas · ${BATTERY_USERS} usuarios · averías: ${BATTERY_INCIDENTS}"
[ "${BATTERY_TABLES}" = "23" ] || [ "${BATTERY_TABLES}" = "21" ] \
    || fail "Inventario inesperado en el banco de la batería (${BATTERY_TABLES} tablas, esperadas 23)."
[ "${BATTERY_LEDGER}" -ge 1 ] \
    || fail "El libro de migraciones del banco de la batería quedó vacío."
[ "${BATTERY_LOCATIONS}" = "10" ] \
    || fail "El catálogo del banco no tiene las 10 sedes del arranque en limpio (${BATTERY_LOCATIONS})."
[ "${BATTERY_MACHINES}" -ge 13 ] \
    || fail "El catálogo del banco no tiene las 13 máquinas del arranque en limpio (${BATTERY_MACHINES})."
[ "${BATTERY_USERS}" -ge 6 ] \
    || fail "El catálogo del banco no tiene los 6 usuarios internos del arranque en limpio (${BATTERY_USERS})."
[ "${BATTERY_INCIDENTS}" = "0" ] \
    || fail "El banco de la batería arrancó con histórico de demostración (${BATTERY_INCIDENTS} averías)."

(
    umask 077
    printf '%s\n' \
        "# Generado por deploy/local/postgres/provision-tls-engine.sh — NO versionado." \
        "# Contrato de entorno del BANCO DE LA BATERÍA HERMÉTICA (RNF-04 · módulo 13)." \
        "#" \
        "# Motor: PostgreSQL 16 con TLS en el contenedor ${CONTAINER_NAME}, puerto ${HOST_PORT}." \
        "# Base: ${BATTERY_DATABASE} (propia del arnés; la categoría del motor nuevo usa" \
        "# ${APP_DATABASE}). Esquema y catálogo canónicos, en arranque en limpio: sin las" \
        "# averías de demostración de EARS-02.6." \
        "#" \
        "# Se declara DATABASE_URL —y no PG_TEST_DSN— porque es la variable que" \
        "# ConnectionFactory lee primero: la batería tiene que resolver su motor por la misma" \
        "# ruta que la aplicación. La verificación de identidad es la del despliegue" \
        "# (verify-full contra la CA local), sin excepción de loopback (RNF-05)." \
        "#" \
        "# Uso: source deploy/local/postgres/.env.battery" \
        "export DATABASE_URL='${BATTERY_DSN}'" \
        > "${BATTERY_ENV_FILE}"
)
# `chmod 600` se aplica donde el sistema de ficheros lo honra (POSIX). En Windows no impone
# permisos de acceso: allí la protección del contrato es la regla de .gitignore, que el
# aprovisionamiento comprueba en vez de suponerla.
chmod 600 "${BATTERY_ENV_FILE}" 2>/dev/null || true
if git -C "${PROJECT_ROOT}" check-ignore -q "deploy/local/postgres/$(basename "${BATTERY_ENV_FILE}")"; then
    ok "Escrito ${BATTERY_ENV_FILE} (excluido por git; chmod 600 donde el sistema lo honra)"
else
    fail "El contrato del banco (${BATTERY_ENV_FILE}) no está excluido por .gitignore: una credencial podría versionarse."
fi

echo
echo "======================================================================"
echo " MOTOR TLS LISTO · inicializador verificado · banco de la batería aprovisionado"
echo "======================================================================"
echo " Contenedor        : ${CONTAINER_NAME} (puerto ${HOST_PORT} → 5432)"
echo " Base / rol        : ${APP_DATABASE} / ${APP_ROLE} (sin privilegios administrativos)"
echo " Contrato entorno  : ${ENV_FILE}"
echo " Guardián Art. III.1: responde 45000 y cede sólo con la ventana de purga"
echo " Identidad del cliente: verify-full contra DNS:localhost e IP:127.0.0.1"
echo " Banco de la batería: ${BATTERY_DATABASE} · contrato en ${BATTERY_ENV_FILE}"
echo
echo " Para usarlo en la categoría del motor nuevo:"
echo "   source deploy/local/postgres/.env.docker-tls"
echo "   php deploy/local/postgres/verify.php"
echo
echo " Para usarlo como banco de la batería hermética:"
echo "   source deploy/local/postgres/.env.battery"
echo
echo " Para retirarlo:"
echo "   bash deploy/local/postgres/provision-tls-engine.sh --down"
echo "======================================================================"
