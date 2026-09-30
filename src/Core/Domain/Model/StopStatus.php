<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * Lifecycle states for a consolidated technician route stop.
 */
enum StopStatus: string
{
    case IN_PROGRESS = 'IN_PROGRESS';
    case PENDING = 'PENDING';
    case COMPLETED = 'COMPLETED';
}
