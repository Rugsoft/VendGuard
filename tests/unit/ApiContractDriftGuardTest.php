<?php

declare(strict_types=1);

/**
 * VendGuard - API contract drift guard (specs/technical/api_contracts.md)
 *
 * Certifies that the error codes and the payload fields the technical
 * specification documents for the incident-comment thread are actually
 * produced by the code, and that any code documented in a comment section is
 * emittable by the comment module itself.
 *
 * Two classes of defect are covered:
 *
 *  1. Phantom error code: §4.5 documented COMMENT_WINDOW_CLOSED (and a 422
 *     MISSING_COMMENT_TEXT / COMMENT_TOO_SHORT that no longer existed), so an
 *     integrator coding against the specification wrote branches that could
 *     never run. The same section announced `metadata.visibility` while every
 *     audit event was written with metadata = null.
 *
 *  2. Phantom payload field: §4.5 documented an item with `user_id`,
 *     `photo_path` and `ticket_code` after the endpoints had moved to the
 *     thread DTO, whose items carry `photo_url` / `is_own_message` instead.
 *
 * The vocabulary is derived from the emitters themselves - `Response::error()`
 * calls, exception error codes, `errorCode` assignments and `'code' =>` arrays
 * resolved with the PHP tokenizer - and the field sets are derived from the
 * DTOs and the service that build the thread, never from a second hand-kept
 * list, so this guard cannot drift away from the code it protects. The
 * extraction floors below make a vacuous pass impossible: if the specification
 * changes its error-list format or the emitters move, the guard fails loudly
 * instead of silently checking nothing.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

$assertions = 0;
$failures = 0;

$assert = function (string $label, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$label}\n";
        return;
    }
    $failures++;
    echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
};

/**
 * Files that implement the incident-comment thread endpoints (Módulo 10).
 * A code documented in §3.3 / §4.5 / §5.5 must be emittable by one of these.
 */
const COMMENT_MODULE_EMITTERS = [
    'src/Presentation/Controller/LocationPortalController.php',
    'src/Presentation/Controller/TechnicianController.php',
    'src/Presentation/Controller/CoordinatorController.php',
    'src/Application/Service/IncidentCommentService.php',
    'src/Infrastructure/Storage/LocalFileUploader.php',
    'src/Core/Domain/Exception/InvalidCommentLengthException.php',
    'src/Core/Domain/Exception/ConversationSealedException.php',
    'src/Core/Domain/Exception/InvalidUploadException.php',
];

/** Apartados del contrato del hilo de comentarios, por canal. */
const COMMENT_CONTRACT_SECTIONS = [
    '3.3' => 'site',
    '4.5' => 'coordinator',
    '5.5' => 'technician',
];

/** Clave de marcador de posición en los ejemplos abreviados de la especificación. */
const PLACEHOLDER_KEY = '…';

/** Suelos anti-vacuidad: una extracción vacía no puede pasar por verde. */
const MIN_DOCUMENTED_CODES = 30;
const MIN_EMITTED_CODES = 250;
const MIN_SECTION_DOCUMENTED_CODES = 12;
const MIN_SECTION_EXAMPLES = 4;

/**
 * Corta la especificación en apartados de nivel 3 ("### 4.5 ...").
 *
 * @return array<string, string> número de apartado => texto
 */
function sliceContractSections(string $markdown): array
{
    $sections = [];
    $current = null;
    foreach (explode("\n", $markdown) as $line) {
        if (preg_match('/^### (\d+(?:\.\d+)*) /', $line, $match)) {
            $current = $match[1];
            $sections[$current] = '';
            continue;
        }
        if (preg_match('/^## /', $line)) {
            $current = null;
        }
        if ($current !== null) {
            $sections[$current] .= $line . "\n";
        }
    }
    return $sections;
}

/**
 * Códigos de error documentados en posición de error: "`409 Conflict` (`CODE`)",
 * "`400` (`CODE` / `OTRO`)" o "`422 Unprocessable` (`CODE`)".
 *
 * @return list<string>
 */
function documentedErrorCodes(string $text): array
{
    $codes = [];
    if (preg_match_all('/`\d{3}[^`]*`\s*\(([^)]*)\)/u', $text, $groups)) {
        foreach ($groups[1] as $group) {
            if (preg_match_all('/[A-Z][A-Z0-9_]{2,}/', $group, $found)) {
                foreach ($found[0] as $code) {
                    $codes[$code] = true;
                }
            }
        }
    }
    ksort($codes);
    return array_keys($codes);
}

/**
 * Códigos de error que un fuente PHP puede emitir: literales en las posiciones
 * reconocidas de error (Response::error, `throw new XException('CODE'`,
 * `errorCode = 'CODE'`, `'code' => 'CODE'`).
 *
 * @return list<string>
 */
function emittedErrorCodes(string $source): array
{
    $codes = [];
    $tokens = token_get_all($source);
    $total = count($tokens);
    $isIgnorable = static fn ($token): bool => is_array($token)
        && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    $nextSignificant = static function (int $from) use ($tokens, $total, $isIgnorable): int {
        for ($i = $from; $i < $total; $i++) {
            if (!$isIgnorable($tokens[$i])) {
                return $i;
            }
        }
        return -1;
    };
    $stringLiteral = static function (int $index) use ($tokens): ?string {
        $token = $tokens[$index] ?? null;
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        return trim($token[1], "'\"");
    };

    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }

        // Response::error('CODE', ...)
        if ($token[0] === T_STRING && strtolower($token[1]) === 'error') {
            $before = $i - 1;
            while ($before >= 0 && $isIgnorable($tokens[$before])) {
                $before--;
            }
            $isStaticCall = $before >= 0 && is_array($tokens[$before]) && $tokens[$before][0] === T_DOUBLE_COLON;
            $open = $nextSignificant($i + 1);
            if ($isStaticCall && $open !== -1 && $tokens[$open] === '(') {
                $code = $stringLiteral($nextSignificant($open + 1));
                if ($code !== null) {
                    $codes[$code] = true;
                }
            }
            continue;
        }

        // throw new SomethingException('CODE', ...)
        if ($token[0] === T_THROW) {
            $new = $nextSignificant($i + 1);
            if ($new !== -1 && is_array($tokens[$new]) && $tokens[$new][0] === T_NEW) {
                $cursor = $nextSignificant($new + 1);
                while ($cursor !== -1 && is_array($tokens[$cursor])
                    && in_array($tokens[$cursor][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $cursor = $nextSignificant($cursor + 1);
                }
                if ($cursor !== -1 && $tokens[$cursor] === '(') {
                    $code = $stringLiteral($nextSignificant($cursor + 1));
                    if ($code !== null) {
                        $codes[$code] = true;
                    }
                }
            }
            continue;
        }

        // $errorCode = 'CODE'  ·  errorCode: 'CODE'
        $isErrorCodeName = ($token[0] === T_VARIABLE && $token[1] === '$errorCode')
            || ($token[0] === T_STRING && $token[1] === 'errorCode');
        if ($isErrorCodeName) {
            $separator = $nextSignificant($i + 1);
            if ($separator !== -1 && ($tokens[$separator] === '=' || $tokens[$separator] === ':')) {
                $code = $stringLiteral($nextSignificant($separator + 1));
                if ($code !== null) {
                    $codes[$code] = true;
                }
            }
            continue;
        }

        // 'code' => 'CODE'
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING
            && in_array(trim($token[1], "'\""), ['code', 'error_code'], true)) {
            $arrow = $nextSignificant($i + 1);
            if ($arrow !== -1 && is_array($tokens[$arrow]) && $tokens[$arrow][0] === T_DOUBLE_ARROW) {
                $code = $stringLiteral($nextSignificant($arrow + 1));
                if ($code !== null) {
                    $codes[$code] = true;
                }
            }
        }
    }

    return array_keys($codes);
}

/**
 * Claves declaradas en un literal de array PHP (`clave: [` o `'clave' => [`),
 * recorriendo corchetes hasta su cierre.
 *
 * @return list<string>
 */
function arrayLiteralKeys(string $source, string $startPattern): array
{
    if (!preg_match($startPattern, $source, $match, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $tail = substr($source, (int) $match[0][1], 4000);
    $depth = 0;
    $body = '';
    $started = false;
    for ($i = 0, $length = strlen($tail); $i < $length; $i++) {
        $char = $tail[$i];
        if ($char === '[') {
            $depth++;
            $started = true;
            continue;
        }
        if ($char === ']') {
            $depth--;
            if ($started && $depth === 0) {
                break;
            }
        }
        if ($started && $depth >= 1) {
            $body .= $char;
        }
    }
    preg_match_all("/'([a-z_]+)'\s*=>/", $body, $keys);
    return array_values(array_unique($keys[1]));
}

/**
 * Ejemplos JSON de un apartado, ya decodificados.
 *
 * @return list<array<string, mixed>>
 */
function documentedJsonExamples(string $section): array
{
    $examples = [];
    if (preg_match_all('/```json\s*\n(.*?)\n```/s', $section, $blocks)) {
        foreach ($blocks[1] as $json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $examples[] = $decoded;
            }
        }
    }
    return $examples;
}

/**
 * Claves documentadas de un contenedor, ignorando los marcadores de posición
 * de los ejemplos abreviados.
 *
 * @param list<array<string, mixed>> $examples
 * @return list<string>
 */
function documentedKeys(array $examples, string $path): array
{
    $keys = [];
    foreach ($examples as $example) {
        $containers = [$example];
        foreach (explode('.', $path) as $segment) {
            $next = [];
            foreach ($containers as $container) {
                if (!is_array($container)) {
                    continue;
                }
                if ($segment === '*') {
                    foreach ($container as $entry) {
                        if (is_array($entry)) {
                            $next[] = $entry;
                        }
                    }
                    continue;
                }
                if (isset($container[$segment]) && is_array($container[$segment])) {
                    $next[] = $container[$segment];
                }
            }
            $containers = $next;
        }
        foreach ($containers as $container) {
            foreach (array_keys($container) as $key) {
                if ($key !== PLACEHOLDER_KEY) {
                    $keys[$key] = true;
                }
            }
        }
    }
    ksort($keys);
    return array_keys($keys);
}

/**
 * Claves reales del contrato: el sobre, la cabecera y la paginación del hilo
 * (servicio + DTO del hilo) y los campos del mensaje con las claves que el DTO
 * declara excluir ante la Sede.
 *
 * @return array{thread: list<string>, incident: list<string>, pagination: list<string>, item: list<string>, siteExcluded: list<string>}
 */
function realContractFieldKeys(string $baseDir): array
{
    $serviceSource = (string) file_get_contents($baseDir . '/src/Application/Service/IncidentCommentService.php');
    $itemDtoSource = (string) file_get_contents($baseDir . '/src/Application/DTO/IncidentCommentItemDto.php');
    $threadDtoSource = (string) file_get_contents($baseDir . '/src/Application/DTO/IncidentCommentThreadDto.php');

    $itemCoreKeys = arrayLiteralKeys($itemDtoSource, "/\\\$payload\s*=\s*\[/");
    preg_match_all("/\\\$payload\['([a-z_]+)'\]\s*=/", $itemDtoSource, $conditionalKeys);
    $itemKeys = array_values(array_unique(array_merge($itemCoreKeys, $conditionalKeys[1])));
    sort($itemKeys);

    $excludedStart = strpos($itemDtoSource, 'siteExcludedKeys');
    $excludedBody = $excludedStart === false ? '' : substr($itemDtoSource, $excludedStart, 400);
    preg_match_all("/'([a-z_]+)'/", $excludedBody, $excludedKeys);

    return [
        'thread' => arrayLiteralKeys($threadDtoSource, "/return\s*\[/"),
        'incident' => arrayLiteralKeys($serviceSource, "/incident:\s*\[/"),
        'pagination' => arrayLiteralKeys($serviceSource, "/pagination:\s*\[/"),
        'item' => $itemKeys,
        'siteExcluded' => array_values(array_unique($excludedKeys[1])),
    ];
}

// ─────────────────────────────────────────────────────────────────────────
echo "======================================================================\n";
echo " VendGuard: Guarda de deriva de contratos de la API (Módulo 10)\n";
echo "======================================================================\n\n";

$specPath = $baseDir . '/specs/technical/api_contracts.md';
$markdown = str_replace("\r\n", "\n", (string) file_get_contents($specPath));
$sections = sliceContractSections($markdown);

$documentedAll = documentedErrorCodes($markdown);
$documentedBySection = [];
foreach (array_keys(COMMENT_CONTRACT_SECTIONS) as $sectionId) {
    $documentedBySection[$sectionId] = documentedErrorCodes($sections[$sectionId] ?? '');
}

$emittedAll = [];
$emittedByFile = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir . '/src', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    foreach (emittedErrorCodes((string) file_get_contents($file->getPathname())) as $code) {
        $emittedAll[$code] = true;
        $emittedByFile[$code][] = basename($file->getPathname());
    }
}
$emittedCodes = array_keys($emittedAll);
sort($emittedCodes);

// ── 1. Extracción y anti-vacuidad ────────────────────────────────────────
echo "--- 1. Extracción del vocabulario ---\n";

$assert(
    '1.1 La especificación de contratos existe y es legible',
    $markdown !== '' && str_contains($markdown, '### 3.3 '),
    "No se pudo leer {$specPath}"
);

$assert(
    '1.2 El documento declara al menos ' . MIN_DOCUMENTED_CODES . ' códigos de error',
    count($documentedAll) >= MIN_DOCUMENTED_CODES,
    'Extraídos: ' . count($documentedAll)
);

$assert(
    '1.3 El código declara al menos ' . MIN_EMITTED_CODES . ' códigos emitibles',
    count($emittedCodes) >= MIN_EMITTED_CODES,
    'Extraídos: ' . count($emittedCodes)
);

$assert(
    '1.4 Los tres apartados del hilo documentan al menos ' . MIN_SECTION_DOCUMENTED_CODES . ' códigos',
    array_sum(array_map('count', $documentedBySection)) >= MIN_SECTION_DOCUMENTED_CODES,
    'Extraídos: ' . array_sum(array_map('count', $documentedBySection))
);

$missingEmitterFiles = array_values(array_filter(
    COMMENT_MODULE_EMITTERS,
    static fn (string $relative): bool => !is_file($baseDir . '/' . $relative)
));
$assert(
    '1.5 Los ficheros del módulo de comentarios siguen existiendo',
    $missingEmitterFiles === [],
    'Ausentes: ' . implode(', ', $missingEmitterFiles)
);

$realKeys = realContractFieldKeys($baseDir);
$realIncidentKeys = $realKeys['incident'];
$realPaginationKeys = $realKeys['pagination'];
$realItemKeys = $realKeys['item'];
$realThreadKeys = $realKeys['thread'];
$siteExcludedKeys = $realKeys['siteExcluded'];

$assert(
    '1.6 La cabecera, la paginación y el sobre del hilo se derivan del código',
    count($realIncidentKeys) === 10
        && count($realPaginationKeys) === 5
        && $realThreadKeys === ['incident', 'pagination', 'comments']
        && count($realItemKeys) === 8
        && $siteExcludedKeys === ['is_internal'],
    sprintf(
        'incident=%d pagination=%d item=%d thread=%s siteExcluded=%s',
        count($realIncidentKeys),
        count($realPaginationKeys),
        count($realItemKeys),
        implode('/', $realThreadKeys),
        implode(',', $siteExcludedKeys)
    )
);

// ── 2. Códigos documentados sin emisor (fantasmas) ───────────────────────
echo "\n--- 2. Códigos de error sin emisor ---\n";

$phantomCodes = array_values(array_diff($documentedAll, $emittedCodes));
$assert(
    '2.1 Ningún código documentado es inemitible por el código',
    $phantomCodes === [],
    'Fantasmas: ' . implode(', ', $phantomCodes)
);

$moduleEmitted = [];
foreach (COMMENT_MODULE_EMITTERS as $relative) {
    $absolute = $baseDir . '/' . $relative;
    if (!is_file($absolute)) {
        continue;
    }
    foreach (emittedErrorCodes((string) file_get_contents($absolute)) as $code) {
        $moduleEmitted[$code] = true;
    }
}
$moduleEmittedCodes = array_keys($moduleEmitted);

$outOfModule = [];
foreach ($documentedBySection as $sectionId => $codes) {
    foreach (array_diff($codes, $moduleEmittedCodes) as $code) {
        $outOfModule[] = "{$sectionId}:{$code}";
    }
}
$assert(
    '2.2 Los códigos del hilo los emite el propio módulo de comentarios',
    $outOfModule === [],
    'Fuera del módulo: ' . implode(', ', $outOfModule)
);

// ── 3. Mordida de la guarda (regresión de los hallazgos históricos) ──────
echo "\n--- 3. Capacidad de detección ---\n";

$legacySectionFixture = <<<'MD'
### 4.5 `POST /api/coordinator/incidents/{id}/comments`
* **Errores de Validación / Negocio:**
  * `422 Unprocessable` (`MISSING_COMMENT_TEXT`): falta `comment_text`.
  * `422 Unprocessable` (`COMMENT_TOO_SHORT`): el texto no alcanza los 5 caracteres.
  * `422 Unprocessable` (`COMMENT_WINDOW_CLOSED`): bitácora sellada.
MD;
$fixtureCodes = documentedErrorCodes($legacySectionFixture);
$fixturePhantoms = array_values(array_diff($fixtureCodes, $emittedCodes));
$assert(
    '3.1 La guarda detecta los fantasmas históricos del apartado 4.5',
    in_array('COMMENT_WINDOW_CLOSED', $fixturePhantoms, true)
        && in_array('COMMENT_TOO_SHORT', $fixturePhantoms, true)
        && !in_array('MISSING_COMMENT_TEXT', $fixturePhantoms, true),
    'Detectados: ' . implode(', ', $fixturePhantoms)
);

$assert(
    '3.2 La guarda no marca como fantasma un código realmente emitido',
    in_array('CONVERSATION_SEALED', $emittedCodes, true)
        && in_array('MISSING_COMMENT_TEXT', $emittedCodes, true)
        && !in_array('CONVERSATION_SEALED', $fixturePhantoms, true)
);

$legacyFieldFixture = <<<'MD'
### 4.5 `POST /api/coordinator/incidents/{id}/comments`
```json
{"success":true,"data":{"comments":[{"id":341,"author_type":"COORDINATOR","user_id":2,"comment_text":"x","photo_path":null,"created_at":"2026-10-05 12:00:00","ticket_code":"INC-2026-0142"}]}}
```
MD;
$fixtureItemKeys = documentedKeys(documentedJsonExamples($legacyFieldFixture), 'data.comments.*');
$fixturePhantomFields = array_values(array_diff($fixtureItemKeys, $realItemKeys));
$assert(
    '3.3 La guarda detecta los campos fantasma del apartado 4.5 histórico',
    in_array('user_id', $fixturePhantomFields, true)
        && in_array('photo_path', $fixturePhantomFields, true)
        && in_array('ticket_code', $fixturePhantomFields, true)
        && !in_array('comment_text', $fixturePhantomFields, true)
        && !in_array('created_at', $fixturePhantomFields, true),
    'Detectados: ' . implode(', ', $fixturePhantomFields)
);

// ── 4. Campos de payload del hilo ────────────────────────────────────────
echo "\n--- 4. Campos de payload documentados ---\n";

$examplesBySection = [];
foreach (array_keys(COMMENT_CONTRACT_SECTIONS) as $sectionId) {
    $examplesBySection[$sectionId] = documentedJsonExamples($sections[$sectionId] ?? '');
}
$assert(
    '4.1 Los apartados del hilo incluyen al menos ' . MIN_SECTION_EXAMPLES . ' ejemplos JSON',
    array_sum(array_map('count', $examplesBySection)) >= MIN_SECTION_EXAMPLES,
    'Ejemplos: ' . array_sum(array_map('count', $examplesBySection))
);

$documentedThreadKeys = [];
$documentedIncidentKeys = [];
$documentedPaginationKeys = [];
$documentedItemKeys = [];
foreach (array_keys(COMMENT_CONTRACT_SECTIONS) as $sectionId) {
    $examples = $examplesBySection[$sectionId];
    $documentedThreadKeys = array_values(array_unique(array_merge(
        $documentedThreadKeys,
        documentedKeys($examples, 'data')
    )));
    $documentedIncidentKeys = array_values(array_unique(array_merge(
        $documentedIncidentKeys,
        documentedKeys($examples, 'data.incident')
    )));
    $documentedPaginationKeys = array_values(array_unique(array_merge(
        $documentedPaginationKeys,
        documentedKeys($examples, 'data.pagination')
    )));
    $documentedItemKeys[$sectionId] = documentedKeys($examples, 'data.comments.*');
}

$phantomThreadKeys = array_values(array_diff($documentedThreadKeys, $realThreadKeys));
$phantomIncidentKeys = array_values(array_diff($documentedIncidentKeys, $realIncidentKeys));
$phantomPaginationKeys = array_values(array_diff($documentedPaginationKeys, $realPaginationKeys));
$assert(
    '4.2 Ningún campo documentado del sobre, la cabecera o la paginación es inexistente',
    $phantomThreadKeys === [] && $phantomIncidentKeys === [] && $phantomPaginationKeys === [],
    sprintf(
        'sobre=[%s] cabecera=[%s] paginación=[%s]',
        implode(',', $phantomThreadKeys),
        implode(',', $phantomIncidentKeys),
        implode(',', $phantomPaginationKeys)
    )
);

$phantomItemKeys = [];
foreach ($documentedItemKeys as $sectionId => $keys) {
    foreach (array_diff($keys, $realItemKeys) as $key) {
        $phantomItemKeys[] = "{$sectionId}:{$key}";
    }
}
$assert(
    '4.3 Ningún campo documentado de los mensajes es inexistente',
    $phantomItemKeys === [],
    'Fantasmas: ' . implode(', ', $phantomItemKeys)
);

$requiredItemKeys = array_values(array_diff($realItemKeys, $siteExcludedKeys));
$missingRequiredKeys = [];
foreach ($documentedItemKeys as $sectionId => $keys) {
    foreach (array_diff($requiredItemKeys, $keys) as $key) {
        $missingRequiredKeys[] = "{$sectionId}:{$key}";
    }
}
$assert(
    '4.4 Los campos obligatorios de los mensajes están documentados en cada canal',
    $missingRequiredKeys === [],
    'Sin documentar: ' . implode(', ', $missingRequiredKeys)
);

// La sede no puede ver la clasificación interna: el propio DTO la excluye y el
// contrato de sede no debe documentarla (RNF-01 / Art. V.4).
$siteDocumentedItemKeys = $documentedItemKeys['3.3'] ?? [];
$internalSectionsDocumentIt = ($documentedItemKeys['4.5'] ?? []) !== []
    && in_array('is_internal', $documentedItemKeys['4.5'], true)
    && in_array('is_internal', $documentedItemKeys['5.5'], true);
$assert(
    '4.5 El canal de sede no documenta is_internal y los internos sí',
    in_array('is_internal', $siteExcludedKeys, true)
        && !in_array('is_internal', $siteDocumentedItemKeys, true)
        && $internalSectionsDocumentIt,
    sprintf(
        'excluidas=%s sede=%s',
        implode(',', $siteExcludedKeys),
        implode(',', $siteDocumentedItemKeys)
    )
);

$itemDtoSource = (string) file_get_contents($baseDir . '/src/Application/DTO/IncidentCommentItemDto.php');
$assert(
    '4.6 Los campos documentados los serializa el DTO del mensaje',
    $documentedItemKeys !== []
        && str_contains($itemDtoSource, "'photo_url' => \$this->photoUrl")
        && str_contains($itemDtoSource, "'is_own_message' => \$this->isOwnMessage")
        && !str_contains($itemDtoSource, "'photo_path'"),
    'El DTO no refleja las claves documentadas'
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 90) . "\n";
echo " Documentos verificados: specs/technical/api_contracts.md (apartados 3.3, 4.5 y 5.5)\n";
echo " Códigos documentados: " . count($documentedAll) . " · emitibles: " . count($emittedCodes) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONTRATO DEL HILO SIN DERIVA (Art. V.5, Art. III.3).\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);
