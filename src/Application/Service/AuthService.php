<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\SiteAccessCodeRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserLockoutRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\Service\SiteAccessCodeGenerator;
use VendGuard\Infrastructure\Config\SecretProvider;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoSiteAccessCodeRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

/**
 * AuthService
 * 
 * Servicio de Aplicación responsable de la autenticación de sedes y personal interno,
 * emisión de tokens criptográficos firmados con HMAC-SHA256 (Dogma Vanilla, sin librerías externas)
 * y verificación de validez y caducidad.
 *
 * La clave de firma se resuelve por entorno a través de `SecretProvider` (hallazgo S-1): ya no
 * existe un literal utilizable en el código fuente. La inyección explícita por constructor
 * mantiene la prioridad para los tests y para un hipotético despliegue con gestor de secretos.
 */
class AuthService
{
    private const SITE_TOKEN_PREFIX = 'site_token_';
    private const AUTH_TOKEN_PREFIX = 'auth_token_';

    /**
     * Fallos consecutivos de clave de centro que activan el bloqueo temporal (S-4).
     * Coincide con el freno del acceso interno para no crear dos políticas distintas.
     */
    public const SITE_LOGIN_MAX_ATTEMPTS = 5;

    /** Duración del bloqueo temporal de una sede tras agotar los intentos: 15 minutos. */
    public const SITE_LOGIN_LOCK_SECONDS = 900;

    /**
     * Fallos consecutivos de contraseña que activan el bloqueo temporal de usuario interno (S-3).
     */
    public const INTERNAL_LOGIN_MAX_ATTEMPTS = 5;

    /** Duración del bloqueo temporal de usuario interno: 15 minutos (900s). */
    public const INTERNAL_LOGIN_LOCK_SECONDS = 900;

    private LocationRepositoryInterface $locationRepo;
    private UserRepositoryInterface $userRepo;
    private SiteAccessCodeRepositoryInterface $accessCodeRepo;
    private UserLockoutRepositoryInterface $userLockoutRepo;
    private string $secretKey;

    public function __construct(
        ?LocationRepositoryInterface $locationRepo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?string $secretKey = null,
        ?SiteAccessCodeRepositoryInterface $accessCodeRepo = null,
        ?UserLockoutRepositoryInterface $userLockoutRepo = null
    ) {
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->userRepo = $userRepo ?? new PdoUserRepository();
        $this->secretKey = $secretKey ?? SecretProvider::authSecret();
        $this->accessCodeRepo = $accessCodeRepo ?? new PdoSiteAccessCodeRepository();
        $this->userLockoutRepo = $userLockoutRepo ?? ($this->userRepo instanceof UserLockoutRepositoryInterface ? $this->userRepo : new PdoUserRepository());
    }

    /**
     * Autentica una sede con las dos credenciales: código identificador y clave de
     * centro (RF-01, EARS 1.1; hallazgo S-4 de la auditoría de arquitectura).
     *
     * El código de sede no es secreto (va impreso en la etiqueta QR), así que por sí
     * solo ya no abre ninguna puerta: el acceso exige además la clave entregada en
     * mano por coordinación. El fracaso es siempre genérico e idéntico —sede
     * inexistente, inactiva, sin clave, bloqueada o clave incorrecta— para no ofrecer
     * un oráculo de existencia de sedes (EARS 1.2).
     *
     * @param string $siteCode Código de sede (ej: SEDE-BCN-01).
     * @param string $accessCode Clave de centro en claro, tal y como la teclea el responsable.
     * @return array{token: string, location: Location}|null `null` en cualquier fallo de credenciales.
     */
    public function loginSite(string $siteCode, string $accessCode): ?array
    {
        $location = $this->locationRepo->findBySiteCode($siteCode, true);
        if ($location === null) {
            return null;
        }

        $state = $this->accessCodeRepo->findAccessState($location->getId());
        if ($state === null || $state['hash'] === null || $state['is_locked']) {
            return null;
        }

        $normalizedCode = SiteAccessCodeGenerator::normalize($accessCode);
        if ($normalizedCode === '' || !password_verify($normalizedCode, $state['hash'])) {
            // El fallo se contabiliza solo sobre sedes existentes: el freno protege la
            // sede real, no deja rastro de sedes inventadas (que no tienen fila).
            $this->accessCodeRepo->registerFailedLogin(
                $location->getId(),
                self::SITE_LOGIN_MAX_ATTEMPTS,
                self::SITE_LOGIN_LOCK_SECONDS
            );

            return null;
        }

        $this->accessCodeRepo->resetLoginAttempts($location->getId());

        return [
            'token' => $this->generateSiteToken($location),
            'location' => $location,
        ];
    }

    /**
     * Autentica un usuario interno por email y contraseña (RF-04), aplicando
     * freno de fuerza bruta y bloqueo temporal a nivel de cuenta (hallazgo S-3).
     *
     * @param string $email Correo del usuario.
     * @param string $plainPassword Contraseña en texto plano.
     * @return array{token: string, user: User}|null|'LOCKED' Devuelve array si es exitoso, 'LOCKED' si la cuenta está bloqueada, o null si las credenciales son inválidas.
     */
    public function loginInternal(string $email, string $plainPassword): array|string|null
    {
        $user = $this->userRepo->findByEmail($email);
        if ($user === null) {
            return null;
        }

        // Comprobar estado de bloqueo
        $lockout = $this->userLockoutRepo->findLoginLockoutState($user->getId());
        if ($lockout !== null && $lockout['is_locked']) {
            return 'LOCKED';
        }

        if (!$user->verifyPassword($plainPassword)) {
            $this->userLockoutRepo->registerFailedLogin(
                $user->getId(),
                self::INTERNAL_LOGIN_MAX_ATTEMPTS,
                self::INTERNAL_LOGIN_LOCK_SECONDS
            );

            // Si este intento activó el bloqueo, indicarlo
            $afterLockout = $this->userLockoutRepo->findLoginLockoutState($user->getId());
            if ($afterLockout !== null && $afterLockout['is_locked']) {
                return 'LOCKED';
            }

            return null;
        }

        // Login exitoso: restablecer intentos
        $this->userLockoutRepo->resetLoginAttempts($user->getId());

        $token = $this->generateInternalToken($user);

        return [
            'token' => $token,
            'user' => $user,
        ];
    }

    /**
     * Genera un token firmado para acceso de sede.
     *
     * El TTL por defecto son 24 horas: EARS 1.3 promete «24 horas» de sesión de centro y el
     * hallazgo V-6 de la auditoría detectó que la implementación emitía 7 días sin cobertura de
     * test. La ventana deslizante de actividad que sugiere el texto original queda como mejora
     * futura (exigiría reemitir el token); esta implementación es estrictamente más conservadora.
     */
    public function generateSiteToken(Location $location, int $ttlSeconds = 86400): string
    {
        $payload = [
            'type' => 'site',
            'location_id' => $location->getId(),
            'site_code' => $location->getSiteCode(),
            'exp' => time() + $ttlSeconds,
        ];

        return self::SITE_TOKEN_PREFIX . $this->signPayload($payload);
    }

    /**
     * Valida y decodifica un token de sede.
     *
     * @return array{type: string, location_id: int, site_code: string, exp: int}|null
     */
    public function validateSiteToken(string $token): ?array
    {
        if (!str_starts_with($token, self::SITE_TOKEN_PREFIX)) {
            return null;
        }

        $signedPart = substr($token, strlen(self::SITE_TOKEN_PREFIX));
        $payload = $this->verifySignedPayload($signedPart);

        if ($payload === null || ($payload['type'] ?? '') !== 'site') {
            return null;
        }

        return $payload;
    }

    /**
     * Genera un token firmado para acceso de personal interno (Coordinador / Técnico).
     */
    public function generateInternalToken(User $user, int $ttlSeconds = 86400): string
    {
        $payload = [
            'type' => 'internal',
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'role' => $user->getRole()->value,
            'exp' => time() + $ttlSeconds,
        ];

        return self::AUTH_TOKEN_PREFIX . $this->signPayload($payload);
    }

    /**
     * Valida y decodifica un token interno.
     *
     * @return array{type: string, user_id: int, email: string, role: string, exp: int}|null
     */
    public function validateInternalToken(string $token): ?array
    {
        if (!str_starts_with($token, self::AUTH_TOKEN_PREFIX)) {
            return null;
        }

        $signedPart = substr($token, strlen(self::AUTH_TOKEN_PREFIX));
        $payload = $this->verifySignedPayload($signedPart);

        if ($payload === null || ($payload['type'] ?? '') !== 'internal') {
            return null;
        }

        return $payload;
    }

    /**
     * Firma un array asociativo con Base64Url y HMAC-SHA256.
     *
     * @param array<string, mixed> $payload
     */
    private function signPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $encoded = $this->base64UrlEncode($json !== false ? $json : '{}');
        $signature = hash_hmac('sha256', $encoded, $this->secretKey);

        return $encoded . '.' . $signature;
    }

    /**
     * Verifica la firma y comprueba la expiración temporal.
     *
     * @return array<string, mixed>|null
     */
    private function verifySignedPayload(string $signedPayload): ?array
    {
        $parts = explode('.', $signedPayload);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        $expectedSignature = hash_hmac('sha256', $encoded, $this->secretKey);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $json = $this->base64UrlDecode($encoded);
        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }

        // Comprobación de expiración temporal
        if (isset($payload['exp']) && is_int($payload['exp']) && time() > $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded !== false ? $decoded : null;
    }
}
