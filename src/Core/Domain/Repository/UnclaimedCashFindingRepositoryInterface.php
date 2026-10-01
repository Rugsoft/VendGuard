<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\UnclaimedCashFinding;

/**
 * Persistence contract for cash found stuck inside a machine with no prior
 * consumer claim (RF-REF-04).
 */
interface UnclaimedCashFindingRepositoryInterface
{
    /**
     * Persists an on-site finding and returns its generated identifier.
     */
    public function insert(UnclaimedCashFinding $finding): int;

    /**
     * Retrieves a finding by its identifier.
     */
    public function findById(int $id): ?UnclaimedCashFinding;

    /**
     * Lists the findings recorded under an incident.
     *
     * @return list<UnclaimedCashFinding>
     */
    public function findByIncident(int $incidentId): array;

    /**
     * Lists the findings recorded by a technician, most recent first.
     *
     * @return list<UnclaimedCashFinding>
     */
    public function findByTechnician(int $technicianId, int $limit = 50): array;
}
