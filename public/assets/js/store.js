/**
 * VendGuard - Global Reactive Store (store.js)
 * 
 * Manages reactive application state using Vue 3 Composition API (reactive, computed).
 * Adheres to Dogma Vanilla (no Pinia, no Vuex, zero npm dependencies).
 * 
 * Key Responsibilities:
 * - Reactive state for current authenticated user and site location.
 * - Automatic persistence and restoration from localStorage across page reloads.
 * - Coordination with native HTTP client (api.js) to synchronize tokens.
 * - Reactive alerts/flash messages system and global loading indicator.
 */

import { api } from './api.js';

const STORAGE_KEY = 'vendguard_session';

/**
 * Fallback native Proxy-based reactivity provider for offline or test environments.
 * @param {Object} target
 * @returns {Proxy}
 */
function createProxyReactive(target) {
  const listeners = new Set();
  const proxy = new Proxy(target, {
    set(obj, prop, val) {
      obj[prop] = val;
      listeners.forEach(fn => {
        try { fn(prop, val); } catch (e) { /* ignore */ }
      });
      return true;
    }
  });
  return proxy;
}

/**
 * Resolves Vue 3 `reactive` function from available runtime environments:
 * 1. Global window.Vue / globalThis.Vue (standard CDN script tag)
 * 2. ESM import from CDN (modern browser module)
 * 3. Fallback ES6 Proxy (headless or CLI tests)
 */
let vueReactive = null;

if (typeof window !== 'undefined' && window.Vue?.reactive) {
  vueReactive = window.Vue.reactive;
} else if (typeof globalThis !== 'undefined' && globalThis.Vue?.reactive) {
  vueReactive = globalThis.Vue.reactive;
}

if (!vueReactive) {
  try {
    const vue = await import('https://unpkg.com/vue@3/dist/vue.esm-browser.prod.js');
    if (vue?.reactive) {
      vueReactive = vue.reactive;
    }
  } catch (err) {
    vueReactive = createProxyReactive;
  }
}

if (!vueReactive) {
  vueReactive = createProxyReactive;
}

/**
 * Core Reactive State
 */
export const state = vueReactive({
  // Active session
  token: null,
  authType: null, // 'internal' | 'site' | null
  user: null,     // { id, name, email, role, phone }
  location: null, // { id, site_code, name, address, contact_name }

  // UI state
  activeIncident: null,
  alerts: [],
  isLoading: false
});

/**
 * Sets up internal user session (Coordinator or Field Technician) (RF-04).
 * 
 * @param {Object} user - User payload { id, name, email, role, phone }
 * @param {string} token - Internal bearer token (auth_token_...)
 */
export function setInternalSession(user, token) {
  state.user = user;
  state.token = token;
  state.authType = 'internal';
  state.location = null;

  api.setToken(token);
  api.setSiteCode(null);

  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({
          authType: 'internal',
          token,
          user
        })
      );
    }
  } catch (e) {
    // Storage quota or privacy sandbox exception
  }
}

/**
 * Sets up site / location responsible session (RF-01).
 * 
 * @param {Object} location - Location payload { id, site_code, name, address, contact_name }
 * @param {string} token - Site bearer token (site_token_...)
 */
export function setSiteSession(location, token) {
  state.location = location;
  state.token = token;
  state.authType = 'site';
  state.user = null;

  api.setToken(token);
  api.setSiteCode(location.site_code);

  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({
          authType: 'site',
          token,
          location
        })
      );
    }
  } catch (e) {
    // Storage quota or privacy sandbox exception
  }
}

/**
 * Clears current session and wipes stored credentials.
 */
export function clearSession() {
  state.token = null;
  state.authType = null;
  state.user = null;
  state.location = null;
  state.activeIncident = null;

  api.clearAuth();

  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.removeItem(STORAGE_KEY);
    }
  } catch (e) {
    // Storage quota or privacy sandbox exception
  }
}

/**
 * Restores session from localStorage on application startup.
 * @returns {boolean} True if a session was restored
 */
export function restoreSession() {
  try {
    if (typeof localStorage === 'undefined') {
      return false;
    }
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      return false;
    }

    const session = JSON.parse(raw);
    if (!session || !session.token) {
      return false;
    }

    if (session.authType === 'internal' && session.user) {
      setInternalSession(session.user, session.token);
      return true;
    }

    if (session.authType === 'site' && session.location) {
      setSiteSession(session.location, session.token);
      return true;
    }
  } catch (e) {
    clearSession();
  }
  return false;
}

/**
 * Adds a temporary flash notification.
 * 
 * @param {string} message - Notification text
 * @param {'info'|'success'|'warning'|'error'} [type='info'] - Semantic color type
 * @param {number} [durationMs=5000] - Auto-dismiss delay in ms (0 to persist)
 * @returns {number} Alert ID
 */
export function addAlert(message, type = 'info', durationMs = 5000) {
  const alertId = Date.now() + Math.floor(Math.random() * 1000);
  const alert = {
    id: alertId,
    message,
    type,
    timestamp: new Date()
  };

  state.alerts.push(alert);

  if (durationMs > 0 && typeof setTimeout !== 'undefined') {
    setTimeout(() => {
      removeAlert(alertId);
    }, durationMs);
  }

  return alertId;
}

/**
 * Removes an alert by ID.
 * @param {number} alertId
 */
export function removeAlert(alertId) {
  const index = state.alerts.findIndex(a => a.id === alertId);
  if (index !== -1) {
    state.alerts.splice(index, 1);
  }
}

/**
 * Sets global loading indicator.
 * @param {boolean} loading
 */
export function setLoading(loading) {
  state.isLoading = Boolean(loading);
}

/**
 * Sets the active incident for inspect/modal views.
 * @param {Object|null} incident
 */
export function setActiveIncident(incident) {
  state.activeIncident = incident || null;
}

// Hook 401 Unauthorized handler to automatically clear expired session and alert user
api.setOnUnauthorized(() => {
  if (state.token) {
    clearSession();
    addAlert('Tu sesión ha expirado. Por favor, identifícate de nuevo.', 'warning', 6000);
  }
});

/**
 * Convenience Store Object
 */
export const store = {
  state,

  // Getters / Computed state helpers
  get isAuthenticated() {
    return Boolean(state.token && (state.user || state.location));
  },
  get isCoordinator() {
    return Boolean(state.user && state.user.role === 'COORDINATOR');
  },
  get isTechnician() {
    return Boolean(state.user && state.user.role === 'TECHNICIAN');
  },
  get isSiteSession() {
    return Boolean(state.authType === 'site' && state.location !== null);
  },

  // Actions
  setInternalSession,
  setSiteSession,
  clearSession,
  restoreSession,
  addAlert,
  removeAlert,
  setLoading,
  setActiveIncident
};

export default store;
