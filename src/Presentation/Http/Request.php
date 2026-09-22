<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Http;

/**
 * Request
 * 
 * Abstracción HTTP nativa para la captura y análisis de peticiones en la API REST.
 * Procesa métodos HTTP, rutas, cabeceras normalizadas, tokens Bearer, cuerpo JSON y archivos adjuntos.
 * 
 * Respeta el Dogma Vanilla y la arquitectura API-First (sin paquetes externos).
 */
class Request
{
    private string $method;
    private string $path;
    /** @var array<string, mixed> */
    private array $queryParams;
    /** @var array<string, mixed> */
    private array $parsedBody;
    /** @var array<string, string> */
    private array $headers;
    /** @var array<string, mixed> */
    private array $files;
    /** @var array<string, string> */
    private array $routeParams = [];
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param string $method Método HTTP (GET, POST, PATCH, etc.)
     * @param string $path Ruta solicitada (ej: /api/locations)
     * @param array<string, mixed> $queryParams Parámetros de query string
     * @param array<string, mixed> $parsedBody Parámetros del cuerpo deserializado
     * @param array<string, string> $headers Cabeceras HTTP
     * @param array<string, mixed> $files Archivos subidos ($_FILES)
     */
    public function __construct(
        string $method = 'GET',
        string $path = '/',
        array $queryParams = [],
        array $parsedBody = [],
        array $headers = [],
        array $files = []
    ) {
        $this->method = strtoupper(trim($method));
        $this->path = '/' . trim(parse_url($path, PHP_URL_PATH) ?? '/', '/');
        if ($this->path !== '/') {
            $this->path = '/' . trim($this->path, '/');
        }
        $this->queryParams = $queryParams;
        $this->parsedBody = $parsedBody;
        $this->files = $files;

        // Normalizar cabeceras a minúsculas para acceso insensible a mayúsculas
        $this->headers = [];
        foreach ($headers as $key => $value) {
            $this->headers[strtolower(trim($key))] = trim((string)$value);
        }
    }

    /**
     * Construye una instancia de Request a partir de las superglobales de PHP.
     */
    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // Capturar cabeceras de servidor
        $headers = [];
        if (function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                $headers = $all;
            }
        } else {
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $headerName = str_replace('_', '-', substr($key, 5));
                    $headers[$headerName] = (string)$value;
                } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                    $headerName = str_replace('_', '-', $key);
                    $headers[$headerName] = (string)$value;
                }
            }
        }

        // Si Authorization no vino en getallheaders, verificar $_SERVER
        if (!isset($headers['Authorization']) && !isset($headers['authorization'])) {
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = (string)$_SERVER['HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['Authorization'] = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }
        }

        // Parsear cuerpo de la petición
        $parsedBody = [];
        $contentType = '';
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'Content-Type') === 0) {
                $contentType = (string)$v;
                break;
            }
        }
        if ($contentType === '' && isset($_SERVER['CONTENT_TYPE'])) {
            $contentType = (string)$_SERVER['CONTENT_TYPE'];
        }

        $rawInput = file_get_contents('php://input');
        if (str_contains(strtolower($contentType), 'application/json') || (empty($_POST) && $rawInput !== false && (str_starts_with(trim($rawInput), '{') || str_starts_with(trim($rawInput), '[')))) {
            if ($rawInput !== false && trim($rawInput) !== '') {
                $decoded = json_decode($rawInput, true);
                if (is_array($decoded)) {
                    $parsedBody = $decoded;
                }
            }
        } else {
            $parsedBody = $_POST;
        }

        return new self(
            $method,
            $path,
            $_GET,
            $parsedBody,
            $headers,
            $_FILES
        );
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return strtoupper($this->method) === strtoupper(trim($method));
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParsedBody(): array
    {
        return $this->parsedBody;
    }

    public function getBodyParam(string $key, mixed $default = null): mixed
    {
        return $this->parsedBody[$key] ?? $default;
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
        $normalized = strtolower(trim($name));
        return $this->headers[$normalized] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        $normalized = strtolower(trim($name));
        return isset($this->headers[$normalized]);
    }

    /**
     * Extrae el token Bearer de la cabecera Authorization si está presente.
     */
    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('Authorization');
        if ($auth === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFile(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    public function getRouteParam(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * @param array<string, string> $params
     */
    public function setRouteParams(array $params): self
    {
        $this->routeParams = $params;
        return $this;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }
}
