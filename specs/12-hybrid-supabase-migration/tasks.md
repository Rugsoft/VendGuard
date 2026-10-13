# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO 12: MIGRACIÓN DE PERSISTENCIA A BASE DE DATOS EN LA NUBE (ENFOQUE HÍBRIDO) (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `12-hybrid-supabase-migration`  
**Documento:** `specs/12-hybrid-supabase-migration/tasks.md`  
**Referencia Funcional:** [`specs/12-hybrid-supabase-migration/spec.md`](spec.md) (y [`specs/functional/hybrid_supabase_migration_spec.md`](../functional/hybrid_supabase_migration_spec.md))  
**Plan Técnico:** [`specs/12-hybrid-supabase-migration/plan.md`](plan.md)  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  
**Sistema de Diseño Visual:** [`docs/design.md`](../../docs/design.md) (Azul corporativo `#2560ff`, ámbar técnico `#fef9c3` con texto `#854d0e`, radio 4px/8px, canvas `#f9fafb`)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Base de Datos PostgreSQL, DDL Canónico y Flujo de Latido Keep-Alive

- [ ] **T-HYBRID-01: Crear DDL canónico de PostgreSQL `database/supabase_init.sql` (Parte 1: Tablas 1 a 10 con enums y blindajes)**
  * **Requisitos:** RF-01.1, RF-02.1, RF-08.1, RF-08.2, RF-08.3, RF-09.1, Constitución Art. III.1, Art. V.1, Art. V.2, Art. V.6
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe `database/supabase_init.sql` conteniendo: extensiones `uuid-ossp`, `pgcrypto`, `pg_cron`; los 6 tipos `ENUM` del dominio; la definición de las tablas `locations`, `machines`, `users`, `incidents`, `incident_history`, `incident_comments`, `audit_log`, `route_settings`, `preventive_settings` y `preventive_orders` con columnas `BIGINT GENERATED ALWAYS AS IDENTITY`, tipos `TIMESTAMPTZ`, restricciones `CHECK` de justificación $\ge 20$ caracteres (Art. V.1), el índice único parcial `uq_machine_active_ticket ON incidents (machine_id) WHERE status NOT IN ('CLOSED', 'CANCELLED')` (Art. V.2 y V.6), y reglas `ON DELETE DO INSTEAD NOTHING` en todas ellas (Art. III.1).

- [ ] **T-HYBRID-02: Completar DDL canónico de PostgreSQL `database/supabase_init.sql` (Parte 2: Tablas 11 a 20 con anti-delete)**
  * **Requisitos:** RF-01.1, RF-02.1, RF-08.1, Constitución Art. III.1
  * **Dependencias:** T-HYBRID-01
  * **Hecho cuando:** El archivo `database/supabase_init.sql` incluye las definiciones completas con tipos nativos y reglas anti-delete `ON DELETE DO INSTEAD NOTHING` para: `preventive_order_items`, `sanitary_certificates`, `spare_parts`, `spare_part_compatibilities`, `spare_part_requests`, `incident_replaced_parts`, `refund_requests`, `unclaimed_cash_findings`, `sanitary_sweep_passes` y `sanitary_sweep_contentions`, verificando que el script SQL completo compila y crea las 20 tablas limpiamente en una instancia de PostgreSQL 15+.

- [ ] **T-HYBRID-03: Crear script de semillas maestras para arranque en limpio `database/supabase_seeds.sql`**
  * **Requisitos:** RF-02.1, RF-02.2, RF-02.3, Constitución Art. V.4
  * **Dependencias:** T-HYBRID-02
  * **Hecho cuando:** Existe `database/supabase_seeds.sql` con sentencias de inserción mediante cláusula estándar `ON CONFLICT DO UPDATE` que cargan las 10 sedes iniciales, las 13 máquinas maestras, el personal interno con contraseñas seguras hasheadas, y el catálogo maestro de repuestos con compatibilidades por modelo, sin insertar ninguna incidencia activa ni expediente histórico previo (*Fresh Start* estricto).

- [ ] **T-HYBRID-04: Implementar procedimiento de barrido sanitario y programación `pg_cron` en `database/supabase_init.sql`**
  * **Requisitos:** RF-06.1, RF-06.2, Constitución Art. II, Art. I.3
  * **Dependencias:** T-HYBRID-02
  * **Hecho cuando:** `database/supabase_init.sql` define el procedimiento almacenado `perform_sanitary_clock_sweep()` y la llamada `cron.schedule('sanitary-biological-clock-sweep', '*/15 * * * *', ...)` que localiza máquinas de alimentos perecederos con incidencias activas $\ge 4$ horas (14.400 segundos naturales), actualiza de forma atómica su estado a `QUARANTINE` en `preventive_settings` y asienta el evento inmutable en `incident_history` y `sanitary_sweep_passes`.

- [ ] **T-HYBRID-05: Implementar flujo desatendido de latido preventivo `.github/workflows/supabase-keepalive.yml`**
  * **Requisitos:** RF-03.1, RF-03.2, RF-03.3, RNF-02
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe `.github/workflows/supabase-keepalive.yml` configurado con programación cron `'0 6 * * *'` (diario a las 06:00 UTC) y disparo manual `workflow_dispatch`, el cual realiza una petición HTTP ligera con `curl` hacia el endpoint de salud `GET /api/health`, registrando en el log el estado devuelto y evitando la suspensión por 7 días de inactividad del plan gratuito.

---

## Fase 2: Infraestructura de Conexión, Normalización SQL ANSI y Transaccionalidad Compensatoria

- [ ] **T-HYBRID-06: Refactorizar `ConnectionFactory.php` con autodetección de driver `pdo_pgsql` vs `pdo_mysql`, Supavisor y SSL**
  * **Requisitos:** RF-01.2, RF-01.4, RF-09.1, RNF-01, RNF-05
  * **Dependencias:** T-HYBRID-01
  * **Hecho cuando:** `src/Infrastructure/Database/ConnectionFactory.php` detecta esquemas `postgres://` o `pgsql:` en `DATABASE_URL`, se conecta mediante `pdo_pgsql` al puerto 6543 (Supavisor) con `sslmode=require`, `ATTR_TIMEOUT => 5`, `ATTR_EMULATE_PREPARES => false`, ejecuta `SET timezone TO 'UTC'`, y mantiene el funcionamiento transparente de `pdo_mysql` ante URLs locales de MariaDB.

- [ ] **T-HYBRID-07: Implementar `FileStorageCompensator.php` para transaccionalidad compensatoria de archivos**
  * **Requisitos:** RF-05.2, RF-05.3, RF-05.4, RNF-03
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe `src/Infrastructure/Storage/FileStorageCompensator.php` con el método `executeWithCompensatingFile(string $filePath, callable $dbOperation): mixed` que ejecuta la operación de base de datos dentro de un bloque `try/catch`, y en caso de excepción o fallo de red elimina físicamente el archivo recién subido en disco con `unlink()`, evitando fugas de archivos huérfanos.

- [ ] **T-HYBRID-08: Normalizar dialecto SQL ANSI en `PdoIncidentRepository.php` (eliminación de backticks y fechas ANSI)**
  * **Requisitos:** RF-01.1, RF-08.1, RF-08.2, RF-08.3, RF-09.2, Constitución Art. III.1, Art. V.1, Art. V.2
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** Todas las consultas SQL de `src/Infrastructure/Repository/PdoIncidentRepository.php` eliminan los backticks (\`) de identificadores de tablas y columnas sustituyéndolos por sintaxis ANSI estándar compatible con PostgreSQL y MariaDB, y las funciones de fecha privativas se normalizan a `CURRENT_TIMESTAMP` e `INTERVAL`.

- [ ] **T-HYBRID-09: Normalizar dialecto SQL ANSI en `PdoMachineRepository.php`, `PdoLocationRepository.php` y `PdoUserRepository.php`**
  * **Requisitos:** RF-01.1, RF-02.2, RF-08.1, Constitución Art. III.1
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** Los repositorios `PdoMachineRepository.php`, `PdoLocationRepository.php` y `PdoUserRepository.php` eliminan los backticks (\`) en sus sentencias `SELECT`, `INSERT` y `UPDATE`, adaptan las columnas booleanas `is_active` para vincular con `PDO::PARAM_BOOL` y se ejecutan sin errores de sintaxis en ambos motores relacionales.

- [ ] **T-HYBRID-10: Normalizar dialecto SQL ANSI en `PdoRefundRepository.php` y `PdoSparePartRepository.php`**
  * **Requisitos:** RF-01.1, RF-08.1, Constitución Art. III.1
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** `PdoRefundRepository.php` y `PdoSparePartRepository.php` sustituyen la sintaxis privativa de MySQL por SQL ANSI portable, eliminando backticks y garantizando que los cálculos monetarios de `NUMERIC(10, 2)` y snapshots de coste operen limpiamente en PostgreSQL y MariaDB.

- [ ] **T-HYBRID-11: Normalizar dialecto SQL ANSI en `PdoPreventiveSettingsRepository.php`, `PdoRouteSettingsRepository.php` y `PdoSanitarySweepVisibilityRepository.php`**
  * **Requisitos:** RF-01.1, RF-06.3, RF-08.1, Constitución Art. II, Art. III.1
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** Los repositorios de mantenimiento preventivo, rutas y visibilidad de barrido sanitario eliminan los backticks (\`) y adaptan las consultas de intervalos temporales a `INTERVAL '4 hours'`, garantizando que las lecturas y actualizaciones atómicas de `sanitary_status` funcionen de forma idéntica en PostgreSQL y MariaDB.

---

## Fase 3: Servicios de Aplicación, Health Check y Controladores REST

- [ ] **T-HYBRID-12: Implementar DTO inmutable `HealthCheckResponseDto.php` y servicio de aplicación `HealthCheckService.php`**
  * **Requisitos:** RF-07.1, RF-07.2, RF-07.3, RNF-01, Constitución Art. VI.1
  * **Dependencias:** T-HYBRID-06
  * **Hecho cuando:** Existen `src/Application/DTO/HealthCheckResponseDto.php` (clase `final readonly` con campos `status`, `database_driver`, `connection_pooler`, `response_time_ms`, `timestamp_utc`) y `src/Application/Service/HealthCheckService.php` que ejecuta `SELECT 1` cronometrando la latencia, devolviendo el estado `HEALTHY` o `UNHEALTHY` sin exponer contraseñas ni datos sensibles de conexión.

- [ ] **T-HYBRID-13: Implementar `HealthController.php` y registrar ruta `GET /api/health` en `AppRouter.php`**
  * **Requisitos:** RF-07.1, RF-07.2, RF-07.3, Constitución Art. VI.1
  * **Dependencias:** T-HYBRID-12
  * **Hecho cuando:** Existe `src/Presentation/Controller/HealthController.php` con el método `check(Request $request): Response`, `src/Presentation/Routing/AppRouter.php` registra la ruta pública `GET /api/health`, emitiendo código HTTP 200 OK cuando la base de datos responde y 503 Service Unavailable cuando falla, con cabecera `Cache-Control: no-cache`.

- [ ] **T-HYBRID-14: Adaptar `TechnicianController.php` con salvaguarda del informe técnico ante cuarentena biológica sobrevenida**
  * **Requisitos:** RF-10.1, RF-10.2, Constitución Art. II, Art. V.1
  * **Dependencias:** T-HYBRID-08
  * **Hecho cuando:** En `TechnicianController::resolveIncident()`, si la máquina entró en `QUARANTINE` a las 4h en la central mientras el técnico trabajaba sin cobertura, el sistema almacena el diagnóstico y repuestos en borrador seguro, suspende el cierre y devuelve un código HTTP 422 con código `SANITARY_QUARANTINE_OVERTAKEN`, exigiendo el checklist de higienización sin borrar el informe redactado.

---

## Fase 4: Frontend Vanilla Vue.js 3 ESM

- [ ] **T-HYBRID-15: Implementar componente global flotante reactivo `ConnectionStateBanner.js`**
  * **Requisitos:** RF-04.3, RF-04.4, RNF-06
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe `public/assets/js/components/ConnectionStateBanner.js` como módulo ES nativo de Vue 3 que renderiza un banner flotante fijo superior (`z-index: 9999`) con estados `idle` (oculto), `cold_start` (aviso azul informativo de 25s), `disconnected` (aviso ámbar accesible con botón táctil $\ge 44\text{ px}$ *"Reintentar"*) y `reconnected` (verde por 3 segundos), escuchando eventos `vendguard:network-status`.

- [ ] **T-HYBRID-16: Adaptar `public/assets/js/api.js` con ventana Cold Start (25s), timeout ordinario (5s), CustomEvents y retención de formularios**
  * **Requisitos:** RF-04.1, RF-04.2, RF-04.5, RNF-01
  * **Dependencias:** T-HYBRID-15
  * **Hecho cuando:** `public/assets/js/api.js`: (1) aplica `AbortSignal.timeout(25000)` ante sospecha de Cold Start emitiendo el evento correspondiente; (2) aplica `AbortSignal.timeout(5000)` en operaciones activas ordinarias; (3) desrumbe botones de envío durante peticiones en curso; y (4) ante fallos de red o timeout emite `vendguard:network-status` con el callback de reintento, preservando los datos reactivos del formulario sin recargar la página.

- [ ] **T-HYBRID-17: Integrar `ConnectionStateBanner.js` en `public/assets/js/app.js` y `public/index.html`**
  * **Requisitos:** RF-04.3, RF-04.4, RNF-06
  * **Dependencias:** T-HYBRID-15, T-HYBRID-16
  * **Hecho cuando:** `public/assets/js/app.js` importa y monta el componente `ConnectionStateBanner.js` en la raíz de la aplicación Vue 3, y el contenedor es visible en todas las vistas (Coordinador, Técnico, Sede y Portal QR) sin interferir con la navegación ordinaria cuando la conexión está activa.

---

## Fase 5: Estrategia de Pruebas Unitarias, Integración, Blindaje Constitucional y Certificación Global

- [ ] **T-HYBRID-18: Implementar pruebas unitarias PHP `ConnectionFactoryTest.php`, `HealthCheckServiceTest.php` y `SqlDialectPortabilityTest.php`**
  * **Requisitos:** RF-01, RF-07, RNF-01, RNF-04, RNF-05
  * **Dependencias:** T-HYBRID-06, T-HYBRID-08, T-HYBRID-12
  * **Hecho cuando:** La ejecución de `php tests/unit/ConnectionFactoryTest.php`, `php tests/unit/HealthCheckServiceTest.php` y `php tests/unit/SqlDialectPortabilityTest.php` pasa al 100% en verde, validando la instanciación de drivers, la ofuscación de credenciales en el health check y la ausencia total de backticks (\`) en todos los repositorios PDO.

- [ ] **T-HYBRID-19: Implementar pruebas unitarias frontend ESM en Node.js `ConnectionStateBannerTest.mjs` y `ApiClientTimeoutTest.mjs`**
  * **Requisitos:** RF-04, RNF-06
  * **Dependencias:** T-HYBRID-15, T-HYBRID-16
  * **Hecho cuando:** La ejecución de `node tests/unit/ConnectionStateBannerTest.mjs` y `node tests/unit/ApiClientTimeoutTest.mjs` pasa al 100% en verde sin dependencias npm, evaluando el renderizado de los 4 estados del banner, la altura del botón táctil $\ge 44\text{ px}$ y el corte exacto de timeouts a los 25s y 5s con preservación del borrador.

- [ ] **T-HYBRID-20: Implementar pruebas de integración HTTP `HealthEndpointTest.php` y `PostgresParityTest.php`**
  * **Requisitos:** RF-01, RF-02, RF-07, RNF-01
  * **Dependencias:** T-HYBRID-13, T-HYBRID-18
  * **Hecho cuando:** La ejecución de `php tests/integration/HealthEndpointTest.php` y `php tests/integration/PostgresParityTest.php` pasa al 100% en verde, verificando respuestas 200 OK y 503 en el router, y validando operaciones CRUD reales sobre las 20 tablas de PostgreSQL con tipos `TIMESTAMPTZ` y secuencias de identidad.

- [ ] **T-HYBRID-21: Implementar pruebas de blindaje constitucional en PostgreSQL `PostgresConstitutionalTest.php`**
  * **Requisitos:** RF-08.1, RF-08.2, RF-08.3, RF-08.4, RF-10, Constitución Art. II, Art. III.1, Art. V.1, Art. V.2, Art. V.4, Art. V.6
  * **Dependencias:** T-HYBRID-20
  * **Hecho cuando:** `php tests/integration/PostgresConstitutionalTest.php` pasa al 100% en verde certificando: (1) cuarentena automática a las 4h en perecederos (Art. II); (2) rechazo de sentencias `DELETE FROM` con cero filas borradas en las 20 tablas (Art. III.1); (3) rechazo por base de datos de justificaciones con $< 20$ caracteres (Art. V.1); (4) rechazo atómico de tickets duplicados en máquinas activas o en `RESOLVED` (Art. V.2 y V.6); y (5) respuesta neutral en QR ciudadano (Art. V.4).

- [ ] **T-HYBRID-22: Certificación de paridad total con la suite global del proyecto (`php tests/run_all.php`)**
  * **Requisitos:** RF-01, RNF-04, Constitución Art. I.1, Art. I.2, Art. I.3
  * **Dependencias:** T-HYBRID-18, T-HYBRID-19, T-HYBRID-20, T-HYBRID-21
  * **Hecho cuando:** La ejecución completa de `php tests/run_all.php` finaliza al **100% en verde (0 errores, 0 fallos)** ejecutando las 249 suites existentes del proyecto sin regresiones, certificando que el sistema es completamente agnóstico de la persistencia y opera idénticamente en local y en la nube.
