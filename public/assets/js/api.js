/**
 * VendGuard - Native HTTP API Client (api.js)
 * 
 * Provides a robust, lightweight wrapper around browser native fetch() API.
 * Adheres to Dogma Vanilla (no axios, no external dependencies).
 * 
 * Key Responsibilities:
 * - Automatically attaches Bearer tokens (auth_token_... or site_token_...) to Authorization header.
 * - Automatically attaches X-Site-Code header when operating under site context.
 * - Auto-detects JSON payloads vs multipart/form-data (file uploads).
 * - Transforms backend envelope errors ({ success: false, error: { code, message, details } }) into ApiError.
 * - Exposes high-level domain methods for all VendGuard REST endpoints.
 */

/**
 * Custom error class representing API failures.
 */
export class ApiError extends Error {
  /**
   * @param {number} status - HTTP status code
   * @param {string} code - Application error code (e.g., 'MACHINE_HAS_ACTIVE_INCIDENT')
   * @param {string} message - Human-readable error message
   * @param {Object|null} [details=null] - Additional validation or business details
   * @param {Object|null} [raw=null] - Raw response payload
   */
  constructor(status, code, message, details = null, raw = null) {
    super(message || `API request failed with status ${status}`);
    this.name = 'ApiError';
    this.status = status;
    this.code = code || 'UNKNOWN_ERROR';
    this.details = details || null;
    this.raw = raw || null;
  }
}

/**
 * Native REST API Client for VendGuard.
 */
export class ApiClient {
  /**
   * @param {string} [baseUrl='/api'] - Base URL for all API calls
   */
  constructor(baseUrl = '/api') {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.token = null;
    this.siteCode = null;
    this.onUnauthorized = null;
  }

  /**
   * Sets the active authentication token.
   * @param {string|null} token
   */
  setToken(token) {
    this.token = token ? String(token).trim() : null;
  }

  /**
   * Gets the active authentication token.
   * @returns {string|null}
   */
  getToken() {
    return this.token;
  }

  /**
   * Sets the active site code (for location portal context).
   * @param {string|null} siteCode
   */
  setSiteCode(siteCode) {
    this.siteCode = siteCode ? String(siteCode).trim() : null;
  }

  /**
   * Gets the active site code.
   * @returns {string|null}
   */
  getSiteCode() {
    return this.siteCode;
  }

  /**
   * Clears all authentication state.
   */
  clearAuth() {
    this.token = null;
    this.siteCode = null;
  }

  /**
   * Registers a callback invoked whenever HTTP 401 Unauthorized occurs.
   * @param {Function|null} callback
   */
  setOnUnauthorized(callback) {
    this.onUnauthorized = typeof callback === 'function' ? callback : null;
  }

  /**
   * Core request dispatcher wrapping fetch().
   * 
   * @param {string} endpoint - Relative path (e.g. '/auth/login') or absolute URL
   * @param {Object} [options={}] - Fetch configuration options
   * @returns {Promise<any>} Response data unpacked from JSON envelope
   * @throws {ApiError}
   */
  async request(endpoint, options = {}) {
    const isAbsolute = /^https?:\/\//i.test(endpoint);
    const cleanEndpoint = endpoint.startsWith('/') ? endpoint : `/${endpoint}`;
    const url = isAbsolute ? endpoint : `${this.baseUrl}${cleanEndpoint}`;

    const headers = { ...(options.headers || {}) };
    const method = (options.method || 'GET').toUpperCase();

    // Attach Bearer token if available and not explicitly provided
    if (this.token && !headers['Authorization']) {
      headers['Authorization'] = `Bearer ${this.token}`;
    }

    // Attach X-Site-Code if available and not explicitly provided
    if (this.siteCode && !headers['X-Site-Code']) {
      headers['X-Site-Code'] = this.siteCode;
    }

    // Prepare body: handle JSON vs FormData
    let body = options.body;
    if (body !== undefined && body !== null) {
      const isFormData = typeof FormData !== 'undefined' && body instanceof FormData;
      if (!isFormData && typeof body === 'object') {
        if (!headers['Content-Type']) {
          headers['Content-Type'] = 'application/json; charset=utf-8';
        }
        body = JSON.stringify(body);
      }
      // If FormData, let browser automatically calculate boundary and Content-Type
    }

    const config = {
      ...options,
      method,
      headers,
      body
    };

    let response;
    try {
      response = await fetch(url, config);
    } catch (networkError) {
      throw new ApiError(
        0,
        'NETWORK_ERROR',
        'Error de conexión con el servidor. Compruebe su conexión a internet.',
        null,
        networkError
      );
    }

    // Handle 204 No Content
    if (response.status === 204) {
      return null;
    }

    // Parse JSON response
    let json = null;
    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
      try {
        json = await response.json();
      } catch (parseError) {
        json = null;
      }
    }

    // Handle HTTP error responses (!2xx)
    if (!response.ok) {
      const errorCode = json?.error?.code || `HTTP_${response.status}`;
      const errorMessage = json?.error?.message || response.statusText || 'Error en la petición';
      const errorDetails = json?.error?.details || null;

      // Notify unauthorized handler on 401
      if (response.status === 401 && this.onUnauthorized) {
        try {
          this.onUnauthorized(errorCode, errorMessage);
        } catch (e) {
          // Prevent listener errors from masking original failure
        }
      }

      throw new ApiError(response.status, errorCode, errorMessage, errorDetails, json);
    }

    // Successful response (2xx): Unpack VendGuard envelope { success: true, data: ... }
    if (json && typeof json === 'object') {
      if ('data' in json) {
        return json.data;
      }
      return json;
    }

    return null;
  }

  // --- Convenience HTTP Verbs ---

  get(endpoint, options = {}) {
    return this.request(endpoint, { ...options, method: 'GET' });
  }

  post(endpoint, body = {}, options = {}) {
    return this.request(endpoint, { ...options, method: 'POST', body });
  }

  put(endpoint, body = {}, options = {}) {
    return this.request(endpoint, { ...options, method: 'PUT', body });
  }

  patch(endpoint, body = {}, options = {}) {
    return this.request(endpoint, { ...options, method: 'PATCH', body });
  }

  delete(endpoint, options = {}) {
    return this.request(endpoint, { ...options, method: 'DELETE' });
  }

  /**
   * Helper for file/form uploads using FormData.
   * @param {string} endpoint
   * @param {FormData} formData
   * @param {Object} [options={}]
   */
  upload(endpoint, formData, options = {}) {
    return this.request(endpoint, {
      ...options,
      method: 'POST',
      body: formData
    });
  }

  /**
   * Triggers an authenticated file download using fetch and an invisible anchor link.
   * @param {string} endpoint
   * @param {string} defaultFilename
   * @param {Object} [params={}]
   */
  async downloadFile(endpoint, defaultFilename = 'export.csv', params = {}) {
    const isAbsolute = /^https?:\/\//i.test(endpoint);
    const cleanEndpoint = endpoint.startsWith('/') ? endpoint : `/${endpoint}`;
    let url = isAbsolute ? endpoint : `${this.baseUrl}${cleanEndpoint}`;

    const queryParams = { ...params };
    const q = new URLSearchParams(queryParams).toString();
    if (q) {
      url += (url.includes('?') ? '&' : '?') + q;
    }

    const headers = {};
    if (this.token) {
      headers['Authorization'] = `Bearer ${this.token}`;
    }

    const response = await fetch(url, { method: 'GET', headers });
    if (!response.ok) {
      let errorMsg = `Error al descargar archivo (${response.status})`;
      try {
        const json = await response.json();
        errorMsg = json?.error?.message || errorMsg;
      } catch (e) {}
      throw new ApiError(response.status, 'DOWNLOAD_ERROR', errorMsg);
    }

    let filename = defaultFilename;
    const disposition = response.headers.get('content-disposition');
    if (disposition && disposition.includes('filename=')) {
      const match = disposition.match(/filename="?([^";]+)"?/i);
      if (match && match[1]) {
        filename = match[1].trim();
      }
    }

    const blob = await response.blob();
    const downloadUrl = (typeof window !== 'undefined' && window.URL) ? window.URL.createObjectURL(blob) : null;
    if (downloadUrl && typeof document !== 'undefined') {
      const a = document.createElement('a');
      a.href = downloadUrl;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(() => window.URL.revokeObjectURL(downloadUrl), 1000);
    }
    return true;
  }

  // =========================================================================
  // HIGH-LEVEL DOMAIN METHODS (VendGuard REST Contracts)
  // =========================================================================

  /**
   * 1. Authentication & Location Access
   */
  auth = {
    /**
     * Authenticates location responsible via site code (RF-01).
     * @param {string} siteCode - e.g. 'SEDE-BCN-01'
     * @returns {Promise<{ token: string, location: Object }>}
     */
    siteLogin: async (siteCode) => {
      const data = await this.post('/auth/site-login', { site_code: siteCode });
      if (data?.token) {
        this.setToken(data.token);
        this.setSiteCode(siteCode);
      }
      return data;
    },

    /**
     * Authenticates internal staff (Coordinator or Technician) (RF-04).
     * @param {string} email
     * @param {string} password
     * @returns {Promise<{ token: string, user: Object }>}
     */
    internalLogin: async (email, password) => {
      const data = await this.post('/auth/login', { email, password });
      if (data?.token) {
        this.setToken(data.token);
      }
      return data;
    }
  };

  /**
   * 2. Location Portal (Informant / Site Responsible)
   */
  locations = {
    /**
     * Retrieves machines catalog for a specific location.
     * @param {string} siteCode
     * @returns {Promise<Array<Object>>}
     */
    getMachines: (siteCode) => {
      const code = siteCode || this.siteCode;
      return this.get(`/locations/${encodeURIComponent(code)}/machines`);
    }
  };  /**
   * 2b. Site Portal: Sanitary Certificates & Refund Desk (RF-PREV-06, RF-PREV-07, RF-REF-06, Art. V.4)
   */
  site = {
    /**
     * Retrieves sanitary status and semaphores for all machines in authenticated location.
     * @returns {Promise<Object>}
     */
    getSanitaryStatus: () => {
      return this.get('/site/sanitary-status');
    },

    /**
     * Retrieves individual official sanitary certificate for a machine.
     * @param {string} machineCode
     * @param {string} [format='json']
     * @returns {Promise<Object|string>}
     */
    getMachineCertificate: (machineCode, format = 'json') => {
      if (format === 'html') {
        return this.get(`/site/certificates/machine/${encodeURIComponent(machineCode)}?format=html`, {
          headers: { 'Accept': 'text/html' }
        });
      }
      return this.get(`/site/certificates/machine/${encodeURIComponent(machineCode)}`);
    },

    /**
     * Retrieves global consolidated sanitary certificate for authenticated location.
     * @param {string} [format='json']
     * @returns {Promise<Object|string>}
     */
    getGlobalCertificate: (format = 'json') => {
      if (format === 'html') {
        return this.get('/site/certificates/global?format=html', {
          headers: { 'Accept': 'text/html' }
        });
      }
      return this.get('/site/certificates/global');
    },

    /**
     * Lists the refund cases held at the authenticated site with anonymized
     * claimant names and no payment instruments (RF-REF-06, RF-REF-10, Art. V.4).
     * @returns {Promise<{total: number, ready_for_pickup_total: number, refunds: Array<Object>}>}
     */
    getRefunds: () => {
      return this.get('/location/refunds');
    },

    /**
     * Releases the cash envelope of a case against the 4-digit pickup PIN typed
     * by the receptionist (RF-REF-06).
     * @param {number|string} refundId
     * @param {string} pickupPin
     * @returns {Promise<Object>}
     */
    deliverRefund: (refundId, pickupPin) => {
      return this.post(`/location/refunds/${encodeURIComponent(refundId)}/deliver`, {
        pickup_pin: String(pickupPin ?? '')
      });
    }
  };

  /**
   * 3. Incidents (Reporting, Comments, Reopening)
   */
  incidents = {
    /**
     * Reports a new machine incident (RF-02, RF-03).
     * Supports both plain JSON object or FormData with attached image.
     * @param {Object|FormData} payload
     * @returns {Promise<Object>}
     */
    create: (payload) => {
      return this.post('/incidents', payload);
    },

    /**
     * Adds a comment or additional photo to an active incident (RF-02 / EARS 2.3).
     * @param {string} ticketCode
     * @param {Object|FormData} payload
     * @returns {Promise<Object>}
     */
    addComment: (ticketCode, payload) => {
      return this.post(`/incidents/${encodeURIComponent(ticketCode)}/comments`, payload);
    },

    /**
     * Reopens an incident within 48h warranty window (RF-09).
     * @param {string} ticketCode
     * @param {string} reason
     * @returns {Promise<Object>}
     */
    reopen: (ticketCode, reason) => {
      return this.post(`/incidents/${encodeURIComponent(ticketCode)}/reopen`, { reason });
    }
  };

  /**
   * 4. Coordinator Operations (Triage, Assignment, Cancellation)
   */
  coordinator = {
    /**
     * Retrieves global incidents list with filtering and SLA tracking (RF-05, RF-11).
     * @param {Object} [filters={}] - { status, urgency, location_id, machine_id, technician_id }
     * @returns {Promise<Array<Object>>}
     */
    getIncidents: (filters = {}) => {
      const params = new URLSearchParams();
      for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== null && value !== '') {
          params.append(key, String(value));
        }
      }
      const qs = params.toString() ? `?${params.toString()}` : '';
      return this.get(`/coordinator/incidents${qs}`);
    },

    /**
     * Assigns incident to a technician, with optional urgency reclassification (RF-05).
     * @param {number|string} incidentId
     * @param {number|string} technicianId
     * @param {string|null} [urgency=null]
     * @param {string|null} [urgencyReason=null]
     * @returns {Promise<Object>}
     */
    assignTechnician: (incidentId, technicianId, urgency = null, urgencyReason = null) => {
      const body = {
        technician_id: Number(technicianId)
      };
      if (urgency) {
        body.urgency = urgency;
        body.urgency_override = urgency;
      }
      if (urgencyReason) {
        body.urgency_reason = urgencyReason;
        body.urgency_override_reason = urgencyReason;
      }
      return this.patch(`/coordinator/incidents/${incidentId}/assign`, body);
    },

    /**
     * Cancels an incident with mandatory reason (Soft Delete) (RF-06).
     * @param {number|string} incidentId
     * @param {string} cancellationReason
     * @returns {Promise<Object>}
     */
    cancelIncident: (incidentId, cancellationReason) => {
      return this.patch(`/coordinator/incidents/${incidentId}/cancel`, {
        cancellation_reason: cancellationReason
      });
    },

    /**
     * Retrieves all active locations with installed machines count (RF-FLEET-02).
     * @returns {Promise<Array<Object>>}
     */
    getLocations: () => {
      return this.get('/coordinator/locations');
    },

    /**
     * Retrieves machines installed at a specific location with operational status (RF-FLEET-03).
     * @param {number|string} locationId
     * @returns {Promise<{ location: Object, machines: Array<Object> }>}
     */
    getLocationMachines: (locationId) => {
      return this.get(`/coordinator/locations/${locationId}/machines`);
    },

    // Mantenimiento Preventivo (Módulo 05: RF-PREV-01, RF-PREV-02, RF-PREV-06)
    getPreventiveDashboard: () => {
      return this.get('/coordinator/preventive/dashboard');
    },
    getPreventiveOrders: (filters = {}) => {
      const params = new URLSearchParams();
      for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== null && value !== '') {
          params.append(key, String(value));
        }
      }
      const qs = params.toString() ? `?${params.toString()}` : '';
      return this.get(`/coordinator/preventive/orders${qs}`);
    },
    createPreventiveOrder: (payload) => {
      return this.post('/coordinator/preventive/orders', payload);
    },
    generateDuePreventiveOrders: (horizonDays = 5) => {
      return this.post('/coordinator/preventive/generate-due', { horizon_days: Number(horizonDays) });
    },
    assignPreventiveOrder: (orderId, technicianId, scheduledDate) => {
      return this.patch(`/coordinator/preventive/orders/${orderId}/assign`, {
        technician_id: Number(technicianId),
        scheduled_date: scheduledDate
      });
    },
    cancelPreventiveOrder: (orderId, reason) => {
      return this.patch(`/coordinator/preventive/orders/${orderId}/cancel`, {
        reason: String(reason)
      });
    },
    getPreventiveSettings: () => {
      return this.get('/coordinator/preventive/settings');
    },
    updatePreventiveSettings: (payload) => {
      return this.patch('/coordinator/preventive/settings', payload);
    },
    getMachinePreventiveConfig: (machineId) => {
      return this.get(`/coordinator/machines/${machineId}/preventive-config`);
    },
    updateMachinePreventiveConfig: (machineId, payload) => {
      return this.patch(`/coordinator/machines/${machineId}/preventive-config`, payload);
    },

    // Máquinas y Personal para Mantenimiento y Operaciones (RF-02, RF-03)
    getMachines: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/machines${q ? `?${q}` : ''}`);
    },
    getUsers: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/users${q ? `?${q}` : ''}`);
    },

    // Gestión de Repuestos (Módulo 06 - RF-REP-01, RF-REP-02, RF-REP-08, RF-REP-09)
    getSparePartsCatalog: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/spare-parts${q ? `?${q}` : ''}`);
    },
    createSparePart: (payload) => {
      return this.post('/coordinator/spare-parts', payload);
    },
    updateSparePart: (id, payload) => {
      return this.put(`/coordinator/spare-parts/${id}`, payload);
    },
    toggleSparePartStatus: (id, isActive) => {
      return this.patch(`/coordinator/spare-parts/${id}/status`, { is_active: isActive });
    },
    getSparePartModels: () => {
      return this.get('/coordinator/spare-parts/models');
    },
    getSparePartsAnalytics: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/spare-parts/analytics${q ? `?${q}` : ''}`);
    },
    downloadSparePartsCsv: (params = {}) => {
      return this.downloadFile('/coordinator/spare-parts/export', 'repuestos_intervenciones.csv', params);
    },
    getSparePartsPendingReview: () => {
      return this.get('/coordinator/spare-parts/requests/pending-review');
    },

    // Gestión de Reintegros e Importe Retenido (Módulo 08 - RF-REF-03, RF-REF-07, RF-REF-08)
    /**
     * Retrieves the global refund inbox with full financial detail and filters.
     * @param {Object} [filters={}] - { status, location_id, machine_id, requires_approval_only, from, to, limit, offset }
     * @returns {Promise<Object>}
     */
    getRefunds: (filters = {}) => {
      const params = new URLSearchParams();
      for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== null && value !== '') {
          params.append(key, String(value));
        }
      }
      const qs = params.toString() ? `?${params.toString()}` : '';
      return this.get(`/coordinator/refunds${qs}`);
    },

    /**
     * Grants the formal double approval of the final payable amount (RF-REF-03).
     * @param {number|string} refundId
     * @param {number} approvedAmount
     * @param {string} [notes='']
     * @returns {Promise<Object>}
     */
    approveRefund: (refundId, approvedAmount, notes = '') => {
      return this.post(`/coordinator/refunds/${encodeURIComponent(refundId)}/approve`, {
        approved_amount: Number(approvedAmount),
        notes: String(notes ?? '')
      });
    },

    /**
     * Registers the digital settlement together with its bank/payment reference (RF-REF-07).
     * @param {number|string} refundId
     * @param {string} paymentReference
     * @param {number|null} [paidAmount=null] Defaults server-side to the approved amount.
     * @returns {Promise<Object>}
     */
    payRefund: (refundId, paymentReference, paidAmount = null) => {
      const body = { payment_reference: String(paymentReference ?? '') };
      if (paidAmount !== null && paidAmount !== undefined && paidAmount !== '') {
        body.paid_amount = Number(paidAmount);
      }
      return this.post(`/coordinator/refunds/${encodeURIComponent(refundId)}/pay`, body);
    },

    /**
     * Rejects a claim with a mandatory written justification of at least 20 characters (RF-REF-08).
     * @param {number|string} refundId
     * @param {string} rejectionReason
     * @returns {Promise<Object>}
     */
    rejectRefund: (refundId, rejectionReason) => {
      return this.post(`/coordinator/refunds/${encodeURIComponent(refundId)}/reject`, {
        rejection_reason: String(rejectionReason ?? '')
      });
    },

    /**
     * Regularizes a case stranded in PENDING_INSPECTION by filing its balance
     * verdict from Coordination, when the incident was cancelled or the
     * technician can no longer reach the machine (RF-REF-04, RF-REF-09).
     * @param {number|string} refundId
     * @param {{finding: string, recoveredAmount?: number|null, cashCustodyAction?: string|null, receptionistName?: string, justification?: string}} payload
     * @returns {Promise<Object>}
     */
    regularizeRefund: (refundId, payload = {}) => {
      const body = {
        finding: String(payload.finding ?? '')
      };

      if (payload.recoveredAmount !== undefined && payload.recoveredAmount !== null && payload.recoveredAmount !== '') {
        body.recovered_amount = Number(payload.recoveredAmount);
      }

      if (payload.cashCustodyAction) {
        body.cash_custody_action = String(payload.cashCustodyAction);
      }

      if (payload.receptionistName) {
        body.receptionist_name = String(payload.receptionistName);
      }

      if (payload.justification) {
        body.justification = String(payload.justification);
      }

      return this.post(`/coordinator/refunds/${encodeURIComponent(refundId)}/regularize`, body);
    }
  };

  /**
   * 5. Field Technician Operations (Mobile Route, Intervention, Resolution)
   */
  technician = {
    /**
     * Retrieves the technician's assigned route (RF-07).
     * @returns {Promise<Array<Object>>}
     */
    getMyRoute: () => {
      return this.get('/technician/my-route');
    },

    /**
     * Retrieves privacy-safe refund claims for a route incident (T-REF-16, Art. V.4).
     * @param {number|string} incidentId
     * @returns {Promise<Object>}
     */
    getRefundInspection: (incidentId) => {
      return this.get(`/technician/incidents/${encodeURIComponent(incidentId)}/refund`);
    },

    /**
     * Retrieves the optimized route map with consolidated stops and navigation URLs (RF-MAP-05, RF-MAP-07).
     * @param {Object} [origin={}] Optional { origin_lat, origin_lng } device GPS coordinates.
     * @returns {Promise<Object>}
     */
    getRouteMap: (origin = {}) => {
      const pairs = Object.entries(origin).filter(([, value]) => value !== undefined && value !== null && value !== '');
      const q = new URLSearchParams(pairs).toString();
      return this.get(`/technician/route/map${q ? `?${q}` : ''}`);
    },

    /**
     * Starts intervention on an incident (transitions to EN_CURSO) (RF-07 / EARS 7.1).
     * @param {number|string} incidentId
     * @returns {Promise<Object>}
     */
    startIncident: (incidentId) => {
      return this.patch(`/technician/incidents/${incidentId}/start`);
    },

    /**
     * Pauses intervention pending replacement parts (RF-07, RF-REP-03, RF-REP-04).
     * @param {number|string} incidentId
     * @param {string|Object} payloadOrNote
     * @returns {Promise<Object>}
     */
    pauseIncident: (incidentId, payloadOrNote) => {
      if (typeof payloadOrNote === 'object' && payloadOrNote !== null) {
        return this.patch(`/technician/incidents/${incidentId}/pause`, payloadOrNote);
      }
      return this.patch(`/technician/incidents/${incidentId}/pause`, {
        parts_note: payloadOrNote,
        pending_parts_reason: payloadOrNote
      });
    },

    /**
     * Resolves incident with strict validation and optional spare parts (RF-08, RF-REP-05, RF-REP-06).
     * @param {number|string} incidentId
     * @param {string|Object} payloadOrDiagnosis
     * @param {string|null} [actionTaken=null]
     * @returns {Promise<Object>}
     */
    resolveIncident: (incidentId, payloadOrDiagnosis, actionTaken = null) => {
      if (typeof payloadOrDiagnosis === 'object' && payloadOrDiagnosis !== null) {
        return this.post(`/technician/incidents/${incidentId}/resolve`, payloadOrDiagnosis);
      }
      return this.post(`/technician/incidents/${incidentId}/resolve`, {
        diagnosis: payloadOrDiagnosis,
        action_taken: actionTaken,
        resolution_diagnosis: payloadOrDiagnosis,
        resolution_action: actionTaken
      });
    },

    // Mantenimiento Preventivo y Checklists Sanitarios (Módulo 05: RF-PREV-02, RF-PREV-03, RF-PREV-04, RF-PREV-08)
    getPreventiveRoute: (locationId = null) => {
      const q = locationId ? `?location_id=${Number(locationId)}` : '';
      return this.get(`/technician/preventive/route${q}`);
    },
    claimPreventiveOrder: (orderId) => {
      return this.post(`/technician/preventive/orders/${orderId}/claim`);
    },
    getPreventiveChecklist: (orderId) => {
      return this.get(`/technician/preventive/orders/${orderId}/checklist`);
    },
    startPreventiveInspection: (orderId) => {
      return this.post(`/technician/preventive/orders/${orderId}/start`);
    },
    completePreventiveInspection: (orderId, payload) => {
      return this.post(`/technician/preventive/orders/${orderId}/complete`, payload);
    },
    reinspectPreventiveOrder: (orderId, payload) => {
      return this.post(`/technician/preventive/orders/${orderId}/reinspect`, payload);
    },

    // Gestión de Repuestos en Movilidad (Módulo 06: RF-REP-03, RF-REP-04)
    getSparePartsCatalog: (machineId, incidentId = null) => {
      const params = { machine_id: machineId };
      if (incidentId) params.incident_id = incidentId;
      const q = new URLSearchParams(params).toString();
      return this.get(`/technician/spare-parts/catalog?${q}`);
    }
  };

  /**
   * 6. Cron / Automated Tasks
   */
  cron = {
    /**
     * Triggers batch auto-close of resolved incidents older than 48h (RF-10).
     * @param {string} cronSecret
     * @returns {Promise<Object>}
     */
    autoClose: (cronSecret) => {
      return this.post(
        '/cron/auto-close',
        {},
        {
          headers: {
            'X-Cron-Token': cronSecret
          }
        }
      );
    }
  };

  /**
   * 7. Public consumer refund tracking (RF-REF-02, RF-REF-07).
   * The secure tracking token is the only credential; no session is required.
   */
  publicRefunds = {
    /**
     * Retrieves the safe public tracking projection for one refund case.
     * @param {string} trackingToken
     * @returns {Promise<Object>}
     */
    track: (trackingToken) => {
      const token = encodeURIComponent(String(trackingToken ?? '').trim());
      return this.get(`/public/refunds/track?token=${token}`);
    },

    /**
     * Corrects Bizum or bank transfer details while the case awaits contact.
     * @param {string} trackingToken
     * @param {{bizum_phone?: string, iban?: string}} payload
     * @returns {Promise<Object>}
     */
    rectify: (trackingToken, payload) => {
      const token = encodeURIComponent(String(trackingToken ?? '').trim());
      return this.patch(`/public/refunds/track?token=${token}`, payload);
    }
  };

  /**
   * 8. QR Code Workflows (RF-01 to RF-05)
   */
  qr = {
    /**
     * Resolves machine status from QR scan (RF-03, RF-04, RF-05).
     * @param {string} code
     * @param {string|null} [site=null]
     * @returns {Promise<Object>}
     */
    scan: (code, site = null) => {
      const params = site ? `?site=${encodeURIComponent(site)}` : '';
      return this.get(`/qr/scan/${encodeURIComponent(code)}${params}`);
    },

    /**
     * Submits a public incident report via QR scan (RF-03, RF-04).
     * @param {Object|FormData} payload
     * @returns {Promise<Object>}
     */
    report: (payload) => {
      return this.post('/qr/report', payload);
    },

    /**
     * Retrieves QR label preview/data for coordinator (RF-01).
     * @param {number|string} machineId
     * @param {Object} [options={}]
     * @returns {Promise<Object>}
     */
    getMachineLabel: (machineId, options = {}) => {
      const q = new URLSearchParams(options).toString();
      return this.get(`/coordinator/machines/${machineId}/qr-label${q ? `?${q}` : ''}`);
    },

    /**
     * Retrieves batch QR labels for location (RF-02).
     * @param {number|string} locationId
     * @returns {Promise<Object>}
     */
    getLocationBatch: (locationId) => {
      return this.get(`/coordinator/locations/${locationId}/qr-batch`);
    }
  };

  map = {
    /**
     * Retrieves all sites with active incidents/preventives for the coordinator territorial triage map (RF-MAP-09).
     * @param {Object} [params={}] Optional { technician_id, unassigned_only, is_critical_only } filters.
     * @returns {Promise<Object>}
     */
    getActiveIncidents: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/map/active-incidents${q ? `?${q}` : ''}`);
    }
  };

  metrics = {
    /**
     * Retrieves KPI summary and SLA alerts (RF-01, RF-03).
     * @param {Object} [params={}]
     * @returns {Promise<Object>}
     */
    getSummary: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/metrics/summary${q ? `?${q}` : ''}`);
    },

    /**
     * Retrieves multidimensional metrics breakdown (RF-02).
     * @param {Object} [params={}]
     * @returns {Promise<Object>}
     */
    getBreakdown: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/metrics/breakdown${q ? `?${q}` : ''}`);
    },

    /**
     * URL for downloading metrics CSV export (RF-06).
     * @param {Object} [params={}]
     * @returns {string}
     */
    exportCsvUrl: (params = {}) => {
      const allParams = { ...params };
      if (this.token) {
        allParams.token = this.token;
      }
      const q = new URLSearchParams(allParams).toString();
      return `${this.baseUrl}/coordinator/metrics/export${q ? `?${q}` : ''}`;
    },

    /**
     * Downloads metrics CSV export using authenticated fetch and saves file (RF-06).
     * @param {Object} [params={}]
     * @returns {Promise<boolean>}
     */
    downloadCsv: (params = {}) => {
      return this.downloadFile('/coordinator/metrics/export', 'vendguard_metrics.csv', params);
    },

    /**
     * Retrieves personal metrics for authenticated technician (RF-04).
     * @param {Object} [params={}]
     * @returns {Promise<Object>}
     */
    getMyMetrics: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/technician/my-metrics${q ? `?${q}` : ''}`);
    }
  };

  auditLog = {
    /**
     * Retrieves paginated immutable audit log events (RF-05, EARS 5.5).
     * @param {Object} [params={}]
     * @returns {Promise<Object>}
     */
    getEvents: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/audit-log${q ? `?${q}` : ''}`);
    },

    /**
     * URL for downloading audit log CSV export (RF-06, EARS 6.2).
     * @param {Object} [params={}]
     * @returns {string}
     */
    exportCsvUrl: (params = {}) => {
      const allParams = { ...params };
      if (this.token) {
        allParams.token = this.token;
      }
      const q = new URLSearchParams(allParams).toString();
      return `${this.baseUrl}/coordinator/audit-log/export${q ? `?${q}` : ''}`;
    },

    /**
     * Downloads audit log CSV export using authenticated fetch and saves file (RF-06).
     * @param {Object} [params={}]
     * @returns {Promise<boolean>}
     */
    downloadCsv: (params = {}) => {
      return this.downloadFile('/coordinator/audit-log/export', 'vendguard_audit_log.csv', params);
    }
  };

  /**
   * 9. Administration Operations (Locations, Machines, Users) - Module 04 (RF-01 to RF-05)
   */
  admin = {
    // Locations (RF-01)
    getLocations: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/locations${q ? `?${q}` : ''}`);
    },
    createLocation: (payload) => {
      return this.post('/coordinator/locations', payload);
    },
    getLocation: (id) => {
      return this.get(`/coordinator/locations/${id}`);
    },
    updateLocation: (id, payload) => {
      return this.put(`/coordinator/locations/${id}`, payload);
    },
    deactivateLocation: (id) => {
      return this.patch(`/coordinator/locations/${id}/deactivate`);
    },
    reactivateLocation: (id) => {
      return this.patch(`/coordinator/locations/${id}/reactivate`);
    },

    // Machines (RF-02)
    getMachines: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/machines${q ? `?${q}` : ''}`);
    },
    createMachine: (payload) => {
      return this.post('/coordinator/machines', payload);
    },
    getMachine: (id) => {
      return this.get(`/coordinator/machines/${id}`);
    },
    updateMachine: (id, payload) => {
      return this.patch(`/coordinator/machines/${id}`, payload);
    },
    transferMachine: (id, payload) => {
      return this.patch(`/coordinator/machines/${id}/transfer`, payload);
    },
    deactivateMachine: (id) => {
      return this.patch(`/coordinator/machines/${id}/deactivate`);
    },
    reactivateMachine: (id, payload = {}) => {
      return this.patch(`/coordinator/machines/${id}/reactivate`, payload);
    },

    // Users (RF-03)
    getUsers: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/users${q ? `?${q}` : ''}`);
    },
    createUser: (payload) => {
      return this.post('/coordinator/users', payload);
    },
    getUser: (id) => {
      return this.get(`/coordinator/users/${id}`);
    },
    updateUser: (id, payload) => {
      return this.patch(`/coordinator/users/${id}`, payload);
    },
    resetUserPassword: (id, newPassword) => {
      return this.patch(`/coordinator/users/${id}/reset-password`, { new_password: newPassword });
    },
    deactivateUser: (id) => {
      return this.patch(`/coordinator/users/${id}/deactivate`);
    },
    reactivateUser: (id) => {
      return this.patch(`/coordinator/users/${id}/reactivate`);
    }
  };
}

// Create default singleton instance
export const api = new ApiClient('/api');
export default api;
