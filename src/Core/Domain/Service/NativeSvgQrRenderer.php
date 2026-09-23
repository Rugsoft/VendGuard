<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Model\QrLabelConfig;

/**
 * NativeSvgQrRenderer
 * 
 * Renderizador vectorial nativo de etiquetas adhesivas y códigos QR en formato SVG.
 * Transforma configuraciones de dominio (QrLabelConfig) y matrices booleanas en
 * gráficos vectoriales puros listos para impresión de alta fidelidad o visualización web.
 * 
 * Cumple con RF-01 (EARS 1.4), RF-02 (EARS 2.3), RNF-01, RNF-03, RNF-04 y el Dogma Vanilla.
 */
class NativeSvgQrRenderer
{
    /**
     * Renderiza una etiqueta adhesiva completa de 400x600 px en SVG estándar.
     *
     * @param QrLabelConfig $config Configuración y metadatos de la etiqueta.
     * @return string Documento XML SVG completo y válido.
     * @throws InvalidArgumentException Si la configuración o datos son inválidos.
     */
    public static function renderLabel(QrLabelConfig $config): string
    {
        $width = $config->getWidth();
        $height = $config->getHeight();

        // 1. Generar la matriz binaria del código QR (nivel ECC M con quiet zone de 4 módulos)
        $matrix = QrMatrixGenerator::generate($config->getTargetUrl(), QrMatrixGenerator::ECC_M, 4);

        // 2. Calcular posición y escala del código QR (mínimo 40x40 mm -> ~152 px; usamos 200x200 px)
        $qrSize = 200.0;
        $qrX = ($width - $qrSize) / 2.0;
        $qrY = 220.0;
        $qrPath = self::matrixToSvgPath($matrix, $qrX, $qrY, $qrSize);

        // 3. Sanitizar campos de texto para XML seguro (evitar XSS o ruptura XML)
        $machineCode = self::escapeXml($config->getMachineCode());
        $machineModel = self::escapeXml(self::truncateText($config->getMachineModel(), 38));
        $locationName = self::escapeXml(self::truncateText($config->getLocationName(), 36));
        $floorWing = self::escapeXml(self::truncateText($config->getFloorWing(), 36));
        $supportPhone = self::escapeXml($config->getSupportPhone());
        $isPerishable = $config->isPerishable();

        // 4. Distintivo de perecedero / no perecedero
        if ($isPerishable) {
            $badgeBg = '#fee2e2';
            $badgeBorder = '#fca5a5';
            $badgeText = '#991b1b';
            $badgeLabel = '❄️ ALIMENTOS FRESCOS · ROTURA DE FRÍO CRÍTICA';
        } else {
            $badgeBg = '#f0fdf4';
            $badgeBorder = '#bbf7d0';
            $badgeText = '#166534';
            $badgeLabel = '☕ BEBIDAS Y SNACKS · SERVICIO ESTÁNDAR';
        }

        // 5. Construcción del SVG
        $svg = [];
        $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '">';
        
        // Estilos CSS incrustados
        $svg[] = '  <defs>';
        $svg[] = '    <style>';
        $svg[] = '      .label-bg { fill: #ffffff; stroke: #cbd5e1; stroke-width: 2; rx: 16px; }';
        $svg[] = '      .header-bg { fill: #0f172a; }';
        $svg[] = '      .font-sans { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }';
        $svg[] = '      .brand-title { font-size: 19px; font-weight: 800; fill: #ffffff; letter-spacing: 1.5px; }';
        $svg[] = '      .brand-subtitle { font-size: 9px; font-weight: 600; fill: #38bdf8; letter-spacing: 0.8px; }';
        $svg[] = '      .machine-code { font-size: 28px; font-weight: 900; fill: #0f172a; letter-spacing: 1px; font-family: "Courier New", Courier, monospace; }';
        $svg[] = '      .machine-meta { font-size: 13px; font-weight: 600; fill: #334155; }';
        $svg[] = '      .location-meta { font-size: 11px; fill: #64748b; font-weight: 500; }';
        $svg[] = '      .badge-text { font-size: 10px; font-weight: 700; }';
        $svg[] = '      .cta-title { font-size: 12px; font-weight: 800; fill: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; }';
        $svg[] = '      .cta-desc { font-size: 11px; font-weight: 500; fill: #475569; }';
        $svg[] = '      .phone-label { font-size: 10px; font-weight: 700; fill: #0369a1; text-transform: uppercase; letter-spacing: 0.5px; }';
        $svg[] = '      .phone-number { font-size: 16px; font-weight: 800; fill: #0c4a6e; letter-spacing: 0.5px; }';
        $svg[] = '      .footer-note { font-size: 9px; fill: #94a3b8; }';
        $svg[] = '    </style>';
        $svg[] = '  </defs>';

        // Fondo de la tarjeta con bordes redondeados
        $svg[] = '  <!-- Contenedor Principal de la Etiqueta Adhesiva -->';
        $svg[] = '  <rect width="' . $width . '" height="' . $height . '" class="label-bg" />';

        // Franja de Cabecera Oficial VendGuard
        $svg[] = '  <!-- Cabecera Oficial y Logotipo -->';
        $svg[] = '  <path d="M 0 16 Q 0 0 16 0 L ' . ($width - 16) . ' 0 Q ' . $width . 0 . ' ' . $width . ' 16 L ' . $width . ' 64 L 0 64 Z" class="header-bg" />';
        
        // Logotipo Vectorial VendGuard (Escudo con check e icono de vending)
        $svg[] = '  <g transform="translate(24, 16)">';
        $svg[] = '    <rect width="32" height="32" rx="8" fill="#0284c7" />';
        $svg[] = '    <path d="M 9 16 L 14 21 L 23 11" fill="none" stroke="#ffffff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />';
        $svg[] = '  </g>';
        $svg[] = '  <text x="68" y="36" class="font-sans brand-title">VENDGUARD</text>';
        $svg[] = '  <text x="68" y="49" class="font-sans brand-subtitle">SISTEMA INTELIGENTE DE CONTROL DE VENDING</text>';

        // Tarjeta de Identificación de la Máquina
        $svg[] = '  <!-- Identificación de Máquina y Sede -->';
        $svg[] = '  <rect x="20" y="74" width="360" height="134" rx="10" fill="#f8fafc" stroke="#e2e8f0" stroke-width="1.5" />';
        
        // Código de la Máquina destacado
        $svg[] = '  <text x="200" y="104" text-anchor="middle" class="machine-code">' . $machineCode . '</text>';
        
        // Modelo de la Máquina
        $svg[] = '  <text x="200" y="124" text-anchor="middle" class="font-sans machine-meta">' . $machineModel . '</text>';

        // Badge Perecedero / No perecedero
        $svg[] = '  <rect x="35" y="134" width="330" height="22" rx="6" fill="' . $badgeBg . '" stroke="' . $badgeBorder . '" stroke-width="1" />';
        $svg[] = '  <text x="200" y="149" text-anchor="middle" fill="' . $badgeText . '" class="font-sans badge-text">' . $badgeLabel . '</text>';

        // Sede y Ubicación
        $svg[] = '  <text x="200" y="174" text-anchor="middle" class="font-sans location-meta">' . $locationName . '</text>';
        $svg[] = '  <text x="200" y="191" text-anchor="middle" class="font-sans location-meta">📍 ' . $floorWing . '</text>';

        // Marco y Renderizado Central del Código QR (Mínimo 40x40 mm garantizado)
        $svg[] = '  <!-- Código QR Vectorial Centralizado -->';
        $svg[] = '  <rect x="' . ($qrX - 6) . '" y="' . ($qrY - 6) . '" width="' . ($qrSize + 12) . '" height="' . ($qrSize + 12) . '" rx="8" fill="#ffffff" stroke="#e2e8f0" stroke-width="1.5" />';
        $svg[] = '  ' . $qrPath;

        // Llamada a la Acción (Call To Action - EARS 1.4)
        $svg[] = '  <!-- Instrucciones y Llamada a la Acción -->';
        $svg[] = '  <text x="200" y="444" text-anchor="middle" class="font-sans cta-title">¿AVERÍA O DINERO RETENIDO?</text>';
        $svg[] = '  <text x="200" y="462" text-anchor="middle" class="font-sans cta-desc">Escanea este código con la cámara de tu móvil para</text>';
        $svg[] = '  <text x="200" y="478" text-anchor="middle" class="font-sans cta-desc">avisar de una avería o reclamar dinero retenido</text>';

        // Bloque de Asistencia Telefónica
        $svg[] = '  <!-- Contacto de Asistencia Técnica Directa -->';
        $svg[] = '  <rect x="24" y="496" width="352" height="66" rx="10" fill="#f0f9ff" stroke="#bae6fd" stroke-width="1.5" />';
        $svg[] = '  <text x="200" y="520" text-anchor="middle" class="font-sans phone-label">📞 Asistencia Técnica y Atención Telefónica</text>';
        $svg[] = '  <text x="200" y="546" text-anchor="middle" class="font-sans phone-number">' . $supportPhone . '</text>';

        // Pie de Página
        $svg[] = '  <!-- Pie Institucional -->';
        $svg[] = '  <text x="200" y="583" text-anchor="middle" class="font-sans footer-note">VendGuard Security &amp; Safety · www.vendguard.onrender.com</text>';

        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    /**
     * Renderiza un código QR independiente en formato SVG a partir de cualquier texto o URL.
     *
     * @param string $text Texto o enlace a codificar.
     * @param int $sizePx Dimensión en píxeles del SVG resultante.
     * @param string $eccLevel Nivel de corrección Reed-Solomon ('L', 'M', 'Q', 'H').
     * @param int $quietZone Módulos de zona de silencio exterior.
     * @return string Elemento SVG autónomo.
     */
    public static function renderStandaloneQr(
        string $text,
        int $sizePx = 250,
        string $eccLevel = QrMatrixGenerator::ECC_M,
        int $quietZone = 4
    ): string {
        if ($sizePx <= 0) {
            throw new InvalidArgumentException('El tamaño en píxeles del código QR debe ser positivo.');
        }

        $matrix = QrMatrixGenerator::generate($text, $eccLevel, $quietZone);
        $path = self::matrixToSvgPath($matrix, 0.0, 0.0, (float)$sizePx);

        $svg = [];
        $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $sizePx . ' ' . $sizePx . '" width="' . $sizePx . '" height="' . $sizePx . '">';
        $svg[] = '  <rect width="' . $sizePx . '" height="' . $sizePx . '" fill="#ffffff" />';
        $svg[] = '  ' . $path;
        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    /**
     * Convierte una matriz booleana 2D de módulos QR en un elemento SVG `<path>` de alta eficiencia.
     *
     * @param array<int, array<int, bool>> $matrix Matriz donde true = módulo oscuro y false = módulo claro.
     * @param float $startX Coordenada horizontal superior izquierda.
     * @param float $startY Coordenada vertical superior izquierda.
     * @param float $targetSize Tamaño físico total reservado en píxeles.
     * @return string Elemento SVG `<path d="..." fill="#0f172a" />`.
     */
    public static function matrixToSvgPath(
        array $matrix,
        float $startX,
        float $startY,
        float $targetSize
    ): string {
        $moduleCount = count($matrix);
        if ($moduleCount === 0) {
            return '';
        }

        $moduleSize = $targetSize / $moduleCount;
        $pathCommands = [];

        for ($r = 0; $r < $moduleCount; $r++) {
            for ($c = 0; $c < $moduleCount; $c++) {
                if ($matrix[$r][$c]) {
                    $x = round($startX + $c * $moduleSize, 2);
                    $y = round($startY + $r * $moduleSize, 2);
                    $s = round($moduleSize, 2);
                    $pathCommands[] = "M {$x},{$y} h {$s} v {$s} h -{$s} Z";
                }
            }
        }

        return '<path d="' . implode(' ', $pathCommands) . '" fill="#0f172a" />';
    }

    /**
     * Escapa caracteres especiales para inserción segura en XML SVG.
     */
    private static function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Trunca un texto si excede una longitud máxima, añadiendo puntos suspensivos para evitar desbordamientos visuales.
     */
    private static function truncateText(string $text, int $maxLength): string
    {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 1, 'UTF-8') . '…';
    }
}
