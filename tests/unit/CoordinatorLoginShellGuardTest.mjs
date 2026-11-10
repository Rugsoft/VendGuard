/**
 * VendGuard - Coordinator login shell guard (CoordinatorLoginShellGuardTest.mjs)
 *
 * H-05 showed the coordinator tabs, filters and table mounted below the login
 * card before a session existed. The authenticated block was a `v-else` of the
 * QR batch view instead of being gated by `isAuthenticated`.
 *
 * This guard freezes the template contract:
 *  - The login form is the unauthenticated branch.
 *  - The QR batch view and the dashboard only mount for an authenticated
 *    coordinator.
 *  - The dashboard tabs stay inside that authenticated branch.
 *  - The service-worker cache version is bumped so installed clients receive
 *    the corrected module.
 *
 * Dogma Vanilla: Node.js native ESM, zero external dependencies, zero network.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const PROJECT_ROOT = fileURLToPath(new URL('../../', import.meta.url));

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

function read(relativePath) {
  return readFileSync(join(PROJECT_ROOT, relativePath), 'utf8');
}

const view = read('public/assets/js/views/CoordinatorDashboardView.js');
const serviceWorker = read('public/sw.js');
const template = view.slice(view.indexOf('template: `'));
const loginIndex = template.indexOf('v-if="!isAuthenticated"');
const authenticatedIndex = template.indexOf('STATE B: AUTHENTICATED');
const tabsIndex = template.indexOf('data-testid="tab-incidents"');
const authenticatedBranch = authenticatedIndex >= 0 && tabsIndex > authenticatedIndex
  ? template.slice(authenticatedIndex, tabsIndex)
  : '';

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - Coordinator login shell guard');
console.log('======================================================================\n');

assert(
  '1.1 The coordinator view declares the unauthenticated login branch',
  loginIndex >= 0 && template.includes('id="coord-email"'),
  `login index: ${loginIndex}`
);

assert(
  '1.2 The authenticated coordinator state comes after the login form',
  authenticatedIndex > loginIndex,
  `login=${loginIndex}, authenticated=${authenticatedIndex}`
);

assert(
  '1.3 The QR batch view only mounts with an authenticated coordinator',
  /<QrBatchPrintView\s+v-if="isAuthenticated && showQrBatchView"/.test(authenticatedBranch),
  authenticatedBranch.slice(0, 160)
);

assert(
  '1.4 The dashboard is gated by isAuthenticated instead of a bare v-else',
  /<div\s+v-else-if="isAuthenticated">/.test(authenticatedBranch)
    && !/<div\s+v-else>/.test(authenticatedBranch)
);

assert(
  '1.5 The coordinator tabs live inside the authenticated branch',
  tabsIndex > authenticatedIndex && authenticatedBranch.includes('vg-action-group')
);

const cacheVersion = Number((serviceWorker.match(/vendguard-app-shell-v(\d+)/) || [])[1] || 0);
assert(
  '1.6 The service-worker cache is versioned after the login-shell change',
  cacheVersion >= 15,
  `cache version detected: ${cacheVersion}`
);

console.log(`\n${assertions - failures}/${assertions} assertions passed.`);
if (failures > 0) {
  process.exitCode = 1;
}
