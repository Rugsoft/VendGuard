# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO DE ADMINISTRACIÓN INTEGRAL (TASKS.MD)
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `04-admin-crud`  
**Documento:** `specs/04-admin-crud/tasks.md`  
**Referencia Funcional:** [`specs/functional/admin_crud_spec.md`](../functional/admin_crud_spec.md)  
**Contratos Técnicos:** [`specs/technical/admin_crud_contracts.md`](../technical/admin_crud_contracts.md)  
**Plan Técnico:** [`specs/04-admin-crud/plan.md`](plan.md)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Esquema de Base de Datos y Excepciones de Dominio

- [ ] **T-ADM-01: Migración DDL y soporte de auditoría (`004_admin_crud_audit_and_snapshot.sql`, `cloud_init.sql`)**
  * **Requisitos:** `RF-05`, Constitución Art. II y Art. III
  * **Dependencias:** Ninguna
  * **Hecho cuando:** La migración DDL amplía `audit_log.entity_type` para incluir `'USER'` y agrega `machine_type_snapshot` en la tabla `incidents` con backfill para tickets existentes; `cloud_init.sql` queda actualizado y los tests de migración pasan.

- [ ] **T-ADM-02: Implementar Excepciones de Dominio para Reglas de Bloqueo Administrativas**
  * **Requisitos:** `RF-01`, `RF-02`, `RF-03`, `RNF-01`
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existen en `src/Core/Domain/Exception/` las clases `CannotDeactivateSelfException`, `MinimumActiveStaffException`, `PendingIncidentsBlockedException`, `ActiveMachinesBlockedException`, `MachineTransferBlockedException`, `MachineTypeChangeBlockedException` e `InactiveRecordCollisionException`.

- [ ] **T-ADM-03: Crear suite de pruebas unitarias para excepciones y reglas de dominio (`AdminValidationExceptionsTest.php`)**
  * **Requisitos:** `RF-01`, `RF-02`, `RF-03`
  * **Dependencias:** T-ADM-02
  * **Hecho cuando:** `php tests/unit/AdminValidationExceptionsTest.php` pasa al 100% en verde, validando que cada excepción provea los códigos de error exactos y mensajes descriptivos requeridos por los contratos técnicos.

---

## Fase 2: Repositorios PDO y Capa de Persistencia

- [ ] **T-ADM-04: Extender `LocationRepositoryInterface` y `PdoLocationRepository.php`**
  * **Requisitos:** `RF-01` (EARS 1.1, 1.2, 1.3, 1.4, 1.5)
  * **Dependencias:** T-ADM-01
  * **Hecho cuando:** `PdoLocationRepository` implementa creación, edición, soft delete (`is_active = 0`), reactivación (`is_active = 1`), listado filtrado (`status`, `search`) y método `countActiveMachines(int $locationId): int`.

- [ ] **T-ADM-05: Extender `MachineRepositoryInterface` y `PdoMachineRepository.php`**
  * **Requisitos:** `RF-02` (EARS 2.1 a 2.6)
  * **Dependencias:** T-ADM-01
  * **Hecho cuando:** `PdoMachineRepository` implementa creación, actualización de modelo/ubicación/tipo, traslado de sede, baja lógica, reactivación (con cambio de sede si aplica), listado con filtros (`status`, `location_id`, `machine_type`, `search`) y comprobación `hasActiveTicketOrWarranty(int $machineId): bool`.

- [ ] **T-ADM-06: Extender `UserRepositoryInterface` y `PdoUserRepository.php`**
  * **Requisitos:** `RF-03` (EARS 3.1 a 3.6)
  * **Dependencias:** T-ADM-01
  * **Hecho cuando:** `PdoUserRepository` implementa creación con hash Bcrypt, actualización de datos de contacto, reseteo de contraseña, baja lógica, reactivación, listado con filtros (`role`, `status`, `search`), conteo de guardia mínima `countActiveByRole(UserRole $role): int` y conteo de incidencias pendientes `countPendingIncidents(int $technicianId): int`.

---

## Fase 3: Servicios de Aplicación y Auditoría Inmutable

- [ ] **T-ADM-07: Implementar `AdminLocationService.php` y suite de prueba unitaria `AdminLocationServiceTest.php`**
  * **Requisitos:** `RF-01`, `RF-05`
  * **Dependencias:** T-ADM-02, T-ADM-04
  * **Hecho cuando:** El servicio orquesta la creación (validando formato `^[A-Z0-9-]{3,32}$` y colisión con inactivas), actualización, baja (bloqueada si hay máquinas activas) y reactivación de sedes, registrando cada acción en `audit_log`, y `AdminLocationServiceTest.php` pasa al 100%.

- [ ] **T-ADM-08: Implementar `AdminMachineService.php` y suite de prueba unitaria `AdminMachineServiceTest.php`**
  * **Requisitos:** `RF-02`, `RF-05`, Constitución Art. II
  * **Dependencias:** T-ADM-02, T-ADM-05
  * **Hecho cuando:** El servicio valida la inmutabilidad del código, bloquea traslados, bajas y cambios de tipo si hay tickets activos o en garantía de 48h (`RESOLVED`), exige nueva sede activa al reactivar si la original está inactiva, registra los eventos en `audit_log`, y `AdminMachineServiceTest.php` pasa al 100%.

- [ ] **T-ADM-09: Implementar `AdminUserService.php` y suite de prueba unitaria `AdminUserServiceTest.php`**
  * **Requisitos:** `RF-03`, `RF-05`, Constitución Art. V.4
  * **Dependencias:** T-ADM-02, T-ADM-06
  * **Hecho cuando:** El servicio valida contraseñas seguras ($\ge 8$ caracteres), previene la auto-desactivación del coordinador autenticado, protege la guardia mínima de al menos 1 técnico y 1 coordinador, bloquea bajas con averías pendientes, registra en `audit_log` (sin filtrar hashes de claves), y `AdminUserServiceTest.php` pasa al 100%.

---

## Fase 4: Controladores REST y Rutas HTTP (API-First)

- [ ] **T-ADM-10: Implementar `CoordinatorAdminController.php` y registrar rutas en `AppRouter.php`**
  * **Requisitos:** `RF-01`, `RF-02`, `RF-03`, `RF-05`
  * **Dependencias:** T-ADM-07, T-ADM-08, T-ADM-09
  * **Hecho cuando:** Todas las rutas REST especificadas en `admin_crud_contracts.md` quedan registradas bajo la protección de `InternalAuthMiddleware(COORDINATOR)` y manejan códigos de estado HTTP `200`, `201`, `400`, `403`, `404`, `409` y `422` según contrato.

- [ ] **T-ADM-11: Actualizar `QrScanController.php` y servicio de escaneo para máquinas inactivas**
  * **Requisitos:** `RF-04` (EARS 4.1, 4.2)
  * **Dependencias:** T-ADM-05
  * **Hecho cuando:** `GET /api/qr/scan/{code}` devuelve para máquinas dadas de baja el estado `INACTIVE`, `allow_reporting = false` y el mensaje oficial de advertencia de fuera de servicio en lugar de error 404.

- [ ] **T-ADM-12: Crear suites de integración HTTP (`AdminLocationsEndpointTest.php`, `AdminMachinesEndpointTest.php`, `AdminUsersEndpointTest.php`, `QrInactiveMachineScanTest.php`)**
  * **Requisitos:** `RF-01` a `RF-05`, `RNF-04`
  * **Dependencias:** T-ADM-10, T-ADM-11
  * **Hecho cuando:** Todas las suites de integración pasan al 100% en verde evaluando respuestas JSON, cabeceras, restricciones constitucionales y persistencia de eventos de auditoría.

---

## Fase 5: Componentes Frontend y Vistas Vanilla Vue 3

- [ ] **T-ADM-13: Implementar componente `AdminLocationsTab.js`**
  * **Requisitos:** `RF-01`, `RNF-05`
  * **Dependencias:** T-ADM-10
  * **Hecho cuando:** Permite listar sedes con filtro de estado (activas/bajas/todas), buscador, modal de alta con validación de código, edición de datos de contacto, y baja lógica con alerta interactiva si tiene máquinas activas.

- [ ] **T-ADM-14: Implementar componente `AdminMachinesTab.js`**
  * **Requisitos:** `RF-02`, `RNF-05`, Constitución Art. II
  * **Dependencias:** T-ADM-10
  * **Hecho cuando:** Permite listar máquinas con badges de tipología sanitaria (resaltando perecederos), filtros combinados, modal de alta, edición de ubicación/modelo, modal de traslado entre sedes activas, y reactivación asistida con selector de nueva sede si la original fue dada de baja.

- [ ] **T-ADM-15: Implementar componente `AdminUsersTab.js`**
  * **Requisitos:** `RF-03`, `RNF-05`
  * **Dependencias:** T-ADM-10
  * **Hecho cuando:** Permite listar personal mostrando insignias de rol e incidencias activas asignadas, modal de alta con contraseña, edición de datos de contacto, modal de reseteo de clave, y confirmación de baja con advertencia de bloqueo si tiene averías pendientes.

- [ ] **T-ADM-16: Implementar vista pública informativa `QrInactiveMachineNotice.js`**
  * **Requisitos:** `RF-04`
  * **Dependencias:** T-ADM-11
  * **Hecho cuando:** La lectura de un QR físico de una máquina retirada muestra una tarjeta informativa amigable bloqueando el formulario de reporte y ofreciendo el teléfono de soporte de la empresa.

- [ ] **T-ADM-17: Integrar subpestaña "🏢 Administración" en `CoordinatorDashboardView.js` y suites ESM `AdminTabsTest.mjs`**
  * **Requisitos:** `RF-01`, `RF-02`, `RF-03`, `RF-04`
  * **Dependencias:** T-ADM-13, T-ADM-14, T-ADM-15, T-ADM-16
  * **Hecho cuando:** El panel de Coordinación integra la navegación entre Sedes, Máquinas y Personal, y `node tests/unit/AdminTabsTest.mjs` pasa al 100% en verde.

---

## Fase 6: Verificación Global y Certificación Constitucional

- [ ] **T-ADM-18: Ejecución global de la batería de pruebas (`php tests/run_all.php`) y verificación de regresión**
  * **Requisitos:** `RNF-01`, `RNF-02`, `RNF-03`, `RNF-04`, Constitución Art. I al VII
  * **Dependencias:** T-ADM-12, T-ADM-17
  * **Hecho cuando:** Las 64 suites de pruebas preexistentes más todas las nuevas suites del módulo 04 pasan al 100% en verde (0 errors, 0 failures), se verifica cero sentencias `DELETE FROM` en el código fuente de producción (`src/`) y la base de datos queda restaurada limpiamente con sus semillas.
