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

- [ ] **T-HYBRID-01: Crear DDL canónico de PostgreSQL `database/supabase_init.sql` (Parte 1: Tablas 1 a 10, citext, disparador anti-delete con bypass y blindajes)**
  * **Requisitos:** RF-01.1, RF-02.1, RF-08.1, RF-08.2, RF-08.3, RF-08.5, RF-09.1, Constitución Art. III.1, Art. V.1, Art. V.2, Art. V.6
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe `database/supabase_init.sql` conteniendo: extensiones `uuid-ossp`, `pgcrypto`, `citext`, `pg_cron`, `pg_net`; los 6 tipos `ENUM` del dominio; la función de disparador `prevent_hard_delete()` que emite excepción `SQLSTATE '45000'` salvaguardando el bypass `vendguard.purge = on`; la definición de las tablas `locations`, `machines`, `users`, `incidents`, `incident_history`, `incident_comments`, `audit_log`, `route_settings`, `preventive_settings` y `preventive_orders` con `BIGINT GENERATED ALWAYS AS IDENTITY`, tipos `TIMESTAMPTZ`, columnas `CITEXT` para unicidad case-insensitive, restricciones `CHECK` $\ge 20$ caracteres (Art. V.1), el índice único parcial `uq_machine_active_ticket ON incidents (machine_id) WHERE status NOT IN ('CLOSED', 'CANCELLED')` (Art. V.2 y V.6), y disparadores `BEFORE DELETE` vinculados a `prevent_hard_delete()` en todas ellas (Art. III.1).

- [ ] **T-HYBRID-02: Completar DDL canónico de PostgreSQL `database/supabase_init.sql` (Parte 2: Tablas 11 a 20 con disparadores anti-delete)**
  * **Requisitos:** RF-01.1, RF-02.1, RF-08.1, Constitución Art. III.1
  * **Dependencias:** T-HYBRID-01
  * **Hecho cuando:** El archivo `database/supabase_init.sql` incluye las definiciones completas con tipos nativos y disparadores `BEFORE DELETE` para: `preventive_order_items`, `sanitary_certificates`, `spare_parts`, `spare_part_compatibilities`, `spare_part_requests`, `incident_replaced_parts`, `refund_requests`, `unclaimed_cash_findings`, `sanitary_sweep_passes` y `sanitary_sweep_contentions`, verificando que el script SQL completo de las 20 tablas compila limpiamente en PostgreSQL 15+.

- [ ] **T-HYBRID-03: Crear script de semillas maestras para arranque en limpio `database/supabase_seeds.sql`**
  * **Requisitos:** RF-02.1, RF-02.2, RF-02.3, Constitución Art. V.4
  * **Dependencias:** T-HYBRID-02
  * **Hecho cuando:** Existe `database/supabase_seeds.sql` con sentencias de inserción mediante cláusula estándar `ON CONFLICT DO UPDATE` que cargan las 10 sedes iniciales, las 13 máquinas maestras, el personal interno con contraseñas seguras hasheadas, y el catálogo maestro de repuestos con compatibilidades por modelo, sin insertar ninguna incidencia activa ni expediente histórico previo (*Fresh Start* estricto).

- [ ] **T-HYBRID-04: Implementar programación `pg_cron` / `pg_net` para invocación canónica del barrido sanitario en `database/supabase_init.sql`**
  * **Requisitos:** RF-06.1, RF-06.2, Constitución Art. II, Art. I.3
  * **Dependencias:** T-HYBRID-02
  * **Hecho cuando:** `database/supabase_init.sql` programa la tarea en `pg_cron` mediante `cron.schedule('sanitary-biological-clock-sweep', '*/15 * * * *', ...)` que invoca vía `pg_net.http_post` el endpoint interno autenticado `POST /api/cron/sanitary-clock-sweep`, garantizando que la lógica de cálculo reside exclusivamente en `SanitaryClockSweepService.php` sin duplicación en procedimientos almacenados SQL (Art. I.3).

- [ ] **T-HYBRID-05: Implementar flujo desatendido de latido preventivo y auto-restauración `.github/workflows/supabase-keepalive.yml`**
  * **Requisitos:** RF-03.1, RF-03.2, RF-03.3, RNF-02
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe `.github/workflows/supabase-keepalive.yml` configurado con programación cron `'0 6 * * *'` (diario a las 06:00 UTC) y disparo manual `workflow_dispatch`, el cual realiza una petición HTTP ligera con `curl` hacia `GET /api/health` para registrar actividad en la base de datos, y en caso de detectar suspensión (HTTP 540) invoca la API de gestión de Supabase (`POST /v1/projects/{ref}/restore`) utilizando `SUPABASE_ACCESS_TOKEN`.

---

## Fase 2: Infraestructura de Conexión, Normalización SQL ANSI, Presupuesto de Espera y Docker

- [ ] **T-HYBRID-06: Refactorizar `ConnectionFactory.php` con autodetección de driver `pdo_pgsql` vs `pdo_mysql`, Supavisor y SSL**
  * **Requisitos:** RF-01.2, RF-01.4, RF-09.1, RNF-01, RNF-05
  * **Dependencias:** T-HYBRID-01
  * **Hecho cuando:** `src/Infrastructure/Database/ConnectionFactory.php` detecta esquemas `postgres://` o `pgsql:` en `DATABASE_URL`, se conecta mediante `pdo_pgsql` al puerto 6543 (Supavisor) con `sslmode=require`, `ATTR_TIMEOUT => 5`, `ATTR_EMULATE_PREPARES => true` (obligatorio para evitar errores de sentencias preparadas en el pooler), y mantiene el funcionamiento transparente de `pdo_mysql` ante URLs locales de MariaDB.

- [ ] **T-HYBRID-07: Adaptar `PdoLockWaitBudget.php` con soporte dual para PostgreSQL (`lock_timeout`) y MySQL (`innodb_lock_wait_timeout`)**
  * **Requisitos:** RF-01.1, RF-09.3, RNF-01
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** `src/Infrastructure/Database/PdoLockWaitBudget.php` detecta el driver activo: si es `pgsql`, gestiona la cota mediante `current_setting('lock_timeout')` y `SET LOCAL lock_timeout = ...` dentro de la transacción activa; si es `mysql`, preserva la lógica de `@@SESSION.innodb_lock_wait_timeout`.

- [ ] **T-HYBRID-08: Implementar `FileStorageCompensator.php` para transaccionalidad compensatoria de archivos**
  * **Requisitos:** RF-05.2, RF-05.3, RF-05.4, RNF-03
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe `src/Infrastructure/Storage/FileStorageCompensator.php` con el método `executeWithCompensatingFile(string $filePath, callable $dbOperation): mixed` que ejecuta la operación de base de datos dentro de un bloque `try/catch`, y en caso de excepción o fallo de red elimina físicamente el archivo en disco con `unlink()`, evitando fugas de archivos huérfanos.

- [ ] **T-HYBRID-09: Normalizar dialecto SQL ANSI en repositorios PDO (Grupo 1: Incidencias, Máquinas, Sedes, Usuarios)**
  * **Requisitos:** RF-01.1, RF-08.1, RF-08.2, RF-08.3, RF-08.5, Constitución Art. III.1, Art. V.1, Art. V.2
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** `PdoIncidentRepository.php`, `PdoMachineRepository.php`, `PdoLocationRepository.php` y `PdoUserRepository.php` eliminan backticks (\`), normalizan cálculos de fecha de MySQL (`DATE_SUB(NOW(), INTERVAL ...)` $\rightarrow$ `(CURRENT_TIMESTAMP - INTERVAL ...)`), adaptan columnas `is_active` con `PDO::PARAM_BOOL` y ejecutan sin fallos en ambos motores relacionales.

- [ ] **T-HYBRID-10: Normalizar dialecto SQL ANSI en repositorios PDO (Grupo 2: Reintegros, Repuestos, Piezas Sustituidas, Métricas)**
  * **Requisitos:** RF-01.1, RF-08.1, Constitución Art. III.1
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** `PdoRefundRequestRepository.php`, `PdoSparePartRepository.php`, `PdoSparePartRequestRepository.php`, `PdoIncidentReplacedPartRepository.php` y `PdoMetricsRepository.php` sustituyen funciones privativas de MySQL (`TIMESTAMPDIFF`, `ON DUPLICATE KEY UPDATE`) por sintaxis ANSI portable compatible con PostgreSQL y MariaDB.

- [ ] **T-HYBRID-11: Normalizar dialecto SQL ANSI en repositorios PDO (Grupo 3: Mantenimiento Preventivo, Rutas, Sanitario, Auditoría)**
  * **Requisitos:** RF-01.1, RF-06.3, RF-08.1, Constitución Art. II, Art. III.1
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** `PdoPreventiveSettingsRepository.php`, `PdoPreventiveOrderRepository.php`, `PdoPreventiveItemRepository.php`, `PdoRouteSettingsRepository.php`, `PdoSanitaryCertificateRepository.php`, `PdoSanitarySweepVisibilityRepository.php`, `PdoAuditLogRepository.php`, `PdoSiteAccessCodeRepository.php` y `PdoUnclaimedCashFindingRepository.php` completan la eliminación de backticks y funciones privativas, completando la normalización de los 18 repositorios del proyecto.

- [ ] **T-HYBRID-12: Adaptar `Dockerfile`, `bin/init_cloud_db.php`, `SeedRunner.php` y `TestDataCleaner.php`**
  * **Requisitos:** RF-01, RF-02, RF-08.1, Constitución Art. III.1
  * **Dependencias:** T-HYBRID-02, T-HYBRID-03, T-HYBRID-06
  * **Hecho cuando:** (1) `Dockerfile` instala `postgresql-dev` y compila la extensión `pdo_pgsql`; (2) `bin/init_cloud_db.php` autodetermina el driver y ejecuta el script correspondiente (`supabase_init.sql` si es PostgreSQL); (3) `SeedRunner.php` soporta sentencias ANSI `ON CONFLICT`; y (4) `tests/Support/TestDataCleaner.php` declara `set_config('vendguard.purge', 'on', true)` en transacciones PostgreSQL para permitir la purga legítima de pruebas sin tropezar con el trigger de Art. III.1.

---

## Fase 3: Servicios de Aplicación, Health Check y Controladores REST

- [ ] **T-HYBRID-13: Implementar DTO inmutable `HealthCheckResponseDto.php` y servicio de aplicación `HealthCheckService.php`**
  * **Requisitos:** RF-07.1, RF-07.2, RF-07.3, RNF-01, Constitución Art. V.4, Art. VI.1
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** Existen `src/Application/DTO/HealthCheckResponseDto.php` (clase `final readonly` con proyección pública segura `status` y `timestamp_utc`, y telemetría extendida para llamadas autenticadas) y `src/Application/Service/HealthCheckService.php` que evalúa la conexión activa con `SELECT 1` cronometrando la latencia, sin exponer secretos ni contraseñas.

- [ ] **T-HYBRID-14: Implementar `HealthController.php` y registrar ruta `GET /api/health` en `AppRouter.php`**
  * **Requisitos:** RF-07.1, RF-07.2, RF-07.3, Constitución Art. V.4, Art. VI.1
  * **Dependencias:** T-HYBRID-13
  * **Hecho cuando:** Existe `src/Presentation/Controller/HealthController.php` con el método `check(Request $request): Response`, `src/Presentation/Routing/AppRouter.php` registra la ruta pública `GET /api/health`, emitiendo código HTTP 200 OK cuando la base de datos responde y 503 Service Unavailable cuando falla, filtrando la telemetría interna si la petición no incluye `X-Internal-Token` o perfil de Coordinador.

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

- [ ] **T-HYBRID-19: Implementar pruebas unitarias PHP `ConnectionFactoryTest.php`, `HealthCheckServiceTest.php`, `PdoLockWaitBudgetTest.php` y `SqlDialectPortabilityTest.php`**
  * **Requisitos:** RF-01, RF-07, RF-09.3, RNF-01, RNF-04, RNF-05
  * **Dependencias:** T-HYBRID-06, T-HYBRID-07, T-HYBRID-11, T-HYBRID-13
  * **Hecho cuando:** La ejecución de `php tests/unit/ConnectionFactoryTest.php`, `php tests/unit/HealthCheckServiceTest.php`, `php tests/unit/PdoLockWaitBudgetTest.php` y `php tests/unit/SqlDialectPortabilityTest.php` pasa al 100% en verde, validando la instanciación de drivers, emulación de prepares, presupuestos de espera, ofuscación de credenciales en health check y compatibilidad ANSI en los 18 repositorios PDO.

- [ ] **T-HYBRID-20: Implementar pruebas unitarias frontend ESM en Node.js `ConnectionStateBannerTest.mjs` y `ApiClientTimeoutTest.mjs`**
  * **Requisitos:** RF-04, RNF-06
  * **Dependencias:** T-HYBRID-16, T-HYBRID-17
  * **Hecho cuando:** La ejecución de `node tests/unit/ConnectionStateBannerTest.mjs` y `node tests/unit/ApiClientTimeoutTest.mjs` pasa al 100% en verde sin dependencias npm, evaluando el renderizado de los estados del banner, la altura del botón táctil $\ge 44\text{ px}$ y el corte exacto de timeout a los 5,0s con preservación del borrador reactivo.

- [ ] **T-HYBRID-21: Implementar pruebas de integración HTTP y blindaje constitucional `HealthEndpointTest.php`, `PostgresParityTest.php` y `PostgresConstitutionalTest.php`**
  * **Requisitos:** RF-01, RF-02, RF-07, RF-08.1, RF-08.2, RF-08.3, RF-08.4, RF-08.5, RF-10, Constitución Art. II, Art. III.1, Art. V.1, Art. V.2, Art. V.4, Art. V.6
  * **Dependencias:** T-HYBRID-14, T-HYBRID-19
  * **Hecho cuando:** `php tests/integration/HealthEndpointTest.php`, `php tests/integration/PostgresParityTest.php` y `php tests/integration/PostgresConstitutionalTest.php` pasan al 100% en verde certificando: (1) respuestas 200 OK y 503 en el router; (2) operaciones CRUD sobre las 20 tablas de PostgreSQL; (3) rechazo ruidoso con `SQLSTATE '45000'` ante sentencias `DELETE FROM` (Art. III.1) respetando la purga de `TestDataCleaner`; (4) rechazo atómico de tickets duplicados con `RESOLVED` (Art. V.2/V.6); (5) rechazo por base de datos de justificaciones $< 20$ caracteres (Art. V.1); y (6) insensibilidad de mayúsculas en emails y sedes mediante `CITEXT`.

- [ ] **T-HYBRID-22: Actualizar cifras documentadas en `README.md` (`DocMetricsGuard.php`) y certificar paridad global (`php tests/run_all.php`)**
  * **Requisitos:** RF-01, RNF-04, Constitución Art. I.1, Art. I.2, Art. I.3
  * **Dependencias:** T-HYBRID-19, T-HYBRID-20, T-HYBRID-21
  * **Hecho cuando:** Se actualizan las cifras documentadas de la batería en `README.md` para satisfacer [`DocMetricsGuard.php`](../../tests/Support/DocMetricsGuard.php) y la ejecución completa de `php tests/run_all.php` finaliza al **100% en verde (0 errores, 0 fallos)** ejecutando la totalidad de las suites existentes y nuevas sin regresiones.
