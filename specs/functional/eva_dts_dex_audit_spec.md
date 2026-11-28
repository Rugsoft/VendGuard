# ESPECIFICACIÓN FUNCIONAL Y TÉCNICA: AUDITORÍA LOCAL MEDIANTE IMPORTACIÓN DE FICHEROS ESTÁNDAR EVA-DTS (`.dex`) EN RUTA (`IN-SITU EVA-DTS / DEX FILE AUDIT`)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 39 · Auditoría Local mediante Importación de Ficheros Estándar EVA-DTS (`.dex`) en Ruta  
**Documento Canónico:** `specs/39-eva-dts-dex-audit/spec.md` (y su espejo `specs/functional/eva_dts_dex_audit_spec.md`)  
**Versión:** 1.1 · Incorpora las 6 Decisiones de Diseño (`D1`–`D6`), los 6 Casos Límite (`C1`–`C6`) y las 5 Resoluciones de Auditoría QA (`QA-1`–`QA-5`) consensuadas el 2026-11-25  
**Estado:** Especificación Funcional y Técnica v1.1 Aprobada por el Product Owner el 2026-11-25 · Puerta de Aprobación SDD Fase 1 superada  
**Fecha:** Noviembre 2026  
**Origen:** Funcionalidad 33 del catálogo [`docs/features_pendientes.md`](../../docs/features_pendientes.md) (Bloque 6 · Evolución Operativa, Trazabilidad APPCC, Rentabilidad y Auditoría Local sin Hardware IoT)  
**Alineación Constitucional:**  
- **Art. I (Supremacía SDD e inadmisibilidad de código simulado):** Esta especificación gobierna todo el diseño del Módulo 39; el parser EVA-DTS en PHP 8.2+ y la micro-prelectura Offline en JavaScript procesan exclusivamente datos reales del estándar EVA-DTS, sin *mocks*, heurísticas ficticias ni datos inventados en flujos de producción.  
- **Art. II (Precaución y prioridad sanitaria absoluta):** El Técnico SAT y Coordinación pueden importar volcados `.dex` en máquinas activas incluso en `SANITARY_QUARANTINE` o con avería abierta `TEMPERATURE_COLD` o `OUT_OF_SERVICE` con fines de diagnóstico forense, mientras que el Reponedor recibe bloqueo estricto (`409 SANITARY_DEX_BLOCKED`, tanto en aplicación como en disparador PostgreSQL) si la máquina está en `SANITARY_QUARANTINE` o tiene una incidencia activa `TEMPERATURE_COLD` (`status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')`). Adicionalmente, cualquier evento `EA1`/`EA2` térmico nuevo o incrementado en una máquina `PERISHABLE_FOOD` o `COMBO` activa un aviso sanitario prominente en ruta y encola una alerta interna prioritaria (`DEX_THERMAL_EVENT_ALERT`) en `notification_outbox` con `dedup_key`, sin auto-crear tickets ni cuarentenas por logs históricos sin reloj fiable.  
- **Art. III.1, III.2 y III.3 (Inviolabilidad e inmutabilidad de datos):** Los volcados `.dex` (`machine_dex_audits`, `machine_dex_selection_lines`, `machine_dex_event_lines`) y su contenido crudo (`raw_payload`) jamás se borran físicamente (`prevent_hard_delete()`); las líneas parseadas quedan congeladas desde el `INSERT`; los volcados posteriores jamás se reescriben tras una anulación `VOIDED` intermedia (la vista de Coordinación computa en lectura el delta efectivo si el predecesor fue anulado); el único cambio de estado admitido es la anulación lógica auditada (`ACTIVE` $\rightarrow$ `VOIDED`) con motivo obligatorio $\ge 20$ caracteres reales; y todo alta, vinculación posterior y anulación queda registrada en `audit_log` bajo `audit_entity_type = 'MACHINE_DEX_AUDIT'`.  
- **Art. IV (Minimalismo tecnológico):** Parser determinista implementado en PHP 8.2+ puro (`declare(strict_types=1);`), persistencia relacional en PostgreSQL/Supabase mediante PDO nativo (Tablas 52, 53 y 54, tipos nativos `#51`–`#55`) sin alterar el DDL de tablas existentes (`machines`, `machine_planogram_slots`, `machine_restock_visits`, `machine_cash_collections`, `incidents`, `offline_sync_mutations`), y micro-prelectura Offline en JavaScript nativo sin dependencias ni *bundlers*.  
- **Art. V.1, V.4 y V.5 (Integridad de reglas de negocio, privacidad y seguridad de carga):**  
  - *Art. V.1:* La anulación lógica de un volcado exige motivo real de 20 a 500 caracteres con al menos 3 bloques alfanuméricos.  
  - *Art. V.4:* Proyecciones estrictas por rol preservando el **Arqueo Ciego** del Módulo 35: el `RESTOCKER` recibe `prefill_sales_counter_reading_eur` **exclusivamente** en la respuesta inmediata del `POST` recién ejecutado (jamás en el `GET` histórico) y nunca recibe `CA3`, recaudaciones esperadas ni arqueos; el `TECHNICIAN` solo recibe ciclos en unidades (`VA1`/`PA2`) y fallos `EA1`/`EA2`; `COORDINATOR` accede a la auditoría financiera completa; `LOCATION_RESPONSIBLE`, el canal público QR y los albaranes A4 tienen opacidad total.  
  - *Art. V.5 (adaptado a carga textual `.dex`):* Validación estricta en servidor sin bytes nulos ni caracteres de control peligrosos, soporte determinista de UTF-8 (tildes/ñ/`€` en descripciones) con conversión de ISO-8859-1/Windows-1252 a UTF-8, tamaño máximo $\le 1\,\text{MB}$ ($1\,048\,576$ bytes), envoltorio `DXS*`..`DXE*`, segmento `VA1` válido, huella `SHA-256` y descarga segura en Coordinación con `X-Content-Type-Options: nosniff`, `Content-Disposition: attachment` y nombre derivado de `audit_code`.  
- **Art. VI (Delimitación del alcance):** Quedan explícitamente fuera del módulo la lectura directa por cable/Web Serial/Bluetooth, la ingesta remota IoT (Feature 18), el disparo automático de mantenimiento preventivo por desgaste (Feature 29, que consumirá estos datos en una fase posterior), los escandallos/recetas de `HOT_DRINKS`, el CRUD administrativo de códigos `EA1`/`EA2` y cualquier exposición en Portal de Sede/QR/Albarán A4.  
- **Art. VII:** Sujeto a aprobación expresa del Product Owner antes de iniciar `T-DEX-01`.

---

## 1. Contexto y Objetivo

### 1.1. El Problema (Por qué)

Incluso tras implantar la reposición in situ con control FEFO (Módulo 34), la recaudación con arqueo ciego (Módulo 35) y la sinergia con el SAT (Módulo 36), la operación sin hardware IoT conectado en bus presenta cuatro carencias críticas:

1. **Errores de transcripción en contadores de ruta:** El reponedor lee manualmente el display interno de la máquina para introducir `sales_counter_reading` en euros (Módulo 35), exponiendo el arqueo a errores de tecleo o lectura.
2. **Ausencia de contraste electrónico por selección frente al planograma:** En máquinas de espirales/bandejas (`SNACKS`, `COLD_DRINKS`, `PERISHABLE_FOOD`, `COMBO`), las ventas registradas por la placa VMC (`PA1`/`PA2`) no se contrastan contra el consumo físico observado en M34 ni revelan selecciones vendidas fuera de planograma.
3. **Averías latentes en placa MDB/frío no detectadas a tiempo:** Las placas almacenan registros de fallos (`EA1`/`EA2`) de monedero, billetero, motores o grupo de frío que pasan inadvertidos en una visita rápida hasta provocar una parada total o una pérdida de cadena de frío.
4. **Falta de contadores reales de erogaciones para futuro mantenimiento basado en uso:** En máquinas `HOT_DRINKS`, `sales_counter_reading` mide euros y las cargas M34 miden gramos o mangas (`GRAMS`/`SLEEVE`), por lo que la futura Feature 29 (*Mantenimiento Preventivo por Desgaste Real*) necesita primero una fuente fiable de ciclos reales (`VA1` totales y `PA2` por selección) importables desde ficheros estándar EVA-DTS (`.dex`).

### 1.2. El Objetivo (Qué)

| Pilar | Capacidad Entregada |
| :--- | :--- |
| **1. Entidad Inmutable `machine_dex_audits` y Parser PHP Determinista (`D1`, `D3`, `C3`, `QA-4`, `QA-5`)** | Nueva entidad propia por máquina (Tablas 52–54), importable por **Reponedor** (M34/M35), **Técnico SAT** y **Coordinación**, con parser EVA-DTS en PHP 8.2+ estricto en seguridad/envoltorio (`DXS`..`DXE`, `VA1`, `SHA-256`, $\le 1\,\text{MB}$, UTF-8/ISO-8859-1) y tolerante con variantes de firmware (`ID1`, `ID4`, `CA3`, `PA1`/`PA2`, `EA1`/`EA2`), preservando el `raw_payload` inmutable en PostgreSQL. |
| **2. Blindaje de Arqueo Ciego y Proyecciones por Rol (`D2`, `QA-1`, Art. V.4)** | Separación estricta de proyecciones: el `RESTOCKER` recibe `prefill_sales_counter_reading_eur` solo en la respuesta inmediata del `POST` (jamás en `GET` histórico), unidades vendidas por selección (`PA2`, sin euros) y alertas `EA1`/`EA2` (jamás `CA3` ni arqueo); el `TECHNICIAN` recibe solo ciclos en unidades y fallos `EA1`/`EA2`; `COORDINATOR` ve la auditoría financiera completa. |
| **3. Auditoría No Destructiva M34/M35 y Asistencia `EA1`/`EA2` (`D4`, `D5`, `C1`, `C2`, `C4`, `QA-2`–`QA-4`)** | Pre-relleno no destructivo de M35; contraste en lectura de `PA1`/`PA2` frente a planograma/M34 (y modo informativo `HOT_DRINKS_SELECTION` en café) y cruce financiero `CA3_VS_VAULT` / `VA1_VS_EXPECTED` en Coordinación sin mutar `current_estimated_qty`; manejo de orden temporal Offline y retrocesos (`counter_reset_detected`, `out_of_order_sync`); y escalado asistido en 1 toque a M36/M16 con alerta térmica `notification_outbox` anti-spam en fallos nuevos o incrementados. |
| **4. Soporte Offline PWA M24 y Consola de Coordinación (`D6`, `C5`, `C6`, `QA-5`)** | Cola FIFO en `IndexedDB` con `client_mutation_id`, validación `DXS*`..`DXE*` + micro-prelectura JS limitada a `ID4`/`VA101`/`EA1`-`EA2` (jamás `CA3`) y aislamiento de conflictos sin frenar M34/M35/M36; más consola de Coordinación con KPIs, filtros, descarga `.dex` segura (`nosniff`), anulación lógica auditada (`ACTIVE` $\rightarrow$ `VOIDED`) y exportación CSV UTF-8 BOM. |

### 1.3. Resumen de Decisiones de Diseño (`D1`–`D6`), Casos Límite (`C1`–`C6`) y Resoluciones QA (`QA-1`–`QA-5`)

| Clave | Decisión Consensuada | Regla Operativa Concreta |
| :--- | :--- | :--- |
| **`D1`** | **Entidad propia inmutable y 3 puertas de entrada** | `machine_dex_audits` es una entidad independiente vinculada a `machine_id`, importable desde el móvil del `RESTOCKER` (dentro o fuera de M34/M35), el móvil del `TECHNICIAN` y el panel de `COORDINATOR`. Admite vínculos opcionales a `restock_visit_id`, `cash_collection_id` e `incident_id` sin alterar el DDL de esas tablas. |
| **`D2`** | **Blindaje estricto de Arqueo Ciego y Art. V.4** | Cada rol consume una proyección dedicada. El `RESTOCKER` recibe `prefill_sales_counter_reading_eur` únicamente en el `POST` inmediato de importación (jamás en `GET` histórico, `QA-1`), `va1_total_vend_units`, unidades por selección (`PA2`) sin importes monetarios y alertas `EA1`/`EA2`; jamás recibe `CA3`, deltas monetarios ni datos de arqueo. El `TECHNICIAN` recibe solo ciclos en unidades y eventos `EA1`/`EA2` (cero importes). `COORDINATOR` accede a la vista completa. Sede/QR/A4 mantienen opacidad absoluta. |
| **`D3`** | **Parser determinista PHP 8.2+ (estricto en sobre, tolerante en firmware)** | Rechaza con `422` bytes nulos/controles peligrosos, tamaño $> 1\,\text{MB}$, ausencia de `DXS*`..`DXE*`, falta de `VA1*` o cabeceras únicas repetidas (`VA1`/`CA3`/`ID4`) y `PA101` repetidos. Deduplica por `(machine_id, sha256_hash)` entre volcados `ACTIVE` (`409 DEX_DUPLICATE_HASH`). Acepta como opcionales `ID1`, `ID4` (2 decimales por defecto si falta), `CA3`, `PA1`/`PA2` y `EA1`/`EA2`, omitiendo bloques propietarios desconocidos con contador `ignored_unknown_lines_count`. Si `ID101`/`ID106` viene poblado (no vacío ni todo ceros) y difiere de `machines.code` normalizado, activa `identity_mismatch_warning = TRUE` sin bloquear. |
| **`D4`** | **Asistencia en 1 toque para `EA1`/`EA2` + alerta sanitaria Art. II** | Catálogo fijo en dominio clasifica `EA1`/`EA2` por prefijos estándar EVA-DTS (`THERMAL_REFRIGERATION`, `PAYMENT_MDB`, `MECHANICAL_VEND`, `POWER_CONTROLLER`, `OTHER`). El móvil sugiere en 1 toque el escalado al SAT (M36 para `RESTOCKER`) o el alta oportunista in situ (M16 para `TECHNICIAN`) pre-cargando los códigos en la Nota Interna (`is_internal = TRUE`, Art. V.4). Si hay evento `THERMAL_REFRIGERATION` nuevo o incrementado en `PERISHABLE_FOOD` o `COMBO`, muestra aviso sanitario prominente en ruta y encola `DEX_THERMAL_EVENT_ALERT` en `notification_outbox` para Coordinación; jamás crea tickets ni cuarentenas automáticamente. |
| **`D5`** | **Pre-relleno no destructivo M35 y auditoría en lectura M34/M35** | En M35, `va1_total_sales_eur` pre-rellena el campo `sales_counter_reading` en UI (editable si el operador debe declarar otra lectura o reseteo). En lectura, cruza selecciones `PA1`/`PA2` contra `machine_planogram_slots` y visitas M34 (más lista de `unmapped_selections`) y en Coordinación cruza `CA3` o `VA1` contra M35 (`CA3_VS_VAULT` / `VA1_VS_EXPECTED`, tolerancia $\pm 1,00\,\text{€}$). Nunca muta `current_estimated_qty` ni sobrescribe M34/M35. |
| **`D6`** | **Soporte Offline PWA (M24) con micro-prelectura JS** | Sin cobertura, `EvaDtsClientPreview.js` valida tamaño $\le 1\,\text{MB}$, sin bytes nulos, inicio `DXS*` **y** cierre `DXE*`, encola el texto crudo en `IndexedDB` con `client_mutation_id` dentro de la cola FIFO del operador y lee **exclusivamente** `ID4`, `VA101`, `VA102` y códigos `EA1`/`EA2` (jamás `CA3`) para pre-rellenar M35 y mostrar alertas en campo. Al recuperar red, PHP es el único parser autoritativo. |
| **`C1`** | **Retroceso o reseteo de contadores acumulados** | Si `va1_total_sales_eur` o `va1_total_vend_units` es inferior al volcado `ACTIVE` previo con `captured_at <= new.captured_at`, se acepta con `counter_reset_detected = TRUE` y aviso destacado en Coordinación. Los deltas del período caen a los valores `since reset` del `.dex` (`VA103`/`VA104`/`PA203`) si existen (`delta_source = 'RESET_FALLBACK'`) o a `NULL` (`delta_source = 'UNAVAILABLE'`), sin bloquear el replay Offline. |
| **`C2`** | **Permisos diferenciados en `SANITARY_QUARANTINE`, `TEMPERATURE_COLD` y `OUT_OF_SERVICE`** | `TECHNICIAN` y `COORDINATOR` pueden importar `.dex` sobre cualquier máquina activa (`is_active = TRUE` y `deleted_at IS NULL`), incluso en `sanitary_status = 'QUARANTINE'`, con avería abierta `TEMPERATURE_COLD` o `is_blocked_no_access = TRUE`. Al `RESTOCKER` se le bloquea en `QUARANTINE` o con avería abierta `TEMPERATURE_COLD` (`409 SANITARY_DEX_BLOCKED` en app y SQL, Art. II.2), pero se le permite en `EXPIRED` o `is_blocked_no_access = TRUE` no sanitario para documentar un escalado M36. |
| **`C3`** | **Persistencia del `.dex` crudo en PostgreSQL** | `raw_payload TEXT NOT NULL` se guarda inmutable en `machine_dex_audits` (compresión TOAST nativa, tope $1\,\text{MB}$), excluido de todos los listados y proyecciones móviles, descargable solo por `COORDINATOR` (`nosniff` + `audit_code.dex`) y protegido por `prevent_hard_delete()` y trigger de inmutabilidad. |
| **`C4`** | **Emparejamiento `PA1` en 2 pasos y modo `HOT_DRINKS`** | En `SNACKS`, `COLD_DRINKS`, `PERISHABLE_FOOD` y `COMBO`, cada `PA101` normalizado en mayúsculas se empareja: (1) coincidencia exacta con `slot_code`; (2) si no hay exacta, recorte de ceros a la izquierda (`011` $\leftrightarrow$ `11`) solo si es unívoco dentro del mismo volcado (marcando `AMBIGUOUS` si colisiona y `UNMAPPED` si no existe). En `HOT_DRINKS`, todas las líneas `PA1`/`PA2` se registran íntegras como `HOT_DRINKS_SELECTION` (informativo, sin falsa alerta `UNMAPPED`). |
| **`C5`** | **Aislamiento de conflictos en replay Offline M24** | Mismo `(imported_by, client_mutation_id)` sobre la misma máquina devuelve `200 OK` idempotente (`409 CLIENT_MUTATION_ID_ALREADY_USED` en máquina distinta). `409 DEX_DUPLICATE_HASH` se resuelve automáticamente retirando el ítem de la cola con aviso informativo. Los errores `422`, `409 SANITARY_DEX_BLOCKED` o `403` pasan a la bandeja de conflictos con volcado/descarte auditado en 1 toque sin frenar visitas M34, recaudaciones M35 ni escalados M36 posteriores (si una M35 encolada referenciaba el `.dex` fallido, se envía con `dex_audit_id = null` preservando `sales_counter_reading`). |
| **`C6`** | **Consola Coordinación, anulación lógica y CSV** | Sub-vista dedicada en Coordinación con KPIs, filtros, carga manual en escritorio, modal de detalle + descarga `.dex`, anulación lógica (`ACTIVE` $\rightarrow$ `VOIDED`, motivo $20..500$ caracteres con $\ge 3$ bloques alfanuméricos, Art. III.2 + V.1, excluyendo la fila anulada de futuros deltas y del índice único de `sha256_hash`), CSV UTF-8 BOM (`;`) neutralizado contra fórmulas y catálogo `EA1`/`EA2` fijo en dominio. |

#### Resoluciones de Auditoría QA (`QA-1`–`QA-5`)

| Clave | Problema Auditado | Resolución Definitiva Acordada |
| :--- | :--- | :--- |
| **`QA-1`** | Fuga de Arqueo Ciego en el `GET` histórico del Reponedor (Art. V.4) y brecha sanitaria frente a M34/M35 ante `TEMPERATURE_COLD` abierta (Art. II.2). | `prefill_sales_counter_reading_eur` (`va1_total_sales_eur`) se expone **exclusivamente** en la respuesta inmediata del `POST` del `RESTOCKER`; el `GET` histórico omite todo campo en euros (`toRestockerArray(includePrefill: false)`). Asimismo, `409 SANITARY_DEX_BLOCKED` para `RESTOCKER` (app + SQL) bloquea tanto `sanitary_status = 'QUARANTINE'` como incidencia activa `TEMPERATURE_COLD`, permitiendo `EXPIRED` y `is_blocked_no_access` no sanitario. |
| **`QA-2`** | Falsos reseteos en Offline diferido (`out_of_order_sync`) y deltas rotos en lectura cuando un volcado intermedio pasa a `VOIDED` (Art. III.3). | `previous_audit_id` busca el último volcado `ACTIVE` con `captured_at <= new.captured_at` (`captured_at DESC, id DESC`), marcando `out_of_order_sync = TRUE` si existía un volcado `ACTIVE` posterior. Tras un `VOIDED` intermedio no se reescriben filas posteriores; Coordinación marca `previous_audit_voided = TRUE` y calcula en lectura `effective_delta_sales_eur` y `effective_delta_vend_units` contra el último predecesor `ACTIVE` válido. |
| **`QA-3`** | Ambigüedad de ventana/base del cruce financiero M35 y fórmula de consumo M34 en `POST` y detalle. | En Coordinación, M35 cruza por `cash_collection_id` o recaudación más cercana del **mismo día civil (`Europe/Madrid`)**, priorizando `CA3_VS_VAULT` (`vault_counted_eur - ca3_cashbox_eur`) sobre `VA1_VS_EXPECTED` (`expected_cashbox_eur - delta_sales_eur`). Para M34, se elimina `restock_expected_delta_units` del `POST` móvil (expone `slot_current_estimated_qty` + `delta_vend_units`) y en Coordinación se calcula `observed_consumption_units = prev_item.qty_after - curr_item.qty_before` entre la visita `COMPLETED` vinculada/del día y su predecesora inmediata. |
| **`QA-4`** | Posiciones EVA-DTS reales `EA202`/`EA203`/`EA205`, prefijos `EG*`..`EJ*`, `PA101` repetido en un mismo `.dex` y anti-spam térmico en `notification_outbox`. | `machine_dex_event_lines` captura `count_since_reset` (`EA202`), `count_since_init` (`EA203`) e `is_active_flag` (`EA205 = '1'`), con catálogo alineado (`EG*` térmico `CRITICAL`; `EA*`/`EB*`/`EC*`/`EJ*` pago; `EF*`/`EH*` mecánica; `ED*`/`EE*` energía/controlador; resto `OTHER`). Un `PA101` duplicado dentro del mismo `.dex` se rechaza con `422 DEX_DUPLICATE_SELECTION_CODE`. `DEX_THERMAL_EVENT_ALERT` (con `dedup_key`) solo se encola ante eventos térmicos nuevos o incrementados frente al volcado `ACTIVE` previo. |
| **`QA-5`** | Tildes españolas (`UTF-8` / `ISO-8859-1`), cotas/cabeceras repetidas, cierre `DXE*` en Offline, descarga segura `nosniff` y códigos de error al enlazar `dex_audit_id`. | Se permiten caracteres `UTF-8` válidos (convirtiendo `ISO-8859-1`/`Windows-1252` a `UTF-8` antes de `sha256_hash`), rechazando `\0` y controles peligrosos (`422 DEX_INVALID_TEXT_PAYLOAD`), valores negativos/overflow y cabeceras `VA1`/`CA3`/`ID4` repetidas (`422 DEX_DUPLICATE_HEADER_SEGMENT`). Offline exige `DXS*`..`DXE*`. La descarga `/raw` devuelve `X-Content-Type-Options: nosniff` y nombre `DEX-AAAA-NNNN.dex`. El enlace opcional `dex_audit_id` en M34/M35/M36 falla explícitamente con `404`/`409`/`422`. |

---

## 2. Usuarios y Roles Implicados

| Usuario / Rol | Relación con el Módulo | Reglas de Privacidad, Perímetro y Permisos (Art. V.4) |
| :--- | :--- | :--- |
| **📦 Cargador / Reponedor de Ruta (`RESTOCKER`)** | Importa ficheros `.dex` en `RestockerRouteView.js` (opcional dentro de visita M34 / recaudación M35 o como acción rápida en parada), utiliza el pre-relleno inmediato del `POST` para `sales_counter_reading` en M35, consulta ventas en unidades (`PA2`) por carril, recibe alertas `EA1`/`EA2` con botón de escalado M36 en 1 toque y opera en modo Offline PWA M24. | **Perímetro y Arqueo Ciego (`C2`, `D2`, `QA-1`, Art. V.4):** Solo opera sobre máquinas activas dentro de sus zonas operativas cubiertas hoy (incluidos desvíos `REASSIGNED` activos de M37; `403 MACHINE_OUTSIDE_RESTOCKER_ZONES` fuera). Bloqueado en `QUARANTINE` o con avería `TEMPERATURE_COLD` abierta (`409 SANITARY_DEX_BLOCKED`). **Nunca** recibe `prefill_sales_counter_reading_eur` en el `GET` histórico, ni `CA3`, importes `PA2` en euros, deltas monetarios ni `raw_payload`. |
| **🔧 Técnico de Campo SAT (`TECHNICIAN`)** | Importa ficheros `.dex` desde `TechnicianRouteView.js` en máquinas de su ruta o zonas asignadas (incluidas máquinas en `SANITARY_QUARANTINE`, con `TEMPERATURE_COLD` abierta o `OUT_OF_SERVICE`), consulta ciclos totales/por selección en unidades (`VA1`/`PA2`) y eventos `EA1`/`EA2` para diagnóstico y alta oportunista M16 en 1 toque, con soporte Offline M24. | **Segregación financiera (`D2`, Art. V.4):** Solo opera sobre máquinas activas donde tenga intervención asignada/activa hoy o cobertura en sus zonas operativas (`403 MACHINE_OUTSIDE_TECHNICIAN_SCOPE`). Recibe exclusivamente ciclos en unidades (`VA1`/`PA2`) y eventos `EA1`/`EA2`. **Nunca** recibe importes monetarios (`VA101` EUR, `CA3`, `PA2` EUR) ni `raw_payload`. |
| **📊 Coordinador de Operaciones (`COORDINATOR`)** | Accede a la consola de **«📄 Auditorías EVA-DTS (.dex)»**, importa ficheros manualmente en escritorio para cualquier máquina activa, consulta el detalle completo con cruces financieros M35 y de carril M34 (más delta efectivo si hubo `VOIDED` previo), descarga el `.dex` crudo con cabeceras seguras, ejecuta anulaciones lógicas justificadas (`VOIDED`, Art. V.1) y exporta CSV UTF-8 BOM. | Único rol autorizado a visualizar importes `VA1` en euros, bloque completo `CA3`, importes `PA2` en euros, comparativas `CA3_VS_VAULT`/`VA1_VS_EXPECTED`, descargar `raw_payload` y ejecutar anulación lógica. |
| **🏢 Responsable de Sede (`LOCATION_RESPONSIBLE`) y Canal Público QR** | No interactúan con ficheros `.dex` ni reciben información derivada de los mismos. | **Opacidad absoluta (Art. V.4):** Ningún endpoint del Portal de Sede, QR público ni Albarán A4 expone volcados `.dex`, contadores de ventas, recaudaciones `CA3`, códigos internos `EA1`/`EA2` ni discrepancias. |

---

## 3. Historias de Usuario

### HU-01: Importación de `.dex` en Ruta por Reponedor con Pre-Relleno Ciego M35 y Asistencia M36
> **Como** Cargador / Reponedor de Ruta (`RESTOCKER`),  
> **quiero** seleccionar un fichero `.dex` extraído de la máquina (online u offline en sótano) para pre-rellenar automáticamente `sales_counter_reading` en mi recaudación M35 en ese acto sin que se me muestre el efectivo en hucha (`CA3`) ni los importes previos en el historial, ver las unidades vendidas por selección y recibir aviso inmediato con botón de 1 toque si hay fallos `EA1`/`EA2` para escalar al SAT,  
> **para** eliminar errores de transcripción manual y detectar averías ocultas al abrir la máquina sin comprometer el Arqueo Ciego.

### HU-02: Diagnóstico Forense con `.dex` en Ruta por Técnico SAT (Incluso en Cuarentena)
> **Como** Técnico de Campo SAT (`TECHNICIAN`),  
> **quiero** subir un fichero `.dex` desde mi vista móvil incluso cuando la máquina se encuentra en `SANITARY_QUARANTINE`, con avería `TEMPERATURE_COLD` abierta o `OUT_OF_SERVICE`, consultar los ciclos acumulados en unidades (`VA1`/`PA2`) y los eventos de fallo `EA1`/`EA2` clasificados por severidad, y abrir una incidencia oportunista M16 con los códigos ya pre-cargados en Nota Interna,  
> **para** diagnosticar fallos intermitentes de placa MDB o refrigeración con evidencia técnica real.

### HU-03: Auditoría Integral, Contraste Financiero/Planograma y Anulación Lógica en Coordinación
> **Como** Coordinador de Operaciones (`COORDINATOR`),  
> **quiero** revisar cada volcado `.dex` en una consola filtrable que cruce automáticamente `CA3`/`VA1` contra M35 (`CA3_VS_VAULT` y `VA1_VS_EXPECTED`), contraste `PA1`/`PA2` contra los carriles del planograma y el consumo observado en M34 (`prev_item.qty_after - curr_item.qty_before`), permita descargar el `.dex` original de forma segura, exportar a CSV y anular lógicamente (`VOIDED`) con motivo $\ge 20$ caracteres cualquier volcado subido a una máquina errónea (recalculando en lectura los deltas efectivos sin mutar el histórico),  
> **para** auditar la fiabilidad de la caja y del surtido sin corromper la serie histórica de deltas.

### HU-04: Protección Sanitaria ante Eventos Térmicos EVA-DTS (Art. II)
> **Como** Coordinador de Operaciones (`COORDINATOR`),  
> **quiero** que cuando un volcado `.dex` de una máquina `PERISHABLE_FOOD` o `COMBO` incluya códigos `EA1`/`EA2` de fallo térmico/refrigeración nuevos o incrementados respecto al volcado previo se muestre un aviso sanitario destacado al operador en ruta y se encole una alerta interna `DEX_THERMAL_EVENT_ALERT` en `notification_outbox`, sin abrir tickets ni cuarentenas automáticas ni repetir alertas por contadores históricos congelados,  
> **para** priorizar la seguridad alimentaria sin generar ruido operativo ni bloqueos falsos en el parque.

### HU-05: Continuidad Offline PWA en Sótanos sin Bloquear Visitas ni Recaudaciones
> **Como** operador en ruta (`RESTOCKER` o `TECHNICIAN`) sin cobertura móvil,  
> **quiero** que mi fichero `.dex` quede validado (`DXS*`..`DXE*`, $\le 1\,\text{MB}$), encolado en `IndexedDB` con pre-lectura segura de `VA101` y `EA1`/`EA2`, y que si al recuperar red ese `.dex` falla (`422`/`409`) se aísle en la bandeja de conflictos sin bloquear la sincronización de las visitas M34, recaudaciones M35 o escalados M36 posteriores,  
> **para** no perder nunca trabajo operativo de campo por un fichero truncado o duplicado.

---

## 4. Requisitos Funcionales (RF) y Criterios de Aceptación (EARS)

### RF-01 · Esquema Relacional Inmutable (`machine_dex_audits`, `machine_dex_selection_lines`, `machine_dex_event_lines`) (`D1`, `C3`, `C6`, `QA-2`, `QA-4`, Art. III, Art. IV)

* **Criterio EARS 01.1 (Nuevos tipos nativos `#51`–`#55`, extensiones de enums y Tablas 52–54 sin modificar DDL de tablas existentes):**
  1. **EL SISTEMA DEBERÁ** crear los enumerados nativos PostgreSQL en `035_eva_dts_dex_audit.sql` y `database/supabase_init.sql`:
     * `#51 dex_audit_status`: `'ACTIVE'`, `'VOIDED'`.
     * `#52 dex_import_source`: `'RESTOCKER_ROUTE'`, `'TECHNICIAN_ROUTE'`, `'COORDINATOR_CONSOLE'`.
     * `#53 dex_selection_match_status`: `'EXACT_MATCH'`, `'LEADING_ZERO_MATCH'`, `'AMBIGUOUS'`, `'UNMAPPED'`, `'HOT_DRINKS_SELECTION'`.
     * `#54 dex_event_category`: `'THERMAL_REFRIGERATION'`, `'PAYMENT_MDB'`, `'MECHANICAL_VEND'`, `'POWER_CONTROLLER'`, `'OTHER'`.
     * `#55 dex_event_severity`: `'CRITICAL'`, `'WARNING'`, `'INFO'`.
  2. **EL SISTEMA DEBERÁ** ampliar `audit_entity_type` con `'MACHINE_DEX_AUDIT'` y `notification_template` con `'DEX_THERMAL_EVENT_ALERT'`.
  3. **EL SISTEMA DEBERÁ** crear la **Tabla 52 `machine_dex_audits`** con:
     * Identidad y trazabilidad: `id` (`BIGINT IDENTITY PK`), `audit_code` (`VARCHAR(24) UNIQUE`, patrón `^DEX-[0-9]{4}-[0-9]{4,}$`), `machine_id` (FK `machines`), `machine_code_snapshot` (`VARCHAR(32)`), `machine_type_snapshot` (`machine_type`), `imported_by` (FK `users`), `importer_role_snapshot` (`user_role`), `importer_name_snapshot` (`VARCHAR(150)`), `import_source` (`dex_import_source`), `status` (`dex_audit_status NOT NULL DEFAULT 'ACTIVE'`).
     * Vínculos operativos opcionales (sin modificar tablas M16/M34/M35/M36): `restock_visit_id` (`BIGINT NULL`, FK `machine_restock_visits`), `cash_collection_id` (`BIGINT NULL`, FK `machine_cash_collections`), `incident_id` (`BIGINT NULL`, FK `incidents`), con índices únicos parciales `WHERE status = 'ACTIVE' AND restock_visit_id IS NOT NULL` y `WHERE status = 'ACTIVE' AND cash_collection_id IS NOT NULL`.
     * Integridad del fichero y metadatos de lectura: `original_filename` (`VARCHAR(255)`), `file_size_bytes` (`INT NOT NULL`, `CHECK (file_size_bytes BETWEEN 1 AND 1048576)`), `sha256_hash` (`CHAR(64) NOT NULL`), `raw_payload` (`TEXT NOT NULL`), `dxs_comm_id` (`VARCHAR(64) NULL`), `dxs_version` (`VARCHAR(32) NULL`), `dex_machine_identifier` (`VARCHAR(64) NULL` desde `ID1`), `identity_mismatch_warning` (`BOOLEAN NOT NULL DEFAULT FALSE`), `id4_decimal_places` (`SMALLINT NOT NULL DEFAULT 2`, `CHECK (id4_decimal_places BETWEEN 0 AND 4)`), `ignored_unknown_lines_count` (`INT NOT NULL DEFAULT 0`).
     * Totales parseados y cadena de deltas: `va1_total_sales_eur` (`NUMERIC(12, 2) NOT NULL CHECK (va1_total_sales_eur >= 0)`), `va1_total_vend_units` (`BIGINT NOT NULL CHECK (va1_total_vend_units >= 0)`), `va1_reset_sales_eur` (`NUMERIC(12, 2) NULL`), `va1_reset_vend_units` (`BIGINT NULL`), `ca3_cashbox_eur` (`NUMERIC(12, 2) NULL`), `ca3_coin_tubes_eur` (`NUMERIC(12, 2) NULL`), `ca3_bills_in_eur` (`NUMERIC(12, 2) NULL`), `previous_audit_id` (`BIGINT NULL`, FK auto-referencial a `machine_dex_audits`), `counter_reset_detected` (`BOOLEAN NOT NULL DEFAULT FALSE`), `out_of_order_sync` (`BOOLEAN NOT NULL DEFAULT FALSE`, `QA-2`), `delta_source` (`VARCHAR(24) NOT NULL`, `'PREVIOUS_AUDIT' | 'INITIAL_BASELINE' | 'RESET_FALLBACK' | 'UNAVAILABLE'`), `delta_sales_eur` (`NUMERIC(12, 2) NULL`), `delta_vend_units` (`BIGINT NULL`).
     * Idempotencia, tiempos y anulación lógica: `client_mutation_id` (`VARCHAR(64) NULL`), `captured_at` (`TIMESTAMPTZ NOT NULL`), `void_reason` (`TEXT NULL`), `voided_by` (`BIGINT NULL`, FK `users`), `voided_by_name_snapshot` (`VARCHAR(150) NULL`), `voided_at` (`TIMESTAMPTZ NULL`), `created_at`, `updated_at`.
     * Índices únicos parciales: `uq_machine_dex_audits_machine_hash UNIQUE (machine_id, sha256_hash) WHERE status = 'ACTIVE'` y `uq_machine_dex_audits_actor_mutation UNIQUE (imported_by, client_mutation_id) WHERE client_mutation_id IS NOT NULL`.
  4. **EL SISTEMA DEBERÁ** crear la **Tabla 53 `machine_dex_selection_lines`** (`id`, `dex_audit_id` FK, `line_sequence`, `raw_selection_code` `VARCHAR(32)`, `normalized_selection_code` `VARCHAR(24)`, `matched_slot_id` `BIGINT NULL` FK `machine_planogram_slots`, `matched_slot_code_snapshot` `VARCHAR(24) NULL`, `match_status` `dex_selection_match_status`, `unit_price_eur` `NUMERIC(10, 2) NULL`, `total_vend_units` `BIGINT NOT NULL`, `total_sales_eur` `NUMERIC(12, 2) NOT NULL`, `reset_vend_units` `BIGINT NULL`, `reset_sales_eur` `NUMERIC(12, 2) NULL`, `delta_vend_units` `BIGINT NULL`, `delta_sales_eur` `NUMERIC(12, 2) NULL`, `created_at`) y la **Tabla 54 `machine_dex_event_lines`** (`id`, `dex_audit_id` FK, `line_sequence`, `source_block` `'EA1' | 'EA2'`, `event_code` `VARCHAR(32)`, `event_category` `dex_event_category`, `severity` `dex_event_severity`, `occurrence_count` `INT NOT NULL DEFAULT 1`, `count_since_reset` `INT NULL`, `count_since_init` `INT NULL`, `is_active_flag` `BOOLEAN NOT NULL DEFAULT FALSE`, `event_occurred_at_raw` `VARCHAR(32) NULL`, `description_es` `VARCHAR(255) NOT NULL`, `is_thermal_alert` `BOOLEAN NOT NULL DEFAULT FALSE`, `created_at`) (`QA-4`).
  5. **EL SISTEMA DEBERÁ** proteger las tres tablas contra `DELETE` mediante `prevent_hard_delete()` (**Art. III.1**) y colocar disparadores de inmutabilidad: `machine_dex_selection_lines` y `machine_dex_event_lines` rechazarán cualquier `UPDATE`; `machine_dex_audits` congelará todas las columnas de captura y parseo tras el `INSERT`, permitiendo únicamente: (a) enlazar una sola vez `NULL` $\rightarrow$ valor no nulo los campos opcionales `restock_visit_id`, `cash_collection_id` o `incident_id` mientras `status = 'ACTIVE'`; y (b) transicionar una sola vez `status` de `'ACTIVE'` a `'VOIDED'` sellando `void_reason`, `voided_by`, `voided_by_name_snapshot` y `voided_at` (`SQLSTATE 45000` en cualquier otro intento, **Art. III.3**).

---

### RF-02 · Parser Determinista EVA-DTS (`.dex`) en PHP 8.2+ e Identidad `ID1` (`D3`, `C1`, `C3`, `C4`, `QA-4`, `QA-5`, Art. I.3, Art. IV, Art. V.5)

* **Criterio EARS 02.1 (Validación de seguridad, codificación UTF-8/ISO-8859-1 y envoltorio `DXS`..`DXE` — `QA-5`):**
  1. **CUANDO** se reciba un payload `.dex` (por subida `multipart/form-data` o cuerpo JSON `raw_payload` desde sincronización Offline), **EL SISTEMA DEBERÁ** validar en servidor antes de parsear:
     * Contenido no vacío y tamaño exacto $\le 1\,048\,576$ bytes ($1\,\text{MB}$, `422 DEX_FILE_TOO_LARGE` si lo supera).
     * Ausencia total de bytes nulos (`\0`) y de caracteres de control peligrosos en `0x00..0x08`, `0x0B..0x0C`, `0x0E..0x1F` y `0x7F` (`422 DEX_INVALID_TEXT_PAYLOAD`).
     * Soporte de `UTF-8` válido (admitiendo tildes, `ñ` y `€` en descripciones `PA103`/`ID104`); si el contenido no es `UTF-8` válido, se convierte deterministamente desde `ISO-8859-1`/`Windows-1252` a `UTF-8` antes de normalizar y calcular el hash.
     * Normalización de saltos de línea (`\r\n` / `\r` $\rightarrow$ `\n`), descartando líneas en blanco extremas, exigiendo que la primera línea no vacía comience por `DXS*` y la última línea no vacía comience por `DXE*` (`422 DEX_INVALID_ENVELOPE`).
     * Presencia obligatoria de al menos un segmento `VA1*` con formato numérico válido (`422 DEX_MISSING_VA1_SEGMENT`) y prohibición de segmentos únicos de cabecera repetidos (`VA1*`, `CA3*`, `ID4*` duplicados $\rightarrow$ `422 DEX_DUPLICATE_HEADER_SEGMENT`).
     * Cálculo de `sha256_hash = hash('sha256', normalized_canonical_payload)` sobre el texto ya normalizado a UTF-8 y `\n` sin espacios finales espurios por línea.
* **Criterio EARS 02.2 (Escalado decimal `ID4`, cotas y parsing tolerante de segmentos — `QA-4`, `QA-5`):**
  1. **EL SISTEMA DEBERÁ** buscar el segmento `ID4*` para extraer el exponente decimal (`ID401` entero en `[0..4]`; si `ID4` falta, asume `2` decimales; si `ID401` está fuera de `[0..4]` o no es entero, rechaza con `422 DEX_INVALID_ID4_DECIMAL`).
  2. **EL SISTEMA DEBERÁ** convertir todo campo monetario entero EVA-DTS dividiendo entre $10^{\text{id4\_decimal\_places}}$ y redondeando a `2` decimales (o aceptar formato decimal explícito con `.`), validando que ningún contador de unidades ni importe monetario sea negativo ni supere los techos del esquema (`9 999 999 999,99 €` en importes; `999 999 999` en unidades; `422 DEX_INVALID_NUMERIC_BOUNDS`).
  3. **EL SISTEMA DEBERÁ** extraer:
     * `ID1`: `dex_machine_identifier` desde `ID101` (o `ID106` si `ID101` está vacío). Si viene poblado y no es todo ceros (`^0+$`) ni plantilla neutra (`^X+$`), lo compara normalizado (`UPPER(BTRIM(...))`) frente a `machines.code`; si difiere, fija `identity_mismatch_warning = TRUE` sin bloquear el alta.
     * `VA1`: `va1_total_sales_eur` (`VA101`), `va1_total_vend_units` (`VA102`), y opcionales `va1_reset_sales_eur` (`VA103`), `va1_reset_vend_units` (`VA104`).
     * `CA3` (opcional): `ca3_cashbox_eur` (`CA302`), `ca3_coin_tubes_eur` (`CA303`) y `ca3_bills_in_eur` (`CA304`) si están presentes; si falta `CA3`, quedan en `NULL`.
     * `PA1` / `PA2` (opcional): emparejando cada `PA1` con su `PA2` consecutivo para obtener código de selección (`PA101`), precio unitario (`PA102` si existe), unidades totales (`PA201`), ventas totales EUR (`PA202`) y parciales desde reseteo (`PA203`, `PA204`). Si un mismo código `PA101` normalizado aparece repetido en el mismo volcado, rechaza con **`422 DEX_DUPLICATE_SELECTION_CODE`** (`QA-4`).
     * `EA1` / `EA2` (opcional, estándar EVA-DTS 6.x): extrayendo de `EA1` código (`EA101`) y fecha/hora cruda (`EA102`/`EA103`), y de `EA2` código (`EA201`), contador desde reseteo (`EA202`), contador acumulado desde inicio (`EA203`) e indicador activo (`EA205 = '1'`), fijando `occurrence_count = EA202 ?? EA203 ?? 1` en `EA2` y `1` en `EA1` (`QA-4`).
     * Todo segmento propietario o no reconocido (`MA5`, `LA1`, `TA2`, `CA1`, `CA2`, `DA1`, `BA1`, etc.) se omite de forma segura incrementando `ignored_unknown_lines_count` sin fallar ni inventar valores (**Art. I.3**).

---

### RF-03 · Guardas de Rol, Perímetro Territorial, Primacía Sanitaria y Proyecciones Art. V.4 (`D1`, `D2`, `C2`, `QA-1`, Art. II.2, Art. V.4)

* **Criterio EARS 03.1 (Autorización por rol, perímetro y bloqueos sanitarios — `QA-1`):**
  1. **CUANDO** se invoque importación `.dex`, **EL SISTEMA DEBERÁ** exigir usuario activo (`is_active = TRUE`) y máquina activa (`machines.is_active = TRUE AND machines.deleted_at IS NULL`, `404`/`422` si no), tomando `SELECT ... FROM machines WHERE id = :machineId FOR UPDATE` para serializar concurrencia e historial de deltas.
  2. **SI** el actor es `'RESTOCKER'`:
     * Debe cubrir la máquina en sus zonas operativas activas hoy (incluidos desvíos `REASSIGNED` activos de M37, `403 MACHINE_OUTSIDE_RESTOCKER_ZONES`).
     * **SI** `machines.sanitary_status = 'QUARANTINE'` **O** existe una incidencia activa con `incidents.category = 'TEMPERATURE_COLD'` y `status NOT IN ('RESOLVED', 'CLOSED', 'CANCELLED')`, **ENTONCES EL SISTEMA DEBERÁ** rechazar el alta tanto en aplicación como en disparador PostgreSQL `enforce_dex_audit_sanitary_guard()` con **`409 Conflict` (`SANITARY_DEX_BLOCKED`)** (**Art. II.2**, `QA-1`). Se permite si `sanitary_status = 'EXPIRED'` o `is_blocked_no_access = TRUE` sin cuarentena ni avería fría viva, para acompañar escalado M36.
  3. **SI** el actor es `'TECHNICIAN'`:
     * Debe tener asignada al menos una intervención/orden activa sobre esa máquina o cubrir hoy la zona operativa de la sede (M17, `403 MACHINE_OUTSIDE_TECHNICIAN_SCOPE`).
     * Se permite importar aunque la máquina esté en `'QUARANTINE'`, con `'TEMPERATURE_COLD'` abierta o `is_blocked_no_access = TRUE` (`C2`).
  4. **SI** el actor es `'COORDINATOR'`: puede importar sobre cualquier máquina activa en cualquier estado sanitario/operativo (`C2`).
* **Criterio EARS 03.2 (Proyecciones estrictas por rol y preservación de Arqueo Ciego — `QA-1`, `QA-3`, Art. V.4):**
  1. **EL SISTEMA DEBERÁ** exponer en dominio proyecciones cerradas sobre `MachineDexAudit`:
     * `toRestockerArray(bool $includeImmediatePrefill = false)`: incluye `id`, `audit_code`, `machine_id`, `machine_code`, `captured_at`, `identity_mismatch_warning`, `counter_reset_detected`, `va1_total_vend_units`, `delta_vend_units`, `selections` (`normalized_selection_code`, `matched_slot_code`, `match_status`, `slot_current_estimated_qty`, `total_vend_units`, `delta_vend_units` — **sin importes en EUR ni campo `restock_expected_delta_units`**), `events` (`EA1`/`EA2` clasificados), `has_thermal_alert` y `suggested_escalation_payload`. **Solo** cuando `$includeImmediatePrefill = true` (respuesta directa del `POST` de importación) incluye `prefill_sales_counter_reading_eur` (`va1_total_sales_eur`); en el `GET` histórico (`$includeImmediatePrefill = false`) **omite siempre** `prefill_sales_counter_reading_eur` y cualquier importe en euros (`QA-1`). **Omite terminantemente** en ambas variantes `ca3_cashbox_eur`, `ca3_coin_tubes_eur`, `ca3_bills_in_eur`, `delta_sales_eur`, `unit_price_eur`, `total_sales_eur`, comparativas M35 y `raw_payload`.
     * `toTechnicianArray()`: igual que la proyección de historial en unidades y eventos `EA1`/`EA2`, **sin** `prefill_sales_counter_reading_eur` ni ningún importe monetario (**Art. V.4**).
     * `toCoordinatorArray()`: vista completa con todos los importes `VA1`/`CA3`/`PA2`, deltas persistidos y efectivos (si `previous_audit_voided = TRUE`), cruces M34 y M35, flag `out_of_order_sync`, flag `can_download_raw` y metadatos de anulación lógica.
     * `Site / QR / A4`: cero endpoints y exclusión absoluta en proyecciones existentes (**Art. V.4**).

---

### RF-04 · Cadena de Deltas, Cruce No Destructivo con M34/M35 y Emparejamiento `PA1` (`D5`, `C1`, `C4`, `QA-2`, `QA-3`)

* **Criterio EARS 04.1 (Cálculo determinista de deltas entre volcados `ACTIVE`, sincronización fuera de orden y lectura tras `VOIDED` — `QA-2`):**
  1. Al insertar un volcado `ACTIVE` (bajo bloqueo `FOR UPDATE` de la fila de `machines`), **EL SISTEMA DEBERÁ** localizar el último volcado `ACTIVE` previo de esa misma `machine_id` con **`captured_at <= new.captured_at`** (ordenado por `captured_at DESC, id DESC`, ignorando volcados `VOIDED`):
     * Si además ya existe al menos un volcado `ACTIVE` con `captured_at > new.captured_at`, marca `out_of_order_sync = TRUE` (`QA-2`).
     * Si **no existe** volcado `ACTIVE` previo con `captured_at <= new.captured_at`: fija `previous_audit_id = NULL`, `counter_reset_detected = FALSE`, `delta_source = 'INITIAL_BASELINE'`, `delta_sales_eur = NULL` y `delta_vend_units = NULL` (y líneas `PA1`/`PA2` con `delta_* = NULL`).
     * Si **existe** dicho volcado previo y `va1_total_sales_eur >= prev.va1_total_sales_eur` y `va1_total_vend_units >= prev.va1_total_vend_units`: fija `previous_audit_id = prev.id`, `counter_reset_detected = FALSE`, `delta_source = 'PREVIOUS_AUDIT'`, `delta_sales_eur = ROUND(va1_total_sales_eur - prev.va1_total_sales_eur, 2)`, `delta_vend_units = va1_total_vend_units - prev.va1_total_vend_units` (y por cada `PA1` emparejable con el previo sin retroceso, `delta_vend_units = cur - prev`, `delta_sales_eur = cur - prev`).
     * Si **existe** dicho volcado previo y alguno de los dos contadores (`va1_total_sales_eur` o `va1_total_vend_units`) retrocede (`< prev`): fija `previous_audit_id = prev.id`, `counter_reset_detected = TRUE`; si el `.dex` aporta `va1_reset_sales_eur` / `va1_reset_vend_units`, fija `delta_source = 'RESET_FALLBACK'` usando esos parciales; si no existen, fija `delta_source = 'UNAVAILABLE'` y `delta_sales_eur = NULL`, `delta_vend_units = NULL` (**C1**, sin inventar cifras).
  2. Los volcados ya persistidos **jamás se modifican** si un predecesor pasa posteriormente a `'VOIDED'` (**Art. III.3**). En el detalle de Coordinación, si el `previous_audit_id` guardado tiene `status = 'VOIDED'`, el servidor expone `previous_audit_voided = TRUE` junto con `effective_previous_audit_id`, `effective_delta_sales_eur` y `effective_delta_vend_units` calculados en lectura frente al último volcado `ACTIVE` anterior a `captured_at` (`QA-2`).
* **Criterio EARS 04.2 (Emparejamiento en 2 pasos con `machine_planogram_slots` y modo `HOT_DRINKS` — `C4`):**
  1. Si `machines.machine_type = 'HOT_DRINKS'`, toda línea `PA1`/`PA2` se persiste con `matched_slot_id = NULL`, `matched_slot_code_snapshot = NULL` y `match_status = 'HOT_DRINKS_SELECTION'`, sin contar como selección no mapeada ni generar alertas de discrepancia de carril.
  2. En el resto de tipologías (`SNACKS`, `COLD_DRINKS`, `PERISHABLE_FOOD`, `COMBO`), frente a los carriles activos (`is_active = TRUE`) de `machine_planogram_slots`:
     * **Paso 1:** coincidencia exacta en mayúsculas (`UPPER(BTRIM(raw_selection_code)) === slot_code`) $\rightarrow$ `match_status = 'EXACT_MATCH'`.
     * **Paso 2:** si no hubo coincidencia exacta, se compara recortando ceros a la izquierda (`ltrim(..., '0')`, preservando `'0'` si todo es cero); si coincide exactamente con un único carril activo y ningún otro `PA1` del mismo volcado colisiona sobre ese carril, asigna `match_status = 'LEADING_ZERO_MATCH'`. Si dos líneas `PA1` del mismo `.dex` resuelven al mismo `slot_id`, ambas se marcan `'AMBIGUOUS'` (`matched_slot_id = NULL`). Si no hay coincidencia, `'UNMAPPED'`.
* **Criterio EARS 04.3 (Pre-relleno M35, enlace explícito `dex_audit_id` y fórmulas cerradas de cruce M34/M35 — `D5`, `QA-3`, `QA-5`):**
  1. En `RestockerRouteView.js`, al subir un `.dex` desde el asistente M34/M35 o desde la parada, el valor `prefill_sales_counter_reading_eur` del `POST` rellena el input de `sales_counter_reading` del formulario M35 (si el operador no lo ha modificado manualmente todavía) y adjunta `dex_audit_id` al enviar la recaudación, la visita o el escalado SAT. Si las rutas M34/M35/M36 reciben `dex_audit_id`, lo validan en la misma transacción rechazando explícitamente con `404 DEX_AUDIT_NOT_FOUND`, `422 DEX_AUDIT_MACHINE_MISMATCH`, `409 DEX_AUDIT_VOIDED` o `409 DEX_AUDIT_ALREADY_LINKED` (`QA-5`).
  2. El sistema **JAMÁS** modifica `machine_planogram_slots.current_estimated_qty` ni las cifras de `machine_restock_visits` o `machine_cash_collections` a partir del `.dex`.
  3. En Coordinación, el detalle del volcado y el KPI de desviación aplican las fórmulas cerradas (`QA-3`):
     * **Cruce financiero M35:** toma la recaudación vinculada por `cash_collection_id` o, si es `NULL`, la más cercana de la misma máquina dentro del **mismo día civil (`Europe/Madrid`)** de `captured_at` (si no hay ninguna, `cash_comparison = null`). Si `ca3_cashbox_eur IS NOT NULL` y `vault_counted_eur IS NOT NULL`, calcula `diff_eur = ROUND(vault_counted_eur - ca3_cashbox_eur, 2)` bajo `comparison_basis = 'CA3_VS_VAULT'`; en su defecto, si existe `delta_sales_eur` efectivo/persistido, calcula `diff_eur = ROUND(expected_cashbox_eur - delta_sales_eur, 2)` bajo `comparison_basis = 'VA1_VS_EXPECTED'` (etiquetado explícitamente por posible venta cashless). Se marca desviación si `ABS(diff_eur) > 1.00`.
     * **Cruce de carril M34:** toma la visita M34 `COMPLETED` vinculada (`restock_visit_id`) o la más cercana del **mismo día civil (`Europe/Madrid`)** y la visita M34 `COMPLETED` inmediatamente anterior; el consumo físico por carril emparejado es `observed_consumption_units = prev_item.qty_after - curr_item.qty_before` (solo si existen ambas líneas) y la diferencia es `delta_vend_units - observed_consumption_units` (`NULL` si falta alguna de las dos visitas o el carril en ellas).

---

### RF-05 · Catálogo Determinista `EA1`/`EA2`, Escalado en 1 Toque (M36/M16) y Alerta Térmica Anti-Spam (`D4`, `QA-4`, Art. II, Art. V.4)

* **Criterio EARS 05.1 (Clasificación fija de códigos `EA1`/`EA2` alineada al estándar EVA-DTS — `QA-4`):**
  1. **EL SISTEMA DEBERÁ** clasificar cada línea `EA1`/`EA2` mediante un catálogo determinista en dominio (`EvaDtsEventCatalog`) con prioridad explícita sobre prefijos y códigos EVA-DTS reales:
     * **`THERMAL_REFRIGERATION` (`CRITICAL`):** prefijo EVA-DTS `EG*` y códigos explícitos `TEMP_*`, `REFR_*`, `COOL_*`, `DEFROST_*`, `EA1_TEMP*`, `EA2_TEMP*`, `E_TEMP*`.
     * **`PAYMENT_MDB` (`WARNING`):** prefijos EVA-DTS `EA*`, `EB*`, `EC*`, `EJ*` (monedero, billetero, cashless/MDB) y códigos `MDB_*`, `COIN_*`, `BILL_*`, `TUBE_*`, `CASHLESS_*`.
     * **`MECHANICAL_VEND` (`WARNING`):** prefijos EVA-DTS `EF*`, `EH*` (extracción, espiral, ascensor, caldera/mezclador) y códigos `MOTOR_*`, `SPIRAL_*`, `DROP_*`, `SENSOR_*`, `VEND_*`.
     * **`POWER_CONTROLLER` (`WARNING`):** prefijos EVA-DTS `ED*`, `EE*` (alimentación, placa VMC, puerta, RTC/comunicación) y códigos `POWER_*`, `VMC_*`, `COMM_*`, `EEPROM_*`.
     * **`OTHER` (`INFO`):** cualquier otro código `EA1`/`EA2` no mapeado, conservando su código crudo intacto sin inventar diagnósticos (**Art. I.3**).
* **Criterio EARS 05.2 (Sugerencia en 1 toque a M36/M16 y alerta térmica anti-spam en `notification_outbox` — `QA-4`, Art. II, Art. V.4):**
  1. Cuando el volcado contenga al menos un evento `CRITICAL` o `WARNING`, la respuesta móvil incluirá `suggested_escalation_payload` con `breakdown_category` sugerida, resumen técnico de códigos (`[DEX {audit_code}] Códigos EA1/EA2: ...`) y botón en 1 toque en UI:
     * En `RestockerRouteView.js`: abre el asistente de escalado al SAT de **Módulo 36** (`ESCALATE_TO_SAT`) pre-rellenando la Nota Interna confidencial (`is_internal = TRUE`, Art. V.4) y enlazando `dex_audit_id`.
     * En `TechnicianRouteView.js`: abre el alta oportunista in situ de **Módulo 16** (o añade Nota Interna si ya existe ticket activo en la máquina) con los códigos detectados y enlace `dex_audit_id`.
  2. **SI** el volcado contiene al menos un evento `THERMAL_REFRIGERATION` **Y** la máquina tiene `machine_type IN ('PERISHABLE_FOOD', 'COMBO')`, **ENTONCES EL SISTEMA DEBERÁ**:
     * Activar `has_thermal_alert = TRUE` en la respuesta móvil para mostrar un banner sanitario de advertencia in situ (**Art. II**).
     * Evaluar si existe novedad térmica respecto al volcado `ACTIVE` previo (`captured_at <= new.captured_at`): al menos un `EA1` térmico nuevo respecto al volcado previo, un `EA2` térmico con `count_since_init > prev_count_since_init` (o en primer volcado `count_since_reset > 0` o `is_active_flag = TRUE`). Solo si hay novedad o incremento, encola en la misma transacción una notificación interna en `notification_outbox` con `template = 'DEX_THERMAL_EVENT_ALERT'` y `dedup_key = 'dex_thermal:' || machine_id || ':' || audit_id` dirigida exclusivamente a los canales internos de Coordinación (**Art. V.4**, jamás a Sede ni QR).
     * **NO** crear tickets automáticos ni cambiar `machines.sanitary_status` a `'QUARANTINE'` de forma desatendida (`D4`).

---

### RF-06 · Modo Offline PWA (M24) con Micro-Prelectura Segura y Aislamiento de Conflictos (`D6`, `C5`, `QA-5`, Art. III.3, Art. V.4)

* **Criterio EARS 06.1 (Encolado Offline y micro-prelectura JS estrictamente acotada — `QA-5`):**
  1. **MIENTRAS** `RESTOCKER` o `TECHNICIAN` esté sin conexión en su vista móvil, al seleccionar un archivo `.dex`, una utilidad vanilla JS (`EvaDtsClientPreview.js`, sin dependencias) validará en cliente tamaño $\le 1\,\text{MB}$, ausencia de bytes nulos, inicio `DXS*` **y** cierre `DXE*`, y extraerá **únicamente**:
     * `id4_decimal_places` (default `2`),
     * `va1_total_sales_eur` (solo expuesto en UI al `RESTOCKER` para pre-rellenar M35; oculto al `TECHNICIAN`),
     * `va1_total_vend_units`, y
     * códigos de eventos `EA1`/`EA2` para aviso inmediato en campo.
     * **Prohibición expresa:** la utilidad JS jamás extrae ni expone valores `CA3` ni importes por carril (`D2`, `D6`, **Art. V.4**).
  2. La mutación se encola en la cola FIFO `IndexedDB` del operador (`IMPORT_DEX_AUDIT`) con `client_mutation_id` (`8..64` chars `^[A-Za-z0-9_.:-]+$`), `machine_id`, `captured_at`, vínculos opcionales locales y `raw_payload`.
* **Criterio EARS 06.2 (Replay idempotente y resolución de conflictos sin bloquear M34/M35/M36 — `C5`):**
  1. Al sincronizar en orden FIFO al recuperar red:
     * **Mismo `(imported_by, client_mutation_id)` y misma `machine_id`:** servidor devuelve `200 OK` (`idempotent_replay = true`) y la cola retira el ítem sin duplicar filas.
     * **Mismo `(imported_by, client_mutation_id)` en distinta `machine_id`:** servidor devuelve `409 CLIENT_MUTATION_ID_ALREADY_USED`.
     * **`409 DEX_DUPLICATE_HASH`:** la cola retira automáticamente el ítem con aviso informativo (*"Volcado ya registrado previamente en la máquina"*) sin generar conflicto bloqueante ni detener la cola.
     * **`422` (fichero corrupto/incompleto), `409 SANITARY_DEX_BLOCKED` o `403`:** el ítem se mueve a la bandeja de conflictos con su motivo exacto y botón de **volcado/descarte auditado en 1 toque** hacia `POST /api/{role}/dex-audits/conflict-dump` (`audit_log`, entidad `'MACHINE'`, **Art. III.3**), **permitiendo continuar** la sincronización de visitas M34, recaudaciones M35 y escalados M36 encolados detrás. Si una recaudación M35 posterior esperaba enlazar un `dex_audit_id` local cuyo `.dex` falló, el cliente la envía con `dex_audit_id = null` preservando intacto `sales_counter_reading`.

---

### RF-07 · Consola de Auditoría DEX en Coordinación, Anulación Lógica y Exportación CSV (`C6`, `QA-2`, `QA-3`, `QA-5`, Art. III.2, Art. V.1, Art. V.4, Art. V.5)

* **Criterio EARS 07.1 (Consola de Coordinación, KPIs, carga manual, descarga segura y detalle completo):**
  1. **EL SISTEMA DEBERÁ** incorporar una sub-vista dedicada en Coordinación (**«📄 Auditorías EVA-DTS (.dex)»**) con:
     * **KPIs reactivos al filtro activo:** total de volcados `ACTIVE`, volcados con alerta térmica/crítica `EA1`/`EA2`, volcados con alerta `ID1` o reseteo de contador (`counter_reset_detected` / `out_of_order_sync`), y volcados con desviación de efectivo $> \pm 1,00\,\text{€}$ frente a M35 (`CA3_VS_VAULT` o `VA1_VS_EXPECTED`).
     * **Filtros:** rango de fechas (`date_from`, `date_to`), zona operativa (`zone_id`), sede (`location_id`), máquina (`machine_id`), fuente de importación (`import_source`), estado (`ACTIVE | VOIDED | ALL`) y solo con alertas (`alerts_only`).
     * **Carga manual en escritorio (`POST /api/coordinator/machines/{machineId}/dex-audits`):** para subir archivos `.dex` directamente sobre cualquier máquina activa.
     * **Modal de detalle:** muestra cabecera (`audit_code`, máquina, `ID1`, warning de identidad/reseteo/`out_of_order_sync`/`previous_audit_voided`), bloque financiero (`VA1` EUR, deltas persistidos y efectivos, `CA3` completo y contraste M35 con `comparison_basis`), tabla de selecciones `PA1`/`PA2` con estado de emparejamiento y consumo observado M34, tabla de eventos `EA1`/`EA2` (`EA202`/`EA203`/`EA205`), y botón **«⬇️ Descargar .dex original»** (`GET /api/coordinator/dex-audits/{id}/raw`, con `Content-Type: text/plain; charset=utf-8`, `X-Content-Type-Options: nosniff` y `Content-Disposition: attachment; filename="DEX-AAAA-NNNN.dex"`, `QA-5`).
* **Criterio EARS 07.2 (Anulación lógica auditada `ACTIVE` $\rightarrow$ `VOIDED` y Exportación CSV UTF-8 BOM — `C6`, Art. III.2, Art. V.1, Art. V.4):**
  1. **CUANDO** un `COORDINATOR` anule lógicamente un volcado `ACTIVE` (`POST /api/coordinator/dex-audits/{id}/void`), **EL SISTEMA DEBERÁ** exigir `void_reason` de `20..500` caracteres reales con al menos `3` bloques alfanuméricos (`422 VOID_REASON_REQUIRED`, **Art. V.1**), transicionar `status = 'VOIDED'`, sellar `voided_by`, `voided_by_name_snapshot` y `voided_at`, y registrar antes/después en `audit_log` (`MACHINE_DEX_AUDIT`). Al pasar a `'VOIDED'`, el `sha256_hash` deja de bloquear reimportación bajo el índice único parcial `WHERE status = 'ACTIVE'` y el volcado anulado queda excluido del cálculo de deltas de futuras importaciones. Si ya estaba `'VOIDED'`, devuelve `409 DEX_AUDIT_ALREADY_VOIDED`.
  2. **EL SISTEMA DEBERÁ** ofrecer exportación CSV autenticada exclusivamente para `COORDINATOR` (`GET /api/coordinator/dex-audits/export-csv`) con BOM UTF-8, separador `;`, fechas en `Europe/Madrid`, neutralización anti-inyección de fórmulas en toda celda que comience por `=`, `+`, `-`, `@`, `\t` o `\r`, y auditoría en `audit_log`.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 · Rendimiento y Presupuesto de Memoria/Latencia:** Todas las consultas de listado, KPIs y resúmenes excluyen `raw_payload` de la lista de columnas `SELECT`; `raw_payload` solo se lee en el endpoint dedicado de descarga en Coordinación. Límite estricto de `1 MB` ($1\,048\,576$ bytes) por volcado para mantener el parseo y la transacción por debajo de `250 ms` sobre Supavisor/PostgreSQL.
* **RNF-02 · Integridad SQL y Defensa en Profundidad (Art. II.2, Art. III, Art. V.1, Art. V.4, Art. V.5):**
  1. Triggers `prevent_hard_delete()` en Tablas 52, 53 y 54; triggers de inmutabilidad en líneas y por etapas en `machine_dex_audits`.
  2. Trigger `enforce_dex_audit_sanitary_guard()` impidiendo en SQL que un usuario `RESTOCKER` inserte una auditoría `.dex` si `machines.sanitary_status = 'QUARANTINE'` o existe una incidencia abierta con `category = 'TEMPERATURE_COLD'` (`QA-1`).
  3. `CHECK` SQL en `void_reason` exigiendo $\ge 20$ caracteres reales y $\ge 3$ bloques alfanuméricos cuando `status = 'VOIDED'`, y `CHECK` no negativos en contadores/importes.
  4. Segregación estricto-privativa en DTOs por rol (`toRestockerArray(bool $includeImmediatePrefill = false)`, `toTechnicianArray()`, `toCoordinatorArray()`) y cabeceras `X-Content-Type-Options: nosniff` + `Content-Disposition: attachment` en descarga `.dex`.
* **RNF-03 · Dogma Vanilla y Diseño Visual (Art. IV):** Cero dependencias externas en PHP 8.2+ y Vue 3 ESM; cumplimiento estricto de [`docs/design.md`](../../docs/design.md) (paleta `#2560ff` / `#f9fafb` / `#2c333f`, bordes `4px`/`8px`, hairline borders) y trazado responsivo sin desbordamiento horizontal a `360 × 800 px` en móvil.
* **RNF-04 · Zona Horaria y Codificación:** Fechas operativas evaluadas en `Europe/Madrid`, persistencia `TIMESTAMPTZ` en PostgreSQL y respuestas JSON con `Content-Type: application/json; charset=utf-8`.

---

## 6. Catálogo de Endpoints REST y Códigos HTTP Canónicos

| Método y Ruta | Roles Permitidos | Propósito y Contrato Clave | Códigos de Error Canónicos |
| :--- | :--- | :--- | :--- |
| `POST /api/restocker/machines/{machineId}/dex-audits` | `RESTOCKER` | Importar `.dex` en ruta (`multipart` o JSON Offline), devuelve `toRestockerArray(true)` con `prefill_sales_counter_reading_eur` inmediato (sin `CA3`/EUR de `PA2`). | `403 MACHINE_OUTSIDE_RESTOCKER_ZONES`, `409 SANITARY_DEX_BLOCKED`, `409 DEX_DUPLICATE_HASH`, `409 CLIENT_MUTATION_ID_ALREADY_USED`, `422` validación `.dex` |
| `GET /api/restocker/machines/{machineId}/dex-audits` | `RESTOCKER` | Listar últimos volcados `ACTIVE` de la máquina con `toRestockerArray(false)` (**sin** `prefill_sales_counter_reading_eur` ni ningún importe en EUR, `QA-1`). | `403`, `404` |
| `POST /api/restocker/dex-audits/conflict-dump` | `RESTOCKER` | Volcado auditado en `audit_log` (entidad `MACHINE`) de un `.dex` fallido en cola Offline M24. | `403`, `404`, `422` |
| `POST /api/technician/machines/{machineId}/dex-audits` | `TECHNICIAN` | Importar `.dex` in situ (admitido en `QUARANTINE`/`TEMPERATURE_COLD`/`OUT_OF_SERVICE`), devuelve `toTechnicianArray()` (sin euros). | `403 MACHINE_OUTSIDE_TECHNICIAN_SCOPE`, `409 DEX_DUPLICATE_HASH`, `409 CLIENT_MUTATION_ID_ALREADY_USED`, `422` |
| `GET /api/technician/machines/{machineId}/dex-audits` | `TECHNICIAN` | Consultar últimos volcados `ACTIVE` de la máquina con proyección `toTechnicianArray()`. | `403`, `404` |
| `POST /api/technician/dex-audits/conflict-dump` | `TECHNICIAN` | Volcado auditado en `audit_log` de conflicto Offline M24 del técnico. | `403`, `404`, `422` |
| `GET /api/coordinator/dex-audits` | `COORDINATOR` | Listado paginado + KPIs reactivos al filtro activo (sin `raw_payload`). | `403`, `422` |
| `POST /api/coordinator/machines/{machineId}/dex-audits` | `COORDINATOR` | Carga manual de `.dex` en escritorio para cualquier máquina activa (`toCoordinatorArray()`). | `403`, `404`, `409 DEX_DUPLICATE_HASH`, `422` |
| `GET /api/coordinator/dex-audits/{id}` | `COORDINATOR` | Detalle completo (`VA1`/`CA3`, deltas efectivos tras `VOIDED`, cruces M34/M35, `PA1`/`PA2`, `EA1`/`EA2`, anulación). | `403`, `404` |
| `GET /api/coordinator/dex-audits/{id}/raw` | `COORDINATOR` | Descarga segura del `.dex` original (`text/plain; charset=utf-8`, `nosniff`, `filename="DEX-AAAA-NNNN.dex"`). | `403`, `404` |
| `POST /api/coordinator/dex-audits/{id}/void` | `COORDINATOR` | Anulación lógica (`ACTIVE` $\rightarrow$ `VOIDED`) con `void_reason` ($\ge 20$ chars reales, Art. V.1). | `403`, `404`, `409 DEX_AUDIT_ALREADY_VOIDED`, `422 VOID_REASON_REQUIRED` |
| `GET /api/coordinator/dex-audits/export-csv` | `COORDINATOR` | Exportación CSV UTF-8 BOM (`;`) neutralizada contra fórmulas y auditada en `audit_log`. | `403`, `422` |

*(Nota de integración M34/M35/M36: las rutas existentes de creación de visitas M34, recaudaciones M35 y escalado/alta M36/M16 aceptan opcionalmente `dex_audit_id`; si el identificador no existe, pertenece a otra máquina, está anulado o ya está enlazado a otra visita/recaudación, devuelven `404 DEX_AUDIT_NOT_FOUND`, `422 DEX_AUDIT_MACHINE_MISMATCH`, `409 DEX_AUDIT_VOIDED` o `409 DEX_AUDIT_ALREADY_LINKED` dentro de la misma transacción).*

---

## 7. Casos Límite Consolidados

| Caso Límite | Comportamiento Esperado |
| :--- | :--- |
| **1. Archivo vacío, bytes nulos, controles peligrosos o $> 1\,\text{MB}$** | Rechazo inmediato `422` (`DEX_FILE_TOO_LARGE` o `DEX_INVALID_TEXT_PAYLOAD`) antes de persistir ni calcular deltas. Acepta `UTF-8` con tildes y convierte `ISO-8859-1`/`Windows-1252` a `UTF-8`. |
| **2. Sin `DXS*`..`DXE*`, sin `VA1*` o cabeceras únicas repetidas** | Rechazo `422` (`DEX_INVALID_ENVELOPE`, `DEX_MISSING_VA1_SEGMENT` o `DEX_DUPLICATE_HEADER_SEGMENT`) en cliente Offline (`DXS*`..`DXE*`) y servidor. |
| **3. Valores negativos, overflow o `PA101` duplicado en el mismo `.dex`** | Rechazo `422` (`DEX_INVALID_NUMERIC_BOUNDS` o `DEX_DUPLICATE_SELECTION_CODE`), evitando volcados corruptos o concatenados. |
| **4. `ID4` ausente vs. `ID4` inválido** | Si falta `ID4`, se aplican `2` decimales por defecto (`id4_decimal_places = 2`). Si `ID4` existe con valor fuera de `0..4`, rechazo `422 DEX_INVALID_ID4_DECIMAL`. |
| **5. `ID1` vacío, `000000` o distinto de `machines.code`** | Si está vacío o es `000000`/`XXXX`, no marca mismatch. Si trae un identificador distinto de `machines.code` normalizado, importa igualmente con `identity_mismatch_warning = TRUE` visible en UI. |
| **6. Líneas `PA1` con ceros a la izquierda (`011` vs `11`) o `HOT_DRINKS`** | En espirales/bandejas: exacta primero, luego recorte unívoco de ceros a la izquierda; si dos `PA1` apuntan al mismo `slot_id`, ambas quedan `'AMBIGUOUS'`. En `HOT_DRINKS`, todas quedan `'HOT_DRINKS_SELECTION'`. |
| **7. Retroceso de `VA1`/`PA2` y sincronización Offline fuera de orden (`QA-2`)** | `previous_audit_id` busca por `captured_at <= new.captured_at` (`out_of_order_sync = TRUE` si ya había uno más reciente). Si retrocede, marca `counter_reset_detected = TRUE` y cae a `RESET_FALLBACK` o `UNAVAILABLE` sin bloquear la cola Offline. |
| **8. Volcado `VOIDED` en mitad de la serie histórica (`QA-2`)** | Los volcados posteriores permanecen inmutables (Art. III.3); Coordinación marca `previous_audit_voided = TRUE` y calcula en lectura `effective_delta_*` contra el predecesor `ACTIVE` válido; el `VOIDED` libera `(machine_id, sha256_hash)` para reimportar en la máquina correcta. |
| **9. Evento térmico `EA1`/`EA2` repetido en placas sin reset (`QA-4`)** | El evento sigue visible en la ficha del volcado, pero `DEX_THERMAL_EVENT_ALERT` en `notification_outbox` solo se encola ante novedad o incremento (`EA203 > prev_EA203` o nuevo `EA1`), evitando spam. |
| **10. Máquina/usuario dado de baja después, `client_mutation_id` ajeno o `.dex` fallido ante M35** | Los volcados históricos siguen consultables, descargables, exportables y anulables mediante snapshots; `client_mutation_id` en máquina distinta devuelve `409 CLIENT_MUTATION_ID_ALREADY_USED`; un `.dex` fallido en Offline va a conflictos dejando sincronizar M35 detrás con `dex_audit_id = null`. |

---

## 8. Fuera de Alcance (Art. VI · YAGNI)

Quedan explícitamente excluidos de este Módulo 39:
1. **Lectura física directa desde el navegador por cable USB/Serie, Web Serial API, Bluetooth o puerto óptico:** El usuario selecciona un fichero `.dex` ya extraído en su dispositivo.
2. **Ingesta telemática remota automática por módem/gateway IoT:** Reservada al Bloque 5 (**Feature 18**).
3. **Disparo o cálculo de mantenimiento preventivo basado en ciclos de desgaste:** Reservado a la **Feature 29**, que reutilizará `machine_dex_audits` y `machine_dex_selection_lines` en su propio ciclo SDD.
4. **Motor de recetas/escandallos para `HOT_DRINKS`:** No se descuentan ni cuadran gramos por botón de bebida.
5. **Mutación automática de `machine_planogram_slots.current_estimated_qty`:** El `.dex` audita en lectura, nunca reemplaza la verdad física de M34.
6. **CRUD administrativo para editar el catálogo de códigos `EA1`/`EA2`:** El catálogo es fijo y determinista en dominio.
7. **Visibilidad alguna en Portal de Sede (`LOCATION_RESPONSIBLE`), Canal Público QR o Albaranes externos A4:** Prohibida por el Art. V.4.

---

## 9. Criterios de Finalización (Definition of Done)

1. Migración incremental `database/supabase_migrations/035_eva_dts_dex_audit.sql` y actualización sincronizada de `database/supabase_init.sql` con tipos `#51`–`#55`, extensiones de `audit_entity_type` y `notification_template`, Tablas 52–54, índices únicos parciales y triggers constitucionales (`prevent_hard_delete`, inmutabilidad por etapas, `enforce_dex_audit_sanitary_guard` con `QUARANTINE` + `TEMPERATURE_COLD`, y `CHECK` de `void_reason`), sin alterar DDL de tablas existentes.
2. Parser determinista EVA-DTS en PHP 8.2+ (`UTF-8`/`ISO-8859-1`, cotas, `EA202`/`EA203`/`EA205`), catálogo `EA1`/`EA2`, validador, servicio de importación/deltas/anti-spam térmico/cruces M34-M35/anulación/CSV y proyecciones estrictas por rol (`QA-1` sin euros en `GET` del reponedor) al 100% bajo pruebas unitarias e integración PostgreSQL (`new_engine` incluido).
3. Integración completa en `RestockerRouteView.js`, `TechnicianRouteView.js`, consola de Coordinación (`CoordinatorDexAuditsSubTab.js`) y cola Offline PWA M24 (`EvaDtsClientPreview.js` con validación `DXS*`..`DXE*` + `IndexedDB` + bandeja de conflictos).
4. Batería completa de pruebas unitarias, HTTP/RBAC/Art. V.4, concurrencia/idempotencia, constitucional PostgreSQL, pruebas JS de UI/Offline y verificación real en navegador a `360 × 800 px` y escritorio al **100 % en verde**, acompañada de `specs/39-eva-dts-dex-audit/traceability-matrix.md`.

---

## 10. Puerta de Aprobación SDD (Fase 2)

Las 6 decisiones de diseño (`D1`–`D6`), los 6 casos límite (`C1`–`C6`) y las 5 resoluciones de auditoría QA (`QA-1`–`QA-5`) han sido consensuados y formalizados con el Product Owner el **2026-11-25**.  
**Estado actual:** Especificación `v1.1` alineada con [`plan.md`](./plan.md) (`v1.1`) y [`tasks.md`](./tasks.md) (`v1.1`), lista para iniciar la ejecución en `T-DEX-01` tras confirmación final del Product Owner.
