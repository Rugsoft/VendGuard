# ESPECIFICACIÓN FUNCIONAL: ESTADO OPERATIVO "PENDIENTE DE INFORMACIÓN" (PENDING_INFO) CON PAUSA DE SLA

**Documento:** `specs/functional/pending_info_sla_pause_spec.md`  
**Referencia de Módulo:** `specs/11-pending-info-sla-pause/spec.md`  
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
3. **Establecer un canal de desbloqueo ágil sin ficciones de estado**: el mensaje de la sede avisa al técnico de que tiene respuesta y la pausa se cierra siempre con una **reanudación explícita** —la del técnico in situ o la del coordinador—, nunca por inferencia del sistema (enmienda del Product Owner, 2026-10-11).
4. **Garantizar la inmutabilidad histórica, el rigor operativo y la protección del consumidor (Art. III, Art. V.1 y Art. V.2)**, exigiendo motivos tipificados obligatorios de al menos 20 caracteres, impidiendo que una máquina cancelada por inactividad figure engañosamente como "Operativa", blindando las reclamaciones de reintegro económico pendientes y bloqueando bucles de reportes duplicados.

---

## 2. Usuarios y Perfiles Implicados

| Perfil / Actor | Permisos sobre la Pausa | Visibilidad de la Incidencia Pausada | Acciones Disponibles |
| :--- | :--- | :--- | :--- |
| **🏢 Responsable de Sede** | **No puede activar la pausa.** | Ve un banner destacado en su portal indicando que la avería requiere su ayuda, mostrando la causa del bloqueo. | Pulsa *"Aportar información / Responder al técnico"* y aporta aclaraciones en el hilo de conversación ($\ge 5$ caracteres). Su mensaje **no altera el estado ni el reloj**: marca la respuesta para que el técnico reanude la intervención cuando confirme el acceso (enmienda del Product Owner, 2026-10-11). |
| **📱 Técnico de Ruta / Campo** | **Puede activar y reanudar la pausa.** | Ve la parada marcada como `⏸️ En espera de sede` en su ruta móvil, con contador de tiempo pausado y, cuando la sede ya respondió, el aviso *"✉️ La sede ha respondido · pendiente de tu reanudación"* (RF-05.6). | Activa la pausa indicando causa tipificada y motivo $\ge 20$ caracteres. Continúa atendiendo otras paradas del día sin bloqueo. Reanuda manualmente cuando obtiene acceso in situ. |
| **📊 Coordinador de Operaciones** | **Control y supervisión total.** | Ve la incidencia con insignia de pausa y el reloj de SLA visualmente congelado en la bandeja de triaje. | Activa o reanuda la pausa, reasigna el ticket conservando el bloqueo, o ejecuta cancelación justificada cuando la espera sin reanudación supera las 72 horas hábiles. |
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
> **para** facilitar la llave o aclarar la ubicación de inmediato, de modo que el técnico reanude la reparación en cuanto confirme el acceso, sin trámites complejos.

### HU-03: Reanudación Inteligente y Libre de Falsedades Operativas
> **Como** Técnico de Campo o Coordinador de Operaciones,  
> **quiero** reanudar yo mismo a "En curso" cuando esté físicamente ante la máquina, o que el coordinador reanude desde central con el destino que corresponda,  
> **para** reflejar con veracidad absoluta cuándo estoy trabajando en el equipo y cuándo debo desplazarme nuevamente: ningún mensaje ajeno puede declarar que estoy trabajando.

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
  CUANDO la incidencia se encuentre en `PENDING_INFO` y el Responsable de Sede publique un comentario público con al menos 5 caracteres descriptivos reales (con o sin fotografía adjunta), el sistema DEBE anexar el mensaje al hilo, **marcar la respuesta de la sede** para el técnico asignado (RF-05.6) y **NO DEBE transicionar el estado ni tocar el reloj contractual**: la pausa sigue vigente, el SLA continúa congelado y la única salida es la reanudación explícita (RF-02.2 y RF-02.3) o la cancelación justificada por inactividad (RF-04.3).  
  > **Enmienda del Product Owner (2026-10-11).** Se deroga la reactivación automática condicional por respuesta de sede —las ramas «en caliente» a `IN_PROGRESS`, desfasada a `ASSIGNED` y el reingreso a `REGISTERED`—: declarar «En curso» a un profesional que puede seguir en la puerta de otro edificio era exactamente la ficción operativa que HU-03 quiere erradicar, y el mensaje de un cliente no puede reiniciar por inferencia un trabajo que solo el técnico sabe si puede retomar. La coherencia estado-responsable del cierre de F-5 sigue vigente para las reanudaciones manuales y la impone el agregado `Incident::resumePendingInfo()`.
* **RF-02.2 [EARS - Estado]:**  
  MIENTRAS la incidencia se encuentre en `PENDING_INFO`, el sistema DEBE ofrecer al Técnico de Campo asignado y al Coordinador de Operaciones una acción manual explícita de *"Reanudar intervención"*.
* **RF-02.3 [EARS - Dirigido por eventos]:**  
  CUANDO el Técnico o el Coordinador confirmen manualmente la reanudación de la intervención:
    * Si la ejecuta el Técnico asignado in situ, el sistema DEBE transicionar a `IN_PROGRESS`.
    * Si la ejecuta el Coordinador desde la central, el sistema DEBE ofrecer seleccionar si reanuda a `ASSIGNED` (para que el técnico acuda) o a `IN_PROGRESS` (si confirma que el técnico ya está trabajando en la máquina). En un expediente sin técnico responsable el único destino legal es `REGISTERED`: la avería vuelve a la cola de asignación en lugar de declarar un responsable inexistente (enmienda del cierre de F-5, 2026-10-11).
* **RF-02.4 [EARS - Ubicuo]:**  
  Toda reanudación manual DEBE registrar de forma inmutable la marca temporal de finalización del intervalo de pausa en la bitácora de auditoría.

---

### Módulo RF-03: Cómputo de SLA y Arquitectura de Doble Reloj Sanitario (Art. II)
* **RF-03.1 [EARS - Ubicuo]:**  
  El sistema DEBE calcular la duración acumulada de cada intervalo transcurrido en `PENDING_INFO` ($\Delta t = t_{\text{reanudación}} - t_{\text{pausa}}$) y descontarlo íntegramente del tiempo computable para el MTTR y los compromisos de SLA contractual.
* **RF-03.2 [EARS - Estado]:**  
  MIENTRAS la incidencia permanezca en `PENDING_INFO`, el sistema DEBE presentar el reloj contractual de cuenta atrás de SLA visualmente congelado con un distintivo explícito de pausa (`⏸️ SLA pausado`), indicando los minutos acumulados en espera de la sede.
* **RF-03.3 [EARS - Dirigido por eventos]:**  
  CUANDO la incidencia sea reanudada, el sistema DEBE recalcular la fecha límite contractual (`sla_target_at`) desplazándola hacia adelante en el tiempo los minutos pausados, aplicando el desplazamiento estrictamente dentro de la **ventana de horario operativo comercial de la sede** (08:00 a 18:00, lunes a viernes), impidiendo vencimientos artificiales en horarios nocturnos o inhábiles.  
  > **Criterio de contrato (2026-10-10, cierre de F-3).** Los «minutos pausados» que se desplazan son los **minutos hábiles realmente perdidos** durante la pausa, no su duración de pared. Las dos definiciones que convivían en el pliego —«reloj contractual congelado» (RF-03.2) y «desplazar los minutos pausados» (RF-03.3)— se resuelven así: si el reloj se congela mientras la sede no atiende, al reanudar sólo se devuelve a la cuenta atrás el tiempo que la jornada de la sede dejó de correr. Una espera de viernes 18:00 a lunes 08:00 —el escenario exacto de RF-01.1— **no mueve la fecha límite**, porque la sede no perdió ni un segundo de jornada; con la lectura anterior, esa misma espera dilataba el compromiso entre 4 y 5 veces, justo en el caso más habitual de la pausa. La duración de pared del intervalo sigue siendo la que se audita en la bitácora y la que descuenta el MTTR (RF-03.1, RF-04.1), y por eso los dos números no coinciden nunca y ninguno sustituye al otro. La medición la toma un único punto (`IncidentPauseService::pauseBusinessSecondsSince()`), sobre el que se apoyan el desplazamiento persistido y la fecha recalculada que publica la ficha de coordinación, de modo que lectura y escritura no puedan discrepar.
* **RF-03.4 [EARS - Estado / Seguridad Alimentaria - Art. II]:**  
  MIENTRAS una máquina quede bajo vigilancia sanitaria (`MachineType::requiresSanitaryWatch() = true`, que cubre la máquina de alimentos perecederos `PERISHABLE_FOOD` **y la máquina mixta `COMBO` con módulo refrigerado de producto fresco**), el sistema DEBE mantener en ejecución paralela e ininterrumpida un **Reloj Sanitario Biológico en tiempo natural continuo de 24 horas**.  
  > **Ratificación del Product Owner (2026-10-10, T-PAUSE-26).** La primera redacción limitaba la vigilancia a `isPerishable = true`, el flag que nombra la máquina **exclusiva** de perecederos, mientras el flujo preventivo ya trataba `COMBO` como máquina de frío (EARS 3.2 de `specs/functional/preventive_maintenance_spec.md` exige registro de temperatura de sonda en `PERISHABLE_FOOD`, `COLD_DRINKS` y `COMBO`). Una sola tipología no puede ser perecedera para la inspección reglamentaria y no serlo para la cuarentena automática sin abrir un hueco al riesgo térmico, así que la vigilancia se extiende a la mixta. Las bebidas frías (`COLD_DRINKS`) quedan **fuera** a propósito: no alojan producto perecedero y su tolerancia térmica es otra (hasta 8,0 °C en el checklist preventivo).
* **RF-03.5 [EARS - Excepcional / Seguridad Alimentaria - Art. II]:**  
  SI el Reloj Sanitario Biológico alcanza las **4 horas naturales continuas sin refrigeración confirmada**, el sistema DEBE:
    1. Activar automáticamente el **Modo de Cuarentena Sanitaria** en la máquina, mostrándola bloqueada en el portal de sede y en el escaneo QR público con el distintivo de *"Riesgo térmico: máquina en cuarentena preventiva"*.
    2. Bloquear de forma estricta e insalvable el formulario de resolución técnica (`RESOLVED`), exigiendo obligatoriamente al técnico declarar: (a) registro de temperatura real del recinto térmico, (b) confirmación de retirada y destrucción del stock perecedero deteriorado por rotura de frío, y (c) ejecución de checklist de higienización sanitaria antes de poder desbloquear la máquina.

---

### Módulo RF-04: Pausas Múltiples y Gestión de Inactividad de 72 Horas Hábiles (Art. V.1 y V.2)

> **Criterio de contrato (2026-10-10).** La frontera del umbral es **estricta** (más de 72 h hábiles, no iguales) y se mide en horario comercial de la sede (08:00-18:00, lunes a viernes). Una sola regla (`IncidentPauseService::isProlongedInactivity()`) decide la insignia de RF-04.2, la disponibilidad de la acción en la ficha de coordinación y la admisión de la cancelación de RF-04.3.
* **RF-04.1 [EARS - Opcional]:**  
  DONDE una incidencia requiera pausas sucesivas durante su ciclo de vida (p. ej., acceso inicial bloqueado y posterior corte eléctrico en la prueba), el sistema DEBE permitir registrar múltiples intervalos de `PENDING_INFO`, sumando acumulativamente todas sus duraciones al descuento total de SLA.
* **RF-04.2 [EARS - Estado]:**  
  MIENTRAS una incidencia acumule más de **72 horas hábiles comerciales** (lunes a viernes de 08:00 a 18:00) en estado `PENDING_INFO` sin reanudación, el sistema DEBE clasificarla con la insignia destacada de *"En espera prolongada de cliente"* y generar una alerta prioritaria en la bandeja de triaje del Coordinador.
  > **Nota de coherencia (enmienda del Product Owner, 2026-10-11).** La insignia y el umbral miden la **pausa sin reanudación**, no el silencio de la sede: con la reactivación automática derogada (RF-02.1), una sede que ya respondió sin que nadie reanude también acumula espera. El coordinador ve la respuesta en el hilo y decide; la cancelación exige siempre la misma medición del umbral (RF-04.3).
* **RF-04.3 [EARS - Estado]:**  
  MIENTRAS una incidencia se encuentre en espera prolongada ($> 72\text{ h}$ hábiles), el sistema DEBE facultar al Coordinador de Operaciones para ejecutar una **cancelación justificada** (`CANCELLED`) con motivo obligatorio $\ge 20$ caracteres (ej. *"Cierre administrativo por inactividad y falta de acceso del cliente tras 72h hábiles"*).  
  > **Ratificación del Product Owner (2026-10-10, cierre de F-1).** El «MIENTRAS una incidencia se encuentre en espera prolongada» es **precondición**, no mera descripción del contexto: fuera de la ventana de las 72 horas hábiles la operación se rechaza con `422 INACTIVITY_THRESHOLD_NOT_REACHED` y **no se escribe nada** (ni ticket, ni máquina, ni reintegro, ni rastro). Se enmienda la decisión contraria anotada en `specs/11-pending-info-sla-pause/tasks.md` (T-PAUSE-09 y T-PAUSE-13), que leía el verbo «facultar» como una autorización sin cerrojo: el cierre administrativo que la bitácora inmutable certifica debe corresponder a una espera realmente medida, y un sistema que sella 72 horas hábiles inexistentes falsea el Art. III. La detección del umbral es la misma regla que enciende la insignia de RF-04.2, de modo que lectura y escritura no pueden discrepar.
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
  SI la Sede envía múltiples mensajes o fotografías en ráfaga (en intervalos de pocos segundos), el sistema DEBE anexarlos todos a la bitácora como comentarios de seguimiento, sin transición alguna de estado que proteger ni errores por mensajes redundantes: la reanudación dejó de ser automática (RF-02.1 enmendado).
* **RF-05.4 [EARS - Estado / Sellado]:**  
  SI una incidencia fue cancelada tras 72 horas hábiles, el hilo de conversación DEBE quedar **sellado en modo estricto de solo lectura** (Art. III y Módulo 10); cualquier intento de enviar comentarios posteriores debe ser rechazado, indicando a la sede que debe confirmar el acceso a la máquina para solicitar una nueva asistencia.
* **RF-05.5 [EARS - Estado]:**  
  MIENTRAS una parada en la vista móvil "Mi Ruta" se encuentre en `PENDING_INFO`, el sistema DEBE mostrar la insignia `⏸️ En espera de sede` junto con el contador de tiempo pausado, **permitiendo al técnico omitir dicha parada y continuar atendiendo las siguientes visitas programadas del día sin bloqueos ni penalizaciones**.
* **RF-05.6 [EARS - Estado]:**  
  MIENTRAS una parada en "Mi Ruta" esté en `PENDING_INFO` y la sede haya publicado algún comentario público posterior al inicio de la pausa vigente, la tarjeta DEBE mostrar el aviso *"✉️ La sede ha respondido · pendiente de tu reanudación"* junto a la insignia de pausa, para que el técnico sepa que la respuesta que esperaba ya está en el hilo y que la pausa solo termina cuando él reanude (RF-02.2, enmienda del Product Owner, 2026-10-11).

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
   El comentario de la sede ya no compite por el estado (RF-02.1 enmendado): si el técnico pulsa *"Reanudar intervención"* en el mismo segundo en que la sede publica su mensaje, el mensaje se anexa y la reanudación se aplica como cualquier otra, sin carrera de transiciones. Dos reanudaciones manuales simultáneas —técnico y coordinador— siguen serializadas por el bloqueo de fila (T-PAUSE-30): una sola cierra la pausa.
3. **Cierre de jornada con ticket en pausa:**  
   Si una avería queda en `PENDING_INFO` al finalizar la jornada laboral, el reloj de SLA permanece congelado durante la noche y el fin de semana hasta que el técnico asignado o el coordinador reanuden manualmente, dentro del horario hábil.
4. **Intento de reanudación sin técnico asignado:**  
   Si una avería en `PENDING_INFO` fue desasignada por Coordinación, al reactivarse pasa forzosamente al estado `REGISTERED`, que la reingresa en la cola de asignación y exige una asignación formal antes de poder iniciar trabajos. Ni `ASSIGNED` ni `IN_PROGRESS` son destinos legales sin técnico responsable: describirían a un profesional que no existe. En el sentido inverso, un expediente con técnico tampoco puede disfrazarse de `REGISTERED` al reanudarse (enmienda del cierre de F-5, 2026-10-11; la coherencia la impone el agregado `Incident`).
5. **Comentario interno de técnico mientras está en pausa:**  
   Si un técnico o coordinador publica una nota interna de taller (`is_internal = true`) mientras el ticket está en `PENDING_INFO`, la avería **permanece en pausa**, como con cualquier comentario público de la sede: ninguna publicación altera el estado ni el reloj (RF-02.1 enmendado) y la reanudación es siempre un acto explícito del técnico o del coordinador.
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
3. En las máquinas bajo vigilancia sanitaria (`MachineType::requiresSanitaryWatch() = true`: alimentos perecederos `PERISHABLE_FOOD` y mixtas `COMBO`), el Reloj Sanitario Biológico active la cuarentena automática a las 4 horas naturales continuas sin frío confirmado y exija la retirada y destrucción del producto perecedero antes de cerrar la avería (Art. II).
4. El Responsable de Sede visualice el banner explicativo en su tarjeta de máquina y pueda responder en el hilo con un comentario público ($\ge 5$ caracteres) sin que el sistema altere el estado ni el reloj: el mensaje marca la respuesta y la parada queda con el aviso de respuesta pendiente (RF-05.6) hasta la reanudación explícita del técnico o del coordinador.
5. El Técnico y el Coordinador dispongan de la opción manual de *"Reanudar intervención"*.
6. Si la avería supera 72 horas hábiles de silencio, el Coordinador pueda cancelarla justificadamente sin que la máquina vuelva a figurar como "Operativa", desvinculando de forma segura los reintegros económicos pendientes y exigiendo confirmación de acceso en nuevos reportes (Art. V.1 y V.2).
7. El seguimiento ciudadano por código QR oculte totalmente el estado de pausa y motivos internos, mostrando un estado público neutro (Art. V.4).
8. Quede bloqueada cualquier transición directa desde `PENDING_INFO` hacia `RESOLVED` sin pasar por `IN_PROGRESS` (Art. V.1).
9. Se verifiquen al 100% todos los requisitos funcionales (RF-01 a RF-06) y no funcionales (RNF-01 a RNF-06) con pruebas automatizadas completas.

---

## 9. Dudas Abiertas

* **Ninguna duda abierta.** Todas las definiciones operativas, actores autorizados, causas tipificadas, cálculo de SLA comercial, doble reloj biológico con cuarentena automática (Art. II), límites de inactividad de 72 horas hábiles, preservación de estado de máquina y reintegros (Art. V.1) y visibilidad pública segregada han sido unánimemente acordadas, validadas y blindadas tras la auditoría QA.
