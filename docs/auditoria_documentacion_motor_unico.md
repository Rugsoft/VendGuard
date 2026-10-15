# AUDITORÍA DE DOCUMENTACIÓN VIVA · QUÉ DICE CADA DOCUMENTO SOBRE EL MOTOR DE BASE DE DATOS

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)
**Módulo:** [`13-single-engine-supabase`](../specs/13-single-engine-supabase/spec.md) · Fase 5 (documentación viva y rollback)
**Documento:** `docs/auditoria_documentacion_motor_unico.md`
**Fecha:** 15 de octubre de 2026
**Estado:** Inventario cerrado. **Las enmiendas no se aplican todavía** (ver §5).
**Procedencia:** solicitado como insumo de la tarea T-13-17 de [`specs/13-single-engine-supabase/tasks.md`](../specs/13-single-engine-supabase/tasks.md).

---

## 1. Qué se midió y cómo

**Pregunta:** qué dice hoy cada documento del repositorio sobre el motor de base de datos y qué debe decir cuando el camino MariaDB/MySQL esté retirado.

**Comando reproducible** (desde la raíz del repositorio):

```bash
grep -rliE "mariadb|mysql" --include=*.md . | grep -v node_modules
grep -rniE "mariadb|mysql" --include=*.md .   # con línea exacta
```

**Resultado:** **59 ficheros markdown** nombran el motor —más este informe, que lo nombra por su obvia razón—. Se reparten así:

| Clase | Ficheros | Destino tras la retirada |
|---|---|---|
| **Vivos** — documentación operativa que hoy rige | **14** | **Se enmendan** (§3) |
| **Históricos** — actas, auditorías, informes y registros por módulo | 33 | **Se preservan tal cual** (§4) |
| **Nuevos del módulo 13** | 4 | Ya redactados para el motor único |
| **Fuera de alcance** — skills del arnés de agentes y volcados generados | 8 | No son documentación del proyecto |

**Regla de clasificación aplicada:** un documento es **vivo** si un lector lo consulta *hoy* para operar o para entender el sistema vigente; es **histórico** si narra lo que ocurrió en una fecha concreta. Un documento histórico que se enmienda deja de ser evidencia: falsea el pasado. Un documento vivo que se conserva miente sobre el presente.

**Artefactos no documentales que también nombran el motor** (no son documentación; van en las tareas de código de la Fase 4): el [`Dockerfile`](../Dockerfile) (`pdo_mysql` junto a `pdo_pgsql`), [`deploy/local/postgres/provision-tls-engine.sh`](../deploy/local/postgres/provision-tls-engine.sh), `skills-lock.json` (fuera de alcance), y los comentarios de `src/`, `bin/` y `database/`.

---

## 2. Los documentos vivos, uno a uno

### 2.1 [`AGENTS.md`](../AGENTS.md) — 2 líneas

| Línea | Qué dice hoy | Cómo debe quedar |
|---|---|---|
| 69 | `* **Base de Datos:** **MariaDB / MySQL**.` | `* **Base de Datos:** **PostgreSQL (Supabase)**.` — y la sección pasa a describir el motor único, su esquema canónico y su política de credenciales |
| 87 | `* **Infrastructure:** Conexión PDO a MariaDB, almacenamiento de imágenes y repositorios.` | `Conexión PDO a PostgreSQL (Supabase), almacenamiento de imágenes y repositorios.` |

**Nota de procedimiento:** `AGENTS.md` es directriz operativa del proyecto, no acta. Su enmienda va con el visto bueno del Product Owner dentro de la Fase 5, nunca antes.

### 2.2 [`README.md`](../README.md) — 14 líneas

| Línea | Qué dice hoy | Cómo debe quedar |
|---|---|---|
| 5 | Badge `MariaDB 10.11+ \| MySQL 8.0` con logo de MariaDB | Badge del motor único (PostgreSQL 17 · Supabase) |
| 118 | «**Arquitectura Híbrida Desacoplada** … manteniendo paridad absoluta con MariaDB/MySQL local» | «**Arquitectura desacoplada con motor único**»: PostgreSQL en Supabase por el agrupador transaccional bajo TLS `verify-full`, sin segunda paridad que mantener |
| 143 | Requisitos: extensiones `pdo_mysql` y `mbstring`; «el Dockerfile instala `pdo_mysql`» | `pdo_pgsql` y `mbstring` |
| 144 | «**Base de Datos:** MySQL 8.0+ o MariaDB 10.5+ (local, Docker o Cloud como TiDB Serverless)» | PostgreSQL 17 (Supabase) en despliegue; el motor del arnés (§8.1 de la especificación del módulo 13) en desarrollo |
| 149 | Encabezado `#### Entorno Local (MariaDB / MySQL):` | Procedimiento único del motor del arnés |
| 152 | `mysql -u root -e "CREATE DATABASE IF NOT EXISTS vendguard_db …"` | El aprovisionamiento del arnés se hace con el inicializador del repositorio, no con un cliente MySQL |
| 172 | «`# contra el motor activo (PostgreSQL en Supabase o MariaDB local):`» | «`# contra el motor único:`» |
| 217 | «el Dockerfile instala `pdo_mysql`; no hace falta ninguna otra extensión» | `pdo_pgsql` |
| 226 | Fila de variables: «`DATABASE_URL` / `MYSQL_URL` — Cadena única del PaaS (`mysql://usuario:clave@host:puerto/base`)» | Se retira `MYSQL_URL` y el ejemplo `mysql://`; queda `DATABASE_URL` con `postgres://` |
| 398 | «`CoordinatorRouteMapApiTest` / `TechnicianRouteMapApiTest` … integración HTTP contra MariaDB» | «… integración HTTP contra el motor del arnés» |
| 401 | «… el ciclo completo contra MariaDB sobre dos conexiones PDO reales» | «… contra el motor del arnés» |
| 417 | Árbol de `bin/`: «`init_cloud_db.php # Inicializador automático para MySQL/TiDB Cloud`» | «Inicializador del arranque desplegado (una sola ruta: DDL base, migraciones, catálogo, histórico de demostración)» |
| 425 | Árbol de `database/`: «`schema.sql # DDL MariaDB del núcleo operativo`» | La línea desaparece con el fichero; el árbol declara `supabase_init.sql`, `supabase_migrations/` y `supabase_seeds.sql` |
| 484 | «`integration/ # Pruebas de persistencia real en MariaDB y API HTTP`» | «… en el motor del arnés y API HTTP» |

### 2.3 [`deploy/local/postgres/README.md`](../deploy/local/postgres/README.md) — 3 líneas

Este documento cambia de **qué** habla, no solo de vocabulario: hoy presenta el motor PostgreSQL local como una excepción que la batería no usa.

| Línea | Qué dice hoy | Cómo debe quedar |
|---|---|---|
| 9 | «… contra el motor local (MariaDB). Las suites que exigen PostgreSQL no pueden mezclarse con…» | El motor PostgreSQL local **es** el banco de la batería; desaparece la advertencia de no mezclar categorías |
| 36 | «La **batería hermética no la lee nunca**: sigue usando el motor local de MariaDB.» | «La **batería hermética lo lee siempre**: es su banco.» — con el aprovisionamiento canónico documentado |
| 204 | «… `PDO::getAvailableDrivers()` devuelve `mysql` y `pgsql`» | Devuelve solo `pgsql` |

### 2.4 [`docs/guia_conexion_supabase.md`](guia_conexion_supabase.md) — 6 líneas

| Línea | Qué dice hoy | Cómo debe quedar |
|---|---|---|
| 46 | Tabla de drivers: «`mysql, pgsql, sqlite` → `pdo_pgsql` ya está activo» | Solo `pgsql` (y `sqlite` si se conserva por otra razón) |
| 190 | «… el agregado devuelve el mismo total (certificado contra MariaDB real en el Caso 7 de…» | La certificación se refrasea: el mismo total, certificado contra el motor único. Si el caso histórico se conserva como evidencia, se marca como histórico |
| 205 | «**UNA sola consulta** … certificada contra MariaDB real en el Caso 9» | Igual que la anterior |
| 214 | «… en MariaDB el ENUM compara por…» | Explicación del comportamiento **del motor único**; la del motor heredado se retira |
| 315 | «… MariaDB sin tocar una línea de código.» | Se retira: ya no hay a dónde volver |
| 329 | «`# Contra el motor que declare el entorno: Supabase (DATABASE_URL=postgres://…) o MariaDB local`» | «`# Contra el motor único:`» |

### 2.5 [`docs/manual_e2e_verification.md`](manual_e2e_verification.md) — 1 línea

| Línea | Qué dice hoy | Cómo debe quedar |
|---|---|---|
| 20 | Diagrama de secuencia: `participant DB as MariaDB` | `participant DB as PostgreSQL` |

### 2.6 [`docs/migracion_supabase_hibrido.md`](migracion_supabase_hibrido.md) — 10 líneas

Es el caso más delicado del inventario: mezcla **acta** (cómo se hizo la migración híbrida) con **procedimiento vivo** (el rollback).

| Línea | Qué dice hoy | Cómo debe quedar |
|---|---|---|
| 14 | «El **Enfoque Híbrido** traslada la persistencia … desde el motor actual (MariaDB local / TiDB en Render)» | Encabezado de acta: «Módulo 12 · migración híbrida (cerrada)», con la nota de que el dualismo se retiró en el módulo 13 |
| 46 | «… con posibilidad de marcha atrás inmediata hacia MariaDB si fuera necesario.» | Se retira la promesa: no hay marcha atrás al motor heredado |
| 208 | «… (mucho más limpio que la columna virtual de MariaDB)» | Comparación histórica; se conserva solo si el documento se marca como acta |
| 448-482 | La rama `createMysqlConnection()` explicada línea a línea | Se conserva como acta del módulo 12, con la nota de retirada |
| 526-528 | «*MySQL/MariaDB:* … *Beneficio:* la sintaxis ANSI sin backticks es válida al 100% tanto en MySQL como en PostgreSQL» | Se retira: con un solo motor no hay sintaxis que reconciliar |
| 655 | «Validar que las 249 suites pasen al 100% en verde … por uso residual de comillas o funciones MySQL» | Instrucción del porte, ya ejecutada; pasa a acta |
| **684** | «**Rollback en 1 minuto:** Cambiar la variable `DATABASE_URL` … para volver a apuntar a la base de datos MariaDB previa.» | **Se sustituye por el procedimiento real de rollback de Supabase: restauración de copia lógica y recuperación a un punto en el tiempo, con el paso a paso concreto** (criterio 5 de aceptación del módulo 13) |

### 2.7 [`specs/technical/database_schema.md`](../specs/technical/database_schema.md) — 4 líneas

| Línea | Qué dice hoy | Cómo debe quedar |
|---|---|---|
| 1 | Título: «ESPECIFICACIÓN TÉCNICA · MODELO DE BASE DE DATOS (MariaDB)» | «… MODELO DE BASE DE DATOS (PostgreSQL · motor único)» |
| 4 | «**Motor:** MariaDB 10.5+ / MySQL 8.0+ (Motor InnoDB)» | «**Motor:** PostgreSQL (Supabase)»; en el arnés, el motor local aprobado |
| 251 | «**Comportamiento en MariaDB:** …» sobre la unicidad del ticket activo | El comportamiento equivalente del motor único: **índice único parcial** (D-6 muere) |
| 299 | «`-- VENDGUARD: DDL DE BASE DE DATOS (MariaDB / MySQL 8.0+)`» | El DDL canónico del motor único |

### 2.8 [`specs/technical/schema_correspondence_contract.md`](../specs/technical/schema_correspondence_contract.md) — 25 líneas

Es el documento que **más cambia**: hoy es un contrato *entre* dos motores y pasa a ser un contrato *de* un esquema.

| Línea | Qué dice hoy | Cómo debe quedar |
|---|---|---|
| 1 y 5 | «CONTRATO DE CORRESPONDENCIA DE ESQUEMA · PostgreSQL ↔ MariaDB» / «Ámbito: Correspondencia declarada entre el esquema MariaDB desplegado y el esquema PostgreSQL del motor nuevo» | «CONTRATO DE ESQUEMA · MOTOR ÚNICO (PostgreSQL)»: sin correspondencia que declarar |
| 10 | «Procedencia del inventario: esquema **desplegado** `vendguard_db` del motor local MariaDB … y los 16 ficheros de `database/migrations/`» | Procedencia: el esquema del motor único y su capa de migraciones |
| 50, 168, 172, 211 | Tablas y reglas de ENUM/columna comparadas «en MariaDB» frente a «en PostgreSQL» | Una sola columna: la forma del motor único |
| 233-242 | Las **diferencias aceptadas D-1 … D-9** (identidad, JSON, ticket activo, disparadores…) | Se retiran: existían solo para puentear dos motores. Sobrevive, si acaso, la nota de por qué el motor único hace lo que hace |
| 254-266 | Adiciones del motor nuevo (`email_key`, algoritmo de contraste que lee **los dos** catálogos) | El algoritmo de certificación conserva una sola lectura —la del motor único— y las adiciones pasan a ser parte del esquema, no excepciones |
| 310 | «Foto del esquema MariaDB desplegado… **No es la verdad del sistema**» | Se retira la foto del motor heredado; la verdad es el esquema del motor único |

### 2.9 [`specs/technical/generated_columns_contract.md`](../specs/technical/generated_columns_contract.md) — 7 líneas

**Destino:** se **retira** con D-6 (el índice compuesto generado de `incidents.is_active_ticket` y las reglas de TiDB que el arranque desplegado no comparte con MariaDB). Sus reglas de operación R1-R4 siguen siendo válidas para la columna generada que sobreviva; se reubican donde el módulo 13 decida conservar el contrato de esquema único.

### 2.10 [`specs/technical/plan.md`](../specs/technical/plan.md) — 7 líneas

| Línea | Qué dice hoy | Cómo debe quedar |
|---|---|---|
| 25 | Árbol: «`database_schema.md # Esquema relacional MariaDB y DDL`» | Esquema relacional del motor único |
| 84 | «`ConnectionFactory.php # Conexión PDO a MariaDB segura (Prepared Statements)`» | Conexión PDO al motor único |
| 116 | «`Integration/ # Pruebas de Integración con MariaDB`» | Con el motor del arnés |
| 128 | «La correspondencia entre las tablas de **MariaDB** (`database_schema.md`) y los endpoints REST…» | La correspondencia entre las tablas del motor único y los endpoints |
| 399 | «**Índice Único Condicional en MariaDB (`uq_machine_active_ticket`)** … mediante una columna virtual» | Índice único **parcial** del motor único |
| 424 | «### 6.2 Pruebas de Integración con MariaDB (`tests/Integration/`)» | Con el motor del arnés |
| 428 | «… verifica que MariaDB lanza error `1062 Duplicate entry`» | El código de error del motor único para violación de unicidad (**`23505`**, no `1062`) |

### 2.11 Contratos técnicos vigentes con una referencia cada uno

| Documento | Línea | Qué dice hoy | Cómo debe quedar |
|---|---|---|---|
| [`refunds_contracts.md`](../specs/technical/refunds_contracts.md) | 751 | «**Pruebas de Integración con MariaDB Real:**» | Con el motor del arnés |
| [`route_map_contracts.md`](../specs/technical/route_map_contracts.md) | 486 | «… ejecutan el algoritmo contra MariaDB real comprobando:» | Con el motor del arnés |
| [`spare_parts_contracts.md`](../specs/technical/spare_parts_contracts.md) | 131 | «## 3. Modelo de Datos Relacional (DDL MariaDB 10.4+ / MySQL 8.0+)» | DDL del motor único (y, si el DDL vive en `supabase_init.sql`, se referencia en lugar de duplicarlo) |
| [`testing_cleanup_contract.md`](../specs/technical/testing_cleanup_contract.md) | 163 | «… el error 1451 de MariaDB.» (violación de clave foránea) | El error equivalente del motor único (**`23503`**), o la regla descrita sin código de motor |

---

## 3. Los documentos históricos (se preservan tal cual)

No se toca ni una línea. La razón es la misma en todos: **su valor es que ocurrieron**. Enmendarlos destruiría la evidencia que los auditores del módulo 12 y del cierre del MVP usaron para ratificar decisiones.

| Grupo | Ficheros | Ejemplo representativo |
|---|---|---|
| Auditorías adversariales del plan híbrido | `docs/auditoria_plan_migracion_supabase_hibrida.md` (22 líneas), `…_revision3.md` (15) | «T-HYBRID-24 y el criterio §8.7 exigen que los inventarios … **sean equivalentes**» |
| Informes y actas de verificación | `docs/verificacion_*.md` (5), `docs/constitutional_audit_report.md`, `docs/informe_sonda_humo_flujo_modulo_11.md` | «Toda acción **presente en `audit_log`** está catalogada (MariaDB real)» |
| Traspasos y propuestas | `docs/handoff_rama_incident-comments.md`, `docs/propuesta_*` (2) | «Batería completa (requiere MariaDB local…)» |
| Registros SDD por módulo | `specs/02-qr-codes`, `03-metrics-audit`, `04-admin-crud`, `06-spare-parts`, `07-route-map`, `08-refunds` (+ su análisis de la sexta tanda), `09-incident-detail-modal`, `10-incident-comments`, `11-pending-info-sla-pause` | «Pruebas de Integración HTTP (MariaDB Real)» |
| Módulo 12 | `spec.md`, `plan.md`, `tasks.md` | Ya llevan su nota de enmienda propuesta (módulo 13) sin alterar el cuerpo |
| Auditoría de arquitectura del triaje y cierre técnico | `specs/technical/auditoria_arquitectura_triage.md`, `specs/technical/tasks.md` | «T-02: Creación y ejecución de la migración DDL en MariaDB» |

**Excepción de forma, no de fondo:** el módulo 12 conserva en su cabecera la **nota de enmienda** propuesta por el módulo 13 ([`spec.md:6`](../specs/12-hybrid-supabase-migration/spec.md)). Es una anotación, no un cambio de texto: AGENTS.md §5.5 exige que una especificación aprobada no se modifique en silencio.

---

## 4. Fuera de alcance

| Grupo | Ficheros | Por qué |
|---|---|---|
| Skills del arnés de agentes | `.agents/skills/mysql-patterns/SKILL.md` y 5 reglas de `php-best-practices` | No son documentación del proyecto: son material de las herramientas de desarrollo. Su mención a MySQL es propia de la skill |
| Volcados generados | `scratch/vendguard-core-repomix.md`, `scratch/vendguard-repomix-full.md` | Volcados de código generados por herramienta (50 y 84 coincidencias): se regeneran, no se enmiendan |
| `skills-lock.json` | 1 fichero | Registro de dependencias de las skills |

---

## 5. Cuándo se aplica, y por qué no ahora

**Regla:** la enmienda de los 14 documentos vivos es la **Fase 5** del módulo 13, **después** de retirar el camino heredado del código.

**Motivo:** al revés se escribiría una documentación falsa. Si el README declara hoy «una sola ruta de arranque» mientras `bin/init_cloud_db.php` sigue teniendo dos ramas, y las guardas del repositorio comprueban coherencia entre documento y código, la enmienda anticipada produce exactamente el tipo de deriva que este repositorio persigue (`ApiContractDriftGuardTest`, `DocMetricsGuard`). La documentación viva se enmienda cuando el hecho es cierto, no antes.

**Consecuencia operativa:** mientras dure el porte, el README sigue describiendo la realidad de dos motores —y es correcto que lo haga—. La nota de enmienda del módulo 12 y este inventario son los que declaran la dirección.

**Orden exacto de la Fase 5:**

1. `AGENTS.md` §4 y §5 (declaración de stack y de infraestructura) — con el visto bueno del Product Owner.
2. `README.md`: badge, requisitos, arranque local, variables de entorno, árbol de directorios y descripciones de suites.
3. `deploy/local/postgres/README.md`: el motor local deja de ser excepción y pasa a ser el banco de la batería.
4. `docs/guia_conexion_supabase.md` y `docs/manual_e2e_verification.md`: guía y diagrama del motor único.
5. `docs/migracion_supabase_hibrido.md`: se marca como acta del módulo 12 y **se redacta el rollback real** (restauración de copia y punto en el tiempo de Supabase) en su lugar.
6. Contratos de `specs/technical/`: `schema_correspondence_contract.md` → contrato de esquema del motor único; `database_schema.md` reescrito; `generated_columns_contract.md` retirado; `plan.md` y los cuatro contratos de módulo con sus referencias y sus códigos de error del motor.

**Verificación de cierre:** tras la Fase 5, el patrón de §1 debe devolver **cero ficheros vivos** (el recuento total pasa de 60 a 46: los 33 históricos, los 8 de fuera de alcance, los 4 del módulo 13 y este informe); los únicos resultados admisibles son los históricos, los ficheros del módulo 13 y los de fuera de alcance. Esa comprobación es la que certifica el criterio 6 de aceptación del módulo 13.
