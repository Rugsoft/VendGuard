<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

/**
 * AppPublicUrl — Host público canónico de los enlaces que salen del sistema.
 *
 * **El defecto que corrige.** Los enlaces de seguimiento de los correos se
 * construían como rutas relativas (`/?qr=VEND-0101`, `/?site=SEDE-BCN-01&ticket=…`).
 * Una ruta relativa es correcta dentro de una página web, pero un cliente de
 * correo no tiene «nuestra» página como base: al pulsar el botón resuelve el
 * enlace contra el dominio del proveedor de correo (por ejemplo
 * `https://mail.google.com/?qr=VEND-0101`) y aterriza en una web ajena que no
 * conoce el expediente. El enlace tiene que viajar **absoluto** desde que sale
 * del sistema.
 *
 * **Precedencia del host** (la misma que ratifica el contrato técnico de QR,
 * `specs/technical/qr_codes_contracts.md` §1.2):
 *   1. Variable de entorno `APP_BASE_URL` (o `VENDGUARD_APP_URL`, el nombre que
 *      usan los flujos de despliegue), si está definida y es un origen
 *      `http(s)` válido. Es el mecanismo previsto para el despliegue.
 *   2. Host efectivo de la petición, cuando quien compone el enlace es un
 *      proceso HTTP (el endpoint de despacho del planificador).
 *   3. Sin ninguno de los dos —worker CLI sin variable— no se inventa un
 *      dominio: el enlace se entrega tal cual. Nunca se embebe un literal del
 *      despliegue en el código.
 *
 * **Seguridad.** Sólo se aceptan orígenes `http`/`https`; cualquier otro
 * esquema (`javascript:`, `data:`, `file:`…) se descarta y el enlace conserva
 * su forma relativa, de modo que una variable de entorno mal escrita no puede
 * convertir el botón del correo en un vector de ejecución.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class AppPublicUrl
{
    /** Variable de entorno con el origen público canónico del despliegue. */
    public const ENV_NAME = 'APP_BASE_URL';

    /**
     * Nombres admitidos, en orden de precedencia. `VENDGUARD_APP_URL` es el que
     * ya usan los flujos de GitHub Actions como URL pública del despliegue.
     */
    private const ENV_NAMES = [self::ENV_NAME, 'VENDGUARD_APP_URL'];

    /** Origen normalizado (sin barra final) o `null` si no hay host utilizable. */
    private readonly ?string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = self::normalize($baseUrl);
    }

    /**
     * Host declarado en el entorno del proceso (`APP_BASE_URL`), si lo hay.
     */
    public static function fromEnvironment(): self
    {
        return new self(self::readEnvironment());
    }

    /**
     * Origen para una petición HTTP: `APP_BASE_URL` si está declarada y, si no,
     * el host efectivo de la petición.
     *
     * Es la resolución que necesitan los procesos que emiten enlaces sin
     * configurar nada —el cron del planificador y el panel de supervisión—, de
     * modo que la URL que se enseña en la bandeja y la que viaja en el correo
     * salen de la misma precedencia.
     */
    public static function fromHttpOrigin(string $scheme, string $host): self
    {
        $configured = self::fromEnvironment();
        if ($configured->isConfigured()) {
            return $configured;
        }

        $host = trim($host);
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
            return $configured;
        }

        return new self((strtolower(trim($scheme)) === 'https' ? 'https' : 'http') . '://' . $host);
    }

    /**
     * Origen normalizado que se aplicará a los enlaces relativos (`null` si no hay).
     */
    public function baseUrl(): ?string
    {
        return $this->baseUrl;
    }

    /**
     * ¿Hay un host público con el que construir enlaces absolutos?
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== null;
    }

    /**
     * Convierte un enlace en absoluto cuando es una ruta del propio sistema.
     *
     * - Los enlaces `http(s)://…` y `mailto:…` se devuelven intactos.
     * - Las rutas (`/`, `/?qr=…`, sin barra inicial) se prefijan con el host.
     * - Cualquier otro esquema (`javascript:`, `data:`, `file:`…) se descarta: un
     *   botón de correo no tiene por qué transportarlo y publicarlo sería
     *   convertir un dato del expediente en ejecución.
     * - Sin host configurado, la ruta se devuelve tal cual: la degradación es
     *   visible en la bandeja y arreglarla es definir `APP_BASE_URL`, no cambiar
     *   el código.
     */
    public function absolute(?string $link): ?string
    {
        if ($link === null) {
            return null;
        }

        $value = trim($link);
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1 || preg_match('#^mailto:#i', $value) === 1) {
            return $value;
        }

        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $value) === 1) {
            return null;
        }

        if ($this->baseUrl === null) {
            return $value;
        }

        // Enlace relativo al esquema (`//host/ruta`): se le presta el esquema del host.
        if (str_starts_with($value, '//')) {
            $scheme = str_contains($this->baseUrl, '://')
                ? substr($this->baseUrl, 0, (int)strpos($this->baseUrl, '://'))
                : 'https';

            return $scheme . ':' . $value;
        }

        return $this->baseUrl . '/' . ltrim($value, '/');
    }

    /**
     * Lectura de `APP_BASE_URL` en todas las fuentes habituales de PHP.
     */
    private static function readEnvironment(): ?string
    {
        foreach (self::ENV_NAMES as $name) {
            $value = getenv($name);
            if ($value === false) {
                $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
            }

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Normaliza un origen `http(s)://host[:puerto][/ruta]` sin barra final.
     *
     * Devuelve `null` ante cualquier candidato vacío, con espacios internos o
     * con un esquema distinto de `http`/`https`.
     */
    private static function normalize(?string $candidate): ?string
    {
        if ($candidate === null) {
            return null;
        }

        $value = trim($candidate);
        if ($value === '' || preg_match('/\s/', $value) === 1) {
            return null;
        }

        $host = '(?:\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.\-]+)(?::\d{1,5})?';
        if (preg_match('#^https?://' . $host . '(?:/[^\s]*)?$#i', $value) !== 1) {
            return null;
        }

        return rtrim($value, '/');
    }
}
