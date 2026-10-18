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
import { ConnectionStateBanner } from './components/ConnectionStateBanner.js';
import { LocationPortalView } from './views/LocationPortalView.js';
import { CoordinatorDashboardView } from './views/CoordinatorDashboardView.js';
import { TechnicianRouteView } from './views/TechnicianRouteView.js';
import { QrReportView } from './views/QrReportView.js';
import { PublicRefundTrackingView } from './views/PublicRefundTrackingView.js';

export const App = {
  name: 'VendGuardApp',
  components: {
    AppNavbar,
    ConnectionStateBanner,
    LocationPortalView,
    CoordinatorDashboardView,
    TechnicianRouteView,
    QrReportView,
    PublicRefundTrackingView
  },
  data() {
    return {
      currentView: 'portal', // 'portal' | 'coordinator' | 'technician' | 'qr' | 'tracking'
      trackingToken: '',
      siteCode: 'SEDE-BCN-01',
      showCredentialsGuide: true,
      qrMachineCode: '',
      qrSiteCode: '',
      deepLinkTicket: ''
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
    /** ¿La URL apunta al Portal de Sede desde el correo de seguimiento? */
    let siteDeepLink = false;

    // 1. Seguimiento público por token (?track=...), antes de restaurar cualquier sesión.
    if (typeof window !== 'undefined' && window.location) {
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.has('track')) {
        this.currentView = 'tracking';
        this.trackingToken = (urlParams.get('track') || '').trim();
        return;
      }

      // 2. Detección de Deep Link QR (?qr=... o ?code=...) (RF-03, RNF-02 / EARS 3.1)
      const qrParam = urlParams.get('qr') || urlParams.get('code');
      if (qrParam && qrParam.trim() !== '') {
        this.currentView = 'qr';
        this.qrMachineCode = qrParam.trim();
        this.qrSiteCode = (urlParams.get('site') || urlParams.get('site_code') || '').trim();
        return;
      }

      // 3. Deep Link al aviso de asignación del técnico (Módulo 15, RF-TG-08):
      //    `/?tech=1&ticket=INC-…`. El enlace NO autentica: abre la vista del
      //    técnico y, si no hay sesión, pide acceso; el ticket queda en la URL y se
      //    aplica cuando la ruta se carga. Un enlace reenviado no es una credencial.
      if (urlParams.has('tech')) {
        this.currentView = 'technician';
        this.deepLinkTicket = (urlParams.get('ticket') || '').trim();
      }

      // 4. Deep Link al Portal de Sede desde el correo de seguimiento
      //    (/?site=SEDE-BCN-01&ticket=INC-2026-...). El botón del aviso tiene que
      //    aterrizar en el portal de ESA sede, no en el formulario vacío: el
      //    código queda prellenado y, tras identificarse con la clave de centro,
      //    el expediente citado abre su hilo de conversación.
      const siteParam = (urlParams.get('site') || urlParams.get('site_code') || '').trim();
      if (siteParam !== '') {
        this.currentView = 'portal';
        this.siteCode = siteParam;
        this.deepLinkTicket = (urlParams.get('ticket') || '').trim();
        siteDeepLink = true;
      }
    }

    // 5. Restauración de sesión si no es acceso público por QR o seguimiento
    const restored = restoreSession();
    if (restored) {
      if (store.state.location) {
        // Sesión de sede ya abierta: se reutiliza si es la sede del enlace.
        if (!siteDeepLink || store.state.location.site_code === this.siteCode) {
          this.currentView = 'portal';
          this.siteCode = store.state.location.site_code;
        } else {
          // El enlace cita otra sede: manda el enlace, no la sesión anterior.
          store.clearSession();
        }
      } else if (siteDeepLink) {
        // El correo pedía el Portal de Sede: la sesión interna no lo secuestra.
        this.currentView = 'portal';
      } else if (store.state.user?.role === 'COORDINATOR') {
        this.currentView = 'coordinator';
      } else if (store.state.user?.role === 'TECHNICIAN') {
        this.currentView = 'technician';
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
      <!--
        Banner flotante global del estado de conexión (RF-04.3, RF-04.4, RNF-06; T-HYBRID-18).
        Se monta en la RAÍZ y sin guarda de vista a propósito: tiene que existir en las cinco
        vistas —Sede, Coordinador, Técnico, Portal QR y seguimiento público—, incluidas las dos
        públicas que ocultan cabecera, selector de perfil y pie. No cambia la navegación
        ordinaria porque en estado 'idle' no pinta nada y su capa no captura clics
        (pointer-events: none), y no ocupa hueco en el flujo: su capa es position: fixed.
      -->
      <ConnectionStateBanner />

      <!-- Global Top Navbar (Oculto en las vistas públicas QR y seguimiento) -->
      <AppNavbar v-if="currentView !== 'qr' && currentView !== 'tracking'" @logout="handleLogout" @navigate-home="currentView = 'portal'" />

      <!-- Profile Selector Bar (Oculto en las vistas públicas QR y seguimiento) -->
      <div
        v-if="currentView !== 'qr' && currentView !== 'tracking'"
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
        v-if="flashMessage && currentView !== 'tracking'"
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
      <main :style="{ flex: 1, padding: currentView === 'qr' || currentView === 'tracking' ? '0' : '16px 0' }">
        <PublicRefundTrackingView
          v-if="currentView === 'tracking'"
          :token="trackingToken"
        />
        <QrReportView
          v-else-if="currentView === 'qr'"
          :code="qrMachineCode"
          :site="qrSiteCode"
          @go-home="switchView('portal')"
        />
        <LocationPortalView
          v-else-if="currentView === 'portal'"
          :initial-site-code="siteCode"
          :initial-ticket-code="deepLinkTicket"
          @authenticated="siteCode = $event"
        />
        <CoordinatorDashboardView
          v-else-if="currentView === 'coordinator'"
        />
        <TechnicianRouteView
          v-else-if="currentView === 'technician'"
          :initial-ticket-code="deepLinkTicket"
        />
      </main>

      <!-- Footer (Oculto en las vistas públicas QR y seguimiento) -->
      <footer v-if="currentView !== 'qr' && currentView !== 'tracking'" style="background-color: #ffffff; border-top: 1px solid var(--color-hairline, #c8cfda); padding: 12px 16px; text-align: center; font-size: 12px; color: var(--color-ink-muted, #6c7e9d);">
        VendGuard MVP v1.0.0 · Sistema de Gestión de Incidencias de Vending · Dogma Vanilla & Docker Design System
      </footer>
    </div>
  `
};

if (typeof document !== 'undefined') {
  createApp(App).mount('#app');
}

export default App;
