<?php

declare(strict_types=1);

/**
 * VendGuard - Puerto de hojas de servicio neutro (arnés de pruebas)
 *
 * Las suites del flujo de repuestos y de los controladores construyen
 * `SparePartTraceabilityService` sin ejercitar el Módulo 19. Desde la firma digital el
 * servicio exige el puerto de hojas, así que estos escenarios necesitan un doble que no
 * escriba nada y que falle en voz alta si alguien intenta guardar una hoja: un cierre que
 * llegara aquí con firma sería un escenario que ya no es el que la suite declara probar.
 */
final class NullServiceSheetRepository implements \VendGuard\Core\Domain\Repository\IncidentServiceSheetRepositoryInterface
{
    public function insert(\VendGuard\Core\Domain\Model\IncidentServiceSheet $sheet): \VendGuard\Core\Domain\Model\IncidentServiceSheet
    {
        throw new \LogicException('Esta suite no declara hojas de servicio: el cierre no debía firmar.');
    }

    public function findDocumentByIncidentId(int $incidentId, ?int $sheetId = null): ?array
    {
        return null;
    }

    public function listByIncidentId(int $incidentId): array
    {
        return [];
    }
}
