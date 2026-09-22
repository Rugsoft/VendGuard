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
     * Starts intervention on an incident (transitions to EN_CURSO) (RF-07 / EARS 7.1).
     * @param {number|string} incidentId
     * @returns {Promise<Object>}
     */
    startIncident: (incidentId) => {
      return this.patch(`/technician/incidents/${incidentId}/start`);
    },

    /**
     * Pauses intervention pending replacement parts (RF-07 / EARS 7.2).
     * @param {number|string} incidentId
     * @param {string} partsNote
     * @returns {Promise<Object>}
     */
    pauseIncident: (incidentId, partsNote) => {
      return this.patch(`/technician/incidents/${incidentId}/pause`, {
        parts_note: partsNote,
        pending_parts_reason: partsNote
      });
    },

    /**
     * Resolves incident with strict validation (>= 20 chars diagnosis & action) (RF-08).
     * @param {number|string} incidentId
     * @param {string} diagnosis
     * @param {string} actionTaken
     * @returns {Promise<Object>}
     */
    resolveIncident: (incidentId, diagnosis, actionTaken) => {
      return this.post(`/technician/incidents/${incidentId}/resolve`, {
        diagnosis,
        action_taken: actionTaken,
        resolution_diagnosis: diagnosis,
        resolution_action: actionTaken
      });
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
}

// Create default singleton instance
export const api = new ApiClient('/api');
export default api;
