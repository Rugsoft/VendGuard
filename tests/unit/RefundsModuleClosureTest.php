<?php

declare(strict_types=1);

/**
 * VendGuard - RefundsModuleClosureTest (T-REF-24)
 *
 * Module closure audit for Módulo 08 (Refunds and Unclaimed Cash Management).
 *
 * ## How this relates to ConstitutionalAuditTest
 * `ConstitutionalAuditTest` (T-41, extended by T-ADM-18 and T-PREV-25) already
 * audits Articles I to VII for the WHOLE project. This suite does not replace
 * it and must never be merged into it: that file is the project's constitutional
 * certificate, and a module audit living there would blur whose scope is whose.
 * Together they close the two levels, the same way `CoordinatorRefundWorkflowA-
 * piTest` (workflow) and `SiteManagerRefundDataSegregationTest` (routing and
 * RBAC) do for the coordination endpoints.
 *
 * ## Why a module needs its own audit
 * The behavioural refund suites prove the module WORKS. They cannot prove it is
 * built the way the project is required to be built, because that is exactly
 * the class of regression that leaves behaviour intact: a dependency creeps in,
 * a bilingual string lands on the wrong side of the language rule, a
 * requirement quietly loses its test. Those regressions are invisible to
 * functional tests by construction, so they need a check of their own.
 *
 * It certifies four things, all statically, with no database and no network:
 *
 * 1. Dogma Vanilla (Constitution Art. IV.3): zero third-party packages. No npm
 *    or Composer manifest, no installed tree, no bundler configuration, no bare
 *    module specifier in the frontend, and no namespace in `src/` that is
 *    neither internal nor a PHP built-in.
 * 2. Dualismo Lingüístico (AGENTS.md §3): business language in Spanish, code in
 *    English. Identifiers carry no accented characters, the specifications are
 *    written in Spanish, the refund UI speaks Spanish, and error codes are
 *    English while their messages are Spanish.
 * 3. Traceability (Constitution Art. I.1, plan §7): every RF-REF and RNF-REF
 *    requirement is still declared in the functional spec, still has a row in
 *    the traceability matrix, and every test that row names exists on disk.
 * 4. Constitutional guardrails (Art. III, V.4, VI): no hard delete anywhere in
 *    `src/`, the Art. III.1 guard on `refund_requests` in place, no way for
 *    production code to unlock it, and no deferred-phase machinery.
 *
 * It reads the repository, it does not mutate it.
 */

require_once __DIR__ . '/../bootstrap.php';

$baseDir = dirname(__DIR__, 2);

$assertions = 0;
$failures = 0;

$assert = static function (string $label, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$label}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
};

$read = static function (string $relativePath) use ($baseDir): string {
    $path = $baseDir . '/' . $relativePath;

    return is_file($path) ? (string)file_get_contents($path) : '';
};

/**
 * @return list<string>
 */
$phpFilesUnder = static function (string $relativeDir) use ($baseDir): array {
    $root = $baseDir . '/' . $relativeDir;
    if (!is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'php') {
            $files[] = $fileInfo->getPathname();
        }
    }
    sort($files);

    return $files;
};

/**
 * @return list<string>
 */
$jsFilesUnder = static function (string $relativeDir) use ($baseDir): array {
    $root = $baseDir . '/' . $relativeDir;
    if (!is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'js') {
            $files[] = $fileInfo->getPathname();
        }
    }
    sort($files);

    return $files;
};

echo "======================================================================\n";
echo " VendGuard: RefundsModuleClosureTest - Cierre del Módulo 08 (T-REF-24)\n";
echo "======================================================================\n";

$srcFiles = $phpFilesUnder('src');
$publicJsFiles = $jsFilesUnder('public/assets/js');

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 1. Dogma Vanilla: cero dependencias externas (Art. IV.3) ---\n";
// ─────────────────────────────────────────────────────────────────────────

foreach (['package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'composer.json', 'composer.lock'] as $manifest) {
    $assert(
        "1.1 No existe el manifiesto de dependencias `{$manifest}`",
        !file_exists($baseDir . '/' . $manifest),
        'presente: ' . $manifest
    );
}

foreach (['vendor', 'node_modules'] as $installedTree) {
    $assert(
        "1.2 No hay ningún árbol de paquetes instalado (`{$installedTree}/`)",
        !is_dir($baseDir . '/' . $installedTree),
        'presente: ' . $installedTree
    );
}

$bundlerConfigs = array_values(array_filter(
    ['vite.config.js', 'vite.config.ts', 'webpack.config.js', 'rollup.config.js', 'esbuild.config.js'],
    static fn (string $name): bool => file_exists($baseDir . '/' . $name)
));
$assert('1.3 No hay configuración de bundler ni de empaquetado', $bundlerConfigs === [], json_encode($bundlerConfigs));

$assert(
    '1.4 Vue 3 se sirve como fichero local ESM, no como paquete instalado',
    is_file($baseDir . '/public/assets/js/vendor/vue.esm-browser.prod.js')
);

// Una dependencia de npm se delata por su import con especificador "bare" (sin
// ruta): `import x from 'lodash'`. Los servicios de mapas (teselas, geocodifi-
// cación) son servicios en tiempo de ejecución de otro módulo, no paquetes, así
// que no se cuentan aquí. El único origen externo tolerado es el respaldo CDN
// de Vue, y sólo como último recurso.
$bareImports = [];
foreach ($publicJsFiles as $path) {
    if (str_contains($path, '/vendor/')) {
        continue; // La copia local de Vue es la librería, no una dependencia
    }
    $source = (string)file_get_contents($path);
    if (preg_match_all('#(?:from|import)\s*\(?\s*[\'"]([a-z@][A-Za-z0-9@/._-]*)[\'"]#', $source, $matches) === 0) {
        continue;
    }
    foreach ($matches[1] as $specifier) {
        if ($specifier === 'vue' || str_starts_with($specifier, 'vue@')) {
            continue;
        }
        $bareImports[] = basename($path) . ' -> ' . $specifier;
    }
}
$assert(
    '1.5 El frontend no importa ningún paquete por especificador (cero npm)',
    $bareImports === [],
    json_encode(array_slice($bareImports, 0, 5))
);

$externalScriptTags = [];
foreach (glob($baseDir . '/public/**/*.html') ?: [] as $htmlPath) {
    if (preg_match_all('#<(?:script|link)[^>]+(?:src|href)=["\']https?://[^"\']+#i', (string)file_get_contents($htmlPath), $matches) > 0) {
        foreach ($matches[0] as $tag) {
            $externalScriptTags[] = basename($htmlPath) . ' -> ' . $tag;
        }
    }
}
$assert(
    '1.6 Ninguna página HTML carga librerías o estilos desde un origen externo',
    $externalScriptTags === [],
    json_encode(array_slice($externalScriptTags, 0, 5))
);

// El módulo de reintegros, en concreto, no habla con ningún origen externo.
$refundFrontend = array_values(array_filter(
    $publicJsFiles,
    static fn (string $path): bool => (bool)preg_match('/(Refund|RefundRequest)/i', basename($path))
));
$refundOrigins = [];
foreach ($refundFrontend as $path) {
    if (preg_match_all('#https?://[A-Za-z0-9./@_-]+#', (string)file_get_contents($path), $matches) > 0) {
        foreach ($matches[0] as $url) {
            $refundOrigins[] = basename($path) . ' -> ' . $url;
        }
    }
}
$assert(
    '1.7 El módulo de reintegros no depende de ningún origen externo',
    $refundFrontend !== [] && $refundOrigins === [],
    json_encode($refundOrigins)
);

$storeSource = $read('public/assets/js/store.js');
$assert(
    '1.8 Vue se resuelve primero en local y el CDN es sólo el último recurso',
    str_contains($storeSource, './vendor/vue.esm-browser.prod.js')
    && strpos($storeSource, './vendor/vue.esm-browser.prod.js') < strpos($storeSource, 'unpkg.com/vue@3')
);

$thirdParty = [];
foreach ($srcFiles as $path) {
    $source = (string)file_get_contents($path);
    if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)\s*(?:as\s+\w+)?;/m', $source, $matches) === 0) {
        continue;
    }
    foreach ($matches[1] as $fqcn) {
        if (str_starts_with($fqcn, 'VendGuard\\')) {
            continue;
        }
        // Los built-ins de PHP son la plataforma, no una dependencia.
        $builtins = [
            'ArrayAccess', 'Closure', 'DateTimeImmutable', 'DateTimeInterface', 'DateTimeZone',
            'DomainException', 'InvalidArgumentException', 'JsonSerializable', 'PDO', 'PDOException',
            'PDOStatement', 'RuntimeException', 'Throwable', 'finfo',
        ];
        if (in_array($fqcn, $builtins, true)) {
            continue;
        }
        $thirdParty[] = basename($path) . ' -> ' . $fqcn;
    }
}
$assert(
    '1.9 `src/` sólo importa el espacio de nombres propio y built-ins de PHP',
    $thirdParty === [],
    json_encode(array_slice($thirdParty, 0, 5))
);

$missingStrictTypes = [];
foreach ($srcFiles as $path) {
    if (!str_contains((string)file_get_contents($path), 'declare(strict_types=1);')) {
        $missingStrictTypes[] = basename($path);
    }
}
$assert(
    '1.10 Todo el backend PHP usa tipado estricto (Art. IV.1)',
    $missingStrictTypes === [],
    json_encode(array_slice($missingStrictTypes, 0, 5))
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 2. Dualismo Lingüístico (AGENTS.md §3) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$accentedIdentifiers = [];
foreach ($srcFiles as $path) {
    if (preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|enum|trait)\s+\S*[áéíóúñÁÉÍÓÚÑ]/m', (string)file_get_contents($path)) === 1) {
        $accentedIdentifiers[] = basename($path);
    }
}
$assert(
    '2.1 Los identificadores del código están en inglés, sin acentos ni eñes',
    $accentedIdentifiers === [],
    json_encode($accentedIdentifiers)
);

$refundSpec = $read('specs/functional/refunds_spec.md');
$assert(
    '2.2 La especificación funcional está redactada en castellano',
    $refundSpec !== ''
    && preg_match('/(Especificación|Requisito|Sistema|solicitante|Reintegro|avería)/u', $refundSpec) === 1
);

$refundContracts = $read('specs/technical/refunds_contracts.md');
$assert(
    '2.3 Los contratos técnicos están redactados en castellano',
    $refundContracts !== ''
    && preg_match('/(Autenticación|Método|Ruta|Roles|Respuesta)/u', $refundContracts) === 1
);

$uiFilesWithSpanish = 0;
foreach ($publicJsFiles as $path) {
    if (preg_match_all('/[\'"][^\'"]*(conserjería|Reintegro|reintegro|saldo|Saldo|dictamen|Dictamen)[^\'"]*[\'"]/u', (string)file_get_contents($path)) > 0) {
        $uiFilesWithSpanish++;
    }
}
$assert(
    '2.4 La interfaz de reintegros se muestra en castellano',
    $uiFilesWithSpanish >= 1,
    'componentes con textos de negocio en español: ' . $uiFilesWithSpanish
);

$assert(
    '2.5 El catálogo de errores expresa los mensajes en castellano',
    preg_match('/El PIN de recogida introducido no coincide/u', $refundContracts) === 1
);

// El código que produce el error está en inglés y su mensaje en castellano: las
// dos caras del mismo contrato, que es el Dualismo Lingüístico en la práctica.
$pickupPinException = $read('src/Core/Domain/Exception/InvalidPickupPinException.php');
$assert(
    '2.6 El código del error está en inglés y su mensaje en castellano',
    str_contains($pickupPinException, "ERROR_CODE = 'INVALID_PICKUP_PIN'")
    && preg_match("/DEFAULT_MESSAGE = 'El PIN de recogida/u", $pickupPinException) === 1
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 3. Trazabilidad de requisitos (Art. I.1, plan §7) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$declared = [];
// Los funcionales van en cabecera `#### RF-REF-0X:` y los no funcionales en
// viñeta `* **RNF-REF-0X (...)`; ambos formatos son parte de la especificación.
if (preg_match_all('/(?:#{4}\s+\*\*|####\s+|\*\s+\*\*)(RNF-REF-\d+|RF-REF-\d+)/', $refundSpec, $matches) > 0) {
    $declared = array_values(array_unique($matches[1]));
}
sort($declared);
$assert(
    '3.1 La especificación funcional declara los 10 funcionales y los 5 no funcionales',
    count(array_filter($declared, static fn (string $id): bool => str_starts_with($id, 'RF-'))) === 10
    && count(array_filter($declared, static fn (string $id): bool => str_starts_with($id, 'RNF-'))) === 5,
    'declarados: ' . json_encode($declared)
);

$plan = $read('specs/08-refunds/plan.md');
$unmapped = [];
foreach ($declared as $id) {
    if (!str_contains($plan, $id)) {
        $unmapped[] = $id;
    }
}
$assert(
    '3.2 Cada requisito declarado tiene fila en la matriz de trazabilidad del plan',
    $unmapped === [],
    json_encode($unmapped)
);

// Cada fila de la matriz debe nombrar al menos un test real en disco: una
// trazabilidad que apunta a un fichero inexistente no es trazabilidad. Esta
// comprobación es la que detectó a `TechnicianResolutionModalTest.mjs`, nombre
// que el plan arrastraba desde antes de que T-REF-16 separase el bloque de
// dictamen del modal, y que nunca llegó a existir en disco.
$matrixRows = preg_split('/\R/', $plan) ?: [];
$danglingTest = [];
foreach ($matrixRows as $row) {
    if (preg_match('/^\|\s*\*\*(RNF-REF-\d+|RF-REF-\d+)\*\*/', $row, $idMatch) !== 1) {
        continue;
    }
    if (preg_match_all('/`([A-Za-z0-9_]+\.(?:php|mjs))`/', $row, $testMatches) === 0) {
        $danglingTest[] = $idMatch[1] . ' (sin test)';
        continue;
    }
    foreach ($testMatches[1] as $testFile) {
        $found = is_file($baseDir . '/tests/integration/' . $testFile)
            || is_file($baseDir . '/tests/unit/' . $testFile);
        if (!$found) {
            $danglingTest[] = $idMatch[1] . ' -> ' . $testFile;
        }
    }
}
$assert(
    '3.3 Toda la matriz de trazabilidad apunta a tests que existen en disco',
    $danglingTest === [],
    json_encode($danglingTest)
);

$closingSuites = [
    'PublicRefundTrackingApiTest.php',
    'TechnicianRefundInspectionApiTest.php',
    'LocationRefundDeliveryApiTest.php',
    'CoordinatorRefundWorkflowApiTest.php',
    'SiteManagerRefundDataSegregationTest.php',
];
$missingSuites = array_values(array_filter(
    $closingSuites,
    static fn (string $name): bool => !is_file($baseDir . '/tests/integration/' . $name)
));
$assert(
    '3.4 Las cinco suites HTTP que cierran el módulo existen',
    $missingSuites === [],
    json_encode($missingSuites)
);

$tasks = $read('specs/08-refunds/tasks.md');
$openTasks = [];
foreach (preg_split('/\R/', $tasks) ?: [] as $line) {
    if (preg_match('/^- \[ \] \*\*(T-REF-\d+):/', $line, $taskMatch) === 1) {
        $openTasks[] = $taskMatch[1];
    }
}
$assert(
    '3.5 No queda ninguna tarea de la Fase 5 sin cerrar',
    $openTasks === [],
    'pendientes: ' . json_encode($openTasks)
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Blindaje constitucional del módulo (Art. III, V.4, VI) ---\n";
// ─────────────────────────────────────────────────────────────────────────

$hardDeletes = [];
foreach ($srcFiles as $path) {
    if (stripos((string)file_get_contents($path), 'DELETE FROM') !== false) {
        $hardDeletes[] = basename($path);
    }
}
$assert('4.1 Ningún fichero de `src/` ejecuta un DELETE FROM (Art. III.1)', $hardDeletes === [], json_encode($hardDeletes));

$purgeUnlockers = [];
foreach ($srcFiles as $path) {
    if (str_contains((string)file_get_contents($path), '@vendguard_purge')) {
        $purgeUnlockers[] = basename($path);
    }
}
$assert(
    '4.2 El código de producción no puede abrir la ventana de purga (Art. III.1)',
    $purgeUnlockers === [],
    json_encode($purgeUnlockers)
);

$guardMigration = $read('database/migrations/010_refund_hard_delete_guard.sql');
$assert(
    '4.3 El guardián de borrado físico está versionado e instalado',
    $guardMigration !== ''
    && str_contains($guardMigration, 'BEFORE DELETE ON `refund_requests`')
    && str_contains($guardMigration, '45000')
);

$cleaner = $read('tests/Support/TestDataCleaner.php');
$assert(
    '4.4 Sólo el arnés de pruebas abre la ventana, y siempre la cierra',
    substr_count($cleaner, '@vendguard_purge') === 2
    && str_contains($cleaner, 'SET @vendguard_purge = 0')
);

$runAll = $read('tests/run_all.php');
$assert(
    '4.5 La batería descubre las suites por glob, así que ninguna queda fuera sin avisar',
    str_contains($runAll, "glob(__DIR__ . '/integration/*.php')")
    && str_contains($runAll, "glob(__DIR__ . '/unit/*.php')")
    && str_contains($runAll, "glob(__DIR__ . '/unit/*.mjs')")
);
$assert(
    '4.6 La guardia de Fase 0 se ejecuta antes que cualquier prueba',
    strpos($runAll, 'auditSuitesCleanupDiscipline') < strpos($runAll, "glob(__DIR__ . '/unit/*.php')")
);

// Art. VI: nada de la Fase 2 colado en el módulo de reintegros.
$deferredPhase = [];
foreach ($srcFiles as $path) {
    $source = (string)file_get_contents($path);
    if (stripos($source, 'refund') === false) {
        continue;
    }
    if (preg_match('/\b(telemetr\w*|IoT|MDB|DEX|inventario de furgonetas)\b/i', $source) === 1) {
        $deferredPhase[] = basename($path);
    }
}
$assert(
    '4.7 Ningún fichero de reintegros arrastra maquinaria de fases futuras (Art. VI)',
    $deferredPhase === [],
    json_encode($deferredPhase)
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallos: {$failures}\n";
if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-24 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
