<?php

declare(strict_types=1);

/**
 * FunctionalSpecMirrorParityTest · Módulo 28 (guardia de deriva)
 *
 * El espejo funcional de `specs/functional/` debe seguir siendo una copia
 * funcional completa del spec canónico, no un puntero. Esta guarda no exige
 * igualdad byte a byte —la cabecera del espejo puede enlazar al canónico— pero
 * sí exige que ambos documentos declaren la misma versión y el mismo universo
 * de criterios: si alguien recorta el espejo o añade un EARS sólo en un lado,
 * la suite se pone en rojo antes de que la divergencia llegue a producción.
 *
 * Comprueba:
 *   1. Existencia y contenido de ambos documentos.
 *   2. Que el espejo conserva las secciones funcionales (no es un puntero).
 *   3. Versión idéntica declarada en ambos.
 *   4. Mismo conjunto de `RF`, `EARS`, `RNF`, `D`, `C` y `QA`.
 *   5. Mismas historias de usuario y ninguna duda abierta.
 *   6. Coherencia nominal del modelo real del parque (`Fas Fast 900`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 */

echo "======================================================================\n";
echo " VendGuard: Suite unitaria - FunctionalSpecMirrorParityTest (Módulo 28)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

$projectRoot = dirname(__DIR__, 2);
$canonicalPath = $projectRoot . '/specs/28-troubleshooting-guides/spec.md';
$mirrorPath = $projectRoot . '/specs/functional/troubleshooting_guides_spec.md';

/**
 * Extrae y normaliza el conjunto de identificadores capturados por un patrón.
 *
 * @return list<string>
 */
$collect = static function (string $pattern, string $content): array {
    preg_match_all($pattern, $content, $matches);
    $values = array_map('strval', $matches[1] ?? []);
    $values = array_values(array_unique($values));
    sort($values);

    return $values;
};

// =====================================================================
// GRUPO 1: Existencia y contenido
// =====================================================================
echo "--- 1. Existencia y contenido de ambos documentos ---\n";

$canonicalExists = is_file($canonicalPath);
$mirrorExists = is_file($mirrorPath);
$assert('1.1 El spec canónico existe', $canonicalExists, $canonicalPath);
$assert('1.2 El espejo funcional existe', $mirrorExists, $mirrorPath);

if (!$canonicalExists || !$mirrorExists) {
    echo "\n[ERROR CRÍTICO] Falta uno de los documentos. Abortando.\n";
    exit(1);
}

$canonical = (string) file_get_contents($canonicalPath);
$mirror = (string) file_get_contents($mirrorPath);
$assert('1.3 El spec canónico no está vacío', trim($canonical) !== '');
$assert('1.4 El espejo funcional no está vacío', trim($mirror) !== '');

// =====================================================================
// GRUPO 2: El espejo es una especificación, no un puntero
// =====================================================================
echo "\n--- 2. El espejo conserva la especificación funcional ---\n";

$mirrorLines = substr_count($mirror, "\n") + 1;
$assert(
    '2.1 El espejo conserva el contexto y el objetivo',
    str_contains($mirror, '## 1. Contexto y Objetivo')
);
$assert(
    '2.2 El espejo conserva los requisitos funcionales',
    str_contains($mirror, '## 4. Requisitos Funcionales (RF)')
);
$assert(
    '2.3 El espejo conserva el Definition of Done',
    str_contains($mirror, '## 8. Criterios de Finalización')
);
$assert(
    '2.4 El espejo no vuelve a ser un puntero de una docena de líneas',
    $mirrorLines >= 120,
    "El espejo sólo tiene {$mirrorLines} líneas."
);

// =====================================================================
// GRUPO 3: Versión declarada
// =====================================================================
echo "\n--- 3. Versión declarada en ambos documentos ---\n";

$versionPattern = '/^\*\*Versión:\*\*\s*(.+)$/mu';
$canonicalVersion = preg_match($versionPattern, $canonical, $mCanonical) === 1 ? trim($mCanonical[1]) : '';
$mirrorVersion = preg_match($versionPattern, $mirror, $mMirror) === 1 ? trim($mMirror[1]) : '';

$assert('3.1 El spec canónico declara su versión', $canonicalVersion !== '');
$assert(
    '3.2 El espejo declara la misma versión que el canónico',
    $canonicalVersion !== '' && $canonicalVersion === $mirrorVersion,
    sprintf('canónico «%s» · espejo «%s»', $canonicalVersion, $mirrorVersion)
);

// =====================================================================
// GRUPO 4: Mismo universo de criterios
// =====================================================================
echo "\n--- 4. Mismo universo de RF, EARS, RNF, D, C y QA ---\n";

$identifiers = [
    'RF' => ['/\bRF-0([1-5])\b/u', 'requisitos funcionales'],
    'EARS' => ['/\bEARS\s+(\d{2}\.\d)\b/u', 'criterios EARS'],
    'RNF' => ['/\bRNF-0([1-3])\b/u', 'requisitos no funcionales'],
    'D' => ['/(?<![A-Za-z0-9])D([1-4])(?![0-9])/u', 'decisiones de diseño'],
    'C' => ['/(?<![A-Za-z0-9])C([1-6])(?![0-9])/u', 'casos límite'],
    'QA' => ['/\bQA\s+([1-4])\b/u', 'resoluciones QA'],
];

foreach ($identifiers as $label => [$pattern, $description]) {
    $canonicalSet = $collect($pattern, $canonical);
    $mirrorSet = $collect($pattern, $mirror);

    $assert(
        sprintf('4.%s Mismo conjunto de %s (%s)', (string) (array_search($label, array_keys($identifiers), true) + 1), $label, $description),
        $canonicalSet === $mirrorSet,
        sprintf('canónico [%s] · espejo [%s]', implode(',', $canonicalSet), implode(',', $mirrorSet))
    );
}

// =====================================================================
// GRUPO 5: Historias de usuario y dudas abiertas
// =====================================================================
echo "\n--- 5. Historias de usuario y dudas abiertas ---\n";

preg_match_all('/^### HU-\d+/mu', $canonical, $canonicalStories);
preg_match_all('/^### HU-\d+/mu', $mirror, $mirrorStories);

$assert(
    '5.1 Ambos documentos declaran el mismo número de historias de usuario',
    count($canonicalStories[0]) === count($mirrorStories[0]),
    sprintf('canónico %d · espejo %d', count($canonicalStories[0]), count($mirrorStories[0]))
);
$assert(
    '5.2 El espejo conserva las tres historias del módulo',
    count($mirrorStories[0]) === 3,
    sprintf('Se encontraron %d historias.', count($mirrorStories[0]))
);

// =====================================================================
// GRUPO 6: Enlace al canónico y ausencia de dudas abiertas
// =====================================================================
echo "\n--- 6. Enlace canónico y cierre de dudas ---\n";

$assert(
    '6.1 El espejo enlaza explícitamente al spec canónico',
    str_contains($mirror, 'specs/28-troubleshooting-guides/spec.md')
);
$canonicalBody = explode('## 9. Dudas Abiertas', $canonical)[0] ?? '';
$mirrorBody = explode('## 9. Dudas Abiertas', $mirror)[0] ?? '';

$assert(
    '6.2 Ninguno de los dos documentos conserva dudas abiertas',
    str_contains($canonical, '## 9. Dudas Abiertas')
        && str_contains($mirror, '## 9. Dudas Abiertas')
        && !str_contains($canonicalBody, '[NECESITA ACLARACIÓN]')
        && !str_contains($mirrorBody, '[NECESITA ACLARACIÓN]')
        && str_contains($canonical, 'Ninguna. Todas las decisiones')
        && str_contains($mirror, 'Ninguna. Todas las decisiones')
);

// =====================================================================
// GRUPO 7: Coherencia nominal del parque
// =====================================================================
echo "\n--- 7. Coherencia nominal del modelo real del parque ---\n";

$assert('7.1 El canónico nombra el modelo real «Fas Fast 900»', str_contains($canonical, 'Fas Fast 900'));
$assert('7.2 El canónico no conserva la grafía «Fasel Fast 900»', !str_contains($canonical, 'Fasel Fast 900'));
$assert('7.3 El espejo nombra el modelo real «Fas Fast 900»', str_contains($mirror, 'Fas Fast 900'));
$assert('7.4 El espejo no conserva la grafía «Fasel Fast 900»', !str_contains($mirror, 'Fasel Fast 900'));

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. ESPEJO FUNCIONAL CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
