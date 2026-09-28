# Especificación Funcional: Catálogo de Repuestos y Trazabilidad de Piezas en Intervención (Módulo M2)

## 1. Contexto y Objetivo

### 1.1 Contexto del Negocio
En la operativa de mantenimiento de máquinas dispensadoras de VendGuard, las averías a menudo requieren la sustitución de piezas críticas (sondas térmicas, electroválvulas, grupos de infusión, placas de control, monederos o muelles). Actualmente:
- Al pausar una intervención técnica en campo por falta de repuestos, el técnico introduce una descripción en texto libre no normalizado, lo cual genera imprecisiones, pedidos incorrectos y falta de trazabilidad en las solicitudes.
- Al resolver o cerrar una avería, no queda constancia estructurada de qué componentes físicos fueron realmente reemplazados, cuántas unidades se emplearon ni cuál fue el destino de la pieza extraída (si quedó para desguace o si es apta para reincorporación al taller).
- El servicio de coordinación carece de una analítica estructurada para identificar qué modelos de máquina presentan mayor tasa de degradación o avería recurrente por componente, así como el coste acumulado en repuestos por máquina y sede.

### 1.2 Objetivo del Módulo
El Módulo M2 (Control Operativo y Taller) tiene como propósito:
1. **Eliminar el texto libre no estructurado** en las pausas técnicas, sustituyéndolo por un catálogo oficial de repuestos compatible con cada modelo específico de máquina y con salvaguarda controlada para piezas no catalogadas.
2. **Garantizar la trazabilidad completa del consumo y destino de piezas** en cada intervención (tanto averías correctivas como órdenes de mantenimiento preventivo), registrando componentes sustituidos, cantidades y clasificación del componente retirado (`DESGUACE` o `TALLER`).
3. **Consolidar el histórico económico mediante snapshots inmutables de coste**, congelando el precio de referencia al momento de la intervención para evitar alteraciones retrospectivas.
4. **Proporcionar al Coordinador del Servicio un panel analítico de fiabilidad y fallos por modelo**, que cuantifique los componentes más sustituidos, los costes acumulados y alerte de fallos prematuros o crónicos por componente y máquina.
5. **Preservar la segregación estricta de datos (Constitución Art. V.4)**, garantizando que los Responsables de Sede no tengan acceso en ningún caso a datos de piezas, reparaciones internas ni costes económicos.

---

## 2. Usuarios del Sistema

| Rol | Descripción | Responsabilidades en este Módulo |
| :--- | :--- | :--- |
| **Coordinador del Servicio** | Supervisor operativo y administrativo del parque | Administra el catálogo de repuestos (altas, modificaciones, compatibilidades por modelo y costes de referencia), supervisa solicitudes de piezas no catalogadas y analiza los informes de fallos y costes por modelo. |
| **Técnico de Ruta / Campo** | Operario en movilidad asignado a la resolución de incidencias y preventivos | Selecciona repuestos compatibles al solicitar piezas pendientes, registra las piezas instaladas al resolver incidencias o preventivos y documenta el destino de las piezas retiradas. |
| **Responsable de Ubicación / Sede** | Usuario informador del cliente en la sede donde opera la máquina | **Sin acceso:** En cumplimiento del Artículo V.4 de la Constitución, los usuarios de sede **jamás** tienen acceso a la visualización de repuestos utilizados, códigos de piezas ni costes económicos asociados. |

---

## 3. Historias de Usuario

### HU-01: Selección Estructurada de Repuestos Compatibles al Pausar Incidencia
**Como** Técnico de Ruta que atiende una avería en una máquina,  
**quiero** pausar la intervención seleccionando los repuestos necesarios desde un catálogo filtrado por el modelo específico de dicha máquina (o solicitar una pieza no catalogada en caso excepcional),  
**para que** el coordinador conozca con total precisión qué componentes se necesitan sin depender de anotaciones informales en texto libre.

### HU-02: Registro Obligatorio de Componentes Sustituidos en la Resolución
**Como** Técnico de Ruta que finaliza una intervención correctiva o preventiva,  
**quiero** declarar explícitamente si se sustituyeron piezas físicas y registrar los repuestos consumidos junto con el destino del material retirado (`DESGUACE` o `TALLER`),  
**para que** quede una constancia inmutable y auditable del trabajo ejecutado y del historial técnico de la máquina intervenida.

### HU-03: Administración Centralizada del Catálogo de Piezas por Modelo
**Como** Coordinador del Servicio,  
**quiero** gestionar el catálogo maestro de repuestos (código, nombre, fabricante, coste de referencia, modelos de máquina compatibles y estado activo/inactivo),  
**para que** el personal de campo opere siempre sobre piezas homologadas, actualizadas y con costes de referencia precisos.

### HU-04: Analítica de Fallos, Costes y Detección de Averías Recurrentes
**Como** Coordinador del Servicio,  
**quiero** consultar un cuadro analítico de componentes con mayor índice de reemplazo, costes acumulados por sede y modelo, y detección de patrones de fallo prematuro por pieza y máquina,  
**para que** podamos identificar modelos problemáticos, negociar con fabricantes y optimizar las compras y revisiones preventivas.

---

## 4. Requisitos Funcionales (RF) con Criterios de Aceptación EARS

### 4.1 Gestión del Catálogo de Repuestos (Coordinación)

#### RF-REP-01: Catálogo Maestro de Repuestos y Compatibilidad por Modelo
El sistema debe mantener un catálogo centralizado de repuestos donde cada elemento contenga: código único de pieza, nombre descriptivo, categoría de componente, fabricante, coste económico de referencia (en euros, con 2 decimales) y los modelos específicos de máquina dispensadora con los que es compatible (pudiendo asociarse una misma pieza a múltiples modelos).

- **EARS Ubicuo**: El sistema DEBE almacenar para cada repuesto del catálogo un identificador único de referencia, denominación, fabricante, coste de referencia (en euros con 2 decimales), lista de modelos específicos compatibles y estado operativo (`activo`/`inactivo`).
- **EARS Evento**: CUANDO el Coordinador del Servicio registre un nuevo repuesto, el sistema DEBE asociar dicho repuesto a uno o más modelos específicos de máquina existentes en el catálogo.
- **EARS Excepción**: SI el Coordinador intenta registrar un repuesto con un código de pieza que ya existe en el catálogo, ENTONCES el sistema DEBE rechazar la creación y mostrar un mensaje de error explicativo en español.

#### RF-REP-02: Edición, Baja Lógica y Preservación de Operativa en Curso
El sistema debe permitir al Coordinador modificar los datos descriptivos, el coste de referencia y la lista de modelos compatibles de un repuesto existente, así como desactivarlo (baja lógica) sin eliminar su histórico previo.

- **EARS Evento**: CUANDO el Coordinador desactive un repuesto, el sistema DEBE marcarlo como inactivo sin eliminar físicamente el registro (`is_active = false`).
- **EARS Estado Continuo**: MIENTRAS un repuesto permanezca en estado inactivo, el sistema DEBE ocultarlo de los selectores de piezas para nuevas averías e intervenciones técnicas de campo.
- **EARS Opcional**: DONDE una avería en curso ya tuviese solicitado un repuesto que posteriormente fue desactivado en el catálogo, el sistema DEBE permitir al técnico seleccionar dicha pieza al resolver esa avería específica sin bloquear la intervención.

---

### 4.2 Solicitud Estructurada en Pausa por Repuesto (Técnico de Ruta)

#### RF-REP-03: Eliminación de Texto Libre y Solicitud Normalizada de Repuestos
Al solicitar la pausa de una avería correctiva por repuesto pendiente (`PENDIENTE_REPUESTO`), el sistema debe impedir la introducción exclusiva de texto libre arbitrario y exigir la selección estructurada de una o más piezas compatibles con el modelo específico de la máquina averiada, indicando la cantidad requerida de cada una.

- **EARS Evento**: CUANDO un Técnico de Ruta ejecute la acción de pausar por repuesto en una avería activa, el sistema DEBE presentar un selector con las piezas activas del catálogo compatibles con el modelo específico de la máquina intervenida.
- **EARS Ubicuo**: El sistema DEBE exigir una cantidad entera positiva comprendida entre 1 y 50 unidades para cada repuesto seleccionado en la solicitud de pausa.
- **EARS Excepción**: SI el técnico no selecciona al menos un repuesto del catálogo ni activa la opción de pieza fuera de catálogo, ENTONCES el sistema DEBE bloquear la transición al estado `PENDIENTE_REPUESTO` y notificar el motivo en español.

#### RF-REP-04: Excepción Controlada de Pieza Fuera de Catálogo (No Bloqueante)
Cuando una avería requiera una pieza imprevista no existente en el catálogo oficial, el técnico debe poder activar la opción "Pieza fuera de catálogo", lo que habilita un campo de descripción obligatoria y justificación técnica para revisión por parte de coordinación.

- **EARS Opcional**: DONDE la pieza requerida por el técnico no figure en el catálogo, el sistema DEBE permitir marcar la opción de repuesto fuera de catálogo.
- **EARS Evento**: CUANDO se marque la opción de repuesto fuera de catálogo, el sistema DEBE exigir una justificación técnica detallada de al menos 20 caracteres describiendo el componente y su función.
- **EARS Evento**: CUANDO se confirme la solicitud con una pieza fuera de catálogo, el sistema DEBE marcar la avería con una etiqueta visual de "Revisión de Repuesto Requerida" visible para el Coordinador.
- **EARS Estado Continuo**: MIENTRAS la avería mantenga la solicitud de pieza fuera de catálogo, el sistema DEBE permitir al técnico reanudar y resolver la intervención introduciendo la pieza no catalogada con un coste provisional de 0,00 €, notificando de manera informativa al Coordinador para su posterior valoración o incorporación al catálogo.

---

### 4.3 Trazabilidad y Consumo de Piezas al Resolver (Técnico de Ruta)

#### RF-REP-05: Declaración de Sustitución de Componentes en la Resolución
Al momento de resolver una avería correctiva o completar una orden de mantenimiento preventivo, el técnico debe indicar obligatoriamente si la intervención conllevó la sustitución física de componentes (Sí / No).

- **EARS Evento**: CUANDO el técnico inicie el flujo de resolución de una avería o preventivo, el sistema DEBE requerir la respuesta obligatoria a la pregunta de si se sustituyeron piezas físicas.
- **EARS Estado Continuo**: MIENTRAS el técnico marque que NO se sustituyeron piezas, el sistema DEBE permitir la resolución convencional requiriendo únicamente el diagnóstico técnico y la acción correctiva realizada.

#### RF-REP-06: Registro de Piezas Instaladas, Destino y Snapshot de Coste
Cuando la resolución involucre sustitución de piezas, el técnico debe registrar cada repuesto efectivamente instalado, su cantidad (entero entre 1 y 50) y el destino asignado al componente retirado (clasificado exclusivamente como: `DESGUACE` o `TALLER`).

- **EARS Evento**: CUANDO el técnico confirme que hubo sustitución de componentes, el sistema DEBE exigir la selección de al menos una pieza instalada compatible con sus unidades correspondientes.
- **EARS Ubicuo**: Para cada componente sustituido, el sistema DEBE registrar el destino de la pieza retirada mediante una clasificación cerrada entre `DESGUACE` o `TALLER`.
- **EARS Evento**: CUANDO se complete la resolución, el sistema DEBE capturar un *snapshot* inmutable del coste unitario de referencia vigente (`unit_cost`) en ese instante exacto para cada pieza instalada, calculando el coste total en repuestos de la intervención.
- **EARS Estado Continuo**: MIENTRAS se realicen modificaciones posteriores en los costes de referencia del catálogo maestro, el sistema DEBE mantener inalterados los costes unitarios y totales consolidados en las intervenciones históricas cerradas.

#### RF-REP-07: Coexistencia con Órdenes de Mantenimiento Preventivo (Módulo 05)
El registro de consumo de repuestos debe estar disponible e integrado en la ejecución y cierre de órdenes preventivas.

- **EARS Evento**: CUANDO un técnico complete una orden de mantenimiento preventivo, el sistema DEBE ofrecer la sección de piezas sustituidas conforme a los mismos criterios de catálogo, cantidades, destino y snapshot de coste.
- **EARS Ubicuo**: El sistema NO DEBE permitir transicionar órdenes de mantenimiento preventivo al estado `PENDIENTE_REPUESTO`; la sustitución de piezas en preventivos se registra exclusivamente al completar la orden.
- **EARS Ubicuo**: SI una máquina se encuentra en situación de cuarentena sanitaria preventiva o con temperatura fuera de rango (Módulo 05 y Artículo II de la Constitución), el registro o sustitución de piezas NO DEBE levantar la cuarentena de forma automática; el desbloqueo exige estrictamente la emisión y registro de un certificado sanitario conforme.

---

### 4.4 Analítica de Fallos y Costes por Modelo (Coordinación)

#### RF-REP-08: Panel Analítico de Fiabilidad, Costes y Alerta de Fallo Recurrente
El sistema debe proporcionar un cuadro de control analítico para el Coordinador que consolide el comportamiento de los repuestos en el parque de máquinas.

- **EARS Ubicuo**: El sistema DEBE presentar un ranking ordenado de los componentes con mayor volumen de reemplazo, desglosable por modelo específico de máquina.
- **EARS Ubicuo**: El sistema DEBE calcular el coste económico acumulado en piezas sustituidas por máquina individual y por sede cliente en el período temporal consultado, basándose en los costes congelados (*snapshots*) de cada intervención.
- **EARS Evento**: CUANDO un componente específico (mismo código de pieza) supere 3 sustituciones en la misma máquina en un período móvil inferior a 90 días, el sistema DEBE emitir una advertencia visual de "Componente con Fallo Recurrente / Prematuro" en el panel analítico y en la ficha técnica de la máquina.

#### RF-REP-09: Exportación de Datos de Taller y Consumo
El sistema debe permitir la exportación inmediata en formato plano CSV de los datos de consumo de piezas y costes agregados para el Coordinador.

- **EARS Evento**: CUANDO el Coordinador solicite la exportación de datos de repuestos, el sistema DEBE generar y descargar un archivo CSV estructurado con: fecha de intervención, código de ticket/orden, código de máquina, modelo de máquina, código de pieza, unidades, coste unitario congelado, coste total consolidado y destino del componente retirado.

---

### 4.5 Privacidad y Segregación de Datos (Constitución Art. V.4)

#### RF-REP-10: Segregación Estricta de Repuestos y Costes para Responsables de Sede
En cumplimiento estricto del Artículo V.4 de la Constitución, los usuarios informadores (Responsables de Ubicación / Sede) no deben tener acceso a datos de repuestos ni costes.

- **EARS Ubicuo**: El sistema DEBE ocultar completamente los bloques de repuestos solicitados, componentes sustituidos, códigos de piezas, destinos de taller y costes económicos en todas las vistas, detalles de tickets y respuestas de API destinadas al rol de Responsable de Sede.

---

## 5. Requisitos No Funcionales (RNF)

- **RNF-REP-01 (Inviolabilidad e Inmutabilidad de Trazabilidad)**: Toda pieza registrada como instalada en una avería o preventivo queda vinculada de forma inalterable al registro histórico y al log de auditoría. Ninguna pieza registrada puede eliminarse físicamente de la base de datos (`DELETE FROM` terminantemente prohibido, Constitución Art. III.1).
- **RNF-REP-02 (Rendimiento en Movilidad)**: La carga del catálogo filtrado por modelo de máquina en la vista móvil del técnico no debe superar los 250 milisegundos bajo conexiones 3G/4G habituales.
- **RNF-REP-03 (Usabilidad Operativa y Sistema de Diseño docs/design.md)**: La selección de repuestos y destino de piezas debe ajustarse al sistema de diseño corporativo (inspiración Docker: radio binario conservador de 4px en botones/chips/inputs y 8px en tarjetas/modales, color interactivo primario azul eléctrico `#2560ff`, tinta `#2c333f`, fondo canvas `#f9fafb`, tipografía Inter y objetivos táctiles móviles $\ge 44\text{px}$ operables a una sola mano en pantallas de 360px sin desplazamiento horizontal).
- **RNF-REP-04 (Moneda y Precisión Decimal Inmutable)**: Los costes unitarios y totales deben manejarse con precisión decimal estricta (2 decimales en euros) evitando errores de redondeo de punto flotante.
- **RNF-REP-05 (Minimalismo Tecnológico y Cero Bloatware)**: La persistencia y cálculo analítico de repuestos se implementa exclusivamente mediante PHP 8 nativo y sentencias SQL optimizadas con PDO, sin librerías externas ni dependencias no autorizadas (Constitución Art. IV).

---

## 6. Casos Límite y Manejo de Errores

1. **Discrepancia entre repuesto solicitado en pausa y repuesto instalado en resolución**:  
   Si el técnico solicitó inicialmente una pieza (ej. "Bomba de Agua Ulka") pero en la resolución comprueba que la avería requería otra (ej. "Electroválvula"), el sistema debe permitir registrar las piezas realmente instaladas. Al resolverse la avería, la solicitud original queda archivada como "Completada con discrepancia justificada" en el log de auditoría.
2. **Reapertura de avería dentro de la ventana de 48 horas (Constitución Art. V.6)**:  
   Si una avería resuelta es reabierta dentro de las 48 horas reglamentarias, las piezas registradas en el primer cierre permanecen inmutables y congeladas. Si en la segunda resolución se requieren piezas adicionales, estas se agregan como nuevas líneas de consumo independientes.
3. **Cancelación de avería en estado `PENDIENTE_REPUESTO`**:  
   Si una avería pausada por repuesto es cancelada o descartada (ej. duplicado o baja de máquina), la solicitud de piezas pasa a estado `CANCELADA` y no computa ningún coste ni consumo en los informes de fiabilidad.
4. **Repuesto desactivado durante una avería en curso**:  
   Si el coordinador desactiva una pieza del catálogo mientras una avería está en estado `PENDIENTE_REPUESTO` solicitando dicha pieza, el técnico puede completar la resolución seleccionando la pieza previamente pedida, pero dicha pieza queda inaccesible para nuevas intervenciones.
5. **Máquina sin repuestos compatibles asociados**:  
   Si una máquina no tiene piezas asignadas en el catálogo de su modelo, el sistema habilita automáticamente el flujo de "Pieza fuera de catálogo" con justificación obligatoria para no bloquear la operativa de campo.
6. **Delimitación de destino sin gestión logística (Anti-Feature Creep, Art. VI)**:  
   La clasificación `TALLER` o `DESGUACE` es un metadato informativo de la intervención. No se generan albaranes logísticos de custodia física ni trasvases de furgoneta en esta fase.

---

## 7. Fuera de Alcance (Out of Scope)

1. **Gestión integral de almacenes físicos y compras**: No se implementa gestión de órdenes de compra automáticas a proveedores, albaranes de entrega de logística ni control de ubicaciones físicas en estanterías de almacén.
2. **Control de stock en furgonetas en tiempo real**: No se realiza descuento dinámico de stock por inventario de furgoneta ni trasvase entre técnicos; se modela el catálogo con costes de referencia y registro de consumo.
3. **Facturación a clientes por piezas**: No se generan facturas ni cargos a clientes por repuestos en garantía o fuera de garantía; el coste es analítico interno para el gestor del parque.
4. **Trazabilidad logística de custodia individual de componentes en taller**: No se crea un submódulo de inventario para piezas reacondicionadas en taller central.

---

## 8. Criterios de Finalización (Definition of Done)

El Módulo M2 se considerará completamente terminado y validado cuando:
1. El Coordinador pueda crear, modificar, desactivar y consultar repuestos en el catálogo asignando compatibilidad por modelo específico de máquina dispensadora.
2. El Técnico no pueda pasar una avería a `PENDIENTE_REPUESTO` mediante texto libre plano, sino seleccionando repuestos compatibles o justificando formalmente una pieza fuera de catálogo.
3. El Técnico registre obligatoriamente en la resolución de averías y preventivos las piezas instaladas, cantidades y el destino del componente retirado (`DESGUACE` o `TALLER`).
4. Al resolver, el sistema capture el coste unitario congelado (*snapshot*) de cada pieza sin alteraciones retrospectivas ante futuros cambios de precio.
5. El Coordinador disponga del panel analítico de piezas más sustituidas, costes acumulados por modelo/sede y alertas de piezas con fallo recurrente (> 3 sustituciones en 90 días en la misma máquina).
6. Los Responsables de Sede tengan restringido al 100% el acceso a cualquier dato o coste de repuestos (Constitución Art. V.4).
7. Toda la batería de pruebas automatizadas (unitarias, frontend y de integración) pase con 0 errores y 0 advertencias respetando el Dogma Vanilla y el Dualismo Lingüístico.

---

## 9. Dudas Abiertas

- **[NECESITA ACLARACIÓN]**: Ninguna. Todos los puntos de ambigüedad técnica, casos límite y alineamiento constitucional fueron auditados y resueltos formalmente con el usuario.
