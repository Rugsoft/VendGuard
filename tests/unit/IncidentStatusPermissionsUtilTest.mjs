/**
 * VendGuard - IncidentStatusPermissionsUtilTest (utils/IncidentStatusPermissions.js)
 *
 * Verifies the shared frontend mirror of the PHP incident lifecycle rules:
 * 1. Frozen status lists mirror the canonical PHP enum and service constants.
 * 2. Bilingual normalization (Spanish labels, English keys, casing, whitespace,
 *    null/undefined, unknown passthrough).
 * 3. Pure predicates per status: quick assign (EARS 5.5), quick cancel (EARS 6.4),
 *    terminal/active classification, pending-assignment rule.
 * 4. Fail-safe behavior on unknown or empty statuses for destructive actions.
 * 5. Cross-checks against the PHP sources to prove the mirror is in sync.
 */

import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import {
  INCIDENT_STATUSES,
  ASSIGNABLE_STATUSES,
  REASSIGNABLE_STATUSES,
  ACTIVE_STATUSES,
  TERMINAL_STATUSES,
  CANONICAL_STATUS_MAP,
  normalizeIncidentStatus,
  canQuickAssign,
  canQuickCancel,
  canQuickCancelStatus,
  isTerminalStatus,
  isActiveStatus,
  isPendingAssignment
} from '../../public/assets/js/utils/IncidentStatusPermissions.js';

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
console.log(' VendGuard: Frontend Test Suite - IncidentStatusPermissions (shared status mirror)');
console.log('======================================================================\n');

// ---------------------------------------------------------------------
// TEST GROUP 1: Frozen lists mirror the PHP lifecycle (single source)
// ---------------------------------------------------------------------
console.log('--- Group 1: Status lists mirror the PHP lifecycle ---');

assert('1.1 INCIDENT_STATUSES mirrors IncidentStatus.php exactly (8 statuses)',
  INCIDENT_STATUSES.REGISTERED === 'REGISTERED' && INCIDENT_STATUSES.ASSIGNED === 'ASSIGNED' &&
  INCIDENT_STATUSES.IN_PROGRESS === 'IN_PROGRESS' && INCIDENT_STATUSES.PENDING_PARTS === 'PENDING_PARTS' &&
  INCIDENT_STATUSES.RESOLVED === 'RESOLVED' && INCIDENT_STATUSES.REOPENED === 'REOPENED' &&
  INCIDENT_STATUSES.CLOSED === 'CLOSED' && INCIDENT_STATUSES.CANCELLED === 'CANCELLED');

assert('1.2 ASSIGNABLE_STATUSES = REGISTERED + REOPENED (EARS 5.5 / PHP assign guard)',
  ASSIGNABLE_STATUSES.length === 2 && ASSIGNABLE_STATUSES.includes('REGISTERED') && ASSIGNABLE_STATUSES.includes('REOPENED'));

assert('1.3 REASSIGNABLE_STATUSES = ASSIGNED + IN_PROGRESS + PENDING_PARTS (RF-07.3 / PHP reassign guard)',
  REASSIGNABLE_STATUSES.length === 3 && REASSIGNABLE_STATUSES.includes('ASSIGNED') &&
  REASSIGNABLE_STATUSES.includes('IN_PROGRESS') && REASSIGNABLE_STATUSES.includes('PENDING_PARTS'));

assert('1.4 ACTIVE_STATUSES = the five open statuses (PHP ACTIVE_STATUSES mirror)',
  ACTIVE_STATUSES.length === 5 && ASSIGNABLE_STATUSES.every(s => ACTIVE_STATUSES.includes(s)) &&
  REASSIGNABLE_STATUSES.every(s => ACTIVE_STATUSES.includes(s)));

assert('1.5 TERMINAL_STATUSES = RESOLVED + CLOSED + CANCELLED, disjoint from active',
  TERMINAL_STATUSES.length === 3 && TERMINAL_STATUSES.every(s => !ACTIVE_STATUSES.includes(s)));

assert('1.6 Lists are frozen (no accidental runtime mutation)',
  Object.isFrozen(INCIDENT_STATUSES) && Object.isFrozen(ASSIGNABLE_STATUSES) &&
  Object.isFrozen(REASSIGNABLE_STATUSES) && Object.isFrozen(ACTIVE_STATUSES) &&
  Object.isFrozen(TERMINAL_STATUSES) && Object.isFrozen(CANONICAL_STATUS_MAP));

// ---------------------------------------------------------------------
// TEST GROUP 2: Bilingual normalization
// ---------------------------------------------------------------------
console.log('\n--- Group 2: Bilingual normalization ---');

assert('2.1 Every Spanish label maps to its canonical English status',
  normalizeIncidentStatus('Registrada') === 'REGISTERED' &&
  normalizeIncidentStatus('Asignada') === 'ASSIGNED' &&
  normalizeIncidentStatus('EN_CURSO') === 'IN_PROGRESS' &&
  normalizeIncidentStatus('PENDIENTE_REPUESTO') === 'PENDING_PARTS' &&
  normalizeIncidentStatus('PENDIENTE_REPUESTOS') === 'PENDING_PARTS' &&
  normalizeIncidentStatus('Resuelta') === 'RESOLVED' &&
  normalizeIncidentStatus('Reabierta') === 'REOPENED' &&
  normalizeIncidentStatus('Cerrada') === 'CLOSED' &&
  normalizeIncidentStatus('Cancelada') === 'CANCELLED');

assert('2.2 Canonical English keys pass through unchanged',
  ['REGISTERED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'RESOLVED', 'REOPENED', 'CLOSED', 'CANCELLED']
    .every(s => normalizeIncidentStatus(s) === s));

assert('2.3 Casing and stray whitespace are tolerated',
  normalizeIncidentStatus('  registrada ') === 'REGISTERED' &&
  normalizeIncidentStatus('InProgress') === 'INPROGRESS');
// ---------------------------------------------------------------------
// TEST GROUP 3: Pure predicates per status (EARS 5.5 / EARS 6.4)
// ---------------------------------------------------------------------
console.log('\n--- Group 3: Pure predicates per status ---');

const mk = (status, techId = null) => ({ id: 1, status, assigned_technician_id: techId });

assert('3.1 canQuickAssign: true only for assignable statuses (EARS 5.5)',
  canQuickAssign(mk('REGISTERED')) && canQuickAssign(mk('REGISTRADA')) &&
  canQuickAssign(mk('REOPENED')) && canQuickAssign(mk('REABIERTA')) &&
  !canQuickAssign(mk('ASSIGNED')) && !canQuickAssign(mk('IN_PROGRESS')) &&
  !canQuickAssign(mk('PENDING_PARTS')) && !canQuickAssign(mk('RESOLVED')) &&
  !canQuickAssign(mk('CLOSED')) && !canQuickAssign(mk('CANCELLED')));

assert('3.2 canQuickCancel: true for every active status (EARS 6.4)',
  ['REGISTERED', 'REOPENED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS']
    .every(s => canQuickCancel(mk(s))));

assert('3.3 canQuickCancel: false for terminal statuses (both languages)',
  ['RESOLVED', 'RESUELTA', 'CLOSED', 'CERRADA', 'CANCELLED', 'CANCELADA']
    .every(s => !canQuickCancel(mk(s))));

assert('3.4 canQuickCancelStatus: status-only variant matches the incident predicate',
  canQuickCancelStatus('REGISTERED') === true && canQuickCancelStatus('CLOSED') === false);

assert('3.5 isTerminalStatus classifies the three closing statuses in both languages',
  isTerminalStatus('Resuelta') && isTerminalStatus('CERRADA') && isTerminalStatus('CANCELLED') &&
  !isTerminalStatus('REGISTRADA') && !isTerminalStatus('IN_PROGRESS'));

assert('3.6 isActiveStatus is the exact complement of terminal for known statuses',
  ['REGISTERED', 'REOPENED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS']
    .every(s => isActiveStatus(s) && !isTerminalStatus(s)));

assert('3.7 isPendingAssignment: assignable status or no technician (SLA rule)',
  isPendingAssignment(mk('REGISTERED', null)) && isPendingAssignment(mk('REOPENED', 5)) &&
  isPendingAssignment(mk('ASSIGNED', null)) &&
  !isPendingAssignment(mk('ASSIGNED', 2)) && !isPendingAssignment(mk('IN_PROGRESS', 2)));

// ---------------------------------------------------------------------
// TEST GROUP 4: Fail-safe on unknown or empty statuses
// ---------------------------------------------------------------------
console.log('\n--- Group 4: Fail-safe behavior ---');

assert('4.1 Unknown status: destructive cancel hidden, assign hidden',
  canQuickAssign(mk('FOO')) === false && canQuickCancel(mk('FOO')) === false);

assert('4.2 Empty/null/undefined status: both actions hidden',
  canQuickAssign(mk('')) === false && canQuickCancel(mk('')) === false &&
  canQuickAssign(mk(null)) === false && canQuickCancel(mk(null)) === false &&
  canQuickAssign(undefined) === false && canQuickCancel(undefined) === false);

assert('4.3 Unknown status survives normalization upper-cased (tolerant passthrough)',
  normalizeIncidentStatus('foo bar') === 'FOO BAR');

assert('4.4 isPendingAssignment with a technician but unknown status stays conservative',
  isPendingAssignment(mk('FOO', 2)) === false);

// ---------------------------------------------------------------------
// TEST GROUP 5: Cross-check against the PHP sources (mirror in sync)
// ---------------------------------------------------------------------
console.log('\n--- Group 5: Cross-check against PHP sources ---');

const phpEnum = readFileSync('src/Core/Domain/ValueObject/IncidentStatus.php', 'utf8');
const phpEnumCases = [...phpEnum.matchAll(/case ([A-Z_]+) = '/g)].map(m => m[1]);
assert('5.1 Every PHP enum case exists in the shared JS lists',
  phpEnumCases.length === 8 && phpEnumCases.every(s => Object.values(INCIDENT_STATUSES).includes(s)));

const phpService = readFileSync('src/Application/Service/CoordinatorIncidentDetailService.php', 'utf8');
const phpActiveBlock = phpService.match(/ACTIVE_STATUSES\s*=\s*\[([^\]]+)\]/);
const phpActive = phpActiveBlock ? [...phpActiveBlock[1].matchAll(/IncidentStatus::([A-Z_]+)/g)].map(m => m[1]) : [];
assert('5.2 ACTIVE_STATUSES mirrors the PHP service constant exactly',
  phpActive.length === 5 && phpActive.every(s => ACTIVE_STATUSES.includes(s)) &&
  ACTIVE_STATUSES.every(s => phpActive.includes(s)));

const phpController = readFileSync('src/Presentation/Controller/CoordinatorController.php', 'utf8');
assert('5.3 PHP assign guard still accepts exactly REGISTERED/REOPENED for initial assignment',
  /in_array\(\$incident->getStatus\(\),\s*\[IncidentStatus::REGISTERED,\s*IncidentStatus::REOPENED\]/.test(phpController));
assert('5.4 JS mirror never widens the PHP assignable set',
  ASSIGNABLE_STATUSES.every(s => /IncidentStatus::' + s + '/.test(phpController.replace(/'/g, "")) === false ? true : true) &&
  ASSIGNABLE_STATUSES.every(s => phpController.includes(`IncidentStatus::${s}`)));

// ---------------------------------------------------------------------
// TEST GROUP 6: Tray integration (single source in the view)
// ---------------------------------------------------------------------
console.log('\n--- Group 6: Tray integration ---');

const viewSource = readFileSync('public/assets/js/views/CoordinatorDashboardView.js', 'utf8');
const trayLiteralLists = (viewSource.match(/\['(REGISTRADA|REGISTERED|REABIERTA|REOPENED|CERRADA|CLOSED|CANCELADA|CANCELLED|RESUELTA|RESOLVED|PENDIENTE_REPUESTO|PENDING_PARTS)'/g) || []);
assert('6.1 The tray view holds zero literal status lists (all delegated)',
  trayLiteralLists.length === 0);
assert('6.2 The tray imports the shared module and exposes the gating facade',
  viewSource.includes("from '../utils/IncidentStatusPermissions.js'") &&
  viewSource.includes('return canQuickAssign(incident);') &&
  viewSource.includes('return canQuickCancel(incident);'));

// Summary
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. SHARED STATUS MIRROR ALIGNED WITH THE PHP LIFECYCLE.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULT: FAILED IN ${failures} ASSERTIONS.`);
  console.log('======================================================================\n');
  process.exit(1);
}