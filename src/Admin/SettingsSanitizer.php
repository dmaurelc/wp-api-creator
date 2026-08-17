<?php

namespace WpApiCreator\Admin;

use WP_Error;

/**
 * Saneado de los ajustes globales enviados desde el dashboard.
 *
 * El cliente solo puede escribir las claves declaradas aqui. Todo lo demas que llegue
 * en el payload se ignora, y las claves ausentes conservan su valor: hasta 1.0.0 el
 * guardado reemplazaba el subarbol `settings` entero, asi que un formulario que se
 * cargase a medias borraba de un golpe los campos que no conocia.
 */
class SettingsSanitizer
{
    /** Unicas claves que el dashboard puede escribir. */
    const WRITABLE_KEYS = [
        'api_namespace',
        'cache_time',
        'require_api_key',
        'jwt_expiration',
        'filter_wp_endpoints',
    ];

    /**
     * Namespaces que no se pueden reutilizar.
     *
     * `creator/v1` es el namespace fijo de las rutas de administracion y la interfaz llego
     * a sugerirlo como valor de ejemplo. `wp/v2` y los demas son de WordPress core: apuntar
     * ahi haria que el middleware y el enforcement se aplicasen a las rutas nativas.
     */
    const RESERVED_NAMESPACES = [
        'creator/v1',
        'wp/v2',
        'wp-site-health/v1',
        'oembed/1.0',
    ];

    /** Formato `slug/vN`. */
    const NAMESPACE_PATTERN = '#^[a-z0-9]([a-z0-9-]*[a-z0-9])?/v[0-9]+$#';

    /**
     * Fusiona el payload del cliente sobre los ajustes ya guardados.
     *
     * @param array $incoming Ajustes recibidos.
     * @param array $existing Ajustes actualmente persistidos.
     * @return array|WP_Error
     */
    public static function sanitize(array $incoming, array $existing)
    {
        $settings = $existing;

        foreach (self::WRITABLE_KEYS as $key) {
            if (!array_key_exists($key, $incoming)) {
                continue;
            }

            $value = $incoming[$key];

            switch ($key) {
                case 'api_namespace':
                    $namespace = self::normalize_namespace((string) $value);
                    $validation = self::validate_namespace($namespace, (string) ($existing['api_namespace'] ?? ''));
                    if ($validation instanceof WP_Error) {
                        return $validation;
                    }
                    $settings[$key] = $namespace;
                    break;

                case 'cache_time':
                    $settings[$key] = max(0, (int) $value);
                    break;

                case 'jwt_expiration':
                    $settings[$key] = max(1, (int) $value);
                    break;

                case 'require_api_key':
                case 'filter_wp_endpoints':
                    $settings[$key] = (bool) $value;
                    break;
            }
        }

        return $settings;
    }

    /**
     * @param string $namespace
     * @return string
     */
    public static function normalize_namespace(string $namespace): string
    {
        return strtolower(trim(trim($namespace), '/'));
    }

    /**
     * @param string $namespace Ya normalizado.
     * @param string $current   Namespace actualmente guardado, que siempre se admite.
     * @return true|WP_Error
     */
    public static function validate_namespace(string $namespace, string $current = '')
    {
        if ($namespace === '') {
            return new WP_Error(
                'invalid_api_namespace',
                __('El namespace de la API no puede estar vacío.', 'wp-api-creator'),
                ['status' => 400]
            );
        }

        if (!preg_match(self::NAMESPACE_PATTERN, $namespace)) {
            return new WP_Error(
                'invalid_api_namespace',
                __('El namespace debe tener el formato "mi-api/v1": minúsculas, números o guiones, seguidos de una versión.', 'wp-api-creator'),
                ['status' => 400]
            );
        }

        if (in_array($namespace, self::RESERVED_NAMESPACES, true)) {
            return new WP_Error(
                'reserved_api_namespace',
                sprintf(
                    /* translators: %s: namespace solicitado */
                    __('El namespace "%s" está reservado por WordPress o por el propio plugin. Elige otro.', 'wp-api-creator'),
                    $namespace
                ),
                ['status' => 400]
            );
        }

        // El namespace vigente esta registrado por este mismo plugin, asi que aparece en la
        // lista de namespaces del servidor. Sin esta exencion, reguardar los ajustes sin
        // cambiar el namespace seria imposible y la pantalla quedaria inutilizable.
        if ($namespace === self::normalize_namespace($current)) {
            return true;
        }

        if (in_array($namespace, self::registered_namespaces(), true)) {
            return new WP_Error(
                'reserved_api_namespace',
                sprintf(
                    /* translators: %s: namespace solicitado */
                    __('El namespace "%s" ya está en uso por WordPress o por otro plugin. Registrar las rutas ahí las mezclaría con las suyas y aplicaría este control de acceso sobre ellas.', 'wp-api-creator'),
                    $namespace
                ),
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * Namespaces REST ya registrados en esta instalacion.
     *
     * La lista negra estatica solo cubre cuatro nombres previstos; esto cubre el caso real,
     * incluidos los de otros plugins (`wc/v3`, `jetpack/v4`...). La comprobacion se hace al
     * guardar, que sucede dentro de una peticion REST ya despachada: en ese punto
     * `rest_api_init` termino y todos los namespaces estan registrados.
     *
     * @return string[]
     */
    private static function registered_namespaces(): array
    {
        if (!function_exists('rest_get_server')) {
            return [];
        }

        $server = rest_get_server();

        if (!is_object($server) || !method_exists($server, 'get_namespaces')) {
            return [];
        }

        return array_map(
            [self::class, 'normalize_namespace'],
            array_map('strval', (array) $server->get_namespaces())
        );
    }
}
