/**
 * VendGuard - Frontend Unit Test Suite (ApiClientParamsTest.mjs)
 *
 * Certifica que `ApiClient.request()` incorpora `options.params` a la URL final.
 * El defecto A5 hacía que los filtros de ausencias y el filtro de rol del listado
 * de técnicos nunca llegaran al servidor, aunque el componente los declaraba.
 *
 * Dogma Vanilla: runner ES Module puro, sin dependencias externas.
 */

import { api } from '../../public/assets/js/api.js';

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - ApiClientParams (A5)');
console.log('======================================================================\n');

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.log(`  [FAIL] ${description}`);
    if (details) console.log(`         ${details}`);
    failures++;
  }
}

const originalFetch = globalThis.fetch;
let lastRequest = null;

globalThis.fetch = async (url, config) => {
  lastRequest = { url, config };
  return {
    status: 200,
    ok: true,
    headers: { get: () => 'application/json' },
    json: async () => ({ success: true, data: [] })
  };
};

try {
  await api.get('/coordinator/technicians/absences', {
    params: { technician_id: 5, status: 'cancelled' }
  });
  assert(
    'GET serializa los parámetros en la query string',
    lastRequest?.url?.includes('?technician_id=5&status=cancelled'),
    lastRequest?.url
  );

  await api.get('/coordinator/users?status=active', {
    params: { role: 'TECHNICIAN' }
  });
  assert(
    'GET conserva la query previa al añadir parámetros',
    lastRequest?.url?.includes('?status=active&role=TECHNICIAN'),
    lastRequest?.url
  );

  await api.get('/search', {
    params: { q: 'monedero', ignored_null: null, ignored_undefined: undefined }
  });
  assert(
    'GET omite null/undefined y conserva el resto',
    lastRequest?.url?.includes('q=monedero')
      && !lastRequest?.url?.includes('ignored_null')
      && !lastRequest?.url?.includes('ignored_undefined'),
    lastRequest?.url
  );

  await api.post('/coordinator/technicians/5/absences', { reason: 'VACATION' }, {
    params: { dry_run: true }
  });
  assert(
    'POST mantiene el body JSON y añade la query',
    lastRequest?.config?.body === JSON.stringify({ reason: 'VACATION' })
      && lastRequest?.url?.includes('?dry_run=true'),
    lastRequest?.url
  );

  await api.get('/search', { params: { tag: ['a', 'b'] } });
  assert(
    'Los arrays se serializan como parámetros repetidos',
    lastRequest?.url?.includes('tag=a&tag=b'),
    lastRequest?.url
  );
} finally {
  globalThis.fetch = originalFetch;
}

console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Exitosas: ${assertions - failures} | Fallidas: ${failures}`);
console.log('======================================================================');

if (failures > 0) {
  process.exit(1);
}
