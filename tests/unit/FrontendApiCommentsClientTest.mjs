/**
 * VendGuard - Frontend Comments API Client Test Suite (T-COM-13)
 *
 * Verifica la condición "Hecho cuando" de la tarea T-COM-13 del módulo 10
 * (hilo de comentarios bidireccional con notas internas confidenciales):
 *
 * 1. `api.incidents.getComments(ticketCode, { limit, beforeId })` y
 *    `api.incidents.addComment(ticketCode, formDataOrJson)`.
 * 2. `api.technician.getComments(incidentId, { limit, beforeId })` y
 *    `api.technician.addComment(incidentId, formData)`.
 * 3. `api.coordinator.getComments(incidentId, { limit, beforeId })` y
 *    `api.coordinator.addComment(incidentId, formDataOrJson)`.
 *
 * Además comprueba que:
 * - La paginación cursorizada se serializa con los nombres de parámetro del
 *   backend (`limit` por defecto 50 y `before_id` sólo cuando se pide un bloque
 *   anterior), conforme a RF-01.2 y RF-01.3.
 * - El envío acepta tanto JSON (`application/json`) como `FormData`
 *   (multipart/form-data con foto opcional) sin fijar manualmente el
 *   Content-Type, conforme a RF-03 y RF-04.1.
 * - La respuesta desenvuelve la envolvente `{ success: true, data }` y entrega
 *   directamente el `IncidentCommentThreadDto`.
 * - El atajo retrospectivo del módulo 09
 *   `api.coordinator.addComment(id, text, isInternal)` conserva su comportamiento
 *   para no regresar el modal de triaje existente.
 *
 * Dogma Vanilla: JavaScript puro en módulos ES nativos (cero dependencias).
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

import { ApiClient, ApiError, api } from '../../public/assets/js/api.js';

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) {
      console.error(`         Motivo: ${details}`);
    }
    failures++;
  }
}

console.log('======================================================================');
console.log(' VendGuard: Suite Frontend - Cliente API del Hilo de Comentarios (T-COM-13)');
console.log('======================================================================\n');

// Arné de fetch simulado: captura la última llamada y devuelve la respuesta armada.
let lastFetchCall = null;
let mockFetchResponse = null;

globalThis.fetch = async (url, options) => {
  lastFetchCall = { url, options };
  return mockFetchResponse;
};

function jsonEnvelope(data, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText: 'OK',
    headers: new Map([['content-type', 'application/json']]),
    json: async () => ({ success: true, data })
  };
}

const threadDto = {
  incident: {
    id: 142,
    ticket_code: 'TICK-2026-00142',
    machine_code: 'VEN-BCN-001',
    machine_model: 'CoffeMax Pro 3000',
    location_name: 'Hospital del Mar',
    status: 'IN_PROGRESS',
    status_label: 'En Reparación',
    is_sealed: false
  },
  pagination: {
    total_comments: 4,
    loaded_count: 4,
    has_more_before: false,
    oldest_id: 12,
    latest_id: 48
  },
  comments: [
    {
      id: 12,
      author_type: 'REPORTER',
      author_name: 'Conserjería Principal (Juan Gómez)',
      comment_text: 'La máquina está en la 3ª planta junto a los ascensores B.',
      photo_url: null,
      created_at: '2026-10-06 10:15:30',
      is_own_message: true
    }
  ]
};

// ---------------------------------------------------------------------
// GRUPO 1: Canal de Sede (api.incidents)
// ---------------------------------------------------------------------
console.log('--- Grupo 1: Canal de Sede (api.incidents) ---');

mockFetchResponse = jsonEnvelope(threadDto);
const siteThread = await api.incidents.getComments('TICK-2026-00142');
assert(
  '1.1 getComments de sede usa el alias GET /incidents/{ticket_code}/comments',
  lastFetchCall.url === '/api/incidents/TICK-2026-00142/comments?limit=50'
    && lastFetchCall.options.method === 'GET'
);
assert(
  '1.2 getComments de sede desenvuelve la envolvente y entrega el DTO del hilo',
  siteThread?.incident?.ticket_code === 'TICK-2026-00142'
    && siteThread?.pagination?.total_comments === 4
    && Array.isArray(siteThread?.comments)
);

await api.incidents.getComments('TICK-2026-00142', { limit: 20, beforeId: 12 });
assert(
  '1.3 getComments de sede serializa limit y before_id para la paginación retroactiva',
  lastFetchCall.url === '/api/incidents/TICK-2026-00142/comments?limit=20&before_id=12',
  `URL obtenida: ${lastFetchCall.url}`
);

await api.incidents.getComments('TICK#2026#0142', { beforeId: null });
assert(
  '1.4 getComments omite before_id cuando es nulo y codifica el código de ticket',
  lastFetchCall.url === '/api/incidents/TICK%232026%230142/comments?limit=50',
  `URL obtenida: ${lastFetchCall.url}`
);

mockFetchResponse = jsonEnvelope(threadDto, 201);
await api.incidents.addComment('TICK-2026-00142', { comment_text: 'Añado una foto de la ubicación exacta.' });
assert(
  '1.5 addComment de sede publica el JSON por POST en el alias del ticket',
  lastFetchCall.url === '/api/incidents/TICK-2026-00142/comments'
    && lastFetchCall.options.method === 'POST'
    && lastFetchCall.options.body === JSON.stringify({ comment_text: 'Añado una foto de la ubicación exacta.' })
);

// El cuerpo de sede jamás debe filtrar is_internal: el servidor lo fuerza a público (RF-03.2).
assert(
  '1.6 El payload de sede no incluye is_internal (forzado público en servidor)',
  !lastFetchCall.options.body.includes('is_internal')
);

const siteFormData = new FormData();
siteFormData.append('comment_text', 'Evidencia de la máquina atascada.');
siteFormData.append('photo', new Blob(['fake-bytes'], { type: 'image/jpeg' }), 'evidencia.jpg');

mockFetchResponse = jsonEnvelope(threadDto, 201);
await api.incidents.addComment('TICK-2026-00142', siteFormData);
assert(
  '1.7 addComment de sede envía FormData sin fijar Content-Type manual (multipart automático)',
  lastFetchCall.options.body === siteFormData
    && lastFetchCall.options.headers['Content-Type'] === undefined
    && lastFetchCall.options.method === 'POST'
);

// ---------------------------------------------------------------------
// GRUPO 2: Canal de Técnico (api.technician)
// ---------------------------------------------------------------------
console.log('\n--- Grupo 2: Canal de Técnico (api.technician) ---');

mockFetchResponse = jsonEnvelope(threadDto);
const technicianThread = await api.technician.getComments(142, { limit: 50 });
assert(
  '2.1 getComments de técnico apunta a GET /technician/incidents/{id}/comments',
  lastFetchCall.url === '/api/technician/incidents/142/comments?limit=50'
    && lastFetchCall.options.method === 'GET'
);
assert(
  '2.2 getComments de técnico devuelve el hilo íntegro desenvuelto',
  technicianThread?.incident?.id === 142 && Array.isArray(technicianThread?.comments)
);

await api.technician.getComments(142, { limit: 10, beforeId: 48 });
assert(
  '2.3 getComments de técnico serializa el cursor before_id',
  lastFetchCall.url === '/api/technician/incidents/142/comments?limit=10&before_id=48',
  `URL obtenida: ${lastFetchCall.url}`
);

const technicianFormData = new FormData();
technicianFormData.append('comment_text', 'Diagnóstico preliminar reservado al taller.');
technicianFormData.append('is_internal', '1');
technicianFormData.append('photo', new Blob(['fake-bytes'], { type: 'image/png' }), 'taller.png');

mockFetchResponse = jsonEnvelope(threadDto, 201);
await api.technician.addComment(142, technicianFormData);
assert(
  '2.4 addComment de técnico publica el FormData (nota interna con foto) por POST',
  lastFetchCall.url === '/api/technician/incidents/142/comments'
    && lastFetchCall.options.method === 'POST'
    && lastFetchCall.options.body === technicianFormData
    && lastFetchCall.options.headers['Content-Type'] === undefined
);

mockFetchResponse = jsonEnvelope(threadDto, 201);
await api.technician.addComment(142, { comment_text: 'Llego a recepción en 10 minutos.', is_internal: false });
assert(
  '2.5 addComment de técnico también acepta JSON con is_internal explícito',
  lastFetchCall.options.method === 'POST'
    && lastFetchCall.options.body === JSON.stringify({ comment_text: 'Llego a recepción en 10 minutos.', is_internal: false })
);

// ---------------------------------------------------------------------
// GRUPO 3: Canal de Coordinación (api.coordinator)
// ---------------------------------------------------------------------
console.log('\n--- Grupo 3: Canal de Coordinación (api.coordinator) ---');

mockFetchResponse = jsonEnvelope(threadDto);
const coordinatorThread = await api.coordinator.getComments(142, { limit: 50, beforeId: 12 });
assert(
  '3.1 getComments de coordinación apunta a GET /coordinator/incidents/{id}/comments',
  lastFetchCall.url === '/api/coordinator/incidents/142/comments?limit=50&before_id=12'
    && lastFetchCall.options.method === 'GET'
);
assert(
  '3.2 getComments de coordinación entrega el DTO del hilo desenvuelto',
  coordinatorThread?.incident?.id === 142 && coordinatorThread?.pagination?.latest_id === 48
);

mockFetchResponse = jsonEnvelope(threadDto, 201);
await api.coordinator.addComment(142, { comment_text: 'Directriz de reparación para el técnico.', is_internal: true });
assert(
  '3.3 addComment de coordinación publica el JSON normalizado por POST',
  lastFetchCall.url === '/api/coordinator/incidents/142/comments'
    && lastFetchCall.options.method === 'POST'
    && lastFetchCall.options.body === JSON.stringify({ comment_text: 'Directriz de reparación para el técnico.', is_internal: true })
);

const coordinatorFormData = new FormData();
coordinatorFormData.append('comment_text', 'Adjunto informe de banco de pruebas.');
coordinatorFormData.append('is_internal', '0');
coordinatorFormData.append('photo', new Blob(['fake-bytes'], { type: 'image/webp' }), 'informe.webp');

mockFetchResponse = jsonEnvelope(threadDto, 201);
await api.coordinator.addComment(142, coordinatorFormData);
assert(
  '3.4 addComment de coordinación acepta FormData (multipart con foto opcional)',
  lastFetchCall.url === '/api/coordinator/incidents/142/comments'
    && lastFetchCall.options.body === coordinatorFormData
    && lastFetchCall.options.headers['Content-Type'] === undefined
);

// Retrocompatibilidad con el modal de triaje del módulo 09 (T-COM-16 aún no lo migra).
mockFetchResponse = jsonEnvelope(threadDto, 201);
await api.coordinator.addComment(142, 'Reviso el histórico de ventas.', true);
assert(
  '3.5 El atajo retrospectivo (id, texto, isInternal) del módulo 09 se conserva',
  lastFetchCall.options.body === JSON.stringify({ comment_text: 'Reviso el histórico de ventas.', is_internal: true })
);

await api.coordinator.addComment(142, 'Aviso público de llegada.');
assert(
  '3.6 El atajo retrospectivo sin bandera mantiene el valor por defecto previo (is_internal = false)',
  lastFetchCall.options.body === JSON.stringify({ comment_text: 'Aviso público de llegada.', is_internal: false })
);

// ---------------------------------------------------------------------
// GRUPO 4: Integración básica con el cliente (tokens y errores)
// ---------------------------------------------------------------------
console.log('\n--- Grupo 4: Autenticación, envolvente y errores ---');

const client = new ApiClient('/api');
client.setToken('auth_token_tech_777');
mockFetchResponse = jsonEnvelope(threadDto);
await client.technician.getComments(142, { limit: 50 });
assert(
  '4.1 El método de comentarios adjunta automáticamente la cabecera Bearer',
  lastFetchCall.options.headers['Authorization'] === 'Bearer auth_token_tech_777'
);

mockFetchResponse = {
  ok: false,
  status: 403,
  statusText: 'Forbidden',
  headers: new Map([['content-type', 'application/json']]),
  json: async () => ({
    success: false,
    error: {
      code: 'CONVERSATION_SEALED',
      message: 'El expediente está sellado y la conversación es de solo lectura.'
    }
  })
};

let sealedError = null;
try {
  await client.coordinator.addComment(142, { comment_text: 'Intento tardío sobre un expediente cerrado.' });
} catch (err) {
  sealedError = err;
}
assert(
  '4.2 El sellado del hilo (HTTP 403) se transforma en un ApiError estructurado',
  sealedError instanceof ApiError
    && sealedError.status === 403
    && sealedError.code === 'CONVERSATION_SEALED'
);

// RESUMEN DE EJECUCIÓN
console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. CONDICIÓN T-COM-13 CUMPLIDA.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.error(` RESULTADO: FALLO EN ${failures} ASERCIONES.`);
console.log('======================================================================\n');
process.exit(1);
