<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\Service\AuthService;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * AuthController
 * 
 * Controlador REST para la autenticación de sedes (responsables de ubicación)
 * y personal interno (coordinadores y técnicos de campo).
 * 
 * Cumple con RF-01, RF-04 y contratos en specs/technical/api_contracts.md.
 */
class AuthController
{
    private AuthService $authService;

    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
    }

    /**
     * POST /api/auth/site-login
     * Acceso de responsables de centro con código de sede y clave de centro (RF-01, EARS 1.1).
     */
    public function siteLogin(Request $request): Response
    {
        $siteCode = $request->getBodyParam('site_code');
        $accessCode = $request->getBodyParam('access_code');

        if ($siteCode === null || trim((string)$siteCode) === '') {
            return Response::error(
                'MISSING_SITE_CREDENTIALS',
                'La petición debe incluir el código de sede y la clave de centro.',
                400
            );
        }

        if ($accessCode === null || trim((string)$accessCode) === '') {
            return Response::error(
                'MISSING_SITE_CREDENTIALS',
                'La petición debe incluir el código de sede y la clave de centro.',
                400
            );
        }

        $result = $this->authService->loginSite(trim((string)$siteCode), (string)$accessCode);

        if ($result === null) {
            return Response::error(
                'INVALID_SITE_CREDENTIALS',
                'Código o clave no reconocidos. Contacte con el servicio técnico.',
                401
            );
        }

        $location = $result['location'];

        return Response::json([
            'token' => $result['token'],
            'location' => [
                'id' => $location->getId(),
                'site_code' => $location->getSiteCode(),
                'name' => $location->getName(),
                'address' => $location->getAddress(),
                'contact_name' => $location->getContactName(),
                'contact_phone' => $location->getContactPhone(),
            ],
        ], 200, 'Identificación de sede completada con éxito');
    }

    /**
     * POST /api/auth/login
     * Acceso seguro para personal interno (coordinadores y técnicos) con email y contraseña (RF-04).
     */
    public function login(Request $request): Response
    {
        $email = $request->getBodyParam('email');
        $password = $request->getBodyParam('password');

        if ($email === null || trim((string)$email) === '' || $password === null || (string)$password === '') {
            return Response::error(
                'MISSING_CREDENTIALS',
                'El correo electrónico y la contraseña son obligatorios.',
                400
            );
        }

        $result = $this->authService->loginInternal(trim((string)$email), (string)$password);

        if ($result === 'LOCKED') {
            return Response::error(
                'ACCOUNT_LOCKED',
                'Cuenta temporalmente bloqueada por demasiados intentos fallidos. Inténtelo de nuevo en 15 minutos.',
                423
            );
        }

        if ($result === null) {
            return Response::error(
                'INVALID_CREDENTIALS',
                'Credenciales incorrectas. Verifique el correo y la contraseña.',
                401
            );
        }

        $user = $result['user'];

        return Response::json([
            'token' => $result['token'],
            'user' => [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'role' => $user->getRole()->value,
                'phone' => $user->getPhone(),
            ],
        ], 200, 'Sesión iniciada correctamente');
    }
}
