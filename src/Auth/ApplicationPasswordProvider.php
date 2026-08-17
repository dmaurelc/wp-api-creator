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

        // Usamos la función nativa de WordPress para validar App Passwords
        $validated_user = wp_authenticate_application_password(null, $username, $password);

        // `wp_authenticate_application_password()` devuelve su primer argumento sin tocarlo
        // — aqui null, NO un WP_Error — en varias salidas tempranas: cuando el sitio no tiene
        // ninguna Application Password creada (`WP_Application_Passwords::is_in_use()`), y
        // cuando el filtro `application_password_is_api_request` decide que la peticion no
        // aplica. Comprobar solo `is_wp_error()` trataria esas salidas como exito y
        // autenticaria con cualquier contrasena conociendo unicamente el nombre de usuario.
        // Solo una instancia de WP_User acredita credenciales validas.
        if (!($validated_user instanceof \WP_User)) {
            return new WP_Error('invalid_app_password', 'La Contraseña de Aplicación proporcionada es incorrecta, ha sido revocada o no está habilitada en este sitio.', ['status' => 401]);
        }

        // Se devuelve el ID resuelto por WordPress y no el de una busqueda propia por login:
        // core tambien acepta el correo electronico como identificador, y ambos pueden diferir.
        return (int) $validated_user->ID;
    }
}
