# Motor local de la categoría de certificación del motor nuevo

Artefacto de aprovisionamiento del PostgreSQL local que necesita la **certificación de
contrato del motor nuevo** (RNF-04, spec §9 duda 1, tareas T-HYBRID-21, T-HYBRID-24 y
T-HYBRID-27).

**Por qué vive aquí y no en la batería hermética:** `php tests/run_all.php` descubre por
`glob` y no tiene canal de omisión, y RNF-04 exige que la batería completa corra sin red
contra el motor local (MariaDB). Las suites que exigen PostgreSQL no pueden mezclarse con
ella sin romper el verde offline o sin saltarse suites en silencio, así que forman una
categoría propia con su canal y su informe. Este directorio aporta lo que esa categoría
necesitaba y no existía: un motor real, disponible y comprobable.

## Qué queda aprovisionado

| Pieza | Estado |
| :--- | :--- |
| Motor | PostgreSQL **16.15**, instalación nativa de EnterpriseDB en `C:\Program Files\PostgreSQL\16` |
| Servicio | `postgresql-x64-16`, arranque automático, escuchando en el puerto **5432** |
| Cliente | `C:\Program Files\PostgreSQL\16\bin` (`psql`, `pg_isready`, `pg_ctl`, `createdb`, `pg_dump`) |
| Base de la categoría | `vendguard_pg_test`, propietaria del rol de aplicación |
| Rol de aplicación | `vendguard_pg` — `LOGIN`, **sin** `SUPERUSER`, `CREATEDB`, `CREATEROLE` ni `INHERIT` |
| Autenticación | `pg_hba.conf` sólo admite loopback (`127.0.0.1/32` y `::1/128`) con `scram-sha-256` |
| Driver en el PHP local | `pdo_pgsql` activado en el `php.ini` de XAMPP (línea 947; copia previa en `php.ini.bak-vendguard-20261013`) |
| Extensiones | `uuid-ossp` 1.1 y `pgcrypto` 1.3, ambas **trusted**: el propietario de la base puede instalarlas |
| Contrato de entorno | `deploy/local/postgres/.env.local` (no versionado, modo 600) |

## Contrato de entorno

Una sola variable, sin valor por defecto y sin credenciales en código versionado:

```text
PG_TEST_DSN = pgsql:host=127.0.0.1;port=5432;dbname=vendguard_pg_test;user=vendguard_pg;password=…
```

- La **batería hermética no la lee nunca**: sigue usando el motor local de MariaDB.
- Las suites de la categoría del motor nuevo leen `PG_TEST_DSN` y **no** tienen valores
  por defecto: si falta, la categoría se declara no ejecutable en vez de omitirse.
- El valor lo genera el aprovisionamiento; `PG_TEST_DSN` no se escribe jamás en un
  archivo versionado ni en un informe.

## Cómo se aprovisiona

Máquina sin motor (requiere permisos de administrador; el instalador de EnterpriseDB
registra el servicio y no tiene vía sin elevación):

```bash
winget install --id PostgreSQL.PostgreSQL.16 --exact --accept-package-agreements --accept-source-agreements \
  --override "--mode unattended --unattendedmodeui none --superpassword <CONTRASEÑA> --serverport 5432 --enable-components server,commandlinetools"
```

Después, y también en cada reejecución:

```bash
PG_SUPER_PASSWORD='<contraseña del superusuario>' bash deploy/local/postgres/provision.sh
source deploy/local/postgres/.env.local
```

`provision.sh` es idempotente: espera al motor, crea el rol y la base si faltan, **rota la
contraseña del rol de aplicación** y reescribe `.env.local` en cada pasada (el archivo es
la única fuente de verdad, de modo que volver a aprovisionar no deja credenciales antiguas
vivas), y termina ejecutando la sonda. Sin `PG_SUPER_PASSWORD` no improvisa credenciales:
falla.

## Cómo se comprueba

```bash
php deploy/local/postgres/verify.php        # con el contrato cargado en el entorno
```

La sonda certifica conexión real por `pdo_pgsql`, versión del motor, que el rol **no** es
superusuario, que puede crear y escribir tablas, y que su transacción se revierte sin
dejar residuo. Termina en verde con la línea `MOTOR NUEVO DISPONIBLE`.

Fallo cerrado, que es la mitad importante del contrato:

```bash
env -u PG_TEST_DSN php deploy/local/postgres/verify.php; echo "exit=$?"   # exit=1
```

Sin credencial no hay verde ni omisión silenciosa: hay un mensaje explícito y código de
salida 1.

## Límites y hallazgos de este motor local

1. **`pg_cron` y `pg_net` no existen en esta instalación.** T-HYBRID-01 enumera `pg_cron`
   entre las extensiones del DDL base y `pg_net` en la variante de orquestador interno:
   escrito de forma incondicional, ese DDL fallaría en el motor donde se certifica. El DDL
   debe instalar el programador interno **solo** en la variante admitida —la que decide no
   usar el reloj del contenedor—, coherente con la decisión ratificada de que el reloj
   canónico vive en el contenedor.
2. **No hay pooler.** Supavisor en modo transacción es parte del camino remoto; aquí la
   conexión es directa, de modo que las garantías del pooler (y sus límites: sentencias
   preparadas, estado de sesión, *advisory locks*) no se pueden certificar localmente.
3. **La medición de RNF-01 sigue abierta.** Este aprovisionamiento cubre la ejecución
   **ordinaria** de la categoría; la medición de latencia contra el motor remoto exige un
   proyecto Supabase con credenciales y se informa por separado (T-HYBRID-27).
4. **`pdo_pgsql` del contenedor es otra tarea.** Aquí se ha activado el driver del PHP
   local de XAMPP; la imagen sigue necesitando su propia instalación (T-HYBRID-12).
5. **Endurecimiento opcional pendiente de decidir:** el instalador deja
   `listen_addresses = '*'` en `postgresql.conf`. La autenticación ya está limitada a
   loopback por `pg_hba.conf`, así que ninguna conexión externa puede autenticarse; si se
   quiere cerrar también el puerto a nivel de interfaz:

   ```powershell
   # En una consola elevada
   psql -U postgres -c "ALTER SYSTEM SET listen_addresses = 'localhost'"
   net stop postgresql-x64-16 && net start postgresql-x64-16
   ```

## Lo que todavía no está cableado

Este directorio aporta el motor, el contrato y la sonda. La **marca de categoría** de las
suites y el **canal propio** de ejecución e informe —de modo que `run_all.php` no las
confunda con suites herméticas— corresponden a T-HYBRID-21 y T-HYBRID-22. Mientras esas
suites no existan, la categoría no tiene suites que ejecutar: su puerta ya está disponible
y se comprueba con la sonda.

## Credenciales

- Las del rol de aplicación son locales, se generan al aprovisionar y viven en
  `.env.local`, ignorado por git (`.gitignore`, patrón `.env.local`).
- La contraseña del superusuario es la que se fijó con `--superpassword` al instalar y
  sólo se usa para aprovisionar; la categoría no la necesita.
- Ninguna de las dos es una credencial de producción ni se reutiliza en el motor remoto.
