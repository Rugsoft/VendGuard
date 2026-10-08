# ESPECIFICACIÓN FUNCIONAL: ESTADO OPERATIVO "PENDIENTE DE INFORMACIÓN" (PENDING_INFO) CON PAUSA DE SLA

**Documento:** `specs/11-pending-info-sla-pause/spec.md`  
**Referencia:** [`specs/functional/pending_info_sla_pause_spec.md`](../functional/pending_info_sla_pause_spec.md)  
**Estado:** Especificación Formal Ratificada y Blindada tras Auditoría QA  
**Fecha:** Octubre 2026  
**Autor:** Antigravity (Advanced Agentic Coding) & Coordinación de Operaciones  
**Proyecto:** VendGuard · Gestor de Incidencias de Vending  
**Alineación Constitucional:** Artículos I al VII de [constitution.md](../../constitution.md) (Especialmente Art. II, Art. III, Art. V.1, Art. V.2 y Art. V.4)  

---

## 1. Contexto y Objetivo

### 1.1. Contexto Operativo
En el mantenimiento correctivo de máquinas dispensadoras de vending, el personal técnico de campo y el equipo de coordinación se encuentran frecuentemente con situaciones de bloqueo físico u operativo imputables a la sede cliente:
* Las instalaciones del cliente se encuentran cerradas fuera del horario habitual o el conserje/recepcionista encargado de facilitar la llave de acceso a la sala no está disponible.
* La máquina fue reubicada dentro del inmueble sin avisar y resulta ilocalizable en la planta o ala reportada.
* El edificio sufre un corte general de suministro eléctrico o una avería en la acometida del inmueble, impidiendo encender o diagnosticar el equipo.
* El técnico requiere aclaraciones específicas del responsable del centro para poder acceder a áreas de acceso restringido (quirófanos, zonas de seguridad, recintos de personal).

Hasta este momento, el reloj de SLA (Acuerdo de Nivel de Servicio) y el cómputo de MTTR (Tiempo Medio de Reparación) continuaban corriendo implacablemente en el sistema mientras el técnico esperaba en la puerta. Esto generaba penalizaciones contractuales injustas para la empresa operadora por causas imputables exclusivamente al cliente, deterioraba las métricas operativas y distorsionaba los informes de auditoría con grandes cuentas.

### 1.2. Objetivo
Establecer un **Estado Operativo Formal denominado "Pendiente de Información" (`PENDING_INFO`)** que permita:
1. **Pausar de forma justificada el reloj de SLA contractual y descontar los tiempos de bloqueo del cómputo de MTTR**, protegiendo a la empresa operadora de penalizaciones injustas cuando el trabajo esté impedido por la sede cliente.
2. **Proteger la salud del consumidor y la seguridad alimentaria (Art. II)**, introduciendo una arquitectura de **doble reloj**: un reloj contractual congelado para la facturación y un **reloj sanitario biológico continuo de 4 horas** que activa automáticamente la cuarentena sanitaria en máquinas de frío si la avería permanece bloqueada en exceso.
3. **Establecer un canal de desbloqueo ágil y reactivación condicional inteligente**, diferenciando reactivaciones inmediatas in situ frente a retornos ordenados a estado asignado si el técnico ya ha abandonado las instalaciones o si el ticket fue reasignado.
4. **Garantizar la inmutabilidad histórica, el rigor operativo y la protección del consumidor (Art. III, Art. V.1 y Art. V.2)**, exigiendo motivos tipificados obligatorios de al menos 20 caracteres, impidiendo que una máquina cancelada por inactividad figure engañosamente como "Operativa", blindando las reclamaciones de reintegro económico pendientes y bloqueando bucles de reportes duplicados.

---

## 2. Usuarios y Perfiles Implicados

| Perfil / Actor | Permisos sobre la Pausa | Visibilidad de la Incidencia Pausada | Acciones Disponibles |
| :--- | :--- | :--- | :--- |
| **🏢 Responsable de Sede** | **No puede activar la pausa.** | Ve un banner destacado en su portal indicando que la avería requiere su ayuda, mostrando la causa del bloqueo. | Pulsa *"Aportar información / Responder al técnico"*, aporta aclaraciones en el hilo de conversación ($\ge 5$ caracteres) y provoca la **reactivación automática** del ticket. |
| **📱 Técnico de Ruta / Campo** | **Puede activar y reanudar la pausa.** | Ve la parada marcada como `⏸️ En espera de sede` en su ruta móvil, con contador de tiempo pausado. | Activa la pausa indicando causa tipificada y motivo $\ge 20$ caracteres. Continúa atendiendo otras paradas del día sin bloqueo. Reanuda manualmente cuando obtiene acceso in situ. |
| **📊 Coordinador de Operaciones** | **Control y supervisión total.** | Ve la incidencia con insignia de pausa y el reloj de SLA visualmente congelado en la bandeja de triaje. | Activa o reanuda la pausa, reasigna el ticket conservando el bloqueo, o ejecuta cancelación justificada si la sede no responde tras 72 horas hábiles de silencio. |
| **👥 Ciudadano / Usuario QR** | **Sin acceso a datos internos (Art. V.4).** | Ve un estado público neutro: *"En proceso de atención técnica"*. | Consulta el seguimiento de su reporte sin acceder a motivos internos de desencuentro con el edificio ni etiquetas técnicas de pausa. |

---

## 3. Historias de Usuario

### HU-01: Pausa Justificada del Técnico ante Falta de Acceso
> **Como** Técnico de Campo en ruta,  
> **quiero** pausar la incidencia a "Pendiente de Información" seleccionando la causa de bloqueo (ej. *"Edificio cerrado / Sin acceso a instalaciones"*) y describiendo la situación con al menos 20 caracteres,  
> **para** congelar el reloj de SLA contractual de la avería, evitar que me penalice el tiempo de espera y poder continuar atendiendo las siguientes paradas de mi jornada.

### HU-02: Notificación Clara y Desbloqueo Ágil desde la Sede
> **Como** Responsable de Sede,  
> **quiero** ver un aviso destacado en la tarjeta de mi máquina informándome de por qué el técnico no ha podido intervenir y un botón directo para responderle,  
> **para** facilitar la llave o aclarar la ubicación de inmediato, provocando la reanudación automática de la reparación sin necesidad de trámites complejos.

### HU-03: Reanudación Inteligente y Libre de Falsedades Operativas
> **Como** Técnico de Campo o Coordinador de Operaciones,  
> **quiero** que el sistema reanude a "En curso" si estoy físicamente en la máquina o a "Asignada" si la respuesta llega horas después o con otro técnico asignado,  
> **para** reflejar con veracidad absoluta cuándo estoy trabajando en el equipo y cuándo debo desplazarme nuevamente.

### HU-04: Supervisión de SLAs y Gestión de Abandonos en Coordinación
> **Como** Coordinador de Operaciones,  
> **quiero** visualizar el reloj de SLA congelado con el tiempo de pausa descontado, recibir una alerta si una avería supera 72 horas hábiles de silencio del cliente y poder cancelarla justificadamente sin que la máquina vuelva falsamente a estar operativa,  
> **para** mantener métricas contractuales limpias y transparentes sin acumular averías "fantasma" en el parque.

### HU-05: Protección Rigurosa de la Seguridad Alimentaria en Máquinas de Frío (Art. II)
> **Como** Coordinador de Operaciones y Responsable Sanitario,  
> **quiero** que un reloj sanitario biológico independiente active la cuarentena sanitaria de la máquina si pasan más de 4 horas naturales sin frío confirmado, y exija la retirada y destrucción del producto perecedero antes de cerrar la avería,  
> **para** garantizar de forma inviolable que los consumidores jamás ingieran comida en mal estado, anteponiendo la salud pública a la operativa comercial.

---

## 4. Requisitos Funcionales (Notación EARS)

### Módulo RF-01: Activación de la Pausa, Orígenes y Causas Tipificadas (Art. V.1)
* **RF-01.1 [EARS - Estado]:**  
  MIENTRAS una incidencia se encuentre en estado asignada (`ASSIGNED`), en curso de intervención (`IN_PROGRESS`), reabierta en garantía (`REOPENED`) o pendiente de repuestos (`PENDING_PARTS`), el sistema DEBE permitir que el Técnico de Campo asignado o el Coordinador de Operaciones transicionen la avería al estado "Pendiente de Información" (`PENDING_INFO`).
* **RF-01.2 [EARS - Dirigido por eventos]:**  
  CUANDO un usuario autorizado solicite pausar la incidencia a `PENDING_INFO`, el sistema DEBE exigir obligatoriamente la selección de una causa tipificada de entre las siguientes opciones:
    1. *"Edificio cerrado / Sin acceso a instalaciones"*
    2. *"Máquina no localizada en la planta o zona indicada"*
    3. *"Corte eléctrico o de suministro ajeno a la máquina"*
    4. *"Pendiente de autorización o contacto de sede"*
* **RF-01.3 [EARS - Dirigido por eventos]:**  
  CUANDO se intente confirmar la pausa a `PENDING_INFO`, el sistema DEBE validar que el motivo explicativo complementario contenga **al menos 20 caracteres descriptivos reales** (excluyendo espacios en blanco superfluos), bloqueando la acción si no se cumple esta condición (Art. V.1).
* **RF-01.4 [EARS - Ubicuo]:**  
  El sistema DEBE registrar de forma inmutable en el historial de la incidencia (`incident_history`) el estado previo de origen, el usuario responsable de la pausa, la marca temporal exacta, la causa tipificada y el texto de justificación (Art. III).

---

### Módulo RF-02: Reactivación Condicional Inteligente y Reanudación Manual
* **RF-02.1 [EARS - Dirigido por eventos]:**  
  CUANDO la incidencia se encuentre en `PENDING_INFO` y el Responsable de Sede publique un comentario público con al menos 5 caracteres descriptivos reales (con o sin fotografía adjunta), el sistema DEBE **reactivar automáticamente** la incidencia de acuerdo con la siguiente regla condicional:
    * **SI** la respuesta se produce en caliente (**menos de 60 minutos** desde la pausa) y el técnico asignado no ha iniciado otra intervención en su ruta diaria, el sistema DEBE transicionar la avería al estado en curso (`IN_PROGRESS`).
    * **SI** han transcurrido **más de 60 minutos** desde la pausa, el técnico ya inició otra intervención en su ruta o la avería fue reasignada a otro técnico durante la pausa, el sistema DEBE transicionar la avería al estado asignada (`ASSIGNED`), notificando al técnico de la disponibilidad de acceso para que inicie la intervención al personarse ante el equipo.
* **RF-02.2 [EARS - Estado]:**  
  MIENTRAS la incidencia se encuentre en `PENDING_INFO`, el sistema DEBE ofrecer al Técnico de Campo asignado y al Coordinador de Operaciones una acción manual explícita de *"Reanudar intervención"*.
* **RF-02.3 [EARS - Dirigido por eventos]:**  
  CUANDO el Técnico o el Coordinador confirmen manualmente la reanudación de la intervención:
    * Si la ejecuta el Técnico asignado in situ, el sistema DEBE transicionar a `IN_PROGRESS`.
    * Si la ejecuta el Coordinador desde la central, el sistema DEBE ofrecer seleccionar si reanuda a `ASSIGNED` (para que el técnico acuda) o a `IN_PROGRESS` (si confirma que el técnico ya está trabajando en la máquina).
* **RF-02.4 [EARS - Ubicuo]:**  
  Toda reanudación (automática o manual) DEBE registrar de forma inmutable la marca temporal de finalización del intervalo de pausa en la bitácora de auditoría.

---

### Módulo RF-03: Cómputo de SLA y Arquitectura de Doble Reloj Sanitario (Art. II)
* **RF-03.1 [EARS - Ubicuo]:**  
  El sistema DEBE calcular la duración acumulada de cada intervalo transcurrido en `PENDING_INFO` ($\Delta t = t_{\text{reanudación}} - t_{\text{pausa}}$) y descontarlo íntegramente del tiempo computable para el MTTR y los compromisos de SLA contractual.
* **RF-03.2 [EARS - Estado]:**  
  MIENTRAS la incidencia permanezca en `PENDING_INFO`, el sistema DEBE presentar el reloj contractual de cuenta atrás de SLA visualmente congelado con un distintivo explícito de pausa (`⏸️ SLA pausado`), indicando los minutos acumulados en espera de la sede.
* **RF-03.3 [EARS - Dirigido por eventos]:**  
  CUANDO la incidencia sea reanudada, el sistema DEBE recalcular la fecha límite contractual (`sla_target_at`) desplazándola hacia adelante en el tiempo los minutos pausados, aplicando el desplazamiento estrictamente dentro de la **ventana de horario operativo comercial de la sede** (08:00 a 18:00, lunes a viernes), impidiendo vencimientos artificiales en horarios nocturnos o inhábiles.
* **RF-03.4 [EARS - Estado / Seguridad Alimentaria - Art. II]:**  
  MIENTRAS una máquina dispense alimentos perecederos con cadena de frío (`isPerishable = true`), el sistema DEBE mantener en ejecución paralela e ininterrumpida un **Reloj Sanitario Biológico en tiempo natural continuo de 24 horas**.
* **RF-03.5 [EARS - Excepcional / Seguridad Alimentaria - Art. II]:**  
  SI el Reloj Sanitario Biológico alcanza las **4 horas naturales continuas sin refrigeración confirmada**, el sistema DEBE:
    1. Activar automáticamente el **Modo de Cuarentena Sanitaria** en la máquina, mostrándola bloqueada en el portal de sede y en el escaneo QR público con el distintivo de *"Riesgo térmico: máquina en cuarentena preventiva"*.
    2. Bloquear de forma estricta e insalvable el formulario de resolución técnica (`RESOLVED`), exigiendo obligatoriamente al técnico declarar: (a) registro de temperatura real del recinto térmico, (b) confirmación de retirada y destrucción del stock perecedero deteriorado por rotura de frío, y (c) ejecución de checklist de higienización sanitaria antes de poder desbloquear la máquina.

---

### Módulo RF-04: Pausas Múltiples y Gestión de Inactividad de 72 Horas Hábiles (Art. V.1 y V.2)
* **RF-04.1 [EARS - Opcional]:**  
  DONDE una incidencia requiera pausas sucesivas durante su ciclo de vida (p. ej., acceso inicial bloqueado y posterior corte eléctrico en la prueba), el sistema DEBE permitir registrar múltiples intervalos de `PENDING_INFO`, sumando acumulativamente todas sus duraciones al descuento total de SLA.
* **RF-04.2 [EARS - Estado]:**  
  MIENTRAS una incidencia acumule más de **72 horas hábiles comerciales** (lunes a viernes de 08:00 a 18:00) en estado `PENDING_INFO` sin respuesta de la Sede, el sistema DEBE clasificarla con la insignia destacada de *"En espera prolongada de cliente"* y generar una alerta prioritaria en la bandeja de triaje del Coordinador.
* **RF-04.3 [EARS - Estado]:**  
  MIENTRAS una incidencia se encuentre en espera prolongada ($> 72\text{ h}$ hábiles), el sistema DEBE facultar al Coordinador de Operaciones para ejecutar una **cancelación justificada** (`CANCELLED`) con motivo obligatorio $\ge 20$ caracteres (ej. *"Cierre administrativo por inactividad y falta de acceso del cliente tras 72h hábiles"*).
* **RF-04.4 [EARS - Ubicuo / Máquinas e Inviolabilidad - Art. V.1]:**  
  CUANDO una avería sea cancelada por falta de acceso tras 72 horas hábiles, la máquina **NO DEBE volver al estado "Operativa"**; el sistema DEBE transicionar la máquina al estado formal *"Fuera de servicio / Bloqueada por falta de acceso"*, evitando que figure engañosamente como apta para el servicio.
* **RF-04.5 [EARS - Ubicuo / Reintegros - Módulo 08]:**  
  CUANDO una avería con una reclamación de dinero retenido vinculada sea cancelada por inactividad de sede, el sistema **NO DEBE cancelar la solicitud de reintegro**; DEBE desvincularla de la avería técnica y trasladarla a la bandeja de Coordinación como reintegro pendiente de liquidación por custodia central (Bizum/IBAN), garantizando que el consumidor final cobre su dinero.
* **RF-04.6 [EARS - Ubicuo / Prevención de Duplicados - Art. V.2]:**  
  SI la Sede intenta registrar un nuevo aviso sobre una máquina bloqueada por falta de acceso previo, el sistema DEBE interceptar la solicitud y exigir que el usuario marque la casilla obligatoria: *"Confirmo formalmente que las instalaciones y la máquina se encuentran abiertas y accesibles para el servicio técnico"*, impidiendo bucles de avisos bloqueados.

---

### Módulo RF-05: Experiencia de Usuario, Sellado e Idempotencia
* **RF-05.1 [EARS - Estado]:**  
  MIENTRAS la máquina tenga una avería en `PENDING_INFO`, el Portal del Responsable de Sede DEBE mostrar en la tarjeta de la máquina un banner prominente en tono ámbar informativo (*"⏸️ Intervención en pausa: El servicio técnico requiere acceso o información"*) detallando la causa tipificada y un botón destacado *"Aportar información / Responder al técnico"*.
* **RF-05.2 [EARS - Dirigido por eventos]:**  
  CUANDO el Responsable de Sede pulse *"Aportar información / Responder al técnico"*, el sistema DEBE abrir de inmediato el hilo de conversación de la avería, facilitando la redacción del mensaje.
* **RF-05.3 [EARS - Ubicuo / Idempotencia]:**  
  SI la Sede envía múltiples mensajes o fotografías en ráfaga (en intervalos de pocos segundos), el sistema DEBE procesar la reactivación en el primer mensaje de forma atómica y anexar los mensajes subsiguientes a la bitácora como comentarios normales de seguimiento, sin provocar errores ni intentos redundantes de cambio de estado.
* **RF-05.4 [EARS - Estado / Sellado]:**  
  SI una incidencia fue cancelada tras 72 horas hábiles, el hilo de conversación DEBE quedar **sellado en modo estricto de solo lectura** (Art. III y Módulo 10); cualquier intento de enviar comentarios posteriores debe ser rechazado, indicando a la sede que debe confirmar el acceso a la máquina para solicitar una nueva asistencia.
* **RF-05.5 [EARS - Estado]:**  
  MIENTRAS una parada en la vista móvil "Mi Ruta" se encuentre en `PENDING_INFO`, el sistema DEBE mostrar la insignia `⏸️ En espera de sede` junto con el contador de tiempo pausado, **permitiendo al técnico omitir dicha parada y continuar atendiendo las siguientes visitas programadas del día sin bloqueos ni penalizaciones**.

---

### Módulo RF-06: Segregación Pública y Transiciones Prohibidas (Art. V.1 y V.4)
* **RF-06.1 [EARS - Estado / Art. V.4]:**  
  MIENTRAS un ciudadano consulte el seguimiento público de una avería mediante código QR, el sistema DEBE presentar un estado neutral: *"En proceso de atención técnica"*, **ocultando totalmente los motivos internos de pausa, llaves ausentes o discrepancias logísticas con el inmueble**.
* **RF-06.2 [EARS - Ubicuo / Art. V.1]:**  
  Queda terminantemente PROHIBIDO transicionar directamente una incidencia desde el estado `PENDING_INFO` al estado resuelta (`RESOLVED`). Para poder resolver una avería, es obligatorio reanudar previamente a `IN_PROGRESS` y registrar la intervención física, diagnóstico y solución ejecutada.
* **RF-06.3 [EARS - Dirigido por eventos]:**  
  CUANDO el Coordinador reasigne una incidencia a otro técnico mientras se encuentra en `PENDING_INFO`, el sistema DEBE conservar el estado `PENDING_INFO` asignando la responsabilidad al nuevo técnico, quien heredará el contexto y el motivo del bloqueo.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Precisión en el Cómputo de Tiempos):**  
  El cálculo de los intervalos de pausa y el desplazamiento de la fecha límite de SLA DEBE realizarse con precisión de segundos en base de datos y mostrarse redondeado al minuto más próximo en la interfaz de usuario.
* **RNF-02 (Rendimiento en la Transición de Pausa):**  
  La ejecución de la pausa o reanudación, incluyendo la actualización del historial de auditoría y el recálculo de la fecha de SLA, DEBE completarse en menos de **200 milisegundos**.
* **RNF-03 (Ergonomía Móvil para Técnicos):**  
  El formulario móvil para declarar la pausa debe ser operable con una sola mano en terminales de 360px de ancho, con botones de causa táctiles amplios ($\ge 44\text{ px}$ de altura) y contador reactivo de caracteres restantes para la justificación.
* **RNF-04 (Consistencia en el Sistema de Diseño):**  
  El estado `PENDING_INFO` utilizará los tokens de diseño visual consolidados: distintivo ámbar técnico `#fef9c3` con texto `#854d0e`, borde de 4px para insignias y botones interactivos, y contraste accesible WCAG AA.
* **RNF-05 (Inviolabilidad Histórica de Auditoría - Art. III):**  
  Todos los cambios hacia o desde `PENDING_INFO` son de solo adición (*Append-Only*). Queda prohibida la modificación o borrado de eventos de pausa previos en el historial de la incidencia.
* **RNF-06 (Prevención de Pérdida de Datos en Borrador):**  
  Si el usuario pulsa `Escape` o hace clic fuera de la ventana de pausa mientras redacta la justificación, el sistema DEBE advertir y solicitar confirmación para no perder el texto escrito.

---

## 6. Casos Límite y Comportamiento ante Errores

1. **Reanudación instantánea por error del técnico:**  
   Si un técnico pausa a `PENDING_INFO` por error y reanuda inmediatamente en menos de 60 segundos, el sistema registra el evento en auditoría pero no altera de forma apreciable el reloj de SLA (duración de pausa $\approx 0$).
2. **Reanudación concurrente simultánea:**  
   Si el técnico pulsa *"Reanudar intervención"* en el mismo segundo en que la sede publica un comentario de respuesta, el sistema resuelve la concurrencia atómicamente garantizando que la avería pasa limpiamente al estado operativo activo sin estados inconsistentes ni errores bloqueantes.
3. **Cierre de jornada con ticket en pausa:**  
   Si una avería queda en `PENDING_INFO` al finalizar la jornada laboral, el reloj de SLA permanece congelado durante la noche y el fin de semana hasta que se produzca la respuesta de la sede o la reactivación técnica dentro del horario hábil.
4. **Intento de reanudación sin técnico asignado:**  
   Si una avería en `PENDING_INFO` fue desasignada por Coordinación, al reactivarse pasa forzosamente al estado `ASSIGNED` o `REGISTERED` requiriendo una asignación formal antes de poder iniciar trabajos.
5. **Comentario interno de técnico mientras está en pausa:**  
   Si un técnico o coordinador publica una nota interna de taller (`is_internal = true`) mientras el ticket está en `PENDING_INFO`, la avería **permanece en pausa**; únicamente los comentarios públicos de la Sede con $\ge 5$ caracteres activan la reanudación automática.
6. **Transición desde `PENDING_PARTS` a `PENDING_INFO`:**  
   Si el técnico acude con el repuesto solicitado pero no puede acceder a las instalaciones, se autoriza la transición directa de `PENDING_PARTS` a `PENDING_INFO` registrando la causa tipificada y pausando el cómputo de SLA.

---

## 7. Fuera de Alcance

Para mantener el principio de foco exclusivo en el valor operativo inmediato y anti-complejidad innecesaria:
1. **Detección telemática IoT de apertura de puertas:** La notificación del acceso depende de la interacción humana (comentario del cliente o pulsación del técnico).
2. **Integración con sistemas de control de accesos de terceros:** No se sincroniza con tornos ni lectores RFID de edificios clientes.
3. **Llamadas telefónicas automáticas o mensajería SMS saliente:** La comunicación se vehicula a través del portal y el hilo de conversación bidireccional del expediente.
4. **Pausa de SLA por falta de repuestos (`PENDING_PARTS`):** La falta de repuestos es responsabilidad logística de la empresa mantenedora y se rige por su propio flujo operativo de taller, no por este estado.

---

## 8. Criterios de Finalización

La especificación se considerará formalmente cumplida cuando:
1. El Técnico y el Coordinador puedan pausar una incidencia en `ASSIGNED`, `IN_PROGRESS` o `PENDING_PARTS` seleccionando una causa tipificada y redactando un motivo de $\ge 20$ caracteres.
2. El reloj contractual de SLA se congele visualmente y su fecha límite se aplace exactamente en la cantidad de minutos que dure la pausa, computados en horario comercial de la sede.
3. En máquinas de alimentos perecederos (`isPerishable = true`), el Reloj Sanitario Biológico active la cuarentena automática a las 4 horas naturales continuas sin frío confirmado y exija la retirada y destrucción del producto perecedero antes de cerrar la avería (Art. II).
4. El Responsable de Sede visualice el banner explicativo en su tarjeta de máquina y pueda reactivar automáticamente la incidencia aportando un comentario público $\ge 5$ caracteres, devolviendo el ticket a `IN_PROGRESS` si ocurre en caliente (< 60 min) o a `ASSIGNED` si ha transcurrido más tiempo.
5. El Técnico y el Coordinador dispongan de la opción manual de *"Reanudar intervención"*.
6. Si la avería supera 72 horas hábiles de silencio, el Coordinador pueda cancelarla justificadamente sin que la máquina vuelva a figurar como "Operativa", desvinculando de forma segura los reintegros económicos pendientes y exigiendo confirmación de acceso en nuevos reportes (Art. V.1 y V.2).
7. El seguimiento ciudadano por código QR oculte totalmente el estado de pausa y motivos internos, mostrando un estado público neutro (Art. V.4).
8. Quede bloqueada cualquier transición directa desde `PENDING_INFO` hacia `RESOLVED` sin pasar por `IN_PROGRESS` (Art. V.1).
9. Se verifiquen al 100% todos los requisitos funcionales (RF-01 a RF-06) y no funcionales (RNF-01 a RNF-06) con pruebas automatizadas completas.

---

## 9. Dudas Abiertas

* **Ninguna duda abierta.** Todas las definiciones operativas, actores autorizados, causas tipificadas, cálculo de SLA comercial, doble reloj biológico con cuarentena automática (Art. II), límites de inactividad de 72 horas hábiles, preservación de estado de máquina y reintegros (Art. V.1) y visibilidad pública segregada han sido unánimemente acordadas, validadas y blindadas tras la auditoría QA.
