#!/usr/bin/env bash
# =============================================================================
# VendGuard · Aprovisionamiento del motor local de la categoría de certificación
# del motor nuevo (PostgreSQL)
# =============================================================================
# RNF-04 · spec §9 duda 1 · T-HYBRID-21, T-HYBRID-24, T-HYBRID-27.
#
# Por qué existe: la batería hermética (`php tests/run_all.php`) corre sin red
# contra el motor local y no tiene canal de omisión. Las suites que exigen
# PostgreSQL forman la categoría de contrato del motor nuevo, con su propio canal
# y su propio informe, y necesitan un motor disponible. Esa provisión no existía
# en el repositorio: este script la declara y la materializa.
#
# Qué hace, en orden:
#   1. Localiza el cliente del motor y espera a que acepte conexiones.
#   2. Crea (idempotente) el rol de aplicación no superusuario y su base de datos.
#   3. Escribe el contrato de entorno en `.env.local` —no versionado— con el DSN.
#   4. Ejecuta la sonda `verify.php`, que falla en cerrado si el motor no sirve.
#
# Uso:
#   PG_SUPER_PASSWORD='<contraseña del superusuario>' bash deploy/local/postgres/provision.sh
#   source deploy/local/postgres/.env.local
#
# Variables:
#   PG_SUPER_PASSWORD  Obligatoria. Contraseña del superusuario que se fijó al instalar
#                      el motor (parámetro --superpassword del instalador). No tiene
#                      valor por defecto: sin credencial, el script no improvisa.
#   PG_SUPER_USER      Opcional. Por defecto `postgres`.
#   PG_TEST_PORT       Opcional. Por defecto 5432.
#   PG_BIN             Opcional. Directorio del cliente. Por defecto la instalación
#                      nativa de Windows en /c/Program Files/PostgreSQL/16/bin.
#
# Efectos: cada ejecución ROTA la contraseña del rol de aplicación y reescribe
# `.env.local`. Es intencionado: el archivo es la única fuente de verdad y volver a
# aprovisionar no deja credenciales antiguas vivas.
#
# Códigos de salida:
#   0 = motor provisionado y sonda en verde.
#   1 = motor ausente, sin responder, o sonda fallida (la categoría queda no ejecutable).
# =============================================================================

set -euo pipefail

PG_BIN="${PG_BIN:-/c/Program Files/PostgreSQL/16/bin}"
PG_TEST_HOST="${PG_TEST_HOST:-127.0.0.1}"
PG_TEST_PORT="${PG_TEST_PORT:-5432}"
PG_SUPER_USER="${PG_SUPER_USER:-postgres}"
APP_ROLE="vendguard_pg"
APP_DATABASE="vendguard_pg_test"
ENV_FILE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/.env.local"

fail() {
    echo "[APROVISIONAMIENTO FALLIDO] $1" >&2
    exit 1
}

# --- 1. Cliente del motor -----------------------------------------------------
if [ ! -x "${PG_BIN}/psql.exe" ] && [ ! -x "${PG_BIN}/psql" ]; then
    cat >&2 <<EOF
[APROVISIONAMIENTO FALLIDO] No encuentro el cliente de PostgreSQL en ${PG_BIN}.
  Si el motor no está instalado todavía, instálalo con permisos de administrador:
    winget install --id PostgreSQL.PostgreSQL.16 --exact --accept-package-agreements --accept-source-agreements --override "--mode unattended --unattendedmodeui none --superpassword <CONTRASEÑA> --serverport ${PG_TEST_PORT} --enable-components server,commandlinetools"
  Si está instalado en otra ruta, indícala:  PG_BIN=/ruta/hasta/bin bash $0
EOF
    exit 1
fi

PSQL="${PG_BIN}/psql.exe"
[ -x "$PSQL" ] || PSQL="${PG_BIN}/psql"
PG_ISREADY="${PG_BIN}/pg_isready.exe"
[ -x "$PG_ISREADY" ] || PG_ISREADY="${PG_BIN}/pg_isready"

[ -n "${PG_SUPER_PASSWORD:-}" ] || fail "Falta PG_SUPER_PASSWORD (contraseña del superusuario del motor). Sin credencial no se improvisa ningún valor por defecto."

export PGPASSWORD="$PG_SUPER_PASSWORD"

echo "=== 1/4 · Esperando al motor en ${PG_TEST_HOST}:${PG_TEST_PORT} ==="
ready=0
for attempt in $(seq 1 20); do
    if "$PG_ISREADY" -h "$PG_TEST_HOST" -p "$PG_TEST_PORT" -q; then
        ready=1
        break
    fi
    sleep 3
done
[ "$ready" -eq 1 ] || fail "El motor no acepta conexiones en ${PG_TEST_HOST}:${PG_TEST_PORT} tras 60 s (¿servicio detenido?)."

if [ -z "$("$PSQL" -h "$PG_TEST_HOST" -p "$PG_TEST_PORT" -U "$PG_SUPER_USER" -d postgres -Atc 'SELECT 1' 2>/dev/null)" ]; then
    fail "El motor responde pero rechaza la credencial de superusuario. Revisa PG_SUPER_USER y PG_SUPER_PASSWORD."
fi
echo "      Motor en marcha: $("$PSQL" -h "$PG_TEST_HOST" -p "$PG_TEST_PORT" -U "$PG_SUPER_USER" -d postgres -Atc "SELECT current_setting('server_version')")"

# --- 2. Rol de aplicación y base de la categoría ------------------------------
echo "=== 2/4 · Rol y base de la categoría ==="
APP_PASSWORD="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
[ ${#APP_PASSWORD} -eq 32 ] || fail "No se pudo generar la contraseña del rol de aplicación."

role_exists="$("$PSQL" -h "$PG_TEST_HOST" -p "$PG_TEST_PORT" -U "$PG_SUPER_USER" -d postgres -Atc "SELECT 1 FROM pg_roles WHERE rolname = '${APP_ROLE}'")"
if [ -z "$role_exists" ]; then
    "$PSQL" -h "$PG_TEST_HOST" -p "$PG_TEST_PORT" -U "$PG_SUPER_USER" -d postgres -v ON_ERROR_STOP=1 \
        -c "CREATE ROLE ${APP_ROLE} LOGIN PASSWORD '${APP_PASSWORD}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT" >/dev/null
    echo "      Rol ${APP_ROLE} creado (sin privilegios administrativos)"
else
    "$PSQL" -h "$PG_TEST_HOST" -p "$PG_TEST_PORT" -U "$PG_SUPER_USER" -d postgres -v ON_ERROR_STOP=1 \
        -c "ALTER ROLE ${APP_ROLE} PASSWORD '${APP_PASSWORD}'" >/dev/null
    echo "      Rol ${APP_ROLE} ya existía: contraseña rotada"
fi

db_exists="$("$PSQL" -h "$PG_TEST_HOST" -p "$PG_TEST_PORT" -U "$PG_SUPER_USER" -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname = '${APP_DATABASE}'")"
if [ -z "$db_exists" ]; then
    "$PSQL" -h "$PG_TEST_HOST" -p "$PG_TEST_PORT" -U "$PG_SUPER_USER" -d postgres -v ON_ERROR_STOP=1 \
        -c "CREATE DATABASE ${APP_DATABASE} OWNER ${APP_ROLE} ENCODING 'UTF8' TEMPLATE template0" >/dev/null
    echo "      Base ${APP_DATABASE} creada con ${APP_ROLE} como propietario"
else
    echo "      Base ${APP_DATABASE} ya existía"
fi

# --- 3. Contrato de entorno ---------------------------------------------------
echo "=== 3/4 · Contrato de entorno (no versionado) ==="
(
    umask 077
    printf '%s\n' \
        "# Generado por deploy/local/postgres/provision.sh — NO versionado." \
        "# Motor local de la categoría de certificación del motor nuevo (spec §9, duda 1)." \
        "# Uso: source deploy/local/postgres/.env.local" \
        "export PG_TEST_DSN='pgsql:host=${PG_TEST_HOST};port=${PG_TEST_PORT};dbname=${APP_DATABASE};user=${APP_ROLE};password=${APP_PASSWORD}'" \
        > "$ENV_FILE"
)
chmod 600 "$ENV_FILE" 2>/dev/null || true
echo "      Escrito ${ENV_FILE} (modo 600, ignorado por git)"

# --- 4. Sonda de disponibilidad ----------------------------------------------
echo "=== 4/4 · Sonda del motor nuevo ==="
# shellcheck disable=SC1090
. "$ENV_FILE"
php "$(dirname "${BASH_SOURCE[0]}")/verify.php" || fail "La sonda del motor nuevo no pasó: la categoría queda declarada como no ejecutable."

echo
echo "Listo. Para ejecutar la categoría del motor nuevo en esta sesión:"
echo "  source deploy/local/postgres/.env.local"
