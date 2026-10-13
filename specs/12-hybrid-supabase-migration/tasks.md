# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO 12: MIGRACIÓN DE PERSISTENCIA A BASE DE DATOS EN LA NUBE (ENFOQUE HÍBRIDO) (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `12-hybrid-supabase-migration`  
**Documento:** `specs/12-hybrid-supabase-migration/tasks.md`  
**Referencia Funcional:** [`specs/12-hybrid-supabase-migration/spec.md`](spec.md) (y [`specs/functional/hybrid_supabase_migration_spec.md`](../functional/hybrid_supabase_migration_spec.md))  
**Plan Técnico:** [`specs/12-hybrid-supabase-migration/plan.md`](plan.md)  
**Auditoría de Referencia:** [`docs/auditoria_plan_migracion_supabase_hibrida.md`](../../docs/auditoria_plan_migracion_supabase_hibrida.md)  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  
**Sistema de Diseño Visual:** [`docs/design.md`](../../docs/design.md) (Azul corporativo `#2560ff`, ámbar técnico `#fef9c3` con texto `#854d0e`, radio 4px/8px, canvas `#f9fafb`)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Base de Datos PostgreSQL, DDL Canónico y Flujo de Latido Keep-Alive

- [ ] **T-HYBRID-01: Crear DDL base de PostgreSQL `database/supabase_init.sql` (Parte 1: Tablas 1 a 10, claves canónicas, disparador anti-delete con puerta de purga y blindajes)**
  * **Requisitos:** RF-01.1, RF-02.1, RF-08.1, RF-08.2, RF-08.3, RF-08.5, RF-09.1, Constitución Art. III.1, Art. V.1, Art. V.2, Art. V.6
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe `database/supabase_init.sql` conteniendo: extensiones `uuid-ossp`, `pgcrypto` y `pg_cron` (más `pg_net` únicamente en la variante de orquestador interno admitida en T-HYBRID-04); los tipos `ENUM` del dominio **con su tabla de correspondencia** respecto a los 24 conjuntos distintos del esquema local (contrato de correspondencia del criterio §8, criterio 7); la función de disparador `prevent_hard_delete()` que emite excepción `SQLSTATE '45000'` salvaguardando la señal de purga **en la forma del motor activo** —`vendguard.purge = on` en PostgreSQL y `@vendguard_purge` en MariaDB—, declarada **a nivel de sesión** por el arnés a través de un único ayudante consciente del driver y jamás por la aplicación; la definición de las tablas `locations`, `machines`, `users`, `incidents`, `incident_history`, `incident_comments`, `audit_log`, `route_settings`, `preventive_settings` y `preventive_orders` con `BIGINT GENERATED ALWAYS AS IDENTITY`, tipos `TIMESTAMPTZ`, columnas canónicas `email_key` en `users` y `site_code_key` en `locations` con `UNIQUE` sobre la clave (EARS-08.5), sin depender de ninguna colación de motor, restricciones `CHECK` $\ge 20$ caracteres (Art. V.1), el índice único parcial `uq_machine_active_ticket ON incidents (machine_id) WHERE status NOT IN ('CLOSED', 'CANCELLED')` (Art. V.2 y V.6), y disparadores `BEFORE DELETE` vinculados a `prevent_hard_delete()` en todas ellas (Art. III.1).

- [ ] **T-HYBRID-02: Completar DDL canónico de PostgreSQL `database/supabase_init.sql` (Parte 2: Tablas 11 a 20 con disparadores anti-delete)**
  * **Requisitos:** RF-01.1, RF-02.1, RF-08.1, Constitución Art. III.1
  * **Dependencias:** T-HYBRID-01
  * **Hecho cuando:** El archivo `database/supabase_init.sql` incluye las definiciones completas con tipos nativos y disparadores `BEFORE DELETE` para: `preventive_order_items`, `sanitary_certificates`, `spare_parts`, `spare_part_compatibilities`, `spare_part_requests`, `incident_replaced_parts`, `refund_requests`, `unclaimed_cash_findings`, `sanitary_sweep_passes` y `sanitary_sweep_contentions`, verificando que el script SQL completo de las 20 tablas compila limpiamente en PostgreSQL 15+.

- [ ] **T-HYBRID-03: Crear script de semillas maestras para arranque en limpio `database/supabase_seeds.sql`**
  * **Requisitos:** RF-02.1, RF-02.2, RF-02.3, Constitución Art. V.4
  * **Dependencias:** T-HYBRID-02
  * **Hecho cuando:** Existe `database/supabase_seeds.sql` con sentencias de inserción cuya sintaxis de upsert resuelve el **adaptador de dialecto** (T-HYBRID-25) —`ON CONFLICT ... DO UPDATE` en PostgreSQL, `ON DUPLICATE KEY UPDATE` en MariaDB, con una sola definición de la regla— que cargan las 10 sedes iniciales, las 13 máquinas maestras, el personal interno con contraseñas seguras hasheadas, y el catálogo maestro de repuestos con compatibilidades por modelo, sin insertar ninguna incidencia activa ni expediente histórico previo (*Fresh Start* estricto).

- [ ] **T-HYBRID-04: Reloj canónico versionado en el contenedor, despertador y vigilante externo con reintento y alerta**
  * **Requisitos:** RF-06.1, RF-06.2, RF-06.5, RF-06.6, Constitución Art. II, Art. I.3
  * **Dependencias:** T-HYBRID-02
  * **Hecho cuando:** Existen los dos artefactos ya versionados —[`deploy/cron/vendguard-sanitary-sweep`](../../deploy/cron/vendguard-sanitary-sweep) como reloj canónico cada 15 minutos en el contenedor y [`.github/workflows/sanitary-clock.yml`](../../.github/workflows/sanitary-clock.yml) como despertador y vigilante externo—, el primero invoca el CLI [`bin/sanitary_clock_sweep.php`](../../bin/sanitary_clock_sweep.php) con el entorno inyectado desde `/etc/vendguard/cron.env` y el segundo solo interviene cuando `sanitary_clock.last_pass_at` supera la tolerancia de 35 minutos, invocando entonces el mismo servicio por `POST /api/cron/sanitary-clock-sweep` autenticado con `CRON_SECRET`; toda invocación fallida reintenta con espera progresiva; cada pasada deja constancia de su resultado; y una ventana sin barrido ejecutado **genera alerta** por un canal que exige atención (ejecución en rojo más webhook cuando esté configurado), marcando la intervención externa como anomalía en lugar de silenciarla. La variante `pg_cron` + `pg_net` solo se admite —documentada en el mismo sitio— si la credencial se lee de un almacén de secretos del proveedor, quedando prohibido incorporarla al DDL versionado. La lógica de cálculo permanece exclusivamente en [`SanitaryClockSweepService.php`](../../src/Application/Service/SanitaryClockSweepService.php) (Art. I.3). La variante `pg_cron` + `pg_net` solo se admite —documentada en el mismo fichero— si la credencial se lee de un almacén de secretos del proveedor, quedando prohibido incorporarla al DDL versionado. La lógica de cálculo permanece exclusivamente en [`SanitaryClockSweepService.php`](../../src/Application/Service/SanitaryClockSweepService.php) (Art. I.3). 

- [ ] **T-HYBRID-05: Implementar flujo desatendido de latido, comprobación de estado del proyecto, auto-restauración y re-inicialización `.github/workflows/supabase-keepalive.yml`**
  * **Requisitos:** RF-03.1, RF-03.2, RF-03.3, RF-02.5, RNF-02
  * **Dependencias:** T-HYBRID-04, T-HYBRID-23
  * **Hecho cuando:** Existe `.github/workflows/supabase-keepalive.yml` con programación `'0 6 * * *'` y `workflow_dispatch` que: (1) consulta `GET /api/status` para registrar actividad real con una consulta sobre tabla; (2) **determina el estado del proyecto por la API de gestión** —no deduciéndolo del código HTTP de la aplicación, que nunca puede observar el `540`—; (3) si el proyecto está suspendido, invoca `POST /v1/projects/{ref}/restore` con `SUPABASE_ACCESS_TOKEN` custodiado como secreto, espera al estado activo y **dispara la re-inicialización y verificación del esquema** (EARS-02.5); y (4) notifica el desenlace por un canal distinto del propio log de ejecución.

---

## Fase 2: Infraestructura de Conexión, Normalización de Dialecto por Adaptador, Presupuesto de Espera y Docker

- [ ] **T-HYBRID-06: Refactorizar `ConnectionFactory.php` con autodetección de driver `pdo_pgsql` vs `pdo_mysql`, Supavisor y SSL**
  * **Requisitos:** RF-01.2, RF-01.4, RF-09.1, RNF-01, RNF-05, EARS-01.6 (Constitución Art. VI)
  * **Dependencias:** T-HYBRID-01
  * **Hecho cuando:** `src/Infrastructure/Database/ConnectionFactory.php` detecta esquemas `postgres://` o `pgsql:` en `DATABASE_URL`, se conecta mediante `pdo_pgsql` al puerto 6543 (Supavisor) con `sslmode=verify-full` y la CA declarada (`sslrootcert`) —prohibido degradar a `require`, `prefer`, `allow` o `disable`—, `ATTR_TIMEOUT => 5`, `ATTR_EMULATE_PREPARES => true` (obligatorio para evitar errores de sentencias preparadas en el pooler), aplica la **política de credenciales S-5 de forma idéntica a los dos drivers** (fuente explícita obligatoria en producción; rechazo del usuario administrativo sin contraseña) y la resuelve **antes** de intentar conectar, sin valores por defecto silenciosos (`postgres` con contraseña vacía no conecta); `AuditFindingsSecondWaveClosureTest` se amplía para certificar esa política también en la rama `pgsql` sin necesidad de red, y el funcionamiento de `pdo_mysql` ante URLs locales de MariaDB permanece intacto.

- [ ] **T-HYBRID-07: Adaptar `PdoLockWaitBudget.php` con soporte dual para PostgreSQL (`lock_timeout`) y MySQL (`innodb_lock_wait_timeout`)**
  * **Requisitos:** RF-01.1, RF-09.3, RNF-01
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** `src/Infrastructure/Database/PdoLockWaitBudget.php` detecta el driver activo: si es `pgsql`, gestiona la cota mediante `current_setting('lock_timeout')` y `SET LOCAL lock_timeout = ...` dentro de la transacción activa; si es `mysql`, preserva la lógica de `@@SESSION.innodb_lock_wait_timeout`.

- [ ] **T-HYBRID-08: Implementar `FileStorageCompensator.php` para transaccionalidad compensatoria de archivos**
  * **Requisitos:** RF-05.2, RF-05.3, RF-05.4, RNF-03
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe `src/Infrastructure/Storage/FileStorageCompensator.php` con el método `executeWithCompensatingFile(string $publicPath, callable $dbOperation): mixed` que ejecuta la operación de base de datos dentro de un bloque `try/catch` y, ante excepción o fallo de red, **delega el borrado físico** en [`LocalFileUploader::delete()`](../../src/Infrastructure/Storage/LocalFileUploader.php) a partir de la **ruta pública** que devolvió la subida —sin reimplementar `unlink()`—, evitando fugas de archivos huérfanos. El compensador queda **conectado a los cinco puntos de uso reales de la subida** (`IncidentCommentService`, `CoordinatorController`, `LocationPortalController`, `QrScanController` y `TechnicianController`), con una prueba que demuestra que, tras un fallo de persistencia, el fichero **ya no existe** en disco.

- [ ] **T-HYBRID-25: Implementar `SqlDialectAdapter.php` como punto único de traducción por operación**
  * **Requisitos:** RF-01.1, RF-01.5, Constitución Art. I.3
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** Existe `src/Infrastructure/Dialect/SqlDialectAdapter.php` que, según el driver activo, resuelve las construcciones que **carecen** de forma estándar común: cláusula de upsert (`ON CONFLICT ... DO UPDATE` con `EXCLUDED` frente a `ON DUPLICATE KEY UPDATE` con `VALUES()`), extracción de JSON (`->>`/`jsonb` frente a `JSON_UNQUOTE(JSON_EXTRACT(...))`) y aritmética de intervalos y diferencias temporales (`CURRENT_TIMESTAMP - INTERVAL` y `EXTRACT(EPOCH FROM ...)` frente a `DATE_SUB`/`TIMESTAMPDIFF`). Los repositorios consumen ese adaptador en lugar de duplicar queries divergentes, y `SqlDialectPortabilityTest` certifica que no queda SQL privativo **fuera** del adaptador. Se abandona formalmente la premisa de «una consulta ANSI para todo».

- [ ] **T-HYBRID-09: Normalizar dialecto SQL ANSI en repositorios PDO (Grupo 1: Incidencias, Máquinas, Sedes, Usuarios)**
  * **Requisitos:** RF-01.1, RF-01.5, RF-08.1, RF-08.2, RF-08.3, RF-08.5, Constitución Art. III.1, Art. V.1, Art. V.2
  * **Dependencias:** T-HYBRID-06, T-HYBRID-25
  * **Hecho cuando:** `PdoIncidentRepository.php`, `PdoMachineRepository.php`, `PdoLocationRepository.php` y `PdoUserRepository.php` eliminan backticks (\`), resuelven sus cálculos de fecha y sus lecturas de JSON a través del adaptador de dialecto (`DATE_SUB(NOW(), INTERVAL ...)` $\rightarrow$ `(CURRENT_TIMESTAMP - INTERVAL ...)`), adaptan columnas `is_active` con `PDO::PARAM_BOOL`, **escriben la clave canónica** en cada alta o modificación de `users` y `locations` (EARS-08.5) y ejecutan sin fallos en ambos motores relacionales.

- [ ] **T-HYBRID-10: Normalizar dialecto SQL ANSI en repositorios PDO (Grupo 2: Reintegros, Repuestos, Piezas Sustituidas, Métricas)**
  * **Requisitos:** RF-01.1, RF-08.1, Constitución Art. III.1
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** `PdoRefundRequestRepository.php`, `PdoSparePartRepository.php`, `PdoSparePartRequestRepository.php`, `PdoIncidentReplacedPartRepository.php` y `PdoMetricsRepository.php` resuelven sus diferencias de dialecto —diferencias temporales, intervalos y upsert— **consumiendo el adaptador de dialecto** (T-HYBRID-25) y no repitiendo la traducción en cada repositorio; ninguna regla de negocio se define dos veces y `SqlDialectPortabilityTest` certifica que no queda SQL privativo fuera del adaptador.

- [ ] **T-HYBRID-11: Normalizar dialecto SQL ANSI en repositorios PDO (Grupo 3: Mantenimiento Preventivo, Rutas, Sanitario, Auditoría)**
  * **Requisitos:** RF-01.1, RF-06.3, RF-08.1, Constitución Art. II, Art. III.1
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** `PdoPreventiveSettingsRepository.php`, `PdoPreventiveOrderRepository.php`, `PdoPreventiveItemRepository.php`, `PdoRouteSettingsRepository.php`, `PdoSanitaryCertificateRepository.php`, `PdoSanitarySweepVisibilityRepository.php`, `PdoAuditLogRepository.php`, `PdoSiteAccessCodeRepository.php` y `PdoUnclaimedCashFindingRepository.php` completan la normalización de los 18 repositorios del proyecto consumiendo el **adaptador de dialecto** (T-HYBRID-25), sin SQL privativo fuera de él y sin duplicar la definición de ninguna regla.

- [ ] **T-HYBRID-12: Adaptar `Dockerfile`, `bin/init_cloud_db.php`, `SeedRunner.php` y `TestDataCleaner.php`**
  * **Requisitos:** RF-01, RF-02, RF-08.1, Constitución Art. III.1
  * **Dependencias:** T-HYBRID-02, T-HYBRID-03, T-HYBRID-06
  * **Hecho cuando:** (1) `Dockerfile` instala `postgresql-dev` y compila la extensión `pdo_pgsql` **verificando que la cadena de compilación está disponible en la imagen** (`$PHPIZE_DEPS`), de modo que la construcción no dependa de una suposición; instala además el reloj del barrido copiando [`deploy/cron/vendguard-sanitary-sweep`](../../deploy/cron/vendguard-sanitary-sweep) a `/etc/crontabs/root` con modo `600`, arranca `crond` antes del servidor web y genera `/etc/vendguard/cron.env` en el arranque a partir del entorno del contenedor, de modo que el reloj declarado en T-HYBRID-04 exista realmente dentro de la imagen; (2) `bin/init_cloud_db.php` autodetermina el driver, aplica el DDL base **y el conjunto de migraciones del motor** (T-HYBRID-23), reintenta al menos tres veces con espera progresiva y **sale con error si el esquema no queda verificado**, sin dejar el servicio sirviendo contra una base sin esquema comprobado; (3) `SeedRunner.php` obtiene su sintaxis de upsert del adaptador de dialecto (T-HYBRID-25), en lugar de sostener un `ON CONFLICT` que no existe en MariaDB; y (4) `tests/Support/TestDataCleaner.php` declara la señal de purga **a nivel de sesión y en la forma del motor activo** —`set_config('vendguard.purge', 'on', false)` en PostgreSQL, `SET @vendguard_purge = 1` en MariaDB— a través de un **único ayudante consciente del driver**, de modo que la purga funcione también en las sentencias de borrado que las suites emiten sin transacción explícita; las aserciones que hoy fijan la forma de MySQL (`RefundsModuleClosureTest` §4.2/§4.4 y `SiteManagerRefundDataSegregationTest`) se **extienden** para certificar las dos formas, sin borrarlas ni debilitarlas.

- [ ] **T-HYBRID-23: Implementar `PostgresMigrationRunner.php` y el conjunto `database/supabase_migrations/` (convergencia del esquema desplegado)**
  * **Requisitos:** RF-02.4, RF-02.5, Constitución Art. I.2, Art. III.1
  * **Dependencias:** T-HYBRID-01, T-HYBRID-02
  * **Hecho cuando:** Existe `src/Infrastructure/Database/PostgresMigrationRunner.php` que aplica, de forma idempotente y en orden, los ficheros de `database/supabase_migrations/` (arrancando con `001_initial_baseline.sql`) sobre una base PostgreSQL ya creada; `bin/init_cloud_db.php` lo invoca cuando el driver es `pgsql`; y se demuestra con una ejecución real que una base creada con el DDL base **recibe** una migración posterior y converge al esquema del repositorio, en lugar de quedarse congelada en su creación inicial (incidente del 2026-10-03).

- [ ] **T-HYBRID-24: Extender el guardián de esquema a la convergencia y a la paridad cruzada de motores**
  * **Requisitos:** RF-02.4, RF-01.5, Constitución Art. I.2
  * **Dependencias:** T-HYBRID-23, T-HYBRID-11
  * **Hecho cuando:** Existe `tests/integration/SchemaConvergenceParityTest.php`, que certifica dos cosas distintas y separadas: (1) **convergencia** —una base construida solo con el DDL base recibe la migración posterior y su inventario pasa a coincidir con el del repositorio, y el escenario «solo DDL base» se detecta como incompleto—; y (2) **correspondencia declarada** —un contrato escrito que enumera qué tablas, columnas e índices deben coincidir uno a uno y qué diferencias se aceptan por diseño (tipos nativos, identidad de fila frente a `AUTO_INCREMENT`, columna generada con índice compuesto frente a índice único parcial, y número de disparadores anti-borrado por motor)—, de modo que una columna añadida en un motor y olvidada en el otro haga fallar la suite mientras una diferencia declarada y aceptada no la hace. Queda prohibido exigir igualdad literal de inventarios, porque el diseño la impide a propósito. Se sustituye el nombre engañoso `PostgresParityTest` por `PostgresSchemaTest`, que solo hace CRUD sobre el motor nuevo.

- [ ] **T-HYBRID-26: Extender la puerta de purga a todas las estructuras de limpieza del arnés**
  * **Requisitos:** RF-08.1, EARS-08.1, Constitución Art. III.1
  * **Dependencias:** T-HYBRID-12, T-HYBRID-23
  * **Hecho cuando:** Se han revisado y adaptado **todas** las estructuras de limpieza, no solo el limpiador compartido: las seis suites que borran filas maestras con SQL directo —`PdoUserRepositoryTest`, `PdoMachineRepositoryTest`, `PdoLocationRepositoryTest`, `IncidentPauseServiceTest`, `IncidentPauseInactivityCancellationTest` y `HarnessResidueGuardTest`— canalizan sus borrados a través de la **señal de sesión en la forma del motor activo** —`vendguard.purge` en PostgreSQL, `@vendguard_purge` en MariaDB— declarada por el único ayudante del limpiador, y la batería completa se ejecuta sin un solo rechazo `45000` procedente del arnés. Se certifica además que la aplicación **nunca** activa esa señal, y las aserciones que hoy fijan la forma de MySQL se **extienden** a las dos formas en lugar de debilitarse.

---

## Fase 3: Servicios de Aplicación, Health Check y Controladores REST

- [ ] **T-HYBRID-13: Implementar DTO inmutable `HealthCheckResponseDto.php` y servicio de aplicación `HealthCheckService.php`**
  * **Requisitos:** RF-07.1, RF-07.2, RF-07.3, RNF-01, Constitución Art. V.4, Art. VI.1
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** Existen `src/Application/DTO/HealthCheckResponseDto.php` (clase `final readonly` con proyección pública segura `status` y `timestamp_utc`, y telemetría extendida para llamadas autenticadas) y `src/Application/Service/HealthCheckService.php` que evalúa la conexión activa con una **consulta sobre una tabla real del dominio** —no un `SELECT 1` sintético, que puede no contar como actividad ante el proveedor— cronometrando la latencia y publicando en la telemetría autenticada `sanitary_clock.last_pass_at` leído del historial de pasadas del barrido, que es la señal que usa el vigilante externo (T-HYBRID-04), sin exponer secretos ni contraseñas.

- [ ] **T-HYBRID-14: Implementar `HealthController.php` y registrar `GET /api/status` conservando `GET /api/health`**
  * **Requisitos:** RF-07.1, RF-07.2, RF-07.3, RF-07.5, Constitución Art. V.4, Art. VI.1
  * **Dependencias:** T-HYBRID-13
  * **Hecho cuando:** Existe `src/Presentation/Controller/HealthController.php` con el método `check(Request $request): Response`, `src/Presentation/Routing/AppRouter.php` registra la ruta **nueva** `GET /api/status` —que verifica la base de datos y emite 200 con estado o 503 cuando no responde—, y la telemetría detallada se filtra salvo que la petición presente la credencial de proceso batch ya existente (`X-Cron-Secret` o `Authorization: Bearer` con `CRON_SECRET`, validada por `CronAuthMiddleware`) o una sesión de Coordinador. La comprobación es **no fatal** —la ausencia de credencial no rechaza la petición, solo reduce la respuesta— y se apoya en un **predicado público y reutilizable** del propio middleware (`hasBatchCredential(Request): bool`), que el rechazo del proceso batch usa también: una sola implementación de la comparación del secreto. **No se crea ninguna cabecera nueva ni una segunda comparación**, y el contrato de `GET /api/health` queda intacto: se certifica con prueba que su respuesta pública no ha cambiado y que sigue sirviendo el documento de la aplicación ante `Accept: text/html`.

- [ ] **T-HYBRID-15: Adaptar `TechnicianController.php` con salvaguarda del informe técnico ante cuarentena biológica sobrevenida**
  * **Requisitos:** RF-10.1, RF-10.2, Constitución Art. II, Art. V.1
  * **Dependencias:** T-HYBRID-09
  * **Hecho cuando:** En `TechnicianController::resolveIncident()`, si la máquina entró en `QUARANTINE` a las 4h en la central mientras el técnico trabajaba sin cobertura, el sistema almacena el diagnóstico y repuestos en borrador seguro, suspende el cierre y devuelve un código HTTP 422 con código `SANITARY_QUARANTINE_OVERTAKEN`, exigiendo el checklist de higienización sin borrar el informe redactado.

---

## Fase 4: Frontend Vanilla Vue.js 3 ESM

- [ ] **T-HYBRID-16: Implementar componente global flotante reactivo `ConnectionStateBanner.js`**
  * **Requisitos:** RF-04.3, RF-04.4, RNF-06
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe `public/assets/js/components/ConnectionStateBanner.js` como módulo ES nativo de Vue 3 que renderiza un banner flotante fijo superior (`z-index: 9999`) con estados `idle` (oculto), `disconnected` (aviso ámbar accesible con botón táctil $\ge 44\text{ px}$ *"Reintentar"*) y `reconnected` (verde por 3 segundos), escuchando eventos nativos `vendguard:network-status`.

- [ ] **T-HYBRID-17: Adaptar `public/assets/js/api.js` con timeout ordinario (5,0s), CustomEvents y retención de formularios**
  * **Requisitos:** RF-04.1, RF-04.2, RF-04.3, RNF-01
  * **Dependencias:** T-HYBRID-16
  * **Hecho cuando:** `public/assets/js/api.js`: (1) aplica `AbortSignal.timeout(5000)` en operaciones de red; (2) deshabilita botones de envío durante peticiones en curso; y (3) ante fallos de red o timeout emite `vendguard:network-status` con el callback de reintento, preservando los datos reactivos del formulario en memoria sin recargar la página.

- [ ] **T-HYBRID-18: Integrar `ConnectionStateBanner.js` en `public/assets/js/app.js` y `public/index.html`**
  * **Requisitos:** RF-04.3, RF-04.4, RNF-06
  * **Dependencias:** T-HYBRID-16, T-HYBRID-17
  * **Hecho cuando:** `public/assets/js/app.js` importa y monta el componente `ConnectionStateBanner.js` en la raíz de la aplicación Vue 3, y el contenedor es visible en todas las vistas (Coordinador, Técnico, Sede y Portal QR) sin interferir con la navegación ordinaria cuando la conexión está activa.

---

## Fase 5: Estrategia de Pruebas Unitarias, Integración, Blindaje Constitucional y Certificación Global

- [ ] **T-HYBRID-19: Ampliar la suite existente `ConnectionFactoryTest.php` e implementar las pruebas unitarias PHP `HealthCheckServiceTest.php`, `PdoLockWaitBudgetTest.php` y `SqlDialectPortabilityTest.php`**
  * **Requisitos:** RF-01, RF-07, RF-09.3, RNF-01, RNF-04, RNF-05
  * **Dependencias:** T-HYBRID-06, T-HYBRID-07, T-HYBRID-11, T-HYBRID-13, T-HYBRID-25
  * **Hecho cuando:** Se amplía la suite ya existente [`ConnectionFactoryTest.php`](../../tests/integration/ConnectionFactoryTest.php) —que hoy verifica atributos PDO y consulta el motor con sintaxis exclusiva de MySQL (`SHOW VARIABLES`, `SELECT DATABASE()`) y **no se duplica** creando otro fichero homónimo en `tests/unit/`— con la autodetección de driver, los timeouts, `ATTR_EMULATE_PREPARES => true` y la comprobación equivalente por driver, y la ejecución de `php tests/unit/HealthCheckServiceTest.php`, `php tests/unit/PdoLockWaitBudgetTest.php` y `php tests/unit/SqlDialectPortabilityTest.php` pasa al 100% en verde, validando los presupuestos de espera por driver, la consulta a tabla real y la ofuscación de credenciales en el health check, y la ausencia de sintaxis privativa fuera del adaptador de dialecto en los 18 repositorios PDO.

- [ ] **T-HYBRID-20: Implementar pruebas unitarias frontend ESM en Node.js `ConnectionStateBannerTest.mjs` y `ApiClientTimeoutTest.mjs`**
  * **Requisitos:** RF-04, RNF-06
  * **Dependencias:** T-HYBRID-16, T-HYBRID-17
  * **Hecho cuando:** La ejecución de `node tests/unit/ConnectionStateBannerTest.mjs` y `node tests/unit/ApiClientTimeoutTest.mjs` pasa al 100% en verde sin dependencias npm, evaluando el renderizado de los estados del banner, la altura del botón táctil $\ge 44\text{ px}$ y el corte exacto de timeout a los 5,0s con preservación del borrador reactivo.

- [ ] **T-HYBRID-21: Implementar pruebas de integración HTTP y blindaje constitucional `HealthEndpointTest.php`, `PostgresSchemaTest.php` y `PostgresConstitutionalTest.php`**
  * **Requisitos:** RF-01, RF-02, RF-07.5, RF-08.1, RF-08.2, RF-08.3, RF-08.4, RF-08.5, RF-10, Constitución Art. II, Art. III.1, Art. V.1, Art. V.2, Art. V.4, Art. V.6
  * **Dependencias:** T-HYBRID-14, T-HYBRID-19, T-HYBRID-26
  * **Hecho cuando:** `php tests/integration/HealthEndpointTest.php`, `php tests/integration/PostgresSchemaTest.php` y `php tests/integration/PostgresConstitutionalTest.php` pasan al 100% en verde certificando: (1) respuestas 200 OK y 503 en `/api/status`, **más la no regresión del contrato de `/api/health`** (envoltura JSON y servicio del documento de la aplicación); Las certificaciones (2) a (6) se ejecutan sobre PostgreSQL y pertenecen a la **certificación de contrato del motor nuevo**: categoría propia, canal propio e informe propio, con el motor disponible; no forman parte de la batería hermética offline (RNF-04) y ninguna se omite en silencio. (2) operaciones CRUD sobre las 20 tablas de PostgreSQL; (3) rechazo ruidoso con `SQLSTATE '45000'` ante sentencias `DELETE FROM` (Art. III.1) **con la puerta de purga del arnés funcionando**, incluidos los borrados dirigidos que las suites emiten sin transacción; (4) rechazo atómico de tickets duplicados con `RESOLVED` (Art. V.2/V.6); (5) rechazo por base de datos de justificaciones $< 20$ caracteres (Art. V.1); y (6) igualdad de la unicidad de email y `site_code` entre los dos motores mediante clave canónica; más la comprobación de que la telemetría autenticada publica `sanitary_clock.last_pass_at` y que la pública no lo publica.

- [ ] **T-HYBRID-27: Medir la latencia transaccional contra el motor remoto (RNF-01)**
  * **Requisitos:** RNF-01, RNF-05
  * **Dependencias:** T-HYBRID-06, T-HYBRID-11
  * **Hecho cuando:** Existe `tests/integration/PoolerLatencyMeasurementTest.php` que mide, **contra el motor remoto y por el camino real** (enrutador, middleware, servicio y PDO), un mínimo de 20 muestras por operación representativa —crear incidencia, listar triaje, pausar y reanudar— publicando mediana y percentil 95 y fallando si la mediana supera los 250 ms. El test deja constancia escrita de que las cifras históricas medidas contra el motor local no se heredan y no valen como evidencia para este requisito, y pertenece a la **certificación de contrato del motor nuevo**: se ejecuta en su canal, con el motor remoto disponible, y su resultado se informa por separado en lugar de mezclarse con la batería hermética.

- [ ] **T-HYBRID-22: Actualizar cifras documentadas en `README.md` (`DocMetricsGuard.php`) y certificar paridad global (`php tests/run_all.php`)**
  * **Requisitos:** RF-01, RNF-04, Constitución Art. I.1, Art. I.2, Art. I.3
  * **Dependencias:** T-HYBRID-19, T-HYBRID-20, T-HYBRID-21, T-HYBRID-24, T-HYBRID-26, T-HYBRID-27
  * **Hecho cuando:** Se actualizan las cifras documentadas de la batería en `README.md` para satisfacer [`DocMetricsGuard.php`](../../tests/Support/DocMetricsGuard.php) la ejecución completa de `php tests/run_all.php` finaliza al **100% en verde (0 errores, 0 fallos)** para la **batería hermética** —todas las suites que corren sin red contra el motor local, existentes y nuevas—, y la **certificación de contrato del motor nuevo** (T-HYBRID-27 incluida) se ejecuta en su propio canal y se informa por separado, también en verde. Las dos certificaciones deben estar en verde antes de cerrar el módulo (spec §8.1).
