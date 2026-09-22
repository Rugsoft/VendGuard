/**
 * VendGuard - AppNavbar Component (AppNavbar.js)
 * 
 * Top application navigation bar providing:
 * - Brand logo and title with Docker Design Tokens (Inter & DM Sans, #2560ff).
 * - Contextual session badges (Site code badge for Location Responsible, Role badge for Coordinator/Technician).
 * - Accessible logout action invoking store.clearSession() and emitting 'logout'.
 * - Global network loading indicator bar.
 */

import { store } from '../store.js';

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
  emits: ['logout', 'navigate-home'],
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
    roleLabel() {
      if (!this.user?.role) return '';
      switch (this.user.role) {
        case 'COORDINATOR': return 'Coordinación';
        case 'TECHNICIAN': return 'Técnico de Ruta';
        default: return this.user.role;
      }
    }
  },
  methods: {
    handleLogout() {
      store.clearSession();
      this.$emit('logout');
    },
    handleBrandClick() {
      this.$emit('navigate-home');
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
            <span>Salir</span>
          </button>
        </div>
      </div>
    </header>
  `
};

export default AppNavbar;
