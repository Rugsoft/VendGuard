# PLAN DE TAREAS DEL MÓDULO 13 · MOTOR ÚNICO: SUPABASE (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)
**Módulo:** `13-single-engine-supabase`
**Especificación:** [`spec.md`](spec.md) — **Plan:** [`plan.md`](plan.md)
**Decisiones del Product Owner (2026-10-15):** batería sobre **PostgreSQL local**; camino MariaDB **retirado por completo**.
**Regla de ejecución:** estricto orden de dependencias; **la rama heredada no se borra hasta que la batería completa esté verde** sobre el motor único.
**Estimación por tarea:** 20–30 minutos (las de porte por lote, lo que dure su lote).

---

## Fase 1 · Enmienda y puerta de aprobación

- [x] **T-13-01: Redactar la enmienda de motor único (especificación, plan y tareas) y anotarla en los documentos vigentes**
  * **Requisitos:** EARS-13.1 a EARS-13.10, AGENTS.md §5.5, Constitución Art. I.1
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existen `spec.md`, `plan.md` y `tasks.md` con el inventario real del repositorio, la tabla de enmiendas a los requisitos del módulo 12 (§4.1 de la especificación), la nota de enmienda anotada en la especificación enmendada y el puntero en `specs/functional/`. **No se escribe código de producción en esta fase.**
  * **Cerrado (2026-10-15):** inventario medido —94 suites resuelven su motor con la factoría y 6 declaran `PG_TEST_DSN`; 14 ficheros ejecutan constructos privativos (73 los mencionan en contexto SQL); 85 ficheros de prueba dependen de `SeedRunner`; 27 ficheros de `src/` referencian el motor heredado; 16 migraciones, 3 DDL y 2 sembradores heredados frente a **1** migración del motor único; 27 documentos lo mencionan; esquema `public` del motor local vacío—. La **puerta de aprobación** del Product Owner abre la Fase 2.

---

## Fase 2 · El banco de la batería y los eslabones críticos

- [x] **T-13-02: Aprovisionar el banco de la batería hermética en el motor PostgreSQL local**
  * **Requisitos:** EARS-13.5, EARS-13.7, RNF-04
  * **Dependencias:** T-13-01 aprobada
  * **Hecho cuando:** El motor de `deploy/local/postgres/` tiene esquema y catálogo canónicos (`supabase_init.sql` + `supabase_migrations/` + `supabase_seeds.sql`) por el inicializador del repositorio, su contrato de entorno está documentado en [`deploy/local/postgres/README.md`](../../deploy/local/postgres/README.md) y una sonda demuestra en cerrado que la batería no depende de la red.
  * **Cerrado (2026-10-15), con evidencia de ejecución:**
    * **El banco es el motor TLS en contenedor** (55433), no el nativo: el instalador nativo tiene `ssl = off` y activarlo exigiría superusuario y elevación sobre una instalación del sistema. El contenedor `vendguard-pg-tls` habla TLS con CA local y SAN `IP:127.0.0.1`, que es lo que `verify-full` necesita **sin excepción de loopback** (RNF-05).
    * **Base propia del arnés:** `vendguard_battery`, separada de `vendguard_pg_test` de la categoría del motor nuevo (el arnés crea sedes, máquinas, usuarios y averías por corrida, y la categoría mide justo eso).
    * **Aprovisionamiento canónico:** 136 sentencias del DDL base · **21** tablas en `public` · libro `schema_migrations` con la línea base aplicada y esquema convergido · catálogo 10 sedes · 13 máquinas · 6 usuarios · 13 repuestos · **0 averías** (arranque en limpio estricto con `VENDGUARD_DEMO_HISTORY=0`, porque las `INC-DEMO-*` ocupan máquina durante su ventana de garantía de 48 h y colisionarían con las fixtures).
    * **La aplicación conecta por su propia factoría:** `ConnectionFactory::getConnection()` con `DATABASE_URL` del contrato → `driver=pgsql`, base `vendguard_battery`, rol `vendguard_pg`, PostgreSQL 16.15, y `pg_stat_ssl` del backend en `ssl=true · TLSv1.3`.
    * **Fallo en cerrado comprobado, las tres formas que importan:** CA ajena ⇒ la conexión no verifica; `sslmode=require` ⇒ rechazo **antes** de conectar («Modo TLS no admitido (RNF-05)»); CA declarada inexistente ⇒ rechazo en cerrado; contrato sin tocar ⇒ conecta. La verificación no es decorativa.
    * **Sin regresión en la categoría:** sonda `verify.php` verde y `SchemaConvergenceParityTest` **21/21** contra `vendguard_pg_test`, que mi aprovisionamiento no toca.
    * **Contrato documentado** en [`deploy/local/postgres/README.md`](../../deploy/local/postgres/README.md) —`DATABASE_URL` en `.env.battery`, no `PG_TEST_DSN`: es la variable que la factoría lee primero, de modo que el banco se resuelve por la **misma** ruta que la aplicación— y el `.gitignore` cubre el fichero para que una credencial no acabe versionada —comprobado por el propio aprovisionamiento con `git check-ignore`, no supuesto: el «modo 600» que declara el `chmod` no impone permisos de acceso en Windows, donde la protección efectiva es esa regla—.
- [x] **T-13-03: Bootstrap y limpieza del arnés en la forma del motor único**
  * **Requisitos:** EARS-13.5, EARS-13.6, EARS-13.7
  * **Dependencias:** T-13-02
  * **Hecho cuando:** [`tests/bootstrap.php`](../../tests/bootstrap.php), [`tests/run_all.php`](../../tests/run_all.php) y [`TestDataCleaner`](../../tests/Support/TestDataCleaner.php) resuelven el motor único, abren la señal de purga en su única forma, y la corrida declara en rojo cualquier residuo con el contrato de limpieza vigente preservado.
  * **Cerrado (2026-10-15), con evidencia de ejecución:**
    * **El bootstrap resuelve el motor y no admite otro.** Fuentes, en orden: el entorno del proceso y el contrato del banco (`deploy/local/postgres/.env.battery`). Sin ninguna de las dos, sale con código **1** y la instrucción exacta; con un contrato que declare el motor heredado, también sale con **1** («El sistema tiene un solo motor»). Comprobadas las cuatro direcciones: carga normal, contrato ausente, motor heredado y **herencia al proceso hijo** (cada suite corre en su propio proceso y recibe el mismo motor sin volver a resolverlo). La declaración se imprime una sola vez por corrida.
    * **El limpiador escribe el dialecto del motor único:** identificadores desnudos, grafo de claves foráneas leído del catálogo real (`pg_constraint`, no `information_schema.key_column_usage`, que es de MySQL y en PostgreSQL no declara el destino de cada clave) y **una sola forma** de la ventana de purga (`set_config('vendguard.purge', …, false)`). Medido: el grafo resuelve **14 tablas** hijas-primero, la purga borró **63 filas** atravesando los disparadores del Art. III.1 y el catálogo quedó intacto (10 sedes · 13 máquinas).
    * **La guarda de residuo, intacta y verde:** [`HarnessResidueGuardTest`](../../tests/integration/HarnessResidueGuardTest.php) → **14/14 aserciones, exit 0** sobre PostgreSQL. Planta residuo, comprueba que el detector lo ve (vivo y en baja lógica), que el borrado ingenuo **es rechazado por el motor**, que la purga drena el subárbol y que las diez sedes semilla quedan intactas. Su SQL se portó al motor único (incluida la clave canónica `site_code_key`, que calcula el adaptador y no la suite) sin tocar su criterio ni sus aserciones.
    * **El ejecutor declara su motor antes de correr nada:** nueva fase 0b con sonda de tres intentos; si el banco no responde, la batería **aborta con código 1** en lugar de omitir suites (EARS-13.6).
    * **La limpieza de cierre ya no usa el sembrador heredado:** la fase final purga y re-siembra con `SeedRunner` —que **ya escribe PostgreSQL**, hallazgo que reduce el tamaño de T-13-04— y se retiró de ella `DemoMetricsSeeder` (motor heredado y fuera del contrato del arnés; el histórico del despliegue entra por `DemoHistorySeeder`). La corrida terminó con «Base de datos restablecida: SÍ (Semillas intactas)».

#### Línea base medida tras el porte (2026-10-15)

Primera corrida completa de la batería contra el banco, que fija el trabajo de los lotes siguientes:

| Medida | Valor |
|---|---|
| Suites ejecutadas | **259** · tiempo 148,9 s |
| Aserciones | 7.855 |
| Verdes | **186** (unit PHP 106/113 · frontend ESM **66/66** intactas) |
| Rojas | **73** (integración 65 · unit 7 · E2E manual 1) |
| Desviaciones de cifras documentadas | 10 (esperadas: las cifras del README se actualizan en T-13-13) |
| Residuo del arnés | **0 sedes** · 10 sedes de negocio · 0 averías |

**Causas raíz de las 73 rojas**, por código del motor: `42601` error de sintaxis (identificadores entre acentos graves) **79** · `42703` columna inexistente **10** · `42883` función inexistente (`DATABASE()`, `DATE_ADD`) **8** · `45000` disparador del Art. III.1 mordiendo donde la suite no abrió la ventana **4** · `23514` restricción `CHECK` **4** · `22007` formato de fecha inválido **4** · `08006` sin conexión **4** · `42804` tipo incompatible **2**. El grueso es dialecto de suite (T-13-05) y el resto, semántica de esquema (enumerados, claves canónicas) que los lotes ya prevén.

**Hallazgo de contrato, CERRADO en esta tarea (2026-10-15):** el contrato de limpieza medía **sedes** del arnés por prefijo reservado y su subárbol, no lo que una suite cuelga de una sede **semilla** ni las cuentas que crea. El criterio es ahora la **identidad contra el catálogo canónico del sembrador** (`SeedRunner::canonicalIdentities()`, contrato de limpieza §8.1 y §8.5): lo que se siembra es lo que se protege. `countTestLocations()`/`purgeTestLocations()` se retiraron y en su lugar quedan `countParkResidue()`/`restorePark()`, más `registerShutdownRestitution()` como gancho único de las suites creadoras —que ahora son trece, incluidas las dos que sólo limpiaban en su camino feliz y por eso dejaban la fuga—. Y lo que el catálogo no puede situar por sí solo **se restituye antes de borrar**: `restituteCatalogMachines()` devuelve a su sede canónica la máquina del catálogo que una suite reubicó —`AdminMachinesEndpointTest` traslada `VEND-0101` y muere antes de devolverlo, y el sembrador no reescribe `location_id`—, y `restituteCatalogUsers()` reinicia el freno de intentos de las cuentas del catálogo —`UserLoginLockoutTest` bloquea `jordi.ruta@…` y muere antes de reiniciarlo, y la corrida siguiente fallaba con `ACCOUNT_LOCKED` en `AuthControllerTest`, que sólo inicia sesión—. El drenaje sólo aborta lo que el catálogo no puede situar.

Medido en vivo: el banco tenía la sede `SEDE-TEMP-97B8` y la cuenta `tecnico.nuevo.b27c@vendguard.internal`, invisibles al criterio retirado; la restitución las drenó y el parque volvió a 10 sedes, 13 máquinas y 6 cuentas, con `HarnessResidueGuardTest` en **25/25** (antes 14/14). Corrida de verificación posterior completa: **259 suites · 7.869 aserciones · 71 rojas** (antes 73), con el cierre de la batería en «Ninguna maestra quedó fuera del catálogo canónico» y el banco en su estado canónico —dos suites que fallaban (`CoordinatorMetricsEndpointTest`, `QrLabelHostResolutionIntegrationTest`) pasan a verde y ninguna nueva entra en rojo—.
- [x] **T-13-04: Llevar el sembrador del arnés a la ruta del motor único (eslabón crítico: 85 ficheros de prueba)**
  * **Requisitos:** EARS-13.4, EARS-13.3
  * **Dependencias:** T-13-03
  * **Hecho cuando:** El catálogo del arnés se siembra por la ruta del motor único —con `SeedRunner` adaptado o sustituido por el catálogo canónico más la generación de claves de centro de desarrollo (hallazgo S-4)—, su efecto está certificado en suite propia y los 85 ficheros que dependían de él siguen en verde.
  * **Cerrado (2026-10-15), con evidencia de ejecución:**
    * **La ruta ya estaba alcanzada; lo que faltaba era certificarla.** T-13-03 lo dejó medido —el sembrador escribe PostgreSQL por el adaptador, con las claves canónicas `site_code_key`/`email_key` y el upsert del motor, y el cierre de la corrida re-siembra con él— y este cierre aporta las dos piezas que su «hecho cuando» exigía: **suite propia** y la **medición de los 85 dependientes**. El censo se vuelve a medir en esta tarea: **85 ficheros** de `tests/` nombran el sembrador, de los que **79 lo instancian**; los 6 restantes lo citan para declarar su identidad canónica o su dialecto.
    * **Suite propia:** [`SeedRunnerSingleEngineTest`](../../tests/integration/SeedRunnerSingleEngineTest.php) — **20/20 aserciones, exit 0**. Certifica cuatro cosas: **(1) la ruta es la del motor único** (adaptador resuelto de la conexión viva, driver `pgsql`; upsert `ON CONFLICT … DO UPDATE` con `EXCLUDED.` y no la familia heredada; y el **fuente** del sembrador escaneado sin comentarios —tokenizado, para no confundir un docblock que cita sintaxis heredada con SQL— sin un solo identificador entre acentos graves ni familia privativa, más la comprobación de que construye su SQL con el adaptador); **(2) el catálogo que escribe es el canónico** (10 sedes activas, 13 máquinas cada una en su sede canónica, 6 cuentas, y el resto del catálogo: 5 frecuencias, base central, órdenes, certificados, checklist, repuestos y auditoría preventiva); **(3) idempotencia medida degradando** una sede a propósito —ficha alterada, baja lógica y freno de intentos disparado— y comprobando que el reencuentro la repara, la reactiva y la deja como una sola fila; **(4) claves de centro de desarrollo (S-4)**: la regla `DEV-<site_code>`, la puerta cerrada con `APP_ENV=production` y con `VENDGUARD_ENV=prod`, y la clave sembrada **abriendo sesión de sede por `AuthService::loginSite()`** —mientras una ajena no la abre—.
    * **La guarda muerde, y está medido:** sobre `database/cloud_init.sql` —artefacto heredado real— el escaneo de dialecto detecta **5 de las 7 familias**; el adaptador de MySQL renderiza `ON DUPLICATE KEY … VALUES(…)` y el del motor único pasa la aserción del upsert; y con una sede degradada **sin sembrar** las dos aserciones de reparación evalúan en falso, pasando a verde tras la siembra. Una guarda que no puede fallar no certifica nada.
    * **Los 85 dependientes, medidos por diferencia (corrida completa antes y después):** antes **259 suites · 8.466 aserciones · 63 rojas** (5 unitarias · 57 de integración · 1 E2E). Después, con la suite nueva dentro: **260 suites · 8.486 aserciones · 63 rojas**, y el **conjunto de suites rojas es idéntico** —ningún dependiente del sembrador cambia de veredicto, ninguna suite verde pasa a roja—. El cierre sigue declarando el parque canónico y el residuo en cero. *Nota de estabilidad:* el total de aserciones oscila entre corridas (una previa midió 8.463) porque lo que una suite que muere temprano no llega a evaluar no se cuenta; lo comparable es el conjunto de rojas, no el total.
    * **Defecto de dependiente encontrado al medir, con causa medida y reparada:** `LocationPortalReopenAuditTest` calculaba sus instantes en **hora local** (`new DateTimeImmutable()->sub(… PT50H)`) y los escribía en `incidents.resolved_at`, que es `timestamptz` con sesión UTC. Con la máquina en UTC+2, «50 h locales» se reinterpretaban como **48 h exactas**: la ventana de los 48 h caía justo en el borde y el veredicto dependía del segundo en que corriera la suite —verde en la línea base, roja minutos después, con `isInWarranty(48)` midiendo 172.800 s exactos y respondiendo `200` donde el caso exige `422`—. La suite calcula ahora sus tres instantes en **UTC** (EARS-13.10: la divergencia se resuelve a favor del contrato del motor único, cuya columna lleva zona horaria) y el caso es determinista: **18/18 aserciones, exit 0**.
    * **Hallazgo de clase declarado, fuera del alcance de esta tarea:** el mismo patrón (instantes en hora local hacia columnas con zona horaria) vive en dos suites más. En `CoordinatorIncidentsEndpointTest` explica **4 de sus 6 fallos** —los minutos de espera y el `sla_breached` salen cortos porque el instante retroactivo se desplaza el huso—; en `ReopenIncidentEndpointTest` el patrón existe pero su rojo es de otra familia (violación de `chk_resolution_diagnosis_len`), que pertenece al lote de reapertura. Queda anotado para el lote que los toque: reparar el huso en una suite no arregla el otro defecto, y mezclarlos ocultaría el segundo.
    * **Cifras documentadas:** la guarda declara ahora **12 desviaciones** (antes 10): las diez heredadas más las dos que aparecen porque el README afirma una batería de 259 suites y la corrida ejecuta 260 (`Total Suites Ejecutadas` y el árbol de directorios). No se corrigen aquí: son las cifras del README y de los informes de cierre, y se actualizan de una vez en T-13-13, cuando el saldo de rojas deje de moverse; moverlas en cada lote sería escribirlas dos veces.
- [ ] **T-13-05: Reescribir los ficheros de prueba con SQL privativo del motor heredado**
  * **Requisitos:** EARS-13.4, EARS-13.10
  * **Dependencias:** T-13-03
  * **Hecho cuando:** los 14 ficheros con constructos privativos ejecutados y el soporte del arnés (`TestDataCleaner`) escriben SQL del motor único, sin un solo identificador entre acentos graves, `ON DUPLICATE KEY`, `DATE_ADD` ni `JSON_EXTRACT`; y las 73 menciones en contexto SQL quedan revisadas una a una —las guardas que vigilan el dialecto se invierten (T-13-06) y las suites que ya ejercen PostgreSQL no se tocan—.
  * **Lote 1 medido (2026-10-15) — identificadores entre acentos graves, con evidencia de ejecución:**
    * **Alcance y forma:** **422 literales de SQL ejecutable** con identificadores entre acentos graves portados a la forma desnuda en **46 ficheros de `tests/`**. El porte es por tokens: comentarios y docblocks quedan intactos, de modo que la documentación que cita sintaxis heredada no se reescribe. `src`, `database` y `bin` quedan fuera a propósito: el ratchet de dialecto ([`SqlDialectPortabilityTest`](../../tests/unit/SqlDialectPortabilityTest.php)) rastrea su saldo exacto y sus tres excepciones pendientes están declaradas con dueño.
    * **Lo que no se tocó, y por qué —declarado por la propia herramienta, no por omisión—:** 35 literales que **además** declaran familia privativa (`ON DUPLICATE KEY UPDATE`, `DATE_SUB`, `JSON_EXTRACT`, `->>`), 8 expectativas de texto que comparan contra el contenido de los `.sql` heredados (`RouteMapMigrationContractTest`, `RefundsModuleClosureTest` —ambas aceptan las dos formas), los 7 literales que renderizan el dialecto a propósito en el guardián, 1 plantilla `sprintf` de identificadores dinámicos —arreglada a mano en `IncidentPauseServiceTest`— y un `SHOW COLUMNS` de `AuditEntityTypesTest`, cuyo defecto no es la cita: la sentencia no existe en el motor único.
    * **Medido (`php tests/run_all.php`, corrida completa):** **259 suites · 8.463 aserciones** (antes 7.869: las suites alcanzan a ejecutar lo que antes moría en su primera sentencia) · rojas **71 → 64** · integración **16/79 → 22/79** · unitarias PHP **106/113 → 107/113** · `42601` **79 → 22** · **cero regresiones** —ninguna suite verde pasó a roja en dos corridas independientes y coincidentes— y **siete suites sanadas**: `AuditActionCatalogTest`, `AdminLocationsEndpointTest`, `CoordinatorCommentsApiTest`, `IncidentCommentsConstitutionalTest`, `PdoRefundRepositoryTest`, `SeedDataTest`, `TechnicianCommentsApiTest`.
    * **La deuda que el lote deja a la vista**, ya sin la cita heredada de por medio: `42883` 8 → 26 (entre ellas `operator does not exist: boolean = integer`), `42804` 2 → 12, `42703` 10 → 11, `22P02` 0 → 6, `42P01` 0 → 2. Los 22 `42601` restantes señalan a constructos declarados fuera del lote —la ventana de purga heredada `SET @vendguard_purge`, `DATE_SUB(… INTERVAL …)`, el upsert y los operadores JSON— y ninguna cita de identificador suelta sobrevive fuera de un literal que declare otra familia.
    * **Defecto del arnés descubierto y corregido en el camino:** `runTestProcess()` drenaba `stdout` hasta el fin de flujo y **sólo después** tocaba `stderr`, así que una suite que escribiera por el canal de errores más de un búfer de tubería se quedaba bloqueada escribiendo mientras el runner la esperaba leyendo, y la batería entera se colgaba sin plazo ni aviso. Medido sobre `LocationRefundDeliveryApiTest` —203 trazas del mismo expediente, **646.899 bytes** por `stderr`—: con tuberías la corrida **no termina** (45 s bloqueada, 3.197 bytes entregados) y con ficheros temporales termina en **2,4 s**. [`tests/run_all.php`](../../tests/run_all.php) recoge ahora las dos salidas en ficheros.
- [ ] **T-13-06: Invertir el inventario de portabilidad de dialecto**
  * **Requisitos:** EARS-13.4, EARS-13.10
  * **Dependencias:** T-13-05
  * **Hecho cuando:** [`SqlDialectPortabilityTest.php`](../../tests/unit/SqlDialectPortabilityTest.php) **prohíbe** el dialecto heredado y exige el del motor único (en lugar de inventariar las excepciones toleradas), y el contrato que lo respalda queda escrito.

---

## Fase 3 · Porte de la batería por lotes verdes

Cada lote cierra con `php tests/run_all.php` en verde, cifras por lote anotadas y las cifras documentadas actualizadas en el mismo commit ([`DocMetricsGuard.php`](../../tests/Support/DocMetricsGuard.php)).

- [ ] **T-13-07: Lote dominio y repositorios** — *Dependencias:* T-13-04, T-13-05, T-13-06. *Hecho cuando:* las suites de dominio, de estado de incidencia y de repositorio PDO corren verdes sobre el motor único.
- [ ] **T-13-08: Lote de endpoints por rol (coordinador, técnico, sede y QR)** — *Dependencias:* T-13-07. *Hecho cuando:* las suites de endpoints de los cuatro roles corren verdes, incluidos los flujos de alta, triaje, asignación, resolución, cancelación y reapertura.
- [ ] **T-13-09: Lote preventivo y repuestos** — *Dependencias:* T-13-07.
- [ ] **T-13-10: Lote de reintegros y custodia** — *Dependencias:* T-13-07.
- [ ] **T-13-11: Lote de comentarios y pausas (SLA y reloj sanitario)** — *Dependencias:* T-13-07.
- [ ] **T-13-12: Lote de ruta, mapa y barrido sanitario** — *Dependencias:* T-13-07.
- [ ] **T-13-13: Lote de esquema, despliegue y E2E manual** — *Dependencias:* T-13-08 a T-13-12. *Hecho cuando:* el E2E de los tres perfiles, las guardas de despliegue y el verificador manual corren verdes sobre el motor único y las cifras documentadas están coherentes.

---

## Fase 4 · Retirada del camino heredado

- [ ] **T-13-14: Factoría de conexión de una sola rama**
  * **Requisitos:** EARS-13.1, EARS-13.2
  * **Dependencias:** T-13-13
  * **Hecho cuando:** [`ConnectionFactory`](../../src/Infrastructure/Database/ConnectionFactory.php) no conserva rama, valor por defecto, credencial ni variable del motor heredado; `MigrationRunner` se retira a favor de `PostgresMigrationRunner`; `PdoLockWaitBudget` conserva solo su ruta PostgreSQL; y la suite de la factoría sigue verde.
- [ ] **T-13-15: Arranque, scripts de operación e imagen con un solo driver**
  * **Requisitos:** EARS-13.3, EARS-13.9
  * **Dependencias:** T-13-14
  * **Hecho cuando:** [`bin/init_cloud_db.php`](../../bin/init_cloud_db.php) tiene una sola ruta de arranque (DDL base, migraciones, catálogo, histórico de demostración de EARS-02.6) y su salida no nombra dos motores; `bin/migrate.php` usa el ejecutor del motor único; `bin/seed.php` y `bin/seed_demo_metrics.php` se retiran; el [`Dockerfile`](../../Dockerfile) instala solo `pdo_pgsql`.
- [ ] **T-13-16: Retirada del DDL, las migraciones y los sembradores heredados; contrato de esquema único**
  * **Requisitos:** EARS-13.3, EARS-13.9
  * **Dependencias:** T-13-15
  * **Hecho cuando:** **antes** de retirar nada se certifica que cada `CREATE TABLE` y cada `ADD COLUMN` de los 16 ficheros de `database/migrations/` está absorbido por `database/supabase_init.sql`, se declara por escrito que la convergencia futura del motor único vive en `supabase_migrations/`, y solo entonces se retiran `database/cloud_init.sql`, `database/schema.sql`, `database/seeds.sql`, `database/migrations/**` y `database/DemoMetricsSeeder.php`; el contrato de correspondencia pasa a ser contrato de esquema del motor único; `generated_columns_contract.md` (D-6) se retira; `database_schema.md` se reescribe; y ninguna suite referencia artefactos retirados.

---

## Fase 5 · Documentación viva y rollback

- [ ] **T-13-17: Enmendar la documentación vigente y redefinir el rollback**
  * **Requisitos:** EARS-13.9, criterios 5 y 6 de aceptación
  * **Dependencias:** T-13-16
  * **Entrada:** [`docs/auditoria_documentacion_motor_unico.md`](../../docs/auditoria_documentacion_motor_unico.md) — el inventario cerrado: 14 documentos vivos con su línea exacta y su redacción de destino, 33 históricos preservados y el orden de enmienda.
  * **Hecho cuando:** el patrón de búsqueda de la auditoría devuelve **cero documentos vivos** que nombren el motor heredado; [`README.md`](../../README.md) declara una sola ruta de arranque y una sola forma de levantar desarrollo; [`AGENTS.md`](../../AGENTS.md) §4 declara PostgreSQL (Supabase) con el visto bueno del Product Owner; el «rollback en un minuto» del motor heredado se sustituye por el procedimiento de restauración de copia y *point-in-time* de Supabase; y las actas históricas quedan intactas.

---

## Fase 6 · Recertificación y cierre

- [ ] **T-13-18: Recertificar los dos canales y cerrar el módulo**
  * **Requisitos:** EARS-13.5, EARS-13.8, criterios 1 a 6 de aceptación
  * **Dependencias:** T-13-17
  * **Hecho cuando:** la batería hermética completa corre verde **sin red** sobre el motor PostgreSQL local con cifras documentadas coherentes; el canal remoto certifica contra Supabase (convergencia de esquema, blindaje constitucional, TLS y presupuesto de latencia de RNF-01); el arranque desplegado carga el catálogo y el histórico de demostración con EARS-02.6 intacto; y el acta del módulo recoge las cifras de ambos canales y las divergencias resueltas (EARS-13.10), si las hubo.
