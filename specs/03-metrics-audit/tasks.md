# PLAN DE TAREAS DE IMPLEMENTACIÓN · MÓDULO DE MÉTRICAS Y AUDITORÍA (TASKS.MD)
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `03-metrics-audit`  
**Documento:** `specs/03-metrics-audit/tasks.md`  
**Referencia Funcional:** [`specs/functional/metrics_audit_spec.md`](../functional/metrics_audit_spec.md)  
**Contratos Técnicos:** [`specs/technical/metrics_audit_contracts.md`](../technical/metrics_audit_contracts.md)  
**Plan Técnico:** [`specs/03-metrics-audit/plan.md`](plan.md)  
**Estimación por Tarea:** 20–30 minutos  
**Regla de Ejecución:** Estricto orden de dependencias; no iniciar una tarea sin completar y verificar sus predecesoras.  

---

## Fase 1: Dominio Matemático y Modelo de Auditoría (Backend Vanilla)

- [x] **T-MET-01: Implementar Value Objects y DTOs del Módulo (`MttrMetric.php`, `MetricFilter.php`, `KpiSummary.php`, `AuditEvent.php`)**
  * **Requisitos:** `RF-01`, `RF-05`, `RNF-01`
  * **Dependencias:** Ninguna
  * **Hecho cuando:** Existen en `src/Core/Domain/Model/` las clases inmutables tipadas `MttrMetric` (formateo Xh Ym, horas decimales, cálculo seguro sin división por cero y estado `N/A`), `MetricFilter`, `KpiSummary` y `AuditEvent` con validaciones estrictas.

- [ ] **T-MET-02: Crear suite de pruebas unitarias para `MttrMetricTest.php`**
  * **Requisitos:** `RF-01` (EARS 1.1, 1.3, 1.4, 1.6, 1.7, 1.8), `RNF-01`
  * **Dependencias:** T-MET-01
  * **Hecho cuando:** La ejecución de `php tests/unit/MttrMetricTest.php` pasa al 100% en verde evaluando cálculo con datos reales, formateo ("3h 15m"), retorno de `N/A` ante cero tickets resueltos, protección ante anomalías de reloj y exclusión de cancelados.

- [ ] **T-MET-03: Crear migración DDL y repositorio de auditoría append-only (`003_create_audit_log_table.sql`, `PdoAuditLogRepository.php`)**
  * **Requisitos:** `RF-05` (EARS 5.3, 5.4), `RNF-03`, Constitución Art. III.1 y III.3
  * **Dependencias:** T-MET-01
  * **Hecho cuando:** La tabla `audit_log` queda creada en base de datos con sus índices correspondientes y `PdoAuditLogRepository` permite insertar y listar eventos cronológicamente, sin exponer ningún método de borrado o actualización física.

- [ ] **T-MET-04: Implementar `AuditLogger.php` y suite de pruebas unitarias `AuditLoggerTest.php`**
  * **Requisitos:** `RF-05` (EARS 5.1, 5.2), Constitución Art. III.3 y Art. V.1
  * **Dependencias:** T-MET-03
  * **Hecho cuando:** `php tests/unit/AuditLoggerTest.php` pasa al 100% en verde, validando que el logger registre eventos de tickets, sedes y máquinas, y lance una excepción impidiendo registrar resoluciones (`RESOLVED`) que carezcan de diagnóstico técnico o solución justificada.

---

## Fase 2: Repositorio Analítico y Servicios de Aplicación

- [ ] **T-MET-05: Implementar consultas de agregación analítica e índices (`PdoMetricsRepository.php`)**
  * **Requisitos:** `RF-01` (EARS 1.2), `RF-02` (EARS 2.1 a 2.6), `RNF-02`
  * **Dependencias:** T-MET-01
  * **Hecho cuando:** `PdoMetricsRepository` implementa consultas SQL nativas filtrando por fecha de resolución (`resolved_at`), calculando diferencias temporales 24/7 y agrupando por sede histórica, técnico resolutor, tipo de máquina (aislando perecederos) y tipo de avería.

- [ ] **T-MET-06: Implementar servicio de cálculo analítico (`MetricsCalculationService.php`)**
  * **Requisitos:** `RF-01`, `RF-02`, `RF-03` (EARS 3.1, 3.2), `RF-04` (EARS 4.3)
  * **Dependencias:** T-MET-05
  * **Hecho cuando:** `MetricsCalculationService` orquesta el cálculo de KPIs (MTTR global, comparativa porcentual de tendencia con periodo previo, tasa de resolución % y evaluación de alertas de SLA de 4h en perecederos y 24h general).

- [ ] **T-MET-07: Crear suite de pruebas unitarias para `MetricsCalculationServiceTest.php`**
  * **Requisitos:** `RF-01`, `RF-03`, `RF-04`
  * **Dependencias:** T-MET-06
  * **Hecho cuando:** `php tests/unit/MetricsCalculationServiceTest.php` pasa al 100% en verde, verificando tendencias, umbrales de alerta de SLA y cálculo del tiempo medio de primera respuesta hasta `IN_PROGRESS`.

- [ ] **T-MET-08: Implementar servicio de exportación tabular CSV con UTF-8 BOM (`MetricsExportService.php`)**
  * **Requisitos:** `RF-06` (EARS 6.1, 6.2)
  * **Dependencias:** T-MET-06, T-MET-03
  * **Hecho cuando:** `MetricsExportService::exportMetricsCsv($data)` y `exportAuditLogCsv($records)` devuelven streams CSV nativos que inician con el BOM `\xEF\xBB\xBF` y respetan el tope de 10.000 filas de seguridad en auditoría.

---

## Fase 3: Controladores REST y Rutas HTTP (API-First)

- [ ] **T-MET-09: Implementar `CoordinatorMetricsController.php` y registrar rutas en `AppRouter.php`**
  * **Requisitos:** `RF-01`, `RF-02`, `RF-03`, `RF-05`, `RF-06`
  * **Dependencias:** T-MET-06, T-MET-08
  * **Hecho cuando:** Los endpoints `GET /api/coordinator/metrics/summary`, `/breakdown`, `/export`, `/audit-log` y `/audit-log/export` están protegidos con `InternalAuthMiddleware(COORDINATOR)` y responden según los contratos técnicos de `metrics_audit_contracts.md`.

- [ ] **T-MET-10: Implementar `TechnicianMetricsController.php` y registrar rutas en `AppRouter.php`**
  * **Requisitos:** `RF-04` (EARS 4.1, 4.2), Constitución Art. V.4
  * **Dependencias:** T-MET-06
  * **Hecho cuando:** El endpoint `GET /api/technician/my-metrics` está protegido con `InternalAuthMiddleware(TECHNICIAN)`, calcula únicamente las métricas del técnico autenticado y responde `403 Forbidden` ante intentos de consultar métricas globales.

- [ ] **T-MET-11: Crear suite de pruebas de integración HTTP (`CoordinatorMetricsEndpointTest.php`, `TechnicianMetricsEndpointTest.php`)**
  * **Requisitos:** `RF-01` a `RF-06`, `RNF-04`
  * **Dependencias:** T-MET-09, T-MET-10
  * **Hecho cuando:** Las suites de integración PHP pasan al 100% en verde, validando respuestas HTTP 200 con filtros reales, descargas CSV con cabeceras correctas y bloqueo de autorización `403` a técnicos.

---

## Fase 4: Componentes Frontend y Vistas Vanilla Vue 3

- [ ] **T-MET-12: Implementar componentes visuales `MetricCards.js` y `MetricBreakdownTable.js`**
  * **Requisitos:** `RF-02`, `RF-03` (EARS 3.1, 3.2)
  * **Dependencias:** T-MET-09
  * **Hecho cuando:** Los componentes renderizan de forma reactiva las tarjetas de KPIs (con badges de SLA y tendencias) y la tabla con desglose por sede, técnico y máquina destacando perecederos sanitarios en amarillo/rojo si superan 4h.

- [ ] **T-MET-13: Implementar visor cronológico `AuditLogViewer.js`**
  * **Requisitos:** `RF-05` (EARS 5.3, 5.5)
  * **Dependencias:** T-MET-09
  * **Hecho cuando:** `AuditLogViewer.js` muestra el listado cronológico de eventos con filtros por entidad/usuario/acción, detalles de cambios en JSON amigable y paginación reactiva.

- [ ] **T-MET-14: Implementar vista ejecutiva imprimible `ExecutiveReportModal.js` y estilos `metrics-print.css`**
  * **Requisitos:** `RF-06` (EARS 6.3)
  * **Dependencias:** T-MET-12
  * **Hecho cuando:** Al pulsar "Generar Informe Ejecutivo" se despliega el resumen ejecutivo y al imprimir (`Ctrl+P` / `window.print()`) se genera una salida limpia sin barras laterales ni botones de acción.

- [ ] **T-MET-15: Integrar vista general `CoordinatorMetricsView.js` y panel privado `TechnicianMetricsView.js`**
  * **Requisitos:** `RF-03`, `RF-04`, `RNF-05`
  * **Dependencias:** T-MET-12, T-MET-13, T-MET-14, T-MET-10
  * **Hecho cuando:** El panel de Coordinación dispone de la nueva sección "📊 Métricas y Auditoría", el panel de Técnico dispone de "📈 Mis Métricas" y la suite `node tests/unit/MetricCardsTest.mjs` pasa al 100% en verde.

---

## Fase 5: Auditoría Constitucional y Despliegue Cloud

- [ ] **T-MET-16: Auditoría Constitucional y Verificación Global de Regresión**
  * **Requisitos:** Artículos I al VII de la Constitución de VendGuard
  * **Dependencias:** T-MET-15
  * **Hecho cuando:** Se ejecuta `php tests/run_all.php` pasando todas las suites de prueba (unitarias PHP, unitarias JS e integración) al 100% en verde con cero fallos, y se verifica el despliegue funcional en producción cloud.
