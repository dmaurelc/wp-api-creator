<?php

namespace WpApiCreator\Auth;

use WP_Error;

/**
 * Proveedor de Autenticación mediante Application Passwords nativas de WordPress.
 * Permite usar el flujo Basic Auth (Base64) estandarizado.
 */
class ApplicationPasswordProvider
{

    /**
     * Valida el header Authorization (Basic) usando los Application Passwords de WP.
     * Retorna el ID del usuario si las credenciales son válidas.
     *
     * @param string $auth_header (ej: "Basic base64(user:password)")
     * @return int|WP_Error El User ID o WP_Error si falla.
     */
    public function validate_credentials(string $auth_header)
    {
        if (strpos($auth_header, 'Basic ') !== 0) {
            return new WP_Error('invalid_auth_header', 'Formato de cabecera Basic Auth inválido.', ['status' => 401]);
        }

        $base64_credentials = substr($auth_header, 6);
        $credentials = base64_decode($base64_credentials);

        if ($credentials === false || strpos($credentials, ':') === false) {
            return new WP_Error('invalid_credentials_format', 'El formato de credenciales codificadas no es válido.', ['status' => 401]);
        }

        list($username, $password) = explode(':', $credentials, 2);

        $user = get_user_by('login', $username);

        if (!$user) {
            return new WP_Error('invalid_username', 'El usuario proporcionado no existe.', ['status' => 401]);
        }

        // Usamos la función nativa de WordPress para validar App Passwords
        $validated_user = wp_authenticate_application_password(null, $username, $password);

        if (is_wp_error($validated_user)) {
             return new WP_Error('invalid_app_password', 'La Contraseña de Aplicación proporcionada es incorrecta o ha sido revocada.', ['status' => 401]);
        }

        return $user->ID;
    }
}
