# ESPECIFICACIÓN FUNCIONAL: AVERÍA OPORTUNISTA IN SITU (ALTA Y ASUNCIÓN EN RUTA)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 16 · Avería Oportunista In Situ  
**Estado:** Especificación Formal Consolidada y Auditada tras QA (Fase 2 SDD - Gate)  
**Fecha:** Octubre 2026  
**Alineación Constitucional:** Artículos I al VII de la Constitución de VendGuard (Especialmente Art. II, Art. III, Art. V.1, Art. V.2, Art. V.3, Art. V.4, Art. V.5 y Art. V.6)

---

## 1. Contexto y Objetivo

### 1.1. Contexto Operativo
En la operativa diaria de mantenimiento de máquinas expendedoras, es habitual que cuando un Técnico de Campo acude físicamente a una sede para atender una parada programada en su ruta (ya sea una avería correctiva o una orden de mantenimiento preventivo), ocurra una de estas dos situaciones sobre **otra máquina distinta ubicada en esa misma sede**:
1. **Aviso verbal o hallazgo directo sin expediente previo:** El responsable del centro o los usuarios del edificio se acercan al técnico en el pasillo y le comunican verbalmente que otra máquina del recinto acaba de fallar (por ejemplo: *"ya que estás aquí, la máquina de café del segundo piso tampoco echa vasos"*), o bien el propio técnico detecta visualmente una anomalía en la máquina contigua.
2. **Incidencia ya abierta en la misma sede pero no asignada a su intervención actual:** Esa segunda máquina ya tenía un aviso registrado en el sistema (pendiente de asignar en la bandeja de Coordinación, asignado a otro compañero que aún no se ha desplazado, pausado en espera de repuesto o información, o recién resuelto en periodo de garantía de 48 horas), y el técnico que ya se encuentra físicamente frente a la máquina puede repararla en el acto.

Sin un flujo específico de **Avería Oportunista In Situ**, el técnico se ve forzado a elegir entre dos malas prácticas:
* **Reparar "en negro" sin registrar nada**, violando el **Artículo III (Trazabilidad Histórica Absoluta)**, falseando las métricas de fiabilidad de la máquina (MTBF/MTTR) y dejando sin garantía de 48 horas la intervención.
* **Pedir a la sede que abra un ticket normal y llamar por teléfono al Coordinador** para que lo busque, se lo asigne y luego el técnico recargue su ruta y lo inicie, provocando fricción operativa, demoras en el pasillo y riesgo de crear avisos duplicados (**Artículo V.2**).

### 1.2. Objetivo
Dotar al Técnico de Campo de un flujo ágil, trazable y blindado por las reglas de negocio para **consultar el resto del parque de máquinas de la sede donde se encuentra trabajando** y ejecutar una **Intervención Oportunista In Situ** en dos modalidades diferenciadas:
1. **Alta Oportunista In Situ (*"Oportunista In Situ · Nueva"*):** Si la otra máquina de la sede no tiene ningún expediente activo ni en garantía, permitir al técnico abrir el parte de avería indicando el origen del hallazgo (*"Aviso verbal en sede"* o *"Detección propia del técnico"*), naciendo el expediente directamente asignado a su nombre y con la intervención **En Curso** para que pueda documentar y resolver la avería en el acto.
2. **Asunción Oportunista In Situ (*"Oportunista In Situ · Asumida"*):** Si la otra máquina de la sede ya cuenta con un expediente abierto o en ventana de garantía de 48 horas (y no está siendo intervenida simultáneamente por otro técnico), permitir al técnico **asumirlo (o reabrirlo y asumirlo) en el acto**, pasando directamente a estado **En Curso** bajo su titularidad, evitando duplicidades (**Artículo V.2**), notificando por correo electrónico a un eventual técnico anterior para ahorrarle un desplazamiento en vano, y protegiendo en todo momento la prioridad suprema de la cadena de frío (**Artículo II**).

---

## 2. Usuarios y Perfiles Implicados

| Perfil / Actor | Rol en la Avería Oportunista In Situ | Permisos y Restricciones |
| :--- | :--- | :--- |
| **📱 Técnico de Campo / Ruta** | Actor principal. Consulta las demás máquinas de la sede donde mantiene una parada activa (o dentro de la ventana de gracia de 30 minutos tras finalizar o pausar su parada en esa sede) y registra un **Alta Oportunista** o ejecuta una **Asunción Oportunista**. | Solo puede operar sobre máquinas operativas de una sede habilitada. No puede asumir expedientes que otro compañero ya tenga **En Curso** (**Art. V.3**), ni operar si dejó olvidada una intervención **En Curso** en otra sede distinta. |
| **🏢 Responsable de Ubicación (Sede)** | Recibe notificación de confirmación por correo electrónico cuando el técnico abre un **Alta Oportunista In Situ** o ejecuta una **Reapertura In Situ** en sus instalaciones, y visualiza el avance en su Portal de Sede. | Mantiene intacta la segregación de privacidad (**Art. V.4**): el técnico figura siempre bajo el alias *"Servicio Técnico Oficial · Operador #[Código]"*, y la sede jamás ve notas internas de traspaso, diagnósticos de taller ni costes. |
| **📊 Coordinador del Servicio** | Supervisa en tiempo real las aperturas y asunciones oportunistas, recibe alertas inmediatas si la avería oportunista es crítica/sanitaria (**Art. II**), si el técnico decide posponer una emergencia de frío en otra sede o si hubo traspaso entre técnicos, y analiza el indicador de desplazamientos evitados. | Visualiza en el expediente y en los paneles las etiquetas diferenciadas **"Oportunista In Situ · Nueva"** y **"Oportunista In Situ · Asumida"**, sumándose ambas en el indicador global de desplazamientos evitados. |
| **📱 Técnico Saliente (Reemplazado)** | Técnico que tenía previamente **Asignada** (o pausada a su nombre) una incidencia aún no iniciada cuando un compañero presente físicamente en esa sede la asume oportunistamente. | Recibe aviso inmediato de desasignación **exclusivamente por Correo Electrónico** (sin canal de mensajería instantánea, en coherencia con el Módulo 15) y se cancela por obsolescencia cualquier aviso de asignación previo que aún estuviera pendiente de despacho en cola. |
| **👥 Ciudadano / Usuario QR** | Consumidor que abrió originalmente por código QR un ticket que posteriormente es asumido o reabierto in situ por el técnico. | Si facilitó su correo al abrir el ticket original, recibe la confirmación de resolución final (también tras una reapertura in situ) con datos públicos neutros, sin ver jamás el nombre opcional de informantes de sede, notas internas ni motivos de traspaso (**Art. V.4**). |

---

## 3. Historias de Usuario

### HU-01: Consulta del Parque de Máquinas de la Sede Durante la Visita o Ventana de Gracia
> **Como** Técnico de Campo que se encuentra atendiendo una parada en una sede (o que acaba de cerrarla o pausarla hace menos de 30 minutos),  
> **quiero** visualizar desde la tarjeta de mi parada activa —o desde una tarjeta temporal de *"Sede recién visitada (30 min)"*— el listado de las demás máquinas instaladas en esa misma sede junto con su estado operativo actual,  
> **para** identificar rápidamente cualquier segunda máquina que me reporten en persona o que yo mismo detecte averiada antes de abandonar el edificio.

### HU-02: Alta Oportunista In Situ en Máquina sin Expediente Activo
> **Como** Técnico de Campo presente en una sede,  
> **quiero** registrar en un solo paso una avería nueva sobre otra máquina de esa misma sede (indicando si fue por aviso verbal en el centro o por detección propia), quedando asignada a mí y **En Curso** desde el primer instante con la etiqueta *"Oportunista In Situ · Nueva"*,  
> **para** repararla en el acto y dejar constancia histórica, diagnóstica y de garantía sin depender de llamadas a Coordinación.

### HU-03: Asunción Oportunista In Situ sin Duplicar Expedientes (Art. V.2)
> **Como** Técnico de Campo presente en una sede,  
> **quiero** que si la máquina que voy a atender ya tiene un expediente abierto (sin asignar, asignado a otro compañero que aún no ha empezado, pausado o en garantía de 48h), el sistema me permita asumirlo directamente y pasarlo a **En Curso** con la etiqueta *"Oportunista In Situ · Asumida"* en lugar de crear un ticket duplicado,  
> **para** aprovechar que ya estoy delante de la máquina, resolver el problema en una sola visita y ahorrar un viaje innecesario a mi compañero.

### HU-04: Salvaguarda de Cadena de Frío con Alerta a Coordinación (Art. II)
> **Como** Técnico de Campo y Coordinador del Servicio,  
> **quiero** que el sistema advierta de forma destacada al técnico antes de abrir o asumir una avería oportunista si tiene esperando en otra sede de su ruta una emergencia **Crítica** o con riesgo sanitario de cadena de frío activo, y que si el técnico confirma quedarse en la sede actual se envíe de inmediato una alerta a Coordinación,  
> **para** que el técnico decida con pleno conocimiento in situ y el Coordinador pueda reasignar de inmediato la urgencia de frío a otro compañero libre si fuera necesario (**Artículo II**).

### HU-05: Comunicaciones Desacopladas, Obsolescencia y Respeto de Canales
> **Como** Responsable de Sede, Coordinador o Técnico previamente asignado,  
> **quiero** recibir únicamente los avisos pertinentes según mi rol y canal (correo a la Sede en altas y reaperturas in situ, y correo de desasignación al técnico saliente cancelando envíos pendientes obsoletos),  
> **para** mantener sincronizada la operativa sin ruido, sin mensajes contradictorios y con total blindaje de privacidad (**Artículo V.4**).

---

## 4. Requisitos Funcionales y Criterios de Aceptación (Notación EARS)

### RF-01: Condición de Habilitación de "Visita en la Sede", Ventana de Gracia y Acceso en Pantalla
*El sistema habilitará la acción oportunista únicamente en las sedes donde el técnico tenga presencia operativa actual o reciente, garantizando siempre un punto de entrada visible en su ruta.*

* **RF-01.1 [EARS - Condicional / Habilitación por Parada Activa o Ventana de Gracia de 30 Minutos]:**  
  CUANDO un Técnico de Campo consulte su hoja de ruta, el sistema DEBE habilitar la opción de **Incidencia Oportunista In Situ** para una sede determinada SI Y SOLO SI se cumple al menos una de las siguientes condiciones:
    1. **Parada activa vigente:** El técnico tiene asignada actualmente en esa sede al menos una incidencia correctiva en estado **Asignada** o **En Curso**, o bien una orden de **Mantenimiento Preventivo** activa en estado **Asignada / Programada** o **En Curso**. *(Nota: un expediente pausado en "Pendiente de Información" o "Pendiente de Repuesto" no mantiene habilitada la sede de forma indefinida; solo activa la ventana de gracia del punto 2 en el instante de pausarse).*
    2. **Ventana de gracia de 30 minutos tras finalizar o pausar:** El técnico ha finalizado (**Resuelta** en correctivo o **Completada** en preventivo) o ha pausado (**Pendiente de Repuesto** o **Pendiente de Información**) una intervención en esa sede dentro de los **últimos 30 minutos naturales**, aún cuando no le quede ninguna otra parada activa en dicho edificio.
* **RF-01.2 [EARS - Estado / Tarjeta Temporal de "Sede Recién Visitada (30 min)" en la Hoja de Ruta]:**  
  MIENTRAS una sede se encuentre habilitada únicamente por la **ventana de gracia de 30 minutos** del RF-01.1.2 (porque el técnico ya resolvió o pausó la última parada activa que tenía en ese edificio), el sistema DEBE mantener visible en la pantalla de ruta del técnico una tarjeta o bloque temporal de **"Sede recién visitada (disponible durante X min por si surge otra avería in situ)"** con el botón de acceso directo al catálogo de máquinas de esa sede, garantizando el cumplimiento de las 3 interacciones máximas del RNF-02.
* **RF-01.3 [EARS - Condicional / Margen de Tolerancia de +5 Minutos con Formulario Abierto]:**  
  SI el técnico abrió en pantalla el formulario de Alta o Asunción Oportunista mientras la sede estaba válidamente habilitada (dentro de los 30 minutos de gracia o con parada asignada), el sistema DEBE conceder un **margen de tolerancia adicional de 5 minutos** (hasta el minuto 35 desde el cierre/pausa previo) para confirmar y enviar el formulario sin rechazar la operación ni perder los datos redactados.
* **RF-01.4 [EARS - Dirigido por eventos / Catálogo de Máquinas de la Sede]:**  
  CUANDO el técnico invoque la vista de máquinas de una sede habilitada, el sistema DEBE mostrar todas las máquinas instaladas en dicha sede (excepto las dadas de baja definitiva como **Inactivas**), indicando para cada máquina: código identificativo, modelo/tipo de surtido, ubicación específica dentro del edificio, estado operativo (incluyendo si tiene alerta de bloqueo de acceso de 72h o cuarentena sanitaria) y si posee un expediente activo o en garantía de 48 horas.
* **RF-01.5 [EARS - Excepcional / Prohibición en Máquinas Inactivas]:**  
  SI una máquina de la sede se encuentra dada de baja definitiva en el inventario (**Inactiva**), el sistema NO DEBE permitir el registro ni la asunción de averías oportunistas sobre ella.

---

### RF-02: Coexistencia y Ordenación de Intervenciones "En Curso" (Misma Sede vs. Sede Distinta)
*El sistema permitirá atender simultáneamente varias máquinas dentro del mismo edificio ordenadas por criticidad, pero impedirá abrir intervenciones en una sede si el técnico dejó olvidada una intervención abierta en otro edificio.*

* **RF-02.1 [EARS - Condicional / Coexistencia Permitida y Ordenación dentro de la Misma Sede]:**  
  MIENTRAS el técnico tenga una o más intervenciones en estado **En Curso** dentro de una sede física (sean correctivas o preventivas), el sistema DEBE permitirle dar de alta o asumir oportunistamente otra incidencia en **esa misma sede**, pasando la nueva incidencia también a estado **En Curso**. Todas las intervenciones **En Curso** de esa misma sede DEBEN mostrarse agrupadas en la Parada #1 de su hoja de ruta, **ordenadas entre sí por prioridad estricta** (1.º incidencias correctivas por mayor urgencia y menor tiempo restante de SLA; 2.º órdenes de mantenimiento preventivo), manteniendo cada máquina sus propios botones individuales de pausa y resolución.
* **RF-02.2 [EARS - Excepcional / Bloqueo por Intervención Olvidada en Otra Sede Distinta]:**  
  SI el técnico intenta dar de alta o asumir una avería oportunista en una **Sede A**, pero mantiene todavía en estado **En Curso** una incidencia correctiva o un mantenimiento preventivo en una **Sede B distinta**, el sistema DEBE **bloquear** la operación y mostrar un mensaje claro indicándole qué expediente y máquina tiene abiertos en la Sede B para que primero los finalice o pause antes de iniciar trabajo en el nuevo edificio.

---

### RF-03: Salvaguarda de Prioridad Sanitaria y Cadena de Frío con Alerta a Coordinación (Artículo II)
*El sistema protegerá la prioridad suprema de las emergencias térmicas/perecederas advirtiendo al técnico antes de asumir carga adicional y alertando de inmediato a Coordinación si decide posponer su desplazamiento hacia la urgencia crítica.*

* **RF-03.1 [EARS - Condicional / Criterio de Advertencia No Bloqueante por Emergencia Crítica o Térmica en Ruta]:**  
  CUANDO el técnico solicite dar de alta o asumir una avería oportunista en su sede actual y tenga asignada en su hoja de ruta otra parada pendiente en una **sede distinta** que cumpla cualquiera de estas dos condiciones:
    1. Esté calificada con nivel de urgencia **Crítica**, o bien
    2. Afecte a una máquina bajo vigilancia sanitaria (**Comida Perecedera** o **Combo Refrigerado**) con riesgo térmico/eléctrico o reloj biológico de cadena de frío activo (**Artículo II**),  
  el sistema DEBE mostrar una **advertencia destacada de prioridad sanitaria (Artículo II)** detallando la máquina crítica que le espera, su sede y el tiempo restante de SLA o de reloj biológico, exigiendo la **confirmación expresa del técnico** antes de proceder.
* **RF-03.2 [EARS - Dirigido por eventos / Decisión del Técnico y Alerta Inmediata a Coordinación por Postergación de Frío - Art. II]:**  
  El sistema NO DEBE bloquear rígidamente al técnico ante la advertencia del RF-03.1; sin embargo, CUANDO el técnico confirme expresamente quedarse atendiendo la avería oportunista in situ teniendo una emergencia del RF-03.1 esperando en otra sede, el sistema DEBE de forma atómica:
    1. Permitir el alta o asunción oportunista y registrar en la auditoría la confirmación expresa de la advertencia sanitaria.
    2. **Encolar de inmediato una alerta prioritaria dirigida a todos los Coordinadores de Operaciones activos (y al Canal Corporativo de Emergencias si la parada postergada tiene vigilancia sanitaria del Art. II)** informando de que el técnico ha asumido una intervención oportunista en su sede actual mientras tiene pendiente la emergencia crítica en otra sede, para que Coordinación pueda supervisar el tiempo o reasignar inmediatamente la emergencia de frío a otro técnico disponible.

---

### RF-04: Flujo A — Alta Oportunista In Situ (Máquina sin Expediente Activo)
*Cuando la máquina afectada en la sede no tenga ningún expediente abierto ni en garantía, el técnico podrá crear el parte e iniciar la intervención en un único acto bajo la etiqueta "Oportunista In Situ · Nueva".*

* **RF-04.1 [EARS - Condicional / Datos Obligatorios y Validación Constitucional de Imagen - Art. V.5]:**  
  CUANDO el técnico seleccione una máquina de la sede que no posea ningún expediente activo ni en ventana de garantía de 48 horas, el sistema DEBE presentar el formulario de **Alta Oportunista In Situ** exigiendo obligatoriamente:
    1. **Categoría del fallo** (dentro del catálogo oficial de averías del sistema).
    2. **Motivo de origen in situ**, debiendo elegir entre:
       * *"Aviso verbal en sede"*, ofreciendo un campo opcional para anotar el **nombre o cargo del informante en sede** (dato que será visible para la propia Sede en su portal/correo y para Coordinación/Técnicos, pero **nunca** en vistas públicas de Ciudadano QR conforme al **Artículo V.4**).
       * *"Detección propia del técnico"* (hallazgo directo durante la visita).
    3. **Descripción del síntoma observado o comunicado**, con una longitud mínima de **10 caracteres** significativos (advirtiendo en la interfaz que esta descripción inicial del síntoma es visible en el expediente de la Sede, por lo que las apreciaciones confidenciales de taller deben reservarse para las notas internas).
    4. **Fotografía de evidencia opcional**, sujeta estrictamente al **Artículo V.5 de la Constitución**: tamaño máximo de **5 MB**, restringida exclusivamente a los cuatro formatos gráficos seguros autorizados (`.jpg`, `.jpeg`, `.png`, `.webp`) y con **verificación obligatoria de su cabecera binaria real en el servidor** antes de su aceptación.
* **RF-04.2 [EARS - Dirigido por eventos / Nacimiento en Estado "En Curso", Etiqueta "Nueva" y Cálculo de SLA]:**  
  CUANDO el técnico confirme el Alta Oportunista In Situ, el sistema DEBE de forma atómica:
    1. Crear el nuevo expediente de incidencia identificado con la modalidad **"Oportunista In Situ · Nueva"** (conservando su submotivo: *Aviso verbal en sede* o *Detección propia del técnico*).
    2. Calcular automáticamente el nivel de urgencia y el plazo objetivo de atención (SLA) aplicando las reglas generales del sistema según la categoría del fallo y el tipo de máquina (escalando automáticamente a **Crítica** y activando el reloj sanitario de 4 horas si afecta a refrigeración o corte eléctrico en máquina de comida perecedera o combo conforme al **Artículo II**).
    3. Asignar la incidencia directamente al técnico actuante y establecer su estado inicial en **En Curso**, fijando la marca temporal tanto de creación como de asignación e inicio real de la intervención en ese mismo instante.
    4. Registrar en el historial inmutable del expediente (**Artículo III.2**) la apertura y puesta en marcha simultánea como *"Oportunista In Situ · Nueva"*.
* **RF-04.3 [EARS - Excepcional / Prohibición de Captura de Datos Bancarios por Reclamación de Monedas en Pasillo - Art. V.4]:**  
  SI un consumidor en el pasillo comunica al técnico que la máquina ha retenido su dinero, el técnico DEBE poder registrar la avería técnica de monedero/billetero mediante Alta Oportunista, pero el formulario del técnico NO DEBE solicitar ni almacenar jamás datos personales o financieros del consumidor (como código bancario IBAN o teléfono particular); el sistema DEBE mostrar un recordatorio en pantalla indicando al técnico que invite al usuario a escanear el código QR exterior de la máquina para tramitar de forma privada su solicitud de reembolso (**Artículo V.4**).

---

### RF-05: Flujo B — Asunción Oportunista In Situ (Máquina con Expediente Abierto o en Garantía)
*Cuando la máquina afectada ya cuente con un expediente activo o en garantía, el sistema evitará duplicidades (Art. V.2) permitiendo al técnico asumir o reabrir dicho expediente bajo la etiqueta "Oportunista In Situ · Asumida".*

* **RF-05.1 [EARS - Condicional / Prevención Estricta de Duplicados - Art. V.2]:**  
  SI la máquina seleccionada en la sede ya tiene un expediente activo (**Registrada**, **Asignada**, **En Curso**, **Pendiente de Repuesto**, **Pendiente de Información** o **Reabierta**) o se encuentra en estado **Resuelta** dentro de las **48 horas de garantía (Artículo V.6)**, el sistema NO DEBE crear un nuevo ticket duplicado, sino mostrar los detalles del expediente existente y ofrecer el flujo de **Asunción Oportunista In Situ** conforme a las reglas siguientes.
* **RF-05.2 [EARS - Condicional / Asunción Directa de Expedientes sin Técnico o del Propio Técnico]:**  
  CUANDO el expediente existente en la máquina se encuentre en estado **Registrada** o **Reabierta** (sin técnico asignado), o bien esté **Asignada**, **Pendiente de Repuesto** o **Pendiente de Información** a nombre del **propio técnico actuante**, el sistema DEBE permitirle asumirlo e iniciarlo en un solo paso, transicionando el expediente directamente a **En Curso** bajo su titularidad y marcándolo en el historial y en el expediente como **"Oportunista In Situ · Asumida"** (salvo que fuera una parada que ya tenía originalmente asignada y simplemente la inicia, en cuyo caso se registra como inicio ordinario o reanudación in situ; se etiqueta como *"Oportunista In Situ · Asumida"* siempre que el expediente no estuviera previamente asignado a este técnico).
* **RF-05.3 [EARS - Condicional / Asunción con Confirmación de Expedientes de Otro Compañero y Privacidad de Nota Interna - Art. V.3 y V.4]:**  
  CUANDO el expediente existente en la máquina esté en estado **Asignada**, **Pendiente de Repuesto** o **Pendiente de Información** a nombre de **otro técnico distinto**, el sistema DEBE informar claramente del nombre del compañero que lo tenía asignado y solicitar confirmación expresa (permitiendo añadir una nota opcional de motivo de traspaso in situ). Al confirmar, el sistema DEBE:
    1. Desasignar al técnico anterior (respetando la regla de **un único técnico responsable activo simultáneo del Artículo V.3**).
    2. Asignar el expediente al técnico presente en la sede, etiquetarlo como **"Oportunista In Situ · Asumida"** y pasarlo inmediatamente a estado **En Curso**.
    3. Guardar cualquier motivo o nota de traspaso entre técnicos con carácter **estrictamente interno (oculto a la Sede y al Ciudadano QR conforme al Artículo V.4)** y dejar constancia en el historial y auditoría del traspaso por presencia física in situ.
* **RF-05.4 [EARS - Condicional / Reapertura In Situ en Garantía (< 48h) y Regla de 3.ª Reincidencia "Avería Crónica" - Art. V.6]:**  
  CUANDO el expediente de la máquina se encuentre en estado **Resuelta** dentro de la ventana de **48 horas de garantía (Artículo V.6)** y el técnico detecte in situ o reciba aviso verbal de que el fallo persiste, el sistema DEBE exigir una descripción del motivo de reincidencia (mínimo 10 caracteres) y evaluar el contador de reaperturas de dicho expediente:
    1. **1.ª o 2.ª reapertura del expediente:** El sistema DEBE reabrir el mismo expediente (incrementando su contador de reaperturas), etiquetarlo como **"Oportunista In Situ · Asumida"**, asignarlo al técnico presente y pasarlo directamente a **En Curso**.
    2. **3.ª reincidencia consecutiva (Límite de "Avería Crónica"):** En coherencia con las reglas de garantía y del Centro de Notificaciones, el sistema DEBE **bloquear la reapertura del expediente antiguo**, marcar dicho expediente como **"Avería Crónica"** (encolando la Alerta de Avería Crónica a Coordinación y al canal de emergencias) y, **en ese mismo acto atómico**, crear automáticamente un **nuevo expediente limpio in situ** (*"Oportunista In Situ · Nueva"*, vinculado como sucesor del ticket crónico) asignado al técnico presente y en estado **En Curso**, para que el técnico pueda intervenir la máquina de inmediato sin romper la regla de bloqueo de la 3.ª reapertura.
* **RF-05.5 [EARS - Excepcional / Bloqueo Inviolable si Otro Técnico ya Tiene la Máquina "En Curso" - Art. V.3]:**  
  SI el expediente existente en la máquina ya se encuentra en estado **En Curso** a nombre de **otro técnico distinto**, el sistema DEBE **bloquear de forma absoluta** cualquier intento de asunción oportunista (**Artículo V.3**), informando en pantalla de que otro compañero ya está interviniendo activamente esa máquina.

---

### RF-06: Bloqueos de Acceso, Cuarentena Sanitaria, Cierre, Notificaciones y Métricas
*La intervención oportunista se integrará sin contradicciones con los protocolos sanitarios (Módulo 11), el Centro de Notificaciones (Módulo 14) y las alertas de Telegram (Módulo 15).*

* **RF-06.1 [EARS - Condicional / Actuación sobre Máquinas con Alerta de Acceso (72h) o Cuarentena Sanitaria (Módulo 11)]:**  
  CUANDO el técnico registre o asuma una avería oportunista sobre una máquina que tenía activa una **alerta de bloqueo por falta de acceso (72 horas)** o una **cuarentena preventiva sanitaria (4 horas sin frío, Módulo 11)**, el sistema DEBE permitir la intervención aplicando al momento de su resolución las siguientes reglas:
    1. **Bloqueo por falta de acceso (72h):** Al resolver satisfactoriamente la intervención in situ, el sistema DEBE levantar automáticamente la alerta de bloqueo por falta de acceso de la máquina, dado que el técnico ha acreditado presencia efectiva ante el equipo.
    2. **Cuarentena preventiva sanitaria (Art. II / Módulo 11):** La simple resolución de una avería comercial o mecánica NO DEBE levantar por sí sola una cuarentena sanitaria; para que la máquina salga del estado de Cuarentena Sanitaria, el cierre DEBE cumplir obligatoriamente el protocolo de verificación de temperatura e higienización / retirada de lote perecedero establecido en el Módulo 11 (**Artículo II**).
* **RF-06.2 [EARS - Ubicuo / Sometimiento a las Reglas Constitucionales de Resolución - Art. V.1]:**  
  Toda incidencia abierta o asumida de forma oportunista DEBE cumplir exactamente las mismas exigencias de cierre que cualquier incidencia ordinaria (**Artículo V.1**): diagnóstico técnico obligatorio (mínimo 20 caracteres), acción correctiva realizada (mínimo 20 caracteres) y declaración de repuestos si aplica.
* **RF-06.3 [EARS - Dirigido por eventos / Notificaciones Alineadas con Módulos 14 y 15, Obsolescencia y Privacidad Art. V.4]:**  
  CUANDO se confirme una operación oportunista in situ, el sistema DEBE gestionar la Bandeja de Salida de Notificaciones cumpliendo estrictamente:
    1. **A la Sede cliente (Correo Electrónico):** Se encolará correo a la Sede únicamente cuando se produzca un **Alta Oportunista In Situ** (Confirmación de apertura indicando que ya está siendo atendida in situ) o una **Reapertura In Situ** (aviso de reapertura en garantía en atención in situ). **NO se enviará ningún correo redundante** a la Sede cuando el técnico simplemente asuma y pase a *En Curso* un expediente que ya estaba previamente abierto. En todo correo o vista de Sede, el técnico figurará exclusivamente bajo su alias anonimizado *"Servicio Técnico Oficial · Operador #[Código]"* (**Artículo V.4**).
    2. **Al Ciudadano QR original (Correo Electrónico, si facilitó correo al abrir el ticket):** Si el técnico asume o reabre in situ un expediente cuyo reportador original fue un Ciudadano QR con correo electrónico, no se le enviarán avisos intermedios de traspaso, pero cuando el técnico **resuelva** la intervención (incluida la resolución tras reapertura in situ), el ciudadano recibirá su correo de **Confirmación de Servicio Restablecido** con enlace público neutro conforme al Módulo 14.
    3. **Al Técnico Saliente reemplazado (Exclusivamente Correo Electrónico, sin Telegram - Módulo 15 RF-TG-06):** Si la incidencia asumida estaba asignada o pausada a nombre de otro compañero (RF-05.3), el sistema DEBE: (a) **cancelar automáticamente por obsolescencia** cualquier notificación previa de *"Nueva asignación"* (tanto de Correo como de Telegram) que aún permaneciera en estado *Pendiente* o *Pendiente de reintento* para ese técnico reemplazado, y (b) encolar un aviso de **Desasignación (`TECHNICIAN_UNASSIGNED`) exclusivamente por Correo Electrónico** (sin botón de enlace y sin canal Telegram, respetando taxativamente el requisito `RF-TG-06` del Módulo 15) informándole de que un compañero presente en la sede ha asumido el expediente in situ.
    4. **Al Coordinador del Servicio (y Canal Corporativo de Emergencias si procede):** Se encolará alerta inmediata si el Alta o Reapertura Oportunista dispara una **Alerta Inmediata de Frío (Artículo II)**, si se alcanza la 3.ª reincidencia (**Alerta de Avería Crónica**), o si el técnico confirma atender una avería oportunista posponiendo una parada **Crítica / Sanitaria** pendiente en otra sede (RF-03.2).
* **RF-06.4 [EARS - Ubicuo / Trazabilidad Diferenciada ("Nueva" vs. "Asumida") e Indicador de Desplazamientos Evitados]:**  
  El sistema DEBE distinguir y mostrar en el detalle del expediente, en el historial y en los paneles de Coordinación las dos etiquetas específicas:
    * **"Oportunista In Situ · Nueva"** (con su submotivo: *Aviso verbal en sede* o *Detección propia del técnico*).
    * **"Oportunista In Situ · Asumida"** (cuando el técnico absorbe o reabre in situ un expediente que no tenía asignado).  
  Ambas modalidades DEBEN contabilizarse conjuntamente (con desglose visible entre *Nuevas* y *Asumidas*) dentro del indicador operativo de **Intervenciones Oportunistas In Situ (Desplazamientos Adicionales Evitados)**.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 · Atomicidad y Prevención de Condiciones de Carrera:**  
  Tanto el Alta Oportunista como la Asunción Oportunista DEBEN ejecutarse de forma atómica. Si dos usuarios (por ejemplo, un Responsable de Sede desde su ordenador y el Técnico desde su móvil en el pasillo, o dos técnicos distintos) intentan operar en el mismo instante sobre la misma máquina, el sistema DEBE impedir cualquier duplicidad de expedientes activos (**Artículo V.2**) o doble titularidad (**Artículo V.3**), devolviendo un mensaje comprensible al segundo solicitante.
* **RNF-02 · Agilidad en Dispositivos Móviles en Ruta (Máximo 3 Interacciones):**  
  El flujo completo de selección de máquina hermana en la sede y apertura o asunción oportunista DEBE poder completarse en un máximo de **3 interacciones** tanto desde la tarjeta de una parada activa como desde la tarjeta temporal de *"Sede recién visitada (30 min)"* (RF-01.2), con controles táctiles amplios y legibles en condiciones de trabajo de campo.
* **RNF-03 · Desacoplamiento de Comunicaciones Externas:**  
  El encolado de avisos a la Sede, a Coordinación o al técnico saliente NUNCA DEBE realizar envíos de red síncronos durante la confirmación del técnico en ruta; la respuesta en pantalla DEBE completarse de forma inmediata incluso si los servicios externos de mensajería o correo presentan lentitud.
* **RNF-04 · Inmutabilidad y Auditoría Completa (Artículo III):**  
  Toda apertura oportunista, asunción, reasignación in situ, reapertura en garantía, bloqueo por 3.ª reincidencia crónica y confirmación de advertencia de cadena de frío DEBE quedar registrada de forma indeleble con marca temporal exacta, usuario actor, estado anterior, estado nuevo y motivo, prohibiéndose cualquier borrado físico de registros.

---

## 6. Casos Límite

| Caso Límite | Comportamiento Esperado del Sistema |
| :--- | :--- |
| **1. El técnico acaba de cerrar o pausar la única avería que tenía en la sede hace 5 minutos y, al salir por recepción, le avisan de otra máquina estropeada.** | Durante los **30 minutos posteriores** al cierre o pausa (RF-01.1.2), la ruta del técnico muestra la **tarjeta temporal de "Sede recién visitada (30 min)"** (RF-01.2), desde la cual puede abrir el listado de máquinas de la sede y registrar o asumir la avería en $\le 3$ toques. |
| **2. El técnico abre el formulario en el minuto 29 de su ventana de gracia y pulsa "Confirmar" en el minuto 31.** | Gracias al **margen de tolerancia de +5 minutos** para formularios ya abiertos (RF-01.3, hasta el minuto 35), el envío se procesa con éxito sin perder lo escrito. |
| **3. El técnico pausó ayer una máquina a "Pendiente de Información" en la Sede Norte y hoy está en la Sede Sur.** | La Sede Norte **no** aparece habilitada hoy (RF-01.1): el estado *Pendiente de Información* solo dio 30 minutos de ventana de gracia ayer cuando estuvo en la puerta del edificio, impidiendo abrir averías oportunistas a distancia días después. |
| **4. El técnico intenta abrir un Alta Oportunista porque creía que la máquina estaba limpia, pero hace 10 segundos la Sede acaba de registrar el ticket desde su ordenador.** | El sistema detecta que la máquina ya tiene un ticket recién **Registrado** (Art. V.2), impide crear un duplicado y ofrece al técnico **asumir** ese ticket recién creado pasándolo a **En Curso** bajo la etiqueta *"Oportunista In Situ · Asumida"* (RF-05.1 y RF-05.2). |
| **5. Dos técnicos coinciden en una sede grande y el Técnico B intenta asumir una máquina que el Técnico A ya tiene "En Curso".** | El sistema bloquea la asunción de forma tajante (RF-05.5 y Art. V.3), informando al Técnico B de que el Técnico A ya está interviniendo activamente esa máquina. |
| **6. La máquina estaba recién "Asignada" al Técnico B (cuyo correo/Telegram de asignación aún estaba "Pendiente" en cola), y el Técnico A la asume in situ.** | El sistema pide confirmación al Técnico A (RF-05.3), transfiere la titularidad a su nombre en estado **En Curso**, **cancela por obsolescencia** los avisos de asignación pendientes del Técnico B y encola un aviso de desasignación **solo por correo electrónico** (sin Telegram, RF-06.3.3 y Módulo 15) para el Técnico B. |
| **7. El técnico tiene una parada "En Curso" en la Sede Norte (olvidó cerrarla al irse), llega a la Sede Sur e intenta asumir oportunistamente una máquina.** | El sistema bloquea la acción en la Sede Sur (RF-02.2) y le indica expresamente qué máquina mantiene **En Curso** en la Sede Norte, obligándole a cerrarla o pausarla primero. |
| **8. El técnico tiene en su ruta una parada "Crítica" o de riesgo sanitario de frío esperando en otro edificio, pero decide confirmar una avería oportunista en su sede actual.** | El sistema muestra la **advertencia sanitaria destacada (Art. II)** (RF-03.1); al confirmar el técnico bajo su responsabilidad, se procesa la intervención in situ y **se encola de inmediato una alerta prioritaria a Coordinación (y Webhook si es frío)** informando de que el técnico ha postergado la salida hacia la emergencia crítica (RF-03.2). |
| **9. La máquina estaba en estado "Resuelta" en garantía (< 48h), ya llevaba 2 reaperturas previas y el técnico intenta reabrirla in situ por 3.ª vez.** | El sistema aplica la regla de **Avería Crónica** (RF-05.4.2): bloquea la 3.ª reapertura del expediente antiguo, lo marca como *Avería Crónica* (alertando a Coordinación) y, en el mismo acto, crea un nuevo expediente limpio *"Oportunista In Situ · Nueva"* asignado al técnico y **En Curso** para que pueda repararla en el acto. |
| **10. La máquina intervenida oportunistamente estaba en Cuarentena Sanitaria (4h sin frío, Módulo 11).** | Al resolver la avería in situ, la cuarentena sanitaria **no** se levanta automáticamente salvo que el técnico complete la verificación de temperatura e higienización/retirada de lote exigida por el Módulo 11 (RF-06.1.2 y Art. II). |
| **11. Un usuario del edificio le reclama en mano al técnico 1,50 € que se acaba de tragar el monedero.** | El técnico abre la avería oportunista de monedero para reparar el atasco físico, pero el sistema le recuerda que **no debe recoger datos bancarios ni personales del usuario** (RF-04.3 y Art. V.4), indicándole al ciudadano que escanee el código QR de la máquina para su reembolso privado. |

---

## 7. Fuera de Alcance

Quedan explícitamente excluidos de esta especificación (para evitar desviaciones del alcance del MVP conforme a las líneas rojas del proyecto):
1. **Apertura oportunista en sedes ajenas a la ruta del técnico:** Un técnico no puede buscar libremente cualquier sede de la ciudad donde no tenga una parada activa (Asignada o En Curso) ni haya finalizado/pausado una intervención en los últimos 30 minutos (+5 min de tolerancia en formulario abierto).
2. **Detección automática por telemetría o proximidad (Bluetooth / NFC / GPS / IoT MDB-DEX):** El descubrimiento de las máquinas de la sede se basa en la sede de la parada activa o recién visitada de la ruta del técnico, sin hardware ni balizas adicionales.
3. **Gestión de cobros o devoluciones de efectivo en mano por parte del técnico:** Toda solicitud de reembolso económico a consumidores sigue exclusivamente el flujo privado de código QR del Módulo 09.
4. **Traspaso de intervenciones que otro técnico ya tenga "En Curso":** Si otro técnico ya inició la intervención en esa máquina y no la ha pausado ni cerrado, sólo Coordinación podría intervenir ante una contingencia excepcional.
5. **Creación de órdenes de Mantenimiento Preventivo oportunistas:** El técnico puede **originar** una avería correctiva oportunista mientras realiza un preventivo en la sede, pero no crear nuevas plantillas ni órdenes de mantenimiento preventivo por su cuenta.
6. **Envío de notificaciones de desasignación por Telegram:** Conforme al Módulo 15 (`RF-TG-06`), la desasignación al técnico saliente se notifica exclusivamente por correo electrónico.

---

## 8. Criterios de Finalización (Definition of Done)

1. Un técnico con una parada activa (**Asignada** o **En Curso**, correctiva o preventiva) en una sede —o dentro de los **30 minutos posteriores** a haber resuelto, completado o pausado una intervención en ella, con tarjeta temporal visible en su ruta y +5 min de tolerancia al enviar— puede consultar el listado de las demás máquinas operativas de esa sede en $\le 3$ interacciones.
2. Si selecciona una máquina sin expediente activo ni en garantía, puede completar un **Alta Oportunista In Situ** indicando categoría, motivo (*aviso verbal* con informante opcional o *detección propia*), descripción ($\ge 10$ caracteres) y foto opcional ($\le 5\text{ MB}$, formatos `.jpg`, `.jpeg`, `.png`, `.webp` con validación de cabecera real en servidor, Art. V.5), quedando la incidencia creada con etiqueta **"Oportunista In Situ · Nueva"**, asignada a su nombre y en estado **En Curso** con su SLA calculado automáticamente.
3. Si selecciona una máquina con expediente abierto (**Registrada**, **Reabierta**, **Asignada**, **Pendiente de Repuesto** o **Pendiente de Información**) o en garantía de 48h (**Resuelta**), el sistema impide crear un duplicado (Art. V.2) y le permite **asumirlo (o reabrirlo y asumirlo) in situ** bajo la etiqueta **"Oportunista In Situ · Asumida"**, pasándolo directamente a **En Curso** a su nombre (y si alcanza la 3.ª reincidencia en garantía, marca el ticket anterior como *Avería Crónica*, alerta a Coordinación y abre en el acto el nuevo expediente limpio in situ).
4. Si el expediente asumido pertenecía a otro técnico en estado **Asignada** o pausado, se pide confirmación expresa (guardando cualquier nota de traspaso como nota estrictamente interna oculta a la Sede, Art. V.4), se desasigna al técnico anterior (Art. V.3), se cancelan por obsolescencia sus avisos de asignación pendientes en cola y se le encola aviso de desasignación **exclusivamente por Correo Electrónico** (sin Telegram, cumpliendo el Módulo 15).
5. Si coexisten varias intervenciones **En Curso** del técnico en la misma sede, se muestran agrupadas en su Parada #1 ordenadas por urgencia/SLA (primero correctivas más críticas, luego preventivas). Si otro técnico ya tiene la máquina **En Curso**, o si el técnico actual dejó olvidada una intervención **En Curso** en otra sede distinta, el sistema bloquea la operación con un mensaje explicativo claro.
6. Si el técnico tiene pendiente en su ruta otra parada **Crítica** o con vigilancia sanitaria de cadena de frío activa en otra sede (Art. II), el sistema muestra una advertencia previa obligando a confirmar antes de proceder y, si el técnico confirma quedarse en la sede actual, **encola de inmediato una alerta prioritaria a Coordinación** para proteger la cadena de frío.
7. Al resolver in situ una máquina con bloqueo de acceso (72h) se levanta dicho bloqueo, pero si la máquina está en **Cuarentena Sanitaria (Módulo 11)** se exige completar la verificación sanitaria del Módulo 11 para levantar la cuarentena.
8. El historial de auditoría, el detalle del expediente y los indicadores de Coordinación distinguen entre **"Oportunista In Situ · Nueva"** y **"Oportunista In Situ · Asumida"** (sumando ambas en el KPI de desplazamientos evitados), y se certifica al 100% el blindaje del Artículo V.4 (técnico anonimizado como *"Servicio Técnico Oficial · Operador #[Código]"*, notas de traspaso internas, informante de sede oculto en QR público y cero captura de datos bancarios en la pantalla del técnico).

---

## 9. Dudas Abiertas

* **Ninguna duda abierta (`0` marcadores `[NECESITA ACLARACIÓN]`).** Todas las decisiones de negocio, reglas constitucionales y hallazgos de la auditoría QA han sido verificados y cerrados con el Product Owner.

---

## 10. Registro de Decisiones de Auditoría de Calidad (QA)

* **Resolución QA 1 (Blindaje Constitucional Art. II · Alerta a Coordinación por Postergación de Frío y Cuarentena Sanitaria - Hallazgos #4, #12 y #15):** La advertencia en ruta salta ante cualquier parada pendiente en otra sede que sea `Crítica` o tenga riesgo sanitario/térmico en máquina perecedera/combo; si el técnico confirma atender la avería oportunista, se encola de inmediato una alerta prioritaria a Coordinación (y Webhook si es frío) para que pueda reasignar la emergencia si lo estima necesario (RF-03.1 y RF-03.2). En máquinas en Cuarentena Sanitaria, resolver una avería oportunista exige cumplir la verificación sanitaria del Módulo 11 para levantar la cuarentena (RF-06.1.2).
* **Resolución QA 2 (Alineación con Módulos 14 y 15 · Correo vs. Telegram, Obsolescencia y Avisos a Sede/QR - Hallazgos #2, #6, #11 y #14):** Se respeta taxativamente `RF-TG-06` del Módulo 15: el aviso de desasignación al técnico saliente se envía **exclusivamente por Correo Electrónico** (sin canal Telegram) y cancela por obsolescencia cualquier aviso de asignación previo que siguiera pendiente en cola. A la Sede solo se le envía correo en *Alta Oportunista* y *Reapertura In Situ* (sin correo redundante al pasar a *En Curso* un ticket ya abierto), y al Ciudadano QR original se le notifica la resolución final conforme al Módulo 14 (RF-06.3).
* **Resolución QA 3 (Ventana de Gracia de 30 min, Estado "Pendiente de Información" y Tarjeta Temporal en Ruta - Hallazgos #8, #9, #10 y #13):** Solo las paradas *Asignadas* o *En Curso* (correctivas o preventivas) habilitan la sede de forma continua; pasar a *Pendiente de Información*, *Pendiente de Repuesto* o *Resuelta/Completada* inicia únicamente la ventana de gracia de 30 minutos (RF-01.1). Durante esos 30 minutos se muestra en la ruta una tarjeta temporal de *"Sede recién visitada (30 min)"* para cumplir las $\le 3$ interacciones (RF-01.2), con +5 minutos de margen de tolerancia si el formulario ya estaba abierto en pantalla (RF-01.3).
* **Resolución QA 4 (Distinción "Nueva" vs. "Asumida", Orden en Parada #1 y 3.ª Reincidencia "Avería Crónica" - Hallazgos #1, #5 y #7):** Se distinguen las etiquetas *"Oportunista In Situ · Nueva"* y *"Oportunista In Situ · Asumida"*, sumando ambas en el KPI de desplazamientos evitados (RF-06.4). Varias máquinas *En Curso* en la misma sede se ordenan en la Parada #1 por urgencia/SLA (primero correctivas urgentes, luego preventivos, RF-02.1). Si al reabrir in situ se alcanza la **3.ª reincidencia consecutiva**, se bloquea la reapertura del ticket viejo marcándolo como *Avería Crónica* (con alerta a Coordinación) y se crea automáticamente en el mismo acto un nuevo expediente limpio *"Oportunista In Situ · Nueva"* en estado *En Curso* (RF-05.4.2).
* **Resolución QA 5 (Blindaje Constitucional Art. V.4 Privacidad y Art. V.5 Carga Segura de Archivos - Hallazgos #3, #16 y #17):** El técnico figura siempre ante Sede y Ciudadano QR bajo el alias *"Servicio Técnico Oficial · Operador #[Código]"*; el motivo de traspaso entre técnicos se guarda como nota estrictamente interna oculta a la Sede; el nombre opcional del informante verbal en sede es visible para Sede y Coordinación pero nunca en la vista pública QR (RF-04.1.2, RF-05.3.3 y RF-06.3.1). La foto opcional exige $\le 5\text{ MB}$, lista cerrada `.jpg`, `.jpeg`, `.png`, `.webp` y verificación obligatoria de cabecera binaria real en el servidor conforme al Artículo V.5 (RF-04.1.4).
