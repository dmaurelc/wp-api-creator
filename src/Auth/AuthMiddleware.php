<?php

namespace WpApiCreator\Auth;

use WP_REST_Request;

/**
 * Middleware que intercepta las solicitudes de la API y resuelve la identidad del usuario
 * en caso de recibir Tokens JWT o API Keys a través de Cabeceras HTTP.
 * Así, WordPress creerá que el usuario está logeado de forma nativa.
 */
class AuthMiddleware
{

    protected $jwt_provider;
    protected $api_key_provider;
    protected $app_password_provider;

    public function __construct(JwtProvider $jwt_provider, ApiKeyProvider $api_key_provider, ApplicationPasswordProvider $app_password_provider)
    {
        $this->jwt_provider = $jwt_provider;
        $this->api_key_provider = $api_key_provider;
        $this->app_password_provider = $app_password_provider;
    }

    /**
     * Metodo interceptor que debe correr ANTES del Gatekeeper de permisos en cada ruta.
     * 
     * @param WP_REST_Request $request
     * @return void
     */
    public function authenticate_request(WP_REST_Request $request): void {
        
        // Si el usuario ya viene logeado por Cookies nativas de WP, y estamos en el entorno mismo, no sobreescribir.
        if (is_user_logged_in() && !isset($_SERVER['HTTP_AUTHORIZATION']) && !isset($_SERVER['HTTP_X_API_KEY'])) {
            return;
        }

        // Intento 1: API Key Header (X-API-KEY) - Muy usado en integraciones s2s (Server to Server)
        $api_key = $request->get_header('x-api-key') ?? ($_SERVER['HTTP_X_API_KEY'] ?? null);
        if ($api_key) {
            $user_id = $this->api_key_provider->validate_key($api_key);
            if ($user_id) {
                // Autenticar la sesión en este hilo de memoria de PHP
                wp_set_current_user($user_id);
                return;
            }
        }

        // Intento 2: Basic Auth (Application Passwords nativas de WordPress)
        $auth_header = $request->get_header('authorization') ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);

        if ($auth_header && strpos($auth_header, 'Basic ') === 0) {
            $user_id = $this->app_password_provider->validate_credentials($auth_header);
            if (!is_wp_error($user_id)) {
                wp_set_current_user($user_id);
                return;
            }
        }

        // Intento 3: JWT Bearer Token (Authorization: Bearer <token>) - Usado en APPs, SPA, React, etc
        if ($auth_header && preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
            $jwt_token = $matches[1];
            
            $validation = $this->jwt_provider->validate_token($jwt_token);
            
            // Si la validacion retorna un Entero (User ID válido)
            if (!is_wp_error($validation)) {
                wp_set_current_user($validation);
                return;
            }
            
            // Podríamos adjuntar el WP_Error a la request en el futuro si queremos loggear 
            // específicamente por qué el token falló, pero ahora solo caerá en el Gatekeeper (no logeado / 401).
        }

        // Si fallan ambos, el hilo sigue su curso como "Usuario No Autenticado" (Guest)
        // Y el Gatekeeper posterior decidirá si bloquea la ruta o no (dependiendo si es `public` o privada).
    }
}
