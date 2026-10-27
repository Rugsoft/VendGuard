# ESPECIFICACIÓN FUNCIONAL: ÓRDENES DE CARGA DE FURGONETA Y CRUCE DE STOCK EN TRIAJE

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 23 · Órdenes de Carga de Furgoneta y Cruce de Stock en Triaje  
**Versión:** 2.1 · Incorpora las 5 Resoluciones de Auditoría QA (13 hallazgos cerrados), el blindaje constitucional (2026-10-26) y la alineación literal de las etiquetas de triaje con el contrato aprobado (2026-10-27; sin cambios de comportamiento)  
**Estado:** Especificación Funcional Validada y Auditada por QA · Lista para Contratos Técnicos y Plan (Fase 2 SDD)  
**Fecha:** Octubre 2026  
**Origen:** Funcionalidad 9 del catálogo de evolución del producto (Fase 2 de Inventario de Furgoneta + Recomendador Territorial de Triaje)  
**Alineación Constitucional:** Art. I.1 (supremacía de la especificación aprobada), Art. II (prioridad sanitaria innegociable en averías de cadena de frío), Art. III.1 y III.3 (prohibición de borrado físico en órdenes y líneas, e inmutabilidad de auditoría), Art. V.1 (justificación obligatoria de al menos 20 caracteres reales en cancelaciones y descartes del candidato recomendado), Art. V.4 (segregación estricta de datos internos y costes frente a sedes clientes y en hojas de carga), Art. VI.1 (autorización expresa de alcance y enmienda normativa de módulos previos).

---

## 1. Contexto y Objetivo

### 1.1. Autorización Formal del Alcance y Enmienda Normativa (Art. I.1 y Art. VI.1)
Habiéndose cerrado y certificado formalmente la Fase 1 (MVP) y la Fase 1 del inventario de furgoneta, el Product Owner autoriza expresamente el alcance del **Módulo 23 · Órdenes de Carga de Furgoneta y Cruce de Stock en Triaje**, levantando la exclusión previa sobre hojas de reposición de furgoneta exclusivamente para cubrir el flujo de preparación, despacho desde almacén central, recepción por el técnico de ruta y cruce informativo de disponibilidad de piezas durante el triaje de averías.

### 1.2. El Problema (Por qué)
Aunque el servicio ya permite registrar cuántas unidades de cada repuesto lleva cada furgoneta, descontar automáticamente las piezas instaladas en intervenciones, alertar cuando una referencia toca su umbral mínimo y sugerir técnicos por zona territorial, en la operativa diaria subsisten dos brechas importantes:

1. **Reposición matutina manual y desconectada de las averías pausadas por falta de pieza:**
   * Cada mañana, Coordinación puede ver las alertas de stock bajo o los saldos negativos por descuadre, pero no dispone de un mecanismo para generar una orden de carga formal que agrupe lo que el almacén central debe preparar para cada furgoneta.
   * Además, si un técnico tiene averías activas con solicitudes pendientes de un repuesto concreto, el coordinador debe cruzar de memoria esa demanda con las alertas de umbral para que al operario de almacén no se le olvide meter esa pieza en la caja matutina, y luego actualizar a mano el stock del vehículo pieza por pieza.
2. **Triaje de averías a ciegas respecto al stock real del vehículo:**
   * Al asignar una avería desde Coordinación, el recomendador ordena a los candidatos por afinidad territorial, presencia en sede, carga de trabajo y guardia sanitaria, pero el coordinador no sabe si el técnico sugerido lleva realmente en su furgoneta repuestos compatibles con el modelo de la máquina afectada y la familia del fallo reportado (o las piezas específicas que la avería ya tenía solicitadas).
   * Esto provoca que se envíen técnicos sin repuestos compatibles a averías que acaban pausadas en espera de pieza, obligando a realizar una segunda visita e incrementando el tiempo medio de resolución.

### 1.3. El Objetivo (Qué)
Dotar al servicio de un sistema de **Órdenes de Carga de Furgoneta y Cruce de Stock en Triaje** que permita:
1. Calcular automáticamente (de forma individual o en lote para toda la flota disponible) las necesidades de reposición de cada furgoneta combinando el déficit respecto al umbral mínimo y las piezas catalogadas solicitadas en averías activas de ese técnico.
2. Gestionar el ciclo de vida de la orden de carga en tres etapas operativas (**En preparación de almacén**, **Despachada** y **Confirmada por el técnico**, más **Cancelada** con justificación obligatoria), distinguiendo entre la cantidad solicitada y la cantidad realmente despachada por almacén, preservando mediante baja lógica cualquier línea retirada antes del despacho (Art. III.1), e imprimiendo una **Hoja de Carga Matutina en formato A4** libre de importes económicos.
3. Permitir al técnico confirmar con un solo toque desde su dispositivo móvil la recepción del lote despachado (con independencia de su estado de turno), conservar visible el comprobante de su última carga confirmada, y señalizar en sus averías activas con solicitudes de repuesto cuándo cuentan ya con las piezas necesarias en su vehículo (distinguiendo si el stock es exclusivo o compartido entre varias averías).
4. Enriquecer de forma informativa la tarjeta de cada candidato en el recomendador de triaje (en tres grados: completo, parcial o sin stock) cruzando el inventario de su furgoneta con las piezas solicitadas en la avería o con el modelo de máquina y la categoría del fallo, sin alterar jamás la puntuación territorial ni la prioridad sanitaria del Artículo II.

### 1.4. Decisiones de Alcance, Casos Límite y Resoluciones QA Consensuadas con el Product Owner
* **D1 (Ciclo de 3 estados + Cancelación y doble cantidad):** La orden nace en *En preparación de almacén*, pasa a *Despachada* cuando almacén entrega el lote (registrando las unidades efectivamente despachadas frente a las solicitadas) y finaliza en *Confirmada por el técnico* cuando el operario acepta la recepción en su móvil (pudiendo pasar a *Cancelada* antes de la confirmación final mediante justificación de al menos 20 caracteres reales).
* **D2 (Cruce informativo en 2 niveles en el triaje sin alterar puntos):** La puntuación de idoneidad territorial/operativa y el escalado sanitario (Art. II) permanecen inalterados. El recomendador añade un indicador informativo que evalúa primero las piezas catalogadas ya solicitadas en la avería (Nivel 1) y, si no las hay, las piezas compatibles con el modelo de la máquina y la familia del fallo (Nivel 2).
* **D3 (Máximo 1 orden abierta por técnico y confirmación móvil en 1 toque):** Cada técnico solo puede tener una orden activa (*En preparación* o *Despachada*) a la vez para evitar duplicar pedidos matutinos. El técnico confirma la recepción del lote despachado en 1 toque sin editar cantidades por su cuenta.
* **D4 (Pre-cálculo unificado con solicitudes de averías, lote sin ausentes y Hoja A4 sin precios):** La cantidad sugerida une el déficit de umbral con la demanda de averías activas con solicitud de pieza asignadas al técnico. La generación en lote excluye a técnicos con ausencia vigente o con orden abierta. La Hoja de Carga A4 omite cualquier coste o precio unitario (Art. V.4).
* **C1 (Tres grados de cobertura también por familia de fallo):** En averías nuevas (Nivel 2), el indicador distingue entre *Stock compatible (N/N ref.)* cuando el técnico lleva todas las referencias compatibles de esa familia para ese modelo, *Stock compatible parcial (M/N ref.)* cuando lleva al menos una pero no todas, y *Sin stock compatible (0/N ref.)* cuando no lleva ninguna.
* **C2 (Ausencia o baja sobrevenida con orden abierta):** Si un técnico entra en ausencia o baja mientras tiene una orden *En preparación* o *Despachada*, la orden no se cancela en silencio ni se traspasa a ciegas a otro compañero; se marca con una alerta visual (*«⚠️ Destinatario actualmente ausente / inactivo»*) para que Coordinación verifique la devolución física de la caja a estantería y la cancele con motivo justificado.
* **C3 (Piezas descatalogadas en tránsito y tope de `9.999` unidades):** No se permite añadir piezas inactivas al crear o editar una orden, pero si una pieza se descataloga mientras la orden ya estaba emitida/despachada, el técnico sí puede confirmar su recepción. Si al confirmar la recepción la suma superase el techo máximo de `9.999` unidades en alguna pieza, la operación se rechaza sin recortar cantidades en silencio.
* **C4 (Furgonetas sin déficit automático y dotación manual):** En la generación en lote se omiten los técnicos sin déficit; en la creación individual para un técnico sin déficit se informa que no hay alertas automáticas pero se permite a Coordinación añadir piezas activas del catálogo manualmente (exigiendo siempre al menos 1 línea activa válida para guardar la orden).
* **C5 (Sin reactivación prematura en el parking + insignia de desbloqueo):** Confirmar la orden de carga incrementa el stock de la furgoneta pero no cambia automáticamente el estado de las averías pausadas. En su lugar, las averías activas con solicitudes pendientes cubiertas por el stock actual de la furgoneta muestran la insignia de repuesto disponible en furgoneta.
* **Resolución QA 1 (Hallazgos 5, 6 y 12 · Baja lógica de líneas retiradas Art. III.1 y tope matemático unificado):** Cuando Coordinación retira una pieza al editar una orden *En preparación de almacén*, la línea **nunca se borra físicamente** (Art. III.1), sino que se marca con **baja lógica** (quedando excluida de la Hoja A4, del despacho y de la suma de stock, y reactivándose si esa misma pieza se vuelve a añadir a la orden). En toda línea activa rige la doble cota: cantidad entre `1` y `9.999` (o `0` y `9.999` al despachar) y saldo final proyectado $\text{disponible actual} + \text{cantidad} \le 9.999$.
* **Resolución QA 2 (Hallazgos 1, 9 y 13 · Semántica de `M/N ref.` en Nivel 1, solicitudes mixtas fuera de catálogo y regla de justificación Art. V.1):** En el Nivel 1 del triaje, `N` es el número de **referencias catalogadas distintas** solicitadas en la avería y `M` es cuántas de ellas están cubiertas al 100 % ($\text{disponible} \ge \text{solicitada}$). Si la avería tiene además alguna solicitud pendiente de **pieza fuera de catálogo**, la etiqueta del triaje añade la advertencia *«(+ pieza fuera de catálogo)»* y la avería no se marca como totalmente cubierta en furgoneta hasta que todas sus solicitudes pendientes estén cubiertas. El indicador de stock es informativo y no añade nuevas exigencias de justificación cuando se asigna al candidato `#1` recomendado (rigiendo la justificación de $\ge 20$ caracteres del Art. V.1 únicamente al descartar al `#1`).
* **Resolución QA 3 (Hallazgos 2, 7 y 8 · Fórmula aditiva en motivo «Ambos», alcance de la insignia en averías activas y aviso de stock compartido):**
  1. Cuando una pieza tiene alerta de umbral ($\text{disponible} \le \text{umbral}$) **y además** solicitudes pendientes en averías activas del técnico ($\text{pedidas en averías} > 0$), o cuando atender las averías dejaría el saldo $\le \text{umbral}$, el motivo se clasifica como **«Ambos»** y la cantidad sugerida suma el colchón de umbral más las averías: $(\text{umbral} + 1 + \text{pedidas en averías}) - \text{disponible}$ (acotada a $\min(9999, 9999 - \text{disponible})$), para que la furgoneta no vuelva a caer en alerta el mismo día tras instalar la pieza.
  2. La insignia de cobertura en ruta se evalúa sobre cualquier avería activa del técnico que tenga solicitudes pendientes de repuesto (tanto en *Pendiente de Repuesto* como si fue pausada temporalmente en *Pendiente de Información*).
  3. Si el stock de la furgoneta cubre las unidades pedidas por esa avería individual ($\text{disponible} \ge \text{solicitada en esa avería}$), pero no alcanza para cubrir simultáneamente la suma de todas las averías activas de ese técnico que piden esa misma pieza ($\text{disponible} < \text{total solicitado en todas sus averías}$), muestra **«📦 Repuesto en furgoneta (stock compartido)»**.
* **Resolución QA 4 (Hallazgos 10 y 11 · Bloqueo de técnicos inactivos, permiso con aviso en ausentes e independencia del estado de turno):** Se prohíbe crear órdenes de carga (individuales o en lote) para técnicos inactivos. Si el técnico está activo pero tiene una ausencia vigente hoy, se le excluye en la generación en lote pero se permite crearle una orden individual mostrando la advertencia *«⚠️ Destinatario actualmente ausente»* (para preparar la caja antes de su reincorporación). El técnico puede confirmar la recepción de su orden *Despachada* desde el móvil con independencia de si está *En turno*, en *Pausa de comida* o *Fuera de turno*.
* **Resolución QA 5 (Hallazgos 3 y 4 · Ordenación determinista por prioridad operativa y comprobante móvil de última recepción):** Las líneas de la propuesta, de la orden y de la Hoja A4 se ordenan por prioridad de motivo (*«Ambos»* $\rightarrow$ *«Solicitada en avería»* $\rightarrow$ *«Stock bajo umbral»* $\rightarrow$ *«Añadida manualmente»*), luego por menor saldo disponible (descuadres negativos primero) y por código de pieza ascendente. En Coordinación, las órdenes abiertas se listan antes que las cerradas por fecha descendente. En el móvil del técnico, además del aviso de orden *Despachada* pendiente, se muestra en modo lectura el comprobante de su **última orden confirmada** (código, fecha/hora de recepción y piezas recibidas).

---

## 2. Usuarios

| Usuario / Rol | Relación con el Módulo | Reglas de Privacidad y Permisos (Art. V.4) |
| :--- | :--- | :--- |
| **📊 Coordinador de Operaciones** | Consulta la propuesta de reposición por furgoneta, genera órdenes individuales o en lote, edita órdenes en preparación (con baja lógica de líneas retiradas), imprime la Hoja de Carga A4, registra el despacho de almacén indicando las unidades reales servidas, cancela órdenes abiertas con justificación ($\ge 20$ caracteres) y consulta el cruce de stock en el triaje de averías. | Acceso completo a las órdenes de carga y al estado de stock de toda la plantilla técnica. Toda acción de creación, edición, despacho o cancelación queda registrada en la auditoría del sistema (Art. III.3). |
| **📱 Técnico de Campo / Ruta** | Consulta desde su vista móvil su orden de carga cuando ha sido despachada por almacén, confirma su recepción en un solo toque (con independencia de su estado de turno y con observación opcional), consulta el comprobante de su última orden confirmada, y visualiza en sus paradas con solicitud de pieza cuándo dispone ya del repuesto en su furgoneta (exclusivo o compartido). | **Aislamiento horizontal (Art. V.4):** Solo puede ver y confirmar órdenes destinadas a sí mismo. No tiene permiso para crear órdenes, alterar las cantidades solicitadas o despachadas, ni visualizar costes o precios de repuestos. |
| **🏢 Responsable de Sede / Ciudadano** | Ninguna interacción con el módulo. | **Aislamiento total (Art. V.4):** Ningún dato sobre órdenes de carga, carencia de repuestos en furgoneta, umbrales o recomendaciones de triaje es visible en el portal de la sede cliente ni en el seguimiento público por código QR. |

---

## 3. Historias de Usuario

### HU-01: Propuesta automática de reposición combinando umbrales y averías con solicitud de pieza
> **Como** Coordinador de Operaciones,  
> **quiero** obtener automáticamente la propuesta de reposición de una furgoneta (o generar en lote las órdenes de toda la plantilla disponible con déficit) sumando tanto el colchón sobre el umbral mínimo/descuadre como las piezas catalogadas solicitadas en averías activas asignadas a ese técnico,  
> **para** preparar cada mañana las cajas de almacén sin que la furgoneta vuelva a caer en alerta tras reparar la avería ni generar órdenes en lote para técnicos ausentes.

### HU-02: Hoja de Carga Matutina en A4 ordenada por urgencia y Despacho con cantidades reales
> **Como** Coordinador de Operaciones,  
> **quiero** imprimir en papel A4 limpio la hoja de preparación de cada orden ordenada por prioridad operativa (sin importes económicos, con casillas de verificación física y recuadros de firma) y, al entregar la caja en almacén, marcarla como despachada confirmando las unidades reales servidas de cada referencia,  
> **para** preparar primero las piezas críticas y reflejar con exactitud si de alguna referencia puntual se sirvieron menos unidades por falta de existencias en central.

### HU-03: Recepción móvil en un toque, comprobante de última carga e identificación de averías desbloqueadas
> **Como** Técnico de Campo,  
> **quiero** ver un aviso claro en mi pantalla de ruta cuando almacén me ha despachado una orden de carga, confirmar su recepción con un solo toque incluso antes de iniciar mi turno, conservar visible el comprobante de mi última recepción confirmada y ver señalizadas mis averías con piezas disponibles en furgoneta (distinguiendo si el stock es compartido entre varias averías),  
> **para** actualizar mi inventario al instante en el mostrador de almacén y saber qué paradas puedo resolver hoy.

### HU-04: Alerta de destinatario ausente y cancelación auditada de órdenes abiertas
> **Como** Coordinador de Operaciones,  
> **quiero** ver una alerta destacada si el técnico destinatario de una orden abierta se encuentra ausente, y poder cancelar cualquier orden aún no confirmada aportando un motivo justificado de al menos 20 caracteres,  
> **para** asegurar que la caja física sea devuelta a la estantería de almacén antes de anular la orden y evitar pérdidas de material.

### HU-05: Cruce informativo de stock compatible durante el triaje de averías
> **Como** Coordinador de Operaciones,  
> **quiero** ver en la tarjeta de cada técnico candidato durante el triaje si dispone en su furgoneta de las referencias exactas solicitadas por la avería (advirtiendo si además requiere pieza fuera de catálogo) o de los repuestos compatibles con el modelo de máquina y la familia del fallo (completo, parcial o sin stock),  
> **para** elegir con mayor acierto quién puede resolver la avería en la primera visita sin alterar la puntuación territorial ni la prioridad sanitaria del Artículo II.

---

## 4. Requisitos Funcionales (RF) y Criterios de Aceptación (EARS)

### RF-01 · Ciclo de Vida, Baja Lógica de Líneas y Reglas de Integridad de las Órdenes de Carga
El sistema deberá gestionar órdenes de carga hacia las furgonetas identificadas por un código único legible, un técnico destinatario activo, un estado dentro del ciclo (**En preparación de almacén**, **Despachada**, **Confirmada por el técnico**, **Cancelada**), notas opcionales, trazabilidad de usuarios/fechas y un listado de líneas de repuestos con motivo de inclusión, cantidad solicitada, cantidad despachada y marca de vigencia lógica.

* **Criterio EARS 01.1 (Unicidad de orden abierta por técnico — Decisión D3):** **MIENTRAS** un técnico tenga una orden de carga en estado *En preparación de almacén* o *Despachada*, **EL SISTEMA DEBERÁ** rechazar cualquier intento de crear una nueva orden de carga para ese mismo técnico, informando del código de la orden abierta vigente para que Coordinación la edite o la cancele antes de emitir otra.
* **Criterio EARS 01.2 (Bloqueo de técnicos inactivos y aviso en ausentes — Resolución QA 4):**
  * **SI** Coordinación intenta crear una orden de carga para un técnico cuya cuenta se encuentra **inactiva**, **ENTONCES EL SISTEMA DEBERÁ** rechazar la operación.
  * **SI** Coordinación crea una orden **individual** para un técnico activo que tiene una ausencia vigente en ese instante, **ENTONCES EL SISTEMA DEBERÁ** permitir la creación mostrando de inmediato la alerta visual *«⚠️ Destinatario actualmente ausente»* para facilitar la preparación anticipada antes de su reincorporación.
* **Criterio EARS 01.3 (Validación de líneas activas y tope matemático doble — Resolución QA 1):** **SI** al crear o editar una orden en estado *En preparación de almacén* la orden no contiene al menos una línea activa, contiene la misma pieza activa repetida, incluye una pieza inactiva en el catálogo maestro, declara una cantidad solicitada fuera del rango entero entre `1` y `9.999`, o produce un saldo proyectado ($\text{saldo disponible actual} + \text{cantidad solicitada}$) superior a `9.999` unidades, **ENTONCES EL SISTEMA DEBERÁ** rechazar la operación e informar de la pieza y causa exacta sin guardar cambios.
* **Criterio EARS 01.4 (Prohibición de borrado físico en órdenes y líneas — Art. III.1 y Resolución QA 1):** **EL SISTEMA DEBERÁ** conservar de forma permanente todas las órdenes de carga y todas sus líneas en el historial operativo, impidiendo cualquier eliminación física de registros. Cuando Coordinación retire una línea durante la edición de una orden en estado *En preparación de almacén*, el sistema deberá aplicarle una **baja lógica** (excluyéndola de la Hoja A4, del despacho y del incremento de stock, y reactivando ese mismo registro si la pieza vuelve a añadirse a la misma orden).

---

### RF-02 · Cálculo Automático de Propuesta de Reposición, Ordenación y Emisión Individual o en Lote
El sistema deberá calcular las necesidades de reposición de cada furgoneta cruzando su inventario actual con sus umbrales mínimos y con las solicitudes de piezas de sus averías activas.

* **Criterio EARS 02.1 (Fórmula de cantidad sugerida y motivo de línea — Decisión D4 y Resoluciones QA 1 y QA 3):** **CUANDO** Coordinación solicite la propuesta automática de carga para un técnico activo, **EL SISTEMA DEBERÁ** evaluar todas las piezas activas del catálogo maestro, sumar las unidades catalogadas con solicitud pendiente en las averías activas asignadas a ese técnico ($\text{pedidas en averías}$), y clasificar e incluir aquellas que cumplan cualquiera de estos tres casos:
  1. **Motivo «Stock bajo umbral»:** Cuando la pieza no tiene solicitudes pendientes en averías ($\text{pedidas en averías} = 0$) pero su cantidad disponible en furgoneta es menor o igual que su umbral mínimo ($\text{disponible} \le \text{umbral}$), sugiriendo:
     $$\text{sugerido\_bruto} = (\text{umbral} - \text{disponible}) + 1$$
  2. **Motivo «Solicitada en avería»:** Cuando la pieza está por encima de su umbral ($\text{disponible} > \text{umbral}$), tiene solicitudes pendientes ($\text{pedidas en averías} > 0$) que superan el saldo disponible ($\text{pedidas en averías} > \text{disponible}$) y su umbral configurado es `0` (sin umbral de reserva), sugiriendo:
     $$\text{sugerido\_bruto} = \text{pedidas en averías} - \max(0, \text{disponible})$$
  3. **Motivo «Ambos»:** Cuando la pieza tiene solicitudes pendientes en averías ($\text{pedidas en averías} > 0$) y, o bien ya está en alerta de umbral ($\text{disponible} \le \text{umbral}$), o bien tiene umbral configurado ($\text{umbral} > 0$) y restar las unidades pedidas dejaría la furgoneta en alerta ($\text{disponible} - \text{pedidas en averías} \le \text{umbral}$), sugiriendo la cantidad necesaria para cubrir las averías y dejar el stock una unidad por encima del umbral:
     $$\text{sugerido\_bruto} = (\text{umbral} + 1 + \text{pedidas en averías}) - \text{disponible}$$
  En todos los casos, la cantidad sugerida final se acotará al tope de capacidad del vehículo:
  $$\text{cantidad sugerida} = \min\big(9999,\; 9999 - \text{disponible},\; \text{sugerido\_bruto}\big)$$
* **Criterio EARS 02.2 (Ordenación determinista de líneas y órdenes — Resolución QA 5):** **EL SISTEMA DEBERÁ** ordenar las líneas activas de toda propuesta, orden de carga y Hoja de Carga A4 siguiendo esta prelación estricta:
  1. Prioridad del motivo: *«Ambos»* $\rightarrow$ *«Solicitada en avería»* $\rightarrow$ *«Stock bajo umbral»* $\rightarrow$ *«Añadida manualmente»*;
  2. Menor saldo disponible actual en la furgoneta (de modo que los saldos negativos por descuadre encabecen su bloque);
  3. Código de pieza en orden alfabético ascendente.
  Asimismo, en el panel de Coordinación las órdenes abiertas (*En preparación de almacén* y *Despachadas*) se listarán siempre por delante de las órdenes cerradas (*Confirmadas* y *Canceladas*), ordenadas por fecha de creación descendente.
* **Criterio EARS 02.3 (Comportamiento en furgonetas sin déficit automático — Caso límite C4):** **SI** al consultar la propuesta individual para un técnico el cálculo automático no detecta ninguna pieza con necesidad de reposición, **ENTONCES EL SISTEMA DEBERÁ** mostrar un aviso indicando que la furgoneta tiene su stock cubierto y permitir igualmente a Coordinación añadir manualmente piezas activas del catálogo (con motivo *«Añadida manualmente»*) para emitir una orden preventiva o de dotación inicial (exigiendo al menos una línea activa válida para guardar).
* **Criterio EARS 02.4 (Generación en lote excluyendo ausentes, sin déficit y con orden abierta — Decisiones D4, C4 y Resolución QA 4):** **CUANDO** Coordinación ejecute la generación masiva en lote de órdenes matutinas, **EL SISTEMA DEBERÁ**:
  1. Evaluar a todos los técnicos activos del servicio;
  2. Omitir automáticamente a los técnicos que no tengan ninguna pieza con necesidad automática de reposición ($\text{cantidad sugerida} = 0$), a los que ya tengan una orden abierta (*En preparación* o *Despachada*) y a los que tengan una ausencia vigente en ese instante;
  3. Crear una orden en estado *En preparación de almacén* por cada técnico elegible, registrar cada creación en la auditoría y presentar un resumen detallando cuántas órdenes se emitieron y qué técnicos fueron omitidos junto con su motivo (*«Sin déficit de stock»*, *«Ya tiene orden abierta»* o *«Ausencia activa»*).

---

### RF-03 · Impresión de Hoja de Carga A4, Despacho de Almacén y Cancelación Auditada
El sistema deberá permitir imprimir el documento físico de preparación de almacén, confirmar la salida del material y cancelar órdenes abiertas de forma justificada.

* **Criterio EARS 03.1 (Hoja de Carga Matutina en formato A4 sin costes — Decisión D4 y Art. V.4):** **CUANDO** Coordinación solicite imprimir una orden de carga, **EL SISTEMA DEBERÁ** generar una vista de impresión en formato A4 limpio que incluya exclusivamente las líneas activas (excluyendo las dadas de baja lógica), el código de la orden, fecha, nombre y código de operador del técnico, estado de la orden, tabla de piezas (código, nombre, categoría, saldo actual en furgoneta, umbral, unidades pedidas en averías, cantidad solicitada, cantidad despachada, motivo de reposición y casilla de verificación física) y recuadros de firma de entrega por Almacén y recepción por el Técnico, **ocultando el 100 % de los costes o precios de las piezas** (Art. V.4).
* **Criterio EARS 03.2 (Paso a Despachada con cantidades reales servidas — Decisión D1 y Resolución QA 1):** **CUANDO** Coordinación marque como *Despachada* una orden que estaba *En preparación de almacén*, **EL SISTEMA DEBERÁ** validar que cada línea activa tenga una cantidad despachada entera entre `0` y `9.999`, que $\text{saldo disponible actual} + \text{cantidad despachada} \le 9.999$, y que la suma total de unidades despachadas en la orden sea al menos `1` (permitiendo despachar `0` en alguna referencia puntual agotada en almacén central), congelar las líneas contra cualquier edición posterior y registrar el despacho en la auditoría con fecha, hora y autor.
* **Criterio EARS 03.3 (Alerta por ausencia o baja sobrevenida del destinatario — Caso límite C2 y Resolución QA 4):** **MIENTRAS** una orden permanezca abierta (*En preparación de almacén* o *Despachada*) y el técnico destinatario tenga una ausencia vigente o se encuentre inactivo, **EL SISTEMA DEBERÁ** mostrar una alerta visual destacada (*«⚠️ Destinatario actualmente ausente / inactivo»*) en la orden sin cancelarla ni reasignarla automáticamente, para que Coordinación verifique la devolución física de la caja a estantería antes de cancelarla.
* **Criterio EARS 03.4 (Cancelación con justificación obligatoria — Art. V.1):** **CUANDO** Coordinación solicite cancelar una orden en estado *En preparación de almacén* o *Despachada*:
  * **SI** el motivo de cancelación aportado contiene menos de **20 caracteres reales** (descontando espacios iniciales y finales), **ENTONCES EL SISTEMA DEBERÁ** rechazar la cancelación conforme al Artículo V.1 de la Constitución.
  * **SI** el motivo alcanza los 20 caracteres reales, **ENTONCES EL SISTEMA DEBERÁ** pasar la orden al estado terminal *Cancelada* sin modificar el stock de la furgoneta, dejando constancia en la auditoría del motivo, fecha y coordinador ejecutor.
  * **SI** la orden ya se encontraba *Confirmada por el técnico* o *Cancelada*, **ENTONCES EL SISTEMA DEBERÁ** rechazar la operación informando que el estado es definitivo.

---

### RF-04 · Confirmación Móvil de Recepción en 1 Toque, Comprobante de Última Carga e Indicador en Averías
El sistema deberá permitir al técnico destinatario confirmar la recepción del lote despachado desde su teléfono móvil, consultar el comprobante de su última recepción e identificar qué averías activas con solicitud de pieza cuentan ya con stock en su furgoneta.

* **Criterio EARS 04.1 (Visibilidad móvil de orden despachada y comprobante de última carga — Art. V.4 y Resolución QA 5):** **CUANDO** un técnico consulte su ruta o su inventario desde la vista móvil, **EL SISTEMA DEBERÁ** mostrarle únicamente su propia orden activa que se encuentre en estado *Despachada* (con el detalle de sus líneas activas y cantidades despachadas) junto con el resumen de solo lectura de su **última orden confirmada** (código de orden, fecha/hora de confirmación y unidades recibidas), impidiendo ver órdenes de otros compañeros o confirmar órdenes que sigan *En preparación de almacén*.
* **Criterio EARS 04.2 (Confirmación atómica en 1 toque sin restricción de estado de turno — Decisiones D1, D3, C3 y Resolución QA 4):** **CUANDO** el técnico destinatario confirme la recepción de su orden *Despachada* (con independencia de si su estado de jornada es *En turno*, *En pausa de comida* o *Fuera de turno*, y pudiendo adjuntar una nota opcional de recepción de hasta 500 caracteres), **EL SISTEMA DEBERÁ** ejecutar en una única operación atómica e indivisible:
  1. Verificar que la orden pertenece al técnico autenticado, que su estado sigue siendo *Despachada* (rechazando confirmaciones duplicadas concurrentes) y que ninguna línea activa con unidades despachadas $> 0$ supera el tope máximo de `9.999` unidades al sumarse al saldo actual de la furgoneta (permitiendo recepcionar piezas que hubieran sido desactivadas en el catálogo maestro después de emitirse la orden, según C3);
  2. Incrementar el saldo disponible de la furgoneta del técnico en cada línea activa con cantidad despachada $> 0$ por el número exacto de unidades despachadas (creando el registro de inventario con umbral `0` si el técnico no tenía esa pieza previamente declarada en su vehículo);
  3. Cambiar el estado de la orden a *Confirmada por el técnico* con la marca temporal exacta de recepción y registrar en la auditoría el saldo previo, las unidades recibidas y el nuevo saldo resultante de cada pieza (Art. III.3).
* **Criterio EARS 04.3 (Insignia de disponibilidad de repuesto en averías activas y aviso de stock compartido — Caso límite C5 y Resoluciones QA 2 y QA 3):** **CUANDO** una avería activa asignada a un técnico tenga solicitudes pendientes de repuesto, **EL SISTEMA DEBERÁ** mantener la avería en su estado actual (sin reactivarla automáticamente al confirmar una carga) y evaluar sus solicitudes pendientes frente al stock actual de la furgoneta del técnico asignado:
  1. **SI** todas las solicitudes pendientes de la avería son catalogadas, el técnico tiene en su furgoneta cantidad suficiente para cubrir esta avería ($\text{disponible} \ge \text{solicitada en esta avería}$) **y además** alcanza para cubrir la suma total de esa pieza solicitada en todas sus averías activas ($\text{disponible} \ge \text{total solicitado en todas sus averías}$), **EL SISTEMA DEBERÁ** mostrar la insignia **«📦 Repuesto ya en furgoneta»**.
  2. **SI** todas las solicitudes pendientes de la avería son catalogadas y el técnico tiene cantidad suficiente para esta avería individual ($\text{disponible} \ge \text{solicitada en esta avería}$), pero **no** alcanza para cubrir a la vez todas sus averías activas que piden esa misma pieza ($\text{disponible} < \text{total solicitado en todas sus averías}$), **EL SISTEMA DEBERÁ** mostrar la insignia **«📦 Repuesto en furgoneta (stock compartido)»**.
  3. **SI** la avería incluye alguna solicitud pendiente de **pieza fuera de catálogo**, **EL SISTEMA DEBERÁ** abstenerse de mostrar la insignia *«📦 Repuesto ya en furgoneta»* (para no inducir a error cuando aún falta un componente fuera de catálogo).

---

### RF-05 · Cruce Informativo de Stock en el Recomendador de Triaje (Decisiones D2, C1 y Resolución QA 2)
El sistema deberá mostrar en la tarjeta de cada candidato del recomendador de asignación el grado de cobertura de repuestos de su furgoneta para la avería que se está triando, sin alterar las puntuaciones ni las reglas de prelación ni añadir bloqueos de justificación sobre el candidato `#1`.

* **Criterio EARS 05.1 (Inmutabilidad de la puntuación, prioridad sanitaria Art. II y regla de justificación Art. V.1 — Resolución QA 2):** **CUANDO** el recomendador calcule la lista de técnicos candidatos para una avería, **EL SISTEMA DEBERÁ** mantener intacta la puntuación de idoneidad, su desglose numérico, el orden de decisión (garantizando que el técnico de guardia 24/7 siga encabezando el escalado sanitario ante emergencias de cadena de frío del Artículo II) y la regla de justificación de despacho (exigiendo motivo de $\ge 20$ caracteres reales únicamente cuando se descarta al candidato `#1` recomendado del ranking, sin exigir justificación adicional al elegir al `#1` aunque su furgoneta no disponga de stock).
* **Criterio EARS 05.2 (Cruce de Nivel 1 — Avería con piezas catalogadas ya solicitadas — Resolución QA 2):** **SI** la avería tiene $N \ge 1$ **referencias catalogadas distintas** con solicitud pendiente, **ENTONCES EL SISTEMA DEBERÁ** contar cuántas de esas $N$ referencias están cubiertas al 100 % en la furgoneta del candidato ($M$ referencias donde $\text{disponible} \ge \text{cantidad solicitada de esa pieza en la avería}$) y clasificarlo en uno de estos tres grados (añadiendo el sufijo *«(+ pieza fuera de catálogo)»* si la avería tiene además alguna solicitud pendiente fuera de catálogo):
  1. **Cobertura completa ($M = N$ y sin piezas fuera de catálogo pendientes):** Etiqueta `Repuesto solicitado en furgoneta (N/N ref.)` (el chip de interfaz antepone `✅`).
  2. **Cobertura parcial ($1 \le M < N$, o $M = 0$ pero con al menos 1 unidad positiva $\text{disponible} > 0$ de alguna pieza pedida, o $M = N$ existiendo además solicitud pendiente fuera de catálogo):** Etiqueta `Repuesto solicitado parcial (M/N ref.)` (chip `⚠️`; con sufijo *«(+ pieza fuera de catálogo)»* cuando aplique).
  3. **Sin cobertura ($\text{disponible} \le 0$ en todas las piezas catalogadas pedidas):** Etiqueta `Sin repuesto solicitado en furgoneta (0/N ref.)` (chip `⚠️`).
* **Criterio EARS 05.3 (Cruce de Nivel 2 — Avería sin solicitudes catalogadas previas, por modelo de máquina y familia de fallo):** **SI** la avería no tiene solicitudes pendientes de piezas catalogadas, **ENTONCES EL SISTEMA DEBERÁ** identificar el total de $N$ referencias activas del catálogo compatibles con el **modelo de la máquina** que pertenezcan a la familia asociada a la **categoría de la avería** (*Frío/Temperatura* $\rightarrow$ piezas térmicas; *Sistema de Pago* $\rightarrow$ piezas de pago; *Atasco de Producto* $\rightarrow$ piezas mecánicas; *Eléctrico/Apagada* $\rightarrow$ piezas electrónicas; *Otra* $\rightarrow$ cualquier pieza compatible con el modelo), contar de cuántas lleva saldo positivo el candidato ($M$ referencias con $\text{disponible} > 0$) y clasificarlo en uno de estos cuatro estados:
  1. **No aplicable por catálogo ($N = 0$):** Etiqueta neutra `ℹ️ Sin repuestos catalogados para este modelo/categoría` (sin alerta negativa).
  2. **Cobertura completa de la familia ($M = N \ge 1$):** Etiqueta `Stock compatible (N/N ref.)` (chip `✅`); la familia y el modelo se muestran en la propia tarjeta de la avería.
  3. **Cobertura parcial de la familia ($1 \le M < N$):** Etiqueta `Stock compatible parcial (M/N ref.)` (chip `⚠️`).
  4. **Sin stock de la familia ($M = 0$ con $N \ge 1$):** Etiqueta `Sin stock compatible (0/N ref.)` (chip `⚠️`).

---

### RF-06 · Privacidad y Segregación de Roles (Art. V.4)
* **Criterio EARS 06.1 (Control de acceso por rol):** **SI** un usuario con rol de Responsable de Sede o un visitante no autenticado intenta consultar, crear, despachar, cancelar o confirmar órdenes de carga, o consultar el cruce de stock de triaje, **ENTONCES EL SISTEMA DEBERÁ** denegar la operación.
* **Criterio EARS 06.2 (Opacidad total hacia la sede cliente):** **EL SISTEMA DEBERÁ** garantizar que ninguna vista, notificación ni respuesta dirigida al Portal de Sede o al canal público por QR incluya datos sobre órdenes de carga, disponibilidad de repuestos en furgoneta o motivos de reposición.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Rendimiento en triaje y movilidad):** El enriquecimiento del recomendador de triaje con el cruce de stock para toda la plantilla de técnicos debe resolverse de forma agregada en menos de **200 milisegundos**, y la consulta/confirmación móvil de la orden de carga del técnico debe responder en menos de **250 milisegundos**.
* **RNF-02 (Inmutabilidad e integridad transaccional — Art. III):** La confirmación de recepción de una orden de carga debe ejecutarse de forma atómica con bloqueo de concurrencia para garantizar que dos pulsaciones simultáneas jamás dupliquen el incremento de stock, dejando huella inmutable del saldo anterior y posterior en la auditoría.
* **RNF-03 (Alineación con el Sistema de Diseño Corporativo e Impresión A4):** Todas las superficies de interfaz (panel de órdenes de carga, etiquetas de cobertura en triaje y aviso móvil de recepción) deben seguir estrictamente el sistema de diseño corporativo (`docs/design.md`): lienzo claro libre de ornamentación superflua, voltaje interactivo único en azul eléctrico para acciones primarias, jerarquía tipográfica en dos niveles (titulares/cifras destacadas frente a texto de lectura/tablas), bordes conservadores binarios de 4px en botones/insignias/campos y 8px en tarjetas/paneles (sin botones píldora ni radios intermedios de 12–16px), tarjetas delimitadas por borde fino sin sombra en reposo, y separación semántica entre avisos de lectura (en tono ámbar) y botones de acción. La Hoja de Carga Matutina debe maquetarse para impresión directa en papel A4 sin desbordamientos.
* **RNF-04 (Dualidad Lingüística):** Todos los textos visibles para los usuarios, cabeceras de la Hoja de Carga A4, etiquetas de estado y mensajes de validación deben presentarse en castellano neutro profesional.

---

## 6. Casos Límite y Manejo de Errores

1. **Doble pulsación o pérdida momentánea de red al confirmar la recepción móvil:** Si el técnico pulsa dos veces seguidas «Confirmar Recepción», la primera petición procesa el alta de stock y cierra la orden como *Confirmada por el técnico*; la segunda petición detecta que la orden ya no está *Despachada* y devuelve un error de conflicto sin volver a sumar las unidades. Además, el técnico ve de inmediato el comprobante de su última orden confirmada (Resolución QA 5).
2. **Rotura parcial de existencias en Almacén Central al despachar:** Si una orden pedía 3 unidades de una bomba y 2 de una sonda, pero en la estantería de almacén central había `0` bombas y `2` sondas, Coordinación puede despachar la orden indicando `0` en la bomba y `2` en la sonda (ya que el total despachado de la orden es $> 0$). Al confirmar el técnico, solo se suman las `2` sondas y la bomba continúa apareciendo como pendiente para la siguiente orden cuando almacén reciba material.
3. **Pieza dada de baja en el catálogo maestro mientras la caja está en tránsito (Caso límite C3):** Si una pieza incluida en una orden ya creada o despachada es desactivada en el catálogo maestro antes de que el técnico confirme la recepción en su móvil, el sistema permite completar la recepción igualmente para no bloquear la caja física entregada.
4. **Técnico que entra de baja médica con una orden ya emitida o preparación anticipada antes de regresar de vacaciones (Caso límite C2 y Resolución QA 4):** La orden muestra la alerta *«⚠️ Destinatario actualmente ausente / inactivo»* sin cancelarse en silencio. Si el técnico causó baja imprevista, Coordinación recoge la caja física y la cancela con justificación ($\ge 20$ caracteres); si almacén preparó la orden la víspera de su regreso, la caja queda lista para cuando el técnico vuelva.
5. **Dos o más averías del mismo técnico pidiendo la misma pieza con stock limitado (Resolución QA 3):** Si un técnico tiene dos averías pidiendo `1` bomba cada una (total `2`) y en su furgoneta solo lleva `1` bomba, ambas muestran *«📦 Repuesto en furgoneta (stock compartido)»* en lugar de dar por hecho que hay unidades para las dos a la vez.
6. **Avería con solicitud mixta de pieza catalogada + pieza fuera de catálogo (Resolución QA 2):** En el triaje se clasifica como *Repuesto solicitado parcial* con el aviso *«(+ pieza fuera de catálogo)»*, y en la tarjeta de la avería no se muestra *«📦 Repuesto ya en furgoneta»* para evitar desplazamientos en falso cuando aún falta el componente fuera de catálogo.

---

## 7. Fuera de Alcance (Out of Scope)

1. **Gestión de compras a proveedores y stock del almacén central:** No se modela el inventario total de las estanterías del almacén central, ni ubicaciones de pasillo/balda, ni pedidos de compra a fabricantes o distribuidores externos.
2. **Traspasos directos entre furgonetas en ruta:** No se incluye el préstamo o trasvase directo de piezas de la furgoneta de un técnico a la de otro compañero sin pasar por Coordinación/Almacén Central.
3. **Valoración económica o facturación en órdenes de carga y hojas A4:** Las órdenes de carga y la Hoja de Carga Matutina A4 operan exclusivamente en unidades físicas; no almacenan ni imprimen precios unitarios ni importes monetarios (Art. V.4).
4. **Edición de cantidades recibidas por parte del técnico en el móvil:** El técnico confirma el lote despachado por almacén en un toque; cualquier ajuste por discrepancia física debe ser realizado o despachado por Coordinación con auditoría.
5. **Sincronización sin conexión (Modo Offline):** La operativa desconectada en sótanos sin cobertura corresponde a una funcionalidad independiente del catálogo de evolución.

---

## 8. Criterios de Finalización (Definition of Done)

1. Coordinación puede consultar la propuesta automática de reposición de cualquier técnico activo (combinando el colchón de umbral/descuadre y las solicitudes de averías activas con la fórmula aditiva de *«Ambos»*) y emitir órdenes de carga individuales o en lote (omitiendo automáticamente en lote a técnicos sin déficit, ausentes o que ya tengan una orden abierta, y bloqueando siempre a técnicos inactivos).
2. El sistema impide que un mismo técnico tenga más de una orden abierta (*En preparación de almacén* o *Despachada*) a la vez, y aplica baja lógica sin borrado físico cuando se retiran líneas en preparación (Art. III.1).
3. Coordinación puede editar órdenes en preparación, imprimir la **Hoja de Carga Matutina en formato A4** ordenada por prioridad operativa y sin importes económicos, despachar la orden indicando las cantidades reales servidas, ver alertas si el destinatario está ausente/inactivo, y cancelar órdenes abiertas con motivo obligatorio de al menos 20 caracteres reales (Art. V.1).
4. El técnico visualiza en su móvil únicamente su propia orden en estado *Despachada* (más el comprobante de su última orden confirmada), confirma su recepción en un solo toque sin restricción de estado de turno incrementando de forma atómica y auditada el stock de su furgoneta, y visualiza en sus averías activas la insignia *«📦 Repuesto ya en furgoneta»* o *«📦 Repuesto en furgoneta (stock compartido)»* cuando todas sus solicitudes pendientes son catalogadas y están cubiertas.
5. El recomendador de triaje muestra en cada candidato el indicador informativo de cobertura de repuestos en dos niveles (`M/N ref.` sobre referencias catalogadas solicitadas con aviso de fuera de catálogo, o `M/N ref.` sobre compatibilidad por modelo y familia del fallo en tres grados: completo, parcial o sin stock) sin alterar la puntuación territorial, la prioridad sanitaria del Artículo II ni las reglas de justificación del candidato `#1`.
6. El Portal de Sede y el canal público QR mantienen opacidad absoluta sobre órdenes de carga y disponibilidad de repuestos (Art. V.4).
7. Todas las pruebas automatizadas del proyecto pasan al 100 % en verde.

---

## 9. Dudas Abiertas (`[NECESITA ACLARACIÓN]`)

* **Ninguna.** Todas las decisiones de alcance, flujo de estados, niveles del recomendador de triaje, casos límite y las 5 Resoluciones de Auditoría QA (`D1` a `D4`, `C1` a `C5` y `QA 1` a `QA 5`, cerrando los 13 hallazgos) han sido aclaradas y ratificadas con el Product Owner.
