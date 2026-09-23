# PLAN DE IMPLEMENTACIÓN TÉCNICA · ADMINISTRACIÓN INTEGRAL (SEDES, MÁQUINAS Y PERSONAL)
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `04-admin-crud`  
**Documento:** `specs/04-admin-crud/plan.md`  
**Referencia Funcional:** [`specs/functional/admin_crud_spec.md`](../functional/admin_crud_spec.md) (RF-01 a RF-05, RNF-01 a RNF-05)  
**Contratos Técnicos:** [`specs/technical/admin_crud_contracts.md`](../technical/admin_crud_contracts.md)  
**Normativa Suprema:** [constitution.md](../../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [AGENTS.md](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  

---

## 1. Estructura de Ficheros y Módulos

Siguiendo Clean Architecture, el Dogma Vanilla (PHP 8.2+ POO estricto sin dependencias de Composer, frontend Vue 3 en ES Modules sin herramientas de compilación/npm en producción) y el Dualismo Lingüístico (código en inglés camelCase/PascalCase, comentarios y UI en castellano):

```text
gestor-incidencias-vending/
├── database/
│   ├── migrations/
│   │   └── 004_admin_crud_audit_and_snapshot.sql   # DDL para USER en audit_log y machine_type_snapshot
│   └── cloud_init.sql                              # Consolidación con la migración 004
├── src/
│   ├── Core/
│   │   └── Domain/
│   │       ├── Exception/
│   │       │   ├── CannotDeactivateSelfException.php     # Intento de auto-baja de coordinador
│   │       │   ├── MinimumActiveStaffException.php       # Infracción de guardia mínima activa
│   │       │   ├── PendingIncidentsBlockedException.php  # Baja de técnico con tickets pendientes
│   │       │   ├── ActiveMachinesBlockedException.php    # Baja de sede con máquinas activas
│   │       │   ├── MachineTransferBlockedException.php   # Traslado de máquina con avería/garantía
│   │       │   ├── MachineTypeChangeBlockedException.php # Cambio de tipo con avería/garantía
│   │       │   └── InactiveRecordCollisionException.php  # Código/email existente en inactivo
│   │       └── Repository/
│   │           ├── LocationRepositoryInterface.php       # Métodos de CRUD y conteo de sedes
│   │           ├── MachineRepositoryInterface.php        # Métodos de CRUD, traslado y validación
│   │           └── UserRepositoryInterface.php           # Métodos de CRUD, reseteo clave y guardias
│   ├── Application/
│   │   └── Service/
│   │       ├── AdminLocationService.php                  # Reglas de negocio y auditoría de Sedes
│   │       ├── AdminMachineService.php                   # Reglas sanitarias, traslados y auditoría
│   │       └── AdminUserService.php                      # Reglas de claves, guardias y personal
│   ├── Infrastructure/
│   │   └── Repository/
│   │       ├── PdoLocationRepository.php                 # Consultas y mutaciones PDO para Sedes
│   │       ├── PdoMachineRepository.php                  # Consultas, traslados y comprobaciones PDO
│   │       └── PdoUserRepository.php                     # Consultas, claves bcrypt y guardias PDO
│   └── Presentation/
│       ├── Controller/
│       │   ├── CoordinatorAdminController.php            # Controladores REST para sedes, máquinas y usuarios
│       │   └── QrScanController.php                      # Actualización de respuesta para máquinas inactivas
│       └── Routing/
│           └── AppRouter.php                             # Registro de nuevas rutas REST del coordinador
├── public/
│   └── assets/
│       └── js/
│           ├── components/
│           │   ├── AdminLocationsTab.js                  # Pestaña y modales reactivos de Sedes
│           │   ├── AdminMachinesTab.js                   # Pestaña, modales y traslado de Máquinas
│           │   ├── AdminUsersTab.js                      # Pestaña, modales y reseteo de Técnicos/Coord.
│           │   └── QrInactiveMachineNotice.js            # Componente informativo público para QR retiradas
│           └── views/
│               ├── CoordinatorDashboardView.js           # Subpestaña "🏢 Administración" integrada
│               └── QrReportView.js                       # Renderizado condicional ante máquina inactiva
└── tests/
    ├── unit/
    │   ├── AdminValidationExceptionsTest.php             # Test unitario de reglas y excepciones de dominio
    │   ├── AdminLocationServiceTest.php                  # Test de reglas de sedes y colisión
    │   ├── AdminMachineServiceTest.php                   # Test de traslados, tipología sanitaria y garantía
    │   ├── AdminUserServiceTest.php                      # Test de auto-baja, guardias mínimas y contraseñas
    │   └── AdminTabsTest.mjs                             # Test unitario frontend ESM para componentes
    └── integration/
        ├── AdminLocationsEndpointTest.php                # Test de integración HTTP endpoints de sedes
        ├── AdminMachinesEndpointTest.php                 # Test de integración HTTP endpoints de máquinas
        ├── AdminUsersEndpointTest.php                    # Test de integración HTTP endpoints de usuarios
        └── QrInactiveMachineScanTest.php                 # Test de escaneo QR sobre máquinas dadas de baja
```

---

## 2. Esquema de Base de Datos y Modelo Relacional

### 2.1 Migración DDL (`004_admin_crud_audit_and_snapshot.sql`)

```sql
-- VendGuard Módulo 04: Migración de soporte para CRUD y preservación histórica
-- 1. Ampliación del enum en audit_log para soportar entidad USER
ALTER TABLE `audit_log` 
MODIFY COLUMN `entity_type` ENUM('TICKET', 'MACHINE', 'LOCATION', 'USER') NOT NULL;

-- 2. Preservación histórica de tipología de máquina en tickets (Artículo II)
ALTER TABLE `incidents`
ADD COLUMN `machine_type_snapshot` ENUM('HOT_DRINKS', 'COLD_DRINKS', 'SNACKS', 'PERISHABLE_FOOD', 'COMBO') NULL AFTER `machine_id`;

-- 3. Backfill para tickets pasados
UPDATE `incidents` i
INNER JOIN `machines` m ON i.`machine_id` = m.`id`
SET i.`machine_type_snapshot` = m.`machine_type`
WHERE i.`machine_type_snapshot` IS NULL;
```

---

## 3. Lógica de Dominio y Reglas Infranqueables

### 3.1 Sedes (Locations)
1. **Inmutabilidad del Código:** `site_code` es asignado en el alta y jamás se modifica en posteriores `UPDATE`.
2. **Colisión Amigable:** Si se intenta registrar un `site_code` que ya existe con `is_active = 0`, el sistema retorna `409 Conflict` con bandera `can_reactivate = true`.
3. **Bloqueo de Baja:** No se puede dar de baja una sede si `SELECT COUNT(*) FROM machines WHERE location_id = ? AND is_active = 1` > 0.
4. **Reactivación Selectiva:** La reactivación de una sede no reactiva en masa sus máquinas dadas de baja.

### 3.2 Máquinas (Machines)
1. **Inmutabilidad del Código:** `code` es permanente y físico en la chapa del activo.
2. **Bloqueo por Averías o Garantía (Decisión QA 1):**
   - Una máquina no puede darse de baja ni trasladarse a otra sede si tiene incidencias en estado activo (`REGISTERED`, `ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`, `REOPENED`) o en ventana de garantía de 48h (`RESOLVED`).
3. **Bloqueo de Cambio de Tipología Sanitaria (Decisión QA 3 & Art. II):**
   - No se puede cambiar la tipología de una máquina si tiene averías activas o en garantía.
   - En incidencias nuevas, `machine_type_snapshot` captura el `machine_type` actual de la máquina para que futuros cambios de modelo o tipología no alteren retroactivamente el cálculo del MTTR ni las auditorías sanitarias pasadas.
4. **Reactivación con Sede Inactiva (Decisión QA 5):**
   - Si la sede original de la máquina está inactiva (`is_active = 0`), la reactivación exige indicar una sede activa de destino y la nueva planta/ala, registrando traslado y reactivación en auditoría.

### 3.3 Personal Interno (Users)
1. **Inmutabilidad del Correo:** `email` no es modificable tras su creación.
2. **Bloqueo de Auto-desactivación (Caso Límite 8):** El coordinador autenticado en la sesión (`$request->getAttribute('user_id')`) no puede ejecutar `PATCH /api/coordinator/users/{id}/deactivate` sobre su propio ID. Retorna `403 Forbidden`.
3. **Guardia Mínima Operativa (Caso Límite 9):**
   - Al intentar dar de baja un usuario, el sistema verifica que la cantidad restante de usuarios activos con su mismo rol (`role`) sea $\ge 1$. De lo contrario, rechaza con `409 Conflict`.
4. **Bloqueo por Averías Pendientes (Decisión QA 2):**
   - Si un técnico tiene averías en `ASSIGNED`, `IN_PROGRESS` o `PENDING_PARTS`, la baja se bloquea con `409 Conflict`. El coordinador debe reasignar primero esas averías a otro técnico desde el panel de triaje.
5. **Seguridad Criptográfica:** Las contraseñas se hashean mediante `password_hash($raw, PASSWORD_BCRYPT, ['cost' => 12])`. El hash jamás se expone en respuestas ni en el registro de auditoría.

### 3.4 Escaneo QR Ciudadano
1. Al escanear una máquina con `is_active = 0`, la API `/api/qr/scan/{code}` devuelve `allow_reporting = false` junto con el mensaje explicativo oficial de "Máquina temporalmente retirada o fuera de servicio", impidiendo la apertura de formularios de avería.

---

## 4. Estrategia de Frontend (Dogma Vanilla Vue 3)

1. **Subnavegación en Panel de Coordinación:**
   - En `CoordinatorDashboardView.js`, la barra superior incluye la pestaña **"🏢 Administración"** con tres subpestañas:
     - `Sedes`
     - `Máquinas`
     - `Técnicos y Coordinadores`
2. **Gestión de Estados e Inactivos:**
   - Filtro toggle interactivo: `Activas` / `Bajas` / `Todas`.
   - Botón directo de **Reactivar** con un solo clic si el usuario intenta crear un registro cuyo código o email colisiona con uno inactivo.
   - En máquinas cuya sede original esté inactiva, el modal de reactivación solicita obligatoriamente la nueva sede mediante un selector desplegable con las sedes activas.
3. **UX Responsiva y Accesible:**
   - Cumplimiento de WCAG 2.1 AA (contraste $\ge 4.5:1$).
   - Diálogos modales accesibles con confirmación explícita para operaciones de baja lógica y reseteo de claves.

---

## 5. Estrategia de Verificación y Testing

1. **Pruebas Unitarias PHP:** Verifican cada regla de bloqueo y excepción de dominio de forma aislada.
2. **Pruebas de Integración PHP:** Verifican el ciclo de vida completo de cada entidad mediante llamadas HTTP y transacciones en base de datos de test, asegurando que se graben los eventos de `audit_log` correspondientes.
3. **Pruebas Unitarias JS (ESM Node.js):** Validan el renderizado condicional de los componentes de administración y la pantalla de máquina inactiva.
4. **Verificación de Regresiones:** El comando `php tests/run_all.php` debe continuar pasando al 100% (64 suites preexistentes + nuevas suites de administración).
