/**
 * VendGuard - CoordinatorAbsencesTab Component (CoordinatorAbsencesTab.js)
 *
 * Gestión de ausencias de técnicos desde el panel de Coordinación (Módulo 21, RF-01, RF-02).
 *
 * Características:
 *  - Listado de ausencias con filtros por técnico y estado (activas, futuras, canceladas, todas).
 *  - Alta de ausencia con comprobación de solapamiento y detección de tickets afectados (RF-02).
 *  - Asistente de reasignación asistida ante averías pendientes del técnico ausente.
 *  - Cancelación lógica auditada con motivo obligatorio (Art. III.1).
 *
 * Dogma Vanilla: Vue 3 Options API sin bundler, diseño Docker con tokens CSS (cero hex hardcodeados).
 * Dualismo Lingüístico: código en inglés, interfaz y mensajes en castellano.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

const EMPTY_CREATE_FORM = Object.freeze({
  technician_id: '',
  reason: 'VACATION',
  starts_at: '',
  ends_at: '',
  notes: ''
});

const EMPTY_CANCEL_FORM = Object.freeze({
  absence_id: null,
  cancellation_reason: ''
});

export const CoordinatorAbsencesTab = {
  name: 'CoordinatorAbsencesTab',

  components: {
    ModalDialog
  },

  data() {
    return {
      absences: [],
      technicians: [],
      filterTechnicianId: '',
      filterStatus: 'all',
      isLoading: false,
      errorMessage: '',
      successMessage: '',

      // Modal de alta
      showCreateModal: false,
      isCreating: false,
      createError: '',
      createForm: { ...EMPTY_CREATE_FORM },

      // Modal de cancelación lógica
      showCancelModal: false,
      isCancelling: false,
      cancelError: '',
      cancelForm: { ...EMPTY_CANCEL_FORM },

      // Asistente modal de reasignación (RF-02)
      showAssistedModal: false,
      affectedIncidents: [],
      createdAbsenceInfo: null
    };
  },

  computed: {
    filteredAbsences() {
      return this.absences;
    },
    hasAffectedIncidents() {
      return this.affectedIncidents.length > 0;
    }
  },

  mounted() {
    this.loadAbsences();
    this.loadTechnicians();
  },

  methods: {
    async loadAbsences() {
      this.isLoading = true;
      this.errorMessage = '';
      try {
        const params = {};
        if (this.filterTechnicianId) {
          params.technician_id = this.filterTechnicianId;
        }
        if (this.filterStatus && this.filterStatus !== 'all') {
          params.status = this.filterStatus;
        }

        const res = await api.get('/api/coordinator/technicians/absences', { params });
        this.absences = Array.isArray(res?.data) ? res.data : [];
      } catch (err) {
        this.errorMessage = err?.message || 'Error al cargar el listado de ausencias.';
      } finally {
        this.isLoading = false;
      }
    },

    async loadTechnicians() {
      try {
        const res = await api.get('/api/coordinator/users', { params: { role: 'TECHNICIAN' } });
        this.technicians = Array.isArray(res?.data) ? res.data.filter(u => u.is_active !== false) : [];
      } catch (err) {
        console.error('Error al cargar técnicos para ausencias:', err);
      }
    },

    openCreateModal() {
      this.createForm = { ...EMPTY_CREATE_FORM };
      this.createError = '';
      this.showCreateModal = true;
    },

    closeCreateModal() {
      this.showCreateModal = false;
      this.createError = '';
    },

    async submitCreateAbsence() {
      if (!this.createForm.technician_id) {
        this.createError = 'Debe seleccionar un técnico.';
        return;
      }
      if (!this.createForm.starts_at || !this.createForm.ends_at) {
        this.createError = 'Debe indicar fecha de inicio y fin.';
        return;
      }

      this.isCreating = true;
      this.createError = '';
      try {
        const res = await api.post(
          `/api/coordinator/technicians/${this.createForm.technician_id}/absences`,
          this.createForm
        );

        this.showCreateModal = false;
        await this.loadAbsences();

        const affected = res?.data?.affected_incidents || [];
        if (affected.length > 0) {
          this.affectedIncidents = affected;
          this.createdAbsenceInfo = res?.data?.absence || null;
          this.showAssistedModal = true;
        } else {
          this.successMessage = 'Ausencia registrada correctamente sin averías afectadas.';
        }
      } catch (err) {
        this.createError = err?.message || 'Error al registrar la ausencia.';
      } finally {
        this.isCreating = false;
      }
    },

    openCancelModal(absence) {
      this.cancelForm = {
        absence_id: absence.id,
        cancellation_reason: ''
      };
      this.cancelError = '';
      this.showCancelModal = true;
    },

    closeCancelModal() {
      this.showCancelModal = false;
      this.cancelError = '';
    },

    async submitCancelAbsence() {
      if ((this.cancelForm.cancellation_reason || '').trim().length < 5) {
        this.cancelError = 'El motivo de cancelación debe tener al menos 5 caracteres.';
        return;
      }

      this.isCancelling = true;
      this.cancelError = '';
      try {
        await api.delete(`/api/coordinator/absences/${this.cancelForm.absence_id}`, {
          cancellation_reason: this.cancelForm.cancellation_reason
        });

        this.showCancelModal = false;
        this.successMessage = 'Ausencia cancelada correctamente.';
        await this.loadAbsences();
      } catch (err) {
        this.cancelError = err?.message || 'Error al cancelar la ausencia.';
      } finally {
        this.isCancelling = false;
      }
    },

    closeAssistedModal() {
      this.showAssistedModal = false;
      this.affectedIncidents = [];
      this.createdAbsenceInfo = null;
    },

    formatDate(isoString) {
      if (!isoString) return '-';
      try {
        const d = new Date(isoString);
        return d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
      } catch (e) {
        return isoString;
      }
    }
  },

  template: `
    <div class="vg-coordinator-absences-tab" style="padding: 16px 0;">
      <!-- Encabezado y acciones -->
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
        <div>
          <h3 style="font-family: var(--font-display); font-size: var(--font-size-title-md); font-weight: 700; color: var(--color-ink); margin: 0;">
            Gestión de Ausencias y Turnos
          </h3>
          <p style="font-size: var(--font-size-body-sm); color: var(--color-ink-muted); margin: 4px 0 0 0;">
            Planificación de bajas médicas, vacaciones y permisos con promoción automática del suplente en el recomendador.
          </p>
        </div>

        <button
          type="button"
          class="vg-btn vg-btn-primary"
          style="display: inline-flex; align-items: center; gap: 6px;"
          data-testid="btn-create-absence"
          @click="openCreateModal"
        >
          <span>➕</span>
          <span>Nueva Ausencia</span>
        </button>
      </div>

      <!-- Filtros -->
      <div style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); padding: 12px 16px; margin-bottom: 16px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <div style="display: flex; align-items: center; gap: 6px;">
          <label for="filter-absence-tech" style="font-size: var(--font-size-body-sm); font-weight: 600; color: var(--color-slate);">
            Técnico:
          </label>
          <select
            id="filter-absence-tech"
            v-model="filterTechnicianId"
            class="vg-input"
            style="height: 34px; font-size: var(--font-size-body-sm);"
            @change="loadAbsences"
          >
            <option value="">Todos los técnicos</option>
            <option v-for="t in technicians" :key="t.id" :value="t.id">
              {{ t.name }}
            </option>
          </select>
        </div>

        <div style="display: flex; align-items: center; gap: 6px;">
          <label for="filter-absence-status" style="font-size: var(--font-size-body-sm); font-weight: 600; color: var(--color-slate);">
            Estado:
          </label>
          <select
            id="filter-absence-status"
            v-model="filterStatus"
            class="vg-input"
            style="height: 34px; font-size: var(--font-size-body-sm);"
            @change="loadAbsences"
          >
            <option value="all">Todas</option>
            <option value="active">Activas hoy</option>
            <option value="future">Futuras</option>
            <option value="cancelled">Canceladas</option>
          </select>
        </div>

        <button
          type="button"
          class="vg-btn vg-btn-secondary"
          style="height: 34px; font-size: var(--font-size-body-sm); padding: 0 10px;"
          @click="loadAbsences"
        >
          🔄 Refrescar
        </button>
      </div>

      <!-- Mensajes -->
      <div v-if="successMessage" style="background-color: var(--color-success-bg); border: 1px solid var(--color-hairline); color: var(--color-success-text); padding: 10px 14px; border-radius: var(--radius-interactive); margin-bottom: 14px; font-size: var(--font-size-body-sm);">
        {{ successMessage }}
      </div>
      <div v-if="errorMessage" style="background-color: var(--color-error-bg); border: 1px solid var(--color-hairline); color: var(--color-error-text); padding: 10px 14px; border-radius: var(--radius-interactive); margin-bottom: 14px; font-size: var(--font-size-body-sm);">
        {{ errorMessage }}
      </div>

      <!-- Tabla de ausencias -->
      <div style="background-color: var(--color-surface-card); border: 1px solid var(--color-hairline); border-radius: var(--radius-card); overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: var(--font-size-body-sm); text-align: left;">
          <thead>
            <tr style="border-bottom: 1px solid var(--color-hairline); background-color: var(--color-surface-1);">
              <th style="padding: 10px 14px; font-weight: 700; color: var(--color-slate);">Técnico</th>
              <th style="padding: 10px 14px; font-weight: 700; color: var(--color-slate);">Motivo</th>
              <th style="padding: 10px 14px; font-weight: 700; color: var(--color-slate);">Desde</th>
              <th style="padding: 10px 14px; font-weight: 700; color: var(--color-slate);">Hasta</th>
              <th style="padding: 10px 14px; font-weight: 700; color: var(--color-slate);">Notas</th>
              <th style="padding: 10px 14px; font-weight: 700; color: var(--color-slate);">Estado</th>
              <th style="padding: 10px 14px; font-weight: 700; color: var(--color-slate); text-align: right;">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="isLoading">
              <td colspan="7" style="padding: 24px; text-align: center; color: var(--color-ink-muted);">
                ⏳ Cargando ausencias...
              </td>
            </tr>
            <tr v-else-if="absences.length === 0">
              <td colspan="7" style="padding: 24px; text-align: center; color: var(--color-ink-muted);">
                No hay ausencias registradas con los filtros seleccionados.
              </td>
            </tr>
            <tr
              v-else
              v-for="a in absences"
              :key="a.id"
              style="border-bottom: 1px solid var(--color-hairline);"
            >
              <td style="padding: 10px 14px; font-weight: 600; color: var(--color-ink);">
                {{ a.technician_name }}
              </td>
              <td style="padding: 10px 14px;">
                <span class="vg-badge" style="background-color: var(--color-surface-2); color: var(--color-primary-dark); padding: 2px 6px; border-radius: var(--radius-interactive); font-weight: 600; font-size: 11px;">
                  {{ a.reason_label || a.reason }}
                </span>
              </td>
              <td style="padding: 10px 14px; color: var(--color-slate);">
                {{ formatDate(a.starts_at) }}
              </td>
              <td style="padding: 10px 14px; color: var(--color-slate);">
                {{ formatDate(a.ends_at) }}
              </td>
              <td style="padding: 10px 14px; color: var(--color-ink-muted); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                {{ a.notes || '-' }}
              </td>
              <td style="padding: 10px 14px;">
                <span
                  v-if="a.is_cancelled"
                  style="color: var(--color-error-text); font-weight: 600; font-size: 11px;"
                >
                  Cancelada ({{ a.cancellation_reason }})
                </span>
                <span
                  v-else
                  style="color: var(--color-success-text); font-weight: 600; font-size: 11px;"
                >
                  Vigente
                </span>
              </td>
              <td style="padding: 10px 14px; text-align: right;">
                <button
                  v-if="!a.is_cancelled"
                  type="button"
                  class="vg-btn vg-btn-secondary"
                  style="height: 28px; font-size: 11px; padding: 0 8px; color: var(--color-urgency-critical);"
                  @click="openCancelModal(a)"
                >
                  Anular
                </button>
                <span v-else style="color: var(--color-ink-muted); font-size: 11px;">-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- MODAL: NUEVA AUSENCIA -->
      <ModalDialog
        v-model="showCreateModal"
        title="Registrar Nueva Ausencia"
        @close="closeCreateModal"
      >
        <form @submit.prevent="submitCreateAbsence">
          <div style="margin-bottom: 12px;">
            <label for="create-absence-tech" style="display: block; font-size: 12px; font-weight: 700; color: var(--color-slate); margin-bottom: 4px;">
              Técnico *
            </label>
            <select
              id="create-absence-tech"
              v-model="createForm.technician_id"
              class="vg-input"
              style="width: 100%; height: 38px;"
              required
            >
              <option value="" disabled>Selecciona un técnico...</option>
              <option v-for="t in technicians" :key="t.id" :value="t.id">
                {{ t.name }}
              </option>
            </select>
          </div>

          <div style="margin-bottom: 12px;">
            <label for="create-absence-reason" style="display: block; font-size: 12px; font-weight: 700; color: var(--color-slate); margin-bottom: 4px;">
              Motivo *
            </label>
            <select
              id="create-absence-reason"
              v-model="createForm.reason"
              class="vg-input"
              style="width: 100%; height: 38px;"
              required
            >
              <option value="VACATION">Vacaciones</option>
              <option value="SICK_LEAVE">Baja médica</option>
              <option value="TRAINING">Formación / Permiso</option>
              <option value="PERSONAL_LEAVE">Asuntos propios</option>
              <option value="COMPENSATORY">Descanso compensatorio</option>
              <option value="OTHER">Otra ausencia</option>
            </select>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
            <div>
              <label for="create-absence-start" style="display: block; font-size: 12px; font-weight: 700; color: var(--color-slate); margin-bottom: 4px;">
                Fecha Inicio *
              </label>
              <input
                id="create-absence-start"
                v-model="createForm.starts_at"
                type="date"
                class="vg-input"
                style="width: 100%; height: 38px;"
                required
              />
            </div>
            <div>
              <label for="create-absence-end" style="display: block; font-size: 12px; font-weight: 700; color: var(--color-slate); margin-bottom: 4px;">
                Fecha Fin *
              </label>
              <input
                id="create-absence-end"
                v-model="createForm.ends_at"
                type="date"
                class="vg-input"
                style="width: 100%; height: 38px;"
                required
              />
            </div>
          </div>

          <div style="margin-bottom: 14px;">
            <label for="create-absence-notes" style="display: block; font-size: 12px; font-weight: 700; color: var(--color-slate); margin-bottom: 4px;">
              Notas (opcional, máx. 500 chars)
            </label>
            <textarea
              id="create-absence-notes"
              v-model="createForm.notes"
              class="vg-input"
              rows="3"
              style="width: 100%; font-size: 13px;"
              placeholder="Detalles sobre la ausencia, justificación o cobertura..."
            ></textarea>
          </div>

          <div v-if="createError" style="color: var(--color-error-text); font-size: 12px; margin-bottom: 12px;">
            {{ createError }}
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 8px;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              @click="closeCreateModal"
            >
              Cancelar
            </button>
            <button
              type="submit"
              class="vg-btn vg-btn-primary"
              :disabled="isCreating"
            >
              <span v-if="!isCreating">Guardar Ausencia</span>
              <span v-else>Guardando...</span>
            </button>
          </div>
        </form>
      </ModalDialog>

      <!-- MODAL: CANCELACIÓN LÓGICA -->
      <ModalDialog
        v-model="showCancelModal"
        title="Anular Ausencia"
        @close="closeCancelModal"
      >
        <form @submit.prevent="submitCancelAbsence">
          <p style="font-size: 13px; color: var(--color-slate); margin-bottom: 12px;">
            La ausencia será cancelada lógicamente (Art. III.1). El técnico volverá a estar disponible según su estado de turno.
          </p>

          <div style="margin-bottom: 14px;">
            <label for="cancel-absence-reason" style="display: block; font-size: 12px; font-weight: 700; color: var(--color-slate); margin-bottom: 4px;">
              Motivo de anulación (mínimo 5 caracteres) *
            </label>
            <textarea
              id="cancel-absence-reason"
              v-model="cancelForm.cancellation_reason"
              class="vg-input"
              rows="3"
              style="width: 100%; font-size: 13px;"
              placeholder="Ej: Alta médica voluntaria anticipada..."
              required
            ></textarea>
          </div>

          <div v-if="cancelError" style="color: var(--color-error-text); font-size: 12px; margin-bottom: 12px;">
            {{ cancelError }}
          </div>

          <div style="display: flex; justify-content: flex-end; gap: 8px;">
            <button
              type="button"
              class="vg-btn vg-btn-secondary"
              @click="closeCancelModal"
            >
              Cerrar
            </button>
            <button
              type="submit"
              class="vg-btn vg-btn-primary"
              style="background-color: var(--color-error); border-color: var(--color-error);"
              :disabled="isCancelling"
            >
              <span v-if="!isCancelling">Confirmar Anulación</span>
              <span v-else>Anulando...</span>
            </button>
          </div>
        </form>
      </ModalDialog>

      <!-- MODAL: ASISTENTE DE REASIGNACIÓN (RF-02) -->
      <ModalDialog
        v-model="showAssistedModal"
        title="Averías Pendientes Afectadas por la Ausencia"
        @close="closeAssistedModal"
      >
        <p style="font-size: 13px; color: var(--color-slate); margin-bottom: 12px;">
          El técnico ausente tiene <strong>{{ affectedIncidents.length }}</strong> avería(s) activas asignadas. Se recomienda reasignarlas para proteger los SLAs de los clientes.
        </p>

        <div style="max-height: 200px; overflow-y: auto; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); padding: 8px; margin-bottom: 14px;">
          <div
            v-for="inc in affectedIncidents"
            :key="inc.id"
            style="padding: 6px 8px; border-bottom: 1px solid var(--color-hairline); font-size: 12px;"
          >
            <strong>{{ inc.ticket_code }}</strong> ({{ inc.status }}) - Máquina #{{ inc.machine_id }}
          </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 8px;">
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            @click="closeAssistedModal"
          >
            Entendido
          </button>
        </div>
      </ModalDialog>
    </div>
  `
};

export default CoordinatorAbsencesTab;
