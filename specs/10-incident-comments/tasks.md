# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO 10: HILO DE COMENTARIOS BIDIRECCIONAL CON NOTAS INTERNAS CONFIDENCIALES (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `10-incident-comments`  
**Documento:** `specs/10-incident-comments/tasks.md`  
**Referencia Funcional:** [`specs/10-incident-comments/spec.md`](spec.md) (y [`specs/functional/incident_comments_spec.md`](../functional/incident_comments_spec.md))  
**Plan Técnico:** [`specs/10-incident-comments/plan.md`](plan.md)  
**Normativa Suprema:** [`constitution.md`](../../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  
**Sistema de Diseño Visual:** [`docs/design.md`](../../docs/design.md) (Tokens Docker: Azul `#2560ff`, ámbar técnico `#f8b60f`, gris neutro `#e5e7eb`, canvas `#f9fafb`, radio 4px/8px)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Backend DTOs, Extensión de Repositorio y Servicio de Aplicación

- [x] **T-COM-01: Implementar DTOs inmutables `IncidentCommentItemDto.php` e `IncidentCommentThreadDto.php`**
  * **Requisitos:** RF-01.2, RF-01.3, RF-02.1, RF-02.2, RF-02.3, RNF-01
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existen en `src/Application/DTO/IncidentCommentItemDto.php` y `src/Application/DTO/IncidentCommentThreadDto.php` las clases `final readonly` con tipado estricto PHP 8.2+ (`declare(strict_types=1);`), inmutabilidad y métodos `toArray()` y `jsonSerialize()` que modelan la cabecera contextual del expediente, la paginación por cursor (`total_comments`, `loaded_count`, `has_more_before`, `oldest_id`, `latest_id`) y la lista de mensajes proyectados con soporte para enmascaramiento y exclusión de campos de confidencialidad interna en perfiles de sede.

- [x] **T-COM-02: Extender `IncidentRepositoryInterface` y `PdoIncidentRepository` con paginación cursorizada y recuentos segregados**
  * **Requisitos:** RF-01.1, RF-01.2, RF-01.3, RF-02.1, Constitución Art. III
  * **Dependencias:** T-COM-01
  * **Hecho cuando:** `IncidentRepositoryInterface` y `PdoIncidentRepository` incorporan: (1) `getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array` utilizando la clave indexada `idx_comments_incident` para devolver bloques cronológicos sin escaneos completos de tabla; (2) `countComments(int $incidentId, bool $includeInternal): int` permitiendo calcular recuentos segregados (solo públicos con `is_internal = 0` vs. total con `is_internal IN (0, 1)`); y la prueba de integración del repositorio valida ambos métodos.

- [x] **T-COM-03: Implementar `IncidentCommentService.php` con lógica de segregación, enmascaramiento, límites y máquina de estados**
  * **Requisitos:** RF-02.1, RF-02.2, RF-02.3, RF-03.1, RF-04.2, RF-04.3, RF-05.1, RF-05.2, RF-05.3, RF-06.3, RNF-01, Constitución Art. III, Art. V.4, Art. V.5
  * **Dependencias:** T-COM-01, T-COM-02
  * **Hecho cuando:** Existe en `src/Application/Service/IncidentCommentService.php` el servicio que implementa: (1) `getThread(int|string $identifier, string $role, ?int $authUserId, int $limit, ?int $beforeId)` con filtrado estricto de notas internas y enmascaramiento oficial del técnico (`"Servicio Técnico Oficial (Operador #XX)"`) ante la Sede; (2) `addComment(...)` validando longitud (5 a 1.000 caracteres descriptivos), verificando que el expediente admita comentarios (activo o `RESOLVED` $\le 48\text{ h}$, rechazando sellados con `ConversationSealedException`), validando imágenes con `LocalFileUploader` ($\le 5\text{ MB}$, magic bytes con `finfo`), fijando `is_internal = false` forzoso para Sede y por defecto `true` para Técnico/Coordinador, y emitiendo el evento inmutable `INCIDENT_COMMENT_ADDED` a `audit_log`.

- [x] **T-COM-04: Implementar pruebas unitarias PHP `IncidentCommentServiceTest.php` e `IncidentCommentThreadDtoTest.php`**
  * **Requisitos:** RF-02, RF-03.1, RF-05, RF-06, RNF-01, Constitución Art. III, Art. V.4
  * **Dependencias:** T-COM-03
  * **Hecho cuando:** La ejecución de `php tests/unit/IncidentCommentServiceTest.php` y `php tests/unit/IncidentCommentThreadDtoTest.php` pasa al 100% en verde evaluando: segregación estricta para sede (cero notas internas ni campo `is_internal`), enmascaramiento de identidad técnica ante la sede, visualización nominal completa y marcas de candado para técnicos/coordinadores, rechazo de textos con $< 5$ o $> 1.000$ caracteres, máquina de estados con garantía de 48h activa vs. rechazo de tickets cerrados/cancelados, y serialización inmutable de DTOs.

---

## Fase 2: Controladores REST y Rutas en AppRouter

- [x] **T-COM-05: Implementar endpoints de Sede en `LocationPortalController.php`**
  * **Requisitos:** RF-01.2, RF-02.1, RF-02.2, RF-03.2, RF-04.1, RNF-01, Constitución Art. V.4
  * **Dependencias:** T-COM-03, T-COM-04
  * **Hecho cuando:** `LocationPortalController` implementa `getComments(Request $request)` y `addComment(Request $request)` orquestados mediante `IncidentCommentService`, aceptando `multipart/form-data` con foto opcional, garantizando que el cliente jamás reciba notas internas ni configure `is_internal = true`, respondiendo `200 OK` con `IncidentCommentThreadDto` y `201 Created` al publicar, o `403 Forbidden` si el expediente está sellado.

- [x] **T-COM-06: Implementar endpoints de Técnico en `TechnicianController.php`**
  * **Requisitos:** RF-01.2, RF-02.3, RF-03.3, RF-04.1, Constitución Art. V.4
  * **Dependencias:** T-COM-03, T-COM-04
  * **Hecho cuando:** `TechnicianController` incorpora `getComments(Request $request)` y `addComment(Request $request)` bajo autenticación de técnico de ruta, permitiendo consultar el hilo íntegro (públicos y notas internas), publicar notas clasificadas mediante el selector de privacidad `is_internal` (por defecto `true`) y adjuntar fotografías in situ con validación binaria, respondiendo `200 OK` y `201 Created`.

- [x] **T-COM-07: Implementar endpoints de Coordinador en `CoordinatorController.php`**
  * **Requisitos:** RF-01.2, RF-02.3, RF-03.3, RF-06.3, Constitución Art. III
  * **Dependencias:** T-COM-03, T-COM-04
  * **Hecho cuando:** `CoordinatorController` implementa `getComments(Request $request)` y refactoriza su método `addComment(Request $request)` conectándolo con `IncidentCommentService`, soportando inspección total del diálogo, publicación de notas internas o públicas, soporte `multipart/form-data` y auditoría inmutable en `audit_log` con acción `INCIDENT_COMMENT_ADDED`.

- [x] **T-COM-08: Registrar y blindar rutas seguras en `AppRouter.php`**
  * **Requisitos:** RF-01, RF-02, RNF-01
  * **Dependencias:** T-COM-05, T-COM-06, T-COM-07
  * **Hecho cuando:** Las rutas `GET/POST /api/location/incidents/{id}/comments` (y alias `/api/incidents/{ticket_code}/comments`), `GET/POST /api/technician/incidents/{id}/comments` y `GET/POST /api/coordinator/incidents/{id}/comments` quedan registradas bajo los middlewares RBAC correspondientes (`$siteAuth`, `$technicianAuth`, `$coordinatorAuth`), y los tests unitarios de enrutamiento existentes pasan al 100% en verde.

---

## Fase 3: Componente Modal Frontend Vanilla/Vue 3 ES Modules

- [ ] **T-COM-09: Scaffolding y maquetación de `IncidentCommentThreadModal.js`**
  * **Requisitos:** RF-01.2, RF-01.4, RNF-03, RNF-04, docs/design.md
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existe en `public/assets/js/components/IncidentCommentThreadModal.js` el componente Vue 3 ESM con props `isOpen`, `incidentId` (o `ticketCode`) y `role`, emitiendo `close` y `comment-added`, maquetado con cabecera contextual fija (ticket code en monospace, máquina, sede, insignia de estado), cuerpo central con scroll vertical independiente y pie fijo conforme a los tokens de diseño Docker.

- [ ] **T-COM-10: Visor cronológico de mensajes, bocadillos diferenciados y carga de históricos previos**
  * **Requisitos:** RF-01.2, RF-01.3, RF-02.4, RF-04.4, RNF-02
  * **Dependencias:** T-COM-09
  * **Hecho cuando:** El modal presenta los mensajes cronológicamente: (1) comentarios públicos en bocadillos grises neutros (o azul suave para mensajes propios); (2) notas internas confidenciales con fondo ámbar `#fef9c3`, borde `#fde047`, distintivo con icono de candado SVG y etiqueta `🔒 Nota Interna de Taller (Confidencial)`; (3) miniaturas fotográficas con visor modal ampliable; (4) auto-scroll al mensaje más reciente al cargar; y (5) botón superior *"Cargar mensajes anteriores"* que recupera bloques previos preservando el punto de lectura visual sin saltos.

- [ ] **T-COM-11: Formulario reactivo de redacción, selector de privacidad por defecto y adjuntos fotográficos**
  * **Requisitos:** RF-03.1, RF-03.2, RF-03.3, RF-04.1, RF-04.5
  * **Dependencias:** T-COM-10
  * **Hecho cuando:** El formulario en el pie del modal incluye: (1) selector reactivo de privacidad para técnicos y coordinadores con la opción `"Nota Interna de Taller"` **preseleccionada por defecto** (y completamente ausente para responsables de sede); (2) área de texto con contador reactivo de caracteres restantes (5 a 1.000), bloqueando el botón de envío si el texto es inferior a 5 caracteres; (3) selector de fotografía con previsualización en miniatura y botón para retirarla antes del envío; y (4) indicador visual de carga (spinner) deshabilitando el botón durante la subida.

- [ ] **T-COM-12: Modo de sellado de solo lectura y guardián de formulario sucio (*Dirty State Guard*)**
  * **Requisitos:** RF-05.3, RF-07.1, RNF-06
  * **Dependencias:** T-COM-11
  * **Hecho cuando:** (1) Si el ticket se encuentra en estado cerrado o cancelado (`is_sealed == true`), el pie del modal oculta el formulario y muestra el aviso de auditoría *"Expediente archivado: conversación sellada por auditoría"*; (2) al pulsar la tecla `Escape` o hacer clic en el fondo sombreado exterior mientras hay texto o foto en edición, el modal solicita confirmación explícita (*"¿Descartar mensaje en redacción?"*) antes de cerrar; y (3) ante errores de red móvil (HTTP 4xx/5xx), retiene íntegros el texto redactado y la fotografía para permitir reintentos manuales inmediatos.

---

## Fase 4: Integración en Vistas Operativas y Capa de API

- [ ] **T-COM-13: Extender métodos cliente en `public/assets/js/api.js`**
  * **Requisitos:** RF-01, RNF-01
  * **Dependencias:** T-COM-08
  * **Hecho cuando:** `public/assets/js/api.js` implementa métodos normalizados tipados: (1) `api.incidents.getComments(ticketCode, { limit, beforeId })` y `api.incidents.addComment(ticketCode, formDataOrJson)`; (2) `api.technician.getComments(incidentId, { limit, beforeId })` y `api.technician.addComment(incidentId, formData)`; (3) `api.coordinator.getComments(incidentId, { limit, beforeId })` y `api.coordinator.addComment(incidentId, formDataOrJson)`.

- [ ] **T-COM-14: Integrar insignia reactiva y modal en `MachineCard.js` y `LocationPortalView.js`**
  * **Requisitos:** RF-01.1, RF-02.1
  * **Dependencias:** T-COM-12, T-COM-13
  * **Hecho cuando:** (1) `MachineCard.js` renderiza una insignia interactiva con icono de diálogo y contador numérico contabilizando **únicamente comentarios públicos** (`public_comments_count`); (2) al pulsar la insignia o el botón de conversación, emite el evento para abrir `IncidentCommentThreadModal` con rol `SITE_MANAGER`; y (3) al emitirse un nuevo comentario, `LocationPortalView.js` recarga el estado de las máquinas actualizando reactivamente el contador.

- [ ] **T-COM-15: Integrar insignia reactiva y modal en tarjetas de parada en `TechnicianRouteView.js`**
  * **Requisitos:** RF-01.1, RF-02.3
  * **Dependencias:** T-COM-12, T-COM-13
  * **Hecho cuando:** (1) Cada tarjeta de parada de intervención en la vista móvil "Mi Ruta" incorpora una insignia interactiva con icono de conversación y contador numérico con la totalidad de mensajes (`comments_count`); (2) al pulsar, abre `IncidentCommentThreadModal` con rol `TECHNICIAN`; y (3) al publicar un mensaje, el contador de la parada se incrementa de forma inmediata en la interfaz móvil.

- [ ] **T-COM-16: Integrar disparador directo al hilo de conversación en `CoordinatorDashboardView.js`**
  * **Requisitos:** RF-01.1, RF-02.3
  * **Dependencias:** T-COM-12, T-COM-13
  * **Hecho cuando:** En la tabla de triaje del coordinador y en `CoordinatorIncidentDetailModal`, cada avería cuenta con un disparador interactivo con el recuento total de mensajes que abre `IncidentCommentThreadModal` con rol `COORDINATOR`, permitiendo participar con notas internas o públicas y manteniendo sincronizados los contadores.

---

## Fase 5: Pruebas Integrales, Blindaje Constitucional y Regresión Global

- [ ] **T-COM-17: Implementar pruebas unitarias reactivas frontend ESM `IncidentCommentThreadModalTest.mjs`**
  * **Requisitos:** RF-01.2, RF-02.4, RF-03.1, RF-03.3, RF-05.3, RNF-04, RNF-06
  * **Dependencias:** T-COM-12
  * **Hecho cuando:** La ejecución de `node tests/unit/IncidentCommentThreadModalTest.mjs` pasa al 100% en verde evaluando: (1) renderizado de bocadillos ámbar `#fef9c3` con candado y etiqueta para notas internas; (2) selector preseleccionado en `'INTERNAL'` para técnicos y ausente para sede; (3) reactividad del contador (deshabilitado con 4 caracteres, habilitado con 5 a 1.000); (4) dirty form guard interceptando pulsaciones de `Escape`; y (5) renderizado del aviso de auditoría en modo sellado de solo lectura.

- [ ] **T-COM-18: Implementar pruebas de integración HTTP contra MariaDB real (`LocationCommentsApiTest.php` y `TechnicianCommentsApiTest.php`)**
  * **Requisitos:** RF-01, RF-02, RF-03, RF-04, RF-05, RNF-01, Constitución Art. V.4, Art. V.5
  * **Dependencias:** T-COM-08, T-COM-13
  * **Hecho cuando:** La ejecución de `php tests/integration/LocationCommentsApiTest.php` y `php tests/integration/TechnicianCommentsApiTest.php` pasa al 100% en verde evaluando: (1) Sede: filtrado en base de datos de notas internas (cero fugas en payload), enmascaramiento oficial de técnicos (`"Servicio Técnico Oficial (Operador #XX)"`), y subida multipart con foto; (2) Técnico: publicación con selector de nota interna persistiendo `is_internal = 1`, visibilidad de nombres reales de compañeros y fotos adjuntas.

- [ ] **T-COM-19: Implementar pruebas de integración de Coordinador y auditoría (`CoordinatorCommentsApiTest.php`)**
  * **Requisitos:** RF-02.3, RF-06.3, Constitución Art. III
  * **Dependencias:** T-COM-08
  * **Hecho cuando:** La ejecución de `php tests/integration/CoordinatorCommentsApiTest.php` pasa al 100% en verde comprobando: publicación de comentarios y notas internas de coordinación, e inserción inmutable de eventos `INCIDENT_COMMENT_ADDED` en la tabla `audit_log` con el identificador del coordinador, código del ticket y visibilidad correspondiente.

- [ ] **T-COM-20: Implementar pruebas de blindaje constitucional (`IncidentCommentsConstitutionalTest.php`) y verificación de regresión global**
  * **Requisitos:** Artículos I al VII de constitution.md, RNF-01 a RNF-06
  * **Dependencias:** T-COM-17, T-COM-18, T-COM-19
  * **Hecho cuando:**
    1. `php tests/integration/IncidentCommentsConstitutionalTest.php` pasa al 100% en verde certificando:
       * **Art. III:** Cero métodos `deleteComment` o `updateComment` en el agregador/repositorio (solo adición).
       * **Art. V.4:** Cero fugas de notas internas o números telefónicos personales de técnicos hacia el cliente.
       * **Art. V.5:** Rechazo estricto con HTTP 422 ante archivos con cabecera binaria falsa (*magic bytes*) o ficheros $> 5\text{ MB}$ sin dejar residuos en `public/uploads/`.
    2. La ejecución de `php tests/run_all.php` finaliza con **100% verde (0 fallos, 0 errores)** en la totalidad de suites del sistema, con base de datos restablecida.
