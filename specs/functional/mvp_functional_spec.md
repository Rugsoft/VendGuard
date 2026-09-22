# ESPECIFICACIÓN FUNCIONAL DEL SISTEMA · MVP
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Documento:** `specs/functional/mvp_functional_spec.md`  
**Estado:** Aprobada tras Auditoría de Calidad (QA)  
**Metodología:** SDD (Specification-Driven Development) · Notación EARS  

---

## 1. Contexto y Objetivo

### 1.1 Contexto
Una empresa operadora de máquinas de vending distribuye cientos de puntos de autoservicio (bebidas frías, calientes, snacks y alimentos frescos perecederos) en múltiples sedes de clientes (oficinas, fábricas, centros educativos y hospitales). Actualmente, los avisos de avería se reciben por canales no estructurados (llamadas, correos generales, mensajes verbales), lo que provoca visitas técnicas solapadas, pérdida de avisos urgentes, nula visibilidad para los responsables del edificio y riesgo sanitario por pérdida de temperatura en comida fresca.

### 1.2 Objetivo
Definir con precisión el comportamiento funcional del Producto Mínimo Viable (MVP) para:
1. Centralizar la notificación de incidencias a través de un acceso ágil sin contraseñas para los responsables de cada sede (por código de sede alfanumérico).
2. Garantizar que nunca coexistan dos partes de avería abiertos simultáneamente sobre la misma máquina.
3. Clasificar automáticamente la urgencia del problema, blindando la prioridad crítica e inmediata para cualquier fallo térmico en alimentos perecederos.
4. Dotar al equipo de coordinación y a los técnicos de campo de un flujo de asignación e intervención con cierre obligatoriamente documentado, ventana de garantía de 48 horas y auditoría inmutable de estados.

---

## 2. Usuarios del Sistema

* **Responsable de Ubicación (Informador):** Personal del edificio o centro cliente (conserje, recepcionista, encargado de planta) que notifica averías en las máquinas instaladas en su sede y consulta su resolución. Accede mediante un **Código de Sede** único. Al reportar, se identifica con nombre y teléfono de contacto para el seguimiento.
* **Técnico de Campo / Ruta (Operador):** Personal técnico itinerante que recibe tareas en su dispositivo móvil, se desplaza a las máquinas, ejecuta la reparación y documenta la intervención. Accede mediante **credenciales individuales (usuario/contraseña)**.
* **Coordinador del Servicio (Administrador):** Responsable operativo que supervisa el parque completo, valida severidades, asigna avisos a las rutas técnicas y gestiona reincidencias o descartes. Accede mediante **credenciales con permisos de administración**.

---

## 3. Historias de Usuario

* **HU-01:** *Como* Responsable de Ubicación, *quiero* acceder rápidamente introduciendo el código alfanumérico de mi edificio *para* ver solo las máquinas de mi centro sin tener que recordar contraseñas.
* **HU-02:** *Como* Responsable de Ubicación, *quiero* registrar una avería seleccionando la máquina y el fallo observado *para* que el servicio técnico acuda a repararla lo antes posible.
* **HU-03:** *Como* Responsable de Ubicación, *quiero* que el sistema me impida abrir un ticket duplicado si la máquina ya está siendo atendida *para* no saturar al servicio técnico ni generar confusiones.
* **HU-04:** *Como* Responsable de Ubicación, *quiero* poder reabrir una incidencia durante las primeras 48 horas tras su arreglo *para* alertar si la máquina vuelve a fallar por la misma causa.
* **HU-05:** *Como* Coordinador, *quiero* un panel de triaje centralizado con filtros y alertas destacadas de fallo de frío *para* priorizar las averías que ponen en riesgo la seguridad alimentaria o la facturación.
* **HU-06:** *Como* Coordinador, *quiero* asignar cada incidencia a un técnico específico *para* fijar un responsable operativo único y coordinar las rutas.
* **HU-07:** *Como* Técnico de Campo, *quiero* consultar en mi móvil la lista de avisos asignados a mi nombre *para* organizar mi ruta del día según su urgencia.
* **HU-08:** *Como* Técnico de Campo, *quiero* registrar el inicio de mi trabajo e introducir obligatoriamente el diagnóstico y la solución aplicada *para* cerrar el parte y alimentar el historial de la máquina.

---

## 4. Requisitos Funcionales y Criterios de Aceptación (Notación EARS)

### RF-01: Acceso por Código de Sede
*El sistema permitirá a los responsables de ubicación identificarse mediante un identificador alfanumérico propio de su edificio.*

* **EARS 1.1 (Evento):** Cuando el usuario introduce un código de sede válido en formato alfanumérico (ej: `SEDE-BCN-01`), el sistema deberá autenticar la sesión y mostrar la vista del portal de sede correspondiente.
* **EARS 1.2 (Excepción):** Si el usuario introduce un código de sede inexistente o inactivo, entonces el sistema deberá denegar el acceso y mostrar el mensaje de error: *"Código de sede no reconocido. Contacte con el servicio técnico."*
* **EARS 1.3 (Estado):** Mientras el responsable de ubicación navegue autenticado por código de sede, el sistema deberá restringir su visibilidad exclusivamente a las máquinas e incidencias pertenecientes a dicha sede, manteniendo la sesión activa durante 24 horas continuadas de actividad.

### RF-02: Detección y Bloqueo Estricto de Incidencias Duplicadas
*El sistema impedirá la existencia de más de una incidencia abierta o no consolidada de forma concurrente en una misma máquina.*

* **EARS 2.1 (Excepción):** Si un usuario intenta crear una incidencia para una máquina que ya cuenta con un ticket en estado `REGISTRADA`, `ASIGNADA`, `EN_CURSO`, `PENDIENTE_REPUESTO` o `REABIERTA`, entonces el sistema deberá bloquear la creación del nuevo ticket, mostrar los datos del aviso activo y habilitar únicamente la opción de añadir comentarios o fotografías adicionales a la bitácora del ticket existente.
* **EARS 2.2 (Excepción/Garantía):** Si un usuario intenta crear una incidencia para una máquina cuyo ticket previo está en estado `RESUELTA` (dentro de las 48 horas de garantía), entonces el sistema deberá bloquear la creación de un nuevo ticket y mostrar el mensaje: *"Esta máquina fue reparada recientemente (Ticket #XXX). Si el fallo persiste, pulsa en 'Reabrir incidencia'"*.
* **EARS 2.3 (Evento):** Cuando el usuario confirme la adición de comentarios o fotografías a un ticket activo preexistente, el sistema deberá anexar la información como un nuevo registro en la bitácora (`incident_comments`) preservando íntegra la foto original del ticket sin sobreescribirla, y notificar la actualización en el panel de triaje.

### RF-03: Registro de Avería y Cálculo Automático de Urgencia
*El sistema registrará nuevos avisos determinando de forma automática el nivel de urgencia según las reglas del negocio.*

* **EARS 3.1 (Evento):** Cuando el responsable de ubicación seleccione una máquina disponible y envíe el formulario de avería con categoría, descripción, nombre del informador y teléfono de contacto, el sistema deberá crear la incidencia en estado `REGISTRADA` con fecha/hora exacta.
* **EARS 3.2 (Estado/Sanitario):** Si la categoría de avería seleccionada corresponde a *"Temperatura / Pérdida de frío"* en una máquina de alimentos perecederos (`PERISHABLE_FOOD`), el sistema deberá asignar de forma automática el nivel de urgencia `CRÍTICA`.
* **EARS 3.3 (Estado/Frío no perecedero):** Si la categoría seleccionada corresponde a *"Temperatura / Pérdida de frío"* en una máquina de bebidas frías o calientes (`COLD_DRINKS` o `HOT_DRINKS`), el sistema deberá asignar el nivel de urgencia `MEDIA`.
* **EARS 3.4 (Estado/Cobro):** Si la categoría seleccionada corresponde a *"Fallo en medios de pago"* (ambos sistemas, efectivo y tarjeta, inutilizados), el sistema deberá asignar el nivel de urgencia `ALTA`.
* **EARS 3.5 (Estado/Mecánico parcial):** Si la categoría corresponde a *"Atasco de producto en espiral específica"*, el sistema deberá asignar el nivel de urgencia `MEDIA`.
* **EARS 3.6 (Estado/Cosmético):** Si la categoría corresponde a *"Iluminación decorativa o desperfecto estético"*, el sistema deberá asignar el nivel de urgencia `BAJA`.
* **EARS 3.7 (Estado/Otro):** Si la categoría corresponde a *"Otro"* (`OTHER`), el sistema deberá asignar por defecto el nivel de urgencia `MEDIA`.
* **EARS 3.8 (Opcional):** Donde el informador declare que la máquina retuvo dinero, el sistema deberá registrar el importe indicado como metadato estrictamente informativo para el expediente.
* **EARS 3.9 (Opcional):** Donde el informador adjunte un archivo de imagen, el sistema deberá validar que su tamaño sea inferior o igual a 5 MB y de tipo seguro (`.jpg`, `.jpeg`, `.png`, `.webp`); si no cumple o si la subida sufre un corte de red, entonces el sistema deberá rechazar la carga, notificar el error pero **conservar íntegros los datos de texto introducidos en el formulario** para permitir su reintento inmediato.

### RF-04: Autenticación Interna y Control de Acceso (RBAC)
*El personal interno (coordinación y técnicos) accederá mediante credenciales seguras individuales.*

* **EARS 4.1 (Evento):** Cuando un usuario interno introduce credenciales válidas (correo y contraseña), el sistema deberá iniciar sesión y redirigir a la vista correspondiente a su rol.
* **EARS 4.2 (Excepción):** Si las credenciales son incorrectas, entonces el sistema deberá rechazar el acceso con un mensaje genérico de error y no revelar si el fallo reside en el usuario o en la clave.
* **EARS 4.3 (Estado/Técnico):** Mientras un usuario con rol *Técnico de Campo* esté conectado, el sistema deberá mostrarle únicamente las incidencias asignadas a su identificador de usuario y la información operativa relevante.
* **EARS 4.4 (Estado/Coordinador):** Mientras un usuario con rol *Coordinador* esté conectado, el sistema deberá otorgarle acceso a la supervisión global, reasignación, descarte y consulta de métricas.

### RF-05: Triaje, Reclasificación y Asignación de Técnico
*El coordinador evaluará la cola de trabajo y fijará la asignación a las rutas de campo.*

* **EARS 5.1 (Evento):** Cuando el coordinador asigne un técnico de campo a una incidencia en estado `REGISTRADA` o `REABIERTA`, el sistema deberá actualizar el estado a `ASIGNADA`, asociar el identificador del técnico y registrar la fecha de asignación.
* **EARS 5.2 (Ubicuo):** El sistema deberá garantizar que una incidencia solo tenga un único técnico asignado activo de forma simultánea.
* **EARS 5.3 (Opcional/Auditoría):** Donde el coordinador detecte discrepancias justificadas en la gravedad de la avería (incluyendo la degradación de una urgencia `CRÍTICA` si la máquina está vacía de producto perecedero), el sistema deberá permitirle modificar el nivel de urgencia **exigiendo obligatoriamente un motivo justificado que quedará registrado en el historial inmutable** de la incidencia.
* **EARS 5.4 (Excepción):** Si el coordinador intenta asignar una incidencia sin seleccionar un técnico válido, entonces el sistema deberá rechazar la operación y mantener la incidencia en su estado actual.

### RF-06: Descarte o Cancelación Lógica de Avisos
*El coordinador podrá anular avisos improcedentes sin destruir información histórica.*

* **EARS 6.1 (Evento):** Cuando el coordinador descarte una incidencia (falsa alarma, error de reporte o máquina retirada), el sistema deberá exigir obligatoriamente un motivo de descarte.
* **EARS 6.2 (Ubicuo):** El sistema deberá realizar la anulación mediante borrado lógico, cambiando el estado a `CANCELADA` y preservando el registro íntegro en base de datos.
* **EARS 6.3 (Excepción):** Si el coordinador intenta cancelar un aviso sin rellenar el motivo explicativo, entonces el sistema deberá bloquear la cancelación.

### RF-07: Gestión de la Intervención Técnica en Campo
*El técnico de campo gestionará el estado de la máquina durante su presencia física.*

* **EARS 7.1 (Evento):** Cuando el técnico de campo llega a la ubicación e interactúa con el control *"Iniciar intervención"*, el sistema deberá transicionar el estado de la incidencia a `EN_CURSO` y guardar la marca de tiempo de inicio.
* **EARS 7.2 (Evento):** Cuando el técnico deba suspender temporalmente los trabajos por no disponer del recambio necesario en su vehículo, el sistema deberá permitir cambiar el estado a `PENDIENTE_REPUESTO`, exigiendo una nota descriptiva de la pieza requerida.
* **EARS 7.3 (Evento):** Cuando el técnico reanude los trabajos tras recibir el repuesto, el sistema deberá permitir devolver la incidencia al estado `EN_CURSO`.

### RF-08: Resolución Obligatoriamente Justificada
*Ninguna avería podrá darse por solucionada sin documentar el trabajo realizado.*

* **EARS 8.1 (Evento):** Cuando el técnico finalice la reparación física y solicite marcar la incidencia como `RESUELTA`, el sistema deberá exigir obligatoriamente:
  * El diagnóstico real del problema detectado (mínimo 20 caracteres descriptivos).
  * La acción técnica correctiva implementada (mínimo 20 caracteres descriptivos).
* **EARS 8.2 (Excepción):** Si el técnico intenta marcar la incidencia como `RESUELTA` con cualquiera de los dos campos con una extensión inferior a **20 caracteres descriptivos en cada uno**, entonces el sistema deberá rechazar el cierre, mostrar un aviso de validación y mantener la incidencia en estado `EN_CURSO`.
* **EARS 8.3 (Evento):** Al confirmarse la resolución válida, el sistema deberá cambiar el estado a `RESUELTA`, registrar la fecha/hora de finalización y activar (o reiniciar a cero) la cuenta atrás de la ventana de garantía de 48 horas.

### RF-09: Reapertura de Incidencia en Ventana de Garantía
*El responsable de ubicación podrá reabrir un caso si la máquina persiste con fallos.*

* **EARS 9.1 (Estado/Evento):** Mientras una incidencia se encuentre en estado `RESUELTA` y no hayan transcurrido más de 48 horas desde su resolución, cuando el responsable de ubicación pulse *"Reabrir incidencia"*, el sistema deberá solicitar la descripción del fallo persistente, cambiar el estado a `REABIERTA`, **desasignar automáticamente al técnico previo** (`assigned_technician_id = NULL`) y trasladar la incidencia a la bandeja de triaje del coordinador como prioritaria.
* **EARS 9.2 (Excepción):** Si han transcurrido más de 48 horas desde que la incidencia fue marcada como `RESUELTA`, entonces el sistema deberá deshabilitar la opción de reapertura y exigir la creación de un nuevo ticket.
* **EARS 9.3 (Estado/Control de Reincidencia):** El sistema permitirá un **máximo de 2 reaperturas sucesivas** por incidencia; si una máquina vuelve a fallar por 3ª vez consecutiva, el sistema marcará el expediente con la etiqueta *"Avería Crónica"* y bloqueará la reapertura automática desde el portal, obligando al usuario a contactar directamente con coordinación para una auditoría exhaustiva.

### RF-10: Cierre Automático y Archivabilidad
*El sistema consolidará el cierre definitivo de los expedientes sin intervención manual.*

* **EARS 10.1 (Evento/Temporal):** Cuando una incidencia permanezca en estado `RESUELTA` durante 48 horas continuadas sin haber sido reabierta, el sistema deberá cambiar su estado automáticamente a `CERRADA` definitiva.
* **EARS 10.2 (Ubicuo):** Una vez que una incidencia alcanza el estado `CERRADA`, el sistema deberá bloquear permanentemente cualquier modificación en sus campos y archivarla en el histórico inmutable de la máquina.

### RF-11: Alertas de SLA por Inactividad
*El sistema vigilará el cumplimiento de los tiempos de respuesta.*

* **EARS 11.1 (Estado/Temporal):** Mientras una incidencia con urgencia `CRÍTICA` permanezca en estado `REGISTRADA` durante más de 60 minutos naturales (24/7) sin que se le haya asignado técnico, el sistema deberá mostrar un indicador visual de alarma urgente en el panel del coordinador, actualizándose mediante sondeo (*polling*) ligero cada 60 segundos o al recargar la vista.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Usabilidad Móvil):** La interfaz para el técnico de campo deberá estar optimizada para uso en pantallas táctiles de smartphone en posición vertical, con botones de acción rápida accesibles con una sola mano.
* **RNF-02 (Agilidad en Reporte):** El flujo de reporte para el responsable de ubicación deberá completarse en menos de 2 minutos y un máximo de 3 pantallas/pasos.
* **RNF-03 (Integridad y No Destrucción):** Prohibición absoluta de operaciones `DELETE` físicas en el almacenamiento de datos. Cualquier anulación se realizará mediante cambios de estado lógicos (*Soft Delete*).
* **RNF-04 (Privacidad y Segregación):** La vista del responsable de ubicación nunca expondrá información confidencial interna, tales como nombres de repuestos con costes, teléfonos personales de técnicos ni comentarios marcados como internos.
* **RNF-05 (Seguridad en Archivos):** Todo archivo adjunto será analizado para verificar que no supere los 5 MB y que su contenido corresponda estrictamente a formatos de imagen seguros.
* **RNF-06 (Sistema de Diseño Visual):** La interfaz seguirá las directrices del sistema de diseño tokenizado en [`docs/design.md`](file:///C:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/docs/design.md): fondo canvas off-white (`#f9fafb`), azul eléctrico interactivo (`#2560ff`), texto slate (`#2c333f`), radio conservador de 4px para elementos interactivos y 8px para tarjetas, transmitiendo rigor de herramienta técnica.

---

## 6. Casos Límite Operativos (Edge Cases)

1. **Pérdida de conexión en el móvil del técnico al intentar cerrar:** Si el técnico pulsa "Resolver" sin cobertura de red en el sótano donde está la máquina, el sistema cliente debe conservar el texto del informe y notificar el reintento en cuanto se restablezca la conectividad.
2. **Intento de reporte simultáneo en el mismo segundo:** Si dos personas intentan reportar la misma máquina casi a la vez, el primer reporte que entre consolidará el ticket; la segunda petición es interceptada por el índice único condicional de duplicados en la base de datos inmediatamente.
3. **Cierre de ticket en el segundo 47h 59m vs 48h 01m:** La ventana de 48 horas se mide estrictamente por marca de tiempo de servidor (UTC) para evitar discrepancias por la zona horaria del dispositivo cliente.
4. **Reasignación de técnico en plena intervención:** Si un coordinador reasigna una incidencia mientras el técnico original la tiene en estado `EN_CURSO`, el sistema exigirá una confirmación explícita de alerta para advertir de que el técnico ya está trabajando in situ.
5. **Máquina irreparable en campo (Retirada a taller):** Si la máquina ha sufrido un daño catastrófico que impide su arreglo in situ, el técnico documenta en la incidencia la necesidad de *"Retirada a taller"*; el coordinador tramita la incidencia a estado `CANCELADA` con dicho motivo y marca la máquina con `is_active = 0` para excluirla del parque activo.
6. **Múltiples evidencias fotográficas:** Cuando se aportan fotos sucesivas en comentarios de un ticket activo, se guardan como registros independientes en `incident_comments`, garantizando que la fotografía original del aviso inicial jamás sea sobreescrita ni eliminada.

---

## 7. Fuera de Alcance (Out of Scope para el MVP)

* **Tramitación económica de reembolsos:** El registro del dinero retenido es meramente informativo. No se incluyen pasarelas bancarias, transferencias Bizum ni emisión de vales de saldo.
* **Telemetría e integración IoT MDB/DEX:** No hay comunicación automática por protocolo de bus de máquina ni sensores en tiempo real; todos los avisos se originan mediante interacción humana.
* **Gestión de inventario de furgonetas:** No se gestiona el descuento de piezas en el almacén rodante del técnico.
* **Rutas asistidas por GPS y geolocalización en tiempo real:** No se calcula el trayecto óptimo en mapa ni se sigue la ubicación física del técnico.
* **Portal de acceso para consumidores anónimos a pie de máquina:** El reporte queda acotado a los responsables autorizados por sede.

---

## 8. Criterios de Finalización del MVP (Definition of Done)

* [ ] El 100% de los requisitos funcionales (RF-01 al RF-11) están implementados y verificados.
* [ ] Se ha verificado que ninguna máquina puede tener más de un ticket activo de forma concurrente (incluyendo `REABIERTA` y `RESUELTA`).
* [ ] Se ha comprobado que las averías térmicas de alimentos se clasifican automáticamente como `CRÍTICA` y solo pueden reclasificarse con justificación auditada.
* [ ] Se ha probado que el cierre de incidencia bloquea envíos con menos de 20 caracteres en diagnóstico o en acción.
* [ ] Se ha validado que tras 48 horas continuadas la incidencia pasa a `CERRADA` sin posibilidad de reapertura.
* [ ] Se ha probado que al reabrir una incidencia el técnico previo queda desasignado y la garantía de 48h se reinicia.
* [ ] No existe ninguna instrucción de borrado físico destructivo en toda la lógica operativa.
* [ ] Todas las pruebas de aceptación automatizadas derivadas de los criterios EARS pasan con éxito.

---

## 9. Registro de Decisiones de Auditoría de Calidad (QA)

* **Resolución QA 1 (Bloqueo de Duplicados en Estados Intermedios):** `REABIERTA` y `RESUELTA` quedan formalmente integradas en el bloqueo de duplicados (EARS 2.1 y EARS 2.2).
* **Resolución QA 2 (Desglose de Urgencia Térmica):** Rotura de frío en alimentos = `CRÍTICA`; en bebidas/café = `MEDIA`. Categoría `"OTHER"` = `MEDIA` por defecto.
* **Resolución QA 3 (Reclasificación Auditada):** El coordinador puede reclasificar cualquier urgencia, pero exigiendo obligatoriamente un motivo auditado en el historial.
* **Resolución QA 4 (20 Caracteres por Campo):** El umbral mínimo aplica de forma independiente tanto al diagnóstico (>= 20) como a la acción realizada (>= 20).
* **Resolución QA 5 (Desasignación y Tope de Reaperturas):** Al reabrir, el técnico se desasigna automáticamente (`technician_id = NULL`), la garantía de 48h se reinicia a 0 y se establece un máximo de 2 reaperturas antes de declarar *"Avería Crónica"*.
* **Resolución QA 6 (SLA 24/7 y Polling):** El SLA de 60 minutos corre 24/7 de reloj continuo. La UI sondea el estado cada 60 segundos.
