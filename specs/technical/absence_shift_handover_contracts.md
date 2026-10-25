# CONTRATOS TÉCNICOS: GESTIÓN DE AUSENCIAS TEMPORALES Y RELEVO DE FIN DE TURNO

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 21 · Gestión de Ausencias Temporales y Relevo de Fin de Turno  
**Documento Canónico:** `specs/technical/absence_shift_handover_contracts.md`  
**Referencia Funcional:** [`../21-absence-shift-handover/spec.md`](../21-absence-shift-handover/spec.md) (v2, 2026-10-23)  
**Fecha:** Octubre 2026  
**Alineación Constitucional:** Art. I (SDD estricto), Art. II (prioridad sanitaria de frío), Art. III.1/III.3 (inmutabilidad y auditoría), Art. IV.1–IV.3 (Dogma Vanilla: PHP 8.2+ OOP nativo, Vue 3 sin bundlers, cero librerías externas), Art. V.3 (técnico único responsable), Art. V.4 (segregación y privacidad de notas internas).

---

## 1. Esquema de Persistencia Relacional (PostgreSQL)

El módulo añade **dos nuevas tablas maestras** al catálogo de base de datos de VendGuard (Tablas 29 y 30).

### 1.1. Tabla 29 · `technician_absences`

```sql
CREATE TABLE IF NOT EXISTS technician_absences (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY,
    technician_id         BIGINT NOT NULL,
    reason                VARCHAR(30) NOT NULL,
    starts_at             TIMESTAMPTZ NOT NULL,
    ends_at               TIMESTAMPTZ NOT NULL,
    notes                 TEXT,
    created_by            BIGINT NOT NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_at          TIMESTAMPTZ,
    cancelled_by          BIGINT,
    cancellation_reason   TEXT,

    CONSTRAINT pk_technician_absences PRIMARY KEY (id),
    CONSTRAINT fk_tech_absences_user FOREIGN KEY (technician_id) REFERENCES users(id),
    CONSTRAINT fk_tech_absences_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_tech_absences_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id),
    CONSTRAINT chk_tech_absences_dates CHECK (ends_at >= starts_at),
    CONSTRAINT chk_tech_absences_reason CHECK (
        reason IN ('VACATION', 'SICK_LEAVE', 'TRAINING', 'PERSONAL_LEAVE', 'COMPENSATORY', 'OTHER')
    ),
    CONSTRAINT chk_tech_absences_cancellation CHECK (
        (cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
        OR (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND LENGTH(TRIM(cancellation_reason)) >= 5)
    )
);

CREATE INDEX IF NOT EXISTS idx_tech_absences_lookup 
    ON technician_absences (technician_id, starts_at, ends_at)
    WHERE cancelled_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_tech_absences_status 
    ON technician_absences (starts_at, ends_at)
    WHERE cancelled_at IS NULL;

-- Migración 014: una sola ausencia viva por técnico en cada instante.
-- Semántica semiabierta [starts_at, ends_at): los rangos contiguos conviven.
CREATE EXTENSION IF NOT EXISTS btree_gist;

ALTER TABLE technician_absences
    ADD CONSTRAINT ex_technician_absences_no_overlap
    EXCLUDE USING gist (
        technician_id WITH =,
        tstzrange(starts_at, ends_at, '[)') WITH &&
    ) WHERE (cancelled_at IS NULL);
```

La comprobación previa del servicio (`hasOverlappingAbsence()`) solo mejora el mensaje. La constraint es la última autoridad y protege también las escrituras que no pasen por el servicio. Una violación de exclusión devuelve `SQLSTATE 23P01` y el repositorio la traduce a `409 ABSENCE_OVERLAP`, sin propagar el mensaje del motor. El cerrojo de aplicación (`SELECT id FROM users WHERE id = :id AND deleted_at IS NULL FOR UPDATE`) serializa las altas del mismo técnico antes de comprobar e insertar.

### 1.2. Tabla 30 · `technician_shifts`

```sql
CREATE TABLE IF NOT EXISTS technician_shifts (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY,
    technician_id         BIGINT NOT NULL,
    shift_date            DATE NOT NULL DEFAULT CURRENT_DATE,
    status                VARCHAR(20) NOT NULL DEFAULT 'ON_SHIFT',
    started_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    meal_break_started_at TIMESTAMPTZ,
    meal_break_ended_at   TIMESTAMPTZ,
    closed_at             TIMESTAMPTZ,
    closed_by             BIGINT,
    close_reason          TEXT,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT pk_technician_shifts PRIMARY KEY (id),
    CONSTRAINT fk_tech_shifts_user FOREIGN KEY (technician_id) REFERENCES users(id),
    CONSTRAINT fk_tech_shifts_closer FOREIGN KEY (closed_by) REFERENCES users(id),
    CONSTRAINT chk_tech_shifts_status CHECK (
        status IN ('ON_SHIFT', 'MEAL_BREAK', 'OFF_SHIFT')
    ),
    CONSTRAINT chk_tech_shifts_meal_order CHECK (
        meal_break_ended_at IS NULL OR (meal_break_started_at IS NOT NULL AND meal_break_ended_at >= meal_break_started_at)
    ),
    CONSTRAINT chk_tech_shifts_close_order CHECK (
        closed_at IS NULL OR closed_at >= started_at
    )
);

-- Restricción de concurrencia: Máximo un turno abierto simultáneo por técnico (R3)
CREATE UNIQUE INDEX IF NOT EXISTS uq_tech_shifts_single_active 
    ON technician_shifts (technician_id)
    WHERE closed_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_tech_shifts_lookup 
    ON technician_shifts (technician_id, shift_date);

CREATE OR REPLACE TRIGGER trg_touch_technician_shifts
    BEFORE UPDATE ON technician_shifts
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
```

### 1.3. Convención temporal de las ausencias

Los límites de una ausencia se interpretan en la zona operativa del parque, **`Europe/Madrid`**, no en UTC:

* Una fecha pura (`YYYY-MM-DD`) abarca desde las `00:00:00` del día inicial hasta las `23:59:59` del día final **en hora local**.
* Una marca con hora y sin offset se interpreta también en `Europe/Madrid`; si trae offset explícito, se respeta el instante recibido.
* La API serializa `starts_at` y `ends_at` con el offset de Madrid y publica `is_full_day = true` cuando ambos límites locales son `00:00:00` y `23:59:59`.
* Los rangos se comparan como intervalos semiabiertos `[starts_at, ends_at)`: dos ausencias que solo comparten el instante límite (por ejemplo, `09:00–13:00` y `13:00–17:00`) **no** se consideran solapadas.

---

## 2. Contratos de Dominio (Domain Model & Enums)

### 2.1. Enums Tipificados

```php
namespace VendGuard\Core\Domain\ValueObject;

enum AbsenceReason: string
{
    case VACATION = 'VACATION';
    case SICK_LEAVE = 'SICK_LEAVE';
    case TRAINING = 'TRAINING';
    case PERSONAL_LEAVE = 'PERSONAL_LEAVE';
    case COMPENSATORY = 'COMPENSATORY';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::VACATION => 'Vacaciones',
            self::SICK_LEAVE => 'Baja médica',
            self::TRAINING => 'Formación / Permiso',
            self::PERSONAL_LEAVE => 'Asuntos propios',
            self::COMPENSATORY => 'Descanso compensatorio',
            self::OTHER => 'Otra ausencia',
        };
    }
}

enum ShiftStatus: string
{
    case ON_SHIFT = 'ON_SHIFT';
    case MEAL_BREAK = 'MEAL_BREAK';
    case OFF_SHIFT = 'OFF_SHIFT';

    public function isAvailable(): bool
    {
        return $this === self::ON_SHIFT;
    }

    public function label(): string
    {
        return match ($this) {
            self::ON_SHIFT => 'En turno',
            self::MEAL_BREAK => 'Pausa comida',
            self::OFF_SHIFT => 'Fuera de turno',
        };
    }
}
```

### 2.2. Entidades Inmutables

* **`TechnicianAbsence`:** Entidad que encapsula la ausencia, validación de rango y cancelación lógica.
* **`TechnicianShift`:** Entidad que encapsula el ciclo de vida de la jornada diaria (`start`, `pauseMeal`, `resume`, `close`).

---

## 3. Puertos de Repositorio (Interfaces)

### 3.1. `TechnicianAbsenceRepositoryInterface`

```php
namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\TechnicianAbsence;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;

interface TechnicianAbsenceRepositoryInterface
{
    public function create(
        int $technicianId,
        AbsenceReason $reason,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string $notes,
        int $createdBy
    ): TechnicianAbsence;

    public function findById(int $id): ?TechnicianAbsence;

    /**
     * @return list<TechnicianAbsence>
     */
    public function findAll(?int $technicianId = null, ?string $status = null): array;

    public function findActiveAbsenceForTechnician(int $technicianId, ?DateTimeImmutable $referenceTime = null): ?TechnicianAbsence;

    public function hasOverlappingAbsence(int $technicianId, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, ?int $excludeAbsenceId = null): bool;

    public function cancel(int $id, int $cancelledBy, string $cancellationReason): bool;
}
```

### 3.2. `TechnicianShiftRepositoryInterface`

```php
namespace VendGuard\Core\Domain\Repository;

use DateTimeImmutable;
use VendGuard\Core\Domain\Model\TechnicianShift;

interface TechnicianShiftRepositoryInterface
{
    public function findActiveShiftByTechnician(int $technicianId): ?TechnicianShift;

    public function startShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift;

    public function pauseMeal(int $shiftId, ?DateTimeImmutable $pausedAt = null): TechnicianShift;

    public function resumeShift(int $shiftId, ?DateTimeImmutable $resumedAt = null): TechnicianShift;

    public function closeShift(int $shiftId, int $closedBy, ?string $closeReason = null, ?DateTimeImmutable $closedAt = null): TechnicianShift;

    /**
     * @return array<int, string> technicianId => shift_status ('ON_SHIFT'|'MEAL_BREAK'|'OFF_SHIFT')
     */
    public function getActiveShiftsMap(): array;
}
```

---

## 4. Servicios de Aplicación y Reglas de Negocio

### 4.1. `ShiftHandoverService` (Caso de Uso de Relevo y Cierre)

Ejecuta el protocolo de relevo de fin de turno y cierre de jornada dentro de una transacción PDO atómica (`BEGIN ... COMMIT`):

1. **Precondición de Integridad (RF-04):** Comprueba si el técnico tiene averías en `IN_PROGRESS`.
   * Si `count(IN_PROGRESS) > 0`: Lanza `HasInProgressIncidentsException` (HTTP 422).
2. **Relevo Explícito de Averías (RF-05):** Si se envía `target_technician_id`:
   * Valida la disponibilidad del receptor antes de abrir la transacción de escritura: técnico activo, rol `TECHNICIAN`, turno abierto en `ON_SHIFT` o, sin turno, guardia 24/7 (`is_on_call = TRUE`) sin ausencia activa; una ausencia sin turno, una pausa de comida o `OFF_SHIFT` sin guardia lo inhabilitan. Error: `422 TARGET_TECHNICIAN_UNAVAILABLE`.
   * `target_technician_id` informado con `incident_ids` vacío: `422 TARGET_WITHOUT_TRANSFERS`.
   * Bloquea con `SELECT ... FOR UPDATE` los tickets solicitados (`incident_ids`).
   * Valida que pertenezcan al técnico y su estado esté en `['ASSIGNED', 'PENDING_PARTS', 'PENDING_INFO']`.
   * Si algún ticket cambió o no es elegible: `409 CONFLICT` (`CONCURRENT_ROUTE_MODIFICATION`).
   * Reasigna cada ticket al receptor llamando a `IncidentRepository::assign()`.
   * Añade en cada ticket un comentario interno (`is_internal = TRUE`):
     `"Traspaso de fin de turno de [Técnico Saliente] a [Técnico Receptor]: [Notas]"` (Art. V.4).
   * Encola una notificación en `notification_outbox` con plantilla `TELEGRAM_INCIDENT_ASSIGNMENT` dirigida al receptor (Módulo 15), con prioridad `PRIORITY` (por delante de las asignaciones ordinarias y por detrás de `CRITICAL_SANITARY`). Se emite por correo y, si el técnico está vinculado, también por Telegram.
3. **Cierre sin Relevo y Protocolo Sanitario de Frío (RF-06 / Art. II):**
   * Las averías comerciales ordinarias (`ASSIGNED`, `PENDING_PARTS`, `PENDING_INFO`) se retienen en el técnico saliente para el día siguiente.
   * Para cada avería sobre máquina perecedera (`FOOD_PERISHABLE`):
     * **Nivel 1:** Si hay un técnico activo con `is_on_call = TRUE` **y disponible** (sin pausa de comida y sin ausencia activa salvo que esté en `ON_SHIFT`), se le traspasa automáticamente con nota interna del sistema y alerta Telegram. Encola el mismo aviso propio y prioritario de relevo que el traspaso manual (`TELEGRAM_INCIDENT_ASSIGNMENT`, prioridad `PRIORITY`), por correo y por Telegram si el guardia está vinculado; nunca el aviso ordinario `TECHNICIAN_ASSIGNED`. Se excluye al técnico saliente y se prefieren los guardias en turno.
     * **Nivel 2:** Si no hay ningún guardia disponible, se desasigna pasando a `REGISTERED` y se emite alerta crítica al canal de Coordinación. La auditoría del evento registra `fallback_reason = 'NO_AVAILABLE_ON_CALL_TECHNICIAN'`.
4. **Cierre de Turno:**
   * Actualiza el registro en `technician_shifts` con `status = 'OFF_SHIFT'`, `closed_at = CURRENT_TIMESTAMP`, `closed_by = :userId`.
   * Inserta evento `CLOSE_TECHNICIAN_SHIFT` o `SHIFT_HANDOVER_REASSIGNMENT` en `audit_log` (Art. III.3).

---

## 5. Contratos de Interfaz HTTP REST

### 5.1. Matriz de Endpoints y Precedencia de Errores (Fail-Fast)

#### `POST /api/technician/shift/close-handover`
1. Middleware de autenticación (`401 UNAUTHORIZED`).
2. Usuario no es `TECHNICIAN` (`403 FORBIDDEN`).
3. Turno no existe o ya está en `OFF_SHIFT` (`422 SHIFT_ALREADY_CLOSED`).
4. Existen averías en `IN_PROGRESS` (`422 HAS_IN_PROGRESS_INCIDENTS`).
5. `target_technician_id` especificado pero inexistente o inactivo (`404 TARGET_TECHNICIAN_NOT_FOUND`).
6. `target_technician_id` es el mismo técnico saliente (`422 INVALID_TARGET_TECHNICIAN`).
7. `target_technician_id` informado con `incident_ids` vacío (`422 TARGET_WITHOUT_TRANSFERS`).
8. `incident_ids` no vacío sin `target_technician_id` (`422 TARGET_TECHNICIAN_REQUIRED`).
9. Receptor no disponible (ausente sin turno, en pausa o `OFF_SHIFT` sin guardia) (`422 TARGET_TECHNICIAN_UNAVAILABLE`). El mensaje es genérico y no revela el motivo ni las fechas de la ausencia.
10. `incident_ids` contiene tickets inexistentes o no asignados al técnico (`409 CONCURRENT_ROUTE_MODIFICATION`).
11. `handover_notes` menor de 10 caracteres reales habiendo tickets a traspasar (`422 MISSING_HANDOVER_NOTES`).
12. Éxito: `200 OK` con JSON resumen de averías traspasadas, retenidas y derivadas a guardia. `target_technician_id: null` + `incident_ids: []` es un cierre sin relevo válido.

#### `POST /api/technician/shift/pause-meal`
1. Middleware de autenticación (`401 UNAUTHORIZED`).
2. Usuario no es `TECHNICIAN` (`403 FORBIDDEN`).
3. Sin turno abierto (`409 NO_OPEN_SHIFT`).
4. Turno ya en `MEAL_BREAK` (`422 SHIFT_ALREADY_PAUSED`).
5. Éxito: `200 OK` con el turno en `MEAL_BREAK`.

#### `POST /api/technician/shift/resume`
1. Middleware de autenticación (`401 UNAUTHORIZED`).
2. Usuario no es `TECHNICIAN` (`403 FORBIDDEN`).
3. Sin turno abierto (`409 NO_OPEN_SHIFT`).
4. Turno en `ON_SHIFT`, sin pausa que reanudar (`422 SHIFT_NOT_IN_MEAL_BREAK`).
5. Éxito: `200 OK` con el turno en `ON_SHIFT`.

#### `GET /api/technician/handover-targets`
1. Middleware de autenticación interno (`401 UNAUTHORIZED`).
2. Usuario no es `TECHNICIAN` (`403 FORBIDDEN`).
3. Éxito: `200 OK` con la lista de técnicos activos distintos del solicitante, ordenada por disponibilidad (`AVAILABLE`, `ON_CALL`, `OFF_SHIFT`, `MEAL_BREAK`, `UNAVAILABLE`) y después por nombre.
4. Cada fila contiene `id`, `name`, `is_active`, `shift_status` (`ON_SHIFT`|`MEAL_BREAK`|`OFF_SHIFT`), `is_on_call`, `availability`, `availability_label` e `is_available_for_handover`; nunca email, teléfono, identificador de Telegram, motivo de ausencia ni fechas de ausencia.
5. Los campos nuevos son aditivos para clientes antiguos, salvo el rechazo de receptores no disponibles, que es un cambio deliberado del contrato de `close-handover`.

#### `POST /api/coordinator/technicians/{id}/absences`
1. Middleware de autenticación interno (`401 UNAUTHORIZED`).
2. Usuario no es `COORDINATOR` o `ADMIN` (`403 FORBIDDEN`).
3. `{id}` inexistente o no es `TECHNICIAN` (`404 TECHNICIAN_NOT_FOUND`).
4. `reason` no pertenece a `AbsenceReason` (`422 INVALID_ABSENCE_REASON`).
5. `starts_at` o `ends_at` inválidos o `ends_at < starts_at` (`422 INVALID_DATE_RANGE`).
6. `notes` supera los 500 caracteres (`422 NOTES_TOO_LONG`).
7. Solapamiento con ausencia activa del técnico (`409 ABSENCE_OVERLAP`), tanto en la comprobación previa como si la constraint `ex_technician_absences_no_overlap` rechaza el `INSERT` con `SQLSTATE 23P01`.
8. Éxito: `201 CREATED` con datos de la ausencia y lista `affected_incidents` con recomendación asistida. Cada incidencia incluye `suggested_technician`: `{ id, name, operator_code, total_score, explanation, badges, current_status }`, o `null` si no hay candidatos. El técnico ausente nunca aparece como sugerido.

#### `DELETE /api/coordinator/absences/{id}`
1. Middleware (`401 UNAUTHORIZED` / `403 FORBIDDEN`).
2. `{id}` no existe (`404 ABSENCE_NOT_FOUND`).
3. Ausencia ya cancelada previamente (`409 ABSENCE_ALREADY_CANCELLED`). La segunda petición no vuelve a registrar auditoría.
4. `cancellation_reason` ausente o $< 5$ caracteres (`422 MISSING_CANCELLATION_REASON`).
5. Éxito: `200 OK` `{ "cancelled": true, "cancelled_at": "..." }`.

#### `POST /api/coordinator/technicians/{id}/shifts/force-close`
1. Middleware (`401 UNAUTHORIZED` / `403 FORBIDDEN`).
2. Técnico `{id}` no encontrado (`404 TECHNICIAN_NOT_FOUND`).
3. Técnico no tiene ningún turno abierto (`409 NO_OPEN_SHIFT_TO_CLOSE`).
4. Técnico tiene averías en `IN_PROGRESS` (`422 HAS_IN_PROGRESS_INCIDENTS`).
5. `reason` $< 10$ caracteres (`422 MISSING_FORCE_CLOSE_REASON`).
6. Éxito: `200 OK`, aplica protocolo sanitario y audita `FORCE_CLOSE_TECHNICIAN_SHIFT`.

---

## 6. Eventos de Auditoría (`audit_log`)

Se registran los siguientes eventos inmutables en `audit_log`:

| Evento | Entidad | Descripción |
| :--- | :--- | :--- |
| `CREATE_TECHNICIAN_ABSENCE` | `USER` | Alta de ausencia por Coordinación. |
| `CANCEL_TECHNICIAN_ABSENCE` | `USER` | Cancelación lógica de ausencia con motivo. |
| `START_TECHNICIAN_SHIFT` | `USER` | Inicio de jornada por el técnico. |
| `ABSENCE_OVERRIDE_BY_SHIFT_START` | `USER` | Aviso informativo de inicio de jornada durante ausencia activa. |
| `PAUSE_TECHNICIAN_SHIFT_MEAL` | `USER` | Inicio de pausa de comida. |
| `RESUME_TECHNICIAN_SHIFT` | `USER` | Reanudación tras pausa de comida. |
| `CLOSE_TECHNICIAN_SHIFT` | `USER` | Cierre regular de jornada. |
| `SHIFT_HANDOVER_REASSIGNMENT` | `TICKET` | Reasignación de ticket por relevo de fin de turno con nota interna. |
| `SANITARY_SHIFT_CLOSE_FAILSAFE` | `TICKET` | Traspaso forzoso a guardia o desasignación a `REGISTERED` por Art. II. |
| `FORCE_CLOSE_TECHNICIAN_SHIFT` | `USER` | Cierre forzado administrativo por Coordinación con motivo. |
