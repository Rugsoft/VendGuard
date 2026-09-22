/**
 * VendGuard - MachineCard Component (MachineCard.js)
 * 
 * Renders individual vending machine card for the Location Responsible portal.
 * Adheres to Docker Design Tokens (RNF-06):
 * - 8px card container border radius (--radius-card).
 * - 4px interactive button/badge border radius (--radius-interactive).
 * - Immediate visual distinction between operational machines and machines with active incidents (RF-02).
 */

import { IncidentBadge } from './IncidentBadge.js';

// Machine type human-readable labels and friendly icons/badges
const MACHINE_TYPE_MAP = {
  PERISHABLE_FOOD: { label: 'Comida Perecedera', icon: '🥪', isPerishable: true },
  COLD_DRINKS: { label: 'Bebidas Frías', icon: '🥤', isPerishable: false },
  HOT_DRINKS: { label: 'Café / Calientes', icon: '☕', isPerishable: false },
  SNACKS: { label: 'Snacks y Aperitivos', icon: '🥨', isPerishable: false },
  COMBO: { label: 'Máquina Mixta', icon: '📦', isPerishable: false }
};

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
  emits: ['report', 'comment', 'reopen', 'select'],
  computed: {
    activeIncident() {
      return this.machine?.active_incident || null;
    },
    hasActiveIncident() {
      return this.activeIncident !== null;
    },
    isUnderWarranty() {
      if (!this.activeIncident) return false;
      const status = String(this.activeIncident.status || '').toUpperCase();
      return status === 'RESUELTA' || status === 'RESOLVED';
    },
    typeInfo() {
      const type = this.machine?.machine_type;
      return MACHINE_TYPE_MAP[type] || { label: type || 'Máquina', icon: '🎰', isPerishable: false };
    },
    cardBorderColor() {
      if (this.hasActiveIncident) {
        if (this.isUnderWarranty) {
          return '#86efac'; // Green outline for warranty/resolved
        }
        return '#fca5a5'; // Light red outline for active incident
      }
      return 'var(--color-hairline, #c8cfda)';
    }
  },
  methods: {
    handleReportClick() {
      this.$emit('report', this.machine);
    },
    handleCommentClick() {
      this.$emit('comment', this.machine);
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
        <!-- Case 1: Machine with active ongoing incident -->
        <div
          v-if="hasActiveIncident && !isUnderWarranty"
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

        <!-- If ongoing incident -> Add comment/photos button -->
        <button
          v-else-if="hasActiveIncident && !isUnderWarranty"
          type="button"
          class="vg-btn vg-btn-secondary"
          style="width: 100%; border-radius: var(--radius-interactive, 4px); font-size: 13px;"
          @click="handleCommentClick"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;" aria-hidden="true">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
          </svg>
          Añadir comentarios / fotos
        </button>

        <!-- If resolved within warranty -> Reopen button -->
        <button
          v-else-if="hasActiveIncident && isUnderWarranty"
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
      </div>
    </div>
  `
};

export default MachineCard;
