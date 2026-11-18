/**
 * chatAssistant.js
 *
 * Configuración del asistente de chat de n8n (H-3 del informe E2E del Módulo 35).
 *
 * El contrato fija el alcance del asistente a Coordinación y Técnico de Ruta
 * (`specs/technical/chat_assistant_contracts.md` §1), pero sus tres destinos
 * externos —la hoja de estilos, el bundle y el webhook— son datos de despliegue:
 * cambian con el tenant, con la versión del paquete y con la red donde se
 * instala la consola. Vivían fijados en `utils/n8nChat.js`, así que cada
 * despliegue exigía tocar el código y cualquier CDN caído entraba en la ruta
 * crítica del arranque.
 *
 * Un despliegue puede sobrescribirlos sin recompilar nada:
 *
 *   window.VENDGUARD_CHAT_CONFIG = {
 *     enabled: false,                       // retira el asistente por completo
 *     webhookUrl: 'https://…/webhook/…/chat',
 *     bundleUrl: 'https://…/chat.bundle.es.js',
 *     styleHref: 'https://…/style.css',
 *     loadTimeoutMs: 8000,                  // techo de espera del bundle
 *     mountDelayMs: 1200                    // retraso si no hay requestIdleCallback
 *   };
 *
 * Las sobrescrituras se validan: solo se aceptan URLs `https://` absolutas y
 * números positivos; lo que no cumple se descarta y se queda el valor por
 * defecto, de modo que una configuración rota degrada el asistente, nunca la
 * consola.
 *
 * Cumple con specs/technical/chat_assistant_contracts.md §4.2.
 */

/**
 * Valores por defecto del asistente, los del tenant actual.
 *
 * `loadTimeoutMs` es el techo de espera del bundle: si el CDN no responde en
 * ese margen, el widget se declara no disponible y la consola sigue su curso.
 * `mountDelayMs` se usa como respaldo de `requestIdleCallback` para no competir
 * con el primer renderizado.
 */
export const CHAT_ASSISTANT_DEFAULTS = Object.freeze({
  enabled: true,
  styleHref: 'https://cdn.jsdelivr.net/npm/@n8n/chat/dist/style.css',
  bundleUrl: 'https://cdn.jsdelivr.net/npm/@n8n/chat/dist/chat.bundle.es.js',
  webhookUrl: 'https://rugsoft.app.n8n.cloud/webhook/a6c77576-7554-4138-8605-ea2f72c0505e/chat',
  loadTimeoutMs: 8000,
  mountDelayMs: 1200
});

/**
 * ¿Es una URL absoluta y segura?
 * @param {unknown} value
 * @returns {boolean}
 */
function isHttpsUrl(value) {
  return typeof value === 'string' && /^https:\/\/[^\s]+$/i.test(value.trim());
}

/**
 * Resuelve la configuración efectiva del asistente.
 *
 * @param {Object|null|undefined} overrides - `window.VENDGUARD_CHAT_CONFIG`
 * @returns {{enabled: boolean, styleHref: string, bundleUrl: string, webhookUrl: string, loadTimeoutMs: number, mountDelayMs: number}}
 */
export function resolveChatAssistantConfig(overrides = null) {
  const source = overrides && typeof overrides === 'object' ? overrides : {};

  const url = (key) => (isHttpsUrl(source[key]) ? source[key].trim() : CHAT_ASSISTANT_DEFAULTS[key]);
  const positive = (key) => {
    const value = Number(source[key]);
    return Number.isFinite(value) && value > 0 ? value : CHAT_ASSISTANT_DEFAULTS[key];
  };
  const delay = () => {
    const value = Number(source.mountDelayMs);
    return Number.isFinite(value) && value >= 0 ? value : CHAT_ASSISTANT_DEFAULTS.mountDelayMs;
  };

  return Object.freeze({
    enabled: source.enabled === false ? false : CHAT_ASSISTANT_DEFAULTS.enabled,
    styleHref: url('styleHref'),
    bundleUrl: url('bundleUrl'),
    webhookUrl: url('webhookUrl'),
    loadTimeoutMs: positive('loadTimeoutMs'),
    mountDelayMs: delay()
  });
}
