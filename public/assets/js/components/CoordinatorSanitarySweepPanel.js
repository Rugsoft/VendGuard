/**
 * VendGuard - CoordinatorSanitarySweepPanel (CoordinatorSanitarySweepPanel.js)
 *
 * Superficie operativa de la contención del barrido sanitario para la Coordinación
 * (Módulo 11: T-PAUSE-41, RF-07.1 a RF-07.5, Constitución Art. II y Art. III).
 *
 * Por qué existe: el barrido de fondo del Reloj Sanitario Biológico publica sus aplazamientos
 * por contención de fila en la respuesta del planificador, que nadie con autoridad para actuar
 * sobre el escritor que retiene la fila llega a leer. Esta pestaña convierte ese dato en tres
 * lecturas que la operación sí puede usar:
 *
 * 1. **Latido del barrido.** Última pasada, minutos transcurridos y aviso explícito cuando el
 *    barrido lleva más de dos cadencias sin latir: «no está corriendo» es un incidente distinto
 *    de «no encuentra nada», y hasta ahora se veían igual desde el panel.
 * 2. **Tendencia de 24 h.** Pasadas, pasadas con aplazamiento, aplazamientos, cuarentenas y
 *    fallos reales de la ventana, con la tasa de contención: permite ver una contención que
 *    crece antes de que llegue a escalar.
 * 3. **Seguimientos abiertos.** Un expediente por fila, con sus pasadas consecutivas, su
 *    antigüedad y su estado de escalado, y un botón que abre la ficha integral del ticket
 *    reutilizando el modal de detalle que ya monta la vista del coordinador.
 *
 * Contrato de responsabilidades: este componente es de presentación pura. La vista
 * (`CoordinatorDashboardView`) es la única que pide el payload y lo pasa como propiedad, de modo
 * que no hay dos verdades ni dos consultas por pantalla; la recarga se pide con `refresh`.
 *
 * Lo que NO hace, a propósito: no escribe nada (el seguimiento lo cierra el propio barrido en la
 * primera pasada que ya no aplaza al expediente) y no deriva la regla de escalado (viaja
 * resuelta desde la política de dominio, junto con sus umbrales).
 *
 * Dogma Vanilla: Vue 3 Options API vía ES Modules, cero dependencias de npm, y ningún literal
 * hexadecimal: el color entra siempre por token (`var(--color-...)`) o por variante de botón.
 */

export const CoordinatorSanitarySweepPanel = {
  name: 'CoordinatorSanitarySweepPanel',
  props: {
    /**
     * Payload de `GET /api/coordinator/sanitary-sweep/contention` (`data` del sobre), o `null`
     * mientras no se haya cargado nada todavía.
     */
    visibility: {
      type: Object,
      default: null
    },
    isLoading: {
      type: Boolean,
      default: false
    },
    errorMessage: {
      type: String,
      default: ''
    }
  },
  emits: ['refresh', 'open-incident-detail'],
  computed: {
    escalation() {
      return this.visibility?.escalation || null;
    },
    level() {
      return this.escalation?.level || 'CLEAR';
    },
    /**
     * La alerta la manda la autoridad única del backend (`requires_attention`): la interfaz no
     * vuelve a decidir con qué umbral se escala, sólo pinta lo que el dominio ya resolvió.
     */
    requiresAttention() {
      return this.escalation?.requires_attention === true;
    },
    hasContention() {
      return this.openContentions.length > 0;
    },
    escalatedCount() {
      return Number(this.escalation?.escalated_contentions || 0);
    },
    openContentions() {
      return Array.isArray(this.visibility?.contentions) ? this.visibility.contentions : [];
    },
    lastPass() {
      return this.visibility?.sweep?.last_pass || null;
    },
    neverRan() {
      return this.visibility !== null && this.lastPass === null;
    },
    isStale() {
      return this.visibility?.sweep?.is_stale === true;
    },
    minutesSinceLastPass() {
      return this.visibility?.sweep?.minutes_since_last_pass ?? null;
    },
    window() {
      return {
        hours: this.visibility?.sweep?.window_hours ?? 24,
        passes: this.visibility?.sweep?.passes_in_window ?? 0,
        passesWithContention: this.visibility?.sweep?.passes_with_contention ?? 0,
        deferrals: this.visibility?.sweep?.deferrals_in_window ?? 0,
        failures: this.visibility?.sweep?.failures_in_window ?? 0,
        quarantines: this.visibility?.sweep?.quarantines_in_window ?? 0,
        ratePercent: this.visibility?.sweep?.contention_rate_percent ?? 0
      };
    },
    cadenceMinutes() {
      return Number(this.visibility?.cadence_minutes || 15);
    },
    thresholds() {
      return {
        consecutive: this.escalation?.consecutive_passes_threshold ?? 3,
        perPass: this.escalation?.per_pass_threshold ?? 5
      };
    },
    /** Copy del aviso: sólo el nivel ESCALATED pinta en tono de alarma. */
    bannerLabel() {
      if (this.requiresAttention) {
        const fleet = this.escalation?.fleet_escalation
          ? ' La última pasada superó el umbral de parque, así que hay más de una máquina retenida a la vez.'
          : '';
        return `${this.escalation?.level_label || 'Contención persistente'}: ${this.escalatedCount} expediente(s) llevan ${this.thresholds.consecutive} o más pasadas seguidas sin poder evaluarse.${fleet}`;
      }

      if (this.hasContention) {
        return `${this.escalation?.level_label || 'Aplazamientos vivos'}: ${this.openContentions.length} expediente(s) aplazados ahora mismo. Ninguno ha alcanzado todavía el umbral de escalado (${this.thresholds.consecutive} pasadas consecutivas).`;
      }

      return 'Sin aplazamientos por contención de fila: el barrido está evaluando a todos sus candidatos.';
    },
    /** Fila de estado del barrido: latido, con la lectura honesta de cada situación. */
    heartbeatLabel() {
      if (this.visibility === null) {
        return 'Cargando el estado del barrido sanitario…';
      }
      if (this.neverRan) {
        return 'Sin pasadas registradas: el barrido todavía no ha corrido en este entorno.';
      }
      if (this.isStale) {
        return `El barrido no está corriendo: la última pasada fue hace ${this.minutesSinceLastPass} min y la cadencia ratificada es de ${this.cadenceMinutes} min.`;
      }

      return `Última pasada hace ${this.minutesSinceLastPass} min (cadencia ${this.cadenceMinutes} min).`;
    },
    heartbeatTone() {
      if (this.visibility === null) {
        return 'neutral';
      }
      if (this.neverRan) {
        return 'warning';
      }

      return this.isStale ? 'danger' : 'success';
    }
  },
  methods: {
    /** Pide a la vista que vuelva a leer la superficie (una sola fuente de verdad). */
    requestRefresh() {
      this.$emit('refresh');
    },
    /** Abre la ficha integral del expediente reutilizando el modal de detalle de la vista. */
    openIncident(incidentId) {
      const id = Number(incidentId);
      if (!Number.isFinite(id) || id <= 0) {
        return;
      }

      this.$emit('open-incident-detail', id);
    },
    /** Antigüedad legible: minutos hasta la hora, horas y minutos a partir de ahí. */
    formatAge(minutes) {
      const value = Number(minutes || 0);
      if (value < 60) {
        return `${value} min`;
      }

      const hours = Math.floor(value / 60);
      const rest = value % 60;

      return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
    },
    /** Contador de pasadas consecutivas con su etiqueta, para no repetir el texto en la plantilla. */
    passesLabel(contention) {
      const passes = Number(contention?.consecutive_passes || 0);

      return passes === 1 ? '1 pasada aplazada' : `${passes} pasadas aplazadas`;
    },
    /** Estado de la fila: escalado, aplazado o cerrado (esta última no debería llegar). */
    rowTone(contention) {
      return contention?.escalated === true ? 'danger' : 'warning';
    }
  },
  template: `
    <div class="vg-sanitary-sweep-panel" data-testid="sanitary-sweep-panel">
      <div class="card shadow-sm" style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card, 8px); background-color: var(--color-surface-card); padding: 16px; margin-bottom: 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
          <div>
            <strong style="font-family: var(--font-display); font-size: 18px; color: var(--color-ink); display: block;">
              🩺 Barrido Sanitario · Contención de fila
            </strong>
            <span style="font-size: 13px; color: var(--color-ink-muted);">
              Visibilidad operativa del proceso de fondo que activa la cuarentena sin intento de cierre (RF-03.5.1 / RF-07).
            </span>
          </div>
          <div class="vg-action-group" style="gap: 8px;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              :disabled="isLoading"
              @click="requestRefresh"
              data-testid="sweep-visibility-refresh"
            >
              🔄 Actualizar
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="errorMessage"
        data-testid="sweep-visibility-error"
        style="background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); border-radius: var(--radius-card, 8px); padding: 12px 16px; margin-bottom: 20px; color: var(--color-error-text);"
      >
        <strong>No se pudo leer la superficie del barrido.</strong>
        <span style="display: block; font-size: 13px; margin-top: 4px;">{{ errorMessage }}</span>
      </div>

      <div
        v-if="visibility"
        data-testid="sweep-escalation-banner"
        :style="requiresAttention
          ? 'background-color: var(--color-error-bg); border: 2px solid var(--color-error-border);'
          : 'background-color: var(--color-info-bg); border: 1px solid var(--color-info-border);'"
        style="border-radius: var(--radius-card, 8px); padding: 14px 18px; margin-bottom: 20px;"
      >
        <strong style="display: block; margin-bottom: 4px;">
          {{ requiresAttention ? '🚨 Alerta de escalado' : 'ℹ️ Estado de la contención' }}
        </strong>
        <span data-testid="sweep-escalation-label" style="font-size: 13px;">{{ bannerLabel }}</span>
      </div>

      <div class="card shadow-sm" style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card, 8px); background-color: var(--color-surface-card); padding: 16px; margin-bottom: 20px;">
        <strong style="font-family: var(--font-display); font-size: 15px; color: var(--color-ink); display: block; margin-bottom: 8px;">
          ⏱️ Latido del barrido
        </strong>
        <span
          data-testid="sweep-heartbeat"
          :data-tone="heartbeatTone"
          :style="heartbeatTone === 'danger'
            ? 'color: var(--color-error-text);'
            : (heartbeatTone === 'warning' ? 'color: var(--color-warning-text);' : 'color: var(--color-ink-secondary);')"
        >
          {{ heartbeatLabel }}
        </span>

        <div v-if="lastPass" data-testid="sweep-last-pass" style="margin-top: 12px; font-size: 13px; color: var(--color-ink-secondary);">
          Pasada #{{ lastPass.pass_id }} · {{ lastPass.started_at }} ·
          {{ lastPass.candidates }} candidato(s) · {{ lastPass.evaluated }} evaluado(s) ·
          {{ lastPass.quarantined_count }} cuarentena(s) · {{ lastPass.contended_count }} aplazado(s) ·
          {{ lastPass.failures_count }} fallo(s) reales · {{ lastPass.duration_ms }} ms
        </div>

        <div class="vg-action-group" style="gap: 24px; margin-top: 16px;" data-testid="sweep-window">
          <div>
            <span style="display: block; font-size: 12px; text-transform: uppercase; color: var(--color-ink-muted);">Pasadas ({{ window.hours }} h)</span>
            <strong>{{ window.passes }}</strong>
          </div>
          <div>
            <span style="display: block; font-size: 12px; text-transform: uppercase; color: var(--color-ink-muted);">Con aplazamiento</span>
            <strong>{{ window.passesWithContention }}</strong>
          </div>
          <div>
            <span style="display: block; font-size: 12px; text-transform: uppercase; color: var(--color-ink-muted);">Aplazamientos</span>
            <strong>{{ window.deferrals }}</strong>
          </div>
          <div>
            <span style="display: block; font-size: 12px; text-transform: uppercase; color: var(--color-ink-muted);">Cuarentenas</span>
            <strong>{{ window.quarantines }}</strong>
          </div>
          <div>
            <span style="display: block; font-size: 12px; text-transform: uppercase; color: var(--color-ink-muted);">Fallos reales</span>
            <strong>{{ window.failures }}</strong>
          </div>
          <div>
            <span style="display: block; font-size: 12px; text-transform: uppercase; color: var(--color-ink-muted);">Tasa de contención</span>
            <strong>{{ window.ratePercent }} %</strong>
          </div>
        </div>
      </div>

      <div class="card shadow-sm" style="border: 1px solid var(--color-hairline); border-radius: var(--radius-card, 8px); background-color: var(--color-surface-card); padding: 16px;">
        <strong style="font-family: var(--font-display); font-size: 15px; color: var(--color-ink); display: block; margin-bottom: 4px;">
          📌 Expedientes aplazados ahora mismo
        </strong>
        <span style="font-size: 13px; color: var(--color-ink-muted);">
          El aplazamiento no es un fallo del barrido: otro escritor del parque sostiene la fila de esa máquina. El barrido lo reintenta en la pasada siguiente y el rastro de cuarentena sigue siendo único.
        </span>

        <div v-if="!hasContention" data-testid="sweep-contentions-empty" style="margin-top: 12px; font-size: 13px; color: var(--color-ink-secondary);">
          Sin seguimientos abiertos: ninguna máquina quedó pendiente de evaluación en la última pasada.
        </div>

        <div v-else style="overflow-x: auto; margin-top: 12px;">
          <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
              <tr style="text-align: left; color: var(--color-ink-muted); text-transform: uppercase; font-size: 11px;">
                <th style="padding: 8px 6px;">Expediente</th>
                <th style="padding: 8px 6px;">Máquina / Sede</th>
                <th style="padding: 8px 6px;">Aplazamiento</th>
                <th style="padding: 8px 6px;">Estado</th>
                <th style="padding: 8px 6px;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="contention in openContentions" :key="contention.incident_id" data-testid="sweep-contention-row">
                <td style="padding: 8px 6px;">
                  <strong>{{ contention.ticket_code }}</strong>
                  <span style="display: block; color: var(--color-ink-muted);">{{ contention.incident_status }}</span>
                </td>
                <td style="padding: 8px 6px;">
                  <strong>{{ contention.machine_code }}</strong>
                  <span style="display: block; color: var(--color-ink-muted);">
                    {{ contention.location_name }} · {{ contention.floor_wing }}
                  </span>
                </td>
                <td style="padding: 8px 6px;">
                  {{ passesLabel(contention) }}
                  <span style="display: block; color: var(--color-ink-muted);">
                    Desde {{ contention.first_detected_at }} · hace {{ formatAge(contention.age_minutes) }}
                  </span>
                </td>
                <td style="padding: 8px 6px;">
                  <span
                    data-testid="sweep-contention-state"
                    :data-escalated="contention.escalated ? 'true' : 'false'"
                    :style="rowTone(contention) === 'danger'
                      ? 'background-color: var(--color-error-bg); color: var(--color-error-text);'
                      : 'background-color: var(--color-warning-bg); color: var(--color-warning-text);'"
                  >
                    {{ contention.escalated ? 'Escalado' : 'Aplazado' }}
                  </span>
                  <span v-if="contention.escalated_at" style="display: block; color: var(--color-ink-muted); margin-top: 4px;">
                    Escaló {{ contention.escalated_at }}
                  </span>
                </td>
                <td style="padding: 8px 6px;">
                  <button
                    type="button"
                    class="vg-btn vg-btn-secondary"
                    @click="openIncident(contention.incident_id)"
                    data-testid="sweep-open-incident"
                  >
                    Abrir ficha
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `
};

export default CoordinatorSanitarySweepPanel;
