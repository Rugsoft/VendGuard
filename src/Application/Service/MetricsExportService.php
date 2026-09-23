<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Core\Domain\Model\AuditEvent;

/**
 * MetricsExportService
 * 
 * Servicio de Aplicación responsable de generar exportaciones tabulares nativas en formato CSV.
 * Da estricto cumplimiento a RF-06 (EARS 6.1, 6.2) y al Dogma Vanilla (sin dependencias externas).
 * 
 * Características clave:
 * 1. Byte Order Mark (BOM) UTF-8 (\xEF\xBB\xBF) al inicio del flujo para compatibilidad directa
 *    con Microsoft Excel en Windows (evitando problemas de codificación de tildes y caracteres en castellano).
 * 2. Formateo y escape riguroso mediante fputcsv en memoria (php://temp).
 * 3. Límite de seguridad estricto de hasta 10.000 registros para descargas del registro de auditoría (EARS 6.2).
 */
class MetricsExportService
{
    public const UTF8_BOM = "\xEF\xBB\xBF";
    public const AUDIT_EXPORT_LIMIT = 10000;

    /**
     * Genera el contenido CSV estructurado para las métricas agregadas y desgloses (EARS 6.1).
     * 
     * @param array{
     *   by_location?: list<array<string, mixed>>,
     *   by_technician?: list<array<string, mixed>>,
     *   by_machine_type?: list<array<string, mixed>>,
     *   by_category?: list<array<string, mixed>>
     * } $breakdownData
     * @return string Flujo CSV con BOM UTF-8.
     */
    public function exportMetricsCsv(array $breakdownData): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return self::UTF8_BOM;
        }

        // 1. Cabecera de columnas formalizada en contrato 3.3
        $headers = [
            'Dimensión',
            'Identificador',
            'Nombre',
            'Activo',
            'Tickets Resueltos',
            'MTTR Minutos',
            'MTTR Formateado',
            'MTTR Horas',
            'SLA Objetivo Horas',
            'Estado SLA'
        ];
        fputcsv($handle, $headers, ',');

        // 2. Filas de Sedes
        if (!empty($breakdownData['by_location'])) {
            foreach ($breakdownData['by_location'] as $loc) {
                fputcsv($handle, [
                    'Sede',
                    $loc['site_code'] ?? (string)($loc['location_id'] ?? ''),
                    $loc['location_name'] ?? '',
                    !empty($loc['is_active']) ? 'Sí' : 'No',
                    (string)($loc['tickets_resolved'] ?? 0),
                    $loc['mttr_minutes'] !== null ? (string)$loc['mttr_minutes'] : 'N/A',
                    $loc['mttr_formatted'] ?? 'N/A',
                    $loc['mttr_hours'] !== null ? (string)$loc['mttr_hours'] : 'N/A',
                    isset($loc['sla_target_hours']) ? (string)$loc['sla_target_hours'] : '24.0',
                    $loc['sla_status'] ?? 'N/A',
                ], ',');
            }
        }

        // 3. Filas de Técnicos
        if (!empty($breakdownData['by_technician'])) {
            foreach ($breakdownData['by_technician'] as $tech) {
                fputcsv($handle, [
                    'Técnico',
                    (string)($tech['technician_id'] ?? ''),
                    $tech['technician_name'] ?? '',
                    !empty($tech['is_active']) ? 'Sí' : 'No',
                    (string)($tech['tickets_resolved'] ?? 0),
                    $tech['mttr_minutes'] !== null ? (string)$tech['mttr_minutes'] : 'N/A',
                    $tech['mttr_formatted'] ?? 'N/A',
                    $tech['mttr_hours'] !== null ? (string)$tech['mttr_hours'] : 'N/A',
                    'N/A',
                    'N/A',
                ], ',');
            }
        }

        // 4. Filas de Tipos de Máquina (con foco sanitario en perecederos)
        if (!empty($breakdownData['by_machine_type'])) {
            foreach ($breakdownData['by_machine_type'] as $mType) {
                fputcsv($handle, [
                    'Tipo Máquina',
                    $mType['machine_type'] ?? '',
                    $mType['display_name'] ?? ($mType['machine_type'] ?? ''),
                    'Sí',
                    (string)($mType['tickets_resolved'] ?? 0),
                    $mType['mttr_minutes'] !== null ? (string)$mType['mttr_minutes'] : 'N/A',
                    $mType['mttr_formatted'] ?? 'N/A',
                    $mType['mttr_hours'] !== null ? (string)$mType['mttr_hours'] : 'N/A',
                    isset($mType['sla_target_hours']) ? (string)$mType['sla_target_hours'] : 'N/A',
                    $mType['sla_status'] ?? 'N/A',
                ], ',');
            }
        }

        // 5. Filas de Categorías de Avería
        if (!empty($breakdownData['by_category'])) {
            foreach ($breakdownData['by_category'] as $cat) {
                fputcsv($handle, [
                    'Categoría Avería',
                    $cat['category'] ?? '',
                    $cat['display_name'] ?? ($cat['category'] ?? ''),
                    'Sí',
                    (string)($cat['tickets_resolved'] ?? 0),
                    $cat['mttr_minutes'] !== null ? (string)$cat['mttr_minutes'] : 'N/A',
                    $cat['mttr_formatted'] ?? 'N/A',
                    'N/A',
                    'N/A',
                    'N/A',
                ], ',');
            }
        }

        rewind($handle);
        $csvBody = stream_get_contents($handle);
        fclose($handle);

        return self::UTF8_BOM . ($csvBody !== false ? $csvBody : '');
    }

    /**
     * Genera el archivo plano CSV para las entradas del log de auditoría (EARS 6.2).
     * Aplica el tope de seguridad de 10.000 filas para salvaguardar la memoria del servidor.
     * 
     * @param list<AuditEvent> $auditEvents
     * @return string Flujo CSV con BOM UTF-8.
     */
    public function exportAuditLogCsv(array $auditEvents): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return self::UTF8_BOM;
        }

        // 1. Cabecera formalizada en contrato 3.5
        $headers = [
            'ID',
            'Fecha y Hora',
            'Entidad',
            'ID Entidad',
            'Acción',
            'Usuario ID',
            'Nombre Usuario',
            'Rol',
            'Estado Previo',
            'Estado Nuevo',
            'Diagnóstico',
            'Solución',
            'Piezas Sustituidas'
        ];
        fputcsv($handle, $headers, ',');

        // 2. Acotar a un máximo de seguridad de 10.000 registros
        $recordsToExport = array_slice($auditEvents, 0, self::AUDIT_EXPORT_LIMIT);

        foreach ($recordsToExport as $event) {
            $prevState = $event->getPreviousState();
            $newState = $event->getNewState();

            // Extraer estado canónico de status
            $prevStatus = $prevState['status'] ?? ($prevState['previous_status'] ?? '');
            if (is_array($prevStatus)) {
                $prevStatus = json_encode($prevStatus, JSON_UNESCAPED_UNICODE);
            }
            $newStatus = $newState['status'] ?? ($newState['new_status'] ?? '');
            if (is_array($newStatus)) {
                $newStatus = json_encode($newStatus, JSON_UNESCAPED_UNICODE);
            }

            // Diagnóstico y solución técnica (Art. V.1)
            $diagnosis = $newState['diagnosis'] ?? ($newState['resolution_diagnosis'] ?? '');
            $solution = $newState['solution'] ?? ($newState['resolution_action'] ?? '');

            // Piezas sustituidas (Art. III.3)
            $parts = '';
            if (!empty($newState['parts_replaced']) && is_array($newState['parts_replaced'])) {
                $parts = implode('; ', $newState['parts_replaced']);
            }

            fputcsv($handle, [
                (string)$event->getId(),
                $event->getCreatedAt(),
                $event->getEntityType(),
                (string)$event->getEntityId(),
                $event->getAction(),
                $event->getUserId() !== null ? (string)$event->getUserId() : 'N/A',
                $event->getUserName(),
                $event->getUserRole(),
                (string)$prevStatus,
                (string)$newStatus,
                (string)$diagnosis,
                (string)$solution,
                $parts,
            ], ',');
        }

        rewind($handle);
        $csvBody = stream_get_contents($handle);
        fclose($handle);

        return self::UTF8_BOM . ($csvBody !== false ? $csvBody : '');
    }
}
