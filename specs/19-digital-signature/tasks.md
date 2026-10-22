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
* `database/supabase_init.sql` declara `incident_service_sheets` con `signed_document JSONB NOT NULL`, `resolution_at TIMESTAMPTZ NOT NULL`, el `CHECK` de rama (firmante de ≥ 3 tras `btrim`, documento de ≥ 5), el `CHECK` de tamaño (64–307 200 bytes con firma mágica de PNG), el `CHECK` de documento objeto con `version`, el índice `(incident_id, recorded_at DESC, id DESC)` y los disparadores `BEFORE DELETE` y `BEFORE UPDATE`.
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
* `SparePartTraceabilityServiceTest` (sección 7.10) y `ServiceSheetAtomicityTest` en verde.

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

* **WU-1 (T-SIGN-01, T-SIGN-02) — `feat(digital-signature): model the service sheet and its frozen document`.** Entidad, validador, DTO, puerto, migración `010` y esquema canónico. Suites: `ServiceSheetValidatorTest` 100/100, `PostgresServiceSheetSchemaTest` 19/19, convergencia y esquema en verde. Desvío: `DocMetricsGuard` se adelanta desde T-SIGN-08 para no cerrar la unidad con la guarda roja.
* **WU-2 (T-SIGN-03, T-SIGN-04) — `feat(digital-signature): persist parts, stock and the sheet in one transaction`.** Repositorio `INSERT … SELECT` con documento congelado, transacción incondicional del cierre, disparadores de inmutabilidad. Suites: `ServiceSheetAtomicityTest` 39/39, `SparePartTraceabilityServiceTest` 55/55. Desvío: T-SIGN-04 se certifica extendiendo esa suite (sección 7.10) en vez de crear `ServiceSheetResolutionTest.php`.
* **WU-3 (T-SIGN-05) — `feat(digital-signature): expose current and historical service sheets`.** Dos endpoints de lectura, autorización RD-17 en el puerto, anclaje de la hoja vigente y auditoría `VIEW_SERVICE_SHEET`. Suites: `ServiceSheetDocumentTest` 27/27, `ServiceSheetPrivacyTest` 7/7, `ServiceSheetAtomicityTest` 40/40 con el fallo del motor encapsulado. Desvíos: se documenta `INVALID_SHEET_ID` (hueco de contrato) y `TestDataCleanerIsolationTest` pasa a exigir 20 tablas transitorias.

* **WU-4 (T-SIGN-06, T-SIGN-07) — `feat(digital-signature): capture the signature and print the service sheet` (`953544d`).** Bloque táctil de conformidad (`TechnicianServiceSheetBlock`), albarán A4 (`ServiceSheetModal` + `service-sheet.css`), métodos de lectura en `api.technician`/`api.coordinator`, bloque obligatorio en el modal de resolución y botón del albarán en el detalle de coordinación. Suites: `TechnicianServiceSheetBlockTest` 31/31, `ServiceSheetModalTest` 25/25, `TechnicianRouteViewTest` 141/141 (grupo 16), `CoordinatorIncidentDetailModalTest` 182/182 (grupo 21). Desvíos: todo cierre viaja ya como objeto con `service_sheet` (se actualizaron 5.13a y 10.9) y el trazo se escribe en `rgb()` para no sumar deuda al trinquete de tokens.
* **WU-5 (T-SIGN-08, T-SIGN-09) — `docs(digital-signature): close the module and sync documented figures`.** `README.md` documenta el módulo y los dos endpoints de lectura; `DocMetricsGuard` queda COHERENTES con 317 suites / 12.323 aserciones; `features_pendientes.md` marca la feature 4 con su alcance real; la spec conserva Q4 (tinta solo en cliente) y Q5 (retención indefinida). `SchemaConvergenceParityTest` y `SqlDialectPortabilityTest` en verde con la migración 010 dentro de la batería.

**E2E manual pendiente (bloquea el cierre formal de T-SIGN-09).** No se ha podido ejecutar en el entorno del agente: hace falta una sesión de navegador real con un dispositivo táctil y un parque sembrado con una avería asignada. Guion a registrar abajo al ejecutarlo:

1. Sembrar `supabase_init.sql` + `supabase_seeds.sql` y arrancar `php -S 127.0.0.1:8000 -t public public/index.php`.
2. Sede reporta la avería, coordinación la asigna al técnico y el técnico inicia la intervención.
3. Abrir «Resolver Avería»: el bloque aparece con «Firma del responsable» activo y el cierre bloqueado hasta completar la conformidad.
4. Firmar con el dedo: el trazo sigue aunque el dedo salga del lienzo y la página no hace scroll.
5. Borrar el trazo: nombre y documento permanecen y el cierre vuelve a bloquearse.
6. Provocar los tres errores (nombre de 2 caracteres, documento `12 34`, lienzo vacío) y comprobar que cada uno señala su campo.
7. Rotar el dispositivo: el trazo se conserva —o se pide refirmar si el navegador no permite copiar el mapa de bits— y el cierre sigue disponible.
8. Cerrar con firma válida: aparece «Ver albarán» en el aviso de cierre.
9. Abrir el albarán: A4, etiqueta «Firma manuscrita digitalizada», nombre, documento y fecha; sin importes, notas internas ni SLA.
10. Imprimir o guardar como PDF: solo el documento; con diagnóstico y solución largos que ocupen dos páginas, el bloque de firma no se parte.
11. Reabrir la avería en garantía y comprobar que el albarán vigente es el del último cierre y que el selector ofrece la visita anterior como histórica con su fecha.
12. Coordinación: «🧾 Albarán de servicio» reimprime desde el detalle; en una avería `OPEN` el botón no aparece.
13. Declarar ausencia (motivo ≥ 10) en otra avería: el albarán imprime el motivo y no una firma.

