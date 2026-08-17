<?php

namespace WpApiCreator\Auth;

use WP_Error;
use WpApiCreator\Domain\ConfigBuilder;

/**
 * Proveedor de JSON Web Tokens (JWT).
 * Implementación standalone (sin dependencias externas) para firmar y verificar
 * tokens usando el algoritmo HS256 (HMAC SHA-256).
 */
class JwtProvider
{
    /** @var string|null Secreto inyectado; null delega la resolucion a constantes y option. */
    private ?string $secret;

    /**
     * @param string|null $secret Secreto explicito. Una cadena vacia simula
     *                            "ningun secreto disponible" y hace fallar la firma
     *                            de forma controlada, sin tocar constantes globales.
     */
    public function __construct(?string $secret = null)
    {
        $this->secret = $secret;
    }

    /**
     * Resuelve la llave de firma.
     *
     * Cadena de resolucion: secreto inyectado -> constante propia -> SECURE_AUTH_KEY ->
     * secreto autogenerado en su propia option.
     *
     * @return string|null Null si no hay ningun secreto utilizable.
     */
    private function get_secret_key(): ?string
    {
        if ($this->secret !== null) {
            return $this->secret !== '' ? $this->secret : null;
        }

        if (defined('WP_API_CREATOR_JWT_SECRET') && WP_API_CREATOR_JWT_SECRET) {
            return (string) WP_API_CREATOR_JWT_SECRET;
        }

        if (defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY) {
            return (string) SECURE_AUTH_KEY;
        }

        $generated = ConfigBuilder::get_or_create_jwt_secret();

        return $generated !== '' ? $generated : null;
    }

    /**
     * Genera un Token JWT válido para un usuario.
     *
     * No declara tipo de retorno a proposito: PHP 7.4 no admite tipos union y devolver
     * un WP_Error desde una firma `: string` seria un TypeError fatal, no un error manejable.
     *
     * @param int $user_id
     * @param int $expiration_in_hours
     * @return string|WP_Error
     */
    public function generate_token(int $user_id, int $expiration_in_hours = 24)
    {
        $secret = $this->get_secret_key();
        if ($secret === null) {
            return new WP_Error(
                'jwt_secret_unavailable',
                'No hay ninguna llave de firma disponible para emitir tokens.',
                ['status' => 500]
            );
        }

        $issued_at       = time();
        $expiration_time = $issued_at + (max(1, $expiration_in_hours) * HOUR_IN_SECONDS);

        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);

        $payload = json_encode([
            'iss' => get_bloginfo('url'), // Issuer
            'iat' => $issued_at,          // Issued at
            'nbf' => $issued_at,          // Not before
            'exp' => $expiration_time,    // Expiration
            'jti' => wp_generate_uuid4(), // JWT ID
            'data' => [
                'user_id' => $user_id,
                'tv'      => TokenVersionStore::read_for_issue($user_id),
            ]
        ]);

        $base64UrlHeader  = self::base64url_encode($header);
        $base64UrlPayload = self::base64url_encode($payload);

        return $base64UrlHeader . '.' . $base64UrlPayload . '.'
            . self::sign($base64UrlHeader . '.' . $base64UrlPayload, $secret);
    }

    /**
     * Valida un Token JWT provisto y retorna el ID del usuario si es exitoso.
     *
     * @param string $token
     * @return int|WP_Error El User ID o un WP_Error si es inválido.
     */
    public function validate_token(string $token)
    {
        $secret = $this->get_secret_key();
        if ($secret === null) {
            return new WP_Error(
                'jwt_secret_unavailable',
                'No hay ninguna llave de firma disponible para validar tokens.',
                ['status' => 500]
            );
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return new WP_Error('jwt_invalid_format', 'El formato del token JWT proporcionado es inválido.', ['status' => 401]);
        }

        list($header64, $payload64, $signature64) = $parts;

        // Validación de firma (usando hash_equals para prevenir ataques de "timing")
        if (!hash_equals(self::sign($header64 . '.' . $payload64, $secret), $signature64)) {
            return new WP_Error('jwt_invalid_signature', 'La firma criptográfica del token no coincide.', ['status' => 401]);
        }

        $payload = json_decode(self::base64url_decode($payload64), true);

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

        // Emisor: un token firmado para otra instalación no vale aquí.
        if (!isset($payload['iss']) || $payload['iss'] !== get_bloginfo('url')) {
            return new WP_Error('jwt_invalid_issuer', 'El token fue emitido para otra instalación.', ['status' => 401]);
        }

        $user_id = (int) $payload['data']['user_id'];

        $revocation_error = $this->check_revocation($payload, $user_id);
        if ($revocation_error instanceof WP_Error) {
            return $revocation_error;
        }

        if (!get_userdata($user_id)) {
            return new WP_Error('jwt_user_not_found', 'El usuario asociado al token ya no existe.', ['status' => 401]);
        }

        return $user_id;
    }

    /**
     * Invalida todos los tokens vivos de un usuario.
     *
     * @param int $user_id
     * @return int La nueva versión de token.
     */
    public function revoke_all_for_user(int $user_id): int
    {
        return TokenVersionStore::revoke($user_id);
    }

    /**
     * Comprueba la versión de token declarada en el payload.
     *
     * `tv` es obligatorio. Los tokens emitidos antes de 1.1.0 no lo llevan; aceptarlos
     * por ausencia los mantendría válidos indefinidamente, justo lo contrario de lo que
     * promete la actualización. Lo mismo aplica al meta del usuario: si falta, el token
     * se considera revocado en lugar de compararse contra un cero implícito.
     *
     * @param array $payload
     * @param int   $user_id
     * @return true|WP_Error
     */
    private function check_revocation(array $payload, int $user_id)
    {
        if (!isset($payload['data']['tv'])) {
            return new WP_Error('jwt_revoked', 'El token fue revocado o pertenece a un esquema anterior.', ['status' => 401]);
        }

        $current = TokenVersionStore::read($user_id);

        if ($current === null || (int) $payload['data']['tv'] !== $current) {
            return new WP_Error('jwt_revoked', 'El token fue revocado.', ['status' => 401]);
        }

        return true;
    }

    /**
     * Firma un mensaje y lo devuelve en base64url.
     */
    private static function sign(string $message, string $secret): string
    {
        return self::base64url_encode(hash_hmac('sha256', $message, $secret, true));
    }

    private static function base64url_encode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64url_decode(string $data): string
    {
        return (string) base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}
