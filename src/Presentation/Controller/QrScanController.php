<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use Throwable;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Service\QrReportService;
use VendGuard\Core\Service\QrScanService;
use VendGuard\Infrastructure\Storage\LocalFileUploader;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * QrScanController
 * 
 * Controlador HTTP público para el flujo de escaneo QR móvil y reporte rápido de averías.
 * Procesa accesos contextuales por enlace QR sin requerir inicio de sesión previo (RF-03).
 * 
 * Endpoints gestionados:
 * - GET  /api/qr/scan/{code}  (Resolución contextual de estado y blindaje de privacidad Art. V.4)
 * - POST /api/qr/report       (Envío de reporte con gestión de concurrencia atómica EARS 4.5)
 * 
 * Cumple con Dogma Vanilla y los Artículos II, IV y V de la Constitución de VendGuard.
 */
class QrScanController
{
    private QrScanService $qrScanService;
    private QrReportService $qrReportService;
    private LocalFileUploader $fileUploader;

    public function __construct(
        ?QrScanService $qrScanService = null,
        ?QrReportService $qrReportService = null,
        ?LocalFileUploader $fileUploader = null
    ) {
        $this->qrScanService = $qrScanService ?? new QrScanService();
        $this->qrReportService = $qrReportService ?? new QrReportService();
        $this->fileUploader = $fileUploader ?? new LocalFileUploader();
    }

    /**
     * GET /api/qr/scan/{code}
     * 
     * Resuelve contextualmente el estado de una máquina tras el escaneo de su código QR.
     * Retorna CAN_REPORT, ACTIVE_INCIDENT (con privacidad blindada) o UNDER_WARRANTY.
     * Si la máquina está dada de baja o no existe, retorna 404 Not Found (EARS 5.2).
     */
    public function scan(Request $request): Response
    {
        $codeParam = $request->getRouteParam('code') ?? $request->getQuery('code') ?? $request->getQuery('qr');

        if ($codeParam === null || trim((string)$codeParam) === '') {
            return Response::error(
                'MISSING_MACHINE_CODE',
                'El código de máquina es obligatorio en la ruta.',
                400
            );
        }

        $machineCode = trim((string)$codeParam);
        $siteCode = $request->getQuery('site') ?? $request->getQuery('site_code');
        $cleanSiteCode = $siteCode !== null && trim((string)$siteCode) !== '' ? trim((string)$siteCode) : null;

        try {
            $data = $this->qrScanService->resolve($machineCode, $cleanSiteCode);
            return Response::json($data, 200);
        } catch (MachineNotFoundException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                404
            );
        } catch (InvalidArgumentException $e) {
            return Response::error(
                'INVALID_MACHINE_CODE',
                $e->getMessage(),
                400
            );
        } catch (Throwable $t) {
            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'Ocurrió un error inesperado al procesar el código QR.',
                500
            );
        }
    }

    /**
     * POST /api/qr/report
     * 
     * Registra el reporte de avería enviado por el usuario desde el formulario QR móvil.
     * Si detecta una avería activa preexistente o concurrente, la fusiona amistosamente (EARS 4.5).
     */
    public function report(Request $request): Response
    {
        // 1. Validar código de máquina obligatorio
        $machineCode = $request->getBodyParam('machine_code');
        if ($machineCode === null || trim((string)$machineCode) === '') {
            return Response::error(
                'MISSING_MACHINE_CODE',
                'El código de la máquina es obligatorio.',
                422
            );
        }

        // 2. Validar categoría de avería
        $category = $request->getBodyParam('category');
        if ($category === null || trim((string)$category) === '') {
            return Response::error(
                'MISSING_CATEGORY',
                'La categoría de avería es obligatoria.',
                422
            );
        }

        // 3. Validar descripción
        $description = trim((string)($request->getBodyParam('description') ?? ''));
        if ($description === '') {
            return Response::error(
                'MISSING_DESCRIPTION',
                'La descripción de la avería es obligatoria.',
                422
            );
        }

        if (mb_strlen($description) < 5) {
            return Response::error(
                'DESCRIPTION_TOO_SHORT',
                'La descripción de la avería debe contener al menos 5 caracteres.',
                422
            );
        }

        // 4. Parámetros opcionales del informador
        $reporterName = $request->getBodyParam('reporter_name');
        $reporterPhone = $request->getBodyParam('reporter_phone');
        $retainedMoney = $request->getBodyParam('retained_money_amount');

        // 5. Manejo de archivo adjunto de evidencia (fotografía)
        $photoPath = null;
        $photoFile = $request->getFile('photo') ?? $request->getFile('image') ?? $request->getFile('file');

        if ($photoFile !== null && isset($photoFile['error']) && $photoFile['error'] !== UPLOAD_ERR_NO_FILE && !empty($photoFile['tmp_name'])) {
            try {
                $photoPath = $this->fileUploader->upload($photoFile);
            } catch (InvalidUploadException $e) {
                return Response::error(
                    $e->getErrorCode(),
                    $e->getMessage(),
                    400
                );
            }
        } elseif ($request->getBodyParam('photo_path') !== null && trim((string)$request->getBodyParam('photo_path')) !== '') {
            $photoPath = trim((string)$request->getBodyParam('photo_path'));
        }

        $payload = [
            'machine_code' => $machineCode,
            'category' => $category,
            'description' => $description,
            'reporter_name' => $reporterName,
            'reporter_phone' => $reporterPhone,
            'retained_money_amount' => $retainedMoney,
            'photo_path' => $photoPath,
        ];

        try {
            $result = $this->qrReportService->report($payload);
            $statusCode = ($result['merged'] ?? false) ? 200 : 201;

            return Response::json($result, $statusCode);
        } catch (MachineNotFoundException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                404
            );
        } catch (InvalidArgumentException $e) {
            return Response::error(
                'INVALID_PAYLOAD',
                $e->getMessage(),
                422
            );
        } catch (Throwable $t) {
            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'Ocurrió un error inesperado al procesar el reporte de avería.',
                500
            );
        }
    }
}
