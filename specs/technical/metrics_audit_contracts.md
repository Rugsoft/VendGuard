# ESPECIFICACIÓN TÉCNICA · CONTRATOS DE API PARA MÉTRICAS (MTTR) Y AUDITORÍA
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `03-metrics-audit`  
**Documento:** `specs/technical/metrics_audit_contracts.md`  
**Referencia Funcional:** `specs/functional/metrics_audit_spec.md` (RF-01 a RF-06)  
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`) y CSV (`text/csv; charset=utf-8`)  
**Metodología:** SDD (Specification-Driven Development) · Contratos API-First  
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.1, 5.2, 5.3, 5.4, 5.6) y VI  

---

## 1. Visión General de Endpoints del Módulo

| Método | Endpoint | Rol / Acceso | Propósito |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/coordinator/metrics/summary` | Coordinador | Resumen de KPIs globales, alertas de SLA fijas y tendencias de MTTR |
| `GET` | `/api/coordinator/metrics/breakdown` | Coordinador | Desglose multidimensional de MTTR por sede histórica, técnico, categoría de máquina y avería |
| `GET` | `/api/coordinator/metrics/export` | Coordinador | Descarga de informe CSV (UTF-8 con BOM) con las métricas agregadas del filtro |
| `GET` | `/api/coordinator/audit-log` | Coordinador | Consulta paginada y filtrable del registro inmutable de auditoría |
| `GET` | `/api/coordinator/audit-log/export` | Coordinador | Descarga de eventos de auditoría en CSV (máx. 10.000 filas más recientes) |
| `GET` | `/api/technician/my-metrics` | Técnico | Autoconsulta segregada de MTTR personal, tiempo de primera respuesta y volumen |

---

## 2. Definición del Esquema de Datos de Auditoría (`audit_log`)

Para cumplir con el **Artículo III.3**, **Artículo V.1** y el requisito **RF-05**, se define la estructura relacional inmutable (*append-only*):

```sql
CREATE TABLE `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` ENUM('TICKET', 'MACHINE', 'LOCATION') NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `action` VARCHAR(64) NOT NULL,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `user_role` VARCHAR(32) NOT NULL,
  `user_name` VARCHAR(100) NOT NULL,
  `previous_state` JSON NULL DEFAULT NULL,
  `new_state` JSON NOT NULL,
  `metadata` JSON NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
  INDEX `idx_audit_action` (`action`),
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

*Nota:* No existen sentencias `UPDATE` ni `DELETE` autorizadas sobre esta tabla (inviolabilidad de auditoría).

---

## 3. Especificación Detallada de Contratos de API

### 3.1 `GET /api/coordinator/metrics/summary` (KPIs Globales y SLA)

Obtiene las tarjetas de indicadores principales de rendimiento para el rango temporal seleccionado (`RF-01`, `RF-03`).

* **Acceso:** Protegido (`InternalAuthMiddleware(COORDINATOR)`).
* **Parámetros Query:**
  * `period` *(opcional)*: `last_7_days`, `last_30_days` (default), `current_month`, `last_month`, `custom`.
  * `from` *(opcional si period=custom)*: Formato `YYYY-MM-DD` (ej. `2026-09-01`).
  * `to` *(opcional si period=custom)*: Formato `YYYY-MM-DD` (ej. `2026-09-30`).

#### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": {
    "period": {
      "key": "last_30_days",
      "from": "2026-08-24 00:00:00",
      "to": "2026-09-23 23:59:59"
    },
    "kpis": {
      "mttr_global_minutes": 195,
      "mttr_global_formatted": "3h 15m",
      "mttr_global_hours": 3.3,
      "mttr_previous_period_minutes": 220,
      "mttr_trend_percentage": -11.4,
      "total_tickets_created": 48,
      "total_tickets_resolved": 42,
      "resolution_rate_percentage": 87.5,
      "active_backlog": 6,
      "critical_sla_breaches": 1
    },
    "sla_alerts": {
      "perishable_food": {
        "sla_target_hours": 4.0,
        "current_mttr_hours": 2.8,
        "is_breached": false,
        "status": "COMPLIANT"
      },
      "general": {
        "sla_target_hours": 24.0,
        "current_mttr_hours": 3.3,
        "is_breached": false,
        "status": "COMPLIANT"
      }
    }
  }
}
```

#### Respuesta de Muestra Vacía (`200 OK` - EARS 1.6)
```json
{
  "success": true,
  "data": {
    "period": {
      "key": "custom",
      "from": "2026-09-01 00:00:00",
      "to": "2026-09-02 23:59:59"
    },
    "kpis": {
      "mttr_global_minutes": null,
      "mttr_global_formatted": "N/A",
      "mttr_global_hours": null,
      "mttr_previous_period_minutes": null,
      "mttr_trend_percentage": null,
      "total_tickets_created": 0,
      "total_tickets_resolved": 0,
      "resolution_rate_percentage": 0.0,
      "active_backlog": 6,
      "critical_sla_breaches": 0
    },
    "sla_alerts": {
      "perishable_food": {
        "sla_target_hours": 4.0,
        "current_mttr_hours": null,
        "is_breached": false,
        "status": "NO_DATA"
      },
      "general": {
        "sla_target_hours": 24.0,
        "current_mttr_hours": null,
        "is_breached": false,
        "status": "NO_DATA"
      }
    }
  }
}
```

---

### 3.2 `GET /api/coordinator/metrics/breakdown` (Desglose Multidimensional)

Proporciona el desglose del MTTR y volumen por dimensiones analíticas combinables (`RF-02`).

* **Acceso:** Protegido (`InternalAuthMiddleware(COORDINATOR)`).
* **Parámetros Query:**
  * `period`, `from`, `to`: Rango temporal (igual a 3.1).
  * `group_by`: `location`, `technician`, `machine_type`, `category`, `urgency` (default: devuelve todas las agrupaciones principales).
  * `location_id` *(opcional)*: Filtra resultados por sede histórica.
  * `technician_id` *(opcional)*: Filtra resultados por técnico resolutor.

#### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": {
    "by_location": [
      {
        "location_id": 1,
        "site_code": "SEDE-BCN-01",
        "location_name": "Hospital del Mar - Edificio Central",
        "is_active": true,
        "tickets_resolved": 28,
        "mttr_minutes": 165,
        "mttr_formatted": "2h 45m",
        "mttr_hours": 2.8,
        "sla_target_hours": 24.0,
        "sla_status": "COMPLIANT"
      },
      {
        "location_id": 2,
        "site_code": "SEDE-BCN-02",
        "location_name": "Torre Glòries - Planta 4 Oficinas",
        "is_active": true,
        "tickets_resolved": 14,
        "mttr_minutes": 255,
        "mttr_formatted": "4h 15m",
        "mttr_hours": 4.3,
        "sla_target_hours": 24.0,
        "sla_status": "COMPLIANT"
      }
    ],
    "by_technician": [
      {
        "technician_id": 2,
        "technician_name": "Jordi Técnico Ruta BCN",
        "is_active": true,
        "tickets_resolved": 42,
        "mttr_minutes": 195,
        "mttr_formatted": "3h 15m",
        "mttr_hours": 3.3
      },
      {
        "technician_id": 5,
        "technician_name": "Carlos Antiguo (Baja)",
        "is_active": false,
        "display_name": "Carlos Antiguo (Inactivo)",
        "tickets_resolved": 0,
        "mttr_minutes": null,
        "mttr_formatted": "N/A",
        "mttr_hours": null
      }
    ],
    "by_machine_type": [
      {
        "machine_type": "PERISHABLE_FOOD",
        "display_name": "Alimentos Perecederos (Sanitario)",
        "is_perishable": true,
        "tickets_resolved": 12,
        "mttr_minutes": 110,
        "mttr_formatted": "1h 50m",
        "mttr_hours": 1.8,
        "sla_target_hours": 4.0,
        "sla_status": "COMPLIANT"
      },
      {
        "machine_type": "HOT_DRINKS",
        "display_name": "Bebidas Calientes",
        "is_perishable": false,
        "tickets_resolved": 15,
        "mttr_minutes": 210,
        "mttr_formatted": "3h 30m",
        "mttr_hours": 3.5,
        "sla_target_hours": 24.0,
        "sla_status": "COMPLIANT"
      },
      {
        "machine_type": "COMBO",
        "display_name": "Snacks y Refrescos (Combo)",
        "is_perishable": false,
        "tickets_resolved": 15,
        "mttr_minutes": 240,
        "mttr_formatted": "4h 00m",
        "mttr_hours": 4.0,
        "sla_target_hours": 24.0,
        "sla_status": "COMPLIANT"
      }
    ],
    "by_category": [
      {
        "category": "TEMPERATURE_COLD",
        "tickets_resolved": 10,
        "mttr_minutes": 105,
        "mttr_formatted": "1h 45m"
      },
      {
        "category": "PAYMENT_SYSTEM",
        "tickets_resolved": 18,
        "mttr_minutes": 230,
        "mttr_formatted": "3h 50m"
      },
      {
        "category": "PRODUCT_JAM",
        "tickets_resolved": 14,
        "mttr_minutes": 180,
        "mttr_formatted": "3h 00m"
      }
    ]
  }
}
```

---

### 3.3 `GET /api/coordinator/metrics/export` (Exportación Tabular CSV)

Genera la descarga inmediata de las métricas agregadas en archivo plano CSV con codificación UTF-8 y marca BOM (`RF-06, EARS 6.1`).

* **Acceso:** Protegido (`InternalAuthMiddleware(COORDINATOR)`).
* **Parámetros Query:** Mismos filtros que `summary` y `breakdown`.
* **Cabeceras de Respuesta HTTP:**
  * `Content-Type: text/csv; charset=UTF-8`
  * `Content-Disposition: attachment; filename="vendguard_metrics_2026-08-24_2026-09-23.csv"`

#### Ejemplo de Cuerpo CSV:
```csv
Dimensión,Identificador,Nombre,Activo,Tickets Resueltos,MTTR Minutos,MTTR Formateado,MTTR Horas,SLA Objetivo Horas,Estado SLA
Sede,SEDE-BCN-01,Hospital del Mar - Edificio Central,Sí,28,165,2h 45m,2.8,24.0,COMPLIANT
Sede,SEDE-BCN-02,Torre Glòries - Planta 4 Oficinas,Sí,14,255,4h 15m,4.3,24.0,COMPLIANT
Técnico,2,Jordi Técnico Ruta BCN,Sí,42,195,3h 15m,3.3,N/A,N/A
Tipo Máquina,PERISHABLE_FOOD,Alimentos Perecederos (Sanitario),Sí,12,110,1h 50m,1.8,4.0,COMPLIANT
Tipo Máquina,HOT_DRINKS,Bebidas Calientes,Sí,15,210,3h 30m,3.5,24.0,COMPLIANT
```

---

### 3.4 `GET /api/coordinator/audit-log` (Consulta del Registro Inmutable)

Permite inspeccionar cronológicamente las acciones ejecutadas sobre tickets, máquinas y sedes (`RF-05`).

* **Acceso:** Protegido (`InternalAuthMiddleware(COORDINATOR)`).
* **Parámetros Query:**
  * `page` *(opcional, default 1)*: Número de página.
  * `limit` *(opcional, default 50, máx 100)*: Tamaño de página.
  * `entity_type` *(opcional)*: `TICKET`, `MACHINE`, `LOCATION`.
  * `entity_id` *(opcional)*: ID numérico de la entidad.
  * `action` *(opcional)*: Ej. `RESOLVE_INCIDENT`, `STATUS_CHANGE`, `ASSIGN_TECHNICIAN`.
  * `from` / `to` *(opcional)*: Rango de fechas `YYYY-MM-DD`.

#### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": {
    "page": 1,
    "limit": 50,
    "total_records": 128,
    "total_pages": 3,
    "items": [
      {
        "id": 128,
        "timestamp": "2026-09-23 10:15:30",
        "entity_type": "TICKET",
        "entity_id": 4,
        "action": "RESOLVE_INCIDENT",
        "user": {
          "id": 2,
          "name": "Jordi Técnico Ruta BCN",
          "role": "TECHNICIAN"
        },
        "details": {
          "previous_status": "IN_PROGRESS",
          "new_status": "RESOLVED",
          "diagnosis": "Sensor térmico NTC descalibrado provocando parada preventiva",
          "solution": "Sustitución de sonda NTC y reprogramación de punto de consigna a 3.5ºC",
          "parts_replaced": [
            "Sonda térmica NTC Sanden",
            "Conector estanco IP67"
          ],
          "resolution_time_minutes": 105
        }
      },
      {
        "id": 127,
        "timestamp": "2026-09-23 09:20:00",
        "entity_type": "TICKET",
        "entity_id": 4,
        "action": "START_INTERVENTION",
        "user": {
          "id": 2,
          "name": "Jordi Técnico Ruta BCN",
          "role": "TECHNICIAN"
        },
        "details": {
          "previous_status": "ASSIGNED",
          "new_status": "IN_PROGRESS",
          "first_response_time_minutes": 50
        }
      },
      {
        "id": 126,
        "timestamp": "2026-09-23 08:30:00",
        "entity_type": "MACHINE",
        "entity_id": 1,
        "action": "UPDATE_LOCATION_PHONE",
        "user": {
          "id": 1,
          "name": "Sara Coordinadora",
          "role": "COORDINATOR"
        },
        "details": {
          "previous_phone": "600111222",
          "new_phone": "600999888"
        }
      }
    ]
  }
}
```

---

### 3.5 `GET /api/coordinator/audit-log/export` (Exportación CSV del Audit Log)

Descarga de las entradas del log de auditoría filtradas, con tope de seguridad de 10.000 filas (`RF-06, EARS 6.2`).

* **Acceso:** Protegido (`InternalAuthMiddleware(COORDINATOR)`).
* **Parámetros Query:** Mismos filtros que `audit-log`.
* **Cabeceras HTTP:**
  * `Content-Type: text/csv; charset=UTF-8`
  * `Content-Disposition: attachment; filename="vendguard_audit_log_2026-09-23.csv"`

#### Ejemplo de Cuerpo CSV:
```csv
ID,Fecha y Hora,Entidad,ID Entidad,Acción,Usuario ID,Nombre Usuario,Rol,Estado Previo,Estado Nuevo,Diagnóstico,Solución,Piezas Sustituidas
128,2026-09-23 10:15:30,TICKET,4,RESOLVE_INCIDENT,2,Jordi Técnico Ruta BCN,TECHNICIAN,IN_PROGRESS,RESOLVED,"Sensor térmico NTC descalibrado","Sustitución sonda NTC","Sonda térmica NTC Sanden; Conector estanco IP67"
127,2026-09-23 09:20:00,TICKET,4,START_INTERVENTION,2,Jordi Técnico Ruta BCN,TECHNICIAN,ASSIGNED,IN_PROGRESS,"","",""
```

---

### 3.6 `GET /api/technician/my-metrics` (Autoconsulta Segregada del Técnico)

Permite al técnico autenticado visualizar exclusivamente sus propias métricas individuales de rendimiento (`RF-04`).

* **Acceso:** Protegido (`InternalAuthMiddleware(TECHNICIAN)`). El ID del técnico se obtiene obligatoriamente del token/sesión criptográfica del usuario autenticado; cualquier parámetro manipulado en query es ignorado.
* **Parámetros Query:**
  * `period`: `last_7_days`, `last_30_days` (default), `current_month`.

#### Respuesta Exitosa (`200 OK`)
```json
{
  "success": true,
  "data": {
    "technician": {
      "id": 2,
      "name": "Jordi Técnico Ruta BCN"
    },
    "period": {
      "key": "last_30_days",
      "from": "2026-08-24 00:00:00",
      "to": "2026-09-23 23:59:59"
    },
    "metrics": {
      "my_mttr_minutes": 195,
      "my_mttr_formatted": "3h 15m",
      "my_mttr_hours": 3.3,
      "total_resolved_tickets": 42,
      "current_in_progress_tickets": 2,
      "avg_first_response_minutes": 45,
      "avg_first_response_formatted": "0h 45m"
    }
  }
}
```

#### Respuesta de Intento No Autorizado (`403 Forbidden` - EARS 4.2)
Si un usuario con rol de Técnico intenta invocar cualquier endpoint de coordinador (`/api/coordinator/metrics/*` o `/api/coordinator/audit-log*`), el sistema deniega el acceso:

```json
{
  "success": false,
  "error": {
    "code": "FORBIDDEN",
    "message": "Acceso denegado: Se requieren privilegios de Coordinación para consultar métricas globales o registros de auditoría."
  }
}
```

---

## 4. Códigos de Error Normalizados del Módulo

| Código HTTP | `error.code` | Causa y Condición |
| :--- | :--- | :--- |
| `400 Bad Request` | `INVALID_DATE_RANGE` | Fecha "Hasta" anterior a la fecha "Desde" (`from > to`) o formato inválido. |
| `400 Bad Request` | `INVALID_PERIOD_FILTER` | Valor de parámetro `period` no reconocido. |
| `401 Unauthorized` | `UNAUTHENTICATED` | Ausencia de token de sesión o credencial inválida/expirada. |
| `403 Forbidden` | `FORBIDDEN` | Usuario con rol `TECHNICIAN` intentando acceder a endpoints de Coordinador. |
| `404 Not Found` | `ENTITY_NOT_FOUND` | `location_id` o `technician_id` especificado en filtro no existe. |
| `422 Unprocessable`| `AUDIT_IMMUTABLE_VIOLATION`| Intento de alteración, borrado o truncado del registro de auditoría. |
