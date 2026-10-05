# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO 09: MODAL DE DETALLE INTEGRAL DE INCIDENCIAS EN TRIAJE (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `09-incident-detail-modal`  
**Documento:** `specs/09-incident-detail-modal/tasks.md`  
**Referencia Funcional:** [`specs/functional/incident_detail_modal_spec.md`](../functional/incident_detail_modal_spec.md) (RF-01 a RF-08, RNF-01 a RNF-06)  
**Plan Técnico:** [`specs/09-incident-detail-modal/plan.md`](plan.md)  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  
**Sistema de Diseño Visual:** [`docs/design.md`](../../docs/design.md) (Tokens Docker: Azul eléctrico `#2560ff`, radio binario 4px/8px, fondo canvas `#f9fafb`, alertas `#f8b60f` y crítico `#e02424`)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Backend DTO y Servicio de Aplicación

- [x] **T-IDM-01: Implementar DTO inmutable `CoordinatorIncidentDetailDto.php`**
  * **Requisitos:** RF-02, RF-03, RF-04, RF-06, RNF-01, RNF-04, RNF-05
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe en `src/Application/DTO/CoordinatorIncidentDetailDto.php` la clase con tipado estricto PHP 8.2+ (`declare(strict_types=1);`), inmutabilidad y método `toArray()` que estructura los bloques `incident`, `location`, `machine`, `technician`, `timeline`, `sla`, `technical_intervention`, `comments`, `refund` y `permissions` conforme al contrato de `plan.md`.

- [x] **T-IDM-02: Implementar métodos de consulta agregada en `PdoIncidentRepository.php`**
  * **Requisitos:** RF-02, RF-04, RF-05, RF-06, RNF-01, Constitución Art. III
  * **Dependencias:** T-IDM-01
  * **Hecho cuando:** `PdoIncidentRepository` implementa el método `findEnrichedDetailById(int|string $identifier): ?array` que recupera en una única transacción de lectura la información completa de la avería, incluyendo datos de máquina, sede cliente, técnico asignado, piezas solicitadas en pausa (`requested_parts`), piezas sustituidas con costes unitarios congelados (`incident_replaced_parts`), bitácora de comentarios y expediente de reintegro vinculado.

- [x] **T-IDM-03: Implementar `CoordinatorIncidentDetailService.php` con algoritmos de SLA y enmascaramiento seguro**
  * **Requisitos:** RF-03, RF-06, RF-07, RNF-01, RNF-05, Constitución Art. II y Art. V.4
  * **Dependencias:** T-IDM-01, T-IDM-02
  * **Hecho cuando:** Existe en `src/Application/Service/CoordinatorIncidentDetailService.php` el servicio que implementa: (1) `computeSlaStatus()` calculando cuenta atrás activa o balance histórico formal cerrado (*"Cumplido en..."* / *"Incumplido por..."*); (2) `maskFinancialAndContactData()` enmascarando teléfono Bizum (`6** *** 789`) e IBAN (`ES**...3456`); y (3) cálculo de permisos operativos según la máquina de estados.

- [x] **T-IDM-04: Implementar suite de pruebas unitarias PHP `CoordinatorIncidentDetailServiceTest.php`**
  * **Requisitos:** RF-02, RF-03, RF-04, RF-06, RF-07, RNF-05
  * **Dependencias:** T-IDM-03
  * **Hecho cuando:** La ejecución de `php tests/unit/CoordinatorIncidentDetailServiceTest.php` pasa al 100% en verde evaluando: cálculo de SLA activo y cerrado, enmascaramiento exacto de datos de pago/contacto, visualización de piezas de catálogo y fuera de catálogo, y matriz de permisos por estado (`REPORTED`, `ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`, `RESOLVED`, `CLOSED`, `CANCELLED`).

---

## Fase 2: Controladores REST y Rutas en AppRouter

- [x] **T-IDM-05: Implementar endpoint `GET /api/coordinator/incidents/{id}/detail` en `CoordinatorController.php`**
  * **Requisitos:** RF-01, RF-02, RF-03, RF-04, RF-06, RNF-01, RNF-05
  * **Dependencias:** T-IDM-03, T-IDM-04
  * **Hecho cuando:** `CoordinatorController::getIncidentDetail(Request $request)` responde `200 OK` con la envolvente canónica JSON y el DTO enriquecido al recibir un ID o código de ticket válido, `404 Not Found` ante tickets inexistentes, y rechaza peticiones no autorizadas.

- [x] **T-IDM-06: Implementar endpoint `POST /api/coordinator/incidents/{id}/comments` en `CoordinatorController.php`**
  * **Requisitos:** RF-05, RNF-04, Constitución Art. III y Art. V.4
  * **Dependencias:** T-IDM-05
  * **Hecho cuando:** `CoordinatorController::addComment(Request $request)` valida texto obligatorio ($\ge 5$ caracteres), persiste el comentario con bandera `is_internal` (público vs. nota interna de taller), registra el evento inmutable `INCIDENT_COMMENT_ADDED` en `audit_log` con el usuario coordinador autenticado y responde `201 Created`.

- [x] **T-IDM-07: Registrar y blindar rutas de detalle y comentarios en `AppRouter.php`**
  * **Requisitos:** RF-01, RF-05, RNF-05
  * **Dependencias:** T-IDM-05, T-IDM-06
  * **Hecho cuando:** Las rutas `GET /api/coordinator/incidents/{id}/detail` y `POST /api/coordinator/incidents/{id}/comments` están registradas en `src/Presentation/Routing/AppRouter.php` bajo el middleware `$coordinatorAuth`, y los tests unitarios de enrutamiento existentes pasan al 100% en verde.

---

## Fase 3: Componente Frontend Vanilla/Vue 3 ES Modules

- [x] **T-IDM-08: Scaffolding y estructura maquetada de `CoordinatorIncidentDetailModal.js`**
  * **Requisitos:** RF-02, RNF-02, RNF-03, docs/design.md
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe en `public/assets/js/components/CoordinatorIncidentDetailModal.js` el componente Vue 3 ESM con props `isOpen` e `incidentId`, emitiendo `close` e `incident-updated`, maquetado con cabecera fija, cuerpo con scroll vertical independiente y pie de acciones conforme a los tokens Docker de `docs/design.md`.

- [x] **T-IDM-09: Renderizado reactivo de cabecera, metadatos, aviso de reapertura y cronograma de SLA**
  * **Requisitos:** RF-02, RF-03, RNF-02
  * **Dependencias:** T-IDM-08
  * **Hecho cuando:** El modal presenta dinámicamente: código de ticket, insignias de estado y urgencia, banner condicional destacado de *"Reabierta en Garantía"* con motivo del cliente, tarjeta de ubicación y máquina con distintivo de perecederos, evidencia gráfica ampliable, línea de tiempo de hitos y tarjeta de monitorización de SLA (con cuenta atrás activa o balance histórico formal cerrado).

- [x] **T-IDM-10: Renderizado de intervención técnica, desglose de repuestos y reintegro enmascarado**
  * **Requisitos:** RF-04, RF-06, RNF-05
  * **Dependencias:** T-IDM-09
  * **Hecho cuando:** El modal muestra: bloque de pausa técnica con piezas de catálogo o fuera de catálogo justificadas, bloque de resolución con diagnóstico, acción y piezas sustituidas con costes unitarios congelados, y bloque condicional de reintegro económico con datos de pago/contacto enmascarados y enlace a la bandeja de Reintegros.

- [x] **T-IDM-11: Implementar bitácora de comentarios y formulario en línea con selector de nota interna**
  * **Requisitos:** RF-05, RNF-04
  * **Dependencias:** T-IDM-10
  * **Hecho cuando:** La bitácora renderiza los mensajes en un contenedor con scroll propio distinguiendo notas públicas de notas internas de taller, y el formulario en línea permite enviar una nueva nota técnica llamando a `POST .../comments` y refrescando la bitácora sin cerrar el modal.

- [x] **T-IDM-12: Implementar paneles colapsables en línea para Asignación y Descarte justificado ($\ge 20$ caracteres)**
  * **Requisitos:** RF-07, RNF-06, Constitución Art. III y Art. V.1
  * **Dependencias:** T-IDM-10
  * **Hecho cuando:** Al pulsar "Asignar/Reasignar" o "Descartar" se despliegan paneles en línea integrados en el cuerpo del modal (sin sub-modales superpuestos); el panel de descarte incluye contador de caracteres reactivo y deshabilita el botón de confirmación si el motivo contiene menos de 20 caracteres reales.

- [ ] **T-IDM-13: Implementar guardián de formulario sucio (*Dirty State Guard*) y control de ciclo de vida del modal**
  * **Requisitos:** RF-08, RNF-06
  * **Dependencias:** T-IDM-11, T-IDM-12
  * **Hecho cuando:** Al pulsar `Escape` o hacer clic en el fondo sombreado exterior, el modal detecta si hay texto sin enviar en el comentario, motivo de descarte o reasignación, solicitando confirmación explícita (*"¿Descartar cambios sin guardar?"*); se cierra inmediatamente si los campos están limpios; y retiene los textos ante errores HTTP de red para permitir reintentos.

---

## Fase 4: Integración en el Panel de Triaje (`CoordinatorDashboardView.js`)

- [ ] **T-IDM-14: Integrar botón disparador "Ver detalle" en las filas de la tabla de triaje**
  * **Requisitos:** RF-01, RNF-02
  * **Dependencias:** T-IDM-08
  * **Hecho cuando:** En la tabla de incidencias de `public/assets/js/views/CoordinatorDashboardView.js`, cada fila incorpora en la columna de acciones el botón "Ver detalle" con icono de inspección y atributo `data-testid="btn-view-detail"`, sin interferir con los botones preexistentes de "Asignar" o "Descartar".

- [ ] **T-IDM-15: Montaje reactivo del modal y sincronización de eventos de actualización**
  * **Requisitos:** RF-01, RF-07, RF-08
  * **Dependencias:** T-IDM-13, T-IDM-14
  * **Hecho cuando:** `CoordinatorDashboardView.js` monta `CoordinatorIncidentDetailModal`, controla su apertura reactiva al pulsar "Ver detalle", y ante el evento `incident-updated` refresca la fila correspondiente en la tabla de triaje sin recargar la página completa.

---

## Fase 4.5: Cierre de Huecos de Backend Detectados en T-IDM-12 (Acuerdo de Product Owner, Octubre 2026)

> [!IMPORTANT]
> Detectados al implementar T-IDM-12: el endpoint de asignación no admite reasignación (solo `REGISTERED`/`REOPENED`) y el de descarte no valida el mínimo de 20 caracteres en servidor. Estas dos tareas cierran ambos huecos antes de las suites de integración T-IDM-17/T-IDM-18.

- [x] **T-IDM-20: Validar en servidor el motivo de descarte con mínimo de 20 caracteres reales**
  * **Requisitos:** RF-07.4, RNF-04, Constitución Art. III.2 y Art. V.1
  * **Dependencias:** T-IDM-07
  * **Hecho cuando:** `CoordinatorController::cancelIncident()` rechaza con `422` (`CANCELLATION_REASON_TOO_SHORT`) cualquier `cancellation_reason` con menos de 20 caracteres reales tras `trim()` (multibyte-safe), conserva la respuesta `200` con motivos válidos y la suite PHP de cancelación pasa al 100% en verde con el nuevo caso límite.

- [ ] **T-IDM-21: Soportar la reasignación técnica en línea con motivo obligatorio y evento INCIDENT_REASSIGNED**
  * **Requisitos:** RF-07.3, RNF-04, Constitución Art. III.3 y Art. V.3
  * **Dependencias:** T-IDM-20
  * **Hecho cuando:** `PATCH /api/coordinator/incidents/{id}/assign` admite reasignaciones desde `ASSIGNED`, `IN_PROGRESS` y `PENDING_PARTS` exigiendo un motivo de reasignación ($\ge 10$ caracteres, `422` si falta), mantiene un único técnico responsable activo (Art. V.3), registra el evento inmutable `INCIDENT_REASSIGNED` en `audit_log`, y el modal vuelve a mostrar el botón "Reasignar Técnico" con su campo de motivo obligatorio operativo.

---

## Fase 5: Pruebas Unitarias Reactivas Frontend, Integración HTTP, Blindaje Constitucional y Cierre

- [ ] **T-IDM-16: Implementar suite de pruebas unitarias reactivas frontend `CoordinatorIncidentDetailModalTest.mjs`**
  * **Requisitos:** RF-01 a RF-08, RNF-01 a RNF-06
  * **Dependencias:** T-IDM-13, T-IDM-15
  * **Hecho cuando:** La ejecución de `node tests/unit/CoordinatorIncidentDetailModalTest.mjs` pasa al 100% en verde verificando: renderizado de bloques, inhabilitación de acciones operativas en tickets resueltos o cerrados, validación de 20 caracteres en descarte, confirmación de guardián sucio al pulsar ESC y cierre limpio sin borrador.

- [ ] **T-IDM-17: Implementar suite de pruebas de integración HTTP `CoordinatorIncidentDetailApiTest.php`**
  * **Requisitos:** RF-01 a RF-07, RNF-01, RNF-04, RNF-05
  * **Dependencias:** T-IDM-07, T-IDM-15, T-IDM-20
  * **Hecho cuando:** La ejecución de `php tests/run_all.php` (o test individual contra MariaDB real) pasa al 100% en verde evaluando: endpoint de detalle enriquecido, asignación técnica desde modal, descarte justificado con motivo válido, rechazo con HTTP 422 ante motivos cortos, adición de notas de taller y persistencia de eventos en `audit_log`.

- [ ] **T-IDM-18: Implementar suite de blindaje constitucional y segregación de datos `CoordinatorIncidentDetailConstitutionalTest.php`**
  * **Requisitos:** Constitución Art. II, Art. III, Art. V.1, Art. V.4, RNF-04, RNF-05
  * **Dependencias:** T-IDM-17, T-IDM-20
  * **Hecho cuando:** La suite pasa al 100% en verde certificando: (1) Inviolabilidad de datos (cero `DELETE FROM`), (2) Validación estricta $\ge 20$ caracteres en descartes, (3) Enmascaramiento irrevocable de teléfonos e IBANs en la respuesta JSON, y (4) Denegación de acceso 403 Forbidden para roles de sede y técnicos.

- [ ] **T-IDM-19: Verificación global de regresión, certificación de Dogma Vanilla y cierre del módulo**
  * **Requisitos:** Todos (RF-01 a RF-08, RNF-01 a RNF-06, Constitución Art. I a VII)
  * **Dependencias:** T-IDM-01 a T-IDM-18, T-IDM-20 y T-IDM-21
  * **Hecho cuando:** La ejecución de `php tests/run_all.php` supera con éxito la totalidad de suites PHP Unit, JS Unit e Integración con 0 errores y 0 fallos, se certifica la ausencia de dependencias npm o Composer externas, y se verifica el cumplimiento estricto del Dualismo Lingüístico.
