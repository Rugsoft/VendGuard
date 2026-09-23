/**
 * VendGuard - Main Frontend Application Root (app.js)
 * 
 * Root Vue 3 Application orchestrating the 3 profile views:
 * 1. LocationPortalView - Portal del Responsable de Sede (RF-01, RF-02, RF-03)
 * 2. CoordinatorDashboardView - Panel de Coordinación y Triaje 24/7 (RF-05, RF-06, RF-11)
 * 3. TechnicianRouteView - Vista Móvil de Ruta del Técnico (RF-07, RF-08, RNF-01)
 * 
 * Dogma Vanilla: Native Vue 3 Composition/Options API via ES Modules (zero npm build step).
 */

import { createApp } from './vendor/vue.esm-browser.prod.js';
import { store, restoreSession } from './store.js';
import { AppNavbar } from './components/AppNavbar.js';
import { LocationPortalView } from './views/LocationPortalView.js';
import { CoordinatorDashboardView } from './views/CoordinatorDashboardView.js';
import { TechnicianRouteView } from './views/TechnicianRouteView.js';
import { QrReportView } from './views/QrReportView.js';

export const App = {
  name: 'VendGuardApp',
  components: {
    AppNavbar,
    LocationPortalView,
    CoordinatorDashboardView,
    TechnicianRouteView,
    QrReportView
  },
  data() {
    return {
      currentView: 'portal', // 'portal' | 'coordinator' | 'technician' | 'qr'
      siteCode: 'SEDE-BCN-01',
      showCredentialsGuide: true,
      qrMachineCode: '',
      qrSiteCode: ''
    };
  },
  computed: {
    user() {
      return store.state.user;
    },
    location() {
      return store.state.location;
    },
    isAuthenticated() {
      return store.isAuthenticated;
    },
    flashMessage() {
      return store.state.flashMessage;
    }
  },
  created() {
    // 1. Detección prioritaria de Deep Link QR (?qr=... o ?code=...) (RF-03, RNF-02 / EARS 3.1)
    if (typeof window !== 'undefined' && window.location) {
      const urlParams = new URLSearchParams(window.location.search);
      const qrParam = urlParams.get('qr') || urlParams.get('code');
      if (qrParam && qrParam.trim() !== '') {
        this.currentView = 'qr';
        this.qrMachineCode = qrParam.trim();
        this.qrSiteCode = (urlParams.get('site') || urlParams.get('site_code') || '').trim();
        return;
      }
    }

    // 2. Restauración de sesión si no es acceso por código QR
    const restored = restoreSession();
    if (restored) {
      if (store.state.user?.role === 'COORDINATOR') {
        this.currentView = 'coordinator';
      } else if (store.state.user?.role === 'TECHNICIAN') {
        this.currentView = 'technician';
      } else if (store.state.location) {
        this.currentView = 'portal';
        this.siteCode = store.state.location.site_code;
      }
    }
  },
  methods: {
    switchView(view) {
      this.currentView = view;
    },
    handleLogout() {
      store.clearSession();
    },
    dismissFlash() {
      store.state.flashMessage = null;
    }
  },
  template: `
    <div style="min-height: 100vh; display: flex; flex-direction: column; background-color: var(--color-canvas, #f9fafb);">
      <!-- Global Top Navbar (Oculto en vista de escaneo QR ciudadano) -->
      <AppNavbar v-if="currentView !== 'qr'" @logout="handleLogout" @navigate-home="currentView = 'portal'" />

      <!-- Profile Selector Bar (Quick Testing Bar - Oculto en vista QR ciudadano) -->
      <div
        v-if="currentView !== 'qr'"
        style="background-color: #ffffff; border-bottom: 1px solid var(--color-hairline, #c8cfda); padding: 8px 16px;"
      >
        <div style="max-width: 1200px; margin: 0 auto; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px;">
          <!-- Profile Tabs -->
          <div style="display: flex; gap: 6px; align-items: center;">
            <span style="font-size: 12px; font-weight: 600; color: var(--color-ink-muted, #6c7e9d); margin-right: 6px;">
              Perfil activo:
            </span>
            <button
              type="button"
              class="vg-btn"
              :class="currentView === 'portal' ? 'vg-btn-primary' : 'vg-btn-secondary'"
              style="height: 32px; font-size: 13px; padding: 0 12px; border-radius: var(--radius-interactive, 4px);"
              @click="switchView('portal')"
            >
              🏢 1. Responsable Sede
            </button>
            <button
              type="button"
              class="vg-btn"
              :class="currentView === 'coordinator' ? 'vg-btn-primary' : 'vg-btn-secondary'"
              style="height: 32px; font-size: 13px; padding: 0 12px; border-radius: var(--radius-interactive, 4px);"
              @click="switchView('coordinator')"
            >
              📊 2. Coordinador
            </button>
            <button
              type="button"
              class="vg-btn"
              :class="currentView === 'technician' ? 'vg-btn-primary' : 'vg-btn-secondary'"
              style="height: 32px; font-size: 13px; padding: 0 12px; border-radius: var(--radius-interactive, 4px);"
              @click="switchView('technician')"
            >
              📱 3. Técnico Móvil
            </button>
          </div>

          <!-- Quick Test Credentials Helper Toggle -->
          <div>
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              style="height: 28px; font-size: 11px; padding: 0 8px;"
              @click="showCredentialsGuide = !showCredentialsGuide"
            >
              {{ showCredentialsGuide ? 'Ocultar credenciales demo' : 'Ver credenciales demo' }}
            </button>
          </div>
        </div>

        <!-- Collapsible Credentials Guide -->
        <div
          v-if="showCredentialsGuide"
          style="max-width: 1200px; margin: 8px auto 0 auto; background-color: #f1f5f9; border-radius: var(--radius-interactive, 4px); padding: 8px 12px; font-size: 12px; color: var(--color-slate, #2c333f); display: flex; flex-wrap: wrap; gap: 16px; align-items: center;"
        >
          <div>
            <strong>🏢 Responsable Sede:</strong> Código <code>SEDE-BCN-01</code> o <code>SEDE-BCN-02</code>
          </div>
          <div>
            <strong>📊 Coordinador:</strong> <code>coordinacion@vendguard.internal</code> / <code>Password123!</code>
          </div>
          <div>
            <strong>📱 Técnico de Ruta:</strong> <code>jordi.ruta@vendguard.internal</code> / <code>Password123!</code>
          </div>
        </div>
      </div>

      <!-- Flash Message Toast -->
      <div
        v-if="flashMessage"
        style="max-width: 1200px; margin: 12px auto 0 auto; padding: 0 16px; width: 100%;"
      >
        <div
          :style="{
            backgroundColor: flashMessage.type === 'error' ? '#fee2e2' : '#eaf8f1',
            borderColor: flashMessage.type === 'error' ? '#ef4444' : '#38bd7d',
            color: flashMessage.type === 'error' ? '#991b1b' : '#065f46'
          }"
          style="border: 1px solid; border-radius: var(--radius-interactive, 4px); padding: 10px 14px; font-size: 13px; display: flex; justify-content: space-between; align-items: center;"
        >
          <span>{{ flashMessage.text }}</span>
          <button type="button" @click="dismissFlash" style="background: none; border: none; cursor: pointer; font-size: 14px;">✕</button>
        </div>
      </div>

      <!-- Main Content Area: Dynamic View -->
      <main :style="{ flex: 1, padding: currentView === 'qr' ? '0' : '16px 0' }">
        <QrReportView
          v-if="currentView === 'qr'"
          :code="qrMachineCode"
          :site="qrSiteCode"
          @go-home="switchView('portal')"
        />
        <LocationPortalView
          v-else-if="currentView === 'portal'"
          :initial-site-code="siteCode"
          @authenticated="siteCode = $event"
        />
        <CoordinatorDashboardView
          v-else-if="currentView === 'coordinator'"
        />
        <TechnicianRouteView
          v-else-if="currentView === 'technician'"
        />
      </main>

      <!-- Footer (Oculto en vista QR móvil) -->
      <footer v-if="currentView !== 'qr'" style="background-color: #ffffff; border-top: 1px solid var(--color-hairline, #c8cfda); padding: 12px 16px; text-align: center; font-size: 12px; color: var(--color-ink-muted, #6c7e9d);">
        VendGuard MVP v1.0.0 · Sistema de Gestión de Incidencias de Vending · Dogma Vanilla & Docker Design System
      </footer>
    </div>
  `
};

if (typeof document !== 'undefined') {
  createApp(App).mount('#app');
}

export default App;
