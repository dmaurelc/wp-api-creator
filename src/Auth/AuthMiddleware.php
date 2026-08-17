<?php

namespace WpApiCreator\Auth;

use WP_Error;
use WP_REST_Request;

/**
 * Middleware que intercepta las solicitudes de la API y resuelve la identidad del usuario
 * en caso de recibir Tokens JWT o API Keys a través de Cabeceras HTTP.
 * Así, WordPress creerá que el usuario está logeado de forma nativa.
 */
class AuthMiddleware
{
    /**
     * Atributo donde se adjunta el motivo del fallo de credencial.
     *
     * Se usa `set_attributes()` y no `set_param()`: la bolsa de parametros tambien lee
     * query string y body, asi que un cliente podria inyectar la clave y falsear el motivo.
     */
    const ERROR_ATTRIBUTE = 'wp_api_creator_auth_error';

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
            $bucket = RateLimiter::api_key_bucket();

            if (RateLimiter::is_blocked($bucket, RateLimiter::API_KEY_THRESHOLD)) {
                $this->attach_error($request, new WP_Error(
                    'api_key_too_many_attempts',
                    'Demasiadas API Keys inválidas desde este origen. Vuelve a intentarlo más tarde.',
                    ['status' => 429, 'retry_after' => RateLimiter::retry_after($bucket, RateLimiter::API_KEY_WINDOW)]
                ));
            } else {
                $user_id = $this->api_key_provider->validate_key($api_key);
                if ($user_id) {
                    // Autenticar la sesión en este hilo de memoria de PHP
                    wp_set_current_user($user_id);
                    RateLimiter::clear($bucket);
                    return;
                }

                // Una key inválida cuenta en su propio espacio, nunca en el del login.
                RateLimiter::register_failure($bucket, RateLimiter::API_KEY_WINDOW);

                $this->attach_error($request, new WP_Error(
                    'api_key_invalid',
                    'La API Key proporcionada no es válida, ha caducado o pertenece a un esquema anterior.',
                    ['status' => 401]
                ));
            }
        }

        // Intento 2: Basic Auth (Application Passwords nativas de WordPress)
        $auth_header = $request->get_header('authorization') ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);

        if ($auth_header && strpos($auth_header, 'Basic ') === 0) {
            $username = self::basic_auth_username($auth_header);
            $ip_key   = RateLimiter::ip_key();
            $user_key = $username !== null ? RateLimiter::user_key($username) : null;

            // Basic Auth es una vía de adivinación de contraseñas equivalente a
            // `/auth/token`: comparte contadores y umbrales con ella.
            $blocked = RateLimiter::is_blocked($ip_key, RateLimiter::IP_THRESHOLD)
                || ($user_key !== null && RateLimiter::is_blocked($user_key, RateLimiter::USER_THRESHOLD));

            if ($blocked) {
                $this->attach_error($request, new WP_Error(
                    'auth_too_many_attempts',
                    'Demasiados intentos fallidos. Vuelve a intentarlo más tarde.',
                    ['status' => 429, 'retry_after' => RateLimiter::retry_after($ip_key, RateLimiter::IP_WINDOW)]
                ));
            } else {
                $user_id = $this->app_password_provider->validate_credentials($auth_header);
                if (!is_wp_error($user_id)) {
                    wp_set_current_user($user_id);
                    RateLimiter::clear($ip_key);
                    if ($user_key !== null) {
                        RateLimiter::clear($user_key);
                    }
                    return;
                }

                RateLimiter::register_login_failure($username);
                $this->attach_error($request, $user_id);
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

            // El motivo viaja adjunto a la request para que el Router pueda responder
            // "expirado" o "revocado" en lugar de un 401 generico sin diagnostico.
            //
            // Un Bearer invalido NO cuenta para el limitador: la firma HS256 no es
            // adivinable, asi que no hay ataque que frenar, y una aplicacion con el token
            // caducado se bloquearia a si misma el acceso a `/auth/token` para renovarlo.
            $this->attach_error($request, $validation);
        }

        // Si fallan todos, el hilo sigue su curso como "Usuario No Autenticado" (Guest)
        // Y el Gatekeeper posterior decidirá si bloquea la ruta o no (dependiendo si es `public` o privada).
    }

    /**
     * Recupera el motivo del ultimo fallo de credencial adjuntado a la peticion.
     *
     * @param WP_REST_Request $request
     * @return WP_Error|null
     */
    public static function get_error(WP_REST_Request $request): ?WP_Error
    {
        $attributes = $request->get_attributes();
        $error = $attributes[self::ERROR_ATTRIBUTE] ?? null;

        return $error instanceof WP_Error ? $error : null;
    }

    /**
     * Extrae el nombre de usuario de una cabecera Basic, sin validarlo.
     *
     * Solo se usa como clave del limitador; la validacion real la hace el proveedor.
     *
     * @param string $auth_header
     * @return string|null
     */
    public static function basic_auth_username(string $auth_header): ?string
    {
        $credentials = base64_decode(substr($auth_header, 6), true);

        if ($credentials === false || strpos($credentials, ':') === false) {
            return null;
        }

        $username = explode(':', $credentials, 2)[0];

        return $username !== '' ? $username : null;
    }

    /**
     * Adjunta el motivo del fallo sin pisar el resto de atributos.
     *
     * @param WP_REST_Request $request
     * @param WP_Error        $error
     * @return void
     */
    private function attach_error(WP_REST_Request $request, WP_Error $error): void
    {
        $attributes = $request->get_attributes();
        $attributes[self::ERROR_ATTRIBUTE] = $error;
        $request->set_attributes($attributes);
    }
}
