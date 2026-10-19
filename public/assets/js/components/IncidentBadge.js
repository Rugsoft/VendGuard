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
 * 
 * Single source note: the localized labels, the semantic palettes and the tolerant
 * key normalization live in utils/IncidentStatusPermissions.js (shared with the
 * tray, the SLA classifiers and the machine cards). This component only maps a
 * resolved config to badge markup; unknown values keep their local fallback.
 */

import {
  INCIDENT_STATUSES,
  normalizeBadgeKey,
  normalizeIncidentStatus,
  resolveBadgeConfig
} from '../utils/IncidentStatusPermissions.js';

/**
 * Insignias de trazabilidad del Módulo 16 (RF-06.4).
 *
 * La etiqueta canónica del expediente viaja ya resuelta desde el backend
 * (`opportunistic_mode_label`), pero la interfaz también necesita pintar el chip a
 * partir del valor crudo (`NEW` / `ASSUMED`) cuando la fila solo trae el enum. Los
 * colores entran por token —nunca por literal hexadecimal— para no engordar el
 * trinquete de deuda de diseño (`DesignTokenDebtRatchetTest`).
 */
const OPPORTUNISTIC_BADGES = Object.freeze({
  NEW: Object.freeze({
    label: 'Oportunista In Situ · Nueva',
    bg: 'var(--color-info-bg)',
    color: 'var(--color-info-text)',
    border: 'var(--color-info-border)',
    cssClass: 'vg-badge-opportunistic-new'
  }),
  ASSUMED: Object.freeze({
    label: 'Oportunista In Situ · Asumida',
    bg: 'var(--color-warning-bg)',
    color: 'var(--color-warning-text)',
    border: 'var(--color-warning-border)',
    cssClass: 'vg-badge-opportunistic-assumed'
  })
});

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
     * Type mode: 'auto' | 'urgency' | 'status' | 'opportunistic'
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
      return normalizeBadgeKey(this.value);
    },
    resolvedConfig() {
      // Módulo 16 (RF-06.4): el chip de modalidad oportunista se resuelve antes que el
      // contrato compartido de estados/urgencias, que no conoce estos dos valores.
      if (this.type === 'opportunistic') {
        const opportunisticKey = String(this.value ?? '').trim().toUpperCase();
        if (OPPORTUNISTIC_BADGES[opportunisticKey]) {
          return OPPORTUNISTIC_BADGES[opportunisticKey];
        }
      }

      const shared = resolveBadgeConfig(this.value, this.type);
      if (shared) {
        return shared;
      }

      // Typed fallbacks preserve the exact pre-extraction rendering of unknown values.
      if (this.type === 'urgency' || this.type === 'status') {
        return { label: this.value, bg: '#f3f4f6', color: '#374151', border: '#e5e7eb' };
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
        textDecoration: normalizeIncidentStatus(this.normalizedKey) === INCIDENT_STATUSES.CANCELLED ? 'line-through' : 'none',
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
