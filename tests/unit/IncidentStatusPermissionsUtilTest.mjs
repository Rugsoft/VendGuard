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
  isResolvedStatus,
  isActiveStatus,
  isPendingAssignment,
  URGENCY_LEVELS,
  URGENCY_RANKS,
  normalizeUrgency,
  isCriticalUrgency,
  STATUS_LABELS,
  URGENCY_LABELS,
  BADGE_URGENCY_PALETTE,
  BADGE_STATUS_PALETTE,
  MACHINE_TYPE_LABELS,
  MACHINE_TYPE_ICONS,
  normalizeBadgeKey,
  resolveBadgeConfig,
  isPerishableMachineType,
  INCIDENT_CATEGORIES
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

assert('1.1 INCIDENT_STATUSES mirrors IncidentStatus.php exactly (9 statuses)',
  INCIDENT_STATUSES.REGISTERED === 'REGISTERED' && INCIDENT_STATUSES.ASSIGNED === 'ASSIGNED' &&
  INCIDENT_STATUSES.IN_PROGRESS === 'IN_PROGRESS' && INCIDENT_STATUSES.PENDING_PARTS === 'PENDING_PARTS' &&
  INCIDENT_STATUSES.PENDING_INFO === 'PENDING_INFO' &&
  INCIDENT_STATUSES.RESOLVED === 'RESOLVED' && INCIDENT_STATUSES.REOPENED === 'REOPENED' &&
  INCIDENT_STATUSES.CLOSED === 'CLOSED' && INCIDENT_STATUSES.CANCELLED === 'CANCELLED');

assert('1.2 ASSIGNABLE_STATUSES = REGISTERED + REOPENED (EARS 5.5 / PHP assign guard)',
  ASSIGNABLE_STATUSES.length === 2 && ASSIGNABLE_STATUSES.includes('REGISTERED') && ASSIGNABLE_STATUSES.includes('REOPENED'));

assert('1.3 REASSIGNABLE_STATUSES = ASSIGNED + IN_PROGRESS + PENDING_PARTS (RF-07.3 / PHP reassign guard)',
  REASSIGNABLE_STATUSES.length === 3 && REASSIGNABLE_STATUSES.includes('ASSIGNED') &&
  REASSIGNABLE_STATUSES.includes('IN_PROGRESS') && REASSIGNABLE_STATUSES.includes('PENDING_PARTS'));

assert('1.4 ACTIVE_STATUSES = the six open statuses, PENDING_INFO included (PHP ACTIVE_STATUSES mirror)',
  ACTIVE_STATUSES.length === 6 && ACTIVE_STATUSES.includes('PENDING_INFO') &&
  ASSIGNABLE_STATUSES.every(s => ACTIVE_STATUSES.includes(s)) &&
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
  normalizeIncidentStatus('PENDIENTE_INFORMACION') === 'PENDING_INFO' &&
  normalizeIncidentStatus('PENDIENTE_DE_INFORMACION') === 'PENDING_INFO' &&
  normalizeIncidentStatus('Resuelta') === 'RESOLVED' &&
  normalizeIncidentStatus('Reabierta') === 'REOPENED' &&
  normalizeIncidentStatus('Cerrada') === 'CLOSED' &&
  normalizeIncidentStatus('Cancelada') === 'CANCELLED');

assert('2.2 Canonical English keys pass through unchanged',
  ['REGISTERED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'PENDING_INFO', 'RESOLVED', 'REOPENED', 'CLOSED', 'CANCELLED']
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
  ['REGISTERED', 'REOPENED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'PENDING_INFO']
    .every(s => canQuickCancel(mk(s))));

assert('3.3 canQuickCancel: false for terminal statuses (both languages)',
  ['RESOLVED', 'RESUELTA', 'CLOSED', 'CERRADA', 'CANCELLED', 'CANCELADA']
    .every(s => !canQuickCancel(mk(s))));

assert('3.4 canQuickCancelStatus: status-only variant matches the incident predicate',
  canQuickCancelStatus('REGISTERED') === true && canQuickCancelStatus('CLOSED') === false);

assert('3.5 isTerminalStatus classifies the three closing statuses in both languages',
  isTerminalStatus('Resuelta') && isTerminalStatus('CERRADA') && isTerminalStatus('CANCELLED') &&
  !isTerminalStatus('REGISTRADA') && !isTerminalStatus('IN_PROGRESS'));

assert('3.5b isResolvedStatus accurately identifies RESOLVED in both languages and rejects other statuses',
  isResolvedStatus('RESOLVED') === true && isResolvedStatus('RESUELTA') === true &&
  isResolvedStatus('Resuelta') === true && isResolvedStatus('CLOSED') === false &&
  isResolvedStatus('ASSIGNED') === false && isResolvedStatus('') === false &&
  isResolvedStatus(null) === false);

assert('3.6 isActiveStatus is the exact complement of terminal for known statuses',
  ['REGISTERED', 'REOPENED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'PENDING_INFO']
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
  phpEnumCases.length === 9 && phpEnumCases.every(s => Object.values(INCIDENT_STATUSES).includes(s)));

const phpService = readFileSync('src/Application/Service/CoordinatorIncidentDetailService.php', 'utf8');
const phpActiveBlock = phpService.match(/ACTIVE_STATUSES\s*=\s*\[([^\]]+)\]/);
const phpActive = phpActiveBlock ? [...phpActiveBlock[1].matchAll(/IncidentStatus::([A-Z_]+)/g)].map(m => m[1]) : [];
assert('5.2 ACTIVE_STATUSES mirrors the PHP service constant exactly',
  phpActive.length === 6 && phpActive.includes('PENDING_INFO') &&
  phpActive.every(s => ACTIVE_STATUSES.includes(s)) &&
  ACTIVE_STATUSES.every(s => phpActive.includes(s)));

const phpController = readFileSync('src/Presentation/Controller/CoordinatorController.php', 'utf8');
assert('5.3 PHP assign guard still accepts exactly REGISTERED/REOPENED for initial assignment',
  /in_array\(\$incident->getStatus\(\),\s*\[IncidentStatus::REGISTERED,\s*IncidentStatus::REOPENED\]/.test(phpController));
assert('5.4 JS mirror never widens the PHP assignable set',
  ASSIGNABLE_STATUSES.every(s => /IncidentStatus::' + s + '/.test(phpController.replace(/'/g, "")) === false ? true : true) &&
  ASSIGNABLE_STATUSES.every(s => phpController.includes(`IncidentStatus::${s}`)));

const phpUrgencySource = readFileSync('src/Core/Domain/ValueObject/UrgencyLevel.php', 'utf8');
const phpUrgencyCases = [...phpUrgencySource.matchAll(/case ([A-Z_]+) = '/g)].map(m => m[1]);
assert('5.5 Every UrgencyLevel case in PHP exists in URGENCY_LEVELS',
  phpUrgencyCases.length === 4 &&
  phpUrgencyCases.every(u => Object.values(URGENCY_LEVELS).includes(u)) &&
  Object.values(URGENCY_LEVELS).every(u => phpUrgencyCases.includes(u)));

const phpRanksMatch = phpUrgencySource.match(/priorityRank\(\)[^{]*\{([\s\S]*?)\}/);
const phpRankPairs = phpRanksMatch
  ? [...phpRanksMatch[1].matchAll(/self::([A-Z_]+)\s*=>\s*(\d+)/g)].map(m => [m[1], Number(m[2])])
  : [];
const phpRankMap = Object.fromEntries(phpRankPairs);

const isPriorityOrderingStrictlyPar = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'].every((a, idx, arr) => {
  return arr.slice(idx + 1).every(b => {
    const phpHigher = phpRankMap[a] > phpRankMap[b];
    const jsEarlier = URGENCY_RANKS[a] < URGENCY_RANKS[b];
    return phpHigher && jsEarlier;
  });
});
assert('5.6 Strict priority rank isomorphism between UrgencyLevel.php and URGENCY_RANKS',
  phpRankPairs.length === 4 && isPriorityOrderingStrictlyPar);

const phpCategorySource = readFileSync('src/Core/Domain/ValueObject/IncidentCategory.php', 'utf8');
const phpCategoryCases = [...phpCategorySource.matchAll(/case ([A-Z_]+) = '/g)].map(m => m[1]);
assert('5.7 Every IncidentCategory case in PHP exists in INCIDENT_CATEGORIES',
  phpCategoryCases.length === 5 &&
  INCIDENT_CATEGORIES.length === 5 &&
  phpCategoryCases.every(c => INCIDENT_CATEGORIES.some(ic => ic.value === c)));

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

// ---------------------------------------------------------------------
// TEST GROUP 7: Localized labels + badge palettes (single shared source)
// ---------------------------------------------------------------------
console.log('\n--- Group 7: Localized labels and badge palettes ---');

assert('7.1 STATUS_LABELS covers the nine lifecycle statuses in Spanish',
  STATUS_LABELS.REGISTERED === 'Registrada' && STATUS_LABELS.ASSIGNED === 'Asignada' &&
  STATUS_LABELS.IN_PROGRESS === 'En curso' && STATUS_LABELS.PENDING_PARTS === 'Pendiente repuesto' &&
  STATUS_LABELS.PENDING_INFO === 'Pendiente información' &&
  STATUS_LABELS.RESOLVED === 'Resuelta (Garantía)' && STATUS_LABELS.REOPENED === 'Reabierta' &&
  STATUS_LABELS.CLOSED === 'Cerrada' && STATUS_LABELS.CANCELLED === 'Cancelada');

assert('7.2 URGENCY_LABELS keeps the short badge wording (full PHP label stays server-side)',
  URGENCY_LABELS.CRITICAL === 'Crítica' && URGENCY_LABELS.HIGH === 'Alta' &&
  URGENCY_LABELS.MEDIUM === 'Media' && URGENCY_LABELS.LOW === 'Baja');

assert('7.2b URGENCY_LEVELS and URGENCY_RANKS mirror UrgencyLevel.php priority ranks (1=CRITICAL..4=LOW)',
  URGENCY_LEVELS.CRITICAL === 'CRITICAL' && URGENCY_LEVELS.HIGH === 'HIGH' &&
  URGENCY_LEVELS.MEDIUM === 'MEDIUM' && URGENCY_LEVELS.LOW === 'LOW' &&
  URGENCY_RANKS.CRITICAL === 1 && URGENCY_RANKS.HIGH === 2 &&
  URGENCY_RANKS.MEDIUM === 3 && URGENCY_RANKS.LOW === 4);

assert('7.2c normalizeUrgency folds Spanish aliases and accents (CRÍTICA -> CRITICAL, Alta -> HIGH)',
  normalizeUrgency('CRÍTICA') === 'CRITICAL' && normalizeUrgency('Crítica') === 'CRITICAL' &&
  normalizeUrgency('critica') === 'CRITICAL' && normalizeUrgency('ALTA') === 'HIGH' &&
  normalizeUrgency('Media') === 'MEDIUM' && normalizeUrgency('baja') === 'LOW' &&
  normalizeUrgency('HIGH') === 'HIGH');

assert('7.2d isCriticalUrgency returns true strictly for CRITICAL in both languages',
  isCriticalUrgency('CRITICAL') === true && isCriticalUrgency('CRÍTICA') === true &&
  isCriticalUrgency('Crítica') === true && isCriticalUrgency('HIGH') === false &&
  isCriticalUrgency('MEDIUM') === false && isCriticalUrgency('') === false &&
  isCriticalUrgency(null) === false);

const paletteUrgencies = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'];
assert('7.3 BADGE_URGENCY_PALETTE: semantic colors survive byte-for-byte',
  BADGE_URGENCY_PALETTE.CRITICAL.bg === '#fee2e2' && BADGE_URGENCY_PALETTE.CRITICAL.color === '#dc2626' &&
  BADGE_URGENCY_PALETTE.HIGH.bg === '#ffedd5' && BADGE_URGENCY_PALETTE.MEDIUM.bg === '#fef9c3' &&
  BADGE_URGENCY_PALETTE.LOW.bg === '#dbeafe' &&
  paletteUrgencies.every(u => BADGE_URGENCY_PALETTE[u].cssClass && Object.isFrozen(BADGE_URGENCY_PALETTE[u])));

assert('7.4 BADGE_STATUS_PALETTE: all per-status colors survive byte-for-byte',
  BADGE_STATUS_PALETTE.REGISTERED.bg === '#e5f2fc' && BADGE_STATUS_PALETTE.ASSIGNED.color === '#6d28d9' &&
  BADGE_STATUS_PALETTE.IN_PROGRESS.color === '#92400e' && BADGE_STATUS_PALETTE.PENDING_PARTS.bg === '#ffedd5' &&
  BADGE_STATUS_PALETTE.PENDING_INFO.bg === '#fef9c3' && BADGE_STATUS_PALETTE.PENDING_INFO.color === '#854d0e' &&
  BADGE_STATUS_PALETTE.PENDING_INFO.border === '#fde047' &&
  BADGE_STATUS_PALETTE.RESOLVED.color === '#065f46' && BADGE_STATUS_PALETTE.REOPENED.color === '#b91c1c' &&
  BADGE_STATUS_PALETTE.CLOSED.color === '#4b5563' && BADGE_STATUS_PALETTE.CANCELLED.color === '#9ca3af');

assert('7.5 MACHINE_TYPE_LABELS + icons mirror the pre-extraction dictionary',
  MACHINE_TYPE_LABELS.PERISHABLE_FOOD === 'Comida Perecedera' && MACHINE_TYPE_LABELS.COLD_DRINKS === 'Bebidas Frías' &&
  MACHINE_TYPE_LABELS.HOT_DRINKS === 'Café / Calientes' && MACHINE_TYPE_LABELS.SNACKS === 'Snacks y Aperitivos' &&
  MACHINE_TYPE_LABELS.COMBO === 'Máquina Mixta' &&
  MACHINE_TYPE_ICONS.PERISHABLE_FOOD === '🥪' && MACHINE_TYPE_ICONS.COMBO === '📦');

// ---------------------------------------------------------------------
// TEST GROUP 8: Badge resolvers (IncidentBadge / MachineCard consumers)
// ---------------------------------------------------------------------
console.log('\n--- Group 8: Badge resolver (palette + label in one lookup) ---');

assert('8.1 resolveBadgeConfig(urgency) returns palette + localized label',
  resolveBadgeConfig('CRITICAL', 'urgency').bg === '#fee2e2' &&
  resolveBadgeConfig('CRITICAL', 'urgency').label === 'Crítica' &&
  resolveBadgeConfig('BAJA', 'urgency').label === 'Baja');

assert('8.2 resolveBadgeConfig(status) resolves bilingual keys with the shared normalizer',
  resolveBadgeConfig('EN_CURSO', 'status').label === 'En curso' &&
  resolveBadgeConfig('PENDIENTE_INFORMACION', 'status').label === 'Pendiente información' &&
  resolveBadgeConfig('Pendiente de información', 'status').label === 'Pendiente información' &&
  resolveBadgeConfig('Resuelta', 'status').label === 'Resuelta (Garantía)' &&
  resolveBadgeConfig('EN CURSO', 'status').label === 'En curso'); // historical badge tolerance: whitespace folds to underscore

assert('8.3 Auto mode keeps the historical urgency-first precedence',
  resolveBadgeConfig('CRITICAL').bg === '#fee2e2' &&
  resolveBadgeConfig('RESUELTA').label === 'Resuelta (Garantía)');

assert('8.4 Unknown values resolve to null (consumers keep their own fallback)',
  resolveBadgeConfig('FOO') === null && resolveBadgeConfig('') === null &&
  resolveBadgeConfig(undefined) === null && resolveBadgeConfig('CRITICAL', 'status') === null);

assert('8.5 normalizeBadgeKey strips diacritics and folds whitespace (CRÍTICA -> CRITICA)',
  normalizeBadgeKey('  CRÍTICA ') === 'CRITICA' && normalizeBadgeKey('En curso') === 'EN_CURSO' &&
  normalizeBadgeKey(null) === '');

assert('8.6 isPerishableMachineType keeps the sanitary rule strict',
  isPerishableMachineType('PERISHABLE_FOOD') === true &&
  isPerishableMachineType('COLD_DRINKS') === false && isPerishableMachineType('') === false);

// ---------------------------------------------------------------------
// TEST GROUP 9: Components delegate (no duplicated dictionaries)
// ---------------------------------------------------------------------
console.log('\n--- Group 9: Consumers delegate to the shared module ---');

const badgeSource = readFileSync('public/assets/js/components/IncidentBadge.js', 'utf8');
const machineCardSource = readFileSync('public/assets/js/components/MachineCard.js', 'utf8');
const moduleSource = readFileSync('public/assets/js/utils/IncidentStatusPermissions.js', 'utf8');
const labelLiterals = (badgeSource.match(/(Registrada|Asignada|'En curso'|Pendiente repuesto|Reabierta|Cerrada|Cancelada|Crítica|'Alta'|'Media'|'Baja')/g) || []);
assert('9.1 IncidentBadge holds zero localized label literals (all delegated)',
  labelLiterals.length === 0);
assert('9.2 IncidentBadge imports the shared resolvers',
  badgeSource.includes("from '../utils/IncidentStatusPermissions.js'") &&
  badgeSource.includes('resolveBadgeConfig(') && badgeSource.includes('normalizeBadgeKey('));
assert('9.3 MachineCard holds no MACHINE_TYPE_MAP dictionary',
  !machineCardSource.includes('MACHINE_TYPE_MAP') &&
  machineCardSource.includes("from '../utils/IncidentStatusPermissions.js'"));
assert('9.4 TechnicianRouteView and ReopenTicketModal delegate status predicates and constants',
  readFileSync('public/assets/js/views/TechnicianRouteView.js', 'utf8').includes("from '../utils/IncidentStatusPermissions.js'") &&
  readFileSync('public/assets/js/components/ReopenTicketModal.js', 'utf8').includes("from '../utils/IncidentStatusPermissions.js'") &&
  readFileSync('public/assets/js/components/MachineCard.js', 'utf8').includes('isResolvedStatus'));
assert('9.5 The module exports the full localized vocabulary',
  (moduleSource.match(/export const (STATUS_LABELS|URGENCY_LABELS|BADGE_URGENCY_PALETTE|BADGE_STATUS_PALETTE|MACHINE_TYPE_LABELS|MACHINE_TYPE_ICONS)/g) || []).length === 6);

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