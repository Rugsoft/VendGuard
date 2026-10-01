<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Certifies that refund cases and unclaimed cash findings own a dedicated
 * entity type in the immutable audit trail (RNF-REF-01, Art. III.3).
 *
 * The point of the test is the collision argument: `refund_requests.id` and
 * `incidents.id` are separate ID spaces, so a refund event recorded under
 * 'TICKET' would resolve to an unrelated incident and silently break the
 * traceability the Constitution demands for money movements.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Core\Domain\Model\AuditEvent;

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

echo "\n--- 1. Constantes de entidad en el modelo de dominio ---\n";

$assert(
    '1.1 AuditEvent declara ENTITY_REFUND_REQUEST',
    AuditEvent::ENTITY_REFUND_REQUEST === 'REFUND_REQUEST'
);

$assert(
    '1.2 AuditEvent declara ENTITY_UNCLAIMED_CASH_FINDING',
    AuditEvent::ENTITY_UNCLAIMED_CASH_FINDING === 'UNCLAIMED_CASH_FINDING'
);

echo "\n--- 2. Whitelist del dominio acepta las entidades de reintegro ---\n";

$buildEvent = function (string $entityType): ?AuditEvent {
    try {
        return new AuditEvent(
            id: null,
            entityType: $entityType,
            entityId: 1,
            action: 'TEST_ACTION',
            userId: 1,
            userRole: 'COORDINATOR',
            userName: 'Coordinación',
            previousState: null,
            newState: ['status' => 'PENDING_INSPECTION']
        );
    } catch (InvalidArgumentException $e) {
        return null;
    }
};

$refundEvent = $buildEvent(AuditEvent::ENTITY_REFUND_REQUEST);
$assert(
    '2.1 El dominio acepta un evento REFUND_REQUEST',
    $refundEvent !== null,
    'El modelo de dominio rechazó REFUND_REQUEST'
);

$unclaimedEvent = $buildEvent(AuditEvent::ENTITY_UNCLAIMED_CASH_FINDING);
$assert(
    '2.2 El dominio acepta un evento UNCLAIMED_CASH_FINDING',
    $unclaimedEvent !== null,
    'El modelo de dominio rechazó UNCLAIMED_CASH_FINDING'
);

$assert(
    '2.3 El evento conserva el entity_type al crearse',
    $refundEvent !== null && $refundEvent->getEntityType() === 'REFUND_REQUEST'
);

$assert(
    '2.4 El dominio sigue rechazando tipos de entidad inventados',
    $buildEvent('REFUND') === null && $buildEvent('SPARE_PART') === null,
    'El dominio aceptó un entity_type no declarado'
);

$assert(
    '2.5 La whitelist no se ha ampliado de forma laxa (TICKET sigue siendo válido)',
    $buildEvent(AuditEvent::ENTITY_TICKET) !== null
);

echo "\n--- 3. AuditLogger expone los métodos de escritura del módulo 08 ---\n";

$assert(
    '3.1 AuditLogger::logRefundEvent está definido',
    method_exists(AuditLogger::class, 'logRefundEvent')
);

$assert(
    '3.2 AuditLogger::logUnclaimedCashEvent está definido',
    method_exists(AuditLogger::class, 'logUnclaimedCashEvent')
);

$auditLoggerSource = (string)file_get_contents($baseDir . '/src/Application/Service/AuditLogger.php');

$assert(
    '3.3 logRefundEvent etiqueta el evento como REFUND_REQUEST',
    str_contains($auditLoggerSource, 'ENTITY_REFUND_REQUEST')
);

$assert(
    '3.4 logUnclaimedCashEvent etiqueta el evento como UNCLAIMED_CASH_FINDING',
    str_contains($auditLoggerSource, 'ENTITY_UNCLAIMED_CASH_FINDING')
);

echo "\n--- 4. El enum de la base de datos admite los nuevos valores ---\n";

try {
    $pdo = \VendGuard\Infrastructure\Database\ConnectionFactory::getConnection();
    $stmt = $pdo->query("SHOW COLUMNS FROM `audit_log` LIKE 'entity_type'");
    $column = $stmt->fetch();
    $enumType = $column === false ? '' : (string)$column['Type'];

    $hasRefundEnum = str_contains($enumType, "'REFUND_REQUEST'");
    $hasUnclaimedEnum = str_contains($enumType, "'UNCLAIMED_CASH_FINDING'");
    $hasLegacyEnum = str_contains($enumType, "'TICKET'")
        && str_contains($enumType, "'MACHINE'")
        && str_contains($enumType, "'LOCATION'")
        && str_contains($enumType, "'USER'");

    $assert(
        '4.1 El enum de audit_log incluye REFUND_REQUEST',
        $hasRefundEnum,
        "Enum actual: {$enumType}"
    );

    $assert(
        '4.2 El enum de audit_log incluye UNCLAIMED_CASH_FINDING',
        $hasUnclaimedEnum,
        "Enum actual: {$enumType}"
    );

    $assert(
        '4.3 La ampliación no elimina los valores preexistentes (TICKET, MACHINE, LOCATION, USER)',
        $hasLegacyEnum,
        "Enum actual: {$enumType}"
    );

    $orphanCount = (int)$pdo->query("SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` IS NULL")->fetchColumn();
    $assert(
        '4.4 Ninguna fila de auditoría quedó con entity_type nulo tras la ampliación',
        $orphanCount === 0,
        "Filas huérfanas: {$orphanCount}"
    );

    $distinctTypes = $pdo->query("SELECT DISTINCT `entity_type` FROM `audit_log`")->fetchAll(\PDO::FETCH_COLUMN);
    $unknownTypes = array_diff($distinctTypes, [
        'TICKET', 'MACHINE', 'LOCATION', 'USER',
        'PREVENTIVE_ORDER', 'SANITARY_CERTIFICATE',
        'REFUND_REQUEST', 'UNCLAIMED_CASH_FINDING',
    ]);
    $assert(
        '4.5 Los datos existentes solo usan tipos declarados en el enum',
        empty($unknownTypes),
        'Tipos no declarados hallados: ' . implode(', ', $unknownTypes)
    );
} catch (Throwable $e) {
    $assert(
        '4.x Se puede inspeccionar el enum de audit_log contra MariaDB real',
        false,
        $e->getMessage()
    );
}

echo "\n--- 5. Blindaje constitucional del log de auditoría ---\n";

$repoInterface = (string)file_get_contents($baseDir . '/src/Core/Domain/Repository/AuditLogRepositoryInterface.php');
$repoPdo = (string)file_get_contents($baseDir . '/src/Infrastructure/Repository/PdoAuditLogRepository.php');

$assert(
    '5.1 La interfaz de repositorio no expone método delete (Art. III)',
    !str_contains(strtolower($repoInterface), 'function delete')
);

$assert(
    '5.2 La interfaz de repositorio no expone método update (Art. III)',
    !str_contains(strtolower($repoInterface), 'function update')
);

$assert(
    '5.3 El repositorio PDO no expone método delete (Art. III)',
    !str_contains(strtolower($repoPdo), 'function delete')
);

$assert(
    '5.4 El repositorio PDO no expone método update (Art. III)',
    !str_contains(strtolower($repoPdo), 'function update')
);

$forbiddenPatterns = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir . '/src', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $content = strtoupper((string)file_get_contents($file->getPathname()));
    if (str_contains($content, 'DELETE FROM AUDIT_LOG') || str_contains($content, 'UPDATE AUDIT_LOG')) {
        $forbiddenPatterns[] = str_replace($baseDir . '/', '', $file->getPathname());
    }
}

$assert(
    '5.5 Cero sentencias UPDATE/DELETE sobre audit_log en todo src/ (Art. III.3)',
    empty($forbiddenPatterns),
    'Mutaciones detectadas en: ' . implode(', ', $forbiddenPatterns)
);

$migrationSource = (string)file_get_contents($baseDir . '/database/migrations/009_refund_audit_entity.sql');

$assert(
    '5.6 La migración de auditoría no borra filas existentes (sólo MODIFY COLUMN)',
    !str_contains(strtoupper($migrationSource), 'DELETE')
);

$assert(
    '5.7 La migración de auditoría no actualiza filas existentes (sólo MODIFY COLUMN)',
    !str_contains(strtoupper($migrationSource), 'UPDATE ')
);

$cloudInit = (string)file_get_contents($baseDir . '/database/cloud_init.sql');
$assert(
    '5.8 cloud_init.sql declara los mismos tipos de entidad que la migración',
    str_contains($cloudInit, "'REFUND_REQUEST'") && str_contains($cloudInit, "'UNCLAIMED_CASH_FINDING'")
);

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. RNF-REF-01 y Art. III.3 CERTIFICADOS.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);