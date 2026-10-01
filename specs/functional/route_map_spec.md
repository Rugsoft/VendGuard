# Especificación Funcional: Mapa Interactivo de Rutas y Georreferenciación (Módulo M4)

## 1. Contexto y Objetivo

### 1.1 Contexto del Negocio
En la operativa diaria de VendGuard, los técnicos de campo atienden incidencias correctivas y órdenes de mantenimiento preventivo desplazándose entre múltiples sedes y edificios. En el modelo operativo previo:
- Las paradas asignadas se presentaban en una lista vertical sin ordenación espacial ni conciencia de proximidad geográfica, lo que provocaba desplazamientos erráticos, mayor consumo de combustible y pérdida de tiempo productivo.
- Si una sede albergaba varias máquinas con averías o mantenimientos el mismo día, las tareas aparecían dispersas en la lista, obligando al técnico a deducir manualmente que debía intervenir varios equipos en la misma ubicación.
- Para trasladarse a una sede, el técnico debía copiar y pegar manualmente la dirección postal en aplicaciones externas de navegación, generando fricción, retrasos e imprecisiones cuando las direcciones no coincidían con el acceso físico.
- El coordinador de operaciones carecía de una visión espacial del parque averiado para evaluar qué incidencias estaban geográficamente próximas entre sí y asignar avisos de forma eficiente.

### 1.2 Objetivo del Módulo
El Módulo M4 (Logística de Campo: Mapa Interactivo de Rutas y Georreferenciación) tiene como propósito:
1. **Georreferenciar de manera exacta y obligatoria cada Sede física** mediante coordenadas geográficas (latitud y longitud), con asistencia visual y validación en la administración del parque, garantizando compatibilidad con el Dogma Vanilla.
2. **Consolidar múltiples intervenciones en una parada física unificada por Sede**, gestionando estados de progreso parcial y heredando de forma estricta la máxima prioridad de las máquinas asignadas en dicha ubicación.
3. **Calcular una ordenación inteligente y determinista de la ruta técnica respetando la Constitución del Proyecto (Art. II)**:
   - Parada en curso (`IN_PROGRESS`) fija en primer lugar.
   - Paradas críticas con SLA de perecederos inminente en orden de vencimiento temporal.
   - Paradas ordinarias ordenadas por el algoritmo del Vecino Más Cercano (*Nearest Neighbor*).
   - Exclusión de paradas en pausa técnica por repuestos (`PENDING_PARTS`).
4. **Proporcionar un mapa interactivo para el técnico móvil**, facilitando la visualización de la secuencia de paradas, el estado de cada ubicación y el acceso directo con un solo toque a la navegación GPS paso a paso y a la ruta completa del día.
5. **Dotar a la Coordinación de un mapa global de incidencias activas**, que permita visualizar la distribución espacial de las averías en el territorio, alertando de sedes con múltiples técnicos para agilizar el triaje y la reasignación por zonas.
6. **Blindar la privacidad del personal y la segregación de datos (Constitución Art. V.4)**, garantizando que el mapa posicione únicamente Sedes físicas fijas (cero rastreo personal continuo de trabajadores) y que los Responsables de Sede no tengan acceso alguno a información cartográfica o rutas.

---

## 2. Usuarios del Sistema

| Rol | Descripción | Responsabilidades en este Módulo |
| :--- | :--- | :--- |
| **Técnico de Ruta / Campo** | Operario en movilidad asignado a la resolución de incidencias y preventivos | Visualiza su ruta ordenada en el mapa móvil, consulta las paradas consolidadas por sede y abre la navegación GPS en Google Maps para llegar a cada destino. |
| **Coordinador del Servicio** | Supervisor operativo y administrativo del parque | Visualiza el mapa global de averías activas del parque para triaje geográfico, gestiona las coordenadas de las sedes y configura la Base Central de partida. |
| **Responsable de Ubicación / Sede** | Usuario informador del cliente en la sede donde opera la máquina | **Sin acceso:** En cumplimiento del Artículo V.4 de la Constitución, los usuarios de sede **jamás** tienen acceso a mapas de rutas, ubicaciones de otros centros ni desplazamientos técnicos. |

---

## 3. Historias de Usuario

### HU-01: Georreferenciación Asistida y Obligatoria de Sedes en Administración
**Como** Coordinador del Servicio,  
**quiero** asignar y validar las coordenadas geográficas exactas de cada sede mediante geocodificación asistida o selección sobre mapa interactivo,  
**para que** todas las ubicaciones del parque cuenten con coordenadas verificadas y validadas dentro del territorio operativo.

### HU-02: Visualización de Ruta Ordenada en el Móvil del Técnico
**Como** Técnico de Ruta que inicia su jornada laboral,  
**quiero** consultar un mapa interactivo en mi dispositivo móvil que muestre mis paradas del día ordenadas secuencialmente (priorizando cualquier tarea en curso, después las urgencias críticas de perecederos y optimizando las ordinarias por proximidad),  
**para que** organice mis desplazamientos de forma lógica, reduciendo tiempos muertos y garantizando el cumplimiento de los SLAs más exigentes.

### HU-03: Consolidación y Seguimiento de Averías y Preventivos por Sede
**Como** Técnico de Ruta que se desplaza a una ubicación,  
**quiero** que todas las incidencias y preventivos asignados a una misma sede se agrupen en una única parada física en el mapa y la lista de ruta, mostrando el progreso de resolución parcial,  
**para que** al llegar al edificio complete todas las intervenciones pendientes en una sola visita sin tener que volver más tarde.

### HU-04: Navegación GPS Directa con Google Maps
**Como** Técnico de Ruta que va a trasladarse a la siguiente parada,  
**quiero** pulsar un botón directo que abra la navegación en Google Maps hacia la sede destino (o abrir la ruta global del día completa con waypoints),  
**para que** el guiado por voz y tráfico vehicular comience al instante sin necesidad de teclear o buscar direcciones en el móvil.

### HU-05: Triaje Geográfico Central para Coordinación
**Como** Coordinador del Servicio,  
**quiero** visualizar en un mapa global todas las sedes con incidencias activas, identificando su nivel de criticidad, el técnico asignado y detectando sedes con múltiples técnicos concurrentes,  
**para que** pueda optimizar la asignación técnica agrupando tareas de la misma zona en un único operario.

---

## 4. Requisitos Funcionales (RF) con Criterios de Aceptación EARS

### 4.1 Georreferenciación de Ubicaciones Físicas

#### RF-MAP-01: Coordenadas Geográficas Obligatorias y Validación Territorial en Sedes
El sistema debe almacenar de forma obligatoria las coordenadas geográficas (latitud y longitud en formato decimal estándar WGS84) para cada Sede del parque, garantizando que se encuentren dentro del área de cobertura geográfica operativa.

* **EARS Ubicuo:** El sistema DEBE validar que la latitud se encuentre en el rango $[-90.0, 90.0]$ y la longitud en el rango $[-180.0, 180.0]$.
* **EARS Ubicuo:** El sistema DEBE validar que las coordenadas se encuentren dentro del marco territorial operativo de la empresa (Península Ibérica e Islas: latitud entre $[27.0, 44.5]$ y longitud entre $[-18.5, 5.0]$), rechazando coordenadas nulas `(0.0, 0.0)` o con ejes intercambiados.
* **EARS Evento:** Cuando el coordinador cree o edite una Sede en el módulo de administración, el sistema DEBE exigir la confirmación de coordenadas válidas antes de permitir guardar el registro en base de datos.
* **EARS Evento:** Cuando el coordinador pulse el botón de "Geocodificar dirección" en la ficha de la Sede, el sistema DEBE proponer las coordenadas resultantes a partir del texto de dirección, ciudad y código postal mediante servicio abierto estándar, permitiendo siempre la corrección manual o arrastre del marcador sobre el mapa.
* **EARS Ubicuo:** Todas las semillas maestras preexistentes de sedes en la base de datos DEBEN incorporar coordenadas geográficas reales validadas para garantizar la ejecución autónoma y offline de la suite de pruebas locales.

#### RF-MAP-02: Configuración de la Base Central Operativa
El sistema debe permitir definir las coordenadas geográficas de la Base/Taller Central de operaciones de la empresa de vending, que servirá como punto de partida de referencia para las rutas del personal técnico.

* **EARS Ubicuo:** El sistema DEBE mantener un registro de configuración operativa con las coordenadas (latitud y longitud) y dirección de la Base Central.
* **EARS Evento:** Cuando el coordinador actualice la dirección o posición de la Base Central, el sistema DEBE validar y guardar las nuevas coordenadas para su uso en los cálculos logísticos.

---

### 4.2 Agrupación, Consolidación y Estados de Paradas

#### RF-MAP-03: Consolidación de Tareas por Sede Física y Progreso Parcial
El sistema debe agrupar todas las intervenciones activas (incidencias correctivas y órdenes de mantenimiento preventivo) asignadas a un mismo técnico en una misma Sede en una única parada física de ruta, reflejando el progreso conforme se resuelven equipos individuales.

* **EARS Ubicuo:** El sistema DEBE generar una única parada física en el mapa y en la lista de ruta para cada Sede que cuente con tareas asignadas al técnico.
* **EARS Evento:** Cuando una Sede tenga múltiples tareas asignadas (por ejemplo, 2 incidencias y 1 preventivo), el sistema DEBE agruparlas bajo la misma parada y mostrar un contador de progreso visible (ej. *"0 de 3 completadas"*).
* **EARS Estado:** Mientras reste al menos una intervención pendiente en la Sede, la parada DEBE permanecer activa en la ruta.
* **EARS Evento:** Cuando el técnico resuelva una de las máquinas de la Sede mientras queden otras pendientes, el sistema DEBE actualizar el contador de progreso (ej. *"1 de 3 completadas"*), recalculando el nivel de urgencia si la tarea resuelta era la única de perecederos.
* **EARS Evento:** Cuando se hayan resuelto todas las máquinas asignadas en dicha Sede, la parada DEBE transicionar al estado "Completada" (mostrándose en color atenuado con distintivo de verificación verde).

#### RF-MAP-04: Herencia de Máxima Criticidad por Parada
El sistema debe aplicar la regla de herencia de criticidad más restrictiva sobre cada parada consolidada.

* **EARS Ubicuo:** Si al menos una de las intervenciones agrupadas en una Sede corresponde a una incidencia de máxima prioridad (**CRÍTICA / Perecederos, Art. II**), la parada completa DEBE ser clasificada como **Parada Crítica**.
* **EARS Evento:** Cuando todas las intervenciones pendientes de una Sede sean de prioridad estándar (altas no perecederas, medias o preventivos), la parada DEBE clasificarse como **Parada Ordinaria / Preventiva**.
* **EARS Evento:** Si una parada crítica tiene su intervención de perecederos resuelta mientras restan tareas ordinarias pendientes, el sistema DEBE recalcular automáticamente la criticidad de la parada al estado ordinario remanente.

---

### 4.3 Algoritmo de Secuenciación y Ordenación de Ruta

#### RF-MAP-05: Ordenación Híbrida y Determinista de Ruta (Vecino Más Cercano)
El sistema debe calcular la secuencia de visita de paradas de la jornada respetando estrictamente la jerarquía constitucional de prioridades y optimizando las restantes mediante el algoritmo del Vecino Más Cercano (*Nearest Neighbor*).

* **EARS Ubicuo (Fase 1 - Intervención en Curso):** Si el técnico tiene una avería en estado iniciada (`IN_PROGRESS`), dicha parada DEBE fijarse de forma inamovible como la **Parada #1 (En curso)**.
* **EARS Ubicuo (Fase 2 - Paradas Críticas por SLA):** Las siguientes paradas de la secuencia DEBEN ser todas las **Paradas Críticas (Perecederos, Art. II)**, ordenadas estrictamente entre sí por el vencimiento de SLA más inminente (< 4.0h).
* **EARS Ubicuo (Fase 3 - Paradas Ordinarias por Proximidad):** Las **Paradas Ordinarias** restantes DEBEN ordenarse encadenadamente mediante el algoritmo del Vecino Más Cercano (*Nearest Neighbor*), seleccionando en cada paso la parada no visitada cuya distancia geográfica (fórmula de Haversine) sea menor respecto al hito inmediatamente anterior.
* **EARS Ubicuo (Fase 4 - Exclusión de Repuestos Pendientes):** Las incidencias que se encuentren en pausa técnica esperando piezas (`PENDING_PARTS`) DEBEN quedar automáticamente **excluidas** de la ruta y del mapa hasta que el material sea recepcionado y la incidencia reanudada.
* **EARS Evento:** Cuando el técnico complete todas las intervenciones de una parada, el sistema DEBE recalcular dinámicamente la secuencia de las paradas restantes tomando como origen la ubicación actual del técnico.

#### RF-MAP-06: Determinación Dinámica del Punto de Origen del Técnico
El sistema debe determinar el punto de partida para el cálculo de proximidad según la disponibilidad de la ubicación del dispositivo del técnico.

* **EARS Evento:** Cuando el técnico cargue su vista de ruta y conceda permisos de geolocalización en su navegador móvil, el sistema DEBE utilizar las coordenadas GPS en tiempo real del dispositivo como punto de inicio de la ruta.
* **EARS Excepción:** Si el técnico deniega los permisos de geolocalización, el dispositivo carece de sensor GPS o se produce un error de lectura, el sistema DEBE utilizar automáticamente las coordenadas de la Base Central como origen de la ruta sin interrumpir el funcionamiento de la aplicación.
* **EARS Evento:** Cuando se aplique el origen por Base Central, el sistema DEBE mostrar un aviso informativo indicando que la ruta se calcula a partir del taller central debido a la indisponibilidad del GPS local.

---

### 4.4 Interfaz Cartográfica Móvil para el Técnico

#### RF-MAP-07: Mapa Interactivo en la Vista "Mi Ruta"
El sistema debe incorporar en la vista móvil del técnico un mapa interactivo responsive optimizado para pantalla vertical que represente la jornada asignada.

* **EARS Ubicuo:** El mapa DEBE mostrar marcadores numéricos secuenciales (1, 2, 3...) que identifiquen claramente el orden de visita calculado para cada parada.
* **EARS Ubicuo:** El mapa DEBE diferenciar visualmente mediante simbología y colores normalizados del sistema de diseño:
  - Parada en curso actual (ámbar / en progreso).
  - Paradas Críticas (rojo / advertencia perecederos).
  - Paradas Ordinarias con incidencias (azul / color primario).
  - Paradas exclusivas de Mantenimiento Preventivo (verde / preventivo).
  - Paradas Completadas (gris / atenuado con check verde).
  - Posición actual del técnico o Base Central (indicador de inicio).
* **EARS Evento:** Cuando el técnico pulse sobre un marcador del mapa, el sistema DEBE centrar la vista en dicho punto acercando la escala hasta un factor **×2 sobre el encaje** (idempotente: nunca más lejos que ×2 ni acumulativo) y abrir una ficha resumen con: nombre de sede, dirección, progreso de máquinas a intervenir, nivel de urgencia y botón directo de navegación.
* **EARS Evento:** Cuando el técnico seleccione una parada en la lista textual de la ruta, el mapa DEBE sincronizarse automáticamente resaltando y encuadrando el marcador correspondiente con el mismo enfoque de escala ×2.

#### RF-MAP-08: Apertura Directa de Navegación GPS en Google Maps
El sistema debe proporcionar acceso con un solo toque a la navegación vehicular asistida en Google Maps desde el dispositivo móvil o navegador de escritorio.

* **EARS Evento:** Cuando el técnico pulse el botón *"Navegar con GPS"* en una parada específica desde un dispositivo móvil, el sistema DEBE invocar directamente la aplicación nativa de Google Maps (o la aplicación predeterminada de navegación en Android/iOS) fijando como destino las coordenadas exactas de dicha sede mediante enlace universal de navegación vehicular.
* **EARS Evento:** Cuando se pulse *"Navegar con GPS"* desde un navegador de escritorio (Desktop), el sistema DEBE abrir una nueva pestaña con la web de Google Maps fijando el destino correspondiente.
* **EARS Evento:** Cuando el técnico pulse el botón general *"Abrir ruta completa en Google Maps"*, el sistema DEBE generar un enlace universal de ruta que concatene como waypoints la secuencia completa de paradas del día en el orden fijado por el sistema.
* **EARS Excepción:** Si la ruta completa supera el número máximo de paradas intermedias soportado por el servicio de mapas en URL (máximo estándar de 9 paradas intermedias), el sistema DEBE enlazar las primeras paradas prioritarias hasta el límite admitido e informar al usuario con una advertencia explicativa.

---

### 4.5 Mapa de Coordinación y Triaje Geográfico

#### RF-MAP-09: Mapa Global de Averías del Parque para Coordinación
El sistema debe ofrecer en el panel de Coordinación una vista de mapa de gran formato con todas las incidencias y preventivos activos en el territorio.

* **EARS Ubicuo:** El mapa de coordinación DEBE representar todas las Sedes con intervenciones activas no resueltas.
* **EARS Ubicuo:** Cada marcador en el mapa de coordinación DEBE indicar:
  - Criticidad máxima de la sede (icono/color distintivo para averías perecederas).
  - Cantidad total de averías y preventivos pendientes en esa sede.
  - Nombre del técnico asignado o indicación clara de *"Sin Asignar"*.
* **EARS Evento:** Cuando una misma Sede tenga tareas asignadas concurrentemente a más de un técnico, el sistema DEBE mostrar un distintivo multi-técnico (ej. *"2 técnicos asignados: Jordi / Marta"*), permitiendo al coordinador reasignar tareas para consolidar la visita en un único operario.
* **EARS Evento:** Cuando el coordinador filtre por técnico, estado de incidencia o tipología de máquina en el panel de triaje, el mapa DEBE actualizar reactivamente los marcadores mostrados.
* **EARS Evento:** Cuando el coordinador haga clic en una sede sin asignar dentro del mapa, el sistema DEBE permitir iniciar el flujo de asignación técnica para las incidencias de dicha ubicación.
* **EARS Evento:** Cuando el coordinador pulse un marcador de cualquier sede (asignada o sin asignar), el sistema DEBE encuadrar la vista sobre esa sede acercando la escala hasta un factor **×2 sobre el encaje**, de forma **idempotente**: si la vista ya está más cerca, sólo recentra, y pulsar de nuevo el mismo marcador NO vuelve a acercar. Si la sede no tiene tareas sin asignar, el pulsado NO DEBE abrir ningún flujo de asignación.

> **Nota de diseño (fase de refinamiento de la UX):** el reencuadre con acercamiento responde a que, en la vista de encaje metropolitano, "centrar" un marcador no produce ningún cambio visible para el usuario. El factor ×2 es el mínimo que hace legible el marcador y su entorno inmediato sin perder el contexto territorial. La idempotencia evita el efecto "zoom progresivo" al pulsar repetidamente sobre el mismo marcador.

---

### 4.6 Blindaje Constitucional, Privacidad y Segregación de Datos

#### RF-MAP-10: Blindaje de Acceso y Privacidad Laboral (Constitución Art. IV y Art. V.4)
El sistema debe garantizar la privacidad del personal técnico y la segregación estricta de datos cartográficos.

* **EARS Ubicuo:** Los usuarios con rol de **Responsable de Sede** NO DEBEN tener acceso en ningún caso al mapa de rutas, a las ubicaciones de otras sedes del cliente, ni a los destinos y desplazamientos de los técnicos (Art. V.4).
* **EARS Ubicuo:** El técnico de campo ÚNICAMENTE DEBE visualizar en su mapa de ruta las paradas que tenga formalmente asignadas para su propia jornada de trabajo, sin visibilidad de las rutas de otros compañeros.
* **EARS Ubicuo:** El mapa de coordinación DEBE representar exclusivamente las posiciones físicas de las Sedes con incidencias y su técnico asignado al ticket. Queda terminantemente PROHIBIDO realizar cualquier rastreo continuo en segundo plano o geolocalización personal del smartphone del trabajador fuera de la consulta puntual de cálculo de ruta en cliente (Art. V.4).
* **EARS Ubicuo:** La implementación cartográfica DEBE ajustarse al **Dogma Vanilla** (Art. IV), utilizando librerías ligeras estándar en ES Modules puros para el navegador, teselas estándar y soporte para ejecución autónoma sin dependencias externas en tiempo de ejecución.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-MAP-01 (Rendimiento de Cálculo de Ruta):** El algoritmo del Vecino Más Cercano y ordenación híbrida para una jornada técnica (hasta 30 paradas) debe ejecutarse en el servidor en un tiempo inferior a 50 milisegundos.
* **RNF-MAP-02 (Carga y Renderizado Cartográfico):** El mapa interactivo debe renderizarse en pantalla en menos de 1.0 segundo tras recibir los datos de paradas, garantizando una interacción táctil fluida (zoom y paneo a 60 fps) en smartphones estándar.
* **RNF-MAP-03 (Compatibilidad Universal de Navegación):** Los enlaces de lanzamiento de navegación GPS deben utilizar estándares universales compatibles tanto con dispositivos móviles Android como iOS, abriendo la app nativa instalada o nueva pestaña web sin requerir APIs propietarias de pago en el cliente.
* **RNF-MAP-04 (Autonomía ante Pérdida de Cobertura GPS):** Si el navegador pierde la señal GPS durante la jornada, la interfaz debe mantener la última secuencia de paradas calculada y permitir la navegación individual a cada parada sin bloquearse ni mostrar pantallas en blanco.
* **RNF-MAP-05 (Autonomía de Pruebas Offline):** Toda la suite automatizada de pruebas (`php tests/run_all.php`) debe ejecutarse de forma 100% autónoma en local sin realizar peticiones de red salientes a servicios de mapas ni APIs de geocodificación de terceros.
* **RNF-MAP-06 (Consistencia con el Sistema de Diseño):** Todos los elementos cartográficos (fichas de parada, marcadores, leyendas y botones de acción) deben cumplir rigurosamente las pautas de diseño del proyecto: bordes técnicos de 4px a 8px, colores semánticos definidos y tipografía institucional.

---

## 6. Casos Límite y Manejo de Situaciones Excepcionales

1. **Rechazo o indisponibilidad de permisos de geolocalización en el móvil:**  
   El sistema conmuta de forma transparente al origen de la Base Central sin mostrar mensajes de error fatales; la interfaz muestra un distintivo informativo: *"Ruta calculada desde Base Central (GPS móvil no disponible)"*.
2. **Incidencia en curso (`IN_PROGRESS`) al abrir la ruta:**  
   La Sede correspondiente se sitúa automáticamente e inamoviblemente en la posición #1 de la ruta con la etiqueta *"En Curso"*; el resto de paradas se ordenan a partir de ella.
3. **Incidencias en pausa técnica por repuestos (`PENDING_PARTS`):**  
   Quedan excluidas de la ruta y del mapa de paradas del día. Al reanudarse tras recibir piezas, el sistema las reincorpora en la secuencia.
4. **Múltiples intervenciones con distinta prioridad en la misma Sede:**  
   La parada se clasifica con la máxima prioridad presente (Crítica si hay perecederos). Al resolver el equipo perecedero mientras restan otros ordinarios, la parada pierde el distintivo crítico y actualiza su progreso.
5. **Incorporación sobrevenida de una nueva incidencia urgente durante la jornada:**  
   Si el coordinador asigna una nueva avería crítica al técnico mientras este se encuentra en ruta, el sistema recalcula la secuencia de paradas restantes, situando la nueva parada crítica en la posición prioritaria correspondiente según su vencimiento de SLA.
6. **Ruta con más paradas que el límite admitido por Google Maps en un solo enlace (waypoints):**  
   Google Maps admite un máximo estándar de 10 puntos (origen + 9 waypoints) en enlaces URL públicos. Si la ruta del día supera este número, el botón de "Ruta completa" concatenará las primeras paradas prioritarias hasta el límite permitido y mostrará un aviso: *"Mostrando las primeras 9 paradas prioritarias en el navegador; utilice el botón individual para las paradas siguientes"*.
7. **Dos o más paradas a la misma distancia geográfica exacta:**  
   El desempate se realiza en primer lugar por antigüedad de la incidencia (la más antigua primero) y subsidiariamente por identificador de Sede.
8. **Técnico sin paradas asignadas en la jornada:**  
   El mapa muestra únicamente el punto de inicio (su posición GPS o la Base Central) acompañado de un mensaje amigable: *"No tienes paradas asignadas para la ruta de hoy"*.

---

## 7. Fuera de Alcance (Exclusiones Explícitas)

Para cumplir con el Artículo VI de la Constitución (**Anti-Feature Creep**) y acotar el esfuerzo al valor logístico real, quedan expresamente excluidas de este módulo las siguientes funcionalidades:
1. **Telemetría o rastreo GPS continuo en tiempo real de vehículos o personas:** No se almacenará ni transmitirá un tracking continuo en segundo plano de la posición de las furgonetas o de los smartphones de los técnicos vía satélite. Solo se utiliza la posición puntual solicitada por el navegador del técnico para calcular la ruta al cargar la pantalla.
2. **Cálculo de tráfico en tiempo real dentro del motor del servidor:** El servidor calculará distancias geográficas y secuencias espaciales; el tráfico vehicular en tiempo real lo gestionará directamente Google Maps al abrir la aplicación de navegación en el móvil.
3. **Optimización con algoritmos de tráfico pesado o restricciones de gálibo/tonelaje:** No se contemplan restricciones de camiones pesados, túneles o peajes en el cálculo interno del orden de paradas.
4. **Firma digital o captura de coordenadas GPS obligatoria al cerrar el ticket:** La georreferenciación de cierre físico no forma parte de este módulo (pertenece a la trazabilidad de resolución del núcleo MVP).
5. **Turnos de reparto o cuadrantes de técnicos:** La asignación horaria laboral o cuadrantes de calendario no forman parte de este módulo.

---

## 8. Criterios de Finalización (Done Criteria)

El Módulo M4 se considerará completado cuando se satisfagan las siguientes condiciones:
1. Las Sedes dispongan de campos de latitud y longitud validados obligatoriamente en base de datos dentro del marco territorial operativo, con asistencia interactiva de geocodificación y migración de semillas preexistentes.
2. La vista móvil "Mi Ruta" integre un mapa interactivo responsive que agrupe tareas por sede, muestre el progreso de resolución parcial y ordene la secuencia de paradas respetando estrictamente:
   - Parada en curso (`IN_PROGRESS`) en primer lugar.
   - Paradas críticas de perecederos después (ordenadas por vencimiento de SLA).
   - Paradas ordinarias después (ordenadas por el algoritmo del Vecino Más Cercano).
   - Exclusión de paradas pausadas por repuestos (`PENDING_PARTS`).
3. Cada parada de la ruta disponga de un botón de navegación directa que abra Google Maps con el destino exacto (en app móvil o pestaña web en desktop), y exista un botón general para abrir la secuencia completa del día con waypoints.
4. El panel de Coordinación disponga de una vista de mapa territorial con todas las incidencias y preventivos activos, identificando sedes con múltiples técnicos para agilizar la asignación por proximidad.
5. Se verifique la ausencia total de exposición de datos cartográficos a usuarios con rol de Responsable de Sede (Constitución Art. V.4) y la ausencia de rastreo personal continuo de trabajadores.
6. Todas las suites de pruebas asociadas (unitarias, reactivas e integración) pasen al 100% en verde con cero fallos y cero regresiones sobre los módulos previos en ejecución local autónoma (Constitución Art. I y IV).

---

## 9. Dudas Abiertas

*Actualmente no existen dudas abiertas pendientes. Todas las ambigüedades respecto a criterios de ordenación, origen de ruta, consolidación por sede, visualización por roles, mecanismos de navegación, casos límite de estados (`PENDING_PARTS`/`IN_PROGRESS`) y blindaje constitucional han quedado formalmente resueltas y aprobadas durante la ronda de especificación.*
