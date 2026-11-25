<?php

declare(strict_types=1);

/**
 * RestockingBiApiTest · T-RBI-04
 *
 * Certifica la capa HTTP del Cuadro de Mando de Reposición, Roturas de Stock y
 * Mermas Comerciales (Módulo 38; `RF-01.2`, `RF-01.4`, `RF-06.3`, `RNF-03`,
 * `C5`, `QA-5`, Art. V.4).
 *
 * Contrato que cubre:
 *
 *   1. **RBAC (Art. V.4).** Sin sesión, ambos endpoints responden `401`; con rol
 *      `TECHNICIAN`, `403`. La puerta la impone `InternalAuthMiddleware`.
 *   2. **Validación fail-fast (`C5`, `QA-5`).** Par de fechas incompleto, orden
 *      invertido, fecha civil imposible e identificadores no enteros producen
 *      `400 INVALID_FILTER_PARAMS`, también en la descarga.
 *   3. **Informe (`RF-01.2`).** `200` con los siete bloques del contrato, los
 *      filtros normalizados, los catálogos estables y el bloqueo térmico cuando
 *      se filtra por reponedor.
 *   4. **CSV (`RF-06.3`).** `200`, `text/csv`, nombre `vendguard_restocking_bi_*`,
 *      BOM UTF-8, cinco secciones y aviso de confidencialidad.
 *   5. **Auditoría (`QA-1`, Art. III.3).** `EXPORT_RESTOCKING_BI_CSV` bajo
 *      `ENTITY_MACHINE_RESTOCK_VISIT` con `entity_id = 0`, el coordinador
 *      autenticado y los filtros aplicados.
 *
 * CATEGORÍA: motor real (`tests/integration/`), base `vendguard_battery` bajo el
 * contrato de la batería. El esquema no se toca: la prueba solo lee agregados y
 * añade su evento de auditoría append-only.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuthService;
use VendGuard\Application\Service\RestockingBiExportService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

$pdo = ConnectionFactory::getConnection();
(new SeedRunner($pdo))->seedAll();

$router = AppRouter::create();
$authService = new AuthService();
$users = new PdoUserRepository($pdo);
$auditRepo = new PdoAuditLogRepository($pdo);

$coordinator = $users->findByEmail('coordinacion@vendguard.internal');
$technician = $users->findByEmail('jordi.ruta@vendguard.internal');

if ($coordinator === null || $technician === null) {
    fwrite(STDERR, "\n[FAIL] No se encontraron los usuarios canónicos de la prueba.\n");
    exit(1);
}

$coordinatorToken = $authService->generateInternalToken($coordinator);
$technicianToken = $authService->generateInternalToken($technician);

$assertions = 0;

$assert = function (bool $condition, string $message, string $detail = '') use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message . ($detail !== '' ? ' · ' . $detail : ''));
    }
    echo "  [OK]   {$message}\n";
};

$dispatch = static function (string $url, array $query, ?string $token) use ($router) {
    $headers = $token === null ? [] : ['Authorization' => 'Bearer ' . $token];

    return $router->dispatch(new Request('GET', $url, $query, [], $headers));
};

$decode = static function ($response): array {
    $payload = json_decode($response->getBody(), true);

    return is_array($payload) ? $payload : [];
};

try {
    echo "--- RBAC de las rutas de reposición ---\n";

    foreach (['/api/coordinator/metrics/restocking', '/api/coordinator/metrics/restocking/export'] as $endpoint) {
        $anonymous = $dispatch($endpoint, [], null);
        $assert($anonymous->getStatusCode() === 401, "{$endpoint} responde 401 sin sesión");

        $asTechnician = $dispatch($endpoint, [], $technicianToken);
        $assert($asTechnician->getStatusCode() === 403, "{$endpoint} responde 403 con rol TECHNICIAN");
    }

    echo "--- Validación y errores del contrato ---\n";

    $invalidCases = [
        ['from solo', ['from' => '2026-11-07'], 'to'],
        ['orden invertido', ['from' => '2026-11-20', 'to' => '2026-11-07'], 'from'],
        ['fecha imposible', ['from' => '2026-02-30', 'to' => '2026-03-01'], 'from'],
        ['identificador no entero', ['from' => '2026-11-07', 'to' => '2026-11-20', 'zone_id' => 'abc'], 'zone_id'],
        ['identificador cero', ['from' => '2026-11-07', 'to' => '2026-11-20', 'restocker_id' => '0'], 'restocker_id'],
    ];

    foreach ($invalidCases as [$label, $query, $field]) {
        $response = $dispatch('/api/coordinator/metrics/restocking', $query, $coordinatorToken);
        $body = $decode($response);
        $assert(
            $response->getStatusCode() === 400
            && ($body['error']['code'] ?? null) === 'INVALID_FILTER_PARAMS'
            && str_contains((string)($body['error']['message'] ?? ''), 'field: ' . $field),
            "El filtro inválido ({$label}) responde 400 e identifica el campo {$field}"
        );
    }

    $invalidExport = $dispatch(
        '/api/coordinator/metrics/restocking/export',
        ['to' => '2026-11-20'],
        $coordinatorToken
    );
    $assert(
        $invalidExport->getStatusCode() === 400
        && ($decode($invalidExport)['error']['code'] ?? null) === 'INVALID_FILTER_PARAMS',
        'La descarga rechaza el mismo filtro inválido con 400'
    );

    echo "--- Informe JSON completo ---\n";

    $filter = ['from' => '2026-11-07', 'to' => '2026-11-20'];
    $reportResponse = $dispatch('/api/coordinator/metrics/restocking', $filter, $coordinatorToken);
    $reportBody = $decode($reportResponse);
    $report = $reportBody['data'] ?? [];

    $assert(
        $reportResponse->getStatusCode() === 200
        && ($reportBody['success'] ?? false) === true
        && str_contains((string)$reportResponse->getHeader('Content-Type'), 'application/json'),
        'El informe responde 200 con el sobre JSON del contrato'
    );
    $assert(
        array_keys($report) === [
            'filters',
            'available_filters',
            'summary',
            'spoilage_comparison',
            'charts',
            'by_machine',
            'by_product',
            'by_restocker',
        ],
        'El informe publica los siete bloques en el orden del contrato'
    );
    $assert(
        ($report['filters']['from'] ?? null) === '2026-11-07'
        && ($report['filters']['to'] ?? null) === '2026-11-20'
        && ($report['filters']['timezone'] ?? null) === 'Europe/Madrid',
        'El bloque filters refleja la ventana validada'
    );
    $assert(
        array_keys($report['available_filters'] ?? []) === ['zones', 'locations', 'restockers'],
        'El informe publica los tres catálogos de filtros'
    );
    $assert(
        array_key_exists('cash_reconciled_ok_count', $report['summary'] ?? [])
        && array_key_exists('cash_discrepancy_flagged_count', $report['summary'] ?? [])
        && array_key_exists('thermal_spoilage_eur', $report['summary'] ?? []),
        'El resumen incluye arqueos OK/descuadre y merma térmica'
    );
    $assert(
        ($report['spoilage_comparison']['thermal_haccp']['segmentable'] ?? null) === true
        && isset($report['charts']['spoilage_bars'], $report['charts']['top_critical_stockout'], $report['charts']['top_critical_expiry'])
        && is_array($report['by_machine'])
        && is_array($report['by_product'])
        && is_array($report['by_restocker']),
        'La comparativa y las series de los dos gráficos salen completas'
    );

    $restockerResponse = $dispatch(
        '/api/coordinator/metrics/restocking',
        $filter + ['restocker_id' => '1'],
        $coordinatorToken
    );
    $restockerReport = $decode($restockerResponse)['data'] ?? [];

    $assert(
        $restockerResponse->getStatusCode() === 200
        && ($restockerReport['filters']['restocker_id'] ?? null) === 1
        && ($restockerReport['spoilage_comparison']['thermal_haccp']['segmentable'] ?? null) === false
        && ($restockerReport['spoilage_comparison']['thermal_haccp']['non_segmentable_message'] ?? null)
            === 'No segmentable por reponedor (ámbito máquina/SAT)'
        && array_key_exists('thermal_spoilage_eur', $restockerReport['summary'] ?? [])
        && $restockerReport['summary']['thermal_spoilage_eur'] === null,
        'Con reponedor la térmica queda en null y se publica el aviso de no segmentación (D2)'
    );

    echo "--- Descarga CSV autenticada ---\n";

    $exportUrl = '/api/coordinator/metrics/restocking/export';
    $globalEventsBefore = count($auditRepo->findByEntity(AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT, 0));

    $exportResponse = $dispatch($exportUrl, $filter, $coordinatorToken);
    $csv = $exportResponse->getBody();

    $assert(
        $exportResponse->getStatusCode() === 200
        && str_starts_with((string)$exportResponse->getHeader('Content-Type'), 'text/csv')
        && str_contains(
            (string)$exportResponse->getHeader('Content-Disposition'),
            'filename="vendguard_restocking_bi_2026-11-07_2026-11-20.csv"'
        )
        && str_contains((string)$exportResponse->getHeader('Cache-Control'), 'no-store'),
        'La descarga responde 200 con las cabeceras y el nombre del contrato'
    );
    $assert(
        substr($csv, 0, 3) === "\xEF\xBB\xBF"
        && substr_count($csv, 'SECCIÓN ') === 5
        && str_contains($csv, RestockingBiExportService::CONFIDENTIALITY_NOTICE),
        'El CSV lleva BOM, las cinco secciones y el aviso de confidencialidad'
    );
    $assert(
        str_contains($csv, 'SECCIÓN 1: RESUMEN EJECUTIVO')
        && str_contains($csv, 'SECCIÓN 5: RENDIMIENTO OPERATIVO Y ARQUEOS POR REPONEDOR')
        && str_contains($csv, 'Arqueos_OK')
        && str_contains($csv, 'Arqueos_Descuadre'),
        'El CSV incluye resumen, arqueos y la sección de reponedores'
    );

    echo "--- Auditoría de la exportación ---\n";

    $globalEventsAfter = $auditRepo->findByEntity(AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT, 0);
    $exportEvents = array_values(array_filter(
        $globalEventsAfter,
        static fn(AuditEvent $event): bool => $event->getAction() === RestockingBiExportService::ACTION_EXPORT
    ));
    $auditEvent = end($exportEvents) ?: null;

    $assert(
        count($globalEventsAfter) === $globalEventsBefore + 1
        && $auditEvent instanceof AuditEvent,
        'La descarga deja exactamente un evento global de auditoría'
    );
    $assert(
        $auditEvent !== null
        && $auditEvent->getEntityId() === 0
        && $auditEvent->getEntityType() === AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT
        && $auditEvent->getUserId() === $coordinator->getId()
        && $auditEvent->getUserRole() === 'COORDINATOR'
        && $auditEvent->getPreviousState() === null,
        'El evento usa ENTITY_MACHINE_RESTOCK_VISIT con entity_id 0 y el coordinador (QA-1)'
    );
    $assert(
        $auditEvent !== null
        && ($auditEvent->getNewState()['from'] ?? null) === '2026-11-07'
        && ($auditEvent->getNewState()['to'] ?? null) === '2026-11-20'
        && array_key_exists('zone_id', $auditEvent->getNewState())
        && $auditEvent->getNewState()['zone_id'] === null
        && preg_match(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string)($auditEvent->getNewState()['exported_at'] ?? '')
        ) === 1,
        'El evento guarda los filtros y la marca Europe/Madrid (QA-1)'
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "\n[FAIL] " . $exception->getMessage() . "\n");
    exit(1);
}

echo "\n======================================================================\n";
echo " VENDGUARD · T-RBI-04 · API REST del cuadro de mando de reposición\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE\n";
echo "======================================================================\n";
exit(0);
