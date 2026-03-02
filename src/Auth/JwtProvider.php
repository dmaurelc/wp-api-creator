<?php

namespace WpApiCreator\Auth;

use WP_Error;

/**
 * Proveedor de JSON Web Tokens (JWT).
 * Implementación standalone (sin dependencias externas) para firmar y verificar
 * tokens usando el algoritmo HS256 (HMAC SHA-256).
 */
class JwtProvider
{

    /**
     * Llave secreta para firmar los tokens.
     * En un entorno de producción, esto debería venir de wp-config.php o la base de datos de forma segura.
     * @return string
     */
    private function get_secret_key(): string {
        if (defined('WP_API_CREATOR_JWT_SECRET')) {
            return WP_API_CREATOR_JWT_SECRET;
        }
        
        // Fallback robusto utilizando las salts únicas de la instalación de WordPress
        return defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'fallback-secret-creator-api-key-2026';
    }

    /**
     * Genera un Token JWT válido para un usuario.
     * 
     * @param int $user_id
     * @param int $expiration_in_hours
     * @return string
     */
    public function generate_token(int $user_id, int $expiration_in_hours = 24): string {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        
        $issued_at = time();
        $expirationTime = $issued_at + ($expiration_in_hours * 60 * 60);

        $payload = json_encode([
            'iss' => get_bloginfo('url'), // Issuer
            'iat' => $issued_at,          // Issued at
            'nbf' => $issued_at,          // Not before
            'exp' => $expirationTime,     // Expiration
            'jti' => wp_generate_uuid4(), // JWT ID
            'data' => [
                'user_id' => $user_id
            ]
        ]);

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->get_secret_key(), true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    /**
     * Valida un Token JWT provisto y retorna el ID del usuario si es exitoso.
     * 
     * @param string $token
     * @return int|WP_Error El User ID o un WP_Error si es inválido.
     */
    public function validate_token(string $token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return new WP_Error('jwt_invalid_format', 'El formato del token JWT proporcionado es inválido.', ['status' => 401]);
        }

        list($header64, $payload64, $signature64) = $parts;

        // Re-crear la firma con la cabecera y el payload usando nuestra llave secreta
        $valid_signature = hash_hmac('sha256', $header64 . "." . $payload64, $this->get_secret_key(), true);
        $valid_signature64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($valid_signature));

        // Validación de firma (usando hash_equals para prevenir ataques de "timing")
        if (!hash_equals($valid_signature64, $signature64)) {
            return new WP_Error('jwt_invalid_signature', 'La firma criptográfica del token no coincide.', ['status' => 401]);
        }

        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload64)), true);
        
        if (!$payload || !isset($payload['exp']) || !isset($payload['data']['user_id'])) {
            return new WP_Error('jwt_invalid_payload', 'El payload del token JWT está corrupto o carece de información.', ['status' => 401]);
        }

        // Validación de Not Before (nbf)
        if (isset($payload['nbf']) && $payload['nbf'] > time()) {
            return new WP_Error('jwt_not_active', 'El token de acceso aún no está activo.', ['status' => 401]);
        }

        // Validación de expiración
        if ($payload['exp'] < time()) {
            return new WP_Error('jwt_expired', 'El token de acceso ha expirado. Por favor proporciona uno nuevo.', ['status' => 401]);
        }

        return (int) $payload['data']['user_id'];
    }
}
