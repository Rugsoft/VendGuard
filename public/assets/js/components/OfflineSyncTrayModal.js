/**
 * VendGuard - OfflineSyncTrayModal (OfflineSyncTrayModal.js)
 *
 * Barra de estado y bandeja de sincronización del técnico de campo (Módulo 24,
 * RF-02.2 a RF-02.4, RF-04.1, RF-05.3, RF-06.1 a RF-06.3 y RNF-03):
 *
 *  - **Reloj de antigüedad (`EARS 02.2`, `D6`)**: muestra siempre el estado de
 *    conexión (en línea, sin conexión o sincronizando N de M) y el tiempo
 *    transcurrido desde la última copia local de la ruta.
 *  - **Aviso ámbar a las 4 horas (`EARS 02.3`)**: la política no se reimplementa
 *    aquí; la decide `evaluateSnapshotValidity` y este componente solo la pinta.
 *  - **Bloqueo de solo lectura a las 12 horas (`EARS 02.4`)**: se anuncia que no
 *    caben acciones nuevas, pero la cola ya encolada sigue intacta y enviable.
 *  - **Contador de cola (`EARS 04.1`)**: pendientes, en envío y conflictos.
 *  - **Cierre obligatorio de conflictos (`EARS 05.3`, `QA 1`, Art. III.1)**: cada
 *    conflicto ofrece un único botón de un toque —«Volcar trabajo al expediente y
 *    cerrar conflicto»—. No existe ningún botón de descarte silencioso: retirar
 *    el trabajo sin rastro del servidor no es una opción de la interfaz.
 *  - **Re-autenticación del mismo técnico (`EARS 06.3`, `D4`)**: si la sesión
 *    caducó al sincronizar, la bandeja identifica al titular de la cola para que
 *    vuelva a identificarse él, y no otro operario del dispositivo compartido.
 *
 * Dogma Vanilla: Vue 3 ESM nativo, sin dependencias externas. Todos los colores,
 * radios y tipografías entran por token (`var(--color-*)`, `var(--radius-*)`,
 * `var(--font-*)`); no se declara ningún literal cromático (RNF-03).
 */

import { ModalDialog } from './ModalDialog.js';
import { MUTATION_STATUS, SNAPSHOT_VALIDITY } from '../utils/OfflineStorage.js';

export const OfflineSyncTrayModal = {
  name: 'OfflineSyncTrayModal',
  components: {
    ModalDialog
  },
  props: {
    /** Sesión autenticada: sin técnico no hay bandeja que mostrar. */
    visible: {
      type: Boolean,
      default: false
    },
    /** Apertura del modal solicitada desde la vista (conflictos, volcados, reautenticación). */
    open: {
      type: Boolean,
      default: false
    },
    /** `ONLINE` | `OFFLINE` | `SYNCING` (estado de red percibido por la vista). */
    connectionStatus: {
      type: String,
      default: 'ONLINE'
    },
    /** Resultado de `evaluateSnapshotValidity` (`D6`). */
    validity: {
      type: Object,
      default: () => ({})
    },
    /** Resumen de `OfflineSyncManager.getQueueSummary()`. */
    queue: {
      type: Object,
      default: () => ({})
    },
    /** Elementos de la cola, en orden FIFO, con su `payload` y su conflicto. */
    items: {
      type: Array,
      default: () => []
    },
    /** Progreso del envío (`current` / `total`). */
    progress: {
      type: Object,
      default: () => ({ current: 0, total: 0 })
    },
    /** Titular de la cola cuando el servidor rechazó la sesión (401 / `EARS 06.3`). */
    reauthOwner: {
      type: Object,
      default: null
    },
    /** Impide disparar dos volcados simultáneos del mismo elemento. */
    dumpingMutationId: {
      type: String,
      default: ''
    },
    /**
     * Modo de exclusión realmente disponible entre pestañas (`R2`):
     * `web-locks`, `storage-lease` o `tab-only`. Solo el último se advierte,
     * porque es el único en el que dos ventanas podrían vaciar la misma cola.
     */
    lockMode: {
      type: String,
      default: 'web-locks'
    }
  },
  emits: ['update:visible', 'update:open', 'sync-now', 'dump-conflict', 'reauth'],
  data() {
    return {
      showTray: false
    };
  },
  watch: {
    open: {
      immediate: true,
      handler(value) {
        if (value === true) {
          this.showTray = true;
        }
      }
    }
  },
  computed: {
    isVisible() {
      return this.visible === true;
    },
    isSyncing() {
      return this.connectionStatus === 'SYNCING';
    },
    isOffline() {
      return this.connectionStatus === 'OFFLINE';
    },
    lockWarningMessage() {
      if (this.lockMode !== 'tab-only') {
        return '';
      }

      return 'Este navegador no ofrece cerrojo entre pestañas ni almacenamiento compartido: '
        + 'sincroniza desde una sola ventana para no duplicar envíos.';
    },
    validityStatus() {
      return String(this.validity?.status || SNAPSHOT_VALIDITY.MISSING);
    },
    isStale() {
      return this.validityStatus === SNAPSHOT_VALIDITY.STALE_WARNING;
    },
    isExpiredReadonly() {
      return this.validityStatus === SNAPSHOT_VALIDITY.EXPIRED_READONLY;
    },
    isMissingCopy() {
      return this.validityStatus === SNAPSHOT_VALIDITY.MISSING;
    },
    ageLabel() {
      const minutes = Number(this.validity?.ageMinutes);
      if (!Number.isFinite(minutes) || minutes < 0) {
        return 'Última sincronización: sin copia local de ruta';
      }

      if (minutes < 1) {
        return 'Última sincronización: hace menos de 1 min';
      }

      if (minutes < 60) {
        return `Última sincronización: hace ${minutes} min`;
      }

      const hours = Math.floor(minutes / 60);
      const rest = minutes % 60;
      const hourLabel = hours === 1 ? '1 h' : `${hours} h`;

      return rest === 0
        ? `Última sincronización: hace ${hourLabel}`
        : `Última sincronización: hace ${hourLabel} ${rest} min`;
    },
    connectionLabel() {
      if (this.isSyncing) {
        const total = Math.max(0, Number(this.progress?.total) || 0);
        const current = Math.max(0, Number(this.progress?.current) || 0);
        return `🔵 Sincronizando (${Math.min(current, total)}/${total})…`;
      }

      return this.isOffline ? '🟠 Modo sin conexión' : '🟢 En línea';
    },
    connectionTone() {
      if (this.isSyncing) {
        return {
          backgroundColor: 'var(--color-info-bg)',
          borderColor: 'var(--color-info-border)',
          color: 'var(--color-info-text)'
        };
      }

      if (this.isOffline) {
        return {
          backgroundColor: 'var(--color-warning-bg)',
          borderColor: 'var(--color-warning-border)',
          color: 'var(--color-warning-text)'
        };
      }

      return {
        backgroundColor: 'var(--color-success-bg)',
        borderColor: 'var(--color-success)',
        color: 'var(--color-success-text)'
      };
    },
    pendingCount() {
      const explicit = Number(this.queue?.total);
      return Number.isFinite(explicit)
        ? explicit
        : this.items.filter((item) => item?.status !== MUTATION_STATUS.CONFLICT).length;
    },
    pendingOnlyCount() {
      return Number(this.queue?.pending) || 0;
    },
    syncingCount() {
      return Number(this.queue?.syncing) || 0;
    },
    conflictCount() {
      return Number(this.queue?.conflicts) || 0;
    },
    conflictItems() {
      return this.items.filter((item) => item?.status === MUTATION_STATUS.CONFLICT);
    },
    pendingItems() {
      return this.items.filter((item) => item?.status !== MUTATION_STATUS.CONFLICT);
    },
    hasQueue() {
      return this.pendingCount > 0;
    },
    queueSummaryLabel() {
      if (!this.hasQueue) {
        return 'Bandeja vacía';
      }

      const parts = [];
      if (this.pendingOnlyCount > 0) parts.push(`${this.pendingOnlyCount} pendiente(s)`);
      if (this.syncingCount > 0) parts.push(`${this.syncingCount} enviándose`);
      if (this.conflictCount > 0) parts.push(`${this.conflictCount} en conflicto`);

      return parts.join(' · ');
    },
    staleMessage() {
      return this.validity?.warningMessage
        || '⚠️ Tu copia de ruta lleva más de 4 horas sin actualizarse. Recupera cobertura cuanto antes.';
    },
    readonlyMessage() {
      return this.validity?.errorMessage
        || '🔒 Copia de ruta caducada (> 12 h sin conexión). Conéctate para actualizar tu ruta antes de registrar nuevas intervenciones.';
    },
    photoQuotaLabel() {
      const count = Number(this.queue?.photoCount) || 0;
      const bytes = Number(this.queue?.photoBytes) || 0;
      if (count === 0) {
        return 'Sin fotografías en cola. La firma digital no consume cupo.';
      }

      const megabytes = (bytes / (1024 * 1024)).toFixed(1).replace('.', ',');

      return `${count} de 5 fotografías · ${megabytes} de 25 MB (la firma no computa)`;
    },
    syncDisabled() {
      return this.isSyncing || !this.hasQueue;
    }
  },
  methods: {
    closeTray() {
      this.showTray = false;
      this.$emit('update:open', false);
    },
    openTray() {
      this.showTray = true;
      this.$emit('update:open', true);
    },
    syncNow() {
      if (this.syncDisabled) {
        return;
      }
      this.$emit('sync-now');
    },
    dumpConflict(item) {
      if (!item?.client_mutation_id) {
        return;
      }
      this.$emit('dump-conflict', item.client_mutation_id);
    },
    requestReauth() {
      this.$emit('reauth');
    },
    isConflict(item) {
      return item?.status === MUTATION_STATUS.CONFLICT;
    },
    stopLabel(item) {
      const type = item?.stop_type === 'PREVENTIVE_ORDER' ? 'Orden preventiva' : 'Avería';
      const code = item?.ticket_or_order_code || item?.machine_code || `Parada ${item?.stop_id ?? ''}`;

      return `${type} ${String(code).trim()}`;
    },
    inSituTime(item) {
      const raw = item?.client_occurred_at;
      if (!raw) {
        return 'Hora in situ no declarada';
      }

      const date = new Date(raw);
      if (Number.isNaN(date.getTime())) {
        return 'Hora in situ no declarada';
      }

      const pad = (value) => String(value).padStart(2, '0');

      return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()} `
        + `${pad(date.getHours())}:${pad(date.getMinutes())}`;
    },
    conflictReason(item) {
      return item?.conflict?.message || item?.conflict?.reason || 'Rechazado por el servidor al sincronizar.';
    },
    workText(item) {
      const payload = item?.payload ?? {};
      const candidates = [
        payload.diagnosis,
        payload.action_taken,
        payload.pending_parts_reason,
        payload.pause_reason,
        payload.reason_text,
        payload.notes
      ];

      const text = candidates.find((value) => typeof value === 'string' && value.trim() !== '');

      return text ? text.trim() : 'Sin texto declarado en la operación.';
    },
    operationLabel(item) {
      const labels = {
        START_INCIDENT: 'Iniciar intervención',
        RESUME_INCIDENT_PENDING_INFO: 'Reanudar intervención',
        PAUSE_INCIDENT_PARTS: 'Pausar por repuesto',
        PAUSE_INCIDENT_PENDING_INFO: 'Pausar por falta de acceso',
        RESOLVE_INCIDENT: 'Resolver avería',
        ADD_INCIDENT_COMMENT: 'Comentario in situ',
        START_PREVENTIVE: 'Iniciar inspección preventiva',
        COMPLETE_PREVENTIVE: 'Completar inspección preventiva',
        REINSPECT_PREVENTIVE: 'Registrar reinspección'
      };

      return labels[item?.operation_type] || 'Operación sin conexión';
    },
    isDumping(item) {
      return this.dumpingMutationId !== '' && String(item?.client_mutation_id) === String(this.dumpingMutationId);
    }
  },
  template: `
    <section
      v-if="visible"
      data-testid="offline-sync-tray"
      aria-live="polite"
      style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px 12px; margin-bottom: 12px;"
    >
      <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
        <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
          <span
            data-testid="offline-connection-state"
            style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; border: 1px solid; border-radius: var(--radius-interactive); font-family: var(--font-body); font-size: 12px; font-weight: 700;"
            :style="connectionTone"
          >
            {{ connectionLabel }}
          </span>
          <span style="font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted);">
            {{ ageLabel }}
          </span>
        </div>

        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            data-testid="offline-open-tray"
            style="min-height: 44px; padding: 0 12px; font-size: 12px; font-weight: 600;"
            @click="openTray"
          >
            🧺 {{ queueSummaryLabel }}
          </button>
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            data-testid="offline-sync-now"
            style="min-height: 44px; padding: 0 12px; font-size: 12px; font-weight: 600;"
            :disabled="syncDisabled"
            @click="syncNow"
          >
            ⟳ Sincronizar ahora
          </button>
        </div>
      </div>

      <div
        v-if="isStale"
        role="alert"
        data-testid="offline-stale-warning"
        style="margin-top: 10px; background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); border-radius: var(--radius-interactive); padding: 10px 12px; font-family: var(--font-body); font-size: 12px; line-height: 1.4;"
      >
        {{ staleMessage }}
      </div>

      <div
        v-if="lockWarningMessage"
        role="alert"
        data-testid="offline-lock-warning"
        style="margin-top: 10px; background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); border-radius: var(--radius-interactive); padding: 10px 12px; font-family: var(--font-body); font-size: 12px; line-height: 1.4;"
      >
        {{ lockWarningMessage }}
      </div>

      <div
        v-if="isExpiredReadonly"
        role="alert"
        data-testid="offline-readonly-warning"
        style="margin-top: 10px; background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); border-radius: var(--radius-interactive); padding: 10px 12px; font-family: var(--font-body); font-size: 12px; line-height: 1.4;"
      >
        {{ readonlyMessage }}
      </div>

      <div
        v-else-if="isMissingCopy && isOffline"
        role="status"
        data-testid="offline-missing-copy"
        style="margin-top: 10px; background-color: var(--color-neutral-bg); border: 1px solid var(--color-neutral-border); color: var(--color-slate); border-radius: var(--radius-interactive); padding: 10px 12px; font-family: var(--font-body); font-size: 12px; line-height: 1.4;"
      >
        No hay copia local de tu ruta en este dispositivo. Conéctate una vez para descargarla antes de trabajar sin cobertura.
      </div>

      <button
        v-if="conflictCount > 0"
        type="button"
        data-testid="offline-conflict-alert"
        style="margin-top: 10px; width: 100%; min-height: 44px; text-align: left; background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); color: var(--color-error-text); border-radius: var(--radius-interactive); padding: 10px 12px; font-family: var(--font-body); font-size: 13px; font-weight: 700; cursor: pointer;"
        @click="openTray"
      >
        ⚠️ {{ conflictCount }} intervención(es) en conflicto: deben volcarse al expediente antes de cerrar sesión.
      </button>

      <ModalDialog
        :model-value="showTray"
        title="Bandeja sin conexión"
        subtitle="Trabajo realizado in situ pendiente de sincronizar con el servidor"
        size="md"
        @update:model-value="showTray = $event"
        @close="closeTray"
      >
        <div
          v-if="reauthOwner"
          role="alert"
          data-testid="offline-reauth-owner"
          style="background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); border-radius: var(--radius-card); padding: 12px; margin-bottom: 14px; font-family: var(--font-body); font-size: 13px; line-height: 1.45;"
        >
          <strong>Sesión caducada.</strong>
          La cola pertenece a {{ reauthOwner.technician_name || reauthOwner.name || 'este técnico' }}
          ({{ reauthOwner.technician_email || reauthOwner.email || 'correo no disponible' }}).
          Vuelve a identificarte con esa misma cuenta para sincronizar: no se ha borrado nada.
          <div style="margin-top: 10px;">
            <button
              type="button"
              class="vg-btn vg-btn-primary"
              style="min-height: 44px; padding: 0 14px; font-size: 13px; font-weight: 600;"
              @click="requestReauth"
            >
              Reautenticar al mismo técnico
            </button>
          </div>
        </div>

        <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px;">
          <span class="vg-chip">{{ pendingOnlyCount }} pendiente(s)</span>
          <span v-if="syncingCount > 0" class="vg-chip">{{ syncingCount }} en envío</span>
          <span v-if="conflictCount > 0" class="vg-chip vg-chip-warning">{{ conflictCount }} en conflicto</span>
        </div>

        <p style="font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted); margin: 0 0 12px 0; line-height: 1.4;">
          {{ photoQuotaLabel }}
        </p>

        <div v-if="!hasQueue" style="font-family: var(--font-body); font-size: 13px; color: var(--color-slate); padding: 12px; border: 1px dashed var(--color-hairline); border-radius: var(--radius-card);">
          No quedan operaciones en la bandeja de este dispositivo.
        </div>

        <div v-if="conflictItems.length > 0" style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px;">
          <div
            v-for="item in conflictItems"
            :key="item.client_mutation_id"
            data-testid="offline-conflict-card"
            style="border: 1px solid var(--color-error-border); border-left: 4px solid var(--color-error); border-radius: var(--radius-card); background-color: var(--color-surface-card); padding: 12px;"
          >
            <div style="display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
              <strong style="font-family: var(--font-display); font-size: 14px; font-weight: 500; color: var(--color-slate);">
                {{ stopLabel(item) }}
              </strong>
              <span style="font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted);">
                {{ inSituTime(item) }}
              </span>
            </div>

            <div style="font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted); margin-bottom: 4px;">
              {{ operationLabel(item) }}
            </div>

            <div style="font-family: var(--font-body); font-size: 12px; color: var(--color-error-text); margin-bottom: 8px; line-height: 1.4;">
              {{ conflictReason(item) }}
            </div>

            <div style="font-family: var(--font-body); font-size: 13px; color: var(--color-slate); background-color: var(--color-canvas); border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); padding: 8px 10px; margin-bottom: 10px; white-space: pre-line; line-height: 1.45;">
              {{ workText(item) }}
            </div>

            <button
              type="button"
              class="vg-btn vg-btn-primary"
              data-testid="offline-conflict-dump"
              style="width: 100%; min-height: 44px; padding: 0 12px; font-size: 13px; font-weight: 700;"
              :disabled="isDumping(item)"
              @click="dumpConflict(item)"
            >
              {{ isDumping(item) ? 'Volcando al expediente…' : 'Volcar trabajo al expediente y cerrar conflicto' }}
            </button>
          </div>
        </div>

        <div v-if="pendingItems.length > 0" style="display: flex; flex-direction: column; gap: 8px;">
          <div
            v-for="item in pendingItems"
            :key="item.client_mutation_id"
            data-testid="offline-pending-card"
            style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 10px 12px;"
          >
            <div style="display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap;">
              <span style="font-family: var(--font-body); font-size: 13px; font-weight: 600; color: var(--color-slate);">
                ⏳ {{ stopLabel(item) }}
              </span>
              <span style="font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted);">
                {{ inSituTime(item) }}
              </span>
            </div>
            <div style="font-family: var(--font-body); font-size: 12px; color: var(--color-ink-muted); margin-top: 2px;">
              {{ operationLabel(item) }} · se enviará al recuperar cobertura
            </div>
          </div>
        </div>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" style="min-height: 44px; padding: 0 14px;" @click="closeTray">
            Cerrar
          </button>
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            style="min-height: 44px; padding: 0 14px; font-weight: 600;"
            :disabled="syncDisabled"
            @click="syncNow"
          >
            ⟳ Sincronizar ahora
          </button>
        </template>
      </ModalDialog>
    </section>
  `
};

export default OfflineSyncTrayModal;
