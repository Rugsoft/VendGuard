# ESPECIFICACIÓN FUNCIONAL: MODAL DE DETALLE INTEGRAL DE ÓRDENES DE MANTENIMIENTO PREVENTIVO

**Documento:** `specs/functional/preventive_order_detail_modal_spec.md`  
**Referencia de Módulo:** `specs/05-preventive-maintenance` (ampliación) · `specs/09-incident-detail-modal` (patrón espejo)  
**Estado:** Especificación Formal Consensuada y Aprobada  
**Fecha:** Octubre 2026  
**Autor:** Antigravity (Advanced Agentic Coding) & Coordinación de Operaciones  
**Proyecto:** VendGuard · Gestor de Incidencias de Vending  
**Alineación Constitucional:** Artículos I al VII de [constitution.md](../../constitution.md)  
**Referencias:** [`preventive_maintenance_spec.md`](preventive_maintenance_spec.md) (RF-PREV-01 a RF-PREV-08) · [`incident_detail_modal_spec.md`](incident_detail_modal_spec.md) (patrón de ficha integral)

---

## 1. Contexto y Objetivo

### 1.1. Contexto Operativo
La pestaña "Órdenes de Mantenimiento Preventivo" del panel de Coordinación ofrece hoy una visión tabular compacta (código de orden, máquina, sede, estado, tipo, fecha programada y límite, técnico asignado y dictamen) junto con las acciones rápidas "Asignar" y "Cancelar".

Esa tabla no permite auditar el contenido real de una inspección: el Coordinador no puede ver qué ítems del checklist normativo se respondieron, con qué severidad y observaciones, qué temperatura de sonda se registró, qué avería correctiva se abrió desde el preventivo (Art. V.2), qué certificado sanitario quedó vinculado (Art. V.4) ni la traza de auditoría inmutable de la orden (Art. III). Para responder a esas preguntas debe cambiar de sección o cruzar datos manualmente, lo que fragmenta el trabajo de supervisión higiénico-sanitaria.

La ficha de detalle integral de averías (módulo 09) ya resolvió exactamente este problema para el dominio correctivo. Este documento define su espejo para el dominio preventivo, **restringido estrictamente a consulta**.

### 1.2. Objetivo
Proporcionar una **Ficha Integral de Detalle de Orden Preventiva** accesible desde la tabla de órdenes de Coordinación mediante un modal ergonómico, reactivo y estrictamente no destructivo, que consolide en una única vista la totalidad de la información higiénico-sanitaria, técnica y documental de la orden, reutilizando los contratos y componentes ya consolidados para las averías.

---

## 2. Usuarios y Perfiles Implicados

* **Coordinador del Servicio (Perfil Exclusivo):**
  * Único usuario autorizado a abrir esta ficha (endpoint protegido por `InternalAuthMiddleware(COORDINATOR)`).
  * Dispone de visibilidad integral de: metadatos de sede y máquina con su semáforo sanitario (Art. II), vigencia de la inspección, técnico inspector con su Código de Operador Oficial (Art. V.4), checklist normativo respondido con severidades y evidencias, dictamen y temperatura, avería correctiva vinculada, certificado sanitario emitido y traza de auditoría.
  * **No dispone de ninguna acción de escritura dentro de la ficha** (ver §7, Fuera de Alcance): asignar, reprogramar y cancelar siguen ejecutándose desde los modales ya existentes en la fila de la tabla.

*(Nota: Los Responsables de Sede y los Técnicos de Ruta conservan sus interfaces específicas de portal y movilidad, quedando excluidos de este modal para preservar la segregación estricta de datos del Art. V.4.)*

---

## 3. Historias de Usuario

### HU-PD-01: Inspección Integral del Expediente Preventivo
> **Como** Coordinador del Servicio,  
> **quiero** abrir una ficha exhaustiva de cualquier orden preventiva desde un botón específico en la tabla de órdenes,  
> **para** evaluar en una única vista el contexto sanitario, la inspección ejecutada y su documentación sin perder el contexto de la bandeja general.

### HU-PD-02: Auditoría del Checklist Normativo
> **Como** Coordinador del Servicio,  
> **quiero** examinar cada ítem del checklist con su severidad (crítico o secundario), su estado, sus observaciones y su evidencia fotográfica,  
> **para** verificar que las inspecciones higiénicas se han ejecutado con rigor y detectar patrones de incumplimiento.

### HU-PD-03: Vigilancia de la Vigencia Sanitaria
> **Como** Coordinador del Servicio,  
> **quiero** ver de un vistazo la fecha límite, los días restantes y el semáforo sanitario de la orden y de la máquina,  
> **para** priorizar actuaciones sobre máquinas vencidas o en cuarentena (Art. II).

### HU-PD-04: Trazabilidad de la Coexistencia Correctiva (Art. V.2)
> **Como** Coordinador del Servicio,  
> **quiero** saber desde la propia orden si el preventivo derivó en una avería correctiva y poder saltar a su ficha de detalle completa,  
> **para** comprobar que no se generaron tickets duplicados y seguir la subsanación del fallo detectado.

### HU-PD-05: Auditoría Documental y de Inmutabilidad (Art. III y V.4)
> **Como** Coordinador del Servicio,  
> **quiero** consultar el certificado sanitario vinculado y la cronología de eventos de auditoría de la orden,  
> **para** acreditar la inspección ante terceros y garantizar que toda la traza histórica permanece intacta.

### HU-PD-06: Resiliencia ante Errores
> **Como** Coordinador del Servicio,  
> **quiero** que la ficha me avise con claridad si la orden no existe o si la carga falla, ofreciéndome reintentar,  
> **para** no quedarme con una ventana vacía sin saber qué ocurrió.

---

## 4. Requisitos Funcionales (Notación EARS)

### Módulo RF-PD-01: Disparador y Apertura del Modal
* **RF-PD-01.1 [EARS - Dirigido por eventos]:**  
  CUANDO el Coordinador pulse el botón "🔍 Ver detalle" (identificado con icono de lupa/inspección) en cualquier fila de la tabla de órdenes preventivas, el sistema DEBE abrir el modal de detalle cargando los datos de la orden seleccionada.
* **RF-PD-01.2 [EARS - Ubícuo]:**  
  El disparador DEBE estar presente en todas las filas, con independencia del estado de la orden, y DEBE permanecer plenamente independiente de los botones de acción rápida preexistentes ("Asignar", "Cancelar"), impidiendo que su pulsación abra el modal de forma involuntaria.
* **RF-PD-01.3 [EARS - Excepcional]:**  
  SI el identificador de la orden no existe, no es recuperable o la petición falla, el sistema DEBE mostrar un mensaje de error descriptivo con opción de reintento, manteniendo la tabla operativa.

### Módulo RF-PD-02: Cabecera de la Ficha
* **RF-PD-02.1 [EARS - Ubícuo]:**  
  El sistema DEBE mostrar en la cabecera el código único de la orden, la insignia semántica de estado, el tipo de orden, un botón de actualización manual de datos y **un único** botón de cierre.
* **RF-PD-02.2 [EARS - Ubícuo]:**  
  El sistema DEBE mantener fija la cabecera y el pie de acciones mientras el cuerpo central dispone de desplazamiento vertical independiente (RNF-03).

### Módulo RF-PD-03: Sede, Máquina y Vigencia Sanitaria
* **RF-PD-03.1 [EARS - Ubícuo]:**  
  El sistema DEBE presentar un bloque estructurado de sede y máquina con: nombre y código identificador de la sede, dirección, código y modelo de la máquina, tipología técnica, ubicación física (planta/ala) y estado sanitario de la máquina.
* **RF-PD-03.2 [EARS - Opcional]:**  
  DONDE la máquina almacene alimentos perecederos, el sistema DEBE mostrar el distintivo sanitario destacado con el límite innegociable de 15 días naturales entre inspecciones (Art. II).
* **RF-PD-03.3 [EARS - Ubícuo]:**  
  El sistema DEBE mostrar la fecha programada, la fecha límite de vigencia, los días restantes y un semáforo sanitario derivado en servidor con los estados: `VIGENTE`, `PROXIMA_A_VENCER` (5 días naturales o menos), `VENCIDA`, `CUARENTENA`, `PAUSA_ESTACIONAL` y `CERRADA` (órdenes completadas o canceladas, que muestran balance histórico en lugar de cuenta atrás).
* **RF-PD-03.4 [EARS - Estado]:**  
  MIENTRAS la orden esté cancelada lógicamente, el sistema DEBE mostrar su motivo de cancelación justificado sin ofrecer acciones de escritura (Art. III).

### Módulo RF-PD-04: Técnico Inspector
* **RF-PD-04.1 [EARS - Estado]:**  
  MIENTRAS la orden cuente con técnico asignado, el sistema DEBE mostrar su nombre profesional y su Código de Operador Técnico Oficial (Art. V.4).
* **RF-PD-04.2 [EARS - Estado]:**  
  MIENTRAS la orden esté pendiente de asignación, el sistema DEBE mostrar el estado "Pendiente de asignación técnica" con distintivo de advertencia visual.

### Módulo RF-PD-05: Checklist Normativo Respondido
* **RF-PD-05.1 [EARS - Estado]:**  
  MIENTRAS la orden disponga de respuestas de checklist registradas, el sistema DEBE listar cada ítem con su descripción, su severidad (crítico o secundario), su estado (`PASS`, `WARN`, `FAIL`, `NOT_APPLICABLE`) en español, sus observaciones del técnico y su evidencia fotográfica ampliable en visor integrado.
* **RF-PD-05.2 [EARS - Ubícuo]:**  
  El sistema DEBE presentar un resumen de cumplimiento con el total de ítems, los conformes, los avisos, los fallos, los no aplicables, el número de fallos críticos y el porcentaje de cumplimiento.
* **RF-PD-05.3 [EARS - Excepcional]:**  
  SI la orden no tiene checklist registrado (órdenes pendientes o programadas), el sistema DEBE mostrar un estado vacío explícito ("Sin checklist registrado"), sin romper la estructura de la ficha.

### Módulo RF-PD-06: Dictamen, Temperatura y Notas
* **RF-PD-06.1 [EARS - Estado]:**  
  MIENTRAS la orden esté completada, el sistema DEBE mostrar el dictamen traducido (`CONFORME`, `CONFORME_CON_OBSERVACIONES`, `NO_CONFORME`, `NO_EVALUABLE_POR_CAUSA_EXTERNA`), la temperatura de sonda medida en grados Celsius, el inicio y la finalización de la inspección y las notas del técnico.
* **RF-PD-06.2 [EARS - Estado]:**  
  MIENTRAS la orden tenga activada la cuarentena sanitaria (`is_quarantine_triggered`), el sistema DEBE mostrar una alerta crítica destacada con la referencia al Artículo II de la Constitución.

### Módulo RF-PD-07: Avería Correctiva Vinculada (Art. V.2)
* **RF-PD-07.1 [EARS - Opcional]:**  
  DONDE la orden preventiva haya derivado en una incidencia correctiva (`linked_incident_id`), el sistema DEBE mostrar un bloque con el código de ticket, su estado y su urgencia, junto a un botón "Ver ficha de avería".
* **RF-PD-07.2 [EARS - Dirigido por eventos]:**  
  CUANDO el Coordinador pulse "Ver ficha de avería", el sistema DEBE cerrar la ficha preventiva y abrir el modal de detalle integral de incidencias (módulo 09) con el ticket vinculado, reutilizando la ficha ya existente sin duplicarla.
* **RF-PD-07.3 [EARS - Excepcional]:**  
  SI la orden no tiene avería vinculada, el sistema DEBE ocultar el bloque de forma limpia, indicando que la inspección no generó correctivo.

### Módulo RF-PD-08: Certificado Sanitario Vinculado (Art. V.4)
* **RF-PD-08.1 [EARS - Opcional]:**  
  DONDE la máquina de la orden disponga de certificado sanitario emitido, el sistema DEBE mostrar su código de certificado, fecha de inspección, vigencia, temperatura registrada, dictamen y estado (`VALID`, `SUSPENDED`, `EXPIRED`), identificando al inspector por nombre y código de operador.
* **RF-PD-08.2 [EARS - Opcional]:**  
  DONDE la máquina no disponga de certificado emitido, el sistema DEBE mostrar un estado vacío explícito y **no** DEBE ofrecer la emisión ni la descarga del certificado desde esta ficha (fuera de alcance, §7).

### Módulo RF-PD-09: Trazabilidad de Auditoría (Art. III)
* **RF-PD-09.1 [EARS - Ubícuo]:**  
  El sistema DEBE listar cronológicamente los eventos de auditoría del ciclo preventivo de la orden con su acción traducida, marca temporal, usuario responsable y rol. La cronología se compone del recorrido de auditoría de la **máquina** de la orden (`entity_type = 'MACHINE'`, el camino que escriben los controladores y servicios reales del módulo), filtrado por las acciones preventivas y atribuido a la orden por el `order_code` almacenado en `metadata` (o por `metadata.order_id`); la composición exacta queda definida en [`specs/technical/preventive_order_detail_contracts.md`](../technical/preventive_order_detail_contracts.md) (§1.2, Decisión 12).
* **RF-PD-09.2 [EARS - Estado]:**  
  MIENTRAS no existan eventos registrados, el sistema DEBE mostrar un estado vacío explícito sin alterar el resto de la ficha.
* **RF-PD-09.3 [EARS - Ubícuo]:**  
  La visualización de la cronología DEBE ser estrictamente de lectura, sin posibilidad de edición, anulación o borrado (Art. III).

### Módulo RF-PD-10: Ciclo de Vida del Modal y Resiliencia
* **RF-PD-10.1 [EARS - Dirigido por eventos]:**  
  CUANDO el usuario pulse la tecla `Escape`, haga clic en el fondo sombreado exterior o pulse el botón de cierre, el sistema DEBE cerrar la ficha de inmediato (la ficha no contiene formularios, por lo que no procede guardián de borradores).
* **RF-PD-10.2 [EARS - Ubícuo]:**  
  Mientras la ficha esté abierta, el sistema DEBE bloquear el desplazamiento del documento de fondo y liberarlo al cerrarse.
* **RF-PD-10.3 [EARS - Excepcional]:**  
  SI la carga del detalle falla, el sistema DEBE mostrar el mensaje de error del servidor con un botón "Reintentar", conservando la ficha abierta.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-PD-01 (Rendimiento y Agilidad):**  
  La apertura y el renderizado de la ficha completa DEBE resolverse con **una única** petición agregada y completarse en menos de **300 milisegundos**, sin cascadas de peticiones por bloque.
* **RNF-PD-02 (Ergonomía Visual y Sistema de Diseño):**  
  La ficha DEBE respetar estrictamente los tokens visuales del sistema (azul corporativo `#2560ff`, fondo canvas `#f9fafb`, texto slate `#2c333f`, bordes hairline `#c8cfda`, radios 4px/8px) definidos en [`docs/design.md`](../../docs/design.md).
* **RNF-PD-03 (Desplazamiento Independiente y Usabilidad en Escritorio):**  
  La ficha DEBE diseñarse para pantallas de escritorio (ancho mínimo 1024px), con cabecera fija, pie de acciones fijo y área central con barra de desplazamiento vertical independiente.
* **RNF-PD-04 (Inmutabilidad y Lectura Pura - Art. III):**  
  La ficha es estrictamente de consulta: el endpoint de detalle NO DEBE ejecutar ninguna escritura (ni `INSERT`, ni `UPDATE`, ni `DELETE`) ni generar eventos de auditoría. Visualizar una orden no altera una sola fila.
* **RNF-PD-05 (Privacidad del Personal - Art. V.4):**  
  La ficha identifica al técnico exclusivamente por nombre profesional y Código de Operador Técnico Oficial, sin DNI ni datos de contacto particulares.
* **RNF-PD-06 (Ausencia de Modales Superpuestos y Reutilización):**  
  La ficha NO DEBE abrir capas modales secundarias: la evidencia fotográfica se amplía en un visor integrado y las acciones de escritura siguen residiendo en los modales ya existentes de la fila. El salto a la ficha de avería DEBE reutilizar el modal del módulo 09, no duplicarlo.
* **RNF-PD-07 (Autorización y Segregación de Datos):**  
  El endpoint de detalle DEBE exigir rol Coordinador; los roles Técnico y Responsable de Sede DEBEN recibir rechazo explícito (Art. V.4).

---

## 6. Casos Límite y Comportamiento ante Errores

1. **Orden pendiente de asignación:** la ficha muestra "Pendiente de asignación técnica", checklist vacío y sin certificado; nunca ofrece acciones de escritura.
2. **Orden vencida:** el semáforo se presenta en rojo con los días de retraso explícitos, sin alterar los datos históricos.
3. **Máquina en cuarentena sanitaria:** se destaca la alerta crítica y se indica la prohibición de consumo derivada del Art. II.
4. **Inspección no evaluable por causa externa:** el dictamen `NO_EVALUABLE_POR_CAUSA_EXTERNA` se muestra con su etiqueta propia y sin cuarentena.
5. **Preventivo que abrió un correctivo:** el bloque de avería vinculada ofrece el salto a su ficha de detalle completa; la ficha preventiva se cierra antes de abrir la correctiva.
6. **Preventivo que se incorporó a un ticket activo preexistente (Art. V.2):** la orden no tiene `linked_incident_id` propio; la ficha lo indica como "sin correctivo vinculado" sin inventar relaciones.
7. **Orden cancelada por traslado o baja de máquina:** se muestra el motivo justificado y ningún dato se elimina (Art. III).
8. **Máquina sin certificado emitido:** estado vacío explícito, sin ofrecer emisión desde la ficha.
9. **Evidencia fotográfica de un ítem rota o inaccesible:** recuadro de sustitución estético ("Evidencia gráfica no disponible") sin alterar la maquetación.
10. **Identificador inexistente:** el servidor responde 404 con mensaje claro y la ficha permanece cerrada mostrando el error con opción de reintento.

---

## 7. Fuera de Alcance

Quedan formalmente excluidos de esta especificación (Art. VI, Anti-Feature Creep):

1. **Cualquier acción de escritura dentro de la ficha:** asignar, reprogramar, cancelar, iniciar inspección, completar checklist o firmar. Todas ellas permanecen en los flujos existentes (modales de la fila y checklist del técnico).
2. **Emisión, descarga o impresión de certificados** desde esta ficha: el certificado se consulta, no se emite ni se exporta (los flujos A4 ya existen en el portal de sede).
3. **Acciones masivas o por lotes:** la ficha gestiona exclusivamente la orden seleccionada.
4. **Modificación de datos históricos:** queda prohibido alterar respuestas de checklist, temperaturas, dictámenes, sellos temporales o notas de inspección.
5. **Activación de la ficha para otros roles:** diseñada con exclusividad para el Coordinador del Servicio.
6. **Telemetría o mediciones automáticas:** la ficha solo muestra lo consignado por el técnico in situ.

---

## 8. Criterios de Finalización

La especificación se considerará cumplida cuando:

1. Cada fila de la tabla de órdenes preventivas del Coordinador disponga del botón "🔍 Ver detalle" que abra la ficha mediante una única petición y en menos de 300 ms.
2. La ficha presente con fidelidad los nueve bloques: cabecera, sede/máquina, vigencia sanitaria, técnico, checklist respondido con resumen de cumplimiento, dictamen/temperatura/notas, avería correctiva vinculada, certificado sanitario y trazabilidad de auditoría.
3. El salto "Ver ficha de avería" abra el modal de detalle de incidencias existente con el ticket vinculado.
4. La ficha no contenga ningún control de escritura y el endpoint de detalle no ejecute ninguna sentencia de escritura ni genere eventos de auditoría.
5. El cierre funcione con Escape, clic en el fondo y botón de cabecera, con **una sola** aspa de cierre, y los fallos de carga ofrezcan reintento.
6. Se verifique el cumplimiento íntegro de RF-PD-01 a RF-PD-10 y RNF-PD-01 a RNF-PD-07 sin vulnerar ningún artículo de la Constitución.

---

## 9. Dudas Abiertas

* **Ninguna duda abierta.** El alcance (solo consulta, cuatro bloques documentales adicionales y especificación dedicada) fue consensuado con el usuario antes de la implementación.
