# syntax=docker/dockerfile:1
# =============================================================================
# VendGuard · PostgreSQL 16 con TLS verificado en contenedor
# =============================================================================
# RNF-05 · spec §9 duda 1 · T-HYBRID-12(2) y T-HYBRID-21.
#
# Por qué existe: la ruta PostgreSQL de `ConnectionFactory` exige `sslmode=verify-full`
# con la CA declarada y **prohíbe degradar** la verificación (RNF-05), sin excepción de
# loopback. El motor local del equipo no sirve TLS, así que el camino feliz del
# inicializador (`bin/init_cloud_db.php`: DDL base + `database/supabase_migrations/` +
# semillas + puerta de verificación) no se podía ejecutar contra PostgreSQL en local.
# Esta imagen levanta un motor equivalente — misma versión mayor que el aprovisionado
# (16) — que **sí** habla TLS, para poder ejecutarlo de verdad.
#
# Por qué el material TLS se copia a la imagen en vez de montarse: PostgreSQL exige que
# la clave privada pertenezca al usuario del motor y tenga modo 600; un montaje desde
# Windows no garantiza ni propietario ni modo, y el motor arrancaría sin TLS o no
# arrancaría. Los ficheros de `certs/` los genera el aprovisionamiento y **no se
# versionan** (`.gitignore`): una clave privada no tiene sitio en el repositorio.
#
# El certificado del servidor lleva `subjectAltName = IP:127.0.0.1`, que es el nombre al
# que apunta el cliente: `verify-full` comprueba la identidad además de la cadena, de
# modo que un SAN ausente o equivocado haría fallar la conexión a propósito.
# =============================================================================

FROM postgres:16-alpine

COPY certs/ca.crt     /etc/vendguard/tls/ca.crt
COPY certs/server.crt /etc/vendguard/tls/server.crt
COPY certs/server.key /etc/vendguard/tls/server.key

RUN chown -R postgres:postgres /etc/vendguard/tls \
    && chmod 644 /etc/vendguard/tls/ca.crt /etc/vendguard/tls/server.crt \
    && chmod 600 /etc/vendguard/tls/server.key

# TLS activo desde el arranque: el motor no se levanta sin certificado.
CMD ["postgres", \
     "-c", "ssl=on", \
     "-c", "ssl_cert_file=/etc/vendguard/tls/server.crt", \
     "-c", "ssl_key_file=/etc/vendguard/tls/server.key"]
