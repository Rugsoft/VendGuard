<?php

declare(strict_types=1);

/**
 * PostalCodeValidationTest · T-ZONE-03
 *
 * Certifica la **capa de valores y excepciones** del Módulo 17 antes de que exista
 * ningún repositorio: si un CP entra mal, si un color no es `#RRGGBB` o si la
 * titularidad conflictiva no trae su código HTTP, el error se descubre aquí y no
 * en el panel del coordinador.
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **`PostalCode` (RF-01.2 / QA 7).** Normaliza con ceros a la izquierda
 *      (`801` → `00801`, `1` → `00001`), conserva los ceros originales, compara
 *      por valor y rechaza con `ZoneValidationException` (HTTP 400) todo lo que no
 *      sean de una a cinco cifras: cadenas vacías, cuatro dígitos, seis, letras,
 *      signos, decimales o cifras separadas. Es el mismo objeto que valida el CP
 *      de la sede en el alta y la edición, así que una sola regla sirve a los dos
 *      caminos.
 *   2. **`ZoneAffinityType` (RF-02.1 / RF-04.1).** Declara `PRIMARY`, `BACKUP` y
 *      `NONE` con sus etiquetas en castellano, y convierte desde texto sin
 *      distinguir mayúsculas ni espacios sobrantes.
 *   3. **`OperationalZone` (RF-01.1).** La entidad es inmutable (clase `final`,
 *      propiedades `readonly` y ningún setter), valida sus invariantes al
 *      construirse, normaliza código/nombre/color, se hidrata desde una fila del
 *      motor (`fromDatabaseRow`) y serializa al contrato (`toArray`/`jsonSerialize`).
 *   4. **Las cinco excepciones de dominio.** Cada una expone el código y el estado
 *      HTTP que declara el contrato: `ZONE_NOT_FOUND` 404, `BAD_REQUEST` 400 (con
 *      variante `UNPROCESSABLE_ENTITY` 422), `POSTAL_CODES_REQUIRE_CONFIRMATION`
 *      409, `TECHNICIAN_ALREADY_PRIMARY_IN_OTHER_ZONE` 409 y
 *      `COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED` 422 con su mínimo de 20
 *      caracteres (Art. V.1).
 *
 * Uso:
 *   php tests/unit/PostalCodeValidationTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\ColdChainOverrideJustificationRequiredException;
use VendGuard\Core\Domain\Exception\PostalCodeConflictException;
use VendGuard\Core\Domain\Exception\PrimaryTechnicianConflictException;
use VendGuard\Core\Domain\Exception\ZoneNotFoundException;
use VendGuard\Core\Domain\Exception\ZoneValidationException;
use VendGuard\Core\Domain\Model\OperationalZone;
use VendGuard\Core\Domain\ValueObject\PostalCode;
use VendGuard\Core\Domain\ValueObject\ZoneAffinityType;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** Ejecuta un bloque que debe lanzar `ZoneValidationException` y devuelve la excepción. */
$expectZoneRejection = static function (callable $operation): ?ZoneValidationException {
    try {
        $operation();
    } catch (ZoneValidationException $exception) {
        return $exception;
    } catch (Throwable $unexpected) {
        echo '  [FAIL] Se esperaba ZoneValidationException y llegó ' . $unexpected::class . "\n";

        return null;
    }

    return null;
};

echo "======================================================================\n";
echo " VENDGUARD · T-ZONE-03 · Valores y excepciones de zonificación operativa\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. Valor normativo y validación del código postal
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 1. PostalCode: normalización y formato ---\n";

$canonical = new PostalCode('08001');
$assert('1.1 Un CP de cinco dígitos conserva su valor', $canonical->value() === '08001', $canonical->value());

$padded = new PostalCode(801);
$assert('1.2 El CP numérico de tres cifras se rellena con ceros a la izquierda', $padded->value() === '00801', $padded->value());

$paddedShort = new PostalCode(1);
$assert('1.3 El CP numérico de una cifra se rellena hasta cinco dígitos', $paddedShort->value() === '00001', $paddedShort->value());

$paddedZero = new PostalCode(0);
$assert('1.4 El CP numérico 0 se normaliza a «00000»', $paddedZero->value() === '00000', $paddedZero->value());

$leadingZeros = new PostalCode('00001');
$assert('1.5 Los ceros a la izquierda originales no se pierden', $leadingZeros->value() === '00001', $leadingZeros->value());

$trimmed = new PostalCode(' 08001 ');
$assert('1.6 El CP con espacios sobrantes se recorta antes de validar', $trimmed->value() === '08001', $trimmed->value());

$assert(
    '1.7 La comprobación sin excepción coincide con la validación del constructor',
    PostalCode::isValid(801)
        && PostalCode::isValid('08001')
        && !PostalCode::isValid('801')
        && !PostalCode::isValid('080011')
        && !PostalCode::isValid('ABCDE')
);

$assert(
    '1.8 Dos CP equivalentes son iguales y se imprimen como su valor canónico',
    $canonical->equals(new PostalCode('08001')) && $canonical->equals($trimmed) && (string)$canonical === '08001',
    (string)$canonical
);

$invalidPostalCodes = [
    'vacío' => '',
    'sólo espacios' => '   ',
    'tres dígitos como texto' => '801',
    'cuatro dígitos' => '0800',
    'seis dígitos' => '080011',
    'letras' => 'ABCDE',
    'letra infiltrada' => '0800A',
    'signo negativo' => '-0801',
    'signo positivo' => '+0801',
    'decimal' => '8.001',
    'dígitos separados' => '0800 1',
    'entero de seis cifras' => 100000,
    'entero negativo' => -1,
];

foreach ($invalidPostalCodes as $case => $value) {
    $rejection = $expectZoneRejection(static fn (): PostalCode => new PostalCode($value));
    $assert(
        "1.9 El CP {$case} se rechaza con ZoneValidationException (400 BAD_REQUEST)",
        $rejection !== null
            && $rejection->getHttpStatusCode() === 400
            && $rejection->getErrorCode() === 'BAD_REQUEST'
            && isset($rejection->getFieldErrors()['postal_code']),
        $rejection === null ? 'no se lanzó la excepción' : 'estado=' . $rejection->getHttpStatusCode()
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Afinidad territorial del técnico
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 2. ZoneAffinityType: titular, suplente y sin zona ---\n";

$assert(
    '2.1 El enumerado declara exactamente PRIMARY, BACKUP y NONE',
    ZoneAffinityType::values() === ['PRIMARY', 'BACKUP', 'NONE'],
    implode(', ', ZoneAffinityType::values())
);

$assert(
    '2.2 Cada afinidad expone su etiqueta en castellano',
    ZoneAffinityType::PRIMARY->label() === 'Titular de zona'
        && ZoneAffinityType::BACKUP->label() === 'Suplente de zona'
        && ZoneAffinityType::NONE->label() === 'Sin zona asignada',
    ZoneAffinityType::PRIMARY->label() . ' · ' . ZoneAffinityType::BACKUP->label() . ' · ' . ZoneAffinityType::NONE->label()
);

$assert(
    '2.3 La conversión desde texto tolera mayúsculas y espacios',
    ZoneAffinityType::fromString('primary') === ZoneAffinityType::PRIMARY
        && ZoneAffinityType::fromString('  NONE ') === ZoneAffinityType::NONE,
    ZoneAffinityType::fromString(' backup ')->value
);

$assert(
    '2.4 La comprobación sin excepción reconoce sólo los valores del enumerado',
    ZoneAffinityType::isValid('BACKUP') && !ZoneAffinityType::isValid('OWNER')
);

$invalidAffinity = null;
try {
    ZoneAffinityType::fromString('OWNER');
} catch (InvalidArgumentException $exception) {
    $invalidAffinity = $exception->getMessage();
}

$assert(
    '2.5 Una afinidad desconocida se rechaza con el vocabulario permitido en el mensaje',
    $invalidAffinity !== null && str_contains($invalidAffinity, 'PRIMARY, BACKUP, NONE'),
    $invalidAffinity ?? 'no se lanzó la excepción'
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Entidad inmutable de zona operativa
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 3. OperationalZone: invariantes, hidratación y contrato ---\n";

$zone = new OperationalZone(
    7,
    '  zona-norte-bcn ',
    '  Litoral / Norte  ',
    '#10B981',
    '  Distritos de Sant Martí y Poblenou  ',
    true,
    '2026-10-20T08:00:00+00:00',
    '2026-10-20T09:30:00+00:00'
);

$assert(
    '3.1 La entidad expone sus datos con los valores normalizados',
    $zone->getId() === 7
        && $zone->getCode() === 'ZONA-NORTE-BCN'
        && $zone->getName() === 'Litoral / Norte'
        && $zone->getColorHex() === '#10b981'
        && $zone->getDescription() === 'Distritos de Sant Martí y Poblenou'
        && $zone->isActive() === true,
    $zone->getCode() . ' · ' . $zone->getName() . ' · ' . $zone->getColorHex()
);

$defaultZone = new OperationalZone(null, 'ZONA-CENTRO', 'Centro');
$assert(
    '3.2 Sin color explícito se aplica el azul del sistema de diseño',
    $defaultZone->getColorHex() === OperationalZone::DEFAULT_COLOR_HEX
        && $defaultZone->getId() === null
        && $defaultZone->isActive() === true,
    $defaultZone->getColorHex()
);

$blankDescription = new OperationalZone(1, 'ZONA-SUR', 'Sur', '#2560ff', '   ');
$assert('3.3 Una descripción en blanco se guarda como ausencia de descripción', $blankDescription->getDescription() === null);

$invalidZones = [
    'código corto' => static fn (): OperationalZone => new OperationalZone(null, 'AB', 'Zona válida'),
    'nombre corto' => static fn (): OperationalZone => new OperationalZone(null, 'ZONA-OK', ' A '),
    'color con nombre' => static fn (): OperationalZone => new OperationalZone(null, 'ZONA-OK', 'Zona válida', 'azul'),
    'color de cinco dígitos' => static fn (): OperationalZone => new OperationalZone(null, 'ZONA-OK', 'Zona válida', '#12345'),
    'identificador cero' => static fn (): OperationalZone => new OperationalZone(0, 'ZONA-OK', 'Zona válida'),
];

foreach ($invalidZones as $case => $build) {
    $rejection = $expectZoneRejection($build);
    $assert(
        "3.4 La zona con {$case} se rechaza al construirla (fail-fast)",
        $rejection !== null && $rejection->getHttpStatusCode() === 400,
        $rejection === null ? 'no se lanzó la excepción' : $rejection->getMessage()
    );
}

$row = [
    'id' => '12',
    'code' => 'ZONA-BCN-SUR',
    'name' => 'Barcelona Sur',
    'color_hex' => '#F59E0B',
    'description' => null,
    'is_active' => 't',
    'created_at' => '2026-10-20 08:00:00+00',
    'updated_at' => '2026-10-20 09:00:00+00',
];
$hydrated = OperationalZone::fromDatabaseRow($row);

$assert(
    '3.5 fromDatabaseRow hidrata el booleano del motor («t») y normaliza el color',
    $hydrated->getId() === 12
        && $hydrated->getColorHex() === '#f59e0b'
        && $hydrated->isActive() === true
        && $hydrated->getDescription() === null,
    'id=' . (string)$hydrated->getId() . ' · activa=' . ($hydrated->isActive() ? 'sí' : 'no')
);

$inactive = OperationalZone::fromDatabaseRow(array_merge($row, ['is_active' => false]));
$inactiveString = OperationalZone::fromDatabaseRow(array_merge($row, ['is_active' => 'f']));
$assert(
    '3.6 fromDatabaseRow entiende el booleano nativo y la forma «f»',
    $inactive->isActive() === false && $inactiveString->isActive() === false
);

$expectedArray = [
    'id' => 7,
    'code' => 'ZONA-NORTE-BCN',
    'name' => 'Litoral / Norte',
    'color_hex' => '#10b981',
    'description' => 'Distritos de Sant Martí y Poblenou',
    'is_active' => true,
    'created_at' => '2026-10-20T08:00:00+00:00',
    'updated_at' => '2026-10-20T09:30:00+00:00',
];

$assert(
    '3.7 toArray devuelve exactamente las claves del contrato en snake_case',
    $zone->toArray() === $expectedArray,
    implode(', ', array_keys($zone->toArray()))
);

$assert(
    '3.8 jsonSerialize es la misma proyección que toArray (los controladores no traducen)',
    $zone->jsonSerialize() === $zone->toArray(),
    json_encode($zone, JSON_UNESCAPED_UNICODE)
);

$reflection = new ReflectionClass(OperationalZone::class);
$setters = array_filter(
    $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
    static fn (ReflectionMethod $method): bool => str_starts_with($method->getName(), 'set')
);
$readonlyProperties = array_filter(
    $reflection->getProperties(),
    static fn (ReflectionProperty $property): bool => $property->isReadOnly()
);

$assert(
    '3.9 La entidad es inmutable: clase final, propiedades readonly y ningún setter',
    $reflection->isFinal()
        && count($readonlyProperties) === 8
        && $setters === [],
    'readonly=' . count($readonlyProperties) . ' · setters=' . count($setters)
);

$brokenRow = $expectZoneRejection(static fn (): OperationalZone => OperationalZone::fromDatabaseRow(['id' => 3, 'code' => 'ZONA-OK']));
$assert(
    '3.10 Una fila sin nombre no se hidrata en silencio: falla en voz alta',
    $brokenRow !== null && isset($brokenRow->getFieldErrors()['name'])
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Excepciones tipadas del módulo
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- 4. Excepciones: códigos y estados HTTP del contrato ---\n";

$notFound = new ZoneNotFoundException(42);
$assert(
    '4.1 ZoneNotFoundException expone 404 y ZONE_NOT_FOUND con el identificador',
    $notFound instanceof DomainException
        && $notFound->getHttpStatusCode() === 404
        && $notFound->getErrorCode() === 'ZONE_NOT_FOUND'
        && $notFound->getZoneId() === 42
        && str_contains($notFound->getMessage(), '42'),
    $notFound->getMessage()
);

$validation = ZoneValidationException::forField('color_hex', 'Color inválido.');
$assert(
    '4.2 ZoneValidationException responde 400 BAD_REQUEST y señala el campo',
    $validation instanceof DomainException
        && $validation->getHttpStatusCode() === 400
        && $validation->getErrorCode() === 'BAD_REQUEST'
        && ($validation->getDetails()['field_errors']['color_hex'] ?? '') === 'Color inválido.',
    $validation->getErrorCode()
);

$unprocessable = ZoneValidationException::unprocessable('La zona no puede reactivarse sin códigos postales.');
$assert(
    '4.3 La variante de regla de negocio responde 422 UNPROCESSABLE_ENTITY',
    $unprocessable->getHttpStatusCode() === 422 && $unprocessable->getErrorCode() === 'UNPROCESSABLE_ENTITY',
    $unprocessable->getErrorCode()
);

$conflict = new PostalCodeConflictException([
    ['postal_code' => '08001', 'current_zone_id' => 2, 'current_zone_name' => 'Barcelona Centro', 'affected_locations_count' => 3],
]);
$assert(
    '4.4 PostalCodeConflictException responde 409 y transporta el detalle del modal',
    $conflict instanceof DomainException
        && $conflict->getHttpStatusCode() === 409
        && $conflict->getErrorCode() === 'POSTAL_CODES_REQUIRE_CONFIRMATION'
        && count($conflict->getConflicts()) === 1
        && ($conflict->getDetails()['conflicts'][0]['affected_locations_count'] ?? 0) === 3
        && str_contains(mb_strtolower($conflict->getMessage()), 'confirmación de traslado'),
    $conflict->getMessage()
);

$primaryConflict = new PrimaryTechnicianConflictException(5, 1, 'Barcelona Litoral y Norte');
$assert(
    '4.5 PrimaryTechnicianConflictException responde 409 con el titularato vigente',
    $primaryConflict instanceof DomainException
        && $primaryConflict->getHttpStatusCode() === 409
        && $primaryConflict->getErrorCode() === 'TECHNICIAN_ALREADY_PRIMARY_IN_OTHER_ZONE'
        && $primaryConflict->getTechnicianId() === 5
        && $primaryConflict->getCurrentZoneId() === 1
        && $primaryConflict->getCurrentZoneName() === 'Barcelona Litoral y Norte'
        && str_contains($primaryConflict->getMessage(), 'titular'),
    $primaryConflict->getMessage()
);

$coldChain = new ColdChainOverrideJustificationRequiredException('Técnico ocupado en otra sede.');
$assert(
    '4.6 La salvaguarda de frío responde 422 y exige 20 caracteres (Art. II / Art. V.1)',
    $coldChain instanceof DomainException
        && $coldChain->getHttpStatusCode() === 422
        && $coldChain->getErrorCode() === 'COLD_CHAIN_OVERRIDE_JUSTIFICATION_REQUIRED'
        && ColdChainOverrideJustificationRequiredException::MINIMUM_LENGTH === 20
        && ($coldChain->getDetails()['minimum_length'] ?? 0) === 20
        && ($coldChain->getDetails()['justification_length'] ?? 0) === mb_strlen('Técnico ocupado en otra sede.')
        && str_contains($coldChain->getMessage(), 'Art. II'),
    $coldChain->getMessage()
);

$assert(
    '4.7 Las cinco excepciones comparten el contrato de error del controlador',
    $notFound instanceof DomainException
        && $validation instanceof DomainException
        && $conflict instanceof DomainException
        && $primaryConflict instanceof DomainException
        && $coldChain instanceof DomainException
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-ZONE-03 CUMPLIDA CON ÉXITO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
