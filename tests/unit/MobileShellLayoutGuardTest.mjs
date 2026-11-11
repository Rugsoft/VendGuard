/**
 * VendGuard - Mobile shell layout guard (MobileShellLayoutGuardTest.mjs)
 *
 * H-03 (390px technician view) showed a 205px horizontal overflow. The navbar,
 * the demo profile bar and the technician route header were inline flex rows
 * whose children could not wrap or shrink. This guard freezes the layout
 * contract that removed the overflow:
 *
 *  - The shell rows wrap and their text/action groups may shrink (`min-width: 0`).
 *  - The templates use the named classes instead of the old fixed inline rows.
 *  - On narrow screens the navbar actions take their own line.
 *  - The service-worker cache version is bumped so installed clients receive
 *    the new CSS and JavaScript.
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

const css = read('public/assets/css/styles.css');
const navbar = read('public/assets/js/components/AppNavbar.js');
const app = read('public/assets/js/app.js');
const technicianRoute = read('public/assets/js/views/TechnicianRouteView.js');
const serviceWorker = read('public/sw.js');

console.log('======================================================================');
console.log(' VendGuard: Frontend Test Suite - Mobile shell layout guard');
console.log('======================================================================\n');

assert(
  '1.1 The navbar row wraps and no longer relies on a fixed 56px inline row',
  /flex-wrap:\s*wrap/.test(declarations(css, '.vg-navbar__inner'))
    && /min-height:\s*56px/.test(declarations(css, '.vg-navbar__inner'))
    && navbar.includes('class="vg-navbar__inner"')
    && !navbar.includes('height: 56px; display: flex;')
);

assert(
  '1.2 The navbar action and session groups can shrink without overflowing',
  /flex-wrap:\s*wrap/.test(declarations(css, '.vg-navbar__actions'))
    && /min-width:\s*0/.test(declarations(css, '.vg-navbar__actions'))
    && /min-width:\s*0/.test(declarations(css, '.vg-navbar__session-label'))
    && navbar.includes('class="vg-navbar__actions"')
    && navbar.includes('class="vg-navbar__session"')
);

assert(
  '1.3 The demo profile tabs wrap and truncate their own row instead of the document',
  /flex-wrap:\s*wrap/.test(declarations(css, '.vg-profile-tabs'))
    && /min-width:\s*0/.test(declarations(css, '.vg-profile-tabs'))
    && app.includes('class="vg-profile-tabs"')
    && app.includes('class="vg-profile-bar__inner"')
);

assert(
  '1.4 The credentials guide can break long demo addresses',
  /overflow-wrap:\s*anywhere/.test(declarations(css, '.vg-profile-guide'))
    && app.includes('class="vg-profile-guide"')
);

assert(
  '1.5 The technician route header and its action row wrap inside the card',
  /flex-wrap:\s*wrap/.test(declarations(css, '.vg-tech-route-header'))
    && /flex-wrap:\s*wrap/.test(declarations(css, '.vg-tech-route-actions'))
    && /min-width:\s*0/.test(declarations(css, '.vg-tech-route-actions'))
    && technicianRoute.includes('class="vg-tech-route-header"')
    && technicianRoute.includes('class="vg-tech-route-actions"')
);

const mobileCss = css.slice(css.lastIndexOf('@media (max-width: 700px)'));
assert(
  '1.6 On narrow screens the navbar actions take a full line and align left',
  /width:\s*100%/.test(declarations(mobileCss, '.vg-navbar__actions'))
    && /justify-content:\s*flex-start/.test(declarations(mobileCss, '.vg-navbar__actions'))
);

const cacheVersion = Number((serviceWorker.match(/vendguard-app-shell-v(\d+)/) || [])[1] || 0);
assert(
  '1.7 The service-worker cache is versioned after the layout change',
  cacheVersion >= 13,
  `cache version detected: ${cacheVersion}`
);

console.log(`\n${assertions - failures}/${assertions} assertions passed.`);
if (failures > 0) {
  process.exitCode = 1;
}
