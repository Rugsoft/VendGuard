<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use stdClass;

/**
 * OfflineBootstrapSnapshotDto
 *
 * Paquete consolidado que el técnico descarga en una sola llamada para poblar
 * `route_snapshots` en `IndexedDB` (Módulo 24, RF-02.1, contrato §6.1).
 *
 * Tres decisiones que este DTO hace explícitas:
 *
 *  - **La identidad no viene del paquete.** El repositorio entrega sólo el
 *    contenido operativo; el técnico y su correo los aporta el caso de uso que
 *    ya autenticó la petición. Así el paquete no puede mezclar datos de dos
 *    cuentas en un dispositivo compartido (Art. V.4).
 *  - **Los relojes de vigencia son del contrato, no del cliente.** Las 4 horas
 *    de aviso ámbar y las 12 horas de paso a solo lectura (`D6`) viajan como
 *    constantes de servidor (`warn_after_seconds` / `readonly_after_seconds`)
 *    para que la PWA no tenga que reimplementar la política ni pueda desviarse
 *    de ella.
 *  - **Los mapas se serializan como objetos.** `preventive_checklists_by_order`
 *    y `spare_parts_catalog_by_machine` son diccionarios indexados por
 *    identificador; un `[]` vacío los convertiría en listas y rompería el
 *    contrato. Vacíos se emiten como `{}` mediante `stdClass`.
 *
 * No incluye coste, precio ni importe alguno: la copia local opera sobre
 * unidades físicas y textos de trabajo (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, inmutable, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final readonly class OfflineBootstrapSnapshotDto implements JsonSerializable
{
    /** Aviso ámbar al superar las 4,0 horas sin refrescar (`D6`, `RF-02.3`). */
    public const WARN_AFTER_SECONDS = 14400;

    /** Paso a solo lectura al superar las 12,0 horas sin conexión (`D6`, `RF-02.4`). */
    public const READONLY_AFTER_SECONDS = 43200;

    /**
     * @param array{
     *   stops?: list<array<string, mixed>>,
     *   enabled_locations?: list<array<string, mixed>>,
     *   pending_critical_stops_other_locations?: list<array<string, mixed>>
     * } $route
     * @param list<array<string, mixed>> $preventiveOrders
     * @param array<int|string, array{order: array<string, mixed>, items: list<array<string, mixed>>}> $preventiveChecklistsByOrder
     * @param array<int|string, array{compatible_parts: list<array<string, mixed>>}> $sparePartsCatalogByMachine
     * @param array{
     *   items?: list<array<string, mixed>>,
     *   active_dispatched_order?: array<string, mixed>|null,
     *   last_confirmed_order?: array<string, mixed>|null
     * } $vanStock
     */
    public DateTimeImmutable $syncedAt;

    public function __construct(
        public int $technicianId,
        public string $technicianName,
        public string $technicianEmail,
        DateTimeImmutable $syncedAt,
        public array $route,
        public array $preventiveOrders,
        public array $preventiveChecklistsByOrder,
        public array $sparePartsCatalogByMachine,
        public array $vanStock
    ) {
        if ($technicianId <= 0) {
            throw new InvalidArgumentException('El paquete de ruta exige un técnico autenticado.');
        }

        if (trim($technicianName) === '') {
            throw new InvalidArgumentException('El paquete de ruta exige el nombre del técnico titular.');
        }

        $this->syncedAt = $syncedAt->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Instante a partir del cual la copia local muestra el aviso ámbar.
     */
    public function warnAfterAt(): DateTimeImmutable
    {
        return $this->syncedAt->modify('+' . self::WARN_AFTER_SECONDS . ' seconds');
    }

    /**
     * Instante a partir del cual la copia local bloquea nuevas acciones.
     */
    public function readonlyAfterAt(): DateTimeImmutable
    {
        return $this->syncedAt->modify('+' . self::READONLY_AFTER_SECONDS . ' seconds');
    }

    /**
     * Proyección del contrato §6.1.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'technician_id' => $this->technicianId,
            'technician_name' => $this->technicianName,
            'technician_email' => $this->technicianEmail,
            'synced_at' => $this->syncedAt->format('Y-m-d\TH:i:sP'),
            'warn_after_seconds' => self::WARN_AFTER_SECONDS,
            'readonly_after_seconds' => self::READONLY_AFTER_SECONDS,
            'route' => [
                'stops' => array_values($this->route['stops'] ?? []),
                'enabled_locations' => array_values($this->route['enabled_locations'] ?? []),
                'pending_critical_stops_other_locations' => array_values(
                    $this->route['pending_critical_stops_other_locations'] ?? []
                ),
            ],
            'preventive_orders' => array_values($this->preventiveOrders),
            'preventive_checklists_by_order' => $this->asJsonObject($this->preventiveChecklistsByOrder),
            'spare_parts_catalog_by_machine' => $this->asJsonObject($this->sparePartsCatalogByMachine),
            'van_stock' => [
                'items' => array_values($this->vanStock['items'] ?? []),
                'active_dispatched_order' => $this->vanStock['active_dispatched_order'] ?? null,
                'last_confirmed_order' => $this->vanStock['last_confirmed_order'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Un diccionario vacío debe viajar como `{}` y no como `[]`.
     *
     * @param array<int|string, mixed> $map
     * @return array<int|string, mixed>|stdClass
     */
    private function asJsonObject(array $map): array|stdClass
    {
        return $map === [] ? new stdClass() : $map;
    }
}
