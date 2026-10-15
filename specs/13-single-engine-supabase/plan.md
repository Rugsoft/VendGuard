# MÓDULO 13 · MOTOR ÚNICO: SUPABASE (POSTGRESQL) SIN CAMINO HEREDADO (PLAN.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)
**Módulo:** `13-single-engine-supabase`
**Documento:** `specs/13-single-engine-supabase/plan.md`
**Especificación:** [`spec.md`](spec.md) — **Tareas:** [`tasks.md`](tasks.md)
**Decisiones del Product Owner (2026-10-15):** motor de la batería = **PostgreSQL local solo para pruebas**; camino MariaDB = **retirada completa**.

---

## 1. Inventario real medido en el repositorio (no estimado)

| Superficie | Dato | Ficheros |
|---|---|---|
| Suites que resuelven su motor con los valores por defecto de la factoría | 94 | `tests/**` (79 de integración + unitarias con base de datos + E2E manual) |
| Suites que ya declaran motor PostgreSQL | 6 | categoría `new_engine` |
| Ficheros de prueba con **constructos privativos ejecutados en SQL** —patrón: `ON DUPLICATE KEY`, `DATE_ADD(`, `JSON_EXTRACT(`, `DATEDIFF(`, `STR_TO_DATE(`, `GROUP_CONCAT(`, `IFNULL(`, `VALUES()`— | **14** (12 de integración + 2 unitarias) | `CoordinatorPreventiveDetailApiTest`, `CoordinatorRouteMapApiTest`, `HarnessResidueGuardTest`, `IncidentCommentsPagedRepositoryTest`, `PdoLocationRepositoryTest`, `PdoUserRepositoryTest`, `PendingInfoConstitutionalTest`, `ResolveIncidentEndpointTest`, `SchemaConvergenceParityTest`, `SiteManagerRouteDataSegregationTest`, `TechnicianRouteEndpointTest`, `TechnicianRouteMapApiTest`, `RouteMapMigrationContractTest`, `SqlDialectPortabilityTest` |
| Ficheros de prueba que además escriben **identificadores entre acentos graves** en contexto SQL (superconjunto textual: incluye las guardas que *vigilan* el dialecto y las suites que ya ejercen PostgreSQL) | **73** (53 de integración, 16 unitarias, más el soporte del arnés, su arranque y el diagnóstico) | `TestDataCleaner`, `bootstrap.php`, `ConnectionFactoryTest`, `CloudDeploySchemaParityTest`, `PdoMachineRepositoryTest`, `PostgresConstitutionalTest`, `PendingInfoApiTest`, `TechnicianCommentsApiTest`… (inventario reproducible con el patrón del plan) |
| Ficheros de prueba que usan el sembrador del arnés | **85** | `SeedRunner` es el eslabón crítico del porte |
| Ficheros de `src/` que referencian el motor heredado | **27** (acoplamiento real en `ConnectionFactory`, `SqlDialectAdapter`, `MigrationRunner`, `SeedRunner`, `PdoLockWaitBudget`, `PdoTransactionManager`, `SqlScriptSplitter` y 11 repositorios; el resto, comentarios) | `src/**` |
| DDL, migraciones y sembradores del motor heredado | `cloud_init.sql` (63 KB), `schema.sql`, `seeds.sql`, `database/migrations/**` (**16** ficheros), `DemoMetricsSeeder.php` | `database/` |
| Esquema canónico del motor único | `supabase_init.sql` (70 KB, esquema completo) + `supabase_migrations/` (**una** migración: `001_initial_baseline.sql`) + `supabase_seeds.sql` | `database/` |
| Scripts de arranque del motor heredado | `bin/migrate.php`, `bin/seed.php`, `bin/seed_demo_metrics.php` (+ la rama MySQL de `bin/init_cloud_db.php`) | `bin/` |
| Imagen | `pdo_mysql` instalado junto a `pdo_pgsql` (línea 8 de [`Dockerfile`](../../Dockerfile)) | `Dockerfile` |
| Documentación que menciona el motor | **27** documentos entre [`README.md`](../../README.md), [`AGENTS.md`](../../AGENTS.md), `docs/` y `specs/technical/`; de ellos se **enmiendan** los vigentes (README, AGENTS.md §4, guías y contratos técnicos) y se **preservan** las actas históricas | 27 ficheros |
| Documentación **histórica** (se preserva) | actas de cierre, auditorías e informes que narran el dualismo | ~40 ficheros |

**Estado del banco de la batería:** el motor PostgreSQL local (`deploy/local/postgres/`) no sirve TLS —el nativo tiene `ssl = off` y activarlo exigiría superusuario y elevación sobre una instalación del sistema—, así que el banco es el **motor TLS en contenedor** (puerto 55433) que el repositorio ya aprovisiona para la categoría. El 2026-10-15 (T-13-02) quedó aprovisionado con el inicializador canónico del repositorio y con **base propia**: `vendguard_battery`, distinguida de `vendguard_pg_test` de la categoría (el arnés crea sedes, máquinas y averías en cada corrida y contaminaría la base sobre la que la categoría mide el catálogo), en arranque en limpio estricto (`VENDGUARD_DEMO_HISTORY=0`, porque las `INC-DEMO-*` ocupan máquina durante su ventana de garantía de 48 h y colisionarían con las fixtures del arnés). Contrato: `deploy/local/postgres/.env.battery` con `DATABASE_URL` en forma `verify-full`.

**Lectura del inventario:** las suites que **no** tocan dialecto —van por repositorios y por el adaptador— son la inmensa mayoría; el porte se concentra en los 14 ficheros con constructos privativos ejecutados, en el sembrador del arnés y en el banco de pruebas. La diferencia entre 14 y 73 mide cuánto texto *menciona* el dialecto frente a cuánto lo *ejecuta*: solo lo segundo obliga a reescribir SQL.

**Hallazgo que condiciona la retirada del DDL heredado:** la capa incremental del motor único tiene hoy **una sola migración** (la línea base). Los 16 ficheros de `database/migrations/` cubren los módulos 3 a 18 y **están ya absorbidos** por `supabase_init.sql`: comprobado elemento a elemento —cada `CREATE TABLE` y cada `ADD COLUMN` de los 16 ficheros aparece en el DDL canónico del motor único—. Conclusión para la Fase 4: retirarlos no pierde esquema, pero **toda columna futura tiene que declararse en `supabase_migrations/`**, porque el DDL base es de creación y no converge por sí solo (EARS-02.4).

---

## 2. Estrategia

**Regla de oro del módulo: nunca abrir una ventana sin certificación.** La rama heredada no se borra hasta que la batería completa esté verde sobre PostgreSQL. No hay un solo commit donde el repositorio se quede sin canal hermético.

### Fase 1 · Enmienda y puerta de aprobación (este documento)

Entregables: `spec.md`, `plan.md`, `tasks.md`, la nota de enmienda en la especificación del módulo 12 y el puntero en `specs/functional/`. **No se escribe código de producción.** Puerta: confirmación del Product Owner.

### Fase 2 · El banco de la batería y los eslabones críticos

1. **Aprovisionamiento canónico del banco:** el motor PostgreSQL local recibe el esquema y el catálogo con el inicializador del repositorio (idempotente), de modo que la batería tenga banco sin depender de la red. Se documenta en [`deploy/local/postgres/README.md`](../../deploy/local/postgres/README.md).
2. **Bootstrap del arnés de una sola forma:** [`tests/bootstrap.php`](../../tests/bootstrap.php), [`tests/run_all.php`](../../tests/run_all.php) y [`TestDataCleaner`](../../tests/Support/TestDataCleaner.php) resuelven el motor único y abren la señal de purga en su única forma. La guarda de residuo de fin de corrida se conserva tal cual.
3. **Sembrador del arnés (el eslabón crítico, 85 dependientes):** `SeedRunner` pasa a escribir por la ruta del motor único —o se sustituye por el catálogo canónico más la lógica de claves de centro de desarrollo (hallazgo S-4)—, y su efecto queda certificado antes de tocar una sola suite más.
4. **Los ficheros con SQL privativo:** se reescriben contra el motor único. `SqlDialectPortabilityTest` **se invierte**: pasa de inventariar el dialecto privativo tolerado a prohibir el dialecto heredado y exigir el del motor único.

### Fase 3 · Porte de la batería por lotes verdes

La corrida completa se hace por lotes —dominio y repositorios, endpoints por rol, preventivo, reintegros, comentarios, ruta y mapa, sanitario, esquema y despliegue— con **cifras por lote** en el acta del módulo. Se ejecuta `php tests/run_all.php` en cada cierre de lote y se actualizan las cifras documentadas ([`DocMetricsGuard.php`](../../tests/Support/DocMetricsGuard.php)) en el mismo commit.

### Fase 4 · Retirada del camino heredado

Con la batería verde en el motor único:

* **Aplicación:** `ConnectionFactory` queda con una sola rama y su política de credenciales; desaparecen `MigrationRunner` (sustituido por `PostgresMigrationRunner`), las rutas MySQL de `PdoLockWaitBudget` y el DSN del motor heredado.
* **Arranque:** `bin/init_cloud_db.php` pierde su rama MySQL; `bin/migrate.php` se reapunta al ejecutor del motor único; `bin/seed.php` y `bin/seed_demo_metrics.php` se retiran (su trabajo lo hace el inicializador canónico y el histórico de demostración de EARS-02.6).
* **Datos y esquema:** se retiran `database/cloud_init.sql`, `database/schema.sql`, `database/seeds.sql`, `database/migrations/**` y `database/DemoMetricsSeeder.php`.
* **Imagen:** el [`Dockerfile`](../../Dockerfile) instala solo `pdo_pgsql`.
* **Contratos:** [`schema_correspondence_contract.md`](../technical/schema_correspondence_contract.md) se convierte en el contrato de esquema del motor único; [`generated_columns_contract.md`](../technical/generated_columns_contract.md) (D-6) se retira con el índice compuesto generado; [`database_schema.md`](../technical/database_schema.md) se reescribe al esquema del motor único.

### Fase 5 · Documentación viva y rollback

El inventario de esta fase ya está cerrado en [`docs/auditoria_documentacion_motor_unico.md`](../../docs/auditoria_documentacion_motor_unico.md): 14 documentos vivos con su línea exacta y su redacción de destino, 33 históricos que se preservan y el orden de enmienda. Solo se aplica cuando el hecho sea cierto (código ya retirado), y su cierre se verifica repitiendo el patrón de búsqueda de la auditoría: cero vivos.

* [`README.md`](../../README.md): una sola ruta de arranque y una sola forma de levantar un entorno de desarrollo (§3 de dudas abiertas).
* [`AGENTS.md`](../../AGENTS.md) §4: la base de datos declarada pasa a ser PostgreSQL (Supabase); la enmienda se aplica con el visto bueno del Product Owner.
* **Rollback redefinido:** desaparece «quitar `DATABASE_URL`» y en su lugar se documenta la restauración de copia y el *point-in-time* de Supabase, con el procedimiento concreto.
* **Actas históricas:** intactas.

### Fase 6 · Recertificación y cierre

Batería hermética completa sobre PostgreSQL local + canal remoto contra Supabase (convergencia, blindaje constitucional, TLS y presupuesto de latencia) + arranque desplegado con el histórico de demostración de EARS-02.6, todo con cifras en el acta.

---

## 3. Riesgos y medidas (los que cambian decisiones, no los obvios)

| Riesgo | Medida |
|---|---|
| El sembrador del arnés (85 dependientes) arrastra el porte | Se aborda **primero**, con suite propia en verde, antes de tocar el resto |
| El banco de la batería comparte motor con las suites de la categoría | La batería tiene su banco; la categoría conserva su esquema aislado; la guarda de residuo sigue midiendo al final de cada corrida |
| Coste de aprovisionamiento por suite (DDL de 100+ sentencias) | Un aprovisionamiento idempotente por corrida; esquema aislado solo donde el aislamiento sea el objeto de la prueba |
| Divergencias reales de comportamiento entre motores aparecidas al portar | Se resuelven a favor del contrato del motor único (EARS-13.10) y se documentan, en lugar de conservarse como paridad |
| La retirada rompe el desarrollo local sin configuración | Se resuelve en la duda §8.1 de la especificación **antes** del borrado |
| El borrado deja referencias en documentación viva | Guarda de repositorio: ninguna referencia a MariaDB/MySQL en `src/`, `bin/` ni `database/`, y documentación viva enmendada (criterios 2 y 6 de aceptación) |

---

## 4. Evidencia por fase (qué se mide y dónde queda)

| Fase | Evidencia |
|---|---|
| 2 | Banco aprovisionado (guarda de esquema y catálogo) · suite del sembrador del arnés en verde · inventario invertido del dialecto |
| 3 | `run_all.php` en verde por lote, con cifras por lote y guarda de residuo limpia |
| 4 | Inventario cero (`src/`, `bin/`, `database/` sin referencias al motor heredado) · arranque de una sola rama · imagen con un solo driver |
| 5 | Documentación viva enmendada · procedimiento de rollback con su primera prueba documentada |
| 6 | Batería completa + canal remoto + arranque desplegado, con cifras en el acta del módulo |

---

## 5. Lo que este módulo decide no hacer

* No migra datos: el despliegue ya está en Supabase.
* No cambia de proveedor ni de topología de nube.
* No reescribe la historia del repositorio (actas, auditorías e informes del dualismo se conservan).
* No aprovecha para rediseñar el esquema: la única intervención sobre él es la retirada del DDL heredado y la conversión del contrato de correspondencia en contrato de esquema único.
