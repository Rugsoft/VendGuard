<?php

declare(strict_types=1);

/**
 * CsatPrivacyConstitutionalTest · T-CSAT-05
 *
 * Certifica la frontera de privacidad del Módulo 20 (Art. V.4, R2): la encuesta
 * de satisfacción es la voz de la sede, y la sede no puede deducir de ella quién
 * intervino ni cuánto costó el servicio.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **La respuesta de sede no lleva técnico.** Ni `technician_id`, ni el
 *      nombre congelado, ni el operador. Se comprueba sobre el JSON real de un
 *      DTO construido a partir de una encuesta que **sí** lleva técnico: si el
 *      dato no aparece ahí, no aparece por ningún camino de lectura.
 *   2. **El listado de pendientes tampoco.** La consulta de pendientes no
 *      selecciona ninguna columna de técnico, y la proyección expone exactamente
 *      cinco claves operativas.
 *   3. **El controlador no lee ni sirve identidad técnica.** No menciona
 *      técnicos ni auditoría: solo traduce la excepción de dominio y proyecta el
 *      DTO.
 *   4. **El módulo no tiene superficie de técnico ni de coordinación en esta
 *      entrega.** Las dos rutas viven bajo `/api/location/` detrás de
 *      `SiteAuthMiddleware`; ninguna otra superficie las sirve.
 *   5. **La respuesta es inmutable.** Ni el puerto declara `update`/`delete` ni
 *      el repositorio escribe `UPDATE` o `DELETE` sobre la tabla.
 *
 * Esta suite es hermética: no toca el motor ni la base de datos.
 *
 * Uso: php tests/unit/CsatPrivacyConstitutionalTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\SatisfactionSurveyDto;
use VendGuard\Core\Domain\Exception\SatisfactionSurveyValidationException;
use VendGuard\Core\Domain\Model\IncidentSatisfactionSurvey;
use VendGuard\Core\Domain\Repository\SatisfactionSurveyRepositoryInterface;

$baseDir = dirname(__DIR__, 2);
$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($ok) {
        echo "  [PASS] {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";

        return;
    }
    $failures++;
    echo "  [FAIL] {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
};

$read = static fn(string $relative): string => (string)file_get_contents($baseDir . '/' . $relative);

/**
 * Devuelve el código sin comentarios: la documentación de un módulo habla de
 * técnicos precisamente para prohibirlos, y eso no es una fuga.
 */
$codeOnly = static function (string $source): string {
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];
            continue;
        }

        $code .= $token;
    }

    return $code;
};

/**
 * Devuelve el fragmento de `$source` entre dos marcadores literales.
 */
$between = static function (string $source, string $start, string $end): string {
    $from = strpos($source, $start);
    if ($from === false) {
        return '';
    }

    $to = strpos($source, $end, $from);
    if ($to === false) {
        return '';
    }

    return substr($source, $from, $to - $from);
};

echo "======================================================================\n";
echo " VENDGUARD · T-CSAT-05 · Privacidad de la encuesta CSAT (Módulo 20)\n";
echo "======================================================================\n";

// =============================================================================
// 1. La respuesta de sede no lleva técnico (R2, Art. V.4)
// =============================================================================
echo "\n--- 1. Respuesta de sede: ni técnico ni nombre congelado ---\n";

$surveyWithTechnician = IncidentSatisfactionSurvey::create(
    incidentId: 1841,
    locationId: 3,
    technicianId: 7,
    technicianNameSnapshot: 'Ana Ruiz',
    resolutionAt: '2026-10-22 17:04:11+02',
    rating: 5,
    comment: 'Vinieron el mismo día.',
)->withIdentity(92, '2026-10-23 09:15:02+02');

$dto = SatisfactionSurveyDto::fromEntity($surveyWithTechnician, 'INC-20261022-0007');
$payload = $dto->toArray();
$serialized = (string)json_encode($dto, JSON_UNESCAPED_UNICODE);

$assert(
    'La proyección de sede tiene exactamente las seis claves del contrato §4.1',
    array_keys($payload) === ['id', 'incident_id', 'ticket_code', 'rating', 'comment', 'created_at'],
    implode(',', array_keys($payload))
);

$assert(
    'El JSON de la respuesta no contiene la palabra «technician»',
    stripos($serialized, 'technician') === false,
    $serialized
);

$assert(
    'El nombre congelado del técnico no viaja a la sede',
    strpos($serialized, 'Ana Ruiz') === false && !str_contains($serialized, 'technician_name'),
    $serialized
);

$assert(
    'El id del técnico no aparece como valor suelto en la respuesta',
    !in_array(7, $payload, true),
    json_encode($payload)
);

$dtoProperties = array_map(
    static fn(ReflectionProperty $property): string => $property->getName(),
    (new ReflectionClass(SatisfactionSurveyDto::class))->getProperties()
);

$assert(
    'El DTO no declara ninguna propiedad de técnico',
    $dtoProperties !== []
        && array_filter($dtoProperties, static fn(string $name): bool => stripos($name, 'technician') !== false) === [],
    implode(',', $dtoProperties)
);

// =============================================================================
// 2. El listado de pendientes tampoco
// =============================================================================
echo "\n--- 2. Listado de pendientes: cinco claves operativas ---\n";

$repositorySource = $read('src/Infrastructure/Repository/PdoSatisfactionSurveyRepository.php');
$repositoryCode = $codeOnly($repositorySource);
$pendingMethod = $between($repositorySource, 'function findPendingByLocation', 'function countPendingByLocation');
$pendingProjection = $between($pendingMethod, '$items[] = [', '];');

$assert(
    'La consulta de pendientes no selecciona ninguna columna de técnico',
    $pendingMethod !== ''
        && stripos($pendingMethod, 'technician') === false
        && stripos($pendingMethod, 'u.name') === false
);

$pendingKeys = [];
if (preg_match_all("/'([a-z_]+)' =>/", $pendingProjection, $matches) > 0) {
    $pendingKeys = $matches[1];
}

$assert(
    'Cada pendiente expone exactamente las cinco claves operativas del contrato §3.1',
    $pendingKeys === ['incident_id', 'ticket_code', 'machine_code', 'machine_model', 'resolved_at'],
    implode(',', $pendingKeys)
);

// =============================================================================
// 3. El controlador no lee ni sirve identidad técnica
// =============================================================================
echo "\n--- 3. Controlador de sede ---\n";

$controllerSource = $read('src/Presentation/Controller/LocationSatisfactionController.php');
$controllerCode = $codeOnly($controllerSource);

$assert(
    'El controlador no menciona técnicos en su código',
    $controllerCode !== '' && stripos($controllerCode, 'technician') === false
);

$assert(
    'El controlador no expone eventos de auditoría al cliente',
    $controllerCode !== '' && stripos($controllerCode, 'audit') === false
);

$assert(
    'La respuesta 201 se construye solo con el DTO (sin campos añadidos a mano)',
    str_contains($controllerSource, 'Response::json($survey->toArray(), 201)')
);

// =============================================================================
// 4. Superficie única: portal de sede
// =============================================================================
echo "\n--- 4. Rutas del módulo ---\n";

$routerSource = $read('src/Presentation/Routing/AppRouter.php');
$routeLines = array_values(array_filter(
    preg_split('/\R/', $routerSource) ?: [],
    static fn(string $line): bool => str_contains($line, 'satisfaction')
));

$assert(
    'El módulo registra exactamente dos rutas',
    count($routeLines) === 2,
    (string)count($routeLines)
);

$assert(
    'Las dos rutas viven bajo /api/location/ y van tras SiteAuthMiddleware',
    count($routeLines) === 2
        && array_reduce(
            $routeLines,
            static fn(bool $carry, string $line): bool => $carry
                && str_contains($line, "'/api/location/")
                && str_contains($line, '[$siteAuth]'),
            true
        ),
    implode(' | ', array_map('trim', $routeLines))
);

// =============================================================================
// 5. Inmutabilidad de la respuesta (Art. III.1, RD-06)
// =============================================================================
echo "\n--- 5. Respuesta inmutable ---\n";

$portMethods = array_map('strtolower', get_class_methods(SatisfactionSurveyRepositoryInterface::class));

$assert(
    'El puerto no declara update ni delete',
    !in_array('update', $portMethods, true)
        && !in_array('delete', $portMethods, true),
    implode(',', $portMethods)
);

$assert(
    'El repositorio no escribe UPDATE ni DELETE sobre la tabla de encuestas',
    preg_match('/\b(UPDATE\s+incident_satisfaction_surveys|DELETE\s+FROM\s+incident_satisfaction_surveys)\b/i', $repositorySource) !== 1
);

$assert(
    'Los errores de la valoración no arrastran datos de técnico',
    (static fn(): bool => SatisfactionSurveyValidationException::alreadySubmitted()->getFieldErrors() === [])()
);

echo "\n======================================================================\n";
echo sprintf(" RESULTADO: %d aserciones · %d fallos\n", $assertions, $failures);
echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);
