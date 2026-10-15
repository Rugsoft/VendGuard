# MÓDULO 13 · MOTOR ÚNICO: SUPABASE (POSTGRESQL) SIN CAMINO HEREDADO (SPEC.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)
**Módulo:** `13-single-engine-supabase`
**Documento:** `specs/13-single-engine-supabase/spec.md`
**Enmienda de origen:** instrucción expresa del Product Owner (2026-10-15): «solo trabajaremos con Supabase, nada de MariaDB»
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII)
**Directrices Operativas:** [`AGENTS.md`](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)
**Módulo precedente:** [`specs/12-hybrid-supabase-migration/`](../12-hybrid-supabase-migration/spec.md) (migración híbrida, hoy cerrada)
**Estimación por Tarea:** 20–30 minutos

---

## 1. Contexto y problema

El módulo 12 dejó **dos motores con contrato equivalente**: MariaDB/MySQL como motor local (desarrollo y arnés hermético) y PostgreSQL —Supabase— como motor del despliegue. La simetría se sostuvo con un adaptador de dialecto explícito (EARS-01.5), un contrato de correspondencia de esquema ([`schema_correspondence_contract.md`](../technical/schema_correspondence_contract.md)), un inventario de dialecto privativo y una doble ejecución de la certificación. Eso tuvo sentido mientras hubo que *migrar*.

Terminada la migración, el dualismo solo cuesta:

* **Dos verdades de esquema.** Toda columna nueva exige escribirse dos veces (DDL base y migración del motor local, más el DDL del motor nuevo) y el olvido de una de las dos es exactamente el defecto que provocó el incidente de esquema desincronizado del 2026-10-03.
* **Una clase entera de defectos por sintaxis.** `ON DUPLICATE KEY UPDATE` frente a `ON CONFLICT ... DO UPDATE`, `VALUES()` frente a `EXCLUDED`, `JSON_EXTRACT` frente a `->>`, columnas generadas frente a índices únicos parciales: cada uno es una oportunidad de divergencia que hay que certificar con suites propias.
* **Un despliegue que arrastra código muerto.** La imagen instala `pdo_mysql`; el arranque lleva una rama MySQL completa (`database/cloud_init.sql` + 16 migraciones + `SeedRunner` + `DemoMetricsSeeder`) que **no se ejecuta** en el despliegue vigente.
* **Un rollback engañoso.** La guía promete «quitar `DATABASE_URL` y volver a MariaDB»; un retorno así devolvería la aplicación a un esquema que ya no evoluciona.

El Product Owner ha declarado que el proyecto se trabaja **solo contra Supabase**. Este módulo convierte esa decisión en contrato: retira el camino heredado por completo y deja **un solo motor**, conservando una batería hermética que no depende de la red.

---

## 2. Objetivo

Que **PostgreSQL sea el único motor** del sistema —en producción, en el arnés de certificación y en el desarrollo— sin que la certificación pierda cobertura ni se convierta en una prueba contra la base desplegada.

---

## 3. Alcance

### 3.1 Dentro del alcance

1. **Un solo motor en la aplicación:** la factoría de conexión conserva únicamente la rama PostgreSQL y su política de credenciales (EARS-13.2).
2. **Un solo esquema canónico:** `database/supabase_init.sql` + `database/supabase_migrations/` + `database/supabase_seeds.sql` son la única fuente de verdad del esquema y del catálogo. Nota medida: la capa incremental del motor único tiene hoy **una sola migración** (la línea base) y los 16 ficheros de `database/migrations/` del motor heredado están **ya absorbidos** por `supabase_init.sql` —comprobado elemento a elemento—; por eso la retirada no pierde esquema, pero **toda columna futura deberá declararse en `supabase_migrations/`** (EARS-02.4: el DDL base crea, no converge).
3. **Batería hermética sobre PostgreSQL local** (decisión del Product Owner, 2026-10-15): el motor de `deploy/local/postgres/` es el banco de la batería; la ausencia de red se conserva (RNF-04).
4. **Retirada completa del camino heredado** (decisión del Product Owner, 2026-10-15): código, DDL, migraciones, sembradores, scripts y documentación de arranque en MariaDB.
5. **Porte del arnés:** las suites que hoy resuelven su motor con valores por defecto de MySQL pasan a resolverlo contra PostgreSQL, con el mismo aislamiento y el mismo contrato de limpieza.
6. **Enmienda de los requisitos vigentes** que presuponen dos motores (§4).

### 3.2 Fuera del alcance

* **Reabrir la migración de datos:** el trasvase de históricos sigue cerrado (módulo 12, §7). No se migra nada nuevo: el despliegue ya está en Supabase.
* **Cambiar de proveedor:** Supabase sigue siendo el motor del despliegue; este módulo no elige ni sustituye proveedor.
* **Reescribir el pasado:** las actas de cierre, auditorías e informes que documentan el dualismo **se conservan tal cual**: son evidencia de lo que ocurrió, no documentación viva. Solo se enmienda la documentación *vigente*.

---

## 4. Requisitos

### RF-13.1: Motor único en la aplicación

* **EARS-13.1 (Ubicuo):** El sistema **siempre** deberá resolver su persistencia contra PostgreSQL. La factoría de conexión rechazará cualquier configuración que no declare el motor PostgreSQL y no conservará rama, valor por defecto ni credencial del motor heredado.
* **EARS-13.2 (No Deseado / Excepción):** **SI** el entorno no declara credenciales explícitas del motor único, **ENTONCES** la aplicación deberá fallar en cerrado antes de intentar conectar, nombrando la variable ausente. (Hoy esta política ya existe para PostgreSQL: se conserva y se extiende a la desaparición del camino alternativo.)

### RF-13.2: Un único esquema canónico

* **EARS-13.3 (Ubicuo):** El esquema desplegado **siempre** deberá converger mediante `database/supabase_init.sql` y el ejecutor de migraciones del motor único; desaparece el segundo juego de DDL y migraciones.
* **EARS-13.4 (Ubicuo):** El catálogo maestro, los datos de demostración y cualquier siembra de desarrollo o de arnés **deberán** escribirse por la ruta del motor único, con identificadores desnudos y la cláusula de upsert que resuelve el adaptador, sin SQL privativo fuera de él.

### RF-13.3: Batería hermética sin red sobre el motor único

* **EARS-13.5 (Ubicuo):** La batería hermética **siempre** deberá ejecutarse sin red contra el motor PostgreSQL local aprovisionado en `deploy/local/postgres/`, con la misma cobertura efectiva que hoy (suites unitarias, de integración y E2E de interfaz), y **nunca** contra la base desplegada.
* **EARS-13.6 (No Deseado / Excepción):** **SI** el motor local no responde, **ENTONCES** la batería deberá declararlo en voz alta y salir con código distinto de cero, sin omitir suites en silencio y sin degradar la corrida a verde.
* **EARS-13.7 (Dirigido por Estado):** **MIENTRAS** la batería se ejecute, el banco de pruebas deberá dejar el motor en un estado conocido: sin residuo de sus sedes, máquinas, usuarios ni averías, y con el catálogo sembrado intacto.

### RF-13.4: Canal remoto de Supabase

* **EARS-13.8 (Ubicuo):** La certificación del contrato del motor remoto **deberá** conservarse en su canal propio —TLS con verificación de identidad, agrupador transaccional, convergencia de esquema y presupuesto de latencia (RNF-01)— y seguir informándose por separado de la batería hermética.

### RF-13.5: Retirada sin pérdida de trazabilidad

* **EARS-13.9 (Dirigido por Evento):** **CUANDO** se complete la retirada, **el sistema** no deberá contener ningún artefacto de ejecución del motor heredado —DDL, migraciones, sembradores, scripts de arranque ni extensión de driver en la imagen— y la documentación vigente deberá declarar un solo motor, preservando las actas históricas.
* **EARS-13.10 (No Deseado / Excepción):** **SI** durante el porte una suite descubre una divergencia real de comportamiento entre motores, **ENTONCES** la divergencia se resolverá a favor del contrato del motor único y se documentará en el acta del módulo, en lugar de conservarse como excepción de paridad.

### 4.1 Enmiendas a requisitos vigentes (módulo 12 y §8 de su especificación)

Estas enmiendas **no se aplican en silencio**: se ejecutan al aprobarse este módulo y se anotan en el documento enmendado (AGENTS.md §5.5).

| Requisito vigente | Qué presupone hoy | Queda como |
|---|---|---|
| EARS-01.1 / EARS-01.5 | «Una sola definición de cada regla y **dos traducciones** de su sintaxis» | Una sola definición y **una sola sintaxis**; el adaptador deja de traducir entre motores y se reduce a los constructos que el motor único necesita |
| EARS-01.6 | Política de credenciales «idéntica a los dos drivers» | Política única del motor único (EARS-13.2) |
| EARS-02.1 / EARS-02.2 | Arranque en limpio y fixtures del arnés en **dos** motores | Ambos en el motor único (EARS-13.3 y EARS-13.4) |
| EARS-02.4 | Convergencia «mediante un ejecutor de migraciones **del motor activo**» | Convergencia mediante el ejecutor del motor único |
| EARS-01.3 y §8 criterio 1 | Dos categorías: batería hermética local (MariaDB) y categoría «motor nuevo» (PostgreSQL) | Batería hermética sobre **motor PostgreSQL local** y **canal remoto** de Supabase (EARS-13.5 y EARS-13.8) |
| §8 criterio 2 | Blindaje constitucional certificado en **los dos** motores, con la señal de purga «en la forma del motor activo» | Certificado en el motor único, con una sola forma de la señal |
| §8 criterio 6 | La imagen instala `pdo_pgsql` **junto a** `pdo_mysql` | La imagen instala solo `pdo_pgsql` |
| §8 criterio 7 y contrato de correspondencia | Correspondencia declarada entre los dos esquemas, con las diferencias aceptadas D-2/D-6/D-9 | **Contrato de esquema del motor único**: desaparece la correspondencia entre motores y, con ella, las diferencias que existían solo para puentearlos |
| RNF-04 | Batería hermética «sin red contra el motor local» | **Se mantiene en su sustancia**: sin red, contra el motor local, que ahora es PostgreSQL |

---

## 5. Topología de motores tras este módulo

| Entorno | Motor | Contrato |
|---|---|---|
| **Producción (Render)** | Supabase (PostgreSQL) por `DATABASE_URL` | TLS `verify-full` con CA declarada, agrupador transaccional, credenciales explícitas (EARS-13.1 y 13.2) |
| **Batería hermética** | PostgreSQL local (`deploy/local/postgres/`) | Sin red, tres intentos fallidos = rojo (EARS-13.5 y 13.6), sin residuo (EARS-13.7) |
| **Canal remoto** | Supabase | Latencia (RNF-01), TLS, convergencia y blindaje (EARS-13.8) |
| **Desarrollo de la aplicación** | **Duda abierta §8.1:** proyecto Supabase de desarrollo o motor TLS en contenedor | La aplicación exige `verify-full`, de modo que el motor local de la batería (TCP plano) no puede servirle |

---

## 6. Criterios de aceptación

El módulo se considerará satisfecho cuando:

1. **Batería hermética en verde sobre PostgreSQL local**, con cobertura equivalente a la vigente y `php tests/run_all.php` terminando en verde con las cifras documentadas coherentes ([`DocMetricsGuard.php`](../../tests/Support/DocMetricsGuard.php)).
2. **Cero rastro del motor heredado en la ejecución:** ninguna referencia a MariaDB/MySQL en `src/`, `bin/` ni `database/`, ningún DDL ni migración del motor heredado, ninguna extensión `pdo_mysql` en la imagen.
3. **Un solo arranque:** `bin/init_cloud_db.php` tiene una sola ruta (DDL base, migraciones, catálogo maestro, histórico de demostración con EARS-02.6 intacto) y su salida no nombra dos motores.
4. **Canal remoto en verde** contra Supabase: convergencia de esquema, blindaje constitucional y presupuesto de latencia de RNF-01.
5. **Rollback redefinido y escrito:** la retirada del motor heredado elimina el «quitar `DATABASE_URL`»; en su lugar queda documentada la restauración de copia/point-in-time de Supabase.
6. **Documentación viva enmendada** ([`README.md`](../../README.md), [`AGENTS.md`](../../AGENTS.md) §4, guías y contratos técnicos vigentes) y **actas históricas preservadas**.

---

## 7. Riesgos declarados

| Riesgo | Mitigación |
|---|---|
| El eslabón crítico del porte es el sembrador del arnés (`SeedRunner`), usado por **85 ficheros de prueba** | Primero se le da ruta por el motor único y se certifica su suite; el resto del porte va por lotes verdes |
| La batería deja de depender de MariaDB y cualquier regresión se detecta tarde | Orden estricto: portar y certificar la batería **antes** de borrar la rama heredada; el borrado nunca abre una ventana sin certificación |
| El motor local de la batería se comparte con las suites de la categoría | La batería usa su propio banco y el contrato de limpieza vigente; las suites de la categoría conservan su esquema aislado |
| Escribir SQL privativo nuevo durante el porte | El inventario de dialecto privativo se invierte: pasa a prohibir el dialecto heredado y a exigir el del motor único |
| La retirada del DDL heredado deja el esquema sin su capa incremental | La absorción está comprobada elemento a elemento antes de borrar (T-13-16) y la convergencia del motor único pasa a declararse solo en `supabase_migrations/` |
| Latencia de aprovisionamiento por suite (DDL de 100+ sentencias) | El banco de la batería se aprovisiona una vez por corrida (idempotente) y solo las suites que lo necesiten usan esquema aislado |
| Pérdida del desarrollo local sin configuración (XAMPP + MariaDB) | Se declara y se resuelve en §8.1 antes del borrado: Supabase de desarrollo o motor TLS en contenedor |

---

## 8. Dudas abiertas

1. **Entorno de desarrollo de la aplicación.** La aplicación exige TLS con verificación de identidad (RNF-05), de modo que el motor local de la batería —TCP plano— **no puede servirla**. Opciones: (a) un proyecto Supabase de desarrollo con credenciales propias; (b) el motor TLS en contenedor de `deploy/local/postgres/provision-tls-engine.sh` (puerto 55433), que sirve el mismo contrato y no depende de la red. La segunda conserva el trabajo offline y la primera es más fiel al despliegue; ambas pueden coexistir.
2. **Forma del contrato de esquema único.** El contrato de correspondencia vigente se escribió para *comparar dos motores*. Al retirar uno, se convierte en el contrato del esquema del motor único; queda por decidir si absorbe también las reglas que hoy viven en `generated_columns_contract.md` (D-6, que muere con el índice compuesto generado).
3. **Qué se conserva del adaptador.** Con un solo motor, `SqlDialectAdapter` puede (a) reducirse a los constructos que el motor único realmente necesita, o (b) desaparecer y dejar el SQL en los repositorios. La primera opción conserva el punto único de construcción de SQL que hoy certifica el inventario; la segunda simplifica más y reparte SQL por los repositorios. **Recomendación:** (a).
