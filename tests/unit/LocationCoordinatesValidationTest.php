<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Presentation\Controller\CoordinatorAdminController;
use VendGuard\Presentation\Http\Request;

$assertions = 0;

function assertCoordinateCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$controllerReflection = new \ReflectionClass(CoordinatorAdminController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$validator = new \ReflectionMethod(CoordinatorAdminController::class, 'validateLocationCoordinates');

$invalidCases = [
    ['missing both coordinates', [], 'INVALID_COORDINATES'],
    ['missing latitude', ['longitude' => 2.17], 'INVALID_COORDINATES'],
    ['missing longitude', ['latitude' => 41.38], 'INVALID_COORDINATES'],
    ['non-numeric latitude', ['latitude' => 'north', 'longitude' => 2.17], 'INVALID_COORDINATES'],
    ['non-numeric longitude', ['latitude' => 41.38, 'longitude' => 'east'], 'INVALID_COORDINATES'],
    ['latitude outside territory', ['latitude' => 26.99, 'longitude' => 2.17], 'COORDINATES_OUT_OF_BOUNDS'],
    ['longitude outside territory', ['latitude' => 41.38, 'longitude' => 5.01], 'COORDINATES_OUT_OF_BOUNDS'],
    ['null-island coordinates', ['latitude' => 0, 'longitude' => 0], 'COORDINATES_OUT_OF_BOUNDS'],
    ['coordinates with swapped axes', ['latitude' => 2.17, 'longitude' => 41.38], 'COORDINATES_OUT_OF_BOUNDS'],
    ['numeric infinity', ['latitude' => '1e309', 'longitude' => 2.17], 'COORDINATES_OUT_OF_BOUNDS'],
];

foreach ($invalidCases as [$caseName, $coordinates, $expectedCode]) {
    $response = $validator->invoke($controller, $coordinates);
    assertCoordinateCondition($response !== null, "{$caseName} produces a validation response");
    assertCoordinateCondition($response->getStatusCode() === 422, "{$caseName} returns HTTP 422");
    $payload = $response->getDecodedBody();
    assertCoordinateCondition(
        ($payload['error']['code'] ?? '') === $expectedCode,
        "{$caseName} maps to {$expectedCode}"
    );
}

$missingCoordinatesMessage = $validator->invoke($controller, [])->getDecodedBody()['error']['message'] ?? '';
assertCoordinateCondition(
    $missingCoordinatesMessage === 'Las coordenadas geográficas (latitud y longitud) son obligatorias y deben ser numéricas.',
    'Missing coordinates use the approved Spanish API message'
);

$outsideTerritoryMessage = $validator->invoke($controller, ['latitude' => 20, 'longitude' => 2])->getDecodedBody()['error']['message'] ?? '';
assertCoordinateCondition(
    $outsideTerritoryMessage === 'Las coordenadas especificadas se encuentran fuera del territorio geográfico operativo permitido.',
    'Out-of-area coordinates use the approved Spanish API message'
);

$validCoordinates = [
    ['territory minimum', 27.0, -18.5],
    ['territory maximum', 44.5, 5.0],
    ['Barcelona location', 41.385312, 2.193245],
];
foreach ($validCoordinates as [$caseName, $latitude, $longitude]) {
    assertCoordinateCondition(
        $validator->invoke($controller, ['latitude' => $latitude, 'longitude' => $longitude]) === null,
        "{$caseName} passes coordinate validation"
    );
}

$createResponse = $controller->createLocation(new Request('POST', '/api/coordinator/locations'));
assertCoordinateCondition($createResponse->getStatusCode() === 422, 'Create endpoint rejects missing coordinates before using persistence');

$updateRequest = (new Request('PUT', '/api/coordinator/locations/5'))->setRouteParams(['id' => '5']);
$updateResponse = $controller->updateLocation($updateRequest);
assertCoordinateCondition($updateResponse->getStatusCode() === 422, 'Update endpoint rejects missing coordinates before using persistence');

fwrite(STDOUT, "Location coordinate validation tests passed ({$assertions} assertions).\n");
