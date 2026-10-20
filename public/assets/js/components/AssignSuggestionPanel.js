/**
 * VendGuard - AssignSuggestionPanel (AssignSuggestionPanel.js)
 *
 * Bloque de asignación asistida por zona (Módulo 17, RF-04.3) compartido por los
 * tres puntos de entrada del panel de coordinador: el modal de la bandeja de
 * triaje, el panel en línea de la ficha de detalle (RF-MAP-09 y ficha T-IDM) y
 * el modal en lote del mapa territorial. El inspector de sugerencia es uno solo
 * para que el ranking explicado se pinte idéntico en todas partes.
 *
 * El componente es sólo presentación: consume la respuesta del endpoint
 * `GET /api/coordinator/incidents/{id}/suggested-technicians` vía prop y no
 * despacha por sí mismo; comunica la elección al contenedor mediante los
 * eventos `select` (elige puesto del ranking) y `dispatch` (despacho en un
 * toque, RNF-02).
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules (cero dependencias externas).
 */

import {
  suggestionBadgeLabel,
  suggestionBreakdown,
  suggestionDistance
} from '../utils/SuggestionFormat.js';

export const AssignSuggestionPanel = {
  name: 'AssignSuggestionPanel',
  props: {
    /** Respuesta completa del endpoint de sugerencias, o `null` si aún no llegó. */
    suggestions: {
      type: Object,
      default: null
    },
    /** Petición en vuelo: se muestra el estado de cálculo sin bloquear el panel. */
    isLoading: {
      type: Boolean,
      default: false
    },
    /** Error del recomendador: se declara y el despacho manual sigue operativo (RF-06.3). */
    error: {
      type: String,
      default: ''
    },
    /** Técnico actualmente seleccionado en el formulario contenedor. */
    selectedTechnicianId: {
      type: [Number, String],
      default: null
    },
    /** Envío en curso en el contenedor: congela la selección y el despacho. */
    busy: {
      type: Boolean,
      default: false
    },
    /** Nota de ámbito opcional (lote del mapa: sobre qué expediente se calculó). */
    caption: {
      type: String,
      default: ''
    }
  },
  emits: ['select', 'dispatch'],
  computed: {
    /** Ranking explicado devuelto por el endpoint; vacío si aún no llegó o falló. */
    rankedTechnicians() {
      const ranked = this.suggestions?.ranked_technicians;
      return Array.isArray(ranked) ? ranked : [];
    },

    /**
     * Primer puesto del ranking: el técnico destacado con la insignia «Sugerido por Zona».
     * Se toma de la lista ordenada para que la identidad recomendación ↔ primer puesto
     * se cumpla por construcción.
     */
    suggestedTechnician() {
      return this.rankedTechnicians[0] || null;
    },

    /** Escalado sanitario activo: la avería es de frío y la guardia 24/7 va delante (Art. II). */
    isColdChainSuggestion() {
      return Boolean(this.suggestions?.incident?.is_cold_chain_risk)
        && Boolean(this.suggestions?.recommendation?.is_cold_chain_escalation);
    },

    /** Banner de emergencia sanitaria (RF-05.1). */
    hasSanitarySuggestionBanner() {
      return this.isColdChainSuggestion && Boolean(this.suggestions?.recommendation);
    },

    /** La zona de la sede no tiene cuadrante propio: se ordena por proximidad y carga (RF-06.1). */
    isUnattendedZone() {
      return Boolean(this.suggestions?.recommendation?.unattended_zone);
    },

    suggestedBadges() {
      return this.suggestedTechnician?.badges || [];
    }
  },
  methods: {
    suggestionBadgeLabel,

    suggestionBreakdown(suggestion) {
      return suggestionBreakdown(suggestion);
    },

    suggestionDistance(suggestion) {
      return suggestionDistance(suggestion);
    },

    /** Selecciona un puesto del ranking sin confirmar el despacho. */
    chooseCandidate(candidate) {
      if (!candidate) {
        return;
      }
      this.$emit('select', candidate);
    },

    /** Despacho en un toque del técnico recomendado. */
    dispatchSuggested() {
      this.$emit('dispatch');
    }
  },
  template: `
    <div>
      <div v-if="isLoading" class="d-flex align-items-center gap-2 mb-3" style="font-size: 12px; color: var(--color-ink-muted);" data-testid="assign-suggestions-loading">
        <span class="spinner-border spinner-border-sm" role="status"></span>
        <span>Calculando la sugerencia territorial…</span>
      </div>

      <div v-if="error" class="mb-3" role="status" data-testid="assign-suggestions-error" style="background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); padding: 10px; border-radius: var(--radius-interactive); font-size: 13px;">
        ⚠️ {{ error }}
      </div>

      <!-- Banner de emergencia sanitaria (Art. II, RF-05.1) -->
      <div v-if="hasSanitarySuggestionBanner" class="mb-3" role="alert" data-testid="assign-sanitary-banner" style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); border-left: 3px solid var(--color-error); color: var(--color-error-text); padding: 12px 14px; border-radius: var(--radius-interactive); font-size: 13px;">
        <div class="fw-bold">🚨 Prioridad Sanitaria Art. II: Atención requerida en &lt; 4 horas.</div>
        <div>Se sugiere técnico de guardia.</div>
        <div v-if="suggestions?.recommendation?.explanation" class="mt-1" style="font-size: 12px;">
          {{ suggestions.recommendation.explanation }}
        </div>
      </div>

      <div v-if="isUnattendedZone" class="mb-3" data-testid="assign-unattended-warning" style="background-color: var(--color-warning-bg); border: 1px solid var(--color-warning-border); color: var(--color-warning-text); padding: 10px; border-radius: var(--radius-interactive); font-size: 13px;">
        ⚠️ Sin técnicos disponibles en esta zona. Se ordena por proximidad y menor carga.
      </div>

      <div v-if="caption" class="mb-2" style="font-size: 12px; color: var(--color-ink-muted);">
        {{ caption }}
      </div>

      <!-- Técnico recomendado: un toque para despachar (RNF-02) -->
      <div v-if="suggestedTechnician" class="mb-3" data-testid="assign-suggested-card" style="background-color: var(--color-primary-subtle); border: 1px solid var(--color-primary); border-radius: var(--radius-card); padding: 12px 14px;">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div>
            <div class="fw-bold" style="font-size: 12px; letter-spacing: 0.03em; color: var(--color-primary-dark);">
              ⭐ Sugerido por Zona · {{ suggestionDistance(suggestedTechnician) }}
            </div>
            <div style="font-size: 15px; font-weight: 600; color: var(--color-ink-slate);">
              {{ suggestedTechnician.name }}
              <span class="font-monospace" style="font-size: 12px; color: var(--color-ink-muted);">{{ suggestedTechnician.operator_code }}</span>
            </div>
            <div style="font-size: 12px; color: var(--color-ink-muted);">
              {{ suggestedTechnician.zone_affinity_label }} · Puntuación {{ suggestedTechnician.total_score }}
            </div>
            <div v-if="suggestionBreakdown(suggestedTechnician)" class="font-monospace mt-1" style="font-size: 11px; color: var(--color-ink-secondary);" data-testid="assign-suggested-breakdown">
              {{ suggestionBreakdown(suggestedTechnician) }}
            </div>
            <div class="d-flex flex-wrap gap-1 mt-2" data-testid="assign-suggested-badges">
              <span v-for="badge in suggestedBadges" :key="badge" class="vg-chip" style="font-size: 11px;">
                {{ suggestionBadgeLabel(badge) }}
              </span>
            </div>
          </div>
        </div>
        <div v-if="suggestedTechnician.current_status?.has_in_progress_stop" class="mt-2" style="font-size: 12px; color: var(--color-warning-text);">
          ⚠️ En curso en {{ suggestedTechnician.current_status.in_progress_location_name }}: requerirá pausar o finalizar antes de iniciar.
        </div>
        <button
          type="button"
          class="vg-btn vg-btn-primary w-100 mt-2"
          :disabled="busy"
          @click="dispatchSuggested"
          data-testid="assign-suggested"
        >
          <span v-if="!busy">⚡ Asignar Sugerido</span>
          <span v-else>Asignando…</span>
        </button>
      </div>

      <!-- Ranking explicado: el coordinador puede elegir a otro sin bloqueo (RF-04.3).
           Las filas llevan altura automática: el contenido de dos líneas (nombre +
           insignias) no cabe en la altura fija del vg-btn y desbordaría sobre la
           fila siguiente (regresión visual reportada en triaje, 2026-10-20). -->
      <div v-if="rankedTechnicians.length > 1" class="mb-3" data-testid="assign-ranking">
        <div class="text-uppercase fw-bold mb-1" style="font-size: 11px; letter-spacing: 0.05em; color: var(--color-ink-muted);">Ranking territorial</div>
        <button
          v-for="candidate in rankedTechnicians"
          :key="candidate.technician_id"
          type="button"
          class="vg-btn vg-btn-sm w-100 d-flex justify-content-between align-items-center gap-2 mb-1 text-start"
          :class="Number(selectedTechnicianId) === Number(candidate.technician_id) ? 'vg-btn-primary' : 'vg-btn-secondary'"
          :disabled="busy"
          :data-testid="'assign-candidate-' + candidate.technician_id"
          style="height: auto; min-height: var(--control-height-sm);"
          @click="chooseCandidate(candidate)"
        >
          <span>
            <span class="fw-semibold" style="font-size: 13px; color: var(--color-ink-slate);">{{ candidate.name }}</span>
            <span class="small" style="color: var(--color-ink-muted);"> · {{ candidate.zone_affinity_label }}</span>
            <span class="d-flex flex-wrap gap-1 mt-1">
              <span v-for="badge in candidate.badges" :key="badge" class="vg-chip" style="font-size: 10px;">{{ suggestionBadgeLabel(badge) }}</span>
            </span>
          </span>
          <span class="font-monospace" style="font-size: 12px; color: var(--color-ink-secondary);">{{ candidate.total_score }} pts</span>
        </button>
      </div>
    </div>
  `
};

export default AssignSuggestionPanel;
