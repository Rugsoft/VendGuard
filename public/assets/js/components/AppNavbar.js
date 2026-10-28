/**
 * VendGuard - AppNavbar Component (AppNavbar.js)
 * 
 * Top application navigation bar providing:
 * - Brand logo and title with Docker Design Tokens (Inter & DM Sans, #2560ff).
 * - Contextual session badges (Site code badge for Location Responsible, Role badge for Coordinator/Technician).
 * - Accessible logout action invoking store.clearSession() and emitting 'logout'.
 * - Global network loading indicator bar.
 * - Guardia de cierre de sesión con la bandeja sin conexión viva (Módulo 24,
 *   RF-06.1 y RF-06.5, D4): con acciones pendientes o en conflicto el botón
 *   bloquea la salida y muestra el recuento; con la bandeja vacía limpia la
 *   copia local del técnico para dejar el dispositivo listo para otro operario.
 */

import { store } from '../store.js';
import { OfflineStorage } from '../utils/OfflineStorage.js';
import { OFFLINE_QUEUE_EVENT } from '../utils/OfflineSyncManager.js';

export const AppNavbar = {
  name: 'AppNavbar',
  props: {
    /**
     * Optional custom title
     */
    appName: {
      type: String,
      default: 'VendGuard'
    }
  },
  emits: ['logout', 'navigate-home', 'logout-blocked'],
  data() {
    return {
      /** Resumen de la cola del técnico para la guardia de salida (`EARS 06.1`). */
      offlineQueue: { pending: 0, syncing: 0, conflicts: 0, total: 0 },
      logoutBlockedMessage: '',
      /** Adaptador de `IndexedDB`; solo se consulta para la sesión de técnico. */
      offlineStorage: new OfflineStorage()
    };
  },
  mounted() {
    this.refreshOfflineQueue();

    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
      window.addEventListener(OFFLINE_QUEUE_EVENT, this.handleOfflineQueueEvent);
      window.addEventListener('online', this.handleOfflineQueueEvent);
      window.addEventListener('offline', this.handleOfflineQueueEvent);
    }
  },
  beforeUnmount() {
    if (typeof window !== 'undefined' && typeof window.removeEventListener === 'function') {
      window.removeEventListener(OFFLINE_QUEUE_EVENT, this.handleOfflineQueueEvent);
      window.removeEventListener('online', this.handleOfflineQueueEvent);
      window.removeEventListener('offline', this.handleOfflineQueueEvent);
    }
  },
  computed: {
    isAuthenticated() {
      return store.isAuthenticated;
    },
    isSiteSession() {
      return store.isSiteSession;
    },
    location() {
      return store.state.location;
    },
    user() {
      return store.state.user;
    },
    isLoading() {
      return store.state.isLoading;
    },
    /** Solo el técnico de campo opera con la bandeja sin conexión (Módulo 24). */
    isTechnicianSession() {
      return Boolean(store.state.user && store.state.user.role === 'TECHNICIAN');
    },
    /** Operaciones que impiden cerrar sesión: pendientes, en envío o en conflicto. */
    pendingOfflineCount() {
      return Math.max(0, Number(this.offlineQueue?.total) || 0);
    },
    roleLabel() {
      if (!this.user?.role) return '';
      switch (this.user.role) {
        case 'COORDINATOR': return 'Coordinación';
        case 'TECHNICIAN': return 'Técnico de Ruta';
        default: return this.user.role;
      }
    },
    quickGuideUrl() {
      if (this.isSiteSession) {
        return '/docs/guia_rapida_por_rol_a4.html#guia-sede';
      }
      if (this.user?.role === 'COORDINATOR') {
        return '/docs/guia_rapida_por_rol_a4.html#guia-coordinacion';
      }
      if (this.user?.role === 'TECHNICIAN') {
        return '/docs/guia_rapida_por_rol_a4.html#guia-tecnico';
      }
      return '/docs/guia_rapida_por_rol_a4.html';
    }
  },
  methods: {
    /**
     * Guardia de salida (Módulo 24, `RF-06.1`, `EARS 06.1`).
     *
     * Con trabajo vivo en el dispositivo no se cierra la sesión: se muestra el
     * recuento y se deja la bandeja accesible. Sin pendientes se limpia la copia
     * local del técnico —paradas, inventario y cola— antes de salir, para que un
     * dispositivo compartido no se quede con la ruta del operario anterior
     * (`EARS 06.5`, Art. V.4).
     */
    handleLogout() {
      const total = Math.max(0, Number(this.offlineQueue?.total) || 0);

      if (this.isTechnicianSession && total > 0) {
        const pending = Math.max(0, Number(this.offlineQueue?.pending) || 0);
        const conflicts = Math.max(0, Number(this.offlineQueue?.conflicts) || 0);
        this.logoutBlockedMessage = `No puedes cerrar sesión: ${total} operación(es) sin sincronizar `
          + `(${pending} pendiente(s), ${conflicts} en conflicto). Sincroniza y vuelca los conflictos `
          + 'desde tu ruta antes de salir.';
        this.$emit('logout-blocked', { total, pending, conflicts });
        return;
      }

      // Limpieza de la copia local con la bandeja vacía (`EARS 06.5`). Se lanza
      // sin esperar: cerrar sesión no puede depender de la latencia del disco.
      try {
        const technicianId = Number(this.user?.id ?? 0);
        if (this.offlineStorage && technicianId > 0) {
          Promise.resolve(this.offlineStorage.clearTechnician(technicianId)).catch(() => {});
        }
      } catch (error) {
        // La sesión se cierra igualmente: el dato local ya no tiene utilidad
        // para nadie sin la cuenta que lo generó.
      }

      store.clearSession();
      this.$emit('logout');
    },
    handleBrandClick() {
      this.$emit('navigate-home');
    },
    /** Relee la cola del técnico autenticado (aislada por `technician_id`). */
    async refreshOfflineQueue() {
      if (!this.isTechnicianSession) {
        this.offlineQueue = { pending: 0, syncing: 0, conflicts: 0, total: 0 };
        return;
      }

      const technicianId = Number(this.user?.id ?? 0);
      if (!(technicianId > 0)) {
        return;
      }

      try {
        const items = await this.offlineStorage.listMutations(technicianId);
        const count = (status) => items.filter((item) => item?.status === status).length;
        this.offlineQueue = {
          pending: count('PENDING'),
          syncing: count('SYNCING'),
          conflicts: count('CONFLICT'),
          total: items.length
        };

        if (items.length === 0) {
          this.logoutBlockedMessage = '';
        }
      } catch (error) {
        // Sin `IndexedDB` legible no hay cola demostrable que bloquee la salida.
      }
    },
    handleOfflineQueueEvent() {
      this.refreshOfflineQueue();
    }
  },
  template: `
    <header
      class="vg-navbar"
      style="background-color: #ffffff; border-bottom: 1px solid var(--color-hairline, #c8cfda); position: sticky; top: 0; z-index: 100; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);"
    >
      <!-- Loading progress indicator bar -->
      <div
        v-if="isLoading"
        style="height: 3px; background: linear-gradient(90deg, #2560ff 0%, #38bd7d 50%, #2560ff 100%); background-size: 200% 100%; animation: vg-progress-animation 1.5s infinite linear; position: absolute; top: 0; left: 0; right: 0;"
      ></div>

      <div
        style="max-width: 1200px; margin: 0 auto; padding: 0 16px; height: 56px; display: flex; align-items: center; justify-content: space-between; gap: 16px;"
      >
        <!-- Brand / Logo -->
        <div
          style="display: flex; align-items: center; gap: 10px; cursor: pointer; user-select: none;"
          @click="handleBrandClick"
        >
          <div
            style="width: 32px; height: 32px; border-radius: var(--radius-interactive, 4px); background-color: var(--color-primary, #2560ff); display: flex; align-items: center; justify-content: center; color: #ffffff; font-weight: 700; font-size: 16px; font-family: var(--font-display, 'DM Sans', sans-serif);"
          >
            V
          </div>
          <div style="display: flex; flex-direction: column;">
            <span
              style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 17px; font-weight: 700; color: var(--color-ink, #000000); letter-spacing: -0.02em; line-height: 1.1;"
            >
              {{ appName }}
            </span>
            <span
              style="font-family: var(--font-body, Inter, sans-serif); font-size: 11px; color: var(--color-ink-muted, #6c7e9d); line-height: 1;"
            >
              Gestión de Incidencias
            </span>
          </div>
        </div>

        <!-- Session Identity & Actions -->
        <div
          v-if="isAuthenticated"
          style="display: flex; align-items: center; gap: 12px;"
        >
          <!-- Site Session Context (Location Responsible) -->
          <div
            v-if="isSiteSession && location"
            style="display: flex; align-items: center; gap: 8px; background-color: var(--color-surface-1, #efefef); padding: 4px 10px; border-radius: var(--radius-interactive, 4px); border: 1px solid var(--color-hairline, #c8cfda);"
          >
            <span
              style="font-family: var(--font-body, Inter, sans-serif); font-size: 11px; font-weight: 700; background-color: var(--color-primary, #2560ff); color: #ffffff; padding: 2px 6px; border-radius: 3px;"
            >
              {{ location.site_code }}
            </span>
            <span
              style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 500; color: var(--color-slate, #2c333f); max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
              :title="location.name"
            >
              {{ location.name }}
            </span>
          </div>

          <!-- Internal User Context (Coordinator / Field Technician) -->
          <div
            v-else-if="user"
            style="display: flex; align-items: center; gap: 8px; background-color: var(--color-surface-1, #efefef); padding: 4px 10px; border-radius: var(--radius-interactive, 4px); border: 1px solid var(--color-hairline, #c8cfda);"
          >
            <span
              style="font-family: var(--font-body, Inter, sans-serif); font-size: 11px; font-weight: 700; padding: 2px 6px; border-radius: 3px;"
              :style="{
                backgroundColor: user.role === 'COORDINATOR' ? '#ede9fe' : '#eaf8f1',
                color: user.role === 'COORDINATOR' ? '#6d28d9' : '#065f46',
                border: '1px solid ' + (user.role === 'COORDINATOR' ? '#c4b5fd' : '#86efac')
              }"
            >
              {{ roleLabel }}
            </span>
            <span
              style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; font-weight: 500; color: var(--color-slate, #2c333f); max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
              :title="user.email"
            >
              {{ user.name || user.email }}
            </span>
          </div>

          <!-- Role Quick Guide Link -->
          <a
            :href="quickGuideUrl"
            target="_blank"
            rel="noopener"
            class="vg-btn vg-btn-secondary"
            style="height: 32px; padding: 0 10px; font-size: 13px; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px; text-decoration: none;"
            :title="'Abrir guía rápida para ' + (isSiteSession ? 'Responsable de Sede' : roleLabel)"
            aria-label="Abrir guía rápida por rol en formato A4"
            data-testid="navbar-btn-role-guide"
          >
            <span>📖</span>
            <span style="font-size: 12px;">Ayuda</span>
          </a>

          <!-- Logout Button (4px interactive radius) -->
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            @click="handleLogout"
            style="height: 32px; padding: 0 12px; font-size: 13px; border-radius: var(--radius-interactive, 4px); display: inline-flex; align-items: center; gap: 6px; cursor: pointer;"
            title="Cerrar sesión activa"
          >
            <svg
              width="14"
              height="14"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
              <polyline points="16 17 21 12 16 7"></polyline>
              <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            <span
              v-if="isTechnicianSession && pendingOfflineCount > 0"
              data-testid="navbar-offline-queue-badge"
              style="display: inline-flex; align-items: center; gap: 4px; background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); border-radius: var(--radius-interactive); padding: 1px 6px; font-family: var(--font-body); font-size: 11px; font-weight: 700;"
              :title="'Acciones pendientes de sincronizar: ' + pendingOfflineCount"
            >
              ⏳ {{ pendingOfflineCount }}
            </span>
            <span>Salir</span>
          </button>
        </div>
      </div>

      <!-- Guardia de cierre de sesión con trabajo vivo en el dispositivo (Módulo 24,
           RF-06.1 / EARS 06.1): el recuento exacto se anuncia bajo la cabecera. -->
      <div
        v-if="logoutBlockedMessage"
        role="alert"
        data-testid="navbar-offline-logout-blocked"
        style="max-width: 1200px; margin: 0 auto; padding: 8px 16px 10px 16px;"
      >
        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); border-radius: var(--radius-interactive); padding: 8px 12px; font-family: var(--font-body); font-size: 12px; line-height: 1.4;">
          <span>🔒 {{ logoutBlockedMessage }}</span>
          <button
            type="button"
            style="background: none; border: none; color: var(--color-warning-text); font-size: 14px; cursor: pointer;"
            aria-label="Descartar aviso"
            @click="logoutBlockedMessage = ''"
          >
            ×
          </button>
        </div>
      </div>
    </header>
  `
};

export default AppNavbar;
