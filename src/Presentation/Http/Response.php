<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Http;

/**
 * Response
 * 
 * Abstracción de respuesta HTTP que implementa la Envolvente JSON estándar de VendGuard:
 * - Éxito: { "success": true, "data": ..., "message": ... }
 * - Error: { "success": false, "error": { "code": ..., "message": ..., "details": ... } }
 * 
 * Cumple con RNF-04, contratos API y el Dogma Vanilla.
 */
class Response
{
    private int $statusCode;
    /** @var array<string, string> */
    private array $headers;
    private string $body;

    public function __construct(string $body = '', int $statusCode = 200, array $headers = [])
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers;
    }

    /**
     * Factoría para respuestas JSON exitosas con el envelope estándar.
     *
     * @param mixed $data Datos de respuesta del recurso solicitado.
     * @param int $statusCode Código HTTP (por defecto 200 OK).
     * @param string|null $message Mensaje opcional explicativo de la operación.
     * @param array<string, string> $headers Cabeceras HTTP adicionales.
     * @return self
     */
    public static function json(
        mixed $data,
        int $statusCode = 200,
        ?string $message = null,
        array $headers = []
    ): self {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        $headers['Content-Type'] = 'application/json; charset=utf-8';

        $jsonEncoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new self($jsonEncoded !== false ? $jsonEncoded : '{}', $statusCode, $headers);
    }

    /**
     * Factoría para respuestas JSON de error estandarizadas.
     *
     * @param string $errorCode Código de error de dominio (ej: MACHINE_HAS_ACTIVE_INCIDENT).
     * @param string $message Mensaje explicativo para el usuario o cliente API.
     * @param int $statusCode Código HTTP del error (400, 401, 403, 404, 409, 422, 500).
     * @param mixed $details Detalles complementarios o mapa de validación de campos.
     * @param array<string, string> $headers Cabeceras HTTP adicionales.
     * @return self
     */
    public static function error(
        string $errorCode,
        string $message,
        int $statusCode = 400,
        mixed $details = null,
        array $headers = []
    ): self {
        $errorPayload = [
            'code' => $errorCode,
            'message' => $message,
        ];

        if ($details !== null) {
            $errorPayload['details'] = $details;
        }

        $payload = [
            'success' => false,
            'error' => $errorPayload,
        ];

        $headers['Content-Type'] = 'application/json; charset=utf-8';

        $jsonEncoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new self($jsonEncoded !== false ? $jsonEncoded : '{}', $statusCode, $headers);
    }

    /**
     * Factoría para respuesta 204 No Content.
     */
    public static function noContent(array $headers = []): self
    {
        return new self('', 204, $headers);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Helper para decodificar el cuerpo JSON (útil en tests).
     *
     * @return array<string, mixed>|null
     */
    public function getDecodedBody(): ?array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function withStatus(int $statusCode): self
    {
        $clone = clone $this;
        $clone->statusCode = $statusCode;
        return $clone;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    /**
     * Emite la respuesta HTTP enviando el código de estado, cabeceras y cuerpo.
     */
    public function send(): void
    {
        http_response_code($this->statusCode);

        if (!headers_sent()) {
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo $this->body;
    }
}
