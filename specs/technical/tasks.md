# PLAN DE TAREAS DE IMPLEMENTACIÓN · MVP (TASKS.MD)
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Documento:** `specs/technical/tasks.md`  
**Metodología:** SDD (Specification-Driven Development)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar sus predecesoras.  

---

## Fase 1: Infraestructura de Base de Datos y Entorno

- [x] **T-01: Inicializar estructura de carpetas y servidor local**
  * **Requisitos:** `RNF-06`
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existen los directorios `public/`, `src/Core/`, `src/Application/`, `src/Infrastructure/`, `src/Presentation/`, `tests/` y el servidor integrado de PHP responde con código HTTP 200 en `http://localhost:8000/`.

- [x] **T-02: Creación y ejecución de la migración DDL en MariaDB**
  * **Requisitos:** `RNF-03`, `RF-02`
  * **Dependencias:** T-01
  * **Hecho cuando:** El script DDL de `database_schema.md` se ejecuta en la base de datos `vendguard_db` creando las 6 tablas (`locations`, `machines`, `users`, `incidents`, `incident_history`, `incident_comments`) y la columna virtual `is_active_ticket` con su índice único `uq_machine_active_ticket`.

- [x] **T-03: Implementar la factoría de conexión PDO (`ConnectionFactory.php`)**
  * **Requisitos:** `RNF-03`
  * **Dependencias:** T-02
  * **Hecho cuando:** `ConnectionFactory::getConnection()` devuelve una instancia de `PDO` en modo `ERRMODE_EXCEPTION`, con codificación UTF-8 `utf8mb4` y emulación de prepares desactivada (`ATTR_EMULATE_PREPARES = false`).

- [x] **T-04: Carga de datos semilla (*Seed Data*) para desarrollo y pruebas**
  * **Requisitos:** `RF-01`, `RF-04`
  * **Dependencias:** T-03
  * **Hecho cuando:** La base de datos contiene al menos 2 sedes (`SEDE-BCN-01`, `SEDE-BCN-02`), 3 máquinas (incluyendo una de `PERISHABLE_FOOD`) y los 2 usuarios de prueba (`coordinacion@vendguard.internal` y `jordi.ruta@vendguard.internal`) con contraseñas cifradas en Bcrypt.

---

## Fase 2: Lógica de Dominio y Pruebas Unitarias (TDD)

- [x] **T-05: Implementar Enums y Value Objects de Dominio**
  * **Requisitos:** `RF-03`, `RF-07`, `RF-08`
  * **Dependencias:** T-01
  * **Hecho cuando:** Existen los ficheros tipados `UrgencyLevel.php` (`LOW`, `MEDIUM`, `HIGH`, `CRITICAL`), `IncidentStatus.php` (8 estados) y `MachineType.php` con métodos de validación inmutables.

- [x] **T-06: Implementar el calculador de urgencias (`UrgencyCalculator.php`)**
  * **Requisitos:** `RF-03` (EARS 3.2, 3.3, 3.4, 3.5, 3.6, 3.7)
  * **Dependencias:** T-05
  * **Hecho cuando:** `UrgencyCalculator::calculate('PERISHABLE_FOOD', 'TEMPERATURE_COLD')` devuelve `CRITICAL`; en `COLD_DRINKS` devuelve `MEDIUM`; en `PAYMENT_SYSTEM` devuelve `HIGH`; y en `OTHER` devuelve `MEDIUM`.

- [x] **T-07: Crear suite de pruebas unitarias para `UrgencyCalculatorTest.php`**
  * **Requisitos:** `RF-03`, `RNF-03`
  * **Dependencias:** T-06
  * **Hecho cuando:** La ejecución de `php tests/Unit/UrgencyCalculatorTest.php` valida los 6 casos de prueba de severidad pasando al 100% en verde.

- [x] **T-08: Implementar el validador de cierre de avería (`ResolutionValidator.php`)**
  * **Requisitos:** `RF-08` (EARS 8.1, 8.2)
  * **Dependencias:** T-05
  * **Hecho cuando:** `ResolutionValidator::validate($diagnosis, $action)` arroja error si el diagnóstico o la acción tienen menos de 20 caracteres cada uno, y devuelve verdadero solo si ambos superan el umbral.

- [x] **T-09: Crear suite de pruebas unitarias para `ResolutionValidatorTest.php`**
  * **Requisitos:** `RF-08`
  * **Dependencias:** T-08
  * **Hecho cuando:** La ejecución de `php tests/Unit/ResolutionValidatorTest.php` comprueba el rechazo de textos cortos (19 caracteres, "ok", ".") y la aceptación con >= 20 caracteres.

- [x] **T-10: Implementar la máquina de estados (`IncidentStateMachine.php`)**
  * **Requisitos:** `RF-05`, `RF-07`, `RF-08`, `RF-09`, `RF-10`
  * **Dependencias:** T-05
  * **Hecho cuando:** `IncidentStateMachine::canTransition($from, $to)` permite únicamente las transiciones legales del grafo y lanza `InvalidTransitionException` ante saltos no autorizados (ej: de `REGISTRADA` directo a `RESUELTA`).

---

## Fase 3: Capa de Persistencia y Repositorios PDO

- [x] **T-11: Implementar `PdoLocationRepository.php`**
  * **Requisitos:** `RF-01`
  * **Dependencias:** T-03, T-04
  * **Hecho cuando:** El método `findBySiteCode('SEDE-BCN-01')` recupera la sede activa y devuelve `null` si el código no existe o tiene `deleted_at IS NOT NULL`.

- [x] **T-12: Implementar `PdoMachineRepository.php` con detección de ticket activo**
  * **Requisitos:** `RF-01`, `RF-02`
  * **Dependencias:** T-03, T-04
  * **Hecho cuando:** `findActiveByLocationId($locationId)` devuelve el listado de máquinas indicando para cada una si tiene un aviso activo o en garantía (`ticket_code` y `status`).

- [x] **T-13: Implementar `PdoUserRepository.php` con autenticación segura**
  * **Requisitos:** `RF-04`
  * **Dependencias:** T-03, T-04
  * **Hecho cuando:** `findByEmail($email)` recupera el usuario y permite verificar su contraseña mediante `password_verify()`.

- [x] **T-14: Implementar `PdoIncidentRepository.php` (Creación, Historial y Soft Delete)**
  * **Requisitos:** `RF-02`, `RF-03`, `RF-05`, `RF-06`, `RNF-03`
  * **Dependencias:** T-03, T-05
  * **Hecho cuando:** El método `create()` inserta la incidencia y su primer registro en `incident_history` dentro de una transacción atómica PDO.

- [x] **T-15: Test de integración de la restricción de duplicados (`DuplicateIncidentTest.php`)**
  * **Requisitos:** `RF-02`, Artículo V de la Constitución
  * **Dependencias:** T-14
  * **Hecho cuando:** El test intenta insertar dos incidencias activas consecutivas para la misma máquina y comprueba que la segunda lanza la excepción de duplicado `409 Conflict`.

---

## Fase 4: Enrutamiento y Base de la API REST

- [x] **T-16: Implementar `Request.php` y `Response.php` (HTTP JSON Envelope)**
  * **Requisitos:** `RNF-04`
  * **Dependencias:** T-01
  * **Hecho cuando:** `Response::json($data, 200)` emite la cabecera `Content-Type: application/json` y el cuerpo estandarizado `{ "success": true, "data": ... }`.

- [x] **T-17: Implementar el enrutador frontal (`Router.php`) y Front Controller (`index.php`)**
  * **Requisitos:** Arquitectura API-First
  * **Dependencias:** T-16
  * **Hecho cuando:** El router captura métodos (`GET`, `POST`, `PATCH`), procesa parámetros en URL (ej: `/api/locations/{code}/machines`) y despacha la petición al controlador adecuado.

- [x] **T-18: Implementar `SiteAuthMiddleware.php` y `InternalAuthMiddleware.php`**
  * **Requisitos:** `RF-01`, `RF-04`
  * **Dependencias:** T-16, T-17
  * **Hecho cuando:** Peticiones sin cabecera de autenticación válida son interceptadas y rechazadas con error HTTP 401 Unauthorized.

- [x] **T-19: Implementar el gestor seguro de subida de fotos (`LocalFileUploader.php`)**
  * **Requisitos:** `RNF-05` (EARS 3.9)
  * **Dependencias:** T-16
  * **Hecho cuando:** Valida que el archivo recibido no supere 5 MB, verifica el tipo MIME real (`image/jpeg`, `image/png`, `image/webp`), genera un nombre hash único y lo almacena en `public/uploads/`.

---

## Fase 5: Controladores y Endpoints de la API REST

- [x] **T-20: Endpoint de Login de Sede y Personal (`AuthController.php`)**
  * **Requisitos:** `RF-01`, `RF-04`
  * **Dependencias:** T-11, T-13, T-18
  * **Hecho cuando:** `POST /api/auth/site-login` valida códigos de sede y `POST /api/auth/login` valida credenciales internas emitiendo tokens de sesión.

- [x] **T-21: Endpoint de Consulta de Máquinas de Sede (`LocationPortalController.php`)**
  * **Requisitos:** `RF-01`, `RF-02`
  * **Dependencias:** T-12, T-20
  * **Hecho cuando:** `GET /api/locations/{site_code}/machines` devuelve el JSON del catálogo de la sede con el estado de avería activo de cada máquina.

- [x] **T-22: Endpoint de Creación de Incidencia (`POST /api/incidents`)**
  * **Requisitos:** `RF-02`, `RF-03`, `RNF-02`
  * **Dependencias:** T-06, T-14, T-19, T-21
  * **Hecho cuando:** Recibe datos `multipart/form-data`, calcula urgencia, rechaza duplicados con HTTP 409 y crea la incidencia devolviendo código HTTP 201 Created.

- [x] **T-23: Endpoint para Añadir Comentarios/Evidencias a Ticket Activo**
  * **Requisitos:** `RF-02` (EARS 2.3)
  * **Dependencias:** T-14, T-22
  * **Hecho cuando:** `POST /api/incidents/{ticket_code}/comments` anexa una entrada en `incident_comments` sin sobreescribir la fotografía original de la incidencia.

- [x] **T-24: Endpoint de Reapertura en Ventana de Garantía (`POST /reopen`)**
  * **Requisitos:** `RF-09` (EARS 9.1, 9.2, 9.3)
  * **Dependencias:** T-14
  * **Hecho cuando:** `POST /api/incidents/{ticket_code}/reopen` pasa el estado a `REABIERTA`, pone `assigned_technician_id = NULL`, reinicia el reloj de 48h, rechaza si han pasado >48h (HTTP 422) y bloquea con "Avería Crónica" a la 3ª reincidencia.

- [x] **T-25: Endpoint de Bandeja Global del Coordinador (`CoordinatorController.php`)**
  * **Requisitos:** `RF-05`, `RF-11`
  * **Dependencias:** T-14, T-20
  * **Hecho cuando:** `GET /api/coordinator/incidents` devuelve todas las averías filtrables, calculando los minutos de espera y marcando `sla_breached: true` en críticas con > 60 min.

- [x] **T-26: Endpoint de Asignación y Reclasificación Auditada de Técnico**
  * **Requisitos:** `RF-05` (EARS 5.1, 5.2, 5.3)
  * **Dependencias:** T-14, T-25
  * **Hecho cuando:** `PATCH /api/coordinator/incidents/{id}/assign` asocia un técnico único y exige motivo obligatorio si se modifica el nivel de urgencia, guardándolo en el historial.

- [x] **T-27: Endpoint de Cancelación Lógica de Avisos**
  * **Requisitos:** `RF-06` (EARS 6.1, 6.2, 6.3), `RNF-03`
  * **Dependencias:** T-14, T-25
  * **Hecho cuando:** `PATCH /api/coordinator/incidents/{id}/cancel` exige motivo de descarte y cambia el estado a `CANCELADA` sin borrar la fila de la base de datos.

- [x] **T-28: Endpoints de Operativa de Campo del Técnico (`TechnicianController.php`)**
  * **Requisitos:** `RF-07`
  * **Dependencias:** T-14, T-20
  * **Hecho cuando:** `GET /api/technician/my-route` lista solo las asignadas al técnico, `PATCH /start` transiciona a `EN_CURSO` y `PATCH /pause` a `PENDIENTE_REPUESTO`.

- [x] **T-29: Endpoint de Resolución con Validación Estricta**
  * **Requisitos:** `RF-08`
  * **Dependencias:** T-08, T-28
  * **Hecho cuando:** `POST /api/technician/incidents/{id}/resolve` valida que el diagnóstico tenga >= 20 caracteres Y la acción >= 20 caracteres, pasando a `RESUELTA` e iniciando los 48h.

- [x] **T-30: Endpoint de Auto-Cierre por Proceso Batch (`CronController.php`)**
  * **Requisitos:** `RF-10` (EARS 10.1, 10.2)
  * **Dependencias:** T-14
  * **Hecho cuando:** `POST /api/cron/auto-close` protegido por token archiva a `CERRADA` definitiva todas las incidencias en `RESUELTA` con más de 48 horas de antigüedad.

---

## Fase 6: Frontend - Tokens Visuales, Cliente API y Estado

- [x] **T-31: Implementar variables CSS del Sistema de Diseño Docker (`design-tokens.css`)**
  * **Requisitos:** `RNF-06` (design.md)
  * **Dependencias:** T-01
  * **Hecho cuando:** El archivo define `--color-primary: #2560ff`, `--color-canvas: #f9fafb`, `--color-slate: #2c333f`, bordes de 4px en interactivos y 8px en tarjetas, e importa las fuentes Inter y DM Sans.

- [x] **T-32: Implementar el cliente HTTP nativo (`api.js`) y almacén reactivo (`store.js`)**
  * **Requisitos:** `RNF-01`, Arquitectura Frontend
  * **Dependencias:** T-31
  * **Hecho cuando:** `api.js` gestiona llamadas `fetch()`, adjunta tokens automáticamente y maneja errores; `store.js` expone el estado reactivo del usuario y la sede con Vue 3 `reactive`.

- [x] **T-33: Componentes base de UI (`AppNavbar.js`, `IncidentBadge.js`, `ModalDialog.js`)**
  * **Requisitos:** `RNF-01`, `RNF-06`
  * **Dependencias:** T-31, T-32
  * **Hecho cuando:** Los componentes renderizan badges con colores semánticos, diálogos modales accesibles y barra de navegación con cierre de sesión.

---

## Fase 7: Vistas de Usuario Frontend

- [x] **T-34: Implementar la vista del Portal del Responsable (`LocationPortalView.js`)**
  * **Requisitos:** `RF-01`, `RF-02`, `RNF-02`
  * **Dependencias:** T-21, T-33
  * **Hecho cuando:** El conserje entra con su código de sede, visualiza las máquinas de su edificio en tarjetas de 8px y ve claramente cuáles tienen incidencias abiertas.

- [x] **T-35: Implementar el modal de reporte guiado de avería (`IncidentReportModal.js`)**
  * **Requisitos:** `RF-02`, `RF-03`, `RNF-05`
  * **Dependencias:** T-22, T-34
  * **Hecho cuando:** Permite reportar la avería en < 2 min; si la máquina ya tiene ticket activo, bloquea la creación y habilita el formulario para anexar comentarios/fotos.

- [x] **T-36: Implementar el modal de reapertura en garantía (`ReopenTicketModal.js`)**
  * **Requisitos:** `RF-09`
  * **Dependencias:** T-24, T-34
  * **Hecho cuando:** En máquinas en estado `RESUELTA` muestra el botón "Reabrir incidencia" si no han pasado 48h y envía el motivo de reapertura.

- [x] **T-37: Implementar el Dashboard del Coordinador (`CoordinatorDashboardView.js`)**
  * **Requisitos:** `RF-05`, `RF-06`, `RF-11`
  * **Dependencias:** T-25, T-26, T-27, T-33
  * **Hecho cuando:** Muestra la tabla de averías con filtros, actualiza alertas de SLA > 60m mediante sondeo cada 60s y abre modales de asignación técnica y descarte.

- [x] **T-38: Implementar la vista móvil de campo del Técnico (`TechnicianRouteView.js`)**
  * **Requisitos:** `RF-07`, `RF-08`, `RNF-01`
  * **Dependencias:** T-28, T-29, T-33
  * **Hecho cuando:** La interfaz vertical de smartphone permite al técnico ver su lista de tareas, pulsar "Iniciar intervención", "Pausar por repuesto" y abrir el modal de cierre que exige >= 20 caracteres por campo.

---

## Fase 8: Verificación Global y Auditoría de Cierre

- [x] **T-39: Ejecutar batería completa de pruebas automatizadas**
  * **Requisitos:** `RNF-03`, Criterios de Finalización
  * **Dependencias:** T-07, T-09, T-15
  * **Hecho cuando:** Todas las pruebas unitarias y de integración se ejecutan exitosamente con cero fallos (`0 errors, 0 failures`).

- [x] **T-40: Ejecutar el guion de verificación manual E2E de los 3 perfiles**
  * **Requisitos:** Criterios de Finalización (Definition of Done)
  * **Dependencias:** T-34, T-35, T-36, T-37, T-38
  * **Hecho cuando:** Se completa con éxito el recorrido funcional: reporte por sede ➔ triaje/asignación ➔ intervención y resolución móvil ➔ comprobación de no-duplicidad y reapertura.

- [ ] **T-41: Auditoría Constitucional y Cierre del MVP**
  * **Requisitos:** Artículos I al VII de `constitution.md`
  * **Dependencias:** T-39, T-40
  * **Hecho cuando:** Se comprueba que no existe ningún `DELETE FROM` en el código, cero frameworks pesados, tipado estricto en el 100% de ficheros PHP y cumplimiento de los tokens visuales de Docker.
