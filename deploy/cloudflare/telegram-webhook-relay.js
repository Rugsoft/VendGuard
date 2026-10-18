/**
 * VendGuard · Relé de Webhook Corporativo para Telegram (Cloudflare Worker)
 *
 * Módulo 14 · Centro de Notificaciones Transaccionales y Alertas de Emergencia.
 *
 * Propósito:
 * Traduce el cuerpo JSON canónico que emite `NativeHttpsWebhookTransport` de VendGuard:
 *   {
 *     "source": "VendGuard",
 *     "event": "SANITARY_QUARANTINE_TRIGGERED",
 *     "priority": "CRITICAL_SANITARY",
 *     "subject": "...",
 *     "text": "...",
 *     "html": "..."
 *   }
 * al contrato de `POST https://api.telegram.org/bot<TOKEN>/sendMessage` y reenvía el
 * código de estado HTTP exacto (2xx, 429, 4xx, 5xx) para que VendGuard aplique su
 * política de reintentos y backoff progresivo (RF-06.1, RF-06.2 y RF-06.5.2).
 *
 * Además, si se habilita un Cron Trigger opcional en el Worker y se declaran las
 * variables `VENDGUARD_APP_URL` y `CRON_SECRET`, la función `scheduled()` puede actuar
 * como planificador externo gratuito para despertar el contenedor y disparar:
 *   - `POST /api/cron/notifications-dispatch`
 *   - `POST /api/cron/sanitary-clock-sweep`
 *
 * Variables de entorno / Secretos en Cloudflare Workers:
 *   - TG_BOT_TOKEN      (obligatorio) Token del bot emitido por @BotFather.
 *   - TG_CHAT_ID        (obligatorio) ID del grupo, canal o chat de destino (ej. -1001234567890).
 *   - RELAY_AUTH_TOKEN  (opcional)    Token compartido en la query string (?token=...) para
 *                                     restringir quién puede invocar el Worker.
 *   - VENDGUARD_APP_URL (opcional)    URL pública de VendGuard para el Cron Trigger opcional.
 *   - CRON_SECRET       (opcional)    Secreto `X-Cron-Secret` de VendGuard para el Cron Trigger.
 */

const TELEGRAM_MAX_MESSAGE_LENGTH = 4096;
const UPSTREAM_TIMEOUT_MS = 4500;

export default {
  /**
   * Punto de entrada HTTP para el Canal Corporativo de Emergencias de VendGuard.
   *
   * @param {Request} request
   * @param {Record<string, string | undefined>} env
   * @returns {Promise<Response>}
   */
  async fetch(request, env) {
    if (request.method !== 'POST') {
      return jsonResponse(
        {
          ok: false,
          error: 'METHOD_NOT_ALLOWED',
          message: 'Use POST with the VendGuard webhook JSON payload.',
        },
        405,
        { Allow: 'POST' }
      );
    }

    const botToken = (env.TG_BOT_TOKEN ?? '').trim();
    const defaultChatId = (env.TG_CHAT_ID ?? '').trim();

    if (botToken === '' || defaultChatId === '') {
      // 500 indica a VendGuard un fallo transitorio hasta que se completen los secretos del Worker.
      return jsonResponse(
        {
          ok: false,
          error: 'WORKER_NOT_CONFIGURED',
          message: 'Missing TG_BOT_TOKEN or TG_CHAT_ID secret in Cloudflare Worker environment.',
        },
        500
      );
    }

    const url = new URL(request.url);
    const expectedRelayToken = (env.RELAY_AUTH_TOKEN ?? '').trim();
    if (expectedRelayToken !== '') {
      const providedToken = (url.searchParams.get('token') ?? '').trim();
      if (!timingSafeEqualString(providedToken, expectedRelayToken)) {
        return jsonResponse(
          {
            ok: false,
            error: 'UNAUTHORIZED_RELAY_TOKEN',
            message: 'Invalid or missing relay token.',
          },
          401
        );
      }
    }

    let payload;
    try {
      payload = await request.json();
    } catch {
      return jsonResponse(
        {
          ok: false,
          error: 'INVALID_JSON',
          message: 'Request body must be valid JSON.',
        },
        400
      );
    }

    if (typeof payload !== 'object' || payload === null) {
      return jsonResponse(
        {
          ok: false,
          error: 'INVALID_PAYLOAD',
          message: 'Webhook payload must be a JSON object.',
        },
        400
      );
    }

    const chatId = (url.searchParams.get('chat_id') ?? defaultChatId).trim();
    const formattedText = formatTelegramMessage(payload);

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), UPSTREAM_TIMEOUT_MS);

    try {
      const telegramResponse = await fetch(`https://api.telegram.org/bot${botToken}/sendMessage`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json; charset=utf-8',
          Accept: 'application/json',
        },
        body: JSON.stringify({
          chat_id: chatId,
          text: formattedText,
          disable_web_page_preview: true,
        }),
        signal: controller.signal,
      });

      const responseText = await telegramResponse.text();

      return new Response(responseText, {
        status: telegramResponse.status,
        headers: {
          'Content-Type': 'application/json; charset=utf-8',
          'Cache-Control': 'no-store',
        },
      });
    } catch (error) {
      // 504 Gateway Timeout para que NativeHttpsWebhookTransport lo clasifique como transitorio (5xx).
      return jsonResponse(
        {
          ok: false,
          error: 'TELEGRAM_UPSTREAM_TIMEOUT',
          message: error instanceof Error ? error.message : 'Upstream request to Telegram failed.',
        },
        504
      );
    } finally {
      clearTimeout(timer);
    }
  },

  /**
   * Planificador Cron opcional (Settings -> Triggers -> Cron Triggers, cada 5 min).
   * Si se definen `VENDGUARD_APP_URL` y `CRON_SECRET`, ejecuta el despachador de
   * notificaciones y el barrido sanitario sin depender de GitHub Actions.
   *
   * @param {{ scheduledTime?: number, cron?: string }} event
   * @param {Record<string, string | undefined>} env
   */
  async scheduled(event, env) {
    const appUrl = (env.VENDGUARD_APP_URL ?? '').trim().replace(/\/+$/, '');
    const cronSecret = (env.CRON_SECRET ?? '').trim();

    if (appUrl === '' || cronSecret === '') {
      return;
    }

    const headers = {
      'X-Cron-Secret': cronSecret,
      Accept: 'application/json',
      'User-Agent': 'VendGuard-CloudflareWorker-Cron/1.0',
    };

    await Promise.allSettled([
      fetch(`${appUrl}/api/cron/notifications-dispatch`, { method: 'POST', headers }),
      fetch(`${appUrl}/api/cron/sanitary-clock-sweep`, { method: 'POST', headers }),
    ]);
  },
};

/**
 * Construye el texto legible para Telegram a partir del JSON canónico de VendGuard.
 * Usa texto plano estructurado para que cualquier carácter especial de sedes o tickets
 * nunca sea rechazado por el parser MarkdownV2/HTML de Telegram.
 *
 * @param {Record<string, unknown>} payload
 * @returns {string}
 */
export function formatTelegramMessage(payload) {
  const priority = typeof payload.priority === 'string' ? payload.priority.trim() : '';
  const eventName = typeof payload.event === 'string' ? payload.event.trim() : '';
  const subject = typeof payload.subject === 'string' ? payload.subject.trim() : '';
  const textBody = typeof payload.text === 'string' ? payload.text.trim() : '';

  const isCritical = priority === 'CRITICAL_SANITARY';
  const badge = isCritical ? '🚨 [EMERGENCIA SANITARIA · VENDGUARD]' : '🔔 [AVISO · VENDGUARD]';

  const lines = [badge];

  if (subject !== '') {
    lines.push(subject);
  }

  if (eventName !== '') {
    lines.push(`Evento: ${eventName}`);
  }

  if (textBody !== '') {
    lines.push('', textBody);
  }

  const fullMessage = lines.join('\n').trim();
  if (fullMessage.length <= TELEGRAM_MAX_MESSAGE_LENGTH) {
    return fullMessage;
  }

  return `${fullMessage.slice(0, TELEGRAM_MAX_MESSAGE_LENGTH - 3)}...`;
}

/**
 * Comparación en tiempo constante de cadenas para evitar ataques de temporización sobre RELAY_AUTH_TOKEN.
 *
 * @param {string} a
 * @param {string} b
 * @returns {boolean}
 */
function timingSafeEqualString(a, b) {
  if (a.length !== b.length) {
    return false;
  }
  let mismatch = 0;
  for (let i = 0; i < a.length; i++) {
    mismatch |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return mismatch === 0;
}

/**
 * @param {Record<string, unknown>} body
 * @param {number} status
 * @param {Record<string, string>} extraHeaders
 * @returns {Response}
 */
function jsonResponse(body, status, extraHeaders = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store',
      ...extraHeaders,
    },
  });
}
