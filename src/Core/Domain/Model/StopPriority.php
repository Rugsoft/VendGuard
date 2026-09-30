<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * Priority inherited by a consolidated route stop.
 */
enum StopPriority: string
{
    case CRITICAL = 'CRITICAL';
    case ORDINARY = 'ORDINARY';
}
