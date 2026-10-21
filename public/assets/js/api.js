/**
 * VendGuard - Native HTTP API Client (api.js)
 * 
 * Provides a robust, lightweight wrapper around browser native fetch() API.
 * Adheres to Dogma Vanilla (no axios, no external dependencies).
 * 
 * Key Responsibilities:
 * - Automatically attaches Bearer tokens (auth_token_... or site_token_...) to Authorization header.
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
 * Tiempo de espera ordinario estricto de toda petición HTTP contra el backend (EARS-04.1).
 *
 * Cinco segundos en el cliente **y** cinco segundos en el tiempo de conexión del driver PDO
 * (`ATTR_TIMEOUT`): la coherencia temporal es extremo a extremo a propósito. Un cliente más
 * paciente que el servidor sólo consigue que el operario espere por una respuesta que ya no
 * va a llegar, y un cliente más impaciente corta operaciones que el motor sí iba a completar.
 */
export const REQUEST_TIMEOUT_MS = 5000;

/**
 * Evento nativo con el que el transporte publica el estado de la conexión (RF-04.3, RF-04.4).
 *
 * El consumidor canónico es `components/ConnectionStateBanner.js`, que reconoce los estados
 * `idle`, `disconnected` y `reconnected`. Un estado que el banner no conozca lo deja donde
 * estaba: aquí se emite lo que ha pasado de verdad, no lo que al banner le convenga.
 */
export const NETWORK_STATUS_EVENT = 'vendguard:network-status';

/**
 * Evento nativo con el que el transporte publica si tiene operaciones en curso (RF-04.2).
 *
 * Sirve a las superficies que quieran reflejar el trabajo pendiente (y a cualquier botón que
 * deba apagarse mientras hay un envío vivo). Es un canal **aparte** del de conexión porque
 * `idle` ya significa «no hay nada que contar» en el banner, y completar una petición con
 * éxito no es lo mismo que recuperar la conexión.
 */
export const REQUEST_STATE_EVENT = 'vendguard:request-state';

/**
 * Construye la señal de aborto del tiempo de espera ordinario (EARS-04.1).
 *
 * Se prefiere el reloj del navegador (`AbortSignal.timeout`), que es el mecanismo nativo y no
 * necesita limpieza. Donde no existe se levanta un `AbortController` con su temporizador, y el
 * `cancel()` devuelto permite apagarlo cuando la respuesta llega antes: un temporizador vivo
 * sobre una petición ya resuelta es una fuga silenciosa.
 *
 * El tiempo es un parámetro (y no la constante a pelo) para que la certificación pueda medir el
 * mecanismo con un reloj corto en lugar de esperar cinco segundos reales por caso.
 *
 * @param {number} [timeoutMs=REQUEST_TIMEOUT_MS] Tiempo de espera en milisegundos.
 * @returns {{signal: (AbortSignal|undefined), hasTimedOut: (function(): boolean), cancel: (function(): void)}}
 */
export function buildTimeoutSignal(timeoutMs = REQUEST_TIMEOUT_MS) {
  if (typeof AbortSignal !== 'undefined' && typeof AbortSignal.timeout === 'function') {
    return {
      signal: AbortSignal.timeout(timeoutMs),
      hasTimedOut: () => false,
      cancel: () => {}
    };
  }

  if (typeof AbortController !== 'undefined') {
    const controller = new AbortController();
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, timeoutMs);

    return {
      signal: controller.signal,
      hasTimedOut: () => timedOut,
      cancel: () => clearTimeout(timer)
    };
  }

  return { signal: undefined, hasTimedOut: () => false, cancel: () => {} };
}

/**
 * Combina la señal del llamante con el reloj ordinario de la casa (EARS-04.1).
 *
 * Que una pantalla quiera poder cancelar su petición **no puede desactivar el techo de 5,0
 * segundos**: el contrato es que toda petición al backend tiene plazo, y una señal ajena que
 * sustituyese al reloj abriría un agujero silencioso por el que una consulta colgada se queda
 * esperando indefinidamente. Aquí se encadenan las dos: la primera que salte decide, y el motivo
 * de cada una llega intacto —`AbortError` si canceló quien pidió, `TimeoutError` si venció el
 * plazo— porque de ese nombre depende que el sistema sepa si debe avisar al operario.
 *
 * @param {(AbortSignal|undefined)} callerSignal Señal del llamante, si la trajo.
 * @param {{signal: (AbortSignal|undefined), hasTimedOut: Function, cancel: Function}} timeout Reloj de la casa.
 * @returns {{signal: (AbortSignal|undefined), hasTimedOut: Function, cancel: Function}}
 */
export function combineWithTimeoutSignal(callerSignal, timeout) {
  const timeoutSignal = timeout?.signal;
  const timedOut = () => (typeof timeout?.hasTimedOut === 'function' ? timeout.hasTimedOut() === true : false);

  if (!callerSignal || typeof AbortController === 'undefined') {
    return {
      signal: callerSignal ?? timeoutSignal,
      hasTimedOut: timedOut,
      cancel: () => timeout?.cancel?.()
    };
  }

  const controller = new AbortController();
  const abortFromCaller = () => controller.abort(callerSignal.reason);
  const abortFromTimeout = () => controller.abort(
    typeof DOMException !== 'undefined'
      ? new DOMException('The operation was aborted due to timeout', 'TimeoutError')
      : undefined
  );

  if (callerSignal.aborted) {
    abortFromCaller();
  } else {
    callerSignal.addEventListener('abort', abortFromCaller, { once: true });
  }

  if (timeoutSignal) {
    if (timeoutSignal.aborted) {
      abortFromTimeout();
    } else {
      timeoutSignal.addEventListener('abort', abortFromTimeout, { once: true });
    }
  }

  return {
    signal: controller.signal,
    hasTimedOut: timedOut,
    cancel: () => {
      callerSignal.removeEventListener?.('abort', abortFromCaller);
      timeoutSignal?.removeEventListener?.('abort', abortFromTimeout);
      timeout?.cancel?.();
    }
  };
}

/**
 * Bloque de mensajes que carga cada petición del hilo: los 50 más recientes del
 * expediente (Módulo 10, RF-01.2 de specs/10-incident-comments/spec.md).
 */
const COMMENT_THREAD_PAGE_SIZE = 50;

/**
 * Construye la cadena de consulta de la paginación cursorizada del hilo a partir de
 * los parámetros normalizados `{ limit, beforeId }` (Módulo 10, RF-01.2, RF-01.3).
 *
 * El backend espera `limit` y `before_id`; `before_id` se omite cuando no se solicita
 * un bloque anterior, de modo que el servidor devuelve siempre el tramo más reciente.
 *
 * @param {{limit?: number|string, beforeId?: number|string|null}} [params={}]
 * @returns {string} Cadena de consulta (`?...`) lista para concatenar, o cadena vacía.
 */
function buildCommentQuery(params = {}) {
  const query = new URLSearchParams();
  const limit = params.limit ?? COMMENT_THREAD_PAGE_SIZE;

  if (limit !== undefined && limit !== null && limit !== '') {
    query.set('limit', String(limit));
  }

  if (params.beforeId !== undefined && params.beforeId !== null && params.beforeId !== '') {
    query.set('before_id', String(params.beforeId));
  }

  const qs = query.toString();
  return qs ? `?${qs}` : '';
}

/**
 * Normaliza el cuerpo de publicación de un mensaje al contrato REST del hilo
 * (Módulo 10, RF-03, RF-04.1). Acepta indistintamente:
 * - `FormData`: envío atómico multipart con `comment_text`, `is_internal` y `photo`.
 * - Objeto plano JSON: `{ comment_text, is_internal? }`.
 * - Texto plano + bandera opcional: atajo retrospectivo previo a este módulo que se
 *   traduce a `{ comment_text, is_internal }` para no romper llamadas existentes.
 *
 * @param {FormData|Object|string} formDataOrJson
 * @param {boolean|undefined} [legacyIsInternal]
 * @returns {Object|FormData} Cuerpo listo para `post()`.
 */
function normalizeCommentBody(formDataOrJson, legacyIsInternal = undefined) {
  if (typeof FormData !== 'undefined' && formDataOrJson instanceof FormData) {
    return formDataOrJson;
  }

  if (typeof formDataOrJson === 'string') {
    const payload = { comment_text: formDataOrJson };
    if (typeof legacyIsInternal === 'boolean') {
      payload.is_internal = legacyIsInternal;
    }
    return payload;
  }

  if (formDataOrJson && typeof formDataOrJson === 'object') {
    return formDataOrJson;
  }

  return {};
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
    /**
     * Operaciones de escritura vivas, por clave de duplicado (RF-04.2).
     *
     * Un doble clic accidental —o un formulario que se reenvía porque el operario no vio
     * ninguna señal— no puede convertirse en dos altas. La segunda entrega idéntica mientras
     * la primera sigue en el aire **recibe la respuesta de la primera** en lugar de salir a la
     * red otra vez: es la forma del transporte de impedir la petición duplicada.
     *
     * @type {Map<string, Promise<any>>}
     */
    this.pendingMutations = new Map();
    /** @type {number} Peticiones en curso (lecturas incluidas), para quien quiera reflejarlas. */
    this.pendingRequests = 0;
    /**
     * ¿La conexión está caída según este cliente?
     *
     * Se levanta al publicar `disconnected` y se baja con el **primer éxito posterior**, que es
     * la única prueba honesta de que la conexión ha vuelto: nadie firma la recuperación sin
     * haber recibido una respuesta.
     */
    this.networkIsDown = false;
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

    // EARS-04.1 — Tiempo de espera ordinario estricto en TODA petición. Si el llamante trae su
    // propia señal se encadenan las dos: quien cancela sigue pudiendo cancelar y el plazo de
    // 5,0 s sigue mordiendo. El motivo de cada una viaja distinto para que el sistema sepa si
    // tiene que avisar al operario (avería) o callarse (cancelación deliberada).
    const timeout = buildTimeoutSignal();
    const linkedSignal = combineWithTimeoutSignal(options.signal, timeout);
    config.signal = linkedSignal.signal;

    // EARS-04.2 — Una segunda entrega idéntica mientras la primera sigue viva no sale a la red:
    // recibe la respuesta de la primera. Sin esto, un doble clic accidental son dos altas.
    const duplicateKey = this.duplicateRequestKey(method, url, config.body);
    const inFlight = duplicateKey === null ? undefined : this.pendingMutations.get(duplicateKey);
    if (inFlight !== undefined) {
      return inFlight;
    }

    const operation = this.dispatchRequest({
      endpoint,
      url,
      config,
      callerOptions: options,
      callerSignal: options.signal,
      hasTimedOut: linkedSignal.hasTimedOut,
      cancelTimeout: linkedSignal.cancel
    });

    if (duplicateKey !== null) {
      this.pendingMutations.set(duplicateKey, operation);
      // La limpieza no puede dejar una promesa rechazada sin dueño: el `catch` explícito se
      // queda con el fallo (que el llamante ya está viendo) antes de retirar la clave.
      operation.catch(() => {}).then(() => this.pendingMutations.delete(duplicateKey));
    }

    return operation;
  }

  /**
   * Ejecuta la petición ya construida y traduce sus desenlaces (EARS-04.1 a EARS-04.4).
   *
   * Vive separada de `request()` porque éste tiene que poder **registrar** la promesa antes de
   * que nadie la espere: un guardián de duplicados que se instala después del `await` llega
   * tarde justo en el caso que existe para cubrir.
   *
   * @param {{endpoint: string, url: string, config: Object, callerOptions: Object,
   *   callerSignal: (AbortSignal|undefined), hasTimedOut: Function, cancelTimeout: Function}} transport
   * @returns {Promise<any>}
   */
  async dispatchRequest(transport) {
    const { endpoint, url, config, callerOptions, callerSignal, hasTimedOut, cancelTimeout } = transport;

    this.pendingRequests++;
    this.announceRequestState();

    let response;
    try {
      response = await fetch(url, config);
    } catch (networkError) {
      const failure = this.describeTransportFailure(networkError, { hasTimedOut, callerSignal });

      // Una cancelación deliberada no es una avería de red: quien canceló ya lo sabe, y anunciar
      // una desconexión global por su decisión sería mentir al resto de la aplicación.
      if (failure.announce) {
        this.networkIsDown = true;
        this.announceNetworkStatus('disconnected', {
          message: failure.error.message,
          retry: () => this.request(endpoint, callerOptions)
        });
      }

      throw failure.error;
    } finally {
      cancelTimeout();
      this.pendingRequests = Math.max(0, this.pendingRequests - 1);
      this.announceRequestState();
    }

    // EARS-04.3 — El primer éxito después de una caída es la prueba de que la conexión volvió:
    // aquí es el transporte quien firma la recuperación, no un temporizador que la suponga.
    if (this.networkIsDown) {
      this.networkIsDown = false;
      this.announceNetworkStatus('reconnected');
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

  // --- Resiliencia de red (RF-04, EARS-04.1 a EARS-04.4) ---

  /**
   * Clave de duplicado de una operación de escritura, o `null` si no se vigila.
   *
   * Las lecturas no se vigilan nunca: dos consultas iguales simultáneas son legítimas y
   * compartir su respuesta sería ahorrar por la vía de mentir. Las escrituras sí, porque ahí el
   * segundo envío idéntico es, por definición, el mismo hecho dos veces.
   *
   * **Las subidas de archivo quedan fuera a propósito.** Su cuerpo es un `FormData`, y dos
   * ficheros distintos viajan al mismo endpoint: fusionarlos entregaría al primero la respuesta
   * del segundo y descartaría una evidencia en silencio, que es peor que una petición de más.
   *
   * @param {string} method
   * @param {string} url
   * @param {*} body Cuerpo ya serializado (o sin serializar si no es JSON).
   * @returns {string|null}
   */
  duplicateRequestKey(method, url, body) {
    if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') {
      return null;
    }
    if (body === undefined || body === null) {
      return `${method} ${url}`;
    }
    if (typeof body === 'string') {
      return `${method} ${url} ${body}`;
    }
    return null;
  }

  /**
   * Traduce un fallo de transporte a la excepción de la aplicación y decide si es una avería
   * que deba anunciarse (EARS-04.1, EARS-04.3).
   *
   * Los tres desenlaces que de verdad existen son distintos y no se pueden mezclar: una
   * **cancelación deliberada** (el llamante abortó: no se anuncia nada a nadie), un **tiempo de
   * espera agotado** (el servidor no llegó a responder en el plazo estricto) y una **caída de
   * red** (la petición no encontró a nadie). Los dos últimos sí se anuncian, porque el operario
   * tiene que saber que lo suyo no se ha perdido.
   *
   * @param {*} networkError Error lanzado por `fetch`.
   * @param {{hasTimedOut?: Function, callerSignal?: AbortSignal}} [transport={}]
   * @returns {{announce: boolean, error: ApiError}}
   */
  describeTransportFailure(networkError, transport = {}) {
    const timedOut = (typeof transport.hasTimedOut === 'function' && transport.hasTimedOut() === true)
      || networkError?.name === 'TimeoutError';
    const callerAborted = networkError?.name === 'AbortError'
      && transport.callerSignal?.aborted === true;

    if (callerAborted) {
      return {
        announce: false,
        error: new ApiError(
          0,
          'REQUEST_ABORTED',
          'La petición se canceló antes de completarse.',
          null,
          networkError
        )
      };
    }

    const seconds = (REQUEST_TIMEOUT_MS / 1000).toFixed(1).replace('.', ',');
    const message = timedOut
      ? `El servidor ha tardado más de ${seconds} segundos en responder. Lo que has escrito sigue en el formulario: reintenta cuando recuperes la conexión.`
      : 'Error de conexión con el servidor. Lo que has escrito sigue en el formulario: comprueba tu conexión y reintenta.';

    return {
      announce: true,
      error: new ApiError(
        0,
        timedOut ? 'REQUEST_TIMEOUT' : 'NETWORK_ERROR',
        message,
        null,
        networkError
      )
    };
  }

  /**
   * Publica un evento nativo en la ventana, si hay ventana que lo reciba.
   *
   * El transporte tiene que poder correr también fuera del navegador (las suites de Node
   * importan este módulo), así que la ausencia de ventana no es un error: simplemente nadie
   * escucha.
   *
   * @param {string} eventName
   * @param {Object} detail
   * @returns {void}
   */
  announce(eventName, detail) {
    if (typeof window === 'undefined' || typeof window.dispatchEvent !== 'function') {
      return;
    }
    if (typeof CustomEvent === 'undefined') {
      return;
    }
    window.dispatchEvent(new CustomEvent(eventName, { detail }));
  }

  /**
   * Anuncia el estado de la conexión al banner global (RF-04.3, RF-04.4).
   *
   * @param {string} status `disconnected` o `reconnected`.
   * @param {{message?: string, retry?: Function}} [detail={}]
   * @returns {void}
   */
  announceNetworkStatus(status, detail = {}) {
    this.announce(NETWORK_STATUS_EVENT, { status, ...detail });
  }

  /**
   * Anuncia cuántas operaciones hay en curso (RF-04.2).
   *
   * @returns {void}
   */
  announceRequestState() {
    this.announce(REQUEST_STATE_EVENT, {
      pending: this.pendingRequests,
      isBusy: this.pendingRequests > 0
    });
  }

  /**
   * Peticiones en curso ahora mismo.
   *
   * @returns {number}
   */
  getPendingRequestCount() {
    return this.pendingRequests;
  }

  /**
   * ¿Hay alguna operación viva?
   *
   * Es la señal con la que una superficie puede apagar sus envíos mientras otro está en el aire
   * y evitar así la petición duplicada accidental (EARS-04.2).
   *
   * @returns {boolean}
   */
  isBusy() {
    return this.pendingRequests > 0;
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

    // EARS-04.1 — La descarga es una operación de red como cualquier otra: el mismo reloj
    // estricto, o una exportación colgada deja al coordinador esperando sin saberlo.
    const timeout = buildTimeoutSignal();

    let response;
    try {
      response = await fetch(url, { method: 'GET', headers, signal: timeout.signal });
    } catch (networkError) {
      timeout.cancel();
      const failure = this.describeTransportFailure(networkError, { hasTimedOut: timeout.hasTimedOut });

      if (failure.announce) {
        this.networkIsDown = true;
        this.announceNetworkStatus('disconnected', {
          message: failure.error.message,
          retry: () => this.downloadFile(endpoint, defaultFilename, params)
        });
      }

      throw failure.error;
    }

    timeout.cancel();

    if (!response.ok) {
      let errorMsg = `Error al descargar archivo (${response.status})`;
      try {
        const json = await response.json();
        errorMsg = json?.error?.message || errorMsg;
      } catch (e) {}
      throw new ApiError(response.status, 'DOWNLOAD_ERROR', errorMsg);
    }

    // La descarga también es una prueba de que la conexión volvió (EARS-04.3).
    if (this.networkIsDown) {
      this.networkIsDown = false;
      this.announceNetworkStatus('reconnected');
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
     * Authenticates location responsible with the two site credentials (RF-01).
     * The site code identifies the centre; the access code — issued at the site's
     * creation and handed over in person — is the credential that opens the door.
     * @param {string} siteCode - e.g. 'SEDE-BCN-01'
     * @param {string} accessCode - e.g. 'K7M4P-2QX9R'
     * @returns {Promise<{ token: string, location: Object }>}
     */
    siteLogin: async (siteCode, accessCode) => {
      const data = await this.post('/auth/site-login', { site_code: siteCode, access_code: accessCode });
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
   * 3a. Notification Center: Outbox & Corporate Emergency Channel (Módulo 14).
   *
   * Superficie de Coordinación para supervisar la bandeja de salida garantizada
   * (RF-06.3), reencolar o cancelar envíos con destino refrescado (RF-06.4) y
   * configurar y probar el Webhook corporativo (RF-06.5). Las rutas y los sobres
   * son los del contrato técnico §4.1 a §4.6; el cliente devuelve `data` ya
   * desenvuelto, igual que el resto de dominios.
   */
  notifications = {
    /**
     * Lists the outbox with summary counters, filters and pagination (RF-06.3).
     * @param {Object} [filters={}] - { status, channel, priority, ticket_code, limit, offset }
     * @returns {Promise<{summary: Object, items: Array<Object>, pagination: Object}>}
     */
    getOutbox: (filters = {}) => {
      const allowed = ['status', 'channel', 'priority', 'ticket_code', 'limit', 'offset'];
      const params = new URLSearchParams();
      for (const key of allowed) {
        const value = filters[key];
        if (value !== undefined && value !== null && String(value).trim() !== '') {
          params.append(key, String(value).trim());
        }
      }
      const qs = params.toString() ? `?${params.toString()}` : '';
      return this.get(`/coordinator/notifications/outbox${qs}`);
    },

    /**
     * Requeues one failed notification, refreshing its recipient with the current
     * site mailbox or webhook URL (RF-06.4).
     * @param {number|string} id
     * @returns {Promise<Object>}
     */
    retryItem: (id) => {
      return this.post(`/coordinator/notifications/outbox/${encodeURIComponent(id)}/retry`);
    },

    /**
     * Requeues every notification currently in FAILED_PERMANENT (RF-06.4).
     * @returns {Promise<{retried_count: number, retried_ids: Array<number>}>}
     */
    retryAllFailed: () => {
      return this.post('/coordinator/notifications/outbox/retry-all-failed');
    },

    /**
     * Cancels a pending or failed notification, documenting the reason (RF-06.4).
     * @param {number|string} id
     * @param {string|null} [reason=null]
     * @returns {Promise<Object>}
     */
    cancelItem: (id, reason = null) => {
      const trimmed = reason === null || reason === undefined ? '' : String(reason).trim();
      return this.post(
        `/coordinator/notifications/outbox/${encodeURIComponent(id)}/cancel`,
        trimmed !== '' ? { reason: trimmed } : {}
      );
    },

    /**
     * Reads the corporate emergency channel set-up and the active on-call list (RF-06.5).
     * @returns {Promise<Object>}
     */
    getSettings: () => {
      return this.get('/coordinator/notifications/settings');
    },

    /**
     * Saves the corporate emergency channel (webhook name, HTTPS URL and switch).
     * The server re-validates the destination against SSRF (RF-06.5.1).
     * @param {{webhook_name?: string, webhook_url?: string|null, is_webhook_enabled: boolean}} payload
     * @returns {Promise<Object>}
     */
    updateSettings: (payload = {}) => {
      const body = {
        is_webhook_enabled: Boolean(payload.is_webhook_enabled)
      };
      if (payload.webhook_name !== undefined && payload.webhook_name !== null) {
        body.webhook_name = String(payload.webhook_name).trim();
      }
      if (payload.webhook_url !== undefined && payload.webhook_url !== null) {
        const url = String(payload.webhook_url).trim();
        body.webhook_url = url;
      }
      return this.put('/coordinator/notifications/settings', body);
    },

    /**
     * Runs the interactive connection test against the corporate channel, with the
     * 5-second timeout enforced by the server (RF-06.5.2).
     * @param {{webhook_name?: string, webhook_url?: string}} [payload={}]
     * @returns {Promise<{test_passed: boolean, status: string, http_code: ?number, duration_ms: number, tested_at: string, error: ?string}>}
     */
    testWebhook: (payload = {}) => {
      const body = {};
      if (payload.webhook_name !== undefined && payload.webhook_name !== null && String(payload.webhook_name).trim() !== '') {
        body.webhook_name = String(payload.webhook_name).trim();
      }
      if (payload.webhook_url !== undefined && payload.webhook_url !== null && String(payload.webhook_url).trim() !== '') {
        body.webhook_url = String(payload.webhook_url).trim();
      }
      return this.post('/coordinator/notifications/settings/test-webhook', body);
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
     * Retrieves the site-scoped conversation thread of an incident by its ticket code
     * (Módulo 10, RF-01.2, RF-01.3). The server filters internal notes out, so a site
     * manager never receives them (RNF-01, Art. V.4).
     * @param {string} ticketCode e.g. 'TICK-2026-00142' (accepts an optional leading '#')
     * @param {{limit?: number|string, beforeId?: number|string|null}} [params={}]
     * @returns {Promise<Object>} IncidentCommentThreadDto
     */
    getComments: (ticketCode, params = {}) => {
      return this.get(`/incidents/${encodeURIComponent(ticketCode)}/comments${buildCommentQuery(params)}`);
    },

    /**
     * Adds a public comment or additional photo to an active incident (RF-02 / EARS 2.3;
     * Módulo 10, RF-03.2, RF-04.1). Accepts a JSON payload `{ comment_text }` or a
     * `FormData` instance for atomic multipart upload with an optional photo.
     * @param {string} ticketCode
     * @param {Object|FormData} formDataOrJson
     * @returns {Promise<Object>} Updated IncidentCommentThreadDto
     */
    addComment: (ticketCode, formDataOrJson) => {
      return this.post(
        `/incidents/${encodeURIComponent(ticketCode)}/comments`,
        normalizeCommentBody(formDataOrJson)
      );
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
     * Ranking explicado de técnicos idóneos para una avería (Módulo 17, RF-04.1, contrato §3.6).
     *
     * Devuelve `{ incident, recommendation, ranked_technicians }`: la recomendación declara
     * el escalado sanitario y la zona desatendida, y cada puesto trae su desglose, sus
     * insignias y la distancia de referencia. El panel de triaje lo usa para destacar al
     * sugerido y para saber cuándo el descarte exige justificación (Art. V.1).
     * @param {number|string} incidentId
     * @returns {Promise<Object>}
     */
    getSuggestedTechnicians: (incidentId) => {
      return this.get(`/coordinator/incidents/${encodeURIComponent(incidentId)}/suggested-technicians`);
    },

    /**
     * Assigns incident to a technician, with optional urgency reclassification (RF-05).
     * @param {number|string} incidentId
     * @param {number|string} technicianId
     * @param {string|null} [urgency=null]
     * @param {string|null} [urgencyReason=null]
     * @returns {Promise<Object>}
     */
    assignTechnician: (incidentId, technicianId, urgency = null, urgencyReason = null, reassignmentReason = null, overrideJustification = null) => {
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
      if (reassignmentReason) {
        // Canonical key of the endpoint plus the compact alias of the technical plan (§2.2).
        body.reassignment_reason = reassignmentReason;
        body.reason = reassignmentReason;
      }
      if (overrideJustification) {
        // Art. V.1: la justificación del descarte sanitario convive con el motivo de
        // reasignación; una razona la cadena de frío y el otro el cambio de titular.
        body.override_justification = String(overrideJustification);
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
     * Pauses an incident on "Pendiente de Información" with SLA freeze (Módulo 11, RF-01).
     * @param {number|string} incidentId
     * @param {string} reasonCategory
     * @param {string} reasonText
     * @returns {Promise<Object>} IncidentPauseResponseDto
     */
    pauseIncidentPendingInfo: (incidentId, reasonCategory, reasonText) => {
      return this.post(`/coordinator/incidents/${encodeURIComponent(incidentId)}/pause-pending-info`, {
        reason_category: reasonCategory,
        reason_text: reasonText
      });
    },

    /**
     * Resumes an incident from "Pendiente de Información" (Módulo 11, RF-02.2, RF-02.3).
     * Target status is optional and defaults to ASSIGNED (or IN_PROGRESS); REGISTERED
     * re-enters the assignment queue and is only legal when the incident has no
     * responsible technician (cierre de F-5, §6.4).
     * @param {number|string} incidentId
     * @param {string|null} [targetStatus='ASSIGNED']
     * @param {string|null} [resumeNote=null]
     * @returns {Promise<Object>} IncidentPauseResponseDto
     */
    resumeIncidentPendingInfo: (incidentId, targetStatus = 'ASSIGNED', resumeNote = null) => {
      const body = {};
      if (targetStatus !== null && targetStatus !== undefined && targetStatus !== '') {
        body.target_status = targetStatus;
      }
      if (resumeNote !== null && resumeNote !== undefined && String(resumeNote).trim() !== '') {
        body.resume_note = String(resumeNote).trim();
      }
      return this.post(`/coordinator/incidents/${encodeURIComponent(incidentId)}/resume-pending-info`, body);
    },

    /**
     * Cancels an incident after prolonged client inactivity (72 business hours) (Módulo 11, RF-04.3).
     * @param {number|string} incidentId
     * @param {string} cancellationReason
     * @returns {Promise<Object>}
     */
    cancelIncidentInactivity: (incidentId, cancellationReason) => {
      return this.post(`/coordinator/incidents/${encodeURIComponent(incidentId)}/cancel-inactivity`, {
        cancellation_reason: cancellationReason
      });
    },

    /**
     * Retrieves the enriched detail file of one incident for the triage modal (Módulo 09, RF-01).
     * @param {number|string} incidentId Incident id or ticket code with optional '#'.
     * @returns {Promise<Object>}
     */
    getIncidentDetail: (incidentId) => {
      return this.get(`/coordinator/incidents/${encodeURIComponent(incidentId)}/detail`);
    },

    /**
     * Retrieves the full conversation thread of an incident (public comments and internal
     * workshop notes) for the operations coordinator (Módulo 10, RF-01.2, RF-02.3).
     * @param {number|string} incidentId Incident id or ticket code with optional '#'.
     * @param {{limit?: number|string, beforeId?: number|string|null}} [params={}]
     * @returns {Promise<Object>} IncidentCommentThreadDto
     */
    getComments: (incidentId, params = {}) => {
      return this.get(`/coordinator/incidents/${encodeURIComponent(incidentId)}/comments${buildCommentQuery(params)}`);
    },

    /**
     * Appends a coordinator comment or internal workshop note to the incident thread
     * (Módulo 09 RF-05; Módulo 10, RF-03.3, RF-04.1). Accepts a `FormData` instance for
     * atomic multipart upload with an optional photo, a plain JSON payload
     * `{ comment_text, is_internal? }`, or the retrospective `(incidentId, text, isInternal)`
     * shortcut used by the triage detail modal.
     * @param {number|string} incidentId Incident id or ticket code with optional '#'.
     * @param {FormData|Object|string} formDataOrJson
     * @param {boolean} [legacyIsInternal=false] Internal note flag for the text shortcut.
     * @returns {Promise<Object>} Updated IncidentCommentThreadDto
     */
    addComment: (incidentId, formDataOrJson, legacyIsInternal = false) => {
      return this.post(
        `/coordinator/incidents/${encodeURIComponent(incidentId)}/comments`,
        normalizeCommentBody(formDataOrJson, legacyIsInternal)
      );
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
    /**
     * Retrieves the integral detail file of one preventive order for the coordinator
     * ficha modal (Módulo 05, RF-PD-01). Read-only: one aggregated request, no writes.
     * @param {number|string} orderId Order id or order code (ej: PREV-2026-0001).
     * @returns {Promise<Object>}
     */
    getPreventiveOrderDetail: (orderId) => {
      return this.get(`/coordinator/preventive/orders/${encodeURIComponent(orderId)}/detail`);
    },
    generateDuePreventiveOrders: (horizonDays = 5) => {
      return this.post('/coordinator/preventive/generate-due', { horizon_days: Number(horizonDays) });
    },
    /**
     * Assigns or reassigns a preventive order. The justified reason is mandatory when the
     * order already had a different responsible technician (RF-PREV-02, EARS 2.6).
     */
    assignPreventiveOrder: (orderId, technicianId, scheduledDate, reassignmentReason = null) => {
      const body = {
        technician_id: Number(technicianId),
        scheduled_date: scheduledDate
      };
      if (reassignmentReason) {
        body.reassignment_reason = String(reassignmentReason);
      }
      return this.patch(`/coordinator/preventive/orders/${orderId}/assign`, body);
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

    /**
     * Operación del barrido sanitario (Módulo 11: T-PAUSE-41, RF-07). Lectura única: el
     * latido del barrido, la tendencia de 24 h, los expedientes aplazados por contención
     * de fila y el nivel de escalado cuando el aplazamiento persiste. Sin escrituras: el
     * seguimiento lo cierra el propio barrido en la primera pasada que ya no aplaza.
     * @returns {Promise<Object>}
     */
    getSanitarySweepContention: () => {
      return this.get('/coordinator/sanitary-sweep/contention');
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

    // Stock de repuestos de las furgonetas (Módulo 18 - RF-03, RF-04.2)
    getVanStocks: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/van-stocks${q ? `?${q}` : ''}`);
    },
    setVanStock: (payload) => {
      return this.put('/coordinator/van-stocks', payload);
    },

    // Gestión de Reintegros e Importe Retenido (Módulo 08 - RF-REF-03, RF-REF-07, RF-REF-08)
    /**
     * Retrieves the global refund inbox with full financial detail and filters.
     * @param {Object} [filters={}] - { status, location_id, machine_id, requires_approval_only, stranded_only, incident_status, from, to, limit, offset }
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
    },

    // Zonificación operativa (Módulo 17: RF-01, RF-02, RF-03). El catálogo devuelve las
    // zonas con sus contadores y la lista de códigos postales vigentes; el traslado de CP
    // puede responder 409 y el coordinador confirma con `confirm_transfers`.
    /**
     * Catálogo completo de zonas (activas e inactivas) con sus contadores y la lista de
     * códigos postales vigentes; incluye el trabajo pendiente de configuración
     * (`unassigned_locations_count`).
     * @param {{include_inactive?: boolean|string}} [params={}]
     * @returns {Promise<{zones: Array<Object>, unassigned_locations_count: number}>}
     */
    getZones: (params = {}) => {
      const q = new URLSearchParams(params).toString();
      return this.get(`/coordinator/zones${q ? `?${q}` : ''}`);
    },
    createZone: (payload) => {
      return this.post('/coordinator/zones', payload);
    },
    getZone: (zoneId) => {
      return this.get(`/coordinator/zones/${encodeURIComponent(zoneId)}`);
    },
    updateZone: (zoneId, payload) => {
      return this.put(`/coordinator/zones/${encodeURIComponent(zoneId)}`, payload);
    },
    /**
     * Asigna o sustituye en bloque los códigos postales de una zona (RF-01.2, QA 4).
     * Sin `confirmTransfers` un traslado responde 409 con `error.conflicts`.
     */
    assignZonePostalCodes: (zoneId, postalCodes, confirmTransfers = false) => {
      const body = { postal_codes: postalCodes };
      if (confirmTransfers) {
        body.confirm_transfers = true;
      }
      return this.post(`/coordinator/zones/${encodeURIComponent(zoneId)}/postal-codes`, body);
    },
    /**
     * Vincula a un técnico al cuadrante de la zona como titular (`isPrimary`) o suplente
     * (RF-02.1). Un segundo titular vivo exige `confirmPrimaryOverride` (QA 9).
     */
    assignZoneTechnician: (zoneId, technicianId, isPrimary = false, confirmPrimaryOverride = false) => {
      const body = {
        technician_id: Number(technicianId),
        is_primary: Boolean(isPrimary)
      };
      if (confirmPrimaryOverride) {
        body.confirm_primary_override = true;
      }
      return this.post(`/coordinator/zones/${encodeURIComponent(zoneId)}/technicians`, body);
    },
    /**
     * Baja lógica del cuadrante (RF-02.2 / QA 10): la vinculación se extingue con
     * `removed_at` y el histórico se conserva.
     */
    removeZoneTechnician: (zoneId, technicianId) => {
      return this.delete(`/coordinator/zones/${encodeURIComponent(zoneId)}/technicians/${encodeURIComponent(technicianId)}`);
    },
    /**
     * Cuadrante vivo de una zona con la identidad de cada técnico (RF-02.3 / QA 15).
     * Se pide al abrir el modal: el catálogo solo publica contadores para no pagar una
     * consulta por zona (RNF-01).
     * @returns {Promise<{zone_id: number, technicians: Array<Object>}>}
     */
    getZoneTechnicians: (zoneId) => {
      return this.get(`/coordinator/zones/${encodeURIComponent(zoneId)}/technicians`);
    },
    /**
     * Consulta inversa del cuadrante (RF-02.3 / QA 15): cada técnico con sus zonas vivas.
     * Un técnico activo sin zona viaja con `zones: []`, que es trabajo pendiente, no un error.
     * @returns {Promise<{technicians: Array<Object>}>}
     */
    getTechnicianQuadrants: () => {
      return this.get('/coordinator/technicians/quadrants');
    },
    /**
     * Fuerza la zona de una sede (`manualOverride = true`) o restablece la herencia por
     * código postal (`false`) (RF-03.2).
     */
    overrideLocationZone: (locationId, zoneId, manualOverride = true) => {
      const body = { is_manual_override: Boolean(manualOverride) };
      if (zoneId !== null && zoneId !== undefined && zoneId !== '') {
        body.zone_id = Number(zoneId);
      }
      return this.post(`/coordinator/locations/${encodeURIComponent(locationId)}/override-zone`, body);
    }
  };

  /**
   * 5. Field Technician Operations (Mobile Route, Intervention, Resolution)
   */
  technician = {
    /**
     * Retrieves the technician's assigned route (RF-07).
     *
     * Con `includeOpportunisticContext` la respuesta pasa del array indexado de
     * paradas al envoltorio del Módulo 16 (`{ stops, enabled_locations,
     * pending_critical_stops_other_locations }`), que es la forma que la vista de
     * ruta necesita para pintar la tarjeta de sede recién visitada sin romper los
     * contratos anteriores (plan §5, decisión 3).
     *
     * @param {{includeOpportunisticContext?: boolean, formOpenedWithinGrace?: boolean}} [options={}]
     * @returns {Promise<Array<Object>|Object>}
     */
    getMyRoute: (options = {}) => {
      const query = new URLSearchParams();
      if (options.includeOpportunisticContext === true) {
        query.set('include_opportunistic_context', '1');
      }
      if (options.formOpenedWithinGrace === true) {
        query.set('form_opened_within_grace', '1');
      }
      const q = query.toString();

      return this.get(`/technician/my-route${q ? `?${q}` : ''}`);
    },

    /**
     * Retrieves the operational machine catalog of a location enabled for the
     * authenticated technician (Módulo 16, RF-01.4). Each machine carries its
     * `opportunistic_action`: CREATE_NEW, ASSUME_DIRECT, ASSUME_WITH_CONFIRMATION,
     * REOPEN_IN_SITU, CURRENT_TECHNICIAN_IN_PROGRESS or BLOCKED_OTHER_TECH_IN_PROGRESS.
     * @param {number|string} locationId
     * @returns {Promise<Object>} LocationOpportunisticCatalogDto
     */
    getOpportunisticMachines: (locationId) => {
      return this.get(`/technician/locations/${encodeURIComponent(locationId)}/opportunistic-machines`);
    },

    /**
     * Registers a brand-new in-situ ticket and starts the intervention in one act
     * (Módulo 16, Flujo A, RF-04). Accepts a plain JSON payload or a `FormData`
     * instance when a photo is attached (Art. V.5).
     * @param {Object|FormData} payload
     * @returns {Promise<Object>} Created opportunistic incident
     */
    createOpportunisticIncident: (payload) => {
      return this.post('/technician/opportunistic/incidents', payload);
    },

    /**
     * Assumes, resumes or reopens in situ an existing ticket under the technician's
     * ownership (Módulo 16, Flujo B, RF-05).
     * @param {number|string} incidentId
     * @param {Object} [payload={}]
     * @returns {Promise<Object>} Updated or successor incident
     */
    assumeOpportunisticIncident: (incidentId, payload = {}) => {
      return this.post(`/technician/opportunistic/incidents/${encodeURIComponent(incidentId)}/assume`, payload);
    },

    /**
     * Retrieves the read-only incident history of a machine the technician is
     * currently working on (RF-07 / EARS H.1-H.5, specs/technical/technician_machine_history_contracts.md).
     * @param {number|string} machineId
     * @returns {Promise<Object>}
     */
    getMachineHistory: (machineId) => {
      return this.get(`/technician/machines/${encodeURIComponent(machineId)}/history`);
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
     * Retrieves the integral conversation thread of an incident assigned to the
     * authenticated technician: public comments plus internal workshop notes, with the
     * real names of the technical team (Módulo 10, RF-01.2, RF-02.3).
     * @param {number|string} incidentId
     * @param {{limit?: number|string, beforeId?: number|string|null}} [params={}]
     * @returns {Promise<Object>} IncidentCommentThreadDto
     */
    getComments: (incidentId, params = {}) => {
      return this.get(`/technician/incidents/${encodeURIComponent(incidentId)}/comments${buildCommentQuery(params)}`);
    },

    /**
     * Publishes a public comment or an internal workshop note on the assigned incident
     * (Módulo 10, RF-03.3, RF-04.1). Accepts a `FormData` instance for atomic multipart
     * upload with an optional photo, or a plain JSON payload `{ comment_text, is_internal? }`.
     * @param {number|string} incidentId
     * @param {FormData|Object} formDataOrJson
     * @returns {Promise<Object>} Updated IncidentCommentThreadDto
     */
    addComment: (incidentId, formDataOrJson) => {
      return this.post(
        `/technician/incidents/${encodeURIComponent(incidentId)}/comments`,
        normalizeCommentBody(formDataOrJson)
      );
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
     * Pauses an incident on "Pendiente de Información" with SLA freeze by the assigned technician (Módulo 11, RF-01).
     * @param {number|string} incidentId
     * @param {string} reasonCategory
     * @param {string} reasonText
     * @returns {Promise<Object>} IncidentPauseResponseDto
     */
    pauseIncidentPendingInfo: (incidentId, reasonCategory, reasonText) => {
      return this.post(`/technician/incidents/${encodeURIComponent(incidentId)}/pause-pending-info`, {
        reason_category: reasonCategory,
        reason_text: reasonText
      });
    },

    /**
     * Resumes an incident from "Pendiente de Información" in situ by the technician (Módulo 11, RF-02.2, RF-02.3).
     * Automatically transitions to IN_PROGRESS.
     * @param {number|string} incidentId
     * @param {string|null} [targetStatus=null] Optional target status ('IN_PROGRESS')
     * @param {string|null} [resumeNote=null] Optional note describing intervention resume
     * @returns {Promise<Object>} IncidentPauseResponseDto
     */
    resumeIncidentPendingInfo: (incidentId, targetStatus = null, resumeNote = null) => {
      const body = {};
      if (targetStatus !== null && targetStatus !== undefined && targetStatus !== '') {
        body.target_status = targetStatus;
      }
      if (resumeNote !== null && resumeNote !== undefined && String(resumeNote).trim() !== '') {
        body.resume_note = String(resumeNote).trim();
      }
      return this.post(`/technician/incidents/${encodeURIComponent(incidentId)}/resume-pending-info`, body);
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
    },

    // Stock de la furgoneta del propio técnico (Módulo 18: RF-04.1)
    getVanStock: () => {
      return this.get('/technician/van-stock');
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
    reissueLocationAccessCode: (id) => {
      return this.post(`/coordinator/locations/${id}/access-code`);
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
    },
    /**
     * Activates or removes the on-call duty flag of a field technician (RF-02.5).
     * The server rejects Coordinators, inactive accounts and non-boolean input.
     * @param {number|string} id
     * @param {boolean} isOnCall
     * @returns {Promise<Object>}
     */
    setUserOnCall: (id, isOnCall) => {
      return this.patch(`/coordinator/users/${encodeURIComponent(id)}/on-call`, {
        is_on_call: Boolean(isOnCall)
      });
    }
  };
}

// Create default singleton instance
export const api = new ApiClient('/api');

/**
 * Convenience role-aware function or direct client delegation for pending-info pause.
 * Pauses an incident on "Pendiente de Información" with SLA freeze (Módulo 11, RF-01, T-PAUSE-15).
 * Dispatches to coordinator or technician endpoint according to role or technician endpoint fallback.
 * @param {number|string} incidentId
 * @param {string} reasonCategory
 * @param {string} reasonText
 * @param {string|null} [role=null]
 * @returns {Promise<Object>} IncidentPauseResponseDto
 */
export function pauseIncidentPendingInfo(incidentId, reasonCategory, reasonText, role = null) {
  const normalizedRole = String(role || '').toUpperCase();
  if (normalizedRole === 'COORDINATOR') {
    return api.coordinator.pauseIncidentPendingInfo(incidentId, reasonCategory, reasonText);
  }
  if (normalizedRole === 'TECHNICIAN') {
    return api.technician.pauseIncidentPendingInfo(incidentId, reasonCategory, reasonText);
  }
  // If role is unspecified, attempt technician route first; on 403 or if coordinator token, coordinator route
  return api.post(`/technician/incidents/${encodeURIComponent(incidentId)}/pause-pending-info`, {
    reason_category: reasonCategory,
    reason_text: reasonText
  }).catch((err) => {
    if (err && err.status === 403) {
      return api.coordinator.pauseIncidentPendingInfo(incidentId, reasonCategory, reasonText);
    }
    throw err;
  });
}

/**
 * Convenience role-aware function or direct client delegation for resuming a pending-info pause (Módulo 11, RF-02, T-PAUSE-15).
 * @param {number|string} incidentId
 * @param {string|null} [targetStatus=null] 'IN_PROGRESS' or 'ASSIGNED'
 * @param {string|null} [resumeNote=null]
 * @param {string|null} [role=null]
 * @returns {Promise<Object>} IncidentPauseResponseDto
 */
export function resumeIncidentPendingInfo(incidentId, targetStatus = null, resumeNote = null, role = null) {
  const normalizedRole = String(role || '').toUpperCase();
  if (normalizedRole === 'COORDINATOR') {
    return api.coordinator.resumeIncidentPendingInfo(incidentId, targetStatus || 'ASSIGNED', resumeNote);
  }
  if (normalizedRole === 'TECHNICIAN') {
    return api.technician.resumeIncidentPendingInfo(incidentId, targetStatus, resumeNote);
  }
  return api.post(`/technician/incidents/${encodeURIComponent(incidentId)}/resume-pending-info`, {
    ...(targetStatus ? { target_status: targetStatus } : {}),
    ...(resumeNote ? { resume_note: resumeNote } : {})
  }).catch((err) => {
    if (err && err.status === 403) {
      return api.coordinator.resumeIncidentPendingInfo(incidentId, targetStatus || 'ASSIGNED', resumeNote);
    }
    throw err;
  });
}

/**
 * Cancels an incident due to prolonged inactivity (72 business hours) (Módulo 11, RF-04.3, T-PAUSE-15).
 * Available to Operations Coordinator.
 * @param {number|string} incidentId
 * @param {string} cancellationReason
 * @returns {Promise<Object>}
 */
export function cancelIncidentInactivity(incidentId, cancellationReason) {
  return api.coordinator.cancelIncidentInactivity(incidentId, cancellationReason);
}

// Attach functions to api singleton as well for both calling styles
api.pauseIncidentPendingInfo = pauseIncidentPendingInfo;
api.resumeIncidentPendingInfo = resumeIncidentPendingInfo;
api.cancelIncidentInactivity = cancelIncidentInactivity;

export default api;
