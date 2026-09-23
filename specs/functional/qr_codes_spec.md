# ESPECIFICACIÓN FUNCIONAL · GENERADOR Y LECTOR DE CÓDIGOS QR PARA MÁQUINAS
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Documento:** `specs/functional/qr_codes_spec.md`  
**Estado:** Especificación Formal Consolidada (Auditada por QA y Lista para Aprobación)  
**Metodología:** SDD (Specification-Driven Development) · Notación EARS  
**Conformidad Constitucional:** Artículos I, II, III, IV y V de la Constitución de VendGuard  

---

## 1. Contexto y Objetivo

### 1.1 Contexto
En un entorno operativo de vending, los usuarios finales (consumidores que pierden dinero o detectan una avería) y el personal de las sedes clientes necesitan una vía inmediata y sin fricción para avisar de un fallo. La necesidad de recordar o buscar un código de sede, navegar por un listado de máquinas y seleccionar la máquina averiada introduce demoras y desincentiva la notificación temprana, lo que agrava pérdidas económicas y riesgos sanitarios en máquinas de alimentos frescos perecederos.

Por su parte, los coordinadores del servicio precisan dotar a cada máquina física de un distintivo visual estandarizado y duradero con un código QR que sirva tanto de canal de reporte como de identificación de contacto oficial de la empresa operadora.

### 1.2 Objetivo
Definir los requisitos funcionales y el comportamiento operativo para:
1. Proporcionar al coordinador un generador de etiquetas adhesivas con código QR, permitiendo la previsualización en tiempo real, ajuste de teléfono de asistencia (con opción de actualizar la sede), impresión directa y exportación gráfica en formato vectorial SVG individual o por lote de sede en cuadrícula A4.
2. Permitir que cualquier persona, al escanear el código QR con la cámara de su teléfono móvil, acceda directamente al formulario de reporte con la sede y la máquina preseleccionadas, sin contraseñas ni pasos intermedios.
3. Blindar el cumplimiento de las reglas constitucionales:
   - **Artículo II (Seguridad Alimentaria):** Alerta sanitaria prioritaria destacada ante escaneos de máquinas de alimentos perecederos.
   - **Artículo V.4 (Segregación y Privacidad de Datos):** Ocultación estricta de notas internas de taller, datos del técnico y costes ante consultas de averías activas.
   - **Artículo V.2 (Prevención de Duplicados y Gestión de Concurrencia):** Canalización automática hacia el ticket existente en caso de avería abierta o envíos simultáneos por dos usuarios.

---

## 2. Usuarios y Roles

* **Coordinador del Servicio:** Responsable operativo que gestiona el parque de máquinas. Es el único actor autorizado para acceder a la herramienta de generación, personalización e impresión/descarga de etiquetas con código QR.
* **Usuario Informador (Consumidor o Personal de Sede):** Cualquier persona situada frente a una máquina física de vending que utiliza su propio smartphone para escanear el código QR adhesivo y reportar un problema (moneda tragada, producto atascado, rotura de frío o máquina apagada). Su acceso es efímero y restringido al reporte de dicha máquina, sin privilegios para navegar por el resto de máquinas de la sede.

---

## 3. Historias de Usuario

* **HU-QR-01 (Generación Individual de Etiqueta):** *Como* Coordinador, *quiero* generar la etiqueta con código QR de una máquina específica con sus datos identificativos y teléfono de soporte *para* imprimirla o descargarla cuando se instale una máquina o se sustituya una pegatina deteriorada.
* **HU-QR-02 (Generación por Lote de Sede en A4):** *Como* Coordinador, *quiero* generar en un solo paso todas las etiquetas QR de las máquinas de una sede concreta en una cuadrícula optimizada para papel A4 con saltos de página limpios *para* preparar el material adhesivo de una nueva apertura o revisión general.
* **HU-QR-03 (Reporte Inmediato por Escaneo):** *Como* Usuario Informador frente a una máquina, *quiero* escanear el código QR con mi teléfono móvil *para* abrir de forma inmediata el formulario de avería con la máquina ya seleccionada y bloqueada, sin tener que escribir códigos de sede ni buscar en listados.
* **HU-QR-04 (Consulta Segura de Avería Preexistente):** *Como* Usuario Informador, *quiero* que al escanear una máquina que ya tiene un aviso abierto se me informe con un resumen público de que el servicio técnico ya está al corriente *para* no duplicar la incidencia y permitirme aportar comentarios o fotos si mi experiencia aporta datos nuevos, protegiendo la privacidad de los datos internos.
* **HU-QR-05 (Confirmación Rápida de Servicio):** *Como* Usuario Informador, *quiero* recibir un comprobante digital en pantalla con el código de ticket al enviar el reporte *para* tener la certeza de que mi aviso ha sido registrado por el servicio técnico.

---

## 4. Requisitos Funcionales y Criterios de Aceptación (Notación EARS)

### RF-01: Generación y Previsualización de Etiquetas QR
*El sistema dispondrá de un generador visual de etiquetas que vinculará de forma unívoca cada máquina con su dirección digital de reporte directo.*

* **EARS 1.1 (Evento):** Cuando el coordinador solicita generar la etiqueta de una máquina, el sistema deberá generar un código QR legible cuyo destino codifique el identificador de la sede y el código unívoco de la máquina.
* **EARS 1.2 (Evento/Personalización):** Cuando el coordinador abra la vista de generación de etiqueta, el sistema deberá mostrar los campos identificativos de la máquina (código, modelo, tipo de máquina, sede y ubicación exacta de planta/ala) y un campo editable inicializado con el teléfono de contacto de la sede, permitiendo modificar el número de asistencia técnica antes de emitir la etiqueta.
* **EARS 1.3 (Evento/Persistencia Opcional):** Cuando el coordinador modifique el teléfono de asistencia en la etiqueta, el sistema deberá incluir una casilla de verificación opcional (*"Actualizar también como teléfono predeterminado de la sede"*); si está marcada, actualizará el registro maestro de la sede; si no está marcada, la modificación será estrictamente efímera para esa impresión física.
* **EARS 1.4 (Estado/Diseño Visual y Anti-desbordamiento):** Mientras el coordinador visualice la vista previa de la etiqueta, el sistema deberá renderizar en tiempo real el diseño final adhesivo conteniendo:
  1. Logotipo identificativo del sistema.
  2. Código alfanumérico visible de la máquina en tipografía destacada.
  3. Tipo y modelo de la máquina.
  4. Sede y planta/ala física con ajuste tipográfico proporcional automático para evitar desbordamientos de texto ante nombres largos.
  5. Código QR de alto contraste reservando un área física mínima intacta de 40x40 mm para garantizar su escaneo rápido.
  6. Texto de llamada a la acción: *"Escanea este código con tu cámara para avisar de una avería o reclamar dinero retenido"*.
  7. Teléfono de asistencia técnica directa configurado para esa impresión.

### RF-02: Impresión y Exportación de Etiquetas
*El sistema facilitará la producción física de etiquetas tanto para uso inmediato de oficina como para talleres o imprentas.*

* **EARS 2.1 (Evento):** Cuando el coordinador pulsa sobre la opción de imprimir etiqueta individual, el sistema deberá lanzar la interfaz de impresión del dispositivo con una plantilla adaptada a etiqueta estándar (ocultando barras de navegación, botones y elementos propios de la aplicación web).
* **EARS 2.2 (Evento/Lote A4):** Cuando el coordinador selecciona la generación por sede, el sistema deberá componer una hoja imprimible en cuadrícula para tamaño A4 con todas las máquinas activas de dicha sede, aplicando saltos de página automáticos para impedir que ninguna pegatina quede cortada entre dos páginas.
* **EARS 2.3 (Evento/Exportación Vectorial):** Cuando el coordinador elija la opción de descarga gráfica individual, el sistema deberá exportar el diseño de la etiqueta en formato vectorial estándar SVG descargable, garantizando nitidez infinita para imprenta sin requerir librerías externas de renderizado en el servidor.

### RF-03: Procesamiento del Escaneo, Aterrizaje y Confirmación
*El sistema procesará la llegada desde un código QR eliminando toda fricción de autenticación manual de sede para el informador.*

* **EARS 3.1 (Evento/Acceso Contextual Efímero):** Cuando un usuario escanea el código QR y accede al enlace, el sistema deberá resolver contextualmente la sede y abrir directamente el formulario de notificación de avería con la máquina correspondiente fijada y preseleccionada, otorgando un acceso efímero circunscrito a dicha máquina sin permitir la navegación por el catálogo de máquinas de la sede.
* **EARS 3.2 (Estado):** Mientras el formulario de reporte cargado mediante QR esté en pantalla, el campo de máquina deberá presentarse bloqueado en modo sólo lectura para evitar que el usuario asigne el reporte por error a una máquina distinta de la que tiene delante.
* **EARS 3.3 (Estado/Advertencia Sanitaria en Perecederos):** Si la máquina escaneada es de tipo alimentos perecederos (`PERISHABLE_FOOD`), el sistema deberá mostrar de forma destacada en la cabecera del formulario un aviso sanitario visible: *"⚠️ Máquina de alimentos frescos: Si los productos están templados o la máquina no enfría, notifícalo como rotura de frío para intervención crítica prioritaria"*, en estricto cumplimiento del Artículo II de la Constitución.
* **EARS 3.4 (Evento/Pantalla de Confirmación Post-Envío):** Cuando el usuario complete los datos obligatorios del reporte y envíe el formulario, el sistema deberá registrar la incidencia y mostrar de inmediato una pantalla de confirmación con el código de ticket (#TICK-XXXX), resumen del aviso, mensaje de agradecimiento y un botón de cierre, sin transferir al usuario a la vista privada de la sede.

### RF-04: Gestión de Máquinas con Incidencias Activas y Concurrencia
*El sistema mantendrá el principio constitucional de cero duplicados y segregación estricta de datos ante accesos mediante código QR.*

* **EARS 4.1 (Excepción/Blindaje de Privacidad Art. V.4):** Si el usuario escanea el código QR de una máquina que ya tiene una incidencia en estado activo (`REGISTRADA`, `ASIGNADA`, `EN_CURSO`, `PENDIENTE_REPUESTO` o `REABIERTA`), el sistema no deberá mostrar el formulario de nueva incidencia; en su lugar, mostrará un panel informativo público indicando que la avería ya está registrada, limitando los datos visibles a: código de ticket, fecha/hora de reporte, categoría y estado operativo general (*"Aviso registrado"*, *"Técnico en camino"* o *"Esperando repuesto"*). Los datos personales del técnico, teléfonos privados, notas internas de taller y costes quedan **100% ocultos e inaccesibles**.
* **EARS 4.2 (Evento/Aportación de Evidencias Adicionales):** En el caso contemplado en EARS 4.1, el sistema deberá ofrecer un botón secundario que despliegue un formulario para que el usuario pueda aportar un comentario o fotografía adicional sobre la avería existente, registrándolo en la bitácora del ticket sin crear duplicidades.
* **EARS 4.3 (Excepción/Ventana de Garantía de 48h):** Si el usuario escanea el código QR de una máquina cuya avería previa está en estado `RESUELTA` dentro del plazo de garantía de 48 horas, el sistema deberá mostrar el aviso de máquina recién reparada y ofrecer la opción directa de reabrir la incidencia si el fallo reincide.
* **EARS 4.4 (Estado/Máquinas Limpias con Historial Pasado):** Si la máquina tiene incidencias previas que se encuentran en estado `CLOSED` (cerrada definitivamente tras vencer las 48h de garantía) o `CANCELLED`, el sistema tratará la máquina como limpia y abrirá el formulario de nueva incidencia con total normalidad.
* **EARS 4.5 (Excepción/Manejo de Concurrencia y Envíos Simultáneos):** Si dos usuarios escanean el QR simultáneamente y el segundo usuario envía su formulario segundos después de que el primero haya creado la avería, el sistema deberá interceptar el conflicto de duplicidad y responder amigablemente: *"Otro usuario acaba de reportar una avería en esta máquina hace un momento (Ticket #XXXX). Hemos registrado tus observaciones en dicho ticket"*, anexando sus datos sin generar error de fallo técnico al usuario.

### RF-05: Tratamiento de Máquinas Reubicadas, Inactivas o No Encontradas
*El sistema resolverá cualquier anomalía de vinculación de forma transparente y con mensajes de cortesía.*

* **EARS 5.1 (Estado/Máquina Reubicada):** Si el enlace físico del código QR contiene un identificador de sede antiguo pero la máquina fue reasignada a una nueva sede en los registros centrales del operador, el sistema deberá resolver la máquina en su sede real vigente de forma transparente.
* **EARS 5.2 (Excepción/Máquina Inactiva o No Encontrada):** Si el enlace del código QR contiene un código de máquina inexistente, malformado o correspondiente a una máquina dada de baja o inactiva en el parque, el sistema deberá mostrar una pantalla explicativa de cortesía: *"Máquina no identificada o temporalmente fuera de servicio. Si necesitas asistencia técnica inmediata, contacta con el servicio de atención."*, ofreciendo un acceso alternativo al portal general del servicio.
* **EARS 5.3 (Excepción/URL con Sede sin Máquina):** Si la URL escaneada incluye el parámetro de sede pero omite el de máquina, el sistema abrirá el portal de sede convencional permitiendo al usuario seleccionar manualmente la máquina afectada.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Facilidad de Lectura y Contraste):** El código QR generado deberá poseer un nivel de corrección de errores estándar medio/alto (nivel M o Q) para permitir su lectura rápida incluso si la pegatina impresa presenta ligeros arañazos, manchas o desgaste ambiental típico de máquinas expendedoras.
* **RNF-02 (Velocidad de Respuesta):** El tiempo de apertura del formulario tras el escaneo no deberá superar los 2 segundos en conexiones móviles 4G/5G convencionales.
* **RNF-03 (Fidelidad de Impresión):** Las plantillas de impresión deberán respetar las proporciones físicas para etiquetas adhesivas estándar sin desbordar los márgenes de página.
* **RNF-04 (Autonomía de Dependencias y Dogma Vanilla):** El renderizado del código QR y su composición visual no dependerán de pasarelas ni servicios web externos de generación de terceros. La generación y exportación vectorial (SVG) se realizará de manera totalmente nativa y autónoma.

---

## 6. Casos Límite y Reglas de Excepción

1. **Escaneo sin cobertura de red:** Si el dispositivo no tiene conexión a internet en el momento exacto del escaneo, el navegador móvil del usuario mostrará su pantalla estándar de sin conexión; la información en texto visible en la etiqueta física (código de máquina, ubicación y teléfono de contacto) servirá de alternativa analógica para que el usuario pueda llamar por teléfono.
2. **Nombres de sede o ubicaciones extremadamente largas:** La plantilla de la pegatina aplicará ajuste proporcional de tipografía garantizando que el código QR conserve su tamaño mínimo de 40x40 mm.
3. **Escaneo de ticket resuelto hace más de 48 horas:** Se considera cerrado (`CLOSED`) y no se ofrece reapertura; se abre un ticket nuevo si se reporta un nuevo fallo.

---

## 7. Fuera de Alcance

Quedan formalmente excluidos de esta especificación:
1. Integración directa con protocolos propietarios de impresoras térmicas industriales (como Zebra ZPL o Datamax).
2. Geolocalización obligatoria por GPS del dispositivo móvil en el momento del escaneo.
3. Uso de acortadores o redirecciones externas de URLs (ej. Bitly, TinyURL).
4. Auditoría analítica de telemetría de clics y escaneos de marketing.

---

## 8. Criterios de Finalización ("Hecho Cuando")

Esta funcionalidad se considerará terminada y lista para entrega cuando:
1. El coordinador pueda previsualizar la etiqueta de cualquier máquina desde su panel, personalizar el teléfono de contacto (con opción de actualizar la sede), imprimir la etiqueta y descargarla en formato vectorial SVG.
2. El coordinador pueda emitir en un solo clic la hoja de impresión con el conjunto de etiquetas de todas las máquinas activas de una sede en cuadrícula A4 con saltos de página limpios.
3. Al escanear la URL del QR, el sistema abra directamente el formulario de reporte con la máquina bloqueada en selección y, si es de alimentos perecederos, muestre el banner destacado de advertencia sanitaria.
4. Tras enviar el reporte por QR, el usuario vea la pantalla de confirmación con el ticket (#TICK-XXXX) y mensaje de agradecimiento sin acceder al portal privado de la sede.
5. Ante una máquina con avería abierta, el escaneo muestre el estado de la incidencia activa con privacidad estricta (sin notas internas ni datos de técnicos) y permita aportar comentarios adicionales.
6. Si dos usuarios envían un reporte simultáneamente, el segundo usuario sea informado amigablemente de que su aviso fue integrado en el ticket recién creado sin recibir un error técnico.
7. Ante un código erróneo o máquina inactiva, el sistema muestre la pantalla de máquina no reconocida con el mensaje de cortesía y acceso alternativo.

---

## 9. Dudas Abiertas

*Actualmente no existen dudas abiertas. Todas las ambigüedades, casos límite, condiciones de carrera y requisitos constitucionales fueron resueltos y aprobados durante la sesión de auditoría con el Product Owner.*
