# ESPECIFICACIÓN FUNCIONAL: CATÁLOGO MAESTRO DE PRODUCTOS, CONSUMIBLES Y PLANOGRAMA POR MÁQUINA (`MACHINE PLANOGRAM & SLOT MATRIX`)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)\
**Módulo:** 32 · Catálogo Maestro de Productos, Consumibles y Planograma por Máquina (*Machine Planogram & Slot Matrix*)\
**Documento Canónico:** `specs/32-product-catalog-planogram/spec.md` (y su espejo `specs/functional/product_catalog_planogram_spec.md`)\
**Versión:** 2.0 · Incorpora las 3 Decisiones de Diseño (`D1`–`D3`), las 3 Decisiones de Casos Límite e Invariantes (`C1`–`C3`) y las 4 Resoluciones de Auditoría QA (`QA 1`–`QA 4`) consensuadas con el Product Owner\
**Estado:** Especificación Funcional Aprobada por el Product Owner el 2026-11-12 · Puerta de Aprobación SDD Fase 1 superada\
**Fecha:** Noviembre 2026\
**Origen:** Funcionalidad 22 del catálogo [`docs/features_pendientes.md`](../../docs/features_pendientes.md) (Bloque 4 · Operativa de Carga, Reposición de Producto y Gestión de Reponedores)\
**Alineación Constitucional:** Art. I (supremacía de la especificación SDD e inadmisibilidad de código simulado), Art. II (prioridad sanitaria innegociable: cerrojo bidireccional en dominio y base de datos que prohíbe asignar productos con `requires_cold_chain = TRUE` a máquinas cuya tipología no sea `PERISHABLE_FOOD` ni `COMBO`, e impide mutar retrospectivamente el producto o la tipología de máquina vulnerando dicho cerrojo), Art. III.1, III.2 y III.3 (prohibición estricta de borrado físico en `vending_products` y `machine_planogram_slots`, baja lógica mediante `is_active = FALSE`, inmutabilidad de `sku` y trazabilidad completa en `audit_log`), Art. IV (minimalismo tecnológico: 2 tablas nucleares sin tablas extra de plantillas, usando presets de dominio por modelo y clonación directa entre máquinas equivalentes), Art. V.4 (segregación estricta de costes `unit_cost_eur` y márgenes, exclusivos de `COORDINATOR` y ocultos a `RESTOCKER`, `TECHNICIAN`, Portal de Sede y vistas públicas QR, más aislamiento territorial del reponedor), Art. VI (delimitación sagrada del alcance sin adelantar órdenes de carga de furgoneta ni visitas de reposición in situ reservadas a las Features 23–28).

---

## 1. Contexto y Objetivo

### 1.1. El Problema (Por qué)
Tras incorporar en el Módulo 31 el rol operativo **Cargador / Reponedor de Ruta (`RESTOCKER`)** y sus cuadrantes territoriales de carga, el sistema necesita modelar **qué productos comerciales y consumibles** (refrescos, snacks, sándwiches/lácteos frescos, café en grano, leche en polvo, chocolate, azúcar, vasos y paletinas) existen en el catálogo de explotación y **cómo se distribuyen físicamente dentro de cada máquina** (el **Planograma o Matriz de Carriles / Tolvas**, `slot_code`), junto con sus capacidades máximas, existencias estimadas y umbrales de alerta de rotura de stock (*stockout*):

1. **Ausencia de catálogo comercial y de consumibles diferenciado de los repuestos técnicos SAT:** El catálogo del Módulo 06 (`spare_parts`) modela componentes electromecánicos (bombas, selectores, placas). Mezclar allí productos alimentarios y consumibles de ruta rompería las reglas de caducidad, cadena de frío (**Art. II**), unidades de medida (`UNIT`, `GRAMS`, `SLEEVE`) y precios de venta al público (`sale_price_eur`).
2. **Riesgo sanitario de configurar productos refrigerados en máquinas sin frío garantizado (Art. II):** Si por un error humano en Coordinación se asigna un sándwich o lácteo (`requires_cold_chain = TRUE`) a un carril de una máquina de snacks sin grupo frigorífico (`SNACKS`), o se cambia después la tipología de la máquina o el atributo del producto, se generaría un riesgo alimentario crítico.
3. **Visibilidad operativa para el Reponedor sin exponer costes internos (Art. V.4):** El reponedor necesita consultar desde `RestockerRouteView.js` qué referencias, capacidades y alertas de agotamiento (`STOCKOUT` / `LOW_STOCK`) tienen las máquinas de sus zonas asignadas antes de abrir la puerta, pero sin acceder jamás al coste de adquisición (`unit_cost_eur`) ni alterar la estructura comercial definida por Coordinación.

### 1.2. El Objetivo (Qué)
Construir el **Catálogo Maestro de Productos y Consumibles (`vending_products`)** y la **Matriz de Planograma por Máquina (`machine_planogram_slots`)** con blindaje sanitario bidireccional (**Art. II**), clonación rápida en 1 clic (**Art. IV**) y consulta móvil para el reponedor bajo segregación económica y territorial (**Art. V.4**):

1. **Catálogo Maestro (`vending_products`, Tabla 42):** Gestión completa en Coordinación de referencias comerciales y consumibles con `sku` único e inmutable (`C1`), 5 categorías (`COLD_DRINK`, `SNACK`, `PERISHABLE_FOOD`, `HOT_BEVERAGE_INGREDIENT`, `CUP_STIRRER_CONSUMABLE`), 3 unidades de medida (`UNIT`, `GRAMS`, `SLEEVE`), bandera `requires_cold_chain`, vida útil máxima (`max_shelf_life_days` obligatoria en cadena de frío y opcional en consumibles/no perecederos, `QA 1`), `unit_cost_eur` (confidencial, Art. V.4), `sale_price_eur` (con soporte de `0.00 €` para consumibles/tolvas, `C2`) y baja lógica (`is_active`, Art. III.1).
2. **Matriz de Planograma por Máquina (`machine_planogram_slots`, Tabla 43) y Clonación en 1 Clic (`D1`, `C1`, `C2`, `C3`, `QA 3`):** Configuración por par único `(machine_id, slot_code)` con `product_id`, `max_capacity`, `current_estimated_qty`, `min_threshold` e `is_active`, ordenación física de bandejas (`QA 3`), semáforo determinista de stock en 3 niveles (`STOCKOUT`, `LOW_STOCK`, `OK`), aplicación en 1 clic de presets estándar por `machine.model` o clonación directa desde otra máquina equivalente (`source_machine_id`) eligiendo el modo de stock inicial (`ZERO` o `FULL`, `C3`) y omitiendo automáticamente productos dados de baja (`QA 3`).
3. **Cerrojo Sanitario Bidireccional (Art. II) y Matriz `MachineType × ProductCategory` (`D3`, `QA 4`):** Validación en dominio y disparadores en PostgreSQL que impiden asignar productos con `requires_cold_chain = TRUE` a máquinas que no sean `PERISHABLE_FOOD` ni `COMBO`, exigen `requires_cold_chain = TRUE` en toda referencia `PERISHABLE_FOOD`, verifican la compatibilidad física entre la tipología de la máquina y la categoría del producto, y bloquean mutaciones inversas sobre `vending_products` o `machines` (integrado en `AdminMachineService::updateMachine`, `QA 4`) que dejen carriles activos en violación sanitaria.
4. **Superficie en Coordinación y Consulta Móvil en `RestockerRouteView.js` (`D2`, `QA 2`):** Consola en Coordinación para administrar el catálogo y los planogramas de todo el parque, y sección en `RestockerRouteView.js` para que el reponedor consulte en modo lectura las máquinas de sus zonas asignadas (`QA 2`) y el estado de sus carriles/tolvas sin exponer `unit_cost_eur` (Art. V.4).

### 1.3. Resumen de Decisiones de Diseño, Casos Límite y Resoluciones QA Consensuados (`D1`–`D3`, `C1`–`C3`, `QA 1`–`QA 4`)
* **D1 (Plantillas por modelo y clonación directa sin tablas extra — Art. IV):**
  * El esquema se mantiene en exactamente 2 tablas nuevas: `vending_products` (Tabla 42) y `machine_planogram_slots` (Tabla 43).
  * Coordinación puede configurar una máquina en 1 clic mediante dos vías: (a) **«Aplicar plantilla estándar del modelo»** (presets de dominio definidos para los modelos del parque: *Necta Korinto*, *Saeco Cristallo*, *Fas Fast 900*, *Jofemar Vision*, o fallback por `MachineType`), o (b) **«Clonar planograma desde otra máquina»** (`source_machine_id` de tipología compatible).
* **D2 (Superficies de UI, permisos RBAC y segregación de `unit_cost_eur` — Art. V.4):**
  * **Coordinación (`COORDINATOR`):** Acceso completo al CRUD de `vending_products` (incluyendo `unit_cost_eur`, `sale_price_eur` y margen bruto) y al editor/clonador de `machine_planogram_slots`.
  * **Reponedor (`RESTOCKER`):** En `RestockerRouteView.js` visualiza las máquinas de sus zonas operativas asignadas (`PRIMARY` y `BACKUP`) y puede abrir el planograma de cada máquina en **solo lectura** (viendo `slot_code`, producto, categoría, unidad, `requires_cold_chain`, `current_estimated_qty / max_capacity`, `min_threshold`, estado `STOCKOUT` / `LOW_STOCK` / `OK` y `sale_price_eur`), **omitiendo por completo `unit_cost_eur`** en el DTO y respuesta JSON (Art. V.4). Cualquier intento de mutación devuelve `403 Forbidden`.
  * **Técnico SAT (`TECHNICIAN`) y Portal de Sede / QR:** Los endpoints de catálogo comercial y planogramas responden `403 Forbidden` a roles no autorizados, y el Portal de Sede / canal QR jamás exponen costes ni inventario interno.
* **D3 (Cerrojo Sanitario Bidireccional Art. II y Matriz `MachineType × ProductCategory`):**
  1. **Invariante de perecederos:** Si `category = 'PERISHABLE_FOOD'`, entonces `requires_cold_chain` debe ser obligatoriamente `TRUE` y `max_shelf_life_days >= 1`.
  2. **Cerrojo Constitucional Art. II (en Dominio y Disparador SQL):** Un producto con `requires_cold_chain = TRUE` **solo** puede estar asignado en un carril activo (`is_active = TRUE`) de una máquina cuyo `machine_type` sea `'PERISHABLE_FOOD'` o `'COMBO'`.
  3. **Matriz de compatibilidad física `MachineType × ProductCategory`:**
     * `HOT_DRINKS`: admite `HOT_BEVERAGE_INGREDIENT` y `CUP_STIRRER_CONSUMABLE` (con `requires_cold_chain = FALSE`).
     * `COLD_DRINKS`: admite `COLD_DRINK` (con `requires_cold_chain = FALSE`).
     * `SNACKS`: admite `SNACK` y `COLD_DRINK` (con `requires_cold_chain = FALSE`).
     * `PERISHABLE_FOOD`: admite `PERISHABLE_FOOD`, `COLD_DRINK` y `SNACK` (con `requires_cold_chain = TRUE` o `FALSE`).
     * `COMBO`: admite `PERISHABLE_FOOD`, `COLD_DRINK`, `SNACK`, `HOT_BEVERAGE_INGREDIENT` y `CUP_STIRRER_CONSUMABLE` (con `requires_cold_chain = TRUE` o `FALSE`).
  4. **Protección bidireccional ante ediciones posteriores:**
     * Si se intenta editar un producto en `vending_products` activando `requires_cold_chain = TRUE` o cambiando su `category` cuando ya está asignado en carriles activos (`is_active = TRUE`) de máquinas incompatibles, el sistema rechaza la operación con `409 Conflict` (`COLD_CHAIN_MACHINE_INCOMPATIBLE` / `PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE`), respaldado además por disparador en PostgreSQL.
     * Si en el CRUD de Máquinas se intenta cambiar el `machine_type` de una máquina que tiene carriles activos con productos `requires_cold_chain = TRUE` (o categorías incompatibles con el nuevo tipo), el sistema y el disparador SQL rechazan el cambio con `409 Conflict` (`PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE`).
* **C1 (Inmutabilidad de `sku`, `UPSERT` sin `DELETE` físico en carriles y aviso de producto en baja — Art. III.1 y III.2):**
  1. El campo `sku` de `vending_products` (normalizado en mayúsculas `^[A-Z0-9_-]{3,32}$`) es único e **inmutable** tras el alta (`409 SKU_CHANGE_FORBIDDEN` si se intenta alterar en edición).
  2. Ambas tablas (`vending_products` y `machine_planogram_slots`) están protegidas por `prevent_hard_delete()` (`Art. III.1`). Al guardar o clonar un planograma, se realiza `UPSERT` sobre `(machine_id, slot_code)` y los carriles previos no incluidos se desactivan con `is_active = FALSE` sin borrar filas.
  3. Un producto con `vending_products.is_active = FALSE` no puede asignarse a un carril nuevo ni cambiar de carril (`422 PRODUCT_INACTIVE`). Si Coordinación desactiva un producto que ya figuraba en carriles activos, la baja lógica se permite informando del número de carriles afectados (`affected_active_slots_count`) y dichos carriles muestran la insignia **`⚠️ Producto dado de baja (Sustituir)`** hasta que se reemplace su referencia.
* **C2 (Reglas numéricas de Precios/Costes, Unidades, Capacidades y Semáforo de Stock en 3 niveles):**
  1. **Costes, PVP y Unidades por Categoría:**
     * `unit_cost_eur`: decimal `> 0.00` y `<= 9999.99` en todas las categorías.
     * En productos de venta directa (`COLD_DRINK`, `SNACK`, `PERISHABLE_FOOD`): `unit_type` debe ser `'UNIT'` y `sale_price_eur > 0.00` (`<= 9999.99`).
     * En ingredientes y consumibles (`HOT_BEVERAGE_INGREDIENT`, `CUP_STIRRER_CONSUMABLE`): `sale_price_eur >= 0.00` (`0.00 €` permitido para vasos, paletinas o azúcar); `HOT_BEVERAGE_INGREDIENT` usa `'GRAMS'` (o `'UNIT'`) y `CUP_STIRRER_CONSUMABLE` usa `'SLEEVE'` o `'UNIT'`.
  2. **Cotas de Carril (`machine_planogram_slots`):**
     * `slot_code` normalizado (`^[A-Z0-9_]{1,24}$`).
     * `max_capacity` entero entre `1` y `10000`.
     * `0 <= min_threshold < max_capacity`.
     * `0 <= current_estimated_qty <= max_capacity`.
  3. **Semáforo determinista de stock (`stock_status`):**
     * **`STOCKOUT` (`Agotado` 🔴):** `current_estimated_qty = 0`.
     * **`LOW_STOCK` (`Bajo umbral` 🟡):** `0 < current_estimated_qty <= min_threshold`.
     * **`OK` (`Abastecido` 🟢):** `current_estimated_qty > min_threshold`.
* **C3 (Modo de Stock Inicial al Clonar/Aplicar Plantilla, Auditoría y Semillas del Parque — Art. III.3):**
  1. Al clonar el planograma de una máquina origen o aplicar la plantilla del modelo sobre una máquina destino, **nunca se copia el `current_estimated_qty` de la máquina origen**. El payload acepta `initial_stock_mode` (`'ZERO'` por defecto → `0`, o `'FULL'` → `max_capacity`). Para los carriles de la máquina destino que ya estaban activos con el **mismo `product_id`**, se preserva su `current_estimated_qty` actual (ajustado a `LEAST(current_estimated_qty, new_max_capacity)`).
  2. Se incorporan los valores `'VENDING_PRODUCT'` y `'MACHINE_PLANOGRAM'` al enum `audit_entity_type` para auditar altas, ediciones, bajas/reactivaciones de productos, guardados de carriles y clonaciones/aplicaciones de plantillas en `audit_log`.
  3. `database/supabase_seeds.sql` y `SeedRunner` incluyen semillas de las 5 categorías de `vending_products` y planogramas activos en las máquinas semilla del parque.
* **Resolución QA 1 (`max_shelf_life_days` condicional y tipos nativos `26.º` y `27.º` en PostgreSQL):**
  1. Cuando `requires_cold_chain = TRUE` (incluyendo obligatoriamente toda referencia `PERISHABLE_FOOD`), `max_shelf_life_days` es **obligatorio (`NOT NULL`, entero `1..3650`)** (`422 PERISHABLE_REQUIRES_COLD_CHAIN_AND_SHELF_LIFE`). Cuando `requires_cold_chain = FALSE` (p. ej., vasos o paletinas en `CUP_STIRRER_CONSUMABLE`), `max_shelf_life_days` es **opcional (`NULL` o entero `1..3650`)**.
  2. Se declaran los tipos nativos `vending_product_category` (#26) y `vending_product_unit_type` (#27) en el contrato de correspondencia de esquema (`specs/technical/schema_correspondence_contract.md`), elevando el catálogo de 41 a **43 tablas**.
* **Resolución QA 2 (Aislamiento territorial estricto del Reponedor en consulta de máquinas y planogramas — Art. V.4):**
  * Un usuario `RESTOCKER` solo puede listar (`GET /api/restocker/machines` e incluido en `GET /api/restocker/my-route`) y consultar el planograma (`GET /api/restocker/machines/{id}/planogram`) de máquinas activas cuya sede pertenezca a una **Zona Operativa viva** (`removed_at IS NULL` en `restocker_zone_assignments` y `operational_zones.is_active = TRUE`) asignada a ese reponedor como `PRIMARY` o `BACKUP`. Si solicita por ID una máquina fuera de sus zonas asignadas, el backend responde con **`403 Forbidden` (`MACHINE_OUTSIDE_RESTOCKER_ZONES`)**.
* **Resolución QA 3 (Ordenación física de bandejas `slot_code` y omisión informada de productos en baja al clonar):**
  1. Los carriles de un planograma se devuelven siempre ordenados por orden físico de máquina: primero los `slot_code` puramente numéricos ordenados por su valor entero ascendente (`9`, `11`, `12`..`68`) y a continuación los códigos alfanuméricos de tolvas/tubos en orden lexicográfico (`HOPPER_CHOCOLATE`, `HOPPER_COFFEE`, `HOPPER_MILK`, `HOPPER_SUGAR`, `TUBE_CUPS`, `TUBE_STIRRERS`).
  2. Si al clonar un planograma (`POST /api/coordinator/machines/{id}/planogram/clone`) la máquina origen contiene algún carril activo cuyo producto fue desactivado con posterioridad (`vending_products.is_active = FALSE`), dicho carril **se omite de la clonación** (no se propaga a la máquina destino) y se devuelve en el array informativo `skipped_inactive_slots` de la respuesta; si tras omitirlos no queda ningún carril clonable con producto activo, responde `422 SOURCE_PLANOGRAM_EMPTY`.
* **Resolución QA 4 (Integración del cerrojo de planograma en `AdminMachineService::updateMachine`):**
  * Cuando Coordinación solicite cambiar el `machine_type` de una máquina en `PUT /api/coordinator/admin/machines/{id}` (`AdminMachineService::updateMachine`), además de comprobar averías activas o en garantía de 48 h, el servicio validará todos los carriles activos (`is_active = TRUE`) de `machine_planogram_slots` frente al nuevo `machine_type`; si existe al menos un carril cuyo producto sea incompatible (por `requires_cold_chain = TRUE` o por `ProductCategory`), abortará con **`409 Conflict` (`PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE`)** detallando los `slot_code` y `sku` en conflicto.

---

## 2. Usuarios y Roles Implicados

| Usuario / Rol | Relación con el Módulo | Reglas de Privacidad y Permisos (Art. V.4) |
| :--- | :--- | :--- |
| **📊 Coordinador de Operaciones (`COORDINATOR`)** | Administra el Catálogo Maestro (`vending_products`), consulta márgenes y costes (`unit_cost_eur`), configura y edita los carriles (`machine_planogram_slots`) de cualquier máquina del parque y aplica plantillas de modelo o clona planogramas en 1 clic. | Único rol autorizado a visualizar y editar `unit_cost_eur` y a mutar el catálogo comercial y la configuración de planogramas. Toda mutación queda registrada en `audit_log` (Art. III.3). |
| **📦 Cargador / Reponedor de Ruta (`RESTOCKER`)** | Consulta desde su vista móvil `RestockerRouteView.js` las máquinas ubicadas en sus Zonas Operativas asignadas (`PRIMARY` y `BACKUP`) y abre el planograma de cada máquina para revisar carriles, referencias, cantidades estimadas y alertas `STOCKOUT` / `LOW_STOCK`. | **Segregación RBAC, aislamiento territorial (`QA 2`) y confidencialidad de costes (Art. V.4):** Acceso de **solo lectura** exclusivamente sobre máquinas de sus zonas vivas (`403 MACHINE_OUTSIDE_RESTOCKER_ZONES` fuera de ellas). El campo `unit_cost_eur` **jamás se incluye** en las respuestas servidas al rol `RESTOCKER`. Tiene bloqueadas (`403 Forbidden`) todas las rutas de mutación. |
| **🔧 Técnico de Campo SAT (`TECHNICIAN`)** | Continúa gestionando sus averías y su catálogo técnico de repuestos (`spare_parts`, Módulo 06) sin interferencia. | **Segregación RBAC (`403 Forbidden`):** No interviene en la gestión del catálogo comercial ni de los planogramas de reposición. |
| **🏢 Responsable de Sede (`LOCATION_RESPONSIBLE`) y Canal Público QR** | Ninguna interacción con este módulo. | **Opacidad total (Art. V.4):** Los costes unitarios (`unit_cost_eur`), márgenes comerciales, umbrales de *stockout* y existencias internas por carril son estrictamente confidenciales e invisibles en el Portal de Sede y en el canal público QR. |

---

## 3. Historias de Usuario

### HU-01: Administración del Catálogo Maestro de Productos y Consumibles
> **Como** Coordinador de Operaciones,\
> **quiero** dar de alta, editar, filtrar y dar de baja lógica productos comerciales y consumibles de ruta (indicando SKU inmutable, categoría, unidad de medida, exigencia de cadena de frío, días de vida útil, coste unitario y PVP),\
> **para** mantener estandarizado el surtido de alimentos, bebidas, ingredientes de tolva y consumibles de todo el parque.

### HU-02: Configuración de la Matriz de Planograma por Máquina y Semáforo de Stock
> **Como** Coordinador de Operaciones,\
> **quiero** definir y ajustar los carriles/selecciones (`slot_code`) de cada máquina con su producto asignado, capacidad máxima, umbral mínimo y stock estimado actual, visualizando al instante qué carriles están en `STOCKOUT` (🔴) o `LOW_STOCK` (🟡),\
> **para** dimensionar correctamente cada punto de venta y detectar carencias de abastecimiento.

### HU-03: Aplicación de Plantilla por Modelo y Clonación de Planograma en 1 Clic
> **Como** Coordinador de Operaciones,\
> **quiero** aplicar en un clic la plantilla estándar del modelo de la máquina o clonar el mapa de carriles desde otra máquina equivalente del parque eligiendo si las nuevas posiciones nacen vacías (`ZERO`) o llenas (`FULL`),\
> **para** dar de alta o reconfigurar máquinas nuevas en segundos sin teclear 20 o 30 carriles uno por uno.

### HU-04: Salvaguarda Sanitaria de Cadena de Frío en Planogramas (Art. II)
> **Como** Coordinador de Operaciones y Responsable de Seguridad Alimentaria,\
> **quiero** que el sistema bloquee de forma innegociable cualquier intento de asignar un producto que requiera cadena de frío (`requires_cold_chain = TRUE`) en una máquina que no sea `PERISHABLE_FOOD` o `COMBO` (incluso si se intenta alterar después el producto o el tipo de máquina),\
> **para** garantizar que jamás se planifique la carga de alimentos perecederos en equipos sin refrigeración sanitaria certificada.

### HU-05: Consulta Móvil de Planograma y Alertas de Stock para el Reponedor
> **Como** Cargador / Reponedor de Ruta (`RESTOCKER`),\
> **quiero** ver desde mi pantalla móvil `RestockerRouteView.js` las máquinas de mis zonas asignadas con el resumen de sus carriles agotados o bajo umbral y abrir el detalle del planograma de cada máquina (sin ver costes internos de compra),\
> **para** saber exactamente qué productos, ingredientes y consumibles necesita cada máquina antes de abrirla.

---

## 4. Requisitos Funcionales (RF) y Criterios de Aceptación (EARS)

### RF-01 · Catálogo Maestro de Productos y Consumibles (`vending_products`) (`D2`, `D3`, `C1`, `C2`, `C3`, `QA 1`)
El sistema deberá gestionar un catálogo maestro de referencias comerciales y consumibles con tipado estricto, baja lógica e inmutabilidad de código `sku`.

* **Criterio EARS 01.1 (Estructura e invariantes de `vending_products` — D3, C1, C2, QA 1 y Art. III.1):**
  1. **EL SISTEMA DEBERÁ** persistir las referencias en la Tabla 42 `vending_products` (`id`, `sku`, `name`, `category`, `unit_type`, `requires_cold_chain`, `max_shelf_life_days`, `unit_cost_eur`, `sale_price_eur`, `is_active`, `created_at`, `updated_at`), protegida contra borrado físico mediante el disparador `trg_no_delete_vending_products` (`prevent_hard_delete()`, Art. III.1) y auditada bajo la entidad `'VENDING_PRODUCT'` en `audit_entity_type` (`C3`).
  2. **EL SISTEMA DEBERÁ** validar los tipos nativos `vending_product_category` (`'COLD_DRINK'`, `'SNACK'`, `'PERISHABLE_FOOD'`, `'HOT_BEVERAGE_INGREDIENT'`, `'CUP_STIRRER_CONSUMABLE'`) y `vending_product_unit_type` (`'UNIT'`, `'GRAMS'`, `'SLEEVE'`) (`QA 1`).
  3. **EL SISTEMA DEBERÁ** exigir:
     * `sku` único normalizado en mayúsculas (`^[A-Z0-9_-]{3,32}$`) y `name` entre `2` y `120` caracteres reales.
     * `unit_cost_eur > 0.00` y `<= 9999.99`.
     * Para `'COLD_DRINK'`, `'SNACK'` y `'PERISHABLE_FOOD'`: `unit_type = 'UNIT'` y `sale_price_eur > 0.00` (`<= 9999.99`).
     * Para `'HOT_BEVERAGE_INGREDIENT'` y `'CUP_STIRRER_CONSUMABLE'`: `sale_price_eur >= 0.00` (`<= 9999.99`), admitiendo `'GRAMS'` o `'UNIT'` en `'HOT_BEVERAGE_INGREDIENT'` y `'SLEEVE'` o `'UNIT'` en `'CUP_STIRRER_CONSUMABLE'`.
     * **SI** `category = 'PERISHABLE_FOOD'`, **ENTONCES EL SISTEMA DEBERÁ** exigir obligatoriamente `requires_cold_chain = TRUE` y `max_shelf_life_days` entero entre `1` y `3650` (`422 PERISHABLE_REQUIRES_COLD_CHAIN_AND_SHELF_LIFE`).
     * **SI** `requires_cold_chain = TRUE` (en cualquier categoría compatible), **ENTONCES EL SISTEMA DEBERÁ** exigir `max_shelf_life_days` entero entre `1` y `3650`; **SI** `requires_cold_chain = FALSE`, **ENTONCES EL SISTEMA DEBERÁ** admitir `max_shelf_life_days` como `NULL` o entero entre `1` y `3650` (`QA 1`).
* **Criterio EARS 01.2 (Inmutabilidad del `sku` y unicidad — C1 y Art. III.3):**
  1. **SI** Coordinación intenta crear un producto con un `sku` ya existente en `vending_products`, **ENTONCES EL SISTEMA DEBERÁ** rechazar el alta con `409 Conflict` (`DUPLICATE_PRODUCT_SKU`).
  2. **SI** Coordinación intenta modificar el `sku` de un producto existente en `PUT /api/coordinator/products/{id}`, **ENTONCES EL SISTEMA DEBERÁ** rechazar la petición con `409 Conflict` (`SKU_CHANGE_FORBIDDEN`) sin alterar el registro.
* **Criterio EARS 01.3 (Baja lógica, reactivación y conteo de carriles afectados — C1 y Art. III.2):**
  1. **CUANDO** Coordinación desactive un producto (`is_active = FALSE`), **EL SISTEMA DEBERÁ** conservar la fila en `vending_products`, registrar el evento en `audit_log` y devolver en la respuesta el contador `affected_active_slots_count` indicando cuántos carriles activos del parque lo tienen asignado actualmente.
  2. **MIENTRAS** un producto tenga `is_active = FALSE` y permanezca en un carril activo preexistente, **EL SISTEMA DEBERÁ** exponer `product_is_active = false` en el DTO del carril y mostrar la insignia **`⚠️ Producto dado de baja (Sustituir)`**.

---

### RF-02 · Matriz de Planograma por Máquina (`machine_planogram_slots`) y Semáforo de Stock (`D1`, `C1`, `C2`, `C3`, `QA 3`)
Cada máquina del parque contará con su mapa estructurado de carriles/tolvas, capacidades y existencias estimadas sin borrado físico.

* **Criterio EARS 02.1 (Estructura, invariantes numéricas y ordenación física de `machine_planogram_slots` — C1, C2, QA 3 y Art. III.1):**
  1. **EL SISTEMA DEBERÁ** persistir los carriles en la Tabla 43 `machine_planogram_slots` (`id`, `machine_id`, `slot_code`, `product_id`, `max_capacity`, `current_estimated_qty`, `min_threshold`, `is_active`, `created_at`, `updated_at`), con restricción de unicidad `uq_machine_planogram_slot UNIQUE (machine_id, slot_code)`, disparador anti-borrado físico `trg_no_delete_machine_planogram_slots` (`prevent_hard_delete()`, Art. III.1) y auditoría bajo `'MACHINE_PLANOGRAM'` (`C3`).
  2. **EL SISTEMA DEBERÁ** garantizar mediante validación de dominio y restricciones `CHECK` en PostgreSQL:
     * `slot_code` normalizado en mayúsculas (`^[A-Z0-9_]{1,24}$`, ej. `'11'`, `'24'`, `'HOPPER_COFFEE'`, `'TUBE_CUPS'`).
     * `max_capacity BETWEEN 1 AND 10000`.
     * `min_threshold >= 0 AND min_threshold < max_capacity`.
     * `current_estimated_qty >= 0 AND current_estimated_qty <= max_capacity`.
  3. **EL SISTEMA DEBERÁ** devolver los carriles ordenados por orden físico de bandeja (`QA 3`): primero los códigos puramente numéricos ordenados por su valor entero ascendente y después los códigos alfanuméricos de tolvas/tubos en orden lexicográfico ascendente.
* **Criterio EARS 02.2 (Guardado por `UPSERT` y baja lógica de carriles sin `DELETE` — C1):**
  1. **CUANDO** Coordinación guarde o actualice un carril individual (`PUT /api/coordinator/machines/{id}/planogram/slots/{slot_code}`), desactive un carril (`DELETE /api/coordinator/machines/{id}/planogram/slots/{slot_code}`) o reemplace el mapa completo de carriles de una máquina (`PUT /api/coordinator/machines/{id}/planogram`), **EL SISTEMA DEBERÁ** ejecutar `UPSERT` sobre `(machine_id, slot_code)` y marcar con `is_active = FALSE` los carriles retirados o no incluidos en la lista completa, sin ejecutar ninguna sentencia `DELETE`.
  2. **SI** se intenta asignar a un carril nuevo (o cambiar el `product_id` de un carril existente, o reactivar un carril inactivo) un producto con `vending_products.is_active = FALSE`, **ENTONCES EL SISTEMA DEBERÁ** rechazar la operación con `422 Unprocessable Entity` (`PRODUCT_INACTIVE`).
* **Criterio EARS 02.3 (Cálculo determinista del semáforo de stock por carril y resumen por máquina — C2):**
  1. **EL SISTEMA DEBERÁ** calcular para cada carril activo (`is_active = TRUE`) su estado `stock_status` y su déficit de reposición `restock_needed_qty = max_capacity - current_estimated_qty`:
     * `'STOCKOUT'` cuando `current_estimated_qty = 0`.
     * `'LOW_STOCK'` cuando `current_estimated_qty > 0 AND current_estimated_qty <= min_threshold`.
     * `'OK'` cuando `current_estimated_qty > min_threshold`.
  2. **EL SISTEMA DEBERÁ** exponer en el resumen del planograma de cada máquina: `active_slots_count`, `stockout_slots_count`, `low_stock_slots_count`, `ok_slots_count`, `inactive_product_slots_count`, `total_capacity`, `total_estimated_qty` y porcentaje global de llenado `fill_rate_percent`.

---

### RF-03 · Cerrojo Sanitario Bidireccional (Art. II) y Matriz `MachineType × ProductCategory` (`D3`, `QA 4`)
El sistema garantizará en la capa de dominio y en el motor PostgreSQL que ningún producto de cadena de frío ni categoría incompatible pueda residir en una máquina no apta.

* **Criterio EARS 03.1 (Bloqueo de asignación en carril por cadena de frío y matriz de categorías — D3 y Art. II):**
  1. **SI** se intenta insertar o mantener activo (`is_active = TRUE`) un carril en `machine_planogram_slots` cuyo producto tenga `requires_cold_chain = TRUE` **Y** la máquina destino tenga un `machine_type` distinto de `'PERISHABLE_FOOD'` y `'COMBO'`, **ENTONCES EL SISTEMA DEBERÁ** rechazar la operación en dominio (`422 COLD_CHAIN_MACHINE_INCOMPATIBLE`) y en base de datos mediante el disparador `trg_enforce_planogram_cold_chain_and_category` (`SQLSTATE '45000'`).
  2. **SI** la categoría del producto (`vending_products.category`) no es compatible con el `machine_type` de la máquina según la matriz de `D3`:
     * `'HOT_DRINKS'` $\rightarrow$ solo `'HOT_BEVERAGE_INGREDIENT'`, `'CUP_STIRRER_CONSUMABLE'`
     * `'COLD_DRINKS'` $\rightarrow$ solo `'COLD_DRINK'`
     * `'SNACKS'` $\rightarrow$ solo `'SNACK'`, `'COLD_DRINK'`
     * `'PERISHABLE_FOOD'` $\rightarrow$ solo `'PERISHABLE_FOOD'`, `'COLD_DRINK'`, `'SNACK'`
     * `'COMBO'` $\rightarrow$ todas las categorías
     **ENTONCES EL SISTEMA DEBERÁ** rechazar la operación en dominio (`422 PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE`) y en el disparador SQL.
* **Criterio EARS 03.2 (Cerrojo inverso al editar `vending_products` o `machines` — D3, QA 4 y Art. II):**
  1. **SI** Coordinación intenta editar un producto en `vending_products` activando `requires_cold_chain = TRUE` (o cambiando su `category`) mientras dicho producto está asignado a algún carril activo (`is_active = TRUE`) de una máquina cuyo `machine_type` sea incompatible, **ENTONCES EL SISTEMA DEBERÁ** rechazar la edición con `409 Conflict` (`COLD_CHAIN_MACHINE_INCOMPATIBLE` / `PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE`) y respaldarlo mediante el disparador `trg_validate_product_update_against_planograms`.
  2. **SI** Coordinación intenta modificar el `machine_type` de una máquina en `machines` (`AdminMachineService::updateMachine`, `QA 4`) a un tipo que resulte incompatible con cualquiera de los productos de sus carriles activos (`is_active = TRUE` en `machine_planogram_slots`), **ENTONCES EL SISTEMA DEBERÁ** rechazar la edición con `409 Conflict` (`PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE`, listando los `slot_code` y `sku` en conflicto) y respaldarlo mediante el disparador `trg_validate_machine_type_against_planogram`.

---

### RF-04 · Plantillas por Modelo y Clonación de Planograma en 1 Clic (`D1`, `C3`, `QA 3`)
Coordinación podrá inicializar o replicar el mapa de carriles de una máquina sin crear tablas adicionales de plantillas.

* **Criterio EARS 04.1 (Aplicación de plantilla estándar de dominio por modelo — D1, C3):**
  1. **CUANDO** Coordinación invoque `POST /api/coordinator/machines/{id}/planogram/apply-preset` indicando `initial_stock_mode` (`'ZERO'` por defecto o `'FULL'`), **EL SISTEMA DEBERÁ** resolver el preset de carriles correspondiente al `machine.model` normalizado (o al `machine.machine_type` si es un modelo nuevo en el parque), emparejar las referencias activas del catálogo (`vending_products`), aplicar `UPSERT` transaccional sobre `machine_planogram_slots` y registrar el evento `PLANOGRAM_PRESET_APPLIED` en `audit_log`.
* **Criterio EARS 04.2 (Clonación directa desde otra máquina compatible — D1, D3, C3, QA 3):**
  1. **CUANDO** Coordinación invoque `POST /api/coordinator/machines/{id}/planogram/clone` con `source_machine_id` e `initial_stock_mode` (`'ZERO'` | `'FULL'`), **EL SISTEMA DEBERÁ** omitir cualquier carril de origen cuyo producto esté dado de baja (`vending_products.is_active = FALSE`) informándolo en `skipped_inactive_slots` (`QA 3`), verificar que quede al menos un carril activo clonable (`422 SOURCE_PLANOGRAM_EMPTY` en caso contrario) y comprobar que todos los carriles clonables cumplan el cerrojo sanitario del **Art. II** y la matriz `MachineType × ProductCategory` frente a la máquina destino.
  2. **SI** la máquina origen contiene algún producto activo incompatible con la tipología de la máquina destino (p. ej., clonar desde una `COMBO` con sándwiches hacia una `SNACKS`), **ENTONCES EL SISTEMA DEBERÁ** abortar la clonación con `422 COLD_CHAIN_MACHINE_INCOMPATIBLE` (o `PRODUCT_CATEGORY_MACHINE_INCOMPATIBLE`) sin modificar la máquina destino.
  3. **CUANDO** la clonación o el preset se apliquen sobre la máquina destino, **EL SISTEMA DEBERÁ** preservar el `current_estimated_qty` existente (acotado al nuevo `max_capacity`) en aquellos carriles donde el `product_id` coincida con el que ya tenía activo ese `slot_code`, y fijar `0` (`'ZERO'`) o `max_capacity` (`'FULL'`) en los carriles nuevos o que hayan cambiado de producto, sin copiar jamás el `current_estimated_qty` de la máquina origen (`C3`).

---

### RF-05 · Superficie en Coordinación y Consulta Móvil del Reponedor (`RestockerRouteView.js`) (`D2`, `QA 2`, Art. V.4)
El módulo proporcionará herramientas visuales en Coordinación y visibilidad operativa de solo lectura en el móvil del reponedor, blindando los costes internos y el perímetro territorial.

* **Criterio EARS 05.1 (Consola de Catálogo y Planogramas en Coordinación — D1, D2):**
  1. **EL SISTEMA DEBERÁ** ofrecer en el panel de Coordinación una vista de gestión con dos secciones/sub-pestañas:
     * **«🍫 Catálogo de Productos y Consumibles»:** tabla con filtros por categoría, exigencia de cadena de frío, estado (`Activos` / `Inactivos` / `Todos`) y búsqueda por SKU/nombre; KPIs de referencias activas, perecederos de frío y consumibles; modal de alta y edición con bloqueo visual del campo `sku` en edición (`C1`), e indicador de `unit_cost_eur`, `sale_price_eur` y margen unitario.
     * **«🗄️ Planograma por Máquina (Matriz de Carriles)»:** selector de máquina del parque (filtrable por sede, zona operativa y tipología), KPIs del planograma (`Carriles activos`, `🔴 Agotados (STOCKOUT)`, `🟡 Bajo umbral (LOW_STOCK)`, `% Llenado estimado`), parrilla/tabla de carriles (`slot_code`, producto, categoría, insignia `❄️ Cadena de Frío`, barra de nivel `current_estimated_qty / max_capacity`, umbral `min_threshold`, semáforo de stock e insignia `⚠️ Producto dado de baja`), acciones para añadir/editar/desactivar carril, botón **«⚡ Aplicar Plantilla del Modelo»** y botón **«📋 Clonar desde otra Máquina»** con selector de modo de stock inicial (`Vacía (0)` / `Llena (100 %)`).
* **Criterio EARS 05.2 (Consulta de máquinas y planogramas en `RestockerRouteView.js` con aislamiento territorial y opacidad de `unit_cost_eur` — D2, QA 2 y Art. V.4):**
  1. **CUANDO** un usuario autenticado con rol `RESTOCKER` acceda a `RestockerRouteView.js` (o invoque `GET /api/restocker/machines`, `GET /api/restocker/my-route` y `GET /api/restocker/machines/{id}/planogram`), **EL SISTEMA DEBERÁ** listar exclusivamente las máquinas activas ubicadas en las sedes de sus Zonas Operativas vivas (`PRIMARY` y `BACKUP` con `removed_at IS NULL` en `restocker_zone_assignments`), mostrando en cada tarjeta de máquina su código, modelo, sede, zona, estado sanitario y el resumen de su planograma (`🔴 N agotados`, `🟡 M bajo umbral`, `% llenado`), permitiendo abrir el detalle de todos sus carriles activos en **solo lectura** (`QA 2`).
  2. **SI** un usuario `RESTOCKER` solicita `GET /api/restocker/machines/{id}/planogram` para una máquina cuya sede no pertenezca a ninguna de sus zonas operativas vivas, **ENTONCES EL SISTEMA DEBERÁ** denegar el acceso con `403 Forbidden` (`MACHINE_OUTSIDE_RESTOCKER_ZONES`) (`QA 2`).
  3. **EL SISTEMA DEBERÁ** omitir de forma absoluta el campo `unit_cost_eur` (y cualquier cálculo de coste o margen comercial) en los endpoints y DTOs servidos al rol `RESTOCKER`, al Portal de Sede y al canal público QR (`Art. V.4`).
  4. **SI** un usuario con rol `RESTOCKER` o `TECHNICIAN` intenta invocar cualquier endpoint de mutación del catálogo o de los planogramas (`POST`, `PUT`, `PATCH`, `DELETE` bajo `/api/coordinator/products*` o `/api/coordinator/machines/*/planogram*`), **ENTONCES EL SISTEMA DEBERÁ** responder con `403 Forbidden`.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 · Inviolabilidad de Datos y Prohibición de Borrado Físico (Art. III.1 y III.3):** Ninguna fila de `vending_products` ni de `machine_planogram_slots` podrá ser eliminada mediante `DELETE FROM` (salvo purga de arnés de test con `vendguard.purge = 'on'`).
* **RNF-02 · Defensa en Profundidad del Cerrojo Sanitario (Art. II):** Las reglas de cadena de frío y compatibilidad `MachineType × ProductCategory` se validarán tanto en la capa de aplicación PHP como mediante disparadores nativos en PostgreSQL.
* **RNF-03 · Fidelidad al Sistema de Diseño (`docs/design.md`) y Dogma Vanilla (Art. IV):** Componentes en Vue 3 ES Modules nativos sin bundlers ni librerías externas, respetando la paleta (`#2560ff`, `#f9fafb`, `#2c333f`, bordes `#c8cfda`), radios conservadores (`4px` / `8px`) y diseño *mobile-first* a `390px`.

---

## 6. Casos Límite

1. **Intento de asignar un sándwich refrigerado (`requires_cold_chain = TRUE`) a una máquina `SNACKS` o `COLD_DRINKS` (`D3`, Art. II):** Coordinación intenta guardar en el carril `11` de una máquina `SNACKS` un producto con `requires_cold_chain = TRUE`. El servicio de dominio lo rechaza con `422 COLD_CHAIN_MACHINE_INCOMPATIBLE`; si alguien intenta insertar la fila por SQL directo, el disparador `trg_enforce_planogram_cold_chain_and_category` aborta la transacción con `SQLSTATE '45000'`.
2. **Intento de burlar el Art. II activando `requires_cold_chain = TRUE` en un producto que ya está en una máquina `SNACKS` (`D3`):** Un snack tenía `requires_cold_chain = FALSE` y estaba en el carril `15` de una máquina `SNACKS`. Coordinación edita el producto e intenta cambiar `requires_cold_chain` a `TRUE` (o su categoría a `PERISHABLE_FOOD`). El sistema bloquea la edición con `409 COLD_CHAIN_MACHINE_INCOMPATIBLE` (y el disparador `trg_validate_product_update_against_planograms` en BD).
3. **Intento de cambiar el `machine_type` de una máquina `PERISHABLE_FOOD` a `SNACKS` teniendo carriles con productos refrigerados (`D3`, `QA 4`):** Coordinación edita la máquina en el CRUD de Administración (`AdminMachineService::updateMachine`) e intenta pasarla a `SNACKS`. Al tener carriles activos con `requires_cold_chain = TRUE`, la operación se rechaza con `409 PLANOGRAM_INCOMPATIBLE_WITH_MACHINE_TYPE`.
4. **Creación de un consumible sin precio de venta al público (`sale_price_eur = 0.00`) y sin caducidad (`max_shelf_life_days = NULL`) vs. refresco con `sale_price_eur = 0.00` (`C2`, `QA 1`):** Dar de alta *"Tira de Vasos Cartón 7oz"* (`CUP_STIRRER_CONSUMABLE`, `SLEEVE`, `requires_cold_chain = FALSE`, `max_shelf_life_days = NULL`) con `unit_cost_eur = 1.80` y `sale_price_eur = 0.00` es aceptado (`201 Created`); en cambio, dar de alta una *"Lata Cola 33cl"* (`COLD_DRINK`, `UNIT`) con `sale_price_eur = 0.00` es rechazado con `422 INVALID_SALE_PRICE`.
5. **Desactivación de un producto que está asignado en 3 carriles activos del parque y posterior clonación (`C1`, `QA 3`):** Coordinación desactiva (`is_active = FALSE`) un zumo que está en 3 máquinas. La operación responde `200 OK` con `affected_active_slots_count: 3`. Esos 3 carriles conservan su `current_estimated_qty` físico y muestran la insignia **`⚠️ Producto dado de baja (Sustituir)`**. Si una de esas máquinas se usa después como `source_machine_id` para clonar su planograma a otra máquina nueva, el carril del zumo dado de baja se omite automáticamente y se devuelve en `skipped_inactive_slots`.
6. **Clonación de planograma preservando el stock estimado de los carriles que no cambiaron de producto (`C3`):** La máquina B ya tenía en el carril `11` el producto `PROD-01` con `current_estimated_qty = 6`. Al clonar sobre B el planograma de la máquina A (donde el carril `11` también lleva `PROD-01` con `current_estimated_qty = 12` y el carril `12` lleva un producto nuevo `PROD-02`) con `initial_stock_mode = 'ZERO'`, el carril `11` de B conserva sus `6` unidades y el carril `12` de B se inicializa en `0` (jamás copia las `12` unidades de A).
7. **Verificación de opacidad de `unit_cost_eur` y aislamiento territorial ante el Reponedor (`D2`, `QA 2`, Art. V.4):** `REP-01` consulta `GET /api/restocker/machines/{id}/planogram` de una máquina de su zona: el JSON devuelto contiene `sale_price_eur` pero ninguna clave `unit_cost_eur` ni `margin_eur`. Si consulta una máquina de otra zona donde no está asignado, recibe `403 MACHINE_OUTSIDE_RESTOCKER_ZONES`.

---

## 7. Fuera de Alcance

Quedan explícitamente excluidos del alcance de este Módulo 32 (reservados a las Features 23 a 28 del Bloque 4 según el Art. VI):
1. **Stock de productos en la furgoneta del reponedor, órdenes de picking matutino y control de temperatura de salida de cámara frigorífica:** Reservado a la **Feature 23**.
2. **Ejecución de visitas de reposición in situ desde el móvil, carga real de unidades, control FEFO de caducidades por lote y checklist de higiene diaria:** Reservado a la **Feature 24**.
3. **Recaudación de efectivo, carga de tubos de cambio y arqueo de bolsas precintadas:** Reservado a la **Feature 25**.
4. **Despacho de avisos de Nivel 1 al reponedor y escalado de averías electromecánicas al SAT:** Reservado a la **Feature 26**.
5. **Planificador de frecuencias de ruta (L-M-V, M-J, diaria) y ordenación geográfica Haversine de paradas de carga:** Reservado a la **Feature 27**.
6. **Analítica histórica de roturas de stock (*Stockout Rate*) y mermas comerciales en el Cuadro de Mandos:** Reservado a la **Feature 28**.

---

## 8. Criterios de Finalización (Definition of Done)

1. Existen las Tablas 42 (`vending_products`) y 43 (`machine_planogram_slots`) en `database/supabase_init.sql` y en su migración incremental (`028_product_catalog_and_planogram_slots.sql`), con los tipos nativos #26 (`vending_product_category`) y #27 (`vending_product_unit_type`) (`QA 1`), `CHECK` constraints numéricas (`C2`), disparadores `prevent_hard_delete()` (`Art. III.1`), disparadores bidireccionales del cerrojo sanitario (`Art. II`, `D3`) y entidades `'VENDING_PRODUCT'` y `'MACHINE_PLANOGRAM'` en `audit_entity_type` (`C3`).
2. El CRUD de `vending_products` valida la inmutabilidad del `sku` (`C1`), las reglas de `requires_cold_chain` y `max_shelf_life_days` (`D3`, `QA 1`), las cotas de `unit_cost_eur` y `sale_price_eur` por categoría (`C2`), el bloqueo ante máquinas incompatibles al editar `requires_cold_chain`/`category` (`D3`) y devuelve `affected_active_slots_count` al desactivar una referencia.
3. El servicio de planogramas soporta guardado individual y completo por `UPSERT` sin `DELETE` físico (`C1`), ordenación física de bandejas (`QA 3`), cálculo del semáforo `STOCKOUT` / `LOW_STOCK` / `OK` (`C2`), bloqueo de cambio de `machine_type` incompatible en `AdminMachineService::updateMachine` (`QA 4`), aplicación de presets de dominio por modelo (`D1`) y clonación directa entre máquinas compatibles omitiendo productos dados de baja (`QA 3`), respetando el modo de stock inicial (`ZERO` / `FULL`) y preservando el stock de carriles cuyo producto no cambió (`C3`).
4. La consola de Coordinación permite gestionar el catálogo de productos y los planogramas por máquina con clonación/plantilla en 1 clic, y `RestockerRouteView.js` permite al `RESTOCKER` consultar las máquinas de sus zonas asignadas (`QA 2`) y sus planogramas en solo lectura sin exponer jamás `unit_cost_eur` (`D2`, `Art. V.4`).
5. Toda la batería de pruebas unitarias, de integración HTTP y de blindaje constitucional pasa al **100 % en verde**.

---

## 9. Dudas Abiertas

* Ninguna. Las 3 decisiones de diseño (`D1`–`D3`), las 3 decisiones de casos límite e invariantes (`C1`–`C3`) y las 4 resoluciones de auditoría QA (`QA 1`–`QA 4`) han sido ratificadas por el Product Owner.
