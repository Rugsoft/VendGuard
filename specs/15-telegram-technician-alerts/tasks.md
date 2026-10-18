# Tareas · Módulo 15 · Aviso de asignación al técnico por Telegram

**Estado global:** **Cerrada y certificada (2026-10-18).** Las quince tareas están completas: la cadena entera
—vinculación en administración, encolado, despacho y entrega real contra `api.telegram.org`— quedó verificada de punta
a punta, con la fila de la cola en `SENT` y el botón absoluto. El acta de cierre está en T-TG-15.

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
- [x] **T-TG-15 · Entrega real y acta de cierre.** Ejecutada el 2026-10-18 contra el API de Telegram en vivo, con el
  token declarado en el entorno del proceso (`VENDGUARD_TELEGRAM_BOT_TOKEN`) y `APP_BASE_URL` fijado:
  * `getMe` → bot **@vendguard_alertas_bot**, `ok: true`.
  * Vinculación del chat real por el endpoint de administración (`PATCH /api/coordinator/users/2` con
    `telegram_chat_id`) → `HTTP 200`, `telegram_linked = true`; el rastro de auditoría lo guarda enmascarado y el chat
    completo no viaja en el payload.
  * Asignación de la avería `TG-CLOSE-855CA2` al técnico 2 por `PATCH …/assign` → `HTTP 200`: se encolan **dos** filas,
    `EMAIL` y `TELEGRAM` (el aviso se suma al correo, decisión 1).
  * Una pasada de `bin/send_notifications.php` → fila `#10061` del canal `TELEGRAM` en **`SENT`**, `attempts = 1`,
    `last_error = NULL`, `processed_at` escrito; el correo hermano queda en `PENDING_RETRY` porque el entorno local no
    declara SMTP (`UnconfiguredEmailTransport`), no por el canal nuevo.
  * Botón entregado, renderizado con el cableado de producción (`NotificationTrackingLinkResolver` con
    `AppPublicUrl::fromEnvironment()`): **«Abrir la avería» → `http://127.0.0.1:8000/?tech=1&ticket=TG-CLOSE-855CA2`**,
    idéntico al esperado; `relative_tracking_links_count = 0`, sin degradación que declarar.
  * El destino se publica como `***1993` en todas las trazas: el `chat_id` no viaja en `payload_json` ni en auditoría.
  * La evidencia se capturó durante la pasada: el restablecimiento de semillas que la batería ejecuta al terminar
    retira después el incidente y la fila, igual que cualquier dato operativo de prueba. El acta son los valores aquí
    transcritos, no una fila que se pueda volver a consultar días más tarde.

---

## Nota de cierre · incidente de despliegue corregido (2026-10-18)

El primer despliegue en Render abortó en el paso 3/6 con `SQLSTATE 42703: column "telegram_chat_id" does not exist`: el
DDL base creaba el índice único antes de que la migración 004 añadiera la columna, y `CREATE TABLE IF NOT EXISTS users`
no añade columnas a una tabla que ya existe. El índice queda ahora envuelto en la misma guarda `undefined_column` que ya
protegía a `idx_users_on_call` (`database/supabase_init.sql`), y `SchemaConvergenceParityTest` exige las cuatro
migraciones en el libro. Corregido en `fab919e`, tras reproducir el fallo y simular el arranque completo sobre un
esquema legado.
