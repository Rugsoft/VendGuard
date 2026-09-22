# PLAN TÉCNICO DE IMPLEMENTACIÓN · MVP
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Documento:** `specs/technical/plan.md`  
**Estatus:** Plan Maestro de Ingeniería (Previo a Implementación)  
**Metodología:** SDD (Specification-Driven Development)  
**Documentos de Referencia:** `constitution.md`, `AGENTS.md`, `docs/design.md`, `specs/functional/mvp_functional_spec.md`, `specs/technical/database_schema.md`, `specs/technical/api_contracts.md`  

---

## 1. Estructura de Módulos y Ficheros

Siguiendo los principios de **Clean Architecture**, **Dogma Vanilla** (cero dependencias pesadas de terceros) y la **separación estricta de responsabilidades**:

```text
gestor-incidencias-vending/
├── constitution.md                     # Ley Suprema del Repositorio
├── AGENTS.md                           # Reglas de desarrollo y directrices
├── docs/
│   ├── manual_0_analisis_problema.md   # Análisis funcional base
│   └── design.md                       # Tokens de diseño visual Docker
├── specs/
│   ├── functional/
│   │   └── mvp_functional_spec.md      # Especificación funcional formal (EARS)
│   └── technical/
│       ├── database_schema.md          # Esquema relacional MariaDB y DDL
│       ├── api_contracts.md            # Contratos REST JSON de la API
│       └── plan.md                     # Este Plan Maestro de Ingeniería
├── public/                             # Raíz web pública (DocumentRoot de Apache/Nginx)
│   ├── index.php                       # Front Controller único del Backend
│   ├── index.html                      # Contenedor SPA del Frontend Vue.js
│   ├── uploads/                        # Almacenamiento seguro de imágenes (sin ejecución PHP)
│   │   └── .htaccess                   # Bloqueo de ejecución de scripts en uploads
│   └── assets/
│       ├── css/
│       │   ├── design-tokens.css       # Variables CSS del sistema Docker (design.md)
│       │   └── main.css                # Estilos base, reset y utilidades
│       └── js/
│           ├── app.js                  # Inicializador de la aplicación Vue.js 3
│           ├── store.js                # Estado reactivo global ligero (Vue reactive/ref)
│           ├── api.js                  # Cliente HTTP fetch nativo para API REST
│           ├── components/             # Componentes modulares Vue
│           │   ├── AppNavbar.js        # Barra superior de navegación y perfiles
│           │   ├── MachineCard.js       # Tarjeta de máquina (4px/8px rounding)
│           │   ├── IncidentBadge.js    # Badges de estado y severidad
│           │   ├── IncidentTimeline.js # Historial y comentarios de avería
│           │   ├── ModalDialog.js      # Modal accesible para acciones y alertas
│           │   └── ImagePreview.js     # Visor y selector de fotos (máx 5MB)
│           └── views/                  # Vistas principales del sistema
│               ├── LocationPortalView.js   # Portal del Responsable (Código de Sede)
│               ├── CoordinatorDashboardView.js # Panel de Triaje y Supervisión
│               └── TechnicianRouteView.js      # Vista móvil "Mi Ruta" para técnicos
├── src/                                # Código fuente Backend (PHP 8.2+ Puro)
│   ├── Core/                           # Capa de Dominio (Pure Business Logic)
│   │   ├── Domain/
│   │   │   ├── Model/                  # Entidades de Dominio
│   │   │   │   ├── Incident.php
│   │   │   │   ├── Machine.php
│   │   │   │   ├── Location.php
│   │   │   │   └── User.php
│   │   │   ├── ValueObject/            # Objetos de Valor
│   │   │   │   ├── TicketCode.php
│   │   │   │   ├── UrgencyLevel.php
│   │   │   │   └── IncidentStatus.php
│   │   │   └── Exception/              # Excepciones de Dominio
│   │   │       ├── DuplicateIncidentException.php
│   │   │       ├── InvalidTransitionException.php
│   │   │       ├── WarrantyExpiredException.php
│   │   │       └── ChronicIncidentException.php
│   │   └── Service/                    # Servicios de Dominio
│   │       ├── UrgencyCalculator.php   # Algoritmo de cálculo de severidad
│   │       └── IncidentStateMachine.php# Guardián de transiciones válidas
│   ├── Application/                    # Capa de Casos de Uso (Orquestación)
│   │   ├── Service/
│   │   │   ├── AuthService.php         # Login interno y login por código de sede
│   │   │   ├── IncidentApplicationService.php # Reporte, asignación y resolución
│   │   │   ├── MachineApplicationService.php  # Catálogo e historial de máquinas
│   │   │   └── SlaMonitorService.php   # Evaluación de tiempos de SLA 24/7
│   │   └── DTO/                        # Data Transfer Objects
│   │       ├── CreateIncidentDTO.php
│   │       ├── AssignTechnicianDTO.php
│   │       └── ResolveIncidentDTO.php
│   ├── Infrastructure/                 # Capa de Infraestructura (Adaptadores y Persistencia)
│   │   ├── Database/
│   │   │   ├── ConnectionFactory.php   # Conexión PDO a MariaDB segura (Prepared Statements)
│   │   │   └── MigrationRunner.php     # Ejecutor del script DDL
│   │   ├── Repository/                 # Repositorios PDO
│   │   │   ├── PdoIncidentRepository.php
│   │   │   ├── PdoMachineRepository.php
│   │   │   ├── PdoLocationRepository.php
│   │   │   └── PdoUserRepository.php
│   │   └── Storage/
│   │       └── LocalFileUploader.php   # Validador de cabeceras MIME y guardado de fotos
│   └── Presentation/                   # Capa de Presentación HTTP (REST API)
│       ├── Routing/
│       │   ├── Router.php              # Router regex frontal ligero
│       │   └── Route.php
│       ├── Http/
│       │   ├── Request.php             # Envoltorio de variables $_SERVER, $_GET, $_POST, input JSON
│       │   ├── Response.php            # Emisor de cabeceras y JSON envelopes
│       │   └── Middleware/             # Filtros de petición
│       │       ├── CorsMiddleware.php
│       │       ├── InternalAuthMiddleware.php
│       │       └── SiteAuthMiddleware.php
│       └── Controller/                 # Controladores REST
│           ├── AuthController.php
│           ├── LocationPortalController.php
│           ├── CoordinatorController.php
│           ├── TechnicianController.php
│           └── CronController.php
└── tests/                              # Batería de Pruebas Automatizadas
    ├── bootstrap.php                   # Autoloading y configuración de BD de test
    ├── Unit/                           # Pruebas Unitarias de Lógica Pura
    │   ├── UrgencyCalculatorTest.php
    │   ├── IncidentStateMachineTest.php
    │   └── ResolutionValidatorTest.php
    ├── Integration/                    # Pruebas de Integración con MariaDB
    │   ├── DuplicateIncidentConstraintTest.php
    │   ├── PdoIncidentRepositoryTest.php
    │   └── SoftDeleteIntegrityTest.php
    └── Manual/                         # Guiones de Verificación Manual E2E
        └── e2e_verification_guide.md
```

---

## 2. Mapeo del Modelo de Datos y Contratos API

La correspondencia entre las tablas de **MariaDB** (`database_schema.md`) y los endpoints REST (`api_contracts.md`) se estructura de forma unívoca:

| Entidad / Tabla | Repositorio PDO | Controlador REST | Endpoint API | Método HTTP | Códigos HTTP |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `locations` | `PdoLocationRepository` | `AuthController` | `/api/auth/site-login` | `POST` | `200`, `401` |
| `machines` | `PdoMachineRepository` | `LocationPortalController` | `/api/locations/{code}/machines` | `GET` | `200`, `401`, `404` |
| `incidents` | `PdoIncidentRepository` | `LocationPortalController` | `/api/incidents` | `POST` | `201`, `400`, `409`, `422` |
| `incident_comments` | `PdoIncidentRepository` | `LocationPortalController` | `/api/incidents/{code}/comments` | `POST` | `201`, `404`, `422` |
| `incidents` | `PdoIncidentRepository` | `LocationPortalController` | `/api/incidents/{code}/reopen` | `POST` | `200`, `404`, `422` |
| `users` | `PdoUserRepository` | `AuthController` | `/api/auth/login` | `POST` | `200`, `401` |
| `incidents` (Global) | `PdoIncidentRepository` | `CoordinatorController` | `/api/coordinator/incidents` | `GET` | `200`, `401`, `403` |
| `incidents` (Asignar) | `PdoIncidentRepository` | `CoordinatorController` | `/api/coordinator/incidents/{id}/assign` | `PATCH` | `200`, `400`, `403`, `404` |
| `incidents` (Descarte) | `PdoIncidentRepository` | `CoordinatorController` | `/api/coordinator/incidents/{id}/cancel` | `PATCH` | `200`, `400`, `403`, `404` |
| `incidents` (Ruta) | `PdoIncidentRepository` | `TechnicianController` | `/api/technician/my-route` | `GET` | `200`, `401`, `403` |
| `incidents` (Iniciar) | `PdoIncidentRepository` | `TechnicianController` | `/api/technician/incidents/{id}/start` | `PATCH` | `200`, `403`, `404`, `409` |
| `incidents` (Pausar) | `PdoIncidentRepository` | `TechnicianController` | `/api/technician/incidents/{id}/pause` | `PATCH` | `200`, `400`, `403`, `404` |
| `incidents` (Resolver)| `PdoIncidentRepository` | `TechnicianController` | `/api/technician/incidents/{id}/resolve` | `POST` | `200`, `403`, `404`, `422` |
| `incidents` (Cron) | `PdoIncidentRepository` | `CronController` | `/api/cron/auto-close` | `POST` | `200`, `401` |

---

## 3. Algoritmos Clave en Pseudocódigo y Máquinas de Estado

### 3.1 Algoritmo de Cálculo Automático de Urgencia (`UrgencyCalculator`)

```text
ALGORITMO calculateUrgency(machineType, category):
    SI category == "TEMPERATURE_COLD":
        SI machineType == "PERISHABLE_FOOD":
            RETORNAR UrgencyLevel.CRITICAL   // Mandato Sanitario Art. II
        SINO:
            RETORNAR UrgencyLevel.MEDIUM     // Refrescos tibios o café
    FIN SI

    SI category == "PAYMENT_SYSTEM":
        RETORNAR UrgencyLevel.HIGH           // Bloqueo total de venta

    SI category == "PRODUCT_JAM":
        RETORNAR UrgencyLevel.MEDIUM         // Afecta solo a un carril

    SI category == "ELECTRICAL_OFF":
        SI machineType == "PERISHABLE_FOOD":
            RETORNAR UrgencyLevel.CRITICAL   // Máquina apagada = pérdida inminente de frío
        SINO:
            RETORNAR UrgencyLevel.HIGH       // Máquina apagada sin venta
    FIN SI

    SI category == "COSMETIC_LIGHTING":
        RETORNAR UrgencyLevel.LOW

    SI category == "OTHER":
        RETORNAR UrgencyLevel.MEDIUM         // Valor prudencial por defecto

    RETORNAR UrgencyLevel.MEDIUM
FIN ALGORITMO
```

### 3.2 Algoritmo de Prevención Estricta de Duplicados (`ReportIncident`)

```text
ALGORITMO reportIncident(locationId, machineId, category, description, reporterData, photoFile):
    INICIAR TRANSACCIÓN PDO

    // 1. Verificar si la máquina ya tiene una incidencia activa o en garantía
    activeTicket = PdoIncidentRepository.findActiveOrResolvedByMachineId(machineId)

    SI activeTicket != NULL:
        CANCELAR TRANSACCIÓN
        SI activeTicket.status == "RESOLVED":
            LANZAR DuplicateIncidentException(
                "MACHINE_IN_WARRANTY", 
                "Esta máquina fue reparada recientemente (Ticket #" + activeTicket.ticketCode + "). Si el fallo persiste, pulsa en 'Reabrir incidencia'."
            )
        SINO:
            LANZAR DuplicateIncidentException(
                "MACHINE_HAS_ACTIVE_INCIDENT", 
                "Esta máquina ya cuenta con un aviso activo (Ticket #" + activeTicket.ticketCode + ") en estado " + activeTicket.status + "."
            )
    FIN SI

    // 2. Procesar imagen opcional con validación estricta
    photoPath = NULL
    SI photoFile != NULL:
        validarFormatoYTamano(photoFile, maxMB = 5, formatos = ["image/jpeg", "image/png", "image/webp"])
        photoPath = LocalFileUploader.save(photoFile)
    FIN SI

    // 3. Determinar urgencia e insertar
    machine = PdoMachineRepository.findById(machineId)
    urgency = UrgencyCalculator.calculate(machine.type, category)
    ticketCode = generarCodigoTicketUnico() // Ej: INC-2026-XXXX

    nuevoId = PdoIncidentRepository.insert({
        ticketCode: ticketCode,
        machineId: machineId,
        locationId: locationId,
        category: category,
        description: description,
        urgency: urgency,
        status: "REGISTERED",
        photoPath: photoPath,
        reporterName: reporterData.name,
        reporterPhone: reporterData.phone
    })

    // 4. Registrar auditoría inmutable
    PdoIncidentRepository.insertHistory(nuevoId, NULL, NULL, "REGISTERED", "Aviso creado por el responsable de sede")

    CONFIRMAR TRANSACCIÓN
    RETORNAR nuevoId
FIN ALGORITMO
```

### 3.3 Algoritmo de Reapertura, Desasignación y Control de Reincidencias

```text
ALGORITMO reopenIncident(ticketCode, reasonText):
    INICIAR TRANSACCIÓN PDO

    incident = PdoIncidentRepository.findByTicketCodeForUpdate(ticketCode)
    
    SI incident.status != "RESOLVED":
        CANCELAR TRANSACCIÓN
        LANZAR InvalidTransitionException("Solo pueden reabrirse incidencias en estado RESUELTA.")
    FIN SI

    // Validar ventana temporal de 48 horas exactas
    horasTranscurridas = calcularHorasDiferencia(incident.resolvedAt, AHORA_UTC())
    SI horasTranscurridas > 48.0:
        CANCELAR TRANSACCIÓN
        LANZAR WarrantyExpiredException("REOPEN_WINDOW_EXPIRED", "Han transcurrido más de 48 horas desde la resolución. Debe crearse un nuevo ticket.")
    FIN SI

    // Validar límite de 2 reaperturas sucesivas
    reopenCount = PdoIncidentRepository.countReopenEvents(incident.id)
    SI reopenCount >= 2:
        CANCELAR TRANSACCIÓN
        PdoIncidentRepository.markAsChronic(incident.id)
        LANZAR ChronicIncidentException("CHRONIC_INCIDENT_LIMIT", "Se ha superado el máximo de 2 reaperturas. Expediente marcado como Avería Crónica. Contacte con coordinación.")
    FIN SI

    // Transicionar estado y desasignar técnico (Cumplimiento Art. V.3)
    PdoIncidentRepository.update(incident.id, {
        status: "REABIERTA",
        assignedTechnicianId: NULL,     // Desasignación obligatoria
        reopenedAt: AHORA_UTC(),
        reopenReason: reasonText
    })

    PdoIncidentRepository.insertHistory(
        incident.id, 
        NULL, 
        "RESOLVED", 
        "REABIERTA", 
        "Reapertura solicitada por cliente (" + (reopenCount + 1) + "ª reincidencia). Motivo: " + reasonText
    )

    CONFIRMAR TRANSACCIÓN
    RETORNAR incident
FIN ALGORITMO
```

### 3.4 Algoritmo de Evaluación de SLA 24/7 en Segundo Plano

```text
ALGORITMO checkCriticalSlaBreaches():
    // Consulta incidencias críticas en espera de técnico
    criticalPending = PdoIncidentRepository.findCriticalRegistered()

    alertaList = []
    AHORA = AHORA_UTC()

    PARA CADA inc EN criticalPending:
        minutosEspera = calcularMinutosDiferencia(inc.createdAt, AHORA) // 24/7 continuo
        SI minutosEspera > 60:
            alertaList.push({
                incidentId: inc.id,
                ticketCode: inc.ticketCode,
                minutesElapsed: minutosEspera,
                isBreached: VERDADERO
            })
        FIN SI
    FIN PARA

    RETORNAR alertaList
FIN ALGORITMO
```

---

## 4. Arquitectura de Componentes Frontend y Flujo de Eventos

La interfaz de usuario se implementa como una aplicación cliente moderna con **Vue.js 3 (Composition API)** organizada en módulos ES (`type="module"`), consumiendo los endpoints JSON nativos.

### 4.1 Árbol de Vistas y Componentes

```text
AppRoot (index.html + app.js)
├── AppNavbar
│   ├── SiteCodeBadge (Si está autenticado en sede)
│   ├── UserProfileMenu (Si es Coordinador/Técnico)
│   └── LogoutButton
│
├── View: LocationPortalView (Responsable de Sede)
│   ├── LocationHeader (Nombre de edificio y dirección)
│   ├── TabNavigation ("Máquinas del Centro" | "Avisos en Curso")
│   ├── MachineCardGrid
│   │   └── MachineCard (Indicador visual de máquina libre o averiada)
│   ├── IncidentReportModal (Formulario guiado con detección de duplicados)
│   │   ├── CategorySelector
│   │   ├── ImagePreview (Validador de 5MB)
│   │   └── ActiveIncidentWarning (Si ya tiene ticket activo)
│   └── ReopenTicketModal (Activo solo en tickets RESUELTOS < 48h)
│
├── View: CoordinatorDashboardView (Panel de Coordinador)
│   ├── SlaAlertBanner (Alerta roja parpadeante de incidencias críticas > 60m)
│   ├── MetricsSummaryBar (Total abiertas, críticas de frío, pendientes de repuesto)
│   ├── IncidentFilterBar (Filtros combinados por estado, severidad y sede)
│   ├── IncidentTable
│   │   └── IncidentRow (Fila con badges de urgencia y selector rápido de técnico)
│   ├── AssignTechnicianModal (Selector de técnico + Urgency Override con justificación)
│   └── CancelIncidentModal (Exige motivo de descarte obligatorio)
│
└── View: TechnicianRouteView (Vista Móvil "Mi Ruta")
    ├── TechnicianHeader (Nombre del técnico y tareas asignadas hoy)
    ├── RouteCardList (Ordenada con prioridad: Críticas arriba)
    │   └── RouteCard
    │       ├── MachineLocationInfo (Planta, ala y observaciones de acceso)
    │       ├── IncidentBadges
    │       └── ActionButtonGroup:
    │           ├── "Iniciar intervención" (Pasa a EN_CURSO)
    │           ├── "Pausar por repuesto" (Abre modal para nota de pieza)
    │           └── "Resolver y cerrar parte" (Abre modal de resolución estricta)
    ├── PauseModal (Campo de texto de recambio requerido)
    └── ResolveModal (Campos obligatorios: Diagnóstico >= 20 chars Y Acción >= 20 chars)
```

### 4.2 Integración Visual de Tokens (`docs/design.md`)

Los componentes implementan estrictamente las clases y variables CSS extraídas del sistema de diseño Docker:

* `--color-primary`: `#2560ff` (utilizado en botones de acción principal, enlaces y foco).
* `--color-canvas`: `#f9fafb` (fondo de la aplicación).
* `--color-surface-card`: `#ffffff` con borde `1px solid #c8cfda`.
* `--color-text-ink`: `#000000` para títulos principales (`font-family: 'DM Sans', sans-serif`).
* `--color-text-slate`: `#2c333f` para etiquetas y textos de formulario (`font-family: 'Inter', sans-serif`).
* `--radius-interactive`: `4px` (`rounded.xs`) en todos los botones, inputs y selectores.
* `--radius-card`: `8px` (`rounded.sm`) en tarjetas y paneles contenedores.

---

## 5. Decisiones Técnicas Justificadas y Alternativas Descartadas

| Decisión Adoptada | Justificación Técnica | Alternativa Descartada | Motivo del Descarte |
| :--- | :--- | :--- | :--- |
| **PHP 8.2+ Moderno Puro (Vanilla POO)** | Control absoluto del código, cero sobrecarga, arranque instantáneo y aprendizaje de arquitectura limpia aplicable al certificado MF0493_3. | Frameworks pesados (Laravel, Symfony) | Viola el Dogma Vanilla y el Artículo IV de la Constitución; sobredimensiona el proyecto con miles de archivos ajenos. |
| **Índice Único Condicional en MariaDB (`uq_machine_active_ticket`)** | Garantiza la regla de duplicados a nivel atómico de motor relacional mediante una columna virtual. Imposible de burlar por condiciones de carrera. | Validación únicamente en código PHP | Si dos peticiones HTTP entran en el mismo milisegundo, la validación en PHP puede dar falso positivo y crear dos registros duplicados en BD. |
| **Vue.js 3 (Composition API modular)** | Reactividad ágil, fácil aprendizaje viniendo de JS/HTML y soporte nativo para migrar a PWA/Capacitor sin reescribir nada. | React / Angular | Curva de aprendizaje mucho más empinada, mayor sobrecarga de bundling y necesidad de JSX/TypeScript obligatorio. |
| **Cliente HTTP fetch nativo** | Los navegadores modernos soportan `fetch()` nativo y `FormData` para envío asíncrono y multipart sin librerías. | Axios / JQuery | Dependencias externas innecesarias que violan la Constitución. |
| **Sondeo ligero (*Polling* de 60s) en Dashboard** | Resuelve el 100% de la necesidad de actualización de SLA del coordinador sin requerir infraestructura adicional. | WebSockets / Socket.io | Complejidad innecesaria de configuración de servidor demonio para el alcance del MVP (Principio YAGNI). |
| **Soft Delete (`deleted_at`) en todas las tablas maestras** | Conserva íntegra la trazabilidad histórica de averías, auditorías y costes, respetando el Artículo III constitucional. | Borrado físico destructivo (`DELETE`) | Viola la Constitución y destruye las métricas de fiabilidad de las máquinas de vending. |

---

## 6. Estrategia de Pruebas (Testing Strategy)

### 6.1 Pruebas Unitarias (`tests/Unit/`)
Prueban la lógica de negocio pura de forma aislada, sin conexión a base de datos:
1. `UrgencyCalculatorTest.php`:
   * Prueba que `PERISHABLE_FOOD` + `TEMPERATURE_COLD` siempre devuelve `CRITICAL`.
   * Prueba que `COLD_DRINKS` + `TEMPERATURE_COLD` devuelve `MEDIUM`.
   * Prueba que `PAYMENT_SYSTEM` devuelve `HIGH`.
   * Prueba que `OTHER` devuelve `MEDIUM`.
2. `ResolutionValidatorTest.php`:
   * Prueba que el intento de resolver con diagnóstico de 19 caracteres es rechazado.
   * Prueba que acción correctiva con menos de 20 caracteres es rechazada.
   * Prueba que ambos campos con >= 20 caracteres son aceptados con éxito.
3. `IncidentStateMachineTest.php`:
   * Prueba transiciones permitidas: `REGISTRADA` ➔ `ASIGNADA` ➔ `EN_CURSO` ➔ `RESUELTA` ➔ `CERRADA`.
   * Prueba transiciones ilegales: `REGISTRADA` ➔ `RESUELTA` (lanza `InvalidTransitionException`).

### 6.2 Pruebas de Integración con MariaDB (`tests/Integration/`)
Prueban la persistencia real, restricciones y transacciones:
1. `DuplicateIncidentConstraintTest.php`:
   * Inserta un ticket activo para la máquina 1.
   * Intenta insertar un segundo ticket en estado `REGISTRADA` para la misma máquina y verifica que MariaDB lanza error `1062 Duplicate entry`.
   * Cambia el primer ticket a `CLOSED` e intenta insertar de nuevo: verifica que ahora sí lo permite.
2. `SoftDeleteIntegrityTest.php`:
   * Ejecuta borrado lógico de una sede y comprueba que `deleted_at` no es nulo pero la fila sigue existiendo físicamente en la tabla.

### 6.3 Guion de Verificación Manual de Extremo a Extremo (E2E)
1. **Flujo Responsable:** Entrar con `SEDE-BCN-01` ➔ Ver máquinas ➔ Crear ticket en máquina 1 ➔ Intentar crear segundo ticket en máquina 1 (verificar bloqueo) ➔ Añadir comentario al ticket existente.
2. **Flujo Coordinador:** Login con `coordinacion@vendguard.internal` ➔ Ver ticket en bandeja ➔ Asignar a Jordi Técnico ➔ Verificar que el ticket desaparece de "Sin asignar".
3. **Flujo Técnico:** Login con `jordi.ruta@vendguard.internal` en vista móvil ➔ Ver tarea asignada ➔ Pulsar "Iniciar" (estado cambia a `EN_CURSO`) ➔ Intentar cerrar con "ok" (verificar rechazo) ➔ Escribir diagnóstico y acción de más de 20 caracteres ➔ Resolver con éxito.
4. **Flujo Garantía:** Responsable entra en su portal ➔ Ve máquina en `RESUELTA` ➔ Pulsa "Reabrir incidencia" ➔ Verificar que el ticket vuelve a triaje con técnico desasignado.

---

## 7. Matriz de Trazabilidad Estricta (Requirements Traceability Matrix)

| Requisito Funcional / No Funcional | Clases / Ficheros Backend | Vistas / Componentes Frontend | Endpoints REST | Tests Asociados |
| :--- | :--- | :--- | :--- | :--- |
| **RF-01 (Acceso Sede)** | `AuthService.php`, `PdoLocationRepository.php` | `LocationPortalView.js` | `POST /api/auth/site-login` | `SiteAuthTest.php` |
| **RF-02 (Bloqueo Duplicados)** | `IncidentApplicationService.php`, DDL (`uq_machine_active_ticket`) | `MachineCard.js`, `IncidentReportModal.js` | `POST /api/incidents` | `DuplicateIncidentConstraintTest.php` |
| **RF-03 (Urgencia Automática)** | `UrgencyCalculator.php`, `LocalFileUploader.php` | `IncidentReportModal.js` | `POST /api/incidents` | `UrgencyCalculatorTest.php` |
| **RF-04 (Autenticación RBAC)** | `AuthService.php`, `InternalAuthMiddleware.php` | `AppNavbar.js` | `POST /api/auth/login` | `RbacSecurityTest.php` |
| **RF-05 (Triaje y Asignación)** | `IncidentApplicationService.php`, `IncidentStateMachine.php` | `CoordinatorDashboardView.js`, `AssignModal.js`| `PATCH /api/coordinator/incidents/{id}/assign` | `IncidentAssignmentTest.php` |
| **RF-06 (Cancelación Justificada)** | `IncidentApplicationService.php` | `CancelIncidentModal.js` | `PATCH /api/coordinator/incidents/{id}/cancel` | `SoftDeleteIntegrityTest.php` |
| **RF-07 (Intervención Móvil)** | `IncidentApplicationService.php`, `IncidentStateMachine.php` | `TechnicianRouteView.js` | `PATCH /api/technician/incidents/{id}/start`, `/pause` | `IncidentStateMachineTest.php` |
| **RF-08 (Resolución Justificada)** | `IncidentApplicationService.php`, `ResolutionValidator.php` | `ResolveModal.js` | `POST /api/technician/incidents/{id}/resolve` | `ResolutionValidatorTest.php` |
| **RF-09 (Reapertura < 48h)** | `IncidentApplicationService.php`, `WarrantyService.php` | `ReopenTicketModal.js` | `POST /api/incidents/{code}/reopen` | `WarrantyPeriodTest.php` |
| **RF-10 (Auto-cierre tras 48h)** | `CronController.php`, `IncidentApplicationService.php` | N/A (Proceso Batch) | `POST /api/cron/auto-close` | `AutoCloseCronTest.php` |
| **RF-11 (Alerta SLA 24/7)** | `SlaMonitorService.php` | `SlaAlertBanner.js` | `GET /api/coordinator/incidents` | `SlaMonitorTest.php` |
| **RNF-01 (Usabilidad Móvil)** | N/A (Frontend) | `TechnicianRouteView.js`, `design-tokens.css` | N/A | Verificación Manual Móvil |
| **RNF-02 (Reporte < 2 min)** | `LocationPortalController.php` | `IncidentReportModal.js` | `POST /api/incidents` | Verificación Manual UX |
| **RNF-03 (No Hard Delete)** | Todos los Repositorios PDO | N/A (Base de Datos) | N/A | `SoftDeleteIntegrityTest.php` |
| **RNF-04 (Privacidad Informador)** | `LocationPortalController.php` (Filtro DTO) | `IncidentTimeline.js` | `GET /api/locations/{code}/incidents` | `DataSegregationTest.php` |
| **RNF-05 (Seguridad Archivos 5MB)**| `LocalFileUploader.php` | `ImagePreview.js` | `POST /api/incidents` | `FileUploadSecurityTest.php` |
| **RNF-06 (Sistema Diseño Docker)** | N/A | `design-tokens.css`, Todos los componentes | N/A | Auditoría Visual de Tokens |

---

## 8. Garantía Constitucional y Dualidad Lingüística

1. **Cumplimiento Constitucional Blindado:**
   * **Artículo I:** Este plan se genera estrictamente antes de escribir código.
   * **Artículo II:** El algoritmo de urgencia fuerza `CRITICAL` en frío de alimentos perecederos; cualquier reclasificación exige auditoría.
   * **Artículo III:** Cero sentencias `DELETE FROM`. Todas las consultas filtran por `deleted_at IS NULL`.
   * **Artículo IV:** PHP 8.2+ puro sin Composer/vendors externos en tiempo de ejecución. Vue.js 3 estándar por módulos ES.
   * **Artículo V:** Detección de duplicados blindada por BD; 20 caracteres mínimos por campo de cierre; un único técnico asignado; fotos múltiples sin sobreescritura.
   * **Artículo VI:** Cero elementos de Fase 2 (sin telemetría MDB, sin inventario de repuestos de camión, sin pasarelas de pago).
   * **Artículo VII:** Plan inmutable sin aprobación explícita del Product Owner.
2. **Dualidad Lingüística Estricta:**
   * **Inglés:** Clases (`IncidentApplicationService`), métodos (`calculateUrgency`), propiedades (`assignedTechnicianId`), endpoints (`/api/incidents`), tablas SQL (`machines`) y commits Git (`feat: implement urgency calculator`).
   * **Castellano:** Documentación (`specs/`), comentarios de bloque explicativos en el código, mensajes de error y respuestas de cara al usuario.
