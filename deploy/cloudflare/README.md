# deploy/cloudflare · Relé de Webhook para Telegram (Cloudflare Worker)

Este directorio contiene el Worker [`telegram-webhook-relay.js`](telegram-webhook-relay.js) para conectar de forma **100 % gratuita** el **Canal Corporativo de Emergencias** de VendGuard (Módulo 14) con un grupo, canal o chat de **Telegram**, y opcionalmente ejecutar los procesos batch (`notifications-dispatch` y `sanitary-clock-sweep`) sin depender de GitHub Actions.

---

## 1. Por qué es necesario este relé

[`NativeHttpsWebhookTransport.php`](../../src/Infrastructure/Notification/Transport/NativeHttpsWebhookTransport.php) envía un cuerpo JSON canónico e independiente del proveedor con timeout estricto de 5 segundos y validación anti-SSRF:

```json
{
  "source": "VendGuard",
  "event": "SANITARY_QUARANTINE_TRIGGERED",
  "priority": "CRITICAL_SANITARY",
  "subject": "[VENDGUARD · EMERGENCIA SANITARIA] ...",
  "text": "...",
  "html": "..."
}
```

El Worker recibe ese JSON por `POST`, compone el mensaje completo (insignia de prioridad sanitaria, asunto, código de evento y cuerpo en texto plano seguro frente a caracteres especiales, recortado a los 4.096 caracteres máximos de Telegram) y lo entrega a `https://api.telegram.org/bot<TG_BOT_TOKEN>/sendMessage`, devolviendo a VendGuard el **mismo código HTTP de Telegram** (`200`, `429`, `4xx`, `5xx`) para que la cola `notification_outbox` aplique su política de reintentos con backoff progresivo.

---

## 2. Paso a paso: Crear el Bot de Telegram y obtener `TG_BOT_TOKEN` y `TG_CHAT_ID`

1. En Telegram, abre una conversación con **`@BotFather`** y envía el comando `/newbot`.
2. Indica el nombre visible (ej. `VendGuard Alertas`) y el usuario terminado en `bot` (ej. `vendguard_alertas_bot`).
3. Copia el **token** que te entrega `@BotFather` (formato `123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ`). Ese será tu **`TG_BOT_TOKEN`**.
4. Añade el bot al grupo de guardia de Telegram (o escríbele en privado) y envía un mensaje cualquiera en el grupo (ej. `hola`).
5. Abre en tu navegador:
   ```text
   https://api.telegram.org/bot<TU_TG_BOT_TOKEN>/getUpdates
   ```
6. Localiza el objeto `"chat":{"id":-1001234567890,...}` en la respuesta JSON. Ese número (incluido el signo `-` si es un grupo o supergrupo) es tu **`TG_CHAT_ID`**.

---

## 3. Paso a paso: Desplegar el Worker gratis en Cloudflare

1. Entra en [Cloudflare Dashboard](https://dash.cloudflare.com/) → **Workers & Pages** → **Create** → **Create Worker** (el plan gratuito incluye 100.000 peticiones diarias sin tarjeta de crédito).
2. Asígnale un nombre (ej. `vendguard-telegram-relay`) y pulsa **Deploy**.
3. Pulsa **Edit code**, reemplaza todo el contenido por el de [`telegram-webhook-relay.js`](telegram-webhook-relay.js) y pulsa **Deploy**.
4. En el panel del Worker, entra en **Settings → Variables and Secrets** y añade:

| Variable / Secreto | Obligatoria | Descripción |
| :--- | :--- | :--- |
| `TG_BOT_TOKEN` | **Sí** *(Secret)* | Token del bot obtenido en `@BotFather`. |
| `TG_CHAT_ID` | **Sí** | Identificador del chat o grupo de Telegram (ej. `-1001234567890`). |
| `RELAY_AUTH_TOKEN` | Opcional *(Secret)* | Si se define, el Worker exigirá `?token=<RELAY_AUTH_TOKEN>` en la URL para aceptar envíos. |
| `VENDGUARD_APP_URL` | Opcional | URL pública de tu despliegue (ej. `https://tu-app.onrender.com`) si usas el Cron Trigger del Worker. |
| `CRON_SECRET` | Opcional *(Secret)* | El mismo valor de `CRON_SECRET` de VendGuard si usas el Cron Trigger del Worker. |

---

## 4. Conectar el Worker en el Panel de Coordinación de VendGuard

1. Inicia sesión en VendGuard como **Coordinador** (`coordinacion@vendguard.internal`).
2. En la barra superior, abre **🔔 Notificaciones** → pestaña **Canal Corporativo (Webhook)**.
3. Completa los campos:
   * **Nombre del canal:** `Telegram Guardia 24/7`
   * **URL del Webhook (`https://`):**
     * Sin `RELAY_AUTH_TOKEN`: `https://vendguard-telegram-relay.<tu-subdominio>.workers.dev`
     * Con `RELAY_AUTH_TOKEN`: `https://vendguard-telegram-relay.<tu-subdominio>.workers.dev?token=<TU_RELAY_AUTH_TOKEN>`
   * **Estado:** activa el conmutador **Habilitar canal corporativo de emergencias**.
4. Pulsa **Probar conexión**:
   * Verás la confirmación en verde (`HTTP 200`) en menos de 5 segundos y recibirás el mensaje `[PRUEBA DE CONEXIÓN · VENDGUARD]` en tu grupo de Telegram.
5. Pulsa **Guardar configuración**.

---

## 5. (Opcional) Activar el Cron Trigger en el mismo Worker

Si quieres que este mismo Worker despache los correos/webhooks y ejecute el barrido sanitario sin usar GitHub Actions:

1. Define `VENDGUARD_APP_URL` y `CRON_SECRET` en **Settings → Variables and Secrets** del Worker.
2. Ve a **Settings → Triggers → Cron Triggers** → **Add Cron Trigger** y configura la expresión:
   ```cron
   */5 * * * *
   ```
3. Cada 5 minutos el Worker invocará `POST /api/cron/notifications-dispatch` y `POST /api/cron/sanitary-clock-sweep` autenticándose con `X-Cron-Secret`.
