<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * IncidentWorkshopNotesRepositoryInterface
 *
 * Capacidad de lectura de la **última Nota Interna de Taller** de un conjunto de
 * expedientes, en una sola ida y vuelta.
 *
 * POR QUÉ EXISTE (Módulo 36, RF-05.2.2 · RNF-01)
 *
 *   La tarjeta de «Mi Ruta» del técnico SAT debe destacar la Nota Interna y la foto
 *   con las que un reponedor escaló la avería. Resolverlo con `getComments()` por
 *   parada repetiría el patrón N+1 que T-HYBRID-27 midió y curó; por eso la lectura
 *   llega agregada: una consulta devuelve la última nota interna de cada expediente
 *   y el controlador la inyecta en el payload de la parada.
 *
 *   Vive en su propio contrato —igual que el tablero de ruta y las capacidades de
 *   lote— para no forzar a los dobles de prueba ni a otros consumidores del
 *   repositorio de expedientes a implementar una lectura que sólo usa la ruta. Si el
 *   repositorio no la declara, el payload publica `null` y no hay lectura individual.
 *
 * PRIVACIDAD (Art. V.4)
 *
 *   Sólo devuelve notas con `is_internal = TRUE`. El consumidor legítimo es el
 *   técnico SAT destinatario del escalado; el Portal de Sede, el canal QR y los
 *   albaranes A4 no usan esta capacidad.
 */
interface IncidentWorkshopNotesRepositoryInterface
{
    /**
     * Última nota interna de cada expediente pedido.
     *
     * «Última» significa la de mayor identificador de inserción dentro del hilo
     * interno, que es el orden en que el servidor las escribió. Los expedientes sin
     * nota interna no aparecen en el mapa: el consumidor publica `null`, nunca un
     * valor inventado.
     *
     * @param list<int> $incidentIds Identificadores de los expedientes de la ruta.
     * @return array<int, array{
     *     note: string,
     *     author_name: string,
     *     photo_path: string|null,
     *     created_at: string|null
     * }> Mapa `id de incidencia => última nota interna`.
     */
    public function findLastInternalNotesForIncidents(array $incidentIds): array;
}
