<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Service;

use VendGuard\Core\Service\IncidentStateMachine as CoreIncidentStateMachine;

/**
 * IncidentStateMachine (Domain Service Alias)
 * 
 * Permite el acceso transparente tanto desde VendGuard\Core\Service
 * como desde VendGuard\Core\Domain\Service.
 */
class_alias(CoreIncidentStateMachine::class, 'VendGuard\Core\Domain\Service\IncidentStateMachine');
