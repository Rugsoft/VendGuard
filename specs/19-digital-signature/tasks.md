# TAREAS DE IMPLEMENTACIÓN: FIRMA DIGITAL Y ALBARÁN DE SERVICIO

**Módulo:** 19 · Firma Digital del Responsable de Sede y Albarán de Servicio  
**Spec:** [`spec.md`](spec.md) (versión 2, Q1–Q8) · **Plan:** [`plan.md`](plan.md) · **Contratos:** [`specs/technical/digital_signature_contracts.md`](../technical/digital_signature_contracts.md)  
**Estado:** Pendiente de aprobación del Product Owner (puerta SDD). **No se escribe código hasta la confirmación.**

---

## Work Units (rebanadas de commit)

| WU | Tareas | Commit |
| :--- | :--- | :--- |
| **WU-1** | T-SIGN-01, T-SIGN-02 | `feat(digital-signature): model the service sheet and its frozen document` |
| **WU-2** | T-SIGN-03, T-SIGN-04 | `feat(digital-signature): persist parts, stock and the sheet in one transaction` |
| **WU-3** | T-SIGN-05 | `feat(digital-signature): expose current and historical service sheets` |
| **WU-4** | T-SIGN-06, T-SIGN-07 | `feat(digital-signature): capture the signature and print the service sheet` |
| **WU-5** | T-SIGN-08, T-SIGN-09 | `docs(digital-signature): close the module and sync documented figures` |

---

## Tareas

### T-SIGN-01 · Esquema de la tabla de hojas de servicio
**Hecho cuando:**
* `database/supabase_init.sql` declara `incident_service_sheets` con `signed_document JSONB NOT NULL`, `resolution_at TIMESTAMPTZ NOT NULL`, el `CHECK` de rama, el `CHECK` de tamaño (64–307 200 bytes), el `CHECK` de documento objeto con `version`, el índice `(incident_id, recorded_at DESC, id DESC)` y el trigger `BEFORE DELETE`.
* `database/supabase_migrations/010_incident_service_sheets.sql` crea lo mismo de forma idempotente, sin `ALTER TYPE`.
* `tests/Support/PostgresFixtureBuilder.php` crea la tabla en la base de pruebas.

### T-SIGN-02 · Entidad, puerto y validador
**Hecho cuando:**
* Existen `IncidentServiceSheet` (dos ramas, técnico firmante, `resolution_at`, `signed_document`), `IncidentServiceSheetRepositoryInterface` (`insert`, `findDocumentByIncidentId`, `listByIncidentId`), `ServiceSheetValidator`, `InvalidServiceSheetException` y `ServiceSheetDto`.
* `ServiceSheetValidator` rechaza: bloque vacío, claves desconocidas, base64 inválido, no-PNG, más de 300 KiB, más de 2 Mpx, nombre corto, documento fuera de rango tras normalizar, ambas ramas y motivo corto.
* `ServiceSheetValidatorTest` cubre los 15+ casos del plan, incluido el base64 partido en líneas y el documento «12345678 Z» normalizado a `12345678Z`.

### T-SIGN-03 · Repositorio PDO
**Hecho cuando:**
* `PdoIncidentServiceSheetRepository::insert()` ejecuta el `INSERT ... SELECT` que compone `signed_document` y copia `resolution_at`, y devuelve la hoja con `id`, `recorded_at`, `resolution_at` y `signed_document`; cero filas → error de persistencia.
* `findDocumentByIncidentId()` devuelve el modelo del contrato §4.4 (documento congelado o proyección viva, hoja, `current`, `history`) con `?sheet_id` opcional.
* `listByIncidentId()` devuelve el historial ordenado.
* Un PNG real de más de 8 KB se guarda y se recupera byte a byte (sin truncamiento).
* No existe ningún método de `UPDATE` ni `DELETE`.

### T-SIGN-04 · Transacción única y auditoría
**Hecho cuando:**
* `SparePartTraceabilityService` recibe el repositorio de hojas como argumento obligatorio y todos los puntos de construcción están actualizados.
* La transacción es incondicional y cubre piezas, descuento de furgoneta, solicitudes, transición, encolado y hoja; la validación y la construcción de entidades de pieza ocurren antes.
* Un fallo del repositorio de hojas no deja piezas, ni movimiento de stock, ni cierre (probado con doble en unitario y en integración).
* El reintento tras el fallo no duplica piezas.
* El evento `RESOLVE_INCIDENT` incluye `service_sheet` en `new_state`, sin el trazo.
* `ServiceSheetResolutionTest` y `ServiceSheetAtomicityTest` en verde.

### T-SIGN-05 · Endpoints de lectura
**Hecho cuando:**
* `GET /api/technician/incidents/{id}/service-sheet` y `GET /api/coordinator/incidents/{id}/service-sheet` están registrados en `AppRouter` y devuelven el modelo del contrato §4.4.
* El técnico firmante desasignado recibe `200`; un técnico ajeno, `403 SERVICE_SHEET_FORBIDDEN`.
* Una avería sin resolver y sin hoja recibe `422 INCIDENT_NOT_RESOLVED`; una avería histórica devuelve `sheet: null` y el documento vivo con «Sin firma del responsable» (la mención la pinta el cliente).
* Una hoja histórica se devuelve con `current: false`; tras un segundo cierre **sin** hoja no se reutiliza la firma anterior.
* `?sheet_id` de otra avería responde `404 SERVICE_SHEET_NOT_FOUND`.
* Cada lectura escribe `VIEW_SERVICE_SHEET` con actor, hoja y vigencia.
* `ServiceSheetDocumentTest` y `ServiceSheetPrivacyTest` en verde.

### T-SIGN-06 · Bloque de firma del técnico
**Hecho cuando:**
* `TechnicianServiceSheetBlock.js` captura el trazo con `pointer` + `setPointerCapture`, rellena el lienzo de blanco opaco, dibuja en `#111`, escala por `devicePixelRatio` y conserva el trazo al redimensionar (o pide refirmar si no puede copiarlo).
* Cuenta píxeles con luminancia < 200 y bloquea el envío por debajo de 150.
* Permite borrar el trazo sin tocar nombre ni documento; el interruptor de ausencia deshabilita el lienzo y lo vacía, y viceversa.
* Emite `change` con `{signer_name, signer_document, signature_png_base64}` o `{absence_reason}`.
* `TechnicianRouteView.js` lo monta en el modal de resolución y envía `service_sheet` en el cuerpo del cierre.
* `TechnicianServiceSheetBlockTest.mjs` en verde.

### T-SIGN-07 · Hoja A4 imprimible
**Hecho cuando:**
* `ServiceSheetModal.js` carga el documento por rol, pinta cabecera, diagnóstico, solución, piezas (sin importes) con «Sin sustitución de componentes» cuando no hay ninguna, firma o motivo, y la etiqueta «Firma manuscrita digitalizada».
* Marca visualmente una hoja histórica con su fecha de intervención y ofrece el selector de hojas anteriores cuando `history` tiene más de una.
* `service-sheet.css` declara A4, `@media print` ocultando el resto de la interfaz y `page-break-inside: avoid` en el bloque de firma.
* El botón está en el detalle de coordinación y en la ruta del técnico tras el cierre.
* `ServiceSheetModalTest.mjs` en verde.

### T-SIGN-08 · Guardas y cifras documentadas
**Hecho cuando:**
* `DocMetricsGuard` está COHERENTES con las nuevas suites (frase canónica, insignia y bloque de fases en los documentos auditados).
* `README.md` documenta los dos endpoints nuevos.
* `SchemaConvergenceParityTest` y `SqlDialectPortabilityTest` en verde con la migración 010.

### T-SIGN-09 · Cierre documental y verificación
**Hecho cuando:**
* Batería global en verde (suites y aserciones actualizadas) y `new_engine` de la tabla nueva en verde.
* E2E manual de trazo, rotación, borrado y refirma, e impresión A4 (incluido un documento de dos páginas) registrado abajo.
* `docs/features_pendientes.md` marca la feature 4 como implementada con su alcance real.
* La spec recoge los límites conocidos definitivos, incluidos Q4 (tinta en cliente) y Q5 (retención indefinida).

---

## Dependencias

* T-SIGN-02 depende de T-SIGN-01 (tipos y `CHECK` alineados).
* T-SIGN-04 depende de T-SIGN-02 y T-SIGN-03.
* T-SIGN-05 depende de T-SIGN-03 y T-SIGN-04.
* T-SIGN-07 depende de T-SIGN-05.
* T-SIGN-08 y T-SIGN-09 dependen de todo lo anterior.

---

## Bitácora de ejecución

_(Vacía: la implementación no ha comenzado. Se rellenará por unidad de trabajo, con desviaciones y hallazgos.)_
