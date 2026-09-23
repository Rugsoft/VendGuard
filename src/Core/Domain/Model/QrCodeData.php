<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;

/**
 * QrCodeData
 * 
 * Value Object inmutable que encapsula los datos esenciales codificados en un código QR.
 * Garantiza que el código de máquina, el código de sede y la URL base cumplan con
 * las reglas de integridad y normalización definidas en los contratos técnicos.
 * 
 * Cumple con Dogma Vanilla y el Artículo IV de la Constitución.
 */
class QrCodeData implements JsonSerializable
{
    private string $machineCode;
    private string $siteCode;
    private string $baseUrl;

    /**
     * @param string $machineCode Código único de la máquina (ej: 'VEND-0101').
     * @param string $siteCode Código de la sede asociada (ej: 'SEDE-BCN-01').
     * @param string $baseUrl URL base del sistema (ej: 'https://vendguard.onrender.com' o '/').
     * @throws InvalidArgumentException Si alguno de los parámetros requeridos está vacío.
     */
    public function __construct(string $machineCode, string $siteCode, string $baseUrl = '/')
    {
        $normalizedMachine = strtoupper(trim($machineCode));
        if ($normalizedMachine === '') {
            throw new InvalidArgumentException('El código de máquina no puede estar vacío.');
        }

        $normalizedSite = strtoupper(trim($siteCode));
        if ($normalizedSite === '') {
            throw new InvalidArgumentException('El código de sede no puede estar vacío.');
        }

        $normalizedBase = rtrim(trim($baseUrl), '/');
        if ($normalizedBase === '') {
            $normalizedBase = '/';
        }

        $this->machineCode = $normalizedMachine;
        $this->siteCode = $normalizedSite;
        $this->baseUrl = $normalizedBase;
    }

    public function getMachineCode(): string
    {
        return $this->machineCode;
    }

    public function getSiteCode(): string
    {
        return $this->siteCode;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Genera la URL normalizada que se codificará en la matriz física del código QR.
     * Estructura: {baseUrl}/?qr={machineCode}&site={siteCode}
     */
    public function getTargetUrl(): string
    {
        $prefix = $this->baseUrl === '/' ? '' : $this->baseUrl;
        return "{$prefix}/?qr={$this->machineCode}&site={$this->siteCode}";
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'machine_code' => $this->machineCode,
            'site_code' => $this->siteCode,
            'base_url' => $this->baseUrl,
            'target_url' => $this->getTargetUrl(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
