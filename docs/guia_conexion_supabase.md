# Guía paso a paso: conectar VendGuard con tu Supabase

**Ámbito:** puesta en marcha del enlace con la base de datos PostgreSQL remota (Supabase) del módulo 12
*Migración híbrida a Supabase*, desde tu cuenta hasta el latido desatendido.
**Fecha de esta revisión:** 2026-10-14 · **Rama:** `supabase-migration`
**Documentos normativos que manda esta guía:** [constitution.md](../constitution.md) (Art. I y III, RNF-05),
[spec.md](../specs/12-hybrid-supabase-migration/spec.md), [plan.md](../specs/12-hybrid-supabase-migration/plan.md) (§8) y
[tasks.md](../specs/12-hybrid-supabase-migration/tasks.md).

> Esta guía **no sustituye** a [docs/migracion_supabase_hibrido.md](migracion_supabase_hibrido.md): ese documento es el
> plan histórico y algunos de sus pasos (3 y 5) quedaron desfasados respecto al código ya implementado. Los desvíos
> están señalados en el apartado 8, con la evidencia que los demuestra.

---

## 0. Quién hace qué (resumen para imprimir)

| # | Paso | Responsable | Tiempo |
| :- | :--- | :--- | :--- |
| 1 | Crear la cuenta y el proyecto en Supabase | **Tú** (navegador) | ~10 min |
| 2 | Copiar la cadena del *pooler* y descargar la CA | **Tú** (navegador) | ~5 min |
| 3 | Crear el Personal Access Token y anotar el *project ref* | **Tú** (navegador) | ~3 min |
| 4 | Guardar credenciales y CA en tu máquina (fuera de git) | **Tú** (o yo, si me lo pides y las dejas en la terminal) | ~5 min |
| 5 | Preflight: resolver la conexión sin conectar | **Yo** | 1 min |
| 6 | Crear esquema, migraciones y datos maestros en Supabase | **Yo** | ~2 min |
| 7 | Levantar la aplicación contra Supabase y ejercer `/api/status` | **Yo** | ~5 min |
| 8 | Certificación del motor nuevo (T-HYBRID-27) y batería hermética | **Yo** | ~20 min |
| 9 | Configurar el latido diario en GitHub (5 secretos) | **Tú** (no hay `gh` CLI en tu máquina: lo verifiqué) | ~10 min |
| 10 | Despliegue del contenedor apuntando a Supabase | **Tú** decides el destino · **yo** preparo y verifico | ~30 min |

**Lo que sólo tú puedes hacer:** crear el proyecto (identidad, facturación y panel de Supabase), descargar la CA con el
navegador y escribir los secretos en GitHub. **Lo que hago yo:** todo lo verificable desde esta máquina —resolución del
contrato, inicializador, servidor local, sonda de estado, suites de certificación, T-HYBRID-27, sincronización de cifras
documentadas y commit—. Ninguna credencial tiene que pasar por el chat: basta con que exista en el entorno de la terminal
o en el fichero ignorado por git que indica el paso 4.

---

## 1. Estado de partida (ya verificado el 2026-10-14)

Medido en tu máquina, no supuesto:

| Comprobación | Resultado |
| :--- | :--- |
| PHP | `8.2.12 (cli)`, ZTS, Windows |
| Drivers PDO | `mysql, pgsql, sqlite` → **`pdo_pgsql` ya está activo** |
| `openssl` y `curl` | disponibles en `/mingw64/bin` |
| Herramientas de GitHub | **`gh` no está instalado** → los secretos se escriben en la web |
| Cliente PostgreSQL local | `psql` 16 (instalación nativa `postgresql-x64-16`) |
| Rama y árbol | `supabase-migration`, árbol limpio |

Y con una sonda temporal —ya retirada, `git status` limpio— contra el contrato real de
[`ConnectionFactory.php`](../src/Infrastructure/Database/ConnectionFactory.php):

| Caso probado | Resultado medido |
| :--- | :--- |
| URI del *pooler* (`...pooler.supabase.com:6543/postgres`) + CA declarada | ✔ `driver=pgsql`, `puerto=6543`, `uses_pooler=sí`, `sslmode=verify-full` |
| La misma URI con `?sslmode=require` | ✔ **rechazada** con «está prohibido degradar a require, prefer, allow o disable» |
| La misma URI con `?pgbouncer=true&connection_limit=1` | ✔ aceptada, pero los dos parámetros se **trasladan literalmente al DSN** |
| `sslrootcert` apuntando a un fichero inexistente | ✔ **rechazada**: «no se sustituye en silencio por otra» |
| Sin declarar CA (cae en la candidata versionada `isrgrootx1.pem`) | ✔ resuelta, **pero esa CA no valida la cadena de Supabase** (ver 8.2) |

---

## 2. Paso 1 — Tú: crear el proyecto en Supabase

1. Crea la cuenta en <https://supabase.com> y una organización.
2. **New project** con estos valores:
   * **Name:** `vendguard-prod`
   * **Database Password:** genérala y **guárdala en tu gestor de contraseñas**. No la pegues en el chat.
   * **Region:** `Central EU (Frankfurt)` o `West EU (London)`.
3. Espera a que el proyecto pase a **Active** (unos minutos).
4. No crees tablas ni ejecutes SQL desde el panel: el esquema lo aplica el inicializador del proyecto (paso 6). Si lo
   haces a mano, dos definiciones del esquema divergirán y la verificación de catálogo del inicializador lo dirá.

## 3. Paso 2 — Tú: copiar la cadena del *pooler* y descargar la CA

1. **Connection string:** botón **Connect** (arriba a la derecha del panel) → pestaña **Transaction pooler** → copia la URI.
   * Forma exacta: `postgres://postgres.<REF>:<CLAVE>@aws-<N>-<REGION>.pooler.supabase.com:6543/postgres`
   * El host lleva un **índice de clúster** (`aws-0-`, `aws-1-`, …) que **no se deduce de la región**: cópialo tal cual.
     Compuesto a mano, el error será un fallo de DNS, no un mensaje de credenciales.
   * El usuario es **`postgres.<REF>`**, no `postgres`. Con `postgres` a secas el *pooler* rechaza la conexión.
   * El **puerto 6543** es el modo *Transaction*; el 5432 del mismo host es *Session* (ver 8.4).
2. **Certificate:** **Database → Settings → SSL Configuration → Download certificate**. Guarda el fichero
   (`prod-ca-2021.crt`). Es la CA raíz propia de Supabase (`Supabase Root 2021 CA`).
3. Anota también la **connection string directa** (`db.<REF>.supabase.co:5432`, usuario `postgres`): no se usa en el
   camino normal, pero es la vía de rescate si el *pooler* no responde.

## 4. Paso 3 — Tú: Personal Access Token y *project ref*

1. **Project ref:** *Project Settings → General → Reference ID*. Es el mismo `<REF>` del usuario del *pooler*.
2. **Personal Access Token:** *Account → Access Tokens → Generate new token*, permiso de escritura sobre
   *Project Settings* (`project_admin_write`). Lo necesita el latido para **consultar el estado** del proyecto y
   **restaurarlo** si se ha enfriado. Guárdalo: sólo se muestra una vez.

## 5. Paso 4 — Tú: dejar las credenciales donde el proyecto las espera

El proyecto no lee ficheros `.env` desde PHP: las variables vienen del **entorno del proceso**. El patrón que ya usa el
repositorio para material local es un fichero `export`-able ignorado por git (`.gitignore` ya cubre `.env.local` en
cualquier carpeta):

```bash
# 1) La CA, fuera del repositorio (no es secreta, pero no tiene por qué viajar en git)
mkdir -p ~/certs && cp ~/Downloads/prod-ca-2021.crt ~/certs/supabase-prod-ca-2021.crt

# 2) Contrato de entorno local del motor remoto (ignorado por git, nunca se versiona)
mkdir -p deploy/local/supabase
cat > deploy/local/supabase/.env.local <<'EOF'
export DATABASE_URL='postgres://postgres.<REF>:<CLAVE>@aws-<N>-<REGION>.pooler.supabase.com:6543/postgres'
export DB_SSL_ROOT_CERT="$HOME/certs/supabase-prod-ca-2021.crt"
# Sólo si más adelante levantas la aplicación declarándose en producción:
# export APP_ENV=production
# export SECRET_KEY='...'
# export CRON_SECRET='...'
EOF

# 3) Cargar el contrato en la terminal y comprobar que git lo ignora
source deploy/local/supabase/.env.local
git check-ignore -v deploy/local/supabase/.env.local
```

Único parámetro admitido además de `sslmode`/`sslrootcert`: ninguno. Cualquier otro (`pgbouncer`, `connection_limit`,
`pgbouncer=true` que añaden los asistentes de ORM) se traslada **literalmente** al DSN y PostgreSQL lo rechaza con
`invalid connection option`. Usa la URI limpia y pasa la CA por `DB_SSL_ROOT_CERT`.

## 6. Pasos 5 a 7 — Yo: preflight, esquema y servidor local

Con el fichero del paso 4 cargado, yo ejecuto esto y te enseño la salida real:

```bash
# Paso 5 · Preflight: comprueba el contrato SIN abrir conexión (no toca la red)
php -r 'require "src/autoload.php"; $i = VendGuard\Infrastructure\Database\ConnectionFactory::describeConnection();
printf("driver=%s host=%s puerto=%s base=%s usuario=%s pooler=%s sslmode=%s\nCA=%s\nclave_declarada=%s\n",
$i["driver"],$i["host"],$i["port"],$i["database"],$i["user"],$i["uses_pooler"]?"sí":"no",$i["ssl_mode"],
$i["ssl_root_certificate"],$i["password_declared"]?"sí":"no");'

# Paso 6 · Esquema + migraciones + semillas maestras (Fresh Start: sin histórico)
php bin/init_cloud_db.php        # exit 0 = «esquema aplicado y verificado»

# Paso 7 · Aplicación real contra Supabase
php -S 127.0.0.1:8080 -t public public/index.php
curl -s -H "X-Cron-Secret: vendguard-dev-cron-key-local-only" http://127.0.0.1:8080/api/status
```

Qué tiene que salir, y qué significa:

| Salida esperada | Significado |
| :--- | :--- |
| `driver=pgsql` · `puerto=6543` · `pooler=sí` · `sslmode=verify-full` | La URI se interpretó bien y el motor nuevo es el activo |
| `CA=…/supabase-prod-ca-2021.crt` | Se está verificando contra la CA de Supabase, no contra la del sistema |
| `¡ÉXITO! La base de datos ha sido inicializada y está 100% lista.` + `exit 0` | El DDL base, las migraciones del motor, las semillas y la **verificación de catálogo** pasaron |
| Cualquier otro código de salida en el paso 6 | El inicializador **no deja servir** contra un esquema no verificado (EARS-02.5): el motivo está en la traza de los 3 intentos |
| `/api/status` con `status: HEALTHY` | El camino real (enrutador → middleware → servicio → PDO) funciona contra Supabase |

El inicializador autodetermina el motor por la URI, reintenta 3 veces con espera progresiva (3 s y 8 s) y sale con error
si el esquema no queda comprobado. No hay ningún paso de «pegar el SQL en el editor de Supabase».

## 7. Pasos 8 y 9 — Yo y tú: certificación y latido

### 8.1 Yo: certificación — ejecutada el 2026-10-14, y **NO superada**

* **T-HYBRID-27** ([tasks.md](../specs/12-hybrid-supabase-migration/tasks.md)): existe
  `tests/integration/PoolerLatencyMeasurementTest.php`, mide contra el motor remoto y **por el camino real**
  (enrutador → middleware → controlador → servicio → repositorio → PDO) **20 muestras por operación** —crear
  incidencia, asignar técnico, pausar, reanudar, listar triaje y listar «Mi Ruta» del técnico—, publica mínimo,
  mediana, percentil 95 y máximo, y falla si la mediana supera los 250 ms. Pertenece al **canal de certificación del
  motor nuevo**: se informa aparte y no se mezcla con la batería hermética.

  **Resultado medido: el presupuesto se cumple ya en las dos lecturas y se incumple todavía en las cuatro
  operaciones de escritura.** Medianas y percentiles 95, contra Supabase
  (`aws-0-eu-west-1.pooler.supabase.com:6543`, PostgreSQL 17.11), con la medición repetida **antes y después**
  del arreglo del N+1 del triaje —mismo enlace, misma suite y misma fecha—:

  | Operación | Mediana antes | Mediana después | p95 antes | p95 después | Coste en vueltas (después) |
  | :--- | ---: | ---: | ---: | ---: | ---: |
  | Crear incidencia | 485,1 ms | 443,7 ms | 642,0 ms | 494,2 ms | 10,5× |
  | Asignar técnico | 551,1 ms | 493,4 ms | 667,4 ms | 645,4 ms | 11,7× |
  | Pausar | 336,5 ms | 310,2 ms | 399,8 ms | 380,4 ms | 7,3× |
  | Reanudar | 366,6 ms | 358,8 ms | 441,1 ms | 432,2 ms | 8,5× |
  | Listar triaje | 624,2 ms | **144,5 ms** | 1.069,3 ms | **181,1 ms** | **3,4×** |

  Una **ida y vuelta** del enlace cuesta **42–44 ms** (mediana de `SELECT 1`), de modo que el presupuesto de 250 ms
  admite unas **5,6–5,9 idas y vueltas por operación**. El incumplimiento que queda no es de la medición: es del
  número de viajes de las cuatro operaciones de escritura. La causa grande ya está arreglada y medida: el **listado
  de triaje hacía un N+1** (`CoordinatorController::getIncidents()` pedía `comments_count` fila a fila) y ahora
  resuelve la conversación de TODA la bandeja con **una sola consulta agregada**
  (`countCommentsForIncidents()`, un único `GROUP BY incident_id`): la serie de muestras deja de crecer con las filas
  —de 167,6 ms con una fila a 1.094,3 ms con veinte **antes**, frente a 127–215 ms con las mismas veinte
  **después**— y **la operación «listar» cumple ya el presupuesto de 250 ms**, con la insignia de conversación
  intacta: el agregado devuelve el mismo total (certificado contra MariaDB real en el Caso 7 de
  `IncidentCommentsPagedRepositoryTest.php`) y el blindaje constitucional del módulo 10 sigue en verde con la
  lectura agregada en su lista blanca de solo consulta.

  La **misma cura se aplicó a «Mi Ruta»** (`TechnicianController::getMyRoute()`, que pagaba el mismo N+1 con la
  insignia de cada parada). Campaña propia, mismo día y mismo enlace (mediana de `SELECT 1`: 43,9 ms antes ·
  43,7 ms después):

  | Operación | Mediana antes | Tras la insignia | Tras batchear las tres lecturas | p95 antes | p95 final |
  | :--- | ---: | ---: | ---: | ---: | ---: |
  | Listar «Mi Ruta» | 2.662,1 ms | 1.739,3 ms | **88,2 ms** | 3.094,1 ms | **101,1 ms** |

  La primera cura quitó lo que debía —una ida y vuelta por parada— (**−34,7 %**), y la bandeja seguía muy por
  encima del presupuesto por **las tres lecturas que le quedaban por parada** —máquina, sede y ajustes preventivos
  de la puerta sanitaria—. Esas tres eran el candidato ya medido, y ya están curadas: la bandeja entera se lee en
  **UNA sola consulta** (`PdoIncidentRepository::findRouteBoard()`, certificada contra MariaDB real en el Caso 9 de
  `TechnicianRouteEndpointTest.php`, parada por parada y campo por campo contra las lecturas individuales), y con
  la misma bandeja de 13 paradas la mediana pasa de **1.739,3 ms a 88,2 ms** —**−94,9 %**, de **40,2× a 2,0×**
  idas y vueltas—: **la operación «ruta» cumple el presupuesto de RNF-01** (aserción 5.1 en verde), con el mismo
  contrato publicado (insignia de conversación, pausa por bloqueo de sede, marca de respuesta y puerta sanitaria
  intactas).

  Además, la sonda destapó un **defecto de portabilidad real**: contra PostgreSQL, `GET /api/technician/my-route`
  respondía **HTTP 500 entera** porque el JOIN comparaba `machine_type` con `preventive_machine_type` —dos tipos
  enumerados distintos por contrato— y el motor no tiene operador entre ellos; en MariaDB el ENUM compara por
  etiqueta y el defecto era invisible a la batería hermética. Se reparó con un cast dialectal nuevo
  (`SqlDialectAdapter::castToText()`), certificado contra el motor remoto con sonda efímera (con cast: 1 fila; sin
  cast: `42883 operator does not exist`).

  **El checkbox de la tarea sigue sin marcar**: quedan **crear, asignar, pausar y reanudar** por encima del umbral
  (408,9 · 451,0 · 261,8 · 308,9 ms de mediana en la última corrida), y el hallazgo, con sus remedios medidos, está
  registrado en `tasks.md`.
* **Batería hermética** (`php tests/run_all.php`): `exit 0` · **258 suites · 9.873 aserciones · 0 fallos · cifras
  documentadas coherentes**. La suite de la categoría aparece **enumerada como `new_engine` y no ejecutada**, y las
  cifras documentadas se sincronizaron con la corrida (9.857 → 9.873).
* **Sin residuo en tu proyecto**: la medición corre dentro de **una transacción externa que se revierte**, de modo
  que no queda ni un expediente, ni una fila de historial, ni un registro de auditoría. Las 20 tablas conservan su
  recuento exacto (comprobado por aserción).
* **Lo que esto cambia en el plan**: de las seis operaciones medidas, **dos ya cumplen** (listar triaje y listar
  «Mi Ruta») y cerrar T-HYBRID-27 exige **reducir los viajes de las cuatro operaciones de escritura** o
  **enmendar RNF-01** (umbral o topología) con tu ratificación. No se ha tocado ni el umbral ni la especificación.

### 8.2 Yo: lo que NO hago, aunque se pueda

* **No apunto `PG_TEST_DSN` a Supabase.** Ese contrato es el del motor local de la ejecución ordinaria
  ([deploy/local/postgres/README.md](../deploy/local/postgres/README.md)); redirigirlo al proyecto remoto sería una
  desviación del plan ratificado y expondría tu proyecto a creación y borrado de esquemas aislados.
* **No importo datos históricos.** El arranque es *Fresh Start* estricto: sólo datos maestros y personal interno.
* **No degrado la verificación TLS** para que una conexión pase. Si falla, se arregla la CA o se cambia de camino.

### 8.3 Tú: latido diario y auto-restauración

GitHub → *Settings → Secrets and variables → Actions → New repository secret*, los cinco que consume
[`supabase-keepalive.yml`](../.github/workflows/supabase-keepalive.yml):

| Secreto | Obligatorio | Para qué |
| :--- | :--- | :--- |
| `VENDGUARD_APP_URL` | **Sí** | URL pública de la aplicación; el flujo late contra `GET /api/status` |
| `CRON_SECRET` | **Sí** | Credencial `X-Cron-Secret` con la que se autentica ese latido |
| `SUPABASE_ACCESS_TOKEN` | **Sí** | Personal Access Token del paso 4; el flujo **pregunta** el estado por la API de gestión |
| `SUPABASE_PROJECT_REF` | **Sí** | Referencia del proyecto, la del *pooler* |
| `VENDGUARD_ALERT_WEBHOOK` | Opcional | Webhook de un canal real para las anomalías (una restauración lo es) |

Después, *Actions → Supabase · latido… → Run workflow* una vez para verificarlo. El plan Free pausa los proyectos con
poca actividad durante siete días: el flujo existe justo para eso. Late con actividad real en la base, consulta el estado
declarado, invoca `POST /v1/projects/{ref}/restore` si está en frío y **no declara recuperado nada hasta ver
`status: HEALTHY`** —el arranque del contenedor reejecuta el inicializador—.

### 8.4 Tú: decisión de contingencia (sólo si aparece)

1. **Error de certificado del *pooler*.** Hay una incidencia abierta en Supabase sobre la conformidad estricta del
   certificado intermedio del *pooler* (puertos 6543 y 5432). La salida limpia es usar el mismo host del *pooler* en
   modo **Session (5432)**, que sigue pasando por Supavisor. Cambiar el puerto es una decisión tuya: el plan ratificado
   elige el 6543 para no agotar conexiones. La otra salida —bajar a `sslmode=require`— **está prohibida por RNF-05** y el
   código la rechaza.
2. **Proyecto pausado.** No es un fallo tuyo: el inicializador reintenta y el latido lo restaura. Desde el panel, la
   restauración está disponible hasta un año después de la pausa.
3. **¿Otro proveedor?** El proyecto no depende de ninguna pieza cerrada de Supabase: es PostgreSQL estándar. Neon,
   Railway o un VPS propio sirven el mismo contrato (`DATABASE_URL` + CA).

---

## 8. Desvíos del plan histórico, con evidencia

| Punto del plan antiguo | Qué dice | Qué hace el código hoy | Evidencia |
| :--- | :--- | :--- | :--- |
| [migracion_supabase_hibrido.md](migracion_supabase_hibrido.md) paso 3 | `?sslmode=require` | Rechaza cualquier modo que no sea `verify-full` (RNF-05) | Sonda del apartado 1 |
| Ídem | `DB_DRIVER="pgsql"` | Variable **inexistente**: el motor lo decide el esquema de la URI | `ConnectionFactory::resolveDriver()` |
| Ídem | «En el archivo `.env` del servidor» | PHP no lee `.env`: las variables vienen del entorno del proceso | `SecretProvider::read()` y `ConnectionFactory` |
| [migracion_supabase_hibrido.md](migracion_supabase_hibrido.md) paso 2 | Pegar `supabase_init.sql` en el editor SQL | `bin/init_cloud_db.php` aplica DDL **y** migraciones **y** semillas **y** verifica catálogo | [bin/init_cloud_db.php](../bin/init_cloud_db.php) |
| [migracion_supabase_hibrido.md](migracion_supabase_hibrido.md) paso 4 | «Que pasen las 249 suites» | La batería hermética corre contra el motor **local**; la del motor nuevo va en canal aparte | plan §8.1, `deploy/local/postgres/` |
| [migracion_supabase_hibrido.md](migracion_supabase_hibrido.md) paso 5 | Latido con `SUPABASE_ANON_KEY` contra la REST API | Latido con 5 secretos, estado por API de gestión y restauración real | [`supabase-keepalive.yml`](../.github/workflows/supabase-keepalive.yml) |
| [ConnectionFactory.php](../src/Infrastructure/Database/ConnectionFactory.php) | — | Sin CA declarada cae en `isrgrootx1.pem`, que **no** valida la cadena de Supabase | Sonda del apartado 1 + cadena `Supabase Root 2021 CA` |

Estos desvíos están **reportados, no corregidos**: tocar el plan o las especificaciones aprobadas exige tu consenso
previo (línea roja 5 de [AGENTS.md](../AGENTS.md)). Si quieres, los enmiendo en el mismo paso en que cerremos T-HYBRID-27.

---

## 9. Problemas conocidos y su causa real

| Síntoma | Causa habitual | Qué hacer |
| :--- | :--- | :--- |
| `could not translate host name` | Host del *pooler* compuesto a mano | Copiar el host exacto del panel (lleva índice de clúster) |
| `Tenant or user not found` | Usuario `postgres` a secas | Usar `postgres.<REF>` |
| Error de certificado / `verify-full` | CA equivocada o sin declarar | Descargar la CA de Supabase y declarar `DB_SSL_ROOT_CERT` |
| `invalid connection option "pgbouncer"` | URI copiada del asistente de ORM | Usar la URI limpia; la CA va en `DB_SSL_ROOT_CERT` |
| `Modo TLS no admitido («require»)…` | Alguien «arregló» el DSN degradando TLS | Quitar `sslmode` del DSN: el modo exigido ya lo impone el código |
| El inicializador falla 3 veces y sale con otro código | Proyecto pausado, red caída o credenciales | Mirar la traza; el latido restaura, pero el esquema no se declara bueno hasta que se verifica |
| `password authentication failed` en producción | Política S-5: usuario administrativo sin contraseña | Definir `DATABASE_URL` completa o `DB_USER`/`DB_PASS` antes de desplegar |

---

## 10. Cierre: qué queda pendiente después de conectar

1. **T-HYBRID-27** — medición de latencia contra el motor remoto (RNF-01). **Ejecutada el 2026-10-14 y todavía no
   superada**: las dos lecturas ya cumplen —el listado de triaje tras arreglar su N+1 (144,5 ms) y **«Mi Ruta» tras
   batchear las tres lecturas por parada (1.739,3 → 88,2 ms)**—, y quedan las cuatro operaciones de escritura por
   encima de la mediana de 250 ms (detalle, antes/después y diagnóstico en 8.1).
   Está abierta, no bloqueada: se cierra reduciendo los viajes de esas cuatro operaciones o ratificando una enmienda
   del umbral o de la topología.
2. **T-HYBRID-22** — sincronización de cifras documentadas y certificación global. Depende de la anterior.
3. **Despliegue en contenedor** — el `CMD` ya ejecuta el inicializador antes del servidor web; el destino (Render u otro)
   y `VENDGUARD_APP_URL` son decisión tuya.
4. **Rollback en un minuto** — quitar `DATABASE_URL` (y `DB_SSL_ROOT_CERT`) del entorno: la aplicación vuelve a
   MariaDB sin tocar una línea de código.
