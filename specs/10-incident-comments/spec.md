# ESPECIFICACIÓN FUNCIONAL: HILO DE COMENTARIOS BIDIRECCIONAL CON NOTAS INTERNAS CONFIDENCIALES

**Documento:** `specs/10-incident-comments/spec.md`  
**Referencia:** [`specs/functional/incident_comments_spec.md`](../functional/incident_comments_spec.md)  
**Estado:** Especificación Formal Aprobada y Auditada tras QA  
**Fecha:** Octubre 2026  
**Autor:** Antigravity (Advanced Agentic Coding) & Coordinación de Operaciones  
**Proyecto:** VendGuard · Gestor de Incidencias de Vending  
**Alineación Constitucional:** Artículos I al VII de [constitution.md](../../constitution.md)  

---

## 1. Contexto y Objetivo

### 1.1. Contexto Operativo
Durante la resolución de averías en máquinas de vending, la comunicación entre la sede cliente (conserjes, recepcionistas, administradores del edificio) y el personal técnico de campo resulta determinante para acelerar la localización física de las máquinas ("está en la 3ª planta junto a los ascensores"), documentar síntomas progresivos ("ahora sale humo del monedero") y coordinar accesos fuera de horario habitual.

Hasta el momento, la comunicación se encontraba fragmentada o restringida:
* El Responsable de Sede solo podía adjuntar comentarios durante el reporte inicial o a través de formularios genéricos sin una visión clara de diálogo con el servicio técnico.
* El Técnico de Campo en su interfaz móvil carecía de un canal interactivo para consultar las aclaraciones de la sede o formular preguntas sobre el terreno sin recurrir a llamadas telefónicas personales.
* Las anotaciones técnicas de taller (como advertencias sobre desgaste crítico de placas electrónicas, diagnósticos preliminares de fallos costosos o notas confidenciales de coordinación) no contaban con un canal protegido dentro del expediente, corriendo el riesgo de filtrarse al cliente o perderse en notas manuales externas.

### 1.2. Objetivo
Establecer un **Canal Unificado de Conversación Bidireccional** dentro del expediente de cada avería que permita:
1. Un diálogo transparente y fluido entre la Sede Cliente y el Servicio Técnico (Comentarios Públicos).
2. Un canal paralelo y estrictamente protegido de anotaciones confidenciales entre el Técnico de Campo y el Coordinador de Operaciones (Notas Internas de Taller), garantizando que el cliente jamás acceda a datos internos ni sospeche su existencia (Art. V.4).
3. Blindaje riguroso de la privacidad del personal de campo frente al cliente (Art. V.4).
4. Aporte continuo de evidencias fotográficas complementarias sin sobreescribir la imagen original del reporte y con validación binaria estricta de seguridad en el servidor (Art. V.5).
5. Inmutabilidad histórica absoluta de todas las comunicaciones y archivos para respaldar la auditoría operativa (Art. III).

---

## 2. Usuarios y Perfiles Implicados

| Perfil / Actor | Nivel de Visibilidad | Permisos de Publicación | Identidad Visible en el Hilo |
| :--- | :--- | :--- | :--- |
| **🏢 Responsable de Sede** | **Exclusivamente Comentarios Públicos.** No tiene visibilidad ni conocimiento de la existencia de notas internas. | Publica **mensajes públicos** dirigidos al equipo técnico y adjunta fotos complementarias. | Se identifica corporativamente como *"Responsable de Sede · [Nombre del Centro]"* (o cargo especificado). |
| **📱 Técnico de Ruta / Campo** | **Comentarios Públicos y Notas Internas.** | Publica **mensajes públicos** o **notas internas de taller** (con *"Nota Interna"* preseleccionada por defecto). Adjunta fotos in situ. | Ante la Sede: *"Servicio Técnico Oficial (Operador #[Código])"*. Ante Coordinación y otros técnicos: Nombre y apellidos reales. |
| **📊 Coordinador de Operaciones** | **Comentarios Públicos y Notas Internas.** | Publica **mensajes públicos** o **notas internas de taller** (con *"Nota Interna"* preseleccionada por defecto). Adjunta fotos. | Ante la Sede: *"Coordinación Central de Operaciones"*. Ante el equipo técnico: Nombre y cargo real. |

*(Nota: Los consumidores finales anónimos que reportaron mediante escaneo de código QR público carecen de acceso al hilo de conversación del edificio; su pantalla de seguimiento solo muestra el estado general del ticket sin exponer el diálogo interno).*

---

## 3. Historias de Usuario

### HU-01: Comunicación Ágil y Guiada desde la Sede
> **Como** Responsable de Sede,  
> **quiero** abrir el hilo de conversación de mi avería activa desde la tarjeta de la máquina en mi portal, leer las respuestas oficiales del servicio técnico y enviar aclaraciones o fotos adicionales,  
> **para** facilitar el acceso al técnico y resolver la incidencia lo antes posible sin depender de llamadas telefónicas.

### HU-02: Coordinación In Situ del Técnico en Movilidad
> **Como** Técnico de Campo en ruta,  
> **quiero** ver los comentarios aportados por la sede en la tarjeta de mi parada y responderles directamente con un mensaje público desde mi teléfono identificándome con mi código oficial,  
> **para** avisar de mi llegada a recepción o pedir detalles de acceso sin exponer mi número o nombre personal.

### HU-03: Registro Seguro de Notas Internas Confidenciales
> **Como** Técnico de Campo o Coordinador de Operaciones,  
> **quiero** redactar una nota técnica clasificada como "Nota Interna de Taller" (opción activa por defecto),  
> **para** compartir diagnósticos delicados, advertencias de componentes o pautas internas con el equipo técnico garantizando que la sede cliente nunca tenga acceso a dicha información.

### HU-04: Supervisión Centralizada y Mediación en Triaje
> **Como** Coordinador de Operaciones,  
> **quiero** inspeccionar el hilo completo (diferenciando visualmente lo público de lo interno) en la ficha de triaje de la incidencia,  
> **para** hacer seguimiento del diálogo, mediar ante el cliente y registrar directrices de reparación para el técnico.

### HU-05: Señalización Visual de Conversación Activa sin Fugas de Información
> **Como** Responsable de Sede o Técnico de Campo,  
> **quiero** ver un indicador con el número de comentarios en la tarjeta de la máquina o parada,  
> **para** saber si hay mensajes nuevos pendientes de lectura, garantizando que el contador de la sede contabilice únicamente mensajes públicos.

### HU-06: Gestión de Históricos Extensos y Navegación Fluida
> **Como** usuario participante en una incidencia compleja,  
> **quiero** que el hilo cargue de inmediato los mensajes más recientes con el scroll posicionado al final y me permita cargar mensajes anteriores bajo demanda,  
> **para** comunicarme con rapidez sin sufrir lentitud por el volumen histórico acumulado.

---

## 4. Requisitos Funcionales (Notación EARS)

### Módulo RF-01: Puntos de Acceso, Contadores Segregados y Navegación
* **RF-01.1 [EARS - Ubicuo]:**  
  El sistema DEBE mostrar una insignia interactiva con icono de conversación y contador numérico en:
    * La tarjeta de máquina activa en el Portal del Responsable de Sede, contabilizando **estrictamente los comentarios públicos** (`is_internal = false`).
    * La tarjeta de parada de intervención en la vista móvil "Mi Ruta" del Técnico de Campo, contabilizando la totalidad de mensajes (públicos e internos).
    * La fila y ficha de triaje del Coordinador de Operaciones, contabilizando la totalidad de mensajes (públicos e internos).
* **RF-01.2 [EARS - Dirigido por eventos]:**  
  CUANDO el usuario pulse sobre la insignia o el botón de conversación, el sistema DEBE abrir un modal dedicado y responsivo de conversación, cargando inicialmente los **50 mensajes más recientes** ordenados cronológicamente y posicionando el scroll automáticamente sobre el mensaje más reciente.
* **RF-01.3 [EARS - Opcional]:**  
  DONDE la incidencia acumule más de 50 comentarios en su histórico, el sistema DEBE mostrar un botón en la parte superior del hilo (*"Cargar mensajes anteriores"*) que permita recuperar bloques previos de mensajes sin perder la posición actual de lectura.
* **RF-01.4 [EARS - Ubicuo]:**  
  El sistema DEBE mostrar en la cabecera del modal el código único de la avería, el modelo de máquina, la sede y el estado operativo actual.

---

## Módulo RF-02: Visualización Segregada y Privacidad del Técnico (Art. V.4)
* **RF-02.1 [EARS - Estado]:**  
  MIENTRAS el usuario autenticado sea un Responsable de Sede, el sistema DEBE filtrar de forma estricta y absoluta la visualización, mostrando única y exclusivamente los comentarios públicos, sin presentar ningún elemento visual, etiqueta o mensaje que delate la existencia de notas internas.
* **RF-02.2 [EARS - Estado]:**  
  MIENTRAS los mensajes sean visualizados por un Responsable de Sede, el sistema DEBE presentar la autoría del técnico bajo el alias oficial *"Servicio Técnico Oficial (Operador #[Código])"*, ocultando su nombre y apellidos personales para proteger su privacidad (Art. V.4).
* **RF-02.3 [EARS - Estado]:**  
  MIENTRAS el usuario autenticado sea un Técnico de Campo o un Coordinador de Operaciones, el sistema DEBE mostrar tanto los comentarios públicos como las notas internas de taller, presentando la identidad nominal completa de los compañeros del equipo técnico.
* **RF-02.4 [EARS - Ubicuo]:**  
  El sistema DEBE destacar visualmente las notas internas frente a los comentarios públicos mediante un distintivo explícito de candado, etiqueta de **"Nota Interna de Taller (Confidencial)"** y fondo cromático diferenciado en tono ámbar de advertencia técnica.

---

## Módulo RF-03: Redacción, Límites y Selector de Privacidad Seguro
* **RF-03.1 [EARS - Ubicuo]:**  
  El sistema DEBE exigir que todo comentario o nota técnica contenga entre **5 y 1.000 caracteres descriptivos**, bloqueando el botón de envío si el texto es inferior a 5 caracteres o si supera los 1.000 caracteres, y mostrando un contador reactivo de caracteres restantes.
* **RF-03.2 [EARS - Estado]:**  
  MIENTRAS el usuario autenticado sea un Responsable de Sede, el sistema DEBE publicar automáticamente el mensaje como comentario público, sin ofrecer ningún selector de privacidad.
* **RF-03.3 [EARS - Estado]:**  
  MIENTRAS el usuario autenticado sea un Técnico de Campo o Coordinador, el sistema DEBE proporcionar un selector reactivo con dos opciones excluyentes, **teniendo preseleccionada por defecto la opción "Nota Interna de Taller"** para prevenir filtraciones accidentales al cliente:
    * **"Nota Interna de Taller"** (Confidencial: visible únicamente para Técnicos y Coordinadores). [PREDETERMINADA]
    * **"Mensaje para Sede"** (Público: visible para todos los participantes).
* **RF-03.4 [EARS - Dirigido por eventos]:**  
  CUANDO el usuario envíe un mensaje válido, el sistema DEBE incorporarlo de forma inmediata al hilo de conversación, actualizar el contador numérico de la tarjeta correspondiente y limpiar el formulario de entrada sin cerrar la ventana de conversación.

---

## Módulo RF-04: Evidencias Fotográficas y Seguridad en Servidor (Art. III y V.5)
* **RF-04.1 [EARS - Opcional]:**  
  DONDE el usuario decida adjuntar una fotografía a su comentario, el sistema DEBE permitir seleccionar un archivo de imagen desde el dispositivo o capturarlo con la cámara móvil.
* **RF-04.2 [EARS - Ubicuo]:**  
  El sistema DEBE verificar en el servidor el tamaño del archivo ($\le 5\text{ MB}$) y validar obligatoriamente su tipo MIME real y su cabecera binaria (*magic bytes*) asegurando que pertenezca exclusivamente a formatos gráficos admitidos (`.jpg`, `.jpeg`, `.png`, `.webp`) (Art. V.5).
* **RF-04.3 [EARS - Ubicuo]:**  
  El sistema DEBE almacenar los archivos fotográficos en disco con nombres hash criptográficos únicos e inmutables (Art. III), garantizando que nunca se sobreescriban ni se eliminen físicamente.
* **RF-04.4 [EARS - Ubicuo]:**  
  El sistema DEBE mostrar las fotografías adjuntas como miniaturas integradas en el mensaje con visor modal ampliable; si una imagen no puede cargarse por fallo de red o archivo inaccesible, el sistema DEBE mostrar un recuadro estético de sustitución (*"Evidencia gráfica no disponible"*) sin quebrar la estructura del mensaje.
* **RF-04.5 [EARS - Estado]:**  
  MIENTRAS se encuentre en proceso la subida de una fotografía, el sistema DEBE deshabilitar el botón de envío y mostrar un indicador visual de carga (rueda de progreso) para evitar pulsaciones duplicadas en conexiones móviles lentas.

---

## Módulo RF-05: Ciclo de Vida y Sellado en Modo Solo Lectura (Art. III)
* **RF-05.1 [EARS - Estado]:**  
  MIENTRAS la incidencia se encuentre en un estado operativo activo (`REPORTED`, `ASSIGNED`, `IN_PROGRESS` o `PENDING_PARTS`), el sistema DEBE mantener habilitado el formulario de envío de nuevos comentarios para los actores autorizados.
* **RF-05.2 [EARS - Estado]:**  
  MIENTRAS la incidencia se encuentre resuelta (`RESOLVED`) dentro de la ventana de garantía de 48 horas (Art. V.6), el sistema DEBE permitir que la sede, el técnico y el coordinador sigan aportando comentarios de seguimiento.
* **RF-05.3 [EARS - Estado]:**  
  MIENTRAS la incidencia se encuentre en estado cerrado definitivo (`CLOSED`) o cancelada (`CANCELLED`), el sistema DEBE sellar el hilo de conversación en **modo estrictamente de solo lectura**, deshabilitando el formulario de envío y mostrando un aviso de *"Expediente archivado: conversación sellada por auditoría"*.
* **RF-05.4 [EARS - Estado]:**  
  MIENTRAS la incidencia se encuentre reabierta en garantía (`REOPENED`) y **sin técnico asignado** (desasignación obligatoria de la reapertura, EARS 9.1), el sistema DEBE conceder al técnico que intervino previamente en el expediente acceso de **solo lectura** al hilo, con las mismas reglas de proyección de su canal (comentarios públicos y notas internas de taller), bloqueando la publicación hasta que coordinación le reasigne el expediente. El acceso de lectura no se concede a ningún otro técnico: leer el hilo no constituye responsabilidad activa sobre la avería (Art. V · Un único técnico responsable activo por incidencia).

---

## Módulo RF-06: Inviolabilidad Histórica y Auditabilidad (Art. III)
* **RF-06.1 [EARS - Ubicuo]:**  
  Los comentarios registrados en el sistema son permanentes y de solo adición (*Append-Only*). El sistema NO DEBE proporcionar ninguna opción de edición o modificación de mensajes ya publicados.
* **RF-06.2 [EARS - Ubicuo]:**  
  Queda terminantemente prohibido el borrado físico o eliminación de comentarios publicados. Cualquier aclaración o corrección por parte de un usuario DEBE realizarse mediante la publicación de un nuevo comentario en el hilo.
* **RF-06.3 [EARS - Ubicuo]:**  
  Toda publicación de comentario o nota interna DEBE generar un registro inmutable en la bitácora de auditoría (`audit_log`), documentando el identificador del usuario, su rol, el código del ticket, la marca temporal exacta y si el mensaje fue público o interno.

---

## Módulo RF-07: Resiliencia ante Fallos de Red y Errores
* **RF-07.1 [EARS - Excepcional]:**  
  SI se produce un fallo de conexión móvil o un error del servidor (HTTP 4xx/5xx) al intentar enviar un comentario, el sistema DEBE mostrar un mensaje descriptivo de error, mantener el modal abierto y **retener íntegro el texto redactado y la fotografía adjunta** para permitir un reintento manual inmediato sin pérdida de datos.
* **RF-07.2 [EARS - Excepcional]:**  
  SI una incidencia es cerrada automáticamente por el cron de garantía mientras el usuario redacta un mensaje, el sistema DEBE rechazar el envío informando de que el expediente ha quedado sellado, permitiendo copiar el texto escrito antes de cerrar el modal.

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 (Segregación Absoluta en Servidor - Art. V.4):**  
  Las respuestas de la API destinadas al Responsable de Sede bajo ninguna circunstancia contendrán notas clasificadas como internas, ni campos, metadatos o recuentos que permitan deducir su existencia. El filtrado DEBE realizarse en la capa de datos del backend antes de serializar el JSON.
* **RNF-02 (Rendimiento y Carga Rápida):**  
  La apertura del modal y la renderización de los últimos 50 mensajes de una avería DEBE completarse en un tiempo inferior a **250 milisegundos**.
* **RNF-03 (Ergonomía Móvil y Escritorio):**  
  La interfaz del modal DEBE ser completamente responsiva (*mobile-first*), diseñada para un uso cómodo con una sola mano en los smartphones de los técnicos de ruta y adaptada a resoluciones de escritorio para la sede y coordinación.
* **RNF-04 (Tokens Visuales del Sistema de Diseño):**  
  La interfaz utilizará los tokens de diseño consolidados (azul corporativo `#2560ff` para acciones principales, bordes de 4px/8px, fondo canvas `#f9fafb`, alertas ámbar `#f8b60f` para notas internas confidenciales y gris neutro `#e5e7eb` para bocadillos de mensaje).
* **RNF-05 (Seguridad en Almacenamiento Multimedia - Art. III y V.5):**  
  Las fotografías adjuntas se almacenarán en directorios protegidos con nombres hash únicos e inmutables, sin acceso a ejecución de scripts y con verificación de cabecera binaria real en el backend.
* **RNF-06 (Prevención de Pérdida de Datos en Borrador):**  
  Si el usuario pulsa la tecla `Escape` o hace clic fuera del modal mientras tiene texto en edición en el formulario de comentario, el sistema DEBE solicitar confirmación explícita antes de cerrar para evitar pérdidas accidentales de información.

---

## 6. Casos Límite y Comportamiento ante Errores

1. **Avería sin comentarios previos:**  
   Al abrir el modal, el sistema muestra un estado vacío estético y limpio: *"Aún no hay mensajes en esta incidencia. Inicie la conversación con el equipo técnico."*
2. **Reasignación técnica con comentarios previos:**  
   Si una incidencia cambia de técnico asignado, los comentarios históricos conservan la autoría del técnico que los redactó originariamente; el nuevo técnico asignado puede consultar el historial íntegro y participar en la conversación activa.
3. **Intentos de envío con espacios en blanco o longitud inválida:**  
   El botón de envío se mantiene deshabilitado mientras el texto no alcance los 5 caracteres reales o si excede los 1.000 caracteres, con indicación visual del contador.
4. **Subida de archivo corrupto o no admitido:**  
   El servidor valida la cabecera real del archivo; si no es un gráfico válido o excede 5 MB, responde con error HTTP 422 descriptivo sin almacenar ningún archivo residual en disco.
5. **Concurrencia de mensajes simultáneos:**  
   Si dos usuarios envían mensajes casi simultáneamente, el sistema los ordena inequívocamente por su marca temporal de registro en servidor (`created_at`) con precisión de milisegundos.
6. **Reporte iniciado por código QR Ciudadano:**  
   Si la avería se originó por escaneo QR de un consumidor anónimo, el seguimiento público por QR no muestra el hilo de conversación; el diálogo se reserva exclusivamente a la Sede (autenticada por código de centro) y al servicio técnico.
7. **Reapertura en garantía sin técnico asignado:**  
   Al reabrir, el expediente se desasigna y regresa a triaje de coordinación, por lo que desaparece de la ruta del técnico. Quien intervino previamente (acreditado por el historial inmutable de estados, `incident_history`) conserva el hilo en **modo de solo lectura**: consulta íntegra con su proyección habitual y sin formulario de envío, con el aviso *"Expediente reabierto pendiente de reasignación: el historial se mantiene consultable"*. Un técnico que no intervino en el expediente recibe `403 NOT_ASSIGNED_TO_TECHNICIAN` tanto en lectura como en publicación. La publicación se restablece únicamente cuando coordinación reasigna el expediente.

---

## 7. Fuera de Alcance

Para mantener la simplicidad y el foco en el valor operativo inmediato:
1. **Canales de chat en tiempo real por WebSockets:** La actualización se basa en peticiones bajo demanda y refresco al abrir el modal o enviar mensajes.
2. **Edición o eliminación de mensajes emitidos:** Los comentarios son permanentes y de solo adición (*Append-Only*, Art. III).
3. **Mensajería directa privada 1 a 1 entre usuarios:** No es un servicio de chat general; todos los mensajes pertenecen estrictamente al expediente de una avería concreta.
4. **Confirmaciones de lectura personalizadas ("Doble check azul"):** No se monitoriza la lectura individual de cada participante.
5. **Participación de usuarios anónimos de códigos QR:** Los consumidores finales no participan en la conversación interna del edificio.

---

## 8. Criterios de Finalización

La especificación se considerará cumplida cuando:
1. La Sede pueda abrir el hilo desde su tarjeta de máquina, consultar los mensajes públicos (con el técnico firmado bajo su código oficial de operador) y enviar comentarios con/sin foto de forma fluida.
2. El Técnico pueda abrir el hilo desde su tarjeta de parada móvil, leer los mensajes públicos y redactar notas internas (preseleccionadas por defecto) o mensajes públicos para la sede.
3. El Coordinador pueda inspeccionar la totalidad de los mensajes públicos e internos y participar en ambas modalidades.
4. Las pruebas de seguridad certifiquen al 100% que ningún endpoint o respuesta dirigida a la Sede expone datos, metadatos ni contadores de notas internas (Art. V.4).
5. Las fotografías adjuntas se validen obligatoriamente en el servidor por cabecera binaria real y tamaño $\le 5\text{ MB}$, guardándose de forma inmutable en disco (Art. III y V.5).
6. El hilo quede sellado en modo solo lectura en incidencias en estado `CLOSED` o `CANCELLED`.
7. Se verifiquen al 100% los requisitos funcionales RF-01 a RF-07 y no funcionales RNF-01 a RNF-06 sin ninguna regresión en el sistema existente.

---

## 9. Dudas Abiertas

* **Ninguna duda abierta.** Todas las definiciones operativas, roles, visibilidad segregada, inmutabilidad, identidad protegida y límites de adjuntos han sido formalmente acordadas y validadas.
