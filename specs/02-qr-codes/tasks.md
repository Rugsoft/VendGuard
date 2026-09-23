# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO CÓDIGOS QR (TASKS.MD)
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `02-qr-codes`  
**Documento:** `specs/02-qr-codes/tasks.md`  
**Referencia Funcional:** [`specs/functional/qr_codes_spec.md`](../functional/qr_codes_spec.md)  
**Contratos Técnicos:** [`specs/technical/qr_codes_contracts.md`](../technical/qr_codes_contracts.md)  
**Plan Técnico:** [`specs/02-qr-codes/plan.md`](plan.md)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

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

- [ ] **T-QR-06: Implementar el servicio de resolución de escaneo (`QrScanService.php`)**
  * **Requisitos:** `RF-03` (EARS 3.1, 3.3), `RF-04` (EARS 4.1, 4.3, 4.4), `RF-05` (EARS 5.1, 5.2)
  * **Dependencias:** T-QR-01
  * **Hecho cuando:** `QrScanService::resolve($machineCode)` resuelve la máquina en su sede real vigente y devuelve el estado correspondiente (`CAN_REPORT` identificando perecederos, `ACTIVE_INCIDENT` blindando la privacidad del Art. V.4, `UNDER_WARRANTY` o excepción si la máquina está inactiva/no existe).

- [ ] **T-QR-07: Crear suite de pruebas unitarias para `QrScanServiceTest.php`**
  * **Requisitos:** `RF-04` (Art. V.4), `RF-05`
  * **Dependencias:** T-QR-06
  * **Hecho cuando:** La ejecución de `php tests/unit/QrScanServiceTest.php` pasa al 100% en verde, verificando que los campos privados de técnicos, notas de taller y costes de repuestos jamás figuren en el payload de una máquina con avería abierta.

- [ ] **T-QR-08: Implementar el servicio de reporte por QR y gestión de concurrencia (`QrReportService.php`)**
  * **Requisitos:** `RF-03` (EARS 3.4), `RF-04` (EARS 4.5)
  * **Dependencias:** T-QR-06
  * **Hecho cuando:** `QrReportService::report($data)` crea un nuevo ticket o, si detecta una avería abierta por envío concurrente de otro usuario, anexa las observaciones como comentario adicional devolviendo `merged: true` sin arrojar error técnico.

- [ ] **T-QR-09: Implementar el servicio de emisión y descarga de etiquetas (`QrLabelService.php`)**
  * **Requisitos:** `RF-01` (EARS 1.2, 1.3), `RF-02` (EARS 2.2, 2.3)
  * **Dependencias:** T-QR-04
  * **Hecho cuando:** `QrLabelService::getMachineLabel($machineId, $customPhone, $updateLocation)` genera el SVG y actualiza opcionalmente el teléfono maestro en BD; y `QrLabelService::getLocationBatch($locationId)` devuelve el array de todas las máquinas activas de la sede con sus respectivos SVGs.

---

## Fase 3: Controladores REST y Rutas HTTP (API-First)

- [ ] **T-QR-10: Implementar `QrScanController.php` y registrar rutas públicas de escaneo**
  * **Requisitos:** `RF-03`, `RF-04`, `RF-05`
  * **Dependencias:** T-QR-08
  * **Hecho cuando:** Los endpoints `GET /api/qr/scan/{code}` y `POST /api/qr/report` están registrados en `AppRouter.php` y responden exactamente conforme a los contratos técnicos de `qr_codes_contracts.md`.

- [ ] **T-QR-11: Implementar `QrLabelController.php` y registrar rutas protegidas de coordinador**
  * **Requisitos:** `RF-01`, `RF-02`
  * **Dependencias:** T-QR-09
  * **Hecho cuando:** Los endpoints `GET /api/coordinator/machines/{id}/qr-label` y `GET /api/coordinator/locations/{id}/qr-batch` están protegidos con `InternalAuthMiddleware(COORDINATOR)` y devuelven el JSON/SVG correspondiente o `401 Unauthorized`.

- [ ] **T-QR-12: Crear suite de pruebas de integración HTTP para los endpoints QR (`QrEndpointsIntegrationTest.php`)**
  * **Requisitos:** `RF-01` a `RF-05`
  * **Dependencias:** T-QR-10, T-QR-11
  * **Hecho cuando:** La ejecución de `php tests/integration/QrEndpointsIntegrationTest.php` valida peticiones HTTP reales sobre los 4 endpoints cubriendo respuestas 200, 201, 401, 404, concurrencia simulada y descarga directa con cabecera `image/svg+xml`.

---

## Fase 4: Componentes Frontend y Vistas Vanilla Vue 3

- [ ] **T-QR-13: Implementar la vista móvil de escaneo y reporte (`QrReportView.js`)**
  * **Requisitos:** `RF-03` (EARS 3.1–3.4), `RF-04` (EARS 4.1, 4.2), `RF-05` (EARS 5.2)
  * **Dependencias:** T-QR-10
  * **Hecho cuando:** Al montar `QrReportView` con una máquina limpia se muestra la máquina bloqueada y el banner sanitario en perecederos; ante avería activa muestra el panel público y formulario de comentario adicional; y tras reportar muestra la confirmación `#TICK-XXXX`.

- [ ] **T-QR-14: Implementar componentes de coordinador: `QrLabelModal.js`, `QrBatchPrintView.js` y `qr-print.css`**
  * **Requisitos:** `RF-01`, `RF-02` (EARS 2.1, 2.2)
  * **Dependencias:** T-QR-11
  * **Hecho cuando:** El panel de coordinación incluye el botón "🏷️ Imprimir QR" en cada máquina (con previsualización, teléfono editable, descarga SVG e impresión) y el botón "📄 Etiquetas de Sede (A4)" que maqueta la cuadrícula con saltos de página limpios.

- [ ] **T-QR-15: Integrar detección de `?qr=...` en `app.js` y pruebas frontend**
  * **Requisitos:** `RF-03`, `RNF-02`
  * **Dependencias:** T-QR-13, T-QR-14
  * **Hecho cuando:** Cargar `/?qr=VEND-0101` en el navegador activa automáticamente la vista `QrReportView` ocultando barras administrativas, y la suite `node tests/unit/QrReportViewTest.mjs` pasa al 100% en verde.

---

## Fase 5: Auditoría Constitucional y Despliegue Cloud

- [ ] **T-QR-16: Auditoría Constitucional y Verificación Global de Regresión**
  * **Requisitos:** Artículos I al VII de la Constitución de VendGuard
  * **Dependencias:** T-QR-15
  * **Hecho cuando:** Se ejecutan todas las suites de pruebas (unitarias PHP, unitarias JS e integración) alcanzando el 100% en verde con cero infracciones constitucionales, y se despliega el módulo verificado en la nube.
