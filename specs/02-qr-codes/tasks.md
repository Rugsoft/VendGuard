# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO CÓDIGOS QR (TASKS.MD)
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `02-qr-codes`  
**Documento:** `specs/02-qr-codes/tasks.md`  
**Referencia Funcional:** [`specs/functional/qr_codes_spec.md`](../functional/qr_codes_spec.md)  
**Contratos Técnicos:** [`specs/technical/qr_codes_contracts.md`](../technical/qr_codes_contracts.md)  
**Plan Técnico:** [`specs/02-qr-codes/plan.md`](plan.md)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  
**Estado:** Módulo desplegado en producción · **Fase 6 (Enmienda 1, aprobada el 2026-10-04) implementada y verificada**  

---

## Fase 1: Dominio y Motor Nativo de Generación QR (Backend Vanilla)

- [x] **T-QR-01: Implementar Value Objects y DTOs del Módulo QR (`QrCodeData.php`, `QrLabelConfig.php`)**
  * **Requisitos:** `RF-01`, `RNF-04`
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existen en `src/Core/Domain/Model/` las clases inmutables tipadas `QrCodeData` y `QrLabelConfig` encapsulando datos de máquina, sede, teléfono editable y URL de destino con validaciones de integridad.

- [x] **T-QR-02: Implementar el generador matemático de matrices QR (`QrMatrixGenerator.php`)**
  * **Requisitos:** `RF-01`, `RNF-01`, `RNF-04`
  * **Dependencias:** T-QR-01
  * **Hecho cuando:** `QrMatrixGenerator::generate($text, 'M')` produce en PHP puro una matriz binaria 2D estándar (versiones 2 a 4) con patrones de búsqueda (finder patterns) y corrección Reed-Solomon sin dependencias de Composer ni librerías externas.

- [x] **T-QR-03: Crear suite de pruebas unitarias para `QrMatrixGeneratorTest.php`**
  * **Requisitos:** `RNF-01`, `RNF-04`
  * **Dependencias:** T-QR-02
  * **Hecho cuando:** La ejecución de `php tests/unit/QrMatrixGeneratorTest.php` pasa al 100% en verde validando dimensiones cuadradas correctas, quiet zone de 4 módulos y generación determinista.

- [x] **T-QR-04: Implementar el renderizador vectorial nativo de etiquetas SVG (`NativeSvgQrRenderer.php`)**
  * **Requisitos:** `RF-01` (EARS 1.4), `RF-02` (EARS 2.3), `RNF-04`
  * **Dependencias:** T-QR-02
  * **Hecho cuando:** `NativeSvgQrRenderer::renderLabel($config)` genera una cadena SVG XML válida de 400x600 px conteniendo el logo, código de máquina destacado, QR centralizado de al menos 40x40 mm y teléfono de asistencia técnica.

- [x] **T-QR-05: Crear suite de pruebas unitarias para `NativeSvgQrRendererTest.php`**
  * **Requisitos:** `RF-01`, `RF-02`, `RNF-03`
  * **Dependencias:** T-QR-04
  * **Hecho cuando:** La ejecución de `php tests/unit/NativeSvgQrRendererTest.php` valida que el SVG generado sea sintácticamente válido, contenga las etiquetas `<svg viewBox="0 0 400 600"`, el código de la máquina, el teléfono y los elementos gráficos de seguridad.

---

## Fase 2: Servicios de Aplicación y Lógica de Negocio

- [x] **T-QR-06: Implementar el servicio de resolución de escaneo (`QrScanService.php`)**
  * **Requisitos:** `RF-03` (EARS 3.1, 3.3), `RF-04` (EARS 4.1, 4.3, 4.4), `RF-05` (EARS 5.1, 5.2)
  * **Dependencias:** T-QR-01
  * **Hecho cuando:** `QrScanService::resolve($machineCode)` resuelve la máquina en su sede real vigente y devuelve el estado correspondiente (`CAN_REPORT` identificando perecederos, `ACTIVE_INCIDENT` blindando la privacidad del Art. V.4, `UNDER_WARRANTY` o excepción si la máquina está inactiva/no existe).

- [x] **T-QR-07: Crear suite de pruebas unitarias para `QrScanServiceTest.php`**
  * **Requisitos:** `RF-04` (Art. V.4), `RF-05`
  * **Dependencias:** T-QR-06
  * **Hecho cuando:** La ejecución de `php tests/unit/QrScanServiceTest.php` pasa al 100% en verde, verificando que los campos privados de técnicos, notas de taller y costes de repuestos jamás figuren en el payload de una máquina con avería abierta.

- [x] **T-QR-08: Implementar el servicio de reporte por QR y gestión de concurrencia (`QrReportService.php`)**
  * **Requisitos:** `RF-03` (EARS 3.4), `RF-04` (EARS 4.5)
  * **Dependencias:** T-QR-06
  * **Hecho cuando:** `QrReportService::report($data)` crea un nuevo ticket o, si detecta una avería abierta por envío concurrente de otro usuario, anexa las observaciones como comentario adicional devolviendo `merged: true` sin arrojar error técnico.

- [x] **T-QR-09: Implementar el servicio de emisión y descarga de etiquetas (`QrLabelService.php`)**
  * **Requisitos:** `RF-01` (EARS 1.2, 1.3), `RF-02` (EARS 2.2, 2.3)
  * **Dependencias:** T-QR-04
  * **Hecho cuando:** `QrLabelService::getMachineLabel($machineId, $customPhone, $updateLocation)` genera el SVG y actualiza opcionalmente el teléfono maestro en BD; y `QrLabelService::getLocationBatch($locationId)` devuelve el array de todas las máquinas activas de la sede con sus respectivos SVGs.

---

## Fase 3: Controladores REST y Rutas HTTP (API-First)

- [x] **T-QR-10: Implementar `QrScanController.php` y registrar rutas públicas de escaneo**
  * **Requisitos:** `RF-03`, `RF-04`, `RF-05`
  * **Dependencias:** T-QR-08
  * **Hecho cuando:** Los endpoints `GET /api/qr/scan/{code}` y `POST /api/qr/report` están registrados en `AppRouter.php` y responden exactamente conforme a los contratos técnicos de `qr_codes_contracts.md`.

- [x] **T-QR-11: Implementar `QrLabelController.php` y registrar rutas protegidas de coordinador**
  * **Requisitos:** `RF-01`, `RF-02`
  * **Dependencias:** T-QR-09
  * **Hecho cuando:** Los endpoints `GET /api/coordinator/machines/{id}/qr-label` y `GET /api/coordinator/locations/{id}/qr-batch` están protegidos con `InternalAuthMiddleware(COORDINATOR)` y devuelven el JSON/SVG correspondiente o `401 Unauthorized`.

- [x] **T-QR-12: Crear suite de pruebas de integración HTTP para los endpoints QR (`QrEndpointsIntegrationTest.php`)**
  * **Requisitos:** `RF-01` a `RF-05`
  * **Dependencias:** T-QR-10, T-QR-11
  * **Hecho cuando:** La ejecución de `php tests/integration/QrEndpointsIntegrationTest.php` valida peticiones HTTP reales sobre los 4 endpoints cubriendo respuestas 200, 201, 401, 404, concurrencia simulada y descarga directa con cabecera `image/svg+xml`.

---

## Fase 4: Componentes Frontend y Vistas Vanilla Vue 3

- [x] **T-QR-13: Implementar la vista móvil de escaneo y reporte (`QrReportView.js`)**
  * **Requisitos:** `RF-03` (EARS 3.1–3.4), `RF-04` (EARS 4.1, 4.2), `RF-05` (EARS 5.2)
  * **Dependencias:** T-QR-10
  * **Hecho cuando:** Al montar `QrReportView` con una máquina limpia se muestra la máquina bloqueada y el banner sanitario en perecederos; ante avería activa muestra el panel público y formulario de comentario adicional; y tras reportar muestra la confirmación `#TICK-XXXX`.

- [x] **T-QR-14: Implementar componentes de coordinador: `QrLabelModal.js`, `QrBatchPrintView.js` y `qr-print.css`**
  * **Requisitos:** `RF-01`, `RF-02` (EARS 2.1, 2.2)
  * **Dependencias:** T-QR-11
  * **Hecho cuando:** El panel de coordinación incluye el botón "🏷️ Imprimir QR" en cada máquina (con previsualización, teléfono editable, descarga SVG e impresión) y el botón "📄 Etiquetas de Sede (A4)" que maqueta la cuadrícula con saltos de página limpios.

- [x] **T-QR-15: Integrar detección de `?qr=...` en `app.js` y pruebas frontend**
  * **Requisitos:** `RF-03`, `RNF-02`
  * **Dependencias:** T-QR-13, T-QR-14
  * **Hecho cuando:** Cargar `/?qr=VEND-0101` en el navegador activa automáticamente la vista `QrReportView` ocultando barras administrativas, y la suite `node tests/unit/QrReportViewTest.mjs` pasa al 100% en verde.

---

## Fase 5: Auditoría Constitucional y Despliegue Cloud

- [x] **T-QR-16: Auditoría Constitucional y Verificación Global de Regresión**
  * **Requisitos:** Artículos I al VII de la Constitución de VendGuard
  * **Dependencias:** T-QR-15
  * **Hecho cuando:** Se ejecutan todas las suites de pruebas (unitarias PHP, unitarias JS e integración) alcanzando el 100% en verde con cero infracciones constitucionales, y se despliega el módulo verificado en la nube.

---

## Fase 6: Enmienda 1 — Escaneo en Pantalla y Host Configurable (Aprobada 2026-10-04 · Implementada)

- [x] **T-QR-17: Resolver dinámicamente la URL base de las etiquetas (`APP_BASE_URL` + host de petición)**
  * **Requisitos:** Contrato técnico `qr_codes_contracts.md` §1.1–§1.2 (Enmienda 1)
  * **Dependencias:** T-QR-09, T-QR-11
  * **Hecho cuando:** `QrLabelService` y `QrLabelController` construyen el destino con `APP_BASE_URL` si existe y, en su defecto, con el host efectivo de la petición (`X-Forwarded-Proto` + `Host`), sin literales de dominio embebidos; el pie del SVG muestra el host resuelto y las suites `QrEndpointsIntegrationTest.php` verifican los tres criterios de aceptación de §1.2.

- [x] **T-QR-18: Implementar el "Modo escaneo" a pantalla completa en `QrLabelModal.js` y `qr-print.css`**
  * **Requisitos:** `RF-06` (EARS 6.1–6.5), `RNF-05`
  * **Dependencias:** T-QR-14
  * **Hecho cuando:** El modal ofrece la acción "🔍 Modo escaneo" que abre una capa a pantalla completa con el QR maximizado (matriz + zona de silencio ≥ 85% del lado menor, ≥ 4 px CSS/módulo en viewports ≥ 360 px), fondo blanco opaco, contraste máximo y cierre que conserva el estado del modal.

- [x] **T-QR-19: Suites de pruebas del modo escaneo (`QrLabelModalScanModeTest.mjs`) y de la URL configurable**
  * **Requisitos:** `RF-06`, Contrato técnico §1.2
  * **Dependencias:** T-QR-17, T-QR-18
  * **Hecho cuando:** La suite Node valida apertura/cierre del modo escaneo, dimensionado mínimo del QR y conservación del teléfono; la suite PHP valida la precedencia de `APP_BASE_URL`/host de petición y la ausencia de dominios embebidos.

- [ ] **T-QR-20: Verificación global de regresión y validación física con Android**
  * **Requisitos:** Artículos I al VII de la Constitución; criterios de finalización §8.8–§8.9 de `qr_codes_spec.md`
  * **Dependencias:** T-QR-17, T-QR-18, T-QR-19
  * **Hecho cuando:** `php tests/run_all.php` pasa al 100% en verde y el modo escaneo se valida manualmente con al menos un móvil Android de referencia frente a la pantalla.
  * **Estado 2026-10-04:** regresión completada (168 suites / 6.317 aserciones al 100%) y validación manual en navegador (86% del lado menor, 8,2–11,4 px/módulo, decodificación real del lienzo recortado). ⏳ Pendiente únicamente la comprobación física del usuario con su móvil Android tras desplegar.
