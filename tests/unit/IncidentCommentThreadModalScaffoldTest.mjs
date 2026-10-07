/**
 * VendGuard - IncidentCommentThreadModal Scaffold Test Suite
 * (IncidentCommentThreadModalScaffoldTest.mjs)
 *
 * Verifica la condición "Hecho cuando" de T-COM-09 (módulo 10, hilo de comentarios):
 * la carcasa estructural del modal y su contrato de comunicación con las vistas que
 * lo abren (RF-01.1, RF-01.4, RNF-03).
 *
 * Hecho cuando:
 * 1. El componente Vue 3 ESM declara las props `isOpen`, `incidentId`, `ticketCode` y
 *    `role` (canal de uso) y emite `close` y `comment-added`.
 * 2. La cabecera contextual es fija y muestra el código de ticket en tipografía
 *    monoespaciada, la máquina, la sede y la insignia de estado (RF-01.4).
 * 3. El cuerpo central tiene scroll vertical independiente sobre el lienzo del sistema.
 * 4. El pie es fijo y ofrece la acción de cierre.
 * 5. La maquetación usa los tokens Docker de docs/design.md y solo depende de módulos
 *    locales (Dogma Vanilla, sin paquetes externos).
 * 6. El ciclo de apertura/cierre funciona: bloqueo de scroll, atajo Escape, cierre por
 *    fondo y liberación en el desmontaje, todo canalizado por `requestClose()` para que
 *    T-COM-12 pueda interceptar el guardián de formulario sucio.
 *
 * Dogma Vanilla: Node.js ESM nativo, cero dependencias externas, cero red. La suite
 * verifica la plantilla como artefacto (marcado, tokens y computadas) sin necesidad de
 * un DOM real; el renderizado en navegador lo cubre la compilación de plantillas.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

// Entorno mínimo de navegador para ejercitar el ciclo de apertura/cierre sin DOM real.
const registeredListeners = new Map();
globalThis.window = {
  addEventListener: (type, handler) => { registeredListeners.set(type, handler); },
  removeEventListener: (type) => { registeredListeners.delete(type); }
};
globalThis.document = { body: { style: { overflow: '' } } };

const HERE = path.dirname(fileURLToPath(import.meta.url));
const COMPONENT_PATH = path.resolve(HERE, '../../public/assets/js/components/IncidentCommentThreadModal.js');

const { IncidentCommentThreadModal } = await import('../../public/assets/js/components/IncidentCommentThreadModal.js');
const { IncidentBadge } = await import('../../public/assets/js/components/IncidentBadge.js');

const componentSource = fs.readFileSync(COMPONENT_PATH, 'utf8');
const template = String(IncidentCommentThreadModal.template || '');

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
console.log(' VendGuard: Frontend Suite - IncidentCommentThreadModal (T-COM-09)');
console.log('======================================================================\n');

/**
 * Instancia de componente con el mismo binding que hace Vue en Options API:
 * métodos enlazados a la instancia y computadas resueltas como getters.
 */
function buildInstance(overrides = {}) {
  const instance = Object.create(IncidentCommentThreadModal);
  Object.assign(instance, {
    thread: null,
    isOpen: false,
    incidentId: null,
    ticketCode: null,
    role: 'SITE_MANAGER',
    emitted: [],
    $emit(name, payload) { this.emitted.push({ name, payload }); },
    ...overrides
  });

  for (const [name, fn] of Object.entries(IncidentCommentThreadModal.methods || {})) {
    instance[name] = fn.bind(instance);
  }

  for (const [name, fn] of Object.entries(IncidentCommentThreadModal.computed || {})) {
    Object.defineProperty(instance, name, { get: () => fn.call(instance) });
  }

  return instance;
}

// ─── Grupo 1: Estructura y contrato de props/eventos (RF-01.1) ───────────────
console.log('--- Grupo 1: Estructura y contrato del componente ---');

assert('1.1 El componente exporta name, props y plantilla en cadena',
  IncidentCommentThreadModal?.name === 'IncidentCommentThreadModal'
    && typeof IncidentCommentThreadModal.template === 'string'
    && typeof IncidentCommentThreadModal.props === 'object');

assert('1.2 Declara las props isOpen, incidentId, ticketCode y role',
  IncidentCommentThreadModal.props.isOpen?.type === Boolean
    && Array.isArray(IncidentCommentThreadModal.props.incidentId?.type)
    && IncidentCommentThreadModal.props.incidentId.type.includes(Number)
    && IncidentCommentThreadModal.props.incidentId.type.includes(String)
    && IncidentCommentThreadModal.props.ticketCode?.type.includes(String)
    && IncidentCommentThreadModal.props.role?.type === String,
  `props reales: ${JSON.stringify(Object.keys(IncidentCommentThreadModal.props || {}))}`);

assert('1.3 Emite exactamente close y comment-added',
  Array.isArray(IncidentCommentThreadModal.emits)
    && IncidentCommentThreadModal.emits.length === 2
    && IncidentCommentThreadModal.emits.includes('close')
    && IncidentCommentThreadModal.emits.includes('comment-added'),
  `emits reales: ${JSON.stringify(IncidentCommentThreadModal.emits)}`);

const roleValidator = IncidentCommentThreadModal.props.role.validator;
assert('1.4 El validador de role admite los tres canales del hilo',
  roleValidator('SITE_MANAGER') === true && roleValidator('TECHNICIAN') === true && roleValidator('COORDINATOR') === true);

assert('1.5 El validador de role rechaza un perfil ajeno al hilo',
  roleValidator('ADMIN') === false && roleValidator('') === false && roleValidator('technician') === false);

assert('1.6 Reutiliza IncidentBadge para la insignia de estado (sin dependencias nuevas)',
  IncidentCommentThreadModal.components?.IncidentBadge === IncidentBadge);

// T-COM-10 conectó el visor al hilo: el transporte entra por el cliente nativo del
// proyecto (api.js) y el componente sigue sin llamar a `fetch()` por su cuenta.
assert('1.7 El transporte HTTP entra por el cliente nativo del proyecto, no por fetch directo',
  componentSource.includes("import { api } from '../api.js'")
    && !componentSource.includes('fetch(')
    && !componentSource.includes('axios'),
  'Todo el tráfico del hilo pasa por api.js (Dogma Vanilla).');

const importedModules = [...componentSource.matchAll(/^\s*import\s+[^'"]*['"]([^'"]+)['"]/gm)].map((m) => m[1]);
assert('1.8 Dogma Vanilla: todos los imports son relativos al proyecto',
  importedModules.length > 0 && importedModules.every((specifier) => specifier.startsWith('./') || specifier.startsWith('../')),
  `imports reales: ${JSON.stringify(importedModules)}`);

// ─── Grupo 2: Cabecera contextual fija (RF-01.4) ────────────────────────────
console.log('\n--- Grupo 2: Cabecera contextual fija (RF-01.4) ---');

assert('2.1 Declara la cabecera, el cuerpo y el pie con sus marcadores de prueba',
  template.includes('data-testid="incident-comment-header"')
    && template.includes('data-testid="incident-comment-body"')
    && template.includes('data-testid="incident-comment-footer"'));

assert('2.2 El código de ticket se pinta en tipografía monoespaciada',
  /class="font-monospace incident-comment-code"/.test(template)
    && template.includes('data-testid="incident-comment-code"'));

assert('2.3 La cabecera integra máquina, sede e insignia de estado',
  template.includes('data-testid="incident-comment-machine"')
    && template.includes('data-testid="incident-comment-location"')
    && /<IncidentBadge[^>]*:value="headerStatus"/.test(template));

assert('2.4 La cabecera es fija (flex: 0 0 auto) y no participa del scroll',
  /data-testid="incident-comment-header"\s*\n?\s*style="flex: 0 0 auto/.test(template.replace(/\s+style=/g, ' style=')),
  'La cabecera debe declarar flex: 0 0 auto en su estilo.');

assert('2.5 La cabecera muestra el canal de uso del hilo',
  template.includes('data-testid="incident-comment-channel"'));

assert('2.6 La cabecera cierra el diálogo con una acción explícita',
  template.includes('data-testid="incident-comment-close"')
    && template.includes('aria-label="Cerrar el hilo de conversación"'));

// ─── Grupo 3: Cuerpo con scroll propio y pie fijo (RNF-03) ───────────────────
console.log('\n--- Grupo 3: Cuerpo con scroll propio y pie fijo (RNF-03) ---');

assert('2.7 El cuerpo declara scroll vertical independiente',
  /data-testid="incident-comment-body"[^>]*overflow-y: auto/.test(template)
    && /data-testid="incident-comment-body"[^>]*min-height: 0/.test(template));

assert('2.8 El cuerpo se apoya en el lienzo del sistema (canvas #f9fafb)',
  /data-testid="incident-comment-body"[^>]*background-color: var\(--color-canvas, #f9fafb\)/.test(template));

assert('2.9 El pie es fijo y ofrece la acción de cierre',
  /data-testid="incident-comment-footer"\s*\n?\s*style="flex: 0 0 auto/.test(template.replace(/\s+style=/g, ' style='))
    && template.includes('data-testid="incident-comment-footer-close"'));

assert('2.10 El marco usa los tokens Docker de tarjeta, borde y radios (docs/design.md)',
  template.includes('border-radius: var(--radius-card, 8px)')
    && template.includes('border: 1px solid var(--color-hairline, #c8cfda)')
    && template.includes('border-radius: var(--radius-interactive, 4px)')
    && template.includes("var(--font-body, Inter, sans-serif)"));

// ─── Grupo 4: Cabecera reactiva antes de que el hilo esté cargado ────────────
console.log('\n--- Grupo 4: Cabecera reactiva y datos del expediente ---');

assert('4.1 Sin hilo ni identificadores la cabecera muestra el marcador vacío',
  buildInstance().headerTicketCode === '#—');

assert('4.2 Con incidentId numérico la cabecera compone el código de ticket',
  buildInstance({ incidentId: 142 }).headerTicketCode === '#142');

assert('4.3 Con ticketCode el identificador visible es el código comercial',
  buildInstance({ ticketCode: 'TICK-2026-00142' }).headerTicketCode === '#TICK-2026-00142');

assert('4.4 El prefijo # del código recibido no se duplica',
  buildInstance({ ticketCode: '#TICK-2026-00142' }).headerTicketCode === '#TICK-2026-00142');

assert('4.5 El hilo cargado manda sobre el identificador recibido por props',
  buildInstance({
    ticketCode: 'TICK-ANTIGUO',
    thread: { incident: { ticket_code: 'TICK-2026-00142' } }
  }).headerTicketCode === '#TICK-2026-00142');

assert('4.6 La máquina se muestra con su modelo y queda vacía sin hilo',
  buildInstance({
    thread: { incident: { machine_code: 'VEN-BCN-001', machine_model: 'CoffeMax Pro 3000' } }
  }).headerMachineLabel === 'VEN-BCN-001 · CoffeMax Pro 3000'
    && buildInstance().headerMachineLabel === '');

assert('4.7 La sede del expediente viaja en la cabecera y queda vacía sin hilo',
  buildInstance({ thread: { incident: { location_name: 'Hospital del Mar' } } }).headerLocationLabel === 'Hospital del Mar'
    && buildInstance().headerLocationLabel === '');

assert('4.8 La insignia de estado no inventa un estado mientras no hay hilo',
  buildInstance().headerStatus === ''
    && buildInstance({ thread: { incident: { status: 'IN_PROGRESS' } } }).headerStatus === 'IN_PROGRESS');

assert('4.9 El canal activo se rotula en castellano para cada perfil',
  buildInstance({ role: 'SITE_MANAGER' }).roleLabel === 'Responsable de Sede'
    && buildInstance({ role: 'TECHNICIAN' }).roleLabel === 'Técnico de Ruta'
    && buildInstance({ role: 'COORDINATOR' }).roleLabel === 'Coordinación');

// ─── Grupo 5: Ciclo de apertura/cierre y guardián futuro (T-COM-12) ──────────
console.log('\n--- Grupo 5: Ciclo de apertura/cierre ---');

const closable = buildInstance({ isOpen: true });
closable.requestClose();
assert('5.1 requestClose emite close hacia la vista que abrió el hilo',
  closable.emitted.length === 1 && closable.emitted[0].name === 'close');

const openWithEscape = buildInstance({ isOpen: true });
openWithEscape.handleKeyDown({ key: 'Escape' });
assert('5.2 La tecla Escape solicita el cierre cuando el hilo está abierto',
  openWithEscape.emitted.length === 1 && openWithEscape.emitted[0].name === 'close');

const closedWithEscape = buildInstance({ isOpen: false });
closedWithEscape.handleKeyDown({ key: 'Escape' });
assert('5.3 Escape no hace nada con el hilo cerrado',
  closedWithEscape.emitted.length === 0);

const backdropModal = buildInstance({ isOpen: true });
const backdropTarget = { id: 'backdrop' };
backdropModal.handleBackdropClick({ target: backdropTarget, currentTarget: backdropTarget });
assert('5.4 El clic en el fondo sombreado solicita el cierre',
  backdropModal.emitted.length === 1 && backdropModal.emitted[0].name === 'close');

const innerClickModal = buildInstance({ isOpen: true });
innerClickModal.handleBackdropClick({ target: { id: 'panel' }, currentTarget: { id: 'backdrop' } });
assert('5.5 Un clic dentro del panel no cierra el hilo',
  innerClickModal.emitted.length === 0);

const lifecycle = buildInstance();
lifecycle.handleOpenState(true);
assert('5.6 Al abrir se bloquea el scroll del fondo y se registra el atajo de teclado',
  globalThis.document.body.style.overflow === 'hidden' && registeredListeners.has('keydown'));

lifecycle.handleOpenState(false);
assert('5.7 Al cerrar se libera el scroll del fondo y se retira el atajo',
  globalThis.document.body.style.overflow === '' && !registeredListeners.has('keydown'));

lifecycle.handleOpenState(true);
lifecycle.releaseScrollLock();
assert('5.8 El desmontaje libera scroll y atajo aunque el modal siga abierto',
  globalThis.document.body.style.overflow === '' && !registeredListeners.has('keydown'));

assert('5.9 Todas las vías de cierre pasan por requestClose (punto de intercepción de T-COM-12)',
  (template.match(/@click="requestClose"|@click\.self="handleBackdropClick"|@click="handleBackdropClick"/g) || []).length >= 3
    && componentSource.includes('requestClose()'));

const selfCloseModal = buildInstance({ isOpen: true });
selfCloseModal.handleKeyDown({ key: 'Esc' });
assert('5.10 Otras teclas no cierran el hilo', selfCloseModal.emitted.length === 0);

// ─── Grupo 6: Elementos declarados para las tareas siguientes ────────────────
console.log('\n--- Grupo 6: Seams de las tareas siguientes ---');

assert('6.1 El evento comment-added queda declarado para el formulario de T-COM-11',
  IncidentCommentThreadModal.emits.includes('comment-added'));

assert('6.2 Existe el lienzo del hilo para el visor cronológico de T-COM-10',
  template.includes('data-testid="incident-comment-thread"') && template.includes('aria-live="polite"'));

assert('6.3 El estado vacío usa el texto de la especificación (§6.1)',
  template.includes('Aún no hay mensajes en esta incidencia. Inicie la conversación con el equipo técnico.'));

assert('6.4 El estado del expediente se lee del DTO del hilo (is_sealed llegará con T-COM-12)',
  componentSource.includes('this.thread?.incident?.status')
    && typeof IncidentCommentThreadModal.computed.headerStatus === 'function');

// ─── Grupo 7: Sanidad del marcado de la carcasa ──────────────────────────────
console.log('\n--- Grupo 7: Sanidad del marcado ---');

/**
 * Comprueba que ninguna etiqueta de la plantilla quede abierta o mal cerrada.
 * El proyecto ya se comió una vez un fallo de plantilla que no se veía hasta abrir
 * la vista en el navegador; este control lo detecta en la batería.
 *
 * @param {string} markup
 * @returns {string|null} el primer desajuste encontrado, o null si está equilibrado
 */
function findTagMismatch(markup) {
  const voidTags = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
  const withoutComments = markup.replace(/<!--[\s\S]*?-->/g, '');
  const stack = [];
  const tagPattern = /<\/?([a-zA-Z][a-zA-Z0-9-]*)([^>]*)>/g;
  let match;

  while ((match = tagPattern.exec(withoutComments)) !== null) {
    const raw = match[0];
    const name = match[1];

    if (raw.startsWith('</')) {
      const opened = stack.pop();
      if (opened !== name) {
        return `cierre </${name}> inesperado (etiqueta abierta: <${opened ?? '—'}>)`;
      }
    } else if (!raw.endsWith('/>') && !voidTags.has(name.toLowerCase())) {
      stack.push(name);
    }
  }

  return stack.length === 0 ? null : `etiquetas sin cerrar: ${stack.join(', ')}`;
}

const tagMismatch = findTagMismatch(template);
assert('7.1 La plantilla abre y cierra todas sus etiquetas',
  tagMismatch === null,
  `desajuste: ${tagMismatch}`);

const headerIndex = template.indexOf('data-testid="incident-comment-header"');
const bodyIndex = template.indexOf('data-testid="incident-comment-body"');
const footerIndex = template.indexOf('data-testid="incident-comment-footer"');
assert('7.2 El orden estructural dentro del diálogo es cabecera → cuerpo → pie',
  headerIndex > -1 && headerIndex < bodyIndex && bodyIndex < footerIndex,
  `índices: header=${headerIndex}, body=${bodyIndex}, footer=${footerIndex}`);

assert('7.3 El diálogo declara su rol accesible y el bloqueo de scroll del fondo',
  template.includes('role="dialog"')
    && template.includes('aria-modal="true"')
    && componentSource.includes("document.body.style.overflow = isOpen ? 'hidden' : ''"));

// ---------------------------------------------------------------------
// 8. RNF-03 · Cabecera responsive para uso a una mano en pantallas estrechas
//
// Trampa de regresión nacida de la verificación visual H-2 (390×844): el bloque
// `.modal-header` es `nowrap` por defecto y las etiquetas de contexto llevaban un
// `max-width: 260px` FIJO, mayor que la columna disponible en un móvil. En esa
// combinación el texto se salía por la izquierda de la tarjeta (x=5,7px frente al
// borde del modal en x=24px) y se imprimía encima del código de ticket. La cabecera
// debe envolver sus dos columnas y cada etiqueta debe elipsar contra su columna.
// ---------------------------------------------------------------------
console.log('\n--- Grupo 8: Cabecera responsive para móvil (RNF-03 · verificación H-2) ---');

const headerBlock = template.slice(headerIndex, template.indexOf('</header>', headerIndex));

assert('8.1 La cabecera envuelve sus columnas en lugar de comprimirlas (RNF-03)',
  /flex-wrap:\s*wrap/.test(headerBlock),
  'sin `flex-wrap: wrap` las dos columnas se comprimen y sus textos se solapan en 390px');

assert('8.2 La columna contextual puede encogerse dentro de la cabecera',
  /class="incident-comment-context"[\s\S]{0,220}min-width:\s*0/.test(headerBlock),
  'sin `min-width: 0` la columna no reduce su ancho y desborda la tarjeta');

const elasticLabels = headerBlock.match(/max-width:\s*100%/g) || [];
assert('8.3 Máquina y sede elipsan contra el ancho de su columna, no contra un valor fijo',
  elasticLabels.length === 2,
  `se esperaban 2 etiquetas con max-width: 100%, encontradas ${elasticLabels.length}`);

assert('8.4 Ninguna etiqueta de la cabecera conserva un ancho máximo fijo que desborde en móvil',
  !/max-width:\s*\d+px/.test(headerBlock),
  'un `max-width` en píxeles vuelve a provocar el desbordamiento medido en 390px');

// Medición real en 390×844 con la captura por cámara (H-4) ya presente: la barra de
// acciones envuelve en dos filas y, sin el anclaje, `justify-content: space-between`
// alinea a la izquierda la fila de un solo elemento, dejando el botón «Enviar» en el
// centro-izquierda (x=117,8 medido) en vez de la esquina inferior derecha, fuera del
// alcance cómodo del pulgar. El grupo de acciones debe anclarse al borde derecho.
const actionBarStart = template.indexOf('<!-- Barra de acciones');
const actionBarBlock = template.slice(
  actionBarStart,
  template.indexOf('data-testid="incident-comment-footer-close"', actionBarStart)
);
assert('8.5 El grupo de acciones se ancla al borde derecho aunque la barra envuelva (RNF-03)',
  /flex-wrap:\s*wrap/.test(actionBarBlock) && /margin-left:\s*auto/.test(actionBarBlock),
  'sin `margin-left: auto` la acción principal pierde la esquina inferior derecha al envolver');

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
console.log('\n======================================================================');
console.log(` Total Assertions: ${assertions} | Passed: ${assertions - failures} | Failed: ${failures}`);

if (failures === 0) {
  console.log(' RESULT: 100% IN GREEN. CONDITION T-COM-09 FULFILLED.');
  console.log('======================================================================\n');
  process.exit(0);
}

console.log(' RESULT: FAILURES DETECTED IN TEST SUITE.');
console.log('======================================================================\n');
process.exit(1);
