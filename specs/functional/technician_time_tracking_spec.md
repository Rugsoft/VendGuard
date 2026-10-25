# ESPECIFICACIÓN FUNCIONAL: REGISTRO DE JORNADA Y CÓMPUTO DE HORAS EFECTIVAS (TECHNICIAN TIME TRACKING & WORK HOURS)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 22 · Registro de Jornada y Cómputo de Horas Efectivas  
**Documento Canónico:** `specs/22-technician-time-tracking/spec.md`  
**Versión:** 2.0 · Incorpora las 5 Resoluciones de Auditoría QA (15 hallazgos cerrados) y blindaje constitucional (2026-10-25)  
**Estado:** Especificación Funcional Validada y Auditada por QA · Lista para Contratos Técnicos y Plan (Fase 2 SDD)  
**Fecha:** Octubre 2026  
**Origen:** Feature 20 del catálogo de funcionalidades pendientes (Bloque 3 · Trazabilidad Avanzada y Eficiencia Operativa)  
**Alineación Constitucional:** Art. I.1 (supremacía de la especificación SDD), Art. II (prioridad sanitaria innegociable e inmutabilidad de marcas de frío), Art. III.1 y III.3 (prohibición de borrado físico y preservación de marcas originales en auditoría), Art. V.1 (validación obligatoria de motivos justificados $\ge 20$ caracteres reales), Art. V.4 (segregación estricta de datos laborales frente a sedes clientes y entre operarios), Art. VI.1 (autorización expresa de alcance).

---

## 1. Contexto, Autorización Formal y Objetivo

### 1.1. Autorización Formal del Alcance (Art. I.1 y Art. VI.1 · Resolución QA 5 / Hallazgo 15)
Habiéndose certificado y cerrado formalmente el alcance de la Fase 1 (MVP) de VendGuard, el Product Owner autoriza expresamente el diseño e implementación del **Módulo 22 · Registro de Jornada y Cómputo de Horas Efectivas**, correspondiente a la **Feature 20** del catálogo de evolución del proyecto. Conforme al Artículo I.1 de la Constitución, ninguna línea de código de producción se escribirá al margen de las reglas funcionales y criterios de aceptación EARS ratificados en este documento.

### 1.2. El Problema (Por qué)
En la operativa diaria de mantenimiento de parques de máquinas de vending, los técnicos de campo inician su jornada en ruta, realizan pausas de descanso o comida y cierran su turno al finalizar el servicio. Hasta ahora, estos cambios de estado de turno permitían conocer la disponibilidad en tiempo real de la flota para el triaje de averías y el relevo de fin de jornada, pero el servicio carecía de un motor de cómputo y registro histórico de horas efectivas trabajadas.

Esto generaba cuatro necesidades operativas y normativas sin cubrir:
1. **Cumplimiento del registro horario laboral:** Imposibilidad de obtener un informe auditable y exportable de las horas brutas, pausas de comida y horas efectivas netas trabajadas por cada técnico en un periodo.
2. **Pérdida de pausas múltiples o cierres desde comida:** Cuando un técnico realizaba más de una pausa dentro del mismo turno o cerraba su jornada directamente desde el estado de pausa de comida, era necesario acumular cada tramo de descanso con exactitud al segundo para no distorsionar las horas efectivas.
3. **Distorsión por olvidos de fichaje:** Si un técnico olvidaba cerrar su turno al acabar la tarde y Coordinación forzaba el cierre a la mañana siguiente, el cálculo literal entre apertura y cierre imputaba erróneamente toda la noche como tiempo trabajado, requiriendo un mecanismo supervisado y auditado de ajuste horario y alerta de turnos anómalos (`> 12 horas brutas`).
4. **Visibilidad de productividad en ruta vs. máquina:** Coordinación necesitaba contrastar cuántas de las horas efectivas de jornada correspondían a tiempo de intervención activa frente a las máquinas y cuántas a desplazamientos o espera en ruta, mientras que el propio técnico carecía de un indicador en su teléfono sobre sus horas acumuladas en el día y en el mes.

### 1.3. El Objetivo (Qué)
El **Módulo 22** dota a VendGuard de un sistema integral de **Registro de Jornada y Cómputo de Horas Efectivas** que:
* Registra de forma inmutable cada evento del ciclo de vida del turno (inicio, pausas de comida, reanudaciones, cierre normal, cierre forzado y rectificaciones administrativas), acumula la totalidad de los tiempos de pausa y preserva siempre las marcas originales del primer fichaje del técnico.
* Calcula con precisión las **Horas Brutas**, **Tiempo de Pausa de Comida**, **Horas Efectivas Netas**, **Tiempo en Intervención In Situ** y **Tiempo en Ruta / Tránsito** por sesión y por técnico.
* Permite a Coordinación indicar la hora real de salida en cierres forzados (sin alterar jamás las marcas del protocolo sanitario del Artículo II), detectar visualmente turnos anómalos (`> 12h` brutas) y turnos abiertos huérfanos de días previos, rectificar sesiones cerradas bajo validación estricta (permitiendo `0s` efectivos para anular fichajes accidentales sin borrado físico) y justificación obligatoria ($\ge 20$ caracteres reales), y exportar el registro horario en formato CSV.
* Ofrece al técnico de campo visibilidad transparente y de solo lectura sobre su propio contador diario y su resumen mensual («⏱️ Mis Horas»), distinguiendo entre `Cierre forzado` y `Ajustado por Coordinación` y preservando el aislamiento confidencial frente a otros compañeros y frente a las sedes clientes (Art. V.4).

### 1.4. Decisiones de Diseño y Resoluciones QA Consensuadas con el Product Owner
* **D1 (Trazabilidad de eventos y pausas acumulables):** Cada sesión conserva el acumulado total de pausa de comida y registra cada transición en un historial inmutable de eventos de turno. Se admiten múltiples pausas en un mismo turno y, si el turno se cierra directamente desde pausa de comida, el tramo final hasta el cierre se descuenta como pausa.
* **D2 (Cierre forzado con hora efectiva, rectificación auditada y alerta `> 12h`):** Coordinación puede indicar la hora efectiva de salida al forzar un cierre y puede rectificar turnos cerrados con motivo obligatorio ($\ge 20$ caracteres reales), conservando el valor anterior y el nuevo en auditoría. Los turnos cuya duración bruta supera las 12 horas se marcan como anómalos.
* **D3 (Cruce de presencia con actividad operativa):** El informe muestra Sesiones, Horas Brutas, Pausas de Comida, Horas Efectivas Netas, Tiempo en Intervención In Situ, Tiempo en Ruta / Tránsito e Intervenciones Resueltas.
* **D4 (Autoconsulta móvil del técnico):** El técnico ve en su barra de turno el tiempo efectivo acumulado hoy y dispone de la vista de solo lectura «⏱️ Mis Horas» para consultar sus sesiones del mes actual, incluyendo el aviso, motivo y marcas originales si Coordinación intervino algún turno.
* **D5 (Integración en Métricas y Auditoría + Exportación CSV):** Coordinación accede a la nueva sub-pestaña «⏱️ Registro de Jornada y Horas» dentro de la vista de Métricas y Auditoría, sincronizada con el selector global de fechas, filtro por técnico y exportación CSV con UTF-8 BOM.
* **C1 (Imputación al día de inicio):** Todo turno que cruce la medianoche se imputa íntegramente a la fecha en que se inició la sesión.
* **C2 (Turnos «En curso» incluidos en vivo):** Los turnos aún abiertos en el instante de la consulta se listan como `En curso` y aportan a los totales y al CSV el tiempo efectivo devengado hasta ese momento.
* **C3 (Suelo cero en Tiempo en Ruta y alerta de intervención fuera de turno):** Si el tiempo de intervención en máquina supera a las horas efectivas de turno fichadas, el Tiempo en Ruta se fija en `0` y se muestra un aviso de intervención registrada fuera de turno.
* **C4 (Validación cuádruple en rectificaciones):** Ningún ajuste puede dejar salida $\le$ entrada, fechas en el futuro, pausa negativa o $>$ duración bruta, duración bruta $> 24\text{ h}$ ni solapamiento con otro turno del mismo técnico.
* **C5 (Visibilidad de plantilla en tabla resumen):** La tabla resumen sin filtro de técnico lista a todos los técnicos activos (aunque tengan `0` sesiones en el periodo) más los técnicos inactivos que hayan registrado actividad en ese rango de fechas.
* **Resolución QA 1 (Hallazgos 1, 2 y 10 · Criterio de intervenciones, preventivos y traspasos):** El Tiempo en Intervención In Situ y el conteo de Intervenciones Resueltas se calculan sobre averías correctivas y órdenes preventivas **cerradas dentro del periodo filtrado** (`resolved_at` / `completed_at` en el rango), imputadas al **técnico resolutor**. En órdenes preventivas se suma `(completed_at - started_at)` siempre que existan ambas marcas y que la orden **no** tenga una avería vinculada derivada de la misma visita (evitando duplicar minutos).
* **Resolución QA 2 (Hallazgos 5, 8 y 14 · Cierre forzado con `effective_closed_at` y blindaje Art. II):** Un cierre forzado en tiempo real mantiene el motivo de $\ge 10$ caracteres, pero si incluye `effective_closed_at` retroactivo exige **$\ge 20$ caracteres reales** (Art. V.1). Además, `effective_closed_at` no puede ser anterior a ninguna marca previa del turno (inicio, inicio de última pausa ni fin de última pausa reanudada), la duración bruta resultante debe ser $\ge$ al tiempo de pausa ya acumulado, y **jamás** altera las marcas temporales reales (`ahora`) del protocolo sanitario de frío del Artículo II.
* **Resolución QA 3 (Hallazgos 3, 9, 12 y 13 · Fecha sincronizada, marcas originales del trabajador y anulación a `0s`):** Al rectificar la hora de entrada cambiando de día, la fecha de imputación de la sesión se actualiza a la nueva fecha de entrada. El turno conserva siempre inmutables las **marcas originales del primer fichaje del técnico** (Art. III.3) aunque sufra múltiples rectificaciones. Se permite que la pausa sea igual a la duración bruta (`tiempo efectivo = 0s`) para poder anular el cómputo de turnos abiertos por error sin borrado físico (Art. III.1).
* **Resolución QA 4 (Hallazgos 6, 7 y 11 · Umbral `> 12h` bruto, insignias diferenciadas y turnos abiertos huérfanos):** La alerta `⚠️ Duración anómala (> 12h)` se evalúa siempre sobre la **Duración Bruta** (`> 43.200 s`). Se distinguen dos insignias independientes: **`Cierre forzado`** (cuando Coordinación cerró en tiempo real sin cambiar horas) y **`Ajustado por Coordinación`** (cuando se rectificó un turno cerrado o se aplicó un `effective_closed_at` retroactivo). Cualquier turno abierto (`En curso`) iniciado antes del rango filtrado se destaca en un aviso de turnos abiertos huérfanos para que nunca quede oculto.
* **Resolución QA 5 (Hallazgos 4 y 15 · Estructura del CSV y cláusula SDD):** El archivo `.csv` se genera con UTF-8 BOM (`\xEF\xBB\xBF`), delimitador punto y coma (`;`), marcas horarias en zona horaria local operativa (`Europe/Madrid`, `YYYY-MM-DD HH:MM:SS`), y dos secciones separadas por una línea en blanco (*Sección 1: Resumen por Técnico* y *Sección 2: Desglose de Sesiones*).

---

## 2. Usuarios

| Rol | Relación con el Módulo | Reglas de Privacidad y Permisos |
| :--- | :--- | :--- |
| **📊 Coordinador de Operaciones** | Consulta el cuadro de mandos de horas trabajadas por periodo y técnico, audita el desglose de sesiones y alertas de turnos abiertos huérfanos, ejecuta cierres forzados (en tiempo real o indicando la hora real de salida), rectifica turnos cerrados con justificación obligatoria y exporta el registro horario a CSV. | Acceso completo a los registros horarios de toda la plantilla técnica. Toda rectificación o cierre forzado con hora efectiva exige motivo justificado de $\ge 20$ caracteres reales y deja huella inmutable preservando el fichaje original (Art. III.3 y Art. V.1). |
| **📱 Técnico de Campo** | Registra su entrada, pausas de comida y salida durante la ruta; visualiza en vivo el tiempo efectivo que lleva trabajado en el día actual y consulta en modo solo lectura su historial del mes en curso («⏱️ Mis Horas»). | **Aislamiento horizontal (Art. V.4):** Solo puede consultar sus propias sesiones y acumulados; jamás puede ver las horas de otros compañeros ni editar manualmente sus marcas temporales. |
| **🏢 Responsable de Sede** | Ninguna interacción con el módulo. | **Aislamiento total (Art. V.4):** Los horarios de turno, pausas, horas trabajadas y ajustes laborales son información interna estrictamente confidencial y nunca se exponen en el portal de sede ni en vistas públicas. |

---

## 3. Historias de Usuario

### HU-01: Cómputo exacto de múltiples pausas y cierres desde comida
> **Como** Técnico de Campo,  
> **quiero** que el sistema acumule correctamente todas las pausas de comida o descanso que realice durante mi turno (incluso si cierro la jornada directamente desde una pausa),  
> **para** que mis horas efectivas trabajadas reflejen con exactitud el tiempo real de servicio sin perder tramos previos.

### HU-02: Autoconsulta de horas del día y del mes en el móvil con transparencia de ajustes
> **Como** Técnico de Campo,  
> **quiero** ver en mi pantalla de ruta el tiempo efectivo que llevo trabajado hoy y poder abrir el resumen «⏱️ Mis Horas» de mi mes actual viendo mis marcas originales si Coordinación intervino algún turno,  
> **para** llevar el control de mi jornada diaria y distinguir con total transparencia cuándo un turno tuvo un cierre forzado o un ajuste horario justificado.

### HU-03: Supervisión de horas efectivas vs. tiempo en máquina y ruta
> **Como** Coordinador de Operaciones,  
> **quiero** consultar en el cuadro de mandos de métricas una sub-pestaña de «⏱️ Registro de Jornada y Horas» filtrable por periodo y técnico que compare las horas efectivas de turno con el tiempo real de intervención en máquinas y el tiempo en ruta,  
> **para** evaluar la carga laboral, detectar cuellos de botella en desplazamientos y verificar el cumplimiento de jornada de toda la plantilla.

### HU-04: Cierre forzado con hora efectiva de salida ante olvidos y aviso de turnos huérfanos
> **Como** Coordinador de Operaciones,  
> **quiero** ser alertado de cualquier turno abierto olvidado (incluso de días anteriores al filtro) y poder indicar la hora real de salida cuando fuerzo su cierre sin afectar a las alertas sanitarias en tiempo real,  
> **para** evitar que las horas nocturnas en las que el técnico ya estaba en su domicilio se sumen falsamente a su registro laboral.

### HU-05: Rectificación auditada de turnos cerrados y anulación de fichajes accidentales
> **Como** Coordinador de Operaciones,  
> **quiero** identificar visualmente cualquier turno cuya duración bruta supere las 12 horas y poder ajustar las marcas de entrada, salida o pausa de un turno cerrado (incluso dejándolo en `0` minutos efectivos si fue una pulsación por error) aportando una justificación obligatoria de al menos 20 caracteres,  
> **para** subsanar errores materiales de fichaje manteniendo inmutables tanto los valores originales del trabajador como la bitácora de cambios.

### HU-06: Exportación oficial del registro de jornada en CSV compatible con hojas de cálculo
> **Como** Coordinador de Operaciones,  
> **quiero** descargar en un clic un archivo CSV con UTF-8 BOM, separador `;` y dos secciones (resumen por técnico y detalle de sesiones en hora local),  
> **para** cumplir con las obligaciones legales de registro de jornada y abrirlo directamente en hojas de cálculo sin problemas de formato ni codificación.

---

## 4. Requisitos Funcionales (RF) y Criterios de Aceptación (EARS)

### RF-01 · Registro Inmutable de Eventos de Turno y Acumulación de Pausas
El sistema deberá registrar cada transición del turno en una bitácora histórica de eventos de jornada, preservar las marcas originales del primer fichaje y acumular con precisión al segundo todos los intervalos de pausa de comida dentro de una misma sesión.

* **Criterio EARS 01.1 (Bitácora inmutable de eventos):** **CUANDO** un técnico inicie su turno, inicie una pausa de comida, reanude su turno o cierre su jornada, o **CUANDO** un coordinador ejecute un cierre forzado o una rectificación horaria, **EL SISTEMA DEBERÁ** registrar un evento inmutable de jornada con el tipo de acción, el instante exacto del suceso, el usuario ejecutor, el estado previo y el estado resultante (Art. III.3).
* **Criterio EARS 01.2 (Acumulación de múltiples pausas):** **CUANDO** un técnico en estado de pausa de comida reanude su turno, **EL SISTEMA DEBERÁ** calcular los segundos transcurridos entre el inicio de dicha pausa y la reanudación, y sumarlos al acumulador total de pausa de comida de esa sesión sin sobrescribir ni perder pausas anteriores realizadas en el mismo turno.
* **Criterio EARS 01.3 (Descuento automático en cierre directo desde pausa de comida):** **SI** un turno que se encuentra en estado de pausa de comida es cerrado directamente (ya sea por el técnico o mediante cierre forzado de Coordinación), **ENTONCES EL SISTEMA DEBERÁ** sumar al acumulador de pausa de comida de esa sesión el intervalo transcurrido entre el inicio de la pausa activa y la hora efectiva de cierre, de modo que dicho tramo no compute como tiempo efectivo de trabajo.
* **Criterio EARS 01.4 (Imputación indivisible al día de inicio):** **CUANDO** una sesión de turno se inicie en una fecha natural y se cierre después de la medianoche (en el día o mes siguiente), **EL SISTEMA DEBERÁ** imputar la totalidad de la duración bruta, las pausas y las horas efectivas de esa sesión al día natural en que se inició el turno.

---

### RF-02 · Cierre Forzado con Hora Efectiva de Salida, Blindaje Sanitario Art. II y Alerta de Turno Anómalo (`> 12h`)
El sistema deberá permitir a Coordinación especificar la hora real de salida al forzar el cierre de un turno olvidado sin alterar los relojes sanitarios del Artículo II, y señalar las sesiones cuya duración bruta supere las 12 horas.

* **Criterio EARS 02.1 (Hora efectiva opcional y umbral de justificación — Resolución QA 2):** **CUANDO** un coordinador ejecute un cierre forzado sobre un turno abierto:
  * **SI** no indica una hora efectiva de salida retroactiva (cierre forzado en tiempo real), **EL SISTEMA DEBERÁ** exigir un motivo de al menos **10 caracteres reales**, aplicar el instante actual como hora de cierre y marcar la sesión con la insignia **`Cierre forzado`**.
  * **SI** indica una hora efectiva de salida (`effective_closed_at`), **EL SISTEMA DEBERÁ** exigir un motivo justificado de al menos **20 caracteres reales** (Art. V.1), aplicar dicha hora efectiva como instante de cierre del turno, conservar como cierre original el instante de ejecución y marcar la sesión con ambas insignias (**`Cierre forzado`** y **`Ajustado por Coordinación`**).
* **Criterio EARS 02.2 (Validación de coherencia con actividad y pausas previas — Resolución QA 2):** **SI** al ejecutar un cierre forzado con hora efectiva de salida el valor indicado es:
  1. Anterior o igual a la hora de inicio del turno, o posterior al instante actual;
  2. Anterior al inicio de la última pausa de comida o anterior al fin de la última pausa ya reanudada; o
  3. Tal que la duración bruta resultante (`hora efectiva - entrada`) sea inferior al tiempo de pausa de comida ya acumulado en la sesión;
  **ENTONCES EL SISTEMA DEBERÁ** rechazar la operación e informar de la incoherencia temporal sin cerrar el turno.
* **Criterio EARS 02.3 (Blindaje innegociable del Artículo II en cierre forzado — Resolución QA 2):** **CUANDO** un cierre forzado con hora efectiva retroactiva dispare el protocolo sanitario para máquinas de alimentos perecederos (traspaso automático a guardia 24/7 o desasignación con alarma crítica), **EL SISTEMA DEBERÁ** ejecutar y fechar todas las transiciones de las incidencias, notas internas y alertas sanitarias con el **instante actual real**, sin retrotraer jamás los relojes de SLA ni las marcas del protocolo de frío (Art. II).
* **Criterio EARS 02.4 (Detección de turno anómalo por Duración Bruta `> 12h` — Resolución QA 4):** **MIENTRAS** una sesión de turno (abierta o cerrada) presente una **Duración Bruta** superior a **12 horas** (`43.200 segundos`), **EL SISTEMA DEBERÁ** marcarla con el indicador de alerta *«⚠️ Duración anómala (> 12h)»* y contabilizarla en el contador de anomalías horarias del periodo.

---

### RF-03 · Rectificación Administrativa Auditada de Turnos Cerrados (Art. III.1, Art. III.3 y Art. V.1)
El sistema deberá permitir a Coordinación corregir las marcas horarias de una sesión ya cerrada cuando existan errores de fichaje, olvidos o pulsaciones accidentales, preservando siempre inmutables las marcas originales del primer fichaje del trabajador.

* **Criterio EARS 03.1 (Exclusividad sobre turnos cerrados):** **SI** un coordinador intenta ejecutar una rectificación horaria sobre un turno que aún permanece abierto (`En curso`), **ENTONCES EL SISTEMA DEBERÁ** rechazar la solicitud indicando que el turno debe cerrarse antes de poder ser rectificado.
* **Criterio EARS 03.2 (Justificación obligatoria de al menos 20 caracteres reales):** **SI** un coordinador solicita rectificar un turno cerrado aportando un motivo con menos de **20 caracteres reales** (descontando espacios iniciales, finales o cadenas compuestas solo por espacios), **ENTONCES EL SISTEMA DEBERÁ** rechazar la rectificación conforme al Artículo V.1 de la Constitución.
* **Criterio EARS 03.3 (Validación cuádruple y permisión de `0s` efectivos para anular errores — Resolución QA 3):** **SI** los nuevos valores de entrada, salida o pausa propuestos en la rectificación incumplen cualquiera de las siguientes cuatro reglas:
  1. La hora de salida debe ser estrictamente posterior a la hora de entrada y ninguna de las dos puede situarse en el futuro respecto al momento actual;
  2. El tiempo total de pausa de comida debe ser mayor o igual a cero y **menor o igual** que la duración bruta del turno (garantizando un tiempo efectivo neto $\ge 0\text{ segundos}$, lo que permite dejar en `0h 00m` efectivas un turno abierto por error accidental sin recurrir al borrado físico prohibido por el Art. III.1);
  3. La duración bruta total de la sesión (`salida - entrada`) no puede superar las **24 horas** (`86.400 segundos`);
  4. El nuevo intervalo `[entrada, salida]` no puede solaparse temporalmente con ninguna otra sesión de turno perteneciente al mismo técnico;
  **ENTONCES EL SISTEMA DEBERÁ** rechazar la operación e indicar la regla específica vulnerada sin alterar el registro.
* **Criterio EARS 03.4 (Sincronización de fecha de imputación y preservación del fichaje original — Resolución QA 3):** **CUANDO** una rectificación supere todas las validaciones, **EL SISTEMA DEBERÁ**:
  1. Actualizar las marcas vigentes del turno y recalcular su fecha natural de imputación según la nueva hora de entrada;
  2. Preservar inmutables las **marcas originales del primer fichaje del técnico** (entrada original, salida original y pausa original antes de cualquier intervención administrativa), incluso si el turno vuelve a ser rectificado posteriormente;
  3. Marcar la sesión con la insignia **`Ajustado por Coordinación`** y registrar en la bitácora del turno y en la auditoría general del sistema los valores previos, los valores nuevos, la identidad del coordinador y el motivo justificado (Art. III.3).

---

### RF-04 · Cómputo de Horas Efectivas y Desglose Operativo (Presencia vs. Máquina vs. Ruta)
El sistema deberá calcular para cada sesión y de forma agregada por técnico las métricas de presencia y su correlación con el tiempo dedicado a intervenciones cerradas en el periodo.

* **Criterio EARS 04.1 (Fórmula de jornada por sesión):** **CUANDO** el sistema calcule las métricas de una sesión de turno, **EL SISTEMA DEBERÁ** determinar en segundos enteros:
  * **Duración Bruta:** Diferencia entre la hora de cierre (o el instante actual si el turno sigue `En curso`) y la hora de inicio.
  * **Tiempo de Pausa de Comida:** Suma de los segundos de pausa completados más, si el turno está actualmente en estado de pausa de comida, el tramo transcurrido desde el inicio de la pausa activa hasta el instante actual.
  * **Horas Efectivas Netas:** Resultado de restar el **Tiempo de Pausa de Comida** a la **Duración Bruta** (con suelo mínimo en `0 segundos`).
* **Criterio EARS 04.2 (Inclusión de turnos «En curso» y detección de turnos abiertos huérfanos — Resolución QA 4):** **MIENTRAS** un turno iniciado dentro del periodo consultado permanezca abierto, **EL SISTEMA DEBERÁ** incluirlo en el listado de sesiones con la etiqueta `En curso` y sumar su tiempo devengado hasta el instante de la consulta en los totales del periodo. **SI** además existiera algún turno abierto (`En curso`) cuya fecha de inicio sea anterior al inicio del rango filtrado, **ENTONCES EL SISTEMA DEBERÁ** exponerlo en un aviso destacado de **«Turnos abiertos pendientes de cierre de fechas anteriores»** para que Coordinación pueda cerrarlo sin que quede oculto fuera del filtro.
* **Criterio EARS 04.3 (Cruce con Tiempo en Intervención In Situ y Tiempo en Ruta — Resolución QA 1):** **CUANDO** el sistema genere el resumen por técnico para un periodo, **EL SISTEMA DEBERÁ** calcular:
  * **Tiempo en Intervención In Situ:** Suma del tiempo neto invertido en las intervenciones **cerradas dentro del periodo filtrado** y atribuidas al **técnico resolutor**, compuesto por:
    1. Averías correctivas resueltas en el periodo (`fecha de resolución - fecha de inicio de intervención`, descontando los segundos de pausas técnicas o de falta de acceso); más
    2. Órdenes preventivas completadas en el periodo (`fecha de completado - fecha de inicio de inspección`) cuando ambas marcas existan y siempre que la orden preventiva **no** tenga una avería correctiva vinculada derivada de la misma visita (evitando el doble cómputo de minutos sobre la misma máquina).
  * **Tiempo en Ruta / Tránsito:** Diferencia entre las **Horas Efectivas Netas** de jornada y el **Tiempo en Intervención In Situ**, aplicando un suelo estricto en cero: $\text{Tiempo en Ruta} = \max(0, \text{Horas Efectivas Netas} - \text{Tiempo en Intervención})$.
  * **Intervenciones Resueltas:** Suma de averías correctivas resueltas más órdenes preventivas completadas por el técnico dentro del periodo filtrado.
* **Criterio EARS 04.4 (Detección de intervenciones fuera de turno):** **SI** el **Tiempo en Intervención In Situ** de un técnico en el periodo es mayor que `0` y supera a sus **Horas Efectivas Netas** de turno fichadas (por ejemplo, al atender una emergencia de guardia 24/7 sin abrir turno ordinario), **ENTONCES EL SISTEMA DEBERÁ** preservar íntegro el Tiempo en Intervención real, fijar el Tiempo en Ruta en `0` y mostrar el indicador *«⚠️ Intervención registrada fuera de turno»*.

---

### RF-05 · Sub-pestaña de Supervisión en el Cuadro de Mandos de Coordinación
El sistema deberá proporcionar a Coordinación una superficie analítica y de gestión dentro del módulo de Métricas y Auditoría para supervisar las horas de la plantilla.

* **Criterio EARS 05.1 (Sincronización con filtros globales y por técnico):** **CUANDO** el coordinador acceda a la sub-pestaña **«⏱️ Registro de Jornada y Horas»** dentro de Métricas y Auditoría o modifique el periodo temporal global (*Últimos 7 días*, *Últimos 30 días*, *Mes actual*, *Mes anterior* o *Rango personalizado*) o el filtro opcional de técnico, **EL SISTEMA DEBERÁ** actualizar simultáneamente las tarjetas de resumen del periodo, el aviso de turnos huérfanos abiertos (si los hubiera), la tabla agregada por técnico y el listado detallado de sesiones de turno.
* **Criterio EARS 05.2 (Cobertura de plantilla en la tabla resumen):** **CUANDO** se consulte el resumen del periodo sin filtrar por un técnico concreto, **EL SISTEMA DEBERÁ** listar a **todos los técnicos activos en plantilla** (incluyendo aquellos con `0` sesiones en el periodo para evidenciar ausencias de fichaje) y a **los técnicos inactivos que hayan registrado al menos un turno o una intervención cerrada dentro del rango consultado** (identificados con la etiqueta `Inactivo`).
* **Criterio EARS 05.3 (Detalle de sesiones, marcas originales y acciones operativas — Resoluciones QA 3 y QA 4):** **CUANDO** el coordinador consulte el listado de sesiones de turno del periodo, **EL SISTEMA DEBERÁ** mostrar para cada sesión la fecha de imputación, el técnico, la hora de entrada, la hora de salida (o `En curso`), el tiempo de comida, las horas efectivas netas, el estado, las insignias diferenciadas (**`⚠️ > 12h`**, **`Cierre forzado`**, **`Ajustado por Coordinación`**), las marcas originales del trabajador cuando haya sido ajustado y las acciones contextuales para **«✏️ Ajustar Turno»** (si está cerrado), **«🛑 Forzar Cierre»** (si está abierto) y **«📜 Ver Bitácora»** de eventos de la sesión.

---

### RF-06 · Exportación de Registro de Jornada en Formato CSV (UTF-8 BOM)
El sistema deberá permitir exportar el informe horario del periodo a un archivo CSV estructurado y listo para auditorías laborales y hojas de cálculo.

* **Criterio EARS 06.1 (Codificación, delimitador y zona horaria — Resolución QA 5):** **CUANDO** el coordinador pulse **«📥 Exportar Jornada CSV»**, **EL SISTEMA DEBERÁ** generar y descargar un archivo `.csv` precedido por la secuencia UTF-8 BOM (`\xEF\xBB\xBF`), utilizando el carácter punto y coma (`;`) como delimitador de columnas y formateando todas las fechas y horas en la zona horaria operativa local (`Europe/Madrid`, formato `YYYY-MM-DD HH:MM:SS`), respetando el periodo y el filtro de técnico activos.
* **Criterio EARS 06.2 (Estructura en dos secciones — Resolución QA 5):** **CUANDO** se genere el archivo CSV de jornada, **EL SISTEMA DEBERÁ** estructurar su contenido en dos bloques separados por una fila en blanco:
  1. **Sección 1 (Resumen por Técnico):** Cabecera y filas con Técnico, Estado en Plantilla, Sesiones, Horas Brutas, Tiempo Comida, Horas Efectivas Netas, Tiempo en Intervención, Tiempo en Ruta, Intervenciones Resueltas, Turnos Anómalos (`> 12h`) e Indicador de Intervención Fuera de Turno.
  2. **Sección 2 (Desglose Cronológico de Sesiones):** Cabecera y filas con ID de Sesión, Fecha Imputación, Técnico, Entrada Vigente, Salida Vigente (o `En curso`), Duración Bruta, Pausa Comida, Horas Efectivas Netas, Estado, Cierre Forzado (`SÍ`/`NO`), Ajustado por Coordinación (`SÍ`/`NO`), Entrada Original Fichada, Salida Original Fichada y Motivo de Cierre/Ajuste.

---

### RF-07 · Visibilidad de Horas Propias en la Vista Móvil del Técnico
El sistema deberá mostrar al técnico de campo en su interfaz móvil el tiempo efectivo acumulado en el día actual y el resumen de sus propias sesiones del mes en curso.

* **Criterio EARS 07.1 (Contador diario en la barra de turno móvil):** **MIENTRAS** el técnico visualice su pantalla de ruta móvil, **EL SISTEMA DEBERÁ** mostrar en el bloque de estado de jornada el tiempo efectivo acumulado en el día natural de hoy (sumando los turnos iniciados y cerrados hoy más el tiempo efectivo transcurrido del turno actual si está abierto) y el tiempo total de pausa de comida del día.
* **Criterio EARS 07.2 (Resumen mensual «⏱️ Mis Horas» de solo lectura):** **CUANDO** el técnico pulse el acceso **«⏱️ Mis Horas»** en su vista móvil, **EL SISTEMA DEBERÁ** desplegar un panel de solo lectura con sus totales del mes en curso (sesiones, horas brutas, tiempo de comida y horas efectivas netas) y el listado cronológico de sus propios turnos del mes.
* **Criterio EARS 07.3 (Transparencia diferenciada de cierres forzados y ajustes — Resoluciones QA 3 y QA 4):** **SI** alguna de las sesiones del técnico fue cerrada por Coordinación en tiempo real, **ENTONCES EL SISTEMA DEBERÁ** mostrar la insignia **`Cierre forzado`** con su motivo; y **SI** la sesión sufrió una modificación de sus marcas horarias (por rectificación de turno cerrado o por `effective_closed_at` en cierre forzado), **ENTONCES EL SISTEMA DEBERÁ** mostrar la insignia **`Ajustado por Coordinación`**, las **marcas originales del primer fichaje del técnico** y el motivo justificado de la última rectificación.

---

### RF-08 · Privacidad y Segregación Estricta de Datos Laborales (Art. V.4)
El sistema deberá blindar la confidencialidad de los registros de presencia, pausas y ajustes horarios frente a terceros y entre compañeros.

* **Criterio EARS 08.1 (Aislamiento horizontal entre técnicos):** **CUANDO** un usuario con rol Técnico de Campo consulte el estado o historial de horas trabajadas, **EL SISTEMA DEBERÁ** devolver exclusivamente los registros asociados a su propia identidad autenticada, bloqueando cualquier intento de consultar sesiones de otros técnicos.
* **Criterio EARS 08.2 (Opacidad total frente al Portal de Sede y vistas públicas):** **MIENTRAS** un Responsable de Sede o un usuario anónimo interactúe con el Portal de Sede, el seguimiento de avisos o el escaneo QR, **EL SISTEMA DEBERÁ** omitir por completo cualquier dato relativo a turnos, horarios de entrada/salida, pausas de comida, horas efectivas o motivos de ajuste laboral (Art. V.4).

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Precisión de Cómputo al Segundo y Formato Legible):** Todos los cálculos internos de duración bruta, pausas de comida, horas efectivas, tiempo en intervención y tiempo en ruta deberán realizarse en **segundos enteros** para evitar errores de redondeo acumulativo, presentándose en la interfaz y en los reportes en formato legible (`HHh MMm`) además del valor en segundos/horas decimales.
* **RNF-02 (Inviolabilidad de la Auditoría y de las Marcas Originales — Art. III.1 y III.3):** Ninguna sesión de turno ni evento de la bitácora horaria podrá eliminarse físicamente. Toda rectificación administrativa conservará inmutables tanto las marcas originales del primer fichaje del trabajador como la secuencia histórica de cada cambio en la bitácora y en el registro de auditoría.
* **RNF-03 (Rendimiento de Consulta Analítica):** El cálculo del informe mensual de horas y su desglose para la plantilla técnica deberá resolverse en un tiempo de respuesta $\le 200\text{ ms}$ en condiciones normales de operación, sin provocar bloqueos en el despacho de incidencias.
* **RNF-04 (Ergonomía Móvil y Cero Fricción Operativa):** La consulta de «⏱️ Mis Horas» y el contador diario en el móvil del técnico serán puramente informativos y nunca bloquearán ni añadirán pasos adicionales al flujo de inicio, pausa, relevo o resolución de averías.
* **RNF-05 (Fidelidad al Sistema de Diseño e Idioma):** La interfaz respetará la estética técnica del sistema (bordes conservadores, jerarquía tipográfica clara, paleta corporativa e insignias semánticas) y presentará todos los textos, etiquetas, cabeceras de CSV y mensajes de validación íntegramente en castellano.

---

## 6. Casos Límite Contemplados

1. **Turno nocturno que cruza la medianoche o el fin de mes (ej. 31 de octubre 22:30 -> 1 de noviembre 01:30):**
   * No se fragmenta la sesión en dos días distintos. Las 3 horas se imputan íntegramente al día de apertura (31 de octubre). Si posteriormente Coordinación rectifica la entrada de un turno de las `00:10` del día 1 a las `23:50` del día 31, la fecha de imputación de la sesión se actualiza automáticamente al día 31.
2. **Múltiples pausas de comida dentro de la misma sesión de turno:**
   * Si el técnico para 20 minutos a media mañana, reanuda, y vuelve a parar 40 minutos al mediodía, el acumulador suma `1.200 + 2.400 = 3.600 segundos` (1h 00m) sin que la segunda pausa borre la primera.
3. **Cierre directo desde pausa de comida (sin pulsar «Reanudar»):**
   * Si el técnico inicia turno a las 08:00, entra en pausa de comida a las 14:00 y a las 15:00 decide cerrar turno directamente desde la pausa, el tramo `14:00 -> 15:00` se contabiliza como pausa de comida (1h) y las horas efectivas netas quedan en `6h 00m` (`08:00 -> 14:00`), nunca en `7h`.
4. **Olvido de cierre de turno, turno huérfano de días previos y cierre forzado con hora efectiva:**
   * Si el técnico abrió turno el lunes a las 08:00, terminó a las 17:00 sin fichar salida, y Coordinación ejecuta el cierre forzado el martes a las 09:00 indicando como hora efectiva el lunes a las 17:00 (con motivo $\ge 20$ caracteres), el turno queda cerrado con `9h` brutas (menos comida) en lugar de `25h`, conservando el martes 09:00 como marca original de cierre forzado y el lunes 17:00 como salida vigente ajustada, sin retrotraer jamás las alertas sanitarias del Art. II. Si el turno olvidado era de una semana anterior al filtro actual, aparece en el aviso de turnos abiertos huérfanos hasta que se cierre.
5. **Anulación a `0s` efectivos de un turno abierto por pulsación accidental:**
   * Si un técnico abrió turno por error en su día libre y lo cerró (o Coordinación lo rectifica), Coordinación puede igualar el tiempo de pausa a la duración bruta dejando `0h 00m` efectivas sin borrar físicamente la fila (Art. III.1).
6. **Rectificaciones múltiples sobre la misma sesión:**
   * Si Coordinación ajusta un turno por primera vez y luego corrige de nuevo la hora de salida, las **marcas originales del primer fichaje del técnico** permanecen intactas y visibles junto al valor vigente, quedando ambos ajustes registrados paso a paso en la bitácora de la sesión.
7. **Orden preventiva que deriva en avería oportunista in situ:**
   * Si durante una inspección preventiva el técnico detecta un fallo que genera una avería vinculada sobre la misma máquina, el tiempo de reparación se contabiliza únicamente en la avería correctiva vinculada para no duplicar minutos de intervención.
8. **Intervención de urgencia en guardia 24/7 sin abrir turno ordinario:**
   * Si un técnico de guardia resuelve una emergencia de frío invirtiendo `45m` en la máquina sin haber pulsado «Iniciar Turno» (`0h` de turno fichado), el sistema muestra `0h 00m` de Horas Efectivas de turno, `0h 45m` de Tiempo en Intervención, fija el Tiempo en Ruta en `0h 00m` (nunca `-45m`) y muestra el aviso `⚠️ Intervención registrada fuera de turno`.
9. **Intento de rectificación que solapa con otro turno del mismo día (turno partido):**
   * Si un técnico tuvo un turno de mañana (`08:00 -> 14:00`) y otro de tarde (`16:00 -> 20:00`), y Coordinación intenta ajustar la salida del turno de mañana a las `17:00`, el sistema detecta la colisión con el turno de tarde (`16:00 -> 20:00`) y rechaza el ajuste informando del solapamiento horario.
10. **Técnico activo sin fichajes vs. técnico dado de baja a mitad de mes:**
    * En la tabla resumen del mes, el técnico activo sin fichajes aparece con `0 sesiones / 0h 00m` para que Coordinación detecte su inactividad, mientras que un técnico desactivado el día 20 que trabajó del 1 al 15 sigue apareciendo en el informe de ese mes con sus horas reales y etiqueta `Inactivo`.

---

## 7. Fuera de Alcance (Exclusiones Explícitas / Anti-Feature Creep)

Quedan expresamente excluidos de este módulo:
1. **Cálculo de nóminas, tarifas monetarias o compensación de horas extra:** No se configuran sueldos, convenios colectivos, bolsas financieras de horas ni importes económicos por hora trabajada.
2. **Geolocalización o Geofencing en el fichaje:** No se capturan coordenadas GPS al iniciar, pausar o cerrar turno (respetando la privacidad del trabajador y el alcance del sistema).
3. **Autoedición de turnos por el técnico:** El técnico de campo no puede crear turnos retroactivos ni editar sus propias horas de entrada, pausa o salida desde el móvil; toda rectificación requiere intervención y firma auditada de Coordinación.
4. **Creación automática de turnos al intervenir máquinas:** Iniciar o resolver una avería estando fuera de turno no abre ni inventa automáticamente un fichaje de jornada.
5. **Bloqueo operativo por límites de jornada:** El contador de horas es una herramienta de registro y supervisión; jamás bloquea la atención de una avería en curso ni impide aplicar el protocolo sanitario del Artículo II ante emergencias de cadena de frío.

---

## 8. Criterios de Finalización

La especificación se considerará implementada y cumplida cuando se verifiquen todos los puntos siguientes:
- [ ] Cada acción de inicio, pausa de comida, reanudación, cierre normal, cierre forzado y ajuste deja un registro inmutable en la bitácora de eventos del turno y en la auditoría del sistema (`RF-01`, `RNF-02`).
- [ ] Un turno soporta múltiples pausas de comida acumulando la totalidad de los segundos descansados, y descuenta automáticamente el último tramo si se cierra directamente desde pausa de comida (`RF-01.2`, `RF-01.3`).
- [ ] Los turnos que cruzan la medianoche se imputan íntegramente a la fecha de inicio de la sesión, y si una rectificación cambia el día de entrada, actualiza su fecha de imputación (`RF-01.4`, `RF-03.4`).
- [ ] El cierre forzado admite indicar una hora efectiva de salida (con motivo $\ge 20$ caracteres reales, coherencia frente a pausas previas y blindaje del reloj real para el protocolo sanitario del Art. II), señala cualquier turno con duración bruta $> 12\text{ h}$ y expone los turnos abiertos huérfanos de fechas anteriores (`RF-02`, `RF-04.2`).
- [ ] Coordinación puede rectificar turnos cerrados bajo validación cuádruple (coherencia temporal sin futuro, pausa $\le$ duración bruta permitiendo `0s` efectivos, tope de 24h y sin solapamiento con otros turnos del mismo técnico) exigiendo motivo de $\ge 20$ caracteres reales y preservando siempre inmutables las marcas originales del primer fichaje del técnico (`RF-03`).
- [ ] El cálculo por técnico reporta Sesiones, Horas Brutas, Pausa de Comida, Horas Efectivas Netas, Tiempo en Intervención In Situ (averías resueltas + preventivos completados sin avería vinculada en el periodo atribuidos al técnico resolutor), Tiempo en Ruta (con suelo en `0` y alerta si hay intervenciones fuera de turno) e Intervenciones Resueltas, incluyendo el tiempo devengado de los turnos `En curso` (`RF-04`).
- [ ] La sub-pestaña «⏱️ Registro de Jornada y Horas» en Métricas y Auditoría responde al selector de periodo global y filtro de técnico, lista a los técnicos activos más los inactivos con actividad en el rango, distingue las insignias `Cierre forzado` y `Ajustado por Coordinación`, y exporta el informe en dos secciones dentro de un CSV con UTF-8 BOM, separador `;` y hora local `Europe/Madrid` (`RF-05`, `RF-06`).
- [ ] El técnico visualiza en su móvil el contador efectivo del día actual y la consulta de solo lectura «⏱️ Mis Horas» del mes en curso con transparencia de insignias, motivos y marcas originales, sin poder ver datos de otros compañeros (`RF-07`, `RF-08.1`).
- [ ] El Portal de Sede y las vistas públicas mantienen opacidad absoluta sobre cualquier dato de turnos u horas trabajadas conforme al Artículo V.4 (`RF-08.2`).

---

## 9. Dudas Abiertas (`[NECESITA ACLARACIÓN]`)

* **Estado de clarificación:** Todas las decisiones de diseño (`D1` a `D5`), casos límite iniciales (`C1` a `C5`) y las 5 Resoluciones de Auditoría QA (`QA 1` a `QA 5`, que cierran los 15 hallazgos de revisión) han sido ratificadas por el Product Owner.
* **Puntos `[NECESITA ACLARACIÓN]` pendientes:** **Ninguno (0).** La especificación funcional v2.0 queda blindada y aprobada para pasar a los Contratos Técnicos y al Plan de Implementación.
