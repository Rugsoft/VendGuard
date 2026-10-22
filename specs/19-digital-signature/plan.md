# PLAN TÉCNICO: FIRMA DIGITAL DEL RESPONSABLE DE SEDE Y ALBARÁN DE SERVICIO

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 19 · Firma Digital y Albarán de Servicio  
**Referencia funcional:** [`specs/19-digital-signature/spec.md`](spec.md) (versión 2, Q1–Q8)  
**Contratos:** [`specs/technical/digital_signature_contracts.md`](../technical/digital_signature_contracts.md)  
**Estado:** Borrador para aprobación del Product Owner

---

## 1. Resumen de la Solución

El bloque de firma viaja **dentro** del cuerpo de resolución como un objeto opcional (`service_sheet`). El servidor lo valida antes de cualquier escritura y, dentro de la **única transacción del cierre** (piezas + stock + solicitudes + estado + notificación + hoja), inserta una fila inmutable que guarda el trazo como `BYTEA` y **congela el documento firmado** (`signed_document`) leyendo las filas recién escritas. El albarán A4 se compone en el navegador desde ese documento congelado y se imprime con `window.print()`.

Dos superficies consumen el modelo de lectura: la ruta del técnico (tras cerrar, para reimprimir y para consultar sus hojas) y el detalle de incidencia en coordinación (vigente e históricas). La lectura se audita.

No hay servicio de aplicación nuevo, no hay plantilla de notificación nueva, no hay dependencia nueva y no hay fichero en disco.

## 2. Arquitectura por Capas

### 2.1. Core / Domain

* `IncidentServiceSheet` — entidad inmutable; dos ramas excluyentes; identificador y nombre del técnico que resolvió; ancla `resolution_at`.
* `IncidentServiceSheetRepositoryInterface` — `insert()`, `findDocumentByIncidentId(int $incidentId, ?int $sheetId)`, `listByIncidentId(int $incidentId)`.
* `ServiceSheetValidator` — validación pura: base64 estricto (tras quitar espacios y saltos), cabecera PNG, `finfo`, tamaño, píxeles del `IHDR`, normalización del documento, ramas, bloque vacío y claves desconocidas.
* `InvalidServiceSheetException` — lleva `getErrors()` para el 422.

### 2.2. Application

* `SparePartTraceabilityService::resolveIncidentWithParts()`:
  * valida todo (incluido el bloque de firma y la construcción de las entidades de pieza) **antes** de abrir la transacción;
  * abre una transacción **incondicional** que cubre piezas, descuento de furgoneta, solicitudes, transición, encolado y hoja (RD-16, enmienda del Módulo 18);
  * enriquece el `new_state` del evento `RESOLVE_INCIDENT` con `service_sheet` (firmado / ausente, nombre, documento, `recorded_at`), sin el trazo.
* `ServiceSheetDto` — metadatos de la hoja en la respuesta del cierre (nunca el trazo ni el snapshot).

### 2.3. Infrastructure

* `PdoIncidentServiceSheetRepository`:
  * `INSERT ... SELECT` con `jsonb_build_object`/`jsonb_agg` que compone el documento congelado y copia `resolution_at` desde `incidents.resolved_at` (§6.1 de los contratos);
  * lectura del documento con `LEFT JOIN LATERAL` (hoja seleccionada + proyección viva) y consulta de historial (§6.2 y §6.3);
  * autorización del técnico en una consulta `EXISTS` (§6.4);
  * sin `UPDATE` ni `DELETE`.
* Enlace del `BYTEA` con `PDO::PARAM_LOB`; si el driver lo exige, stream en memoria. Se verifica con un PNG real de más de 8 KB.

### 2.4. Presentation

* `TechnicianController::resolveIncident` — captura `InvalidServiceSheetException` → `422 INVALID_SERVICE_SHEET`.
* `TechnicianController::serviceSheet` — `GET /api/technician/incidents/{id}/service-sheet`; regla de acceso «asignado **o** firmante» (RD-17); escribe `VIEW_SERVICE_SHEET`.
* `CoordinatorController::serviceSheet` — `GET /api/coordinator/incidents/{id}/service-sheet`; cualquier incidencia; escribe `VIEW_SERVICE_SHEET`.
* `AppRouter` — dos rutas `GET`; la `POST` de resolución no cambia de ruta.
* Serialización del trazo: base64 solo en el modelo de lectura; en la respuesta del cierre se devuelven únicamente metadatos.

### 2.5. Frontend (Vue 3 ESM, Options API, sin bundler)

* `TechnicianServiceSheetBlock.js` — lienzo con fondo blanco opaco y trazo `#111`, `pointerdown/pointermove/pointerup` + `setPointerCapture`, escalado por `devicePixelRatio`, conservación del trazo al redimensionar (copia del mapa de bits), botón «Borrar», detección de tinta (< 150 píxeles con luminancia < 200), campos nombre/documento, interruptor «No había responsable disponible» con motivo. Emite `change` con el bloque listo o `null`.
* `ServiceSheetModal.js` — carga el modelo de lectura por rol, pinta el documento congelado (o la proyección viva con «Sin firma del responsable»), avisa cuando la hoja es histórica, ofrece el selector de hojas anteriores (`history` + `sheet_id`), imprime la etiqueta «Firma manuscrita digitalizada» y expone «Imprimir / Guardar PDF».
* `TechnicianRouteView.js` — monta el bloque en el modal de resolución; tras el cierre ofrece «Ver albarán».
* `CoordinatorIncidentDetailModal.js` — botón «Albarán de servicio».
* `service-sheet.css` — A4 (`21 × 29,7 cm`), `@media print` con ocultación del resto de la interfaz, tabla de piezas sin importe, `page-break-inside: avoid` en el bloque de firma y flujo multipágina entre secciones.
* `index.html` — `<link rel="stylesheet" href="/assets/css/service-sheet.css">`.

## 3. Matriz de Trazabilidad RF → Artefactos → Suites

| RF | Artefactos principales | Suites que lo prueban |
| :--- | :--- | :--- |
| RF-01 (captura, tinta, resize, fondo) | `TechnicianServiceSheetBlock.js`, `TechnicianRouteView.js` | `TechnicianServiceSheetBlockTest.mjs` |
| RF-01.5 / RD-07 (normalización) | `ServiceSheetValidator` | `ServiceSheetValidatorTest` |
| RF-02 (ausencia) | `ServiceSheetValidator`, bloque | `ServiceSheetValidatorTest`, `TechnicianServiceSheetBlockTest.mjs` |
| RF-03.1 / RD-16 (atomicidad real) | `SparePartTraceabilityService` | `ServiceSheetResolutionTest` (doble que falla), `ServiceSheetAtomicityTest` (integración) |
| RF-03.2 (inmutabilidad) | DDL + trigger | `PostgresServiceSheetSchemaTest` (`new_engine`) |
| RF-03.5 / RD-10 (documento congelado) | repositorio, `ServiceSheetDocumentTest` | `ServiceSheetDocumentTest` (renombrar sede y pieza tras firmar) |
| RF-03.3 / RF-03.6 / RD-11 (vigencia e histórico) | repositorio | `ServiceSheetDocumentTest` (reapertura con y sin firma) |
| RF-04 (documento) | repositorio, controladores | `ServiceSheetDocumentTest` (carga completa, fuera de catálogo, cero piezas) |
| RF-04.2 / RD-04 (sin costes) | repositorio, snapshot | `ServiceSheetPrivacyTest` |
| RF-04.4 (avería histórica sin hoja) | repositorio, `ServiceSheetModal.js` | `ServiceSheetDocumentTest`, `ServiceSheetModalTest.mjs` |
| RF-05 (impresión) | `ServiceSheetModal.js`, `service-sheet.css` | `ServiceSheetModalTest.mjs`, E2E manual |
| RF-05.3 / RD-17 (acceso) | ambos controladores | `ServiceSheetDocumentTest` (403 a técnico ajeno, 200 al firmante desasignado) |
| RF-05.5 (`history`/`sheet_id`) | repositorio | `ServiceSheetDocumentTest` (`404 SERVICE_SHEET_NOT_FOUND`) |
| RF-05.6 / RD-18 (lectura auditada) | controladores | `ServiceSheetDocumentTest` (evento por lectura) |
| RF-06 (auditoría del cierre) | `SparePartTraceabilityService` | `ServiceSheetResolutionTest` |

## 4. Plan de Pruebas

### 4.1. Suites nuevas (batería hermética)

1. **`tests/unit/ServiceSheetValidatorTest.php`** — base64 inválido y con saltos de línea, prefijo `data:` aceptado, no-PNG, PNG > 300 KiB, dimensiones > 2 Mpx, `IHDR` truncado, nombre corto, documento con espacios internos, minúsculas, longitud fuera de rango, claves desconocidas, bloque vacío, ambas ramas, motivo corto, motivo y firma juntos (15+ casos).
2. **`tests/unit/ServiceSheetResolutionTest.php`** — el servicio inserta la hoja **dentro** de la transacción; un fallo del repositorio de hojas propaga y el gestor de transacción recibe `rollback`; el evento `RESOLVE_INCIDENT` lleva el bloque sin el trazo; sin `service_sheet` no se inserta nada; `{}` lanza antes de abrir la transacción.
3. **`tests/integration/ServiceSheetAtomicityTest.php`** — con un repositorio de hojas que lanza: tras el fallo no hay filas en `incident_replaced_parts` para esa incidencia, no se movió el stock y la incidencia sigue `IN_PROGRESS`; el reintento produce exactamente una línea por pieza.
4. **`tests/integration/ServiceSheetDocumentTest.php`** — firma → documento congelado; renombrar la sede y una pieza del catálogo **no** cambia el documento; reapertura + cierre firmado → dos hojas, la segunda vigente y la primera histórica con su técnico; reapertura + cierre sin hoja → `current: false` y no se muestra la firma anterior; cero piezas → `parts: []`; 403 a técnico ajeno; 200 al firmante desasignado; `?sheet_id` de otra avería → `404 SERVICE_SHEET_NOT_FOUND`; cada lectura deja `VIEW_SERVICE_SHEET`.
5. **`tests/integration/ServiceSheetPrivacyTest.php`** — la carga útil serializada (documento, hoja e historial) no contiene `unit_cost`, `total_cost`, `reference_cost`, `notes`, `sla` ni teléfonos.
6. **`tests/unit/TechnicianServiceSheetBlockTest.mjs`** — registra `pointer` + `setPointerCapture`, escala por `devicePixelRatio`, rellena fondo blanco, cuenta tinta, conserva el trazo al redimensionar, ofrece borrado y la rama de ausencia; no usa `innerHTML` con datos del usuario ni asigna `src` sin el prefijo `data:image/png;base64,`.
7. **`tests/unit/ServiceSheetModalTest.mjs`** — incluye ticket, piezas, sello de firma y la etiqueta «Firma manuscrita digitalizada»; muestra «Sin firma del responsable» y «Sin sustitución de componentes»; avisa de hoja histórica; no pinta columna de importe; el CSS declara A4, `@media print` y `page-break-inside: avoid`.
8. **`tests/integration/PostgresServiceSheetSchemaTest.php`** (`new_engine`) — `CHECK` de rama, tope de tamaño, `resolution_at` no nulo, `signed_document` objeto con `version`, índice presente y trigger de borrado.

> El `new_engine` se ejecuta por separado (motor PostgreSQL con `deploy/local/postgres/.env.local`) y **no** entra en las cifras de la batería hermética; el resto suma siete suites a la batería.

### 4.2. Suites y guardas modificadas (con su razón)

* **`tests/unit/SparePartTraceabilityServiceTest.php`** — constructor con un argumento obligatorio; nuevos casos de hoja, ausencia, bloque vacío y orden de escritura dentro de la transacción.
* **`tests/unit/TechnicianRefundVerdictTest.php`, `TechnicianPreventiveSparePartsAndSegregationTest.php`, `CoordinatorSparePartsControllerTest.php`** — mismos ajustes de construcción.
* **`tests/unit/VanStockServiceTest.php`** — sin cambios de contrato; se añade el caso de que un fallo posterior revierte el descuento (integración).
* **`tests/Support/PostgresFixtureBuilder.php`** — crea `incident_service_sheets` con `signed_document` y `resolution_at`.
* **`DocMetricsGuard`** — actualización de cifras canónicas en `README.md` y los documentos auditados (+7 suites).
* **`README.md`** — dos endpoints nuevos en la tabla y cifras de batería.
* **`ConstitutionalAuditTest`** — sin enmienda de términos. La comprobación de costes se hace en `ServiceSheetPrivacyTest`, no en el test estático.

### 4.3. E2E manual (documentado, no automatizable con el arnés actual)

Trazo real con el dedo en un móvil; rotación a mitad de firma sin perder el trazo; borrado y refirma; lectura del albarán tras cerrar; impresión A4 desde Android/iOS (o «Guardar como PDF»); albarán de dos páginas con el bloque de firma entero en una; verificación de que el trazo se ve nítido y no pixelado. Se registra en la bitácora de `tasks.md`.

## 5. Invariantes que la Implementación Debe Respetar

1. **Ningún cierre a medias:** piezas, stock, solicitudes, `RESOLVED` y hoja comparten transacción.
2. **Ningún `UPDATE` ni `DELETE`** sobre `incident_service_sheets`.
3. **Ningún coste ni nota interna** en la respuesta del albarán ni en el snapshot.
4. **El trazo nunca viaja en la respuesta del cierre**, solo en el documento.
5. **El técnico solo ve lo suyo y lo que firmó.**
6. **La validación es de servidor** para tamaño, MIME y dimensiones; la tinta es de cliente y está declarada.
7. **Una hoja existente imprime su `signed_document`, no una proyección viva.**
8. **Sin dependencias nuevas** y sin `composer`/`npm` en runtime.

## 6. Riesgos y Huecos Declarados

| Riesgo | Impacto | Mitigación |
| :--- | :--- | :--- |
| La transacción ahora cubre piezas y stock (RD-16) | Un error de BD al descontar revierte el cierre, cuando antes no | `consume` no consulta disponibilidad y admite negativos; el único fallo posible es de infraestructura. Cubierto por `ServiceSheetAtomicityTest` y la batería del Módulo 18 |
| `PDO::PARAM_LOB` trunca el binario | Firma corrupta impresa | Suite con PNG real > 8 KB y comprobación de longitud y cabecera al leer |
| Firma en blanco aceptada por un cliente hostil | Documento sin trazo | Detección de tinta en cliente + límite declarado (§11.4); añadir GD exigiría autorización de dependencia |
| El lienzo pierde el trazo al rotar | Firma cortada | Copia del mapa de bits antes de redimensionar; si falla, se pide refirmar (RF-01.7) |
| Impresión A4 en iOS/Android | Documento mal paginado | E2E manual en dos dispositivos; `page-break-inside: avoid` |
| Zona horaria de las fechas | Hora impresa distinta según la sesión de BD | El snapshot guarda el instante ISO con offset; el cliente formatea en `Europe/Madrid` |
| La proyección viva (averías sin hoja) sigue derivando | Documento histórico de pre-módulo no congelado | Declarado: sin hoja no hay firma, y el papel no acredita conformidad |
| Auditoría en cada `GET` | Una escritura por lectura; suites que cuentan eventos | Los tests afirman el evento explícito; no hay conteos globales de `audit_log` |
| Nueva dependencia obligatoria en el constructor del servicio | Rotura de factorías de pruebas | Se actualizan en WU-2; la batería completa lo detecta |
| Firma no consentida | El técnico podría rellenar el trazo de otro | No hay control técnico posible, igual que en papel; el albarán identifica al firmante declarado |

## 7. Estrategia de Entrega

Una rama `digital-signature` y **cinco unidades de trabajo** con commit propio (una PR al final, con el mismo criterio que el Módulo 18):

1. **WU-1** — esquema (init + migración 010 + fixture), entidad, puerto, validador y sus suites unitarias.
2. **WU-2** — repositorio PDO, transacción única del cierre, auditoría enriquecida y suites de atomicidad.
3. **WU-3** — endpoints de lectura, regla de acceso, auditoría de lectura y suites de documento y privacidad.
4. **WU-4** — superficies Vue (bloque, hoja, CSS, montajes) y suites `.mjs`.
5. **WU-5** — cifras documentadas, README, límites declarados, E2E y verificación final.
