<?php

declare(strict_types=1);

/**
 * RestockerSatSynergyValidatorTest · T-RSS-04
 *
 * Certifica el validador puro de la sinergia reponedor ↔ SAT antes de que
 * exista el gateway: elegibilidad de Nivel 1, guarda constitucional, coherencia
 * rol/nivel, bloqueo de repuestos, rigor textual y evidencia fotográfica.
 *
 * QUÉ SE CERTIFICA, Y POR QUÉ ASÍ
 *
 *   1. **Elegibilidad y mapeo (D2, QA-AMB-01).** Un aviso solo se pre-clasifica
 *      `L1_RESTOCKER` cuando la categoría no es térmica ni eléctrica, la máquina
 *      no está en cuarentena y no hay reclamación > 10,00 € ni devolución
 *      digital. La categoría sale siempre del mapeo determinista del motivo, no
 *      del cliente; el motivo declarado se conserva como rastro si la exclusión
 *      fuerza `L2_SAT`.
 *
 *   2. **Guarda constitucional (Art. II, QA-CONST-01).** Forzar Nivel 1 sobre
 *      `TEMPERATURE_COLD` o `ELECTRICAL_OFF` se rechaza con 422, y resolver un
 *      Nivel 1 sobre máquina en cuarentena con 409. Ninguna de las dos guardas
 *      depende del cliente.
 *
 *   3. **Coherencia rol/nivel (QA-CON-01).** `L1_RESTOCKER` exige `RESTOCKER`
 *      activo y `L2_SAT` exige `TECHNICIAN` activo; la pareja cruzada y el
 *      usuario inactivo se rechazan con el código exacto del contrato.
 *
 *   4. **Sin repuestos en Nivel 1 (QA-LIM-03).** Ni el estado `PENDING_PARTS` ni
 *      un motivo de repuestos caben en `L1_RESTOCKER`; en `L2_SAT` no se
 *      interfieren.
 *
 *   5. **Rigor textual (Art. V.1).** Diagnóstico y acción exigen 20 caracteres
 *      reales tras colapsar espacios; la nota de escalado exige 20..1000 y tres
 *      bloques alfanuméricos, de modo que una cadena de guiones no pasa.
 *
 *   6. **Evidencia real (Art. V.5).** La fotografía es obligatoria, pesa como
 *      máximo 5 MB y, cuando llegan los bytes, su firma binaria manda: un GIF
 *      renombrado a `.jpg` no se acepta.
 *
 * CATEGORÍA: hermética (`tests/unit/`). Sin base de datos, sin disco y sin reloj.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\RestockerSatSynergyValidationException;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentDispatchLevel;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\Level1IssueType;
use VendGuard\Core\Service\RestockerSatSynergyValidator;

$assertions = 0;

function assertRssCondition(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param callable(): void $operation
 */
function assertRssCode(callable $operation, string $expectedCode, int $expectedStatus, string $message): void
{
    global $assertions;
    $assertions++;

    try {
        $operation();
    } catch (RestockerSatSynergyValidationException $exception) {
        if ($exception->getErrorCode() === $expectedCode
            && $exception->getHttpStatusCode() === $expectedStatus) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s (se esperaba %s/%d y llegó %s/%d: %s)',
            $message,
            $expectedCode,
            $expectedStatus,
            $exception->getErrorCode(),
            $exception->getHttpStatusCode(),
            $exception->getMessage()
        ));
    }

    throw new RuntimeException($message . ' (no se lanzó ninguna excepción)');
}

try {
    echo "--- Elegibilidad y mapeo determinista (D2, QA-AMB-01) ---\n";

    $mappings = [
        Level1IssueType::STOCKOUT_EMPTY_SLOT->value => IncidentCategory::PRODUCT_JAM,
        Level1IssueType::MINOR_SPIRAL_JAM->value => IncidentCategory::PRODUCT_JAM,
        Level1IssueType::EMPTY_CHANGE_TUBES->value => IncidentCategory::PAYMENT_SYSTEM,
        Level1IssueType::MISSING_CONSUMABLES->value => IncidentCategory::OTHER,
    ];
    foreach ($mappings as $typeValue => $expectedCategory) {
        $plan = RestockerSatSynergyValidator::classifyCreation(
            Level1IssueType::fromString($typeValue),
            IncidentCategory::OTHER
        );
        assertRssCondition(
            $plan['dispatch_level'] === IncidentDispatchLevel::L1_RESTOCKER
                && $plan['category'] === $expectedCategory
                && $plan['level1_issue_type'] === Level1IssueType::fromString($typeValue)
                && $plan['forced_to_sat'] === false,
            'El motivo ' . $typeValue . ' se clasifica en L1 con su categoría canónica'
        );
    }

    $ordinary = RestockerSatSynergyValidator::classifyCreation(
        null,
        IncidentCategory::PRODUCT_JAM
    );
    assertRssCondition(
        $ordinary['dispatch_level'] === IncidentDispatchLevel::L2_SAT
            && $ordinary['category'] === IncidentCategory::PRODUCT_JAM
            && $ordinary['level1_issue_type'] === null
            && $ordinary['forced_to_sat'] === false,
        'Un alta sin motivo rápido sigue el cauce ordinario L2_SAT'
    );

    $thermalForced = RestockerSatSynergyValidator::classifyCreation(
        Level1IssueType::MINOR_SPIRAL_JAM,
        IncidentCategory::TEMPERATURE_COLD
    );
    assertRssCondition(
        $thermalForced['dispatch_level'] === IncidentDispatchLevel::L2_SAT
            && $thermalForced['category'] === IncidentCategory::TEMPERATURE_COLD
            && $thermalForced['level1_issue_type'] === Level1IssueType::MINOR_SPIRAL_JAM
            && $thermalForced['forced_to_sat'] === true
            && $thermalForced['forced_reason'] === 'FORBIDDEN_CATEGORY',
        'Una categoría térmica fuerza L2_SAT y conserva el motivo como rastro'
    );

    $quarantineForced = RestockerSatSynergyValidator::classifyCreation(
        Level1IssueType::EMPTY_CHANGE_TUBES,
        IncidentCategory::PAYMENT_SYSTEM,
        'quarantine'
    );
    assertRssCondition(
        $quarantineForced['dispatch_level'] === IncidentDispatchLevel::L2_SAT
            && $quarantineForced['forced_reason'] === 'SANITARY_QUARANTINE'
            && $quarantineForced['category'] === IncidentCategory::PAYMENT_SYSTEM,
        'La cuarentena sanitaria fuerza L2_SAT (QA-CONST-01)'
    );

    $claimForced = RestockerSatSynergyValidator::classifyCreation(
        Level1IssueType::STOCKOUT_EMPTY_SLOT,
        IncidentCategory::PRODUCT_JAM,
        null,
        10.01
    );
    assertRssCondition(
        $claimForced['dispatch_level'] === IncidentDispatchLevel::L2_SAT
            && $claimForced['forced_reason'] === 'MONETARY_CLAIM_OVER_LIMIT',
        'Una reclamación superior a 10,00 € fuerza L2_SAT'
    );

    $claimAtLimit = RestockerSatSynergyValidator::classifyCreation(
        Level1IssueType::STOCKOUT_EMPTY_SLOT,
        IncidentCategory::PRODUCT_JAM,
        null,
        10.00
    );
    assertRssCondition(
        $claimAtLimit['dispatch_level'] === IncidentDispatchLevel::L1_RESTOCKER,
        'Una reclamación de exactamente 10,00 € sigue siendo elegible para L1'
    );

    $digitalForced = RestockerSatSynergyValidator::classifyCreation(
        Level1IssueType::MISSING_CONSUMABLES,
        IncidentCategory::OTHER,
        null,
        null,
        'transferencia_bancaria'
    );
    assertRssCondition(
        $digitalForced['dispatch_level'] === IncidentDispatchLevel::L2_SAT
            && $digitalForced['forced_reason'] === 'DIGITAL_REFUND_METHOD',
        'Una devolución digital fuerza L2_SAT aunque el importe sea bajo'
    );

    assertRssCondition(
        RestockerSatSynergyValidator::isLevel1Eligible(IncidentCategory::OTHER, null, 5.0, 'EFECTIVO')
            && !RestockerSatSynergyValidator::isLevel1Eligible(IncidentCategory::ELECTRICAL_OFF)
            && !RestockerSatSynergyValidator::isLevel1Eligible(IncidentCategory::OTHER, 'SANITARY_QUARANTINE')
            && !RestockerSatSynergyValidator::isLevel1Eligible(IncidentCategory::OTHER, null, 25.0),
        'El predicado de elegibilidad coincide con las cuatro exclusiones de D2'
    );
    echo "  [OK]   Cuatro mapeos, exclusiones y motivo conservado como rastro\n";

    echo "--- Guarda constitucional de categoría (Art. II) ---\n";

    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertExplicitLevel1Allowed(IncidentCategory::TEMPERATURE_COLD),
        RestockerSatSynergyValidationException::L1_DISPATCH_FORBIDDEN_FOR_CATEGORY,
        422,
        'Forzar L1 sobre una avería térmica se rechaza'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertExplicitLevel1Allowed(IncidentCategory::ELECTRICAL_OFF),
        RestockerSatSynergyValidationException::L1_DISPATCH_FORBIDDEN_FOR_CATEGORY,
        422,
        'Forzar L1 sobre una avería eléctrica se rechaza'
    );
    RestockerSatSynergyValidator::assertExplicitLevel1Allowed(IncidentCategory::PRODUCT_JAM);
    assertRssCondition(true, 'Un aviso de producto admite el despacho explícito a Nivel 1');
    echo "  [OK]   Térmica y eléctrica rechazadas; producto admitido\n";

    echo "--- Coherencia rol / nivel (QA-CON-01) ---\n";

    RestockerSatSynergyValidator::assertAssigneeRole(
        IncidentDispatchLevel::L1_RESTOCKER,
        'restocker',
        true
    );
    assertRssCondition(true, 'Un RESTOCKER activo custodía un ticket L1');

    RestockerSatSynergyValidator::assertAssigneeRole(
        IncidentDispatchLevel::L2_SAT,
        'TECHNICIAN',
        true
    );
    assertRssCondition(true, 'Un TECHNICIAN activo custodía un ticket L2');

    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertAssigneeRole(
            IncidentDispatchLevel::L1_RESTOCKER,
            'TECHNICIAN'
        ),
        RestockerSatSynergyValidationException::INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL,
        422,
        'Un técnico no puede custodiar un ticket L1'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertAssigneeRole(
            IncidentDispatchLevel::L2_SAT,
            'RESTOCKER'
        ),
        RestockerSatSynergyValidationException::INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL,
        422,
        'Un reponedor no puede custodiar un ticket L2'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertAssigneeRole(
            IncidentDispatchLevel::L1_RESTOCKER,
            'RESTOCKER',
            false
        ),
        RestockerSatSynergyValidationException::INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL,
        422,
        'Un usuario inactivo no puede recibir el despacho'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertAssigneeRole(
            IncidentDispatchLevel::L2_SAT,
            'COORDINATOR'
        ),
        RestockerSatSynergyValidationException::INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL,
        422,
        'Un rol ajeno al nivel se rechaza'
    );
    echo "  [OK]   Cuatro parejas rechazadas y dos controles positivos\n";

    echo "--- Guarda sanitaria y repuestos (QA-CONST-01, QA-LIM-03) ---\n";

    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertLevel1ResolutionAllowed('QUARANTINE', 7),
        RestockerSatSynergyValidationException::SANITARY_L1_RESOLUTION_BLOCKED,
        409,
        'Resolver un L1 sobre máquina en cuarentena se bloquea'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertLevel1ResolutionAllowed(' sanitary_quarantine ', 7),
        RestockerSatSynergyValidationException::SANITARY_L1_RESOLUTION_BLOCKED,
        409,
        'El alias funcional SANITARY_QUARANTINE también bloquea'
    );
    RestockerSatSynergyValidator::assertLevel1ResolutionAllowed('ACTIVE', 7);
    RestockerSatSynergyValidator::assertLevel1ResolutionAllowed(null, 7);
    assertRssCondition(true, 'Una máquina activa o sin estatus sanitario permite resolver');

    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertNotPendingParts(
            IncidentDispatchLevel::L1_RESTOCKER,
            IncidentStatus::PENDING_PARTS->value
        ),
        RestockerSatSynergyValidationException::L1_PENDING_PARTS_FORBIDDEN,
        409,
        'Un L1 no puede pasar a PENDING_PARTS'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertNotPendingParts(
            IncidentDispatchLevel::L1_RESTOCKER,
            null,
            'Falta el motorreductor del carril 24.'
        ),
        RestockerSatSynergyValidationException::L1_PENDING_PARTS_FORBIDDEN,
        409,
        'Un L1 no puede declarar motivo de repuestos'
    );
    RestockerSatSynergyValidator::assertNotPendingParts(
        IncidentDispatchLevel::L2_SAT,
        IncidentStatus::PENDING_PARTS->value,
        'Falta el motorreductor del carril 24.'
    );
    assertRssCondition(true, 'El L2_SAT conserva su cauce de repuestos sin interferencias');
    echo "  [OK]   Cuarentena y repuestos bloqueados solo donde manda el contrato\n";

    echo "--- Rigor textual de la resolución (Art. V.1) ---\n";

    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertResolutionTexts(str_repeat('a', 19), str_repeat('b', 20)),
        RestockerSatSynergyValidationException::RESOLUTION_TEXT_TOO_SHORT,
        422,
        'Un diagnóstico de 19 caracteres se rechaza'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertResolutionTexts(str_repeat('a', 20), '  ' . str_repeat('b', 19) . '  '),
        RestockerSatSynergyValidationException::RESOLUTION_TEXT_TOO_SHORT,
        422,
        'La acción se mide sobre caracteres reales tras colapsar espacios'
    );

    $resolutionTexts = RestockerSatSynergyValidator::assertResolutionTexts(
        '  ' . str_repeat('a', 10) . '     ' . str_repeat('b', 9) . '  ',
        str_repeat('c', 10) . ' ' . str_repeat('d', 10)
    );
    assertRssCondition(
        $resolutionTexts['diagnosis'] === str_repeat('a', 10) . ' ' . str_repeat('b', 9)
            && $resolutionTexts['action'] === str_repeat('c', 10) . ' ' . str_repeat('d', 10)
            && mb_strlen($resolutionTexts['diagnosis']) === 20,
        'Dos textos de exactamente 20 caracteres reales se normalizan y aceptan'
    );
    echo "  [OK]   19 rechazados, 20 exactos aceptados y espacios colapsados\n";

    echo "--- Nota interna de escalado (Art. V.1) ---\n";

    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertEscalationInternalNote(str_repeat('a', 19)),
        RestockerSatSynergyValidationException::ESCALATION_INTERNAL_NOTE_REQUIRED,
        422,
        'Una nota de 19 caracteres se rechaza'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertEscalationInternalNote('Motor quemado'),
        RestockerSatSynergyValidationException::ESCALATION_INTERNAL_NOTE_REQUIRED,
        422,
        'Una nota con solo dos bloques alfanuméricos se rechaza'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertEscalationInternalNote(str_repeat('-', 40)),
        RestockerSatSynergyValidationException::ESCALATION_INTERNAL_NOTE_REQUIRED,
        422,
        'Una cadena de guiones no aporta bloques alfanuméricos'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertEscalationInternalNote(str_repeat('ab ', 333) . 'cd'),
        RestockerSatSynergyValidationException::ESCALATION_INTERNAL_NOTE_REQUIRED,
        422,
        'Una nota de 1001 caracteres se rechaza'
    );

    $note = RestockerSatSynergyValidator::assertEscalationInternalNote(
        '  El   motorreductor de la espiral 24 huele a bobina quemada.  '
    );
    assertRssCondition(
        $note === 'El motorreductor de la espiral 24 huele a bobina quemada.'
            && RestockerSatSynergyValidator::countAlphanumericBlocks($note) === 10,
        'La nota válida se normaliza y declara sus bloques alfanuméricos'
    );

    $maxNote = RestockerSatSynergyValidator::assertEscalationInternalNote(str_repeat('ab ', 333) . 'c');
    assertRssCondition(
        mb_strlen($maxNote) === 1000,
        'Una nota de exactamente 1000 caracteres se acepta'
    );
    echo "  [OK]   Mínimo, máximo, espacios, guiones y bloques certificados\n";

    echo "--- Evidencia fotográfica obligatoria (Art. V.5) ---\n";

    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertEscalationPhoto(null),
        RestockerSatSynergyValidationException::ESCALATION_PHOTO_REQUIRED,
        422,
        'El escalado sin fotografía se rechaza'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertEscalationPhoto('/uploads/evidencia.gif', 1024),
        RestockerSatSynergyValidationException::INVALID_EVIDENCE_IMAGE,
        422,
        'Una extensión no admitida se rechaza'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertEscalationPhoto('/uploads/evidencia.jpg', 0),
        RestockerSatSynergyValidationException::INVALID_EVIDENCE_IMAGE,
        422,
        'Un archivo vacío se rechaza'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertEscalationPhoto(
            '/uploads/evidencia.jpg',
            RestockerSatSynergyValidator::MAX_PHOTO_SIZE_BYTES + 1
        ),
        RestockerSatSynergyValidationException::INVALID_EVIDENCE_IMAGE,
        422,
        'Una fotografía por encima de 5 MB se rechaza'
    );

    RestockerSatSynergyValidator::assertEscalationPhoto(
        '/uploads/evidencia.jpg',
        RestockerSatSynergyValidator::MAX_PHOTO_SIZE_BYTES
    );
    assertRssCondition(true, 'Una fotografía de exactamente 5 MB se acepta');

    $pngBytes = "\x89PNG\r\n\x1a\n" . str_repeat('x', 16);
    RestockerSatSynergyValidator::assertEscalationPhoto('/uploads/evidencia.png', null, 'image/png', $pngBytes);
    assertRssCondition(
        RestockerSatSynergyValidator::detectImageMime($pngBytes) === 'image/png',
        'La firma PNG se detecta por sus bytes reales'
    );

    $jpegBytes = "\xFF\xD8\xFF\xE0" . str_repeat('x', 16);
    RestockerSatSynergyValidator::assertEscalationPhoto('/uploads/evidencia.jpg', null, null, $jpegBytes);
    assertRssCondition(
        RestockerSatSynergyValidator::detectImageMime($jpegBytes) === 'image/jpeg',
        'La firma JPEG se detecta por sus bytes reales'
    );

    $webpBytes = 'RIFF' . pack('V', 20) . 'WEBP' . str_repeat('x', 12);
    RestockerSatSynergyValidator::assertEscalationPhoto('/uploads/evidencia.webp', null, 'image/webp', $webpBytes);
    assertRssCondition(
        RestockerSatSynergyValidator::detectImageMime($webpBytes) === 'image/webp',
        'La firma WebP se detecta por sus bytes reales'
    );

    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertEscalationPhoto(
            '/uploads/evidencia.jpg',
            null,
            null,
            'GIF89a' . str_repeat('x', 20)
        ),
        RestockerSatSynergyValidationException::INVALID_EVIDENCE_IMAGE,
        422,
        'Un GIF renombrado a .jpg no pasa la firma real'
    );
    assertRssCode(
        static fn () => RestockerSatSynergyValidator::assertEscalationPhoto(
            '/uploads/evidencia.png',
            null,
            'image/jpeg',
            $pngBytes
        ),
        RestockerSatSynergyValidationException::INVALID_EVIDENCE_IMAGE,
        422,
        'El MIME declarado que no coincide con la firma se rechaza'
    );
    echo "  [OK]   Obligatoria, 5 MB, tres firmas reales y suplantación rechazada\n";

    echo "--- Contrato de la excepción ---\n";

    try {
        RestockerSatSynergyValidator::assertResolutionTexts('corto', str_repeat('b', 20));
    } catch (RestockerSatSynergyValidationException $exception) {
        assertRssCondition(
            $exception->getField() === 'resolution_diagnosis'
                && $exception->getContext()['length'] === 5
                && $exception->getContext()['minimum'] === 20,
            'La excepción transporta campo y contexto del fallo'
        );
    }
    echo "  [OK]   error_code, estado HTTP, campo y contexto disponibles\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
}

echo "======================================================================\n";
echo " VENDGUARD · T-RSS-04 · Validador de la sinergia reponedor-SAT\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE\n";
echo "======================================================================\n";
exit(0);
