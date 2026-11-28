/**
 * VendGuard - EvaDtsClientPreview
 *
 * Pre-filtro Offline del Módulo 39 (`RF-06.1`, `D6`, `QA-5`, Art. V.4).
 *
 * Antes de encolar un `.dex` sin cobertura, el navegador comprueba el sobre
 * EVA-DTS y ofrece una micro-prelectura para la parada:
 *
 *  - tamaño real en UTF-8 ≤ 1 MB,
 *  - ausencia de bytes nulos y controles peligrosos,
 *  - primera línea `DXS*` y última `DXE*`.
 *
 * La lectura está deliberadamente acotada a `ID4`, `VA1` y códigos `EA1`/`EA2`.
 * **Nunca** se lee `CA3` ni ningún importe por carril. El `va1_total_sales_eur`
 * solo se devuelve al `RESTOCKER`, que lo usa para pre-rellenar M35; al
 * `TECHNICIAN` se le oculta. PHP sigue siendo el único parser autoritativo.
 *
 * Dogma Vanilla: módulo ES nativo, sin dependencias externas (Art. IV.3).
 */

export const MAX_DEX_BYTES = 1048576;
export const DEFAULT_ID4_DECIMAL_PLACES = 2;
export const DEX_PREVIEW_ROLES = Object.freeze({
  RESTOCKER: 'RESTOCKER',
  TECHNICIAN: 'TECHNICIAN'
});

const MAX_MONEY_EUR = 9999999999.99;
const MAX_UNITS = 999999999;
const FORBIDDEN_CONTROLS = /[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/;
const THERMAL_CODE = /^(?:EG[0-9A-Z_]*|TEMP_.*|REFR_.*|COOL_.*|DEFROST_.*|EA[12]_TEMP.*|E_TEMP.*)$/i;

/**
 * @param {string} code
 * @param {string} message
 * @param {Object} [details={}]
 * @returns {{code: string, message: string, details: Object}}
 */
function issue(code, message, details = {}) {
  return { code, message, details };
}

/**
 * @param {string} text
 * @returns {number}
 */
function utf8ByteLength(text) {
  if (typeof TextEncoder === 'function') {
    return new TextEncoder().encode(text).byteLength;
  }

  // Respaldo para entornos antiguos; el navegador objetivo siempre tiene TextEncoder.
  return unescape(encodeURIComponent(text)).length;
}

/**
 * Normaliza saltos y espacios finales igual que el parser PHP.
 *
 * @param {string} text
 * @returns {string[]}
 */
function normalizeLines(text) {
  const lines = text
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .split('\n')
    .map((line) => line.replace(/[ \t]+$/, ''));

  while (lines.length > 0 && lines[0].trim() === '') {
    lines.shift();
  }
  while (lines.length > 0 && lines[lines.length - 1].trim() === '') {
    lines.pop();
  }

  return lines;
}

/**
 * Valida el sobre y devuelve las líneas solo para el uso interno del preview.
 *
 * @param {string} rawText
 * @returns {{ok: boolean, valid: boolean, bytes: number, lines: string[], errors: Array<Object>}}
 */
function inspectDexEnvelopeWithLines(rawText) {
  const errors = [];

  if (typeof rawText !== 'string' || rawText === '') {
    errors.push(issue(
      'DEX_INVALID_TEXT_PAYLOAD',
      'El fichero .dex está vacío o no es texto.'
    ));

    return { ok: false, valid: false, bytes: 0, lines: [], errors };
  }

  const bytes = utf8ByteLength(rawText);
  if (bytes > MAX_DEX_BYTES) {
    errors.push(issue(
      'DEX_FILE_TOO_LARGE',
      `El fichero .dex supera el tope de 1 MB (${bytes} bytes > ${MAX_DEX_BYTES}).`,
      { bytes, max_bytes: MAX_DEX_BYTES }
    ));

    return { ok: false, valid: false, bytes, lines: [], errors };
  }

  if (FORBIDDEN_CONTROLS.test(rawText)) {
    errors.push(issue(
      'DEX_INVALID_TEXT_PAYLOAD',
      'El fichero .dex contiene bytes nulos o caracteres de control peligrosos.'
    ));

    return { ok: false, valid: false, bytes, lines: [], errors };
  }

  const lines = normalizeLines(rawText);
  if (lines.length < 2 || !lines[0].startsWith('DXS*') || !lines[lines.length - 1].startsWith('DXE*')) {
    errors.push(issue(
      'DEX_INVALID_ENVELOPE',
      'El envoltorio EVA-DTS es inválido: la primera línea debe empezar por DXS* y la última por DXE*.'
    ));

    return { ok: false, valid: false, bytes, lines, errors };
  }

  return { ok: true, valid: true, bytes, lines, errors };
}

/**
 * Valida el sobre EVA-DTS sin interpretar ni devolver el contenido financiero.
 *
 * La lista interna de líneas no sale de este módulo: así ni siquiera un llamador
 * que valide antes de encolar puede leer un bloque `CA3` desde aquí.
 *
 * @param {string} rawText
 * @returns {{ok: boolean, valid: boolean, bytes: number, errors: Array<Object>}}
 */
export function inspectDexEnvelope(rawText) {
  const inspection = inspectDexEnvelopeWithLines(rawText);

  return {
    ok: inspection.ok,
    valid: inspection.valid,
    bytes: inspection.bytes,
    errors: inspection.errors
  };
}

/**
 * @param {string[]} lines
 * @returns {number}
 */
function readId4DecimalPlaces(lines) {
  for (const line of lines) {
    if (!line.startsWith('ID4*')) {
      continue;
    }

    const fields = line.split('*');
    let candidate = String(fields[1] ?? '').trim();
    if (candidate === '' && /^\d+$/.test(String(fields[2] ?? '').trim())) {
      candidate = String(fields[2]).trim();
    }

    if (/^\d$/.test(candidate) && Number(candidate) <= 4) {
      return Number(candidate);
    }

    return DEFAULT_ID4_DECIMAL_PLACES;
  }

  return DEFAULT_ID4_DECIMAL_PLACES;
}

/**
 * @param {string|null|undefined} raw
 * @param {number} scale
 * @returns {number|null}
 */
function parseMoney(raw, scale) {
  const clean = String(raw ?? '').trim();
  if (clean === '' || !/^\d+(\.\d{1,2})?$/.test(clean)) {
    return null;
  }

  const value = clean.includes('.') ? Number(clean) : Number(clean) / (10 ** scale);
  if (!Number.isFinite(value) || value < 0 || value > MAX_MONEY_EUR) {
    return null;
  }

  return Math.round(value * 100) / 100;
}

/**
 * @param {string|null|undefined} raw
 * @returns {number|null}
 */
function parseUnits(raw) {
  const clean = String(raw ?? '').trim();
  if (!/^\d+$/.test(clean)) {
    return null;
  }

  const value = Number(clean);
  if (!Number.isSafeInteger(value) || value < 0 || value > MAX_UNITS) {
    return null;
  }

  return value;
}

/**
 * Lee solo el primer `VA1` y respeta la escala vigente en ese punto del fichero,
 * igual que el parser PHP.
 *
 * @param {string[]} lines
 * @returns {{sales: number, units: number}|null}
 */
function readVa1(lines) {
  let scale = DEFAULT_ID4_DECIMAL_PLACES;

  for (const line of lines) {
    if (line.startsWith('ID4*')) {
      scale = readId4DecimalPlaces([line]);
      continue;
    }

    if (!line.startsWith('VA1*')) {
      continue;
    }

    const fields = line.split('*');
    const sales = parseMoney(fields[1] ?? null, scale);
    const units = parseUnits(fields[2] ?? null);
    if (sales === null || units === null) {
      return null;
    }

    return { sales, units };
  }

  return null;
}

/**
 * @param {string[]} lines
 * @returns {Array<{source_block: string, event_code: string, is_thermal_alert: boolean}>}
 */
function readEvents(lines) {
  const events = [];

  for (const line of lines) {
    if (!line.startsWith('EA1*') && !line.startsWith('EA2*')) {
      continue;
    }

    const fields = line.split('*');
    const code = String(fields[1] ?? '').trim();
    if (code === '') {
      continue;
    }

    events.push({
      source_block: line.slice(0, 3),
      event_code: code,
      is_thermal_alert: THERMAL_CODE.test(code)
    });
  }

  return events;
}

/**
 * Micro-prelectura Offline de un `.dex`.
 *
 * @param {string} rawText Contenido ya leído del fichero.
 * @param {{role?: string}} [options={}]
 * @returns {{
 *   ok: boolean,
 *   valid: boolean,
 *   bytes: number,
 *   id4_decimal_places: number,
 *   va1_total_vend_units: number|null,
 *   events: Array<Object>,
 *   va1_total_sales_eur?: number|null,
 *   errors: Array<Object>
 * }}
 */
export function previewDex(rawText, options = {}) {
  const inspection = inspectDexEnvelopeWithLines(rawText);
  if (!inspection.ok) {
    return {
      ok: false,
      valid: false,
      bytes: inspection.bytes,
      id4_decimal_places: DEFAULT_ID4_DECIMAL_PLACES,
      va1_total_vend_units: null,
      events: [],
      errors: inspection.errors
    };
  }

  const role = String(options.role ?? DEX_PREVIEW_ROLES.RESTOCKER).toUpperCase();
  const va1 = readVa1(inspection.lines);
  const preview = {
    ok: true,
    valid: true,
    bytes: inspection.bytes,
    id4_decimal_places: readId4DecimalPlaces(inspection.lines),
    va1_total_vend_units: va1?.units ?? null,
    events: readEvents(inspection.lines),
    errors: []
  };

  // Art. V.4: solo el Reponedor ve el importe que pre-rellena su M35.
  if (role === DEX_PREVIEW_ROLES.RESTOCKER) {
    preview.va1_total_sales_eur = va1?.sales ?? null;
  }

  return preview;
}

/**
 * Variante para un `File`/`Blob` del selector móvil.
 *
 * Comprueba el tamaño antes de leer y delega el resto en `previewDex`.
 *
 * @param {Blob|File} file
 * @param {{role?: string}} [options={}]
 * @returns {Promise<Object>}
 */
export async function previewDexFile(file, options = {}) {
  if (file === null || typeof file !== 'object' || typeof file.text !== 'function') {
    return previewDex('', options);
  }

  if (Number(file.size) > MAX_DEX_BYTES) {
    return {
      ok: false,
      valid: false,
      bytes: Number(file.size),
      id4_decimal_places: DEFAULT_ID4_DECIMAL_PLACES,
      va1_total_vend_units: null,
      events: [],
      errors: [issue(
        'DEX_FILE_TOO_LARGE',
        `El fichero .dex supera el tope de 1 MB (${Number(file.size)} bytes > ${MAX_DEX_BYTES}).`,
        { bytes: Number(file.size), max_bytes: MAX_DEX_BYTES }
      )]
    };
  }

  const text = await readDexFileText(file);

  return previewDex(text, options);
}

/**
 * Lee un `.dex` respetando la codificación de origen (H25-07).
 *
 * `File.text()` decodifica siempre como UTF-8 y sustituye por U+FFFD los bytes
 * Windows-1252 (`ñ`, acentos, `€`), una pérdida irreversible antes de enviar el
 * payload. Se leen los bytes y se intenta UTF-8 estricto; si no es válido, se
 * decodifica como `windows-1252`, la misma conversión que aplica el servidor.
 */
export async function readDexFileText(file) {
  const data = await file.arrayBuffer();
  const buffer = data instanceof ArrayBuffer ? data : new Uint8Array(data).buffer;

  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(buffer);
  } catch {
    return new TextDecoder('windows-1252').decode(buffer);
  }
}

export default previewDex;
