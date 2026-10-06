# ESPECIFICACIÓN TÉCNICA · CONTRATO DE API DEL DETALLE DE ORDEN PREVENTIVA

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `05-preventive-maintenance` (ampliación) · patrón espejo de `09-incident-detail-modal`  
**Documento:** `specs/technical/preventive_order_detail_contracts.md`  
**Referencia Funcional:** [`specs/functional/preventive_order_detail_modal_spec.md`](../functional/preventive_order_detail_modal_spec.md) (RF-PD-01 a RF-PD-10, RNF-PD-01 a RNF-PD-07)  
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`)  
**Metodología:** SDD (Specification-Driven Development) · Contratos API-First  
**Conformidad Constitucional:** Art. I (SDD), Art. II (semáforo sanitario), Art. III (lectura pura), Art. IV (Dogma Vanilla), Art. V.4 (privacidad del operador), Art. VI (alcance acotado)

---

## 1. Endpoint

| Propiedad | Valor |
| --- | --- |
| **Método** | `GET` |
| **Ruta** | `/api/coordinator/preventive/orders/{id}/detail` |
| **Autenticación** | `InternalAuthMiddleware(COORDINATOR)` (Bearer interno de coordinación) |
| **Identificador** | ID primario positivo **o** código de orden (`PREV-2026-0001`, `ORD-PREV-2026-0003`) |
| **Escrituras** | Ninguna. GET idempotente y no destructivo (Art. III, RNF-PD-04) |
| **Peticiones por apertura** | Una única lectura agregada (RNF-PD-01) |
| **Reutilización de repositorios** | `PreventiveOrderRepositoryInterface::findById/findByCode`, `PreventiveItemRepositoryInterface::findByOrderId`, `SanitaryCertificateRepositoryInterface::findLatestByMachineId`, `IncidentRepositoryInterface::findById`, `AuditLogRepositoryInterface::findByEntity` |
| **DDL nuevo** | Ninguno. No procede migración |

### 1.1. Resolución del identificador
* Si `{id}` es numérico y positivo → búsqueda por ID primario.
* Si `{id}` no es numérico → se normaliza (mayúsculas, `#` inicial y espacios eliminados) y se busca por `order_code`.
* Cualquier otro caso → `400 INVALID_PREVENTIVE_ORDER_IDENTIFIER`.

### 1.2. Composición del bloque `audit_trail` (Decisión 12)

Los escritores reales del ciclo preventivo (`CoordinatorPreventiveController`, `TechnicianPreventiveController`, `SanitaryCertificateService`, `PreventiveChecklistEvaluationService` y `PreventiveCoexistenceBridgeService`) registran cada acción preventiva **sobre la máquina auditada** mediante `AuditLogger::logMachineEvent` (`entity_type = 'MACHINE'`); la propia documentación de `AuditLogger::logPreventiveOrderEvent` y la aserción 4.9 de `AuditEntityTypesTest` reservan `PREVENTIVE_ORDER` para el histórico nuevo, que hoy no existe. Por coherencia con el camino de escritura real:

* La cronología se lee **del recorrido `MACHINE` de la máquina de la orden** (`findByEntity('MACHINE', order.machine_id)`).
* Se **filtra** al vocabulario preventivo que los escritores emiten: `CREATE_PREVENTIVE_ORDER`, `ASSIGN_PREVENTIVE_ORDER`, `CLAIM_PREVENTIVE_ORDER`, `START_PREVENTIVE_INSPECTION`, `EVALUATE_PREVENTIVE_CHECKLIST`, `CANCEL_PREVENTIVE_ORDER`, `ISSUE_SANITARY_CERTIFICATE`, `SUSPEND_SANITARY_CERTIFICATE` y `REINSPECTION_COMPLETED`.
* Se **atribuye** a la orden por `metadata.order_code` (que todo escritor de orden almacena) o, equivalentemente, por `metadata.order_id`; los eventos de la misma máquina pertenecientes a otras órdenes y las acciones correctivas quedan fuera.
* Prohibido sembrar o leer bajo `PREVENTIVE_ORDER`: la migración 004 redefine `entity_type` con un enum de cuatro valores y, sin `STRICT_TRANS_TABLES`, cualquier fila sembrada antes queda silenciosamente vaciada a `''`, lo que aborta el arranque en la migración 012.

### 1.3. Respuestas

| Código | Caso | Cuerpo |
| --- | --- | --- |
| `200` | Ficha ensamblada | Envolvente canónica `{ success, data }` con los nueve bloques de §2 |
| `400` | Identificador inválido | `{ success: false, error: { code: 'INVALID_PREVENTIVE_ORDER_IDENTIFIER', message } }` |
| `401` / `403` | Sin sesión o rol distinto de Coordinador | Envolvente de error del middleware de autenticación |
| `404` | Orden inexistente | `{ success: false, error: { code: 'PREVENTIVE_ORDER_NOT_FOUND', message } }` |
| `500` | Fallo interno | Envolvente `INTERNAL_ERROR` de `handleExecution` |

---

## 2. Esquema de respuesta (`data`)

```jsonc
{
  "order": {
    "id": 1,
    "order_code": "ORD-PREV-2026-0001",
    "status": "COMPLETED",
    "status_label": "Completada",
    "order_type": "ROUTINE",
    "order_type_label": "Ordinaria",
    "scheduled_date": "2026-10-05",
    "due_date": "2026-10-06",
    "created_at": "2026-09-22 12:00:00",
    "updated_at": "2026-09-22 12:00:00",
    "started_at": "2026-10-05 09:00:00",
    "completed_at": "2026-10-05 09:35:00",
    "temperature_measured": 3.2,
    "result": "CONFORME",
    "result_label": "Conforme",
    "is_quarantine_triggered": false,
    "notes": "Inspección higiénico-sanitaria inicial conforme.",
    "cancellation_reason": null,
    "linked_incident_id": null
  },
  "location": {
    "id": 1,
    "name": "Hospital del Mar - Edificio Central",
    "site_code": "SEDE-BCN-01",
    "address": "Passeig Marítim 25, Barcelona"
  },
  "machine": {
    "id": 1,
    "code": "VEND-0101",
    "model": "Sanden Vendo G-Drink",
    "machine_type": "PERISHABLE_FOOD",
    "machine_type_label": "Alimentos perecederos (Sándwiches y lácteos frescos)",
    "floor_wing": "Planta Baja - Urgencias",
    "has_perishables": true,
    "sanitary_status": "OK",
    "sanitary_status_label": "Operativa y vigente"
  },
  "technician": {
    "assigned": true,
    "id": 2,
    "name": "Jordi Técnico Ruta BCN",
    "operator_code": "OP-01"
  },
  "validity": {
    "state": "CERRADA",
    "state_label": "Inspección cerrada",
    "days_remaining": null,
    "is_overdue": false,
    "valid_until": "2026-10-06",
    "balance_label": "Inspección completada el 05/10/2026 09:35"
  },
  "checklist": {
    "has_checklist": true,
    "totals": {
      "total": 7,
      "pass": 5,
      "warn": 1,
      "fail": 0,
      "not_applicable": 1,
      "critical_failures": 0
    },
    "compliance_percent": 83,
    "items": [
      {
        "item_code": "TEMPERATURE_READING",
        "item_description": "Temperatura de sonda estabilizada ≤ 4.0 °C en alimentos perecederos",
        "is_critical": true,
        "status": "PASS",
        "status_label": "Conforme",
        "observations": "Sonda estabilizada en 3.2 °C tras espera de régimen.",
        "photo_url": null
      }
    ]
  },
  "linked_incident": {
    "id": 3661,
    "ticket_code": "INC-DEMO-0922",
    "status": "CLOSED",
    "status_label": "Cerrada",
    "urgency": "LOW",
    "urgency_label": "Baja",
    "created_at": "2026-09-22 09:15:00"
  },
  "certificate": {
    "certificate_code": "CERT-2026-0001",
    "inspection_date": "2026-10-05",
    "valid_until": "2026-10-19",
    "temperature_measured": 3.2,
    "result": "CONFORME",
    "result_label": "Conforme",
    "status": "VALID",
    "status_label": "Vigente",
    "inspector": { "name": "Jordi Técnico Ruta BCN", "operator_code": "OP-01" }
  },
  "audit_trail": [
    {
      "action": "ASSIGN_PREVENTIVE_ORDER",
      "action_label": "Asignación de técnico",
      "created_at": "2026-10-04 10:00:00",
      "user_name": "Sara Coordinadora",
      "user_role": "COORDINATOR"
    },
    {
      "action": "EVALUATE_PREVENTIVE_CHECKLIST",
      "action_label": "Finalización de la inspección",
      "created_at": "2026-10-05 09:35:00",
      "user_name": "Jordi Técnico Ruta BCN",
      "user_role": "TECHNICIAN"
    }
  ]
}
```

### 2.1. Semántica de bloques

* **`order`** — Datos de cabecera y resultado de la inspección. `status_label`, `order_type_label` y `result_label` se traducen en servidor (`Dualismo Lingüístico`: contrato en inglés, etiquetas en español).
* **`location` / `machine`** — Contexto del punto de servicio. `has_perishables` es `true` únicamente para `PERISHABLE_FOOD` (Art. II). `sanitary_status` procede del semáforo de la máquina: `OK`, `ATTENTION_REQUIRED`, `EXPIRED`, `QUARANTINE`, `SEASONAL_PAUSE`.
* **`technician`** — `assigned=false` con `id/name/operator_code` nulos cuando la orden está pendiente de asignación.
* **`validity`** — Semáforo derivado en servidor (RF-PD-03.3):
  * `CUARENTENA` si `is_quarantine_triggered` o la máquina está en `QUARANTINE`.
  * `PAUSA_ESTACIONAL` si la máquina está en `SEASONAL_PAUSE`.
  * `CERRADA` si la orden está `COMPLETED` o `CANCELLED` (muestra `balance_label` histórico y `days_remaining = null`).
  * `VENCIDA` si la fecha límite ya expiró sin completar (`is_overdue = true`).
  * `PROXIMA_A_VENCER` si quedan 5 días naturales o menos.
  * `VIGENTE` en el resto de casos.
  * `days_remaining` se calcula por días naturales sobre `due_date` en zona `Europe/Madrid` y solo se publica en órdenes abiertas (es `null` en órdenes cerradas, incluidas las que arrastran cuarentena sanitaria ya declarada).
* **`checklist`** — Respuestas normativas. `compliance_percent` = `pass / (total − not_applicable)` redondeado, y `0` si el denominador es cero: mide la proporción de ítems estrictamente conformes entre los evaluables, mientras que las observaciones (`warn`) y los no conformes se publican aparte en `totals`. `critical_failures` cuenta ítems con `is_critical = true` y estado `FAIL`. `photo_url` es una ruta relativa o `null`.
* **`linked_incident`** — Avería correctiva creada desde el preventivo (Art. V.2). Es `null` cuando no existe vínculo; **no** se infieren incidencias activas de la máquina que no provengan de esta orden.
* **`certificate`** — Último certificado sanitario de la máquina (`findLatestByMachineId`). Es `null` si nunca se emitió. La ficha no emite, descarga ni imprime certificados.
* **`audit_trail`** — Cronología inmutable del ciclo preventivo de la orden, en orden cronológico ascendente. Los escritores del módulo (`CoordinatorPreventiveController`, `TechnicianPreventiveController`, `SanitaryCertificateService`, `PreventiveChecklistEvaluationService` y el puente de coexistencia) registran cada acción preventiva sobre la **máquina auditada** mediante `AuditLogger::logMachineEvent` (`entity_type = 'MACHINE'`, decisión formalizada en la §1), de modo que la cronología de la orden se compone como el recorrido `MACHINE` de su máquina **filtrado** por el vocabulario preventivo (`CREATE_PREVENTIVE_ORDER`, `ASSIGN_PREVENTIVE_ORDER`, `CLAIM_PREVENTIVE_ORDER`, `START_PREVENTIVE_INSPECTION`, `EVALUATE_PREVENTIVE_CHECKLIST`, `CANCEL_PREVENTIVE_ORDER`, `ISSUE_SANITARY_CERTIFICATE`, `SUSPEND_SANITARY_CERTIFICATE`, `REINSPECTION_COMPLETED`) y **atribuido** por el `order_code` que todo escritor de orden almacena en `metadata` (o por `metadata.order_id`, que se acepta como referencia directa equivalente). Los eventos de la misma máquina pertenecientes a otras órdenes y las acciones correctivas de la máquina quedan fuera. Cada acción se traduce a una etiqueta legible; las acciones desconocidas conservan su código técnico para no perder traza.

---

## 3. Trazabilidad de requisitos

| Requisito | Bloque / comportamiento |
| --- | --- |
| RF-PD-01 | Botón "Ver detalle" en la fila + este endpoint |
| RF-PD-02 | `order` y anatomía de cabecera del componente |
| RF-PD-03 | `location`, `machine`, `validity`, `order.cancellation_reason` |
| RF-PD-04 | `technician` |
| RF-PD-05 | `checklist` |
| RF-PD-06 | `order.result`, `order.temperature_measured`, `order.notes`, `order.is_quarantine_triggered` |
| RF-PD-07 | `linked_incident` + evento `open-incident-detail` del componente |
| RF-PD-08 | `certificate` |
| RF-PD-09 | `audit_trail` |
| RF-PD-10 | Cierre por Escape/fondo/botón y estados de carga y error del componente |
| RNF-PD-01 | Lectura agregada única; sin peticiones por bloque |
| RNF-PD-04 | Sin escrituras ni eventos de auditoría en el camino de lectura |
| RNF-PD-05 | `technician.operator_code` y `certificate.inspector.operator_code` |
| RNF-PD-07 | Middleware de Coordinador en la ruta |
| RF-PD-09 (composición) | La cronología se lee del camino `MACHINE` de la máquina de la orden, filtrado por vocabulario preventivo y atribuido por `metadata.order_code` / `metadata.order_id` (Decisión 12, §1) |

---

## 4. Criterios de verificación automatizada

1. `GET` con ID y con código devuelven `200` y los nueve bloques.
2. `GET` con identificador inexistente devuelve `404 PREVENTIVE_ORDER_NOT_FOUND`; con identificador inválido, `400`.
3. Roles Técnico y Responsable de Sede reciben rechazo.
4. Tras varios `GET` consecutivos, los conteos de `preventive_orders`, `preventive_order_items` y `audit_log` permanecen inalterados (RNF-PD-04).
5. Los ítems del checklist se devuelven con su severidad y estado; los contadores y el porcentaje de cumplimiento coinciden con las filas persistidas.
6. El semáforo de `validity` respeta las fronteras de 5 días, el vencimiento, la cuarentena y las órdenes cerradas.
