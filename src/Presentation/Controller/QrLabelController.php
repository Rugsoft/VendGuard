<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use InvalidArgumentException;
use Throwable;
use VendGuard\Core\Domain\Exception\MachineNotFoundException;
use VendGuard\Core\Service\QrLabelService;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * QrLabelController
 * 
 * Controlador HTTP para las operaciones del Coordinador relativas a la emisión,
 * personalización, previsualización y exportación de etiquetas QR (RF-01, RF-02).
 * 
 * Endpoints gestionados:
 * - GET /api/coordinator/machines/{id}/qr-label (Previsualización JSON o descarga directa SVG)
 * - GET /api/coordinator/locations/{id}/qr-batch (Lote completo de etiquetas para hoja A4)
 * 
 * Requiere autenticación de Coordinador (InternalAuthMiddleware).
 * Cumple con Dogma Vanilla y los Artículos I, II, IV y V de la Constitución de VendGuard.
 */
class QrLabelController
{
    private QrLabelService $qrLabelService;

    public function __construct(?QrLabelService $qrLabelService = null)
    {
        $this->qrLabelService = $qrLabelService ?? new QrLabelService();
    }

    /**
     * GET /api/coordinator/machines/{id}/qr-label
     * 
     * Devuelve los metadatos y el contenido vectorial SVG de la etiqueta adhesiva de una máquina.
     * Soporta personalización efímera o persistente de teléfono (EARS 1.2, 1.3) y descarga directa (.svg).
     */
    public function getMachineLabel(Request $request): Response
    {
        $idParam = $request->getRouteParam('id');
        if ($idParam === null || !is_numeric($idParam) || (int)$idParam <= 0) {
            return Response::error(
                'INVALID_MACHINE_ID',
                'El identificador de máquina (id) debe ser un número entero positivo.',
                400
            );
        }

        $machineId = (int)$idParam;
        $customPhone = $request->getQuery('phone');
        $cleanPhone = $customPhone !== null && trim((string)$customPhone) !== '' ? trim((string)$customPhone) : null;

        $updateLocationRaw = $request->getQuery('update_location_phone', false);
        $updateLocation = filter_var($updateLocationRaw, FILTER_VALIDATE_BOOLEAN);

        $format = strtolower(trim((string)$request->getQuery('format', 'json')));

        try {
            $data = $this->qrLabelService->getMachineLabel($machineId, $cleanPhone, $updateLocation);

            // Si se solicita descarga directa como archivo vectorial SVG (EARS 2.3)
            if ($format === 'svg') {
                $filename = 'etiqueta-' . $data['machine']['code'] . '.svg';
                return new Response(
                    $data['svg_content'],
                    200,
                    [
                        'Content-Type' => 'image/svg+xml; charset=utf-8',
                        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    ]
                );
            }

            // Respuesta JSON estándar para modal de previsualización (EARS 1.4)
            return Response::json($data, 200);
        } catch (MachineNotFoundException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                404
            );
        } catch (InvalidArgumentException $e) {
            return Response::error(
                'INVALID_PARAMETER',
                $e->getMessage(),
                400
            );
        } catch (Throwable $t) {
            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'Ocurrió un error inesperado al generar la etiqueta con código QR.',
                500
            );
        }
    }

    /**
     * GET /api/coordinator/locations/{id}/qr-batch
     * 
     * Devuelve el conjunto de etiquetas con código QR para todas las máquinas activas de una sede.
     * Diseñado para la impresión en lote en cuadrícula para tamaño de papel A4 (RF-02, EARS 2.2).
     */
    public function getLocationBatch(Request $request): Response
    {
        $idParam = $request->getRouteParam('id');
        if ($idParam === null || !is_numeric($idParam) || (int)$idParam <= 0) {
            return Response::error(
                'INVALID_LOCATION_ID',
                'El identificador de sede (id) debe ser un número entero positivo.',
                400
            );
        }

        $locationId = (int)$idParam;

        try {
            $data = $this->qrLabelService->getLocationBatch($locationId);
            return Response::json($data, 200);
        } catch (MachineNotFoundException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                404
            );
        } catch (InvalidArgumentException $e) {
            return Response::error(
                'INVALID_PARAMETER',
                $e->getMessage(),
                400
            );
        } catch (Throwable $t) {
            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'Ocurrió un error inesperado al generar el lote de etiquetas de la sede.',
                500
            );
        }
    }
}
