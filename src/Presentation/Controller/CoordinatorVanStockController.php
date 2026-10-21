<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\Service\VanStockService;
use VendGuard\Core\Domain\Exception\VanStockValidationException;
use VendGuard\Infrastructure\Repository\PdoVanStockRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CoordinatorVanStockController
 *
 * Inventario y ajuste del stock de las furgonetas para la Coordinación (Módulo 18, RF-03 y RF-04.2).
 *
 * Dos lecturas en un solo endpoint, porque son la misma tabla mirada con dos preguntas distintas:
 *
 *  - **Alerta** (`below_threshold=1`): solo las parejas bajo umbral, ya ordenadas por severidad
 *    desde la consulta. Es la fuente de la insignia de reposición y del descuadre.
 *  - **Inventario** (sin el parámetro): todas las parejas, opcionalmente las de un técnico.
 *
 * El ajuste es un caso de uso con reglas, no un `UPDATE` con un formulario delante: el servicio
 * comprueba cotas, existencia del técnico y existencia de la pieza antes de escribir, y el
 * repositorio deja la fila y su evento de auditoría en la misma transacción. El controlador se
 * limita a leer, delegar y traducir la excepción al sobre de error del proyecto.
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
class CoordinatorVanStockController
{
    private VanStockService $vanStockService;

    public function __construct(?VanStockService $vanStockService = null)
    {
        $this->vanStockService = $vanStockService ?? new VanStockService(new PdoVanStockRepository());
    }

    /**
     * GET /api/coordinator/van-stocks
     *
     * Contrato §4.2.
     *
     * Query Parameters:
     * - technician_id (int, opcional): limita el resultado a una furgoneta.
     * - below_threshold (1, opcional): devuelve solo las parejas bajo umbral, por severidad.
     */
    public function getInventory(Request $request): Response
    {
        $technicianId = null;
        $rawTechnicianId = $request->getQuery('technician_id');
        if ($rawTechnicianId !== null && trim((string)$rawTechnicianId) !== '') {
            $technicianId = $this->positiveIntOrNull($rawTechnicianId);
            if ($technicianId === null) {
                return $this->invalidPayload([
                    'technician_id' => 'Debe ser un número entero positivo.',
                ]);
            }
        }

        $belowThreshold = false;
        $rawBelowThreshold = $request->getQuery('below_threshold');
        if ($rawBelowThreshold !== null && trim((string)$rawBelowThreshold) !== '') {
            $belowThreshold = $this->booleanFlagOrNull($rawBelowThreshold);
            if ($belowThreshold === null) {
                return $this->invalidPayload([
                    'below_threshold' => 'Debe ser 1 para pedir la alerta o 0 para el inventario completo.',
                ]);
            }
        }

        $entries = $belowThreshold
            ? $this->vanStockService->listBelowThreshold($technicianId)
            : $this->vanStockService->listInventory($technicianId);

        return Response::json([
            'below_threshold' => $belowThreshold,
            'items' => array_map(
                static fn ($entry): array => $entry->toCoordinatorArray(),
                $entries
            ),
        ], 200);
    }

    /**
     * PUT /api/coordinator/van-stocks
     *
     * Contrato §4.3. El cuerpo declara el **valor absoluto**, no un delta: el coordinador dice
     * cuántas unidades hay, y el delta respecto al saldo previo queda en la respuesta y en el
     * evento de auditoría.
     */
    public function upsertStock(Request $request): Response
    {
        $technicianId = $this->positiveIntOrNull($request->getBodyParam('technician_id'));
        $sparePartId = $this->positiveIntOrNull($request->getBodyParam('spare_part_id'));
        $quantityAvailable = $this->intOrNull($request->getBodyParam('quantity_available'));
        $minThreshold = $this->intOrNull($request->getBodyParam('min_threshold'));

        $fieldErrors = [];
        if ($technicianId === null) {
            $fieldErrors['technician_id'] = 'Obligatorio y entero positivo.';
        }
        if ($sparePartId === null) {
            $fieldErrors['spare_part_id'] = 'Obligatorio y entero positivo.';
        }
        if ($quantityAvailable === null) {
            $fieldErrors['quantity_available'] = 'Obligatorio y entero.';
        }
        if ($minThreshold === null) {
            $fieldErrors['min_threshold'] = 'Obligatorio y entero.';
        }

        if ($fieldErrors !== []) {
            return $this->invalidPayload($fieldErrors);
        }

        $actorId = $request->getAttribute('user_id');

        try {
            $result = $this->vanStockService->setStock(
                $technicianId,
                $sparePartId,
                $quantityAvailable,
                $minThreshold,
                $actorId !== null && is_numeric($actorId) ? (int)$actorId : null
            );
        } catch (VanStockValidationException $exception) {
            return Response::error(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getHttpStatusCode(),
                $exception->getFieldErrors()
            );
        }

        return Response::json([
            'technician_id' => $result->item->technicianId,
            'spare_part_id' => $result->item->sparePartId,
            'quantity_available' => $result->item->quantityAvailable,
            'min_threshold' => $result->item->minThreshold,
            'is_below_threshold' => $result->item->isBelowThreshold(),
            'previous_quantity_available' => $result->previousQuantityAvailable,
            'message' => 'El stock de la furgoneta ha sido actualizado con éxito.',
        ], 200);
    }

    /**
     * @param array<string, string> $fieldErrors
     */
    private function invalidPayload(array $fieldErrors): Response
    {
        return Response::error(
            VanStockValidationException::INVALID_PAYLOAD_CODE,
            'Los datos del stock son incompletos o erróneos.',
            VanStockValidationException::UNPROCESSABLE_HTTP_STATUS,
            $fieldErrors
        );
    }

    /**
     * Entero positivo a partir de un valor de query o de cuerpo; `null` si no lo es.
     */
    private function positiveIntOrNull(mixed $value): ?int
    {
        $int = $this->intOrNull($value);

        return $int !== null && $int > 0 ? $int : null;
    }

    /**
     * Entero a partir de un valor de query o de cuerpo; `null` si no tiene esa forma.
     *
     * Acepta `int` y cadenas de dígitos con signo, que es lo que llega por query string. Rechaza
     * decimales y notación científica: `1e3` no es una cantidad declarable.
     */
    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int)trim($value);
        }

        return null;
    }

    /**
     * Bandera booleana de query: `null` cuando el valor no es reconocible como sí o como no.
     */
    private function booleanFlagOrNull(mixed $value): ?bool
    {
        $normalized = strtolower(trim((string)$value));

        return match ($normalized) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };
    }
}
