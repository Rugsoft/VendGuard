/**
 * VendGuard - OperationalZoneManagementTab Component (OperationalZoneManagementTab.js)
 *
 * Pestaña de Administración Territorial del panel de Coordinación (Módulo 17:
 * RF-01, RF-02, RF-03, DoD-01, DoD-02, DoD-04 y `docs/design.md`).
 *
 * QUÉ HACE
 *
 *   1. Lista las zonas operativas en tarjetas, cada una con su carril de color, su
 *      código en monoespaciada y su estado. No hay una tabla paralela: la tarjeta ES
 *      la ficha de la zona, y el color que el coordinador elige aquí es el mismo que
 *      pinta el mapa territorial.
 *   2. Alta y edición de metadatos (código inmutable, nombre, color corporativo y
 *      descripción) con validación en cliente del mismo contrato que el servidor.
 *   3. Gestión de códigos postales en bloque. Un CP que ya pertenece a otra zona no
 *      se mueve en silencio: el servidor responde 409 y el coordinador decide viendo
 *      **cuántas sedes cambian de demarcación** antes de confirmar (QA 4 / QA 6).
 *   4. Cuadrante técnico: alta como titular o suplente y baja lógica, con la lista de
 *      quién cubre la zona —nombre, código de operador, rol, guardia y alta— servida
 *      bajo demanda y retirable fila a fila (RF-02.3, QA 15). Un segundo titular vivo
 *      exige confirmación explícita porque degrada al anterior (QA 9).
 *   5. Lectura inversa por técnico: sus zonas vivas con el color de la demarcación y
 *      señalización expresa de quien no cubre ninguna (RF-02.3).
 *   5. Desactivación con liberación territorial: la confirmación cifra lo que se
 *      libera (CP, sedes y técnicos) antes de ejecutarla (QA 7 / QA 13).
 *
 * DECISIONES DE INTERFAZ
 *
 *   - **Nada se mueve sin decir qué se mueve.** Los tres modales de confirmación
 *     (traslado, degradación de titularidad y liberación) enumeran las consecuencias
 *     reales que el servidor ya calculó; el cliente no las estima por su cuenta.
 *   - **La zona se desactiva, no se borra.** El botón dice «Desactivar» y la tarjeta
 *     conserva su histórico; reactivarla no devuelve territorio, y el modal lo avisa.
 *   - **Estados vacíos que orientan.** Una zona sin códigos postales o sin cuadrante
 *     invita a completarla en lugar de mostrar una fila hueca.
 *   - **Se retira a quien se ve.** La baja del cuadrante sale de la fila del técnico,
 *     no de un selector con todo el directorio: el coordinador retira a quien está
 *     cubriendo la zona, sin margen para equivocarse de persona (QA 15).
 *
 * Dogma Vanilla: Vue 3 Options API en módulos ESM nativos, sin dependencias npm.
 * Dualismo Lingüístico: código en inglés, textos de interfaz en castellano.
 */

import { api } from '../api.js';
import { ModalDialog } from './ModalDialog.js';

/** Patrón del código postal español y de la zona: cinco dígitos exactos (QA 7). */
const POSTAL_CODE_PATTERN = /^\d{5}$/;

/** Patrón del color corporativo: `#RRGGBB` (dos dígitos por canal). */
const ZONE_COLOR_PATTERN = /^#[0-9a-fA-F]{6}$/;

/**
 * Color con el que arranca el alta: el azul de voltaje del sistema de diseño.
 *
 * Es el único literal cromático del módulo: el resto de colores entra por token
 * (`var(--color-…)`) o por dato (el `color_hex` de cada zona). El trinquete de deuda
 * de `tests/unit/DesignTokenDebtRatchetTest.mjs` vigila que no crezca.
 */
export const DEFAULT_ZONE_COLOR = '#2560ff';

/**
 * Convierte el texto del formulario en una lista de códigos postales normalizada.
 *
 * Acepta comas, puntos y coma, espacios y saltos de línea: el coordinador pega la
 * lista como la tiene a mano y la pestaña no le impone un separador concreto. Los
 * duplicados se colapsan y el orden se conserva en ascendente para que dos envíos
 * de la misma lista produzcan la misma petición.
 *
 * @param {string} raw Texto del área de códigos postales.
 * @returns {string[]} Códigos de cinco dígitos, sin repetir y ordenados.
 */
export function parsePostalCodesInput(raw) {
  const tokens = String(raw || '')
    .split(/[\s,;]+/)
    .map((token) => token.trim())
    .filter((token) => token !== '');

  return [...new Set(tokens)].sort();
}

/**
 * Códigos del formulario que no cumplen el contrato de cinco dígitos.
 *
 * @param {string} raw Texto del área de códigos postales.
 * @returns {string[]} Tokens inválidos, en el orden en que se escribieron.
 */
export function invalidPostalCodes(raw) {
  const tokens = String(raw || '')
    .split(/[\s,;]+/)
    .map((token) => token.trim())
    .filter((token) => token !== '');

  return [...new Set(tokens)].filter((token) => !POSTAL_CODE_PATTERN.test(token));
}

export const OperationalZoneManagementTab = {
  name: 'OperationalZoneManagementTab',
  components: { ModalDialog },
  data() {
    return {
      zones: [],
      unassignedLocationsCount: 0,
      isLoading: false,
      errorMessage: '',
      successMessage: '',
      filterStatus: 'all', // 'all' | 'active' | 'inactive'
      searchQuery: '',

      // Alta de zona
      showCreateModal: false,
      createForm: {
        code: '',
        name: '',
        color_hex: DEFAULT_ZONE_COLOR,
        description: ''
      },
      createErrors: {},
      isSubmittingCreate: false,

      // Edición de zona
      showEditModal: false,
      editForm: {
        id: null,
        code: '',
        name: '',
        color_hex: DEFAULT_ZONE_COLOR,
        description: '',
        is_active: true
      },
      editErrors: {},
      isSubmittingEdit: false,

      // Confirmación de desactivación con liberación territorial
      showReleaseModal: false,
      zoneToRelease: null,
      releaseSummary: '',
      isSubmittingRelease: false,

      // Gestión de códigos postales
      showPostalCodesModal: false,
      postalZone: null,
      postalCodesInput: '',
      postalErrors: {},
      postalConflicts: [],
      isSubmittingPostalCodes: false,

      // Cuadrante técnico
      showTechniciansModal: false,
      quadrantZone: null,
      technicianDirectory: [],
      quadrantForm: {
        technician_id: '',
        is_primary: false
      },
      quadrantErrors: {},
      primaryConflict: null,
      isSubmittingQuadrant: false,
      // Filas vivas del cuadrante que devuelve `GET /zones/{id}/technicians` (RF-02.3,
      // QA 15). Se piden al abrir el modal: el catálogo solo trae contadores.
      quadrantTechnicians: [],
      isLoadingQuadrant: false,
      isRemovingTechnicianId: null,
      quadrantNotice: '',

      // Lectura inversa: «qué cubre este técnico» (RF-02.3).
      catalogView: 'zones', // 'zones' | 'technicians'
      technicianQuadrants: [],
      isLoadingTechnicianQuadrants: false
    };
  },
  computed: {
    /** Zonas filtradas por estado y texto, sin pedir de nuevo al servidor. */
    filteredZones() {
      const query = this.searchQuery.trim().toLowerCase();

      return this.zones.filter((zone) => {
        if (this.filterStatus === 'active' && !zone.is_active) {
          return false;
        }
        if (this.filterStatus === 'inactive' && zone.is_active) {
          return false;
        }
        if (query === '') {
          return true;
        }

        const haystack = [
          zone.code,
          zone.name,
          zone.description,
          ...(zone.postal_codes || [])
        ]
          .join(' ')
          .toLowerCase();

        return haystack.includes(query);
      });
    },

    /** Contadores de la cabecera: lo que hay y lo que falta por asignar. */
    summaryMetrics() {
      const total = this.zones.length;
      const active = this.zones.filter((zone) => zone.is_active).length;
      const postalCodes = this.zones.reduce(
        (accumulator, zone) => accumulator + Number(zone.postal_codes_count || 0),
        0
      );
      const primary = this.zones.reduce(
        (accumulator, zone) => accumulator + Number(zone.primary_technicians_count || 0),
        0
      );
      const backup = this.zones.reduce(
        (accumulator, zone) => accumulator + Number(zone.backup_technicians_count || 0),
        0
      );

      return {
        total,
        active,
        inactive: total - active,
        postalCodes,
        primary,
        backup,
        unassigned: this.unassignedLocationsCount
      };
    },

    /** ¿La zona elegida conserva territorio que habría que liberar? */
    releaseRequired() {
      if (!this.zoneToRelease) {
        return false;
      }

      return this.territoryTotal(this.zoneToRelease) > 0;
    },

    /** Códigos nuevos que el formulario aún no tiene y que sí son válidos. */
    postalCodesToSubmit() {
      return parsePostalCodesInput(this.postalCodesInput);
    },

    /** Códigos ya presentes en la zona, para no anunciarlos como novedad. */
    newPostalCodes() {
      const current = new Set((this.postalZone?.postal_codes || []).map(String));
      return this.postalCodesToSubmit.filter((code) => !current.has(code));
    },

    /**
     * Códigos que la zona gobierna hoy y que la lista ya no declara.
     *
     * Guardar es una sustitución, no un incremento: lo ausente se libera de forma
     * lógica (hallazgo H-1), así que el coordinador debe verlo antes de confirmar.
     */
    postalCodesToRelease() {
      const declared = new Set(this.postalCodesToSubmit);
      return (this.postalZone?.postal_codes || [])
        .map(String)
        .filter((code) => !declared.has(code));
    },

    /** ¿Hay algún código con formato inválido en el área de texto? */
    postalInputHasInvalidCodes() {
      return invalidPostalCodes(this.postalCodesInput).length > 0;
    },

    /**
     * Técnicos activos del directorio interno, para **vincular** (RF-02.1).
     *
     * El directorio sigue siendo la fuente del alta porque el servidor es quien rechaza
     * una vinculación duplicada o una segunda titularidad. La baja ya no se hace desde
     * aquí: se retira a quien está en la lista del cuadrante, no a ciegas (QA 15).
     */
    availableTechnicians() {
      return this.technicianDirectory.filter((technician) => technician.id > 0);
    },

    /**
     * Contadores del cuadrante derivados de la lista cargada.
     *
     * Se calculan sobre las filas y no sobre el contador del catálogo para que, tras una
     * alta o una baja, lo que el coordinador ve y lo que cuenta no puedan divergir.
     */
    quadrantCounts() {
      const primary = this.quadrantTechnicians.filter((assignment) => assignment.is_primary).length;

      return { primary, backup: this.quadrantTechnicians.length - primary };
    },
  },
  mounted() {
    this.loadZones();
  },
  methods: {
    /**
     * Catálogo territorial completo (activas e inactivas) con el contador de sedes
     * sin zona. Es la única lectura de la pestaña: todo lo demás es reactivo.
     */
    async loadZones() {
      this.isLoading = true;
      this.errorMessage = '';

      try {
        // El transporte ya desenvuelve `{ success, data }`: lo que llega es el catálogo.
        const data = (await api.coordinator.getZones()) || {};

        this.zones = Array.isArray(data.zones) ? data.zones : [];
        this.unassignedLocationsCount = Number(data.unassigned_locations_count || 0);

        // El modal de cuadrante referencia un objeto de la lista: tras recargar hay que
        // apuntarlo a la fila nueva o sus contadores se quedarían congelados en los de
        // antes de vincular al técnico.
        if (this.quadrantZone) {
          const refreshed = this.zones.find((zone) => zone.id === this.quadrantZone.id);
          if (refreshed) {
            this.quadrantZone = refreshed;
          }
        }
      } catch (error) {
        this.errorMessage = error?.message || 'No se pudo cargar el catálogo de zonas operativas.';
      } finally {
        this.isLoading = false;
      }
    },

    /** Suma del territorio vivo de una zona (CP + sedes + técnicos). */
    territoryTotal(zone) {
      if (!zone) {
        return 0;
      }

      return (
        Number(zone.postal_codes_count || 0)
        + Number(zone.locations_count || 0)
        + Number(zone.primary_technicians_count || 0)
        + Number(zone.backup_technicians_count || 0)
      );
    },

    /** Primera letra de la zona, para el marcador tipográfico de la tarjeta. */
    zoneInitial(zone) {
      return String(zone?.name || '?').trim().charAt(0).toUpperCase();
    },

    /** Texto legible de la carga del cuadrante. */
    quadrantSummary(zone) {
      const primary = Number(zone?.primary_technicians_count || 0);
      const backup = Number(zone?.backup_technicians_count || 0);

      if (primary === 0 && backup === 0) {
        return 'Sin cuadrante';
      }

      return `${primary} titular(es) · ${backup} suplente(s)`;
    },

    /** Códigos postales visibles en la tarjeta antes de resumir el resto. */
    visiblePostalCodes(zone, limit = 6) {
      const codes = Array.isArray(zone?.postal_codes) ? zone.postal_codes : [];
      return codes.slice(0, limit);
    },

    /** Códigos que no caben en la tarjeta. */
    hiddenPostalCodesCount(zone, limit = 6) {
      const codes = Array.isArray(zone?.postal_codes) ? zone.postal_codes : [];
      return Math.max(0, codes.length - limit);
    },

    /** Mensaje temporal de confirmación, con retirada automática a los cuatro segundos. */
    showSuccessNotification(message) {
      this.successMessage = message;
      setTimeout(() => {
        if (this.successMessage === message) {
          this.successMessage = '';
        }
      }, 4000);
    },

    /** Validación del alta, con el mismo contrato que aplica el servidor. */
    validateZoneForm(form, { requireCode }) {
      const errors = {};

      if (requireCode) {
        const code = String(form.code || '').trim().toUpperCase();
        if (code === '') {
          errors.code = 'El código de zona es obligatorio.';
        } else if (!/^[A-Z0-9_-]{3,50}$/.test(code)) {
          errors.code = 'El código admite entre 3 y 50 caracteres: letras, números, guion y guion bajo.';
        }
      }

      if (!String(form.name || '').trim()) {
        errors.name = 'El nombre de la zona es obligatorio.';
      } else if (String(form.name).trim().length < 3) {
        errors.name = 'El nombre debe tener al menos 3 caracteres.';
      }

      if (!ZONE_COLOR_PATTERN.test(String(form.color_hex || '').trim())) {
        errors.color_hex = 'El color debe tener el formato #RRGGBB.';
      }

      return errors;
    },

    openCreateModal() {
      this.createForm = {
        code: '',
        name: '',
        color_hex: DEFAULT_ZONE_COLOR,
        description: ''
      };
      this.createErrors = {};
      this.showCreateModal = true;
    },

    closeCreateModal() {
      this.showCreateModal = false;
      this.createErrors = {};
    },

    async submitCreate() {
      this.createErrors = this.validateZoneForm(this.createForm, { requireCode: true });
      if (Object.keys(this.createErrors).length > 0) {
        return;
      }

      this.isSubmittingCreate = true;
      try {
        const payload = {
          code: String(this.createForm.code).trim().toUpperCase(),
          name: String(this.createForm.name).trim(),
          color_hex: String(this.createForm.color_hex).trim().toLowerCase(),
          description: String(this.createForm.description || '').trim() || null
        };

        await api.coordinator.createZone(payload);
        this.showSuccessNotification(`Zona «${payload.name}» creada. Ya puede recibir códigos postales y técnicos.`);
        this.closeCreateModal();
        await this.loadZones();
      } catch (error) {
        if (error?.code === 'ZONE_CODE_ALREADY_EXISTS') {
          this.createErrors.code = error.message;
        } else if (error?.code === 'ZONE_NAME_ALREADY_EXISTS') {
          this.createErrors.name = error.message;
        } else {
          this.createErrors.general = error?.message || 'No se pudo crear la zona operativa.';
        }
      } finally {
        this.isSubmittingCreate = false;
      }
    },

    openEditModal(zone) {
      this.editForm = {
        id: zone.id,
        code: zone.code,
        name: zone.name,
        color_hex: zone.color_hex || DEFAULT_ZONE_COLOR,
        description: zone.description || '',
        is_active: Boolean(zone.is_active)
      };
      this.editErrors = {};
      this.showEditModal = true;
    },

    closeEditModal() {
      this.showEditModal = false;
      this.editErrors = {};
    },

    /**
     * Guarda la edición. Si el coordinador desmarca «activa» y la zona conserva
     * territorio, no se envía nada: se abre la confirmación con lo que se liberaría.
     */
    async submitEdit() {
      this.editErrors = this.validateZoneForm(this.editForm, { requireCode: false });
      if (Object.keys(this.editErrors).length > 0) {
        return;
      }

      const currentZone = this.zones.find((zone) => zone.id === this.editForm.id) || null;

      if (this.editForm.is_active === false && currentZone && currentZone.is_active) {
        this.showEditModal = false;
        await this.requestDeactivation(currentZone);
        return;
      }

      await this.persistEdit(false);
    },

    /** Envía la edición, opcionalmente confirmando la liberación del territorio. */
    async persistEdit(confirmRelease) {
      this.isSubmittingEdit = true;
      try {
        const payload = {
          name: String(this.editForm.name).trim(),
          color_hex: String(this.editForm.color_hex).trim().toLowerCase(),
          description: String(this.editForm.description || '').trim() || null,
          is_active: Boolean(this.editForm.is_active)
        };
        if (confirmRelease) {
          payload.confirm_release = true;
        }

        await api.coordinator.updateZone(this.editForm.id, payload);
        this.showSuccessNotification(
          payload.is_active
            ? `Zona «${payload.name}» actualizada.`
            : `Zona «${payload.name}» desactivada: sus sedes quedan sin zona y el territorio se liberó.`
        );
        this.closeEditModal();
        this.closeReleaseModal();
        await this.loadZones();
      } catch (error) {
        // El servidor también exige la confirmación: si la pide, se abre el mismo
        // modal con lo que ya sabemos de la zona en lugar de mostrar un error seco.
        if (error?.status === 422 && !confirmRelease) {
          const zone = this.zones.find((item) => item.id === this.editForm.id) || null;
          if (zone) {
            this.zoneToRelease = zone;
            this.releaseSummary = this.buildReleaseSummary(zone);
            this.showEditModal = false;
            this.showReleaseModal = true;
            return;
          }
        }

        // Con el modal de edición ya cerrado, el error se publica donde el coordinador
        // lo ve: en la franja de avisos de la pestaña, no en un formulario invisible.
        if (confirmRelease || !this.showEditModal) {
          this.errorMessage = error?.message || 'No se pudo actualizar la zona.';
        } else {
          this.editErrors.general = error?.message || 'No se pudo actualizar la zona.';
        }
      } finally {
        this.isSubmittingEdit = false;
      }
    },

    /** Frase de impacto de la liberación, con las cifras reales de la zona. */
    buildReleaseSummary(zone) {
      return [
        `${Number(zone.postal_codes_count || 0)} código(s) postal(es)`,
        `${Number(zone.locations_count || 0)} sede(s)`,
        `${Number(zone.primary_technicians_count || 0) + Number(zone.backup_technicians_count || 0)} técnico(s) del cuadrante`
      ].join(' · ');
    },

    closeReleaseModal() {
      this.showReleaseModal = false;
      this.zoneToRelease = null;
      this.releaseSummary = '';
      this.isSubmittingRelease = false;
    },

    /**
     * Abre la desactivación. Sin territorio que liberar no hay nada que confirmar: la
     * zona se desactiva directamente en lugar de pedir una firma ceremonial.
     */
    async requestDeactivation(zone) {
      if (this.territoryTotal(zone) === 0) {
        this.zoneToRelease = zone;
        await this.confirmRelease();
        return;
      }

      this.zoneToRelease = zone;
      this.releaseSummary = this.buildReleaseSummary(zone);
      this.showReleaseModal = true;
    },

    /** Confirma la desactivación liberando el territorio en una sola operación. */
    async confirmRelease() {
      if (!this.zoneToRelease) {
        return;
      }

      this.isSubmittingRelease = true;
      try {
        await api.coordinator.updateZone(this.zoneToRelease.id, { is_active: false, confirm_release: true });
        this.showSuccessNotification(
          `Zona «${this.zoneToRelease.name}» desactivada. Sus sedes quedaron «Sin Zona Asignada» y el territorio está libre.`
        );
        this.closeReleaseModal();
        await this.loadZones();
      } catch (error) {
        this.errorMessage = error?.message || 'No se pudo desactivar la zona.';
        this.closeReleaseModal();
      } finally {
        this.isSubmittingRelease = false;
      }
    },

    /** Reactiva una zona sin devolverle territorio: debe reasignarse a mano. */
    async reactivateZone(zone) {
      try {
        await api.coordinator.updateZone(zone.id, { is_active: true });
        this.showSuccessNotification(
          `Zona «${zone.name}» reactivada. Debe volver a declarar sus códigos postales y su cuadrante.`
        );
        await this.loadZones();
      } catch (error) {
        this.errorMessage = error?.message || 'No se pudo reactivar la zona.';
      }
    },

    // ─────────────────────────────────────────────────────────────────
    // Códigos postales (RF-01.2, QA 4 / QA 6 / QA 7)
    // ─────────────────────────────────────────────────────────────────

    openPostalCodesModal(zone) {
      this.postalZone = zone;
      this.postalCodesInput = (zone.postal_codes || []).join(', ');
      this.postalErrors = {};
      this.postalConflicts = [];
      this.showPostalCodesModal = true;
    },

    closePostalCodesModal() {
      this.showPostalCodesModal = false;
      this.postalZone = null;
      this.postalCodesInput = '';
      this.postalConflicts = [];
      this.postalErrors = {};
    },

    /**
     * Envía la lista completa de códigos de la zona. Un traslado desde otra zona
     * responde 409 con las sedes afectadas y **no mueve nada** hasta que se confirme.
     */
    async submitPostalCodes(confirmTransfers = false) {
      this.postalErrors = {};

      if (this.postalZone === null) {
        return;
      }

      const invalid = invalidPostalCodes(this.postalCodesInput);
      if (invalid.length > 0) {
        this.postalErrors.codes = `Estos códigos no tienen cinco dígitos: ${invalid.join(', ')}.`;
        return;
      }

      const codes = this.postalCodesToSubmit;
      if (codes.length === 0) {
        this.postalErrors.codes = 'Declare al menos un código postal de cinco dígitos.';
        return;
      }

      this.isSubmittingPostalCodes = true;
      try {
        const result = await api.coordinator.assignZonePostalCodes(this.postalZone.id, codes, confirmTransfers);
        const transferred = Number(result?.transferred_postal_codes?.length || 0);
        const released = Number(result?.released_postal_codes?.length || 0);
        const affected = Number(result?.affected_locations_count || 0);

        const parts = [];
        if (released > 0) {
          parts.push(`${released} código(s) liberado(s)`);
        }
        if (transferred > 0) {
          parts.push(`${transferred} código(s) trasladado(s)`);
        }
        if (affected > 0) {
          parts.push(`${affected} sede(s) recolocada(s)`);
        }

        this.showSuccessNotification(
          parts.length === 0
            ? 'Códigos postales asignados a la zona.'
            : `Territorio actualizado: ${parts.join(', ')}.`
        );
        this.postalConflicts = [];
        this.closePostalCodesModal();
        await this.loadZones();
      } catch (error) {
        if (error?.code === 'POSTAL_CODES_REQUIRE_CONFIRMATION') {
          this.postalConflicts = error?.raw?.error?.conflicts || error?.details?.conflicts || [];
          this.postalErrors.codes = error.message;
        } else if (error?.code === 'UNPROCESSABLE_ENTITY') {
          this.postalErrors.codes = error.message;
        } else {
          this.postalErrors.codes = error?.message || 'No se pudieron guardar los códigos postales.';
        }
      } finally {
        this.isSubmittingPostalCodes = false;
      }
    },

    /** Segunda llamada, ya confirmada: mueve los CP y recoloca las sedes heredadas. */
    async confirmPostalCodesTransfer() {
      await this.submitPostalCodes(true);
    },

    cancelPostalCodesTransfer() {
      this.postalConflicts = [];
      this.postalErrors = {};
    },

    // ─────────────────────────────────────────────────────────────────
    // Cuadrante técnico (RF-02.1, RF-02.2, QA 9 / QA 10)
    // ─────────────────────────────────────────────────────────────────

    async openTechniciansModal(zone) {
      this.quadrantZone = zone;
      this.quadrantForm = { technician_id: '', is_primary: false };
      this.quadrantErrors = {};
      this.primaryConflict = null;
      this.quadrantNotice = '';
      this.quadrantTechnicians = [];
      this.showTechniciansModal = true;

      // Dos lecturas independientes: el directorio para **vincular** y el cuadrante vivo
      // para **nombrar** a quien ya cubre la zona (RF-02.3, QA 15).
      await Promise.all([this.loadTechnicianDirectory(), this.loadZoneQuadrant(zone.id)]);
    },

    /** Directorio de técnicos activos que alimenta el selector de alta. */
    async loadTechnicianDirectory() {
      try {
        const technicians = await api.admin.getUsers({ role: 'TECHNICIAN', status: 'active' });
        this.technicianDirectory = Array.isArray(technicians) ? technicians : [];
      } catch (error) {
        this.quadrantErrors.general = error?.message || 'No se pudo cargar el directorio de técnicos.';
      }
    },

    /**
     * Cuadrante vivo de la zona abierta (RF-02.3, QA 15).
     *
     * Se pide cada vez que el modal se abre y después de cada mutación: el catálogo solo
     * publica contadores, así que confiar en la fila cargada dejaría la lista congelada.
     */
    async loadZoneQuadrant(zoneId) {
      this.isLoadingQuadrant = true;

      try {
        const data = (await api.coordinator.getZoneTechnicians(zoneId)) || {};
        this.quadrantTechnicians = Array.isArray(data.technicians) ? data.technicians : [];
      } catch (error) {
        this.quadrantErrors.general = error?.message || 'No se pudo cargar el cuadrante de la zona.';
      } finally {
        this.isLoadingQuadrant = false;
      }
    },

    closeTechniciansModal() {
      this.showTechniciansModal = false;
      this.quadrantZone = null;
      this.quadrantForm = { technician_id: '', is_primary: false };
      this.primaryConflict = null;
      this.quadrantErrors = {};
      this.quadrantNotice = '';
      this.quadrantTechnicians = [];
    },

    /**
     * Cambia entre la vista por zona y la lectura inversa por técnico (RF-02.3).
     *
     * La lectura inversa es perezosa: no se paga hasta que el coordinador la pide, para
     * que abrir la pestaña territorial siga costando una sola lectura.
     */
    async selectCatalogView(view) {
      this.catalogView = view === 'technicians' ? 'technicians' : 'zones';

      if (this.catalogView === 'technicians') {
        await this.loadTechnicianQuadrants();
      }
    },

    /** Cobertura por técnico: sus zonas vivas y las que no cubre (QA 15). */
    async loadTechnicianQuadrants() {
      this.isLoadingTechnicianQuadrants = true;

      try {
        const data = (await api.coordinator.getTechnicianQuadrants()) || {};
        this.technicianQuadrants = Array.isArray(data.technicians) ? data.technicians : [];
      } catch (error) {
        this.errorMessage = error?.message || 'No se pudo cargar la cobertura por técnico.';
      } finally {
        this.isLoadingTechnicianQuadrants = false;
      }
    },

    /** Fecha de alta legible; una marca ilegible no inventa una fecha. */
    formatAssignmentDate(value) {
      if (!value) {
        return '—';
      }

      // El motor entrega `2026-10-20 09:12:44+00`: `Date` no interpreta un desfase de
      // dos dígitos sin minutos, así que se normaliza antes de parsear.
      const parsed = new Date(
        String(value).trim().replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00')
      );
      if (Number.isNaN(parsed.getTime())) {
        return '—';
      }

      return parsed.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
    },

    async submitQuadrantAssignment(confirmPrimaryOverride = false) {
      this.quadrantErrors = {};
      this.quadrantNotice = '';

      if (this.quadrantZone === null) {
        return;
      }

      const technicianId = Number(this.quadrantForm.technician_id);
      if (!Number.isInteger(technicianId) || technicianId <= 0) {
        this.quadrantErrors.technician_id = 'Seleccione un técnico del directorio.';
        return;
      }

      this.isSubmittingQuadrant = true;
      try {
        await api.coordinator.assignZoneTechnician(
          this.quadrantZone.id,
          technicianId,
          this.quadrantForm.is_primary,
          confirmPrimaryOverride
        );

        const role = this.quadrantForm.is_primary ? 'titular' : 'suplente';
        const zoneId = this.quadrantZone.id;

        this.showSuccessNotification(`Técnico vinculado como ${role} de «${this.quadrantZone.name}».`);
        this.primaryConflict = null;
        this.quadrantForm = { technician_id: '', is_primary: false };
        await this.loadZones();
        await this.loadZoneQuadrant(zoneId);
      } catch (error) {
        if (error?.code === 'TECHNICIAN_ALREADY_PRIMARY_IN_OTHER_ZONE') {
          this.primaryConflict = {
            technicianId,
            message: error.message,
            currentZoneName: error?.raw?.error?.current_zone_name || error?.details?.current_zone_name || ''
          };
        } else if (error?.code === 'UNPROCESSABLE_ENTITY' || error?.code === 'BAD_REQUEST') {
          this.quadrantErrors.technician_id = error.message;
        } else {
          this.quadrantErrors.general = error?.message || 'No se pudo vincular al técnico.';
        }
      } finally {
        this.isSubmittingQuadrant = false;
      }
    },

    /** Confirmación de la degradación del titular anterior (QA 9). */
    async confirmPrimaryOverride() {
      await this.submitQuadrantAssignment(true);
      if (this.primaryConflict === null) {
        this.quadrantNotice = 'Titularidad transferida: la vinculación anterior quedó degradada a suplente.';
      }
    },

    cancelPrimaryOverride() {
      this.primaryConflict = null;
      this.quadrantForm.is_primary = false;
    },

    /**
     * Baja lógica del cuadrante. El histórico se conserva (`removed_at`), así que la
     * interfaz habla de «retirar», nunca de «eliminar» (Art. III.1).
     */
    async removeTechnicianFromQuadrant(technicianId) {
      if (this.quadrantZone === null) {
        return;
      }

      this.isRemovingTechnicianId = technicianId;
      this.quadrantErrors = {};
      this.quadrantNotice = '';

      try {
        const zoneId = this.quadrantZone.id;

        await api.coordinator.removeZoneTechnician(zoneId, technicianId);
        this.quadrantNotice = 'Técnico retirado del cuadrante. Su histórico de asignaciones se conserva.';
        await this.loadZones();
        await this.loadZoneQuadrant(zoneId);
      } catch (error) {
        this.quadrantErrors.general = error?.code === 'ASSIGNMENT_NOT_FOUND'
          ? 'Ese técnico no tiene una vinculación viva con esta zona.'
          : (error?.message || 'No se pudo retirar al técnico del cuadrante.');
      } finally {
        this.isRemovingTechnicianId = null;
      }
    }
  },
  template: `
    <div class="operational-zone-tab" data-testid="operational-zone-tab">
      <!-- Cabecera: el territorio como estado del sistema, no como adorno. -->
      <div class="vg-card mb-4" data-testid="zone-tab-header">
        <div class="p-3 d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div>
            <div class="text-uppercase fw-bold" style="letter-spacing: 0.08em; font-size: 11px; color: var(--color-ink-muted);">
              Módulo 17 · Zonificación operativa
            </div>
            <h3 class="mb-1" style="font-size: 24px; font-weight: 500; letter-spacing: -0.3px; color: var(--color-ink-slate);">
              Demarcación territorial del parque
            </h3>
            <p class="mb-0" style="font-size: 13px; color: var(--color-ink-muted);">
              Los códigos postales resuelven la zona de cada sede; el cuadrante decide quién la atiende. Todo cambio queda auditado.
            </p>
          </div>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="vg-btn vg-btn-secondary" @click="loadZones" :disabled="isLoading" data-testid="zone-reload">
              <span v-if="isLoading" class="spinner-border spinner-border-sm me-1" role="status"></span>
              <span>Actualizar</span>
            </button>
            <button type="button" class="vg-btn vg-btn-primary" @click="openCreateModal" data-testid="zone-create-open">
              <span>➕</span>
              <span>Nueva Zona</span>
            </button>
          </div>
        </div>

        <!-- Franja de contadores: cifras del catálogo, sin tarjetas decorativas. -->
        <div class="d-flex flex-wrap gap-2 px-3 pb-3" data-testid="zone-summary">
          <span class="vg-chip">Zonas: {{ summaryMetrics.total }}</span>
          <span class="vg-chip vg-chip-success">Activas: {{ summaryMetrics.active }}</span>
          <span class="vg-chip">Inactivas: {{ summaryMetrics.inactive }}</span>
          <span class="vg-chip">CP asignados: {{ summaryMetrics.postalCodes }}</span>
          <span class="vg-chip">Titulares: {{ summaryMetrics.primary }}</span>
          <span class="vg-chip">Suplentes: {{ summaryMetrics.backup }}</span>
          <span class="vg-chip vg-chip-warning" data-testid="zone-unassigned-chip">
            Sedes sin zona: {{ summaryMetrics.unassigned }}
          </span>
        </div>
      </div>

      <!-- Avisos -->
      <div v-if="successMessage" class="vg-alert vg-alert-info d-flex justify-content-between align-items-center" role="status" data-testid="zone-success">
        <span>✅ {{ successMessage }}</span>
        <button type="button" class="vg-btn vg-btn-ghost vg-btn-icon vg-btn-close" @click="successMessage = ''" aria-label="Cerrar aviso"></button>
      </div>
      <div v-if="errorMessage" class="vg-alert vg-alert-error d-flex justify-content-between align-items-center" role="alert" data-testid="zone-error">
        <span>⚠️ {{ errorMessage }}</span>
        <button type="button" class="vg-btn vg-btn-ghost vg-btn-icon vg-btn-close" @click="errorMessage = ''" aria-label="Cerrar aviso"></button>
      </div>

      <!-- Filtros -->
      <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <input
          type="search"
          class="vg-input"
          style="max-width: 320px;"
          placeholder="Buscar por código, nombre o código postal"
          v-model="searchQuery"
          data-testid="zone-search"
        />
        <select class="vg-select" style="max-width: 200px;" v-model="filterStatus" data-testid="zone-filter-status">
          <option value="all">Todas las zonas</option>
          <option value="active">Solo activas</option>
          <option value="inactive">Solo inactivas</option>
        </select>
        <!-- Dos lecturas del mismo territorio: la demarcación y la persona (RF-02.3). -->
        <div
          class="d-inline-flex"
          role="group"
          aria-label="Vista de la consola territorial"
          style="border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); overflow: hidden;"
        >
          <button
            type="button"
            class="vg-btn vg-btn-sm"
            :class="catalogView === 'zones' ? 'vg-btn-primary' : 'vg-btn-ghost'"
            :aria-pressed="catalogView === 'zones' ? 'true' : 'false'"
            @click="selectCatalogView('zones')"
            data-testid="zone-view-zones"
          >Por zona</button>
          <button
            type="button"
            class="vg-btn vg-btn-sm"
            :class="catalogView === 'technicians' ? 'vg-btn-primary' : 'vg-btn-ghost'"
            :aria-pressed="catalogView === 'technicians' ? 'true' : 'false'"
            @click="selectCatalogView('technicians')"
            data-testid="zone-view-technicians"
          >Por técnico</button>
        </div>
        <span class="ms-auto" style="font-size: 12px; color: var(--color-ink-muted);">
          {{ filteredZones.length }} de {{ zones.length }} zonas visibles
        </span>
      </div>

      <!-- Cargando / vacío / tarjetas -->
      <div v-if="catalogView === 'zones' && isLoading" class="text-center py-5" style="color: var(--color-ink-muted);" data-testid="zone-loading">
        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
        <span>Cargando demarcaciones…</span>
      </div>

      <div v-else-if="catalogView === 'zones' && zones.length === 0" class="vg-card p-4 text-center" data-testid="zone-empty">
        <div class="mb-2" style="font-size: 28px;">🗺️</div>
        <h4 class="mb-1" style="font-size: 18px; font-weight: 500;">Aún no hay zonas operativas</h4>
        <p class="mb-3" style="font-size: 13px; color: var(--color-ink-muted);">
          Cree la primera demarcación y asígnela a sus códigos postales para que el triaje recomiende al técnico correcto.
        </p>
        <button type="button" class="vg-btn vg-btn-primary" @click="openCreateModal">Crear la primera zona</button>
      </div>

      <div v-else-if="catalogView === 'zones' && filteredZones.length === 0" class="vg-card p-4 text-center" data-testid="zone-no-results">
        <p class="mb-1 fw-semibold">Ninguna zona coincide con el filtro.</p>
        <p class="mb-0" style="font-size: 13px; color: var(--color-ink-muted);">Pruebe otro estado o borre la búsqueda.</p>
      </div>

      <div v-else-if="catalogView === 'zones'" class="row g-3" data-testid="zone-grid">
        <div v-for="zone in filteredZones" :key="zone.id" class="col-12 col-xl-6" data-testid="zone-card">
          <!-- El carril de color es la identidad cromática de la zona: el mismo que usa el mapa. -->
          <article
            class="vg-card h-100 d-flex flex-column"
            :style="{ borderLeftWidth: '4px', borderLeftStyle: 'solid', borderLeftColor: zone.color_hex, opacity: zone.is_active ? 1 : 0.72 }"
          >
            <div class="p-3 flex-grow-1">
              <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div class="d-flex align-items-start gap-2">
                  <span
                    class="d-inline-flex align-items-center justify-content-center flex-shrink-0"
                    :style="{ width: '32px', height: '32px', borderRadius: 'var(--radius-interactive)', backgroundColor: zone.color_hex, color: '#ffffff', fontWeight: 600 }"
                    aria-hidden="true"
                  >{{ zoneInitial(zone) }}</span>
                  <div>
                    <div class="font-monospace" style="font-size: 11px; letter-spacing: 0.06em; color: var(--color-ink-muted);">
                      {{ zone.code }}
                    </div>
                    <h4 class="mb-0" style="font-size: 18px; font-weight: 500; line-height: 1.25;">{{ zone.name }}</h4>
                  </div>
                </div>
                <span v-if="zone.is_active" class="vg-chip vg-chip-success" data-testid="zone-status-active">Activa</span>
                <span v-else class="vg-chip" data-testid="zone-status-inactive">Inactiva</span>
              </div>

              <p v-if="zone.description" class="mb-2" style="font-size: 13px; color: var(--color-ink-secondary);">
                {{ zone.description }}
              </p>
              <p v-else class="mb-2 fst-italic" style="font-size: 12px; color: var(--color-ink-muted);">
                Sin descripción operativa.
              </p>

              <!-- Tres cifras: territorio, parque y personas. -->
              <div class="d-flex flex-wrap gap-2 mb-2" data-testid="zone-metrics">
                <span class="vg-chip" :title="zone.postal_codes_count + ' códigos postales vigentes'">
                  📮 {{ zone.postal_codes_count }} CP
                </span>
                <span class="vg-chip" :title="zone.locations_count + ' sedes en la demarcación'">
                  🏢 {{ zone.locations_count }} sedes
                </span>
                <span class="vg-chip" :title="quadrantSummary(zone)">
                  🧑‍🔧 {{ zone.primary_technicians_count }}T / {{ zone.backup_technicians_count }}S
                </span>
              </div>

              <!-- Badges de códigos postales: el territorio explícito, no un número. -->
              <div v-if="(zone.postal_codes || []).length > 0" class="d-flex flex-wrap gap-1" data-testid="zone-postal-badges">
                <span
                  v-for="code in visiblePostalCodes(zone)"
                  :key="code"
                  class="font-monospace"
                  style="font-size: 11px; padding: 2px 6px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive); color: var(--color-ink-secondary); background-color: var(--color-surface-1);"
                >{{ code }}</span>
                <span v-if="hiddenPostalCodesCount(zone) > 0" style="font-size: 11px; color: var(--color-ink-muted);">
                  +{{ hiddenPostalCodesCount(zone) }} más
                </span>
              </div>
              <p v-else class="mb-0" style="font-size: 12px; color: var(--color-warning-text);">
                Sin códigos postales: las sedes de esta demarcación no heredan zona.
              </p>
            </div>

            <div class="px-3 py-2 d-flex flex-wrap gap-2" style="border-top: 1px solid var(--color-hairline);">
              <button type="button" class="vg-btn vg-btn-sm vg-btn-secondary" @click="openEditModal(zone)" data-testid="zone-edit">
                ✏️ Editar
              </button>
              <button type="button" class="vg-btn vg-btn-sm vg-btn-info-soft" @click="openPostalCodesModal(zone)" data-testid="zone-postal-codes">
                📮 Códigos postales
              </button>
              <button type="button" class="vg-btn vg-btn-sm vg-btn-primary-outline" @click="openTechniciansModal(zone)" data-testid="zone-technicians">
                🧑‍🔧 Cuadrante
              </button>
              <button
                v-if="zone.is_active"
                type="button"
                class="vg-btn vg-btn-sm vg-btn-danger-soft"
                @click="requestDeactivation(zone)"
                data-testid="zone-deactivate"
              >⏸️ Desactivar</button>
              <button
                v-else
                type="button"
                class="vg-btn vg-btn-sm vg-btn-success-soft"
                @click="reactivateZone(zone)"
                data-testid="zone-reactivate"
              >▶️ Reactivar</button>
            </div>
          </article>
        </div>
      </div>

      <!-- Lectura inversa: qué cubre cada técnico (RF-02.3, QA 15). -->
      <div v-if="catalogView === 'technicians'" data-testid="technician-quadrants">
        <div v-if="isLoadingTechnicianQuadrants" class="text-center py-5" style="color: var(--color-ink-muted);" data-testid="technician-quadrants-loading">
          <div class="spinner-border spinner-border-sm me-2" role="status"></div>
          <span>Cargando la cobertura por técnico…</span>
        </div>

        <div v-else-if="technicianQuadrants.length === 0" class="vg-card p-4 text-center" data-testid="technician-quadrants-empty">
          <div class="mb-2" style="font-size: 28px;">🧑‍🔧</div>
          <p class="mb-1 fw-semibold">No hay técnicos en el directorio.</p>
          <p class="mb-0" style="font-size: 13px; color: var(--color-ink-muted);">
            Cree técnicos en Administración → Usuarios para poder asignarles demarcaciones.
          </p>
        </div>

        <div v-else class="d-flex flex-column gap-2">
          <article
            v-for="technician in technicianQuadrants"
            :key="technician.technician_id"
            class="vg-card p-3"
            data-testid="technician-quadrant-row"
          >
            <div class="d-flex flex-wrap align-items-center gap-2">
              <strong style="font-size: 15px;">{{ technician.name }}</strong>
              <span class="font-monospace" style="font-size: 12px; color: var(--color-ink-secondary);">
                {{ technician.operator_code || ('ID #' + technician.technician_id) }}
              </span>
              <span v-if="technician.is_on_call" class="vg-chip vg-chip-warning">En guardia</span>
              <span v-if="!technician.is_active" class="vg-chip vg-chip-danger" data-testid="technician-inactive">Baja</span>
              <span
                v-if="technician.zones.length === 0"
                class="vg-chip ms-auto"
                data-testid="technician-without-zone"
              >Sin zona asignada</span>
            </div>

            <div v-if="technician.zones.length > 0" class="d-flex flex-wrap gap-2 mt-2" data-testid="technician-zone-chips">
              <span
                v-for="zone in technician.zones"
                :key="zone.zone_id"
                class="vg-chip"
                :style="{ borderColor: zone.color_hex, color: zone.color_hex }"
                :data-testid="zone.is_primary ? 'technician-zone-primary' : 'technician-zone-backup'"
              >
                <span
                  aria-hidden="true"
                  :style="{ display: 'inline-block', width: '8px', height: '8px', borderRadius: '50%', backgroundColor: zone.color_hex, marginRight: '6px' }"
                ></span>
                {{ zone.name }} · {{ zone.is_primary ? 'Titular' : 'Suplente' }}
              </span>
            </div>
          </article>
        </div>
      </div>

      <!-- MODAL: alta de zona -->
      <ModalDialog v-model="showCreateModal" title="Nueva zona operativa" size="md" @close="closeCreateModal">
        <div data-testid="zone-create-modal">
          <div v-if="createErrors.general" class="vg-alert vg-alert-error">{{ createErrors.general }}</div>

          <form id="zone-create-form" @submit.prevent="submitCreate">
            <div class="mb-3">
              <label class="form-label fw-bold" style="font-size: 13px;" for="zone-create-code">
                Código de zona <span class="text-danger">*</span>
              </label>
              <input
                id="zone-create-code"
                type="text"
                class="vg-input font-monospace"
                placeholder="ZONA-NORTE"
                maxlength="50"
                v-model="createForm.code"
                data-testid="zone-create-code"
              />
              <small style="font-size: 12px; color: var(--color-ink-muted);">Se guarda en mayúsculas y no se puede cambiar después.</small>
              <div v-if="createErrors.code" class="text-danger" style="font-size: 12px;">{{ createErrors.code }}</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold" style="font-size: 13px;" for="zone-create-name">
                Nombre <span class="text-danger">*</span>
              </label>
              <input
                id="zone-create-name"
                type="text"
                class="vg-input"
                placeholder="Barcelona Litoral"
                maxlength="100"
                v-model="createForm.name"
                data-testid="zone-create-name"
              />
              <div v-if="createErrors.name" class="text-danger" style="font-size: 12px;">{{ createErrors.name }}</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold" style="font-size: 13px;" for="zone-create-color">Color corporativo</label>
              <div class="d-flex align-items-center gap-2">
                <input id="zone-create-color" type="color" v-model="createForm.color_hex" data-testid="zone-create-color" style="width: 48px; height: 32px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);" />
                <span class="font-monospace" style="font-size: 12px; color: var(--color-ink-secondary);">{{ createForm.color_hex }}</span>
              </div>
              <small style="font-size: 12px; color: var(--color-ink-muted);">Es el color con el que la zona se pinta en el mapa territorial.</small>
              <div v-if="createErrors.color_hex" class="text-danger" style="font-size: 12px;">{{ createErrors.color_hex }}</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold" style="font-size: 13px;" for="zone-create-description">Descripción operativa</label>
              <textarea
                id="zone-create-description"
                class="vg-textarea"
                rows="3"
                placeholder="Qué cubre esta demarcación y cómo se organiza el servicio."
                v-model="createForm.description"
                data-testid="zone-create-description"
              ></textarea>
            </div>
          </form>
        </div>

        <!-- El atributo form del pie mantiene el Enter del teclado enviando el formulario del cuerpo. -->
        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="closeCreateModal">Cancelar</button>
          <button type="submit" form="zone-create-form" class="vg-btn vg-btn-primary" :disabled="isSubmittingCreate" data-testid="zone-create-submit">
            <span v-if="isSubmittingCreate" class="spinner-border spinner-border-sm me-1" role="status"></span>
            <span>Crear zona</span>
          </button>
        </template>
      </ModalDialog>

      <!-- MODAL: edición de zona -->
      <ModalDialog v-model="showEditModal" :title="'Editar ' + editForm.code" size="md" @close="closeEditModal">
        <div data-testid="zone-edit-modal">
          <div v-if="editErrors.general" class="vg-alert vg-alert-error">{{ editErrors.general }}</div>

          <form id="zone-edit-form" @submit.prevent="submitEdit">
            <div class="mb-3">
              <label class="form-label fw-bold" style="font-size: 13px;">Código de zona</label>
              <input type="text" class="vg-input font-monospace" :value="editForm.code" readonly />
              <small style="font-size: 12px; color: var(--color-ink-muted);">El código es la clave estable de la demarcación.</small>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold" style="font-size: 13px;">Nombre <span class="text-danger">*</span></label>
              <input type="text" class="vg-input" maxlength="100" v-model="editForm.name" data-testid="zone-edit-name" />
              <div v-if="editErrors.name" class="text-danger" style="font-size: 12px;">{{ editErrors.name }}</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold" style="font-size: 13px;">Color corporativo</label>
              <div class="d-flex align-items-center gap-2">
                <input type="color" v-model="editForm.color_hex" data-testid="zone-edit-color" style="width: 48px; height: 32px; border: 1px solid var(--color-hairline); border-radius: var(--radius-interactive);" />
                <span class="font-monospace" style="font-size: 12px; color: var(--color-ink-secondary);">{{ editForm.color_hex }}</span>
              </div>
              <div v-if="editErrors.color_hex" class="text-danger" style="font-size: 12px;">{{ editErrors.color_hex }}</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold" style="font-size: 13px;">Descripción operativa</label>
              <textarea class="vg-textarea" rows="3" v-model="editForm.description"></textarea>
            </div>

            <div class="form-check mb-3">
              <input id="zone-edit-active" class="form-check-input" type="checkbox" v-model="editForm.is_active" data-testid="zone-edit-active" />
              <label class="form-check-label" for="zone-edit-active" style="font-size: 13px;">
                Zona activa
              </label>
              <div style="font-size: 12px; color: var(--color-ink-muted);">
                Desactivarla libera sus códigos postales y su cuadrante; reactivarla no los devuelve.
              </div>
            </div>

          </form>
        </div>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="closeEditModal">Cancelar</button>
          <button type="submit" form="zone-edit-form" class="vg-btn vg-btn-primary" :disabled="isSubmittingEdit" data-testid="zone-edit-submit">
            <span v-if="isSubmittingEdit" class="spinner-border spinner-border-sm me-1" role="status"></span>
            <span>Guardar cambios</span>
          </button>
        </template>
      </ModalDialog>

      <!-- MODAL: confirmación de desactivación con liberación -->
      <ModalDialog
        v-model="showReleaseModal"
        :title="zoneToRelease ? ('Liberar el territorio de ' + zoneToRelease.code) : 'Liberar territorio'"
        size="sm"
        @close="closeReleaseModal"
      >
        <div v-if="zoneToRelease" data-testid="zone-release-modal">
          <div class="vg-alert vg-alert-warning" data-testid="zone-release-summary">
            <p class="mb-1 fw-semibold">Esta operación libera, en una sola transacción:</p>
            <p class="mb-0">{{ releaseSummary }}</p>
          </div>

          <p style="font-size: 13px; color: var(--color-ink-secondary);">
            Las sedes de la zona quedarán «Sin Zona Asignada», los códigos postales quedarán libres para otra demarcación
            y las vinculaciones técnicas se darán de baja conservando su histórico. Reactivar la zona después no
            recupera este territorio.
          </p>
        </div>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="closeReleaseModal">Cancelar</button>
          <button type="button" class="vg-btn vg-btn-danger" :disabled="isSubmittingRelease" @click="confirmRelease" data-testid="zone-release-confirm">
            <span v-if="isSubmittingRelease" class="spinner-border spinner-border-sm me-1" role="status"></span>
            <span>Desactivar y liberar</span>
          </button>
        </template>
      </ModalDialog>

      <!-- MODAL: códigos postales -->
      <ModalDialog
        v-model="showPostalCodesModal"
        :title="postalZone ? ('Códigos postales de ' + postalZone.code) : 'Códigos postales'"
        size="lg"
        @close="closePostalCodesModal"
      >
        <div v-if="postalZone" data-testid="zone-postal-modal">
          <p style="font-size: 13px; color: var(--color-ink-secondary);">
            Las sedes heredan la zona por su código postal. La lista que guarde es la <strong>colección final</strong>:
            los códigos que ya no aparezcan se liberan. Un código que ya pertenece a otra demarcación exige
            confirmación: el traslado mueve también sus sedes.
          </p>

          <label class="form-label fw-bold" style="font-size: 13px;" for="zone-postal-input">
            Códigos vigentes (coma o salto de línea)
          </label>
          <textarea
            id="zone-postal-input"
            class="vg-textarea font-monospace"
            rows="5"
            placeholder="08005, 08006, 08007"
            v-model="postalCodesInput"
            data-testid="zone-postal-input"
          ></textarea>

          <div class="d-flex flex-wrap gap-2 mt-2 mb-3">
            <span class="vg-chip">{{ postalCodesToSubmit.length }} declarados</span>
            <span class="vg-chip vg-chip-success">{{ newPostalCodes.length }} nuevos</span>
            <span class="vg-chip vg-chip-warning" v-if="postalCodesToRelease.length > 0" data-testid="zone-postal-release-chip">
              {{ postalCodesToRelease.length }} a liberar
            </span>
            <span class="vg-chip vg-chip-danger" v-if="postalInputHasInvalidCodes">Hay códigos con formato inválido</span>
          </div>

          <div v-if="postalCodesToRelease.length > 0" class="vg-alert vg-alert-warning" data-testid="zone-postal-releases">
            <p class="mb-2 fw-semibold">Al guardar, estos códigos dejarán de pertenecer a la zona:</p>
            <div class="d-flex flex-wrap gap-2 mb-2">
              <span v-for="code in postalCodesToRelease" :key="code" class="vg-chip vg-chip-danger font-monospace">{{ code }}</span>
            </div>
            <p class="mb-0" style="font-size: 12px;">
              Sus sedes heredadas recalculan su zona; las anuladas a mano conservan la suya.
            </p>
          </div>

          <div v-if="postalErrors.codes" class="vg-alert vg-alert-error" data-testid="zone-postal-error">{{ postalErrors.codes }}</div>

          <!-- Confirmación en bloque: lo que cambia de demarcación, sede por sede. -->
          <div v-if="postalConflicts.length > 0" class="vg-alert vg-alert-warning" data-testid="zone-postal-conflicts">
            <p class="mb-2 fw-semibold">Estos códigos cambiarán de zona junto con sus sedes:</p>
            <ul class="mb-2" style="font-size: 13px;">
              <li v-for="conflict in postalConflicts" :key="conflict.postal_code">
                <span class="font-monospace">{{ conflict.postal_code }}</span>
                — desde «{{ conflict.current_zone_name }}» ·
                {{ conflict.affected_locations_count }} sede(s) heredada(s)
              </li>
            </ul>
            <div class="d-flex gap-2">
              <button type="button" class="vg-btn vg-btn-sm vg-btn-primary" :disabled="isSubmittingPostalCodes" @click="confirmPostalCodesTransfer" data-testid="zone-postal-confirm">
                Confirmar traslado
              </button>
              <button type="button" class="vg-btn vg-btn-sm vg-btn-secondary" @click="cancelPostalCodesTransfer">Revisar</button>
            </div>
          </div>

        </div>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="closePostalCodesModal">Cerrar</button>
          <button
            type="button"
            class="vg-btn vg-btn-primary"
            :disabled="isSubmittingPostalCodes || postalInputHasInvalidCodes"
            @click="submitPostalCodes(false)"
            data-testid="zone-postal-submit"
          >
            <span v-if="isSubmittingPostalCodes" class="spinner-border spinner-border-sm me-1" role="status"></span>
            <span>Guardar códigos</span>
          </button>
        </template>
      </ModalDialog>

      <!-- MODAL: cuadrante técnico -->
      <ModalDialog
        v-model="showTechniciansModal"
        :title="quadrantZone ? ('Cuadrante de ' + quadrantZone.code) : 'Cuadrante técnico'"
        size="lg"
        @close="closeTechniciansModal"
      >
        <div v-if="quadrantZone" data-testid="zone-quadrant-modal">
          <div class="d-flex flex-wrap gap-2 mb-3" data-testid="zone-quadrant-counts">
            <span class="vg-chip">Titulares: {{ quadrantCounts.primary }}</span>
            <span class="vg-chip">Suplentes: {{ quadrantCounts.backup }}</span>
          </div>

          <div v-if="quadrantNotice" class="vg-alert vg-alert-info" data-testid="zone-quadrant-notice">{{ quadrantNotice }}</div>
          <div v-if="quadrantErrors.general" class="vg-alert vg-alert-error">{{ quadrantErrors.general }}</div>

          <!-- Quién cubre la zona, con nombre y rol (RF-02.3 / QA 15). -->
          <div v-if="isLoadingQuadrant" class="text-center py-3" style="color: var(--color-ink-muted);" data-testid="zone-quadrant-loading">
            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
            <span style="font-size: 13px;">Cargando el cuadrante…</span>
          </div>

          <div v-else-if="quadrantTechnicians.length === 0" class="vg-alert vg-alert-info" data-testid="zone-quadrant-empty">
            Esta zona no tiene cuadrante todavía: vincule a un técnico para que el triaje lo recomiende.
          </div>

          <ul v-else class="list-unstyled mb-3" data-testid="zone-quadrant-list">
            <li
              v-for="assignment in quadrantTechnicians"
              :key="assignment.assignment_id"
              class="d-flex flex-wrap align-items-center gap-2 py-2"
              style="border-bottom: 1px solid var(--color-hairline);"
              data-testid="zone-quadrant-row"
            >
              <span v-if="assignment.is_primary" class="vg-chip vg-chip-success" data-testid="zone-quadrant-primary">Titular</span>
              <span v-else class="vg-chip" data-testid="zone-quadrant-backup">Suplente</span>
              <strong style="font-size: 14px;">{{ assignment.name }}</strong>
              <span class="font-monospace" style="font-size: 12px; color: var(--color-ink-secondary);">
                {{ assignment.operator_code || ('ID #' + assignment.technician_id) }}
              </span>
              <span v-if="assignment.is_on_call" class="vg-chip vg-chip-warning">En guardia</span>
              <span v-if="!assignment.is_active" class="vg-chip vg-chip-danger" data-testid="zone-quadrant-inactive">Baja</span>
              <span style="font-size: 12px; color: var(--color-ink-muted);">Alta: {{ formatAssignmentDate(assignment.assigned_at) }}</span>
              <button
                type="button"
                class="vg-btn vg-btn-sm vg-btn-danger-soft ms-auto"
                :disabled="isRemovingTechnicianId !== null"
                @click="removeTechnicianFromQuadrant(assignment.technician_id)"
                data-testid="zone-quadrant-remove"
              >
                <span v-if="isRemovingTechnicianId === assignment.technician_id" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <span>Retirar</span>
              </button>
            </li>
          </ul>

          <form id="zone-quadrant-form" @submit.prevent="submitQuadrantAssignment(false)">
            <div class="mb-3">
              <label class="form-label fw-bold" style="font-size: 13px;" for="zone-quadrant-technician">Técnico</label>
              <select id="zone-quadrant-technician" class="vg-select" v-model="quadrantForm.technician_id" data-testid="zone-quadrant-technician">
                <option value="">Seleccione un técnico activo…</option>
                <option v-for="technician in availableTechnicians" :key="technician.id" :value="technician.id">
                  {{ technician.name }} · {{ technician.operator_code || ('ID #' + technician.id) }}
                </option>
              </select>
              <div v-if="quadrantErrors.technician_id" class="text-danger" style="font-size: 12px;">{{ quadrantErrors.technician_id }}</div>
            </div>

            <div class="form-check mb-3">
              <input id="zone-quadrant-primary" class="form-check-input" type="checkbox" v-model="quadrantForm.is_primary" data-testid="zone-quadrant-primary" />
              <label class="form-check-label" for="zone-quadrant-primary" style="font-size: 13px;">
                Titular principal (solo uno por zona)
              </label>
              <div style="font-size: 12px; color: var(--color-ink-muted);">
                Sin marcar queda como suplente: cubre la zona cuando el titular no está libre.
              </div>
            </div>

            <!-- Degradación del titular anterior: consecuencia explícita, no silenciosa. -->
            <div v-if="primaryConflict" class="vg-alert vg-alert-warning" data-testid="zone-quadrant-conflict">
              <p class="mb-1 fw-semibold">Ese técnico ya es titular de otra zona.</p>
              <p class="mb-2" style="font-size: 13px;">{{ primaryConflict.message }}</p>
              <div class="d-flex gap-2">
                <button type="button" class="vg-btn vg-btn-sm vg-btn-danger" :disabled="isSubmittingQuadrant" @click="confirmPrimaryOverride" data-testid="zone-quadrant-confirm">
                  Sustituir titularidad
                </button>
                <button type="button" class="vg-btn vg-btn-sm vg-btn-secondary" @click="cancelPrimaryOverride">Cancelar</button>
              </div>
            </div>

          </form>

          <p class="mt-3 mb-0" style="font-size: 12px; color: var(--color-ink-muted);">
            La baja de una fila es lógica: la vinculación se extingue con su fecha y el histórico de asignaciones se conserva.
          </p>
        </div>

        <template #footer>
          <button type="button" class="vg-btn vg-btn-secondary" @click="closeTechniciansModal">Cerrar</button>
          <button type="submit" form="zone-quadrant-form" class="vg-btn vg-btn-primary" :disabled="isSubmittingQuadrant" data-testid="zone-quadrant-submit">
            <span v-if="isSubmittingQuadrant" class="spinner-border spinner-border-sm me-1" role="status"></span>
            <span>Vincular al cuadrante</span>
          </button>
        </template>
      </ModalDialog>
    </div>
  `
};

export default OperationalZoneManagementTab;
