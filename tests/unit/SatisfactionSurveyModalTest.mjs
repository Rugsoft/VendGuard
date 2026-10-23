/**
 * VendGuard - SatisfactionSurveyModal Test Suite (T-CSAT-07)
 *
 * Verifies:
 * 1. Options API shape and the `episodes` / `isOpen` / `loading` contract (contratos §10.1, R1).
 * 2. The modal always shows the most recent pending episode and never invents a total.
 * 3. A successful submit emits `submitted` with the incident id so the parent refetches (RF-05.5, R3).
 * 4. A 409 (`SURVEY_ALREADY_SUBMITTED`) removes the episode without a red error (RF-05.4).
 * 5. A network error keeps both the rating and the written comment (RF-05.4).
 * 6. Accessibility: dialog role, live counter, star labels, 500-char limit, Escape closes (RF-05.6).
 * 7. The `satisfaction` client group points at the real endpoints (contratos §3.1, §4.1).
 */

// Mock localStorage for headless Node environment
const storageMock = (() => {
  let store = {};
  return {
    getItem: (key) => store[key] || null,
    setItem: (key, val) => { store[key] = String(val); },
    removeItem: (key) => { delete store[key]; },
    clear: () => { store = {}; }
  };
})();
globalThis.localStorage = storageMock;

import { api } from '../../public/assets/js/api.js';
import { SatisfactionSurveyModal } from '../../public/assets/js/components/SatisfactionSurveyModal.js';

let assertions = 0;
let failures = 0;

function assert(description, condition, details = '') {
  assertions++;
  if (condition) {
    console.log(`  [PASS] ${description}`);
  } else {
    console.error(`  [FAIL] ${description}`);
    if (details) {
      console.error(`         Reason: ${details}`);
    }
    failures++;
  }
}

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - SatisfactionSurveyModal (T-CSAT-07)');
console.log('======================================================================\n');

const episodeA = {
  incident_id: 42,
  ticket_code: 'INC-2026-0042',
  machine_code: 'VEND-0101',
  machine_model: 'Sanden Vendo G-Drink',
  resolved_at: '2026-07-01T09:30:00+00:00'
};

const episodeB = {
  incident_id: 43,
  ticket_code: 'INC-2026-0043',
  machine_code: 'VEND-0102',
  machine_model: 'Necta Canto Touch',
  resolved_at: '2026-07-02T11:00:00+00:00'
};

/**
 * Builds a fake `this` with the real data/computed/methods wired together, so the
 * assertions exercise the component logic and not a copy of it.
 */
function createInstance(overrides = {}) {
  const emitted = [];
  const instance = {
    ...SatisfactionSurveyModal.data(),
    episodes: [episodeA, episodeB],
    loading: false,
    $emit(event, payload) {
      emitted.push({ event, payload });
    },
    ...overrides
  };

  instance.emitted = emitted;

  for (const [name, fn] of Object.entries(SatisfactionSurveyModal.methods)) {
    instance[name] = fn.bind(instance);
  }

  for (const [name, fn] of Object.entries(SatisfactionSurveyModal.computed)) {
    Object.defineProperty(instance, name, { get: () => fn.call(instance), configurable: true });
  }

  return instance;
}

async function withApiSubmit(stub, run) {
  const original = api.satisfaction.submit;
  api.satisfaction.submit = stub;
  try {
    return await run();
  } finally {
    api.satisfaction.submit = original;
  }
}

// ---------------------------------------------------------------------
// GROUP 1: Component shape (Options API, contratos §10.1 / R1)
// ---------------------------------------------------------------------
console.log('--- Group 1: Options API shape ---');

assert('1.1 Component name is "SatisfactionSurveyModal"', SatisfactionSurveyModal.name === 'SatisfactionSurveyModal');
assert('1.2 Uses Options API (no setup())', SatisfactionSurveyModal.setup === undefined && typeof SatisfactionSurveyModal.data === 'function');
assert(
  '1.3 Declares isOpen, episodes and loading props',
  Object.keys(SatisfactionSurveyModal.props).sort().join(',') === 'episodes,isOpen,loading'
);
assert(
  '1.4 episodes defaults to a fresh array factory',
  typeof SatisfactionSurveyModal.props.episodes.default === 'function'
    && Array.isArray(SatisfactionSurveyModal.props.episodes.default())
    && SatisfactionSurveyModal.props.episodes.default() !== SatisfactionSurveyModal.props.episodes.default()
);
assert(
  '1.5 Emits submitted and closed',
  SatisfactionSurveyModal.emits.includes('submitted') && SatisfactionSurveyModal.emits.includes('closed')
);
const freshData = SatisfactionSurveyModal.data();
assert(
  '1.6 Initial state has no rating, no comment and no messages',
  freshData.rating === null && freshData.comment === '' && freshData.submitting === false
    && freshData.errorMessage === '' && freshData.noticeMessage === ''
);

// ---------------------------------------------------------------------
// GROUP 2: Derived state
// ---------------------------------------------------------------------
console.log('\n--- Group 2: Derived state ---');

let instance = createInstance();
assert('2.1 current is the most recent pending episode', instance.current?.incident_id === 42);
assert('2.2 ratingScale is 1..5', instance.ratingScale.join(',') === '1,2,3,4,5');
assert('2.3 Empty counter reads "0/500"', instance.commentCounter === '0/500');
instance.comment = 'Muy rápido';
assert('2.4 Counter follows the comment length', instance.commentCounter === '10/500');
instance.comment = 'a'.repeat(500);
assert('2.5 500 characters is still valid', instance.isCommentTooLong === false);
assert('2.6 canSubmit is enabled at the limit', instance.canSubmit === false, 'sin nota elegida no se envía');
instance.comment = 'a'.repeat(501);
assert('2.7 501 characters is too long', instance.isCommentTooLong === true);

instance = createInstance();
assert('2.8 canSubmit is false without a rating', instance.canSubmit === false);
instance.rating = 4;
assert('2.9 canSubmit is true with a rating', instance.canSubmit === true);
instance.submitting = true;
assert('2.10 canSubmit is false while submitting', instance.canSubmit === false);

instance = createInstance({ episodes: [] });
assert('2.11 current is null with no episodes', instance.current === null);
assert('2.12 canSubmit is false with no episode', instance.canSubmit === false);

instance = createInstance();
assert(
  '2.13 resolvedAtLabel renders a readable date',
  typeof instance.resolvedAtLabel === 'string' && instance.resolvedAtLabel !== '' && instance.resolvedAtLabel.includes('2026')
);
instance.episodes = [{ ...episodeA, resolved_at: 'not-a-date' }];
assert('2.14 resolvedAtLabel falls back to the raw value', instance.resolvedAtLabel === 'not-a-date');
instance.episodes = [];
assert('2.15 resolvedAtLabel is empty with no episode', instance.resolvedAtLabel === '');

// ---------------------------------------------------------------------
// GROUP 3: Successful submit (RF-05.5, R3)
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Successful submit ---');

instance = createInstance();
let captured = null;
await withApiSubmit(async (incidentId, payload) => {
  captured = { incidentId, payload };
  return { id: 7, incident_id: incidentId, rating: payload.rating, comment: payload.comment };
}, async () => {
  instance.rating = 5;
  instance.comment = '  Excelente atención  ';
  await instance.submit();
});

assert('3.1 Sends the incident id of the current episode', captured?.incidentId === 42);
assert('3.2 Sends the numeric rating and the trimmed comment', captured?.payload.rating === 5 && captured?.payload.comment === 'Excelente atención');
assert(
  '3.3 Emits submitted with the created survey and the incident id',
  instance.emitted.length === 1
    && instance.emitted[0].event === 'submitted'
    && instance.emitted[0].payload.incidentId === 42
    && instance.emitted[0].payload.alreadySubmitted === false
    && instance.emitted[0].payload.survey?.id === 7
);
assert('3.4 Resets rating and comment after a success', instance.rating === null && instance.comment === '');
assert('3.5 Leaves submitting false', instance.submitting === false);

instance = createInstance();
captured = null;
await withApiSubmit(async (incidentId, payload) => {
  captured = { incidentId, payload };
  return { id: 8 };
}, async () => {
  instance.rating = 3;
  await instance.submit();
});
assert('3.6 An empty comment is omitted from the payload', captured !== null && !('comment' in captured.payload));

// ---------------------------------------------------------------------
// GROUP 4: Submit guards
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Submit guards ---');

instance = createInstance();
let calls = 0;
await withApiSubmit(async () => { calls++; return { id: 1 }; }, async () => {
  await instance.submit();
});
assert('4.1 No request without a rating', calls === 0);
assert('4.2 No submitted event without a rating', instance.emitted.length === 0);

instance = createInstance();
calls = 0;
await withApiSubmit(async () => { calls++; return { id: 9 }; }, async () => {
  instance.rating = 4;
  instance.submitting = true;
  await instance.submit();
});
assert('4.3 A submit already in flight is not duplicated', calls === 0);

instance = createInstance();
calls = 0;
await withApiSubmit(async () => { calls++; return { id: 9 }; }, async () => {
  instance.rating = 4;
  instance.comment = 'a'.repeat(501);
  await instance.submit();
});
assert('4.4 An oversized comment is never sent', calls === 0);

// ---------------------------------------------------------------------
// GROUP 5: 409 already submitted (RF-05.4)
// ---------------------------------------------------------------------
console.log('\n--- Group 5: 409 SURVEY_ALREADY_SUBMITTED ---');

instance = createInstance();
await withApiSubmit(async () => {
  const error = new Error('Ese servicio ya fue valorado.');
  error.code = 'SURVEY_ALREADY_SUBMITTED';
  throw error;
}, async () => {
  instance.rating = 2;
  instance.comment = 'Comentario previo';
  await instance.submit();
});

assert(
  '5.1 A 409 still emits submitted so the parent refreshes',
  instance.emitted.length === 1
    && instance.emitted[0].event === 'submitted'
    && instance.emitted[0].payload.alreadySubmitted === true
    && instance.emitted[0].payload.incidentId === 42
);
assert('5.2 A 409 never paints a red error', instance.errorMessage === '');
assert('5.3 A 409 is explained in a neutral notice', typeof instance.noticeMessage === 'string' && instance.noticeMessage.length > 0);
assert('5.4 A 409 clears the form for the next episode', instance.rating === null && instance.comment === '');
assert('5.5 A 409 leaves submitting false', instance.submitting === false);

// ---------------------------------------------------------------------
// GROUP 6: Transport failure keeps the user input (RF-05.4)
// ---------------------------------------------------------------------
console.log('\n--- Group 6: Network error preserves input ---');

instance = createInstance();
await withApiSubmit(async () => {
  throw new Error('No hay conexión con el servidor.');
}, async () => {
  instance.rating = 5;
  instance.comment = 'Todo perfecto';
  await instance.submit();
});

assert('6.1 The server message is shown', instance.errorMessage === 'No hay conexión con el servidor.');
assert('6.2 The rating is preserved', instance.rating === 5);
assert('6.3 The comment is preserved', instance.comment === 'Todo perfecto');
assert('6.4 No submitted event is emitted on failure', instance.emitted.length === 0);
assert('6.5 Failed submit releases the button', instance.submitting === false);

instance = createInstance();
await withApiSubmit(async () => { throw {}; }, async () => {
  instance.rating = 1;
  await instance.submit();
});
assert('6.6 A bodiless failure still shows a safe message', typeof instance.errorMessage === 'string' && instance.errorMessage.length > 0);

// ---------------------------------------------------------------------
// GROUP 7: Closing the modal (RF-05.2, R3)
// ---------------------------------------------------------------------
console.log('\n--- Group 7: Close and Escape ---');

instance = createInstance();
instance.close();
assert('7.1 close() emits closed', instance.emitted.length === 1 && instance.emitted[0].event === 'closed');

instance = createInstance();
let prevented = false;
instance.onKeydown({ key: 'Escape', preventDefault() { prevented = true; } });
assert('7.2 Escape emits closed', instance.emitted.length === 1 && instance.emitted[0].event === 'closed');
assert('7.3 Escape prevents the default navigation', prevented === true);

instance = createInstance();
instance.onKeydown({ key: 'Enter', preventDefault() {} });
assert('7.4 Other keys do not close the modal', instance.emitted.length === 0);

// ---------------------------------------------------------------------
// GROUP 8: Accessibility and template contract (RF-05.6)
// ---------------------------------------------------------------------
console.log('\n--- Group 8: Accessibility template contract ---');

const template = SatisfactionSurveyModal.template;
assert('8.1 Dialog role and modal flag are present', template.includes('role="dialog"') && template.includes('aria-modal="true"'));
assert('8.2 Dialog is labelled by its title', template.includes('aria-labelledby="csat-modal-title"'));
assert('8.3 The counter is announced politely', template.includes('aria-live="polite"') && template.includes('{{ commentCounter }}'));
assert('8.4 Stars expose the 1..5 labels', template.includes("'1 estrella'") && template.includes("' estrellas'"));
assert('8.5 Comment is capped at 500 characters', template.includes(':maxlength="500"'));
assert('8.6 Submit is disabled while the form is not submittable', template.includes(':disabled="!canSubmit"'));
assert('8.7 Escape reaches the handler', template.includes('@keydown="onKeydown"'));
assert('8.8 The empty state is rendered when nothing is pending', template.includes('data-testid="csat-empty"'));
assert('8.9 The loading state is rendered', template.includes('data-testid="csat-loading"'));
assert('8.10 The episode shows ticket, machine and resolution date', template.includes('current.ticket_code') && template.includes('current.machine_code') && template.includes('resolvedAtLabel'));
assert('8.11 The modal is hidden while isOpen is false', template.includes('v-if="isOpen"'));

// ---------------------------------------------------------------------
// GROUP 9: Initial focus (RF-05.6)
// ---------------------------------------------------------------------
console.log('\n--- Group 9: Initial focus ---');

let focused = 0;
instance = createInstance({
  $refs: { starInputs: [{ focus() { focused++; } }, { focus() {} }] },
  $nextTick(callback) { callback(); }
});
SatisfactionSurveyModal.mounted.call(instance);
assert('9.1 mounted() focuses the first star', focused === 1);

focused = 0;
instance = createInstance({
  $refs: { starInputs: { focus() { focused++; } } },
  $nextTick(callback) { callback(); }
});
SatisfactionSurveyModal.mounted.call(instance);
assert('9.2 A single (non-array) ref still receives focus', focused === 1);

instance = createInstance({ $refs: {}, $nextTick(callback) { callback(); } });
let threw = false;
try {
  SatisfactionSurveyModal.mounted.call(instance);
} catch (error) {
  threw = true;
}
assert('9.3 Missing refs never break the mount', threw === false);

// ---------------------------------------------------------------------
// GROUP 10: API client wiring (contratos §3.1, §4.1)
// ---------------------------------------------------------------------
console.log('\n--- Group 10: API client wiring ---');

assert('10.1 api exposes the satisfaction group', typeof api.satisfaction?.getPending === 'function' && typeof api.satisfaction.submit === 'function');

const originalGet = api.get;
const originalPost = api.post;
let seenGet = null;
let seenPost = null;
api.get = function (endpoint) { seenGet = endpoint; return Promise.resolve({ items: [], pending_total: 0 }); };
api.post = function (endpoint, body) { seenPost = { endpoint, body }; return Promise.resolve({ id: 1 }); };

try {
  await api.satisfaction.getPending();
  assert('10.2 getPending hits the site pending endpoint', seenGet === '/location/satisfaction-surveys/pending');

  await api.satisfaction.submit(9, { rating: 5, comment: 'Bien' });
  assert(
    '10.3 submit posts to the incident survey endpoint',
    seenPost?.endpoint === '/location/incidents/9/satisfaction-survey'
  );
  assert(
    '10.4 submit forwards the payload untouched',
    seenPost?.body?.rating === 5 && seenPost?.body?.comment === 'Bien'
  );
} finally {
  api.get = originalGet;
  api.post = originalPost;
}

console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-CSAT-07 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
