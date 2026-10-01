<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Unit suite for the domain exceptions of T-REF-03.
 *
 * The "Hecho cuando" criterion requires evaluating the normalized HTTP status
 * codes (403, 409, 422), the Spanish messages defined in the contracts
 * catalogue, the antifraud limits and the anonymization guarantees, so each
 * exception is asserted against the exact contract text rather than merely
 * against its own class name.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Core\Domain\Exception\InvalidBizumPhoneException;
use VendGuard\Core\Domain\Exception\InvalidIbanFormatException;
use VendGuard\Core\Domain\Exception\InvalidPickupPinException;
use VendGuard\Core\Domain\Exception\InvalidRefundAmountException;
use VendGuard\Core\Domain\Exception\InvalidRefundStateTransitionException;
use VendGuard\Core\Domain\Exception\ReceptionDeliveryNotAllowedException;
use VendGuard\Core\Domain\Exception\SiteRefundDataForbiddenException;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\RefundStatus;

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
 * Every refund exception must be a DomainException so the HTTP layer can catch
 * the whole family in one place.
 */
$assertCommon = function (string $label, DomainException $e, int $expectedStatus, string $expectedCode) use ($assert): void {
    $assert(
        "{$label} mapea al estado HTTP {$expectedStatus}",
        $e->getHttpStatusCode() === $expectedStatus,
        "Obtenido: {$e->getHttpStatusCode()}"
    );

    $assert(
        "{$label} expone el error code {$expectedCode}",
        $e->getErrorCode() === $expectedCode,
        "Obtenido: {$e->getErrorCode()}"
    );

    $assert(
        "{$label} hereda el estado HTTP en el código nativo de excepción",
        $e->getCode() === $expectedStatus,
        "Obtenido: {$e->getCode()}"
    );

    $assert(
        "{$label} es una excepción de dominio (DomainException)",
        $e instanceof DomainException
    );
};

echo "\n--- 0. Guardas estáticas de anti-fuga (se ejecutan antes de instanciar) ---\n";

// Estas comprobaciones trabajan sobre el código fuente y no sobre instancias,
// de modo que una fuga del secreto se reporta como fallo limpio en lugar de
// provocar un crashe por argumentos en la sección 4.
$exceptionDir = $baseDir . '/src/Core/Domain/Exception';

$refundExceptions = array_values(array_filter(
    glob($exceptionDir . '/*.php') ?: [],
    static function (string $path): bool {
        $name = basename($path, '.php');

        return str_starts_with($name, 'Invalid') || str_starts_with($name, 'Reception')
            || str_starts_with($name, 'SiteRefund');
    }
));

$assert(
    '0.1 Se localizan las siete excepciones del módulo para su inspección estática',
    count($refundExceptions) >= 7,
    'Excepciones encontradas: ' . count($refundExceptions)
);

$pinLeak = [];
$ibanLeak = [];
$sqlLeak = [];

foreach ($refundExceptions as $path) {
    $name = basename($path, '.php');
    $source = (string)file_get_contents($path);

    if (str_contains($source, 'ES9121000418450200051332')) {
        $ibanLeak[] = "{$name}: IBAN real incrustado";
    }

    if (str_contains($source, 'private readonly string $pin') || str_contains($source, '$this->pin')) {
        $pinLeak[] = "{$name}: almacena o concatena el PIN de recogida";
    }

    $upper = strtoupper($source);
    if (str_contains($upper, 'SELECT ') || str_contains($upper, 'INSERT ')
        || str_contains($upper, 'UPDATE ') || str_contains($upper, 'DELETE FROM')) {
        $sqlLeak[] = "{$name}: ejecuta SQL desde la capa de dominio";
    }
}

$assert(
    '0.2 Ninguna excepción de reintegro incrusta un IBAN real en el código (Art. V.4)',
    empty($ibanLeak),
    implode('; ', $ibanLeak)
);

$assert(
    '0.3 Ninguna excepción de reintegro acepta ni concatena el PIN de recogida (Art. V.4)',
    empty($pinLeak),
    implode('; ', $pinLeak)
);

$assert(
    '0.4 Ninguna excepción del módulo ejecuta SQL (separación de responsabilidades)',
    empty($sqlLeak),
    implode('; ', $sqlLeak)
);

echo "\n--- 1. InvalidRefundAmountException (422 / RF-REF-03) ---\n";

$amount = new InvalidRefundAmountException(75.00);

$assertCommon('1.1 InvalidRefundAmountException', $amount, 422, 'INVALID_REFUND_AMOUNT');

$assert(
    '1.2 El mensaje en castellano es el del catálogo de contratos',
    $amount->getMessage() === 'El importe reclamado debe ser mayor a 0,00 € y no puede exceder el límite máximo de 50,00 €.',
    "Obtenido: {$amount->getMessage()}"
);

$assert(
    '1.3 Conserva el importe intentado y el tope de 50,00 €',
    $amount->getAttemptedAmount() === 75.00 && $amount->getMaximumAllowed() === 50.00
);

$assert(
    '1.4 Los detalles exponen el importe sin datos personales',
    $amount->getDetails() === ['attempted_amount' => 75.00, 'maximum_allowed' => 50.00]
);

echo "\n--- 2. InvalidIbanFormatException (422 / Módulo 97) ---\n";

$iban = new InvalidIbanFormatException('ES91****1332');

$assertCommon('2.1 InvalidIbanFormatException', $iban, 422, 'INVALID_IBAN_FORMAT');

$assert(
    '2.2 El mensaje en castellano es el del catálogo de contratos',
    $iban->getMessage() === 'El código de cuenta bancaria (IBAN) introducido no es válido conforme al algoritmo oficial Módulo 97.',
    "Obtenido: {$iban->getMessage()}"
);

$assert(
    '2.3 Sólo admite un IBAN enmascarado para el diagnóstico (Art. V.4)',
    $iban->getMaskedIban() === 'ES91****1332'
);

$assert(
    '2.4 El IBAN enmascarado nunca aparece en el mensaje de la excepción',
    !str_contains($iban->getMessage(), 'ES91')
);

echo "\n--- 3. InvalidBizumPhoneException (422 / RF-REF-07) ---\n";

$bizum = new InvalidBizumPhoneException();

$assertCommon('3.1 InvalidBizumPhoneException', $bizum, 422, 'INVALID_BIZUM_PHONE');

$assert(
    '3.2 El mensaje en castellano es el del catálogo de contratos',
    $bizum->getMessage() === 'El número de teléfono para Bizum debe contener exactamente 9 dígitos numéricos.',
    "Obtenido: {$bizum->getMessage()}"
);

echo "\n--- 4. InvalidPickupPinException (422 / RF-REF-06) ---\n";

$pin = new InvalidPickupPinException();

$assertCommon('4.1 InvalidPickupPinException', $pin, 422, 'INVALID_PICKUP_PIN');

$assert(
    '4.2 El mensaje en castellano es el del catálogo de contratos',
    $pin->getMessage() === 'El PIN de recogida introducido no coincide con el expediente de reintegro.',
    "Obtenido: {$pin->getMessage()}"
);

$assert(
    '4.3 El mensaje NO filtra ningún PIN ni cifra suelta (Art. V.4)',
    !str_contains($pin->getMessage(), '4821')
    && !preg_match('/\d{4}/', $pin->getMessage())
);

echo "\n--- 5. ReceptionDeliveryNotAllowedException (422 / RF-REF-05) ---\n";

$reception = new ReceptionDeliveryNotAllowedException(25.00, CompensationMethod::BIZUM);

$assertCommon('5.1 ReceptionDeliveryNotAllowedException', $reception, 422, 'RECEPTION_DELIVERY_NOT_ALLOWED');

$assert(
    '5.2 El mensaje en castellano es el del catálogo de contratos',
    $reception->getMessage() === 'No se permite el depósito en conserjería para importes superiores a 10,00 € o con método de compensación digital.',
    "Obtenido: {$reception->getMessage()}"
);

$assert(
    '5.3 Conserva importe, método de compensación y límite presencial',
    $reception->getClaimedAmount() === 25.00
    && $reception->getCompensationMethod() === CompensationMethod::BIZUM
    && $reception->getReceptionLimit() === 10.00
);

$assert(
    '5.4 Los detalles serializan el enum como su valor de cadena',
    $reception->getDetails() === [
        'claimed_amount' => 25.00,
        'compensation_method' => 'BIZUM',
        'reception_limit' => 10.00,
    ]
);

echo "\n--- 6. InvalidRefundStateTransitionException (409) ---\n";

$transition = new InvalidRefundStateTransitionException(
    RefundStatus::REJECTED,
    RefundStatus::PAID_DIGITAL,
    'PAY_REFUND'
);

$assertCommon('6.1 InvalidRefundStateTransitionException', $transition, 409, 'INVALID_REFUND_STATE_TRANSITION');

$assert(
    '6.2 Es la única excepción de transición y NO mapea a 422',
    $transition->getHttpStatusCode() === 409
    && $transition->getHttpStatusCode() !== 422,
    'Un conflicto de estado debe ser 409, no 422'
);

$assert(
    '6.3 El mensaje en castellano es el del catálogo de contratos',
    $transition->getMessage() === 'La acción solicitada no es válida para el estado actual del expediente.',
    "Obtenido: {$transition->getMessage()}"
);

$assert(
    '6.4 Conserva el estado origen, destino y la acción intentada',
    $transition->getFromStatus() === RefundStatus::REJECTED
    && $transition->getToStatus() === RefundStatus::PAID_DIGITAL
    && $transition->getAttemptedAction() === 'PAY_REFUND'
);

$assert(
    '6.5 Los detalles toleran estados nulos sin romper la serialización',
    (new InvalidRefundStateTransitionException())->getDetails() === [
        'from_status' => null,
        'to_status' => null,
        'attempted_action' => null,
    ]
);

echo "\n--- 7. SiteRefundDataForbiddenException (403 / Art. V.4) ---\n";

$forbidden = new SiteRefundDataForbiddenException('LOCATION_MANAGER', 'refund.iban');

$assertCommon('7.1 SiteRefundDataForbiddenException', $forbidden, 403, 'FORBIDDEN');

$assert(
    '7.2 El mensaje en castellano es el del catálogo de contratos',
    $forbidden->getMessage() === 'No dispone de permisos para gestionar o consultar datos financieros de reintegros.',
    "Obtenido: {$forbidden->getMessage()}"
);

$assert(
    '7.3 Identifica el rol que intentó el acceso y el recurso protegido',
    $forbidden->getAttemptedRole() === 'LOCATION_MANAGER'
    && $forbidden->getRequestedResource() === 'refund.iban'
);

$assert(
    '7.4 Cubre también al técnico de campo, no sólo a la sede',
    (new SiteRefundDataForbiddenException('TECHNICIAN', 'refund.bizum_phone'))->getAttemptedRole() === 'TECHNICIAN'
);

echo "\n--- 8. Coherencia del catálogo de errores normalizados ---\n";

$expectedCatalogue = [
    'InvalidRefundAmountException' => [422, 'INVALID_REFUND_AMOUNT'],
    'InvalidIbanFormatException' => [422, 'INVALID_IBAN_FORMAT'],
    'InvalidBizumPhoneException' => [422, 'INVALID_BIZUM_PHONE'],
    'InvalidPickupPinException' => [422, 'INVALID_PICKUP_PIN'],
    'ReceptionDeliveryNotAllowedException' => [422, 'RECEPTION_DELIVERY_NOT_ALLOWED'],
    'InvalidRefundStateTransitionException' => [409, 'INVALID_REFUND_STATE_TRANSITION'],
    'SiteRefundDataForbiddenException' => [403, 'FORBIDDEN'],
];

$exceptionDir = $baseDir . '/src/Core/Domain/Exception';

foreach ($expectedCatalogue as $shortName => [$status, $code]) {
    $fqcn = "VendGuard\\Core\\Domain\\Exception\\{$shortName}";
    $path = $exceptionDir . '/' . $shortName . '.php';

    $assert(
        "8.x {$shortName} existe en src/Core/Domain/Exception/",
        class_exists($fqcn) && is_file($path)
    );

    if (!class_exists($fqcn) || !is_file($path)) {
        continue;
    }

    $reflection = new ReflectionClass($fqcn);

    $assert(
        "8.x {$shortName} declara HTTP_STATUS {$status}",
        (int)$reflection->getConstant('HTTP_STATUS') === $status
    );

    $assert(
        "8.x {$shortName} declara ERROR_CODE {$code}",
        (string)$reflection->getConstant('ERROR_CODE') === $code
    );

    $source = (string)file_get_contents($path);

    $assert(
        "8.x {$shortName} declara strict_types",
        str_contains($source, 'declare(strict_types=1);')
    );

    $assert(
        "8.x {$shortName} extiende de DomainException",
        $reflection->isSubclassOf(DomainException::class)
    );
}

echo "\n--- 9. Blindaje constitucional del log de auditoría ---\n";

// Las guardas de anti-fuga se ejecutan en la sección 0, antes de instanciar
// ninguna excepción, para que un PIN filtrado se reporte como fallo y no como
// crashe por argumentos.
$assert(
    '9.1 Las siete excepciones del módulo están cubiertas por las guardas estáticas',
    count($refundExceptions) >= 7
);

$assert(
    '9.2 Ninguna excepción de reintegro expone un método de escritura en base de datos',
    empty(array_filter($refundExceptions, static function (string $path): bool {
        $source = strtolower((string)file_get_contents($path));

        return str_contains($source, 'pdo') || str_contains($source, 'connectionfactory');
    }))
);

echo "\n--- 10. Límites antifraude y anonimización desde las excepciones ---\n";

$assert(
    '10.1 El tope de importe de la excepción coincide con el del dominio',
    (new InvalidRefundAmountException(51.00))->getMaximumAllowed()
    === \VendGuard\Core\Domain\Model\RefundRequest::MAX_CLAIMED_AMOUNT
);

$assert(
    '10.2 El límite de conserjería de la excepción coincide con el del dominio',
    (new ReceptionDeliveryNotAllowedException(11.00, CompensationMethod::EN_MANO_SEDE))->getReceptionLimit()
    === \VendGuard\Core\Domain\Model\RefundRequest::RECEPTION_DELIVERY_LIMIT
);

$assert(
    '10.3 El mensaje de catálogo del tope sigue citando los 50,00 € del dominio',
    str_contains(
        InvalidRefundAmountException::DEFAULT_MESSAGE,
        number_format(\VendGuard\Core\Domain\Model\RefundRequest::MAX_CLAIMED_AMOUNT, 2, ',', '.') . ' €'
    )
);

$assert(
    '10.4 El mensaje de catálogo de la custodia sigue citando los 10,00 € del dominio',
    str_contains(
        ReceptionDeliveryNotAllowedException::DEFAULT_MESSAGE,
        number_format(\VendGuard\Core\Domain\Model\RefundRequest::RECEPTION_DELIVERY_LIMIT, 2, ',', '.') . ' €'
    )
);

// Dualismo Lingüístico: los mensajes de cara al usuario son castellanos.
// Se comprueba contra marcadores del castellano y contra la ausencia de
//(authorización, invalid, not allowed) que delatarían un mensaje en inglés.
$spanishMarkers = ['El ', 'La ', 'No ', 'debe', 'no puede', 'introducido', 'expediente', 'importe', 'permisos'];
$englishMarkers = ['Invalid ', 'not allowed', 'must ', 'The requested', 'Forbidden', 'Unauthorized'];

$messages = [
    $amount->getMessage(),
    $iban->getMessage(),
    $bizum->getMessage(),
    $pin->getMessage(),
    $reception->getMessage(),
    $transition->getMessage(),
    $forbidden->getMessage(),
];

$nonSpanish = array_values(array_filter(
    $messages,
    static function (string $message) use ($spanishMarkers, $englishMarkers): bool {
        $hasSpanish = false;
        foreach ($spanishMarkers as $marker) {
            if (str_contains($message, $marker)) {
                $hasSpanish = true;
                break;
            }
        }

        foreach ($englishMarkers as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }

        return !$hasSpanish;
    }
));

$assert(
    '10.5 Todos los mensajes de la familia están redactados en castellano',
    empty($nonSpanish),
    'Mensajes no detectados como castellanos: ' . implode(' | ', $nonSpanish)
);

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-03 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);