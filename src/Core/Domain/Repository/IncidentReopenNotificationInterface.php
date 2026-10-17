<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\Incident;

/**
 * IncidentReopenNotificationInterface
 *
 * Capacidad opcional del repositorio de incidencias que el Portal de Sede necesita
 * para que las alertas de reapertura (RF-05.7) y de Avería Crónica (RF-05.7) se
 * encolen DENTRO de la transacción que sella el cambio de estado.
 *
 * **Por qué un contrato de capacidad y no un cambio de firma.** La operación
 * pública `reopen()` ya está publicada y tienen dobles de prueba decenas de
 * suites; ampliarla con callbacks habría obligado a reescribir cada doble sin
 * aportar nada a su escenario. El portal comprueba `instanceof` de esta
 * interfaz —implementada por `PdoIncidentRepository`— y usa la variante
 * transaccional cuando existe; con un doble que no la implementa, la reapertura
 * conserva exactamente su contrato anterior.
 *
 * Los callbacks se ejecutan ANTES del commit: si el encolado falla, la marca de
 * Avería Crónica y la propia reapertura se revierten con él, y si la transacción
 * se confirma, las filas de la cola son tan durables como el estado que las
 * justifica (RF-01.2).
 */
interface IncidentReopenNotificationInterface
{
    /**
     * Reabre una incidencia en garantía ejecutando, dentro de su transacción,
     * los ganchos de notificación indicados.
     *
     * @param int $incidentId Expediente a reabrir.
     * @param string $reasonText Motivo descriptivo de la reapertura.
     * @param callable(Incident): void|null $onReopened Gancho con el expediente ya reabierto.
     * @param callable(Incident): void|null $onChronic Gancho con el expediente marcado como crónico.
     *
     * @return Incident Expediente reabierto (o excepción `ChronicIncidentException`).
     */
    public function reopenWithLifecycleNotifications(
        int $incidentId,
        string $reasonText,
        ?callable $onReopened = null,
        ?callable $onChronic = null
    ): Incident;
}
