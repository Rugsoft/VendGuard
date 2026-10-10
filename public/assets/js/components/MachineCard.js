/**
 * VendGuard - MachineCard Component (MachineCard.js)
 * 
 * Renders individual vending machine card for the Location Responsible portal.
 * Adheres to Docker Design Tokens (RNF-06):
 * - 8px card container border radius (--radius-card).
 * - 4px interactive button/badge border radius (--radius-interactive).
 * - Immediate visual distinction between operational machines and machines with active incidents (RF-02).
 *
 * Módulo 10 (T-COM-14): cada avería activa (en curso o en garantía) expone una
 * insignia interactiva de conversación con el recuento numérico de comentarios
 * PÚBLICOS (`active_incident.public_comments_count`). El total de mensajes jamás
 * se lee en este canal: contabilizar notas internas revelaría su existencia al
 * Responsable de Sede (RF-01.1, RF-02.1, Constitución Art. V.4).
 */

import { IncidentBadge } from './IncidentBadge.js';
import { MACHINE_TYPE_LABELS, MACHINE_TYPE_ICONS, isPerishableMachineType, isResolvedStatus, isPendingInfoStatus, AMBER_TECHNICAL_TOKENS } from '../utils/IncidentStatusPermissions.js';
import { PAUSE_REASON_CATEGORIES } from './PendingInfoPauseModal.js';

// Machine-type labels, icons and the sanitary perishable rule live in the shared
// module utils/IncidentStatusPermissions.js (mirrors MachineType.php); unknown
// types keep the local neutral fallback.

export const MachineCard = {
  name: 'MachineCard',
  components: {
    IncidentBadge
  },
  props: {
    /**
     * Machine data object:
     * { id, code, model, machine_type, floor_wing, notes, active_incident }
     */
    machine: {
      type: Object,
      required: true
    }
  },
  emits: ['report', 'open-comments', 'reopen', 'select'],
  computed: {
    activeIncident() {
      return this.machine?.active_incident || null;
    },
    hasActiveIncident() {
      return this.activeIncident !== null;
    },
    isUnderWarranty() {
      if (!this.activeIncident) return false;
      return isResolvedStatus(this.activeIncident.status);
    },
    isPendingInfo() {
      if (!this.activeIncident) return false;
      return isPendingInfoStatus(this.activeIncident.status);
    },
    pendingInfoReasonLabel() {
      if (!this.activeIncident) return '';
      const direct = this.activeIncident.pending_info_reason_category_label;
      if (direct && typeof direct === 'string' && direct.trim() !== '') {
        return direct;
      }
      const raw = this.activeIncident.pending_info_reason_category;
      if (raw) {
        const found = PAUSE_REASON_CATEGORIES.find(c => c.value === raw);
        if (found) {
          return found.label;
        }
      }
      return 'Información pendiente de la sede';
    },
    pendingInfoReasonText() {
      return this.activeIncident?.pending_info_reason_text || '';
    },
    typeInfo() {
      const type = this.machine?.machine_type;
      if (MACHINE_TYPE_LABELS[type]) {
        return { label: MACHINE_TYPE_LABELS[type], icon: MACHINE_TYPE_ICONS[type], isPerishable: isPerishableMachineType(type) };
      }
      return { label: type || 'Máquina', icon: '🎰', isPerishable: false };
    },
    cardBorderColor() {
      if (this.hasActiveIncident) {
        if (this.isPendingInfo) {
          return AMBER_TECHNICAL_TOKENS.border; // Amber technical outline for pending info (RF-05.1, RNF-04)
        }
        if (this.isUnderWarranty) {
          return '#86efac'; // Green outline for warranty/resolved
        }
        return '#fca5a5'; // Light red outline for active incident
      }
      return 'var(--color-hairline, #c8cfda)';
    },
    /**
     * Contador de la insignia de conversación. Lee EXCLUSIVAMENTE el recuento
     * segregado de comentarios públicos (`public_comments_count`) que la API
     * entrega al canal de sede (RF-01.1, RNF-01, Art. V.4).
     */
    publicCommentsCount() {
      const raw = this.activeIncident?.public_comments_count;
      const parsed = Number.parseInt(raw, 10);
      return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    },
    /**
     * Etiqueta accesible de la insignia, con el recuento público ya resuelto.
     */
    conversationBadgeLabel() {
      return `Conversación (${this.publicCommentsCount})`;
    },
    amberTokens() {
      return AMBER_TECHNICAL_TOKENS;
    },
    /**
     * Máquina fuera de servicio por falta de acceso previo (RF-04.4, Art. V.1).
     *
     * No es una máquina operativa: figura en el portal precisamente para que la sede
     * vea por qué está parada y pueda confirmar el acceso al pedir un aviso nuevo
     * (RF-04.6), en lugar de desaparecer del parque como si estuviera sana.
     */
    isBlockedNoAccess() {
      return this.machine?.is_blocked_no_access === true;
    }
  },
  methods: {
    handleReportClick() {
      this.$emit('report', this.machine);
    },
    /**
     * Abre el hilo de conversación del expediente activo (RF-01.2). La vista que
     * contiene la tarjeta monta el modal con el canal 'SITE_MANAGER'.
     */
    handleOpenComments() {
      this.$emit('open-comments', this.machine);
    },
    handleReopenClick() {
      this.$emit('reopen', this.machine);
    },
    handleCardClick() {
      this.$emit('select', this.machine);
    }
  },
  template: `
    <div
      class="vg-card vg-machine-card"
      :data-machine-code="machine.code"
      :data-machine-id="machine.id"
      :data-has-incident="hasActiveIncident ? 'true' : 'false'"
      :style="{
        borderRadius: 'var(--radius-card, 8px)',
        border: '1px solid ' + cardBorderColor,
        backgroundColor: '#ffffff',
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'space-between',
        padding: '16px',
        boxShadow: 'var(--shadow-card, 0 1px 3px rgba(0, 0, 0, 0.04))',
        position: 'relative',
        transition: 'box-shadow 0.2s ease, border-color 0.2s ease'
      }"
    >
      <!-- Top: Header & Badges -->
      <div>
        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 8px;">
          <!-- Machine Code & Name -->
          <div>
            <div style="display: flex; align-items: center; gap: 6px;">
              <span style="font-size: 18px;" aria-hidden="true">{{ typeInfo.icon }}</span>
              <h4
                style="font-family: var(--font-display, 'DM Sans', sans-serif); font-size: 16px; font-weight: 700; color: var(--color-ink, #000000); margin: 0;"
              >
                {{ machine.code }}
              </h4>
            </div>
            <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 13px; color: var(--color-ink-muted, #6c7e9d); margin-top: 2px;">
              {{ machine.model || 'Modelo estándar' }}
            </div>
          </div>

          <!-- Machine Type Badge -->
          <span
            style="font-family: var(--font-body, Inter, sans-serif); font-size: 11px; font-weight: 600; padding: 2px 6px; border-radius: var(--radius-interactive, 4px); white-space: nowrap;"
            :style="{
              backgroundColor: typeInfo.isPerishable ? '#fee2e2' : '#f3f4f6',
              color: typeInfo.isPerishable ? '#dc2626' : '#4b5563',
              border: typeInfo.isPerishable ? '1px solid #fca5a5' : '1px solid #e5e7eb'
            }"
          >
            {{ typeInfo.label }}
          </span>
        </div>

        <!-- Location floor/wing -->
        <div
          style="display: flex; align-items: center; gap: 5px; font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-slate, #2c333f); margin-bottom: 12px; background-color: var(--color-surface-1, #efefef); padding: 4px 8px; border-radius: var(--radius-interactive, 4px);"
        >
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
            <circle cx="12" cy="10" r="3"></circle>
          </svg>
          <span style="font-weight: 500;">{{ machine.floor_wing || 'Ubicación no especificada' }}</span>
        </div>

        <!-- Middle: Incident Status Banner -->
        <!-- Case 0: Machine with active incident in PENDING_INFO (RF-05.1, RNF-04) -->
        <div
          v-if="hasActiveIncident && isPendingInfo"
          class="vg-pending-info-banner"
          data-testid="machine-card-pending-info-banner"
          :style="{
            background: amberTokens.bg,
            border: '1px solid ' + amberTokens.border,
            padding: '10px',
            borderRadius: 'var(--radius-interactive, 4px)',
            marginBottom: '14px'
          }"
        >
          <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 6px;">
            <span
              :style="{
                fontFamily: 'var(--font-body, Inter, sans-serif)',
                fontSize: '11px',
                fontWeight: '700',
                color: amberTokens.color
              }"
            >
              Ticket #{{ activeIncident.ticket_code }}
            </span>
            <IncidentBadge
              v-if="activeIncident.status"
              :value="activeIncident.status"
              type="status"
              size="sm"
            />
          </div>
          <div
            :style="{
              fontFamily: 'var(--font-display, \\'DM Sans\\', sans-serif)',
              fontWeight: '700',
              color: amberTokens.color,
              fontSize: '13px',
              lineHeight: '1.3'
            }"
          >
            ⏸️ Intervención en Pausa: El técnico necesita tu ayuda
          </div>
          <div
            :style="{
              fontFamily: 'var(--font-body, Inter, sans-serif)',
              fontSize: '12px',
              color: 'var(--color-warning-text, ' + amberTokens.color + ')',
              margin: '4px 0'
            }"
          >
            <strong>Causa:</strong> {{ pendingInfoReasonLabel }}
          </div>
          <p
            v-if="pendingInfoReasonText"
            :style="{
              fontFamily: 'var(--font-body, Inter, sans-serif)',
              fontSize: '11px',
              color: 'var(--color-warning-text, ' + amberTokens.color + ')',
              margin: '0 0 8px 0',
              fontStyle: 'italic',
              lineHeight: '1.35'
            }"
          >
            "{{ pendingInfoReasonText }}"
          </p>
          <button
            type="button"
            class="vg-btn vg-btn-primary vg-pending-info-action-btn"
            data-testid="machine-card-pending-info-reply-btn"
            style="width: 100%; font-size: 12px; height: 36px; padding: 0 10px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border-radius: var(--radius-interactive, 4px);"
            @click="handleOpenComments"
          >
            💬 Aportar información / Responder al técnico
          </button>
        </div>

        <!-- Case 1: Machine with active ongoing incident -->
        <div
          v-else-if="hasActiveIncident && !isUnderWarranty"
          style="background-color: #fef2f2; border: 1px solid #fee2e2; border-radius: var(--radius-interactive, 4px); padding: 10px; margin-bottom: 14px;"
        >
          <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 6px;">
            <span style="font-family: var(--font-body, Inter, sans-serif); font-size: 11px; font-weight: 700; color: #991b1b;">
              Ticket #{{ activeIncident.ticket_code }}
            </span>
            <div style="display: flex; gap: 4px;">
              <IncidentBadge
                v-if="activeIncident.urgency"
                :value="activeIncident.urgency"
                type="urgency"
                size="sm"
              />
              <IncidentBadge
                v-if="activeIncident.status"
                :value="activeIncident.status"
                type="status"
                size="sm"
              />
            </div>
          </div>
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: #7f1d1d; margin: 0; line-height: 1.3;">
            Avería en curso. No es posible abrir un nuevo ticket para esta máquina.
          </p>
        </div>

        <!-- Case 2: Machine repaired recently within 48h warranty -->
        <div
          v-else-if="hasActiveIncident && isUnderWarranty"
          style="background-color: #f0fdf4; border: 1px solid #dcfce7; border-radius: var(--radius-interactive, 4px); padding: 10px; margin-bottom: 14px;"
        >
          <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 6px;">
            <span style="font-family: var(--font-body, Inter, sans-serif); font-size: 11px; font-weight: 700; color: #166534;">
              Ticket #{{ activeIncident.ticket_code }}
            </span>
            <IncidentBadge
              :value="activeIncident.status"
              type="status"
              size="sm"
            />
          </div>
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: #14532d; margin: 0; line-height: 1.3;">
            Reparada recientemente (garantía de 48h activa). Si el fallo persiste, puedes reabrir el caso.
          </p>
        </div>

        <!-- Case 2.b: Machine out of service — no-access block after 72h cancellation
             (RF-04.4, RF-04.6, Art. V.1 y V.2) -->
        <div
          v-else-if="isBlockedNoAccess"
          style="background-color: var(--color-urgency-critical-bg); border: 1px solid var(--color-urgency-critical); border-radius: var(--radius-interactive, 4px); padding: 10px; margin-bottom: 14px;"
          data-testid="machine-card-blocked-banner"
        >
          <div style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 700; color: var(--color-error-text); margin-bottom: 4px;">
            ⛔ Fuera de servicio · Bloqueada por falta de acceso
          </div>
          <p style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; color: var(--color-slate); margin: 0; line-height: 1.3;">
            El aviso anterior se canceló tras 72 horas hábiles sin poder acceder a la máquina.
            Al pedir asistencia deberá confirmar formalmente que queda accesible (Art. V.2).
          </p>
        </div>

        <!-- Case 3: Fully operational machine -->
        <div
          v-else
          style="display: flex; align-items: center; gap: 6px; margin-bottom: 14px; padding: 6px 8px; background-color: #f0fdf4; border-radius: var(--radius-interactive, 4px);"
        >
          <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #22c55e; display: inline-block;" aria-hidden="true"></span>
          <span style="font-family: var(--font-body, Inter, sans-serif); font-size: 12px; font-weight: 600; color: #15803d;">
            Operativa · Sin averías reportadas
          </span>
        </div>
      </div>

      <!-- Bottom: Action Buttons (4px interactive radius) -->
      <div>
        <!-- If operational -> Report button -->
        <button
          v-if="!hasActiveIncident"
          type="button"
          class="vg-btn vg-btn-primary"
          style="width: 100%; border-radius: var(--radius-interactive, 4px);"
          @click="handleReportClick"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;" aria-hidden="true">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
          </svg>
          Reportar avería
        </button>

        <!-- If ongoing incident -> Conversation badge with public comment count (RF-01.1) -->
        <button
          v-else-if="hasActiveIncident && !isUnderWarranty"
          type="button"
          class="vg-btn vg-btn-secondary vg-conversation-badge"
          data-testid="machine-card-comments-badge"
          :aria-label="conversationBadgeLabel"
          style="width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border-radius: var(--radius-interactive, 4px); font-size: 13px;"
          @click="handleOpenComments"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
          </svg>
          Conversación
          <span
            class="vg-conversation-badge__count"
            data-testid="machine-card-comments-count"
            style="display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 18px; padding: 0 5px; border-radius: var(--radius-interactive, 4px); background-color: var(--color-primary, #2560ff); color: #ffffff; font-family: var(--font-body, Inter, sans-serif); font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums;"
          >
            {{ publicCommentsCount }}
          </span>
        </button>

        <!-- If resolved within warranty -> Reopen button + Conversation badge -->
        <div
          v-else-if="hasActiveIncident && isUnderWarranty"
          style="display: flex; flex-direction: column; gap: 8px;"
        >
          <button
            type="button"
            class="vg-btn vg-btn-secondary"
            style="width: 100%; border-radius: var(--radius-interactive, 4px); font-size: 13px; color: #b91c1c; border-color: #fca5a5;"
            @click="handleReopenClick"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;" aria-hidden="true">
              <polyline points="1 4 1 10 7 10"></polyline>
              <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
            </svg>
            Reabrir incidencia
          </button>
          <button
            type="button"
            class="vg-btn vg-btn-secondary vg-conversation-badge"
            data-testid="machine-card-comments-badge"
            :aria-label="conversationBadgeLabel"
            style="width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 6px; border-radius: var(--radius-interactive, 4px); font-size: 13px;"
            @click="handleOpenComments"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            </svg>
            Conversación
            <span
              class="vg-conversation-badge__count"
              data-testid="machine-card-comments-count"
              style="display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 18px; padding: 0 5px; border-radius: var(--radius-interactive, 4px); background-color: var(--color-primary, #2560ff); color: #ffffff; font-family: var(--font-body, Inter, sans-serif); font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums;"
            >
              {{ publicCommentsCount }}
            </span>
          </button>
        </div>
      </div>
    </div>
  `
};

export default MachineCard;
