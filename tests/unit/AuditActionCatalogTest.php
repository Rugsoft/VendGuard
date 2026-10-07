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
    'src/Presentation/Controller/CoordinatorController.php::logTicketEvent',
];

/** AuditLogger writers whose second argument (or `action:` named argument) is the action. */
const AUDIT_WRITER_METHODS = [
    'logTicketEvent',
    'logMachineEvent',
    'logLocationEvent',
    'logUserEvent',
    'logPreventiveOrderEvent',
    'logSanitaryCertificateEvent',
    'logRefundEvent',
    'logUnclaimedCashEvent',
];

/**
 * Extracts every audit action a PHP source can persist.
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

        $method = $token[1];
        $site = $relativePath . '::' . $method;

        // Split the argument list at top level, honouring nesting.
        $depth = 1;
        $arguments = [];
        $current = [];
        for ($k = $cursor + 1; $k < $count; $k++) {
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

        // Locate the action argument: named `action:` wins, otherwise positional #2.
        $actionArgument = null;
        $named = null;
        foreach ($arguments as $index => $argument) {
            $clean = array_values(array_filter($argument, static fn($tk): bool => !$isSkippable($tk)));
            if (isset($clean[0], $clean[1]) && is_array($clean[0]) && $clean[0][0] === T_STRING
                && $clean[0][1] === 'action' && $clean[1] === ':') {
                $named = array_slice($argument, array_search(':', $argument, true) + 1);
                break;
            }
            if ($index === 1) {
                $actionArgument = $argument;
            }
        }
        $expression = $named ?? $actionArgument;
        if ($expression === null) {
            $dynamic[$site] = '(no action argument found)';
            continue;
        }

        $clean = array_values(array_filter($expression, static fn($tk): bool => !$isSkippable($tk)));
        $stringLiterals = [];
        foreach ($clean as $tk) {
            if (is_array($tk) && $tk[0] === T_CONSTANT_ENCAPSED_STRING) {
                $stringLiterals[] = trim($tk[1], "'\"");
            }
        }

        $isPlainLiteral = count($clean) === 1 && count($stringLiterals) === 1;
        if ($isPlainLiteral) {
            $literals[$stringLiterals[0]] = $literals[$stringLiterals[0]] ?? $site;
            continue;
        }

        foreach ($stringLiterals as $literal) {
            $literals[$literal] = $literals[$literal] ?? $site . ' (expression)';
        }
        $flat = '';
        foreach ($clean as $tk) {
            $flat .= is_array($tk) ? $tk[1] : $tk;
        }
        $dynamic[$site] = $flat;
    }

    // Raw inserts into audit_log (demo/metric seeders).
    if (preg_match_all("/action'\s*=>\s*'([A-Z][A-Z0-9_]+)'/", $source, $matches)) {
        foreach ($matches[1] as $literal) {
            $literals[$literal] = $literals[$literal] ?? $relativePath . ' (raw insert)';
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
    $persisted = $pdo->query('SELECT DISTINCT `action` FROM `audit_log`')->fetchAll(PDO::FETCH_COLUMN);
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
