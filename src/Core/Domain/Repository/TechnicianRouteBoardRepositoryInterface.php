<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\Machine;

/**
 * TechnicianRouteBoardRepositoryInterface
 *
 * Puerto de dominio del **tablero de ruta** del técnico: la bandeja de paradas del día
 * (`GET /api/technician/my-route`) leída en **una sola consulta**, con todo lo que cada tarjeta
 * publica —el expediente, su máquina, su sede, los ajustes preventivos de la puerta sanitaria, el
 * recuento de conversación y la marca de respuesta de la sede— resuelto en el mismo viaje.
 *
 * POR QUÉ EXISTE (Módulo 12 · T-HYBRID-27 · RNF-01, medido)
 *
 *   La bandeja pagaba, contra el motor remoto, **seis idas y vueltas por petición**: la
 *   autenticación, los expedientes asignados, el recuento de conversaciones y una lectura por
 *   familia de datos (máquina, sede, ajustes preventivos). Los N+1 por parada se curaron antes
 *   —recuento agregado y lecturas por lote, 2.662,1 ms → 1.739,3 ms → 284,0 ms de mediana—, pero
 *   el presupuesto de RNF-01 (250 ms) admite **5,7 vueltas** con el enlace medido (43,9 ms): las
 *   cinco lecturas fijas del controlador no caben por muy baratas que sean, porque no dejan de ser
 *   cinco viajes. La única forma de que la bandeja entre en el presupuesto es que deje de ser una
 *   composición de lecturas y pase a ser **un tablero**: una consulta, un viaje.
 *
 *   El patrón ya está bendecido en el proyecto: `PdoIncidentRepository::findEnrichedDetailById()`
 *   resuelve «toda la ficha del expediente en una sola ida y vuelta» para el modal del
 *   coordinador, y `PdoMetricsRepository` calcula los KPI en SQL. Un tablero de lectura es lo
 *   mismo aplicado a la bandeja móvil.
 *
 * EXISTE COMO CONTRATO PROPIO, CON RESERVA DECLARADA
 *
 *   Igual que `IncidentSiteResponseRepositoryInterface` y las capacidades de lote, vive en su
 *   propio contrato para no forzar a los dobles de prueba ni a otros consumidores del repositorio
 *   de expedientes a implementar una lectura que sólo usa la ruta. `TechnicianController` la
 *   aprovecha **si el repositorio la declara** y, si no, compone la parada con las lecturas
 *   ordinarias (por lote cuando existen), publicando exactamente el mismo payload.
 *
 * LA REGLA DEL DOMINIO NO SE DUPLICA AQUÍ
 *
 *   El tablero no decide nada: entrega los **hechos** que el controlador y el servicio de pausa ya
 *   saben interpretar. En particular devuelve los ajustes preventivos en crudo —no un veredicto—
 *   porque la puerta sanitaria del cierre (`IncidentPauseService::requirementFromSettings()`) sigue
 *   siendo la única definición de cuándo muerde el Art. II.
 *
 * La lectura es pura y de solo consulta (Art. III): no escribe, no transiciona y no audita nada.
 */
interface TechnicianRouteBoardRepositoryInterface
{
    /**
     * Tablero de paradas asignadas a un técnico, en una sola ida y vuelta.
     *
     * El orden es el de la ruta (criticidad y antigüedad), igual que el de
     * `findAssignedToTechnician()`, y sólo aparecen los expedientes no borrados cuyo estado está
     * entre los pedidos. Una parada cuya máquina o sede no exista se entrega igualmente con el
     * lado ausente en `null`: la tarjeta publica entonces los datos denormalizados del expediente,
     * que es la degradación ya declarada de la ruta.
     *
     * @param int $technicianId Técnico responsable cuyas paradas se leen.
     * @param list<string> $statuses Estados de ruta admitidos (los del listado ordinario).
     * @return array<int, array{
     *     incident: Incident,
     *     machine: Machine|null,
     *     location: Location|null,
     *     settings: array<string, mixed>|null,
     *     comments_total: int,
     *     site_response_since_pause: bool
     * }> Lista ordenada de paradas.
     */
    public function findRouteBoard(int $technicianId, array $statuses = []): array;
}
