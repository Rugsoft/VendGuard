<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable physical stop consolidating all assigned tasks at one location.
 */
final readonly class RouteStop implements JsonSerializable
{
    /**
     * @param array<array<string, mixed>> $tasks
     */
    public function __construct(
        private int $order,
        private Location $location,
        private StopStatus $status,
        private StopPriority $priority,
        private bool $isCritical,
        private int $totalTasks,
        private int $completedTasks,
        private array $tasks,
        private float $distanceFromPreviousKm,
        private string $navigationUrl
    ) {
        if ($order < 1) {
            throw new InvalidArgumentException('El orden de la parada debe ser mayor que cero.');
        }

        if ($totalTasks < 1 || $completedTasks < 0 || $completedTasks > $totalTasks) {
            throw new InvalidArgumentException('El progreso de tareas de la parada no es válido.');
        }

        if (!array_is_list($tasks) || count($tasks) !== $totalTasks) {
            throw new InvalidArgumentException('La lista de tareas debe coincidir con el total de la parada.');
        }

        foreach ($tasks as $task) {
            if (!is_array($task) || $task === []) {
                throw new InvalidArgumentException('Cada tarea de la parada debe contener sus datos.');
            }
        }

        if (($status === StopStatus::COMPLETED) !== ($completedTasks === $totalTasks)) {
            throw new InvalidArgumentException('El estado completado debe coincidir con el progreso total.');
        }

        if ($isCritical !== ($priority === StopPriority::CRITICAL)) {
            throw new InvalidArgumentException('La criticidad debe coincidir con la prioridad heredada.');
        }

        if (!is_finite($distanceFromPreviousKm) || $distanceFromPreviousKm < 0.0) {
            throw new InvalidArgumentException('La distancia desde la parada anterior no puede ser negativa o no finita.');
        }

        if (trim($navigationUrl) === '') {
            throw new InvalidArgumentException('La parada requiere una URL de navegación.');
        }
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function getLocation(): Location
    {
        return $this->location;
    }

    public function getStatus(): StopStatus
    {
        return $this->status;
    }

    public function getPriority(): StopPriority
    {
        return $this->priority;
    }

    public function isCritical(): bool
    {
        return $this->isCritical;
    }

    public function getTotalTasks(): int
    {
        return $this->totalTasks;
    }

    public function getCompletedTasks(): int
    {
        return $this->completedTasks;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    public function getProgressRatio(): float
    {
        return $this->completedTasks / $this->totalTasks;
    }

    public function getDistanceFromPreviousKm(): float
    {
        return $this->distanceFromPreviousKm;
    }

    public function getNavigationUrl(): string
    {
        return $this->navigationUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'location' => $this->location->toArray(),
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'is_critical' => $this->isCritical,
            'total_tasks' => $this->totalTasks,
            'completed_tasks' => $this->completedTasks,
            'tasks' => $this->tasks,
            'distance_from_previous_km' => $this->distanceFromPreviousKm,
            'navigation_url' => $this->navigationUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
