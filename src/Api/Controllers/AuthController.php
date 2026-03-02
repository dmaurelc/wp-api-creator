<?php

namespace WpApiCreator\Api\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WpApiCreator\Auth\JwtProvider;

/**
 * Controlador de Autenticación de la API.
 * 
 * Expone un endpoint para canjear credenciales de Username/Password por Tokens JWT.
 */
class AuthController
{

    protected $jwt_provider;

    public function __construct(JwtProvider $jwt_provider)
    {
        $this->jwt_provider = $jwt_provider;
    }

    /**
     * Genera un Token JWT a cambio de Usuario y Contraseña (Authentication Endpoint).
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function generate_token(WP_REST_Request $request)
    {
        
        $username = $request->get_param('username');
        $password = $request->get_param('password');

        if (empty($username) || empty($password)) {
            return new WP_Error('auth_missing_credentials', 'Debes enviar usuario (username) y contraseña (password).', ['status' => 400]);
        }

        // Verificamos las credenciales contra el motor interno de WordPress
        $user = wp_authenticate($username, $password);

        if (is_wp_error($user)) {
            return new WP_Error('auth_failed', 'Las credenciales proporcionadas son incorrectas.', ['status' => 401]);
        }

        // Expira por defecto en 24 horas, puede ser configurado.
        $expiration_hours = 24;
        
        // Generar JWT
        $token = $this->jwt_provider->generate_token($user->ID, $expiration_hours);

        return new WP_REST_Response([
            'message' => 'Autenticación exitosa',
            'token' => $token,
            'user' => [
                'id' => $user->ID,
                'username' => $user->user_login,
                'email' => $user->user_email,
                'roles' => $user->roles,
            ],
            'expires_in_hours' => $expiration_hours
        ], 200);
    }
}
