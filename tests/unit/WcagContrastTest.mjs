/**
 * VendGuard - WCAG AA Contrast Unit Tests (WcagContrastTest.mjs)
 *
 * Cierra el hueco 3 de la auditoría de trazabilidad del módulo 11 (T-PAUSE-28, RNF-04,
 * Constitución Art. II): la especificación exige contraste accesible WCAG AA para el
 * ámbar técnico del estado pausado y hasta ahora sólo se asertaba que los tokens
 * *existen*, nunca cuánto contraste dan de verdad.
 *
 * Qué mide
 *   Todas las parejas texto/fondo declaradas por el sistema de estado se resuelven con la
 *   fórmula de luminancia relativa de WCAG 2.1 y se exige el umbral AA de texto normal
 *   (4,5:1). Entran:
 *     1. El ámbar técnico del módulo 11 (`AMBER_TECHNICAL_TOKENS`, pausa de SLA).
 *     2. Las urgencias (`BADGE_URGENCY_PALETTE`).
 *     3. Los estados del ciclo de vida (`BADGE_STATUS_PALETTE`).
 *     4. Los semáforos sanitarios y los estados de orden preventiva, leídos por su API
 *        pública (`getSanitaryStatusBadge` / `getOrderStatusBadge`).
 *     5. La pareja de aviso del sistema de diseño (`--color-warning-bg` /
 *        `--color-warning-text`) que pinta la cabecera del modal de pausa, leída del
 *        propio `design-tokens.css`.
 *
 * Qué NO mide, y por qué
 *   Los filos decorativos de las insignias (1,1-1,6:1). WCAG 1.4.11 se aplica a los
 *   límites que identifican un control, no a un borde cuyo único cometido es agrupar: el
 *   estado lo comunica el texto y esa es la pareja que se exige. Sus ratios se publican
 *   como información y las excepciones se declaran una a una más abajo.
 *
 * Dogma Vanilla: ejecutable con `node` sin dependencias npm.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  AMBER_TECHNICAL_TOKENS,
  BADGE_URGENCY_PALETTE,
  BADGE_STATUS_PALETTE
} from '../../public/assets/js/utils/IncidentStatusPermissions.js';

import {
  MACHINE_SANITARY_STATUSES,
  PREVENTIVE_ORDER_STATUSES,
  getSanitaryStatusBadge,
  getOrderStatusBadge
} from '../../public/assets/js/utils/PreventiveLabels.js';

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
console.log(' VendGuard: Frontend Test Suite - WCAG AA Contrast (T-PAUSE-28, RNF-04)');
console.log('======================================================================\n');

// ─── Utilidad WCAG 2.1 ────────────────────────────────────────────────────────
const relativeLuminance = (hex) => {
  const channels = [1, 3, 5]
    .map((offset) => parseInt(hex.slice(offset, offset + 2), 16) / 255)
    .map((channel) => (channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4));

  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
};

const contrastRatio = (foreground, background) => {
  const [lighter, darker] = [relativeLuminance(foreground), relativeLuminance(background)]
    .sort((a, b) => b - a);

  return (lighter + 0.05) / (darker + 0.05);
};

const AA_NORMAL_TEXT = 4.5;

/**
 * Excepciones declaradas: contraste insuficiente **preexistente y ajeno al módulo 11**.
 *
 * Se declaran en lugar de esconderse para que la batería no dé por bueno lo que no mide.
 * La decisión de corregirlas es del Product Owner y afecta a otros módulos (urgencia
 * crítica y estado cancelado), así que T-PAUSE-28 no cambia colores compartidos.
 */
const DOCUMENTED_EXCEPTIONS = Object.freeze({
  'URGENCY.CRITICAL': {
    measured: 3.95,
    reason: 'Texto rojo (#dc2626) sobre rojo claro (#fee2e2): la insignia de urgencia CRÍTICA no alcanza AA de texto normal.',
    scope: 'Módulo 04/07 (badges compartidos), fuera del alcance del módulo 11.',
    pendingProductOwnerDecision: true
  },
  'STATUS.CANCELLED': {
    measured: 2.31,
    reason: 'Texto gris claro (#9ca3af) sobre gris (#f3f4f6): el estado cancelado se lee a propósito como apagado y no alcanza AA.',
    scope: 'Módulo 04 (ciclo de vida), fuera del alcance del módulo 11.',
    pendingProductOwnerDecision: true
  }
});

// ─── Recolección de parejas ───────────────────────────────────────────────────
const textPairs = [];
const borderPairs = [];

const collect = (source, label, tokens) => {
  if (!tokens || !tokens.bg || !tokens.color) return;
  textPairs.push({ source, label, fg: tokens.color, bg: tokens.bg, ratio: contrastRatio(tokens.color, tokens.bg) });
  if (tokens.border) {
    borderPairs.push({ source, label, ratio: contrastRatio(tokens.border, tokens.bg) });
  }
};

collect('AMBER', 'AMBER_TECHNICAL', AMBER_TECHNICAL_TOKENS);
for (const [key, tokens] of Object.entries(BADGE_URGENCY_PALETTE)) collect('URGENCY', key, tokens);
for (const [key, tokens] of Object.entries(BADGE_STATUS_PALETTE)) collect('STATUS', key, tokens);
for (const key of Object.keys(MACHINE_SANITARY_STATUSES)) collect('SANITARY', key, getSanitaryStatusBadge(key));
for (const key of Object.keys(PREVENTIVE_ORDER_STATUSES)) collect('ORDER', key, getOrderStatusBadge(key));

// La pareja del sistema de diseño que pinta la cabecera del modal de pausa.
const tokensCssPath = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../public/assets/css/design-tokens.css'
);
const tokensCss = fs.readFileSync(tokensCssPath, 'utf8');
const cssToken = (name) => {
  const match = tokensCss.match(new RegExp(`${name}\\s*:\\s*(#[0-9a-fA-F]{6})`));
  return match ? match[1] : null;
};

const warningBg = cssToken('--color-warning-bg');
const warningText = cssToken('--color-warning-text');

if (warningBg && warningText) {
  textPairs.push({
    source: 'DESIGN_TOKEN',
    label: 'WARNING_SURFACE',
    fg: warningText,
    bg: warningBg,
    ratio: contrastRatio(warningText, warningBg)
  });
}

// ─── Grupo 1: Fórmula y anclaje del ámbar del módulo 11 ───────────────────────
console.log('--- Grupo 1: Fórmula WCAG y anclaje del ámbar del módulo 11 (RNF-04) ---');

assert('1.1 La fórmula de luminancia reproduce los extremos conocidos (negro/blanco = 21:1)',
  Math.abs(contrastRatio('#000000', '#ffffff') - 21) < 0.01
    && Math.abs(contrastRatio('#ffffff', '#ffffff') - 1) < 0.001);

assert('1.2 La fórmula es simétrica respecto al orden de los colores',
  Math.abs(contrastRatio('#fef9c3', '#854d0e') - contrastRatio('#854d0e', '#fef9c3')) < 0.0001);

assert('1.3 El ámbar técnico es el literal que fija la especificación (#fef9c3 sobre #854d0e)',
  AMBER_TECHNICAL_TOKENS.bg === '#fef9c3' && AMBER_TECHNICAL_TOKENS.color === '#854d0e');

const amber = textPairs.find((pair) => pair.label === 'AMBER_TECHNICAL');
assert('1.4 El ámbar de la pausa de SLA supera AA de texto normal con holgura',
  amber.ratio >= AA_NORMAL_TEXT,
  `ratio medido: ${amber.ratio.toFixed(2)}:1`);

// ─── Grupo 2: Cobertura de todas las parejas declaradas ───────────────────────
console.log('\n--- Grupo 2: Cobertura de las parejas de estado declaradas ---');

const bySource = textPairs.reduce((acc, pair) => {
  acc[pair.source] = (acc[pair.source] || 0) + 1;
  return acc;
}, {});

assert('2.1 Se miden las cinco familias de tokens de estado, sin dejar ninguna fuera',
  ['AMBER', 'URGENCY', 'STATUS', 'SANITARY', 'ORDER', 'DESIGN_TOKEN'].every((source) => bySource[source] > 0),
  `familias medidas: ${JSON.stringify(bySource)}`);

// Guardian de mantenimiento: si aparece una pareja nueva sin medición, este número cambia y
// la aserción obliga a revisarla en lugar de dejarla pasar en silencio.
const EXPECTED_PAIR_COUNT = 26;
assert('2.2 Ninguna pareja nueva entra en el sistema sin pasar por esta medición',
  textPairs.length === EXPECTED_PAIR_COUNT,
  `parejas medidas: ${textPairs.length} · esperadas: ${EXPECTED_PAIR_COUNT}`);

assert('2.3 Todos los estados del ciclo de vida declaran color de texto y de fondo',
  Object.values(BADGE_STATUS_PALETTE).every((tokens) => Boolean(tokens.bg && tokens.color)));

// ─── Grupo 3: Umbral AA de texto normal y excepciones declaradas ──────────────
console.log('\n--- Grupo 3: Umbral AA de texto normal y excepciones declaradas ---');

const failing = textPairs.filter((pair) => pair.ratio < AA_NORMAL_TEXT);
const failingLabels = failing.map((pair) => `${pair.source}.${pair.label}`).sort();
const exceptionLabels = Object.keys(DOCUMENTED_EXCEPTIONS).sort();

assert('3.1 El conjunto de fallos reales coincide exactamente con las excepciones declaradas',
  JSON.stringify(failingLabels) === JSON.stringify(exceptionLabels),
  `fallos: ${JSON.stringify(failingLabels)} · excepciones: ${JSON.stringify(exceptionLabels)}`);

for (const [key, entry] of Object.entries(DOCUMENTED_EXCEPTIONS)) {
  const measured = failing.find((pair) => `${pair.source}.${pair.label}` === key);

  assert(`3.2 ${key} se declara con su ratio medido, su motivo y su alcance ajeno al módulo 11`,
    Boolean(measured)
      && Math.abs(measured.ratio - entry.measured) < 0.01
      && entry.reason.length > 40
      && entry.scope.includes('módulo')
      && entry.pendingProductOwnerDecision === true,
    `medido: ${measured ? measured.ratio.toFixed(2) : 'N/A'}`);
}

assert('3.3 Ningún estado del módulo 11 (ámbar de pausa, cuarentena, espera) entra en la lista de excepciones',
  !failingLabels.some((label) => label === 'AMBER.AMBER_TECHNICAL'
    || label === 'STATUS.PENDING_INFO'
    || label === 'SANITARY.QUARANTINE'
    || label === 'SANITARY.ATTENTION_REQUIRED'));

// ─── Grupo 4: Pareja de aviso del sistema de diseño ───────────────────────────
console.log('\n--- Grupo 4: Pareja de aviso del sistema de diseño (cabecera del modal de pausa) ---');

assert('4.1 Los tokens de aviso del sistema de diseño se leen del CSS real, no de una copia',
  warningBg !== null && warningText !== null,
  `bg: ${warningBg} · texto: ${warningText}`);

const warningPair = textPairs.find((pair) => pair.label === 'WARNING_SURFACE');
assert('4.2 La cabecera ámbar del modal de pausa también supera AA de texto normal',
  warningPair.ratio >= AA_NORMAL_TEXT,
  `ratio medido: ${warningPair.ratio.toFixed(2)}:1`);

assert('4.3 Los dos ámbares del sistema conviven con roles declarados y sin mezclarse',
  warningPair.bg !== AMBER_TECHNICAL_TOKENS.bg
    && warningPair.fg !== AMBER_TECHNICAL_TOKENS.color
    && amber.ratio >= AA_NORMAL_TEXT
    && warningPair.ratio >= AA_NORMAL_TEXT);

// ─── Grupo 5: Filos decorativos (información, no umbral) ──────────────────────
console.log('\n--- Grupo 5: Filos decorativos: medidos e informados, fuera del umbral 1.4.11 ---');

assert('5.1 Todos los filos declarados se miden y se publican',
  borderPairs.length > 0 && borderPairs.every((pair) => Number.isFinite(pair.ratio)));

const worstBorder = borderPairs.reduce((acc, pair) => (pair.ratio < acc.ratio ? pair : acc), borderPairs[0]);
assert('5.2 El filo más flojo se declara explícitamente como decorativo, no como fallo',
  worstBorder.ratio < 3
    && !Object.keys(DOCUMENTED_EXCEPTIONS).some((label) => label.includes('border')),
  `filo más flojo: ${worstBorder.source}.${worstBorder.label} = ${worstBorder.ratio.toFixed(2)}:1`);

// Informe de la medición completa, para que el resultado sea auditable desde el log.
console.log('\n[INFORME] Ratios medidos (texto sobre fondo):');
for (const pair of [...textPairs].sort((a, b) => a.ratio - b.ratio)) {
  const verdict = pair.ratio >= AA_NORMAL_TEXT ? 'AA' : 'EXCEPCIÓN DECLARADA';
  console.log(`  ${pair.ratio.toFixed(2).padStart(6)}:1  ${verdict.padEnd(20)} ${pair.source}.${pair.label} (${pair.fg} sobre ${pair.bg})`);
}

// Resumen final
console.log('\n======================================================================');
console.log(` Total Aserciones: ${assertions} | Pasadas: ${assertions - failures} | Fallidas: ${failures}`);

if (failures === 0) {
  console.log(' RESULTADO: 100% EN VERDE. RNF-04 (WCAG AA) CERTIFICADO CON EXCEPCIONES DECLARADAS.');
  console.log('======================================================================\n');
  process.exit(0);
} else {
  console.error(` RESULTADO: HAY ${failures} FALLOS.`);
  console.log('======================================================================\n');
  process.exit(1);
}
