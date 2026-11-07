<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use Throwable;
use VendGuard\Application\Service\SanitarySpoilageService;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Exception\SanitarySpoilageValidationException;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Storage\LocalFileUploader;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * SanitarySpoilageController — superficie REST del Acta de Merma APPCC (Módulo 29).
 *
 * Expone los seis casos de uso del contrato técnico:
 *   - Levantamiento de cuarentena con acta y evidencia fotográfica.
 *   - Consulta del Acta Oficial A4 y del historial por máquina, con proyección
 *     pública para el Técnico y completa para Coordinación.
 *   - Panel, ajuste económico y exportación CSV exclusivos de Coordinación.
 *
 * La autorización se aplica primero en el middleware de la ruta y se vuelve a
 * comprobar aquí (defensa en profundidad): un controlador invocado sin el
 * contexto de sesión no debe convertirse en una puerta trasera. La segregación
 * financiera del Art. V.4 vive en el servicio y en la entidad, nunca en esta
 * capa, que se limita a transportar la serialización recibida.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class SanitarySpoilageController
{
    /** Roles internos que pueden levantar cuarentenas y consultar actas. */
    private const INTERNAL_ROLES = ['TECHNICIAN', 'COORDINATOR'];

    /** Roles que pueden acceder al panel financiero de Coordinación. */
    private const COORDINATOR_ROLES = ['COORDINATOR'];

    private ?SanitarySpoilageService $service;

    private ?LocalFileUploader $uploader;

    private ?MachineRepositoryInterface $machineRepository;

    public function __construct(
        ?SanitarySpoilageService $service = null,
        ?LocalFileUploader $uploader = null,
        ?MachineRepositoryInterface $machineRepository = null
    ) {
        $this->service = $service;
        $this->uploader = $uploader;
        $this->machineRepository = $machineRepository;
    }

    /**
     * `POST /api/sanitary-quarantines/machines/{machineId}/lift`
     *
     * Acepta `multipart/form-data` con `photo` o JSON con `evidence_photo_url`.
     * La foto se valida con `LocalFileUploader` (≤ 5 MB y MIME real) antes de
     * tocar la base de datos. La vía JSON solo admite una evidencia ya
     * almacenada en `/uploads/` que supere la misma inspección de archivo. Si el
     * servicio rechaza el acta después de haber guardado la imagen, el archivo
     * se elimina para no dejar evidencias huérfanas en `public/uploads/`.
     */
    public function lift(Request $request): Response
    {
        $actor = $this->authorize($request, self::INTERNAL_ROLES);
        if ($actor instanceof Response) {
            return $actor;
        }

        $machineId = $this->positiveRouteId($request, 'machineId', 'INVALID_MACHINE_IDENTIFIER');
        if ($machineId instanceof Response) {
            return $machineId;
        }

        $photoFile = $request->getFile('photo');
        $uploadedPath = null;

        if (is_array($photoFile) && (int)($photoFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploadedPath = $this->uploader()->upload($photoFile);
            } catch (InvalidUploadException $exception) {
                return $this->uploadFailure($exception);
            }
        }

        $body = $request->getParsedBody();
        $providedUrl = $body['evidence_photo_url'] ?? '';
        $evidencePhotoUrl = $uploadedPath ?? '';

        if ($uploadedPath === null && is_string($providedUrl) && trim($providedUrl) !== '') {
            try {
                $evidencePhotoUrl = $this->uploader()->validateStoredImage($providedUrl);
            } catch (InvalidUploadException $exception) {
                return $this->uploadFailure($exception);
            }
        }

        try {
            $result = $this->service()->liftQuarantineWithSpoilageRecord(
                $machineId,
                $body,
                $evidencePhotoUrl,
                $actor
            );
        } catch (SanitarySpoilageValidationException $exception) {
            $this->discardUploadedEvidence($uploadedPath);
            return $this->domainFailure($exception);
        } catch (Throwable $exception) {
            $this->discardUploadedEvidence($uploadedPath);
            throw $exception;
        }

        return Response::json(
            $result,
            201,
            'Acta de Merma registrada y cuarentena sanitaria levantada correctamente. Máquina restituida a servicio.'
        );
    }

    /**
     * `GET /api/sanitary-spoilage-records/{id}/official-act`
     */
    public function getOfficialAct(Request $request): Response
    {
        $actor = $this->authorize($request, self::INTERNAL_ROLES);
        if ($actor instanceof Response) {
            return $actor;
        }

        $recordId = $this->positiveRouteId($request, 'id', 'INVALID_SPOILAGE_RECORD_IDENTIFIER');
        if ($recordId instanceof Response) {
            return $recordId;
        }

        try {
            $officialAct = $this->service()->getOfficialAct($recordId, $actor);
        } catch (SanitarySpoilageValidationException $exception) {
            return $this->domainFailure($exception);
        }

        return Response::json($officialAct, 200);
    }

    /**
     * `GET /api/sanitary-spoilage-records/by-machine/{machineId}`
     */
    public function getByMachine(Request $request): Response
    {
        $actor = $this->authorize($request, self::INTERNAL_ROLES);
        if ($actor instanceof Response) {
            return $actor;
        }

        $machineId = $this->positiveRouteId($request, 'machineId', 'INVALID_MACHINE_IDENTIFIER');
        if ($machineId instanceof Response) {
            return $machineId;
        }

        try {
            $history = $this->service()->listByMachine($machineId, $actor['role']);
        } catch (SanitarySpoilageValidationException $exception) {
            return $this->domainFailure($exception);
        }

        return Response::json($history, 200);
    }

    /**
     * `GET /api/sanitary-spoilage-records/by-machine-code/{code}`
     *
     * Localiza las actas de cualquier máquina por su código visible. Es la vía
     * que permite al Técnico consultar el Acta A4 sin que la máquina forme
     * parte de una avería de su ruta (QA 3.1).
     */
    public function getByMachineCode(Request $request): Response
    {
        $actor = $this->authorize($request, self::INTERNAL_ROLES);
        if ($actor instanceof Response) {
            return $actor;
        }

        $rawCode = $request->getRouteParam('code');
        $code = is_string($rawCode) ? trim($rawCode) : '';
        if ($code === '' || strlen($code) > 80) {
            return Response::error('INVALID_MACHINE_CODE', 'El código de máquina no es válido.', 400);
        }

        $machine = $this->machines()->findByCode($code, withIncident: false, allowDeleted: true);
        if ($machine === null) {
            return Response::error('MACHINE_NOT_FOUND', 'No existe una máquina con ese código.', 404);
        }

        try {
            $history = $this->service()->listByMachine($machine->getId(), $actor['role']);
        } catch (SanitarySpoilageValidationException $exception) {
            return $this->domainFailure($exception);
        }

        return Response::json($history, 200);
    }

    /**
     * `GET /api/sanitary-quarantines/active`
     *
     * Listado transversal de cuarentenas activas para Técnicos y Coordinación
     * (C2). Solo publica datos de máquina y el bloqueo C1; nunca actas ni
     * importes, de modo que el Técnico puede localizar una cuarentena que no
     * forme parte de su ruta.
     */
    public function listActiveQuarantines(Request $request): Response
    {
        $actor = $this->authorize($request, self::INTERNAL_ROLES);
        if ($actor instanceof Response) {
            return $actor;
        }

        try {
            $payload = $this->service()->listActiveQuarantines($actor);
        } catch (SanitarySpoilageValidationException $exception) {
            return $this->domainFailure($exception);
        }

        return Response::json($payload, 200);
    }

    /**
     * `GET /api/coordinator/sanitary-spoilage-records`
     */
    public function listForCoordinator(Request $request): Response
    {
        $actor = $this->authorize($request, self::COORDINATOR_ROLES);
        if ($actor instanceof Response) {
            return $actor;
        }

        $filters = $this->extractFilters($request);
        if ($filters instanceof Response) {
            return $filters;
        }

        try {
            $panel = $this->service()->listForCoordinator($filters);
        } catch (SanitarySpoilageValidationException $exception) {
            return $this->domainFailure($exception);
        }

        return Response::json($panel, 200);
    }

    /**
     * `PATCH /api/coordinator/sanitary-spoilage-records/{id}/loss-estimate`
     */
    public function adjustLossEstimate(Request $request): Response
    {
        $actor = $this->authorize($request, self::COORDINATOR_ROLES);
        if ($actor instanceof Response) {
            return $actor;
        }

        $recordId = $this->positiveRouteId($request, 'id', 'INVALID_SPOILAGE_RECORD_IDENTIFIER');
        if ($recordId instanceof Response) {
            return $recordId;
        }

        try {
            $result = $this->service()->adjustLossEstimate($recordId, $request->getParsedBody(), $actor);
        } catch (SanitarySpoilageValidationException $exception) {
            return $this->domainFailure($exception);
        }

        return Response::json($result, 200, 'Importe de la merma actualizado correctamente.');
    }

    /**
     * `GET /api/coordinator/sanitary-spoilage-records/export-csv`
     *
     * La respuesta es el CSV binario, no una envoltura JSON: el navegador lo
     * descarga directamente con BOM UTF-8 y separador `;`.
     */
    public function exportCsv(Request $request): Response
    {
        $actor = $this->authorize($request, self::COORDINATOR_ROLES);
        if ($actor instanceof Response) {
            return $actor;
        }

        $filters = $this->extractFilters($request);
        if ($filters instanceof Response) {
            return $filters;
        }

        try {
            $csv = $this->service()->exportCsvForCoordinator($filters, $actor);
        } catch (SanitarySpoilageValidationException $exception) {
            return $this->domainFailure($exception);
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="actas-merma-appcc.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Recupera el actor autenticado y aplica el rol exigido.
     *
     * @param list<string> $allowedRoles
     * @return array{id: int, role: string, name: string}|Response
     */
    private function authorize(Request $request, array $allowedRoles): array|Response
    {
        $actor = $this->extractActor($request);
        if ($actor === null) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado. Se requiere una sesión interna válida.',
                401
            );
        }

        if (!in_array($actor['role'], $allowedRoles, true)) {
            return Response::error(
                'FORBIDDEN',
                'Acceso denegado: el rol autenticado no dispone de permisos para este recurso.',
                403,
                [
                    'required_roles' => $allowedRoles,
                    'user_role' => $actor['role'],
                ]
            );
        }

        return $actor;
    }

    /**
     * @return array{id: int, role: string, name: string}|null
     */
    private function extractActor(Request $request): ?array
    {
        $user = $request->getAttribute('authenticated_user');
        if ($user instanceof User) {
            $id = $user->getId();
            $role = strtoupper(trim($user->getRole()->value));
            $name = $user->getName();
        } else {
            $rawId = $request->getAttribute('user_id');
            $rawRole = $request->getAttribute('user_role');
            if (!is_numeric($rawId) || !is_string($rawRole)) {
                return null;
            }

            $id = (int)$rawId;
            $role = strtoupper(trim($rawRole));
            $rawName = $request->getAttribute('user_name', '');
            $name = is_string($rawName) ? trim($rawName) : '';
        }

        if ($id < 1 || $role === '') {
            return null;
        }

        return [
            'id' => $id,
            'role' => $role,
            'name' => $name,
        ];
    }

    /**
     * @return int|Response
     */
    private function positiveRouteId(Request $request, string $parameter, string $errorCode): int|Response
    {
        $rawId = $request->getRouteParam($parameter);
        $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            return Response::error(
                $errorCode,
                'El identificador de ruta debe ser un número entero positivo.',
                422
            );
        }

        return (int)$id;
    }

    /**
     * Extrae los filtros opcionales del panel sin reinterpretar su contenido.
     * El servicio valida el rango de fechas y el repositorio aplica el resto.
     *
     * @return array<string, mixed>|Response
     */
    private function extractFilters(Request $request): array|Response
    {
        $filters = [];

        foreach (['machine_id', 'quarantine_origin', 'disposal_method', 'date_from', 'date_to', 'search'] as $key) {
            $rawValue = $request->getQuery($key);
            if ($rawValue === null) {
                continue;
            }

            if (!is_scalar($rawValue)) {
                return Response::error(
                    'INVALID_FILTER',
                    sprintf('El filtro «%s» debe ser un valor escalar.', $key),
                    422,
                    ['field' => $key]
                );
            }

            $value = trim((string)$rawValue);
            if ($value === '') {
                continue;
            }

            if ($key === 'machine_id') {
                $machineId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($machineId === false) {
                    return Response::error(
                        'INVALID_FILTER',
                        'El filtro machine_id debe ser un número entero positivo.',
                        422,
                        ['field' => 'machine_id']
                    );
                }

                $filters[$key] = (int)$machineId;
                continue;
            }

            $filters[$key] = $value;
        }

        return $filters;
    }

    /**
     * Traduce un rechazo de negocio conservando el código y el estado exactos
     * que el contrato REST exige.
     */
    private function domainFailure(SanitarySpoilageValidationException $exception): Response
    {
        $details = [];
        $field = $exception->getField();
        if ($field !== null) {
            $details['field'] = $field;
        }

        return Response::error(
            $exception->getErrorCode(),
            $exception->getMessage(),
            $exception->getHttpStatusCode(),
            $details !== [] ? $details : null
        );
    }

    /**
     * Un archivo inválido es un 422 de validación del acta; un fallo de disco o
     * de directorio sigue siendo un 500 del servidor.
     */
    private function uploadFailure(InvalidUploadException $exception): Response
    {
        if ($exception->getHttpStatusCode() >= 500) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                500,
                ['field' => 'evidence_photo_url']
            );
        }

        return Response::error(
            'INVALID_SPOILAGE_RECORD',
            $exception->getMessage(),
            422,
            ['field' => 'evidence_photo_url']
        );
    }

    private function discardUploadedEvidence(?string $uploadedPath): void
    {
        if ($uploadedPath !== null && $uploadedPath !== '') {
            $this->uploader()->delete($uploadedPath);
        }
    }

    private function service(): SanitarySpoilageService
    {
        return $this->service ??= new SanitarySpoilageService();
    }

    private function machines(): MachineRepositoryInterface
    {
        return $this->machineRepository ??= new PdoMachineRepository();
    }

    private function uploader(): LocalFileUploader
    {
        return $this->uploader ??= new LocalFileUploader();
    }
}
