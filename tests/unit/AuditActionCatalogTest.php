<?php

declare(strict_types=1);

/**
 * VendGuard - Audit action vocabulary guard (audit_log.action)
 *
 * Certifies that the action vocabulary the backend actually persists into
 * `audit_log` and the catalogue the coordinator's audit viewer offers
 * (row badges, dropdown options and grouping) are exactly the same set.
 *
 * Two classes of defect are covered:
 *
 *  1. Phantom filter: the viewer offered options such as ASSIGN_TECHNICIAN or
 *     CANCEL_INCIDENT that no code path ever writes, so selecting them always
 *     returned zero rows. A filter that can never match is a broken promise to
 *     the coordinator (EARS 5.5), not a harmless extra option.
 *
 *  2. Invisible action: the backend wrote dozens of actions the viewer could
 *     neither label nor filter, so the immutable log showed raw enum strings.
 *
 * The vocabulary is derived from the writers themselves - the literals passed
 * to `AuditLogger::log*Event()` (positional or named argument) plus the values
 * the seeders insert into `audit_log` - instead of a second hand-kept list, so
 * this guard cannot drift away from the code it protects. Any action argument
 * that is not a plain literal is reported as a dynamic call site and must be
 * explicitly acknowledged below, because a computed action could otherwise
 * escape the extraction unnoticed.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Core\Domain\Model\AuditEvent;

$assertions = 0;
$failures = 0;

$assert = function (string $label, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$label}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
};

/**
 * Dynamic (non-literal) action call sites explicitly accepted by this guard.
 * A new entry means a computed action value was introduced, which the
 * extraction below cannot enumerate; it must be reviewed by hand.
 */
const KNOWN_DYNAMIC_ACTION_SITES = [
    // El triaje fusiona la auditoría con la asignación: `buildTicketEvent()`
    // recibe la acción ya calculada según haya reasignación o no. Las dos ramas
    // del ternario sí se enumeran como literales, así que la guarda las extrae;
    // el sitio se declara porque la expresión no es un literal plano.
    'src/Presentation/Controller/CoordinatorController.php::buildTicketEvent',
    // Módulo 16: `logOpportunisticEvent()` valida la acción contra su catálogo
    // cerrado y delega en `logTicketEvent()` con el código ya normalizado. La
    // delegación es dinámica por diseño; las seis acciones reales se enumeran
    // como literales en cada llamada de `OpportunisticIncidentService` y este
    // guard las extrae de allí.
    'src/Application/Service/AuditLogger.php::logTicketEvent',
    // Misma delegación: `logTicketEvent()` compone el evento con
    // `buildTicketEvent(action: $action)` y lo persiste. La acción real la
    // enumeran los llamadores del servicio, no esta capa.
    'src/Application/Service/AuditLogger.php::buildTicketEvent',
];

/** AuditLogger writers whose second argument (or `action:` named argument) is the action. */
const AUDIT_WRITER_METHODS = [
    'logTicketEvent',
    // El triaje fusionado compone el evento antes del cambio y lo entrega al
    // repositorio en la misma sentencia; la firma de la acción es la misma.
    'buildTicketEvent',
    'logMachineEvent',
    'logLocationEvent',
    'logUserEvent',
    'logPreventiveOrderEvent',
    'logSanitaryCertificateEvent',
    'logRefundEvent',
    'logUnclaimedCashEvent',
    'logNotificationOutboxEvent',
    'logNotificationChannelSettingEvent',
    // Módulo 16: firma de los seis hechos de la avería oportunista in situ.
    'logOpportunisticEvent',
];

/**
 * Divide la lista de argumentos de una llamada cuyo paréntesis de apertura ocupa
 * `$openIndex`, respetando el anidamiento de paréntesis, corchetes y llaves.
 *
 * @param list<mixed> $tokens Flujo de `token_get_all()`.
 * @param int $openIndex Índice del token `(` que abre la llamada.
 * @return list<list<mixed>> Argumentos en crudo, en orden posicional.
 */
function splitCallArguments(array $tokens, int $openIndex): array
{
    $count = count($tokens);
    $depth = 1;
    $arguments = [];
    $current = [];

    for ($k = $openIndex + 1; $k < $count; $k++) {
        $inner = $tokens[$k];
        $text = is_array($inner) ? $inner[1] : $inner;
        if (in_array($text, ['(', '[', '{'], true)) {
            $depth++;
        } elseif (in_array($text, [')', ']', '}'], true)) {
            $depth--;
            if ($depth === 0) {
                $arguments[] = $current;
                break;
            }
        } elseif ($text === ',' && $depth === 1) {
            $arguments[] = $current;
            $current = [];
            continue;
        }
        $current[] = $inner;
    }

    return $arguments;
}

/**
 * Localiza el argumento de la acción: el nombrado `action:` manda; si no existe,
 * se toma la posición indicada.
 *
 * @param list<list<mixed>> $arguments Argumentos devueltos por `splitCallArguments()`.
 * @param int $position Posición de la acción en la firma posicional.
 * @return list<mixed>|null Tokens del argumento, ya sin el nombre, o `null`.
 */
function resolveActionArgument(array $arguments, int $position): ?array
{
    foreach ($arguments as $argument) {
        $clean = array_values(array_filter($argument, static fn($tk): bool => !(is_array($tk)
            && in_array($tk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))));
        if (isset($clean[0], $clean[1]) && is_array($clean[0]) && $clean[0][0] === T_STRING
            && $clean[0][1] === 'action' && $clean[1] === ':') {
            return array_slice($argument, array_search(':', $argument, true) + 1);
        }
    }

    return $arguments[$position] ?? null;
}

/**
 * Reduce la expresión de la acción a su forma analizable por la guarda.
 *
 * @param list<mixed> $expression Tokens del argumento de acción.
 * @return array{plain: ?string, literals: list<string>, flat: string}
 *         plain:    el literal único si la expresión es un literal plano
 *         literals: todos los literales contenidos (ramas de un ternario, etc.)
 *         flat:     texto de la expresión, para el informe de sitios dinámicos
 */
function analyseActionExpression(array $expression): array
{
    $clean = array_values(array_filter($expression, static fn($tk): bool => !(is_array($tk)
        && in_array($tk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))));

    $literals = [];
    $flat = '';
    foreach ($clean as $tk) {
        if (is_array($tk) && $tk[0] === T_CONSTANT_ENCAPSED_STRING) {
            $literals[] = trim($tk[1], "'\"");
        }
        $flat .= is_array($tk) ? $tk[1] : $tk;
    }

    return [
        'plain' => count($clean) === 1 && count($literals) === 1 ? $literals[0] : null,
        'literals' => $literals,
        'flat' => $flat,
    ];
}

/**
 * Extracts every audit action a PHP source can persist.
 *
 * Los hechos territoriales del Módulo 17 no pasan por `AuditLogger`: el repositorio
 * construye el `AuditEvent` y lo entrega al repositorio de auditoría dentro de su
 * propia transacción. Por eso la extracción cubre también `new AuditEvent(...)`
 * (cuarto argumento posicional o `action:` nombrado); si solo mirase los métodos de
 * `AuditLogger`, esas nuevas acciones se leerían como filtros fantasma del visor.
 *
 * @return array{literals: array<string, string>, dynamic: array<string, string>}
 *         literals: action => first call site that writes it
 *         dynamic:  site   => source expression that could not be enumerated
 */
function extractAuditActions(string $source, string $relativePath): array
{
    $literals = [];
    $dynamic = [];
    $tokens = token_get_all($source);
    $count = count($tokens);

    $isSkippable = static fn($token): bool => is_array($token)
        && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);

    $register = static function (array $expression, string $site) use (&$literals, &$dynamic): void {
        $analysis = analyseActionExpression($expression);

        if ($analysis['plain'] !== null) {
            $literals[$analysis['plain']] = $literals[$analysis['plain']] ?? $site;
            return;
        }

        foreach ($analysis['literals'] as $literal) {
            $literals[$literal] = $literals[$literal] ?? $site . ' (expression)';
        }
        $dynamic[$site] = $analysis['flat'];
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], AUDIT_WRITER_METHODS, true)) {
            continue;
        }

        // Skip the declarations themselves (AuditLogger defines these methods).
        $before = $i - 1;
        while ($before >= 0 && $isSkippable($tokens[$before])) {
            $before--;
        }
        if ($before >= 0 && is_array($tokens[$before]) && $tokens[$before][0] === T_FUNCTION) {
            continue;
        }

        $cursor = $i + 1;
        while ($cursor < $count && $isSkippable($tokens[$cursor])) {
            $cursor++;
        }
        if ($cursor >= $count || $tokens[$cursor] !== '(') {
            continue;
        }

        $site = $relativePath . '::' . $token[1];
        $expression = resolveActionArgument(splitCallArguments($tokens, $cursor), 1);
        if ($expression === null) {
            $dynamic[$site] = '(no action argument found)';
            continue;
        }

        $register($expression, $site);
    }

    // Escrituras directas: el evento se construye y se entrega sin `AuditLogger`.
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_NEW) {
            continue;
        }

        $cursor = $i + 1;
        while ($cursor < $count && $isSkippable($tokens[$cursor])) {
            $cursor++;
        }
        if ($cursor >= $count || !is_array($tokens[$cursor]) || $tokens[$cursor][0] !== T_STRING
            || $tokens[$cursor][1] !== 'AuditEvent') {
            continue;
        }

        $open = $cursor + 1;
        while ($open < $count && $isSkippable($tokens[$open])) {
            $open++;
        }
        if ($open >= $count || $tokens[$open] !== '(') {
            continue;
        }

        $site = $relativePath . '::new AuditEvent';
        // Firma posicional: id, entityType, entityId, action.
        $expression = resolveActionArgument(splitCallArguments($tokens, $open), 3);
        if ($expression === null) {
            continue;
        }

        // Solo interesa la escritura con acción literal. Una acción calculada aquí es
        // hidratación (`$row['action']` en `PdoAuditLogRepository`) o la delegación de
        // `AuditLogger::log()`, que escribe lo que ya enumeró otra llamada; tratarlas
        // como sitios dinámicos obligaría a declarar lecturas como si fueran escrituras.
        $analysis = analyseActionExpression($expression);
        if ($analysis['plain'] === null) {
            continue;
        }

        $literals[$analysis['plain']] = $literals[$analysis['plain']] ?? $site;
    }

    // Acciones transportadas por el parámetro interno `auditAction:`.
    // `ShiftHandoverService::executeClose()` recibe el código y lo entrega a
    // `new AuditEvent(action: $auditAction)`; sin esta pasada, CLOSE_TECHNICIAN_SHIFT
    // y FORCE_CLOSE_TECHNICIAN_SHIFT se leían como filtros fantasma.
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'auditAction') {
            continue;
        }

        $colon = $i + 1;
        while ($colon < $count && $isSkippable($tokens[$colon])) {
            $colon++;
        }
        if ($colon >= $count || $tokens[$colon] !== ':') {
            continue;
        }

        $expression = [];
        $depth = 0;
        for ($k = $colon + 1; $k < $count; $k++) {
            $inner = $tokens[$k];
            $text = is_array($inner) ? $inner[1] : $inner;
            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                break;
            }
            $expression[] = $inner;
        }

        if ($expression !== []) {
            $register($expression, $relativePath . '::auditAction');
        }
    }

    // Raw inserts into audit_log (demo/metric seeders).
    if (preg_match_all("/action'\s*=>\s*'([A-Z][A-Z0-9_]+)'/", $source, $matches)) {
        foreach ($matches[1] as $literal) {
            $literals[$literal] = $literals[$literal] ?? $relativePath . ' (raw insert)';
        }
    }

    // Acciones que un escritor directo de `audit_log` declara como constante
    // propia y entrega por parámetro (`recordAudit(action: self::ACTION_X)`).
    // Sin esta pasada, Módulo 24 escribía OFFLINE_CONFLICT_DUMPED y
    // OFFLINE_DEACTIVATED_ACCOUNT_DUMP con un valor ligado a la consulta, y el
    // catálogo del visor se leía como dos filtros fantasma cuando sí tenían autor.
    if (str_contains($source, 'INSERT INTO audit_log')
        && preg_match_all("/const\s+ACTION_[A-Z0-9_]*\s*=\s*'([A-Z][A-Z0-9_]+)'/", $source, $matches)) {
        foreach ($matches[1] as $literal) {
            $literals[$literal] = $literals[$literal] ?? $relativePath . ' (action constant)';
        }
    }

    // Módulo 24: `PdoOfflineSyncMutationRepository::recordAppliedMutation()`
    // transcribe `operationType` a `audit_log.action`, y los valores posibles son
    // la lista cerrada `OfflineSyncMutation::OPERATION_*`. Sin esta pasada esas
    // filas no tenían escritor enumerable y las nueve operaciones offline se
    // leían como filtros fantasma en cuanto el visor las ofrecía.
    if (str_contains($source, 'public const OPERATION_')
        && preg_match_all("/public const OPERATION_[A-Z0-9_]*\s*=\s*'([A-Z][A-Z0-9_]+)'/", $source, $matches)) {
        foreach ($matches[1] as $literal) {
            $literals[$literal] = $literals[$literal] ?? $relativePath . ' (offline operation)';
        }
    }

    return ['literals' => $literals, 'dynamic' => $dynamic];
}

/** @return array{literals: array<string, string>, dynamic: array<string, string>} */
function extractAuditActionsFromTree(string $baseDir, array $roots): array
{
    $literals = [];
    $dynamic = [];

    foreach ($roots as $root) {
        $directory = $baseDir . '/' . $root;
        if (!is_dir($directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        foreach ($files as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen($baseDir) + 1));
            $found = extractAuditActions((string)file_get_contents($path), $relative);
            foreach ($found['literals'] as $action => $site) {
                $literals[$action] = $literals[$action] ?? $site;
            }
            foreach ($found['dynamic'] as $site => $expression) {
                $dynamic[$site] = $expression;
            }
        }
    }

    ksort($literals);
    ksort($dynamic);

    return ['literals' => $literals, 'dynamic' => $dynamic];
}

/**
 * Reads the single source of truth of the browser catalogue.
 *
 * @return array{actions: array<string, array{label: string, tone: string, group: string}>,
 *               tones: string[], groups: array<string, string>, entities: array<string, string>}
 */
function parseAuditCatalogue(string $source): array
{
    $actions = [];
    if (preg_match_all('/\{[^{}]*code:\s*\'([A-Z][A-Z0-9_]*)\'[^{}]*\}/', $source, $entries, PREG_SET_ORDER)) {
        foreach ($entries as $entry) {
            $block = $entry[0];
            $label = preg_match('/label:\s*\'([^\']*)\'/', $block, $m) ? $m[1] : '';
            $tone = preg_match('/tone:\s*\'([a-z]+)\'/', $block, $m) ? $m[1] : '';
            $group = preg_match('/group:\s*\'([a-z_]+)\'/', $block, $m) ? $m[1] : '';
            $actions[$entry[1]] = ['label' => $label, 'tone' => $tone, 'group' => $group];
        }
    }

    $tones = [];
    if (preg_match('/TONES = Object\.freeze\(\{(.*?)\n\}\);/s', $source, $block)) {
        if (preg_match_all('/^\s*([a-z]+):\s*\{\s*bg:/m', $block[1], $matches)) {
            $tones = $matches[1];
        }
    }

    $groups = [];
    if (preg_match('/AUDIT_ACTION_GROUPS = Object\.freeze\(\{(.*?)\n\}\);/s', $source, $block)) {
        if (preg_match_all('/^\s*([a-z_]+):\s*\'([^\']+)\'/m', $block[1], $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $groups[$match[1]] = $match[2];
            }
        }
    }

    $entities = [];
    if (preg_match('/AUDIT_ENTITY_LABELS = Object\.freeze\(\{(.*?)\n\}\);/s', $source, $block)) {
        if (preg_match_all('/^\s*([A-Z_]+):\s*\'([^\']+)\'/m', $block[1], $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $entities[$match[1]] = $match[2];
            }
        }
    }

    return ['actions' => $actions, 'tones' => $tones, 'groups' => $groups, 'entities' => $entities];
}

$writer = extractAuditActionsFromTree($baseDir, ['src', 'database']);
$backendActions = $writer['literals'];

$cataloguePath = '/public/assets/js/utils/AuditActionLabels.js';
$catalogueSource = is_file($baseDir . $cataloguePath) ? (string)file_get_contents($baseDir . $cataloguePath) : '';
$catalogue = parseAuditCatalogue($catalogueSource);
$catalogueActions = $catalogue['actions'];

echo "\n--- 0. La extracción no es vacua (prueba de que la guarda puede fallar) ---\n";

$assert(
    '0.1 El extractor enumera el vocabulario del backend (>= 50 acciones)',
    count($backendActions) >= 50,
    'Acciones extraídas: ' . count($backendActions)
);

$assert(
    '0.2 El extractor resuelve argumentos nombrados (action: REOPEN_TICKET)',
    isset($backendActions['REOPEN_TICKET'])
);

$assert(
    '0.3 El extractor resuelve las inserciones directas de los sembradores (TICKET_CREATED)',
    isset($backendActions['TICKET_CREATED'])
);

$assert(
    '0.4 El extractor resuelve las dos ramas de una expresión ternaria (INCIDENT_ASSIGNED + INCIDENT_REASSIGNED)',
    isset($backendActions['INCIDENT_ASSIGNED'], $backendActions['INCIDENT_REASSIGNED'])
);

$syntheticAuditAction = "<?php\n\$service->executeClose(auditAction: 'SYNTHETIC_AUDIT_ACTION_PROBE');\n";
$syntheticAuditActionFound = extractAuditActions($syntheticAuditAction, 'synthetic');
$assert(
    '0.5 El extractor resuelve el parámetro interno auditAction:',
    isset($syntheticAuditActionFound['literals']['SYNTHETIC_AUDIT_ACTION_PROBE'])
);

$syntheticDrift = "<?php\n\$logger->logTicketEvent(\n    ticketId: 1,\n    action: 'SYNTHETIC_DRIFT_PROBE',\n    user: [],\n    previousState: null,\n    newState: []\n);\n";
$syntheticFound = extractAuditActions($syntheticDrift, 'synthetic');
$assert(
    '0.5 Una acción nueva añadida al backend sería detectada por esta guarda',
    isset($syntheticFound['literals']['SYNTHETIC_DRIFT_PROBE']),
    'La extracción no detectó la acción sintética inyectada'
);

echo "\n--- 1. Sitios con acción calculada (no enumerable) ---\n";

$dynamicSites = array_keys($writer['dynamic']);
$undeclaredDynamic = array_values(array_diff($dynamicSites, KNOWN_DYNAMIC_ACTION_SITES));

$assert(
    '1.1 Toda acción calculada está declarada explícitamente en la guarda',
    $undeclaredDynamic === [],
    'Sitios sin declarar: ' . implode(', ', $undeclaredDynamic)
);

$assert(
    '1.2 Los sitios declarados siguen existiendo (sin entradas obsoletas)',
    array_diff(KNOWN_DYNAMIC_ACTION_SITES, $dynamicSites) === [],
    'Sitios declarados que ya no existen: ' . implode(', ', array_diff(KNOWN_DYNAMIC_ACTION_SITES, $dynamicSites))
);

echo "\n--- 2. Catálogo de la interfaz (fuente única de verdad) ---\n";

$assert(
    '2.1 El catálogo de acciones existe y declara entradas',
    $catalogueActions !== [],
    'No se pudo leer public/assets/js/utils/AuditActionLabels.js'
);

$missingInCatalogue = array_values(array_diff(array_keys($backendActions), array_keys($catalogueActions)));
$assert(
    '2.2 Toda acción que el backend escribe tiene etiqueta y filtro en el visor',
    $missingInCatalogue === [],
    'Acciones sin catalogar: ' . implode(', ', $missingInCatalogue)
);

$missingInBackend = array_values(array_diff(array_keys($catalogueActions), array_keys($backendActions)));
$assert(
    '2.3 El catálogo no ofrece opciones que el backend no escriba jamás (filtros fantasma)',
    $missingInBackend === [],
    'Opciones sin escritor real: ' . implode(', ', $missingInBackend)
);

$codes = array_keys($catalogueActions);
$assert(
    '2.4 Ningún código de acción se declara dos veces',
    count($codes) === count(array_unique($codes))
);

$malformed = array_values(array_filter($codes, static fn(string $code): bool => preg_match('/^[A-Z][A-Z0-9_]*$/', $code) !== 1));
$assert(
    '2.5 Todos los códigos respetan el formato de audit_log.action',
    $malformed === [],
    'Códigos mal formados: ' . implode(', ', $malformed)
);

$emptyLabels = array_values(array_filter($codes, static fn(string $code): bool => trim($catalogueActions[$code]['label']) === ''));
$assert(
    '2.6 Ninguna entrada se queda sin etiqueta en español',
    $emptyLabels === [],
    'Entradas sin etiqueta: ' . implode(', ', $emptyLabels)
);

$labels = array_map(static fn(array $entry): string => $entry['label'], $catalogueActions);
$duplicatedLabels = array_keys(array_filter(array_count_values($labels), static fn(int $count): bool => $count > 1));
$assert(
    '2.7 Ninguna etiqueta se repite (el desplegable no es ambiguo)',
    $duplicatedLabels === [],
    'Etiquetas repetidas: ' . implode(', ', $duplicatedLabels)
);

$unknownTones = [];
foreach ($catalogueActions as $code => $entry) {
    if (!in_array($entry['tone'], $catalogue['tones'], true)) {
        $unknownTones[] = $code . ' => ' . $entry['tone'];
    }
}
$assert(
    '2.8 Toda entrada usa un tono declarado en la paleta del visor',
    $unknownTones === [] && $catalogue['tones'] !== [],
    'Tonos desconocidos: ' . implode(', ', $unknownTones)
);

$unknownGroups = [];
$groupUsage = [];
foreach ($catalogueActions as $code => $entry) {
    if (!array_key_exists($entry['group'], $catalogue['groups'])) {
        $unknownGroups[] = $code . ' => ' . $entry['group'];
    }
    $groupUsage[$entry['group']] = ($groupUsage[$entry['group']] ?? 0) + 1;
}
$assert(
    '2.9 Toda entrada pertenece a un grupo declarado del desplegable',
    $unknownGroups === [] && $catalogue['groups'] !== [],
    'Grupos desconocidos: ' . implode(', ', $unknownGroups)
);

$emptyGroups = array_keys(array_diff_key($catalogue['groups'], $groupUsage));
$assert(
    '2.10 Ningún grupo del desplegable queda vacío',
    $emptyGroups === [],
    'Grupos sin acciones: ' . implode(', ', $emptyGroups)
);

echo "\n--- 3. El visor deriva del catálogo (sin listas paralelas) ---\n";

$viewerPath = $baseDir . '/public/assets/js/components/AuditLogViewer.js';
$viewerSource = (string)file_get_contents($viewerPath);

$assert(
    '3.1 El visor importa el catálogo compartido',
    str_contains($viewerSource, "from '../utils/AuditActionLabels.js'")
);

$hardcodedOptions = [];
foreach ($codes as $code) {
    if (str_contains($viewerSource, '<option value="' . $code . '"')) {
        $hardcodedOptions[] = $code;
    }
}
$assert(
    '3.2 El visor no mantiene su propia lista de opciones de acción',
    $hardcodedOptions === [],
    'Opciones duplicadas a mano: ' . implode(', ', $hardcodedOptions)
);

$assert(
    '3.3 El visor no mantiene su propia paleta de insignias',
    !str_contains($viewerSource, '#dcfce7') && !str_contains($viewerSource, '#f3e8ff'),
    'El visor conserva colores de insignia escritos a mano'
);

$expectedEntities = AuditEvent::ENTITY_TYPES;
sort($expectedEntities);

$hardcodedEntities = [];
foreach ($expectedEntities as $entityType) {
    if (str_contains($viewerSource, '<option value="' . $entityType . '"')) {
        $hardcodedEntities[] = $entityType;
    }
}
$assert(
    '3.4 El visor tampoco mantiene su propia lista de entidades (filtro derivado)',
    $hardcodedEntities === [] && str_contains($viewerSource, 'entityOptions'),
    'Entidades escritas a mano: ' . implode(', ', $hardcodedEntities)
);

$catalogueEntities = array_keys($catalogue['entities']);
sort($catalogueEntities);
$assert(
    '3.5 El catálogo de entidades coincide exactamente con el enum de audit_log',
    $catalogueEntities === $expectedEntities,
    'Catálogo: ' . implode(', ', $catalogueEntities) . ' | Enum: ' . implode(', ', $expectedEntities)
);

echo "\n--- 4. El vocabulario persistido coincide con el catálogo (MariaDB real) ---\n";

try {
    $pdo = \VendGuard\Infrastructure\Database\ConnectionFactory::getConnection();
    $persisted = $pdo->query('SELECT DISTINCT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
    $persisted = array_values(array_unique(array_map('strval', $persisted)));
    sort($persisted);

    $assert(
        '4.1 El registro contiene eventos reales que inspeccionar',
        $persisted !== [],
        'audit_log no tiene acciones persistidas'
    );

    $uncatalogued = array_values(array_diff($persisted, array_keys($catalogueActions)));
    $assert(
        '4.2 Toda acción presente en audit_log está catalogada en el visor',
        $uncatalogued === [],
        'Acciones persistidas sin catalogar: ' . implode(', ', $uncatalogued)
    );
} catch (Throwable $exception) {
    $assert('4.x Se puede inspeccionar audit_log contra MariaDB real', false, $exception->getMessage());
}

echo "\n--- 5. Resolución de insignias (integración del catálogo) ---\n";

$toneMap = [];
if (preg_match('/TONES = Object\.freeze\(\{(.*?)\n\}\);/s', $catalogueSource, $block)
    && preg_match_all('/^\s*([a-z]+):\s*\{\s*bg:\s*\'([^\']+)\',\s*color:\s*\'([^\']+)\',\s*border:\s*\'([^\']+)\'/m', $block[1], $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $toneMap[$match[1]] = ['bg' => $match[2], 'color' => $match[3], 'border' => $match[4]];
    }
}

$assert(
    '5.1 La paleta define los tonos con relleno, texto y borde',
    $toneMap !== [] && array_keys($toneMap) === $catalogue['tones'],
    'Tonos con descriptor incompleto'
);

$assert(
    '5.2 La resolución de insignia está expuesta como función del catálogo',
    str_contains($catalogueSource, 'export function getAuditActionBadge')
    && str_contains($catalogueSource, 'export function getAuditActionGroups')
);

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. EARS 5.3/5.5 y Art. III.3 CERTIFICADOS.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);
