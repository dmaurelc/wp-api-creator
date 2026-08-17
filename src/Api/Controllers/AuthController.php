<?php

namespace WpApiCreator\Api\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WpApiCreator\Auth\JwtProvider;
use WpApiCreator\Auth\RateLimiter;
use WpApiCreator\Domain\ConfigBuilder;

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

        $ip_key   = RateLimiter::ip_key();
        $user_key = RateLimiter::user_key((string) $username);

        $throttled = $this->check_throttle($ip_key, $user_key);
        if ($throttled instanceof WP_REST_Response) {
            return $throttled;
        }

        // Verificamos las credenciales contra el motor interno de WordPress
        $user = wp_authenticate($username, $password);

        if (is_wp_error($user)) {
            RateLimiter::register_login_failure((string) $username);

            return new WP_Error('auth_failed', 'Las credenciales proporcionadas son incorrectas.', ['status' => 401]);
        }

        // Credenciales correctas: el historial de fallos deja de contar.
        RateLimiter::clear($ip_key);
        RateLimiter::clear($user_key);

        $expiration_hours = max(1, (int) (ConfigBuilder::get_global_settings()['jwt_expiration'] ?? 24));

        $token = $this->jwt_provider->generate_token($user->ID, $expiration_hours);

        // `generate_token` puede devolver un WP_Error si no hay llave de firma disponible.
        if (is_wp_error($token)) {
            return $token;
        }

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

    /**
     * Comprueba ambas politicas del limitador antes de tocar `wp_authenticate()`.
     *
     * @param string $ip_key
     * @param string $user_key
     * @return true|WP_REST_Response
     */
    private function check_throttle(string $ip_key, string $user_key)
    {
        if (RateLimiter::is_blocked($ip_key, RateLimiter::IP_THRESHOLD)) {
            return $this->too_many_requests(RateLimiter::retry_after($ip_key, RateLimiter::IP_WINDOW));
        }

        if (RateLimiter::is_blocked($user_key, RateLimiter::USER_THRESHOLD)) {
            return $this->too_many_requests(RateLimiter::retry_after($user_key, RateLimiter::USER_WINDOW));
        }

        return true;
    }

    /**
     * Respuesta 429 con `Retry-After`.
     *
     * Se devuelve un WP_REST_Response y no un WP_Error porque la conversion de errores
     * de WordPress descarta las cabeceras y el cliente perderia el tiempo de espera.
     *
     * @param int $retry_after Segundos hasta el proximo intento admitido.
     * @return WP_REST_Response
     */
    private function too_many_requests(int $retry_after): WP_REST_Response
    {
        $response = new WP_REST_Response([
            'code'    => 'auth_too_many_attempts',
            'message' => 'Demasiados intentos fallidos. Vuelve a intentarlo más tarde.',
            'data'    => ['status' => 429, 'retry_after' => $retry_after],
        ], 429);

        $response->header('Retry-After', (string) $retry_after);

        return $response;
    }
}
