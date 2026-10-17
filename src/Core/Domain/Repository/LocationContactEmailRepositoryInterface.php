<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * LocationContactEmailRepositoryInterface
 *
 * Capacidad de la ficha maestra de Sede que el Centro de Notificaciones necesita
 * (RF-02.1): el buzón institucional al que viajan los avisos del expediente.
 *
 * Vive como contrato separado —igual que `LocationBatchRepositoryInterface`—
 * para que las implementaciones que no participan del módulo 14 no tengan que
 * declarar métodos que no usan.
 */
interface LocationContactEmailRepositoryInterface
{
    /**
     * Actualiza el correo oficial de contacto de una sede.
     *
     * @param int $id Identificador de la sede.
     * @param string $contactEmail Dirección válida del buzón institucional.
     * @return bool True cuando la fila se actualizó.
     */
    public function updateContactEmail(int $id, string $contactEmail): bool;
}
