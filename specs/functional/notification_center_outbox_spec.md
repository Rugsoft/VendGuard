# ESPECIFICACIÓN FUNCIONAL: CENTRO DE NOTIFICACIONES MULTICANAL Y BANDEJA DE SALIDA GARANTIZADA

**Documento:** `specs/functional/notification_center_outbox_spec.md`  
**Referencia de Módulo:** `specs/14-notification-center-outbox/spec.md`  
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Estado:** Especificación Formal Consolidada y Auditada tras QA (Fase 2 SDD - Gate)  
**Fecha:** Octubre 2026  
**Alineación Constitucional:** Artículos I al VII de la Constitución de VendGuard (Especialmente Art. II, Art. III, Art. V.1, Art. V.4 y Art. V.6)

---

## 1. Contexto y Objetivo

### 1.1. Contexto Operativo
En la gestión diaria de un parque de máquinas expendedoras, el valor de registrar una avería, alertar de una pérdida de frío o pausar una intervención por falta de acceso depende de que la persona responsable se entere a tiempo para actuar. Hasta ahora, el sistema requería que los actores mantuvieran abierta su pantalla o recargaran sus bandejas de trabajo para descubrir novedades críticas:
* Cuando se produce una rotura de cadena de frío en una máquina bajo vigilancia sanitaria (Artículo II), cada minuto cuenta. Si el coordinador o el técnico de guardia no están mirando el panel en ese instante, se pierde un tiempo crítico antes de las 4 horas límite de seguridad alimentaria.
* Cuando el coordinador asigna o reasigna un aviso urgente, el técnico en ruta no recibe un aviso proactivo en su teléfono si está conduciendo o reparando otra máquina.
* Cuando un técnico se encuentra con el edificio cerrado o una máquina ilocalizable y declara la avería como *"Pendiente de Información"*, el responsable de la sede no recibe un aviso inmediato en su correo electrónico para facilitarle el acceso, prolongando innecesariamente la parada del servicio.
* Por otro lado, enviar correos electrónicos o mensajes a canales corporativos en el mismo instante en que un usuario pulsa un botón entraña dos riesgos graves: ralentizar o bloquear la pantalla del usuario si el servidor de correo tarda en responder, y perder para siempre avisos críticos si el proveedor externo sufre una caída temporal.

### 1.2. Objetivo
Establecer un **Centro de Notificaciones Multicanal con Bandeja de Salida Garantizada** que permita:
1. **Desacoplar la acción del usuario del envío efectivo del mensaje**, registrando cada notificación de forma atómica e instantánea junto con la operación de negocio que la origina, para que ninguna pantalla sufra esperas ni ningún fallo externo impida guardar una avería o cambio de estado.
2. **Garantizar la entrega con prioridad sanitaria absoluta (Artículo II)**, procesando siempre en primer lugar las emergencias de cadena de frío frente a los avisos ordinarios, aplicando una política de reintentos automáticos progresivos ante fallos temporales de red y ofreciendo a Coordinación visibilidad total de los fallos definitivos con opción de reenvío o cancelación manual.
3. **Blindar la vigilancia proactiva de la seguridad alimentaria (Artículo II)**, notificando al instante las averías térmicas o reclasificaciones críticas en máquinas bajo vigilancia sanitaria, la inminencia de vencimiento a las 2 y 3 horas naturales, la entrada en cuarentena sanitaria a las 4 horas y cualquier escalado por contención del barrido sanitario, tanto a los Coordinadores y Técnicos de guardia como al canal corporativo de emergencias.
4. **Mantener informados en todo momento a los actores del ciclo de vida del expediente** (Coordinador, Técnico asignado, Responsable de Sede y Ciudadano reportador por código QR), garantizando de forma inviolable la **segregación de privacidad (Artículo V.4)** y la **auditabilidad institucional (Artículo III.3)**.

---

## 2. Usuarios y Perfiles Implicados

| Perfil / Actor | Canal de Recepción | Avisos que Recibe | Permisos de Gestión |
| :--- | :--- | :--- | :--- |
| **📊 Coordinador de Operaciones** | **Correo Electrónico** individual y **Canal Corporativo de Emergencias** (Webhook). | Alertas inmediatas de frío y reclasificaciones críticas (Art. II), alertas de inminencia de SLA (> 2h sin asignar y > 3h sin iniciar), activación de cuarentena sanitaria (4h), escalado por contención del barrido sanitario, reaperturas en garantía, alertas de *"Avería Crónica"* (3.ª reincidencia) y alertas de notificaciones en *"Fallo definitivo"*. | Configura el canal corporativo de emergencias (con prueba de conexión directa), designa qué técnicos están *"De guardia"*, supervisa la bandeja de salida y ejecuta reintentos o cancelaciones manuales. |
| **📱 Técnico de Ruta / Campo** | **Correo Electrónico** individual (y **Canal de Emergencias** si participa en el grupo corporativo). | Si está *"De guardia"*: alertas inmediatas de frío y cuarentena sanitaria de tickets sin asignar. En general: aviso inmediato de asignación y desasignación de ticket, alerta de 3h sin inicio en perecederos asignados a su nombre, respuesta de la Sede a una pausa por falta de acceso y activación de cuarentena en sus máquinas asignadas. | Sin permisos de configuración del centro de notificaciones. Su identidad personal permanece protegida frente a terceros (Art. V.4). |
| **🏢 Responsable de Sede** | **Correo Electrónico** oficial de la ficha de la Sede (y adicionalmente el correo del informador si indicó uno distinto en el reporte). | Confirmación de apertura de incidencia con enlace a su Portal de Sede, solicitud urgente de acceso/información cuando el técnico pausa a *"Pendiente de Información"* (incluyendo causa tipificada y motivo de bloqueo), aviso de entrada en cuarentena preventiva de una máquina de su centro, confirmación de resolución con recordatorio de las 48h de garantía y aviso de cancelación (advirtiendo si la máquina quedó bloqueada tras 72h). | Puede indicar un correo adicional al reportar una avería sin que la sede pierda copia oficial (Art. III.3). Jamás recibe notas internas de taller, diagnósticos técnicos de cierre, costes de repuestos ni nombres o teléfonos personales de los técnicos (Art. V.4). |
| **👥 Ciudadano / Usuario QR** | **Correo Electrónico** opcional facilitado voluntariamente al escanear el código QR. | Si facilitó su correo al abrir el ticket: confirmación de registro con enlace a la vista pública acotada de seguimiento QR, confirmación de resolución y aviso neutro si el ticket se cancela. Si aportó observaciones sobre un ticket ya abierto: acuse puntual de su aportación sin suplantar al reportador original. | Sin acceso al portal de sede, ni a motivos de pausa con el edificio, ni a textos libres de otros usuarios (Art. V.4). Si no indica correo al reportar, su experiencia en pantalla no varía y se omite el envío postal. |

---

## 3. Historias de Usuario

### HU-01: Alerta Inmediata de Emergencia Térmica con Prioridad de Cola (Art. II)
> **Como** Coordinador de Operaciones o Técnico de Guardia,  
> **quiero** recibir una alerta prioritaria inmediata en mi correo y en el canal corporativo de emergencias en cuanto se registre, reabra o reclasifique como crítica una avería en una máquina con vigilancia sanitaria, adelantando en la cola de envíos a cualquier mensaje ordinario,  
> **para** movilizar asistencia urgente antes de que se rompa la cadena de frío, con la certeza de que si el proveedor falla temporalmente el sistema reintentará el envío automáticamente.

### HU-02: Designación Ágil de Técnicos de Guardia
> **Como** Coordinador de Operaciones,  
> **quiero** activar o desactivar la marca de *"Técnico de guardia"* sobre los técnicos en activo desde el directorio de personal,  
> **para** asegurar que las alertas críticas de frío en incidencias recién registradas lleguen de inmediato al profesional responsable del turno de urgencias, y ser advertido expresamente si en ese momento no hay ningún técnico de guardia designado.

### HU-03: Vigilancia Proactiva Antes del Límite Sanitario (2h, 3h, 4h y Barrido)
> **Como** Coordinador de Operaciones,  
> **quiero** recibir un aviso urgente único si una avería en máquina bajo vigilancia sanitaria cumple 2 horas naturales sin técnico asignado o 3 horas naturales sin haber iniciado la intervención presencial, así como si salta la cuarentena a las 4 horas o si el barrido automático sufre contención persistente,  
> **para** intervenir a tiempo y proteger de forma inviolable la seguridad alimentaria.

### HU-04: Aviso Inmediato de Trabajo Asignado y Respuestas de Sede en Ruta
> **Como** Técnico de Campo en ruta,  
> **quiero** recibir un correo en cuanto me asignen o retiren una avería, así como cuando una sede responda a mi solicitud de acceso en una parada pausada (sin recibir correos duplicados si la sede escribe varios mensajes seguidos en el mismo minuto),  
> **para** organizar mis desplazamientos al instante sin consultar manualmente cada pocos minutos si ha cambiado mi ruta.

### HU-05: Comunicación Transparente y Segregada con la Sede y el Ciudadano
> **Como** Responsable de Sede o Ciudadano informador,  
> **quiero** recibir en mi correo los avisos de apertura, bloqueo de acceso (solo para la Sede), resolución con garantía de 48h o cancelación, con un enlace acorde a mi nivel de acceso y sin exponer jamás datos confidenciales,  
> **para** hacer seguimiento del servicio y colaborar con rapidez manteniendo la trazabilidad institucional del edificio.

### HU-06: Supervisión de la Bandeja de Salida y Recuperación ante Fallos
> **Como** Coordinador de Operaciones,  
> **quiero** ver una alerta cuando alguna notificación pase a *"Fallo definitivo"*, consultar los 6 estados de la bandeja, corregir un correo o dirección de Webhook mal escrita y relanzar o cancelar manualmente los envíos,  
> **para** mantener bajo control las comunicaciones del servicio sin depender de soporte informático.

---

## 4. Requisitos Funcionales y Criterios de Aceptación (Notación EARS)

### RF-01: Ciclo de Vida en Bandeja de Salida, Prioridad Sanitaria y Rescate de Envíos
*El sistema garantizará que la generación de notificaciones nunca bloquee al usuario, despachando primero las emergencias sanitarias y recuperándose de caídas abruptas.*

* **RF-01.1 [EARS - Ubicuo / Estados Canónicos]:**  
  El sistema DEBE gestionar toda notificación saliente dentro de una bandeja de salida persistente utilizando exclusivamente los siguientes **6 estados canónicos**:
    1. **"Pendiente"**: Registrada y lista para su primer intento de entrega.
    2. **"En proceso"**: Bloqueada temporalmente por el despachador en segundo plano durante el intento de envío activo.
    3. **"Pendiente de reintento"**: En espera de que venza su intervalo de espera progresiva tras haber sufrido un fallo transitorio de entrega.
    4. **"Enviada"**: Entregada y confirmada con éxito por el proveedor externo.
    5. **"Fallo definitivo"**: Envío detenido tras agotar los 5 intentos máximos o tras recibir un rechazo permanente de formato o destino inválido.
    6. **"Cancelada por obsolescencia"**: Envío anulado lógicamente antes de su entrega porque un cambio en el ticket, en el destinatario o una acción manual de Coordinación dejó sin validez el aviso.
* **RF-01.2 [EARS - Ubicuo / Desacoplamiento]:**  
  El sistema DEBE registrar las notificaciones en estado *"Pendiente"* en el mismo acto atómico en que se confirma la operación de negocio que las origina, y NO DEBE ejecutar conexiones directas a servidores externos durante la petición del usuario, con la **única excepción explícita** de la acción interactiva *"Probar conexión"* del Canal Corporativo de Emergencias (RF-06.5).
* **RF-01.3 [EARS - Dirigido por eventos / Orden de Prioridad Sanitaria - Art. II]:**  
  CUANDO el proceso de envío en segundo plano seleccione un lote de notificaciones listas para despachar (*"Pendiente"* o *"Pendiente de reintento"* con tiempo de espera cumplido), el sistema DEBE ordenar la cola por **prioridad estricta**, procesando **siempre en primer lugar las alertas de Prioridad Crítica / Sanitaria (Artículo II)** (*Alerta Inmediata de Frío*, *Inminencia de SLA 2h/3h*, *Cuarentena Sanitaria 4h* y *Escalado de Barrido Sanitario*) por delante de cualquier notificación operativa ordinaria, y marcando el lote seleccionado como *"En proceso"*.
* **RF-01.4 [EARS - Excepcional / Rescate de Envíos Huérfanos en Tránsito]:**  
  SI una notificación permanece atrapada en estado *"En proceso"* durante **más de 5 minutos** continuados (debido a una interrupción abrupta o caída del despachador antes de recibir respuesta), el siguiente barrido del proceso en segundo plano DEBE rescatarla automáticamente, contabilizar el intento interrumpido y pasarla a *"Pendiente de reintento"* (o a *"Fallo definitivo"* si alcanzara el 5.º intento), registrando la incidencia de tiempo agotado en tránsito.
* **RF-01.5 [EARS - Dirigido por eventos / Confirmación de Entrega]:**  
  CUANDO el proveedor externo confirme la recepción del mensaje, el sistema DEBE transicionar la notificación a *"Enviada"*, registrando la marca temporal exacta de entrega.

---

### RF-02: Datos de Contacto por Correo, Trazabilidad de Sede y Guardia Técnica (Art. III.3)
*El sistema garantizará que toda Sede disponga de buzón institucional, preservará la trazabilidad de cada centro y permitirá designar técnicos de guardia.*

* **RF-02.1 [EARS - Ubicuo / Correo Oficial de Sede y Sedes Preexistentes]:**  
  El sistema DEBE exigir en la ficha maestra de cada Sede una dirección de correo electrónico de contacto válida y editable por Coordinación. Para las sedes preexistentes dadas de alta con anterioridad a este módulo, el sistema DEBE dotarlas de una dirección de correo institucional válida por defecto (editable por Coordinación); SI por cualquier anomalía una sede careciera de correo válido en el momento de un evento, el sistema NO DEBE bloquear jamás el registro ni el avance de las averías, señalizando una advertencia en el panel de Coordinación.
* **RF-02.2 [EARS - Opcional / Correo en Formulario de Reporte]:**  
  DONDE un usuario reporte una avería o aporte evidencias (tanto desde el Portal de Sede como mediante escaneo público de código QR), el formulario DEBE ofrecer un campo opcional de correo electrónico del informador; SI el usuario introduce un valor, el sistema DEBE validar que posea un formato de correo electrónico válido antes de aceptar el envío.
* **RF-02.3 [EARS - Condicional / Doble Destinatario en Sede por Auditabilidad - Art. III.3]:**  
  CUANDO se registre una avería desde el Portal de Sede:
    1. Si el informador deja en blanco el correo opcional del formulario (o escribe el mismo correo oficial de la Sede), el sistema DEBE enviar todas las notificaciones del expediente destinadas a la sede únicamente a la dirección de correo oficial configurada en la ficha de la Sede.
    2. Si el informador introduce en el formulario una dirección de correo distinta a la oficial de la Sede, el sistema DEBE encolar las notificaciones de apertura, resolución y cancelación **tanto para el correo oficial de la Sede como para el correo indicado por el informador**, garantizando que el centro cliente nunca pierda la trazabilidad institucional de lo ocurrido en sus instalaciones (Art. III.3).
* **RF-02.4 [EARS - Condicional / Ciudadano QR Anónimo]:**  
  CUANDO un consumidor anónimo registre una avería mediante escaneo de código QR y deje en blanco el campo opcional de correo electrónico, el sistema DEBE registrar la incidencia con normalidad y omitir la generación de notificaciones dirigidas al ciudadano, encolando igualmente el aviso correspondiente para el correo oficial de la Sede propietaria de la máquina y las alertas internas que procedan.
* **RF-02.5 [EARS - Ubicuo / Gestión de Técnico de Guardia]:**  
  El sistema DEBE permitir al Coordinador de Operaciones activar o desactivar el indicador de *"Técnico de guardia"* sobre cualquier usuario con rol de Técnico de Campo que se encuentre en estado activo.
* **RF-02.6 [EARS - Excepcional / Baja de Técnico de Guardia]:**  
  SI un Técnico de Campo que tiene activa la condición de *"Técnico de guardia"* es dado de baja lógica o desactivado en el directorio de personal, el sistema DEBE retirar automáticamente su marca de guardia en ese mismo acto para impedir que las alertas críticas se dirijan a personal inactivo.

---

### RF-03: Alertas de Emergencia Sanitaria, Frío, Cuarentena y Barrido (Artículo II)
*El sistema otorgará máxima prioridad a todos los eventos que comprometan la seguridad alimentaria en máquinas bajo vigilancia sanitaria (alimentos frescos perecederos y máquinas mixtas con módulo refrigerado).*

* **RF-03.1 [EARS - Dirigido por eventos / Alerta Inmediata de Frío y Reclasificación Crítica - Art. II]:**  
  CUANDO en una máquina sujeta a vigilancia sanitaria: (a) se registre o reabra una incidencia con categoría de fallo térmico (*"Temperatura / Pérdida de frío"*), corte eléctrico (*"Máquina apagada / Sin corriente"*) o urgencia *"Crítica"*, o bien (b) el Coordinador de Operaciones **reclasifique en triaje a urgencia *"Crítica"*** una incidencia abierta que aún no hubiera disparado alerta de frío en su ciclo actual, el sistema DEBE encolar de inmediato una **Alerta Inmediata de Frío** con prioridad máxima dirigida a:
    1. Todos los Coordinadores de Operaciones activos (vía correo electrónico).
    2. Todos los Técnicos de Campo activos que tengan marcada la condición de *"Técnico de guardia"* (vía correo electrónico), salvo que el ticket ya tenga un técnico específico asignado, en cuyo caso se notificará a dicho técnico asignado.
    3. El canal corporativo de emergencias (vía Webhook, si se encuentra activo).
* **RF-03.2 [EARS - Excepcional / Ausencia de Técnico de Guardia]:**  
  SI en el momento de dispararse una Alerta Inmediata de Frío sobre un ticket sin técnico asignado no existe ningún técnico en activo con la condición de *"Técnico de guardia"*, el sistema DEBE emitir igualmente la alerta a todos los Coordinadores activos y al canal corporativo de emergencias, incluyendo en el asunto y en el encabezado del mensaje la advertencia destacada: *"⚠️ ALERTA SIN TÉCNICO DE GUARDIA DESIGNADO: Requiere asignación manual inmediata"*.
* **RF-03.3 [EARS - Dirigido por eventos / Cuarentena Sanitaria a las 4h - Art. II]:**  
  CUANDO el reloj sanitario biológico continuo alcance las **4 horas naturales sin refrigeración confirmada** y active automáticamente el Modo de Cuarentena Sanitaria sobre la máquina, el sistema DEBE encolar con prioridad máxima:
    1. Una **Alerta Crítica de Cuarentena Sanitaria** para todos los Coordinadores activos, para el Técnico asignado al expediente (o para los Técnicos de guardia si el ticket sigue sin asignar) y para el canal corporativo de emergencias.
    2. Un **Aviso Preventivo de Bloqueo Sanitario** dirigido al correo oficial de la Sede cliente, informando de que la máquina ha quedado bloqueada por precaución sanitaria hasta su higienización y verificación técnica.
* **RF-03.4 [EARS - Dirigido por eventos / Escalado por Contención del Barrido Sanitario - Art. II]:**  
  CUANDO el barrido periódico del reloj sanitario biológico marque un expediente en estado **Escalado por Contención Persistente** (3 pasadas consecutivas aplazado por contención de bloqueo) o declare un **Escalado de Parque** (más de 5 aplazamientos en una sola pasada), el sistema DEBE encolar con prioridad máxima una única **Alerta de Escalado del Barrido Sanitario** por episodio de escalado, dirigida a todos los Coordinadores activos y al canal corporativo de emergencias, advirtiendo de que la latencia de detección sanitaria se está duplicando.

---

### RF-04: Alertas de Inminencia de Vencimiento de SLA Sanitario (2h y 3h) y Control Anti-Spam
*El sistema vigilará de forma continua los tickets en máquinas bajo vigilancia sanitaria para alertar antes de alcanzar el umbral crítico de 4 horas.*

* **RF-04.1 [EARS - Estado / Umbral de 2 horas sin asignar]:**  
  MIENTRAS una incidencia abierta sobre una máquina sujeta a vigilancia sanitaria permanezca sin técnico asignado (en estado *"Registrada"* o *"Reabierta"*) y acumule **más de 2 horas en tiempo natural continuo (24/7)** desde su apertura o última reapertura, el sistema DEBE encolar con prioridad máxima una **Alerta de Inminencia de SLA (2h sin asignación)** dirigida a todos los Coordinadores activos y al canal corporativo de emergencias.
* **RF-04.2 [EARS - Estado / Umbral de 3 horas sin haber iniciado intervención]:**  
  MIENTRAS una incidencia abierta sobre una máquina sujeta a vigilancia sanitaria acumule **más de 3 horas en tiempo natural continuo (24/7)** desde su apertura o última reapertura **sin haber entrado ni una sola vez en estado *"En curso"*** durante el ciclo de atención vigente, el sistema DEBE encolar con prioridad máxima una **Alerta Crítica de Inminencia de SLA (3h sin inicio de intervención)** dirigida a todos los Coordinadores activos, al Técnico asignado (si lo tuviera) y al canal corporativo de emergencias. *(Nota: si el técnico ya inició la intervención pasando a "En curso" antes de las 3h y luego la pausó, no se emite la alerta de "sin inicio" de 3h, pero el reloj biológico de 4h continúa corriendo inexorablemente y activará la alerta de Cuarentena Sanitaria de RF-03.3 si a las 4h naturales no se ha resuelto).*
* **RF-04.3 [EARS - Ubicuo / Coherencia con el Reloj Biológico - Art. II]:**  
  El cómputo de los umbrales de 2 horas y 3 horas en máquinas bajo vigilancia sanitaria DEBE medirse en **tiempo natural continuo e ininterrumpido (24/7)**, sin detenerse ni descontar los intervalos en que el expediente se encuentre pausado en *"Pendiente de Información"*, dado que el deterioro biológico del alimento perecedero continúa avanzando aunque el inmueble esté cerrado.
* **RF-04.4 [EARS - Ubicuo / Deduplicación Anti-Spam]:**  
  El sistema DEBE garantizar que para un mismo ciclo de atención de una incidencia se emita **como máximo una única notificación por el umbral de 2 horas y una única notificación por el umbral de 3 horas**, impidiendo que ejecuciones sucesivas del proceso de vigilancia generen avisos repetidos sobre el mismo hito ya notificado.
* **RF-04.5 [EARS - Dirigido por eventos / Reinicio por Reapertura en Garantía]:**  
  CUANDO una incidencia resuelta sea reabierta válidamente dentro de la ventana de garantía de 48 horas, el sistema DEBE iniciar un nuevo ciclo de vigilancia tomando como referencia la fecha y hora exacta de la reapertura, permitiendo emitir las alertas de 2h y 3h correspondientes a este nuevo ciclo si volvieran a superarse dichos umbrales sin asignación o sin inicio de intervención en la nueva visita.

---

### RF-05: Notificaciones del Ciclo de Vida del Expediente y Blindaje de Privacidad (Art. V.4)
*El sistema notificará todos los hitos clave de tramitación respetando de forma estricta la segregación de datos entre Sede y Ciudadano QR.*

* **RF-05.1 [EARS - Dirigido por eventos / Confirmación de Apertura Diferenciada]:**  
  CUANDO se registre una nueva incidencia, el sistema DEBE encolar un correo de **Confirmación de Registro de Avería**:
    1. **Para la Sede** (conforme a RF-02.3): incluyendo código de ticket, máquina, planta/ala, categoría, descripción reportada, fecha/hora y un **enlace directo al Portal de Sede**.
    2. **Para el Ciudadano QR** (si facilitó su correo al reportar por QR): incluyendo únicamente datos públicos neutros (código de ticket, máquina, categoría declarada y fecha/hora de apertura) y un **enlace directo a la vista pública acotada de seguimiento QR** de esa máquina.
* **RF-05.2 [EARS - Dirigido por eventos / Asignación y Reasignación Técnica]:**  
  CUANDO el Coordinador asigne una incidencia a un Técnico de Campo, el sistema DEBE encolar un correo de **Aviso de Nueva Asignación** para dicho técnico con el código de ticket, nivel de urgencia, sede, dirección, planta/ala, máquina y descripción del problema. SI se trata de una reasignación que retira el ticket a un técnico anterior, el sistema DEBE encolar además un **Aviso de Desasignación** para el técnico saliente informándole de que el expediente ha pasado a otro compañero.
* **RF-05.3 [EARS - Dirigido por eventos / Pausa por Falta de Acceso o Información]:**  
  CUANDO el Técnico o el Coordinador transicionen una incidencia al estado *"Pendiente de Información"*, el sistema DEBE encolar un correo prioritario de **Solicitud de Acceso o Información** dirigido **exclusivamente a la Sede cliente** (jamás al Ciudadano QR), detallando la causa tipificada del bloqueo, el motivo explicativo registrado ($\ge 20$ caracteres, idéntico al visible en el banner del Portal de Sede) y un enlace directo a su Portal de Sede para responder al servicio técnico.
* **RF-05.4 [EARS - Dirigido por eventos / Respuesta de la Sede en Pausa y Agrupación de Ráfagas]:**  
  CUANDO una incidencia se encuentre en estado *"Pendiente de Información"* y el Responsable de Sede publique un comentario público de respuesta, el sistema DEBE notificar al Técnico de Campo asignado (o a Coordinación si el ticket no tuviera técnico asignado) informándole del texto aportado para que pueda reanudar cuando confirme el acceso. SI en el instante de publicar el comentario ya existe en la bandeja de salida una notificación de *"Aviso de Respuesta de Sede"* en estado **"Pendiente"** (aún no tomada por el despachador) para ese mismo ticket y destinatario, el sistema DEBE actualizar el contenido de dicha notificación pendiente con el último mensaje en lugar de encolar un correo adicional duplicado.
* **RF-05.5 [EARS - Dirigido por eventos / Resolución y Garantía de 48h]:**  
  CUANDO el Técnico marque una incidencia como *"Resuelta"*, el sistema DEBE encolar un correo de **Confirmación de Servicio Restablecido** dirigido a la Sede (con enlace al Portal de Sede) y al Ciudadano QR original si facilitó correo (con enlace a la vista pública QR), informando de que el equipo vuelve a estar operativo e indicando expresamente que dispone de un plazo de **48 horas de garantía** para reabrir el aviso si el fallo persiste.
* **RF-05.6 [EARS - Ubicuo / Blindaje Estricto de Privacidad - Art. V.4]:**  
  El sistema DEBE filtrar en origen el contenido de todas las notificaciones dirigidas a la Sede o al Ciudadano QR, garantizando que:
    1. **Ninguna notificación a Sede ni a Ciudadano QR** incluya jamás notas internas de taller (`is_internal`), diagnósticos técnicos ni acciones de resolución, costes económicos de repuestos ni el nombre, apellidos, correo o teléfono personal de los técnicos (quienes solo figurarán bajo el alias *"Servicio Técnico Oficial · Operador #[Código]"*).
    2. **Ninguna notificación al Ciudadano QR** incluya jamás avisos de pausa (*"Pendiente de Información"*), causas de desencuentro con el inmueble ni textos libres redactados por otros usuarios.
* **RF-05.7 [EARS - Dirigido por eventos / Reapertura en Garantía y Avería Crónica]:**  
  CUANDO una Sede o Ciudadano QR reabra válidamente una incidencia dentro de las 48 horas de garantía, el sistema DEBE encolar un **Aviso de Reapertura en Garantía** dirigido a todos los Coordinadores activos informando de que el ticket ha regresado sin técnico asignado a la bandeja prioritaria de triaje (además de la Alerta Inmediata de Frío de RF-03.1 si aplica vigilancia sanitaria). SI una máquina intenta reabrirse por **3.ª vez consecutiva** y el sistema bloquea la reapertura marcando el expediente como **"Avería Crónica"**, el sistema DEBE encolar de inmediato una **Alerta de Avería Crónica** dirigida a todos los Coordinadores activos y al canal corporativo de emergencias para su auditoría exhaustiva.
* **RF-05.8 [EARS - Dirigido por eventos / Cancelación de Incidencia y Bloqueo tras 72h]:**  
  CUANDO el Coordinador cancele una incidencia (estado *"Cancelada"*), el sistema DEBE encolar un correo informativo de **Notificación de Cancelación de Expediente**:
    1. **Para la Sede:** indicando el cierre administrativo del ticket y, si se trata de una cancelación por inactividad tras superar las **72 horas hábiles en espera de acceso**, advirtiendo de forma destacada que la máquina ha quedado en estado *"Fuera de servicio / Bloqueada por falta de acceso"* hasta que la sede confirme la apertura de las instalaciones.
    2. **Para el Ciudadano QR** (si facilitó correo al abrir el ticket): mostrando un mensaje público neutro de cierre del expediente sin exponer motivos internos del inmueble.
* **RF-05.9 [EARS - Dirigido por eventos / Segundo Informador Concurrente por QR]:**  
  CUANDO un ciudadano escanee el código QR de una máquina que ya tiene una incidencia activa (o en envío simultáneo concurrente) y aporte observaciones adicionales facilitando su correo electrónico, el sistema DEBE encolar únicamente un correo puntual de **Acuse de Observación Registrada** para dicho ciudadano con el código de ticket y el enlace a la vista pública QR, **sin sobrescribir ni suplantar** el correo del reportador original asociado a la incidencia.

---

### RF-06: Política de Reintentos, Fallos Definitivos y Panel de Supervisión en Coordinación
*El sistema distinguirá entre caídas temporales de red y rechazos permanentes, ofreciendo a Coordinación control total para corregir destinos y relanzar envíos.*

* **RF-06.1 [EARS - Excepcional / Reintentos con Espera Progresiva ante Fallos Transitorios]:**  
  SI el intento de entrega de una notificación falla por una **causa transitoria** (error de conexión de red, tiempo de espera agotado o indisponibilidad temporal del servidor remoto), el sistema DEBE incrementar el contador de intentos realizados, registrar el detalle del error devuelto y programar automáticamente el siguiente reintento en estado **"Pendiente de reintento"** aplicando una **espera progresiva** (1 minuto tras el 1.º fallo, 5 minutos tras el 2.º fallo, 15 minutos tras el 3.º fallo y 60 minutos tras el 4.º fallo).
* **RF-06.2 [EARS - Excepcional / Paso a Fallo Definitivo por Agotamiento o Rechazo Permanente]:**  
  SI una notificación: (a) alcanza el **máximo de 5 intentos fallidos**, o bien (b) recibe un **rechazo permanente e irreversible** (dirección de destino con sintaxis inválida, buzón inexistente rechazado definitivamente por el servidor o destino de Webhook malformado), el sistema DEBE detener de inmediato los reintentos automáticos, transicionar la notificación al estado **"Fallo definitivo"**, conservar íntegro el mensaje de error y activar un indicador visual de alerta en el panel del Coordinador de Operaciones.
* **RF-06.3 [EARS - Ubicuo / Panel de Supervisión con los 6 Estados Canónicos]:**  
  El sistema DEBE ofrecer al Coordinador de Operaciones un panel de supervisión del Centro de Notificaciones que muestre:
    1. Resumen de contadores operativos: notificaciones en cola (*"Pendiente"*, *"En proceso"* y *"Pendiente de reintento"*), enviadas con éxito en las últimas 24 horas (*"Enviada"*) y notificaciones en **"Fallo definitivo"**.
    2. Listado histórico filtrable por cualquiera de los **6 estados canónicos** (*Pendiente*, *En proceso*, *Pendiente de reintento*, *Enviada*, *Fallo definitivo*, *Cancelada por obsolescencia*), por canal (*Correo*, *Webhook de Emergencias*), por prioridad (*Crítica Sanitaria*, *Ordinaria*) y por código de ticket, mostrando destinatario, tipo de aviso, intentos realizados, fechas y detalle del último error.
* **RF-06.4 [EARS - Dirigido por eventos / Reintento Manual con Destino Corregido y Cancelación Manual]:**  
  CUANDO el Coordinador pulse la acción *"Reintentar envío"* sobre una notificación en estado *"Fallo definitivo"* (o *"Reintentar todas las fallidas"*), el sistema DEBE **actualizar el destinatario de la notificación tomando el correo oficial de la Sede o la dirección del Webhook vigente en ese momento** (permitiendo así que si el fallo se debió a una dirección mal escrita que el Coordinador acaba de corregir, el reintento utilice el dato corregido), restablecer su estado a *"Pendiente"*, reiniciar su contador de intentos a cero y registrar la acción en auditoría. Asimismo, MIENTRAS una notificación se encuentre en *"Pendiente"*, *"Pendiente de reintento"* o *"Fallo definitivo"*, el Coordinador DEBE poder pulsar *"Cancelar envío"* para pasarla manualmente a *"Cancelada por obsolescencia"*.
* **RF-06.5 [EARS - Ubicuo / Configuración Segura y Prueba Directa del Canal de Emergencias]:**  
  El sistema DEBE permitir al Coordinador configurar desde su panel el Canal Corporativo de Emergencias (dirección de destino del Webhook, nombre identificativo del canal —ej. Slack, Discord o Telegram— y selector de activación/desactivación), cumpliendo las siguientes reglas:
    1. **Validación de Seguridad del Destino:** El sistema DEBE exigir que la dirección del Webhook utilice obligatoriamente protocolo seguro cifrado (`https://`) hacia un dominio público externo, bloqueando y rechazando direcciones sin cifrar (`http://`), direcciones de bucle local (`localhost`, `127.0.0.1`) o rangos de red interna privada.
    2. **Verificación Interactiva Directa (*"Probar conexión"*):** CUANDO el Coordinador pulse el botón *"Probar conexión"*, el sistema DEBE ejecutar un envío de verificación directo con un límite máximo de espera de **5 segundos**, mostrar de inmediato en pantalla si la entrega fue exitosa o el motivo exacto del fallo, y dejar constancia de la prueba en el historial.

---

### RF-07: Prevención de Avisos Obsoletos e Inviolabilidad Histórica (Artículo III)
*El sistema evitará enviar notificaciones desfasadas respecto a la realidad del ticket y preservará toda la trazabilidad para auditoría.*

* **RF-07.1 [EARS - Excepcional / Cancelación Automática por Obsolescencia]:**  
  SI en el momento en que el proceso en segundo plano va a despachar una notificación en estado *"Pendiente"* o *"Pendiente de reintento"* se comprueba que el estado actual de la incidencia o del destinatario dejó sin validez el propósito del mensaje —incluyendo expresamente: (a) alertas de *"2h sin asignar"* cuando el ticket ya tiene técnico asignado; (b) alertas de *"3h sin iniciar"* cuando el ticket ya entró en *"En curso"*; (c) avisos de *"Nueva asignación"* a un técnico que ya ha sido reemplazado por otro; (d) avisos de pausa cuando la pausa ya fue reanudada; (e) cualquier aviso operativo previo (salvo el propio aviso de cancelación de RF-05.8) cuando la incidencia ha pasado a estado *"Cancelada"* o *"Cerrada"*; o (f) avisos dirigidos a un usuario interno o canal Webhook que ha sido desactivado—, el sistema NO DEBE enviar el mensaje al exterior; en su lugar, DEBE transicionar la notificación al estado **"Cancelada por obsolescencia"** documentando el motivo exacto.
* **RF-07.2 [EARS - Ubicuo / Prohibición de Borrado Físico - Art. III.1]:**  
  Queda terminantemente prohibido eliminar o destruir físicamente ningún registro de la bandeja de notificaciones ni de la configuración de canales. Todas las notificaciones en cualquiera de sus 6 estados DEBEN conservarse de forma permanente e inmutable como evidencia histórica auditable.
* **RF-07.3 [EARS - Ubicuo / Trazabilidad en Auditoría - Art. III.3]:**  
  El sistema DEBE registrar en la bitácora inmutable de auditoría todo cambio en la designación de técnicos de guardia, toda modificación o prueba de la configuración del canal corporativo de emergencias, el paso de una notificación a *"Fallo definitivo"* y los reintentos o cancelaciones manuales ejecutados por Coordinación.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Latencia Cero en Operaciones de Usuario):**  
  El encolado de notificaciones en la bandeja de salida no deberá incrementar en más de **30 milisegundos** el tiempo de respuesta percibido por el usuario al reportar una incidencia, asignar un técnico, pausar, comentar, resolver o cancelar una avería, con total independencia de la disponibilidad o lentitud de los proveedores externos.
* **RNF-02 (Cadencia y Tiempo de Despacho en Segundo Plano):**  
  El proceso de despacho en segundo plano deberá ejecutarse con una cadencia regular que garantice que las notificaciones recién generadas (y con prioridad absoluta las alertas sanitarias del Artículo II) comiencen su intento de entrega en un margen objetivo inferior a **1 minuto** desde su registro.
* **RNF-03 (Tiempos de Espera Acotados ante Proveedores Caídos):**  
  Cada intento de conexión hacia un servidor de correo o canal externo deberá contar con un límite máximo de espera (*timeout*) estricto (máximo **5 segundos** por envío) que impida que un proveedor lento bloquee el procesamiento del resto de mensajes de la cola.
* **RNF-04 (Segregación Absoluta de Datos Confidenciales en Origen - Art. V.4):**  
  Los datos almacenados y formateados para notificaciones destinadas a Sedes o Ciudadanos QR deberán construirse aplicando en origen las reglas de ocultación de identidad técnica, costes, diagnósticos de cierre y notas internas, diferenciando además la proyección de Sede frente a la proyección pública neutra del Ciudadano QR.
* **RNF-05 (Consistencia Visual con el Sistema de Diseño):**  
  El panel de supervisión de notificaciones, los selectores de técnico de guardia y el modal de configuración del canal de emergencias respetarán estrictamente los tokens visuales del sistema (fondo `#f9fafb`, azul interactivo `#2560ff`, bordes conservadores de 4px y 8px, e insignias semánticas para los 6 estados con contraste accesible WCAG AA).
* **RNF-06 (Idempotencia ante Ejecuciones Concurrentes y Caídas):**  
  El despacho de la bandeja de salida, el rescate de envíos huérfanos en estado *"En proceso"* (> 5 minutos) y el evaluador de umbrales de SLA (2h/3h) deberán ser 100% idempotentes: si dos procesos de fondo se solapan, ningún destinatario recibirá el mismo mensaje por duplicado.

---

## 6. Casos Límite y Comportamiento ante Errores

1. **Alerta crítica de frío sin ningún técnico marcado "De guardia":**  
   Si se registra, reabre o reclasifica a *"Crítica"* una avería en una máquina bajo vigilancia sanitaria sin técnico asignado y en ese instante ningún técnico activo tiene la marca de guardia, el sistema envía igualmente la alerta a todos los Coordinadores activos y al canal corporativo de emergencias resaltando en el asunto y cuerpo: *"⚠️ ALERTA SIN TÉCNICO DE GUARDIA DESIGNADO: Requiere asignación manual inmediata"*.
2. **Reporte ciudadano por código QR sin correo vs. segundo ciudadano concurrente con correo:**  
   * Si el consumidor que abre el ticket por QR deja vacío el campo opcional de correo, se guarda la incidencia sin error y solo se encolan los avisos para la Sede y el equipo interno.
   * Si un segundo ciudadano escanea el QR cuando el ticket ya está abierto (o en envío simultáneo) y aporta una observación indicando su correo, recibe únicamente su **Acuse de Observación Registrada** con enlace a la vista pública QR, sin modificar ni sustituir el correo del ciudadano que abrió originalmente el ticket (RF-05.9).
3. **Reasignación rápida de técnico antes de que salga el correo de la primera asignación:**  
   Si el Coordinador asigna la avería al Técnico A y segundos después (antes del despacho en segundo plano) la reasigna al Técnico B, el sistema detecta que el aviso pendiente de *"Nueva asignación"* para el Técnico A ha quedado desfasado respecto al responsable vigente, lo pasa a *"Cancelada por obsolescencia"* sin enviarlo y despacha únicamente el aviso al Técnico B.
4. **Ráfaga de comentarios públicos de la Sede mientras la avería está en "Pendiente de Información":**  
   Si el Responsable de Sede envía varios comentarios seguidos antes de que el despachador haya enviado el primer *"Aviso de Respuesta de Sede"* al técnico asignado, el sistema reutiliza y actualiza la notificación que sigue en estado *"Pendiente"* con el texto más reciente, evitando encolar múltiples correos idénticos en el mismo minuto (RF-05.4).
5. **Desactivación o corrección del Canal de Emergencias (Webhook) o del correo de Sede con envíos en cola:**  
   * Si el Webhook está desactivado antes del evento, no se encolan envíos para el Webhook. Si se desactiva mientras había envíos de Webhook en *"Pendiente"* o *"Pendiente de reintento"*, el despachador los marca como *"Cancelada por obsolescencia"* (RF-07.1).
   * Si un envío a Sede o Webhook cayó en *"Fallo definitivo"* porque la dirección estaba mal escrita, cuando el Coordinador corrige la dirección en la ficha de la Sede o en la configuración del Webhook y pulsa *"Reintentar envío"*, el sistema actualiza el destinatario con el nuevo valor vigente antes de reencolarlo (RF-06.4).
6. **Caída abrupta del despachador a mitad de envío (Notificación atrapada en *"En proceso"*):**  
   Si el proceso en segundo plano sufre un corte eléctrico o cierre inesperado tras marcar un lote como *"En proceso"*, transcurridos **5 minutos** el siguiente barrido rescata automáticamente esas notificaciones y las pasa a *"Pendiente de reintento"* (o *"Fallo definitivo"* en su 5.º intento), impidiendo que queden bloqueadas para siempre (RF-01.4).
7. **Intervención iniciada y posteriormente pausada antes de las 3 horas en máquina de frío:**  
   Si un técnico inicia la intervención (*"En curso"*) a la hora 1:30 y a la hora 2:00 la pausa a *"Pendiente de Información"* o *"Pendiente de Repuestos"*, a las 3 horas no se emite la alerta de *"3h sin inicio de intervención"* (pues ya se inició), pero a las **4 horas naturales continuas** salta inexorablemente la Cuarentena Sanitaria automática y se envían las alertas críticas de cuarentena a Coordinación, Técnico, Webhook y Sede (RF-03.3 y RF-04.2).

---

## 7. Fuera de Alcance

Para mantener la simplicidad operativa, el foco en el valor real y el respeto a los Artículos IV y VI de la Constitución:
1. **Buzón o campana de notificaciones dentro de la interfaz web para usuarios finales:** Las notificaciones operativas se entregan por canales salientes (Correo Electrónico y Canal Corporativo de Emergencias), reservándose la interfaz web al panel de supervisión y configuración de Coordinación.
2. **Mensajería por SMS telefónico o llamadas de voz automatizadas:** No se integran pasarelas de telefonía móvil de pago por mensaje (SMS/voz).
3. **Respuestas entrantes por correo electrónico (*Inbound Email Parsing*):** Los destinatarios no responden contestando al correo recibido; el correo incluye un enlace directo (al Portal de Sede o vista móvil/pública según el rol) para interactuar con todas las garantías de seguridad y auditoría.
4. **Configuración de Webhooks personales por cada técnico o sede:** El canal de Webhook es único/corporativo para el centro de control de operaciones y emergencias, administrado exclusivamente por Coordinación.
5. **Editor visual de diseño libre de plantillas de correo:** Las plantillas de notificación son estandarizadas, limpias, responsivas y blindadas por el sistema para garantizar que nunca se filtre información restringida por el Artículo V.4.

---

## 8. Criterios de Finalización

La especificación se considerará formalmente cumplida cuando:
1. Toda operación cubierta (apertura, alerta de frío inicial o por reclasificación crítica, asignación/reasignación, pausa por falta de acceso, respuesta de sede, resolución, reapertura en garantía, avería crónica, cancelación, inminencia de SLA 2h/3h, cuarentena sanitaria 4h y escalado de barrido sanitario) registre sus notificaciones de forma atómica en estado *"Pendiente"* sin bloquear ni demorar la respuesta al usuario.
2. El despachador ordene la cola por **prioridad estricta**, despachando siempre primero las emergencias sanitarias del Artículo II, rescate automáticamente cualquier notificación atrapada en *"En proceso"* durante más de 5 minutos y aplique hasta 5 intentos con espera progresiva ($1\text{ m}, 5\text{ m}, 15\text{ m}, 60\text{ m}$) para errores transitorios, pasando directamente a *"Fallo definitivo"* los rechazos permanentes de formato o destino.
3. Las fichas de Sede cuenten con correo electrónico oficial obligatorio/editable (con valor institucional por defecto en sedes preexistentes) y, cuando el informador en el Portal de Sede indique un correo distinto al oficial, se envíe copia tanto al buzón oficial de la Sede como al del informador (Art. III.3).
4. El Coordinador pueda marcar y desmarcar qué técnicos activos están *"De guardia"* (retirándose automáticamente al desactivar un técnico), y las Alertas Inmediatas de Frío lleguen a todos los Coordinadores activos, a los Técnicos de guardia y al canal de emergencias (advirtiendo expresamente si no hubiera ningún técnico de guardia designado).
5. El proceso de vigilancia evalúe en tiempo natural continuo (24/7) las incidencias en máquinas bajo vigilancia sanitaria, emitiendo como máximo una alerta a las 2 horas sin asignación y una alerta a las 3 horas sin haber entrado en *"En curso"* por cada ciclo de atención (reiniciándose solo si hay reapertura en garantía).
6. El Coordinador disponga en su panel de la vista de supervisión filtrable por los **6 estados canónicos**, con alerta de fallos definitivos, acciones manuales de *"Cancelar envío"* y *"Reintentar envío"* (refrescando el destinatario con el correo de Sede o URL de Webhook vigente corregido), y configuración del Canal Corporativo de Emergencias (restringido a destinos `https://` públicos) con botón directo de *"Probar conexión"* (máx. 5 s).
7. Las notificaciones obsoletas se transicionen automáticamente a *"Cancelada por obsolescencia"* sin enviarse al exterior, y no exista ningún borrado físico en todo el ciclo de vida (Art. III.1).
8. Se certifique al 100% el blindaje del Artículo V.4: ninguna notificación a Sede ni a Ciudadano QR expone notas internas, diagnósticos de cierre, costes ni datos personales de técnicos, y ninguna notificación al Ciudadano QR expone estados o motivos de pausa con el edificio ni textos de otros usuarios.

---

## 9. Dudas Abiertas

* **Ninguna duda abierta.** Todas las definiciones funcionales, casos límite y alineaciones constitucionales han sido validadas y cerradas con el Product Owner.

---

## 10. Registro de Decisiones de Auditoría de Calidad (QA)

* **Resolución QA 1 (Prioridad Sanitaria en Cola - Art. II):** El despachador procesa la bandeja de salida por orden estricto de prioridad, anteponiendo siempre las emergencias del Artículo II (*Alerta de Frío*, *SLA 2h/3h*, *Cuarentena 4h* y *Escalado de Barrido*) sobre los avisos ordinarios (RF-01.3).
* **Resolución QA 2 (Doble Destinatario en Sede por Auditabilidad - Art. III.3):** Si el informador en el Portal de Sede indica un correo distinto al oficial de la Sede, se notifica a ambos buzones para que la Sede conserve siempre su registro institucional (RF-02.3). Las sedes preexistentes reciben un correo institucional por defecto editable (RF-02.1).
* **Resolución QA 3 (Segregación Estricta Sede vs. Ciudadano QR - Art. V.4):** La Sede recibe enlaces a su Portal de Sede y el motivo de pausa ($\ge 20$ caracteres) para desbloquear el acceso; el Ciudadano QR jamás recibe avisos de pausa ni textos de terceros, solo datos públicos neutros y enlace a la vista pública QR (RF-05.1, RF-05.3 y RF-05.6).
* **Resolución QA 4 (Reclasificación Crítica, Umbral de 3h y Escalado de Barrido - Art. II):** Se dispara también la Alerta Inmediata de Frío cuando Coordinación reclasifica a *"Crítica"* una avería en máquina bajo vigilancia sanitaria (RF-03.1); el umbral de 3h salta si en el ciclo vigente aún no se ha entrado en *"En curso"* (RF-04.2); y se alerta a Coordinación y Webhook cuando el barrido sanitario entra en estado escalado (RF-03.4).
* **Resolución QA 5 (Reaperturas, Averías Crónicas, Cancelaciones, Ráfagas y Segundo QR):** Se notifica a Coordinación toda reapertura en garantía y el bloqueo por 3.ª reincidencia (*"Avería Crónica"*, RF-05.7); al cancelar un ticket se cancelan por obsolescencia sus envíos previos pendientes y se notifica a la Sede advirtiendo si la máquina quedó bloqueada tras 72h hábiles (RF-05.8 y RF-07.1); en ráfagas de respuestas de Sede se actualiza el aviso que aún esté *"Pendiente"* sin duplicar filas (RF-05.4); y el segundo informador QR recibe acuse puntual sin suplantar al reportador original (RF-05.9).
* **Resolución QA 6 (6 Estados Canónicos, Rescate > 5 min y Fallos Permanentes):** Se unifican los 6 estados (*Pendiente*, *En proceso*, *Pendiente de reintento*, *Enviada*, *Fallo definitivo*, *Cancelada por obsolescencia*), se rescatan automáticamente envíos atrapados en *"En proceso"* tras 5 minutos (RF-01.4) y los rechazos permanentes de formato/destino pasan directamente a *"Fallo definitivo"* sin consumir reintentos inútiles (RF-06.2).
* **Resolución QA 7 (Destinatario Corregido en Reintentos y Seguridad de Webhook):** Al reintentar un envío fallido se toma el correo oficial de Sede o la URL de Webhook vigente corregida (RF-06.4); el Webhook exige destino `https://` público externo y *"Probar conexión"* opera como verificación directa acotada a 5 segundos (RF-06.5).
