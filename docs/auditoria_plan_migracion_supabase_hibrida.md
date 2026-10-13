# AUDITORÍA CRÍTICA DEL PLAN DE MIGRACIÓN HÍBRIDA A SUPABASE (MÓDULO 12)

**Documento:** `docs/auditoria_plan_migracion_supabase_hibrida.md`  
**Fecha:** 13 de octubre de 2026 · **revisión 2** (segunda pasada sobre la versión reparada)  
**Objeto auditado:** [`specs/12-hybrid-supabase-migration/plan.md`](../specs/12-hybrid-supabase-migration/plan.md), [`spec.md`](../specs/12-hybrid-supabase-migration/spec.md), [`tasks.md`](../specs/12-hybrid-supabase-migration/tasks.md) y [`docs/migracion_supabase_hibrido.md`](migracion_supabase_hibrido.md)  
**Rama:** `supabase-migration` · primera pasada sobre `7ecd3bb`; revisión 2 sobre `70cbb17`  
**Naturaleza:** Auditoría adversarial de solo lectura. **No se ha modificado ninguna línea de `src/`, `tests/`, `database/` ni de las especificaciones.**  
**Normativa Suprema:** [`constitution.md`](../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  
**Veredicto de la primera pasada:** **NO iniciar la Fase 1 (T-HYBRID-01)**. El plan es formalmente impecable y falla en dos premisas centrales, configura mal el pooler elegido e introduce una bomba silenciosa en el arnés de pruebas.

**Veredicto vigente (revisión 2):** la reparación fue real y cerró siete de los once hallazgos, pero trasladó el problema en dos sitios y **reintrodujo un fallo de despliegue que este repositorio ya pagó en producción** (N-12). El estado por hallazgo, los hallazgos nuevos y las decisiones que siguen pendientes están en la **§8**.

---

## 1. Resumen Ejecutivo

El plan del módulo 12 está escrito con un rigor documental notable: mapea requisitos, cita artículos constitucionales, define contratos JSON, incluye pseudocódigo y una estrategia de pruebas de nueve suites nuevas. El problema no es de forma, es de **verdad verificable**: tres de sus afirmaciones estructurales no se sostienen contra el motor real, la documentación del proveedor o el propio código del repositorio.

*(El plan fue reparado después de esta primera pasada: el commit `70cbb17` aplicó las correcciones de las secciones 1 a 6. El estado de cada hallazgo tras esa reparación, junto con los hallazgos nuevos que la propia reparación introdujo, se documenta en la **§8 Revisión 2**, que es la parte vigente de este informe.)*

| # | Hallazgo | Severidad | Requisito que se cae con él |
| :--- | :--- | :--- | :--- |
| 1 | El «Cold Start de 25 s» es físicamente imposible: un proyecto pausado no despierta con una consulta | **Crítica** | RF-04 (EARS-04.1), RF-03 (EARS-03.1/03.3), spec §6.1, §8.6 |
| 2 | El plan viola el requisito que él mismo redacta: duplica el barrido sanitario en un procedimiento almacenado | **Crítica** | EARS-06.1, Art. I.3, Art. II |
| 3 | El anti-borrado ciego en 20 tablas rompe el arnés y degrada un guardián ya probado a un no-op silencioso | **Crítica** | RF-08.1, Art. III.1, criterio §8.1 |
| 4 | Las dos palancas de configuración del pooler transaccional son las que Supabase desaconseja | **Alta** | RF-01, RF-09, RNF-01, RNF-05 |
| 5 | El presupuesto de espera de bloqueo y el bloqueo por fila no tienen ruta de migración | **Alta** | T-PAUSE-40/41, RNF-01 |
| 6 | El alcance del dialecto (5 repositorios) es una quinta parte de la realidad medida | **Alta** | RF-01.1, RF-08, T-HYBRID-08..11 |
| 7 | El cotejamiento `utf8mb4_unicode_ci` no se replica: cambia en silencio la unicidad de email y sede | **Alta** | Art. V.2, Art. V.6, Art. V.4 |
| 8 | El plan gratuito no aporta lo que el plan promete: sin backups, sin PITR, sin SLA | **Alta** | spec §1.1, RNF-02, RNF-03 |
| 9 | El despliegue real no arrancaría: imagen sin `pdo_pgsql` y bootstrap MySQL en el `CMD` | **Alta** | RF-01, criterio §8.1 |
| 10 | La certificación exigida se rompe con el propio plan (guarda de cifras documentadas) | **Media** | T-HYBRID-22, criterio §8.1 |
| 11 | Defectos menores: endpoint público de salud, cifras sin fuente, timeout cliente/servidor incoherente | **Baja** | RF-07, RNF-01, Art. V.4 |

---

## 2. Método y Evidencia

**Lectura completa (primera pasada):** `plan.md` (444 líneas), `spec.md` (223), `tasks.md` (142), `docs/migracion_supabase_hibrido.md` (696). **Segunda pasada:** los mismos tres documentos ya reparados (`plan.md` 369 líneas, `spec.md` 226, `tasks.md` 143) más `src/Presentation/Routing/AppRouter.php`, `src/Presentation/Http/Middleware/CronAuthMiddleware.php`, `src/Infrastructure/Config/SecretProvider.php`, `tests/integration/CloudDeploySchemaParityTest.php`, `tests/diagnostics/PendingInfoSmokeWorkflowDiagnostic.php` y los métodos de purga de `TestDataCleaner`. Artefactos que el plan afirma tocar: `Dockerfile`, `src/Infrastructure/Database/ConnectionFactory.php`, `PdoLockWaitBudget.php`, `PdoTransactionManager.php`, `tests/Support/DocMetricsGuard.php`, `tests/Support/TestDataCleaner.php`, `database/schema.sql`, `database/cloud_init.sql`, `database/migrations/010_refund_hard_delete_guard.sql`, `specs/technical/testing_cleanup_contract.md`.

**Sondeos de código:** inventario de sentencias `DELETE FROM`/`TRUNCATE`/`DROP TABLE`, de backticks, de funciones privativas de MySQL (`TIMESTAMPDIFF`, `DATE_ADD`, `DATE_SUB`, `IFNULL`, `GROUP_CONCAT`, `VALUES()`, `@@SESSION`, `innodb_lock_wait_timeout`, `FOR UPDATE`) y del número real de repositorios y tablas.

**Investigación externa con fuentes:** comportamiento de pausa/restauración y límites del plan gratuito de Supabase; semántica del pooler Supavisor en modo transacción (prepared statements, estado de sesión, advisory locks) y soporte de `PDO::ATTR_TIMEOUT` en `pdo_pgsql`; disponibilidad de `pg_cron` y `pg_net`. Las URLs consultadas constan en el Anexo B.

**Sesión de interrogatorio:** conducida con la disciplina de la skill `grill-me` (invocable solo por el usuario), aplicada como auditoría adversarial en lugar de como entrevista.

---

## 3. Hallazgos

### H-1 · El «Cold Start de 25 s» es físicamente imposible

**Lo que afirma el plan:** la base de datos se «duerme» y despierta en 15–20 s; el cliente debe esperar una ventana de gracia de 25 s y «completa la petición con normalidad en cuanto el motor responde» (RF-04.1, EARS-04.1, spec §6.1, criterio §8.6, estado `cold_start` de `ConnectionStateBanner.js`).

**Lo que ocurre de verdad:** Supabase pausa un proyecto Free tras aproximadamente una semana de actividad baja y, mientras está pausado, devuelve **HTTP 540** con el mensaje de que el proyecto «cannot process requests until it is un-paused by the owner». **Ninguna consulta lo despierta.** Las dos vías documentadas de vuelta son la acción manual en el dashboard o la llamada autenticada `POST /v1/projects/{ref}/restore` (scope `projects:write`).

**Consecuencias:**

1. La ventana de 25 s no es un mecanismo: esperar 25 s, 5 s o cinco horas produce idéntico resultado. EARS-04.1 es inimplementable tal como está redactado.
2. El latido diario (RF-03) deja de ser una comodidad y pasa a ser **toda** la historia de disponibilidad del sistema, y en las 22 tareas del plan **no existe ni un solo paso de auto-restauración**.
3. La excepción de fallo (EARS-03.3) se resuelve con «emitir una alerta en el registro de diagnóstico»: un log que nadie lee no es una notificación.
4. El latido es un `curl` de GitHub Actions contra el endpoint de la propia aplicación PHP, alojada en un PaaS gratuito que también se suspende por inactividad. El plan no menciona en ninguna parte el sueño del contenedor de aplicación.
5. GitHub desactiva los *workflows* programados tras periodos prolongados de inactividad del repositorio: el único punto de sostenimiento de la disponibilidad es un cron gratuito que se apaga solo si se deja de commitear.

### H-2 · El plan viola el requisito que él mismo redacta (Art. I.3)

**EARS-06.1 exige:** concentrar la lógica del barrido sanitario en el servicio de dominio «como única fuente de verdad (Art. I.3), **prohibiendo divergencias de cálculo entre procedimientos almacenados de base de datos y código de aplicación**».

**T-HYBRID-04 ordena:** implementar el procedimiento almacenado `perform_sanitary_clock_sweep()` y programarlo con `cron.schedule('sanitary-biological-clock-sweep', '*/15 * * * *', ...)`. Es, literalmente, la práctica prohibida por el requisito contiguo.

**Contradicción interna adicional:** el Algoritmo 4 del propio plan declara que la verdad está en `SanitaryClockSweepService.sweep()` y, tres párrafos después, ofrece dos orquestaciones incompatibles entre sí («`SELECT net.http_post(...)` o invoca el runner binario nativo») sin elegir una.

**Impacto real:** el procedimiento en SQL esquivaría la maquinaria completa que hoy sostiene el Art. II — [`SanitaryClockSweepService.php`](../src/Application/Service/SanitaryClockSweepService.php) (456 líneas), [`SanitarySweepEscalationPolicy.php`](../src/Core/Service/SanitarySweepEscalationPolicy.php), [`PdoSanitarySweepVisibilityRepository.php`](../src/Infrastructure/Repository/PdoSanitarySweepVisibilityRepository.php) y las tablas de pasadas y contenciones. Art. II quedaría **certificado sobre el camino MySQL y ejecutado en producción por un camino SQL que ninguna suite prueba**.

### H-3 · El anti-borrado «en las 20 tablas» rompe el arnés y degrada un guardián ya probado

Este es el hallazgo más destructivo, y es silencioso.

**Lo que ya existe y funciona:** [`database/migrations/010_refund_hard_delete_guard.sql`](../database/migrations/010_refund_hard_delete_guard.sql) convirtió el Art. III.1 en algo ejecutable: un trigger `BEFORE DELETE` que **rechaza en voz alta** (`SIGNAL SQLSTATE '45000'` con mensaje constitucional) y cede ante una única señal auditable, `@vendguard_purge`, que solo escribe `TestDataCleaner`. El propio encabezado de la migración documenta el motivo: «the test harness genuinely has to empty these tables between runs».

**Lo que propone el plan:** `ON DELETE DO INSTEAD NOTHING` sobre las 20 tablas, **sin puerta de salida**.

**Consecuencias verificables:**

1. [`TestDataCleaner.php:123`](../tests/Support/TestDataCleaner.php#L123) y numerosas suites de integración ejecutan `DELETE FROM` dirigido para limpiar (entre otras: [`TechnicianRouteMapApiTest.php:62`](../tests/integration/TechnicianRouteMapApiTest.php#L62), [`PdoUserRepositoryTest.php:118`](../tests/integration/PdoUserRepositoryTest.php#L118), [`PdoSparePartRepositoryTest.php:31`](../tests/integration/PdoSparePartRepositoryTest.php#L31), [`PdoLocationRepositoryTest.php:113`](../tests/integration/PdoLocationRepositoryTest.php#L113), [`IncidentPauseServiceTest.php:616`](../tests/unit/IncidentPauseServiceTest.php#L616), [`CoordinatorSanitarySweepEndpointTest.php:544`](../tests/integration/CoordinatorSanitarySweepEndpointTest.php#L544)). Con reglas ciegas dejan de limpiar: errores 1451 y residuo acumulado entre suites.
2. [`SiteManagerRefundDataSegregationTest.php:416`](../tests/integration/SiteManagerRefundDataSegregationTest.php#L416) afirma que un borrado real **es rechazado** con SQLSTATE 45000 y mensaje de Art. III.1, y su §6.4 exige que el guardián **no** muerda sobre un id inexistente. Con un no-op la primera aserción pasa a roja: el criterio §8.1 («batería al completo en verde») es inalcanzable por construcción.
3. Un no-op silencioso es exactamente la ceguera que el proyecto ya rechazó por escrito en [`testing_cleanup_contract.md`](../specs/technical/testing_cleanup_contract.md) §6.1: «un verde sobre datos inconsistentes». El plan **no refuerza** el blindaje: cambia un rechazo ruidoso por un silencio.
4. Las reglas de PostgreSQL no disparan ante `TRUNCATE`, y el escape de purga actual (`@vendguard_purge`) es un variable de sesión de MySQL: en PostgreSQL y a través del pooler transaccional el equivalente (`set_config('vendguard.purge', 'on', true)`) solo es fiable dentro de la transacción.
5. El DDL que escribe el plan no es válido: la forma correcta es `CREATE RULE ... AS ON DELETE TO tabla DO INSTEAD NOTHING`, no «`ON DELETE DO INSTEAD NOTHING`». Que esto haya superado una «auditoría QA ratificada» indica que nadie lo ejecutó.

### H-4 · Las dos palancas de configuración del pooler son las que Supabase desaconseja

| El plan ordena | Lo que exige el modo transacción |
| :--- | :--- |
| `ATTR_EMULATE_PREPARES => false` en el puerto 6543 | El pooler en modo transacción **no soporta prepared statements de servidor**; la recomendación es desactivarlos (es decir, emulación activada). |
| `SET timezone TO 'UTC'` una vez por conexión | El estado de sesión **no sobrevive** entre transacciones y puede **contaminar el backend de otro cliente**. La forma correcta es `SET LOCAL TIME ZONE` dentro de la transacción, o confiar en el UTC por defecto del proyecto. |

Nota agravante: la alternativa moderna de `pdo_pgsql` (`ATTR_DISABLE_PREPARES`) existe desde PHP 8.4, y la imagen está clavada en `php:8.2-cli-alpine`. Todo RF-09 (normalización UTC y conversión a `Europe/Madrid`) cabalga sobre el mecanismo que el pooler elegido rompe.

### H-5 · El presupuesto de espera de bloqueo y el bloqueo por fila no tienen ruta de migración

- [`PdoLockWaitBudget.php:51`](../src/Infrastructure/Database/PdoLockWaitBudget.php#L51) lee `@@SESSION.innodb_lock_wait_timeout` y lo reescribe con `SET SESSION` (:55, :78). Es sintaxis exclusiva de MySQL; el análogo en PostgreSQL es `lock_timeout`, y su ajuste por sesión no es fiable a través del pooler transaccional.
- [`PdoIncidentRepository.php:267`](../src/Infrastructure/Repository/PdoIncidentRepository.php#L267) toma el bloqueo exclusivo de la fila con `SELECT ... FOR UPDATE`.
- Las locks de sesión (`pg_advisory_lock`) son inseguras en modo transacción; la variante correcta es `pg_advisory_xact_lock`.
- **Ninguno de estos artefactos aparece en el árbol de ficheros ni en las 22 tareas del plan:** `PdoLockWaitBudget`, `PdoTransactionManager`, `MigrationRunner`, `SeedRunner`, `bin/init_cloud_db.php`, `bin/migrate.php`, `DemoMetricsSeeder` y `tests/Support/TestDataCleaner.php` están completamente ausentes, pese a que todas contienen dialecto o supuestos de MySQL.

El presupuesto de espera es precisamente lo que hace verdad T-PAUSE-40 (espera acotada medida) y T-PAUSE-41 (contención con escalado): la pieza queda huérfana.

### H-6 · El alcance del dialecto es una quinta parte de la realidad

`tasks.md` normaliza cinco repositorios. El dialecto medido:

| Artefacto | Evidencia | Dialecto |
| :--- | :--- | :--- |
| [`PdoIncidentRepository.php`](../src/Infrastructure/Repository/PdoIncidentRepository.php) | :469, :1916, :2009, :2158, :1232 | `DATE_SUB(NOW(), INTERVAL 48 HOUR)` — **la ventana de garantía de 48 h** — y `CONCAT(IFNULL(...))` |
| [`PdoMetricsRepository.php`](../src/Infrastructure/Repository/PdoMetricsRepository.php) | :49, :234, :237, :263, :318, :396, :460, :522, :538 | `TIMESTAMPDIFF` en ocho puntos, `GREATEST` |
| [`PdoUserRepository.php`](../src/Infrastructure/Repository/PdoUserRepository.php) | :387, :561 | `DATE_SUB(NOW(), INTERVAL 48 HOUR)`, `DATE_ADD(NOW(), INTERVAL ... SECOND)` |
| [`PdoPreventiveOrderRepository.php`](../src/Infrastructure/Repository/PdoPreventiveOrderRepository.php) | :445 | `BETWEEN CURRENT_DATE() AND DATE_ADD(...)` |
| [`PdoIncidentReplacedPartRepository.php`](../src/Infrastructure/Repository/PdoIncidentReplacedPartRepository.php) | :194, :232, :273, :324, :407 | `DATE_SUB(NOW(), INTERVAL ...)` |
| [`PdoSanitarySweepVisibilityRepository.php`](../src/Infrastructure/Repository/PdoSanitarySweepVisibilityRepository.php) | :123-128 | upsert con `VALUES()` |
| [`SeedRunner.php`](../src/Infrastructure/Database/SeedRunner.php) | 30+ coincidencias | `VALUES()`, `DATE_ADD`, `DATE_SUB`, `ON DUPLICATE KEY UPDATE` |
| [`ConnectionFactory.php`](../src/Infrastructure/Database/ConnectionFactory.php) | :92 | `PDO::MYSQL_ATTR_INIT_COMMAND` con `SET NAMES ... COLLATE` |

Además: `tests/integration/IncidentCommentsPagedRepositoryTest.php:205` consulta `information_schema.statistics` con `GROUP_CONCAT` y `SEQ_IN_INDEX` (exclusivo de MySQL), y `TestDataCleaner` cosecha el grafo de claves foráneas desde `information_schema` asumiendo el dialecto de MySQL.

**Hay 18 repositorios reales**, no los 5 nombrados, y alguno de los nombrados no existe: el árbol del plan lista `PdoRefundRepository.php`, cuando el fichero es [`PdoRefundRequestRepository.php`](../src/Infrastructure/Repository/PdoRefundRequestRepository.php).

**La prueba propuesta es insuficiente y el plan la incumple:** `SqlDialectPortabilityTest` (T-HYBRID-18) certificaría «cero backticks», pero la portabilidad no se decide por las comillas sino por las funciones: `DATE_ADD` frente a `+ INTERVAL`, `ON DUPLICATE KEY UPDATE` frente a `ON CONFLICT`, `JSON_UNQUOTE(JSON_EXTRACT(...))` frente a `->>`, `TIMESTAMPDIFF` frente a `EXTRACT(EPOCH FROM ...)`. Y el pseudocódigo del propio Algoritmo 4 escribe `EXTRACT(EPOCH FROM (now_utc - i.created_at))`, sintaxis exclusiva de PostgreSQL que su propia prueba prohibiría.

### H-7 · La bomba silenciosa del cotejamiento

Todo el sistema es `utf8mb4_unicode_ci` — insensible a mayúsculas **y a acentos**:

- Conexión: [`ConnectionFactory.php:92`](../src/Infrastructure/Database/ConnectionFactory.php#L92) fija `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci`.
- Esquema: las 20 tablas de [`cloud_init.sql`](../database/cloud_init.sql) y las de [`schema.sql`](../database/schema.sql) declaran `COLLATE=utf8mb4_unicode_ci`.

Sobre esa insensibilidad descansan hoy dos invariantes de negocio: `UNIQUE KEY uq_users_email (email)` ([`cloud_init.sql:105`](../database/cloud_init.sql#L105)) y `UNIQUE KEY uq_locations_site_code (site_code)` ([`cloud_init.sql:50`](../database/cloud_init.sql#L50)).

PostgreSQL compara **respetando mayúsculas y acentos** por defecto. Sin normalización explícita:

- `Admin@centro.es` y `admin@centro.es` pasan a ser **dos cuentas distintas** (identidad de login y Art. V.4).
- `SEDE-BCN-01` y `sede-bcn-01` pueden coexistir como dos sedes.
- `VEND-0101` y `vend-0101` dejan de colisionar, debilitando la red de seguridad de unicidad de ticket (Art. V.2 y V.6) en **producción** respecto al motor donde se certifica.

Ninguna tarea, requisito ni prueba del plan menciona `citext`, índices funcionales sobre `lower(...)` ni normalización en la capa de aplicación.

### H-8 · El plan gratuito no aporta lo que el plan promete

`spec.md` §1.1 justifica la migración con «persistencia administrada en la nube con **copias de seguridad continuas y alta disponibilidad**». El plan Free: **sin backups automáticos**, **sin PITR** y **sin SLA de disponibilidad**; la propia documentación de Supabase recomienda a los usuarios Free exportar con `supabase db dump` y custodiar copias fuera. Se migra de TiDB a Supabase Free y se pierde precisamente la garantía que motivaba el viaje.

Cifras adicionales del plan que no cuadran con el plan gratuito vigente:

- **RNF-03** justifica mantener las fotos en el servidor de aplicaciones para no consumir un «egress de 2 GB». El actual son **5 GB, compartidos a nivel de organización** entre Storage, Realtime, Auth, Functions, Supavisor y la propia base de datos: el tráfico del pooler consume el mismo presupuesto que se quería proteger.
- El máximo son **2 proyectos activos**, y EARS-02.2 exige un entorno de fixtures en la nube que **ninguna tarea aprovisiona**.

### H-9 · El despliegue real no arrancaría

- [`Dockerfile`](../Dockerfile) construye sobre `php:8.2-cli-alpine` e instala `docker-php-ext-install pdo pdo_mysql`. No hay `pdo_pgsql`.
- El `CMD` ejecuta `php bin/init_cloud_db.php`, un inicializador MySQL/TiDB cuyo DDL abre con `DROP TABLE`.
- El plan añade `database/supabase_init.sql` y `PostgresMigrationRunner.php`, pero **no toca la imagen, ni el `CMD`, ni `bin/init_cloud_db.php`**.

Consecuencia: sin `pdo_pgsql` la primera petición lanzaría «could not find driver», y el contenedor intentaría ejecutar DDL de MySQL contra PostgreSQL.

### H-10 · La certificación exigida la rompe el propio plan

T-HYBRID-22 exige la batería completa (249 suites · 9.425 aserciones) en verde mientras T-HYBRID-18..21 añaden alrededor de nueve suites.

[`tests/Support/DocMetricsGuard.php`](../tests/Support/DocMetricsGuard.php) audita las cifras escritas a mano en la insignia del README, las cuatro líneas de fase, los totales y el comentario del árbol de directorios, y **hace fallar** `php tests/run_all.php` ante cualquier desfase. Ninguna tarea actualiza esas cifras: el criterio §8.1 falla por construcción.

Y hay una inversión de cobertura: RNF-04 manda que toda la batería corra offline contra MariaDB. Eso convierte las cuatro suites nuevas de PostgreSQL en **lo único** que toca el motor que servirá producción: el motor con menos cobertura es el de producción, y las suites certificadas miden el motor que producción abandona.

### H-11 · Defectos menores

1. `/api/health` público informando driver, pooler y latencia es superficie de reconocimiento gratuita en un sistema cuyo Art. V.4 y VI.1 van de no filtrar interioridades. El plan lo declara «público» y la spec lo rutea al coordinador: hay que decidirlo y autenticarlo (ya existen `CronAuthMiddleware` e `InternalAuthMiddleware` para esto).
2. «Más de 15 transacciones concurrentes» y «60 conexiones directas» se presentan como hechos sin fuente.
3. `ATTR_TIMEOUT => 5` es un timeout **de conexión** (confirmado en el código de `pdo_pgsql`), de modo que no puede generar la ventana de 25 s de servidor que el banner del cliente espera: cliente 25 s y servidor 5 s siguen siendo mutuamente incoherentes.

---

## 4. Calibración: lo que el plan acierta

Para que este informe sea utilizable y no una demolición, se deja constancia de lo verificado como correcto:

1. **El inventario de tablas es exacto:** `cloud_init.sql` contiene exactamente **20** sentencias `CREATE TABLE`, las mismas que enumera el plan.
2. **La equivalencia del blindaje de duplicados es real:** [`schema.sql:114`](../database/schema.sql#L114) define `is_active_ticket TINYINT GENERATED ALWAYS AS (IF(status IN ('CLOSED','CANCELLED'), NULL, 1)) VIRTUAL` con `UNIQUE KEY uq_machine_active_ticket (machine_id, is_active_ticket)`, que es funcionalmente equivalente al índice único parcial `WHERE status NOT IN ('CLOSED','CANCELLED')` propuesto. Ambas formas abarcan `RESOLVED` y protegen la ventana de garantía (Art. V.6).
3. **Escapar de la limitación de TiDB es una victoria real:** [`generated_columns_contract.md`](../specs/technical/generated_columns_contract.md) documenta que TiDB no admite `ALTER TABLE ... ADD` de columnas generadas `STORED`. El índice parcial de PostgreSQL elimina esa deuda de motor.
4. **El Dogma Vanilla se respeta:** conectar por `pdo_pgsql` sin SDK ni cliente npm cumple el Art. IV.
5. **`PDO::ATTR_TIMEOUT` sí funciona en `pdo_pgsql`**, como timeout de conexión (`connect_timeout` de libpq).
6. **`pg_cron` está disponible en el plan Free** y puede ejecutar una función que escriba filas; `pg_net` existe y soporta POST a endpoints externos (aunque su API está marcada como beta y la garantía por plan no es explícita).

---

## 5. Trazabilidad: hallazgo → requisito → tarea afectada

| Hallazgo | Requisito / artículo | Tarea del plan que hay que reescribir |
| :--- | :--- | :--- |
| H-1 | RF-04 (EARS-04.1), RF-03 (EARS-03.1/03.3), §6.1, §8.4, §8.6 | T-HYBRID-05, T-HYBRID-15, T-HYBRID-16, T-HYBRID-17 |
| H-2 | EARS-06.1, Art. I.3, Art. II | T-HYBRID-04 |
| H-3 | RF-08.1, Art. III.1, §8.1/§8.2 | T-HYBRID-01, T-HYBRID-02, T-HYBRID-21 |
| H-4 | RF-01, RF-09, RNF-01, RNF-05 | T-HYBRID-06, T-HYBRID-12, T-HYBRID-13 |
| H-5 | T-PAUSE-40/41, RNF-01 | *Ausente del plan* |
| H-6 | RF-01.1, RF-08, Art. V.1 | T-HYBRID-08, T-HYBRID-09, T-HYBRID-10, T-HYBRID-11, T-HYBRID-18 |
| H-7 | Art. V.2, Art. V.4, Art. V.6 | *Ausente del plan* |
| H-8 | spec §1.1, RNF-02, RNF-03 | T-HYBRID-03, T-HYBRID-05 |
| H-9 | RF-01, §8.1 | *Ausente del plan* (Dockerfile, `CMD`, `bin/init_cloud_db.php`) |
| H-10 | T-HYBRID-22, §8.1 | T-HYBRID-18, T-HYBRID-19, T-HYBRID-20, T-HYBRID-21, T-HYBRID-22 |
| H-11 | RF-07, RNF-01, Art. V.4 | T-HYBRID-12, T-HYBRID-13 |

---

## 6. Plan de Reparación Propuesto (antes de escribir una línea de SQL)

1. **Suprimir la ficción del cold start** y sustituirla por la verdad operativa: si el proyecto se pausa, el servicio está caído hasta que alguien lo restaure. Automatizar `POST /v1/projects/{ref}/restore` con un token de la Management API custodiado en *secrets* de GitHub Actions, con reintentos y notificación real (no un log).
2. **Elegir una sola orquestación del barrido sanitario** y eliminar el procedimiento almacenado duplicado. La opción coherente con EARS-06.1 es `pg_cron` invocando de forma delgada el servicio de dominio existente, con autenticación interna y secreto en Vault.
3. **Conservar el rechazo ruidoso** (SQLSTATE + mensaje constitucional) y la puerta de purga, portada como ajuste transaccional (`set_config(..., true)`), en lugar de un `DO INSTEAD NOTHING` ciego en 20 tablas.
4. **Corregir la configuración del pooler:** emulación de prepares activada y zona horaria resuelta por transacción (`SET LOCAL`) o por configuración del proyecto.
5. **Sustituir el alcance inventado por un inventario real del dialecto:** los 18 repositorios, `SeedRunner`, `DemoMetricsSeeder`, `bin/`, las suites que consultan `information_schema`, `PdoLockWaitBudget` y `PdoTransactionManager`.
6. **Decidir el cotejamiento** de forma explícita y probada: `citext`, índices funcionales sobre `lower(...)` o normalización en aplicación, con pruebas para email, `site_code` y `ticket_code`.
7. **Arreglar el despliegue:** añadir `pdo_pgsql` a la imagen, reescribir el `CMD` y retirar el inicializador MySQL/TiDB del arranque.
8. **Asumir que el plan gratuito no son backups** e incorporar el volcado periódico con custodia externa, o declarar el riesgo por escrito y sus consecuencias.
9. **Incorporar la actualización de las cifras documentadas** a la lista de tareas de la fase de pruebas, porque la guarda de métricas las audita y las convierte en requisito de verde.

---

## 7. Limitaciones de esta Auditoría

- **No se ejecutó ninguna suite de pruebas.** La auditoría es de lectura; no hay cambio de código que justifique una ejecución, y ejecutar hoy la batería informaría sobre MariaDB, no sobre PostgreSQL. Las cifras de suites y aserciones citadas provienen de la documentación del propio plan y no de una medición en vivo.
- **No se ha probado ninguna conexión real contra Supabase.** Los hallazgos H-4 y H-5 se apoyan en documentación oficial y en el código fuente del driver, no en una conexión ejecutada.
- **Cifras del plan no rebatidas por falta de fuente:** los límites concretos de conexiones directas y de pool («15 transacciones», «60 conexiones directas») se marcan como no verificados, no como falsos.
- **Un sondeo automatizado devolvió falsos ceros** (filtro de recuento del buscador); los hallazgos se confirmaron repitiendo los sondeos y leyendo los ficheros directamente, de modo que no dependen de esa salida.

---

## 8. Revisión 2 · Segunda pasada sobre la versión reparada

**Objeto:** los documentos del módulo 12 tal como quedaron en el commit `70cbb17` (*re-anchor the module 12 spec, plan and tasks on the audited facts*), que aplica las reparaciones propuestas en las secciones 1 a 6 de este informe. **Método:** idéntica disciplina adversarial. Los hallazgos nuevos conservan los identificadores `N-n` de la pasada en conversación, para que ambas lecturas sean trazables entre sí; aquí se presentan **ordenados por gravedad**, no en el orden en que se descubrieron.

### 8.1 Qué se cerró, qué sigue abierto y qué empeoró

| Hallazgo original | Estado tras la reparación | Evidencia de cierre | Residuo |
| :--- | :--- | :--- | :--- |
| **H-1** Cold start imposible | **Cerrado** | Cero coincidencias de `cold_start` / `25 s` / `25000` / `25 segundos` en los tres documentos; EARS-04.1 reescrito a 5,0 s en ambos extremos; EARS-03.3 y §6.1 describen pausa real y restauración por API; T-HYBRID-05 añade la auto-restauración | La condición que dispara la restauración es inobservable desde la aplicación → **N-4** |
| **H-2** Doble fuente de verdad del barrido | **Cerrado en lo esencial** | EARS-06.1 prohíbe la duplicación; EARS-06.2 invoca el endpoint canónico existente; desaparece el procedimiento almacenado; el `EXTRACT(EPOCH FROM ...)` que contradecía la prueba de portabilidad ya no está en el Algoritmo 4 | El acoplamiento nuevo y su credencial → **N-1**, **N-3**, **N-5** |
| **H-3** Anti-borrado ciego | **Cerrado en lo esencial** | EARS-08.1 restaura el disparador ruidoso `SQLSTATE '45000'` con puerta `vendguard.purge`; el diseño es **compatible con las dos aserciones que ya existían** (§6.2 muerde sobre filas reales, §6.4 no muerde sobre un id inexistente) | La puerta no cubre a quien de verdad borra en el arnés → **N-2** |
| **H-4** Configuración del pooler | **Cerrado** | T-HYBRID-06 fija `ATTR_EMULATE_PREPARES => true`; desaparece el `SET timezone` de conexión; EARS-09.3 resuelve el bloqueo con `SET LOCAL lock_timeout` dentro de la transacción | La spec ya no declara de dónde sale la zona horaria de sesión (con `TIMESTAMPTZ` es innecesario, pero conviene dejarlo escrito) |
| **H-5** Presupuesto de espera de bloqueo | **Cerrado en lo esencial** | EARS-09.3 y T-HYBRID-07 con detección de driver; el árbol incorpora `PdoLockWaitBudget` y `PdoTransactionManager` | `PdoTransactionManager` figura en el árbol pero no tiene tarea propia; la rama PostgreSQL solo la ejerce la suite nueva |
| **H-6** Alcance del dialecto | **Cerrado parcialmente** | Los 18 repositorios enumerados y agrupados en T-HYBRID-09/10/11, con `PdoRefundRequestRepository` corregido; T-HYBRID-12 incorpora `Dockerfile`, `init_cloud_db.php`, `SeedRunner` y `TestDataCleaner` | Siguen fuera [`database/DemoMetricsSeeder.php`](../database/DemoMetricsSeeder.php), [`bin/seed_demo_metrics.php`](../bin/seed_demo_metrics.php) y la suite que consulta `information_schema.statistics` con `GROUP_CONCAT`; y la premisa «ANSI sin duplicar» es falsa → **N-7** |
| **H-7** Cotejamiento | **Abierto** | EARS-08.5 y T-HYBRID-01/21 incorporan `citext` | El criterio es insatisfacible tal como está redactado y la paridad entre motores se pierde → **N-6** |
| **H-8** Garantías del plan gratuito | **Cerrado** | §1.1 admite la ausencia de PITR y de copias continuas; RNF-03 pasa a 5 GB compartidos a nivel de organización | Ninguna tarea crea la exportación periódica en la que ahora se apoya la resiliencia; el entorno de fixtures en la nube (EARS-02.2) sigue sin aprovisionar |
| **H-9** Despliegue | **Cerrado parcialmente** | §8.6 y T-HYBRID-12 exigen `pdo_pgsql` e inicializador consciente del driver | Falta verificar paquete y cadena de compilación en Alpine; el arranque no reintenta ni se reejecuta tras restaurar el proyecto → **N-10** |
| **H-10** Cifras documentadas | **Cerrado** | T-HYBRID-22, spec §8.1 y plan §7.4 asumen la actualización de las cifras de la batería | — |
| **H-11** Defectos menores | **Cerrado parcialmente** | La telemetría de salud queda protegida (EARS-07.2/07.3) y las cifras sin fuente («60 conexiones directas», «15 transacciones») desaparecen del texto reparado | La cabecera `X-Internal-Token` es nueva e inventada → **N-1**; el contrato de `/api/health` sigue chocando con el handler vivo → **N-2** |

**Balance:** 4 hallazgos cerrados (H-1, H-4, H-8, H-10), 6 cerrados parcialmente o en lo esencial (H-2, H-3, H-5, H-6, H-9, H-11) y 1 abierto (H-7). La reparación no fue cosmética. Pero introdujo **un fallo de despliegue nuevo y grave (N-12)** y dejó **cinco decisiones sin tomar** (§8.5).

### 8.2 Hallazgos nuevos, por gravedad

#### N-12 · La reparación eliminó el ejecutor de migraciones y reintrodujo el fallo del 2026-10-03 *(Crítica)*

`PostgresMigrationRunner` existía en el plan original y **no aparece en ninguna de las tres versiones reparadas** (verificado por búsqueda en `plan.md`, `spec.md` y `tasks.md`: ausencia total). El camino PostgreSQL queda así apoyado en **una sola foto fija**, `database/supabase_init.sql`, sin ningún mecanismo de convergencia.

Ese diseño tiene nombre en este repositorio y una cicatriz que lo documenta. [`CloudDeploySchemaParityTest.php`](../tests/integration/CloudDeploySchemaParityTest.php) existe por el incidente del **2026-10-03**: el contenedor creaba el esquema con el DDL base y **nunca aplicaba `database/migrations/`**, así que `refund_requests` quedó sin tres columnas y la bandeja de reintegros respondió 500 en producción **con toda la batería en verde**. Su diagnóstico literal: *«every suite runs against the local schema, which is migrated. The deployment runs against a schema that is created, not migrated. That gap is what this suite closes»*.

Al retirar el ejecutor, el proyecto vuelve a tener un esquema local migrado y un esquema de producción creado — **en el motor nuevo** — y en cuanto se añada la primera columna después del despliegue, PostgreSQL no tendrá forma de recibirla. No es una hipótesis: el propio plan crea tablas y columnas en sus tareas 01 a 04. Y el guardián que existe para detectar esa asimetría quedará vigilando el motor antiguo.

#### N-1 · El secreto del barrido no tiene dónde vivir, y sin él Art. II se apaga en silencio *(Crítica)*

El repositorio ya tiene credencial interna de proceso batch, y no es la que el plan inventa: [`CronAuthMiddleware.php:76`](../src/Presentation/Http/Middleware/CronAuthMiddleware.php#L76) acepta `X-Cron-Secret: <CRON_SECRET>`, `Authorization: Bearer <CRON_SECRET>` o un token de sesión de Coordinador, y responde **401**. El plan estrena `X-Internal-Token` para `/api/health` (EARS-07.3, plan §2.1): un mecanismo paralelo para la misma clase de llamante.

Lo grave es lo otro: [`SecretProvider.php:89`](../src/Infrastructure/Config/SecretProvider.php#L89) **lanza excepción en producción si falta `CRON_SECRET`**. Como EARS-06.2 convierte `pg_cron` + `pg_net` en el **único** orquestador de producción del barrido, la ausencia de una variable de entorno deja el endpoint en error y **desactiva la cuarentena automática de un perecedero sin que nada avise**.

Y la credencial tiene que viajar: `pg_net.http_post` la necesita, y los tres documentos **no mencionan ni una vez Supabase Vault**. Las dos únicas salidas son **embeber el secreto en `database/supabase_init.sql`** —un fichero versionado— o dejar abierto un endpoint capaz de escribir cuarentenas. Ninguna está decidida.

#### N-5 · El barrido sanitario pasa a depender de dos servicios que se duermen *(Alta)*

`pg_cron` corre **dentro** del motor que se pausa, y `pg_net` llama a la aplicación, que vive en un PaaS gratuito que se suspende por inactividad, con **2 segundos de timeout por defecto**, de forma asíncrona y sin reintento. Una cadencia de 15 minutos no mantiene caliente el contenedor: cada invocación se juega el arranque en frío contra un reloj de dos segundos.

El modo de fallo no es un error visible: es que **la cuarentena no se ejecute y nadie se entere** — sin reintento, sin alerta de barrido perdido y sin registro de «no corrió». Para el artículo del que cuelga la seguridad alimentaria, es el peor fallo posible; la versión anterior (`bin/sanitary_clock_sweep.php` en el cron del host) era más robusta.

#### N-2 · La puerta de purga no cubre a quien de verdad borra *(Alta)*

EARS-08.1 instala 20 disparadores y una única salida, `vendguard.purge`. T-HYBRID-12 parchea **solo** `TestDataCleaner`. Pero el arnés tiene dos estrategias de borrado y la segunda no pasa por el limpiador: seis suites borran **maestras** con SQL directo —[`PdoUserRepositoryTest.php:141`](../tests/integration/PdoUserRepositoryTest.php#L141) (`users` por email, `machines` en cuatro puntos), [`PdoMachineRepositoryTest.php`](../tests/integration/PdoMachineRepositoryTest.php), [`PdoLocationRepositoryTest.php:113`](../tests/integration/PdoLocationRepositoryTest.php#L113), [`IncidentPauseServiceTest.php:616`](../tests/unit/IncidentPauseServiceTest.php#L616) (`audit_log` y `machines`), [`IncidentPauseInactivityCancellationTest.php:1082`](../tests/unit/IncidentPauseInactivityCancellationTest.php#L1082) y [`HarnessResidueGuardTest.php`](../tests/integration/HarnessResidueGuardTest.php)—: diecisiete sentencias sobre las tablas que `TestDataCleaner::PROTECTED_TABLES` declara intocables. Con los 20 disparadores puestos, todas reciben `45000` y las suites se ponen rojas.

Agravante técnico: `set_config(..., true)` es de ámbito **transaccional**, y ninguna de esas sentencias abre transacción — van en autocommit. La salida honesta es separar planos: marca de purga a nivel de **sesión** para el arnés local (que se conecta directo a MariaDB) y rechazo ruidoso intacto para la aplicación.

#### N-4 · El latido nunca podrá ver un HTTP 540 *(Alta)*

EARS-03.3 condiciona la auto-restauración a que el latido «detecte que el servicio en la nube devuelve código HTTP 540». Pero el latido es, por EARS-03.1, un `curl` contra **nuestro** `/api/health`, que por EARS-07.4 devuelve **503**. El `540` es un estado HTTP de las APIs de Supabase (PostREST, Studio, Management), no algo que emita una conexión PostgreSQL por PDO. La rama de restauración, tal como está escrita, **o no dispara nunca** o dispara ante cualquier 503 —incluida una saturación del pool o el contenedor dormido— y entonces llama a `projects:write` para restaurar un proyecto sano.

#### N-3 · El endpoint y el health ya existen, y cambiarlos rompe contratos vivos *(Alta)*

`POST /api/cron/sanitary-clock-sweep` **ya está registrado**: [`AppRouter.php:246`](../src/Presentation/Routing/AppRouter.php#L246), con `CronController::sweepSanitaryClock` y `CronAuthMiddleware`. T-HYBRID-04 «programa» algo que existe (correcto), pero el plan no lo reconoce.

Y `/api/health` también existe, con un contrato distinto del que el plan supone: [`AppRouter.php:31`](../src/Presentation/Routing/AppRouter.php#L31) es un handler **dual** que sirve el SPA `index.html` cuando el `Accept` trae `text/html` y devuelve un JSON con `name`, `version`, `status: 'operational'` y `php_version` cuando no. **No toca la base de datos.** Cinco suites lo usan como sonda de arranque con `curl http://127.0.0.1:8000/api/health`, y un diagnóstico fija además su envoltura JSON ([`PendingInfoSmokeWorkflowDiagnostic.php:347`](../tests/diagnostics/PendingInfoSmokeWorkflowDiagnostic.php#L347) exige que el cuerpo contenga `VendGuard API`). El contrato nuevo de §2.1 no contiene esa cadena y no dice una palabra sobre preservar la ruta que sirve el frontend.

#### N-7 · La apuesta central sigue apoyada en una falsedad *(Alta)*

El plan mantiene que una consulta ANSI sin comillas privativas «es válida al 100% en MySQL, MariaDB y PostgreSQL» y permite «conmutar motores con cero código duplicado» ([`plan.md:49`](../specs/12-hybrid-supabase-migration/plan.md#L49), y la afirmación «cero duplicación» de la línea 278), y declara `SeedRunner.php` como «sembrador agnóstico de motor (ANSI SQL)».

No existe forma ANSI de: `ON CONFLICT` frente a `ON DUPLICATE KEY UPDATE`; `EXCLUDED` frente a `VALUES()`; `JSON_EXTRACT`/`JSON_UNQUOTE` frente a `->>`; `TIMESTAMPDIFF` frente a `EXTRACT(EPOCH)`/`date_part`; `DATE_ADD(x, INTERVAL n DAY)` frente a `x + INTERVAL 'n days'`. **Son exactamente los constructos que el sistema más usa.** T-HYBRID-10 ordena sustituir `ON DUPLICATE KEY UPDATE` por «sintaxis ANSI portable»: esa sintaxis no existe. Y T-HYBRID-12(3) pide un `SeedRunner` con `ON CONFLICT` que a la vez sea agnóstico, lo que es autocontradictorio.

Consecuencia: o se acepta **duplicación de consultas por driver** —que el propio plan descarta en su tabla de decisiones— o el upsert se queda en dialecto de MySQL y RF-01 se incumple. Las tres tareas de normalización cubren 18 repositorios (uno de 2.176 líneas) con un criterio de cierre que en los repositorios con upsert y JSON **no se puede satisfacer sin duplicar**. La elección no está hecha, y es el corazón de la migración.

#### N-6 · EARS-08.5 sigue siendo insatisfacible y contradice al §8 *(Media-alta)*

Exige «insensibilidad a mayúsculas **y acentos**... mediante `CITEXT` o índices funcionales `UNIQUE (LOWER(...))`... replicando la semántica de `utf8mb4_unicode_ci`». Ni `citext` ni `LOWER()` quitan acentos: solo cubren mayúsculas. Y el criterio §8.2 redacta lo mismo **sin** mencionar acentos: dos barras de aceptación distintas para la misma regla.

Hay algo más profundo: MariaDB conservará su cotejamiento fuerte (insensible a mayúsculas **y** acentos) mientras PostgreSQL use el débil. La misma regla tendrá **dos fuerzas según el motor**, y como las pruebas corren offline contra MariaDB (RNF-04), certificarán la fuerte mientras producción ejecuta la débil. La reparación honesta no es una colación ni una extensión, sino **normalizar en la aplicación** con una columna canónica (`email_key`, `site_code_key`), que es la única forma de que ambos motores se comporten igual y de que la prueba mida lo que producción ejecuta.

#### N-8 · `PostgresParityTest` no prueba paridad, y la deriva se multiplica sin guardián *(Media)*

El test hace CRUD sobre las 20 tablas de PostgreSQL: eso es un *smoke test* de un motor, no una comprobación de paridad entre motores. Ahora existen **tres** definiciones de esquema —`schema.sql` + 18 migraciones (MariaDB), `cloud_init.sql` (MySQL/TiDB) y `supabase_init.sql` (PostgreSQL)— y solo la del camino de despliegue MySQL/TiDB tiene guardián. Una columna nueva tendrá que aparecer en tres sitios y solo uno está auditado.

#### N-10 · El esquema se inicializa una sola vez, y nunca después de restaurar *(Media)*

El `CMD` del contenedor ejecuta `php bin/init_cloud_db.php ; php -S ...`. El `;` garantiza que el servidor arranca aunque el inicializador falle (bien), pero un arranque contra un proyecto pausado o inaccesible deja el servicio **sirviendo sin esquema verificado**, sin reintento y sin segunda oportunidad. Y como el ciclo de recuperación documentado es «pausa → restaurar», nadie vuelve a ejecutar el inicializador ni a comprobar semillas tras la restauración.

#### N-9 · El rendimiento certificado no es el que se va a servir *(Media)*

RNF-01 exige 250 ms de mediana «a través del agrupador Supavisor». La evidencia de rendimiento del proyecto —medianas de ≈3 ms medidas por el camino real router + middleware + servicio + PDO, documentadas al cerrar el módulo 11— se obtuvo contra **MariaDB local**. El plan traslada el umbral a un camino remoto con pool transaccional y no define ninguna tarea de medición: ni número de muestras, ni percentil exigido, ni qué ocurre si no se cumple. Heredar una cifra medida en otra arquitectura no es un criterio.

#### N-11 · Un detalle barato de cerrar *(Baja)*

EARS-03.2 exige que el latido registre «actividad real en el motor» mediante `SELECT 1`, pero el health actual ni toca la base de datos, y un `SELECT 1` puede no contar para la heurística de pausa de Supabase. Tocar una tabla real cuesta lo mismo y no deja la disponibilidad apoyada en una suposición.

### 8.3 Correcciones a esta propia auditoría

Dos afirmaciones de la pasada anterior quedaron imprecisas y se corrigen aquí, porque de la segunda nace el hallazgo más grave de esta revisión:

1. **Sobre el contrato de `/api/health` (hoy N-3).** Se afirmó que cinco suites lo usan como sonda y que ninguna fija su contrato. Lo primero se confirma; lo segundo no: existe un diagnóstico que **sí** fija la envoltura JSON, y lo hace sobre `/`, que comparte handler con `/api/health`.
2. **Sobre el guardián de paridad (hoy N-8 y N-12).** Se afirmó que `CloudDeploySchemaParityTest` guarda «solo el DDL de TiDB». Es más amplio: guarda el camino de despliegue MySQL/TiDB **completo** —DDL base más el conjunto de migraciones— y comprueba que el arranque del contenedor aplica `database/migrations/`. Esa precisión es la que hace visible que la reparación retirase el ejecutor de migraciones de PostgreSQL.

### 8.4 Veredicto revisado

La reparación fue real: eliminó las tres premisas falsas, restauró el rechazo ruidoso, corrigió las dos palancas del pooler, amplió el alcance del dialecto a los 18 repositorios y asumió la actualización de las cifras documentadas. Eso es la mayor parte de lo que pedía la primera pasada.

Lo que queda ya no es falsedad documental sino **riesgo de ingeniería sin decidir**: una credencial sin domicilio, un barrido sanitario colgado de dos servicios que se duermen, una puerta de purga que no cubre al arnés que realmente borra, una condición de restauración inobservable, una apuesta de dialecto común que no existe para los constructos que más se usan y —lo más grave— **un esquema de producción que se crea en lugar de migrarse**, que es literalmente el fallo del 2026-10-03 trasladado al motor nuevo.

Por eso el veredicto sigue siendo **no empezar por T-HYBRID-01**: las tareas 01 a 04 construyen el DDL y la orquestación, y sin las cinco decisiones de §8.5 volverán a ser papel.

### 8.5 Decisiones que hay que tomar antes de escribir una línea de SQL

1. **Dónde vive la credencial del callback.** Vault con `net.http_post` leyéndola, o se abandona `pg_net` y se usa el cron del host con el CLI que ya existe. Embeber `CRON_SECRET` en `supabase_init.sql` no es una opción.
2. **Convergencia de esquema para PostgreSQL.** Devolver un ejecutor de migraciones (como el que la reparación retiró) y **extender el guardián de paridad** para que cubra el esquema nuevo. Sin esto, N-12.
3. **Cotejamiento en la capa de aplicación.** Columna canónica por campo de unicidad, en lugar de confiar en una colación que ningún motor replica igual.
4. **¿Se acepta duplicación de consultas por driver?** Es la única salida realista para upserts y JSON. Si la respuesta es no, RF-01 no se puede cumplir y hay que decirlo en la especificación.
5. **Robustez del barrido.** Reintento, alerta por barrido perdido y registro de «no corrió», o vuelta al cron del host. Art. II no admite un fallo silencioso.

### 8.6 Limitaciones de esta revisión

- Todo el análisis es **estático y de solo lectura**; no se ejecutó ninguna suite ni se estableció ninguna conexión contra PostgreSQL o Supabase.
- Los plazos y límites del proveedor (2 s de `pg_net`, pausa tras inactividad, 5 GB de egress compartido, ausencia de PITR) provienen de su documentación oficial, no de una medición propia.
- La afirmación sobre el ámbito transaccional de `set_config(..., true)` (N-2) se apoya en la semántica documentada de `SET LOCAL` en PostgreSQL, no en una ejecución.
- La lista de residuos de dialecto del hallazgo H-6 es exhaustiva para `src/`, `bin/` y `database/`, y muestral en `tests/`.

---

## 8.7 Estado tras la reparación de la revisión 2

Los doce hallazgos `N-n` y las cinco decisiones de §8.5 se han incorporado a los documentos del módulo 12 (rama `supabase-migration`): `tasks.md` pasa de 22 a **27 tareas** y `spec.md` gana los criterios EARS-01.5, 02.4, 02.5, 06.5, 06.6 y 07.5. La tabla siguiente dice **dónde** queda cada uno; la columna final dice **qué sigue sin ser verdad** mientras no exista código.

| Hallazgo | Dónde queda resuelto | Naturaleza de la reparación |
| :--- | :--- | :--- |
| **N-12** Esquema creado, no migrado | plan §6 (primera decisión ratificada), EARS-02.4/02.5, T-HYBRID-23 (`PostgresMigrationRunner` + `database/supabase_migrations/001_initial_baseline.sql`), T-HYBRID-24 | Decisión de diseño + tareas |
| **N-1** Credencial sin domicilio | EARS-06.2 (cron del servidor de aplicaciones como orquestador canónico), EARS-06.6 (`pg_cron`+`pg_net` solo con credencial en almacén del proveedor, prohibido en el DDL versionado), T-HYBRID-04; EARS-07.3 y T-HYBRID-14 reutilizan `CRON_SECRET` y eliminan la cabecera inventada | Decisión de diseño + tareas |
| **N-5** Barrido dependiente de dos servicios que se duermen | EARS-06.5 (reintento con espera progresiva, registro de cada pasada, **alerta** ante ventana sin barrido), T-HYBRID-04; T-HYBRID-05(4) exige notificar por un canal distinto del log | Decisión de diseño + tareas |
| **N-2** Puerta de purga que no cubre al arnés | EARS-08.1 y criterio §8.2 pasan a **ámbito de sesión** (`set_config(..., false)`), plan §4.3, T-HYBRID-12(4) y T-HYBRID-26 (las seis suites que borran maestras) | Decisión de diseño + tareas |
| **N-4** Restauración disparada por un código HTTP inobservable | EARS-03.3 condiciona la restauración al **estado del proyecto leído por la API de gestión**, nunca al código HTTP de la aplicación; T-HYBRID-05(2) | Decisión de diseño |
| **N-3** Contratos vivos de `/api/health` | EARS-07.5 y T-HYBRID-14: ruta nueva `GET /api/status`, contrato de `/api/health` **intacto** y certificado por prueba (envoltura JSON y servicio del documento) | Decisión de diseño + tareas |
| **N-7** Portabilidad ANSI inexistente | EARS-01.1 y EARS-01.5, plan §6 (quinta decisión), T-HYBRID-25 (`SqlDialectAdapter` como punto único de traducción) y T-HYBRID-09/10/11/12 que lo consumen | Decisión de diseño: se abandona la premisa, no se disfraza |
| **N-6** Cotejamiento insatisfacible | EARS-08.5 y criterio §8.2 pasan a **claves canónicas en la aplicación** (`email_key`, `site_code_key`), con `CITEXT` retirado del DDL y declarado como alternativa descartada (plan §4.2 y §6) | Decisión de diseño |
| **N-8** `PostgresParityTest` no probaba paridad | T-HYBRID-24 crea `SchemaConvergenceParityTest` (convergencia **y** paridad cruzada de inventarios) y renombra el smoke test a `PostgresSchemaTest` | Tarea |
| **N-10** Esquema sin segunda oportunidad | T-HYBRID-12(2): `init_cloud_db.php` reintenta ≥3 veces y **sale con error si el esquema no queda verificado**; T-HYBRID-05(3) re-inicializa tras restaurar | Tarea |
| **N-9** Rendimiento certificado en otra arquitectura | T-HYBRID-27: medición contra el motor remoto por el camino real, mínimo 20 muestras por operación, mediana y percentil 95 publicados, umbral de 250 ms, y constancia escrita de que las cifras locales no se heredan | Tarea |
| **N-11** Latido sin actividad real | EARS-03.2 y T-HYBRID-13 sustituyen el `SELECT 1` sintético por una consulta sobre tabla real del dominio | Decisión de diseño |
| **§8.5** Cinco decisiones pendientes | plan §6, tabla de decisiones ratificadas y alternativas descartadas, declarada **vinculante** para las tareas | Decisión de diseño |

**Lo que esta reparación no demuestra** (y que una tercera pasada debe exigir):

1. **No existe todavía una línea de SQL ni de PHP nuevo.** Los artefactos citados son tareas, no ficheros. Ninguna cifra de paridad, convergencia o latencia ha sido medida.
2. **La reparación es documental.** Cierra la clase de defecto que esta auditoría podía juzgar —afirmaciones falsas y decisiones sin tomar— y deja en pie los riesgos que solo se cierran ejecutando: `pdo_pgsql` sobre Alpine, el pooler real, el `pg_net` de 2 s y la semántica de `set_config` de sesión a través de Supavisor.
3. **Los límites del proveedor siguen proviniendo de su documentación**, no de una medición propia, y el plan gratuito puede cambiar sus condiciones.
4. **Tres definiciones de esquema coexisten por diseño** (`schema.sql` + migraciones, `cloud_init.sql`, `supabase_init.sql` + `supabase_migrations/`). La convergencia queda *especificada* y **no** verificada; hasta que `SchemaConvergenceParityTest` se ejecute, la deriva sigue siendo posible.

---

## Anexo A · Sondas ejecutadas

- Inventario de `DELETE FROM` / `TRUNCATE TABLE` / `DROP TABLE` en todo el repositorio (118 coincidencias, truncado por tamaño).
- Conteo de backticks en `src/` (150 coincidencias, truncado) y en `src/Infrastructure/Repository` (cero en los ficheros muestreados directamente).
- Inventario de funciones privativas de MySQL (136 coincidencias): `ON DUPLICATE KEY UPDATE`, `AUTO_INCREMENT`, `VALUES()`, `DATE_ADD`, `DATE_SUB`, `TIMESTAMPDIFF`, `JSON_EXTRACT`, `GROUP_CONCAT`.
- Inventario de bloqueo y sesión en `src/`: `@@SESSION.innodb_lock_wait_timeout`, `SET SESSION`, `SELECT ... FOR UPDATE`.
- Conteo de `CREATE TABLE` en `database/`: 20 en `cloud_init.sql`.
- Inventario de columnas generadas (`GENERATED ALWAYS AS`, `VIRTUAL`, `STORED`).
- Cotejamiento y claves únicas (`COLLATE`, `CHARSET`, `uq_users_email`, `uq_locations_site_code`).
- Listado real de repositorios: 18 ficheros en `src/Infrastructure/Repository/`.

**Sondas añadidas en la revisión 2:**

- Rastro de la ficción eliminada (`cold_start|Cold Start|25 s|25000|25 segundos`) en los tres documentos reparados: cero coincidencias.
- Localización de los artefactos que el plan daba por nuevos: `AppRouter.php:31`, `:52`, `:53` (health dual) y `:246` (endpoint del barrido con `CronAuthMiddleware`).
- Credencial interna real del proceso batch: `CronAuthMiddleware` (`X-Cron-Secret`, Bearer, Coordinador) y `SecretProvider::cronSecret()` con fallo cerrado en producción.
- Búsqueda de `Vault` en los tres documentos reparados: cero coincidencias.
- Borrados directos de tablas maestras fuera de `TestDataCleaner`: 17 sentencias en seis suites.
- Búsqueda de `PostgresMigrationRunner` en los tres documentos reparados: ausencia total.
- Residuos de dialecto no cubiertos por ninguna tarea: `database/DemoMetricsSeeder.php`, `bin/seed_demo_metrics.php`.
- Verificación de la numeración de tareas (01–22, sin duplicados ni huecos) y de que `RF-08.5` resuelve contra `EARS-08.5`.

## Anexo B · Fuentes externas consultadas

- Supabase · [Pricing](https://supabase.com/pricing)
- Supabase · [Project Pausing](https://supabase.com/docs/guides/platform/free-project-pausing)
- Supabase · [HTTP status codes](https://supabase.com/docs/guides/troubleshooting/http-status-codes)
- Supabase · [Restore a project API](https://supabase.com/docs/reference/api/v1-restore-a-project)
- Supabase · [Database Backups](https://supabase.com/docs/guides/platform/backups)
- Supabase · [Connecting to Postgres (pooler y modos de conexión)](https://supabase.com/docs/guides/database/connecting-to-postgres)
- Supabase · [Disabling prepared statements](https://supabase.com/docs/guides/troubleshooting/disabling-prepared-statements-qL8lEL)
- Supabase · [Cron](https://supabase.com/docs/guides/cron) y [pg_net](https://supabase.com/docs/guides/database/extensions/pg_net)
- PostgreSQL · [`SET`](https://www.postgresql.org/docs/current/sql-set.html) y [advisory locks](https://www.postgresql.org/docs/current/explicit-locking.html#ADVISORY-LOCKS)
- PHP · [pdo_pgsql driver source](https://raw.githubusercontent.com/php/php-src/master/ext/pdo_pgsql/pgsql_driver.c) y [`PDO_PGSQL DSN`](https://www.php.net/manual/en/ref.pdo-pgsql.connection.php)

---

*Auditoría de solo lectura en sus dos pasadas. Ningún fichero de `src/`, `tests/`, `database/` ni de las especificaciones del módulo 12 fue modificado durante su elaboración: la primera documenta el plan original (`7ecd3bb`) y la segunda audita la reparación (`70cbb17`), que fue obra de terceros y se limitó a los documentos del módulo. La parte vigente de este informe es la §8.*
