# Especificación Funcional · Aviso de asignación al técnico por Telegram

**Módulo:** 15 (extensión del Centro de Notificaciones, Módulo 14)
**Estado:** **Aprobada e implementada (2026-10-18).** Certificada con la batería completa y verificada en navegador;
la única comprobación pendiente es la entrega real contra el API de Telegram, que exige un token de bot y un `chat_id`
vivos (ver [tasks.md](tasks.md), T-TG-15).
**Fecha:** 2026-10-18
**Rama:** `notifications`
**Precedencia normativa:** [`constitution.md`](../../constitution.md) (Art. II, III y V) y [`AGENTS.md`](../../AGENTS.md)

---

## 1. Problema y objetivo

El técnico de campo se entera de una asignación cuando abre la aplicación y sincroniza su ruta, o cuando revisa el
correo. En el móvil, el correo llega tarde, se pierde entre el ruido y obliga a teclear credenciales para llegar a la
avería. El objetivo es que **la asignación le llegue al bolsillo** —Telegram— con un enlace que le deje **en esa
avería concreta dentro de su vista de ruta**, no en la pantalla de inicio.

**Resultado observable:** asignada una avería a un técnico con Telegram vinculado, en menos de un minuto recibe un
mensaje con ticket, máquina, sede y urgencia, y un botón que, tras identificarse, abre su ruta con el expediente
resaltado.

## 2. Hechos del sistema vigente que condicionan el diseño

| Hecho verificado | Consecuencia |
| :--- | :--- |
| `notification_channel` es `ENUM('EMAIL','WEBHOOK')` | Hay que añadir el canal `TELEGRAM` de forma formal |
| `users` no tiene ninguna columna de Telegram | Hay que añadir `telegram_chat_id` |
| `TECHNICIAN_ASSIGNED` se encola con canal `EMAIL` al técnico entrante | El nuevo canal **se suma** a esa fila, no la sustituye |
| `TECHNICIAN_ASSIGNED` es audiencia `INTERNAL` y **no** lleva enlace | Hay que crear la audiencia `TECHNICIAN` en el resolutor |
| `GET /api/technician/my-route` devuelve `ticket_code` por parada | El deep link puede emparejar por código de ticket |
| `app.js` ya resuelve `?qr=` y `?site=&ticket=` | Existe patrón de deep link; el técnico es el único perfil sin él |
| Ya existe un relé Cloudflare para el canal corporativo (un solo chat) | **No** se reutiliza: el destino es personal y por técnico |

## 3. Requisitos funcionales (EARS)

* **RF-TG-01 · Canal de entrega propio.** El sistema dispondrá de un canal de notificación `TELEGRAM` en la cola
  `notification_outbox`, con su propio transporte, estado y política de reintentos. Un fallo de Telegram **no** puede
  alterar el resultado del correo ya encolado ni el de la asignación.
* **RF-TG-02 · Aviso al técnico entrante.** Cuando una incidencia pase a tener técnico asignado, el sistema encolará,
  **en la misma transacción** que el correo existente, un aviso `TECHNICIAN_ASSIGNED` por el canal `TELEGRAM`
  dirigido al `chat_id` del técnico entrante.
* **RF-TG-03 · Sin vinculación, sin aviso y sin ruido.** Si el técnico no tiene `telegram_chat_id` válido —o el bot no
  está configurado—, **no se encola ninguna fila** de Telegram. La asignación nunca falla ni se degrada por ello.
* **RF-TG-04 · Enlace a la avería.** El aviso incluirá un botón que lleve a `/?tech=1&ticket={ticket_code}` y la misma
  URL en el cuerpo de texto, para que el enlace sea utilizable aunque el cliente no muestre el botón.
* **RF-TG-05 · Misma verdad para correo y Telegram.** El destino del botón lo resolverá **una sola autoridad**
  (`NotificationTrackingLinkResolver`, nueva audiencia `TECHNICIAN`), y el correo de asignación pasará a llevar el
  mismo enlace con la misma etiqueta. Lo que viaja por un canal y por el otro no puede divergir.
* **RF-TG-06 · La desasignación no lleva enlace.** `TECHNICIAN_UNASSIGNED` se queda sin botón y sin canal Telegram: al
  técnico saliente no se le invita a abrir un expediente que ya no es suyo.
* **RF-TG-07 · Vinculación por administración.** Coordinación/Administración podrá declarar, editar y retirar el
  `telegram_chat_id` de un usuario desde el panel de usuarios, con validación de formato y rastro de auditoría del
  cambio (valor anterior y nuevo).
* **RF-TG-08 · Aterrizaje en la avería.** Al abrir el enlace, la aplicación mostrará la vista del técnico y, una vez
  cargada su ruta, **resaltará la parada citada** y la desplazará a la vista. Si la incidencia ya no está en su ruta,
  mostrará un aviso explícito en lugar de una pantalla vacía.
* **RF-TG-09 · Enlace que no es credencial.** El deep link **no** autentica: quien lo abra deberá identificarse. Un
  enlace reenviado no puede convertirse en acceso a datos de otra persona.
* **RF-TG-10 · Visibilidad de la carencia.** El panel de administración mostrará qué técnicos activos **no** tienen
  Telegram vinculado, para que la falta de avisos sea un dato visible y no una sospecha.

## 4. Reglas de negocio innegociables

1. **Un único técnico por chat.** Un `chat_id` no puede estar asignado a dos usuarios activos: lo contrario
   significaría que un técnico recibe las averías de otro.
2. **Privacidad.** El `chat_id` es dato personal: sólo se publica en el contrato de administración de usuarios
   (rol `COORDINATOR`). No viaja en payloads de Sede, Ciudadano QR ni en el JSON del Webhook corporativo.
3. **Contenido neutro.** El aviso de asignación no incluye diagnóstico, acciones, importes, ni el teléfono o correo
   del técnico. Es el payload ya sanitizado el que se envía, sin claves nuevas.
4. **Aislamiento de fallos.** Un `chat_id` inválido, un 403 del bot o un token ausente se registran como fallo de
   entrega en la bandeja; nunca como excepción hacia la asignación.
5. **Sin credenciales en claro.** El token del bot vive en el entorno (`VENDGUARD_TELEGRAM_BOT_TOKEN`), nunca en la
   base, en el repositorio ni en el payload de la cola.

## 5. Casos límite

| Caso | Comportamiento esperado |
| :--- | :--- |
| Técnico sin `chat_id` | Sólo correo; no se crea fila Telegram (RF-TG-03) |
| Bot sin token declarado | Igual que el caso anterior; el panel muestra la carencia de vinculación |
| Reasignación a otro técnico antes del envío | La fila Telegram del técnico reemplazado se cancela por obsolescencia, igual que el correo (Módulo 14, RF-07.1) |
| El técnico bloqueó el bot (403) | Fallo **permanente**: no reintentos infinitos; visible en la bandeja con su motivo |
| Límite de tasa de Telegram (429) | Fallo transitorio: entra en la curva de backoff 1/5/15/60 min |
| Mensaje > 4096 caracteres | Se recorta con marca de elipsis, sin romper el envío |
| Enlace abierto sin sesión | Vista del técnico con formulario de acceso; tras entrar, resalta la parada citada |
| Enlace abierto por un coordinador o una sede | La vista del técnico exige sesión de técnico: no se sirven datos de ruta a otro rol |
| Ticket ya resuelto o reasignado | La ruta no lo contiene: aviso «el expediente ya no está en tu ruta» |

## 6. Fuera de alcance (explícito)

* **Auto-vinculación** con el bot (`t.me/<bot>?start=<código>`): se valorará como fase posterior; exige webhook
  público del bot, secreto de cabecera, códigos de un uso y caducidad.
* **Inicio de sesión automático** desde el enlace (token bearer en URL).
* Otros avisos al técnico por Telegram (alerta de frío, reapertura, SLA): la arquitectura lo permitirá sin cambios,
  pero esta entrega cubre **sólo la asignación**.
* Comandos entrantes al bot, botones de acción (iniciar/cerrar la avería desde Telegram) y edición de mensajes ya
  enviados.
* Sustituir el relé corporativo del Módulo 14: convive con él.

## 7. Criterios de aceptación (Definition of Done)

1. Asignar una avería a un técnico con `chat_id` produce **dos** filas en `notification_outbox` —`EMAIL` y
   `TELEGRAM`— y la entrega de Telegram llega en menos de 60 s con el botón correcto.
2. El `href` del correo y el `url` del botón de Telegram son **idénticos** y apuntan a
   `{APP_BASE_URL}/?tech=1&ticket={ticket_code}`.
3. Sin `chat_id` no se crea fila Telegram y la asignación responde con éxito.
4. Reasignar antes del despacho cancela la fila Telegram del técnico reemplazado.
5. Un `chat_id` bloqueado produce `FAILED_PERMANENT` con motivo legible; un 429 produce `PENDING_RETRY` con backoff.
6. El enlace abre la vista del técnico, y tras identificarse resalta el expediente citado.
7. La batería global queda en verde y la frase canónica de cifras se actualiza en los cinco documentos vivos.
8. Ningún payload de Sede o Ciudadano QR contiene `telegram_chat_id`, y la auditoría registra los cambios de
   vinculación con valor anterior y nuevo.

## 8. Trazabilidad

| Requisito | Origen |
| :--- | :--- |
| RF-TG-01..06 | Módulo 14 (RF-05.2, RF-06.1–RF-06.4) extendido |
| RF-TG-07, RF-TG-10 | Módulo 04 (administración de usuarios) extendido |
| RF-TG-08, RF-TG-09 | Módulo 07 (vista del técnico) y Módulo 14 (enlaces de seguimiento) |
| Art. II | Prioridad y no silenciar fallos de entrega |
| Art. III.3 | Auditoría de cambios de datos de usuario |
| Art. V.4 | Contenido neutro: sin datos técnicos privados |
