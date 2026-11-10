/**
 * VendGuard - Coordinator triage table layout guard (CoordinatorTriageLayoutGuardTest.mjs)
 *
 * H-04 (1280x720) reported that the triage table pushed its action column past
 * the viewport and that the floating chat covered the lower-right actions.
 * This guard freezes the layout contract that addresses both problems:
 *
 *  - The table has an explicit scroll container constrained to its card.
 *  - The action column stays visible when the table needs horizontal scroll.
 *  - A wide-screen right gutter keeps the chat bubble outside the table actions.
 *  - The service-worker cache version is bumped so installed clients receive
 *    the corrected layout.
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

function declarations(source, selector) {
  const start = source.indexOf(selector);
  if (start < 0) {
    return '';
  }

  const open = source.indexOf('{', start);
  const close = source.indexOf('}', open);
  return open >= 0 && close > open ? source.slice(open + 1, close) : '';
}

const dashboard = read('public/assets/js/views/CoordinatorDashboardView.js');
const css = read('public/assets/css/styles.css');
const serviceWorker = read('public/sw.js');

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - Coordinator triage layout guard');
console.log('======================================================================\n');

assert(
  '1.1 The triage table uses an explicit scroll container inside its card',
  dashboard.includes('class="vg-card vg-triage-table-card"')
    && dashboard.includes('class="vg-triage-table-scroll"')
    && dashboard.includes('class="vg-triage-table"')
    && !dashboard.includes('style="overflow-x: auto;"')
);

assert(
  '1.2 The scroll container is constrained to the card width',
  /overflow-x:\s*auto/.test(declarations(css, '.vg-triage-table-scroll'))
    && /max-width:\s*100%/.test(declarations(css, '.vg-triage-table-scroll'))
);

assert(
  '1.3 The action header and cells share one sticky column',
  (dashboard.match(/class="vg-triage-actions"/g) || []).length >= 2
    && /position:\s*sticky/.test(declarations(css, '.vg-triage-actions'))
    && /right:\s*0/.test(declarations(css, '.vg-triage-actions'))
    && /width:\s*240px/.test(declarations(css, '.vg-triage-actions'))
);

assert(
  '1.4 The sticky column inherits its row background instead of covering it',
  /background-color:\s*inherit/.test(declarations(css, '.vg-triage-actions'))
);

const wideCss = css.slice(css.lastIndexOf('@media (min-width: 900px)'));
assert(
  '1.5 A wide-screen gutter keeps the floating chat outside the action column',
  /padding-right:\s*88px/.test(declarations(wideCss, '.vg-triage-table-card'))
);

const cacheVersion = Number((serviceWorker.match(/vendguard-app-shell-v(\d+)/) || [])[1] || 0);
assert(
  '1.6 The service-worker cache is versioned after the layout change',
  cacheVersion >= 14,
  `cache version detected: ${cacheVersion}`
);

console.log(`\n${assertions - failures}/${assertions} assertions passed.`);
if (failures > 0) {
  process.exitCode = 1;
}
