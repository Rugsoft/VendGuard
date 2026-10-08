# ESPECIFICACIÓN FUNCIONAL: MODAL DE DETALLE INTEGRAL DE INCIDENCIAS EN TRIAJE Y OPERACIONES

**Documento:** `specs/functional/incident_detail_modal_spec.md`  
**Referencia de Módulo:** `specs/09-incident-detail-modal/spec.md`  
**Estado:** Especificación Formal Consensuada y Auditada (Post-QA)  
**Fecha:** Octubre 2026  
**Autor:** Antigravity (Advanced Agentic Coding) & Coordinación de Operaciones  
**Proyecto:** VendGuard · Gestor de Incidencias de Vending  
**Alineación Constitucional:** Artículos I al VII de [constitution.md](../../constitution.md)  

---

## 1. Contexto y Objetivo

### 1.1. Contexto Operativo
En la operativa diaria de VendGuard, el Coordinador de Operaciones supervisa la bandeja central de triaje, donde convergen averías reportadas por personal de sede, usuarios finales mediante códigos QR y avisos prioritarios de la cadena de frío.

Actualmente, la tabla de triaje proporciona una visión tabular compacta (código de máquina, sede, criticidad, técnico asignado y estado). Cuando el coordinador necesita evaluar el contexto profundo de una avería —como entender el motivo de una pausa por repuestos, auditar el coste de las piezas instaladas, verificar si existe dinero tragado reclamado por un ciudadano o analizar si el ticket fue reabierto tras una intervención fallida— debe recurrir a la consola de auditoría o cambiar de sección, fragmentando el flujo de trabajo y demorando la toma de decisiones en incidencias con riesgo de SLA.

### 1.2. Objetivo
Proporcionar una **Ficha Integral de Detalle Operativo** directamente accesible desde la bandeja de triaje mediante un modal ergonómico, reactivo y no destructivo. El modal consolida la totalidad de la información técnica, cronológica y económica del expediente en un único punto focal, e integra formularios en línea para ejecutar acciones operativas inmediatas (asignar, reasignar, descartar justificadamente y añadir notas o comentarios) con pleno respeto a la máquina de estados, el blindaje de datos personales y la auditabilidad inmutable.

---

## 2. Usuarios y Perfiles Implicados

* **Coordinador de Operaciones (Perfil Exclusivo):**
  * Es el único usuario con autorización para interactuar con este modal de detalle integral.
  * Dispone de visibilidad integral y sin restricciones de:
    * Metadatos completos de sede y máquina (ubicación, modelo, categoría sanitaria de perecederos).
    * Cronograma de hitos temporales y evaluación de SLA de frío (4 horas).
    * Identidad del técnico asignado, código de operador oficial y motivo de reasignación.
    * Justificación técnica, diagnóstico, desglose de piezas sustituidas (con costes unitarios congelados y destino reglamentario a *Desguace* o *Taller*).
    * Bitácora de comentarios públicos y notas internas confidenciales de taller.
    * Expediente de reintegro vinculado (con importes y estados, protegiendo datos sensibles).
  * Puede ejecutar acciones de triaje directo siempre que el ticket se encuentre en un estado operativo activo.

*(Nota: Los Responsables de Sede y los Técnicos de Ruta mantienen sus interfaces específicas de portal y movilidad, quedando excluidos de este modal para preservar la segregación estricta de datos del Art. V.4).*

---

## 3. Historias de Usuario

### HU-01: Inspección Integral del Expediente
> **Como** Coordinador de Operaciones,  
> **quiero** abrir una ficha exhaustiva de cualquier incidencia mediante un botón específico en la tabla de triaje,  
> **para** evaluar en una única vista todos los datos técnicos, temporales y económicos del expediente sin perder el contexto de la bandeja general.

### HU-02: Supervisión Rigurosa de SLA y Cadena de Frío
> **Como** Coordinador de Operaciones,  
> **quiero** examinar el cronograma de fases y el estado del objetivo de SLA (con cuenta atrás activa o balance histórico final si está resuelto),  
> **para** priorizar averías inminentes y auditar el cumplimiento del límite de 4 horas en alimentos perecederos.

### HU-03: Auditoría de Repuestos, Pausas y Costes de Intervención
> **Como** Coordinador de Operaciones,  
> **quiero** inspeccionar las piezas solicitadas durante una pausa técnica (del catálogo o fuera de catálogo justificada) y las piezas declaradas en la resolución con sus costes congelados y destino reglamentario,  
> **para** justificar los consumos de material y verificar la idoneidad del trabajo técnico.

### HU-04: Gestión Operativa Directa en Línea con Blindaje de Auditoría
> **Como** Coordinador de Operaciones,  
> **quiero** asignar un técnico, reasignar a otro operario con motivo registrado o descartar el aviso con una justificación $\ge 20$ caracteres directamente en paneles integrados en el modal,  
> **para** resolver el triaje con agilidad garantizando que toda acción quede registrada de forma inmutable en la bitácora de auditoría.

### HU-05: Protección de Datos y Supervisión de Reintegros Vinculados
> **Como** Coordinador de Operaciones,  
> **quiero** consultar si la avería incluye una solicitud de reintegro de dinero retenido (mostrando importes y estado, pero con datos bancarios y telefónicos enmascarados),  
> **para** seguir la liquidación económica protegiendo la privacidad del consumidor.

### HU-06: Resiliencia ante Errores y Cierres Accidentales
> **Como** Coordinador de Operaciones,  
> **quiero** que el modal me alerte antes de cerrarse si tengo un borrador en redacción y que retenga mi texto ante fallos de conexión,  
> **para** evitar la pérdida involuntaria de justificaciones técnicas extensas.

---

## 4. Requisitos Funcionales (Notación EARS)

### Módulo RF-01: Disparador y Apertura del Modal
* **RF-01.1 [EARS - Dirigido por eventos]:**  
  CUANDO el Coordinador pulse sobre el botón o enlace específico "Ver detalle" (identificado con icono de lupa/inspección) en cualquier fila de la tabla de triaje, el sistema DEBE abrir el modal de detalle integral cargando los datos del ticket seleccionado.
* **RF-01.2 [EARS - Ubicuo]:**  
  El sistema DEBE mantener plenamente operativos e independientes los botones de acción rápida preexistentes en la fila de la tabla ("Asignar", "Descartar"), impidiendo que su pulsación dispare la apertura involuntaria del modal.
* **RF-01.3 [EARS - Excepcional]:**  
  SI el identificador de la incidencia seleccionada no existe o no puede ser recuperado por el servidor, el sistema DEBE mostrar una notificación clara de error y mantener cerrado el modal.

---

### Módulo RF-02: Cabecera, Metadatos de Máquina/Sede y Reaperturas
* **RF-02.1 [EARS - Ubicuo]:**  
  El sistema DEBE mostrar en la cabecera del modal el código único de incidencia, la insignia semántica de estado actual, la insignia de urgencia/criticidad, un botón de actualización manual de datos y el botón de cierre.
* **RF-02.2 [EARS - Opcional]:**  
  DONDE la incidencia haya sido reabierta por la sede cliente dentro de la ventana de garantía de 48 horas (Art. V.6), el sistema DEBE mostrar en la cabecera un distintivo destacado de **"Reabierta en Garantía"**, la fecha de reapertura y el texto explicativo obligatorio aportado por el cliente.
* **RF-02.3 [EARS - Ubicuo]:**  
  El sistema DEBE presentar un bloque estructurado de ubicación y máquina que incluya:
    * Nombre de la sede cliente y código identificador de centro.
    * Ubicación física exacta (planta, ala, sala o zona).
    * Código identificativo de la máquina, modelo, fabricante y tipología técnica.
    * Indicador sanitario destacado si la máquina almacena alimentos perecederos (Art. II).
* **RF-02.4 [EARS - Ubicuo]:**  
  El sistema DEBE presentar la descripción original del problema emitida por el informador, el canal de reporte (Portal de Sede o Lectura QR Ciudadana) y la evidencia fotográfica adjunta en miniatura ampliable con visor integrado (si existe).

---

### Módulo RF-03: Cronograma de Ciclo de Vida y Evaluación de SLA
* **RF-03.1 [EARS - Ubicuo]:**  
  El sistema DEBE presentar una línea temporal ordenada que documente los hitos del ciclo de vida del ticket con fecha, hora y tiempos transcurridos:
    * Creación y registro del aviso.
    * Asignación técnica oficial.
    * Inicio de intervención física in situ (`started_at`).
    * Pausa estructurada por repuestos (`paused_at`, si aplica).
    * Resolución técnica documentada (`resolved_at`, si aplica).
    * Cierre definitivo formal (`closed_at`, si aplica).
* **RF-03.2 [EARS - Estado]:**  
  MIENTRAS la incidencia se encuentre activa sobre una máquina de alimentos perecederos, el sistema DEBE mostrar un monitor dinámico del SLA de 4 horas con indicación del tiempo restante o alerta visual destacada si el plazo ha sido superado (Art. II).
* **RF-03.3 [EARS - Estado]:**  
  MIENTRAS la incidencia se encuentre en un estado terminal o resuelto (`RESOLVED`, `CLOSED` o `CANCELLED`), el sistema DEBE sustituir la cuenta atrás por el balance histórico formal de cumplimiento (ej. *"Cumplido en 2 h 15 min"* o *"Incumplido por 35 min"*).

---

### Módulo RF-04: Bloque de Intervención Técnica, Repuestos y Costes
* **RF-04.1 [EARS - Estado]:**  
  MIENTRAS la incidencia cuente con técnico asignado, el sistema DEBE mostrar el nombre del profesional, su código de operador oficial y el momento exacto de asignación.
* **RF-04.2 [EARS - Estado]:**  
  MIENTRAS la incidencia esté en pausa técnica (`PENDING_PARTS`), el sistema DEBE mostrar el motivo de la pausa y el desglose de piezas solicitadas:
    * Si proceden del catálogo maestro: referencia, descripción y unidades solicitadas.
    * Si se trata de una pieza especial fuera de catálogo: descripción detallada y justificación técnica obligatoria ($\ge 20$ caracteres).
* **RF-04.3 [EARS - Estado]:**  
  MIENTRAS la incidencia esté resuelta o cerrada, el sistema DEBE mostrar:
    * Diagnóstico técnico obligatorio registrado por el técnico.
    * Acción correctiva documentada.
    * Desglose de piezas sustituidas declaradas, indicando referencia, descripción, unidades, coste unitario congelado (*snapshot* inmutable) y destino reglamentario (*Desguace* o *Taller*).
    * Coste total acumulado de materiales imputado a la reparación.
* **RF-04.4 [EARS - Estado]:**  
  MIENTRAS la incidencia esté cancelada o descartada, el sistema DEBE mostrar la fecha de descarte, el operador responsable que lo autorizó y la justificación obligatoria de descarte.

---

### Módulo RF-05: Bitácora de Comentarios y Notas Técnicas
* **RF-05.1 [EARS - Ubicuo]:**  
  El sistema DEBE listar cronológicamente todos los comentarios y evidencias asociados al ticket, diferenciando visualmente el autor (Responsable de Sede, Técnico de Campo, Coordinador o Sistema).
* **RF-05.2 [EARS - Ubicuo]:**  
  El sistema DEBE distinguir inequívocamente los comentarios públicos (visibles en el portal de sede) de las notas internas confidenciales de taller.
* **RF-05.3 [EARS - Dirigido por eventos]:**  
  CUANDO el Coordinador redacte un comentario en el formulario en línea del modal y seleccione su visibilidad (pública o nota interna), el sistema DEBE registrar el mensaje en la base de datos, generar el evento de auditoría en `audit_log`, refrescar la bitácora y limpiar el campo de texto sin cerrar el modal.

---

### Módulo RF-06: Expediente de Reintegro Vinculado y Privacidad
* **RF-06.1 [EARS - Opcional]:**  
  DONDE la incidencia tenga asociada una solicitud de reintegro de dinero retenido, el sistema DEBE mostrar una sección específica con:
    * Importe total reclamado por el usuario.
    * Método de compensación elegido (*En mano en sede*, *Bizum*, *Transferencia bancaria*).
    * Datos de contacto y financieros estrictamente enmascarados para preservar la privacidad (ej. teléfono `6** *** 789` o cuenta `ES** **** **** **** **12 3456`).
    * Estado del expediente de reembolso y dictamen de inspección técnica de saldo (si fue inspeccionado el monedero).
    * Enlace directo que permite abrir el expediente completo en la bandeja de Reintegros de Coordinación para trámites de liquidación telemática autorizados.
* **RF-06.2 [EARS - Opcional]:**  
  DONDE la incidencia no disponga de solicitud de reintegro, el sistema DEBE ocultar esta sección de forma limpia.

---

### Módulo RF-07: Acciones Operativas Directas en Línea y Blindaje Constitucional
* **RF-07.1 [EARS - Estado]:**  
  MIENTRAS la incidencia se encuentre en un estado operativo activo (`REPORTED`, `ASSIGNED`, `IN_PROGRESS` o `PENDING_PARTS`), el sistema DEBE habilitar las acciones operativas directas ("Asignar/Reasignar Técnico" y "Descartar Incidencia").
* **RF-07.2 [EARS - Estado]:**  
  MIENTRAS la incidencia se encuentre en un estado terminal o resuelto (`RESOLVED`, `CLOSED` o `CANCELLED`), el sistema DEBE inhabilitar las acciones de asignación, reasignación y descarte, presentando el modal en modo consulta y auditoría inmutable (permitiendo únicamente la adición de comentarios si el ticket sigue dentro del plazo de 48 horas de garantía).
* **RF-07.3 [EARS - Dirigido por eventos]:**  
  CUANDO el Coordinador active "Asignar Técnico" o "Reasignar Técnico", el sistema DEBE desplegar un panel integrado en línea dentro del propio modal (sin modales superpuestos) con el selector de técnicos activos y el campo de motivo obligatorio en caso de reasignación. Al confirmar, el sistema DEBE actualizar la asignación y registrar el evento inmutable en `audit_log`.
* **RF-07.4 [EARS - Dirigido por eventos]:**  
  CUANDO el Coordinador active "Descartar Incidencia", el sistema DEBE desplegar un panel integrado en línea exigiendo obligatoriamente un motivo de cancelación con una longitud mínima de **20 caracteres reales** (Art. III.2 y V.1), impidiendo el envío si no se alcanza dicho umbral. Al confirmar, el sistema DEBE ejecutar el descarte lógico (`CANCELLED`), registrar el evento en `audit_log` con el identificador del coordinador y actualizar la interfaz.

---

### Módulo RF-08: Ciclo de Vida del Modal y Prevención de Pérdida de Datos
* **RF-08.1 [EARS - Dirigido por eventos]:**  
  CUANDO el usuario pulse la tecla `Escape`, haga clic en el fondo sombreado exterior o pulse el botón de cierre, el sistema DEBE comprobar si existe algún formulario en edición activa con texto no guardado (comentario, motivo de descarte o reasignación).
* **RF-08.2 [EARS - Estado]:**  
  MIENTRAS existan textos en borrador sin guardar en el modal, el sistema DEBE solicitar confirmación explícita mediante diálogo de advertencia (*"¿Descartar cambios sin guardar?"*). Si el usuario confirma, el modal se cerrará; si cancela, el modal permanecerá abierto conservando íntegro el texto introducido.
* **RF-08.3 [EARS - Estado]:**  
  MIENTRAS no existan datos sin guardar, el sistema DEBE cerrar el modal inmediatamente al recibir cualquiera de las señales de cierre, devolviendo el foco a la tabla de triaje.
* **RF-08.4 [EARS - Excepcional]:**  
  SI se produce un fallo de red o un error del servidor (HTTP 4xx/5xx) al enviar una asignación, descarte o comentario, el sistema DEBE mostrar un mensaje descriptivo de error, mantener el modal abierto y **retener íntegro el texto redactado** para permitir su reintento inmediato sin pérdida de información.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Rendimiento y Agilidad):**  
  La apertura y renderizado del modal con la totalidad de sus bloques DEBE completarse en menos de **300 milisegundos**.
* **RNF-02 (Ergonomía Visual y Sistema de Diseño):**  
  El modal DEBE respetar estrictamente los tokens visuales del sistema (azul corporativo `#2560ff`, esquinas redondeadas 4px/8px, fondo canvas `#f9fafb`, alertas ámbar `#f8b60f` y crítico `#e02424`).
* **RNF-03 (Desplazamiento Independiente y Usabilidad en Escritorio):**  
  El modal DEBE diseñarse para pantallas de escritorio (ancho mínimo 1024px), contando con cabecera fija, pie de acciones fijo y un área central de contenido con barra de desplazamiento vertical independiente.
* **RNF-04 (Inmutabilidad y Auditoría Absoluta - Art. III):**  
  La visualización del modal es estrictamente no destructiva. Toda acción ejecutada desde sus paneles (asignación, reasignación, descarte o comentario) DEBE persistir un registro *Append-Only* en la tabla `audit_log` con marca temporal y usuario responsable.
* **RNF-05 (Minimización y Privacidad del Dato - Art. V.4):**  
  El acceso a los datos completos de costes, notas internas de taller y expedientes de reintegro queda restringido con exclusividad al rol de Coordinación. Los datos de pago mostrados en este modal DEBEN presentarse enmascarados.
* **RNF-06 (Prevención de Modales Superpuestos):**  
  Toda interacción operativa (asignación, descarte, notas) DEBE resolverse mediante componentes integrados en línea dentro del propio contenedor modal, eliminando la aparición de capas modales secundarias ("modal sobre modal").

---

## 6. Casos Límite y Comportamiento ante Errores

1. **Avería no asignada durante más de 60 minutos:**  
   El bloque de técnico muestra el estado "Pendiente de Asignación" con distintivo de advertencia visual destacado, situando en primer término el botón de asignación técnica inmediata.
2. **Incidencia reabierta por la sede cliente:**  
   Se destaca visualmente en la cabecera el aviso de reapertura y se añade al cronograma el hito de reapertura con la fecha, autor y motivo detallado aportado por el cliente.
3. **Pausa técnica con repuestos fuera de catálogo:**  
   Si el técnico solicitó una pieza no listada, el bloque de repuestos muestra su descripción y la justificación técnica reglamentaria ($\ge 20$ caracteres).
4. **Incidencia resuelta sin material sustituido:**  
   El bloque de resolución muestra el diagnóstico y la acción correctiva, indicando con claridad: *"Sin sustitución de repuestos (Intervención sin coste de material)"*.
5. **Incidencia cerrada hace más de 48 horas:**  
   El expediente se encuentra archivado y sellado; los formularios de asignación, descarte y adición de comentarios se deshabilitan por completo.
6. **Evidencia fotográfica rota o inaccesible:**  
   El modal presenta un recuadro de sustitución estético (*"Evidencia gráfica no disponible"*) sin alterar la estructura ni el diseño del modal.
7. **Actualización concurrente por un técnico:**  
   Si el técnico cambia el estado del ticket mientras el coordinador tiene el modal abierto, la acción manual de "Actualizar Datos" o el intento de enviar una acción sincroniza los datos del servidor e informa al coordinador del nuevo estado detectado.
8. **Volumen extenso de comentarios:**  
   La bitácora de mensajes cuenta con desplazamiento vertical propio y acotado, manteniendo visibles los comentarios más recientes y permitiendo consultar el histórico completo sin alargar desproporcionadamente la ventana modal.
9. **Fallo de red durante el envío:**  
   Si la petición falla, el modal no se cierra y los campos de entrada retienen el texto escrito, permitiendo al usuario reintentar el envío inmediatamente.

---

## 7. Fuera de Alcance

Quedan formalmente excluidos de esta especificación:
1. **Modificación directa de datos históricos originales:** Queda prohibido alterar el texto del reporte inicial del informador, transferir la incidencia a otra máquina a posteriori o modificar marcas temporales de auditoría.
2. **Acciones masivas o por lotes:** El modal gestiona exclusivamente el expediente seleccionado; las acciones sobre múltiples tickets se efectúan desde la tabla de triaje general y desde el flujo de consolidación por sede del mapa territorial (RF-MAP-09), nunca desde este modal.
3. **Exportación individual a PDF o impresión directa:** Los informes formales agregados y certificados ya disponen de plantillas A4 dedicadas; este modal no genera documentos PDF individuales.
4. **Activación del modal para otros roles:** Este modal integral está diseñado con exclusividad para el Coordinador de Operaciones. Los Responsables de Sede y los Técnicos conservan sus flujos desacoplados.

---

## 8. Criterios de Finalización

La especificación se considerará cumplida cuando:
1. Cada fila de la tabla de triaje del Coordinador disponga del botón "Ver detalle" que abra el modal en $< 300\text{ ms}$.
2. El modal presente con fidelidad los bloques de cabecera (con aviso de reapertura si aplica), datos de máquina/sede, cronograma con evaluación de SLA (activo o histórico), intervención técnica, desglose de repuestos (catálogo y fuera de catálogo con costes congelados), bitácora de comentarios y expediente de reintegro enmascarado.
3. Las acciones de asignar, reasignar, descartar (con motivo obligatorio $\ge 20$ caracteres) y comentar funcionen mediante formularios integrados en línea y registren sus eventos correspondientes en `audit_log`.
4. Las acciones operativas se inhabiliten automáticamente en tickets resueltos o cerrados.
5. El cierre por tecla ESC o clic exterior solicite confirmación si hay texto sin guardar, y cualquier error de red retenga el texto para reintento.
6. Se verifique el cumplimiento íntegro de RF-01 a RF-08 y RNF-01 a RNF-06 sin vulnerar ningún artículo de la Constitución.

---

## 9. Dudas Abiertas

* **Ninguna duda abierta.** Todas las ambigüedades, contradicciones, casos límite y restricciones constitucionales han sido formalmente identificadas, consensuadas y resueltas.
