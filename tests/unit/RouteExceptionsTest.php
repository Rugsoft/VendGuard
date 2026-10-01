<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\CoordinatesOutOfBoundsException;
use VendGuard\Core\Domain\Exception\InvalidCoordinatesException;
use VendGuard\Core\Domain\Exception\SiteRouteDataForbiddenException;

$assertions = 0;

function assertRouteExceptionCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $invalidCoordinates = new InvalidCoordinatesException();
    assertRouteExceptionCondition($invalidCoordinates instanceof DomainException, 'invalid coordinates exception is a domain exception');
    assertRouteExceptionCondition($invalidCoordinates->getErrorCode() === 'INVALID_COORDINATES', 'invalid coordinates uses the contract error code');
    assertRouteExceptionCondition($invalidCoordinates->getHttpStatusCode() === 422, 'invalid coordinates maps to HTTP 422');
    assertRouteExceptionCondition($invalidCoordinates->getCode() === 422, 'invalid coordinates exposes HTTP 422 as the native exception code');
    assertRouteExceptionCondition(str_contains($invalidCoordinates->getMessage(), 'coordenadas'), 'invalid coordinates message is in Spanish');
    assertRouteExceptionCondition(
        str_contains($invalidCoordinates->getMessage(), 'numéricas'),
        'invalid coordinates message explains numeric coordinate requirements'
    );

    $invalidWithDetails = new InvalidCoordinatesException('La latitud y la longitud deben ser numéricas.', 'latitude');
    assertRouteExceptionCondition($invalidWithDetails->getCoordinate() === 'latitude', 'invalid coordinates preserves the rejected coordinate name');
    assertRouteExceptionCondition(
        $invalidWithDetails->getDetails() === ['coordinate' => 'latitude'],
        'invalid coordinates serializes contextual details'
    );

    $outOfBounds = new CoordinatesOutOfBoundsException();
    assertRouteExceptionCondition($outOfBounds instanceof DomainException, 'out-of-bounds exception is a domain exception');
    assertRouteExceptionCondition($outOfBounds->getErrorCode() === 'COORDINATES_OUT_OF_BOUNDS', 'out-of-bounds uses the contract error code');
    assertRouteExceptionCondition($outOfBounds->getHttpStatusCode() === 422, 'out-of-bounds maps to HTTP 422');
    assertRouteExceptionCondition($outOfBounds->getCode() === 422, 'out-of-bounds exposes HTTP 422 as the native exception code');
    assertRouteExceptionCondition(str_contains($outOfBounds->getMessage(), 'territorio geográfico operativo'), 'out-of-bounds message describes the operational territory');

    $outOfBoundsWithDetails = new CoordinatesOutOfBoundsException(
        'Las coordenadas están fuera del territorio operativo.',
        0.0,
        0.0
    );
    assertRouteExceptionCondition($outOfBoundsWithDetails->getLatitude() === 0.0, 'out-of-bounds preserves the rejected latitude');
    assertRouteExceptionCondition($outOfBoundsWithDetails->getLongitude() === 0.0, 'out-of-bounds preserves the rejected longitude');
    assertRouteExceptionCondition(
        $outOfBoundsWithDetails->getDetails() === ['latitude' => 0.0, 'longitude' => 0.0],
        'out-of-bounds serializes contextual coordinate details'
    );

    $forbidden = new SiteRouteDataForbiddenException();
    assertRouteExceptionCondition($forbidden instanceof DomainException, 'site route access exception is a domain exception');
    assertRouteExceptionCondition($forbidden->getErrorCode() === 'FORBIDDEN', 'site route access uses the technical contract error code');
    assertRouteExceptionCondition($forbidden->getHttpStatusCode() === 403, 'site route access maps to HTTP 403');
    assertRouteExceptionCondition($forbidden->getCode() === 403, 'site route access exposes HTTP 403 as the native exception code');
    assertRouteExceptionCondition($forbidden->getAttemptedRole() === 'LOCATION_MANAGER', 'site route access records the denied role');
    assertRouteExceptionCondition(str_contains($forbidden->getMessage(), 'Artículo V.4'), 'forbidden message cites constitutional privacy');
    assertRouteExceptionCondition(str_contains($forbidden->getMessage(), 'responsables de sede'), 'forbidden message is in Spanish and identifies the audience');

    $forbiddenWithContext = new SiteRouteDataForbiddenException(
        'TECHNICIAN',
        '/api/coordinator/map/active-incidents'
    );
    assertRouteExceptionCondition($forbiddenWithContext->getAttemptedRole() === 'TECHNICIAN', 'site route access preserves the attempted role');
    assertRouteExceptionCondition(
        $forbiddenWithContext->getRequestedResource() === '/api/coordinator/map/active-incidents',
        'site route access preserves the requested resource'
    );

    echo "Route exception tests passed. Total Assertions: {$assertions}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Route exception test failed: {$exception->getMessage()}\n");
    exit(1);
}
