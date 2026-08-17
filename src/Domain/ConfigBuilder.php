<?php

namespace WpApiCreator\Domain;

/**
 * Responsable de armar e interactuar con la configuración global del plugin.
 * Obtiene qué CPTs (Custom post types) y metas serán expuestos.
 *
 * Usa una caché estática in-memory por request y wp_cache_* para persistencia
 * entre requests cuando hay un Object Cache (Redis/Memcached) disponible.
 */
class ConfigBuilder
{
    const OPTION_KEY = 'wp_api_creator_config';
    const CACHE_GROUP = 'creator_config';
    const CACHE_KEY = 'full_config';

    /** Copia integra del option previa a la migracion destructiva de 1.1.0. */
    const OPTION_BACKUP_KEY = 'wp_api_creator_config_backup_1_0_0';

    /**
     * Secreto de firma autogenerado. Vive en su propia option y NUNCA dentro de
     * $config['settings']: ese subarbol lo sobrescribe el dashboard al guardar, y
     * perder el secreto invalidaria todos los tokens vivos sin causa aparente.
     */
    const OPTION_JWT_SECRET = 'wp_api_creator_jwt_secret';

    /**
     * Mapa id_de_key => timestamp de ultimo uso. Fuera del blob de configuracion
     * para que el path de request no tenga que hacer read-modify-write del documento
     * entero (endpoints y permisos incluidos) solo para anotar una fecha.
     */
    const OPTION_KEY_USAGE = 'wp_api_creator_key_usage';

    /** @var array|null Caché estática per-request */
    private static ?array $config_cache = null;

    /**
     * Obtiene la configuración completa del plugin con caché multinivel.
     *
     * @return array
     */
    private static function get_config(): array
    {
        // Nivel 1: Caché estática (mismo request)
        if (self::$config_cache !== null) {
            return self::$config_cache;
        }

        // Nivel 2: Object Cache (Redis/Memcached si está disponible)
        $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
        if (is_array($cached)) {
            self::$config_cache = $cached;
            return $cached;
        }

        // Nivel 3: Base de datos (get_option)
        $config = get_option(self::OPTION_KEY, []);
        if (!is_array($config)) {
            $config = [];
        }

        // Poblar ambos niveles de caché
        self::$config_cache = $config;
        wp_cache_set(self::CACHE_KEY, $config, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS);

        return $config;
    }

    /**
     * Invalida todas las capas de caché.
     *
     * @return void
     */
    public static function invalidate_cache(): void
    {
        self::$config_cache = null;
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
    }

    /**
     * Retorna la matriz de endpoints activos.
     *
     * @return array
     */
    public static function get_active_endpoints(): array
    {
        $config = self::get_config();
        return $config['endpoints'] ?? [];
    }

    /**
     * Retorna la configuración de visibilidad de rutas globales.
     *
     * @return array
     */
    public static function get_global_routes_config(): array
    {
        $config = self::get_config();
        return $config['global_routes'] ?? [];
    }

    /**
     * Retorna la configuración de ajustes globales.
     *
     * @return array
     */
    public static function get_global_settings(): array
    {
        $config = self::get_config();

        $settings = $config['settings'] ?? [];

        // Defaults.
        // `api_keys` NO se expone aqui: su ubicacion canonica es la raiz del config,
        // no el subarbol `settings`, que el dashboard reemplaza al guardar.
        return [
            'api_namespace'        => $settings['api_namespace'] ?? 'wp-custom-api/v1',
            'cache_time'           => isset($settings['cache_time']) ? (int) $settings['cache_time'] : 0,
            'require_api_key'      => isset($settings['require_api_key']) ? (bool) $settings['require_api_key'] : false,
            'jwt_expiration'       => isset($settings['jwt_expiration']) ? max(1, (int) $settings['jwt_expiration']) : 24,
            'filter_wp_endpoints'  => isset($settings['filter_wp_endpoints']) ? (bool) $settings['filter_wp_endpoints'] : false,
        ];
    }

    /**
     * Retorna las API Keys almacenadas desde su ubicacion canonica: la raiz del config.
     *
     * @return array Lista indexada; nunca un mapa asociativo.
     */
    public static function get_api_keys(): array
    {
        $config = self::get_config();
        return self::normalize_api_keys($config['api_keys'] ?? []);
    }

    /**
     * Persiste la lista completa de API Keys en la ubicacion canonica.
     *
     * Solo debe invocarse desde el contexto de administracion.
     *
     * @param array $keys
     * @return bool
     */
    public static function save_api_keys(array $keys): bool
    {
        $config = get_option(self::OPTION_KEY, []);
        if (!is_array($config)) {
            $config = [];
        }
        $config['api_keys'] = self::normalize_api_keys($keys);
        return self::save_config($config);
    }

    /**
     * Garantiza una lista indexada de entradas validas.
     *
     * Sin `array_values()` un borrado deja huecos en los indices y `json_encode`
     * serializa el array como objeto, lo que rompe `.map()` en el dashboard.
     *
     * @param mixed $keys
     * @return array
     */
    public static function normalize_api_keys($keys): array
    {
        if (!is_array($keys)) {
            return [];
        }

        $normalized = [];
        foreach ($keys as $key) {
            if (is_array($key) && isset($key['id'])) {
                $normalized[] = $key;
            }
        }

        return array_values($normalized);
    }

    /**
     * Devuelve el secreto de firma autogenerado, creandolo la primera vez.
     *
     * Se guarda con `autoload = no` porque solo se consulta al emitir o validar
     * un token, nunca en el arranque de una pagina normal.
     *
     * @return string
     */
    public static function get_or_create_jwt_secret(): string
    {
        $secret = get_option(self::OPTION_JWT_SECRET, '');
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        $secret = bin2hex(random_bytes(32));

        // `add_option` falla si otro proceso gano la carrera; releemos para quedarnos
        // con el valor que realmente quedo persistido.
        add_option(self::OPTION_JWT_SECRET, $secret, '', 'no');
        $stored = get_option(self::OPTION_JWT_SECRET, '');

        return (is_string($stored) && $stored !== '') ? $stored : $secret;
    }

    /**
     * Retorna todas las configuraciones de endpoints WP core.
     *
     * @return array Mapa de post_type => config
     */
    public static function get_wp_endpoint_configs(): array
    {
        $config = self::get_config();
        return $config['wp_endpoint_configs'] ?? [];
    }

    /**
     * Retorna la configuración de un endpoint WP core por su post_type.
     *
     * @param string $post_type
     * @return array|null
     */
    public static function get_wp_endpoint_config(string $post_type): ?array
    {
        $configs = self::get_wp_endpoint_configs();
        return $configs[$post_type] ?? null;
    }

    /**
     * Guarda la configuración de un endpoint WP core.
     *
     * @param string $post_type
     * @param array  $endpoint_config
     * @return bool
     */
    public static function save_wp_endpoint_config(string $post_type, array $endpoint_config): bool
    {
        $config = self::get_config();
        if (!isset($config['wp_endpoint_configs'])) {
            $config['wp_endpoint_configs'] = [];
        }
        $config['wp_endpoint_configs'][$post_type] = $endpoint_config;
        return self::save_config($config);
    }

    /**
     * Guarda toda la configuración e invalida la caché.
     *
     * REGLA DURA DEL PROYECTO: este metodo es exclusivo del contexto de administracion
     * y de la rutina de migracion. Ninguna ruta del path de request (src/Api, src/Auth)
     * puede invocarlo. La lectura esta cacheada 5 minutos, asi que un read-modify-write
     * desde una peticion puede reescribir configuracion obsoleta y revertir en silencio
     * cambios que un administrador acaba de guardar.
     *
     * @param array $config
     * @return bool
     */
    public static function save_config(array $config): bool
    {
        // Se invalida antes y despues de escribir. Solo antes dejaria una ventana en la que
        // una peticion concurrente repuebla la cache con el valor viejo entre el vaciado y
        // el `update_option`, y ese valor obsoleto sobreviviria hasta 5 minutos: el
        // administrador veria un 200 al activar `require_api_key` con la API aun abierta.
        self::invalidate_cache();

        $saved = update_option(self::OPTION_KEY, $config);

        self::invalidate_cache();

        // Las respuestas cacheadas dependen de qué campos expone cada endpoint y de sus
        // permisos. Se invalida aquí y no en `AdminApi` porque este es el único punto por
        // el que pasan los cuatro caminos de guardado del dashboard y también la migración.
        ResponseCache::bump_config_version();

        if ($saved) {
            return true;
        }

        // `update_option` devuelve false cuando el valor almacenado ya era identico.
        // Guardar sin cambios no es un fallo y no debe reportarse como error al dashboard.
        return get_option(self::OPTION_KEY, null) === $config;
    }
}
