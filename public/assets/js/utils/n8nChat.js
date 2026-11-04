/**
 * n8nChat.js
 *
 * Encapsula el ciclo de vida del widget asistente de chat de n8n.
 * Garantiza que el widget solo se instancie para usuarios internos autenticados
 * (Coordinadores o Técnicos de Ruta) y transmita el JWT firmado con HS256.
 *
 * Cumple con specs/technical/chat_assistant_contracts.md.
 */

const STYLE_HREF = 'https://cdn.jsdelivr.net/npm/@n8n/chat/dist/style.css';
const BUNDLE_URL = 'https://cdn.jsdelivr.net/npm/@n8n/chat/dist/chat.bundle.es.js';
const WEBHOOK_URL = 'https://rugsoft.app.n8n.cloud/webhook/a6c77576-7554-4138-8605-ea2f72c0505e/chat';

let isChatMounted = false;
let currentToken = null;

/**
 * Inyecta la hoja de estilos del widget si no está ya presente en el <head>.
 */
function ensureStyle() {
  if (typeof document === 'undefined') return;
  if (!document.querySelector(`link[href="${STYLE_HREF}"]`)) {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = STYLE_HREF;
    link.id = 'n8n-chat-style';
    document.head.appendChild(link);
  }
}

/**
 * Monta el widget de chat de n8n con el token verificado del usuario.
 * @param {string} token - Token JWT firmado con HS256
 */
export async function mountChatWidget(token) {
  if (!token || typeof window === 'undefined' || typeof document === 'undefined') {
    return;
  }

  // Si ya está montado con el mismo token, no duplicar
  if (isChatMounted && currentToken === token) {
    return;
  }

  // Si cambió de token, limpiar el anterior primero
  if (isChatMounted) {
    unmountChatWidget();
  }

  ensureStyle();

  try {
    const { createChat } = await import(BUNDLE_URL);

    createChat({
      webhookUrl: WEBHOOK_URL,
      metadata: {
        token: token
      }
    });

    isChatMounted = true;
    currentToken = token;
  } catch (err) {
    console.warn('VendGuard: No se pudo inicializar el widget de chat n8n:', err);
  }
}

/**
 * Desmonta el widget de chat y remueve sus elementos del DOM al cerrar sesión.
 */
export function unmountChatWidget() {
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

  isChatMounted = false;
  currentToken = null;
}
