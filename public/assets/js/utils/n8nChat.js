/**
 * n8nChat.js
 *
 * Encapsula el ciclo de vida del widget asistente de chat de n8n.
 * Garantiza que el widget solo se instancie para usuarios internos autenticados
 * (Coordinadores o Técnicos de Ruta) y transmita el JWT firmado con HS256.
 *
 * Desde H-3 del informe E2E del Módulo 35 este módulo no conoce ningún destino
 * externo: la hoja de estilos, el bundle y el webhook viven en
 * `config/chatAssistant.js` y admiten sobrescritura por despliegue. Además el
 * montaje se difiere al primer hueco de inactividad y la importación del bundle
 * tiene techo de espera, de modo que un CDN lento o caído no retrasa el arranque
 * de la consola: en el peor caso el asistente no aparece y se avisa por consola.
 *
 * Cumple con specs/technical/chat_assistant_contracts.md.
 */

import { resolveChatAssistantConfig } from '../config/chatAssistant.js';

/**
 * Roles con derecho al asistente (`specs/technical/chat_assistant_contracts.md` §1).
 *
 * El contrato lo limita a Coordinadores y Técnicos de Ruta; el reponedor y el
 * responsable de sede quedan fuera. El backend ya no emite `chat_token` a esos
 * roles, y esta lista es la segunda orilla: un token antiguo en el almacén no
 * debe montar el widget en una sesión que el contrato no contempla.
 */
export const CHAT_ALLOWED_ROLES = Object.freeze(['COORDINATOR', 'TECHNICIAN']);

let isChatMounted = false;
let currentToken = null;
let pendingToken = null;
let mountGeneration = 0;

/**
 * ¿Puede este rol recibir el asistente?
 * @param {string|null|undefined} role - Rol del usuario autenticado
 * @returns {boolean}
 */
export function isChatRoleAllowed(role) {
  return CHAT_ALLOWED_ROLES.includes(String(role || '').trim().toUpperCase());
}

/**
 * Configuración efectiva: la de despliegue si existe, los valores por defecto si no.
 * @returns {ReturnType<typeof resolveChatAssistantConfig>}
 */
function resolveConfig() {
  const overrides = typeof window !== 'undefined' ? window.VENDGUARD_CHAT_CONFIG : null;
  return resolveChatAssistantConfig(overrides);
}

/**
 * Importa el bundle del asistente con techo de espera.
 *
 * El importador es un parámetro para poder verificar el techo sin red: si el CDN
 * no responde dentro de `timeoutMs`, la promesa se resuelve con `null` y el
 * arranque de la consola nunca queda pendiente de un tercero.
 *
 * @param {string} bundleUrl - URL absoluta del bundle
 * @param {number} timeoutMs - Techo de espera en milisegundos
 * @param {(url: string) => Promise<any>} [importer] - Cargador del bundle
 * @returns {Promise<{createChat: Function}|null>}
 */
export async function loadChatBundle(bundleUrl, timeoutMs, importer = (url) => import(url)) {
  const limit = Number(timeoutMs) > 0 ? Number(timeoutMs) : 1;
  let timer = null;

  const deadline = new Promise((resolve) => {
    timer = setTimeout(() => resolve(null), limit);
  });

  try {
    const loaded = await Promise.race([importer(bundleUrl), deadline]);
    return loaded && typeof loaded.createChat === 'function' ? loaded : null;
  } catch (_) {
    return null;
  } finally {
    if (timer !== null) clearTimeout(timer);
  }
}

/**
 * Inyecta (o reapunta) la hoja de estilos del widget.
 * @param {string} styleHref - URL absoluta de la hoja
 */
function ensureStyle(styleHref) {
  if (typeof document === 'undefined' || !styleHref) return;
  if (typeof document.getElementById !== 'function') return;

  let link = document.getElementById('n8n-chat-style');
  if (!link) {
    link = document.createElement('link');
    link.id = 'n8n-chat-style';
    link.rel = 'stylesheet';
    document.head.appendChild(link);
  }
  if (link.href !== styleHref) {
    link.href = styleHref;
  }
}

/**
 * Nombre de pila para el saludo: del objeto `user` o del claim del JWT.
 * @param {string} token
 * @param {Object|null} user
 * @returns {string}
 */
function firstNameFrom(token, user) {
  let userName = user?.name || '';
  if (!userName && typeof token === 'string') {
    try {
      const parts = token.split('.');
      if (parts.length === 3) {
        const payloadStr = atob(parts[1].replace(/-/g, '+').replace(/_/g, '/'));
        const payload = JSON.parse(payloadStr);
        userName = payload?.name || '';
      }
    } catch (_) {}
  }
  return String(userName || '').trim().split(' ')[0] || '';
}

/**
 * Montaje efectivo, ya fuera del camino crítico del arranque.
 * @param {number} generation - Generación del montaje programado
 * @param {string} token
 * @param {Object|null} user
 * @param {ReturnType<typeof resolveChatAssistantConfig>} config
 */
async function performMount(generation, token, user, config) {
  if (generation !== mountGeneration) return;

  const bundle = await loadChatBundle(config.bundleUrl, config.loadTimeoutMs);
  if (generation !== mountGeneration) return;

  pendingToken = null;

  if (!bundle) {
    console.warn(
      'VendGuard: el asistente no está disponible (bundle inalcanzable o fuera de tiempo).'
    );
    return;
  }

  ensureStyle(config.styleHref);

  const nombre = firstNameFrom(token, user);
  const saludo = nombre
    ? `¡Hola, ${nombre}! Soy tu asistente de Vendguard.`
    : '¡Hola! Soy tu asistente de Vendguard.';

  try {
    bundle.createChat({
      webhookUrl: config.webhookUrl,
      metadata: { token },
      defaultLanguage: 'en',
      initialMessages: [
        saludo,
        '¿En qué puedo ayudarte hoy?'
      ],
      i18n: {
        en: {
          title: 'Asistente Vendguard',
          subtitle: 'Consulta incidencias, máquinas y técnicos',
          footer: '',
          getStarted: 'Nueva conversación',
          inputPlaceholder: 'Escribe tu pregunta...',
        },
      },
    });

    isChatMounted = true;
    currentToken = token;
  } catch (err) {
    console.warn('VendGuard: No se pudo inicializar el widget de chat n8n:', err);
  }
}

/**
 * Programa el montaje del widget de chat de n8n con el token verificado.
 *
 * Devuelve de inmediato: el bundle se carga en el primer hueco de inactividad y
 * con techo de espera, así que ni el CDN ni su fallo forman parte del arranque
 * de la consola.
 *
 * @param {string} token - Token JWT firmado con HS256
 * @param {Object|null} user - Datos del usuario autenticado { id, name, role, ... }
 */
export function mountChatWidget(token, user = null) {
  if (!token || typeof window === 'undefined' || typeof document === 'undefined') {
    return;
  }

  // Defensa en profundidad (H-2): si la sesión no es de Coordinación ni de Técnico
  // —o cambió a un rol sin derecho—, el widget se retira en lugar de montarse.
  if (user !== null && !isChatRoleAllowed(user?.role)) {
    unmountChatWidget();
    return;
  }

  const config = resolveConfig();
  if (!config.enabled) {
    unmountChatWidget();
    return;
  }

  // Ya montado con el mismo token, o ya programado: no duplicar.
  if (isChatMounted && currentToken === token) {
    return;
  }
  if (pendingToken === token) {
    return;
  }
  if (isChatMounted) {
    unmountChatWidget();
  }

  const generation = ++mountGeneration;
  pendingToken = token;

  const run = () => {
    performMount(generation, token, user, config);
  };

  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(run, { timeout: 2000 });
  } else {
    setTimeout(run, config.mountDelayMs);
  }
}

/**
 * Desmonta el widget, cancela un montaje pendiente y remueve sus elementos del
 * DOM (y su hoja de estilos) al cerrar sesión.
 */
export function unmountChatWidget() {
  mountGeneration++;
  pendingToken = null;

  if (typeof document === 'undefined') return;

  const chatSelectors = [
    '#n8n-chat',
    '.n8n-chat',
    '[class*="n8n-chat"]',
    '[class*="chat-widget"]',
    '[class*="chat-window"]',
    '[class*="chat-toggle"]',
    '[class*="chat-wrapper"]'
  ];

  try {
    const elements = document.querySelectorAll(chatSelectors.join(', '));
    elements.forEach((el) => {
      try {
        el.remove();
      } catch (_) {}
    });
  } catch (_) {}

  if (typeof document.getElementById === 'function') {
    const style = document.getElementById('n8n-chat-style');
    if (style && typeof style.remove === 'function') {
      try {
        style.remove();
      } catch (_) {}
    }
  }

  isChatMounted = false;
  currentToken = null;
}
