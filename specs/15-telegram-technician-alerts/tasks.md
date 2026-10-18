# Tareas · Módulo 15 · Aviso de asignación al técnico por Telegram

**Estado global:** **Implementado y certificado en local (2026-10-18).** Queda una sola casilla abierta —la entrega
real contra el API de Telegram—, bloqueada por una dependencia externa: no hay token de bot ni `chat_id` real en el
entorno de este repositorio. No es un fallo del código: la cadena completa está certificada con el transporte de
memoria y la interfaz web se verificó en navegador.

**Decisiones cerradas con el usuario (2026-10-18):**

1. El aviso por Telegram **se suma** al correo; no lo sustituye.
2. El `chat_id` se declara **desde el panel de administración**; la auto-vinculación con el bot queda para una fase
   posterior.
3. El enlace a la avería viaja **en correo y en Telegram**, resuelto por la misma autoridad.

---

## Fase 3 · Contratos y pruebas

- [x] **T-TG-01 · Certificar el canal y el transporte.** `tests/unit/TelegramTransportTest.php` (23 aserciones, verde):
  cuerpo exacto (`chat_id`, texto, `inline_keyboard`), recorte a 4.096 caracteres, clasificación `200/400/403/429/500`,
  timeout de 5 s y redacción del token en el diagnóstico.
- [x] **T-TG-02 · Certificar el destino del enlace.** `tests/unit/NotificationTrackingLinkResolverTest.php`: audiencia
  `TECHNICIAN` con y sin ticket (`/?tech=1&ticket=…`, `/?tech=1`), `TECHNICIAN_UNASSIGNED` sin enlace, URL absoluta con
  `APP_BASE_URL` y degradación declarada sin host.
- [x] **T-TG-03 · Certificar el encolado.** `tests/integration/NotificationOutboxEnqueueIntegrationTest.php` §16: con
  `chat_id` dos filas (EMAIL + TELEGRAM); sin `chat_id` una; sin token una; el payload no lleva el chat; la
  reasignación cancela la fila del saliente y la desasignación no invita al saliente.
- [x] **T-TG-04 · Certificar el despacho y los fallos.** `tests/integration/NotificationDispatcherAndSlaSweepTest.php` §9:
  `SENT` con el botón absoluto, `429` → `PENDING_RETRY` con la curva 1/5/15/60, `403` → `FAILED_PERMANENT` auditado, y
  aislamiento entre canales (ni un chat por SMTP ni un correo por Telegram). Sin tocar la red.
- [x] **T-TG-05 · Certificar la administración del `chat_id`.** `tests/integration/AdminUsersEndpointTest.php` §9:
  vinculación, persistencia, `telegram_linked` en el listado, auditoría enmascarada, formato inválido, rol no técnico,
  chat duplicado y desvinculación con `""`.
- [x] **T-TG-06 · Certificar el deep link.** `tests/unit/TechnicianDeepLinkTest.mjs` (13 aserciones, verde): abre la
  vista del técnico, resalta la parada, avisa si no está en ruta y no secuestra una sesión de sede o coordinación.

## Fase 4 · Implementación

- [x] **T-TG-07 · Esquema.** `database/supabase_migrations/004_telegram_technician_channel.sql` y réplica en
  `database/supabase_init.sql`. Verificado **en el motor real**: `notification_channel = EMAIL,WEBHOOK,TELEGRAM`,
  `users.telegram_chat_id VARCHAR(32)`, `chk_users_telegram_chat_id` y `uq_users_telegram_chat_id`; la migración figura
  aplicada en `schema_migrations` y las suites de paridad de esquema siguen verdes.
- [x] **T-TG-08 · Canal y transporte.** `NotificationChannel::TELEGRAM`, `SecretProvider::telegramBotToken()` (alias
  `TG_BOT_TOKEN`), `TelegramTransport` y su registro en `NotificationTransportRegistry::forRuntime()` con el caso «sin
  token» como fallo transitorio, nunca como excepción de composición.
- [x] **T-TG-09 · Enlace y render.** Audiencia `TECHNICIAN` en el resolutor, `ctaLabel`/`ctaUrl` en
  `RenderedNotificationMessage` y relleno en `NotificationMessageRenderer`: el correo de asignación pasa a llevar el
  mismo botón que Telegram.
- [x] **T-TG-10 · Encolado y despacho.** `queueTelegram` en el encolador con guardas de vinculación y de token, y filtro
  de canal `TELEGRAM` en la bandeja de Coordinación.
- [x] **T-TG-11 · Administración.** Campo `telegram_chat_id` en modelo, repositorio, servicio, controlador y panel;
  indicador de técnicos sin vincular y columna de estado en el directorio.
- [x] **T-TG-12 · Deep link en la vista del técnico.** `?tech=1` en `app.js`, prop `initial-ticket-code`, resalte y
  desplazamiento tras `loadRoute()`, consumidor único del parámetro y aviso de expediente ausente. Verificado además en
  navegador real: sesión de coordinación intacta, login de técnico, parada resaltada y aviso con ticket inexistente.

## Fase 5 · Verificación y trazabilidad

- [x] **T-TG-13 · Documentación.** Canal `TELEGRAM`, fila 9 del catálogo de plantillas y contrato del deep link en
  `specs/technical/notification_center_outbox_contracts.md` §3.5; campo de usuario en
  `specs/technical/admin_crud_contracts.md` §3.3; variable `VENDGUARD_TELEGRAM_BOT_TOKEN` en `README.md`.
- [x] **T-TG-14 · Cifras canónicas.** Frase de la batería actualizada en los cinco documentos vivos; `DocMetricsGuard`
  las declara `COHERENTES`.
- [ ] **T-TG-15 · Entrega real y acta de cierre.** **Bloqueada por credenciales**: falta `VENDGUARD_TELEGRAM_BOT_TOKEN`
  en el entorno y un `chat_id` real al que entregar. El recorrido está certificado hasta el borde del API (transporte,
  clasificación de respuestas, reintentos y botón) y la interfaz se verificó en navegador. Para cerrarla: declarar el
  token, vincular el chat de un técnico desde Administración → Usuarios, asignarle una avería y ejecutar una pasada de
  `bin/send_notifications.php`; la fila debe quedar en `SENT` con el botón `{APP_BASE_URL}/?tech=1&ticket=…`.
