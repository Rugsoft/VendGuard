# ESPECIFICACIÓN FUNCIONAL · MÉTRICAS DE SERVICIO (MTTR) Y AUDITORÍA OPERATIVA
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `03-metrics-audit`  
**Documento:** `specs/functional/metrics_audit_spec.md`  
**Estado:** Especificación Formal Consolidada y Auditada (Lista para Aprobación Técnica)  
**Metodología:** SDD (Specification-Driven Development) · Notación EARS  
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.1, 5.2, 5.3, 5.4, 5.6) y VI de la Constitución de VendGuard  

---

## 1. Contexto y Objetivo

### 1.1 Contexto
La operación de un parque de máquinas de vending distribuido exige supervisión continua de la calidad y velocidad de respuesta del servicio técnico. El tiempo transcurrido desde que un usuario o sensor reporta una avería hasta que el equipo vuelve a estar operativo impacta directamente en:
1. **La satisfacción y confianza del usuario:** Reduciendo tiempos de retención indebida de dinero o máquinas fuera de servicio.
2. **La seguridad alimentaria (Artículo II de la Constitución):** Las incidencias en máquinas de alimentos frescos perecederos requieren una contención y resolución urgente antes de que se rompa la cadena de frío o caduquen los productos.
3. **El rendimiento del equipo técnico y cumplimiento de acuerdos de nivel de servicio (SLA):** Esencial para identificar cuellos de botella en sedes críticas o tipos específicos de máquinas.

Adicionalmente, para garantizar la rendición de cuentas, la transparencia organizativa y prevenir manipulaciones de datos o disputas operativas, es obligatorio mantener un registro inmutable de auditoría (*Audit Log*) que registre quién, cuándo y qué cambió a lo largo de todo el ciclo de vida de tickets, máquinas y sedes, incluyendo las justificaciones obligatorias de cierre y repuestos utilizados (Artículos III.3 y V.1).

### 1.2 Objetivo
Definir los requisitos funcionales y operativos del sistema para:
1. **Calcular y visualizar de forma determinista el Tiempo Medio de Resolución (MTTR - *Mean Time To Resolve*):** Medido en tiempo natural continuo 24/7 desde la apertura de una incidencia hasta su primera resolución efectiva, filtrado por fecha de resolución, con desgloses multidimensionales.
2. **Proporcionar cuadros de mando diferenciados según el rol:**
   - **Vista de Coordinador:** Análisis global y comparativo por sede histórica, técnico que resolvió, tipología de máquina (con vigilancia sanitaria prioritaria de perecederos) y tipología de avería/prioridad, junto al volumen de backlog y cumplimiento de SLA.
   - **Vista de Técnico (Autoconsulta Segregada):** Panel personal donde el operario evalúa su propio MTTR, volumen de tickets resueltos, tiempo de primera respuesta e incidencias en curso, sin acceder a datos agregados de sus pares ni métricas ajenas.
3. **Ofrecer un Registro de Auditoría Inmutable (*Append-Only*):** Trazabilidad exhaustiva y no manipulable de eventos del ciclo de vida de tickets, modificaciones de parque de máquinas y cambios en sedes.
4. **Facilitar la toma de decisiones mediante exportación:** Descarga tabular estructurada (CSV UTF-8 con BOM acotada a seguridad) para explotación analítica externa e informes ejecutivos de gestión optimizados para formato imprimible / PDF.

---

## 2. Usuarios y Roles

* **Coordinador del Servicio:** Responsable de la supervisión de operaciones, asignación de recursos y control de SLA. Accede al cuadro de mando analítico completo con todos los filtros globales, comparativas entre sedes y técnicos, registro integral de auditoría del sistema y herramientas de exportación general.
* **Técnico Asignado:** Operador de campo encargado de la resolución física o técnica de averías. Accede exclusivamente a su vista personal de rendimiento (*Mis Métricas*) para monitorizar su propio MTTR y tasa de resolución, con estricta segregación de datos para no exponer el rendimiento de otros técnicos ni el log de auditoría global.
* **Usuario Informador (Público):** Sin acceso alguno a métricas, informes ni registros de auditoría interna.

---

## 3. Historias de Usuario

* **HU-MET-01 (Cuadro de Mando Ejecutivo para Coordinador):** *Como* Coordinador, *quiero* consultar el MTTR global y filtrarlo por rangos de fechas de resolución, sedes, técnicos y tipos de máquina *para* identificar cuellos de botella y comprobar el rendimiento operativo del servicio.
* **HU-MET-02 (Priorización y Alerta Sanitaria de Alimentos Perecederos):** *Como* Coordinador, *quiero* visualizar el MTTR desagregado entre máquinas de alimentos perecederos y máquinas no perecederas *para* auditar que se cumpla la urgencia prioritaria exigida por la seguridad alimentaria (umbral objetivo ≤ 4 horas).
* **HU-MET-03 (Autoconsulta de Métricas del Técnico):** *Como* Técnico, *quiero* acceder a un panel privado con mis métricas individuales de resolución y tiempo de primera respuesta *para* conocer mi desempeño histórico sin ver comparativas confidenciales de otros compañeros.
* **HU-MET-04 (Pistas de Auditoría Inmutables):** *Como* Coordinador, *quiero* inspeccionar el historial cronológico de cambios de un ticket o del parque de máquinas *para* verificar qué usuario realizó cada cambio de estado, modificación de prioridad, reasignación o sustitución de repuestos.
* **HU-MET-05 (Exportación Tabular y Reporte Ejecutivo):** *Como* Coordinador, *quiero* exportar las métricas en formato CSV y generar un resumen ejecutivo imprimible/PDF *para* presentar informes periódicos a la dirección o a los clientes de las sedes.

---

## 4. Requisitos Funcionales y Criterios de Aceptación (Notación EARS)

### RF-01: Cálculo Matemático del MTTR (Mean Time To Resolve)
*El sistema calculará el MTTR como la media aritmética del tiempo de resolución de los tickets resueltos dentro del periodo consultado.*

* **EARS 1.1 (Cálculo Base y Cómputo 24/7):** Cuando el sistema compute el MTTR de un conjunto de incidencias, el tiempo de resolución de cada ticket individual deberá calcularse exactamente como la diferencia en minutos naturales continuos (régimen 24/7) entre su marca de tiempo de creación (`created_at`) y la marca de tiempo de su primer cambio a estado `RESOLVED` o `CLOSED` (`resolved_at`).
* **EARS 1.2 (Criterio de Pertenencia Temporal al Filtro):** Cuando el usuario filtre por un rango de fechas para consultar el MTTR, el sistema deberá seleccionar los tickets cuya primera marca de tiempo de resolución (`resolved_at`) se encuentre comprendida entre la fecha/hora de inicio y la fecha/hora de fin del filtro.
* **EARS 1.3 (Reaperturas Posteriores dentro de 48h):** Si un ticket resuelto es reabierto válidamente dentro de la ventana de 48 horas contemplada en el Artículo V.6 constitucional (`IN_PROGRESS` u `OPEN`), el sistema deberá mantener fija e invariable la marca de tiempo de su primera resolución para el cómputo del MTTR original, sin penalizar retroactivamente la métrica inicial.
* **EARS 1.4 (Exclusión de Cancelados y Duplicados):** Si un ticket ha sido anulado, cancelado o marcado como duplicado (`CANCELLED` / `DUPLICATE`), el sistema deberá excluirlo estrictamente del cálculo del MTTR (no sumará tiempo ni incrementará el divisor de tickets resueltos).
* **EARS 1.5 (Exclusión de Tickets Abiertos):** Mientras un ticket permanezca en estados pendientes (`OPEN`, `ASSIGNED`, `IN_PROGRESS`), el sistema no deberá incluirlo en el cálculo del MTTR, contabilizándolo exclusivamente en las métricas de volumen de backlog y tickets en curso.
* **EARS 1.6 (Muestra Vacía / Sin Incidencias Resueltas):** Cuando no existan tickets resueltos válidos dentro del periodo o filtros aplicados, el sistema no deberá mostrar un valor de 0 horas ni arrojar errores de división por cero; deberá devolver explícitamente el estado `N/A` (No Aplicable / Sin datos).
* **EARS 1.7 (Protección ante Inconsistencia Horaria):** Si por error de sincronización de reloj un ticket presentara `resolved_at < created_at`, el sistema deberá computar 0 minutos y registrar una advertencia de consistencia, impidiendo valores negativos en el sumatorio del MTTR.
* **EARS 1.8 (Representación Temporal Clara):** Cuando el sistema visualice el MTTR en interfaz o reportes, deberá formatear el valor numérico en una representación legible para humanos (ej. "3h 45m" o "48h 12m") complementada con el valor exacto en horas decimales con un decimal.

---

### RF-02: Filtros y Dimensiones Analíticas Multidimensionales
*El sistema permitirá segmentar y desglosar las métricas operativas a través de filtros combinables.*

* **EARS 2.1 (Filtro Temporal):** El sistema deberá permitir filtrar las métricas por periodos predeterminados (*Últimos 7 días*, *Últimos 30 días*, *Mes actual*, *Mes anterior*) o mediante la selección de un rango personalizado de fechas (desde/hasta).
* **EARS 2.2 (Desglose por Sede Histórica):** Cuando el coordinador seleccione una sede o active la vista comparativa, el sistema deberá desglosar las métricas asociando cada ticket a la sede en la que estaba instalada la máquina en el momento del reporte, manteniendo dicha asignación invariable aun si la máquina fue reubicada posteriormente.
* **EARS 2.3 (Desglose por Técnico Resolutor y Reasignaciones):** Cuando el coordinador filtre por técnicos, el sistema atribuirá la resolución del ticket y su MTTR al técnico que ejecutó y firmó la resolución efectiva del ticket. En caso de que el ticket haya sido reasignado previamente, el tiempo total se atribuye al técnico resolutor final, quedando la trazabilidad del técnico anterior registrada en auditoría.
* **EARS 2.4 (Desglose por Tipo de Máquina y Seguridad Alimentaria):** El sistema deberá permitir desglosar las métricas según la categoría de máquina, destacando visualmente la comparativa de MTTR en **Máquinas de Alimentos Perecederos** frente al resto de máquinas (bebidas, snacks, café) para verificar el cumplimiento del Artículo II constitucional.
* **EARS 2.5 (Desglose por Tipo de Avería y Prioridad):** El sistema deberá categorizar los tiempos de resolución y volúmenes según la tipología de fallo (frío, cobro/monedas, atasco, apagado) y su nivel de prioridad (`CRITICAL`, `HIGH`, `MEDIUM`, `LOW`).
* **EARS 2.6 (Entidades Inactivas o Históricas):** Si un técnico, máquina o sede que tuvo actividad en el rango de fechas consultado ha sido desactivado o dado de baja lógica (`is_active = false`), el sistema deberá incluirlo en los agregados históricos identificándolo visualmente con el distintivo `(Inactivo)`.

---

### RF-03: Cuadro de Mando del Coordinador y KPIs de Operación
*El panel de coordinación dispondrá de un resumen de indicadores clave de rendimiento (KPIs).*

* **EARS 3.1 (Tarjetas de KPIs Globales):** Al acceder al cuadro de mando de métricas, el sistema deberá mostrar al coordinador las siguientes tarjetas de resumen para el periodo seleccionado:
  1. **MTTR Promedio Global:** Tiempo medio de resolución con indicador de tendencia porcentual (aumento/disminución) respecto al periodo inmediatamente anterior de idéntica duración.
  2. **Total de Incidencias Registradas:** Volumen de tickets creados en el periodo.
  3. **Tasa de Resolución (%):** Calculada formalmente como:
     $$\text{Tasa de Resolución} = \left(\frac{\text{Tickets Resueltos en el periodo}}{\text{Tickets Creados en el periodo}}\right) \times 100$$
  4. **Backlog Activo:** Número total de incidencias actualmente sin resolver (`OPEN`, `ASSIGNED`, `IN_PROGRESS`) en el sistema a la fecha actual.
  5. **Incidencias Críticas / SLA en Riesgo:** Número de tickets de alta prioridad o perecederos que superan los umbrales de SLA.
* **EARS 3.2 (Alertas de Desviación de SLA Fijas):** El sistema evaluará el cumplimiento de SLA contra dos umbrales operativos fijos:
  - **Máquinas de Alimentos Perecederos (Art. II):** SLA objetivo de **4 horas**.
  - **Resto del Parque (General):** SLA objetivo de **24 horas**.
  Si el MTTR supera estos valores, el sistema resaltará el indicador con distintivo visual amarillo (desviación leve) o rojo (incumplimiento crítico).

---

### RF-04: Panel Privado de Rendimiento del Técnico (*Mis Métricas*)
*El sistema proveerá una vista individualizada y restringida de autoconsulta para los técnicos de campo.*

* **EARS 4.1 (Segregación de Métricas):** Cuando un usuario con rol de Técnico acceda a su sección de métricas, el sistema únicamente calculará y mostrará los indicadores correspondientes a los tickets resueltos o actualmente asignados a su propio identificador de usuario.
* **EARS 4.2 (Bloqueo de Métricas Ajenas y Globales):** Si un técnico intenta acceder a métricas de otros técnicos, rankings comparativos o datos globales de coordinación, el sistema deberá denegar el acceso devolviendo un error de autorización (`403 Forbidden`) y restringiendo la interfaz.
* **EARS 4.3 (KPIs del Técnico):** El panel del técnico deberá exhibir:
  1. Su **MTTR promedio personal** en el periodo seleccionado (calculado sobre los tickets donde figure como técnico resolutor).
  2. **Total de incidencias resueltas** por el técnico en el periodo.
  3. **Incidencias actualmente en curso** asignadas al técnico.
  4. **Tiempo promedio de primera respuesta técnica:** Medido en minutos desde la creación del ticket (`created_at`) hasta su primer paso a estado en curso (`IN_PROGRESS`).

---

### RF-05: Registro Inmutable de Auditoría (*Audit Log*)
*El sistema registrará de forma cronológica, inalterable y detallada todas las acciones de cambio en el ciclo de vida del sistema, dando cumplimiento a los Artículos III.3 y V.1 constitucionales.*

* **EARS 5.1 (Eventos Auditados del Ticket):** El sistema registrará obligatoriamente un evento de auditoría ante:
  1. Creación de ticket (vía panel, QR o API).
  2. Cambio de estado del ticket (`OPEN` -> `ASSIGNED` -> `IN_PROGRESS` -> `RESOLVED` -> `CLOSED` o reaperturas dentro de 48h).
  3. Al registrar el estado `RESOLVED` o `CLOSED`, captura obligatoria del **diagnóstico técnico**, la **solución aplicada** y la **lista de piezas/repuestos sustituidos** (cumplimiento Art. III.3 y Art. V.1).
  4. Asignación o reasignación de técnico (indicando técnico saliente y técnico entrante).
  5. Modificación de prioridad o tipología de avería.
  6. Adición de comentarios técnicos internos o evidencias de intervención.
* **EARS 5.2 (Eventos Auditados de Maestros - Máquinas y Sedes):** El sistema registrará un evento de auditoría ante:
  1. Alta, baja lógica (`soft delete`) o modificación de datos de una máquina (cambio de modelo, reubicación de planta/ala, tipo de máquina).
  2. Alta, baja lógica o modificación de una sede (nombre, dirección o teléfono de contacto).
* **EARS 5.3 (Estructura de Datos del Evento):** Cada entrada de auditoría deberá contener inmutablemente:
  1. Identificador unívoco del evento.
  2. Marca de tiempo exacta con precisión de segundos (`timestamp`).
  3. Identificador y nombre del usuario causante (o `"Sistema / QR Público"` en reportes anónimos o procesos por lotes).
  4. Rol del usuario en el momento de la acción.
  5. Tipo de entidad afectada (`TICKET`, `MACHINE`, `LOCATION`).
  6. Identificador de la entidad afectada.
  7. Acción ejecutada (ej. `STATUS_CHANGE`, `ASSIGN_TECHNICIAN`, `UPDATE_PHONE`, `CREATE_TICKET`).
  8. Estado o valor previo (`previous_value`) y estado o valor nuevo (`new_value`) en formato legible / estructurado.
* **EARS 5.4 (Inmutabilidad Estricta):** El registro de auditoría será estrictamente de adición (*append-only*). El sistema no dispondrá bajo ninguna circunstancia de funciones, botones ni endpoints para editar, alterar o eliminar registros del log de auditoría.
* **EARS 5.5 (Consulta y Búsqueda para Coordinación):** Cuando el coordinador consulte el visor de auditoría, el sistema deberá permitir filtrar por fecha, tipo de entidad, identificador de ticket/máquina/sede, usuario causante y tipo de acción.

---

### RF-06: Exportación de Datos e Informes de Gestión
*El sistema ofrecerá mecanismos de exportación fiables y limpios tanto para análisis tabular como para presentación ejecutiva.*

* **EARS 6.1 (Exportación Tabular CSV de Métricas):** Cuando el coordinador solicite exportar la tabla de métricas agregadas (MTTR por sede, técnico o tipo de máquina), el sistema deberá generar un archivo en formato CSV codificado en UTF-8 (con BOM para compatibilidad directa con hojas de cálculo como Microsoft Excel y Google Sheets), respetando exactamente los filtros aplicados en pantalla.
* **EARS 6.2 (Exportación Tabular CSV del Log de Auditoría y Tope de Seguridad):** Cuando el coordinador solicite exportar los eventos de auditoría, el sistema deberá descargar un CSV conteniendo todos los campos del registro según los criterios de búsqueda activos, acotando la descarga a un máximo de seguridad de los **10.000 registros** más recientes e indicando un aviso al usuario si se alcanza dicho tope para que ajuste sus filtros.
* **EARS 6.3 (Informe Resumen Ejecutivo Imprimible / PDF):** Cuando el coordinador pulse la opción de *"Generar Informe Ejecutivo"*, el sistema deberá desplegar una vista maquetada y limpia del resumen de KPIs principales, alertas de SLA y tablas agregadas por sede y perecederos, optimizada con estilos de impresión para salida directa a PDF o papel sin elementos de navegación web ni barras laterales.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Precisión Numérica y Determinismo):** Todos los cálculos de tiempo deberán basarse en diferencias de minutos naturales continuos (24/7) y marcas de tiempo con zona horaria consistente. Los cálculos estadísticos deben ser idempotentes ante reconsultas con idénticos filtros.
* **RNF-02 (Rendimiento de Consultas Analíticas):** La generación de métricas y carga del cuadro de mando no deberá demorarse más de 1.5 segundos para un volumen típico de hasta 10.000 tickets históricos.
* **RNF-03 (Trazabilidad e Integridad de Auditoría):** Las inserciones en el log de auditoría deben ejecutarse de forma atómica y sincrónica con la mutación de estado que las provoca, garantizando que ninguna acción de negocio ocurra sin su contraparte en el registro de auditoría.
* **RNF-04 (Privacidad y Segregación de Roles - Art. V.4):** Las rutas de API de métricas globales y auditoría general deberán responder `403 Forbidden` / `401 Unauthorized` si son invocadas por roles no autorizados (Técnico o público sin autenticar).
* **RNF-05 (Accesibilidad y Visualización Adaptativa):** Los cuadros de mando y tablas analíticas deberán ser responsivos, permitiendo su correcta visualización en pantallas de escritorio, portátiles y tablets.

---

## 6. Casos Límite y Reglas de Excepción

1. **División por Cero en MTTR:** Si una sede o técnico tiene 0 tickets resueltos en el periodo seleccionado (aunque tenga tickets abiertos o cancelados), el MTTR se calcula como `null` y se representa en la interfaz como `N/A`.
2. **Ticket Resuelto en Cero Minutos:** Si un ticket se resuelve dentro del mismo minuto de su creación (ej. resolución inmediata tras verificación rápida), el tiempo computado será `0 minutos`, contabilizándose válidamente en la media.
3. **Múltiples Cambios de Estado Rápido y Reasignaciones:** Si un ticket transiciona por varios técnicos o estados intermedios antes de resolverse, se registran individualmente todos los eventos en el log de auditoría, pero el MTTR se atribuye íntegramente al técnico resolutor final medido entre `created_at` y el primer `resolved_at`.
4. **Modificación de Máquina, Sede o Técnico Inactivo:** Las bajas lógicas conservan intacta la referencia histórica en métricas y auditoría, identificándose con el sufijo `(Inactivo)`.
5. **Rango de Fechas Invertido o Vacío:** Si el usuario introduce una fecha "Hasta" anterior a la fecha "Desde", el sistema validará el formulario impidiendo la consulta y mostrando una indicación de error al usuario.
6. **Exportación con Cero Resultados:** Si los filtros aplicados no arrojan datos, la exportación CSV generará el archivo con la cabecera de columnas y cero filas de datos, sin arrojar error técnico.

---

## 7. Fuera de Alcance (*Out of Scope*)

1. **Modelos Predictivos y Machine Learning:** No se implementarán algoritmos de mantenimiento predictivo basados en IA o previsiones probabilísticas de fallo de componentes mecánicos.
2. **Envíos Automatizados Programados (Cron Emails):** La generación de informes y exportaciones se realiza bajo demanda interactiva del usuario coordinador; no se incluye un despachador desatendido de correos periódicos en esta fase.
3. **Edición o Eliminación de Registros de Auditoría:** Queda terminantemente prohibida cualquier funcionalidad para modificar, borrar o truncar registros del log de auditoría (Principio de Inviolabilidad del Art. III).
4. **Gestión Contable de Nóminas o Tarifas Horarias:** El sistema no calcula bonificaciones salariales ni costes laborales a partir de los tiempos de resolución.

---

## 8. Criterios de Finalización ("Hecho Cuando")

La especificación se considerará formalmente cumplida cuando:
1. Se calculen matemáticamente en tiempo 24/7 el MTTR global, por sede histórica, por técnico resolutor, por tipología de máquina (con vigilancia sanitaria en perecederos) y por tipo de fallo, mostrando `N/A` cuando la muestra sea cero y excluyendo cancelados.
2. La reapertura de un ticket dentro de la ventana de 48 horas (Art. V.6) no altere retroactivamente el cálculo del MTTR inicial ya registrado.
3. El panel del Coordinador muestre de forma responsiva las tarjetas de KPIs (incluyendo tendencia porcentual, tasa de resolución y backlog) y alertas ante desvíos de los umbrales fijos de SLA (4h perecederos, 24h general).
4. El rol de Técnico disponga de su vista de autoconsulta privada (*Mis Métricas* con MTTR propio y tiempo de primera respuesta a `IN_PROGRESS`), teniendo vetado el acceso a métricas globales y de otros compañeros.
5. El registro de auditoría capture de forma automática e inmutable todos los eventos estipulados de tickets (incluyendo diagnóstico, solución y repuestos según Art. III.3 y Art. V.1), máquinas y sedes.
6. La exportación en formato CSV descargue archivos válidos con cabecera y codificación UTF-8 con BOM acotados a 10.000 filas para auditoría, y la vista de impresión genere un informe ejecutivo limpio.
7. Se verifiquen todas las reglas constitucionales (integridad de datos, seguridad de roles, estricta separación de privilegios) con una suite de pruebas automatizadas al 100% en verde.

---

## 9. Dudas Abiertas

*Actualmente no existen dudas abiertas pendientes de resolución.* Todas las observaciones de QA, conflictos constitucionales, ambigüedades de filtros temporales y casos límite han sido auditados, unificados y cerrados formalmente.
