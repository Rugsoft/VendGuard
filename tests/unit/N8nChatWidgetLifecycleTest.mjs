/**
 * VendGuard - Frontend Unit Test Suite (N8nChatWidgetLifecycleTest.mjs)
 *
 * Valida el ciclo de vida del widget asistente de chat n8n y la gestión del token
 * en el store reactivo de VendGuard (specs/technical/chat_assistant_contracts.md).
 *
 * Dogma Vanilla: runner ES Module puro en Node.js, sin dependencias externas.
 */

// Simulación de entorno DOM para Node.js
class FakeElement {
  constructor(tagName) {
    this.tagName = tagName.toUpperCase();
    this.children = [];
    this.id = '';
    this.className = '';
    this.attributes = {};
    this.parentNode = null;
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
    if (name === 'id') this.id = String(value);
    if (name === 'class') this.className = String(value);
  }

  getAttribute(name) {
    return this.attributes[name] || null;
  }

  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  remove() {
    if (this.parentNode) {
      const idx = this.parentNode.children.indexOf(this);
      if (idx !== -1) {
        this.parentNode.children.splice(idx, 1);
      }
      this.parentNode = null;
    }
  }
}

class FakeDocument {
  constructor() {
    this.head = new FakeElement('head');
    this.body = new FakeElement('body');
  }

  createElement(tagName) {
    return new FakeElement(tagName);
  }

  querySelector(selector) {
    if (selector.includes('link[href=')) {
      const href = selector.match(/href="([^"]+)"/)?.[1];
      return this.head.children.find(el => el.attributes?.href === href) || null;
    }
    return null;
  }

  querySelectorAll(selector) {
    // Busca elementos que contengan n8n-chat o chat- en id o clase
    const matches = [];
    const search = (node) => {
      for (const child of node.children) {
        if (
          child.id?.includes('n8n-chat') ||
          child.className?.includes('n8n-chat') ||
          child.className?.includes('chat-')
        ) {
          matches.push(child);
        }
        search(child);
      }
    };
    search(this.body);
    return matches;
  }
}

// Configurar mocks globales si se ejecuta bajo Node
if (typeof globalThis.window === 'undefined') {
  globalThis.window = globalThis;
}
if (typeof globalThis.document === 'undefined') {
  globalThis.document = new FakeDocument();
}

// Mock localStorage
const storageMap = new Map();
globalThis.localStorage = {
  getItem: (k) => (storageMap.has(k) ? storageMap.get(k) : null),
  setItem: (k, v) => storageMap.set(k, String(v)),
  removeItem: (k) => storageMap.delete(k),
  clear: () => storageMap.clear()
};

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - N8nChatWidgetLifecycleTest');
console.log('======================================================================\n');

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.log(`  [FAIL] ${description}`);
    if (details) console.log(`         ${details}`);
    failures++;
  }
}

const { store, setInternalSession, clearSession, restoreSession } = await import('../../public/assets/js/store.js');
const { mountChatWidget, unmountChatWidget } = await import('../../public/assets/js/utils/n8nChat.js');

// ---------------------------------------------------------------------
// 1. Almacenamiento y reactividad de chatToken en store.js
// ---------------------------------------------------------------------
console.log('--- 1. Gestión de chatToken en store.js ---');

const mockUser = {
  id: 42,
  name: 'Ana Coordinadora',
  email: 'ana@vendguard.internal',
  role: 'COORDINATOR'
};
const mockToken = 'auth_token_fake.signature';
const mockChatToken = 'header.payload.signature_jwt';

setInternalSession(mockUser, mockToken, mockChatToken);

assert('store.state.chatToken se establece tras setInternalSession', store.state.chatToken === mockChatToken);
assert('store.state.user contiene el usuario interno', store.state.user?.id === 42);

// Verificar almacenamiento en localStorage
const stored = JSON.parse(localStorage.getItem('vendguard_session') || '{}');
assert('chatToken se persiste en localStorage', stored.chatToken === mockChatToken);

// Restauración de sesión
clearSession();
assert('clearSession limpia store.state.chatToken', store.state.chatToken === null);

// Simular payload con exp futuro
const freshPayload = Buffer.from(JSON.stringify({ exp: Math.floor(Date.now() / 1000) + 3600 })).toString('base64url');
const freshToken = `auth_token_${freshPayload}.sig`;
localStorage.setItem('vendguard_session', JSON.stringify({
  authType: 'internal',
  token: freshToken,
  chatToken: mockChatToken,
  user: mockUser
}));

const restored = restoreSession();
assert('restoreSession() recupera exitosamente la sesión interna', restored === true);
assert('restoreSession() restaura el chatToken', store.state.chatToken === mockChatToken);

// ---------------------------------------------------------------------
// 2. Ciclo de vida y desmontaje del widget n8nChat
// ---------------------------------------------------------------------
console.log('\n--- 2. Ciclo de vida del widget (mount / unmount) ---');

// Simular elementos inyectados en document.body por el widget
const chatNode = document.createElement('div');
chatNode.setAttribute('id', 'n8n-chat');
chatNode.setAttribute('class', 'n8n-chat-widget');
document.body.appendChild(chatNode);

assert('El nodo de chat existe en document.body antes de unmount', document.body.children.includes(chatNode));

unmountChatWidget();

assert('unmountChatWidget() remueve el contenedor del chat del DOM', !document.body.children.includes(chatNode));

// Limpieza final
clearSession();

console.log('\n======================================================================');
if (failures === 0) {
  console.log(` RESULTADO: Todas las pruebas (${assertions}) pasaron con éxito.`);
  process.exit(0);
} else {
  console.log(` RESULTADO: ${failures} de ${assertions} pruebas fallaron.`);
  process.exit(1);
}
console.log('======================================================================\n');
