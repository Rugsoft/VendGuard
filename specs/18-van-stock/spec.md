# ESPECIFICACIÓN FUNCIONAL: STOCK DE REPUESTOS EN FURGONETA (VAN STOCK)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 18 · Stock de Repuestos en Furgoneta  
**Documento Canónico:** `specs/18-van-stock/spec.md`  
**Estado:** Aprobada por el Product Owner el 2026-10-21 · Implementada y verificada (ciclo SDD cerrado: fases 3, 4 y 5)  
**Fecha:** Octubre 2026  
**Alineación Constitucional:** Artículos I al VII de la Constitución de VendGuard (Especialmente Art. III.1, Art. IV, Art. V.1, Art. V.4 y Art. VI.1)

---

## 1. Autorización Formal del Alcance (Art. VI.1)

El Artículo VI.1 de la Constitución prohíbe escribir código o diseñar esquemas para la *"gestión de inventario de furgonetas"* mientras el MVP no esté 100 % operativo y **formalmente aprobado**. `AGENTS.md` §5.1 reproduce la misma prohibición.

Precondición satisfecha y declaración del Product Owner (2026-10-21), verbatim:

> "confirmo que la fase 1 queda formalmente cerrada y de momento autorizo modulo 18 con alcance mínimo (stock + descuento + alerta, sin albarán automático)"

Consecuencias normativas de esta autorización:

1. La Fase 1 (MVP) queda **formalmente cerrada** por declaración expresa del Product Owner. La evidencia ejecutable de su cierre es el acta [`docs/verificacion_criterios_finalizacion_mvp.md`](../../docs/verificacion_criterios_finalizacion_mvp.md) (2026-10-08, 8/8 casillas demostradas).
2. El módulo 18 queda autorizado **exclusivamente** en el alcance mínimo descrito en §2.1. Todo lo listado en §2.2 sigue vedado hasta autorización expresa y nueva enmienda de spec.
3. La guarda `tests/unit/ConstitutionalAuditTest.php` §7.1 (auditoría automática del Art. VI) debe **enmendarse** para retirar el término `van_inventory` de su lista de términos prohibidos, conservando intactos los términos de telemetría IoT/MDB/DEX y pasarelas de pago. La enmienda cita la autorización de este apartado como evidencia. Ver §8.2.

---

## 2. Alcance

### 2.1. Dentro del alcance (alcance mínimo autorizado)

1. **Inventario declarado:** stock de repuestos transportado por cada técnico, por pareja `(técnico, pieza del catálogo maestro M2)`, con cantidad disponible y umbral mínimo de reposición.
2. **Descuento automático:** decremento de ese stock al declarar piezas sustituidas, tanto en la resolución de avería correctiva como en el completado de inspección preventiva.
3. **Alerta de stock bajo:** detección de las parejas técnico/pieza que han alcanzado o rebajado su umbral mínimo, publicada en una superficie de Coordinación.
4. **Consulta de superficies:** el técnico consulta su propio stock en modo lectura; el coordinador lo carga, lo ajusta y lo audita.

### 2.2. Fuera del alcance (excluido explícitamente)

Queda **fuera** de este módulo, y no debe implementarse ni diseñarse aquí:

* **Albarán de reposición automática** desde Base Central hacia la furgoneta, y cualquier trasvase logístico de custodia física. Es una exclusión ya declarada en la spec aprobada de M2: [`specs/functional/spare_parts_spec.md`](../functional/spare_parts_spec.md) §167 *("No se generan albaranes logísticos de custodia física ni trasvases de furgoneta en esta fase")* y §173, [`specs/06-spare-parts/plan.md`](../06-spare-parts/plan.md) §306 y [`specs/technical/spare_parts_contracts.md`](../technical/spare_parts_contracts.md) §Art. VI. Incorporarlo exigiría **enmienda previa de la spec de M2** (Art. I + `AGENTS.md` §5.5), no una simple ampliación de este módulo.
  > **Enmienda SDD (2026-10-26, Módulo 23):** El Product Owner autoriza la Fase 2 en [`specs/23-van-restock-stock-aware-triage/spec.md`](../23-van-restock-stock-aware-triage/spec.md), habilitando las órdenes de carga (`van_restock_orders`, `van_restock_order_items`), la Hoja de Carga Matutina (Picking A4 sin costes), la confirmación móvil (`CONFIRMED_BY_TECH`) y el cruce informativo en triaje (`TechnicianAssignmentSuggester`).
* Órdenes de compra a proveedores, picking, gestión de almacenes físicos o ubicaciones en estanterías.
* Valoración monetaria del inventario de furgoneta (no se almacena coste en el stock, ver RD-05).
* Nuevas plantillas de notificación en el catálogo cerrado de `notification_template` (el aviso es una superficie de interfaz, no un canal).
* Autoservicio del técnico (que el propio técnico modifique su stock por API).
* Modo offline / IndexedDB (feature independiente del bloque, no autorizada).

---

## 3. Usuarios y Perfiles Implicados

| Perfil / Actor | Rol en el Stock de Furgoneta | Permisos y Restricciones |
| :--- | :--- | :--- |
| **📊 Coordinador de Operaciones** | Actor de configuración y control. Carga el stock inicial de cada furgoneta, ajusta cantidades y umbrales, y consulta las alertas de stock bajo. | Control total sobre el inventario de cualquier técnico. Toda alta o ajuste queda auditada (Art. III.3). |
| **📱 Técnico de Campo / Ruta** | Beneficiario operativo. Consulta el stock que transporta y ve el descuento al declarar piezas. | **Solo lectura** sobre su propio stock. No puede alterar cantidades ni umbrales vía API. El descuento es un efecto del sistema, no una acción suya. |
| **🏢 Responsable de Sede** | Cliente / sede receptora del servicio de vending. | **Acceso estrictamente bloqueado (Art. V.4):** cero visibilidad sobre stock, repuestos, cantidades, umbrales o alertas internas. La segregación se verifica con una suite constitucional propia (RF-06.2). |

---

## 4. Historias de Usuario

### HU-01: Carga y Ajuste del Stock de una Furgoneta
> **Como** Coordinador de Operaciones,  
> **quiero** registrar qué piezas del catálogo transporta cada técnico y en qué cantidad, con su umbral mínimo de reposición,  
> **para** saber por primera vez qué lleva realmente cada furgoneta antes de asignar una avería.

### HU-02: Descuento Automático al Declarar Piezas Sustituidas
> **Como** Coordinador de Operaciones,  
> **quiero** que el stock del técnico se descuente solo cuando declara una pieza sustituida en la resolución de una avería o en el completado de un preventivo,  
> **para** mantener el inventario fiel sin pedirle al técnico un registro administrativo adicional.

### HU-03: Alerta de Stock Bajo
> **Como** Coordinador de Operaciones,  
> **quiero** ver de un vistazo qué piezas de qué técnicos han tocado su umbral mínimo,  
> **para** planificar la reposición antes de que la falta de pieza provoque un desplazamiento en balde o una segunda visita.

### HU-04: Consulta del Propio Stock en Ruta
> **Como** Técnico de Campo,  
> **quiero** consultar desde mi vista de ruta qué piezas llevo y cuáles estoy por agotar,  
> **para** decidir si puedo resolver la avería en la primera visita o debo pedir pieza.

---

## 5. Requisitos Funcionales con Criterios de Aceptación (EARS)

### RF-01: Inventario de Stock por Técnico y Pieza

* **RF-01.1: Registro y Ajuste:** el coordinador debe poder registrar o ajustar el stock de un técnico para una pieza del catálogo maestro, fijando **cantidad disponible** y **umbral mínimo**. El alta y el ajuste operan sobre la pareja única `(technician_id, spare_part_id)`: si la fila no existe se crea, si existe se actualiza su cantidad y su umbral.
* **RF-01.2: Validaciones de Dominio:**
  * `quantity_available`: entero con signo, acotado entre `-999` y `9999` (cota anti-errata, análoga a `INVALID_PART_QUANTITY` de M2).
  * `min_threshold`: entero acotado entre `0` y `9999`.
  * La pieza debe existir en `spare_parts` (incluidas las de baja lógica: una furgoneta puede llevar aún una pieza descatalogada).
  * El usuario debe ser un `TECHNICIAN` existente, activo o no (un técnico de baja conserva su inventario histórico).
* **RF-01.3: Criterios de Aceptación EARS:**
  * **[Ubiquitous]** El sistema debe impedir la existencia de dos filas de stock para la misma pareja `(technician_id, spare_part_id)`.
  * **[Unwanted behaviour]** Si el coordinador envía una cantidad o umbral fuera de las cotas declaradas, el sistema debe rechazar la operación con `422` y el código `INVALID_VAN_STOCK_QUANTITY`, sin escribir nada.
  * **[Unwanted behaviour]** Si la pieza o el técnico referenciados no existen, el sistema debe rechazar la operación con `404` y el código `VAN_STOCK_TARGET_NOT_FOUND`.
  * **[Ubiquitous]** La baja de una fila de stock debe ser **lógica** (cantidad `0`), nunca un borrado físico: el disparador `prevent_hard_delete()` de Art. III.1 se aplica a la nueva tabla.

### RF-02: Descuento Automático al Declarar Piezas Sustituidas

* **RF-02.1: Punto Único de Descuento:** el decremento debe ejecutarse en el punto donde se persiste la línea de pieza sustituida, mediante **un único método compartido** invocado desde los dos caminos existentes: resolución de avería correctiva (`SparePartTraceabilityService::resolveIncidentWithParts()`) y completado de preventivo (`SparePartTraceabilityService::recordPreventiveReplacedParts()`). No se duplica la lógica de descuento por camino.
* **RF-02.2: Alcance del Descuento:**
  * Se descuenta **una unidad de stock por unidad declarada** (`quantity` de la línea), contra el stock del **técnico que declara**.
  * **Pieza catalogada:** descuenta. Si el técnico no tenía fila previa de esa pieza, se crea con cantidad inicial `0` y se descuenta sobre ella.
  * **Pieza fuera de catálogo** (`spare_part_id = NULL`): **no descuenta** (no tiene identidad en el catálogo). Ver RD-01.
* **RF-02.3: Atomicidad y Reintentos:** el descuento se aplica en la misma unidad de escritura que la línea de pieza sustituida. Ver RD-02.
* **RF-02.4: Criterios de Aceptación EARS:**
  * **[Event-driven]** Cuando el técnico resuelva una avería declarando piezas catalogadas, el sistema debe decrementar su stock por la cantidad declarada de cada línea, dentro de la misma operación que registra la línea.
  * **[Event-driven]** Cuando el técnico complete una inspección preventiva declarando piezas catalogadas, el sistema debe decrementar su stock con la misma regla que en el camino correctivo.
  * **[Ubiquitous]** El descuento **nunca** puede impedir la resolución de la avería ni el completado del preventivo: el cierre justificado (Art. V.1) y la prioridad sanitaria (Art. II) prevalecen sobre la contabilidad del inventario. Ver RD-04.
  * **[Unwanted behaviour]** Si la línea declarada es una pieza fuera de catálogo, el sistema no debe crear ni modificar ninguna fila de stock, y la declaración debe seguir registrándose con normalidad.
  * **[State-driven]** Mientras una avería se reabra dentro de la ventana de 48 h (Art. V.6) y se declaren piezas nuevas, cada declaración debe descontar de forma aditiva, sin recalcular ni revertir los descuentos anteriores. Ver RD-03.

### RF-03: Alerta de Stock Bajo

* **RF-03.1: Definición Objetiva del Umbral:** una pareja `(técnico, pieza)` está **bajo umbral** cuando `quantity_available <= min_threshold`. Con `min_threshold = 0`, la condición cubre el stock a cero y el stock negativo.
* **RF-03.2: Superficie de la Alerta:** el panel de Coordinación debe exponer la lista de parejas bajo umbral, con técnico, código y nombre de pieza, cantidad disponible, umbral, y una marca de severidad cuando la cantidad es negativa (descuadre). Orden por severidad descendente: primero las negativas, después por menor cantidad disponible.
* **RF-03.3: Criterios de Aceptación EARS:**
  * **[Ubiquitous]** El sistema debe devolver la lista de parejas bajo umbral en una única consulta filtrada, sin recorrer el inventario completo del lado cliente.
  * **[Unwanted behaviour]** Si no existe ninguna pareja bajo umbral, el sistema debe devolver una lista vacía y la interfaz debe mostrar un estado vacío explicativo, nunca una tabla en blanco sin contexto.
  * **[State-driven]** Mientras `quantity_available < 0`, la fila debe marcarse visiblemente como **descuadre** (se declararon más unidades de las cargadas), nunca ocultarse ni truncarse a `0`.
  * **[Ubiquitous]** La alerta **no** usa el centro de notificaciones: es una superficie de interfaz de Coordinación, y no añade valores al enum `notification_template` (ver §2.2).

### RF-04: Superficies de Consulta y Gestión

* **RF-04.1: Vista del Técnico (solo lectura):** la vista de ruta del técnico debe listar su propio stock con código, nombre de pieza, cantidad disponible y una insignia de *"bajo umbral"* cuando `quantity_available <= min_threshold`. No expone el umbral como campo editable ni permite mutación alguna.
* **RF-04.2: Panel del Coordinador:** el panel de Coordinación debe permitir consultar el inventario por técnico, cargar y ajustar cantidades y umbrales, y ver la alerta de RF-03.
* **RF-04.3: Criterios de Aceptación EARS:**
  * **[Ubiquitous]** El endpoint del técnico debe devolver exclusivamente las filas cuyo `technician_id` es el del usuario autenticado; ninguna consulta del técnico puede parametrizar el técnico objetivo.
  * **[Unwanted behaviour]** Si una petición de mutación llega con sesión de `TECHNICIAN`, el sistema debe rechazarla con `403` (el ajuste es competencia exclusiva del coordinador).
  * **[State-driven]** Mientras el técnico no tenga ninguna fila de stock, su vista debe mostrar un estado vacío explicativo, sin error.
  * **[Ubiquitous]** La vista del técnico no expone importes ni costes de pieza (Art. V.4): el stock es un conteo de unidades.

### RF-05: Auditoría y Trazabilidad

* **RF-05.1: Registro de Cambios:** toda alta o ajuste de stock por el coordinador debe registrar un evento en `audit_log` con actor, técnico destino, pieza, cantidad anterior, cantidad nueva y umbral, en la misma operación que la escritura.
* **RF-05.2: Criterios de Aceptación EARS:**
  * **[Event-driven]** Cuando el coordinador cree o ajuste una fila de stock, el sistema debe registrar el evento de auditoría con el delta aplicado.
  * **[Ubiquitous]** El descuento automático (RF-02) queda trazado por la vía ya existente de la línea de pieza sustituida (`incident_replaced_parts`, origen inmutable del consumo) y **no** genera un evento de auditoría duplicado.
  * **[Ubiquitous]** No debe existir ninguna sentencia de borrado físico sobre la nueva tabla; el inventario histórico se conserva íntegro (Art. III).

### RF-06: Privacidad y Aislamiento (Art. V.4)

* **RF-06.1: Rutas y Roles:** todos los endpoints del módulo se registran tras el middleware de rol interno: `GET /api/technician/van-stock` con `TECHNICIAN`; el resto con `COORDINATOR`. No se registra ninguna ruta de stock en el portal de sede ni en el escaneo público.
* **RF-06.2: Verificación Constitucional:** una suite constitucional propia debe demostrar que ni el portal de sede ni el escaneo público exponen stock, cantidades, umbrales ni alertas, con la misma forma que la verificación territorial del Módulo 17.
* **RF-06.3: Criterios de Aceptación EARS:**
  * **[Ubiquitous]** El sistema debe denegar con `403` cualquier acceso a los endpoints de stock desde una sesión de Responsable de Sede.
  * **[Ubiquitous]** La proyección de respuesta al portal de sede no debe incluir ninguna clave de stock, cantidad o umbral (se amplía la lista de claves vedadas del saneador de sede con las claves del módulo).

---

## 6. Requisitos No Funcionales (RNF)

* **RNF-01 (Rendimiento):** la consulta del propio stock del técnico debe responder en menos de 250 ms, en línea con el presupuesto de movilidad de M2 (RNF-REP-02).
* **RNF-02 (Dogma Vanilla, Art. IV):** backend PHP 8.2 puro con PDO sobre PostgreSQL; frontend Vue 3 estándar sin bundler. **Cero dependencias nuevas.**
* **RNF-03 (Tipado y Contratos):** `declare(strict_types=1);` en todo el código nuevo; DTO de entrada tipado; sin `mocks` ni stubs en rutas de producción (Art. I.3).
* **RNF-04 (Guardas del repositorio):** el módulo debe dejar en verde las guardas estructurales afectadas (§8.2) y no debe introducir literales de color nuevos en JavaScript (techo del trinquete de deuda de tokens congelado).
* **RNF-05 (Compatibilidad hacia atrás):** la incorporación del módulo no puede alterar el comportamiento observable de ningún endpoint existente: los payloads de resolución y de completado de preventivo mantienen su contrato actual, y el descuento es un efecto interno.

---

## 7. Resoluciones de Diseño (cierran ambigüedades antes de escribir código)

**RD-01 · Pieza fuera de catálogo: no descuenta, no bloquea.** Una pieza fuera de catálogo se persiste hoy con `spare_part_id = NULL`, `is_out_of_catalog = true` y `unit_cost_snapshot = 0.00`. Sin identidad en el catálogo no hay pareja `(técnico, pieza)` que decrementar, así que el descuento se omite y la declaración se registra igual. Alternativa descartada: bloquear la declaración (rompería una regla de negocio vigente) o inventar una pieza fantasma en el catálogo (crearía datos basura). Limitación aceptada y visible: la interfaz de resolución indica que esa línea no afecta al stock.

**RD-02 · Atomicidad: misma unidad de escritura que la línea de pieza.** Hoy el bucle de piezas sustituidas se ejecuta con autocommit fuera de la transacción que cierra la avería (`SparePartTraceabilityService`). El descuento se aplica en esa misma unidad por línea, sin ampliar ni reordenar la transacción existente. Razón: (a) el hecho físico —la pieza salió de la furgoneta— es verdadero con independencia de que la transición del ticket culmine; (b) envolver todo en una transacción nueva cambiaría el comportamiento de un flujo que no se ha pedido modificar (RNF-05).

> **Enmienda (2026-10-22, Módulo 19, Q3).** El camino **correctivo** deja de cumplir el «sin ampliar ni reordenar»: `SparePartTraceabilityService::resolveIncidentWithParts()` pasa a envolver en una única transacción las piezas, el descuento, las solicitudes, la transición a `RESOLVED`, el encolado y la hoja de servicio. Razón: el albarán firmado imprime la lista de piezas y un fallo a mitad dejaba piezas y descuentos sin cierre, que el reintento duplicaba. El camino **preventivo** mantiene la unidad por línea descrita arriba. No cambia RD-04: `consume` no consulta disponibilidad y admite saldos negativos. Véase [`specs/19-digital-signature/spec.md`](../19-digital-signature/spec.md) RD-16.

**RD-03 · Reapertura: descuento aditivo, nunca reversión.** La spec de M2 ya declara que las piezas de una reapertura dentro de las 48 h se computan como **registros aditivos** sobre el primer cierre. El stock sigue esa misma regla: cada declaración descuenta una vez, sin reversión ni recálculo. No se necesita tabla de idempotencia: una línea declarada equivale a una unidad física que salió del vehículo.

**RD-04 · Stock negativo: aritmética honesta, con marca de descuadre.** El stock puede quedar por debajo de cero si se declaran más unidades de las cargadas. Se conserva el valor negativo y se marca como **descuadre** (RF-03.3), en vez de truncar a cero o bloquear el cierre. Razón: truncar escondería la señal real de negocio —una carga no registrada— y bloquear chocaría con el Art. V.1 (cierre justificado) y con la prioridad sanitaria del Art. II.

**RD-05 · Sin coste en el stock: el snapshot del Art. III no se toca.** La tabla de stock almacena únicamente unidades y umbral. No tiene coste, ni valoración, ni precio de compra. Razón: el coste ya se congela de forma inmutable en `incident_replaced_parts.unit_cost_snapshot` desde `spare_parts.reference_cost`; duplicar un coste en el stock abriría la puerta a dos verdades económicas y a romper la inmutabilidad histórica. El contrato de 17 columnas de `incident_replaced_parts` queda intacto.

---

## 8. Impacto en Artefactos Existentes y Guardas

### 8.1. Artefactos que el módulo debe tocar

| Artefacto | Qué cambia |
| :--- | :--- |
| `database/supabase_migrations/009_technician_van_stocks.sql` | **Nuevo.** Migración incremental del módulo, con cabecera `POR QUÉ EXISTE / QUÉ DECLARA / IDEMPOTENCIA` y `IF NOT EXISTS` / `DO ... EXCEPTION WHEN duplicate_object`, como 007 y 008. |
| `database/supabase_init.sql` | DDL base de la tabla nueva, su disparador `trg_no_delete_*`, índices y `CHECK`; más el valor nuevo del enum de auditoría (`ALTER TYPE ... ADD VALUE IF NOT EXISTS`, patrón del Módulo 17). |
| `database/supabase_seeds.sql` + `src/Infrastructure/Database/SeedRunner.php` | Semillas del inventario de demostración (un método nuevo junto a `seedSpareParts()`). |
| `specs/technical/schema_correspondence_contract.md` | Inventario de tablas del contrato (23 → 24 filas numeradas), columnas de la tabla nueva y su blindaje. |
| `tests/integration/SchemaConvergenceParityTest.php` y `tests/integration/PostgresSchemaTest.php` | **Listas de tablas escritas a mano en ambos ficheros**: alta de la tabla nueva, y recuento de disparadores `BEFORE DELETE` (25 → 26). |
| `tests/Support/TestDataCleaner.php` (+ `TestDataCleanerIsolationTest.php`) | Orden de purgado de la tabla hija nueva, con su espejo en la suite de aislamiento. |
| `src/Application/Service/SparePartTraceabilityService.php` | Invocación del descuento compartido en los dos caminos (correctivo y preventivo). |
| `src/Presentation/Routing/AppRouter.php` | Registro de las rutas nuevas tras el middleware de rol. |
| `tests/Support/DocMetricsGuard.php` + README/CHANGELOG | Sincronización de las cifras de batería (suites/aserciones) que la guarda audita. |
| `docs/features_pendientes.md` | Marcar la feature #5 con su estado real (documento **no versionado**, `.gitignore:21`), sin prometer el albarán excluido. |

### 8.2. Guardas que el módulo debe dejar en verde

1. `tests/unit/ConstitutionalAuditTest.php` §7.1 — **enmienda requerida**: retirar `van_inventory` de la lista de términos prohibidos, citando la autorización de §1, conservando `telemetry`, `mdb_protocol`, `dex_protocol`, `payment_gateway`, `stripe` y `paypal`. Nomenclatura permitida: `technician_van_stocks` no contiene el literal prohibido.
2. `tests/unit/DesignTokenDebtRatchetTest.mjs` — techo congelado de literales hexadecimales en JavaScript: ningún color en línea nuevo.
3. `tests/Support/DocMetricsGuard.php` — cifras de batería sincronizadas tras añadir suites.
4. Guardas de esquema de §8.1 y guarda de purgado de datos de test.
5. Stubs de repositorio en tests unitarios que implementan interfaces de dominio: cualquier ampliación de interfaz obliga a actualizar todos los stubs que la implementan.

---

## 9. Criterios de Aceptación / Definition of Done

- [x] El inventario de stock existe por pareja única `(técnico, pieza)` con cantidad y umbral, y no admite borrado físico.
- [x] El coordinador puede cargar y ajustar el stock de cualquier técnico, con auditoría del delta.
- [x] El descuento se aplica por un **único método compartido** desde los dos caminos de declaración (correctivo y preventivo), por la cantidad declarada.
- [x] La pieza fuera de catálogo no descuenta y no altera el flujo de declaración.
- [x] El stock puede quedar negativo y se muestra marcado como descuadre.
- [x] La alerta de stock bajo se publica en Coordinación con orden por severidad y estado vacío explicativo.
- [x] El técnico consulta su propio stock en solo lectura, sin poder parametrizar a otro técnico ni mutar nada.
- [x] Ninguna superficie de sede ni de escaneo público expone stock, cantidades ni umbrales (suite constitucional propia).
- [x] La resolución de avería y el completado de preventivo **no** pueden fallar por causa del inventario.
- [x] Cero dependencias nuevas; frontend en Vue 3 estándar y backend en PHP puro tipado.
- [x] La batería global (`php tests/run_all.php`) queda en verde y las cifras documentadas se sincronizan.
- [x] Las guardas de §8.2 quedan en verde, incluida la enmienda de la auditoría del Art. VI.
- [x] El albarán de reposición automática **no** se ha implementado (§2.2).

---

## 10. Trazabilidad Documental

* **Origen de la petición:** feature #5 del catálogo de pendientes, `docs/features_pendientes.md` §5 (*"🚐 Inventario de Repuestos en Furgoneta (Van Stock)"*), bloque 2, complejidad estimada Media-Alta (3 días). El documento **no está versionado** en el repositorio, por lo que esta spec es la referencia normativa del módulo.
* **Base normativa previa:** [`specs/functional/spare_parts_spec.md`](../functional/spare_parts_spec.md) (RF-REP-01 a RF-REP-10) y [`specs/technical/spare_parts_contracts.md`](../technical/spare_parts_contracts.md), de las que este módulo hereda el catálogo maestro, el patrón de snapshot inmutable de coste y la exclusión de albaranes.
* **Artefactos de las fases 3 y 4 (redactados y cerrados):** `specs/18-van-stock/plan.md` (diseño técnico y matriz de trazabilidad), `specs/18-van-stock/tasks.md` (tareas T-VAN-01 a T-VAN-11 con su bitácora de ejecución) y `specs/technical/van_stock_contracts.md` (contratos HTTP y DDL definitivo).
* **Documento espejo:** `specs/functional/van_stock_spec.md` mantiene la copia espejo de este documento canónico; su cabecera declara la referencia inversa.
---

## 11. Límites Conocidos y Verificación Final

* **Suelo de saldo `-999` (`chk_van_stock_quantity_range`):** el saldo de una pareja `(técnico, pieza)` no puede bajar de `-999`. Un descuadre acumulado que rebasara ese suelo abortaría la escritura y, con ella, el cierre de la avería, que es justo lo que RD-04 quiere impedir. El rango admite 999 sustituciones sin reponer, así que el caso es alcanzable solo en teoría; queda escrito como límite conocido del módulo, no como defecto a descubrir en producción. Si alguna vez se aproxima, la salida es reponer stock —lo que devuelve el saldo a positivo—, no ampliar el rango.
* **Albarán de reposición automática:** no implementado, por alcance autorizado (§2.2). La alerta de bajo umbral es la señal operativa; la propuesta de albarán, los pedidos, el picking y la valoración monetaria siguen siendo trabajo futuro.
* **Verificación final:** `php tests/run_all.php` → **312 suites · 12.108 aserciones · 0 fallos**, cifras documentadas coherentes y base restaurada a semillas. Las suites propias del módulo son `PostgresVanStockSchemaTest` (18/18, canal `new_engine`), `VanStockItemTest` (17/17), `VanStockServiceTest` (29/29), `SparePartTraceabilityServiceTest` (48/48 con los apartados del descuento), `VanStockApiTest` (30/30, canal `new_engine`), `VanStockPrivacyTest` (9/9), `TechnicianVanStockPanelTest` (20/20) y `CoordinatorVanStockTabTest` (30/30). No quedó ninguna prueba del módulo sin ejecutar: las dos del canal `new_engine` se corrieron por su canal propio contra el motor local (`deploy/local/postgres/.env.local`).
