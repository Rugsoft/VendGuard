# ESPECIFICACIÓN FUNCIONAL: ZONIFICACIÓN OPERATIVA Y ASIGNACIÓN ASISTIDA DE TÉCNICOS POR TERRITORIO

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 17 · Zonificación Operativa y Asignación Asistida  
**Documento Espejo Funcional:** `specs/functional/operational_zones_spec.md`  
**Referencia Canónica:** [`specs/17-operational-zones/spec.md`](../17-operational-zones/spec.md)  
**Estado:** Cerrada y aprobada tras auditoría QA  
**Fecha:** Octubre 2026  
**Alineación Constitucional:** Artículos I al VII de la Constitución de VendGuard (Especialmente Art. II, Art. III, Art. V.1, Art. V.3 y Art. V.4)

---

## 1. Contexto y Objetivo

### 1.1. Contexto Operativo
En parques de máquinas de vending distribuidos en entornos metropolitanos y comarcales, el parque de sedes clientes se encuentra disperso geográficamente. Cuando el Coordinador realiza el triaje de una avería abierta, el selector de asignación tradicional presenta a los técnicos sin un criterio de afinidad territorial predeterminado.

Esta falta de zonificación ocasiona fricciones operativas de alto impacto:
1. **Desplazamientos ineficientes y dispersión de rutas:** Un técnico asignado a una sede en el extremo norte de la ciudad puede ser enviado repentinamente al sur, duplicando tiempos de viaje en vehículo, gasto de combustible y huella de carbono.
2. **Pérdida de familiaridad con las sedes:** Los técnicos no se especializan en los accesos, normas de seguridad y conserjerías de centros concretos (hospitales, universidades, complejos corporativos), lo que incrementa los tiempos muertos de llegada.
3. **Triaje manual lento y subjetivo:** El coordinador debe recordar mentalmente qué técnico suele cubrir cada barrio o consultar manualmente el mapa antes de decidir la asignación.
4. **Riesgo en emergencias sanitarias de frío (Art. II):** Ante una avería crítica en una máquina con alimentos perecederos, una mala asignación inicial a un técnico lejano o saturado de trabajo pone en riesgo el cumplimiento estricto del SLA contractual de menos de 4 horas.

### 1.2. Objetivo
Implantar un sistema de **Zonificación Territorial Operativa** y un motor de **Sugerencia Asistida de Asignación** en el panel de triaje de Coordinación que:
1. Estructure el territorio en zonas operativas delimitadas por códigos postales, con sedes asociadas de forma automática o manual.
2. Organice al equipo técnico mediante cuadrantes de cobertura, definiendo para cada técnico **una zona principal/titular** y opcionalmente **zonas secundarias/suplentes**.
3. Sugiera al coordinador, con un solo clic y de forma explicada, el técnico más idóneo para cada avería basándose en: afinidad territorial de zona, paradas activas del día en la misma sede/zona, carga de trabajo en curso y preservación prioritaria de la cadena de frío (Art. II).
4. Respete el principio de libertad de gestión del coordinador (sugerencia asistida, no vinculante), exigiendo justificación formal ($\ge 20$ caracteres, Art. V.1) al descartar la salvaguarda de frío, y manteniendo el blindaje estricto de privacidad frente a las sedes clientes (Art. V.4).

---

## 2. Usuarios y Perfiles Implicados

| Perfil / Actor | Rol en la Zonificación y Asignación Asistida | Permisos y Restricciones |
| :--- | :--- | :--- |
| **📊 Coordinador de Operaciones** | Actor principal de configuración y triaje. Crea y gestiona zonas operativas, vincula códigos postales, define cuadrantes técnicos (titular/suplentes), asigna o desvincula sedes manualmente, y recibe sugerencias inteligentes en el modal de asignación. | Control total de configuración y visualización. Puede anular libremente la sugerencia del sistema; si anula una sugerencia sanitaria de frío del Art. II debe registrar justificación obligatoria ($\ge 20$ caracteres). |
| **📱 Técnico de Campo / Ruta** | Beneficiario operativo. Es asignado a una zona titular y posibles zonas suplentes. Recibe averías optimizadas según su demarcación territorial habitual. | Visualiza en su perfil o cabecera de ruta sus zonas asignadas (titular y suplentes) a título meramente informativo. No puede alterar los cuadrantes ni redefinir zonas. |
| **🏢 Responsable de Sede** | Cliente / sede receptora del servicio de vending. | **Acceso estrictamente bloqueado (Art. V.4):** No tiene visibilidad alguna sobre nombres de zonas, límites territoriales, cuadrantes de técnicos ni criterios de asignación interna. El técnico sigue figurando bajo el código oficial de operador anónimo. |

---

## 3. Historias de Usuario

### HU-01: Configuración Territorial de Zonas y Códigos Postales
> **Como** Coordinador de Operaciones,  
> **quiero** definir zonas operativas (con nombre, código identificativo y color corporativo) y vincular a cada una su catálogo de códigos postales únicos,  
> **para** estructurar geográficamente el parque de máquinas y facilitar la organización de la flota técnica.

### HU-02: Cuadrante de Técnicos con Titularidad y Suplencias
> **Como** Coordinador de Operaciones,  
> **quiero** asignar a cada técnico una zona principal como titular y, opcionalmente, una o más zonas adicionales como suplente,  
> **para** disponer de un mapa de cobertura claro con alternativas naturales de respaldo ante ausencias o sobrecargas de trabajo.

### HU-03: Herencia Automática de Zona en Sedes y Excepciones Manuales
> **Como** Coordinador de Operaciones,  
> **quiero** que al crear o editar una sede, esta herede automáticamente la zona asociada a su código postal, y que pueda modificarla manualmente si operativamente conviene que la atienda otra demarcación,  
> **para** automatizar el alta de centros y mantener flexibilidad ante excepciones territoriales.

### HU-04: Sugerencia Inteligente de Técnico en Triaje
> **Como** Coordinador de Operaciones,  
> **quiero** que al pulsar en asignar una avería, el sistema me muestre en primera posición destacada al técnico más idóneo (titular de la zona, con presencia in situ o menor carga de trabajo), junto con un resumen claro del motivo de la recomendación y un botón de asignación en un clic,  
> **para** despachar incidencias en menos de 5 segundos reduciendo desplazamientos innecesarios.

### HU-05: Salvaguarda Sanitaria Prioritaria en Alimentos Perecederos (Art. II)
> **Como** Coordinador de Operaciones,  
> **quiero** que ante una avería crítica en una máquina con alimentos perecederos, si el técnico titular de la zona tiene paradas previas que comprometan la atención antes de 4 horas, el sistema me sugiera prioritariamente al técnico de guardia 24/7 disponible y exija justificación si decido no enviarlo,  
> **para** evitar de forma innegociable la ruptura de la cadena de frío y cumplir con la Constitución de VendGuard.

---

## 4. Requisitos Funcionales (RF-x) con Criterios de Aceptación (EARS)

### RF-01: Gestión de Zonas Operativas y Códigos Postales
* **RF-01.1: Atributos y Ciclo de Vida de la Zona:** El sistema debe permitir al coordinador dar de alta, editar y desactivar lógicamente zonas operativas. Cada zona debe contar con:
  * Identificador único alfanumérico (código de zona, ej. `ZONA-NORTE-BCN`).
  * Nombre representativo ($\ge 3$ caracteres, ej. *"Litoral / Norte"*).
  * Color identificativo hexadecimal (ej. `#2560ff`) para representación cartográfica.
  * Descripción funcional opcional.
  * Estado operativo (`Activa` / `Inactiva`).
* **RF-01.2: Validación y Unicidad de Códigos Postales:**
  * **Formato Estricto:** Los códigos postales deben estar formados exactamente por **5 dígitos numéricos** (`^\d{5}$`), normalizados con ceros a la izquierda (ej. `08001`).
  * **Regla de Unicidad Territorial:** Un código postal solo puede pertenecer a **una única zona activa simultáneamente**.
* **RF-01.3: Criterios de Aceptación EARS:**
  * **[Ubiquitous]** El sistema debe impedir la creación de dos zonas con el mismo código identificativo o el mismo nombre.
  * **[Event-driven]** Cuando el coordinador intente asignar a una zona un lote de códigos postales que ya pertenecen a otras zonas activas, el sistema debe presentar un modal previo detallando los códigos a mover y las sedes afectadas, aplicando el traslado de forma atómica en una única transacción tras la confirmación expresa.
  * **[Event-driven]** Cuando el coordinador desactive una zona que contiene sedes activas, el sistema debe solicitar confirmación advirtiendo de que todas sus sedes (tanto heredadas como forzadas) pasarán al estado *"Sin Zona Asignada"* (`zone_id = NULL`), conservando su historial para una futura reactivación o reubicación.

### RF-02: Cuadrante de Cobertura Técnica (Titularidad y Suplencias)
* **RF-02.1: Reglas de Cobertura:**
  * Un técnico solo puede ser **Titular Principal** (`is_primary = true`) de **máximo una zona operativa activa**.
  * Un técnico puede ser configurado como **Suplente / Respaldo** (`is_primary = false`) en una o más zonas operativas adicionales.
  * Una zona operativa puede contar con uno o varios técnicos titulares y uno o varios suplentes.
* **RF-02.2: Criterios de Aceptación EARS:**
  * **[Ubiquitous]** El sistema debe permitir únicamente la vinculación de usuarios con rol `TECHNICIAN` activo a los cuadrantes de zona.
  * **[Unwanted behavior]** Si el coordinador intenta marcar a un técnico como titular de una segunda zona cuando ya es titular de otra, el sistema debe advertir que perderá la titularidad de su zona previa y solicitar confirmación.
  * **[State-driven]** Mientras un técnico esté desactivado o de baja laboral, el sistema debe excluirlo de las sugerencias activas de la zona sin eliminar su vinculación histórica.

### RF-03: Herencia Territorial en Sedes y Anulación Manual
* **RF-03.1: Herencia por Código Postal:** Al registrar o actualizar una sede, si su código postal coincide con alguno de los códigos postales de una zona activa, la sede heredará automáticamente dicha zona (`zone_id = zona.id`, `is_manual_override = false`).
* **RF-03.2: Asignación Forzada Manual (Excepción Operativa):** El coordinador debe poder anular la herencia y asignar manualmente una sede a cualquier zona operativa distinta, o desvincularla para dejarla en *"Sin Zona Asignada"*. En ese caso se fija `is_manual_override = true`.
* **RF-03.3: Edición de Sedes y Traslado de Códigos Postales:**
  * Al editar el código postal de una sede, si esta **no** tenía asignación forzada manual (`is_manual_override = false`), el sistema recalcula automáticamente su zona heredada según el nuevo código postal. Si tenía asignación forzada manual (`is_manual_override = true`), la zona seleccionada se mantiene inalterada.
  * Al trasladar un código postal de una zona a otra, todas las sedes que heredaban dicho código postal deben actualizar su zona automáticamente (excepto aquellas con `is_manual_override = true`).
  * Los tickets de avería que ya estuvieran **Asignados** o **En Curso** no deben sufrir reasignación automática para no desestabilizar las rutas operativas en marcha.
* **RF-03.4: Criterios de Aceptación EARS:**
  * **[Event-driven]** Cuando se guarde una sede con un código postal asociado a una zona y sin anulación manual, el sistema debe asociar la sede a dicha zona.
  * **[State-driven]** Mientras una sede mantenga `is_manual_override = true`, las modificaciones de códigos postales en las zonas no deben alterar su zona asignada.
  * **[Ubiquitous]** Las sedes cuyo código postal no coincida con ninguna zona activa deben mostrarse en el panel de sedes con la insignia visual *"Sin Zona Asignada"*.

### RF-04: Motor de Sugerencia Inteligente de Asignación en Triaje
* **RF-04.1: Algoritmo de Ponderación Determinista:** Al desplegar el modal de asignación o reasignación de una avería, el sistema debe evaluar a los técnicos disponibles y calcular un índice numérico de idoneidad basado en:
  1. **Afinidad Territorial:**
     * Técnico titular de la zona de la sede: **+50 puntos**.
     * Técnico suplente de la zona de la sede: **+25 puntos**.
     * Técnico de otra zona: **+0 puntos**.
  2. **Presencia In Situ y Paradas Activas:**
     * Si el técnico ya tiene una intervención `IN_PROGRESS` o pendiente en la **misma sede**: **+40 puntos** (atención inmediata sin desplazamiento).
     * Si el técnico tiene intervenciones programadas en **otras sedes de la misma zona**: **+20 puntos**.
  3. **Penalización por Intervención en Curso en Otra Sede (Art. V.3 y Módulo 16):**
     * Si el técnico se encuentra actualmente con una parada `IN_PROGRESS` abierta en una **sede diferente**: **$-15$ puntos** de penalización y advertencia visible (*"⚠️ En curso en otra sede: requerirá pausar o finalizar antes de iniciar"*).
  4. **Carga Operativa Viva (Definición Objetiva):**
     * Por cada ticket de avería activo asignado (`ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`) o cada orden de mantenimiento preventivo (`IN_INSPECTION` o `SCHEDULED` para hoy): **$-5$ puntos**.
     * Por cada ticket en pausa por falta de acceso (`PENDING_INFO`, Módulo 11): penalización atenuada de **$-2$ puntos** (reloj de SLA pausado sin dedicación física inmediata).
     * Se computan todas las paradas vivas en la hoja de ruta del técnico en el momento del triaje, con independencia de la fecha en que se abriera el expediente.
  5. **Disponibilidad Inmediata:** Exclusión de técnicos inactivos o dados de baja.
* **RF-04.2: Regla de Desempate y Múltiples Titulares:** Si dos técnicos obtienen la misma puntuación final, el orden de sugerencia se resuelve de forma determinista por **mayor tiempo transcurrido desde la última asignación formal** (`assigned_at` en el histórico), situando en primer lugar al técnico que lleve más tiempo sin recibir trabajo para equilibrar la fatiga del equipo.
* **RF-04.3: Criterios de Aceptación EARS:**
  * **[Event-driven]** Cuando el coordinador abra el modal de asignación de una avería, el sistema debe destacar al técnico con mayor puntuación bajo la etiqueta *"⭐ Sugerido por Zona"* con desglose explicativo de su puntuación.
  * **[Event-driven]** Cuando el coordinador pulse el botón directo *"Asignar sugerido"*, el sistema debe ejecutar la asignación técnica en una única interacción.
  * **[Optional]** Si el coordinador decide no aceptar al técnico sugerido, el sistema debe permitir seleccionar a cualquier otro técnico del desplegable sin bloqueo alguno.

### RF-05: Salvaguarda Sanitaria en Averías Críticas de Frío (Art. II y Art. V.1)
* **RF-05.1: Protección de Emergencia Sanitaria (< 4h):** Si la avería recae sobre una máquina con alimentos perecederos (`PERISHABLE_FOOD` o `COMBO`) o cuenta con prioridad `CRITICAL`:
  * El motor de sugerencia debe comprobar si el técnico titular de la zona tiene **cualquier parada previa activa** (correctiva o preventiva).
  * Si el titular tiene paradas previas activas, el motor antepone de inmediato al técnico con guardia 24/7 activa (`is_on_call = true`) o con menor cola inmediata de trabajo, destacándolo con la insignia *"🚨 Prioridad Sanitaria Art. II · Guardia 24/7"*.
* **RF-05.2: Justificación Obligatoria de Anulación Sanitaria (Art. V.1):** Si el coordinador decide no asignar al técnico de guardia sugerido y selecciona a un técnico que tenga paradas previas o que no esté de guardia, el sistema debe **exigir obligatoriamente una justificación de al menos 20 caracteres** antes de confirmar el despacho, auditando el motivo por el cual se pospone o asume el riesgo sanitario.
* **RF-05.3: Criterios de Aceptación EARS:**
  * **[State-driven]** Mientras una avería sea crítica o perecedera, el sistema debe anteponer al técnico de guardia 24/7 frente a titulares de zona con paradas previas activas.
  * **[Unwanted behavior]** Si el coordinador intenta confirmar la asignación a un técnico no sugerido en una avería sanitaria sin aportar un texto de justificación de $\ge 20$ caracteres, el sistema debe bloquear la acción mostrando el error de validación constitucional.

### RF-06: Gestión de Zonas Desatendidas o Sin Técnicos Disponibles
* **RF-06.1: Detección y Proximidad Espacial:** Si la zona de la sede no cuenta con técnicos asignados o si todos sus integrantes están dados de baja o han alcanzado su cupo máximo de paradas:
  * El sistema muestra la advertencia *"⚠️ Sin técnicos disponibles en esta zona"*.
  * El sistema lista a los demás técnicos activos ordenados por: (1.º) proximidad espacial respecto a su parada más reciente, y (2.º) menor carga de trabajo.
* **RF-06.2: Cálculo de Proximidad sin GPS (Dogma Vanilla):**
  * La distancia se calcula con trigonometría esférica de Haversine (`GeoDistanceService`) midiendo la separación en km entre las coordenadas `(lat, lng)` de la sede averiada y:
    1. La sede donde el técnico tenga su parada `IN_PROGRESS` actual.
    2. Si no tiene parada en curso, la sede de su última parada completada hoy.
    3. Si aún no ha iniciado ruta, las coordenadas de la Base Central.
  * **Sedes sin Coordenadas (`lat`/`lng` nulos):** Si la sede averiada o la del técnico carecen de coordenadas válidas, se aplica una distancia neutra penalizada (999 km), desempatando directamente por menor carga de trabajo activa.
* **RF-06.3: Criterios de Aceptación EARS:**
  * **[State-driven]** Mientras una zona esté desatendida, el sistema debe presentar a los técnicos del parque ordenados por proximidad Haversine y menor carga.
  * **[Unwanted behavior]** El sistema jamás debe bloquear la asignación de un ticket porque la zona esté desatendida; el coordinador siempre podrá despachar la incidencia.

### RF-07: Blindaje de Privacidad y Segregación de Datos (Art. V.4)
* **RF-07.1: Aislamiento para Responsables de Sede:**
  * Las interfaces del Portal de Sede y los reportes públicos QR no deben mostrar nombres de zonas operativas, cuadrantes técnicos ni rutas territoriales.
  * La asignación asistida por zona no debe alterar la anonimización del técnico ante el responsable de sede, manteniéndose el identificador oficial *"Servicio Técnico Oficial · Operador #[Código]"*.
* **RF-07.2: Criterios de Aceptación EARS:**
  * **[Ubiquitous]** El sistema debe ocultar toda información referente a zonas operativas, delimitación de códigos postales y cuadrantes de técnicos en cualquier endpoint consumido por el rol de Responsable de Sede o por consultas públicas anónimas.

---

## 5. Requisitos No Funcionales (RNF-x)

* **RNF-01 · Rendimiento del Recomendador:** El cálculo del índice de idoneidad y la ordenación de técnicos sugeridos para una incidencia debe completarse en menos de **100 milisegundos**, garantizando apertura instantánea del modal de asignación.
* **RNF-02 · Agilidad de Triaje ($\le 2$ interacciones):** La asignación del técnico recomendado debe poder completarse en un máximo de **dos interacciones** (abrir modal $\to$ confirmar asignación con botón predeterminado).
* **RNF-03 · Trazabilidad y Auditoría Atómica (Art. III):**
  * Cada cambio en la configuración de zonas, códigos postales o cuadrantes de técnicos debe registrar un evento inmutable en el registro de auditoría (`audit_log`).
  * En traslados masivos de códigos postales, se registra un evento matriz **`OPERATIONAL_ZONE_POSTAL_CODE_TRANSFERRED`** con el balance global y eventos individuales **`LOCATION_ZONE_REASSIGNED`** por cada sede afectada dentro de la misma transacción.
* **RNF-04 · Consistencia Territorial Idempotente:** La reubicación de códigos postales entre zonas debe ejecutarse de forma transaccional, garantizando que jamás existan códigos postales huérfanos o duplicados entre zonas activas.

---

## 6. Casos Límite y Reglas de Excepción

1. **Sede con Código Postal Inexistente:** Si una sede se registra con un código postal que no existe en el catálogo de zonas, la sede queda registrada con `zone_id = NULL` y clasificada como *"Sin Zona Asignada"*.
2. **Empate Técnico en la Puntuación:** Se desempata estrictamente por mayor tiempo transcurrido desde la última asignación formal (`assigned_at`).
3. **Desactivación de una Zona con Averías Abiertas:** Las averías activas de sus sedes permanecen asignadas a sus técnicos actuales. Las sedes pasan a `zone_id = NULL` conservando su historial.
4. **Técnico Reasignado de Zona con Averías Pendientes:** Si un técnico deja de ser titular de una zona, conserva sus averías ya asignadas en curso para no perturbar su jornada actual.
5. **Averías Concurrentes en la Misma Sede (Sinergia Módulo 16):** Si un técnico ya tiene una parada en la sede, la bonificación de presencia in situ (+40 pts) lo situará en primera posición. Si está `IN_PROGRESS` en otra sede, la penalización ($-15$ pts) advertirá de la incompatibilidad territorial.

---

## 7. Fuera de Alcance (Líneas Rojas)

1. **Optimización de tráfico en tiempo real o integración GPS externa:** Prohibido el uso de APIs externas de tráfico o geolocalización en segundo plano (Dogma Vanilla y Art. V.4).
2. **Polígonos geográficos complejos o extensiones espaciales pesadas:** La delimitación territorial se realiza estrictamente mediante códigos postales (`^\d{5}$`).
3. **Auto-asignación ciega o despacho autónomo:** El sistema jamás asigna automáticamente un ticket sin la confirmación humana expresa del Coordinador.
4. **Gestión de flotas vehiculares o consumo de combustible:** Queda fuera del alcance el control de matriculación, kilometraje físico de furgonetas o tarjetas de combustible.

---

## 8. Criterios de Finalización (Definition of Done - DoD)

* **DoD-01:** La configuración de zonas permite crear, editar, listar y desactivar zonas con nombre, código y color.
* **DoD-02:** Los códigos postales (formato `^\d{5}$`) respetan la unicidad estricta entre zonas activas y se reasignan limpiamente con confirmación en bloque del coordinador.
* **DoD-03:** Las sedes heredan automáticamente la zona por su código postal y admiten anulación/asignación forzada manual (`is_manual_override = true`).
* **DoD-04:** El cuadrante técnico permite fijar 1 zona titular y múltiples suplencias por técnico, excluyendo a técnicos inactivos.
* **DoD-05:** El modal de asignación de incidencias presenta al técnico sugerido con desglose de puntuación (+50/+25 zona, +40/+20 in situ, -5/-2 carga, -15 otra sede) y asignación en 1 clic.
* **DoD-06:** Ante emergencias críticas de frío (Art. II), el sistema antepone al técnico de guardia 24/7 si el titular de zona presenta paradas previas activas, y exige justificación $\ge 20$ caracteres si el coordinador descarta la sugerencia sanitaria (Art. V.1).
* **DoD-07:** Si una zona no tiene técnicos disponibles, se muestra la advertencia y se lista a los demás técnicos por proximidad Haversine / menor carga sin bloquear el despacho.
* **DoD-08:** El portal de sede y las vistas públicas no contienen referencias a zonas ni cuadrantes (Art. V.4).
* **DoD-09:** Todas las acciones territoriales quedan registradas inmutablemente en `audit_log` con evento dual matriz-sede en traslados masivos (Art. III).

---

## 9. Resoluciones de Auditoría QA

* **Resolución QA 1 (Art. II y Art. V.1 - Salvaguarda Sanitaria y Justificación):** En averías perecederas/críticas, si el titular de zona tiene cualquier parada previa activa, se antepone obligatoriamente al técnico de guardia 24/7 (`is_on_call = true`). Ignorar la sugerencia exige justificación obligatoria $\ge 20$ caracteres.
* **Resolución QA 2 (Art. V.3 y Módulo 16 - Coexistencia entre Sedes):** Si un técnico tiene una parada `IN_PROGRESS` en otra sede distinta, se le penaliza con $-15$ puntos y advertencia visible. Si es en la misma sede, se le bonifica con $+40$ puntos.
* **Resolución QA 3 (Cálculo de Carga y "Hoy"):** Se define la carga como toda parada viva en la hoja de ruta en el momento del triaje. Averías y preventivos penalizan $-5$ puntos; pausas `PENDING_INFO` penalizan atenuadamente $-2$ puntos.
* **Resolución QA 4 (Código Postal y Ciclo de Vida):** Formato numérico estricto de 5 dígitos (`^\d{5}$`). La edición de sede recalcula zona si `is_manual_override = false`. La desactivación de zona pasa sedes a `NULL` (*"Sin Zona Asignada"*). Los traslados de códigos postales se confirman en bloque de forma atómica.
* **Resolución QA 5 (Proximidad Haversine y Desempates):** Proximidad esférica Haversine respecto a parada `IN_PROGRESS`, última parada completada o Base Central. Sedes sin coordenadas computan 999 km de penalización. Desempates resueltos deterministamente por mayor tiempo desde la última asignación (`assigned_at`).
* **Resolución QA 6 (Art. III - Trazabilidad Dual en Cambios Masivos):** En reubicaciones masivas se emite un evento matriz `OPERATIONAL_ZONE_POSTAL_CODE_TRANSFERRED` y eventos individuales `LOCATION_ZONE_REASSIGNED` por sede afectada en la misma transacción.
