# AUDITORÍA FUNCIONAL · MÓDULO 29

## Acta de Merma y Destrucción de Lotes por Cuarentena Sanitaria (APPCC / HACCP)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 29 · `specs/29-haccp-spoilage-log`  
**Referencias:** [`spec.md`](../specs/29-haccp-spoilage-log/spec.md) v3.0 · [`haccp_spoilage_log_contracts.md`](../specs/technical/haccp_spoilage_log_contracts.md) v1.1 · [`traceability-matrix.md`](../specs/29-haccp-spoilage-log/traceability-matrix.md)
**Fecha de la revisión:** 2026-11-06  
**Rama:** `spoilage-log` (commit de referencia `fd28e19`)  
**Alcance:** revisión de solo lectura del código de dominio, API, persistencia, UI y esquema frente a los criterios EARS, decisiones `D1`–`D3`, casos `C1`–`C6` y resoluciones `QA 1`–`QA 4`.  
**Resultado:** núcleo funcional implementado y certificado por la batería; **0 huecos funcionales abiertos**, **7 hallazgos resueltos** y **0 desviaciones menores** pendientes de hardening.

> Este documento no modifica la especificación aprobada. Los hallazgos 1 a 6 son cambios de implementación ya aplicados y el hallazgo 7 se cerró mediante la enmienda §3.4 del contrato técnico (v1.1), aprobada por el Product Owner.

---

## 1. Resumen

| # | Hallazgo | Severidad | Regla afectada | Estado |
| :---: | :--- | :---: | :--- | :---: |
| 1 | Colisión del código de certificado higiénico | **Alta** | RF-03.1 / EARS 03.1 | Resuelto (Módulo 29) |
| 2 | El técnico no accede al Acta A4 de cualquier máquina | **Media** | QA 3.1 | Resuelto |
| 3 | Levantamiento técnico limitado a incidencias de su ruta | **Media** | C2 / HU-01 / EARS 01.2 | Resuelto |
| 4 | Bypass de la validación de la fotografía por la vía JSON | **Media** | EARS 02.2 / RNF-02 | Resuelto |
| 5 | Código interno de máquina vacía impreso en el Acta A4 | **Baja** | D3 / EARS 02.1 | Resuelto |
| 6 | El filtro de fechas no cubre el último segundo del día | **Baja** | QA 4.3 | Resuelto |
| 7 | El contrato promete tabla paginada y no hay paginación | **Baja** | Contrato técnico §3.4 | Resuelto |

---

## 2. Hallazgos funcionales

### 2.1. Colisión del código de certificado higiénico

**Severidad:** Alta  
**Reglas:** RF-03.1, EARS 03.1 (emisión indivisible del acta y del certificado), RNF-01.

**Evidencia**

- `src/Application/Service/SanitarySpoilageService.php::generateCertificateCode()` (líneas 525-528) genera el código con `random_int(1000, 9999)`: `CERT-AAAA-NNNN`.
- `database/supabase_init.sql` (línea 1311) declara `CONSTRAINT uq_certificates_code UNIQUE (certificate_code)`.
- `src/Infrastructure/Repository/PdoSanitaryCertificateRepository.php::createCertificate()` (líneas 39-41) genera además códigos secuenciales `CERT-AAAA-<MAX(id)+1>` en el mismo espacio de nombres.
- No existe reintento ni secuencia dedicada en `SanitarySpoilageService`; una violación de unicidad lanza una excepción PDO.
- El controlador solo convierte en respuesta las `SanitarySpoilageValidationException`; cualquier otro `Throwable` se relanza y produce un **HTTP 500**, revirtiendo la transacción completa.

**Impacto**

El levantamiento de cuarentena falla y la máquina permanece bloqueada, aunque todos los datos sanitarios sean válidos. Con 9.000 códigos aleatorios por año, la probabilidad de al menos una colisión es aproximadamente:

- 8 % con 40 actas emitidas en el año.
- 42 % con 100 actas emitidas en el año.

La convivencia con los certificados secuenciales del servicio preventivo reduce aún más el espacio disponible y adelanta el problema.

**Recomendación**

Emitir el código del certificado con la misma disciplina que `nextRecordCode()`: secuencia dedicada o cálculo bajo `pg_advisory_xact_lock` dentro de la transacción del levantamiento, y reintento acotado ante violación de unicidad como última red. Añadir una prueba que precargue el código candidato y verifique que el levantamiento sigue emitiendo un certificado distinto sin fallar.

**Resolución aplicada (2026-11-06)**

- Se añadió `nextCertificateCode(int $year)` al contrato del repositorio y a `PdoSanitarySpoilageRecordRepository`. Reserva el siguiente correlativo `CERT-AAAA-NNNN` con `pg_advisory_xact_lock`, leyendo el máximo sufijo ya emitido en `sanitary_certificates` —incluidos los certificados de los flujos preventivos y las semillas—, en lugar de sortear un número entre 1000 y 9999.
- `SanitarySpoilageService::liftQuarantineWithSpoilageRecord()` usa esa reserva dentro de la misma transacción del acta y se eliminó `generateCertificateCode()`.
- La prueba `SanitarySpoilageServiceIntegrationTest` siembra un certificado previo `CERT-AAAA-0001` y exige que el levantamiento emita `CERT-AAAA-0002`, verificando además que la fila exista.
- Verificación: canal `new_engine` del módulo en verde (3 suites, 135 aserciones), validador (67), frontend (68) y guarda de dialecto (107). PHP lint sin errores.

**Riesgo residual:** el flujo preventivo (`PdoSanitaryCertificateRepository::createCertificate`) sigue calculando su código con `MAX(id)+1` y no participa del bloqueo de asesoría. Un levantamiento y una emisión preventiva estrictamente concurrentes podrían chocar; la reserva del Módulo 29 ya evita los códigos existentes, pero cerrar esa carrera requiere que ambos flujos compartan la misma secuencia. Queda anotado para una tarea posterior. Además, `PreventiveCoexistenceBridgeService` devuelve un `certificate_code` aleatorio en la respuesta de reinspección sin persistirlo en `sanitary_certificates`; es un flujo distinto, pero conviene alinearlo cuando se aborde la secuencia compartida.

---

### 2.2. El técnico no puede consultar el Acta A4 de cualquier máquina

**Severidad:** Media  
**Regla:** QA 3.1 («cualquier Técnico de Campo activo puede consultar e imprimir el Acta Oficial A4 de cualquier máquina»).

**Evidencia**

- El único punto de entrada al Acta A4 en la vista de técnico es el historial dentro de `SanitarySpoilageLiftModal` (`public/assets/js/components/SanitarySpoilageLiftModal.js`, bloque «Actas emitidas de esta máquina»).
- Ese modal solo se abre desde una incidencia de la ruta marcada con `sanitary_resolution_required` (`public/assets/js/views/TechnicianRouteView.js`, líneas 4454-4466 y 5394-5404).
- No existe buscador de máquinas ni historial independiente de actas para el rol `TECHNICIAN`; el modal `SanitarySpoilageOfficialActModal` solo está montado en esa vista y en la pestaña de Coordinación.
- La API sí permite la consulta por identificador de acta (`GET /api/sanitary-spoilage-records/{id}/official-act`, roles `TECHNICIAN` y `COORDINATOR`).

**Impacto**

Un técnico que atiende una inspección en ruta no puede localizar ni imprimir el acta de una máquina que no forme parte de una incidencia abierta de su ruta, aunque la operación esté autorizada por la API. La capacidad existe en el backend pero no es alcanzable desde la interfaz.

**Recomendación**

Añadir al técnico un acceso directo al Acta A4 desde el historial/buscador de máquinas (o desde la ficha de máquina), sin exponer importes. Cubrir con una prueba de navegación que un técnico abre el A4 de una máquina ajena a su ruta.

**Resolución aplicada (2026-11-06)**

- Se añadió `GET /api/sanitary-spoilage-records/by-machine-code/{code}` para roles `TECHNICIAN` y `COORDINATOR`. Resuelve la máquina por su código visible y reutiliza la proyección pública de `listByMachine()`, sin importes económicos.
- `TechnicianMachineHistoryModal` incorpora un buscador de actas por código de máquina y emite `open-official-act`; `TechnicianRouteView` expone el botón «Consultar actas APPCC por código de máquina» en la cabecera, sin exigir una incidencia de la ruta.
- La prueba HTTP cubre la consulta por código, la opacidad financiera y el 404. Las suites de interfaz cubren la normalización del código, la emisión del identificador del acta y la navegación desde la cabecera.
- Verificación focalizada: `SanitarySpoilageApiIntegrationTest` 64 aserciones; canal `new_engine` 3 suites · 135 aserciones; `SanitarySpoilageFrontendTest` 68; `TechnicianMachineHistoryModalTest` 28; `TechnicianRouteViewTest` 181.
- Batería global: **400 suites · 14.977 aserciones · 0 fallos**, con la cifra canónica sincronizada en README, matriz de trazabilidad y `docs/features_pendientes.md`.

---

### 2.3. Levantamiento técnico limitado a incidencias de su ruta

**Severidad:** Media  
**Reglas:** C2, HU-01 y EARS 01.2 (cualquier técnico activo, incluido un compañero de relevo, puede tramitar el levantamiento).

**Evidencia**

- `openSpoilageLiftModal(incident)` (`TechnicianRouteView.js`, líneas 2395-2408) construye la máquina a partir de la incidencia y solo se invoca desde la tarjeta de una incidencia con `sanitary_resolution_required`.
- Una cuarentena originada por una **inspección preventiva no conforme** sin incidencia asociada no ofrece botón al técnico.
- Coordinación sí dispone del listado global de máquinas en cuarentena y puede levantar cualquiera (`CoordinatorSanitarySpoilageTab`).

**Impacto**

El técnico queda limitado a las cuarentenas que la ruta le presenta como incidencia. Los casos de cuarentena preventiva o de bloqueo cautelar sin incidencia quedan en la práctica solo en manos de Coordinación, por debajo de la autorización transversal que exige C2.

**Recomendación**

Ofrecer al técnico un listado de máquinas activas en cuarentena (similar al de Coordinación, sin datos financieros) o un acceso desde la orden preventiva no conforme. Verificar que el modal sigue exigiendo máquina activa, estado `QUARANTINE` y ausencia de averías abiertas antes de habilitar el envío.

**Resolución aplicada (2026-11-06)**

- Se añadió `GET /api/sanitary-quarantines/active` para `TECHNICIAN` y `COORDINATOR`. Publica las máquinas activas en `QUARANTINE` con código, modelo, sede, tipología, fechas de inspección y el bloqueo C1; no incluye actas ni claves financieras.
- `TechnicianSanitaryQuarantineListModal` lista esas cuarentenas desde la cabecera del técnico. Una avería abierta deshabilita el levantamiento; una cuarentena limpia emite la máquina al modal APPCC existente, que revalida máquina activa, estado `QUARANTINE` y ausencia de averías abiertas antes de habilitar el envío.
- Verificación focalizada: `SanitarySpoilageApiIntegrationTest` 64 aserciones; canal `new_engine` 3 suites · 135 aserciones; `SanitarySpoilageFrontendTest` 68; `TechnicianRouteViewTest` 181; plantillas 76; PWA 44; deuda de tokens 2268/2268.
- Batería global: **400 suites · 14.977 aserciones · 0 fallos**, con la cifra canónica sincronizada en README, matriz de trazabilidad y `docs/features_pendientes.md`.

---

### 2.4. Bypass de la validación de la fotografía por la vía JSON

**Severidad:** Media  
**Reglas:** EARS 02.2 y RNF-02 (fotografía verificada, ≤ 5 MB y formato gráfico real en el 100 % de las actas).

**Evidencia**

- `SanitarySpoilageController::lift()` valida la imagen con `LocalFileUploader` (tamaño y `finfo`) solo cuando llega como `multipart/form-data` con el archivo `photo`.
- Si la petición llega como JSON, acepta `evidence_photo_url` como una cadena de 5 a 255 caracteres y la pasa al servicio sin comprobar que corresponda a un archivo subido, existente o de tipo imagen (`SanitarySpoilageController.php`, líneas 87-89).
- El validador de dominio solo comprueba longitud de la URL (`SanitarySpoilageValidator::validateCreation`).

**Impacto**

Un usuario interno autenticado puede emitir un acta con una URL de evidencia inventada o externa, eludiendo la verificación de contenido real que exige el Art. V.5. El acta quedaría emitida y el A4 mostraría una imagen no acreditada (o ninguna).

**Recomendación**

Aceptar el levantamiento únicamente por `multipart/form-data`, o verificar en el servidor que la URL pertenece a `/uploads/`, que el archivo existe y que su contenido real es JPEG, PNG o WebP dentro del límite. Añadir una prueba negativa con `evidence_photo_url` arbitraria.

**Resolución aplicada (2026-11-06)**

- `LocalFileUploader::validateStoredImage()` comprueba que la ruta JSON sea interna a `/uploads/`, rechaza traversal, enlaces fuera del directorio y consultas o fragmentos, y exige un archivo regular, no vacío, de hasta 5 MB, con MIME real JPEG/PNG/WebP y extensión coherente.
- `SanitarySpoilageController::lift()` aplica esa validación antes de invocar al servicio cuando no hay archivo multipart; cualquier rechazo se traduce a `422 INVALID_SPOILAGE_RECORD` con `field: evidence_photo_url` y no altera la cuarentena.
- Pruebas HTTP: imagen válida fuera de `/uploads/`, ruta con traversal, archivo no gráfico existente, URL inventada y URL válida de una imagen recién almacenada (`201`).
- Verificación: `SanitarySpoilageApiIntegrationTest` 69 aserciones; canal `new_engine` 3 suites · 140 aserciones · 0 fallos; batería global 400 suites · 14.977 aserciones · 0 fallos (la suite HTTP no forma parte de la batería hermética).

---

## 3. Desviaciones menores

### 3.1. Código interno de máquina vacía impreso en el Acta A4

**Severidad:** Baja  
**Reglas:** D3, EARS 02.1.

**Evidencia**

- `SanitarySpoilageValidator::EMPTY_BATCH_CODES = 'SIN_STOCK_AFECTADO'`.
- El Acta A4 imprime `affected_batch_codes` sin traducción (`SanitarySpoilageOfficialActModal.js`).
- La especificación pide registrar los lotes como «Sin stock afectado»; el documento oficial entrega a Sanidad el token interno en mayúsculas y con guiones bajos.

**Impacto**

Es un defecto de presentación del documento oficial, no de integridad: el valor almacenado es correcto y estable. También existe una diferencia menor de redacción en la etiqueta del método vacío («No aplica (Máquina verificada sin stock afectado)» frente a «No aplicable (máquina vacía)»).

**Recomendación**

Mantener el código en la base y añadir una etiqueta de presentación para el A4 y la tabla («Sin stock afectado»). Unificar la redacción del método con la especificación.

**Resolución aplicada (2026-11-06)**

- El valor persistido sigue siendo `SIN_STOCK_AFECTADO`; `SanitarySpoilageOfficialActModal` y la tabla de Coordinación lo traducen a «Sin stock afectado» en la celda y en el título, sin alterar el payload.
- La etiqueta del método vacío se unifica con la especificación: `No aplicable (máquina vacía)`, tanto en `SanitarySpoilageRecord` como en la tabla y en el aviso del modal de levantamiento. El contrato técnico se alinea.
- Pruebas: `SanitarySpoilageFrontendTest` 71 aserciones y `SanitarySpoilageValidatorTest` 68; batería global 400 suites · 14.981 aserciones · 0 fallos.

---

### 3.2. El filtro de fechas no cubre el último segundo del día

**Severidad:** Baja  
**Regla:** QA 4.3 (el rango abarca el día completo inclusive).

**Evidencia**

- `PdoSanitarySpoilageRecordRepository::findFilteredForCoordinator()` normaliza `date_to` a `23:59:59` y aplica `r.created_at <= CAST(:date_to AS timestamptz)` (líneas 620-623).
- `created_at` es `TIMESTAMPTZ`, con precisión de microsegundos: un acta creada a las `23:59:59.400` queda fuera del filtro.

**Impacto**

Puede omitirse un acta del listado y del CSV si se emitió en la fracción final del día. Es un caso poco frecuente pero real y difícil de detectar.

**Recomendación**

Comparar contra el inicio del día siguiente con `<` (`date_to + 1 day`) en lugar de `<= 23:59:59`. Añadir una prueba con una acta en `23:59:59.5`.

**Resolución aplicada (2026-11-06)**

- `PdoSanitarySpoilageRecordRepository::findFilteredForCoordinator()` expresa ahora el límite superior como el inicio exclusivo del día siguiente (`date_to + 1 day` a las `00:00:00`) y compara con `<`. El día final se incluye completo aunque el acta tenga microsegundos; la validación estricta `YYYY-MM-DD` y el rechazo de rangos invertidos no cambian.
- `SanitarySpoilageServiceIntegrationTest` (§7) inserta un acta a las `2026-03-15 23:59:59.5` y certifica que aparece en el listado y en el CSV de ese día, y que no se arrastra al `2026-03-14`.
- Verificación: canal `new_engine` 3 suites · 144 aserciones · 0 fallos (`SanitarySpoilageServiceIntegrationTest` 46, con la aserción `C6` de outbox); batería global 400 suites · 14.981 aserciones · 0 fallos (la suite PostgreSQL viaja en el canal `new_engine`).

---

### 3.3. El contrato promete tabla paginada y no hay paginación

**Severidad:** Baja  
**Regla:** Contrato técnico §3.4.

**Evidencia**

- `specs/technical/haccp_spoilage_log_contracts.md` §3.4 describe la tabla como «paginada/filtrada».
- `PdoSanitarySpoilageRecordRepository::findFilteredForCoordinator()` no aplica `LIMIT` ni recibe página; devuelve todo el histórico.
- `CoordinatorSanitarySpoilageTab.js` y la exportación CSV consumen la lista completa.
- La especificación funcional (EARS 05.2) no exige paginación; solo filtros y ordenación.

**Impacto**

A medida que crezca el histórico, el panel y el CSV cargan todos los registros en una sola respuesta. Hoy no rompe ningún criterio funcional, pero contradice el contrato técnico y degrada la experiencia.

**Recomendación**

O bien implementar paginación con parámetros `page`/`page_size` y controles en la tabla, o bien enmendar el contrato técnico para declarar explícitamente que el listado es completo. La segunda opción requiere aprobación del Product Owner por afectar a un contrato aprobado.

**Resolución aplicada (2026-11-06)**

- **Decisión:** se enmienda el contrato técnico §3.4 en lugar de implementar paginación. La EARS 05.2 solo exige filtros, orden determinista y exportación CSV completa; añadir `page`/`page_size`, metadatos y controles de tabla sería una funcionalidad no solicitada y ampliaría el MVP (Art. VI). El CSV debe seguir exportando la totalidad del conjunto filtrado, no una página.
- **Contrato:** `haccp_spoilage_log_contracts.md` v1.1 declara que el listado es completo, sin `LIMIT`, y que el CSV reutiliza ese mismo conjunto; §3.6 se alinea con la misma regla.
- **Implementación:** no se modifica código ni comportamiento. `findFilteredForCoordinator()` sigue devolviendo todas las actas filtradas y `CoordinatorSanitarySpoilageTab.js` sigue renderizando el listado íntegro.
- **Verificación:** el contrato ya no promete paginación y las únicas menciones restantes son la evidencia histórica del hallazgo; `git diff --check` está limpio. La batería global no cambia (400 suites · 14.981 aserciones) porque la enmienda es documental.

---

## 4. Cobertura verificada (sin hallazgos)

Se contrastaron y se consideran correctamente cubiertos:

- Levantamiento atómico con bloqueo `FOR UPDATE` de la máquina y serialización de levantamientos concurrentes.
- Bloqueo por avería correctiva abierta (C1), máquina inactiva o fuera de cuarentena.
- Autoría del técnico in situ frente al coordinador registrador (QA 1), incluida la auditoría de ambos.
- Operación estrictamente en línea (C3) y ausencia de encolado offline del acta.
- Techos térmicos por tipología y rango físico de ambas lecturas (Art. II, QA 3).
- Unidades `0..500`, lotes `3..500` con bloque alfanumérico real, confirmación de máquina vacía y método de destrucción (D3, QA 4).
- Fotografía obligatoria y verificada en ambas vías: multipart con `LocalFileUploader` y JSON contra archivos existentes en `/uploads/`, con tope de 5 MB, `finfo` y extensión coherente.
- El A4 y la tabla presentan «Sin stock afectado» para las actas de máquina vacía y «No aplicable (máquina vacía)» como método, sin alterar los tokens persistidos.
- Observaciones de higienización `20..1000` con tres bloques reales y aviso de privacidad visible.
- Inmutabilidad de los hechos sanitarios y prohibición de borrado mediante disparadores; relación `1..N` ante reaperturas.
- Coste automático a 2,50 €/ud., ajustes sucesivos con motivo real, rechazo de importes idénticos y opacidad financiera para técnico, Sede, QR y A4.
- Cuatro KPIs globales inmunes al filtrado, orden determinista, listado completo sin paginación (contrato §3.4 v1.1), listado de cuarentenas activas, rango de fechas con el día final completo inclusive (límite superior exclusivo al inicio del día siguiente) y CSV con BOM y neutralización de fórmulas.
- Registro de auditoría de alta, lectura del A4, ajuste y exportación bajo `SANITARY_SPOILAGE_RECORD`.

---

## 5. Orden de remediación propuesto

1. **Hallazgo 1** (Alta): **resuelto** con la reserva correlativa bajo bloqueo de asesoría y la prueba de regresión. Queda anotada la carrera residual con el flujo preventivo.
2. **Hallazgo 2** (Media): **resuelto** con el buscador de actas por código de máquina, accesible desde la cabecera del técnico y desde el historial de máquina, sin importes.
3. **Hallazgo 3** (Media): **resuelto** con el listado transversal de cuarentenas activas y el acceso al levantamiento desde cuarentenas ajenas a la ruta.
4. **Hallazgo 4** (Media): **resuelto** con la validación de la ruta JSON contra archivos reales de `/uploads/`.
5. **Hallazgo 5** (Baja): **resuelto** con la traducción del token en el A4 y la tabla y la alineación de la etiqueta del método vacío.
6. **Hallazgo 6** (Baja): **resuelto** con el límite superior exclusivo al inicio del día siguiente y la regresión con un acta a las `23:59:59.5`.
7. **Hallazgo 7** (Baja): **resuelto** mediante la enmienda §3.4 del contrato técnico (v1.1), que declara el listado completo sin paginación y alinea el CSV con ese mismo conjunto.

Los hallazgos 1 a 6 no requieren modificar `spec.md`: son correcciones de implementación dentro de criterios ya aprobados. El hallazgo 7 se resolvió mediante una enmienda del contrato técnico, sin alterar `spec.md`.

---

## 6. Trazabilidad de la revisión

- **Especificación:** `specs/29-haccp-spoilage-log/spec.md` v3.0.
- **Contratos:** `specs/technical/haccp_spoilage_log_contracts.md` v1.1.
- **Dominio y aplicación:** `SanitarySpoilageService.php`, `SanitarySpoilageValidator.php`, `SanitarySpoilageRecord.php`.
- **Persistencia:** `PdoSanitarySpoilageRecordRepository.php`, `database/supabase_migrations/025_sanitary_spoilage_records.sql`, `database/supabase_init.sql`.
- **API:** `SanitarySpoilageController.php`, `src/Presentation/Routing/AppRouter.php` (líneas 347-352).
- **UI:** `CoordinatorSanitarySpoilageTab.js`, `SanitarySpoilageLiftModal.js`, `SanitarySpoilageOfficialActModal.js`, `TechnicianRouteView.js`.
- **Pruebas de referencia:** `SanitarySpoilageValidatorTest.php`, `SanitarySpoilageServiceIntegrationTest.php`, `SanitarySpoilageApiIntegrationTest.php`, `PostgresSanitarySpoilageSchemaTest.php`, `SanitarySpoilageFrontendTest.mjs`.

La batería global se encuentra en verde tras las remediaciones. Las líneas citadas corresponden a la revisión original sobre el commit `fd28e19`; las resoluciones se aplicaron en la rama `spoilage-log`.
