<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Routing;

use Closure;
use DomainException;
use Throwable;
use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Exception\InvalidResolutionException;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * Router
 * 
 * Enrutador frontal nativo y despachador de peticiones para la API REST.
 * Soporta métodos HTTP (GET, POST, PATCH, PUT, DELETE, OPTIONS), captura de parámetros
 * en URL (ej: /api/locations/{code}/machines) y cadena de middlewares.
 * 
 * Respeta el Dogma Vanilla y la arquitectura API-First (sin paquetes de terceros).
 */
class Router
{
    /** @var array<string, list<array{pattern: string, regex: string, paramNames: list<string>, handler: callable|array, middlewares: list<mixed>}>> */
    private array $routes = [
        'GET' => [],
        'POST' => [],
        'PATCH' => [],
        'PUT' => [],
        'DELETE' => [],
        'OPTIONS' => [],
    ];

    /** @var list<mixed> */
    private array $globalMiddlewares = [];

    /**
     * Registra un middleware global que se ejecutará en todas las peticiones.
     */
    public function use(mixed $middleware): self
    {
        $this->globalMiddlewares[] = $middleware;
        return $this;
    }

    /**
     * Registra una ruta para el método GET.
     */
    public function get(string $pattern, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('GET', $pattern, $handler, $middlewares);
    }

    /**
     * Registra una ruta para el método POST.
     */
    public function post(string $pattern, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('POST', $pattern, $handler, $middlewares);
    }

    /**
     * Registra una ruta para el método PATCH.
     */
    public function patch(string $pattern, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('PATCH', $pattern, $handler, $middlewares);
    }

    /**
     * Registra una ruta para el método PUT.
     */
    public function put(string $pattern, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('PUT', $pattern, $handler, $middlewares);
    }

    /**
     * Registra una ruta para el método DELETE.
     */
    public function delete(string $pattern, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('DELETE', $pattern, $handler, $middlewares);
    }

    /**
     * Registra una ruta para cualquier método especificado.
     */
    public function addRoute(string $method, string $pattern, callable|array $handler, array $middlewares = []): self
    {
        $method = strtoupper(trim($method));
        $normalizedPattern = '/' . trim($pattern, '/');
        if ($normalizedPattern !== '/') {
            $normalizedPattern = '/' . trim($normalizedPattern, '/');
        }

        // Compilar el patrón a una expresión regular con captura de nombres
        // Soporta {paramName} donde paramName puede ser alfanumérico o guion
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            return '(?P<' . $matches[1] . '>[^/]+)';
        }, $normalizedPattern);

        $regex = '#^' . $regex . '$#u';

        $this->routes[$method][] = [
            'pattern' => $normalizedPattern,
            'regex' => $regex,
            'paramNames' => $paramNames,
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];

        return $this;
    }

    /**
     * Despacha una petición entrante hacia la ruta y controlador correspondientes.
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        // Soporte de solicitudes preliminares CORS (OPTIONS preflight)
        if ($method === 'OPTIONS') {
            return Response::noContent([
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PATCH, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            ]);
        }

        // 1. Buscar coincidencia exacta de método y ruta
        $matchingRoute = null;
        $matchedParams = [];

        if (isset($this->routes[$method])) {
            foreach ($this->routes[$method] as $route) {
                if (preg_match($route['regex'], $path, $matches)) {
                    $matchingRoute = $route;
                    foreach ($route['paramNames'] as $paramName) {
                        if (isset($matches[$paramName])) {
                            $matchedParams[$paramName] = urldecode((string)$matches[$paramName]);
                        }
                    }
                    break;
                }
            }
        }

        // 2. Si no coincide el método pero la ruta existe en otro método -> 405 Method Not Allowed
        if ($matchingRoute === null) {
            $allowedMethods = [];
            foreach ($this->routes as $m => $routeList) {
                if ($m === $method) {
                    continue;
                }
                foreach ($routeList as $route) {
                    if (preg_match($route['regex'], $path)) {
                        $allowedMethods[] = $m;
                        break;
                    }
                }
            }

            if (!empty($allowedMethods)) {
                return Response::error(
                    'METHOD_NOT_ALLOWED',
                    "Método {$method} no permitido para la ruta '{$path}'. Métodos permitidos: " . implode(', ', $allowedMethods),
                    405,
                    ['allowed_methods' => $allowedMethods],
                    ['Allow' => implode(', ', $allowedMethods)]
                );
            }

            // 3. Ninguna coincidencia -> 404 Not Found
            return Response::error(
                'ROUTE_NOT_FOUND',
                "La ruta solicitada '{$path}' no existe en la API de VendGuard.",
                404,
                ['path' => $path, 'method' => $method]
            );
        }

        // Inyectar los parámetros de ruta en la petición
        $request->setRouteParams($matchedParams);

        // Concatenar middlewares globales y específicos de la ruta
        $allMiddlewares = array_merge($this->globalMiddlewares, $matchingRoute['middlewares']);

        try {
            return $this->executePipeline($allMiddlewares, $matchingRoute['handler'], $request);
        } catch (DuplicateIncidentException $e) {
            return Response::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $e->getHttpStatusCode(),
                [
                    'ticket_code' => $e->getTicketCode(),
                    'ticket_status' => $e->getTicketStatus(),
                ]
            );
        } catch (InvalidTransitionException $e) {
            return Response::error(
                'INVALID_TRANSITION',
                $e->getMessage(),
                422,
                [
                    'from_status' => $e->getFromStatus()?->value,
                    'to_status' => $e->getToStatus()?->value,
                ]
            );
        } catch (InvalidResolutionException $e) {
            return Response::error(
                'INVALID_RESOLUTION',
                $e->getMessage(),
                422,
                ['field' => $e->getField()]
            );
        } catch (DomainException $e) {
            return Response::error(
                'DOMAIN_ERROR',
                $e->getMessage(),
                400
            );
        } catch (Throwable $e) {
            return Response::error(
                'INTERNAL_SERVER_ERROR',
                'Ha ocurrido un error interno no controlado en el servidor.',
                500,
                [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                ]
            );
        }
    }

    /**
     * Ejecuta la cadena de middlewares y finaliza en el controlador de la ruta.
     *
     * @param list<mixed> $middlewares
     * @param callable|array $handler
     * @param Request $request
     * @return Response
     */
    private function executePipeline(array $middlewares, callable|array $handler, Request $request): Response
    {
        $pipeline = array_reduce(
            array_reverse($middlewares),
            function (Closure $next, mixed $middleware) {
                return function (Request $req) use ($middleware, $next): Response {
                    if (is_callable($middleware)) {
                        return $middleware($req, $next);
                    }
                    if (is_object($middleware) && method_exists($middleware, 'handle')) {
                        return $middleware->handle($req, $next);
                    }
                    if (is_string($middleware) && class_exists($middleware)) {
                        $instance = new $middleware();
                        if (method_exists($instance, 'handle')) {
                            return $instance->handle($req, $next);
                        }
                    }
                    return $next($req);
                };
            },
            function (Request $req) use ($handler): Response {
                return $this->invokeHandler($handler, $req);
            }
        );

        return $pipeline($request);
    }

    /**
     * Invoca el manejador de la ruta (Closure, función o [Controller, 'metodo']).
     */
    private function invokeHandler(callable|array $handler, Request $request): Response
    {
        if (is_array($handler)) {
            [$controller, $method] = $handler;
            if (is_string($controller) && class_exists($controller)) {
                $controller = new $controller();
            }

            if (!is_object($controller) || !method_exists($controller, (string)$method)) {
                throw new DomainException("Controlador o método '{$method}' no encontrado.");
            }

            $response = $controller->$method($request);
        } else {
            $response = $handler($request);
        }

        if ($response instanceof Response) {
            return $response;
        }

        if (is_array($response)) {
            return Response::json($response);
        }

        return new Response((string)$response, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
