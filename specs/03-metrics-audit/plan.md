# PLAN DE IMPLEMENTACIÓN TÉCNICA · MÉTRICAS (MTTR) Y AUDITORÍA OPERATIVA
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `03-metrics-audit`  
**Documento:** `specs/03-metrics-audit/plan.md`  
**Referencia Funcional:** [`specs/functional/metrics_audit_spec.md`](../functional/metrics_audit_spec.md) (RF-01 a RF-06, RNF-01 a RNF-05)  
**Contratos Técnicos:** [`specs/technical/metrics_audit_contracts.md`](../technical/metrics_audit_contracts.md)  
**Normativa Suprema:** [constitution.md](../../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [AGENTS.md](../../AGENTS.md) (SDD, Dogma Vanilla, Dualismo Lingüístico)  

---

## 1. Estructura de Módulos y Ficheros

Siguiendo Clean Architecture, el Dogma Vanilla (PHP 8.2+ POO estricto sin dependencias de Composer, frontend Vue 3 en ES Modules sin herramientas de compilación/npm en producción) y el Dualismo Lingüístico (código en inglés camelCase/PascalCase, comentarios y UI en castellano):

```text
gestor-incidencias-vending/
├── database/
│   ├── cloud_init.sql                          # Actualización con tabla audit_log e índices
│   └── migrations/
│       └── 003_create_audit_log_table.sql      # Script DDL idempotente de migración
├── src/
│   ├── Core/
│   │   ├── Domain/
│   │   │   ├── Model/
│   │   │   │   ├── AuditEvent.php              # Entidad inmutable de registro de auditoría
│   │   │   │   ├── MetricFilter.php            # Value Object para rango de fechas y filtros
│   │   │   │   ├── MttrMetric.php              # Value Object matemático para cálculo y formato MTTR
│   │   │   │   └── KpiSummary.php              # DTO con resumen global, tendencias y SLAs
│   │   │   └── Repository/
│   │   │       ├── AuditLogRepositoryInterface.php  # Contrato inmutable append-only
│   │   │       └── MetricsRepositoryInterface.php   # Contrato para agregaciones analíticas
│   ├── Application/
│   │   └── Service/
│   │       ├── AuditLogger.php                 # Servicio sincrónico de inserción de eventos
│   │       ├── MetricsCalculationService.php   # Lógica analítica de MTTR, tendencias y SLA
│   │       └── MetricsExportService.php        # Generador de archivos planos CSV con BOM
│   ├── Infrastructure/
│   │   └── Persistence/
│   │       ├── PdoAuditLogRepository.php       # Persistencia en BD MySQL/TiDB (append-only)
│   │       └── PdoMetricsRepository.php        # Consultas de agregación SQL optimizadas
│   └── Presentation/
│       ├── Controller/
│       │   ├── CoordinatorMetricsController.php # GET /api/coordinator/metrics/* y audit-log
│       │   └── TechnicianMetricsController.php  # GET /api/technician/my-metrics
│       └── Routing/
│           └── AppRouter.php                   # Registro de rutas protegidas de métricas
├── public/
│   └── assets/
│       ├── css/
│       │   └── metrics-print.css               # Estilos de impresión para informe ejecutivo/PDF
│       └── js/
│           ├── components/
│           │   ├── MetricCards.js              # Tarjetas de KPIs (MTTR, tendencia, backlog, SLA)
│           │   ├── MetricBreakdownTable.js     # Tablas multidimensionales (sedes, perecederos)
│           │   ├── AuditLogViewer.js           # Visor cronológico paginado del log inmutable
│           │   └── ExecutiveReportModal.js     # Modal maquetado de informe ejecutivo imprimible
│           └── views/
│               ├── CoordinatorMetricsView.js   # Vista principal de analítica para Coordinador
│               └── TechnicianMetricsView.js    # Panel privado "Mis Métricas" para Técnico
└── tests/
    ├── unit/
    │   ├── MttrMetricTest.php                  # Test unitario del cálculo y formateo de MTTR
    │   ├── MetricsCalculationServiceTest.php   # Test de KPIs, tendencias, SLAs y casos vacíos N/A
    │   ├── AuditLoggerTest.php                 # Test de inmutabilidad y captura de eventos (Art. III/V)
    │   └── MetricCardsTest.mjs                 # Test unitario JS (Node ESM) para componentes
    └── integration/
        ├── CoordinatorMetricsEndpointTest.php  # Test de integración HTTP endpoints coordinador
        ├── TechnicianMetricsEndpointTest.php   # Test de segregación y seguridad (Art. V.4)
        └── MetricsExportEndpointTest.php       # Test de descarga CSV UTF-8 con BOM
```

---

## 2. Esquema de Base de Datos y Modelo Relacional

### 2.1 Tabla Inmutable `audit_log` (DDL MySQL 8.0 / MariaDB / TiDB)
Dando estricto cumplimiento a los **Artículos III.1, III.3 y V.1** de la Constitución:

```sql
CREATE TABLE IF NOT EXISTS `audit_log` (
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

### 2.2 Índices Analíticos en la Tabla `incidents`
Para garantizar el requisito no funcional **RNF-02** (consultas analíticas < 1.5s en volúmenes históricos), se verifica la existencia de índices compuestos optimizados para agregaciones temporales:

```sql
-- Índice para filtrado temporal por resolución y cálculo de MTTR (RF-01, EARS 1.2)
ALTER TABLE `incidents` ADD INDEX `idx_incidents_resolved_mttr` (`status`, `resolved_at`, `created_at`);

-- Índice para desglose por sede histórica y técnico resolutor (RF-02)
ALTER TABLE `incidents` ADD INDEX `idx_incidents_metrics_breakdown` (`location_id`, `assigned_technician_id`, `status`, `resolved_at`);
```

---

## 3. Integración de Contratos de API REST

Todos los endpoints respetan las especificaciones formalizadas en `metrics_audit_contracts.md`:

1. **`GET /api/coordinator/metrics/summary`:**
   - **Autorización:** `InternalAuthMiddleware(UserRole::COORDINATOR)`.
   - **Procesamiento:** Calcula el MTTR global en minutos, su formateo ("Xh Ym"), horas decimales, variación porcentual frente al periodo equivalente anterior, tasa de resolución % y alertas de SLA fijas (4h perecederos, 24h general). Devuelve `N/A` ante muestra vacía.
2. **`GET /api/coordinator/metrics/breakdown`:**
   - **Autorización:** `InternalAuthMiddleware(UserRole::COORDINATOR)`.
   - **Procesamiento:** Devuelve los bloques `by_location` (asociado a la sede histórica inmutable), `by_technician` (atribuido al resolutor final), `by_machine_type` (destacando perecederos sanitarios) y `by_category`.
3. **`GET /api/coordinator/metrics/export`:**
   - **Autorización:** `InternalAuthMiddleware(UserRole::COORDINATOR)`.
   - **Respuesta:** Archivo CSV plano con cabecera `Content-Type: text/csv; charset=UTF-8` y secuencia inicial de bytes UTF-8 BOM (`\xEF\xBB\xBF`) para compatibilidad directa con Excel.
4. **`GET /api/coordinator/audit-log` y `/export`:**
   - **Autorización:** `InternalAuthMiddleware(UserRole::COORDINATOR)`.
   - **Respuesta:** Paginación de 50 en 50 registros para consulta interactiva y exportación CSV acotada a las 10.000 filas más recientes (`EARS 6.2`).
5. **`GET /api/technician/my-metrics`:**
   - **Autorización:** `InternalAuthMiddleware(UserRole::TECHNICIAN)`.
   - **Segregación Estricta:** Extrae el `user_id` del token autenticado. Cualquier intento de consulta sobre otros usuarios o acceso a rutas de coordinador genera un `403 Forbidden` (`EARS 4.2`).

---

## 4. Algoritmos Clave en Pseudocódigo

### 4.1 Algoritmo de Cálculo Determinista de MTTR y KPIs (Tiempo Natural 24/7)

```text
ALGORITMO CalcularMttr(ticketsResueltos):
    SI ticketsResueltos ESTÁ VACÍO ENTONCES:
        RETORNAR MttrMetric(
            minutos = NULL,
            formato = "N/A",
            horasDecimales = NULL
        )
    FIN SI

    minutosTotales = 0
    conteoValido = 0

    PARA CADA ticket EN ticketsResueltos HACER:
        // Exclusión estricta de cancelados y duplicados (EARS 1.4)
        SI ticket.status ES 'CANCELLED' O ticket.status ES 'DUPLICATE' ENTONCES:
            CONTINUAR
        FIN SI

        // Cálculo continuo 24/7 entre creación y primer paso a RESOLVED/CLOSED (EARS 1.1)
        creado = ParsearFecha(ticket.created_at)
        resuelto = ParsearFecha(ticket.first_resolved_at) // Inmutable ante reaperturas (EARS 1.3)

        diferenciaMinutos = MinutosEntre(creado, resuelto)

        // Protección ante anomalía horaria (EARS 1.7)
        SI diferenciaMinutos < 0 ENTONCES:
            diferenciaMinutos = 0
            RegistrarAdvertencia("Inconsistencia horaria en ticket: " + ticket.id)
        FIN SI

        minutosTotales = minutosTotales + diferenciaMinutos
        conteoValido = conteoValido + 1
    FIN PARA

    SI conteoValido == 0 ENTONCES:
        RETORNAR MttrMetric(minutos = NULL, formato = "N/A", horasDecimales = NULL)
    FIN SI

    mttrMinutos = Redondear(minutosTotales / conteoValido)
    horas = mttrMinutos DIV 60
    restoMinutos = mttrMinutos MOD 60
    formato = horas + "h " + restoMinutos + "m"
    horasDecimales = Redondear(mttrMinutos / 60.0, 1)

    RETORNAR MttrMetric(minutos = mttrMinutos, formato = formato, horasDecimales = horasDecimales)
FIN ALGORITMO
```

### 4.2 Algoritmo de Variación de Tendencia frente al Periodo Anterior

```text
ALGORITMO CalcularTendencia(mttrActual, mttrAnterior):
    SI mttrActual.minutos ES NULL O mttrAnterior.minutos ES NULL O mttrAnterior.minutos == 0 ENTONCES:
        RETORNAR NULL
    FIN SI

    variacion = ((mttrActual.minutos - mttrAnterior.minutos) / mttrAnterior.minutos) * 100.0
    RETORNAR Redondear(variacion, 1) // Negativo indica mejora en MTTR (reducción de tiempo)
FIN ALGORITMO
```

### 4.3 Algoritmo de Inserción Atómica en el Registro de Auditoría

```text
ALGORITMO RegistrarEventoAuditoria(pdo, entidadTipo, entidadId, accion, usuario, estadoPrevio, nuevoEstado, metadatos):
    // Inserción síncrona en la misma transacción o conexión (RNF-03)
    sentencia = "INSERT INTO audit_log 
                 (entity_type, entity_id, action, user_id, user_role, user_name, previous_state, new_state, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"

    // Si la acción es RESOLVE_INCIDENT, forzar la presencia de diagnóstico, solución y piezas (Art. III.3, V.1)
    SI accion == 'RESOLVE_INCIDENT' ENTONCES:
        ASEGURAR(nuevoEstado.diagnosis NO ESTÁ VACÍO, "El diagnóstico es obligatorio por Constitución Art. V.1")
        ASEGURAR(nuevoEstado.solution NO ESTÁ VACÍO, "La solución es obligatoria por Constitución Art. V.1")
    FIN SI

    pdo.Ejecutar(sentencia, [
        entidadTipo,
        entidadId,
        accion,
        usuario.id,
        usuario.role,
        usuario.name,
        JsonEncode(estadoPrevio),
        JsonEncode(nuevoEstado),
        JsonEncode(metadatos)
    ])
FIN ALGORITMO
```

---

## 5. Arquitectura de Componentes Frontend Vanilla

Siguiendo el estándar de la aplicación (Vue 3 Composition API cargado nativamente mediante `<script type="module">` sin build tools):

1. **`CoordinatorMetricsView.js`:**
   - Controlador de estado reactivo (`selectedPeriod`, `dateRange`, `activeTab`, `filters`).
   - Carga paralela de `summary` y `breakdown` con indicador visual de carga.
   - Pestañas de navegación interna:
     - **Visión General y KPIs:** Renderiza `MetricCards.js` y gráficas/tablas de SLA.
     - **Desglose Multidimensional:** Renderiza `MetricBreakdownTable.js` con filtros por sede, técnico y perecederos.
     - **Pistas de Auditoría:** Renderiza `AuditLogViewer.js` con paginación interactiva.
   - Botón de acción: *"Exportar CSV"* y *"Generar Informe Ejecutivo"*.
2. **`MetricCards.js`:**
   - Presentación de métricas clave con códigos de color de contraste:
     - MTTR Global con etiqueta de tendencia verde (si baja) o roja (si sube).
     - Tarjeta de Seguridad Alimentaria (Perecederos): Resaltado prioritario con badge de SLA (4h).
     - Tasa de resolución % y Backlog activo.
3. **`ExecutiveReportModal.js` y `metrics-print.css`:**
   - Modal que maqueta un informe limpio de gestión.
   - Al pulsar *"Imprimir / Guardar PDF"*, activa `window.print()` ocultando barras laterales, cabeceras web y botones interactivos, forzando saltos de página limpios (`page-break-inside: avoid`).
4. **`TechnicianMetricsView.js`:**
   - Panel minimalista y privado para técnicos de ruta.
   - Muestra exclusivamente: MTTR individual, tickets resueltos, avisos en curso y tiempo medio de primera respuesta.

---

## 6. Decisiones Técnicas Justificadas y Alternativas Descartadas

| Decisión Técnica Adoptada | Justificación Técnica | Alternativa Descartada y Motivo |
| :--- | :--- | :--- |
| **Cálculo analítico en SQL nativo y PHP puro (Dogma Vanilla)** | Los agregados de MTTR y filtros por rango se resuelven en milisegundos mediante índices B-Tree en MySQL/TiDB y PHP 8.2 sin dependencias externas. | **Motor externo de BI / ElasticSearch:** Descartado por violar el Artículo IV (cero bloatware) y añadir sobrecostes de infraestructura injustificados para un MVP. |
| **Tabla `audit_log` con columnas JSON (`previous_state`, `new_state`)** | Permite registrar esquemas heterogéneos de cambios (tickets con diagnósticos/repuestos vs cambios de teléfono en sedes) con inmutabilidad estricta. | **Triggers de base de datos:** Descartados por opacidad en despliegues cloud distribuidos (TiDB) y dificultad de prueba unitaria. |
| **Generación de CSV nativo con BOM UTF-8 (`\xEF\xBB\xBF`)** | Garantiza que tildes y caracteres en español se abran perfectamente en Microsoft Excel en Windows sin necesidad de librerías externas. | **Exportación directa a XLSX / PhpSpreadsheet:** Descartado por requerir Composer y decenas de megabytes de dependencias bloatware. |
| **Informe Ejecutivo vía `@media print` CSS nativo del navegador** | Aprovecha el motor de renderizado del navegador del usuario para generar PDFs vectoriales limpios sin librerías pesadas en backend. | **Generadores headless como Dompdf/Wkhtmltopdf:** Descartados por requerir binarios externos o librerías de terceros prohibidas por el Dogma Vanilla. |

---

## 7. Estrategia de Pruebas Automatizadas

Se aplicará la estrategia de pruebas rigurosa del repositorio (`tests/run_all.php`):

### 7.1 Pruebas Unitarias Backend (PHP puro)
* **`tests/unit/MttrMetricTest.php`:**
  - Validación del cálculo en minutos, formato legible ("2h 30m") y horas decimales.
  - Validación de muestra vacía (retorno estricto de `N/A` y valores numéricos `null`).
  - Validación de exclusión de tickets cancelados y protección ante fechas inconsistentes (`resolved < created`).
* **`tests/unit/MetricsCalculationServiceTest.php`:**
  - Validación de cálculo de tasa de resolución % y cálculo de tendencias porcentuales.
  - Verificación de alertas de SLA (evaluación contra 4h en perecederos y 24h general).
* **`tests/unit/AuditLoggerTest.php`:**
  - Verificación de inserción de eventos de tickets, sedes y máquinas.
  - Comprobación de excepción y rechazo si al resolver una avería falta el diagnóstico o solución (Art. V.1).
  - Comprobación de que no existen métodos de borrado ni edición en el repositorio de auditoría.

### 7.2 Pruebas Unitarias Frontend (Node.js ESM)
* **`tests/unit/MetricCardsTest.mjs`:**
  - Validación de formateo visual de métricas `N/A`.
  - Validación de aplicación de clases CSS de alerta según estado de SLA (`COMPLIANT` vs `BREACHED`).

### 7.3 Pruebas de Integración HTTP
* **`tests/integration/CoordinatorMetricsEndpointTest.php`:**
  - Invocación de `GET /api/coordinator/metrics/summary` y `breakdown` verificando payloads JSON exactos.
  - Validación de filtros por rango de fechas predeterminado y personalizado.
* **`tests/integration/TechnicianMetricsEndpointTest.php`:**
  - Invocación de `GET /api/technician/my-metrics` verificando datos propios.
  - Verificación de respuesta `403 Forbidden` si el técnico intenta invocar `/api/coordinator/metrics/*` o `/api/coordinator/audit-log*`.
* **`tests/integration/MetricsExportEndpointTest.php`:**
  - Validación de cabeceras HTTP de descarga CSV y presencia del BOM UTF-8 en el inicio del stream.
  - Comprobación del límite de seguridad de 10.000 registros en el log de auditoría.

---

## 8. Mapeo de Trazabilidad con Requisitos

| Requisito | Cláusulas EARS Cubiertas | Componentes y Servicios Implicados | Pruebas Asociadas |
| :--- | :--- | :--- | :--- |
| **RF-01 (Cálculo MTTR)** | EARS 1.1 a 1.8 | `MttrMetric.php`, `MetricsCalculationService.php` | `MttrMetricTest.php` |
| **RF-02 (Filtros y Desglose)** | EARS 2.1 a 2.6 | `PdoMetricsRepository.php`, `CoordinatorMetricsController.php` | `CoordinatorMetricsEndpointTest.php` |
| **RF-03 (KPIs Coordinador)**| EARS 3.1, 3.2 | `KpiSummary.php`, `MetricCards.js`, `CoordinatorMetricsView.js` | `MetricsCalculationServiceTest.php` |
| **RF-04 (Panel Técnico)** | EARS 4.1 a 4.3 | `TechnicianMetricsController.php`, `TechnicianMetricsView.js` | `TechnicianMetricsEndpointTest.php` |
| **RF-05 (Audit Log Inmutable)**| EARS 5.1 a 5.5 | `AuditLogger.php`, `PdoAuditLogRepository.php`, `AuditLogViewer.js` | `AuditLoggerTest.php` |
| **RF-06 (Exportación y PDF)**| EARS 6.1 a 6.3 | `MetricsExportService.php`, `ExecutiveReportModal.js`, `metrics-print.css` | `MetricsExportEndpointTest.php` |
| **RNF-01 a RNF-05** | Todas | Arquitectura Vanilla, Middleware de Autenticación, Índices SQL | Suite global `tests/run_all.php` |

---

## 9. Garantía de Dogma Vanilla y Dualismo Lingüístico

* **Dogma Vanilla:**
  - Backend: Cero librerías externas en `composer.json`. Todo el cálculo matemático, procesamiento de fechas, generación de CSV y formateo se implementa con funciones nativas de PHP 8.2+.
  - Frontend: Vue 3 estándar cargado como módulo ES en el navegador (`import { ref, computed } from '/assets/js/vendor/vue.esm-browser.prod.js'`). Cero empaquetadores (sin Vite ni Webpack en ejecución de producción).
* **Dualismo Lingüístico:**
  - Código: Nombres de clases, métodos, variables, rutas de API y tablas en inglés (`MetricsCalculationService`, `getSummary`, `audit_log`, `resolved_at`).
  - Documentación y Textos de Interfaz: Comentarios de código, especificaciones en `specs/`, mensajes de error y etiquetas del panel de usuario en castellano riguroso.
