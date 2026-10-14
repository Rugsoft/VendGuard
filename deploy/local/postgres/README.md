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

## Ruta alternativa: el mismo motor en contenedor, con TLS (T-HYBRID-12)

El motor de arriba no sirve TLS, y la ruta PostgreSQL de `ConnectionFactory` exige
`sslmode=verify-full` con CA declarada **sin excepción de loopback** (RNF-05). Esa
combinación dejaba el camino feliz de `bin/init_cloud_db.php` —DDL base, migraciones del
motor, semillas y puerta de verificación— sin poder ejecutarse contra PostgreSQL en local, y
su certificación quedaba en inspección de código. Esta ruta levanta un motor equivalente que
sí habla TLS, para poder ejercitarlo de verdad:

```bash
bash deploy/local/postgres/provision-tls-engine.sh
source deploy/local/postgres/.env.docker-tls
DATABASE_URL="$PG_TEST_DSN" php bin/init_cloud_db.php   # ya lo ejecuta el aprovisionamiento
```

Qué hace el aprovisionamiento, y por qué así:

- Genera una **CA local efímera** y un certificado de servidor con `subjectAltName =
  IP:127.0.0.1, DNS:localhost` (siempre que falten o no verifiquen: es idempotente).
- Construye `deploy/local/postgres/tls/Dockerfile` (PostgreSQL 16 + TLS) y levanta el
  contenedor `vendguard-pg-tls` en el puerto **55433** —el 5432 lo ocupa el motor nativo—
  con un volumen propio (`vendguard_pg_tls_data`).
- Crea el **rol de aplicación** `vendguard_pg` **sin** `SUPERUSER`, `CREATEDB`, `CREATEROLE`
  ni `INHERIT`, y le cede la propiedad de la base. La credencial administrativa del motor
  sólo sirve para eso: PostgreSQL 16 se niega a degradar al superusuario de arranque, así
  que la imagen no lo usa como rol de la categoría (misma política S-5 que el motor
  nativo).
- Escribe `.env.docker-tls` (modo 600) con **sólo** `PG_TEST_DSN`. `DATABASE_URL` **no** se
  exporta a propósito: `ConnectionFactory` la lee antes que nada y haría que la batería
  hermética resolviera contra este motor. El inicializador la recibe delante, en su propio
  proceso.
- Ejecuta la **puerta de identidad del cliente local** antes de la sonda y del inicializador:
  exige una libpq que verifique direcciones del SAN, comprueba las dos formas del SAN y
  rechaza un nombre no declarado. Es lo que garantiza que este motor se use con la misma
  verificación que la imagen desplegada, y no con una más laxa.

Evidencia que deja una pasada (no una promesa): 136 sentencias del DDL base, las 20 tablas
del dominio más el libro `schema_migrations`, la migración `001_initial_baseline.sql`
aplicada y el esquema **convergido**, las semillas maestras, la puerta de catálogo
(10 sedes · 13 máquinas · 6 usuarios · 13 repuestos · 3/3 columnas de reintegros) y el
guardián del Art. III.1 respondiendo `45000` a un borrado sin ventana y cediendo sólo con la
señal de sesión. Todo ello con el cliente local ya alineado: el DSN del contrato abre por
`host=127.0.0.1` —la dirección del SAN—, así que la pasada del inicializador ejerce la
verificación que antes no podía. La categoría del motor nuevo también corre contra este
motor: `php tests/integration/SchemaConvergenceParityTest.php` (21/21).

Para retirarlo: `bash deploy/local/postgres/provision-tls-engine.sh --down` (el volumen de
datos se conserva; `docker volume rm vendguard_pg_tls_data` para borrarlo también).

## Cliente PostgreSQL del PHP local, alineado con la imagen

`verify-full` es una exigencia de las dos partes: el motor declara su identidad y el
**cliente** la comprueba. El PHP de XAMPP enlazaba **libpq 11.4**, que verifica el nombre
contra los `dNSName` del SAN pero **no** contra las direcciones `iPAddress` (capacidad
incorporada en PostgreSQL 12): con esa librería, abrir por `127.0.0.1` rechazaba un
certificado válido —`server certificate for "localhost" does not match host name
"127.0.0.1"`— y el cliente local quedaba verificando **menos** que la imagen desplegada
(`php:8.2-cli-alpine`, libpq moderna). El desvío está cerrado:

```bash
bash deploy/local/postgres/upgrade-local-libpq.sh           # alinea y certifica (idempotente)
bash deploy/local/postgres/upgrade-local-libpq.sh --check    # sólo comprueba; falla en cerrado
bash deploy/local/postgres/upgrade-local-libpq.sh --restore  # vuelve al respaldo
```

- **Qué sustituye:** `libpq.dll` por la del cliente PostgreSQL 16 ya instalado en el equipo
  (`C:\Program Files\PostgreSQL\16\bin`; ninguna descarga) y las dependencias que la nueva
  librería **importa de verdad** —leídas de su propia tabla de importación, no supuestas:
  `libintl-9.dll`, `libwinpthread-1.dll` y `libiconv-2.dll`.
- **Qué no toca:** `libcrypto-3-x64.dll` ni `libssl-3-x64.dll` del PHP. Verificar direcciones
  del SAN es una capacidad de **libpq**, no de OpenSSL (libpq la ejerce con
  `X509_check_ip_asc`), y sustituir OpenSSL perturbaría a `php_openssl`, compilado contra
  3.0.x. El libpq nuevo se enlaza contra el OpenSSL que el PHP ya carga.
- **Respaldo y reversión:** `C:\xampp\php\libpq-backup-<versión respaldada>\` con
  `MANIFEST.txt`, que anota qué fichero se sustituyó y cuál se añadió, y
  `C:\xampp\php\libpq-alignment.state`, que registra **qué respaldo** es el de esta
  alineación. Es lo que permite volver atrás cuando la versión actual ya no es la respaldada:
  sin ese estado, restaurar tendría que adivinar el directorio por el nombre y una segunda
  pasada fabricaría un respaldo falso con la versión nueva dentro. Si la certificación falla
  después del cambio, el script **restaura el respaldo por su cuenta** y sale con error: no
  se queda un PHP con un cliente que no cumple.
- **Idempotente y con modo comprobación:** si el PHP ya enlaza la versión del cliente de
  origen, no sustituye nada y sólo certifica; una pasada posterior **reutiliza** el respaldo
  de la primera alineación. `--check` no toca ningún fichero y termina en **1** si el cliente
  enlazado no verifica direcciones del SAN, así que sirve de puerta antes de aprovisionar.
- **Puerta de identidad** ([`check-local-libpq.php`](check-local-libpq.php)): se ejecuta
  también dentro de `provision-tls-engine.sh`, de modo que aprovisionar con un cliente
  antiguo **falla en cerrado** con la instrucción para actualizarlo. Certifica tres cosas:
  que la forma con nombre verifica (`DNS:localhost`), que la forma con dirección verifica
  (`IP:127.0.0.1`) y que un nombre que el certificado **no** declara (`127.0.0.2`, la misma
  máquina) es **rechazado** por el propio certificado. Sin el caso negativo, un cliente que
  no verificase nada también aprobaría el positivo.

Resultado en este equipo: `libpq 11.4 → 16.15`, confirmado por
`PDO::ATTR_CLIENT_VERSION` de la conexión en vivo, y el contrato abre por `host=127.0.0.1`
—la dirección del SAN— en vez de por el nombre. La versión del cliente es del entorno, no
del repositorio: en otra máquina el aprovisionamiento exigirá la misma capacidad y dirá
cómo conseguirla.

La paridad con la imagen no se da por supuesta: dentro de `vendguard:local` (la imagen
construida desde el `Dockerfile`) la libpq es la **18.6**, y conectando desde ella al mismo
motor —por `127.0.0.1`, por `localhost` y por un nombre que el certificado no declara— se
mide lo mismo que en el cliente local: acepta las dos formas del SAN y rechaza la tercera.

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
4. **`pdo_pgsql` del contenedor: hecho y verificado.** La imagen de la aplicación instala
   `postgresql-dev` con la cadena de `$PHPIZE_DEPS` y compila `pdo_pgsql`; dentro de la
   imagen construida, `PDO::getAvailableDrivers()` devuelve `mysql` y `pgsql`. Falta que
   este motor local sea el que sirve la ruta PostgreSQL de la aplicación: aquí se ha
   activado el driver del PHP de XAMPP.
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
