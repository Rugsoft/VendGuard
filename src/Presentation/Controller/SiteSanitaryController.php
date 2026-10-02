<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use Closure;
use DomainException;
use InvalidArgumentException;
use Throwable;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\SanitaryCertificateService;
use VendGuard\Core\Domain\Exception\CannotIssueNonConformCertificateException;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveOrderRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoSanitaryCertificateRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * SiteSanitaryController
 * 
 * Controlador REST para el Portal de Ubicación / Sede en Mantenimiento Preventivo (Módulo 05).
 * 
 * Endpoints registrados bajo SiteAuthMiddleware:
 * - GET /api/site/sanitary-status
 * - GET /api/site/certificates/machine/{code}
 * - GET /api/site/certificates/global
 * 
 * Cumple con:
 * - RF-PREV-06 (EARS 6.2): Visualización de semáforos higiénicos, última desinfección y temperatura.
 * - RF-PREV-07 (EARS 7.1, 7.2): Emisión y descarga de Certificados Sanitario Individual y Global Consolidado.
 * - RNF-03: Impresión optimizada en A4 (@media print).
 * - Constitución Art. II: Seguridad alimentaria.
 * - Constitución Art. V.4: Privacidad estricta de técnicos mediante Código de Operador Técnico Oficial.
 * - Dogma Vanilla (PHP 8.2+ sin dependencias de frameworks).
 * - Dualismo Lingüístico (código en inglés, interfaz y certificados en español).
 */
class SiteSanitaryController
{
    private SanitaryCertificateService $certificateService;
    private LocationRepositoryInterface $locationRepo;
    private MachineRepositoryInterface $machineRepo;

    public function __construct(
        ?SanitaryCertificateService $certificateService = null,
        ?LocationRepositoryInterface $locationRepo = null,
        ?MachineRepositoryInterface $machineRepo = null
    ) {
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();

        $this->certificateService = $certificateService ?? new SanitaryCertificateService(
            certificateRepo: new PdoSanitaryCertificateRepository(),
            orderRepo: new PdoPreventiveOrderRepository(),
            settingsRepo: new PdoPreventiveSettingsRepository(),
            machineRepo: $this->machineRepo,
            auditLogger: new AuditLogger(new PdoAuditLogRepository())
        );
    }

    // =========================================================================
    // 1. Semáforo Higiénico y Estado Sanitario de Sede (RF-PREV-06, EARS 6.2)
    // =========================================================================

    /**
     * GET /api/site/sanitary-status
     * 
     * Retorna el estado higiénico general de la sede y el semáforo sanitario de cada
     * máquina instalada (última desinfección, última temperatura y avisos).
     */
    public function getSanitaryStatus(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $locationId = $this->extractLocationId($request);
            $statusData = $this->certificateService->getSiteSanitaryStatus($locationId);

            return Response::json($statusData, 200);
        });
    }

    // =========================================================================
    // 2. Certificado Sanitario Oficial Individual (RF-PREV-07, EARS 7.1, 7.4)
    // =========================================================================

    /**
     * GET /api/site/certificates/machine/{code}
     * 
     * Devuelve el Certificado Sanitario Oficial de una máquina de la sede en formato JSON
     * o vista HTML A4 imprimible (@media print) si se solicita Accept: text/html.
     */
    public function getMachineCertificate(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $code = trim((string)($request->getRouteParam('code') ?? $request->getRouteParam('machine_code')));
            if ($code === '') {
                throw new InvalidArgumentException('El código de máquina es obligatorio.');
            }

            $locationId = $this->extractLocationId($request);

            // Validar existencia y pertenencia de la máquina a la sede autenticada
            $machine = $this->machineRepo->findByCode($code);
            if ($machine === null) {
                return Response::error('MACHINE_NOT_FOUND', "Máquina con código '{$code}' no encontrada.", 404);
            }

            if ($machine->getLocationId() !== $locationId) {
                return Response::error('FORBIDDEN', 'La máquina solicitada no pertenece a la sede autenticada.', 403);
            }

            $certData = $this->certificateService->getIndividualCertificate($code);

            // Si se solicita formato HTML para impresión A4
            $acceptHeader = (string)$request->getHeader('Accept');
            $format = (string)$request->getQuery('format');
            if (str_contains($acceptHeader, 'text/html') || strtolower($format) === 'html') {
                $html = $this->renderIndividualCertificateHtml($certData);
                return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }

            return Response::json($certData, 200);
        });
    }

    // =========================================================================
    // 3. Certificado Global Consolidado de Sede (RF-PREV-07, EARS 7.2)
    // =========================================================================

    /**
     * GET /api/site/certificates/global
     * 
     * Retorna el Certificado Global Consolidado de Sede con dictamen unívoco
     * (CONFORME, CONFORME_CON_OBSERVACIONES o CONDICIONADO ante incidencias de parque).
     */
    public function getGlobalCertificate(Request $request): Response
    {
        return $this->handleExecution(function () use ($request): Response {
            $locationId = $this->extractLocationId($request);
            $reportData = $this->certificateService->getGlobalSiteCertificate($locationId);

            // Si se solicita formato HTML para impresión A4
            $acceptHeader = (string)$request->getHeader('Accept');
            $format = (string)$request->getQuery('format');
            if (str_contains($acceptHeader, 'text/html') || strtolower($format) === 'html') {
                $html = $this->renderGlobalCertificateHtml($reportData);
                return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }

            return Response::json($reportData, 200);
        });
    }

    // =========================================================================
    // Plantillas de Impresión A4 (@media print, RNF-03)
    // =========================================================================

    /**
     * @param array<string, mixed> $cert
     */
    private function renderIndividualCertificateHtml(array $cert): string
    {
        $code = htmlspecialchars((string)($cert['certificate_code'] ?? ''));
        $status = htmlspecialchars((string)($cert['status'] ?? ''));
        $machine = $cert['machine'] ?? [];
        $location = $cert['location'] ?? [];
        $inspector = $cert['inspector'] ?? [];
        $items = $cert['inspected_items'] ?? [];

        $machCode = htmlspecialchars((string)($machine['code'] ?? ''));
        $machModel = htmlspecialchars((string)($machine['model'] ?? ''));
        $machSerial = htmlspecialchars((string)($machine['serial_number'] ?? ''));
        $machType = htmlspecialchars((string)($machine['machine_type'] ?? ''));
        $locName = htmlspecialchars((string)($location['name'] ?? ''));
        $locAddr = htmlspecialchars((string)($location['address'] ?? ''));
        $inspName = htmlspecialchars((string)($inspector['name'] ?? ''));
        $inspOp = htmlspecialchars((string)($inspector['operator_code'] ?? ''));
        $inspDate = htmlspecialchars((string)($cert['inspection_date'] ?? ''));
        $validUntil = htmlspecialchars((string)($cert['valid_until'] ?? ''));
        $result = htmlspecialchars((string)($cert['result'] ?? ''));
        $temp = isset($cert['temperature_measured']) && $cert['temperature_measured'] !== null
            ? htmlspecialchars((string)$cert['temperature_measured']) . ' °C'
            : 'N/A';

        $itemsHtml = '';
        foreach ($items as $item) {
            $iName = htmlspecialchars((string)($item['item'] ?? $item['title'] ?? 'Comprobación'));
            $iStatus = htmlspecialchars((string)($item['status'] ?? 'CONFORME'));
            $itemsHtml .= "<tr><td>{$iName}</td><td style='text-align:right; font-weight:bold; color:#15803d;'>{$iStatus}</td></tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Certificado Sanitario Oficial - {$code}</title>
<style>
  @page { size: A4 portrait; margin: 15mm; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color: #1e293b; margin: 0; padding: 20px; line-height: 1.5; }
  .cert-container { max-width: 800px; margin: 0 auto; border: 3px double #0284c7; padding: 30px; border-radius: 8px; }
  .header { text-align: center; border-bottom: 2px solid #0284c7; padding-bottom: 15px; margin-bottom: 20px; }
  .header h1 { margin: 0; color: #0369a1; font-size: 24px; text-transform: uppercase; }
  .header p { margin: 5px 0 0; color: #64748b; font-size: 13px; }
  .badge { display: inline-block; padding: 6px 14px; background: #dcfce7; color: #166534; font-weight: bold; border-radius: 20px; font-size: 14px; margin-top: 10px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
  .box { background: #f8fafc; padding: 15px; border-radius: 6px; border: 1px solid #e2e8f0; }
  .box h3 { margin: 0 0 10px; font-size: 14px; color: #0369a1; text-transform: uppercase; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
  .box p { margin: 4px 0; font-size: 13px; }
  .box strong { color: #334155; }
  table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
  th, td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; }
  th { background: #f1f5f9; text-align: left; color: #475569; }
  .footer { margin-top: 30px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px; }
  @media print {
    body { padding: 0; }
    .cert-container { border: 2px solid #0284c7; }
    .no-print { display: none; }
  }
</style>
</head>
<body>
<div class="cert-container">
  <div class="header">
    <h1>Certificado Sanitario Oficial de Aptitud</h1>
    <p>Conforme al Artículo II de la Constitución de VendGuard y Normativa Higiénica de Alimentos</p>
    <div class="badge">REGISTRO OFICIAL VÁLIDO: {$code}</div>
  </div>

  <div class="grid">
    <div class="box">
      <h3>Datos de la Máquina Dispensadora</h3>
      <p><strong>Código Máquina:</strong> {$machCode}</p>
      <p><strong>Modelo:</strong> {$machModel}</p>
      <p><strong>Número de Serie:</strong> {$machSerial}</p>
      <p><strong>Tipología Normativa:</strong> {$machType}</p>
    </div>
    <div class="box">
      <h3>Ubicación / Sede</h3>
      <p><strong>Centro:</strong> {$locName}</p>
      <p><strong>Dirección:</strong> {$locAddr}</p>
      <p><strong>Dictamen de Inspección:</strong> <span style="color:#16a34a; font-weight:bold;">{$result}</span></p>
      <p><strong>Temperatura Registrada:</strong> {$temp}</p>
    </div>
  </div>

  <div class="box" style="margin-bottom: 20px;">
    <h3>Verificación y Trazabilidad Técnica (Art. V.4)</h3>
    <div class="grid" style="margin-bottom: 0;">
      <div>
        <p><strong>Inspector Autorizado:</strong> {$inspName}</p>
        <p><strong>Código de Operador Oficial:</strong> {$inspOp}</p>
      </div>
      <div>
        <p><strong>Fecha y Hora de Inspección:</strong> {$inspDate}</p>
        <p><strong>Vigencia Reglamentaria Hasta:</strong> <span style="color:#0284c7; font-weight:bold;">{$validUntil}</span></p>
      </div>
    </div>
  </div>

  <div class="box">
    <h3>Comprobaciones Sanitarias Normativas</h3>
    <table>
      <thead>
        <tr><th>Punto de Control</th><th style="text-align:right;">Calificación</th></tr>
      </thead>
      <tbody>
        {$itemsHtml}
      </tbody>
    </table>
  </div>

  <div class="footer">
    <p>Documento oficial emitido conforme a la Constitución de VendGuard. Identificación del personal restringida a Código de Operador Técnico Oficial (Art. V.4). Válido ante inspecciones de sanidad pública y clientes.</p>
  </div>
</div>
</body>
</html>
HTML;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderGlobalCertificateHtml(array $report): string
    {
        $code = htmlspecialchars((string)($report['global_certificate_code'] ?? ''));
        $location = $report['location'] ?? [];
        $locCode = htmlspecialchars((string)($location['site_code'] ?? ''));
        $locName = htmlspecialchars((string)($location['name'] ?? ''));
        $locAddr = htmlspecialchars((string)($location['address'] ?? ''));
        $issueDate = htmlspecialchars((string)($report['issue_date'] ?? date('Y-m-d H:i:s')));
        $verdict = htmlspecialchars((string)($report['global_verdict'] ?? 'CONFORME'));
        $explanation = htmlspecialchars((string)($report['verdict_explanation'] ?? ''));
        $breakdown = $report['machines_breakdown'] ?? [];

        $badgeColor = match ($verdict) {
            'CONFORME' => 'background:#dcfce7; color:#166534;',
            'CONFORME_CON_OBSERVACIONES' => 'background:#fef9c3; color:#854d0e;',
            default => 'background:#fee2e2; color:#991b1b;',
        };

        $rowsHtml = '';
        foreach ($breakdown as $row) {
            $mCode = htmlspecialchars((string)($row['code'] ?? ''));
            $mType = htmlspecialchars((string)($row['machine_type'] ?? ''));
            $mFloor = htmlspecialchars((string)($row['floor_wing'] ?? ''));
            $mVerdict = htmlspecialchars((string)($row['verdict'] ?? ''));
            $mQuarantine = !empty($row['quarantine']) ? '<span style="color:#dc2626; font-weight:bold;">SÍ (CUARENTENA)</span>' : '<span style="color:#16a34a;">NO</span>';
            $mDetail = htmlspecialchars((string)($row['detail'] ?? ''));

            $rowsHtml .= "<tr>
                <td><strong>{$mCode}</strong></td>
                <td>{$mType}</td>
                <td>{$mFloor}</td>
                <td><strong>{$mVerdict}</strong></td>
                <td>{$mQuarantine}</td>
                <td style='font-size:12px;'>{$mDetail}</td>
            </tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Certificado Global Consolidado de Sede - {$code}</title>
<style>
  @page { size: A4 landscape; margin: 15mm; }
  body { font-family: 'Segoe UI', Arial, sans-serif; color: #1e293b; margin: 0; padding: 20px; line-height: 1.5; }
  .cert-container { max-width: 1000px; margin: 0 auto; border: 3px double #0284c7; padding: 25px; border-radius: 8px; }
  .header { text-align: center; border-bottom: 2px solid #0284c7; padding-bottom: 12px; margin-bottom: 15px; }
  .header h1 { margin: 0; color: #0369a1; font-size: 22px; text-transform: uppercase; }
  .header p { margin: 4px 0 0; color: #64748b; font-size: 13px; }
  .badge { display: inline-block; padding: 6px 14px; font-weight: bold; border-radius: 20px; font-size: 14px; margin-top: 8px; {$badgeColor} }
  .meta-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 15px; font-size: 13px; }
  .box { background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 12px; }
  th, td { padding: 7px 10px; border-bottom: 1px solid #cbd5e1; }
  th { background: #f1f5f9; text-align: left; color: #475569; }
  .footer { margin-top: 20px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 8px; }
  @media print {
    body { padding: 0; }
    .cert-container { border: 2px solid #0284c7; }
    .no-print { display: none; }
  }
</style>
</head>
<body>
<div class="cert-container">
  <div class="header">
    <h1>Certificado Global Consolidado de Sede</h1>
    <p>Inspección Higiénico-Sanitaria y Control de Cadena de Frío</p>
    <div class="badge">DICTAMEN GLOBAL: {$verdict}</div>
  </div>

  <div class="meta-grid">
    <div class="box">
      <strong>Sede Cliente:</strong> {$locName} ({$locCode})<br>
      <strong>Dirección:</strong> {$locAddr}<br>
      <strong>Observaciones / Diagnóstico:</strong> {$explanation}
    </div>
    <div class="box">
      <strong>Código Expediente:</strong> {$code}<br>
      <strong>Fecha de Expedición:</strong> {$issueDate}
    </div>
  </div>

  <div class="box">
    <strong style="color:#0369a1; text-transform:uppercase; font-size:13px;">Desglose Individual de Parque Instalado</strong>
    <table>
      <thead>
        <tr>
          <th>Código</th>
          <th>Tipo Máquina</th>
          <th>Ubicación</th>
          <th>Calificación</th>
          <th>Cuarentena</th>
          <th>Detalle de Inspección</th>
        </tr>
      </thead>
      <tbody>
        {$rowsHtml}
      </tbody>
    </table>
  </div>

  <div class="footer">
    <p>Certificación integral de instalaciones vending conforme a los Artículos II y V.4 de la Constitución de VendGuard. Documento imprimible en formato A4 (@media print).</p>
  </div>
</div>
</body>
</html>
HTML;
    }

    // =========================================================================
    // Métodos Auxiliares y Mapeo Centralizado de Excepciones
    // =========================================================================

    private function handleExecution(Closure $callback): Response
    {
        try {
            return $callback();
        } catch (CannotIssueNonConformCertificateException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                $e->getDetails()
            );
        } catch (InvalidArgumentException $e) {
            return Response::error('VALIDATION_ERROR', $e->getMessage(), 400);
        } catch (DomainException $e) {
            $code = method_exists($e, 'getErrorCode') ? (string)$e->getErrorCode() : 'DOMAIN_ERROR';
            $statusCode = method_exists($e, 'getHttpStatusCode')
                ? (int)$e->getHttpStatusCode()
                : ($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400);
            return Response::error($code, $e->getMessage(), $statusCode);
        } catch (Throwable $e) {
            // El mensaje de la excepción no se devuelve al cliente. Un
            // `PDOException` arrastra la consulta SQL completa, que es un mapa
            // de la base de datos; el `details` del Router añade además clase,
            // fichero y línea. El rastro se queda en el log del servidor.
            error_log('[vendguard] ' . $e);

            return Response::error('INTERNAL_SERVER_ERROR', 'Error interno del servidor.', 500);
        }
    }

    private function extractLocationId(Request $request): int
    {
        $location = $request->getAttribute('authenticated_location');
        if ($location instanceof Location) {
            return $location->getId();
        }

        $locId = $request->getAttribute('location_id');
        if ($locId !== null && is_numeric($locId) && (int)$locId > 0) {
            return (int)$locId;
        }

        throw new DomainException('No se pudo determinar la sede autenticada.', 401);
    }
}
