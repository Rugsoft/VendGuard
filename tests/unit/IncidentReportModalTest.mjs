/**
 * VendGuard - IncidentReportModal & ImagePreview Test Suite (T-35)
 * 
 * Verifies:
 * 1. Fast guided reporting workflow (< 2 min UX, RNF-02).
 * 2. Automatic Health Precaution Alert for temperature loss on PERISHABLE_FOOD machines (Art. II).
 * 3. Strict duplicate detection: if machine already has active ticket or API returns 409 Conflict,
 *    blocks creation and enables appending comments/photos to the existing ticket log (RF-02 / EARS 2.1, 2.3).
 * 4. Image preview client-side 5 MB limit check (RNF-05) preserving text data on upload failures (EARS 3.9).
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

import { api, ApiError } from '../../public/assets/js/api.js';
import { store, clearSession, setSiteSession } from '../../public/assets/js/store.js';
import { ImagePreview } from '../../public/assets/js/components/ImagePreview.js';
import { IncidentReportModal } from '../../public/assets/js/components/IncidentReportModal.js';

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
console.log(' VendGuard: Frontend Test Suite - IncidentReportModal (T-35)');
console.log('======================================================================\n');

// Mock machines
const perishableMachine = {
  id: 1,
  code: 'VEND-0101',
  model: 'Sanden Vendo G-Drink',
  machine_type: 'PERISHABLE_FOOD',
  floor_wing: 'Planta Baja - Urgencias',
  active_incident: null
};

const duplicateActiveMachine = {
  id: 2,
  code: 'VEND-0102',
  model: 'Necta Canto Touch',
  machine_type: 'HOT_DRINKS',
  floor_wing: 'Planta 1 - Admisión',
  active_incident: {
    id: 42,
    ticket_code: 'INC-2026-0042',
    status: 'EN_CURSO',
    urgency: 'HIGH',
    category: 'PAYMENT_SYSTEM'
  }
};

// ---------------------------------------------------------------------
// TEST GROUP 1: ImagePreview Validation (RNF-05 & EARS 3.9)
// ---------------------------------------------------------------------
console.log('--- Group 1: ImagePreview 5 MB Limit & Safe Formats (RNF-05) ---');

assert('1.1 ImagePreview defines name "ImagePreview"', ImagePreview.name === 'ImagePreview');

let imageEmits = [];
const mockPreviewInstance = {
  maxSizeMb: 5,
  errorMessage: '',
  previewUrl: null,
  revokePreview() {},
  clearPreview() { this.previewUrl = null; this.errorMessage = ''; },
  $emit: (evt, val) => { imageEmits.push({ evt, val }); }
};

// 1.2: Reject oversized file (> 5 MB)
const oversizedFile = {
  name: 'large_photo.jpg',
  size: 6 * 1024 * 1024, // 6 MB
  type: 'image/jpeg'
};

ImagePreview.methods.validateAndEmit.call(mockPreviewInstance, oversizedFile);
assert('1.2 File > 5 MB is rejected with error', imageEmits.some(e => e.evt === 'error' && e.val.includes('5 MB')));
assert('1.3 Oversized file sets error message', mockPreviewInstance.errorMessage.includes('5 MB'));

// 1.4: Reject unapproved MIME format
imageEmits = [];
const scriptFile = {
  name: 'malware.php',
  size: 1024,
  type: 'application/x-php'
};

ImagePreview.methods.validateAndEmit.call(mockPreviewInstance, scriptFile);
assert('1.4 Unapproved MIME type is rejected', imageEmits.some(e => e.evt === 'error' && e.val.includes('Formato')));

// 1.5: Accept valid image (<= 5 MB)
imageEmits = [];
const validImage = new Blob(['dummy image content'], { type: 'image/jpeg' });
validImage.name = 'vending_front.jpg';

ImagePreview.methods.validateAndEmit.call(mockPreviewInstance, validImage);
assert('1.5 Valid JPG under 5 MB is accepted and emitted', imageEmits.some(e => e.evt === 'update:modelValue' && e.val === validImage));

// ---------------------------------------------------------------------
// TEST GROUP 2: Guided Report & Food Safety Alert (Art. II / RF-03)
// ---------------------------------------------------------------------
console.log('\n--- Group 2: Guided Report & Food Safety Alert (Art. II) ---');

assert('2.1 IncidentReportModal defines 6 standard categories', IncidentReportModal.computed.categories().length === 6);

// Helper for modal instance evaluation
function createModalInstance(machine, initialData = {}) {
  let emits = [];
  const instance = {
    modelValue: true,
    machine,
    category: 'TEMPERATURE_COLD',
    description: '',
    retainedMoney: '',
    reporterName: 'Conserje Test',
    reporterPhone: '600123456',
    photoFile: null,
    commentText: '',
    commentAuthor: 'Conserje Test',
    commentPhotoFile: null,
    isSubmitting: false,
    errorMessage: '',
    duplicateIncidentData: null,
    ...initialData,
    $emit: (evt, val) => { emits.push({ evt, val }); },
    getEmits: () => emits
  };

  // Add computed getters
  Object.defineProperty(instance, 'hasActiveIncident', {
    get: () => IncidentReportModal.computed.hasActiveIncident.call(instance)
  });
  Object.defineProperty(instance, 'activeIncident', {
    get: () => IncidentReportModal.computed.activeIncident.call(instance)
  });
  Object.defineProperty(instance, 'isFoodSafetyCritical', {
    get: () => IncidentReportModal.computed.isFoodSafetyCritical.call(instance)
  });
  Object.defineProperty(instance, 'modalTitle', {
    get: () => IncidentReportModal.computed.modalTitle.call(instance)
  });

  return instance;
}

// 2.2: Food Safety Critical alert trigger
const modalPerishable = createModalInstance(perishableMachine, { category: 'TEMPERATURE_COLD' });
assert('2.2 PERISHABLE_FOOD + TEMPERATURE_COLD activates isFoodSafetyCritical', modalPerishable.isFoodSafetyCritical === true);
assert('2.3 Template contains food safety alert notice', IncidentReportModal.template.includes('Prioridad Sanitaria Crítica (Art. II Constitución)'));

// 2.4: Non-perishable does NOT trigger food safety alert
const modalDrinks = createModalInstance({ ...perishableMachine, machine_type: 'COLD_DRINKS' }, { category: 'TEMPERATURE_COLD' });
assert('2.4 COLD_DRINKS + TEMPERATURE_COLD does not activate isFoodSafetyCritical', modalDrinks.isFoodSafetyCritical === false);

// ---------------------------------------------------------------------
// TEST GROUP 3: Successful Incident Report Submission
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Submitting Valid Incident Report ---');

let lastCreatedPayload = null;
api.incidents.create = async (payload) => {
  lastCreatedPayload = payload;
  return {
    id: 88,
    ticket_code: 'INC-2026-0088',
    status: 'REGISTRADA',
    urgency: 'CRITICAL',
    machine_id: 1
  };
};

const modalToSubmit = createModalInstance(perishableMachine, {
  category: 'TEMPERATURE_COLD',
  description: 'Máquina marcando +14ºC en cuba de ensaladas',
  reporterName: 'Carlos Conserje',
  reporterPhone: '611223344',
  retainedMoney: '2.50'
});

await IncidentReportModal.methods.handleSubmitReport.call(modalToSubmit);

assert('3.1 api.incidents.create() was called with machine_id 1', lastCreatedPayload.machine_id === 1);
assert('3.2 Payload contains category TEMPERATURE_COLD', lastCreatedPayload.category === 'TEMPERATURE_COLD');
assert('3.3 Payload contains description text', lastCreatedPayload.description.includes('+14ºC'));
assert('3.4 Payload contains retained money as number', lastCreatedPayload.retained_money_amount === 2.5);
assert('3.5 handleSubmitReport emits "created" event with new ticket code', modalToSubmit.getEmits().some(e => e.evt === 'created' && e.val.ticket_code === 'INC-2026-0088'));

// ---------------------------------------------------------------------
// TEST GROUP 4: Strict Prevention of Duplicates & Appending Comments (RF-02)
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Duplicate Detection & Comment Appending (RF-02) ---');

// 4.1: Machine initialized with pre-existing active incident
const modalDuplicateInit = createModalInstance(duplicateActiveMachine);
assert('4.1 Machine with active incident detected as hasActiveIncident', modalDuplicateInit.hasActiveIncident === true);
assert('4.2 Modal title reflects ongoing incident', modalDuplicateInit.modalTitle.includes('Avería en curso'));

// 4.2: Machine initially believed operational, but API returns 409 Conflict
api.incidents.create = async () => {
  throw new ApiError(
    409,
    'MACHINE_HAS_ACTIVE_INCIDENT',
    'Esta máquina ya cuenta con un aviso activo.',
    { ticket_code: 'INC-2026-0042', status: 'EN_CURSO', urgency: 'HIGH' }
  );
};

const modalConflict = createModalInstance(perishableMachine, {
  description: 'Intento de duplicar ticket',
  reporterName: 'Juan',
  reporterPhone: '600000000'
});

await IncidentReportModal.methods.handleSubmitReport.call(modalConflict);

assert('4.3 409 Conflict sets duplicateIncidentData', modalConflict.duplicateIncidentData?.ticket_code === 'INC-2026-0042');
assert('4.4 409 Conflict automatically switches hasActiveIncident to true', modalConflict.hasActiveIncident === true);
assert('4.5 Error message informs user about duplicate block', modalConflict.errorMessage.includes('aviso activo'));
assert('4.6 Original description text is preserved for user safety (EARS 3.9)', modalConflict.description === 'Intento de duplicar ticket');

// 4.7: Appending comments to duplicate active ticket (EARS 2.3)
let lastCommentPayload = null;
let lastCommentTicket = null;

api.incidents.addComment = async (ticketCode, payload) => {
  lastCommentTicket = ticketCode;
  lastCommentPayload = payload;
  return {
    id: 1,
    incident_id: 42,
    author_name: payload.author_name,
    comment: payload.comment
  };
};

modalConflict.commentAuthor = 'Recepción Central';
modalConflict.commentText = 'El cliente añade que el lector contactless parpadea en rojo.';

await IncidentReportModal.methods.handleSubmitComment.call(modalConflict);

assert('4.7 api.incidents.addComment() called for active ticket INC-2026-0042', lastCommentTicket === 'INC-2026-0042');
assert('4.8 Comment payload contains author and comment text', lastCommentPayload.author_name === 'Recepción Central' && lastCommentPayload.comment.includes('contactless'));
assert('4.9 handleSubmitComment emits "commented" event', modalConflict.getEmits().some(e => e.evt === 'commented'));

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-35 FULFILLED SUCCESSFULLY.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
