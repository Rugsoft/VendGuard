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

    /**
     * Total cash already booked as unclaimed surplus for one machine.
     *
     * It exists for the aggregate ceiling of RF-REF-04: a per-finding limit can
     * be multiplied by repeating the intervention on the same machine, so the
     * rule needs to know what that machine has already absorbed.
     */
    public function sumAmountByMachine(int $machineId): float;
}
