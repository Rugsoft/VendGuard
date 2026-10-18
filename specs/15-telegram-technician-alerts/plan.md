# Plan Técnico · Aviso de asignación al técnico por Telegram (Módulo 15)

**Estado:** **Cerrado e implementado (2026-10-18)** — entrega real certificada; ver [tasks.md](tasks.md)
**Spec funcional:** [spec.md](spec.md)
**Contratos afectados:** `specs/technical/notification_center_outbox_contracts.md`, `specs/technical/api_contracts.md`
**Precedencia:** [`constitution.md`](../../constitution.md) · [`AGENTS.md`](../../AGENTS.md)

---

## 1. Decisiones de diseño y alternativas descartadas

| Decisión | Motivo | Alternativa descartada y por qué |
| :--- | :--- | :--- |
| Canal `TELEGRAM` de primera clase en `notification_channel` | Cada transporte ya se selecciona por canal (`NotificationTransportRegistry`); el destino personal no cabe en la URL única del canal corporativo | **Reutilizar `WEBHOOK`** añadiendo `?chat_id=` al relé: cero migración, pero mezcla dos destinos bajo un canal, el relé no puede pintar botón y la bandeja mentiría sobre el canal usado |
| `users.telegram_chat_id` como columna | El destinatario ya se persiste como texto en `notification_outbox.recipient`; sólo falta saber cuál | Tabla `user_telegram_links` aparte: más piezas para un dato escalar |
| Transporte nativo en PHP | Es el patrón del módulo (`NativeHttpsWebhookTransport`): HTTPS con `stream_context`, timeout 5 s, clasificación tipada | Ampliar el Worker de Cloudflare: despliegue externo obligatorio y un segundo lugar donde vive la regla |
| Enlace idéntico en correo y Telegram | Una sola autoridad resuelve el destino (RF-TG-05) | Sólo Telegram con enlace: dos reglas de destino que pueden divergir |
| Sin fila si no hay vinculación | La bandeja no debe llenarse de envíos imposibles; la carencia se ve en el panel | Encolar en `FAILED_PERMANENT`: ruido permanente por configuración, no por fallo real |
| Deep link sin token de sesión | Un enlace reenviado sería una credencial | Auto-login con token firmado de un uso: comodidad alta, riesgo de filtración alto |

---

## 2. Esquema de datos

### 2.1. Migración `004_telegram_technician_channel.sql`

```sql
-- 1. Canal de entrega personal del técnico.
--    PostgreSQL 12+ admite ALTER TYPE ... ADD VALUE dentro de una transacción,
--    con una condición: el valor nuevo NO puede usarse en la misma transacción.
--    Por eso esta migración sólo declara el valor y la columna, y no escribe
--    ninguna fila TELEGRAM.
ALTER TYPE notification_channel ADD VALUE IF NOT EXISTS 'TELEGRAM';

-- 2. Identidad Telegram del usuario (dato personal, opcional).
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS telegram_chat_id VARCHAR(32);

-- 3. Formato: chat de usuario (positivo) o de grupo/supergrupo (negativo).
DO $do$
BEGIN
    ALTER TABLE users
        ADD CONSTRAINT chk_users_telegram_chat_id
        CHECK (telegram_chat_id IS NULL OR telegram_chat_id ~ '^-?[0-9]{5,20}$');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- 4. Un chat pertenece a un solo usuario: si dos técnicos compartieran chat_id,
--    uno recibiría las averías del otro (regla 1 de la spec).
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_telegram_chat_id
    ON users (telegram_chat_id)
    WHERE telegram_chat_id IS NOT NULL AND deleted_at IS NULL;
```

**Réplica obligatoria en la foto de creación:** `database/supabase_init.sql` (línea del `CREATE TYPE notification_channel`
y definición de `users`) para que una base nueva y una migrada converjan. Las pruebas `SchemaConvergenceParityTest` y
`CloudDeploySchemaParityTest` fallarán si se toca una y no la otra: es la red que impide la deriva.

**Riesgo verificado en la tarea de ejecución:** el ejecutor `PostgresMigrationRunner` envuelve la migración en una
transacción. Hay que **probar en el motor real** que el `ALTER TYPE` entra y que la columna queda utilizable en la
pasada siguiente; si el motor de certificación no lo admitiera, la alternativa es declarar el valor en la foto de
creación y aplicar la columna en una migración separada sin el `ALTER TYPE`.

### 2.2. Sin cambios en `notification_channel_settings`

El canal corporativo (Webhook) mantiene su configuración en base. El token del bot es **entorno**, no configuración de
negocio: no se añaden columnas ni se exponen credenciales en tablas.

---

## 3. Contratos

### 3.1. Canal y transporte

```php
enum NotificationChannel: string {
    case EMAIL = 'EMAIL';
    case WEBHOOK = 'WEBHOOK';
    case TELEGRAM = 'TELEGRAM';   // nuevo · label: 'Telegram del Técnico'
}
```

`NotificationTransportInterface` no cambia. `TelegramTransport` implementa:

```php
public function channel(): NotificationChannel;              // TELEGRAM
public function deliver(string $recipient, RenderedNotificationMessage $message): TransportDeliveryResult;
```

* **Destinatario:** el `chat_id` (`notification_outbox.recipient`).
* **Petición:** `POST https://api.telegram.org/bot{TOKEN}/sendMessage`, JSON
  `{ chat_id, text, disable_web_page_preview: true, reply_markup?: { inline_keyboard: [[{ text, url }]] } }`.
  El host es una **constante del código**, no un dato de la fila: no hay superficie SSRF nueva.
* **Seam de prueba:** constructor `TelegramTransport(?callable $httpPost = null)`; en producción usa
  `stream_context_create` + `file_get_contents` con `ssl` verificado, `timeout` 5 s y `follow_location: 0`.
  La batería inyecta un doble y **no toca la red**.
* **Clasificación:** `2xx` éxito; `429` (respetando `retry_after` sólo como diagnóstico) y `5xx`/red/TLS transitorios;
  `400`, `401`, `403` (bot bloqueado o chat inexistente) y `404` permanentes.
* **Longitud:** texto recortado a 4.096 caracteres con elipsis (límite de Telegram).
* **Token:** `VENDGUARD_TELEGRAM_BOT_TOKEN` (alias `TG_BOT_TOKEN`, el mismo nombre del relé, para no duplicar valores),
  leído por `SecretProvider::telegramBotToken(): ?string`. **Ausente no es fatal**: el canal simplemente no está
  disponible (`UnconfiguredTelegramTransport` → fallo transitorio si alguna fila llegara a existir).

### 3.2. Renderizado y botón

`RenderedNotificationMessage` gana dos campos opcionales (`?string $ctaLabel`, `?string $ctaUrl`) que el renderizador
rellena desde el borrador (`cta_label` + URL ya resuelta). SMTP y Webhook los ignoran; Telegram los usa. El JSON del
Webhook **no** cambia.

`NotificationTrackingLinkResolver` gana la audiencia:

```php
public const AUDIENCE_TECHNICIAN = 'TECHNICIAN';
// TECHNICIAN_ASSIGNED  → '/?tech=1&ticket=' . rawurlencode($ticketCode)   (o '/?tech=1' sin ticket)
// TECHNICIAN_UNASSIGNED → null (sin botón, RF-TG-06)
```

Con `APP_BASE_URL` declarada, el botón del correo y el de Telegram salen absolutos; sin ella, la degradación sigue
declarándose en `relative_tracking_links_count` como hoy (T-NOTIF-17), ahora también para este canal.

### 3.3. Encolado

En `NotificationOutboxEnqueuerService::enqueueAssignment`, junto al `queueEmail` del técnico entrante:

```php
$chatId = $incoming?->getTelegramChatId();          // válido por CHECK + validación de servicio
if ($chatId !== null && $this->telegramConfigured()) {
    $count += $this->queueTelegram(TECHNICIAN_ASSIGNED, $chatId, ROLE_TECHNICIAN, $incomingId, $after, $payload) ? 1 : 0;
}
```

* `queueTelegram` reutiliza el **mismo** `queue()` (sanitización + render + persistencia), con `channel = TELEGRAM`.
* `telegramConfigured()` consulta el registro de transportes (`has(TELEGRAM)`) — la misma composición que despacha, de
  modo que no se encola lo que no se podrá entregar.
* **Obsolescencia:** `NotificationObsolescencePolicy` decide por plantilla + `recipient_user_id`, sin conocer el canal;
  la fila Telegram del técnico reemplazado se cancela igual que el correo. No se toca esa clase.
* **Sin dedup key nuevo:** se mantiene el comportamiento actual de la asignación (la obsolescencia cubre el reemplazo).

### 3.4. Administración de usuarios

`POST/PATCH /api/coordinator/admin/users` aceptan y publican `telegram_chat_id`:

* Validación de forma (`^-?[0-9]{5,20}$`), de unicidad (índice parcial) y de rol (sólo `TECHNICIAN` puede tenerlo: se
  ignora/ rechaza para otros roles).
* Auditoría `USER_UPDATED` con diff `telegram_chat_id: anterior → nuevo`, enmascarado en los metadatos
  (`***1234`) para no volcar el identificador completo en el rastro.
* La respuesta del listado incorpora `telegram_chat_id` y un booleano derivado `telegram_linked`.
* UI: campo en alta/edición sólo visible para rol técnico, columna de estado («Vinculado» / «Sin vincular») y contador
  de técnicos activos sin vincular (RF-TG-10).

### 3.5. Deep link de la vista del técnico

| Aspecto | Contrato |
| :--- | :--- |
| URL | `/?tech=1&ticket=INC-2026-XXXX` |
| `app.js` | `?tech=1` fuerza `currentView = 'technician'` **antes** de restaurar sesión; el enlace manda sobre una sesión previa de otro perfil |
| Prop | `TechnicianRouteView` recibe `initial-ticket-code` |
| Aplicación | tras `loadRoute()`, busca por `ticket_code` (comparación insensible a mayúsculas), desplaza la tarjeta (`scrollIntoView`) y la resalta ~2,5 s; marca el enlace como consumido para no repetirlo al re-renderizar |
| Sin sesión | Se muestra el formulario de acceso del propio técnico; tras entrar se aplica el enlace (el parámetro vive en la URL) |
| Rol distinto | La API de ruta responde con el rol del token: un coordinador no obtiene ruta de técnico. No se sirven datos por el solo hecho de tener el enlace |
| No encontrado | Aviso «El expediente ya no está en tu ruta» con acción para recargar |

Estética: reutiliza los tokens de `docs/design.md` (azul eléctrico para el resalte, borde hairline, sin geometría
inventada). Sin literales hex nuevos: el trinquete de deuda de tokens debe seguir en su cifra actual.

---

## 4. Privacidad y seguridad

| Dato | Dónde puede aparecer | Dónde no puede aparecer |
| :--- | :--- | :--- |
| `telegram_chat_id` | API de administración (COORDINATOR), fila `notification_outbox.recipient` | Payloads de Sede/Ciudadano QR, JSON del Webhook, bandeja pública, logs |
| Token del bot | Entorno del proceso | Base de datos, repositorio, payload, logs, respuestas HTTP |
| Código de ticket | Correo y mensaje al técnico asignado | Cualquier endpoint sin sesión de técnico |

* El token nunca se registra: los mensajes de error del transporte se depuran igual que en el Webhook.
* `NotificationPayloadSanitizer` no cambia: el aviso reutiliza el payload neutro ya sanitizado.

---

## 5. Matriz de fallos

| Situación | Cola | Bandeja | Alerta |
| :--- | :--- | :--- | :--- |
| Sin `chat_id` | No se encola | Nada que mostrar | Panel marca «Sin vincular» |
| Sin token de bot | No se encola | Nada que mostrar | Panel marca la carencia |
| `403` (bot bloqueado) | `FAILED_PERMANENT` | Motivo legible | Visible en bandeja |
| `429` / `5xx` / red | `PENDING_RETRY` (1/5/15/60) | Reintento programado | Visible en bandeja |
| Técnico reemplazado | `CANCELLED_OBSOLETE` | Motivo de obsolescencia | Sin alerta (es lo correcto) |
| `chat_id` borrado tras encolar | Fallo permanente en el envío | Motivo | Visible |

---

## 6. Plan de pruebas (herméticas, sin red)

| Suite | Qué certifica |
| :--- | :--- |
| `tests/unit/NotificationChannelTest.php` (nuevo o ampliación de `DomainEnumsTest`) | Valor `TELEGRAM`, etiqueta y `fromString` |
| `tests/unit/TelegramTransportTest.php` (nuevo) | Cuerpo JSON exacto (chat_id, texto recortado, `inline_keyboard`), ausencia de botón cuando no hay CTA, clasificación 200/400/403/429/500, timeout, y que el token no aparece en el diagnóstico |
| `tests/unit/NotificationTrackingLinkResolverTest.php` (ampliación) | Audiencia `TECHNICIAN`: ruta con ticket, sin ticket, `TECHNICIAN_UNASSIGNED` sin enlace, absoluta con host y degradación sin host |
| `tests/unit/NotificationMessageRendererTest.php` (ampliación) | `ctaLabel`/`ctaUrl` poblados para asignación; el HTML/texto del correo no cambia de forma |
| `tests/integration/NotificationOutboxEnqueueIntegrationTest.php` (ampliación) | Asignación con `chat_id` → 2 filas (EMAIL + TELEGRAM); sin `chat_id` → 1; sin token → 1; reasignación → la Telegram del saliente queda cancelada |
| `tests/integration/NotificationDispatcherAndSlaSweepTest.php` (ampliación) | Despacho con transporte en memoria: fila Telegram → `SENT`; 429 → `PENDING_RETRY`; 403 → `FAILED_PERMANENT` |
| `tests/integration/AdminUsersEndpointTest.php` (ampliación) | Alta/edición de `telegram_chat_id`, rechazo de formato, unicidad, rechazo en roles no técnicos, auditoría con valor enmascarado |
| `tests/unit/TechnicianDeepLinkTest.mjs` (nuevo) | `?tech=1&ticket=` lleva a la vista del técnico; resalta la parada; aviso si no está en ruta; no secuestra una sesión de sede |
| `tests/integration/NotificationConstitutionalTest.php` (ampliación) | `telegram_chat_id` ausente de cualquier payload de Sede/Ciudadano QR |

Toda suite nueva se registra en `tests/run_all.php`. La frase canónica de cifras (`281 suites · 10.933 aserciones` hoy)
cambiará y debe actualizarse en **los cinco documentos vivos** y en los anclajes del `README.md`, o
`tests/Support/DocMetricsGuard.php` pondrá la batería en rojo.

---

## 7. Ficheros previstos

**Nuevos:** migración `004_telegram_technician_channel.sql`, `src/Infrastructure/Notification/Transport/TelegramTransport.php`,
`TelegramUnconfiguredTransport` (o caso dentro del anterior), `tests/unit/TelegramTransportTest.php`,
`tests/unit/TechnicianDeepLinkTest.mjs`.

**Modificados:** `NotificationChannel`, `SecretProvider`, `NotificationTransportRegistry`,
`NotificationOutboxEnqueuerService`, `NotificationTrackingLinkResolver`, `RenderedNotificationMessage`,
`NotificationMessageRenderer`, `User`/`PdoUserRepository` (campo), `AdminUserService`, `CoordinatorAdminController`
(contrato), `AdminUsersTab.js`, `app.js`, `TechnicianRouteView.js`, `CoordinatorNotificationCenterModal.js` (filtro de
canal), `database/supabase_init.sql`, los cuatro contratos/specs vivos y la frase de cifras en cinco documentos.

---

## 8. Riesgos y mitigaciones

| Riesgo | Impacto | Mitigación |
| :--- | :--- | :--- |
| `ALTER TYPE ... ADD VALUE` dentro de la transacción del ejecutor | La migración no convergería | Probar primero en el motor de certificación; plan B en §2.1 |
| Deriva entre foto de creación y migración | Batería roja de paridad | Actualizar `supabase_init.sql` en el mismo commit y dejar que las suites de paridad lo certifiquen |
| Chat compartido entre técnicos | Fuga de expedientes entre personas | Índice único parcial + rechazo en servicio con mensaje claro |
| Token filtrado en logs | Compromiso del bot | Transporte depura cualquier `https://api.telegram.org/bot…` en los diagnósticos |
| Sesión distinta en el navegador de Telegram | Fricción (login la primera vez) | Aceptado y documentado; sin auto-login (RF-TG-09) |
| Crecimiento del alcance hacia «todo por Telegram» | Feature creep | Fuera de alcance explícito en §6 de la spec |

**Rollback:** retirar el token del entorno detiene el canal sin tocar código ni datos (no se encolan filas nuevas); las
filas pendientes quedan visibles en la bandeja. Borrar `telegram_chat_id` de un técnico detiene sólo sus avisos.

## 9. Orden de ejecución (una vez aprobada la spec)

1. Aprobación humana de esta spec y del plan (Fase 2 SDD).
2. **Tests primero:** suites de §6 en rojo (Fase 3).
3. Migración + enum + transporte + registro (RF-TG-01..03).
4. Resolutor + render + encolado + admin (RF-TG-04..07, 10).
5. Deep link y resalte en la vista del técnico (RF-TG-08, 09).
6. Contratos, specs y cifras; batería global en verde (Fase 5).
