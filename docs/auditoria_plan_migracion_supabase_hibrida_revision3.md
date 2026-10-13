# AUDITORÍA ADVERSARIAL · TERCERA PASADA SOBRE EL PLAN DE MIGRACIÓN HÍBRIDA (MÓDULO 12)

**Documento:** `docs/auditoria_plan_migracion_supabase_hibrida_revision3.md`
**Fecha:** 13 de octubre de 2026
**Objeto auditado:** [`specs/12-hybrid-supabase-migration/spec.md`](../specs/12-hybrid-supabase-migration/spec.md), [`plan.md`](../specs/12-hybrid-supabase-migration/plan.md) y [`tasks.md`](../specs/12-hybrid-supabase-migration/tasks.md) tal como quedaron **en el árbol de trabajo** tras la reparación de los doce hallazgos de la revisión 2 (base `70cbb17`, reparación **sin commitear**).
**Informes precedentes:** [`docs/auditoria_plan_migracion_supabase_hibrida.md`](auditoria_plan_migracion_supabase_hibrida.md) (pasadas 1 y 2)
**Rama:** `supabase-migration`
**Naturaleza:** Auditoría adversarial de **solo lectura**. No se ha modificado ninguna línea de `src/`, `tests/`, `database/`, `bin/` ni de las especificaciones.
**Normativa Suprema:** [`constitution.md`](../constitution.md) (Artículos I al VII)
**Directrices Operativas:** [`AGENTS.md`](../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)

**Veredicto bis:** la reparación de la revisión 2 resolvió los defectos que **ese** informe podía juzgar —falsedades documentales y decisiones sin tomar— y al hacerlo **volvió a escribir política del arnés sin leer el arnés** (R3-1) y dejó **cuatro suites del motor nuevo dentro del glob de la batería que debe estar verde sin red** (R3-2). Sigue vigente el «no empezar por T-HYBRID-01»: las tareas 01 a 04 construyen el DDL y la orquestación, y ahora además construirían una puerta de purga que el limpiador compartido no puede ejecutar tal como está especificada.

---

## 1. Resumen Ejecutivo

La segunda reparación fue honesta en su diagnóstico y **correcta en sus decisiones de arquitectura**: mover el barrido al cron del servidor de aplicaciones, crear una ruta nueva `/api/status` dejando `/api/health` intacto, devolver el ejecutor de migraciones, sustituir la quimera ANSI por un adaptador explícito y resolver la unicidad con claves canónicas son cinco aciertos verificables contra el código (véase la calibración de §5).

El problema de la tercera pasada es de otra clase: **la reparación se escribió contra la idea del arnés, no contra el arnés**. Y como el arnés ya tiene contratos certificados por pruebas verdes, esa distancia se convierte en fallos concretos.

| # | Hallazgo | Severidad | Requisito o artefacto que se cae |
| :--- | :--- | :--- | :--- |
| R3-1 | La puerta de purga del arnés se reescribió en el dialecto del motor que no la ejecuta: `set_config('vendguard.purge', …)` es PostgreSQL, el arnés limpia en MariaDB con `@vendguard_purge`, y hay **tres suites verdes que fijan ese contrato** | **Crítica** | EARS-08.1, Art. III.1, T-HYBRID-12(4), T-HYBRID-26, criterio §8.2 |
| R3-2 | Las cuatro suites del motor nuevo caen en el `glob` de `tests/run_all.php` (sin canal de omisión) y **ninguna tarea aprovisiona PostgreSQL**; T-HYBRID-27 (medición remota) es además dependencia de la certificación offline | **Crítica** | RNF-04, EARS-01.3, T-HYBRID-22, T-HYBRID-27 |
| R3-3 | `sslmode=require` **no verifica** el certificado del servidor: RNF-05 promete exactamente lo que su configuración no hace | **Alta** | RNF-05, T-HYBRID-06 |
| R3-4 | La rama PostgreSQL de `ConnectionFactory` esquiva el *fail-closed* de credenciales (S-5) que el proyecto ya certifica con una guarda en verde | **Alta** | EARS-01.4, T-HYBRID-06, Art. VI, `AuditFindingsSecondWaveClosureTest` |
| R3-5 | El compensador de archivos nace **sin llamante** y duplica `LocalFileUploader::delete()`; cinco puntos de uso vivos siguen subiendo la foto antes de insertar | **Alta** | EARS-05.3, T-HYBRID-08 |
| R3-6 | La telemetría **opcional** de `/api/status` se apoya en `CronAuthMiddleware`, que es *fail-closed* (401) y con predicado privado: no puede ejercer de comprobación no fatal | **Alta** | EARS-07.2, EARS-07.3, T-HYBRID-14 |
| R3-7 | La paridad de inventarios prometida es inalcanzable literalmente: el diseño **quiere** que los dos esquemas difieran, y el criterio no define el mapeo | **Media-alta** | T-HYBRID-24, §8.7, EARS-08.5 |
| R3-8 | El hallazgo N-7 no se cerró en tres sitios: EARS-02.1, T-HYBRID-03, T-HYBRID-10/11 y el árbol siguen ordenando la portabilidad ANSI que el propio plan abandonó | **Media** | EARS-02.1, T-HYBRID-03/10/11 |
| R3-9 | Contradicciones internas del plan: «22 tareas» (hay 27), `ConnectionFactoryTest` duplicado en el árbol, «6 tipos ENUM» frente a 24 conjuntos locales, orden de fase que viola la regla de dependencias | **Media** | plan §1, §5.1, T-HYBRID-01, T-HYBRID-19 |
| R3-10 | El orquestador canónico del barrido no existe en **ninguna** definición de despliegue del repositorio | **Media** | EARS-06.2, T-HYBRID-04 |
| R3-11 | Desorden normativo de numeración EARS y «ninguna duda abierta» con R3-1…R3-7 sobre la mesa | **Baja** | spec §4, §9 |

---

## 2. Método y Evidencia

**Lectura completa:** `spec.md` (233 líneas), `plan.md` (406), `tasks.md` (143) y las secciones del informe de la revisión 2.

**Sondas sobre el código** (no sobre la documentación):

1. Inventario de la puerta de purga: `@vendguard_purge` en `database/` y `tests/`, y búsqueda de `set_config` / `current_setting` en **todo** el repositorio.
2. Contrato del limpiador compartido: `PURGE_ROOTS`, `PROTECTED_TABLES`, `withPurgeUnlocked()`, y las suites que fijan su forma por aserción.
3. Descubrimiento y semántica de la batería: `glob()` de las cuatro categorías, ausencia de canal de omisión, y recuento real de ficheros-suite.
4. Contrato del *health* vivo: handler dual de `AppRouter`, rutas que comparte y las cinco suites que lo usan como sonda de arranque.
5. Contrato de `CronAuthMiddleware`: código de respuesta e visibilidad de su predicado.
6. Cotas de credenciales y TLS: política S-5 de `ConnectionFactory`, guarda que la certifica, y `sslmode` declarado en los tres documentos.
7. Asimetrías de esquema entre motores: columnas generadas, `ENUM` distintos, `AUTO_INCREMENT`, disparadores por motor.
8. Definición de despliegue: `Dockerfile`, `bin/`, existencia de `.github/`, de *compose* y de cualquier configuración de plataforma.
9. Puntos de uso reales de `LocalFileUploader` y orden subida → inserción en el servicio de comentarios.

---

## 3. Estado de los hallazgos de la revisión 2

| Hallazgo | Estado en la tercera pasada | Evidencia |
| :--- | :--- | :--- |
| **N-12** Esquema creado y no migrado | **Cerrado** | `PostgresMigrationRunner` + `database/supabase_migrations/` en plan §1, EARS-02.4, T-HYBRID-23/24; el guardián de paridad se extiende |
| **N-1** Credencial sin domicilio | **Cerrado en lo esencial** | EARS-06.2 (cron del servidor de aplicaciones) y EARS-06.6 (Vault si se usa `pg_cron`); desaparece `X-Internal-Token`. Residuo → R3-10 |
| **N-5** Barrido colgado de dos servicios que se duermen | **Cerrado** | EARS-06.5 (reintento, registro de pasada y alerta), T-HYBRID-04; residuo → R3-10 |
| **N-2** Puerta de purga que no cubre al arnés | **Reabierto como R3-1** | La intención (ámbito de sesión, todas las estructuras de limpieza) es correcta; la **forma técnica especificada es del motor equivocado** y rompe tres aserciones verdes |
| **N-4** Condición de restauración inobservable | **Cerrado** | EARS-03.3 lee el estado por la API de gestión, no del código HTTP de la aplicación |
| **N-3** Contratos vivos de `/api/health` | **Cerrado** | Ruta nueva `/api/status`, contrato de `/api/health` congelado y certificado; verificado contra `AppRouter:47-53` y las cinco suites que lo usan de sonda |
| **N-7** Premisa ANSI inexistente | **Cerrado parcialmente** | El adaptador es la decisión correcta, pero tres documentos siguen ordenando la premisa abandonada → R3-8 |
| **N-6** Cotejamiento insatisfacible | **Cerrado** | Claves canónicas en la aplicación, `CITEXT` retirado del DDL |
| **N-8** `PostgresParityTest` no probaba paridad | **Cerrado en la intención, no en el criterio** | El nombre y la separación mejoran; la igualdad literal de inventarios no es implementable → R3-7 |
| **N-10** Esquema sin segunda oportunidad | **Cerrado** | EARS-02.5 y T-HYBRID-12(2): reintento, salida con error y re-inicialización tras restaurar |
| **N-9** Rendimiento certificado en otra arquitectura | **Cerrado en la intención, no en el encaje** | La medición es medible (20 muestras, mediana y p95); su lugar en la batería offline es el problema → R3-2 |
| **N-11** Latido sin actividad real | **Cerrado** | EARS-03.2 sustituye la sonda sintética por una consulta sobre tabla real |

**Balance:** 6 cerrados, 4 cerrados con residuo y 2 reabiertos por su forma técnica (N-2 → R3-1, N-7 → R3-8).

---

## 4. Hallazgos de la tercera pasada

### R3-1 · La puerta de purga se reescribió en el dialecto del motor que no la ejecuta *(Crítica)*

**Lo que ya existe y está en verde.** El Art. III.1 es ejecutable hoy mediante **una** señal, y es una variable de usuario de MySQL:

- [`010_refund_hard_delete_guard.sql:48`](../database/migrations/010_refund_hard_delete_guard.sql#L48) — `IF COALESCE(@vendguard_purge, 0) <> 1 THEN SIGNAL SQLSTATE '45000' …`.
- [`TestDataCleaner.php:158`](../tests/Support/TestDataCleaner.php#L158) y [`:163`](../tests/Support/TestDataCleaner.php#L163) — la abre con `SET @vendguard_purge = 1` y la cierra con `SET @vendguard_purge = 0`.
- [`RefundsModuleClosureTest.php:432`](../tests/unit/RefundsModuleClosureTest.php#L432) — certifica que **ningún** fichero de `src/` contiene el marcador.
- [`RefundsModuleClosureTest.php:453`](../tests/unit/RefundsModuleClosureTest.php#L453) — certifica que el limpiador lo contiene **exactamente dos veces** y que incluye `SET @vendguard_purge = 0`.
- [`SiteManagerRefundDataSegregationTest.php:497`](../tests/integration/SiteManagerRefundDataSegregationTest.php#L497), [`:523`](../tests/integration/SiteManagerRefundDataSegregationTest.php#L523) y [`:534`](../tests/integration/SiteManagerRefundDataSegregationTest.php#L534) — la usa y comprueba que el marcador vive en un único fichero.
- [`CoordinatorIncidentDetailConstitutionalTest.php:201`](../tests/integration/CoordinatorIncidentDetailConstitutionalTest.php#L201) — la restablece a `0`.

**Lo que ordena la reparación.** Los tres documentos renombraron la señal a `vendguard.purge` y la declararon «de ámbito de sesión» en abstracto; y T-HYBRID-12(4) concreta el mecanismo: *«`TestDataCleaner.php` declara `set_config('vendguard.purge', 'on', false)` a nivel de sesión al abrir su conexión»*.

**Lo que ocurre de verdad.**

1. `set_config()` **no existe en MariaDB ni en MySQL**. El arnés limpia contra el motor local (RNF-04); `TestDataCleaner` es el limpiador **compartido por las 249 suites**. Implementar T-HYBRID-12(4) tal como está escrito rompe la limpieza de toda la batería en el motor en el que se ejecuta.
2. Si en cambio se implementa con rama por driver, el proyecto pasa a tener **dos puertas con dos nombres** (`@vendguard_purge` y `vendguard.purge`) mientras los tres documentos afirman que hay **una** — y las dos aserciones del §4 del guardián del módulo 08 (`substr_count(...) === 2`) dejan de ser ciertas en cuanto el limpiador crece.
3. La sonda global es concluyente: **cero** coincidencias de `set_config` o `current_setting` en `src/`, `tests/` y `database/`. La construcción no está en el proyecto por ningún lado; se introdujo por escrito sin señalarla como nueva.
4. Consecuencia de fondo: EARS-08.1 redacta como universal («el disparador cederá ante la señal explícita … `vendguard.purge`») lo que en la mitad del sistema se llama de otra forma. Es el mismo patrón que el hallazgo **H-3** de la primera pasada (política de arnés escrita sin leer el arnés), esta vez no como no-op silencioso sino como orden imposible de ejecutar.

**Reparación honesta:** nombrar **las dos formas** en EARS-08.1, plan §4.3 y T-HYBRID-12(4) —parámetro de sesión `vendguard.purge` en PostgreSQL, variable de usuario `@vendguard_purge` en MariaDB—, encapsularlas en un único ayudante del limpiador consciente del driver (`openPurgeWindow(PDO): void`), y ampliar explícitamente la aserción de `RefundsModuleClosureTest` §4 (que hoy fija la forma de MySQL) para que certifique **el contrato de los dos motores** en lugar de borrar la prueba que molesta.

---

### R3-2 · Las suites del motor nuevo entran en el glob de la batería offline, y ninguna tarea aprovisiona PostgreSQL *(Crítica)*

**Lo que ordena la reparación:** cuatro suites nuevas en `tests/integration/` (`PostgresSchemaTest`, `PostgresConstitutionalTest`, `SchemaConvergenceParityTest`, `PoolerLatencyMeasurementTest`) y T-HYBRID-22 como certificación «100% en verde» de `php tests/run_all.php`, con T-HYBRID-27 (medición **contra el motor remoto**) entre sus dependencias.

**Lo que hace la batería:** [`run_all.php:182`](../tests/run_all.php#L182), [`:210`](../tests/run_all.php#L210), [`:260`](../tests/run_all.php#L260) y [`:294`](../tests/run_all.php#L294) descubren las suites por `glob()` en cuatro categorías y cuentan **cada fichero** como una suite; no existe canal de omisión, ni marca de «requiere motor externo», ni categoría para pruebas no herméticas. El recuento real de ficheros-suite es 108 + 64 + 76 + 1 = **249**, la cifra que declara el README.

**Lo que no existe en el repositorio:** no hay `.github/` (ningún flujo de trabajo, tampoco el que el plan crea), no hay *compose* ni configuración de plataforma, y [`Dockerfile:4`](../Dockerfile#L4) instala `pdo` y `pdo_mysql` y **ningún** `pdo_pgsql` — es decir, hoy **no hay forma de levantar un PostgreSQL** con los artefactos versionados.

**Contradicción resultante:** RNF-04 exige que **la totalidad** de la batería se ejecute sin red contra el motor local; EARS-01.3 exige que en el entorno local «el sistema ejecute todas las consultas y pruebas sin emitir ninguna llamada de red hacia el exterior»; y T-HYBRID-22 exige la batería al 100% en verde mientras cuatro suites necesitan un motor que nadie aprovisiona y una de ellas mide **latencia contra el motor remoto**. O la batería falla en cuanto se escriben las suites, o se incumple RNF-04 escribiéndolas de forma que se salten sin decirlo — la ceguera que el proyecto ya rechazó por escrito en [`testing_cleanup_contract.md`](../specs/technical/testing_cleanup_contract.md) §6.1.

**Reparación honesta:** declarar **dos categorías** de certificación —batería offline (hermética, contra el motor local) y *suite de contrato del motor nuevo*, ejecutable solo con PostgreSQL disponible—, dar a la segunda su propio canal y su propio informe, y decir con precisión qué tarea aprovisiona el motor (local por contenedor y remoto por credenciales). `tests/Manual/` ya demuestra que el proyecto sabe tener una categoría aparte.

---

### R3-3 · RNF-05 promete verificación de certificado y especifica el modo que no la hace *(Alta)*

**Lo que afirma la especificación:** «Toda conexión … deberá cifrarse obligatoriamente mediante protocolo seguro TLS/SSL **con verificación de certificado** (`sslmode=require`)».

**Lo que dice el propio motor** (documentación de libpq, Anexo B): *«By default, PostgreSQL will not perform any verification of the server certificate»*; la verificación exige `verify-ca` o `verify-full`; y solo si existe un fichero de CA raíz el modo `require` se comporta como `verify-ca`, comportamiento que la documentación **desaconseja** — *«applications that need certificate validation should always use verify-ca or verify-full»*.

**Lo que hace el plan:** fija el DSN con `sslmode=require` ([plan.md:172](../specs/12-hybrid-supabase-migration/plan.md#L172) y [`:193`](../specs/12-hybrid-supabase-migration/plan.md#L193)) y **no menciona `sslrootcert`**, `PGSSLROOTCERT` ni certificado alguno. Sin CA raíz, `require` cifra y no verifica: acepta cualquier servidor que presente cualquier certificado. La conexión queda cifrada —que era el objetivo operativo— pero el criterio «con verificación de certificado» se certificaría en falso.

**Reparación honesta:** o se eleva la configuración a `sslmode=verify-full` con la CA de Supabase incluida en la imagen (el proyecto ya versiona una CA para MySQL: [`isrgrootx1.pem`](../src/Infrastructure/Database/isrgrootx1.pem)), o RNF-05 se redacta como «cifrado obligatorio en tránsito» sin prometer verificación. Cualquiera de las dos es defendible; la actual no lo es.

---

### R3-4 · La rama PostgreSQL esquiva el *fail-closed* de credenciales que el proyecto ya certifica *(Alta)*

**Lo que ya existe:** [`ConnectionFactory.php:65`](../src/Infrastructure/Database/ConnectionFactory.php#L65) exige, en producción, una fuente explícita de credenciales y rechaza `root` sin contraseña, con dos excepciones ruidosas y nominadas (hallazgo S-5). Ese contrato está **certificado por una guarda en verde**: [`AuditFindingsSecondWaveClosureTest.php:94`](../tests/unit/AuditFindingsSecondWaveClosureTest.php#L94) y [`:108`](../tests/unit/AuditFindingsSecondWaveClosureTest.php#L108).

**Lo que ordena el Algoritmo 1 del plan** para la rama nueva: `user = parsed['user'] ?: 'postgres'` y `pass = parsed['pass'] ?: ''`. Es decir: un `postgres://` de producción **sin contraseña** no falla en cerrado; se conecta con cadena vacía, o falla más tarde con un error de autenticación genérico que además puede acabar en el log de arranque. La guarda existente no lo detecta porque solo ejerce el camino `mysql`.

**Reparación honesta:** la rama `pgsql` debe ejecutar **la misma** política antes de conectar (fuente explícita obligatoria en producción, contraseña no vacía, usuario administrativo por defecto rechazado), y `AuditFindingsSecondWaveClosureTest` debe extenderse al camino nuevo —puede hacerlo sin red, porque la política debe lanzar **antes** de intentar conectar—.

---

### R3-5 · El compensador de archivos nace sin llamante y duplica una capacidad existente *(Alta)*

**Lo que ya existe:** [`LocalFileUploader`](../src/Infrastructure/Storage/LocalFileUploader.php) valida y almacena la evidencia (≤ 5 MB, MIME real), es dueño del directorio de destino y **ya expone** [`delete(string $publicPath): bool`](../src/Infrastructure/Storage/LocalFileUploader.php#L181), que hace `unlink()` sobre la ruta pública que devolvió `upload()`.

**Quién lo usa hoy:** cinco puntos vivos — [`IncidentCommentService.php:85`](../src/Application/Service/IncidentCommentService.php#L85), [`CoordinatorController.php:103`](../src/Presentation/Controller/CoordinatorController.php#L103), [`LocationPortalController.php:95`](../src/Presentation/Controller/LocationPortalController.php#L95), [`QrScanController.php:118`](../src/Presentation/Controller/QrScanController.php#L118) y [`TechnicianController.php:74`](../src/Presentation/Controller/TechnicianController.php#L74).

**Dónde está el agujero que EARS-05.3 quiere tapar:** en el servicio de comentarios la subida ocurre **antes** de la inserción — [`IncidentCommentService.php:246`](../src/Application/Service/IncidentCommentService.php#L246) sube la foto y [`:266`](../src/Application/Service/IncidentCommentService.php#L266) persiste el comentario—, de modo que un fallo de inserción deja la foto huérfana en disco, sin compensación.

**Lo que ordena la reparación:** crear `FileStorageCompensator` con `executeWithCompensatingFile(string $filePath, callable $dbOperation)`, como tarea de **dependencia «Ninguna»**, y sin decir una palabra sobre **quién lo llama**. Tres defectos en una firma: (1) ninguna tarea conecta el compensador a los cinco puntos de uso, así que la pieza queda huérfana —el patrón exacto del hallazgo **H-5**—; (2) recibe una ruta de fichero cruda mientras el subidor devuelve una **ruta pública** (`/uploads/<hash>.<ext>`), de modo que cada llamante tendría que recomponerla; y (3) reimplementa `unlink()` cuando el subidor ya tiene `delete()`.

**Reparación honesta:** T-HYBRID-08 debe nombrar los llamantes (o el punto único de servicio por el que pasan las cinco rutas de subida), consumir `LocalFileUploader::delete()` en lugar de duplicarlo, aceptar la ruta pública que `upload()` devuelve, y traer una prueba que demuestre que el fichero **ya no está** cuando la operación de base de datos lanza.

---

### R3-6 · La telemetría opcional de `/api/status` se apoya en un middleware que no puede hacerla *(Alta)*

**Lo que existe:** [`CronAuthMiddleware.php:54`](../src/Presentation/Http/Middleware/CronAuthMiddleware.php#L54) responde `401 UNAUTHORIZED` cuando la credencial falta o no es válida, y su predicado [`isAuthorized()`](../src/Presentation/Http/Middleware/CronAuthMiddleware.php#L73) es **privado**. Su docblock explica con precisión por qué la credencial vive ahí: antes de él, una ruta de negocio quedó «desnuda» y cualquier ruta nueva de automatización repetía el patrón.

**Lo que ordena la reparación:** EARS-07.2 exige que la llamada pública devuelva solo `status` y `timestamp_utc`; EARS-07.3 exige que la telemetría detallada aparezca **solo si** la petición presenta la credencial o una sesión de Coordinador; y T-HYBRID-14 concreta que esa validación la hace `CronAuthMiddleware`. Pero la ruta debe servir **200 público** cuando no hay credencial: eso es un predicado **no fatal**, y el middleware solo sabe rechazar. Reutilizarlo como está es imposible; la alternativa que queda —comparar el secreto otra vez dentro del controlador— es precisamente el «mecanismo de credencial paralelo» que la tarea prohíbe.

**Reparación honesta:** hacer público y reutilizable un predicado no fatal (`hasBatchCredential(Request): bool`) y construir sobre él **tanto** el rechazo del middleware **como** la decisión de telemetría del controlador, con una única implementación de la comparación en tiempo constante. Es un cambio pequeño y deja el contrato de credencial en un solo sitio, que es lo que el propio middleware documenta como su razón de existir.

---

### R3-7 · La paridad de inventarios prometida es inalcanzable tal como está redactada *(Media-alta)*

T-HYBRID-24 y el criterio §8.7 exigen que «los inventarios de tablas, columnas e índices del esquema PostgreSQL y del esquema MariaDB **sean equivalentes**». El propio diseño hace que **no puedan** serlo, y esa diferencia es deliberada:

| Elemento | Esquema local verificado | Esquema PostgreSQL planificado |
| :--- | :--- | :--- |
| Identidad de fila | `AUTO_INCREMENT` en las 20 tablas de `cloud_init.sql` | `BIGINT GENERATED ALWAYS AS IDENTITY` |
| Dominio cerrado | **24 conjuntos `ENUM(...)` distintos** entre `cloud_init.sql` (25 apariciones) y `schema.sql` | 6 tipos `CREATE TYPE` según T-HYBRID-01 |
| Unicidad de ticket activo | [`schema.sql:114`](../database/schema.sql#L114) columna generada `is_active_ticket` + `UNIQUE KEY` compuesto [`:123`](../database/schema.sql#L123) | índice único **parcial** `WHERE status NOT IN ('CLOSED','CANCELLED')` |
| Anti-borrado | **1** disparador `BEFORE DELETE` (solo `refund_requests`) | **20** disparadores `BEFORE DELETE` |

Un aserto de igualdad literal falla por construcción en las cuatro filas de esa tabla. Lo que se puede —y se debe— certificar es un **mapeo declarado**: qué columnas se corresponden, qué diferencias se aceptan por diseño (tipos, identidad, columna generada frente a índice parcial, número de disparadores) y cuáles son deriva real. Sin ese contrato, el guardián del §8.7 no es un criterio: es una promesa que ninguna implementación razonable puede cumplir.

**De paso, una cifra sin fuente:** T-HYBRID-01 pide «los 6 tipos `ENUM` del dominio», pero el esquema local contiene **24 conjuntos distintos** de valores. O el criterio enumera cuáles son esos 6 y su mapeo, o la cifra no verifica.

---

### R3-8 · El hallazgo N-7 no se cerró en tres sitios *(Media)*

La quinta decisión ratificada del plan —adaptador explícito por operación, con abandono formal de la premisa ANSI— quedó aplicada en `EARS-01.1/01.5`, en T-HYBRID-09, en T-HYBRID-25 y en T-HYBRID-12(3). **No** en:

1. **EARS-02.1** — el sembrado en limpio se describe con `ON CONFLICT DO UPDATE`, que es sintaxis **exclusiva** de PostgreSQL, presentada como «cláusula estándar».
2. **T-HYBRID-03** — repite literalmente esa orden («cláusula estándar `ON CONFLICT DO UPDATE`»), veinte líneas después de que T-HYBRID-12(3) prohíba exactamente eso en `SeedRunner` («en lugar de sostener un `ON CONFLICT` que no existe en MariaDB»).
3. **T-HYBRID-10 y T-HYBRID-11** — ordenan «sustituir funciones privativas de MySQL (`TIMESTAMPDIFF`, `ON DUPLICATE KEY UPDATE`) por **sintaxis ANSI portable**» sin mencionar el adaptador, y el comentario del árbol sigue anunciando «los 18 repositorios PDO normalizados a dialecto ANSI estándar».

Como esos dos constructos son, según la decisión ratificada, precisamente los que **no** tienen forma común, las tareas 10 y 11 no son ejecutables como están redactadas: quien las implemente literalmente no puede cerrarlas y quien las cierre de verdad estará incumpliendo su propio criterio de «Hecho cuando». Es la mitad del hallazgo N-7 que quedó sin propagar.

---

### R3-9 · Contradicciones internas y cifras sin fuente en el plan *(Media)*

1. **El árbol describe una tarea de otro tamaño:** `tasks.md # Plan de 22 tareas atómicas secuenciales`, cuando el fichero contiene **27**.
2. **El árbol duplica una suite que la tarea dice ampliar:** sigue listando `tests/unit/ConnectionFactoryTest.php` como prueba nueva, mientras §5.1 y T-HYBRID-19 dicen que se **amplía** la existente en `tests/integration/`. La contradicción vive dentro del mismo documento y la introdujo la reparación.
3. **Cifra sin fuente:** «6 tipos `ENUM`» frente a 24 conjuntos distintos (R3-7).
4. **La Fase 2 viola la regla del propio documento:** T-HYBRID-26 está listada **antes** de sus dependencias declaradas (T-HYBRID-12 y T-HYBRID-23), mientras la cabecera exige «estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras».

---

### R3-10 · El orquestador canónico del barrido no existe en ninguna definición de despliegue *(Media)*

La decisión de mover el barrido al servidor de aplicaciones es la correcta y la sostengo. Pero su criterio de cierre —T-HYBRID-04: «existe la programación periódica cada 15 minutos en el servidor de aplicaciones (cron del host o del proveedor)»— **no puede satisfacerse ni verificarse con nada que haya en el repositorio**: no hay `.github/`, no hay *compose*, no hay configuración de plataforma, y el único proceso que arranca la imagen es `php -S` ([`Dockerfile:21`](../Dockerfile#L21)). La disyunción «cron del host **o** del proveedor» deja sin decidir dónde vive el reloj del artículo del que depende la seguridad alimentaria, y el contenedor objetivo no declara ninguna facilidad de programación.

A la vez, EARS-06.6 mantiene vivo `pg_cron` + Vault como variante admitida: una segunda orquestación cuyo aprovisionamiento (extensión + secreto en un almacén del proveedor) no cubre ninguna tarea y cuya disponibilidad en el plan gratuito **no** he verificado en esta pasada.

**Reparación honesta:** elegir el host concreto y dejar en el repositorio el artefacto que lo hace verificable (una entrada de programación versionada, una configuración de plataforma o el procedimiento documentado con su evidencia), o convertir la disyunción en una decisión con dueño y plazo.

---

### R3-11 · Desorden normativo y dudas abiertas *(Baja)*

- **RF-06** enumera 06.1, 06.2, **06.5, 06.6**, 06.3, 06.4; **RF-07** enumera 07.1, **07.5**, 07.2, 07.3, 07.4. La numeración de criterios es la interfaz de trazabilidad del proyecto y conviene que sea monótona.
- **§9** declara «Ninguna duda abierta» mientras R3-1 a R3-7 siguen sobre la mesa, con R3-1 y R3-2 impidiendo empezar por T-HYBRID-01.

---

## 5. Calibración: lo que esta pasada confirma como correcto

Para que el informe sea utilizable y no una demolición:

1. **Las decisiones de arquitectura de la revisión 2 son acertadas y están bien fundadas.** Mover el barrido al cron del servidor de aplicaciones, congelar `/api/health`, crear `/api/status`, devolver el ejecutor de migraciones, sustituir la quimera ANSI por un adaptador y resolver la unicidad con claves canónicas: ninguna de las cinco se apoya en una premisa falsa contra el código.
2. **La ruta nueva para el diagnóstico es la decisión correcta y está bien calibrada.** Verificado: [`AppRouter.php:31`](../src/Presentation/Routing/AppRouter.php#L31) define el handler dual, [`:52`](../src/Presentation/Routing/AppRouter.php#L52) y [`:53`](../src/Presentation/Routing/AppRouter.php#L53) lo registran para `/` y `/api/health`, y **cinco** suites lo usan como sonda de arranque. Añadir `/api/status` en vez de tocar el contrato vivo evita romper consumidores reales.
3. **La cifra de tablas es exacta:** `cloud_init.sql` contiene exactamente **20** `CREATE TABLE`, la misma cifra que usan spec, plan y tareas.
4. **La guarda de cifras está correctamente identificada:** el README declara 249 suites y 9.425 aserciones, y el inventario real de ficheros-suite (108 + 64 + 76 + 1) coincide. T-HYBRID-22 no puede cerrarse sin actualizar esas cifras, y el plan lo dice.
5. **El presupuesto de espera de bloqueo ya tiene la forma correcta:** [`PdoLockWaitBudget.php`](../src/Infrastructure/Database/PdoLockWaitBudget.php) lee `@@SESSION.innodb_lock_wait_timeout` y la rama `pgsql` con `SET LOCAL lock_timeout` dentro de la transacción es la que el pooler admite.
6. **Ninguno de los nombres planificados colisiona con código existente:** `HealthCheckService`, `HealthCheckResponseDto`, `HealthController`, `SqlDialectAdapter`, `FileStorageCompensator`, `PostgresMigrationRunner`, `database/supabase_init.sql` y `database/supabase_migrations/` están libres.
7. **La medición de RNF-01 es medible tal como se diseñó:** 20 muestras por operación representativa, mediana y percentil 95 publicados y umbral explícito. El defecto no está en la métrica sino en dónde se ejecuta (R3-2).

---

## 6. Trazabilidad: hallazgo → requisito → artefacto

| Hallazgo | Requisito / artículo | Artefacto o tarea afectada |
| :--- | :--- | :--- |
| R3-1 | EARS-08.1, Art. III.1, criterio §8.2 | spec §1.6, §6.5; plan §4.3 y árbol; T-HYBRID-01, T-HYBRID-12(4), T-HYBRID-26; `TestDataCleaner`, `010_refund_hard_delete_guard.sql`, `RefundsModuleClosureTest` |
| R3-2 | RNF-04, EARS-01.3, EARS-07.1 | T-HYBRID-21, T-HYBRID-22, T-HYBRID-24, T-HYBRID-27; `tests/run_all.php`; `Dockerfile` |
| R3-3 | RNF-05 | EARS-01.2, plan §3.1, T-HYBRID-06 |
| R3-4 | EARS-01.4, Art. VI | Algoritmo 1, T-HYBRID-06, `AuditFindingsSecondWaveClosureTest` |
| R3-5 | EARS-05.2, EARS-05.3 | spec §6.2 y §8.5; T-HYBRID-08; `LocalFileUploader`, `IncidentCommentService` |
| R3-6 | EARS-07.2, EARS-07.3, Art. V.4 | plan §2.1, T-HYBRID-14, `CronAuthMiddleware` |
| R3-7 | EARS-08.5, EARS-02.4 | T-HYBRID-01, T-HYBRID-24, spec §8.7 |
| R3-8 | EARS-01.1, EARS-01.5, Art. I.3 | EARS-02.1, T-HYBRID-03, T-HYBRID-10, T-HYBRID-11, árbol del plan |
| R3-9 | Art. I.1 (coherencia documental) | plan §1, §5.1, T-HYBRID-01, T-HYBRID-19 |
| R3-10 | EARS-06.2, EARS-06.6, Art. II | T-HYBRID-04, `.github/workflows/supabase-keepalive.yml` |
| R3-11 | Art. I.2 | spec §4 (RF-06, RF-07), §9 |

---

## 7. Limitaciones de esta pasada

- **No se ejecutó ninguna suite.** La auditoría es estática; la batería sigue certificando MariaDB y no el motor nuevo.
- **No se estableció ninguna conexión contra PostgreSQL ni Supabase.** R3-3 se apoya en la documentación de libpq, no en una conexión ejecutada; R3-2 se apoya en el código de la batería, no en una ejecución de la batería con PostgreSQL levantado.
- **La disponibilidad de Vault y de `pg_cron`/`pg_net` en el plan gratuito no se ha reverificado** en esta pasada: R3-10 señala que el aprovisionamiento no existe en el plan, no que el proveedor no lo ofrezca.
- **El recuento de `ENUM` se hizo por conjuntos de valores distintos** (24) y no por tipos del dominio; si el plan pretende 6 tipos reutilizados, la cifra sigue necesitando su mapeo explícito.
- **Los hallazgos R3-1, R3-4, R3-5 y R3-6 se apoyan en código que existe hoy y está certificado**; los cuatro se pueden reparar sin escribir una línea de SQL.

---

## 8. Estado tras la reparación (revisión 3.1)

Los once hallazgos se han incorporado a los documentos del módulo 12 **sin tocar ninguna decisión ratificada**. La tabla dice dónde queda cada uno y qué sigue pendiente de código.

| Hallazgo | Dónde queda resuelto | Naturaleza |
| :--- | :--- | :--- |
| **R3-1** Forma de la puerta de purga | spec §1.6, EARS-08.1, §6.5 y criterio §8.2; plan §4.3 (con su propia cláusula de «forma por motor»), árbol y decisiones; T-HYBRID-01, 12(4) y 26; EARS-01.5 ya existente y EARS-01.6 nuevo, sin cambios en la decisión de sesión | Documental |
| **R3-2** Categorías de certificación | RNF-04 reescrita, criterio §8.1 en dos categorías, plan §5.3/§5.4 y nueva decisión ratificada; T-HYBRID-21/22/27 declaran su categoría | Documental |
| **R3-3** TLS verificado | RNF-05 pasa a `verify-full` + `sslrootcert` con prohibición de degradar; plan §3.1 (DSN y diagrama) y decisión nueva | Documental |
| **R3-4** Política de credenciales en ambos drivers | Nuevo EARS-01.6, criterio §8.6, Algoritmo 1 con el bloque de política S-5, decisión nueva y T-HYBRID-06 | Documental |
| **R3-5** Compensador con dueño | EARS-05.3, §6.2, criterio §8.5 y T-HYBRID-08 con los cinco puntos de uso, `LocalFileUploader::delete()` y la prueba de borrado | Documental |
| **R3-6** Predicado no fatal | EARS-07.3, plan §2.1 y T-HYBRID-14 con `hasBatchCredential(Request)` reutilizado | Documental |
| **R3-7** Correspondencia de esquema | Criterio §8.7 reescrito, T-HYBRID-24 y T-HYBRID-01 (24 conjuntos `ENUM`) | Documental |
| **R3-8** N-7 propagado | EARS-02.1, T-HYBRID-03, T-HYBRID-10/11, título de la Fase 2 y comentario del árbol | Documental |
| **R3-9** Contradicciones internas | Árbol del plan: «27 tareas», `ConnectionFactoryTest` solo en `tests/integration/`, Fase 2 reordenada (26 tras sus dependencias) | Documental |
| **R3-10** Reloj del barrido | **Decidido y materializado (revisión 3.2):** reloj canónico en el contenedor ([`deploy/cron/vendguard-sanitary-sweep`](../deploy/cron/vendguard-sanitary-sweep)) con despertador y vigilante externo ([`.github/workflows/sanitary-clock.yml`](../.github/workflows/sanitary-clock.yml)); EARS-06.2/06.5, criterio §8.3, plan §7 y T-HYBRID-04/12 | Documental **y ejecutable**: los artefactos existen y su comportamiento está probado |
| **R3-11** Orden y dudas | RF-06 y RF-07 reordenados por número y §9 con las tres dudas abiertas reales | Documental |

**Lo que esta reparación no demuestra:** sigue sin haber una línea de SQL ni de PHP nuevo, ni una sola suite de la batería ejecutada; las dos categorías de certificación están especificadas y **no** instrumentadas; y las dos dudas que quedan en spec §9 (aprovisionamiento del motor nuevo y diferencias de esquema aceptadas) no se cierran desde la documentación.

### 8.1 Cierre de R3-10: la decisión del reloj y sus artefactos (revisión 3.2)

La duda que este informe dejó abierta —«dónde vive el reloj del barrido»— queda decidida así: **el reloj canónico vive en el contenedor de aplicaciones y su despertador y vigilante vive fuera**, ninguna de las dos piezas en el motor de datos.

| Pieza | Artefacto | Qué resuelve |
| :--- | :--- | :--- |
| Reloj canónico | [`deploy/cron/vendguard-sanitary-sweep`](../deploy/cron/vendguard-sanitary-sweep) | Cadencia de 15 minutos declarada en el repositorio, instalada en la imagen por T-HYBRID-12 (busybox `crond`, ya presente en Alpine: sin dependencias nuevas) |
| Despertador y vigilante | [`.github/workflows/sanitary-clock.yml`](../.github/workflows/sanitary-clock.yml) | Despierta el contenedor suspendido, detecta la ventana perdida con la tolerancia de 35 minutos, interviene invocando el mismo servicio canónico y **alerta** |
| Canal de alerta | Ejecución en rojo (que el repositorio notifica) más webhook opcional | Que un barrido no ejecutado no muera en un registro que nadie lee (EARS-06.5) |
| Instrucciones y verificación | [`deploy/cron/README.md`](../deploy/cron/README.md) y plan §7.2 | Instalación y las comprobaciones observables de que el reloj existe y late |

**Comprobado ejecutando los cinco bloques `run:` reales del flujo** contra un servidor HTTP simulado, con las mismas variables que usa el runner: sin configuración falla en cerrado **sin emitir ninguna llamada de red**; con la ventana cubierta la ejecución queda **verde y sin duplicar** la pasada del reloj interno; con la ventana perdida invoca el barrido autenticado, alerta y deja la ejecución **en rojo**; y sin registro de pasada hace lo mismo. Además se validaron el YAML, la sintaxis de los cinco scripts y el formato de cinco campos de la entrada de crontab. Reproducción del arnés y modos de fallo cubiertos: plan §7.

**Riesgo que la decisión no elimina, y que queda escrito:** si la aplicación entera está caída, ningún reloj puede ejecutar lo que la aplicación no puede atender. El diseño garantiza que el fallo sea **visible** (ejecución en rojo y telemetría obsoleta) y que la recuperación sea **idempotente** (el barrido pone en cuarentena las máquinas vencidas en la primera pasada tras el retorno).

---

## Anexo A · Sondas ejecutadas

- Puerta de purga: `@vendguard_purge` en `database/`, `tests/` y `src/` (1 escritor: el limpiador); `set_config`/`current_setting`: cero coincidencias en todo el repositorio.
- Contrato del limpiador: `PURGE_ROOTS`, `PROTECTED_TABLES`, `KNOWN_OPERATIONAL`, `TEST_LOCATION_PREFIXES`, `withPurgeUnlocked()`.
- Batería: `glob()` de `unit/*.php`, `unit/*.mjs`, `integration/*.php` y `Manual/*.php`; ausencia de canal de omisión; recuento real de ficheros (108 + 64 + 76 + 1).
- Salud: handler compartido por `/` y `/api/health` con rama HTML; cinco suites que lo sondean por `curl`.
- Credenciales: política S-5 en `ConnectionFactory` y guarda que la certifica; `CronAuthMiddleware` (401 y predicado privado).
- TLS: `sslmode` declarado en los tres documentos; ausencia de `sslrootcert`.
- Asimetrías de esquema: 20 `CREATE TABLE`; 24 conjuntos `ENUM(...)` distintos (25 apariciones); 20 `AUTO_INCREMENT`; columna generada + índice compuesto en `schema.sql:114,123`; disparadores `CREATE TRIGGER`: 1 en MariaDB.
- Despliegue: `Dockerfile` (solo `pdo` y `pdo_mysql`; `CMD` con `init_cloud_db.php ; php -S`); ausencia de `.github/` y de *compose*; inventario de `bin/`.
- Subidas: `LocalFileUploader` (`upload`, `delete`, `validate`), cinco llamantes y orden subida → inserción en `IncidentCommentService`.
- Colisiones de nombre: los ocho artefactos planificados están libres.

## Anexo B · Fuentes externas consultadas

- PostgreSQL · [SSL Support (libpq)](https://www.postgresql.org/docs/current/libpq-ssl.html) — verificación por defecto, semántica de `require`, `verify-ca` y `verify-full`.
