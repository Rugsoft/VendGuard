<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use PDO;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\MachineDexAuditService;
use VendGuard\Core\Domain\Enum\DexImportSource;
use VendGuard\Core\Domain\Exception\DexAuditConflictException;
use VendGuard\Core\Domain\Exception\DexAuditForbiddenException;
use VendGuard\Core\Domain\Exception\DexAuditValidationException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Service\DexAuditValidator;
use VendGuard\Core\Service\DexSelectionMatcher;
use VendGuard\Core\Service\EvaDtsParser;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoMachineDexAuditGateway;
use VendGuard\Infrastructure\Repository\PdoMachineDexAuditRepository;
use VendGuard\Infrastructure\Repository\PdoNotificationOutboxRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorDexAuditController
 *
 * Superficie REST de Coordinación para el libro EVA-DTS `.dex` (Módulo 39,
 * RF-07; C6, QA-2, QA-3 y QA-5; Art. III.2, V.1 y V.4).
 *
 * | Método | Ruta                                                     |
 * |--------|----------------------------------------------------------|
 * | GET    | `/api/coordinator/dex-audits`                            |
 * | POST   | `/api/coordinator/machines/{machineId}/dex-audits`       |
 * | GET    | `/api/coordinator/dex-audits/export-csv`                 |
 * | GET    | `/api/coordinator/dex-audits/{id}`                       |
 * | GET    | `/api/coordinator/dex-audits/{id}/raw`                   |
 * | POST   | `/api/coordinator/dex-audits/{id}/void`                  |
 *
 * `raw` es la única salida con el `.dex` original: `text/plain`, `nosniff` y
 * nombre `DEX-AAAA-NNNN.dex`. El listado y los KPIs nunca leen `raw_payload`
 * (`RNF-01`). El controlador traduce las excepciones de dominio a la envolvente
 * canónica y no reinterpreta reglas.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de los puertos, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class CoordinatorDexAuditController
{
    private ?MachineDexAuditService $dexService;

    private ?AuditLogger $auditLogger;

    private ?PDO $pdo;

    public function __construct(
        ?MachineDexAuditService $dexService = null,
        ?AuditLogger $auditLogger = null,
        ?PDO $pdo = null
    ) {
        $this->dexService = $dexService;
        $this->auditLogger = $auditLogger;
        $this->pdo = $pdo;
    }

    /** GET /api/coordinator/dex-audits */
    public function index(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $filters = $this->filters($request);
            $limit = $this->boundedQuery($request, 'limit', 1, 500) ?? 100;
            $offset = $this->nonNegativeQuery($request, 'offset') ?? 0;

            return Response::json($this->service()->listForCoordinator($filters, $limit, $offset), 200);
        });
    }

    /** POST /api/coordinator/machines/{machineId}/dex-audits */
    public function import(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $machineId = $this->positiveRouteId($request, 'machineId');
            $actor = $this->actor($request);
            $payload = $this->importPayload($request, $machineId, $actor, 'COORDINATOR_CONSOLE');
            $result = $this->service()->importDex($payload);
            $presented = $this->service()->presentImportResult($result, 'COORDINATOR');

            return Response::json($presented, 201, 'Volcado .dex importado y auditado.');
        });
    }

    /** GET /api/coordinator/dex-audits/export-csv */
    public function exportCsv(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $csv = $this->service()->exportCsv($this->filters($request));

            return new Response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="dex-audits.csv"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ]);
        });
    }

    /** GET /api/coordinator/dex-audits/{id} */
    public function show(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $auditId = $this->positiveRouteId($request, 'id');
            $detail = $this->service()->findForRole($auditId, 'COORDINATOR');
            if ($detail === null) {
                return Response::error('DEX_AUDIT_NOT_FOUND', 'El volcado .dex no existe.', 404);
            }

            return Response::json($detail, 200);
        });
    }

    /** GET /api/coordinator/dex-audits/{id}/raw */
    public function raw(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $auditId = $this->positiveRouteId($request, 'id');
            $raw = $this->service()->findRawPayload($auditId);
            if ($raw === null) {
                return Response::error('DEX_AUDIT_NOT_FOUND', 'El volcado .dex no existe.', 404);
            }

            $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$raw['audit_code']) ?: 'DEX-AUDIT';

            return new Response((string)$raw['raw_payload'], 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => sprintf('attachment; filename="%s.dex"', $code),
                'Cache-Control' => 'no-store, private',
            ]);
        });
    }

    /** POST /api/coordinator/dex-audits/{id}/void */
    public function void(Request $request): Response
    {
        return $this->execute(function () use ($request): Response {
            $auditId = $this->positiveRouteId($request, 'id');
            $actor = $this->actor($request);
            $reason = (string)($request->getBodyParam('void_reason') ?? '');
            $previous = $this->service()->findForRole($auditId, 'COORDINATOR');
            if ($previous === null) {
                return Response::error('DEX_AUDIT_NOT_FOUND', 'El volcado .dex no existe.', 404);
            }

            $voided = $this->service()->voidAudit(
                $auditId,
                $reason,
                (int)$actor['id'],
                (string)$actor['name'],
                (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.uP')
            );
            $voided['can_download_raw'] = true;

            $this->audit()->logMachineEvent(
                (int)$voided['machine_id'],
                'DEX_AUDIT_VOIDED',
                $actor,
                ['status' => $previous['status'] ?? null],
                ['status' => $voided['status'] ?? 'VOIDED', 'void_reason' => $reason],
                ['dex_audit_id' => $auditId, 'audit_code' => $voided['audit_code'] ?? null]
            );

            return Response::json($voided, 200, 'Volcado .dex anulado lógicamente.');
        });
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    private function importPayload(Request $request, int $machineId, array $actor, string $source): array
    {
        $file = $request->getFile('file') ?? $request->getFile('dex_file');
        $rawPayload = '';
        $filename = trim((string)($request->getBodyParam('original_filename') ?? ''));

        if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpName = (string)($file['tmp_name'] ?? '');
            $read = $tmpName !== '' ? file_get_contents($tmpName) : false;
            if ($read === false) {
                throw new DexAuditValidationException('No se pudo leer el fichero .dex adjunto.', 'DEX_INVALID_TEXT_PAYLOAD');
            }
            $rawPayload = $read;
            $filename = $filename !== '' ? $filename : (string)($file['name'] ?? '');
        } else {
            $rawPayload = (string)($request->getBodyParam('raw_payload') ?? '');
        }

        $payload = [
            'machine_id' => $machineId,
            'actor_id' => (int)$actor['id'],
            'import_source' => $source,
            'raw_payload' => $rawPayload,
            'original_filename' => $filename !== '' ? $filename : 'volcado.dex',
            'captured_at' => trim((string)($request->getBodyParam('captured_at') ?? '')) ?: (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP'),
        ];

        foreach (['client_mutation_id', 'expected_machine_code', 'restock_visit_id', 'cash_collection_id', 'incident_id'] as $field) {
            $value = $request->getBodyParam($field);
            if ($value !== null && $value !== '') {
                $payload[$field] = $value;
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $filters = [];
        foreach (['date_from', 'date_to'] as $field) {
            $value = trim((string)($request->getQuery($field) ?? ''));
            if ($value === '') {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException(sprintf('El filtro %s debe tener formato YYYY-MM-DD.', $field));
            }
            $filters[$field] = $value;
        }

        $status = strtoupper(trim((string)($request->getQuery('status') ?? '')));
        if ($status !== '') {
            if (!in_array($status, ['ACTIVE', 'VOIDED', 'ALL'], true)) {
                throw new InvalidArgumentException('El filtro status debe ser ACTIVE, VOIDED o ALL.');
            }
            $filters['status'] = $status;
        }

        $source = strtoupper(trim((string)($request->getQuery('import_source') ?? '')));
        if ($source !== '') {
            if (!DexImportSource::isValid($source)) {
                throw new InvalidArgumentException('El filtro import_source no es una puerta de importación válida.');
            }
            $filters['import_source'] = $source;
        }
        foreach (['zone_id', 'location_id', 'machine_id'] as $field) {
            $value = $this->positiveQuery($request, $field);
            if ($value !== null) {
                $filters[$field] = $value;
            }
        }
        $alertsOnly = strtolower(trim((string)($request->getQuery('alerts_only') ?? '')));
        if (in_array($alertsOnly, ['1', 'true', 'yes', 'on'], true)) {
            $filters['alerts_only'] = true;
        }

        return $filters;
    }

    /**
     * @return array{id: int|null, role: string, name: string}
     */
    private function actor(Request $request): array
    {
        $user = $request->getAttribute('authenticated_user');

        return [
            'id' => (int)($request->getAttribute('user_id') ?? 0) ?: null,
            'role' => (string)($request->getAttribute('user_role') ?? 'COORDINATOR'),
            'name' => $user instanceof User ? $user->getName() : 'Coordinación',
        ];
    }

    private function positiveRouteId(Request $request, string $key): int
    {
        $value = (string)($request->getRouteParam($key) ?? '');
        if (!ctype_digit($value) || (int)$value <= 0) {
            throw new InvalidArgumentException(sprintf('El identificador %s debe ser un entero positivo.', $key));
        }

        return (int)$value;
    }

    private function positiveQuery(Request $request, string $field): ?int
    {
        $value = trim((string)($request->getQuery($field) ?? ''));
        if ($value === '') {
            return null;
        }
        if (!ctype_digit($value) || (int)$value <= 0) {
            throw new InvalidArgumentException(sprintf('El parámetro %s debe ser un entero positivo.', $field));
        }

        return (int)$value;
    }

    private function boundedQuery(Request $request, string $field, int $minimum, int $maximum): ?int
    {
        $value = $this->positiveQuery($request, $field);
        if ($value !== null && ($value < $minimum || $value > $maximum)) {
            throw new InvalidArgumentException(sprintf('El parámetro %s debe estar entre %d y %d.', $field, $minimum, $maximum));
        }

        return $value;
    }

    private function nonNegativeQuery(Request $request, string $field): ?int
    {
        $value = trim((string)($request->getQuery($field) ?? ''));
        if ($value === '') {
            return null;
        }
        if (!ctype_digit($value)) {
            throw new InvalidArgumentException(sprintf('El parámetro %s debe ser un entero no negativo.', $field));
        }

        return (int)$value;
    }

    private function service(): MachineDexAuditService
    {
        if ($this->dexService === null) {
            $pdo = $this->pdo ?? ConnectionFactory::getConnection();
            $this->pdo = $pdo;
            $this->dexService = new MachineDexAuditService(
                new PdoMachineDexAuditRepository($pdo),
                new PdoMachineDexAuditGateway($pdo),
                new EvaDtsParser(),
                new DexSelectionMatcher(),
                new DexAuditValidator(),
                new PdoNotificationOutboxRepository($pdo)
            );
        }

        return $this->dexService;
    }

    private function audit(): AuditLogger
    {
        return $this->auditLogger ??= new AuditLogger();
    }

    private function execute(callable $operation): Response
    {
        try {
            return $operation();
        } catch (DexAuditValidationException | DexAuditConflictException | DexAuditForbiddenException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $exception->getDetails()
            );
        } catch (InvalidArgumentException $exception) {
            return Response::error('VALIDATION_ERROR', $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo en la consola de auditorías .dex: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo completar la operación sobre auditorías .dex.', 500);
        }
    }
}
