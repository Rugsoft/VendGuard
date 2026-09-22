<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Core\Domain\Exception\DuplicateIncidentException;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\LocationRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

/**
 * AuthService
 * 
 * Servicio de Aplicación responsable de la autenticación de sedes y personal interno,
 * emisión de tokens criptográficos firmados con HMAC-SHA256 (Dogma Vanilla, sin librerías externas)
 * y verificación de validez y caducidad.
 */
class AuthService
{
    private const SECRET_KEY = 'vendguard-secret-key-change-in-production-rf04';
    private const SITE_TOKEN_PREFIX = 'site_token_';
    private const AUTH_TOKEN_PREFIX = 'auth_token_';

    private LocationRepositoryInterface $locationRepo;
    private UserRepositoryInterface $userRepo;
    private string $secretKey;

    public function __construct(
        ?LocationRepositoryInterface $locationRepo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?string $secretKey = null
    ) {
        $this->locationRepo = $locationRepo ?? new PdoLocationRepository();
        $this->userRepo = $userRepo ?? new PdoUserRepository();
        $this->secretKey = $secretKey ?? self::SECRET_KEY;
    }

    /**
     * Autentica una sede por su código identificador (RF-01).
     *
     * @param string $siteCode Código de sede (ej: SEDE-BCN-01).
     * @return array{token: string, location: Location}|null Devuelve null si no existe o está inactiva.
     */
    public function loginSite(string $siteCode): ?array
    {
        $location = $this->locationRepo->findBySiteCode($siteCode, true);
        if ($location === null) {
            return null;
        }

        $token = $this->generateSiteToken($location);

        return [
            'token' => $token,
            'location' => $location,
        ];
    }

    /**
     * Autentica un usuario interno por email y contraseña (RF-04).
     *
     * @param string $email Correo del usuario.
     * @param string $plainPassword Contraseña en texto plano.
     * @return array{token: string, user: User}|null Devuelve null si credenciales inválidas.
     */
    public function loginInternal(string $email, string $plainPassword): ?array
    {
        $user = $this->userRepo->findByEmail($email);
        if ($user === null) {
            return null;
        }

        if (!$user->verifyPassword($plainPassword)) {
            return null;
        }

        $token = $this->generateInternalToken($user);

        return [
            'token' => $token,
            'user' => $user,
        ];
    }

    /**
     * Genera un token firmado para acceso de sede.
     */
    public function generateSiteToken(Location $location, int $ttlSeconds = 604800): string
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
