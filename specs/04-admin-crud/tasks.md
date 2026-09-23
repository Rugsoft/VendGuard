# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO DE ADMINISTRACIÓN INTEGRAL (TASKS.MD)
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `04-admin-crud`  
**Documento:** `specs/04-admin-crud/tasks.md`  
**Referencia Funcional:** [`specs/functional/admin_crud_spec.md`](../functional/admin_crud_spec.md) (RF-01 a RF-05, RNF-01 a RNF-05)  
**Contratos Técnicos:** [`specs/technical/admin_crud_contracts.md`](../technical/admin_crud_contracts.md)  
**Plan Técnico:** [`specs/04-admin-crud/plan.md`](plan.md)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Esquema de Base de Datos y Excepciones de Dominio

- [x] **T-ADM-01: Migración DDL y soporte de auditoría (`004_admin_crud_audit_and_snapshot.sql`, `cloud_init.sql`)**
  * **Requisitos:** `RF-05`, Constitución Art. II y Art. III
  * **Dependencias:** Ninguna
  * **Hecho cuando:** La ejecución de `php bin/migrate.php` aplica con éxito `004_admin_crud_audit_and_snapshot.sql`, ampliando `audit_log.entity_type` para incluir `'USER'` e incorporando `machine_type_snapshot` en la tabla `incidents` con backfill para tickets preexistentes; el esquema `database/cloud_init.sql` queda consolidado.

- [x] **T-ADM-02: Implementar Excepciones de Dominio para Reglas de Bloqueo Administrativas**
  * **Requisitos:** `RF-01`, `RF-02`, `RF-03`, `RNF-01`
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existen en `src/Core/Domain/Exception/` las 7 clases tipadas (`CannotDeactivateSelfException`, `MinimumActiveStaffException`, `PendingIncidentsBlockedException`, `ActiveMachinesBlockedException`, `MachineTransferBlockedException`, `MachineTypeChangeBlockedException` e `InactiveRecordCollisionException`), cada una proveyendo su código de error técnico y mensaje en castellano según contrato.

- [x] **T-ADM-03: Crear suite de pruebas unitarias para excepciones y reglas de dominio (`AdminValidationExceptionsTest.php`)**
  * **Requisitos:** `RF-01`, `RF-02`, `RF-03`
  * **Dependencias:** T-ADM-02
  * **Hecho cuando:** La ejecución de `php tests/unit/AdminValidationExceptionsTest.php` pasa al 100% en verde evaluando las 7 excepciones, verificando códigos HTTP (`403`, `409`), identificadores de error (`CANNOT_DEACTIVATE_SELF`, `MINIMUM_ACTIVE_STAFF_BREACH`, etc.) y banderas de reactivación.

---

## Fase 2: Repositorios PDO y Capa de Persistencia

- [x] **T-ADM-04: Extender `LocationRepositoryInterface` y `PdoLocationRepository.php`**
  * **Requisitos:** `RF-01` (EARS 1.1, 1.2, 1.3, 1.4, 1.5)
  * **Dependencias:** T-ADM-01
  * **Hecho cuando:** `PdoLocationRepository` implementa métodos para creación (`create`), actualización (`update`), baja lógica (`softDelete` fijando `is_active = 0` y `deleted_at = NOW()`), reactivación (`restore` fijando `is_active = 1` y `deleted_at = NULL`), listado con filtros (`findAll` con `status` y `search`), y verificación `countActiveMachines(int $locationId): int`.

- [x] **T-ADM-05: Extender `MachineRepositoryInterface` y `PdoMachineRepository.php`**
  * **Requisitos:** `RF-02` (EARS 2.1 a 2.6)
  * **Dependencias:** T-ADM-01
  * **Hecho cuando:** `PdoMachineRepository` implementa creación (`create`), actualización de modelo/ubicación/tipo (`update`), traslado entre sedes (`transfer`), baja lógica (`softDelete`), reactivación con reubicación (`restoreWithLocation`), listado con filtros (`findAll` con `status`, `location_id`, `machine_type`, `search`) y comprobación atómica `hasActiveTicketOrWarranty(int $machineId): bool`.

- [x] **T-ADM-06: Extender `UserRepositoryInterface` y `PdoUserRepository.php`**
  * **Requisitos:** `RF-03` (EARS 3.1 a 3.6)
  * **Dependencias:** T-ADM-01
  * **Hecho cuando:** `PdoUserRepository` implementa creación con hash Bcrypt (`create`), actualización de contacto (`update`), reseteo de contraseña (`resetPassword`), baja lógica (`softDelete`), reactivación (`restore`), listado con filtros (`findAll` con `role`, `status`, `search`), conteo de guardia mínima `countActiveByRole(UserRole $role): int` y conteo de incidencias pendientes `countPendingIncidents(int $technicianId): int`.

---

## Fase 3: Servicios de Aplicación y Auditoría Inmutable

- [x] **T-ADM-07: Implementar `AdminLocationService.php` y suite de prueba unitaria `AdminLocationServiceTest.php`**
  * **Requisitos:** `RF-01`, `RF-05`
  * **Dependencias:** T-ADM-02, T-ADM-04
  * **Hecho cuando:** `AdminLocationService` orquesta la validación de regex `^[A-Z0-9-]{3,32}$`, detección de colisión con sedes inactivas (`can_reactivate = true`), bloqueo de baja si hay máquinas activas (`ActiveMachinesBlockedException`) y registro sincrónico append-only en `audit_log`, pasando al 100% la suite `php tests/unit/AdminLocationServiceTest.php`.

- [x] **T-ADM-08: Implementar `AdminMachineService.php` y suite de prueba unitaria `AdminMachineServiceTest.php`**
  * **Requisitos:** `RF-02`, `RF-05`, Constitución Art. II
  * **Dependencias:** T-ADM-02, T-ADM-05
  * **Hecho cuando:** `AdminMachineService` valida la inmutabilidad del código, bloquea traslados, bajas y cambios de tipo si hay tickets activos o en ventana de garantía de 48h (`RESOLVED`), exige nueva sede activa al reactivar si la original está dada de baja, registra eventos en `audit_log`, y la suite `php tests/unit/AdminMachineServiceTest.php` pasa al 100%.

- [x] **T-ADM-09: Implementar `AdminUserService.php` y suite de prueba unitaria `AdminUserServiceTest.php`**
  * **Requisitos:** `RF-03`, `RF-05`, Constitución Art. V.4
  * **Dependencias:** T-ADM-02, T-ADM-06
  * **Hecho cuando:** `AdminUserService` valida contraseñas seguras ($\ge 8$ caracteres), bloquea la auto-desactivación del coordinador en sesión, asegura la guardia mínima ($\ge 1$ técnico y $\ge 1$ coordinador activos), bloquea bajas con averías pendientes, registra en `audit_log` (sin filtrar hashes de claves), y la suite `php tests/unit/AdminUserServiceTest.php` pasa al 100%.

---

## Fase 4: Controladores REST y Rutas HTTP (API-First)

- [x] **T-ADM-10: Implementar `CoordinatorAdminController.php` y registrar rutas en `AppRouter.php`**
  * **Requisitos:** `RF-01`, `RF-02`, `RF-03`, `RF-05`
  * **Dependencias:** T-ADM-07, T-ADM-08, T-ADM-09
  * **Hecho cuando:** Todos los endpoints especificados en `admin_crud_contracts.md` quedan registrados bajo la protección de `InternalAuthMiddleware(COORDINATOR)` en `AppRouter.php`, mapeando las excepciones de dominio a sus respectivos códigos de respuesta HTTP `200`, `201`, `400`, `403`, `404`, `409` y `422`.

- [x] **T-ADM-11: Actualizar `QrScanController.php` y servicio de escaneo para máquinas inactivas**
  * **Requisitos:** `RF-04` (EARS 4.1, 4.2)
  * **Dependencias:** T-ADM-05
  * **Hecho cuando:** Al invocar `GET /api/qr/scan/{code}` sobre una máquina con `is_active = 0`, el controlador responde HTTP `200` con `status = "INACTIVE"`, `is_active = false`, `allow_reporting = false` y el mensaje oficial de advertencia de fuera de servicio, bloqueando la creación de incidencias.

- [x] **T-ADM-12: Crear suites de integración HTTP (`AdminLocationsEndpointTest.php`, `AdminMachinesEndpointTest.php`, `AdminUsersEndpointTest.php`, `QrInactiveMachineScanTest.php`)**
  * **Requisitos:** `RF-01` a `RF-05`, `RNF-04`
  * **Dependencias:** T-ADM-10, T-ADM-11
  * **Hecho cuando:** La ejecución de las 4 suites de integración PHP pasa al 100% en verde evaluando las llamadas HTTP reales, validaciones de cabeceras Bearer, respuestas JSON y comprobando que cada mutación guarde su fila correspondiente en `audit_log`.

---

## Fase 5: Componentes Frontend y Vistas Vanilla Vue 3

- [x] **T-ADM-13: Implementar componente `AdminLocationsTab.js`**
  * **Requisitos:** `RF-01`, `RNF-05`
  * **Dependencias:** T-ADM-10
  * **Hecho cuando:** El componente Vue 3 ESM renderiza la tabla de sedes con filtros de estado (`active`/`inactive`/`all`), caja de búsqueda, modal de alta con validación de código, edición de datos de contacto, y diálogo de confirmación de baja que alerta interactivamente si tiene máquinas activas.

- [x] **T-ADM-14: Implementar componente `AdminMachinesTab.js`**
  * **Requisitos:** `RF-02`, `RNF-05`, Constitución Art. II
  * **Dependencias:** T-ADM-10
  * **Hecho cuando:** El componente Vue 3 ESM renderiza la tabla de máquinas con insignias de tipología sanitaria (resaltando perecederos), filtros cruzados, modal de alta, edición, modal de traslado con selector de sedes activas, y diálogo de reactivación asistida con selector forzoso de nueva sede si la original fue dada de baja.

- [x] **T-ADM-15: Implementar componente `AdminUsersTab.js`**
  * **Requisitos:** `RF-03`, `RNF-05`
  * **Dependencias:** T-ADM-10
  * **Hecho cuando:** El componente Vue 3 ESM renderiza la plantilla técnica y de coordinación mostrando roles y recuento de averías activas asignadas, modal de alta con contraseña, edición de datos de contacto, modal de reseteo de clave, y confirmación de baja con advertencia de bloqueo si tiene averías pendientes.

- [x] **T-ADM-16: Implementar componente informativo público `QrInactiveMachineNotice.js` y actualizar `QrReportView.js`**
  * **Requisitos:** `RF-04`
  * **Dependencias:** T-ADM-11
  * **Hecho cuando:** La lectura ciudadana de un código QR físico de una máquina retirada despliega la tarjeta informativa visual amigable indicando "Máquina temporalmente retirada o fuera de servicio" y ocultando completamente el formulario de reporte de averías.

- [ ] **T-ADM-17: Integrar subpestaña "🏢 Administración" en `CoordinatorDashboardView.js` y suites ESM `AdminTabsTest.mjs`**
  * **Requisitos:** `RF-01`, `RF-02`, `RF-03`, `RF-04`
  * **Dependencias:** T-ADM-13, T-ADM-14, T-ADM-15, T-ADM-16
  * **Hecho cuando:** La vista `CoordinatorDashboardView.js` incorpora la pestaña "🏢 Administración" con navegación reactiva fluida entre Sedes, Máquinas y Personal, y la ejecución de `node tests/unit/AdminTabsTest.mjs` pasa al 100% en verde.

---

## Fase 6: Verificación Global y Certificación Constitucional

- [ ] **T-ADM-18: Ejecución global de la batería de pruebas (`php tests/run_all.php`) y verificación de regresión**
  * **Requisitos:** `RNF-01`, `RNF-02`, `RNF-03`, `RNF-04`, Constitución Art. I al VII
  * **Dependencias:** T-ADM-12, T-ADM-17
  * **Hecho cuando:** La ejecución de `php tests/run_all.php` corre las 64 suites preexistentes más todas las nuevas suites del módulo 04 al 100% en verde (0 errores, 0 fallos), el comando de auditoría certifica cero sentencias `DELETE FROM` en `src/`, y la base de datos queda restaurada con sus datos semilla intactos.
