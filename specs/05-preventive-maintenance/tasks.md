# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO DE MANTENIMIENTO PREVENTIVO Y CHECKLISTS SANITARIOS (TASKS.MD)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `05-preventive-maintenance`  
**Documento:** `specs/05-preventive-maintenance/tasks.md`  
**Referencia Funcional:** [`specs/functional/preventive_maintenance_spec.md`](../functional/preventive_maintenance_spec.md) (RF-PREV-01 a RF-PREV-08, RNF-01 a RNF-06)  
**Contratos Técnicos y DDL:** [`specs/technical/preventive_maintenance_contracts.md`](../technical/preventive_maintenance_contracts.md)  
**Plan Técnico:** [`specs/05-preventive-maintenance/plan.md`](plan.md)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Esquema de Base de Datos y Excepciones de Dominio

- [x] **T-PREV-01: Migración DDL y extensiones preventivas (`005_preventive_maintenance.sql`, `cloud_init.sql`)**
  * **Requisitos:** RF-PREV-01 a RF-PREV-08, Constitución Art. II, Art. III, Art. V.4
  * **Dependencias:** Ninguna
  * **Hecho cuando:** La ejecución de `php bin/migrate.php` aplica con éxito `005_preventive_maintenance.sql`, creando las tablas `preventive_settings`, `preventive_orders`, `preventive_order_items`, `sanitary_certificates`, ampliando `machines` (semáforo, próximas fechas y pausa estacional), `users` (`operator_code`), `incidents` (`preventive_order_id`) y `audit_log`; `database/cloud_init.sql` queda consolidado con semillas normativas (máximo 15 días en perecederos).

- [x] **T-PREV-02: Implementar Excepciones de Dominio Preventivas**
  * **Requisitos:** RF-PREV-01, RF-PREV-03, RF-PREV-04, RF-PREV-07, RNF-06, Constitución Art. II
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existen en `src/Core/Domain/Exception/` las 8 clases tipadas (`PerishableFrequencyLimitException`, `InvalidTemperatureRangeException`, `ChecklistIncompleteException`, `PreventiveOrderAlreadyAssignedException`, `PreventiveOrderNotInInspectionException`, `ReinspectionTemperatureExceededException`, `CannotIssueNonConformCertificateException` y `SeasonalPauseMissingReasonException`), cada una proveyendo su código de error técnico y mensaje en castellano según contrato.

- [x] **T-PREV-03: Crear suite de pruebas unitarias para excepciones y reglas de dominio (`PreventiveExceptionsTest.php`)**
  * **Requisitos:** RF-PREV-01, RF-PREV-03, RF-PREV-04, RF-PREV-07, RNF-06
  * **Dependencias:** T-PREV-02
  * **Hecho cuando:** La ejecución de `php tests/unit/PreventiveExceptionsTest.php` pasa al 100% en verde evaluando las 8 excepciones, verificando códigos HTTP (`400`, `409`, `422`), códigos de error (`PERISHABLE_FREQUENCY_LIMIT_EXCEEDED`, `INVALID_TEMPERATURE_RANGE`, etc.) y mensajes.

---

## Fase 2: Repositorios PDO y Capa de Persistencia

- [x] **T-PREV-04: Implementar `PreventiveSettingsRepositoryInterface` y `PdoPreventiveSettingsRepository.php`**
  * **Requisitos:** RF-PREV-01 (EARS 1.1, 1.2, 1.3, 1.4, 1.5)
  * **Dependencias:** T-PREV-01, T-PREV-02
  * **Hecho cuando:** `PdoPreventiveSettingsRepository` implementa consulta de frecuencias por tipología (`findByMachineType`), actualización de frecuencias globales (`updateTypeSettings` con validación $\le 15$ días en perecederos), configuración individual por máquina (`updateMachineConfig`) y gestión de pausa estacional (`setSeasonalPause` y `resumeSeasonalPause`).

- [x] **T-PREV-05: Implementar `PreventiveOrderRepositoryInterface` y `PdoPreventiveOrderRepository.php`**
  * **Requisitos:** RF-PREV-02, RF-PREV-04, Constitución Art. III
  * **Dependencias:** T-PREV-01, T-PREV-02
  * **Hecho cuando:** `PdoPreventiveOrderRepository` implementa creación (`create`), búsqueda por ID/código (`findById`, `findByCode`), asignación a técnico (`assignTechnician`), autoasignación in situ (`claimOrderOpportunistically` atómico con bloqueo si ya no está en `PENDING_ASSIGNMENT`), cancelación lógica (`softCancel` fijando `status = 'CANCELLED'` sin borrado físico), listados para coordinación y ruta móvil de técnico.

- [x] **T-PREV-06: Implementar `PreventiveItemRepositoryInterface` y `PdoPreventiveItemRepository.php`**
  * **Requisitos:** RF-PREV-03, RF-PREV-04
  * **Dependencias:** T-PREV-01
  * **Hecho cuando:** `PdoPreventiveItemRepository` implementa inserción por lotes de las comprobaciones de checklist (`saveOrderItems`), vinculación fotográfica de evidencias de fallos y consulta de ítems por orden (`findByOrderId`).

- [x] **T-PREV-07: Implementar `SanitaryCertificateRepositoryInterface` y `PdoSanitaryCertificateRepository.php`**
  * **Requisitos:** RF-PREV-07, RF-PREV-08, Constitución Art. V.4
  * **Dependencias:** T-PREV-01
  * **Hecho cuando:** `PdoSanitaryCertificateRepository` implementa emisión y registro de certificados (`createCertificate`), consulta de certificado vigente por máquina (`findActiveByMachineCode`), suspensión cautelar (`suspendByMachineId` ante averías de frío) y consulta agregada para Certificado Global de Sede.

---

## Fase 3: Servicios de Aplicación y Reglas de Negocio

- [x] **T-PREV-08: Implementar `PreventiveSettingsService.php` y suite unitaria `PreventiveSettingsServiceTest.php`**
  * **Requisitos:** RF-PREV-01, Constitución Art. II
  * **Dependencias:** T-PREV-02, T-PREV-04
  * **Hecho cuando:** `PreventiveSettingsService` valida y bloquea cualquier intento de configurar frecuencias $> 15$ días en perecederos (`PerishableFrequencyLimitException`), gestiona pausas estacionales con justificación obligatoria y registra eventos en `audit_log`, pasando al 100% `php tests/unit/PreventiveSettingsServiceTest.php`.

- [x] **T-PREV-09: Implementar `PreventiveOrderSchedulerService.php` y suite unitaria `PreventiveOrderSchedulerServiceTest.php`**
  * **Requisitos:** RF-PREV-01 (inicio de ciclo en altas), RF-PREV-02 (horizonte 5 días)
  * **Dependencias:** T-PREV-04, T-PREV-05
  * **Hecho cuando:** `PreventiveOrderSchedulerService` genera automáticamente órdenes preventivas para máquinas a $\le 5$ días del vencimiento, omite máquinas en pausa estacional o con orden activa previa, recalcula fechas límite y la suite `php tests/unit/PreventiveOrderSchedulerServiceTest.php` pasa al 100%.

- [x] **T-PREV-10: Implementar `PreventiveChecklistEvaluationService.php` y suite unitaria `PreventiveChecklistEvaluationServiceTest.php`**
  * **Requisitos:** RF-PREV-03, RF-PREV-04, RNF-06, Constitución Art. II
  * **Dependencias:** T-PREV-02, T-PREV-05, T-PREV-06
  * **Hecho cuando:** `PreventiveChecklistEvaluationService` valida el rango físico de temperatura `[-5.0, 25.0]` con 1 decimal exacto, evalúa dictámenes (`CONFORME`, `CONFORME_CON_OBSERVACIONES`, `NO_CONFORME`), sitúa la máquina en estado `QUARANTINE` ante fallos críticos o temperatura $> 4.0\text{ }^\circ\text{C}$ en perecederos, pasando al 100% `php tests/unit/PreventiveChecklistEvaluationServiceTest.php`.

- [x] **T-PREV-11: Implementar `PreventiveCoexistenceBridgeService.php` y suite unitaria `PreventiveCoexistenceBridgeServiceTest.php`**
  * **Requisitos:** RF-PREV-05, RF-PREV-08, Constitución Art. II, Art. V.1, Art. V.2
  * **Dependencias:** T-PREV-10
  * **Hecho cuando:** `PreventiveCoexistenceBridgeService` crea incidencia correctiva vinculada con urgencia `CRITICAL` si no hay ticket previo, o añade apunte en bitácora (`incident_comments`) y eleva a `CRITICAL` si ya existe ticket activo (Art. V.2); exige $\ge 20$ caracteres en diagnóstico y solución para cerrar el correctivo (Art. V.1) y coordina la reapertura de la máquina tras reinspección térmica ($\le 4.0\text{ }^\circ\text{C}$), pasando al 100% `php tests/unit/PreventiveCoexistenceBridgeServiceTest.php`.

- [x] **T-PREV-12: Implementar `SanitaryCertificateService.php` y suite unitaria `SanitaryCertificateServiceTest.php`**
  * **Requisitos:** RF-PREV-07, Constitución Art. V.4
  * **Dependencias:** T-PREV-07
  * **Hecho cuando:** `SanitaryCertificateService` emite certificados sanitarios con `operator_code` (sin DNI ni teléfono privado del técnico), genera la consolidación global de sede (dictamen `CONDICIONADO` si alguna máquina falla), suspende cautelarmente certificados ante averías de frío sobrevenidas y pasa al 100% `php tests/unit/SanitaryCertificateServiceTest.php`.

---

## Fase 4: Controladores REST y Rutas HTTP (API-First)

- [x] **T-PREV-13: Implementar `CoordinatorPreventiveController.php` y registrar rutas en `AppRouter.php`**
  * **Requisitos:** RF-PREV-01, RF-PREV-02, RF-PREV-06
  * **Dependencias:** T-PREV-08, T-PREV-09
  * **Hecho cuando:** Todos los endpoints de coordinación (`/dashboard`, `/orders`, `/generate-due`, `/assign`, `/cancel`, `/settings`, `/machines/{id}/preventive-config`) quedan registrados bajo la protección de `InternalAuthMiddleware(COORDINATOR)` en `AppRouter.php`.

- [x] **T-PREV-14: Implementar `TechnicianPreventiveController.php` y registrar rutas en `AppRouter.php`**
  * **Requisitos:** RF-PREV-02 (Claim), RF-PREV-03, RF-PREV-04, RF-PREV-08
  * **Dependencias:** T-PREV-10, T-PREV-11
  * **Hecho cuando:** Los endpoints del técnico (`/route`, `/claim`, `/checklist`, `/start`, `/complete`, `/reinspect`) quedan registrados bajo `InternalAuthMiddleware(TECHNICIAN)` en `AppRouter.php`, manejando las excepciones de dominio con códigos `200`, `400`, `409` y `422`.

- [x] **T-PREV-15: Implementar `SiteSanitaryController.php` y registrar rutas en `AppRouter.php`**
  * **Requisitos:** RF-PREV-06, RF-PREV-07
  * **Dependencias:** T-PREV-12
  * **Hecho cuando:** Los endpoints para clientes (`/sanitary-status`, `/certificates/machine/{code}`, `/certificates/global`) quedan registrados bajo `SiteAuthMiddleware` en `AppRouter.php`, devolviendo semáforos, certificados individuales A4 y certificados consolidados de sede.

- [x] **T-PREV-16: Extender `QrScanController.php` para modos `SANITARY_QUARANTINE` y `SEASONAL_PAUSE`**
  * **Requisitos:** RF-PREV-04 (EARS 4.2), RF-PREV-01 (EARS 1.5), Constitución Art. II
  * **Dependencias:** T-PREV-10
  * **Hecho cuando:** `GET /api/qr/scan/{code}` sobre una máquina en cuarentena responde HTTP `200` con `status_mode = "SANITARY_QUARANTINE"`, alerta sanitaria roja y bloqueo de reportes/compras; y sobre una máquina en pausa estacional responde `status_mode = "SEASONAL_PAUSE"` con aviso vacacional.

- [x] **T-PREV-17: Crear suites de integración HTTP (`CoordinatorPreventiveApiTest.php`, `TechnicianPreventiveApiTest.php`, `SiteSanitaryApiTest.php`, `QrSanitaryModeApiTest.php`)**
  * **Requisitos:** RF-PREV-01 a RF-PREV-08, RNF-04, RNF-05
  * **Dependencias:** T-PREV-13, T-PREV-14, T-PREV-15, T-PREV-16
  * **Hecho cuando:** La ejecución de las 4 suites de integración PHP pasa al 100% en verde evaluando peticiones HTTP reales, validaciones Bearer, respuestas JSON y registro de eventos en `audit_log`.

---

## Fase 5: Componentes Frontend y Vistas Vanilla Vue 3

- [x] **T-PREV-18: Implementar `CoordinatorPreventiveDashboard.js` y `CoordinatorPreventiveOrdersTab.js`**
  * **Requisitos:** RF-PREV-02, RF-PREV-06
  * **Dependencias:** T-PREV-13
  * **Hecho cuando:** Los componentes Vue 3 ESM renderizan los KPIs de semáforos de parque, alertas de máquinas en cuarentena, listado de órdenes con filtros, botón "Generar Preventivos Inminentes", modal de asignación técnica y cancelación lógica justificada.

- [x] **T-PREV-19: Implementar `CoordinatorPreventiveSettingsModal.js`**
  * **Requisitos:** RF-PREV-01, Constitución Art. II
  * **Dependencias:** T-PREV-13
  * **Hecho cuando:** El componente Vue 3 ESM permite ajustar frecuencias sanitarias bloqueando entradas $> 15$ días en perecederos e incluye formulario para activar/desactivar pausas estacionales con fecha y motivo justificado.

- [x] **T-PREV-20: Implementar `TechnicianPreventiveRouteTab.js` con Visita Oportunista**
  * **Requisitos:** RF-PREV-02 (EARS 2.3)
  * **Dependencias:** T-PREV-14
  * **Hecho cuando:** La vista móvil del técnico agrupa sus inspecciones asignadas y destaca órdenes pendientes de la sede en la que se encuentra trabajando para permitir su autoasignación inmediata con un solo toque.

- [x] **T-PREV-21: Implementar `TechnicianChecklistModal.js` y `TechnicianReinspectionModal.js`**
  * **Requisitos:** RF-PREV-03, RF-PREV-04, RF-PREV-08, RNF-01, RNF-06
  * **Dependencias:** T-PREV-14
  * **Hecho cuando:** El modal táctil permite registrar la temperatura con teclado numérico estricto `[-5.0, 25.0]` y responder los ítems del checklist en menos de 90 segundos; y el modal de reinspección permite registrar la lectura de comprobación tras resolver la avería.

- [x] **T-PREV-22: Implementar `SiteSanitaryStatusTab.js`, `SanitaryCertificateModal.js` y `SiteGlobalCertificateModal.js`**
  * **Requisitos:** RF-PREV-06, RF-PREV-07, RNF-03, Constitución Art. V.4
  * **Dependencias:** T-PREV-15
  * **Hecho cuando:** El portal de sede muestra los semáforos higiénicos, permite visualizar e imprimir en A4 (`@media print`) el certificado oficial individual con `operator_code` (sin DNI privado) y el certificado consolidado de sede con dictamen `CONDICIONADO` ante incidencias.

- [x] **T-PREV-23: Implementar componentes públicos `QrSanitaryQuarantineModal.js` y `QrSeasonalPauseNotice.js` e integrarlos en `QrReportView.js`**
  * **Requisitos:** RF-PREV-01, RF-PREV-04, Constitución Art. II
  * **Dependencias:** T-PREV-16
  * **Hecho cuando:** El escaneo QR ciudadano de una máquina en cuarentena muestra la alerta roja prominente con bloqueo total de reporte y compra; y en máquinas en pausa estacional muestra el aviso informativo vacacional.

- [x] **T-PREV-24: Integración global en `app.js` y tests frontend ESM (`PreventiveTabsTest.mjs`)**
  * **Requisitos:** RF-PREV-01 a RF-PREV-08
  * **Dependencias:** T-PREV-18, T-PREV-19, T-PREV-20, T-PREV-21, T-PREV-22, T-PREV-23
  * **Hecho cuando:** La aplicación inyecta fluidamente las nuevas vistas preventivas según el rol autenticado y la suite `node tests/unit/PreventiveTabsTest.mjs` pasa al 100% en verde.

---

## Fase 6: Verificación Global y Certificación Constitucional

- [x] **T-PREV-25: Batería completa de pruebas (`php tests/run_all.php`), verificación de regresión y auditoría constitucional**
  * **Requisitos:** RNF-01 a RNF-06, Constitución Art. I al VII
  * **Dependencias:** T-PREV-17, T-PREV-24
  * **Hecho cuando:** La ejecución de `php tests/run_all.php` corre las 68 suites preexistentes más todas las nuevas suites del módulo 05 al 100% en verde (0 errores, 0 fallos), el comando de auditoría certifica cero sentencias `DELETE FROM` en `src/` (Art. III), se valida el cumplimiento innegociable de seguridad alimentaria (Art. II), la regla de ticket único (Art. V.2), el cierre justificado (Art. V.1) y la privacidad de operadores técnicos (Art. V.4).
