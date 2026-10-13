# CONTRATO DE CORRESPONDENCIA DE ESQUEMA · PostgreSQL ↔ MariaDB

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Documento:** `specs/technical/schema_correspondence_contract.md`  
**Ámbito:** Correspondencia declarada entre el esquema MariaDB desplegado y el esquema PostgreSQL del motor nuevo (módulo 12)  
**Requisitos que lo exigen:** spec §8 criterio 7, RNF-04 · T-HYBRID-24 (`SchemaConvergenceParityTest`), T-HYBRID-01 y T-HYBRID-02 (DDL que debe cumplirlo)  
**Decisiones vinculantes:** [plan.md](../12-hybrid-supabase-migration/plan.md) §4, §6 y §8  
**Normativa Suprema:** [constitution.md](../../constitution.md) (Artículos I, II, III, V y VI)  
**Estado:** Contrato declarado y **materializado por completo** en `database/supabase_init.sql` (T-HYBRID-01: tablas 1-10 · T-HYBRID-02: tablas 11-20 y el script de las 20 compilando y correspondiendo)  
**Procedencia del inventario:** esquema **desplegado** `vendguard_db` del motor local MariaDB (consultado a través de `information_schema` el 2026-10-13) y los 16 ficheros de `database/migrations/`  
**Fuente del lado PostgreSQL:** plan §4 (blindaje constitucional, claves canónicas, índice único parcial, disparadores) y las tareas T-HYBRID-01/02

---

## 1. Propósito y regla rectora

Este documento declara **qué debe corresponder uno a uno** entre los dos esquemas y **qué diferencias se aceptan por diseño**. Existe porque la igualdad literal de inventarios es **inalcanzable y además indeseada**: el diseño del motor nuevo es deliberadamente más estricto en unos puntos (Art. V.1 en la base, disparadores anti-borrado en las 20 tablas, claves canónicas) y estructuralmente distinto en otros (identidad de fila, tipos nativos, una columna derivada frente a un índice parcial).

**Regla rectora.** Todo elemento del esquema recibe exactamente uno de estos tres veredictos:

| Veredicto | Significado | Consecuencia en la suite |
| :--- | :--- | :--- |
| **CORRESPONDENCIA** | Debe existir en **ambos** motores, uno a uno, con la forma que este contrato fija | Ausencia o desvío ⇒ **fallo** |
| **DIFERENCIA ACEPTADA** (`D-n`) | Las dos formas son distintas **por diseño**; el contrato fija la forma exacta de cada motor y lo que hay que certificar en lugar de la igualdad | Forma distinta de la declarada ⇒ **fallo** |
| **ADICIÓN DECLARADA** (`A-n`) | Existe **sólo** en el motor nuevo y es intencionada | Si desaparece ⇒ **fallo**; si aparece algo no declarado ⇒ **fallo** |

**Se prohíbe expresamente** exigir igualdad literal de tablas, columnas o índices entre motores (spec §8 criterio 7). Un elemento que exista en un motor y no esté declarado aquí —en cualquiera de los dos sentidos— se considera **deriva** y hace fallar la suite.

**Autoridad del documento:** el DDL de PostgreSQL (`database/supabase_init.sql`) y sus migraciones se escriben **conforme a este contrato**. Si al escribir el DDL aparece una divergencia inevitable, se modifica **este contrato primero** y con la misma revisión, nunca el DDL en silencio (AGENTS.md §5.5).

**Fuera de alcance:** paridad de datos y de semillas (EARS-02.1), paridad de *rendimiento*, el pooler remoto Supavisor y el esquema remoto de Supabase.

---

## 2. Correspondencia obligatoria

### 2.1 Tablas (20)

El conjunto de tablas debe ser **idéntico** en ambos motores, con los mismos nombres (sin renombrados ni traducciones) y los mismos roles. El inventario de columnas por tabla es el que fija el §7; aquí se declara la tabla y su papel en el dominio.

| # | Tabla | Columnas | Papel |
| :--- | :--- | ---: | :--- |
| 1 | `locations` | 17 | Sedes de cliente (raíz del parque) |
| 2 | `machines` | 19 | Parque de máquinas; pertenece a una sede |
| 3 | `users` | 13 | Personal interno (coordinador, técnico) |
| 4 | `incidents` | 35 | Núcleo operativo; concentra el blindaje constitucional |
| 5 | `incident_history` | 7 | Trazabilidad inmutable de estados |
| 6 | `incident_comments` | 9 | Bitácora de evidencias y mensajes (módulo 10) |
| 7 | `incident_replaced_parts` | 17 | Piezas sustituidas y su importe derivado |
| 8 | `refund_requests` | 35 | Reintegros (módulo 8); única tabla con disparador en MariaDB |
| 9 | `unclaimed_cash_findings` | 7 | Hallazgos de efectivo no reclamado |
| 10 | `spare_parts` | 11 | Catálogo maestro de repuestos |
| 11 | `spare_part_compatibilities` | 6 | Compatibilidad repuesto ↔ modelo |
| 12 | `spare_part_requests` | 10 | Solicitudes de repuesto |
| 13 | `preventive_settings` | 7 | Configuración de mantenimiento preventivo por tipo |
| 14 | `preventive_orders` | 20 | Órdenes preventivas |
| 15 | `preventive_order_items` | 9 | Checklist de la orden preventiva |
| 16 | `sanitary_certificates` | 18 | Certificados sanitarios |
| 17 | `route_settings` | 12 | Configuración de ruta por técnico |
| 18 | `sanitary_sweep_passes` | 9 | Registro de pasadas del barrido (módulo 11) |
| 19 | `sanitary_sweep_contentions` | 9 | Contención de fila leída como aplazamiento (T-PAUSE-39) |
| 20 | `audit_log` | 11 | Auditoría inmutable de sistema (Art. VI) |

> **Nota de documentación:** el diccionario de [`database_schema.md`](database_schema.md) cubre únicamente las 7 tablas del DDL base (`schema.sql`), porque precede a las 16 migraciones. El inventario de referencia de **este** contrato es el esquema desplegado y las migraciones, no aquel diccionario parcial.

### 2.2 Columnas

**Regla general:** para cada tabla, el conjunto de **nombres de columna** debe ser idéntico en ambos motores; la nulabilidad y el **sentido** del valor por defecto también. Los tipos se rigen por el mapeo del §3. El total actual es **281 columnas** (snapshot del §7); el contrato no las retipea una a una —sería una copia del inventario, no un contrato— sino que fija las reglas y las excepciones, y la suite las aplica consultando los catálogos de ambos motores:

- Una columna presente en un motor y ausente en el otro ⇒ **fallo** (es el modo de deriva que este contrato existe para atrapar: «columna añadida en un motor y olvidada en el otro»).
- Una columna cuyo tipo no corresponda según el §3 y que no esté declarada como `D-n` ⇒ **fallo**.
- Una columna con el mismo nombre pero distinta nulabilidad ⇒ **fallo**, salvo las excepciones declaradas en el §4.

### 2.3 Claves primarias y restricciones únicas (32)

Todas deben existir en ambos motores con las mismas columnas y la misma semántica de unicidad. Las **12 restricciones nombradas** además codifican reglas de negocio constitucionales: no son índices de rendimiento y no pueden desaparecer.

| Tabla | Restricción | Columnas | Regla que protege |
| :--- | :--- | :--- | :--- |
| `locations` | `PRIMARY` | `id` | Identidad de fila |
| `locations` | `uq_locations_site_code` | `site_code` | Un código de sede, una sede (Art. I.2) |
| `machines` | `PRIMARY` | `id` | Identidad de fila |
| `machines` | `uq_machines_code` | `code` | Una máquina, un código |
| `users` | `PRIMARY` | `id` | Identidad de fila |
| `users` | `uq_users_email` | `email` | Un login, una identidad (**D-5**) |
| `incidents` | `PRIMARY` | `id` | Identidad de fila |
| `incidents` | `uq_incidents_ticket_code` | `ticket_code` | Un expediente, un código |
| `incidents` | `uq_machine_active_ticket` | `machine_id`, `is_active_ticket` | **Un único ticket activo por máquina** (Art. V.2/V.6) — **D-6** |
| `incident_history` | `PRIMARY` | `id` | Identidad de fila |
| `incident_comments` | `PRIMARY` | `id` | Identidad de fila |
| `incident_replaced_parts` | `PRIMARY` | `id` | Identidad de fila |
| `refund_requests` | `PRIMARY` | `id` | Identidad de fila |
| `refund_requests` | `tracking_token` | `tracking_token` | Un enlace público, un expediente de reintegro |
| `unclaimed_cash_findings` | `PRIMARY` | `id` | Identidad de fila |
| `spare_parts` | `PRIMARY` | `id` | Identidad de fila |
| `spare_parts` | `uq_spare_parts_part_code` | `part_code` | Un repuesto, un código |
| `spare_part_compatibilities` | `PRIMARY` | `id` | Identidad de fila |
| `spare_part_compatibilities` | `uq_part_model` | `spare_part_id`, `machine_model` | Una compatibilidad por repuesto y modelo |
| `spare_part_requests` | `PRIMARY` | `id` | Identidad de fila |
| `preventive_settings` | `PRIMARY` | `id` | Identidad de fila |
| `preventive_settings` | `uq_prev_settings_type` | `machine_type` | Una configuración por tipo de máquina |
| `preventive_orders` | `PRIMARY` | `id` | Identidad de fila |
| `preventive_orders` | `uq_prev_order_code` | `order_code` | Una orden, un código |
| `preventive_order_items` | `PRIMARY` | `id` | Identidad de fila |
| `sanitary_certificates` | `PRIMARY` | `id` | Identidad de fila |
| `sanitary_certificates` | `uq_certificates_code` | `certificate_code` | Un certificado, un código |
| `route_settings` | `PRIMARY` | `id` | Identidad de fila |
| `sanitary_sweep_passes` | `PRIMARY` | `id` | Identidad de fila |
| `sanitary_sweep_contentions` | `PRIMARY` | `id` | Identidad de fila |
| `sanitary_sweep_contentions` | `uq_sanitary_sweep_contention_incident` | `incident_id` | Una contención por incidencia |
| `audit_log` | `PRIMARY` | `id` | Identidad de fila |

**Índices secundarios (no únicos):** hay **56** repartidos en 18 tablas (`preventive_settings` y `route_settings` sólo tienen su clave). Deben corresponder también, con la libertad de implementación del §4 **D-9**: la suite compara *columnas indexadas por tabla*, no nombres ni orden interno de creación.

### 2.4 Claves foráneas (35)

Deben existir en ambos motores con las mismas columnas y **las mismas reglas `ON DELETE` / `ON UPDATE`**: esas reglas son reglas de negocio (qué se puede borrar, qué se propaga y qué se preserva) y no un detalle de implementación.

| Tabla | Restricción | Columna(s) | Referencia | `ON DELETE` | `ON UPDATE` |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `incidents` | `fk_incidents_location` | `location_id` | `locations` | RESTRICT | CASCADE |
| `incidents` | `fk_incidents_machine` | `machine_id` | `machines` | RESTRICT | CASCADE |
| `incidents` | `fk_incidents_technician` | `assigned_technician_id` | `users` | RESTRICT | CASCADE |
| `incident_comments` | `fk_comments_incident` | `incident_id` | `incidents` | CASCADE | CASCADE |
| `incident_comments` | `fk_comments_user` | `user_id` | `users` | SET NULL | CASCADE |
| `incident_history` | `fk_history_incident` | `incident_id` | `incidents` | CASCADE | CASCADE |
| `incident_history` | `fk_history_user` | `user_id` | `users` | SET NULL | CASCADE |
| `incident_replaced_parts` | `fk_replaced_incident` | `incident_id` | `incidents` | RESTRICT | CASCADE |
| `incident_replaced_parts` | `fk_replaced_location` | `location_id` | `locations` | RESTRICT | CASCADE |
| `incident_replaced_parts` | `fk_replaced_machine` | `machine_id` | `machines` | RESTRICT | CASCADE |
| `incident_replaced_parts` | `fk_replaced_preventive` | `preventive_order_id` | `preventive_orders` | RESTRICT | CASCADE |
| `incident_replaced_parts` | `fk_replaced_spare_part` | `spare_part_id` | `spare_parts` | RESTRICT | CASCADE |
| `incident_replaced_parts` | `fk_replaced_technician` | `technician_id` | `users` | RESTRICT | CASCADE |
| `machines` | `fk_machines_location` | `location_id` | `locations` | RESTRICT | CASCADE |
| `preventive_orders` | `fk_prev_orders_location` | `location_id` | `locations` | RESTRICT | RESTRICT |
| `preventive_orders` | `fk_prev_orders_machine` | `machine_id` | `machines` | RESTRICT | RESTRICT |
| `preventive_orders` | `fk_prev_orders_technician` | `assigned_technician_id` | `users` | RESTRICT | RESTRICT |
| `preventive_order_items` | `fk_order_items_order` | `preventive_order_id` | `preventive_orders` | RESTRICT | RESTRICT |
| `refund_requests` | `fk_refund_coordinator` | `coordinator_id` | `users` | SET NULL | CASCADE |
| `refund_requests` | `fk_refund_incident` | `incident_id` | `incidents` | RESTRICT | CASCADE |
| `refund_requests` | `fk_refund_location` | `location_id` | `locations` | RESTRICT | CASCADE |
| `refund_requests` | `fk_refund_machine` | `machine_id` | `machines` | RESTRICT | CASCADE |
| `refund_requests` | `fk_refund_technician` | `technician_id` | `users` | SET NULL | CASCADE |
| `sanitary_certificates` | `fk_certificates_location` | `location_id` | `locations` | RESTRICT | RESTRICT |
| `sanitary_certificates` | `fk_certificates_machine` | `machine_id` | `machines` | RESTRICT | RESTRICT |
| `sanitary_certificates` | `fk_certificates_prev_order` | `preventive_order_id` | `preventive_orders` | RESTRICT | RESTRICT |
| `sanitary_certificates` | `fk_certificates_technician` | `technician_id` | `users` | RESTRICT | RESTRICT |
| `sanitary_sweep_contentions` | `fk_sanitary_sweep_contention_incident` | `incident_id` | `incidents` | RESTRICT | CASCADE |
| `spare_part_compatibilities` | `fk_compat_spare_part` | `spare_part_id` | `spare_parts` | CASCADE | CASCADE |
| `spare_part_requests` | `fk_requests_incident` | `incident_id` | `incidents` | RESTRICT | CASCADE |
| `spare_part_requests` | `fk_requests_spare_part` | `spare_part_id` | `spare_parts` | RESTRICT | CASCADE |
| `spare_part_requests` | `fk_requests_user` | `requested_by_user_id` | `users` | RESTRICT | CASCADE |
| `unclaimed_cash_findings` | `fk_unclaimed_incident` | `incident_id` | `incidents` | RESTRICT | CASCADE |
| `unclaimed_cash_findings` | `fk_unclaimed_machine` | `machine_id` | `machines` | RESTRICT | CASCADE |
| `unclaimed_cash_findings` | `fk_unclaimed_technician` | `technician_id` | `users` | RESTRICT | CASCADE |

**Correspondencia literal exigible:** `RESTRICT` ↔ `NO ACTION`/`RESTRICT`, `CASCADE` ↔ `CASCADE`, `SET NULL` ↔ `SET NULL`. Un `CASCADE` que se convierta en `RESTRICT` (o al revés) cambia lo que el sistema permite borrar y **es fallo**, aunque la clave exista.

### 2.5 Restricciones `CHECK` (3)

| Tabla | Restricción | Condición | Veredicto |
| :--- | :--- | :--- | :--- |
| `audit_log` | `previous_state` | `json_valid(...)` | **D-4**: en PostgreSQL la validez la impone el tipo `jsonb` |
| `audit_log` | `new_state` | `json_valid(...)` | **D-4**, ídem |
| `audit_log` | `metadata` | `json_valid(...)` | **D-4**, ídem |

Estas tres son **todas** las restricciones `CHECK` del esquema MariaDB. Las condiciones de longitud mínima del Art. V.1 que el motor nuevo añade a nivel de base figuran como **A-3**: son adiciones declaradas, no correspondencia.

### 2.6 Conjuntos `ENUM` (24 conjuntos en 25 columnas)

Regla: cada columna `ENUM` debe tener en PostgreSQL el **mismo conjunto de miembros**, sin añadir ni quitar valores y sin alterar la ortografía. El orden interno no se exige (en PostgreSQL el tipo `ENUM` tiene orden; en MariaDB el índice del miembro importa para comparaciones, así que **se declara el mismo orden como buena práctica** y la suite certifica el conjunto, no el ordinal).

El DDL de PostgreSQL debe llevar la **tabla de correspondencia** columna → tipo nativo (T-HYBRID-01). Los 24 conjuntos vigentes son:

| # | Conjunto |
| ---: | :--- |
| 1 | `('APPROVED','REJECTED')` |
| 2 | `('CONFORME','CONFORME_CON_OBSERVACIONES')` |
| 3 | `('CONFORME','CONFORME_CON_OBSERVACIONES','NO_CONFORME','NO_EVALUABLE_POR_CAUSA_EXTERNA')` |
| 4 | `('COORDINATOR','TECHNICIAN')` |
| 5 | `('DESGUACE','TALLER')` |
| 6 | `('EN_MANO_SEDE','BIZUM','TRANSFERENCIA_BANCARIA')` |
| 7 | `('FOUND_PHYSICAL','CONFIRMED_NO_CASH','UNVERIFIED_NO_CASH')` |
| 8 | `('HOT_DRINKS','COLD_DRINKS','SNACKS','PERISHABLE_FOOD','COMBO')` |
| 9 | `('HYDRAULIC','THERMAL','ELECTRONIC','MECHANICAL','PAYMENT_SYSTEM','CONSUMABLE','OTHER')` |
| 10 | `('INCIDENT','PREVENTIVE')` |
| 11 | `('LEFT_AT_RECEPTION','HELD_FOR_CENTRAL')` |
| 12 | `('LOW','MEDIUM','HIGH','CRITICAL')` |
| 13 | `('OK','ATTENTION_REQUIRED','EXPIRED','QUARANTINE','SEASONAL_PAUSE')` |
| 14 | `('PASS','WARN','FAIL','NOT_APPLICABLE')` |
| 15 | `('PENDING','ATTENDED','CANCELLED')` |
| 16 | `('PENDING_ASSIGNMENT','SCHEDULED','IN_INSPECTION','COMPLETED','EXPIRED','CANCELLED')` |
| 17 | `('PENDING_INSPECTION','DEPOSITED_AT_RECEPTION','VERIFIED_PENDING_PAYMENT','REQUIRES_COORDINATOR_APPROVAL','PENDING_CONTACT','PAID_DIGITAL','REFUNDED_IN_HAND','REJECTED')` |
| 18 | `('PERISHABLE_FOOD','HOT_DRINKS','COLD_DRINKS','SNACKS','COMBO')` |
| 19 | `('REGISTERED','ASSIGNED','IN_PROGRESS','PENDING_PARTS','PENDING_INFO','RESOLVED','REOPENED','CLOSED','CANCELLED')` |
| 20 | `('REPORTER','TECHNICIAN','COORDINATOR','SYSTEM')` |
| 21 | `('ROUTINE','REINSPECTION','MANUAL_EXTRA')` |
| 22 | `('TEMPERATURE_COLD','PAYMENT_SYSTEM','PRODUCT_JAM','ELECTRICAL_OFF','OTHER')` |
| 23 | `('TICKET','MACHINE','LOCATION','USER','PREVENTIVE_ORDER','SANITARY_CERTIFICATE','REFUND_REQUEST','UNCLAIMED_CASH_FINDING')` |
| 24 | `('VALID','SUSPENDED','REVOKED')` |

> **Aviso de lectura:** los conjuntos 8 y 18 tienen los mismos miembros en **distinto orden** (`HOT_DRINKS` primero frente a `PERISHABLE_FOOD` primero). Son dos columnas distintas y ambas se conservan tal cual: el contrato no unifica conjuntos por parecido.

---

## 3. Mapeo de tipos declarado

Aplica a **todas** las columnas (281 en el snapshot). Es el único mapeo autorizado: cualquier otro tipo en el motor nuevo, o cualquier columna que no encaje aquí sin estar declarada como `D-n`, es **fallo**.

| Tipo en MariaDB | Columnas | Tipo en PostgreSQL | Nota |
| :--- | ---: | :--- | :--- |
| `INT UNSIGNED` (identidad, `AUTO_INCREMENT`) | 72 (`int`) | `BIGINT GENERATED ALWAYS AS IDENTITY` | **D-1** |
| `BIGINT UNSIGNED` (identidad) | 3 (`bigint`) | `BIGINT GENERATED ALWAYS AS IDENTITY` | **D-1** (`audit_log`, `sanitary_sweep_passes`, `sanitary_sweep_contentions`) |
| `INT UNSIGNED` (clave foránea o valor) | — | `BIGINT` | Consecuencia de **D-1**: la columna referenciada debe admitir el valor |
| `TINYINT(3) UNSIGNED` (contador) | 3 | `SMALLINT` | Contadores de intentos de acceso: el rango local (0..255) cabe entero en `SMALLINT`, que además pertenece a la misma familia entera corta |
| `VARCHAR(n)` | 49 | `VARCHAR(n)` | Misma longitud declarada |
| `TEXT` | 21 | `TEXT` | — |
| `LONGTEXT` (JSON con `json_valid`) | 3 | `JSONB` | **D-4** (`audit_log.previous_state`, `new_state`, `metadata`) |
| `DECIMAL(p,s)` | 20 | `NUMERIC(p,s)` | Importes y cantidades: sin pérdida de escala |
| `TINYINT(1)` (booleano) | 14 | `BOOLEAN` | **D-3**. La columna derivada `incidents.is_active_ticket` (`TINYINT(4)`) no entra aquí: la sustituye el índice único parcial de **D-6** |
| `DATETIME` | 42 | `TIMESTAMPTZ` | **D-2** |
| `TIMESTAMP` | 23 | `TIMESTAMPTZ` | **D-2** |
| `DATE` | 5 | `DATE` | — |
| `ENUM(...)` | 25 | Tipo nativo por conjunto (§2.6) | Sin `VARCHAR`: el conjunto se conserva en el motor |

---

## 4. Diferencias aceptadas por diseño

Cada diferencia lleva su forma exacta en cada motor y **qué certifica la suite en su lugar**. Ninguna de estas diferencias puede usarse como excusa para no certificar la regla de negocio subyacente.

| ID | Diferencia aceptada | MariaDB | PostgreSQL | Por qué se acepta | Qué certifica la suite |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **D-1** | Identidad de fila | `INT/BIGINT UNSIGNED AUTO_INCREMENT` | `BIGINT GENERATED ALWAYS AS IDENTITY` | No hay `AUTO_INCREMENT` en PostgreSQL ni `IDENTITY` en MariaDB; la función (identidad monótona generada por el motor) coincide | Que la identidad se genera sin intervención de la aplicación y que la columna referenciada admite el valor |
| **D-2** | Tipos temporales y auto-actualización | `DATETIME`/`TIMESTAMP`; **11** columnas `updated_at` con `ON UPDATE CURRENT_TIMESTAMP`, **30** columnas con `DEFAULT CURRENT_TIMESTAMP` | `TIMESTAMPTZ`; por defecto `CURRENT_TIMESTAMP`; la auto-actualización se materializa con un disparador `BEFORE UPDATE` que invoca `touch_updated_at()` —`trg_touch_<tabla>`—, escribe `statement_timestamp()` (el análogo del instante de **sentencia** que usa el motor local: `CURRENT_TIMESTAMP` sería el de transacción y la marca no avanzaría entre dos `UPDATE` de la misma transacción) y **respeta** el valor cuando la sentencia ya escribe `updated_at` | PostgreSQL **no tiene** `ON UPDATE` en la definición de columna, y `DATETIME` no lleva desplazamiento horario | Que el instante es correcto en UTC (RF-09) y que, **en un `UPDATE` real, `updated_at` avanza**: se certifica el comportamiento, no la definición. La forma PostgreSQL de esa certificación (el disparador, y no una cláusula inexistente) queda declarada aquí, en la fila, al escribirse el DDL (T-HYBRID-01) |
| **D-3** | Booleanos | `TINYINT(1)` | `BOOLEAN` | Homologación de tipo, no de significado | Que los valores escritos y leídos por la aplicación conservan verdadero/falso con `PDO::PARAM_BOOL` |
| **D-4** | Documentos JSON | `LONGTEXT` + `CHECK json_valid(...)` + colación `utf8mb4_bin` | `JSONB` | El `CHECK` es la forma de MariaDB de garantizar validez; el tipo `jsonb` la hace estructural en PostgreSQL | Que escribir JSON inválido **falla** en ambos motores y que el contenido escrito se recupera igual |
| **D-5** | Unicidad de texto | `utf8mb4_unicode_ci`: unicidad insensible a mayúsculas **y** acentos | Claves canónicas `A-1`/`A-2` con `UNIQUE` sobre la clave | `citext`/`lower()` sólo cubren mayúsculas: el motor nuevo quedaría con una regla **más débil** que la certificada | Que `Ana@X.com` y `ana@x.com` **colisionan** en ambos motores y que la semántica es la misma |
| **D-6** | Un único ticket activo por máquina | `is_active_ticket` `VIRTUAL GENERATED` = `IF(status IN ('CLOSED','CANCELLED'), NULL, 1)` + único compuesto `(machine_id, is_active_ticket)` | Índice único **parcial**: `UNIQUE (machine_id) WHERE status NOT IN ('CLOSED','CANCELLED')` | PostgreSQL no necesita la columna derivada para expresar la condición; el `NULL` como «no participo en el índice» es propio de MariaDB | Que **no** pueden coexistir dos tickets activos para la misma máquina, que un `RESOLVED` **sí** sigue bloqueando la ventana de 48 h y que cerrar o cancelar libera la máquina (Art. V.2/V.6) |
| **D-7** | Importe derivado | `total_cost_snapshot` `STORED GENERATED` = `quantity * unit_cost_snapshot` | `GENERATED ALWAYS AS (quantity * unit_cost_snapshot) STORED` | Misma función, distinta sintaxis | Que el importe derivado es exacto y no se puede escribir a mano en ninguno de los dos motores |
| **D-8** | Disparadores anti-borrado | **1**: `trg_refund_requests_no_hard_delete` (sólo `refund_requests`) | **20**: `BEFORE DELETE` en todas las tablas (plan §4) | El blindaje del Art. III.1 se **amplía** a todo el esquema en el motor nuevo; el histórico de MariaDB sólo tenía la guarda del módulo de reintegros | Que ninguna tabla admite `DELETE FROM` sin la señal de sesión, **y** que la puerta de purga del arnés sigue funcionando en su forma por motor |
| **D-9** | Nombres e implementación de índices | Índices explícitos y únicos compuestos (`uq_*`, `idx_*`); **un** índice redundante sobre una columna ya única (`idx_refund_tracking_token`) | Índices parciales y de expresión donde haga falta; el redundante se refleja para que el recuento declarado de 56 no dependa de una interpretación (T-HYBRID-02) | La forma de un índice es implementación; su efecto sobre las consultas y sobre la unicidad no lo es | Que **columnas indexadas por tabla** coinciden y que el plan de las consultas críticas no degrada (medición de RNF-01 aparte) |
| **D-10** | Motor de almacenamiento | `ENGINE=InnoDB`, `utf8mb4_unicode_ci` | Almacenamiento nativo de PostgreSQL | `ENGINE` no tiene equivalente y la colación se sustituye por **D-5** | Nada: no se exige. La suite ignora `ENGINE` y la colación de tabla |

---

## 5. Adiciones declaradas del motor nuevo (sólo en PostgreSQL)

Existen **únicamente** en el motor nuevo, son deliberadas y la suite debe **exigirlas** (si desaparecen, es fallo) sin confundirlas con deriva.

| ID | Adición | Requisito | Qué debe certificar la suite |
| :--- | :--- | :--- | :--- |
| **A-1** | Columna `email_key` en `users` (`VARCHAR(150) NOT NULL`) con `UNIQUE (uq_users_email_key`) | EARS-08.5, **D-5** | Que la colisión de identidad se rechaza igual que en MariaDB y que la clave se escribe en cada alta y modificación (`NOT NULL`: una fila sin clave normalizada no es un duplicado invisible, es un alta incompleta) |
| **A-2** | Columna `site_code_key` en `locations` (`VARCHAR(32) NOT NULL`) con `UNIQUE (uq_locations_site_code_key)` | EARS-08.5, **D-5** | Ídem para el código de sede |
| **A-3** | Restricciones `CHECK` de longitud mínima (Art. V.1) en los campos de cierre y justificación | Art. V.1, plan §4.5 | Que una justificación por debajo del mínimo **es rechazada por la base**, no sólo por el servicio. **Lista fijada al escribir el DDL (T-HYBRID-01):** mínimo de **20 caracteres reales tras recortar** —`char_length(trim(...)) >= 20`— sobre cuatro columnas de `incidents`, con el patrón `IS NULL OR …` porque el campo solo se llena al cerrar, pausar o cancelar: `chk_resolution_diagnosis_len` (`resolution_diagnosis`), `chk_resolution_action_len` (`resolution_action`), `chk_pending_info_reason_text_len` (`pending_info_reason_text`) y `chk_cancellation_reason_len` (`cancellation_reason`). El umbral no se inventa: reproduce el que el dominio ya exige (`ResolutionValidator::MIN_LENGTH`, `Incident::MIN_PAUSE_REASON_LENGTH`, `IncidentPauseService::MIN_CANCELLATION_REASON_LENGTH`). `preventive_orders.cancellation_reason` no recibe `CHECK` porque su cancelación no tiene mínimo declarado en el servicio. **Lista de la Parte 2 (T-HYBRID-02):** mismo mínimo de 20 caracteres reales en `chk_coordinator_justification_len` (`refund_requests.coordinator_justification`, `JustificationTooShortException::MINIMUM_LENGTH`) y `chk_technician_justification_len` (`refund_requests.technician_justification`, el veredicto `UNVERIFIED_NO_CASH` que exige explicación escrita), y `chk_custom_part_description_len` (`spare_part_requests.custom_part_description`, repuesto fuera de catálogo). `incident_replaced_parts.custom_part_name` **no** lo recibe: su regla de dominio es de 3 caracteres y describe una pieza, no una justificación. Criterio único en las dos partes: **solo se blinda en la base el umbral que el dominio ya exige**, nunca uno nuevo |
| **A-4** | **20** disparadores `BEFORE DELETE` sobre `prevent_hard_delete()` | Art. III.1, **D-8** | Que `DELETE FROM` sobre cada una de las 20 tablas falla con `SQLSTATE '45000'` y que la señal de purga del arnés lo levanta |
| **A-5** | Libro del ejecutor de migraciones (`PostgresMigrationRunner`) | EARS-02.4, T-HYBRID-23 | Que el esquema **converge** al aplicar una migración posterior. *Si el ejecutor necesita tabla propia, su nombre se declara aquí al escribirlo; este contrato no lo impone.* |

**Adiciones que no se admiten:** cualquier columna, tabla, índice o restricción extra que no figure en esta tabla ni en el §3. Una adición no declarada es deriva, igual que una ausencia.

---

## 6. Cómo lo consume la suite (`SchemaConvergenceParityTest`)

T-HYBRID-24 implementa este contrato. La derivación es la siguiente, y **se ejecuta en la categoría del motor nuevo** (necesita los dos motores a la vez: el MariaDB local y el motor aprovisionado por [`deploy/local/postgres/`](../../deploy/local/postgres/README.md), plan §8):

```text
ALGORITMO certifiCorrespondencia():
    # 1. Inventarios, desde los catálogos de cada motor (nunca desde ficheros .sql)
    mariadb   = LEER_CATALOGO(motor='mysql')        # information_schema
    postgres  = LEER_CATALOGO(motor='pgsql')        # pg_catalog / information_schema

    # 2. Correspondencia obligatoria
    PARA CADA tabla EN contrato.tablas:             # §2.1 (20)
        AFIRMAR tabla EXISTE EN mariadb Y EN postgres
        AFIRMAR nombres_de_columna(mariadb[tabla]) == nombres_de_columna(postgres[tabla])   # §2.2
        PARA CADA columna: AFIRMAR normalizar_tipo(postgres) == mapeo(§3, tipo_mariadb)
    AFIRMAR unicos_y_pk(mariadb) == unicos_y_pk(postgres) SEGUN §2.3 (con D-6 normalizado)
    AFIRMAR foraneas_con_reglas(mariadb) == foraneas_con_reglas(postgres)   # §2.4: columna + ON DELETE/UPDATE
    AFIRMAR conjuntos_enum(mariadb) == conjuntos_enum(postgres)             # §2.6

    # 3. Diferencias aceptadas: no se exige igualdad, se exige la forma declarada
    PARA CADA diferencia EN contrato.diferencias:   # §4 (D-1 … D-10)
        AFIRMAR forma(postgres) == diferencia.declarada_para_postgres
        AFIRMAR forma(mariadb) == diferencia.declarada_para_mariadb
        # y, cuando la diferencia declara una certificación de comportamiento (D-2, D-5, D-6, D-8),
        # ejecutarla: no basta con que la estructura esté declarada.

    # 4. Adiciones declaradas: se exigen, y no cuentan como deriva
    PARA CADA adicion EN contrato.adiciones: AFIRMAR adicion EXISTE EN postgres    # §5 (A-1 … A-5)

    # 5. Deriva: cualquier cosa no declarada, en cualquier dirección
    sobrantes_postgres = ELEMENTOS(postgres) - CORRESPONDENCIA - DIFERENCIAS - ADICIONES
    sobrantes_mariadb  = ELEMENTOS(mariadb)  - CORRESPONDENCIA - DIFERENCIAS
    AFIRMAR sobrantes_postgres VACIO Y sobrantes_mariadb VACIO
```

**Reglas de ejecución:**

1. **Los inventarios se leen de los catálogos vivos**, no de los ficheros `.sql`: es la única forma de que la suite vea la deriva que el incidente del 2026-10-03 dejó pasar (esquema desplegado ≠ esquema del repositorio).
2. **El informe es por elemento**, no un booleano: la suite enumera tabla, columna, restricción o índice que falla, en qué motor y con qué forma observada frente a la declarada.
3. **Ninguna diferencia se «avisa y sigue»:** o está declarada aquí con su forma exacta, o es fallo.
4. **La convergencia se certifica por separado** (parte 1 de T-HYBRID-24): una base creada sólo con el DDL base debe **recibir** la migración posterior y converger. Este contrato no cubre esa mitad.

---

## 7. Inventario de referencia (snapshot 2026-10-13)

Foto del esquema MariaDB desplegado, tomada de `information_schema`. **No es la verdad del sistema**: es la referencia contra la que se comprueba que este contrato sigue describiendo la realidad. Cualquier migración que altere la forma lo actualiza en el mismo commit.

| Elemento | Valor observado | Sección que lo declara |
| :--- | ---: | :--- |
| Tablas | 20 | §2.1 |
| Columnas | 281 | §2.2 |
| Claves primarias y restricciones únicas | 32 (20 `PRIMARY` + 12 nombradas) | §2.3 |
| Índices secundarios no únicos | 56 | §2.3 (**D-9**) |
| Claves foráneas | 35 | §2.4 |
| Restricciones `CHECK` | 3 (`json_valid` en `audit_log`) | §2.5 (**D-4**) |
| Conjuntos `ENUM` distintos | 24 en 25 columnas | §2.6 |
| Columnas generadas | 2 (`incidents.is_active_ticket` VIRTUAL, `incident_replaced_parts.total_cost_snapshot` STORED) | **D-6**, **D-7** |
| Columnas `updated_at` con `ON UPDATE CURRENT_TIMESTAMP` | 11 | **D-2** |
| Columnas con `DEFAULT CURRENT_TIMESTAMP` | 30 | **D-2** |
| Disparadores `BEFORE DELETE` | 1 (`refund_requests`) | **D-8** |
| Colaciones en uso | `utf8mb4_unicode_ci` (tablas) y `utf8mb4_bin` (las 3 columnas JSON) | **D-5**, **D-4** |
| Motor de tablas | InnoDB | **D-10** |

**Migraciones que explican el inventario actual:** `database/schema.sql` (7 tablas base) y `database/migrations/003` a `018` (13 tablas más, columnas de pausa, inactividad, visibilidad del barrido, códigos de acceso, bloqueos y auditoría).

---

## 8. Lo que este contrato no cubre

1. **El DDL de PostgreSQL** (`database/supabase_init.sql`) y su conjunto de migraciones: son T-HYBRID-01, 02 y 23, y se escriben **conforme** a este contrato. Hasta que existan, la correspondencia está declarada pero **no materializada**.
2. **La convergencia del esquema desplegado** (una base creada solo con el DDL base recibe la migración posterior): T-HYBRID-23/24, parte 1.
3. **La paridad de datos y semillas** (EARS-02.1) y la **medición de RNF-01** contra el motor remoto (T-HYBRID-27).
4. **El esquema remoto de Supabase y el pooler Supavisor**: la suite compara el motor PostgreSQL local; el esquema remoto se verifica por su propio canal.
5. **Los índices implícitos que un motor crea y el otro no** (por ejemplo los que PostgreSQL asocia a una restricción): se normalizan por su semántica antes de comparar (**D-9**), nunca por su nombre.
6. **El diccionario de datos completo**: [`database_schema.md`](database_schema.md) sólo documenta 7 tablas; **este contrato no sustituye** a ese diccionario ni lo amplía.
