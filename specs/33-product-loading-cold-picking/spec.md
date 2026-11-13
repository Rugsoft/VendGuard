# ESPECIFICACIÓN FUNCIONAL: ÓRDENES DE CARGA DE FURGONETA DE REPOSICIÓN Y PICKING CON TRAZABILIDAD DE FRÍO (`ROUTE PRODUCT LOADING & COLD CHAMBER PICKING`)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)\
**Módulo:** 33 · Órdenes de Carga de Furgoneta de Reposición y Picking con Trazabilidad de Frío (*Route Product Loading & Cold Chamber Picking*)\
**Documento Canónico:** `specs/33-product-loading-cold-picking/spec.md` (y su espejo `specs/functional/product_loading_cold_picking_spec.md`)\
**Versión:** 2.0 · Incorpora las 4 Decisiones de Diseño (`D1`–`D4`), las 4 Reglas de Casos Límite e Invariantes (`C1`–`C4`) y las 4 Resoluciones de Auditoría QA (`QA 1`–`QA 4`) consensuadas con el Product Owner\
**Estado:** Especificación Funcional Aprobada por el Product Owner el 2026-11-13 · Puerta de Aprobación SDD Fase 1 superada\
**Fecha:** Noviembre 2026\
**Origen:** Funcionalidad 23 del catálogo [`docs/features_pendientes.md`](../../docs/features_pendientes.md) (Bloque 4 · Operativa de Carga, Reposición de Producto y Gestión de Reponedores)\
**Alineación Constitucional:** Art. I (supremacía de la especificación SDD e inadmisibilidad de código simulado en la propuesta, el despacho térmico y la liquidación al cierre), Art. II (prioridad sanitaria innegociable: cerrojo de salida de cámara frigorífica $\le 4,0\,^\circ\text{C}$, lote y fecha de caducidad futura acotada a `max_shelf_life_days` para referencias con `requires_cold_chain = TRUE`, exclusión de lotes caducados del stock útil de furgoneta y exclusión de máquinas en `SANITARY_QUARANTINE` u `OUT_OF_SERVICE` en la propuesta matutina), Art. III.1, III.2 y III.3 (prohibición estricta de borrado físico en `restocker_van_product_stocks`, `product_loading_orders` y `product_loading_order_items`, baja lógica de líneas retiradas en preparación e historial inmutable en `audit_log`), Art. IV (minimalismo tecnológico: 3 tablas relacionales limpias, sin dependencias externas), Art. V.1 (justificación obligatoria de al menos 20 caracteres reales en cancelaciones de órdenes, ajustes manuales de furgoneta y liquidaciones de cierre con descuadre), Art. V.4 (segregación estricta de costes `unit_cost_eur` y precios en la Hoja de Picking A4 y en la vista móvil del reponedor, más aislamiento horizontal entre reponedores y opacidad total frente a Sede/QR), Art. VI (delimitación sagrada del alcance sin adelantar visitas de reposición in situ ni arqueos de efectivo reservados a las Features 24–28).

---

## 1. Contexto y Objetivo

### 1.1. El Problema (Por qué)
Con el rol **Cargador / Reponedor de Ruta (`RESTOCKER`)** y sus cuadrantes territoriales operativos (Módulo 31) y el **Catálogo Maestro de Productos y Planogramas por Máquina** (Módulo 32) ya en producción, el servicio conoce qué máquinas atiende cada reponedor y qué carriles están en alerta (`STOCKOUT` / `LOW_STOCK`), pero carece del eslabón logístico y sanitario entre el Almacén Central / Cámara Frigorífica y la furgoneta de ruta:

1. **Cálculo manual del abastecimiento matutino de producto y consumibles:** Cada mañana, Coordinación y Almacén necesitan saber cuántas unidades de cada refresco, snack, sándwich, bolsa de café o tira de vasos debe cargar cada furgoneta según los huecos reales de los planogramas de las máquinas operativas de su zona, aplicando un colchón de seguridad y descontando el stock vigente que ya lleva el vehículo.
2. **Riesgo de rotura de cadena de frío o carga de lotes caducados en la salida de almacén (Art. II):** A diferencia de los repuestos electromecánicos del SAT (Módulos 18 y 23), los productos alimentarios refrigerados (`requires_cold_chain = TRUE`, incluyendo todo `PERISHABLE_FOOD`) requieren trazabilidad sanitaria por **código de lote (`batch_code`)**, **fecha de caducidad (`expiration_date`)** dentro de su vida útil máxima (`max_shelf_life_days`) y control estricto de la **temperatura de salida de cámara frigorífica ($\le 4,0\,^\circ\text{C}$)** antes de subir a la furgoneta isotérmica.
3. **Falta de liquidación de retorno al cierre del recorrido:** Al terminar la jornada, es necesario contrastar el stock disponible en la furgoneta menos las unidades abastecidas en máquinas y las mermas retiradas frente al conteo físico de retorno (`RECONCILED_AT_CLOSE`), auditando cualquier descuadre y dejando el inventario de la furgoneta cuadrado para el día siguiente.

### 1.2. El Objetivo (Qué)
Construir el sistema de **Órdenes de Carga de Furgoneta de Reposición, Inventario Móvil por Lote y Picking con Trazabilidad de Frío** que permita:

1. **Inventario de Producto en Furgoneta por Lote (`restocker_van_product_stocks`, Tabla 44):** Controlar las existencias transportadas por cada reponedor particionadas por `(restocker_id, product_id, batch_code)` con su `expiration_date` coherente (`QA 3`), semáforo sanitario de caducidad (`EXPIRED` 🔴, `EXPIRING_SOON` 🟡, `OK` 🟢) y ajuste manual auditado desde Coordinación (`D2`, `D4`).
2. **Propuesta Automática de Carga Matutina y Emisión Individual o en Lote (`D1`, `QA 1`):** Calcular la demanda agregada de las máquinas activas y operativas (excluyendo máquinas en `SANITARY_QUARANTINE` u `OUT_OF_SERVICE`, `QA 1`) en las zonas que cubre el reponedor hoy, permitiendo elegir entre **«Solo alertas (`ALERTS_ONLY`: carriles en `STOCKOUT` o `LOW_STOCK`)»** o **«Ruta completa (`ALL_GAPS`: todos los carriles con hueco)»** hasta llenar `max_capacity`, aplicando un porcentaje de colchón configurable (`safety_buffer_pct`: `0..50 %`) y descontando el stock no caducado ya disponible en la furgoneta.
3. **Ciclo de Vida de 4 Etapas + Cancelación (`product_loading_orders`, Tabla 45 y `product_loading_order_items`, Tabla 46) y Hoja de Picking A4 en 2 Secciones (`D2`, `D3`, `D4`):** Gestionar el flujo `PENDING_PICKING` $\rightarrow$ `DISPATCHED` $\rightarrow$ `CONFIRMED_BY_RESTOCKER` $\rightarrow$ `RECONCILED_AT_CLOSE` (y `CANCELLED` con justificación $\ge 20$ caracteres, Art. V.1), imprimiendo la **Hoja de Picking A4** separada en **«❄️ Cámara Frigorífica (≤ 4,0 °C)»** y **«📦 Almacén General y Consumibles»** sin ningún importe económico (Art. V.4).
4. **Cerrojo Sanitario de Salida de Cámara Frigorífica en el Despacho (`D2`, `QA 2`, `QA 3`, Art. II):** Exigir al pasar a `DISPATCHED` (cuando exista al menos una línea con `dispatched_qty > 0` y `requires_cold_chain = TRUE`) la temperatura `cold_chamber_exit_temp_celsius` en $[-5,0\,^\circ\text{C},\; 4,0\,^\circ\text{C}]$, el `batch_code` y una `expiration_date` estrictamente futura ($> \text{hoy}$) y dentro de `CURRENT_DATE + max_shelf_life_days` (`QA 2`), bloqueando el despacho ante cualquier desviación térmica, lote vencido o colisión de fecha en un mismo lote (`QA 3`).
5. **Confirmación Móvil en 1 Toque y Liquidación al Cierre (`RECONCILED_AT_CLOSE`) (`D3`, `QA 4`):** Permitir al reponedor confirmar la recepción en 1 toque desde `RestockerRouteView.js` (incrementando `restocker_van_product_stocks` por lote) y, al cierre del recorrido, ejecutar la liquidación (desde el móvil o desde Coordinación) sobre las líneas despachadas (`dispatched_qty > 0`, sellando automáticamente a `0` las líneas con `dispatched_qty = 0`, `QA 4`) contrastando `stock_lote - abastecidas_en_máquinas - mermas_retiradas = retorno_esperado` frente a `retorno_contado`, exigiendo justificación $\ge 20$ caracteres (Art. V.1) si hay descuadre y auditándolo en `audit_log` (Art. III.3).

### 1.3. Resumen de Decisiones de Diseño, Casos Límite y Resoluciones QA Consensuadas (`D1`–`D4`, `C1`–`C4`, `QA 1`–`QA 4`)

* **D1 (Cálculo de propuesta matutina con selector de modo `ALERTS_ONLY` / `ALL_GAPS`, colchón `0..50 %` y descuento de stock sano en furgoneta):**
  1. Para un reponedor activo, el sistema identifica todas las máquinas activas y operativas (`machines.is_active = TRUE` y `machines.status NOT IN ('SANITARY_QUARANTINE', 'OUT_OF_SERVICE')`, `QA 1`) de las Zonas Operativas vivas donde dicho reponedor actúa hoy (como titular `PRIMARY` disponible, o como suplente `BACKUP` en zonas donde el `PRIMARY` tiene ausencia vigente hoy o no hay titular, conforme a la cobertura determinista del Módulo 31).
  2. Sobre los carriles activos (`machine_planogram_slots.is_active = TRUE` con producto activo `vending_products.is_active = TRUE`) de esas máquinas, suma por `product_id` el hueco hasta capacidad máxima $\text{gap} = \sum (\text{max\_capacity} - \text{current\_estimated\_qty})$:
     * En modo **`ALERTS_ONLY` (por defecto):** incluye únicamente los carriles cuyo estado en el planograma sea `STOCKOUT` (`current_estimated_qty = 0`) o `LOW_STOCK` (`current_estimated_qty <= min_threshold`).
     * En modo **`ALL_GAPS` («Ruta completa»):** incluye todos los carriles con `current_estimated_qty < max_capacity`.
  3. Aplica el porcentaje de colchón configurable `safety_buffer_pct` (entero `0..50`, por defecto `10 %`):
     $$\text{demanda\_bruta} = \lceil \text{gap} \times (1 + \text{safety\_buffer\_pct} / 100) \rceil$$
  4. Descuenta las unidades vigentes no caducadas (`quantity_available` de lotes con `expiration_date IS NULL OR expiration_date > CURRENT_DATE`) que el reponedor ya tiene en `restocker_van_product_stocks`:
     $$\text{requested\_qty\_sugerida} = \max(0,\; \text{demanda\_bruta} - \text{stock\_útil\_furgoneta})$$
* **D2 (Trazabilidad de Lote, Caducidad y Temperatura de Cámara $\le 4,0\,^\circ\text{C}$ en el acto de Despacho `DISPATCHED` — Art. II):**
  1. **Al crear/editar la orden (`PENDING_PICKING`):** Se especifican las referencias (`product_id`) y cantidades solicitadas (`requested_qty`), permitiendo la emisión individual o masiva en lote en 1 clic antes de que almacén prepare físicamente los carros.
  2. **Al despachar almacén (`DISPATCHED`):**
     * **SI** la orden contiene al menos una línea activa con `dispatched_qty > 0` cuyo producto tiene `requires_cold_chain = TRUE`:
       * Es **obligatorio** declarar en la cabecera de la orden `cold_chamber_exit_temp_celsius` entre `-5.0` y `4.0` °C. Si falta o es $> 4,0\,^\circ\text{C}$, se bloquea el despacho con **`422 Unprocessable Entity` (`COLD_CHAMBER_TEMP_EXCEEDED`)** en cumplimiento del **Art. II**.
       * Es **obligatorio** declarar en cada línea con `requires_cold_chain = TRUE` y `dispatched_qty > 0` un `batch_code` normalizado (`^[A-Z0-9_-]{2,40}$`, distinto de `'SIN-LOTE'`) y una `expiration_date` estrictamente posterior a la fecha actual en `Europe/Madrid` (`expiration_date > CURRENT_DATE`) y que no supere `CURRENT_DATE + max_shelf_life_days` (`QA 2`).
     * **SI** una línea corresponde a un producto con `requires_cold_chain = FALSE` (refrescos, snacks, café, vasos/paletinas), `batch_code` y `expiration_date` son opcionales (si no se informan, se normalizan al código de lote canónico `'SIN-LOTE'` con `expiration_date = NULL`; si se informa `expiration_date`, debe ser igualmente $> \text{CURRENT\_DATE}$ y respetar `max_shelf_life_days` cuando esté definido).
  3. **Stock en furgoneta por lote (`restocker_van_product_stocks`):** La clave de unicidad es `(restocker_id, product_id, batch_code)`, permitiendo que en la furgoneta coexistan unidades de distintos lotes y fechas de caducidad para el mismo producto.
* **D3 (Confirmación móvil en 1 toque, Liquidación al Cierre `RECONCILED_AT_CLOSE` y unicidad de orden activa):**
  1. **Unicidad de orden activa por reponedor:** Un reponedor solo puede tener **1 orden activa** simultáneamente en cualquiera de los estados no terminales (`PENDING_PICKING`, `DISPATCHED` o `CONFIRMED_BY_RESTOCKER`), garantizado por índice único parcial en PostgreSQL.
  2. **Confirmación de recepción (`DISPATCHED` $\rightarrow$ `CONFIRMED_BY_RESTOCKER`):** El reponedor confirma la recepción en 1 toque desde `RestockerRouteView.js` (sin restricción de estado de turno laboral), sumando de forma atómica `dispatched_qty` en `restocker_van_product_stocks` para cada `(restocker_id, product_id, batch_code)`.
  3. **Liquidación al cierre (`CONFIRMED_BY_RESTOCKER` $\rightarrow$ `RECONCILED_AT_CLOSE`):** Ejecutable tanto por el propio **Reponedor desde su móvil** como por **Coordinación** (sin bloquear el fichaje laboral `CLOSE_SHIFT` del Módulo 31). Para cada línea activa con `dispatched_qty > 0` se declaran:
     * `restocked_in_machines_qty` ($\ge 0$): unidades abastecidas en máquinas durante la ruta.
     * `withdrawn_spoilage_qty` ($\ge 0$): mermas o unidades dañadas/retiradas en ruta.
     * `counted_return_qty` ($\ge 0$): unidades físicas reales de ese lote que retornan en la furgoneta al cierre.
     El sistema toma el saldo vigente del lote en la furgoneta antes de liquidar (`van_stock_before_reconcile`), valida que `restocked_in_machines_qty + withdrawn_spoilage_qty <= van_stock_before_reconcile`, calcula el retorno esperado:
     $$\text{expected\_return\_qty} = \text{van\_stock\_before\_reconcile} - \text{restocked\_in\_machines\_qty} - \text{withdrawn\_spoilage\_qty}$$
     y el descuadre:
     $$\text{discrepancy\_qty} = \text{counted\_return\_qty} - \text{expected\_return\_qty}$$
     Si en alguna línea $\text{discrepancy\_qty} \neq 0$, exige obligatoriamente `reconciliation_notes` con **$\ge 20$ caracteres reales** (**Art. V.1**). En la misma transacción actualiza `restocker_van_product_stocks.quantity_available = counted_return_qty` y audita el cierre y cualquier descuadre en `audit_log` (**Art. III.3**).
* **D4 (Hoja de Picking A4 dividida en Cámara Frigorífica y Almacén Seco sin importes, Semáforo de Caducidad en Furgoneta y Ajuste Manual Auditado):**
  1. **Hoja de Picking A4 en 2 bloques:** Maquetada para impresión directa (`window.print()`) dividiendo las líneas activas en **«❄️ Sección 1: Cámara Frigorífica (Cadena de Frío · Salida ≤ 4,0 °C · Art. II)»** (con recuadro destacado para temperatura de salida, lote y caducidad) y **«📦 Sección 2: Almacén General y Consumibles»**, ordenadas por urgencia (`STOCKOUT_ALERT` $\rightarrow$ `LOW_STOCK_ALERT` $\rightarrow$ `ROUTE_GAP_FILL` $\rightarrow$ `MANUAL_ADDITION`) y código `sku`, **omitiendo por completo `unit_cost_eur` y `sale_price_eur`** (**Art. V.4**).
  2. **Semáforo sanitario de caducidad en `restocker_van_product_stocks`:**
     * **`EXPIRED` (`🔴 Lote caducado`):** `expiration_date IS NOT NULL AND expiration_date <= CURRENT_DATE`. No cuenta como stock útil en `D1` y muestra alerta sanitaria de retirada obligatoria (**Art. II**).
     * **`EXPIRING_SOON` (`🟡 Caducidad próxima ≤ 48h`):** `expiration_date > CURRENT_DATE AND expiration_date <= CURRENT_DATE + 2`.
     * **`OK` (`🟢 Vigente`):** `expiration_date IS NULL OR expiration_date > CURRENT_DATE + 2`.
  3. **Ajuste manual de stock por lote en Coordinación:** Coordinación puede inicializar, ajustar o retirar unidades de un lote en `restocker_van_product_stocks` aportando motivo obligatorio $\ge 20$ caracteres reales (**Art. V.1**), auditando el saldo anterior y nuevo en `audit_log` (**Art. III.3**) sin ejecutar jamás `DELETE` físico (**Art. III.1**).
* **Reglas de Casos Límite e Invariantes (`C1`–`C4`):**
  * **C1 (Baja lógica de líneas en `PENDING_PICKING` y tope numérico `99.999` — Art. III.1):** Ninguna fila de las 3 tablas se borra físicamente (`prevent_hard_delete()`). Retirar una línea mientras la orden está en `PENDING_PICKING` aplica `is_active = FALSE` (reactivándola si el mismo producto se vuelve a añadir). Como los ingredientes de bebida caliente se miden en gramos (`GRAMS`, ej. bolsas de `1000 g` a `10000 g`), el tope de cantidad por línea y por lote en furgoneta se fija en `99.999` unidades/gramos (`1..99999` en `requested_qty`, `0..99999` en `dispatched_qty`, y saldo proyectado en furgoneta $\le 99999$).
  * **C2 (Bloqueo de reponedores inactivos, aviso de ausencia en orden individual y exclusión en lote):** No se pueden crear órdenes para usuarios inactivos ni para usuarios cuyo rol no sea `RESTOCKER`. Si el reponedor está activo pero tiene ausencia vigente hoy, se le excluye en la emisión masiva en lote pero se permite emitirle una orden individual mostrando la alerta **`⚠️ Destinatario actualmente ausente`**. Si entra en ausencia o baja teniendo una orden abierta, la orden muestra **`⚠️ Destinatario actualmente ausente / inactivo`** sin cancelarse en silencio.
  * **C3 (Unicidad de producto activo en `PENDING_PICKING` y tolerancia a producto desactivado en tránsito):** En `PENDING_PICKING` cada línea activa corresponde a un `product_id` distinto activo en el catálogo. Si un producto es dado de baja en `vending_products` después de haberse emitido o despachado la orden, se permite completar la recepción y la liquidación para no bloquear la mercancía física en tránsito.
  * **C4 (Comprobante móvil de última orden liquidada):** En `RestockerRouteView.js`, además de la tarjeta interactiva de su orden activa (`DISPATCHED` para confirmar en 1 toque, o `CONFIRMED_BY_RESTOCKER` para liquidar al cierre) y de la tabla de stock actual de su furgoneta con semáforo de caducidad, el reponedor visualiza en modo lectura el comprobante de su **última orden cerrada (`RECONCILED_AT_CLOSE`)**.
* **Resoluciones de Auditoría QA (`QA 1`–`QA 4`):**
  * **Resolución QA 1 (Exclusión de máquinas en `SANITARY_QUARANTINE` u `OUT_OF_SERVICE` en la propuesta matutina — Art. II):** En el cálculo de propuesta (`RF-02.1`), solo se computan carriles de máquinas activas cuyo estado operativo (`machines.status`) no sea `'SANITARY_QUARANTINE'` ni `'OUT_OF_SERVICE'`, exponiendo en la respuesta de propuesta el contador `excluded_unavailable_machines_count`. De este modo se impide extraer productos refrigerados de cámara frigorífica para máquinas precintadas o fuera de servicio.
  * **Resolución QA 2 (Cota superior de `expiration_date` según `max_shelf_life_days` del producto — Art. II):** Al despachar una orden (`RF-03.3`) o al dar de alta / incrementar un lote en furgoneta (`RF-01.3`), si el producto tiene `max_shelf_life_days IS NOT NULL` en `vending_products`, además de exigir `expiration_date > CURRENT_DATE` el sistema valida que `expiration_date <= CURRENT_DATE + max_shelf_life_days`; si supera la vida útil máxima del producto, rechaza la operación con **`422 Unprocessable Entity` (`EXPIRATION_EXCEEDS_MAX_SHELF_LIFE`)**.
  * **Resolución QA 3 (Inmutabilidad de `expiration_date` para un mismo `(restocker_id, product_id, batch_code)`):** Si en `restocker_van_product_stocks` ya existe un lote registrado para `(restocker_id, product_id, batch_code)` (con `batch_code != 'SIN-LOTE'`) y se intenta despachar, confirmar o ajustar ese mismo código de lote con una `expiration_date` diferente de la ya registrada, el sistema rechaza la operación con **`409 Conflict` (`BATCH_EXPIRATION_MISMATCH`)**.
  * **Resolución QA 4 (Autocierre en liquidación de líneas con `dispatched_qty = 0` y tipos nativos #28–#30):** Las líneas activas en las que almacén despachó `0` unidades (`dispatched_qty = 0`) no requieren entrada en el formulario de liquidación y se sellan automáticamente con `van_stock_before_reconcile = 0`, `restocked_in_machines_qty = 0`, `withdrawn_spoilage_qty = 0`, `expected_return_qty = 0`, `counted_return_qty = 0` y `discrepancy_qty = 0`. Asimismo, se declaran los tipos nativos `product_loading_order_status` (#28), `product_loading_demand_mode` (#29) y `product_loading_item_reason` (#30) en `specs/technical/schema_correspondence_contract.md`, elevando el catálogo de **43 a 46 tablas**.

---

## 2. Usuarios y Roles Implicados

| Usuario / Rol | Relación con el Módulo | Reglas de Privacidad y Permisos (Art. V.4) |
| :--- | :--- | :--- |
| **📊 Coordinador de Operaciones (`COORDINATOR`)** | Consulta el inventario por lote de todas las furgonetas de reposición, realiza ajustes auditados ($\ge 20$ caracteres), calcula propuestas automáticas (`ALERTS_ONLY` / `ALL_GAPS` + % colchón), crea órdenes individuales o en lote, edita órdenes en `PENDING_PICKING`, imprime la Hoja de Picking A4 en 2 secciones, registra el despacho de almacén (`DISPATCHED`) con control de cámara frigorífica ($\le 4,0\,^\circ\text{C}$), ejecuta o supervisa la liquidación (`RECONCILED_AT_CLOSE`) y cancela órdenes con justificación ($\ge 20$ caracteres). | Acceso completo al inventario de furgonetas de reposición y al ciclo de órdenes de carga. Toda mutación queda registrada en `audit_log` (Art. III.3). |
| **📦 Cargador / Reponedor de Ruta (`RESTOCKER`)** | Consulta en `RestockerRouteView.js` el stock por lote de su propia furgoneta (con alertas de caducidad), confirma en 1 toque la recepción de su orden `DISPATCHED`, ejecuta al final del recorrido la liquidación de cierre (`RECONCILED_AT_CLOSE`) declarando abastecidas, mermas y conteo de retorno (con justificación $\ge 20$ caracteres si hay descuadre) y consulta el comprobante de su última orden liquidada. | **Aislamiento horizontal y confidencialidad de costes (Art. V.4):** Solo puede ver, confirmar y liquidar sus propias órdenes y su propio stock de furgoneta (`403 Forbidden` ante órdenes de otro reponedor). Jamás recibe `unit_cost_eur` en ningún endpoint o DTO. No puede crear órdenes, despacharlas desde almacén ni alterar `dispatched_qty`. |
| **🔧 Técnico de Campo SAT (`TECHNICIAN`)** | Continúa operando con sus órdenes de repuestos técnicos (`van_restock_orders`, Módulo 23) sin interferencia. | **Segregación RBAC (`403 Forbidden`):** No tiene acceso a las órdenes de carga de productos comerciales ni al stock de furgonetas de reposición. |
| **🏢 Responsable de Sede (`LOCATION_RESPONSIBLE`) y Canal Público QR** | Ninguna interacción con este módulo. | **Opacidad total (Art. V.4):** Ningún dato sobre órdenes de carga, lotes, temperaturas de cámara, mermas o descuadres de furgoneta es visible en el Portal de Sede ni en el canal público QR. |

---

## 3. Historias de Usuario

### HU-01: Propuesta Automática de Carga Matutina y Emisión en Lote
> **Como** Coordinador de Operaciones,\
> **quiero** calcular automáticamente la necesidad de carga de un reponedor (o emitir en lote las órdenes de toda la plantilla disponible) sobre las máquinas operativas de sus zonas (excluyendo las que estén en cuarentena sanitaria o fuera de servicio), eligiendo entre cubrir solo los carriles en alerta (`STOCKOUT` / `LOW_STOCK`) o todos los huecos hasta capacidad máxima más un colchón porcentual y descontando el stock no caducado que ya lleva en su furgoneta,\
> **para** preparar cada mañana el abastecimiento exacto de cada ruta sin duplicar mercancía ni sacar perecederos para máquinas bloqueadas.

### HU-02: Hoja de Picking A4 Separada por Cámara Frigorífica y Almacén Seco
> **Como** Coordinador de Operaciones / Responsable de Almacén,\
> **quiero** imprimir una Hoja de Picking en formato A4 limpio dividida en dos secciones («❄️ Cámara Frigorífica ≤ 4,0 °C» y «📦 Almacén General y Consumibles»), ordenada por prioridad operativa y libre de precios o costes unitarios,\
> **para** que el operario de almacén prepare por separado el carro isotérmico de frío y el carro de seco sin exponer información financiera interna (Art. II y Art. V.4).

### HU-03: Despacho con Cerrojo Sanitario de Cámara Frigorífica y Lotes (Art. II)
> **Como** Coordinador de Operaciones,\
> **quiero** que al marcar una orden como `DISPATCHED` el sistema exija declarar la temperatura de salida de cámara frigorífica ($\le 4,0\,^\circ\text{C}$), el código de lote y una fecha de caducidad futura dentro de `max_shelf_life_days` en todos los productos con `requires_cold_chain = TRUE`, bloqueando el despacho si la cámara supera los $4,0\,^\circ\text{C}$, si algún lote está caducado o si el lote colisiona con otra fecha,\
> **para** blindar la cadena de frío desde la salida misma del almacén central (Art. II).

### HU-04: Recepción Móvil en 1 Toque y Control de Caducidades en Furgoneta
> **Como** Cargador / Reponedor de Ruta (`RESTOCKER`),\
> **quiero** ver en mi pantalla móvil `RestockerRouteView.js` mi orden `DISPATCHED` (con sus lotes, caducidades y temperatura de salida de cámara), confirmar su recepción con un solo toque actualizando al instante el inventario por lote de mi furgoneta y ver resaltados con semáforo sanitario los lotes próximos a caducar o caducados,\
> **para** salir a ruta con mi stock cargado y priorizar los lotes por fecha de vencimiento (FEFO).

### HU-05: Liquidación de Furgoneta al Cierre de Ruta y Auditoría de Descuadres
> **Como** Cargador / Reponedor de Ruta o Coordinador de Operaciones,\
> **quiero** liquidar la orden activa al terminar el recorrido (`RECONCILED_AT_CLOSE`) declarando por línea despachada las unidades abastecidas en máquinas, las mermas retiradas y el conteo físico de retorno en la furgoneta, calculando automáticamente cualquier descuadre y exigiendo justificación de al menos 20 caracteres reales si no cuadra,\
> **para** dejar el inventario de la furgoneta exacto para la mañana siguiente y auditar cualquier diferencia de stock (Art. III.3 y Art. V.1).

---

## 4. Requisitos Funcionales (RF) y Criterios de Aceptación (EARS)

### RF-01 · Inventario de Producto en Furgoneta por Lote (`restocker_van_product_stocks`), Semáforo de Caducidad y Ajuste Auditado (`D2`, `D4`, `C1`, `QA 2`, `QA 3`)
El sistema deberá mantener el stock físico transportado en la furgoneta de cada reponedor desglosado por producto y código de lote, con control sanitario de caducidad y prohibición de borrado físico.

* **Criterio EARS 01.1 (Estructura e invariantes de `restocker_van_product_stocks` — D2, C1 y Art. III.1):**
  1. **EL SISTEMA DEBERÁ** persistir las existencias por lote en la Tabla 44 `restocker_van_product_stocks` (`id`, `restocker_id`, `product_id`, `batch_code`, `expiration_date`, `quantity_available`, `created_at`, `updated_at`), con restricción de unicidad `uq_restocker_van_product_batch UNIQUE (restocker_id, product_id, batch_code)`, cota `quantity_available BETWEEN 0 AND 99999`, disparador anti-borrado físico `trg_no_delete_restocker_van_product_stocks` (`prevent_hard_delete()`, Art. III.1) y auditoría bajo `'RESTOCKER_VAN_STOCK'` en `audit_entity_type`.
  2. **EL SISTEMA DEBERÁ** normalizar `batch_code` en mayúsculas (`^[A-Z0-9_-]{2,40}$`), usando `'SIN-LOTE'` cuando no se especifique lote en productos con `requires_cold_chain = FALSE`.
* **Criterio EARS 01.2 (Semáforo determinista de caducidad por lote en furgoneta — D4 y Art. II):**
  1. **EL SISTEMA DEBERÁ** calcular para cada registro de `restocker_van_product_stocks` su estado sanitario `expiry_status` respecto a la fecha actual en `Europe/Madrid`:
     * `'EXPIRED'` (`🔴 Caducado`): cuando `expiration_date IS NOT NULL AND expiration_date <= CURRENT_DATE`.
     * `'EXPIRING_SOON'` (`🟡 Próximo a caducar`): cuando `expiration_date IS NOT NULL AND expiration_date > CURRENT_DATE AND expiration_date <= CURRENT_DATE + 2`.
     * `'OK'` (`🟢 Vigente`): cuando `expiration_date IS NULL OR expiration_date > CURRENT_DATE + 2`.
  2. **MIENTRAS** un lote en la furgoneta tenga `quantity_available > 0` y `expiry_status = 'EXPIRED'`, **EL SISTEMA DEBERÁ** excluir sus unidades del cómputo de stock útil disponible en las propuestas matutinas (`D1`) y mostrar una alerta sanitaria destacada en Coordinación y en `RestockerRouteView.js` para su retirada/baja (**Art. II**).
* **Criterio EARS 01.3 (Ajuste manual auditado desde Coordinación y coherencia de lote — D4, QA 2, QA 3, Art. III.3 y Art. V.1):**
  1. **CUANDO** Coordinación solicite dar de alta o ajustar la cantidad de un lote en la furgoneta de un reponedor activo (`PUT /api/coordinator/restocker-van-stocks/{restockerId}`), **EL SISTEMA DEBERÁ** exigir un motivo (`reason`) de al menos **20 caracteres reales** (`422 INVALID_JUSTIFICATION_LENGTH` si es menor, Art. V.1), validar que para productos con `requires_cold_chain = TRUE` se indique `batch_code` (distinto de `'SIN-LOTE'`) y `expiration_date` (admitiendo ajustar a `0` o reducir un lote ya caducado para registrar su retirada de la furgoneta, pero bloqueando dar de alta o incrementar unidades de un lote con `expiration_date <= CURRENT_DATE` mediante `422 EXPIRED_OR_INVALID_BATCH`, o con `expiration_date > CURRENT_DATE + max_shelf_life_days` mediante `422 EXPIRATION_EXCEEDS_MAX_SHELF_LIFE`, `QA 2`), verificar que si el lote ya existía no se altere su `expiration_date` (`409 BATCH_EXPIRATION_MISMATCH`, `QA 3`), realizar `UPSERT` sobre `(restocker_id, product_id, batch_code)` y registrar en `audit_log` el saldo previo, el nuevo saldo y el motivo.

---

### RF-02 · Propuesta Automática de Carga Matutina y Emisión Individual o en Lote (`D1`, `C1`, `C2`, `C3`, `QA 1`)
El sistema deberá calcular las necesidades de abastecimiento de cada furgoneta a partir de los planogramas de las máquinas operativas de sus zonas cubiertas hoy.

* **Criterio EARS 02.1 (Cálculo de propuesta automática por modo de demanda, colchón y exclusión de máquinas no operativas — D1, QA 1):**
  1. **CUANDO** Coordinación consulte la propuesta de carga para un reponedor activo (`GET /api/coordinator/product-loading-orders/proposal?restocker_id={id}&demand_mode={ALERTS_ONLY|ALL_GAPS}&safety_buffer_pct={0..50}`), **EL SISTEMA DEBERÁ**:
     * Determinar las Zonas Operativas activas cubiertas hoy por ese reponedor (zonas donde es `PRIMARY` sin ausencia vigente hoy, más zonas donde es `BACKUP` sin ausencia vigente hoy y el `PRIMARY` está ausente o vacante, según el motor del Módulo 31).
     * Identificar las máquinas activas (`machines.is_active = TRUE`) de esas zonas cuyo estado operativo no sea `'SANITARY_QUARANTINE'` ni `'OUT_OF_SERVICE'`, contabilizando aparte en `excluded_unavailable_machines_count` cuántas máquinas activas de sus zonas fueron omitidas por encontrarse en cuarentena sanitaria o fuera de servicio (`QA 1`).
     * Seleccionar todos los carriles activos (`machine_planogram_slots.is_active = TRUE`) con productos activos (`vending_products.is_active = TRUE`) de dichas máquinas operativas que cumplan el filtro `demand_mode`:
       * En `'ALERTS_ONLY'` (valor por defecto): carriles con `current_estimated_qty = 0` (`STOCKOUT`) o `current_estimated_qty <= min_threshold` (`LOW_STOCK`).
       * En `'ALL_GAPS'`: todos los carriles con `current_estimated_qty < max_capacity`.
     * Para cada producto con hueco $\text{gap} = \sum (\text{max\_capacity} - \text{current\_estimated\_qty}) > 0$, calcular:
       $$\text{gross\_demand\_qty} = \lceil \text{gap} \times (1 + \text{safety\_buffer\_pct} / 100) \rceil$$
     * Restar el stock útil no caducado en la furgoneta del reponedor ($\text{usable\_van\_stock\_qty} = \sum \text{quantity\_available}$ donde `expiration_date IS NULL OR expiration_date > CURRENT_DATE`):
       $$\text{suggested\_qty} = \min\big(99999,\; 99999 - \text{total\_van\_stock\_qty},\; \max(0,\; \text{gross\_demand\_qty} - \text{usable\_van\_stock\_qty})\big)$$
     * Clasificar el motivo de inclusión (`reason`) de cada línea sugerida con `suggested_qty > 0` por la mayor urgencia de sus carriles afectados:
       1. `'STOCKOUT_ALERT'` si al menos un carril afectado de ese producto está en `STOCKOUT` (`current_estimated_qty = 0`);
       2. `'LOW_STOCK_ALERT'` si no hay carriles en `STOCKOUT` pero al menos uno está en `LOW_STOCK` (`current_estimated_qty <= min_threshold`);
       3. `'ROUTE_GAP_FILL'` si todos los carriles afectados están en estado `OK` (modo `ALL_GAPS`).
* **Criterio EARS 02.2 (Ordenación determinista por bloque de almacén y urgencia — D4):**
  1. **EL SISTEMA DEBERÁ** ordenar las líneas de la propuesta, de la orden y de la Hoja de Picking A4 agrupando primero por exigencia de cadena de frío (`requires_cold_chain = TRUE` primero $\rightarrow$ `requires_cold_chain = FALSE` después), luego por prioridad de motivo (`'STOCKOUT_ALERT'` $\rightarrow$ `'LOW_STOCK_ALERT'` $\rightarrow$ `'ROUTE_GAP_FILL'` $\rightarrow$ `'MANUAL_ADDITION'`) y finalmente por `sku` ascendente.
* **Criterio EARS 02.3 (Creación individual, unicidad de orden activa y generación en lote — D1, D3, C1, C2):**
  1. **MIENTRAS** un reponedor tenga una orden de carga en estado `'PENDING_PICKING'`, `'DISPATCHED'` o `'CONFIRMED_BY_RESTOCKER'`, **EL SISTEMA DEBERÁ** rechazar cualquier intento de crearle otra orden con `409 Conflict` (`ACTIVE_LOADING_ORDER_EXISTS`), indicando el `order_code` de la orden activa.
  2. **SI** Coordinación intenta crear una orden para un usuario inactivo o cuyo rol no sea `'RESTOCKER'`, **ENTONCES EL SISTEMA DEBERÁ** rechazar la operación con `422 Unprocessable Entity` (`INVALID_RESTOCKER_RECIPIENT`).
  3. **CUANDO** Coordinación ejecute la emisión masiva en lote (`POST /api/coordinator/product-loading-orders/batch` con `demand_mode` y `safety_buffer_pct`), **EL SISTEMA DEBERÁ** evaluar a todos los reponedores activos, omitir a los que tengan ausencia vigente hoy, a los que ya tengan una orden activa y a los que tengan `0` líneas sugeridas, crear una orden en `PENDING_PICKING` para cada reponedor elegible y devolver el desglose de órdenes creadas y reponedores omitidos con su causa exacta.

---

### RF-03 · Ciclo de Edición en Preparación, Hoja de Picking A4 y Despacho con Cerrojo de Frío (`D2`, `D4`, `C1`, `C3`, `QA 2`, `QA 3`, Art. II, Art. V.1, Art. V.4)
El sistema gestionará la preparación de la orden, la impresión de la Hoja de Picking A4 en dos bloques y el control sanitario de salida de cámara frigorífica al despachar.

* **Criterio EARS 03.1 (Tablas `product_loading_orders` y `product_loading_order_items` y baja lógica en edición — D2, D3, C1, QA 4 y Art. III.1):**
  1. **EL SISTEMA DEBERÁ** persistir las órdenes en la Tabla 45 `product_loading_orders` (`id`, `order_code`, `restocker_id`, `status`, `demand_mode`, `safety_buffer_pct`, `cold_chamber_exit_temp_celsius`, `notes`, `cancel_reason`, `reconciliation_notes`, `created_by`, `dispatched_by`, `reconciled_by`, `created_at`, `dispatched_at`, `confirmed_at`, `reconciled_at`, `cancelled_at`, `updated_at`) y sus líneas en la Tabla 46 `product_loading_order_items` (`id`, `order_id`, `product_id`, `reason`, `requested_qty`, `dispatched_qty`, `batch_code`, `expiration_date`, `van_stock_before_reconcile`, `restocked_in_machines_qty`, `withdrawn_spoilage_qty`, `expected_return_qty`, `counted_return_qty`, `discrepancy_qty`, `is_active`, `created_at`, `updated_at`), protegidas por `prevent_hard_delete()` (Art. III.1) y auditadas bajo `'PRODUCT_LOADING_ORDER'`.
  2. **CUANDO** Coordinación edite una orden en estado `'PENDING_PICKING'` (`PUT /api/coordinator/product-loading-orders/{id}`), **EL SISTEMA DEBERÁ** realizar `UPSERT` sobre `(order_id, product_id)` y marcar con `is_active = FALSE` cualquier línea retirada (sin ejecutar `DELETE`), exigiendo al menos 1 línea activa con `requested_qty BETWEEN 1 AND 99999`.
* **Criterio EARS 03.2 (Hoja de Picking A4 en 2 Secciones sin importes económicos — D4 y Art. V.4):**
  1. **CUANDO** Coordinación solicite la Hoja de Picking A4 (`GET /api/coordinator/product-loading-orders/{id}/picking-sheet` o impresión en UI), **EL SISTEMA DEBERÁ** estructurar el documento con cabecera identificativa (`order_code`, fecha, reponedor, código de operario, zonas cubiertas, modo de demanda, `%` colchón y estado), seguida de:
     * **«❄️ Sección 1: Cámara Frigorífica (Cadena de Frío · Salida ≤ 4,0 °C · Art. II)»:** recuadro de temperatura de salida de cámara (`cold_chamber_exit_temp_celsius`) y tabla de productos con `requires_cold_chain = TRUE` (SKU, nombre, unidad, lote, fecha de caducidad, cantidad solicitada, cantidad despachada, motivo y casilla de verificación `[ ]`).
     * **«📦 Sección 2: Almacén General y Consumibles»:** tabla de productos con `requires_cold_chain = FALSE` y recuadros inferiores de firma de entrega (Almacén) y recepción (Reponedor).
  2. **EL SISTEMA DEBERÁ** omitir de forma absoluta cualquier precio de venta (`sale_price_eur`), coste unitario (`unit_cost_eur`) o valoración monetaria en la Hoja de Picking A4 (**Art. V.4**).
* **Criterio EARS 03.3 (Despacho de almacén con Cerrojo Sanitario de Cámara Frigorífica y validación de vida útil / lote — D2, QA 2, QA 3 y Art. II):**
  1. **CUANDO** Coordinación marque como `'DISPATCHED'` una orden que estaba en `'PENDING_PICKING'` (`POST /api/coordinator/product-loading-orders/{id}/dispatch`), **EL SISTEMA DEBERÁ** validar que cada línea activa tenga `dispatched_qty` entero entre `0` y `99999`, que la suma total de `dispatched_qty` en la orden sea $\ge 1$, y que el saldo proyectado en la furgoneta no supere `99999`.
  2. **SI** la orden incluye al menos una línea activa con `dispatched_qty > 0` cuyo producto tiene `requires_cold_chain = TRUE`, **ENTONCES EL SISTEMA DEBERÁ**:
     * Exigir `cold_chamber_exit_temp_celsius` numérico entre `-5.0` y `4.0` °C; si no se proporciona o es $> 4,0\,^\circ\text{C}$ (o $< -5,0\,^\circ\text{C}$), **rechazar el despacho con `422 Unprocessable Entity` (`COLD_CHAMBER_TEMP_EXCEEDED`)** (**Art. II**).
     * Exigir en cada línea con `requires_cold_chain = TRUE` y `dispatched_qty > 0` un `batch_code` válido (`^[A-Z0-9_-]{2,40}$`, distinto de `'SIN-LOTE'`) y una `expiration_date` estrictamente posterior al día actual en `Europe/Madrid` (`expiration_date > CURRENT_DATE`); si falta o `expiration_date <= CURRENT_DATE`, **rechazar el despacho con `422 Unprocessable Entity` (`EXPIRED_OR_INVALID_BATCH`)** (**Art. II**).
  3. **SI** en cualquier línea con `dispatched_qty > 0` se proporciona `expiration_date` y el producto tiene `max_shelf_life_days IS NOT NULL` en `vending_products`, **ENTONCES EL SISTEMA DEBERÁ** verificar que `expiration_date <= CURRENT_DATE + max_shelf_life_days`, rechazando la operación con **`422 Unprocessable Entity` (`EXPIRATION_EXCEEDS_MAX_SHELF_LIFE`)** si excede dicho techo (`QA 2`).
  4. **SI** en cualquier línea con `dispatched_qty > 0` y `batch_code != 'SIN-LOTE'` ya existe en `restocker_van_product_stocks` para ese `(restocker_id, product_id, batch_code)` una `expiration_date` diferente de la informada, **ENTONCES EL SISTEMA DEBERÁ** rechazar el despacho con **`409 Conflict` (`BATCH_EXPIRATION_MISMATCH`)** (`QA 3`).
  5. **SI** ninguna línea con `dispatched_qty > 0` tiene `requires_cold_chain = TRUE`, `cold_chamber_exit_temp_celsius` será opcional (`NULL` o en rango $[-5.0, 4.0]$) y en las líneas con `dispatched_qty > 0` los campos `batch_code` y `expiration_date` serán opcionales (normalizando `batch_code` vacío a `'SIN-LOTE'`).
* **Criterio EARS 03.4 (Cancelación auditada con justificación $\ge 20$ caracteres — Art. V.1):**
  1. **CUANDO** Coordinación solicite cancelar una orden en estado `'PENDING_PICKING'` o `'DISPATCHED'` (`POST /api/coordinator/product-loading-orders/{id}/cancel`), **EL SISTEMA DEBERÁ** exigir un `cancel_reason` de al menos **20 caracteres reales** (`422 INVALID_JUSTIFICATION_LENGTH` si no los alcanza, Art. V.1), transicionar la orden a `'CANCELLED'` sin alterar `restocker_van_product_stocks` y registrar la cancelación en `audit_log`.
  2. **SI** la orden ya se encuentra en `'CONFIRMED_BY_RESTOCKER'`, `'RECONCILED_AT_CLOSE'` o `'CANCELLED'`, **ENTONCES EL SISTEMA DEBERÁ** rechazar la cancelación con `409 Conflict` (`ORDER_CANCELLATION_NOT_ALLOWED`) porque la mercancía ya fue recepcionada en la furgoneta (debiendo cerrarse vía liquidación) o ya es terminal.

---

### RF-04 · Recepción Móvil en 1 Toque y Liquidación de Cierre (`RECONCILED_AT_CLOSE`) (`D3`, `C3`, `C4`, `QA 3`, `QA 4`, Art. III.3, Art. V.1)
El reponedor podrá recepcionar el lote despachado en un toque y liquidar la ruta al finalizar, cuadrando el inventario real de su furgoneta.

* **Criterio EARS 04.1 (Confirmación atómica de recepción en 1 toque — D2, D3, C3, QA 3 y Art. III.3):**
  1. **CUANDO** el reponedor destinatario confirme la recepción de su orden en estado `'DISPATCHED'` (`POST /api/restocker/product-loading-orders/{id}/confirm`), **EL SISTEMA DEBERÁ** ejecutar en una transacción atómica con bloqueo `SELECT ... FOR UPDATE`:
     * Verificar que la orden pertenece al `restocker_id` autenticado (`403 Forbidden` en caso contrario) y que sigue en estado `'DISPATCHED'` (`409 INVALID_ORDER_STATE_TRANSITION` si ya fue confirmada o cancelada).
     * Para cada línea activa con `dispatched_qty > 0`, hacer `UPSERT` en `restocker_van_product_stocks` sobre `(restocker_id, product_id, batch_code)` sumando `dispatched_qty` a `quantity_available` (verificando el tope `<= 99999` y la coherencia de `expiration_date` según `QA 3`).
     * Transicionar la orden a `'CONFIRMED_BY_RESTOCKER'` con sello `confirmed_at = NOW()` y auditar en `audit_log` el incremento de stock por lote.
* **Criterio EARS 04.2 (Liquidación de cierre de ruta `RECONCILED_AT_CLOSE`, sellado de líneas en `0` y auditoría de descuadre — D3, QA 4, Art. III.3 y Art. V.1):**
  1. **CUANDO** el propio reponedor (`POST /api/restocker/product-loading-orders/{id}/reconcile`) o Coordinación (`POST /api/coordinator/product-loading-orders/{id}/reconcile`) liquide una orden que se encuentra en estado `'CONFIRMED_BY_RESTOCKER'`, **EL SISTEMA DEBERÁ** ejecutar en una transacción atómica con bloqueo `SELECT ... FOR UPDATE`:
     * Para cada línea activa con `dispatched_qty = 0`, sellar automáticamente `van_stock_before_reconcile = 0`, `restocked_in_machines_qty = 0`, `withdrawn_spoilage_qty = 0`, `expected_return_qty = 0`, `counted_return_qty = 0` y `discrepancy_qty = 0` sin exigir entrada manual (`QA 4`).
     * Para cada línea activa con `dispatched_qty > 0`, leer el saldo actual del lote `(restocker_id, product_id, batch_code)` en `restocker_van_product_stocks` (`van_stock_before_reconcile`) y validar que se declaren enteros no negativos `restocked_in_machines_qty >= 0`, `withdrawn_spoilage_qty >= 0` y `counted_return_qty` (`0..99999`), cumpliendo `restocked_in_machines_qty + withdrawn_spoilage_qty <= van_stock_before_reconcile` (`422 RECONCILIATION_EXCEEDS_AVAILABLE_STOCK` en caso contrario).
     * Calcular en cada línea con `dispatched_qty > 0`:
       $$\text{expected\_return\_qty} = \text{van\_stock\_before\_reconcile} - \text{restocked\_in\_machines\_qty} - \text{withdrawn\_spoilage\_qty}$$
       $$\text{discrepancy\_qty} = \text{counted\_return\_qty} - \text{expected\_return\_qty}$$
     * **SI** en al menos una línea $\text{discrepancy\_qty} \neq 0$ **Y** el campo `reconciliation_notes` tiene menos de **20 caracteres reales**, **ENTONCES EL SISTEMA DEBERÁ** rechazar la liquidación con **`422 Unprocessable Entity` (`RECONCILIATION_DISCREPANCY_REASON_REQUIRED`)** (**Art. V.1**).
     * Actualizar `restocker_van_product_stocks.quantity_available = counted_return_qty` para cada lote liquidado, guardar en `product_loading_order_items` los valores de liquidación, pasar la orden a `'RECONCILED_AT_CLOSE'` (`reconciled_at = NOW()`, `reconciled_by = actor_id`) y registrar en `audit_log` el resumen completo de liquidación y las líneas con descuadre (**Art. III.3**).

---

### RF-05 · Superficies de Interfaz en Coordinación y en `RestockerRouteView.js` (`D1`–`D4`, `C4`, `QA 1`, Art. V.4)
* **Criterio EARS 05.1 (Consola de Órdenes de Carga y Stock de Furgonetas de Reposición en Coordinación):**
  1. **EL SISTEMA DEBERÁ** ofrecer en Coordinación la gestión integrada de:
     * **«🚚 Órdenes de Carga de Producto y Cámara Frigorífica»:** KPIs (`En picking`, `Despachadas`, `En ruta (Confirmadas)`, `Liquidadas hoy`), generador de propuesta individual y en lote (con selector `Solo alertas` / `Ruta completa`, `% colchón` e indicador de máquinas omitidas por cuarentena/fuera de servicio `QA 1`), listado de órdenes (abiertas primero), modal de edición en `PENDING_PICKING`, modal de despacho (`DISPATCHED`) con validación de temperatura de cámara frigorífica ($\le 4,0\,^\circ\text{C}$), lote y caducidad, modal de liquidación de cierre (`RECONCILED_AT_CLOSE`), modal de cancelación justificada ($\ge 20$ caracteres) y vista de impresión de la **Hoja de Picking A4** en 2 secciones.
     * **«🚐 Stock de Producto en Furgonetas (Por Lote)»:** filtro por reponedor, categoría y estado de caducidad (`🔴 Caducados`, `🟡 Próximos ≤ 48h`, `🟢 Vigentes`), y modal de ajuste manual auditado ($\ge 20$ caracteres).
* **Criterio EARS 05.2 (Vista móvil del Reponedor en `RestockerRouteView.js` con opacidad de costes — D3, D4, C4 y Art. V.4):**
  1. **EL SISTEMA DEBERÁ** mostrar en `RestockerRouteView.js` para el usuario `RESTOCKER`:
     * Si tiene una orden en `'DISPATCHED'`: banner destacado con temperatura de salida de cámara, detalle de lotes/cantidades y botón **«✅ Confirmar Recepción en Furgoneta (1 Toque)»**.
     * Si tiene una orden en `'CONFIRMED_BY_RESTOCKER'`: tarjeta de orden en ruta con botón **«📋 Liquidar Cierre de Furgoneta»** que abre el formulario de cuadre por línea (`abastecidas`, `mermas`, `retorno contado`, cálculo en vivo de descuadre y nota obligatoria $\ge 20$ caracteres si hay diferencia).
     * Bloque **«🚐 Mi Stock en Furgoneta (Por Lote / FEFO)»** ordenado por fecha de caducidad más próxima primero (FEFO), con semáforo sanitario (`🔴 Caducado`, `🟡 Caduca pronto`, `🟢 OK`) y comprobante de solo lectura de su **última orden liquidada (`RECONCILED_AT_CLOSE`)** (`C4`).
     * **Opacidad de costes (Art. V.4):** Ninguna respuesta ni vista servida a `RESTOCKER` incluirá jamás `unit_cost_eur`.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 · Prohibición de Borrado Físico e Inmutabilidad de Auditoría (Art. III.1 y III.3):** Las Tablas 44 (`restocker_van_product_stocks`), 45 (`product_loading_orders`) y 46 (`product_loading_order_items`) contarán con disparadores `prevent_hard_delete()`.
* **RNF-02 · Defensa en Profundidad del Cerrojo Térmico y de Caducidad (Art. II):** El techo de temperatura de salida de cámara frigorífica ($\le 4,0\,^\circ\text{C}$) y la obligatoriedad de lote y caducidad en líneas despachadas de productos `requires_cold_chain = TRUE` se impondrán tanto en el servicio de dominio PHP como mediante restricciones `CHECK` y disparadores en PostgreSQL.
* **RNF-03 · Fidelidad al Sistema de Diseño (`docs/design.md`) e Impresión A4 Limpia (Art. IV):** Componentes en Vue 3 ES Modules nativos sin librerías externas, bordes conservadores (`4px` / `8px`), paleta corporativa (`#2560ff`, `#f9fafb`, `#2c333f`, `#c8cfda`) y maquetación `@media print` A4 sin importes económicos.

---

## 6. Casos Límite

1. **Intento de despachar una orden con sándwiches (`requires_cold_chain = TRUE`) a $5,2\,^\circ\text{C}$, con un lote que caduca hoy o que supera `max_shelf_life_days` (`D2`, `QA 2`, Art. II):** Almacén intenta pasar a `DISPATCHED` una orden que incluye 10 unidades de *"Sándwich Mixto"* (`max_shelf_life_days = 5`) indicando `cold_chamber_exit_temp_celsius = 5.2` (o `expiration_date = hoy`, o `expiration_date = hoy + 30 días`). El sistema rechaza la operación con `422 COLD_CHAMBER_TEMP_EXCEEDED`, `422 EXPIRED_OR_INVALID_BATCH` o `422 EXPIRATION_EXCEEDS_MAX_SHELF_LIFE`, y el disparador SQL en PostgreSQL impide igualmente la persistencia si se invoca por SQL directo.
2. **Exclusión de máquina en `SANITARY_QUARANTINE` u `OUT_OF_SERVICE` en la propuesta matutina (`QA 1`, Art. II):** En la Zona Norte hay una máquina `PERISHABLE_FOOD` en `SANITARY_QUARANTINE` con 8 huecos de sándwich y otra máquina `COMBO` activa (`OK`) con 4 huecos de sándwich. Al pedir la propuesta matutina, el sistema reclama únicamente los `4` sándwiches de la máquina operativa y devuelve `excluded_unavailable_machines_count = 1`.
3. **Intento de despachar un lote existente en furgoneta con fecha de caducidad contradictoria (`QA 3`):** El reponedor ya tiene en su furgoneta 3 unidades del lote `LOTE-SW-01` con caducidad `2026-11-16`. Al despachar una nueva orden con el mismo código `LOTE-SW-01` pero indicando `2026-11-18`, el sistema rechaza la operación con `409 BATCH_EXPIRATION_MISMATCH`.
4. **Lote caducado en la furgoneta excluido automáticamente de la propuesta matutina (`D1`, `D4`, Art. II):** El reponedor tenía en su furgoneta 4 sándwiches del lote `L-ANTIGUO` cuya `expiration_date` venció ayer (`expiry_status = 'EXPIRED'`) y 2 sándwiches del lote `L-VIGENTE` que caduca en 3 días. Si las máquinas de su zona necesitan 10 sándwiches hoy, la propuesta matutina solo descuenta los `2` sándwiches vigentes (sugiriendo cargar `8`, nunca `4`) y muestra la alerta roja de lote caducado en la furgoneta para su retirada.
5. **Liquidación al cierre con descuadre ($\text{discrepancy\_qty} \neq 0$) sin justificación suficiente vs. con justificación $\ge 20$ caracteres (`D3`, Art. V.1):** Un lote tenía 20 latas en la furgoneta; el reponedor declara `12` abastecidas en máquinas y `0` mermas ($\text{retorno esperado} = 8$), pero cuenta `7` latas de retorno físico ($\text{descuadre} = -1$). Si intenta cerrar con `reconciliation_notes = "falta 1"`, recibe `422 RECONCILIATION_DISCREPANCY_REASON_REQUIRED`. Al aportar una justificación de $\ge 20$ caracteres reales, la orden pasa a `RECONCILED_AT_CLOSE`, el stock de la furgoneta queda fijado en `7` y el descuadre `-1` queda auditado en `audit_log`.
6. **Doble clic al confirmar recepción o al liquidar (`D3`):** Gracias al bloqueo pesimista `SELECT ... FOR UPDATE` sobre `product_loading_orders`, el segundo clic concurrente detecta que la orden ya cambió de estado y responde `409 INVALID_ORDER_STATE_TRANSITION` sin duplicar el incremento ni la liquidación de stock.

---

## 7. Fuera de Alcance

Quedan explícitamente excluidos del alcance de este Módulo 33 (reservados a las Features 24 a 28 según el Art. VI):
1. **Ejecución paso a paso de la visita de reposición a pie de máquina (`machine_restock_visits`), apertura de puerta con control térmico de cabina y checklist diario de higiene:** Reservado a la **Feature 24**.
2. **Recaudación de efectivo, carga de tubos de cambio y arqueo de bolsas precintadas:** Reservado a la **Feature 25**.
3. **Despacho de incidencias de Nivel 1 al reponedor y escalado de averías electromecánicas al SAT:** Reservado a la **Feature 26**.
4. **Calendario de frecuencias periódicas por máquina (`DAILY`, `MON_WED_FRI`, etc.) y optimización geográfica de paradas de carga:** Reservado a la **Feature 27**.
5. **Cuadro de mando analítico de *Stockout Rate* y mermas comerciales históricas:** Reservado a la **Feature 28**.

---

## 8. Criterios de Finalización (Definition of Done)

1. Existen las Tablas 44 (`restocker_van_product_stocks`), 45 (`product_loading_orders`) y 46 (`product_loading_order_items`) en `database/supabase_init.sql` y en la migración incremental `029_product_loading_orders_and_cold_picking.sql`, con los tipos nativos #28 (`product_loading_order_status`), #29 (`product_loading_demand_mode`) y #30 (`product_loading_item_reason`) (`QA 4`), restricciones `CHECK`, índice único de orden activa por reponedor, disparadores `prevent_hard_delete()` (`Art. III.1`), disparador del cerrojo térmico/lote en despacho (`Art. II`) y entidades `'RESTOCKER_VAN_STOCK'` y `'PRODUCT_LOADING_ORDER'` en `audit_entity_type`.
2. El motor de propuestas calcula la demanda de las máquinas activas y operativas (excluyendo `SANITARY_QUARANTINE` y `OUT_OF_SERVICE`, `QA 1`) de las zonas cubiertas hoy por el reponedor según `demand_mode` (`ALERTS_ONLY` | `ALL_GAPS`) y `safety_buffer_pct` (`0..50 %`), descontando exclusivamente el stock útil no caducado de la furgoneta (`D1`, `D4`), y permite emitir órdenes individuales o en lote (`C2`).
3. El flujo de órdenes soporta edición con baja lógica en `PENDING_PICKING` (`C1`), impresión de la **Hoja de Picking A4** en 2 secciones (`Cámara Frigorífica` y `Almacén General`) sin importes económicos (`D4`, `Art. V.4`), despacho `DISPATCHED` con bloqueo sanitario si `cold_chamber_exit_temp_celsius > 4.0 °C`, lote caducado, fecha superior a `CURRENT_DATE + max_shelf_life_days` (`QA 2`) o colisión de fecha en lote existente (`QA 3`), y cancelación con justificación $\ge 20$ caracteres (`Art. V.1`).
4. El reponedor puede confirmar en 1 toque su orden `DISPATCHED` desde `RestockerRouteView.js`, consultar su stock por lote ordenado por FEFO con semáforo de caducidad, ver su última orden liquidada (`C4`) y ejecutar (él o Coordinación) la liquidación de cierre `RECONCILED_AT_CLOSE` sellando automáticamente líneas con `dispatched_qty = 0` (`QA 4`), auditando cualquier descuadre con justificación $\ge 20$ caracteres (`D3`, `Art. III.3`, `Art. V.1`) y sin exponer jamás `unit_cost_eur` (`Art. V.4`).
5. Toda la batería de pruebas unitarias, de integración HTTP y de blindaje constitucional pasa al **100 % en verde**.

---

## 9. Dudas Abiertas

* Ninguna. Las 4 decisiones de diseño (`D1`–`D4`), las 4 reglas de casos límite (`C1`–`C4`) y las 4 resoluciones de auditoría QA (`QA 1`–`QA 4`) han sido consensuadas con el Product Owner.
