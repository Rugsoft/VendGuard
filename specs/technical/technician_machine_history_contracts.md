# Especificación Técnica — Historial de Averías de la Máquina (Técnico)

**Módulo:** RF-07 — Gestión de la Intervención Técnica en Campo
**Actor:** Field Technician (Técnico de Ruta)
**Estado:** Aprobado (gate de planificación, plan SDD aceptado por el usuario)
**Dogma:** Vanilla PHP 8.2 + PDO · Vue 3 ESM · Cero dependencias nuevas

---

## 1. Problema

El técnico ve en `Mi Ruta` únicamente sus averías en estados activos de ruta
(`ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`). No dispone de ningún acceso para
consultar las averías **históricas** (resueltas, cerradas, reabiertas) de la
máquina en la que está trabajando, información crítica para diagnosticar
averías recurrentes ("avería crónica", Art. V.6 / EARS 9.3).

## 2. Requisitos (EARS)

* **EARS H.1 (Evento):** Cuando el técnico pulse el control **"Historial de la
  máquina"** sobre una avería de su ruta, el sistema deberá mostrar un modal de
  **solo lectura** con todas las averías registradas para esa máquina (toda
  avería no `CANCELADA` ni borrada lógicamente), ordenadas de más reciente a
  más antigua.
* **EARS H.2 (Ubicuo/Contenido):** Cada entrada del historial deberá mostrar:
  `ticket_code`, estado, urgencia, categoría, descripción, técnico asignado
  (nombre, si existe) y fechas `created_at`, `resolved_at` y `closed_at`. El
  endpoint deberá incluir además el número de **reaperturas** por avería.
* **EARS H.3 (Excepción/Autorización):** Si el técnico autenticado no tiene
  ninguna avería de esa máquina en estados activos de ruta, el sistema deberá
  rechazar la consulta con `403 NOT_ASSIGNED_TO_TECHNICIAN`.
* **EARS H.4 (Excepción/Inexistente):** Si la máquina no existe (o está borrada
  lógicamente), el sistema deberá devolver `404 MACHINE_NOT_FOUND`.
* **EARS H.5 (Estado/Privacidad — Art. V.4):** El endpoint será de **solo
  lectura**, autenticado con `InternalAuthMiddleware` (rol `TECHNICIAN`), y
  **no expondrá datos privados**: sin claimantes, sin reintegros, sin
  dictámenes económicos y sin teléfonos de terceros.
* **EARS H.6 (Compatibilidad):** La operación no deberá alterar el comportamiento
  de la lista de pendientes (carga, orden, filtros ni acciones de intervención).

## 3. Contrato de API

### `GET /api/technician/machines/{id}/history`

* **Auth:** `InternalAuthMiddleware(UserRole::TECHNICIAN)` (Bearer token).
* **200 OK**

```json
{
  "success": true,
  "data": {
    "machine": { "id": 12, "code": "VM-012", "model": "Necta Koro" },
    "history": [
      {
        "id": 3401,
        "ticket_code": "INC-2026-0341",
        "status": "CLOSED",
        "urgency": "HIGH",
        "category": "MECHANICAL",
        "description": "No expende producto",
        "technician_name": "Marc Puig",
        "created_at": "2026-09-30 10:12:00",
        "resolved_at": "2026-09-30 12:40:00",
        "closed_at": "2026-10-02 12:41:00",
        "reopen_count": 0
      }
    ]
  }
}
```

* **401 UNAUTHORIZED** — token ausente o inválido.
* **403 NOT_ASSIGNED_TO_TECHNICIAN** — el técnico no tiene avería activa de
  ruta en esa máquina (EARS H.3).
* **404 MACHINE_NOT_FOUND** — máquina inexistente o borrada lógicamente
  (EARS H.4).

> `history` **excluye** `CANCELLED` (EARS H.1). El estado `REOPENED` se muestra
> como cualquier otro estado; el nº de reaperturas viene en `reopen_count`.

## 4. Diseño

### Backend (Clean Architecture)

* **Core/Domain:** sin cambios (no hay reglas de negocio nuevas; es una consulta).
* **Infrastructure:** `PdoIncidentRepository::findAllByMachineId(int $machineId,
  array $excludeStatuses = ['CANCELLED']): array` — misma forma SQL que
  `findAllByLocation()` (JOINs a `machines`, `locations`, `users`;
  `deleted_at IS NULL`; `ORDER BY i.created_at DESC`). Placeholders con nombre
  único (PDO sin emulación, regla HY093).
* **Application/Presentation:** `TechnicianController::getMachineHistory()`:
  1. Resolución de identidad (401 si falta `user_id`).
  2. Carga de máquina (`machineRepo->findById`) → 404 si no existe.
  3. Comprobación de autorización: `countActiveRouteByMachineAndTechnician()`
     (reuso de `findActiveByMachineId()` comparando `assigned_technician_id`)
     → 403 si no hay coincidencia.
  4. Historial completo + conteo de reaperturas por avería
     (`countReopenEvents()` ya existente).
* **Routing:** nueva ruta GET bajo el bloque `$technicianAuth` de `AppRouter`.

### Frontend (Vue 3 ESM)

* `api.js` → `technician.getMachineHistory(machineId)`.
* Nuevo componente `public/assets/js/components/TechnicianMachineHistoryModal.js`
  basado en `ModalDialog` (solo lectura, lista cronológica inversa, badges de
  estado/urgencia vía `IncidentBadge`, estado vacío, errores 403/404 en línea).
* `TechnicianRouteView.js`: botón "🕘 Historial de la máquina" por tarjeta
  (junto a los controles existentes de teléfono y GPS) + estado
  `historyMachineId` + render del modal fuera del `v-for`.
* Sin dependencias nuevas; estilos con tokens del design system (bordes 4px en
  controles, 8px en modal, hairline).

## 5. Impacto y compatibilidad

* **Base de datos:** ninguna migración (solo lectura de tablas existentes).
* **Endpoints existentes:** sin cambios. `my-route`, `start`, `pause`,
  `resolve` y el resto de vistas no alteran su comportamiento (EARS H.6).
* **Seguridad:** superficie nueva de solo lectura, con verificación de
  pertenencia de la máquina a la ruta activa del técnico.

## 6. Criterios de aceptación (verificación)

1. `php tests/run_all.php` en verde, incluyendo las suites nuevas:
   * `tests/unit/TechnicianMachineHistoryServiceTest.php`
   * `tests/integration/TechnicianMachineHistoryApiTest.php`
   * `tests/unit/TechnicianMachineHistoryModalTest.mjs`
   * ampliaciones de `TechnicianRouteViewTest.mjs` y `FrontendApiStoreTest.mjs`.
2. Regresión intacta: `TechnicianRouteEndpointTest.php`,
   `TechnicianRouteViewTest.php/.mjs` sin cambios de resultado.
3. Smoke en navegador: login técnico → pendientes → botón "Historial de la
   máquina" → modal con historial; la lista de pendientes se comporta igual.
