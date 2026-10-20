# ESPECIFICACIÓN FUNCIONAL: ZONIFICACIÓN OPERATIVA Y ASIGNACIÓN ASISTIDA DE TÉCNICOS POR TERRITORIO

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 17 · Zonificación Operativa y Asignación Asistida  
**Documento Espejo Funcional:** `specs/functional/operational_zones_spec.md`  
**Referencia Canónica:** [`specs/17-operational-zones/spec.md`](../17-operational-zones/spec.md)  
**Estado:** Cerrada y aprobada · Enmienda de implementabilidad 2026-10-20 (Resoluciones QA 7 a 12) · Implementación certificada 2026-10-20 (T-ZONE-01 a T-ZONE-13) · Enmienda de auditoría 2026-10-20 (Resolución QA 14, T-ZONE-14) · Ampliación de visibilidad del cuadrante 2026-10-20 (RF-02.3, RF-02.4 y Resolución QA 15)  
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
| **📊 Coordinador de Operaciones** | Actor principal de configuración y triaje. Crea y gestiona zonas operativas, vincula códigos postales, define cuadrantes técnicos (titular/suplentes), asigna o desvincula sedes manualmente, y recibe sugerencias inteligentes en el panel de asignación. | Control total de configuración y visualización. Puede anular libremente la sugerencia del sistema; si anula una sugerencia sanitaria de frío del Art. II debe registrar justificación obligatoria ($\ge 20$ caracteres). |
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
  * **Regla de Unicidad Territorial:** Un código postal solo puede pertenecer a **una única zona operativa viva** en cada momento (índice único parcial `uq_zone_postal_codes_live` sobre `postal_code WHERE released_at IS NULL`). La desactivación de una zona **libera** sus códigos postales en la misma transacción marcándolos con `released_at` (Resoluciones QA 7 y QA 13), de modo que quedan disponibles para otra zona **sin borrar su historial**: el disparador anti-borrado sigue intacto (Art. III.1). Volver a activar la zona **no** los recupera automáticamente.
* **RF-01.3: Criterios de Aceptación EARS:**
  * **[Ubiquitous]** El sistema debe impedir la creación de dos zonas con el mismo código identificativo o el mismo nombre.
  * **[Event-driven]** Cuando el coordinador intente asignar a una zona un lote de códigos postales que ya pertenecen a otra zona, el sistema debe presentar un modal previo detallando los códigos a mover y las sedes afectadas, aplicando el traslado de forma atómica en una única transacción tras la confirmación expresa.
  * **[Event-driven]** Cuando el coordinador desactive una zona que contiene sedes activas, el sistema debe solicitar confirmación advirtiendo de que todas sus sedes (tanto heredadas como forzadas) pasarán al estado *"Sin Zona Asignada"* (`zone_id = NULL`), conservando su historial. En la misma transacción, el sistema debe liberar sus códigos postales y dar de baja lógica (`removed_at`) las vinculaciones de su cuadrante técnico, exigiéndose `confirm_release = true` cuando existan códigos postales o técnicos vinculados (Resolución QA 7).

### RF-02: Cuadrante de Cobertura Técnica (Titularidad y Suplencias)
* **RF-02.1: Reglas de Cobertura:**
  * Un técnico solo puede ser **Titular Principal** (`is_primary = true`) de **máximo una zona operativa**, materializado con el índice único parcial `uq_technician_primary_zone` sobre las vinculaciones vivas (`is_primary = TRUE AND removed_at IS NULL`). Desactivar una zona extingue esa titularidad.
  * Un técnico puede ser configurado como **Suplente / Respaldo** (`is_primary = false`) en una o más zonas operativas adicionales.
  * Una zona operativa puede contar con uno o varios técnicos titulares y uno o varios suplentes.
* **RF-02.2: Criterios de Aceptación EARS:**
  * **[Ubiquitous]** El sistema debe permitir únicamente la vinculación de usuarios con rol `TECHNICIAN` activo a los cuadrantes de zona.
  * **[Unwanted behavior]** Si el coordinador intenta marcar a un técnico como titular de una segunda zona cuando ya es titular de otra, el sistema debe advertir que perderá la titularidad de su zona previa y solicitar confirmación.
  * **[State-driven]** Mientras un técnico esté desactivado o de baja laboral, el sistema debe excluirlo de las sugerencias activas de la zona sin eliminar su vinculación histórica.
  * **[Ubiquitous]** La baja de un técnico del cuadrante debe ser **lógica** (`removed_at`), nunca un borrado físico (Art. III.1). Una vinculación extinguida no se reactiva: si el técnico regresa, se registra una vinculación nueva y auditada (Resolución QA 10).

* **RF-02.3: Visibilidad del Cuadrante (Lectura Legible y Consulta Inversa):**
  * **Identidad, no contadores:** el cuadrante de una zona se presenta con el nombre y el código de operador de cada técnico vinculado, su condición (**Titular** / **Suplente**), su marca de guardia 24/7 y la fecha en que se vinculó. El contador de RF-02.1 sigue existiendo en el listado, pero deja de ser la única lectura.
  * **Vinculaciones vivas y vencidas:** la fuente es `technician_zone_assignments` con `removed_at IS NULL`, ordenada por titularidad y después por nombre. Una vinculación viva de un usuario desactivado o de baja **sí** aparece, marcada como «Baja»: su vínculo sigue vigente y ocultarla dejaría una fila imposible de extinguir desde la interfaz (Resolución QA 10).
  * **Retirada sobre lo que se ve:** la baja del cuadrante se ejecuta desde la fila del técnico, con la confirmación ya existente y la auditoría `TECHNICIAN_ZONE_REMOVED`. El selector ciego de todo el directorio desaparece: solo se puede retirar a quien está en el cuadrante.
  * **Consulta inversa:** la consola territorial ofrece una vista **por técnico** que enumera sus zonas vivas —titular en una, suplente en las demás— y señala a los técnicos activos que no tienen ninguna. Responde a «¿qué cubre este técnico?» sin recorrer las zonas una a una.
* **RF-02.4: Criterios de Aceptación EARS (Visibilidad del Cuadrante):**
  * **[Ubiquitous]** El sistema debe publicar el cuadrante vivo de una zona con `name`, `operator_code`, `is_primary`, `is_on_call`, `is_active` y `assigned_at` de cada vinculación.
  * **[State-driven]** Mientras exista una vinculación viva, el técnico debe aparecer en el cuadrante aunque su usuario esté desactivado o de baja, con la insignia «Baja» y la acción de retirada disponible.
  * **[Event-driven]** Cuando el coordinador abra el cuadrante de una zona, el sistema debe listar primero a los titulares y después a los suplentes por orden alfabético.
  * **[Ubiquitous]** El sistema debe ofrecer la lectura inversa por técnico, con sus zonas vivas y su rol en cada una, incluyendo a los técnicos activos sin zona.
  * **[Unwanted behaviour]** Si una zona no tiene ninguna vinculación viva, el sistema debe mostrar un estado vacío explicativo, nunca una lista en blanco.
  * **[Ubiquitous]** Ambas superficies son exclusivas de Coordinación: ni el portal de sede ni la sesión del técnico reciben el cuadrante de una zona (Art. V.4).

### RF-03: Herencia Territorial en Sedes y Anulación Manual
* **RF-03.1: Herencia por Código Postal:** Al registrar o actualizar una sede, si su código postal coincide con alguno de los códigos postales de una zona activa, la sede heredará automáticamente dicha zona (`zone_id = zona.id`, `is_manual_override = false`). El código postal es un atributo **obligatorio** de la sede en el alta y la edición, persistido en `locations.postal_code` con formato de cinco dígitos (Resolución QA 7); las sedes históricas que aún no lo tengan permanecen como *Sin Zona Asignada* hasta que se editen, porque la migración nunca inventa un código postal.
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
* **RF-04.1: Algoritmo de Ponderación Determinista:** Al desplegar el panel de asignación o reasignación de una avería, el sistema debe evaluar a los técnicos disponibles y calcular un índice numérico de idoneidad basado en:
  1. **Afinidad Territorial:**
     * Técnico titular de la zona de la sede: **+50 puntos**.
     * Técnico suplente de la zona de la sede: **+25 puntos**.
     * Técnico de otra zona: **+0 puntos**.
  2. **Presencia In Situ y Paradas Activas:**
     * Si el técnico ya tiene una parada viva (`ASSIGNED` o `IN_PROGRESS`) en la **misma sede**: **+40 puntos** (atención inmediata sin desplazamiento).
     * Si el técnico tiene paradas vivas hoy en **otras sedes de la misma zona**: **+20 puntos**. Ambas bonificaciones son **excluyentes**: una parada en la misma sede no suma además el `+20` de zona (Resolución QA 12).
  3. **Penalización por Intervención en Curso en Otra Sede (Art. V.3 y Módulo 16):**
     * Si el técnico se encuentra actualmente con una parada `IN_PROGRESS` abierta en una **sede diferente**: **$-15$ puntos** de penalización y advertencia visible (*"⚠️ En curso en otra sede: requerirá pausar o finalizar antes de iniciar"*).
  4. **Carga Operativa Viva (Definición Objetiva):**
     * Por cada ticket de avería activo asignado (`ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`) o cada orden de mantenimiento preventivo (`IN_INSPECTION` o `SCHEDULED` para hoy): **$-5$ puntos**.
     * Por cada ticket en pausa por falta de acceso (`PENDING_INFO`, Módulo 11): penalización atenuada de **$-2$ puntos** (reloj de SLA pausado sin dedicación física inmediata).
     * Se computan todas las paradas vivas en la hoja de ruta del técnico en el momento del triaje, con independencia de la fecha en que se abriera el expediente.
  5. **Disponibilidad Inmediata:** Exclusión de técnicos inactivos o dados de baja.
* **RF-04.2: Regla de Desempate y Múltiples Titulares:** Si dos técnicos obtienen la misma puntuación final, el orden de sugerencia se resuelve de forma determinista por **mayor tiempo transcurrido desde su última asignación formal**, tomando `MAX(incidents.assigned_at)` de los expedientes que el técnico tiene asignados hoy; un técnico **sin ninguna asignación registrada** se trata como asignado en `epoch` (prioridad máxima de reparto) para equilibrar la fatiga del equipo. Si persiste el empate, ordena `users.id ASC`, garantizando reproducibilidad total.
* **RF-04.3: Criterios de Aceptación EARS:**
  * **[Event-driven]** Cuando el coordinador abra el panel de asignación de una avería, el sistema debe destacar al técnico con mayor puntuación bajo la etiqueta *"⭐ Sugerido por Zona"* con desglose explicativo de su puntuación.
  * **[Event-driven]** Cuando el coordinador pulse el botón directo *"Asignar sugerido"*, el sistema debe ejecutar la asignación técnica en una única interacción.
  * **[Optional]** Si el coordinador decide no aceptar al técnico sugerido, el sistema debe permitir seleccionar a cualquier otro técnico del desplegable sin bloqueo alguno, **con la única excepción** de la salvaguarda sanitaria de RF-05.2, que exige justificación cuando se descarta una recomendación de frío.

### RF-05: Salvaguarda Sanitaria en Averías Críticas de Frío (Art. II y Art. V.1)
* **RF-05.1: Protección de Emergencia Sanitaria (< 4h):** Si la avería recae sobre una máquina con alimentos perecederos (`PERISHABLE_FOOD` o `COMBO`) o cuenta con prioridad `CRITICAL`:
  * El motor de sugerencia debe evaluar el **cuadrante titular completo** de la zona: el escalado se dispara cuando **ningún titular activo de la zona está libre** de paradas previas activas (correctiva o preventiva). Si al menos un titular está libre, no hay escalado y puntúa el scoring ordinario.
  * Cuando el escalado se dispara, el motor antepone al técnico de guardia (`users.is_on_call = true`, Módulo 14), destacándolo con la insignia *"🚨 Prioridad Sanitaria Art. II · Guardia 24/7"*. Si hay varios técnicos de guardia, gana el de mayor puntuación; si **no hay ninguno**, se antepone el técnico activo con menor carga viva y, a igual carga, el más próximo (Resolución QA 9).
* **RF-05.2: Justificación Obligatoria de Anulación Sanitaria (Art. V.1):** Si la avería es perecedera o `CRITICAL` y la recomendación sanitaria está activa, el coordinador debe aportar `override_justification` de **al menos 20 caracteres** siempre que asigne a un técnico distinto del recomendado; el campo viaja en el `PATCH /api/coordinator/incidents/{id}/assign` y se audita junto al despacho. Cuando la recomendación no es sanitaria, no se exige justificación adicional. El campo convive con `reassignment_reason`: uno razona el descarte sanitario y el otro el cambio de titular, y ambos pueden coexistir en una misma reasignación (Resolución QA 8).
* **RF-05.3: Criterios de Aceptación EARS:**
  * **[State-driven]** Mientras una avería sea crítica o perecedera, el sistema debe anteponer al técnico de guardia 24/7 frente a titulares de zona con paradas previas activas.
  * **[Unwanted behavior]** Si el coordinador intenta confirmar la asignación a un técnico no sugerido en una avería sanitaria sin aportar un texto de justificación de $\ge 20$ caracteres, el sistema debe bloquear la acción mostrando el error de validación constitucional.

### RF-06: Gestión de Zonas Desatendidas o Sin Técnicos Disponibles
* **RF-06.1: Detección y Proximidad Espacial:** Si la zona de la sede no cuenta con técnicos asignados o si todos sus integrantes están dados de baja o han alcanzado su **cupo máximo de 10 paradas vivas** cada uno (Resolución QA 14):
  * El sistema muestra la advertencia *"⚠️ Sin técnicos disponibles en esta zona"*.
  * El sistema lista a los demás técnicos activos ordenados por: (1.º) proximidad espacial respecto a su parada más reciente, y (2.º) menor carga de trabajo.
* **RF-06.2: Cálculo de Proximidad sin GPS (Dogma Vanilla):**
  * La distancia se calcula con trigonometría esférica de Haversine (`GeoDistanceService`) midiendo la separación en km entre las coordenadas `(lat, lng)` de la sede averiada y:
    1. La sede donde el técnico tenga su parada `IN_PROGRESS` actual.
    2. Si no tiene parada en curso, la sede de su última parada completada hoy.
    3. Si aún no ha iniciado ruta, las coordenadas de la Base Central.
  * **Coordenadas inválidas:** `locations.latitude`/`longitude` y `route_settings.base_latitude`/`base_longitude` son `NOT NULL` con valores por defecto, así que el escenario degradado no es «columna nula» sino coordenada inválida (no finita, `0,0` o fuera del área operativa que valida `GeoDistanceService`): se aplica una distancia neutra penalizada de **999 km**, desempatando directamente por menor carga de trabajo activa (Resolución QA 5).
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

* **RNF-01 · Rendimiento del Recomendador:** El cálculo del índice de idoneidad y la ordenación de técnicos sugeridos para una incidencia debe completarse en menos de **100 milisegundos**, garantizando apertura instantánea del panel de asignación. La medición se certifica sobre el endpoint real con una flota sembrada de 6 técnicos activos y 20 repeticiones, exigiendo **mediana < 100 ms**; el cálculo puro se certifica por determinismo y no por reloj de pared.
* **RNF-02 · Agilidad de Triaje ($\le 2$ interacciones):** La asignación del técnico recomendado debe poder completarse en un máximo de **dos interacciones** (abrir modal $\to$ confirmar asignación con botón predeterminado).
* **RNF-03 · Trazabilidad y Auditoría Atómica (Art. III):**
  * Cada cambio en la configuración de zonas, códigos postales o cuadrantes de técnicos debe registrar un evento inmutable en el registro de auditoría (`audit_log`).
  * En traslados masivos de códigos postales, se registra un evento matriz **`ZONE_POSTAL_CODE_TRANSFERRED`** (entidad `OPERATIONAL_ZONE`) con el balance global y eventos individuales **`LOCATION_ZONE_REASSIGNED`** por cada sede afectada dentro de la misma transacción.
* **RNF-04 · Consistencia Territorial Idempotente:** La reubicación de códigos postales entre zonas debe ejecutarse de forma transaccional, garantizando que jamás existan códigos postales huérfanos o duplicados en todo el catálogo.

---

## 6. Casos Límite y Reglas de Excepción

1. **Sede con Código Postal Inexistente:** Si una sede se registra con un código postal que no existe en el catálogo de zonas, la sede queda registrada con `zone_id = NULL` y clasificada como *"Sin Zona Asignada"*.
2. **Empate Técnico en la Puntuación:** Se desempata estrictamente por mayor tiempo transcurrido desde la última asignación formal (`MAX(incidents.assigned_at)`; sin asignaciones previas, `epoch`) y, si persiste, por `users.id ASC`.
3. **Desactivación de una Zona con Averías Abiertas:** Las averías activas de sus sedes permanecen asignadas a sus técnicos actuales. Las sedes pasan a `zone_id = NULL` conservando su historial, se liberan los códigos postales de la zona y se dan de baja lógica las vinculaciones de su cuadrante, todo en la misma transacción auditada.
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
* **DoD-02:** Los códigos postales (formato `^\d{5}$`) son únicos en todo el catálogo y se trasladan entre zonas con confirmación en bloque del coordinador, sin duplicarse ni dejar huérfanas las sedes heredadas.
* **DoD-03:** Las sedes heredan automáticamente la zona por su código postal y admiten anulación/asignación forzada manual (`is_manual_override = true`).
* **DoD-04:** El cuadrante técnico permite fijar 1 zona titular y múltiples suplencias por técnico, excluyendo a técnicos inactivos.
* **DoD-05:** El panel de asignación de incidencias presenta al técnico sugerido con desglose de puntuación (+50/+25 zona, +40/+20 in situ, -5/-2 carga, -15 otra sede) y asignación en 1 clic.
* **DoD-06:** Ante emergencias críticas de frío (Art. II), el sistema antepone al técnico de guardia 24/7 cuando ningún titular activo de la zona está libre de paradas previas, y exige `override_justification` $\ge 20$ caracteres si el coordinador descarta la recomendación sanitaria (Art. V.1).
* **DoD-07:** Si una zona no tiene técnicos disponibles, se muestra la advertencia y se lista a los demás técnicos por proximidad Haversine / menor carga sin bloquear el despacho.
* **DoD-08:** El portal de sede y las vistas públicas no contienen referencias a zonas ni cuadrantes (Art. V.4).
* **DoD-09:** Todas las acciones territoriales quedan registradas inmutablemente en `audit_log` con evento dual matriz-sede en traslados masivos (Art. III).
* **DoD-10:** Toda sede guarda un código postal válido (`locations.postal_code`) exigido en el alta y la edición, y la herencia de zona se recalcula al cambiarlo salvo anulación manual.
* **DoD-11:** Desactivar una zona libera códigos postales y cuadrante de forma atómica y auditada, y la baja de un técnico del cuadrante es lógica (`removed_at`), preservando el histórico (Art. III.1).

---

## 9. Resoluciones de Auditoría QA

* **Resolución QA 1 (Art. II y Art. V.1 - Salvaguarda Sanitaria y Justificación):** En averías perecederas/críticas, si el titular de zona tiene cualquier parada previa activa, se antepone obligatoriamente al técnico de guardia 24/7 (`is_on_call = true`). Ignorar la sugerencia exige justificación obligatoria $\ge 20$ caracteres.
* **Resolución QA 2 (Art. V.3 y Módulo 16 - Coexistencia entre Sedes):** Si un técnico tiene una parada `IN_PROGRESS` en otra sede distinta, se le penaliza con $-15$ puntos y advertencia visible. Si es en la misma sede, se le bonifica con $+40$ puntos.
* **Resolución QA 3 (Cálculo de Carga y "Hoy"):** Se define la carga como toda parada viva en la hoja de ruta en el momento del triaje. Averías y preventivos penalizan $-5$ puntos; pausas `PENDING_INFO` penalizan atenuadamente $-2$ puntos.
* **Resolución QA 4 (Código Postal y Ciclo de Vida):** Formato numérico estricto de 5 dígitos (`^\d{5}$`). La edición de sede recalcula zona si `is_manual_override = false`. La desactivación de zona pasa sedes a `NULL` (*"Sin Zona Asignada"*). Los traslados de códigos postales se confirman en bloque de forma atómica.
* **Resolución QA 5 (Proximidad Haversine y Desempates):** Proximidad esférica Haversine respecto a parada `IN_PROGRESS`, última parada completada o Base Central (`route_settings.base_latitude`/`base_longitude`). Coordenadas inválidas computan 999 km de penalización. Desempates resueltos deterministamente por mayor tiempo desde la última asignación (`MAX(incidents.assigned_at)`, `epoch` si nunca asignado) y, en último término, por `users.id ASC`.
* **Resolución QA 6 (Art. III - Trazabilidad Dual en Cambios Masivos):** En reubicaciones masivas se emite un evento matriz `ZONE_POSTAL_CODE_TRANSFERRED` (entidad `OPERATIONAL_ZONE`) y eventos individuales `LOCATION_ZONE_REASSIGNED` por sede afectada en la misma transacción.
* **Resolución QA 7 (Código Postal de Sede y Ciclo de Vida de la Zona — aprobada 2026-10-20):** El código postal vive en `locations.postal_code` (`VARCHAR(5)`, `CHECK (postal_code IS NULL OR postal_code ~ '^[0-9]{5}$')`), es obligatorio en el alta y la edición de sede y la migración no inventa datos: las sedes históricas sin CP quedan como *Sin Zona Asignada*. La unicidad del CP es **global entre filas vivas** (`uq_zone_postal_codes_live`, índice único parcial) y desactivar una zona libera sus CPs con baja **lógica** (`released_at`) y da de baja lógica su cuadrante en la misma transacción, con confirmación `confirm_release = true`. Reactivar la zona no restaura CPs ni cuadrantes. La liberación es un `UPDATE`, nunca un `DELETE` (Resolución QA 13).
* **Resolución QA 8 (Justificación Sanitaria y Endpoint de Despacho — aprobada 2026-10-20):** El despacho se mantiene en `PATCH /api/coordinator/incidents/{id}/assign`; la justificación viaja en `override_justification` ($\ge 20$ caracteres) y solo se exige cuando la recomendación sanitaria está activa y el técnico elegido no es el recomendado. Convive con `reassignment_reason` sin sustituirlo.
* **Resolución QA 9 (Cuadrante Titular Múltiple y Ausencia de Guardia — aprobada 2026-10-20):** El escalado del Art. II se evalúa sobre el cuadrante titular completo: solo se dispara si ningún titular activo está libre de paradas previas. Sin técnico de guardia, la recomendación recae en el técnico activo con menor carga viva y, a igual carga, el más próximo.
* **Resolución QA 10 (Baja Lógica del Cuadrante — aprobada 2026-10-20):** `technician_zone_assignments` incorpora `removed_at`; la baja es lógica (Art. III.1), la unicidad se declara parcial (`uq_technician_zone_live ... WHERE removed_at IS NULL` y `uq_technician_primary_zone ... WHERE is_primary = TRUE AND removed_at IS NULL`) y el reingreso crea una vinculación nueva y auditada.
* **Resolución QA 11 (Extensión del ENUM de Auditoría — aprobada 2026-10-20):** La migración `007` añade `OPERATIONAL_ZONE` y `TECHNICIAN_ZONE` a `audit_entity_type` con `ALTER TYPE ... ADD VALUE IF NOT EXISTS`, actualiza la declaración de `database/supabase_init.sql`, `AuditEvent::ENTITY_TYPES`, `AuditActionLabels.js` y las tres listas `CONTRACT_TABLES` (25 tablas). Como el runner envuelve cada migración en transacción, la `007` solo ejecuta DDL: ningún valor nuevo se usa hasta el commit.
* **Resolución QA 12 (Exclusividad de Bonificaciones y Medición de RNF-01 — aprobada 2026-10-20):** `+40` (misma sede) y `+20` (otras sedes de la zona) son excluyentes. RNF-01 se certifica con mediana < 100 ms con 20 repeticiones del endpoint real con 6 técnicos sembrados.
* **Resolución QA 13 (Liberación Lógica de Códigos Postales — aprobada 2026-10-20):** La liberación de CP que exige QA 7 no puede ser un `DELETE` sobre `operational_zone_postal_codes`: el Art. III.1 prohíbe el borrado físico y el disparador constitucional lo rechaza con `SQLSTATE '45000'`. Por tanto, la fila se conserva y se marca con `released_at`; la tabla obtiene una clave primaria sustituta (`id`) y la unicidad territorial pasa a un índice único parcial `uq_zone_postal_codes_live (postal_code) WHERE released_at IS NULL`. Un CP liberado queda disponible para otra zona (convive con su fila histórica), reactivar la zona de origen no lo recupera y el historial completo sigue siendo auditable. La migración `008` evoluciona las bases existentes y `supabase_init.sql` declara la forma final.
* **Resolución QA 14 (Cupo de Paradas Vivas de la Zona Desatendida — aprobada 2026-10-20):** El «cupo máximo de paradas» de RF-06.1 se fija en **10 paradas vivas por técnico** (`TechnicianAssignmentSuggester::MAX_LIVE_STOPS_PER_TECHNICIAN`), entendiendo por parada viva toda avería activa (`ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS`), toda pausa por falta de acceso (`PENDING_INFO`) y toda orden preventiva de hoy, que es la misma carga con la que puntúa el scorer (QA 3). Una zona está desatendida cuando **ningún** titular ni suplente activo se sitúa por debajo del cupo; el profesional al cupo **sigue apareciendo en el ranking y sigue siendo asignable** (RF-06.3), pero no evita la advertencia ni la ordenación por proximidad y menor carga. El umbral es una constante de dominio, no configuración persistida: así el cálculo es determinista y no depende de una tabla de ajustes que el MVP no tiene.
* **Resolución QA 15 (Lectura del Cuadrante y Consulta Inversa — aprobada 2026-10-20):** El cuadrante se lee **bajo demanda y por zona** (`GET /api/coordinator/zones/{id}/technicians`), no dentro del listado: `GET /api/coordinator/zones` sigue devolviendo solo contadores y no paga una consulta por zona (RNF-01). Cada fila publica `assignment_id`, `technician_id`, `name`, `operator_code`, `is_primary`, `is_on_call`, `is_active` y `assigned_at`; el `email` se retira de la proyección porque el panel no lo usa y una pantalla de administración no necesita más datos personales de los que muestra. La condición de baja **no** filtra: ocultarla haría inalcanzable la baja lógica de su vinculación (QA 10). La consulta inversa se sirve agregada en una sola sentencia con `LEFT JOIN` para incluir a los técnicos activos sin zona (`GET /api/coordinator/technicians/quadrants`), y su ruta estática se registra antes de cualquier `{id}` de técnico para que el enrutador —que resuelve por orden de registro— no la interprete como un identificador.
