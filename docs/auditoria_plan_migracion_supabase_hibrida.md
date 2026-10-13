# AUDITORÍA CRÍTICA DEL PLAN DE MIGRACIÓN HÍBRIDA A SUPABASE (MÓDULO 12)

**Documento:** `docs/auditoria_plan_migracion_supabase_hibrida.md`  
**Fecha:** Octubre 2026  
**Objeto auditado:** [`specs/12-hybrid-supabase-migration/plan.md`](../specs/12-hybrid-supabase-migration/plan.md), [`spec.md`](../specs/12-hybrid-supabase-migration/spec.md), [`tasks.md`](../specs/12-hybrid-supabase-migration/tasks.md) y [`docs/migracion_supabase_hibrido.md`](migracion_supabase_hibrido.md)  
**Rama:** `supabase-migration` (commit `7ecd3bb`)  
**Naturaleza:** Auditoría adversarial de solo lectura. **No se ha modificado ninguna línea de `src/`, `tests/`, `database/` ni de las especificaciones.**  
**Normativa Suprema:** [`constitution.md`](../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  
**Veredicto:** **NO iniciar la Fase 1 (T-HYBRID-01)**. El plan es formalmente impecable y falla en dos premisas centrales, configura mal el pooler elegido e introduce una bomba silenciosa en el arnés de pruebas.

---

## 1. Resumen Ejecutivo

El plan del módulo 12 está escrito con un rigor documental notable: mapea requisitos, cita artículos constitucionales, define contratos JSON, incluye pseudocódigo y una estrategia de pruebas de nueve suites nuevas. El problema no es de forma, es de **verdad verificable**: tres de sus afirmaciones estructurales no se sostienen contra el motor real, la documentación del proveedor o el propio código del repositorio.

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

**Lectura completa:** `plan.md` (444 líneas), `spec.md` (223), `tasks.md` (142), `docs/migracion_supabase_hibrido.md` (696), más los artefactos que el plan afirma tocar: `Dockerfile`, `src/Infrastructure/Database/ConnectionFactory.php`, `PdoLockWaitBudget.php`, `PdoTransactionManager.php`, `tests/Support/DocMetricsGuard.php`, `tests/Support/TestDataCleaner.php`, `database/schema.sql`, `database/cloud_init.sql`, `database/migrations/010_refund_hard_delete_guard.sql`, `specs/technical/testing_cleanup_contract.md`.

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

## Anexo A · Sondas ejecutadas

- Inventario de `DELETE FROM` / `TRUNCATE TABLE` / `DROP TABLE` en todo el repositorio (118 coincidencias, truncado por tamaño).
- Conteo de backticks en `src/` (150 coincidencias, truncado) y en `src/Infrastructure/Repository` (cero en los ficheros muestreados directamente).
- Inventario de funciones privativas de MySQL (136 coincidencias): `ON DUPLICATE KEY UPDATE`, `AUTO_INCREMENT`, `VALUES()`, `DATE_ADD`, `DATE_SUB`, `TIMESTAMPDIFF`, `JSON_EXTRACT`, `GROUP_CONCAT`.
- Inventario de bloqueo y sesión en `src/`: `@@SESSION.innodb_lock_wait_timeout`, `SET SESSION`, `SELECT ... FOR UPDATE`.
- Conteo de `CREATE TABLE` en `database/`: 20 en `cloud_init.sql`.
- Inventario de columnas generadas (`GENERATED ALWAYS AS`, `VIRTUAL`, `STORED`).
- Cotejamiento y claves únicas (`COLLATE`, `CHARSET`, `uq_users_email`, `uq_locations_site_code`).
- Listado real de repositorios: 18 ficheros en `src/Infrastructure/Repository/`.

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

*Auditoría de solo lectura. Ningún fichero de `src/`, `tests/`, `database/` ni de las especificaciones del módulo 12 fue modificado durante su elaboración.*
