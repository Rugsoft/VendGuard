<?php

declare(strict_types=1);

/**
 * IncidentPauseSiteCommentRepealTest
 *
 * Guarda de la derogación de la reactivación automática por comentario de sede
 * (módulo 11, enmienda del Product Owner E-1, 2026-10-11).
 *
 * El defecto reportado: un mensaje de la sede sobre un expediente pausado lo despausaba
 * solo y lo devolvía a «En curso» (rama en caliente de RF-02.1). El Product Owner lo
 * declaró contrario a la operación —el sistema no puede afirmar que el técnico está
 * delante de la máquina porque un tercero haya escrito— y la pausa quedó cerrada sólo
 * por acto explícito o por la cancelación justificada por inactividad.
 *
 * Esta suite es la guarda estructural de esa derogación: certifica que ni el servicio de
 * aplicación, ni el controlador del portal de sede, ni el frontend conservan el camino
 * automático —y que sus piezas de contrato (`auto_resumed`, la ventana de calidez y el
 * código de error del gancho) no reaparecen por descuido—, y que las dos reanudaciones
 * MANUALES siguen en pie como únicos cierres de pausa.
 *
 * La mitad comportamental —un comentario a los 20 y a los 90 minutos deja el expediente
 * intacto con la marca de respuesta activa— se certifica por el camino real en
 * `tests/integration/PendingInfoSiteCommentNoReactivationTest.php`, y la marca derivada
 * que consume la tarjeta móvil, en
 * `tests/unit/TechnicianRouteSiteResponseMarkerTest.php`.
 *
 * Dogma Vanilla: PHP 8.2 puro — reflexión y lectura de fuentes del propio repositorio,
 * sin librerías externas, sin red y sin base de datos.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Application\Service\IncidentPauseService;
use VendGuard\Presentation\Controller\LocationPortalController;

echo "======================================================================\n";
echo " VendGuard: Guarda de derogación de la reactivación por comentario (E-1)\n";
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

/**
 * Raíz del repositorio, resuelta desde este archivo para que la guarda funcione igual
 * desde cualquier directorio de trabajo.
 */
$repoRoot = dirname(__DIR__, 2);

/**
 * Lee un archivo del repositorio sin fallar si no existe.
 */
$read = static fn (string $relativePath): string => is_file($repoRoot . '/' . $relativePath)
    ? (string)file_get_contents($repoRoot . '/' . $relativePath)
    : '';

/**
 * Concatena el contenido de todos los archivos PHP/JS de un directorio del repositorio,
 * ignorando las carpetas de dependencias externas.
 *
 * @return array<string, string> Ruta relativa => contenido.
 */
$sourcesUnder = static function (string $relativeDir) use ($repoRoot): array {
    $base = $repoRoot . '/' . $relativeDir;
    if (!is_dir($base)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        if (!in_array(strtolower($file->getExtension()), ['php', 'js', 'mjs'], true)) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        if (str_contains($path, '/node_modules/')) {
            continue;
        }

        $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $repoRoot))), '/');
        $files[$relative] = (string)file_get_contents($path);
    }

    ksort($files);

    return $files;
};

/**
 * Archivos del árbol de código que contienen una aguja dada.
 *
 * @param array<string, string> $sources
 * @return list<string>
 */
$filesContaining = static function (array $sources, string $needle): array {
    $hits = [];
    foreach ($sources as $relative => $content) {
        if (str_contains($content, $needle)) {
            $hits[] = $relative;
        }
    }

    return $hits;
};

// =====================================================================
// GRUPO 1: El servicio de aplicación no conserva el camino automático
// =====================================================================
echo "--- Grupo 1: El servicio de pausa ya no reanuda por comentario de sede ---\n";

$serviceReflection = new ReflectionClass(IncidentPauseService::class);

$assert(
    '1.1 El servicio no expone handleSiteCommentReactivation (derogado en E-1)',
    !$serviceReflection->hasMethod('handleSiteCommentReactivation'),
    'Métodos públicos reales: ' . implode(', ', array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $serviceReflection->getMethods(ReflectionMethod::IS_PUBLIC)
    ))
);

$assert(
    '1.2 La ventana de calidez de 60 minutos ya no existe como constante pública',
    !$serviceReflection->hasConstant('HOT_REACTIVATION_WINDOW_SECONDS')
        && !defined(IncidentPauseService::class . '::HOT_REACTIVATION_WINDOW_SECONDS')
);

$assert(
    '1.3 El mínimo de sustancia del comentario de sede ya no existe como constante',
    !$serviceReflection->hasConstant('MIN_SITE_COMMENT_LENGTH')
        && !defined(IncidentPauseService::class . '::MIN_SITE_COMMENT_LENGTH')
);

$assert(
    '1.4 Las reanudaciones MANUALES siguen en el contrato público del servicio',
    $serviceReflection->hasMethod('resumePendingInfoManually')
        && $serviceReflection->getMethod('resumePendingInfoManually')->isPublic()
        && $serviceReflection->hasMethod('declarePendingInfoPause')
        && $serviceReflection->hasMethod('cancelByInactivity')
);

$assert(
    '1.5 La cancelación justificada por inactividad sigue siendo la tercera salida de la pausa',
    $serviceReflection->hasConstant('PROLONGED_INACTIVITY_BUSINESS_HOURS')
        && (int)$serviceReflection->getConstant('PROLONGED_INACTIVITY_BUSINESS_HOURS') === 72
);

$serviceSource = $read('src/Application/Service/IncidentPauseService.php');
$assert(
    '1.6 El código del servicio no conserva rastros del algoritmo derogado',
    !str_contains($serviceSource, 'handleSiteCommentReactivation')
        && !str_contains($serviceSource, 'HOT_REACTIVATION_WINDOW_SECONDS')
        && !str_contains($serviceSource, 'MIN_SITE_COMMENT_LENGTH')
        && !str_contains($serviceSource, 'reactivationNote')
);

// =====================================================================
// GRUPO 2: El portal de sede publica el hilo, no una transición
// =====================================================================
echo "\n--- Grupo 2: El comentario de la sede no transiciona el expediente ---\n";

$portalReflection = new ReflectionClass(LocationPortalController::class);
$portalConstructorParameters = array_map(
    static fn (ReflectionParameter $parameter): string => (string)$parameter->getType(),
    $portalReflection->getConstructor()?->getParameters() ?? []
);

$assert(
    '2.1 El portal de sede ya no depende del servicio de pausa',
    !in_array(IncidentPauseService::class, $portalConstructorParameters, true)
        && !$portalReflection->hasProperty('pauseService')
        && !$portalReflection->hasMethod('pauses'),
    'Parámetros reales: ' . implode(', ', $portalConstructorParameters)
);

$portalSource = $read('src/Presentation/Controller/LocationPortalController.php');
$assert(
    '2.2 El portal no conserva el gancho de reactivación ni su código de error',
    !str_contains($portalSource, 'handleSiteCommentReactivation')
        && !str_contains($portalSource, 'REACTIVATION_FAILED')
        && !str_contains($portalSource, 'IncidentPauseService')
);

$assert(
    '2.3 La respuesta 201 del comentario es el hilo canónico, sin bloque de pausa',
    !str_contains($portalSource, 'auto_resumed')
        && !str_contains($portalSource, "\$payload['pause']")
        && !str_contains($portalSource, 'pauseBlock')
);

$assert(
    '2.4 El sellado del expediente cancelado sigue vigente en el portal (RF-05.4)',
    str_contains($portalSource, 'ConversationSealedException')
        && str_contains($portalSource, 'sealedCommentMessage')
        && str_contains($portalSource, 'getHttpStatusCode()')
);

// =====================================================================
// GRUPO 3: Ni el backend ni el frontend reintroducen el camino automático
// =====================================================================
echo "\n--- Grupo 3: Guarda transversal sobre el árbol de código ---\n";

$sourceTree = $sourcesUnder('src');
$frontendTree = $sourcesUnder('public/assets/js');

$assert(
    '3.0 La guarda lee de verdad el árbol de código (premisa de fixture)',
    count($sourceTree) > 50 && count($frontendTree) > 20,
    'Archivos backend: ' . count($sourceTree) . ' · frontend: ' . count($frontendTree)
);

$retiredIdentifiers = [
    'handleSiteCommentReactivation',
    'HOT_REACTIVATION_WINDOW_SECONDS',
    'REACTIVATION_FAILED',
    'auto_resumed',
];

foreach ($retiredIdentifiers as $position => $identifier) {
    $hits = array_merge(
        $filesContaining($sourceTree, $identifier),
        $filesContaining($frontendTree, $identifier)
    );
    $assert(
        '3.' . ($position + 1) . " Ningún archivo de código conserva «{$identifier}»",
        $hits === [],
        'Archivos con rastro: ' . implode(', ', $hits)
    );
}

$autoResumeCopyHits = array_merge(
    $filesContaining($frontendTree, 'se reanudará automáticamente'),
    $filesContaining($frontendTree, 'despausar')
);
$assert(
    '3.5 El portal de sede no promete ninguna reanudación automática',
    $autoResumeCopyHits === [],
    'Archivos con copy derogado: ' . implode(', ', $autoResumeCopyHits)
);

// =====================================================================
// GRUPO 4: Las dos salidas explícitas siguen enrutadas
// =====================================================================
echo "\n--- Grupo 4: Las reanudaciones explícitas siguen disponibles ---\n";

$routerSource = $read('src/Presentation/Routing/AppRouter.php');

$assert(
    '4.1 El técnico conserva su reanudación in situ por HTTP',
    str_contains($routerSource, '/api/technician/incidents/{id}/resume-pending-info')
);

$assert(
    '4.2 El coordinador conserva su reanudación con destino explícito por HTTP',
    str_contains($routerSource, '/api/coordinator/incidents/{id}/resume-pending-info')
);

$technicianSource = $read('src/Presentation/Controller/TechnicianController.php');
$coordinatorSource = $read('src/Presentation/Controller/CoordinatorController.php');
$assert(
    '4.3 Ambas puertas invocan la reanudación manual del servicio de pausa',
    str_contains($technicianSource, 'resumePendingInfoManually')
        && str_contains($coordinatorSource, 'resumePendingInfoManually')
);

$assert(
    '4.4 El agregado mantiene la guarda estado-responsable (F-5) para las reanudaciones manuales',
    str_contains($read('src/Core/Domain/Model/Incident.php'), 'public function resumePendingInfo(')
);

$assert(
    '4.5 La marca derivada de respuesta de sede sigue siendo la única huella del comentario',
    str_contains($read('src/Presentation/Controller/TechnicianController.php'), 'site_response_since_pause')
        && str_contains($read('src/Core/Domain/Repository/IncidentSiteResponseRepositoryInterface.php'), 'hasSiteResponseSinceCurrentPause')
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. DEROGACIÓN E-1 CUSTODIADA SIN RASTROS VIVOS.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
