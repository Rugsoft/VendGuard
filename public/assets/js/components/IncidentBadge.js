/**
 * VendGuard - IncidentBadge Component (IncidentBadge.js)
 * 
 * Renders status and urgency badges using Docker Design System tokens (RNF-06).
 * Follows Dogma Vanilla: pure Vue 3 component with in-browser template.
 * 
 * Features:
 * - Semantic color mapping for all incident urgencies and lifecycle statuses.
 * - Bilingual compatibility: accepts both English and Spanish status/urgency values.
 * - 4px border radius adhering to RNF-06 interactive/label tokens.
 */

// Mapping dictionaries for Semantic Statuses & Urgencies
const URGENCY_CONFIG = {
  CRITICAL: {
    label: 'Crítica',
    cssClass: 'vg-badge-critical',
    bg: '#fee2e2',
    color: '#dc2626',
    border: '#fca5a5'
  },
  CRITICA: {
    label: 'Crítica',
    cssClass: 'vg-badge-critical',
    bg: '#fee2e2',
    color: '#dc2626',
    border: '#fca5a5'
  },
  HIGH: {
    label: 'Alta',
    cssClass: 'vg-badge-high',
    bg: '#ffedd5',
    color: '#c2410c',
    border: '#fdba74'
  },
  ALTA: {
    label: 'Alta',
    cssClass: 'vg-badge-high',
    bg: '#ffedd5',
    color: '#c2410c',
    border: '#fdba74'
  },
  MEDIUM: {
    label: 'Media',
    cssClass: 'vg-badge-medium',
    bg: '#fef9c3',
    color: '#854d0e',
    border: '#fde047'
  },
  MEDIA: {
    label: 'Media',
    cssClass: 'vg-badge-medium',
    bg: '#fef9c3',
    color: '#854d0e',
    border: '#fde047'
  },
  LOW: {
    label: 'Baja',
    cssClass: 'vg-badge-low',
    bg: '#dbeafe',
    color: '#1d4ed8',
    border: '#93c5fd'
  },
  BAJA: {
    label: 'Baja',
    cssClass: 'vg-badge-low',
    bg: '#dbeafe',
    color: '#1d4ed8',
    border: '#93c5fd'
  }
};

const STATUS_CONFIG = {
  REGISTERED: {
    label: 'Registrada',
    bg: '#e5f2fc',
    color: '#003db5',
    border: '#9ec5fe'
  },
  REGISTRADA: {
    label: 'Registrada',
    bg: '#e5f2fc',
    color: '#003db5',
    border: '#9ec5fe'
  },
  ASSIGNED: {
    label: 'Asignada',
    bg: '#ede9fe',
    color: '#6d28d9',
    border: '#c4b5fd'
  },
  ASIGNADA: {
    label: 'Asignada',
    bg: '#ede9fe',
    color: '#6d28d9',
    border: '#c4b5fd'
  },
  IN_PROGRESS: {
    label: 'En curso',
    bg: '#fef3c7',
    color: '#92400e',
    border: '#fcd34d'
  },
  EN_CURSO: {
    label: 'En curso',
    bg: '#fef3c7',
    color: '#92400e',
    border: '#fcd34d'
  },
  PENDING_PARTS: {
    label: 'Pendiente repuesto',
    bg: '#ffedd5',
    color: '#c2410c',
    border: '#fdba74'
  },
  PENDIENTE_REPUESTO: {
    label: 'Pendiente repuesto',
    bg: '#ffedd5',
    color: '#c2410c',
    border: '#fdba74'
  },
  RESOLVED: {
    label: 'Resuelta (Garantía)',
    bg: '#eaf8f1',
    color: '#065f46',
    border: '#86efac'
  },
  RESUELTA: {
    label: 'Resuelta (Garantía)',
    bg: '#eaf8f1',
    color: '#065f46',
    border: '#86efac'
  },
  REOPENED: {
    label: 'Reabierta',
    bg: '#fee2e2',
    color: '#b91c1c',
    border: '#fca5a5'
  },
  REABIERTA: {
    label: 'Reabierta',
    bg: '#fee2e2',
    color: '#b91c1c',
    border: '#fca5a5'
  },
  CLOSED: {
    label: 'Cerrada',
    bg: '#f3f4f6',
    color: '#4b5563',
    border: '#d1d5db'
  },
  CERRADA: {
    label: 'Cerrada',
    bg: '#f3f4f6',
    color: '#4b5563',
    border: '#d1d5db'
  },
  CANCELLED: {
    label: 'Cancelada',
    bg: '#f3f4f6',
    color: '#9ca3af',
    border: '#e5e7eb'
  },
  CANCELADA: {
    label: 'Cancelada',
    bg: '#f3f4f6',
    color: '#9ca3af',
    border: '#e5e7eb'
  }
};

export const IncidentBadge = {
  name: 'IncidentBadge',
  props: {
    /**
     * Value to render (e.g. 'CRITICAL', 'EN_CURSO', 'LOW', 'RESUELTA')
     */
    value: {
      type: [String, Number],
      required: true
    },
    /**
     * Type mode: 'auto' | 'urgency' | 'status'
     */
    type: {
      type: String,
      default: 'auto'
    },
    /**
     * Size: 'sm' | 'md'
     */
    size: {
      type: String,
      default: 'md'
    },
    /**
     * Optional custom label overriding the default
     */
    customLabel: {
      type: String,
      default: null
    }
  },
  computed: {
    normalizedKey() {
      if (!this.value) return '';
      return String(this.value)
        .trim()
        .toUpperCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, '_');
    },
    resolvedConfig() {
      const key = this.normalizedKey;

      if (this.type === 'urgency') {
        return URGENCY_CONFIG[key] || { label: this.value, bg: '#f3f4f6', color: '#374151', border: '#e5e7eb' };
      }

      if (this.type === 'status') {
        return STATUS_CONFIG[key] || { label: this.value, bg: '#f3f4f6', color: '#374151', border: '#e5e7eb' };
      }

      // Auto-detect between urgency and status
      if (URGENCY_CONFIG[key]) {
        return URGENCY_CONFIG[key];
      }
      if (STATUS_CONFIG[key]) {
        return STATUS_CONFIG[key];
      }

      return {
        label: this.value,
        bg: '#f3f4f6',
        color: '#374151',
        border: '#d1d5db'
      };
    },
    displayLabel() {
      if (this.customLabel) return this.customLabel;
      return this.resolvedConfig.label || String(this.value);
    },
    badgeStyle() {
      const cfg = this.resolvedConfig;
      const isSm = this.size === 'sm';

      return {
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: '4px',
        fontFamily: 'var(--font-body, Inter, sans-serif)',
        fontSize: isSm ? '11px' : '12px',
        fontWeight: '600',
        lineHeight: '1.2',
        padding: isSm ? '2px 6px' : '3px 8px',
        borderRadius: 'var(--radius-interactive, 4px)',
        backgroundColor: cfg.bg,
        color: cfg.color,
        border: `1px solid ${cfg.border || 'transparent'}`,
        textDecoration: this.normalizedKey === 'CANCELADA' || this.normalizedKey === 'CANCELLED' ? 'line-through' : 'none',
        whiteSpace: 'nowrap'
      };
    },
    dotColor() {
      return this.resolvedConfig.color;
    }
  },
  template: `
    <span
      class="vg-badge"
      :class="resolvedConfig.cssClass"
      :style="badgeStyle"
      :data-badge-value="normalizedKey"
      role="status"
    >
      <span
        style="width: 6px; height: 6px; border-radius: 50%; display: inline-block;"
        :style="{ backgroundColor: dotColor }"
        aria-hidden="true"
      ></span>
      <span>{{ displayLabel }}</span>
    </span>
  `
};

export default IncidentBadge;
