<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification\Transport;

use InvalidArgumentException;
use VendGuard\Core\Domain\ValueObject\NotificationChannel;
use VendGuard\Infrastructure\Notification\Template\RenderedNotificationMessage;

/**
 * InMemoryNotificationTransport — Adaptador hermético determinista (T-NOTIF-05).
 *
 * Sustituye a los transportes nativos en la batería de pruebas y en cualquier
 * simulación local: **no abre sockets, no resuelve DNS y no depende del reloj**.
 * Devuelve exactamente los resultados que se le programan, en el orden en que
 * se le programan, y registra cada entrega para poder certificar:
 *
 *   - que el despachador invoca al transporte correcto con el destinatario
 *     esperado y el mensaje ya renderizado,
 *   - que la política de reintentos recibe un fallo transitorio y luego un
 *     éxito en el segundo intento,
 *   - que un rechazo permanente no consume reintentos.
 *
 * Es inyectable por constructor en los servicios de aplicación, igual que los
 * transportes nativos, porque todos comparten `NotificationTransportInterface`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class InMemoryNotificationTransport implements NotificationTransportInterface
{
    /** @var list<array{recipient: string, message: RenderedNotificationMessage}> */
    private array $deliveries = [];

    /** @var array<string, list<TransportDeliveryResult>> */
    private array $scriptedOutcomes = [];

    private TransportDeliveryResult $defaultOutcome;

    public function __construct(
        private readonly NotificationChannel $channel = NotificationChannel::EMAIL,
        ?TransportDeliveryResult $defaultOutcome = null,
    ) {
        $this->defaultOutcome = $defaultOutcome ?? TransportDeliveryResult::success();
    }

    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    /**
     * Programa una secuencia de resultados para un destinatario concreto.
     *
     * El primer `deliver()` consume el primer resultado, el segundo el segundo, y
     * así sucesivamente; agotada la secuencia se usa el resultado por defecto.
     */
    public function scriptOutcomes(string $recipient, TransportDeliveryResult ...$outcomes): void
    {
        $key = $this->recipientKey($recipient);
        $this->scriptedOutcomes[$key] = array_values($outcomes);
    }

    /**
     * Cambia el resultado devuelto cuando no queda secuencia programada.
     */
    public function setDefaultOutcome(TransportDeliveryResult $outcome): void
    {
        $this->defaultOutcome = $outcome;
    }

    public function deliver(string $recipient, RenderedNotificationMessage $message): TransportDeliveryResult
    {
        $this->deliveries[] = ['recipient' => $recipient, 'message' => $message];

        $key = $this->recipientKey($recipient);
        if (isset($this->scriptedOutcomes[$key]) && $this->scriptedOutcomes[$key] !== []) {
            return array_shift($this->scriptedOutcomes[$key]);
        }

        return $this->defaultOutcome;
    }

    /**
     * @return list<array{recipient: string, message: RenderedNotificationMessage}>
     */
    public function deliveries(): array
    {
        return $this->deliveries;
    }

    public function deliveryCount(): int
    {
        return count($this->deliveries);
    }

    /**
     * @return list<string>
     */
    public function deliveredRecipients(): array
    {
        return array_map(
            static fn (array $delivery): string => $delivery['recipient'],
            $this->deliveries
        );
    }

    /**
     * @return ?array{recipient: string, message: RenderedNotificationMessage}
     */
    public function lastDelivery(): ?array
    {
        return $this->deliveries === [] ? null : $this->deliveries[array_key_last($this->deliveries)];
    }

    /**
     * Vacía entregas y secuencias programadas para aislar escenarios.
     */
    public function reset(): void
    {
        $this->deliveries = [];
        $this->scriptedOutcomes = [];
        $this->defaultOutcome = TransportDeliveryResult::success();
    }

    private function recipientKey(string $recipient): string
    {
        $key = strtolower(trim($recipient));

        if ($key === '') {
            throw new InvalidArgumentException('El destinatario de la simulación no puede estar vacío.');
        }

        return $key;
    }
}
