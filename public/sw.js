/**
 * VendGuard — Service Worker del App Shell (Módulo 24 · T-OFFLINE-10)
 *
 * Certifica RF-01.2 (la interfaz de trabajo carga completa sin red en menos de 2 s si
 * se abrió antes con conexión), RF-01.3 (actualización transparente de versión) y
 * RNF-01. Los contratos de este fichero viven en
 * `specs/technical/offline_pwa_contracts.md` §2.2 y no se inventan aquí.
 *
 * Reglas de interceptación:
 *   1. `/api/*` y todo método distinto de GET: **Network-Only estricto**. Nunca se
 *      guarda una respuesta de API en `CacheStorage`: en un dispositivo compartido una
 *      respuesta cacheada sería la sesión de otro técnico (Art. V.4).
 *   2. GET del mismo origen (documentos, scripts, estilos, iconos):
 *      *Stale-While-Revalidate*. Se sirve la copia al instante y se refresca en
 *      segundo plano cuando hay red.
 *   3. Navegación HTML sin red ni copia: se devuelve el App Shell precacheado en lugar
 *      de la pantalla de error del navegador.
 *
 * Dogma Vanilla: sin Workbox ni dependencias externas (Art. IV.3).
 */

'use strict';

/** Nombre de caché versionado (contratos §2.2). Subirlo es lo que invalida el shell. */
const CACHE_NAME = 'vendguard-app-shell-v1';

/** Prefijo de la superficie HTTP que jamás entra en caché (Art. V.4). */
const API_PATH_PREFIX = '/api/';

/**
 * App Shell completo: documento, hojas de estilo y todos los módulos ES de Vue 3.
 *
 * La lista es explícita a propósito —el Service Worker no puede enumerar el sistema de
 * ficheros— y debe crecer con cada módulo nuevo, o la interfaz se quedaría a medias sin
 * conexión. `styles.css` entra aunque no lo enlace `index.html` porque lo carga bajo
 * demanda la pestaña de stock de furgoneta del coordinador.
 */
const APP_SHELL_ASSETS = [
  '/',
  '/index.html',
  '/manifest.webmanifest',
  // Hojas de estilo
  '/assets/css/design-tokens.css',
  '/assets/css/metrics-print.css',
  '/assets/css/qr-print.css',
  '/assets/css/service-sheet.css',
  '/assets/css/styles.css',
  // Núcleo de la aplicación
  '/assets/js/app.js',
  '/assets/js/api.js',
  '/assets/js/store.js',
  '/assets/js/vendor/vue.esm-browser.prod.js',
  // Utilidades
  '/assets/js/utils/AuditActionLabels.js',
  '/assets/js/utils/DateTimeFormat.js',
  '/assets/js/utils/IncidentStatusPermissions.js',
  '/assets/js/utils/MapZoomPan.js',
  '/assets/js/utils/Mercator.js',
  '/assets/js/utils/OpportunisticContext.js',
  '/assets/js/utils/PreventiveLabels.js',
  '/assets/js/utils/SuggestionFormat.js',
  // Vistas
  '/assets/js/views/CoordinatorDashboardView.js',
  '/assets/js/views/CoordinatorMetricsView.js',
  '/assets/js/views/LocationPortalView.js',
  '/assets/js/views/PublicRefundTrackingView.js',
  '/assets/js/views/QrBatchPrintView.js',
  '/assets/js/views/QrReportView.js',
  '/assets/js/views/TechnicianMetricsView.js',
  '/assets/js/views/TechnicianRouteView.js',
  // Componentes
  '/assets/js/components/AdminLocationsTab.js',
  '/assets/js/components/AdminMachinesTab.js',
  '/assets/js/components/AdminUsersTab.js',
  '/assets/js/components/AppNavbar.js',
  '/assets/js/components/AssignSuggestionPanel.js',
  '/assets/js/components/AuditLogViewer.js',
  '/assets/js/components/ConnectionStateBanner.js',
  '/assets/js/components/CoordinatorAbsencesTab.js',
  '/assets/js/components/CoordinatorFleetTab.js',
  '/assets/js/components/CoordinatorIncidentDetailModal.js',
  '/assets/js/components/CoordinatorNotificationCenterModal.js',
  '/assets/js/components/CoordinatorPreventiveDashboard.js',
  '/assets/js/components/CoordinatorPreventiveOrderDetailModal.js',
  '/assets/js/components/CoordinatorPreventiveOrdersTab.js',
  '/assets/js/components/CoordinatorPreventiveSettingsModal.js',
  '/assets/js/components/CoordinatorRefundsTab.js',
  '/assets/js/components/CoordinatorSanitarySweepPanel.js',
  '/assets/js/components/CoordinatorSparePartsAnalyticsTab.js',
  '/assets/js/components/CoordinatorSparePartsTab.js',
  '/assets/js/components/CoordinatorTerritorialMapTab.js',
  '/assets/js/components/CoordinatorVanStockTab.js',
  '/assets/js/components/CoordinatorWorkHoursTab.js',
  '/assets/js/components/ExecutiveReportModal.js',
  '/assets/js/components/ImagePreview.js',
  '/assets/js/components/IncidentBadge.js',
  '/assets/js/components/IncidentCommentThreadModal.js',
  '/assets/js/components/IncidentReportModal.js',
  '/assets/js/components/LocationRefundsTab.js',
  '/assets/js/components/MachineCard.js',
  '/assets/js/components/MetricBreakdownTable.js',
  '/assets/js/components/MetricCards.js',
  '/assets/js/components/ModalDialog.js',
  '/assets/js/components/OperationalZoneManagementTab.js',
  '/assets/js/components/PendingInfoPauseModal.js',
  '/assets/js/components/QrInactiveMachineNotice.js',
  '/assets/js/components/QrLabelModal.js',
  '/assets/js/components/QrRefundRequestBlock.js',
  '/assets/js/components/QrSanitaryQuarantineModal.js',
  '/assets/js/components/QrSeasonalPauseNotice.js',
  '/assets/js/components/ReopenTicketModal.js',
  '/assets/js/components/SanitaryCertificateModal.js',
  '/assets/js/components/SatisfactionSurveyModal.js',
  '/assets/js/components/ServiceSheetModal.js',
  '/assets/js/components/SiteGlobalCertificateModal.js',
  '/assets/js/components/SiteSanitaryStatusTab.js',
  '/assets/js/components/TechnicianChecklistModal.js',
  '/assets/js/components/TechnicianMachineHistoryModal.js',
  '/assets/js/components/TechnicianOpportunisticModal.js',
  '/assets/js/components/TechnicianPreventiveRouteTab.js',
  '/assets/js/components/TechnicianReinspectionModal.js',
  '/assets/js/components/TechnicianResolutionPartsBlock.js',
  '/assets/js/components/TechnicianResolutionRefundBlock.js',
  '/assets/js/components/TechnicianRouteMapModal.js',
  '/assets/js/components/TechnicianSanitaryResolutionBlock.js',
  '/assets/js/components/TechnicianServiceSheetBlock.js',
  '/assets/js/components/TechnicianSparePartsPauseModal.js',
  '/assets/js/components/TechnicianVanStockPanel.js',
];

/**
 * `install`: precachea el App Shell y toma el control sin esperar a cerrar pestañas.
 *
 * Se cachea activo a activo en lugar de con `addAll`: `addAll` rechaza la instalación
 * completa si un solo fichero falta, y un módulo retirado dejaría la aplicación sin
 * ninguna capacidad sin conexión. Un fallo puntual se registra y no arrastra al resto.
 */
self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);

    await Promise.all(APP_SHELL_ASSETS.map(async (asset) => {
      try {
        await cache.add(asset);
      } catch (error) {
        console.warn(`VendGuard · No se pudo precachear ${asset}:`, error);
      }
    }));

    await self.skipWaiting();
  })());
});

/** `activate`: retira las cachés de versiones anteriores y toma el control del cliente. */
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();

    await Promise.all(keys
      .filter((key) => key !== CACHE_NAME)
      .map((key) => caches.delete(key)));

    await self.clients.claim();
  })());
});

/** ¿La URL pertenece a la superficie de API? */
function isApiRequest(url) {
  try {
    return new URL(url).pathname.startsWith(API_PATH_PREFIX);
  } catch (error) {
    return false;
  }
}

/**
 * `fetch`: Network-Only para la API y Stale-While-Revalidate para el resto.
 */
self.addEventListener('fetch', (event) => {
  const request = event.request;

  // 1. Network-Only estricto (Art. V.4): /api/* y cualquier método que no sea GET van
  //    siempre a la red. Si la red falla, el error viaja tal cual; nunca se sustituye
  //    por una respuesta guardada.
  if (request.method !== 'GET' || isApiRequest(request.url)) {
    event.respondWith(fetch(request));
    return;
  }

  // Fuera del mismo origen no se cachea nada: la caché es del App Shell propio.
  const requestUrl = new URL(request.url);
  if (requestUrl.origin !== self.location.origin) {
    return;
  }

  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);

    const network = fetch(request).then((response) => {
      if (response.ok && response.type === 'basic') {
        cache.put(request, response.clone()).catch(() => {});
      }

      return response;
    }).catch(() => null);

    if (cached) {
      // El refresco sigue en segundo plano: la respuesta ya está servida (RNF-01).
      event.waitUntil(network);
      return cached;
    }

    const fresh = await network;
    if (fresh) {
      return fresh;
    }

    // 3. Sin red y sin copia: el shell precacheado, nunca la pantalla de error.
    if (request.mode === 'navigate') {
      const shell = await cache.match('/index.html');
      if (shell) {
        return shell;
      }
    }

    return Response.error();
  })());
});
